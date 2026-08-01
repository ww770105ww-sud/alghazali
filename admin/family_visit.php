<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$type = 'family_visit';
$page_title = 'إدارة الزيارات العائلية';
$permission_prefix = 'family_visit';

if (!has_permission($permission_prefix . '_view')) {
    header('Location: index.php?error=no_permission');
    exit();
}

// جلب بيانات المستخدم الحالي
$stmt_user = $pdo->prepare("
    SELECT u.*, r.name as role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.id = ?
");
$stmt_user->execute([$_SESSION['admin_id']]);
$currentUser = $stmt_user->fetch();

// نظام العزل والفلترة
$where_clauses = ["1=1"];
$params = [];

// جلب فلاتر البحث من الرابط (للمدراء)
$agent_filter = $_GET['agent_filter'] ?? '';
$branch_filter = $_GET['branch_filter'] ?? '';

// التحقق من هوية المستخدم لفرض العزل
$is_super_user = in_array(strtolower($currentUser['role_name'] ?? ''), ['admin', 'developer', 'accountant', 'relayer']) || has_permission('view_all_agents_branches');
$can_view_all = has_permission('view_all_passports') || has_permission('view_all_agents_branches');
$is_agent = (($currentUser['role_name'] ?? '') === 'agent');
$is_branch = (($currentUser['role_name'] ?? '') === 'branch');

if (!$is_super_user && !$can_view_all) {
    if (!empty($currentUser['agent_id'])) {
        $agent_filter = $currentUser['agent_id'];
        $branch_filter = '';
    } elseif (!empty($currentUser['branch_id'])) {
        $branch_filter = $currentUser['branch_id'];
        $agent_filter = $_GET['agent_filter'] ?? '';
    }
}

if (!empty($agent_filter)) {
    $where_clauses[] = "r.agent_id = ?";
    $params[] = $agent_filter;
}
if (!empty($branch_filter)) {
    $where_clauses[] = "r.branch_id = ?";
    $params[] = $branch_filter;
}

if (!empty($_GET['status_filter'])) {
    $where_clauses[] = "r.status_id = ?";
    $params[] = intval($_GET['status_filter']);
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// الاستعلام الرئيسي للطلبات
$requests_stmt = $pdo->prepare("
    SELECT r.*, s.status_name, s.status_color, 
           ag.agent_name, br.branch_name,
           (SELECT COUNT(*) FROM family_visit_individuals WHERE request_id = r.id) as individuals_count,
           sale_inv.net_amount as total_price,
           sale_inv.cost_amount as total_cost,
           sale_inv.amount_received as total_paid,
           sale_inv.id as sales_invoice_id,
           sale_inv.payment_status as sales_payment_status,
           sale_inv.invoice_status as sales_invoice_status,
           purchase_inv.id as purchase_invoice_id,
           purchase_inv.net_amount as purchase_total_amount,
           purchase_inv.amount_received as purchase_paid_amount,
           purchase_inv.payment_status as purchase_payment_status,
           purchase_inv.invoice_status as purchase_invoice_status,
           sup.supplier_name as purchase_supplier_name,
           sale_acc.account_code as sales_account_code,
           sale_acc.account_name_ar as sales_account_name,
           c.currency_name,
           c.currency_symbol
    FROM family_visit_requests r
    LEFT JOIN statuses s ON r.status_id = s.id
    LEFT JOIN agents ag ON r.agent_id = ag.id
    LEFT JOIN branches br ON r.branch_id = br.id
    LEFT JOIN invoices sale_inv ON sale_inv.id = COALESCE(
        r.sales_invoice_id,
        r.invoice_id,
        (
            SELECT i.id
            FROM invoices i
            WHERE i.source_type = 'FamilyVisit' AND i.source_id = r.id AND i.invoice_category = 'sales' AND i.invoice_status <> 'cancelled'
            ORDER BY i.id DESC
            LIMIT 1
        )
    )
    LEFT JOIN invoices purchase_inv ON purchase_inv.id = COALESCE(
        r.purchase_invoice_id,
        (
            SELECT i.id
            FROM invoices i
            WHERE i.source_type = 'FamilyVisit' AND i.source_id = r.id AND i.invoice_category = 'purchase' AND i.invoice_status <> 'cancelled'
            ORDER BY i.id DESC
            LIMIT 1
        )
    )
    LEFT JOIN suppliers sup ON purchase_inv.supplier_id = sup.id
    LEFT JOIN unified_accounts sale_acc ON sale_inv.account_id = sale_acc.id
    LEFT JOIN currencies c ON COALESCE(sale_inv.currency_id, purchase_inv.currency_id) = c.id
    $where_sql
    ORDER BY r.created_at DESC
");
$requests_stmt->execute($params);
$requests = $requests_stmt->fetchAll();

// تجميع حالة الأفراد لكل طلب (حالة لكل فرد)
$individualStatusByRequest = [];
try {
    $individualsTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'family_visit_individuals'")->fetchColumn();
    $individualStatusColumnExists = false;
    if ($individualsTableExists) {
        $individualStatusColumnExists = (bool)$pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'status_id'")->fetchColumn();
    }

    if ($individualsTableExists && $individualStatusColumnExists && !empty($requests)) {
        $requestIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $requests)));
        if (!empty($requestIds)) {
            $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
            $stmtIndStatuses = $pdo->prepare("
                SELECT i.request_id,
                       COALESCE(s.status_name, '---') AS status_name,
                       COALESCE(s.status_color, '#6c757d') AS status_color,
                       COUNT(*) AS cnt
                FROM family_visit_individuals i
                LEFT JOIN statuses s ON i.status_id = s.id
                WHERE i.request_id IN ($placeholders)
                GROUP BY i.request_id, i.status_id
                ORDER BY cnt DESC
            ");
            $stmtIndStatuses->execute($requestIds);
            foreach ($stmtIndStatuses->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rid = (int)($row['request_id'] ?? 0);
                if (!$rid) continue;
                $individualStatusByRequest[$rid][] = $row;
            }
        }
    }
} catch (Throwable $e) {
    $individualStatusByRequest = [];
}

// حذف طلب
if (isset($_GET['delete_id'])) {
    if (has_permission($permission_prefix . '_delete')) {
        $deleteId = (int)$_GET['delete_id'];
        $stmtReq = $pdo->prepare("SELECT id, sales_invoice_id, purchase_invoice_id, invoice_id FROM family_visit_requests WHERE id = ? LIMIT 1");
        $stmtReq->execute([$deleteId]);
        $reqRow = $stmtReq->fetch(PDO::FETCH_ASSOC);

        $salesInvoiceId = !empty($reqRow['sales_invoice_id']) ? (int)$reqRow['sales_invoice_id'] : (!empty($reqRow['invoice_id']) ? (int)$reqRow['invoice_id'] : null);
        $purchaseInvoiceId = !empty($reqRow['purchase_invoice_id']) ? (int)$reqRow['purchase_invoice_id'] : null;

        if (!$salesInvoiceId) {
            $stmt = $pdo->prepare("SELECT id FROM invoices WHERE source_type = 'FamilyVisit' AND source_id = ? AND invoice_category = 'sales' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$deleteId]);
            $salesInvoiceId = (int)($stmt->fetchColumn() ?: 0) ?: null;
        }
        if (!$purchaseInvoiceId) {
            $stmt = $pdo->prepare("SELECT id FROM invoices WHERE source_type = 'FamilyVisit' AND source_id = ? AND invoice_category = 'purchase' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$deleteId]);
            $purchaseInvoiceId = (int)($stmt->fetchColumn() ?: 0) ?: null;
        }

        $postedFound = false;
        foreach (array_filter([$salesInvoiceId, $purchaseInvoiceId]) as $invId) {
            $stmt = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$invId]);
            if ($stmt->fetchColumn() === 'posted') {
                $postedFound = true;
                break;
            }
        }

        if ($postedFound) {
            $_SESSION['error'] = 'لا يمكن حذف الطلب لأن عليه فاتورة/فواتير مُرحلة. قم بإلغاء الترحيل أولاً.';
            header('Location: family_visit.php');
            exit();
        }

        $pdo->prepare("DELETE FROM family_visit_individuals WHERE request_id = ?")->execute([$deleteId]);
        $pdo->prepare("DELETE FROM family_visit_requests WHERE id = ?")->execute([$deleteId]);
        $_SESSION['success'] = 'تم حذف الطلب بنجاح';
        header('Location: family_visit.php');
        exit();
    }
}

// جلب البيانات المساعدة
$statuses = $pdo->query("SELECT * FROM statuses")->fetchAll();
$relationships = $pdo->query("SELECT * FROM family_relationships WHERE status = 'active'")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents WHERE status = 'active'")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE status = 'active'")->fetchAll();
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();

// جلب العملات
$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1 ORDER BY currency_name")->fetchAll();
// جلب الموردين مع حساباتهم
$p_supp = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$p_supp->execute();
$p_id = $p_supp->fetchColumn();
$suppliers_with_codes = [];
if ($p_id) {
    $s_stmt = $pdo->prepare("SELECT coa.*, (SELECT id FROM suppliers WHERE account_id = coa.id LIMIT 1) as supplier_id FROM unified_accounts coa WHERE coa.parent_id = ? AND coa.account_status = 'active' ORDER BY coa.account_code ASC");
    $s_stmt->execute([$p_id]);
    while ($row = $s_stmt->fetch()) {
        $row['display_name'] = $row['account_code'] . ' - ' . $row['account_name_ar'];
        $suppliers_with_codes[] = $row;
    }
}

// الكيانات مع حساباتها الموحدة (نفس bus_flight_bookings.php)
$customers_entities = $pdo->query("
    SELECT c.id as id, c.account_id as account_id, c.full_name as name, ua.account_code
    FROM customers c
    JOIN unified_accounts ua ON c.account_id = ua.id
    WHERE c.status = 'active' AND c.deleted_at IS NULL
    ORDER BY c.full_name ASC
")->fetchAll();

$agents_entities = $pdo->query("
    SELECT a.id, a.agent_name as name, a.account_id as account_id, acc.account_code
    FROM agents a
    JOIN unified_accounts acc ON a.account_id = acc.id
    WHERE a.status = 'active' AND a.deleted_at IS NULL
    ORDER BY a.agent_name ASC
")->fetchAll();

$cashboxes_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '101%' AND account_code != '101' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$cash_accounts = $cashboxes_entities;

$banks_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '102%' AND account_code != '102' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$bank_accounts = $banks_entities;

$branches_accounts = $pdo->query("SELECT id, branch_name as account_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC")->fetchAll();

// جلب إحصائيات الحالات للزيارة العائلية
$stats_on_clauses = ["s.id = r.status_id"];
$stats_params = [];
if (!empty($agent_filter)) { $stats_on_clauses[] = "r.agent_id = ?"; $stats_params[] = $agent_filter; }
if (!empty($branch_filter)) { $stats_on_clauses[] = "r.branch_id = ?"; $stats_params[] = $branch_filter; }
$stats_on_sql = implode(" AND ", $stats_on_clauses);

$status_stats_stmt = $pdo->prepare("
    SELECT 
        s.id, s.status_name, s.status_color,
        COUNT(r.id) as total,
        COUNT(CASE WHEN DATE(r.created_at) = CURDATE() THEN 1 END) as today,
        COUNT(CASE WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week,
        COUNT(CASE WHEN MONTH(r.created_at) = MONTH(CURDATE()) AND YEAR(r.created_at) = YEAR(CURDATE()) THEN 1 END) as this_month,
        COUNT(CASE WHEN MONTH(r.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(r.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) THEN 1 END) as last_month
    FROM statuses s
    LEFT JOIN family_visit_requests r ON $stats_on_sql
    GROUP BY s.id, s.status_name, s.status_color
    ORDER BY s.id ASC
");
$status_stats_stmt->execute($stats_params);
$status_stats = $status_stats_stmt->fetchAll();

require_once 'header.php';
?>

<style>
    @media (max-width: 768px) {
        .page-header-actions {
            flex-direction: column !important;
            align-items: stretch !important;
            width: 100%;
            gap: 0.75rem !important;
        }
        .header-controls {
            flex-direction: column !important;
            width: 100%;
            gap: 0.75rem !important;
        }
        .header-controls form, .header-controls .input-group, .header-controls button {
            width: 100% !important;
        }
        .mini-card {
            min-width: 150px !important;
            padding: 12px !important;
        }
        .stat-value {
            font-size: 1.3rem !important;
        }
        .stat-label {
            font-size: 0.85rem !important;
        }
        .table-responsive {
            border: 0;
        }
        .table thead {
            display: none;
        }
        .table tbody tr {
            display: flex;
            flex-direction: column;
            margin-bottom: 1.25rem;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            padding: 1rem;
            border: 1px solid #f0f0f0;
        }
        .table tbody td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0 !important;
            border: 0;
            border-bottom: 1px dashed #eee;
            text-align: left;
            width: 100%;
        }
        .table tbody td:last-child {
            border-bottom: 0;
            padding-top: 12px;
            justify-content: center;
        }
        .table tbody td::before {
            content: attr(data-label);
            font-weight: 700;
            color: #495057;
            margin-right: 10px;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        /* Improve finance mini cards on mobile */
        .finance-mini-card {
            padding: 12px !important;
            border-radius: 12px !important;
        }
        /* Improve individual entry form on mobile */
        #addRequestModal .modal-body .row > div {
            width: 100% !important;
            flex: 0 0 100% !important;
            max-width: 100% !important;
        }
        #addRequestModal .modal-dialog {
            margin: 0.5rem !important;
        }
        /* Improve buttons on mobile */
        .btn-sm {
            padding: 0.5rem 1rem !important;
        }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 page-header-actions">
        <h3 class="fw-bold mb-0"><i class="fas fa-users me-2 text-info"></i> <?php echo $page_title; ?></h3>
        
        <div class="d-flex gap-2 align-items-center header-controls">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <?php if ($is_super_user || $can_view_all): ?>
                <select name="agent_filter" class="form-select form-select-sm rounded-pill shadow-sm border-0" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">كل الوكلاء</option>
                    <?php foreach($agents as $ag): ?>
                        <option value="<?php echo $ag['id']; ?>" <?php echo $agent_filter == $ag['id'] ? 'selected' : ''; ?>><?php echo $ag['agent_name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="branch_filter" class="form-select form-select-sm rounded-pill shadow-sm border-0" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">كل الفروع</option>
                    <?php foreach($branches as $br): ?>
                        <option value="<?php echo $br['id']; ?>" <?php echo $branch_filter == $br['id'] ? 'selected' : ''; ?>><?php echo $br['branch_name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </form>

            <div class="input-group" style="width: 250px;">
                <span class="input-group-text bg-white border-0 shadow-sm rounded-start-pill"><i class="fas fa-search text-muted"></i></span>
                <input type="text" id="tableSearch" class="form-control border-0 shadow-sm rounded-end-pill" placeholder="بحث سريع...">
            </div>
            
            <?php if (has_permission($permission_prefix . '_create')): ?>
            <button type="button" class="btn btn-info text-white rounded-pill px-4 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addRequestModal">
                <i class="fas fa-plus-circle me-2"></i> طلب جديد
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars((string)$_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>



    <!-- الإحصائيات (البطاقات العلوية) -->
    <div class="row g-2 mb-4 overflow-auto flex-nowrap pb-3 custom-scrollbar px-1">
        <!-- بطاقة الإجمالي العام -->
        <div class="col-auto">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-primary text-white h-100 mini-card position-relative overflow-hidden" style="min-width: 200px;">
                <div class="position-absolute end-0 top-0 opacity-10" style="font-size: 3.5rem; transform: translate(15%, -15%);"><i class="fas fa-globe"></i></div>
                <div class="stat-label text-white opacity-75">إجمالي الطلبات</div>
                <div class="stat-value mb-2"><?php echo count($requests); ?></div>
                <div class="sub-stat text-white opacity-75 mt-auto">اليوم: <span class="fw-bold"><?php echo array_sum(array_column($status_stats, 'today')); ?></span></div>
                <a href="family_visit.php" class="stretched-link"></a>
            </div>
        </div>

        <?php foreach($status_stats as $stat): 
            $isActive = isset($_GET['status_filter']) && $_GET['status_filter'] == $stat['id'];
        ?>
        <div class="col-auto">
            <div class="card border-0 shadow-sm rounded-4 p-3 h-100 mini-card transition-all <?php echo $isActive ? 'ring-2 ring-primary shadow-lg' : ''; ?>"
                 style="min-width: 180px; border-top: 4px solid <?php echo $stat['status_color']; ?> !important;">
                <div class="stat-label text-truncate"><?php echo $stat['status_name']; ?></div>
                <div class="stat-value mb-2" style="color: <?php echo $stat['status_color']; ?>;"><?php echo $stat['total']; ?></div>
                
                <div class="d-flex justify-content-between align-items-center mt-auto">
                    <div class="sub-stat">اليوم: <span class="sub-stat-value"><?php echo $stat['today']; ?></span></div>
                    <?php 
                    $diff = $stat['this_month'] - $stat['last_month'];
                    if ($diff != 0):
                        $color = $diff > 0 ? 'text-success' : 'text-danger';
                        $icon = $diff > 0 ? 'fa-caret-up' : 'fa-caret-down';
                    ?>
                    <div class="sub-stat <?php echo $color; ?>"><i class="fas <?php echo $icon; ?>"></i> <?php echo abs($diff); ?></div>
                    <?php endif; ?>
                </div>
                <a href="family_visit.php?status_filter=<?php echo $stat['id']; ?><?php echo !empty($agent_filter) ? '&agent_filter='.$agent_filter : ''; ?><?php echo !empty($branch_filter) ? '&branch_filter='.$branch_filter : ''; ?>" class="stretched-link"></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- جدول الطلبات -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">رقم المستند</th>
                            <th>صاحب الطلب</th>
                            <th>الأفراد</th>
                            <th>المورد والشراء</th>
                            <th>الحساب وإجمالي الفاتورة</th>
                            <th>حالة الدفع والترحيل</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($requests as $r): ?>
                        <tr>
                            <td data-label="رقم المستند" class="px-4 fw-bold text-primary fv-raise">
                                <div><?php echo h($r['document_no']); ?></div>
                                <div class="x-small text-muted mt-1"><?php echo date('Y-m-d', strtotime($r['created_at'])); ?></div>
                            </td>
                            <td data-label="صاحب الطلب" class="fv-raise">
                                <div class="fw-bold"><?php echo h($r['owner_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($r['owner_id_no']); ?></small>
                                <div class="x-small text-muted mt-1"><?php echo htmlspecialchars($r['agent_name'] ?: ($r['branch_name'] ?: 'بدون جهة')); ?></div>
                            </td>
                            <td data-label="الأفراد" class="fv-raise">
                                <span class="badge bg-info bg-opacity-10 text-info px-3"><?php echo $r['individuals_count']; ?> أفراد</span>
                                <?php
                                $indStatuses = $individualStatusByRequest[(int)$r['id']] ?? [];
                                if (!empty($indStatuses)):
                                ?>
                                    <div class="mt-2 d-flex flex-wrap gap-1">
                                        <?php foreach ($indStatuses as $st): ?>
                                            <span class="badge rounded-pill" style="background-color: <?php echo htmlspecialchars((string)($st['status_color'] ?? '#6c757d')); ?>20; color: <?php echo htmlspecialchars((string)($st['status_color'] ?? '#6c757d')); ?>; border: 1px solid <?php echo htmlspecialchars((string)($st['status_color'] ?? '#6c757d')); ?>;">
                                                <?php echo htmlspecialchars((string)($st['status_name'] ?? '---')); ?>
                                                <span class="ms-1"><?php echo (int)($st['cnt'] ?? 0); ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php $currencyMark = trim((string)($r['currency_symbol'] ?: ($r['currency_name'] ?: ''))); ?>
                            <td data-label="المورد والشراء" class="small fv-raise">
                                <div class="finance-mini-card">
                                    <div class="mini-label">المورد</div>
                                    <div class="mini-name clamp-2"><?php echo htmlspecialchars($r['purchase_invoice_id'] ? ($r['purchase_supplier_name'] ?: 'المورد غير محدد') : 'لا توجد فاتورة شراء'); ?></div>
                                    <div class="mini-label">الشراء</div>
                                    <div class="mini-amount" style="color:#dc2626;">
                                        <?php
                                        $purchaseAmount = $r['purchase_invoice_id']
                                            ? (float)($r['purchase_total_amount'] ?? 0)
                                            : (float)($r['total_cost'] ?? 0);
                                        echo number_format($purchaseAmount, 2);
                                        echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : '';
                                        ?>
                                    </div>
                                </div>
                            </td>
                            <td data-label="الحساب وإجمالي الفاتورة" class="small fv-raise">
                                <div class="finance-mini-card">
                                    <div class="mini-label">الحساب الآخر</div>
                                    <div class="mini-name clamp-2">
                                        <?php
                                        $salesAccountLabel = trim((string)($r['sales_account_code'] ?? ''));
                                        $salesAccountName = trim((string)($r['sales_account_name'] ?? ''));
                                        echo htmlspecialchars($salesAccountName ?: 'الحساب غير محدد');
                                        ?>
                                    </div>
                                    <div class="mini-label">إجمالي الفاتورة</div>
                                    <div class="mini-amount" style="color:#16a34a;">
                                        <?php
                                        $saleAmount = (float)($r['total_price'] ?? 0);
                                        echo number_format($saleAmount, 2);
                                        echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : '';
                                        ?>
                                    </div>
                                </div>
                            </td>
                            <td data-label="حالة الدفع والترحيل">
                                <?php
                                $pay_badges = [
                                    'unpaid' => '<span class="badge bg-danger-subtle text-danger rounded-pill">غير مدفوع</span>',
                                    'partial' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                    'partially_paid' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                    'paid' => '<span class="badge bg-success-subtle text-success rounded-pill">مدفوع بالكامل</span>',
                                    'fully_paid' => '<span class="badge bg-success-subtle text-success rounded-pill">مدفوع بالكامل</span>',
                                    'awaiting_approval' => '<span class="badge bg-info-subtle text-info rounded-pill">بانتظار الاعتماد</span>'
                                ];
                                $invoice_badges = [
                                    'draft' => '<span class="badge bg-secondary-subtle text-secondary rounded-pill">مسودة</span>',
                                    'posted' => '<span class="badge bg-primary-subtle text-primary rounded-pill">مرحل</span>',
                                    'cancelled' => '<span class="badge bg-danger-subtle text-danger rounded-pill">ملغي</span>'
                                ];

                                $saleNet = (float)($r['total_price'] ?? 0);
                                $salePaid = (float)($r['total_paid'] ?? 0);
                                $salesPayKey = ($saleNet > 0 && $salePaid >= $saleNet - 0.01)
                                    ? 'fully_paid'
                                    : ($salePaid > 0 ? 'partial' : 'unpaid');

                                $purchaseNet = (float)($r['purchase_total_amount'] ?? 0);
                                $purchasePaid = (float)($r['purchase_paid_amount'] ?? 0);
                                $purchasePayKey = ($purchaseNet > 0 && $purchasePaid >= $purchaseNet - 0.01)
                                    ? 'fully_paid'
                                    : ($purchasePaid > 0 ? 'partial' : 'unpaid');
                                ?>
                                <div class="payment-stack">
                                    <div class="payment-box small">
                                        <div class="payment-box-title text-success">البيع</div>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php echo $pay_badges[$salesPayKey] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                            <?php echo $invoice_badges[$r['sales_invoice_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                        </div>
                                    </div>
                                    <div class="payment-box small">
                                        <div class="payment-box-title text-primary">الشراء</div>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php echo !empty($r['purchase_invoice_id']) ? ($pay_badges[$purchasePayKey] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : '<span class="badge bg-light text-dark rounded-pill">لا توجد</span>'; ?>
                                            <?php echo !empty($r['purchase_invoice_id']) ? ($invoice_badges[$r['purchase_invoice_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : ''; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="الإجراءات" class="text-center">
                                <?php
                                $salesStatus = $r['sales_invoice_status'] ?? 'draft';
                                $purchaseStatus = $r['purchase_invoice_status'] ?? (!empty($r['purchase_invoice_id']) ? 'draft' : null);
                                $hasSalesInvoice = !empty($r['sales_invoice_id']);
                                $hasPurchaseInvoice = !empty($r['purchase_invoice_id']);
                                $hasPostedInvoice = ($salesStatus === 'posted') || ($purchaseStatus === 'posted');
                                $hasDraftInvoice = ($salesStatus === 'draft') || ($purchaseStatus === 'draft');
                                $canModifyRow = in_array((string)$salesStatus, ['draft', 'cancelled'], true)
                                    && (empty($r['purchase_invoice_id']) || in_array((string)$purchaseStatus, ['draft', 'cancelled'], true));
                                $canFinancialPost = $is_super_user || has_permission('family_visit_financial_post') || has_permission('work_visa_financial_post');
                                ?>
                                <div class="financial-actions-wrap">
                                    <button class="btn btn-sm btn-outline-info view-request" data-id="<?php echo $r['id']; ?>" title="عرض"><i class="fas fa-eye"></i></button>
                                    <?php if ($canModifyRow && !$hasPostedInvoice): ?>
                                        <div class="financial-action-group">
                                            <button class="financial-action-btn" type="button" data-action-menu-toggle="manage-<?php echo $r['id']; ?>" title="إدارة الطلب" style="background: rgba(59, 130, 246, 0.12); color: #1d4ed8;">
                                                <i class="fas fa-gear"></i>
                                                <span>إدارة</span>
                                                <i class="fas fa-chevron-down small"></i>
                                            </button>
                                            <div class="financial-action-menu" id="manage-<?php echo $r['id']; ?>">
                                                <div class="financial-action-menu-title">إدارة الطلب</div>
                                                <button class="financial-action-menu-item manage-option edit-request" type="button" data-id="<?php echo $r['id']; ?>">
                                                    <i class="fas fa-pen-to-square"></i>
                                                    <span>تعديل</span>
                                                </button>
                                                <div class="financial-action-menu-title mt-2">حذف الفواتير</div>
                                                <button class="financial-action-menu-item delete-option <?php echo ($hasSalesInvoice && $salesStatus !== 'posted') ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if ($hasSalesInvoice && $salesStatus !== 'posted'): ?>
                                                            onclick="FamilyVisit.cancelInvoices(<?php echo $r['id']; ?>, 'sales')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-file-circle-xmark"></i>
                                                    <span>حذف فاتورة البيع</span>
                                                </button>
                                                <button class="financial-action-menu-item delete-option <?php echo ($hasPurchaseInvoice && $purchaseStatus !== 'posted') ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if ($hasPurchaseInvoice && $purchaseStatus !== 'posted'): ?>
                                                            onclick="FamilyVisit.cancelInvoices(<?php echo $r['id']; ?>, 'purchase')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-file-circle-xmark"></i>
                                                    <span>حذف فاتورة الشراء</span>
                                                </button>
                                                <button class="financial-action-menu-item delete-option <?php echo (($hasSalesInvoice && $salesStatus !== 'posted') || ($hasPurchaseInvoice && $purchaseStatus !== 'posted')) ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if (($hasSalesInvoice && $salesStatus !== 'posted') || ($hasPurchaseInvoice && $purchaseStatus !== 'posted')): ?>
                                                            onclick="FamilyVisit.cancelInvoices(<?php echo $r['id']; ?>, 'all')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-layer-group"></i>
                                                    <span>حذف الكل</span>
                                                </button>
                                                <a href="?delete_id=<?php echo $r['id']; ?>" class="financial-action-menu-item delete-option delete-family-request">
                                                    <i class="fas fa-trash"></i>
                                                    <span>حذف الطلب</span>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($canFinancialPost && $hasDraftInvoice && ($hasSalesInvoice || $hasPurchaseInvoice)): ?>
                                        <div class="financial-action-group">
                                            <button class="financial-action-btn fin-post" type="button" data-action-menu-toggle="post-<?php echo $r['id']; ?>" title="خيارات الترحيل">
                                                <i class="fas fa-file-export"></i>
                                                <span>الترحيل</span>
                                                <i class="fas fa-chevron-down small"></i>
                                            </button>
                                            <div class="financial-action-menu" id="post-<?php echo $r['id']; ?>">
                                                <div class="financial-action-menu-title">خيارات الترحيل</div>
                                                <button class="financial-action-menu-item post-option <?php echo (($salesStatus === 'draft') && ($purchaseStatus === 'draft')) ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if (($salesStatus === 'draft') && ($purchaseStatus === 'draft')): ?>
                                                            onclick="FamilyVisit.postFinance(<?php echo $r['id']; ?>, 'all')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-layer-group"></i>
                                                    <span>ترحيل الكل</span>
                                                </button>
                                                <button class="financial-action-menu-item post-option <?php echo ($hasSalesInvoice && $salesStatus === 'draft') ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if ($hasSalesInvoice && $salesStatus === 'draft'): ?>
                                                            onclick="FamilyVisit.postFinance(<?php echo $r['id']; ?>, 'sales')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                                    <span>ترحيل البيع</span>
                                                </button>
                                                <button class="financial-action-menu-item post-option <?php echo ($hasPurchaseInvoice && $purchaseStatus === 'draft') ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if ($hasPurchaseInvoice && $purchaseStatus === 'draft'): ?>
                                                            onclick="FamilyVisit.postFinance(<?php echo $r['id']; ?>, 'purchase')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-cart-plus"></i>
                                                    <span>ترحيل الشراء</span>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($canFinancialPost && $hasPostedInvoice && ($hasSalesInvoice || $hasPurchaseInvoice)): ?>
                                        <div class="financial-action-group">
                                            <button class="financial-action-btn fin-unpost" type="button" data-action-menu-toggle="unpost-<?php echo $r['id']; ?>" title="خيارات إلغاء الترحيل">
                                                <i class="fas fa-rotate-left"></i>
                                                <span>إلغاء الترحيل</span>
                                                <i class="fas fa-chevron-down small"></i>
                                            </button>
                                            <div class="financial-action-menu" id="unpost-<?php echo $r['id']; ?>">
                                                <div class="financial-action-menu-title">خيارات إلغاء الترحيل</div>
                                                <button class="financial-action-menu-item unpost-option <?php echo (($salesStatus === 'posted') && ($purchaseStatus === 'posted')) ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if (($salesStatus === 'posted') && ($purchaseStatus === 'posted')): ?>
                                                            onclick="FamilyVisit.unpostFinance(<?php echo $r['id']; ?>, 'all')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-layer-group"></i>
                                                    <span>إلغاء ترحيل الكل</span>
                                                </button>
                                                <button class="financial-action-menu-item unpost-option <?php echo ($hasSalesInvoice && $salesStatus === 'posted') ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if ($hasSalesInvoice && $salesStatus === 'posted'): ?>
                                                            onclick="FamilyVisit.unpostFinance(<?php echo $r['id']; ?>, 'sales')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-clock-rotate-left"></i>
                                                    <span>إلغاء ترحيل البيع</span>
                                                </button>
                                                <button class="financial-action-menu-item unpost-option <?php echo ($hasPurchaseInvoice && $purchaseStatus === 'posted') ? '' : 'disabled'; ?>"
                                                        type="button"
                                                        <?php if ($hasPurchaseInvoice && $purchaseStatus === 'posted'): ?>
                                                            onclick="FamilyVisit.unpostFinance(<?php echo $r['id']; ?>, 'purchase')"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-clock-rotate-left"></i>
                                                    <span>إلغاء ترحيل الشراء</span>
                                                </button>
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

<!-- Modal إضافة طلب جديد -->
<div class="modal fade" id="addRequestModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form id="addRequestForm" method="POST" enctype="multipart/form-data" action="process_family_visit.php?action=add">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="modal-header bg-info text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة طلب زيارة عائلية</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- القسم الأول: بيانات الطلب -->
                    <div class="section-title mb-3"><i class="fas fa-file-invoice text-info"></i> بيانات الطلب الأساسية</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">رقم المستند</label>
                            <input type="text" name="document_no" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3 position-relative">
                            <label class="form-label fw-bold" id="issue_date_label">تاريخ الإصدار</label>
                            <input type="hidden" name="issue_date" id="issue_date" required>
                            <input type="date" id="issue_date_greg" class="form-control rounded-3" required>
                            <input type="text" id="issue_date_hijri" class="form-control rounded-3 d-none" inputmode="numeric" placeholder="1447-01-01" pattern="\d{4}-\d{2}-\d{2}">
                            <div id="issue_date_hijri_picker" class="hijri-picker d-none"></div>
                            <div class="form-text small mt-1 d-none" id="issue_date_preview"></div>
                            <div class="form-text small mt-1" id="visit_remaining_preview"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">نوع التاريخ</label>
                            <select name="date_type" id="date_type" class="form-select rounded-3">
                                <option value="gregorian">ميلادي</option>
                                <option value="hijri">هجري</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">مدة الزيارة (شهر)</label>
                            <select name="visit_duration_months" id="visit_duration_months" class="form-select rounded-3">
                                <?php
                                $defaultMonths = (int)($settings['family_visit_default_validity_months'] ?? 1);
                                $monthOptions = [1, 2, 3, 6, 12];
                                if (!in_array($defaultMonths, $monthOptions, true)) {
                                    $monthOptions[] = $defaultMonths;
                                }
                                sort($monthOptions);
                                foreach ($monthOptions as $m): ?>
                                    <option value="<?php echo (int)$m; ?>" <?php echo ($m === $defaultMonths) ? 'selected' : ''; ?>>
                                        <?php echo (int)$m; ?> شهر
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="visit_expiry_date" id="visit_expiry_date">
                            <div class="form-text small mt-1" id="visit_expiry_preview"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">اسم صاحب الطلب</label>
                            <input type="text" name="owner_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">رقم السجل/الإقامة</label>
                            <input type="text" name="owner_id_no" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">رقم الجوال</label>
                            <input type="text" name="phone_no" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">العنوان</label>
                            <input type="text" name="address" class="form-control rounded-3">
                        </div>
                        <!-- Always show branch_id and agent_id as hidden fields based on current user -->
                        <?php if (!empty($currentUser['agent_id'])): ?>
                            <input type="hidden" name="agent_id" id="main_agent_id" value="<?php echo $currentUser['agent_id']; ?>">
                        <?php endif; ?>
                        <?php if (!empty($currentUser['branch_id'])): ?>
                            <input type="hidden" name="branch_id" id="main_branch_id" value="<?php echo $currentUser['branch_id']; ?>">
                        <?php endif; ?>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">صورة الإقامة</label>
                            <input type="file" name="iqama_image" class="form-control rounded-3" accept="image/*">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">مستند الزيارة (PDF)</label>
                            <input type="file" name="document_pdf" class="form-control rounded-3" accept="application/pdf,image/*">
                        </div>
                    </div>

                    <!-- القسم الثاني: الأفراد -->
                    <div class="section-title d-flex justify-content-between align-items-center mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span><i class="fas fa-user-friends text-info"></i> بيانات الأفراد</span>
                            <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                                <button type="button" class="btn btn-sm btn-outline-primary px-3" id="applyDefaultPriceBtn" title="تطبيق السعر الافتراضي">
                                    <i class="fas fa-download me-1"></i> تنزيل السعر
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- نموذج إدخال الفرد (Form Area) -->
                    <div class="bg-light p-3 rounded-4 mb-3 border border-2 border-info border-opacity-10 position-relative shadow-sm">
                        <!-- Pricing Info Badge -->
                        <div id="pricing_info_badge" class="position-absolute top-0 start-50 translate-middle-x badge bg-white border text-primary shadow-sm px-3 py-2 d-none" style="margin-top: -10px; z-index: 5;">
                            <i class="fas fa-tag me-1"></i> التسعيرة: 
                            <span id="target_purchase_label" class="fw-bold me-2">0.00</span> 
                            <span id="target_sale_label" class="fw-bold text-success me-2">0.00</span>
                            <span id="target_currency_label" class="small"></span>
                        </div>

                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label x-small fw-bold">الاسم الكامل</label>
                                <input type="text" id="entry_name" class="form-control form-control-sm rounded-2 border-0 shadow-sm" placeholder="اسم الفرد...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">رقم الجواز</label>
                                <input type="text" id="entry_passport" class="form-control form-control-sm rounded-2 border-0 shadow-sm" placeholder="رقم الجواز...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">الصلة</label>
                                <select id="entry_relationship" class="form-select form-select-sm rounded-2 border-0 shadow-sm">
                                    <option value="">اختر...</option>
                                    <?php foreach($relationships as $rel): ?>
                                    <option value="<?php echo $rel['id']; ?>" data-name="<?php echo $rel['name_ar']; ?>"><?php echo $rel['name_ar']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">الجنس</label>
                                <select id="entry_gender" class="form-select form-select-sm rounded-2 border-0 shadow-sm">
                                    <option value="male">ذكر</option>
                                    <option value="female">أنثى</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label x-small fw-bold">تاريخ الميلاد</label>
                                <input type="date" id="entry_dob" class="form-control form-control-sm rounded-2 border-0 shadow-sm">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label x-small fw-bold">العمر</label>
                                <input type="number" id="entry_age" class="form-control form-control-sm rounded-2 bg-white shadow-sm border-0" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">جهة القدوم</label>
                                <select id="entry_coming_from_city_id" class="form-select form-select-sm rounded-2 border-0 shadow-sm">
                                    <option value="">اختر...</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo (int)$city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">سعر الشراء</label>
                                <input type="number" step="0.01" id="entry_purchase" class="form-control form-control-sm rounded-2 border-0 shadow-sm fw-bold text-primary">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label x-small fw-bold">سعر البيع</label>
                                <input type="number" step="0.01" id="entry_sale" class="form-control form-control-sm rounded-2 border-0 shadow-sm fw-bold text-success">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label x-small fw-bold">الوثائق المستلمة</label>
                                <input type="text" id="entry_received_documents" class="form-control form-control-sm rounded-2 border-0 shadow-sm" placeholder="مثال: الجواز - صورة - إقامة">
                            </div>
                            <div class="col-md-7 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" id="clearEntryBtn">
                                    تفريغ <i class="fas fa-eraser ms-1"></i>
                                </button>
                                <button type="button" class="btn btn-info text-white rounded-pill px-4 shadow-sm fw-bold" id="pushToListBtn">
                                    إنزال الفرد <i class="fas fa-arrow-down ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive mb-3 shadow-sm rounded-3 overflow-hidden">
                        <table class="table table-bordered table-hover align-middle mb-0" id="individualsTable">
                            <thead class="bg-primary text-white x-small">
                                <tr class="fw-bold">
                                    <th class="border-0">الاسم</th>
                                    <th class="border-0">رقم الجواز</th>
                                    <th class="border-0">الصلة</th>
                                    <th class="border-0">الجنس</th>
                                    <th class="border-0">تاريخ الميلاد</th>
                                    <th class="border-0">العمر</th>
                                    <th class="border-0">جهة القدوم</th>
                                    <th class="border-0">تكلفة البند</th>
                                    <th class="border-0">مبلغ البند</th>
                                    <th class="border-0">الوثائق المستلمة</th>
                                    <th class="border-0 text-center" style="width: 80px;">إجراء</th>
                                </tr>
                            </thead>
                            <tbody id="individualsList" class="x-small">
                                <!-- سيتم إضافة الأفراد هنا ديناميكياً -->
                            </tbody>
                            <tfoot class="bg-light fw-bold x-small">
                                <tr>
                                    <td colspan="7" class="text-end">الإجمالي:</td>
                                    <td id="totalPurchasePrice" class="text-primary fw-bold">0.00</td>
                                    <td id="totalSalePrice" class="text-success fw-bold">0.00</td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- القسم الثالث: البيانات المالية الموحدة -->
                    <?php
                    $family_service_id = 5;
                    $family_agent_id = $currentUser['agent_id'] ?? null;
                    $family_branch_id = $currentUser['branch_id'] ?? null;
                    $family_price_config = null;
                    try {
                        $family_price_config = get_service_price_config($pdo, $family_service_id, $family_agent_id, $family_branch_id, null, null);
                    } catch (Exception $e) {
                        $family_price_config = null;
                    }
                    $family_currency_id = (int)($family_price_config['currency_id'] ?? ($settings['base_currency_id'] ?? 1));

                    $current_invoice = [
                        'invoice_date' => normalize_datetime_db(null),
                        'branch_id' => $_SESSION['branch_id'] ?? null,
                        'source_type' => 'الزيارة العائلية',
                        'delivery_type' => $settings['default_delivery_type'] ?? 'draft',
                        'record_purchase' => 1,
                        'total_amount' => 0,
                        'discount' => 0,
                        'cost_amount' => 0,
                        'amount_received' => 0,
                        'sale_currency_id' => $family_currency_id,
                        'currency_id' => $family_currency_id,
                        'exchange_rate' => 1,
                        'description' => ''
                    ];
                    $financial_fields_select2_parent = '#addRequestModal';
                    $financial_fields_show_service_select = false;
                    $financial_fields_form_selector = '#addRequestForm';
                    $financial_fields_hide_service_accounts = true;
                    include '../includes/financial_fields.php';
                    ?>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات عامة</label>
                        <textarea name="notes" class="form-control rounded-3" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-info text-white rounded-pill px-5 fw-bold">حفظ الطلب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Template للفرد الجديد -->
<template id="individualRowTemplate">
    <tr class="individual-row">
        <td class="display-name"></td>
        <td class="display-passport"></td>
        <td class="display-relationship"></td>
        <td class="display-gender"></td>
        <td class="display-dob"></td>
        <td class="display-age text-center"></td>
        <td class="display-coming-from"></td>
        <td class="display-purchase fw-bold text-primary"></td>
        <td class="display-sale fw-bold text-success"></td>
        <td class="display-received-docs"></td>
        <td class="text-center p-0">
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-link text-primary edit-individual" title="تعديل"><i class="fas fa-edit"></i></button>
                <button type="button" class="btn btn-link text-danger remove-individual" title="حذف"><i class="fas fa-times"></i></button>
            </div>
            <!-- Hidden inputs for form submission -->
            <input type="hidden" name="ind_name[]" class="input-name">
            <input type="hidden" name="ind_passport[]" class="input-passport">
            <input type="hidden" name="ind_relationship[]" class="input-relationship">
            <input type="hidden" name="ind_gender[]" class="input-gender">
            <input type="hidden" name="ind_dob[]" class="input-dob">
            <input type="hidden" name="ind_age[]" class="input-age">
            <input type="hidden" name="ind_coming_from_city_id[]" class="input-coming-from-city">
            <input type="hidden" name="ind_received_documents[]" class="input-received-docs">
            <input type="hidden" name="ind_cost_amount[]" class="input-purchase purchase-price-input">
            <input type="hidden" name="ind_line_total_amount[]" class="input-sale sale-price-input">
        </td>
    </tr>
    <tr class="requirements-row bg-light bg-opacity-50">
        <td colspan="11">
            <div class="requirements-container p-2 small text-muted">
                <span class="me-2"><i class="fas fa-tasks me-1"></i> المتطلبات:</span>
                <div class="requirements-list d-inline-flex flex-wrap gap-2">
                    <!-- سيتم تحميل المتطلبات هنا -->
                </div>
            </div>
        </td>
    </tr>
</template>

<!-- Modal عرض التفاصيل وتغيير الحالة -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-0 py-3">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-eye me-2"></i> تفاصيل طلب الزيارة العائلية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewRequestContent">
                <!-- سيتم تحميل المحتوى هنا عبر AJAX -->
                <div class="text-center py-5">
                    <div class="spinner-border text-info" role="status"></div>
                    <div class="mt-2 text-muted">جاري تحميل البيانات...</div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode(generate_csrf_token()); ?>;
document.addEventListener('DOMContentLoaded', function() {
    const dateTypeSelect = document.getElementById('date_type');
    const issueDateLabel = document.getElementById('issue_date_label');
    const issueDateHidden = document.getElementById('issue_date');
    const issueDateGreg = document.getElementById('issue_date_greg');
    const issueDateHijri = document.getElementById('issue_date_hijri');
    const issueDateHijriPicker = document.getElementById('issue_date_hijri_picker');
    const issueDatePreview = document.getElementById('issue_date_preview');
    const visitRemainingPreview = document.getElementById('visit_remaining_preview');
    const visitDurationMonths = document.getElementById('visit_duration_months');
    const visitExpiryHidden = document.getElementById('visit_expiry_date');
    const visitExpiryPreview = document.getElementById('visit_expiry_preview');

    const individualsList = document.getElementById('individualsList');
    const template = document.getElementById('individualRowTemplate');
    const pushToListBtn = document.getElementById('pushToListBtn');
    const clearEntryBtn = document.getElementById('clearEntryBtn');

    // حقول الإدخال (Entry Fields)
    const entryName = document.getElementById('entry_name');
    const entryPassport = document.getElementById('entry_passport');
    const entryRelationship = document.getElementById('entry_relationship');
    const entryGender = document.getElementById('entry_gender');
    const entryDob = document.getElementById('entry_dob');
    const entryAge = document.getElementById('entry_age');
    const entryComingFromCity = document.getElementById('entry_coming_from_city_id');
    const entryPurchase = document.getElementById('entry_purchase');
    const entrySale = document.getElementById('entry_sale');
    const entryReceivedDocuments = document.getElementById('entry_received_documents');

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function gregorianToJD(y, m, d) {
        if (m <= 2) {
            y -= 1;
            m += 12;
        }
        const a = Math.floor(y / 100);
        const b = 2 - a + Math.floor(a / 4);
        return Math.floor(365.25 * (y + 4716)) + Math.floor(30.6001 * (m + 1)) + d + b - 1524;
    }

    function jdToGregorian(jd) {
        let z = jd;
        let a = z;
        const alpha = Math.floor((a - 1867216.25) / 36524.25);
        a = a + 1 + alpha - Math.floor(alpha / 4);
        const b = a + 1524;
        const c = Math.floor((b - 122.1) / 365.25);
        const d = Math.floor(365.25 * c);
        const e = Math.floor((b - d) / 30.6001);
        const day = b - d - Math.floor(30.6001 * e);
        const month = (e < 14) ? (e - 1) : (e - 13);
        const year = (month > 2) ? (c - 4716) : (c - 4715);
        return { year, month, day };
    }

    function hijriToJD(y, m, d) {
        return d + Math.ceil(29.5 * (m - 1)) + (y - 1) * 354 + Math.floor((3 + 11 * y) / 30) + 1948439 - 1;
    }

    function jdToHijri(jd) {
        const y = Math.floor((30 * (jd - 1948439) + 10646) / 10631);
        const m = Math.min(12, Math.ceil((jd - (29 + hijriToJD(y, 1, 1))) / 29.5) + 1);
        const d = jd - hijriToJD(y, m, 1) + 1;
        return { year: y, month: m, day: d };
    }

    function parseYmd(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((value || '').trim());
        if (!m) return null;
        return { y: parseInt(m[1], 10), m: parseInt(m[2], 10), d: parseInt(m[3], 10) };
    }

    const hijriMonthNames = [
        'محرم',
        'صفر',
        'ربيع الأول',
        'ربيع الآخر',
        'جمادى الأولى',
        'جمادى الآخرة',
        'رجب',
        'شعبان',
        'رمضان',
        'شوال',
        'ذو القعدة',
        'ذو الحجة'
    ];

    function isHijriLeapYear(y) {
        return ((11 * y + 14) % 30) < 11;
    }

    function hijriMonthLength(y, m) {
        if (m % 2 === 1) {
            return 30;
        }
        if (m !== 12) {
            return 29;
        }
        return isHijriLeapYear(y) ? 30 : 29;
    }

    function getTodayHijri() {
        const now = new Date();
        const jd = gregorianToJD(now.getFullYear(), now.getMonth() + 1, now.getDate());
        return jdToHijri(jd);
    }

    function getHijriFromHidden() {
        const g = parseYmd(issueDateHidden?.value || '');
        if (!g) return null;
        const jd = gregorianToJD(g.y, g.m, g.d);
        return jdToHijri(jd);
    }

    let hijriPickerState = { y: null, m: null };

    function closeHijriPicker() {
        if (!issueDateHijriPicker) return;
        issueDateHijriPicker.classList.add('d-none');
    }

    function openHijriPicker() {
        if (!issueDateHijriPicker || !issueDateHijri) return;
        if (issueDateHijri.classList.contains('d-none')) return;

        const parsed = parseYmd(issueDateHijri.value);
        const base = parsed ? { year: parsed.y, month: parsed.m, day: parsed.d } : (getHijriFromHidden() || getTodayHijri());
        hijriPickerState.y = base.year;
        hijriPickerState.m = base.month;
        renderHijriPicker(base.day);
        issueDateHijriPicker.classList.remove('d-none');
    }

    function renderHijriPicker(selectedDay) {
        if (!issueDateHijriPicker) return;
        const y = hijriPickerState.y || getTodayHijri().year;
        const m = hijriPickerState.m || getTodayHijri().month;

        const jdFirst = hijriToJD(y, m, 1);
        const gFirst = jdToGregorian(jdFirst);
        const firstDate = new Date(gFirst.year, gFirst.month - 1, gFirst.day);
        const firstDowSun0 = firstDate.getDay();
        const firstIndex = (firstDowSun0 + 1) % 7;

        const daysInMonth = hijriMonthLength(y, m);

        const todayH = getTodayHijri();
        const parsedSelected = parseYmd(issueDateHijri?.value || '');
        const selected = parsedSelected ? { y: parsedSelected.y, m: parsedSelected.m, d: parsedSelected.d } : null;

        const startYear = y - 10;
        const endYear = y + 10;

        const monthOptions = hijriMonthNames.map((name, idx) => {
            const mm = idx + 1;
            return `<option value="${mm}" ${mm === m ? 'selected' : ''}>${name}</option>`;
        }).join('');

        const yearOptions = Array.from({ length: endYear - startYear + 1 }, (_, i) => startYear + i).map(yy => {
            return `<option value="${yy}" ${yy === y ? 'selected' : ''}>${yy}</option>`;
        }).join('');

        const week = ['س', 'ح', 'ن', 'ث', 'ر', 'خ', 'ج'].map(w => `<div>${w}</div>`).join('');

        const cells = [];
        for (let i = 0; i < firstIndex; i++) {
            cells.push('<button type="button" class="hijri-day is-empty" tabindex="-1"></button>');
        }
        for (let d = 1; d <= daysInMonth; d++) {
            const isToday = (todayH.year === y && todayH.month === m && todayH.day === d);
            const isSelected = selected ? (selected.y === y && selected.m === m && selected.d === d) : (selectedDay === d);
            const classes = ['hijri-day'];
            if (isToday) classes.push('is-today');
            if (isSelected) classes.push('is-selected');
            cells.push(`<button type="button" class="${classes.join(' ')}" data-hy="${y}" data-hm="${m}" data-hd="${d}">${d}</button>`);
        }
        while (cells.length % 7 !== 0) {
            cells.push('<button type="button" class="hijri-day is-empty" tabindex="-1"></button>');
        }

        issueDateHijriPicker.innerHTML = `
            <div class="hijri-picker-header">
                <button type="button" class="btn btn-sm btn-light" data-hijri-nav="-1">‹</button>
                <div class="hijri-picker-selects">
                    <select class="form-select form-select-sm" data-hijri-month>${monthOptions}</select>
                    <select class="form-select form-select-sm" data-hijri-year>${yearOptions}</select>
                </div>
                <button type="button" class="btn btn-sm btn-light" data-hijri-nav="1">›</button>
            </div>
            <div class="hijri-week">${week}</div>
            <div class="hijri-grid">${cells.join('')}</div>
        `;
    }

    function formatArabicDate(y, m, d) {
        return `${y}/${pad2(m)}/${pad2(d)}`;
    }

    function formatGregorianArabic(value) {
        const g = parseYmd(value);
        if (!g) return '---';
        return formatArabicDate(g.y, g.m, g.d);
    }

    function formatHijriArabic(value) {
        const h = parseYmd(value);
        if (!h) return '---';
        return formatArabicDate(h.y, h.m, h.d);
    }

    function updateIssueDatePreview() {
        if (!issueDatePreview || !dateTypeSelect) {
            return;
        }

        const type = dateTypeSelect.value || 'gregorian';
        const hiddenValue = issueDateHidden ? issueDateHidden.value : '';
        const hijriValue = issueDateHijri ? issueDateHijri.value : '';
        const gregValue = issueDateGreg ? issueDateGreg.value : '';

        if (type === 'hijri') {
            issueDatePreview.classList.remove('d-none');
            if (!hijriValue) {
                issueDatePreview.textContent = 'أدخل التاريخ الهجري بصيغة YYYY-MM-DD، وسيتم حفظ مقابله الميلادي تلقائيًا.';
                return;
            }
            issueDatePreview.textContent = `التاريخ الهجري المعروض: ${formatHijriArabic(hijriValue)} | المحفوظ ميلاديًا: ${formatGregorianArabic(hiddenValue)}`;
            return;
        }
        issueDatePreview.textContent = '';
        issueDatePreview.classList.add('d-none');
    }

    function addMonthsSafe(ymd, monthsToAdd) {
        const p = parseYmd(ymd);
        if (!p) return null;
        const base = new Date(p.y, p.m - 1, p.d);
        const targetMonth = base.getMonth() + monthsToAdd;
        const target = new Date(base);
        target.setMonth(targetMonth);
        if (target.getMonth() !== ((targetMonth % 12) + 12) % 12) {
            target.setDate(0);
        }
        const y = target.getFullYear();
        const m = target.getMonth() + 1;
        const d = target.getDate();
        return `${y}-${pad2(m)}-${pad2(d)}`;
    }

    function updateExpiryPreview() {
        if (!visitDurationMonths || !visitExpiryHidden || !issueDateHidden) {
            return;
        }
        const issue = issueDateHidden.value;
        const months = parseInt(visitDurationMonths.value || '1', 10) || 1;
        if (!issue) {
            visitExpiryHidden.value = '';
            if (visitExpiryPreview) visitExpiryPreview.textContent = '';
            if (visitRemainingPreview) visitRemainingPreview.textContent = '';
            return;
        }

        const expiry = addMonthsSafe(issue, months);
        if (!expiry) {
            visitExpiryHidden.value = '';
            if (visitExpiryPreview) visitExpiryPreview.textContent = '';
            if (visitRemainingPreview) visitRemainingPreview.textContent = '';
            return;
        }
        visitExpiryHidden.value = expiry;

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const pExp = parseYmd(expiry);
        const expDate = pExp ? new Date(pExp.y, pExp.m - 1, pExp.d) : null;
        if (!expDate) {
            if (visitExpiryPreview) visitExpiryPreview.textContent = '';
            if (visitRemainingPreview) visitRemainingPreview.textContent = '';
            return;
        }
        expDate.setHours(0, 0, 0, 0);
        const diffDays = Math.ceil((expDate.getTime() - today.getTime()) / 86400000);

        const type = dateTypeSelect ? (dateTypeSelect.value || 'gregorian') : 'gregorian';
        if (type === 'hijri') {
            const h = jdToHijri(gregorianToJD(pExp.y, pExp.m, pExp.d));
            const expiryHijri = `${h.year}/${pad2(h.month)}/${pad2(h.day)} هجري`;
            if (visitExpiryPreview) visitExpiryPreview.textContent = `تاريخ الانتهاء: ${expiryHijri}`;
        } else {
            const expiryGreg = `${pExp.y}/${pad2(pExp.m)}/${pad2(pExp.d)}`;
            if (visitExpiryPreview) visitExpiryPreview.textContent = `تاريخ الانتهاء: ${expiryGreg}`;
        }
        if (visitRemainingPreview) {
            visitRemainingPreview.textContent = diffDays >= 0
                ? `المتبقي حتى الانتهاء: ${diffDays} يوم`
                : `منتهي منذ: ${Math.abs(diffDays)} يوم`;
        }
    }

    function setIssueDateMode(type) {
        if (!issueDateHidden || !issueDateGreg || !issueDateHijri) {
            return;
        }
        const isHijri = type === 'hijri';
        issueDateGreg.classList.toggle('d-none', isHijri);
        issueDateHijri.classList.toggle('d-none', !isHijri);
        issueDateGreg.disabled = isHijri;
        issueDateHijri.disabled = !isHijri;
        issueDateGreg.required = !isHijri;
        issueDateHijri.required = isHijri;
        if (issueDateLabel) {
            issueDateLabel.textContent = isHijri ? 'تاريخ الإصدار (هجري)' : 'تاريخ الإصدار (ميلادي)';
        }

        if (isHijri) {
            if (issueDateHidden.value) {
                const g = parseYmd(issueDateHidden.value);
                if (g) {
                    const jd = gregorianToJD(g.y, g.m, g.d);
                    const h = jdToHijri(jd);
                    issueDateHijri.value = `${h.year}-${pad2(h.month)}-${pad2(h.day)}`;
                }
            } else if (issueDateGreg.value) {
                const g = parseYmd(issueDateGreg.value);
                if (g) {
                    issueDateHidden.value = issueDateGreg.value;
                    const jd = gregorianToJD(g.y, g.m, g.d);
                    const h = jdToHijri(jd);
                    issueDateHijri.value = `${h.year}-${pad2(h.month)}-${pad2(h.day)}`;
                }
            }
        } else {
            if (issueDateHidden.value) {
                issueDateGreg.value = issueDateHidden.value;
            }
            issueDateHijri.value = '';
        }

        updateIssueDatePreview();
        updateExpiryPreview();

        if (type === 'hijri') {
            setTimeout(function() {
                openHijriPicker();
            }, 0);
        } else {
            closeHijriPicker();
        }
    }

    function syncIssueDate() {
        if (!issueDateHidden || !issueDateGreg || !issueDateHijri || !dateTypeSelect) {
            return;
        }
        const type = dateTypeSelect.value || 'gregorian';
        if (type === 'hijri') {
            const h = parseYmd(issueDateHijri.value);
            if (!h) {
                issueDateHidden.value = '';
                updateIssueDatePreview();
                return;
            }
            const jd = hijriToJD(h.y, h.m, h.d);
            const g = jdToGregorian(jd);
            issueDateHidden.value = `${g.year}-${pad2(g.month)}-${pad2(g.day)}`;
        } else {
            issueDateHidden.value = issueDateGreg.value || '';
        }
        updateIssueDatePreview();
        updateExpiryPreview();
    }

    if (dateTypeSelect && issueDateHidden && issueDateGreg && issueDateHijri) {
        dateTypeSelect.addEventListener('change', function() {
            setIssueDateMode(this.value);
            syncIssueDate();
        });
        issueDateGreg.addEventListener('change', function() {
            syncIssueDate();
        });
        issueDateHijri.addEventListener('input', function() {
            syncIssueDate();
        });
        setIssueDateMode(dateTypeSelect.value || 'gregorian');
        syncIssueDate();
    }

    if (issueDateHijri && issueDateHijriPicker) {
        issueDateHijri.addEventListener('focus', function() {
            if ((dateTypeSelect?.value || 'gregorian') === 'hijri') {
                openHijriPicker();
            }
        });
        issueDateHijri.addEventListener('click', function() {
            if ((dateTypeSelect?.value || 'gregorian') === 'hijri') {
                openHijriPicker();
            }
        });
        document.addEventListener('click', function(e) {
            if (issueDateHijri.classList.contains('d-none')) return;
            const isInside = issueDateHijriPicker.contains(e.target) || issueDateHijri.contains(e.target);
            if (!isInside) {
                closeHijriPicker();
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeHijriPicker();
            }
        });
        issueDateHijriPicker.addEventListener('click', function(e) {
            const dayBtn = e.target.closest('[data-hy][data-hm][data-hd]');
            if (dayBtn) {
                const hy = parseInt(dayBtn.getAttribute('data-hy') || '', 10);
                const hm = parseInt(dayBtn.getAttribute('data-hm') || '', 10);
                const hd = parseInt(dayBtn.getAttribute('data-hd') || '', 10);
                if (hy && hm && hd) {
                    issueDateHijri.value = `${hy}-${pad2(hm)}-${pad2(hd)}`;
                    syncIssueDate();
                    closeHijriPicker();
                }
                return;
            }

            const navBtn = e.target.closest('[data-hijri-nav]');
            if (navBtn) {
                const step = parseInt(navBtn.getAttribute('data-hijri-nav') || '0', 10);
                let y = hijriPickerState.y || getTodayHijri().year;
                let m = hijriPickerState.m || getTodayHijri().month;
                m += step;
                if (m < 1) {
                    m = 12;
                    y -= 1;
                } else if (m > 12) {
                    m = 1;
                    y += 1;
                }
                hijriPickerState.y = y;
                hijriPickerState.m = m;
                renderHijriPicker(1);
                return;
            }

            const monthSelect = e.target.closest('[data-hijri-month]');
            if (monthSelect) {
                const m = parseInt(monthSelect.value || '', 10);
                if (m >= 1 && m <= 12) {
                    hijriPickerState.m = m;
                    renderHijriPicker(1);
                }
                return;
            }

            const yearSelect = e.target.closest('[data-hijri-year]');
            if (yearSelect) {
                const y = parseInt(yearSelect.value || '', 10);
                if (y) {
                    hijriPickerState.y = y;
                    renderHijriPicker(1);
                }
                return;
            }
        });
    }

    if (visitDurationMonths) {
        visitDurationMonths.addEventListener('change', function() {
            updateExpiryPreview();
        });
        updateExpiryPreview();
    }

    function setFinancialTotals(totalSale, totalPurchase) {
        const saleSelectors = [
            '#total_amount',
            'input[name="total_amount"]',
            '#sale_price',
            'input[name="sale_price"]'
        ];
        const purchaseSelectors = [
            '#cost_amount',
            'input[name="cost_amount"]',
            '#purchase_price',
            'input[name="purchase_price"]'
        ];

        saleSelectors.forEach(selector => {
            document.querySelectorAll(selector).forEach(input => {
                input.value = totalSale.toFixed(2);
                input.setAttribute('data-original-price', totalSale.toFixed(2));
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        purchaseSelectors.forEach(selector => {
            document.querySelectorAll(selector).forEach(input => {
                input.value = totalPurchase.toFixed(2);
                input.setAttribute('data-original-cost', totalPurchase.toFixed(2));
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        if (window.FinancialFields && typeof window.FinancialFields.updateLogic === 'function') {
            window.FinancialFields.updateLogic();
        }
    }

    function calculateTotals() {
        let totalPurchase = 0;
        let totalSale = 0;
        let names = [];

        const scope = individualsList || document;
        const purchaseInputs = scope.querySelectorAll('.individual-row .purchase-price-input');
        const saleInputs = scope.querySelectorAll('.individual-row .sale-price-input');
        const nameInputs = scope.querySelectorAll('.individual-row .input-name');
        
        purchaseInputs.forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) totalPurchase += val;
        });
        
        saleInputs.forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) totalSale += val;
        });

        nameInputs.forEach(input => {
            if (input.value.trim() !== '') {
                names.push(input.value.trim());
            }
        });
        
        document.getElementById('totalPurchasePrice').innerText = totalPurchase.toFixed(2);
        document.getElementById('totalSalePrice').innerText = totalSale.toFixed(2);
        setFinancialTotals(totalSale, totalPurchase);

        // تحديث حقل البيان تلقائياً
        const descriptionInput = document.getElementById('description');
        if (descriptionInput && names.length > 0) {
            const count = names.length;
            const namesStr = names.join(' - ');
            descriptionInput.value = `معاملة زيارة عائلية لعدد (${count}) أفراد: ${namesStr}`;
        } else if (descriptionInput) {
            descriptionInput.value = '';
        }
    }

    // حساب العمر تلقائياً عند تغيير تاريخ الميلاد في النموذج
    entryDob.addEventListener('input', function() {
        if (this.value) {
            const dob = new Date(this.value);
            const diff = Date.now() - dob.getTime();
            const ageDate = new Date(diff);
            const age = Math.abs(ageDate.getUTCFullYear() - 1970);
            entryAge.value = age;
        }
    });

    // تفريغ حقول الإدخال
    function clearForm() {
        entryName.value = '';
        entryPassport.value = '';
        entryRelationship.value = '';
        entryGender.value = 'male';
        entryDob.value = '';
        entryAge.value = '';
        if (entryComingFromCity) entryComingFromCity.value = '';
        if (entryReceivedDocuments) entryReceivedDocuments.value = '';
        // لا نفرغ الأسعار لتسهيل إدخال الفرد التالي بنفس السعر
    }

    clearEntryBtn.addEventListener('click', clearForm);

    // إنزال الفرد إلى الجدول
    pushToListBtn.addEventListener('click', function() {
        if (!entryName.value || !entryPassport.value || !entryRelationship.value) {
            Swal.fire({
                icon: 'error',
                title: 'خطأ',
                text: 'يرجى إكمال بيانات الاسم والجواز والصلة',
                confirmButtonText: 'حسناً'
            });
            return;
        }

        // تحقق من تكرار نفس الاسم أو رقم الجواز في نفس الطلب
        let duplicate = false;
        const existingNames = individualsList.querySelectorAll('.input-name');
        const existingPassports = individualsList.querySelectorAll('.input-passport');
        const nameTrimmed = entryName.value.trim();
        const passportTrimmed = entryPassport.value.trim();

        for (let i = 0; i < existingNames.length; i++) {
            if (existingNames[i].value.trim() === nameTrimmed) {
                Swal.fire({
                    icon: 'warning',
                    title: 'تكرار',
                    text: `الاسم "${nameTrimmed}" موجود بالفعل في نفس الطلب`,
                    confirmButtonText: 'حسناً'
                });
                duplicate = true;
                break;
            }
            if (existingPassports[i].value.trim() === passportTrimmed) {
                Swal.fire({
                    icon: 'warning',
                    title: 'تكرار',
                    text: `رقم الجواز "${passportTrimmed}" موجود بالفعل في نفس الطلب`,
                    confirmButtonText: 'حسناً'
                });
                duplicate = true;
                break;
            }
        }

        if (duplicate) {
            return;
        }

        const clone = template.content.cloneNode(true);
        const row = clone.querySelector('.individual-row');
        const comingFromText = entryComingFromCity && entryComingFromCity.value
            ? (entryComingFromCity.options[entryComingFromCity.selectedIndex]?.text || '---')
            : '---';
        
        // تعبئة البيانات المرئية
        row.querySelector('.display-name').innerText = entryName.value;
        row.querySelector('.display-passport').innerText = entryPassport.passport || entryPassport.value;
        row.querySelector('.display-relationship').innerText = entryRelationship.options[entryRelationship.selectedIndex].text;
        row.querySelector('.display-gender').innerText = entryGender.options[entryGender.selectedIndex].text;
        row.querySelector('.display-dob').innerText = entryDob.value;
        row.querySelector('.display-age').innerText = entryAge.value;
        row.querySelector('.display-coming-from').innerText = comingFromText;
        row.querySelector('.display-purchase').innerText = parseFloat(entryPurchase.value || 0).toFixed(2);
        row.querySelector('.display-sale').innerText = parseFloat(entrySale.value || 0).toFixed(2);
        row.querySelector('.display-received-docs').innerText = (entryReceivedDocuments?.value || '').trim() || '---';

        // تعبئة الحقول المخفية (لإرسالها للسيرفر)
        row.querySelector('.input-name').value = entryName.value;
        row.querySelector('.input-passport').value = entryPassport.value;
        row.querySelector('.input-relationship').value = entryRelationship.value;
        row.querySelector('.input-gender').value = entryGender.value;
        row.querySelector('.input-dob').value = entryDob.value;
        row.querySelector('.input-age').value = entryAge.value;
        row.querySelector('.input-coming-from-city').value = entryComingFromCity?.value || '';
        row.querySelector('.input-received-docs').value = (entryReceivedDocuments?.value || '').trim();
        row.querySelector('.input-purchase').value = entryPurchase.value;
        row.querySelector('.input-sale').value = entrySale.value;

        individualsList.appendChild(clone);
        calculateTotals();
        clearForm();
        
        // تحديث المتطلبات للسطر المضاف حديثاً
        const lastRow = individualsList.lastElementChild.previousElementSibling;
        updateRequirements(lastRow);
    });

    // إجراءات الجدول (تعديل وحذف)
    individualsList.addEventListener('click', function(e) {
        // حذف
        if (e.target.closest('.remove-individual')) {
            const row = e.target.closest('.individual-row');
            const reqRow = row.nextElementSibling;
            row.remove();
            if (reqRow && reqRow.classList.contains('requirements-row')) reqRow.remove();
            calculateTotals();
        }
        
        // تعديل (سحب البيانات للنموذج)
        if (e.target.closest('.edit-individual')) {
            const row = e.target.closest('.individual-row');
            
            // سحب البيانات للنموذج العلوي
            entryName.value = row.querySelector('.input-name').value;
            entryPassport.value = row.querySelector('.input-passport').value;
            entryRelationship.value = row.querySelector('.input-relationship').value;
            entryGender.value = row.querySelector('.input-gender').value;
            entryDob.value = row.querySelector('.input-dob').value;
            entryAge.value = row.querySelector('.input-age').value;
            if (entryComingFromCity) entryComingFromCity.value = row.querySelector('.input-coming-from-city')?.value || '';
            entryPurchase.value = row.querySelector('.input-purchase').value;
            entrySale.value = row.querySelector('.input-sale').value;
            if (entryReceivedDocuments) entryReceivedDocuments.value = row.querySelector('.input-received-docs')?.value || '';

            // حذف السطر من الجدول بعد سحبه للتعديل
            const reqRow = row.nextElementSibling;
            row.remove();
            if (reqRow && reqRow.classList.contains('requirements-row')) reqRow.remove();
            calculateTotals();
            
            // التركيز على حقل الاسم
            entryName.focus();
        }
    });

    window.updatePaymentLogic = function() {
        const paymentType = $('#payment_type').val();
        const accountSelect = $('#account_id');
        const accountLabel = $('#account_label');
        const amountReceived = $('#amount_received');

        // تفريغ الحسابات
        accountSelect.empty().append('<option value="">اختر الحساب...</option>');
        
        // إعادة تعيين الحقول المخفية
        $('#customer_id_hidden').val('');
        $('#agent_id_hidden').val('');
        $('#branch_id_hidden').val('');

        let accounts = [];
        let label = 'الحساب';

        if (paymentType === 'cash') {
            accounts = <?php echo json_encode($cashboxes_entities); ?>;
            label = 'الصندوق (نقد)';
            amountReceived.prop('readonly', false).removeClass('bg-light');
        } else if (paymentType === 'credit') {
            accounts = <?php echo json_encode($customers_entities); ?>;
            label = 'العميل (آجل)';
            amountReceived.val('0.00').prop('readonly', true).addClass('bg-light');
        } else if (paymentType === 'agent') {
            accounts = <?php echo json_encode($agents_entities); ?>;
            label = 'الوكيل (آجل)';
            amountReceived.val('0.00').prop('readonly', true).addClass('bg-light');
        } else if (paymentType === 'branch') {
            accounts = <?php echo json_encode($branches_accounts); ?>;
            label = 'الفرع (آجل)';
            amountReceived.val('0.00').prop('readonly', true).addClass('bg-light');
        } else if (paymentType === 'bank_transfer') {
            accounts = <?php echo json_encode($banks_entities); ?>;
            label = 'البنك (تحويل)';
            amountReceived.prop('readonly', false).removeClass('bg-light');
        }

        accountLabel.text(label);
        accounts.forEach(acc => {
            const displayName = acc.account_code ? `${acc.account_code} - ${acc.name || acc.account_name}` : (acc.name || acc.account_name);
            const value = paymentType === 'cash' || paymentType === 'bank_transfer' ? acc.account_id : acc.id;
            const customerId = paymentType === 'credit' ? acc.id : '';
            const agentId = paymentType === 'agent' ? acc.id : '';
            
            accountSelect.append(`<option value="${value}" data-customer-id="${customerId}" data-agent-id="${agentId}">${displayName}</option>`);
        });
    };

    window.loadPricesForSelectedAccount = async function() {
        const paymentType = $('#payment_type').val();
        const selectedId = $('#account_id').val();
        const pricingBadge = document.getElementById('pricing_info_badge');

        // تعبئة الحقل المخفي المناسب بناءً على نوع الدفع
        $('#customer_id_hidden').val(paymentType === 'credit' ? selectedId : '');
        $('#agent_id_hidden').val(paymentType === 'agent' ? selectedId : '');
        $('#branch_id_hidden').val(paymentType === 'branch' ? selectedId : '');

        let url = `ajax_family_visit.php?action=get_service_price`;
        if (selectedId) {
            if (paymentType === 'credit') url += `&customer_id=${selectedId}`;
            else if (paymentType === 'agent') url += `&agent_id=${selectedId}`;
            else if (paymentType === 'branch') url += `&branch_id=${selectedId}`;
        }

        try {
            const res = await fetch(url);
            const result = await res.json();
            
            if (result.status === 'success') {
                const data = result.data;
                const purchasePrice = data.purchase_price;
                const salePrice = data.sale_price;
                const currencySymbol = data.currency_symbol;
                const currencyId = data.currency_id;

                // تحديث نموذج الإدخال (Entry Fields)
                if (entryPurchase) entryPurchase.value = purchasePrice;
                if (entrySale) entrySale.value = salePrice;
                if (currencyId) {
                    const $mainCurrency = $('#main_currency_id');
                    const $saleCurrency = $('#sale_currency_id');
                    if ($mainCurrency.length) $mainCurrency.val(String(currencyId)).trigger('change');
                    if ($saleCurrency.length) $saleCurrency.val(String(currencyId)).trigger('change');
                }

                // إظهار بادج التسعيرة
                if (pricingBadge) {
                    pricingBadge.classList.remove('d-none');
                    document.getElementById('target_purchase_label').innerText = purchasePrice.toFixed(2);
                    document.getElementById('target_sale_label').innerText = salePrice.toFixed(2);
                    document.getElementById('target_currency_label').innerText = currencySymbol;
                }
                
                // تحديث التيمبلت للأسطر القادمة
                const purchaseInputTpl = template.content.querySelector('.purchase-price-input');
                const saleInputTpl = template.content.querySelector('.sale-price-input');
                if (purchaseInputTpl) purchaseInputTpl.value = purchasePrice;
                if (saleInputTpl) saleInputTpl.value = salePrice;
            } else {
                if (pricingBadge) pricingBadge.classList.add('d-none');
            }
        } catch (err) {
            console.error('Error loading prices:', err);
        }
    };

    // عند فتح مودال الإضافة، تأكد من تحديث منطق الدفع وجلب الأسعار الافتراضية
    const addRequestModal = document.getElementById('addRequestModal');
    if (addRequestModal) {
        addRequestModal.addEventListener('shown.bs.modal', function () {
            updatePaymentLogic();
            loadPricesForSelectedAccount(); // جلب السعر الافتراضي العام عند الفتح
        });
    }

    const addRequestForm = document.getElementById('addRequestForm');
    if (addRequestForm) {
        addRequestForm.addEventListener('submit', function(e) {
            syncIssueDate();
            calculateTotals();
            const hasIndividuals = (individualsList && individualsList.querySelectorAll('.individual-row').length > 0);
            if (!hasIndividuals) {
                e.preventDefault();
                alert('يرجى إضافة فرد واحد على الأقل قبل الحفظ.');
            }
        });
    }

    // دالة جلب السعر التلقائي
    window.updateServicePrices = async function() {
        const agentId = document.getElementById('main_agent_id')?.value;
        const branchId = document.getElementById('main_branch_id')?.value;
        const pricingBadge = document.getElementById('pricing_info_badge');
        
        if (!agentId && !branchId) {
            pricingBadge?.classList.add('d-none');
            return;
        }

        try {
            const res = await fetch(`ajax_family_visit.php?action=get_service_price&agent_id=${agentId}&branch_id=${branchId}`);
            const result = await res.json();
            
            if (result.status === 'success') {
                const data = result.data;
                const purchasePrice = data.purchase_price;
                const salePrice = data.sale_price;
                const currencySymbol = data.currency_symbol;
                const currencyId = data.currency_id;

                // تحديث المدخلات في نموذج الإدخال
                if (entryPurchase) entryPurchase.value = purchasePrice;
                if (entrySale) entrySale.value = salePrice;
                if (currencyId) {
                    const $mainCurrency = $('#main_currency_id');
                    const $saleCurrency = $('#sale_currency_id');
                    if ($mainCurrency.length) $mainCurrency.val(String(currencyId)).trigger('change');
                    if ($saleCurrency.length) $saleCurrency.val(String(currencyId)).trigger('change');
                }

                // تحديث جميع المدخلات الحالية في الجدول (إذا رغب المستخدم في تطبيق السعر على الكل)
                // ملاحظة: هذا السلوك يعتمد على رغبة المستخدم، سنتركه لزر "تنزيل السعر" فقط لتجنب تغيير أسعار تم إدخالها يدوياً فجأة

                // إظهار بادج التسعيرة
                if (pricingBadge) {
                    pricingBadge.classList.remove('d-none');
                    document.getElementById('target_purchase_label').innerText = purchasePrice.toFixed(2);
                    document.getElementById('target_sale_label').innerText = salePrice.toFixed(2);
                    document.getElementById('target_currency_label').innerText = currencySymbol;
                }
                
                // تحديث التيمبلت ليكون السعر الافتراضي للأسطر الجديدة
                const purchaseInputTpl = template.content.querySelector('.purchase-price-input');
                const saleInputTpl = template.content.querySelector('.sale-price-input');
                if (purchaseInputTpl) purchaseInputTpl.value = purchasePrice;
                if (saleInputTpl) saleInputTpl.value = salePrice;
            }
        } catch (err) {
            console.error('Error loading prices:', err);
        }
    }

    // زر تنزيل السعر على جميع الأفراد
    const applyPriceBtn = document.getElementById('applyDefaultPriceBtn');
    if (applyPriceBtn) {
        applyPriceBtn.onclick = function() {
            const agentId = document.getElementById('main_agent_id')?.value;
            const branchId = document.getElementById('main_branch_id')?.value;
            
            if (!agentId && !branchId) {
                alert('يرجى اختيار الوكيل أو الفرع أولاً');
                return;
            }
            
            updateServicePrices();
        };
    }

    // جلب الأسعار تلقائياً عند تغيير الوكيل أو الفرع
    const agentSelect = document.querySelector('select[name="agent_id"]');
    const branchSelect = document.querySelector('select[name="branch_id"]');
    
    async function loadServicePrices() {
        const agentId = agentSelect ? agentSelect.value : '';
        const branchId = branchSelect ? branchSelect.value : '';
        
        try {
            const res = await fetch(`ajax_family_visit.php?action=get_service_price&agent_id=${agentId}&branch_id=${branchId}`);
            const result = await res.json();
            
            if (result.status === 'success') {
                const data = result.data || {};
                const purchasePrice = parseFloat(data.purchase_price ?? 0) || 0;
                const salePrice = parseFloat(data.sale_price ?? 0) || 0;
                if (entryPurchase) entryPurchase.value = purchasePrice.toFixed(2);
                if (entrySale) entrySale.value = salePrice.toFixed(2);

                const purchaseInputTpl = template.content.querySelector('.purchase-price-input');
                const saleInputTpl = template.content.querySelector('.sale-price-input');
                if (purchaseInputTpl) purchaseInputTpl.value = purchasePrice.toFixed(2);
                if (saleInputTpl) saleInputTpl.value = salePrice.toFixed(2);
            }
        } catch (err) { console.error('Error loading prices:', err); }
    }

    if (agentSelect) agentSelect.addEventListener('change', loadServicePrices);
    if (branchSelect) branchSelect.addEventListener('change', loadServicePrices);

    // تنفيذ أولي لجلب الأسعار إذا كان هناك وكيل أو فرع مختار مسبقاً
    if ((agentSelect && agentSelect.value) || (branchSelect && branchSelect.value)) {
        loadServicePrices();
    }

    async function updateRequirements(row) {
        if (!row) {
            return;
        }

        const relInput = row.querySelector('.input-relationship');
        const genderInput = row.querySelector('.input-gender');
        const ageInput = row.querySelector('.input-age');
        const reqRow = row.nextElementSibling;
        const reqList = reqRow ? reqRow.querySelector('.requirements-list') : null;

        if (!reqList) {
            return;
        }

        const relId = relInput ? relInput.value : '';
        const gender = genderInput ? genderInput.value : '';
        const age = ageInput ? ageInput.value : '';

        if (!relId) {
            reqList.innerHTML = '---';
            return;
        }

        try {
            const res = await fetch(`ajax_family_visit.php?action=get_requirements&relationship_id=${encodeURIComponent(relId)}&gender=${encodeURIComponent(gender)}&age=${encodeURIComponent(age)}`);
            const payload = await res.json();
            const data = Array.isArray(payload) ? payload : (payload.data || []);
            if (Array.isArray(data) && data.length > 0) {
                reqList.innerHTML = data.map(req => `
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="checkbox" checked disabled>
                        <label class="form-check-label x-small ${req.is_mandatory ? 'fw-bold text-dark' : ''}">
                            ${req.requirement_name} ${req.is_mandatory ? '<span class="text-danger">*</span>' : ''}
                        </label>
                    </div>
                `).join('');
            } else {
                reqList.innerHTML = '<span class="x-small">لا توجد متطلبات خاصة</span>';
            }
        } catch (err) {
            console.error('Error loading requirements:', err);
        }
    }

    // عرض تفاصيل الطلب
    document.querySelectorAll('.view-request').forEach(btn => {
        btn.onclick = async function() {
            const id = this.dataset.id;
            const modal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
            modal.show();
            
            const content = document.getElementById('viewRequestContent');
            content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info"></div></div>';

            try {
                const res = await fetch(`ajax_family_visit.php?action=get_request_details&id=${id}`);
                const result = await res.json();
                
                if (result.status === 'success') {
                    const req = result.data;
                    const formatDateParts = (value) => {
                        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((value || '').trim());
                        if (!m) return null;
                        return { y: parseInt(m[1], 10), m: parseInt(m[2], 10), d: parseInt(m[3], 10) };
                    };
                    const formatDateArabic = (value) => {
                        const d = formatDateParts(value);
                        return d ? `${d.y}/${String(d.m).padStart(2, '0')}/${String(d.d).padStart(2, '0')}` : (value || '---');
                    };
                    const displayIssueDate = (() => {
                        if ((req.date_type || 'gregorian') !== 'hijri') {
                            return formatDateArabic(req.issue_date);
                        }
                        const g = formatDateParts(req.issue_date);
                        if (!g) return req.issue_date || '---';
                        const jd = gregorianToJD(g.y, g.m, g.d);
                        const h = jdToHijri(jd);
                        return `${h.year}/${String(h.month).padStart(2, '0')}/${String(h.day).padStart(2, '0')} هجري`;
                    })();
                    content.innerHTML = `
                        <div class="row g-4 text-end" dir="rtl">
                            <!-- بيانات الطلب -->
                            <div class="col-md-4 border-start">
                                <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fas fa-file-alt me-1 text-info"></i> بيانات المستند</h6>
                                <div class="mb-2"><span class="text-muted small">رقم المستند:</span> <span class="fw-bold">${req.document_no}</span></div>
                                <div class="mb-2"><span class="text-muted small">تاريخ الإصدار:</span> <span>${displayIssueDate}</span></div>
                                <div class="mb-2"><span class="text-muted small">صاحب الطلب:</span> <span class="fw-bold">${req.owner_name}</span></div>
                                <div class="mb-2"><span class="text-muted small">رقم السجل:</span> <span>${req.owner_id_no}</span></div>
                                <div class="mb-2"><span class="text-muted small">الجوال:</span> <span>${req.phone_no || '---'}</span></div>
                                <div class="mb-2"><span class="text-muted small">الحالة الحالية:</span> 
                                    <span class="badge rounded-pill" style="background-color: ${req.status_color}; color: #fff;">${req.status_name}</span>
                                </div>
                                <hr>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">تغيير حالة الطلب بالكامل:</label>
                                    <select class="form-select form-select-sm rounded-3 update-request-status" data-id="${req.id}">
                                        ${<?php echo json_encode($statuses); ?>.map(s => `
                                            <option value="${s.id}" ${s.id == req.status_id ? 'selected' : ''}>${s.status_name}</option>
                                        `).join('')}
                                    </select>
                                </div>
                                <hr>
                                <div class="mb-3 bg-light p-2 rounded-3">
                                    <h6 class="fw-bold small mb-2"><i class="fas fa-passport me-1"></i> بيانات التأشيرة (للطلب)</h6>
                                    <div class="mb-2">
                                        <label class="small text-muted">رقم التأشيرة:</label>
                                        <input type="text" class="form-control form-control-sm visa-no-input" value="${req.visa_no || ''}" data-id="${req.id}">
                                    </div>
                                    <div class="mb-2">
                                        <label class="small text-muted">مدة التأشيرة (يوم):</label>
                                        <input type="number" class="form-control form-control-sm visa-duration-input" value="${req.visa_duration || 30}" data-id="${req.id}">
                                    </div>
                                    <button class="btn btn-sm btn-info text-white w-100 save-visa-info" data-id="${req.id}">حفظ بيانات التأشيرة</button>
                                </div>
                                <div class="d-grid gap-2">
                                    ${req.iqama_image ? `<a href="../assets/uploads/family_visits/${req.iqama_image}" target="_blank" class="btn btn-sm btn-outline-info"><i class="fas fa-image me-1"></i> عرض صورة الإقامة</a>` : ''}
                                    ${req.document_pdf ? `<a href="../assets/uploads/family_visits/${req.document_pdf}" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-file-pdf me-1"></i> عرض مستند الزيارة</a>` : ''}
                                </div>
                            </div>
                            
                            <!-- بيانات الأفراد -->
                            <div class="col-md-8">
                                <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fas fa-user-friends me-1 text-info"></i> بيانات الأفراد (${req.individuals.length})</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle">
                                        <thead class="bg-light x-small">
                                            <tr>
                                                <th>الاسم</th>
                                                <th>الجواز</th>
                                                <th>الصلة</th>
                                                <th>جهة القدوم</th>
                                                <th>الوثائق المستلمة</th>
                                                <th>الحالة</th>
                                                <th>سعر الشراء</th>
                                                <th>سعر البيع</th>
                                                <th class="text-center">إجراء</th>
                                            </tr>
                                        </thead>
                                        <tbody class="small">
                                            ${req.individuals.map(ind => `
                                                <tr>
                                                    <td class="fw-bold">${ind.full_name}</td>
                                                    <td>${ind.passport_no}</td>
                                                    <td>${ind.relationship_name}</td>
                                                    <td>${ind.coming_from_city_name || '---'}</td>
                                                    <td>${(ind.received_documents || '').trim() || '---'}</td>
                                                    <td><span class="badge bg-light text-dark border">${ind.individual_status}</span></td>
                                                    <td class="text-primary fw-bold">${parseFloat(ind.purchase_price || ind.agent_price).toFixed(2)}</td>
                                                    <td class="text-success fw-bold">${parseFloat(ind.sale_price).toFixed(2)}</td>
                                                    <td class="text-center">
                                                        <button class="btn btn-sm btn-link p-0 update-ind-status" data-id="${ind.id}"><i class="fas fa-sync-alt"></i></button>
                                                    </td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3 p-3 bg-light rounded-3 small">
                                    <div class="fw-bold mb-1 text-muted">ملاحظات:</div>
                                    <div>${req.notes || 'لا توجد ملاحظات'}</div>
                                </div>
                            </div>
                        </div>
                    `;
                }
            } catch (err) {
                content.innerHTML = '<div class="alert alert-danger">خطأ في تحميل البيانات.</div>';
            }
        };
    });

    // زر التعديل (فتح مودال التفاصيل حالياً كحل مؤقت للمراجعة والتعديل)
    document.querySelectorAll('.edit-request').forEach(btn => {
        btn.onclick = function() {
            const id = this.dataset.id;
            // توجيه لزر العرض لأن واجهة العرض تدعم التعديل (الحالة، التأشيرة)
            const viewBtn = document.querySelector(`.view-request[data-id="${id}"]`);
            if (viewBtn) viewBtn.click();
        };
    });

    // تحديث حالة الطلب عبر AJAX
    document.addEventListener('change', async function(e) {
        if (e.target.classList.contains('update-request-status')) {
            const id = e.target.dataset.id;
            const statusId = e.target.value;
            if (confirm('هل تريد تغيير حالة الطلب وكافة الأفراد التابعين له؟')) {
                try {
                    const body = new URLSearchParams({ id, status_id: statusId, csrf_token: CSRF_TOKEN });
                    const res = await fetch('ajax_family_visit.php?action=update_request_status', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body
                    });
                    const result = await res.json();
                    if (result.status === 'success') location.reload();
                    else alert(result.message);
                } catch (err) { alert('خطأ في الاتصال بالسيرفر'); }
            }
        }
    });
    // حفظ بيانات التأشيرة
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('save-visa-info')) {
            const id = e.target.dataset.id;
            const visaNo = document.querySelector('.visa-no-input').value;
            const duration = document.querySelector('.visa-duration-input').value;
            
            try {
                const body = new URLSearchParams({ id, visa_no: visaNo, duration, csrf_token: CSRF_TOKEN });
                const res = await fetch('ajax_family_visit.php?action=update_visa_info', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const result = await res.json();
                if (result.status === 'success') {
                    alert('تم حفظ بيانات التأشيرة بنجاح');
                    location.reload();
                } else {
                    alert(result.message);
                }
            } catch (err) { alert('خطأ في الاتصال بالسيرفر'); }
        }
    });

    function toggleActionMenu(menuId) {
        const target = document.getElementById(menuId);
        if (!target) return;
        const willShow = !target.classList.contains('show');
        document.querySelectorAll('.financial-action-menu.show').forEach(menu => menu.classList.remove('show'));
        if (willShow) target.classList.add('show');
    }

    function closeActionMenus() {
        document.querySelectorAll('.financial-action-menu.show').forEach(menu => menu.classList.remove('show'));
    }

    async function confirmDialog({ title, text, icon, confirmText, cancelText }) {
        const isDark = document.body.classList.contains('theme-dark');
        if (window.Swal && typeof window.Swal.fire === 'function') {
            const res = await window.Swal.fire({
                title,
                text,
                icon: icon || 'question',
                showCancelButton: true,
                confirmButtonText: confirmText || 'تأكيد',
                cancelButtonText: cancelText || 'إلغاء',
                reverseButtons: true,
                background: isDark ? '#0b1220' : '#ffffff',
                color: isDark ? '#e2e8f0' : '#0f172a',
                confirmButtonColor: isDark ? '#2563eb' : undefined,
                cancelButtonColor: isDark ? '#475569' : undefined
            });
            return !!res.isConfirmed;
        }
        return window.confirm(text || title || '');
    }

    async function postFinance(id, scope) {
        closeActionMenus();
        const ok = await confirmDialog({
            title: 'تأكيد الترحيل المالي',
            text: 'هل تريد ترحيل الفاتورة/الفواتير المحددة؟',
            icon: 'warning',
            confirmText: 'نعم، رحّل',
            cancelText: 'تراجع'
        });
        if (!ok) return;
        try {
            const body = new URLSearchParams({ id: String(id), scope: String(scope || 'all'), csrf_token: CSRF_TOKEN });
            const res = await fetch('ajax_family_visit.php?action=post_finance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            const result = await res.json();
            if (result.status === 'success') {
                if (window.Swal) {
                    await window.Swal.fire({ icon: 'success', title: 'تم', text: result.message || 'تم الترحيل بنجاح' });
                }
                location.reload();
                return;
            }
            if (window.Swal) {
                await window.Swal.fire({ icon: 'error', title: 'خطأ', text: result.message || 'فشل الترحيل' });
            } else {
                alert(result.message || 'فشل الترحيل');
            }
        } catch (err) {
            alert('خطأ في الاتصال بالسيرفر');
        }
    }

    async function unpostFinance(id, scope) {
        closeActionMenus();
        const ok = await confirmDialog({
            title: 'تأكيد إلغاء الترحيل',
            text: 'سيتم إرجاع الفاتورة/الفواتير إلى مسودة. هل تريد المتابعة؟',
            icon: 'warning',
            confirmText: 'نعم، ألغِ الترحيل',
            cancelText: 'تراجع'
        });
        if (!ok) return;
        try {
            const body = new URLSearchParams({ id: String(id), scope: String(scope || 'all'), csrf_token: CSRF_TOKEN });
            const res = await fetch('ajax_family_visit.php?action=unpost_finance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            const result = await res.json();
            if (result.status === 'success') {
                if (window.Swal) {
                    await window.Swal.fire({ icon: 'success', title: 'تم', text: result.message || 'تم إلغاء الترحيل بنجاح' });
                }
                location.reload();
                return;
            }
            if (window.Swal) {
                await window.Swal.fire({ icon: 'error', title: 'خطأ', text: result.message || 'فشل إلغاء الترحيل' });
            } else {
                alert(result.message || 'فشل إلغاء الترحيل');
            }
        } catch (err) {
            alert('خطأ في الاتصال بالسيرفر');
        }
    }

    async function cancelInvoices(id, scope) {
        closeActionMenus();
        const ok = await confirmDialog({
            title: 'تأكيد حذف الفواتير',
            text: 'سيتم تحويل الفاتورة/الفواتير إلى ملغي وفصلها عن الطلب. لا يمكن حذف فاتورة مُرحلة.',
            icon: 'warning',
            confirmText: 'نعم، احذف',
            cancelText: 'تراجع'
        });
        if (!ok) return;
        try {
            const body = new URLSearchParams({ id: String(id), scope: String(scope || 'all'), csrf_token: CSRF_TOKEN });
            const res = await fetch('ajax_family_visit.php?action=cancel_invoices', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            });
            const result = await res.json();
            if (result.status === 'success') {
                if (window.Swal) {
                    await window.Swal.fire({ icon: 'success', title: 'تم', text: result.message || 'تم حذف الفواتير بنجاح' });
                }
                location.reload();
                return;
            }
            if (window.Swal) {
                await window.Swal.fire({ icon: 'error', title: 'خطأ', text: result.message || 'فشل حذف الفواتير' });
            } else {
                alert(result.message || 'فشل حذف الفواتير');
            }
        } catch (err) {
            alert('خطأ في الاتصال بالسيرفر');
        }
    }

    window.FamilyVisit = {
        postFinance,
        unpostFinance,
        cancelInvoices
    };

    document.addEventListener('click', function(e) {
        const menuToggleBtn = e.target.closest('[data-action-menu-toggle]');
        if (menuToggleBtn) {
            e.preventDefault();
            e.stopPropagation();
            toggleActionMenu(menuToggleBtn.dataset.actionMenuToggle);
            return;
        }
        const deleteLink = e.target.closest('.delete-family-request');
        if (deleteLink) {
            e.preventDefault();
            e.stopPropagation();
            closeActionMenus();
            (async () => {
                const ok = await confirmDialog({
                    title: 'تأكيد حذف الطلب',
                    text: 'سيتم حذف الطلب وكافة الأفراد التابعين له. هل تريد المتابعة؟',
                    icon: 'warning',
                    confirmText: 'نعم، احذف',
                    cancelText: 'تراجع'
                });
                if (ok) {
                    window.location.href = deleteLink.getAttribute('href');
                }
            })();
            return;
        }
        if (!e.target.closest('.financial-action-group')) {
            closeActionMenus();
        }
    });
    // تفعيل منطق الدفع عند التحميل
    if (typeof window.updatePaymentLogic === 'function') {
        window.updatePaymentLogic();
    }
});
</script>

<style>
    .section-title { font-size: 1rem; font-weight: 700; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 0.5rem; }
    .x-small { font-size: 0.75rem; }
    .table-sm td, .table-sm th { padding: 0.3rem; }
    .clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .finance-mini-card {
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 14px;
        padding: 10px 12px;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
    }

    body.theme-dark .finance-mini-card {
        background: rgba(17, 24, 39, 0.9);
        border-color: rgba(148, 163, 184, 0.18);
    }

    .finance-mini-card .mini-label {
        font-size: 0.7rem;
        font-weight: 800;
        color: #64748b;
        margin-bottom: 2px;
    }

    body.theme-dark .finance-mini-card .mini-label {
        color: #94a3b8;
    }

    .finance-mini-card .mini-name {
        font-size: 0.78rem;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 6px;
    }

    body.theme-dark .finance-mini-card .mini-name {
        color: #e2e8f0;
    }

    .finance-mini-card .mini-amount {
        font-size: 0.9rem;
        font-weight: 900;
        letter-spacing: 0.2px;
    }

    .payment-stack {
        display: grid;
        gap: 0.45rem;
        min-width: 160px;
    }

    .payment-box {
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 14px;
        padding: 10px 12px;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
    }

    body.theme-dark .payment-box {
        background: rgba(17, 24, 39, 0.9);
        border-color: rgba(148, 163, 184, 0.18);
    }

    .payment-box-title {
        font-size: 0.72rem;
        font-weight: 900;
        margin-bottom: 6px;
    }

    table.align-middle > :not(caption) > * > td.fv-raise {
        vertical-align: top !important;
        padding-top: 0.95rem;
        padding-bottom: 0.95rem;
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
        font-weight: 800;
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
        font-weight: 800;
        color: var(--text-muted);
        padding: 0.1rem 0.35rem 0.25rem;
    }

    .financial-action-menu-item {
        border: 0;
        border-radius: 0.85rem;
        padding: 0.5rem 0.7rem;
        font-size: 0.74rem;
        font-weight: 800;
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

    .financial-action-menu-item.delete-option {
        color: #b91c1c;
        background: rgba(239, 68, 68, 0.10);
        text-decoration: none;
    }

    body.theme-dark .financial-action-menu-item.delete-option {
        color: #fecaca;
        background: rgba(239, 68, 68, 0.16);
    }

    .financial-action-menu-item.disabled {
        opacity: 0.55;
        cursor: not-allowed;
        pointer-events: none;
    }

    body.theme-dark .financial-action-menu {
        background: rgba(15, 23, 42, 0.98);
        border-color: rgba(148, 163, 184, 0.22);
    }

    body.theme-dark .financial-action-menu-title {
        color: #cbd5e1;
    }

    body.theme-dark .financial-action-menu-item {
        color: #e2e8f0;
    }

    body.theme-dark .financial-action-menu-item:hover:not(.disabled) {
        background: rgba(59, 130, 246, 0.14);
    }

    /* تحسينات التصميم والوضع الليلي للبطاقات */
    .transition-all { transition: all 0.3s ease; }
    .ring-2 { box-shadow: 0 0 0 2px var(--primary-color); }
    .custom-scrollbar::-webkit-scrollbar { height: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 4px; }
    
    .mini-card {
        min-width: 180px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid rgba(0,0,0,0.05) !important;
        background: #fff;
    }
    .mini-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important;
    }
    .stat-label { font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 2px; }
    .stat-value { font-size: 1.5rem; font-weight: 800; line-height: 1; }
    .sub-stat { font-size: 0.65rem; color: #94a3b8; }
    .sub-stat-value { font-weight: 700; color: #1e293b; }
    
    body.theme-dark .stat-label { color: #94a3b8; }
    body.theme-dark .sub-stat-value { color: #e2e8f0; }
    body.theme-dark .mini-card { background: #111827 !important; border-color: #1e2d45 !important; }

    .hijri-picker {
        position: absolute;
        top: calc(100% + 6px);
        inset-inline-start: 0;
        width: min(340px, 92vw);
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.12);
        border-radius: 14px;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        z-index: 1080;
        padding: 10px;
        direction: rtl;
    }

    body.theme-dark .hijri-picker {
        background: #0b1220;
        border-color: rgba(148, 163, 184, 0.18);
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.5);
    }

    .hijri-picker-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
    }

    .hijri-picker-header .btn {
        border-radius: 10px;
        padding: 6px 10px;
        font-size: 0.85rem;
    }

    .hijri-picker-selects {
        display: grid;
        grid-template-columns: 1.2fr 0.8fr;
        gap: 8px;
        flex: 1;
    }

    .hijri-picker select {
        border-radius: 10px;
        padding: 6px 10px;
        font-size: 0.9rem;
        border: 1px solid rgba(15, 23, 42, 0.12);
        background: rgba(248, 250, 252, 0.95);
        color: #0f172a;
    }

    body.theme-dark .hijri-picker select {
        border-color: rgba(148, 163, 184, 0.18);
        background: rgba(30, 41, 59, 0.7);
        color: #e2e8f0;
    }

    .hijri-week {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 6px;
        margin-bottom: 6px;
        font-size: 0.75rem;
        color: #64748b;
        text-align: center;
        font-weight: 700;
    }

    body.theme-dark .hijri-week {
        color: #94a3b8;
    }

    .hijri-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 6px;
    }

    .hijri-day {
        width: 100%;
        aspect-ratio: 1 / 1;
        border-radius: 12px;
        border: 1px solid rgba(15, 23, 42, 0.08);
        background: rgba(248, 250, 252, 0.95);
        color: #0f172a;
        font-weight: 800;
        font-size: 0.85rem;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        user-select: none;
    }

    .hijri-day:hover {
        border-color: rgba(59, 130, 246, 0.55);
        background: rgba(59, 130, 246, 0.08);
    }

    .hijri-day.is-today {
        border-color: rgba(16, 185, 129, 0.6);
        background: rgba(16, 185, 129, 0.12);
        color: #0f766e;
    }

    .hijri-day.is-selected {
        border-color: rgba(37, 99, 235, 0.75);
        background: rgba(37, 99, 235, 0.16);
        color: #1d4ed8;
    }

    body.theme-dark .hijri-day {
        border-color: rgba(148, 163, 184, 0.16);
        background: rgba(30, 41, 59, 0.7);
        color: #e2e8f0;
    }

    body.theme-dark .hijri-day:hover {
        border-color: rgba(96, 165, 250, 0.55);
        background: rgba(96, 165, 250, 0.12);
    }

    body.theme-dark .hijri-day.is-selected {
        border-color: rgba(96, 165, 250, 0.8);
        background: rgba(96, 165, 250, 0.18);
        color: #bfdbfe;
    }

    .hijri-day.is-empty {
        visibility: hidden;
        pointer-events: none;
    }
</style>

<?php require_once 'footer.php'; ?>
