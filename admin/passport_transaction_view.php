<?php
ob_start();
require_once 'header.php';

if (!isset($_GET['id'])) {
    header('Location: passport_transactions.php');
    exit();
}

$id = (int)$_GET['id'];

// دالة لجلب تفاصيل فاتورة كاملة مع حساباتها وسدادها
function getFullInvoiceData($pdo, $inv_num)
{
    $stmt = $pdo->prepare("
        SELECT i.*, b.branch_name, c.currency_name, c.currency_symbol, c.exchange_rate,
               u.full_name as creator_name,
               p.full_name as poster_name,
               coa_pay.account_name_ar as payment_account_name
        FROM invoices i
        JOIN branches b ON i.branch_id = b.id
        JOIN currencies c ON i.currency_id = c.id
        LEFT JOIN users u ON i.created_by = u.id
        LEFT JOIN users p ON i.posted_by = p.id
        LEFT JOIN unified_accounts coa_pay ON i.account_id = coa_pay.id
        WHERE i.invoice_number = ?
    ");
    $stmt->execute([$inv_num]);
    $inv = $stmt->fetch();

    if (!$inv) return null;

    $is_sales_invoice = ($inv['invoice_category'] == 'sales');
    $target_transaction_type = $is_sales_invoice ? 'receipt' : 'payment';

    // 1. حساب المبلغ الابتدائي (من القيد المحاسبي المباشر للفاتورة)
    $stmt_init = $pdo->prepare("
        SELECT ft.id, ft.transaction_number, ft.transaction_date, ft.posted_at, ft.amount, u.full_name as creator_name
        FROM financial_transactions ft
        LEFT JOIN users u ON ft.created_by = u.id
        WHERE ft.reference_id = ? AND ft.reference_type = 'invoice' AND ft.status = 'posted'
        LIMIT 1
    ");
    $stmt_init->execute([$inv['id']]);
    $init_ft = $stmt_init->fetch();

    // المبلغ الابتدائي هو ما تم دفعه عند إنشاء الفاتورة (مخزن في القيد المباشر)
    // في نظامنا، مبلغ القيد المباشر (ft.amount) هو إجمالي الفاتورة، ولكننا نريد فقط الجزء الذي دخل الصندوق
    $initial_paid = 0;
    if ($init_ft) {
        $stmt_init_paid = $pdo->prepare("
            SELECT SUM(debit) FROM journal_lines
            WHERE financial_transaction_id = ?
            AND account_id IN (
                SELECT id FROM unified_accounts
                WHERE account_type IN ('box', 'bank')
            )
        ");
        $stmt_init_paid->execute([$init_ft['id']]);
        $initial_paid = (float)$stmt_init_paid->fetchColumn();
    }

    // 2. جلب سجل التحصيلات/المدفوعات اللاحقة
    // لفواتير البيع: سندات قبض (receipt)
    // لفواتير الشراء: سندات صرف (payment)
    $stmt_payments = $pdo->prepare("
        SELECT 
            COALESCE(pa.allocated_amount, ft.amount) as allocated_amount,
            ft.transaction_number,
            ft.transaction_date,
            ft.posted_at,
            u.full_name as payment_by,
            ft.status as payment_status,
            ft.id as voucher_id,
            ft.amount as payment_total_amount,
            curr.currency_symbol as payment_currency_symbol,
            ? as transaction_type
        FROM financial_transactions ft
        LEFT JOIN (
            SELECT 
                financial_transaction_id,
                invoice_id,
                SUM(allocated_amount) as allocated_amount
            FROM payment_allocations
            GROUP BY financial_transaction_id, invoice_id
        ) pa ON ft.id = pa.financial_transaction_id AND pa.invoice_id = ?
        LEFT JOIN currencies curr ON ft.currency_id = curr.id
        LEFT JOIN users u ON ft.created_by = u.id
        WHERE (
               pa.invoice_id = ?
               OR (ft.reference_id = ? AND ft.reference_type = ? AND ft.transaction_type = ?)
              )
        AND ft.status IN ('posted', 'draft')
        AND ft.transaction_type = ?
        GROUP BY ft.id
        ORDER BY ft.transaction_date DESC
    ");
    $stmt_payments->execute([
        $target_transaction_type,
        $inv['id'],
        $inv['id'],
        $inv['source_id'],
        $inv['source_type'],
        $target_transaction_type,
        $target_transaction_type
    ]);
    $payments_res = $stmt_payments->fetchAll();

    // إزالة التكرارات (تأكيد إضافي)
    $seen_vouchers = [];
    $unique_payments = [];
    foreach ($payments_res as $p) {
        $voucher_id = $p['voucher_id'];
        if (!in_array($voucher_id, $seen_vouchers)) {
            $seen_vouchers[] = $voucher_id;
            $unique_payments[] = $p;
        }
    }
    $payments_res = $unique_payments;

        // تصحيح المبالغ الموزعة للسندات المرتبطة بالمصدر مباشرة
        foreach ($payments_res as &$p) {
            if (empty($p['allocated_amount'])) {
                $p['allocated_amount'] = $p['payment_total_amount'];
            }
        }

        $inv['payments'] = $payments_res;

        // 3. إضافة السداد الابتدائي إلى القائمة فقط إذا لم يكن موجودًا بالفعل في payments
        $initial_already_exists = false;
        foreach ($inv['payments'] as $p) {
            if (isset($p['voucher_id']) && $init_ft && $p['voucher_id'] == $init_ft['id']) {
                $initial_already_exists = true;
                break;
            }
        }

        if ($initial_paid > 0 && $init_ft && !$initial_already_exists) {
            array_unshift($inv['payments'], [
                'allocated_amount' => $initial_paid,
                'transaction_number' => $init_ft['transaction_number'],
                'transaction_date' => $init_ft['transaction_date'],
                'posted_at' => $init_ft['posted_at'],
                'payment_by' => $init_ft['creator_name'] ?? $inv['creator_name'],
                'payment_status' => 'posted',
                'voucher_id' => $init_ft['id'],
                'payment_total_amount' => $init_ft['amount'],
                'payment_currency_symbol' => $inv['currency_symbol'],
                'transaction_type' => 'initial'
            ]);
        }

    // 4. الإجمالي المستلم = الابتدائي + اللاحق (المرحل وغير المرحل)
    $extra_received = 0;
    $extra_received_posted = 0;
    $extra_received_unposted = 0;

    foreach($inv['payments'] as $p) {
        if (isset($p['transaction_type']) && $p['transaction_type'] != 'initial') {
            $extra_received += (float)$p['allocated_amount'];
            if ($p['payment_status'] == 'posted') {
                $extra_received_posted += (float)$p['allocated_amount'];
            } else {
                $extra_received_unposted += (float)$p['allocated_amount'];
            }
        }
    }
    $inv['calculated_amount_received'] = $initial_paid + $extra_received_posted;
    $inv['total_allocated_including_unposted'] = $initial_paid + $extra_received;

    return $inv;
}

// دالة لجلب تفاصيل القيد المحاسبي (Revenue, Cost, Profit Accounts)
function getJournalEntryDetails($pdo, $sale_id, $pur_id)
{
    $ids = array_filter([(int)$sale_id, (int)$pur_id]);
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT
            ft.id as transaction_id,
            ft.transaction_number,
            ft.transaction_date,
            ft.amount as total_amount,
            ft.status,
            coa.account_code,
            coa.account_name_ar,
            jl.debit,
            jl.credit,
            jl.currency_id,
            curr.currency_symbol,
            coa.account_type
        FROM financial_transactions ft
        JOIN journal_lines jl ON ft.id = jl.financial_transaction_id
        JOIN unified_accounts coa ON jl.account_id = coa.id
        LEFT JOIN currencies curr ON jl.currency_id = curr.id
        WHERE ft.reference_type = 'invoice'
        AND ft.reference_id IN ($placeholders)
        AND ft.status = 'posted'
        ORDER BY ft.transaction_date DESC, coa.account_code
    ");
    $stmt->execute(array_values($ids));
    return $stmt->fetchAll();
}

// Fetch transaction details
$stmt = $pdo->prepare("
    SELECT pt.*,
           inv.id AS invoice_id,
           inv.invoice_number,
           inv.total_amount AS gross_sale_price,
           inv.discount AS discount,
           inv.net_amount AS sale_price,
           
           -- حساب المبلغ المقبوض بشكل ديناميكي (مطابق لنظام الفواتير)
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
                 ), 0) +
                 -- إضافة المبالغ المسجلة مباشرة على المعاملة (إذا وجدت)
                 IFNULL((
                     SELECT SUM(ft_pt.amount)
                     FROM financial_transactions ft_pt
                     WHERE ft_pt.reference_id = pt.id AND ft_pt.reference_type = 'passport_transaction' AND ft_pt.transaction_type = 'receipt' AND ft_pt.status = 'posted'
                 ), 0)
            ) AS amount_received,

            -- بيانات فاتورة الشراء
            pur.id AS purchase_invoice_id,
            pur.invoice_number as purchase_invoice_number,
            COALESCE(pur.total_amount, inv.cost_amount) AS purchase_price,

           inv.currency_id AS currency_id,
           inv.delivery_type AS payment_type,
           COALESCE(pur.supplier_id, inv.supplier_id) as supplier_id,
           COALESCE(sup.supplier_name, sup2.supplier_name) as supplier_name,
           s.status_name, s.status_color,
           c.currency_name, c.currency_symbol,
           b.branch_name,
           a.agent_name,
           u.full_name as created_by_name,
           fc.city_name as from_city_name,
           tc.city_name as to_city_name,
           cust.full_name as customer_name,
           cust.phone as customer_phone
    FROM passport_transactions pt
    LEFT JOIN invoices inv
        ON inv.source_type = 'passport_transaction'
       AND inv.source_id = pt.id
       AND inv.invoice_category = 'sales'
    LEFT JOIN invoices pur
        ON pur.source_type = 'passport_transaction'
       AND pur.source_id = pt.id
       AND pur.invoice_category = 'purchase'
    LEFT JOIN suppliers sup ON inv.supplier_id = sup.id
    LEFT JOIN suppliers sup2 ON pur.supplier_id = sup2.id
    LEFT JOIN statuses s ON pt.status_id = s.id
    LEFT JOIN currencies c ON inv.currency_id = c.id
    LEFT JOIN currencies cpur ON pur.currency_id = cpur.id
    LEFT JOIN branches b ON pt.branch_id = b.id
    LEFT JOIN agents a ON pt.agent_id = a.id
    LEFT JOIN users u ON pt.created_by = u.id
    LEFT JOIN cities fc ON pt.from_city_id = fc.id
    LEFT JOIN cities tc ON pt.to_city_id = tc.id
    LEFT JOIN customers cust ON pt.customer_id = cust.id
    WHERE pt.id = ?
");
$stmt->execute([$id]);
$trx = $stmt->fetch();

// إعادة حساب المتبقي والربح بناءً على المبلغ المقبوض المحسوب
if ($trx) {
    $trx['remaining_amount'] = $trx['sale_price'] - $trx['amount_received'];
    
    // الربح (مطابق لـ invoices.php)
    // نحتاج لجلب سعر التكلفة من فاتورة الشراء أو الحقل المسجل
    $cost_in_sale_currency = $trx['purchase_price']; // تم جلبه في الاستعلام الأصلي
    // (ملاحظة: الربح في الاستعلام الأصلي كان يعتمد على cost_amount، سنتركه يعتمد على ما تم جلبه)
    $trx['profit'] = $trx['sale_price'] - $cost_in_sale_currency;
}

// جلب الفواتير المرتبطة بالكامل
$sale_inv = null;
$pur_inv = null;
if ($trx['invoice_number']) {
    $sale_inv = getFullInvoiceData($pdo, $trx['invoice_number']);
}
if ($trx['purchase_invoice_number']) {
    $pur_inv = getFullInvoiceData($pdo, $trx['purchase_invoice_number']);
}

// جلب تفاصيل القيد المحاسبي
$journal_details = getJournalEntryDetails($pdo, $sale_inv['id'] ?? 0, $pur_inv['id'] ?? 0);

// حساب المجاميع
$revenue_accounts = array_filter($journal_details, function ($j) {
    return $j['account_type'] == 'revenue';
});
$cost_accounts = array_filter($journal_details, function ($j) {
    return $j['account_type'] == 'expense';
});
$cash_accounts = array_filter($journal_details, function ($j) {
    return $j['account_type'] == 'box' || $j['account_type'] == 'bank';
});

$total_revenue = array_sum(array_column($revenue_accounts, 'credit'));
$total_cost = array_sum(array_column($cost_accounts, 'debit'));
$total_cash_received = array_sum(array_column($cash_accounts, 'debit'));

// Fallback للبيانات التقديرية إذا لم يتم الترحيل بعد (Draft)
if ($total_revenue == 0 && $sale_inv) {
    $total_revenue = (float)$sale_inv['total_amount'] - (float)$sale_inv['discount'];
}
if ($total_cost == 0 && $pur_inv) {
    $total_cost = (float)$pur_inv['total_amount'];
} elseif ($total_cost == 0 && $sale_inv && $sale_inv['cost_amount'] > 0) {
    $total_cost = (float)$sale_inv['cost_amount'];
}

if ($total_cash_received == 0) {
    $total_cash_received = ($trx['amount_received'] ?? 0);
}

$net_diff = $total_revenue - $total_cost;
$net_profit = $net_diff > 0 ? $net_diff : 0;
$net_loss = $net_diff < 0 ? abs($net_diff) : 0;

if (!$trx) {
    header('Location: passport_transactions.php');
    exit();
}

// Fetch status logs
$stmt_logs = $pdo->prepare("
    SELECT ptl.*, s.status_name, s.status_color, u.full_name
    FROM passport_transaction_logs ptl
    JOIN statuses s ON ptl.status_id = s.id
    JOIN users u ON ptl.changed_by = u.id
    WHERE ptl.transaction_id = ?
    ORDER BY ptl.created_at DESC
");
$stmt_logs->execute([$id]);
$logs = $stmt_logs->fetchAll();

// Fetch payments (receipts - using new financial transaction system)
$stmt_payments = $pdo->prepare("
    SELECT DISTINCT 
        ft.*, 
        u.full_name as user_name, 
        coa.account_name_ar as account_name,
        ft.transaction_number as receipt_number,
        ft.transaction_date as date
    FROM financial_transactions ft
    JOIN users u ON ft.created_by = u.id
    JOIN journal_lines jl ON ft.id = jl.financial_transaction_id AND jl.debit > 0
    JOIN unified_accounts coa ON jl.account_id = coa.id
    LEFT JOIN payment_allocations pa ON ft.id = pa.financial_transaction_id
    WHERE (
        (ft.reference_id = ? AND ft.reference_type = 'passport_transaction')
        OR 
        (ft.reference_id = ? AND ft.reference_type = 'invoice')
        OR 
        (pa.invoice_id = ?)
    )
    AND ft.transaction_type = 'receipt'
    AND ft.status = 'posted'
    ORDER BY ft.transaction_date DESC, ft.created_at DESC
");
$stmt_payments->execute([$id, $trx['invoice_id'], $trx['invoice_id']]);
$payments = $stmt_payments->fetchAll();

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    $new_status_id = (int)$_POST['new_status_id'];
    $notes = htmlspecialchars($_POST['status_notes'] ?? '');

    if (has_permission('passport_transactions_change_status')) {
        $stmt_upd = $pdo->prepare("UPDATE passport_transactions SET status_id = ? WHERE id = ?");
        if ($stmt_upd->execute([$new_status_id, $id])) {
            // Log change
            $stmt_log = $pdo->prepare("INSERT INTO passport_transaction_logs (transaction_id, status_id, changed_by, notes) VALUES (?, ?, ?, ?)");
            $stmt_log->execute([$id, $new_status_id, $_SESSION['admin_id'], $notes]);

            $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم تغيير حالة المعاملة بنجاح.'];
            header("Location: passport_transaction_view.php?id=$id");
            exit();
        }
    }
}

// Workflow transitions
$workflow = get_workflow_for_transaction('passport_transactions', $trx['branch_id']);
$allowed_transitions = [];
if ($workflow) {
    $stmt_curr_step = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ? LIMIT 1");
    $stmt_curr_step->execute([$workflow['id'], $trx['status_id']]);
    $current_step_id = $stmt_curr_step->fetchColumn();

    if ($current_step_id) {
        $allowed_transitions = get_allowed_transitions($workflow['id'], $current_step_id, $_SESSION['role_id'] ?? null, $_SESSION['admin_id']);
    }
}

?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-passport me-2"></i> تفاصيل معاملة الجوازات</h3>
            <p class="text-muted small mb-0">معاملة رقم: <?php echo htmlspecialchars($trx['transaction_number']); ?></p>
        </div>
        <div class="d-flex gap-2">
            <?php if (has_permission('passport_transactions_print')): ?>
                <a href="passport_transaction_print.php?id=<?php echo $id; ?>" target="_blank" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
                    <i class="fas fa-print me-1"></i> طباعة
                </a>
            <?php endif; ?>
            <?php if (has_permission('passport_transactions_edit')): ?>
                <a href="passport_transaction_edit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary rounded-pill px-3 shadow-sm">
                    <i class="fas fa-edit me-1"></i> تعديل
                </a>
            <?php endif; ?>
            <a href="passport_transactions.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                <i class="fas fa-arrow-right me-1"></i> عودة للقائمة
            </a>
        </div>
    </div>

    <!-- ملخص العملية (الربح) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="fas fa-chart-bar text-primary me-2"></i> ملخص الحسابات (Financial Summary)</h5>
                    <div class="text-end">
                        <span class="badge bg-light text-primary rounded-pill px-3 py-2 border">
                            <i class="fas fa-tag me-1"></i>
                            معاملة جوازات
                        </span>
                        <div class="mt-2 small text-muted"><?php echo $trx['operation_date']; ?></div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <!-- بطاقة الإيرادات -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 bg-success text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-file-invoice-dollar fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1">الإيرادات (Revenue)</small>
                                <h4 class="fw-bold mb-2"><?php echo number_format($total_revenue, 2); ?> <small class="fs-6"><?php echo $trx['currency_symbol'] ?? ''; ?></small></h4>
                                <?php if ($sale_inv && $sale_inv['discount'] > 0): ?>
                                    <div class="p-1 bg-white bg-opacity-25 rounded-3 extra-small">
                                        شامل خصم: <?php echo number_format($sale_inv['discount'], 2); ?>
                                    </div>
                                <?php elseif (!empty($revenue_accounts)): ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small text-truncate">
                                        <?php echo htmlspecialchars($revenue_accounts[array_key_first($revenue_accounts)]['account_name_ar']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- بطاقة التكاليف -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 bg-danger text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-hand-holding-usd fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1">التكاليف (Cost)</small>
                                <h4 class="fw-bold mb-2"><?php echo number_format($total_cost, 2); ?> <small class="fs-6"><?php echo $trx['currency_symbol'] ?? ''; ?></small></h4>
                                <?php if (!empty($cost_accounts)): ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small text-truncate">
                                        <?php echo htmlspecialchars($cost_accounts[array_key_first($cost_accounts)]['account_name_ar']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- بطاقة الصناديق (المبلغ الواصل) -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 bg-info text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-cash-register fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1">الواصل (الصناديق)</small>
                                <h4 class="fw-bold mb-2"><?php echo number_format($total_cash_received, 2); ?> <small class="fs-6"><?php echo $trx['currency_symbol'] ?? ''; ?></small></h4>
                                <?php if (!empty($cash_accounts)): ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small text-truncate">
                                        <?php echo htmlspecialchars($cash_accounts[array_key_first($cash_accounts)]['account_name_ar']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small">
                                        لم يتم استلام مبالغ
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- بطاقة الأرباح -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 <?php echo $net_diff >= 0 ? 'bg-primary' : 'bg-dark'; ?> text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-chart-line fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1"><?php echo $net_diff >= 0 ? 'الأرباح (Profit)' : 'الخسارة (Loss)'; ?></small>
                                <h4 class="fw-bold mb-2"><?php echo number_format(abs($net_diff), 2); ?> <small class="fs-6"><?php echo $trx['currency_symbol'] ?? ''; ?></small></h4>
                                <div class="p-2 bg-white bg-opacity-25 rounded-3 small">
                                    <?php echo $net_diff >= 0 ? 'ربح صافي' : 'خسارة محققة'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Main Info -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-info-circle me-2"></i> البيانات الأساسية</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-3">
                                <label class="text-muted small d-block mb-1">اسم المسافر</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['full_name']); ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-3">
                                <label class="text-muted small d-block mb-1">رقم الهاتف</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['phone_number']); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-3">
                                <label class="text-muted small d-block mb-1">تاريخ الميلاد</label>
                                <span class="fw-bold"><?php echo $trx['date_of_birth'] ?: 'غير محدد'; ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-3">
                                <label class="text-muted small d-block mb-1">مكان الميلاد</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['place_of_birth'] ?: 'غير محدد'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-3">
                                <label class="text-muted small d-block mb-1">نوع الهوية</label>
                                <span class="fw-bold"><?php echo $trx['id_type'] == 'passport' ? 'جواز سفر' : 'هوية وطنية'; ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-3">
                                <label class="text-muted small d-block mb-1">خط السير</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['from_city_name']); ?> <i class="fas fa-long-arrow-alt-left mx-2 text-primary"></i> <?php echo htmlspecialchars($trx['to_city_name']); ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-3">
                                <label class="text-muted small d-block mb-1">تاريخ العملية</label>
                                <span class="fw-bold"><?php echo $trx['operation_date']; ?></span>
                            </div>
                        </div>
                        <?php if ($trx['customer_name'] || $trx['agent_name']): ?>
                        <div class="col-md-12">
                            <div class="p-3 bg-light rounded-3 border-start border-4 border-primary">
                                <label class="text-muted small d-block mb-1"><?php echo $trx['agent_id'] ? 'الوكيل المتعاقد' : 'العميل (صاحب الطلب)'; ?></label>
                                <span class="fw-bold fs-6">
                                    <i class="fas <?php echo $trx['agent_id'] ? 'fa-user-tie' : 'fa-user'; ?> me-2 text-primary"></i>
                                    <?php echo htmlspecialchars($trx['agent_name'] ?: $trx['customer_name']); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($trx['transaction_type'] != 'passport_only'): ?>
                        <hr class="my-4 opacity-50">
                        <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-id-card me-2"></i> تفاصيل البطاقة</h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="text-muted small d-block">رقم المعاملة</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['card_transaction_number'] ?: '-'); ?></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted small d-block">التاريخ</label>
                                <span class="fw-bold"><?php echo $trx['card_transaction_date'] ?: '-'; ?></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted small d-block">رقم البطاقة</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['card_number'] ?: '-'); ?></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted small d-block">تاريخ الإصدار</label>
                                <span class="fw-bold"><?php echo $trx['card_issue_date'] ?: '-'; ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($trx['transaction_type'] != 'card_only'): ?>
                        <hr class="my-4 opacity-50">
                        <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-passport me-2"></i> تفاصيل الجواز</h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="text-muted small d-block">رقم المعاملة</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['passport_transaction_number'] ?: '-'); ?></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted small d-block">التاريخ</label>
                                <span class="fw-bold"><?php echo $trx['passport_transaction_date'] ?: '-'; ?></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted small d-block">رقم الجواز</label>
                                <span class="fw-bold"><?php echo htmlspecialchars($trx['passport_number'] ?: '-'); ?></span>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted small d-block">تاريخ الإصدار</label>
                                <span class="fw-bold"><?php echo $trx['passport_issue_date'] ?: '-'; ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial History -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-success"><i class="fas fa-file-invoice-dollar me-2"></i> السجل المالي والدفعات</h5>
                    <?php if (has_permission('passport_transactions_collect_payment') && $trx['remaining_amount'] > 0): ?>
                        <button class="btn btn-success btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#paymentModal">
                            <i class="fas fa-plus me-1"></i> تسجيل دفعة
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">رقم السند</th>
                                    <th>التاريخ</th>
                                    <th>الحساب</th>
                                    <th>المبلغ</th>
                                    <th>المستخدم</th>
                                    <th class="pe-4">البيان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted small">لا توجد دفعات مسجلة حالياً</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold small"><?php echo htmlspecialchars($p['receipt_number']); ?></td>
                                            <td class="small"><?php echo $p['date']; ?></td>
                                            <td class="small"><?php echo htmlspecialchars($p['account_name']); ?></td>
                                            <td class="fw-bold text-success small"><?php echo number_format($p['amount'], 2); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($p['user_name']); ?></td>
                                            <td class="pe-4 extra-small"><?php echo htmlspecialchars($p['description']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div class="col-lg-4">
            <!-- Status Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body text-center p-4">
                    <div class="mb-3">
                        <span class="badge bg-<?php echo $trx['status_color'] ?: 'primary'; ?> bg-opacity-10 text-<?php echo $trx['status_color'] ?: 'primary'; ?> rounded-pill px-4 py-2 fs-6">
                            <?php echo htmlspecialchars($trx['status_name']); ?>
                        </span>
                    </div>
                    <h6 class="text-muted small mb-3">الحالة الحالية للمعاملة</h6>

                    <?php if (!empty($allowed_transitions)): ?>
                        <div class="d-grid gap-2">
                            <?php foreach ($allowed_transitions as $trans): ?>
                                <button class="btn btn-outline-<?php echo $trans['color'] ?: 'primary'; ?> rounded-pill"
                                    data-bs-toggle="modal" data-bs-target="#statusModal<?php echo $trans['to_step_id']; ?>">
                                    <i class="fas fa-random me-1"></i> نقل إلى: <?php echo htmlspecialchars($trans['to_step_name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Financial Summary Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 bg-primary text-white">
                <div class="card-body p-4">
                    <h6 class="mb-4 fw-bold opacity-75">الملخص المالي</h6>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="opacity-75">سعر البيع:</span>
                        <span class="fw-bold"><?php echo number_format($trx['sale_price'], 2); ?> <?php echo $trx['currency_symbol']; ?></span>
                    </div>
                    <?php if ($trx['discount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="opacity-75 text-warning">الخصم:</span>
                        <span class="fw-bold text-warning"><?php echo number_format($trx['discount'], 2); ?> <?php echo $trx['currency_symbol']; ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="opacity-75">المبلغ المقبوض:</span>
                        <span class="fw-bold text-info"><?php echo number_format($trx['amount_received'], 2); ?> <?php echo $trx['currency_symbol']; ?></span>
                    </div>
                    <?php if ($trx['agent_name'] || $trx['customer_name']): ?>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="opacity-75">الجهة الدافعة:</span>
                        <span class="fw-bold small"><?php echo htmlspecialchars($trx['agent_name'] ?: $trx['customer_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($trx['supplier_name']): ?>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="opacity-75">سعر التكلفة:</span>
                        <span class="fw-bold text-white-50"><?php echo number_format($trx['purchase_price'], 2); ?> <?php echo $trx['currency_symbol']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="opacity-75">المورد:</span>
                        <span class="fw-bold small"><?php echo htmlspecialchars($trx['supplier_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <hr class="opacity-25">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fs-5">المتبقي:</span>
                        <span class="fs-4 fw-bold text-warning"><?php echo number_format($trx['remaining_amount'], 2); ?> <?php echo $trx['currency_symbol']; ?></span>
                    </div>
                    <?php if ($trx['profit'] != 0): ?>
                    <div class="mt-3 pt-3 border-top border-white border-opacity-10 d-flex justify-content-between align-items-center">
                        <span class="small opacity-75">صافي الربح التقديري:</span>
                        <span class="fw-bold text-success"><?php echo number_format($trx['profit'], 2); ?> <?php echo $trx['currency_symbol']; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Timeline -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-history me-2"></i> سجل التحركات</h6>
                </div>
                <div class="card-body p-0">
                    <div class="timeline p-4">
                        <?php foreach ($logs as $l): ?>
                            <div class="timeline-item position-relative ps-4 pb-4 border-start border-2 border-light">
                                <div class="timeline-dot position-absolute top-0 start-0 translate-middle bg-<?php echo $l['status_color'] ?: 'primary'; ?> rounded-circle" style="width: 12px; height: 12px; left: -1px !important; margin-top: 6px;"></div>
                                <div class="small fw-bold mb-1 text-<?php echo $l['status_color'] ?: 'primary'; ?>"><?php echo htmlspecialchars($l['status_name']); ?></div>
                                <div class="extra-small text-muted mb-2"><i class="fas fa-user-edit me-1"></i> <?php echo htmlspecialchars($l['full_name']); ?> | <i class="fas fa-clock me-1"></i> <?php echo date('Y-m-d H:i', strtotime($l['created_at'])); ?></div>
                                <?php if (isset($l['notes']) && !empty($l['notes'])): ?>
                                    <div class="p-2 bg-light rounded-2 extra-small"><?php echo htmlspecialchars($l['notes'] ?? ''); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- قسم حسابات الأرباح والتكاليف والإيرادات -->
    <?php if (!empty($journal_details) && ($sale_inv['invoice_status'] == 'posted' || $pur_inv['invoice_status'] == 'posted')): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="mt-3">
                    <h5 class="fw-bold mb-3"><i class="fas fa-book me-2 text-primary"></i>تفاصيل القيود المحاسبية (Journal Entries)</h5>

                    <?php
                    $grouped_entries = [];
                    foreach ($journal_details as $jd) {
                        $grouped_entries[$jd['transaction_number']][] = $jd;
                    }

                    foreach ($grouped_entries as $trx_num => $lines):
                        $trx_debit = array_sum(array_column($lines, 'debit'));
                        $trx_credit = array_sum(array_column($lines, 'credit'));
                    ?>
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-light py-3 border-0 d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold">
                                    رقم القيد: <span class="badge bg-primary rounded-pill px-3"><?php echo $trx_num; ?></span>
                                    <span class="ms-3 text-muted small"><i class="far fa-calendar-alt me-1"></i><?php echo $lines[0]['transaction_date']; ?></span>
                                </h6>
                                <div class="small fw-bold">
                                    الحالة: <span class="text-success"><i class="fas fa-check-circle me-1"></i>مرحل</span>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 align-middle">
                                        <thead class="bg-light">
                                            <tr>
                                                <th class="ps-4" style="width: 40%;"><i class="fas fa-money-check-alt me-2"></i>الحساب</th>
                                                <th class="text-center"><i class="fas fa-tags me-2"></i>النوع</th>
                                                <th class="text-end" style="width: 15%;"><i class="fas fa-arrow-alt-circle-down me-2"></i>مدين (Debit)</th>
                                                <th class="text-end" style="width: 15%;"><i class="fas fa-arrow-alt-circle-up me-2"></i>دائن (Credit)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lines as $jd): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($jd['account_name_ar']); ?></div>
                                                        <small class="text-muted"><?php echo $jd['account_code']; ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php
                                                        $badge_color = match ($jd['account_type']) {
                                                            'revenue' => 'success',
                                                            'expense' => 'danger',
                                                            'box', 'bank' => 'info',
                                                            'receivable' => 'primary',
                                                            'payable' => 'warning',
                                                            default => 'secondary'
                                                        };
                                                        $type_label = match ($jd['account_type']) {
                                                            'revenue' => 'إيراد',
                                                            'expense' => 'تكلفة',
                                                            'box' => 'صندوق',
                                                            'bank' => 'بنك',
                                                            'receivable' => 'العملاء',
                                                            'payable' => 'الموردين',
                                                            default => $jd['account_code'] ?? 'أخرى'
                                                        };
                                                        ?>
                                                        <span class="badge bg-<?php echo $badge_color; ?> rounded-pill px-3 py-2">
                                                            <?php echo $type_label; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end fw-bold <?php echo $jd['debit'] > 0 ? 'text-primary' : 'text-muted'; ?>">
                                                        <?php echo $jd['debit'] > 0 ? number_format($jd['debit'], 2) : '-'; ?>
                                                    </td>
                                                    <td class="text-end fw-bold <?php echo $jd['credit'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                                        <?php echo $jd['credit'] > 0 ? number_format($jd['credit'], 2) : '-'; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="bg-light fw-bold border-top">
                                            <tr>
                                                <td colspan="2" class="ps-4">إجمالي القيد</td>
                                                <td class="text-end text-primary"><?php echo number_format($trx_debit, 2); ?></td>
                                                <td class="text-end text-success"><?php echo number_format($trx_credit, 2); ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- فاتورة البيع (إيراد) -->
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-success text-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i>فاتورة البيع (للعميل)</h6>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if ($sale_inv && $sale_inv['invoice_status'] == 'posted'): ?>
                            <span class="badge bg-white text-success rounded-pill px-3"><i class="fas fa-check-double me-1"></i>مرحلة</span>
                        <?php else: ?>
                            <span class="badge bg-white text-warning rounded-pill px-3"><i class="fas fa-file-alt me-1"></i>مسودة</span>
                        <?php endif; ?>
                        <span class="badge bg-white text-success rounded-pill"><?php echo $sale_inv['invoice_number'] ?? 'غير موجودة'; ?></span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if ($sale_inv): ?>
                        <div class="row g-3 mb-4">
                            <div class="col-12 text-center">
                                <h4 class="fw-bold text-success mb-2"><?php echo $trx['currency_symbol'] . ' ' . number_format($sale_inv['net_amount'], 2); ?></h4>
                                <small class="text-muted">إجمالي الفاتورة</small>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">تاريخ الفاتورة</small>
                                <span class="fw-bold"><?php echo $sale_inv['invoice_date']; ?></span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">الفرع</small>
                                <span class="fw-bold"><?php echo $sale_inv['branch_name']; ?></span>
                            </div>
                            <div class="col-12">
                                <hr class="my-3">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">الإجمالي:</span>
                                    <span class="fw-bold"><?php echo $trx['currency_symbol'] . ' ' . number_format($sale_inv['total_amount'], 2); ?></span>
                                </div>
                                <?php if ($sale_inv['discount'] > 0): ?>
                                    <div class="d-flex justify-content-between text-danger">
                                        <span>الخصم:</span>
                                        <span><?php echo $trx['currency_symbol'] . ' ' . number_format($sale_inv['discount'], 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between fw-bold">
                                    <span>الصافي:</span>
                                    <span class="text-success"><?php echo $trx['currency_symbol'] . ' ' . number_format($sale_inv['net_amount'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if (isset($sale_inv['notes']) && !empty($sale_inv['notes'])): ?>
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-muted d-block mb-1">ملاحظات:</small>
                                <?php echo htmlspecialchars($sale_inv['notes'] ?? ''); ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-file-invoice-dollar fa-3x opacity-25 mb-3"></i>
                            <div>لم يتم إنشاء فاتورة البيع بعد</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- فاتورة الشراء (تكلفة) -->
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-danger text-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-file-invoice me-2"></i>فاتورة الشراء (للمورد)</h6>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if ($pur_inv && $pur_inv['invoice_status'] == 'posted'): ?>
                            <span class="badge bg-white text-danger rounded-pill px-3"><i class="fas fa-check-double me-1"></i>مرحلة</span>
                        <?php else: ?>
                            <span class="badge bg-white text-warning rounded-pill px-3"><i class="fas fa-file-alt me-1"></i>مسودة</span>
                        <?php endif; ?>
                        <span class="badge bg-white text-danger rounded-pill"><?php echo $pur_inv['invoice_number'] ?? 'غير موجودة'; ?></span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if ($pur_inv): ?>
                        <div class="row g-3 mb-4">
                            <div class="col-12 text-center">
                                <h4 class="fw-bold text-danger mb-2"><?php echo $trx['currency_symbol'] . ' ' . number_format($pur_inv['total_amount'], 2); ?></h4>
                                <small class="text-muted">إجمالي الفاتورة</small>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">تاريخ الفاتورة</small>
                                <span class="fw-bold"><?php echo $pur_inv['invoice_date']; ?></span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">الفرع</small>
                                <span class="fw-bold"><?php echo $pur_inv['branch_name']; ?></span>
                            </div>
                        </div>
                        <?php if (isset($pur_inv['notes']) && !empty($pur_inv['notes'])): ?>
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-muted d-block mb-1">ملاحظات:</small>
                                <?php echo htmlspecialchars($pur_inv['notes'] ?? ''); ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-file-invoice fa-3x opacity-25 mb-3"></i>
                            <div>لم يتم إنشاء فاتورة الشراء بعد</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Modals -->
<?php foreach ($allowed_transitions as $trans): ?>
    <div class="modal fade" id="statusModal<?php echo $trans['to_step_id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-<?php echo $trans['color'] ?: 'primary'; ?> text-white py-3 rounded-top-4">
                    <h5 class="modal-title fw-bold">تغيير حالة المعاملة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="process_passport_transaction.php" method="POST">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="status_id" value="<?php echo $trans['to_status_id'] ?? $trans['to_step_id']; ?>">
                    <div class="modal-body p-4">
                        <p class="mb-3">أنت على وشك نقل المعاملة إلى مرحلة: <span class="fw-bold text-<?php echo $trans['color'] ?: 'primary'; ?>"><?php echo htmlspecialchars($trans['to_step_name']); ?></span></p>

                        <?php
                        // جلب الحقول المطلوبة لهذه الخطوة من سير العمل
                        $stmt_fields = $pdo->prepare("SELECT show_fields FROM workflow_steps WHERE id = ?");
                        $stmt_fields->execute([$trans['to_step_id']]);
                        $fields_str = $stmt_fields->fetchColumn();

                        if ($fields_str) {
                            $fields = explode(',', $fields_str);
                            echo '<div class="row g-3 mb-3">';
                            foreach ($fields as $field) {
                                $field = trim($field);
                                // تسميات الحقول
                                $labels = [
                                    'card_transaction_number' => 'رقم معاملة البطاقة',
                                    'card_transaction_date' => 'تاريخ معاملة البطاقة',
                                    'card_number' => 'رقم البطاقة',
                                    'card_issue_date' => 'تاريخ إصدار البطاقة',
                                    'passport_transaction_number' => 'رقم معاملة الجواز',
                                    'passport_transaction_date' => 'تاريخ معاملة الجواز',
                                    'passport_number' => 'رقم الجواز',
                                    'passport_issue_date' => 'تاريخ إصدار الجواز',
                                    'delivery_receiver_name' => 'اسم المستلم'
                                ];
                                $label = $labels[$field] ?? $field;
                                $input_type = (strpos($field, 'date') !== false) ? 'date' : 'text';
                                echo '<div class="col-12">';
                                echo '<label class="form-label small fw-bold">' . $label . '</label>';
                                echo '<input type="' . $input_type . '" class="form-control" name="extra_fields[' . $field . ']" required>';
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                        ?>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">ملاحظات إضافية</label>
                            <textarea class="form-control" name="status_notes" rows="3" placeholder="أدخل ملاحظاتك هنا..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-<?php echo $trans['color'] ?: 'primary'; ?> rounded-pill px-4 shadow-sm">تأكيد التغيير</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white py-3 rounded-top-4">
                <h5 class="modal-title fw-bold">تسجيل دفعة مالية</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="process_passport_transaction.php" method="POST">
                <input type="hidden" name="action" value="collect_payment">
                <input type="hidden" name="invoice_id" value="<?php echo $trx['invoice_id']; ?>">
                <input type="hidden" name="transaction_id" value="<?php echo $id; ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted d-block mb-1">المبلغ المتبقي</label>
                        <div class="fs-4 fw-bold text-danger"><?php echo number_format($trx['remaining_amount'], 2); ?> <?php echo $trx['currency_symbol']; ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">المبلغ المدفوع</label>
                        <div class="input-group">
                            <input type="number" step="0.01" class="form-control fw-bold fs-5" name="amount" max="<?php echo $trx['remaining_amount']; ?>" required>
                            <span class="input-group-text"><?php echo $trx['currency_symbol']; ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">تاريخ الدفع</label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">طريقة الدفع</label>
                        <select class="form-select" name="payment_type" required>
                            <option value="cash">نقداً</option>
                            <option value="bank_transfer">تحويل بنكي</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">الحساب المالي</label>
                        <select class="form-select" name="account_id" required>
                            <option value="">اختر الحساب...</option>
                            <?php
                            // جلب الصناديق والبنوك
                            $accounts = $pdo->query("SELECT id, account_name_ar FROM unified_accounts WHERE is_active = 1 AND (account_code LIKE '1111%' OR account_code LIKE '1112%')")->fetchAll();
                            foreach ($accounts as $acc) {
                                echo '<option value="' . $acc['id'] . '">' . $acc['account_name_ar'] . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 shadow-sm">تسجيل الدفعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
