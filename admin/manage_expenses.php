<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/CurrencyExchange.php';

$currencyExchange = new CurrencyExchange($pdo);
$baseCurrency = $currencyExchange->getBaseCurrency();
$base_currency_id = $baseCurrency['id'] ?? null;

// التحقق من صلاحيات المستخدم
if (!has_permission('manage_expenses')) {
    echo "<script>alert('ليس لديك صلاحية لإدارة الحسابات المصروفة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// إضافة حساب مصروفات
if (isset($_POST['add_expense_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_expenses.php';</script>");
    }
    $account_name = $_POST['account_name'];
    $parent_id = $_POST['parent_id'];
    $opening_balance = $_POST['opening_balance'] ?? 0;
    $currency_id = $_POST['currency_id'] ?? 1;
    $status = $_POST['status'] == 'active' ? 'active' : $_POST['status'];

    try {
        $pdo->beginTransaction();

        // جلب معلومات الحساب الأصل لتحديد الكود ونوع الحساب
        $stmt_parent = $pdo->prepare("SELECT account_code, account_type FROM unified_accounts WHERE id = ?");
        $stmt_parent->execute([$parent_id]);
        $parent = $stmt_parent->fetch();
        
        if (!$parent) throw new Exception("الطلب غير صالح، يرجى تحديد حساب أصل صالح.");

        // توليد كود حساب جديد
        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ?");
        $stmt_last->execute([$parent_id]);
        $last_code = $stmt_last->fetchColumn();
        
        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = $parent['account_code'] . '001';
        }

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status) VALUES (?, ?, 'expense', 'debit', ?, ?)");
        $stmt->execute([$new_code, $account_name, $parent_id, $status]);
        
        $new_account_id = $pdo->lastInsertId();
        
        // تفعيل العملة الأساسية للحساب الجديد - فقط العملة الأساسية للنظام
        if ($base_currency_id) {
            $opening_balance_for_base = $_POST['opening_balance'] ?? 0;
            $stmt_base_balance = $pdo->prepare("INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) VALUES (?, ?, ?, ?, 0)");
            $stmt_base_balance->execute([$new_account_id, $base_currency_id, $opening_balance_for_base, $opening_balance_for_base]);
        }

        $pdo->commit();
        echo "<script>location.href='manage_expenses.php?success=1';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء إضافة الحساب: " . $e->getMessage();
    }
}

// تحديث حساب مصروفات
if (isset($_POST['update_expense_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_expenses.php';</script>");
    }
    $id = $_POST['id'];
    $account_name = $_POST['account_name'];
    $new_status = $_POST['status'];

    try {
        $pdo->beginTransaction();
        
        // الحصول على الحالة الحالية
        $stmt_get_current = $pdo->prepare("SELECT account_status FROM unified_accounts WHERE id = ?");
        $stmt_get_current->execute([$id]);
        $current_status = $stmt_get_current->fetchColumn();
        
        // إذا كنا نريد تغيير الحالة إلى مغلق، تحقق من أن الرصيد صفر
        if ($new_status === 'closed' && $current_status !== 'closed') {
            $stmt_check_balance = $pdo->prepare("SELECT SUM(current_balance) as total FROM account_balances_unified WHERE account_id = ?");
            $stmt_check_balance->execute([$id]);
            $total_balance = (float)$stmt_check_balance->fetchColumn();
            if ($total_balance != 0) {
                throw new Exception("لا يمكن تغيير الحالة إلى مغلق لأن الرصيد ليس صفرًا.");
            }
        }
        
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_name_ar = ?, account_status = ? WHERE id = ? AND account_type = 'expense'");
        $stmt->execute([$account_name, $new_status, $id]);
        
        $pdo->commit();
        echo "<script>location.href='manage_expenses.php?success=2';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء تعديل الحساب: " . $e->getMessage();
    }
}

// حذف حساب مصروفات عبر POST + CSRF
if (isset($_POST['delete_expense_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_expenses.php';</script>");
    }
    $id = (int)$_POST['delete_expense_account'];
    try {
        $pdo->beginTransaction();
        
        // تحقق من أن الرصيد صفر
        $stmt_check_balance = $pdo->prepare("SELECT SUM(current_balance) as total FROM account_balances_unified WHERE account_id = ?");
        $stmt_check_balance->execute([$id]);
        $total_balance = (float)$stmt_check_balance->fetchColumn();
        if ($total_balance != 0) {
            throw new Exception("لا يمكن حذف الحساب لأن الرصيد ليس صفرًا.");
        }

        // التحقق من إمكانية حذف الحساب (لا يمكن حذفه إذا كانت لديه أرصدة أو معاملات)
        if (!can_delete_account($id)) {
            throw new Exception("لا يمكن حذف هذا الحساب لأنه قد يحتوي على أرصدة أو معاملات مسجلة. يرجى التأكد من تفريغ الحساب أولاً قبل حذفه.");
        }

        // حذف الأرصدة المرتبطة بالحساب
        $stmt_del_bal = $pdo->prepare("DELETE FROM account_balances_unified WHERE account_id = ?");
        $stmt_del_bal->execute([$id]);

        // حذف الحساب من شجرة الحسابات الموحدة
        $stmt = $pdo->prepare("DELETE FROM unified_accounts WHERE id = ? AND account_type = 'expense'");
        $stmt->execute([$id]);

        $pdo->commit();
        echo "<script>location.href='manage_expenses.php?success=3';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء حذف الحساب: " . $e->getMessage();
    }
}

// جلب الحسابات المصروفة مع إمكانية البحث
$where = "WHERE coa.account_type = 'expense'";
$params = [];
if (!empty($_GET['q'])) {
    $where .= " AND (coa.account_name_ar LIKE ? OR coa.account_code LIKE ?)";
    $q = "%" . $_GET['q'] . "%";
    $params[] = $q; $params[] = $q;
}

$expenses_stmt = $pdo->prepare("
    SELECT coa.*, p.account_name_ar as parent_name
    FROM unified_accounts coa
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id
    $where
    ORDER BY coa.account_code ASC
");
$expenses_stmt->execute($params);
$expenses = $expenses_stmt->fetchAll();

// جلب الأرصدة لكل الحسابات المصروفة دفعة واحدة
$expense_ids = array_column($expenses, 'id');
$balances = [];
if (!empty($expense_ids)) {
    $placeholders = implode(',', array_fill(0, count($expense_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT ab.*, c.currency_name, c.currency_symbol 
        FROM account_balances_unified ab 
        JOIN currencies c ON ab.currency_id = c.id 
        WHERE ab.account_id IN ($placeholders)
    ");
    $bal_stmt->execute($expense_ids);
    while ($row = $bal_stmt->fetch()) {
        $balances[$row['account_id']][] = $row;
    }
}

// جلب الحسابات الأصلية (لإضافة حسابات جديدة تحتها)
$parent_accounts = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_type = 'expense' ORDER BY account_code")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1")->fetchAll();

$page_title = "إدارة الحسابات المصروفة";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-wallet me-2 text-primary"></i> إدارة الحسابات المصروفة</h3>
            <p class="text-muted small mb-0">إضافة وتعديل وإدارة الحسابات المصروفة مع إمكانية إضافة حسابات جديدة تحت الحسابات الأصلية</p>
        </div>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة حساب مصروفات
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4">
            <i class="fas fa-check-circle me-2"></i>
            <?php
            if ($_GET['success'] == 1) echo "تم إضافة حساب مصروفات جديد بنجاح.";
            if ($_GET['success'] == 2) echo "تم تعديل معلومات الحساب بنجاح.";
            if ($_GET['success'] == 3) echo "تم حذف الحساب بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 py-3">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control bg-light border-0" placeholder="ابحث عن حساب مصروفات بالاسم أو الكود..." value="<?php echo h($_GET['q'] ?? ''); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-light border w-100 rounded-3">بحث</button>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-secondary small text-uppercase fw-bold">
                        <tr>
                            <th class="px-4 py-3">كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>الحساب الأصل</th>
                            <th>الرصيد الحالي</th>
                            <th>الحالة</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td class="px-4">
                                <code class="text-primary fw-bold"><?php echo $exp['account_code']; ?></code>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($exp['account_name_ar']); ?></div>
                                <div class="extra-small text-muted">ID: #<?php echo $exp['id']; ?></div>
                            </td>
                            <td class="small">
                                <?php echo htmlspecialchars($exp['parent_name'] ?: '---'); ?>
                            </td>
                            <td>
                                <?php 
                                if (isset($balances[$exp['id']])) {
                                    foreach ($balances[$exp['id']] as $bal) {
                                        echo '<div class="mb-1 small">' . format_account_balance($bal['current_balance'], $exp['normal_balance'], $bal['currency_name']) . '</div>';
                                    }
                                } else {
                                    echo '<span class="text-muted small">0.00</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo get_account_status_label($exp['account_status']); ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <a href="account_statement.php?id=<?php echo $exp['id']; ?>" class="btn btn-sm btn-light border-0" title="كشف حساب">
                                        <i class="fas fa-file-invoice-dollar text-primary"></i>
                                    </a>
                                    <button class="btn btn-sm btn-light border-0 edit-expense" 
                                            data-id="<?php echo $exp['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($exp['account_name_ar']); ?>"
                                            data-status="<?php echo $exp['account_status']; ?>"
                                            title="تعديل"><i class="fas fa-edit text-warning"></i></button>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الحساب المصروفات؟')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_expense_account" value="<?php echo $exp['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border-0" title="حذف"><i class="fas fa-trash text-danger"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="6" class="py-5 text-center text-muted">لا توجد حسابات مصروفة مطابقة لبحثك</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة حساب مصروفات -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة حساب مصروفات جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">اسم الحساب (مثلاً: مصروفات سفر) <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">الحساب الأصل <span class="text-danger">*</span></label>
                        <select name="parent_id" class="form-select rounded-3" required>
                            <option value="">--- اختر الحساب الأصل من الحسابات المصروفة ---</option>
                            <?php foreach ($parent_accounts as $pa): ?>
                                <option value="<?php echo $pa['id']; ?>"><?php echo $pa['account_code'] . ' - ' . $pa['account_name_ar']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">العملة</label>
                            <select name="currency_id" class="form-select rounded-3">
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $base_currency_id) ? 'selected' : ''; ?>>
                                        <?php echo $c['currency_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">الرصيد الافتتاحي</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control rounded-3" value="0.00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">الحالة</label>
                        <select name="status" class="form-select rounded-3">
                            <option value="active">نشط</option>
                            <option value="dormant">خامل</option>
                            <option value="closed">مغلق</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إغلاق</button>
                    <button type="submit" name="add_expense_account" class="btn btn-primary rounded-pill px-5 fw-bold shadow">إضافة الحساب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل حساب مصروفات -->
<div class="modal fade" id="editExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل معلومات الحساب</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">اسم الحساب <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" id="edit_name" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">الحالة</label>
                        <select name="status" id="edit_status" class="form-select rounded-3">
                            <option value="active">نشط</option>
                            <option value="inactive">متوقف</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إغلاق</button>
                    <button type="submit" name="update_expense_account" class="btn btn-warning rounded-pill px-5 fw-bold shadow">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.edit-expense').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var status = $(this).data('status');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_status').val(status);
        
        $('#editExpenseModal').modal('show');
    });
});
</script>

<?php require_once 'footer.php'; ?>
