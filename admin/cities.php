<?php
require_once 'header.php';

if (!has_permission('settings_edit')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات المباشرة عبر الرابط. استخدم أزرار الصفحة المحمية فقط.";
}

// معالجة الإضافة
if (isset($_POST['add_city'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='cities.php';</script>");
    }
    try {
        // التحقق من التكرار في نفس الدولة
        $check = $pdo->prepare("SELECT id FROM cities WHERE city_name = ? AND country_id = ?");
        $check->execute([$_POST['city_name'], $_POST['country_id']]);
        if ($check->fetch()) {
            $error = "هذه المدينة مسجلة مسبقاً في هذه الدولة";
        } else {
            $stmt = $pdo->prepare("INSERT INTO cities (city_name, country_id) VALUES (?, ?)");
            $stmt->execute([$_POST['city_name'], $_POST['country_id']]);
            echo "<script>location.href='cities.php?success=1';</script>";
        }
    } catch (PDOException $e) {
        $error = "خطأ في الإضافة: " . $e->getMessage();
    }
}

// معالجة التعديل
if (isset($_POST['edit_city'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='cities.php';</script>");
    }
    try {
        $stmt = $pdo->prepare("UPDATE cities SET city_name = ?, country_id = ? WHERE id = ?");
        $stmt->execute([$_POST['city_name'], $_POST['country_id'], $_POST['id']]);
        echo "<script>location.href='cities.php?success=2';</script>";
    } catch (PDOException $e) {
        $error = "خطأ في التعديل: " . $e->getMessage();
    }
}

// معالجة الحذف
if (isset($_POST['delete_city'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='cities.php';</script>");
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM cities WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_city']]);
        echo "<script>location.href='cities.php?success=3';</script>";
    } catch (PDOException $e) {
        $error = "خطأ في الحذف: " . $e->getMessage();
    }
}

$cities = $pdo->query("SELECT cities.*, countries.country_name FROM cities JOIN countries ON cities.country_id = countries.id ORDER BY country_name ASC, city_name ASC")->fetchAll();
$countries = $pdo->query("SELECT * FROM countries ORDER BY country_name ASC")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-city text-primary me-2"></i> إدارة المدن</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCityModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة مدينة جديدة
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
                            <th class="px-4 py-3">اسم المدينة</th>
                            <th>الدولة المرتبطة</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cities as $c): ?>
                        <tr>
                            <td class="px-4 fw-bold"><?php echo htmlspecialchars($c['city_name']); ?></td>
                            <td><span class="badge bg-info"><?php echo htmlspecialchars($c['country_name']); ?></span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary edit-btn" 
                                        data-id="<?php echo $c['id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($c['city_name']); ?>" 
                                        data-country="<?php echo $c['country_id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="delete_city" value="<?php echo $c['id']; ?>">
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
<div class="modal fade" id="addCityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">إضافة مدينة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المدينة</label>
                        <input type="text" name="city_name" class="form-control" required placeholder="مثلاً: الرياض">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الدولة</label>
                        <select name="country_id" class="form-select" required>
                            <option value="">-- اختر الدولة --</option>
                            <?php foreach ($countries as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['country_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_city" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editCityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">تعديل بيانات المدينة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المدينة</label>
                        <input type="text" name="city_name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الدولة</label>
                        <select name="country_id" id="edit_country" class="form-select" required>
                            <?php foreach ($countries as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['country_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="edit_city" class="btn btn-primary">حفظ التعديلات</button>
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
        document.getElementById('edit_country').value = this.dataset.country;
        new bootstrap.Modal(document.getElementById('editCityModal')).show();
    });
});
</script>

<?php require_once 'footer.php'; ?>
