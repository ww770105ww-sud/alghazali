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

// استعلام ميزان المراجعة (بالاعتماد على الجداول الموحدة الجديدة)
$query = "
    SELECT 
        coa.account_code, 
        coa.account_name_ar as account_name,
        -- الأرصدة المدينة (بالعملة الأساسية)
        SUM(COALESCE(jl.debit, 0) * COALESCE(ft.exchange_rate, 1)) as total_debit,
        -- الأرصدة الدائنة (بالعملة الأساسية)
        SUM(COALESCE(jl.credit, 0) * COALESCE(ft.exchange_rate, 1)) as total_credit,
        coa.normal_balance,
        COALESCE((SELECT SUM(opening_balance) FROM account_balances_unified WHERE account_id = coa.id), 0) as opening_balance
    FROM unified_accounts coa
    LEFT JOIN journal_lines jl ON coa.id = jl.account_id
    LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        AND ft.transaction_date BETWEEN ? AND ?
        AND ft.status = 'posted'
    -- استثناء الحسابات الرئيسية (التي لديها أبناء) لضمان عدم تكرار الأرصدة في الميزان
    WHERE coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    GROUP BY coa.id, coa.account_code, coa.account_name_ar, coa.normal_balance
    HAVING total_debit != 0 OR total_credit != 0 OR opening_balance != 0
    ORDER BY coa.account_code ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$start_date, $end_date]);
$report_data = $stmt->fetchAll();

?>

<div class="container-fluid">
    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fas fa-balance-scale text-primary me-2"></i> ميزان المراجعة (Trial Balance)</h5>
            <div class="text-muted small">العملة الأساسية: <?php echo $base_currency['currency_name']; ?></div>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 mb-4 no-print">
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

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="bg-light text-center">
                        <tr>
                            <th rowspan="2" width="120">رقم الحساب</th>
                            <th rowspan="2">اسم الحساب</th>
                            <th colspan="2">الحركة (بالعملة الأساسية)</th>
                            <th colspan="2">الرصيد الختامي</th>
                        </tr>
                        <tr>
                            <th width="150">مدين</th>
                            <th width="150">دائن</th>
                            <th width="150">مدين</th>
                            <th width="150">دائن</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_debit = 0;
                        $grand_credit = 0;
                        $grand_bal_debit = 0;
                        $grand_bal_credit = 0;
                        
                        foreach($report_data as $row): 
                            // حساب الأرصدة الافتتاحية
                            $op_debit = ($row['normal_balance'] == 'debit') ? (float)$row['opening_balance'] : 0;
                            $op_credit = ($row['normal_balance'] == 'credit') ? (float)$row['opening_balance'] : 0;
                            
                            // الحركات خلال الفترة
                            $move_debit = (float)$row['total_debit'];
                            $move_credit = (float)$row['total_credit'];
                            
                            $grand_debit += $move_debit;
                            $grand_credit += $move_credit;
                            
                            // الرصيد الختامي
                            $final_balance = ($row['normal_balance'] == 'debit') 
                                ? ($op_debit + $move_debit - $move_credit) 
                                : ($op_credit + $move_credit - $move_debit);
                            
                            $bal_debit = ($row['normal_balance'] == 'debit' && $final_balance > 0) ? $final_balance : (($row['normal_balance'] == 'credit' && $final_balance < 0) ? abs($final_balance) : 0);
                            $bal_credit = ($row['normal_balance'] == 'credit' && $final_balance > 0) ? $final_balance : (($row['normal_balance'] == 'debit' && $final_balance < 0) ? abs($final_balance) : 0);
                            
                            $grand_bal_debit += $bal_debit;
                            $grand_bal_credit += $bal_credit;
                        ?>
                        <tr>
                            <td class="text-center fw-bold"><?php echo $row['account_code']; ?></td>
                            <td><?php echo htmlspecialchars($row['account_name']); ?></td>
                            <td class="text-end text-success"><?php echo number_format($move_debit, 2); ?></td>
                            <td class="text-end text-danger"><?php echo number_format($move_credit, 2); ?></td>
                            <td class="text-end fw-bold"><?php echo $bal_debit > 0 ? number_format($bal_debit, 2) : '-'; ?></td>
                            <td class="text-end fw-bold"><?php echo $bal_credit > 0 ? number_format($bal_credit, 2) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold text-end">
                        <tr>
                            <td colspan="2" class="text-center">الإجمالي الكلي</td>
                            <td class="text-success"><?php echo number_format($grand_debit, 2); ?></td>
                            <td class="text-danger"><?php echo number_format($grand_credit, 2); ?></td>
                            <td><?php echo number_format($grand_bal_debit, 2); ?></td>
                            <td><?php echo number_format($grand_bal_credit, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <?php if (abs($grand_bal_debit - $grand_bal_credit) > 0.01): ?>
                <div class="alert alert-danger mt-3 no-print">
                    <i class="fas fa-exclamation-triangle me-2"></i> تنبيه: ميزان المراجعة غير متزن. يوجد فرق قدره: <?php echo number_format(abs($grand_bal_debit - $grand_bal_credit), 2); ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success mt-3 no-print">
                    <i class="fas fa-check-circle me-2"></i> ميزان المراجعة متزن تماماً.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
