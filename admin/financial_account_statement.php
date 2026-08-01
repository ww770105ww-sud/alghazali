<?php
require_once 'header.php';

$account_id = $_GET['id'] ?? null;
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$currency_id = $_GET['currency_id'] ?? '';

if (!$account_id) {
    echo "<div class='alert alert-danger'>لم يتم تحديد الحساب المالي.</div>";
    exit();
}

// جلب بيانات الحساب الموحد
$stmt_acc = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = ?");
$stmt_acc->execute([$account_id]);
$account = $stmt_acc->fetch();

if (!$account) {
    echo "<div class='alert alert-danger'>الحساب المالي غير موجود.</div>";
    exit();
}

// جلب العملات المفعلة لهذا الحساب من أرصدة الحساب الموحد
$stmt_currs = $pdo->prepare("SELECT c.id, c.currency_name, c.currency_symbol FROM currencies c JOIN account_balances_unified ab ON c.id = ab.currency_id WHERE ab.account_id = ?");
$stmt_currs->execute([$account_id]);
$account_currencies = $stmt_currs->fetchAll();

$where = "WHERE jl.account_id = ? AND ft.transaction_date BETWEEN ? AND ? AND ft.status = 'posted'";
$params = [$account_id, $from_date, $to_date];
if ($currency_id) {
    $where .= " AND jl.currency_id = ?";
    $params[] = $currency_id;
}

$stmt_trans = $pdo->prepare("
    SELECT jl.*, ft.transaction_type, ft.transaction_number, ft.transaction_date as operation_date, 
           COALESCE(jl.description, ft.description) as description, c.currency_name, c.currency_symbol, u.username,
           i.invoice_category, i.id as invoice_id
    FROM journal_lines jl 
    JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id 
    JOIN currencies c ON jl.currency_id = c.id 
    LEFT JOIN users u ON ft.created_by = u.id 
    LEFT JOIN invoices i ON (ft.transaction_number = i.invoice_number OR ft.reference_number = i.invoice_number OR (ft.reference_id = i.id AND ft.reference_type = 'invoice'))
    $where 
    ORDER BY ft.transaction_date ASC, jl.id ASC
");
$stmt_trans->execute($params);
$transactions = $stmt_trans->fetchAll();

$prev_where = "WHERE jl.account_id = ? AND ft.transaction_date < ? AND ft.status = 'posted'";
$prev_params = [$account_id, $from_date];
if ($currency_id) {
    $prev_where .= " AND jl.currency_id = ?";
    $prev_params[] = $currency_id;
}

$stmt_prev = $pdo->prepare("SELECT jl.currency_id, SUM(jl.debit - jl.credit) as prev_balance FROM journal_lines jl JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id $prev_where GROUP BY jl.currency_id");
$stmt_prev->execute($prev_params);
$prev_balances = $stmt_prev->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt_open = $pdo->prepare("SELECT currency_id, opening_balance FROM account_balances_unified WHERE account_id = ?");
$stmt_open->execute([$account_id]);
$open_balances = $stmt_open->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($open_balances as $curr_id => $opening_balance) {
    if (!isset($prev_balances[$curr_id])) {
        $prev_balances[$curr_id] = 0;
    }
    $prev_balances[$curr_id] += $opening_balance;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <h3 class="fw-bold text-primary"><i class="fas fa-file-invoice-dollar me-2"></i> كشف حساب: <?php echo htmlspecialchars($account['account_name_ar']); ?></h3>
        <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="fas fa-print me-1"></i> طباعة كشف الحساب
        </button>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 rounded-4 mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="id" value="<?php echo $account_id; ?>">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">العملة</label>
                    <select name="currency_id" class="form-select">
                        <option value="">كل العملات</option>
                        <?php foreach ($account_currencies as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $currency_id == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['currency_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 rounded-3"><i class="fas fa-search me-1"></i> عرض الكشف</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Ledger Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center border-top">
                    <thead class="bg-light">
                        <tr>
                            <th class="py-3">التاريخ</th>
                            <th>البيان</th>
                            <th>مدين (له)</th>
                            <th>دائن (عليه)</th>
                            <th>الرصيد الجاري</th>
                            <th class="no-print">بواسطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- الرصيد السابق -->
                        <?php if (!empty($prev_balances)): ?>
                            <?php foreach ($prev_balances as $curr_id => $bal):
                                // إذا اخترنا عملة محددة، نعرض فقط رصيدها السابق
                                if ($currency_id && $curr_id != $currency_id) continue;
                                $stmt_c = $pdo->prepare("SELECT currency_name, currency_symbol FROM currencies WHERE id = ?");
                                $stmt_c->execute([$curr_id]);
                                $curr_info = $stmt_c->fetch();
                            ?>
                                <tr class="bg-light bg-opacity-50">
                                    <td colspan="2" class="text-end fw-bold italic">رصيد سابق (<?php echo $curr_info['currency_name']; ?>)</td>
                                    <td class="fw-bold text-success"><?php echo $bal >= 0 ? number_format($bal, 2) : '-'; ?></td>
                                    <td class="fw-bold text-danger"><?php echo $bal < 0 ? number_format(abs($bal), 2) : '-'; ?></td>
                                    <td class="fw-bold <?php echo $bal >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format(abs($bal), 2); ?> <small><?php echo $curr_info['currency_symbol']; ?></small>
                                    </td>
                                    <td class="no-print">---</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- العمليات -->
                        <?php
                        $current_running_balances = $prev_balances;
                        foreach ($transactions as $t):
                            $amt = $t['debit'] - $t['credit'];
                            $current_running_balances[$t['currency_id']] = ($current_running_balances[$t['currency_id']] ?? 0) + $amt;
                            $running = $current_running_balances[$t['currency_id']];
                            
                            // تحسين مسمى نوع العملية
                            $type_label = $t['transaction_type'];
                            $is_cash_bank = (strpos($account['account_code'], '101') === 0 || strpos($account['account_code'], '102') === 0);
                            
                            if ($t['invoice_category'] == 'purchase') {
                                $type_label = $is_cash_bank ? 'سداد مشتريات' : 'فاتورة شراء';
                            } elseif ($t['invoice_category'] == 'sales') {
                                $type_label = $is_cash_bank ? 'تحصيل مبيعات' : 'فاتورة بيع';
                            } elseif ($t['transaction_type'] == 'payment') {
                                $type_label = $t['invoice_id'] ? 'سداد فاتورة' : 'سند صرف';
                            } elseif ($t['transaction_type'] == 'receipt') {
                                $type_label = $t['invoice_id'] ? 'تحصيل فاتورة' : 'سند قبض';
                            } elseif ($t['transaction_type'] == 'exchange') {
                                $type_label = 'تصريف عملة';
                            }
                        ?>
                            <tr>
                                <td class="small"><?php echo $t['operation_date']; ?></td>
                                <td class="text-start px-4">
                                    <div class="fw-bold"><?php echo htmlspecialchars($t['description']); ?></div>
                                    <small class="text-muted">المرجع: <?php echo $type_label; ?> #<?php echo $t['transaction_number']; ?></small>
                                </td>
                                <td class="fw-bold text-success"><?php echo $t['debit'] > 0 ? number_format($t['debit'], 2) : '-'; ?></td>
                                <td class="fw-bold text-danger"><?php echo $t['credit'] > 0 ? number_format($t['credit'], 2) : '-'; ?></td>
                                <td class="fw-bold <?php echo $running >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format(abs($running), 2); ?> <small><?php echo $t['currency_symbol']; ?></small>
                                </td>
                                <td class="no-print small text-muted"><?php echo htmlspecialchars($t['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($transactions) && empty($prev_balances)): ?>
                            <tr>
                                <td colspan="6" class="py-5 text-muted">لا توجد عمليات مسجلة في هذه الفترة.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
