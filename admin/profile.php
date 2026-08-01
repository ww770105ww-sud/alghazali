<?php
// معالجة AJAX قبل تحميل header
if(isset($_POST['ajax_update_profile'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once '../includes/db.php';
    require_once '../includes/functions.php';

    if(!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'رمز الأمان غير صالح'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    while (ob_get_level()) {
        ob_end_clean();
    }

    $user_id = $_SESSION['admin_id'];
    $upload_dir = '../assets/uploads/profiles/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $full_name = $_POST['full_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $profile_image = $_POST['current_image'] ?? '';

    try {
        // دالة لتصغير حجم الصورة
        function compressImage($source, $destination, $quality, $maxWidth = 300, $maxHeight = 300) {
            $info = @getimagesize($source);
            if (!$info) return false;

            $image = null;
            if ($info['mime'] == 'image/jpeg') {
                $image = @imagecreatefromjpeg($source);
            } elseif ($info['mime'] == 'image/gif') {
                $image = @imagecreatefromgif($source);
            } elseif ($info['mime'] == 'image/png') {
                $image = @imagecreatefrompng($source);
            } else {
                return false;
            }

            if (!$image) return false;

            list($width, $height) = $info;
            $ratio = $width / $height;
            if ($maxWidth / $maxHeight > $ratio) $maxWidth = $maxHeight * $ratio;
            else $maxHeight = $maxWidth / $ratio;

            $newImage = imagecreatetruecolor($maxWidth, $maxHeight);

            if ($info['mime'] == 'image/png' || $info['mime'] == 'image/gif') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $maxWidth, $maxHeight, $transparent);
            }

            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $maxWidth, $maxHeight, $width, $height);

            if ($info['mime'] == 'image/jpeg') imagejpeg($newImage, $destination, $quality);
            elseif ($info['mime'] == 'image/gif') imagegif($newImage, $destination);
            elseif ($info['mime'] == 'image/png') imagepng($newImage, $destination, 9 - round($quality/11.11));

            imagedestroy($image);
            imagedestroy($newImage);

            return $destination;
        }

        // التحقق من وجود صورة مقصوصة (Base64)
        if(!empty($_POST['cropped_image'])) {
            $data = $_POST['cropped_image'];
            if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
                $data = substr($data, strpos($data, ',') + 1);
                $type = strtolower($type[1]);

                if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) {
                    throw new Exception('نوع الملف غير مدعوم');
                }

                $data = base64_decode($data, true);
                if ($data === false) {
                    throw new Exception('فشل في فك تشفير الصورة');
                }

                $new_name = 'profile_' . $user_id . '_' . time() . '.' . $type;
                $temp_file = sys_get_temp_dir() . '/' . $new_name;

                if (file_put_contents($temp_file, $data) === false) {
                    throw new Exception('فشل في كتابة الملف المؤقت');
                }

                $target_path = $upload_dir . $new_name;

                if(compressImage($temp_file, $target_path, 80, 300, 300)) {
                    $profile_image = $new_name;
                } else {
                    if (!copy($temp_file, $target_path)) {
                        throw new Exception('فشل في نقل الملف');
                    }
                    $profile_image = $new_name;
                }
                @unlink($temp_file);
            }
        }

        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, profile_image = ? WHERE id = ?");
        $stmt->execute([$full_name, $username, $profile_image, $user_id]);

        $_SESSION['username'] = $username;
        echo json_encode(['status' => 'success', 'message' => 'تم تحديث الملف الشخصي بنجاح'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('Profile update error: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

require_once 'header.php';

$user_id = $_SESSION['admin_id'];
$user_role = $_SESSION['role'] ?? 'editor';
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

$upload_dir = '../assets/uploads/profiles/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// تغيير كلمة المرور
if(isset($_POST['change_password'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='profile.php';</script>");
    }
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    $user_stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $userData = $user_stmt->fetch();

    if(password_verify($current_pass, $userData['password'])) {
        if($new_pass === $confirm_pass) {
            $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_pass, $user_id]);
            header("Location: profile.php?success=2");
        } else {
            header("Location: profile.php?error=match");
        }
    } else {
        header("Location: profile.php?error=current");
    }
    exit;
}

$user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$u = $user_stmt->fetch();
?>

<!-- Cropper.js CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">

<div class="container-fluid">
    <h3 class="fw-bold mb-4"><i class="fas fa-user-circle me-2 text-primary"></i> إعدادات الملف الشخصي</h3>

    <div id="alertContainer">
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success border-0 shadow-sm rounded-3">
                <?php echo h($_GET['success'] == 1 ? 'تم تحديث الملف الشخصي بنجاح.' : 'تم تغيير كلمة المرور بنجاح.'); ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger border-0 shadow-sm rounded-3">
                <?php
                $error_msg = $_GET['error'] ?? '';
                if($error_msg == 'match') echo 'كلمة المرور الجديدة غير متطابقة.';
                elseif($error_msg == 'current') echo 'كلمة المرور الحالية غير صحيحة.';
                else echo 'حدث خطأ ما.';
                ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-primary">المعلومات الشخصية</h5>
                </div>
                <div class="card-body p-4">
                    <form id="profileForm">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="ajax_update_profile" value="1">
                        <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($u['profile_image'] ?? ''); ?>">
                        <input type="hidden" name="cropped_image" id="cropped_image_input">

                        <div class="text-center mb-4">
                            <div class="position-relative d-inline-block">
                                <div id="previewPlaceholder" class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center text-muted border shadow-sm" style="width: 120px; height: 120px; overflow: hidden;">
                                    <?php if($u['profile_image']): ?>
                                        <img src="../assets/uploads/profiles/<?php echo htmlspecialchars($u['profile_image']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-user fa-4x"></i>
                                    <?php endif; ?>
                                </div>
                                <label for="profile_image" class="btn btn-sm btn-primary position-absolute bottom-0 end-0 rounded-circle shadow" style="width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                                    <i class="fas fa-camera"></i>
                                    <input type="file" id="profile_image" class="d-none" accept="image/*">
                                </label>
                            </div>
                            <p class="mt-2 text-muted small">اضغط على الكاميرا لرفع وقص صورتك</p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">الاسم الكامل</label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($u['full_name'] ?? ''); ?>" placeholder="أدخل اسمك الكامل">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">اسم المستخدم</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($u['username']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">الدور / الصلاحية</label>
                            <input type="text" class="form-control bg-light" value="<?php echo strtoupper($user_role); ?>" readonly>
                        </div>

                        <button type="submit" id="saveBtn" class="btn btn-primary w-100 rounded-pill shadow-sm py-2">
                            <i class="fas fa-save me-1"></i> حفظ التغييرات
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-danger">تغيير كلمة المرور</h5>
                </div>
                <div class="card-body p-4">
                    <form action="profile.php" method="POST">
                        <?php echo csrf_input(); ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">كلمة المرور الحالية</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label fw-bold">كلمة المرور الجديدة</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold">تأكيد كلمة المرور الجديدة</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-danger w-100 rounded-pill shadow-sm py-2">تحديث كلمة المرور</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal لقص الصورة -->
<div class="modal fade" id="cropModal" tabindex="-1" aria-labelledby="cropModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cropModalLabel">قص الصورة الشخصية</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="img-container">
            <img id="imageToCrop" src="" style="max-width: 100%;">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
        <button type="button" class="btn btn-primary" id="cropBtn">قص وحفظ</button>
      </div>
    </div>
  </div>
</div>

<!-- Cropper.js JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<script>
let cropper;

// دالة للتحقق من وجود العناصر وتهيئتها
function initializeProfileForm() {
    const imageInput = document.getElementById('profile_image');
    const imageToCrop = document.getElementById('imageToCrop');
    const cropModalElement = document.getElementById('cropModal');
    const cropBtn = document.getElementById('cropBtn');
    const previewPlaceholder = document.getElementById('previewPlaceholder');
    const croppedImageInput = document.getElementById('cropped_image_input');
    const profileForm = document.getElementById('profileForm');
    const saveBtn = document.getElementById('saveBtn');
    const alertContainer = document.getElementById('alertContainer');

    // التحقق من وجود جميع العناصر المطلوبة
    if (!imageInput || !imageToCrop || !cropModalElement || !cropBtn || !previewPlaceholder || !croppedImageInput || !profileForm) {
        console.error('بعض العناصر المطلوبة غير موجودة في الصفحة');
        return;
    }

    const cropModal = new bootstrap.Modal(cropModalElement);

    // معالج تغيير الملف
    imageInput.addEventListener('change', function(e) {
        const files = e.target.files;
        if (files && files.length > 0) {
            const reader = new FileReader();
            reader.onload = function(event) {
                imageToCrop.src = event.target.result;
                cropModal.show();
            };
            reader.readAsDataURL(files[0]);
        }
    });

    // معالج ظهور Modal
    cropModalElement.addEventListener('shown.bs.modal', function() {
        if(cropper) {
            cropper.destroy();
        }
        cropper = new Cropper(imageToCrop, {
            aspectRatio: 1,
            viewMode: 1,
            autoCropArea: 1,
        });
    });

    // معالج إخفاء Modal
    cropModalElement.addEventListener('hidden.bs.modal', function() {
        if(cropper) {
            cropper.destroy();
            cropper = null;
        }
    });

    // معالج زر القص والحفظ
    cropBtn.addEventListener('click', function() {
        if (!cropper) {
            console.error('Cropper لم يتم تهيئته');
            return;
        }

        const canvas = cropper.getCroppedCanvas({
            width: 400,
            height: 400,
        });

        const croppedDataUrl = canvas.toDataURL('image/jpeg', 0.8);
        previewPlaceholder.innerHTML = `<img src="${croppedDataUrl}" style="width: 100%; height: 100%; object-fit: cover;">`;
        croppedImageInput.value = croppedDataUrl;
        cropModal.hide();
    });

    // معالج إرسال النموذج
    profileForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        if (!saveBtn) {
            console.error('زر الحفظ غير موجود');
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> جاري الحفظ...';

        fetch('profile.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('Response text:', text);
            if (!text) {
                throw new Error('استجابة فارغة من الخادم');
            }
            return JSON.parse(text);
        })
        .then(res => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save me-1"></i> حفظ التغييرات';
            if (alertContainer) {
                alertContainer.innerHTML = `<div class="alert alert-${res.status === 'success' ? 'success' : 'danger'} border-0 shadow-sm rounded-3">${res.message}</div>`;
            }
            if(res.status === 'success') {
                setTimeout(() => location.reload(), 1500);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save me-1"></i> حفظ التغييرات';
            if (alertContainer) {
                alertContainer.innerHTML = `<div class="alert alert-danger border-0 shadow-sm rounded-3">حدث خطأ أثناء الحفظ: ${error.message}</div>`;
            }
        });
    });
}

// تهيئة النموذج عند تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeProfileForm);
} else {
    initializeProfileForm();
}
</script>

<?php require_once 'footer.php'; ?>
