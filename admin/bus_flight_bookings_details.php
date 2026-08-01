<?php
ob_start();
require_once 'header.php';
require_once '../core/bookings/BookingServiceFactory.php';

if (!isset($_GET['id'])) {
    header('Location: bus_flight_bookings.php');
    exit();
}

$id = (int)$_GET['id'];
$bookingFinancialSourceTypes = [
    'حجوزات الباصات والطيران',
    'تذاكر طيران وبصات',
    'حجوزات الباصات',
    'حجوزات الطيران',
    'bus',
    'flight',
    'الطيران'
];
$bookingFinancialSourceTypesSql = "'" . implode("','", array_map(static function ($value) use ($pdo) {
    return substr($pdo->quote($value), 1, -1);
}, $bookingFinancialSourceTypes)) . "'";

// جلب تفاصيل الحجز مع الربط بالجداول الأخرى
$stmt = $pdo->prepare("
    SELECT
        b.*,
        COALESCE(inv.total_amount, 0) AS sale_price,
        COALESCE(inv_p.total_amount, 0) AS purchase_price,
        -- حساب المبلغ المحصل (البيع) - نفس منطق invoices.php و bus_flight_bookings.php
        (
            IFNULL((
                SELECT SUM(jl.debit)
                FROM journal_lines jl
                JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                AND jl.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
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
        ) AS amount_received,
        -- حساب المبلغ المسدد للمورد (الشراء)
        (
            IFNULL((
                SELECT SUM(jl.credit)
                FROM journal_lines jl
                JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                WHERE ft_i.reference_id = inv_p.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                AND jl.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
                )
            ), 0) +
            IFNULL((
                SELECT SUM(pa.allocated_amount)
                FROM payment_allocations pa
                JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                WHERE pa.invoice_id = inv_p.id AND ft.status = 'posted'
                AND ft.id NOT IN (
                    SELECT id FROM financial_transactions
                    WHERE reference_id = inv_p.id AND reference_type = 'invoice'
                )
            ), 0)
        ) AS purch_amount_received,
        (COALESCE(inv.total_amount, 0) - (
            IFNULL((
                SELECT SUM(jl.debit)
                FROM journal_lines jl
                JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                AND jl.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
                )
            ), inv.amount_received) +
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
        )) AS remaining_amount,
        (COALESCE(inv.total_amount, 0) - COALESCE(inv_p.total_amount, 0)) AS profit,
        COALESCE(inv.currency_id, 1) AS currency_id,
        COALESCE(inv.delivery_type, 'cash') AS payment_type,
        c_from.city_name AS from_city_name,
        c_to.city_name AS to_city_name,
        curr.currency_name,
        curr.currency_symbol,
        bs.status_name AS booking_status_name,
        bs.status_color AS booking_status_color,
        cust.full_name AS customer_full_name,
        cust.account_id AS customer_chart_account_id,
        u.full_name AS created_by_user_full_name,
        s.supplier_name,
        cnt.country_name AS nationality_name
    FROM bus_flight_bookings b
    LEFT JOIN cities c_from ON b.from_city_id = c_from.id
    LEFT JOIN cities c_to ON b.to_city_id = c_to.id
    LEFT JOIN invoices inv ON (
        inv.id = b.sales_invoice_id 
        OR inv.id = b.invoice_id 
        OR (inv.source_type IN ($bookingFinancialSourceTypesSql) AND inv.source_id = b.id AND inv.invoice_category = 'sales')
    )
    LEFT JOIN invoices inv_p ON (
        inv_p.id = b.purchase_invoice_id 
        OR (inv_p.source_type IN ($bookingFinancialSourceTypesSql) AND inv_p.source_id = b.id AND inv_p.invoice_category = 'purchase')
    )
    LEFT JOIN currencies curr ON COALESCE(inv.currency_id, 1) = curr.id
    LEFT JOIN statuses bs ON b.status_id = bs.id
    LEFT JOIN customers cust ON b.customer_id = cust.id
    LEFT JOIN users u ON b.created_by = u.id
    LEFT JOIN suppliers s ON b.supplier_id = s.id
    LEFT JOIN countries cnt ON b.nationality_id = cnt.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
    header('Location: bus_flight_bookings.php');
    exit();
}

$currentBookingSourceType = 'حجوزات الباصات والطيران';
if (($b['service_type'] ?? '') === 'bus') {
    $currentBookingSourceType = 'حجوزات الباصات';
} elseif (($b['service_type'] ?? '') === 'flight') {
    $currentBookingSourceType = 'حجوزات الطيران';
}

// جلب سجل الحالات
$stmt_logs = $pdo->prepare("
    SELECT sl.*, s.status_name, s.status_color, u.full_name
    FROM booking_status_logs sl
    JOIN statuses s ON sl.new_status_id = s.id
    JOIN users u ON sl.changed_by = u.id
    WHERE sl.booking_id = ?
    ORDER BY sl.created_at DESC
");
$stmt_logs->execute([$id]);
$logs = $stmt_logs->fetchAll();

// جلب سجل الدفعات (السندات) المرتبطة بالحجز (استخدام نظام القيود المالية الجديد)
$stmt_payments = $pdo->prepare("
    SELECT ft.*, ft.amount as amount, u.full_name as user_full_name, coa.account_name_ar as account_name
    FROM financial_transactions ft
    JOIN users u ON ft.created_by = u.id
    JOIN journal_lines jl ON ft.id = jl.financial_transaction_id AND jl.debit > 0
    JOIN unified_accounts coa ON jl.account_id = coa.id
    WHERE ft.reference_id = ? AND ft.reference_type = ? AND ft.transaction_type = 'receipt'
    ORDER BY ft.created_at DESC
");
$stmt_payments->execute([$id, $b['service_type']]);
$payments = $stmt_payments->fetchAll();

$stmt_sales_inv = $pdo->prepare("
    SELECT i.*, ua.account_name_ar as account_name, coa.account_name_ar as coa_name, coa.account_code as coa_code
    FROM invoices i
    LEFT JOIN unified_accounts ua ON i.account_id = ua.id
    LEFT JOIN customers cust ON i.customer_id = cust.id
    LEFT JOIN unified_accounts coa ON cust.account_id = coa.id
    WHERE i.source_type IN ($bookingFinancialSourceTypesSql) AND i.source_id = ? AND i.invoice_category = 'sales'
    ORDER BY i.id DESC
    LIMIT 1
");
$stmt_sales_inv->execute([$id]);
$sales_invoice = $stmt_sales_inv->fetch();

$stmt_purch_inv = $pdo->prepare("
    SELECT i.*, s.supplier_name, ua.account_name_ar as account_name, coa.account_name_ar as coa_name, coa.account_code as coa_code
    FROM invoices i
    LEFT JOIN suppliers s ON i.supplier_id = s.id
    LEFT JOIN unified_accounts ua ON i.account_id = ua.id
    LEFT JOIN unified_accounts coa ON s.account_id = coa.id
    WHERE i.source_type IN ($bookingFinancialSourceTypesSql) AND i.source_id = ? AND i.invoice_category = 'purchase'
    ORDER BY i.id DESC
    LIMIT 1
");
$stmt_purch_inv->execute([$id]);
$purch_invoice = $stmt_purch_inv->fetch();

// ضمان أن حالة السداد في الفواتير الموحدة تتحدّث بناءً على المبلغ المحصّل الحقيقي.
function derive_payment_status($invoice, $computedPaid)
{
    $netAmount = ($invoice['total_amount'] ?? 0) - ($invoice['discount'] ?? 0);
    $paid = max(0, $computedPaid);
    $remaining = max(0, $netAmount - $paid);

    if ($paid <= 0) {
        return ['payment_status' => 'unpaid', 'amount_received' => $paid, 'remaining_amount' => $remaining];
    }

    if ($remaining <= 0) {
        return ['payment_status' => 'paid', 'amount_received' => $paid, 'remaining_amount' => $remaining];
    }

    return ['payment_status' => 'partial', 'amount_received' => $paid, 'remaining_amount' => $remaining];
}

if ($sales_invoice) {
    $salesComputedPaid = $b['amount_received'] ?? ($sales_invoice['amount_received'] ?? 0);
    $salesDerived = derive_payment_status($sales_invoice, $salesComputedPaid);
    $sales_invoice['payment_status'] = $salesDerived['payment_status'];
    $sales_invoice['amount_received'] = $salesDerived['amount_received'];
    $sales_invoice['remaining_amount'] = $salesDerived['remaining_amount'];
}

if ($purch_invoice) {
    $purchComputedPaid = $b['purch_amount_received'] ?? ($purch_invoice['amount_received'] ?? 0);
    $purchDerived = derive_payment_status($purch_invoice, $purchComputedPaid);
    $purch_invoice['payment_status'] = $purchDerived['payment_status'];
    $purch_invoice['amount_received'] = $purchDerived['amount_received'];
    $purch_invoice['remaining_amount'] = $purchDerived['remaining_amount'];
}

// معالجة تغيير سير العمل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_workflow'])) {
    $new_workflow_id = (int)$_POST['new_workflow_id'];
    if ($new_workflow_id > 0) {
        $stmt_wf = $pdo->prepare("UPDATE bus_flight_bookings SET workflow_id = ? WHERE id = ?");
        if ($stmt_wf->execute([$new_workflow_id, $id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم تغيير سير العمل بنجاح'];
            header("Location: bus_flight_bookings_details.php?id=$id");
            exit();
        }
    }
}

// جلب سير العمل المناسب لهذا الحجز
$all_workflows = get_all_workflows_for_transaction('bus_flight_bookings', $b['branch_id']);
$workflow = null;

// إذا كان الحجز مرتبطاً بسير عمل محدد
if (!empty($b['workflow_id'])) {
    $stmt_wf = $pdo->prepare("SELECT * FROM workflows WHERE id = ? AND is_active = 1");
    $stmt_wf->execute([$b['workflow_id']]);
    $workflow = $stmt_wf->fetch();
}

// إذا لم نجد سير عمل محدد، نستخدم الافتراضي
if (!$workflow) {
    $workflow = get_workflow_for_transaction('bus_flight_bookings', $b['branch_id']);
}

$allowed_transitions = [];
$current_step = null;
$workflow_steps = [];

if ($workflow) {
    // جلب جميع خطوات سير العمل للعرض
    $stmt_steps = $pdo->prepare("SELECT ws.*, s.status_name FROM workflow_steps ws JOIN statuses s ON ws.status_id = s.id WHERE ws.workflow_id = ? ORDER BY ws.sort_order ASC");
    $stmt_steps->execute([$workflow['id']]);
    $workflow_steps = $stmt_steps->fetchAll();

    // جلب الخطوة الحالية بناءً على الحالة
    $stmt_curr = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ? LIMIT 1");
    $stmt_curr->execute([$workflow['id'], $b['status_id']]);
    $current_step_id = $stmt_curr->fetchColumn();

    if ($current_step_id) {
        $allowed_transitions = get_allowed_transitions($workflow['id'], $current_step_id, $_SESSION['role_id'] ?? null, $_SESSION['user_id'] ?? null);
    }
}

// معالجة تغيير الحالة عبر سير العمل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_workflow_status'])) {
    $to_status_id = (int)$_POST['to_status_id'];
    $notes = $_POST['workflow_notes'] ?? '';
    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
    $extra_fields = $_POST['extra_fields'] ?? [];
    $transition_id = $_POST['transition_id'] ?? null;

    if ($to_status_id > 0) {
        // استخدام دالة change_booking_status لتغيير الحالة
        if (change_booking_status($id, $to_status_id, $user_id, $notes, $extra_fields, $transition_id)) {
            $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم نقل الحجز إلى المرحلة الجديدة بنجاح'];
            header("Location: bus_flight_bookings_details.php?id=$id");
            exit();
        } else {
            $error = "فشل في تحديث الحالة";
        }
    }
}

// معالجة إنشاء فواتير مالية للحجوزات القديمة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_missing_invoices'])) {
    require_once '../includes/accounting_functions.php';
    try {
        $pdo->beginTransaction();

        $customer_id = $b['customer_id'];
        $supplier_id = $b['supplier_id'];
        $branch_id = $b['branch_id'];
        $currency_id = $b['currency_id'] ?? 1;
        $sale_price = (float)$b['sale_price'];
        $purchase_price = (float)$b['purchase_price'];
        $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];

        // 1. إنشاء فاتورة البيع (إذا لم تكن موجودة)
        if (!$sales_invoice) {
            $sales_invoice_id = php_create_invoice_and_post(
                $pdo,
                'sales',
                $branch_id,
                $currentBookingSourceType,
                $id,
                $customer_id,
                $currency_id,
                $sale_price,
                0,
                $purchase_price,
                'draft',
                "فاتورة مبيعات لحجز رقم " . $b['booking_number'],
                normalize_datetime_db(null),
                $user_id,
                $b['agent_id'],
                $b['account_id']
            );
            // تحديث الحجز لربطه بالفاتورة
            $pdo->prepare("UPDATE bus_flight_bookings SET invoice_id = ?, sales_invoice_id = ? WHERE id = ?")->execute([$sales_invoice_id, $sales_invoice_id, $id]);
        }

        // 2. إنشاء فاتورة الشراء (إذا لم تكن موجودة)
        if (!$purch_invoice) {
            $stmt_sup_acc = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt_sup_acc->execute([$supplier_id]);
            $supplier_account_id = $stmt_sup_acc->fetchColumn();

            if ($supplier_account_id) {
                $purchase_invoice_id = php_create_invoice_and_post(
                    $pdo,
                    'purchase',
                    $branch_id,
                    $currentBookingSourceType,
                    $id,
                    $supplier_id,
                    $currency_id,
                    $purchase_price,
                    0,
                    0,
                    'draft',
                    "فاتورة تكلفة لحجز رقم " . $b['booking_number'],
                    normalize_datetime_db(null),
                    $user_id,
                    null,
                    $supplier_account_id
                );
                $pdo->prepare("UPDATE bus_flight_bookings SET purchase_invoice_id = ? WHERE id = ?")->execute([$purchase_invoice_id, $id]);
            }
        }

        $pdo->commit();
        $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم بنجاح', 'body' => 'تم إنشاء وترحيل الفواتير والقيود المحاسبية للحجز بنجاح'];
        header("Location: bus_flight_bookings_details.php?id=$id");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => "حدث خطأ أثناء ترحيل الفواتير: " . $e->getMessage()];
        header("Location: bus_flight_bookings_details.php?id=$id");
        exit();
    }
}
?>

<div class="container-fluid py-4">
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <strong><?= $_SESSION['flash_message']['title'] ?></strong> <?= $_SESSION['flash_message']['body'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1">تفاصيل الحجز رقم: <?= $b['booking_number'] ?></h3>
            <p class="text-muted small mb-0">عرض كافة البيانات المرتبطة بالحجز والسجل الزمني للحالات</p>
        </div>
        <div class="btn-group">
            <a href="bus_flight_bookings.php" class="btn btn-outline-secondary rounded-pill px-4 me-2">
                <i class="fas fa-arrow-right me-1"></i> العودة للقائمة
            </a>
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 shadow-sm">
                <i class="fas fa-print me-1"></i> طباعة التفاصيل
            </button>
        </div>
    </div>

    <?php if (!empty($workflow_steps)): ?>
        <div class="card shadow-sm border-0 rounded-4 mb-4 no-print">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h6 class="text-muted small fw-bold mb-0"><i class="fas fa-project-diagram me-2 text-primary"></i> مسار سير عمل الحجوزات: <?= htmlspecialchars($workflow['name'] ?? 'الافتراضي') ?></h6>

                    <?php if (count($all_workflows) > 1 && $is_admin): ?>
                        <form method="POST" class="d-flex gap-2 align-items-center">
                            <label class="small text-muted mb-0">تغيير سير العمل:</label>
                            <select name="new_workflow_id" class="form-select form-select-sm rounded-pill" style="width: auto;">
                                <?php foreach ($all_workflows as $wf): ?>
                                    <option value="<?= $wf['id'] ?>" <?= $workflow['id'] == $wf['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($wf['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="change_workflow" class="btn btn-sm btn-primary rounded-pill px-3">تحديث</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="workflow-steps d-flex justify-content-between align-items-center position-relative">
                    <div class="workflow-progress-line position-absolute w-100 bg-light" style="height: 4px; top: 50%; left: 0; transform: translateY(-50%); z-index: 0;"></div>
                    <?php
                    $passed = true;
                    foreach ($workflow_steps as $step):
                        $is_current = ($step['id'] == $current_step_id);
                    ?>
                        <div class="workflow-step-item text-center position-relative" style="z-index: 1;">
                            <div class="workflow-dot rounded-circle d-flex align-items-center justify-content-center mx-auto mb-2 <?= $is_current ? 'bg-primary text-white shadow' : ($passed ? 'bg-success text-white' : 'bg-white border text-muted') ?>"
                                style="width: 40px; height: 40px; transition: all 0.3s ease;">
                                <?php if ($passed && !$is_current): ?>
                                    <i class="fas fa-check"></i>
                                <?php else: ?>
                                    <i class="<?= $step['is_final'] ? 'fas fa-flag-checkered' : 'fas fa-circle' ?>"></i>
                                <?php endif; ?>
                            </div>
                            <span class="small fw-bold <?= $is_current ? 'text-primary' : ($passed ? 'text-success' : 'text-muted') ?>">
                                <?= htmlspecialchars($step['status_name']) ?>
                            </span>
                            <?php if ($is_current) $passed = false; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- البيانات الأساسية للمسافر -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-primary text-white border-0 py-3 rounded-top-4">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-user me-2"></i> بيانات المسافر والرحلة</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3 border-bottom pb-2">
                        <label class="text-muted small d-block">اسم المسافر</label>
                        <span class="fw-bold fs-5 text-dark"><?= htmlspecialchars($b['traveler_name']) ?></span>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6 border-end">
                            <label class="text-muted small d-block">رقم الجوال</label>
                            <span class="fw-bold text-dark"><?= htmlspecialchars($b['mobile_number']) ?></span>
                        </div>
                        <div class="col-6">
                            <label class="text-muted small d-block">الجنسية</label>
                            <span class="fw-bold text-dark"><?= htmlspecialchars($b['nationality_name'] ?? '---') ?></span>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6 border-end">
                            <label class="text-muted small d-block">مكان الميلاد</label>
                            <span class="fw-bold text-dark"><?= htmlspecialchars($b['place_of_birth'] ?? '---') ?></span>
                        </div>
                        <div class="col-6">
                            <label class="text-muted small d-block">تاريخ الميلاد</label>
                            <span class="fw-bold text-dark"><?= $b['date_of_birth'] ?: '---' ?></span>
                        </div>
                    </div>
                    <div class="mb-3 border-top pt-3">
                        <label class="text-muted small d-block">بيانات الهوية (<?= $b['id_type'] == 'passport' ? 'جواز سفر' : 'بطاقة وطنية' ?>)</label>
                        <span class="fw-bold text-dark"><?= htmlspecialchars($b['id_number']) ?></span>
                        <div class="extra-small text-muted mt-1">
                            مكان الإصدار: <?= htmlspecialchars($b['id_issue_place'] ?? '---') ?> |
                            التاريخ: <?= $b['id_issue_date'] ?: '---' ?> |
                            الانتهاء: <?= $b['id_expiry_date'] ?: '---' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- بيانات الحجز والرحلة -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-success text-white border-0 py-3 rounded-top-4">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-ticket-alt me-2"></i> بيانات الرحلة والمورد</h6>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-4 p-3 bg-light rounded-4">
                        <div class="text-center">
                            <span class="text-muted extra-small d-block">من</span>
                            <span class="fw-bold text-primary fs-5"><?= htmlspecialchars($b['from_city_name']) ?></span>
                        </div>
                        <i class="fas fa-long-arrow-alt-left fa-2x text-muted mx-3"></i>
                        <div class="text-center">
                            <span class="text-muted extra-small d-block">إلى</span>
                            <span class="fw-bold text-primary fs-5"><?= htmlspecialchars($b['to_city_name']) ?></span>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6 border-end">
                            <label class="text-muted small d-block">نوع الخدمة</label>
                            <span class="badge bg-<?= $b['service_type'] == 'bus' ? 'success' : 'info' ?> rounded-pill px-3">
                                <i class="fas <?= $b['service_type'] == 'bus' ? 'fa-bus' : 'fa-plane' ?> me-1"></i> <?= $b['service_type'] == 'bus' ? 'باص' : 'طيران' ?>
                            </span>
                        </div>
                        <div class="col-6">
                            <label class="text-muted small d-block">نوع الرحلة</label>
                            <span class="fw-bold text-dark"><?= $b['trip_type'] == 'one_way' ? 'ذهاب فقط' : ($b['trip_type'] == 'round_trip' ? 'ذهاب وعودة' : 'متعدد الوجهات') ?></span>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6 border-end">
                            <label class="text-muted small d-block">تاريخ المغادرة</label>
                            <span class="fw-bold text-dark"><?= $b['departure_date'] ?></span>
                        </div>
                        <div class="col-6">
                            <?php if ($b['trip_type'] == 'round_trip'): ?>
                                <label class="text-muted small d-block">تاريخ العودة</label>
                                <span class="fw-bold text-dark"><?= $b['return_date'] ?: '---' ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3 border-top pt-3">
                        <label class="text-muted small d-block">المورد / شركة النقل</label>
                        <span class="fw-bold text-dark"><i class="fas fa-truck me-2 text-muted"></i> <?= htmlspecialchars($b['supplier_name']) ?></span>
                        <?php if ($b['service_type'] == 'bus' && $b['bus_type']): ?>
                            <div class="extra-small text-muted mt-1">نوع الباص: <?= htmlspecialchars($b['bus_type']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- البيانات المالية والحالة -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-warning text-dark border-0 py-3 rounded-top-4">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-dollar-sign me-2"></i> البيانات المالية والحالة</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="text-muted small d-block">الحالة الحالية</label>
                        <span class="badge bg-<?= $b['booking_status_color'] ?> rounded-pill px-4 py-2 fs-6 w-100 mt-1">
                            <?= $b['booking_status_name'] ?>
                        </span>
                    </div>

                    <?php if (!empty($allowed_transitions)): ?>
                        <div class="mb-3 border-top pt-3 no-print">
                            <label class="text-muted small d-block mb-2"><i class="fas fa-random me-1 text-primary"></i> تغيير المرحلة (سير العمل)</label>
                            <div class="d-grid gap-2">
                                <?php foreach ($allowed_transitions as $trans): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-outline-<?= $trans['color'] ?: 'primary' ?> rounded-pill text-start px-3 py-2"
                                        data-bs-toggle="modal"
                                        data-bs-target="#workflowModal<?= $trans['to_step_id'] ?>">
                                        <i class="fas fa-chevron-left me-2"></i> <?= htmlspecialchars($trans['to_step_name']) ?>
                                    </button>

                                    <!-- Modal لكل انتقال -->
                                    <div class="modal fade" id="workflowModal<?= $trans['to_step_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg rounded-4">
                                                <form method="POST">
                                                    <input type="hidden" name="to_status_id" value="<?= (int)$pdo->query("SELECT status_id FROM workflow_steps WHERE id = " . $trans['to_step_id'])->fetchColumn() ?>">
                                                    <input type="hidden" name="to_step_id" value="<?= $trans['to_step_id'] ?>">
                                                    <input type="hidden" name="transition_id" value="<?= $trans['transition_id'] ?? '' ?>">
                                                    <div class="modal-header bg-<?= $trans['color'] ?: 'primary' ?> text-white border-0 py-3">
                                                        <h6 class="modal-title fw-bold">نقل إلى: <?= htmlspecialchars($trans['to_step_name']) ?></h6>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <p class="text-muted small mb-3">هل أنت متأكد من رغبتك في نقل الحجز إلى مرحلة "<?= htmlspecialchars($trans['to_step_name']) ?>"؟</p>

                                                        <?php
                                                        $step_fields = get_step_fields($trans['to_step_id']);
                                                        if (!empty($step_fields)):
                                                            $all_fields = get_all_workflow_fields();
                                                        ?>
                                                            <div class="row g-3 mb-3">
                                                                <?php foreach ($step_fields as $fkey):
                                                                    if (!isset($all_fields[$fkey])) continue;
                                                                    $ftype = 'text';
                                                                    $fvalue = '';
                                                                    if (strpos($fkey, 'date') !== false || strpos($fkey, 'datetime') !== false) {
                                                                        $ftype = (strpos($fkey, 'datetime') !== false) ? 'datetime-local' : 'date';
                                                                        // تعبئة الوقت الحالي تلقائياً لحقول التاريخ والوقت المحددة
                                                                        if (in_array($fkey, ['confirm_datetime', 'mod_datetime', 'cancel_datetime'])) {
                                                                            $fvalue = date('Y-m-d\TH:i');
                                                                        }
                                                                    }
                                                                    if (strpos($fkey, 'amount') !== false || strpos($fkey, 'price') !== false) $ftype = 'number';
                                                                ?>
                                                                    <div class="col-md-12">
                                                                        <label class="form-label small fw-bold"><?= $all_fields[$fkey] ?></label>
                                                                        <?php if ($fkey == 'is_cancelled'): ?>
                                                                            <select name="extra_fields[<?= $fkey ?>]" class="form-select rounded-3">
                                                                                <option value="0">لا</option>
                                                                                <option value="1">نعم</option>
                                                                            </select>
                                                                        <?php elseif (strpos($fkey, 'reason') !== false || $fkey == 'notes'): ?>
                                                                            <textarea name="extra_fields[<?= $fkey ?>]" class="form-control rounded-3" rows="2"></textarea>
                                                                        <?php else: ?>
                                                                            <input type="<?= $ftype ?>" name="extra_fields[<?= $fkey ?>]" class="form-control rounded-3" step="0.01" value="<?= $fvalue ?>">
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="mb-0">
                                                            <label class="form-label small fw-bold">ملاحظات التحويل</label>
                                                            <textarea class="form-control rounded-3" name="workflow_notes" rows="3" placeholder="أدخل أي ملاحظات اختيارية هنا..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light border-0">
                                                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                                                        <button type="submit" name="change_workflow_status" class="btn btn-<?= $trans['color'] ?: 'primary' ?> rounded-pill px-4">تأكيد النقل</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-0">
                        <label class="text-muted small d-block">نوع التوصيل</label>
                        <span class="fw-bold text-dark">
                            <?php
                            switch ($b['payment_type']) {
                                case 'cash':
                                    echo 'نقد';
                                    break;
                                case 'credit':
                                    echo 'أجل (مديونية)';
                                    break;
                                case 'bank_transfer':
                                    echo 'تحويل بنكي';
                                    break;
                                case 'no_payment':
                                    echo 'بدون توصيل';
                                    break;
                                default:
                                    echo $b['payment_type'];
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- القسم المحاسبي المطور -->
    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-dark text-white border-0 py-3 rounded-top-4 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i> الحالة المحاسبية والمالية للحجز</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <!-- فاتورة البيع (إيراد) -->
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <!-- Header matching the screenshot design -->
                                <div class="card-header bg-success text-white py-3 border-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex gap-2 align-items-center">
                                            <span class="badge bg-white text-success rounded-pill small"><?php echo $sales_invoice['invoice_number'] ?? '---'; ?></span>
                                            <?php if ($sales_invoice && $sales_invoice['invoice_status'] == 'posted'): ?>
                                                <span class="badge bg-warning text-dark rounded-pill small">مرحلة</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark rounded-pill small">مسودة</span>
                                            <?php endif; ?>
                                        </div>
                                        <h6 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i>فاتورة البيع (للعميل)</h6>
                                    </div>
                                </div>
                                <div class="card-body p-4 bg-light">
                                    <?php if ($sales_invoice): ?>
                                        <?php
                                        $payment_status = $sales_invoice['payment_status'] ?? 'unpaid';
                                        $status_labels = [
                                            'paid' => ['مدفوع', 'bg-success'],
                                            'partial' => ['جزئي', 'bg-warning'],
                                            'unpaid' => ['غير مدفوع', 'bg-danger']
                                        ];
                                        $status_info = $status_labels[$payment_status] ?? ['غير معروف', 'bg-secondary'];
                                        $net_amount = $sales_invoice['total_amount'] - ($sales_invoice['discount'] ?? 0);
                                        $paid_amount = $sales_invoice['amount_received'] ?? 0;
                                        $remaining = $sales_invoice['remaining_amount'] ?? ($net_amount - $paid_amount);
                                        ?>
                                        <!-- Client and Payment Status Row -->
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <div class="text-muted small mb-1">حالة السداد</div>
                                                <span class="badge <?php echo $status_info[1]; ?> rounded-pill px-3"><?php echo $status_info[0]; ?></span>
                                            </div>
                                            <div class="col-6 text-end">
                                                <div class="text-muted small mb-1">العميل</div>
                                                <span class="fw-bold"><?php echo htmlspecialchars($b['customer_full_name'] ?? 'غير معروف'); ?></span>
                                            </div>
                                        </div>

                                        <!-- Financial Summary -->
                                        <div class="bg-white rounded-3 p-3 mb-3">
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <div class="text-muted small">المبلغ</div>
                                                    <div class="fw-bold text-success fs-5"><?php echo number_format($sales_invoice['total_amount'], 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <div class="text-muted small">الخصم</div>
                                                    <div class="fw-bold text-danger"><?php echo number_format($sales_invoice['discount'] ?? 0, 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-12 border-top pt-2 mt-2">
                                                    <div class="text-muted small">الصافي</div>
                                                    <div class="fw-bold text-success fs-4"><?php echo number_format($net_amount, 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="text-muted small">المبلغ الواصل</div>
                                                    <div class="fw-bold text-info fs-5"><?php echo number_format($paid_amount, 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <div class="text-muted small">المتبقي</div>
                                                    <div class="fw-bold text-danger"><?php echo number_format($remaining, 2); ?> ر.س</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Payments Table Header -->
                                        <div class="text-muted small fw-bold mb-2">سجل المدفوعات</div>
                                        <div class="table-responsive bg-white rounded-3">
                                            <table class="table table-sm mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="small">التاريخ</th>
                                                        <th class="small">السند</th>
                                                        <th class="small">المبلغ</th>
                                                        <th class="small">الحالة</th>
                                                        <th class="small">إدارة</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-3">
                                                            <i class="fas fa-info-circle me-1"></i> لا توجد مدفوعات
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Action Buttons -->
                                        <div class="mt-3">
                                            <div class="d-grid gap-2">
                                                <a href="invoice_details.php?id=<?= $sales_invoice['id'] ?>" class="btn btn-sm btn-outline-success rounded-pill">
                                                    <i class="fas fa-eye me-1"></i> عرض الفاتورة الكاملة
                                                </a>
                                                <?php if ($sales_invoice['invoice_status'] == 'draft'): ?>
                                                    <form method="post" action="invoices.php" class="d-inline" onsubmit="return confirm('هل أنت متأكد من ترحيل فاتورة البيع؟')">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="invoice_action" value="post_invoice">
                                                        <input type="hidden" name="invoice_id" value="<?= $sales_invoice['id'] ?>">
                                                        <input type="hidden" name="return_to" value="bus_flight_bookings_details.php?id=<?= $id ?>">
                                                        <button type="submit" class="btn btn-sm btn-primary rounded-pill">
                                                            <i class="fas fa-check-double me-1"></i> ترحيل الفاتورة
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-exclamation-circle fa-2x text-warning mb-2"></i>
                                            <p class="text-muted small mb-3">لم يتم إنشاء فاتورة العميل بعد</p>
                                            <form method="POST" action="bus_flight_bookings_details.php?id=<?= $id ?>" class="d-inline">
                                                <input type="hidden" name="create_missing_invoices" value="1">
                                                <button type="submit" class="btn btn-sm btn-warning rounded-pill">
                                                    <i class="fas fa-plus me-1"></i> إنشاء الفاتورة
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- فاتورة الشراء (تكلفة) -->
                        <div class="col-xl-6">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <!-- Header matching the screenshot design -->
                                <div class="card-header bg-danger text-white py-3 border-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex gap-2 align-items-center">
                                            <span class="badge bg-white text-danger rounded-pill small"><?php echo $purch_invoice['invoice_number'] ?? '---'; ?></span>
                                            <?php if ($purch_invoice && $purch_invoice['invoice_status'] == 'posted'): ?>
                                                <span class="badge bg-warning text-dark rounded-pill small">مرحلة</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark rounded-pill small">مسودة</span>
                                            <?php endif; ?>
                                        </div>
                                        <h6 class="mb-0 fw-bold"><i class="fas fa-shopping-cart me-2"></i>فاتورة الشراء (للمورد)</h6>
                                    </div>
                                </div>
                                <div class="card-body p-4 bg-light">
                                    <?php if ($purch_invoice): ?>
                                        <?php
                                        $payment_status_p = $purch_invoice['payment_status'] ?? 'unpaid';
                                        $status_info_p = $status_labels[$payment_status_p] ?? ['غير معروف', 'bg-secondary'];
                                        $net_amount_p = $purch_invoice['total_amount'] - ($purch_invoice['discount'] ?? 0);
                                        $paid_amount_p = $purch_invoice['purch_amount_received'] ?? 0;
                                        $remaining_p = $purch_invoice['remaining_amount'] ?? ($net_amount_p - $paid_amount_p);
                                        ?>
                                        <!-- Supplier and Payment Status Row -->
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <div class="text-muted small mb-1">حالة السداد للمورد</div>
                                                <span class="badge <?php echo $status_info_p[1]; ?> rounded-pill px-3"><?php echo $status_info_p[0]; ?></span>
                                            </div>
                                            <div class="col-6 text-end">
                                                <div class="text-muted small mb-1">المورد</div>
                                                <span class="fw-bold"><?php echo htmlspecialchars($b['supplier_name'] ?? 'غير معروف'); ?></span>
                                            </div>
                                        </div>

                                        <!-- Financial Summary -->
                                        <div class="bg-white rounded-3 p-3 mb-3">
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <div class="text-muted small">المبلغ</div>
                                                    <div class="fw-bold text-danger fs-5"><?php echo number_format($purch_invoice['total_amount'], 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <div class="text-muted small">الخصم</div>
                                                    <div class="fw-bold text-warning"><?php echo number_format($purch_invoice['discount'] ?? 0, 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-12 border-top pt-2 mt-2">
                                                    <div class="text-muted small">الصافي</div>
                                                    <div class="fw-bold text-danger fs-4"><?php echo number_format($net_amount_p, 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="text-muted small">المبلغ الواصل</div>
                                                    <div class="fw-bold text-info fs-5"><?php echo number_format($paid_amount_p, 2); ?> ر.س</div>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <div class="text-muted small">المتبقي</div>
                                                    <div class="fw-bold text-danger"><?php echo number_format($remaining_p, 2); ?> ر.س</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Payments Table Header -->
                                        <div class="text-muted small fw-bold mb-2">سجل المدفوعات للمورد</div>
                                        <div class="table-responsive bg-white rounded-3">
                                            <table class="table table-sm mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="small">التاريخ</th>
                                                        <th class="small">السند</th>
                                                        <th class="small">المبلغ</th>
                                                        <th class="small">الحالة</th>
                                                        <th class="small">إدارة</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-3">
                                                            <i class="fas fa-info-circle me-1"></i> لا توجد مدفوعات
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Action Buttons -->
                                        <div class="mt-3">
                                            <div class="d-grid gap-2">
                                                <a href="invoice_details.php?id=<?= $purch_invoice['id'] ?>" class="btn btn-sm btn-outline-danger rounded-pill">
                                                    <i class="fas fa-eye me-1"></i> عرض الفاتورة الكاملة
                                                </a>
                                                <?php if ($purch_invoice['invoice_status'] == 'draft'): ?>
                                                    <form method="post" action="invoices.php" class="d-inline" onsubmit="return confirm('هل أنت متأكد من ترحيل فاتورة الشراء؟')">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="invoice_action" value="post_invoice">
                                                        <input type="hidden" name="invoice_id" value="<?= $purch_invoice['id'] ?>">
                                                        <input type="hidden" name="return_to" value="bus_flight_bookings_details.php?id=<?= $id ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning rounded-pill">
                                                            <i class="fas fa-check-double me-1"></i> ترحيل الفاتورة
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-exclamation-circle fa-2x text-warning mb-2"></i>
                                            <p class="text-muted small mb-3">لم يتم إنشاء فاتورة المورد بعد</p>
                                            <form method="POST" action="bus_flight_bookings_details.php?id=<?= $id ?>" class="d-inline">
                                                <input type="hidden" name="create_missing_invoices" value="1">
                                                <button type="submit" class="btn btn-sm btn-warning rounded-pill">
                                                    <i class="fas fa-plus me-1"></i> إنشاء الفاتورة
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!$sales_invoice || !$purch_invoice): ?>
                            <div class="col-12 mt-4 text-center no-print">
                                <form method="POST" onsubmit="return confirm('هل أنت متأكد من رغبتك في إنشاء الفواتير المفقودة وترحيل القيود المحاسبية لهذا الحجز؟');">
                                    <button type="submit" name="create_missing_invoices" class="btn btn-primary rounded-pill px-5 py-2 shadow-sm fw-bold">
                                        <i class="fas fa-cogs me-2"></i> إنشاء وترحيل الفواتير المالية المفقودة
                                    </button>
                                    <div class="small text-muted mt-2">سيتم إنشاء الفواتير (مبيعات أو تكلفة) التي لم تصدر لهذا الحجز بعد</div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- سجل الحالات والملاحظات -->
    <div class="row g-4 mt-1">
        <div class="col-md-8">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-light border-0 py-3">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i> سجل الحالات والتحركات</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center">
                            <thead class="bg-light small text-muted">
                                <tr>
                                    <th class="py-3">الحالة الجديدة</th>
                                    <th>المستخدم</th>
                                    <th>التاريخ والوقت</th>
                                    <th>الملاحظات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?= $log['status_color'] ?> rounded-pill px-3"><?= $log['status_name'] ?></span>
                                        </td>
                                        <td class="small fw-bold text-dark"><?= htmlspecialchars($log['full_name']) ?></td>
                                        <td class="small text-muted"><?= $log['created_at'] ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($log['notes'] ?: '---') ?></td>
                                    </tr>
                                <?php endforeach;
                                if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="4" class="py-4 text-muted small">لا يوجد سجل حالات لهذا الحجز</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-light border-0 py-3">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2 text-primary"></i> معلومات إضافية</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted small d-block">البيان التلقائي</label>
                        <p class="small fw-bold text-dark bg-light p-3 rounded-3 border-start border-primary border-4"><?= htmlspecialchars($b['description'] ?: 'لا يوجد بيان') ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="text-muted small d-block">ملاحظات إضافية</label>
                        <p class="small text-muted"><?= htmlspecialchars($b['notes'] ?: 'لا توجد ملاحظات') ?></p>
                    </div>
                    <div class="mb-0 pt-3 border-top">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>المنشئ:</span>
                            <span class="fw-bold"><?= htmlspecialchars($b['created_by_user_full_name']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted">
                            <span>تاريخ الإنشاء:</span>
                            <span class="fw-bold"><?= $b['created_at'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {

        .btn-group,
        .no-print {
            display: none !important;
        }

        .container-fluid {
            width: 100%;
            padding: 0 !important;
        }

        .card {
            box-shadow: none !important;
            border: 1px solid #eee !important;
        }

        .bg-primary,
        .bg-success,
        .bg-warning {
            background-color: #f8f9fa !important;
            color: #000 !important;
            border: 1px solid #ddd !important;
        }
    }

    .workflow-progress-line {
        position: absolute;
        width: calc(100% - 80px);
        height: 3px;
        background-color: #e9ecef;
        top: 20px;
        left: 40px;
        z-index: 0;
    }

    .workflow-step-item {
        flex: 1;
        z-index: 1;
    }

    .workflow-dot {
        border: 2px solid #fff;
    }
</style>
</div>
<?php require_once 'footer.php'; ?>
