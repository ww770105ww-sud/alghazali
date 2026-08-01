<?php
require_once 'header.php';

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings['allow_editor_services']) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

$upload_dir = '../assets/uploads/services/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// إضافة خدمة جديدة
if(isset($_POST['add_service'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='services.php';</script>");
    }
    $service_image = '';
    if(!empty($_FILES['service_image']['name'])) {
        $service_image = time() . '_' . basename($_FILES['service_image']['name']);
        move_uploaded_file($_FILES['service_image']['tmp_name'], $upload_dir . $service_image);
    }

    $revenue_account_id = !empty($_POST['revenue_account_id']) ? (int)$_POST['revenue_account_id'] : null;
    $cost_account_id = !empty($_POST['cost_account_id']) ? (int)$_POST['cost_account_id'] : null;
    $profit_account_id = !empty($_POST['profit_account_id']) ? (int)$_POST['profit_account_id'] : null;
    $service_type = !empty($_POST['service_type']) ? $_POST['service_type'] : null;
    
    $stmt = $pdo->prepare("INSERT INTO services (service_name, service_type, service_image, price, currency_id, nights_count, hotel_name, makkah_days, madinah_days, quad_price, triple_price, double_price, print_terms, revenue_account_id, cost_account_id, profit_account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['service_name'], $service_type, $service_image, $_POST['price'], $_POST['currency_id'], $_POST['nights_count'], 
        $_POST['hotel_name'], $_POST['makkah_days'], $_POST['madinah_days'], 
        $_POST['quad_price'], $_POST['triple_price'], $_POST['double_price'],
        $_POST['print_terms'],
        $revenue_account_id, $cost_account_id, $profit_account_id
    ]);
    header("Location: services.php?success=1");
    exit;
}

// تحديث خدمة
if(isset($_POST['update_service'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='services.php';</script>");
    }
    $service_id = $_POST['service_id'];
    $service_image = $_POST['current_image'];

    if(!empty($_FILES['service_image']['name'])) {
        $service_image = time() . '_' . basename($_FILES['service_image']['name']);
        move_uploaded_file($_FILES['service_image']['tmp_name'], $upload_dir . $service_image);
    }

    $revenue_account_id = !empty($_POST['revenue_account_id']) ? (int)$_POST['revenue_account_id'] : null;
    $cost_account_id = !empty($_POST['cost_account_id']) ? (int)$_POST['cost_account_id'] : null;
    $profit_account_id = !empty($_POST['profit_account_id']) ? (int)$_POST['profit_account_id'] : null;
    $service_type = !empty($_POST['service_type']) ? $_POST['service_type'] : null;
    
    $stmt = $pdo->prepare("UPDATE services SET 
        service_name = ?, service_type = ?, service_image = ?, price = ?, currency_id = ?, nights_count = ?, 
        hotel_name = ?, makkah_days = ?, madinah_days = ?, 
        quad_price = ?, triple_price = ?, double_price = ?, print_terms = ?,
        revenue_account_id = ?, cost_account_id = ?, profit_account_id = ? 
        WHERE id = ?");
    $stmt->execute([
        $_POST['service_name'], $service_type, $service_image, $_POST['price'], $_POST['currency_id'], $_POST['nights_count'], 
        $_POST['hotel_name'], $_POST['makkah_days'], $_POST['madinah_days'], 
        $_POST['quad_price'], $_POST['triple_price'], $_POST['double_price'],
        $_POST['print_terms'],
        $revenue_account_id, $cost_account_id, $profit_account_id,
        $service_id
    ]);
    header("Location: services.php?updated=1");
    exit;
}

// حذف خدمة عبر POST + CSRF
if(isset($_POST['delete_service'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='services.php';</script>");
    }
    $id = (int)$_POST['delete_service'];
    $pdo->prepare("DELETE FROM services WHERE id = ?")->execute([$id]);
    header("Location: services.php?deleted=1");
    exit;
}

$services = $pdo->query("
    SELECT 
        s.*, 
        c.currency_name, c.currency_code,
        r.account_code AS revenue_account_code, r.account_name_ar AS revenue_account_name,
        co.account_code AS cost_account_code, co.account_name_ar AS cost_account_name,
        p.account_code AS profit_account_code, p.account_name_ar AS profit_account_name
    FROM services s 
    LEFT JOIN currencies c ON s.currency_id = c.id 
    LEFT JOIN unified_accounts r ON s.revenue_account_id = r.id
    LEFT JOIN unified_accounts co ON s.cost_account_id = co.id
    LEFT JOIN unified_accounts p ON s.profit_account_id = p.id
    ORDER BY s.created_at DESC
")->fetchAll();

// Fetch all passport transaction types to check links
$passportTypes = $pdo->query("
    SELECT pt.*, s.service_name 
    FROM passport_transaction_types pt 
    LEFT JOIN services s ON pt.service_id = s.id
    ORDER BY pt.type_name ASC
")->fetchAll();

$currencies = $pdo->query("SELECT * FROM currencies ORDER BY currency_name ASC")->fetchAll();
$accounts = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_status = 'active' ORDER BY account_code ASC")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>إدارة الخدمات وبرامج العمرة</h3>
        <button class="btn btn-primary shadow-sm rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addServiceModal">
            <i class="fas fa-plus me-2"></i> إضافة خدمة جديدة
        </button>
    </div>

    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            تم إضافة الخدمة بنجاح!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['updated'])): ?>
        <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm rounded-3">
            تم تحديث بيانات الخدمة بنجاح.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">الصورة</th>
                            <th>اسم الخدمة</th>
                            <th>الشروط الخاصة</th>
                            <th>السعر</th>
                            <th>أنواع المعاملات المرتبطة</th>
                            <th>حساب الإيرادات</th>
                            <th>حساب التكلفة</th>
                            <th>حساب الأرباح</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($services)): ?>
                            <tr><td colspan="9" class="text-center py-5 text-muted">لا توجد خدمات مضافة حالياً.</td></tr>
                        <?php endif; ?>
                        <?php foreach($services as $s): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <?php if($s['service_image']): ?>
                                    <img src="../assets/uploads/services/<?php echo htmlspecialchars($s['service_image']); ?>" class="rounded-3 border shadow-sm" width="80" height="60" style="object-fit: cover; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#viewImageModal<?php echo $s['id']; ?>">
                                    <!-- Modal عرض الصورة -->
                                    <div class="modal fade" id="viewImageModal<?php echo $s['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><?php echo htmlspecialchars($s['service_name']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body text-center">
                                                    <img src="../assets/uploads/services/<?php echo htmlspecialchars($s['service_image']); ?>" class="img-fluid rounded-3" style="max-width: 100%; max-height: 70vh;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="bg-light rounded-3 d-flex align-items-center justify-content-center text-muted" style="width: 80px; height: 60px;">
                                        <i class="fas fa-image fa-2x"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold"><?php echo $s['service_name']; ?></td>
                            <td>
                                <div class="text-muted extra-small" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($s['print_terms'] ?? ''); ?>">
                                    <?php echo $s['print_terms'] ?: '<span class="opacity-50">لا توجد شروط خاصة</span>'; ?>
                                </div>
                            </td>
                            <td>
                                <span class="fw-bold text-success">
                                    <?php echo number_format($s['price'], 2); ?> 
                                    <small class="text-muted"><?php echo $s['currency_code']; ?></small>
                                </span>
                            </td>
                            <td>
                                <?php 
                                // Find all passport transaction types linked to this service
                                $linkedTypes = array_filter($passportTypes, function($pt) use ($s) {
                                    return $pt['service_id'] == $s['id'];
                                });
                                if(!empty($linkedTypes)):
                                    foreach($linkedTypes as $pt):
                                ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1 rounded-pill me-1 mb-1 d-inline-block">
                                        <?php echo $pt['type_name']; ?>
                                    </span>
                                <?php 
                                    endforeach;
                                else:
                                ?>
                                    <span class="text-muted small opacity-50">لا توجد</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($s['revenue_account_code'] && $s['revenue_account_name']): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 rounded-pill">
                                        <?php echo $s['revenue_account_code']; ?> - <?php echo $s['revenue_account_name']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small opacity-50">غير محدد</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($s['cost_account_code'] && $s['cost_account_name']): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1 rounded-pill">
                                        <?php echo $s['cost_account_code']; ?> - <?php echo $s['cost_account_name']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small opacity-50">غير محدد</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($s['profit_account_code'] && $s['profit_account_name']): ?>
                                    <span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1 rounded-pill">
                                        <?php echo $s['profit_account_code']; ?> - <?php echo $s['profit_account_name']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small opacity-50">غير محدد</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary rounded-pill px-3 me-1" data-bs-toggle="modal" data-bs-target="#editServiceModal<?php echo $s['id']; ?>">
                                    <i class="fas fa-edit me-1"></i> تعديل
                                </button>
                                <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذه الخدمة؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="delete_service" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                        <i class="fas fa-trash me-1"></i> حذف
                                    </button>
                                </form>
                            </td>
                        </tr>

                        <!-- Modal تعديل الخدمة -->
                        <div class="modal fade" id="editServiceModal<?php echo $s['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content border-0 shadow">
                                    <form method="POST" enctype="multipart/form-data">
                                        <?php echo csrf_input(); ?>
                                        <div class="modal-header bg-primary text-white py-3">
                                            <h5 class="modal-title"><i class="fas fa-edit me-2"></i> تعديل الخدمة: <?php echo $s['service_name']; ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <input type="hidden" name="service_id" value="<?php echo $s['id']; ?>">
                                            <input type="hidden" name="current_image" value="<?php echo $s['service_image']; ?>">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">اسم الخدمة</label>
                                                    <input type="text" name="service_name" class="form-control" value="<?php echo $s['service_name']; ?>" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">نوع الخدمة</label>
                                                    <select name="service_type" id="edit_service_type_<?php echo $s['id']; ?>" class="form-select" onchange="autoFillTerms('edit_<?php echo $s['id']; ?>')">
                                                        <option value="">اختر نوع الخدمة</option>
                                                        <option value="umrah" <?php echo ($s['service_type'] ?? '') === 'umrah' ? 'selected' : ''; ?>>الحج والعمرة</option>
                                                        <option value="bus_flight" <?php echo ($s['service_type'] ?? '') === 'bus_flight' ? 'selected' : ''; ?>>حجوزات تذاكر طيران وبصات</option>
                                                        <option value="work_visa" <?php echo ($s['service_type'] ?? '') === 'work_visa' ? 'selected' : ''; ?>>تأشيرة العمل</option>
                                                        <option value="family_visit" <?php echo ($s['service_type'] ?? '') === 'family_visit' ? 'selected' : ''; ?>>زيارة عائلية</option>
                                                        <option value="postal" <?php echo ($s['service_type'] ?? '') === 'postal' ? 'selected' : ''; ?>>خدمات البريد</option>
                                                        <option value="passport" <?php echo ($s['service_type'] ?? '') === 'passport' ? 'selected' : ''; ?>>جوازات السفر</option>
                                                        <option value="general" <?php echo ($s['service_type'] ?? '') === 'general' ? 'selected' : ''; ?>>عام</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 mb-3">
                                                    <label class="form-label fw-bold">السعر الأساسي</label>
                                                    <input type="number" step="0.01" name="price" class="form-control" value="<?php echo $s['price']; ?>" required>
                                                </div>
                                                <div class="col-md-3 mb-3">
                                                    <label class="form-label fw-bold">العملة</label>
                                                    <select name="currency_id" class="form-select" required>
                                                        <?php foreach($currencies as $c): ?>
                                                            <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $s['currency_id']) ? 'selected' : ''; ?>>
                                                                <?php echo $c['currency_name']; ?> (<?php echo $c['currency_code']; ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-12 mb-3">
                                                    <label class="form-label fw-bold">صورة الخدمة</label>
                                                    <input type="file" name="service_image" class="form-control mb-2">
                                                    <?php if($s['service_image']): ?>
                                                        <img src="../assets/uploads/services/<?php echo $s['service_image']; ?>" class="img-thumbnail" width="120">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">اسم الفندق</label>
                                                    <input type="text" name="hotel_name" class="form-control" value="<?php echo $s['hotel_name']; ?>">
                                                </div>
                                                <div class="col-md-2 mb-3">
                                                    <label class="form-label fw-bold">إجمالي الليالي</label>
                                                    <input type="number" name="nights_count" class="form-control" value="<?php echo $s['nights_count']; ?>">
                                                </div>
                                                <div class="col-md-2 mb-3">
                                                    <label class="form-label fw-bold">أيام مكة</label>
                                                    <input type="number" name="makkah_days" class="form-control" value="<?php echo $s['makkah_days']; ?>">
                                                </div>
                                                <div class="col-md-2 mb-3">
                                                    <label class="form-label fw-bold">أيام المدينة</label>
                                                    <input type="number" name="madinah_days" class="form-control" value="<?php echo $s['madinah_days']; ?>">
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">سعر الغرفة الثنائية</label>
                                                    <input type="number" step="0.01" name="double_price" class="form-control" value="<?php echo $s['double_price']; ?>">
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">سعر الغرفة الثلاثية</label>
                                                    <input type="number" step="0.01" name="triple_price" class="form-control" value="<?php echo $s['triple_price']; ?>">
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">سعر الغرفة الرباعية</label>
                                                    <input type="number" step="0.01" name="quad_price" class="form-control" value="<?php echo $s['quad_price']; ?>">
                                                </div>
                                                <div class="col-12 mb-3">
                                                    <label class="form-label fw-bold">شروط وأحكام خاصة بهذه الخدمة (تظهر في السند)</label>
                                                    <textarea id="edit_print_terms_<?php echo $s['id']; ?>" name="print_terms" class="form-control" rows="4" placeholder="مثال لفيزا العمل: المكتب غير مسؤول عن الرفض الطبي..."><?php echo htmlspecialchars($s['print_terms'] ?? ''); ?></textarea>
                                                    <div class="form-text small text-primary"><i class="fas fa-info-circle me-1"></i> هذه الشروط ستظهر في الجزء الجانبي من سند القبض عند اختيار هذه الخدمة.</div>
                                                </div>
                                                <hr>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">حساب الإيرادات (Revenue)</label>
                                                    <select name="revenue_account_id" class="form-select">
                                                        <option value="">اختر الحساب...</option>
                                                        <?php foreach($accounts as $acc): ?>
                                                            <option value="<?php echo $acc['id']; ?>" <?php echo $acc['id'] == $s['revenue_account_id'] ? 'selected' : ''; ?>>
                                                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">حساب التكلفة (Cost)</label>
                                                    <select name="cost_account_id" class="form-select">
                                                        <option value="">اختر الحساب...</option>
                                                        <?php foreach($accounts as $acc): ?>
                                                            <option value="<?php echo $acc['id']; ?>" <?php echo $acc['id'] == $s['cost_account_id'] ? 'selected' : ''; ?>>
                                                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">حساب الأرباح (Profit)</label>
                                                    <select name="profit_account_id" class="form-select">
                                                        <option value="">اختر الحساب...</option>
                                                        <?php foreach($accounts as $acc): ?>
                                                            <option value="<?php echo $acc['id']; ?>" <?php echo $acc['id'] == $s['profit_account_id'] ? 'selected' : ''; ?>>
                                                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                            <button type="submit" name="update_service" class="btn btn-primary px-4">حفظ التغييرات</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة خدمة -->
<div class="modal fade" id="addServiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> إضافة برنامج عمرة / خدمة جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الخدمة</label>
                            <input type="text" name="service_name" class="form-control" placeholder="أدخل اسم البرنامج أو الخدمة" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">نوع الخدمة</label>
                            <select name="service_type" id="add_service_type" class="form-select" onchange="autoFillTerms('add')">
                                <option value="">اختر نوع الخدمة</option>
                                <option value="umrah">الحج والعمرة</option>
                                <option value="bus_flight">حجوزات تذاكر طيران وبصات</option>
                                <option value="work_visa">تأشيرة العمل</option>
                                <option value="family_visit">زيارة عائلية</option>
                                <option value="postal">خدمات البريد</option>
                                <option value="passport">جوازات السفر</option>
                                <option value="general">عام</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">السعر الأساسي</label>
                            <input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" class="form-select" required>
                                <option value="" disabled selected>اختر العملة</option>
                                <?php foreach($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>">
                                        <?php echo $c['currency_name']; ?> (<?php echo $c['currency_code']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">صورة الخدمة</label>
                            <input type="file" name="service_image" class="form-control">
                            <div class="form-text">يفضل استخدام صور عالية الجودة بنسبة عرض 4:3.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الفندق</label>
                            <input type="text" name="hotel_name" class="form-control" placeholder="اسم الفندق المقترح">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label fw-bold">إجمالي الليالي</label>
                            <input type="number" name="nights_count" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label fw-bold">أيام مكة</label>
                            <input type="number" name="makkah_days" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label fw-bold">أيام المدينة</label>
                            <input type="number" name="madinah_days" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-primary">سعر الغرفة الثنائية</label>
                            <input type="number" step="0.01" name="double_price" class="form-control border-primary border-opacity-25" placeholder="0.00">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-primary">سعر الغرفة الثلاثية</label>
                            <input type="number" step="0.01" name="triple_price" class="form-control border-primary border-opacity-25" placeholder="0.00">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-primary">سعر الغرفة الرباعية</label>
                            <input type="number" step="0.01" name="quad_price" class="form-control border-primary border-opacity-25" placeholder="0.00">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">شروط وأحكام خاصة بهذه الخدمة (تظهر في السند)</label>
                            <textarea id="add_print_terms" name="print_terms" class="form-control" rows="4" placeholder="اكتب الشروط الخاصة بهذه الخدمة ليتم طباعتها في السند..."></textarea>
                            <div class="form-text small text-primary"><i class="fas fa-info-circle me-1"></i> هذه الشروط خاصة بكل خدمة على حدة وتظهر في المساحة الجانبية للسند.</div>
                        </div>
                        <hr>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">حساب الإيرادات (Revenue)</label>
                            <select name="revenue_account_id" class="form-select">
                                <option value="">اختر الحساب...</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">حساب التكلفة (Cost)</label>
                            <select name="cost_account_id" class="form-select">
                                <option value="">اختر الحساب...</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">حساب الأرباح (Profit)</label>
                            <select name="profit_account_id" class="form-select">
                                <option value="">اختر الحساب...</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_service" class="btn btn-primary px-5 shadow-sm rounded-pill">حفظ البرنامج</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const serviceTerms = {
    'umrah': <?php echo json_encode($settings['umrah_service_terms'] ?? ''); ?>,
    'bus_flight': <?php echo json_encode($settings['bus_flight_service_terms'] ?? ''); ?>,
    'work_visa': <?php echo json_encode($settings['work_visa_service_terms'] ?? ''); ?>,
    'family_visit': <?php echo json_encode($settings['family_visit_service_terms'] ?? ''); ?>,
    'passport': <?php echo json_encode($settings['passport_service_terms'] ?? ''); ?>,
    'general': ''
};

function autoFillTerms(prefix) {
    let typeSelect, termsTextarea;
    if (prefix === 'add') {
        typeSelect = document.getElementById('add_service_type');
        termsTextarea = document.getElementById('add_print_terms');
    } else {
        // For edit modals, prefix is 'edit_{service_id}'
        const serviceId = prefix.replace('edit_', '');
        typeSelect = document.getElementById('edit_service_type_' + serviceId);
        termsTextarea = document.getElementById('edit_print_terms_' + serviceId);
    }
    if (typeSelect && termsTextarea) {
        termsTextarea.value = serviceTerms[typeSelect.value] || '';
    }
}
</script>

<?php require_once 'footer.php'; ?>
