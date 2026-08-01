<?php
require_once 'header.php';

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings['allow_editor_messages']) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete']) || isset($_GET['read'])) {
    $error = "تم تعطيل تنفيذ الإجراءات المباشرة عبر الرابط. استخدم أزرار الصفحة المحمية فقط.";
}

// معالجة الحذف
if(isset($_POST['delete_message'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='messages.php';</script>");
    }
    $stmt = $pdo->prepare("DELETE FROM contact_messages WHERE id = ?");
    $stmt->execute([(int)$_POST['delete_message']]);
    echo "<script>window.location.href='messages.php?status=deleted';</script>";
    exit;
}

// معالجة القراءة
if(isset($_POST['mark_read'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='messages.php';</script>");
    }
    $stmt = $pdo->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
    $stmt->execute([(int)$_POST['mark_read']]);
    echo "<script>window.location.href='messages.php?status=read';</script>";
    exit;
}

$messages = $pdo->query("SELECT * FROM contact_messages ORDER BY created_at DESC")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-envelope-open-text me-2 text-primary"></i> رسائل اتصل بنا</h3>
        <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo count($messages); ?> رسالة إجمالية</span>
    </div>

    <?php if(isset($_GET['status'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php 
                if($_GET['status'] == 'deleted') echo "تم حذف الرسالة بنجاح.";
                if($_GET['status'] == 'read') echo "تم تحديد الرسالة كمقروءة.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">المرسل</th>
                            <th class="py-3">الموضوع</th>
                            <th class="py-3">التاريخ</th>
                            <th class="py-3">الحالة</th>
                            <th class="py-3 text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($messages)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                                <p class="mb-0">لا توجد رسائل واردة حالياً</p>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach($messages as $msg): ?>
                        <tr class="<?php echo $msg['is_read'] ? 'opacity-75' : 'fw-bold bg-light-primary'; ?>">
                            <td class="px-4 py-3">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm me-3 bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div>
                                        <div class="mb-0"><?php echo htmlspecialchars($msg['name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($msg['phone']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3">
                                <span class="text-truncate d-inline-block" style="max-width: 200px;">
                                    <?php echo htmlspecialchars($msg['subject']); ?>
                                </span>
                            </td>
                            <td class="py-3 text-muted small">
                                <i class="far fa-clock me-1"></i>
                                <?php echo date('Y/m/d H:i', strtotime($msg['created_at'])); ?>
                            </td>
                            <td class="py-3">
                                <?php if($msg['is_read']): ?>
                                    <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill">مقروءة</span>
                                <?php else: ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill">جديدة</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center">
                                <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                                    <button class="btn btn-white btn-sm px-3 border-end" title="عرض" data-bs-toggle="modal" data-bs-target="#viewMsgModal<?php echo $msg['id']; ?>">
                                        <i class="fas fa-eye text-info"></i>
                                    </button>
                                    <?php if(!$msg['is_read']): ?>
                                    <form method="POST" class="d-inline-block mb-0">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="mark_read" value="<?php echo $msg['id']; ?>">
                                        <button type="submit" class="btn btn-white btn-sm px-3 border-end" title="تحديد كمقروءة">
                                            <i class="fas fa-check text-success"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذه الرسالة نهائياً؟')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_message" value="<?php echo $msg['id']; ?>">
                                        <button type="submit" class="btn btn-white btn-sm px-3" title="حذف">
                                            <i class="fas fa-trash text-danger"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Modal عرض الرسالة -->
                        <div class="modal fade" id="viewMsgModal<?php echo $msg['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border-0 shadow-lg rounded-4">
                                    <div class="modal-header bg-light border-0 py-3">
                                        <h5 class="modal-title fw-bold"><i class="fas fa-envelope-open me-2 text-primary"></i> تفاصيل الرسالة</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <div class="row mb-4">
                                            <div class="col-6">
                                                <label class="text-muted small mb-1">المرسل</label>
                                                <p class="fw-bold mb-0"><?php echo htmlspecialchars($msg['name']); ?></p>
                                            </div>
                                            <div class="col-6">
                                                <label class="text-muted small mb-1">الهاتف</label>
                                                <p class="fw-bold mb-0"><?php echo htmlspecialchars($msg['phone']); ?></p>
                                            </div>
                                        </div>
                                        <div class="mb-4">
                                            <label class="text-muted small mb-1">البريد الإلكتروني</label>
                                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($msg['email'] ?: 'غير متوفر'); ?></p>
                                        </div>
                                        <div class="mb-4">
                                            <label class="text-muted small mb-1">الموضوع</label>
                                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($msg['subject']); ?></p>
                                        </div>
                                        <div class="p-3 bg-light rounded-3">
                                            <label class="text-muted small mb-2 d-block">نص الرسالة</label>
                                            <p class="mb-0 text-dark" style="white-space: pre-wrap; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                        </div>
                                    </div>
                                    <div class="modal-footer border-0 bg-light p-3">
                                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
                                        <?php if(!$msg['is_read']): ?>
                                            <form method="POST" class="d-inline-block mb-0">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="mark_read" value="<?php echo $msg['id']; ?>">
                                                <button type="submit" class="btn btn-primary rounded-pill px-4">تحديد كمقروءة</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
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

<style>
    .bg-light-primary { background-color: rgba(13, 110, 253, 0.02) !important; }
    .table-hover tbody tr:hover { background-color: rgba(0,0,0,0.01) !important; }
    .btn-white { background: #fff; border: 1px solid #eee; }
    .btn-white:hover { background: #f8f9fa; }
    .avatar-sm { flex-shrink: 0; }
</style>

<?php require_once 'footer.php'; ?>
