<?php
require_once 'header.php';

// التحقق من الصلاحيات (نفترض وجود صلاحية لإدارة الإعدادات أو الموقع)
if (!has_permission('settings_edit')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات المباشرة عبر الرابط. استخدم أزرار الصفحة المحمية فقط.";
}

// معالجة الإضافة
if (isset($_POST['add_country'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='countries.php';</script>");
    }
    try {
        // التحقق من التكرار
        $check = $pdo->prepare("SELECT id FROM countries WHERE country_name = ? OR country_code = ?");
        $check->execute([$_POST['country_name'], $_POST['country_code']]);
        if ($check->fetch()) {
            $error = "هذه الدولة مسجلة مسبقاً (الاسم أو الرمز مكرر)";
        } else {
            $stmt = $pdo->prepare("INSERT INTO countries (country_name, country_code, dial_code) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['country_name'], $_POST['country_code'], $_POST['dial_code']]);
            echo "<script>location.href='countries.php?success=1';</script>";
        }
    } catch (PDOException $e) {
        $error = "خطأ في الإضافة: " . $e->getMessage();
    }
}

// معالجة التعديل
if (isset($_POST['edit_country'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='countries.php';</script>");
    }
    try {
        $stmt = $pdo->prepare("UPDATE countries SET country_name = ?, country_code = ?, dial_code = ? WHERE id = ?");
        $stmt->execute([$_POST['country_name'], $_POST['country_code'], $_POST['dial_code'], $_POST['id']]);
        echo "<script>location.href='countries.php?success=2';</script>";
    } catch (PDOException $e) {
        $error = "خطأ في التعديل: " . $e->getMessage();
    }
}

// معالجة الحذف
if (isset($_POST['delete_country'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='countries.php';</script>");
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM countries WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_country']]);
        echo "<script>location.href='countries.php?success=3';</script>";
    } catch (PDOException $e) {
        $error = "لا يمكن حذف الدولة لارتباطها بمدن أو معاملات أخرى.";
    }
}

$countries = $pdo->query("SELECT * FROM countries ORDER BY country_name ASC")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-globe-americas text-primary me-2"></i> إدارة الدول</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCountryModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة دولة جديدة
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">تمت العملية بنجاح.</div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">اسم الدولة</th>
                            <th>رمز الدولة (الجواز)</th>
                            <th>فتح الخط الدولي</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($countries as $c): ?>
                        <tr>
                            <td class="px-4 fw-bold"><?php echo htmlspecialchars($c['country_name']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($c['country_code']); ?></span></td>
                            <td><span class="text-info dir-ltr"><?php echo htmlspecialchars($c['dial_code']); ?></span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary edit-btn" 
                                        data-id="<?php echo $c['id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($c['country_name']); ?>" 
                                        data-code="<?php echo htmlspecialchars($c['country_code']); ?>" 
                                        data-dial="<?php echo htmlspecialchars($c['dial_code']); ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد؟ سيتم حذف كافة المدن المرتبطة بها.')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="delete_country" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addCountryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">إضافة دولة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الدولة</label>
                        <input type="text" name="country_name" class="form-control" required placeholder="مثلاً: المملكة العربية السعودية">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رمز الدولة (في الجواز)</label>
                        <input type="text" name="country_code" class="form-control" required placeholder="مثلاً: SAU">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">فتح الخط الدولي</label>
                        <input type="text" name="dial_code" class="form-control" required placeholder="مثلاً: +966">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_country" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editCountryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">تعديل بيانات الدولة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الدولة</label>
                        <input type="text" name="country_name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رمز الدولة (في الجواز)</label>
                        <input type="text" name="country_code" id="edit_code" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">فتح الخط الدولي</label>
                        <input type="text" name="dial_code" id="edit_dial" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="edit_country" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit_id').value = this.dataset.id;
        document.getElementById('edit_name').value = this.dataset.name;
        document.getElementById('edit_code').value = this.dataset.code;
        document.getElementById('edit_dial').value = this.dataset.dial;
        new bootstrap.Modal(document.getElementById('editCountryModal')).show();
    });
});
</script>

<?php require_once 'footer.php'; ?>
