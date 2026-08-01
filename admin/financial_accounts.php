<?php
/** @var bool $is_admin */
/** @var string $user_role */
/** @var array $settings */
require_once 'header.php';

require_once '../includes/CurrencyExchange.php';
$currencyExchange = new CurrencyExchange($pdo);
$baseCurrency = $currencyExchange->getBaseCurrency();
$base_currency_id = $baseCurrency['id'] ?? null;

// التحقق من الصلاحية
if (!$is_admin && !in_array($user_role, ['accountant', 'branch_manager', 'agent', 'branch_user'])) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$isDeveloper = ($user_role === 'developer' || (int)($user_role_id ?? 0) === 2);
$canManageFinancialAccounts = $is_admin || $user_role === 'accountant' || has_permission('manage_financial_accounts');

if (isset($_GET['zero_balances']) || isset($_GET['repair_tree']) || isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// تصفير كافة الأرصدة بطلب من المطور فقط عبر POST + CSRF
if (isset($_POST['dangerous_financial_action']) && $_POST['dangerous_financial_action'] === 'zero_balances') {
    if (!$isDeveloper) {
        http_response_code(403);
        die("غير مصرح لك بتصفير الأرصدة.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
        try {
            // كتابة قائمة الجداول للتشخيص
            $stmt_all_tables = $pdo->query("SHOW TABLES");
            $existing_tables = $stmt_all_tables->fetchAll(PDO::FETCH_COLUMN);
            file_put_contents(__DIR__ . '/tables_list.txt', implode("\n", $existing_tables));

            $pdo->beginTransaction();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            // حذف جميع أرصدة الحسابات (بدلاً من مجرد تصفيرها)
            $pdo->exec("DELETE FROM account_balances_unified");
            
            // حذف البيانات من الجداول المالية إن وجدت
            $tables_to_delete = [
                'journal_lines', 'journal_entries', 'financial_transactions',
                'receipts', 'payments', 'unified_payments',
                'invoices', 'invoice_details', 'bus_flight_bookings'
            ];
            foreach ($tables_to_delete as $table) {
                if (in_array($table, $existing_tables)) {
                    $pdo->exec("DELETE FROM `$table`");
                }
            }
            
            // إضافة العملة الافتراضية لجميع الحسابات النشطة
            $stmt_def_curr = $pdo->query("SELECT id, currency_code FROM currencies WHERE is_default = 1 LIMIT 1");
            $default_curr = $stmt_def_curr->fetch(PDO::FETCH_ASSOC);
            
            if ($default_curr) {
                $stmt_all_acc = $pdo->query("SELECT id FROM unified_accounts WHERE is_active = 1");
                $all_acc_ids = $stmt_all_acc->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($all_acc_ids as $acc_id) {
                    $stmt_check = $pdo->prepare("SELECT id FROM account_balances_unified WHERE account_id = ? AND currency_id = ? AND branch_id IS NULL");
                    $stmt_check->execute([$acc_id, $default_curr['id']]);
                    if (!$stmt_check->fetch()) {
                        $stmt_insert = $pdo->prepare("
                            INSERT INTO account_balances_unified
                            (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen)
                            VALUES (?, NULL, ?, ?, 0, 0, 0, 0, 0)
                        ");
                        $stmt_insert->execute([$acc_id, $default_curr['id'], $default_curr['currency_code']]);
                    }
                }
            }
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->commit();
            echo "<script>alert('تم تصفير جميع الأرصدة وحذف كافة الحركات المالية المتوفرة بنجاح!'); location.href='financial_accounts.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "خطأ أثناء تصفير الأرصدة: " . $e->getMessage();
        }
    }
}

// إصلاح شجرة الحسابات بطلب من المطور فقط عبر POST + CSRF
if (isset($_POST['dangerous_financial_action']) && $_POST['dangerous_financial_action'] === 'repair_tree') {
    if (!$isDeveloper) {
        http_response_code(403);
        die("غير مصرح لك بإصلاح شجرة الحسابات.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // جلب جميع الحسابات لترتيبها حسب الكود
            $stmt = $pdo->query("SELECT id, account_code FROM unified_accounts ORDER BY LENGTH(account_code) ASC, account_code ASC");
            $all_accs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt_update = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE id = ?");
            
            foreach ($all_accs as $acc) {
                $code = trim($acc['account_code']);
                $id = $acc['id'];
                
                if (strlen($code) <= 1) {
                    $stmt_update->execute([null, $id]);
                    continue;
                }
                
                $parent_id = null;
                for ($i = strlen($code) - 1; $i >= 1; $i--) {
                    $prefix = substr($code, 0, $i);
                    $stmt_find = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
                    $stmt_find->execute([$prefix]);
                    $found_parent = $stmt_find->fetch(PDO::FETCH_ASSOC);
                    
                    if ($found_parent) {
                        $parent_id = $found_parent['id'];
                        break;
                    }
                }
                
                $stmt_update->execute([$parent_id, $id]);
            }
            
            $pdo->commit();
            echo "<script>alert('تم إصلاح شجرة الحسابات وربط الحسابات بآبائها الصحيحة بناءً على كود الحساب بنجاح!'); location.href='financial_accounts.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "خطأ أثناء إصلاح شجرة الحسابات: " . $e->getMessage();
        }
    }
}

// جلب الفروع والوكلاء والموظفين والعملاء والمستخدمين للنماذج
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();
$employees = $pdo->query("SELECT id, full_name FROM employees WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();
$customers = $pdo->query("SELECT id, full_name FROM customers WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();
$users_list = $pdo->query("SELECT id, username FROM users WHERE status = 'active'")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies")->fetchAll();

// جلب شجرة الحسابات للربط
$type_filter = $_GET['type'] ?? '';

// جلب جميع الحسابات النشطة/موقوفة للربط كأب
$chart_accounts = $pdo->query("SELECT id, account_code, account_name_ar, account_type FROM unified_accounts WHERE (account_status = 'active' OR account_status = 'dormant') ORDER BY account_code")->fetchAll();
$all_accounts = $pdo->query("SELECT id, account_code, account_name_ar, account_type, parent_id FROM unified_accounts WHERE (account_status = 'active' OR account_status = 'dormant') ORDER BY account_code")->fetchAll();

// دالة لبناء شجرة الحسابات بشكل هرمي بتعقيد O(n) (مميز جدًا!)
function buildAccountTree(&$accounts)
{
    $grouped = [];
    $idToAccount = [];
    $account_ids = array_column($accounts, 'id');
    
    // Group accounts by their parent_id for O(1) lookups, storing references!
    foreach ($accounts as &$acc) {
        $idToAccount[$acc['id']] = &$acc;
        $p_id = $acc['parent_id'] ? (int)$acc['parent_id'] : null;
        $grouped[$p_id][] = &$acc;
    }
    unset($acc);
    
    // Recursive tree builder using references!
    $buildRecursive = function($parentId = null) use (&$buildRecursive, &$grouped, &$idToAccount) {
        $branch = [];
        
        if (!isset($grouped[$parentId])) {
            return $branch;
        }
        
        foreach ($grouped[$parentId] as &$account) {
            $children = $buildRecursive($account['id']);
            if ($children) {
                $account['children'] = $children;
            }
            $branch[] = $account;
        }
        unset($account);
        
        return $branch;
    };
    
    // Root accounts are those with parent_id NULL or parent_id not present in account_ids!
    $rootBranch = [];
    foreach ($accounts as &$account) {
        $p_id = $account['parent_id'] ? (int)$account['parent_id'] : null;
        $is_root = ($p_id === null) || !in_array($p_id, $account_ids);
        if ($is_root) {
            // Check if we already added this account to avoid duplicates!
            $alreadyAdded = false;
            foreach ($rootBranch as $existing) {
                if ($existing['id'] === $account['id']) {
                    $alreadyAdded = true;
                    break;
                }
            }
            if (!$alreadyAdded) {
                $children = $buildRecursive($account['id']);
                if ($children) {
                    $account['children'] = $children;
                }
                $rootBranch[] = $account;
            }
        }
    }
    unset($account);
    
    return $rootBranch;
}

// دالة لحساب الرصيد التجميعي الموحد بتعقيد O(n) أيضًا!
function calculateAggregateBalances(&$accounts)
{
    $grouped = [];
    $idToAccount = [];
    $depth = [];
    
    // Group accounts by parent_id, create ID map, and calculate initial depth!
    foreach ($accounts as &$acc) {
        $idToAccount[$acc['id']] = &$acc;
        $p_id = $acc['parent_id'] ? (int)$acc['parent_id'] : null;
        // Handle empty string parent_id as null!
        if ($p_id === 0 && is_string($acc['parent_id']) && trim($acc['parent_id']) === '') {
            $p_id = null;
        }
        $grouped[$p_id][] = &$acc;
        $acc['current_balance'] = (float)$acc['direct_unified_balance'];
        $depth[$acc['id']] = 0;
    }
    unset($acc);
    
    // Calculate depth for all accounts!
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($accounts as &$acc) {
            $p_id = $acc['parent_id'] ? (int)$acc['parent_id'] : null;
            if ($p_id === 0 && is_string($acc['parent_id']) && trim($acc['parent_id']) === '') {
                $p_id = null;
            }
            if ($p_id !== null && isset($depth[$p_id]) && $depth[$acc['id']] <= $depth[$p_id]) {
                $depth[$acc['id']] = $depth[$p_id] + 1;
                $changed = true;
            }
        }
        unset($acc);
    }
    
    // Sort accounts in descending order of depth (children before parents)!
    usort($accounts, function($a, $b) use ($depth) {
        return $depth[$b['id']] - $depth[$a['id']];
    });
    
    // Now sum children's balances into parents!
    foreach ($accounts as &$acc) {
        $p_id = $acc['parent_id'] ? (int)$acc['parent_id'] : null;
        if ($p_id === 0 && is_string($acc['parent_id']) && trim($acc['parent_id']) === '') {
            $p_id = null;
        }
        if ($p_id !== null && isset($idToAccount[$p_id])) {
            $idToAccount[$p_id]['current_balance'] += $acc['current_balance'];
        }
    }
    unset($acc);
}

// حساب الأرصدة من account_balances_unified لكل حساب (مثل كشف الحساب)
$stmt_real_balances = $pdo->query("
    SELECT 
        abu.account_id, 
        abu.currency_id,
        abu.current_balance as net_balance,
        abu.current_balance_base as net_balance_base,
        c.currency_name, c.currency_symbol, c.currency_code
    FROM account_balances_unified abu
    LEFT JOIN currencies c ON abu.currency_id = c.id
");
$real_balances_raw = $stmt_real_balances->fetchAll(PDO::FETCH_ASSOC);

$real_balances = [];
$real_balances_base = [];
$all_balances_map = [];
foreach ($real_balances_raw as $b) {
    $account_id = $b['account_id'];
    if (!isset($real_balances[$account_id])) {
        $real_balances[$account_id] = [];
        $real_balances_base[$account_id] = 0;
        $all_balances_map[$account_id] = [];
    }
    $real_balances[$account_id][$b['currency_id']] = $b['net_balance'];
    $real_balances_base[$account_id] += $b['net_balance_base'];
    $all_balances_map[$account_id][] = $b;
}

// جلب جميع الحسابات لبناء الشجرة من الجدول الموحد مع الأرصدة التجميعية (مقومة بالعملة الأساسية)
$all_chart_accounts = $pdo->query("
    SELECT coa.*, 0 as direct_unified_balance
    FROM unified_accounts coa
    WHERE coa.account_status = 'active' OR coa.account_status = 'dormant'
    ORDER BY account_code
")->fetchAll();

// تحديث الرصيد المباشر لكل حساب من الأرصدة الحقيقية
foreach ($all_chart_accounts as &$acc) {
    $acc['direct_unified_balance'] = $real_balances_base[$acc['id']] ?? 0;
}
unset($acc);

// إضافة الرصيد التجميعي الموحد لكل حساب (بطريقة O(n)!)
calculateAggregateBalances($all_chart_accounts);

$accountTree = buildAccountTree($all_chart_accounts);

// Create map account IDs to aggregate balances
$aggregate_balances_map = [];
foreach ($all_chart_accounts as $acc) {
    $aggregate_balances_map[$acc['id']] = $acc['current_balance'];
}

// حذف حساب مالي عبر POST + CSRF فقط
if (isset($_POST['delete_account'])) {
    if (!$canManageFinancialAccounts) {
        die("غير مصرح لك بحذف الحسابات المالية.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
        $id = (int)$_POST['delete_account'];

        try {
            // التحقق من وجود حسابات فرعية
            $stmt_check_children = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE parent_id = ?");
            $stmt_check_children->execute([$id]);
            if ($stmt_check_children->fetchColumn() > 0) {
                throw new Exception("لا يمكن حذف هذا الحساب لوجود حسابات فرعية مرتبطة به.");
            }

            // التحقق من وجود حركات في دفتر اليومية
            $stmt_check_journal = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id = ?");
            $stmt_check_journal->execute([$id]);
            if ($stmt_check_journal->fetchColumn() > 0) {
                throw new Exception("لا يمكن حذف الحساب لوجود حركات مالية مسجلة عليه.");
            }

            // التحقق من وجود حركات مالية موحدة
            $stmt_check_unified = $pdo->prepare("SELECT COUNT(*) FROM financial_transactions WHERE (party_account_id = ? OR cash_bank_account_id = ?)");
            $stmt_check_unified->execute([$id, $id]);
            if ($stmt_check_unified->fetchColumn() > 0) {
                throw new Exception("لا يمكن حذف الحساب لوجود حركات مالية مرتبطة به.");
            }

            $stmt = $pdo->prepare("UPDATE unified_accounts SET account_status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);

            echo "<script>location.href='financial_accounts.php?success=4&type=" . ($type_filter ?? '') . "';</script>";
            exit();
        } catch (Exception $e) {
            $error = "خطأ في الحذف: " . $e->getMessage();
        }
    }
}

// إضافة حساب مالي جديد
if (isset($_POST['add_account'])) {
    if (!$canManageFinancialAccounts) {
        die("غير مصرح لك بإضافة حسابات مالية.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
        $account_name = $_POST['account_name'];
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $currency_id = (int)$_POST['currency_id'];
        $opening_balance = (float)($_POST['opening_balance'] ?? 0);
        $status = $_POST['status'] == 'active' ? 'active' : 'dormant';

        try {
            $pdo->beginTransaction();

            $account_type = 'asset';
            $normal_balance = 'debit';
            $new_code = '';

            if ($parent_id) {
                // جلب كود ونوع الحساب الأب
                $stmt_parent = $pdo->prepare("SELECT account_code, account_type, normal_balance FROM unified_accounts WHERE id = ?");
                $stmt_parent->execute([$parent_id]);
                $parent = $stmt_parent->fetch();

                if (!$parent) throw new Exception("يجب اختيار حساب أب صالح من الشجرة.");

                $account_type = $parent['account_type'];
                $normal_balance = $parent['normal_balance'];

                // توليد كود حساب جديد (زيادة الرقم الأخير)
                $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ?");
                $stmt_last->execute([$parent_id]);
                $last_code = $stmt_last->fetchColumn();

                if ($last_code) {
                    $new_code = (string)((int)$last_code + 1);
                } else {
                    $new_code = $parent['account_code'] . '001';
                }
            } else {
                // إضافة حساب رئيسي (مستوى 1)
                $account_type = $_POST['account_type'] ?? 'asset';
                $normal_balance = ($account_type == 'asset' || $account_type == 'expense') ? 'debit' : 'credit';

                $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id IS NULL AND account_code REGEXP '^[0-9]+$'");
                $stmt_last->execute();
                $last_code = $stmt_last->fetchColumn();
                $new_code = $last_code ? (string)((int)$last_code + 1) : '1';
            }

            // إدراج الحساب مع الحقول الجديدة
                            $stmt = $pdo->prepare("INSERT INTO unified_accounts 
                                (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                                VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$new_code, $account_name, $account_type, $normal_balance, $parent_id, $status]);

            $new_account_id = $pdo->lastInsertId();

            // تفعيل الرصيد الافتتاحي في الجدول الموحد
            $stmt_curr = $pdo->prepare("SELECT currency_code, exchange_rate FROM currencies WHERE id = ?");
            $stmt_curr->execute([$currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            $rate = (float)($curr['exchange_rate'] ?? 1);
            $opening_balance_base = $opening_balance * $rate;
            
            $stmt_base_balance = $pdo->prepare("INSERT INTO account_balances_unified 
                                (account_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0)");
                            $stmt_base_balance->execute([$new_account_id, $currency_id, $currency_code, $opening_balance, $opening_balance, $opening_balance_base, $opening_balance_base]);

            $pdo->commit();
            echo "<script>location.href='financial_accounts.php?success=1&type=" . ($type_filter ?? '') . "';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
        }
    }
}

// تحديث حساب مالي
if (isset($_POST['update_account'])) {
    if (!$canManageFinancialAccounts) {
        die("غير مصرح لك بتعديل الحسابات المالية.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
        $id = (int)$_POST['id'];
        $account_name = $_POST['account_name'];
        $status = $_POST['status'];
        $account_type = $_POST['account_type'] ?? 'asset';
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        try {
            $stmt = $pdo->prepare("UPDATE unified_accounts 
                SET account_name_ar = ?, account_status = ?, account_type = ?, parent_id = ?
                WHERE id = ?");
            $stmt->execute([$account_name, ($status == 'active' ? 'active' : 'dormant'), $account_type, $parent_id, $id]);
            echo "<script>location.href='financial_accounts.php?success=2&type=" . ($type_filter ?? '') . "';</script>";
        } catch (PDOException $e) {
            $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
        }
    }
}

// إضافة رصيد عملة جديد لحساب موجود
if (isset($_POST['add_account_balance'])) {
    if (!$canManageFinancialAccounts) {
        die("غير مصرح لك بإضافة أرصدة عملات.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
        $account_id = $_POST['account_id'];
        $currency_id = $_POST['currency_id'];
        $opening_balance = $_POST['opening_balance'];

        try {
            // Get currency code and exchange rate
            $stmt_curr = $pdo->prepare("SELECT currency_code, exchange_rate FROM currencies WHERE id = ?");
            $stmt_curr->execute([$currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            $rate = (float)($curr['exchange_rate'] ?? 1);
            $opening_balance_base = $opening_balance * $rate;
            
            $stmt = $pdo->prepare("INSERT INTO account_balances_unified (account_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0)");
            $stmt->execute([$account_id, $currency_id, $currency_code, $opening_balance, $opening_balance, $opening_balance_base, $opening_balance_base]);
            echo "<script>location.href='financial_accounts.php?success=3';</script>";
        } catch (PDOException $e) {
            $error = "حدث خطأ: ربما هذه العملة موجودة مسبقاً لهذا الحساب.";
        }
    }
}

// نظام العزل - الحسابات الخاصة بالمستخدم فقط (مع تعديل الأسماء لتناسب unified_accounts)
$ef = get_entity_filter('fa', 'branch_id', 'agent_id', 'employee_id', 'linked_user_id');
$where = "WHERE 1=1 AND (fa.account_status = 'active' OR fa.account_status = 'dormant')"; // تعديل ليناسب get_entity_filter إذا كان يعيد شرطاً
if (!empty($ef['clause']) && $ef['clause'] != '1=1') {
    $where .= " AND " . $ef['clause'];
}
$params = $ef['params'];

// إضافة فلتر النوع (بنك أو صندوق أو إيرادات أو مصاريف) إذا وجد
$type_filter = $_GET['type'] ?? '';
if ($type_filter === 'bank') {
    $where .= " AND (fa.account_code LIKE '102%')";
    $page_title = "إدارة البنوك";
} elseif ($type_filter === 'box') {
    $where .= " AND (fa.account_code LIKE '101%')";
    $page_title = "إدارة الصناديق";
} elseif ($type_filter === 'supplier') {
    $where .= " AND (fa.account_code LIKE '2111%')";
    $page_title = "إدارة حسابات الموردين";
} elseif ($type_filter === 'customer') {
    $where .= " AND (fa.account_code LIKE '11201%')";
    $page_title = "إدارة حسابات العملاء";
} elseif ($type_filter === 'expense') {
    $where .= " AND fa.account_type = 'expense'";
    $page_title = "إدارة حسابات المصاريف";
} elseif ($type_filter === 'income') {
    $where .= " AND fa.account_type = 'income'";
    $page_title = "إدارة حسابات الإيرادات";
} else {
    $page_title = "إدارة الحسابات المالية";
}

$accounts_stmt = $pdo->prepare("
    SELECT fa.id, fa.account_name_ar as account_name, fa.account_code, fa.account_type,
           fa.normal_balance, (fa.account_status = 'active') as is_active, fa.parent_id
    FROM unified_accounts fa
    $where
    ORDER BY fa.account_type, fa.account_code
");
$accounts_stmt->execute($params);
$accounts = $accounts_stmt->fetchAll();

// جلب الأرصدة من account_balances_unified للحسابات المعروضة فقط
$account_ids = array_column($accounts, 'id');
$all_balances = [];
$unified_balance_for_list = [];

if (!empty($account_ids)) {
    // We already have $all_balances_map from earlier, let's use that!
    foreach ($account_ids as $acc_id) {
        if (isset($all_balances_map[$acc_id])) {
            $all_balances[$acc_id] = $all_balances_map[$acc_id];
        }
    }
}

// دالة لحساب صافي الرصيد الموحد بالعملة الأساسية
function getUnifiedNetBalance($balances, $baseCurrency) {
    $total = 0;
    if (empty($balances)) return 0;
    
    foreach ($balances as $b) {
        $total += (float)($b['current_balance_base'] ?? ($b['current_balance'] * $b['exchange_rate']));
    }
    return $total;
}
?>

<style>
    .tree-node {
        margin-right: 20px;
    }

    .account-tree-item {
        padding: 8px 12px;
        border-radius: 6px;
        margin-bottom: 2px;
        background: #ffffff;
        border: 1px solid #f1f3f5;
        display: flex;
        align-items: center;
        transition: all 0.2s;
    }

    .account-tree-item:hover {
        background: #f8f9fa;
        border-color: #e9ecef;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .tree-toggle {
        cursor: pointer;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #adb5bd;
        transition: color 0.2s;
    }

    .tree-toggle:hover {
        color: #0d6efd;
    }

    .account-code {
        font-family: 'Courier New', Courier, monospace;
        background: #f8f9fa;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
        color: #6c757d;
        margin-left: 12px;
        border: 1px solid #e9ecef;
        min-width: 100px;
        text-align: center;
    }

    .account-name {
        font-size: 0.95rem;
        color: #212529;
        flex-grow: 1;
    }

    .account-type-badge {
        font-size: 0.65rem;
        padding: 3px 10px;
        border-radius: 20px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .children-container {
        margin-right: 12px;
        padding-right: 12px;
        border-right: 1px solid #dee2e6;
    }

    .balance-badge {
        min-width: 120px;
        text-align: left;
        font-family: 'Courier New', Courier, monospace;
        font-weight: 600;
    }

    body.theme-dark .account-tree-item {
        background: #1a2234;
        border-color: #2d3748;
        color: #e2e8f0;
    }

    body.theme-dark .account-name {
        color: #e2e8f0;
    }

    body.theme-dark .account-code {
        background: #111827;
        border-color: #2d3748;
        color: #94a3b8;
    }

    body.theme-dark .children-container {
        border-right-color: #2d3748;
    }
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-sitemap me-2 text-primary"></i> <?php echo $page_title; ?></h3>
        <div class="d-flex gap-2">
            <ul class="nav nav-pills bg-white p-1 rounded shadow-sm border" id="accountTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo empty($type_filter) ? 'active' : ''; ?> rounded px-4 py-2" id="tree-tab" data-bs-toggle="tab" data-bs-target="#accounts-tree" type="button" role="tab">
                        <i class="fas fa-tree me-1"></i> عرض الشجرة
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded px-4 py-2" id="accounts-tab" data-bs-toggle="tab" data-bs-target="#accounts-list" type="button" role="tab">
                        <i class="fas fa-th-large me-1"></i> عرض البطاقات
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo !empty($type_filter) ? 'active' : ''; ?> rounded px-4 py-2" id="table-tab" data-bs-toggle="tab" data-bs-target="#accounts-table-tab" type="button" role="tab">
                        <i class="fas fa-table me-1"></i> عرض الجدول
                    </button>
                </li>
            </ul>
            <?php if ($canManageFinancialAccounts): ?>
                <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                    <i class="fas fa-plus-circle me-1"></i> إضافة حساب جديد
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            <?php
            if ($_GET['success'] == 1) echo "تمت إضافة الحساب بنجاح.";
            if ($_GET['success'] == 2) echo "تم تحديث الحساب بنجاح.";
            if ($_GET['success'] == 3) echo "تم إضافة رصيد العملة بنجاح.";
            if ($_GET['success'] == 4) echo "تم حذف الحساب المالي بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- حقل البحث الديناميكي -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body py-2">
            <div class="input-group input-group-lg border-0 shadow-none">
                <span class="input-group-text border-0 bg-transparent text-primary"><i class="fas fa-search"></i></span>
                <input type="text" id="dynamicAccountSearch" class="form-control border-0 bg-transparent shadow-none fs-5" placeholder="ابحث عن حساب بالاسم أو الكود...">
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="tab-content mt-3" id="accountTabsContent">
        <!-- تبويب جدول الحسابات -->
        <div class="tab-pane fade <?php echo !empty($type_filter) ? 'show active' : ''; ?>" id="accounts-table-tab" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary small text-uppercase fw-bold">
                                <tr>
                                    <th class="px-4 py-3">كود الحساب</th>
                                    <th>اسم الحساب</th>
                                    <th>النوع</th>
                                    <th>الرصيد الحالي</th>
                                    <th>الحالة</th>
                                    <th class="text-center">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $types = [
                                    'asset' => 'أصول',
                                    'liability' => 'خصوم',
                                    'equity' => 'حقوق ملكية',
                                    'income' => 'إيرادات',
                                    'expense' => 'مصروفات',
                                    'receivable' => 'العملاء',
                                    'payable' => 'الموردين',
                                    'box' => 'صندوق',
                                    'bank' => 'بنك',
                                    'agent' => 'وكيل',
                                    'branch' => 'فرع',
                                    'عميل' => 'عميل',
                                    'مورد' => 'مورد',
                                    'وكيل' => 'وكيل'
                                ];
                                foreach ($accounts as $acc): ?>
                                    <tr class="account-item-row" data-search="<?php echo htmlspecialchars($acc['account_name'] . ' ' . $acc['account_code']); ?>">
                                        <td class="px-4">
                                            <code class="text-primary fw-bold"><?php echo $acc['account_code']; ?></code>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($acc['account_name']); ?></div>
                                            <div class="extra-small text-muted">ID: #<?php echo $acc['id']; ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary rounded-pill px-3">
                                                <?php echo $types[$acc['account_type']] ?? $acc['account_type']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $acc_balances = $all_balances[$acc['id']] ?? [];
                                            $unified_net = $aggregate_balances_map[$acc['id']] ?? 0;
                                            $accNormalBalance = $acc['normal_balance'] ?? 'debit';
                                            
                                            $accStatusText = '';
                                            if (abs($unified_net) < 0.01) {
                                                $accStatusText = 'متعادل';
                                                $accStatusClass = 'text-muted';
                                            } else {
                                                $accStatusText = ($accNormalBalance == 'debit') ? ($unified_net > 0 ? 'عليه' : 'له') : ($unified_net > 0 ? 'عليه' : 'له');
                                                $accStatusClass = $unified_net > 0 ? 'text-danger' : 'text-success';
                                            }
                                            ?>
                                            <div class="fw-bold <?php echo $accStatusClass; ?>" title="صافي الرصيد الموحد">
                                                <?php echo number_format(abs($unified_net), 2); ?>
                                                <small class="ms-1 text-primary"><?php echo $baseCurrency['currency_symbol']; ?></small>
                                                <span class="extra-small opacity-75">(<?php echo $accStatusText; ?>)</span>
                                            </div>
                                            <?php if (!empty($acc_balances)): ?>
                                                <div class="extra-small text-muted mt-1">
                                                    <?php foreach ($acc_balances as $b): 
                                                        if (abs($b['current_balance']) < 0.01) continue;
                                                    ?>
                                                        <div class="d-inline-block me-2 border-end pe-2 last-child-border-0">
                                                            <?php echo number_format($b['current_balance'], 2); ?> 
                                                            <span class="fw-bold"><?php echo $b['currency_code']; ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($acc['is_active']): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3">نشط</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3">معطل</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <a href="account_statement.php?id=<?php echo $acc['id']; ?>" class="btn btn-sm btn-light border-0" title="كشف حساب">
                                                    <i class="fas fa-file-invoice-dollar text-primary"></i>
                                                </a>
                                                <button class="btn btn-sm btn-light border-0" data-bs-toggle="modal" data-bs-target="#editAccountModal<?php echo $acc['id']; ?>" title="تعديل">
                                                    <i class="fas fa-edit text-warning"></i>
                                                </button>
                                                <?php if ($canManageFinancialAccounts): ?>
                                                    <form method="post" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الحساب؟')">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="delete_account" value="<?php echo $acc['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-light border-0" title="حذف">
                                                            <i class="fas fa-trash text-danger"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($accounts)): ?>
                                    <tr>
                                        <td colspan="6" class="py-5 text-center text-muted">لا توجد حسابات مطابقة للبحث</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- تبويب قائمة الحسابات -->
        <div class="tab-pane fade" id="accounts-list" role="tabpanel">
            <div class="row">
                <?php foreach ($accounts as $acc): ?>
                    <div class="col-md-4 mb-4 account-item-card" data-search="<?php echo htmlspecialchars($acc['account_name'] . ' ' . $acc['account_code']); ?>">
                        <div class="card h-100 shadow-sm border-0 rounded-4 overflow-hidden">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($acc['account_name']); ?></h5>
                                        <div class="d-flex gap-1 flex-wrap mb-2">
                                            <span class="badge bg-primary-subtle text-primary small px-3 rounded-pill">
                                                <?php
                                                $types = ['box' => 'صندوق', 'bank' => 'بنك', 'agent' => 'وكيل', 'branch' => 'فرع', 'expense' => 'مصروف', 'income' => 'إيراد', 'receivable' => 'العملاء', 'payable' => 'الموردين'];
                                                echo $types[$acc['account_type']] ?? $acc['account_type'];
                                                ?>
                                            </span>
                                            <span class="badge bg-info-subtle text-info small px-3 rounded-pill">
                                                <?php
                                                $owner_types = ['system' => 'النظام', 'agent' => 'وكيل', 'branch' => 'فرع', 'employee' => 'موظف', 'customer' => 'عميل', 'other' => 'آخر'];
                                                $o_type = $acc['owner_type'] ?? 'system';
                                                echo $owner_types[$o_type] ?? $o_type;
                                                ?>
                                            </span>
                                        </div>
                                        <div class="small text-muted mb-2">
                                            <i class="fas fa-barcode me-1"></i> كود الحساب: <?php echo htmlspecialchars($acc['account_code']); ?>
                                        </div>
                                    </div>
                                    <?php if ($canManageFinancialAccounts): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                <li><a class="dropdown-item" href="account_statement.php?id=<?php echo $acc['id']; ?>"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i> كشف الحساب</a></li>
                                                <li>
                                                    <hr class="dropdown-divider">
                                                </li>
                                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#editAccountModal<?php echo $acc['id']; ?>"><i class="fas fa-edit me-2"></i> تعديل الحساب</a></li>
                                                <li>
                                                    <form method="post" class="mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الحساب؟')">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="delete_account" value="<?php echo $acc['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="fas fa-trash me-2"></i> حذف الحساب
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body px-4">
                                <div class="mb-3">
                                    <h6 class="text-muted small fw-bold mb-3 border-bottom pb-2">الرصيد المحاسبي:</h6>
                                    <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded-3">
                                        <div>
                                            <div class="small fw-bold text-dark"><?php echo htmlspecialchars($baseCurrency['currency_name'] ?? 'العملة الأساسية'); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold <?php echo ($aggregate_balances_map[$acc['id']] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo number_format(abs($aggregate_balances_map[$acc['id']] ?? 0), 2); ?>
                                                <small class="ms-1"><?php echo htmlspecialchars($baseCurrency['currency_symbol'] ?? ''); ?></small>
                                            </div>
                                            <div class="x-small <?php echo ($aggregate_balances_map[$acc['id']] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                <?php echo ($aggregate_balances_map[$acc['id']] ?? 0) >= 0 ? '(له)' : '(عليه)'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                    $b = $all_balances[$acc['id']][0] ?? null;
                                    if ($b): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light bg-opacity-50 rounded-3">
                                            <div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($b['currency_name'] ?? 'عملة أخرى'); ?></div>
                                                <div class="x-small text-muted">افتتاحي: <?php echo number_format($b['opening_balance'] ?? 0, 2); ?></div>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold <?php echo $b['current_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo number_format(abs($b['current_balance']), 2); ?>
                                                    <small class="ms-1"><?php echo htmlspecialchars($b['currency_symbol'] ?? ''); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-4 pt-3 border-top">
                                    <div class="d-flex justify-content-between small">
                                        <span class="text-muted">الحالة:</span>
                                        <span class="badge <?php echo $acc['is_active'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?> rounded-pill px-3"><?php echo $acc['is_active'] ? 'نشط' : 'معطل'; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- تبويب شجرة الحسابات (الأول افتراضياً) -->
        <div class="tab-pane fade <?php echo empty($type_filter) ? 'show active' : ''; ?>" id="accounts-tree" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-secondary">دليل الحسابات المتكامل - وكالة الغزالي للسفريات والحج والعمرة</h6>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($isDeveloper): ?>
                            <form method="post" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من رغبتك في إعادة بناء شجرة الحسابات تلقائياً بناءً على بادئات أكواد الحسابات؟ سيقوم هذا بربط كل حساب بوالده الصحيح وإصلاح أي انقطاع في الشجرة.');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="dangerous_financial_action" value="repair_tree">
                                <button type="submit" class="btn btn-sm btn-warning rounded-pill px-3 text-dark fw-bold">
                                    <i class="fas fa-tools me-1"></i> إصلاح شجرة الحسابات
                                </button>
                            </form>
                            <form method="post" class="d-inline-block mb-0" onsubmit="return confirm('تنبيه هام جداً: هل أنت متأكد من رغبتك في تصفير جميع الأرصدة وحذف كافة الحركات والقيود المحاسبية بالكامل؟ لا يمكن التراجع عن هذا الإجراء!');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="dangerous_financial_action" value="zero_balances">
                                <button type="submit" class="btn btn-sm btn-danger rounded-pill px-3">
                                    <i class="fas fa-trash-alt me-1"></i> تصفير كافة الأرصدة
                                </button>
                            </form>
                        <?php endif; ?>
                        <div class="btn-group">
                        <button class="btn btn-sm btn-outline-secondary" onclick="expandAll()"><i class="fas fa-plus me-1"></i> توسيع الكل</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="collapseAll()"><i class="fas fa-minus me-1"></i> طي الكل</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4 bg-light bg-opacity-50">
                    <div id="tree-container">
                        <?php
                        function renderTree($nodes, $level = 1)
                        {
                            global $all_balances;
                            global $canManageFinancialAccounts;
                            foreach ($nodes as $node) {
                                $hasChildren = !empty($node['children']);
                                echo '<div class="tree-node" data-level="' . $level . '">';
                                echo '<div class="account-tree-item" data-search="' . htmlspecialchars($node['account_name_ar'] . ' ' . $node['account_code']) . '">';

                                // أيقونة التوسيع
                                if ($hasChildren) {
                                    echo '<span class="tree-toggle" onclick="toggleNode(this)"><i class="fas fa-chevron-down"></i></span>';
                                } else {
                                    echo '<span class="tree-toggle opacity-25"><i class="fas fa-circle" style="font-size: 0.4rem;"></i></span>';
                                }

                                // كود الحساب
                                echo '<span class="account-code">' . htmlspecialchars($node['account_code']) . '</span>';

                                // اسم الحساب
                                echo '<span class="account-name fw-bold">' . htmlspecialchars($node['account_name_ar']) . '</span>';

                                // نوع الحساب
                                $type_labels = [
                                    'asset' => ['label' => 'أصول', 'class' => 'bg-success-subtle text-success border-success-subtle'],
                                    'liability' => ['label' => 'خصوم', 'class' => 'bg-danger-subtle text-danger border-danger-subtle'],
                                    'equity' => ['label' => 'حقوق ملكية', 'class' => 'bg-primary-subtle text-primary border-primary-subtle'],
                                    'income' => ['label' => 'إيرادات', 'class' => 'bg-info-subtle text-info border-info-subtle'],
                                    'expense' => ['label' => 'مصروفات', 'class' => 'bg-warning-subtle text-warning border-warning-subtle'],
                                    'receivable' => ['label' => 'العملاء', 'class' => 'bg-warning-subtle text-warning border-warning-subtle'],
                                    'payable' => ['label' => 'الموردين', 'class' => 'bg-info-subtle text-info border-info-subtle'],
                                    'box' => ['label' => 'صندوق', 'class' => 'bg-success-subtle text-success border-success-subtle'],
                                    'bank' => ['label' => 'بنك', 'class' => 'bg-success-subtle text-success border-success-subtle'],
                                    'عميل' => ['label' => 'عميل', 'class' => 'bg-warning-subtle text-warning border-warning-subtle'],
                                    'مورد' => ['label' => 'مورد', 'class' => 'bg-info-subtle text-info border-info-subtle'],
                                    'وكيل' => ['label' => 'وكيل', 'class' => 'bg-primary-subtle text-primary border-primary-subtle'],
                                    'agent' => ['label' => 'وكيل', 'class' => 'bg-primary-subtle text-primary border-primary-subtle'],
                                    'branch' => ['label' => 'فرع', 'class' => 'bg-secondary-subtle text-secondary border-secondary-subtle']
                                ];
                                $typeInfo = $type_labels[$node['account_type']] ?? ['label' => $node['account_type'], 'class' => 'bg-secondary-subtle text-secondary border-secondary-subtle'];
                                echo '<span class="badge border ' . $typeInfo['class'] . ' account-type-badge me-2">' . $typeInfo['label'] . '</span>';

                                // الرصيد الموحد (له/عليه)
                                $balanceYER = (float)$node['current_balance'];
                                $normalBalance = $node['normal_balance'] ?? 'debit';
                                
                                $statusText = '';
                                if ($balanceYER == 0) {
                                    $statusText = 'متعادل';
                                    $statusClass = 'text-muted';
                                } else if ($normalBalance == 'debit') {
                                    // حساب مدين (أصل/عميل): موجب = عليه
                                    $statusText = $balanceYER > 0 ? 'عليه' : 'له';
                                    $statusClass = $balanceYER > 0 ? 'text-danger' : 'text-success';
                                } else {
                                    // حساب دائن (خصم/مورد): موجب = لنا عنده
                                    $statusText = $balanceYER > 0 ? 'لنا عنده' : 'له عندنا';
                                    $statusClass = $balanceYER > 0 ? 'text-success' : 'text-danger';
                                }

                                global $baseCurrency;
                                $baseSymbol = $baseCurrency['currency_symbol'] ?? 'ر.ي';

                                echo '<span class="balance-badge ms-3 ' . $statusClass . ' fw-bold" title="صافي الرصيد الموحد">';
                                echo number_format(abs($balanceYER), 2) . ' <small class="ms-1">' . $baseSymbol . '</small>';
                                echo ' <span class="extra-small opacity-75">(' . $statusText . ')</span>';
                                echo '</span>';

                                // أزرار الإجراءات في الشجرة
                                echo '<div class="ms-auto btn-group btn-group-sm">';
                                echo '<button class="btn btn-link text-warning p-1" data-bs-toggle="modal" data-bs-target="#editAccountModal' . $node['id'] . '" title="تعديل"><i class="fas fa-edit"></i></button>';
                                if ($canManageFinancialAccounts) {
                                    echo '<form method="post" class="d-inline-block mb-0" onsubmit="return confirm(\'هل أنت متأكد من حذف هذا الحساب؟\')">';
                                    echo csrf_input();
                                    echo '<input type="hidden" name="delete_account" value="' . (int)$node['id'] . '">';
                                    echo '<button type="submit" class="btn btn-link text-danger p-1" title="حذف"><i class="fas fa-trash"></i></button>';
                                    echo '</form>';
                                }
                                echo '</div>';

                                echo '</div>'; // end account-tree-item

                                if ($hasChildren) {
                                    echo '<div class="children-container">';
                                    renderTree($node['children'], $level + 1);
                                    echo '</div>';
                                }
                                echo '</div>'; // end tree-node
                            }
                        }
                        renderTree($accountTree);
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal تعديل الحساب (لجميع الحسابات في الشجرة) -->
<?php foreach ($all_chart_accounts as $acc):
    $acc['account_name'] = $acc['account_name_ar'] ?? $acc['account_name'];
?>
    <div class="modal fade" id="editAccountModal<?php echo $acc['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">تعديل الحساب: <?php echo htmlspecialchars($acc['account_name']); ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="id" value="<?php echo $acc['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">اسم الحساب</label>
                            <input type="text" name="account_name" class="form-control" value="<?php echo htmlspecialchars($acc['account_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">نوع الحساب</label>
                            <select name="account_type" class="form-select" required <?php echo !empty($type_filter) ? 'readonly' : ''; ?>>
                                <?php
                                $types = ['income' => 'إيرادات', 'expense' => 'مصروفات', 'asset' => 'أصول', 'liability' => 'خصوم', 'equity' => 'حقوق ملكية'];
                                foreach ($types as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $acc['account_type'] == $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">الربط مع شجرة الحسابات (الأب)</label>
                            <select name="parent_id" id="edit_account_parent_id_<?php echo $acc['id']; ?>" class="form-select border-0 bg-light">
                                <option value="">-- بدون أب (حساب رئيسي) --</option>
                                <?php foreach ($chart_accounts as $ca): ?>
                                    <option value="<?php echo $ca['id']; ?>" <?php echo ($acc['parent_id'] ?? '') == $ca['id'] ? 'selected' : ''; ?> data-type="<?php echo htmlspecialchars($ca['account_type']); ?>">
                                        <?php echo $ca['account_code'] . ' - ' . $ca['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">الحسابات الفرعية للأب المختار</label>
                            <select id="edit_account_children_<?php echo $acc['id']; ?>" class="form-select border-0 bg-light" disabled>
                                <option value="">-- اختر حساب أولاً --</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo ($acc['is_active'] ?? ($acc['status'] == 'active')) ? 'selected' : ''; ?>>نشط</option>
                                <option value="inactive" <?php echo !($acc['is_active'] ?? ($acc['status'] == 'active')) ? 'selected' : ''; ?>>معطل</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_account" class="btn btn-primary px-4">حفظ التغييرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    function toggleNode(element) {
        const container = element.closest('.tree-node').querySelector('.children-container');
        const icon = element.querySelector('i');
        if (container.style.display === 'none') {
            container.style.display = 'block';
            icon.classList.replace('fa-chevron-left', 'fa-chevron-down');
        } else {
            container.style.display = 'none';
            icon.classList.replace('fa-chevron-down', 'fa-chevron-left');
        }
    }

    function expandAll() {
        document.querySelectorAll('.children-container').forEach(el => el.style.display = 'block');
        document.querySelectorAll('.tree-toggle i').forEach(el => {
            if (el.classList.contains('fa-chevron-left')) {
                el.classList.replace('fa-chevron-left', 'fa-chevron-down');
            }
        });
    }

    function collapseAll() {
        document.querySelectorAll('.children-container').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.tree-toggle i').forEach(el => {
            if (el.classList.contains('fa-chevron-down')) {
                el.classList.replace('fa-chevron-down', 'fa-chevron-left');
            }
        });
    }

    // Pass all accounts to JS
    const allAccounts = <?php echo json_encode($all_accounts); ?>;
    
    // Function to update children dropdown
    function updateChildrenDropdown(parentSelectId, childrenSelectId) {
        const parentSelect = document.getElementById(parentSelectId);
        const childrenSelect = document.getElementById(childrenSelectId);
        
        if (!parentSelect || !childrenSelect) return;
        
        const parentId = parentSelect.value;
        
        // Clear and reset children select
        childrenSelect.innerHTML = '';
        
        if (!parentId) {
            childrenSelect.disabled = true;
            childrenSelect.innerHTML = '<option value="">-- اختر حساب أولاً --</option>';
            return;
        }
        
        // Filter children of this parent
        const children = allAccounts.filter(account => account.parent_id == parentId);
        
        if (children.length === 0) {
            childrenSelect.disabled = true;
            childrenSelect.innerHTML = '<option value="">-- لا توجد حسابات فرعية --</option>';
            return;
        }
        
        childrenSelect.disabled = false;
        childrenSelect.innerHTML = '<option value="">-- اختر حساب فرعي --</option>';
        
        children.forEach(child => {
            const option = document.createElement('option');
            option.value = child.id;
            option.textContent = child.account_code + ' - ' + child.account_name_ar;
            childrenSelect.appendChild(option);
        });
    }
    
    // Filter parent account dropdown based on selected account type
    document.addEventListener('DOMContentLoaded', function() {
        // Handle all account type selects (both add and edit)
        document.querySelectorAll('select[name="account_type"]').forEach(function(accountTypeSelect) {
            // Find the corresponding parent select
            let parentAccountSelect = null;
            let childrenAccountSelect = null;
            if (accountTypeSelect.closest('#addAccountModal')) {
                parentAccountSelect = document.getElementById('add_account_parent_id');
                childrenAccountSelect = document.getElementById('add_account_children');
            } else {
                // It's an edit modal - find its ID
                const modal = accountTypeSelect.closest('.modal');
                if (modal) {
                    const modalId = modal.id;
                    if (modalId.startsWith('editAccountModal')) {
                        const accId = modalId.replace('editAccountModal', '');
                        parentAccountSelect = document.getElementById('edit_account_parent_id_' + accId);
                        childrenAccountSelect = document.getElementById('edit_account_children_' + accId);
                    }
                }
            }

            if (accountTypeSelect && parentAccountSelect) {
                const parentOptions = Array.from(parentAccountSelect.querySelectorAll('option'));

                function filterParentAccounts() {
                    const selectedType = accountTypeSelect.value;
                    parentOptions.forEach(option => {
                        if (option.value === '') {
                            option.style.display = '';
                        } else if (option.getAttribute('data-type') === selectedType) {
                            option.style.display = '';
                        } else {
                            option.style.display = 'none';
                        }
                    });
                    // Reset selected value if current is hidden
                    const currentValue = parentAccountSelect.value;
                    if (currentValue) {
                        const currentOption = parentAccountSelect.querySelector('option[value="' + currentValue + '"]');
                        if (currentOption && currentOption.style.display === 'none') {
                            parentAccountSelect.value = '';
                        }
                    }
                    // Update children dropdown
                    if (childrenAccountSelect) {
                        if (parentAccountSelect.id.startsWith('add_account')) {
                            updateChildrenDropdown('add_account_parent_id', 'add_account_children');
                        } else {
                            const accId = parentAccountSelect.id.replace('edit_account_parent_id_', '');
                            updateChildrenDropdown('edit_account_parent_id_' + accId, 'edit_account_children_' + accId);
                        }
                    }
                }

                accountTypeSelect.addEventListener('change', filterParentAccounts);
                filterParentAccounts(); // Run on load
                
                // Add event listener for parent select change to update children
                parentAccountSelect.addEventListener('change', function() {
                    if (childrenAccountSelect) {
                        if (parentAccountSelect.id.startsWith('add_account')) {
                            updateChildrenDropdown('add_account_parent_id', 'add_account_children');
                        } else {
                            const accId = parentAccountSelect.id.replace('edit_account_parent_id_', '');
                            updateChildrenDropdown('edit_account_parent_id_' + accId, 'edit_account_children_' + accId);
                        }
                    }
                });
                
                // Initial children dropdown update
                if (childrenAccountSelect) {
                    if (parentAccountSelect.id.startsWith('add_account')) {
                        updateChildrenDropdown('add_account_parent_id', 'add_account_children');
                    } else {
                        const accId = parentAccountSelect.id.replace('edit_account_parent_id_', '');
                        updateChildrenDropdown('edit_account_parent_id_' + accId, 'edit_account_children_' + accId);
                    }
                }
            }
        });
    });
</script>
</div>

<!-- Modal إضافة حساب -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white border-0">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> إضافة حساب مالي جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الحساب</label>
                            <input type="text" name="account_name" class="form-control border-0 bg-light" placeholder="مثلاً: صندوق المركز" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">نوع الحساب</label>
                            <select name="account_type" class="form-select border-0 bg-light" required <?php echo !empty($type_filter) ? 'readonly' : ''; ?>>
                                <?php
                                $types = ['income' => 'إيرادات', 'expense' => 'مصروفات', 'asset' => 'أصول', 'liability' => 'خصوم', 'equity' => 'حقوق ملكية'];
                                foreach ($types as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $type_filter === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($type_filter)): ?>
                                <input type="hidden" name="account_type" value="<?php echo $type_filter; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الربط مع شجرة الحسابات (الأب)</label>
                            <select name="parent_id" id="add_account_parent_id" class="form-select border-0 bg-light">
                                <option value="">-- بدون أب (حساب رئيسي) --</option>
                                <?php foreach ($chart_accounts as $ca): ?>
                                    <option value="<?php echo $ca['id']; ?>" data-type="<?php echo htmlspecialchars($ca['account_type']); ?>">
                                        <?php echo $ca['account_code'] . ' - ' . $ca['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحسابات الفرعية للأب المختار</label>
                            <select id="add_account_children" class="form-select border-0 bg-light" disabled>
                                <option value="">-- اختر حساب أولاً --</option>
                            </select>
                        </div>

                        <div class="col-12 mb-4">
                            <hr>
                            <h6 class="fw-bold text-primary mb-3">الرصيد الافتتاحي (العملة الأولى):</h6>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" class="form-select border-0 bg-light" required>
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $c['is_default'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['currency_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الرصيد الافتتاحي</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control border-0 bg-light" value="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحد الائتماني للحساب</label>
                            <input type="number" step="0.01" name="credit_limit" class="form-control border-0 bg-light" value="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" class="form-select border-0 bg-light">
                                <option value="active">نشط</option>
                                <option value="inactive">معطل</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_account" class="btn btn-primary rounded-pill px-4">إضافة الحساب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // البحث الديناميكي
    document.getElementById('dynamicAccountSearch').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();

        if (searchTerm === '') {
            // إعادة تعيين جميع العناصر عند مسح البحث
            document.querySelectorAll('.account-item-row').forEach(row => row.style.display = '');
            document.querySelectorAll('.account-item-card').forEach(card => card.style.display = '');
            
            const treeNodes = document.querySelectorAll('.tree-node');
            treeNodes.forEach(node => {
                node.style.display = 'block';
            });
            return;
        }

        // تصفية الجدول
        const tableRows = document.querySelectorAll('.account-item-row');
        tableRows.forEach(row => {
            const text = row.getAttribute('data-search').toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });

        // تصفية البطاقات
        const cardItems = document.querySelectorAll('.account-item-card');
        cardItems.forEach(card => {
            const text = card.getAttribute('data-search').toLowerCase();
            card.style.display = text.includes(searchTerm) ? '' : 'none';
        });

        // تصفية شجرة الحسابات
        const treeNodes = document.querySelectorAll('.tree-node');
        treeNodes.forEach(node => {
            const accountItem = node.querySelector('.account-tree-item');
            if (!accountItem) return;
            
            // جلب النص للبحث من data-search attribute
            const text = accountItem.getAttribute('data-search').toLowerCase();
            
            if (text.includes(searchTerm)) {
                node.style.display = 'block';
                // إظهار جميع الأباء لكي يظهر الحساب المطابق
                let parent = node.parentElement.closest('.tree-node');
                while (parent) {
                    parent.style.display = 'block';
                    const parentContainer = parent.querySelector('.children-container');
                    if (parentContainer) parentContainer.style.display = 'block';
                    parent = parent.parentElement.closest('.tree-node');
                }
            } else {
                // إخفاء العنصر إذا لم يطابق البحث
                node.style.display = 'none';
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>

