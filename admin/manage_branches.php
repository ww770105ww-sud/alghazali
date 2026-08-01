<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!has_permission('manage_settings')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// جلب معلومات الاعمدة أولاً
$stmt = $pdo->query("DESCRIBE branches");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

// إضافة فرع جديد
if (isset($_POST['add_branch'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_branches.php';</script>");
    }
    
    try {
        $branch_name = $_POST['branch_name'];
        
        // بناء الاستعلام بناءً على الأعمدة الموجودة
        $cols = ['branch_name'];
        $vals = [$branch_name];
        
        if (in_array('branch_code', $columns)) {
            $cols[] = 'branch_code';
            $vals[] = $_POST['branch_code'] ?? '';
        }
        if (in_array('address', $columns)) {
            $cols[] = 'address';
            $vals[] = $_POST['address'] ?? '';
        }
        if (in_array('phone', $columns)) {
            $cols[] = 'phone';
            $vals[] = $_POST['phone'] ?? '';
        }
        if (in_array('status', $columns)) {
            $cols[] = 'status';
            $vals[] = isset($_POST['is_active']) ? 'active' : 'inactive';
        }
        if (in_array('is_active', $columns)) {
            $cols[] = 'is_active';
            $vals[] = isset($_POST['is_active']) ? 1 : 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($vals), '?'));
        $stmt = $pdo->prepare("INSERT INTO branches (" . implode(',', $cols) . ") VALUES ($placeholders)");
        $stmt->execute($vals);
        
        echo "<script>location.href='manage_branches.php?success=1';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ: " . $e->getMessage();
    }
}

// تحديث فرع
if (isset($_POST['update_branch'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='manage_branches.php';</script>");
    }
    
    try {
        $id = $_POST['id'];
        $branch_name = $_POST['branch_name'];
        
        // بناء الاستعلام بناءً على الأعمدة الموجودة
        $updates = ['branch_name = ?'];
        $vals = [$branch_name];
        
        if (in_array('branch_code', $columns)) {
            $updates[] = 'branch_code = ?';
            $vals[] = $_POST['branch_code'] ?? '';
        }
        if (in_array('address', $columns)) {
            $updates[] = 'address = ?';
            $vals[] = $_POST['address'] ?? '';
        }
        if (in_array('phone', $columns)) {
            $updates[] = 'phone = ?';
            $vals[] = $_POST['phone'] ?? '';
        }
        if (in_array('status', $columns)) {
            $updates[] = 'status = ?';
            $vals[] = isset($_POST['is_active']) ? 'active' : 'inactive';
        }
        if (in_array('is_active', $columns)) {
            $updates[] = 'is_active = ?';
            $vals[] = isset($_POST['is_active']) ? 1 : 0;
        }
        
        $vals[] = $id;
        
        $stmt = $pdo->prepare("UPDATE branches SET " . implode(',', $updates) . " WHERE id = ?");
        $stmt->execute($vals);
        
        echo "<script>location.href='manage_branches.php?success=2';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ: " . $e->getMessage();
    }
}

// حذف فرع
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        echo "<script>location.href='manage_branches.php?success=3';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ: " . $e->getMessage();
    }
}

// جلب جميع الفروع مع معالجة الاعمدة المفقودة
$select_cols = ['id', 'branch_name'];
if (in_array('branch_code', $columns)) $select_cols[] = 'branch_code';
if (in_array('address', $columns)) $select_cols[] = 'address';
if (in_array('phone', $columns)) $select_cols[] = 'phone';
if (in_array('status', $columns)) $select_cols[] = 'status';
if (in_array('is_active', $columns)) $select_cols[] = 'is_active';
$branches = $pdo->query("SELECT " . implode(',', $select_cols) . " FROM branches ORDER BY id ASC")->fetchAll();
$page_title = "إدارة جدول الفروع";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-building me-2 text-primary"></i> إدارة جدول الفروع</h3>
            <p class="text-muted small mb-0">إضافة وتعديل وحذف الفروع من جدول branches الأساسي</p>
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
            if ($_GET['success'] == 3) echo "تم حذف الفرع بنجاح.";
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
                        <input type="text" id="branchSearch" class="form-control bg-light border-0" placeholder="بحث سريع...">
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center" id="branchesTable">
                    <thead class="bg-light text-secondary small text-uppercase fw-bold">
                        <tr>
                            <th class="px-4 py-3">المعرف</th>
                            <th>اسم الفرع</th>
                            <?php if (in_array('branch_code', $columns)): ?><th>كود الفرع</th><?php endif; ?>
                            <?php if (in_array('address', $columns)): ?><th>العنوان</th><?php endif; ?>
                            <?php if (in_array('phone', $columns)): ?><th>الهاتف</th><?php endif; ?>
                            <?php if (in_array('status', $columns)): ?><th>الحالة</th><?php endif; ?>
                            <?php if (in_array('is_active', $columns)): ?><th>الحالة (is_active)</th><?php endif; ?>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                        <tr>
                            <td class="px-4">
                                <code class="text-primary fw-bold"><?php echo $branch['id']; ?></code>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($branch['branch_name']); ?></div>
                            </td>
                            <?php if (in_array('branch_code', $columns)): ?>
                            <td>
                                <span class="text-muted small"><?php echo htmlspecialchars($branch['branch_code'] ?? '-'); ?></span>
                            </td>
                            <?php endif; ?>
                            <?php if (in_array('address', $columns)): ?>
                            <td>
                                <span class="text-muted small"><?php echo htmlspecialchars($branch['address'] ?? '-'); ?></span>
                            </td>
                            <?php endif; ?>
                            <?php if (in_array('phone', $columns)): ?>
                            <td>
                                <span class="text-muted small"><?php echo htmlspecialchars($branch['phone'] ?? '-'); ?></span>
                            </td>
                            <?php endif; ?>
                            <?php if (in_array('status', $columns)): ?>
                            <td>
                                <span class="badge rounded-pill <?php echo ($branch['status'] ?? 'active') === 'active' ? 'bg-success text-white' : 'bg-secondary' ?>">
                                    <?php echo ($branch['status'] ?? 'active') === 'active' ? 'نشط' : 'غير نشط'; ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <?php if (in_array('is_active', $columns)): ?>
                            <td>
                                <span class="badge rounded-pill <?php echo ($branch['is_active'] ?? 1) ? 'bg-success text-white' : 'bg-secondary' ?>">
                                    <?php echo ($branch['is_active'] ?? 1) ? 'نشط' : 'غير نشط'; ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-light border-0 edit-branch"
                                        data-id="<?php echo $branch['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($branch['branch_name']); ?>"
                                        data-code="<?php echo htmlspecialchars($branch['branch_code'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($branch['address'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($branch['phone'] ?? ''); ?>"
                                        data-status="<?php echo $branch['status'] ?? 'active'; ?>"
                                        data-active="<?php echo $branch['is_active'] ?? 1; ?>"
                                        title="تعديل"><i class="fas fa-edit text-warning"></i></button>
                                    <a href="manage_branches.php?delete=<?php echo $branch['id']; ?>"
                                        class="btn btn-sm btn-light border-0"
                                        onclick="return confirm('هل أنت متأكد من حذف هذا الفرع؟')"
                                        title="حذف"><i class="fas fa-trash text-danger"></i></a>
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
                            <input type="text" name="branch_name" class="form-control rounded-3" required>
                        </div>
                        <?php if (in_array('branch_code', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">كود الفرع</label>
                            <input type="text" name="branch_code" class="form-control rounded-3">
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('address', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">العنوان</label>
                            <input type="text" name="address" class="form-control rounded-3">
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('phone', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الهاتف</label>
                            <input type="text" name="phone" class="form-control rounded-3">
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('status', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    نشط
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('is_active', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active_old" checked>
                                <label class="form-check-label" for="is_active_old">
                                    نشط (is_active)
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_branch" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ الفرع</button>
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
                            <input type="text" name="branch_name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <?php if (in_array('branch_code', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">كود الفرع</label>
                            <input type="text" name="branch_code" id="edit_code" class="form-control rounded-3">
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('address', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">العنوان</label>
                            <input type="text" name="address" id="edit_address" class="form-control rounded-3">
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('phone', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الهاتف</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control rounded-3">
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('status', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_active">
                                <label class="form-check-label" for="edit_active">
                                    نشط
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('is_active', $columns)): ?>
                        <div class="col-md-12 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_active_old">
                                <label class="form-check-label" for="edit_active_old">
                                    نشط (is_active)
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_branch" class="btn btn-warning rounded-pill px-5 fw-bold shadow">حفظ التغييرات</button>
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
        var code = $(this).data('code');
        var address = $(this).data('address');
        var phone = $(this).data('phone');
        var status = $(this).data('status');
        var active = $(this).data('active');

        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_code').val(code);
        $('#edit_address').val(address);
        $('#edit_phone').val(phone);
        if ($('#edit_active').length) {
            $('#edit_active').prop('checked', status === 'active');
        }
        if ($('#edit_active_old').length) {
            $('#edit_active_old').prop('checked', active == 1);
        }

        $('#editBranchModal').modal('show');
    });

    $("#branchSearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#branchesTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});
</script>

<?php require_once 'footer.php'; ?>
