<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$type = 'hajj';
$page_title = 'نظام إدارة الحج المتكامل';
$permission_prefix = 'umrah';

if (!has_permission($permission_prefix . '_view')) {
    header('Location: index.php?error=no_permission');
    exit();
}

// جلب إعدادات العمرة
$settings = getSettings($pdo);
$umrah_settings = $settings;

if (!get_module_status($pdo, 'enable_hajj') && $_SESSION['role'] !== 'developer') {
    die("<div style='text-align:center; padding:50px; font-family:Tahoma;'><h3>عذراً، خدمات الحج معطلة حالياً من قبل الإدارة.</h3><a href='index.php'>العودة للرئيسية</a></div>");
}

// جلب بيانات المستخدم الحالي
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$currentUser = $stmt_user->fetch();

$hajj_service_name = 'خدمات الحج';
$hajj_service_aliases = get_hajj_service_aliases();
$hajj_invoice_source_types_sql = "'" . implode("', '", array_map('addslashes', $hajj_service_aliases)) . "'";

// نظام العزل والفلترة
$entity_filter = get_entity_filter('p', 'branch_id', 'agent_id', null, null);
$where_clauses = ["p.transaction_type = ?"];
$params = [$type];

if (!empty($entity_filter['clause'])) {
    $where_clauses[] = $entity_filter['clause'];
    $params = array_merge($params, $entity_filter['params']);
}

// الفلاتر الإضافية
if (!empty($_GET['status_filter'])) {
    $where_clauses[] = "p.status_id = ?";
    $params[] = intval($_GET['status_filter']);
}

if (!empty($_GET['id'])) {
    $where_clauses[] = "p.id = ?";
    $params[] = intval($_GET['id']);
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// تحقق من وجود الأعمدة الجديدة في جدول passports
$has_new_columns = false;
try {
    $check_columns = $pdo->query("SHOW COLUMNS FROM passports LIKE 'sales_invoice_id'");
    if ($check_columns->fetch()) {
        $has_new_columns = true;
    }
} catch (Exception $e) {
    $has_new_columns = false;
}

// بناء الاستعلام بناءً على وجود الأعمدة
if ($has_new_columns) {
    $sql = "
    SELECT p.*, s.status_name, s.status_color, ser.service_name,
           br.branch_name, ag.agent_name, c.currency_name, c.currency_symbol,
           h.host_name, g.guarantor_name,
           w.name as workflow_name,
           ws.step_name as current_workflow_step,
           ws.color as current_workflow_color,
           DATEDIFF(p.visa_expiry_date, CURDATE()) as remaining_days,
           inv.id as sales_invoice_id, inv.invoice_number as sales_invoice_number,
           inv.total_amount as sales_amount, inv.discount as sales_discount,
           inv.invoice_status as sales_status,
           (
                IFNULL((
                    SELECT SUM(jl.debit)
                    FROM journal_lines jl
                    JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                    WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                    AND jl.account_id IN (
                        SELECT id FROM unified_accounts
                        WHERE account_type IN ('box', 'bank')
                    )
                ), 0) +
                IFNULL((
                    SELECT SUM(pa.allocated_amount)
                    FROM payment_allocations pa
                    JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                    WHERE pa.invoice_id = inv.id AND ft.status = 'posted'
                    AND ft.id NOT IN (
                        SELECT id FROM financial_transactions
                        WHERE reference_id = inv.id AND reference_type = 'invoice'
                    )
                ), 0)
           ) as sales_received,
           pur.id as purchase_invoice_id, pur.invoice_number as purchase_invoice_number,
           pur.total_amount as purchase_amount, pur.invoice_status as purchase_status,
           (
                IFNULL((
                    SELECT SUM(jl_p.credit)
                    FROM journal_lines jl_p
                    JOIN financial_transactions ft_ip ON jl_p.financial_transaction_id = ft_ip.id
                    WHERE ft_ip.reference_id = pur.id AND ft_ip.reference_type = 'invoice' AND ft_ip.status = 'posted'
                    AND jl_p.account_id IN (
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
           ) as purchase_paid,
           sup.supplier_name,
           ua_inv.account_name_ar as cashbox_name,
           ua_inv.account_code as invoice_account_code,
           ua_inv.account_name_ar as invoice_account_name_ar,
           COALESCE(cust.full_name, ag.agent_name, ua_ag.account_name_ar, ua_br.account_name_ar, br.branch_name, '---') as account_display_name
    FROM passports p
    LEFT JOIN statuses s ON p.status_id = s.id
    LEFT JOIN services ser ON p.service_id = ser.id
    LEFT JOIN branches br ON p.branch_id = br.id
    LEFT JOIN agents ag ON p.agent_id = ag.id
    LEFT JOIN customers cust ON p.customer_id = cust.id
    LEFT JOIN unified_accounts ua_br ON br.account_id = ua_br.id
    LEFT JOIN unified_accounts ua_ag ON ag.account_id = ua_ag.id
    LEFT JOIN umrah_hosts h ON p.host_id = h.id
    LEFT JOIN umrah_guarantors g ON p.guarantor_id = g.id
    LEFT JOIN workflows w ON p.workflow_id = w.id
    LEFT JOIN workflow_steps ws ON (ws.workflow_id = p.workflow_id AND ws.status_id = p.status_id)
    LEFT JOIN invoices inv ON (
        inv.id = p.sales_invoice_id 
        OR inv.id = p.invoice_id 
        OR (inv.source_type IN ($hajj_invoice_source_types_sql) AND inv.source_id = p.id AND inv.invoice_category = 'sales')
    )
    LEFT JOIN currencies c ON inv.currency_id = c.id
    LEFT JOIN unified_accounts ua_inv ON inv.account_id = ua_inv.id
    LEFT JOIN invoices pur ON (
        pur.id = p.purchase_invoice_id 
        OR (pur.source_type IN ($hajj_invoice_source_types_sql) AND pur.source_id = p.id AND pur.invoice_category = 'purchase')
    )
    LEFT JOIN suppliers sup ON (inv.supplier_id = sup.id OR pur.supplier_id = sup.id)
    $where_sql
    GROUP BY p.id
    ORDER BY p.created_at DESC
    ";
} else {
    $sql = "
    SELECT p.*, s.status_name, s.status_color, ser.service_name,
           br.branch_name, ag.agent_name, c.currency_name, c.currency_symbol,
           h.host_name, g.guarantor_name,
           w.name as workflow_name,
           ws.step_name as current_workflow_step,
           ws.color as current_workflow_color,
           DATEDIFF(p.visa_expiry_date, CURDATE()) as remaining_days,
           inv.id as sales_invoice_id, inv.invoice_number as sales_invoice_number,
           inv.total_amount as sales_amount, inv.discount as sales_discount,
           inv.invoice_status as sales_status,
           (
                IFNULL((
                    SELECT SUM(jl.debit)
                    FROM journal_lines jl
                    JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                    WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                    AND jl.account_id IN (
                        SELECT id FROM unified_accounts
                        WHERE account_type IN ('box', 'bank')
                    )
                ), 0) +
                IFNULL((
                    SELECT SUM(pa.allocated_amount)
                    FROM payment_allocations pa
                    JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                    WHERE pa.invoice_id = inv.id AND ft.status = 'posted'
                    AND ft.id NOT IN (
                        SELECT id FROM financial_transactions
                        WHERE reference_id = inv.id AND reference_type = 'invoice'
                    )
                ), 0)
           ) as sales_received,
           pur.id as purchase_invoice_id, pur.invoice_number as purchase_invoice_number,
           pur.total_amount as purchase_amount, pur.invoice_status as purchase_status,
           (
                IFNULL((
                    SELECT SUM(jl_p.credit)
                    FROM journal_lines jl_p
                    JOIN financial_transactions ft_ip ON jl_p.financial_transaction_id = ft_ip.id
                    WHERE ft_ip.reference_id = pur.id AND ft_ip.reference_type = 'invoice' AND ft_ip.status = 'posted'
                    AND jl_p.account_id IN (
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
           ) as purchase_paid,
           sup.supplier_name,
           ua_inv.account_name_ar as cashbox_name,
           ua_inv.account_code as invoice_account_code,
           ua_inv.account_name_ar as invoice_account_name_ar,
           COALESCE(cust.full_name, ag.agent_name, ua_ag.account_name_ar, ua_br.account_name_ar, br.branch_name, '---') as account_display_name
    FROM passports p
    LEFT JOIN statuses s ON p.status_id = s.id
    LEFT JOIN services ser ON p.service_id = ser.id
    LEFT JOIN branches br ON p.branch_id = br.id
    LEFT JOIN agents ag ON p.agent_id = ag.id
    LEFT JOIN customers cust ON p.customer_id = cust.id
    LEFT JOIN unified_accounts ua_br ON br.account_id = ua_br.id
    LEFT JOIN unified_accounts ua_ag ON ag.account_id = ua_ag.id
    LEFT JOIN umrah_hosts h ON p.host_id = h.id
    LEFT JOIN umrah_guarantors g ON p.guarantor_id = g.id
    LEFT JOIN workflows w ON p.workflow_id = w.id
    LEFT JOIN workflow_steps ws ON (ws.workflow_id = p.workflow_id AND ws.status_id = p.status_id)
    LEFT JOIN invoices inv ON p.invoice_id = inv.id
    LEFT JOIN currencies c ON inv.currency_id = c.id
    LEFT JOIN unified_accounts ua_inv ON inv.account_id = ua_inv.id
    LEFT JOIN invoices pur ON (pur.source_type IN ($hajj_invoice_source_types_sql) AND pur.source_id = p.id AND pur.invoice_category = 'purchase')
    LEFT JOIN suppliers sup ON (inv.supplier_id = sup.id OR pur.supplier_id = sup.id)
    $where_sql
    GROUP BY p.id
    ORDER BY p.created_at DESC
    ";
}

$passports = $pdo->prepare($sql);
$passports->execute($params);
$passports = $passports->fetchAll();

// جلب سير العمل المتاح للعمرة
$hajj_workflows = get_all_workflows_for_transaction('hajj', $currentUser['branch_id']);

$statuses = $pdo->query("SELECT * FROM statuses")->fetchAll();
$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC")->fetchAll();

// ربط نموذج العمرة بخدمة واحدة فقط: خدمات العمرة
$hajj_service_id = resolve_service_id($pdo, $hajj_service_name);
if (!$hajj_service_id) {
    foreach ($hajj_service_aliases as $service_alias) {
        if ($service_alias === $hajj_service_name) {
            continue;
        }
        $hajj_service_id = resolve_service_id($pdo, $service_alias);
        if ($hajj_service_id) {
            break;
        }
    }
}
if (!$hajj_service_id) {
    foreach (['حج وعمرة', 'خدمات العمرة', 'umrah'] as $legacy_service_alias) {
        $hajj_service_id = resolve_service_id($pdo, $legacy_service_alias);
        if ($hajj_service_id) {
            break;
        }
    }
}

// Fetch service prices for this service
$hajj_service_prices = [];
if ($hajj_service_id) {
    $price_stmt = $pdo->prepare("
        SELECT sp.*, c.currency_name, c.currency_symbol, c.exchange_rate, c.exchange_rate_buy, c.exchange_rate_sell
        FROM service_prices sp 
        LEFT JOIN currencies c ON sp.currency_id = c.id 
        WHERE sp.service_id = ? AND sp.status = 'active'
        ORDER BY (sp.customer_id IS NULL AND sp.supplier_id IS NULL AND sp.branch_id IS NULL AND sp.agent_id IS NULL) DESC
    ");
    $price_stmt->execute([$hajj_service_id]);
    $hajj_service_prices = $price_stmt->fetchAll();
}

// لا حاجة للمستضيفين والضمان في خدمات الحج

// جلب الدول لاستخدامها في الجنسية
$all_countries = $pdo->query("SELECT * FROM countries ORDER BY country_name ASC")->fetchAll();

// جلب العملات
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell, is_default FROM currencies WHERE is_active = 1 ORDER BY currency_name")->fetchAll();
$base_currency = $pdo->query("SELECT * FROM currencies WHERE is_default = 1 LIMIT 1")->fetch();

// الحسابات المالية للبيانات المالية الموحدة
$cash_accounts = $pdo->query("
    SELECT id, account_name_ar as account_name
    FROM unified_accounts
    WHERE (account_code LIKE '11101%')
      AND account_status = 'active'
    ORDER BY account_name_ar ASC
")->fetchAll();

$bank_accounts = $pdo->query("
    SELECT id, account_name_ar as account_name
    FROM unified_accounts
    WHERE (account_code LIKE '11102%')
      AND account_status = 'active'
    ORDER BY account_name_ar ASC
")->fetchAll();

$customer_accounts = $pdo->query("
    SELECT ua.id, ua.account_name_ar as account_name
    FROM customers c
    JOIN unified_accounts ua ON c.account_id = ua.id
    WHERE c.deleted_at IS NULL AND c.status = 'active' AND ua.account_status = 'active'
    ORDER BY ua.account_name_ar ASC
")->fetchAll();
$agents_accounts = $pdo->query("
    SELECT ua.id, ua.account_name_ar as account_name
    FROM agents a
    JOIN unified_accounts ua ON a.account_id = ua.id
    WHERE a.deleted_at IS NULL AND a.status = 'active' AND ua.account_status = 'active'
    ORDER BY ua.account_name_ar ASC
")->fetchAll();
$branches_accounts = $pdo->query("SELECT id, branch_name as account_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC")->fetchAll();
$suppliers_accounts = $pdo->query("SELECT id, supplier_name, account_id FROM suppliers WHERE deleted_at IS NULL ORDER BY supplier_name ASC")->fetchAll();
function get_accounts_under_parent($pdo, $parent_account_code, $entity_type = null) {
    // جلب معرف الحساب الأب
    $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
    $stmt_parent->execute([$parent_account_code]);
    $parent_id = $stmt_parent->fetchColumn();
    if (!$parent_id) return [];
    
    // جلب الحسابات تحت هذا الأب مع ربطها بالكيانات
    $sql = "SELECT ua.id, ua.account_code, ua.account_name_ar,
                   c.id as customer_id,
                   a.id as agent_id,
                   s.id as supplier_id
            FROM unified_accounts ua
            LEFT JOIN customers c ON c.account_id = ua.id
            LEFT JOIN agents a ON a.account_id = ua.id
            LEFT JOIN suppliers s ON s.account_id = ua.id
            WHERE ua.parent_id = ? AND ua.account_status = 'active'
            ORDER BY ua.account_code ASC";
    $stmt = $pdo->prepare($sql);
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

$customers_entities = get_accounts_under_parent($pdo, '11201', 'customer');
$agents_entities = get_accounts_under_parent($pdo, '11203', 'agent');
$cashboxes_entities = get_accounts_under_parent($pdo, '11101', 'cashbox');
$banks_entities = get_accounts_under_parent($pdo, '11102', 'bank');

// Debugging entities data (commented out
// echo "<pre>Cashboxes: "; var_dump($cashboxes_entities); echo "</pre>";
// echo "<pre>Customers: "; var_dump($customers_entities); echo "</pre>";
// echo "<pre>Banks: "; var_dump($banks_entities); echo "</pre>";
// echo "<pre>Agents: "; var_dump($agents_entities); echo "</pre>";

// الموردين يُحمَّلون تلقائياً داخل financial_fields.php (نفس invoices.php)

// جلب الوكلاء والفروع للمدير والمطور أو من لديه الصلاحية
$stmt_user_details = $pdo->prepare("SELECT u.user_type, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt_user_details->execute([$_SESSION['admin_id']]);
$user_details = $stmt_user_details->fetch();

$is_super_user = in_array($user_details['user_type'], ['admin', 'developer']) || in_array($user_details['role'], ['admin', 'developer']) || has_permission('view_all_transactions');

$pricing_context = get_current_user_pricing_context($pdo);
$can_edit_purchase_price = has_permission('umrah_edit_purchase_price') || can_edit_service_purchase_price($pricing_context);
$can_edit_currency = can_edit_service_currency($pricing_context);
$can_edit_sale_price = has_permission('umrah_show_sale_price') || can_edit_service_sale_price($pricing_context);

$agents = [];
$branches = [];
if ($is_super_user) {
    $agents = $pdo->query("SELECT id, agent_name, 0 as agent_price, 0 as sale_price, NULL as currency_id FROM agents WHERE status = 'active'")->fetchAll();
    $branches = $pdo->query("SELECT id, branch_name, 0 as branch_price, 0 as sale_price, NULL as currency_id FROM branches WHERE status = 'active'")->fetchAll();
} else {
    // جلب بيانات الوكيل/الفرع الخاص بالمستخدم الحالي فقط إذا لم يكن لديه صلاحية العرض الشامل
    if ($currentUser['agent_id']) {
        $stmt = $pdo->prepare("SELECT id, agent_name, 0 as agent_price, 0 as sale_price, NULL as currency_id FROM agents WHERE id = ?");
        $stmt->execute([$currentUser['agent_id']]);
        $agents = $stmt->fetchAll();
    } elseif ($currentUser['branch_id']) {
        $stmt = $pdo->prepare("SELECT id, branch_name, 0 as branch_price, 0 as sale_price, NULL as currency_id FROM branches WHERE id = ? AND status = 'active'");
        $stmt->execute([$currentUser['branch_id']]);
        $branches = $stmt->fetchAll();
    }
}

require_once 'header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-kaaba me-2"></i> <?php echo $page_title; ?></h3>
            <p class="text-muted small">إدارة معاملات الحج والعمليات المالية</p>
        </div>
        <div class="d-flex gap-2">
            <?php if (!empty($_GET['id'])): ?>
                <a href="hajj.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                    <i class="fas fa-times-circle me-1"></i> عرض الكل
                </a>
            <?php endif; ?>
            <?php if (has_permission($permission_prefix . '_create')): ?>
                <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addHajjModal">
                    <i class="fas fa-plus-circle me-1"></i> إضافة حاج جديد
                </button>
            <?php endif; ?>


        </div>
    </div>

    <!-- تنبيهات النجاح والخطأ -->
    <?php if (isset($_GET['posted'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i> تم ترحيل الفاتورة بنجاح محاسبياً.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['reset'])): ?>
        <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4" role="alert">
            <i class="fas fa-info-circle me-2"></i> تم إلغاء الترحيل وإعادة الفاتورة لمسودة.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4" role="alert">
            <i class="fas fa-trash-alt me-2"></i> تم حذف الفاتورة بنجاح.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- كروت الإحصائيات -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-primary border-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-primary-light p-3 rounded-3 text-primary">
                        <i class="fas fa-users fa-2x"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="small text-muted">إجمالي الحجاج</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo count($passports); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- إضافة المزيد من الإحصائيات حسب الحاجة -->
    </div>

    <!-- جدول المعاملات -->
    <div id="umrah_transition_form" class="d-none mb-4">
        <!-- سيتم ملؤه ديناميكياً عند الضغط على انتقال -->
    </div>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i> قائمة المعاملات</h6>
            <div class="d-flex gap-2">
                <input type="text" id="umrahSearch" class="form-control form-control-sm rounded-pill px-3" placeholder="بحث باسم الحاج أو رقم الجواز...">
                <select id="statusFilter" class="form-select form-select-sm rounded-pill px-3" style="width: 150px;">
                    <option value="">كل الحالات</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo $s['status_name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <style>
            .clamp-2 {
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            .finance-mini-card {
                border-radius: 18px;
                padding: 0.75rem 0.85rem;
                border: 1px solid rgba(148, 163, 184, 0.18);
                background: rgba(248, 250, 252, 0.86);
                min-width: 165px;
            }

            body.theme-dark .finance-mini-card {
                background: rgba(30, 41, 59, 0.85);
                border-color: rgba(148, 163, 184, 0.12);
            }

            .finance-mini-card .mini-label {
                font-size: 0.72rem;
                color: var(--text-muted);
                margin-bottom: 0.18rem;
            }

            .finance-mini-card .mini-name {
                font-size: 0.9rem;
                font-weight: 800;
                color: var(--text-bold);
                line-height: 1.5;
                margin-bottom: 0.45rem;
            }

            .finance-mini-card .mini-amount {
                font-size: 1.02rem;
                font-weight: 900;
                color: #2563eb;
            }

            .payment-stack {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem;
                min-width: 160px;
            }

            @media (max-width: 1200px) {
                .payment-stack {
                    grid-template-columns: 1fr;
                }
            }

            .payment-box {
                border-radius: 16px;
                border: 1px solid rgba(148, 163, 184, 0.18);
                background: rgba(248, 250, 252, 0.78);
                padding: 0.65rem 0.75rem;
            }

            body.theme-dark .payment-box {
                background: rgba(30, 41, 59, 0.78);
                border-color: rgba(148, 163, 184, 0.12);
            }

            .payment-box-title {
                font-size: 0.75rem;
                font-weight: 800;
                margin-bottom: 0.35rem;
            }
        </style>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="px-4 py-3">المعاملة / الاسم</th>
                            <th>الجواز</th>
                            <th>التأشيرة / التنبيه</th>
                            <th class="text-center">المورد والشراء</th>
                            <th class="text-center">الحساب والبيع</th>
                            <th class="text-center">حالة الدفع والترحيل</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="umrahTableBody">
                        <?php foreach ($passports as $p): ?>
                            <tr class="umrah-row" data-status="<?php echo $p['status_id']; ?>">
                                <td class="px-4 text-start">
                                    <div class="fw-bold"><?php echo htmlspecialchars($p['full_name'] ?: $p['full_name_en']); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1 font-monospace"><?php echo htmlspecialchars($p['passport_number']); ?></span>
                                    <div class="mt-2">
                                        <span class="badge rounded-pill px-3 py-2 mb-1 shadow-sm" style="background-color: <?php echo htmlspecialchars($p['status_color']); ?>; color: #fff;">
                                            <?php echo htmlspecialchars($p['status_name']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($p['visa_number']): ?>
                                        <div class="small fw-bold font-monospace"><?php echo $p['visa_number']; ?></div>
                                        <?php if (!$p['is_outside_ksa']): ?>
                                            <?php
                                            $expiry_date = !empty($p['visa_expiry_date']) ? $p['visa_expiry_date'] : null;
                                            $days = 0;
                                            $badge_class = 'bg-danger';

                                            if ($expiry_date) {
                                                $now = new DateTime();
                                                $expiry = new DateTime($expiry_date);
                                                $diff = $now->diff($expiry);
                                                $days = $diff->invert ? -$diff->days : $diff->days;

                                                if ($days <= 0) $badge_class = 'bg-danger animate-pulse';
                                                elseif ($days <= $umrah_settings['visa_expiry_alert_days']) $badge_class = 'bg-warning text-dark';
                                                else $badge_class = 'bg-success';
                                            }
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?> small rounded-pill shadow-sm" title="تاريخ الانتهاء: <?php echo $expiry_date; ?>">
                                                <i class="far fa-calendar-times me-1"></i>
                                                متبقي: <?php echo $days; ?> يوم
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill shadow-sm">
                                                <i class="fas fa-plane-departure me-1"></i> خارج المملكة
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small opacity-50"><i class="fas fa-hourglass-start me-1"></i> بانتظار التأشيرة</span>
                                    <?php endif; ?>
                                </td>
                                <?php $currencyMark = trim((string)($p['currency_symbol'] ?? ($p['currency_name'] ?? ''))); ?>
                                <td class="small text-start">
                                    <div class="finance-mini-card">
                                        <div class="mini-label">المورد</div>
                                        <div class="mini-name clamp-2"><?php echo htmlspecialchars(($p['supplier_name'] ?? '') !== '' ? $p['supplier_name'] : '---'); ?></div>
                                        <div class="mini-label">سعر الشراء</div>
                                        <div class="mini-amount" style="color:#dc2626;">
                                            <?php
                                            $purchaseAmount = $p['purchase_invoice_id'] ? (float)($p['purchase_amount'] ?? 0) : 0;
                                            echo $p['purchase_invoice_id'] ? number_format($purchaseAmount, 2) : '---';
                                            echo $currencyMark !== '' && $p['purchase_invoice_id'] ? ' ' . htmlspecialchars($currencyMark) : '';
                                            ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="small text-start">
                                    <?php
                                    $impactedCode = trim((string)($p['invoice_account_code'] ?? ''));
                                    $impactedName = trim((string)($p['invoice_account_name_ar'] ?? ''));
                                    $impactedDisplay = $impactedName !== '' ? $impactedName : '---';
                                    ?>
                                    <div class="finance-mini-card">
                                        <div class="mini-label">الحساب المتأثر</div>
                                        <div class="mini-name clamp-2"><?php echo htmlspecialchars($impactedDisplay); ?></div>
                                        <div class="mini-label">سعر البيع</div>
                                        <div class="mini-amount" style="color:#16a34a;">
                                            <?php
                                            $saleAmount = $p['sales_invoice_id'] ? ((float)($p['sales_amount'] ?? 0) - (float)($p['sales_discount'] ?? 0)) : 0;
                                            echo $p['sales_invoice_id'] ? number_format($saleAmount, 2) : '---';
                                            echo $currencyMark !== '' && $p['sales_invoice_id'] ? ' ' . htmlspecialchars($currencyMark) : '';
                                            ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-start">
                                    <?php
                                    $pay_badges = [
                                        'unpaid' => '<span class="badge bg-danger-subtle text-danger rounded-pill">غير مدفوع</span>',
                                        'partial' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                        'partially_paid' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                        'fully_paid' => '<span class="badge bg-success-subtle text-success rounded-pill">مدفوع بالكامل</span>',
                                        'awaiting_approval' => '<span class="badge bg-info-subtle text-info rounded-pill">بانتظار الاعتماد</span>',
                                        'posted' => '<span class="badge bg-primary-subtle text-primary rounded-pill">مرحل مالياً</span>'
                                    ];
                                    $invoice_badges = [
                                        'draft' => '<span class="badge bg-secondary-subtle text-secondary rounded-pill">مسودة</span>',
                                        'posted' => '<span class="badge bg-primary-subtle text-primary rounded-pill">مرحل</span>',
                                        'cancelled' => '<span class="badge bg-danger-subtle text-danger rounded-pill">ملغي</span>'
                                    ];

                                    $salesNet = (float)($p['sales_amount'] ?? 0) - (float)($p['sales_discount'] ?? 0);
                                    $salesPaid = (float)($p['sales_received'] ?? 0);
                                    $salesPayKey = ($salesNet > 0 && $salesPaid >= $salesNet - 0.01) ? 'fully_paid' : ($salesPaid > 0 ? 'partial' : 'unpaid');

                                    $purNet = (float)($p['purchase_amount'] ?? 0);
                                    $purPaid = (float)($p['purchase_paid'] ?? 0);
                                    $purPayKey = ($purNet > 0 && $purPaid >= $purNet - 0.01) ? 'fully_paid' : ($purPaid > 0 ? 'partial' : 'unpaid');
                                    ?>
                                    <div class="payment-stack">
                                        <div class="payment-box small">
                                            <div class="payment-box-title text-success">البيع</div>
                                            <div class="d-flex flex-wrap gap-1">
                                            <?php echo $pay_badges[$salesPayKey] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                            <?php echo $invoice_badges[$p['sales_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                            </div>
                                        </div>
                                        <div class="payment-box small">
                                            <div class="payment-box-title text-primary">الشراء</div>
                                            <div class="d-flex flex-wrap gap-1">
                                            <?php echo !empty($p['purchase_invoice_id']) ? ($pay_badges[$purPayKey] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : '<span class="badge bg-light text-dark rounded-pill">لا توجد</span>'; ?>
                                            <?php echo !empty($p['purchase_invoice_id']) ? ($invoice_badges[$p['purchase_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : ''; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <!-- عرض التفاصيل -->
                                        <button class="btn btn-sm btn-info text-white shadow-sm view-umrah" data-id="<?php echo $p['id']; ?>" data-tab="info" title="التفاصيل"><i class="fas fa-eye"></i></button>

                                        <?php
                                        $is_any_posted = ($p['sales_status'] == 'posted' || $p['purchase_status'] == 'posted');
                                        ?>

                                        <!-- زر الترحيل (قائمة منسدلة) -->
                                        <?php if (($p['sales_invoice_id'] && $p['sales_status'] == 'draft') || ($p['purchase_invoice_id'] && $p['purchase_status'] == 'draft')): ?>
                                            <div class="dropdown d-inline-block">
                                                <button class="btn btn-sm btn-success text-white shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" title="ترحيل">
                                                    <i class="fas fa-check-double"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                                                    <li>
                                                        <h6 class="dropdown-header small fw-bold">ترحيل محاسبياً (Post)</h6>
                                                    </li>
                                                    <?php if ($p['sales_invoice_id'] && $p['sales_status'] == 'draft' && $p['purchase_invoice_id'] && $p['purchase_status'] == 'draft'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد ترحيل الفواتير" data-confirm-text="هل تريد ترحيل البيع والشراء معاً؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="post_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['sales_invoice_id']; ?>">
                                                                <input type="hidden" name="post_scope" value="all">
                                                                <input type="hidden" name="linked_invoice_id" value="<?php echo $p['purchase_invoice_id']; ?>">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-check-double me-2 text-success"></i>ترحيل الكل</button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if ($p['sales_invoice_id'] && $p['sales_status'] == 'draft'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد ترحيل فاتورة البيع" data-confirm-text="هل تريد ترحيل فاتورة البيع؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="post_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['sales_invoice_id']; ?>">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>ترحيل البيع</button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if ($p['purchase_invoice_id'] && $p['purchase_status'] == 'draft'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد ترحيل فاتورة الشراء" data-confirm-text="هل تريد ترحيل فاتورة الشراء؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="post_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['purchase_invoice_id']; ?>">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-file-invoice me-2 text-warning"></i>ترحيل الشراء</button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <!-- زر الحذف (قائمة منسدلة) - يظهر إذا كانت أي فاتورة مسودة -->
                                        <?php if (($p['sales_invoice_id'] && $p['sales_status'] == 'draft') || ($p['purchase_invoice_id'] && $p['purchase_status'] == 'draft')): ?>
                                            <div class="dropdown d-inline-block">
                                                <button class="btn btn-sm btn-danger text-white shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" title="حذف">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                                                    <li>
                                                        <h6 class="dropdown-header small fw-bold">خيارات الحذف</h6>
                                                    </li>
                                                    <?php if ($p['sales_invoice_id'] && $p['purchase_invoice_id'] && $p['sales_status'] == 'draft' && $p['purchase_status'] == 'draft'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف الفواتير" data-confirm-text="هل تريد حذف فاتورة البيع والشراء معاً؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="delete_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['sales_invoice_id']; ?>">
                                                                <input type="hidden" name="delete_scope" value="both">
                                                                <input type="hidden" name="linked_id" value="<?php echo $p['purchase_invoice_id']; ?>">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small text-danger"><i class="fas fa-trash-alt me-2"></i>حذف الكل (الفواتير)</button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if ($p['sales_invoice_id'] && $p['sales_status'] == 'draft'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف فاتورة البيع" data-confirm-text="هل تريد حذف فاتورة البيع؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="delete_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['sales_invoice_id']; ?>">
                                                                <input type="hidden" name="delete_scope" value="self">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-trash me-2 text-primary"></i>حذف فاتورة البيع</button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if ($p['purchase_invoice_id'] && $p['purchase_status'] == 'draft'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف فاتورة الشراء" data-confirm-text="هل تريد حذف فاتورة الشراء؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="delete_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['purchase_invoice_id']; ?>">
                                                                <input type="hidden" name="delete_scope" value="self">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-trash me-2 text-warning"></i>حذف فاتورة الشراء</button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>

                                                    <?php if (has_permission('umrah_delete') && !$is_any_posted): ?>
                                                        <?php if ($p['sales_invoice_id'] || $p['purchase_invoice_id']): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                        <?php endif; ?>
                                                        <li><a class="dropdown-item py-2 small text-danger fw-bold delete-umrah" href="javascript:void(0);" data-id="<?php echo $p['id']; ?>"><i class="fas fa-user-times me-2"></i>حذف المعاملة بالكامل</a></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (has_permission('umrah_edit') && !$is_any_posted): ?>
                                            <button class="btn btn-sm btn-warning text-dark shadow-sm edit-umrah" data-id="<?php echo $p['id']; ?>" title="تعديل"><i class="fas fa-edit"></i></button>
                                        <?php endif; ?>

                                        <!-- زر إلغاء الترحيل (قائمة منسدلة) -->
                                        <?php if (($p['sales_status'] == 'posted') || ($p['purchase_status'] == 'posted')): ?>
                                            <div class="dropdown d-inline-block">
                                                <button class="btn btn-sm btn-warning text-dark shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" title="إلغاء الترحيل">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                                                    <li>
                                                        <h6 class="dropdown-header small fw-bold">إعادة التعيين إلى مسودة (Reset)</h6>
                                                    </li>
                                                    <?php if ($p['sales_status'] == 'posted' && $p['purchase_status'] == 'posted'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء الترحيل" data-confirm-text="هل تريد إلغاء ترحيل البيع والشراء معاً؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="reset_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['sales_invoice_id']; ?>">
                                                                <input type="hidden" name="reset_type" value="all">
                                                                <input type="hidden" name="linked_invoice_id" value="<?php echo $p['purchase_invoice_id']; ?>">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-sync me-2 text-danger"></i>إلغاء ترحيل الكل</button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if ($p['sales_status'] == 'posted'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء ترحيل فاتورة البيع" data-confirm-text="هل تريد إلغاء ترحيل فاتورة البيع؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="reset_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['sales_invoice_id']; ?>">
                                                                <input type="hidden" name="reset_type" value="sales">
                                                                <input type="hidden" name="return_to" value="hajj.php">
                                                                <button type="submit" class="dropdown-item py-2 small"><i class="fas fa-undo me-2 text-warning"></i>إلغاء ترحيل البيع</button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if ($p['purchase_status'] == 'posted'): ?>
                                                        <li>
                                                            <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء ترحيل فاتورة الشراء" data-confirm-text="هل تريد إلغاء ترحيل فاتورة الشراء؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                                <?php echo csrf_input(); ?>
                                                                <input type="hidden" name="invoice_action" value="reset_invoice">
                                                                <input type="hidden" name="invoice_id" value="<?php echo $p['purchase_invoice_id']; ?>">
                                                                <input type="hidden" name="reset_type" value="purchase">
                                                                <input type="hidden" name="return_to" value="hajj.php">
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
                        <?php if (empty($passports)): ?>
                            <tr>
                                <td colspan="9" class="py-5 text-muted">لا توجد معاملات حالياً</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة حاج عمرة متكامل -->
<div class="modal fade" id="addHajjModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="addHajjForm" method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="add_hajj">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-kaaba me-2"></i> إضافة معاملة حج جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- إشعار نوع الخدمة -->
                    <div class="alert alert-custom-info py-2 px-3 mb-2 d-flex align-items-center">
                        <i class="fas fa-info-circle fa-lg me-2"></i>
                        <div>
                            <h6 class="fw-bold mb-0" style="font-size: 0.8rem;">نوع الخدمة: <?php echo htmlspecialchars($hajj_service_name); ?></h6>
                        </div>
                    </div>

                    <input type="hidden" name="customer_id" id="customer_id_hidden">
                    <input type="hidden" name="agent_id" id="agent_id_hidden">
                    <input type="hidden" name="service_id" value="<?php echo (int)$hajj_service_id; ?>">

                    <div class="row g-4">
                        <!-- الأعمدة اليسرى: بيانات الحاج والملاحظات -->
                        <div class="col-lg-6">
                            <!-- القسم 1: بيانات الحاج والجواز -->
                            <div class="form-section-card mb-4">
                                <div class="form-section-header">
                                    <i class="fas fa-id-card"></i>
                                    <h6>1. بيانات الحاج والجواز</h6>
                                </div>
                                <div class="form-section-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">قراءة الجواز (OCR)</label>
                                            <div class="input-group">
                                                <input type="file" name="passport_image" id="passportOCR" class="form-control" accept="image/*">
                                                <button class="btn btn-primary btn-sm" type="button" id="scan_passport_btn"><i class="fas fa-qrcode"></i></button>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">رقم الجواز</label>
                                            <input type="text" name="passport_number" id="ocr_passport" class="form-control font-monospace" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">الاسم (عربي)</label>
                                            <input type="text" name="full_name" id="ocr_name" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">الاسم (EN)</label>
                                            <input type="text" name="full_name_en" id="ocr_name_en" class="form-control font-monospace">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">الجنسية</label>
                                            <select name="nationality" id="ocr_nationality" class="form-select">
                                                <option value="">اختر...</option>
                                                <?php foreach ($all_countries as $country): ?>
                                                    <option value="<?php echo htmlspecialchars($country['country_name']); ?>" data-code="<?php echo htmlspecialchars($country['country_code']); ?>"><?php echo htmlspecialchars($country['country_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">الجنس</label>
                                            <select name="gender" id="ocr_gender" class="form-select">
                                                <option value="">اختر...</option>
                                                <option value="Male">ذكر</option>
                                                <option value="Female">أنثى</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">تاريخ الميلاد</label>
                                            <input type="date" name="date_of_birth" id="ocr_dob" class="form-control">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-danger">انتهاء الجواز</label>
                                            <input type="date" name="passport_expiry_date" id="ocr_expiry" class="form-control border-danger">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">رقم التواصل</label>
                                            <input type="text" name="phone_number" class="form-control" placeholder="00966...">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section-card">
                                <div class="form-section-header">
                                    <i class="fas fa-notes-medical text-info"></i>
                                    <h6>2. الملاحظات</h6>
                                </div>
                                <div class="form-section-body">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">ملاحظات سريعة</label>
                                            <input type="text" name="notes" class="form-control" placeholder="ملاحظات إضافية...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- العمود الأيمن: البيانات المالية والفاتورة -->
                        <div class="col-lg-6">
                            <?php
                            // إعداد بيانات الفاتورة الحالية
                            $current_invoice = [
                                'invoice_date' => normalize_datetime_db(null),
                                'branch_id' => (count($branches) === 1) ? $branches[0]['id'] : null,
                                'source_type' => $hajj_service_name,
                                'delivery_type' => $settings['default_delivery_type'] ?? 'draft',
                                'total_amount' => 0,
                                'discount' => 0,
                                'cost_amount' => 0,
                                'amount_received' => 0,
                                'currency_id' => 1,
                                'description' => ''
                            ];
                            $financial_fields_select2_parent = '#addHajjModal';
                            $financial_fields_show_service_select = false;
                            $financial_fields_header_layout = 'split_rows';
                            $financial_fields_hide_service_accounts = true;
                            include '../includes/financial_fields.php';
                            ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-rounded btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-rounded btn-success">
                        <i class="fas fa-save me-2"></i> حفظ المعاملة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<style>
    :root {
        --primary-color: #4e73df;
        --secondary-color: #858796;
        --success-color: #1cc88a;
        --info-color: #36b9cc;
        --warning-color: #f6c23e;
        --danger-color: #e74a3b;
        --dark-color: #5a5c69;
        --light-bg: #f8f9fc;
        --card-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }

    body {
        background-color: var(--light-bg);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .bg-primary-light {
        background-color: rgba(78, 115, 223, 0.1);
    }

    .animate-pulse {
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }

        100% {
            opacity: 1;
        }
    }

    #addHajjModal .modal-dialog {
        width: 1100px;
        max-width: 95%;
        margin: 1.5rem auto;
    }

    #viewModal .modal-dialog {
        width: 1300px;
        max-width: 95%;
        margin: 1rem auto;
    }

    #viewModal .modal-body {
        max-height: 85vh;
        overflow-y: auto;
    }

    #addHajjModal .modal-content {
        border: none;
        border-radius: 0.75rem;
        box-shadow: 0 0.5rem 2rem rgba(0, 0, 0, 0.15);
        max-height: 90vh;
        display: flex;
        flex-direction: column;
    }
    
    #addHajjModal .modal-body {
        flex-grow: 1;
        overflow-y: auto;
    }
    
    #addHajjModal .modal-footer {
        flex-shrink: 0;
    }

    #addHajjModal .modal-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, #224abe 100%);
        color: white;
        padding: 0.4rem 1.5rem;
    }

    #addHajjModal .modal-body {
        padding: 0.5rem 1.25rem;
        background-color: #f8f9fc;
    }

    #addHajjModal .form-section-card {
        margin-bottom: 0.4rem;
        border: 1px solid #e3e6f0;
        border-radius: 0.5rem;
        background: #fff;
    }

    #addHajjModal .form-section-header {
        padding: 0.25rem 0.75rem;
        background-color: #f8f9fc;
        border-bottom: 1px solid #e3e6f0;
        border-radius: 0.5rem 0.5rem 0 0;
    }

    #addHajjModal .form-section-header i {
        font-size: 0.8rem;
        color: var(--primary-color);
        margin-left: 0.4rem;
    }

    #addHajjModal .form-section-header h6 {
        font-size: 0.9rem;
        font-weight: 700;
        margin-bottom: 0;
    }

    #addHajjModal .form-section-body {
        padding: 0.6rem 1rem;
    }

    #addHajjModal .form-label {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.2rem;
        font-size: 0.85rem;
    }

    #addHajjModal .form-control,
    #addHajjModal .form-select {
        padding: 0.4rem 0.75rem;
        font-size: 0.9rem;
        border-radius: 0.4rem;
        height: auto;
    }

    #addHajjModal .input-group-text {
        padding: 0.4rem 0.75rem;
        font-size: 0.85rem;
    }

    #addHajjModal .btn-sm {
        padding: 0.15rem 0.4rem;
        font-size: 0.75rem;
    }

    #addHajjModal .modal-footer {
        padding: 0.3rem 1.5rem;
    }

    /* تحسينات الـ Modal */
    .modal-content {
        border: none;
        border-radius: 1.25rem;
        box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, #224abe 100%);
        color: white;
        border-bottom: none;
        padding: 1.5rem;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-footer {
        background-color: var(--light-bg);
        border-top: 1px solid #e3e6f0;
        padding: 1.25rem;
    }

    /* تحسينات الكروت داخل الـ Modal */
    .form-section-card {
        border: 1px solid #e3e6f0;
        border-radius: 1rem;
        margin-bottom: 1.5rem;
        transition: all 0.3s ease;
        background: #fff;
    }

    .form-section-card:hover {
        box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.05);
    }

    .form-section-header {
        padding: 0.5rem 1rem;
        background-color: #f8f9fc;
        border-bottom: 1px solid #e3e6f0;
        border-radius: 1rem 1rem 0 0;
        display: flex;
        align-items: center;
    }

    .form-section-header i {
        font-size: 1.2rem;
        margin-left: 0.75rem;
        color: var(--primary-color);
    }

    .form-section-header h6 {
        margin-bottom: 0;
        font-weight: 700;
        color: var(--dark-color);
    }

    .form-section-body {
        padding: 0.75rem 1rem;
    }

    /* تحسينات الحقول */
    .form-label {
        font-weight: 600;
        color: #4e73df;
        margin-bottom: 0.4rem;
        font-size: 0.9rem;
    }

    .form-control,
    .form-select {
        border: 1px solid #d1d3e2;
        border-radius: 0.5rem;
        padding: 0.6rem 1rem;
        font-size: 0.95rem;
        transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #bac8f3;
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.15);
        background-color: #fff;
    }

    .input-group-text {
        background-color: #f8f9fc;
        border: 1px solid #d1d3e2;
        color: var(--primary-color);
        border-radius: 0.5rem;
    }

    .apple-input {
        border: 1px solid #d1d3e2;
        border-radius: 0.6rem;
        padding: 0.65rem 1rem;
        background-color: #fff;
    }

    .apple-input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.1);
    }

    /* تحسينات الأزرار */
    .btn-rounded {
        border-radius: 50rem;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }

    .btn-primary:hover {
        background-color: #2e59d9;
        border-color: #2653d4;
        transform: translateY(-1px);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }

    .btn-success {
        background-color: var(--success-color);
        border-color: var(--success-color);
    }

    .btn-success:hover {
        background-color: #17a673;
        border-color: #169b6b;
        transform: translateY(-1px);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }

    /* تنبيهات مخصصة */
    .alert-custom-info {
        background-color: #eef2ff;
        border-right: 5px solid var(--primary-color);
        color: #3730a3;
        border-radius: 0.75rem;
    }

    /* الخطوط والأرقام */
    .font-monospace {
        font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace !important;
        letter-spacing: 0.5px;
    }

    .extra-small {
        font-size: 0.75rem;
    }

    /* تحسينات الجدول */
    .table thead th {
        background-color: #f8f9fc;
        color: var(--dark-color);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.8rem;
        border-top: none;
    }

    .umrah-row {
        transition: background-color 0.2s;
    }

    .umrah-row:hover {
        background-color: rgba(78, 115, 223, 0.03);
    }

    /* أيقونات ملونة */
    .text-icon-primary {
        color: var(--primary-color);
    }

    .text-icon-success {
        color: var(--success-color);
    }

    .text-icon-warning {
        color: var(--warning-color);
    }

    .text-icon-danger {
        color: var(--danger-color);
    }

    /* Dark mode styles for Umrah modal */
    :root {
        --text-main: #212529;
        --text-bold: #000000;
        --text-muted: #5f6368;
        --card-border: rgba(0, 0, 0, .125);
        --bg-light: #f8f9fa;
        --card-bg: #ffffff;
    }

    body.theme-dark {
        --text-main: #e2e8f0;
        --text-bold: #ffffff;
        --text-muted: #94a3b8;
        --card-border: rgba(255, 255, 255, .1);
        --bg-light: #1e293b;
        --card-bg: #111827;
    }

    body {
        color: var(--text-main) !important;
    }

    .modal-content {
        color: var(--text-main) !important;
        background-color: var(--card-bg) !important;
        border-color: var(--card-border) !important;
    }

    .fw-bold {
        font-weight: 700 !important;
        color: var(--text-bold) !important;
    }

    .text-muted {
        color: var(--text-muted) !important;
    }

    .card, .form-section-card {
        background-color: var(--card-bg) !important;
        border-color: var(--card-border) !important;
    }

    .form-label {
        color: var(--text-bold) !important;
        font-weight: 700;
    }

    .form-control, .form-select {
        background-color: var(--bg-light) !important;
        border-color: var(--card-border) !important;
        color: var(--text-main) !important;
    }

    .form-control:focus, .form-select:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
    }

    .form-section-header {
        background-color: var(--bg-light) !important;
        border-bottom-color: var(--card-border) !important;
    }

    .form-section-header h6 {
        color: var(--text-bold) !important;
    }

    body.theme-dark .modal-header {
        border-bottom-color: var(--card-border) !important;
    }

    body.theme-dark .modal-footer {
        border-top-color: var(--card-border) !important;
        background-color: var(--bg-light) !important;
    }

    body.theme-dark .bg-light {
        background-color: var(--bg-light) !important;
    }
    body.theme-dark #addHajjModal .modal-content {
        background-color: #1a2234;
        border: 1px solid #2d3748;
        color: #e2e8f0;
    }

    body.theme-dark #addHajjModal .modal-header {
        background: linear-gradient(135deg, #2e59d9 0%, #1e3a8a 100%);
        border-bottom: 1px solid #2d3748;
    }

    body.theme-dark #addHajjModal .modal-body {
        background-color: #1a2234;
    }

    body.theme-dark #addHajjModal .form-section-card {
        background-color: #2d3748;
        border-color: #4a5568;
    }

    body.theme-dark #addHajjModal .form-section-header {
        background-color: #1f2937;
        border-bottom-color: #4a5568;
    }

    body.theme-dark #addHajjModal .form-section-header i {
        color: #60a5fa;
    }

    body.theme-dark #addHajjModal .form-section-header h6 {
        color: #e2e8f0;
    }

    body.theme-dark #addHajjModal .form-label {
        color: #60a5fa;
    }

    body.theme-dark #addHajjModal .form-control,
    body.theme-dark #addHajjModal .form-select {
        background-color: #1f2937;
        border-color: #4a5568;
        color: #e2e8f0;
    }

    body.theme-dark #addHajjModal .form-control:focus,
    body.theme-dark #addHajjModal .form-select:focus {
        background-color: #1f2937;
        border-color: #3b82f6;
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
    }

    body.theme-dark #addHajjModal .input-group-text {
        background-color: #1f2937;
        border-color: #4a5568;
        color: #60a5fa;
    }

    body.theme-dark #addHajjModal .modal-footer {
        background-color: #1f2937;
        border-top: 1px solid #2d3748;
    }

    body.theme-dark #account_balance_info,
    body.theme-dark #supplier_balance_info {
        background-color: #2d3748 !important;
        border-color: #4a5568 !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark #exchange_rate_container {
        background-color: #1f2937 !important;
        border-color: #4a5568 !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark #equivalent_cost_display {
        background-color: transparent !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark .alert-custom-info,
    body.dark-mode .alert-custom-info {
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-right-color: #3b82f6 !important;
        color: #93c5fd !important;
    }
</style>

<!-- Modal تغيير الحالة -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-sync me-2"></i> تغيير حالة المعاملة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="statusForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" id="status_passport_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الحالة الجديدة</label>
                        <select name="status_id" id="new_status_id" class="form-select rounded-3" required onchange="toggleVisaFields(this.value)">
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo $s['status_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="visa_fields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-bold">رقم التأشيرة</label>
                            <input type="text" name="visa_number" class="form-control rounded-3">
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">تاريخ الإصدار</label>
                                <input type="date" name="visa_issue_date" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">تاريخ الانتهاء</label>
                                <input type="date" name="visa_expiry_date" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>

                    <div id="outside_ksa_field" class="form-check form-switch mt-3" style="display:none;">
                        <input class="form-check-input" type="checkbox" name="is_outside_ksa" id="is_outside_ksa_check">
                        <label class="form-check-label fw-bold" for="is_outside_ksa_check">الحاج خارج المملكة حالياً (إيقاف التنبيهات)</label>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-success w-100 rounded-pill fw-bold py-2 shadow">تحديث الحالة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal عرض التفاصيل -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-info text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-eye me-2"></i> تفاصيل معاملة الحج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div id="viewModalContent" class="modal-body p-0">
                <!-- سيتم تحميل المحتوى عبر AJAX -->
                <div class="text-center py-5">
                    <div class="spinner-border text-info" role="status"></div>
                    <div class="mt-2 text-muted">جاري تحميل البيانات...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal تعديل حاج -->
<div class="modal fade" id="editUmrahModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form id="editUmrahForm" method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="update_traveler">
                <input type="hidden" name="id" id="edit_passport_id">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات الحاج</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div id="editUmrahModalBody" class="modal-body p-3" style="max-height: 85vh; overflow-y: auto;">
                    <!-- سيتم تحميل المحتوى هنا -->
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="mt-2 text-muted">جاري تحميل بيانات الحاج...</div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 fw-bold border shadow-sm" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow"><i class="fas fa-save me-2"></i> حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
    // دالة إظهار حقول التأشيرة حسب الحالة

    const cashAccounts = <?php echo json_encode($cash_accounts); ?>;
    const bankAccounts = <?php echo json_encode($bank_accounts); ?>;
    const customerAccounts = <?php echo json_encode($customer_accounts); ?>;
    const agentsAccounts = <?php echo json_encode($agents_accounts); ?>;

    const umrahServicePrices = <?php echo json_encode($hajj_service_prices); ?>;
    const umrahBranchId = <?php echo json_encode($currentUser['branch_id']); ?>;
    const umrahAgentId = <?php echo json_encode($currentUser['agent_id'] ?? 'null'); ?>;
    
    // Function to find the best matching service price
    function findUmrahServicePrice(customerId = null, supplierId = null) {
        let bestMatch = null;
        
        for (let price of umrahServicePrices) {
            let matchScore = 0;
            let valid = true;
            
            // Check customer
            if (price.customer_id !== null && customerId !== price.customer_id) {
                valid = false;
            } else if (price.customer_id !== null && customerId === price.customer_id) {
                matchScore += 100; // Highest priority
            }
            
            // Check supplier
            if (price.supplier_id !== null && supplierId !== price.supplier_id) {
                valid = false;
            } else if (price.supplier_id !== null && supplierId === price.supplier_id) {
                matchScore += 90;
            }
            
            // Check branch
            if (price.branch_id !== null && umrahBranchId !== price.branch_id) {
                valid = false;
            } else if (price.branch_id !== null && umrahBranchId === price.branch_id) {
                matchScore += 80;
            }
            
            // Check agent
            if (price.agent_id !== null && umrahAgentId !== price.agent_id) {
                valid = false;
            } else if (price.agent_id !== null && umrahAgentId === price.agent_id) {
                matchScore += 70;
            }
            
            // Check if global (all null)
            if (price.customer_id === null && price.supplier_id === null && price.branch_id === null && price.agent_id === null) {
                matchScore += 10;
            }
            
            if (valid) {
                if (!bestMatch || matchScore > bestMatch.score) {
                    bestMatch = { ...price, score: matchScore };
                }
            }
        }
        
        return bestMatch;
    }
    
    // Function to update the form fields with the selected service price
    function updateUmrahFormFields(price) {
        const salePriceInput = document.querySelector('input[name="total_amount"]');
        const costPriceInput = document.querySelector('input[name="cost_amount"]');
        const currencySelect = document.querySelector('select[name="currency_id"]');
        
        if (price) {
            if (salePriceInput) salePriceInput.value = price.default_sale_price;
            if (costPriceInput) costPriceInput.value = (price.agent_price || price.branch_price || 0);
            if (currencySelect) currencySelect.value = price.currency_id;
            
            // Trigger input events to update any related calculations
            if (salePriceInput) salePriceInput.dispatchEvent(new Event('input'));
            if (costPriceInput) costPriceInput.dispatchEvent(new Event('input'));
            if (currencySelect) currencySelect.dispatchEvent(new Event('change'));
        }
    }
    
    // Event listeners for customer and supplier selects
    function setupUmrahServicePriceListeners() {
        // Find elements
        let supplierSelect = document.querySelector('select[name="supplier_id"]');
        let deliveryTypeSelect = document.getElementById('delivery_type');
        let accountSelect = document.getElementById('account_select');
        
        // Function to get current customer ID from form
        function getCurrentCustomerId() {
            if (!deliveryTypeSelect || deliveryTypeSelect.value !== 'credit') return null;
            if (!accountSelect) return null;
            const selectedOption = accountSelect.options[accountSelect.selectedIndex];
            if (!selectedOption) return null;
            const customerId = selectedOption.dataset.customerId;
            return customerId ? parseInt(customerId) : null;
        }
        
        // Function to update prices
        function updatePrices() {
            const customerId = getCurrentCustomerId();
            const supplierId = supplierSelect ? (supplierSelect.value ? parseInt(supplierSelect.value) : null) : null;
            const price = findUmrahServicePrice(customerId, supplierId);
            updateUmrahFormFields(price);
        }
        
        // Listen to supplier change
        if (supplierSelect) {
            supplierSelect.addEventListener('change', updatePrices);
        }
        
        // Listen to delivery type change
        if (deliveryTypeSelect) {
            deliveryTypeSelect.addEventListener('change', updatePrices);
        }
        
        // Listen to account change
        if (accountSelect) {
            accountSelect.addEventListener('change', updatePrices);
        }
        
        // Initialize with default price
        updatePrices();
    }
    
    // Wait for DOM content loaded and initialize
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(setupUmrahServicePriceListeners, 500); // Give time for other scripts to load
    });

    function toggleVisaFields(statusId) {
        const visaFields = document.getElementById('visa_fields');
        const outsideKsaField = document.getElementById('outside_ksa_field');

        // الحالة 8 هي "تم إصدار التأشيرة"
        visaFields.style.display = (statusId == 8) ? 'block' : 'none';

        // الحالة 13 هي "خارج المملكة"
        outsideKsaField.style.display = (statusId == 13) ? 'block' : 'none';
    }



    // حفظ تغيير الحالة








    // معالجة الـ OCR باستخدام Tesseract.js للتركيز على MRZ

    // #region debug-point A:ocr-helper
    function hajjOcrDebugReport(hypothesisId, msg, data = {}, runId = 'pre-fix') {
        fetch('http://127.0.0.1:7777/event', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                sessionId: 'hajj-ocr',
                runId,
                hypothesisId,
                location: 'admin/hajj.php',
                msg: `[DEBUG] ${msg}`,
                data,
                ts: Date.now()
            })
        }).catch(() => {});
    }
    // #endregion


    // دالة معالجة الصورة (Preprocessing)
    async function preprocessPassportImage(file, angle = 0) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d', {
                        willReadFrequently: true
                    });
                    const scale = 2;
                    const rad = angle * Math.PI / 180;
                    const sin = Math.abs(Math.sin(rad)),
                        cos = Math.abs(Math.cos(rad));
                    canvas.width = (img.width * cos + img.height * sin) * scale;
                    canvas.height = (img.width * sin + img.height * cos) * scale;
                    ctx.scale(scale, scale);
                    ctx.translate(canvas.width / (2 * scale), canvas.height / (2 * scale));
                    ctx.rotate(rad);
                    ctx.drawImage(img, -img.width / 2, -img.height / 2);
                    let imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    let data = imageData.data;
                    for (let i = 0; i < data.length; i += 4) {
                        let avg = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
                        avg = avg < 125 ? 0 : 255;
                        data[i] = data[i + 1] = data[i + 2] = avg;
                    }
                    ctx.putImageData(imageData, 0, 0);
                    const mrzCanvas = document.createElement('canvas');
                    const mrzCtx = mrzCanvas.getContext('2d');
                    mrzCanvas.width = canvas.width;
                    mrzCanvas.height = canvas.height * 0.35;
                    mrzCtx.drawImage(canvas, 0, canvas.height * 0.65, canvas.width, canvas.height * 0.35, 0, 0, mrzCanvas.width, mrzCanvas.height);
                    resolve(mrzCanvas.toDataURL('image/jpeg', 1.0));
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    /**
     * دالة تعبئة الجنس والجنسية
     */
    function fillGenderAndNationality(prefix, mrz2) {
        if (!mrz2 || mrz2.length < 30) return;
        const cleanMRZ2 = mrz2.toUpperCase().trim();

        // 1. معالجة الجنس (الحرف 21 - الفهرس 20)
        const genderChar = cleanMRZ2.substring(20, 21);
        const genderSelect = document.getElementById(prefix + '_gender');
        if (genderSelect && (!genderSelect.value || genderSelect.value === '')) {
            if (genderChar === 'M') genderSelect.value = 'Male';
            else if (genderChar === 'F') genderSelect.value = 'Female';

            if (genderSelect.value) {
                genderSelect.classList.add('animate__animated', 'animate__flash', 'bg-warning-subtle');
                setTimeout(() => genderSelect.classList.remove('bg-warning-subtle'), 2000);
            }
        }

        // 2. معالجة الجنسية (الأحرف 11 إلى 13 - الفهرس 10 إلى 13) باستخدام data-code
        const natCode = cleanMRZ2.substring(10, 13).replace(/</g, '');
        const natSelect = document.getElementById(prefix + '_nationality');
        if (natSelect && (!natSelect.value || natSelect.value === '')) {
            let found = false;
            for (let i = 0; i < natSelect.options.length; i++) {
                const opt = natSelect.options[i];
                if (opt.dataset.code && opt.dataset.code.toUpperCase() === natCode) {
                    natSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }

            if (found) {
                natSelect.classList.add('animate__animated', 'animate__flash', 'bg-warning-subtle');
                setTimeout(() => natSelect.classList.remove('bg-warning-subtle'), 2000);
            }
        }
    }



    // دالة التعريب الصوتي
    function transliterateEnToAr(text) {
        if (!text) return '';
        let result = text.toUpperCase().replace(/</g, ' ').replace(/\s+/g, ' ').trim();

        const dictionary = {
            'MOHAMMED': 'محمد',
            'MOHAMAD': 'محمد',
            'MUHAMMAD': 'محمد',
            'MOHD': 'محمد',
            'MOHAMED': 'محمد',
            'ABDULLAH': 'عبدالله',
            'ABDUL': 'عبد',
            'AHMED': 'أحمد',
            'AHMAD': 'أحمد',
            'ALI': 'علي',
            'HASSAN': 'حسن',
            'HASAN': 'حسن',
            'HUSSEIN': 'حسين',
            'HUSEIN': 'حسين',
            'OMAR': 'عمر',
            'OTHMAN': 'عثمان',
            'OSMAN': 'عثمان',
            'ABO': 'أبو',
            'ABU': 'أبو',
            'AL': 'ال',
            'BIN': 'بن',
            'SAID': 'سعيد',
            'SALEH': 'صالح',
            'IBRAHIM': 'إبراهيم',
            'EBRANIM': 'إبراهيم',
            'ISMAIL': 'إسماعيل',
            'YOUSEF': 'يوسف',
            'JOSEPH': 'يوسف',
            'YOUSIF': 'يوسف',
            'KHALID': 'خالد',
            'MAHMOUD': 'محمود',
            'MUSTAFA': 'مصطفى',
            'MANSOUR': 'منصور',
            'NASSER': 'ناصر',
            'HAMAD': 'حمد',
            'SALEM': 'سالم',
            'JABER': 'جابر',
            'SAMI': 'سامي',
            'FAISAL': 'فيصل',
            'NAIF': 'نايف',
            'BADER': 'بدر',
            'FAHAD': 'فهد',
            'ZIDAN': 'زيدان',
            'AMMAR': 'عمار',
            'YASSER': 'ياسر',
            'JAMAL': 'جمال',
            'ANWAR': 'أنور',
            'MUSA': 'موسى',
            'ISA': 'عيسى',
            'SULAIMAN': 'سليمان',
            'SOLIMAN': 'سليمان',
            'DAWOOD': 'داود',
            'RAHMAN': 'رحمن',
            'RAHIM': 'رحيم',
            'AZIZ': 'عزيز',
            'RAIMI': 'الريمي',
            'ALRAIMI': 'الريمي'
        };

        const phonetics = [{
                en: 'AL ',
                ar: 'ال'
            }, {
                en: 'SH',
                ar: 'ش'
            }, {
                en: 'KH',
                ar: 'خ'
            }, {
                en: 'TH',
                ar: 'ث'
            },
            {
                en: 'GH',
                ar: 'غ'
            }, {
                en: 'PH',
                ar: 'ف'
            }, {
                en: 'CH',
                ar: 'ش'
            }, {
                en: 'EE',
                ar: 'ي'
            },
            {
                en: 'OO',
                ar: 'و'
            }, {
                en: 'OU',
                ar: 'و'
            }, {
                en: 'AA',
                ar: 'ا'
            }, {
                en: 'AY',
                ar: 'ي'
            },
            {
                en: 'EY',
                ar: 'ي'
            }, {
                en: 'IE',
                ar: 'ي'
            }, {
                en: 'QU',
                ar: 'كو'
            }, {
                en: 'CK',
                ar: 'ك'
            }
        ];

        const charMap = {
            'A': 'ا',
            'B': 'ب',
            'C': 'ك',
            'D': 'د',
            'E': 'ي',
            'F': 'ف',
            'G': 'ج',
            'H': 'ه',
            'I': 'ي',
            'J': 'ج',
            'K': 'ك',
            'L': 'ل',
            'M': 'م',
            'N': 'ن',
            'O': 'و',
            'P': 'ب',
            'Q': 'ق',
            'R': 'ر',
            'S': 'س',
            'T': 'ت',
            'U': 'و',
            'V': 'ف',
            'W': 'و',
            'X': 'كس',
            'Y': 'ي',
            'Z': 'ز'
        };

        let words = result.split(' ');
        let arabicWords = words.map(word => {
            if (dictionary[word]) return dictionary[word];
            let pWord = word;
            phonetics.forEach(p => {
                pWord = pWord.replace(new RegExp(p.en, 'g'), p.ar);
            });
            let ar = "";
            for (let i = 0; i < pWord.length; i++) {
                const char = pWord[i];
                if (/[\u0600-\u06FF]/.test(char)) ar += char;
                else ar += (charMap[char] || char);
            }
            return ar;
        });

        return arabicWords.join(' ').replace(/\s+/g, ' ').replace(/ال /g, 'ال').replace(/اا/g, 'ا').replace(/وو/g, 'و').replace(/يي/g, 'ي').trim();
    }

    // دالة تحليل MRZ (TD3 Standard)
    function parseMRZ(text) {
        if (!text) return null;

        let rawText = text.toUpperCase().replace(/K/g, '<');
        const lines = rawText.split('\n')
            .map(l => l.replace(/[^A-Z0-9<]/g, '').trim())
            .filter(l => l.length >= 40);

        let l1 = lines.find(l => l.startsWith('P<'));
        let l2 = lines.find(l => !l.startsWith('P<') && l.length >= 40);

        if (!l1 || !l2) return null;

        l1 = l1.substring(0, 44);
        l2 = l2.substring(0, 44);

        try {
            const data = {
                mrz_line_1: l1,
                mrz_line_2: l2,
                ocr_raw_text: text,
                country_code: l1.substring(2, 5).replace(/</g, ''),
                nationality_code: l2.substring(10, 13).replace(/</g, '')
            };

            // 1. معالجة رقم الجواز
            let passportNum = l2.substring(0, 9).replace(/</g, '').trim();
            if (data.nationality_code === 'YEM' || data.country_code === 'YEM') {
                passportNum = passportNum.replace(/[^0-9]/g, '');
            } else {
                passportNum = passportNum.replace(/[^A-Z0-9]/g, '');
            }
            data.passport_number = passportNum;

            // 2. معالجة الاسم (SURNAME<<GIVEN<NAMES)
            let namePart = l1.substring(5);
            const nameSplit = namePart.split('<<');
            if (nameSplit.length >= 2) {
                let surname = nameSplit[0].replace(/</g, ' ').trim();
                let givenNamesRaw = nameSplit.slice(1).join(' ');
                let givenNames = givenNamesRaw.replace(/<+/g, ' ').trim();
                data.full_name_en = (givenNames + ' ' + surname).replace(/\s+/g, ' ').trim();
            } else {
                data.full_name_en = namePart.replace(/<+/g, ' ').trim();
            }
            data.full_name = transliterateEnToAr(data.full_name_en);

            // 3. معالجة التواريخ
            const convertDate = (str, isDOB = false) => {
                if (!/^\d{6}$/.test(str)) return "";
                let yy = parseInt(str.substring(0, 2));
                let mm = str.substring(2, 4);
                let dd = str.substring(4, 6);
                let yearPrefix = "20";
                if (isDOB) {
                    const currentYY = new Date().getFullYear() % 100;
                    if (yy > currentYY + 10) yearPrefix = "19";
                }
                return `${yearPrefix}${yy}-${mm}-${dd}`;
            };

            data.date_of_birth = convertDate(l2.substring(13, 19), true);
            data.passport_expiry_date = convertDate(l2.substring(21, 27), false);

            return data;
        } catch (e) {
            return null;
        }
    }

    // دالة استخراج سطر MRZ
    function extractMRZ(text) {
        if (!text) return null;
        const lines = text.toUpperCase().split('\n').map(l => l.replace(/[^A-Z0-9<]/g, '').trim()).filter(l => l.length >= 40);
        // Find lines that look like MRZ. MRZ lines are typically 44 chars long for TD3
        // We are looking for two lines.
        const mrzLines = lines.filter(l => l.length === 44);
        if (mrzLines.length >= 2) {
            return mrzLines[0] + '\n' + mrzLines[1];
        }
        return null;
    }


    $(document).ready(function() {
        // حفظ المعاملة
        $('#addHajjForm').off('submit.umrahAdd').on('submit.umrahAdd', function(e) {
            e.preventDefault();

            const recordVal = $('#record_purchase').val();
            if (recordVal === null || recordVal === '') {
                alert('يرجى اختيار ما إذا كنت تريد تسجيل مديونية للمورد أم لا.');
                $('#record_purchase').focus();
                return;
            }
            if (window.validateDiscount && !window.validateDiscount()) {
                alert('عفواً! لا يمكن حفظ المعاملة لأن السعر بعد الخصم أقل من سعر التكلفة.');
                $('#discount').focus();
                return;
            }

            const formData = new FormData(this);
            formData.append('action', 'add_hajj'); // Ensure the action is set

            // Append CSRF token if available
            const csrfToken = $('input[name="csrf_token"]').val();
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            $.ajax({
                url: 'ajax_hajj.php',
                type: 'POST',
                data: formData,
                processData: false, // Important for FormData
                contentType: false, // Important for FormData
                dataType: 'json',
                beforeSend: function() {
                    $('#addHajjModal .modal-footer button[type="submit"]').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'نجاح!',
                            text: response.message,
                            showConfirmButton: false,
                            timer: 1500
                        }).then(() => {
                            $('#addHajjModal').modal('hide');
                            location.reload(); // Reload the page to show updated list
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'خطأ!',
                            text: response.message || 'حدث خطأ أثناء حفظ المعاملة.'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'خطأ في الاتصال!',
                        text: 'تعذر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.'
                    });
                    console.error('AJAX Error:', status, error);
                },
                complete: function() {
                    $('#addHajjModal .modal-footer button[type="submit"]').prop('disabled', false);
                }
            });
        });

    });
</script>

<script>
    const CSRF_TOKEN = <?php echo json_encode(generate_csrf_token()); ?>;
    const nativeFetch = window.fetch.bind(window);
    window.fetch = (resource, options = {}) => {
        const url = typeof resource === 'string' ? resource : resource.url;
        const method = (options.method || 'GET').toUpperCase();
        if (url && url.includes('ajax_hajj.php') && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            options.headers = new Headers(options.headers || {});
            options.headers.set('X-CSRF-Token', CSRF_TOKEN);
        }
        return nativeFetch(resource, options);
    };
    const Umrah = {
        // دالة تحضير الانتقال (عرض الحقول المطلوبة وقائمة التحقق)
        prepareTransition: function(id, trans) {
            // البحث عن النموذج في المودال أولاً ثم في الصفحة الرئيسية
            let form = $('#viewModal #umrah_transition_form_modal');
            if (form.length === 0) {
                form = $('#umrah_transition_form');
            }

            // جلب الحقول المطلوبة للمرحلة الجديدة
            $.get('ajax_hajj.php', {
                action: 'get_step_fields',
                step_id: trans.to_step_id
            }, function(res) {
                let fieldsHtml = '';
                if (res.status === 'success' && res.fields && res.fields.length > 0) {
                    fieldsHtml = '<div class="row g-3 mb-3">';
                    res.fields.forEach(field => {
                        const today = new Date().toISOString().split('T')[0];
                        fieldsHtml += `
                            <div class="col-md-6">
                                <label class="extra-small text-muted mb-1 fw-bold">${field.label}:</label>
                                <input type="${field.type}" name="${field.key}" class="form-control form-control-sm rounded-pill extra-field" data-key="${field.key}" ${field.type === 'date' ? `value="${today}"` : ''} required>
                            </div>
                        `;
                    });
                    fieldsHtml += '</div>';
                }

                // أفراد المجموعة (Family Members)
                let membersHtml = '';
                if (window.umrahGroupMembers && window.umrahGroupMembers.length > 1) {
                    membersHtml = `
                        <div class="mb-3 p-3 bg-white border rounded-4 shadow-xs">
                            <h6 class="fw-bold small mb-2 text-info"><i class="fas fa-users me-2"></i> نقل أفراد المجموعة أيضاً:</h6>
                            <div class="row g-2">
                                ${window.umrahGroupMembers.map(member => `
                                    <div class="col-md-6">
                                        <div class="form-check small">
                                            <input class="form-check-input member-check-transition" type="checkbox" value="${member.id}" id="trans_member_${member.id}" ${member.id == id ? 'checked disabled' : 'checked'}>
                                            <label class="form-check-label extra-small" for="trans_member_${member.id}">${member.full_name} ${member.id == id ? '<span class="text-primary">(الحالي)</span>' : ''}</label>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        `;
                }

                // بناء قائمة التحقق (Checklist)
                let checklistHtml = '';
                if (window.umrahChecklist && window.umrahChecklist.length > 0) {
                    checklistHtml = `
                        <div class="mb-3 p-3 bg-warning-subtle rounded-4 border border-warning-subtle">
                            <h6 class="fw-bold small mb-2 text-dark"><i class="fas fa-tasks me-2"></i> قائمة التحقق من الوثائق:</h6>
                            <div class="row g-2">
                        `;
                    window.umrahChecklist.forEach(item => {
                        checklistHtml += `
                            <div class="col-md-6">
                                <div class="form-check form-switch small">
                                    <input class="form-check-input checklist-item" type="checkbox" id="check_${item.requirement_id}"
                                           data-id="${item.requirement_id}" ${item.relayer_verified == 1 ? 'checked' : ''} ${item.relayer_verified == 1 ? 'disabled' : ''}>
                                    <label class="form-check-label extra-small" for="check_${item.requirement_id}">${item.requirement_name}</label>
                                </div>
                            </div>
                            `;
                    });
                    checklistHtml += `
                            </div>
                        </div>
                        `;
                }

                let html = `
                    <div class="p-4 bg-white rounded-4 border-start border-4 border-primary shadow mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                            <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-exchange-alt me-2"></i> الانتقال إلى: ${trans.to_step_name || trans.to_name}</h6>
                            <button type="button" class="btn-close extra-small" onclick="$('#umrah_transition_form, #umrah_transition_form_modal').addClass('d-none'); $('#umrah_transitions_list').removeClass('d-none');"></button>
                        </div>

                        ${membersHtml}
                        ${checklistHtml}
                        ${fieldsHtml}

                        <div class="mb-3">
                            <label class="extra-small text-muted mb-1 fw-bold">ملاحظات الانتقال:</label>
                            <textarea id="trans_notes" class="form-control form-control-sm rounded-3" rows="2" placeholder="أضف ملاحظاتك حول هذه العملية هنا..."></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button class="btn btn-primary rounded-pill py-2 shadow-sm fw-bold" onclick="Umrah.executeTransition(${id}, ${trans.to_step_id})">
                                <i class="fas fa-check-circle me-1"></i> تنفيذ عملية النقل
                            </button>
                            <button class="btn btn-light btn-sm rounded-pill py-2 border" onclick="$('#umrah_transition_form, #umrah_transition_form_modal').addClass('d-none'); $('#umrah_transitions_list').removeClass('d-none');">إلغاء</button>
                        </div>
                    </div>
                `;
                form.html(html).removeClass('d-none');
                $('#umrah_transitions_list').addClass('d-none');

                if (form[0]) {
                    form[0].scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            });
        },

        // تنفيذ الانتقال
        executeTransition: function(id, toStepId) {
            if (!confirm('هل أنت متأكد من نقل المعاملة لهذه المرحلة؟')) return;

            const notes = $('#trans_notes').val();
            const extraData = {};
            $('.extra-field').each(function() {
                extraData[$(this).data('key')] = $(this).val();
            });

            // جمع أفراد المجموعة المختارين
            const passportIds = [];
            // إضافة المعرف الأساسي دائماً
            passportIds.push(id);

            $('.member-check-transition:checked').each(function() {
                const val = $(this).val();
                if (val != id) { // تجنب التكرار
                    passportIds.push(val);
                }
            });

            // جمع حالة الـ Checklist
            const checklist = [];
            $('.checklist-item').each(function() {
                checklist.push({
                    id: $(this).data('id'),
                    verified: $(this).is(':checked') ? 1 : 0
                });
            });

            $.ajax({
                url: 'ajax_hajj.php',
                type: 'POST',
                data: {
                    action: 'process_umrah_transition',
                    passport_id: passportIds,
                    to_step_id: toStepId,
                    notes: notes,
                    extra_data: extraData,
                    checklist: checklist
                },
                dataType: 'json',
                success: function(res) {
                    if (res.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'تم بنجاح',
                            text: res.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('خطأ في العملية', res.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr.responseText);
                    Swal.fire({
                        title: 'خطأ في الخادم (Server Error)',
                        html: `<div class="text-start extra-small bg-light p-2 border rounded" style="max-height:200px; overflow:auto;">
                                    <strong>الحالة:</strong> ${status}<br>
                                    <strong>الخطأ:</strong> ${error}<br>
                                    <hr>
                                    <strong>رد الخادم:</strong><br>
                                    <pre class="m-0" style="white-space: pre-wrap;">${xhr.responseText || 'لا يوجد رد من الخادم'}</pre>
                                   </div>`,
                        icon: 'error'
                    });
                }
            });
        },

        // اعتماد مالي
        approveFinance: function(id) {
            if (!confirm('هل أنت متأكد من اعتماد الحسابات لهذه المعاملة؟')) return;

            $.post('ajax_hajj.php', {
                action: 'approve_finance',
                id: id
            }, function(res) {
                if (res.status === 'success') {
                    Swal.fire('تم بنجاح', res.message, 'success').then(() => {
                        // إعادة فتح المودال وتحديث التبويب المالي
                        Umrah.openDetailsModal(id, 'financial');
                    });
                } else {
                    Swal.fire('خطأ', res.message, 'error');
                }
            }, 'json');
        },

        // ترحيل مالي (إنشاء فاتورة)
        postFinance: function(id) {
            if (!confirm('هل أنت متأكد من الترحيل المالي لهذه المعاملة؟ سيتم إنشاء فاتورة مبيعات وتقييد المبلغ.')) return;

            $.post('ajax_hajj.php', {
                action: 'post_finance',
                id: id,
                csrf_token: CSRF_TOKEN
            }, function(res) {
                if (res.status === 'success') {
                    Swal.fire('تم بنجاح', res.message, 'success').then(() => {
                        Umrah.openDetailsModal(id, 'financial');
                    });
                } else {
                    Swal.fire('خطأ', res.message, 'error');
                }
            }, 'json');
        },

        // فتح مودال التفاصيل (دالة مساعدة للتحديث)
        openDetailsModal: function(id, tab = 'info') {
            const modalEl = document.getElementById('viewModal');
            let modal = bootstrap.Modal.getInstance(modalEl);
            if (!modal) modal = new bootstrap.Modal(modalEl);
            modal.show();

            document.getElementById('viewModalContent').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><div class="mt-2">جاري تحميل البيانات...</div></div>';

            fetch(`ajax_hajj.php?action=view_details&id=${id}`)
                .then(res => {
                    if (!res.ok) throw new Error('خطأ في استجابة الخادم');
                    const contentType = res.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        return res.json().then(payload => {
                            throw new Error(payload.message || 'تعذر تحميل تفاصيل المعاملة');
                        });
                    }
                    return res.text();
                })
                .then(html => {
                    document.getElementById('viewModalContent').innerHTML = html;
                    const tabEl = document.querySelector(`#viewModalContent #${tab}-tab`);
                    if (tabEl) {
                        bootstrap.Tab.getOrCreateInstance(tabEl).show();
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    document.getElementById('viewModalContent').innerHTML = `
                        <div class="alert alert-danger m-3">
                            <h6 class="fw-bold"><i class="fas fa-exclamation-triangle me-2"></i> حدث خطأ أثناء تحميل البيانات</h6>
                            <p class="small mb-0">${err.message}</p>
                            <button class="btn btn-sm btn-outline-danger mt-2" onclick="Umrah.openDetailsModal(${id}, '${tab}')">إعادة المحاولة</button>
                        </div>`;
                });
        },

        // دالة النقل اليدوي للإدارة
        manualTransition: function(id, toStepId, stepName) {
            Swal.fire({
                title: 'تغيير المرحلة يدوياً',
                text: `هل أنت متأكد من نقل المعاملة إلى مرحلة "${stepName}"؟ سيتم تجاوز القواعد المعتادة لسير العمل.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'نعم، انقل الآن',
                cancelButtonText: 'إلغاء',
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'ajax_hajj.php',
                        type: 'POST',
                        data: {
                            action: 'process_umrah_transition',
                            passport_id: id,
                            to_step_id: toStepId,
                            notes: 'تغيير يدوي لمرحلة سير العمل بواسطة الإدارة',
                            csrf_token: CSRF_TOKEN
                        },
                        dataType: 'json',
                        success: function(res) {
                            if (res.status === 'success') {
                                Swal.fire('تم بنجاح', res.message, 'success').then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('خطأ في العملية', res.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', xhr.responseText);
                            Swal.fire({
                                title: 'خطأ في الخادم (Server Error)',
                                html: `<div class="text-start extra-small bg-light p-2 border rounded" style="max-height:200px; overflow:auto;">
                                            <strong>الحالة:</strong> ${status}<br>
                                            <strong>الخطأ:</strong> ${error}<br>
                                            <hr>
                                            <strong>رد الخادم:</strong><br>
                                            <pre class="m-0" style="white-space: pre-wrap;">${xhr.responseText || 'لا يوجد رد من الخادم'}</pre>
                                           </div>`,
                                icon: 'error'
                            });
                        }
                    });
                }
            });
        },

        initEditModal: function(id) {
            console.log('Initializing edit modal for ID:', id);
        },

        // تسديد المبلغ المتبقي (فتح صفحة سند قبض)
        payRemaining: function(id, amount) {
            // يمكن توجيه المستخدم لصفحة إنشاء سند قبض مع تمرير البيانات
            window.open(`receipt_vouchers.php?action=add&source_type=hajj&source_id=${id}&amount=${amount}`, '_blank');
        },

        // سداد مديونية المورد (فتح صفحة سند صرف)
        paySupplier: function(id, amount) {
            window.open(`payment_vouchers.php?action=add&source_type=hajj&source_id=${id}&amount=${amount}`, '_blank');
        },

        // حذف فاتورة
        deleteInvoice: function(id) {
            Swal.fire({
                title: 'هل أنت متأكد؟',
                text: "سيتم حذف الفاتورة وجميع القيود المرتبطة بها!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'نعم، احذفها',
                cancelButtonText: 'إلغاء'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('ajax_hajj.php', {
                        action: 'delete_invoice',
                        id: id,
                        csrf_token: CSRF_TOKEN
                    }, function(res) {
                        if (res.status === 'success') {
                            Swal.fire('تم الحذف!', res.message, 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('خطأ', res.message, 'error');
                        }
                    }, 'json');
                }
            });
        }
    };

    $(document).ready(function() {
        // Handle js-confirm-submit forms with SweetAlert
        $(document).on('submit', 'form.js-confirm-submit', async function(e) {
            if (this.dataset.confirmed === '1') {
                this.dataset.confirmed = '0';
                return true;
            }

            e.preventDefault();
            const ok = await Swal.fire({
                title: this.dataset.confirmTitle || 'تأكيد العملية',
                text: this.dataset.confirmText || 'هل تريد المتابعة؟',
                icon: this.dataset.confirmIcon || 'warning',
                showCancelButton: true,
                confirmButtonText: this.dataset.confirmButton || 'نعم',
                cancelButtonText: this.dataset.cancelButton || 'إلغاء'
            });

            if (ok.isConfirmed) {
                this.dataset.confirmed = '1';
                this.requestSubmit ? this.requestSubmit() : this.submit();
            }

            return false;
        });

        // Attach event listeners

        // فتح مودال تغيير الحالة
        document.querySelectorAll('.status-umrah').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                document.getElementById('status_passport_id').value = id;
                const row = this.closest('tr');
                const currentStatus = row.dataset.status;
                document.getElementById('new_status_id').value = currentStatus;
                toggleVisaFields(currentStatus);

                const modal = new bootstrap.Modal(document.getElementById('statusModal'));
                modal.show();
            });
        });

        // حفظ تغيير الحالة
        document.getElementById('statusForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch('ajax_hajj.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('تم بنجاح', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('خطأ', data.message, 'error');
                    }
                });
        });

        // فتح مودال العرض
        document.querySelectorAll('.view-umrah').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                const tab = this.dataset.tab || 'info';
                Umrah.openDetailsModal(id, tab);
            });
        });

        // فتح مودال التعديل
        document.querySelectorAll('.edit-umrah').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                document.getElementById('edit_passport_id').value = id;
                const modal = new bootstrap.Modal(document.getElementById('editUmrahModal'));
                modal.show();

                // تحميل محتوى المودال عبر AJAX
                fetch(`ajax_hajj.php?action=get_hajj_for_edit&id=${id}`)
                    .then(res => res.text())
                    .then(html => {
                        document.getElementById('editUmrahModalBody').innerHTML = html;
                        // بعد تحميل المحتوى، قم بتهيئة أي سكريبتات jQuery أو دوال أخرى إذا لزم الأمر
                        Umrah.initEditModal(id);
                    });
            });
        });

        // حذف المعاملة
        document.querySelectorAll('.delete-umrah').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                Swal.fire({
                    title: 'هل أنت متأكد؟',
                    text: "سيتم حذف المعاملة وجميع البيانات المرتبطة بها!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'نعم، احذفها',
                    cancelButtonText: 'إلغاء'
                }).then((result) => {
                    if (result.isConfirmed) {
                        fetch('ajax_hajj.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: `action=delete_hajj&id=${id}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.status === 'success') {
                                    Swal.fire('تم الحذف!', data.message, 'success').then(() => location.reload());
                                } else {
                                    Swal.fire('خطأ', data.message, 'error');
                                }
                            });
                    }
                });
            });
        });

        const scanBtn = document.getElementById('scan_passport_btn');
        if (scanBtn) {
            scanBtn.addEventListener('click', async function() {
                const fileInput = document.getElementById('passportOCR');
                // #region debug-point A:ocr-click
                hajjOcrDebugReport('A', 'OCR button clicked', {
                    hasFileInput: !!fileInput,
                    hasFile: !!(fileInput && fileInput.files && fileInput.files[0]),
                    themeDark: document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode')
                });
                // #endregion
                if (!fileInput || !fileInput.files[0]) {
                    // #region debug-point A:ocr-no-file
                    hajjOcrDebugReport('A', 'OCR aborted: no file selected');
                    // #endregion
                    Swal.fire({
                        title: 'تنبيه',
                        text: 'يرجى اختيار صورة الجواز أولاً',
                        icon: 'warning'
                    });
                    return;
                }

                const btn = this;
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> جاري القراءة...';

                Swal.fire({
                    title: 'جاري مسح بصمة الجواز (MRZ)...',
                    html: `<div id="ocr-status">جاري تهيئة المحرك...</div>
                       <div class="progress mt-2"><div id="ocr-progress" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div></div>`,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => Swal.showLoading()
                });

                try {
                    // #region debug-point B:create-worker
                    hajjOcrDebugReport('B', 'Creating Tesseract worker', {
                        fileName: fileInput.files[0]?.name || null,
                        fileType: fileInput.files[0]?.type || null,
                        fileSize: fileInput.files[0]?.size || null
                    });
                    // #endregion
                    const worker = await Tesseract.createWorker('eng', 1, {
                        logger: m => {
                            if (m.status === 'recognizing text') {
                                const p = Math.round(m.progress * 100);
                                const pb = document.getElementById('ocr-progress');
                                if (pb) pb.style.width = p + '%';
                            }
                        }
                    });

                    await worker.setParameters({
                        tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<',
                        tessedit_pageseg_mode: '6'
                    });
                    // #region debug-point B:worker-ready
                    hajjOcrDebugReport('B', 'Tesseract worker ready');
                    // #endregion

                    const angles = [0, -2, 2, -4, 4, -6, 6];
                    let data = null;
                    let finalMRZImage = null;
                    for (const angle of angles) {
                        // #region debug-point C:angle-start
                        hajjOcrDebugReport('C', 'Starting OCR angle attempt', {
                            angle
                        });
                        // #endregion
                        const processedImage = await preprocessPassportImage(fileInput.files[0], angle);
                        const {
                            data: {
                                text,
                                blocks
                            }
                        } = await worker.recognize(processedImage);
                        // #region debug-point C:angle-result
                        hajjOcrDebugReport('C', 'OCR angle result received', {
                            angle,
                            textSample: (text || '').substring(0, 180),
                            blockCount: Array.isArray(blocks) ? blocks.length : null
                        });
                        // #endregion
                        const parsed = parseMRZ(text);
                        // #region debug-point D:parse-result
                        hajjOcrDebugReport('D', 'MRZ parse attempted', {
                            angle,
                            parsed: !!parsed,
                            passportNumber: parsed?.passport_number || null,
                            nationality: parsed?.nationality || null
                        });
                        // #endregion
                        if (parsed) {
                            data = parsed;
                            finalMRZImage = processedImage;
                            break;
                        }
                    }

                    await worker.terminate();
                    // #region debug-point B:worker-terminated
                    hajjOcrDebugReport('B', 'Tesseract worker terminated', {
                        success: !!data
                    });
                    // #endregion

                    if (data) {
                        // تعبئة البيانات في الفورم
                        document.getElementById('ocr_passport').value = data.passport_number || '';

                        if (data.date_of_birth) {
                            document.getElementById('ocr_dob').value = data.date_of_birth;
                        }
                        if (data.passport_expiry_date) {
                            document.getElementById('ocr_expiry').value = data.passport_expiry_date;
                        }
                        if (data.full_name) {
                            document.getElementById('ocr_name').value = data.full_name;
                        }
                        if (data.full_name_en) {
                            document.getElementById('ocr_name_en').value = data.full_name_en;
                        }

                        fillGenderAndNationality('ocr', data.mrz_line_2);
                        $('#ocr_nationality').trigger('change');

                        // عرض الصورة المعالجة إذا وجدت
                        const previewContainer = document.getElementById('ocr_passport_preview');
                        const previewImg = previewContainer.querySelector('img');
                        if (previewImg) previewImg.src = finalMRZImage;
                        previewContainer.classList.remove('d-none');
                        // #region debug-point E:ocr-success
                        hajjOcrDebugReport('E', 'OCR completed successfully', {
                            passportNumber: data.passport_number || null,
                            fullName: data.full_name || null,
                            themeDark: document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode')
                        });
                        // #endregion
                        Swal.fire({
                            title: 'تمت القراءة',
                            text: 'تم استخراج البيانات بنجاح من بصمة الجواز (MRZ)',
                            icon: 'success'
                        });
                    } else {
                        Swal.fire({
                            title: 'تنبيه',
                            text: 'لم يتم العثور على بصمة MRZ صالحة. تأكد من جودة الصورة.',
                            icon: 'warning'
                        });
                        // #region debug-point D:no-valid-mrz
                        hajjOcrDebugReport('D', 'OCR finished without valid MRZ');
                        // #endregion
                    }
                } catch (err) {
                    // #region debug-point E:ocr-error
                    hajjOcrDebugReport('E', 'OCR exception thrown', {
                        name: err?.name || null,
                        message: err?.message || String(err),
                        stack: err?.stack ? String(err.stack).substring(0, 400) : null,
                        themeDark: document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode')
                    });
                    // #endregion
                    Swal.fire({
                        title: 'خطأ',
                        text: 'حدث خطأ غير متوقع أثناء المعالجة.',
                        icon: 'error'
                    });
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            });
        }



        // معالجة إرسال نموذج التعديل
        $('#editUmrahForm').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            formData.append('action', 'update_traveler');

            $.ajax({
                url: 'ajax_hajj.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        Swal.fire('تم بنجاح', response.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('خطأ', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('خطأ', 'حدث خطأ أثناء الاتصال بالخادم', 'error');
                }
            });
        });

        $('#ocr_name').on('input', function() {
            const name = $(this).val().trim();
            if (name) {
                $('#description').val('معاملة تاشيرة عمره للاخ ' + name);
            } else {
                $('#description').val('');
            }
        });

        window.entitiesData = window.entitiesData || {
            cashboxes: <?php echo json_encode($cashboxes_entities); ?>,
            customers: <?php echo json_encode($customers_entities); ?>,
            banks: <?php echo json_encode($banks_entities); ?>,
            agents: <?php echo json_encode($agents_entities); ?>
        };

        function fetchPricing() {
            const serviceId = 4;
            const customerId = $('#customer_id_hidden').val();
            const agentId = $('#agent_id_hidden').val();
            const branchId = $('#branch_id').val();
            const supplierId = $('#supplier_id').val();

            $.ajax({
                url: 'ajax_get_service_price.php',
                data: {
                    service_id: serviceId,
                    customer_id: customerId,
                    agent_id: agentId,
                    branch_id: branchId,
                    supplier_id: supplierId
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#total_amount').val(res.sale_price).attr('data-original-price', res.sale_price).attr('data-service-currency-id', res.currency_id);
                        $('#cost_amount').val(res.purchase_price).attr('data-original-cost', res.purchase_price).attr('data-cost-service-currency-id', res.currency_id);

                        if (res.currency_id) {
                            $('#sale_currency_id').val(res.currency_id);
                            $('#main_currency_id').val(res.currency_id);
                            if (window.updateLogic) window.updateLogic();
                            if (window.updateConvertedPrices) window.updateConvertedPrices();
                        }
                    }
                }
            });
        }

        $(document).on('financialFields:accountChanged financialFields:supplierChanged', fetchPricing);
        $('#branch_id').on('change', fetchPricing);
        fetchPricing();
    });
</script>
</body>

</html>
