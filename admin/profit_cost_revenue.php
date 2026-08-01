<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!has_permission('financial_reports_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// جلب العملة الأساسية
$stmt_base = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies WHERE is_default = 1 LIMIT 1");
$base_currency = $stmt_base->fetch();

// 1. الإيرادات (Revenue) - حسابات تبدأ بـ 4
$query_rev = "
    SELECT coa.account_name_ar as account_name, coa.account_code,
           SUM(jl.credit * ft.exchange_rate) - SUM(jl.debit * ft.exchange_rate) as amount
    FROM unified_accounts coa
    LEFT JOIN journal_lines jl ON coa.id = jl.account_id
    LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        AND ft.transaction_date BETWEEN ? AND ?
        AND ft.status = 'posted'
    WHERE coa.account_code LIKE '4%' 
    AND coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    GROUP BY coa.id, coa.account_code, coa.account_name_ar
    HAVING amount != 0
    ORDER BY coa.account_code
";
$stmt_rev = $pdo->prepare($query_rev);
$stmt_rev->execute([$start_date, $end_date]);
$revenues = $stmt_rev->fetchAll();

// 2. التكلفة (Cost) - حسابات تبدأ بـ 5
$query_cost = "
    SELECT coa.account_name_ar as account_name, coa.account_code,
           SUM(jl.debit * ft.exchange_rate) - SUM(jl.credit * ft.exchange_rate) as amount
    FROM unified_accounts coa
    LEFT JOIN journal_lines jl ON coa.id = jl.account_id
    LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        AND ft.transaction_date BETWEEN ? AND ?
        AND ft.status = 'posted'
    WHERE coa.account_code LIKE '5%' 
    AND coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    GROUP BY coa.id, coa.account_code, coa.account_name_ar
    HAVING amount != 0
    ORDER BY coa.account_code
";
$stmt_cost = $pdo->prepare($query_cost);
$stmt_cost->execute([$start_date, $end_date]);
$costs = $stmt_cost->fetchAll();

// 3. الأرباح (Profit) = الإيرادات - التكلفة
$total_revenue = array_sum(array_column($revenues, 'amount'));
$total_cost = array_sum(array_column($costs, 'amount'));
$net_profit = $total_revenue - $total_cost;
$profit_margin = $total_revenue > 0 ? ($net_profit / $total_revenue) * 100 : 0;

?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --revenue-color: #10b981;
        --cost-color: #ef4444;
        --profit-color: #3b82f6;
    }

    .summary-card {
        border: none;
        border-radius: 20px;
        padding: 2rem;
        color: white;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .summary-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }

    .summary-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }

    .revenue-card {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    .cost-card {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    }

    .profit-card {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    }

    .summary-label {
        font-size: 0.9rem;
        font-weight: 600;
        opacity: 0.9;
        margin-bottom: 0.5rem;
    }

    .summary-amount {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
    }

    .summary-detail {
        font-size: 0.85rem;
        opacity: 0.85;
    }

    .account-table {
        border-radius: 16px;
        overflow: hidden;
    }

    .account-table thead th {
        background: #f8f9fa;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: none;
        padding: 1rem;
    }

    .account-table tbody td {
        padding: 1rem;
        vertical-align: middle;
    }

    .account-table tbody tr:hover {
        background: #f8f9fa;
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">
                <i class="fas fa-chart-bar text-primary me-2"></i>
                ملخص الأرباح والتكاليف والإيرادات
            </h2>
            <p class="text-muted mb-0">Profit, Cost & Revenue Summary</p>
        </div>
        <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="fas fa-print me-2"></i> طباعة
        </button>
    </div>

    <!-- Date Filter -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">من تاريخ</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">إلى تاريخ</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill">
                        <i class="fas fa-filter me-1"></i> تصفية
                    </button>
                </div>
                <div class="col-md-4 text-end">
                    <span class="text-muted small">العملة: <strong><?php echo $base_currency['currency_name']; ?> (<?php echo $base_currency['currency_symbol']; ?>)</strong></span>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <!-- Revenue Card -->
        <div class="col-md-4">
            <div class="summary-card revenue-card">
                <div class="summary-label">
                    <i class="fas fa-arrow-up me-1"></i> إجمالي الإيرادات (Revenue)
                </div>
                <div class="summary-amount">
                    <?php echo number_format($total_revenue, 2); ?>
                    <small style="font-size: 1rem;"><?php echo $base_currency['currency_symbol']; ?></small>
                </div>
                <div class="summary-detail">
                    <?php echo count($revenues); ?> حساب إيرادات
                </div>
            </div>
        </div>

        <!-- Cost Card -->
        <div class="col-md-4">
            <div class="summary-card cost-card">
                <div class="summary-label">
                    <i class="fas fa-arrow-down me-1"></i> إجمالي التكاليف (Cost)
                </div>
                <div class="summary-amount">
                    <?php echo number_format($total_cost, 2); ?>
                    <small style="font-size: 1rem;"><?php echo $base_currency['currency_symbol']; ?></small>
                </div>
                <div class="summary-detail">
                    <?php echo count($costs); ?> حساب تكاليف
                </div>
            </div>
        </div>

        <!-- Profit Card -->
        <div class="col-md-4">
            <div class="summary-card <?php echo $net_profit >= 0 ? 'profit-card' : 'cost-card'; ?>">
                <div class="summary-label">
                    <i class="fas fa-<?php echo $net_profit >= 0 ? 'chart-line' : 'chart-line-down'; ?> me-1"></i>
                    <?php echo $net_profit >= 0 ? 'صافي الربح (Profit)' : 'صافي الخسارة (Loss)'; ?>
                </div>
                <div class="summary-amount">
                    <?php echo number_format($net_profit, 2); ?>
                    <small style="font-size: 1rem;"><?php echo $base_currency['currency_symbol']; ?></small>
                </div>
                <div class="summary-detail">
                    هامش الربح: <strong><?php echo number_format($profit_margin, 1); ?>%</strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Tables -->
    <div class="row g-4">
        <!-- Revenue Details -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 account-table">
                <div class="card-header bg-success text-white py-3 border-0">
                    <h5 class="mb-0 fw-bold">
                        <i class="fas fa-plus-circle me-2"></i>
                        تفاصيل الإيرادات (Revenue Details)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($revenues)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                            <p>لا توجد إيرادات في هذه الفترة</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">الحساب</th>
                                    <th class="text-end pe-4">المبلغ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($revenues as $rev): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold"><?php echo htmlspecialchars($rev['account_name']); ?></div>
                                            <small class="text-muted"><?php echo $rev['account_code']; ?></small>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-success">
                                            <?php echo number_format($rev['amount'], 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr class="fw-bold">
                                    <td class="ps-4 fs-6">إجمالي الإيرادات</td>
                                    <td class="text-end pe-4 fs-5 text-success">
                                        <?php echo number_format($total_revenue, 2); ?> <?php echo $base_currency['currency_symbol']; ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Cost Details -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 account-table">
                <div class="card-header bg-danger text-white py-3 border-0">
                    <h5 class="mb-0 fw-bold">
                        <i class="fas fa-minus-circle me-2"></i>
                        تفاصيل التكاليف (Cost Details)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($costs)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                            <p>لا توجد تكاليف في هذه الفترة</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">الحساب</th>
                                    <th class="text-end pe-4">المبلغ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($costs as $cost): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold"><?php echo htmlspecialchars($cost['account_name']); ?></div>
                                            <small class="text-muted"><?php echo $cost['account_code']; ?></small>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-danger">
                                            (<?php echo number_format($cost['amount'], 2); ?>)
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr class="fw-bold">
                                    <td class="ps-4 fs-6">إجمالي التكاليف</td>
                                    <td class="text-end pe-4 fs-5 text-danger">
                                        (<?php echo number_format($total_cost, 2); ?>) <?php echo $base_currency['currency_symbol']; ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Profit Calculation -->
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-primary text-white py-3 border-0">
                    <h5 class="mb-0 fw-bold">
                        <i class="fas fa-calculator me-2"></i>
                        حساب صافي الربح (Net Profit Calculation)
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row justify-content-center">
                        <div class="col-md-8">
                            <div class="bg-light rounded-4 p-4">
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                                    <span class="fw-bold fs-5 text-success">
                                        <i class="fas fa-arrow-up me-2"></i> الإيرادات
                                    </span>
                                    <span class="fw-bold fs-4 text-success">
                                        + <?php echo number_format($total_revenue, 2); ?>
                                    </span>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                                    <span class="fw-bold fs-5 text-danger">
                                        <i class="fas fa-arrow-down me-2"></i> التكاليف
                                    </span>
                                    <span class="fw-bold fs-4 text-danger">
                                        - <?php echo number_format($total_cost, 2); ?>
                                    </span>
                                </div>

                                <div class="d-flex justify-content-between align-items-center pt-2">
                                    <span class="fw-bold fs-4 <?php echo $net_profit >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                        <i class="fas fa-<?php echo $net_profit >= 0 ? 'check-circle' : 'times-circle'; ?> me-2"></i>
                                        <?php echo $net_profit >= 0 ? 'صافي الربح' : 'صافي الخسارة'; ?>
                                    </span>
                                    <span class="fw-bold fs-3 <?php echo $net_profit >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                        = <?php echo number_format($net_profit, 2); ?> <?php echo $base_currency['currency_symbol']; ?>
                                    </span>
                                </div>

                                <div class="mt-4 pt-3 border-top">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">هامش الربح (Profit Margin):</span>
                                        <span class="fw-bold fs-5 <?php echo $profit_margin >= 20 ? 'text-success' : ($profit_margin >= 10 ? 'text-warning' : 'text-danger'); ?>">
                                            <?php echo number_format($profit_margin, 1); ?>%
                                        </span>
                                    </div>
                                    <div class="progress mt-2" style="height: 8px;">
                                        <div class="progress-bar <?php echo $profit_margin >= 20 ? 'bg-success' : ($profit_margin >= 10 ? 'bg-warning' : 'bg-danger'); ?>"
                                            role="progressbar"
                                            style="width: <?php echo min($profit_margin, 100); ?>%"></div>
                                    </div>
                                    <small class="text-muted">
                                        <?php if ($profit_margin >= 30): ?>
                                            ✓ ممتاز - هامش ربح عالي
                                        <?php elseif ($profit_margin >= 20): ?>
                                            ✓ جيد - هامش ربح مقبول
                                        <?php elseif ($profit_margin >= 10): ?>
                                            ⚠ متوسط - يحتاج تحسين
                                        <?php else: ?>
                                            ⚠ منخفض - يجب مراجعة التكاليف
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>