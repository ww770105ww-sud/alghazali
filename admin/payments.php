<?php
require_once 'header.php';
require_once '../includes/CurrencyExchange.php';

/**
 * وظائف الصلاحيات والتحقق
 */
function has_permission_v3($permission_code, $branch_id = null)
{
    global $pdo, $user_role, $user_branch_id, $user_role_id;
    if ($user_role == 'developer') return true;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions_unified rp JOIN unified_permissions p ON rp.permission_id = p.id WHERE rp.role_id = ? AND p.permission_code = ?");
    $stmt->execute([$user_role_id, $permission_code]);
    return $stmt->fetchColumn() > 0;
}

function can_edit_voucher($voucher)
{
    global $user_role, $user_id, $user_branch_id;
    if ($user_role == 'developer') return true;
    if (!in_array($voucher['status'], ['draft', 'cancelled'])) return false;

    // استخدام الصلاحيات الموحدة
    if (has_permission_v3('voucher_edit')) return true;

    if ($user_role == 'admin') return true;
    if ($user_role == 'branch_manager' && $voucher['branch_id'] == $user_branch_id) return true;
    if ($user_role == 'accountant') return true;
    return $voucher['created_by'] == $user_id;
}

function can_post_voucher($voucher)
{
    global $user_role, $user_branch_id;
    if ($user_role == 'developer') return true;
    if (!in_array($voucher['status'], ['draft', 'cancelled'])) return false;

    // استخدام الصلاحيات الموحدة
    if (has_permission_v3('voucher_post')) return true;

    if ($user_role == 'admin') return true;
    if ($user_role == 'accountant') return true;
    if ($user_role == 'branch_manager' && $voucher['branch_id'] == $user_branch_id) return true;
    return false;
}

function can_reverse_voucher($voucher)
{
    global $user_role;
    if ($user_role == 'developer') return true;
    if ($voucher['status'] != 'posted') return false;

    // السندات العكسية نفسها لا يمكن عكسها
    if (!empty($voucher['original_voucher_id'])) return false;
    if ($voucher['is_reversed']) return false;

    // الصلاحيات التفصيلية حسب نوع السند
    $ttype = strtolower($voucher['transaction_type'] ?? '');
    if ($ttype === 'receipt' && has_permission_v3('receipt_reverse')) return true;
    if ($ttype === 'payment' && has_permission_v3('payment_reverse')) return true;

    // توافق خلفي: الصلاحية العامة القديمة
    if (has_permission_v3('voucher_reverse')) return true;

    if ($user_role == 'admin') return true;
    return false;
}

function can_delete_voucher($voucher)
{
    global $user_role, $user_id, $user_branch_id;
    if ($user_role == 'developer') return true;

    // لا يمكن حذف السندات المرحلة
    if ($voucher['status'] == 'posted') return false;

    // تحديد هل السند أصلي أم عكسي
    $is_reversal = !empty($voucher['original_voucher_id']) || ($voucher['reference_type'] ?? '') === 'reversal';
    $ttype = strtolower($voucher['transaction_type'] ?? '');

    // الصلاحيات التفصيلية
    if (!$is_reversal) {
        if ($ttype === 'receipt' && has_permission_v3('receipt_delete_original')) return true;
        if ($ttype === 'payment' && has_permission_v3('payment_delete_original')) return true;
    } else {
        if ($ttype === 'receipt' && has_permission_v3('receipt_delete_reversal')) return true;
        if ($ttype === 'payment' && has_permission_v3('payment_delete_reversal')) return true;
    }

    // توافق خلفي: الصلاحية العامة القديمة
    if (has_permission_v3('voucher_delete')) return true;

    if ($user_role == 'admin') return true;
    if ($user_role == 'branch_manager' && $voucher['branch_id'] == $user_branch_id) return true;
    if ($user_role == 'accountant') return true;
    return $voucher['created_by'] == $user_id;
}

/**
 * تسجيل سجل التدقيق
 */
// log_audit is defined in includes/functions.php

if (!has_permission_v3('payments_view') && !has_permission_v3('voucher_create')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$success_msg = "";
$error_msg = "";

if (isset($_POST['add_payment'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "خطأ في التحقق من الطلب (CSRF).";
    } elseif (!has_permission_v3('voucher_create')) {
        $error_msg = "ليس لديك صلاحية إنشاء سندات.";
    } else {
        try {
            $pdo->beginTransaction();
            $id = $_POST['edit_payment_id'] ?? null;
            $date = $_POST['date'];

            // --- التحقق من إغلاق الفترة المالية ---
            if (is_period_closed($pdo, $date)) {
                throw new Exception("تنبيه: لا يمكن إنشاء/تعديل السند. التاريخ المحدد ($date) يقع ضمن فترة مالية مغلقة.");
            }
            // --- نهاية التحقق ---

            $payee_type = $_POST['payee_type'];
            $payee_id = $_POST['payee_id'];
            $amount = $_POST['amount'];
            $currency_id = $_POST['currency_id'];
            $account_id = $_POST['account_id'];
            $cost_center_id = !empty($_POST['cost_center_id']) ? (int)$_POST['cost_center_id'] : null;

            // Check require cost center setting
            $stmt_setting = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'require_cost_center'");
            $stmt_setting->execute();
            $require_cost_center = (bool)$stmt_setting->fetchColumn();
            
            if ($require_cost_center && !$cost_center_id) {
                throw new Exception("يرجى اختيار مركز التكلفة.");
            }

            // التحقق من صحة حساب الصندوق/البنك
            $cash_bank_validation = validate_postable_account($pdo, $account_id);
            if (!$cash_bank_validation['valid']) {
                throw new Exception($cash_bank_validation['message']);
            }

            // التحقق من صحة حساب المستفيد
            $payee_validation = validate_postable_account($pdo, $payee_id);
            if (!$payee_validation['valid']) {
                throw new Exception($payee_validation['message']);
            }

            // Validate currency for the cash/bank account ($account_id)
            $stmt_check_currency_cash_bank = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ? AND is_frozen = 0");
            $stmt_check_currency_cash_bank->execute([$account_id, $currency_id]);
            if ($stmt_check_currency_cash_bank->fetchColumn() == 0) {
                throw new Exception("العملة المحددة غير مفعلة أو مجمدة لحساب الصندوق/البنك المحدد.");
            }

            // Validate currency for the payee's account ($party_account_id)
            $party_account_id = $payee_id; // from line 119
            $stmt_check_currency_payee = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ? AND is_frozen = 0");
            $stmt_check_currency_payee->execute([$party_account_id, $currency_id]);
            if ($stmt_check_currency_payee->fetchColumn() == 0) {
                throw new Exception("العملة المحددة غير مفعلة أو مجمدة لحساب المستفيد.");
            }

            // فرض حدود الدين/المدين من جانب الخادم
            // جلب الإعدادات العامة للتحقق هل الرقابة مفعلة أم لا
            $stmt_sys_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('allow_negative_balance')");
            $sys_settings = $stmt_sys_settings->fetchAll(PDO::FETCH_KEY_PAIR);
            $allow_negative = (bool)($sys_settings['allow_negative_balance'] ?? false);

            // 1. لحساب الصندوق/البنك (account_id)
            $stmt_cash_bank_limits = $pdo->prepare("SELECT current_balance, credit_limit, debit_limit FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
            $stmt_cash_bank_limits->execute([$account_id, $currency_id]);
            $cash_bank_info = $stmt_cash_bank_limits->fetch(PDO::FETCH_ASSOC);

            if ($cash_bank_info) {
                $cash_bank_current_balance = floatval($cash_bank_info['current_balance']);
                $cash_bank_credit_limit = floatval($cash_bank_info['credit_limit']);
                $new_cash_bank_balance = $cash_bank_current_balance - $amount; // Payment decreases balance

                // إذا كان الرصيد سيصبح سالباً والرقابة مفعلة (السماح بالرصيد السالب معطل)
                if (!$allow_negative && $new_cash_bank_balance < 0) {
                    throw new Exception("عذراً، الرصيد في الصندوق/البنك غير كافٍ. الرصيد الحالي: " . number_format($cash_bank_current_balance, 2));
                }

                if ($cash_bank_credit_limit != 0 && $new_cash_bank_balance < $cash_bank_credit_limit) {
                    throw new Exception("المبلغ المدفوع يتجاوز الحد الائتماني لحساب الصندوق/البنك. الرصيد الجديد المتوقع: " . number_format($new_cash_bank_balance, 2));
                }
            }

            // 2. لحساب المستفيد (party_account_id)
            $stmt_payee_limits = $pdo->prepare("SELECT current_balance, credit_limit, debit_limit FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
            $stmt_payee_limits->execute([$party_account_id, $currency_id]);
            $payee_info = $stmt_payee_limits->fetch(PDO::FETCH_ASSOC);

            if ($payee_info) {
                $payee_current_balance = floatval($payee_info['current_balance']);
                $payee_debit_limit = floatval($payee_info['debit_limit']);
                $new_payee_balance = $payee_current_balance + $amount; // Payment increases payee's balance

                if ($payee_debit_limit != 0 && $new_payee_balance > $payee_debit_limit) {
                    throw new Exception("المبلغ المدفوع يتجاوز الحد الدائن لحساب المستفيد. الرصيد الجديد المتوقع: " . number_format($new_payee_balance, 2));
                }
            }
            $description = $_POST['description'];
            $allocations = $_POST['allocations'] ?? [];

            // تحديد entity_id - ابحث عن الكيان من رقم الحساب
            $entity_id = $payee_id; // default to account id
            $table_map = ['customer' => 'customers', 'agent' => 'agents', 'supplier' => 'suppliers', 'employee' => 'employees', 'branch' => 'branches'];
            if (isset($table_map[$payee_type])) {
                $stmt_entity = $pdo->prepare("SELECT id FROM " . $table_map[$payee_type] . " WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL");
                $stmt_entity->execute([$payee_id]);
                $found_entity_id = $stmt_entity->fetchColumn();
                if ($found_entity_id) {
                    $entity_id = $found_entity_id;
                }
            }

            // جلب حساب الطرف - الآن payee_id هو رقم الحساب مباشرة
            $party_account_id = $payee_id;

            // التحقق من صحة الحساب
            $stmt_check = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ? AND is_active = 1");
            $stmt_check->execute([$party_account_id]);
            if (!$stmt_check->fetch()) {
                throw new Exception("الحساب المحدد غير صالح أو غير نشط.");
            }

            // التحقق من الحدود المالية باستخدام الدالة الموحدة
            // 1. حساب الصندوق/البنك (الدافع): ينقص رصيده
            check_account_limits($pdo, $account_id, $currency_id, -$amount);

            // 2. حساب المستلم (المورد/الطرف الآخر): يزيد رصيده
            check_account_limits($pdo, $party_account_id, $currency_id, $amount);

            // حساب إجمالي التوزيعات
            $total_allocated = 0;
            if (!empty($allocations)) {
                foreach ($allocations as $inv_id => $alloc_amount) {
                    $total_allocated += floatval($alloc_amount);
                }
            }

            // إذا كان المبلغ الإجمالي أكبر من التوزيعات، سنقوم بتقسيمه
            $remaining_advance = $amount - $total_allocated;

            if ($total_allocated > $amount) {
                throw new Exception("المبلغ الموزع ($total_allocated) أكبر من مبلغ السند ($amount).");
            }

            if ($id) {
                // تعديل (للمسودة فقط)
                $stmt_old = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                $stmt_old->execute([$id]);
                $old_voucher = $stmt_old->fetch();
                if (!can_edit_voucher($old_voucher)) throw new Exception("لا يمكن تعديل هذا السند.");

                // جلب التوزيعات القديمة - بدون خصم من الفواتير
                // يتم الخصم من الفواتير فقط عند الترحيل
                $stmt_old_allocs = $pdo->prepare("SELECT invoice_id, allocated_amount FROM payment_allocations WHERE financial_transaction_id = ?");
                $stmt_old_allocs->execute([$id]);
                $old_allocs = $stmt_old_allocs->fetchAll();
                $old_invoice_ids = array_values(array_unique(array_map(static function ($row) {
                    return (int)($row['invoice_id'] ?? 0);
                }, $old_allocs)));

                // لا يتم خصم المبالغ القديمة من الفواتير هنا
                // لأن الفواتير تُحدّث فقط عند ترحيل السند

                // ✅ إذا كان السند مرحلاً: نعكس القيود اليومية ثم نحذفها
                // (التعديل لا يُعاد الترحيل تلقائياً — السند يعود لمسودة ويُرحَّل يدوياً)
                if ($old_voucher['status'] == 'posted') {
                    if (!balances_triggers_enabled($pdo)) {
                        apply_transaction_balances($pdo, (int)$id, -1);
                    }

                    // حذف القيود — السند سيُرحَّل من جديد بعد التعديل
                    $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$id]);
                }

                $stmt = $pdo->prepare("UPDATE financial_transactions SET transaction_date = ?, entity_type = ?, entity_id = ?, party_account_id = ?, cash_bank_account_id = ?, currency_id = ?, amount = ?, description = ?, cost_center_id = ?, updated_at = NOW(), updated_by = ?, updated_ip = ? WHERE id = ?");
                $stmt->execute([$date, $payee_type, $entity_id, $party_account_id, $account_id, $currency_id, $amount, $description, $cost_center_id, $_SESSION['admin_id'], $_SERVER['REMOTE_ADDR'], $id]);
                $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$id]);
                $voucher_id = $id;

                // جلب البيانات الجديدة للسجل
                $stmt_new = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                $stmt_new->execute([$id]);
                $new_voucher = $stmt_new->fetch();
                $new_voucher['allocations'] = $allocations;

                log_audit($pdo, 'update', 'financial_transactions', $id, $old_voucher, $new_voucher, "تعديل سند صرف");

                // ✅ بعد التعديل: السند يصبح مسودة تلقائياً
                // لا نُطبّق الأرصدة الجديدة الآن — سيتم ذلك عند الترحيل
                if ($old_voucher['status'] == 'posted') {
                    $pdo->prepare("UPDATE financial_transactions SET status = 'draft', posted_at = NULL, posted_by = NULL WHERE id = ?")
                        ->execute([$id]);
                    php_recalculate_invoice_payments($pdo, $old_invoice_ids);
                }
            } else {
                // إنشاء السند الأول (بالمبلغ الموزع أو المبلغ كامل إذا لم يكن هناك توزيع)
                $first_voucher_amount = ($total_allocated > 0) ? $total_allocated : $amount;

                // Use our PHP function to create the voucher
                require_once '../includes/accounting_functions.php';

                // Generate transaction number
                $transaction_number = fn_get_next_sequence($pdo, 'payment');
                
                // Get exchange rate
                $stmt_curr = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE id = ?");
                $stmt_curr->execute([$currency_id]);
                $exchange_rate = (float)($stmt_curr->fetchColumn() ?: 1);

                // Insert first voucher
                $stmt = $pdo->prepare("INSERT INTO financial_transactions (
                    transaction_number, transaction_date, branch_id, transaction_type,
                    entity_type, entity_id, amount, currency_id, cash_bank_account_id, party_account_id,
                    description, cost_center_id, created_by, exchange_rate, status
                ) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
                $stmt->execute([
                    $transaction_number,
                    $_SESSION['branch_id'],
                    'payment',
                    $payee_type,
                    $entity_id,
                    $first_voucher_amount,
                    $currency_id,
                    $account_id,
                    $party_account_id,
                    $description . ($remaining_advance > 0 ? " (جزء سداد فواتير)" : ""),
                    $cost_center_id,
                    $_SESSION['admin_id'],
                    $exchange_rate
                ]);
                $voucher_id = $pdo->lastInsertId();

                // جلب البيانات الجديدة للسجل
                $stmt_new = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                $stmt_new->execute([$voucher_id]);
                $new_voucher = $stmt_new->fetch();
                $new_voucher['allocations'] = $allocations;

                log_audit($pdo, 'create', 'financial_transactions', $voucher_id, null, $new_voucher, "إنشاء سند صرف");

                // إذا كان هناك متبقي، ننشئ سنداً ثانياً كـ "رصيد في الحساب"
                if ($total_allocated > 0 && $remaining_advance > 0) {
                    $transaction_number2 = fn_get_next_sequence($pdo, 'payment');
                    $stmt2 = $pdo->prepare("INSERT INTO financial_transactions (
                        transaction_number, transaction_date, branch_id, transaction_type,
                        entity_type, entity_id, amount, currency_id, cash_bank_account_id, party_account_id,
                        description, cost_center_id, created_by, exchange_rate, status
                    ) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
                    $stmt2->execute([
                        $transaction_number2,
                        $_SESSION['branch_id'],
                        'payment',
                        $payee_type,
                        $entity_id,
                        $remaining_advance,
                        $currency_id,
                        $account_id,
                        $party_account_id,
                        $description . " (رصيد متبقي في الحساب)",
                        null,
                        $_SESSION['admin_id'],
                        $exchange_rate
                    ]);
                }
            }

            if (!empty($allocations)) {
                foreach ($allocations as $inv_id => $alloc_amount) {
                    $alloc_amount = floatval($alloc_amount);
                    if ($alloc_amount > 0) {
                        $pdo->prepare("INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)")->execute([$voucher_id, $inv_id, $alloc_amount]);

                        // ملاحظة: لا يتم تحديث الفاتورة هنا
                        // يتم تحديث amount_received و payment_status فقط عند ترحيل السند
                    }
                }
            }

            $pdo->commit();
            header("Location: payments.php?success=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = $e->getMessage();
        }
    }
}

$where_clauses = ["transaction_type = 'payment'"];
$params = [];
if (!empty($_GET['search_num'])) {
    $where_clauses[] = "transaction_number LIKE ?";
    $params[] = '%' . $_GET['search_num'] . '%';
}
if (!empty($_GET['from_date'])) {
    $where_clauses[] = "transaction_date >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_clauses[] = "transaction_date <= ?";
    $params[] = $_GET['to_date'];
}
$where_sql = implode(' AND ', $where_clauses);

$query = "SELECT t.*, c.currency_symbol, coa.account_name_ar as account_name,
                CASE
                    WHEN t.entity_type = 'agent' THEN (SELECT agent_name FROM agents WHERE id = t.entity_id)
                    WHEN t.entity_type = 'branch' THEN (SELECT branch_name FROM branches WHERE id = t.entity_id)
                    WHEN t.entity_type = 'customer' THEN (SELECT full_name FROM customers WHERE id = t.entity_id)
                    WHEN t.entity_type = 'supplier' THEN (SELECT supplier_name FROM suppliers WHERE id = t.entity_id)
                    WHEN t.entity_type = 'employee' THEN (SELECT full_name FROM employees WHERE id = t.entity_id)
                    WHEN t.entity_type = 'expense' THEN (SELECT account_name_ar FROM unified_accounts WHERE id = t.entity_id)
                    ELSE 'غير معروف'
                END as party_name,
                (SELECT EXISTS(SELECT 1 FROM financial_transactions rt 
                              WHERE rt.reference_type = 'reversal' AND rt.reference_id = t.id LIMIT 1)) as has_reversal
          FROM financial_transactions t
          JOIN unified_accounts coa ON t.cash_bank_account_id = coa.id
          JOIN currencies c ON t.currency_id = c.id
          WHERE $where_sql
          ORDER BY t.transaction_date DESC, t.created_at DESC";
$payments = $pdo->prepare($query);
$payments->execute($params);
$payments = $payments->fetchAll();

$accounts = get_cash_bank_postable_accounts($pdo);
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies")->fetchAll();
$cost_centers = $pdo->query("SELECT id, center_code, center_name_ar FROM cost_centers WHERE is_active = 1 ORDER BY center_code ASC")->fetchAll();
$settings = getSettings($pdo);
$require_cost_center = !empty($settings['require_cost_center']);
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --apple-bg: #f5f5f7;
        --apple-card-bg: rgba(255, 255, 255, 0.72);
        --apple-blue: #007aff;
        --apple-green: #34c759;
        --apple-red: #ff3b30;
        --apple-orange: #ff9500;
        --apple-gray: #8e8e93;
        --apple-radius: 20px;
        --apple-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        --transition-base: all 0.3s cubic-bezier(0.25, 0.1, 0.25, 1);
    }

    body {
        background-color: var(--apple-bg);
        font-family: 'Inter', -apple-system, sans-serif;
        color: #1d1d1f;
    }

    .apple-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2rem;
    }

    .apple-card {
        background: var(--apple-card-bg);
        backdrop-filter: blur(20px);
        border-radius: var(--apple-radius);
        border: 1px solid rgba(255, 255, 255, 0.4);
        box-shadow: var(--apple-shadow);
        margin-bottom: 2rem;
        overflow: hidden;
    }

    .apple-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }

    .btn-apple-primary {
        background: var(--apple-blue);
        color: white;
        border: none;
        padding: 0.6rem 1.5rem;
        border-radius: 40px;
        font-weight: 600;
        transition: var(--transition-base);
    }

    .btn-apple-primary:hover {
        background: #0066cc;
        transform: scale(1.02);
    }

    .apple-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .apple-table th {
        background: rgba(0, 0, 0, 0.02);
        padding: 1rem;
        font-weight: 600;
        color: var(--apple-gray);
        font-size: 0.85rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .apple-table td {
        padding: 1rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.03);
        vertical-align: middle;
    }

    .apple-badge {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.75rem;
    }

    .bg-draft {
        background: #fff3cd;
        color: #856404;
    }

    .bg-posted {
        background: #d4edda;
        color: #155724;
    }

    .bg-cancelled {
        background: #f8d7da;
        color: #721c24;
    }

    .apple-modal {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(30px);
        border-radius: 28px;
        border: 1px solid rgba(255, 255, 255, 0.4);
    }

    .apple-input {
        background: rgba(0, 0, 0, 0.04);
        border: 1px solid transparent;
        border-radius: 12px;
        padding: 0.5rem 0.8rem;
        font-size: 0.9rem;
    }

    .apple-input:focus {
        background: white;
        border-color: var(--apple-blue);
        box-shadow: 0 0 0 4px rgba(0, 122, 255, 0.1);
        outline: none;
    }

    .apple-modal {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 28px;
        border: 1px solid rgba(255, 255, 255, 0.4);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.1);
    }
    
    .modal-dialog-scrollable .modal-content {
        max-height: calc(100vh - 3.5rem);
    }
    
    .modal-dialog-scrollable .modal-body {
        overflow-y: auto;
    }
    
    .modal-dialog-scrollable .modal-footer {
        position: sticky;
        bottom: 0;
        background: inherit;
        z-index: 10;
    }
</style>

<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">سندات الصرف</h1>
            <p class="text-muted small mb-0">نظام إدارة المدفوعات وتوزيع المبالغ على الموردين</p>
        </div>
        <button class="btn-apple-primary" data-bs-toggle="modal" data-bs-target="#paymentModal" onclick="resetForm()"><i class="fas fa-plus me-2"></i> إنشاء سند جديد</button>
    </div>

    <?php if (isset($_GET['success'])): ?><div class="alert alert-success border-0 rounded-4 shadow-sm mb-4">تمت العملية بنجاح.</div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="apple-card p-4">
        <form method="GET" class="row g-3">
            <div class="col-md-3"><input type="text" name="search_num" class="form-control apple-input" placeholder="رقم السند..." value="<?php echo h($_GET['search_num'] ?? ''); ?>"></div>
            <div class="col-md-3"><input type="date" name="from_date" class="form-control apple-input" value="<?php echo h($_GET['from_date'] ?? ''); ?>"></div>
            <div class="col-md-3"><input type="date" name="to_date" class="form-control apple-input" value="<?php echo h($_GET['to_date'] ?? ''); ?>"></div>
            <div class="col-md-3"><button type="submit" class="btn btn-dark rounded-pill px-4 w-100">بحث</button></div>
        </form>
    </div>

    <div class="apple-card">
        <div class="table-responsive"><table class="apple-table">
            <thead>
                <tr>
                    <th>رقم السند</th>
                    <th>التاريخ</th>
                    <th>المستفيد</th>
                    <th>الحساب</th>
                    <th>المبلغ</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $r):
                    $stmt_pa = $pdo->prepare("SELECT COUNT(*) as inv_count, SUM(allocated_amount) as total_alloc FROM payment_allocations WHERE financial_transaction_id = ?");
                    $stmt_pa->execute([$r['id']]);
                    $alloc_info = $stmt_pa->fetch();
                ?>
                    <tr>
                        <td class="fw-bold"><?php echo h($r['transaction_number']); ?></td>
                        <td><?php echo h($r['transaction_date']); ?></td>
                        <td>
                            <div class="fw-bold"><?php echo h($r['party_name']); ?></div>
                            <div class="small text-muted">
                                <?php
                                $type_map = ['customer' => 'عميل', 'agent' => 'وكيل', 'supplier' => 'مورد', 'employee' => 'موظف', 'branch' => 'فرع', 'bank' => 'بنك', 'cash' => 'صندوق', 'expense' => 'حساب مصروف/آخر'];
                                echo h($type_map[$r['entity_type']] ?? $r['entity_type']);
                                ?>
                            </div>
                        </td>
                        <td><?php echo h($r['account_name']); ?></td>
                        <td class="fw-bold text-danger"><?php echo number_format($r['amount'], 2); ?> <?php echo h($r['currency_symbol']); ?></td>
                        <td>
                            <?php if ($r['status'] == 'reversed' || $r['is_reversed'] || $r['original_voucher_id']): ?>
                                <span class="apple-badge bg-secondary text-white">🟠 معكوس</span>
                            <?php elseif ($r['status'] == 'draft'): ?>
                                <span class="apple-badge bg-draft">🟡 مسودة</span>
                            <?php elseif ($r['status'] == 'posted'): ?>
                                <span class="apple-badge bg-posted">🟢 مرحل</span>
                            <?php else: ?>
                                <span class="apple-badge bg-cancelled">🔴 ملغي</span>
                            <?php endif; ?>

                            <?php if ($alloc_info['inv_count'] > 0): ?>
                                <div class="mt-1"><span class="badge bg-info-subtle text-info border border-info-subtle small">تسديد فواتير (<?php echo $alloc_info['inv_count']; ?>)</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-light rounded-circle me-1" onclick="viewVoucher(<?php echo $r['id']; ?>)" title="عرض"><i class="fas fa-eye"></i></button>

                            <?php if (in_array($r['status'], ['draft', 'cancelled']) && !$r['is_reversed'] && !$r['original_voucher_id']): ?>
                                <?php if (can_edit_voucher($r)): ?>
                                    <button class="btn btn-sm btn-light rounded-circle me-1" onclick="editVoucher(<?php echo $r['id']; ?>)" title="تعديل"><i class="fas fa-edit"></i></button>
                                <?php endif; ?>
                                <?php if (can_post_voucher($r)): ?>
                                    <button class="btn btn-sm btn-success rounded-circle me-1 text-white" onclick="postVoucher(<?php echo $r['id']; ?>)" title="ترحيل"><i class="fas fa-upload"></i></button>
                                <?php endif; ?>
                            <?php elseif ($r['status'] == 'posted' && can_reverse_voucher($r) && !$r['is_reversed'] && !$r['original_voucher_id']): ?>
                                <button class="btn btn-sm btn-danger rounded-circle me-1 text-white" onclick="cancelVoucher(<?php echo $r['id']; ?>)" title="عكس الترحيل"><i class="fas fa-undo"></i></button>
                            <?php endif; ?>

                            <?php if (in_array($r['status'], ['draft', 'cancelled']) && can_delete_voucher($r) && !$r['is_reversed'] && !$r['original_voucher_id']): ?>
                                <button class="btn btn-sm btn-outline-danger rounded-circle me-1" onclick="deleteVoucher(<?php echo $r['id']; ?>)" title="حذف"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>

                            <a href="print_payment.php?id=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-light rounded-circle"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content apple-modal">
            <form method="POST" id="voucherForm">
                <?php echo csrf_input(); ?>
                <div class="modal-header border-0 p-4">
                    <h5 class="fw-bold" id="modalTitle">سند صرف جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pb-4">
                    <input type="hidden" name="edit_payment_id" id="edit_payment_id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="small fw-bold mb-1">نوع المستفيد</label>
                            <select name="payee_type" id="payee_type" class="form-select apple-input" required tabindex="1">
                                <option value="">اختار نوع المستفيد</option>
                                <option value="supplier">مورد</option>
                                <option value="agent">وكيل</option>
                                <option value="customer">عميل</option>
                                <option value="employee">موظف</option>
                                <option value="bank">بنك</option>
                                <option value="cash">صندوق</option>
                                <option value="expense">مصروف</option>
                                <option value="branch">فرع</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold mb-1">العملة</label>
                            <select name="currency_id" id="currency_id" class="form-select apple-input" required tabindex="2">
                                <option value="">اختار العملة</option>
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo h($c['currency_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold mb-1">التاريخ</label>
                            <input type="date" name="date" id="date" class="form-control apple-input" value="<?php echo date('Y-m-d'); ?>" required tabindex="3">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold mb-1">المستفيد</label>
                            <select name="payee_id" id="payee_id" class="form-select apple-input" required tabindex="4">
                                <option value="">اختر المستفيد...</option>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="small fw-bold mb-1">الصرف من (الصندوق / البنك)</label><select name="account_id" id="account_id" class="form-select apple-input" required tabindex="5">
                                <option value="">اختر...</option><?php foreach ($accounts as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo h($a['account_name']); ?> (<?php echo h($a['account_code']); ?>)</option><?php endforeach; ?>
                            </select></div>
                        <div id="cost-center-wrapper" class="col-md-6" style="display: <?php echo $require_cost_center ? 'block' : 'none'; ?>;">
                            <label class="small fw-bold mb-1">مركز التكلفة</label>
                            <select name="cost_center_id" id="cost_center_id" class="form-select apple-input" tabindex="5.5" <?php echo $require_cost_center ? 'required' : ''; ?>>
                                <option value="">-- بدون مركز تكلفة --</option>
                                <?php foreach ($cost_centers as $cc): ?>
                                    <option value="<?php echo $cc['id']; ?>"><?php echo h($cc['center_code'] . ' - ' . $cc['center_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="invoices_section" class="col-12 d-none">
                            <div class="apple-card p-3 bg-light border-0 shadow-none" style="max-height: 250px; overflow-y: auto;">
                                <h6 class="fw-bold mb-2 small">الفواتير غير المسددة</h6>
                                <div id="currency_alert" class="alert alert-warning extra-small p-2 d-none">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    تنبيه: يوجد فواتير غير مسددة بعملات أخرى.
                                </div>
                                <div id="invoices_list" class="table-responsive"></div>
                            </div>
                        </div>
                        <div class="col-md-4"><label class="small fw-bold mb-1">المبلغ الإجمالي</label><input type="number" step="0.01" name="amount" id="amount" class="form-control apple-input fw-bold" required tabindex="6"></div>
                        <div class="col-md-8"><label class="small fw-bold mb-1">المبلغ بالحروف</label>
                            <div id="amount_text" class="p-2 bg-light rounded-3 extra-small text-muted" style="min-height: 38px;">---</div>
                        </div>
                        <div class="col-12"><label class="small fw-bold mb-1">البيان</label><textarea name="description" id="description" class="form-control apple-input" rows="2" tabindex="7" placeholder="سيتم تعبئته تلقائياً..."></textarea></div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0 d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-muted text-decoration-none order-2 order-md-1" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_payment" class="btn-apple-primary px-5 w-100 w-md-auto order-1 order-md-2 mb-2 mb-md-0" tabindex="8">حفظ السند</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content apple-modal p-4" id="viewContent"></div>
    </div>
</div>

<script src="assets/js/tafqeet.js"></script>
<script>
    function resetForm() {
        $('#voucherForm')[0].reset();
        $('#edit_payment_id').val('');
        $('#modalTitle').text('سند صرف جديد');
        $('#invoices_section').addClass('d-none');
        $('#invoices_list').empty();
        $('#amount_text').text('---');
        
        // Reset cost center field visibility
        var costCenterWrapper = $('#cost-center-wrapper');
        var costCenterSelect = $('#cost_center_id');
        costCenterSelect.val('');
        if (REQUIRE_COST_CENTER) {
            costCenterWrapper.show();
            costCenterSelect.prop('required', true);
        } else {
            costCenterWrapper.hide();
            costCenterSelect.prop('required', false);
        }
    }

    function loadEntities(type, selectedId = null) {
        return new Promise((resolve, reject) => {
            $('#payee_id').html('<option value="">جاري التحميل...</option>');
            $.get('ajax/get_entities.php', {
                type: type,
                _: new Date().getTime()
            }, function(data) {
                let options = '<option value="">اختر المستفيد...</option>';
                let dataArray = Array.isArray(data) ? data : (data.entities ? data.entities : []);
                if (dataArray && dataArray.length > 0) {
                    dataArray.forEach(item => {
                        let selected = (selectedId && item.account_id == selectedId) ? 'selected' : '';
                        options += `<option value="${item.account_id}" ${selected}>${item.name}</option>`;
                    });
                } else {
                    options = '<option value="">لا توجد بيانات</option>';
                }
                $('#payee_id').html(options);

                // إظهار قسم الفواتير لأنواع معينة (موردين، عملاء، وكلاء، فروع، موظفين)
                if (['supplier', 'customer', 'agent', 'branch', 'employee'].includes(type)) {
                    $('#invoices_section').removeClass('d-none');
                } else {
                    $('#invoices_section').addClass('d-none');
                    $('#invoices_list').empty();
                }

                resolve();
            }).fail(function() {
                $('#payee_id').html('<option value="">خطأ في التحميل</option>');
                reject();
            });
        });
    }

    $('#payee_type').on('change', function() {
        loadEntities($(this).val());
    });

    $('#payee_id').on('change', async function() {
        let type = $('#payee_type').val();
        let accountId = $('#payee_id').val();
        let currencyId = $('#currency_id').val();

        if (accountId) {
            await filterCurrenciesByAccount(accountId);
            // After filter, get the updated currencyId
            currencyId = $('#currency_id').val();
        } else {
            $('#currency_id option').show();
        }

        if (['supplier', 'agent'].includes(type) && accountId && currencyId) {
            loadUnpaidInvoices();
        }
    });

    let currentAccountCurrencies = [];

    function filterCurrenciesByAccount(accountId) {
        return new Promise((resolve, reject) => {
            $.get('ajax/get_account_currencies.php', {
                account_id: accountId
            }, function(res) {
                const $currencySelect = $('#currency_id');
                const selectedVal = $currencySelect.val();
                currentAccountCurrencies = res.currencies || [];

                $currencySelect.find('option').each(function() {
                    const optVal = $(this).val();
                    if (optVal === "") return;

                    const found = currentAccountCurrencies.find(c => c.id == optVal);
                    if (found) {
                        $(this).show();
                    } else {
                        $(this).hide();
                        if (selectedVal == optVal) $currencySelect.val('');
                    }
                });

                if (currentAccountCurrencies.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'تنبيه',
                        text: 'هذا الحساب لا يمتلك أي عملات مفعلة. يرجى تفعيل العملات له أولاً من شاشة "إدارة العملات والحدود المالية".'
                    });
                }
                resolve();
            }).fail(reject);
        });
    }

    $('#currency_id').on('change', function() {
        let type = $('#payee_type').val();
        let payeeId = $('#payee_id').val();
        let currencyId = $(this).val();

        if (['supplier', 'agent'].includes(type) && payeeId && currencyId) {
            loadUnpaidInvoices();
        }
    });

    $('#voucherForm').on('submit', function(e) {
        const currencyId = $('#currency_id').val();
        if (currencyId && currentAccountCurrencies.length > 0) {
            const found = currentAccountCurrencies.find(c => c.id == currencyId);
            if (!found) {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: 'العملة المختارة غير مفعلة لهذا الحساب.'
                });
                e.preventDefault();
                return false;
            }
        }
        return true;
    });

    function loadUnpaidInvoices() {
        let payer_id = $('#payee_id').val();
        let currency_id = $('#currency_id').val();
        let type = $('#payee_type').val();
        let voucher_id = $('#edit_payment_id').val() || 0;

        $.get('ajax/get_unpaid_invoices.php', {
            customer_id: payer_id,
            currency_id: currency_id,
            type_payer: type,
            type: (type === 'supplier') ? 'purchase' : 'sales',
            voucher_id: voucher_id
        }, function(res) {
            let invoices = res.invoices;
            let others = res.other_currencies;

            if (others && others.length > 0) {
                let alertHtml = '<i class="fas fa-exclamation-triangle me-1"></i> تنبيه: يوجد فواتير غير مسددة بعملات أخرى: ';
                others.forEach(function(o, i) {
                    alertHtml += `<strong>${o.currency_name} (${o.currency_symbol})</strong>`;
                    if (i < others.length - 1) alertHtml += '، ';
                });
                $('#currency_alert').html(alertHtml).removeClass('d-none');
            } else {
                $('#currency_alert').addClass('d-none');
            }

            let html = '<table class="table table-sm small"><thead><tr><th><input type="checkbox" id="check_all"></th><th>رقم الفاتورة</th><th>التاريخ</th><th>المبلغ الإجمالي</th><th>الحالي</th><th>المخصص</th><th>المتبقي بعد</th></tr></thead><tbody>';

            if (invoices.length === 0) {
                html += '<tr><td colspan="7" class="text-center text-muted p-3">لا توجد فواتير غير مسددة بهذه العملة</td></tr>';
            } else {
                invoices.forEach(inv => {
                    let isChecked = inv.current_allocated > 0 ? 'checked' : '';
                    let isDisabled = inv.current_allocated > 0 ? '' : 'disabled';
                    let allocVal = inv.current_allocated > 0 ? inv.current_allocated : '';
                    let futureRem = (parseFloat(inv.remaining) - parseFloat(inv.current_allocated || 0)).toFixed(2);

                    html += `<tr>
                        <td><input type="checkbox" class="invoice-checkbox" data-id="${inv.id}" data-remaining="${inv.remaining}" ${isChecked}></td>
                        <td>${inv.invoice_number}</td>
                        <td>${inv.invoice_date || ''}</td>
                        <td>${inv.net_amount} ${inv.currency_symbol || ''}</td>
                        <td class="text-danger current-remaining">${inv.remaining}</td>
                        <td><input type="number" step="0.01" class="form-control form-control-sm alloc-input" name="allocations[${inv.id}]" value="${allocVal}" max="${inv.remaining}" ${isDisabled}></td>
                        <td class="text-primary future-remaining">${futureRem}</td>
                    </tr>`;
                });
            }
            html += '</tbody></table></div>';
            $('#invoices_list').html(html);

            $('.invoice-checkbox').on('change', function() {
                let row = $(this).closest('tr');
                let input = row.find('.alloc-input');
                if ($(this).is(':checked')) {
                    let voucherAmount = parseFloat($('#amount').val() || 0);
                    let currentAllocated = 0;
                    $('.alloc-input:not(:disabled)').each(function() {
                        currentAllocated += parseFloat($(this).val() || 0);
                    });

                    let remainingInVoucher = Math.max(0, voucherAmount - currentAllocated);
                    let invoiceRemaining = parseFloat($(this).data('remaining'));
                    let defaultValue = Math.min(invoiceRemaining, remainingInVoucher);
                    if (defaultValue <= 0) defaultValue = invoiceRemaining;

                    input.prop('disabled', false).val(defaultValue.toFixed(2));
                } else {
                    input.prop('disabled', true).val('');
                }
                calculateTotal();
            });

            $('.alloc-input').on('input', function() {
                let row = $(this).closest('tr');
                let alloc = parseFloat($(this).val() || 0);
                let current = parseFloat(row.find('.current-remaining').text());
                row.find('.future-remaining').text((current - alloc).toFixed(2));
                calculateTotal('allocation');
            });

            $('#check_all').on('change', function() {
                let checked = $(this).is(':checked');
                $('.invoice-checkbox').prop('checked', checked).trigger('change');
            });
        });
    }

    function calculateTotal(source = 'input') {
        let totalAllocated = 0;
        $('.alloc-input:not(:disabled)').each(function() {
            totalAllocated += parseFloat($(this).val() || 0);
        });

        if (source === 'allocation') {
            $('#amount').val(totalAllocated.toFixed(2));
        }

        updateTafqeet();
        updateDescription();
    }

    function updateDescription() {
        let name = $('#payee_id option:selected').text();

        if (name && name !== 'اختر المستفيد...') {
            let selectedInvoices = [];
            $('.invoice-checkbox:checked').each(function() {
                let invNum = $(this).closest('tr').find('td:eq(1)').text();
                selectedInvoices.push(invNum);
            });

            let desc = selectedInvoices.length > 0 ?
                `سداد فواتير (${selectedInvoices.join(', ')}) - ${name}` :
                `دفعة على الحساب - ${name}`;

            $('#description').val(desc);
        }
    }

    $('#payee_id, #amount, #currency_id').on('change input', updateDescription);

    function updateTafqeet() {
        let amount = $('#amount').val();
        if (amount) $('#amount_text').text(tafqeet(amount));
    }
    $('#amount').on('input', function() {
        updateTafqeet();

        let totalVoucherAmount = parseFloat($(this).val() || 0);
        let remainingToAllocate = totalVoucherAmount;

        $('.invoice-checkbox:checked').each(function() {
            let row = $(this).closest('tr');
            let input = row.find('.alloc-input');
            let invoiceRemaining = parseFloat($(this).data('remaining'));

            let alloc = Math.min(invoiceRemaining, remainingToAllocate);
            input.val(alloc > 0 ? alloc.toFixed(2) : '');
            remainingToAllocate -= alloc;

            let current = parseFloat(row.find('.current-remaining').text());
            row.find('.future-remaining').text((current - (alloc > 0 ? alloc : 0)).toFixed(2));
        });

        calculateTotal('amount_input');
    });

    function postVoucher(id) {
        if (confirm('هل أنت متأكد من ترحيل هذا السند؟')) $.post('ajax/post_voucher.php', {
            id: id,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, res => {
            if (res.success) location.reload();
            else alert(res.message);
        }, 'json');
    }

    function cancelVoucher(id) {
        let reason = prompt('سبب الإلغاء:');
        if (reason) $.post('ajax/reverse_voucher.php', {
            id: id,
            reason: reason,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, res => {
            if (res.success) location.reload();
            else alert(res.message);
        }, 'json');
    }

    function deleteVoucher(id) {
        if (confirm('هل أنت متأكد من حذف هذا السند نهائياً؟ لا يمكن التراجع عن هذه العملية.')) {
            $.post('ajax/delete_voucher.php', {
                id: id,
                csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
            }, function(res) {
                if (res.success) location.reload();
                else alert(res.message);
            }, 'json');
        }
    }

    function viewVoucher(id) {
        $.get('ajax/get_voucher_details.php', {
            id: id
        }, v => {
            let html = `
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">${v.transaction_number}</h4>
                        <p class="text-muted small">${v.transaction_date}</p>
                    </div>
                    <span class="apple-badge bg-${v.status}">${v.status === 'draft' ? '🟡 مسودة' : (v.status === 'posted' ? '🟢 مرحل' : '🔴 ملغي')}</span>
                </div>
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <label class="text-muted small d-block mb-1">المستفيد</label>
                        <div class="fw-bold">${v.party_name} (${v.entity_type})</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small d-block mb-1">الحساب المصروف منه</label>
                        <div class="fw-bold">${v.account_name}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small d-block mb-1">المبلغ</label>
                        <div class="fw-bold text-danger fs-4">${parseFloat(v.amount).toLocaleString()} ${v.currency_symbol}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small d-block mb-1">البيان</label>
                        <div class="fw-bold">${v.description || '---'}</div>
                    </div>
                </div>

                ${v.allocations.length ? `
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-file-invoice me-2"></i>توزيع السند على الفواتير</h6>
                        <table class="table table-sm small">
                            <thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>المبلغ المخصص</th></tr></thead>
                            <tbody>
                                ${v.allocations.map(a => `<tr><td>${a.invoice_number}</td><td>${a.invoice_date}</td><td>${parseFloat(a.allocated_amount).toLocaleString()}</td></tr>`).join('')}
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="2">إجمالي الموزع</td>
                                    <td>${v.allocations.reduce((acc, a) => acc + parseFloat(a.allocated_amount), 0).toLocaleString()}</td>
                                </tr>
                                ${parseFloat(v.amount) - v.allocations.reduce((acc, a) => acc + parseFloat(a.allocated_amount), 0) > 0.01 ? `
                                    <tr class="table-info">
                                        <td colspan="2">المتبقي (رصيد في الحساب)</td>
                                        <td>${(parseFloat(v.amount) - v.allocations.reduce((acc, a) => acc + parseFloat(a.allocated_amount), 0)).toLocaleString()}</td>
                                    </tr>
                                ` : ''}
                            </tfoot>
                        </table></div>
                    </div>
                ` : `
                    <div class="alert alert-info small mb-4">
                        <i class="fas fa-info-circle me-2"></i>هذا السند غير مخصص لفواتير محددة (دفعة على الحساب).
                    </div>
                `}

                <div class="mb-4">
                    <h6 class="fw-bold mb-3"><i class="fas fa-history me-2"></i>سجل المعاملة (Audit Log)</h6>
                    <div class="timeline-small">
                        ${v.audit_logs.map(log => `
                            <div class="log-item mb-2 p-2 bg-light rounded-3 border-start border-danger border-3">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold small text-danger">${log.action_type.toUpperCase()}</span>
                                    <span class="extra-small text-muted">${log.created_at}</span>
                                </div>
                                <div class="small">${log.user_name} - IP: ${log.user_ip}</div>
                                ${log.reason ? `<div class="extra-small text-danger">السبب: ${log.reason}</div>` : ''}
                            </div>
                        `).join('')}
                    </div>
                </div>

                <div class="bg-light p-3 rounded-4 small mb-4">
                    <div class="row">
                        <div class="col-md-6 mb-2"><strong>أنشئ بواسطة:</strong> ${v.creator_name} في ${v.created_at}</div>
                        ${v.posted_at ? `<div class="col-md-6 mb-2"><strong>رُحل بواسطة:</strong> ${v.poster_name} في ${v.posted_at}</div>` : ''}
                        ${v.cancelled_at ? `<div class="col-md-6"><strong>أُلغي بواسطة:</strong> ${v.canceller_name} في ${v.cancelled_at}<br><strong>السبب:</strong> ${v.cancellation_reason}</div>` : ''}
                    </div>
                </div>
                <div class="text-center">
                    <button class="btn btn-dark rounded-pill px-5" data-bs-dismiss="modal">إغلاق</button>
                </div>
            `;
            $('#viewContent').html(html);
            $('#viewModal').modal('show');
        });
    }

    function editVoucher(id) {
        $.get('ajax/get_voucher_details.php', {
            id: id
        }, v => {
            resetForm();
            $('#edit_payment_id').val(v.id);
            $('#modalTitle').text('تعديل سند صرف: ' + v.transaction_number);
            $('#date').val(v.transaction_date);
            $('#payee_type').val(v.entity_type);
            
            // Handle cost center field visibility
            var costCenterWrapper = $('#cost-center-wrapper');
            var costCenterSelect = $('#cost_center_id');
            
            // If require cost center is on, or the voucher already has a cost center id, show the field
            if (REQUIRE_COST_CENTER || (v.cost_center_id && v.cost_center_id !== null && v.cost_center_id !== '')) {
                costCenterWrapper.show();
                costCenterSelect.prop('required', REQUIRE_COST_CENTER);
            } else {
                costCenterWrapper.hide();
                costCenterSelect.prop('required', false);
            }

            // تحميل المستفيدين ثم ضبط القيمة المختارة
            loadEntities(v.entity_type, v.party_account_id).then(() => {
                $('#currency_id').val(v.currency_id);
                $('#account_id').val(v.cash_bank_account_id);
                $('#amount').val(v.amount);
                $('#description').val(v.description);
                
                // Set cost center value if present
                if (v.cost_center_id) {
                    costCenterSelect.val(v.cost_center_id);
                }
                
                updateTafqeet();

                // تحميل الفواتير المخصصة وتحديدها
                if (['customer', 'agent', 'supplier'].includes(v.entity_type)) {
                    loadUnpaidInvoices();
                }

                $('#paymentModal').modal('show');
            });
        });
    }
    <?php if (!empty($_GET['edit_id'])): ?>
        $(function() {
            editVoucher(<?php echo (int)$_GET['edit_id']; ?>);
        });
    <?php endif; ?>
    const REQUIRE_COST_CENTER = <?php echo $require_cost_center ? 'true' : 'false'; ?>;
    const CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
    
    // ربط الدوال المحلية بالدوال العامة في receipts-actions.js
    window.editVoucherLocal = editVoucher;
</script>
<script src="assets/js/receipts-actions.js?v=<?php echo time(); ?>"></script>
<?php require_once 'footer.php'; ?>
