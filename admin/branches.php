<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/CurrencyExchange.php';

$currencyExchange = new CurrencyExchange($pdo);
$baseCurrency = $currencyExchange->getBaseCurrency();
$base_currency_id = $baseCurrency['id'] ?? null;

// التحقق من الصلاحية
if (!has_permission('manage_financial_accounts')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['deactivate']) || isset($_GET['delete_permanent'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// إضافة فرع جديد
if (isset($_POST['add_branch_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='branches.php';</script>");
    }
    $account_name = $_POST['account_name'];
    $branch_id = $_POST['branch_id'] ?: null;
    $opening_balance = $_POST['opening_balance'] ?? 0;
    $currency_id = $_POST['currency_id'] ?? 1;
    $status = $_POST['status'] == 'active' ? 'active' : $_POST['status'];

    try {
        $pdo->beginTransaction();

        // 1. العثور على الحساب الرئيسي للفروع (11202)
        $parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11202'");
        $parent_stmt->execute();
        $parent_id = $parent_stmt->fetchColumn();
        
        if (!$parent_id) throw new Exception("الحساب الرئيسي للفروع (11202) غير موجود.");

        // التحقق من عدم تكرار الاسم تحت نفس الأب
        $stmt_check_name = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE account_name_ar = ? AND parent_id = ?");
        $stmt_check_name->execute([$account_name, $parent_id]);
        if ($stmt_check_name->fetchColumn() > 0) {
            throw new Exception("اسم الفرع موجود بالفعل.");
        }

        // 2. توليد الكود الجديد (11202001, 11202002, ...)
        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ? AND account_code LIKE '11202%'");
        $stmt_last->execute([$parent_id]);
        $last_code = $stmt_last->fetchColumn();
        
        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = "11202001";
        }

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, branch_id, account_status) VALUES (?, ?, 'asset', 'debit', ?, ?, ?)");
        $stmt->execute([$new_code, $account_name, $parent_id, $branch_id, $status]);
        
        $new_account_id = $pdo->lastInsertId();
        
        // تفعيل الرصيد الافتتاحي في الجدول الموحد - فقط العملة الأساسية للنظام
        if ($base_currency_id) {
            $opening_balance_for_base = $_POST['opening_balance'] ?? 0;
            $stmt_base_balance = $pdo->prepare("INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) VALUES (?, ?, ?, ?, 0)");
            $stmt_base_balance->execute([$new_account_id, $base_currency_id, $opening_balance_for_base, $opening_balance_for_base]);
        }

        $pdo->commit();
        echo "<script>location.href='branches.php?success=1';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
    }
}

// تحديث فرع
if (isset($_POST['update_branch_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='branches.php';</script>");
    }
    $id = $_POST['id'];
    $account_name = $_POST['account_name'];
    $status = $_POST['status'];

    try {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_name_ar = ?, account_status = ? WHERE id = ?");
        $stmt->execute([$account_name, $status, $id]);
        echo "<script>location.href='branches.php?success=2';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
    }
}

// تحويل إلى خامل عبر POST + CSRF
if (isset($_POST['deactivate_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='branches.php';</script>");
    }
    $id = (int)$_POST['deactivate_account'];
    try {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        echo "<script>location.href='branches.php?success=3';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ أثناء التحويل إلى خامل: " . $e->getMessage();
    }
}

// حذف نهائي عبر POST + CSRF
if (isset($_POST['delete_account_permanent'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='branches.php';</script>");
    }
    $id = (int)$_POST['delete_account_permanent'];
    try {
        $pdo->beginTransaction();

        // التحقق من وجود حركات مالية باستخدام الدالة الموحدة
        if (!can_delete_account($id)) {
            throw new Exception("لا يمكن حذف الحساب نهائيًا لوجود عمليات مالية مرتبطة به. يمكنك تغيير حالته إلى (خامل) بدلاً من ذلك.");
        }

        // حذف الأرصدة المرتبطة بالحساب
        $stmt_del_bal = $pdo->prepare("DELETE FROM account_balances_unified WHERE account_id = ?");
        $stmt_del_bal->execute([$id]);

        // حذف الحساب من شجرة الحسابات الموحدة
        $stmt = $pdo->prepare("DELETE FROM unified_accounts WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        echo "<script>location.href='branches.php?success=4';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الحذف النهائي: " . $e->getMessage();
    }
}

// جلب معرف الأب للفروع (11202)
$parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11202'");
$parent_stmt->execute();
$branches_parent_id = $parent_stmt->fetchColumn();

// جلب الفروع من النظام الموحد
$where = "WHERE coa.parent_id = ?";
$params = [$branches_parent_id];
if (!empty($_GET['q'])) {
    $where .= " AND (coa.account_name_ar LIKE ? OR coa.account_code LIKE ?)";
    $q = "%" . $_GET['q'] . "%";
    $params[] = $q; $params[] = $q;
}

$branches_stmt = $pdo->prepare("
    SELECT coa.*, p.account_name_ar as parent_name, b.branch_name as parent_branch_name
    FROM unified_accounts coa
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id
    LEFT JOIN branches b ON coa.branch_id = b.id
    $where
    ORDER BY coa.account_code ASC
");
$branches_stmt->execute($params);
$branches = $branches_stmt->fetchAll();

// جلب الأرصدة لكل الفروع دفعة واحدة
$branch_ids = array_column($branches, 'id');
$balances = [];
if (!empty($branch_ids)) {
    $placeholders = implode(',', array_fill(0, count($branch_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT ab.*, c.currency_name, c.currency_symbol 
        FROM account_balances_unified ab 
        JOIN currencies c ON ab.currency_id = c.id 
        WHERE ab.account_id IN ($placeholders)
    ");
    $bal_stmt->execute($branch_ids);
    while ($row = $bal_stmt->fetch()) {
        $balances[$row['account_id']][] = $row;
    }
}

$total_balance = 0;
$currency_name = '';
if (!empty($branches)) {
    foreach ($branches as $br) {
        if (isset($balances[$br['id']])) {
            foreach ($balances[$br['id']] as $bal) {
                $total_balance += (float)$bal['current_balance'];
                if (!$currency_name) $currency_name = $bal['currency_name'];
            }
        }
    }
}

$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1")->fetchAll();
$parent_branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();

$page_title = "إدارة الفروع";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-code-branch me-2 text-primary"></i> إدارة الفروع</h3>
            <div class="d-flex align-items-center">
                <p class="text-muted small mb-0 me-3">إدارة وتعديل حسابات الفروع في شجرة الحسابات</p>
                <div class="ms-2 px-3 py-1 bg-white border rounded-pill shadow-sm small">
                    <?php echo get_total_balance_status($total_balance, 'asset', $currency_name); ?>
                </div>
            </div>
        </div>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addBranchModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة فرع جديد
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php
            if ($_GET['success'] == 1) echo "تمت إضافة الفرع بنجاح.";
            if ($_GET['success'] == 2) echo "تم تحديث بيانات الفرع بنجاح.";
            if ($_GET['success'] == 3) echo "تم تحويل الفرع إلى خامل بنجاح.";
            if ($_GET['success'] == 4) echo "تم حذف الفرع نهائيًا بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 py-3">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="branchSearch" class="form-control bg-light border-0" placeholder="بحث سريع باسم أو كود الفرع...">
                    </div>
                </div>
                <div class="col-md-8 text-end">
                    <form method="GET" class="d-inline-flex">
                        <input type="text" name="q" class="form-control form-control-sm me-2" placeholder="بحث متقدم..." value="<?php echo h($_GET['q'] ?? ''); ?>">
                        <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3">بحث</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center" id="branchesTable">
                    <thead class="bg-light text-secondary small text-uppercase fw-bold">
                        <tr>
                            <th class="px-4 py-3">كود الحساب</th>
                            <th>اسم الفرع</th>
                            <th>الفرع الأب</th>
                            <th>الرصيد الحالي</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                        <tr>
                            <td class="px-4">
                                <code class="text-primary fw-bold"><?php echo $branch['account_code']; ?></code>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($branch['account_name_ar']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-normal">
                                    <i class="fas fa-building me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($branch['parent_branch_name'] ?? 'عام'); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if (isset($balances[$branch['id']])) {
                                    foreach ($balances[$branch['id']] as $bal) {
                                        echo '<div class="mb-1 small">' . format_account_balance($bal['current_balance'], $branch['normal_balance'], $bal['currency_name']) . '</div>';
                                    }
                                } else {
                                    echo '<span class="text-muted small">0.00</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo get_account_status_label($branch['account_status']); ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="account_statement.php?id=<?php echo $branch['id']; ?>" class="btn btn-sm btn-light border-0" title="كشف حساب">
                                        <i class="fas fa-file-invoice-dollar text-primary"></i>
                                    </a>
                                    <button class="btn btn-sm btn-light border-0 edit-branch" 
                                            data-id="<?php echo $branch['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($branch['account_name_ar']); ?>"
                                            data-branch="<?php echo $branch['branch_id']; ?>"
                                            data-status="<?php echo $branch['account_status']; ?>"
                                            title="تعديل"><i class="fas fa-edit text-warning"></i></button>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من تحويل هذا الفرع إلى خامل؟')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="deactivate_account" value="<?php echo $branch['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border-0" title="تحويل إلى خامل"><i class="fas fa-pause text-secondary"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الفرع نهائيًا؟ هذا الإجراء لا يمكن التراجع عنه!')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_account_permanent" value="<?php echo $branch['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border-0" title="حذف نهائي"><i class="fas fa-trash text-danger"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة فرع -->
<div class="modal fade" id="addBranchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة فرع جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">اسم الفرع <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" class="form-control rounded-3" placeholder="مثلاً: فرع صنعاء" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الفرع الأب</label>
                            <select name="branch_id" class="form-select rounded-3">
                                <option value="">-- عام (بدون فرع) --</option>
                                <?php foreach ($parent_branches as $pb): ?>
                                    <option value="<?php echo $pb['id']; ?>"><?php echo $pb['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الحالة</label>
                            <select name="status" class="form-select rounded-3">
                                <option value="active">نشط</option>
                                <option value="closed">مغلق (للتصفية)</option>
                                <option value="dormant">راكد (غير مستخدم)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_branch_account" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ الفرع</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل فرع -->
<div class="modal fade" id="editBranchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات الفرع</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">اسم الفرع <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الفرع الأب</label>
                            <select name="branch_id" id="edit_branch" class="form-select rounded-3">
                                <option value="">-- عام (بدون فرع) --</option>
                                <?php foreach ($parent_branches as $pb): ?>
                                    <option value="<?php echo $pb['id']; ?>"><?php echo $pb['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الحالة</label>
                            <select name="status" id="edit_status" class="form-select rounded-3">
                                <option value="active">نشط</option>
                                <option value="closed">مغلق (للتصفية)</option>
                                <option value="dormant">راكد (غير مستخدم)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_branch_account" class="btn btn-warning rounded-pill px-5 fw-bold shadow">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.edit-branch').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var branch = $(this).data('branch');
        var status = $(this).data('status');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_branch').val(branch);
        $('#edit_status').val(status);
        
        $('#editBranchModal').modal('show');
    });

    // بحث ديناميكي في الجدول
    $("#branchSearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#branchesTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});
</script>

<?php require_once 'footer.php'; ?>
