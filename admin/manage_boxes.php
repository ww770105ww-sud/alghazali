<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/CurrencyExchange.php';

$currencyExchange = new CurrencyExchange($pdo);
$baseCurrency = $currencyExchange->getBaseCurrency();
$base_currency_id = $baseCurrency['id'] ?? null;

// التحقق من صلاحية إدارة الحسابات المالية
if (!has_permission('manage_financial_accounts')) {
    echo "<script>alert('ليس لديك صلاحية للوصول إلى إدارة الحسابات المالية'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['deactivate']) || isset($_GET['delete_permanent'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// إضافة صندوق جديد
if (isset($_POST['add_box_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_boxes.php';</script>");
    }
    $account_name = $_POST['account_name'];
    $parent_id = $_POST['parent_id'];
    $branch_id = $_POST['branch_id'] ?: null;
    $opening_balance = $_POST['opening_balance'] ?? 0;
    $currency_id = $_POST['currency_id'] ?? 1;
    $status = $_POST['status'] == 'active' ? 'active' : $_POST['status'];

    try {
        $pdo->beginTransaction();

        // التحقق من عدم تكرار اسم الصندوق تحت نفس الحساب الرئيسي
        $stmt_check_name = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE account_name_ar = ? AND parent_id = ?");
        $stmt_check_name->execute([$account_name, $parent_id]);
        if ($stmt_check_name->fetchColumn() > 0) {
            throw new Exception("اسم الصندوق موجود بالفعل تحت هذا الحساب الرئيسي.");
        }

        $stmt_parent = $pdo->prepare("SELECT account_code, account_type, normal_balance FROM unified_accounts WHERE id = ?");
        $stmt_parent->execute([$parent_id]);
        $parent = $stmt_parent->fetch();
        
        if (!$parent) throw new Exception("لم يتم العثور على الحساب الرئيسي.");

        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ?");
        $stmt_last->execute([$parent_id]);
        $last_code = $stmt_last->fetchColumn();
        
        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = $parent['account_code'] . '001';
        }

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, branch_id, account_status) VALUES (?, ?, 'asset', 'debit', ?, ?, ?)");
        $stmt->execute([$new_code, $account_name, $parent_id, $branch_id, $status]);
        
        $new_account_id = $pdo->lastInsertId();
        
        // تفعيل عملة الأساس للصندوق الجديد - فقط العملة الأساسية للنظام
        if ($base_currency_id) {
            $opening_balance_for_base = $_POST['opening_balance'] ?? 0;
            $stmt_base_balance = $pdo->prepare("INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) VALUES (?, ?, ?, ?, 0)");
            $stmt_base_balance->execute([$new_account_id, $base_currency_id, $opening_balance_for_base, $opening_balance_for_base]);
        }

        $pdo->commit();
        echo "<script>location.href='manage_boxes.php?success=1';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء إضافة الصندوق: " . $e->getMessage();
    }
}

// تحديث الصندوق
if (isset($_POST['update_box_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_boxes.php';</script>");
    }
    $id = $_POST['id'];
    $account_name = $_POST['account_name'];
    $branch_id = $_POST['branch_id'] ?: null;
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
        
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_name_ar = ?, account_status = ?, branch_id = ? WHERE id = ?");
        $stmt->execute([$account_name, $new_status, $branch_id, $id]);

        $pdo->commit();
        echo "<script>location.href='manage_boxes.php?success=2';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء تحديث الصندوق: " . $e->getMessage();
    }
}

// تحويل إلى خامل عبر POST + CSRF
if (isset($_POST['deactivate_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_boxes.php';</script>");
    }
    $id = (int)$_POST['deactivate_account'];
    try {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        echo "<script>location.href='manage_boxes.php?success=3';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ أثناء التحويل إلى خامل: " . $e->getMessage();
    }
}

// حذف نهائي عبر POST + CSRF
if (isset($_POST['delete_account_permanent'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_boxes.php';</script>");
    }
    $id = (int)$_POST['delete_account_permanent'];
    try {
        $pdo->beginTransaction();
        
        // تحقق من أن الرصيد صفر
        $stmt_check_balance = $pdo->prepare("SELECT SUM(current_balance) as total FROM account_balances_unified WHERE account_id = ?");
        $stmt_check_balance->execute([$id]);
        $total_balance = (float)$stmt_check_balance->fetchColumn();
        if ($total_balance != 0) {
            throw new Exception("لا يمكن حذف الحساب نهائيًا لأن الرصيد ليس صفرًا.");
        }

        // التحقق من إمكانية حذف الحساب وعدم وجود حركات مالية مرتبطة
        if (!can_delete_account($id)) {
            throw new Exception("لا يمكن حذف الحساب نهائيًا لوجود عمليات مالية مرتبطة به. يمكنك تغيير حالته إلى خامل بدلاً من ذلك.");
        }

        // حذف الأرصدة المرتبطة بالحساب
        $stmt_del_bal = $pdo->prepare("DELETE FROM account_balances_unified WHERE account_id = ?");
        $stmt_del_bal->execute([$id]);

        // حذف الحساب من شجرة الحسابات الموحدة
        $stmt = $pdo->prepare("DELETE FROM unified_accounts WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        echo "<script>location.href='manage_boxes.php?success=4';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الحذف النهائي: " . $e->getMessage();
    }
}

// تحميل حساب الصناديق الرئيسي (11101)
$parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11101'");
$parent_stmt->execute();
$boxes_parent_id = $parent_stmt->fetchColumn(); 

// جلب الصناديق من شجرة الحسابات مع الفروع
$where = "WHERE coa.parent_id = ?";
$params = [$boxes_parent_id];
if (!empty($_GET['q'])) {
    $where .= " AND (coa.account_name_ar LIKE ? OR coa.account_code LIKE ?)";
    $q = "%" . $_GET['q'] . "%";
    $params[] = $q; $params[] = $q;
}

$boxes_stmt = $pdo->prepare("
    SELECT coa.*, p.account_name_ar as parent_name, b.branch_name
    FROM unified_accounts coa
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id
    LEFT JOIN branches b ON coa.branch_id = b.id
    $where
    ORDER BY coa.account_code ASC
");
$boxes_stmt->execute($params);
$boxes = $boxes_stmt->fetchAll();

// تحميل أرصدة الصناديق حسب العملات
$box_ids = array_column($boxes, 'id');
$balances = [];
if (!empty($box_ids)) {
    $placeholders = implode(',', array_fill(0, count($box_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT
            jl.account_id,
            jl.currency_id,
            c.currency_name,
            c.currency_symbol,
            c.currency_code,
            SUM(jl.debit - jl.credit) AS net_balance,
            SUM((jl.debit - jl.credit) * COALESCE(c.exchange_rate, 1)) AS net_balance_base
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        LEFT JOIN currencies c ON jl.currency_id = c.id
        WHERE ft.status = 'posted' AND jl.account_id IN ($placeholders)
        GROUP BY jl.account_id, jl.currency_id, c.currency_name, c.currency_symbol, c.currency_code
        ORDER BY jl.account_id ASC, c.currency_name ASC
    ");
    $bal_stmt->execute($box_ids);
    while ($row = $bal_stmt->fetch()) {
        $balances[$row['account_id']][] = $row;
    }
}

$total_balance = 0;
$currency_name = '';
if (!empty($boxes)) {
    foreach ($boxes as $b) {
        if (isset($balances[$b['id']])) {
            foreach ($balances[$b['id']] as $bal) {
                $total_balance += (float)$bal['net_balance_base'];
                if (!$currency_name) $currency_name = $bal['currency_name'];
            }
        }
    }
}

// تحميل الحسابات الرئيسية المتاحة تحت حساب الصناديق
$parent_accounts = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code = '11101' OR parent_id = ? ORDER BY account_code");
$parent_accounts->execute([$boxes_parent_id]);
$parent_accounts = $parent_accounts->fetchAll();

$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();

$page_title = "إدارة الصناديق";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-cash-register me-2 text-success"></i> إدارة الصناديق</h3>
            <div class="d-flex align-items-center">
                <p class="text-muted small mb-0 me-3">إدارة ومتابعة الصناديق النقدية مع أرصدة الحسابات</p>
                <div class="ms-2 px-3 py-1 bg-white border rounded-pill shadow-sm small">
                    <?php echo get_total_balance_status($total_balance, 'asset', $currency_name); ?>
                </div>
            </div>
        </div>
        <button class="btn btn-success rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addBoxModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة صندوق جديد
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php
            if ($_GET['success'] == 1) echo "تمت إضافة الصندوق بنجاح.";
            if ($_GET['success'] == 2) echo "تم تحديث بيانات الصندوق بنجاح.";
            if ($_GET['success'] == 3) echo "تم تحويل الصندوق إلى خامل بنجاح.";
            if ($_GET['success'] == 4) echo "تم حذف الصندوق نهائيًا بنجاح.";
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
                        <input type="text" id="boxSearch" class="form-control bg-light border-0" placeholder="بحث باسم الصندوق أو كود الصندوق...">
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
                <table class="table table-hover align-middle mb-0 text-center" id="boxesTable">
                    <thead class="bg-light text-secondary small text-uppercase fw-bold">
                        <tr>
                            <th class="px-4 py-3">كود الحساب</th>
                            <th>اسم الصندوق</th>
                            <th>الفرع</th>
                            <th>الرصيد الحالي</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($boxes as $box): ?>
                        <tr>
                            <td class="px-4">
                                <code class="text-primary fw-bold"><?php echo $box['account_code']; ?></code>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($box['account_name_ar']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-normal">
                                    <i class="fas fa-building me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($box['branch_name'] ?? 'عام'); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if (isset($balances[$box['id']])) {
                                    foreach ($balances[$box['id']] as $bal) {
                                        echo '<div class="mb-1 small">' . format_account_balance($bal['net_balance'], $box['normal_balance'], $bal['currency_name']) . '</div>';
                                    }
                                } else {
                                    echo '<span class="text-muted small">0.00</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo get_account_status_label($box['account_status']); ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="account_statement.php?id=<?php echo $box['id']; ?>" class="btn btn-sm btn-light border-0" title="كشف حساب">
                                        <i class="fas fa-file-invoice-dollar text-primary"></i>
                                    </a>
                                    <button class="btn btn-sm btn-light border-0 edit-box" 
                                            data-id="<?php echo $box['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($box['account_name_ar']); ?>"
                                            data-branch="<?php echo $box['branch_id']; ?>"
                                            data-status="<?php echo $box['account_status']; ?>"
                                            title="تعديل"><i class="fas fa-edit text-warning"></i></button>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من تحويل هذا الصندوق إلى خامل؟')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="deactivate_account" value="<?php echo $box['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border-0" title="تحويل إلى خامل"><i class="fas fa-pause text-secondary"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الصندوق نهائيًا؟ هذا الإجراء لا يمكن التراجع عنه!')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_account_permanent" value="<?php echo $box['id']; ?>">
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

<!-- Modal إضافة صندوق -->
<div class="modal fade" id="addBoxModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-success text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة صندوق جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">اسم الصندوق <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" class="form-control rounded-3" placeholder="مثال: صندوق المبيعات" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">الفرع المرتبط به <span class="text-danger">*</span></label>
                        <select name="branch_id" class="form-select rounded-3" required>
                            <option value="">-- اختر الفرع --</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">الحساب الرئيسي (الأصل) <span class="text-danger">*</span></label>
                        <select name="parent_id" class="form-select rounded-3" required>
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
                            <option value="closed">مغلق (للمراجعة)</option>
                            <option value="dormant">خامل (غير مستخدم)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_box_account" class="btn btn-success rounded-pill px-5 fw-bold shadow">حفظ الصندوق</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل صندوق -->
<div class="modal fade" id="editBoxModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات الصندوق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">اسم الصندوق <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" id="edit_name" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">الفرع المرتبط به</label>
                        <select name="branch_id" id="edit_branch" class="form-select rounded-3">
                            <option value="">-- عام (بدون فرع) --</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">الحالة</label>
                        <select name="status" id="edit_status" class="form-select rounded-3">
                            <option value="active">نشط</option>
                            <option value="closed">مغلق (للمراجعة)</option>
                            <option value="dormant">خامل (غير مستخدم)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_box_account" class="btn btn-warning rounded-pill px-5 fw-bold shadow">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.edit-box').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var branch = $(this).data('branch');
        var status = $(this).data('status');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_branch').val(branch);
        $('#edit_status').val(status);
        
        $('#editBoxModal').modal('show');
    });

    // بحث فوري داخل الجدول
    $("#boxSearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#boxesTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});
</script>

<?php require_once 'footer.php'; ?>

