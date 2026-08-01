<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';

$settings = getSettings($pdo);
$base_currency = $pdo->query("SELECT * FROM currencies WHERE is_default = 1")->fetch();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['admin_id'];
$success_msg = "";
$error_msg = "";
$umrah_source_aliases = get_umrah_service_aliases();
$umrah_source_placeholders = implode(', ', array_fill(0, count($umrah_source_aliases), '?'));
$hajj_source_aliases = get_hajj_service_aliases();
$hajj_source_placeholders = implode(', ', array_fill(0, count($hajj_source_aliases), '?'));
$postal_source_aliases = get_postal_service_aliases();
$postal_source_placeholders = implode(', ', array_fill(0, count($postal_source_aliases), '?'));
$booking_service_source_aliases = ['حجوزات الباصات والطيران', 'تذاكر طيران وبصات', 'حجوزات الباصات', 'حجوزات الطيران', 'bus', 'flight', 'الطيران'];
$booking_service_source_placeholders = implode(', ', array_fill(0, count($booking_service_source_aliases), '?'));
$passport_service_source_aliases = array_values(array_unique(array_merge($umrah_source_aliases, $hajj_source_aliases)));
$passport_service_source_placeholders = implode(', ', array_fill(0, count($passport_service_source_aliases), '?'));
$family_visit_source_aliases = ['زيارة عائلية'];
$family_visit_source_placeholders = implode(', ', array_fill(0, count($family_visit_source_aliases), '?'));
$passport_transaction_source_aliases = ['معاملات جواز', 'معاملات جوازات'];
$passport_transaction_source_placeholders = implode(', ', array_fill(0, count($passport_transaction_source_aliases), '?'));

function invoice_safe_return_to($value, $default = 'invoices.php')
{
    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    $parts = parse_url($value);
    if ($parts === false || !empty($parts['scheme']) || !empty($parts['host'])) {
        return $default;
    }

    $path = ltrim((string)($parts['path'] ?? ''), "/\\");
    if ($path === '') {
        $path = $default;
    }

    $result = $path;
    if (!empty($parts['query'])) {
        $result .= '?' . $parts['query'];
    }

    return $result;
}

$legacyInvoiceActionRequested = isset($_GET['reset_invoice']) || isset($_GET['unpost_invoice']) || isset($_GET['post_invoice']) || isset($_GET['post_all']) || isset($_GET['delete_invoice']) || isset($_GET['reset_purchase']) || isset($_GET['post_purchase']) || isset($_GET['confirm_linked']) || isset($_GET['delete_both']) || isset($_GET['delete_linked_only']);
if ($legacyInvoiceActionRequested) {
    $error_msg = "تم تعطيل تنفيذ أوامر الفواتير الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// API endpoint to get active currencies for an account
if (isset($_GET['action']) && $_GET['action'] === 'get_active_currencies' && isset($_GET['account_id'])) {
    $account_id_param = $_GET['account_id'];
    if ($account_id_param === 'all') {
        // Return all currencies
        $stmt = $pdo->query("SELECT id, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell, is_default FROM currencies ORDER BY is_default DESC, currency_name ASC");
        $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $account_id = (int)$account_id_param;
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.currency_name, c.currency_symbol, c.exchange_rate, c.exchange_rate_buy, c.exchange_rate_sell, c.is_default
            FROM account_balances_unified abu
            JOIN currencies c ON abu.currency_id = c.id
            WHERE abu.account_id = ? AND abu.is_frozen = 0
            ORDER BY c.is_default DESC, c.currency_name ASC
        ");
        $stmt->execute([$account_id]);
        $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json');
    echo json_encode($currencies);
    exit;
}

// API endpoint to get account_id from entity (customer_id, supplier_id, or agent_id)
if (isset($_GET['action']) && $_GET['action'] === 'get_account_from_entity' && isset($_GET['entity_type']) && isset($_GET['entity_id'])) {
    $entity_type = $_GET['entity_type'];
    $entity_id = (int)$_GET['entity_id'];
    $account_id = null;
    
    if ($entity_type === 'customer') {
        $stmt = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
    } elseif ($entity_type === 'supplier') {
        $stmt = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
    } elseif ($entity_type === 'agent') {
        $stmt = $pdo->prepare("SELECT account_id FROM agents WHERE id = ?");
    }
    
    if (isset($stmt)) {
        $stmt->execute([$entity_id]);
        $account_id = $stmt->fetchColumn();
    }
    
    header('Content-Type: application/json');
    echo json_encode(['account_id' => $account_id]);
    exit;
}

if (isset($_GET['posted']) && $_GET['posted'] === '1' && empty($_GET['status'])) {
    $_GET['status'] = 'posted';
}

// فلترة الفواتير (نقلها للأعلى ليتم استخدامها في الاستعلام الرئيسي قبل Header)

// جلب إعدادات الترقيم العامة للاستعلام
$def_s_pref = $settings['sales_invoice_prefix'] ?? 'SAL-';
$def_p_pref = $settings['purchase_invoice_prefix'] ?? 'PUR-';

// تجهيز الاستعلام الرئيسي لدمج الفواتير بطريقة ذكية

// جلب بيانات الموردين مع أكواد حساباتهم مسبقاً بشكل هرمي
// جلب معرف الأب للموردين (21101)
$parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$parent_stmt_suppliers->execute();
$suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

// جلب الموردين من النظام الموحد مثل suppliers.php
$suppliers_stmt = $pdo->prepare("
    SELECT coa.*,
           (SELECT id FROM suppliers WHERE account_id = coa.id LIMIT 1) as supplier_id
    FROM unified_accounts coa
    WHERE coa.parent_id = ? AND coa.account_status = 'active'
    ORDER BY coa.account_code ASC
");
$suppliers_stmt->execute([$suppliers_parent_id]);
$suppliers_with_codes = [];
while ($row = $suppliers_stmt->fetch()) {
    $row['display_name'] = $row['account_code'] . ' - ' . $row['account_name_ar'];
    $suppliers_with_codes[] = $row;
}

// جلب البيانات للقوائم
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell FROM currencies ORDER BY is_default DESC, currency_name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, supplier_name, account_id FROM suppliers WHERE deleted_at IS NULL ORDER BY supplier_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC")->fetchAll();

// جلب حسابات فروق العملة
$exchange_gain_id = get_setting('exchange_gain_account_id');
$exchange_loss_id = get_setting('exchange_loss_account_id');
$exchange_gain_name = "";
$exchange_loss_name = "";

if ($exchange_gain_id) {
    $stmt_gain = $pdo->prepare("SELECT account_name_ar FROM unified_accounts WHERE id = ?");
    $stmt_gain->execute([$exchange_gain_id]);
    $exchange_gain_name = $stmt_gain->fetchColumn();
}
if ($exchange_loss_id) {
    $stmt_loss = $pdo->prepare("SELECT account_name_ar FROM unified_accounts WHERE id = ?");
    $stmt_loss->execute([$exchange_loss_id]);
    $exchange_loss_name = $stmt_loss->fetchColumn();
}

// جلب الكيانات مع حساباتها بشكل هرمي
// دالة مساعدة لجلب الحسابات تحت حساب أب معين
function get_accounts_under_parent($pdo, $parent_account_code, $entity_type = null) {
    // جلب معرف الحساب الأب
    $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
    $stmt_parent->execute([$parent_account_code]);
    $parent_id = $stmt_parent->fetchColumn();
    if (!$parent_id) return [];
    
    // جلب الحسابات تحت هذا الأب
    $stmt = $pdo->prepare("
        SELECT ua.id, ua.account_code, ua.account_name_ar,
               (SELECT id FROM customers WHERE account_id = ua.id LIMIT 1) as customer_id,
               (SELECT id FROM agents WHERE account_id = ua.id LIMIT 1) as agent_id,
               (SELECT id FROM suppliers WHERE account_id = ua.id LIMIT 1) as supplier_id
        FROM unified_accounts ua
        WHERE ua.parent_id = ? AND ua.account_status = 'active'
        ORDER BY ua.account_code ASC
    ");
    $stmt->execute([$parent_id]);
    $accounts = [];
    while ($row = $stmt->fetch()) {
        $display_name = $row['account_code'] . ' - ' . $row['account_name_ar'];
        if ($entity_type === 'customer' && empty($row['customer_id'])) {
            $display_name .= ' (legacy غير مربوط)';
        } elseif ($entity_type === 'agent' && empty($row['agent_id'])) {
            $display_name .= ' (legacy غير مربوط)';
        }
        $row['display_name'] = $display_name;
        $row['name'] = $row['account_name_ar'];
        $accounts[] = $row;
    }
    return $accounts;
}

$cashboxes_entities = get_accounts_under_parent($pdo, '11101');
$banks_entities = get_accounts_under_parent($pdo, '11102');
$customers_entities = get_accounts_under_parent($pdo, '11201', 'customer');
$agents_entities = get_accounts_under_parent($pdo, '11203', 'agent');

// Debug: Uncomment to see the data
// echo "<pre>";
// echo "cashboxes_entities: "; var_dump($cashboxes_entities);
// echo "banks_entities: "; var_dump($banks_entities);
// echo "customers_entities: "; var_dump($customers_entities);
// echo "agents_entities: "; var_dump($agents_entities);
// echo "</pre>";
// exit;

$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC")->fetchAll();

// Load service configs for frontend
$service_configs = [];
foreach ($services as $s) {
    $cfg = getServiceInvoiceConfig($s['service_name'], $settings);
    // Get account names for display
    $revenue_acc_name = '';
    if ($cfg['revenue_account_id']) {
        $stmt = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
        $stmt->execute([$cfg['revenue_account_id']]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($acc) $revenue_acc_name = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
    }
    $cost_acc_name = '';
    if ($cfg['cost_account_id']) {
        $stmt = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
        $stmt->execute([$cfg['cost_account_id']]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($acc) $cost_acc_name = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
    }
    $profit_acc_name = '';
    if ($cfg['profit_account_id']) {
        $stmt = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
        $stmt->execute([$cfg['profit_account_id']]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($acc) $profit_acc_name = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
    }
    $service_configs[$s['service_name']] = [
        'revenue_account_id' => $cfg['revenue_account_id'],
        'revenue_account_name' => $revenue_acc_name,
        'cost_account_id' => $cfg['cost_account_id'],
        'cost_account_name' => $cost_acc_name,
        'profit_account_id' => $cfg['profit_account_id'],
        'profit_account_name' => $profit_acc_name
    ];
}

// دالة لجلب اسم الطرف
function getPartyName($pdo, $inv, $party_name_maps = null)
{
    $party_name_maps = $party_name_maps ?: [
        'customers' => [],
        'agents' => [],
        'suppliers' => [],
        'accounts' => [],
    ];
    $category = $inv['invoice_category'] ?? null;
    $supplier_id = $inv['supplier_id'] ?? null;
    $account_id = $inv['account_id'] ?? null;
    $customer_id = $inv['customer_id'] ?? null;
    $agent_id = $inv['agent_id'] ?? null;

    // للأمان: إذا كان هناك عميل أو وكيل محدد، نعرض اسمه أولاً
    if (!empty($customer_id)) {
        $name = $party_name_maps['customers'][(int)$customer_id] ?? null;
        if ($name === null) {
            $stmt = $pdo->prepare("SELECT full_name as name FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $name = $stmt->fetchColumn();
        }
        if ($name) {
            return "عميل: " . $name;
        }
    } elseif (!empty($agent_id)) {
        $name = $party_name_maps['agents'][(int)$agent_id] ?? null;
        if ($name === null) {
            $stmt = $pdo->prepare("SELECT agent_name as name FROM agents WHERE id = ?");
            $stmt->execute([$agent_id]);
            $name = $stmt->fetchColumn();
        }
        if ($name) {
            return "وكيل: " . $name;
        }
    }

    // إذا لم يوجد عميل/وكيل، نتحقق من نوع الفاتورة
    if ($category == 'purchase' && !empty($supplier_id)) {
        $name = $party_name_maps['suppliers'][(int)$supplier_id] ?? null;
        if ($name === null) {
            $stmt = $pdo->prepare("SELECT supplier_name as name FROM suppliers WHERE id = ?");
            $stmt->execute([$supplier_id]);
            $name = $stmt->fetchColumn();
        }
        if ($name) {
            return "مورد: " . $name;
        }
    }

    // إذا لم يوجد أي من الأطراف القديمة، نحاول جلب من unified_accounts باستخدام account_id
    if (!empty($account_id)) {
        $name = $party_name_maps['accounts'][(int)$account_id] ?? null;
        if ($name === null) {
            $stmt = $pdo->prepare("SELECT account_name_ar as name FROM unified_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $name = $stmt->fetchColumn();
        }
        if ($name) {
            return $name;
        }
    }

    return "فاتورة عامة";
}

function getPartyNameMaps($pdo, $invoices)
{
    $ids = [
        'customers' => [],
        'agents' => [],
        'suppliers' => [],
        'accounts' => [],
    ];

    foreach ($invoices as $invoice) {
        foreach ([
            'customers' => $invoice['customer_id'] ?? null,
            'agents' => $invoice['agent_id'] ?? null,
            'suppliers' => $invoice['supplier_id'] ?? null,
            'accounts' => $invoice['account_id'] ?? null,
        ] as $type => $id) {
            if ($id !== null && $id !== '') {
                $ids[$type][(int)$id] = true;
            }
        }
    }

    $maps = [
        'customers' => [],
        'agents' => [],
        'suppliers' => [],
        'accounts' => [],
    ];
    $queries = [
        'customers' => 'SELECT id, full_name AS name FROM customers WHERE id IN (%s)',
        'agents' => 'SELECT id, agent_name AS name FROM agents WHERE id IN (%s)',
        'suppliers' => 'SELECT id, supplier_name AS name FROM suppliers WHERE id IN (%s)',
        'accounts' => 'SELECT id, account_name_ar AS name FROM unified_accounts WHERE id IN (%s)',
    ];

    foreach ($queries as $type => $sql) {
        if (empty($ids[$type])) {
            continue;
        }
        $placeholders = implode(', ', array_fill(0, count($ids[$type]), '?'));
        $stmt = $pdo->prepare(sprintf($sql, $placeholders));
        $stmt->execute(array_keys($ids[$type]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $maps[$type][(int)$row['id']] = $row['name'];
        }
    }

    return $maps;
}

function resolveInvoicePartyAccounts($pdo, $delivery_type, $account_id, $customer_id = null, $agent_id = null, $supplier_id = null)
{
    $resolved = [
        'customer_account_id' => null,
        'agent_account_id' => null,
        'supplier_account_id' => null,
    ];

    if ($customer_id) {
        $stmt = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $resolved['customer_account_id'] = $stmt->fetchColumn() ?: null;
    }

    if ($agent_id) {
        $stmt = $pdo->prepare("SELECT account_id FROM agents WHERE id = ?");
        $stmt->execute([$agent_id]);
        $resolved['agent_account_id'] = $stmt->fetchColumn() ?: null;
    }

    if ($supplier_id) {
        $stmt = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $resolved['supplier_account_id'] = $stmt->fetchColumn() ?: null;
    }

    // Legacy fallback: عند غياب سجل customer/agent المرتبط، نستخدم الحساب المختار مباشرة.
    if (!$resolved['customer_account_id'] && in_array($delivery_type, ['credit', 'credit_doc'], true) && $account_id) {
        $resolved['customer_account_id'] = $account_id;
    }

    if (!$resolved['agent_account_id'] && $delivery_type === 'agent' && $account_id) {
        $resolved['agent_account_id'] = $account_id;
    }

    return $resolved;
}

// معالجة العمليات (يجب أن تكون قبل header.php لمنع خطأ Headers already sent)
// معالجة ترحيل الفواتير (تعديل)
if (isset($_POST['update_invoice'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        try {
            $pdo->beginTransaction();

            $invoice_id = (int)$_POST['invoice_id'];
            $p_num = "";
            $s_num = "";

            // جلب الفاتورة الحالية وتحديد الفواتير المرتبطة (بيع وشراء)
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $current_inv = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current_inv) throw new Exception("الفاتورة غير موجودة.");

            // البحث عن الفاتورة المقابلة (بيع أو شراء)
            $sale_data = ($current_inv['invoice_category'] == 'sales') ? $current_inv : null;
            $pur_data = ($current_inv['invoice_category'] == 'purchase') ? $current_inv : null;

            // إذا كانت فاتورة بيع، نبحث عن الشراء المرتبط بها
            if ($sale_data) {
                $inv_config = getServiceInvoiceConfig($sale_data['source_type'], $settings);
                $s_pref = $inv_config['sales_prefix'];
                $p_pref = $inv_config['purchase_prefix'];
                $p_num = str_replace($s_pref, $p_pref, $sale_data['invoice_number']);

                $stmt_pur = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ? AND invoice_category = 'purchase' LIMIT 1");
                $stmt_pur->execute([$p_num]);
                $pur_data = $stmt_pur->fetch(PDO::FETCH_ASSOC);
            } else if ($pur_data) {
                // إذا كانت فاتورة شراء، نبحث عن البيع المرتبط بها
                $inv_config = getServiceInvoiceConfig($pur_data['source_type'], $settings);
                $s_pref = $inv_config['sales_prefix'];
                $p_pref = $inv_config['purchase_prefix'];
                $s_num = str_replace($p_pref, $s_pref, $pur_data['invoice_number']);

                $stmt_sale = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ? AND invoice_category = 'sales' LIMIT 1");
                $stmt_sale->execute([$s_num]);
                $sale_data = $stmt_sale->fetch(PDO::FETCH_ASSOC);
            }

            if ($current_inv['invoice_status'] == 'draft') {
                $invoice_date = normalize_datetime_db($_POST['invoice_date'] ?? null);

                // --- التحقق من إغلاق الفترة المالية ---
                if (is_period_closed($pdo, $invoice_date)) {
                    throw new Exception("تنبيه: لا يمكن تعديل الفاتورة. التاريخ الجديد ($invoice_date) يقع ضمن فترة مالية مغلقة.");
                }
                // --- نهاية التحقق ---

                $branch_id = $_POST['branch_id'];
                $source_type = $_POST['source_type'];
                $description = $_POST['description'];
                $currency_id = $_POST['currency_id']; // هذه هي عملة التكلفة/الشراء (المورد)
                $sale_currency_id = $_POST['sale_currency_id'] ?? $currency_id; // عملة البيع
                $exchange_rate = (float)($_POST['exchange_rate'] ?? 1);

                // تحديث فاتورة البيع
                if ($sale_data && $sale_data['invoice_status'] == 'draft') {
                    // جلب البيانات القديمة
                    $stmt_old_sale = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                    $stmt_old_sale->execute([$sale_data['id']]);
                    $old_sale = $stmt_old_sale->fetch(PDO::FETCH_ASSOC);

                    $total_amount = (float)$_POST['total_amount'];
                    $discount = (float)($_POST['discount'] ?? 0);
                    $cost_amount = (float)$_POST['cost_amount'];

                    // تحويل التكلفة لعملة البيع إذا اختلفتا
                    $cost_in_sale_currency = $cost_amount;
                    if ($sale_currency_id != $currency_id && $exchange_rate > 0) {
                        $cost_in_sale_currency = $cost_amount * $exchange_rate;
                    }

                    $delivery_type = $_POST['delivery_type'];
                    $account_id = $_POST['account_id'] ?: null;
                    $amount_received = (float)$_POST['amount_received'];
                    $customer_id = $_POST['customer_id'] ?: null;
                    $agent_id = $_POST['agent_id'] ?: null;

                    // التحقق من صحة حساب الصندوق/البنك إذا كان مُختارًا
                    if ($account_id) {
                        $cash_bank_validation = validate_postable_account($pdo, $account_id);
                        if (!$cash_bank_validation['valid']) {
                            throw new Exception($cash_bank_validation['message']);
                        }
                    }

                    $partyAccounts = resolveInvoicePartyAccounts($pdo, $delivery_type, $account_id, $customer_id, $agent_id, null);
                    $customer_account_id = $partyAccounts['customer_account_id'];
                    $agent_account_id = $partyAccounts['agent_account_id'];

                    if ($customer_account_id) {
                        $cust_acc_validation = validate_postable_account($pdo, $customer_account_id);
                        if (!$cust_acc_validation['valid']) {
                            throw new Exception($cust_acc_validation['message']);
                        }
                    }

                    if ($agent_account_id) {
                        $agent_acc_validation = validate_postable_account($pdo, $agent_account_id);
                        if (!$agent_acc_validation['valid']) {
                            throw new Exception($agent_acc_validation['message']);
                        }
                    }

                    // --- Credit/Debit Limit Check for Update ---
                    if ($customer_account_id && in_array($delivery_type, ['credit', 'credit_doc'])) {
                        check_account_limits($pdo, $customer_account_id, $sale_currency_id, ($total_amount - $discount));
                    }
                    // --- End Limit Check ---

                    $supplier_id = $_POST['supplier_id'] ?: null;

                    $stmt_up_sale = $pdo->prepare("UPDATE invoices SET
                    invoice_date = ?, branch_id = ?, source_type = ?, description = ?,
                    total_amount = ?, discount = ?, cost_amount = ?, currency_id = ?,
                    delivery_type = ?, account_id = ?, customer_id = ?, agent_id = ?, supplier_id = ?, customer_account_id = ?, amount_received = ?,
                    updated_at = CURRENT_TIMESTAMP, updated_by = ?
                    WHERE id = ?");
                    $stmt_up_sale->execute([$invoice_date, $branch_id, $source_type, $description, $total_amount, $discount, $cost_in_sale_currency, $sale_currency_id, $delivery_type, $account_id, $customer_id, $agent_id, $supplier_id, $customer_account_id, $amount_received, $_SESSION['admin_id'], $sale_data['id']]);

                    // جلب البيانات الجديدة
                    $stmt_new_sale = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                    $stmt_new_sale->execute([$sale_data['id']]);
                    $new_sale = $stmt_new_sale->fetch(PDO::FETCH_ASSOC);

                    log_audit($pdo, 'update', 'invoices', $sale_data['id'], $old_sale, $new_sale, "تعديل فاتورة بيع");
                }

                // تحديث فاتورة الشراء
                if ($pur_data && $pur_data['invoice_status'] == 'draft') {
                    // جلب البيانات القديمة
                    $stmt_old_pur = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                    $stmt_old_pur->execute([$pur_data['id']]);
                    $old_pur = $stmt_old_pur->fetch(PDO::FETCH_ASSOC);

                    $cost_amount = (float)$_POST['cost_amount'];
                    $supplier_id = $_POST['supplier_id'] ?: null;
                    $partyAccounts = resolveInvoicePartyAccounts($pdo, 'credit', null, null, null, $supplier_id);
                    $supplier_account_id = $partyAccounts['supplier_account_id'];
                    if ($supplier_account_id) {
                        $supplier_acc_validation = validate_postable_account($pdo, $supplier_account_id);
                        if (!$supplier_acc_validation['valid']) {
                            throw new Exception($supplier_acc_validation['message']);
                        }
                    }

                    $stmt_up_pur = $pdo->prepare("UPDATE invoices SET
                    invoice_date = ?, branch_id = ?, source_type = ?, supplier_id = ?,
                    currency_id = ?, total_amount = ?, account_id = ?, supplier_account_id = ?, description = ?,
                    updated_at = CURRENT_TIMESTAMP, updated_by = ?
                    WHERE id = ?");

                    // --- التحقق من حدود المورد عند التعديل ---
                    if ($supplier_account_id) {
                        check_account_limits($pdo, $supplier_account_id, $currency_id, $cost_amount);
                    }
                    // --- نهاية التحقق ---

                    $stmt_up_pur->execute([$invoice_date, $branch_id, $source_type, $supplier_id, $currency_id, $cost_amount, $supplier_account_id, $supplier_account_id, $description, $_SESSION['admin_id'], $pur_data['id']]);

                    // جلب البيانات الجديدة
                    $stmt_new_pur = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                    $stmt_new_pur->execute([$pur_data['id']]);
                    $new_pur = $stmt_new_pur->fetch(PDO::FETCH_ASSOC);

                    log_audit($pdo, 'update', 'invoices', $pur_data['id'], $old_pur, $new_pur, "تعديل فاتورة شراء");
                } else if (!$pur_data && $sale_data && isset($_POST['record_purchase']) && $_POST['record_purchase'] == '1') {
                    // إنشاء فاتورة شراء جديدة إذا لم تكن موجودة
                    $supplier_id = $_POST['supplier_id'];
                    $cost_amount = (float)$_POST['cost_amount'];

                    // جلب رقم فاتورة الشراء المتوقع
                    $inv_config = getServiceInvoiceConfig($sale_data['source_type'], $settings);
                    $s_pref = $inv_config['sales_prefix'];
                    $p_pref = $inv_config['purchase_prefix'];
                    $p_num = str_replace($s_pref, $p_pref, $sale_data['invoice_number']);

                    $partyAccounts = resolveInvoicePartyAccounts($pdo, 'credit', null, null, null, $supplier_id);
                    $supplier_account_id = $partyAccounts['supplier_account_id'];

                    if ($supplier_account_id) {
                        $supplier_acc_validation = validate_postable_account($pdo, $supplier_account_id);
                        if (!$supplier_acc_validation['valid']) {
                            throw new Exception($supplier_acc_validation['message']);
                        }
                    }

                    $stmt_ins_pur = $pdo->prepare("INSERT INTO invoices (
                    invoice_number, invoice_date, branch_id, invoice_category,
                    source_type, supplier_id, currency_id, total_amount, discount,
                    cost_amount, payment_type, delivery_type, account_id, supplier_account_id,
                    amount_received, description, invoice_status, created_by
                ) VALUES (?, ?, ?, 'purchase', ?, ?, ?, ?, 0, 0, 'credit', 'credit', ?, ?, 0, ?, 'draft', ?)");

                    // --- التحقق من حدود المورد عند إضافة شراء لفاتورة بيع قائمة ---
                    if ($supplier_account_id) {
                        check_account_limits($pdo, $supplier_account_id, $currency_id, $cost_amount);
                    }
                    // --- نهاية التحقق ---

                    $stmt_ins_pur->execute([$p_num, $invoice_date, $branch_id, $source_type, $supplier_id, $currency_id, $cost_amount, $supplier_account_id, $supplier_account_id, $description, $_SESSION['admin_id']]);
                }
            }

            $pdo->commit();
            header("Location: invoices.php?updated=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "خطأ في التحديث: " . $e->getMessage();
        }
    }
}

// معالجة إضافة فاتورة جديدة
if (isset($_POST['add_invoice'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        try {
            $pdo->beginTransaction();

            $invoice_date = normalize_datetime_db($_POST['invoice_date'] ?? null);

            // --- التحقق من إغلاق الفترة المالية ---
            if (is_period_closed($pdo, $invoice_date)) {
                throw new Exception("تنبيه: لا يمكن إضافة الفاتورة. التاريخ المحدد ($invoice_date) يقع ضمن فترة مالية مغلقة.");
            }
            // --- نهاية التحقق ---

            $branch_id = $_POST['branch_id'] ?? 1;
            $source_type = ($_POST['source_type'] ?? 'general') ?: 'general';
            $description = $_POST['description'] ?? '';
            $admin_id = $_SESSION['admin_id'];

            // 1. جلب حسابات الخدمة من الإعدادات
            $srv_config = getServiceInvoiceConfig($source_type, $settings);
            $revenue_acc = $srv_config['revenue_account_id'] ?? ($settings['default_sales_account_id'] ?? null);
            $cost_acc = $srv_config['cost_account_id'] ?? ($settings['default_cost_account_id'] ?? null);

            // 2. إنشاء فاتورة البيع (دائماً) باستخدام الترقيم التلقائي المعتمد في الإعدادات
            $inv_res = generateInvoiceNumber($pdo, $source_type, 'sales', $settings);
            $sale_invoice_num = $inv_res['number'];
            $invoice_numeric_part = $inv_res['numeric_part'];

            $sale_total = (float)($_POST['total_amount'] ?? 0);
            $discount = (float)($_POST['discount'] ?? 0);
            $cost_amount = (float)($_POST['cost_amount'] ?? 0);
            $sale_currency = $_POST['sale_currency_id'] ?? ($_POST['currency_id'] ?? 1);

            // معالجة فروق العملة إذا كانت عملة البيع تختلف عن عملة الشراء
            $purchase_currency = $_POST['currency_id'] ?? 1;
            $exchange_rate = (float)($_POST['exchange_rate'] ?? 1);

            // إذا كان هناك اختلاف في العملات، يجب تحويل التكلفة لعملة البيع لتسجيلها في فاتورة البيع
            $cost_in_sale_currency = $cost_amount;
            if ($sale_currency != $purchase_currency && $exchange_rate > 0) {
                $cost_in_sale_currency = $cost_amount * $exchange_rate;
            }

            $delivery_type = $_POST['delivery_type'] ?? 'draft';
            $account_id = $_POST['account_id'] ?? null;
            $received_amount = (float)($_POST['received_amount'] ?? $_POST['amount_received'] ?? 0);
            $customer_id = $_POST['customer_id'] ?: null;
            $agent_id = $_POST['agent_id'] ?: null;

            // التحقق من صحة حساب الصندوق/البنك إذا كان مُختارًا
            if ($account_id) {
                $cash_bank_validation = validate_postable_account($pdo, $account_id);
                if (!$cash_bank_validation['valid']) {
                    throw new Exception($cash_bank_validation['message']);
                }
            }

            $partyAccounts = resolveInvoicePartyAccounts($pdo, $delivery_type, $account_id, $customer_id, $agent_id, null);
            $customer_account_id = $partyAccounts['customer_account_id'];
            $agent_account_id = $partyAccounts['agent_account_id'];

            if ($customer_account_id) {
                $cust_acc_validation = validate_postable_account($pdo, $customer_account_id);
                if (!$cust_acc_validation['valid']) {
                    throw new Exception($cust_acc_validation['message']);
                }
            }

            if ($agent_account_id) {
                $agent_acc_validation = validate_postable_account($pdo, $agent_account_id);
                if (!$agent_acc_validation['valid']) {
                    throw new Exception($agent_acc_validation['message']);
                }
            }

            // --- Currency validation for Sales Invoice ---
            if ($customer_account_id) {
                // التحقق من أن هذه العملة مفعلة للعميل وليست مجمدة
                $stmt_check_frozen = $pdo->prepare("SELECT is_frozen FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
                $stmt_check_frozen->execute([$customer_account_id, $sale_currency]);
                $is_frozen = $stmt_check_frozen->fetchColumn();

                if ($is_frozen === '1') {
                    throw new Exception("هذه العملة غير مفعلة أو مجمدة لهذا العميل. يرجى اختيار عملة أخرى أو تفعيلها من صفحة إدارة العملات.");
                }

                // --- Credit/Debit Limit Check for Sales Invoice (Credit Sales Only) ---
                // Only check limits for credit sales (آجل)
                $is_credit_sale = in_array($delivery_type, ['credit', 'credit_doc']);

                if ($is_credit_sale) {
                    // استخدام الدالة الموحدة للتحقق من الحدود والرصيد
                    check_account_limits($pdo, $customer_account_id, $sale_currency, ($sale_total - $discount));
                }
                // --- End Credit/Debit Limit Check ---
            }
            // --- End Currency validation ---

            $stmt_sale = $pdo->prepare("INSERT INTO invoices (
            invoice_number, invoice_date, branch_id, invoice_category,
            source_type, source_id, customer_id, agent_id, supplier_id,
            currency_id, total_amount, discount, cost_amount, payment_type,
            delivery_type, account_id, customer_account_id, amount_received, description,
            invoice_status, created_by
        ) VALUES (?, ?, ?, 'sales', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");

            $stmt_sale->execute([
                $sale_invoice_num,
                $invoice_date,
                $branch_id,
                $source_type,
                $_POST['source_id'] ?? 0,
                $customer_id,
                $agent_id,
                $_POST['supplier_id'] ?: null,
                $sale_currency,
                $sale_total,
                $discount,
                $cost_in_sale_currency, // تم استخدام التكلفة المحولة لعملة البيع
                $_POST['payment_type'] ?? $delivery_type,
                $delivery_type,
                $account_id,
                $customer_account_id,
                $received_amount,
                $description,
                $admin_id
            ]);
            $new_sale_id = $pdo->lastInsertId();

            // جلب البيانات الجديدة للسجل
            $stmt_new_sale = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_new_sale->execute([$new_sale_id]);
            $new_sale_data = $stmt_new_sale->fetch(PDO::FETCH_ASSOC);
            log_audit($pdo, 'create', 'invoices', $new_sale_id, null, $new_sale_data, "إنشاء فاتورة بيع جديدة");

            // 2. إنشاء فاتورة الشراء (إذا تم تفعيل زر "تسجيل مديونية للمورد")
            // المورد يذهب فقط في فاتورة الشراء وليس في فاتورة البيع
            if (isset($_POST['record_purchase']) && $_POST['record_purchase'] == '1') {
                $pur_res = generateInvoiceNumber($pdo, $source_type, 'purchase', $settings, $invoice_numeric_part);
                $purchase_invoice_num = $pur_res['number'];
                $purchase_cost = (float)($_POST['cost_amount'] ?? 0);
                $purchase_currency = $_POST['currency_id'] ?? 1; // العملة الأساسية المختارة في الأعلى
                $supplier_id = $_POST['supplier_id'] ?? null;

                // Uncomment to debug
                // echo "<pre>";
                // echo "record_purchase is set to: " . ($_POST['record_purchase'] ?? 'not set') . "\n";
                // echo "supplier_id: " . var_export($supplier_id, true) . "\n";
                // echo "purchase_cost: " . var_export($purchase_cost, true) . "\n";
                // echo "</pre>";
                // exit;

                if ($supplier_id && $purchase_cost > 0) {
                    $partyAccounts = resolveInvoicePartyAccounts($pdo, 'credit', null, null, null, $supplier_id);
                    $supplier_account_id = $partyAccounts['supplier_account_id'];
                    
                    // التحقق من صحة حساب المورد
                    if ($supplier_account_id) {
                        $supplier_acc_validation = validate_postable_account($pdo, $supplier_account_id);
                        if (!$supplier_acc_validation['valid']) {
                            throw new Exception($supplier_acc_validation['message']);
                        }
                    }

                    // --- التحقق من حدود المورد عند إنشاء فاتورة شراء جديدة ---
                    if ($supplier_account_id) {
                        check_account_limits($pdo, $supplier_account_id, $purchase_currency, $purchase_cost);
                    }
                    // --- نهاية التحقق ---

                    $stmt_purchase = $pdo->prepare("INSERT INTO invoices (
                    invoice_number, invoice_date, branch_id, invoice_category,
                    source_type, source_id, supplier_id,
                    currency_id, total_amount, discount, cost_amount, payment_type,
                    delivery_type, account_id, supplier_account_id, amount_received, description,
                    invoice_status, created_by
                ) VALUES (?, ?, ?, 'purchase', ?, ?, ?, ?, ?, 0, ?, 'credit', 'credit', ?, ?, 0, ?, 'draft', ?)");

                    $stmt_purchase->execute([
                        $purchase_invoice_num,
                        $invoice_date,
                        $branch_id,
                        $source_type,
                        $_POST['source_id'] ?? 0,
                        $supplier_id,
                        $purchase_currency,
                        $purchase_cost,
                        $purchase_cost,
                        $supplier_account_id,
                        $supplier_account_id,
                        $description,
                        $admin_id
                    ]);
                    $new_pur_id = $pdo->lastInsertId();

                    // جلب البيانات الجديدة للسجل
                    $stmt_new_pur = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                    $stmt_new_pur->execute([$new_pur_id]);
                    $new_pur_data = $stmt_new_pur->fetch(PDO::FETCH_ASSOC);
                    log_audit($pdo, 'create', 'invoices', $new_pur_id, null, $new_pur_data, "إنشاء فاتورة شراء جديدة");
                }
            }

            $pdo->commit();
            header("Location: invoices.php?created=1");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "خطأ: " . $e->getMessage();
        }
    }
}

// إعادة تعيين الفاتورة لمسودة (للإصلاح) عبر POST + CSRF فقط
if (isset($_POST['invoice_action']) && $_POST['invoice_action'] === 'reset_invoice') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception("خطأ في التحقق من الطلب (CSRF).");
        }

        $id = (int)($_POST['invoice_id'] ?? 0);
        $type = $_POST['reset_type'] ?? 'sales'; // sales, purchase, all
        $linked_invoice_id = (int)($_POST['linked_invoice_id'] ?? 0);
        $user_id = $_SESSION['admin_id'];

        $pdo->beginTransaction();

        // جلب أرقام الفواتير المرتبطة
        $stmt_ids = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.invoice_category, i.source_type, i.source_id
            FROM invoices i WHERE i.id = ?
        ");
        $stmt_ids->execute([$id]);
        $row = $stmt_ids->fetch();

        $ids_to_reset = [];
        if ($row) {
            $pur_id = null;
            $sal_id = null;
            
            // الطريقة 1: البحث عن الفواتير المقابلة باستخدام الأعمدة الجديدة (sales_invoice_id, purchase_invoice_id) من جدول الخدمة
            if ($row['source_id'] && $row['source_type']) {
                $source_tables = [
                    'خدمات العمرة' => 'passports',
                    'حج وعمرة' => 'passports',
                    'umrah' => 'passports',
                    'خدمات الحج' => 'passports',
                    'hajj' => 'passports',
                    'خدمات البريد' => 'postal_shipments',
                    'postal' => 'postal_shipments',
                    'حجوزات باص وطيران' => 'bus_flight_bookings',
                    'زيارة عائلية' => 'family_visit_requests',
                    'معاملات جواز' => 'passport_transactions'
                ];
                
                if (isset($source_tables[$row['source_type']])) {
                    $table = $source_tables[$row['source_type']];
                    // إذا كانت الفاتورة الحالية مبيعات، نحصل على فاتورة الشراء من نفس الخدمة
                    if ($row['invoice_category'] == 'sales') {
                        $stmt_purchase = $pdo->prepare("SELECT purchase_invoice_id FROM {$table} WHERE sales_invoice_id = ? LIMIT 1");
                        $stmt_purchase->execute([$row['id']]);
                        $pur_id = $stmt_purchase->fetchColumn();
                    }
                    // إذا كانت الفاتورة الحالية شراء، نحصل على فاتورة البيع من نفس الخدمة
                    else {
                        $stmt_sales = $pdo->prepare("SELECT sales_invoice_id FROM {$table} WHERE purchase_invoice_id = ? LIMIT 1");
                        $stmt_sales->execute([$row['id']]);
                        $sal_id = $stmt_sales->fetchColumn();
                    }
                }
            }
            
            // الطريقة 2: إذا لم نجدها بالطريقة الجديدة، نجرب البحث برقم الفاتورة (للبيانات القديمة)
            if (!$pur_id && !$sal_id) {
                // استخراج الرقم العددي من رقم الفاتورة
                $numeric_part = preg_replace('/^[A-Z]+-/i', '', $row['invoice_number']);
                $linked_category = ($row['invoice_category'] == 'sales') ? 'purchase' : 'sales';
                
                // البحث عن الفاتورة المقابلة بنفس الرقم العددي
                $stmt_linked = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number LIKE ? AND invoice_category = ? LIMIT 1");
                $stmt_linked->execute(['%' . $numeric_part, $linked_category]);
                $linked_id = $stmt_linked->fetchColumn();
                
                if ($row['invoice_category'] == 'sales') {
                    $pur_id = $linked_id;
                } else {
                    $sal_id = $linked_id;
                }
            }
            
            if ($type == 'all') {
                $ids_to_reset[] = $row['id'];
                if ($linked_invoice_id > 0) {
                    $ids_to_reset[] = $linked_invoice_id;
                } else {
                    if ($pur_id) $ids_to_reset[] = $pur_id;
                    if ($sal_id) $ids_to_reset[] = $sal_id;
                }
            } elseif ($type == 'purchase') {
                if ($row['invoice_category'] == 'purchase') $ids_to_reset[] = $row['id'];
                elseif ($pur_id) $ids_to_reset[] = $pur_id;
            } else { // sales
                if ($row['invoice_category'] == 'sales') $ids_to_reset[] = $row['id'];
                elseif ($sal_id) $ids_to_reset[] = $sal_id;
            }
        }

        foreach ($ids_to_reset as $reset_id) {
            // جلب بيانات الفاتورة قبل التعديل للسجل
            $stmt_old = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_old->execute([$reset_id]);
            $old_invoice = $stmt_old->fetch(PDO::FETCH_ASSOC);

            // جلب رقم الفاتورة
            $inv_num = $old_invoice['invoice_number'] ?? null;

            if ($inv_num) {
                // المتابعة لإلغاء القيد الابتدائي فقط (السند التلقائي للمبلغ الواصل عند الإنشاء)
                $original_inv_num = $old_invoice['invoice_number'] ?? null;
                $numeric_inv_num = preg_replace('/[^0-9]/', '', $original_inv_num);

                $stmt_ft = $pdo->prepare("
                        SELECT id FROM financial_transactions
                        WHERE
                            (transaction_number = ? OR reference_number = ?) -- Original full invoice number
                            OR
                            (transaction_number = ? OR reference_number = ?) -- Numeric part of invoice number
                            OR
                            (reference_id = ? AND reference_type = 'invoice')
                    ");
                $stmt_ft->execute([
                    $inv_num,
                    $inv_num,
                    $numeric_inv_num,
                    $numeric_inv_num,
                    $reset_id
                ]);
                $ft_ids = $stmt_ft->fetchAll(PDO::FETCH_COLUMN);

                foreach ($ft_ids as $ft_id) {
                    // إلغاء ترحيل المعاملة المالية
                    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                    $stmt->execute([$ft_id]);
                    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($voucher && $voucher['status'] == 'posted') {
                        // أولاً: عكس تأثير القيد على الأرصدة BEFORE حذف سطور القيد
                        if (!balances_triggers_enabled($pdo)) {
                            apply_transaction_balances($pdo, (int)$ft_id, -1);
                        }
                        
                        // ثانياً: حذف سطور القيد
                        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ft_id]);
                        
                        // ثالثاً: تحديث حالة المعاملة إلى ملغي حتى لا تمنع إعادة ترحيل الفاتورة لاحقاً
                        $stmt_reset = $pdo->prepare("
                            UPDATE financial_transactions
                            SET status = 'cancelled',
                                updated_at = CURRENT_TIMESTAMP,
                                updated_by = ?
                            WHERE id = ?
                        ");
                        $stmt_reset->execute([$_SESSION['admin_id'], $ft_id]);
                        
                        // رابعاً: إعادة حساب مبالغ الفواتير المرتبطة
                        $stmt_allocs = $pdo->prepare("SELECT DISTINCT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
                        $stmt_allocs->execute([$ft_id]);
                        $invoice_ids = $stmt_allocs->fetchAll(PDO::FETCH_COLUMN);
                        
                        foreach ($invoice_ids as $inv_id) {
                            php_recalculate_invoice_payment($pdo, $inv_id);
                        }
                        
                        // خامساً: تسجيل في audit_log
                        $stmt_after = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                        $stmt_after->execute([$ft_id]);
                        $voucher_after = $stmt_after->fetch(PDO::FETCH_ASSOC);
                        log_audit($pdo, 'unpost', 'financial_transactions', $ft_id, $voucher, $voucher_after, "إلغاء ترحيل سند مرتبط بفاتورة");
                    }
                }
            }

            // تحديث حالة الفاتورة إلى مسودة
            $stmt = $pdo->prepare("UPDATE invoices SET invoice_status = 'draft', posted_at = NULL, posted_by = NULL, payment_status = 'unpaid' WHERE id = ?");
            $stmt->execute([$reset_id]);

            // جلب البيانات الجديدة للسجل
            $stmt_new = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_new->execute([$reset_id]);
            $new_invoice = $stmt_new->fetch(PDO::FETCH_ASSOC);

            // تسجيل العملية
            log_audit($pdo, 'reset_to_draft', 'invoices', $reset_id, $old_invoice, $new_invoice, "إعادة تعيين الفاتورة إلى مسودة");
        }

        $pdo->commit();

        $return_to = invoice_safe_return_to($_POST['return_to'] ?? 'invoices.php');
        $separator = (strpos($return_to, '?') === false) ? '?' : '&';
        header("Location: " . $return_to . $separator . "reset=1");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "خطأ في إعادة التعيين: " . $e->getMessage();
    }
}

// ترحيل خدمة محاسبياً عبر POST + CSRF فقط
if (isset($_POST['invoice_action']) && $_POST['invoice_action'] === 'post_invoice') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception("خطأ في التحقق من الطلب (CSRF).");
        }

        $user_id = $_SESSION['admin_id'];
        $ids_to_post = [];
        $post_scope = $_POST['post_scope'] ?? 'single';
        $linked_invoice_id = (int)($_POST['linked_invoice_id'] ?? 0);

        if ($post_scope === 'all') {
            $main_id = (int)($_POST['invoice_id'] ?? 0);
            // البحث عن الفاتورة المرتبطة (البيع والشراء)
            $stmt_all = $pdo->prepare("SELECT i.id, i.invoice_number, i.invoice_category, i.source_type FROM invoices i WHERE i.id = ?");
            $stmt_all->execute([$main_id]);
            $row_all = $stmt_all->fetch();

            if ($row_all) {
                $ids_to_post[] = $row_all['id'];

                if ($linked_invoice_id > 0) {
                    $ids_to_post[] = $linked_invoice_id;
                }

                // استخراج البادئة من رقم الفاتورة الحالية
                $current_prefix = '';
                if (preg_match('/^([A-Z]+-)/i', $row_all['invoice_number'], $matches)) {
                    $current_prefix = $matches[1];
                }
                
                // تحديد البادئة المقابلة
                if ($current_prefix) {
                    // أولاً: جلب الفواتير المرتبطة بنفس الرقم العددي
                    $numeric_part = preg_replace('/^[A-Z]+-/i', '', $row_all['invoice_number']);
                    $linked_category = ($row_all['invoice_category'] == 'sales') ? 'purchase' : 'sales';
                    
                    // البحث عن الفاتورة المقابلة بنفس الرقم العددي
                    if ($linked_invoice_id <= 0) {
                        $stmt_linked = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE invoice_number LIKE ? AND invoice_category = ? LIMIT 1");
                        $stmt_linked->execute(['%' . $numeric_part, $linked_category]);
                        $linked_row = $stmt_linked->fetch();
                        if ($linked_row) {
                            $ids_to_post[] = $linked_row['id'];
                        }
                    }
                }
            }
        } else {
            $ids_to_post[] = (int)($_POST['invoice_id'] ?? 0);
        }

        $ids_to_post = array_values(array_unique(array_filter(array_map('intval', $ids_to_post))));

        foreach ($ids_to_post as $st_id) {
            // جلب البيانات قبل الترحيل
            $stmt_before = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_before->execute([$st_id]);
            $inv_before = $stmt_before->fetch(PDO::FETCH_ASSOC);

            if (!$inv_before || $inv_before['invoice_status'] == 'posted') continue;

            // --- التحقق من الحدود قبل الترحيل ---
            if ($inv_before['invoice_category'] == 'sales' && in_array($inv_before['delivery_type'], ['credit', 'credit_doc'])) {
                if ($inv_before['customer_account_id']) {
                    check_account_limits($pdo, $inv_before['customer_account_id'], $inv_before['currency_id'], ($inv_before['total_amount'] - $inv_before['discount']));
                }
            }
            // --- نهاية التحقق ---

            // تنفيذ الترحيل باستخدام الدالة PHP الجديدة
            php_post_invoice($pdo, $st_id, $user_id);

            // جلب البيانات بعد الترحيل
            $stmt_after = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_after->execute([$st_id]);
            $inv_after = $stmt_after->fetch(PDO::FETCH_ASSOC);

            log_audit($pdo, 'post', 'invoices', $st_id, $inv_before, $inv_after, "ترحيل الفاتورة محاسبياً");
        }

        $return_to = invoice_safe_return_to($_POST['return_to'] ?? 'invoices.php');
        $separator = (strpos($return_to, '?') === false) ? '?' : '&';
        header("Location: " . $return_to . $separator . "posted=1");
        exit;
    } catch (Exception $e) {
        $error_msg = "خطأ في الترحيل: " . $e->getMessage();
    }
}

// حذف فاتورة عبر POST + CSRF فقط
if (isset($_POST['invoice_action']) && $_POST['invoice_action'] === 'delete_invoice') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception("خطأ في التحقق من الطلب (CSRF).");
        }

        $id = (int)($_POST['invoice_id'] ?? 0);
        $return_to = invoice_safe_return_to($_POST['return_to'] ?? 'invoices.php');
        $delete_scope = $_POST['delete_scope'] ?? '';
        $linked_invoice_id = (int)($_POST['linked_id'] ?? 0);
        $source_type = $_POST['source_type'] ?? '';
        $source_id = (int)($_POST['source_id'] ?? 0);

        // 1. التحقق من وجود فاتورة مرتبطة ديناميكياً بناءً على إعدادات الخدمة
        $stmt_current = $pdo->prepare("SELECT id, invoice_number, invoice_category, source_type, source_id FROM invoices WHERE id = ?");
        $stmt_current->execute([$id]);
        $current_inv = $stmt_current->fetch(PDO::FETCH_ASSOC);

        // If invoice not found by id, try by source_type and source_id
        if (!$current_inv && $source_type && $source_id) {
            // Try to find the invoice by source_type and source_id
            $stmt_current = $pdo->prepare("SELECT id, invoice_number, invoice_category, source_type, source_id FROM invoices WHERE source_type = ? AND source_id = ? AND invoice_category = ? LIMIT 1");
            // Determine invoice_category based on delete button clicked
            $category = 'sales';
            if (isset($_POST['delete_scope']) && $_POST['delete_scope'] === 'self') {
                // Check which button was clicked: if we're deleting purchase, category is purchase
                // We can guess based on source_type and which id we had
                $stmt_check_purchase = $pdo->prepare("SELECT id FROM invoices WHERE source_type = ? AND source_id = ? AND invoice_category = 'purchase' LIMIT 1");
                $stmt_check_purchase->execute([$source_type, $source_id]);
                $purchase_inv = $stmt_check_purchase->fetchColumn();
                if ($purchase_inv && $linked_invoice_id === 0) {
                    $category = 'purchase';
                }
            }
            $stmt_current->execute([$source_type, $source_id, $category]);
            $current_inv = $stmt_current->fetch(PDO::FETCH_ASSOC);
            if ($current_inv) {
                $id = $current_inv['id'];
            }
        }

        if (!$current_inv) {
            $error_msg = "الفاتورة غير موجودة.";
        } else {
            $inv_config = getServiceInvoiceConfig($current_inv['source_type'], $settings);
            $s_pref = $inv_config['sales_prefix'];
            $p_pref = $inv_config['purchase_prefix'];

            $linked_num = ($current_inv['invoice_category'] == 'sales')
                ? str_replace($s_pref, $p_pref, $current_inv['invoice_number'])
                : str_replace($p_pref, $s_pref, $current_inv['invoice_number']);

            $stmt_linked = $pdo->prepare("SELECT id, invoice_number, invoice_category FROM invoices WHERE invoice_number = ? LIMIT 1");
            $stmt_linked->execute([$linked_num]);
            $linked_inv = $stmt_linked->fetch(PDO::FETCH_ASSOC);

            if ($linked_inv && $delete_scope === '') {
                $self_label = ($current_inv['invoice_category'] === 'sales') ? 'حذف فاتورة البيع فقط' : 'حذف فاتورة الشراء فقط';
                $linked_label = ($current_inv['invoice_category'] === 'sales') ? 'حذف فاتورة الشراء فقط' : 'حذف فاتورة البيع فقط';
                $error_msg = "تنبيه: هذه الفاتورة مرتبطة بالفاتورة (" . $linked_inv['invoice_number'] . "). هل تريد حذفها فقط أم حذفهما معاً؟ <br><br>"
                    . "<div class='d-flex flex-wrap gap-2'>"
                    . "<form method='post' class='d-inline-block mb-0'>"
                    . csrf_input()
                    . "<input type='hidden' name='invoice_action' value='delete_invoice'>"
                    . "<input type='hidden' name='invoice_id' value='" . (int)$id . "'>"
                    . "<input type='hidden' name='delete_scope' value='self'>"
                    . "<input type='hidden' name='return_to' value='" . htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') . "'>"
                    . "<button type='submit' class='btn btn-sm btn-danger rounded-pill'>$self_label</button>"
                    . "</form>"
                    . "<form method='post' class='d-inline-block mb-0'>"
                    . csrf_input()
                    . "<input type='hidden' name='invoice_action' value='delete_invoice'>"
                    . "<input type='hidden' name='invoice_id' value='" . (int)$id . "'>"
                    . "<input type='hidden' name='delete_scope' value='linked_only'>"
                    . "<input type='hidden' name='linked_id' value='" . (int)$linked_inv['id'] . "'>"
                    . "<input type='hidden' name='return_to' value='" . htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') . "'>"
                    . "<button type='submit' class='btn btn-sm btn-warning rounded-pill'>$linked_label</button>"
                    . "</form>"
                    . "<form method='post' class='d-inline-block mb-0'>"
                    . csrf_input()
                    . "<input type='hidden' name='invoice_action' value='delete_invoice'>"
                    . "<input type='hidden' name='invoice_id' value='" . (int)$id . "'>"
                    . "<input type='hidden' name='delete_scope' value='both'>"
                    . "<input type='hidden' name='linked_id' value='" . (int)$linked_inv['id'] . "'>"
                    . "<input type='hidden' name='return_to' value='" . htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') . "'>"
                    . "<button type='submit' class='btn btn-sm btn-dark rounded-pill'>حذف الكل</button>"
                    . "</form>"
                    . "</div>";
            } else {
                $ids_to_delete = [];

                if ($delete_scope === 'both') {
                    $ids_to_delete[] = $id;
                    $ids_to_delete[] = $linked_invoice_id ?: (int)($linked_inv['id'] ?? 0);
                } elseif ($delete_scope === 'linked_only') {
                    $ids_to_delete[] = $linked_invoice_id ?: (int)($linked_inv['id'] ?? 0);
                } else {
                    $ids_to_delete[] = $id;
                }

                $ids_to_delete = array_values(array_unique(array_filter($ids_to_delete)));
                if (empty($ids_to_delete)) {
                    $error_msg = "لم يتم تحديد فاتورة للحذف.";
                } else {
                    $booking_ids_to_delete = [];
                    $stmt_invoice_source = $pdo->prepare("SELECT source_type, source_id FROM invoices WHERE id = ?");
                    $stmt_booking_inv = $pdo->prepare("SELECT id FROM invoices WHERE source_type IN ($booking_service_source_placeholders) AND source_id = ?");

                    foreach ($ids_to_delete as $candidate_invoice_id) {
                        $stmt_invoice_source->execute([$candidate_invoice_id]);
                        $invoice_source = $stmt_invoice_source->fetch(PDO::FETCH_ASSOC);
                        $source_type = trim((string)($invoice_source['source_type'] ?? ''));
                        $source_id = (int)($invoice_source['source_id'] ?? 0);

                        if ($source_id > 0 && in_array($source_type, $booking_service_source_aliases, true)) {
                            $booking_ids_to_delete[] = $source_id;
                        }
                    }

                    $booking_ids_to_delete = array_values(array_unique(array_filter($booking_ids_to_delete)));
                    foreach ($booking_ids_to_delete as $booking_id_to_delete) {
                        $stmt_booking_inv->execute(array_merge($booking_service_source_aliases, [$booking_id_to_delete]));
                        $booking_invoice_ids = $stmt_booking_inv->fetchAll(PDO::FETCH_COLUMN);
                        $ids_to_delete = array_values(array_unique(array_merge($ids_to_delete, array_map('intval', $booking_invoice_ids))));
                    }

                    // 2. تحقق قبل الحذف: لا يوجد سداد مرتبط + الحالة مسودة أو ملغية
                    foreach ($ids_to_delete as $del_id) {
                        // التحقق من وجود أي سدادات خارجية (ليست القيد الابتدائي للفاتورة)
                        $stmt_vouchers = $pdo->prepare("
                            SELECT ft.id, ft.transaction_number, ft.transaction_type
                            FROM payment_allocations pa
                            JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                            WHERE pa.invoice_id = ?
                            AND ft.status = 'posted'
                            AND NOT (ft.reference_id = ? AND ft.reference_type = 'invoice')
                        ");
                        $stmt_vouchers->execute([$del_id, $del_id]);
                        $external_vouchers = $stmt_vouchers->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($external_vouchers)) {
                            $links = [];
                            foreach ($external_vouchers as $v) {
                                $v_num = $v['transaction_number'];
                                $v_id = $v['id'];
                                $v_type = $v['transaction_type'];
                                $target_page = ($v_type == 'receipt' || substr($v_num, 0, 3) == 'RCT') ? 'receipts.php' : 'payments.php';
                                $links[] = "<a href='$target_page' class='fw-bold text-danger' target='_blank'>$v_num</a>";
                            }
                            $v_list = implode('، ', $links);
                            $error_msg = "لا يمكن الحذف: توجد سندات خارجية مرحلة مرتبطة بهذه الفاتورة: ($v_list). يرجى إلغاء ترحيل هذه السندات أولاً.";
                            break;
                        }

                        $stmt_check = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ?");
                        $stmt_check->execute([$del_id]);
                        $status = $stmt_check->fetchColumn();
                        if (!in_array($status, ['draft', 'cancelled'], true)) {
                            $error_msg = "لا يمكن حذف الفاتورة إلا إذا كانت مسودة أو ملغية.";
                            break;
                        }
                    }

                    if (empty($error_msg)) {
                        $pdo->beginTransaction();
                        
                        try {
                            $stmt_before_delete = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                            $stmt_del = $pdo->prepare("DELETE FROM invoices WHERE id = ?");

                            // إضافة مصفوفة لتتبع معاملات العمرة التي يجب حذفها لتجنب التكرار
                            $umrah_ids_to_delete = [];
                            $family_visit_ids_to_delete = [];
                            $passport_transaction_ids_to_delete = [];

                            foreach ($ids_to_delete as $del_id) {
                                $stmt_before_delete->execute([$del_id]);
                                $old_invoice = $stmt_before_delete->fetch(PDO::FETCH_ASSOC);

                                if ($old_invoice) {
                                    // --- الحذف الكامل لكل ما يتعلق بالفاتورة ---
                                    $inv_num = $old_invoice['invoice_number'] ?? null;
                                    if ($inv_num) {
                                        $numeric_inv_num = preg_replace('/[^0-9]/', '', $inv_num);

                                        // 1. إيجاد كل المعاملات المالية المرتبطة
                                        $stmt_ft = $pdo->prepare("
                                                SELECT id FROM financial_transactions
                                                WHERE
                                                    (transaction_number = ? OR reference_number = ?) -- Original full invoice number
                                                    OR
                                                    (transaction_number = ? OR reference_number = ?) -- Numeric part of invoice number
                                                    OR
                                                    (reference_id = ? AND reference_type = 'invoice')
                                            ");
                                        $stmt_ft->execute([
                                            $inv_num,
                                            $inv_num,
                                            $numeric_inv_num,
                                            $numeric_inv_num,
                                            $del_id
                                        ]);
                                        $ft_ids = $stmt_ft->fetchAll(PDO::FETCH_COLUMN);

                                        foreach ($ft_ids as $ft_id) {
                                            // أولاً: إلغاء الترحيل وتصحيح الأرصدة إن كانت مرحلة
                                            $stmt_ft_check = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                                            $stmt_ft_check->execute([$ft_id]);
                                            $voucher = $stmt_ft_check->fetch(PDO::FETCH_ASSOC);
                                            
                                            if ($voucher && $voucher['status'] == 'posted' && !balances_triggers_enabled($pdo)) {
                                                apply_transaction_balances($pdo, (int)$ft_id, -1);
                                            }
                                            
                                            // 2. حذف تخصيصات المدفوعات
                                            $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$ft_id]);
                                            
                                            // 3. حذف خطوط الدفتر اليومي
                                            $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ft_id]);
                                            
                                            // 4. حذف المعاملة المالية نفسها
                                            $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$ft_id]);
                                        }
                                    }
                                    // --- نهاية الحذف الكامل ---

                                    // إذا كانت الفاتورة مرتبطة بمعاملة عمرة، نضيف معرف المعاملة للمصفوفة
                                    if ((is_umrah_service($old_invoice['source_type'] ?? '') || is_hajj_service($old_invoice['source_type'] ?? '')) && !empty($old_invoice['source_id'])) {
                                        $umrah_ids_to_delete[] = $old_invoice['source_id'];
                                    }
                                    if (in_array($old_invoice['source_type'] ?? '', $family_visit_source_aliases, true) && !empty($old_invoice['source_id'])) {
                                        $family_visit_ids_to_delete[] = $old_invoice['source_id'];
                                    }
                                    if (in_array($old_invoice['source_type'] ?? '', $passport_transaction_source_aliases, true) && !empty($old_invoice['source_id'])) {
                                        $passport_transaction_ids_to_delete[] = $old_invoice['source_id'];
                                    }

                                    $stmt_del->execute([$del_id]);
                                    log_audit($pdo, 'delete', 'invoices', $del_id, $old_invoice, null, 'حذف فاتورة');
                                }
                            }

                            // حذف معاملات العمرة المرتبطة
                            if (!empty($umrah_ids_to_delete)) {
                                $umrah_ids_to_delete = array_unique($umrah_ids_to_delete);
                                foreach ($umrah_ids_to_delete as $passport_id) {
                                    // 1. حذف تفاصيل العمرة
                                    $pdo->prepare("DELETE FROM umrah_details WHERE passport_id = ?")->execute([$passport_id]);

                                    // 2. حذف أي فواتير أخرى مرتبطة بنفس المعاملة لم يتم تحديدها للحذف بعد
                                    $stmt_other_inv = $pdo->prepare("SELECT id FROM invoices WHERE source_type IN ($passport_service_source_placeholders) AND source_id = ?");
                                    $stmt_other_inv->execute(array_merge($passport_service_source_aliases, [$passport_id]));
                                    $other_inv_ids = $stmt_other_inv->fetchAll(PDO::FETCH_COLUMN);

                                    foreach ($other_inv_ids as $other_id) {
                                        if (!in_array($other_id, $ids_to_delete)) {
                                            $stmt_before_delete->execute([$other_id]);
                                            $other_inv_data = $stmt_before_delete->fetch(PDO::FETCH_ASSOC);
                                            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$other_id]);
                                            if ($other_inv_data) {
                                                log_audit($pdo, 'delete', 'invoices', $other_id, $other_inv_data, null, 'حذف فاتورة مرتبطة بمعاملة عمره محذوفة');
                                            }
                                        }
                                    }

                                    // 3. حذف المعاملة (جواز السفر)
                                    $pdo->prepare("DELETE FROM passports WHERE id = ?")->execute([$passport_id]);
                                    log_audit($pdo, 'delete', 'passports', $passport_id, null, null, 'حذف معاملة عمره بالكامل بسبب حذف الفاتورة');
                                }
                            }

                            if (!empty($booking_ids_to_delete)) {
                                $booking_ids_to_delete = array_unique($booking_ids_to_delete);
                                $stmt_booking_before_delete = $pdo->prepare("SELECT * FROM bus_flight_bookings WHERE id = ?");
                                foreach ($booking_ids_to_delete as $booking_id_to_delete) {
                                    $stmt_booking_before_delete->execute([$booking_id_to_delete]);
                                    $booking_before = $stmt_booking_before_delete->fetch(PDO::FETCH_ASSOC);

                                    $pdo->prepare("DELETE FROM workflow_approval_requests WHERE booking_id = ?")->execute([$booking_id_to_delete]);
                                    $pdo->prepare("DELETE FROM bus_flight_bookings WHERE id = ?")->execute([$booking_id_to_delete]);

                                    log_audit($pdo, 'delete', 'bus_flight_bookings', $booking_id_to_delete, $booking_before ?: null, null, 'حذف الحجز بالكامل بسبب حذف الفاتورة المرتبطة');
                                }
                            }

                            // حذف معاملات زيارة عائلية المرتبطة
                            if (!empty($family_visit_ids_to_delete)) {
                                $family_visit_ids_to_delete = array_unique($family_visit_ids_to_delete);
                                $stmt_family_visit_before_delete = $pdo->prepare("SELECT * FROM family_visit_requests WHERE id = ?");
                                foreach ($family_visit_ids_to_delete as $family_visit_id) {
                                    $stmt_family_visit_before_delete->execute([$family_visit_id]);
                                    $family_visit_before = $stmt_family_visit_before_delete->fetch(PDO::FETCH_ASSOC);

                                    // 1. حذف الأفراد
                                    $pdo->prepare("DELETE FROM family_visit_individuals WHERE request_id = ?")->execute([$family_visit_id]);

                                    // 2. حذف أي فواتير أخرى مرتبطة بنفس المعاملة لم يتم تحديدها للحذف بعد
                                    $stmt_other_inv = $pdo->prepare("SELECT id FROM invoices WHERE source_type IN ($family_visit_source_placeholders) AND source_id = ?");
                                    $stmt_other_inv->execute(array_merge($family_visit_source_aliases, [$family_visit_id]));
                                    $other_inv_ids = $stmt_other_inv->fetchAll(PDO::FETCH_COLUMN);

                                    foreach ($other_inv_ids as $other_id) {
                                        if (!in_array($other_id, $ids_to_delete)) {
                                            $stmt_before_delete->execute([$other_id]);
                                            $other_inv_data = $stmt_before_delete->fetch(PDO::FETCH_ASSOC);
                                            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$other_id]);
                                            if ($other_inv_data) {
                                                log_audit($pdo, 'delete', 'invoices', $other_id, $other_inv_data, null, 'حذف فاتورة مرتبطة بمعاملة زيارة عائلية محذوفة');
                                            }
                                        }
                                    }

                                    // 3. حذف المعاملة الرئيسية
                                    $pdo->prepare("DELETE FROM family_visit_requests WHERE id = ?")->execute([$family_visit_id]);
                                    log_audit($pdo, 'delete', 'family_visit_requests', $family_visit_id, $family_visit_before ?: null, null, 'حذف معاملة زيارة عائلية بالكامل بسبب حذف الفاتورة');
                                }
                            }

                            // حذف معاملات جواز السفر المرتبطة
                            if (!empty($passport_transaction_ids_to_delete)) {
                                $passport_transaction_ids_to_delete = array_unique($passport_transaction_ids_to_delete);
                                $stmt_passport_transaction_before_delete = $pdo->prepare("SELECT * FROM passport_transactions WHERE id = ?");
                                foreach ($passport_transaction_ids_to_delete as $passport_transaction_id) {
                                    $stmt_passport_transaction_before_delete->execute([$passport_transaction_id]);
                                    $passport_transaction_before = $stmt_passport_transaction_before_delete->fetch(PDO::FETCH_ASSOC);

                                    // 1. حذف أي فواتير أخرى مرتبطة بنفس المعاملة لم يتم تحديدها للحذف بعد
                                    $stmt_other_inv = $pdo->prepare("SELECT id FROM invoices WHERE source_type IN ($passport_transaction_source_placeholders) AND source_id = ?");
                                    $stmt_other_inv->execute(array_merge($passport_transaction_source_aliases, [$passport_transaction_id]));
                                    $other_inv_ids = $stmt_other_inv->fetchAll(PDO::FETCH_COLUMN);

                                    foreach ($other_inv_ids as $other_id) {
                                        if (!in_array($other_id, $ids_to_delete)) {
                                            $stmt_before_delete->execute([$other_id]);
                                            $other_inv_data = $stmt_before_delete->fetch(PDO::FETCH_ASSOC);
                                            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$other_id]);
                                            if ($other_inv_data) {
                                                log_audit($pdo, 'delete', 'invoices', $other_id, $other_inv_data, null, 'حذف فاتورة مرتبطة بمعاملة جواز سحر محذوفة');
                                            }
                                        }
                                    }

                                    // 2. حذف المعاملة الرئيسية
                                    $pdo->prepare("DELETE FROM passport_transactions WHERE id = ?")->execute([$passport_transaction_id]);
                                    log_audit($pdo, 'delete', 'passport_transactions', $passport_transaction_id, $passport_transaction_before ?: null, null, 'حذف معاملة جواز سحر بالكامل بسبب حذف الفاتورة');
                                }
                            }

                            $pdo->commit();
                            $success_msg = "تم حذف الفاتورة وكل ما يتعلق بها بنجاح.";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error_msg = "خطأ في الحذف: " . $e->getMessage();
                        }

                        // حذف معاملات العمرة المرتبطة
                        if (!empty($umrah_ids_to_delete)) {
                            $umrah_ids_to_delete = array_unique($umrah_ids_to_delete);
                            foreach ($umrah_ids_to_delete as $passport_id) {
                                // 1. حذف تفاصيل العمرة
                                $pdo->prepare("DELETE FROM umrah_details WHERE passport_id = ?")->execute([$passport_id]);

                                // 2. حذف أي فواتير أخرى مرتبطة بنفس المعاملة لم يتم تحديدها للحذف بعد
                                $stmt_other_inv = $pdo->prepare("SELECT id FROM invoices WHERE source_type IN ($passport_service_source_placeholders) AND source_id = ?");
                                $stmt_other_inv->execute(array_merge($passport_service_source_aliases, [$passport_id]));
                                $other_inv_ids = $stmt_other_inv->fetchAll(PDO::FETCH_COLUMN);

                                foreach ($other_inv_ids as $other_id) {
                                    // حذف الفاتورة الأخرى إذا لم تكن في القائمة الأصلية المحذوفة
                                    if (!in_array($other_id, $ids_to_delete)) {
                                        $stmt_before_delete->execute([$other_id]);
                                        $other_inv_data = $stmt_before_delete->fetch(PDO::FETCH_ASSOC);
                                        $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$other_id]);
                                        if ($other_inv_data) {
                                            log_audit($pdo, 'delete', 'invoices', $other_id, $other_inv_data, null, 'حذف فاتورة مرتبطة بمعاملة عمرة محذوفة');
                                        }
                                    }
                                }

                                // 3. حذف المعاملة (جواز السفر)
                                $pdo->prepare("DELETE FROM passports WHERE id = ?")->execute([$passport_id]);
                                log_audit($pdo, 'delete', 'passports', $passport_id, null, null, 'حذف معاملة عمره بالكامل بسبب حذف الفاتورة');
                            }
                        }

                        $separator = (strpos($return_to, '?') === false) ? '?' : '&';
                        header("Location: " . $return_to . $separator . "deleted=1");
                        exit;
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error_msg = "خطأ في الحذف: " . $e->getMessage();
    }
}

require_once 'header.php';

// فلترة الفواتير
$where = "WHERE 1=1";
$params = [];

if (!empty($_GET['from_date'])) {
    $where .= " AND i.invoice_date >= ?";
    $params[] = normalize_date_filter_start($_GET['from_date']);
}
if (!empty($_GET['to_date'])) {
    $where .= " AND i.invoice_date <= ?";
    $params[] = normalize_date_filter_end($_GET['to_date']);
}
if (!empty($_GET['invoice_category'])) {
    $where .= " AND i.invoice_category = ?";
    $params[] = $_GET['invoice_category'];
}
if (!empty($_GET['branch_id'])) {
    $where .= " AND i.branch_id = ?";
    $params[] = $_GET['branch_id'];
}
if (!empty($_GET['currency_filter'])) {
    $where .= " AND i.currency_id = ?";
    $params[] = $_GET['currency_filter'];
}
if (!empty($_GET['status'])) {
    $where .= " AND i.invoice_status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['q'])) {
    $search = "%" . $_GET['q'] . "%";
    $where .= " AND (i.invoice_number LIKE ? OR i.description LIKE ?)";
    $params[] = $search;
    $params[] = $search;
}
if (!empty($_GET['service_type'])) {
    $selected_service_type = trim((string)$_GET['service_type']);
    if (is_umrah_service($selected_service_type)) {
        $where .= " AND i.source_type IN ($umrah_source_placeholders)";
        $params = array_merge($params, $umrah_source_aliases);
    } elseif (is_hajj_service($selected_service_type)) {
        $where .= " AND i.source_type IN ($hajj_source_placeholders)";
        $params = array_merge($params, $hajj_source_aliases);
    } elseif (is_postal_service($selected_service_type)) {
        $where .= " AND i.source_type IN ($postal_source_placeholders)";
        $params = array_merge($params, $postal_source_aliases);
    } else {
        $where .= " AND i.source_type = ?";
        $params[] = $selected_service_type;
    }
}

// جلب إعدادات الترقيم العامة للاستعلام
$def_s_pref = $settings['sales_invoice_prefix'] ?? 'SAL-';
$def_p_pref = $settings['purchase_invoice_prefix'] ?? 'PUR-';

// تجهيز الاستعلام الرئيسي لدمج الفواتير بطريقة ذكية
$query = "SELECT
            -- بيانات فاتورة البيع
            CASE WHEN i.invoice_category = 'sales' THEN i.id ELSE NULL END as sales_id,
            CASE WHEN i.invoice_category = 'sales' THEN i.invoice_number ELSE NULL END as sales_number,
            CASE WHEN i.invoice_category = 'sales' THEN i.total_amount ELSE 0 END as sales_amount,
            CASE WHEN i.invoice_category = 'sales' THEN i.discount ELSE 0 END as sales_discount,
            CASE WHEN i.invoice_category = 'sales' THEN i.invoice_status ELSE NULL END as sales_status,
            -- حساب المبلغ المستلم (الابتدائي من القيد + أي تحصيلات لاحقة)
            CASE WHEN i.invoice_category = 'sales' THEN (
                IFNULL((
                    SELECT SUM(jl.debit)
                    FROM journal_lines jl
                    JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                    WHERE ft_i.reference_id = i.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                    AND jl.account_id IN (
                        SELECT id FROM unified_accounts
                        WHERE account_type IN ('box', 'bank')
                    )
                ), 0) +
                IFNULL((
                    SELECT SUM(pa.allocated_amount)
                FROM payment_allocations pa
                JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                WHERE pa.invoice_id = i.id AND ft.status = 'posted'
                    AND ft.id NOT IN (
                        SELECT id FROM financial_transactions
                        WHERE reference_id = i.id AND reference_type = 'invoice'
                    )
                ), 0)
            ) ELSE 0 END as sales_received,
            CASE WHEN i.invoice_category = 'sales' THEN i.account_id ELSE NULL END as sales_account_id,
            CASE WHEN i.invoice_category = 'sales' THEN i.delivery_type ELSE NULL END as sales_delivery_type,

            -- حقول الهوية للأطراف (مهمة لدالة getPartyName)
            i.account_id,
            i.customer_id,
            i.agent_id,

            -- بيانات فاتورة الشراء (سواء كانت مدمجة أو مستقلة)
            CASE WHEN i.invoice_category = 'purchase' THEN i.id ELSE pur.id END as purchase_id,
            CASE WHEN i.invoice_category = 'purchase' THEN i.invoice_number ELSE pur.invoice_number END as purchase_number,
            CASE WHEN i.invoice_category = 'purchase' THEN i.total_amount ELSE pur.total_amount END as purchase_amount,
            CASE WHEN i.invoice_category = 'purchase' THEN i.invoice_status ELSE pur.invoice_status END as purchase_status,
            -- حساب المبلغ المسدد للمورد (الابتدائي من القيد + أي مدفوعات لاحقة)
            CASE
                WHEN i.invoice_category = 'purchase' THEN (
                    IFNULL((
                        SELECT SUM(jl_p.credit)
                        FROM journal_lines jl_p
                        JOIN financial_transactions ft_ip ON jl_p.financial_transaction_id = ft_ip.id
                        WHERE ft_ip.reference_id = i.id AND ft_ip.reference_type = 'invoice' AND ft_ip.status = 'posted'
                        AND jl_p.account_id IN (
                            SELECT id FROM unified_accounts
                            WHERE account_type IN ('box', 'bank')
                        )
                    ), 0) +
                    IFNULL((
                        SELECT SUM(pa_p.allocated_amount)
                        FROM payment_allocations pa_p
                        JOIN financial_transactions ft_p ON pa_p.financial_transaction_id = ft_p.id
                        WHERE pa_p.invoice_id = i.id AND ft_p.status = 'posted'
                        AND ft_p.id NOT IN (
                            SELECT id FROM financial_transactions
                            WHERE reference_id = i.id AND reference_type = 'invoice'
                        )
                    ), 0)
                )
                WHEN pur.id IS NOT NULL THEN (
                    IFNULL((
                        SELECT SUM(jl_p2.credit)
                        FROM journal_lines jl_p2
                        JOIN financial_transactions ft_ip2 ON jl_p2.financial_transaction_id = ft_ip2.id
                        WHERE ft_ip2.reference_id = pur.id AND ft_ip2.reference_type = 'invoice' AND ft_ip2.status = 'posted'
                        AND jl_p2.account_id IN (
                            SELECT id FROM unified_accounts
                            WHERE account_type IN ('box', 'bank')
                        )
                    ), 0) +
                    IFNULL((
                        SELECT SUM(pa_p.allocated_amount)
                        FROM payment_allocations pa_p
                        JOIN financial_transactions ft_p ON pa_p.financial_transaction_id = ft_p.id
                        WHERE pa_p.invoice_id = pur.id AND ft_p.status = 'posted'
                        AND ft_p.id NOT IN (
                            SELECT id FROM financial_transactions
                            WHERE reference_id = pur.id AND reference_type = 'invoice'
                        )
                    ), 0)
                )
                ELSE 0
            END as purchase_received,
            CASE WHEN i.invoice_category = 'purchase' THEN i.supplier_id ELSE COALESCE(pur.supplier_id, i.supplier_id) END as supplier_id,

            -- بيانات عامة
            i.invoice_date,
            i.invoice_category,
            i.source_type,
            i.source_id,
            i.description,
            i.amount_received as sales_amount_received,
            i.cost_amount as sales_cost_field,
            i.currency_id,
            pur.currency_id as purchase_currency_id,
            i.supplier_id as direct_supplier_id,
            b.branch_name,
            c.currency_symbol,
            c.exchange_rate_buy as sales_rate,
            cpur.exchange_rate_buy as pur_rate,

            -- الربح والخسارة المحتسب (بناءً على تحويل التكلفة لعملة البيع)
            (CASE WHEN i.invoice_category = 'sales' THEN (i.total_amount - i.discount) ELSE 0 END -
             COALESCE(
                CASE WHEN i.invoice_category = 'purchase' THEN
                    (i.total_amount * IFNULL(cpur.exchange_rate_buy, 1) / IFNULL(c.exchange_rate_buy, 1))
                ELSE
                    (pur.total_amount * IFNULL(cpur.exchange_rate_buy, 1) / IFNULL(c.exchange_rate_buy, 1))
                END,
                i.cost_amount, 0)
            ) as profit_loss

          FROM invoices i
          LEFT JOIN branches b ON i.branch_id = b.id
          LEFT JOIN currencies c ON i.currency_id = c.id

          -- ربط فاتورة الشراء المقابلة بطريقة ذكية
          LEFT JOIN invoices pur ON (
              (pur.source_type = i.source_type AND pur.source_id = i.source_id AND pur.source_id != 0 AND pur.source_id IS NOT NULL AND pur.invoice_category = 'purchase' AND i.invoice_category = 'sales')
              OR
              (
                i.invoice_category = 'sales' AND pur.invoice_category = 'purchase'
                AND i.source_type = pur.source_type
                AND SUBSTRING_INDEX(i.invoice_number, '-', -1) = SUBSTRING_INDEX(pur.invoice_number, '-', -1)
                AND i.source_id = 0 AND pur.source_id = 0
              )
          )
          LEFT JOIN currencies cpur ON (CASE WHEN i.invoice_category = 'purchase' THEN i.currency_id ELSE pur.currency_id END) = cpur.id

          $where AND (
              -- 1. فواتير البيع (تظهر دائماً، وإذا ارتبطت بشراء تدمج في نفس الصف)
              i.invoice_category = 'sales'
              OR
              -- 2. فواتير الشراء المستقلة (التي ليس لها فاتورة بيع مرتبطة)
              (i.invoice_category = 'purchase' AND NOT EXISTS (
                  SELECT 1 FROM invoices s
                  WHERE s.invoice_category = 'sales'
                  AND s.source_type = i.source_type
                  AND (
                      (s.source_id = i.source_id AND i.source_id != 0 AND i.source_id IS NOT NULL)
                      OR
                      (i.source_id = 0 AND s.source_id = 0 AND SUBSTRING_INDEX(s.invoice_number, '-', -1) = SUBSTRING_INDEX(i.invoice_number, '-', -1))
                  )
              ))
          )
          ORDER BY i.invoice_date DESC, i.id DESC";

// جلب البيانات للقوائم
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell FROM currencies ORDER BY is_default DESC, currency_name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, supplier_name, account_id FROM suppliers WHERE deleted_at IS NULL ORDER BY supplier_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC")->fetchAll();

// جلب حسابات فروق العملة
$exchange_gain_id = get_setting('exchange_gain_account_id');
$exchange_loss_id = get_setting('exchange_loss_account_id');
$exchange_gain_name = "";
$exchange_loss_name = "";

if ($exchange_gain_id) {
    $stmt_gain = $pdo->prepare("SELECT account_name_ar FROM unified_accounts WHERE id = ?");
    $stmt_gain->execute([$exchange_gain_id]);
    $exchange_gain_name = $stmt_gain->fetchColumn();
}
if ($exchange_loss_id) {
    $stmt_loss = $pdo->prepare("SELECT account_name_ar FROM unified_accounts WHERE id = ?");
    $stmt_loss->execute([$exchange_loss_id]);
    $exchange_loss_name = $stmt_loss->fetchColumn();
}

$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC")->fetchAll();

$allowed_per_page = [5, 25, 50, 100, 200];
$per_page = (int)($_GET['per_page'] ?? 50);
if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 50;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$total_invoices = 0;
$total_pages = 1;

try {
    $count_query = preg_replace(
        '/\s+ORDER BY i\.invoice_date DESC, i\.id DESC;?\s*$/',
        '',
        $query
    );
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$count_query}) AS invoice_rows");
    $count_stmt->execute($params);
    $total_invoices = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_invoices / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $paged_query = rtrim($query, " ;\r\n") . " LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($paged_query);
    foreach ($params as $index => $param) {
        $stmt->bindValue($index + 1, $param);
    }
    $stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'invoices') !== false) {
        echo "<div class='alert alert-warning border-0 shadow-sm rounded-4 p-4 mb-4'>";
        echo "<h4 class='fw-bold text-warning'><i class='fas fa-exclamation-triangle me-2'></i> تنبيه: النظام المالي الموحد غير مفعل</h4>";
        echo "<p>يرجى التأكد من تحديث قاعدة البيانات لتفعيل نظام الفواتير الموحد.</p>";
        echo "</div>";
        $invoices = [];
    } else {
        throw $e;
    }
}
$party_name_maps = getPartyNameMaps($pdo, $invoices);
$pagination_query = $_GET;
$pagination_query['per_page'] = $per_page;
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
        --apple-radius-sm: 12px;
        --apple-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        --apple-shadow-hover: 0 10px 40px rgba(0, 0, 0, 0.12);
        --transition-base: all 0.3s cubic-bezier(0.25, 0.1, 0.25, 1);
    }

    body {
        background-color: #f5f5f7;
        font-family: 'Inter', -apple-system, sans-serif;
        color: #1d1d1f;
        direction: rtl;
    }

    .apple-container {
        width: 100%;
        max-width: 1600px;
        margin: 0 auto;
        padding: 20px;
    }

    .apple-card {
        background: #ffffff;
        border-radius: 20px;
        border: 1px solid rgba(0, 0, 0, 0.05);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
        overflow: hidden;
    }

    .apple-header {
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .apple-title {
        font-weight: 700;
        margin: 0;
    }

    .btn-apple-primary {
        background: #007aff;
        color: white;
        border: none;
        padding: 10px 25px;
        border-radius: 40px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        text-decoration: none;
    }

    .apple-input {
        background: #f5f5f7 !important;
        border: 1px solid transparent !important;
        border-radius: 12px !important;
        padding: 10px 15px !important;
    }

    .apple-table {
        width: 100%;
        border-collapse: collapse;
    }

    /* Edit Modal Specific Styles */
    .edit-modal-header {
        background-color: #0dcaf0 !important;
        color: white;
    }

    .edit-modal-footer {
        background-color: #f8f9fa;
    }

    /* Fix for action buttons */
    .btn-light.rounded-circle {
        width: 32px !important;
        height: 32px !important;
        padding: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border: 1px solid #dee2e6 !important;
    }

    .btn-light.rounded-circle i {
        font-size: 0.85rem;
    }

    /* Make modal body scrollable and footer fixed */
    .modal-dialog {
        max-height: 98vh;
    }
    .modal-content {
        max-height: 98vh;
        display: flex;
        flex-direction: column;
    }
    .modal-header {
        flex-shrink: 0;
    }
    .modal-body {
        flex-grow: 1;
        overflow-y: auto;
    }
    .modal-footer {
        flex-shrink: 0;
        z-index: 10;
    }
    
    /* Make save buttons very clear and visible */
    .modal-footer button[type="submit"] {
        background-color: #007aff !important;
        color: white !important;
        border: none !important;
        padding: 12px 30px !important;
        font-weight: 700 !important;
        font-size: 16px !important;
        box-shadow: 0 4px 12px rgba(0, 122, 255, 0.4) !important;
        display: inline-block !important;
    }
    
    /* Make new invoice save button extra prominent */
    .modal-footer button[name="add_invoice"] {
        padding: 18px 40px !important;
        font-size: 20px !important;
        box-shadow: 0 8px 20px rgba(0, 122, 255, 0.5) !important;
        border-radius: 12px !important;
    }
    
    /* Ensure edit modal's save button uses the same blue color */
    .modal-footer button[name="update_invoice"] {
        background-color: #007aff !important;
    }

    #newInvoiceModal .modal-dialog,
    #editInvoiceModal .modal-dialog {
        max-width: min(1380px, calc(100vw - 1.5rem));
        margin: 0.75rem auto;
    }

    #newInvoiceModal .modal-content,
    #editInvoiceModal .modal-content {
        min-height: 0;
        height: calc(100vh - 1.5rem);
        max-height: calc(100vh - 1.5rem);
        overflow: hidden;
        border-radius: 1.5rem !important;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    #newInvoiceModal form,
    #editInvoiceModal form {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
    }

    #newInvoiceModal .modal-body,
    #editInvoiceModal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
        padding-bottom: 1rem !important;
    }

    #newInvoiceModal .modal-header,
    #editInvoiceModal .modal-header {
        flex-shrink: 0;
        padding: 1rem 1.5rem;
    }

    #newInvoiceModal .modal-footer,
    #editInvoiceModal .modal-footer {
        flex-shrink: 0;
        position: sticky;
        bottom: 0;
        z-index: 8;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.75rem;
        flex-wrap: wrap;
        padding: 0.9rem 1.25rem !important;
        border-top: 1px solid rgba(203, 213, 225, 0.9) !important;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.94) 0%, rgba(248, 250, 252, 0.98) 100%) !important;
        box-shadow: 0 -10px 24px rgba(15, 23, 42, 0.08);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    #newInvoiceModal .modal-footer .btn,
    #editInvoiceModal .modal-footer .btn {
        min-width: 150px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        white-space: nowrap;
        border-radius: 999px !important;
    }

    #newInvoiceModal .modal-footer .btn-outline-secondary,
    #editInvoiceModal .modal-footer .btn-outline-secondary {
        border-color: #cbd5e1;
        color: #475569;
        background: rgba(255, 255, 255, 0.78);
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
    }

    #newInvoiceModal .modal-footer .btn-outline-secondary:hover,
    #editInvoiceModal .modal-footer .btn-outline-secondary:hover {
        background: #eef2f7;
        color: #334155;
        border-color: #94a3b8;
    }

    #newInvoiceModal .modal-footer button[name="add_invoice"],
    #editInvoiceModal .modal-footer button[name="update_invoice"] {
        min-width: 210px;
        box-shadow: 0 10px 22px rgba(0, 122, 255, 0.28) !important;
    }

    #newInvoiceModal .modal-footer button[name="add_invoice"] {
        padding: 0.95rem 2rem !important;
        font-size: 1.02rem !important;
        border-radius: 999px !important;
    }

    #editInvoiceModal .modal-footer button[name="update_invoice"] {
        padding: 0.85rem 1.75rem !important;
        font-size: 1rem !important;
        border-radius: 999px !important;
    }

    @media (max-width: 767.98px) {
        #newInvoiceModal .modal-dialog,
        #editInvoiceModal .modal-dialog {
            max-width: calc(100vw - 0.75rem);
            margin: 0.375rem auto;
        }

        #newInvoiceModal .modal-content,
        #editInvoiceModal .modal-content {
            height: calc(100vh - 0.75rem);
            max-height: calc(100vh - 0.75rem);
        }

        #newInvoiceModal .modal-footer,
        #editInvoiceModal .modal-footer {
            justify-content: stretch;
        }

        #newInvoiceModal .modal-footer .btn,
        #editInvoiceModal .modal-footer .btn {
            width: 100%;
            min-width: 0;
        }
    }

    .invoice-alert-stack {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
    }

    .invoice-page-alert {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem 3.25rem 1rem 1rem;
        overflow: hidden;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid transparent;
    }

    .invoice-page-alert::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: currentColor;
        opacity: 0.9;
    }

    .invoice-page-alert .btn-close {
        position: absolute;
        top: 1rem;
        left: 1rem;
        opacity: 0.75;
    }

    .invoice-page-alert-icon {
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1.05rem;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.35);
    }

    .invoice-page-alert-content {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        min-width: 0;
    }

    .invoice-page-alert-title {
        font-weight: 800;
        font-size: 0.98rem;
        line-height: 1.35;
    }

    .invoice-page-alert-text {
        font-size: 0.88rem;
        line-height: 1.65;
        opacity: 0.9;
    }

    .invoice-page-alert-success {
        color: #166534;
        background: linear-gradient(135deg, rgba(236, 253, 245, 0.98) 0%, rgba(220, 252, 231, 0.95) 100%);
        border-color: rgba(34, 197, 94, 0.18);
    }

    .invoice-page-alert-success .invoice-page-alert-icon {
        color: #15803d;
        background: rgba(34, 197, 94, 0.14);
    }

    .invoice-page-alert-info {
        color: #1d4ed8;
        background: linear-gradient(135deg, rgba(239, 246, 255, 0.98) 0%, rgba(219, 234, 254, 0.95) 100%);
        border-color: rgba(59, 130, 246, 0.2);
    }

    .invoice-page-alert-info .invoice-page-alert-icon {
        color: #2563eb;
        background: rgba(59, 130, 246, 0.14);
    }

    .invoice-page-alert-warning {
        color: #9a3412;
        background: linear-gradient(135deg, rgba(255, 247, 237, 0.98) 0%, rgba(255, 237, 213, 0.96) 100%);
        border-color: rgba(249, 115, 22, 0.2);
    }

    .invoice-page-alert-warning .invoice-page-alert-icon {
        color: #ea580c;
        background: rgba(249, 115, 22, 0.14);
    }

    .invoice-page-alert-danger {
        color: #991b1b;
        background: linear-gradient(135deg, rgba(254, 242, 242, 0.98) 0%, rgba(254, 226, 226, 0.95) 100%);
        border-color: rgba(239, 68, 68, 0.18);
    }

    .invoice-page-alert-danger .invoice-page-alert-icon {
        color: #dc2626;
        background: rgba(239, 68, 68, 0.14);
    }

    body.theme-dark .invoice-page-alert,
    body.dark-mode .invoice-page-alert {
        box-shadow: 0 18px 40px rgba(2, 6, 23, 0.28), inset 0 1px 0 rgba(255,255,255,0.03);
    }

    body.theme-dark .invoice-page-alert .btn-close,
    body.dark-mode .invoice-page-alert .btn-close {
        filter: invert(1) grayscale(1);
    }

    body.theme-dark .invoice-page-alert-success,
    body.dark-mode .invoice-page-alert-success {
        color: #bbf7d0;
        background: linear-gradient(135deg, rgba(20, 83, 45, 0.88) 0%, rgba(21, 128, 61, 0.18) 100%);
        border-color: rgba(34, 197, 94, 0.2);
    }

    body.theme-dark .invoice-page-alert-success .invoice-page-alert-icon,
    body.dark-mode .invoice-page-alert-success .invoice-page-alert-icon {
        color: #86efac;
        background: rgba(34, 197, 94, 0.16);
    }

    body.theme-dark .invoice-page-alert-info,
    body.dark-mode .invoice-page-alert-info {
        color: #bfdbfe;
        background: linear-gradient(135deg, rgba(30, 64, 175, 0.28) 0%, rgba(15, 23, 42, 0.92) 100%);
        border-color: rgba(96, 165, 250, 0.18);
    }

    body.theme-dark .invoice-page-alert-info .invoice-page-alert-icon,
    body.dark-mode .invoice-page-alert-info .invoice-page-alert-icon {
        color: #93c5fd;
        background: rgba(59, 130, 246, 0.18);
    }

    body.theme-dark .invoice-page-alert-warning,
    body.dark-mode .invoice-page-alert-warning {
        color: #fdba74;
        background: linear-gradient(135deg, rgba(124, 45, 18, 0.4) 0%, rgba(15, 23, 42, 0.92) 100%);
        border-color: rgba(251, 146, 60, 0.2);
    }

    body.theme-dark .invoice-page-alert-warning .invoice-page-alert-icon,
    body.dark-mode .invoice-page-alert-warning .invoice-page-alert-icon {
        color: #fb923c;
        background: rgba(249, 115, 22, 0.18);
    }

    body.theme-dark .invoice-page-alert-danger,
    body.dark-mode .invoice-page-alert-danger {
        color: #fecaca;
        background: linear-gradient(135deg, rgba(127, 29, 29, 0.42) 0%, rgba(15, 23, 42, 0.92) 100%);
        border-color: rgba(248, 113, 113, 0.18);
    }

    body.theme-dark .invoice-page-alert-danger .invoice-page-alert-icon,
    body.dark-mode .invoice-page-alert-danger .invoice-page-alert-icon {
        color: #fca5a5;
        background: rgba(239, 68, 68, 0.18);
    }

    body.theme-dark #newInvoiceModal .modal-content,
    body.theme-dark #editInvoiceModal .modal-content,
    body.dark-mode #newInvoiceModal .modal-content,
    body.dark-mode #editInvoiceModal .modal-content {
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.98) 0%, rgba(17, 24, 39, 0.98) 100%);
        border: 1px solid rgba(71, 85, 105, 0.55);
        box-shadow: 0 22px 50px rgba(2, 6, 23, 0.45);
    }

    body.theme-dark #newInvoiceModal .modal-footer,
    body.theme-dark #editInvoiceModal .modal-footer,
    body.dark-mode #newInvoiceModal .modal-footer,
    body.dark-mode #editInvoiceModal .modal-footer {
        background: linear-gradient(180deg, rgba(30, 41, 59, 0.92) 0%, rgba(15, 23, 42, 0.98) 100%) !important;
        border-top-color: rgba(71, 85, 105, 0.85) !important;
        box-shadow: 0 -12px 28px rgba(2, 6, 23, 0.36);
    }

    body.theme-dark #newInvoiceModal .modal-footer .btn-outline-secondary,
    body.theme-dark #editInvoiceModal .modal-footer .btn-outline-secondary,
    body.dark-mode #newInvoiceModal .modal-footer .btn-outline-secondary,
    body.dark-mode #editInvoiceModal .modal-footer .btn-outline-secondary {
        background: rgba(30, 41, 59, 0.92);
        border-color: #475569;
        color: #e2e8f0;
        box-shadow: 0 10px 22px rgba(2, 6, 23, 0.22);
    }

    body.theme-dark #newInvoiceModal .modal-footer .btn-outline-secondary:hover,
    body.theme-dark #editInvoiceModal .modal-footer .btn-outline-secondary:hover,
    body.dark-mode #newInvoiceModal .modal-footer .btn-outline-secondary:hover,
    body.dark-mode #editInvoiceModal .modal-footer .btn-outline-secondary:hover {
        background: #334155;
        border-color: #64748b;
        color: #f8fafc;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1 fw-bold">الفواتير الموحدة</h1>
            <p class="text-muted mb-0 small">إدارة شاملة لكافة الخدمات المالية</p>
        </div>
        <div class="d-flex gap-2">

            <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm fw-600" data-bs-toggle="modal" data-bs-target="#newInvoiceModal">
                <i class="fas fa-plus me-2"></i> إضافة فاتورة جديدة
            </button>
            <a href="financial_hub.php" class="btn btn-light rounded-pill px-4 border shadow-sm fw-600">
                <i class="fas fa-chart-pie me-2"></i> المركز المالي
            </a>
        </div>
    </div>

    <!-- البحث المتقدم -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">بحث عام</label>
                    <input type="text" name="q" class="form-control" value="<?php echo h($_GET['q'] ?? ''); ?>" placeholder="رقم، بيان...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control" value="<?php echo h($_GET['from_date'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control" value="<?php echo h($_GET['to_date'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">نوع الفاتورة</label>
                    <select name="invoice_category" class="form-select">
                        <option value="">الكل</option>
                        <option value="sales" <?php echo (h($_GET['invoice_category'] ?? '')) == 'sales' ? 'selected' : ''; ?>>بيع</option>
                        <option value="purchase" <?php echo (h($_GET['invoice_category'] ?? '')) == 'purchase' ? 'selected' : ''; ?>>شراء</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">الخدمة</label>
                    <select name="service_type" class="form-select">
                        <option value="">كل الخدمات</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?php echo h($s['service_name']); ?>" <?php echo (h($_GET['service_type'] ?? '')) == $s['service_name'] ? 'selected' : ''; ?>><?php echo h($s['service_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">الفرع</label>
                    <select name="branch_id" class="form-select">
                        <option value="">كل الفروع</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo h($b['id']); ?>" <?php echo (h($_GET['branch_id'] ?? '')) == $b['id'] ? 'selected' : ''; ?>><?php echo h($b['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">الحالة</label>
                    <select name="status" class="form-select">
                        <option value="">كل الحالات</option>
                        <option value="draft" <?php echo (h($_GET['status'] ?? '')) == 'draft' ? 'selected' : ''; ?>>مسودة</option>
                        <option value="posted" <?php echo (h($_GET['status'] ?? '')) == 'posted' ? 'selected' : ''; ?>>مرحل</option>
                        <option value="cancelled" <?php echo (h($_GET['status'] ?? '')) == 'cancelled' ? 'selected' : ''; ?>>ملغي</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">العملة</label>
                    <select name="currency_filter" class="form-select">
                        <option value="">كل العملات</option>
                        <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo h($curr['id']); ?>" <?php echo (h($_GET['currency_filter'] ?? '')) == $curr['id'] ? 'selected' : ''; ?>><?php echo h($curr['currency_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> بحث</button>
                    <a href="invoices.php" class="btn btn-outline-secondary"><i class="fas fa-sync"></i></a>
                </div>
            </form>
        </div>
    </div>

    <?php
    $page_alerts = [];
    if (isset($_GET['created']) && $_GET['created'] == 1) {
        $page_alerts[] = [
            'type' => 'success',
            'icon' => 'fa-file-circle-check',
            'title' => 'تم الإنشاء بنجاح',
            'message' => 'تم إنشاء الفواتير بنجاح.'
        ];
    }
    if (isset($_GET['updated']) && $_GET['updated'] == 1) {
        $page_alerts[] = [
            'type' => 'success',
            'icon' => 'fa-pen-to-square',
            'title' => 'تم التحديث بنجاح',
            'message' => 'تم تحديث الفاتورة بنجاح.'
        ];
    }
    if (isset($_GET['reset']) && $_GET['reset'] == 1) {
        $page_alerts[] = [
            'type' => 'info',
            'icon' => 'fa-rotate-left',
            'title' => 'تمت الإعادة إلى المسودة',
            'message' => 'تمت إعادة ضبط الفاتورة بنجاح.'
        ];
    }
    if ($success_msg) {
        $page_alerts[] = [
            'type' => 'success',
            'icon' => 'fa-circle-check',
            'title' => 'تم تنفيذ العملية',
            'message' => $success_msg
        ];
    }
    if ($error_msg) {
        $page_alerts[] = [
            'type' => 'danger',
            'icon' => 'fa-circle-xmark',
            'title' => 'تعذر تنفيذ العملية',
            'message' => $error_msg
        ];
    }
    ?>
    <?php if (!empty($page_alerts)): ?>
        <div class="invoice-alert-stack mb-4">
            <?php foreach ($page_alerts as $alert): ?>
                <div class="invoice-page-alert invoice-page-alert-<?php echo htmlspecialchars($alert['type']); ?> alert alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
                    <div class="invoice-page-alert-icon">
                        <i class="fas <?php echo htmlspecialchars($alert['icon']); ?>"></i>
                    </div>
                    <div class="invoice-page-alert-content">
                        <div class="invoice-page-alert-title"><?php echo htmlspecialchars($alert['title']); ?></div>
                        <div class="invoice-page-alert-text"><?php echo $alert['message']; ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- الجدول -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">رقم العملية</th>
                        <th>التاريخ</th>
                        <th>الخدمة</th>
                        <th>العميل</th>
                        <th>المورد</th>
                        <th class="text-end">سعر البيع</th>
                        <th class="text-end text-danger">التكلفة</th>
                        <th class="text-end text-success">الربح</th>
                        <th class="text-center">الحالة</th>
                        <th class="pe-4 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv):
                        $is_purchase_only = ($inv['sales_number'] === null);
                        $display_number = $is_purchase_only ? $inv['purchase_number'] : $inv['sales_number'];
                    ?>
                        <tr>
                            <td class="ps-4">
                                <span class="fw-bold text-primary"><?php echo preg_replace('/^[A-Z-]+/', '', $display_number); ?></span>
                                <div class="extra-small text-muted mt-1"><?php echo $inv['branch_name']; ?></div>
                            </td>
                            <td><small><?php echo h(format_datetime_display($inv['invoice_date'])); ?></small></td>
                            <td>
                                <?php
                                    $service_name = normalize_service_display_name($inv['source_type'] ?? '');
                                ?>
                                <span class="badge-apple bg-primary-subtle text-primary"><?php echo htmlspecialchars($service_name); ?></span>
                            </td>
                            <td>
                                <?php $cust_name = getPartyName($pdo, $inv, $party_name_maps); ?>
                                <div class="fw-bold small"><?php echo h($cust_name); ?></div>
                                <?php if (!$is_purchase_only && $inv['sales_status'] == 'posted'):
                                    $s_total = round((float)$inv['sales_amount'] - (float)$inv['sales_discount'], 2);
                                    $s_received = round((float)$inv['sales_received'], 2);
                                    if ($s_received >= $s_total && $s_total > 0): ?>
                                        <div class="extra-small text-success mt-1"><i class="fas fa-check-circle me-1"></i>مدفوع</div>
                                    <?php elseif ($s_received > 0): ?>
                                        <div class="extra-small text-info mt-1"><i class="fas fa-adjust me-1"></i>مدفوع جزئياً</div>
                                    <?php else: ?>
                                        <div class="extra-small text-danger mt-1"><i class="fas fa-clock me-1"></i>غير مدفوع</div>
                                <?php endif;
                                endif; ?>
                            </td>
                            <td>
                                <?php
                                $sup_name = "---";
                                $target_supplier_id = $inv['purchase_id'] ? $inv['supplier_id'] : ($inv['direct_supplier_id'] ?? null);
                                if ($target_supplier_id) {
                                    $stmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
                                    $stmt->execute([$target_supplier_id]);
                                    $sup_name = $stmt->fetchColumn();
                                }
                                ?>
                                <div class="fw-bold small"><?php echo $sup_name; ?></div>
                                <?php if ($inv['purchase_id'] && $inv['purchase_status'] == 'posted'):
                                    $p_total = round((float)$inv['purchase_amount'], 2);
                                    $p_received = round((float)$inv['purchase_received'], 2);
                                    if ($p_received >= $p_total && $p_total > 0): ?>
                                        <div class="extra-small text-success mt-1"><i class="fas fa-check-circle me-1"></i>مسدد</div>
                                    <?php elseif ($p_received > 0): ?>
                                        <div class="extra-small text-info mt-1"><i class="fas fa-adjust me-1"></i>مسدد جزئياً</div>
                                    <?php else: ?>
                                        <div class="extra-small text-danger mt-1"><i class="fas fa-clock me-1"></i>غير مسدد</div>
                                <?php endif;
                                endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="fw-bold text-dark small" title="الإجمالي قبل الخصم">
                                    <?php echo number_format($is_purchase_only ? 0 : $inv['sales_amount'], 2); ?> <small><?php echo $inv['currency_symbol']; ?></small>
                                </div>
                                <?php if (!$is_purchase_only && $inv['sales_discount'] > 0): ?>
                                    <div class="extra-small text-danger" title="مبلغ الخصم">
                                        خصم: <?php echo number_format($inv['sales_discount'], 2); ?>
                                    </div>
                                    <div class="fw-bold text-primary mt-1 border-top pt-1" title="الصافي بعد الخصم">
                                        <?php echo number_format($inv['sales_amount'] - $inv['sales_discount'], 2); ?> <small><?php echo $inv['currency_symbol']; ?></small>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-danger fw-600">
                                <?php
                                $p_amt = $inv['purchase_id'] ? $inv['purchase_amount'] : $inv['sales_cost_field'];
                                echo number_format($p_amt, 2); ?> <small><?php echo $inv['currency_symbol']; ?></small>
                            </td>
                            <td class="text-end text-success fw-bold"><?php echo number_format($inv['profit_loss'], 2); ?> <small><?php echo $inv['currency_symbol']; ?></small></td>
                            <td class="text-center">
                                <div class="d-flex flex-column gap-1 align-items-center">
                                    <?php if (!$is_purchase_only): ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <?php
                                            $s_status_class = 'bg-warning text-dark';
                                            $s_status_text = 'مسودة';
                                            if ($inv['sales_status'] == 'posted') {
                                                $s_status_class = 'bg-success';
                                                $s_status_text = 'مرحل';
                                            } elseif ($inv['sales_status'] == 'cancelled') {
                                                $s_status_class = 'bg-danger';
                                                $s_status_text = 'ملغي';
                                            }
                                            ?>
                                            <span class="badge <?php echo $s_status_class; ?> extra-small">البيع: <?php echo $s_status_text; ?></span>
                                            <?php
                                            $s_total = round((float)$inv['sales_amount'] - (float)$inv['sales_discount'], 2);
                                            $s_received = round((float)$inv['sales_received'], 2);
                                            if ($inv['sales_status'] == 'posted'):
                                                if ($s_received >= $s_total && $s_total > 0): ?>
                                                    <span class="badge bg-success rounded-circle p-1" title="مدفوع بالكامل"><i class="fas fa-check fs-xs"></i></span>
                                                <?php elseif ($s_received > 0): ?>
                                                    <span class="badge bg-info rounded-circle p-1" title="مدفوع جزئياً"><i class="fas fa-adjust fs-xs"></i></span>
                                            <?php endif;
                                            endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($inv['purchase_id']): ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <?php
                                            $p_status_class = 'bg-warning text-dark';
                                            $p_status_text = 'مسودة';
                                            if ($inv['purchase_status'] == 'posted') {
                                                $p_status_class = 'bg-success';
                                                $p_status_text = 'مرحل';
                                            } elseif ($inv['purchase_status'] == 'cancelled') {
                                                $p_status_class = 'bg-danger';
                                                $p_status_text = 'ملغي';
                                            }
                                            ?>
                                            <span class="badge <?php echo $p_status_class; ?> extra-small">الشراء: <?php echo $p_status_text; ?></span>
                                            <?php
                                            $p_total = round((float)$inv['purchase_amount'], 2);
                                            $p_received = round((float)$inv['purchase_received'], 2);
                                            if ($inv['purchase_status'] == 'posted'):
                                                if ($p_received >= $p_total && $p_total > 0): ?>
                                                    <!-- Removed icon -->
                                                <?php elseif ($p_received > 0): ?>
                                                    <!-- Removed icon -->
                                            <?php endif;
                                            endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="pe-4 text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <?php $main_id = $is_purchase_only ? $inv['purchase_id'] : $inv['sales_id']; ?>
                                    <a href="invoice_details.php?id=<?php echo $main_id; ?>" class="btn btn-sm btn-light rounded-circle shadow-sm" title="عرض التفاصيل"><i class="fas fa-eye text-primary"></i></a>

                                    <?php if (!$is_purchase_only): ?>
                                        <?php if ($inv['sales_status'] == 'draft' || $inv['sales_status'] == 'cancelled'): ?>
                                            <button type="button" class="btn btn-sm btn-light rounded-circle shadow-sm" data-bs-toggle="modal" data-bs-target="#editInvoiceModal" onclick='loadInvoiceData(<?php echo json_encode($inv); ?>)' title="تعديل"><i class="fas fa-edit text-info"></i></button>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- زر الترحيل (قائمة منسدلة) -->
                                    <?php if (($inv['sales_status'] != 'posted' && !$is_purchase_only) || ($inv['purchase_id'] && $inv['purchase_status'] != 'posted')): ?>
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ترحيل">
                                                <i class="fas fa-check-double text-success"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                <li>
                                                    <h6 class="dropdown-header small fw-bold">ترحيل محاسبياً (Post)</h6>
                                                </li>
                                                <?php if ($inv['sales_status'] != 'posted' && $inv['purchase_id'] && $inv['purchase_status'] != 'posted'): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد الترحيل المالي" data-confirm-text="هل تريد ترحيل فواتير البيع والشراء معاً؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="post_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $main_id; ?>">
                                                            <input type="hidden" name="post_scope" value="all">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-check-double me-2 text-success"></i>ترحيل الكل</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                <?php endif; ?>

                                                <?php if ($inv['sales_status'] != 'posted' && !$is_purchase_only): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد ترحيل فاتورة البيع" data-confirm-text="هل تريد ترحيل فاتورة البيع؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="post_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['sales_id']; ?>">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>ترحيل البيع</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if ($inv['purchase_id'] && $inv['purchase_status'] != 'posted'): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد ترحيل فاتورة الشراء" data-confirm-text="هل تريد ترحيل فاتورة الشراء؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="post_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['purchase_id']; ?>">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-file-invoice me-2 text-warning"></i>ترحيل الشراء</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <!-- زر الحذف (قائمة منسدلة) -->
                                    <?php if (($inv['sales_status'] != 'posted' && !$is_purchase_only) || ($inv['purchase_id'] && $inv['purchase_status'] != 'posted')): ?>
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="حذف">
                                                <i class="fas fa-trash text-danger"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                <li>
                                                    <h6 class="dropdown-header small fw-bold">حذف الفاتورة (Delete)</h6>
                                                </li>
                                                <?php if ($inv['sales_status'] != 'posted' && $inv['purchase_id'] && $inv['purchase_status'] != 'posted'): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف الفواتير" data-confirm-text="سيتم حذف فواتير البيع والشراء معاً وكل ما يرتبط بها. هل تريد المتابعة؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="delete_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['sales_id']; ?>">
                                                            <input type="hidden" name="delete_scope" value="both">
                                                            <input type="hidden" name="linked_id" value="<?php echo $inv['purchase_id']; ?>">
                                                            <button type="submit" class="dropdown-item py-2 small text-danger"><i class="fas fa-trash-alt me-2"></i>حذف الكل</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                <?php endif; ?>

                                                <?php if ($inv['sales_status'] != 'posted' && !$is_purchase_only): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف فاتورة البيع" data-confirm-text="هل تريد حذف فاتورة البيع؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="delete_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['sales_id']; ?>">
                                                            <input type="hidden" name="delete_scope" value="self">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-trash me-2"></i>حذف البيع</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if ($inv['purchase_id'] && $inv['purchase_status'] != 'posted'): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف فاتورة الشراء" data-confirm-text="هل تريد حذف فاتورة الشراء؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="delete_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['purchase_id']; ?>">
                                                            <input type="hidden" name="delete_scope" value="self">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-trash me-2 text-warning"></i>حذف الشراء</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <!-- زر إلغاء الترحيل (قائمة منسدلة) -->
                                    <?php if (($inv['sales_status'] == 'posted') || ($inv['purchase_id'] && $inv['purchase_status'] == 'posted')): ?>
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="إلغاء الترحيل">
                                                <i class="fas fa-undo text-warning"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                <li>
                                                    <h6 class="dropdown-header small fw-bold">إعادة التعيين إلى مسودة (Reset)</h6>
                                                </li>
                                                <?php if ($inv['sales_status'] == 'posted' && $inv['purchase_id'] && $inv['purchase_status'] == 'posted'): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء الترحيل" data-confirm-text="سيتم إرجاع فواتير البيع والشراء إلى مسودة. هل تريد المتابعة؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="reset_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['sales_id']; ?>">
                                                            <input type="hidden" name="reset_type" value="all">
                                                            <input type="hidden" name="linked_invoice_id" value="<?php echo $inv['purchase_id']; ?>">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-sync me-2 text-danger"></i>إلغاء ترحيل الكل</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                <?php endif; ?>

                                                <?php if ($inv['sales_status'] == 'posted'): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء ترحيل البيع" data-confirm-text="سيتم إرجاع فاتورة البيع إلى مسودة. هل تريد المتابعة؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="reset_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['sales_id']; ?>">
                                                            <input type="hidden" name="reset_type" value="sales">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-undo me-2 text-warning"></i>إلغاء ترحيل البيع</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if ($inv['purchase_id'] && $inv['purchase_status'] == 'posted'): ?>
                                                    <li>
                                                        <form method="post" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء ترحيل الشراء" data-confirm-text="سيتم إرجاع فاتورة الشراء إلى مسودة. هل تريد المتابعة؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="invoice_action" value="reset_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $inv['purchase_id']; ?>">
                                                            <input type="hidden" name="reset_type" value="purchase">
                                                            <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-history me-2 text-secondary"></i>إلغاء ترحيل الشراء</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pagination_url = function ($target_page) use ($pagination_query) {
    $query = $pagination_query;
    $query['page'] = $target_page;
    return 'invoices.php?' . http_build_query($query);
};
$pager_start = max(1, $page - 2);
$pager_end = min($total_pages, $page + 2);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-4" dir="rtl">
    <div class="text-muted small">
        إجمالي الفواتير: <?php echo h(number_format($total_invoices)); ?>
        <span class="mx-1">·</span>
        الصفحة <?php echo h($page); ?> من <?php echo h($total_pages); ?>
    </div>
    <?php if ($total_pages > 1): ?>
        <nav aria-label="صفحات الفواتير">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo h($pagination_url(max(1, $page - 1))); ?>">السابق</a>
                </li>
                <?php for ($pager_page = $pager_start; $pager_page <= $pager_end; $pager_page++): ?>
                    <li class="page-item <?php echo $pager_page === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo h($pagination_url($pager_page)); ?>"><?php echo h($pager_page); ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo h($pagination_url(min($total_pages, $page + 1))); ?>">التالي</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- مودال تعديل الفاتورة -->
<div class="modal fade" id="editInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header edit-modal-header border-bottom-0 pt-4 px-4 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>تعديل الفاتورة الحالية</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editInvoiceForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="invoice_id" id="edit_invoice_id">
                <input type="hidden" name="customer_id" id="edit_customer_id">
                <input type="hidden" name="agent_id" id="edit_agent_id">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><label class="form-label small fw-bold text-muted">تاريخ الفاتورة</label><input type="datetime-local" name="invoice_date" id="edit_invoice_date" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label small fw-bold text-muted">الفرع المسؤول</label><select name="branch_id" id="edit_branch_id" class="form-select" required><?php foreach ($branches as $b): ?><option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><label class="form-label small fw-bold text-muted">نوع الخدمة</label><select name="source_type" id="edit_service_id_select" class="form-select select2-modal-edit">
                                <option value="general">عام (General)</option><?php foreach ($services as $s): ?><option value="<?php echo $s['service_name']; ?>" data-id="<?php echo $s['id']; ?>"><?php echo $s['service_name']; ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-3"><label class="form-label small fw-bold text-muted">نوع التوصيل</label><select name="delivery_type" id="edit_delivery_type" class="form-select" required>
                                <option value="draft">📝 مسودة</option>
                                <option value="cash">💵 نقد</option>
                                <option value="credit">📅 آجل</option>
                                <option value="bank_transfer">🏦 تحويل بنكي</option>
                                <option value="agent">👤 وكيل</option>
                            </select></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted" id="edit_account_label">الحساب المتأثر</label>
                            <select name="account_id" id="edit_account_select" class="form-select select2-modal-edit" required disabled>
                                <option value="">-- اختر نوع التوصيل أولاً --</option>
                            </select>
                            <!-- منطقة عرض الرصيد والحد الائتماني (تعديل) -->
                            <div id="edit_account_balance_info" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted"><i class="fas fa-wallet me-1"></i> صافي الرصيد الموحد:</span>
                                    <span id="edit_unified_balance_display" class="fw-bold"></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الائتماني:</span>
                                    <span id="edit_unified_limit_display" class="fw-bold text-danger"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">المورد</label>
                            <select name="supplier_id" id="edit_supplier_id" class="form-select select2-modal-edit">
                                <option value="">-- اختر المورد --</option>
                                <?php foreach ($suppliers_with_codes as $s): ?>
                                    <option value="<?php echo $s['supplier_id']; ?>" data-account="<?php echo $s['id']; ?>"><?php echo $s['display_name']; ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- التحكم في قيد فاتورة الشراء بناءً على إعدادات "إنشاء الفواتير تلقائياً" (تعديل) -->
                            <?php if (isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true)): ?>
                                <input type="hidden" name="record_purchase" id="edit_record_purchase" value="1">
                            <?php else: ?>
                                <div class="mt-2 p-2 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25">
                                    <label class="form-label extra-small fw-bold text-primary mb-1"><i class="fas fa-question-circle me-1"></i> هل تريد إنشاء فاتورة شراء للمورد؟</label>
                                    <select name="record_purchase" id="edit_record_purchase" class="form-select form-select-sm border-primary" required>
                                        <option value="" disabled>-- يجب الاختيار --</option>
                                        <option value="1">نعم، تسجيل مديونية</option>
                                        <option value="0">لا، مبيعات فقط</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <!-- منطقة عرض رصيد المورد والحد الدائن (تعديل) -->
                            <div id="edit_supplier_balance_info" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted"><i class="fas fa-wallet me-1"></i> رصيد المكتب عند المورد:</span>
                                    <span id="edit_supplier_unified_balance_display" class="fw-bold"></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الدائن المسموح:</span>
                                    <span id="edit_supplier_unified_limit_display" class="fw-bold text-success"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3 p-3 bg-light rounded-4 border">
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-primary">إجمالي سعر البيع</label>
                            <input type="number" step="0.01" name="total_amount" id="edit_total_amount" class="form-control fw-bold text-primary" required data-original-price="0" data-service-currency-id="">
                            <div id="edit_sales_exchange_info" class="extra-small text-muted mt-1" style="display: none;"></div>
                        </div>
                        <div class="col-md-2" id="edit_received_amount_field" style="display: none;"><label class="form-label small fw-bold text-muted">المبلغ الواصل</label><input type="number" step="0.01" name="amount_received" id="edit_amount_received" class="form-control"></div>
                        <div class="col-md-2"><label class="form-label small fw-bold text-danger">مبلغ الخصم</label><input type="number" step="0.01" name="discount" id="edit_discount" class="form-control" data-original-discount="0"></div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted">عملة البيع</label>
                            <select name="sale_currency_id" id="edit_sale_currency_id" class="form-select">
                                <?php foreach ($currencies as $curr): ?>
                                    <option value="<?php echo $curr['id']; ?>" data-symbol="<?php echo $curr['currency_symbol']; ?>" data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>" data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>" data-rate="<?php echo $curr['exchange_rate'] ?? 1; ?>"><?php echo $curr['currency_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="form-label small fw-bold text-warning">سعر التكلفة</label><input type="number" step="0.01" name="cost_amount" id="edit_cost_amount" class="form-control fw-bold text-warning" data-original-cost="0" data-cost-service-currency-id=""></div>
                        <div class="col-md-2"><label class="form-label small fw-bold text-muted">عملة التكلفة</label><select name="currency_id" id="edit_main_currency_id" class="form-select"><?php foreach ($currencies as $curr): ?><option value="<?php echo $curr['id']; ?>" data-symbol="<?php echo $curr['currency_symbol']; ?>" data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>" data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>" data-rate="<?php echo $curr['exchange_rate'] ?? 1; ?>"><?php echo $curr['currency_name']; ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="row g-3 mb-3" id="edit_exchange_rate_container" style="display: none;">
                        <div class="col-md-8">
                            <div class="p-3 bg-white border border-dashed rounded-4">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-6"><label class="form-label small fw-bold text-muted" id="edit_exchange_rate_label">سعر الصرف</label>
                                        <div class="input-group"><span class="input-group-text">1 <span class="edit-pur-symbol"></span> =</span><input type="number" step="0.000001" name="exchange_rate" id="edit_invoice_exchange_rate" class="form-control text-center fw-bold" value="1.000000"><span class="input-group-text"><span class="edit-sale-symbol"></span></span></div>
                                    </div>
                                    <div class="col-md-6"><label class="form-label small fw-bold text-muted">التكلفة المعادلة</label><input type="text" id="edit_equivalent_cost_display" class="form-control bg-light" readonly></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- حسابات الخدمة (تعديل) -->
                    <div class="row g-3 mb-3 p-3 bg-light rounded-4 border border-dashed">
                        <div class="col-12 mb-3">
                            <h6 class="fw-bold text-primary"><i class="fas fa-bookkeeping me-2"></i> حسابات الخدمة</h6>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-success">حساب الإيرادات</label>
                            <input type="text" id="edit_service_revenue_account" class="form-control bg-white" readonly value="" placeholder="لا تختار نوع الخدمة أولاً">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-danger">حساب التكلفة</label>
                            <input type="text" id="edit_service_cost_account" class="form-control bg-white" readonly value="" placeholder="لا تختار نوع الخدمة أولاً">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-warning">حساب الأرباح</label>
                            <input type="text" id="edit_service_profit_account" class="form-control bg-white" readonly value="" placeholder="لا تختار نوع الخدمة أولاً">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label small fw-bold text-muted">البيان / الوصف</label><textarea name="description" id="edit_description" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer edit-modal-footer invoice-modal-footer border-top-0 p-4 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4 py-2" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_invoice" class="btn btn-info text-white px-5 py-2 shadow fw-bold">تحديث الفاتورة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: New Invoice -->
<div class="modal fade" id="newInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 pt-4 px-4 bg-primary text-white rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>إضافة فاتورة جديدة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="invoiceForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="customer_id" id="customer_id_hidden">
                <input type="hidden" name="agent_id" id="agent_id_hidden">
                <div class="modal-body p-4">
                    <!-- المعلومات الأساسية -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">تاريخ الفاتورة</label>
                            <input type="datetime-local" name="invoice_date" class="form-control apple-input" value="<?php echo h(format_datetime_local_value(normalize_datetime_db(null))); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">الفرع المسؤول</label>
                            <select name="branch_id" class="form-select apple-input" required>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">نوع الخدمة</label>
                            <select name="source_type" id="service_id" class="form-select select2-modal">
                                <option value="general">عام (General)</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['service_name']; ?>" data-id="<?php echo $s['id']; ?>"><?php echo $s['service_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">نوع التوصيل</label>
                            <select name="delivery_type" id="delivery_type" class="form-select apple-input" required>
                                <option value="" selected disabled>-- اختر النوع --</option>
                                <option value="draft">📝 مسودة</option>
                                <option value="cash">💵 نقد</option>
                                <option value="credit">📅 آجل</option>
                                <option value="bank_transfer">🏦 تحويل بنكي</option>
                                <option value="agent">👤 وكيل</option>
                            </select>
                        </div>
                    </div>

                    <!-- الأطراف والحسابات -->
                    <div class="row g-3 mb-4 p-3 bg-light rounded-4 border border-dashed">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted" id="account_label">الحساب المتأثر</label>
                            <select name="account_id" id="account_select" class="form-select select2-modal" required disabled>
                                <option value="">-- اختر نوع التوصيل أولاً --</option>
                            </select>
                            <!-- منطقة عرض الرصيد والحد الائتماني -->
                            <div id="account_balance_info" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted"><i class="fas fa-wallet me-1"></i> صافي الرصيد الموحد:</span>
                                    <span id="unified_balance_display" class="fw-bold"></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الائتماني:</span>
                                    <span id="unified_limit_display" class="fw-bold text-danger"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">المورد (جهة التكلفة)</label>
                            <select name="supplier_id" id="supplier_id" class="form-select select2-modal">
                                <option value="">-- اختر المورد --</option>
                                <?php foreach ($suppliers_with_codes as $s): ?>
                                    <option value="<?php echo $s['supplier_id']; ?>" data-account="<?php echo $s['id']; ?>"><?php echo $s['display_name']; ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- التحكم في قيد فاتورة الشراء بناءً على إعدادات "إنشاء الفواتير تلقائياً" -->
                            <?php if (isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true)): ?>
                                <input type="hidden" name="record_purchase" id="record_purchase" value="1">
                            <?php else: ?>
                                <div class="mt-2 p-2 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25">
                                    <label class="form-label extra-small fw-bold text-primary mb-1"><i class="fas fa-question-circle me-1"></i> هل تريد إنشاء فاتورة شراء للمورد؟</label>
                                    <select name="record_purchase" id="record_purchase" class="form-select form-select-sm border-primary" required>
                                        <option value="" selected disabled>-- يجب الاختيار --</option>
                                        <option value="1">نعم، تسجيل مديونية</option>
                                        <option value="0">لا، مبيعات فقط</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <!-- منطقة عرض رصيد المورد والحد الدائن -->
                            <div id="supplier_balance_info" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted"><i class="fas fa-wallet me-1"></i> رصيد المكتب عند المورد:</span>
                                    <span id="supplier_unified_balance_display" class="fw-bold"></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الدائن المسموح:</span>
                                    <span id="supplier_unified_limit_display" class="fw-bold text-success"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- المبالغ والعملات -->
                    <div class="row g-3 mb-4 p-3 bg-white border rounded-4 shadow-sm">
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-primary">إجمالي سعر البيع</label>
                            <input type="number" step="0.01" name="total_amount" id="total_amount" class="form-control fw-bold text-primary" required data-original-price="0" data-service-currency-id="">
                            <div id="sales_exchange_info" class="extra-small text-muted mt-1" style="display: none;"></div>
                        </div>
                        <div class="col-md-2" id="received_amount_field" style="display: none;">
                            <label class="form-label small fw-bold text-muted">المبلغ الواصل (المقبوض)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-primary"><i class="fas fa-hand-holding-usd"></i></span>
                                <input type="number" step="0.01" name="received_amount" id="received_amount" class="form-control fw-bold border-primary text-primary" placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-danger">مبلغ الخصم</label>
                            <input type="number" step="0.01" name="discount" id="discount" class="form-control" data-original-discount="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted">عملة البيع</label>
                            <select name="sale_currency_id" id="sale_currency_id" class="form-select">
                                <?php foreach ($currencies as $curr): ?>
                                    <option value="<?php echo $curr['id']; ?>" data-symbol="<?php echo $curr['currency_symbol']; ?>" data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>" data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>" data-rate="<?php echo $curr['exchange_rate'] ?? 1; ?>"><?php echo $curr['currency_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-warning">سعر التكلفة</label>
                            <input type="number" step="0.01" name="cost_amount" id="cost_amount" class="form-control fw-bold text-warning" data-original-cost="0" data-cost-service-currency-id="">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted" id="main_currency_label">عملة التكلفة</label>
                            <select name="currency_id" id="main_currency_id" class="form-select">
                                <?php foreach ($currencies as $curr): ?>
                                    <option value="<?php echo $curr['id']; ?>" data-symbol="<?php echo $curr['currency_symbol']; ?>" data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>" data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>" data-rate="<?php echo $curr['exchange_rate'] ?? 1; ?>"><?php echo $curr['currency_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- سعر الصرف والتكلفة المعادلة -->
                    <div class="row g-3 mb-4" id="exchange_rate_container" style="display: none;">
                        <div class="col-md-8">
                            <div class="p-3 bg-light border border-dashed rounded-4">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted" id="exchange_rate_label">سعر الصرف</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white">1 <span class="pur-symbol"></span> =</span>
                                            <input type="number" step="0.000001" name="exchange_rate" id="invoice_exchange_rate" class="form-control text-center fw-bold" value="1.000000">
                                            <span class="input-group-text bg-white"><span class="sale-symbol"></span></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted" id="equivalent_cost_label">التكلفة المعادلة</label>
                                        <input type="text" id="equivalent_cost_display" class="form-control bg-white" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- حسابات الخدمة -->
                    <div class="row g-3 mb-4 p-3 bg-light rounded-4 border border-dashed">
                        <div class="col-12 mb-3">
                            <h6 class="fw-bold text-primary"><i class="fas fa-bookkeeping me-2"></i> حسابات الخدمة</h6>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-success">حساب الإيرادات</label>
                            <input type="text" id="service_revenue_account" class="form-control bg-white" readonly value="" placeholder="لا تختار نوع الخدمة أولاً">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-danger">حساب التكلفة</label>
                            <input type="text" id="service_cost_account" class="form-control bg-white" readonly value="" placeholder="لا تختار نوع الخدمة أولاً">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-warning">حساب الأرباح</label>
                            <input type="text" id="service_profit_account" class="form-control bg-white" readonly value="" placeholder="لا تختار نوع الخدمة أولاً">
                        </div>
                    </div>
                    <!-- البيان -->
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">البيان / الوصف (يظهر في القيد المحاسبي)</label>
                            <textarea name="description" class="form-control apple-input" rows="2" placeholder="اكتب تفاصيل الفاتورة هنا..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer invoice-modal-footer border-top-0 p-3 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4 py-2" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_invoice" class="btn btn-primary px-8 py-4 shadow-lg fw-bold fs-4">
                        <i class="fas fa-check-circle me-3 fa-lg"></i> حفظ الفاتورة والترحيل للمسودة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        async function showPageDialog({ title, text, icon = 'info', confirmText = 'حسناً', cancelText = 'إلغاء', showCancel = false }) {
            const isDark = document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode');

            if (window.Swal && typeof window.Swal.fire === 'function') {
                return await window.Swal.fire({
                    title,
                    text,
                    icon,
                    showCancelButton: showCancel,
                    confirmButtonText: confirmText,
                    cancelButtonText: cancelText,
                    reverseButtons: true,
                    background: isDark ? '#0b1220' : '#ffffff',
                    color: isDark ? '#e2e8f0' : '#0f172a',
                    confirmButtonColor: isDark ? '#2563eb' : undefined,
                    cancelButtonColor: isDark ? '#475569' : undefined
                });
            }

            if (showCancel) {
                return { isConfirmed: window.confirm(text || title || '') };
            }

            window.alert(text || title || '');
            return { isConfirmed: true };
        }

        async function confirmDialog({ title, text, icon, confirmText, cancelText }) {
            const res = await showPageDialog({
                title,
                text,
                icon: icon || 'question',
                confirmText: confirmText || 'تأكيد',
                cancelText: cancelText || 'إلغاء',
                showCancel: true
            });
            return !!res.isConfirmed;
        }

        $(document).on('submit', 'form.js-confirm-submit', async function(e) {
            if (this.dataset.confirmed === '1') {
                this.dataset.confirmed = '0';
                return true;
            }

            e.preventDefault();

            const ok = await confirmDialog({
                title: this.dataset.confirmTitle || 'تأكيد العملية',
                text: this.dataset.confirmText || 'هل تريد المتابعة؟',
                icon: this.dataset.confirmIcon || 'warning',
                confirmText: this.dataset.confirmButton || 'نعم',
                cancelText: this.dataset.cancelButton || 'إلغاء'
            });

            if (ok) {
                this.dataset.confirmed = '1';
                this.requestSubmit ? this.requestSubmit() : this.submit();
            }

            return false;
        });

        $('.select2-modal').select2({
            dropdownParent: $('#newInvoiceModal'),
            width: '100%'
        });
        $('.select2-modal-edit').select2({
            dropdownParent: $('#editInvoiceModal'),
            width: '100%'
        });

        const entitiesData = {
            cashboxes: <?php echo json_encode($cashboxes_entities); ?>,
            customers: <?php echo json_encode($customers_entities); ?>,
            banks: <?php echo json_encode($banks_entities); ?>,
            agents: <?php echo json_encode($agents_entities); ?>
        };
        const serviceConfigs = <?php echo json_encode($service_configs); ?>;

        // Function to update service account display
        function updateServiceAccounts(serviceName, prefix = '', serviceId = null) {
            const setAccountFields = function(config) {
                if (!config) {
                    $('#' + prefix + 'service_revenue_account').val('').attr('placeholder', 'لا تختار نوع الخدمة أولاً');
                    $('#' + prefix + 'service_cost_account').val('').attr('placeholder', 'لا تختار نوع الخدمة أولاً');
                    $('#' + prefix + 'service_profit_account').val('').attr('placeholder', 'لا تختار نوع الخدمة أولاً');
                    return;
                }

                $('#' + prefix + 'service_revenue_account').val(config.revenue_account_name || 'لم يتم إعداد الحساب');
                $('#' + prefix + 'service_cost_account').val(config.cost_account_name || 'لم يتم إعداد الحساب');
                $('#' + prefix + 'service_profit_account').val(config.profit_account_name || 'لم يتم إعداد الحساب');
            };

            if (!serviceName || serviceName === 'general') {
                setAccountFields(null);
                return;
            }

            const fallbackConfig = serviceConfigs[serviceName] || null;
            const resolvedServiceId = serviceId || ($('#' + prefix + 'service_id_select').find(':selected').data('id')) || ($('#' + prefix + 'service_id').find(':selected').data('id'));

            if (!resolvedServiceId) {
                setAccountFields(fallbackConfig);
                return;
            }

            $.ajax({
                url: 'ajax_get_service_accounts.php',
                data: { service_id: resolvedServiceId },
                dataType: 'json',
                success: function(res) {
                    if (res && res.success) {
                        setAccountFields(res);
                    } else {
                        setAccountFields(fallbackConfig);
                    }
                },
                error: function() {
                    setAccountFields(fallbackConfig);
                }
            });
        }

        // Event listener for service type change
        $('#service_id').change(function() {
            const serviceName = $(this).val();
            const serviceId = $(this).find(':selected').data('id') || null;
            updateServiceAccounts(serviceName, '', serviceId);
        });

        // Initialize service accounts for edit modal as well
        $('#edit_service_id_select').change(function() {
            const serviceName = $(this).val();
            const serviceId = $(this).find(':selected').data('id') || null;
            updateServiceAccounts(serviceName, 'edit_', serviceId);
        });

        // Function to update a currency dropdown with active currencies for an account
        function updateCurrencyDropdown(currencySelectId, accountId, prefix = '') {
            if (!accountId) {
                // If no account selected, show all currencies
                $.get('invoices.php', {
                    action: 'get_active_currencies',
                    account_id: 'all'
                }, function(currencies) {
                    const select = $('#' + currencySelectId);
                    const currentValue = select.val();
                    select.empty();
                    currencies.forEach(curr => {
                        const isSelected = curr.id == currentValue ? 'selected' : '';
                        select.append($('<option>', {
                            value: curr.id,
                            'data-symbol': curr.currency_symbol,
                            'data-buy': curr.exchange_rate_buy ?? 1,
                            'data-sell': curr.exchange_rate_sell ?? 1,
                            'data-rate': curr.exchange_rate ?? 1,
                            selected: isSelected
                        }).text(curr.currency_name));
                    });
                    updateLogic(prefix);
                }, 'json');
                return;
            }

            // Get active currencies for the selected account
            $.get('invoices.php', { 
                action: 'get_active_currencies', 
                account_id: accountId 
            }, function(currencies) {
                const select = $('#' + currencySelectId);
                const currentValue = select.val();
                select.empty();
                
                // If no active currencies found, show all currencies as fallback
                if (!currencies || currencies.length === 0) {
                    $.get('invoices.php', {
                        action: 'get_active_currencies',
                        account_id: 'all'
                    }, function(allCurrencies) {
                        allCurrencies.forEach(curr => {
                            const isSelected = curr.is_default ? 'selected' : '';
                            select.append($('<option>', {
                                value: curr.id,
                                'data-symbol': curr.currency_symbol,
                                'data-buy': curr.exchange_rate_buy ?? 1,
                                'data-sell': curr.exchange_rate_sell ?? 1,
                                'data-rate': curr.exchange_rate ?? 1,
                                selected: isSelected
                            }).text(curr.currency_name));
                        });
                        updateLogic(prefix);
                    }, 'json');
                    return;
                }

                // Populate with active currencies
                currencies.forEach(curr => {
                    const isSelected = (curr.id == currentValue) || curr.is_default ? 'selected' : '';
                    select.append($('<option>', {
                        value: curr.id,
                        'data-symbol': curr.currency_symbol,
                        'data-buy': curr.exchange_rate_buy ?? 1,
                        'data-sell': curr.exchange_rate_sell ?? 1,
                        'data-rate': curr.exchange_rate ?? 1,
                        selected: isSelected
                    }).text(curr.currency_name));
                });
                
                updateLogic(prefix);
            }, 'json');
        }

        function updateLogic(prefix = '') {
            const isEdit = prefix === 'edit_';
            const recordPurchase = $('#' + prefix + 'record_purchase').val() === '1';
            const purCurrencyId = $('#' + prefix + 'main_currency_id').val();
            const saleCurrencyId = $('#' + prefix + 'sale_currency_id').val();

            $('#' + prefix + 'sale_currency_field').show();
            $('#' + prefix + 'main_currency_label').text(recordPurchase ? 'عملة التكلفة (المورد)' : 'العملة');

            if (purCurrencyId && saleCurrencyId && purCurrencyId != saleCurrencyId) {
                $('#' + prefix + 'exchange_rate_container').show();
                const purOpt = $('#' + prefix + 'main_currency_id option:selected');
                const saleOpt = $('#' + prefix + 'sale_currency_id option:selected');
                const purSymbol = purOpt.data('symbol') || '---';
                const saleSymbol = saleOpt.data('symbol') || '---';
                const purBuy = parseFloat(purOpt.data('buy')) || 1;
                const saleSell = parseFloat(saleOpt.data('sell')) || 1;
                const rate = purBuy / saleSell;

                if (isEdit) {
                    $('.edit-pur-symbol').text(purSymbol);
                    $('.edit-sale-symbol').text(saleSymbol);
                    $('#edit_exchange_rate_label').html('1 ' + purSymbol + ' = ? ' + saleSymbol);
                } else {
                    $('.pur-symbol').text(purSymbol);
                    $('.sale-symbol').text(saleSymbol);
                    $('#exchange_rate_label').html('1 ' + purSymbol + ' = ? ' + saleSymbol);
                }
                $('#' + prefix + 'invoice_exchange_rate').val(rate.toFixed(6));
            } else {
                $('#' + prefix + 'invoice_exchange_rate').val('1.000000');
                $('#' + prefix + 'exchange_rate_container').hide();
            }
            calculateEquivalent(prefix);
        }

        function calculateEquivalent(prefix = '') {
            const cost = parseFloat($('#' + prefix + 'cost_amount').val()) || 0;
            const saleCurrencyId = $('#' + prefix + 'sale_currency_id').val();
            const mainCurrencyId = $('#' + prefix + 'main_currency_id').val();
            const rate = parseFloat($('#' + prefix + 'invoice_exchange_rate').val()) || 1;
            let equivalent = (saleCurrencyId != mainCurrencyId) ? cost * rate : cost;
            const saleSymbol = $('#' + prefix + 'sale_currency_id option:selected').data('symbol') || 'ر.ي';
            $('#' + prefix + 'equivalent_cost_display').val(equivalent.toLocaleString(undefined, {
                minimumFractionDigits: 2
            }) + ' ' + saleSymbol);
        }

        function updateConvertedPrices(prefix = '', skipDiscount = false) {
            const priceOrig = parseFloat($('#' + prefix + 'total_amount').attr('data-original-price')) || 0;
            const priceCurrId = $('#' + prefix + 'total_amount').attr('data-service-currency-id');
            const saleCurrId = $('#' + prefix + 'sale_currency_id').val();
            const discount = parseFloat($('#' + prefix + 'discount').val()) || 0;
            const infoDivId = prefix === 'edit_' ? 'edit_sales_exchange_info' : 'sales_exchange_info';

            if (priceOrig > 0) {
                $('#' + prefix + 'total_amount').prop('readonly', true);
                let convBase = priceOrig;
                if (saleCurrId && priceCurrId && saleCurrId != priceCurrId) {
                    const saleOpt = $('#' + prefix + 'sale_currency_id option:selected');
                    const serviceOpt = $('#' + prefix + 'sale_currency_id option[value="' + priceCurrId + '"]').length ? $('#' + prefix + 'sale_currency_id option[value="' + priceCurrId + '"]') : $('#' + prefix + 'main_currency_id option[value="' + priceCurrId + '"]');
                    if (serviceOpt.length) {
                        const buyRate = parseFloat(serviceOpt.data('buy'));
                        const sellRate = parseFloat(saleOpt.data('sell'));
                        const rate = buyRate / sellRate;
                        convBase = priceOrig * rate;

                        const serviceSymbol = serviceOpt.data('symbol') || '---';
                        const saleSymbol = saleOpt.data('symbol') || '---';
                        $('#' + infoDivId).html(`<i class="fas fa-sync-alt me-1"></i> 1 ${serviceSymbol} = ${rate.toFixed(4)} ${saleSymbol}`).show();
                    }
                } else {
                    $('#' + infoDivId).hide();
                }
                // إجمالي سعر البيع يظل ثابتاً كما طلبت ولا يخصم منه الخصم برمجياً في الحقل
                $('#' + prefix + 'total_amount').val(convBase.toFixed(2));
            } else {
                $('#' + prefix + 'total_amount').prop('readonly', false);
                $('#' + infoDivId).hide();
            }

            const costOrig = parseFloat($('#' + prefix + 'cost_amount').attr('data-original-cost')) || 0;
            const costCurrId = $('#' + prefix + 'cost_amount').attr('data-cost-service-currency-id');
            const mainCurrId = $('#' + prefix + 'main_currency_id').val();
            if (costOrig > 0) {
                $('#' + prefix + 'cost_amount').prop('readonly', true);
                let convCost = costOrig;
                if (mainCurrId && costCurrId && mainCurrId != costCurrId) {
                    const mainOpt = $('#' + prefix + 'main_currency_id option:selected');
                    const costSrvOpt = $('#' + prefix + 'main_currency_id option[value="' + costCurrId + '"]').length ? $('#' + prefix + 'main_currency_id option[value="' + costCurrId + '"]') : $('#' + prefix + 'sale_currency_id option[value="' + costCurrId + '"]');
                    if (costSrvOpt.length) {
                        convCost = costOrig * (parseFloat(costSrvOpt.data('buy')) / parseFloat(mainOpt.data('sell')));
                    }
                }
                $('#' + prefix + 'cost_amount').val(convCost.toFixed(2));
            } else {
                $('#' + prefix + 'cost_amount').prop('readonly', false);
            }

            validateDiscount(prefix);
            calculateEquivalent(prefix);
        }

        function validateDiscount(prefix = '') {
            const total = parseFloat($('#' + prefix + 'total_amount').val()) || 0;
            const discount = parseFloat($('#' + prefix + 'discount').val()) || 0;
            const cost = parseFloat($('#' + prefix + 'cost_amount').val()) || 0;
            const saleCurrencyId = $('#' + prefix + 'sale_currency_id').val();
            const mainCurrencyId = $('#' + prefix + 'main_currency_id').val();
            const rate = parseFloat($('#' + prefix + 'invoice_exchange_rate').val()) || 1;

            // حساب التكلفة بعملة البيع للمقارنة
            // إذا كانت عملة المورد (mainCurrencyId) تختلف عن عملة البيع، نستخدم سعر الصرف
            const costInSaleCurrency = (saleCurrencyId != mainCurrencyId) ? cost * rate : cost;
            const netPrice = total - discount;

            if (discount > 0 && netPrice < costInSaleCurrency - 0.01) {
                $('#' + prefix + 'discount').addClass('is-invalid');
                const maxAllowed = Math.max(0, total - costInSaleCurrency);
                const errorMsg = `عفواً! لا يمكن أن يقل السعر الصافي عن التكلفة (${costInSaleCurrency.toFixed(2)}). أقصى خصم مسموح: ${maxAllowed.toFixed(2)}`;

                if (!$('#' + prefix + 'discount_error').length) {
                    $('#' + prefix + 'discount').after(`<div id="${prefix}discount_error" class="invalid-feedback extra-small fw-bold">${errorMsg}</div>`);
                } else {
                    $('#' + prefix + 'discount_error').text(errorMsg);
                }
                return false;
            } else {
                $('#' + prefix + 'discount').removeClass('is-invalid');
                $('#' + prefix + 'discount_error').remove();
                return true;
            }
        }

        // إضافة التحقق للخصم عند الإدخال في الحقول المؤثرة
        $(document).on('input', '#discount, #edit_discount, #total_amount, #edit_total_amount, #cost_amount, #edit_cost_amount, #invoice_exchange_rate, #edit_invoice_exchange_rate', function() {
            const prefix = $(this).attr('id').startsWith('edit') ? 'edit_' : '';
            validateDiscount(prefix);
        });

        // Events for New Modal
        $('#main_currency_id, #sale_currency_id, #record_purchase').change(() => {
            updateLogic();
            updateConvertedPrices();
        });
        $('#invoice_exchange_rate, #cost_amount').on('input', () => calculateEquivalent());
        $('#discount').on('input', () => updateConvertedPrices('', true));
        $('#delivery_type').change(function() {
            handleDeliveryType($(this).val(), 'account_select', 'account_label', 'received_amount_field');
        });

        // التحقق من اختيار مديونية المورد والخصم قبل الإرسال
        $('#invoiceForm, #editInvoiceForm').submit(function(e) {
            const prefix = $(this).attr('id') === 'editInvoiceForm' ? 'edit_' : '';

            // 1. تحقق من مديونية المورد
            const val = $('#' + prefix + 'record_purchase').val();
            if (val === null || val === '') {
                e.preventDefault();
                showPageDialog({
                    title: 'بيانات ناقصة',
                    text: 'يرجى اختيار ما إذا كنت تريد تسجيل مديونية للمورد أم لا.',
                    icon: 'warning'
                });
                $('#' + prefix + 'record_purchase').focus();
                return false;
            }

            // 2. تحقق من الخصم
            if (!validateDiscount(prefix)) {
                e.preventDefault();
                showPageDialog({
                    title: 'تحذير مالي',
                    text: 'عفواً! لا يمكن حفظ الفاتورة لأن السعر بعد الخصم أقل من سعر التكلفة.',
                    icon: 'warning'
                });
                $('#' + prefix + 'discount').focus();
                return false;
            }

            // 3. تحقق من مركز التكلفة إذا كان إلزامياً
            const requireCostCenter = <?php echo ($settings['require_cost_center'] ?? 0) ? 'true' : 'false'; ?>;
            if (requireCostCenter) {
                const branchId = $('#' + prefix + 'branch_id').val();
                if (!branchId || branchId === '') {
                    e.preventDefault();
                    showPageDialog({
                        title: 'مركز التكلفة مطلوب',
                        text: 'عفواً! اختيار الفرع (مركز التكلفة) إلزامي حسب إعدادات النظام.',
                        icon: 'warning'
                    });
                    $('#' + prefix + 'branch_id').focus();
                    return false;
                }
            }
        });

        // Events for Edit Modal
        $('#edit_main_currency_id, #edit_sale_currency_id, #edit_record_purchase').change(() => {
            updateLogic('edit_');
            updateConvertedPrices('edit_');
        });
        $('#edit_invoice_exchange_rate, #edit_cost_amount').on('input', () => calculateEquivalent('edit_'));
        $('#edit_discount').on('input', () => updateConvertedPrices('edit_', true));
        $('#edit_delivery_type').change(function() {
            handleDeliveryType($(this).val(), 'edit_account_select', 'edit_account_label', 'edit_received_amount_field');
        });

        function handleDeliveryType(type, selectId, labelId, receivedFieldId, prefix = '') {
            let list = [],
                label = 'الحساب المتأثر';
            const $sel = $('#' + selectId);

            if (!type || type === '') {
                $sel.prop('disabled', true).empty().append('<option value="">-- اختر نوع التوصيل أولاً --</option>').trigger('change');
                $('#' + labelId).text('الحساب المتأثر');
                $('#' + receivedFieldId).hide();
                return;
            }

            $sel.prop('disabled', false);
            if (type === 'cash') {
                list = entitiesData.cashboxes;
                label = 'الحساب: الصناديق';
                $('#' + receivedFieldId).show();
            } else if (type === 'credit') {
                list = entitiesData.customers;
                label = 'الحساب: العملاء';
                $('#' + receivedFieldId).hide();
            } else if (type === 'bank_transfer') {
                list = entitiesData.banks;
                label = 'الحساب: البنوك';
                $('#' + receivedFieldId).hide();
            } else if (type === 'agent') {
                list = entitiesData.agents;
                label = 'الحساب: الوكلاء';
                $('#' + receivedFieldId).hide();
            } else {
                $('#' + receivedFieldId).hide();
            }

            $('#' + labelId).text(label);
            $sel.empty().append('<option value="">-- اختر --</option>');
            list.forEach(item => {
                const customerId = item.customer_id || '';
                const agentId = item.agent_id || '';
                const displayName = item.display_name || (item.account_code + ' - ' + (item.name || item.account_name_ar));
                $sel.append(`<option value="${item.id}" data-customer-id="${customerId}" data-agent-id="${agentId}">${displayName}</option>`);
            });
            $sel.trigger('change');
        }

        // إضافة تنبيه عند النقر على الحساب قبل اختيار نوع التوصيل
        $(document).on('click', '.select2-container--disabled', function() {
            const id = $(this).prev('select').attr('id');
            if (id === 'account_select' || id === 'edit_account_select') {
                showPageDialog({
                    title: 'تنبيه',
                    text: 'يرجى اختيار "نوع التوصيل" أولاً لتتمكن من اختيار الحساب.',
                    icon: 'info'
                });
                const prefix = id.startsWith('edit') ? 'edit_' : '';
                $('#' + prefix + 'delivery_type').focus();
            }
        });

        // تحديث الحقول المخفية للعميل/الوكيل عند تغيير الحساب
        $('#account_select').change(function() {
            const customerId = $(this).find(':selected').data('customer-id');
            const agentId = $(this).find(':selected').data('agent-id');
            const accountId = $(this).val();

            $('#customer_id_hidden').val(customerId);
            $('#agent_id_hidden').val(agentId);

            // Update sale currency dropdown
            updateCurrencyDropdown('sale_currency_id', accountId, '');

            // جلب وعرض الرصيد والحد الائتماني الموحد
            if (accountId) {
                $.get('ajax_get_account_balances.php', {
                    account_id: accountId
                }, function(data) {
                    if (data && data.length > 0) {
                        let totalNetBalanceBase = 0;
                        let creditLimitBase = parseFloat(data[0].credit_limit_base) || 0;
                        const normalBalance = data[0].normal_balance;

                        data.forEach(bal => {
                            totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
                        });

                        let statusText = '';
                        let statusClass = '';
                        if (Math.abs(totalNetBalanceBase) < 0.01) {
                            statusText = '(متعادل)';
                            statusClass = 'text-muted';
                        } else if (normalBalance === 'debit') {
                            // حساب مدين (أصل/عميل): موجب = عليه
                            statusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                            statusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
                        } else {
                            // حساب دائن (خصم): موجب = عليه
                            statusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                            statusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
                        }

                        const baseSymbol = '<?php echo $base_currency['currency_symbol']; ?>';

                        $('#unified_balance_display').html(`<span class="${statusClass}">${Math.abs(totalNetBalanceBase).toLocaleString(undefined, {minimumFractionDigits: 2})}</span> <small class="text-muted">${baseSymbol}</small> ${statusText}`);
                        $('#unified_limit_display').text(creditLimitBase > 0 ? creditLimitBase.toLocaleString(undefined, {
                            minimumFractionDigits: 2
                        }) + ' ' + baseSymbol : 'غير محدد');
                        $('#account_balance_info').removeClass('d-none');
                    } else {
                        $('#account_balance_info').addClass('d-none');
                    }
                });
            } else {
                $('#account_balance_info').addClass('d-none');
            }
        });

        // Supplier change event for new invoice
        $('#supplier_id').change(function() {
            const supplierId = $(this).val();
            if (supplierId) {
                // Get account_id from suppliers table
                $.get('invoices.php', {
                    action: 'get_account_from_entity',
                    entity_type: 'supplier',
                    entity_id: supplierId
                }, function(data) {
                    if (data && data.account_id) {
                        updateCurrencyDropdown('main_currency_id', data.account_id, '');
                    }
                }, 'json');
            } else {
                updateCurrencyDropdown('main_currency_id', null, '');
            }
        });

        // Supplier change event for edit invoice
        $('#edit_supplier_id').change(function() {
            const supplierId = $(this).val();
            if (supplierId) {
                // Get account_id from suppliers table
                $.get('invoices.php', {
                    action: 'get_account_from_entity',
                    entity_type: 'supplier',
                    entity_id: supplierId
                }, function(data) {
                    if (data && data.account_id) {
                        updateCurrencyDropdown('edit_main_currency_id', data.account_id, 'edit_');
                    }
                }, 'json');
            } else {
                updateCurrencyDropdown('edit_main_currency_id', null, 'edit_');
            }
        });

        $('#edit_account_select').change(function() {
            const customerId = $(this).find(':selected').data('customer-id');
            const agentId = $(this).find(':selected').data('agent-id');
            const accountId = $(this).val();

            // Update sale currency dropdown for edit modal
            updateCurrencyDropdown('edit_sale_currency_id', accountId, 'edit_');

            $('#edit_customer_id').val(customerId);
            $('#edit_agent_id').val(agentId);

            // جلب وعرض الرصيد والحد الائتماني الموحد (تعديل)
            if (accountId) {
                $.get('ajax_get_account_balances.php', {
                    account_id: accountId
                }, function(data) {
                    if (data && data.length > 0) {
                        let totalNetBalanceBase = 0;
                        let creditLimitBase = parseFloat(data[0].credit_limit_base) || 0;
                        const normalBalance = data[0].normal_balance;

                        data.forEach(bal => {
                            totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
                        });

                        let statusText = '';
                        let statusClass = '';
                        if (Math.abs(totalNetBalanceBase) < 0.01) {
                            statusText = '(متعادل)';
                            statusClass = 'text-muted';
                        } else if (normalBalance === 'debit') {
                            // حساب مدين (أصل/عميل): موجب = عليه
                            statusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                            statusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
                        } else {
                            // حساب دائن (خصم): موجب = عليه
                            statusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                            statusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
                        }

                        const baseSymbol = '<?php echo $base_currency['currency_symbol']; ?>';

                        $('#edit_unified_balance_display').html(`<span class="${statusClass}">${Math.abs(totalNetBalanceBase).toLocaleString(undefined, {minimumFractionDigits: 2})}</span> <small class="text-muted">${baseSymbol}</small> ${statusText}`);
                        $('#edit_unified_limit_display').text(creditLimitBase > 0 ? creditLimitBase.toLocaleString(undefined, {
                            minimumFractionDigits: 2
                        }) + ' ' + baseSymbol : 'غير محدد');
                        $('#edit_account_balance_info').removeClass('d-none');
                    } else {
                        $('#edit_account_balance_info').addClass('d-none');
                    }
                });
            } else {
                $('#edit_account_balance_info').addClass('d-none');
            }
        });

        // جلب بيانات الخدمة (سعر و تكلفة)
        $('#service_id, #edit_service_id_select').change(function() {
            const prefix = $(this).attr('id').startsWith('edit') ? 'edit_' : '';
            const serviceName = $(this).val();
            const serviceId = $(this).find(':selected').data('id');

            if (serviceName && serviceName !== 'general') {
                $.ajax({
                    url: 'ajax_get_service_price.php',
                    data: {
                        service_name: serviceName,
                        service_id: serviceId
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            // تعبئة المبالغ والعملات
                            $('#' + prefix + 'total_amount').val(res.sale_price).attr('data-original-price', res.sale_price).attr('data-service-currency-id', res.currency_id);
                            $('#' + prefix + 'cost_amount').val(res.purchase_price).attr('data-original-cost', res.purchase_price).attr('data-cost-service-currency-id', res.currency_id);

                            // تعيين العملات الافتراضية للخدمة
                            if (res.currency_id) {
                                $('#' + prefix + 'sale_currency_id').val(res.currency_id).trigger('change');
                                $('#' + prefix + 'main_currency_id').val(res.currency_id).trigger('change');
                            }

                            // تعيين المورد الافتراضي إذا وجد
                            if (res.supplier_id) {
                                $('#' + prefix + 'supplier_id').val(res.supplier_id).trigger('change');
                                // نترك خيار تسجيل مديونية المورد للمستخدم ولا نفعله تلقائياً
                            }

                            // تعيين العميل/الحساب الافتراضي إذا وجد
                            if (res.customer_id || res.agent_id) {
                                const type = res.customer_id ? 'credit' : 'agent';
                                const targetId = res.customer_id || res.agent_id;
                                const dataAttr = res.customer_id ? 'customer-id' : 'agent-id';

                                $('#' + prefix + 'delivery_type').val(type).trigger('change');

                                // ننتظر قليلاً حتى يتم تحميل قائمة الحسابات بناءً على النوع
                                setTimeout(() => {
                                    const $accSel = $('#' + prefix + 'account_select');
                                    const optionToSelect = $accSel.find(`option[data-${dataAttr}="${targetId}"]`);
                                    if (optionToSelect.length) {
                                        $accSel.val(optionToSelect.val()).trigger('change.select2');
                                    }
                                }, 200);
                            }

                            updateConvertedPrices(prefix);

                            // إضافة رابط لصفحة العمرة إذا كانت الخدمة "حج وعمرة"

                        }
                    }
                });
            } else {
                $('#' + prefix + 'total_amount').val('').prop('readonly', false).attr('data-original-price', 0);
                $('#' + prefix + 'cost_amount').val('').prop('readonly', false).attr('data-original-cost', 0);
            }
        });

        // تحديث رصيد المورد والحد الدائن عند الاختيار
        $('#supplier_id, #edit_supplier_id').change(function() {
            const prefix = $(this).attr('id').startsWith('edit') ? 'edit_' : '';
            const accountId = $(this).find(':selected').data('account');
            const $infoBox = $('#' + prefix + 'supplier_balance_info');

            if (accountId) {
                $.get('ajax_get_account_balances.php', {
                    account_id: accountId
                }, function(data) {
                    if (data && data.length > 0) {
                        let totalNetBalanceBase = 0;
                        let debitLimitBase = parseFloat(data[0].debit_limit_base) || 0;
                        const normalBalance = data[0].normal_balance;

                        data.forEach(bal => {
                            totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
                        });

                        let statusText = '';
                        let statusClass = '';
                        if (Math.abs(totalNetBalanceBase) < 0.01) {
                            statusText = '(متعادل)';
                            statusClass = 'text-muted';
                        } else if (normalBalance === 'debit') {
                            // حساب مدين (أصل/عميل)
                            statusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                            statusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
                        } else {
                            // حساب دائن (خصم/مورد)
                            statusText = totalNetBalanceBase > 0 ? '(لنا عنده)' : '(له عندنا)';
                            statusClass = totalNetBalanceBase > 0 ? 'text-success' : 'text-danger';
                        }

                        const baseSymbol = '<?php echo $base_currency['currency_symbol']; ?>';

                        $('#' + prefix + 'supplier_unified_balance_display').html(`<span class="${statusClass}">${Math.abs(totalNetBalanceBase).toLocaleString(undefined, {minimumFractionDigits: 2})}</span> <small class="text-muted">${baseSymbol}</small> ${statusText}`);
                        $('#' + prefix + 'supplier_unified_limit_display').text(debitLimitBase > 0 ? debitLimitBase.toLocaleString(undefined, {
                            minimumFractionDigits: 2
                        }) + ' ' + baseSymbol : 'غير محدد');
                        $infoBox.removeClass('d-none');
                    } else {
                        $infoBox.addClass('d-none');
                    }
                });
            } else {
                $infoBox.addClass('d-none');
            }
        });

        window.loadInvoiceData = function(inv) {
            $('#edit_invoice_id').val(inv.sales_id || inv.purchase_id);
            $('#edit_invoice_date').val((inv.invoice_date || '').replace(' ', 'T').slice(0, 16));

            // ضبط العملات
            $('#edit_sale_currency_id').val(inv.currency_id).trigger('change.select2');
            $('#edit_main_currency_id').val(inv.purchase_currency_id || inv.currency_id).trigger('change.select2');

            $('#edit_service_id_select').val(inv.source_type || 'general').trigger('change.select2');
            const editServiceId = $('#edit_service_id_select').find(':selected').data('id') || null;
            updateServiceAccounts(inv.source_type || 'general', 'edit_', editServiceId); // Call to update service accounts display!
            $('#edit_branch_id').val(inv.branch_id || 1);
            $('#edit_total_amount').val(inv.sales_amount || 0);
            $('#edit_discount').val(inv.sales_discount || '');
            $('#edit_cost_amount').val(inv.purchase_amount || inv.sales_cost_field || 0);
            $('#edit_amount_received').val(inv.sales_amount_received || 0);
            $('#edit_delivery_type').val(inv.sales_delivery_type || 'draft').trigger('change');
            $('#edit_supplier_id').val(inv.supplier_id || '').trigger('change.select2');
            $('#edit_description').val(inv.description || '');
            $('#edit_record_purchase').val(inv.purchase_id ? '1' : '0').trigger('change');

            // ضبط الهويات المخفية
            $('#edit_customer_id').val(inv.customer_id || '');
            $('#edit_agent_id').val(inv.agent_id || '');

            handleDeliveryType(inv.sales_delivery_type, 'edit_account_select', 'edit_account_label', 'edit_received_amount_field', 'edit_');
            setTimeout(() => {
                $('#edit_account_select').val(inv.sales_account_id).trigger('change.select2');
            }, 100);
            updateLogic('edit_');
        };
    });
</script>
<?php require_once 'footer.php'; ?>
