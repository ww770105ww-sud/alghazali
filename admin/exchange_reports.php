<?php
/**
 * تقارير تصريف العملات وتاريخ الأسعار
 * وكالة الغزالي للسفريات والسياحة
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';
$page_title = 'تقارير تصريف العملات';
require_once 'header.php';

// فلاتر البحث
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$currency_id = $_GET['currency_id'] ?? '';

// 1. جلب سجل العمليات مع الفلاتر
$sql_history = "
    SELECT 
        et.*,
        fc.currency_name as from_currency_name,
        fc.currency_symbol as from_currency_symbol,
        tc.currency_name as to_currency_name,
        tc.currency_symbol as to_currency_symbol,
        fa.account_name_ar as from_account_name,
        ta.account_name_ar as to_account_name,
        u.full_name as creator_name
    FROM currency_exchange_transactions et
    JOIN currencies fc ON et.from_currency_id = fc.id
    JOIN currencies tc ON et.to_currency_id = tc.id
    JOIN unified_accounts fa ON et.from_account_id = fa.id
    JOIN unified_accounts ta ON et.to_account_id = ta.id
    LEFT JOIN users u ON et.created_by = u.id
    WHERE et.transaction_date BETWEEN :from_date AND :to_date
";

if ($currency_id) {
    $sql_history .= " AND (et.from_currency_id = :curr_id OR et.to_currency_id = :curr_id)";
}
$sql_history .= " ORDER BY et.transaction_date DESC, et.id DESC";

$stmt_history = $pdo->prepare($sql_history);
$params = [':from_date' => $from_date, ':to_date' => $to_date];
if ($currency_id) $params[':curr_id'] = $currency_id;
$stmt_history->execute($params);
$history = $stmt_history->fetchAll();

// 2. جلب تاريخ أسعار الصرف
$sql_rates = "
    SELECT 
        rh.*,
        c.currency_name,
        c.currency_symbol,
        u.full_name as changer_name
    FROM currency_exchange_rates_history rh
    JOIN currencies c ON rh.currency_id = c.id
    LEFT JOIN users u ON rh.changed_by = u.id
    ORDER BY rh.effective_date DESC
    LIMIT 50
";
$rates_history = $pdo->query($sql_rates)->fetchAll();

// جلب العملات للفلاتر
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies WHERE is_active = 1")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-6">
            <h3 class="fw-bold text-dark"><i class="bi bi-graph-up-arrow text-primary me-2"></i>تقارير العملات والصرف</h3>
            <p class="text-muted small">مراقبة تاريخ أسعار الصرف وتحليل عمليات التحويل المالي</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary rounded-pill px-4" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> طباعة التقرير
            </button>
        </div>
    </div>

    <!-- فلاتر البحث -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control rounded-3" value="<?= $from_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control rounded-3" value="<?= $to_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">العملة</label>
                    <select name="currency_id" class="form-select rounded-3">
                        <option value="">كل العملات</option>
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $currency_id == $c['id'] ? 'selected' : '' ?>>
                                <?= $c['currency_name'] ?> (<?= $c['currency_symbol'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 rounded-3 fw-bold">
                        <i class="bi bi-filter me-1"></i> تحديث التقرير
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <!-- جدول سجل عمليات الصرف -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-list-check me-2 text-success"></i>سجل عمليات الصرف</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.9rem;">
                            <thead class="bg-light text-muted small">
                                <tr>
                                    <th>رقم العملية</th>
                                    <th>التاريخ</th>
                                    <th>من</th>
                                    <th>إلى</th>
                                    <th>سعر الصرف</th>
                                    <th>بواسطة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= $h['transaction_number'] ?></td>
                                        <td class="small"><?= $h['transaction_date'] ?></td>
                                        <td>
                                            <div class="fw-bold"><?= number_format($h['from_amount'], 2) ?></div>
                                            <div class="small text-muted"><?= $h['from_currency_symbol'] ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success"><?= number_format($h['to_amount'], 2) ?></div>
                                            <div class="small text-muted"><?= $h['to_currency_symbol'] ?></div>
                                        </td>
                                        <td class="small fw-bold text-dark"><?= number_format($h['exchange_rate'], 4) ?></td>
                                        <td class="small text-muted"><?= $h['creator_name'] ?></td>
                                    </tr>
                                <?php endforeach; if(empty($history)): ?>
                                    <tr><td colspan="6" class="py-5 text-muted">لا توجد عمليات صرف في هذه الفترة</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- جدول تاريخ أسعار الصرف -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2 text-info"></i>تاريخ تغير الأسعار</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                            <thead class="bg-light text-muted">
                                <tr>
                                    <th class="ps-3">العملة</th>
                                    <th>السعر القديم</th>
                                    <th>السعر الجديد</th>
                                    <th>التاريخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rates_history as $r): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold"><?= $r['currency_name'] ?></td>
                                        <td class="text-muted text-decoration-line-through"><?= number_format($r['old_rate_sell'], 2) ?></td>
                                        <td class="fw-bold text-primary"><?= number_format($r['new_rate_sell'], 2) ?></td>
                                        <td class="small text-muted" title="<?= $r['effective_date'] ?>">
                                            <?= date('m/d H:i', strtotime($r['effective_date'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; if(empty($rates_history)): ?>
                                    <tr><td colspan="4" class="py-5 text-center text-muted">لا يوجد سجل لتغير الأسعار</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .card-header form, header, footer, nav { display: none !important; }
    .card { border: 1px solid #eee !important; box-shadow: none !important; }
    .container-fluid { width: 100% !important; padding: 0 !important; }
}
</style>

<?php require_once 'footer.php'; ?>
