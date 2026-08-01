<?php
$page_title = "تنفيذ الترحيل";
require_once 'header.php';

$message = '';
$message_type = 'info';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['run_migration'])) {
    try {
        ob_start();
        include __DIR__ . '/../migrate_new_features.php';
        $output = ob_get_clean();
        $message = nl2br(htmlspecialchars($output));
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'خطأ: ' . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
}
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-database me-2 text-primary"></i> تنفيذ الترحيل</h5>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message_type; ?> mb-4">
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        هذا الترحيل سيقوم ب:
                        <ul class="mb-0 mt-2">
                            <li>إضافة الإعدادات الافتراضية للوقت والتاريخ والمنطقة الزمنية</li>
                            <li>إنشاء جدول <code>user_activity_logs</code> لسجل النشاط</li>
                            <li>إنشاء جدول <code>user_sessions</code> لإدارة الجلسات</li>
                            <li>إنشاء جدول <code>attendance_attempts</code> لمحاولات الحضور</li>
                            <li>تعديل جدول الحضور إذا كان موجوداً</li>
                        </ul>
                    </div>

                    <form method="POST" onsubmit="return confirm('هل أنت متأكد من تشغيل الترحيل؟')">
                        <button type="submit" name="run_migration" class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-play me-2"></i> تشغيل الترحيل
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>