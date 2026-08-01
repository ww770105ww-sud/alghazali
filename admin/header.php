<?php
ob_start();
define('SYSTEM_ACCESS', true);
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_functions.php';
require_once __DIR__ . '/../includes/system_error_audit.php';
require_once __DIR__ . '/../includes/crm_functions.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// Validate that current session is still active in database
$current_session_id = session_id();
$current_user_id = $_SESSION['admin_id'];
try {
    // Check if user_sessions table has device_fingerprint column
    $checkCol = $pdo->query("SHOW COLUMNS FROM user_sessions LIKE 'device_fingerprint'");
    $hasDeviceFingerprint = $checkCol->fetch() !== false;
    
    $stmt = $pdo->prepare("SELECT status, id FROM user_sessions WHERE session_id = ? AND user_id = ? ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$current_session_id, $current_user_id]);
    $session = $stmt->fetch();
    
    if (!$session) {
        // Session not found in database, create a new one!
        createUserSession($current_user_id);
    } elseif ($session['status'] !== 'active') {
        // Session is terminated, log out!
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
} catch (Exception $e) {
    // If there's any error, log it and continue
    error_log("Session validation error: " . $e->getMessage());
}

// Update last session activity
updateSessionActivity();

// تسجيل أخطاء النظام (لوحة التحكم فقط)
try {
    register_system_error_audit($pdo);
} catch (Throwable $e) {
    // Silent fail.
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
$u = $pdo->prepare("
    SELECT u.*, r.name as role_name, r.display_name as role_display_name, b.branch_name, a.agent_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN branches b ON u.branch_id = b.id
    LEFT JOIN agents a ON u.agent_id = a.id
    WHERE u.id = ?
");
$u->execute([$user_id]);
$currentUser = $u->fetch();

$settings = getSettings($pdo);

// جلب تفضيل السمة للمستخدم
$user_theme = 'light';
if ($user_id) {
    try {
        $stmt_pref = $pdo->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = 'theme'");
        $stmt_pref->execute([$user_id]);
        $user_theme = $stmt_pref->fetchColumn() ?: ($settings['default_theme'] ?? 'light');
    } catch (PDOException $e) {
        $user_theme = $settings['default_theme'] ?? 'light';
    }
} else {
    $user_theme = $settings['default_theme'] ?? 'light';
}

$user_role = $currentUser['role_name'] ?? $_SESSION['role'] ?? 'employee';
$user_role_id = $currentUser['role_id'] ?? $_SESSION['role_id'] ?? null;
$user_branch_id = $currentUser['branch_id'] ?? $_SESSION['branch_id'] ?? null;
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

// تنبيه النسخ الاحتياطي المجدول (وقت محدد ولم يُنفَّذ اليوم بعد)
$backup_due_banner = '';
if ($is_admin && !empty($settings['backup_local_enabled']) && !empty($settings['backup_notify_due'])) {
    $sched = $settings['backup_schedule_time'] ?? '03:00';
    $lastD = $settings['backup_last_run_date'] ?? '';
    $today = date('Y-m-d');
    $nowHM = date('H:i');
    if ($lastD !== $today && $nowHM >= $sched) {
        $backup_due_banner = 'حان وقت النسخ الاحتياطي اليومي (الجدولة: ' . htmlspecialchars($sched) . '). نفّذ المهمة المجدولة أو '
            . '<a href="db_backup.php" class="alert-link">افتح صفحة النسخ الاحتياطي</a>.';
    }
}

// جلب عدد الرسائل غير المقروءة
$unread_messages = $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0")->fetchColumn();

// جلب عدد المشتركين الجدد (غير المقروءين)
$new_subscribers = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE is_read = 0")->fetchColumn();

// جلب عدد الرسائل الداخلية غير المقروءة
$admin_id = $_SESSION['admin_id'];
$unread_internal = $pdo->prepare("SELECT COUNT(*) FROM internal_messages WHERE receiver_id = ? AND is_read = 0");
$unread_internal->execute([$admin_id]);
$unread_internal_count = $unread_internal->fetchColumn();

// دالة للتحقق من تواريخ السفر القادمة وتوليد إشعارات
function checkUpcomingTravelNotifications($pdo, $currentUser) {
    // إضافة الأعمدة إذا لم تكن موجودة (جاهز لأي حالة)
    try {
        $check1 = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'source_type'");
        if (!$check1->fetch()) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN source_type VARCHAR(100) NULL AFTER link");
        }
        $check2 = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'source_id'");
        if (!$check2->fetch()) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN source_id INT(11) NULL AFTER source_type");
        }
        $check3 = $pdo->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notifications_source'");
        if (!$check3->fetch()) {
            $pdo->exec("CREATE INDEX idx_notifications_source ON notifications (source_type, source_id)");
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء هنا، الأعمدة موجودة بالفعل أو لا نحتاجها الآن
    }
    
    $todayDate = date('Y-m-d');
    $tomorrowDate = date('Y-m-d', strtotime('+1 day'));
    
    // 1. تحقق من حجوزات الطيران والباصات
    $bookingsStmt = $pdo->prepare("
        SELECT 
            b.id, 
            b.traveler_name, 
            b.departure_date, 
            b.service_type,
            b.branch_id,
            b.agent_id
        FROM bus_flight_bookings b
        WHERE 
            b.deleted_at IS NULL 
            AND b.departure_date IS NOT NULL
            AND b.departure_date BETWEEN ? AND ?
    ");
    $bookingsStmt->execute([$todayDate, $tomorrowDate]);
    $bookings = $bookingsStmt->fetchAll();
    
    foreach ($bookings as $booking) {
        // تحقق من وجود إشعار بالفعل
        $existing = $pdo->prepare("
            SELECT id FROM notifications 
            WHERE source_type = ? AND source_id = ?
        ");
        $sourceType = $booking['service_type'] == 'flight' ? 'flight_booking' : 'bus_booking';
        $existing->execute([$sourceType, $booking['id']]);
        
        if (!$existing->fetch()) {
            // إنشاء إشعار
            $title = $booking['service_type'] == 'flight' ? 'تنبيه: موعد طيران قريب' : 'تنبيه: موعد رحلـة باص قريب';
            $message = "المسافر: " . $booking['traveler_name'] . "\nتاريخ المغادرة: " . $booking['departure_date'];
            $link = 'bus_flight_bookings.php';
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    branch_id, agent_id, title, message, link, type, 
                    source_type, source_id, created_by
                ) VALUES (?, ?, ?, ?, ?, 'warning', ?, ?, ?)
            ");
            $stmt->execute([
                $booking['branch_id'],
                $booking['agent_id'],
                $title,
                $message,
                $link,
                $sourceType,
                $booking['id'],
                $currentUser['id']
            ]);
        }
    }
    
    // 2. تحقق من معاملات الجوازات (تاريخ السفر)
    $passportStmt = $pdo->prepare("
        SELECT 
            pt.id, 
            pt.full_name, 
            pt.travel_date,
            pt.branch_id,
            pt.agent_id
        FROM passport_transactions pt
        WHERE 
            pt.travel_date IS NOT NULL
            AND pt.travel_date BETWEEN ? AND ?
    ");
    $passportStmt->execute([$todayDate, $tomorrowDate]);
    $passportTrxs = $passportStmt->fetchAll();
    
    foreach ($passportTrxs as $trx) {
        // تحقق من وجود إشعار بالفعل
        $existing = $pdo->prepare("
            SELECT id FROM notifications 
            WHERE source_type = ? AND source_id = ?
        ");
        $existing->execute(['passport_travel', $trx['id']]);
        
        if (!$existing->fetch()) {
            // إنشاء إشعار
            $title = 'تنبيه: موعد سفر قريب (معاملة جوازات)';
            $message = "الاسم: " . $trx['full_name'] . "\nتاريخ السفر: " . $trx['travel_date'];
            $link = 'passport_transactions.php';
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    branch_id, agent_id, title, message, link, type, 
                    source_type, source_id, created_by
                ) VALUES (?, ?, ?, ?, ?, 'warning', ?, ?, ?)
            ");
            $stmt->execute([
                $trx['branch_id'],
                $trx['agent_id'],
                $title,
                $message,
                $link,
                'passport_travel',
                $trx['id'],
                $currentUser['id']
            ]);
        }
    }
}

// تشغيل دالة التحقق من الإشعارات
checkUpcomingTravelNotifications($pdo, $currentUser);

// أحدث العناصر للإشعارات
$recent_internal = $pdo->prepare("
    SELECT im.message, im.created_at, u.full_name, u.username
    FROM internal_messages im
    JOIN users u ON im.sender_id = u.id
    WHERE im.receiver_id = ? AND im.is_read = 0
    ORDER BY im.created_at DESC
    LIMIT 5
");
$recent_internal->execute([$admin_id]);
$recent_internal_items = $recent_internal->fetchAll();

$recent_contact = $pdo->query("
    SELECT name, subject, created_at
    FROM contact_messages
    WHERE is_read = 0
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll();

$recent_subs = $pdo->query("
    SELECT email, created_at
    FROM subscribers
    WHERE is_read = 0
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll();

// جلب عدد المعاملات بانتظار الترحيل المالي (النظام المالي الموحد)
try {
    $pending_posting_count = $pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_status = 'draft'")->fetchColumn();
} catch (PDOException $e) {
    $pending_posting_count = 0;
}

// جلب الإشعارات غير المقروءة
$notif_count = 0;
$recent_notifs = [];
if (isset($_SESSION['admin_id'])) {
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    $user_role = $_SESSION['role'] ?? null;

    // بناء استعلام محسن للإشعارات
    $where_conditions = [];
    $params = [];

    if ($user_id) {
        $where_conditions[] = "user_id = ?";
        $params[] = $user_id;
    }

    if ($user_role) {
        $where_conditions[] = "role_id = ?";
        $params[] = $user_role;
    }

    if ($agent_id) {
        $where_conditions[] = "agent_id = ?";
        $params[] = $agent_id;
    }

    if ($branch_id) {
        $where_conditions[] = "branch_id = ?";
        $params[] = $branch_id;
    }

    // إذا لم يكن هناك شروط، أظهر جميع الإشعارات (للمدراء)
    if (empty($where_conditions)) {
        $where_clause = "1=1";
        $params = [];
    } else {
        $where_clause = implode(" OR ", $where_conditions);
    }

    $stmt_n = $pdo->prepare("SELECT * FROM notifications WHERE ($where_clause) AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
    $stmt_n->execute($params);
    $recent_notifs = $stmt_n->fetchAll();

    $stmt_nc = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE ($where_clause) AND is_read = 0");
    $stmt_nc->execute($params);
    $notif_count = $stmt_nc->fetchColumn();

    // إضافة عدد المشاكل غير المحلولة في تأشيرات العمل
    try {
        $stmt_unresolved = $pdo->prepare("SELECT COUNT(*) FROM passports WHERE (agent_id = ? OR branch_id = ?) AND is_resolved = 0");
        $stmt_unresolved->execute([$agent_id, $branch_id]);
        $unresolved_count = $stmt_unresolved->fetchColumn();
    } catch (PDOException $e) {
        $unresolved_count = 0;
    }

    // تنبيهات انتهاء التأشيرة
    $expiry_alerts = [];
    $expiry_alert_days = $settings['work_visa_expiry_alert_days'] ?? 5;

    // جلب التأشيرات التي ستنتهي قريباً (ولم تسلم للعميل بعد)
    $stmt_expiry = $pdo->prepare("
        SELECT id, full_name, passport_number, visa_expiry_date,
        DATEDIFF(visa_expiry_date, CURDATE()) as days_left
        FROM passports
        WHERE (agent_id = ? OR branch_id = ?)
        AND status_id NOT IN (5, 14, 19)
        AND visa_expiry_date IS NOT NULL
        AND DATEDIFF(visa_expiry_date, CURDATE()) <= ?
        AND DATEDIFF(visa_expiry_date, CURDATE()) >= 0
        ORDER BY visa_expiry_date ASC
    ");
    $stmt_expiry->execute([$agent_id, $branch_id, $expiry_alert_days]);
    $expiry_alerts = $stmt_expiry->fetchAll();
    $expiry_count = count($expiry_alerts);

    $total_alert_count = $notif_count + $unresolved_count + $expiry_count + $unread_messages + $new_subscribers + $unread_internal_count;
}
// جلب عدد طلبات الاعتماد المعلقة للمدراء
$pending_approvals_count = 0;
if ($is_admin) {
    $stmt_app_count = $pdo->query("SELECT COUNT(*) FROM workflow_approval_requests WHERE status = 'pending'");
    $pending_approvals_count = $stmt_app_count->fetchColumn();
}

$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/admin/index.php');
$admin_pos = strpos($script_name, '/admin/');
$admin_base_url = $admin_pos !== false ? substr($script_name, 0, $admin_pos + 7) : '/admin/';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم | <?php echo $settings['site_name']; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/icon-fallback.css?v=20260617">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="chat.css">
    <link rel="stylesheet" href="assets/css/theme.css.php">
    <style>
        /* Select2 dark mode styles */
        body.theme-dark .select2-container--default .select2-selection--single,
        body.dark-mode .select2-container--default .select2-selection--single,
        body.theme-dark .select2-container--default .select2-selection--multiple,
        body.dark-mode .select2-container--default .select2-selection--multiple {
            background-color: #0f1e35 !important;
            border-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }
        body.theme-dark .select2-container--default .select2-selection--single .select2-selection__rendered,
        body.dark-mode .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #e2e8f0 !important;
        }
        body.theme-dark .select2-dropdown,
        body.dark-mode .select2-dropdown {
            background-color: #111827 !important;
            border-color: #1e2d45 !important;
        }
        body.theme-dark .select2-results__option,
        body.dark-mode .select2-results__option {
            color: #e2e8f0 !important;
        }
        body.theme-dark .select2-results__option--highlighted,
        body.dark-mode .select2-results__option--highlighted {
            background-color: #1e2d45 !important;
        }
        body.theme-dark .select2-search--dropdown .select2-search__field,
        body.dark-mode .select2-search--dropdown .select2-search__field {
            background-color: #0f1e35 !important;
            color: #e2e8f0 !important;
            border-color: #1e2d45 !important;
        }
        body.theme-dark .select2-container--default .select2-selection--single .select2-selection__arrow,
        body.dark-mode .select2-container--default .select2-selection--single .select2-selection__arrow {
            border-left-color: #1e2d45 !important;
        }
        /* Input group text dark mode styles */
        body.theme-dark .input-group-text,
        body.dark-mode .input-group-text {
            background-color: #0f1e35 !important;
            border-color: #1e2d45 !important;
            color: #94a3b8 !important;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/unified-design.css?v=20260630">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script src="assets/js/tafqeet.js"></script>
    <script>
        (function() {
            function enableIconFallback() {
                var cssReady = false;
                var fontReady = false;
                try {
                    var probe = document.createElement('i');
                    probe.className = 'fas fa-check';
                    probe.style.position = 'absolute';
                    probe.style.opacity = '0';
                    probe.style.pointerEvents = 'none';
                    document.body.appendChild(probe);
                    var content = window.getComputedStyle(probe, '::before').getPropertyValue('content');
                    cssReady = !!content && content !== 'none' && content !== 'normal' && content !== '""';
                    probe.remove();
                } catch (e) {}
                try {
                    fontReady = document.fonts && (
                        document.fonts.check('900 1em "Font Awesome 6 Free"') ||
                        document.fonts.check('900 1em "Font Awesome 5 Free"') ||
                        document.fonts.check('400 1em "Font Awesome 6 Brands"') ||
                        document.fonts.check('400 1em "Font Awesome 5 Brands"')
                    );
                } catch (e) {}
                document.documentElement.classList.toggle('fa-fallback', !cssReady || !fontReady);
            }
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(enableIconFallback);
            }
            document.addEventListener('DOMContentLoaded', enableIconFallback);
            window.addEventListener('load', enableIconFallback);
            setTimeout(enableIconFallback, 350);
            setTimeout(enableIconFallback, 1200);
        })();
    </script>
    <style>
        :root {
            --sidebar-width: 260px;
        }

        body {
            overflow-y: auto;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            right: 0;
            color: var(--sidebar-text, #334155);
            z-index: 1050;
            /* رفع القيمة لضمان الظهور فوق الـ overlay */
            transition: transform 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
            background: var(--sidebar-bg, #ffffff) !important;
            border-left: 1px solid var(--sidebar-border, #e2e8f0);
            box-shadow: -1px 0 10px rgba(15, 23, 42, 0.06);
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: #f8fafc;
        }

        .main-wrapper {
            margin-right: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            position: relative;
        }

        .main-wrapper.sidebar-collapsed {
            margin-right: 0 !important;
        }

        .sidebar.collapsed {
            transform: translateX(100%);
        }

        .content-body {
            flex: 1;
            padding: 20px;
            width: 100%;
        }

        .top-navbar {
            background: var(--card-bg);
            padding: 10px 25px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1001;
            border-bottom: 1px solid var(--border-color);
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .icon-btn {
            position: relative;
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            padding: 0;
        }

        .icon-btn:hover {
            background: var(--card-bg);
            color: var(--primary-color);
            border-color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.1);
        }

        .icon-btn i {
            font-size: 1.05rem;
        }

        .icon-badge {
            position: absolute;
            top: -5px;
            left: -5px;
            background: #ef4444;
            color: #fff;
            border-radius: 50px;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            font-size: 0.62rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
            z-index: 2;
        }

        .visit-site-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 10px;
            background: #f8fafc;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
        }

        .visit-site-btn:hover {
            background: var(--primary-color);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.15);
        }

        .apps-menu {
            min-width: 320px;
            border-radius: 12px !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08) !important;
            padding: 15px !important;
            background: #ffffff !important;
        }

        .app-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 10px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s;
            height: 100%;
        }

        .app-item i {
            font-size: 1.4rem;
            color: #2563eb;
        }

        .app-item span {
            font-size: 0.75rem;
            font-weight: 600;
        }

        .app-item:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            color: #2563eb;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1025;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            transition: opacity 0.3s ease;
        }

        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(100%);
                right: 0;
                z-index: 1050;
                -webkit-overflow-scrolling: touch;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-right: 0 !important;
            }

            .sidebar-overlay.show {
                display: block !important;
            }

            #sidebarToggle {
                display: block !important;
            }

            .top-navbar {
                padding: 8px 12px;
            }

            .top-actions {
                gap: 6px;
            }

            .icon-btn {
                width: 34px;
                height: 34px;
                border-radius: 8px;
            }

            .icon-btn i {
                font-size: 0.9rem;
            }

            #sidebarToggle {
                width: 38px;
                height: 38px;
                display: flex !important;
                align-items: center;
                justify-content: center;
                border-radius: 10px;
                border: 1px solid #e2e8f0;
                background: #fff;
                color: #1e293b;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            }

            @media (max-width: 575.98px) {

                .top-actions .icon-btn[title*="ملء الشاشة"],
                .top-actions .icon-btn[title*="الوصول السريع"],
                .top-actions .icon-btn[title*="بحث"] {
                    display: none;
                }
            }
        }

        @media (min-width: 992px) {
            #sidebarToggle {
                display: none !important;
            }
        }

        .sidebar-header {
            padding: 22px 20px 18px;
            text-align: center;
            background: rgba(var(--primary-rgb, 29, 61, 188), 0.05);
            border-bottom: 1px solid var(--sidebar-border, #e2e8f0);
            position: relative;
        }

        .sidebar-header::after {
            display: none;
        }

        .sidebar-logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 10px;
            box-shadow: none;
        }

        .sidebar-header h5 {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 2px;
            color: #0f172a;
            letter-spacing: 0;
        }

        .sidebar-header small {
            font-size: 0.75rem;
            color: #64748b;
        }

        .user-panel {
            padding: 14px 16px;
            margin: 12px 10px 8px;
            background: rgba(var(--primary-rgb, 29, 61, 188), 0.03);
            border: 1px solid var(--sidebar-border, #e2e8f0);
            border-radius: 12px;
            transition: 0.2s;
        }

        .user-panel:hover {
            background: #eef2f6;
        }

        .user-panel-inner {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #cbd5e1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            flex-shrink: 0;
            box-shadow: none;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-meta {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .user-name {
            color: #0f172a;
            font-weight: 700;
            font-size: 0.92rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role-pill {
            display: inline-block;
            margin-top: 5px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.72rem;
            background: #e2e8f0;
            color: #334155;
            border: none;
            font-weight: 600;
        }

        .sidebar-menu {
            padding: 8px 10px 20px;
        }

        .sidebar-section-label {
            padding: 14px 10px 6px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #475569;
        }

        .sidebar-menu a {
            color: var(--sidebar-text, #334155) !important;
            text-decoration: none;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background-color 0.15s ease, color 0.15s ease;
            position: relative;
            border-radius: 10px;
            margin-bottom: 4px;
            font-size: 0.9rem;
            font-weight: 500;
            background: transparent;
        }

        body.theme-dark .sidebar-menu a,
        body.dark-mode .sidebar-menu a {
            color: var(--sidebar-text, #f1f5f9) !important;
        }

        .sidebar-menu a .menu-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            background: var(--sidebar-icon-bg, #e2e8f0);
            color: var(--sidebar-icon, #2563eb) !important;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        body.theme-dark .sidebar-menu a .menu-icon,
        body.dark-mode .sidebar-menu a .menu-icon {
            background: rgba(255, 255, 255, 0.1);
            color: var(--primary-color, #3b82f6) !important;
        }

        .sidebar-menu a:hover {
            color: #0f172a;
            background: #f1f5f9;
        }

        .sidebar-menu a:hover .menu-icon {
            background: #dbeafe;
            color: #2563eb;
        }

        .sidebar-menu a.active {
            color: #0f172a;
            background: #e2e8f0;
            border: 1px solid #cbd5e1;
        }

        .sidebar-menu a.active .menu-icon {
            background: #2563eb;
            color: #fff;
        }

        .sidebar-menu a.active::before {
            display: none;
        }

        .sidebar-menu a.text-danger {
            color: #f87171 !important;
        }

        .sidebar-menu a.text-danger .menu-icon {
            background: rgba(248, 113, 113, 0.1);
            color: #f87171;
        }

        .sidebar-menu a.text-danger:hover {
            background: rgba(248, 113, 113, 0.1);
        }

        .sidebar-menu hr {
            border-color: rgba(255, 255, 255, 0.06);
            margin: 8px 5px;
        }

        .badge-notify {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.65rem;
            padding: 2px 7px;
            border-radius: 50px;
            font-weight: 700;
            background: #ef4444;
            color: #fff;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #f0f0f0;
            padding: 15px 20px;
            font-weight: bold;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 6px;
        }

        body.theme-dark {
            background-color: #0b1120 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .main-wrapper {
            background-color: #0b1120 !important;
        }

        body.theme-dark .content-body {
            background-color: #0b1120 !important;
        }

        body.theme-dark .top-navbar {
            background: #0b1120 !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.6);
            border-bottom: 1px solid #1e2d45;
        }

        body.theme-dark .top-navbar .icon-btn {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        body.theme-dark .top-navbar .icon-btn:hover {
            background: #334155;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        body.theme-dark .visit-site-btn {
            background: #1e293b;
            color: #f1f5f9;
            border-color: #334155;
        }

        body.theme-dark .visit-site-btn:hover {
            background: var(--primary-color);
            color: #fff;
            border-color: var(--primary-color);
        }

        body.theme-dark .apps-menu {
            background: #0f172a !important;
        }

        body.theme-dark .app-item {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        body.theme-dark .app-item:hover {
            background: #334155;
            border-color: var(--primary-color);
            color: #fff;
        }

        body.theme-dark .dropdown-menu {
            background: #0f172a !important;
            border: 1px solid #1e293b !important;
        }

        body.theme-dark .list-group-item {
            background: transparent !important;
            color: #f1f5f9 !important;
        }

        body.theme-dark .list-group-item-action:hover {
            background: #1e293b !important;
        }

        body.theme-dark .text-dark {
            color: #f1f5f9 !important;
        }

        /* الكروت */
        body.theme-dark .card {
            background: #111827 !important;
            border-color: #1e2d45 !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.5);
            color: #e2e8f0 !important;
        }

        body.theme-dark .card-header {
            background: #0f1e35 !important;
            border-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .card-body {
            background: #111827 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .card-footer {
            background: #0f1e35 !important;
            border-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .card-title,
        body.theme-dark .card-text {
            color: #e2e8f0 !important;
        }

        /* الشريط الجانبي - dark mode: استخدم نفس ألوان المتغيرات للمظهر المتناسق */
        body.theme-dark .sidebar {
            background-color: var(--sidebar-bg) !important;
            background-image: none !important;
            border-right-color: var(--sidebar-border) !important;
        }

        body.theme-dark .sidebar-header {
            background: rgba(0, 0, 0, 0.18) !important;
        }

        body.theme-dark .user-panel {
            background: rgba(255, 255, 255, 0.03) !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        body.theme-dark .sidebar-menu a .menu-icon {
            background: rgba(255, 255, 255, 0.04) !important;
        }

        body.theme-dark .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.05) !important;
        }

        body.theme-dark .sidebar-menu a:hover .menu-icon {
            background: color-mix(in srgb, var(--primary-color) 18%, transparent) !important;
            color: var(--primary-color) !important;
        }

        body.theme-dark .sidebar-menu a.active {
            background: linear-gradient(90deg, color-mix(in srgb, var(--primary-color) 18%, transparent), color-mix(in srgb, var(--primary-color) 6%, transparent)) !important;
            border-color: color-mix(in srgb, var(--primary-color) 25%, transparent) !important;
        }

        /* الجداول */
        body.theme-dark table,
        body.theme-dark .table {
            color: #e2e8f0 !important;
            border-color: #1e2d45 !important;
            background-color: #111827 !important;
        }

        body.theme-dark .table> :not(caption)>*>* {
            background-color: #111827 !important;
            color: #e2e8f0 !important;
            border-color: #1e2d45 !important;
        }

        body.theme-dark .table thead th,
        body.theme-dark .table thead tr {
            background-color: #0f1e35 !important;
            color: #94a3b8 !important;
            border-color: #1e2d45 !important;
        }

        body.theme-dark .table-striped>tbody>tr:nth-of-type(odd)>* {
            background-color: #0f1e35 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .table-hover>tbody>tr:hover>* {
            background-color: #162032 !important;
            color: #ffffff !important;
        }

        body.theme-dark td,
        body.theme-dark th {
            border-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .table-bordered,
        body.theme-dark .table-bordered td,
        body.theme-dark .table-bordered th {
            border-color: #1e2d45 !important;
        }

        /* النماذج */
        body.theme-dark .form-control,
        body.theme-dark .form-select,
        body.theme-dark input[type="text"],
        body.theme-dark input[type="email"],
        body.theme-dark input[type="password"],
        body.theme-dark input[type="number"],
        body.theme-dark input[type="search"],
        body.theme-dark input[type="date"],
        body.theme-dark select,
        body.theme-dark textarea {
            background-color: #0f1e35 !important;
            color: #e2e8f0 !important;
            border-color: #1e2d45 !important;
        }

        body.theme-dark .form-control:focus,
        body.theme-dark .form-select:focus,
        body.theme-dark input:focus,
        body.theme-dark textarea:focus {
            background-color: #0f1e35 !important;
            color: #e2e8f0 !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.16rem color-mix(in srgb, var(--primary-color) 25%, transparent) !important;
        }

        body.theme-dark ::placeholder {
            color: #4b5563 !important;
        }

        body.theme-dark label,
        body.theme-dark .form-label {
            color: #cbd5e1 !important;
        }

        body.theme-dark .input-group-text {
            background-color: #0f1e35 !important;
            border-color: #1e2d45 !important;
            color: #94a3b8 !important;
        }

        /* الأزرار */
        body.theme-dark .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: #fff !important;
        }

        body.theme-dark .btn-primary:hover {
            filter: brightness(1.1);
        }

        body.theme-dark .btn-secondary {
            background-color: #1e2d45 !important;
            border-color: #2d3f5c !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .btn-light {
            background-color: #1e2d45 !important;
            border-color: #2d3f5c !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .btn-light:hover {
            background-color: #2d3f5c !important;
        }

        body.theme-dark .btn-outline-primary {
            color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }

        body.theme-dark .btn-outline-primary:hover {
            background-color: var(--primary-color) !important;
            color: #fff !important;
        }

        body.theme-dark .btn-outline-success {
            color: #4ade80 !important;
            border-color: #4ade80 !important;
        }

        body.theme-dark .btn-outline-success:hover {
            background-color: #4ade80 !important;
            color: #020617 !important;
        }

        body.theme-dark .btn-outline-danger {
            color: #f87171 !important;
            border-color: #f87171 !important;
        }

        body.theme-dark .btn-outline-danger:hover {
            background-color: #f87171 !important;
            color: #020617 !important;
        }

        body.theme-dark .btn-outline-info {
            color: #38bdf8 !important;
            border-color: #38bdf8 !important;
        }

        body.theme-dark .btn-outline-info:hover {
            background-color: #38bdf8 !important;
            color: #020617 !important;
        }

        body.theme-dark .btn-outline-warning {
            color: #fbbf24 !important;
            border-color: #fbbf24 !important;
        }

        body.theme-dark .btn-outline-warning:hover {
            background-color: #fbbf24 !important;
            color: #020617 !important;
        }

        body.theme-dark .btn-outline-secondary {
            color: #94a3b8 !important;
            border-color: #2d3f5c !important;
        }

        body.theme-dark .btn-outline-secondary:hover {
            background-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .btn-close {
            filter: invert(1);
        }

        /* المودال */
        body.theme-dark .modal-content {
            background-color: #111827 !important;
            border-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .modal-header {
            background-color: #0f1e35 !important;
            border-bottom-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .modal-footer {
            background-color: #0f1e35 !important;
            border-top-color: #1e2d45 !important;
        }

        body.theme-dark .modal-title,
        body.theme-dark .modal-body {
            color: #e2e8f0 !important;
        }

        /* القوائم */
        body.theme-dark .list-group-item {
            background-color: #111827 !important;
            border-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .list-group-item-action:hover {
            background-color: #1e2d45 !important;
            color: var(--primary-color) !important;
        }

        body.theme-dark .list-group-item.active {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: #fff !important;
        }

        /* القائمة المنسدلة */
        body.theme-dark .dropdown-menu {
            background-color: #111827 !important;
            border-color: #1e2d45 !important;
        }

        body.theme-dark .dropdown-item {
            color: #e2e8f0 !important;
        }

        body.theme-dark .dropdown-item:hover {
            background-color: #1e2d45 !important;
            color: var(--primary-color) !important;
        }

        body.theme-dark .dropdown-divider {
            border-color: #1e2d45 !important;
        }

        /* Alerts */
        body.theme-dark .alert {
            border-color: #1e2d45 !important;
        }

        body.theme-dark .alert-success {
            background-color: rgba(74, 222, 128, 0.15) !important;
            color: #4ade80 !important;
            border-color: rgba(74, 222, 128, 0.3) !important;
        }

        body.theme-dark .alert-danger {
            background-color: rgba(248, 113, 113, 0.15) !important;
            color: #f87171 !important;
            border-color: rgba(248, 113, 113, 0.3) !important;
        }

        body.theme-dark .alert-warning {
            background-color: rgba(251, 191, 36, 0.15) !important;
            color: #fbbf24 !important;
            border-color: rgba(251, 191, 36, 0.3) !important;
        }

        body.theme-dark .alert-info {
            background-color: rgba(56, 189, 248, 0.15) !important;
            color: #38bdf8 !important;
            border-color: rgba(56, 189, 248, 0.3) !important;
        }

        body.theme-dark .alert-primary {
            background-color: color-mix(in srgb, var(--primary-color) 15%, transparent) !important;
            color: var(--primary-color) !important;
            border-color: color-mix(in srgb, var(--primary-color) 30%, transparent) !important;
        }

        body.theme-dark .alert-secondary {
            background-color: #1e2d45 !important;
            color: #94a3b8 !important;
        }

        body.theme-dark .alert-light {
            background-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        /* Badges والخلفيات */
        body.theme-dark .bg-light {
            background-color: #111827 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .bg-white {
            background-color: #111827 !important;
            color: #e2e8f0 !important;
        }

        body.theme-dark .bg-body {
            background-color: #0b1120 !important;
        }

        body.theme-dark .badge.bg-light {
            background-color: #1e2d45 !important;
            color: #e2e8f0 !important;
        }

        /* الخطوط */
        body.theme-dark h1,
        body.theme-dark h2,
        body.theme-dark h3,
        body.theme-dark h4,
        body.theme-dark h5,
        body.theme-dark h6 {
            color: #f1f5f9 !important;
        }

        body.theme-dark p {
            color: #cbd5e1;
        }

        body.theme-dark .text-dark {
            color: #e2e8f0 !important;
        }

        body.theme-dark .text-muted {
            color: #64748b !important;
        }

        body.theme-dark .text-body {
            color: #e2e8f0 !important;
        }

        body.theme-dark strong,
        body.theme-dark b {
            color: #f1f5f9;
        }

        body.theme-dark small {
            color: #64748b !important;
        }

        /* HR والحدود */
        body.theme-dark hr {
            border-color: #1e2d45 !important;
            opacity: 0.5;
        }

        body.theme-dark .border {
            border-color: #1e2d45 !important;
        }

        /* Pagination */
        body.theme-dark .page-item .page-link {
            background-color: #111827 !important;
            border-color: #1e2d45 !important;
            color: #cbd5e1 !important;
        }

        body.theme-dark .page-item .page-link:hover {
            background-color: #1e2d45 !important;
            color: var(--primary-color) !important;
        }

        body.theme-dark .page-item.active .page-link {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: #fff !important;
        }

        body.theme-dark .page-item.disabled .page-link {
            background-color: #0f1e35 !important;
            border-color: #1e2d45 !important;
            color: #4b5563 !important;
        }

        /* Tabs */
        body.theme-dark .nav-tabs {
            border-bottom-color: #1e2d45 !important;
        }

        body.theme-dark .nav-tabs .nav-link {
            color: #94a3b8 !important;
            border-color: transparent !important;
        }

        body.theme-dark .nav-tabs .nav-link.active {
            background-color: #111827 !important;
            border-color: #1e2d45 #1e2d45 #111827 !important;
            color: var(--primary-color) !important;
        }

        body.theme-dark .tab-content {
            background-color: #111827;
            color: #e2e8f0;
        }

        body.theme-dark .nav-pills .nav-link {
            color: #94a3b8 !important;
        }

        body.theme-dark .nav-pills .nav-link.active {
            background-color: var(--primary-color) !important;
            color: #fff !important;
        }

        /* Progress */
        body.theme-dark .progress {
            background-color: #1e2d45 !important;
        }

        body.theme-dark .progress-bar {
            background-color: var(--primary-color) !important;
        }

        /* Accordion */
        body.theme-dark .accordion-item {
            background-color: #111827 !important;
            border-color: #1e2d45 !important;
        }

        body.theme-dark .accordion-button {
            background-color: #0f1e35 !important;
            color: #e2e8f0 !important;
            box-shadow: none !important;
        }

        body.theme-dark .accordion-button:not(.collapsed) {
            background-color: #162032 !important;
            color: var(--primary-color) !important;
        }

        body.theme-dark .accordion-body {
            background-color: #111827 !important;
            color: #cbd5e1 !important;
        }

        /* Spinner */
        body.theme-dark .spinner-border {
            color: var(--primary-color) !important;
        }

        /* Dropdown Submenu Sidebar */
        .sidebar-menu .collapse a {
            padding-right: 45px;
            font-size: 0.82rem;
            margin-bottom: 1px;
        }

        .sidebar-menu .collapse-toggle::after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-right: auto;
            transition: transform 0.3s;
            font-size: 0.8rem;
            opacity: 0.5;
        }

        .sidebar-menu .collapse-toggle:not(.collapsed)::after {
            transform: rotate(180deg);
        }

        body,
        .top-navbar,
        .card,
        .sidebar,
        .form-control,
        .btn,
        .modal-content,
        .table,
        .list-group-item {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
    </style>

    <script>
        const ADMIN_BASE_URL = <?php echo json_encode($admin_base_url, JSON_UNESCAPED_SLASHES); ?>;

        function adminUrl(path) {
            if (!path || path === '#') return path;
            if (/^(?:[a-z]+:)?\/\//i.test(path) || path.startsWith('/') || path.startsWith('mailto:') || path.startsWith('tel:')) {
                return path;
            }
            return ADMIN_BASE_URL + path.replace(/^\.?\//, '');
        }

        function normalizeAdminNavigationLinks(root = document) {
            root.querySelectorAll('.sidebar-menu a[href], #quickSearchResults a[href], .apps-menu a[href]').forEach(link => {
                const href = link.getAttribute('href');
                link.setAttribute('href', adminUrl(href));
            });
        }

        function toggleSidebarMenu(event) {
            if (event) {
                // Only prevent default if the clicked element is NOT a link
                if (!event.target.closest('a')) {
                    event.preventDefault();
                }
                event.stopPropagation();
            }
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainWrapper = document.querySelector('.main-wrapper');

            if (window.innerWidth >= 992) {
                // التحكم في الديسك توب
                sidebar.classList.toggle('collapsed');
                mainWrapper.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
            } else {
                // التحكم في الموبايل
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
                if (sidebar.classList.contains('show')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            }
            return false;
        }

        // استعادة حالة القائمة عند التحميل
        document.addEventListener('DOMContentLoaded', function() {
            normalizeAdminNavigationLinks();

            const sidebar = document.getElementById('sidebar');
            const mainWrapper = document.querySelector('.main-wrapper');
            const isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';

            if (isCollapsed && window.innerWidth >= 992) {
                sidebar.classList.add('collapsed');
                mainWrapper.classList.add('sidebar-collapsed');
            }

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('hide_sidebar')) {
                if (sidebar && mainWrapper && window.innerWidth >= 992) {
                    sidebar.classList.add('collapsed');
                    mainWrapper.classList.add('sidebar-collapsed');
                }
            }
        });
    </script>
    <script>
        function toggleFullScreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
                localStorage.setItem('admin_fullscreen', '1');
                const icon = document.getElementById('fullscreenIcon');
                if (icon) {
                    icon.classList.replace('fa-expand', 'fa-compress');
                }
            } else {
                document.exitFullscreen();
                localStorage.setItem('admin_fullscreen', '0');
                const icon = document.getElementById('fullscreenIcon');
                if (icon) {
                    icon.classList.replace('fa-compress', 'fa-expand');
                }
            }
        }
        // استعادة أيقونة ملء الشاشة عند تغيير الصفحة (لا يمكن طلب ملء الشاشة تلقائياً بدون تفاعل المستخدم)
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('admin_fullscreen') === '1') {
                const icon = document.getElementById('fullscreenIcon');
                if (icon) {
                    icon.classList.replace('fa-expand', 'fa-compress');
                }

                // إضافة مستمع للنقر الأول لتفعيل ملء الشاشة إذا كان مخزناً في localStorage
                const activateFS = () => {
                    if (localStorage.getItem('admin_fullscreen') === '1' && !document.fullscreenElement) {
                        document.documentElement.requestFullscreen().catch(() => {});
                    }
                    document.removeEventListener('click', activateFS);
                };
                document.addEventListener('click', activateFS);
            }
        });
        // متابعة حالة الـ fullscreen إذا أُغلق بمفتاح Esc
        document.addEventListener('fullscreenchange', function() {
            if (!document.fullscreenElement) {
                localStorage.setItem('admin_fullscreen', '0');
                const icon = document.getElementById('fullscreenIcon');
                if (icon) {
                    icon.classList.replace('fa-compress', 'fa-expand');
                }
            }
        });

        function applyThemeFromStorage() {
            const cookieTheme = (document.cookie.match(/(?:^|;\s*)theme=([^;]+)/) || [])[1];
            const mode = localStorage.getItem('admin_theme') || localStorage.getItem('theme') || (cookieTheme ? decodeURIComponent(cookieTheme) : '') || '<?php echo $user_theme; ?>';
            if (mode === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.body.classList.toggle('theme-dark', prefersDark);
                document.body.classList.toggle('dark-mode', prefersDark);
                document.documentElement.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
            } else {
                document.body.classList.toggle('theme-dark', mode === 'dark');
                document.body.classList.toggle('dark-mode', mode === 'dark');
                document.documentElement.setAttribute('data-bs-theme', mode === 'dark' ? 'dark' : 'light');
            }

            const icon = document.getElementById('adminThemeIcon');
            if (icon) {
                const isDark = document.body.classList.contains('theme-dark');
                if (isDark) {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                } else {
                    icon.classList.remove('fa-sun');
                    icon.classList.add('fa-moon');
                }
            }
        }

        function openQuickSearch() {
            const modalEl = document.getElementById('quickSearchModal');
            const inputEl = document.getElementById('quickSearchInput');
            const resultsEl = document.getElementById('quickSearchResults');
            const items = [{
                    label: 'المراسلة الداخلية',
                    href: 'internal_messages.php'
                },
                {
                    label: 'الرسائل',
                    href: 'messages.php'
                },
                {
                    label: 'الدفعات',
                    href: 'batches.php'
                },
                {
                    label: 'الجوازات',
                    href: 'passports.php'
                },
                {
                    label: 'الأخبار',
                    href: 'news.php'
                },
                {
                    label: 'الصفحات',
                    href: 'content.php'
                },
                {
                    label: 'المستخدمون',
                    href: 'users.php'
                },
                {
                    label: 'الإعدادات',
                    href: 'settings.php'
                },
                {
                    label: 'الملف الشخصي',
                    href: 'profile.php'
                }
            ];
            resultsEl.innerHTML = items.map(i => `<a class="list-group-item list-group-item-action" href="${i.href}">${i.label}</a>`).join('');
            normalizeAdminNavigationLinks(resultsEl);
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            setTimeout(() => inputEl.focus(), 200);
            inputEl.oninput = function() {
                const q = this.value.trim();
                const filtered = items.filter(i => i.label.includes(q));
                resultsEl.innerHTML = filtered.map(i => `<a class="list-group-item list-group-item-action" href="${i.href}">${i.label}</a>`).join('');
                normalizeAdminNavigationLinks(resultsEl);
            };
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'f' || e.key === 'F') {
                toggleFullScreen();
            }
            if ((e.key === 'k' || e.key === 'K') && (e.altKey || e.ctrlKey)) {
                e.preventDefault();
                openQuickSearch();
            }
        });
        // تحديث شارة الرسائل غير المقروءة لتختفي بعد القراءة
        function refreshTopMessagesBadge() {
            fetch('internal_messages.php?action=get_unread_counts')
                .then(res => res.json())
                .then(data => {
                    const badge = document.getElementById('topMessagesBadge');
                    const total = data && data.counts ? Object.values(data.counts).reduce((a, b) => a + parseInt(b || 0, 10), 0) : 0;
                    if (badge) {
                        if (total > 0) {
                            badge.textContent = total;
                            badge.style.display = 'inline-block';
                        } else {
                            badge.style.display = 'none';
                        }
                    } else if (total > 0) {
                        const anchor = document.querySelector('.top-actions a[title="الرسائل"]');
                        if (anchor) {
                            const span = document.createElement('span');
                            span.className = 'icon-badge';
                            span.id = 'topMessagesBadge';
                            span.textContent = total;
                            anchor.appendChild(span);
                        }
                    }
                    // تحديث شارة الرسائل في الشريط الجانبي (الأيقونة داخل لوحة المستخدم)
                    const sideBadge = document.getElementById('sideMessagesBadge');
                    if (sideBadge) {
                        if (total > 0) {
                            sideBadge.textContent = total;
                            sideBadge.style.display = 'inline-block';
                        } else {
                            sideBadge.style.display = 'none';
                        }
                    }
                    // تحديث شارة عنصر القائمة "المراسلة الداخلية"
                    const sideMenuLink = Array.from(document.querySelectorAll('.sidebar-menu a')).find(a => (a.getAttribute('href') || '').endsWith('/internal_messages.php') || a.getAttribute('href') === 'internal_messages.php');
                    if (sideMenuLink) {
                        let menuBadge = sideMenuLink.querySelector('.badge-notify');
                        if (total > 0) {
                            if (!menuBadge) {
                                menuBadge = document.createElement('span');
                                menuBadge.className = 'badge bg-danger badge-notify';
                                sideMenuLink.appendChild(menuBadge);
                            }
                            menuBadge.textContent = total;
                            menuBadge.style.display = 'inline-block';
                        } else if (menuBadge) {
                            menuBadge.style.display = 'none';
                        }
                    }
                }).catch(() => {});
        }

        function markAllNotifsRead() {
            fetch('ajax_work_visa.php?action=mark_notifs_read')
                .then(res => res.json())
                .then(res => {
                    if (res.status === 'success') {
                        const badge = document.getElementById('mainNotifBadge');
                        if (badge) badge.style.display = 'none';
                        const list = document.getElementById('headerNotifList');
                        const workVisaSection = list.querySelector('.text-warning');
                        if (workVisaSection) {
                            workVisaSection.nextElementSibling?.remove(); // remove first notif
                            workVisaSection.remove();
                        }
                    }
                });
        }

        function markNotifRead(notifId, element) {
            fetch('ajax_work_visa.php?action=mark_single_notif_read&notif_id=' + notifId)
                .then(res => res.json())
                .then(res => {
                    if (res.status === 'success') {
                        // إزالة الإشعار من القائمة
                        element.remove();
                        // تحديث الشارة
                        const badge = document.getElementById('mainNotifBadge');
                        if (badge) {
                            const currentCount = parseInt(badge.textContent) || 0;
                            if (currentCount > 1) {
                                badge.textContent = currentCount - 1;
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    }
                })
                .catch(() => {
                    // في حال فشل، نتابع إلى الرابط
                });
        }

        // وظائف الأصوات
        function playNotificationSound() {
            if (<?php echo ($settings['enable_notification_sound'] ?? 1) ? 'true' : 'false'; ?>) {
                const audio = new Audio('https://www.soundjay.com/misc/sounds/bell-ringing-05.wav');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            }
        }

        function playMessageSound() {
            if (<?php echo ($settings['enable_message_sound'] ?? 1) ? 'true' : 'false'; ?>) {
                const audio = new Audio('https://www.soundjay.com/misc/sounds/message-sound.wav');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            applyThemeFromStorage();
            refreshTopMessagesBadge();
            setInterval(refreshTopMessagesBadge, 5000);
        });
    </script>
    <style>
        .modal-body {
            max-height: 80vh;
            overflow-y: auto;
        }

        .app-toast.toast {
            min-width: 330px;
            max-width: 420px;
            border-radius: 22px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.16) !important;
            background: rgba(255, 255, 255, 0.96) !important;
            color: #0f172a !important;
            backdrop-filter: blur(16px);
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.18);
            opacity: 1;
        }

        .app-toast .toast-body {
            padding: 0.95rem 1rem;
        }

        .app-toast .btn-close {
            opacity: 0.78;
            filter: none;
        }

        .app-toast[data-toast-type="success"] {
            border-inline-start: 4px solid #10b981 !important;
        }

        .app-toast[data-toast-type="danger"] {
            border-inline-start: 4px solid #ef4444 !important;
        }

        .app-toast[data-toast-type="warning"] {
            border-inline-start: 4px solid #f59e0b !important;
        }

        .app-toast[data-toast-type="info"] {
            border-inline-start: 4px solid #3b82f6 !important;
        }

        .app-toast[data-toast-type="success"] #toastIcon {
            color: #10b981;
        }

        .app-toast[data-toast-type="danger"] #toastIcon {
            color: #ef4444;
        }

        .app-toast[data-toast-type="warning"] #toastIcon {
            color: #f59e0b;
        }

        .app-toast[data-toast-type="info"] #toastIcon {
            color: #3b82f6;
        }

        .alert {
            border: 1px solid rgba(148, 163, 184, 0.14);
            border-radius: 18px;
            padding: 0.95rem 1rem;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(5, 150, 105, 0.08));
            color: #065f46;
            border-color: rgba(16, 185, 129, 0.16);
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.12), rgba(220, 38, 38, 0.08));
            color: #991b1b;
            border-color: rgba(239, 68, 68, 0.16);
        }

        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.13), rgba(217, 119, 6, 0.08));
            color: #92400e;
            border-color: rgba(245, 158, 11, 0.18);
        }

        .alert-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(37, 99, 235, 0.08));
            color: #1d4ed8;
            border-color: rgba(59, 130, 246, 0.16);
        }

        .alert .btn-close {
            opacity: 0.72;
        }

        .swal2-popup.app-swal-popup {
            border-radius: 26px;
            padding: 1.15rem 1.15rem 1.35rem;
            border: 1px solid rgba(148, 163, 184, 0.16);
            box-shadow: 0 28px 70px rgba(15, 23, 42, 0.24);
        }

        .swal2-title.app-swal-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: inherit;
        }

        .swal2-html-container.app-swal-html {
            font-size: 0.97rem;
            line-height: 1.8;
            color: inherit;
        }

        .swal2-actions.app-swal-actions {
            gap: 0.6rem;
        }

        .swal2-styled.app-swal-confirm,
        .swal2-styled.app-swal-cancel,
        .swal2-styled.app-swal-deny {
            border-radius: 999px !important;
            padding: 0.72rem 1.25rem !important;
            font-weight: 800 !important;
            box-shadow: none !important;
        }

        .swal2-icon.app-swal-icon {
            margin-top: 0.35rem;
            margin-bottom: 0.4rem;
        }

        .swal2-toast.app-swal-popup {
            border-radius: 18px;
            padding: 0.7rem 0.85rem;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.22);
        }

        body.theme-dark .app-toast.toast,
        body.dark-mode .app-toast.toast {
            background: rgba(15, 23, 42, 0.94) !important;
            color: #e2e8f0 !important;
            border-color: rgba(148, 163, 184, 0.12) !important;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
        }

        body.theme-dark .app-toast .btn-close,
        body.dark-mode .app-toast .btn-close {
            filter: invert(1) grayscale(1);
        }

        body.theme-dark .alert,
        body.dark-mode .alert {
            color: #e2e8f0;
            border-color: rgba(148, 163, 184, 0.12);
            box-shadow: 0 16px 38px rgba(0, 0, 0, 0.28);
        }

        body.theme-dark .alert-success,
        body.dark-mode .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.16), rgba(6, 95, 70, 0.2));
            color: #d1fae5;
        }

        body.theme-dark .alert-danger,
        body.dark-mode .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.16), rgba(127, 29, 29, 0.2));
            color: #fee2e2;
        }

        body.theme-dark .alert-warning,
        body.dark-mode .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.18), rgba(120, 53, 15, 0.2));
            color: #fef3c7;
        }

        body.theme-dark .alert-info,
        body.dark-mode .alert-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.16), rgba(30, 64, 175, 0.2));
            color: #dbeafe;
        }

        body.theme-dark .swal2-popup.app-swal-popup,
        body.dark-mode .swal2-popup.app-swal-popup {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.98), rgba(11, 18, 32, 0.98)) !important;
            color: #e2e8f0 !important;
            border-color: rgba(148, 163, 184, 0.12);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6);
        }

        body.theme-dark .swal2-html-container.app-swal-html,
        body.theme-dark .swal2-title.app-swal-title,
        body.dark-mode .swal2-html-container.app-swal-html,
        body.dark-mode .swal2-title.app-swal-title {
            color: #e2e8f0 !important;
        }
    </style>
    <!-- Toast Notification Container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
        <div id="liveToast" class="app-toast toast align-items-center border-0 shadow-lg" data-toast-type="success" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center">
                    <i id="toastIcon" class="fas fa-check-circle me-2 fs-5"></i>
                    <span id="toastMessage" class="fw-bold"></span>
                </div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="../assets/css/mobile-fix.css?v=<?php echo time(); ?>">
    <style>
        /* Modern Permission Styles */
        .perm-title {
            font-weight: 700;
            color: #2d3748;
            font-size: 0.9rem;
            margin-bottom: 2px;
        }

        .perm-code {
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 0.65rem;
            color: #a0aec0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .perm-card.is-active {
            border-color: #34C759 !important;
            background: rgba(52, 199, 89, 0.04) !important;
        }
    </style>

    <style>
        /* ================================================
           Modern Toggle Switch - iOS/Material Style
           System Wide - All pages
           ================================================ */

        /* --- Premium Rectangular Toggle Track --- */
        .form-check.form-switch .form-check-input,
        .form-switch-container .form-switch .form-check-input,
        div.form-switch:not(.perm-card) .form-check-input {
            appearance: none !important;
            -webkit-appearance: none !important;
            position: relative !important;
            width: 46px !important;
            height: 24px !important;
            border-radius: 24px !important;
            background-color: #e2e8f0 !important;
            cursor: pointer !important;
            outline: none !important;
            border: none !important;
            margin: 0 !important;
            transition: background-color 0.3s ease !important;
            box-shadow: none !important;
            flex-shrink: 0 !important;
            /* يمنع الزر من الانضغاط وتغير شكله في الجوال */
        }

        /* --- No text for clean look --- */
        .form-check.form-switch .form-check-input::before,
        .form-switch-container .form-switch .form-check-input::before,
        div.form-switch:not(.perm-card) .form-check-input::before {
            content: '' !important;
            display: none !important;
        }

        /* --- Thumb (Circle) same height as track --- */
        .form-check.form-switch .form-check-input::after,
        .form-switch-container .form-switch .form-check-input::after,
        div.form-switch:not(.perm-card) .form-check-input::after {
            content: '' !important;
            position: absolute !important;
            height: 24px !important;
            width: 24px !important;
            left: 0 !important;
            bottom: 0 !important;
            background-color: #ffffff !important;
            border-radius: 50% !important;
            transition: transform 0.3s cubic-bezier(0.25, 0.1, 0.25, 1), width 0.2s ease !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2) !important;
        }

        /* --- Checked State (ON) --- */
        .form-check.form-switch .form-check-input:checked,
        .form-switch-container .form-switch .form-check-input:checked,
        div.form-switch:not(.perm-card) .form-check-input:checked {
            background-color: #3b82f6 !important;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }

        .form-check.form-switch .form-check-input:checked::after,
        .form-switch-container .form-switch .form-check-input:checked::after,
        div.form-switch:not(.perm-card) .form-check-input:checked::after {
            transform: translateX(22px) !important;
        }

        /* --- Hover --- */
        .form-check.form-switch .form-check-input:hover:not(:disabled),
        .form-switch-container .form-switch .form-check-input:hover:not(:disabled),
        div.form-switch:not(.perm-card) .form-check-input:hover:not(:disabled) {
            /* Keep clean */
        }

        /* Bouncy click stretch animation */
        .form-check.form-switch .form-check-input:active::after,
        .form-switch-container .form-switch .form-check-input:active::after,
        div.form-switch:not(.perm-card) .form-check-input:active::after {
            width: 28px !important;
        }

        .form-check.form-switch .form-check-input:checked:active::after,
        .form-switch-container .form-switch .form-check-input:checked:active::after,
        div.form-switch:not(.perm-card) .form-check-input:checked:active::after {
            transform: translateX(18px) !important;
        }

        /* --- Disabled --- */
        .form-check.form-switch .form-check-input:disabled,
        .form-switch-container .form-switch .form-check-input:disabled,
        div.form-switch:not(.perm-card) .form-check-input:disabled {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
        }

        /* --- Label --- */
        .form-check.form-switch .form-check-label,
        .form-switch-container .form-switch .form-check-label,
        div.form-switch:not(.perm-card) .form-check-label {
            cursor: pointer;
            font-weight: 500;
            font-size: 0.88rem;
            margin: 0 !important;
            user-select: none;
        }

        /* --- Dark Mode --- */
        body.theme-dark .form-check.form-switch .form-check-input:not(:checked),
        body.theme-dark .form-switch-container .form-switch .form-check-input:not(:checked),
        body.theme-dark div.form-switch:not(.perm-card) .form-check-input:not(:checked) {
            background-color: #344054 !important;
            border-color: #475467 !important;
        }

        body.theme-dark .form-check.form-switch .form-check-input:not(:checked)::after,
        body.theme-dark .form-switch-container .form-switch .form-check-input:not(:checked)::after,
        body.theme-dark div.form-switch:not(.perm-card) .form-check-input:not(:checked)::after {
            background: #d0d5dd;
        }

        body.theme-dark .form-check.form-switch .form-check-label,
        body.theme-dark .form-switch-container .form-switch .form-check-label,
        body.theme-dark div.form-switch:not(.perm-card) .form-check-label {
            color: #e2e8f0 !important;
        }
        
        body.theme-dark .text-muted,
        body.theme-dark small.text-muted {
            color: #94a3b8 !important;
        }
    </style>
</head>

<body class="<?php echo ($user_theme == 'dark') ? 'theme-dark' : ''; ?>">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebarMenu(event)"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-globe" style="color: #fff;"></i>
            </div>
            <h5 class="mb-0">
                <a href="../index.php" target="_blank" style="color: inherit; text-decoration: none;">
                    <?php echo $settings['site_name']; ?>
                </a>
            </h5>
            <small>لوحة التحكم</small>
        </div>
        <div class="user-panel">
            <div class="user-panel-inner">
                <div class="user-avatar position-relative">
                    <?php if ($currentUser['profile_image']): ?>
                        <img src="../assets/uploads/profiles/<?php echo $currentUser['profile_image']; ?>" alt="">
                    <?php else: ?>
                        <i class="fas fa-user" style="font-size: 1.2rem; color: #64748b;"></i>
                    <?php endif; ?>
                    <?php if ($unread_internal_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem; padding: 3px 5px;">
                            <?php echo $unread_internal_count; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="user-meta" style="min-width:0;flex:1;">
                    <div class="user-name"><?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></div>
                    <div class="user-role-pill">
                        <?php echo htmlspecialchars($currentUser['role_display_name'] ?? 'مستخدم'); ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="sidebar-menu">
            <a href="currency_exchange.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'currency_exchange.php' || basename($_SERVER['PHP_SELF']) == 'exchange_reports.php' ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-money-bill-transfer"></i></span>
                تصريف العملات
            </a>
            <?php if (basename($_SERVER['PHP_SELF']) == 'currency_exchange.php' || basename($_SERVER['PHP_SELF']) == 'exchange_reports.php'): ?>
                <a href="exchange_reports.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'exchange_reports.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                    <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-chart-line"></i></span>
                    تقارير الصرف
                </a>
            <?php endif; ?>

            <!-- الرئيسية -->
            <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                الرئيسية
            </a>

            <!-- وحدة CRM -->
            <?php if (is_crm_enabled()): ?>
            <a href="crm/index.php" class="<?php echo (strpos($_SERVER['PHP_SELF'], 'crm/') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-comments"></i></span>
                وحدة CRM
            </a>
            <?php endif; ?>

            <!-- نظام السفر الجديد (مقسم) -->
            <?php if (has_permission('umrah_view')): ?>
                <?php if (get_module_status($pdo, 'enable_umrah')): ?>
                    <a href="umrah.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'umrah.php' ? 'active' : ''; ?>">
                        <span class="menu-icon"><i class="fas fa-kaaba text-primary"></i></span>
                        قسم العمرة
                    </a>
                <?php endif; ?>
                <?php if (get_module_status($pdo, 'enable_hajj')): ?>
                    <a href="hajj.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'hajj.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                        <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-mosque text-primary"></i></span>
                        خدمات الحج
                    </a>
                <?php endif; ?>
                <?php if (get_module_status($pdo, 'enable_umrah')): ?>
                    <a href="umrah_hosts.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'umrah_hosts.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                        <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-house-user"></i></span>
                        إدارة المستضيفين
                    </a>
                    <a href="umrah_guarantors.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'umrah_guarantors.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                        <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-user-shield"></i></span>
                        إدارة الضامنين
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (has_permission('passport_transactions_view') && get_module_status($pdo, 'enable_passport_transactions')): ?>
                <a href="passport_transactions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'passport_transactions.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-address-card"></i></span>
                    معاملات الجوازات
                </a>
            <?php endif; ?>
            
            <?php if (has_permission('view_all_passports')): ?>
                <a href="public_queries.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'public_queries.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-search"></i></span>
                    سجل الاستعلامات العامة
                </a>
            <?php endif; ?>

            <?php if (has_permission('bookings_view') && (get_module_status($pdo, 'enable_bus_bookings') || get_module_status($pdo, 'enable_flight_bookings'))): ?>
                <a href="bus_flight_bookings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'bus_flight_bookings.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-route"></i></span>
                    حجوزات الباصات والطيران
                </a>
                <?php if (get_module_status($pdo, 'enable_flight_bookings')): ?>
                    <a href="flight_bookings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'flight_bookings.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                        <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-plane-departure"></i></span>
                        حجوزات الطيران
                    </a>
                <?php endif; ?>
                <?php if (get_module_status($pdo, 'enable_bus_bookings')): ?>
                    <a href="bus_bookings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'bus_bookings.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                        <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-bus"></i></span>
                        حجوزات الباصات
                    </a>
                <?php endif; ?>
                <a href="bus_flight_bookings_reports.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'bus_flight_bookings_reports.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                    <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-chart-line"></i></span>
                    تقارير الحجوزات
                </a>
                <a href="booking_tickets.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'booking_tickets.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                    <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-ticket-alt text-info"></i></span>
                    التذاكر الرقمية
                </a>
                <a href="booking_modifications.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'booking_modifications.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                    <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-edit text-warning"></i></span>
                    طلبات تعديل الحجوزات
                </a>
                <a href="booking_refunds.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'booking_refunds.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                    <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-hand-holding-usd text-success"></i></span>
                    طلبات الاسترداد المالي
                </a>
                <a href="booking_notifications.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'booking_notifications.php' ? 'active' : ''; ?>" style="padding-right: 30px; font-size: 0.8rem;">
                    <span class="menu-icon" style="width: 24px; height: 24px; font-size: 0.7rem;"><i class="fas fa-bell text-primary"></i></span>
                    إشعارات الحجوزات
                </a>
            <?php endif; ?>

            <?php if (has_permission('work_visa_view') && get_module_status($pdo, 'enable_work_visa')): ?>
                <a href="work_visa.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'work_visa.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-briefcase text-success"></i></span>
                    تأشيرة العمل
                </a>
            <?php endif; ?>

            <?php if (has_permission('family_visit_view') && get_module_status($pdo, 'enable_family_visit')): ?>
                <a href="family_visit.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'family_visit.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-users text-info"></i></span>
                    الزيارة العائلية
                </a>
                <?php if (get_module_status($pdo, 'enable_postal_services')): ?>
                    <a href="postal_services.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'postal_services.php' ? 'active' : ''; ?>">
                        <span class="menu-icon"><i class="fas fa-box text-warning"></i></span>
                        خدمات البريد
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- نظام السفر -->
            <div class="sidebar-section-label">إدارة العمليات</div>
            <?php if (has_permission('view_passports')): ?>
                <a href="passports.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'passports.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-passport"></i></span>
                    المعاملات / الجوازات
                    <?php if ($pending_posting_count > 0): ?>
                        <span class="badge bg-warning text-dark badge-notify" title="بانتظار الترحيل المالي"><?php echo $pending_posting_count; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <?php if ($is_admin): ?>
                <a href="workflow_approvals.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'workflow_approvals.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-shield-halved text-warning"></i></span>
                    طلبات اعتماد العمليات
                    <?php if ($pending_approvals_count > 0): ?>
                        <span class="badge bg-danger badge-notify"><?php echo $pending_approvals_count; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <!-- النظام المحاسبي المتكامل -->
            <div class="sidebar-section-label">النظام المالي</div>
            <a href="financial_hub.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'financial_hub.php' ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-calculator text-primary"></i></span>
                مركز التحكم المالي
            </a>

            <!-- التواصل والإدارة -->
            <div class="sidebar-section-label">الإدارة والتهيئة</div>
            <a href="internal_messages.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'internal_messages.php' ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-comments text-info"></i></span>
                المراسلة الداخلية
                <?php if ($unread_internal_count > 0): ?>
                    <span class="badge bg-danger badge-notify"><?php echo $unread_internal_count; ?></span>
                <?php endif; ?>
            </a>

            <?php if ($is_admin): ?>
                <a href="system_hub.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'system_hub.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-cogs text-secondary"></i></span>
                    تهيئة النظام
                </a>
                <a href="db_backup.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'db_backup.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-database text-danger"></i></span>
                    إنشاء نسخة احتياطية
                </a>
                <a href="audit_log.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit_log.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="fas fa-clipboard-list text-warning"></i></span>
                    سجل التدقيق
                </a>
                <?php if (!empty($settings['enable_recycle_bin']) && (has_permission('recycle_bin_view') || $is_admin)): ?>
                    <a href="recycle_bin.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'recycle_bin.php' ? 'active' : ''; ?>">
                        <span class="menu-icon"><i class="fas fa-trash-restore text-info"></i></span>
                        سلة المحذوفات
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- الملف الشخصي -->
            <div class="sidebar-section-label">الحساب الشخصي</div>
            <a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-user-circle"></i></span>
                الملف الشخصي
            </a>

            <!-- تسجيل الخروج -->
            <a href="logout.php" class="text-danger border-top mt-2 pt-2">
                <span class="menu-icon"><i class="fas fa-sign-out-alt"></i></span>
                تسجيل الخروج
            </a>
        </div>
    </div>
    <!-- /#sidebar-wrapper -->

    <!-- Page Content -->
    <div class="main-wrapper">
        <nav class="top-navbar">
            <div class="d-flex align-items-center">
                <button class="icon-btn" id="sidebarToggle" onclick="toggleSidebarMenu(event)"><i class="fas fa-bars"></i></button>
                <h4 class="page-title mb-0 ms-3 d-none d-md-block"><?php echo $page_title ?? 'الرئيسية'; ?></h4>
            </div>

            <div class="top-actions">
                <?php if (!empty($settings['show_visit_site_button'])): ?>
                    <a href="../index.php" target="_blank" class="visit-site-btn d-none d-md-inline-flex">
                        <i class="fas fa-globe"></i>
                        <span>زيارة الموقع</span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($settings['show_theme_toggle_button'])): ?>
                    <?php include 'theme_toggle.php'; ?>
                <?php endif; ?>

                <?php if (!empty($settings['show_quick_access_button'])): ?>
                    <div class="dropdown d-none d-md-block">
                        <button class="icon-btn" type="button" data-bs-toggle="dropdown" title="الوصول السريع">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-3 apps-menu">
                            <div class="row g-2">
                                <div class="col-4"><a href="passports.php" class="app-item"><i class="fas fa-passport"></i><span>المعاملات</span></a></div>
                                <div class="col-4"><a href="receipts.php" class="app-item"><i class="fas fa-file-invoice-dollar"></i><span>سند قبض</span></a></div>
                                <div class="col-4"><a href="reports.php" class="app-item"><i class="fas fa-chart-bar"></i><span>التقارير</span></a></div>
                                <div class="col-4"><a href="users.php" class="app-item"><i class="fas fa-users-cog"></i><span>المستخدمون</span></a></div>
                                <div class="col-4"><a href="settings.php" class="app-item"><i class="fas fa-cogs"></i><span>الإعدادات</span></a></div>
                                <div class="col-4"><a href="profile.php" class="app-item"><i class="fas fa-user-cog"></i><span>ملفي</span></a></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($settings['show_search_button'])): ?>
                    <button class="icon-btn" onclick="openQuickSearch()" title="بحث سريع (Ctrl+K)">
                        <i class="fas fa-search"></i>
                    </button>
                <?php endif; ?>

                <?php if (!empty($settings['show_fullscreen_button'])): ?>
                    <button class="icon-btn" onclick="toggleFullScreen()" title="ملء الشاشة (F)">
                        <i class="fas fa-expand" id="fullscreenIcon"></i>
                    </button>
                <?php endif; ?>

                <?php if (!empty($settings['show_notifications_button'])): ?>
                    <div class="dropdown">
                        <button class="icon-btn" type="button" data-bs-toggle="dropdown" title="الإشعارات">
                            <i class="fas fa-bell"></i>
                            <?php if ($total_alert_count > 0): ?>
                                <span class="icon-badge" id="mainNotifBadge"><?php echo $total_alert_count; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-0" style="width: 350px;" id="headerNotifList">
                            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                                <h6 class="mb-0">الإشعارات</h6>
                                <a href="#" class="text-primary small" onclick="markAllNotifsRead()">تحديد الكل كمقروء</a>
                            </div>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if ($unresolved_count > 0): ?>
                                    <div class="px-3 pt-2 pb-1 small text-warning fw-bold">مشاكل تحتاج إلى حل (<?php echo $unresolved_count; ?>)</div>
                                    <a href="work_visa.php?filter=unresolved" class="dropdown-item d-flex align-items-center gap-2 py-2">
                                        <i class="fas fa-exclamation-triangle text-warning"></i>
                                        <div>
                                            <div class="fw-bold">لديك <?php echo $unresolved_count; ?> معاملة تحتاج إلى حل.</div>
                                            <small class="text-muted">انقر للمراجعة واتخاذ إجراء.</small>
                                        </div>
                                    </a>
                                <?php endif; ?>
                                <?php if ($expiry_count > 0): ?>
                                    <div class="px-3 pt-2 pb-1 small text-danger fw-bold border-top">تنبيهات انتهاء التأشيرة (<?php echo $expiry_count; ?>)</div>
                                    <?php foreach ($expiry_alerts as $alert): ?>
                                        <a href="work_visa.php?id=<?php echo $alert['id']; ?>" class="dropdown-item d-flex align-items-center gap-2 py-2 border-bottom">
                                            <div class="bg-danger bg-opacity-10 p-2 rounded-circle text-danger">
                                                <i class="fas fa-clock"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold small text-dark"><?php echo htmlspecialchars($alert['full_name']); ?></div>
                                                <div class="extra-small text-danger fw-bold">ستنتهي خلال <?php echo $alert['days_left']; ?> أيام (<?php echo $alert['visa_expiry_date']; ?>)</div>
                                                <small class="text-muted extra-small">جواز: <?php echo $alert['passport_number']; ?></small>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($notif_count > 0): ?>
                                    <div class="px-3 pt-2 pb-1 small text-info fw-bold">إشعارات جديدة (<?php echo $notif_count; ?>)</div>
                                    <?php foreach ($recent_notifs as $notif): ?>
                                        <?php
                                        // تحديد الرابط بناءً على نوع الإشعار
                                        $link = 'notifications.php?notif_id=' . $notif['id'];
                                        if (strpos($notif['title'], 'معاملة') !== false || strpos($notif['message'], 'معاملة') !== false) {
                                            $link = 'passports.php?highlight=' . $notif['id'];
                                        } elseif (strpos($notif['title'], 'تأشيرة') !== false || strpos($notif['message'], 'تأشيرة') !== false) {
                                            $link = 'work_visa.php?highlight=' . $notif['id'];
                                        }
                                        ?>
                                        <a href="<?php echo $link; ?>?notif_id=<?php echo $notif['id']; ?>" class="dropdown-item d-flex align-items-center gap-2 py-2 notification-link" data-notif-id="<?php echo $notif['id']; ?>" onclick="markNotifRead(<?php echo $notif['id']; ?>, this); return true;">
                                            <i class="fas fa-info-circle text-info"></i>
                                            <div>
                                                <div><?php echo htmlspecialchars($notif['message']); ?></div>
                                                <small class="text-muted"><?php echo date('d M, H:i', strtotime($notif['created_at'])); ?></small>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($total_alert_count == 0): ?>
                                    <div class="text-center text-muted p-4">لا توجد إشعارات جديدة</div>
                                <?php endif; ?>
                            </div>
                            <a href="notifications.php" class="dropdown-item text-center small text-primary p-2 border-top">عرض كل الإشعارات</a>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button class="icon-btn" type="button" data-bs-toggle="dropdown" title="الرسائل">
                            <i class="fas fa-envelope"></i>
                            <span class="icon-badge" id="topMessagesBadge" style="display: none;"></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-0" style="width: 350px;">
                            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                                <h6 class="mb-0">صندوق الوارد</h6>
                                <a href="internal_messages.php?action=new" class="text-primary small">رسالة جديدة</a>
                            </div>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if ($unread_internal_count > 0): ?>
                                    <div class="px-3 pt-2 pb-1 small text-primary fw-bold">رسائل داخلية (<?php echo $unread_internal_count; ?>)</div>
                                    <?php foreach ($recent_internal_items as $item): ?>
                                        <a href="internal_messages.php" class="dropdown-item d-flex align-items-center gap-2 py-2">
                                            <i class="fas fa-comment-dots text-primary"></i>
                                            <div>
                                                <div class="fw-bold">من: <?php echo htmlspecialchars($item['full_name'] ?: $item['username']); ?></div>
                                                <div class="text-truncate" style="max-width: 250px;"><?php echo htmlspecialchars($item['message']); ?></div>
                                                <small class="text-muted"><?php echo date('d M, H:i', strtotime($item['created_at'])); ?></small>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($unread_messages > 0): ?>
                                    <div class="px-3 pt-2 pb-1 small text-success fw-bold">رسائل زوار (<?php echo $unread_messages; ?>)</div>
                                    <?php foreach ($recent_contact as $item): ?>
                                        <a href="messages.php" class="dropdown-item d-flex align-items-center gap-2 py-2">
                                            <i class="fas fa-envelope-open-text text-success"></i>
                                            <div>
                                                <div class="fw-bold">من: <?php echo htmlspecialchars($item['name']); ?></div>
                                                <div class="text-truncate" style="max-width: 250px;"><?php echo htmlspecialchars($item['subject']); ?></div>
                                                <small class="text-muted"><?php echo date('d M, H:i', strtotime($item['created_at'])); ?></small>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($new_subscribers > 0): ?>
                                    <div class="px-3 pt-2 pb-1 small text-info fw-bold">مشتركون جدد (<?php echo $new_subscribers; ?>)</div>
                                    <?php foreach ($recent_subs as $item): ?>
                                        <a href="subscribers.php" class="dropdown-item d-flex align-items-center gap-2 py-2">
                                            <i class="fas fa-at text-info"></i>
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($item['email']); ?></div>
                                                <small class="text-muted"><?php echo date('d M, H:i', strtotime($item['created_at'])); ?></small>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($unread_internal_count == 0 && $unread_messages == 0 && $new_subscribers == 0): ?>
                                    <div class="text-center text-muted p-4">لا توجد رسائل جديدة</div>
                                <?php endif; ?>
                            </div>
                            <a href="internal_messages.php" class="dropdown-item text-center small text-primary p-2 border-top">عرض كل الرسائل</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="vr mx-2 d-none d-lg-block"></div>

                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <div class="user-avatar">
                            <?php if ($currentUser['profile_image']): ?>
                                <img src="../assets/uploads/profiles/<?php echo $currentUser['profile_image']; ?>" alt="">
                            <?php else: ?>
                                <i class="fas fa-user" style="font-size: 1.2rem; color: #64748b;"></i>
                            <?php endif; ?>
                        </div>
                        <div class="user-meta ms-2 d-none d-lg-block">
                            <div class="user-name fw-bold"><?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></div>
                            <div class="user-role text-muted small"><?php echo htmlspecialchars($currentUser['role_display_name'] ?? 'مستخدم'); ?></div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-2" style="min-width: 220px;">
                        <li>
                            <div class="px-3 py-2 border-bottom">
                                <div class="fw-bold"><?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></div>
                            </div>
                        </li>
                        <li><a class="dropdown-item py-2" href="profile.php"><i class="fas fa-user-cog me-2"></i> الملف الشخصي</a></li>
                        <li><a class="dropdown-item py-2" href="settings.php"><i class="fas fa-cogs me-2"></i> إعدادات الحساب</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger py-2" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> تسجيل الخروج</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="content-body">
            <?php if ($backup_due_banner !== ''): ?>
                <div class="container-fluid pt-3">
                    <div class="alert alert-info border-0 shadow-sm rounded-3 mb-0 d-flex align-items-center gap-2" role="alert">
                        <i class="fas fa-database fa-lg"></i>
                        <div><?php echo $backup_due_banner; ?></div>
                    </div>
                </div>
            <?php endif; ?>
