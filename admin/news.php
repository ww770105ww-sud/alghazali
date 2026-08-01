<?php
require_once 'header.php';

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if ($user_role === 'editor' && empty($settings['allow_editor_news'])) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete']) || isset($_GET['toggle'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// إنشاء جدول الأخبار إذا لم يكن موجوداً
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (PDOException $e) {
    die('خطأ في إنشاء جدول الأخبار: ' . $e->getMessage());
}

// إضافة خبر
if (isset($_POST['add_news'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); window.location.href='news.php';</script>");
    }
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '' || $content === '') {
        echo "<script>alert('يرجى تعبئة العنوان والمحتوى'); window.location.href='news.php';</script>";
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO news (title, content, is_active) VALUES (?, ?, ?)");
    $stmt->execute([$title, $content, $is_active]);

    echo "<script>window.location.href='news.php?success=1';</script>";
    exit();
}

// تعديل خبر
if (isset($_POST['edit_news'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); window.location.href='news.php';</script>");
    }
    $news_id = (int)($_POST['news_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($news_id <= 0 || $title === '' || $content === '') {
        echo "<script>alert('بيانات غير مكتملة'); window.location.href='news.php';</script>";
        exit();
    }

    $stmt = $pdo->prepare("UPDATE news SET title = ?, content = ?, is_active = ? WHERE id = ?");
    $stmt->execute([$title, $content, $is_active, $news_id]);

    echo "<script>window.location.href='news.php?updated=1';</script>";
    exit();
}

// حذف خبر عبر POST + CSRF
if (isset($_POST['delete_news'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); window.location.href='news.php';</script>");
    }
    $news_id = (int)$_POST['delete_news'];

    if ($news_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
        $stmt->execute([$news_id]);
    }

    echo "<script>window.location.href='news.php?deleted=1';</script>";
    exit();
}

// تغيير الحالة عبر POST + CSRF
if (isset($_POST['toggle_news'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); window.location.href='news.php';</script>");
    }
    $news_id = (int)$_POST['toggle_news'];

    if ($news_id > 0) {
        $stmt = $pdo->prepare("UPDATE news SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$news_id]);
    }

    echo "<script>window.location.href='news.php?toggled=1';</script>";
    exit();
}

// جلب بيانات التعديل
$edit_news = null;
if (isset($_GET['edit'])) {
    $news_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM news WHERE id = ?");
    $stmt->execute([$news_id]);
    $edit_news = $stmt->fetch(PDO::FETCH_ASSOC);
}

// جلب الأخبار
$stmt = $pdo->query("SELECT * FROM news ORDER BY created_at DESC, id DESC");
$news_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات
$total_news = count($news_list);
$active_news = 0;
$inactive_news = 0;

foreach ($news_list as $item) {
    if ((int)$item['is_active'] === 1) {
        $active_news++;
    } else {
        $inactive_news++;
    }
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h2 class="mb-1">إدارة الأخبار</h2>
            <p class="text-muted mb-0">إضافة وتعديل وحذف أخبار الموقع</p>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">تمت إضافة الخبر بنجاح.</div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">تم تعديل الخبر بنجاح.</div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">تم حذف الخبر بنجاح.</div>
    <?php endif; ?>

    <?php if (isset($_GET['toggled'])): ?>
        <div class="alert alert-success">تم تحديث حالة الخبر بنجاح.</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted mb-2">إجمالي الأخبار</div>
                    <div class="fs-3 fw-bold"><?php echo $total_news; ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted mb-2">الأخبار المفعلة</div>
                    <div class="fs-3 fw-bold text-success"><?php echo $active_news; ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted mb-2">الأخبار غير المفعلة</div>
                    <div class="fs-3 fw-bold text-danger"><?php echo $inactive_news; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <?php echo $edit_news ? 'تعديل الخبر' : 'إضافة خبر جديد'; ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="post">
                <?php echo csrf_input(); ?>
                <?php if ($edit_news): ?>
                    <input type="hidden" name="news_id" value="<?php echo (int)$edit_news['id']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">عنوان الخبر</label>
                    <input
                        type="text"
                        name="title"
                        class="form-control"
                        required
                        value="<?php echo htmlspecialchars($edit_news['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">محتوى الخبر</label>
                    <textarea
                        name="content"
                        class="form-control"
                        rows="6"
                        required
                    ><?php echo htmlspecialchars($edit_news['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="form-switch-container mb-4">
                    <div class="form-switch">
                        <label class="form-check-label fw-bold text-dark" for="is_active">
                            تفعيل الخبر ونشره في الموقع
                        </label>
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="is_active"
                            id="is_active"
                            <?php
                            $checked = $edit_news ? ((int)$edit_news['is_active'] === 1) : true;
                            echo $checked ? 'checked' : '';
                            ?>
                        >
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($edit_news): ?>
                        <button type="submit" name="edit_news" class="btn btn-primary">حفظ التعديلات</button>
                        <a href="news.php" class="btn btn-secondary">إلغاء</a>
                    <?php else: ?>
                        <button type="submit" name="add_news" class="btn btn-success">إضافة الخبر</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">قائمة الأخبار</h5>
        </div>
        <div class="card-body">
            <?php if (empty($news_list)): ?>
                <div class="alert alert-info mb-0">لا توجد أخبار مضافة حالياً.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:70px;">#</th>
                                <th>العنوان</th>
                                <th>المحتوى</th>
                                <th style="width:120px;">الحالة</th>
                                <th style="width:180px;">تاريخ الإضافة</th>
                                <th style="width:220px;">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($news_list as $news): ?>
                                <tr>
                                    <td><?php echo (int)$news['id']; ?></td>
                                    <td><?php echo htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                        $content_preview = mb_substr(strip_tags($news['content']), 0, 120, 'UTF-8');
                                        echo htmlspecialchars($content_preview, ENT_QUOTES, 'UTF-8');
                                        if (mb_strlen(strip_tags($news['content']), 'UTF-8') > 120) {
                                            echo '...';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$news['is_active'] === 1): ?>
                                            <span class="badge bg-success">مفعل</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">معطل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($news['created_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <a href="news.php?edit=<?php echo (int)$news['id']; ?>" class="btn btn-sm btn-primary">تعديل</a>
                                            <form method="post" class="d-inline-block mb-0">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="toggle_news" value="<?php echo (int)$news['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-warning">
                                                    <?php echo ((int)$news['is_active'] === 1) ? 'تعطيل' : 'تفعيل'; ?>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الخبر؟');">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="delete_news" value="<?php echo (int)$news['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    حذف
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
