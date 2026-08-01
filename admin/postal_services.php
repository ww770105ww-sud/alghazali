<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$page_title = 'إدارة خدمات البريد';
$permission_prefix = 'family_visit';

if (!has_permission($permission_prefix . '_view')) {
    header('Location: index.php?error=no_permission');
    exit();
}

// Check if postal services are enabled
$settings = getSettings($pdo);
if (!get_module_status($pdo, 'enable_postal_services') && $_SESSION['role'] !== 'developer') {
    die("<div style='text-align:center; padding:50px; font-family:Tahoma;'><h3>عذراً، خدمات البريد معطلة حالياً من قبل الإدارة.</h3><a href='index.php'>العودة للرئيسية</a></div>");
}

require_once 'header.php';
?>
<style>
    .postal-info-card,
    .finance-mini-card {
        border-radius: 18px;
        padding: 0.75rem 0.85rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(248, 250, 252, 0.86);
        min-width: 165px;
    }

    body.theme-dark .postal-info-card,
    body.theme-dark .finance-mini-card,
    body.dark-mode .postal-info-card,
    body.dark-mode .finance-mini-card {
        background: rgba(30, 41, 59, 0.85);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .postal-info-card .mini-label,
    .finance-mini-card .mini-label {
        font-size: 0.72rem;
        color: #64748b;
        margin-bottom: 0.18rem;
    }

    body.theme-dark .postal-info-card .mini-label,
    body.theme-dark .finance-mini-card .mini-label,
    body.dark-mode .postal-info-card .mini-label,
    body.dark-mode .finance-mini-card .mini-label {
        color: #94a3b8;
    }

    .postal-info-card .mini-name,
    .finance-mini-card .mini-name {
        font-size: 0.9rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.5;
        margin-bottom: 0.3rem;
    }

    body.theme-dark .postal-info-card .mini-name,
    body.theme-dark .finance-mini-card .mini-name,
    body.dark-mode .postal-info-card .mini-name,
    body.dark-mode .finance-mini-card .mini-name {
        color: #e2e8f0;
    }

    .finance-mini-card .mini-amount {
        font-size: 1rem;
        font-weight: 900;
    }

    .postal-meta {
        font-size: 0.78rem;
        color: #64748b;
    }

    body.theme-dark .postal-meta,
    body.dark-mode .postal-meta {
        color: #94a3b8;
    }

    .payment-stack {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        min-width: 180px;
    }

    .payment-box {
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(248, 250, 252, 0.78);
        padding: 0.65rem 0.75rem;
    }

    body.theme-dark .payment-box,
    body.dark-mode .payment-box {
        background: rgba(30, 41, 59, 0.78);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .payment-box-title {
        font-size: 0.75rem;
        font-weight: 800;
        margin-bottom: 0.4rem;
    }

    .payment-stack .badge {
        font-size: 0.73rem;
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

    body.theme-dark .financial-action-btn.fin-post,
    body.dark-mode .financial-action-btn.fin-post {
        background: rgba(34, 197, 94, 0.18);
        color: #86efac;
    }

    body.theme-dark .financial-action-btn.fin-unpost,
    body.dark-mode .financial-action-btn.fin-unpost {
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
        background: #fff;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 1rem;
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.16);
        padding: 0.45rem;
        display: none;
        flex-direction: column;
        gap: 0.35rem;
        z-index: 30;
    }

    body.theme-dark .financial-action-menu,
    body.dark-mode .financial-action-menu {
        background: #0f172a;
        border-color: rgba(148, 163, 184, 0.16);
    }

    .financial-action-menu.show {
        display: flex;
    }

    .financial-action-menu-title {
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
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
        color: inherit;
        width: 100%;
        transition: all 0.16s ease;
        text-decoration: none;
    }

    .financial-action-menu-item:hover:not(.disabled) {
        background: rgba(59, 130, 246, 0.08);
    }

    .financial-action-menu-item.post-option {
        color: #15803d;
    }

    .financial-action-menu-item.unpost-option {
        color: #b45309;
    }

    .financial-action-menu-item.manage-option {
        color: #1d4ed8;
    }

    .financial-action-menu-item.delete-option {
        color: #dc2626;
    }

    .financial-action-menu-item.disabled {
        opacity: 0.45;
        cursor: not-allowed;
        pointer-events: none;
    }

    @media (max-width: 991.98px) {
        .payment-stack {
            grid-template-columns: 1fr;
        }
    }

    /* Modal styling improvements */
    .modal-dialog-scrollable .modal-content {
        max-height: calc(100vh - 3.5rem);
    }
    
    .modal-dialog-scrollable .modal-body {
        overflow-y: auto;
        max-height: calc(100vh - 200px);
    }
    
    .modal-footer {
        background: linear-gradient(to bottom, rgba(255,255,255,0) 0%, rgba(255,255,255,1) 20%);
        border-top: 1px solid rgba(0,0,0,0.05);
        position: sticky;
        bottom: 0;
        z-index: 10;
    }
    
    body.theme-dark .modal-footer,
    body.dark-mode .modal-footer {
        background: linear-gradient(to bottom, rgba(15,23,42,0) 0%, rgba(15,23,42,1) 20%);
        border-top: 1px solid rgba(255,255,255,0.05);
    }
    
    /* Field grouping styling */
    .field-section {
        background: rgba(248,250,252,0.5);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid rgba(148,163,184,0.1);
    }
    
    body.theme-dark .field-section,
    body.dark-mode .field-section {
        background: rgba(30,41,59,0.3);
        border-color: rgba(148,163,184,0.1);
    }
</style>
<?php
$settings = getSettings($pdo);
$entityFilter = get_entity_filter('ps', 'branch_id', 'agent_id', null, 'created_by');
$search = trim((string)($_GET['search'] ?? ''));
$whereClauses = ['ps.deleted_at IS NULL', $entityFilter['clause']];
$params = $entityFilter['params'];

if (!function_exists('generate_next_postal_tracking_number')) {
    function generate_next_postal_tracking_number(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query("
                SELECT COALESCE(MAX(id), 0) + 1
                FROM postal_shipments
            ");
            $nextNumber = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $nextNumber = 1;
        }

        return 'POST-' . str_pad((string)max(1, $nextNumber), 6, '0', STR_PAD_LEFT);
    }
}

if ($search !== '') {
    $whereClauses[] = "(ps.tracking_number LIKE ? OR ps.shipment_name LIKE ? OR ps.sender_full_name LIKE ? OR ps.recipient_full_name LIKE ?)";
    for ($i = 0; $i < 4; $i++) {
        $params[] = '%' . $search . '%';
    }
}

if (isset($_GET['delete_id']) && has_permission($permission_prefix . '_delete')) {
    $deleteId = (int)$_GET['delete_id'];
    $stmtRow = $pdo->prepare("
        SELECT id, sales_invoice_id, purchase_invoice_id
        FROM postal_shipments
        WHERE id = ? AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmtRow->execute([$deleteId]);
    $shipmentRow = $stmtRow->fetch(PDO::FETCH_ASSOC);

    if ($shipmentRow) {
        $invoiceIds = [];
        foreach (['sales_invoice_id', 'purchase_invoice_id'] as $col) {
            if (!empty($shipmentRow[$col])) {
                $invoiceIds[] = (int)$shipmentRow[$col];
            }
        }

        if (empty($invoiceIds)) {
            $stmtInv = $pdo->prepare("
                SELECT id
                FROM invoices
                WHERE source_type IN ('خدمات البريد', 'postal')
                  AND source_id = ?
                  AND invoice_status <> 'cancelled'
            ");
            $stmtInv->execute([$deleteId]);
            $invoiceIds = array_map('intval', $stmtInv->fetchAll(PDO::FETCH_COLUMN));
        }

        $postedFound = false;
        if (!empty($invoiceIds)) {
            $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
            $stmtPosted = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE id IN ($placeholders) AND invoice_status = 'posted'");
            $stmtPosted->execute($invoiceIds);
            $postedFound = (int)$stmtPosted->fetchColumn() > 0;
        }

        if ($postedFound) {
            $_SESSION['error'] = 'لا يمكن حذف الشحنة لأن عليها فاتورة أو فواتير مُرحلة.';
        } else {
            $stmtDelete = $pdo->prepare("UPDATE postal_shipments SET deleted_at = NOW() WHERE id = ?");
            $stmtDelete->execute([$deleteId]);
            $_SESSION['success'] = 'تم حذف الشحنة البريدية بنجاح.';
        }
    }

    header('Location: postal_services.php');
    exit();
}

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
$shipmentsStmt = $pdo->prepare("
    SELECT ps.*,
           ag.agent_name,
           br.branch_name,
           sender_city.city_name AS sender_city_name,
           recipient_city.city_name AS recipient_city_name,
           sale_inv.id AS sale_invoice_id,
           sale_inv.invoice_number AS sale_invoice_number,
           sale_inv.net_amount AS sale_total_amount,
           sale_inv.amount_received AS sale_received_amount,
           sale_inv.payment_status AS sale_payment_status,
           sale_inv.invoice_status AS sale_invoice_status,
           purchase_inv.id AS purchase_invoice_id,
           purchase_inv.invoice_number AS purchase_invoice_number,
           purchase_inv.net_amount AS purchase_total_amount,
           purchase_inv.payment_status AS purchase_payment_status,
           purchase_inv.invoice_status AS purchase_invoice_status,
           sup.supplier_name AS supplier_name,
           sale_acc.account_code AS sale_account_code,
           sale_acc.account_name_ar AS sale_account_name,
           curr.currency_symbol
    FROM postal_shipments ps
    LEFT JOIN agents ag ON ag.id = ps.agent_id
    LEFT JOIN branches br ON br.id = ps.branch_id
    LEFT JOIN cities sender_city ON sender_city.id = ps.sender_city_id
    LEFT JOIN cities recipient_city ON recipient_city.id = ps.recipient_city_id
    LEFT JOIN invoices sale_inv ON sale_inv.id = COALESCE(
        ps.sales_invoice_id,
        (
            SELECT i.id
            FROM invoices i
            WHERE i.source_type IN ('خدمات البريد', 'postal')
              AND i.source_id = ps.id
              AND i.invoice_category = 'sales'
              AND i.invoice_status <> 'cancelled'
            ORDER BY i.id DESC
            LIMIT 1
        )
    )
    LEFT JOIN invoices purchase_inv ON purchase_inv.id = COALESCE(
        ps.purchase_invoice_id,
        (
            SELECT i.id
            FROM invoices i
            WHERE i.source_type IN ('خدمات البريد', 'postal')
              AND i.source_id = ps.id
              AND i.invoice_category = 'purchase'
              AND i.invoice_status <> 'cancelled'
            ORDER BY i.id DESC
            LIMIT 1
        )
    )
    LEFT JOIN suppliers sup ON sup.id = purchase_inv.supplier_id
    LEFT JOIN unified_accounts sale_acc ON sale_acc.id = sale_inv.account_id
    LEFT JOIN currencies curr ON curr.id = COALESCE(sale_inv.currency_id, purchase_inv.currency_id)
    $whereSql
    ORDER BY ps.created_at DESC, ps.id DESC
");
$shipmentsStmt->execute($params);
$shipments = $shipmentsStmt->fetchAll(PDO::FETCH_ASSOC);

$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$postalServiceId = resolve_service_id($pdo, 'خدمات البريد');
if ($postalServiceId <= 0) {
    $postalServiceId = resolve_service_id($pdo, 'postal');
}

$postalPriceConfig = null;
if ($postalServiceId > 0) {
    try {
        $postalPriceConfig = get_service_price_config(
            $pdo,
            $postalServiceId,
            $_SESSION['agent_id'] ?? null,
            $_SESSION['branch_id'] ?? null,
            null,
            null
        );
    } catch (Throwable $e) {
        $postalPriceConfig = null;
    }
}

$postalCurrencyId = (int)($postalPriceConfig['currency_id'] ?? ($settings['base_currency_id'] ?? 1));
$current_invoice = [
    'invoice_date' => normalize_datetime_db(null),
    'branch_id' => $_SESSION['branch_id'] ?? null,
    'source_type' => 'خدمات البريد',
    'delivery_type' => $settings['default_delivery_type'] ?? 'draft',
    'record_purchase' => 1,
    'total_amount' => 0,
    'discount' => 0,
    'cost_amount' => 0,
    'amount_received' => 0,
    'sale_currency_id' => $postalCurrencyId,
    'currency_id' => $postalCurrencyId,
    'exchange_rate' => 1,
    'description' => ''
];
$financial_fields_select2_parent = '#addPostalShipmentModal';
$financial_fields_show_service_select = false;
$financial_fields_form_selector = '#addPostalShipmentForm';
$financial_fields_hide_service_accounts = true;

$successMessage = $_SESSION['success'] ?? null;
$errorMessage = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
$nextTrackingNumber = generate_next_postal_tracking_number($pdo);
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">خدمات البريد</h2>
            <div class="text-muted">إدارة الشحنات البريدية وربطها بالفواتير والحقول المالية الموحدة.</div>
        </div>
        <div class="d-flex gap-2">
            <form class="d-flex gap-2" method="GET">
                <input type="text" name="search" class="form-control rounded-pill" value="<?php echo htmlspecialchars($search); ?>" placeholder="بحث برقم التتبع أو المرسل أو المستلم">
                <button type="submit" class="btn btn-light rounded-pill px-4">بحث</button>
            </form>
            <?php if (has_permission($permission_prefix . '_add')): ?>
                <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addPostalShipmentModal">
                    <i class="fas fa-plus me-1"></i> إضافة شحنة
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success rounded-4 border-0 shadow-sm"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger rounded-4 border-0 shadow-sm"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>رقم التتبع</th>
                            <th>الرسالة / المحتوى</th>
                            <th>المستلم و المرسل</th>
                            <th>الموصل</th>
                            <th>الحساب والبيع</th>
                            <th>المورد والشراء</th>
                            <th>السداد والترحيل</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($shipments)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">لا توجد شحنات بريدية حتى الآن.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($shipments as $shipment): ?>
                            <?php
                            $currencyMark = trim((string)($shipment['currency_symbol'] ?? ''));
                            $saleStatus = (string)($shipment['sale_invoice_status'] ?? 'draft');
                            $purchaseStatus = (string)($shipment['purchase_invoice_status'] ?? 'draft');
                            $paymentStatus = (string)($shipment['sale_payment_status'] ?? 'unpaid');
                            $purchasePaymentStatus = (string)($shipment['purchase_payment_status'] ?? 'unpaid');
                            $hasSalesInvoice = !empty($shipment['sale_invoice_id']);
                            $hasPurchaseInvoice = !empty($shipment['purchase_invoice_id']);
                            $hasPostedInvoice = ($saleStatus === 'posted') || ($purchaseStatus === 'posted');
                            $hasDraftInvoice = ($saleStatus === 'draft') || ($purchaseStatus === 'draft');
                            $canModifyRow = in_array($saleStatus, ['draft', 'cancelled', ''], true)
                                && (!$hasPurchaseInvoice || in_array($purchaseStatus, ['draft', 'cancelled', ''], true));
                            $payBadges = [
                                'unpaid' => '<span class="badge bg-danger-subtle text-danger rounded-pill">غير مدفوع</span>',
                                'partial' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                'partially_paid' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                'paid' => '<span class="badge bg-success-subtle text-success rounded-pill">مدفوع بالكامل</span>',
                                'fully_paid' => '<span class="badge bg-success-subtle text-success rounded-pill">مدفوع بالكامل</span>',
                                'awaiting_approval' => '<span class="badge bg-info-subtle text-info rounded-pill">بانتظار الاعتماد</span>',
                                'posted' => '<span class="badge bg-primary-subtle text-primary rounded-pill">مرحل مالياً</span>'
                            ];
                            $invoiceBadges = [
                                'draft' => '<span class="badge bg-secondary-subtle text-secondary rounded-pill">مسودة</span>',
                                'posted' => '<span class="badge bg-primary-subtle text-primary rounded-pill">مرحل</span>',
                                'cancelled' => '<span class="badge bg-danger-subtle text-danger rounded-pill">ملغي</span>',
                                '' => '<span class="badge bg-light text-dark rounded-pill">---</span>'
                            ];
                            $shipmentPayload = htmlspecialchars(json_encode([
                                'id' => (int)$shipment['id'],
                                'tracking_number' => (string)($shipment['tracking_number'] ?? ''),
                                'shipment_name' => (string)($shipment['shipment_name'] ?? ''),
                                'content_description' => (string)($shipment['content_description'] ?? ''),
                                'expected_send_date' => (string)($shipment['expected_send_date'] ?? ''),
                                'sender_full_name' => (string)($shipment['sender_full_name'] ?? ''),
                                'sender_city_id' => $shipment['sender_city_id'] !== null ? (int)$shipment['sender_city_id'] : '',
                                'sender_city_name' => (string)($shipment['sender_city_name'] ?? ''),
                                'sender_phone' => (string)($shipment['sender_phone'] ?? ''),
                                'recipient_full_name' => (string)($shipment['recipient_full_name'] ?? ''),
                                'recipient_city_id' => $shipment['recipient_city_id'] !== null ? (int)$shipment['recipient_city_id'] : '',
                                'recipient_city_name' => (string)($shipment['recipient_city_name'] ?? ''),
                                'recipient_phone' => (string)($shipment['recipient_phone'] ?? ''),
                                'courier_name' => (string)($shipment['courier_name'] ?? ''),
                                'notes' => (string)($shipment['notes'] ?? ''),
                                'description' => (string)($shipment['description'] ?? ''),
                                'customer_id' => $shipment['customer_id'] !== null ? (int)$shipment['customer_id'] : '',
                                'supplier_id' => $shipment['supplier_id'] !== null ? (int)$shipment['supplier_id'] : '',
                                'agent_id' => $shipment['agent_id'] !== null ? (int)$shipment['agent_id'] : '',
                                'branch_id' => $shipment['branch_id'] !== null ? (int)$shipment['branch_id'] : '',
                                'sale_invoice_number' => (string)($shipment['sale_invoice_number'] ?? ''),
                                'purchase_invoice_number' => (string)($shipment['purchase_invoice_number'] ?? ''),
                                'sale_total_amount' => (float)($shipment['sale_total_amount'] ?? 0),
                                'purchase_total_amount' => (float)($shipment['purchase_total_amount'] ?? 0),
                                'currency_symbol' => $currencyMark,
                                'sale_payment_status' => $paymentStatus,
                                'sale_invoice_status' => $saleStatus,
                                'purchase_invoice_status' => $purchaseStatus,
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td>
                                    <div class="postal-info-card">
                                        <div class="mini-label">رقم التتبع</div>
                                        <div class="mini-name"><?php echo htmlspecialchars($shipment['tracking_number']); ?></div>
                                        <div class="mini-label">تاريخ الإرسال المتوقع</div>
                                        <div class="postal-meta"><?php echo !empty($shipment['expected_send_date']) ? htmlspecialchars($shipment['expected_send_date']) : '---'; ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="postal-info-card">
                                        <div class="mini-label">الرسالة</div>
                                        <div class="mini-name"><?php echo htmlspecialchars($shipment['shipment_name']); ?></div>
                                        <div class="mini-label">المحتوى</div>
                                        <div class="postal-meta"><?php echo htmlspecialchars($shipment['content_description'] ?: '---'); ?></div>
                                    </div>
                                </td>
                                <td class="small">
                                    <div class="finance-mini-card">
                                        <div class="mini-label text-success">المستلم</div>
                                        <div class="mini-name"><?php echo htmlspecialchars($shipment['recipient_full_name']); ?></div>
                                        <div class="postal-meta"><?php echo htmlspecialchars($shipment['recipient_city_name'] ?: '---'); ?></div>
                                        <div class="postal-meta"><?php echo htmlspecialchars($shipment['recipient_phone'] ?: '---'); ?></div>
                                        <hr class="my-2 opacity-25">
                                        <div class="mini-label text-primary">المرسل</div>
                                        <div class="mini-name"><?php echo htmlspecialchars($shipment['sender_full_name']); ?></div>
                                        <div class="postal-meta"><?php echo htmlspecialchars($shipment['sender_city_name'] ?: '---'); ?></div>
                                        <div class="postal-meta"><?php echo htmlspecialchars($shipment['sender_phone'] ?: '---'); ?></div>
                                    </div>
                                </td>
                                <td class="small">
                                    <div class="finance-mini-card">
                                        <div class="mini-label">الموصل</div>
                                        <div class="mini-name"><?php echo htmlspecialchars($shipment['courier_name'] ?: '---'); ?></div>
                                        <div class="mini-label">ملاحظة</div>
                                        <div class="postal-meta"><?php echo htmlspecialchars($shipment['notes'] ?: '---'); ?></div>
                                    </div>
                                </td>
                                <td class="small">
                                    <div class="finance-mini-card">
                                        <div class="mini-label">الحساب</div>
                                        <div class="mini-name">
                                            <?php
                                            $saleAccountText = trim((string)($shipment['sale_account_name'] ?? ''));
                                            echo htmlspecialchars($saleAccountText !== '' ? $saleAccountText : '---');
                                            ?>
                                        </div>
                                        <div class="mini-label">سعر البيع</div>
                                        <div class="mini-amount text-success">
                                            <?php echo number_format((float)($shipment['sale_total_amount'] ?? 0), 2); ?>
                                            <?php echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : ''; ?>
                                        </div>
                                        <div class="postal-meta mt-1"><?php echo htmlspecialchars($shipment['sale_invoice_number'] ?: '---'); ?></div>
                                    </div>
                                </td>
                                <td class="small">
                                    <div class="finance-mini-card">
                                        <div class="mini-label">المورد</div>
                                        <div class="mini-name"><?php echo htmlspecialchars($shipment['supplier_name'] ?: '---'); ?></div>
                                        <div class="mini-label">سعر الشراء</div>
                                        <div class="mini-amount text-primary">
                                            <?php echo number_format((float)($shipment['purchase_total_amount'] ?? 0), 2); ?>
                                            <?php echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : ''; ?>
                                        </div>
                                        <div class="postal-meta mt-1"><?php echo htmlspecialchars($shipment['purchase_invoice_number'] ?: '---'); ?></div>
                                    </div>
                                </td>
                                <td class="small">
                                    <div class="payment-stack">
                                        <div class="payment-box small">
                                            <div class="payment-box-title text-success">البيع</div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php echo $payBadges[$paymentStatus] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                                <?php echo $invoiceBadges[$saleStatus] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                            </div>
                                        </div>
                                        <div class="payment-box small">
                                            <div class="payment-box-title text-primary">الشراء</div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php echo $hasPurchaseInvoice ? ($payBadges[$purchasePaymentStatus] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : '<span class="badge bg-light text-dark rounded-pill">لا توجد</span>'; ?>
                                                <?php if ($hasPurchaseInvoice): ?>
                                                    <?php echo $invoiceBadges[$purchaseStatus] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="financial-actions-wrap">
                                        <button type="button" class="btn btn-sm btn-outline-secondary postal-view-btn" data-shipment="<?php echo $shipmentPayload; ?>" title="التفاصيل">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <?php if (has_permission('family_visit_financial_post') && $hasDraftInvoice): ?>
                                            <div class="financial-action-group">
                                                <button class="financial-action-btn fin-post" type="button" data-action-menu-toggle="post-<?php echo $shipment['id']; ?>" title="خيارات الترحيل">
                                                    <i class="fas fa-file-export"></i>
                                                    <span>الترحيل</span>
                                                    <i class="fas fa-chevron-down small"></i>
                                                </button>
                                                <div class="financial-action-menu" id="post-<?php echo $shipment['id']; ?>">
                                                    <div class="financial-action-menu-title">خيارات الترحيل</div>
                                                    <button class="financial-action-menu-item post-option <?php echo (($saleStatus === 'draft') && (!$hasPurchaseInvoice || $purchaseStatus === 'draft')) ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if (($saleStatus === 'draft') && (!$hasPurchaseInvoice || $purchaseStatus === 'draft')): ?>
                                                                onclick="PostalServices.postFinance(<?php echo (int)$shipment['id']; ?>, 'all')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-layer-group"></i>
                                                        <span>ترحيل الكل</span>
                                                    </button>
                                                    <button class="financial-action-menu-item post-option <?php echo ($hasSalesInvoice && $saleStatus === 'draft') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasSalesInvoice && $saleStatus === 'draft'): ?>
                                                                onclick="PostalServices.postFinance(<?php echo (int)$shipment['id']; ?>, 'sales')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-arrow-up-right-from-square"></i>
                                                        <span>ترحيل البيع</span>
                                                    </button>
                                                    <button class="financial-action-menu-item post-option <?php echo ($hasPurchaseInvoice && $purchaseStatus === 'draft') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasPurchaseInvoice && $purchaseStatus === 'draft'): ?>
                                                                onclick="PostalServices.postFinance(<?php echo (int)$shipment['id']; ?>, 'purchase')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-cart-plus"></i>
                                                        <span>ترحيل الشراء</span>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (has_permission('family_visit_financial_post') && $hasPostedInvoice): ?>
                                            <div class="financial-action-group">
                                                <button class="financial-action-btn fin-unpost" type="button" data-action-menu-toggle="unpost-<?php echo $shipment['id']; ?>" title="خيارات إلغاء الترحيل">
                                                    <i class="fas fa-rotate-left"></i>
                                                    <span>إلغاء الترحيل</span>
                                                    <i class="fas fa-chevron-down small"></i>
                                                </button>
                                                <div class="financial-action-menu" id="unpost-<?php echo $shipment['id']; ?>">
                                                    <div class="financial-action-menu-title">خيارات إلغاء الترحيل</div>
                                                    <button class="financial-action-menu-item unpost-option <?php echo (($saleStatus === 'posted') && (!$hasPurchaseInvoice || $purchaseStatus === 'posted')) ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if (($saleStatus === 'posted') && (!$hasPurchaseInvoice || $purchaseStatus === 'posted')): ?>
                                                                onclick="PostalServices.unpostFinance(<?php echo (int)$shipment['id']; ?>, 'all')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-layer-group"></i>
                                                        <span>إلغاء ترحيل الكل</span>
                                                    </button>
                                                    <button class="financial-action-menu-item unpost-option <?php echo ($hasSalesInvoice && $saleStatus === 'posted') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasSalesInvoice && $saleStatus === 'posted'): ?>
                                                                onclick="PostalServices.unpostFinance(<?php echo (int)$shipment['id']; ?>, 'sales')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-arrow-rotate-left"></i>
                                                        <span>إلغاء ترحيل البيع</span>
                                                    </button>
                                                    <button class="financial-action-menu-item unpost-option <?php echo ($hasPurchaseInvoice && $purchaseStatus === 'posted') ? '' : 'disabled'; ?>"
                                                            type="button"
                                                            <?php if ($hasPurchaseInvoice && $purchaseStatus === 'posted'): ?>
                                                                onclick="PostalServices.unpostFinance(<?php echo (int)$shipment['id']; ?>, 'purchase')"
                                                            <?php endif; ?>>
                                                        <i class="fas fa-clock-rotate-left"></i>
                                                        <span>إلغاء ترحيل الشراء</span>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($canModifyRow && (has_permission($permission_prefix . '_edit') || has_permission($permission_prefix . '_delete'))): ?>
                                            <div class="financial-action-group">
                                                <button class="financial-action-btn" type="button" data-action-menu-toggle="manage-<?php echo $shipment['id']; ?>" title="إدارة المعاملة" style="background: rgba(59, 130, 246, 0.12); color: #1d4ed8;">
                                                    <i class="fas fa-gear"></i>
                                                    <span>إدارة</span>
                                                    <i class="fas fa-chevron-down small"></i>
                                                </button>
                                                <div class="financial-action-menu" id="manage-<?php echo $shipment['id']; ?>">
                                                    <div class="financial-action-menu-title">إدارة المعاملة</div>
                                                    <?php if (has_permission($permission_prefix . '_edit')): ?>
                                                        <button class="financial-action-menu-item manage-option postal-edit-btn" type="button" data-shipment="<?php echo $shipmentPayload; ?>">
                                                            <i class="fas fa-pen-to-square"></i>
                                                            <span>تعديل الشحنة</span>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($hasSalesInvoice || $hasPurchaseInvoice): ?>
                                                        <button class="financial-action-menu-item delete-option <?php echo $hasPostedInvoice ? 'disabled' : ''; ?>"
                                                                type="button"
                                                                <?php if (!$hasPostedInvoice): ?>
                                                                    onclick="PostalServices.deleteFinance(<?php echo (int)$shipment['id']; ?>, 'all')"
                                                                <?php endif; ?>>
                                                            <i class="fas fa-trash-can"></i>
                                                            <span>حذف الكل</span>
                                                        </button>
                                                        <button class="financial-action-menu-item delete-option <?php echo (!$hasSalesInvoice || $saleStatus === 'posted') ? 'disabled' : ''; ?>"
                                                                type="button"
                                                                <?php if ($hasSalesInvoice && $saleStatus !== 'posted'): ?>
                                                                    onclick="PostalServices.deleteFinance(<?php echo (int)$shipment['id']; ?>, 'sales')"
                                                                <?php endif; ?>>
                                                            <i class="fas fa-file-invoice-dollar"></i>
                                                            <span>حذف البيع فقط</span>
                                                        </button>
                                                        <button class="financial-action-menu-item delete-option <?php echo (!$hasPurchaseInvoice || $purchaseStatus === 'posted') ? 'disabled' : ''; ?>"
                                                                type="button"
                                                                <?php if ($hasPurchaseInvoice && $purchaseStatus !== 'posted'): ?>
                                                                    onclick="PostalServices.deleteFinance(<?php echo (int)$shipment['id']; ?>, 'purchase')"
                                                                <?php endif; ?>>
                                                            <i class="fas fa-file-invoice"></i>
                                                            <span>حذف الشراء فقط</span>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (has_permission($permission_prefix . '_delete')): ?>
                                                        <a href="postal_services.php?delete_id=<?php echo (int)$shipment['id']; ?>" class="financial-action-menu-item delete-option <?php echo $hasPostedInvoice ? 'disabled' : ''; ?>" <?php echo $hasPostedInvoice ? 'tabindex="-1" aria-disabled="true"' : 'onclick="return confirm(\'هل أنت متأكد من حذف هذه الشحنة؟\');"'; ?>>
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

<?php if (has_permission($permission_prefix . '_add')): ?>
<div class="modal fade" id="addPostalShipmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form id="addPostalShipmentForm" method="POST" action="process_postal_service.php?action=add">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold">إضافة شحنة بريدية جديدة</h5>
                        <div class="text-muted small">أدخل بيانات الشحنة والحقول المالية الموحدة في نفس النموذج.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    
                    <!-- Basic Info Section -->
                    <div class="field-section">
                        <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رقم التتبع</label>
                            <input type="text" name="tracking_number" class="form-control rounded-3" value="<?php echo htmlspecialchars($nextTrackingNumber); ?>" readonly>
                            <div class="form-text">يُولَّد تلقائياً بشكل متسلسل من النظام.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">اسم الرسالة / الطرد</label>
                            <input type="text" name="shipment_name" class="form-control rounded-3" placeholder="مثال: كرتون أو بكت" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">تاريخ الإرسال المتوقع</label>
                            <input type="date" name="expected_send_date" class="form-control rounded-3">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">المحتوى</label>
                            <textarea name="content_description" class="form-control rounded-3" rows="2" placeholder="وصف مختصر لمحتوى الشحنة"></textarea>
                        </div>

                        </div>
                    </div>
                    
                    <!-- Sender Info Section -->
                    <div class="field-section">
                        <h6 class="fw-bold text-primary mb-3">بيانات المرسل</h6>
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">اسم المرسل الرباعي</label>
                                <input type="text" name="sender_full_name" id="add_sender_full_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">مدينة الإرسال</label>
                                <select name="sender_city_id" id="add_sender_city_id" class="form-select rounded-3">
                                    <option value="">اختر المدينة</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo (int)$city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">رقم المرسل</label>
                                <input type="text" name="sender_phone" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recipient Info Section -->
                    <div class="field-section">
                        <h6 class="fw-bold text-success mb-3">بيانات المستلم</h6>
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">اسم المستلم</label>
                                <input type="text" name="recipient_full_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">مدينة الاستلام</label>
                                <select name="recipient_city_id" id="add_recipient_city_id" class="form-select rounded-3">
                                    <option value="">اختر المدينة</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo (int)$city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">رقم المستلم</label>
                                <input type="text" name="recipient_phone" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>

                    <!-- Additional Info Section -->
                    <div class="field-section">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">اسم الموصل</label>
                                <input type="text" name="courier_name" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ملاحظة</label>
                                <input type="text" name="notes" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>

                    <!-- Financial Fields Section -->
                    <div class="field-section">
                        <h6 class="fw-bold text-info mb-3">الحقول المالية</h6>
                        <?php include '../includes/financial_fields.php'; ?>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5">حفظ الشحنة</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="viewPostalShipmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">تفاصيل الشحنة البريدية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewPostalShipmentContent"></div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<?php if (has_permission($permission_prefix . '_edit')): ?>
<div class="modal fade" id="editPostalShipmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form id="editPostalShipmentForm" method="POST" action="process_postal_service.php?action=update">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">تعديل الشحنة البريدية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="shipment_id" id="edit_shipment_id">
                    
                    <!-- Basic Info Section -->
                    <div class="field-section">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">رقم التتبع</label>
                                <input type="text" id="edit_tracking_number" class="form-control rounded-3" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">اسم الرسالة / الطرد</label>
                                <input type="text" name="shipment_name" id="edit_shipment_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">تاريخ الإرسال المتوقع</label>
                                <input type="date" name="expected_send_date" id="edit_expected_send_date" class="form-control rounded-3">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">المحتوى</label>
                                <textarea name="content_description" id="edit_content_description" class="form-control rounded-3" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sender Info Section -->
                    <div class="field-section">
                        <h6 class="fw-bold text-primary mb-3">بيانات المرسل</h6>
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">اسم المرسل الرباعي</label>
                                <input type="text" name="sender_full_name" id="edit_sender_full_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">مدينة الإرسال</label>
                                <select name="sender_city_id" id="edit_sender_city_id" class="form-select rounded-3">
                                    <option value="">اختر المدينة</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo (int)$city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">رقم المرسل</label>
                                <input type="text" name="sender_phone" id="edit_sender_phone" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recipient Info Section -->
                    <div class="field-section">
                        <h6 class="fw-bold text-success mb-3">بيانات المستلم</h6>
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">اسم المستلم</label>
                                <input type="text" name="recipient_full_name" id="edit_recipient_full_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">مدينة الاستلام</label>
                                <select name="recipient_city_id" id="edit_recipient_city_id" class="form-select rounded-3">
                                    <option value="">اختر المدينة</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo (int)$city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">رقم المستلم</label>
                                <input type="text" name="recipient_phone" id="edit_recipient_phone" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Info Section -->
                    <div class="field-section">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">اسم الموصل</label>
                                <input type="text" name="courier_name" id="edit_courier_name" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ملاحظة</label>
                                <input type="text" name="notes" id="edit_notes" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Financial Fields Section -->
                    <div class="field-section">
                        <h6 class="fw-bold text-info mb-3">الحقول المالية</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">العميل</label>
                                <select name="customer_id" id="edit_customer_id" class="form-select rounded-3">
                                    <option value="">اختر العميل</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">المورد</label>
                                <select name="supplier_id" id="edit_supplier_id" class="form-select rounded-3">
                                    <option value="">اختر المورد</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">الوصف</label>
                                <input type="text" name="description" id="edit_description" class="form-control rounded-3">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-5">حفظ التعديل</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const CSRF_TOKEN = <?php echo json_encode(generate_csrf_token()); ?>;
    const viewModalEl = document.getElementById('viewPostalShipmentModal');
    const viewContent = document.getElementById('viewPostalShipmentContent');
    const editModalEl = document.getElementById('editPostalShipmentModal');
    const currencyFmt = (value, symbol) => `${Number(value || 0).toFixed(2)}${symbol ? ' ' + symbol : ''}`;
    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const customers = <?php echo json_encode($customers_entities ?? [], JSON_UNESCAPED_UNICODE); ?>;
    const suppliers = <?php echo json_encode($suppliers_with_codes ?? [], JSON_UNESCAPED_UNICODE); ?>;
    const addSenderNameEl = document.getElementById('add_sender_full_name');
    const addSenderCityEl = document.getElementById('add_sender_city_id');
    const addRecipientCityEl = document.getElementById('add_recipient_city_id');
    const addDescriptionEl = document.getElementById('description');
    const editSenderNameEl = document.getElementById('edit_sender_full_name');
    const editSenderCityEl = document.getElementById('edit_sender_city_id');
    const editRecipientCityEl = document.getElementById('edit_recipient_city_id');
    const editDescriptionEl = document.getElementById('edit_description');

    function getSelectedText(selectEl) {
        if (!selectEl || selectEl.selectedIndex < 0) {
            return '';
        }
        return String(selectEl.options[selectEl.selectedIndex]?.text || '').trim();
    }

    function buildPostalDescription(senderCity, recipientCity, senderName) {
        const parts = ['ارسال رساله'];
        if (senderCity) {
            parts.push(`من ${senderCity}`);
        }
        if (recipientCity) {
            parts.push(`الى ${recipientCity}`);
        }
        if (senderName) {
            parts.push(`للاخ ${senderName}`);
        }
        return parts.join(' ').trim();
    }

    function updateAddDescription() {
        if (!addDescriptionEl) {
            return;
        }
        addDescriptionEl.value = buildPostalDescription(
            getSelectedText(addSenderCityEl),
            getSelectedText(addRecipientCityEl),
            addSenderNameEl?.value.trim() || ''
        );
    }

    function updateEditDescription() {
        if (!editDescriptionEl) {
            return;
        }
        editDescriptionEl.value = buildPostalDescription(
            getSelectedText(editSenderCityEl),
            getSelectedText(editRecipientCityEl),
            editSenderNameEl?.value.trim() || ''
        );
    }

    [addSenderNameEl, addSenderCityEl, addRecipientCityEl].forEach((el) => {
        if (el) {
            el.addEventListener('input', updateAddDescription);
            el.addEventListener('change', updateAddDescription);
        }
    });

    [editSenderNameEl, editSenderCityEl, editRecipientCityEl].forEach((el) => {
        if (el) {
            el.addEventListener('input', updateEditDescription);
            el.addEventListener('change', updateEditDescription);
        }
    });

    async function sendFinancialAction(action, id, scope) {
        const response = await fetch(`ajax_postal_services.php?action=${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                id: String(id),
                scope: scope
            })
        });

        return response.json();
    }

    async function confirmAction(title, text, confirmButtonText) {
        if (typeof Swal !== 'undefined') {
            const result = await Swal.fire({
                title,
                text,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText,
                cancelButtonText: 'إلغاء'
            });
            return !!result.isConfirmed;
        }

        return window.confirm(text);
    }

    function closeActionMenus() {
        document.querySelectorAll('.financial-action-menu.show').forEach((menu) => menu.classList.remove('show'));
    }

    document.addEventListener('click', function (e) {
        const menuToggleBtn = e.target.closest('[data-action-menu-toggle]');
        if (menuToggleBtn) {
            e.preventDefault();
            e.stopPropagation();
            const menuId = menuToggleBtn.getAttribute('data-action-menu-toggle');
            const menu = document.getElementById(menuId);
            if (!menu) {
                return;
            }
            const willShow = !menu.classList.contains('show');
            closeActionMenus();
            if (willShow) {
                menu.classList.add('show');
            }
            return;
        }

        if (!e.target.closest('.financial-action-group')) {
            closeActionMenus();
        }
    });

    const PostalServices = {
        async postFinance(id, scope) {
            closeActionMenus();
            const scopeLabel = scope === 'sales' ? 'فاتورة البيع' : (scope === 'purchase' ? 'فاتورة الشراء' : 'كامل الفواتير');
            const confirmed = await confirmAction('تأكيد الترحيل', `هل أنت متأكد من ترحيل ${scopeLabel} لهذه الشحنة؟`, 'نعم، ترحيل');
            if (!confirmed) {
                return;
            }
            try {
                const result = await sendFinancialAction('post_finance', id, scope);
                if (result.status === 'success') {
                    if (typeof Swal !== 'undefined') {
                        await Swal.fire({ title: 'تم', text: result.message || 'تم الترحيل بنجاح', icon: 'success' });
                    }
                    window.location.reload();
                    return;
                }
                throw new Error(result.message || 'تعذر تنفيذ الترحيل');
            } catch (error) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: 'خطأ', text: error.message || 'حدث خطأ أثناء تنفيذ الترحيل المالي', icon: 'error' });
                } else {
                    alert(error.message || 'حدث خطأ أثناء تنفيذ الترحيل المالي');
                }
            }
        },

        async unpostFinance(id, scope) {
            closeActionMenus();
            const scopeLabel = scope === 'sales' ? 'ترحيل البيع' : (scope === 'purchase' ? 'ترحيل الشراء' : 'كامل الترحيل');
            const confirmed = await confirmAction('إلغاء الترحيل', `هل أنت متأكد من إلغاء ${scopeLabel} لهذه الشحنة؟`, 'نعم، إلغاء الترحيل');
            if (!confirmed) {
                return;
            }
            try {
                const result = await sendFinancialAction('unpost_finance', id, scope);
                if (result.status === 'success') {
                    if (typeof Swal !== 'undefined') {
                        await Swal.fire({ title: 'تم', text: result.message || 'تم إلغاء الترحيل بنجاح', icon: 'success' });
                    }
                    window.location.reload();
                    return;
                }
                throw new Error(result.message || 'تعذر إلغاء الترحيل');
            } catch (error) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: 'خطأ', text: error.message || 'حدث خطأ أثناء إلغاء الترحيل المالي', icon: 'error' });
                } else {
                    alert(error.message || 'حدث خطأ أثناء إلغاء الترحيل المالي');
                }
            }
        },

        async deleteFinance(id, scope) {
            closeActionMenus();
            const scopeLabel = scope === 'sales' ? 'فاتورة البيع' : (scope === 'purchase' ? 'فاتورة الشراء' : 'كل الفواتير');
            const confirmed = await confirmAction('تأكيد الحذف المالي', `هل تريد حذف ${scopeLabel} لهذه الشحنة؟`, 'نعم، احذف');
            if (!confirmed) {
                return;
            }
            try {
                const result = await sendFinancialAction('cancel_invoices', id, scope);
                if (result.status === 'success') {
                    if (typeof Swal !== 'undefined') {
                        await Swal.fire({ title: 'تم', text: result.message || 'تم حذف الفواتير بنجاح', icon: 'success' });
                    }
                    window.location.reload();
                    return;
                }
                throw new Error(result.message || 'تعذر حذف الفواتير');
            } catch (error) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: 'خطأ', text: error.message || 'حدث خطأ أثناء حذف الفواتير', icon: 'error' });
                } else {
                    alert(error.message || 'حدث خطأ أثناء حذف الفواتير');
                }
            }
        }
    };

    window.PostalServices = PostalServices;

    function parseShipment(button) {
        try {
            return JSON.parse(button.getAttribute('data-shipment') || '{}');
        } catch (error) {
            return {};
        }
    }

    function fillSelectOptions(selectEl, rows, selectedValue, getValue, getLabel) {
        if (!selectEl) {
            return;
        }
        const currentValue = selectedValue === null || selectedValue === undefined ? '' : String(selectedValue);
        const options = ['<option value="">---</option>'];
        rows.forEach((row) => {
            const value = String(getValue(row) ?? '');
            const label = String(getLabel(row) ?? '');
            const selected = value === currentValue ? ' selected' : '';
            options.push(`<option value="${value}"${selected}>${label}</option>`);
        });
        selectEl.innerHTML = options.join('');
    }

    document.querySelectorAll('.postal-view-btn').forEach((button) => {
        button.addEventListener('click', function () {
            const shipment = parseShipment(this);
            viewContent.innerHTML = `
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="small text-muted">رقم التتبع</div>
                        <div class="fw-bold">${escapeHtml(shipment.tracking_number || '---')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">اسم الرسالة / الطرد</div>
                        <div class="fw-bold">${escapeHtml(shipment.shipment_name || '---')}</div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted">المحتوى</div>
                        <div class="fw-bold">${escapeHtml(shipment.content_description || '---')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">المستلم</div>
                        <div class="fw-bold">${escapeHtml(shipment.recipient_full_name || '---')}</div>
                        <div class="text-muted small mt-1">${escapeHtml(shipment.recipient_city_name || '---')} | ${escapeHtml(shipment.recipient_phone || '---')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">المرسل</div>
                        <div class="fw-bold">${escapeHtml(shipment.sender_full_name || '---')}</div>
                        <div class="text-muted small mt-1">${escapeHtml(shipment.sender_city_name || '---')} | ${escapeHtml(shipment.sender_phone || '---')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">اسم الموصل</div>
                        <div class="fw-bold">${escapeHtml(shipment.courier_name || '---')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">تاريخ الإرسال المتوقع</div>
                        <div class="fw-bold">${escapeHtml(shipment.expected_send_date || '---')}</div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted">ملاحظة</div>
                        <div class="fw-bold">${escapeHtml(shipment.notes || '---')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">فاتورة البيع</div>
                        <div class="fw-bold">${escapeHtml(shipment.sale_invoice_number || '---')}</div>
                        <div class="text-success small mt-1">${escapeHtml(currencyFmt(shipment.sale_total_amount, shipment.currency_symbol))}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">فاتورة الشراء</div>
                        <div class="fw-bold">${escapeHtml(shipment.purchase_invoice_number || '---')}</div>
                        <div class="text-primary small mt-1">${escapeHtml(currencyFmt(shipment.purchase_total_amount, shipment.currency_symbol))}</div>
                    </div>
                </div>
            `;
            new bootstrap.Modal(viewModalEl).show();
        });
    });

    document.querySelectorAll('.postal-edit-btn').forEach((button) => {
        button.addEventListener('click', function () {
            const shipment = parseShipment(this);
            document.getElementById('edit_shipment_id').value = shipment.id || '';
            document.getElementById('edit_tracking_number').value = shipment.tracking_number || '';
            document.getElementById('edit_shipment_name').value = shipment.shipment_name || '';
            document.getElementById('edit_content_description').value = shipment.content_description || '';
            document.getElementById('edit_expected_send_date').value = shipment.expected_send_date || '';
            document.getElementById('edit_sender_full_name').value = shipment.sender_full_name || '';
            document.getElementById('edit_sender_city_id').value = shipment.sender_city_id || '';
            document.getElementById('edit_sender_phone').value = shipment.sender_phone || '';
            document.getElementById('edit_recipient_full_name').value = shipment.recipient_full_name || '';
            document.getElementById('edit_recipient_city_id').value = shipment.recipient_city_id || '';
            document.getElementById('edit_recipient_phone').value = shipment.recipient_phone || '';
            document.getElementById('edit_courier_name').value = shipment.courier_name || '';
            document.getElementById('edit_notes').value = shipment.notes || '';
            updateEditDescription();

            fillSelectOptions(
                document.getElementById('edit_customer_id'),
                customers,
                shipment.customer_id || '',
                (row) => row.customer_id || row.id || '',
                (row) => `${row.name || row.account_name_ar || ''}`.trim()
            );
            fillSelectOptions(
                document.getElementById('edit_supplier_id'),
                suppliers,
                shipment.supplier_id || '',
                (row) => row.supplier_id || row.id || '',
                (row) => row.display_name || `${row.account_name_ar || ''}`
            );

            new bootstrap.Modal(editModalEl).show();
        });
    });
});
</script>

<style>
    /* Fix modal footer to always be visible */
    #addPostalShipmentModal .modal-content,
    #editPostalShipmentModal .modal-content {
        display: flex;
        flex-direction: column;
        max-height: 90vh;
    }
    
    #addPostalShipmentModal .modal-header,
    #editPostalShipmentModal .modal-header {
        flex-shrink: 0;
    }
    
    #addPostalShipmentModal .modal-body,
    #editPostalShipmentModal .modal-body {
        flex-grow: 1;
        overflow-y: auto;
    }
    
    #addPostalShipmentModal .modal-footer,
    #editPostalShipmentModal .modal-footer {
        flex-shrink: 0;
        background-color: white;
        position: sticky;
        bottom: 0;
        z-index: 10;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
    }
    
    /* Improve field sections */
    .field-section {
        margin-bottom: 1.5rem;
        padding: 1rem;
        border: 1px solid #f0f0f0;
        border-radius: 8px;
        background-color: #fafafa;
    }
    
    .field-section h6 {
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #e0e0e0;
    }
</style>

<?php require_once 'footer.php'; ?>
