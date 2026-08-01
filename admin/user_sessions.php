<?php
$page_title = "إدارة الجلسات";
require_once 'header.php';

requireAdminAccess();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'خطأ في التحقق من الطلب (CSRF). يرجى المحاولة مرة أخرى.';
    } else {
        if (isset($_POST['terminate_session'])) {
            if (isset($_POST['session_id']) && is_numeric($_POST['session_id'])) {
                $ok = terminateUserSession($_POST['session_id']);
                $_SESSION['flash_message'] = $ok
                    ? ['type' => 'success', 'title' => 'نجاح!', 'body' => 'تم إنهاء الجلسة بنجاح']
                    : ['type' => 'danger',  'title' => 'خطأ!',  'body' => 'تعذر إنهاء الجلسة.'];
            } elseif (isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
                $ok = endAllUserSessions($_POST['user_id']);
                $_SESSION['flash_message'] = $ok
                    ? ['type' => 'success', 'title' => 'نجاح!', 'body' => 'تم إنهاء جميع جلسات المستخدم بنجاح']
                    : ['type' => 'danger',  'title' => 'خطأ!',  'body' => 'تعذر إنهاء الجلسات.'];
            }
        } elseif (isset($_POST['block_device'])) {
            if (isset($_POST['session_id']) && is_numeric($_POST['session_id'])) {
                $reason = trim((string)($_POST['reason'] ?? ''));
                if (mb_strlen($reason) > 255) {
                    $errors[] = 'سبب الحظر طويل جداً (الحد الأقصى 255 حرف).';
                } else {
                    $ok = blockDevice($_POST['session_id'], $reason);
                    $_SESSION['flash_message'] = $ok
                        ? ['type' => 'success', 'title' => 'نجاح!', 'body' => 'تم حظر الجهاز وإنهاء الجلسة بنجاح']
                        : ['type' => 'danger',  'title' => 'خطأ!',  'body' => 'تعذر حظر الجهاز.'];
                }
            }
        } elseif (isset($_POST['unblock_device'])) {
            if (isset($_POST['blocked_device_id']) && is_numeric($_POST['blocked_device_id'])) {
                $ok = unblockDevice($_POST['blocked_device_id']);
                $_SESSION['flash_message'] = $ok
                    ? ['type' => 'success', 'title' => 'نجاح!', 'body' => 'تم إلغاء حظر الجهاز بنجاح']
                    : ['type' => 'danger',  'title' => 'خطأ!',  'body' => 'تعذر إلغاء حظر الجهاز.'];
            }
        }
    }
    if (headers_sent()) {
        echo '<script>window.location.href = "user_sessions.php";</script>';
        exit();
    }
    header('Location: user_sessions.php');
    exit();
}

$settings = getSettings($pdo);
$allow_multiple_sessions = normalize_bool_setting($settings['allow_multiple_sessions'] ?? false);
$session_behavior = $settings['session_behavior'] ?? 'reject_new';

$active_tab = $_GET['tab'] ?? 'sessions';

$filter_user     = $_GET['user_id']    ?? '';
$filter_status   = $_GET['status']     ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to   = $_GET['date_to']   ?? '';

$users = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name")->fetchAll();

if ($active_tab === 'blocked') {
    $blocked_devices = getBlockedDevices($filter_user ?: null);
    $blocker_ids = [];
    foreach ($blocked_devices as $b) {
        if (!empty($b['blocked_by'])) $blocker_ids[(int)$b['blocked_by']] = true;
    }
    $blockers = [];
    if (!empty($blocker_ids)) {
        $ph = implode(',', array_fill(0, count($blocker_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, full_name, username FROM users WHERE id IN ($ph)");
        $stmt->execute(array_keys($blocker_ids));
        foreach ($stmt->fetchAll() as $b) {
            $blockers[(int)$b['id']] = $b;
        }
    }
} else {
    $sql = "SELECT us.*, u.username, u.full_name
            FROM user_sessions us
            LEFT JOIN users u ON us.user_id = u.id
            WHERE 1=1";
    $params = [];

    if (!empty($filter_user)) {
        $sql .= " AND us.user_id = ?";
        $params[] = (int)$filter_user;
    }
    if (!empty($filter_status)) {
        $sql .= " AND us.status = ?";
        $params[] = (string)$filter_status;
    }
    if (!empty($filter_date_from)) {
        $sql .= " AND DATE(us.started_at) >= ?";
        $params[] = (string)$filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $sql .= " AND DATE(us.started_at) <= ?";
        $params[] = (string)$filter_date_to;
    }
    $sql .= " ORDER BY us.started_at DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();
}
?>

<style>
    .session-actions { min-width: 245px; }
    .session-actions .btn { white-space: nowrap; }
    .session-actions .block-reason { width: 112px; }
    @media (max-width: 768px) {
        .session-actions { min-width: 190px; }
        .session-actions .block-reason { width: 100%; }
    }
</style>

<div class="container-fluid py-4">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>خطأ:</strong><br>
            <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold"><i class="fas fa-cog me-2 text-primary"></i> إعدادات الجلسات الحالية</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="alert <?php echo $allow_multiple_sessions ? 'alert-info' : 'alert-warning'; ?>">
                        <i class="fas fa-users me-2"></i>
                        <strong>تعدد الجلسات:</strong>
                        <?php echo $allow_multiple_sessions ? 'مسموح به (يمكن تسجيل الدخول من أجهزة متعددة)' : 'ممنوع (جهاز واحد فقط)'; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info">
                        <i class="fas fa-exchange-alt me-2"></i>
                        <strong>سلوك الجلسات:</strong>
                        <?php echo $session_behavior === 'terminate_old' ? 'إنهاء الجلسة القديمة تلقائياً' : 'رفض تسجيل الدخول الجديد'; ?>
                    </div>
                </div>
            </div>
            <a href="settings.php?tab=time_date" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-edit me-1"></i> تعديل الإعدادات
            </a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" id="sessionTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'sessions' ? 'active' : ''; ?>" href="?tab=sessions">
                <i class="fas fa-desktop me-1"></i> الجلسات النشطة والسابقة
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'blocked' ? 'active' : ''; ?>" href="?tab=blocked">
                <i class="fas fa-ban me-1"></i> الأجهزة المحظورة
            </a>
        </li>
    </ul>

    <div class="tab-content" id="sessionTabContent">
        <div class="tab-pane fade <?php echo $active_tab === 'sessions' ? 'show active' : ''; ?>" id="sessions-tab">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="tab" value="sessions">
                        <div class="col-md-3">
                            <label class="form-label">المستخدم</label>
                            <select name="user_id" class="form-select">
                                <option value="">جميع المستخدمين</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo $filter_user == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">الحالة</label>
                            <select name="status" class="form-select">
                                <option value="">جميع الحالات</option>
                                <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>نشطة</option>
                                <option value="terminated" <?php echo $filter_status == 'terminated' ? 'selected' : ''; ?>>منتهية</option>
                                <option value="ended" <?php echo $filter_status == 'ended' ? 'selected' : ''; ?>>انتهت</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">من تاريخ</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">إلى تاريخ</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search me-1"></i> بحث</button>
                            <a href="user_sessions.php?tab=sessions" class="btn btn-outline-secondary"><i class="fas fa-redo me-1"></i> إعادة تعيين</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-desktop me-2 text-primary"></i> سجل الجلسات</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>المستخدم</th>
                                    <th>تاريخ البداية</th>
                                    <th>تاريخ الانتهاء</th>
                                    <th>مدة الجلسة</th>
                                    <th>IP</th>
                                    <th>المتصفح</th>
                                    <th>نظام التشغيل</th>
                                    <th>الجهاز</th>
                                    <th>المنطقة الزمنية</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sessions)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4 text-muted">
                                            <i class="fas fa-inbox fa-3x mb-2 d-block"></i>
                                            لا توجد جلسات
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sessions as $session):
                                        $duration = '';
                                        if (!empty($session['ended_at']) && !empty($session['started_at'])) {
                                            $start = @new DateTime($session['started_at']);
                                            $end   = @new DateTime($session['ended_at']);
                                            if ($start && $end) {
                                                $diff = $start->diff($end);
                                                if ($diff->days > 0) {
                                                    $duration = $diff->days . ' يوم ' . $diff->h . ' س ' . $diff->i . ' د';
                                                } elseif ($diff->h > 0) {
                                                    $duration = $diff->h . ' س ' . $diff->i . ' د';
                                                } else {
                                                    $duration = $diff->i . ' د ' . $diff->s . ' ث';
                                                }
                                            }
                                        } elseif ($session['status'] === 'active') {
                                            $duration = '<span class="text-success fw-bold">جارية</span>';
                                        }

                                        $dt_en = $session['device_type'] ?? '';
                                        $dt_ar = getDeviceTypeLabel($dt_en);
                                        $dt_class = 'desktop';
                                        if (in_array(strtolower($dt_en), ['mobile', 'جوال'], true)) $dt_class = 'mobile';
                                        elseif (in_array(strtolower($dt_en), ['tablet', 'تابلت'], true)) $dt_class = 'tablet';

                                        $statusBadgeClass = 'secondary';
                                        $statusAr = 'انتهت';
                                        if ($session['status'] === 'active') {
                                            $statusBadgeClass = 'success';
                                            $statusAr = 'نشطة';
                                        } elseif ($session['status'] === 'terminated') {
                                            $statusBadgeClass = 'danger';
                                            $statusAr = 'منتهية (من قبل الإدارة)';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($session['full_name'] ?? $session['username'] ?? 'N/A'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($session['username'] ?? ''); ?></small>
                                            </td>
                                            <td class="text-nowrap small"><?php echo format_date_display($session['started_at'], true); ?></td>
                                            <td class="text-nowrap small"><?php echo !empty($session['ended_at']) ? format_date_display($session['ended_at'], true) : '-'; ?></td>
                                            <td class="small"><?php echo $duration; ?></td>
                                            <td><code class="small"><?php echo htmlspecialchars($session['ip_address'] ?? ''); ?></code></td>
                                            <td class="small"><?php echo htmlspecialchars($session['browser'] ?? ''); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($session['operating_system'] ?? ''); ?></td>
                                            <td>
                                                <i class="fas fa-<?php
                                                    echo $dt_class === 'mobile'  ? 'mobile-alt'  :
                                                         ($dt_class === 'tablet' ? 'tablet-alt' : 'desktop');
                                                ?> me-1"></i>
                                                <?php echo htmlspecialchars($dt_ar); ?>
                                            </td>
                                            <td class="small"><?php echo htmlspecialchars($session['timezone'] ?? ''); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusBadgeClass; ?>">
                                                    <?php echo $statusAr; ?>
                                                </span>
                                            </td>
                                            <td class="session-actions">
                                                <?php if ($session['status'] === 'active'): ?>
                                                    <div class="d-flex flex-wrap align-items-center gap-1">
                                                        <form method="POST" class="m-0">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="session_id" value="<?php echo (int)$session['id']; ?>">
                                                            <button type="submit" name="terminate_session" class="btn btn-outline-danger btn-sm" onclick="return confirm('هل أنت متأكد من إنهاء هذه الجلسة؟')" title="إنهاء الجلسة">
                                                                <i class="fas fa-stop me-1"></i> إنهاء
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="m-0 d-flex align-items-center gap-1 js-block-device-form">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="session_id" value="<?php echo (int)$session['id']; ?>">
                                                            <input type="text" name="reason" class="form-control form-control-sm block-reason d-none" placeholder="سبب الحظر" maxlength="255">
                                                            <button type="submit" name="block_device" class="btn btn-outline-warning btn-sm js-block-device-button" title="حظر الجهاز">
                                                                <i class="fas fa-ban me-1"></i> حظر
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="m-0">
                                                            <?php echo csrf_input(); ?>
                                                            <input type="hidden" name="user_id" value="<?php echo (int)$session['user_id']; ?>">
                                                            <button type="submit" name="terminate_session" class="btn btn-outline-secondary btn-sm" onclick="return confirm('هل أنت متأكد من إنهاء جميع جلسات هذا المستخدم؟')" title="إنهاء جميع الجلسات">
                                                                <i class="fas fa-stop-circle me-1"></i> الكل
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
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

        <div class="tab-pane fade <?php echo $active_tab === 'blocked' ? 'show active' : ''; ?>" id="blocked-tab">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="tab" value="blocked">
                        <div class="col-md-3">
                            <label class="form-label">المستخدم</label>
                            <select name="user_id" class="form-select">
                                <option value="">جميع المستخدمين</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo $filter_user == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search me-1"></i> بحث</button>
                            <a href="user_sessions.php?tab=blocked" class="btn btn-outline-secondary"><i class="fas fa-redo me-1"></i> إعادة تعيين</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-ban me-2 text-primary"></i> الأجهزة المحظورة</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>المستخدم</th>
                                    <th>تاريخ الحظر</th>
                                    <th>IP</th>
                                    <th>السبب</th>
                                    <th>محظور بواسطة</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($blocked_devices)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <i class="fas fa-inbox fa-3x mb-2 d-block"></i>
                                            لا توجد أجهزة محظورة
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($blocked_devices as $device): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($device['full_name'] ?? $device['username'] ?? 'N/A'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($device['username'] ?? ''); ?></small>
                                            </td>
                                            <td class="text-nowrap small"><?php echo format_date_display($device['blocked_at'], true); ?></td>
                                            <td><code class="small"><?php echo htmlspecialchars($device['ip_address'] ?? ''); ?></code></td>
                                            <td class="small"><?php echo htmlspecialchars($device['reason'] ?? '-'); ?></td>
                                            <td class="small">
                                                <?php if (!empty($device['blocked_by']) && isset($blockers[(int)$device['blocked_by']])): ?>
                                                    <?php $bl = $blockers[(int)$device['blocked_by']];
                                                          echo htmlspecialchars($bl['full_name'] ?? $bl['username']); ?>
                                                <?php elseif (!empty($device['blocked_by'])): ?>
                                                    <span class="text-muted">#<?php echo (int)$device['blocked_by']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo !empty($device['is_active']) ? 'danger' : 'secondary'; ?>">
                                                    <?php echo !empty($device['is_active']) ? 'محظور' : 'غير محظور'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($device['is_active'])): ?>
                                                    <form method="POST" class="d-inline">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="blocked_device_id" value="<?php echo (int)$device['id']; ?>">
                                                        <button type="submit" name="unblock_device" class="btn btn-success btn-sm" onclick="return confirm('هل أنت متأكد من إلغاء حظر هذا الجهاز؟');">
                                                            <i class="fas fa-unlock"></i> إلغاء الحظر
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
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

<script>
document.querySelectorAll('.js-block-device-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        const reasonInput = form.querySelector('.block-reason');
        const button = form.querySelector('.js-block-device-button');

        if (reasonInput && reasonInput.classList.contains('d-none')) {
            event.preventDefault();
            reasonInput.classList.remove('d-none');
            reasonInput.focus();
            if (button) {
                button.classList.remove('btn-outline-warning');
                button.classList.add('btn-warning', 'text-white');
                button.innerHTML = '<i class="fas fa-check me-1"></i> تأكيد';
            }
            return;
        }

        if (!confirm('هل أنت متأكد من حظر هذا الجهاز؟')) {
            event.preventDefault();
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
