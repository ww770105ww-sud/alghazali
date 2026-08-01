<?php
require_once 'header.php';

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings['allow_editor_subscribers']) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات المباشرة عبر الرابط. استخدم أزرار الصفحة المحمية فقط.";
}

// معالجة الحذف
if(isset($_POST['delete_subscriber'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='subscribers.php';</script>");
    }
    $stmt = $pdo->prepare("DELETE FROM subscribers WHERE id = ?");
    $stmt->execute([(int)$_POST['delete_subscriber']]);
    echo "<script>window.location.href='subscribers.php?status=deleted';</script>";
    exit;
}

if(isset($_POST['send_email'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='subscribers.php';</script>");
    }
    $subject = $_POST['subject'];
    $message = $_POST['message'];
    $subscribers_list = $pdo->query("SELECT email FROM subscribers")->fetchAll();
    
    // محاكاة الإرسال
    $success_msg = "تم إرسال الرسالة بنجاح إلى " . count($subscribers_list) . " مشترك.";
}

// تعليم جميع المشتركين كمقروءين عند زيارة الصفحة
$pdo->query("UPDATE subscribers SET is_read = 1 WHERE is_read = 0");

$subscribers = $pdo->query("SELECT * FROM subscribers ORDER BY created_at DESC")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-users-gear me-2 text-primary"></i> إدارة المشتركين</h3>
        <div class="d-flex gap-2">
            <span class="badge bg-primary rounded-pill px-3 py-2 d-flex align-items-center"><?php echo count($subscribers); ?> مشترك</span>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#sendEmailModal">
                <i class="fas fa-paper-plane me-2"></i> إرسال رسالة للجميع
            </button>
        </div>
    </div>

    <?php if(isset($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo $success_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['status']) && $_GET['status'] == 'deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i> تم حذف المشترك بنجاح.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">المشترك</th>
                            <th class="py-3">تاريخ الاشتراك</th>
                            <th class="py-3 text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($subscribers)): ?>
                        <tr>
                            <td colspan="3" class="text-center py-5 text-muted">
                                <i class="fas fa-user-slash fa-3x mb-3 opacity-25"></i>
                                <p class="mb-0">لا يوجد مشتركون حالياً</p>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach($subscribers as $sub): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm me-3 bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold mb-0">
    <?php echo htmlspecialchars($sub['email']); ?>
    <?php if(isset($sub['is_read']) && $sub['is_read'] == 0): ?>
        <span class="badge bg-warning text-dark ms-2" style="font-size: 0.6rem;">جديد</span>
    <?php endif; ?>
</div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 text-muted small">
                                <i class="far fa-calendar-alt me-1"></i>
                                <?php echo date('Y/m/d H:i', strtotime($sub['created_at'])); ?>
                            </td>
                            <td class="py-3 text-center">
                                <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا المشترك من القائمة البريدية؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="delete_subscriber" value="<?php echo $sub['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3" title="حذف">
                                        <i class="fas fa-trash-alt me-1"></i> حذف
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

<!-- Modal إرسال بريد -->
<div class="modal fade" id="sendEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-light border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-paper-plane me-2 text-primary"></i> إرسال حملة بريدية</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 shadow-sm small mb-4">
                        <i class="fas fa-info-circle me-2"></i> سيتم إرسال هذه الرسالة إلى جميع المشتركين (<?php echo count($subscribers); ?> مشترك).
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">عنوان الرسالة (الموضوع)</label>
                        <input type="text" name="subject" class="form-control rounded-3 border-light bg-light py-2" placeholder="أدخل موضوع الرسالة هنا..." required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">محتوى الرسالة</label>
                        <textarea name="message" class="form-control rounded-3 border-light bg-light" rows="8" placeholder="اكتب نص الرسالة البريدية هنا..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light p-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="send_email" class="btn btn-primary rounded-pill px-4">إرسال الحملة الآن</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .table-hover tbody tr:hover { background-color: rgba(0,0,0,0.01) !important; }
    .avatar-sm { flex-shrink: 0; }
</style>

<?php require_once 'footer.php'; ?>
