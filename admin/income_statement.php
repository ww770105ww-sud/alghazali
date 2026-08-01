<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!has_permission('financial_reports_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-12-31');

// جلب العملة الأساسية
$stmt_base = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies WHERE is_default = 1 LIMIT 1");
$base_currency = $stmt_base->fetch();

// دالة جلب الحسابات مع التسلسل الهرمي (بدون WITH RECURSIVE لالتوافق مع الإصدارات القديمة)
function get_accounts_hierarchy(PDO $pdo, $prefix, $start_date, $end_date, $is_revenue = true) {
    // أولاً جلب جميع الحسابات
    $all_accounts = [];
    $stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code LIKE ? ORDER BY account_code");
    $stmt->execute([$prefix . '%']);
    $accounts_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // بناء شجرة الحسابات
    $account_tree = [];
    $account_map = [];
    foreach ($accounts_raw as $acc) {
        $account_map[$acc['id']] = array_merge($acc, ['children' => []]);
    }
    
    foreach ($account_map as &$acc) {
        if ($acc['parent_id'] === null) {
            $account_tree[] = &$acc;
        } else if (isset($account_map[$acc['parent_id']])) {
            $account_map[$acc['parent_id']]['children'][] = &$acc;
        }
    }
    
    // دالة مساعدة لتسطير الشجرة مع المستوى
    $flatten = function($accounts, $level = 0) use (&$flatten) {
        $result = [];
        foreach ($accounts as $acc) {
            $acc['level'] = $level;
            $acc['path'] = $acc['account_code'];
            $result[] = $acc;
            if (!empty($acc['children'])) {
                $result = array_merge($result, $flatten($acc['children'], $level + 1));
            }
        }
        return $result;
    };
    
    $accounts_flat = $flatten($account_tree);
    
    // جلب المبالغ لكل حساب
    foreach ($accounts_flat as &$acc) {
        $amount_query = "
            SELECT 
                CASE 
                    WHEN ? = true THEN 
                        COALESCE(SUM(jl.credit * ft.exchange_rate) - SUM(jl.debit * ft.exchange_rate), 0)
                    ELSE
                        COALESCE(SUM(jl.debit * ft.exchange_rate) - SUM(jl.credit * ft.exchange_rate), 0)
                END AS amount
            FROM unified_accounts ua
            LEFT JOIN journal_lines jl ON ua.id = jl.account_id
            LEFT JOIN financial_transactions ft 
                ON jl.financial_transaction_id = ft.id
                AND ft.transaction_date BETWEEN ? AND ?
                AND ft.status = 'posted'
            WHERE ua.id = ?
        ";
        $amount_stmt = $pdo->prepare($amount_query);
        $amount_stmt->execute([$is_revenue, $start_date, $end_date, $acc['id']]);
        $acc['amount'] = $amount_stmt->fetchColumn() ?: 0;
    }
    
    return $accounts_flat;
}

// جلب إيرادات (4xxx)
$revenue_accounts = get_accounts_hierarchy($pdo, '4', $start_date, $end_date, true);
// جلب مصاريف (5xxx و 6xxx)
$expense_accounts_5 = get_accounts_hierarchy($pdo, '5', $start_date, $end_date, false);
$expense_accounts_6 = get_accounts_hierarchy($pdo, '6', $start_date, $end_date, false);
$expense_accounts = array_merge($expense_accounts_5, $expense_accounts_6);

// حساب الإجماليات
function calculate_totals($accounts) {
    $total = 0;
    foreach ($accounts as $acc) {
        // نجمع فقط الحسابات الفرعية (ليست حسابات أبواب)
        $is_parent = false;
        foreach ($accounts as $a) {
            if ($a['parent_id'] == $acc['id']) {
                $is_parent = true;
                break;
            }
        }
        if (!$is_parent) {
            $total += $acc['amount'];
        }
    }
    return $total;
}

$total_revenue = calculate_totals($revenue_accounts);
$total_expense = calculate_totals($expense_accounts);
$net_profit = $total_revenue - $total_expense;

// دالة عرض الحساب مع التسلسل
function render_account($account, $is_revenue) {
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $account['level']);
    $is_parent = false;
    static $all_accounts = null;
    
    if ($all_accounts === null) {
        global $revenue_accounts, $expense_accounts;
        $all_accounts = array_merge($revenue_accounts, $expense_accounts);
    }
    
    foreach ($all_accounts as $acc) {
        if ($acc['parent_id'] == $account['id']) {
            $is_parent = true;
            break;
        }
    }
    
    $text_class = $is_parent ? 'fw-semibold text-secondary' : '';
    $amount_text = $is_parent ? '' : number_format($account['amount'], 2);
    if (!$is_parent && !$is_revenue) {
        $amount_text = '(' . $amount_text . ')';
    }
    
    echo "<tr class='$text_class'>";
    echo "<td class='ps-" . ($account['level'] * 4 + 2) . "'>";
    echo "<span class='text-muted small'>" . htmlspecialchars($account['account_code']) . "</span> - ";
    echo htmlspecialchars($account['account_name_ar']);
    echo "</td>";
    echo "<td class='text-end fw-bold'>" . $amount_text . "</td>";
    echo "</tr>";
}
?>

<div class="container-fluid">
    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fas fa-chart-line text-success me-2"></i> قائمة الدخل (Income Statement)</h5>
            <div class="text-muted small">العملة الأساسية: <?php echo $base_currency['currency_name']; ?></div>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 mb-5 no-print">
                <div class="col-md-3">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> تصفية</button>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" onclick="window.print()" class="btn btn-outline-secondary w-100"><i class="fas fa-print me-1"></i> طباعة</button>
                </div>
            </form>

            <div class="row justify-content-center">
                <div class="col-md-10">
                    <h4 class="text-center mb-4 fw-bold">بيان الربح والخسارة</h4>
                    <p class="text-center text-muted mb-5">للفترة من <?php echo $start_date; ?> إلى <?php echo $end_date; ?></p>
                    
                    <!-- الإيرادات -->
                    <div class="mb-5">
                        <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">
                            <i class="fas fa-dollar-sign me-2"></i>الإيرادات التشغيلية والخدمية
                        </h6>
                        <table class="table table-borderless align-middle">
                            <tbody>
                                <?php foreach($revenue_accounts as $acc): ?>
                                    <?php render_account($acc, true); ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="border-top bg-light">
                                <tr class="fw-bold">
                                    <td class="ps-2 fs-5">إجمالي الإيرادات</td>
                                    <td class="text-end fs-5 text-success"><?php echo number_format($total_revenue, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- المصاريف -->
                    <div class="mb-5">
                        <h6 class="fw-bold text-danger mb-3 border-bottom pb-2">
                            <i class="fas fa-shopping-cart me-2"></i>المصروفات والتكاليف
                        </h6>
                        <table class="table table-borderless align-middle">
                            <tbody>
                                <?php foreach($expense_accounts as $acc): ?>
                                    <?php render_account($acc, false); ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="border-top bg-light">
                                <tr class="fw-bold">
                                    <td class="ps-2 fs-5">إجمالي المصروفات</td>
                                    <td class="text-end fs-5 text-danger"><?php echo number_format($total_expense, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- صافي الربح -->
                    <div class="card bg-<?php echo $net_profit >= 0 ? 'success' : 'danger'; ?> text-white border-0 shadow-sm p-4 rounded-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="mb-0 fw-bold">
                                <i class="fas fa-<?php echo $net_profit >= 0 ? 'check-circle' : 'times-circle'; ?> me-2"></i>
                                <?php echo $net_profit >= 0 ? 'صافي الربح (Net Profit)' : 'صافي الخسارة (Net Loss)'; ?>
                            </h3>
                            <h2 class="mb-0 fw-bold">
                                <?php echo number_format($net_profit, 2); ?> 
                                <?php echo $base_currency['currency_symbol']; ?>
                            </h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
