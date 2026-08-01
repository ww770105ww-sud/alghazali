<?php
// =====================================================
// general_ledger.php - اليومية العامة (سجل الحركات المالية)
// =====================================================

require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$transaction_type = $_GET['type'] ?? '';
$currency_id = (int)($_GET['currency_id'] ?? 0);
$account_type_filter = $_GET['account_type_filter'] ?? '';
$specific_account_id = (int)($_GET['specific_account_id'] ?? 0);
$cost_center_id = (int)($_GET['cost_center_id'] ?? 0);

$query = "
    SELECT ft.id, ft.transaction_number, ft.transaction_date, 
           ft.description, ft.status,
           jl.debit as debit_amount, jl.credit as credit_amount,
           ua.account_name_ar as account_name, ua.account_code,
           c.currency_code, c.currency_symbol,
           u.username as creator_name,
           ft.transaction_type,
           cc.center_name_ar as cost_center_name
    FROM financial_transactions ft
    JOIN journal_lines jl ON ft.id = jl.financial_transaction_id
    JOIN unified_accounts ua ON jl.account_id = ua.id
    LEFT JOIN cost_centers cc ON jl.cost_center_id = cc.id
    LEFT JOIN currencies c ON ft.currency_id = c.id
    LEFT JOIN users u ON ft.created_by = u.id
    WHERE ft.transaction_date BETWEEN ? AND ?
";

$params = [$from_date, $to_date];

if ($transaction_type) {
    $query .= " AND ft.transaction_type = ?";
    $params[] = $transaction_type;
}

if ($currency_id > 0) {
    $query .= " AND ft.currency_id = ?";
    $params[] = $currency_id;
}

if ($specific_account_id > 0) {
    $query .= " AND jl.account_id = ?";
    $params[] = $specific_account_id;
}

if ($cost_center_id > 0) {
    $query .= " AND jl.cost_center_id = ?";
    $params[] = $cost_center_id;
}

$query .= " ORDER BY ft.transaction_date DESC, ft.id DESC, jl.id ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Function to calculate opening balance per account
function get_account_opening_balance($pdo, $account_id, $from_date)
{
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(jl.debit * COALESCE(ft.exchange_rate, 1)), 0) as total_debit,
            COALESCE(SUM(jl.credit * COALESCE(ft.exchange_rate, 1)), 0) as total_credit
        FROM journal_lines jl
        LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        WHERE jl.account_id = ? AND ft.transaction_date < ? AND ft.status = 'posted'
    ");
    $stmt->execute([$account_id, $from_date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total_debit'] - $result['total_credit'];
}

$currencies = $pdo->query("SELECT id, currency_name, currency_code FROM currencies WHERE is_active = 1")->fetchAll();

$page_title = "اليومية العامة";
require_once 'header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-book me-2"></i> اليومية العامة</h3>
        <div class="btn-group">
            <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-4 me-2">
                <i class="fas fa-print me-2"></i> طباعة
            </button>
        </div>
    </div>

    <!-- فلاتر البحث -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control rounded-3" value="<?php echo $from_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control rounded-3" value="<?php echo $to_date; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">نوع الحساب</label>
                    <select name="account_type_filter" id="account_type_filter" class="form-select rounded-3">
                        <option value="">كل الأنواع</option>
                        <option value="customers" <?php echo $account_type_filter == 'customers' ? 'selected' : ''; ?>>العملاء</option>
                        <option value="agents" <?php echo $account_type_filter == 'agents' ? 'selected' : ''; ?>>الوكلاء</option>
                        <option value="branches" <?php echo $account_type_filter == 'branches' ? 'selected' : ''; ?>>الفروع</option>
                        <option value="employees" <?php echo $account_type_filter == 'employees' ? 'selected' : ''; ?>>الموظفين</option>
                        <option value="suppliers" <?php echo $account_type_filter == 'suppliers' ? 'selected' : ''; ?>>الموردين</option>
                        <option value="banks" <?php echo $account_type_filter == 'banks' ? 'selected' : ''; ?>>البنوك</option>
                        <option value="cash_funds" <?php echo $account_type_filter == 'cash_funds' ? 'selected' : ''; ?>>الصناديق</option>
                        <option value="expenses" <?php echo $account_type_filter == 'expenses' ? 'selected' : ''; ?>>المصاريف</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">اختر الحساب</label>
                    <select name="specific_account_id" id="specific_account_id" class="form-select rounded-3">
                        <option value="">كل الحسابات</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">مركز التكلفة</label>
                    <select name="cost_center_id" class="form-select rounded-3">
                        <option value="0">كل المراكز</option>
                        <?php 
                        $all_ccs = $pdo->query("SELECT id, center_name_ar FROM cost_centers ORDER BY center_code")->fetchAll();
                        foreach($all_ccs as $cc): 
                        ?>
                            <option value="<?php echo $cc['id']; ?>" <?php echo $cost_center_id == $cc['id'] ? 'selected' : ''; ?>><?php echo $cc['center_name_ar']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">نوع العملية</label>
                    <select name="type" class="form-select rounded-3">
                        <option value="">كل العمليات</option>
                        <option value="receipt" <?php echo $transaction_type == 'receipt' ? 'selected' : ''; ?>>سند قبض</option>
                        <option value="payment" <?php echo $transaction_type == 'payment' ? 'selected' : ''; ?>>سند صرف</option>
                        <option value="transfer" <?php echo $transaction_type == 'transfer' ? 'selected' : ''; ?>>تحويل/تصريف</option>
                        <option value="expense" <?php echo $transaction_type == 'expense' ? 'selected' : ''; ?>>مصاريف</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">العملة</label>
                    <select name="currency_id" class="form-select rounded-3">
                        <option value="0">كل العملات</option>
                        <?php foreach($currencies as $cur): ?>
                            <option value="<?php echo $cur['id']; ?>" <?php echo $currency_id == $cur['id'] ? 'selected' : ''; ?>><?php echo $cur['currency_code']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-3">
                        <i class="fas fa-filter me-1"></i> تصفية
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ملخص الحساب (إذا تم اختيار حساب محدد) -->
    <?php if ($specific_account_id > 0): 
        // Get account details if a specific account is selected
        $acc_stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = ?");
        $acc_stmt->execute([$specific_account_id]);
        $account = $acc_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($account):
            $opening_balance = get_account_opening_balance($pdo, $account['id'], $from_date);
            
            // Calculate period totals for this specific account
            $period_debit = 0;
            $period_credit = 0;
            foreach ($transactions as $tx) {
                $period_debit += $tx['debit_amount'];
                $period_credit += $tx['credit_amount'];
            }
            $closing_balance = $opening_balance + $period_debit - $period_credit;
    ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-light rounded-top-4 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">
                    <span class="text-muted small me-2"><?php echo htmlspecialchars($account['account_code']); ?></span>
                    <?php echo htmlspecialchars($account['account_name_ar']); ?>
                </h5>
                <div class="text-muted small">
                    ملخص الفترة من <?php echo htmlspecialchars($from_date); ?> إلى <?php echo htmlspecialchars($to_date); ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="row g-0 border-bottom">
                    <div class="col-md-3 p-3 bg-light border-end text-center">
                        <div class="text-muted small mb-1">الرصيد الافتتاحي</div>
                        <div class="fw-bold <?php echo $opening_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo number_format($opening_balance, 2); ?> <?php echo $opening_balance >= 0 ? 'مدين' : 'دائن'; ?>
                        </div>
                    </div>
                    <div class="col-md-3 p-3 bg-light border-end text-center">
                        <div class="text-muted small mb-1">إجمالي المدين في الفترة</div>
                        <div class="fw-bold text-success"><?php echo number_format($period_debit, 2); ?></div>
                    </div>
                    <div class="col-md-3 p-3 bg-light border-end text-center">
                        <div class="text-muted small mb-1">إجمالي الدائن في الفترة</div>
                        <div class="fw-bold text-danger"><?php echo number_format($period_credit, 2); ?></div>
                    </div>
                    <div class="col-md-3 p-3 bg-light text-center">
                        <div class="text-muted small mb-1">الرصيد الختامي</div>
                        <div class="fw-bold <?php echo $closing_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo number_format($closing_balance, 2); ?> <?php echo $closing_balance >= 0 ? 'مدين' : 'دائن'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; endif; ?>

    <!-- جدول القيود -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">التاريخ / الرقم</th>
                            <th>البيان / الحساب</th>
                            <th class="text-end">مدين (عليه)</th>
                            <th class="text-end">دائن (له)</th>
                            <th class="text-center">العملة</th>
                            <th class="text-center">الحالة</th>
                            <th class="pe-4">بواسطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $current_entry_id = null;
                        foreach($transactions as $trx): 
                            $is_new_entry = ($trx['id'] !== $current_entry_id);
                            if ($is_new_entry) $current_entry_id = $trx['id'];
                        ?>
                            <tr style="<?php echo $is_new_entry ? 'border-top: 2px solid #dee2e6;' : ''; ?>">
                                <td class="ps-4">
                                    <?php if($is_new_entry): ?>
                                        <div class="fw-bold small"><?php echo $trx['transaction_date']; ?></div>
                                        <div class="text-muted extra-small"><?php echo $trx['transaction_number']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($is_new_entry): ?>
                                        <div class="small fw-bold text-dark mb-1" title="<?php echo htmlspecialchars($trx['description']); ?>">
                                            <?php echo htmlspecialchars($trx['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="ps-3 d-flex justify-content-between">
                                        <span class="small"><?php echo $trx['account_name']; ?></span>
                                        <?php if($trx['cost_center_name']): ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary extra-small ms-2">CC: <?php echo $trx['cost_center_name']; ?></span>
                                        <?php endif; ?>
                                        <span class="text-muted extra-small"><?php echo $trx['account_code']; ?></span>
                                    </div>
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?php echo $trx['debit_amount'] > 0 ? number_format($trx['debit_amount'], 2) : '-'; ?>
                                </td>
                                <td class="text-end fw-bold text-danger">
                                    <?php echo $trx['credit_amount'] > 0 ? number_format($trx['credit_amount'], 2) : '-'; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info bg-opacity-10 text-info"><?php echo $trx['currency_code']; ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if($is_new_entry): ?>
                                        <?php if(strtolower($trx['status']) == 'posted'): ?>
                                            <span class="badge bg-success rounded-pill">مرحل</span>
                                        <?php elseif(strtolower($trx['status']) == 'cancelled'): ?>
                                            <span class="badge bg-danger rounded-pill">ملغي</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark rounded-pill">مسودة</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 small text-muted">
                                    <?php if($is_new_entry): ?>
                                        <?php echo htmlspecialchars($trx['creator_name']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($transactions)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                                    لا توجد قيود مالية في هذه الفترة
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.extra-small { font-size: 0.7rem; }
@media print {
    .top-navbar, .sidebar, .card-header, .btn-group, form { display: none !important; }
    .main-wrapper { margin: 0 !important; padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #eee !important; }
}
</style>

<?php require_once 'footer.php'; ?>

<script>
$(document).ready(function() {
    const accountTypeFilter = $('#account_type_filter');
    const specificAccountSelect = $('#specific_account_id');

    const currentAccountType = "<?php echo $account_type_filter; ?>";
    const currentSpecificAccountId = "<?php echo $specific_account_id; ?>";

    function populateAccounts(data, selectedId) {
        specificAccountSelect.empty();
        specificAccountSelect.append($('<option>', {
            value: '',
            text: 'كل الحسابات'
        }));
        $.each(data, function(key, entry) {
            specificAccountSelect.append($('<option>', {
                value: entry.id,
                text: entry.name,
                selected: (entry.id == selectedId)
            }));
        });
    }

    function fetchAccounts(selectedType, selectedId = 0) {
        if (selectedType) {
            $.ajax({
                url: 'ajax_get_accounts_for_filter.php',
                type: 'GET',
                data: {
                    account_type: selectedType
                },
                dataType: 'json',
                success: function(data) {
                    populateAccounts(data, selectedId);
                },
                error: function(xhr, status, error) {
                    console.error("Error fetching accounts: " + error);
                    populateAccounts([], 0);
                }
            });
        } else {
            populateAccounts([], 0);
        }
    }

    // Event Listeners
    accountTypeFilter.on('change', function() {
        const selectedType = $(this).val();
        fetchAccounts(selectedType);
    });

    // Initial load
    if (currentAccountType) {
        fetchAccounts(currentAccountType, currentSpecificAccountId);
    }
});
</script>