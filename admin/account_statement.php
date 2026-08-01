<?php
$page_title = "كشف حساب";
ob_end_clean();
require_once 'header.php';

$account_id = $_GET['account_id'] ?? '';
$currency_id = $_GET['currency_id'] ?? '';
$account_type = $_GET['account_type'] ?? 'all';
$document_type_filter = $_GET['document_type'] ?? 'all';
$use_date_range = isset($_GET['use_date_range']) && $_GET['use_date_range'] == '1';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$account_info = null;
$opening_balance = 0;
$transactions = [];
$currency_info = null;
$accounts = [];
$currencies = [];
$db_unified_net_balance = 0;
$total_net_words_base = '';
$selected_balance = 0;
$balance_words = '';
$balance_by_currency = [];
$transaction_summary_by_currency = [];

function has_permission_v3($permission_code)
{
    global $pdo, $user_role, $user_role_id;
    if ($user_role === 'developer') return true;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions_unified rp JOIN unified_permissions p ON rp.permission_id = p.id WHERE rp.role_id = ? AND p.permission_code = ?");
    $stmt->execute([$user_role_id, $permission_code]);
    return $stmt->fetchColumn() > 0;
}

$can_delete_voucher = has_permission_v3('voucher_delete');

// جلب الحسابات للفلتر
$stmt_accounts = $pdo->query("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts ORDER BY account_code");
$all_accounts = $stmt_accounts->fetchAll();

$accounts = [];
// Instead of build_flat_tree, let's just format the accounts with display_name
foreach ($all_accounts as $acc) {
    $acc['display_name'] = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
    $accounts[] = $acc;
}

// جلب العملات
$stmt_currencies = $pdo->query("SELECT id, currency_name, currency_code, currency_symbol FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, currency_name ASC");
$currencies = $stmt_currencies->fetchAll();

// جلب العملة الافتراضية للنظام (اليمني)
$stmt_default = $pdo->query("SELECT * FROM currencies WHERE is_default = 1 LIMIT 1");
$default_currency = $stmt_default->fetch();

if ($account_id && $currency_id) {
    // جلب معلومات الحساب من الجدول الموحد
    $stmt_acc = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = ?");
    $stmt_acc->execute([$account_id]);
    $account_info = $stmt_acc->fetch();

    if ($currency_id !== 'all') {
        $stmt_curr = $pdo->prepare("SELECT * FROM currencies WHERE id = ?");
        $stmt_curr->execute([$currency_id]);
        $currency_info = $stmt_curr->fetch();
    }

    // 1. جلب الرصيد الافتتاحي من جدول الأرصدة الموحد
    $base_opening_balance = 0;
    if ($currency_id !== 'all') {
        $stmt_base_opening = $pdo->prepare("
            SELECT opening_balance
            FROM account_balances_unified
            WHERE account_id = ?
              AND (currency_id = ? OR currency_code = (SELECT currency_code FROM currencies WHERE id = ?))
        ");
        $stmt_base_opening->execute([$account_id, $currency_id, $currency_id]);
        $base_opening_balance = (float)($stmt_base_opening->fetchColumn() ?: 0);
    }

    // 2. حساب الحركات السابقة لتاريخ البداية (إذا تم تحديد تاريخ)
    $prior_transactions_balance = 0;
    if ($use_date_range) {
        $opening_sql = "
            SELECT
                SUM(jl.debit) as total_debit,
                SUM(jl.credit) as total_credit
            FROM journal_lines jl
            JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
            WHERE jl.account_id = ?
              AND ft.transaction_date < ?
              AND ft.status IN ('posted', 'reversed')
        ";

        $opening_params = [$account_id, $date_from];
        if ($currency_id !== 'all') {
            $opening_sql .= " AND (jl.currency_id = ?)";
            $opening_params[] = $currency_id;
        }

        $stmt_opening = $pdo->prepare($opening_sql);
        $stmt_opening->execute($opening_params);
        $opening = $stmt_opening->fetch();
        $prior_transactions_balance = (float)($opening['total_debit'] ?: 0) - (float)($opening['total_credit'] ?: 0);
    }

    $opening_balance = $base_opening_balance + $prior_transactions_balance;

    // 3. جلب الحركات خلال الفترة من النظام الموحد (المرحلة فقط)
    $trans_sql = "
        SELECT
            ft.transaction_date,
            COALESCE(MAX(i.invoice_number), ft.reference_number, cet.transaction_number, ft.transaction_number) as reference_number,
            ft.description as description,
            SUM(jl.debit) as debit_amount,
            SUM(jl.credit) as credit_amount,
            CASE 
                WHEN MAX(i.invoice_category) = 'purchase' THEN 'فاتورة شراء'
                WHEN MAX(i.invoice_category) = 'sales' THEN 'فاتورة بيع'
                ELSE ft.transaction_type 
            END as source_type,
            ft.transaction_type as original_transaction_type,
            MAX(i.invoice_category) as invoice_category,
            COALESCE(MAX(i.source_type), ft.reference_type, '') as service_name,
            MAX(i.payment_status) as payment_status,
            ft.status as transaction_status,
            ft.id as source_id,
            cur.currency_code as currency_code,
            cur.currency_name as currency_name,
            cur.currency_symbol as currency_symbol,
            MAX(i.id) as invoice_id,
            ft.created_at
        FROM financial_transactions ft
        JOIN journal_lines jl ON ft.id = jl.financial_transaction_id AND jl.account_id = :acc_id
        LEFT JOIN currencies cur ON jl.currency_id = cur.id
        LEFT JOIN invoices i ON (ft.transaction_number = i.invoice_number OR ft.reference_number = i.invoice_number OR (ft.reference_id = i.id AND ft.reference_type = 'invoice'))
        LEFT JOIN currency_exchange_transactions cet ON ft.transaction_number = cet.transaction_number
        WHERE ft.status IN ('posted', 'reversed')
        GROUP BY ft.id, ft.transaction_date, ft.transaction_type, ft.transaction_number, ft.reference_number, ft.description, ft.reference_type, ft.status, ft.created_at, cur.currency_code, cur.currency_name, cur.currency_symbol, cet.transaction_number, cet.notes
    ";

    $trans_params = [':acc_id' => $account_id];

    if ($document_type_filter != 'all') {
        if ($document_type_filter == 'invoice') {
            $trans_sql .= " AND (i.id IS NOT NULL OR ft.transaction_type = 'invoice') ";
        } else {
            $trans_sql .= " AND ft.transaction_type = :doc_type ";
            $trans_params[':doc_type'] = $document_type_filter;
        }
    }

    if ($use_date_range) {
        $trans_sql .= " AND ft.transaction_date BETWEEN :d_from AND :d_to ";
        $trans_params[':d_from'] = $date_from;
        $trans_params[':d_to'] = $date_to;
    }

    if ($currency_id !== 'all') {
        $trans_sql .= " AND (SELECT id FROM currencies c3 WHERE c3.currency_code = cur.currency_code LIMIT 1) = :curr_id ";
        $trans_params[':curr_id'] = $currency_id;
    }

    $trans_sql .= " ORDER BY ft.transaction_date ASC, ft.created_at ASC";

    $stmt_trans = $pdo->prepare($trans_sql);
    $stmt_trans->execute($trans_params);
    $transactions = $stmt_trans->fetchAll();

    $summary_sql = "
        SELECT
            COALESCE(c.currency_code, CONCAT('CUR', jl.currency_id)) AS currency_code,
            COALESCE(c.currency_name, 'غير محددة') AS currency_name,
            COALESCE(c.currency_symbol, '') AS currency_symbol,
            SUM(jl.debit) AS total_debit,
            SUM(jl.credit) AS total_credit
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        LEFT JOIN currencies c ON jl.currency_id = c.id
        WHERE jl.account_id = ?
          AND ft.status IN ('posted', 'reversed')
    ";
    $summary_params = [$account_id];
    if ($document_type_filter != 'all') {
        $summary_sql .= " AND ft.transaction_type = ? ";
        $summary_params[] = $document_type_filter;
    }
    if ($use_date_range) {
        $summary_sql .= " AND ft.transaction_date BETWEEN ? AND ? ";
        $summary_params[] = $date_from;
        $summary_params[] = $date_to;
    }
    $summary_sql .= " GROUP BY c.currency_code, c.currency_name, c.currency_symbol ORDER BY c.currency_code";
    $stmt_summary = $pdo->prepare($summary_sql);
    $stmt_summary->execute($summary_params);
    $transaction_summary_by_currency = $stmt_summary->fetchAll();

    if (empty($transaction_summary_by_currency) && !empty($balance_by_currency)) {
        foreach ($balance_by_currency as $bc) {
            $transaction_summary_by_currency[] = [
                'currency_code' => $bc['code'] ?? '',
                'currency_name' => $bc['name'],
                'currency_symbol' => $bc['symbol'],
                'total_debit' => 0,
                'total_credit' => 0,
            ];
        }
    }

    // جلب عمليات التصريف المتعلقة بالحساب
    $exchange_transactions = [];
    if ($account_id) {
        $exchange_sql = "
            SELECT
                cet.transaction_date,
                cet.transaction_number,
                cet.from_amount,
                cet.to_amount,
                cet.exchange_rate,
                cet.notes,
                fc.currency_code as from_currency_code,
                fc.currency_symbol as from_currency_symbol,
                tc.currency_code as to_currency_code,
                tc.currency_symbol as to_currency_symbol,
                fa.account_name_ar as from_account_name,
                ta.account_name_ar as to_account_name,
                u.full_name as created_by_name,
                b.branch_name
            FROM currency_exchange_transactions cet
            JOIN currencies fc ON cet.from_currency_id = fc.id
            JOIN currencies tc ON cet.to_currency_id = tc.id
            JOIN unified_accounts fa ON cet.from_account_id = fa.id
            JOIN unified_accounts ta ON cet.to_account_id = ta.id
            LEFT JOIN users u ON cet.created_by = u.id
            LEFT JOIN branches b ON cet.branch_id = b.id
            WHERE cet.from_account_id = ? OR cet.to_account_id = ?
        ";
        $exchange_params = [$account_id, $account_id];
        if ($use_date_range) {
            $exchange_sql .= " AND cet.transaction_date BETWEEN ? AND ?";
            $exchange_params[] = $date_from;
            $exchange_params[] = $date_to;
        }
        $exchange_sql .= " ORDER BY cet.transaction_date DESC";

        $stmt_exchange = $pdo->prepare($exchange_sql);
        $stmt_exchange->execute($exchange_params);
        $exchange_transactions = $stmt_exchange->fetchAll();
    }

    require_once '../includes/tafqeet.php';

    // حساب إجماليات الأرصدة حسب العملة مع الأرصدة الافتتاحية
    $balance_by_currency = [];
    $total_net_balance_base = 0;
    $default_currency = $pdo->query("SELECT currency_code, currency_name, currency_symbol FROM currencies WHERE is_default = 1")->fetch() ?: ['currency_name' => 'ريال يمني', 'currency_symbol' => 'ر.ي', 'currency_code' => 'YER'];

    // جلب كافة أرصدة النظام لهذا الحساب من الجدول الموحد
    $stmt_all_openings = $pdo->prepare("
        SELECT ab.opening_balance, ab.current_balance, c.id as currency_id, c.currency_code, c.currency_name, c.currency_symbol, c.exchange_rate
        FROM account_balances_unified ab
        JOIN currencies c ON ab.currency_id = c.id
        WHERE ab.account_id = ?
    ");
    $stmt_all_openings->execute([$account_id]);
    $all_openings = $stmt_all_openings->fetchAll();

    foreach ($all_openings as $ao) {
        $key = $ao['currency_symbol'] . '|' . $ao['currency_name'];

        // حساب الرصيد الحالي الحقيقي بناءً على الحركات الفعلية لضمان الدقة
        $stmt_actual = $pdo->prepare("SELECT SUM(debit - credit) FROM journal_lines jl JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id WHERE jl.account_id = ? AND jl.currency_id = ? AND ft.status IN ('posted', 'reversed')");
        $stmt_actual->execute([$account_id, $ao['currency_id']]);
        $actual_net_movement = (float)$stmt_actual->fetchColumn();

        $balance_by_currency[$key] = [
            'code' => $ao['currency_code'],
            'symbol' => $ao['currency_symbol'],
            'name' => $ao['currency_name'],
            'debit' => 0,
            'credit' => 0,
            'opening_balance' => (float)$ao['opening_balance'],
            'current_balance' => $actual_net_movement, // استخدام الرصيد الفعلي المحسوب
            'balance' => ($currency_id === 'all') ? $actual_net_movement : (float)$ao['opening_balance'],
            'rate' => (float)($ao['exchange_rate'] ?: 1),
            'use_system_balance' => ($currency_id === 'all'),
            'source' => ($currency_id === 'all') ? 'رصيد الحساب الفعلي' : 'رصيد الافتتاح'
        ];
    }
    
    // If no account_balances_unified entries, build balance_by_currency from journal lines
    if (empty($balance_by_currency)) {
        $stmt_jl_currencies = $pdo->prepare("
            SELECT DISTINCT jl.currency_id, c.currency_code, c.currency_name, c.currency_symbol, c.exchange_rate
            FROM journal_lines jl
            LEFT JOIN currencies c ON jl.currency_id = c.id
            WHERE jl.account_id = ?
        ");
        $stmt_jl_currencies->execute([$account_id]);
        $jl_currencies = $stmt_jl_currencies->fetchAll();
        
        foreach ($jl_currencies as $jc) {
            $currency_id_jl = $jc['currency_id'];
            $symbol = $jc['currency_symbol'] ?? 'CUR' . $currency_id_jl;
            $name = $jc['currency_name'] ?? 'Currency ' . $currency_id_jl;
            $key = $symbol . '|' . $name;
            
            $stmt_actual = $pdo->prepare("SELECT SUM(debit - credit) FROM journal_lines jl JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id WHERE jl.account_id = ? AND jl.currency_id = ? AND ft.status IN ('posted', 'reversed')");
            $stmt_actual->execute([$account_id, $currency_id_jl]);
            $actual_net_movement = (float)$stmt_actual->fetchColumn();
            
            $balance_by_currency[$key] = [
                'code' => $jc['currency_code'] ?? 'CUR' . $currency_id_jl,
                'symbol' => $symbol,
                'name' => $name,
                'debit' => 0,
                'credit' => 0,
                'opening_balance' => 0,
                'current_balance' => $actual_net_movement,
                'balance' => $actual_net_movement,
                'rate' => (float)($jc['exchange_rate'] ?? 1),
                'use_system_balance' => true,
                'source' => 'رصيد الحساب الفعلي'
            ];
        }
    }

    foreach ($transactions as $t) {
        $symbol = $t['currency_symbol'] ?: 'غير محددة';
        $name = $t['currency_name'] ?: 'غير محددة';
        $key = $symbol . '|' . $name;
        if (!isset($balance_by_currency[$key])) {
            // محاولة جلب سعر الصرف إذا لم يكن موجوداً في الأرصدة الافتتاحية
            $stmt_rate = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE currency_code = ?");
            $stmt_rate->execute([$t['currency_symbol']]);
            $rate = (float)($stmt_rate->fetchColumn() ?: 1);

            $balance_by_currency[$key] = [
                'symbol' => $symbol,
                'name' => $name,
                'debit' => 0,
                'credit' => 0,
                'balance' => 0,
                'rate' => $rate,
                'use_system_balance' => false,
                'source' => 'حركة الحسابات'
            ];
        }
        $balance_by_currency[$key]['debit'] += (float)$t['debit_amount'];
        $balance_by_currency[$key]['credit'] += (float)$t['credit_amount'];
        if (empty($balance_by_currency[$key]['use_system_balance'])) {
            $balance_by_currency[$key]['balance'] += ((float)$t['debit_amount'] - (float)$t['credit_amount']);
        }
    }

    // حساب صافي الرصيد الموحد مباشرة من حركات الحسابات الفعلية لضمان الدقة
    $stmt_db_net = $pdo->prepare("
        SELECT SUM((jl.debit - jl.credit) * COALESCE(c.exchange_rate, 1)) as total_net
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        LEFT JOIN currencies c ON jl.currency_id = c.id
        WHERE jl.account_id = ? AND ft.status IN ('posted', 'reversed')
    ");
    $stmt_db_net->execute([$account_id]);
    $db_unified_net_balance = (float)($stmt_db_net->fetchColumn() ?: 0);
    
    // حساب صافي الرصيد الموحد (بناءً على الحركات المختارة)
    foreach ($balance_by_currency as $bc) {
        $total_net_balance_base += ($bc['balance'] * $bc['rate']);
    }

    $total_net_words_base = tafqeet_php(abs($db_unified_net_balance), $default_currency['currency_name'] ?? 'ريال يمني');

    // Determine balance for any currency selection
    if ($currency_id !== 'all') {
        $selected_balance = $opening_balance;
        foreach ($transactions as $t) {
            $selected_balance += ((float)$t['debit_amount'] - (float)$t['credit_amount']);
        }
        $display_balance = $selected_balance;
        $display_currency = $currency_info['currency_name'] ?? 'ريال';
    } else {
        // For "all" currencies, use the unified base balance
        $display_balance = $db_unified_net_balance;
        $display_currency = $default_currency['currency_name'];
    }

    // جلب طبيعة الحساب (مدين أم دائن) لتحديد "له" أو "عليه" بشكل صحيح
    $account_normal_balance = $account_info['normal_balance'] ?? 'debit';

    if ($account_normal_balance == 'debit') {
        // للحسابات المدينة (أصول/مصاريف): الموجب "عليه" (مدين) والسالب "له" (دائن)
        $balance_type = $display_balance >= 0 ? 'عليه (مدين لنا)' : 'له (دائن لنا)';
    } else {
        // للحسابات الدائنة (خصوم/إيرادات): الموجب "عليه" (مدين) والسالب "له" (دائن)
        // في المحاسبة العربية: الرصيد الدائن للمورد يسمى "له"
        $balance_type = $display_balance > 0 ? 'عليه (مدين لنا)' : 'له (دائن لنا)';
    }

    $balance_words = tafqeet_php(abs($display_balance), $display_currency);
}

// جلب الحسابات والعملات للفلترة
require_once '../includes/accounting_functions.php';

// جلب معرفات الحسابات للكيانات النشطة فقط
$active_account_ids = [];

// العملاء النشطون
$stmt = $pdo->query("SELECT account_id FROM customers WHERE status = 'active' AND deleted_at IS NULL");
$active_account_ids = array_merge($active_account_ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

// الوكلاء النشطون
$stmt = $pdo->query("SELECT account_id FROM agents WHERE status = 'active' AND deleted_at IS NULL");
$active_account_ids = array_merge($active_account_ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

// الموردون النشطون
$stmt = $pdo->query("SELECT account_id FROM suppliers WHERE deleted_at IS NULL");
$active_account_ids = array_merge($active_account_ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

// الموظفون النشطون
$stmt = $pdo->query("SELECT account_id FROM employees WHERE status = 'active' AND deleted_at IS NULL");
$active_account_ids = array_merge($active_account_ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

// الفروع النشطة
$stmt = $pdo->query("SELECT account_id FROM branches WHERE status = 'active' AND deleted_at IS NULL");
$active_account_ids = array_merge($active_account_ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

$active_account_ids = array_unique(array_filter($active_account_ids));

// جلب جميع الحسابات وتصفيتها
$all_accounts_raw = $pdo->query("SELECT id, account_code, account_name_ar, parent_id, account_type FROM unified_accounts ORDER BY account_code")->fetchAll();

$categorized_accounts = [
    'all' => [],
    '101' => [], // الصناديق
    '102' => [], // البنوك
    'customer' => [], // العملاء
    'supplier' => [], // الموردين
    'agent' => [], // الوكلاء
    'branch' => [], // الفروع
    'employee' => [], // الموظفين
    'expense' => [] // المصاريف
];

// جلب معرف الحساب الرئيسي للعملاء (account_code = '11201')
$stmt_customer_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11201' LIMIT 1");
$stmt_customer_parent->execute();
$customer_parent_id = $stmt_customer_parent->fetchColumn();

// جلب معرف الحساب الرئيسي للوكلاء (account_code = '11203')
$stmt_agent_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11203' LIMIT 1");
$stmt_agent_parent->execute();
$agent_parent_id = $stmt_agent_parent->fetchColumn();

// جلب معرف الحساب الرئيسي للموردين (account_code = '21101')
$stmt_supplier_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101' LIMIT 1");
$stmt_supplier_parent->execute();
$supplier_parent_id = $stmt_supplier_parent->fetchColumn();

// جلب معرف الحساب الرئيسي للموظفين (account_code = '21103')
$stmt_employee_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21103' LIMIT 1");
$stmt_employee_parent->execute();
$employee_parent_id = $stmt_employee_parent->fetchColumn();

// جلب معرف الحساب الرئيسي للفروع (account_code = '11202')
$stmt_branch_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11202' LIMIT 1");
$stmt_branch_parent->execute();
$branch_parent_id = $stmt_branch_parent->fetchColumn();

foreach ($all_accounts_raw as $acc) {
    $acc_id = intval($acc['id']);
    
    // تحديد نوع الحساب بناءً على الكيان المرتبط به أو الحساب الأب
    $account_category = null;
    
    // تحقق من نوع الحساب بناءً على الجداول الخاصة بالكيانات
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->execute([$acc_id]);
    $is_customer = $stmt->rowCount() > 0;
    
    $stmt = $pdo->prepare("SELECT id FROM agents WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->execute([$acc_id]);
    $is_agent = $stmt->rowCount() > 0;
    
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE account_id = ? AND deleted_at IS NULL");
    $stmt->execute([$acc_id]);
    $is_supplier = $stmt->rowCount() > 0;
    
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->execute([$acc_id]);
    $is_employee = $stmt->rowCount() > 0;
    
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->execute([$acc_id]);
    $is_branch = $stmt->rowCount() > 0;
    
    if ($is_customer || ($customer_parent_id && $acc['parent_id'] == $customer_parent_id)) {
        $account_category = 'customer';
    } elseif ($is_agent || ($agent_parent_id && $acc['parent_id'] == $agent_parent_id)) {
        $account_category = 'agent';
    } elseif ($is_supplier || ($supplier_parent_id && $acc['parent_id'] == $supplier_parent_id)) {
        $account_category = 'supplier';
    } elseif ($is_employee || ($employee_parent_id && $acc['parent_id'] == $employee_parent_id)) {
        $account_category = 'employee';
    } elseif ($is_branch || ($branch_parent_id && $acc['parent_id'] == $branch_parent_id)) {
        $account_category = 'branch';
    } elseif (strpos($acc['account_code'], '11101') === 0) {
        $account_category = '101';
    } elseif (strpos($acc['account_code'], '11102') === 0) {
        $account_category = '102';
    } elseif (strpos($acc['account_code'], '5') === 0) {
        $account_category = 'expense';
    }
    
    if ($account_category) {
        // إضافة display_name مثل build_flat_tree
        $acc['display_name'] = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
        $categorized_accounts[$account_category][] = $acc;
        $categorized_accounts['all'][] = $acc;
    }
}

// الآن جلب الحسابات الهرمية للقائمة الرئيسية
$accounts = get_hierarchical_accounts($pdo);

// تحويل البيانات المصنفة إلى JSON للاستخدام في الجافاسكريبت
$categorized_accounts_json = json_encode($categorized_accounts);

$currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies")->fetchAll();
?>

<div class="container-fluid py-4">
    <style>
        .statement-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 1px solid #e9ecef;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .statement-card .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-bottom: none;
            color: white;
            padding: 1.5rem;
        }

        .statement-card .card-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1.25rem;
        }

        .statement-card .form-select,
        .statement-card .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            background: #ffffff;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .statement-card .form-select.account-select {
            border: 2px solid #e9ecef;
            background: #ffffff;
        }

        .statement-card .btn-toggle-date {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            color: #6c757d;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }

        .statement-card .btn-toggle-date:hover,
        .statement-card .btn-toggle-date.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: white;
        }

        .statement-card .form-select:focus,
        .statement-card .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .statement-card .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        .statement-card .table {
            border-collapse: separate;
            border-spacing: 0 0.75rem;
            margin: 0;
        }

        .statement-card .table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            color: #2b3a4d;
            font-weight: 600;
            padding: 1rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .statement-card .table tbody tr {
            background: #ffffff;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }

        .statement-card .table tbody tr:hover {
            background-color: #f8faff;
            box-shadow: inset 4px 0 0 #667eea;
        }

        .balance-column {
            background-color: #fcfcfc;
            font-weight: 700;
        }

        .summary-widget {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }

        .summary-widget:hover {
            transform: translateY(-5px);
        }

        .statement-card .table tbody tr td,
        .statement-card .table tbody tr th {
            vertical-align: middle;
            border: none;
            padding: 1rem;
            color: #495057;
        }

        .statement-card .table tbody tr td:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        .statement-card .table tbody tr td:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .statement-card .table-hover tbody tr:hover {
            background: linear-gradient(135deg, #f8f9ff 0%, #ffffff 100%);
        }

        .statement-card .badge {
            border-radius: 20px;
            font-size: 0.75rem;
            padding: 0.5rem 1rem;
        }

        .statement-card .form-check-label {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .statement-card .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .statement-card .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .statement-card .text-success {
            color: #28a745 !important;
            font-weight: 600;
        }

        .statement-card .text-danger {
            color: #dc3545 !important;
            font-weight: 600;
        }

        .statement-card .text-primary {
            color: #007bff !important;
            font-weight: 600;
        }

        .statement-card .fw-bold {
            font-weight: 700 !important;
        }

        .statement-card .small {
            font-size: 0.8rem;
        }

        .statement-card .extra-small {
            font-size: 0.75rem;
        }

        .statement-card .action-button {
            min-width: 34px;
            padding: 0.35rem 0.5rem;
            border-radius: 10px;
            font-size: 0.82rem;
        }

        @media (max-width: 768px) {
            .statement-card .table-responsive {
                font-size: 0.8rem;
            }

            .statement-card .table thead th,
            .statement-card .table tbody td {
                padding: 0.5rem;
            }
        }
    </style>
    <div class="card border-0 statement-card shadow-none mb-4 d-print-none">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 text-dark fw-bold"><i class="fas fa-filter me-2 text-primary"></i>خيارات فلترة كشف الحساب</h5>
        </div>
        <div class="card-body bg-light p-4 rounded-bottom-4">
            <form method="GET" id="filterForm">
                <div id="updateReminder" class="alert alert-warning py-2 mb-3 text-center d-none" style="border-radius: 12px; font-weight: bold;">
                    <i class="fas fa-info-circle me-2"></i> يرجى تحديث البيانات لعرض النتائج الجديدة (اضغط على زر عرض)
                </div>
                <!-- الصف الأول: نوع التصنيف، الحساب، العملة، زر العرض -->
                <div class="row g-3 mb-3 align-items-end">
                    <div class="col-lg-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-2">1. نوع التصنيف</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-0"><i class="fas fa-tags text-primary opacity-50"></i></span>
                            <select id="account_type" name="account_type" class="form-select border-0 py-2">
                                <option value="all" <?php echo $account_type == 'all' ? 'selected' : ''; ?>>كافة التصنيفات</option>
                                <option value="101" <?php echo $account_type == '101' ? 'selected' : ''; ?>>الصناديق</option>
                                <option value="102" <?php echo $account_type == '102' ? 'selected' : ''; ?>>البنوك</option>
                                <option value="customer" <?php echo $account_type == 'customer' ? 'selected' : ''; ?>>العملاء</option>
                                <option value="supplier" <?php echo $account_type == 'supplier' ? 'selected' : ''; ?>>الموردين</option>
                                <option value="agent" <?php echo $account_type == 'agent' ? 'selected' : ''; ?>>الوكلاء</option>
                                <option value="branch" <?php echo $account_type == 'branch' ? 'selected' : ''; ?>>الفروع</option>
                                <option value="employee" <?php echo $account_type == 'employee' ? 'selected' : ''; ?>>الموظفين</option>
                                <option value="expense" <?php echo $account_type == 'expense' ? 'selected' : ''; ?>>المصاريف</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-2">2. اختيار الحساب المالي المباشر</label>
                        <div class="shadow-sm">
                            <select name="account_id" id="account_select" class="form-select account-select select2" required>
                                <option value="">-- ابحث عن الحساب أو اختر من القائمة --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>" data-code="<?php echo $acc['account_code']; ?>" <?php echo $account_id == $acc['id'] ? 'selected' : ''; ?>>
                                        <?php echo $acc['display_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php 
                        // جلب اسم الحساب المحدد للبدج
                        $selected_account_display = '';
                        if ($account_info) {
                            $selected_account_display = $account_info['account_code'] . ' - ' . $account_info['account_name_ar'];
                        }
                        ?>
                        <?php if ($selected_account_display): ?>
                            <div class="mt-2">
                                <span class="badge rounded-pill px-3 py-2" style="
                                    background-color: color-mix(in srgb, var(--primary-color) 15%, transparent) !important;
                                    color: var(--primary-color) !important;
                                    border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent) !important;
                                ">
                                    <i class="fas fa-check-circle me-1"></i>
                                    <?php echo htmlspecialchars($selected_account_display); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-2">3. عملة التقرير</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-0"><i class="fas fa-coins text-warning opacity-50"></i></span>
                            <select name="currency_id" class="form-select border-0 py-2" required>
                                <option value="all" <?php echo $currency_id == 'all' ? 'selected' : ''; ?>>جميع العملات (عرض مجمع)</option>
                                <?php foreach ($currencies as $curr): ?>
                                    <option value="<?php echo $curr['id']; ?>" <?php echo $currency_id == $curr['id'] ? 'selected' : ''; ?>><?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_symbol']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-2">
                        <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill shadow-lg fw-bold">
                            <i class="fas fa-sync-alt me-2"></i>عرض
                        </button>
                    </div>
                </div>

                <!-- الصف الثاني: نوع المستند، تحديد الفترة، التاريخ من/إلى -->
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-2">4. تصفية حسب نوع المستند</label>
                        <div class="input-group shadow-sm">
                            <span class="input-group-text bg-white border-0"><i class="fas fa-file-invoice text-info opacity-50"></i></span>
                            <select name="document_type" class="form-select border-0 py-2">
                                <option value="all" <?php echo $document_type_filter == 'all' ? 'selected' : ''; ?>>كافة أنواع المستندات</option>
                                <option value="receipt" <?php echo $document_type_filter == 'receipt' ? 'selected' : ''; ?>>سندات القبض فقط</option>
                                <option value="payment" <?php echo $document_type_filter == 'payment' ? 'selected' : ''; ?>>سندات الصرف فقط</option>
                                <option value="invoice" <?php echo $document_type_filter == 'invoice' ? 'selected' : ''; ?>>الفواتير فقط</option>
                                <option value="exchange" <?php echo $document_type_filter == 'exchange' ? 'selected' : ''; ?>>عمليات التصريف</option>
                                <option value="journal" <?php echo $document_type_filter == 'journal' ? 'selected' : ''; ?>>القيود اليومية</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-2">5. تحديد الفترة</label>
                        <input type="hidden" name="use_date_range" id="use_date_range" value="<?php echo $use_date_range ? '1' : '0'; ?>">
                        <button type="button" id="toggle_date_range" class="btn btn-white bg-white border-0 shadow-sm w-100 py-2 text-start d-flex justify-content-between align-items-center <?php echo $use_date_range ? 'active border-primary' : ''; ?>">
                            <span><i class="fas fa-calendar-alt me-2 text-secondary opacity-50"></i>تحديد تاريخ محدد</span>
                            <i class="fas fa-chevron-down small opacity-50"></i>
                        </button>
                    </div>
                    <div class="col-lg-3 date-range-field" style="display: <?php echo $use_date_range ? 'block' : 'none'; ?>;">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-2">من تاريخ</label>
                        <input type="date" id="date_from" name="date_from" class="form-control border-0 bg-white shadow-sm" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-lg-3 date-range-field" style="display: <?php echo $use_date_range ? 'block' : 'none'; ?>;">
                        <label class="form-label small fw-bold text-muted text-uppercase mb-2">إلى تاريخ</label>
                        <input type="date" id="date_to" name="date_to" class="form-control border-0 bg-white shadow-sm" value="<?php echo $date_to; ?>">
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($account_info): ?>
        <div class="card border-0 statement-card overflow-hidden shadow-sm mb-5">
            <div class="card-body p-0">
                <!-- ترويسة كشف الحساب -->
                <div class="bg-white p-4 border-bottom">
                    <div class="row align-items-center g-4">
                        <div class="col-md-7">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary-subtle p-3 rounded-4 me-3">
                                    <i class="fas fa-university fs-3 text-primary"></i>
                                </div>
                                <div>
                                    <h3 class="fw-bold text-dark mb-1"><?php echo $account_info['account_name_ar']; ?></h3>
                                    <p class="text-muted mb-0 d-flex align-items-center">
                                        <span class="badge bg-light text-dark border me-2 font-monospace"><?php echo $account_info['account_code']; ?></span>
                                        <span class="small"><i class="fas fa-clock me-1"></i> تم التوليد في: <?php echo date('Y-m-d H:i'); ?></span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5 text-md-end">
                            <div class="d-inline-block text-start bg-dark text-white p-3 rounded-4 shadow-sm" style="min-width: 280px;">
                                <div class="small fw-bold mb-1 opacity-75">صافي الرصيد الموحد (مجمع)</div>
                                <div class="h3 mb-0 fw-bold d-flex justify-content-between align-items-center">
                                    <span><?php echo number_format(abs($db_unified_net_balance), 2); ?></span>
                                    <small class="fs-6 opacity-75 ms-2"><?php echo $default_currency['currency_symbol']; ?></small>
                                </div>
                                <div class="extra-small mt-1 <?php echo ((($account_info['normal_balance'] ?? 'debit') == 'debit' && $db_unified_net_balance >= 0) || (($account_info['normal_balance'] ?? 'debit') == 'credit' && $db_unified_net_balance <= 0)) ? 'text-success' : 'text-danger'; ?>">
                                     <i class="fas <?php echo ((($account_info['normal_balance'] ?? 'debit') == 'debit' && $db_unified_net_balance >= 0) || (($account_info['normal_balance'] ?? 'debit') == 'credit' && $db_unified_net_balance <= 0)) ? 'fa-arrow-up' : 'fa-arrow-down'; ?> me-1"></i>
                                     الرصيد النهائي: <?php
                                                     if (($account_info['normal_balance'] ?? 'debit') == 'debit') {
                                                         echo $db_unified_net_balance >= 0 ? 'عليه (مدين لنا)' : 'له (دائن لنا)';
                                                     } else {
                                                         // For credit-normal accounts (suppliers):
                                                         // If balance is > 0 (debit): "عليه (مدين لنا)" → he owes us
                                                         // If balance is < 0 (credit): "له (دائن لنا)" → we owe him
                                                         echo $db_unified_net_balance > 0 ? 'عليه (مدين لنا)' : 'له (دائن لنا)';
                                                     }
                                                     ?>
                                 </div>
                                <div class="extra-small mt-2 border-top border-secondary pt-2 opacity-75">
                                    <i class="fas fa-spell-check me-1"></i>
                                    <?php echo $total_net_words_base; ?>
                                </div>

                                <?php if ($currency_id !== 'all' && $currency_info): ?>
                                    <div class="mt-3 pt-3 border-top border-secondary border-opacity-25">
                                        <div class="small fw-bold mb-1 opacity-75">رصيد (<?php echo $currency_info['currency_name'] ?? 'غير محددة'; ?>) الحالي</div>
                                        <div class="h5 mb-0 fw-bold d-flex justify-content-between align-items-center">
                                            <span><?php echo number_format(abs($selected_balance), 2); ?></span>
                                            <small class="fs-6 opacity-75 ms-2"><?php echo $currency_info['currency_symbol'] ?? ''; ?></small>
                                        </div>
                                        <div class="extra-small mt-1 <?php echo ((($account_info['normal_balance'] ?? 'debit') == 'debit' && $selected_balance >= 0) || (($account_info['normal_balance'] ?? 'debit') == 'credit' && $selected_balance <= 0)) ? 'text-success' : 'text-danger'; ?>">
                                             الحالة: <?php 
                                                 if (($account_info['normal_balance'] ?? 'debit') == 'debit') {
                                                     echo $selected_balance >= 0 ? 'عليه (مدين لنا)' : 'له (دائن لنا)';
                                                 } else {
                                                     echo $selected_balance > 0 ? 'عليه (مدين لنا)' : 'له (دائن لنا)';
                                                 }
                                             ?>
                                         </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- شريط المعلومات السريع -->
                <div class="bg-light px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-3 border-bottom">
                    <div class="d-flex gap-2">
                        <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill">
                            <i class="fas fa-calendar-day me-2 text-primary"></i>
                            الفترة: <span class="fw-bold"><?php echo $use_date_range ? "$date_from ➜ $date_to" : 'كافة الحركات التاريخية'; ?></span>
                        </span>
                        <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill">
                            <i class="fas fa-coins me-2 text-warning"></i>
                            العملة: <span class="fw-bold"><?php echo $currency_id == 'all' ? 'جميع العملات المسجلة' : ($currency_info['currency_name'] ?? 'غير محددة'); ?></span>
                        </span>
                    </div>
                    <div class="d-print-none">
                        <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 shadow-sm">
                            <i class="fas fa-print me-2"></i>طباعة التقرير
                        </button>
                    </div>
                </div>

                <!-- جدول الحركات -->
                <div class="table-responsive p-0">
                    <table class="table table-hover align-middle mb-0" style="border-spacing: 0;">
                        <thead>
                            <tr class="bg-white border-bottom">
                                <th class="ps-4 py-3 text-muted small text-center" width="100">التاريخ</th>
                                <th class="py-3 text-muted small text-center" width="130">الرقم المرجعي</th>
                                <th class="py-3 text-muted small text-center" width="110">نوع السند</th>
                                <th class="py-3 text-muted small">البيان والتفاصيل</th>
                                <th class="py-3 text-muted small text-end" width="130">مدين (عليه)</th>
                                <th class="py-3 text-muted small text-end" width="130">دائن (له)</th>
                                <?php if ($currency_id == 'all'): ?>
                                    <th class="py-3 text-muted small text-center" width="100">العملة</th>
                                <?php else: ?>
                                    <th class="py-3 text-muted small text-end pe-4" width="150">الرصيد التراكمي</th>
                                <?php endif; ?>
                                <th class="py-3 text-muted small text-center d-print-none" width="120">إجراء</th>
                            </tr>
                        </thead>
                        <tbody class="border-0">
                            <?php if ($currency_id !== 'all' && ($opening_balance != 0)): ?>
                                <tr class="bg-primary-subtle bg-opacity-10 fw-bold">
                                    <td class="ps-4 text-center small text-primary"><?php echo $use_date_range ? $date_from : 'بداية'; ?></td>
                                    <td colspan="2" class="text-center small text-muted">رصيد سابق</td>
                                    <td class="text-primary italic">الرصيد الافتتاحي المدور من الفترات السابقة</td>
                                    <td class="text-end text-success"><?php echo $opening_balance > 0 ? number_format($opening_balance, 2) : '-'; ?></td>
                                    <td class="text-end text-danger"><?php echo $opening_balance < 0 ? number_format(abs($opening_balance), 2) : '-'; ?></td>
                                    <td class="text-end pe-4 text-dark balance-column"><?php echo number_format($opening_balance, 2); ?></td>
                                    <td class="d-print-none"></td>
                                </tr>
                            <?php endif; ?>
                            <?php
                            $running_balance = ($currency_id !== 'all') ? $opening_balance : 0;
                            $total_debit = 0;
                            $total_credit = 0;
                            foreach ($transactions as $t):
                                $debit = (float)$t['debit_amount'];
                                $credit = (float)$t['credit_amount'];
                                $running_balance += ($debit - $credit);
                                $total_debit += $debit;
                                $total_credit += $credit;

                                $reference_display = $t['reference_number'];
                                $symbol = $t['currency_symbol'] ?? $currency_info['currency_symbol'] ?? 'ر.ي';

                                $document_type_display = '';
                                $badge_class = 'bg-light text-dark border';

                                // منطق مسميات أنواع السندات المحسن
                                $is_cash_bank = (strpos($account_info['account_code'], '101') === 0 || strpos($account_info['account_code'], '102') === 0);

                                if ($t['source_type'] == 'فاتورة شراء' || $t['source_type'] == 'Purchase invoice') {
                                    $document_type_display = $is_cash_bank ? 'سداد مشتريات' : 'فاتورة شراء';
                                    $badge_class = 'bg-danger-subtle text-danger border-danger-subtle';
                                } elseif ($t['source_type'] == 'فاتورة بيع' || $t['source_type'] == 'Sales invoice') {
                                    $document_type_display = $is_cash_bank ? 'تحصيل مبيعات' : 'فاتورة بيع';
                                    $badge_class = 'bg-success-subtle text-success border-success-subtle';
                                } elseif ($t['original_transaction_type'] == 'payment') {
                                    $document_type_display = $t['invoice_id'] ? 'سداد فاتورة' : 'سند صرف';
                                    $badge_class = 'bg-danger-subtle text-danger border-danger-subtle';
                                } elseif ($t['original_transaction_type'] == 'receipt') {
                                    $document_type_display = $t['invoice_id'] ? 'تحصيل فاتورة' : 'سند قبض';
                                    $badge_class = 'bg-success-subtle text-success border-success-subtle';
                                } elseif ($t['source_type'] == 'invoice') {
                                    $document_type_display = 'فاتورة';
                                    $badge_class = 'bg-primary-subtle text-primary border-primary-subtle';
                                } elseif ($t['source_type'] == 'exchange') {
                                    $document_type_display = 'تصريف';
                                    $badge_class = 'bg-warning-subtle text-warning-emphasis border-warning-subtle';
                                } else {
                                    $document_type_display = 'قيد';
                                    $badge_class = 'bg-secondary-subtle text-secondary border-secondary-subtle';
                                }
                            ?>
                                <tr class="border-bottom">
                                    <td class="ps-4 text-center small text-muted"><?php echo $t['transaction_date']; ?></td>
                                    <td class="text-center">
                                        <span class="fw-bold small text-dark"><?php echo $reference_display; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $badge_class; ?> rounded-pill extra-small"><?php echo $document_type_display; ?></span>
                                        <?php if (!empty($t['payment_status'])): ?>
                                            <div class="mt-1">
                                                <?php if ($t['payment_status'] == 'fully_paid'): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.6rem; padding: 0.2rem 0.5rem;">مسدد</span>
                                                <?php elseif ($t['payment_status'] == 'partial'): ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size: 0.6rem; padding: 0.2rem 0.5rem;">جزئي</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.6rem; padding: 0.2rem 0.5rem;">غير مسدد</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($t['service_name'])): ?>
                                            <?php 
                                                $service_display = $t['service_name'];
                                                if ($service_display === 'passport_transaction') {
                                                    $service_display = 'معاملة جوازات';
                                                }
                                            ?>
                                            <div class="extra-small text-muted mt-1" style="font-size: 0.65rem;"><?php echo htmlspecialchars($service_display); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-dark fw-medium"><?php 
                                            $desc = $t['description'] ?: '-';
                                            echo htmlspecialchars($desc);
                                        ?></div>
                                    </td>
                                    <td class="text-end text-success fw-bold">
                                        <?php echo $debit > 0 ? number_format($debit, 2) . ' <small class="opacity-75">' . htmlspecialchars($symbol) . '</small>' : '<span class="opacity-25">-</span>'; ?>
                                    </td>
                                    <td class="text-end text-danger fw-bold">
                                        <?php echo $credit > 0 ? number_format($credit, 2) . ' <small class="opacity-75">' . htmlspecialchars($symbol) . '</small>' : '<span class="opacity-25">-</span>'; ?>
                                    </td>
                                    <?php if ($currency_id == 'all'): ?>
                                        <td class="text-center"><span class="badge bg-light text-dark border extra-small"><?php echo $symbol; ?></span></td>
                                    <?php else: ?>
                                        <td class="text-end pe-4 fw-bold balance-column <?php echo $running_balance >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                            <?php echo number_format($running_balance, 2); ?> <small class="opacity-75"><?php echo htmlspecialchars($symbol); ?></small>
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-center d-print-none">
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-light border p-1 px-2" onclick="viewTransactionDetails(<?php echo $t['source_id']; ?>)" title="عرض">
                                                <i class="fas fa-eye text-primary small"></i>
                                            </button>
                                            <?php if (in_array($t['source_type'], ['payment', 'receipt'])): ?>
                                                <button type="button" class="btn btn-sm btn-light border p-1 px-2" onclick="editTransaction(<?php echo $t['source_id']; ?>, '<?php echo $t['source_type']; ?>')" title="تعديل">
                                                    <i class="fas fa-edit text-secondary small"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- تذييل الجداول والمجاميع -->
                <div class="bg-light p-4 border-top">
                    <div class="row g-4">
                        <!-- ملخص الأرصدة الحالية -->
                        <div class="col-lg-6">
                            <h6 class="fw-bold mb-3 d-flex align-items-center">
                                <i class="fas fa-chart-pie me-2 text-primary"></i>
                                ملخص الأرصدة الحالية حسب العملة
                            </h6>
                            <div class="table-responsive summary-widget bg-white">
                                <table class="table table-sm table-borderless align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3 py-2 small">العملة</th>
                                            <th class="py-2 small text-end">الرصيد</th>
                                            <th class="py-2 small text-center">الحالة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($balance_by_currency as $currency_summary): 
                                            $account_type = $account_info['normal_balance'] ?? 'debit';
                                            if ($account_type == 'debit') {
                                                $status_text = $currency_summary['balance'] >= 0 ? 'عليه' : 'له';
                                                $status_class = $currency_summary['balance'] >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                                            } else {
                                                // For credit accounts (suppliers): positive balance → "عليه", negative → "له"
                                                $status_text = $currency_summary['balance'] > 0 ? 'عليه' : 'له';
                                                $status_class = $currency_summary['balance'] > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                                            }
                                        ?>
                                            <tr class="border-bottom">
                                                <td class="ps-3 py-2">
                                                    <div class="fw-bold small"><?php echo htmlspecialchars($currency_summary['name'] ?? ''); ?></div>
                                                    <div class="extra-small text-muted"><?php echo $currency_summary['code'] ?? ''; ?></div>
                                                </td>
                                                <td class="text-end py-2 fw-bold <?php echo ($account_type == 'debit' && $currency_summary['balance'] >= 0) || ($account_type == 'credit' && $currency_summary['balance'] <= 0) ? 'text-primary' : 'text-danger'; ?>">
                                                    <?php echo number_format(abs($currency_summary['balance']), 2); ?>
                                                </td>
                                                <td class="text-center py-2">
                                                    <span class="badge <?php echo $status_class; ?> rounded-pill extra-small">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- ملخص حركات الفترة -->
                        <div class="col-lg-6">
                            <h6 class="fw-bold mb-3 d-flex align-items-center">
                                <i class="fas fa-exchange-alt me-2 text-warning"></i>
                                إجمالي حركات الفترة المحددة
                            </h6>
                            <div class="table-responsive summary-widget bg-white">
                                <table class="table table-sm table-borderless align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-3 py-2 small">العملة</th>
                                            <th class="py-2 small text-end text-success">إجمالي مدين</th>
                                            <th class="py-2 small text-end text-danger">إجمالي دائن</th>
                                            <th class="py-2 small text-end">صافي الحركة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($transaction_summary_by_currency)): ?>
                                            <?php foreach ($transaction_summary_by_currency as $summary):
                                                $net_movement = $summary['total_debit'] - $summary['total_credit'];
                                                $account_type = $account_info['normal_balance'] ?? 'debit';
                                                if ($account_type == 'debit') {
                                                    $movement_type = $net_movement >= 0 ? 'عليه' : 'له';
                                                } else {
                                                    $movement_type = $net_movement > 0 ? 'عليه' : 'له';
                                                }
                                                // Set color classes based on account type
                                                if ($account_type == 'debit') {
                                                    $net_class = $net_movement >= 0 ? 'text-success' : 'text-danger';
                                                    $badge_class = $net_movement >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                                                } else {
                                                    $net_class = $net_movement > 0 ? 'text-success' : 'text-danger';
                                                    $badge_class = $net_movement > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                                                }
                                                $movement_words = tafqeet_php(abs($net_movement), $summary['currency_name']);
                                            ?>
                                                <tr class="border-bottom">
                                                    <td class="ps-3 py-2 fw-bold small"><?php echo htmlspecialchars($summary['currency_name']); ?></td>
                                                    <td class="text-end py-2 text-success fw-bold small"><?php echo number_format($summary['total_debit'], 2); ?></td>
                                                    <td class="text-end py-2 text-danger fw-bold small"><?php echo number_format($summary['total_credit'], 2); ?></td>
                                                    <td class="text-end py-2 fw-bold small">
                                                        <div class="<?php echo $net_class; ?>">
                                                            <?php echo number_format(abs($net_movement), 2); ?>
                                                            <span class="badge <?php echo $badge_class; ?> ms-1">
                                                                <?php echo $movement_type; ?>
                                                            </span>
                                                        </div>
                                                        <div class="extra-small text-muted mt-1" style="font-size: 0.7rem;">
                                                            <?php echo $movement_words; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-4 text-muted small italic">لا توجد حركات في هذه الفترة</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- قسم عمليات التصريف -->
                <?php if (!empty($exchange_transactions)): ?>
                    <div class="mt-4">
                        <h6 class="fw-bold mb-3 d-flex align-items-center">
                            <i class="fas fa-exchange-alt me-2 text-warning"></i>
                            عمليات التصريف المتعلقة بالحساب
                        </h6>
                        <div class="table-responsive bg-white rounded-3 border">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4 py-3 text-muted small text-center" width="100">التاريخ</th>
                                        <th class="py-3 text-muted small text-center" width="130">رقم المعاملة</th>
                                        <th class="py-3 text-muted small">من العملة</th>
                                        <th class="py-3 text-muted small">إلى العملة</th>
                                        <th class="py-3 text-muted small text-end">المبلغ المُصرف</th>
                                        <th class="py-3 text-muted small text-end">المبلغ المُستلم</th>
                                        <th class="py-3 text-muted small text-center">سعر الصرف</th>
                                        <th class="py-3 text-muted small">الملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exchange_transactions as $ex): ?>
                                        <tr class="border-bottom">
                                            <td class="ps-4 text-center small text-muted"><?php echo $ex['transaction_date']; ?></td>
                                            <td class="text-center">
                                                <span class="fw-bold small text-dark"><?php echo $ex['transaction_number']; ?></span>
                                            </td>
                                            <td class="small">
                                                <div class="fw-bold"><?php echo $ex['from_currency_symbol']; ?> <?php echo number_format($ex['from_amount'], 2); ?></div>
                                                <div class="text-muted extra-small"><?php echo $ex['from_account_name']; ?></div>
                                            </td>
                                            <td class="small">
                                                <div class="fw-bold"><?php echo $ex['to_currency_symbol']; ?> <?php echo number_format($ex['to_amount'], 2); ?></div>
                                                <div class="text-muted extra-small"><?php echo $ex['to_account_name']; ?></div>
                                            </td>
                                            <td class="text-end text-danger fw-bold small"><?php echo number_format($ex['from_amount'], 2); ?> <?php echo $ex['from_currency_symbol']; ?></td>
                                            <td class="text-end text-success fw-bold small"><?php echo number_format($ex['to_amount'], 2); ?> <?php echo $ex['to_currency_symbol']; ?></td>
                                            <td class="text-center small">
                                                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">
                                                    <?php echo number_format($ex['exchange_rate'], 4); ?>
                                                </span>
                                            </td>
                                            <td class="small text-muted"><?php echo htmlspecialchars($ex['notes'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($_GET): ?>
        <div class="alert bg-white border rounded-4 p-5 text-center shadow-sm">
            <i class="fas fa-search fs-1 text-primary opacity-25 mb-3 d-block"></i>
            <h5 class="fw-bold text-dark">جاهز لتوليد كشف الحساب</h5>
            <p class="text-muted">يرجى اختيار الحساب والعملة من الأعلى، ثم اضغط على زر "توليد كشف الحساب" لعرض النتائج.</p>
        </div>
    <?php endif; ?>
</div>
</div>

<!-- View details modal -->
<div class="modal fade" id="transactionDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-invoice me-2"></i> تفاصيل السند</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="transactionDetailsContent">
                <div class="text-center py-5 text-muted">جاري جلب بيانات السند...</div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script>
    const categorizedAccounts = <?php echo $categorized_accounts_json; ?>;
    const allAccounts = <?php echo json_encode($accounts); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5'
        });

        // فلترة الحسابات بناءً على النوع
        function filterAccounts() {
            const typeCode = $('#account_type').val();
            const selectedVal = $('#account_select').val();

            $('#account_select').empty().append('<option value="">-- ابحث عن الحساب أو اختر من القائمة --</option>');

            let accountsToShow = [];
            if (typeCode === 'all') {
                accountsToShow = allAccounts;
            } else if (categorizedAccounts[typeCode]) {
                accountsToShow = categorizedAccounts[typeCode];
            }

            accountsToShow.forEach(function(acc) {
                const displayName = acc.display_name || acc.account_name_ar || 'حساب';
                const isSelected = (selectedVal && acc.id == selectedVal) ? 'selected' : '';
                $('#account_select').append(`<option value="${acc.id}" data-code="${acc.account_code || ''}" ${isSelected}>${displayName}</option>`);
            });

            $('#account_select').trigger('change.select2');
        }

        // إظهار تنبيه عند تغيير أي قيمة
        $('#account_type, #account_select, [name="currency_id"], [name="document_type"], #date_from, #date_to').on('change', function() {
            $('#updateReminder').removeClass('d-none');
        });

        $('#account_type').on('change', function() {
            filterAccounts();
        });

        // فلترة الحسابات عند تحميل الصفحة
        filterAccounts();

        // زر تحديد الفترة
        $('#toggle_date_range').on('click', function() {
            const useDateRangeField = $('#use_date_range');
            const enabled = useDateRangeField.val() === '0';
            useDateRangeField.val(enabled ? '1' : '0');

            $('.date-range-field').toggle(enabled);
            $(this).toggleClass('active border-primary', enabled);
            $('#updateReminder').removeClass('d-none');
        });

        updateDateSection();
    });

    function viewTransactionDetails(id) {
        $('#transactionDetailsContent').html('<div class="text-center py-5 text-muted">جاري جلب بيانات السند...</div>');
        $('#transactionDetailsModal').modal('show');
        $.get('ajax/get_voucher_details.php', {
            id: id
        }, function(v) {
            if (!v) {
                $('#transactionDetailsContent').html('<div class="text-center py-5 text-danger">تعذر جلب بيانات السند.</div>');
                return;
            }

            const statusBadge = v.status === 'draft' ?
                '<span class="badge bg-warning text-dark">مسودة</span>' :
                (v.status === 'posted' ? '<span class="badge bg-success text-white">مرحلة</span>' : '<span class="badge bg-danger text-white">ملغاة</span>');

            let allocationsHtml = '';
            if (v.allocations && v.allocations.length > 0) {
                allocationsHtml = `
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-file-invoice me-2"></i>الفواتير المسددة</h6>
                        <div class="table-responsive">
                            <table class="table table-sm small mb-0">
                                <thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>المبلغ المخصص</th></tr></thead>
                                <tbody>
                                    ${v.allocations.map(a => `<tr><td>${a.invoice_number}</td><td>${a.invoice_date}</td><td>${parseFloat(a.allocated_amount).toLocaleString()}</td></tr>`).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            let auditHtml = '';
            if (v.audit_logs && v.audit_logs.length > 0) {
                auditHtml = `
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-history me-2"></i>سجل التعديلات</h6>
                        ${v.audit_logs.map(log => `
                            <div class="p-3 mb-2 bg-light rounded-3 border-start border-3 border-primary">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold small text-primary">${log.action_type || 'تعديل'}</span>
                                    <span class="extra-small text-muted">${log.created_at}</span>
                                </div>
                                <div class="small text-muted">${log.user_name || 'غير معروف'} - ${log.user_ip || ''}</div>
                                ${log.reason ? `<div class="mt-2 small text-danger">السبب: ${log.reason}</div>` : ''}
                            </div>
                        `).join('')}
                    </div>
                `;
            }

            const html = `
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">رقم السند</div>
                        <div class="fw-bold fs-5">${v.transaction_number}</div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="small text-muted mb-1">الحالة</div>
                        <div>${statusBadge}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">التاريخ</div>
                        <div class="fw-bold">${v.transaction_date}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">نوع السند</div>
                        <div class="fw-bold text-capitalize">${v.transaction_type || '-'}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted mb-1">المبلغ</div>
                        <div class="fw-bold text-primary">${parseFloat(v.amount).toLocaleString()} ${v.currency_symbol || ''}</div>
                    </div>
                </div>
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">الحساب</div>
                        <div class="fw-bold">${v.account_name || '-'}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">الطرف</div>
                        <div class="fw-bold">${v.party_name || '-'}</div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted mb-1">الوصف</div>
                        <div class="fw-bold">${v.description || '-'}</div>
                    </div>
                </div>
                ${allocationsHtml}
                ${auditHtml}
                <div class="bg-light p-3 rounded-4 text-muted">
                    <div class="row">
                        <div class="col-md-6 mb-2"><strong>أنشئ بواسطة:</strong> ${v.creator_name || 'غير معروف'} في ${v.created_at || '-'}</div>
                        ${v.posted_at ? `<div class="col-md-6 mb-2"><strong>رُحل بواسطة:</strong> ${v.poster_name || 'غير معروف'} في ${v.posted_at}</div>` : ''}
                    </div>
                </div>
            `;

            $('#transactionDetailsContent').html(html);
        }, 'json').fail(function() {
            $('#transactionDetailsContent').html('<div class="text-center py-5 text-danger">تعذر جلب بيانات السند.</div>');
        });
    }

    function editTransaction(id, sourceType) {
        let targetUrl = null;
        if (sourceType === 'payment') {
            targetUrl = 'payments.php?edit_id=' + encodeURIComponent(id);
        } else if (sourceType === 'receipt') {
            targetUrl = 'receipts.php?edit_id=' + encodeURIComponent(id);
        } else if (sourceType === 'exchange') {
            targetUrl = 'currency_exchange.php?edit_id=' + encodeURIComponent(id);
        }

        if (!targetUrl) {
            alert('لا يمكن تعديل هذا النوع من السندات من هنا.');
            return;
        }

        window.location.href = targetUrl;
    }

    function deleteTransaction(id) {
        if (!confirm('هل أنت متأكد من حذف هذا السند نهائياً؟')) {
            return;
        }

        $.post('ajax/delete_voucher.php', {
            id: id,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.message || 'فشل حذف السند.');
            }
        }, 'json').fail(function() {
            alert('حدث خطأ أثناء محاولة حذف السند.');
        });
    }
</script>

<?php require_once 'footer.php'; ?>
