<?php
$page_title = 'إنشاء نسخة احتياطية';
$error_message = null;
$success_message = null;

require_once dirname(__DIR__) . '/includes/session_config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/backup_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }

    try {
        $backupSql = generateDatabaseBackup($pdo);
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $downloadName = 'backup_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$dbName) . '_' . date('Ymd_His') . '.sql';

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        echo $backupSql;
        exit;
    } catch (Exception $e) {
        $error_message = 'تعذر إنشاء النسخة الاحتياطية: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_backup_server'])) {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }

    try {
        if ((int)backup_get_setting($pdo, 'backup_local_enabled', '0') !== 1) {
            throw new Exception('تفعيل «حفظ نسخة على الخادم» من الإعدادات أولاً، وحدد مساراً آمناً.');
        }
        $backupSql = generateDatabaseBackup($pdo);
        $res = backup_save_sql_to_disk($pdo, $backupSql);
        if (!$res['ok']) {
            throw new Exception($res['error'] ?? 'فشل الحفظ');
        }
        backup_set_setting($pdo, 'backup_last_run_at', date('c'), 'general');
        backup_set_setting($pdo, 'backup_last_run_date', date('Y-m-d'), 'general');
        backup_set_setting($pdo, 'backup_last_status', 'ok: ' . basename($res['path'] ?? ''), 'general');
        $success_message = 'تم حفظ النسخة على الخادم: ' . ($res['path'] ?? '');
    } catch (Exception $e) {
        backup_set_setting($pdo, 'backup_last_status', 'err: ' . mb_substr($e->getMessage(), 0, 200), 'general');
        $error_message = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'يرجى اختيار ملف نسخة احتياطية صحيح.';
    } else {
        $file = $_FILES['backup_file'];
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExt !== 'sql') {
            $error_message = 'يجب أن يكون الملف من نوع SQL.';
        } elseif ($fileSize > 50 * 1024 * 1024) {
            $error_message = 'حجم الملف كبير جداً. الحد الأقصى 50 ميجابايت.';
        } else {
            try {
                $sql = file_get_contents($fileTmpName);
                if ($sql === false) {
                    throw new Exception('تعذر قراءة محتوى الملف.');
                }

                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        $pdo->exec($statement);
                    }
                }
                $success_message = 'تم استعادة النسخة الاحتياطية بنجاح.';
            } catch (Exception $e) {
                $error_message = 'تعذر استعادة النسخة الاحتياطية: ' . $e->getMessage();
            }
        }
    }
}

require_once 'header.php';

$backup_local_enabled = (int)backup_get_setting($pdo, 'backup_local_enabled', '0') === 1;
$backup_local_path = backup_get_setting($pdo, 'backup_local_path', 'storage/db_backups');
$backup_schedule_time = backup_get_setting($pdo, 'backup_schedule_time', '03:00');
$backup_last_run_at = backup_get_setting($pdo, 'backup_last_run_at', '');
$backup_last_status = backup_get_setting($pdo, 'backup_last_status', '');
$cronSecret = backup_get_setting($pdo, 'backup_cron_secret', '');
$resolved = backup_resolve_storage_dir($pdo);
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-database me-2"></i> إنشاء نسخة احتياطية لقاعدة البيانات</h3>
                            <p class="text-muted mb-0">تنزيل ملف SQL أو حفظه على الخادم حسب الإعدادات.</p>
                        </div>
                        <a href="settings.php?tab=backup" class="btn btn-outline-primary rounded-pill">
                            <i class="fas fa-cog me-1"></i> إعدادات النسخ الاحتياطي
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger rounded-4 shadow-sm mb-4">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success rounded-4 shadow-sm mb-4">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-info-circle me-2"></i> حالة الإعدادات</h6>
                    <ul class="list-unstyled small text-muted mb-0">
                        <li><strong>حفظ على الخادم:</strong> <?php echo $backup_local_enabled ? 'مفعّل' : 'غير مفعّل'; ?></li>
                        <li><strong>المسار النسبي:</strong> <?php echo htmlspecialchars($backup_local_path); ?></li>
                        <li><strong>وقت الجدولة المقترح:</strong> <?php echo htmlspecialchars($backup_schedule_time); ?> (يُستخدم مع المهمة المجدولة)</li>
                        <li><strong>المسار المُحَلّ:</strong> <?php echo $resolved ? htmlspecialchars($resolved) : '<span class="text-danger">غير جاهز</span>'; ?></li>
                        <?php if ($backup_last_run_at !== ''): ?>
                            <li><strong>آخر تشغيل:</strong> <?php echo htmlspecialchars($backup_last_run_at); ?></li>
                        <?php endif; ?>
                        <?php if ($backup_last_status !== ''): ?>
                            <li><strong>آخر حالة:</strong> <?php echo htmlspecialchars($backup_last_status); ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <form method="POST">
                        <p class="mb-4 text-muted">اضغط الزر أدناه لإنشاء نسخة احتياطية من جميع جداول البيانات، بما في ذلك هيكل الجداول والبيانات.</p>
                        <button type="submit" name="create_backup" class="btn btn-success rounded-pill px-4 py-2">
                            <i class="fas fa-download me-2"></i> تنزيل النسخة الاحتياطية
                        </button>
                        <?php if ($backup_local_enabled && $resolved): ?>
                            <button type="submit" name="save_backup_server" class="btn btn-primary rounded-pill px-4 py-2 ms-2" onclick="return confirm('سيتم حفظ ملف SQL في مجلد النسخ على الخادم. المتابعة؟');">
                                <i class="fas fa-hdd me-2"></i> حفظ نسخة على الخادم
                            </button>
                        <?php endif; ?>
                        <small class="d-block text-muted mt-3">تنبيه: إذا كانت قاعدة البيانات كبيرة جداً، فقد يستغرق التنزيل وقتاً أطول.</small>
                    </form>
                </div>
            </div>

            <?php if ($cronSecret !== ''): ?>
                <div class="card border-0 shadow-sm rounded-4 mt-3">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-2"><i class="fas fa-clock me-2 text-info"></i> تشغيل مجدول (خارج المتصفح)</h6>
                        <p class="small text-muted mb-2">جدولة مهمة يومية في الوقت الذي اخترته في الإعدادات، مثلاً عبر Windows Task Scheduler:</p>
                        <code class="d-block small p-2 bg-light rounded mb-0 user-select-all">curl "<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000') . dirname($_SERVER['SCRIPT_NAME'] ?? '/admin') . '/backup_run.php?token=' . urlencode($cronSecret)); ?>"</code>
                        <p class="small text-muted mt-2 mb-0">يُنفَّذ الملف <code>backup_run.php</code> في نفس مجلد لوحة التحكم؛ عدّل المسار إذا كان الخادم مختلفاً.</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-warning mb-3"><i class="fas fa-upload me-2"></i> استعادة نسخة احتياطية</h5>
                    <form method="POST" enctype="multipart/form-data">
                        <p class="mb-4 text-muted">اختر ملف نسخة احتياطية SQL لاستعادة قاعدة البيانات. سيتم استبدال البيانات الحالية بالبيانات من الملف.</p>
                        <div class="mb-3">
                            <label for="backup_file" class="form-label">اختر ملف النسخة الاحتياطية</label>
                            <input type="file" class="form-control" id="backup_file" name="backup_file" accept=".sql" required>
                            <div class="form-text">يجب أن يكون الملف من نوع .sql وحجمه أقل من 50 ميجابايت.</div>
                        </div>
                        <button type="submit" name="restore_backup" class="btn btn-warning rounded-pill px-4 py-2" onclick="return confirm('هل أنت متأكد من استعادة النسخة الاحتياطية؟ سيتم استبدال البيانات الحالية.')">
                            <i class="fas fa-upload me-2"></i> استعادة النسخة الاحتياطية
                        </button>
                        <small class="d-block text-muted mt-3">تحذير: هذه العملية لا يمكن التراجع عنها. تأكد من أن لديك نسخة احتياطية حديثة قبل الاستعادة.</small>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
