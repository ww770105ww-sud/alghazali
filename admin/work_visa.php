<?php
// 1. استدعاء الملفات الأساسية
require_once '../includes/db.php';
require_once '../includes/functions.php';

$page_title = 'تأشيرات العمل'; // Set the page title
require_once 'header.php';
?>
<style>
    /* تحسين وضوح الخطوط في كامل الصفحة والمودالات مع دعم الوضعين */
    :root {
        --text-main: #212529;
        --text-bold: #000000;
        --text-muted: #5f6368;
        --card-border: rgba(0, 0, 0, .125);
        --bg-light: #f8f9fa;
        --card-bg: #ffffff;
    }

    /* تطبيق الإعدادات عند تفعيل الوضع الليلي */
    body.theme-dark {
        --text-main: #e2e8f0;
        --text-bold: #ffffff;
        --text-muted: #94a3b8;
        --card-border: rgba(255, 255, 255, .1);
        --bg-light: #1e293b;
        --card-bg: #111827;
    }

    /* تحسين التباين العام */
    body {
        color: var(--text-main) !important;
    }

    .modal-content {
        color: var(--text-main) !important;
        background-color: var(--card-bg) !important;
    }

    .fw-bold {
        font-weight: 700 !important;
        color: var(--text-bold) !important;
    }

    .text-muted {
        color: var(--text-muted) !important;
    }

    .card {
        background-color: var(--card-bg) !important;
        border: 1px solid var(--card-border) !important;
    }

    .list-group-item {
        color: var(--text-main) !important;
        background-color: transparent !important;
        border-color: var(--card-border) !important;
    }

    .form-label {
        color: var(--text-bold) !important;
        font-weight: 700;
    }

    .badge {
        font-weight: 700;
        padding: 0.5em 0.8em;
    }

    /* تخصيصات مودال التفاصيل */
    #detailsModalContent .fw-bold {
        color: var(--text-bold) !important;
    }

    #detailsModalContent .text-primary {
        color: #3b82f6 !important;
    }

    #detailsModalContent .text-success {
        color: #22c55e !important;
    }

    #detailsModalContent .text-danger {
        color: #ef4444 !important;
    }

    #detailsModalContent .bg-light {
        background-color: var(--bg-light) !important;
        border: 1px solid var(--card-border) !important;
    }

    /* تحسين الجدول في كلا الوضعين */
    .table {
        color: var(--text-main) !important;
    }

    .table thead th {
        background-color: var(--bg-light) !important;
        color: var(--text-bold) !important;
        border-bottom: 2px solid var(--card-border) !important;
    }

    .table tbody td {
        vertical-align: middle;
        border-color: var(--card-border) !important;
    }

    /* تحسين سجل الحركات */
    .timeline-small .fw-bold {
        font-size: 0.95rem;
        color: var(--text-bold) !important;
    }

    .timeline-small .text-muted {
        color: var(--text-muted) !important;
        font-weight: 500;
    }

    /* دعم إضافي للوضع الليلي في عناصر النظام الأخرى */
    body.theme-dark .modal-header {
        border-bottom-color: var(--card-border) !important;
    }

    body.theme-dark .modal-footer {
        border-top-color: var(--card-border) !important;
    }

    body.theme-dark .nav-pills .nav-link {
        color: var(--text-main);
    }

    body.theme-dark .nav-pills .nav-link.active {
        background-color: #3b82f6;
        color: white;
    }

    body.theme-dark .bg-light {
        background-color: var(--bg-light) !important;
    }

    /* تحسين أزرار سير العمل */
    .transition-btn {
        transition: all 0.2s ease;
        border: 2px solid transparent;
    }

    .transition-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .financial-actions-wrap {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        justify-content: center;
        align-items: center;
    }

    .financial-action-btn {
        border: 0;
        border-radius: 999px;
        padding: 0.38rem 0.72rem;
        font-size: 0.72rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        transition: all 0.18s ease;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
    }

    .financial-action-btn:hover:not(.disabled) {
        transform: translateY(-1px);
        box-shadow: 0 6px 14px rgba(15, 23, 42, 0.14);
    }

    .financial-action-btn.fin-post {
        background: rgba(34, 197, 94, 0.12);
        color: #15803d;
    }

    .financial-action-btn.fin-unpost {
        background: rgba(245, 158, 11, 0.16);
        color: #b45309;
    }

    .financial-action-btn.disabled,
    .financial-action-btn:disabled {
        background: rgba(148, 163, 184, 0.18) !important;
        color: #64748b !important;
        box-shadow: none;
        cursor: not-allowed;
        pointer-events: none;
    }

    body.theme-dark .financial-action-btn.fin-post {
        background: rgba(34, 197, 94, 0.18);
        color: #86efac;
    }

    body.theme-dark .financial-action-btn.fin-unpost {
        background: rgba(245, 158, 11, 0.2);
        color: #fcd34d;
    }

    .financial-action-group {
        position: relative;
        display: inline-flex;
    }

    .financial-action-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        inset-inline-end: 0;
        min-width: 210px;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 1rem;
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.16);
        padding: 0.45rem;
        display: none;
        flex-direction: column;
        gap: 0.35rem;
        z-index: 30;
    }

    .financial-action-menu.show {
        display: flex;
    }

    .financial-action-menu-title {
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--text-muted);
        padding: 0.1rem 0.35rem 0.25rem;
    }

    .financial-action-menu-item {
        border: 0;
        border-radius: 0.85rem;
        padding: 0.5rem 0.7rem;
        font-size: 0.74rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.45rem;
        text-align: right;
        background: transparent;
        color: var(--text-main);
        width: 100%;
        transition: all 0.16s ease;
    }

    .financial-action-menu-item:hover:not(.disabled) {
        background: rgba(59, 130, 246, 0.08);
    }

    .financial-action-menu-item.post-option {
        color: #15803d;
        background: rgba(34, 197, 94, 0.08);
    }

    .financial-action-menu-item.unpost-option {
        color: #b45309;
        background: rgba(245, 158, 11, 0.10);
    }

    .financial-action-menu-item.manage-option {
        color: #1d4ed8;
        background: rgba(59, 130, 246, 0.08);
    }

    .financial-action-menu-item.delete-option {
        color: #b91c1c;
        background: rgba(239, 68, 68, 0.08);
    }

    .financial-action-menu-item.disabled {
        opacity: 0.55;
        cursor: not-allowed;
        pointer-events: none;
    }

    .page-toolbar-card {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.96));
        border: 1px solid var(--card-border);
        border-radius: 1.25rem;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
    }

    body.theme-dark .page-toolbar-card {
        background: linear-gradient(135deg, rgba(17, 24, 39, 0.96), rgba(15, 23, 42, 0.96));
    }

    .toolbar-title {
        display: flex;
        align-items: center;
        gap: 0.85rem;
    }

    .toolbar-title-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(34, 197, 94, 0.12);
        color: #16a34a;
        font-size: 1.2rem;
        box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.16);
    }

    body.theme-dark .toolbar-title-icon {
        background: rgba(34, 197, 94, 0.18);
        color: #86efac;
    }

    .toolbar-subtitle {
        font-size: 0.82rem;
        color: var(--text-muted);
        margin-top: 0.2rem;
    }

    .toolbar-form .form-select,
    .toolbar-form .form-control,
    .toolbar-form .input-group-text {
        border: 1px solid var(--card-border) !important;
        box-shadow: none !important;
        background-color: var(--card-bg) !important;
        color: var(--text-main) !important;
    }

    .toolbar-form .input-group-text {
        color: var(--text-muted) !important;
    }

    .workvisa-table-card {
        border: 1px solid var(--card-border);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
    }

    body.theme-dark .workvisa-table-card {
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.98), rgba(2, 6, 23, 0.98));
    }

    .workvisa-table-wrap {
        padding: 0.8rem;
    }

    .workvisa-table-wrap .table {
        direction: rtl;
        border-collapse: separate;
        border-spacing: 0 8px;
        margin-bottom: 0;
    }

    .workvisa-table-wrap .table thead th {
        border: 0 !important;
        padding: 0.9rem 0.8rem;
        font-size: 0.9rem;
        font-weight: 800;
        white-space: nowrap;
        background: transparent !important;
    }

    .workvisa-table-wrap .table tbody td {
        background: rgba(255, 255, 255, 0.78);
        padding: 0.85rem 0.75rem;
        border-top: 1px solid rgba(148, 163, 184, 0.18) !important;
        border-bottom: 1px solid rgba(148, 163, 184, 0.18) !important;
        vertical-align: middle;
    }

    body.theme-dark .workvisa-table-wrap .table tbody td {
        background: rgba(15, 23, 42, 0.9);
        border-top-color: rgba(148, 163, 184, 0.12) !important;
        border-bottom-color: rgba(148, 163, 184, 0.12) !important;
    }

    .workvisa-table-wrap .table tbody tr td:first-child {
        border-inline-start: 1px solid rgba(148, 163, 184, 0.18) !important;
        border-start-start-radius: 18px;
        border-end-start-radius: 18px;
    }

    .workvisa-table-wrap .table tbody tr td:last-child {
        border-inline-end: 1px solid rgba(148, 163, 184, 0.18) !important;
        border-start-end-radius: 18px;
        border-end-end-radius: 18px;
    }

    body.theme-dark .workvisa-table-wrap .table tbody tr td:first-child,
    body.theme-dark .workvisa-table-wrap .table tbody tr td:last-child {
        border-color: rgba(148, 163, 184, 0.12) !important;
    }

    .workvisa-table-wrap .table tbody tr {
        transition: transform 0.16s ease, box-shadow 0.16s ease;
    }

    .workvisa-table-wrap .table tbody tr:hover {
        transform: translateY(-1px);
    }

    .clamp-1,
    .clamp-2 {
        overflow: hidden;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        word-break: break-word;
    }

    .clamp-1 {
        -webkit-line-clamp: 1;
    }

    .clamp-2 {
        -webkit-line-clamp: 2;
    }

    .person-cell .person-name {
        font-size: 1rem;
        font-weight: 800;
        color: var(--text-bold);
        line-height: 1.45;
    }

    .person-cell .person-name-en {
        font-size: 0.78rem;
        color: #60a5fa;
        margin-top: 0.22rem;
        letter-spacing: 0.02em;
    }

    .person-cell .person-meta {
        font-size: 0.76rem;
        color: var(--text-muted);
        margin-top: 0.35rem;
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

    .status-stack,
    .payment-stack {
        min-width: 160px;
    }

    .status-stack .badge,
    .payment-stack .badge {
        font-size: 0.73rem;
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
        margin-bottom: 0.4rem;
    }

    .record-date {
        white-space: nowrap;
        font-weight: 700;
        color: var(--text-muted);
    }

    .payment-stack {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }

    .attachment-profession-card {
        min-width: 170px;
        border-radius: 18px;
        padding: 0.75rem 0.85rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(248, 250, 252, 0.86);
    }

    body.theme-dark .attachment-profession-card {
        background: rgba(30, 41, 59, 0.85);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .attachment-profession-card .mini-label {
        font-size: 0.72rem;
        color: var(--text-muted);
        margin-bottom: 0.25rem;
    }

    .attachment-icons-row {
        display: flex;
        justify-content: center;
        gap: 0.45rem;
        margin-bottom: 0.55rem;
    }

    .attachment-icon-chip {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.9);
        color: #94a3b8;
    }

    body.theme-dark .attachment-icon-chip {
        background: rgba(15, 23, 42, 0.75);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .attachment-icon-chip.is-filled {
        color: #16a34a;
        background: rgba(22, 163, 74, 0.12);
        border-color: rgba(22, 163, 74, 0.22);
    }

    .profession-badge-card {
        border-radius: 14px;
        padding: 0.45rem 0.6rem;
        background: rgba(59, 130, 246, 0.08);
        border: 1px solid rgba(59, 130, 246, 0.14);
        color: var(--text-bold);
        font-size: 0.83rem;
        font-weight: 800;
        line-height: 1.5;
        text-align: center;
    }

    body.theme-dark .profession-badge-card {
        background: rgba(59, 130, 246, 0.12);
        border-color: rgba(96, 165, 250, 0.18);
    }

    @media (max-width: 1200px) {
        .payment-stack {
            grid-template-columns: 1fr;
        }
    }
</style>
<?php

// 2. التحقق من الصلاحيات
if (!has_permission('work_visa_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// 3. تحديد هوية المستخدم ونوعه
$user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'];
$stmt_user = $pdo->prepare("SELECT user_type, branch_id, agent_id FROM users WHERE id = ?");
$stmt_user->execute([$user_id]);
$current_user_info = $stmt_user->fetch(PDO::FETCH_ASSOC);

$user_type = $current_user_info['user_type'] ?? 'other';
$user_agent_id = $current_user_info['agent_id'];
$user_branch_id = $current_user_info['branch_id'];

// تحديد ما إذا كان المستخدم مديراً أو مرحلاً (له صلاحيات واسعة)
$stmt_user_details = $pdo->prepare("SELECT u.user_type, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt_user_details->execute([$user_id]);
$user_details = $stmt_user_details->fetch();

$is_super_user = in_array($user_details['user_type'], ['admin', 'developer']) || in_array($user_details['role'], ['admin', 'developer']) || has_permission('view_all_transactions');
$is_admin_or_dev = in_array($user_type, ['admin', 'developer']);
$is_agent = ($user_type === 'agent');
$is_branch = ($user_type === 'branch');
$pricing_context = get_current_user_pricing_context($pdo);
$can_edit_purchase_price = has_permission('work_visa_edit_purchase_price') || can_edit_service_purchase_price($pricing_context);
$can_edit_currency = can_edit_service_currency($pricing_context);
$can_edit_sale_price = has_permission('work_visa_show_sale_price') || can_edit_service_sale_price($pricing_context);

// 4. جلب البيانات الأساسية للنماذج
if ($is_super_user) {
    $agents = $pdo->query("SELECT id, agent_name, 0 as default_price, 0 as default_sale_price, NULL as currency_id FROM agents WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $branches = $pdo->query("SELECT id, branch_name, 0 as default_price, 0 as default_sale_price, NULL as currency_id FROM branches WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $agents = [];
    $branches = [];
    if ($user_agent_id) {
        $stmt = $pdo->prepare("SELECT id, agent_name, 0 as default_price, 0 as default_sale_price, NULL as currency_id FROM agents WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$user_agent_id]);
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user_branch_id) {
        $stmt = $pdo->prepare("SELECT id, branch_name, 0 as default_price, 0 as default_sale_price, NULL as currency_id FROM branches WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$user_branch_id]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$professions = $pdo->query("SELECT id, name_ar FROM professions WHERE status = 'active'")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell FROM currencies WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
// جلب الموردين مع أكواد حساباتهم مثل invoices.php
$parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$parent_stmt_suppliers->execute();
$suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

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

$suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE deleted_at IS NULL ORDER BY supplier_name ASC")->fetchAll();

// جلب البيانات الافتراضية للمستخدم الحالي (إذا كان وكيلاً أو فرعاً)
$user_defaults = [
    'purchase_price' => '',
    'sale_price' => '',
    'currency_id' => ''
];

// محاولة جلب السعر من إدارة أسعار الخدمات أولاً (لخدمة تأشيرة العمل ID=6)
$service_id_work = 6;
$price_config = get_service_price_config($pdo, $service_id_work, $user_agent_id, $user_branch_id);

if ($price_config) {
    $user_defaults['purchase_price'] = $price_config['purchase_price'];
    $user_defaults['sale_price'] = $price_config['sale_price'];
    $user_defaults['currency_id'] = $price_config['currency_id'];
}

// جلب سير العمل المناسب لتأشيرة العمل (النوع 6 أو work_visa)
$wf = get_workflow_for_transaction('work_visa');
$wf_steps = [];
if ($wf) {
    $stmt_steps = $pdo->prepare("SELECT ws.*, s.status_name FROM workflow_steps ws JOIN statuses s ON ws.status_id = s.id WHERE ws.workflow_id = ? ORDER BY ws.sort_order");
    $stmt_steps->execute([$wf['id']]);
    $wf_steps = $stmt_steps->fetchAll(PDO::FETCH_ASSOC);
}
$statuses = $wf_steps;

// جلب الدول لاستخدامها في الجنسية
$all_countries = $pdo->query("SELECT * FROM countries ORDER BY country_name ASC")->fetchAll();

$nationalities = include '../includes/nationalities.php';

// جلب الدفعات النشطة
$active_batches = $pdo->query("SELECT id, CONCAT(batch_day, ' - ', batch_month_name, ' - ', batch_year) as batch_name FROM batches WHERE is_closed = 0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// الحسابات المالية للبيانات المالية الموحدة
$cash_accounts = $pdo->query("
    SELECT id, account_name_ar as account_name
    FROM unified_accounts
    WHERE (account_code LIKE '1111%' OR account_name_ar LIKE '%صندوق%' OR parent_id IN (SELECT id FROM unified_accounts WHERE account_code = '1-1000-01'))
      AND account_code != '1-1000-01'
      AND is_active = 1
    ORDER BY account_name_ar ASC
")->fetchAll();

$bank_accounts = $pdo->query("
    SELECT id, account_name_ar as account_name
    FROM unified_accounts
    WHERE (account_code LIKE '1112%' OR account_name_ar LIKE '%بنك%')
      AND is_active = 1
    ORDER BY account_name_ar ASC
")->fetchAll();

$customer_accounts = $pdo->query("SELECT id, full_name as account_name FROM customers WHERE deleted_at IS NULL AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// 5. جلب بيانات الجدول الرئيسي مع تطبيق الفلترة
$entity_filter = get_entity_filter('p');
$where_clauses = ["(p.transaction_type = 'work_visa' OR p.transaction_type = '6')", $entity_filter['clause']];
$params = $entity_filter['params'];

// إضافة فلاتر إضافية من الـ URL
$status_filter = $_GET['status_filter'] ?? '';
$agent_filter = $_GET['agent_filter'] ?? '';
$branch_filter = $_GET['branch_filter'] ?? '';

if ($status_filter) {
    $where_clauses[] = "p.status_id = ?";
    $params[] = $status_filter;
}
if ($agent_filter && has_permission('view_all_passports')) { // Ensure only privileged users can use this filter
    $where_clauses[] = "p.agent_id = ?";
    $params[] = $agent_filter;
}
if ($branch_filter && has_permission('view_all_passports')) { // Ensure only privileged users can use this filter
    $where_clauses[] = "p.branch_id = ?";
    $params[] = $branch_filter;
}

// حساب الإحصائيات العامة
$total_filter = get_entity_filter('p');
$stmt_total = $pdo->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as this_month,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_month
    FROM passports p WHERE (p.transaction_type = 'work_visa' OR p.transaction_type = '6') AND {$total_filter['clause']}");
$stmt_total->execute($total_filter['params']);
$total_stats = $stmt_total->fetch(PDO::FETCH_ASSOC);

// إحصائيات لكل حالة بناءً على سير العمل
$status_stats = [];
foreach ($wf_steps as $step) {
    $step_entity_filter = get_entity_filter('p');
    $step_where = ["(p.transaction_type = 'work_visa' OR p.transaction_type = '6')", "p.status_id = ?", $step_entity_filter['clause']];
    $step_params = array_merge([$step['status_id']], $step_entity_filter['params']);

    if ($agent_filter && has_permission('view_all_passports')) {
        $step_where[] = "p.agent_id = ?";
        $step_params[] = $agent_filter;
    }
    if ($branch_filter && has_permission('view_all_passports')) {
        $step_where[] = "p.branch_id = ?";
        $step_params[] = $branch_filter;
    }

    $stmt_step_stat = $pdo->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as this_month,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_month
        FROM passports p WHERE " . implode(" AND ", $step_where));
    $stmt_step_stat->execute($step_params);
    $step_stat = $stmt_step_stat->fetch(PDO::FETCH_ASSOC);

    $status_stats[] = array_merge($step_stat, [
        'id' => $step['status_id'],
        'status_name' => $step['step_name'],
        'status_color' => $step['color']
    ]);
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$stmt = $pdo->prepare("
    SELECT p.*, pr.name_ar as profession_name, s.status_name, s.status_color as status_color,
           a.agent_name, b.branch_name, c.currency_name, c.currency_symbol,
           purchase_inv.total_amount as purchase_price, sale_inv.total_amount as sale_price,
           sale_inv.payment_status as sales_payment_status, sale_inv.invoice_status as sales_invoice_status,
           purchase_inv.payment_status as purchase_payment_status, purchase_inv.invoice_status as purchase_invoice_status,
           sup.supplier_name as purchase_supplier_name,
           sale_acc.account_code as sales_account_code, sale_acc.account_name_ar as sales_account_name
    FROM passports p
    LEFT JOIN professions pr ON p.profession_id = pr.id
    LEFT JOIN statuses s ON p.status_id = s.id
    LEFT JOIN agents a ON p.agent_id = a.id
    LEFT JOIN branches b ON p.branch_id = b.id
    LEFT JOIN invoices sale_inv ON p.sales_invoice_id = sale_inv.id
    LEFT JOIN invoices purchase_inv ON p.purchase_invoice_id = purchase_inv.id
    LEFT JOIN suppliers sup ON purchase_inv.supplier_id = sup.id
    LEFT JOIN unified_accounts sale_acc ON sale_inv.account_id = sale_acc.id
    LEFT JOIN currencies c ON COALESCE(sale_inv.currency_id, purchase_inv.currency_id) = c.id
    $where_sql
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$passports = $stmt->fetchAll();
?>

<div class="container-fluid">
    <div class="page-toolbar-card p-3 p-lg-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="toolbar-title">
                <span class="toolbar-title-icon"><i class="fas fa-briefcase"></i></span>
                <div>
                    <h3 class="fw-bold mb-0"><?php echo $page_title; ?></h3>
                    <div class="toolbar-subtitle">عرض مرتب للمعاملات مع فلترة مباشرة حسب الحالة والجهة.</div>
                </div>
            </div>

            <form method="GET" class="toolbar-form d-flex flex-wrap gap-2 align-items-center flex-grow-1 justify-content-end">
            <?php if (has_permission('view_all_passports')): ?>
                <select name="agent_filter" class="form-select rounded-pill shadow-sm border-0" style="width: 180px;" onchange="this.form.submit()">
                    <option value="">كل الوكلاء</option>
                    <?php foreach ($agents as $ag): ?>
                        <option value="<?php echo $ag['id']; ?>" <?php echo $agent_filter == $ag['id'] ? 'selected' : ''; ?>><?php echo $ag['agent_name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="branch_filter" class="form-select rounded-pill shadow-sm border-0" style="width: 180px;" onchange="this.form.submit()">
                    <option value="">كل الفروع</option>
                    <?php foreach ($branches as $br): ?>
                        <option value="<?php echo $br['id']; ?>" <?php echo $branch_filter == $br['id'] ? 'selected' : ''; ?>><?php echo $br['branch_name']; ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <select name="status_filter" class="form-select rounded-pill" style="width: 180px;" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                <?php foreach ($wf_steps ?: [] as $step): ?>
                    <option value="<?php echo $step['status_id']; ?>" <?php echo (string)$status_filter === (string)$step['status_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($step['step_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="input-group" style="width: 250px;">
                <span class="input-group-text bg-white border-0 shadow-sm rounded-start-pill"><i class="fas fa-search text-muted"></i></span>
                <input type="text" id="tableSearch" class="form-control border-0 shadow-sm rounded-end-pill" placeholder="بحث سريع...">
            </div>

            <?php if (has_permission('work_visa_create')): ?>
                <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus-circle me-2"></i> إضافة
                </button>
            <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- الإشعارات -->
    <?php if (isset($_GET['error'])):
        $error_code = $_GET['error'];
        $error_msg = $_GET['msg'] ?? '';

        $display_msg = 'غير معروف';
        if ($error_code === 'duplicate_passport') $display_msg = 'رقم الجواز مكرر ومسجل مسبقاً في النظام.';
        elseif ($error_code === 'duplicate_own') $display_msg = 'رقم الجواز مسجل مسبقاً ضمن معاملاتك.';
        elseif ($error_code === 'no_permission') $display_msg = 'ليس لديك صلاحية للقيام بهذا الإجراء.';
        elseif ($error_code === 'not_found') $display_msg = 'السجل المطلوب غير موجود.';
        elseif ($error_code === 'db_error') $display_msg = 'خطأ في قاعدة البيانات: ' . $error_msg;
        elseif ($error_code === 'status_failed') $display_msg = 'فشل في تغيير حالة المعاملة.';
        else $display_msg = $error_msg ?: $error_code;
    ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4">
            <i class="fas fa-exclamation-circle me-2"></i> حدث خطأ: <?php echo htmlspecialchars($display_msg); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4">
            <i class="fas fa-check-circle me-2"></i> تم حفظ البيانات بنجاح.
        </div>
    <?php endif; ?>

    <!-- وضع المراجعة للمرحل -->
    <?php if (in_array($_SESSION['role'], ['relayer', 'مرحل'])): ?>
        <div class="alert alert-info border-0 shadow-sm rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between">
            <div>
                <h6 class="fw-bold mb-1"><i class="fas fa-user-check me-2"></i> وضع مراجعة البيانات (للمرحل)</h6>
                <p class="small mb-0 opacity-75">قم بمراجعة وتأكيد مستندات الوكلاء للانتقال للمرحلة التالية.</p>
            </div>
            <a href="work_visa.php?status_filter=1" class="btn btn-sm btn-primary rounded-pill px-4">المعاملات بانتظار الترحيل</a>
        </div>
    <?php endif; ?>

    <!-- الجدول الرئيسي -->
    <div class="card border-0 rounded-4 workvisa-table-card">
        <div class="card-body p-0">
            <div class="table-responsive workvisa-table-wrap">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">العميل</th>
                            <?php if (has_permission('work_visa_show_sale_price')): ?>
                                <th>المورد والشراء</th>
                                <th>الحساب والبيع</th>
                            <?php endif; ?>
                            <?php if (has_permission('work_visa_view_passport_no') || has_permission('work_visa_view_workflow') || has_permission('work_visa_view_history') || has_permission('request_document_confirmation')): ?>
                                <th>رقم الجواز / الحالة</th>
                            <?php endif; ?>
                            <?php if (has_permission('work_visa_view_attachments') || has_permission('work_visa_view_profession')): ?>
                                <th>المرفقات / المهنة</th>
                            <?php endif; ?>
                            <?php if (has_permission('work_visa_payment_status_view')): ?><th>حالة الدفع</th><?php endif; ?>
                            <th>التاريخ</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passports as $p): ?>
                            <tr>
                                <td class="px-4">
                                    <div class="person-cell">
                                        <div class="person-name"><?php echo htmlspecialchars($p['full_name']); ?></div>
                                        <div class="person-name-en font-monospace clamp-1"><?php echo htmlspecialchars($p['full_name_en'] ?? '---'); ?></div>
                                        <div class="person-meta clamp-1">
                                            <?php echo htmlspecialchars($p['agent_name'] ?: ($p['branch_name'] ?: 'بدون جهة')); ?>
                                        </div>
                                    </div>
                                </td>
                                <?php if (has_permission('work_visa_show_sale_price')): ?>
                                    <?php $currencyMark = trim((string)($p['currency_symbol'] ?: ($p['currency_name'] ?: ''))); ?>
                                    <td class="small">
                                        <div class="finance-mini-card">
                                            <div class="mini-label">المورد</div>
                                            <div class="mini-name clamp-2"><?php echo htmlspecialchars($p['purchase_supplier_name'] ?: 'المورد غير محدد'); ?></div>
                                            <div class="mini-label">سعر الشراء</div>
                                            <div class="mini-amount" style="color:#dc2626;">
                                                <?php
                                                $purchaseAmount = isset($p['purchase_price']) ? (float)$p['purchase_price'] : 0;
                                                echo number_format($purchaseAmount, 2);
                                                echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : '';
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="small">
                                        <div class="finance-mini-card">
                                            <div class="mini-label">الحساب</div>
                                            <div class="mini-name clamp-2">
                                                <?php
                                                $salesAccountName = trim((string)($p['sales_account_name'] ?? ''));
                                                echo htmlspecialchars($salesAccountName ?: 'الحساب غير محدد');
                                                ?>
                                            </div>
                                            <div class="mini-label">سعر البيع</div>
                                            <div class="mini-amount" style="color:#16a34a;">
                                                <?php
                                                $saleAmount = (float)($p['sale_price'] ?? 0);
                                                echo number_format($saleAmount, 2);
                                                echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : '';
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <?php if (has_permission('work_visa_view_passport_no') || has_permission('work_visa_view_workflow') || has_permission('work_visa_view_history') || has_permission('request_document_confirmation')): ?>
                                    <td>
                                        <div class="d-flex flex-column align-items-center gap-2">
                                            <?php if (has_permission('work_visa_view_passport_no')): ?>
                                                <span class="badge bg-light text-dark border rounded-pill px-3 py-2"><?php echo htmlspecialchars($p['passport_number']); ?></span>
                                            <?php endif; ?>
                                            <?php if (has_permission('work_visa_view_workflow') || has_permission('work_visa_view_history') || has_permission('request_document_confirmation')): ?>
                                                <div class="d-flex flex-column align-items-center status-stack">
                                                    <span class="badge rounded-pill" style="background-color: <?php echo $p['status_color']; ?>20; color: <?php echo $p['status_color']; ?>; border: 1px solid <?php echo $p['status_color']; ?>;">
                                                        <?php echo htmlspecialchars($p['status_name']); ?>
                                                    </span>
                                                    <?php
                                                    $canReviewUnverifiedChecklist = in_array((string)($_SESSION['role'] ?? ''), ['admin', 'developer', 'relayer'], true) ? 1 : 0;
                                                    $requirementGender = strtolower((string)($p['gender'] ?? ''));
                                                    $requirementGender = $requirementGender === 'female' ? 'female' : ($requirementGender === 'male' ? 'male' : 'both');
                                                    $stmt_missing = $pdo->prepare("SELECT COUNT(*) FROM profession_requirements pr LEFT JOIN work_visa_checklist wvc ON pr.id = wvc.requirement_id AND wvc.passport_id = ? WHERE pr.profession_id = ? AND COALESCE(pr.gender, 'both') IN ('both', ?) AND (wvc.is_completed = 0 OR wvc.is_completed IS NULL OR (wvc.relayer_verified = 0 AND ? = 1))");
                                                    $stmt_missing->execute([$p['id'], $p['profession_id'], $requirementGender, $canReviewUnverifiedChecklist]);
                                                    $missing_count = $stmt_missing->fetchColumn();

                                                    if ($missing_count > 0 && ($p['status_name'] == 'تسليم للفرع الرئيسي' || $p['status_name'] == 'طلب تأكيد استلام وثائق')): ?>
                                                        <button class="btn btn-xs btn-danger extra-small rounded-pill px-2 mt-1 view-details" data-id="<?php echo $p['id']; ?>" data-tab="workflow">
                                                            <i class="fas fa-exclamation-triangle me-1"></i> وثائق ناقصة (<?php echo $missing_count; ?>)
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>

                                <?php if (has_permission('work_visa_view_attachments') || has_permission('work_visa_view_profession')): ?>
                                    <td>
                                        <div class="attachment-profession-card text-center">
                                            <?php if (has_permission('work_visa_view_attachments')): ?>
                                                <div class="mini-label">المرفقات</div>
                                                <div class="attachment-icons-row">
                                                    <span class="attachment-icon-chip <?php echo !empty($p['passport_image']) ? 'is-filled' : ''; ?>" title="الجواز">
                                                        <i class="fas fa-passport"></i>
                                                    </span>
                                                    <span class="attachment-icon-chip <?php echo !empty($p['personal_photo']) ? 'is-filled' : ''; ?>" title="الشخصية">
                                                        <i class="fas fa-user"></i>
                                                    </span>
                                                    <span class="attachment-icon-chip <?php echo !empty($p['authorization_image']) ? 'is-filled' : ''; ?>" title="التفويض">
                                                        <i class="fas fa-file-contract"></i>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (has_permission('work_visa_view_profession')): ?>
                                                <div class="mini-label <?php echo has_permission('work_visa_view_attachments') ? 'mt-1' : ''; ?>">المهنة</div>
                                                <div class="profession-badge-card clamp-2">
                                                    <?php echo htmlspecialchars($p['profession_name'] ?? '---'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <?php if (has_permission('work_visa_payment_status_view')): ?>
                                    <td>
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
                                        ?>
                                        <div class="payment-stack">
                                            <div class="payment-box small">
                                                <div class="payment-box-title text-success">البيع</div>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php echo $pay_badges[$p['sales_payment_status'] ?? 'unpaid'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                                    <?php echo $invoice_badges[$p['sales_invoice_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                                </div>
                                            </div>
                                            <div class="payment-box small">
                                                <div class="payment-box-title text-primary">الشراء</div>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php echo !empty($p['purchase_invoice_id']) ? ($pay_badges[$p['purchase_payment_status'] ?? 'unpaid'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : '<span class="badge bg-light text-dark rounded-pill">لا توجد</span>'; ?>
                                                    <?php echo !empty($p['purchase_invoice_id']) ? ($invoice_badges[$p['purchase_invoice_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : ''; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <td class="record-date"><?php echo date('Y-m-d', strtotime($p['created_at'])); ?></td>
                                <td class="text-center">
                                    <?php
                                    $salesStatus = $p['sales_invoice_status'] ?? 'draft';
                                    $purchaseStatus = $p['purchase_invoice_status'] ?? (!empty($p['purchase_invoice_id']) ? 'draft' : null);
                                    $hasSalesInvoice = !empty($p['sales_invoice_id']);
                                    $hasPurchaseInvoice = !empty($p['purchase_invoice_id']);
                                    $hasPostedInvoice = ($salesStatus === 'posted') || ($purchaseStatus === 'posted');
                                    $hasDraftInvoice = ($salesStatus === 'draft') || ($purchaseStatus === 'draft');
                                    $canModifyRow = in_array($salesStatus, ['draft', 'cancelled'], true)
                                        && (empty($p['purchase_invoice_id']) || in_array((string)$purchaseStatus, ['draft', 'cancelled'], true));
                                    ?>
                                    <div class="financial-actions-wrap">
                                        <?php if (has_permission('work_visa_view')): ?>
                                            <button class="btn btn-sm btn-outline-info view-details" data-id="<?php echo $p['id']; ?>" title="التفاصيل"><i class="fas fa-eye"></i></button>
                                        <?php endif; ?>

                                        <?php if (has_permission('work_visa_edit_workflow') || has_permission('request_document_confirmation')): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" title="نقل"><i class="fas fa-bolt"></i></button>
                                                <div class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3" id="quick-transitions-<?php echo $p['id']; ?>">
                                                    <h6 class="dropdown-header small">نقل إلى:</h6>
                                                    <a class="dropdown-item small text-primary view-details" href="#" data-id="<?php echo $p['id']; ?>">عرض كافة الخيارات</a>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php
                                        if (has_permission('document_receiver_confirm')):
                                            // تحقق من وجود وثائق غير مؤكدة لهذه المهنة
                                            $requirementGender = strtolower((string)($p['gender'] ?? ''));
                                            $requirementGender = $requirementGender === 'female' ? 'female' : ($requirementGender === 'male' ? 'male' : 'both');
                                            $stmt_missing = $pdo->prepare("SELECT COUNT(*) FROM profession_requirements pr LEFT JOIN work_visa_checklist wvc ON pr.id = wvc.requirement_id AND wvc.passport_id = ? WHERE pr.profession_id = ? AND COALESCE(pr.gender, 'both') IN ('both', ?) AND (wvc.relayer_verified = 0 OR wvc.relayer_verified IS NULL)");
                                            $stmt_missing->execute([$p['id'], $p['profession_id'], $requirementGender]);
                                            $missing_count = $stmt_missing->fetchColumn();

                                            if ($missing_count > 0): ?>
                                                <button class="btn btn-sm btn-outline-warning confirm-docs-btn" data-id="<?php echo $p['id']; ?>" title="تأكيد استلام الوثائق"><i class="fas fa-file-signature"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (has_permission('work_visa_print')): ?>
                                            <a href="print_work_visa.php?id=<?php echo $p['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="طباعة"><i class="fas fa-print"></i></a>
                                        <?php endif; ?>

                                        <?php if (has_permission('work_visa_financial_post') && $hasDraftInvoice): ?>
                                            <div class="financial-action-group">
                                                <button class="financial-action-btn fin-post" type="button" data-action-menu-toggle="post-<?php echo $p['id']; ?>" title="خيارات الترحيل">
                                                    <i class="fas fa-file-export"></i>
                                                    <span>الترحيل</span>
                                                    <i class="fas fa-chevron-down small"></i>
                                                </button>
                                                <div class="financial-action-menu" id="post-<?php echo $p['id']; ?>">
                                                    <div class="financial-action-menu-title">خيارات الترحيل</div>
                                                    <button class="financial-action-menu-item post-option <?php echo (($salesStatus === 'draft') && ($purchaseStatus === 'draft')) ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if (($salesStatus === 'draft') && ($purchaseStatus === 'draft')): ?>
                                                                onclick="WorkVisa.postFinance(<?php echo $p['id']; ?>, 'all')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-layer-group"></i>
                                                        <span>ترحيل الكل</span>
                                                    </button>
                                                    <button class="financial-action-menu-item post-option <?php echo ($hasSalesInvoice && $salesStatus === 'draft') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasSalesInvoice && $salesStatus === 'draft'): ?>
                                                                onclick="WorkVisa.postFinance(<?php echo $p['id']; ?>, 'sales')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-arrow-up-right-from-square"></i>
                                                        <span>ترحيل البيع</span>
                                                    </button>
                                                    <button class="financial-action-menu-item post-option <?php echo ($hasPurchaseInvoice && $purchaseStatus === 'draft') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasPurchaseInvoice && $purchaseStatus === 'draft'): ?>
                                                                onclick="WorkVisa.postFinance(<?php echo $p['id']; ?>, 'purchase')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-cart-plus"></i>
                                                        <span>ترحيل الشراء</span>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (has_permission('work_visa_financial_post') && $hasPostedInvoice): ?>
                                            <div class="financial-action-group">
                                                <button class="financial-action-btn fin-unpost" type="button" data-action-menu-toggle="unpost-<?php echo $p['id']; ?>" title="خيارات إلغاء الترحيل">
                                                    <i class="fas fa-rotate-left"></i>
                                                    <span>إلغاء الترحيل</span>
                                                    <i class="fas fa-chevron-down small"></i>
                                                </button>
                                                <div class="financial-action-menu" id="unpost-<?php echo $p['id']; ?>">
                                                    <div class="financial-action-menu-title">خيارات إلغاء الترحيل</div>
                                                    <button class="financial-action-menu-item unpost-option <?php echo (($salesStatus === 'posted') && ($purchaseStatus === 'posted')) ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if (($salesStatus === 'posted') && ($purchaseStatus === 'posted')): ?>
                                                                onclick="WorkVisa.unpostFinance(<?php echo $p['id']; ?>, 'all')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-layer-group"></i>
                                                        <span>إلغاء ترحيل الكل</span>
                                                    </button>
                                                    <button class="financial-action-menu-item unpost-option <?php echo ($hasSalesInvoice && $salesStatus === 'posted') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasSalesInvoice && $salesStatus === 'posted'): ?>
                                                                onclick="WorkVisa.unpostFinance(<?php echo $p['id']; ?>, 'sales')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-arrow-rotate-left"></i>
                                                        <span>إلغاء ترحيل البيع</span>
                                                    </button>
                                                    <button class="financial-action-menu-item unpost-option <?php echo ($hasPurchaseInvoice && $purchaseStatus === 'posted') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasPurchaseInvoice && $purchaseStatus === 'posted'): ?>
                                                                onclick="WorkVisa.unpostFinance(<?php echo $p['id']; ?>, 'purchase')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-clock-rotate-left"></i>
                                                        <span>إلغاء ترحيل الشراء</span>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($canModifyRow && (has_permission('work_visa_edit') || has_permission('work_visa_delete'))): ?>
                                            <div class="financial-action-group">
                                                <button class="financial-action-btn" type="button" data-action-menu-toggle="manage-<?php echo $p['id']; ?>" title="إدارة المعاملة" style="background: rgba(59, 130, 246, 0.12); color: #1d4ed8;">
                                                    <i class="fas fa-gear"></i>
                                                    <span>إدارة</span>
                                                    <i class="fas fa-chevron-down small"></i>
                                                </button>
                                                <div class="financial-action-menu" id="manage-<?php echo $p['id']; ?>">
                                                    <div class="financial-action-menu-title">إدارة المعاملة</div>
                                                    <?php if (has_permission('work_visa_edit')): ?>
                                                        <button class="financial-action-menu-item manage-option edit-visa" type="button" data-id="<?php echo $p['id']; ?>">
                                                            <i class="fas fa-pen-to-square"></i>
                                                            <span>تعديل المعاملة</span>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (has_permission('work_visa_delete')): ?>
                                                        <button class="financial-action-menu-item delete-option <?php echo ($hasSalesInvoice || $hasPurchaseInvoice) ? '' : 'disabled'; ?>"
                                                                type="button"
                                                                <?php if ($hasSalesInvoice || $hasPurchaseInvoice): ?>
                                                                    onclick="WorkVisa.deleteFinance(<?php echo $p['id']; ?>, 'all')"
                                                                <?php endif; ?>>
                                                            <i class="fas fa-trash-can"></i>
                                                            <span>حذف الكل</span>
                                                        </button>
                                                        <button class="financial-action-menu-item delete-option <?php echo $hasSalesInvoice ? '' : 'disabled'; ?>"
                                                                type="button"
                                                                <?php if ($hasSalesInvoice): ?>
                                                                    onclick="WorkVisa.deleteFinance(<?php echo $p['id']; ?>, 'sales')"
                                                                <?php endif; ?>>
                                                            <i class="fas fa-file-invoice-dollar"></i>
                                                            <span>حذف البيع فقط</span>
                                                        </button>
                                                        <button class="financial-action-menu-item delete-option <?php echo $hasPurchaseInvoice ? '' : 'disabled'; ?>"
                                                                type="button"
                                                                <?php if ($hasPurchaseInvoice): ?>
                                                                    onclick="WorkVisa.deleteFinance(<?php echo $p['id']; ?>, 'purchase')"
                                                                <?php endif; ?>>
                                                            <i class="fas fa-file-invoice"></i>
                                                            <span>حذف الشراء فقط</span>
                                                        </button>
                                                        <a href="passports.php?delete_id=<?php echo $p['id']; ?>&redirect=work_visa.php"
                                                           class="financial-action-menu-item delete-option delete-passport-record">
                                                            <i class="fas fa-trash"></i>
                                                            <span>حذف المعاملة</span>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
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
</div>

<!-- مودال إضافة معاملة جديدة (addModal) -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" enctype="multipart/form-data" action="passports.php?add_passport=1" id="workVisaAddForm">
                <input type="hidden" name="transaction_type" value="work_visa">
                <input type="hidden" name="redirect" value="work_visa.php">
                <input type="hidden" name="confirm_duplicate" id="add_confirm_duplicate" value="0">
                <input type="hidden" name="add_passport" value="1">
                <div class="modal-header bg-success text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة معاملة جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 80vh; overflow-y: auto;">
                    <div class="row g-3">
                        <!-- Always show branch_id and agent_id as hidden fields based on current user -->
                        <?php if ($user_agent_id): ?>
                            <input type="hidden" name="agent_id" id="add_agent_id" value="<?php echo $user_agent_id; ?>">
                        <?php endif; ?>
                        <?php if ($user_branch_id): ?>
                            <input type="hidden" name="branch_id" id="add_branch_id" value="<?php echo $user_branch_id; ?>">
                        <?php endif; ?>

                        <!-- السطر الأول: الاسم والجواز -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الاسم (عربي)</label>
                            <input type="text" name="full_name" id="add_full_name" class="form-control form-control-sm rounded-3" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الاسم (إنجليزي)</label>
                            <input type="text" name="full_name_en" id="add_full_name_en" class="form-control form-control-sm rounded-3 font-monospace">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-primary">رقم الجواز</label>
                            <input type="text" name="passport_number" id="add_passport_number" class="form-control form-control-sm rounded-3" required>
                        </div>

                        <!-- السطر الثاني: الجنسية والجنس والمهنة -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الجنسية</label>
                            <select name="nationality" id="add_nationality" class="form-select form-select-sm rounded-3">
                                <option value="">اختر...</option>
                                <?php foreach ($all_countries as $country): ?>
                                    <option value="<?php echo htmlspecialchars($country['country_name']); ?>" data-code="<?php echo htmlspecialchars($country['country_code']); ?>"><?php echo htmlspecialchars($country['country_name']); ?> (<?php echo htmlspecialchars($country['country_code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div id="add_nationality_rule" class="extra-small text-muted mt-1"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الجنس (Sex)</label>
                            <select name="gender" id="add_gender" class="form-select form-select-sm rounded-3">
                                <option value="">اختر...</option>
                                <option value="Male">ذكر (Male)</option>
                                <option value="Female">أنثى (Female)</option>
                            </select>
                            <div id="add_gender_rule" class="extra-small text-muted mt-1"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-success">المهنة</label>
                            <select name="profession_id" id="add_profession_id" class="form-select form-select-sm rounded-3 profession-select" data-prefix="add" required>
                                <option value="">اختر المهنة...</option>
                                <?php foreach ($professions as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>"><?php echo $prof['name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- السطر الثالث: التواريخ -->
                        <?php if ($settings['show_dob_field'] ?? 1): ?>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">تاريخ الميلاد</label>
                                <input type="date" name="date_of_birth" id="add_dob" class="form-control form-control-sm rounded-3 visa-date-input" data-rule="age">
                                <div id="add_dob_rule" class="extra-small text-muted mt-1"></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($settings['show_issue_date_field'] ?? 1): ?>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">إصدار الجواز</label>
                                <input type="date" name="passport_issue_date" id="add_passport_issue" class="form-control form-control-sm rounded-3 visa-date-input">
                            </div>
                        <?php endif; ?>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">انتهاء الجواز</label>
                            <input type="date" name="passport_expiry_date" id="add_passport_expiry" class="form-control form-control-sm rounded-3 visa-date-input" data-rule="passport_validity">
                            <div id="add_passport_expiry_rule" class="extra-small text-muted mt-1"></div>
                        </div>

                        <!-- السطر الرابع: الجوال والصور -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الجوال</label>
                            <input type="text" name="phone_number" id="add_phone_number" class="form-control form-control-sm rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">صورة الجواز</label>
                            <div class="input-group input-group-sm mb-1">
                                <input type="file" name="passport_image" id="add_passport_image_input" class="form-control rounded-start-3" accept="image/*">
                                <?php if (has_permission('work_visa_scan_passport')): ?>
                                    <button class="btn btn-warning btn-sm text-white" type="button" id="scan_passport_btn">
                                        <i class="fas fa-qrcode"></i> قراءة
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div id="add_passport_preview" class="ocr-preview-container d-none mb-1 text-center border rounded-3 p-1 bg-light">
                                <img src="" class="img-fluid rounded shadow-sm" style="max-height: 100px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الصورة الشخصية</label>
                            <input type="file" name="personal_photo" class="form-control form-control-sm rounded-3" accept="image/*">
                        </div>

                        <?php if ($settings['show_profession_requirements'] ?? 1): ?>
                            <div class="col-12" id="add_requirements_container" style="display:none;">
                                <label class="form-label fw-bold text-warning small"><i class="fas fa-tasks me-1"></i> متطلبات المهنة:</label>
                                <div class="d-flex flex-wrap gap-2 p-2 bg-light rounded-3 border" id="add_requirements_list"></div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <div id="add_rule_alerts" class="alert alert-warning rounded-4 py-2 px-3 small d-none mb-0"></div>
                        </div>

                        <!-- البيانات المالية الموحدة -->
                        <?php
                        // إعداد بيانات الفاتورة الحالية
                        $current_invoice = [
                            'invoice_date' => normalize_datetime_db(null),
                            'branch_id' => $_SESSION['branch_id'] ?? null,
                            'source_type' => 'فيز العمل',
                            'delivery_type' => $settings['default_delivery_type'] ?? 'draft',
                            'sale_currency_id' => $user_defaults['currency_id'] ?? 1,
                            'total_amount' => $user_defaults['sale_price'] ?? 0,
                            'discount' => 0,
                            'record_purchase' => '1',
                            'cost_amount' => $user_defaults['purchase_price'] ?? 0,
                            'received_amount' => 0,
                            'currency_id' => $user_defaults['currency_id'] ?? 1,
                            'description' => ''
                        ];
                        $financial_fields_prefix = 'add_';
                        $financial_fields_select2_parent = '#addModal';
                        $financial_fields_form_selector = '#workVisaAddForm';
                        $financial_fields_show_service_select = false;
                        $financial_fields_header_layout = 'split_rows';
                        $financial_fields_title_layout = 'block';
                        $financial_fields_hide_service_accounts = true;
                        include '../includes/financial_fields.php';
                        ?>
                        <div class="col-md-12 mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="print_receipt_auto" id="add_print_receipt_auto" checked>
                                <label class="form-check-label small fw-bold" for="add_print_receipt_auto">
                                    طباعة سند القبض فوراً
                                </label>
                            </div>
                        </div>

                        <?php if (has_permission('work_visa_show_batch_link')): ?>
                            <div class="col-md-12">
                                <label class="form-label fw-bold text-primary small"><i class="fas fa-layer-group me-1"></i> ربط بالدفعة (اختياري)</label>
                                <select name="batch_id" id="add_batch_id" class="form-select form-select-sm rounded-pill">
                                    <option value="">-- بدون دفعة --</option>
                                    <?php foreach ($active_batches as $batch): ?>
                                        <option value="<?php echo $batch['id']; ?>"><?php echo $batch['batch_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- Hidden OCR Fields -->
                        <input type="hidden" name="mrz_line_1" id="add_mrz_line_1">
                        <input type="hidden" name="mrz_line_2" id="add_mrz_line_2">
                        <input type="hidden" name="ocr_raw_text" id="add_ocr_raw_text">
                        <input type="hidden" name="passport_country_code" id="add_passport_country_code">
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_passport" class="btn btn-success rounded-pill px-5 fw-bold">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال التعديل (editModal) -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" enctype="multipart/form-data" action="passports.php?update_passport=1" id="workVisaEditForm">
                <input type="hidden" name="passport_id" id="edit_passport_id">
                <input type="hidden" name="transaction_type" value="work_visa">
                <input type="hidden" name="redirect" value="work_visa.php">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل المعاملة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 80vh; overflow-y: auto;">
                    <div class="row g-3">
                        <!-- Always show branch_id and agent_id as hidden fields based on current user -->
                        <?php if ($user_agent_id): ?>
                            <input type="hidden" name="agent_id" id="edit_agent_id" value="<?php echo $user_agent_id; ?>">
                        <?php endif; ?>
                        <?php if ($user_branch_id): ?>
                            <input type="hidden" name="branch_id" id="edit_branch_id" value="<?php echo $user_branch_id; ?>">
                        <?php endif; ?>

                        <!-- السطر الأول -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الاسم (عربي)</label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control form-control-sm rounded-3" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الاسم (إنجليزي)</label>
                            <input type="text" name="full_name_en" id="edit_full_name_en" class="form-control form-control-sm rounded-3 font-monospace">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-primary">رقم الجواز</label>
                            <input type="text" name="passport_number" id="edit_passport_number" class="form-control form-control-sm rounded-3" required>
                        </div>

                        <!-- السطر الثاني -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الجنسية</label>
                            <select name="nationality" id="edit_nationality" class="form-select form-select-sm rounded-3">
                                <option value="">اختر...</option>
                                <?php foreach ($all_countries as $country): ?>
                                    <option value="<?php echo htmlspecialchars($country['country_name']); ?>" data-code="<?php echo htmlspecialchars($country['country_code']); ?>"><?php echo htmlspecialchars($country['country_name']); ?> (<?php echo htmlspecialchars($country['country_code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div id="edit_nationality_rule" class="extra-small text-muted mt-1"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">الجنس (Sex)</label>
                            <select name="gender" id="edit_gender" class="form-select form-select-sm rounded-3">
                                <option value="">اختر...</option>
                                <option value="Male">ذكر (Male)</option>
                                <option value="Female">أنثى (Female)</option>
                            </select>
                            <div id="edit_gender_rule" class="extra-small text-muted mt-1"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-success">المهنة</label>
                            <select name="profession_id" id="edit_profession_id" class="form-select form-select-sm rounded-3 profession-select" data-prefix="edit" required>
                                <option value="">اختر المهنة...</option>
                                <?php foreach ($professions as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>"><?php echo $prof['name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- السطر الثالث -->
                        <?php if ($settings['show_dob_field'] ?? 1): ?>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">تاريخ الميلاد</label>
                                <input type="date" name="date_of_birth" id="edit_dob" class="form-control form-control-sm rounded-3 visa-date-input" data-rule="age">
                                <div id="edit_dob_rule" class="extra-small text-muted mt-1"></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($settings['show_issue_date_field'] ?? 1): ?>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">إصدار الجواز</label>
                                <input type="date" name="passport_issue_date" id="edit_passport_issue" class="form-control form-control-sm rounded-3 visa-date-input">
                            </div>
                        <?php endif; ?>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">انتهاء الجواز</label>
                            <input type="date" name="passport_expiry_date" id="edit_passport_expiry" class="form-control form-control-sm rounded-3 visa-date-input" data-rule="passport_validity">
                            <div id="edit_passport_expiry_rule" class="extra-small text-muted mt-1"></div>
                        </div>

                        <!-- السطر الرابع -->
                        <div class="col-md-4 d-none">
                            <label class="form-label fw-bold small">الحالة</label>
                            <input type="hidden" name="status_id" id="edit_status_id">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">صورة الجواز</label>
                            <div class="input-group input-group-sm mb-1">
                                <input type="file" name="passport_image" id="edit_passport_image_input" class="form-control rounded-start-3" accept="image/*">
                                <?php if (has_permission('work_visa_scan_passport')): ?>
                                    <button class="btn btn-warning btn-sm text-white" type="button" id="edit_scan_passport_btn">
                                        <i class="fas fa-qrcode"></i> قراءة
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div id="edit_passport_preview" class="ocr-preview-container d-none mb-1 text-center border rounded-3 p-1 bg-light">
                                <img src="" class="img-fluid rounded shadow-sm" style="max-height: 100px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <!-- Hidden OCR Fields -->
                            <input type="hidden" name="mrz_line_1" id="edit_mrz_line_1">
                            <input type="hidden" name="mrz_line_2" id="edit_mrz_line_2">
                            <input type="hidden" name="ocr_raw_text" id="edit_ocr_raw_text">
                            <input type="hidden" name="passport_country_code" id="edit_passport_country_code">
                        </div>

                        <?php if ($settings['show_profession_requirements'] ?? 1): ?>
                            <div class="col-12" id="edit_requirements_container" style="display:none;">
                                <label class="form-label fw-bold text-warning small"><i class="fas fa-tasks me-1"></i> متطلبات المهنة:</label>
                                <div class="d-flex flex-wrap gap-2 p-2 bg-light rounded-3 border" id="edit_requirements_list"></div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <div id="edit_rule_alerts" class="alert alert-warning rounded-4 py-2 px-3 small d-none mb-0"></div>
                        </div>

                        <?php
                        $current_invoice = [
                            'invoice_date' => normalize_datetime_db(null),
                            'branch_id' => $_SESSION['branch_id'] ?? null,
                            'source_type' => 'فيز العمل',
                            'delivery_type' => $settings['default_delivery_type'] ?? 'draft',
                            'sale_currency_id' => $user_defaults['currency_id'] ?? 1,
                            'total_amount' => $user_defaults['sale_price'] ?? 0,
                            'discount' => 0,
                            'record_purchase' => '1',
                            'cost_amount' => $user_defaults['purchase_price'] ?? 0,
                            'received_amount' => 0,
                            'currency_id' => $user_defaults['currency_id'] ?? 1,
                            'description' => ''
                        ];
                        $financial_fields_prefix = 'edit_';
                        $financial_fields_select2_parent = '#editModal';
                        $financial_fields_form_selector = '#workVisaEditForm';
                        $financial_fields_show_service_select = false;
                        $financial_fields_header_layout = 'split_rows';
                        $financial_fields_title_layout = 'block';
                        $financial_fields_hide_service_accounts = true;
                        include '../includes/financial_fields.php';
                        ?>

                        <?php if (has_permission('work_visa_show_batch_link')): ?>
                            <div class="col-md-12">
                                <label class="form-label fw-bold text-primary small"><i class="fas fa-layer-group me-1"></i> ربط بالدفعة (اختياري)</label>
                                <select name="batch_id" id="edit_batch_id" class="form-select form-select-sm rounded-pill">
                                    <option value="">-- بدون دفعة --</option>
                                    <?php foreach ($active_batches as $batch): ?>
                                        <option value="<?php echo $batch['id']; ?>"><?php echo $batch['batch_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_passport" class="btn btn-primary rounded-pill px-5 fw-bold">تحديث</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="workVisaNoticeContainer" class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1085; width: min(92vw, 720px);"></div>

<!-- مودال التفاصيل (detailsModal) -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-info text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-id-card me-2"></i> تفاصيل المعاملة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="detailsModalContent">
                <!-- جاري التحميل... -->
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
                <?php if (has_permission('work_visa_approve') || has_permission('request_document_confirmation') || in_array($_SESSION['role'], ['relayer', 'مرحل', 'admin'])): ?>
                    <button type="button" class="btn btn-success rounded-pill px-5 fw-bold" id="saveChecklistBtn">حفظ التغييرات</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
    const CSRF_TOKEN = <?php echo json_encode(generate_csrf_token()); ?>;
    const nativeFetch = window.fetch.bind(window);
    window.fetch = (resource, options = {}) => {
        const url = typeof resource === 'string' ? resource : resource.url;
        const method = (options.method || 'GET').toUpperCase();
        if (url && url.includes('ajax_work_visa.php') && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            options.headers = new Headers(options.headers || {});
            options.headers.set('X-CSRF-Token', CSRF_TOKEN);
        }
        return nativeFetch(resource, options);
    };
    /**
     * نظام JavaScript الموحد لصفحة تأشيرات العمل
     */
    const WorkVisa = {
        showNotice(message, type = 'info') {
            const typeMap = {
                success: {
                    swalIcon: 'success',
                    title: 'تمت العملية بنجاح'
                },
                error: {
                    swalIcon: 'error',
                    title: 'حدث خطأ'
                },
                warning: {
                    swalIcon: 'warning',
                    title: 'تنبيه'
                },
                info: {
                    swalIcon: 'info',
                    title: 'معلومة'
                }
            };

            const config = typeMap[type] || typeMap.info;
            if (typeof Swal !== 'undefined') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top',
                    showConfirmButton: false,
                    timer: 3600,
                    timerProgressBar: true,
                    customClass: {
                        popup: 'shadow-lg rounded-4'
                    },
                    didOpen: toast => {
                        toast.addEventListener('mouseenter', Swal.stopTimer);
                        toast.addEventListener('mouseleave', Swal.resumeTimer);
                    }
                });

                Toast.fire({
                    icon: config.swalIcon,
                    title: config.title,
                    text: message
                });
                return;
            }

            const container = document.getElementById('workVisaNoticeContainer');
            if (!container) {
                window.alert(message);
                return;
            }

            const notice = document.createElement('div');
            notice.className = 'alert alert-info alert-dismissible fade show shadow-sm border-0 rounded-4 mb-2';
            notice.innerHTML = `
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="fw-bold mb-1">${config.title}</div>
                        <div>${message}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            container.appendChild(notice);
        },

        escapeHtml(value = '') {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        async confirmDialog({
            title = 'تأكيد العملية',
            text = '',
            html = '',
            icon = 'question',
            confirmText = 'نعم، متابعة',
            cancelText = 'إلغاء',
            reverseButtons = true
        } = {}) {
            if (typeof Swal !== 'undefined') {
                const isDark = document.body.classList.contains('theme-dark');
                const result = await Swal.fire({
                    title,
                    text: html ? undefined : text,
                    html: html || undefined,
                    icon,
                    showCancelButton: true,
                    confirmButtonText: confirmText,
                    cancelButtonText: cancelText,
                    reverseButtons,
                    focusCancel: true,
                    background: isDark ? '#0b1220' : '#ffffff',
                    color: isDark ? '#e2e8f0' : '#0f172a',
                    customClass: {
                        popup: 'rounded-4 shadow-lg',
                        confirmButton: 'btn btn-primary rounded-pill px-4 mx-1',
                        cancelButton: (isDark ? 'btn btn-outline-light' : 'btn btn-light') + ' rounded-pill px-4 mx-1'
                    },
                    buttonsStyling: false
                });
                return !!result.isConfirmed;
            }

            return window.confirm(text || title);
        },

        async alertDialog({
            title = 'تنبيه',
            text = '',
            html = '',
            icon = 'info',
            confirmText = 'حسنًا'
        } = {}) {
            if (typeof Swal !== 'undefined') {
                const isDark = document.body.classList.contains('theme-dark');
                await Swal.fire({
                    title,
                    text: html ? undefined : text,
                    html: html || undefined,
                    icon,
                    confirmButtonText: confirmText,
                    background: isDark ? '#0b1220' : '#ffffff',
                    color: isDark ? '#e2e8f0' : '#0f172a',
                    customClass: {
                        popup: 'rounded-4 shadow-lg',
                        confirmButton: 'btn btn-primary rounded-pill px-4'
                    },
                    buttonsStyling: false
                });
                return;
            }

            window.alert(text || title);
        },

        // 1. تهيئة الأحداث
        init() {
            document.addEventListener('click', e => this.handleClicks(e));

            // التحقق من وجود معرف في الرابط لفتح التفاصيل تلقائياً
            const urlParams = new URLSearchParams(window.location.search);
            const passportId = urlParams.get('id');
            if (passportId) {
                this.openDetailsModal(passportId);
            }

            // ربط البحث الديناميكي
            const searchInput = document.getElementById('tableSearch');
            if (searchInput) {
                searchInput.addEventListener('input', (e) => this.filterTable(e.target.value));
            }

            // ربط أحداث الإدخال المباشرة
            const setupPassportInput = (id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', function() {
                        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    });
                }
            };

            // استخدام MutationObserver لمراقبة تغييرات الـ DOM وربط الأحداث
            const observer = new MutationObserver((mutations) => {
                setupPassportInput('add_passport_number');
                setupPassportInput('edit_passport_number');
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            // تنفيذ أولي
            setupPassportInput('add_passport_number');
            setupPassportInput('edit_passport_number');

            // جلب الأسعار تلقائياً عند فتح مودال الإضافة للوكلاء والفروع
            const addModalEl = document.getElementById('addModal');
            if (addModalEl) {
                addModalEl.addEventListener('shown.bs.modal', () => {
                    const duplicateInput = document.getElementById('add_confirm_duplicate');
                    if (duplicateInput) duplicateInput.value = '0';
                    this.syncAutoDescription('add', true);
                    const agentId = document.getElementById('add_agent_id')?.value;
                    const branchId = document.getElementById('add_branch_id')?.value;
                    if (agentId || branchId) {
                        this.loadServicePrice('add');
                    }
                    const professionId = document.getElementById('add_profession_id')?.value;
                    if (professionId) {
                        this.loadProfessionRequirements(professionId, 'add');
                    }
                });
            }

            const addFormEl = document.getElementById('workVisaAddForm');
            if (addFormEl) {
                addFormEl.addEventListener('submit', async (e) => {
                    if (addFormEl.dataset.skipDuplicateCheck === '1') {
                        addFormEl.dataset.skipDuplicateCheck = '0';
                        return;
                    }
                    e.preventDefault();
                    const canSubmit = await this.checkDuplicateBeforeSubmit();
                    if (!canSubmit) {
                        return;
                    }
                    addFormEl.dataset.skipDuplicateCheck = '1';
                    addFormEl.submit();
                });
            }

            // ربط أحداث تغيير التواريخ للتحقق التلقائي
            document.querySelectorAll('.visa-date-input').forEach(input => {
                input.addEventListener('change', (e) => {
                    this.validateField(e.target);
                    this.updateRuleAlerts(e.target.id.startsWith('edit_') ? 'edit' : 'add');
                });
            });

            // مستمع تغيير المهنة
            document.addEventListener('change', e => {
                if (e.target.classList.contains('profession-select')) {
                    this.loadProfessionRequirements(e.target.value, e.target.dataset.prefix);
                    this.loadServicePrice(e.target.dataset.prefix);
                    this.syncAutoDescription(e.target.dataset.prefix);
                    return;
                }

                if (['add_gender', 'edit_gender', 'add_nationality', 'edit_nationality'].includes(e.target.id)) {
                    const prefix = e.target.id.startsWith('edit_') ? 'edit' : 'add';
                    const professionId = document.getElementById(`${prefix}_profession_id`)?.value;
                    if (professionId) {
                        this.loadProfessionRequirements(professionId, prefix);
                    } else {
                        this.updateRuleAlerts(prefix);
                    }
                    return;
                }

                if (['add_passport_issue', 'edit_passport_issue'].includes(e.target.id)) {
                    this.syncPassportExpiryFromIssueDate(e.target.id.startsWith('edit_') ? 'edit' : 'add');
                    return;
                }

                if (['add_full_name', 'add_passport_number', 'edit_full_name', 'edit_passport_number'].includes(e.target.id)) {
                    const duplicateInput = document.getElementById('add_confirm_duplicate');
                    if (e.target.id.startsWith('add_') && duplicateInput) duplicateInput.value = '0';
                    this.syncAutoDescription(e.target.id.startsWith('edit_') ? 'edit' : 'add');
                }
            });

            document.addEventListener('input', e => {
                if (['add_full_name', 'add_passport_number', 'edit_full_name', 'edit_passport_number'].includes(e.target.id)) {
                    const duplicateInput = document.getElementById('add_confirm_duplicate');
                    if (e.target.id.startsWith('add_') && duplicateInput) duplicateInput.value = '0';
                    this.syncAutoDescription(e.target.id.startsWith('edit_') ? 'edit' : 'add');
                }
                if (['add_description', 'edit_description'].includes(e.target.id)) {
                    const desc = e.target;
                    const lastAuto = desc.dataset.lastAutoDescription || '';
                    desc.dataset.autoManaged = (desc.value.trim() === '' || desc.value.trim() === lastAuto.trim()) ? '1' : '0';
                }
            });
        },

        normalizeGenderValue(value) {
            if (value === 'Male') return 'male';
            if (value === 'Female') return 'female';
            return '';
        },

        generateAutoDescription(prefix) {
            const name = document.getElementById(`${prefix}_full_name`)?.value?.trim() || '';
            const passport = document.getElementById(`${prefix}_passport_number`)?.value?.trim() || '';
            if (!name && !passport) {
                return '';
            }

            let text = `معاملة تأشيرة عمل للأخ ${name || '---'}`;
            if (passport) {
                text += ` - رقم الجواز ${passport}`;
            }
            return text;
        },

        syncAutoDescription(prefix, force = false) {
            const desc = document.getElementById(`${prefix}_description`);
            if (!desc) return;

            const generated = this.generateAutoDescription(prefix);
            const lastAuto = desc.dataset.lastAutoDescription || '';
            const autoManaged = desc.dataset.autoManaged !== '0';
            if (force || autoManaged || desc.value.trim() === '' || desc.value.trim() === lastAuto.trim()) {
                desc.value = generated;
                desc.dataset.lastAutoDescription = generated;
                desc.dataset.autoManaged = '1';
            }
        },

        syncPassportExpiryFromIssueDate(prefix, force = false) {
            const issueInput = document.getElementById(`${prefix}_passport_issue`);
            const expiryInput = document.getElementById(`${prefix}_passport_expiry`);
            if (!issueInput || !expiryInput || !issueInput.value) {
                return;
            }

            const issueDate = new Date(issueInput.value);
            if (Number.isNaN(issueDate.getTime())) {
                return;
            }

            const lastAuto = expiryInput.dataset.lastAutoValue || '';
            const canAutoFill = force || expiryInput.value === '' || expiryInput.value === lastAuto;
            if (!canAutoFill) {
                return;
            }

            issueDate.setFullYear(issueDate.getFullYear() + 5);
            const yyyy = issueDate.getFullYear();
            const mm = String(issueDate.getMonth() + 1).padStart(2, '0');
            const dd = String(issueDate.getDate()).padStart(2, '0');
            const autoValue = `${yyyy}-${mm}-${dd}`;
            expiryInput.value = autoValue;
            expiryInput.dataset.lastAutoValue = autoValue;
            expiryInput.dispatchEvent(new Event('change', { bubbles: true }));
        },

        async checkDuplicateBeforeSubmit() {
            const duplicateInput = document.getElementById('add_confirm_duplicate');
            if (duplicateInput && duplicateInput.value === '1') {
                return true;
            }

            const passportNumber = document.getElementById('add_passport_number')?.value?.trim() || '';
            const fullName = document.getElementById('add_full_name')?.value?.trim() || '';
            if (!passportNumber && !fullName) {
                return true;
            }

            try {
                const params = new URLSearchParams({
                    action: 'check_duplicate_work_visa',
                    passport_number: passportNumber,
                    full_name: fullName,
                    transaction_type: 'work_visa',
                    agent_id: document.getElementById('add_agent_id')?.value || '',
                    branch_id: document.getElementById('add_branch_id')?.value || ''
                });
                const res = await fetch(`ajax_work_visa.php?${params.toString()}`);
                const data = await res.json();
                if (!data || data.status !== 'success' || !data.duplicate) {
                    return true;
                }

                const reasons = [];
                if (data.duplicate_by_passport) reasons.push('رقم الجواز مكرر');
                if (data.duplicate_by_name) reasons.push('الشخص مسجل مسبقاً');
                const examples = (data.matches || []).slice(0, 3).map(item => {
                    const scope = item.agent_name || item.branch_name || '';
                    return `- ${item.full_name || '---'} | ${item.passport_number || '---'}${scope ? ' | ' + scope : ''}`;
                }).join('\n');

                const examplesHtml = (data.matches || []).slice(0, 3).map(item => {
                    const scope = item.agent_name || item.branch_name || '';
                    return `<li class="mb-1">${this.escapeHtml(item.full_name || '---')} <span class="text-muted">|</span> ${this.escapeHtml(item.passport_number || '---')}${scope ? ` <span class="text-muted">|</span> ${this.escapeHtml(scope)}` : ''}</li>`;
                }).join('');

                const confirmed = await this.confirmDialog({
                    title: reasons.join(' و ') || 'هذه البيانات مكررة',
                    icon: 'warning',
                    confirmText: 'نعم، أنشئ معاملة أخرى',
                    cancelText: 'إلغاء',
                    html: `
                        <div class="text-end">
                            ${data.scope_hint ? `<div class="small text-muted mb-2">${this.escapeHtml(data.scope_hint)}</div>` : ''}
                            ${examplesHtml ? `<ul class="small text-end pe-3 mb-3">${examplesHtml}</ul>` : ''}
                            <div class="fw-bold">هل تريد إنشاء فاتورة أو معاملة أخرى لنفس رقم الجواز أو نفس الشخص؟</div>
                        </div>
                    `
                });
                if (confirmed && duplicateInput) {
                    duplicateInput.value = '1';
                }
                return confirmed;
            } catch (err) {
                console.error('Duplicate check failed:', err);
                return true;
            }
        },

        // 1.1 منطق العملات وسعر الصرف
        updateCurrencyLogic(prefix) {
            const purCurrencyId = document.getElementById(prefix + '_purchase_currency_id')?.value;
            const saleCurrencyId = document.getElementById(prefix + '_sale_currency_id')?.value;
            const exchangeContainer = document.getElementById(prefix + '_exchange_rate_container');

            if (purCurrencyId && saleCurrencyId && purCurrencyId != saleCurrencyId) {
                exchangeContainer.style.display = 'block';
                const purOpt = document.querySelector(`#${prefix}_purchase_currency_id option[value="${purCurrencyId}"]`);
                const saleOpt = document.querySelector(`#${prefix}_sale_currency_id option[value="${saleCurrencyId}"]`);
                const purSymbol = purOpt?.dataset.symbol || '---';
                const saleSymbol = saleOpt?.dataset.symbol || '---';
                const purBuy = parseFloat(purOpt?.dataset.buy) || 1;
                const saleSell = parseFloat(saleOpt?.dataset.sell) || 1;
                const rate = purBuy / saleSell;
                const addPurSymbol = document.querySelector('.add-pur-symbol');
                const addSaleSymbol = document.querySelector('.add-sale-symbol');
                const exchangeRateInput = document.getElementById(prefix + '_invoice_exchange_rate');

                document.querySelector('.' + prefix + '-pur-symbol')?.textContent.replace(purSymbol);
                document.querySelector('.' + prefix + '-sale-symbol')?.textContent.replace(saleSymbol);
                if (addPurSymbol) addPurSymbol.textContent = purSymbol;
                if (addSaleSymbol) addSaleSymbol.textContent = saleSymbol;
                if (exchangeRateInput) exchangeRateInput.value = rate.toFixed(6);
            } else {
                exchangeContainer.style.display = 'none';
                const exchangeRateInput = document.getElementById(prefix + '_invoice_exchange_rate');
                if (exchangeRateInput) exchangeRateInput.value = '1.000000';
            }
            this.calculateEquivalent(prefix);
            this.validateDiscount(prefix);
        },

        calculateEquivalent(prefix) {
            const cost = parseFloat(document.getElementById(prefix + '_purchase_price')?.value) || 0;
            const saleCurrencyId = document.getElementById(prefix + '_sale_currency_id')?.value;
            const purCurrencyId = document.getElementById(prefix + '_purchase_currency_id')?.value;
            const rate = parseFloat(document.getElementById(prefix + '_invoice_exchange_rate')?.value) || 1;
            const saleSymbol = document.querySelector(`#${prefix}_sale_currency_id option:selected`)?.dataset.symbol || 'ر.ي';
        },

        validateDiscount(prefix) {
            const total = parseFloat(document.getElementById(prefix + '_sale_price')?.value) || 0;
            const discount = parseFloat(document.getElementById(prefix + '_discount')?.value) || 0;
            const cost = parseFloat(document.getElementById(prefix + '_purchase_price')?.value) || 0;
            const saleCurrencyId = document.getElementById(prefix + '_sale_currency_id')?.value;
            const purCurrencyId = document.getElementById(prefix + '_purchase_currency_id')?.value;
            const rate = parseFloat(document.getElementById(prefix + '_invoice_exchange_rate')?.value) || 1;

            const costInSaleCurrency = (saleCurrencyId && purCurrencyId && saleCurrencyId != purCurrencyId) ? cost * rate : cost;
            const netPrice = total - discount;

            const discountInput = document.getElementById(prefix + '_discount');
            if (discount > 0 && netPrice < costInSaleCurrency - 0.01) {
                discountInput.classList.add('is-invalid');
            } else {
                discountInput.classList.remove('is-invalid');
            }
        },

        // 1.3 تبديل نوع الجهة (وكيل/فرع)
        handleEntityTypeChange(prefix) {
            const type = document.getElementById(prefix + '_entity_type').value;
            const agentCont = document.getElementById(prefix + '_agent_container');
            const branchCont = document.getElementById(prefix + '_branch_container');
            const agentSelect = document.getElementById(prefix + '_agent_id');
            const branchSelect = document.getElementById(prefix + '_branch_id');

            if (type === 'agent') {
                agentCont.classList.remove('d-none');
                branchCont.classList.add('d-none');
                if (branchSelect) branchSelect.value = '';
            } else {
                agentCont.classList.add('d-none');
                branchCont.classList.remove('d-none');
                if (agentSelect) agentSelect.value = '';
            }
            this.loadServicePrice(prefix);
        },

        updatePaymentLogic(prefix) {
            const paymentType = $(`#${prefix}_payment_type`).val();
            const accountField = $(`#${prefix}_account_field`);
            const accountSelect = $(`#${prefix}_account_id`);
            const customerField = $(`#${prefix}_customer_field`);
            const amountReceived = $(`#${prefix}_amount_received`);

            // تصفير
            accountSelect.empty().append('<option value="">اختر الحساب</option>');
            accountField.addClass('d-none');
            accountSelect.removeAttr('required');
            customerField.addClass('d-none');
            $(`#${prefix}_customer_id`).removeAttr('required');

            if (!paymentType) {
                amountReceived.prop('readonly', true).addClass('bg-light').val('');
                return;
            }

            let accounts = [];
            let label = 'الحساب';

            if (paymentType === 'cash') {
                accounts = <?php echo json_encode($cash_accounts); ?> || [];
                label = 'الصندوق (نقد)';
                amountReceived.prop('readonly', false).removeClass('bg-light');
                accountField.removeClass('d-none');
                accountSelect.attr('required', 'required');
            } else if (paymentType === 'bank_transfer') {
                accounts = <?php echo json_encode($bank_accounts); ?> || [];
                label = 'البنك (تحويل)';
                amountReceived.prop('readonly', false).removeClass('bg-light');
                accountField.removeClass('d-none');
                accountSelect.attr('required', 'required');
            } else if (paymentType === 'credit') {
                amountReceived.prop('readonly', true).addClass('bg-light').val('0');
                customerField.removeClass('d-none');
                $(`#${prefix}_customer_id`).attr('required', 'required');
                return;
            }

            let labelHtml = label + ' <span class="text-danger">*</span>';
            if (accounts.length === 0) {
                labelHtml += ' <span class="badge bg-danger-subtle text-danger small">لا يوجد حسابات!</span>';
            } else {
                accounts.forEach(acc => {
                    accountSelect.append($('<option>', {
                        value: acc.id,
                        text: acc.account_name
                    }));
                });
            }
            accountField.find('label').html(labelHtml);
        },

        // 1.2 تحميل سعر الخدمة تلقائياً
        async loadServicePrice(prefix) {
            const serviceSelect = document.getElementById(prefix + '_profession_id'); // في تاشيرة العمل نستخدم المهنة كخدمة أساسية أو خدمة مرتبطة
            const agentSelect = document.getElementById(prefix + '_agent_id');
            const branchSelect = document.getElementById(prefix + '_branch_id');

            const agentId = agentSelect ? agentSelect.value : "";
            const branchId = branchSelect ? branchSelect.value : "";

            // جلب السعر بناءً على الوكيل أو الفرع المختار
            try {
                const res = await fetch(`ajax_work_visa.php?action=get_service_price&service_id=6&agent_id=${agentId}&branch_id=${branchId}`);
                const data = await res.json();

                if (data.status === 'success') {
                    const saleInput = document.getElementById(prefix + '_total_amount');
                    const purchaseInput = document.getElementById(prefix + '_cost_amount');
                    const saleCurrencySelect = document.getElementById(prefix + '_sale_currency_id');
                    const costCurrencySelect = document.getElementById(prefix + '_main_currency_id');
                    const revenueInput = document.getElementById(prefix + '_service_revenue_account');
                    const costAccountInput = document.getElementById(prefix + '_service_cost_account');
                    const profitInput = document.getElementById(prefix + '_service_profit_account');

                    if (saleInput) saleInput.value = data.sale_price;
                    if (purchaseInput) purchaseInput.value = data.purchase_price;
                    if (saleCurrencySelect) saleCurrencySelect.value = data.currency_id;
                    if (costCurrencySelect) costCurrencySelect.value = data.currency_id;
                    if (revenueInput && data.revenue_account_name) revenueInput.value = data.revenue_account_name;
                    if (costAccountInput && data.cost_account_name) costAccountInput.value = data.cost_account_name;
                    if (profitInput && data.profit_account_name) profitInput.value = data.profit_account_name;

                    [saleCurrencySelect, costCurrencySelect].forEach((el) => {
                        if (el) el.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    [saleInput, purchaseInput].forEach((el) => {
                        if (el) el.dispatchEvent(new Event('input', { bubbles: true }));
                    });
                }
            } catch (err) {
                console.error('Error loading price:', err);
            }
        },

        // 1.1 تحميل متطلبات المهنة والقواعد
        async loadProfessionRequirements(professionId, prefix) {
            const container = document.getElementById(`${prefix}_requirements_container`);
            const list = document.getElementById(`${prefix}_requirements_list`);
            const dobRule = document.getElementById(`${prefix}_dob_rule`);
            const expiryRule = document.getElementById(`${prefix}_passport_expiry_rule`);
            const nationalityRule = document.getElementById(`${prefix}_nationality_rule`);
            const genderRule = document.getElementById(`${prefix}_gender_rule`);

            if (!professionId) {
                if (container) container.style.display = 'none';
                if (list) list.innerHTML = '';
                if (dobRule) dobRule.innerHTML = '';
                if (expiryRule) expiryRule.innerHTML = '';
                if (nationalityRule) nationalityRule.innerHTML = '';
                if (genderRule) genderRule.innerHTML = '';
                return;
            }

            try {
                const selectedGender = this.normalizeGenderValue(document.getElementById(`${prefix}_gender`)?.value || '');
                const res = await fetch(`ajax_work_visa.php?action=get_profession_requirements&profession_id=${professionId}&gender=${encodeURIComponent(selectedGender)}`);
                const data = await res.json();

                // 2. عرض القواعد وحفظها في الـ modal للتحقق اللاحق
                const modal = document.getElementById(`${prefix}Modal`);
                modal.dataset.requirements = JSON.stringify(data.requirements_all || data.requirements || []);
                if (data.rules) {
                    modal.dataset.minAge = data.rules.min_age;
                    modal.dataset.maxAge = data.rules.max_age;
                    modal.dataset.minPassportValidity = data.rules.min_passport_validity_months;
                    modal.dataset.allowedNationalities = JSON.stringify(data.rules.allowed_nationalities_list || []);

                    if (dobRule) dobRule.innerHTML = '';
                    if (expiryRule) expiryRule.innerHTML = '';
                } else {
                    delete modal.dataset.minAge;
                    delete modal.dataset.maxAge;
                    delete modal.dataset.minPassportValidity;
                    delete modal.dataset.allowedNationalities;
                    if (dobRule) dobRule.innerHTML = '';
                    if (expiryRule) expiryRule.innerHTML = '';
                }

                this.renderRequirements(prefix);
                this.updateRuleAlerts(prefix);

                // إعادة التحقق من الحقول الحالية إذا كانت ممتلئة
                const dobInput = document.getElementById(`${prefix}_dob`);
                const expiryInput = document.getElementById(`${prefix}_passport_expiry`);
                if (dobInput && dobInput.value) this.validateField(dobInput);
                if (expiryInput && expiryInput.value) this.validateField(expiryInput);

            } catch (err) {
                console.error('Error loading requirements:', err);
            }
        },

        renderRequirements(prefix) {
            const container = document.getElementById(`${prefix}_requirements_container`);
            const list = document.getElementById(`${prefix}_requirements_list`);
            const modal = document.getElementById(`${prefix}Modal`);
            if (!container || !list || !modal) return;

            let requirements = [];
            try {
                requirements = JSON.parse(modal.dataset.requirements || '[]');
            } catch (e) {
                requirements = [];
            }

            const selectedGender = this.normalizeGenderValue(document.getElementById(`${prefix}_gender`)?.value || '');
            const matched = requirements.filter(req => {
                const reqGender = (req.gender || 'both').toLowerCase();
                return reqGender === 'both' || (selectedGender && reqGender === selectedGender);
            });

            if (!matched.length) {
                container.style.display = 'none';
                list.innerHTML = '';
                return;
            }

            container.style.display = 'block';
            list.innerHTML = matched.map(req => {
                const reqGender = (req.gender || 'both').toLowerCase();
                const genderBadge = reqGender === 'male'
                    ? '<span class="badge bg-primary-subtle text-primary border">للذكر</span>'
                    : (reqGender === 'female'
                        ? '<span class="badge bg-danger-subtle text-danger border">للأنثى</span>'
                        : '<span class="badge bg-secondary-subtle text-secondary border">عام</span>');
                return `<div class="d-inline-flex align-items-center gap-2 px-3 py-2 bg-white rounded-pill border shadow-sm"><span class="small fw-bold">${req.requirement_name}</span>${genderBadge}</div>`;
            }).join('');
        },

        updateRuleAlerts(prefix) {
            const modal = document.getElementById(`${prefix}Modal`);
            if (!modal) return;

            const alertsBox = document.getElementById(`${prefix}_rule_alerts`);
            const nationalityRule = document.getElementById(`${prefix}_nationality_rule`);
            const genderRule = document.getElementById(`${prefix}_gender_rule`);
            const nationalityInput = document.getElementById(`${prefix}_nationality`);
            const genderInput = document.getElementById(`${prefix}_gender`);
            const messages = [];

            let requirements = [];
            let allowedNationalities = [];
            try {
                requirements = JSON.parse(modal.dataset.requirements || '[]');
            } catch (e) {}
            try {
                allowedNationalities = JSON.parse(modal.dataset.allowedNationalities || '[]');
            } catch (e) {}

            const selectedGender = this.normalizeGenderValue(genderInput?.value || '');
            const genderSpecificExists = requirements.some(req => ['male', 'female'].includes((req.gender || 'both').toLowerCase()));
            const matchedGenderRequirements = requirements.filter(req => {
                const reqGender = (req.gender || 'both').toLowerCase();
                return reqGender !== 'both' && selectedGender && reqGender === selectedGender;
            });

            if (genderRule) {
                if (!selectedGender && genderSpecificExists) {
                    genderRule.innerHTML = `<i class="fas fa-circle-info me-1"></i> اختر الجنس لإظهار المتطلبات الخاصة.`;
                    genderRule.className = 'extra-small text-warning mt-1 fw-bold';
                    messages.push('اختر الجنس لإظهار المتطلبات الخاصة بالذكر أو الأنثى.');
                } else if (selectedGender && matchedGenderRequirements.length > 0) {
                    genderRule.innerHTML = `<i class="fas fa-list-check me-1"></i> ظهرت ${matchedGenderRequirements.length} متطلبات مرتبطة بالجنس المحدد.`;
                    genderRule.className = 'extra-small text-primary mt-1 fw-bold';
                } else {
                    genderRule.innerHTML = '';
                }
            }

            if (nationalityInput) nationalityInput.classList.remove('is-invalid');
            if (allowedNationalities.length > 0) {
                if (nationalityInput?.value) {
                    const isAllowed = allowedNationalities.map(v => v.trim().toLowerCase()).includes(nationalityInput.value.trim().toLowerCase());
                    if (nationalityRule) {
                        nationalityRule.innerHTML = isAllowed
                            ? `<i class="fas fa-check-circle me-1"></i> الجنسية مطابقة للقواعد.`
                            : `<i class="fas fa-times-circle me-1"></i> الجنسية الحالية غير مسموح بها لهذه المهنة.`;
                        nationalityRule.className = `extra-small mt-1 fw-bold ${isAllowed ? 'text-success' : 'text-danger'}`;
                    }
                    if (!isAllowed) {
                        nationalityInput.classList.add('is-invalid');
                        messages.push(`الجنسية الحالية غير مسموح بها. المسموح: ${allowedNationalities.join('، ')}`);
                    }
                } else if (nationalityRule) {
                    nationalityRule.innerHTML = `<i class="fas fa-info-circle me-1"></i> الجنسيات المسموح بها: ${allowedNationalities.join('، ')}`;
                    nationalityRule.className = 'extra-small text-primary mt-1 fw-bold';
                }
            } else if (nationalityRule) {
                nationalityRule.innerHTML = '';
            }

            this.renderRequirements(prefix);

            if (alertsBox) {
                if (messages.length > 0) {
                    alertsBox.classList.remove('d-none');
                    alertsBox.innerHTML = messages.map(msg => `<div><i class="fas fa-exclamation-triangle me-1"></i>${msg}</div>`).join('');
                } else {
                    alertsBox.classList.add('d-none');
                    alertsBox.innerHTML = '';
                }
            }
        },

        // 1.2 التحقق من الحقل بناءً على القواعد
        validateField(input) {
            const modal = input.closest('.modal');
            const ruleType = input.dataset.rule;
            const ruleDisplay = document.getElementById(input.id + '_rule');
            if (!ruleType || !modal.dataset.minAge) return;

            const val = input.value;
            if (!val) {
                if (ruleDisplay) ruleDisplay.innerHTML = '';
                input.classList.remove('is-invalid');
                return;
            }

            if (ruleType === 'age') {
                const birthDate = new Date(val);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;

                const min = parseInt(modal.dataset.minAge);
                const max = parseInt(modal.dataset.maxAge);

                if (age < min || age > max) {
                    ruleDisplay.innerHTML = `<i class="fas fa-times-circle me-1"></i> العمر الحالي (${age}) غير مطابق للقواعد (${min}-${max})`;
                    ruleDisplay.className = 'extra-small text-danger mt-1 fw-bold animate__animated animate__shakeX';
                    input.classList.add('is-invalid');
                } else {
                    ruleDisplay.innerHTML = '';
                    input.classList.remove('is-invalid');
                }
            } else if (ruleType === 'passport_validity') {
                const expiryDate = new Date(val);
                const today = new Date();
                const diffTime = expiryDate - today;
                const diffMonths = diffTime / (1000 * 60 * 60 * 24 * 30.44);
                const minMonths = parseInt(modal.dataset.minPassportValidity);

                if (diffMonths < minMonths) {
                    ruleDisplay.innerHTML = `<i class="fas fa-times-circle me-1"></i> الصلاحية (${Math.floor(diffMonths)} شهر) أقل من المطلوب (${minMonths})`;
                    ruleDisplay.className = 'extra-small text-danger mt-1 fw-bold animate__animated animate__shakeX';
                    input.classList.add('is-invalid');
                } else {
                    ruleDisplay.innerHTML = '';
                    input.classList.remove('is-invalid');
                }
            }
            this.updateRuleAlerts(input.id.startsWith('edit_') ? 'edit' : 'add');
        },

        // تصفية الجدول ديناميكياً
        filterTable(query) {
            const q = query.toLowerCase().trim();
            const rows = document.querySelectorAll('table tbody tr');

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                if (text.includes(q)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        },

        // 2. معالجة النقرات (Event Delegation)
        handleClicks(e) {
            const menuToggleBtn = e.target.closest('[data-action-menu-toggle]');
            if (menuToggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.toggleActionMenu(menuToggleBtn.dataset.actionMenuToggle);
                return;
            }

            if (!e.target.closest('.financial-action-group')) {
                this.closeActionMenus();
            }

            // أزرار التفاصيل
            const viewBtn = e.target.closest('.view-details');
            if (viewBtn) {
                e.preventDefault();
                this.openDetailsModal(viewBtn.dataset.id, viewBtn.dataset.tab || 'info');
                return;
            }

            // أزرار التعديل
            const editBtn = e.target.closest('.edit-visa');
            if (editBtn) {
                e.preventDefault();
                this.closeActionMenus();
                this.openEditModal(editBtn.dataset.id);
                return;
            }

            // أزرار الانتقال السريع
            const quickBtn = e.target.closest('.quick-transition') || e.target.closest('.transition-btn');
            if (quickBtn) {
                e.preventDefault();
                this.showTransitionForm(quickBtn.dataset.passportId, quickBtn.dataset.toStep, quickBtn.dataset.toName);
                return;
            }

            // زر حفظ الـ Checklist
            if (e.target.id === 'saveChecklistBtn') {
                this.saveChecklist();
                return;
            }

            // أزرار OCR
            if (e.target.id === 'scan_passport_btn') {
                this.processPassportOCR('add');
                return;
            }
            if (e.target.id === 'edit_scan_passport_btn') {
                this.processPassportOCR('edit');
                return;
            }

            // زر تأكيد الوثائق من الجدول
            const confirmDocsBtn = e.target.closest('.confirm-docs-btn');
            if (confirmDocsBtn) {
                e.preventDefault();
                this.openConfirmDocsModal(confirmDocsBtn.dataset.id);
                return;
            }

            const deleteRecordBtn = e.target.closest('.delete-passport-record');
            if (deleteRecordBtn) {
                e.preventDefault();
                this.closeActionMenus();
                this.deletePassportRecord(deleteRecordBtn.getAttribute('href'));
                return;
            }
        },

        toggleActionMenu(menuId) {
            const target = document.getElementById(menuId);
            if (!target) {
                return;
            }

            const willShow = !target.classList.contains('show');
            this.closeActionMenus();
            if (willShow) {
                target.classList.add('show');
            }
        },

        closeActionMenus() {
            document.querySelectorAll('.financial-action-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        },

        async deletePassportRecord(url) {
            const confirmed = await this.confirmDialog({
                title: 'تأكيد حذف المعاملة',
                text: 'سيتم حذف أصل المعاملة نفسها. هل تريد المتابعة؟',
                icon: 'warning',
                confirmText: 'نعم، احذف المعاملة',
                cancelText: 'تراجع'
            });

            if (confirmed && url) {
                window.location.href = url;
            }
        },

        // فتح مودال تأكيد استلام الوثائق للمسؤول
        async openConfirmDocsModal(id) {
            const modalEl = document.getElementById('detailsModal');
            const content = document.getElementById('detailsModalContent');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
            modal.show();

            try {
                const res = await fetch(`ajax_work_visa.php?action=get_work_visa_details&id=${id}`);
                const json = await res.json();

                if (json.status === 'success') {
                    const data = json.data;
                    // تخصيص العرض ليكون تركيزه على الـ Checklist والاعتماد
                    let html = `
                    <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4">
                        <h6 class="fw-bold mb-1"><i class="fas fa-info-circle me-2"></i> تأكيد استلام وثائق المعاملة</h6>
                        <p class="small mb-0">يرجى مراجعة الوثائق المحددة من قبل الوكيل وتأكيد استلامها فعلياً.</p>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-5">
                            <div class="card border-0 bg-light rounded-4 p-4 h-100 border border-secondary-subtle">
                                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">بيانات المعاملة</h6>
                                <div class="mb-3"><small class="text-muted fw-bold d-block mb-1">اسم صاحب الجواز:</small> <div class="fw-bold fs-6 text-dark">${data.full_name}</div></div>
                                <div class="mb-3"><small class="text-muted fw-bold d-block mb-1">رقم الجواز:</small> <div class="fw-bold fs-6 text-primary font-monospace">${data.passport_number}</div></div>
                                <div class="mb-3"><small class="text-muted fw-bold d-block mb-1">المهنة:</small> <div class="fw-bold text-success">${data.profession_name}</div></div>
                                <div class="mb-3"><small class="text-muted fw-bold d-block mb-1">الجهة المرسلة:</small> <div class="fw-bold text-dark">${data.agent_name || data.branch_name}</div></div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="card border-0 shadow-sm rounded-4 p-4 border border-warning-subtle h-100">
                                <h6 class="fw-bold text-warning border-bottom pb-2 mb-3">قائمة التحقق (Checklist)</h6>
                                <div class="list-group list-group-flush">
                                    ${data.checklist.map(item => `
                                        <div class="list-group-item d-flex align-items-center justify-content-between py-3 border-0 px-0 mb-2 rounded-3 ${item.relayer_verified == 1 ? 'bg-success-subtle border-start border-success border-4' : (item.is_completed == 1 ? 'bg-info-subtle border-start border-info border-4' : 'bg-light')}">
                                            <div class="ps-3">
                                                <div class="fw-bold text-dark">${item.requirement_name}</div>
                                                ${item.relayer_verified == 1 ?
                                                    '<span class="extra-small text-success fw-bold"><i class="fas fa-check-double me-1"></i> تم التأكيد والاستلام</span>' :
                                                    (item.is_completed == 1 ? '<span class="extra-small text-info fw-bold"><i class="fas fa-check-circle me-1"></i> حدده الوكيل كمستلم</span>' : '<span class="extra-small text-muted">لم يحدده الوكيل</span>')
                                                }
                                            </div>
                                            <div class="d-flex align-items-center gap-2 pe-3">
                                                ${item.relayer_verified == 0 ? `
                                                    <button class="btn btn-sm btn-success rounded-pill px-3 extra-small fw-bold" onclick="WorkVisa.verifySingleItem(${data.id}, ${item.requirement_id}, 1)">
                                                        <i class="fas fa-check me-1"></i> تأكيد الاستلام
                                                    </button>
                                                ` : (
                                                    // إظهار زر التراجع فقط لمن لديه الصلاحية أو الأدمن
                                                    (<?php echo has_permission('work_visa_edit_verified_docs') ? 'true' : 'false'; ?> ||
                                                     ['admin', 'developer', 'مدير', 'مبرمج'].includes('<?php echo $_SESSION['role']; ?>'.toLowerCase())) ? `
                                                    <button class="btn btn-sm btn-outline-danger rounded-pill px-3 extra-small fw-bold" onclick="WorkVisa.verifySingleItem(${data.id}, ${item.requirement_id}, 0)">
                                                        <i class="fas fa-undo me-1"></i> تراجع
                                                    </button>
                                                ` : '<span class="badge bg-success-subtle text-success extra-small"><i class="fas fa-check-double"></i> مؤكد</span>'
                                                )}
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                                <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                                    <div class="small text-muted">
                                        <i class="fas fa-info-circle me-1"></i> يمكنك تأكيد كل وثيقة على حدة.
                                    </div>
                                    <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="WorkVisa.finishVerification(${data.id})">
                                        <i class="fas fa-check-double me-2"></i> إنهاء التدقيق والمتابعة
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                    content.innerHTML = html;

                    // إخفاء زر الحفظ الافتراضي للمودال
                    const saveBtn = document.getElementById('saveChecklistBtn');
                    if (saveBtn) saveBtn.classList.add('d-none');
                } else {
                    content.innerHTML = `<div class="alert alert-danger">${json.message}</div>`;
                }
            } catch (err) {
                content.innerHTML = `<div class="alert alert-danger">خطأ في الاتصال بالخادم</div>`;
            }
        },

        // تأكيد استلام وثيقة واحدة
        async verifySingleItem(passportId, reqId, verified) {
            try {
                const fd = new FormData();
                fd.append('passport_id', passportId);
                fd.append('requirement_id', reqId);
                fd.append('verified', verified);
                fd.append('csrf_token', CSRF_TOKEN);

                const res = await fetch('ajax_work_visa.php?action=relayer_verify_item', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();

                if (json.status === 'success') {
                    // إعادة تحميل المودال لتحديث الحالة
                    this.openConfirmDocsModal(passportId);
                } else {
                    await this.alertDialog({
                        title: json.status === 'success' ? 'تم التحديث' : 'تعذر التأكيد',
                        text: json.message || 'تعذر تنفيذ العملية',
                        icon: json.status === 'success' ? 'success' : 'error'
                    });
                }
            } catch (err) {
                await this.alertDialog({
                    title: 'خطأ في التأكيد',
                    text: 'حدث خطأ أثناء التأكيد',
                    icon: 'error'
                });
            }
        },

        // إنهاء التدقيق والانتقال للمرحلة التالية
        async finishVerification(id) {
            const confirmed = await this.confirmDialog({
                title: 'إنهاء التدقيق',
                text: 'هل انتهيت من تدقيق كافة الوثائق وتريد الانتقال للمرحلة التالية؟',
                icon: 'question',
                confirmText: 'نعم، متابعة',
                cancelText: 'تراجع'
            });
            if (!confirmed) return;

            try {
                // جلب البيانات للتأكد من الانتقالات المتاحة
                const resDetails = await fetch(`ajax_work_visa.php?action=get_work_visa_details&id=${id}`);
                const dataDetails = await resDetails.json();

                let targetStepId = null;
                if (dataDetails.status === 'success' && dataDetails.data.transitions) {
                    // البحث عن انتقال للمرحلة التالية (مثلاً "تم تأكيد استلام الوثائق")
                    const targetTransition = dataDetails.data.transitions.find(t => t.to_step_name.includes('تأكيد استلام') || t.to_step_name.includes('تم الاستلام'));
                    if (targetTransition) targetStepId = targetTransition.to_step_id;
                }

                if (targetStepId) {
                    const fdTrans = new FormData();
                    fdTrans.append('passport_id[]', id);
                    fdTrans.append('to_step_id', targetStepId);
                    fdTrans.append('notes', 'تم تدقيق الوثائق وتأكيد استلامها.');

                    const resTrans = await fetch('ajax_work_visa.php?action=process_transition', {
                        method: 'POST',
                        body: fdTrans
                    });
                    const jsonTrans = await resTrans.json();
                    await this.alertDialog({
                        title: jsonTrans.status === 'success' ? 'تم تحديث المرحلة' : 'تعذر الإنهاء',
                        text: jsonTrans.message || 'تعذر تنفيذ العملية',
                        icon: jsonTrans.status === 'success' ? 'success' : 'error'
                    });
                    location.reload();
                } else {
                    await this.alertDialog({
                        title: 'تم حفظ التدقيق',
                        text: 'تم تحديث قائمة الوثائق بنجاح. لا توجد مرحلة انتقالية مبرمجة حالياً.',
                        icon: 'success'
                    });
                    location.reload();
                }
            } catch (err) {
                await this.alertDialog({
                    title: 'خطأ في الإنهاء',
                    text: 'حدث خطأ أثناء الإنهاء',
                    icon: 'error'
                });
            }
        },

        // 3. فتح مودال التفاصيل
        async openDetailsModal(id, targetTab = 'info') {
            const modalEl = document.getElementById('detailsModal');
            const content = document.getElementById('detailsModalContent');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
            modal.show();

            try {
                const res = await fetch(`ajax_work_visa.php?action=get_work_visa_details&id=${id}`);
                const text = await res.text();

                let json;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    content.innerHTML = `<div class="alert alert-danger">
                    <strong>خطأ في استجابة الخادم:</strong> الاستجابة ليست بتنسيق JSON صحيح.
                    <hr>
                    <small class="extra-small d-block text-start" style="max-height: 200px; overflow: auto;">${text}</small>
                </div>`;
                    return;
                }

                if (json.status === 'success') {
                    this.renderDetails(json.data);
                    // تخزين المعرف الحالي للزر
                    const saveBtn = document.getElementById('saveChecklistBtn');
                    if (saveBtn) saveBtn.dataset.passportId = id;

                    // تفعيل التبويب المستهدف
                    if (targetTab === 'workflow') {
                        const tabTrigger = document.getElementById('workflow-tab');
                        if (tabTrigger) bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
                    }
                } else {
                    content.innerHTML = `<div class="alert alert-danger">${json.message || 'خطأ غير معروف'}</div>`;
                }
            } catch (err) {
                console.error('Fetch error:', err);
                content.innerHTML = `<div class="alert alert-danger">خطأ في الاتصال بالخادم: ${err.message}</div>`;
            }
        },

        // 4. بناء واجهة التفاصيل ديناميكياً
        renderDetails(data) {
            const content = document.getElementById('detailsModalContent');
            const canVerify = <?php echo has_permission('document_receiver_confirm') ? 'true' : 'false'; ?> || ['admin', 'relayer', 'مرحل'].includes('<?php echo $_SESSION['role']; ?>'.toLowerCase());
            const isAgentOrBranch = ['agent', 'branch', 'وكيل', 'فرع'].includes('<?php echo $_SESSION['role']; ?>'.toLowerCase());
            const canViewHistory = <?php echo (has_permission('work_visa_view_history') || has_permission('work_visa_view_workflow') || has_permission('request_document_confirmation')) ? 'true' : 'false'; ?>;
            const canEditWorkflow = <?php echo (has_permission('work_visa_edit_workflow') || has_permission('request_document_confirmation')) ? 'true' : 'false'; ?>;

            let html = `
            <ul class="nav nav-pills nav-fill mb-4 bg-light p-1 rounded-pill" id="detailsTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active rounded-pill" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab"><i class="fas fa-info-circle me-1"></i> البيانات الأساسية</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link rounded-pill" id="workflow-tab" data-bs-toggle="tab" data-bs-target="#workflow" type="button" role="tab"><i class="fas fa-tasks me-1"></i> ${canViewHistory ? 'سير العمل والسجل' : 'سير العمل'}</button>
                </li>
                ${data.paid_amount !== undefined ? `
                <li class="nav-item">
                    <button class="nav-link rounded-pill" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial" type="button" role="tab"><i class="fas fa-wallet me-1"></i> البيانات المالية</button>
                </li>
                ` : ''}
            </ul>

            <div class="tab-content" id="detailsTabsContent">
                <!-- تبويب المعلومات الأساسية -->
                <div class="tab-pane fade show active" id="info" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="card border-0 bg-light rounded-4 p-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                                    <h6 class="fw-bold text-primary mb-0"><i class="fas fa-info-circle me-2"></i> البيانات الشخصية</h6>
                                    <span class="badge rounded-pill px-3 py-2" style="background-color: ${data.status_color}20; color: ${data.status_color}; border: 1px solid ${data.status_color};">
                                        ${data.status_name}
                                    </span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-3"><span class="text-muted small">الاسم الكامل:</span> <div class="fw-bold">${data.full_name}</div></div>
                                    <div class="col-md-3"><span class="text-muted small">English Name:</span> <div class="fw-bold font-monospace">${data.full_name_en || '---'}</div></div>
                                    <div class="col-md-3"><span class="text-muted small">رقم الجواز:</span> <div class="fw-bold text-primary">${data.passport_number}</div></div>
                                    <div class="col-md-3"><span class="text-muted small">الجنسية:</span> <div class="fw-bold">${data.nationality || '---'}</div></div>
                                    <div class="col-md-3"><span class="text-muted small">الجنس (Sex):</span> <div class="fw-bold">${data.gender === 'Male' ? 'ذكر' : (data.gender === 'Female' ? 'أنثى' : '---')}</div></div>
                                    <div class="col-md-3"><span class="text-muted small">المهنة:</span> <div class="fw-bold text-success">${data.profession_name || '---'}</div></div>
                                    <div class="col-md-3"><span class="text-muted small">الوكيل/الفرع:</span> <div class="small fw-bold">${data.agent_name || data.branch_name || '---'}</div></div>
                                    <div class="col-md-3"><span class="text-muted small">الدفعة:</span> <div class="small fw-bold text-primary">${data.batch_name || '---'}</div></div>
                                </div>

                                ${data.batch_no || data.visa_no || data.embassy_exit_date || data.request_date ? `
                                <h6 class="fw-bold text-dark border-bottom pb-2 mt-4 mb-3"><i class="fas fa-file-invoice me-2"></i> بيانات ترحيل وتأشيرة</h6>
                                <div class="row g-3">
                                    ${data.batch_no ? `<div class="col-md-3"><span class="text-muted small">رقم الباتش:</span> <div class="fw-bold">${data.batch_no}</div></div>` : ''}
                                    ${data.request_date ? `<div class="col-md-3"><span class="text-muted small">تاريخ الطلب:</span> <div class="fw-bold">${data.request_date}</div></div>` : ''}
                                    ${data.visa_no ? `<div class="col-md-3"><span class="text-muted small">رقم التأشيرة:</span> <div class="fw-bold text-success">${data.visa_no}</div></div>` : ''}
                                    ${data.visa_issue_date ? `<div class="col-md-3"><span class="text-muted small">تاريخ الإصدار:</span> <div class="fw-bold">${data.visa_issue_date}</div></div>` : ''}
                                    ${data.visa_expiry_date ? `<div class="col-md-3"><span class="text-muted small">تاريخ الانتهاء:</span> <div class="fw-bold text-danger">${data.visa_expiry_date}</div></div>` : ''}
                                    ${data.embassy_exit_date ? `<div class="col-md-3"><span class="text-muted small">الخروج من السفارة:</span> <div class="fw-bold text-primary">${data.embassy_exit_date}</div></div>` : ''}
                                    ${data.arrival_office_date ? `<div class="col-md-3"><span class="text-muted small">وصول المكتب:</span> <div class="fw-bold text-info">${data.arrival_office_date}</div></div>` : ''}
                                    ${data.main_branch_delivery_date ? `<div class="col-md-3"><span class="text-muted small">التسليم للفرع:</span> <div class="fw-bold">${data.main_branch_delivery_date}</div></div>` : ''}
                                </div>
                                ` : ''}

                                <h6 class="fw-bold text-primary border-bottom pb-2 mt-4 mb-3"><i class="fas fa-paperclip me-2"></i> المرفقات والوثائق</h6>
                                <div class="row g-3">
                                    ${['passport_image', 'personal_photo', 'exit_image', 'authorization_image', 'deportation_image', 'letter_image', 'print_image'].map(key => {
                                        if (!data[key]) return '';
                                        const labels = {
                                            'passport_image': 'صورة الجواز',
                                            'personal_photo': 'الصورة الشخصية',
                                            'exit_image': 'تأشيرة الخروج',
                                            'authorization_image': 'التفويض',
                                            'deportation_image': 'بلاغ الهروب',
                                            'letter_image': 'خطاب التنازل',
                                            'print_image': 'برنت الجوازات'
                                        };
                                        return `
                                            <div class="col-md-2">
                                                <div class="card border rounded-4 p-2 text-center bg-white h-100 shadow-sm">
                                                    <img src="../assets/uploads/${data[key]}"
                                                         class="img-fluid rounded-3 mb-2 shadow-sm"
                                                         style="max-height: 100px; min-height: 100px; object-fit: contain; background: #f8f9fa;">
                                                    <div class="fw-bold extra-small text-dark mb-2">${labels[key] || 'عرض الملف'}</div>
                                                    <div class="d-flex gap-1 mt-auto">
                                                        <a href="../assets/uploads/${data[key]}"
                                                           target="_blank"
                                                           class="btn btn-xs btn-light border flex-grow-1 extra-small rounded-pill p-1">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="../assets/uploads/${data[key]}"
                                                           download
                                                           class="btn btn-xs btn-primary flex-grow-1 extra-small rounded-pill p-1">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        `;
                                    }).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- تبويب سير العمل -->
                <div class="tab-pane fade" id="workflow" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-12">
                            ${data.group_members && data.group_members.length > 0 ? `
                            <div class="card border-0 bg-white shadow-sm rounded-4 p-4 mb-3">
                                <h6 class="fw-bold text-info border-bottom pb-2 mb-3"><i class="fas fa-users me-2"></i> أفراد المجموعة / العائلة</h6>
                                <div class="list-group list-group-flush small">
                                    <div class="row">
                                        ${data.group_members.map(member => `
                                            <div class="col-md-4">
                                                <div class="list-group-item d-flex align-items-center justify-content-between py-2 border-0 px-0">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="fw-bold">${member.full_name}</div>
                                                        <div class="extra-small text-muted">${member.passport_number}</div>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input member-check" type="checkbox" value="${member.id}" checked id="member_${member.id}">
                                                    </div>
                                                </div>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                                <div class="mt-2 text-end">
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill extra-small" onclick="WorkVisa.toggleAllMembers(true)">تحديد الكل</button>
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill extra-small" onclick="WorkVisa.toggleAllMembers(false)">إلغاء الكل</button>
                                </div>
                            </div>
                            ` : ''}
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="card border-0 bg-white shadow-sm rounded-4 p-4 h-100">
                                        <h6 class="fw-bold text-warning border-bottom pb-2 mb-3 d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-tasks me-2"></i> قائمة التحقق (Checklist)</span>
                                            ${data.status_name.includes('تأكيد استلام') ? `<span class="badge bg-success extra-small">وثائق مستلمة ومؤكدة</span>` : ''}
                                        </h6>
                                        <div class="list-group list-group-flush" id="checklist-container">
                                            ${data.checklist.length > 0 ? data.checklist.map(item => `
                                                <div class="list-group-item d-flex align-items-center justify-content-between py-2 border-0 px-0 mb-2 rounded-3 ${item.relayer_verified == 1 ? 'bg-success-subtle' : (data.status_name.includes('تأكيد استلام') && item.is_completed == 1 ? 'bg-danger-subtle' : (item.is_completed == 1 ? 'bg-info-subtle' : 'bg-light'))}">
                                                    <div class="ps-2">
                                                        <div class="small fw-bold">${item.requirement_name}</div>
                                                        ${item.relayer_verified == 1 ?
                                                            `<small class="extra-small text-success fw-bold"><i class="fas fa-check-double me-1"></i> تم استلامه وتأكيده (بواسطة: ${item.verifier_name})</small>` :
                                                            (data.status_name.includes('تأكيد استلام') && item.is_completed == 1 ?
                                                                `<small class="extra-small text-danger fw-bold"><i class="fas fa-times-circle me-1"></i> لم يتم استلامه من قبل الإدارة</small>` :
                                                                (item.is_completed == 1 ? `<small class="extra-small text-info fw-bold"><i class="fas fa-check me-1"></i> بانتظار تأكيد الاستلام من الفرع الرئيسي</small>` : `<small class="extra-small text-muted">لم يتم تسليمه بعد</small>`)
                                                            )
                                                        }
                                                    </div>
                                                    <div class="form-check form-switch pe-2">
                                                        <input class="form-check-input verify-item" type="checkbox"
                                                               data-req-id="${item.requirement_id}"
                                                               ${item.is_completed == 1 || item.relayer_verified == 1 ? 'checked' : ''}
                                                               ${data.status_name.includes('تأكيد استلام') && item.relayer_verified == 1 ? 'disabled' : ''}>
                                                    </div>
                                                </div>
                                            `).join('') : '<div class="text-center py-4 text-muted">لا توجد متطلبات محددة لهذه المهنة</div>'}
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border-0 bg-white shadow-sm rounded-4 p-4 mb-3">
                                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3 d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-route me-2"></i> الإجراء القادم</span>
                                            ${data.all_workflow_steps && data.all_workflow_steps.length > 0 && ['admin', 'relayer', 'مدير', 'مرحل'].includes('<?php echo $_SESSION['role']; ?>'.toLowerCase()) ? `
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary rounded-pill extra-small px-2" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-edit me-1"></i> تعديل السير
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 small">
                                                        <li><h6 class="dropdown-header extra-small text-muted">تغيير المرحلة يدوياً</h6></li>
                                                        ${data.all_workflow_steps.map(step => `
                                                            <li>
                                                                <a class="dropdown-item d-flex align-items-center justify-content-between py-2 ${step.id == data.current_step_id ? 'active' : ''}"
                                                                   href="javascript:void(0)"
                                                                   onclick="WorkVisa.manualTransition(${data.id}, ${step.id}, '${step.step_name}')">
                                                                    <span>${step.step_name}</span>
                                                                    ${step.id == data.current_step_id ? '<i class="fas fa-check-circle ms-2"></i>' : ''}
                                                                </a>
                                                            </li>
                                                        `).join('')}
                                                    </ul>
                                                </div>
                                            ` : ''}
                                        </h6>

                                        <div id="transition_form_container" class="d-none mb-3 p-3 bg-light rounded-4 border border-primary">
                                            <h6 class="fw-bold text-primary mb-3" id="target_step_name">نقل إلى: ...</h6>
                                            <div id="dynamic_fields_container" class="row g-2 mb-3"></div>
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">ملاحظات الانتقال</label>
                                                <textarea id="transition_notes" class="form-control form-control-sm rounded-3" rows="2"></textarea>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button class="btn btn-sm btn-primary rounded-pill px-4" id="confirm_transition_btn">تأكيد النقل</button>
                                                <button class="btn btn-sm btn-light rounded-pill px-3" onclick="WorkVisa.cancelTransition()">إلغاء</button>
                                            </div>
                                        </div>

                                        <div class="d-grid gap-2" id="transitions_list">
                                            ${data.pending_approval ? `
                                                <div class="alert alert-warning border-0 shadow-sm rounded-4 py-3 mb-0 text-center animate__animated animate__pulse animate__infinite">
                                                    <i class="fas fa-clock-rotate-left fa-2x mb-2 text-warning"></i>
                                                    <div class="fw-bold">الطلب قيد المعالجة</div>
                                                    <div class="extra-small text-muted mt-1">بانتظار تأكيد ${data.pending_approval.to_step_name} من قبل الإدارة</div>
                                                </div>
                                            ` : (data.transitions && data.transitions.length > 0 ?
                                                data.transitions.map(tr => {
                                                    // إظهار الزر فقط إذا كان للمستخدم صلاحية تعديل سير العمل أو إذا كان هو الوكيل صاحب الطلب
                                                    if (canEditWorkflow) {
                                                        return `
                                                            <button class="btn btn-primary btn-lg rounded-pill transition-btn text-center px-4 py-3 shadow-sm fw-bold"
                                                                    data-passport-id="${data.id}"
                                                                    data-to-step="${tr.to_step_id}"
                                                                    data-to-name="${tr.to_step_name}">
                                                                <i class="fas fa-bolt me-2"></i> تنفيذ: ${tr.to_step_name}
                                                            </button>
                                                        `;
                                                    }
                                                    return '';
                                                }).join('') : (canEditWorkflow ? '<div class="alert alert-light small py-3 text-center">لا توجد انتقالات متاحة حالياً للمرحلة التالية</div>' : '<div class="alert alert-light small py-3 text-center">لا تملك صلاحية تعديل سير العمل</div>')
                                            )}
                                        </div>
                                    </div>

                                    <div class="card border-0 bg-danger-subtle rounded-4 p-4 mb-3">
                                        <h6 class="fw-bold text-danger mb-3"><i class="fas fa-sticky-note me-2"></i> ملاحظات المرحل</h6>
                                        <div class="mb-3">
                                            <textarea id="relayer_note_text" class="form-control form-control-sm rounded-3" rows="3" placeholder="أضف ملاحظات للمرسل (الوكيل/الفرع)...">${data.relayer_notes || ''}</textarea>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <button class="btn btn-sm btn-danger rounded-pill px-3" onclick="WorkVisa.addRelayerNote(${data.id})">إرسال الملاحظة</button>
                                            ${data.is_resolved == 0 ? `
                                                <button class="btn btn-sm btn-success rounded-pill px-3" onclick="WorkVisa.markResolved(${data.id})">تم الحل</button>
                                            ` : ''}
                                        </div>
                                    </div>

                                    ${canViewHistory ? `
                                    <div class="card border-0 bg-white shadow-sm rounded-4 p-4">
                                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3 d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-history me-2 text-info"></i> سجل الحركات</span>
                                            <span class="badge bg-light text-dark border extra-small">${data.audit_logs.length} حركات</span>
                                        </h6>
                                        <div class="timeline-small small" style="max-height: 250px; overflow-y: auto;">
                                            ${data.audit_logs.map(log => `
                                                <div class="mb-3 border-start ps-3 position-relative">
                                                    <div class="position-absolute start-0 top-0 translate-middle-x bg-info rounded-circle" style="width: 10px; height: 10px; margin-left: -5px; margin-top: 5px;"></div>
                                                    <div class="fw-bold text-dark">${log.new_status}</div>
                                                    <div class="extra-small text-muted mb-1">${log.changed_at}</div>
                                                    <div class="extra-small text-primary">${log.changer_name} (${log.role_name || '---'})</div>
                                                    ${log.notes ? `<div class="extra-small bg-light p-1 rounded mt-1 text-muted italic">"${log.notes}"</div>` : ''}
                                                </div>
                                            `).join('')}
                                        </div>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- تبويب البيانات المالية (جديد) -->
                ${data.paid_amount !== undefined ? `
                <div class="tab-pane fade" id="financial" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-header bg-white border-0 py-3">
                                    <h6 class="fw-bold mb-0"><i class="fas fa-book me-2 text-primary"></i> حسابات الخدمة</h6>
                                </div>
                                <div class="card-body">
                                    <div class="list-group list-group-flush small">
                                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                            <span class="text-muted">حساب الإيرادات</span>
                                            <span class="fw-bold text-success text-end">${data.service_accounts?.revenue_account_name || 'غير محدد'}</span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                            <span class="text-muted">حساب التكلفة</span>
                                            <span class="fw-bold text-danger text-end">${data.service_accounts?.cost_account_name || 'غير محدد'}</span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                            <span class="text-muted">حساب الأرباح</span>
                                            <span class="fw-bold text-warning text-end">${data.service_accounts?.profit_account_name || 'غير محدد'}</span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                            <span class="text-muted">آخر سند قبض</span>
                                            <span class="fw-bold text-end">${data.last_payment ? '#' + data.last_payment.receipt_number : 'لا يوجد'}</span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                            <span class="text-muted">المبلغ المقبوض</span>
                                            <span class="fw-bold text-success">${Number(data.paid_amount || 0).toLocaleString()} ${data.currency_symbol || ''}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold mb-0"><i class="fas fa-file-invoice-dollar me-2 text-success"></i> فاتورة البيع</h6>
                                    ${data.sales_invoice ? `<span class="badge bg-light text-dark border">${data.sales_invoice.invoice_number}</span>` : ''}
                                </div>
                                <div class="card-body">
                                    ${data.sales_invoice ? `
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <span class="badge bg-success-subtle text-success border">${this.getInvoicePaymentStatusLabel(data.sales_invoice.payment_status)}</span>
                                            <span class="badge ${data.sales_invoice.invoice_status === 'posted' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary'} border">${this.getInvoiceStatusLabel(data.sales_invoice.invoice_status)}</span>
                                            <span class="badge bg-warning-subtle text-warning border">${this.getDeliveryTypeLabel(data.sales_invoice.delivery_type)}</span>
                                        </div>
                                        <div class="list-group list-group-flush small">
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">الحساب</span><span class="fw-bold text-end">${data.sales_invoice.account_display || '---'}</span></div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">العميل / الوكيل</span><span class="fw-bold text-end">${data.sales_invoice.counterparty_name || '---'}</span></div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">المبلغ</span><span class="fw-bold">${Number(data.sales_invoice.total_amount || 0).toLocaleString()} ${data.sales_invoice.currency_symbol || ''}</span></div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">المسدد</span><span class="fw-bold">${Number(data.sales_invoice.amount_received || 0).toLocaleString()} ${data.sales_invoice.currency_symbol || ''}</span></div>
                                        </div>
                                        <a class="btn btn-outline-success rounded-pill w-100 mt-3" href="invoice_details.php?id=${data.sales_invoice.id}" target="_blank">فتح الفاتورة</a>
                                    ` : '<div class="text-center text-muted py-4">لا توجد فاتورة بيع مرتبطة</div>'}
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold mb-0"><i class="fas fa-file-invoice me-2 text-danger"></i> فاتورة الشراء</h6>
                                    ${data.purchase_invoice ? `<span class="badge bg-light text-dark border">${data.purchase_invoice.invoice_number}</span>` : ''}
                                </div>
                                <div class="card-body">
                                    ${data.purchase_invoice ? `
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <span class="badge bg-success-subtle text-success border">${this.getInvoicePaymentStatusLabel(data.purchase_invoice.payment_status)}</span>
                                            <span class="badge ${data.purchase_invoice.invoice_status === 'posted' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary'} border">${this.getInvoiceStatusLabel(data.purchase_invoice.invoice_status)}</span>
                                            <span class="badge bg-warning-subtle text-warning border">${this.getDeliveryTypeLabel(data.purchase_invoice.delivery_type)}</span>
                                        </div>
                                        <div class="list-group list-group-flush small">
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">المورد</span><span class="fw-bold text-end">${data.purchase_invoice.counterparty_name || '---'}</span></div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">الحساب</span><span class="fw-bold text-end">${data.purchase_invoice.account_display || '---'}</span></div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">المبلغ</span><span class="fw-bold">${Number(data.purchase_invoice.total_amount || 0).toLocaleString()} ${data.purchase_invoice.currency_symbol || ''}</span></div>
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="text-muted">المسدد</span><span class="fw-bold">${Number(data.purchase_invoice.amount_received || 0).toLocaleString()} ${data.purchase_invoice.currency_symbol || ''}</span></div>
                                        </div>
                                        <a class="btn btn-outline-danger rounded-pill w-100 mt-3" href="invoice_details.php?id=${data.purchase_invoice.id}" target="_blank">فتح الفاتورة</a>
                                    ` : '<div class="text-center text-muted py-4">لا توجد فاتورة شراء مرتبطة</div>'}
                                </div>
                            </div>
                        </div>
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}
            </div>
        `;
            content.innerHTML = html;
        },

        getPaymentStatusLabel(status) {
            const labels = {
                'unpaid': 'غير مدفوع',
                'partial': 'مدفوع جزئياً',
                'partially_paid': 'مدفوع جزئياً',
                'fully_paid': 'مدفوع بالكامل',
                'awaiting_approval': 'بانتظار الاعتماد',
                'posted': 'مرحل مالياً'
            };
            return labels[status] || status;
        },

        getInvoicePaymentStatusLabel(status) {
            return this.getPaymentStatusLabel(status);
        },

        getInvoiceStatusLabel(status) {
            const labels = {
                'draft': 'غير مرحل',
                'posted': 'مرحل',
                'cancelled': 'ملغي'
            };
            return labels[status] || status;
        },

        getDeliveryTypeLabel(type) {
            const labels = {
                'cash': 'صندوق',
                'credit': 'آجل',
                'bank_transfer': 'بنك',
                'agent': 'وكيل',
                'branch': 'فرع',
                'draft': 'مسودة'
            };
            return labels[type] || type;
        },

        // اعتماد مالي
        async approveFinance(id) {
            const confirmed = await this.confirmDialog({
                title: 'اعتماد الحسابات',
                text: 'هل أنت متأكد من اعتماد الحسابات لهذه المعاملة؟',
                icon: 'question',
                confirmText: 'نعم، اعتماد',
                cancelText: 'إلغاء'
            });
            if (!confirmed) return;

            try {
                const formData = new FormData();
                formData.append('id', id);

                const res = await fetch('ajax_work_visa.php?action=approve_finance', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.status === 'success') {
                    await this.alertDialog({
                        title: 'تم الاعتماد',
                        text: result.message || 'تم اعتماد الحسابات بنجاح',
                        icon: 'success'
                    });
                    this.openDetailsModal(id); // تحديث العرض
                } else {
                    await this.alertDialog({
                        title: 'تعذر الاعتماد',
                        text: result.message || 'تعذر اعتماد الحسابات',
                        icon: 'error'
                    });
                }
            } catch (err) {
                await this.alertDialog({
                    title: 'خطأ في المعالجة',
                    text: 'حدث خطأ أثناء المعالجة',
                    icon: 'error'
                });
            }
        },

        // انتقال يدوي للمرحلة (للأدمن)
        async manualTransition(passportId, toStepId, stepName) {
            const confirmed = await this.confirmDialog({
                title: 'تغيير يدوي للمرحلة',
                html: `<div class="text-end">
                    <div class="mb-2">هل أنت متأكد من تغيير مرحلة المعاملة يدويًا إلى:</div>
                    <div class="fw-bold text-primary mb-2">${this.escapeHtml(stepName)}</div>
                    <div class="small text-muted">سيتم تجاوز القواعد المعتادة لسير العمل.</div>
                </div>`,
                icon: 'warning',
                confirmText: 'نعم، غيّر المرحلة',
                cancelText: 'إلغاء'
            });
            if (!confirmed) return;

            try {
                const formData = new FormData();
                formData.append('passport_id', passportId);
                formData.append('to_step_id', toStepId);
                formData.append('notes', 'تغيير يدوي لمرحلة سير العمل بواسطة الإدارة');

                const res = await fetch('ajax_work_visa.php?action=process_transition', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.status === 'success') {
                    await this.alertDialog({
                        title: 'تم تغيير المرحلة',
                        text: result.message || 'تم تحديث المرحلة بنجاح',
                        icon: 'success'
                    });
                    this.openDetailsModal(passportId); // تحديث العرض
                } else {
                    await this.alertDialog({
                        title: 'تعذر تغيير المرحلة',
                        text: result.message || 'تعذر تنفيذ العملية',
                        icon: 'error'
                    });
                }
            } catch (err) {
                console.error('Manual transition error:', err);
                await this.alertDialog({
                    title: 'خطأ في تغيير المرحلة',
                    text: 'حدث خطأ أثناء محاولة تغيير المرحلة',
                    icon: 'error'
                });
            }
        },

        // ترحيل مالي
        async postFinance(id, scope = 'all') {
            const scopeLabel = scope === 'sales' ? 'فاتورة البيع فقط' : (scope === 'purchase' ? 'فاتورة الشراء فقط' : 'كل الفواتير');
            const confirmed = await this.confirmDialog({
                title: 'تأكيد الترحيل',
                text: `هل أنت متأكد من ترحيل ${scopeLabel} لهذه المعاملة؟ سيتم تحديث القيود المحاسبية بحسب الاختيار.`,
                icon: 'question',
                confirmText: 'نعم، ترحيل',
                cancelText: 'إلغاء'
            });
            if (!confirmed) return;

            try {
                this.closeActionMenus();
                const formData = new FormData();
                formData.append('id', id);
                formData.append('scope', scope);
                formData.append('csrf_token', CSRF_TOKEN);

                const res = await fetch('ajax_work_visa.php?action=post_finance', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.status === 'success') {
                    this.showNotice(result.message, 'success');
                    window.location.reload();
                } else {
                    this.showNotice(result.message || 'تعذر تنفيذ الترحيل المطلوب', 'error');
                }
            } catch (err) {
                this.showNotice('حدث خطأ أثناء تنفيذ الترحيل المالي', 'error');
            }
        },

        async unpostFinance(id, scope = 'all') {
            const scopeLabel = scope === 'sales' ? 'فاتورة البيع فقط' : (scope === 'purchase' ? 'فاتورة الشراء فقط' : 'كل الفواتير');
            const confirmed = await this.confirmDialog({
                title: 'إلغاء الترحيل',
                text: `هل أنت متأكد من إلغاء ترحيل ${scopeLabel} لهذه المعاملة؟ ستعود الفواتير المحددة إلى حالة المسودة.`,
                icon: 'warning',
                confirmText: 'نعم، إلغاء الترحيل',
                cancelText: 'تراجع'
            });
            if (!confirmed) return;

            try {
                this.closeActionMenus();
                const formData = new FormData();
                formData.append('id', id);
                formData.append('scope', scope);
                formData.append('csrf_token', CSRF_TOKEN);

                const res = await fetch('ajax_work_visa.php?action=unpost_finance', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.status === 'success') {
                    this.showNotice(result.message, 'success');
                    window.location.reload();
                } else {
                    this.showNotice(result.message || 'تعذر إلغاء الترحيل', 'error');
                }
            } catch (err) {
                this.showNotice('حدث خطأ أثناء إلغاء الترحيل المالي', 'error');
            }
        },

        async deleteFinance(id, scope = 'all') {
            const scopeLabel = scope === 'sales'
                ? 'فاتورة البيع فقط'
                : (scope === 'purchase' ? 'فاتورة الشراء فقط' : 'فاتورتي البيع والشراء');
            const confirmMessage = scope === 'all'
                ? `هل أنت متأكد من حذف ${scopeLabel} من هذه المعاملة؟\n\nلن يتم حذف أصل معاملة فيز العمل، وسيتم حذف الربط المالي فقط.`
                : `هل أنت متأكد من حذف ${scopeLabel} من هذه المعاملة؟`;

            const confirmed = await this.confirmDialog({
                title: 'تأكيد الحذف المالي',
                text: confirmMessage,
                icon: 'warning',
                confirmText: 'نعم، احذف',
                cancelText: 'إلغاء'
            });
            if (!confirmed) return;

            try {
                this.closeActionMenus();
                const formData = new FormData();
                formData.append('id', id);
                formData.append('scope', scope);
                formData.append('csrf_token', CSRF_TOKEN);

                const res = await fetch('ajax_work_visa.php?action=delete_finance', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.status === 'success') {
                    this.showNotice(result.message, 'success');
                    window.location.reload();
                } else {
                    this.showNotice(result.message || 'تعذر حذف الفاتورة المطلوبة', 'error');
                }
            } catch (err) {
                this.showNotice('حدث خطأ أثناء حذف الفاتورة المطلوبة', 'error');
            }
        },

        // 5. فتح مودال التعديل
        async openEditModal(id) {
            try {
                const res = await fetch(`ajax_work_visa.php?action=get_work_visa_details&id=${id}`);
                const json = await res.json();

                if (json.status === 'success') {
                    const data = json.data;
                    const fields = {
                        'edit_passport_id': data.id,
                        'edit_full_name': data.full_name,
                        'edit_full_name_en': data.full_name_en || '',
                        'edit_nationality': data.nationality || '',
                        'edit_gender': data.gender || '',
                        'edit_passport_number': data.passport_number,
                        'edit_agent_id': data.agent_id || '',
                        'edit_branch_id': data.branch_id || '',
                        'edit_customer_id_hidden': data.sales_invoice?.customer_id || '',
                        'edit_agent_id_hidden': data.sales_invoice?.agent_id || data.agent_id || '',
                        'edit_profession_id': data.profession_id || '',
                        'edit_status_id': data.status_id,
                        'edit_dob': data.date_of_birth || '',
                        'edit_passport_issue': data.passport_issue_date || '',
                        'edit_passport_expiry': data.passport_expiry_date || '',
                        'edit_batch_id': data.batch_id || '',
                        'edit_invoice_date': (data.sales_invoice?.invoice_date || data.purchase_invoice?.invoice_date || '').replace(' ', 'T').slice(0, 16),
                        'edit_delivery_type': data.sales_invoice?.delivery_type || <?php echo json_encode($settings['default_delivery_type'] ?? 'draft'); ?>,
                        'edit_sale_currency_id': data.sales_invoice?.currency_id || '1',
                        'edit_total_amount': data.sales_invoice?.total_amount || '0',
                        'edit_received_amount': data.sales_invoice?.amount_received || '0',
                        'edit_record_purchase': data.purchase_invoice ? '1' : '0',
                        'edit_main_currency_id': data.purchase_invoice?.currency_id || data.sales_invoice?.currency_id || '1',
                        'edit_cost_amount': data.purchase_invoice?.total_amount || '0',
                        'edit_description': data.sales_invoice?.description || data.description || ''
                    };

                    for (let fid in fields) {
                        const el = document.getElementById(fid);
                        if (el) el.value = fields[fid];
                    }

                    const serviceRevenue = document.getElementById('edit_service_revenue_account');
                    const serviceCost = document.getElementById('edit_service_cost_account');
                    const serviceProfit = document.getElementById('edit_service_profit_account');
                    if (serviceRevenue) serviceRevenue.value = data.service_accounts?.revenue_account_name || '';
                    if (serviceCost) serviceCost.value = data.service_accounts?.cost_account_name || '';
                    if (serviceProfit) serviceProfit.value = data.service_accounts?.profit_account_name || '';

                    const deliveryType = document.getElementById('edit_delivery_type');
                    if (deliveryType) {
                        deliveryType.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    setTimeout(() => {
                        const accountSelect = document.getElementById('edit_account_select');
                        const supplierSelect = document.getElementById('edit_supplier_id');

                        if (accountSelect && data.sales_invoice?.account_id) {
                            const optionText = data.sales_invoice.account_display || data.sales_invoice.counterparty_name || '-- اختر --';
                            if (![...accountSelect.options].some(opt => String(opt.value) === String(data.sales_invoice.account_id))) {
                                accountSelect.append(new Option(optionText, data.sales_invoice.account_id, true, true));
                            }
                            accountSelect.value = String(data.sales_invoice.account_id);
                            accountSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        }

                        if (supplierSelect && data.purchase_invoice?.supplier_id) {
                            supplierSelect.value = String(data.purchase_invoice.supplier_id);
                            supplierSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }, 350);

                    ['edit_sale_currency_id', 'edit_main_currency_id', 'edit_total_amount', 'edit_cost_amount', 'edit_received_amount', 'edit_record_purchase'].forEach((fid) => {
                        const el = document.getElementById(fid);
                        if (el) {
                            el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
                        }
                    });

                    this.syncAutoDescription('edit', true);

                    // تحميل المتطلبات إذا كانت المهنة موجودة
                    if (data.profession_id) {
                        this.loadProfessionRequirements(data.profession_id, 'edit');
                    } else {
                        document.getElementById('edit_requirements_container').style.display = 'none';
                        document.getElementById('edit_requirements_list').innerHTML = '';
                    }

                    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
                }
            } catch (err) {
                await this.alertDialog({
                    title: 'تعذر فتح التعديل',
                    text: 'فشل جلب بيانات التعديل',
                    icon: 'error'
                });
            }
        },

        toggleAllMembers(checked) {
            document.querySelectorAll('.member-check').forEach(el => el.checked = checked);
        },

        async showTransitionForm(passportId, stepId, stepName) {
            const formContainer = document.getElementById('transition_form_container');
            const transitionsList = document.getElementById('transitions_list');
            const fieldsContainer = document.getElementById('dynamic_fields_container');
            const targetNameEl = document.getElementById('target_step_name');
            const confirmBtn = document.getElementById('confirm_transition_btn');

            targetNameEl.innerText = `نقل إلى: ${stepName}`;
            fieldsContainer.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
            formContainer.classList.remove('d-none');
            transitionsList.classList.add('d-none');

            try {
                const res = await fetch(`ajax_work_visa.php?action=get_step_fields&step_id=${stepId}`);
                const json = await res.json();

                let checklistHtml = '';
                // إذا كانت الوجهة هي تسليم للفرع الرئيسي، نجلب الـ Checklist
                if (stepName.includes('تسليم للفرع الرئيسي') || stepName.includes('طلب تأكيد استلام وثائق')) {
                    const resDetails = await fetch(`ajax_work_visa.php?action=get_work_visa_details&id=${passportId}`);
                    const dataDetails = await resDetails.json();
                    if (dataDetails.status === 'success' && dataDetails.data.checklist) {
                        checklistHtml = `
                        <div class="col-12 mt-3">
                            <div class="card border-warning bg-warning-subtle rounded-3 p-3 shadow-sm">
                                <h6 class="fw-bold text-dark small mb-3 border-bottom pb-2"><i class="fas fa-tasks me-2"></i> تحديد الوثائق المسلمة (Checklist)</h6>
                                <div class="list-group list-group-flush small">
                                    ${dataDetails.data.checklist.map(item => `
                                        <div class="list-group-item bg-transparent border-0 py-2 px-0 d-flex align-items-center justify-content-between mb-1 rounded-2 ${item.relayer_verified == 1 ? 'bg-success-subtle px-2' : ''}">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="fw-bold ${item.relayer_verified == 1 ? 'text-success' : 'text-dark'}">${item.requirement_name}</span>
                                                ${item.relayer_verified == 1 ? '<i class="fas fa-check-double text-success extra-small" title="مؤكد استلامه"></i>' : ''}
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input transition-checklist-item" type="checkbox"
                                                       data-req-id="${item.requirement_id}"
                                                       ${item.is_completed == 1 || item.relayer_verified == 1 ? 'checked' : ''}
                                                       ${item.relayer_verified == 1 ? 'disabled' : ''}>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                                <div class="mt-2 extra-small text-muted italic">
                                    * الوثائق المؤكد استلامها مسبقاً لا يمكن تغيير حالتها.
                                </div>
                            </div>
                        </div>
                    `;
                    }
                }

                let fieldsHtml = '';
                if (json.status === 'success' && json.fields && json.fields.length > 0) {
                    const today = new Date().toISOString().split('T')[0];
                    fieldsHtml = json.fields.map(f => `
                    <div class="${f.type === 'textarea' ? 'col-12' : 'col-md-6'}">
                        <label class="form-label extra-small fw-bold">${f.label}</label>
                        ${f.type === 'textarea' ?
                            `<textarea name="${f.name}" class="dynamic-field form-control form-control-sm rounded-3" rows="2" ${f.required ? 'required' : ''}></textarea>` :
                            `<input type="${f.type}" name="${f.name}" class="dynamic-field form-control form-control-sm rounded-3" ${f.required ? 'required' : ''} ${f.type === 'date' ? `value="${today}"` : ''}>`
                        }
                    </div>
                `).join('');
                } else if (!checklistHtml) {
                    fieldsHtml = '<div class="col-12 extra-small text-muted">لا توجد حقول إضافية مطلوبة لهذه المرحلة</div>';
                }

                fieldsContainer.innerHTML = fieldsHtml + checklistHtml;

                confirmBtn.onclick = () => this.processTransition(passportId, stepId);

            } catch (err) {
                fieldsContainer.innerHTML = '<div class="alert alert-danger extra-small">خطأ في جلب الحقول</div>';
            }
        },

        cancelTransition() {
            document.getElementById('transition_form_container').classList.add('d-none');
            document.getElementById('transitions_list').classList.remove('d-none');
        },

        // 6. تنفيذ الانتقال
        async processTransition(passportId, toStepId) {
            const confirmed = await this.confirmDialog({
                title: 'تأكيد نقل المرحلة',
                text: 'هل أنت متأكد من نقل المعاملة لهذه المرحلة؟',
                icon: 'question',
                confirmText: 'نعم، تنفيذ النقل',
                cancelText: 'تراجع'
            });
            if (!confirmed) return;

            const notes = document.getElementById('transition_notes') ? document.getElementById('transition_notes').value : '';
            const members = Array.from(document.querySelectorAll('.member-check:checked')).map(el => el.value);

            // إذا كان هناك قائمة تحقق في نموذج الانتقال، نقوم بحفظها أولاً
            const transChecklist = document.querySelectorAll('.transition-checklist-item');
            if (transChecklist.length > 0) {
                const checklistData = new FormData();
                checklistData.append('passport_id', passportId);
                transChecklist.forEach((cb, index) => {
                    checklistData.append(`checklist[${index}][requirement_id]`, cb.dataset.reqId);
                    checklistData.append(`checklist[${index}][is_completed]`, cb.checked ? 1 : 0);
                });
                await fetch('ajax_work_visa.php?action=update_checklist', {
                    method: 'POST',
                    body: checklistData
                });
            }

            const formData = new FormData();
            // إذا كان هناك أفراد مختارون، نرسلهم كمصفوفة، وإلا نرسل المعرف الأساسي فقط
            const ids = members.length > 0 ? members : [passportId];

            ids.forEach(id => formData.append('passport_id[]', id));
            formData.append('to_step_id', toStepId);
            formData.append('notes', notes);

            // إضافة الحقول الديناميكية
            document.querySelectorAll('.dynamic-field').forEach(el => {
                formData.append(el.name, el.value);
            });

            try {
                const res = await fetch('ajax_work_visa.php?action=process_transition', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (json.status === 'success') {
                    await this.alertDialog({
                        title: 'تم تنفيذ النقل',
                        text: json.message || 'تم نقل المعاملة بنجاح',
                        icon: 'success'
                    });
                    location.reload();
                } else {
                    await this.alertDialog({
                        title: 'تعذر النقل',
                        text: json.message ? `خطأ: ${json.message}` : 'تعذر نقل المعاملة',
                        icon: 'error'
                    });
                }
            } catch (err) {
                await this.alertDialog({
                    title: 'خطأ في الاتصال',
                    text: 'فشل الاتصال بالخادم',
                    icon: 'error'
                });
            }
        },

        // 7. حفظ الـ Checklist
        async saveChecklist() {
            const btn = document.getElementById('saveChecklistBtn');
            const passportId = btn.dataset.passportId;
            const items = [];

            document.querySelectorAll('.verify-item').forEach(cb => {
                items.push({
                    requirement_id: cb.dataset.reqId,
                    is_completed: cb.checked ? 1 : 0
                });
            });

            try {
                const formData = new FormData();
                formData.append('passport_id', passportId);
                // إرسال كـ JSON أو عبر مصفوفة FormData
                items.forEach((item, index) => {
                    formData.append(`checklist[${index}][requirement_id]`, item.requirement_id);
                    formData.append(`checklist[${index}][is_completed]`, item.is_completed);
                });

                const res = await fetch('ajax_work_visa.php?action=update_checklist', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (json.status === 'success') {
                    await this.alertDialog({
                        title: 'تم الحفظ',
                        text: 'تم حفظ التغييرات بنجاح',
                        icon: 'success'
                    });
                    bootstrap.Modal.getInstance(document.getElementById('detailsModal')).hide();
                    location.reload();
                } else {
                    await this.alertDialog({
                        title: 'تعذر الحفظ',
                        text: json.message ? `خطأ: ${json.message}` : 'تعذر حفظ التغييرات',
                        icon: 'error'
                    });
                }
            } catch (err) {
                await this.alertDialog({
                    title: 'خطأ في الحفظ',
                    text: 'فشل الحفظ',
                    icon: 'error'
                });
            }
        },

        // 8. OCR محلي باستخدام Tesseract.js للتركيز الحصري على بصمة الجواز (MRZ)
        async processPassportOCR(prefix) {
            const fileInput = document.getElementById(prefix === 'add' ? 'add_passport_image_input' : 'edit_passport_image_input');
            const isDark = document.body.classList.contains('theme-dark');
            if (!fileInput.files || !fileInput.files[0]) {
                Swal.fire({
                    title: 'تنبيه',
                    text: 'يرجى اختيار صورة الجواز أولاً',
                    icon: 'warning',
                    background: isDark ? '#0b1220' : '#ffffff',
                    color: isDark ? '#e2e8f0' : '#0f172a'
                });
                return;
            }

            const btn = document.getElementById(prefix === 'add' ? 'scan_passport_btn' : 'edit_scan_passport_btn');
            const oldText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التحليل...';
            btn.disabled = true;

            Swal.fire({
                title: 'جاري مسح بصمة الجواز (MRZ)...',
                html: `<div id="ocr-status">جاري تهيئة المحرك...</div>
                   <div class="progress mt-2"><div id="ocr-progress" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div></div>`,
                allowOutsideClick: false,
                showConfirmButton: false,
                background: isDark ? '#0b1220' : '#ffffff',
                color: isDark ? '#e2e8f0' : '#0f172a',
                didOpen: () => Swal.showLoading()
            });

            try {
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

                const angles = [0, -2, 2, -4, 4, -6, 6];
                let parsedData = null;
                let finalMRZImage = null;

                for (let angle of angles) {
                    document.getElementById('ocr-status').innerText = `جاري المحاولة بزاوية ${angle}°...`;
                    const processedImg = await this.preprocessPassportImage(fileInput.files[0], angle);
                    const {
                        data: {
                            text
                        }
                    } = await worker.recognize(processedImg);

                    parsedData = this.parseClientMRZ(text);
                    if (parsedData) {
                        finalMRZImage = processedImg;
                        break;
                    }
                }

                await worker.terminate();

                if (parsedData) {
                    this.fillOCRFields(prefix, parsedData);

                    // عرض صورة بصمة الجواز في المعاينة
                    const previewContainer = document.getElementById(prefix + '_passport_preview');
                    if (previewContainer && finalMRZImage) {
                        const previewImg = previewContainer.querySelector('img');
                        if (previewImg) previewImg.src = finalMRZImage;
                        previewContainer.classList.remove('d-none');
                    }

                    Swal.fire({
                        title: 'تمت القراءة',
                        text: 'تم استخراج البيانات بنجاح من بصمة الجواز (MRZ)',
                        icon: 'success',
                        background: isDark ? '#0b1220' : '#ffffff',
                        color: isDark ? '#e2e8f0' : '#0f172a'
                    });
                } else {
                    Swal.fire({
                        title: 'تنبيه',
                        text: 'لم يتم العثور على بصمة MRZ صالحة. تأكد من جودة الصورة.',
                        icon: 'warning',
                        background: isDark ? '#0b1220' : '#ffffff',
                        color: isDark ? '#e2e8f0' : '#0f172a'
                    });
                }
            } catch (err) {
                console.error('OCR Error:', err);
                Swal.fire({
                    title: 'خطأ',
                    text: 'حدث خطأ غير متوقع أثناء المعالجة.',
                    icon: 'error',
                    background: isDark ? '#0b1220' : '#ffffff',
                    color: isDark ? '#e2e8f0' : '#0f172a'
                });
            } finally {
                btn.innerHTML = oldText;
                btn.disabled = false;
            }
        },

        // 8.1 معالجة الصورة (قص منطقة البصمة فقط)
        async preprocessPassportImage(file, angle = 0) {
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

                        // تحسين التباين
                        let imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        let data = imageData.data;
                        for (let i = 0; i < data.length; i += 4) {
                            let avg = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
                            avg = avg < 125 ? 0 : 255;
                            data[i] = data[i + 1] = data[i + 2] = avg;
                        }
                        ctx.putImageData(imageData, 0, 0);

                        // قص منطقة MRZ (آخر 35%)
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
        },

        // 8.2 محرك تحليل MRZ (TD3 Standard)
        parseClientMRZ(text) {
            if (!text) return null;

            // تنظيف النص: حروف كبيرة، أرقام، وعلامة < فقط
            let rawText = text.toUpperCase().replace(/K/g, '<');
            const lines = rawText.split('\n')
                .map(l => l.replace(/[^A-Z0-9<]/g, '').trim())
                .filter(l => l.length >= 40);

            // البحث عن سطرين MRZ (السطر الأول يبدأ بـ P<)
            let l1 = lines.find(l => l.startsWith('P<'));
            let l2 = lines.find(l => !l.startsWith('P<') && l.length >= 40);

            if (!l1 || !l2) return null;

            // التأكد من أخذ 44 حرفاً فقط للجوازات القياسية
            l1 = l1.substring(0, 44);
            l2 = l2.substring(0, 44);

            try {
                const result = {
                    passport_number: "",
                    full_name_en: "",
                    nationality_code: l2.substring(10, 13).replace(/</g, ''),
                    country_code: l1.substring(2, 5).replace(/</g, ''),
                    gender: l2.substring(20, 21),
                    date_of_birth: "",
                    passport_expiry_date: "",
                    mrz_line_1: l1,
                    mrz_line_2: l2,
                    ocr_raw_text: text
                };

                // 1. معالجة رقم الجواز (أول 9 خانات من السطر الثاني)
                let pNum = l2.substring(0, 9).replace(/</g, '').trim();
                // الجواز اليمني لا يحتوي على حروف
                if (result.nationality_code === 'YEM' || result.country_code === 'YEM') {
                    pNum = pNum.replace(/[^0-9]/g, '');
                } else {
                    pNum = pNum.replace(/[^A-Z0-9]/g, '');
                }
                result.passport_number = pNum;

                // 2. معالجة الاسم (SURNAME<<GIVEN<NAMES)
                // السطر الأول يبدأ بـ P<CCC ثم الاسم من الخانة 6 (فهرس 5)
                let namePart = l1.substring(5);
                const nameSplit = namePart.split('<<');
                if (nameSplit.length >= 2) {
                    let surname = nameSplit[0].replace(/</g, ' ').trim();
                    // نأخذ كل ما بعد << وننظفه من علامات الحشو
                    let givenNamesRaw = nameSplit.slice(1).join(' ');
                    let givenNames = givenNamesRaw.replace(/<+/g, ' ').trim();
                    result.full_name_en = (givenNames + ' ' + surname).replace(/\s+/g, ' ').trim();
                } else {
                    // fallback إذا لم يجد <<
                    result.full_name_en = namePart.replace(/<+/g, ' ').trim();
                }

                // 3. معالجة التواريخ (YYMMDD)
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

                result.date_of_birth = convertDate(l2.substring(13, 19), true);
                result.passport_expiry_date = convertDate(l2.substring(21, 27), false);

                return result;
            } catch (e) {
                return null;
            }
        },

        // 8.3 تعبئة الحقول في الفورم
        fillOCRFields(prefix, data) {
            const safeSet = (idSuffix, val) => {
                const el = document.getElementById(prefix + idSuffix);
                if (el && (!el.value || el.value === '') && val) {
                    el.value = val;
                    el.classList.add('animate__animated', 'animate__flash', 'bg-warning-subtle');
                    setTimeout(() => el.classList.remove('bg-warning-subtle'), 2000);
                }
            };

            safeSet('_passport_number', data.passport_number);
            safeSet('_full_name_en', data.full_name_en);

            if (data.full_name_en) {
                const arName = this.transliterateEnToAr(data.full_name_en);
                safeSet('_full_name', arName);
            }

            safeSet('_dob', data.date_of_birth);
            safeSet('_passport_expiry', data.passport_expiry_date);

            // تعبئة الجنس والجنسية
            this.fillGenderAndNationality(prefix, data.mrz_line_2);

            // تعبئة الحقول المخفية والمعلومات الخام
            const hiddenFields = {
                '_mrz_line_1': data.mrz_line_1,
                '_mrz_line_2': data.mrz_line_2,
                '_ocr_raw_text': data.ocr_raw_text,
                '_passport_country_code': data.country_code
            };

            for (let [suffix, val] of Object.entries(hiddenFields)) {
                const el = document.getElementById(prefix + suffix);
                if (el) el.value = val || '';
            }
        },

        // 8.4 تعبئة الجنس والجنسية
        fillGenderAndNationality(prefix, mrz2) {
            if (!mrz2 || mrz2.length < 30) return;

            const cleanMRZ2 = mrz2.toUpperCase().trim();
            const genderId = prefix + '_gender';
            const nationalityId = prefix + '_nationality';

            // 1. معالجة الجنس (الحرف 21 - الفهرس 20)
            const genderChar = cleanMRZ2.substring(20, 21);
            const genderSelect = document.getElementById(genderId);
            if (genderSelect && (!genderSelect.value || genderSelect.value === '')) {
                if (genderChar === 'M') genderSelect.value = 'Male';
                else if (genderChar === 'F') genderSelect.value = 'Female';

                if (genderSelect.value) {
                    genderSelect.classList.add('animate__animated', 'animate__flash', 'bg-warning-subtle');
                    setTimeout(() => genderSelect.classList.remove('bg-warning-subtle'), 2000);
                }
            }

            // 2. معالجة الجنسية (الأحرف 11 إلى 13 - الفهرس 10 إلى 13)
            const nationalityCode = cleanMRZ2.substring(10, 13).replace(/</g, '');
            const nationalitySelect = document.getElementById(nationalityId);
            if (nationalitySelect && (!nationalitySelect.value || nationalitySelect.value === '')) {
                let found = false;
                // البحث داخل الخيارات عن data-code مطابق لرمز الدولة (مثلاً YEM)
                for (let i = 0; i < nationalitySelect.options.length; i++) {
                    const opt = nationalitySelect.options[i];
                    if (opt.dataset.code && opt.dataset.code.toUpperCase() === nationalityCode) {
                        nationalitySelect.selectedIndex = i;
                        found = true;
                        break;
                    }
                }

                if (found) {
                    nationalitySelect.classList.add('animate__animated', 'animate__flash', 'bg-warning-subtle');
                    setTimeout(() => nationalitySelect.classList.remove('bg-warning-subtle'), 2000);
                }
            }
        },

        // 8.5 محرك التعريب الصوتي
        transliterateEnToAr(text) {
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
                'ALRAIMI': 'الريمي',
                'SAYED': 'سيد',
                'SHARIF': 'شريف',
                'TAHER': 'طاهر',
                'YAHYA': 'يحيى',
                'ZAKARIA': 'زكريا',
                'MARYAM': 'مريم',
                'FATIMA': 'فاطمة',
                'AISHA': 'عائشة',
                'KHADIJA': 'خديجة',
                'ZAINAB': 'زينب'
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
        },

        // معاينة الصورة
        previewImage(input, previewId) {
            const container = document.getElementById(previewId);
            if (!container) return;
            const img = container.querySelector('img');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    container.classList.remove('d-none');
                }
                reader.readAsDataURL(input.files[0]);
            }
        },

        bindStaticEvents() {
            // أي أحداث ثابتة أخرى
        }
    };

    // تشغيل النظام
    WorkVisa.init();
</script>

<style>
    /* تحسينات التصميم والوضع الليلي */
    .transition-all {
        transition: all 0.3s ease;
    }

    .ring-2 {
        box-shadow: 0 0 0 2px var(--primary-color);
    }

    .custom-scrollbar::-webkit-scrollbar {
        height: 4px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.1);
        border-radius: 4px;
    }

    .mini-card {
        min-width: 200px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid rgba(0, 0, 0, 0.05) !important;
    }

    .mini-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08) !important;
    }

    .stat-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #64748b;
        margin-bottom: 2px;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        line-height: 1;
    }

    .sub-stat {
        font-size: 0.65rem;
        color: #94a3b8;
    }

    .sub-stat-value {
        font-weight: 700;
        color: #1e293b;
    }

    body.theme-dark .stat-label {
        color: #94a3b8;
    }

    body.theme-dark .sub-stat-value {
        color: #e2e8f0;
    }

    body.theme-dark .mini-card {
        border-color: #1e2d45 !important;
    }

    body.theme-dark .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
    }

    body.theme-dark .card {
        background-color: #111827 !important;
        border-color: #1e2d45 !important;
    }

    body.theme-dark .table {
        color: #e2e8f0 !important;
    }

    body.theme-dark .table thead {
        background-color: #0f1e35 !important;
    }

    body.theme-dark .table-hover tbody tr:hover {
        background-color: #1e2d45 !important;
    }

    body.theme-dark .bg-light {
        background-color: #0f1e35 !important;
    }

    body.theme-dark .text-muted {
        color: #94a3b8 !important;
    }

    body.theme-dark .form-control,
    body.theme-dark .form-select {
        background-color: #0f1e35 !important;
        border-color: #1e2d45 !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark .input-group-text {
        background-color: #1e2d45 !important;
        border-color: #1e2d45 !important;
        color: #94a3b8 !important;
    }
</style>

<?php require_once 'footer.php'; ?>
