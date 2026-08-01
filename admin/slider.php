<?php
// تفعيل Buffer لمنع أخطاء Header
ob_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if(!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// جلب الإعدادات للتحقق من الصلاحية
$settings_check = getSettings($pdo);
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings_check['allow_editor_slider']) {
    header('Location: index.php');
    exit();
}

$legacyDeleteError = isset($_GET['delete'])
    ? 'تم تعطيل تنفيذ الحذف عبر الرابط المباشر. استخدم زر الحذف الداخلي المحمي فقط.'
    : '';

$upload_dir = '../assets/uploads/slider/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// 1. معالجة طلب AJAX للرفع (يجب أن يكون قبل أي مخرجات HTML)
if(isset($_POST['ajax_upload'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'count' => 0, 'errors' => ['Invalid CSRF token']]);
        exit();
    }
    header('Content-Type: application/json');
    $response = ['success' => false, 'count' => 0, 'errors' => []];
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if(!empty($_FILES['slider_images']['name'][0])) {
        $files = $_FILES['slider_images'];
        $count = count($files['name']);
        
        for($i = 0; $i < $count; $i++) {
            if($files['error'][$i] === 0) {
                $file_tmp = $files['tmp_name'][$i];
                $file_name = $files['name'][$i];
                $file_size = $files['size'][$i];
                
                // Validate file size (max 5MB)
                if ($file_size > 5 * 1024 * 1024) {
                    $response['errors'][] = "الملف {$file_name} كبير جدًا (الحد الأقصى 5MB)";
                    continue;
                }
                
                // Validate MIME type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file_tmp);
                finfo_close($finfo);
                
                if (!in_array($mime_type, $allowed_mime_types)) {
                    $response['errors'][] = "نوع الملف {$file_name} غير مسموح به";
                    continue;
                }
                
                // Validate extension
                $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                if (!in_array($extension, $allowed_extensions)) {
                    $response['errors'][] = "امتداد الملف {$file_name} غير مسموح به";
                    continue;
                }
                
                $image_name = time() . '_' . $i . '.' . $extension;
                if(move_uploaded_file($file_tmp, $upload_dir . $image_name)) {
                    $stmt = $pdo->prepare("INSERT INTO slider_images (image_path, title, subtitle, sort_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $image_name, 
                        $_POST['title'] ?: '', 
                        $_POST['subtitle'] ?: '', 
                        $_POST['sort_order'] ?: 0
                    ]);
                    $response['count']++;
                }
            }
        }
        $response['success'] = true;
    }
    echo json_encode($response);
    exit();
}

// 2. معالجة الحذف (قبل Header أيضاً)
if(isset($_POST['delete_slider_image'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='slider.php';</script>");
    }
    $stmt = $pdo->prepare("SELECT image_path FROM slider_images WHERE id = ?");
    $stmt->execute([(int)$_POST['delete_slider_image']]);
    $img = $stmt->fetch();
    if($img) {
        @unlink($upload_dir . $img['image_path']);
        $pdo->prepare("DELETE FROM slider_images WHERE id = ?")->execute([(int)$_POST['delete_slider_image']]);
    }
    header('Location: slider.php?deleted=1');
    exit();
}

// 3. معالجة تحديث SEO
if(isset($_POST['update_seo_home'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='slider.php';</script>");
    }
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('meta_description', ?, 'seo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$_POST['meta_description']]);
    
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('meta_keywords', ?, 'seo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$_POST['meta_keywords']]);
    header("Location: slider.php?seo_success=1");
    exit();
}

// 4. معالجة تحديث بيانات الصورة
if(isset($_POST['update_image'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='slider.php';</script>");
    }
    $stmt = $pdo->prepare("UPDATE slider_images SET title = ?, subtitle = ?, sort_order = ? WHERE id = ?");
    $stmt->execute([
        $_POST['title'], 
        $_POST['subtitle'], 
        $_POST['sort_order'], 
        $_POST['image_id']
    ]);
    header("Location: slider.php?updated=1");
    exit();
}

// الآن نستدعي الـ Header بعد انتهاء كافة عمليات التوجيه (Redirects)
require_once 'header.php';

$images = $pdo->query("SELECT * FROM slider_images ORDER BY sort_order ASC, id DESC")->fetchAll();
$settings = getSettings($pdo);
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark"><i class="fas fa-images me-2 text-primary"></i> إدارة سلايدر الصور و SEO</h3>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary shadow-sm rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#seoHomeModal">
                <i class="fas fa-search me-1"></i> إعدادات SEO الرئيسية
            </button>
            <button class="btn btn-primary shadow-sm rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addImageModal">
                <i class="fas fa-plus-circle me-1"></i> رفع صور جديدة
            </button>
        </div>
    </div>

    <?php if(isset($_GET['seo_success'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 py-3">تم تحديث إعدادات SEO بنجاح!</div>
    <?php endif; ?>
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 py-3">تم رفع <?php echo (int)$_GET['success']; ?> صور بنجاح!</div>
    <?php endif; ?>
    <?php if(isset($_GET['updated'])): ?>
        <div class="alert alert-info border-0 shadow-sm rounded-3 py-3">تم التحديث بنجاح.</div>
    <?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3 py-3">تم الحذف بنجاح.</div>
    <?php endif; ?>
    <?php if($legacyDeleteError): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-3 py-3"><?php echo $legacyDeleteError; ?></div>
    <?php endif; ?>

    <div class="row">
        <?php foreach($images as $img): ?>
        <div class="col-xl-4 col-lg-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="position-relative">
                    <img src="../assets/uploads/slider/<?php echo rawurlencode($img['image_path']); ?>" class="card-img-top" style="height: 220px; object-fit: cover;">
                    <div class="position-absolute top-0 end-0 p-3">
                        <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="delete_slider_image" value="<?php echo $img['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm rounded-circle shadow">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body p-3 text-center">
                    <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($img['title'] ?: 'بدون عنوان'); ?></h6>
                    <span class="badge bg-light text-dark border">الترتيب: <?php echo $img['sort_order']; ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal إضافة صور -->
<div class="modal fade" id="addImageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="uploadForm" enctype="multipart/form-data" class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">إضافة صور للسلايدر</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="ajax_upload" value="1">
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label fw-bold">اختر الصور</label>
                        <input type="file" name="slider_images[]" class="form-control" multiple required accept="image/*">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">الترتيب</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label">العنوان (اختياري)</label>
                        <input type="text" name="title" class="form-control">
                    </div>
                </div>

                <div id="progressWrapper" class="mt-3 d-none">
                    <div class="progress" style="height: 20px;">
                        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 0%">0%</div>
                    </div>
                    <small id="progressStatus" class="text-primary mt-1 d-block text-center">جاري الرفع...</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
                <button type="submit" id="submitBtn" class="btn btn-primary rounded-pill px-4">بدء الرفع</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal SEO -->
<div class="modal fade" id="seoHomeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">إعدادات SEO</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php echo csrf_input(); ?>
                <div class="mb-3">
                    <label class="form-label">الوصف</label>
                    <textarea name="meta_description" class="form-control" rows="3"><?php echo htmlspecialchars($settings['meta_description']); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">الكلمات المفتاحية</label>
                    <input type="text" name="meta_keywords" class="form-control" value="<?php echo htmlspecialchars($settings['meta_keywords']); ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="update_seo_home" class="btn btn-dark">حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const xhr = new XMLHttpRequest();
    const btn = document.getElementById('submitBtn');
    const wrapper = document.getElementById('progressWrapper');
    const bar = document.getElementById('progressBar');
    const status = document.getElementById('progressStatus');

    btn.disabled = true;
    wrapper.classList.remove('d-none');

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const p = Math.round((e.loaded / e.total) * 100);
            bar.style.width = p + '%';
            bar.innerText = p + '%';
            if(p === 100) status.innerText = 'جاري الحفظ في قاعدة البيانات...';
        }
    };

    xhr.onload = function() {
        try {
            const res = JSON.parse(xhr.responseText);
            if(res.success) {
                window.location.href = 'slider.php?success=' + res.count;
            } else {
                alert('فشل الرفع');
                btn.disabled = false;
            }
        } catch(e) {
            alert('حدث خطأ في السيرفر');
            btn.disabled = false;
        }
    };

    xhr.open('POST', 'slider.php', true);
    xhr.send(formData);
});
</script>

<?php require_once 'footer.php'; ?>
<?php ob_end_flush(); ?>
