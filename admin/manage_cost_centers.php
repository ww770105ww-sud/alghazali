<?php
$page_title = "إدارة مراكز التكلفة";
require_once 'header.php';

// التحقق من الصلاحيات
if (!has_permission('settings_view') && !$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، ليس لديك صلاحية للوصول إلى هذه الصفحة.</div></div>";
    require_once 'footer.php';
    exit;
}

$success_msg = "";
$error_msg = "";

if (isset($_GET['delete'])) {
    $error_msg = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// معالجة الحفظ والتعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_center'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
    $id = (int)($_POST['id'] ?? 0);
    $center_code = $_POST['center_code'];
    $center_name_ar = $_POST['center_name_ar'];
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE cost_centers SET center_code = ?, center_name_ar = ?, parent_id = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$center_code, $center_name_ar, $parent_id, $is_active, $id]);
            $success_msg = "تم تحديث مركز التكلفة بنجاح.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO cost_centers (center_code, center_name_ar, parent_id, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([$center_code, $center_name_ar, $parent_id, $is_active]);
            $success_msg = "تم إضافة مركز التكلفة بنجاح.";
        }
    } catch (PDOException $e) {
        $error_msg = "خطأ: " . $e->getMessage();
    }
    }
}

// معالجة الحذف عبر POST + CSRF
if (isset($_POST['delete_center'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "رمز التحقق الأمني غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.";
    } else {
    $del_id = (int)$_POST['delete_center'];
    try {
        // التحقق من عدم وجود أبناء
        $check = $pdo->prepare("SELECT COUNT(*) FROM cost_centers WHERE parent_id = ?");
        $check->execute([$del_id]);
        if ($check->fetchColumn() > 0) {
            $error_msg = "لا يمكن حذف مركز تكلفة يحتوي على مراكز فرعية.";
        } else {
            $pdo->prepare("DELETE FROM cost_centers WHERE id = ?")->execute([$del_id]);
            $success_msg = "تم حذف مركز التكلفة بنجاح.";
        }
    } catch (PDOException $e) {
        $error_msg = "خطأ في الحذف: " . $e->getMessage();
    }
    }
}

// جلب المراكز
$centers = $pdo->query("SELECT * FROM cost_centers ORDER BY center_code")->fetchAll(PDO::FETCH_ASSOC);

function buildTree($elements, $parentId = null) {
    $branch = array();
    foreach ($elements as $element) {
        if ($element['parent_id'] == $parentId) {
            $children = buildTree($elements, $element['id']);
            if ($children) {
                $element['children'] = $children;
            }
            $branch[] = $element;
        }
    }
    return $branch;
}

$tree = buildTree($centers);
?>

<div class="container-fluid py-4 apple-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-project-diagram me-2"></i> إدارة مراكز التكلفة</h3>
            <p class="text-muted mb-0">تنظيم وتصنيف التكاليف والإيرادات بناءً على الأقسام أو المشاريع.</p>
        </div>
        <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#centerModal" onclick="resetForm()">
            <i class="fas fa-plus me-2"></i> إضافة مركز جديد
        </button>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success rounded-4 shadow-sm border-0 mb-4 p-3"><i class="fas fa-check-circle me-2"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger rounded-4 shadow-sm border-0 mb-4 p-3"><i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">الكود</th>
                                    <th>اسم مركز التكلفة</th>
                                    <th>المركز الأب</th>
                                    <th class="text-center">الحالة</th>
                                    <th class="text-center pe-4">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($centers)): ?>
                                    <tr><td colspan="5" class="text-center py-5 text-muted">لا توجد مراكز تكلفة حالياً.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($centers as $c): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-primary"><?php echo htmlspecialchars($c['center_code']); ?></td>
                                            <td><?php echo htmlspecialchars($c['center_name_ar']); ?></td>
                                            <td>
                                                <?php 
                                                if ($c['parent_id']) {
                                                    $parent = array_filter($centers, function($item) use ($c) { return $item['id'] == $c['parent_id']; });
                                                    echo htmlspecialchars(reset($parent)['center_name_ar'] ?? '-');
                                                } else {
                                                    echo '<span class="text-muted italic">مركز رئيسي</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge rounded-pill <?php echo $c['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo $c['is_active'] ? 'نشط' : 'متوقف'; ?>
                                                </span>
                                            </td>
                                            <td class="text-center pe-4">
                                                <button class="btn btn-sm btn-light rounded-circle shadow-sm me-1" onclick='editCenter(<?php echo json_encode($c); ?>)' title="تعديل">
                                                    <i class="fas fa-edit text-info"></i>
                                                </button>
                                                <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="delete_center" value="<?php echo $c['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-light rounded-circle shadow-sm" title="حذف">
                                                        <i class="fas fa-trash text-danger"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="centerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="modalTitle">إضافة مركز تكلفة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-body p-4">
                    <input type="hidden" name="id" id="center_id">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">كود المركز</label>
                        <input type="text" name="center_code" id="center_code" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">اسم المركز (بالعربي)</label>
                        <input type="text" name="center_name_ar" id="center_name_ar" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">المركز الأب</label>
                        <select name="parent_id" id="parent_id" class="form-select rounded-3">
                            <option value="">-- مركز رئيسي --</option>
                            <?php foreach ($centers as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['center_name_ar']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                        <label class="form-check-label small fw-bold">نشط</label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">حفظ البيانات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    $('#modalTitle').text('إضافة مركز تكلفة');
    $('#center_id').val('');
    $('#center_code').val('');
    $('#center_name_ar').val('');
    $('#parent_id').val('');
    $('#is_active').prop('checked', true);
}

function editCenter(data) {
    $('#modalTitle').text('تعديل مركز تكلفة');
    $('#center_id').val(data.id);
    $('#center_code').val(data.center_code);
    $('#center_name_ar').val(data.center_name_ar);
    $('#parent_id').val(data.parent_id);
    $('#is_active').prop('checked', data.is_active == 1);
    $('#centerModal').modal('show');
}
</script>

<?php require_once 'footer.php'; ?>
