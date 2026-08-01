<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if(!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$arabic_months = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
    7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
];

// معالجة طلبات GET قبل أي إخراج HTML
if(isset($_GET['toggle_close'])) {
    $batch_id = (int)$_GET['toggle_close'];
    $status = (int)$_GET['status'];
    $stmt = $pdo->prepare("UPDATE batches SET is_closed = ? WHERE id = ?");
    $stmt->execute([$status, $batch_id]);
    header("Location: batches.php");
    exit;
}

// إضافة دفعة جديدة
if(isset($_POST['add_batch'])) {
    $month_num = (int)$_POST['month'];
    $month_name = $arabic_months[$month_num];
    $stmt = $pdo->prepare("INSERT INTO batches (batch_day, batch_month, batch_year, batch_month_name, status_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['day'], $month_num, $_POST['year'], $month_name, $_POST['status_id']]);
    header("Location: batches.php");
    exit;
}

// تحديث حالة الدفعة (وتحديث جميع الجوازات التابعة لها)
if(isset($_POST['update_batch_status'])) {
    $batch_id = $_POST['batch_id'];
    $status_id = $_POST['status_id'];
    $notes = $_POST['notes'] ?? 'تحديث جماعي لحالة الدفعة';
    
    try {
        $pdo->beginTransaction();
        
        // 1. تحديث حالة الدفعة نفسها
        $stmt = $pdo->prepare("UPDATE batches SET status_id = ? WHERE id = ?");
        $stmt->execute([$status_id, $batch_id]);
        
        // 2. جلب جميع الجوازات في هذه الدفعة
        $stmt_get = $pdo->prepare("SELECT id FROM passports WHERE batch_id = ?");
        $stmt_get->execute([$batch_id]);
        $passport_ids = $stmt_get->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($passport_ids)) {
            // 3. استخدام الدالة الموحدة لتغيير الحالة لجميع الجوازات
            // ملاحظة: الدالة change_transaction_status تقوم بالتسجيل في transaction_status_logs وإرسال الإشعارات وتحديث الأبناء
            if (!change_transaction_status($passport_ids, $status_id, $_SESSION['admin_id'], $notes)) {
                throw new Exception("فشل تحديث حالة الجوازات عبر نظام سير العمل");
            }
        }
        
        $pdo->commit();
        header("Location: batches.php?success=1");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ: " . $e->getMessage();
    }
}

require_once 'header.php';

$batches = $pdo->query("
    SELECT b.*, ws.step_name as status_name, ws.color as status_color, COUNT(p.id) as passport_count
    FROM batches b 
    LEFT JOIN workflow_steps ws ON b.status_id = ws.id 
    LEFT JOIN passports p ON b.id = p.batch_id
    GROUP BY b.id
    ORDER BY b.created_at DESC
")->fetchAll();

// جلب جميع المراحل المتاحة لتأشيرة العمل (لعرضها في القائمة)
$workflow_steps = $pdo->query("SELECT ws.id, ws.step_name as status_name, ws.color as status_color FROM workflow_steps ws JOIN workflows w ON ws.workflow_id = w.id WHERE w.transaction_type = 'work_visa' ORDER BY ws.sort_order")->fetchAll();

$settings_data = getSettings($pdo);

// جلب المرحلة الأولية لسير عمل تأشيرة العمل
$stmt_init = $pdo->prepare("SELECT ws.id FROM workflow_steps ws JOIN workflows w ON ws.workflow_id = w.id WHERE w.transaction_type = ? AND ws.is_initial = 1 LIMIT 1");
$stmt_init->execute(['work_visa']);
$initial_work_visa_status_id = $stmt_init->fetchColumn() ?: 1;
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>إدارة الدفعات</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBatchModal">إضافة دفعة جديدة</button>
    </div>

    <div class="alert alert-info">
        <i class="fas fa-calendar-alt me-2"></i> إعدادات التكرار: يتم تكرار الدفعات كل <strong><?php echo $settings_data['batch_repeat_days']; ?></strong> يوم. يمكنك تعديل هذا من الإعدادات.
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="px-4 py-3">اسم الدفعة</th>
                            <th>التاريخ</th>
                            <th>عدد الجوازات</th>
                            <th>الحالة الحالية</th>
                            <th>حالة الدفعة</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($batches as $b): ?>
                        <tr class="<?php echo $b['is_closed'] ? 'table-light opacity-75' : ''; ?>">
                            <td class="px-4 py-3 fw-bold">
                                دفعة شهر <?php echo $b['batch_month_name'] ?: $arabic_months[$b['batch_month']]; ?> (<?php echo $b['batch_year']; ?>)
                            </td>
                            <td><?php echo $b['batch_day'] . '/' . $b['batch_month'] . '/' . $b['batch_year']; ?></td>
                            <td>
                                <span class="badge bg-info text-dark">
                                    <i class="fas fa-file me-1"></i> <?php echo $b['passport_count']; ?> جواز
                                </span>
                            </td>
                            <td>
                                <span class="badge" style="background-color: <?php echo $b['status_color']; ?>;">
                                    <?php echo $b['status_name']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if($b['is_closed']): ?>
                                    <span class="badge bg-danger">مغلقة</span>
                                <?php else: ?>
                                    <span class="badge bg-success">مفتوحة</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#updateStatusModal<?php echo $b['id']; ?>">
                                        <i class="fas fa-sync-alt"></i> تغيير الحالة
                                    </button>
                                    <?php if($b['is_closed']): ?>
                                        <a href="?toggle_close=<?php echo $b['id']; ?>&status=0" class="btn btn-sm btn-outline-success" onclick="return confirm('هل تريد إعادة فتح هذه الدفعة؟')">
                                            <i class="fas fa-folder-open"></i> فتح الدفعة
                                        </a>
                                    <?php else: ?>
                                        <a href="?toggle_close=<?php echo $b['id']; ?>&status=1" class="btn btn-sm btn-outline-danger" onclick="return confirm('إغلاق الدفعة سيخفي جميع جوازاتها من العرض العام. هل أنت متأكد؟')">
                                            <i class="fas fa-folder"></i> إغلاق الدفعة
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Modal تحديث الحالة -->
                        <div class="modal fade" id="updateStatusModal<?php echo $b['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header"><h5>تحديث حالة الدفعة</h5></div>
                                        <div class="modal-body">
                                            <input type="hidden" name="batch_id" value="<?php echo $b['id']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">المرحلة الجديدة</label>
                                                <select name="status_id" class="form-select rounded-pill shadow-sm">
                                                    <?php foreach($workflow_steps as $step): ?>
                                                        <option value="<?php echo $step['id']; ?>" <?php echo ($step['id'] == $b['status_id']) ? 'selected' : ''; ?>>
                                                            <?php echo $step['status_name']; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold small">ملاحظة التحديث الجماعي</label>
                                                <textarea name="notes" class="form-control rounded-3" rows="2" placeholder="مثال: تم تسليم الدفعة بالكامل للسفارة"></textarea>
                                            </div>
                                            <div class="alert alert-warning border-0 small rounded-4 shadow-sm mb-0">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                تنبيه: سيتم نقل <strong><?php echo $b['passport_count']; ?></strong> جواز إلى المرحلة المختارة وتسجيل ذلك في سجل الحركات لكل جواز.
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                                            <button type="submit" name="update_batch_status" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">تحديث الدفعة كاملة</button>
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

<!-- Modal إضافة دفعة -->
<div class="modal fade" id="addBatchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة دفعة جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-4">
                            <label class="form-label fw-bold small">اليوم</label>
                            <input type="number" name="day" class="form-control rounded-pill" value="<?php echo date('d'); ?>" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-bold small">الشهر</label>
                            <select name="month" class="form-select rounded-pill" required>
                                <?php foreach($arabic_months as $num => $name): ?>
                                    <option value="<?php echo $num; ?>" <?php echo ($num == date('m')) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-bold small">السنة</label>
                            <input type="number" name="year" class="form-control rounded-pill" value="<?php echo date('Y'); ?>" required>
                        </div>
                        
                        <div class="col-12 mt-3">
                            <label class="form-label fw-bold text-primary small"><i class="fas fa-flag me-1"></i> المرحلة الأولية للدفعة</label>
                            <select name="status_id" class="form-select rounded-pill shadow-sm" required>
                                <?php foreach($workflow_steps as $step): ?>
                                    <option value="<?php echo $step['id']; ?>" <?php echo ($step['id'] == $initial_work_visa_status_id) ? 'selected' : ''; ?>>
                                        <?php echo $step['status_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="extra-small text-muted mt-2 px-2">
                                <i class="fas fa-info-circle me-1"></i> تم اختيار "<?php 
                                    foreach($workflow_steps as $s) if($s['id'] == $initial_work_visa_status_id) echo $s['status_name']; 
                                ?>" تلقائياً بناءً على إعدادات سير عمل تأشيرة العمل.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_batch" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">حفظ الدفعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
