<?php
require_once 'header.php';

/**
 * وظائف الصلاحيات والتحقق
 */
function has_permission_v3($permission_code, $branch_id = null)
{
    global $pdo, $user_role, $user_branch_id, $user_role_id;
    $role_lc = strtolower((string)$user_role);
    $role_id  = (int)($user_role_id ?? 0);
    if ($role_lc === 'developer' || $role_id === 2) return true;
    if ($role_lc === 'admin' || $role_id === 1) return true;
    if (!$role_id) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions_unified rp JOIN unified_permissions p ON rp.permission_id = p.id WHERE rp.role_id = ? AND p.permission_code = ?");
    $stmt->execute([$role_id, $permission_code]);
    return (int)$stmt->fetchColumn() > 0;
}

function can_edit_voucher($voucher)
{
    global $user_role, $user_id, $user_branch_id, $user_role_id;
    $role_lc = strtolower((string)$user_role);
    $role_id = (int)($user_role_id ?? 0);
    if ($role_lc === 'developer' || $role_id === 2) return true;
    if (!in_array($voucher['status'], ['draft', 'cancelled'])) return false;
    if (!empty($voucher['posted_at']) && $voucher['status'] === 'cancelled' && empty($voucher['original_voucher_id'])) {
        // السند الملغي الذي كان مرحلاً يمكن تعديله
    }

    if (has_permission_v3('voucher_edit')) return true;

    if ($role_lc === 'admin' || $role_id === 1) return true;
    if ($role_lc === 'branch_manager' && $voucher['branch_id'] == $user_branch_id) return true;
    if ($role_lc === 'accountant') return true;
    return $voucher['created_by'] == $user_id;
}

function can_post_voucher($voucher)
{
    global $user_role, $user_branch_id, $user_role_id;
    $role_lc = strtolower((string)$user_role);
    $role_id = (int)($user_role_id ?? 0);
    if ($role_lc === 'developer' || $role_id === 2) return true;
    if (!in_array($voucher['status'], ['draft', 'cancelled'])) return false;

    if (has_permission_v3('voucher_post')) return true;

    if ($role_lc === 'admin' || $role_id === 1) return true;
    if ($role_lc === 'accountant') return true;
    if ($role_lc === 'branch_manager' && $voucher['branch_id'] == $user_branch_id) return true;
    return false;
}

function can_unpost_voucher($voucher)
{
    global $user_role, $user_role_id;
    $role_lc = strtolower((string)$user_role);
    $role_id = (int)($user_role_id ?? 0);
    if ($role_lc === 'developer' || $role_id === 2) return true;
    if ($voucher['status'] !== 'posted') return false;

    // لا يمكن سحب ترحيل السند العكسي نفسه
    if (!empty($voucher['original_voucher_id'])) return false;
    if ($voucher['has_reversal'] || $voucher['is_reversed']) return false;

    if (has_permission_v3('vouchers_unpost')) return true;
    if ($role_lc === 'admin' || $role_id === 1) return true;
    return false;
}

function can_reverse_voucher($voucher)
{
    global $user_role, $user_role_id;
    $role_lc = strtolower((string)$user_role);
    $role_id = (int)($user_role_id ?? 0);
    if ($role_lc === 'developer' || $role_id === 2) return true;
    if ($voucher['status'] != 'posted') return false;

    // السندات العكسية نفسها لا يمكن عكسها
    if (!empty($voucher['original_voucher_id'])) return false;
    if ($voucher['has_reversal'] || $voucher['is_reversed']) return false;

    // الصلاحيات التفصيلية حسب نوع السند
    $ttype = strtolower($voucher['transaction_type'] ?? '');
    if ($ttype === 'receipt' && has_permission_v3('receipt_reverse')) return true;
    if ($ttype === 'payment' && has_permission_v3('payment_reverse')) return true;

    // توافق خلفي: الصلاحية العامة القديمة
    if (has_permission_v3('voucher_reverse')) return true;

    if ($role_lc === 'admin' || $role_id === 1) return true;
    return false;
}

function can_delete_voucher($voucher)
{
    global $user_role, $user_id, $user_branch_id, $user_role_id;
    $role_lc = strtolower((string)$user_role);
    $role_id = (int)($user_role_id ?? 0);
    if ($role_lc === 'developer' || $role_id === 2) return true;

    // لا يمكن حذف السندات المرحلة — إلا بعد الإلغاء/سحب الترحيل أولاً
    if ($voucher['status'] == 'posted') return false;

    // تحديد هل السند أصلي أم عكسي
    $is_reversal = !empty($voucher['original_voucher_id']) || ($voucher['reference_type'] ?? '') === 'reversal';
    $ttype = strtolower($voucher['transaction_type'] ?? '');

    // الصلاحيات التفصيلية
    if (!$is_reversal) {
        if ($ttype === 'receipt' && has_permission_v3('receipt_delete_original')) return true;
        if ($ttype === 'payment' && has_permission_v3('payment_delete_original')) return true;
    } else {
        if ($ttype === 'receipt' && has_permission_v3('receipt_delete_reversal')) return true;
        if ($ttype === 'payment' && has_permission_v3('payment_delete_reversal')) return true;
    }

    // توافق خلفي: الصلاحية العامة القديمة
    if (has_permission_v3('voucher_delete')) return true;

    if ($role_lc === 'admin' || $role_id === 1) return true;
    if ($role_lc === 'branch_manager' && $voucher['branch_id'] == $user_branch_id) return true;
    if ($role_lc === 'accountant') return true;
    return $voucher['created_by'] == $user_id;
}

/**
 * تسجيل سجل التدقيق
 */
// log_audit is defined in includes/functions.php
if (!has_permission_v3('receipts_view') && !has_permission_v3('voucher_create')) {
    echo "<script>alert('ليس لديك صلاحية لاستعراض سندات القبض.'); location.href='index.php';</script>";
    exit();
}

$success_msg = "";
$error_msg = "";

// إضافة/تعديل سند قبض
if (isset($_POST['add_receipt'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "خطأ في رمز التحقق (CSRF).";
    } else {
        $id = $_POST['edit_receipt_id'] ?? null;
    if (!has_permission_v3('voucher_create')) {
        $error_msg = "ليس لديك صلاحية لإنشاء سند قبض.";
    } else {
        try {
            $pdo->beginTransaction();

            $date = $_POST['date'];

            if (is_period_closed($pdo, $date)) {
                throw new Exception("خطأ في التاريخ: التاريخ المحدد داخل فترة مغلقة. لا يمكن إضافة أو تعديل سندات في هذه الفترة ($date).");
            }

            $payer_type = $_POST['payer_type'];
            $payer_id = $_POST['payer_id']; // now account id
            $amount = $_POST['amount'];
            $currency_id = $_POST['currency_id'];
            $account_id = $_POST['account_id'];
            $description = $_POST['description'];
            $allocations = $_POST['allocations'] ?? []; // [invoice_id => amount]
            $cost_center_id = !empty($_POST['cost_center_id']) ? (int)$_POST['cost_center_id'] : null;

            // Check require cost center setting
            $stmt_setting = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'require_cost_center'");
            $stmt_setting->execute();
            $require_cost_center = (bool)$stmt_setting->fetchColumn();
            
            if ($require_cost_center && !$cost_center_id) {
                throw new Exception("يرجى اختيار مركز التكلفة.");
            }

            // التحقق من صحة حساب الصندوق/البنك
            $cash_bank_validation = validate_postable_account($pdo, $account_id);
            if (!$cash_bank_validation['valid']) {
                throw new Exception($cash_bank_validation['message']);
            }

            // التحقق من صحة حساب الدافع
            $payer_validation = validate_postable_account($pdo, $payer_id);
            if (!$payer_validation['valid']) {
                throw new Exception($payer_validation['message']);
            }

            // تحديد entity_id - ابحث عن الكيان من رقم الحساب
            $entity_id = $payer_id; // default to account id
            $table_map = ['customer' => 'customers', 'agent' => 'agents', 'supplier' => 'suppliers', 'employee' => 'employees', 'branch' => 'branches'];
            if (isset($table_map[$payer_type])) {
                $stmt_entity = $pdo->prepare("SELECT id FROM " . $table_map[$payer_type] . " WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL");
                $stmt_entity->execute([$payer_id]);
                $found_entity_id = $stmt_entity->fetchColumn();
                if ($found_entity_id) {
                    $entity_id = $found_entity_id;
                }
            }


            // جلب حساب الطرف - الآن payer_id هو رقم الحساب مباشرة
            $party_account_id = $payer_id;

            // التحقق من صحة الحساب
            $stmt_check = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ? AND is_active = 1");
            $stmt_check->execute([$party_account_id]);
            if (!$stmt_check->fetch()) {
                throw new Exception("الحساب المحدد غير صالح أو غير نشط.");
            }

            // Validate currency for the cash/bank account ($account_id)
            $stmt_check_currency_cash_bank = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ? AND is_frozen = 0");
            $stmt_check_currency_cash_bank->execute([$account_id, $currency_id]);
            if ($stmt_check_currency_cash_bank->fetchColumn() == 0) {
                throw new Exception("العملة المحددة غير مفعلة أو مجمدة لحساب الصندوق/البنك المحدد.");
            }

            // Validate currency for the payer's account ($party_account_id)
            $stmt_check_currency_payer = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ? AND is_frozen = 0");
            $stmt_check_currency_payer->execute([$party_account_id, $currency_id]);
            if ($stmt_check_currency_payer->fetchColumn() == 0) {
                throw new Exception("العملة المحددة غير مفعلة أو مجمدة لحساب المستفيد.");
            }

            // التحقق من الحدود المالية باستخدام الدالة الموحدة
            // 1. حساب الصندوق/البنك (المستلم): يزيد رصيده
            check_account_limits($pdo, $account_id, $currency_id, $amount);

            // 2. حساب الدافع (العميل/الوكيل): ينقص رصيده
            check_account_limits($pdo, $party_account_id, $currency_id, -$amount);

            // حساب إجمالي التوزيعات
            $total_allocated = 0;
            if (!empty($allocations)) {
                foreach ($allocations as $inv_id => $alloc_amount) {
                    $total_allocated += floatval($alloc_amount);
                }
            }

            // إذا كان المبلغ الإجمالي أكبر من التوزيعات، سنقوم بتقسيمه
            $remaining_advance = $amount - $total_allocated;

            if ($total_allocated > $amount) {
                throw new Exception("المبلغ الموزع ($total_allocated) أكبر من مبلغ السند ($amount).");
            }

            if ($id) {
                // تعديل (للمسودة فقط)
                $stmt_old = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                $stmt_old->execute([$id]);
                $old_voucher = $stmt_old->fetch();
                if (!can_edit_voucher($old_voucher))
                    throw new Exception("لا يمكن تعديل هذا السند.");

                // جلب التوزيعات القديمة - بدون خصم من الفواتير
                // يتم الخصم من الفواتير فقط عند الترحيل
                $stmt_old_allocs = $pdo->prepare("SELECT invoice_id, allocated_amount FROM payment_allocations WHERE financial_transaction_id = ?");
                $stmt_old_allocs->execute([$id]);
                $old_allocs = $stmt_old_allocs->fetchAll();

                $stmt = $pdo->prepare("UPDATE financial_transactions SET
                    transaction_date = ?, entity_type = ?, entity_id = ?, party_account_id = ?,
                    cash_bank_account_id = ?, currency_id = ?, amount = ?, description = ?,
                    cost_center_id = ?,
                    updated_at = NOW(), updated_by = ?, updated_ip = ?
                    WHERE id = ?");
                $stmt->execute([$date, $payer_type, $entity_id, $party_account_id, $account_id, $currency_id, $amount, $description, $cost_center_id, $_SESSION['admin_id'], $_SERVER['REMOTE_ADDR'], $id]);

                $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$id]);
                $voucher_id = $id;

                $stmt_new = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                $stmt_new->execute([$id]);
                $new_voucher = $stmt_new->fetch();

                $new_voucher['allocations'] = $allocations;

                log_audit($pdo, 'update', 'financial_transactions', $id, $old_voucher, $new_voucher, "تعديل سند قبض");
            } else {
                // Use PHP code to create the voucher
                require_once '../includes/accounting_functions.php';

                // Generate transaction number
                $transaction_number = fn_get_next_sequence($pdo, 'receipt');

                // Get exchange rate
                $stmt_curr = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE id = ?");
                $stmt_curr->execute([$currency_id]);
                $exchange_rate = (float)($stmt_curr->fetchColumn() ?: 1);

                // Insert voucher
                $stmt = $pdo->prepare("INSERT INTO financial_transactions (
                    transaction_number, transaction_date, branch_id, transaction_type,
                    entity_type, entity_id, amount, currency_id, cash_bank_account_id, party_account_id,
                    description, cost_center_id, created_by, exchange_rate, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
                $stmt->execute([
                    $transaction_number,
                    $date,
                    $_SESSION['branch_id'],
                    'receipt',
                    $payer_type,
                    $entity_id,
                    $amount,
                    $currency_id,
                    $account_id,
                    $party_account_id,
                    $description,
                    $cost_center_id,
                    $_SESSION['admin_id'],
                    $exchange_rate
                ]);
                $voucher_id = $pdo->lastInsertId();

                $stmt_new = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
                $stmt_new->execute([$voucher_id]);
                $new_voucher = $stmt_new->fetch();
                $new_voucher['allocations'] = $allocations;

                log_audit($pdo, 'create', 'financial_transactions', $voucher_id, null, $new_voucher, "إضافة سند قبض جديد");
            }


            if (!empty($allocations)) {
                foreach ($allocations as $inv_id => $alloc_amount) {
                    $alloc_amount = floatval($alloc_amount);
                    if ($alloc_amount > 0) {
                        $pdo->prepare("INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)")
                            ->execute([$voucher_id, $inv_id, $alloc_amount]);
                    }
                }
            }

            $pdo->commit();
            header("Location: receipts.php?success=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = $e->getMessage();
        }
    }
    }
}

// جلب سندات القبض
$where_clauses = ["transaction_type = 'receipt'"];
$params = [];
if (!empty($_GET['search_num'])) {
    $where_clauses[] = "transaction_number LIKE ?";
    $params[] = '%' . $_GET['search_num'] . '%';
}
if (!empty($_GET['from_date'])) {
    $where_clauses[] = "transaction_date >= ?";
    $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_clauses[] = "transaction_date <= ?";
    $params[] = $_GET['to_date'];
}
$where_sql = implode(' AND ', $where_clauses);

$query = "
    SELECT t.*, c.currency_symbol, coa.account_name_ar as account_name,
           (SELECT account_name_ar FROM unified_accounts WHERE id = t.party_account_id) as party_name,
           (SELECT i.invoice_number FROM payment_allocations pa
            JOIN invoices i ON pa.invoice_id = i.id
            WHERE pa.financial_transaction_id = t.id LIMIT 1) as linked_invoice,
           (SELECT COUNT(*) FROM payment_allocations WHERE financial_transaction_id = t.id) as alloc_count,
           (SELECT EXISTS(SELECT 1 FROM financial_transactions rt 
                          WHERE rt.reference_type = 'reversal' AND rt.reference_id = t.id LIMIT 1)) as has_reversal,
           (SELECT rt.transaction_number
            FROM financial_transactions rt
            WHERE rt.reference_type = 'reversal' AND rt.reference_id = t.id
            ORDER BY rt.id DESC
            LIMIT 1) as reversal_transaction_number,
           (SELECT ot.transaction_number
            FROM financial_transactions ot
            WHERE ot.id = t.reference_id
            LIMIT 1) as original_transaction_number
    FROM financial_transactions t
    JOIN unified_accounts coa ON t.cash_bank_account_id = coa.id
    JOIN currencies c ON t.currency_id = c.id
    WHERE $where_sql
    ORDER BY t.transaction_date DESC, t.created_at DESC
";
$receipts = $pdo->prepare($query);
$receipts->execute($params);
$receipts = $receipts->fetchAll();

$accounts = get_cash_bank_postable_accounts($pdo);
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies")->fetchAll();
$cost_centers = $pdo->query("SELECT id, center_code, center_name_ar FROM cost_centers WHERE is_active = 1 ORDER BY center_code ASC")->fetchAll();
$settings = getSettings($pdo);
$require_cost_center = !empty($settings['require_cost_center']);
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --apple-bg: #f5f5f7;
        --apple-card-bg: rgba(255, 255, 255, 0.72);
        --apple-blue: #007aff;
        --apple-green: #34c759;
        --apple-red: #ff3b30;
        --apple-orange: #ff9500;
        --apple-gray: #8e8e93;
        --apple-radius: 20px;
        --apple-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        --transition-base: all 0.3s cubic-bezier(0.25, 0.1, 0.25, 1);
    }

    body {
        background-color: var(--apple-bg);
        font-family: 'Inter', -apple-system, sans-serif;
        color: #1d1d1f;
    }

    .apple-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2rem;
    }

    .apple-card {
        background: var(--apple-card-bg);
        backdrop-filter: blur(20px);
        border-radius: var(--apple-radius);
        border: 1px solid rgba(255, 255, 255, 0.4);
        box-shadow: var(--apple-shadow);
        margin-bottom: 2rem;
        overflow: hidden;
    }

    .apple-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }

    .btn-apple-primary {
        background: var(--apple-blue);
        color: white;
        border: none;
        padding: 0.6rem 1.5rem;
        border-radius: 40px;
        font-weight: 600;
        transition: var(--transition-base);
    }

    .btn-apple-primary:hover {
        background: #0066cc;
        transform: scale(1.02);
    }

    .apple-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .apple-table th {
        background: rgba(0, 0, 0, 0.02);
        padding: 1rem;
        font-weight: 600;
        color: var(--apple-gray);
        font-size: 0.85rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .apple-table td {
        padding: 1rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.03);
        vertical-align: middle;
    }

    .apple-badge {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.75rem;
    }

    .bg-draft {
        background: #fff3cd;
        color: #856404;
    }

    .bg-posted {
        background: #d4edda;
        color: #155724;
    }

    .bg-cancelled {
        background: #f8d7da;
        color: #721c24;
    }

    .bg-reversal {
        background: #fff3cd;
        color: #8a5300;
    }

    .apple-modal {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 28px;
        border: 1px solid rgba(255, 255, 255, 0.4);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.1);
    }

    .apple-input {
        background: rgba(0, 0, 0, 0.04);
        border: 1px solid transparent;
        border-radius: 12px;
        padding: 0.5rem 0.8rem;
        font-size: 0.9rem;
    }

    .apple-input:focus {
        background: white;
        border-color: var(--apple-blue);
        box-shadow: 0 0 0 4px rgba(0, 122, 255, 0.1);
        outline: none;
    }
    
    .modal-dialog-scrollable .modal-content {
        max-height: calc(100vh - 3.5rem);
    }
    
    .modal-dialog-scrollable .modal-body {
        overflow-y: auto;
    }
    
    .modal-dialog-scrollable .modal-footer {
        position: sticky;
        bottom: 0;
        background: inherit;
        z-index: 10;
    }
</style>

<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">سندات القبض</h1>
            <p class="text-muted small mb-0">متابعة جميع التحصيلات وربطها بالفواتير</p>
        </div>
        <button class="btn-apple-primary" data-bs-toggle="modal" data-bs-target="#receiptModal" onclick="resetForm()">
            <i class="fas fa-plus me-2"></i> إضافة سند قبض
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 rounded-4 shadow-sm mb-4">تمت العملية بنجاح.</div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="apple-card p-4">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <input type="text" name="search_num" class="form-control apple-input" placeholder="رقم السند..."
                    value="<?php echo h($_GET['search_num'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <input type="date" name="from_date" class="form-control apple-input"
                    value="<?php echo h($_GET['from_date'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <input type="date" name="to_date" class="form-control apple-input"
                    value="<?php echo h($_GET['to_date'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-dark rounded-pill px-4 w-100">بحث</button>
            </div>
        </form>
    </div>

    <div class="apple-card">
        <div class="table-responsive"><table class="apple-table">
            <thead>
                <tr>
                    <th>رقم السند</th>
                    <th>التاريخ</th>
                    <th>الجهة</th>
                    <th>الفاتورة / الفواتير</th>
                    <th>الحساب</th>
                    <th>المبلغ</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receipts as $r):
                    // جلب معلومات التوزيعات بدون التأثير على الفواتير
                    $stmt_pa = $pdo->prepare("SELECT COUNT(*) as inv_count, SUM(allocated_amount) as total_alloc FROM payment_allocations WHERE financial_transaction_id = ?");
                    $stmt_pa->execute([$r['id']]);
                    $alloc_info = $stmt_pa->fetch();
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?php echo h($r['transaction_number']); ?></div>
                            <?php if (($r['reference_type'] ?? '') === 'reversal' && !empty($r['original_transaction_number'])): ?>
                                <div class="small text-warning">سند عكسي لـ <?php echo h($r['original_transaction_number']); ?></div>
                            <?php elseif (!empty($r['reversal_transaction_number'])): ?>
                                <div class="small text-success">تم عكسه بسند <?php echo h($r['reversal_transaction_number']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($r['transaction_date']); ?></td>
                        <td>
                            <div class="fw-bold"><?php echo h($r['party_name']); ?></div>
                            <div class="small text-muted">
                                <?php
                                $type_map = ['customer' => 'عميل', 'agent' => 'وكيل', 'supplier' => 'مورد', 'employee' => 'موظف', 'branch' => 'فرع', 'bank' => 'بنك', 'cash' => 'صندوق', 'expense' => 'حساب عام/آخر'];
                                echo h($type_map[$r['entity_type']] ?? $r['entity_type']);
                                ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($r['linked_invoice']): ?>
                                <a href="invoice_details.php?id=<?php
                                    $stmt_get_id = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
                                    $stmt_get_id->execute([$r['linked_invoice']]);
                                    echo h($stmt_get_id->fetchColumn());
                                ?>" class="badge bg-primary-subtle text-primary text-decoration-none border border-primary-subtle p-2 rounded-3">
                                    <i class="fas fa-file-invoice me-1"></i>
                                    <?php echo h($r['linked_invoice']); ?>
                                    <?php if ($r['alloc_count'] > 1): ?>
                                        <span class="badge bg-primary ms-1">+<?php echo h($r['alloc_count'] - 1); ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">---</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($r['account_name']); ?></td>
                        <td class="fw-bold text-primary"><?php echo number_format($r['amount'], 2); ?>
                            <?php echo h($r['currency_symbol']); ?>
                        </td>
                        <td>
                            <?php if (($r['reference_type'] ?? '') === 'reversal'): ?>
                                <span class="apple-badge bg-reversal">سند عكسي</span>
                            <?php elseif ($r['has_reversal']): ?>
                                <span class="apple-badge bg-cancelled">معكوس</span>
                            <?php elseif ($r['status'] == 'draft'): ?>
                                <span class="apple-badge bg-draft">مسودة</span>
                            <?php elseif ($r['status'] == 'posted'): ?>
                                <span class="apple-badge bg-posted">مرحل</span>
                            <?php else: ?>
                                <span class="apple-badge bg-cancelled">ملغي</span>
                            <?php endif; ?>

                            <?php if ($alloc_info['inv_count'] > 0): ?>
                                <div class="mt-1"><span
                                        class="badge bg-info-subtle text-info border border-info-subtle small">تسوية فواتير
                                        (<?php echo $alloc_info['inv_count']; ?>)</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-light rounded-circle me-1"
                                onclick="viewVoucher(<?php echo $r['id']; ?>)" title="عرض"><i
                                    class="fas fa-eye"></i></button>

                            <?php if (in_array($r['status'], ['draft', 'cancelled']) && !$r['has_reversal']): ?>
                                <?php if (can_edit_voucher($r)): ?>
                                    <button class="btn btn-sm btn-light rounded-circle me-1"
                                        onclick="editVoucher(<?php echo $r['id']; ?>)" title="تعديل"><i
                                            class="fas fa-edit"></i></button>
                                <?php endif; ?>
                                <?php if (can_post_voucher($r)): ?>
                                    <button class="btn btn-sm btn-success rounded-circle me-1 text-white"
                                        onclick="postVoucher(<?php echo $r['id']; ?>)" title="ترحيل"><i
                                            class="fas fa-upload"></i></button>
                                <?php endif; ?>
                            <?php elseif ($r['status'] == 'posted' && can_reverse_voucher($r) && (($r['reference_type'] ?? '') !== 'reversal') && !$r['has_reversal']): ?>
                                <button class="btn btn-sm btn-danger rounded-circle me-1 text-white"
                                    onclick="cancelVoucher(<?php echo $r['id']; ?>)" title="إلغاء الترحيل"><i
                                        class="fas fa-undo"></i></button>
                            <?php endif; ?>

                            <?php if (in_array($r['status'], ['draft', 'cancelled']) && can_delete_voucher($r) && !$r['has_reversal']): ?>
                                <button class="btn btn-sm btn-outline-danger rounded-circle me-1"
                                    onclick="deleteVoucher(<?php echo $r['id']; ?>)" title="حذف"><i
                                        class="fas fa-trash"></i></button>
                            <?php endif; ?>

                            <a href="print_receipt.php?id=<?php echo $r['id']; ?>" target="_blank"
                                class="btn btn-sm btn-light rounded-circle"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- Modal إضافة/تعديل سند قبض -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content apple-modal">
            <form method="POST" id="voucherForm">
                <?php echo csrf_input(); ?>
                <div class="modal-header border-0 p-4">
                    <h5 class="fw-bold" id="modalTitle">سند قبض جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pb-4">
                    <input type="hidden" name="edit_receipt_id" id="edit_receipt_id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="small fw-bold mb-1">نوع الجهة</label>
                            <select name="payer_type" id="payer_type" class="form-select apple-input" required
                                tabindex="1">
                                <option value="">اختر نوع الجهة</option>
                                <option value="customer">عميل</option>
                                <option value="agent">وكيل</option>
                                <option value="supplier">مورد</option>
                                <option value="employee">موظف</option>
                                <option value="branch">فرع</option>
                                <option value="bank">بنك</option>
                                <option value="cash">صندوق</option>
                                <option value="expense">حساب عام/آخر</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold mb-1">العملة</label>
                            <select name="currency_id" id="currency_id" class="form-select apple-input" required
                                tabindex="2">
                                <option value="">اختر العملة</option>
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo h($c['currency_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold mb-1">التاريخ</label>
                            <input type="date" name="date" id="date" class="form-control apple-input"
                                value="<?php echo date('Y-m-d'); ?>" required tabindex="3">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold mb-1">الجهة</label>
                            <select name="payer_id" id="payer_id" class="form-select apple-input" required tabindex="4">
                                <option value="">اختر الجهة...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold mb-1">الصندوق / البنك</label>
                            <select name="account_id" id="account_id" class="form-select apple-input" required
                                tabindex="5">
                                <option value="">اختر...</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo h($a['account_name']); ?>
                                        (<?php echo h($a['account_code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="cost-center-wrapper" class="col-md-6" style="display: <?php echo $require_cost_center ? 'block' : 'none'; ?>;">
                            <label class="small fw-bold mb-1">مركز التكلفة</label>
                            <select name="cost_center_id" id="cost_center_id" class="form-select apple-input" tabindex="5.5" <?php echo $require_cost_center ? 'required' : ''; ?>>
                                <option value="">-- بدون مركز تكلفة --</option>
                                <?php foreach ($cost_centers as $cc): ?>
                                    <option value="<?php echo $cc['id']; ?>"><?php echo h($cc['center_code'] . ' - ' . $cc['center_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- قسم الفواتير غير المسددة -->
                        <div id="invoices_section" class="col-12 d-none">
                            <div class="apple-card p-3 bg-light border-0 shadow-none"
                                style="max-height: 250px; overflow-y: auto;">
                                <h6 class="fw-bold mb-2 small">الفواتير غير المسددة</h6>
                                <div id="currency_alert" class="alert alert-warning extra-small p-2 d-none">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    تنبيه: توجد فواتير غير مسددة بعملات أخرى.
                                </div>
                                <div id="invoices_list" class="table-responsive">
                                    <!-- سيتم تحميل الفواتير عبر AJAX -->
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="small fw-bold mb-1">المبلغ المستلم</label>
                            <input type="number" step="0.01" name="amount" id="amount"
                                class="form-control apple-input fw-bold" required tabindex="6">
                        </div>
                        <div class="col-md-8">
                            <label class="small fw-bold mb-1">المبلغ بالحروف</label>
                            <div id="amount_text" class="p-2 bg-light rounded-3 extra-small text-muted"
                                style="min-height: 38px;">---</div>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold mb-1">البيان</label>
                            <textarea name="description" id="description" class="form-control apple-input" rows="2"
                                tabindex="7" placeholder="اكتب تفاصيل سند القبض..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0 d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-muted text-decoration-none order-2 order-md-1"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_receipt" class="btn-apple-primary px-5 w-100 w-md-auto order-1 order-md-2 mb-2 mb-md-0" tabindex="8">حفظ
                        السند</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal عرض تفاصيل السند -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content apple-modal p-4" id="viewContent">
            <!-- سيتم تحميل تفاصيل السند عبر AJAX -->
        </div>
    </div>
</div>

<script src="assets/js/tafqeet.js"></script>
<script>
    function resetForm() {
        $('#voucherForm')[0].reset();
        $('#edit_receipt_id').val('');
        $('#modalTitle').text('سند قبض جديد');
        $('#invoices_section').addClass('d-none');
        $('#invoices_list').empty();
        $('#amount_text').text('---');
    }

    function loadEntities(type, selectedId = null) {
        return new Promise((resolve, reject) => {
            $('#payer_id').html('<option value="">جاري التحميل...</option>');
            $.get('ajax/get_entities.php', {
                type: type,
                _: new Date().getTime()
            }, function (res) {
                let options = '<option value="">اختر الجهة...</option>';
                let dataArray = Array.isArray(res) ? res : (res.entities ? res.entities : []);

                if (dataArray.length > 0) {
                    dataArray.forEach(function (item) {
                        let selected = (selectedId && item.account_id == selectedId) ? 'selected' : '';
                        let entityIdAttr = item.id ? `data-entity-id="${item.id}"` : '';
                        options += `<option value="${item.account_id}" ${entityIdAttr} ${selected}>${item.name}</option>`;
                    });
                } else {
                    options = '<option value="">لا توجد بيانات</option>';
                }
                $('#payer_id').html(options);

                if (['supplier', 'customer', 'agent', 'branch', 'employee'].includes(type)) {
                    $('#invoices_section').removeClass('d-none');
                } else {
                    $('#invoices_section').addClass('d-none');
                    $('#invoices_list').empty();
                }
                resolve();
            }).fail(function () {
                $('#payer_id').html('<option value="">خطأ في التحميل</option>');
                reject();
            });
        });
    }

    $('#payer_type').on('change', function () {
        loadEntities($(this).val());
    });

    $('#payer_id').on('change', async function () {
        let type = $('#payer_type').val();
        let accountId = $('#payer_id').val();
        let currencyId = $('#currency_id').val();

        if (accountId) {
            await filterCurrenciesByAccount(accountId);
            // After filter, get the updated currencyId
            currencyId = $('#currency_id').val();
        } else {
            // Reset to all currencies if no account
            $('#currency_id option').show();
        }

        if (['customer', 'agent'].includes(type) && accountId && currencyId) {
            loadUnpaidInvoices();
        }
    });

    let currentAccountCurrencies = [];

    function filterCurrenciesByAccount(accountId) {
        return new Promise((resolve, reject) => {
            $.get('ajax/get_account_currencies.php', { account_id: accountId }, function(res) {
                const $currencySelect = $('#currency_id');
                const selectedVal = $currencySelect.val();
                currentAccountCurrencies = res.currencies || [];

                $currencySelect.find('option').each(function() {
                    const optVal = $(this).val();
                    if (optVal === "") return;

                    const found = currentAccountCurrencies.find(c => c.id == optVal);
                    if (found) {
                        $(this).show();
                    } else {
                        $(this).hide();
                        if (selectedVal == optVal) $currencySelect.val('');
                    }
                });

                if (currentAccountCurrencies.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'تنبيه',
                        text: 'هذا الحساب لا يملك عملات مفعلة. يرجى تفعيل العملات له أولا من إعدادات الحسابات.'
                    });
                }
                resolve();
            }).fail(reject);
        });
    }

    $('#currency_id').on('change', function () {
        let type = $('#payer_type').val();
        let payerId = $('#payer_id').val();
        let currencyId = $(this).val();

        if (['customer', 'agent'].includes(type) && payerId && currencyId) {
            loadUnpaidInvoices();
        }

        if (typeof checkAccountLimit === 'function') checkAccountLimit();
    });

    function loadUnpaidInvoices(editVoucherId = null) {
        let payer_id = $('#payer_id').val();
        let currency_id = $('#currency_id').val();
        let type = $('#payer_type').val();
        let voucher_id = editVoucherId || $('#edit_receipt_id').val() || 0;

        $.get('ajax/get_unpaid_invoices.php', {
            customer_id: payer_id,
            currency_id: currency_id,
            type_payer: type,
            type: 'sales',
            voucher_id: voucher_id
        }, function (res) {
            if (res.error) {
                $('#invoices_list').html(`<p class="text-danger">خطأ: ${res.error}</p>`);
                return;
            }

            let invoices = res.invoices || [];
            let others = res.other_currencies || [];

            if (others && others.length > 0) {
                let alertHtml = '<i class="fas fa-exclamation-triangle me-1"></i> تنبيه: توجد فواتير غير مسددة بعملات أخرى: ';
                others.forEach(function (o, i) {
                    alertHtml += `<strong>${o.currency_name} (${o.currency_symbol})</strong>`;
                    if (i < others.length - 1) alertHtml += '، ';
                });
                $('#currency_alert').html(alertHtml).removeClass('d-none');
            } else {
                $('#currency_alert').addClass('d-none');
            }

            let html = '<table class="table table-sm small"><thead><tr><th><input type="checkbox" id="check_all"></th><th>رقم الفاتورة</th><th>التاريخ</th><th>المبلغ المستحق</th><th>المتبقي</th><th>المسدد</th><th>المتبقي بعد السداد</th></tr></thead><tbody>';

            if (invoices.length === 0) {
                html += '<tr><td colspan="7" class="text-center text-muted p-3">لا توجد فواتير غير مسددة لهذه الجهة</td></tr>';
            } else {
                invoices.forEach(function (inv) {
                    let isChecked = inv.current_allocated > 0 ? 'checked' : '';
                    let isDisabled = inv.current_allocated > 0 ? '' : 'disabled';
                    let allocVal = inv.current_allocated > 0 ? inv.current_allocated : '';
                    let futureRem = (parseFloat(inv.remaining) - parseFloat(inv.current_allocated || 0)).toFixed(2);

                    html += `<tr>
                            <td><input type="checkbox" class="invoice-checkbox" data-id="${inv.id}" data-remaining="${inv.remaining}" ${isChecked}></td>
                            <td>${inv.invoice_number}</td>
                            <td>${inv.invoice_date || ''}</td>
                            <td>${inv.net_amount} ${inv.currency_symbol || ''}</td>
                            <td class="text-danger current-remaining">${inv.remaining}</td>
                            <td><input type="number" step="0.01" class="form-control form-control-sm alloc-input" name="allocations[${inv.id}]" value="${allocVal}" max="${inv.remaining}" ${isDisabled}></td>
                            <td class="text-primary future-remaining">${futureRem}</td>
                        </tr>`;
                });
            }
            html += '</tbody></table></div>';
            $('#invoices_list').html(html);

            $('.invoice-checkbox').on('change', function () {
                let row = $(this).closest('tr');
                let input = row.find('.alloc-input');
                if ($(this).is(':checked')) {
                    let voucherAmount = parseFloat($('#amount').val() || 0);
                    let currentAllocated = 0;
                    $('.alloc-input:not(:disabled)').each(function () {
                        currentAllocated += parseFloat($(this).val() || 0);
                    });

                    let remainingInVoucher = Math.max(0, voucherAmount - currentAllocated);
                    let invoiceRemaining = parseFloat($(this).data('remaining'));
                    let defaultValue = Math.min(invoiceRemaining, remainingInVoucher);
                    if (defaultValue <= 0) defaultValue = invoiceRemaining;

                    input.prop('disabled', false).val(defaultValue.toFixed(2));
                } else {
                    input.prop('disabled', true).val('');
                }
                calculateTotal('allocation');
            });

            $('.alloc-input').on('input', function () {
                let row = $(this).closest('tr');
                let alloc = parseFloat($(this).val() || 0);
                let current = parseFloat(row.find('.current-remaining').text());
                row.find('.future-remaining').text((current - alloc).toFixed(2));
                calculateTotal('allocation');
            });

            $('#check_all').on('change', function () {
                let checked = $(this).is(':checked');
                $('.invoice-checkbox').prop('checked', checked).trigger('change');
            });
        });
    }

    function calculateTotal(source = 'input') {
        let totalAllocated = 0;
        $('.alloc-input:not(:disabled)').each(function () {
            totalAllocated += parseFloat($(this).val() || 0);
        });

        if (source === 'allocation') {
            $('#amount').val(totalAllocated.toFixed(2));
        }

        updateTafqeet();
        updateDescription();
    }

    function updateDescription() {
        let name = $('#payer_id option:selected').text();

        if (name && name !== 'اختر الجهة...') {
            let selectedInvoices = [];
            $('.invoice-checkbox:checked').each(function () {
                let invNum = $(this).closest('tr').find('td:eq(1)').text();
                selectedInvoices.push(invNum);
            });

            let desc = selectedInvoices.length > 0 ?
                `سداد فواتير (${selectedInvoices.join(', ')}) - ${name}` :
                `سند قبض من الحساب - ${name}`;

            $('#description').val(desc);
        }
    }

    function checkAccountLimit() {
        const accountId = $('#payer_id').find(':selected').data('account-id');
        const currencyId = $('#currency_id').val();
        const amount = parseFloat($('#amount').val()) || 0;

        if (!accountId || !currencyId || amount <= 0) return;

        const currencyData = currentAccountCurrencies.find(c => c.id == currencyId);
        if (currencyData) {
            const currentBalance = parseFloat(currencyData.current_balance);
            const debitLimit = parseFloat(currencyData.debit_limit);

            // مثال على سند القبض: مدين الصندوق (حساب الصندوق له رصيد مدين) ودين العميل (حساب العميل له رصيد دائن)
            // القواعد المحاسبية: Debit Box, Credit Payer
            // الحسابات التي طبيعتها مدين (Normal Balance = Debit): نزيدها بالمدين
            // الحسابات التي طبيعتها دائن (Normal Balance = Credit): نزيدها بالدائن

            // سنقوم بالتحقق من الحد المدين (Debit Limit) لهذا الحساب لتأكد من أن العملية لن تتجاوز الحد المسموح به، وخاصة لحسابات الصندوق والبنك التي لا يمكن أن تكون سالبة
            // سنستخدم دالة "check_account_limits" للتحقق من الحدود المسموح بها للحساب

            // سنقوم بتحويل المبلغ من حساب العميل (حساب دائن يقل رصيده) إلى حساب الصندوق (حساب مدين يزيد رصيده)
            // مع الأخذ في الاعتبار الحدود المالية للعملية.
        }
    }

    $('#voucherForm').on('submit', function(e) {
        const currencyId = $('#currency_id').val();
        if (currencyId && currentAccountCurrencies.length > 0) {
            const found = currentAccountCurrencies.find(c => c.id == currencyId);
            if (!found) {
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'العملة المختارة غير مفعلة لهذا الحساب.' });
                e.preventDefault();
                return false;
            }
        }
        return true;
    });

    $('#amount').on('input', function () {
        updateTafqeet();
        if (typeof checkAccountLimit === 'function') checkAccountLimit();

        let totalVoucherAmount = parseFloat($(this).val() || 0);
        let remainingToAllocate = totalVoucherAmount;

        $('.invoice-checkbox:checked').each(function () {
            let row = $(this).closest('tr');
            let input = row.find('.alloc-input');
            let invoiceRemaining = parseFloat($(this).data('remaining'));

            let alloc = Math.min(invoiceRemaining, remainingToAllocate);
            input.val(alloc > 0 ? alloc.toFixed(2) : '');
            remainingToAllocate -= alloc;

            let current = parseFloat(row.find('.current-remaining').text());
            row.find('.future-remaining').text((current - (alloc > 0 ? alloc : 0)).toFixed(2));
        });

        calculateTotal('amount_input');
    });

    function updateTafqeet() {
        let amount = $('#amount').val();
        if (amount) {
            $('#amount_text').text(tafqeet(amount));
        } else {
            $('#amount_text').text('---');
        }
    }

    window.editVoucherLocal = function (id) {
        $.get('ajax/get_voucher_details.php', { id: id }, function (v) {
            resetForm();
            $('#edit_receipt_id').val(v.id);
            $('#modalTitle').text('تعديل سند قبض: ' + v.transaction_number);
            $('#date').val(v.transaction_date);
            $('#payer_type').val(v.entity_type);

            loadEntities(v.entity_type, v.party_account_id).then(() => {
                $('#payer_id').val(v.party_account_id);
                $('#currency_id').val(v.currency_id);
                $('#account_id').val(v.cash_bank_account_id);
                $('#amount').val(v.amount);
                $('#description').val(v.description);
                $('#cost_center_id').val(v.cost_center_id);
                updateTafqeet();

                if (['customer', 'agent'].includes(v.entity_type)) {
                    // لتحميل الفواتير غير المسددة مع تمرير voucher_id لتسجيل التوزيعات الحالية
                    loadUnpaidInvoices(v.id);

                    // سنستخدم Interval بدلاً من MutationObserver للتحقق من تحميل العناصر
                    const checkInterval = setInterval(() => {
                        if ($('.invoice-checkbox').length > 0) {
                            clearInterval(checkInterval);
                            if (v.allocations && v.allocations.length > 0) {
                                v.allocations.forEach(function (alloc) {
                                    let checkbox = $(`.invoice-checkbox[data-id="${alloc.invoice_id}"]`);
                                    if (checkbox.length) {
                                        checkbox.prop('checked', true);
                                        let input = $(`input[name="allocations[${alloc.invoice_id}]"]`);
                                        if (input.length) {
                                            input.val(alloc.allocated_amount).prop('disabled', false);
                                        }
                                    }
                                });
                                calculateTotal('amount_input');
                            }
                        }
                    }, 100);

                    // إيقاف الفحص بعد 3 ثوانٍ كوقت احتياطي لمنع التكرار إلى الأبد
                    setTimeout(() => clearInterval(checkInterval), 3000);
                }
                $('#receiptModal').modal('show');
            });
        });
    }

    <?php if (!empty($_GET['edit_id'])): ?>
        $(function () {
            window.editVoucherLocal(<?php echo (int) $_GET['edit_id']; ?>);
        });
    <?php endif; ?>

    const CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
        const REQUIRE_COST_CENTER = <?php echo $require_cost_center ? 'true' : 'false'; ?>;
</script>
<!-- إضافة ملفات الجافاسكريبت الخاصة بإجراءات السندات -->
<script src="assets/js/receipts-actions.js?v=<?php echo time(); ?>"></script>
<?php require_once 'footer.php'; ?>
