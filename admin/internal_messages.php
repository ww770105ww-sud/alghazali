<?php
header('Content-Type: text/html; charset=utf-8');
ob_start();
// debug helpers
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once '../includes/session_config.php';
// indicate file loaded
// أضف هذه الدالة في بداية الملف بعد require_once
function detectServerEnvironment()
{
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';

    // التحقق من البيئة المحلية
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1']) ||
        strpos($host, '192.168.') === 0 ||
        strpos($host, '10.') === 0 ||
        strpos($host, '172.16.') === 0 ||
        strpos($host, '172.17.') === 0 ||
        strpos($host, '172.18.') === 0 ||
        strpos($host, '172.19.') === 0 ||
        strpos($host, '172.20.') === 0 ||
        strpos($host, '172.21.') === 0 ||
        strpos($host, '172.22.') === 0 ||
        strpos($host, '172.23.') === 0 ||
        strpos($host, '172.24.') === 0 ||
        strpos($host, '172.25.') === 0 ||
        strpos($host, '172.26.') === 0 ||
        strpos($host, '172.27.') === 0 ||
        strpos($host, '172.28.') === 0 ||
        strpos($host, '172.29.') === 0 ||
        strpos($host, '172.30.') === 0 ||
        strpos($host, '172.31.') === 0;

    return [
        'is_local' => $isLocal,
        'host' => $host,
        'ip' => $ip,
        'base_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://' . $host
    ];
}

$env = detectServerEnvironment();

// تمرير معلومات البيئة إلى JavaScript
echo "<script>window.SERVER_ENV = " . json_encode($env) . ";</script>";

// بدلاً من استخدام localhost دائماً، استخدم البيئة المكتشفة
$ws_host = $env['is_local'] ? 'localhost' : $env['host'];
$ws_port = 8081; // نفس المنفذ دائماً

// تمرير إعدادات WebSocket إلى JavaScript
echo "<script>window.WS_CONFIG = { host: '{$ws_host}', port: {$ws_port} };</script>";
//echo "<!-- internal_messages_new.php loaded -->";

require_once '../includes/db.php';

// التأكد من وجود الإعدادات اللازمة
try {
    require_once '../includes/functions.php';
    $settings = getSettings($pdo);

    // التأكد من وجود المفاتيح المطلوبة في system_settings
    $required_keys = [
        'edit_delete_time_limit' => 5,
        'disappear_after_read_seconds' => 10
    ];

    foreach ($required_keys as $key => $default) {
        if (!isset($settings[$key])) {
            $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'messages')")
                ->execute([$key, $default]);
        }
    }
} catch (Exception $e) {
    // تجاهل الأخطاء البسيطة هنا
}

try {
    // جدول الرسائل
    $pdo->query("SELECT is_disappeared FROM internal_messages LIMIT 1");
} catch (Exception $e) {
    $pdo->query("ALTER TABLE `internal_messages` ADD `read_at` DATETIME NULL DEFAULT NULL");
    $pdo->query("ALTER TABLE `internal_messages` ADD `is_disappeared` TINYINT(1) DEFAULT 0");
    $pdo->query("ALTER TABLE `internal_messages` ADD `original_message` TEXT NULL DEFAULT NULL");
}

try {
    // جدول المكالمات
    $pdo->query("SELECT id FROM internal_calls LIMIT 1");
} catch (Exception $e) {
    $pdo->query("
        CREATE TABLE IF NOT EXISTS `internal_calls` (
          `id` int NOT NULL AUTO_INCREMENT,
          `caller_id` int NOT NULL,
          `receiver_id` int DEFAULT NULL,
          `group_id` int DEFAULT NULL,
          `call_type` enum('audio','video') NOT NULL,
          `status` enum('calling','ringing','accepted','rejected','busy','cancelled','missed','ended','expired') NOT NULL DEFAULT 'calling',
          `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `answered_at` datetime DEFAULT NULL,
          `ended_at` datetime DEFAULT NULL,
          `duration` int DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `caller_id` (`caller_id`),
          KEY `receiver_id` (`receiver_id`),
          KEY `group_id` (`group_id`),
          KEY `status` (`status`),
          KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

try {
    $pdo->query("ALTER TABLE `internal_calls` MODIFY `status` enum('calling','ringing','accepted','rejected','busy','cancelled','missed','ended','expired') NOT NULL DEFAULT 'calling'");
} catch (Exception $e) {
    error_log('internal_messages.php internal_calls status enum check failed: ' . $e->getMessage());
}

try {
    // جدول رسائل المجموعات
    $pdo->query("SELECT original_message FROM internal_group_messages LIMIT 1");
} catch (Exception $e) {
    $pdo->query("ALTER TABLE `internal_group_messages` ADD `original_message` TEXT NULL DEFAULT NULL");
}

$current_user_id = $_SESSION['admin_id'] ?? null;
$upload_dir = '../assets/uploads/chat/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

function chat_detect_attachment_type(string $mime, string $extension): string
{
    if (strpos($mime, 'image/') === 0) return 'image';
    if (strpos($mime, 'video/') === 0) return 'video';
    if (strpos($mime, 'audio/') === 0) return 'audio';
    if ($extension === 'pdf') return 'document';
    return 'document';
}

function chat_handle_attachment_upload(string $field, string $upload_dir): array
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'type' => null, 'name' => null];
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed');
    }

    $max_size = 50 * 1024 * 1024;
    if ((int)$_FILES[$field]['size'] > $max_size) {
        throw new RuntimeException('File is too large');
    }

    $original_name = basename((string)$_FILES[$field]['name']);
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'mp4',
        'webm',
        'mov',
        'mp3',
        'wav',
        'aac',
        'ogg',
        'm4a',
        'webm',
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'zip',
        'rar'
    ];

    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Unsupported file type');
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $_FILES[$field]['tmp_name']);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
            finfo_close($finfo);
        }
    }

    $safe_name = time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $upload_dir . $safe_name)) {
        throw new RuntimeException('Could not save uploaded file');
    }

    return [
        'path' => 'assets/uploads/chat/' . $safe_name,
        'type' => chat_detect_attachment_type($mime, $extension),
        'name' => $original_name
    ];
}

function chat_expire_stale_calls(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        UPDATE internal_calls
        SET status = 'expired', ended_at = COALESCE(ended_at, NOW())
        WHERE status IN ('calling', 'ringing')
          AND TIMESTAMPDIFF(SECOND, COALESCE(started_at, created_at), NOW()) > 60
    ");
    $stmt->execute();
}

function chat_call_is_active(array $call): bool
{
    return in_array((string)($call['status'] ?? ''), ['calling', 'ringing', 'accepted'], true);
}

function chat_find_user_active_call(PDO $pdo, int $user_id): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, status, call_type, group_id
        FROM internal_calls
        WHERE status IN ('calling', 'ringing', 'accepted')
          AND (
              caller_id = ?
              OR receiver_id = ?
              OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?)
          )
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $call = $stmt->fetch(PDO::FETCH_ASSOC);
    return $call ?: null;
}

function chat_finish_user_active_calls(PDO $pdo, int $user_id, string $status = 'ended'): void
{
    $allowed = ['cancelled', 'missed', 'ended', 'expired'];
    if (!in_array($status, $allowed, true)) {
        $status = 'ended';
    }

    $stmt = $pdo->prepare("
        UPDATE internal_calls
        SET status = ?,
            ended_at = COALESCE(ended_at, NOW()),
            duration = CASE
                WHEN duration IS NULL THEN GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(answered_at, started_at, created_at), NOW()))
                ELSE duration
            END
        WHERE status IN ('calling', 'ringing', 'accepted')
          AND (
              caller_id = ?
              OR receiver_id = ?
              OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?)
          )
    ");
    $stmt->execute([$status, $user_id, $user_id, $user_id]);
}

// جلب دور المستخدم الحالي
$user_role = $_SESSION['role'] ?? 'editor';
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

// معالجة طلبات AJAX قبل تحميل الهيدر
if (isset($_GET['action'])) {
    error_reporting(0); // Disable all errors for AJAX
    ini_set('display_errors', 0); // Disable error display for AJAX
    if (ob_get_level()) {
        ob_end_clean(); // Clean any existing output buffers
    }
    if (!$current_user_id) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    $action = $_GET['action'];
    header('Content-Type: application/json');

    // إرسال رسالة فردية
    if ($action == 'send') {
        $receiver_id = (int)$_POST['receiver_id'];
        $message = $_POST['message'] ?? '';
        $attachment = ['path' => null, 'type' => null, 'name' => null];

        // منع المستخدم من إرسال رسائل لنفسه
        if ($receiver_id == $current_user_id) {
            echo json_encode(['status' => 'error', 'message' => 'لا يمكنك إرسال رسالة لنفسك']);
            exit();
        }

        try {
            $attachment = chat_handle_attachment_upload('chat_attachment', $upload_dir);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit();
        }

        if ($receiver_id > 0 && (!empty($message) || $attachment['path'])) {
            $stmt = $pdo->prepare("INSERT INTO internal_messages (sender_id, receiver_id, message, image_path, attachment_type, attachment_name, is_disappeared) VALUES (?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$current_user_id, $receiver_id, $message, $attachment['path'], $attachment['type'], $attachment['name']]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // إرسال رسالة جماعية
    if ($action == 'send_group') {
        $group_id = (int)$_POST['group_id'];
        $message = $_POST['message'] ?? '';
        $attachment = ['path' => null, 'type' => null, 'name' => null];

        // التحقق من أن المستخدم عضو في المجموعة
        $member_check = $pdo->prepare("SELECT id FROM internal_group_members WHERE group_id = ? AND user_id = ?");
        $member_check->execute([$group_id, $current_user_id]);
        if (!$member_check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'أنت لست عضواً في هذه المجموعة']);
            exit();
        }

        try {
            $attachment = chat_handle_attachment_upload('chat_attachment', $upload_dir);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit();
        }

        if (!empty($message) || $attachment['path']) {
            $stmt = $pdo->prepare("INSERT INTO internal_group_messages (group_id, sender_id, message, image_path, attachment_type, attachment_name) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$group_id, $current_user_id, $message, $attachment['path'], $attachment['type'], $attachment['name']]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // تحديث إعدادات التنبيهات
    if ($action == 'toggle_notification') {
        $type = $_POST['type']; // sound or notification
        $enabled = (int)$_POST['enabled'];

        $update_field = ($type == 'sound') ? 'sound_enabled' : 'notification_enabled';
        $stmt = $pdo->prepare("UPDATE notification_settings SET $update_field = ? WHERE user_id = ?");
        $stmt->execute([$enabled, $current_user_id]);

        echo json_encode(['status' => 'success']);
        exit();
    }

    // جلب قائمة المستخدمين (بدون المستخدم الحالي)
    if ($action == 'chat_presence') {
        $target_type = ($_POST['target_type'] ?? '') === 'group' ? 'group' : 'user';
        $target_id = (int)($_POST['target_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if ($target_id <= 0 || !in_array($status, ['typing', 'recording', 'idle'], true)) {
            echo json_encode(['status' => 'error']);
            exit();
        }

        if ($status === 'idle') {
            $stmt = $pdo->prepare("DELETE FROM internal_chat_presence WHERE user_id = ? AND target_type = ? AND target_id = ?");
            $stmt->execute([$current_user_id, $target_type, $target_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO internal_chat_presence (user_id, target_type, target_id, status, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()
            ");
            $stmt->execute([$current_user_id, $target_type, $target_id, $status]);
        }

        echo json_encode(['status' => 'success']);
        exit();
    }

    if ($action == 'get_chat_presence') {
        $target_type = ($_GET['target_type'] ?? '') === 'group' ? 'group' : 'user';
        $target_id = (int)($_GET['target_id'] ?? 0);

        $pdo->exec("DELETE FROM internal_chat_presence WHERE updated_at < DATE_SUB(NOW(), INTERVAL 20 SECOND)");

        if ($target_type === 'group') {
            $stmt = $pdo->prepare("
                SELECT p.status, u.full_name, u.username
                FROM internal_chat_presence p
                JOIN users u ON u.id = p.user_id
                WHERE p.target_type = 'group'
                  AND p.target_id = ?
                  AND p.user_id <> ?
                  AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 7 SECOND)
                ORDER BY p.updated_at DESC
                LIMIT 3
            ");
            $stmt->execute([$target_id, $current_user_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT p.status, u.full_name, u.username
                FROM internal_chat_presence p
                JOIN users u ON u.id = p.user_id
                WHERE p.target_type = 'user'
                  AND p.target_id = ?
                  AND p.user_id = ?
                  AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 7 SECOND)
                ORDER BY p.updated_at DESC
                LIMIT 1
            ");
            $stmt->execute([$current_user_id, $target_id]);
        }

        echo json_encode(['status' => 'success', 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    if ($action == 'get_users') {
        if (!$current_user_id) {
            echo json_encode(['users' => [], 'message' => 'Not logged in']);
            exit();
        }
        $users = $pdo->prepare("
            SELECT u.id, u.username, u.full_name, u.profile_image, u.is_online, u.last_seen,
                   (SELECT COUNT(*) FROM internal_messages im
                    WHERE im.sender_id = u.id AND im.receiver_id = ?
                    AND im.is_read = 0
                    AND (im.is_deleted_for_all = 0 OR im.is_deleted_for_all IS NULL)
                    AND (im.is_disappeared = 0 OR im.is_disappeared IS NULL)) as unread_count,
                   (SELECT status FROM internal_calls
                    WHERE (caller_id = u.id OR receiver_id = u.id OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = u.id))
                    AND status IN ('calling', 'ringing', 'accepted')
                    ORDER BY id DESC LIMIT 1) as call_status
            FROM users u
            WHERE u.id != ?
            ORDER BY u.is_online DESC, u.last_seen DESC, u.full_name ASC
        ");
        try {
            $users->execute([$current_user_id, $current_user_id]);
            $user_list = $users->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Query error: " . $e->getMessage());
            echo json_encode(['users' => [], 'message' => 'Database error']);
            exit();
        }
        echo json_encode(['users' => $user_list]);
        exit();
    }

    // جلب قائمة المجموعات
    if ($action == 'get_groups') {
        $sql = $is_admin ?
            "SELECT mg.*, u.full_name as creator_name,
                   (SELECT COUNT(*) FROM internal_group_messages gm WHERE gm.group_id = mg.id AND gm.is_deleted = 0) as msg_count
            FROM internal_groups mg
            JOIN users u ON mg.created_by = u.id
            ORDER BY mg.created_at DESC" :
            "SELECT mg.*, u.full_name as creator_name,
                   (SELECT COUNT(*) FROM internal_group_messages gm WHERE gm.group_id = mg.id AND gm.is_deleted = 0) as msg_count
            FROM internal_groups mg
            JOIN users u ON mg.created_by = u.id
            JOIN internal_group_members gmb ON mg.id = gmb.group_id
            WHERE gmb.user_id = ?
            ORDER BY mg.created_at DESC";

        $groups = $pdo->prepare($sql);
        if ($is_admin) {
            $groups->execute();
        } else {
            $groups->execute([$current_user_id]);
        }
        $group_list = $groups->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['groups' => $group_list]);
        exit();
    }

    // جلب معلومات مجموعة محددة
    if ($action == 'get_group_info') {
        $group_id = (int)$_GET['group_id'];
        $stmt = $pdo->prepare("SELECT * FROM internal_groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($group) {
            // جلب قائمة الأعضاء مع حالاتهم
            $members_stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.username, u.is_online, u.last_seen
                FROM internal_group_members gm
                JOIN users u ON gm.user_id = u.id
                WHERE gm.group_id = ?
            ");
            $members_stmt->execute([$group_id]);
            $members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);

            $online_count = 0;
            $total_count = count($members);
            $now = new DateTime();

            foreach ($members as $m) {
                $lastSeen = new DateTime($m['last_seen']);
                $diff = $now->getTimestamp() - $lastSeen->getTimestamp();
                if ($m['is_online'] == 1 && $diff < 300) { // 5 minutes
                    $online_count++;
                }
            }

            $group['members'] = array_column($members, 'id');
            $group['total_members'] = $total_count;
            $group['online_members'] = $online_count;

            echo json_encode(['status' => 'success', 'group' => $group]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'المجموعة غير موجودة']);
        }
        exit();
    }

    // تحديث مجموعة (للمدير أو منشئ المجموعة)
    if ($action == 'update_group') {
        $group_id = (int)$_POST['group_id'];
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $members = $_POST['members'] ?? [];

        // التحقق من الصلاحيات
        $check = $pdo->prepare("SELECT created_by FROM internal_groups WHERE id = ?");
        $check->execute([$group_id]);
        $creator_id = $check->fetchColumn();

        if (!$is_admin && $creator_id != $current_user_id) {
            echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية لتعديل هذه المجموعة']);
            exit();
        }

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'اسم المجموعة مطلوب']);
            exit();
        }

        // تحديث البيانات الأساسية
        $stmt = $pdo->prepare("UPDATE internal_groups SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $group_id]);

        // تحديث الأعضاء: حذف الكل ثم إعادة الإضافة (أو يمكن استخدام مقارنة المصفوفات)
        $pdo->prepare("DELETE FROM internal_group_members WHERE group_id = ?")->execute([$group_id]);

        if (!empty($members) && is_array($members)) {
            $member_stmt = $pdo->prepare("INSERT INTO internal_group_members (group_id, user_id) VALUES (?, ?)");
            foreach ($members as $member_id) {
                $member_stmt->execute([$group_id, (int)$member_id]);
            }
        }

        // التأكد من بقاء المنشئ كعضو
        $pdo->prepare("INSERT IGNORE INTO internal_group_members (group_id, user_id) VALUES (?, ?)")->execute([$group_id, $creator_id]);

        echo json_encode(['status' => 'success']);
        exit();
    }

    // حذف مجموعة
    if ($action == 'delete_group') {
        if (!$is_admin) {
            echo json_encode(['status' => 'error', 'message' => 'فقط المسؤول يمكنه حذف المجموعات']);
            exit();
        }
        $group_id = (int)$_POST['group_id'];
        $pdo->prepare("DELETE FROM internal_groups WHERE id = ?")->execute([$group_id]);
        $pdo->prepare("DELETE FROM internal_group_members WHERE group_id = ?")->execute([$group_id]);
        $pdo->prepare("DELETE FROM internal_group_messages WHERE group_id = ?")->execute([$group_id]);
        echo json_encode(['status' => 'success']);
        exit();
    }

    // إنشاء مجموعة جديدة (للمدير والمطور فقط)
    if ($action == 'create_group') {
        if (!$is_admin) {
            echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية لإنشاء مجموعات']);
            exit();
        }

        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $members = $_POST['members'] ?? [];

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'اسم المجموعة مطلوب']);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO internal_groups (name, description, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$name, $description, $current_user_id]);
        $group_id = $pdo->lastInsertId();

        // إضافة الأعضاء
        if (!empty($members) && is_array($members)) {
            $member_stmt = $pdo->prepare("INSERT INTO internal_group_members (group_id, user_id) VALUES (?, ?)");
            foreach ($members as $member_id) {
                $member_stmt->execute([$group_id, (int)$member_id]);
            }
        }

        // إضافة المنشئ كعضو
        $member_stmt = $pdo->prepare("INSERT IGNORE INTO internal_group_members (group_id, user_id) VALUES (?, ?)");
        $member_stmt->execute([$group_id, $current_user_id]);

        echo json_encode(['status' => 'success', 'group_id' => $group_id]);
        exit();
    }

    // جلب رسائل المحادثة
    if ($action == 'fetch') {
        $chat_user_id = (int)$_GET['user'];
        $u1 = isset($_GET['u1']) ? (int)$_GET['u1'] : $current_user_id;
        $last_message_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

        // منع جلب الرسائل من المستخدم لنفسه
        if ($chat_user_id == $current_user_id) {
            echo json_encode(['messages' => []]);
            exit();
        }

        $settings = getSettings($pdo);
        $disappear_seconds = (int)($settings['disappear_after_read_seconds'] ?? 10);

        // تحديث وقت القراءة للرسائل التي استلمتها أنا الآن ولم يتم تسجيل وقت قراءتها
        if ($u1 == $current_user_id) {
            $pdo->prepare("UPDATE internal_messages SET is_read = 1, read_at = NOW() WHERE receiver_id = ? AND sender_id = ? AND is_read = 0")->execute([$current_user_id, $chat_user_id]);
            // إصلاح الرسائل القديمة التي قُرأت ولكن ليس لها وقت قراءة
            $pdo->prepare("UPDATE internal_messages SET read_at = created_at WHERE receiver_id = ? AND sender_id = ? AND is_read = 1 AND read_at IS NULL")->execute([$current_user_id, $chat_user_id]);
        }

        // تحديث حالة "الاختفاء" لجميع الرسائل المقروءة في هذه المحادثة (المرسلة والمستلمة)
        // يتم وضع العلامة دائماً إذا كان الخيار مفعلاً، ولكن الفلترة تتم في الاستعلام اللاحق بناءً على رتبة المستخدم
        if ($settings && $settings['auto_delete_messages']) {
            $pdo->prepare("
                UPDATE internal_messages
                SET is_disappeared = 1
                WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
                AND is_read = 1 AND read_at IS NOT NULL
                AND (NOW() > DATE_ADD(read_at, INTERVAL $disappear_seconds SECOND))
            ")->execute([$current_user_id, $chat_user_id, $chat_user_id, $current_user_id]);
        }

        $where_clause = "WHERE ((im.sender_id = ? AND im.receiver_id = ?) OR (im.sender_id = ? AND im.receiver_id = ?))";
        $params = [$u1, $chat_user_id, $chat_user_id, $u1];

        // إذا لم يكن المستخدم مديراً، قم بإخفاء الرسائل المختفية
        if (!$is_admin) {
            $where_clause .= " AND (im.is_disappeared = 0 OR im.is_disappeared IS NULL)";
        }

        // تصفية الرسائل المحذوفة مع مراعاة القيم NULL
        $where_clause .= " AND (im.is_deleted_for_all = 0 OR im.is_deleted_for_all IS NULL) AND (
            (im.sender_id = ? AND (im.is_deleted_by_sender = 0 OR im.is_deleted_by_sender IS NULL)) OR
            (im.receiver_id = ? AND (im.is_deleted_by_receiver = 0 OR im.is_deleted_by_receiver IS NULL))
        )";
        $params[] = $current_user_id;
        $params[] = $current_user_id;

        // جلب فقط الرسائل الجديدة بعد الرسالة الأخيرة
        if ($last_message_id > 0) {
            $where_clause .= " AND im.id > ?";
            $params[] = $last_message_id;
        }

        // استعلام مع JOIN لحذر N+1 query problem!
        $msg_stmt = $pdo->prepare("
            SELECT im.*, u.username, u.full_name, u.profile_image
            FROM internal_messages im
            JOIN users u ON im.sender_id = u.id
            $where_clause
            ORDER BY im.created_at ASC
        ");
        $msg_stmt->execute($params);
        $messages = $msg_stmt->fetchAll();

        $output = [];
        foreach ($messages as $msg) {
            $display_name = (!empty($msg['full_name'])) ? $msg['full_name'] : $msg['username'];
            $output[] = [
                'id' => $msg['id'],
                'sender_id' => $msg['sender_id'],
                'message' => $msg['message'],
                'image_path' => $msg['image_path'],
                'attachment_type' => $msg['attachment_type'] ?? null,
                'attachment_name' => $msg['attachment_name'] ?? null,
                'is_read' => $msg['is_read'],
                'is_edited' => $msg['is_edited'],
                'created_at' => $msg['created_at'],
                'sender_name' => $display_name,
                'sender_image' => $msg['profile_image'],
                'is_own' => ($msg['sender_id'] == $current_user_id)
            ];
        }

        echo json_encode(['messages' => $output]);
        exit();
    }

    // تعديل رسالة مجموعة
    if ($action == 'edit_group_message') {
        $message_id = (int)$_POST['message_id'];
        $new_message = $_POST['message'] ?? '';

        if ($message_id > 0 && !empty($new_message)) {
            $check = $pdo->prepare("SELECT sender_id, created_at FROM internal_group_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();

            if ($msg && $msg['sender_id'] == $current_user_id) {
                // التحقق من الوقت المسموح للتعديل
                $settings = getSettings($pdo);
                $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                $msg_time = new DateTime($msg['created_at']);
                $now = new DateTime();
                $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                if ($diff <= ($time_limit * 60)) {
                    // حفظ الرسالة الأصلية قبل التعديل لأول مرة
                    $original_check = $pdo->prepare("SELECT original_message FROM internal_group_messages WHERE id = ?");
                    $original_check->execute([$message_id]);
                    if (empty($original_check->fetchColumn())) {
                        $current_msg = $pdo->prepare("SELECT message FROM internal_group_messages WHERE id = ?");
                        $current_msg->execute([$message_id]);
                        $old_text = $current_msg->fetchColumn();
                        $pdo->prepare("UPDATE internal_group_messages SET original_message = ? WHERE id = ?")->execute([$old_text, $message_id]);
                    }

                    $stmt = $pdo->prepare("UPDATE internal_group_messages SET message = ?, is_edited = 1, edited_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_message, $message_id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة التعديل ($time_limit دقائق)"]);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بتعديل هذه الرسالة']);
            }
        }
        exit();
    }

    // حذف رسالة مجموعة
    if ($action == 'delete_group_message') {
        $message_id = (int)$_POST['message_id'];
        $type = $_POST['type'] ?? 'for_everyone';

        if ($message_id > 0) {
            $check = $pdo->prepare("SELECT sender_id, created_at FROM internal_group_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();

            if ($msg) {
                if ($type == 'for_me') {
                    echo json_encode(['status' => 'error', 'message' => 'الحذف الشخصي في المجموعات غير متوفر حالياً']);
                } else {
                    if ($msg['sender_id'] == $current_user_id || $is_admin) {
                        // التحقق من الوقت المسموح للحذف (للمستخدم العادي فقط، المدير يستثنى)
                        if (!$is_admin) {
                            $settings = getSettings($pdo);
                            $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                            $msg_time = new DateTime($msg['created_at']);
                            $now = new DateTime();
                            $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                            if ($diff > ($time_limit * 60)) {
                                echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة الحذف ($time_limit دقائق)"]);
                                exit();
                            }
                        }

                        $stmt = $pdo->prepare("UPDATE internal_group_messages SET is_deleted = 1, is_deleted_for_all = 1 WHERE id = ?");
                        $stmt->execute([$message_id]);
                        echo json_encode(['status' => 'success']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بحذف هذه الرسالة']);
                    }
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'الرسالة غير موجودة']);
            }
        }
        exit();
    }

    // تعديل رسالة
    if ($action == 'edit_message') {
        $message_id = (int)$_POST['message_id'];
        $new_message = $_POST['message'] ?? '';

        if ($message_id > 0 && !empty($new_message)) {
            $check = $pdo->prepare("SELECT sender_id, created_at FROM internal_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();

            if ($msg && $msg['sender_id'] == $current_user_id) {
                // التحقق من الوقت المسموح للتعديل
                $settings = getSettings($pdo);
                $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                $msg_time = new DateTime($msg['created_at']);
                $now = new DateTime();
                $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                if ($diff <= ($time_limit * 60)) {
                    // حفظ الرسالة الأصلية
                    $original_check = $pdo->prepare("SELECT original_message FROM internal_messages WHERE id = ?");
                    $original_check->execute([$message_id]);
                    if (empty($original_check->fetchColumn())) {
                        $current_msg = $pdo->prepare("SELECT message FROM internal_messages WHERE id = ?");
                        $current_msg->execute([$message_id]);
                        $old_text = $current_msg->fetchColumn();
                        $pdo->prepare("UPDATE internal_messages SET original_message = ? WHERE id = ?")->execute([$old_text, $message_id]);
                    }

                    $stmt = $pdo->prepare("UPDATE internal_messages SET message = ?, is_edited = 1, edited_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_message, $message_id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة التعديل ($time_limit دقائق)"]);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بتعديل هذه الرسالة']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // حذف رسالة
    if ($action == 'delete_message') {
        $message_id = (int)$_POST['message_id'];
        $type = $_POST['type'] ?? 'for_me';

        if ($message_id > 0) {
            $check = $pdo->prepare("SELECT sender_id, receiver_id, created_at FROM internal_messages WHERE id = ?");
            $check->execute([$message_id]);
            $msg = $check->fetch();

            if ($msg) {
                if ($type == 'for_everyone') {
                    if ($msg['sender_id'] == $current_user_id) {
                        // التحقق من الوقت المسموح للحذف لدى الجميع
                        $settings = getSettings($pdo);
                        $time_limit = $settings['edit_delete_time_limit'] ?? 5;
                        $msg_time = new DateTime($msg['created_at']);
                        $now = new DateTime();
                        $diff = $now->getTimestamp() - $msg_time->getTimestamp();

                        if ($diff <= ($time_limit * 60)) {
                            $stmt = $pdo->prepare("UPDATE internal_messages SET is_deleted_for_all = 1 WHERE id = ?");
                            $stmt->execute([$message_id]);
                            echo json_encode(['status' => 'success']);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => "عذراً، انتهت مهلة الحذف لدى الجميع ($time_limit دقائق)"]);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'لا يمكنك حذف هذه الرسالة للطرفين']);
                    }
                } else {
                    // حذف لدي (مسموح دائماً)
                    if ($msg['sender_id'] == $current_user_id) {
                        $stmt = $pdo->prepare("UPDATE internal_messages SET is_deleted_by_sender = 1 WHERE id = ?");
                        $stmt->execute([$message_id]);
                    } else if ($msg['receiver_id'] == $current_user_id) {
                        $stmt = $pdo->prepare("UPDATE internal_messages SET is_deleted_by_receiver = 1 WHERE id = ?");
                        $stmt->execute([$message_id]);
                    }
                    echo json_encode(['status' => 'success']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'الرسالة غير موجودة']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
        }
        exit();
    }

    // جلب رسائل المجموعة
    if ($action == 'fetch_group') {
        $group_id = (int)$_GET['group'];
        $last_message_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

        // التحقق من أن المستخدم عضو في المجموعة
        if (!$is_admin) {
            $member_check = $pdo->prepare("SELECT id FROM internal_group_members WHERE group_id = ? AND user_id = ?");
            $member_check->execute([$group_id, $current_user_id]);
            if (!$member_check->fetch()) {
                echo json_encode(['messages' => []]);
                exit();
            }
        }

        $where_clause = "WHERE igm.group_id = ? AND igm.is_deleted = 0";
        $params = [$group_id];

        // جلب فقط الرسائل الجديدة بعد الرسالة الأخيرة
        if ($last_message_id > 0) {
            $where_clause .= " AND igm.id > ?";
            $params[] = $last_message_id;
        }

        $msg_stmt = $pdo->prepare("
            SELECT igm.*, u.username, u.full_name, u.profile_image
            FROM internal_group_messages igm
            JOIN users u ON igm.sender_id = u.id
            $where_clause
            ORDER BY igm.created_at ASC
        ");
        $msg_stmt->execute($params);
        $messages = $msg_stmt->fetchAll();

        $output = [];
        foreach ($messages as $msg) {
            $display_name = (!empty($msg['full_name'])) ? $msg['full_name'] : $msg['username'];
            $output[] = [
                'id' => $msg['id'],
                'sender_id' => $msg['sender_id'],
                'message' => $msg['message'],
                'image_path' => $msg['image_path'],
                'attachment_type' => $msg['attachment_type'] ?? null,
                'attachment_name' => $msg['attachment_name'] ?? null,
                'created_at' => $msg['created_at'],
                'sender_name' => $display_name,
                'sender_image' => $msg['profile_image'],
                'is_own' => ($msg['sender_id'] == $current_user_id),
                'is_edited' => $msg['is_edited'] ?? 0,
                'is_read' => $msg['is_read'] ?? 0
            ];
        }

        echo json_encode(['messages' => $output]);
        exit();
    }

    // جلب عدد الرسائل غير المقروءة (للهيدر والفوتر)
    if ($action == 'get_unread_count' || $action == 'get_unread_counts') {
        $unread_stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM internal_messages WHERE receiver_id = ? AND is_read = 0");
        $unread_stmt->execute([$current_user_id]);
        $unread_count = $unread_stmt->fetchColumn();

        if ($action == 'get_unread_counts') {
            echo json_encode([
                'status' => 'success',
                'counts' => [
                    'internal' => (int)$unread_count
                ]
            ]);
        } else {
            echo json_encode(['status' => 'success', 'unread_count' => (int)$unread_count]);
        }
        exit();
    }

    // جلب آخر رسالة غير مقروءة
    if ($action == 'get_latest_message') {
        $latest_stmt = $pdo->prepare("
            SELECT im.*, u.full_name, u.username
            FROM internal_messages im
            JOIN users u ON im.sender_id = u.id
            WHERE im.receiver_id = ? AND im.is_read = 0
            ORDER BY im.created_at DESC
            LIMIT 1
        ");
        $latest_stmt->execute([$current_user_id]);
        $message = $latest_stmt->fetch();
        if ($message) {
            echo json_encode([
                'status' => 'success',
                'message' => [
                    'id' => $message['id'],
                    'full_name' => $message['full_name'],
                    'username' => $message['username'],
                    'message' => $message['message'],
                    'created_at' => $message['created_at']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'success', 'message' => null]);
        }
        exit();
    }

    // ==== ENDPOINTS للاتصالات ====

    // بدء مكالمة جديدة
    if ($action == 'start_call') {
        chat_expire_stale_calls($pdo);

        $call_type = $_POST['call_type'] ?? 'audio';
        $target_type = $_POST['target_type'] ?? 'user';
        $target_id = (int)($_POST['target_id'] ?? 0);

        if (!in_array($call_type, ['audio', 'video'], true) || !in_array($target_type, ['user', 'group'], true) || $target_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid call request']);
            exit();
        }

        $pdo->beginTransaction();

        $busy_call = chat_find_user_active_call($pdo, (int)$current_user_id);
        if ($busy_call) {
            $pdo->rollBack();
            echo json_encode([
                'status' => 'busy',
                'message' => 'أنت مشغول حاليا في مكالمة أخرى',
                'busy_reason' => $busy_call['group_id'] ? 'group_call' : 'in_call'
            ]);
            exit();
        }

        // Check if target user is busy
        if ($target_type == 'user') {
            $target_busy_call = chat_find_user_active_call($pdo, $target_id);
            if ($target_busy_call) {
                $pdo->rollBack();
                echo json_encode([
                    'status' => 'busy',
                    'message' => 'المستخدم مشغول حاليا في مكالمة أخرى.',
                    'busy_reason' => $target_busy_call['group_id'] ? 'group_call' : 'in_call'
                ]);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO internal_calls (caller_id, receiver_id, call_type, status) VALUES (?, ?, ?, 'calling')");
            $stmt->execute([$current_user_id, $target_id, $call_type]);
        } else if ($target_type == 'group') {
            $member_check = $pdo->prepare("SELECT id FROM internal_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
            $member_check->execute([$target_id, $current_user_id]);
            if (!$member_check->fetch()) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'ليست لديك صلاحية بدء مكالمة داخل هذه المجموعة']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO internal_calls (caller_id, group_id, call_type, status) VALUES (?, ?, ?, 'calling')");
            $stmt->execute([$current_user_id, $target_id, $call_type]);
        }

        $call_id = $pdo->lastInsertId();
        $pdo->commit();
        echo json_encode(['status' => 'success', 'call_id' => $call_id]);
        exit();
    }

    // تحديث حالة المكالمة
    if ($action == 'update_call_status') {
        chat_expire_stale_calls($pdo);

        $call_id = (int)($_POST['call_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        $allowed = ['calling', 'ringing', 'accepted', 'rejected', 'busy', 'cancelled', 'missed', 'ended', 'expired'];
        if ($call_id <= 0 || !in_array($status, $allowed, true)) {
            echo json_encode(['status' => 'error', 'message' => 'حالة غير صالحة']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM internal_calls
            WHERE id = ?
              AND (caller_id = ? OR receiver_id = ? OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?))
            LIMIT 1
        ");
        $stmt->execute([$call_id, $current_user_id, $current_user_id, $current_user_id]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$call) {
            echo json_encode(['status' => 'error', 'message' => 'Call not found']);
            exit();
        }

        $data = ['status' => $status];
        if ($status == 'accepted') {
            $data['answered_at'] = date('Y-m-d H:i:s');
        }
        if (in_array($status, ['rejected', 'busy', 'cancelled', 'missed', 'ended', 'expired'], true)) {
            $data['ended_at'] = date('Y-m-d H:i:s');
            // حساب المدة
            $start = new DateTime($call['answered_at'] ?: $call['started_at']);
            $end = new DateTime();
            $data['duration'] = $end->getTimestamp() - $start->getTimestamp();
        }

        $fields = array_keys($data);
        $set_clause = implode(' = ?, ', $fields) . ' = ?';
        $stmt = $pdo->prepare("UPDATE internal_calls SET $set_clause WHERE id = ?");
        $values = array_values($data);
        $values[] = $call_id;
        $stmt->execute($values);

        echo json_encode(['status' => 'success']);
        exit();
    }

    // جلب قائمة المكالمات
    if ($action == 'get_call_status') {
        chat_expire_stale_calls($pdo);

        $call_id = (int)($_GET['call_id'] ?? 0);
        if ($call_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid call id']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT id, caller_id, receiver_id, group_id, call_type, status, answered_at, ended_at, duration
            FROM internal_calls
            WHERE id = ?
              AND (caller_id = ? OR receiver_id = ? OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?))
            LIMIT 1
        ");
        $stmt->execute([$call_id, $current_user_id, $current_user_id, $current_user_id]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$call) {
            echo json_encode(['status' => 'error', 'message' => 'Call not found']);
            exit();
        }

        echo json_encode(['status' => 'success', 'call' => $call]);
        exit();
    }

    if ($action == 'get_active_call') {
        chat_expire_stale_calls($pdo);

        $call_id = (int)($_GET['call_id'] ?? 0);
        if ($call_id <= 0) {
            echo json_encode(['status' => 'success', 'call' => null]);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT id, caller_id, receiver_id, group_id, call_type, status, answered_at, ended_at, duration, created_at
            FROM internal_calls
            WHERE id = ?
              AND status IN ('calling', 'ringing', 'accepted')
              AND (caller_id = ? OR receiver_id = ? OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?))
            LIMIT 1
        ");
        $stmt->execute([$call_id, $current_user_id, $current_user_id, $current_user_id]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'call' => ($call && chat_call_is_active($call)) ? $call : null]);
        exit();
    }

    if ($action == 'get_calls') {
        chat_expire_stale_calls($pdo);

        $sql = "
            SELECT c.*,
                   caller.full_name as caller_name, caller.profile_image as caller_image,
                   receiver.full_name as receiver_name, receiver.profile_image as receiver_image,
                   g.name as group_name
            FROM internal_calls c
            JOIN users caller ON c.caller_id = caller.id
            LEFT JOIN users receiver ON c.receiver_id = receiver.id
            LEFT JOIN internal_groups g ON c.group_id = g.id
            WHERE c.caller_id = ? OR c.receiver_id = ? OR c.group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?)
            ORDER BY c.created_at DESC
            LIMIT 50
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
        $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'calls' => $calls]);
        exit();
    }

    // جلب مكالمات فائتة
    if ($action == 'get_user_status') {
        chat_expire_stale_calls($pdo);

        $user_id = (int)($_GET['user_id'] ?? $current_user_id);

        // Check if user is in an active call
        $stmt = $pdo->prepare("
            SELECT status FROM internal_calls
            WHERE status IN ('calling', 'ringing', 'accepted')
            AND (caller_id = ? OR receiver_id = ? OR group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?))
            LIMIT 1
        ");
        $stmt->execute([$user_id, $user_id, $user_id]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check if user is online
        $online_stmt = $pdo->prepare("SELECT is_online, last_seen FROM users WHERE id = ?");
        $online_stmt->execute([$user_id]);
        $user_data = $online_stmt->fetch(PDO::FETCH_ASSOC);

        $status = 'available';
        if ($call) {
            if ($call['status'] == 'ringing') {
                $status = 'ringing';
            } else if ($call['status'] == 'accepted') {
                $status = 'in_call';
            } else {
                $status = 'busy';
            }
        } else if (!$user_data || $user_data['is_online'] != 1) {
            $status = 'offline';
        }

        echo json_encode([
            'status' => 'success',
            'user_status' => $status,
            'last_seen' => $user_data['last_seen'] ?? null
        ]);
        exit();
    }

    if ($action == 'get_missed_calls') {
        chat_expire_stale_calls($pdo);

        $sql = "
            SELECT c.*,
                   caller.full_name as caller_name, caller.profile_image as caller_image,
                   receiver.full_name as receiver_name, receiver.profile_image as receiver_image,
                   g.name as group_name
            FROM internal_calls c
            JOIN users caller ON c.caller_id = caller.id
            LEFT JOIN users receiver ON c.receiver_id = receiver.id
            LEFT JOIN internal_groups g ON c.group_id = g.id
            WHERE (c.receiver_id = ? OR c.group_id IN (SELECT group_id FROM internal_group_members WHERE user_id = ?)) AND c.status = 'missed'
            ORDER BY c.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$current_user_id, $current_user_id]);
        $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'missed_calls' => $calls]);
        exit();
    }

    if ($action == 'cleanup_my_calls') {
        chat_expire_stale_calls($pdo);
        chat_finish_user_active_calls($pdo, (int)$current_user_id, 'ended');
        echo json_encode(['status' => 'success']);
        exit();
    }
}

if (!$current_user_id) {
    header('Location: login.php');
    exit();
}

// تحديث حالة الاتصال وآخر ظهور
$pdo->prepare("UPDATE users SET last_seen = NOW(), is_online = 1 WHERE id = ?")->execute([$current_user_id]);

$current_user_id = $_SESSION['admin_id'];

require_once 'header.php';

// جلب إعدادات التنبيهات للمستخدم الحالي
$notification_settings = $pdo->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
$notification_settings->execute([$current_user_id]);
$settings = $notification_settings->fetch();
?>

<div class="container-fluid chat-app-container">
    <div class="row h-100 g-0">
        <!-- القائمة الجانبية -->
        <div id="sidePanel" class="col-md-3 border-end chat-sidebar">
            <div class="p-3">
                <!-- العنوان الرئيسي -->
                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0"><i class="fas fa-comments me-2 text-primary"></i> النظام الجديد</h5>
                </div>

                <!-- علامات التبويب -->
                <ul class="nav nav-pills nav-fill mb-3" id="sidebarTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-chats" data-tab="chats" type="button" role="tab">
                            <i class="fas fa-comment-dots me-1"></i> المحادثات
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-groups" data-tab="groups" type="button" role="tab">
                            <i class="fas fa-users me-1"></i> المجموعات
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-calls" data-tab="calls" type="button" role="tab">
                            <i class="fas fa-phone-alt me-1"></i> المكالمات
                            <span id="missedCallsBadge" class="badge bg-danger ms-1 d-none">0</span>
                        </button>
                    </li>
                </ul>

                <!-- زر الإضافة -->
                <div class="mb-3 d-flex gap-2">
                    <button id="addChatBtn" class="btn btn-primary flex-grow-1 rounded-pill">
                        <i class="fas fa-plus me-1"></i> محادثة جديدة
                    </button>
                    <?php if ($is_admin): ?>
                        <button class="btn btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#createGroupModal" title="مجموعة جديدة">
                            <i class="fas fa-user-friends"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- محتوى التبويبات -->
                <div class="tab-content" id="sidebarTabContent">
                    <!-- تبويب المحادثات -->
                    <div class="tab-pane fade show active" id="tabContent-chats" role="tabpanel">
                        <div id="usersList" class="list-group shadow-sm rounded"></div>
                    </div>

                    <!-- تبويب المجموعات -->
                    <div class="tab-pane fade" id="tabContent-groups" role="tabpanel">
                        <div id="groupsList" class="list-group shadow-sm rounded"></div>
                    </div>

                    <!-- تبويب المكالمات -->
                    <div class="tab-pane fade" id="tabContent-calls" role="tabpanel">
                        <div id="callsList" class="list-group shadow-sm rounded"></div>
                    </div>
                </div>

                <!-- إعدادات التنبيهات -->
                <div class="mt-3 p-3 bg-light rounded">
                    <h6 class="fw-bold mb-2"><i class="fas fa-bell me-1"></i> التنبيهات</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="soundToggle" <?php echo (isset($settings['sound_enabled']) && $settings['sound_enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="soundToggle">صوت التنبيه</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="notificationToggle" <?php echo (isset($settings['notification_enabled']) && $settings['notification_enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="notificationToggle">التنبيهات</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- منطقة المحادثة -->
        <div id="mainChatPanel" class="col-md-9 d-flex flex-column chat-main-panel">
            <!-- رأس المحادثة -->
            <div id="chatHeader" class="p-3 border-bottom bg-light d-flex align-items-center chat-header-bar">
                <button id="backToListBtn" class="btn btn-link p-0 me-3 d-md-none text-dark text-decoration-none">
                    <i class="fas fa-arrow-right fa-lg"></i>
                </button>
                <h5 id="chatTitle" class="mb-0 flex-grow-1">اختر محادثة للبدء</h5>
                <div id="callButtons" class="d-none gap-2 me-2">
                    <button id="audioCallBtn" class="chat-header-icon" title="مكالمة صوتية">
                        <i class="fas fa-phone-alt"></i>
                    </button>
                    <button id="videoCallBtn" class="chat-header-icon" title="مكالمة فيديو">
                        <i class="fas fa-video"></i>
                    </button>
                </div>
                <div class="dropdown">
                    <button type="button" id="chatMenuBtn" class="chat-header-icon" data-bs-toggle="dropdown" aria-expanded="false" title="خيارات المحادثة">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end chat-options-menu shadow">
                        <li><a class="dropdown-item" href="#" data-chat-menu="info"><i class="fas fa-user-circle me-2"></i> معلومات المستخدم</a></li>
                        <li><a class="dropdown-item" href="#" data-chat-menu="search"><i class="fas fa-search me-2"></i> البحث داخل المحادثة</a></li>
                        <li><a class="dropdown-item" href="#" data-chat-menu="select"><i class="fas fa-check-double me-2"></i> تحديد الرسائل</a></li>
                        <li><a class="dropdown-item" href="#" data-chat-menu="media"><i class="fas fa-photo-video me-2"></i> الوسائط والملفات</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger" href="#" data-chat-menu="delete_all"><i class="fas fa-trash-alt me-2"></i> حذف جميع الرسائل</a></li>
                        <li><a class="dropdown-item text-danger" href="#" data-chat-menu="delete_chat"><i class="fas fa-comment-slash me-2"></i> حذف المحادثة</a></li>
                        <li><a class="dropdown-item" href="#" data-chat-menu="export"><i class="fas fa-file-export me-2"></i> تصدير المحادثة</a></li>
                        <li><a class="dropdown-item" href="#" data-chat-menu="mute"><i class="fas fa-bell-slash me-2"></i> كتم الإشعارات</a></li>
                        <li><a class="dropdown-item text-danger" href="#" data-chat-menu="block"><i class="fas fa-ban me-2"></i> حظر المستخدم</a></li>
                        <li><a class="dropdown-item text-danger" href="#" data-chat-menu="report"><i class="fas fa-flag me-2"></i> الإبلاغ</a></li>
                    </ul>
                </div>
            </div>

            <!-- منطقة الرسائل -->
            <div id="chatBulkActions" class="chat-selection-toolbar d-none">
                <button type="button" id="cancelSelectionBtn" class="chat-header-icon" title="إلغاء التحديد"><i class="fas fa-times"></i></button>
                <strong id="selectedMessagesCount" class="selection-count">0 محددة</strong>
                <span class="flex-grow-1"></span>
                <button type="button" id="replySelectedMessagesBtn" class="chat-header-icon" title="رد"><i class="fas fa-reply"></i></button>
                <button type="button" id="copySelectedMessagesBtn" class="chat-header-icon" title="نسخ"><i class="fas fa-copy"></i></button>
                <button type="button" id="forwardSelectedMessagesBtn" class="chat-header-icon" title="إعادة توجيه"><i class="fas fa-share"></i></button>
                <button type="button" id="shareSelectedMessagesBtn" class="chat-header-icon" title="مشاركة"><i class="fas fa-share-alt"></i></button>
                <button type="button" id="deleteSelectedMessagesBtn" class="chat-header-icon text-danger" title="حذف"><i class="fas fa-trash-alt"></i></button>
                <button type="button" id="deleteAllMessagesBtn" class="d-none" aria-hidden="true"></button>
                <button type="button" id="toggleSelectMessagesBtn" class="d-none" aria-hidden="true"></button>
            </div>
            <div id="messagesContainer" class="p-3">
                <div class="text-center text-muted mt-5">
                    <i class="fas fa-comments fa-3x mb-2"></i>
                    <p>اختر محادثة من القائمة الجانبية</p>
                </div>
            </div>

            <!-- صندوق الكتابة والإرسال -->
            <div class="p-3 border-top bg-white">
                <div id="presenceIndicator" class="presence-indicator d-none"></div>
                <div id="editComposerBar" class="edit-composer-bar d-none">
                    <div><i class="fas fa-edit me-1"></i><span>تعديل الرسالة</span></div>
                    <button type="button" id="cancelEditBtn" class="btn btn-sm btn-link text-muted p-0"><i class="fas fa-times"></i></button>
                </div>
                <div id="attachmentPreview" class="attachment-preview d-none"></div>
                <form id="messageForm" class="d-flex gap-2 composer-shell">
                    <button type="button" id="attachBtn" class="composer-action-btn" title="إرفاق ملف"><i class="fas fa-paperclip"></i></button>
                    <div class="flex-grow-1 position-relative">
                        <textarea id="messageInput" class="form-control rounded-pill" placeholder="اكتب رسالتك هنا..." rows="1" style="resize: none; padding-right: 45px;"></textarea>
                        <label for="imageUpload" class="position-absolute" style="right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #007bff;">
                            <i class="fas fa-image fa-lg"></i>
                            <input type="file" id="imageUpload" class="d-none" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
                        </label>
                        <input type="file" id="videoUpload" class="d-none" accept="video/*">
                        <input type="file" id="documentUpload" class="d-none" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
                        <input type="file" id="audioUpload" class="d-none" accept=".mp3,.wav,.aac,.ogg,.m4a,audio/mpeg,audio/wav,audio/aac,audio/ogg,audio/mp4">
                    </div>
                    <button type="button" id="recordBtn" class="composer-action-btn composer-primary-action" title="تسجيل صوتي"><i class="fas fa-microphone"></i></button>
                    <div id="voiceGestureHint" class="voice-gesture-hint d-none">
                        <i class="fas fa-lock"></i>
                        <span>اسحب للأعلى للتثبيت</span>
                    </div>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 composer-submit-btn" style="height: 40px; display: flex; align-items: center;">
                        <i class="fas fa-paper-plane me-1"></i> إرسال
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="attachmentSheetBackdrop" class="attachment-sheet-backdrop d-none"></div>
<div id="attachmentSheet" class="attachment-sheet d-none" aria-hidden="true">
    <div class="attachment-sheet-handle"></div>
    <div class="attachment-sheet-title">إرفاق</div>
    <div class="attachment-grid">
        <button type="button" class="attachment-choice" data-attach-choice="image"><span class="choice-icon bg-pink"><i class="fas fa-image"></i></span><span>صورة</span></button>
        <button type="button" class="attachment-choice" data-attach-choice="video"><span class="choice-icon bg-purple"><i class="fas fa-video"></i></span><span>فيديو</span></button>
        <button type="button" class="attachment-choice" data-attach-choice="document"><span class="choice-icon bg-blue"><i class="fas fa-file-alt"></i></span><span>مستند</span></button>
        <button type="button" class="attachment-choice" data-attach-choice="audio"><span class="choice-icon bg-orange"><i class="fas fa-music"></i></span><span>ملف صوتي</span></button>
        <button type="button" class="attachment-choice" data-attach-choice="camera"><span class="choice-icon bg-green"><i class="fas fa-camera"></i></span><span>الكاميرا</span></button>
        <button type="button" class="attachment-choice" data-attach-choice="location"><span class="choice-icon bg-red"><i class="fas fa-map-marker-alt"></i></span><span>الموقع</span></button>
        <button type="button" class="attachment-choice" data-attach-choice="contact"><span class="choice-icon bg-teal"><i class="fas fa-address-book"></i></span><span>جهة اتصال</span></button>
    </div>
</div>

<div class="modal fade" id="cameraModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content camera-modal-content">
            <div class="modal-header">
                <h5 class="modal-title">الكاميرا</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <video id="cameraPreview" class="camera-preview" autoplay playsinline muted></video>
                <canvas id="cameraCanvas" class="d-none"></canvas>
                <div id="cameraStatus" class="small text-muted mt-2"></div>
            </div>
            <div class="modal-footer justify-content-center gap-2">
                <button type="button" id="switchCameraBtn" class="btn btn-outline-secondary rounded-pill"><i class="fas fa-sync-alt me-1"></i> تبديل الكاميرا</button>
                <button type="button" id="capturePhotoBtn" class="btn btn-primary rounded-pill"><i class="fas fa-camera me-1"></i> التقاط صورة</button>
                <button type="button" id="recordVideoBtn" class="btn btn-outline-danger rounded-pill"><i class="fas fa-circle me-1"></i> تسجيل فيديو</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal لإنشاء مجموعة جديدة -->
<div class="modal fade" id="createGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء مجموعة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">اسم المجموعة</label>
                    <input type="text" id="groupName" class="form-control" placeholder="أدخل اسم المجموعة">
                </div>
                <div class="mb-3">
                    <label class="form-label">الوصف (اختياري)</label>
                    <textarea id="groupDescription" class="form-control" placeholder="أدخل وصف المجموعة" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">اختر الأعضاء</label>
                    <div id="membersList" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" id="createGroupBtn">إنشاء</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal لتعديل المجموعة -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تعديل المجموعة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editGroupId">
                <div class="mb-3">
                    <label class="form-label">اسم المجموعة</label>
                    <input type="text" id="editGroupName" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">الوصف</label>
                    <textarea id="editGroupDescription" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">إدارة الأعضاء</label>
                    <div id="editMembersList" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-danger" id="deleteGroupBtn">حذف المجموعة</button>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn btn-primary" id="updateGroupBtn">حفظ التغييرات</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Audio elements for calls -->
<audio id="ringtoneAudio" preload="auto"></audio>
<audio id="dialtoneAudio" preload="auto"></audio>

<!-- Modal للمكالمات -->
<div class="modal fade" id="callModal" tabindex="-1" aria-labelledby="callModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen">
        <div class="modal-content call-modal-content">
            <div class="modal-body call-modal-body p-0">
                <!-- الخلفية -->
                <div class="call-background">
                    <div class="gradient-circle circle-1"></div>
                    <div class="gradient-circle circle-2"></div>
                    <div class="gradient-circle circle-3"></div>
                </div>

                <!-- إشعارات الأحداث -->
                <div class="call-notifications" id="callNotifications"></div>

                <!-- عرض المشاركين -->
                <div class="participants-container" id="participantsContainer"></div>

                <!-- المستقبل البعيد (كامل الشاشة) -->
                <div class="remote-video-wrapper" id="remoteVideoWrapper">
                    <video id="remoteVideo" autoplay playsinline class="remote-video"></video>
                    <div class="remote-placeholder" id="remotePlaceholder">
                        <div class="call-glass-panel">
                            <div id="callTypeBadge" class="call-type-badge">
                                <i id="callTypeIcon" class="fas fa-phone-alt"></i>
                                <span id="callTypeText">مكالمة صوتية</span>
                            </div>
                            <div id="callAvatarWrap" class="call-avatar-wrap is-ringing">
                                <img id="callAvatarImg" class="call-avatar-img d-none" alt="">
                                <i id="callAvatarIcon" class="fas fa-user"></i>
                            </div>
                            <h3 id="callingUser" class="call-user-name">جاري الاتصال...</h3>
                            <div class="call-status text-light">
                                <i class="fas fa-circle fa-xs text-success me-1"></i>
                                <span id="callStatusText">جاري الاتصال</span>
                            </div>
                            <div id="callMetaGrid" class="call-meta-grid">
                                <div><span>المدة</span><strong id="callDurationText">00:00</strong></div>
                                <div><span>الجودة</span><strong id="callQualityText">جيدة</strong></div>
                                <div><span>الشبكة</span><strong id="callNetworkText">مستقرة</strong></div>
                                <div><span>المشاركين</span><strong id="callParticipantsText">1</strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- المستخدم الحالي (دائرة صغيرة) -->
                <div class="local-video-wrapper" id="localVideoWrapper">
                    <video id="localVideo" autoplay playsinline muted class="local-video"></video>
                    <div class="local-placeholder">
                        <i class="fas fa-user-circle fa-2x"></i>
                    </div>
                </div>

                <!-- زر الخروج (أعلى اليمين) -->
                <button type="button" class="call-close-btn" data-bs-dismiss="modal" aria-label="إغلاق">
                    <i class="fas fa-arrow-right fa-lg"></i>
                </button>

                <!-- ============================================================
                     واجهة أزرار التحكم في المكالمات - نظام ديناميكي متكامل
                     يتغير تلقائياً حسب حالة المكالمة:
                     1. واردة  → رد + رفض فقط
                     2. صادرة  → إنهاء + مكبر + ميكروفون
                     3. جارية  → إنهاء + ميكروفون + مكبر + (كاميرا + تبديل + شاشة للفيديو)
                     ============================================================ -->

                <!-- ── حالة 1: مكالمة واردة ── -->
                <div id="incomingCallUI" class="call-controls-bar incoming-bar" style="display:none;">
                    <p class="call-bar-label" id="incomingCallLabel">مكالمة واردة</p>
                    <div class="call-btns-row">
                        <div class="call-btn-wrap">
                            <button type="button" id="rejectCallBtn" class="call-fab call-fab--reject" title="رفض المكالمة" aria-label="رفض المكالمة">
                                <i class="fas fa-phone-slash"></i>
                            </button>
                            <span class="call-btn-label">رفض</span>
                        </div>
                        <div class="call-btn-wrap">
                            <button type="button" id="acceptCallBtn" class="call-fab call-fab--accept call-fab--pulse" title="قبول المكالمة" aria-label="قبول المكالمة">
                                <i class="fas fa-phone"></i>
                            </button>
                            <span class="call-btn-label">رد</span>
                        </div>
                    </div>
                </div>

                <!-- ── حالة 2 & 3: مكالمة صادرة / جارية ── -->
                <div id="callControls" class="call-controls-bar active-bar" style="display:none;">
                    <div class="call-btns-row" id="callBtnsRow">
                        <div class="call-btn-wrap">
                            <button type="button" id="toggleAudioBtn" class="call-fab call-fab--ctrl" title="كتم/تشغيل الميكروفون" aria-label="كتم الميكروفون">
                                <i class="fas fa-microphone"></i>
                            </button>
                            <span class="call-btn-label" id="audioLabel">ميكروفون</span>
                        </div>
                        <div class="call-btn-wrap">
                            <button type="button" id="toggleSpeakerBtn" class="call-fab call-fab--ctrl" title="تشغيل/إيقاف مكبر الصوت" aria-label="مكبر الصوت">
                                <i class="fas fa-volume-up"></i>
                            </button>
                            <span class="call-btn-label" id="speakerLabel">مكبر</span>
                        </div>
                        <div class="call-btn-wrap">
                            <button type="button" id="endCallBtn" class="call-fab call-fab--end" title="إنهاء المكالمة" aria-label="إنهاء المكالمة">
                                <i class="fas fa-phone-slash"></i>
                            </button>
                            <span class="call-btn-label">إنهاء</span>
                        </div>
                        <div class="call-btn-wrap call-video-only" id="toggleVideoBtnWrap" style="display:none;">
                            <button type="button" id="toggleVideoBtn" class="call-fab call-fab--ctrl" title="تشغيل/إيقاف الكاميرا" aria-label="الكاميرا">
                                <i class="fas fa-video"></i>
                            </button>
                            <span class="call-btn-label" id="videoLabel">كاميرا</span>
                        </div>

                        <!-- زر المزيد -->
                        <div class="call-btn-wrap" id="moreControlsBtnWrap">
                            <button type="button" id="moreControlsBtn" class="call-fab call-fab--ctrl" title="المزيد">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <span class="call-btn-label">المزيد</span>
                        </div>

                        <!-- القائمة المنبثقة للمزيد -->
                        <div id="moreControlsMenu" class="more-controls-menu" style="display: none;">
                            <div class="more-menu-item call-video-only" id="switchCameraBtnMore" style="display:none;" onclick="switchCamera()">
                                <i class="fas fa-sync-alt"></i>
                                <span>تبديل الكاميرا</span>
                            </div>
                            <div class="more-menu-item call-video-only" id="shareScreenBtnMore" style="display:none;" onclick="shareScreen()">
                                <i class="fas fa-desktop"></i>
                                <span>مشاركة الشاشة</span>
                            </div>
                            <div class="more-menu-item" onclick="raiseHand()">
                                <i class="fas fa-hand"></i>
                                <span>رفع اليد</span>
                            </div>
                            <div class="more-menu-item" onclick="toggleReactions()">
                                <i class="fas fa-smile"></i>
                                <span>التفاعلات</span>
                            </div>
                        </div>
                    </div>
                </div>


            </div>
        </div>
    </div>
</div>

<style>
    .justify-content-between {
        justify-content: space-between !important;
    }

    .badge {
        font-size: 0.75rem;
        padding: 0.25em 0.5em;
    }

    .bg-danger {
        background-color: #dc3545 !important;
    }

    .rounded-pill {
        border-radius: 50rem !important;
    }

    .message-bubble {
        position: relative;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05) !important;
        border-radius: 12px !important;
    }

    .msg-own {
        background-color: var(--primary-color) !important;
        color: white !important;
        border: none !important;
    }

    .msg-own .fa-check,
    .msg-own .fa-check-double {
        color: rgba(255, 255, 255, 0.8) !important;
    }

    .msg-own .text-white {
        color: #fff !important;
    }

    .msg-other .fa-check,
    .msg-other .fa-check-double {
        color: #6c757d !important;
    }

    .msg-other {
        background-color: #ffffff !important;
        color: #212529 !important;
        border: 1px solid #dee2e6 !important;
    }

    body.theme-dark .msg-other {
        background-color: #1e2d45 !important;
        color: #f1f5f9 !important;
        border-color: #334155 !important;
    }

    /* Dark Mode Overrides */
    body.theme-dark .chat-app-container {
        background-color: #0b1120 !important;
    }

    body.theme-dark .chat-sidebar {
        background-color: #0b1120 !important;
        border-color: #1e2d45 !important;
    }

    body.theme-dark .chat-main-panel {
        background-color: #0b1120 !important;
    }

    body.theme-dark .list-group-item {
        background-color: transparent;
        border-color: #1e2d45;
        color: #e2e8f0;
    }

    body.theme-dark .list-group-item:hover {
        background-color: #1e2d45;
        color: #fff;
    }

    body.theme-dark .list-group-item.active {
        background-color: var(--primary-color) !important;
        border-color: var(--primary-color) !important;
    }

    body.theme-dark .chat-sidebar .bg-light {
        background-color: #111827 !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark .chat-main-panel .bg-light {
        background-color: #111827 !important;
        border-color: #1e2d45 !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark #messagesContainer {
        background-color: #0b1120 !important;
    }

    body.theme-dark .bg-white {
        background-color: #111827 !important;
        border-color: #1e2d45 !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark .chat-main-panel>.border-top.bg-white {
        background-color: #111827 !important;
        border-color: #1e2d45 !important;
    }

    body.theme-dark #messageForm .bg-white {
        background-color: transparent !important;
    }

    body.theme-dark .form-control {
        background-color: #1e2d45;
        border-color: #334155;
        color: #f1f5f9;
    }

    body.theme-dark .form-control::placeholder {
        color: #64748b;
    }

    body.theme-dark .text-muted {
        color: #94a3b8 !important;
    }

    body.theme-dark .text-dark {
        color: #f1f5f9 !important;
    }

    body.theme-dark .modal-content {
        background-color: #111827;
        color: #f1f5f9;
        border-color: #1e2d45;
    }

    body.theme-dark .modal-header,
    body.theme-dark .modal-footer {
        border-color: #1e2d45;
    }

    body.theme-dark .message-actions .btn-link {
        background: rgba(255, 255, 255, 0.05);
        color: #94a3b8 !important;
    }

    body.theme-dark .message-actions .btn-link:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff !important;
    }

    body.theme-dark .dropdown-menu {
        background-color: #1e293b;
        border: 1px solid #334155;
    }

    body.theme-dark .dropdown-item {
        color: #f1f5f9;
    }

    body.theme-dark .dropdown-item:hover {
        background-color: #334155;
    }

    /* Mobile Dark Mode */
    @media (max-width: 767.98px) {
        body.theme-dark .chat-main-panel {
            background-color: #0b1120;
        }

        body.theme-dark .chat-main-panel>div:last-child {
            background-color: #111827 !important;
            border-color: #1e2d45 !important;
        }
    }

    .message-wrapper {
        position: relative;
        max-width: 75%;
        display: flex;
        align-items: flex-start;
    }

    .justify-content-end .message-wrapper {
        flex-direction: row-reverse;
    }

    .message-actions {
        opacity: 0;
        transition: all 0.2s ease;
        visibility: hidden;
        margin: 0 5px;
    }

    .chat-bulk-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .chat-header-bar {
        min-height: 66px;
        gap: 10px;
        background: #f8fafc !important;
    }

    .chat-header-icon {
        width: 38px;
        height: 38px;
        border: 0;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #334155;
        background: transparent;
        transition: background 0.18s ease, transform 0.18s ease, color 0.18s ease;
    }

    .chat-header-icon:hover {
        background: rgba(15, 23, 42, 0.08);
        color: #0f766e;
        transform: translateY(-1px);
    }

    .chat-options-menu {
        min-width: 230px;
        padding: 8px;
        border-radius: 12px;
    }

    .chat-options-menu .dropdown-item {
        padding: 9px 10px;
        border-radius: 9px;
    }

    .chat-selection-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 56px;
        padding: 8px 14px;
        color: #0f172a;
        background: #e0f2fe;
        border-bottom: 1px solid #bae6fd;
        animation: slideDown 0.18s ease;
    }

    .selection-count {
        font-size: 0.95rem;
        white-space: nowrap;
    }

    .message-select-holder {
        width: 30px;
        min-width: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .message-select-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }

    .message-row {
        transition: background 0.18s ease, transform 0.18s ease;
        border-radius: 16px;
        padding: 2px 6px;
    }

    .message-row.is-selected {
        background: rgba(14, 165, 233, 0.16);
    }

    .message-row.is-selection-mode .message-bubble {
        cursor: pointer;
    }

    .message-row.is-selected .message-bubble {
        box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.35), 0 6px 18px rgba(15, 23, 42, 0.12) !important;
        transform: translateY(-1px);
    }

    .mb-3:hover .message-actions {
        opacity: 1;
        visibility: visible;
    }

    .message-actions .btn-link {
        color: #999 !important;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.03);
        text-decoration: none;
    }

    .message-actions .btn-link:hover {
        background: rgba(0, 0, 0, 0.08);
        color: #666 !important;
    }

    .dropdown-menu {
        font-size: 0.85rem;
        border: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        border-radius: 10px;
        padding: 5px;
    }

    .dropdown-item {
        border-radius: 6px;
        padding: 6px 12px;
        transition: all 0.2s;
    }

    .dropdown-item:hover {
        background-color: #f8f9fa;
    }

    .dropdown-item.text-danger:hover {
        background-color: #fff5f5;
    }

    .message-bubble::after {
        content: "";
        position: absolute;
        width: 0;
        height: 0;
        border-top: 10px solid transparent;
        border-bottom: 10px solid transparent;
    }

    .justify-content-start .message-bubble::after {
        left: -10px;
        top: 10px;
        border-right: 10px solid #ffffff;
    }

    .justify-content-end .message-bubble::after {
        right: -10px;
        top: 10px;
        border-left: 10px solid #007bff;
    }

    #messagesContainer::-webkit-scrollbar {
        width: 6px;
    }

    #messagesContainer::-webkit-scrollbar-thumb {
        background-color: #ccc;
        border-radius: 10px;
    }

    .list-group-item.active {
        background-color: #007bff !important;
        border-color: #007bff !important;
    }

    .chat-sidebar,
    .chat-main-panel {
        height: calc(100vh - 100px);
        display: flex;
        flex-direction: column;
    }

    .chat-app-container {
        background: #f4f7fb;
    }

    .chat-sidebar {
        background: #ffffff;
    }

    .chat-main-panel {
        background: linear-gradient(180deg, #f8fbff 0%, #edf3f8 100%);
    }

    #messagesContainer {
        background:
            radial-gradient(circle at top left, rgba(14, 165, 233, 0.08), transparent 32%),
            radial-gradient(circle at bottom right, rgba(34, 197, 94, 0.08), transparent 28%),
            #f7fafc;
    }

    .composer-shell {
        position: relative;
        align-items: flex-end;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        padding: 8px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    }

    .composer-action-btn,
    .composer-send-btn {
        width: 40px;
        height: 40px;
        border: 0;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        transition: transform 0.15s ease, background 0.15s ease, color 0.15s ease;
    }

    .composer-action-btn {
        background: #ffffff;
        color: #334155;
        border: 1px solid #e2e8f0;
    }

    .composer-action-btn:hover {
        background: #e0f2fe;
        color: #0369a1;
        transform: translateY(-1px);
    }

    .composer-action-btn.is-recording {
        background: #fee2e2;
        color: #b91c1c;
        animation: pulseRecord 1s infinite;
    }

    .composer-action-btn.is-locked-recording {
        background: #dc2626;
        color: #fff;
        border-color: #dc2626;
        animation: none;
    }

    .voice-gesture-hint {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 10px);
        transform: translateX(-50%);
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 12px;
        border-radius: 999px;
        color: #0f172a;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid #e2e8f0;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
        font-size: 0.82rem;
        font-weight: 700;
        pointer-events: none;
        white-space: nowrap;
        z-index: 3;
    }

    .voice-gesture-hint.is-lock-target {
        color: #fff;
        background: #2563eb;
        border-color: #2563eb;
    }

    .composer-send-btn {
        background: var(--primary-color, #0d6efd);
        color: #ffffff;
    }

    .composer-input {
        min-height: 40px;
        max-height: 120px;
        resize: none;
        border: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 9px 12px !important;
        border-radius: 12px !important;
    }

    .composer-shell label[for="imageUpload"] {
        display: none !important;
    }

    .attachment-preview,
    .edit-composer-bar,
    .presence-indicator {
        margin-bottom: 8px;
        border-radius: 14px;
        padding: 8px 12px;
    }

    .attachment-preview {
        background: #f1f5f9;
        border: 1px solid #dbe4ee;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-size: 0.88rem;
    }

    .edit-composer-bar {
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #9a3412;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.88rem;
    }

    .presence-indicator {
        color: #0284c7;
        background: rgba(224, 242, 254, 0.86);
        border: 1px solid #bae6fd;
        font-size: 0.82rem;
        width: fit-content;
    }

    .media-attachment {
        overflow: hidden;
        border-radius: 12px;
        margin-bottom: 8px;
        background: rgba(15, 23, 42, 0.06);
    }

    .media-attachment img,
    .media-attachment video {
        display: block;
        max-width: 100%;
        max-height: 320px;
        border-radius: 12px;
    }

    .media-attachment audio {
        width: min(320px, 70vw);
        display: block;
    }

    .voice-attachment {
        width: min(285px, 68vw);
        margin-bottom: 4px;
    }

    .voice-player {
        display: flex;
        align-items: center;
        gap: 9px;
        min-height: 46px;
        padding: 7px 10px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.18);
    }

    .msg-own .voice-player.is-unheard {
        background: rgba(255, 255, 255, 0.24);
    }

    .msg-own .voice-player.is-heard {
        background: rgba(219, 234, 254, 0.34);
    }

    .msg-other .voice-player {
        background: #eef2f7;
    }

    body.theme-dark .msg-other .voice-player {
        background: #27364f;
    }

    .voice-state-dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        flex: 0 0 auto;
        background: #94a3b8;
    }

    .voice-player.is-heard .voice-state-dot {
        background: #38bdf8;
        box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
    }

    .voice-player audio {
        width: 100%;
        height: 34px;
        display: block;
        min-width: 0;
    }

    .voice-player audio::-webkit-media-controls-enclosure {
        border-radius: 999px;
        background: transparent;
    }

    .document-attachment {
        display: flex;
        align-items: center;
        gap: 10px;
        color: inherit;
        text-decoration: none;
        padding: 10px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(148, 163, 184, 0.3);
        margin-bottom: 8px;
    }

    .msg-other .document-attachment {
        background: #f8fafc;
    }

    .attachment-sheet-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1050;
        background: rgba(15, 23, 42, 0.32);
        backdrop-filter: blur(2px);
        animation: fadeIn 0.16s ease;
    }

    .attachment-sheet {
        position: fixed;
        left: 50%;
        right: auto;
        bottom: 18px;
        width: min(520px, calc(100vw - 24px));
        z-index: 1051;
        transform: translateX(-50%);
        padding: 12px 14px 16px;
        border-radius: 22px;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
        animation: sheetUp 0.22s cubic-bezier(.2, .8, .2, 1);
    }

    .attachment-sheet-handle {
        width: 44px;
        height: 4px;
        margin: 0 auto 10px;
        border-radius: 999px;
        background: #cbd5e1;
    }

    .attachment-sheet-title {
        font-weight: 700;
        color: #0f172a;
        margin: 0 6px 12px;
    }

    .attachment-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(72px, 1fr));
        gap: 10px;
    }

    .attachment-choice {
        border: 0;
        background: transparent;
        border-radius: 16px;
        padding: 10px 6px;
        display: grid;
        place-items: center;
        gap: 7px;
        color: #334155;
        font-size: 0.84rem;
        transition: transform 0.18s ease, background 0.18s ease;
    }

    .attachment-choice:hover {
        background: #f1f5f9;
        transform: translateY(-3px);
    }

    .choice-icon {
        width: 48px;
        height: 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        color: #fff;
        font-size: 1.2rem;
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
        transition: transform 0.18s ease;
    }

    .attachment-choice:hover .choice-icon {
        transform: rotate(-4deg) scale(1.05);
    }

    .bg-pink {
        background: linear-gradient(135deg, #ec4899, #f97316);
    }

    .bg-purple {
        background: linear-gradient(135deg, #7c3aed, #2563eb);
    }

    .bg-blue {
        background: linear-gradient(135deg, #0284c7, #06b6d4);
    }

    .bg-orange {
        background: linear-gradient(135deg, #f59e0b, #ef4444);
    }

    .bg-green {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .bg-red {
        background: linear-gradient(135deg, #ef4444, #e11d48);
    }

    .bg-teal {
        background: linear-gradient(135deg, #14b8a6, #0f766e);
    }

    .camera-modal-content {
        overflow: hidden;
        border-radius: 18px;
    }

    .camera-preview {
        width: 100%;
        max-height: 62vh;
        aspect-ratio: 16 / 9;
        border-radius: 14px;
        background: #020617;
        object-fit: cover;
    }

    body.theme-dark .chat-header-bar,
    body.theme-dark .chat-selection-toolbar {
        background: #111827 !important;
        color: #e2e8f0;
        border-color: #1e2d45 !important;
    }

    body.theme-dark .chat-header-icon {
        color: #e2e8f0;
    }

    body.theme-dark .attachment-sheet {
        background: rgba(17, 24, 39, 0.98);
        border-color: #334155;
    }

    body.theme-dark .attachment-sheet-title,
    body.theme-dark .attachment-choice {
        color: #f1f5f9;
    }

    body.theme-dark .attachment-choice:hover {
        background: #1e293b;
    }

    body.theme-dark .message-row.is-selected {
        background: rgba(45, 212, 191, 0.16);
    }

    body.theme-dark .composer-shell,
    body.dark-mode .composer-shell {
        background: #0f172a;
        border-color: #24324a;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.28);
    }

    body.theme-dark .composer-action-btn,
    body.dark-mode .composer-action-btn {
        background: #111827;
        color: #dbeafe;
        border-color: #2f405f;
    }

    body.theme-dark .composer-action-btn:hover,
    body.dark-mode .composer-action-btn:hover {
        background: #1e293b;
        color: #93c5fd;
    }

    body.theme-dark .composer-action-btn.is-recording,
    body.dark-mode .composer-action-btn.is-recording {
        background: #7f1d1d;
        color: #fecaca;
        border-color: #991b1b;
    }

    body.theme-dark .composer-action-btn.is-locked-recording,
    body.dark-mode .composer-action-btn.is-locked-recording {
        background: #dc2626;
        color: #fff;
        border-color: #ef4444;
    }

    body.theme-dark .voice-gesture-hint,
    body.dark-mode .voice-gesture-hint {
        color: #e2e8f0;
        background: #111827;
        border-color: #334155;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.34);
    }

    @keyframes pulseRecord {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.35);
        }

        50% {
            box-shadow: 0 0 0 8px rgba(220, 38, 38, 0);
        }
    }

    @keyframes sheetUp {
        from {
            opacity: 0;
            transform: translate(-50%, 24px);
        }

        to {
            opacity: 1;
            transform: translate(-50%, 0);
        }
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .chat-sidebar {
        overflow-y: auto;
    }

    #messagesContainer {
        flex-grow: 1;
        overflow-y: auto;
    }

    /* تحسينات الهواتف */
    @media (max-width: 767.98px) {
        .chat-app-container {
            padding: 0;
            margin: 0;
            height: calc(100vh - 56px);
            overflow: hidden;
        }

        .chat-sidebar {
            width: 100% !important;
            height: 100% !important;
            max-height: none !important;
            display: block;
            border: none !important;
            overflow-y: auto;
        }

        .chat-main-panel {
            width: 100% !important;
            max-height: none !important;
            display: none !important;
            position: fixed;
            top: 56px;
            left: 0;
            right: 0;
            bottom: 0 !important;
            z-index: 1050;
            background: white;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-main-panel.show-mobile {
            display: flex !important;
        }

        .message-wrapper {
            max-width: 90%;
        }

        .message-bubble {
            padding: 8px 12px !important;
            font-size: 0.85rem;
            border-radius: 12px !important;
        }

        #messagesContainer {
            padding: 10px !important;
            flex-grow: 1;
            overflow-y: auto;
            height: 0;
        }

        .chat-sidebar.hide-mobile {
            display: none !important;
        }

        /* تنسيق منطقة الإرسال في الموبايل */
        .chat-main-panel>div:last-child {
            padding: 10px !important;
            background: white;
            border-top: 1px solid #dee2e6;
            margin-bottom: 0 !important;
            position: sticky;
            bottom: 0;
            z-index: 1060;
            flex-shrink: 0;
        }

        #messageForm {
            display: flex !important;
            align-items: center !important;
            flex-wrap: nowrap !important;
            gap: 6px !important;
            width: 100%;
            border-radius: 999px;
            padding: 6px;
        }

        #messageForm .flex-grow-1 {
            min-width: 0;
        }

        #messageInput {
            font-size: 0.9rem;
            padding: 10px 45px 10px 15px !important;
            width: 100%;
            border-radius: 20px !important;
        }

        .composer-action-btn {
            width: 42px;
            height: 42px;
            border-radius: 50%;
        }

        #recordBtn {
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
        }

        #messageForm button[type="submit"] {
            padding: 0 15px !important;
            font-size: 0.85rem;
            flex-shrink: 0;
            height: 40px;
            display: flex !important;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }

        .voice-attachment {
            width: min(240px, 62vw);
        }

        .voice-player {
            min-height: 42px;
            padding: 5px 8px;
        }

        .voice-player audio {
            height: 32px;
        }

        body.theme-dark .chat-main-panel>div:last-child,
        body.dark-mode .chat-main-panel>div:last-child {
            background: #0b1120 !important;
            border-color: #1e2d45 !important;
        }

        body.theme-dark #messageForm,
        body.dark-mode #messageForm {
            background: #0f172a;
            border-color: #24324a;
        }

        #chatTitle {
            font-size: 1rem;
        }

        .chat-bulk-actions {
            width: 100%;
            margin-top: 8px;
            justify-content: flex-start;
        }

        #backToListBtn {
            margin-right: 10px !important;
        }
    }

    /* ================================================================
       أزرار التحكم في المكالمات - تصميم جديد بالكامل
       مشابه لـ WhatsApp / Telegram / Google Meet
       ================================================================ */

    /* شريط الأزرار العام */
    .call-controls-bar {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 25;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        padding-bottom: max(28px, env(safe-area-inset-bottom));
        padding-top: 20px;
        background: linear-gradient(to top, rgba(0, 0, 0, 0.72) 0%, rgba(0, 0, 0, 0.0) 100%);
        animation: callBarIn 0.32s cubic-bezier(.2, .9, .2, 1) both;
    }

    @keyframes callBarIn {
        from {
            opacity: 0;
            transform: translateY(28px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* عنوان الشريط */
    .call-bar-label {
        margin: 0 0 14px;
        font-size: 0.82rem;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.55);
        text-align: center;
    }

    /* صف الأزرار */
    .call-btns-row {
        display: flex;
        align-items: flex-end;
        justify-content: center;
        gap: clamp(18px, 4vw, 36px);
        flex-wrap: wrap;
        padding: 0 16px;
    }

    /* غلاف زر + تسمية */
    .call-btn-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
    }

    /* تسمية الزر */
    .call-btn-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.78);
        text-align: center;
        letter-spacing: 0.02em;
        user-select: none;
    }

    /* ─── الزر الدائري (FAB) الأساسي ─── */
    .call-fab {
        position: relative;
        width: 62px;
        height: 62px;
        border-radius: 50%;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1.35rem;
        color: #fff;
        outline: none;
        -webkit-tap-highlight-color: transparent;
        transition:
            transform 0.18s cubic-bezier(.34, 1.56, .64, 1),
            box-shadow 0.18s ease,
            filter 0.18s ease;
        overflow: hidden;
    }

    /* تأثير الضغط (Ripple) */
    .call-fab::after {
        content: '';
        position: absolute;
        inset: 50% 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.30);
        transform: translate(-50%, -50%);
        opacity: 0;
        transition: width 0.35s ease, height 0.35s ease, opacity 0.35s ease;
        pointer-events: none;
    }

    .call-fab:active::after {
        width: 160%;
        height: 160%;
        opacity: 1;
        transition: 0s;
    }

    .call-fab:hover {
        transform: translateY(-3px) scale(1.06);
        filter: brightness(1.12);
    }

    .call-fab:active {
        transform: scale(0.93);
        filter: brightness(0.95);
    }

    /* ─── أنواع الأزرار ─── */

    /* زر الرد (أخضر كبير) */
    .call-fab--accept {
        width: 74px;
        height: 74px;
        font-size: 1.6rem;
        background: linear-gradient(145deg, #22c55e, #16a34a);
        box-shadow: 0 6px 28px rgba(34, 197, 94, 0.50);
    }

    .call-fab--accept:hover {
        box-shadow: 0 10px 36px rgba(34, 197, 94, 0.65);
    }

    /* زر الرفض (أحمر كبير) */
    .call-fab--reject {
        width: 74px;
        height: 74px;
        font-size: 1.6rem;
        background: linear-gradient(145deg, #ef4444, #dc2626);
        box-shadow: 0 6px 28px rgba(239, 68, 68, 0.50);
    }

    .call-fab--reject:hover {
        box-shadow: 0 10px 36px rgba(239, 68, 68, 0.65);
    }

    /* زر إنهاء المكالمة (أحمر كبير في الوسط) */
    .call-fab--end {
        width: 70px;
        height: 70px;
        font-size: 1.5rem;
        background: linear-gradient(145deg, #ef4444, #b91c1c);
        box-shadow: 0 6px 28px rgba(239, 68, 68, 0.55);
    }

    .call-fab--end:hover {
        box-shadow: 0 10px 36px rgba(239, 68, 68, 0.70);
    }

    /* أزرار التحكم العادية */
    .call-fab--ctrl {
        background: rgba(255, 255, 255, 0.16);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.18);
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.22);
    }

    .call-fab--ctrl:hover {
        background: rgba(255, 255, 255, 0.26);
    }

    /* حالة الكتم / الإيقاف */
    .call-fab--ctrl.off {
        background: linear-gradient(145deg, #f97316, #dc2626);
        border-color: transparent;
        box-shadow: 0 4px 18px rgba(249, 115, 22, 0.40);
    }

    .call-fab--ctrl.off:hover {
        filter: brightness(1.1);
    }

    /* ─── تأثير النبض (Pulse) لزر الرد ─── */
    .call-fab--pulse {
        animation: fabPulseRing 1.6s ease-out infinite;
    }

    .call-fab--pulse::before {
        content: '';
        position: absolute;
        inset: -8px;
        border-radius: 50%;
        border: 3px solid rgba(34, 197, 94, 0.55);
        animation: fabPulseExpand 1.6s ease-out infinite;
        pointer-events: none;
    }

    @keyframes fabPulseRing {

        0%,
        100% {
            box-shadow: 0 6px 28px rgba(34, 197, 94, 0.50);
        }

        50% {
            box-shadow: 0 6px 38px rgba(34, 197, 94, 0.80), 0 0 0 10px rgba(34, 197, 94, 0.12);
        }
    }

    @keyframes fabPulseExpand {
        0% {
            transform: scale(1);
            opacity: 0.7;
        }

        70% {
            transform: scale(1.5);
            opacity: 0;
        }

        100% {
            transform: scale(1.5);
            opacity: 0;
        }
    }

    /* ─── شريط المكالمة الواردة (مسافة أكبر بين الزرين) ─── */
    .incoming-bar .call-btns-row {
        gap: clamp(48px, 10vw, 96px);
    }

    /* ─── قائمة المزيد (More Menu) ─── */
    .more-controls-menu {
        position: absolute;
        bottom: 110px;
        right: 20px;
        background: rgba(15, 23, 42, 0.94);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 22px;
        padding: 10px;
        min-width: 200px;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.5);
        z-index: 100;
        display: flex;
        flex-direction: column;
        gap: 4px;
        animation: menuIn 0.28s cubic-bezier(.2, .9, .2, 1) both;
    }

    @keyframes menuIn {
        from {
            opacity: 0;
            transform: translateY(15px) scale(0.92);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .more-menu-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 18px;
        border-radius: 16px;
        color: white;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .more-menu-item:hover {
        background: rgba(255, 255, 255, 0.12);
        transform: translateX(-4px);
    }

    .more-menu-item i {
        font-size: 1.15rem;
        width: 26px;
        text-align: center;
        color: rgba(255, 255, 255, 0.85);
    }

    .more-menu-item span {
        font-size: 0.95rem;
        font-weight: 600;
    }

    /* ─── دعم الوضع الداكن ─── */
    body.theme-dark .call-fab--ctrl {
        background: rgba(255, 255, 255, 0.13);
        border-color: rgba(255, 255, 255, 0.14);
    }

    /* ─── استجابة للشاشات الصغيرة ─── */
    @media (max-width: 480px) {
        .call-fab {
            width: 54px;
            height: 54px;
            font-size: 1.15rem;
        }

        .call-fab--accept,
        .call-fab--reject {
            width: 64px;
            height: 64px;
            font-size: 1.4rem;
        }

        .call-fab--end {
            width: 60px;
            height: 60px;
            font-size: 1.3rem;
        }

        .call-btns-row {
            gap: clamp(14px, 3.5vw, 24px);
        }

        .incoming-bar .call-btns-row {
            gap: clamp(36px, 8vw, 72px);
        }

        .call-btn-label {
            font-size: 0.65rem;
        }
    }

    /* Modern messaging-app call modal */
    .call-modal-content {
        background: transparent;
        color: white;
        border: 0;
        height: 100%;
        overflow: hidden;
    }

    .call-modal-body {
        padding: 0;
        height: 100vh;
        position: relative;
        overflow: hidden;
        isolation: isolate;
    }

    .call-background {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        background:
            radial-gradient(circle at 18% 18%, rgba(16, 185, 129, 0.36), transparent 28%),
            radial-gradient(circle at 78% 12%, rgba(59, 130, 246, 0.36), transparent 34%),
            linear-gradient(145deg, #07111f 0%, #0f253a 42%, #111827 100%);
        z-index: 0;
    }

    .call-background::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.01));
        backdrop-filter: blur(1px);
    }

    .call-ambient {
        position: absolute;
        width: 34vmax;
        height: 34vmax;
        border-radius: 999px;
        filter: blur(35px);
        opacity: 0.24;
        pointer-events: none;
        z-index: 0;
        animation: callFloat 10s ease-in-out infinite alternate;
    }

    .call-ambient-one {
        left: -10vmax;
        bottom: -12vmax;
        background: #14b8a6;
    }

    .call-ambient-two {
        right: -12vmax;
        top: -10vmax;
        background: #6366f1;
        animation-delay: -4s;
    }

    .remote-video-wrapper {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1;
    }

    .remote-video {
        width: 100%;
        height: 100%;
        object-fit: cover;
        background: #020617;
    }

    .remote-placeholder {
        text-align: center;
        color: white;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 2;
        width: min(92vw, 520px);
        animation: callPanelIn 0.38s cubic-bezier(.2, .9, .2, 1) both;
    }

    .call-glass-panel {
        width: 100%;
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 32px;
        background: rgba(15, 23, 42, 0.42);
        box-shadow: 0 30px 90px rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(22px);
        -webkit-backdrop-filter: blur(22px);
        padding: clamp(26px, 5vw, 44px);
    }

    .call-type-badge {
        width: fit-content;
        margin: 0 auto 22px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.16);
        color: rgba(255, 255, 255, 0.92);
        font-size: 0.92rem;
        font-weight: 700;
    }

    .call-type-badge i {
        font-size: 1rem;
    }

    .call-avatar-wrap {
        position: relative;
        width: clamp(116px, 18vw, 152px);
        height: clamp(116px, 18vw, 152px);
        margin: 0 auto 22px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.34), rgba(255, 255, 255, 0.10));
        border: 1px solid rgba(255, 255, 255, 0.26);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25), 0 20px 55px rgba(0, 0, 0, 0.28);
        transition: all 0.5s cubic-bezier(.4, 0, .2, 1);
    }

    /* تكبير الصورة في حالة الرنين لمكالمات الفيديو */
    .call-avatar-wrap.video-ringing {
        width: clamp(200px, 45vw, 280px);
        height: clamp(200px, 45vw, 280px);
        border-radius: 35px;
        border-width: 2px;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5);
    }

    .call-avatar-wrap::before,
    .call-avatar-wrap::after {
        content: '';
        position: absolute;
        inset: -10px;
        border-radius: inherit;
        border: 1px solid rgba(16, 185, 129, 0.34);
        opacity: 0;
        pointer-events: none;
    }

    .call-avatar-wrap.is-ringing::before,
    .call-avatar-wrap.is-ringing::after {
        animation: callPulse 1.7s ease-out infinite;
    }

    .call-avatar-wrap.is-ringing::after {
        animation-delay: 0.55s;
    }

    .call-avatar-img {
        width: calc(100% - 12px);
        height: calc(100% - 12px);
        object-fit: cover;
        border-radius: 50%;
    }

    #callAvatarIcon {
        color: rgba(255, 255, 255, 0.92);
        font-size: clamp(3.1rem, 8vw, 4.8rem);
    }

    .call-user-name {
        margin: 0;
        font-size: clamp(1.65rem, 4vw, 2.65rem);
        font-weight: 800;
        line-height: 1.18;
        letter-spacing: 0;
        text-shadow: 0 12px 32px rgba(0, 0, 0, 0.28);
    }

    .call-status {
        margin-top: 10px;
        font-size: 1.05rem;
        font-weight: 600;
        opacity: 0.9;
    }

    .call-meta-grid {
        margin-top: 28px;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .call-meta-grid div {
        min-width: 0;
        border-radius: 18px;
        padding: 12px 10px;
        background: rgba(255, 255, 255, 0.10);
        border: 1px solid rgba(255, 255, 255, 0.11);
    }

    .call-meta-grid span,
    .call-meta-grid strong {
        display: block;
        overflow-wrap: anywhere;
    }

    .call-meta-grid span {
        color: rgba(255, 255, 255, 0.66);
        font-size: 0.76rem;
        margin-bottom: 4px;
    }

    .call-meta-grid strong {
        color: #fff;
        font-size: 0.9rem;
    }

    .local-video-wrapper {
        position: absolute;
        bottom: 118px;
        right: max(20px, env(safe-area-inset-right));
        width: clamp(118px, 14vw, 168px);
        aspect-ratio: 3 / 4;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
        z-index: 10;
        background: rgba(15, 23, 42, 0.54);
        border: 1px solid rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(18px);
    }

    .local-video {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .local-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }

    .call-close-btn {
        position: absolute;
        top: max(20px, env(safe-area-inset-top));
        right: max(20px, env(safe-area-inset-right));
        width: 48px;
        height: 48px;
        border-radius: 50%;
        border: 0;
        background: rgba(255, 255, 255, 0.12);
        color: white;
        cursor: pointer;
        z-index: 20;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s ease, background 0.2s ease;
        backdrop-filter: blur(16px);
    }

    .call-close-btn:hover {
        background: rgba(255, 255, 255, 0.18);
        transform: translateY(-1px);
    }

    /* ── التصميم الجديد لأزرار المكالمات موجود أعلاه ضمن فئة .call-controls-bar ── */

    @keyframes callPulse {
        0% {
            transform: scale(0.94);
            opacity: 0.65;
        }

        70% {
            transform: scale(1.42);
            opacity: 0;
        }

        100% {
            transform: scale(1.42);
            opacity: 0;
        }
    }

    @keyframes callPanelIn {
        from {
            opacity: 0;
            transform: scale(0.94) translateY(16px);
        }

        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }

    @keyframes callFloat {
        from {
            transform: translate3d(0, 0, 0) scale(1);
        }

        to {
            transform: translate3d(4vw, -3vh, 0) scale(1.08);
        }
    }

    @media (max-width: 767.98px) {
        .remote-placeholder {
            width: min(92vw, 420px);
        }

        .call-glass-panel {
            border-radius: 26px;
            padding: 24px 18px;
        }

        .call-meta-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .local-video-wrapper {
            width: 96px;
            right: 16px;
            bottom: 124px;
        }

        /* ── الاستجابة للأزرار موجودة ضمن فئة .call-controls-bar أعلاه ── */
    }

    /* Call Notifications */
    .call-notifications {
        position: absolute;
        top: max(70px, env(safe-area-inset-top));
        left: 50%;
        transform: translateX(-50%);
        z-index: 30;
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-width: min(90vw, 400px);
    }

    .call-notification {
        background: rgba(15, 23, 42, 0.75);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 16px;
        padding: 12px 20px;
        color: white;
        font-size: 0.9rem;
        backdrop-filter: blur(18px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        animation: slideDown 0.3s cubic-bezier(0.2, 0.9, 0.2, 1) both;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Participants Container */
    .participants-container {
        position: absolute;
        bottom: 120px;
        right: max(20px, env(safe-area-inset-right));
        display: flex;
        flex-direction: column;
        gap: 10px;
        z-index: 15;
        max-height: calc(100vh - 280px);
        overflow-y: auto;
        padding: 10px;
    }

    /* Reactions Panel */
    .reactions-panel {
        position: absolute;
        bottom: 140px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 25;
        display: none;
        gap: 12px;
        padding: 10px 20px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.75);
        border: 1px solid rgba(255, 255, 255, 0.18);
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
        backdrop-filter: blur(20px);
    }

    .reaction-btn {
        width: 48px;
        height: 48px;
        border: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.12);
        font-size: 1.5rem;
        cursor: pointer;
        transition: transform 0.15s ease, background 0.15s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .reaction-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.15);
    }

    /* Call control button states */
    .call-control-btn.active {
        background: #10b981 !important;
    }

    .call-control-btn.off {
        background: #ef4444 !important;
    }

    .call-control-btn.hand-raised {
        background: #f59e0b !important;
    }

    @media (prefers-reduced-motion: reduce) {

        .remote-placeholder,
        .call-ambient,
        .call-avatar-wrap.is-ringing::before,
        .call-avatar-wrap.is-ringing::after,
        .call-notification {
            animation: none !important;
        }
    }
</style>

<!-- WebRTC Library -->
<script src="https://cdn.jsdelivr.net/npm/simple-peer@9.11.1/simplepeer.min.js"></script>

<script>
    let currentChatType = null; // 'user' or 'group'
    let currentChatId = null;
    let messageRefreshInterval = null;
    let presenceRefreshInterval = null;
    let typingTimeout = null;
    let editingMessage = null;
    let mediaRecorder = null;
    let recordedChunks = [];
    let recordedAudioFile = null;
    let selectedAttachmentFile = null;
    let voiceRecorderStream = null;
    let voiceRecordingLocked = false;
    let voicePressActive = false;
    let voicePressStartY = 0;
    let voicePointerId = null;
    let voiceCancelCurrent = false;
    let voiceSuppressClickUntil = 0;
    let isSelectionMode = false;
    let selectedMessageIds = new Set();
    let currentVisibleMessageIds = [];
    let currentMessagesCache = [];
    let longPressTimer = null;
    let cameraStream = null;
    let currentCameraFacing = 'environment';
    let cameraRecorder = null;
    let cameraVideoChunks = [];
    let cancelCameraRecording = false;
    let isLoadingUsers = false;
    let pendingUsersReload = false;
    let usersRefreshInterval = null;

    // Call variables - declared early
    let callModal = null;
    let localStream = null;
    let peer = null;
    let isCallActive = false;
    let isAudioMuted = false;
    let isVideoMuted = false;
    let currentCallType = null; // 'audio' or 'video'
    let currentCallPartnerId = null;
    let currentCallPartnerName = null;
    let currentCallPartnerImage = null;
    let currentCallTargetType = 'user';
    let currentCallTargetId = null;
    let isCaller = false;
    let pendingSignal = null;
    let processedSignalMsgIds = new Set(); // Stores IDs of processed signaling messages
    let pendingIceCandidates = []; // Stores pending ICE candidates before peer is ready
    let currentCallId = null;
    let currentCallStatus = null;
    let callStatusInterval = null;
    let callRingTimeout = null;
    let callDurationInterval = null;
    let callAcceptedAt = null;
    let callCleanupInProgress = false;
    const CALL_RING_TIMEOUT_MS = 45000;

    // Audio for calls
    let audioContext = null;
    let ringtoneOscillator = null;
    let dialtoneOscillator = null;
    let ringtoneGainNode = null;
    let dialtoneGainNode = null;
    let ringtoneInterval = null;
    let dialtoneInterval = null;
    let ringtoneToneTimeouts = [];
    let isRingtonePlaying = false;
    let isDialtonePlaying = false;
    let isSpeakerOn = false;
    let currentCallFacingMode = 'user';
    let missedCallsInterval = null;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function getCurrentTargetPayload() {
        if (!currentChatId) return null;
        return currentChatType === 'user' ? {
            key: 'receiver_id',
            value: currentChatId,
            action: 'send'
        } : {
            key: 'group_id',
            value: currentChatId,
            action: 'send_group'
        };
    }

    function setPresence(status) {
        const target = getCurrentTargetPayload();
        if (!target) return;

        fetch('internal_messages.php?action=chat_presence', {
            method: 'POST',
            body: new URLSearchParams({
                target_type: currentChatType,
                target_id: currentChatId,
                status
            })
        }).catch(() => {});
    }

    function renderAttachment(msg) {
        if (!msg.image_path) return '';

        const url = `../${msg.image_path}`;
        const name = escapeHtml(msg.attachment_name || 'مرفق');
        const type = msg.attachment_type || (/\.(jpe?g|png|gif|webp)$/i.test(msg.image_path) ? 'image' : 'document');

        if (type === 'image') {
            return `<a href="${url}" target="_blank"><img src="${url}" class="rounded mb-2 img-fluid" style="max-height: 250px;"></a>`;
        }

        if (type === 'video') {
            return `<div class="media-attachment"><video src="${url}" controls class="w-100"></video></div>`;
        }

        if (type === 'audio') {
            const listenedClass = msg.is_own && msg.is_read == 1 ? 'is-heard' : 'is-unheard';
            return `
                    <div class="voice-attachment">
                        <div class="voice-player ${listenedClass}">
                            <span class="voice-state-dot"></span>
                            <audio src="${url}" controls preload="metadata"></audio>
                        </div>
                    </div>
                `;
        }

        return `
                <a href="${url}" target="_blank" class="document-attachment text-decoration-none d-flex align-items-center gap-2">
                    <i class="fas fa-file-alt"></i>
                    <span>${name}</span>
                </a>
            `;
    }

    function supportedMimeType(candidates) {
        if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
        return candidates.find(type => MediaRecorder.isTypeSupported(type)) || '';
    }

    function getRecorderOptions(kind = 'audio') {
        const audioTypes = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/mp4'
        ];
        const videoTypes = [
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm',
            'video/mp4'
        ];
        const mimeType = supportedMimeType(kind === 'video' ? videoTypes : audioTypes);
        return mimeType ? {
            mimeType
        } : {};
    }

    function recorderExtension(mimeType, fallback) {
        if ((mimeType || '').includes('mp4')) return 'mp4';
        if ((mimeType || '').includes('ogg')) return 'ogg';
        return fallback;
    }

    function makeUploadFile(blob, fileName) {
        try {
            return new File([blob], fileName, {
                type: blob.type || 'application/octet-stream'
            });
        } catch (error) {
            blob.name = fileName;
            return blob;
        }
    }

    function isMediaFeatureAllowed(feature) {
        const policy = document.permissionsPolicy || document.featurePolicy;
        if (!policy) return true;

        try {
            if (typeof policy.allowsFeature === 'function') {
                return policy.allowsFeature(feature);
            }
            if (typeof policy.allowedFeatures === 'function') {
                return policy.allowedFeatures().includes(feature);
            }
        } catch (error) {
            return true;
        }

        return true;
    }

    function explainMediaError(error, source) {
        const messages = {
            NotAllowedError: 'تم رفض صلاحية الوصول. افتح إعدادات الموقع واسمح باستخدام ' + source + '.',
            SecurityError: 'المتصفح منع الوصول لأسباب أمنية. افتح الصفحة عبر localhost أو HTTPS.',
            NotFoundError: 'لم يتم العثور على جهاز ' + source + ' متصل.',
            NotReadableError: 'تعذر تشغيل ' + source + ' لأنه مستخدم في تطبيق آخر أو يحتاج إعادة توصيل.',
            OverconstrainedError: 'إعدادات الجهاز المطلوبة غير مدعومة على هذا المتصفح.',
            NotSupportedError: 'صيغة التسجيل غير مدعومة في هذا المتصفح.'
        };
        return messages[error && error.name] || (error && error.message ? error.message : 'حدث خطأ غير معروف أثناء تشغيل ' + source + '.');
    }

    function showToast(icon, title, text = '') {
        if (window.Swal) {
            Swal.fire({
                icon,
                title,
                text,
                timer: text ? undefined : 1500,
                showConfirmButton: !!text
            });
        } else {
            alert(text ? `${title}\n${text}` : title);
        }
    }

    function openAttachmentSheet() {
        if (!currentChatId) {
            alert('اختر محادثة أولاً');
            return;
        }
        document.getElementById('attachmentSheetBackdrop').classList.remove('d-none');
        document.getElementById('attachmentSheet').classList.remove('d-none');
        document.getElementById('attachmentSheet').setAttribute('aria-hidden', 'false');
    }

    function closeAttachmentSheet() {
        document.getElementById('attachmentSheetBackdrop').classList.add('d-none');
        document.getElementById('attachmentSheet').classList.add('d-none');
        document.getElementById('attachmentSheet').setAttribute('aria-hidden', 'true');
    }

    function chooseFileInput(inputId, accept) {
        const input = document.getElementById(inputId);
        if (!input) return;
        if (accept) input.setAttribute('accept', accept);
        input.value = '';
        input.click();
    }

    function handleSelectedFile(file) {
        selectedAttachmentFile = file || null;
        recordedAudioFile = null;
        updateAttachmentPreview(selectedAttachmentFile);
    }

    function sendLocationNow(button) {
        if (!currentChatId) {
            alert('اختر محادثة أولاً');
            return;
        }

        if (!navigator.geolocation) {
            alert('المتصفح لا يدعم تحديد الموقع');
            return;
        }

        if (button) button.disabled = true;
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const lat = position.coords.latitude.toFixed(6);
                const lng = position.coords.longitude.toFixed(6);
                document.getElementById('messageInput').value = `موقعي الحالي: https://www.google.com/maps?q=${lat},${lng}`;
                if (button) button.disabled = false;
                sendCurrentMessage();
            },
            (error) => {
                if (button) button.disabled = false;
                alert(error.message || 'تعذر الحصول على الموقع. تأكد من السماح للمتصفح باستخدام الموقع.');
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    }

    async function sendContactCard() {
        const result = await Swal.fire({
            title: 'إرسال جهة اتصال',
            html: '<input id="contactNameInput" class="swal2-input" placeholder="الاسم"><input id="contactPhoneInput" class="swal2-input" placeholder="رقم الهاتف">',
            showCancelButton: true,
            confirmButtonText: 'إرسال',
            cancelButtonText: 'إلغاء',
            preConfirm: () => {
                const name = document.getElementById('contactNameInput').value.trim();
                const phone = document.getElementById('contactPhoneInput').value.trim();
                if (!name || !phone) {
                    Swal.showValidationMessage('أدخل الاسم ورقم الهاتف');
                    return false;
                }
                return {
                    name,
                    phone
                };
            }
        });
        if (!result.isConfirmed) return;
        document.getElementById('messageInput').value = `جهة اتصال:\n${result.value.name}\n${result.value.phone}`;
        sendCurrentMessage();
    }

    async function openCameraModal() {
        if (!currentChatId) {
            alert('اختر محادثة أولاً');
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('المتصفح لا يدعم الكاميرا');
            return;
        }

        if (!isMediaFeatureAllowed('camera')) {
            showToast('error', 'تعذر تشغيل الكاميرا', 'إعدادات الصفحة أو الإطار تمنع استخدام الكاميرا.');
            return;
        }

        const modalEl = document.getElementById('cameraModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        await startCameraStream();
    }

    async function startCameraStream() {
        try {
            stopCameraStream();
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: {
                            ideal: currentCameraFacing
                        }
                    },
                    audio: true
                });
            } catch (withAudioError) {
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: {
                            ideal: currentCameraFacing
                        }
                    },
                    audio: false
                });
            }
            const preview = document.getElementById('cameraPreview');
            preview.srcObject = cameraStream;
            document.getElementById('cameraStatus').textContent = '';
        } catch (error) {
            console.error('Camera error:', error);
            document.getElementById('cameraStatus').textContent = explainMediaError(error, 'الكاميرا');
        }
    }

    async function switchCameraFacing() {
        if (cameraRecorder && cameraRecorder.state === 'recording') {
            showToast('info', 'أوقف تسجيل الفيديو أولاً');
            return;
        }

        currentCameraFacing = currentCameraFacing === 'environment' ? 'user' : 'environment';
        const switchBtn = document.getElementById('switchCameraBtn');
        if (switchBtn) switchBtn.disabled = true;
        await startCameraStream();
        if (switchBtn) switchBtn.disabled = false;
    }

    function stopCameraStream() {
        if (cameraRecorder && cameraRecorder.state === 'recording') {
            cancelCameraRecording = true;
            cameraRecorder.stop();
        }
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        const preview = document.getElementById('cameraPreview');
        if (preview) preview.srcObject = null;
    }

    function closeCameraModal() {
        stopCameraStream();
        cameraVideoChunks = [];
        const recordBtn = document.getElementById('recordVideoBtn');
        if (recordBtn) recordBtn.innerHTML = '<i class="fas fa-circle me-1"></i> تسجيل فيديو';
    }

    function captureCameraPhoto() {
        const preview = document.getElementById('cameraPreview');
        if (!preview || !cameraStream) return;

        const canvas = document.getElementById('cameraCanvas');
        canvas.width = preview.videoWidth || 1280;
        canvas.height = preview.videoHeight || 720;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(preview, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            if (!blob) return;
            selectedAttachmentFile = new File([blob], `camera_${Date.now()}.jpg`, {
                type: 'image/jpeg'
            });
            recordedAudioFile = null;
            updateAttachmentPreview(selectedAttachmentFile);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('cameraModal')).hide();
            sendCurrentMessage();
        }, 'image/jpeg', 0.9);
    }

    function toggleCameraVideoRecording() {
        if (!cameraStream) return;
        const recordBtn = document.getElementById('recordVideoBtn');

        if (cameraRecorder && cameraRecorder.state === 'recording') {
            cameraRecorder.stop();
            return;
        }

        try {
            cameraVideoChunks = [];
            const options = getRecorderOptions('video');
            cameraRecorder = new MediaRecorder(cameraStream, options);
            cameraRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) cameraVideoChunks.push(event.data);
            };
            cameraRecorder.onstop = () => {
                if (cancelCameraRecording) {
                    cancelCameraRecording = false;
                    cameraVideoChunks = [];
                    recordBtn.innerHTML = '<i class="fas fa-circle me-1"></i> تسجيل فيديو';
                    return;
                }
                const mimeType = cameraRecorder.mimeType || options.mimeType || 'video/webm';
                const ext = recorderExtension(mimeType, 'webm');
                const blob = new Blob(cameraVideoChunks, {
                    type: mimeType
                });
                selectedAttachmentFile = new File([blob], `camera_video_${Date.now()}.${ext}`, {
                    type: blob.type
                });
                recordedAudioFile = null;
                updateAttachmentPreview(selectedAttachmentFile);
                recordBtn.innerHTML = '<i class="fas fa-circle me-1"></i> تسجيل فيديو';
                bootstrap.Modal.getOrCreateInstance(document.getElementById('cameraModal')).hide();
                sendCurrentMessage();
            };
            cancelCameraRecording = false;
            cameraRecorder.start();
            recordBtn.innerHTML = '<i class="fas fa-stop me-1"></i> إيقاف التسجيل';
        } catch (error) {
            console.error('Video recording error:', error);
            showToast('error', 'تعذر تسجيل الفيديو', explainMediaError(error, 'الكاميرا'));
        }
    }

    function updateAttachmentPreview(file) {
        const preview = document.getElementById('attachmentPreview');
        if (!preview) return;

        if (!file) {
            preview.classList.add('d-none');
            preview.innerHTML = '';
            return;
        }

        const sizeKb = Math.max(1, Math.round(file.size / 1024));
        preview.classList.remove('d-none');
        preview.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-paperclip text-primary"></i>
                    <span class="small">${escapeHtml(file.name)} (${sizeKb} KB)</span>
                </div>
                <button type="button" class="btn btn-sm btn-link text-danger p-0" id="clearAttachmentBtn">
                    <i class="fas fa-times"></i>
                </button>
            `;

        document.getElementById('clearAttachmentBtn').addEventListener('click', () => {
            selectedAttachmentFile = null;
            recordedAudioFile = null;
            document.getElementById('imageUpload').value = '';
            updateAttachmentPreview(null);
        });
    }

    async function sendCurrentMessage() {
        if (editingMessage) {
            await saveEditedMessage();
            return;
        }

        const target = getCurrentTargetPayload();
        if (!target) {
            alert('اختر محادثة أولاً');
            return;
        }

        const messageInputEl = document.getElementById('messageInput');
        const fileInput = document.getElementById('imageUpload');
        const message = messageInputEl.value.trim();
        const attachment = recordedAudioFile || selectedAttachmentFile || fileInput.files[0] || null;

        if (!message && !attachment) return;

        const formData = new FormData();
        formData.append('message', message);
        formData.append(target.key, target.value);
        if (attachment) {
            formData.append('chat_attachment', attachment, attachment.name || `attachment_${Date.now()}`);
        }

        try {
            const response = await fetch(`internal_messages.php?action=${target.action}`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.status === 'success') {
                messageInputEl.value = '';
                fileInput.value = '';
                selectedAttachmentFile = null;
                recordedAudioFile = null;
                updateAttachmentPreview(null);
                setPresence('idle');
                loadMessages();
            } else {
                alert(data.message || 'حدث خطأ أثناء إرسال الرسالة');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('تعذر إرسال الرسالة، حاول مرة أخرى');
        }
    }

    function setEditingMessage(id, message) {
        editingMessage = {
            id,
            message
        };
        selectedAttachmentFile = null;
        recordedAudioFile = null;
        document.getElementById('imageUpload').value = '';
        updateAttachmentPreview(null);
        document.getElementById('messageInput').value = message || '';
        document.getElementById('editComposerBar').classList.remove('d-none');
        document.querySelector('#messageForm button[type="submit"]').innerHTML = '<i class="fas fa-save me-1"></i> حفظ';
        document.getElementById('messageInput').focus();
    }

    function clearEditingMessage() {
        editingMessage = null;
        document.getElementById('editComposerBar').classList.add('d-none');
        document.querySelector('#messageForm button[type="submit"]').innerHTML = '<i class="fas fa-paper-plane me-1"></i> إرسال';
    }

    async function saveEditedMessage() {
        if (!editingMessage) return false;

        const messageInputEl = document.getElementById('messageInput');
        const newMessage = messageInputEl.value.trim();
        if (!newMessage) return true;

        const action = currentChatType === 'user' ? 'edit_message' : 'edit_group_message';
        const formData = new FormData();
        formData.append('message_id', editingMessage.id);
        formData.append('message', newMessage);

        try {
            const response = await fetch(`internal_messages.php?action=${action}`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.status === 'success') {
                messageInputEl.value = '';
                clearEditingMessage();
                loadMessages();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'تعذر تعديل الرسالة',
                    text: data.message || 'حدث خطأ أثناء التعديل'
                });
            }
        } catch (error) {
            console.error('Edit error:', error);
            Swal.fire({
                icon: 'error',
                title: 'تعذر تعديل الرسالة'
            });
        }

        return true;
    }

    function setSelectionMode(enabled) {
        isSelectionMode = enabled;
        if (!enabled) selectedMessageIds.clear();
        document.getElementById('chatBulkActions').classList.toggle('d-none', !enabled);
        document.getElementById('chatHeader').classList.toggle('d-none', enabled);
        updateSelectionToolbar();
        loadMessages();
    }

    function updateSelectedMessages() {
        updateSelectionToolbar();
    }

    function toggleMessageSelection(id) {
        const numericId = Number(id);
        if (!isSelectionMode) {
            setSelectionMode(true);
        }
        if (selectedMessageIds.has(numericId)) {
            selectedMessageIds.delete(numericId);
        } else {
            selectedMessageIds.add(numericId);
        }
        if (selectedMessageIds.size === 0) {
            setSelectionMode(false);
            return;
        }
        updateSelectionToolbar();
        document.querySelectorAll(`[data-message-id="${numericId}"]`).forEach(el => {
            el.classList.toggle('is-selected', selectedMessageIds.has(numericId));
        });
    }

    function updateSelectionToolbar() {
        const count = selectedMessageIds.size;
        const countEl = document.getElementById('selectedMessagesCount');
        if (countEl) countEl.textContent = `${count} محددة`;
        document.getElementById('deleteSelectedMessagesBtn').disabled = count === 0;
        document.getElementById('copySelectedMessagesBtn').disabled = count === 0;
        document.getElementById('forwardSelectedMessagesBtn').disabled = count === 0;
        document.getElementById('shareSelectedMessagesBtn').disabled = count === 0;
        document.getElementById('replySelectedMessagesBtn').disabled = count !== 1;
    }

    function getSelectedMessagesText() {
        return currentMessagesCache
            .filter(msg => selectedMessageIds.has(Number(msg.id)))
            .map(msg => `${msg.sender_name || ''}: ${msg.message || msg.attachment_name || 'مرفق'}`)
            .join('\n');
    }

    async function copySelectedMessages() {
        const text = getSelectedMessagesText();
        if (!text) return;
        try {
            await navigator.clipboard.writeText(text);
            showToast('success', 'تم النسخ');
        } catch (error) {
            const input = document.createElement('textarea');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            input.remove();
            showToast('success', 'تم النسخ');
        }
    }

    async function shareSelectedMessages() {
        const text = getSelectedMessagesText();
        if (!text) return;
        if (navigator.share) {
            await navigator.share({
                text
            }).catch(() => {});
        } else {
            await copySelectedMessages();
        }
    }

    function replyToSelectedMessage() {
        const msg = currentMessagesCache.find(item => selectedMessageIds.has(Number(item.id)));
        if (!msg) return;
        document.getElementById('messageInput').value = `رداً على ${msg.sender_name}:\n> ${(msg.message || msg.attachment_name || 'مرفق').slice(0, 120)}\n\n`;
        setSelectionMode(false);
        document.getElementById('messageInput').focus();
    }

    function forwardSelectedMessages() {
        const text = getSelectedMessagesText();
        if (!text) return;
        document.getElementById('messageInput').value = `إعادة توجيه:\n${text}`;
        setSelectionMode(false);
        document.getElementById('messageInput').focus();
    }

    async function deleteAllCurrentConversation() {
        const ids = currentVisibleMessageIds.filter(Boolean);
        if (!currentChatId || !ids.length) {
            showToast('info', 'لا توجد رسائل للحذف');
            return;
        }

        const type = await chooseDeleteType('حذف جميع رسائل هذه المحادثة؟');
        if (!type) return;

        const ok = await confirmDelete('تأكيد حذف جميع الرسائل', `سيتم حذف ${ids.length} رسالة من المحادثة الحالية فقط.`);
        if (!ok) return;

        await deleteMessages(ids, type);
    }

    async function searchConversation() {
        const result = await Swal.fire({
            title: 'البحث داخل المحادثة',
            input: 'text',
            inputPlaceholder: 'اكتب كلمة البحث',
            showCancelButton: true,
            confirmButtonText: 'بحث',
            cancelButtonText: 'إلغاء'
        });
        if (!result.isConfirmed || !result.value) return;

        const term = result.value.trim().toLowerCase();
        const found = currentMessagesCache.find(msg => (msg.message || '').toLowerCase().includes(term));
        if (!found) {
            showToast('info', 'لا توجد نتائج');
            return;
        }

        const row = document.querySelector(`[data-message-id="${found.id}"]`);
        if (row) {
            row.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            row.classList.add('is-selected');
            setTimeout(() => {
                if (!selectedMessageIds.has(Number(found.id))) row.classList.remove('is-selected');
            }, 1800);
        }
    }

    function showConversationInfo() {
        const title = document.getElementById('chatTitle').innerText || 'المحادثة';
        Swal.fire({
            icon: currentChatType === 'group' ? 'info' : 'info',
            title,
            html: `<div class="text-start" dir="rtl">
                    <p class="mb-2">النوع: ${currentChatType === 'group' ? 'مجموعة' : 'محادثة فردية'}</p>
                    <p class="mb-0">عدد الرسائل الظاهرة: ${currentVisibleMessageIds.length}</p>
                </div>`
        });
    }

    function showConversationMedia() {
        const media = currentMessagesCache.filter(msg => msg.image_path);
        if (!media.length) {
            showToast('info', 'لا توجد وسائط أو ملفات');
            return;
        }
        const html = media.map(msg => {
            const name = escapeHtml(msg.attachment_name || msg.image_path.split('/').pop());
            return `<a class="d-flex align-items-center gap-2 text-decoration-none border rounded p-2 mb-2" href="../${msg.image_path}" target="_blank">
                    <i class="fas fa-paperclip"></i><span>${name}</span>
                </a>`;
        }).join('');
        Swal.fire({
            title: 'الوسائط والملفات',
            html,
            width: 600,
            confirmButtonText: 'إغلاق'
        });
    }

    function exportConversation() {
        if (!currentMessagesCache.length) {
            showToast('info', 'لا توجد رسائل للتصدير');
            return;
        }
        const title = document.getElementById('chatTitle').innerText || 'chat';
        const text = currentMessagesCache.map(msg => {
            const time = new Date(msg.created_at).toLocaleString('ar-SA');
            const body = msg.message || msg.attachment_name || msg.image_path || '';
            return `[${time}] ${msg.sender_name}: ${body}`;
        }).join('\n');
        const blob = new Blob([text], {
            type: 'text/plain;charset=utf-8'
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${title.replace(/[\\/:*?"<>|]+/g, '_')}_${Date.now()}.txt`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function muteConversationNotifications() {
        const notificationToggle = document.getElementById('notificationToggle');
        if (notificationToggle) {
            notificationToggle.checked = false;
            notificationToggle.dispatchEvent(new Event('change'));
        }
        showToast('success', 'تم كتم الإشعارات');
    }

    function handleChatMenuAction(action) {
        if (!currentChatId && !['mute'].includes(action)) {
            alert('اختر محادثة أولاً');
            return;
        }
        if (action === 'info') showConversationInfo();
        if (action === 'search') searchConversation();
        if (action === 'select') setSelectionMode(true);
        if (action === 'media') showConversationMedia();
        if (action === 'delete_all' || action === 'delete_chat') deleteAllCurrentConversation();
        if (action === 'export') exportConversation();
        if (action === 'mute') muteConversationNotifications();
        if (action === 'block') showToast('info', 'الحظر يحتاج جدول صلاحيات مخصص', 'لم أضف تغييراً على قاعدة البيانات حتى لا يتأثر النظام الحالي.');
        if (action === 'report') showToast('success', 'تم تسجيل البلاغ محلياً', 'يمكن ربط هذا الخيار لاحقاً بنظام البلاغات إذا كان موجوداً.');
    }

    async function chooseDeleteType(title) {
        if (currentChatType === 'group') {
            return 'for_everyone';
        }

        const result = await Swal.fire({
            icon: 'warning',
            title,
            text: 'اختر طريقة الحذف المناسبة',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'حذف لدي فقط',
            denyButtonText: 'حذف لدى الجميع',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545',
            denyButtonColor: '#b91c1c'
        });

        if (result.isConfirmed) return 'for_me';
        if (result.isDenied) return 'for_everyone';
        return null;
    }

    async function confirmDelete(title, text) {
        const result = await Swal.fire({
            icon: 'warning',
            title,
            text,
            showCancelButton: true,
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545',
            reverseButtons: true
        });
        return result.isConfirmed;
    }

    async function deleteMessageRequest(id, type = 'for_me') {
        const action = currentChatType === 'user' ? 'delete_message' : 'delete_group_message';
        const formData = new FormData();
        formData.append('message_id', id);
        formData.append('type', type);

        const response = await fetch(`internal_messages.php?action=${action}`, {
            method: 'POST',
            body: formData
        });
        return response.json();
    }

    async function deleteMessages(ids, type) {
        let successCount = 0;
        let firstError = '';

        for (const id of ids) {
            const data = await deleteMessageRequest(id, type);
            if (data.status === 'success') {
                successCount++;
            } else if (!firstError) {
                firstError = data.message || 'تعذر حذف بعض الرسائل';
            }
        }

        selectedMessageIds.clear();
        setSelectionMode(false);
        loadMessages();

        if (successCount === ids.length) {
            Swal.fire({
                icon: 'success',
                title: 'تم الحذف',
                timer: 1200,
                showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: successCount ? 'warning' : 'error',
                title: successCount ? 'تم حذف بعض الرسائل' : 'تعذر الحذف',
                text: firstError
            });
        }
    }

    let pendingReloadTimeout = null;

    // تحميل قائمة المستخدمين والمجموعات
    async function loadUsers() {
        if (isLoadingUsers) {
            pendingUsersReload = true;
            return;
        }

        isLoadingUsers = true;
        pendingUsersReload = false;
        if (pendingReloadTimeout) {
            clearTimeout(pendingReloadTimeout);
            pendingReloadTimeout = null;
        }
        try {
            // جلب المجموعات أولاً
            const groupsRes = await fetch('internal_messages.php?action=get_groups');
            const groupsData = await groupsRes.json();

            // جلب المستخدمين
            const usersRes = await fetch('internal_messages.php?action=get_users');
            const usersData = await usersRes.json();

            const usersList = document.getElementById('usersList');
            if (!usersList) {
                console.error('usersList element not found');
                return;
            }
            usersList.innerHTML = '';

            // عرض المجموعات أولاً
            if (groupsData.groups && groupsData.groups.length > 0) {
                const groupHeader = document.createElement('div');
                groupHeader.className = 'p-2 bg-light small fw-bold text-muted border-bottom';
                groupHeader.innerHTML = '<i class="fas fa-users me-1"></i> المجموعات';
                usersList.appendChild(groupHeader);

                groupsData.groups.forEach(group => {
                    const groupEl = document.createElement('a');
                    groupEl.href = '#';
                    groupEl.className = 'list-group-item list-group-item-action d-flex align-items-center justify-content-between';
                    if (currentChatType === 'group' && currentChatId == group.id) {
                        groupEl.classList.add('active');
                    }

                    groupEl.innerHTML = `
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle me-2 bg-primary d-flex align-items-center justify-content-center text-white" style="width: 40px; height: 40px;">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="ms-1">
                                    <div class="fw-bold">${group.name}</div>
                                    <small class="${currentChatId == group.id && currentChatType === 'group' ? 'text-white-50' : 'text-muted'}">
                                        بواسطة ${group.creator_name} • ${group.msg_count} رسالة
                                    </small>
                                </div>
                            </div>
                        `;

                    groupEl.onclick = (e) => {
                        e.preventDefault();
                        openChat('group', group.id, group.name);
                    };
                    usersList.appendChild(groupEl);
                });
            }

            // عرض الأفراد
            const userHeader = document.createElement('div');
            userHeader.className = 'p-2 bg-light small fw-bold text-muted border-bottom mt-2';
            userHeader.innerHTML = '<i class="fas fa-user me-1"></i> الأفراد';
            usersList.appendChild(userHeader);

            if (usersData.users) {
                usersData.users.forEach(user => {
                    const userEl = document.createElement('a');
                    userEl.href = '#';
                    userEl.className = 'list-group-item list-group-item-action d-flex align-items-center justify-content-between';
                    if (currentChatType === 'user' && currentChatId == user.id) {
                        userEl.classList.add('active');
                    }

                    const displayName = user.full_name && user.full_name.trim() !== '' ? user.full_name : user.username;
                    const profileImg = user.profile_image ? `../assets/uploads/profiles/${user.profile_image}` : null;

                    const lastSeenDate = new Date(user.last_seen);
                    const now = new Date();
                    const diffMinutes = Math.floor((now - lastSeenDate) / 1000 / 60);
                    const isOnline = user.is_online == 1 && diffMinutes < 5;

                    let statusColor = 'bg-secondary'; // offline
                    let statusText = 'غير متصل';
                    let statusDotVisible = true;

                    if (user.call_status) {
                        if (user.call_status === 'accepted') {
                            statusColor = 'bg-danger'; // busy in call
                            statusText = 'في مكالمة';
                        } else if (user.call_status === 'ringing') {
                            statusColor = 'bg-warning'; // ringing
                            statusText = 'يرن';
                        } else if (user.call_status === 'calling') {
                            statusColor = 'bg-primary'; // calling
                            statusText = 'يتصل';
                        }
                    } else if (isOnline) {
                        statusColor = 'bg-success';
                        statusText = 'متصل الآن';
                    }

                    const imgHtml = profileImg ?
                        `<div class="position-relative">
                                <img src="${profileImg}" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;">
                                ${statusDotVisible ? `<span class="position-absolute bottom-0 end-0 border border-white rounded-circle ${statusColor}" style="width: 12px; height: 12px; margin-right: 5px;"></span>` : ''}
                            </div>` :
                        `<div class="position-relative">
                                <div class="rounded-circle me-2 bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 40px; height: 40px;"><i class="fas fa-user"></i></div>
                                ${statusDotVisible ? `<span class="position-absolute bottom-0 end-0 border border-white rounded-circle ${statusColor}" style="width: 12px; height: 12px; margin-right: 5px;"></span>` : ''}
                            </div>`;

                    const unreadBadge = user.unread_count > 0 ? `<span class="badge bg-danger rounded-pill">${user.unread_count}</span>` : '';

                    let lastSeenText = '';
                    if (user.call_status) {
                        const textClass = user.call_status === 'accepted' ? 'text-danger' : (user.call_status === 'ringing' ? 'text-warning' : 'text-primary');
                        lastSeenText = `<span class="${textClass} small">${statusText}</span>`;
                    } else if (isOnline) {
                        lastSeenText = '<span class="text-success small">متصل الآن</span>';
                    } else {
                        if (isNaN(lastSeenDate.getTime())) {
                            lastSeenText = '';
                        } else if (diffMinutes < 60) {
                            lastSeenText = `<span class="small text-muted">آخر ظهور قبل ${diffMinutes} د</span>`;
                        } else if (diffMinutes < 1440) {
                            lastSeenText = `<span class="small text-muted">آخر ظهور قبل ${Math.floor(diffMinutes/60)} س</span>`;
                        } else {
                            lastSeenText = `<span class="small text-muted">${lastSeenDate.toLocaleDateString('ar-SA')}</span>`;
                        }
                    }

                    userEl.innerHTML = `
                            <div class="d-flex align-items-center">
                                ${imgHtml}
                                <div class="ms-1">
                                    <div class="fw-bold">${displayName}</div>
                                    <div class="d-flex align-items-center gap-2">
                                        <small class="${currentChatId == user.id && currentChatType === 'user' ? 'text-white-50' : 'text-muted'}">@${user.username}</small>
                                        ${lastSeenText}
                                    </div>
                                </div>
                            </div>
                            ${unreadBadge}
                        `;

                    userEl.onclick = (e) => {
                        e.preventDefault();
                        openChat('user', user.id, displayName, user.profile_image, isOnline, user.last_seen);
                    };
                    usersList.appendChild(userEl);
                });
            }
        } catch (error) {
            console.error('Error loading chats:', error);
        } finally {
            isLoadingUsers = false;
            if (pendingUsersReload) {
                pendingUsersReload = false;
                pendingReloadTimeout = setTimeout(() => {
                    loadUsers();
                }, 1000);
            }
        }
    }

    // تحميل قائمة المجموعات (تم دمجها مع loadUsers)
    function loadGroups() {
        // يمكن تركها فارغة أو حذفها من الأماكن الأخرى
        loadUsers();
    }

    // فتح نافذة تعديل المجموعة
    function openEditGroupModal(groupId) {
        fetch(`internal_messages.php?action=get_group_info&group_id=${groupId}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    const group = data.group;
                    document.getElementById('editGroupId').value = group.id;
                    document.getElementById('editGroupName').value = group.name;
                    document.getElementById('editGroupDescription').value = group.description;

                    // جلب قائمة المستخدمين لاختيار الأعضاء
                    fetch('internal_messages.php?action=get_users')
                        .then(r => r.json())
                        .then(userData => {
                            const editMembersList = document.getElementById('editMembersList');
                            editMembersList.innerHTML = '';
                            userData.users.forEach(user => {
                                const isMember = group.members.includes(user.id.toString()) || group.members.includes(parseInt(user.id));
                                const div = document.createElement('div');
                                div.className = 'form-check mb-2';
                                div.innerHTML = `
                                        <input class="form-check-input edit-member-checkbox" type="checkbox" value="${user.id}" id="edit_user_${user.id}" ${isMember ? 'checked' : ''}>
                                        <label class="form-check-label" for="edit_user_${user.id}">
                                            ${user.full_name} (@${user.username})
                                        </label>
                                    `;
                                editMembersList.appendChild(div);
                            });

                            const modal = new bootstrap.Modal(document.getElementById('editGroupModal'));
                            modal.show();
                        });
                }
            });
    }

    // تحديث المجموعة
    document.getElementById('updateGroupBtn').addEventListener('click', function() {
        const groupId = document.getElementById('editGroupId').value;
        const name = document.getElementById('editGroupName').value;
        const description = document.getElementById('editGroupDescription').value;
        const selectedMembers = Array.from(document.querySelectorAll('.edit-member-checkbox:checked')).map(cb => cb.value);

        if (!name) {
            alert('اسم المجموعة مطلوب');
            return;
        }

        const formData = new FormData();
        formData.append('group_id', groupId);
        formData.append('name', name);
        formData.append('description', description);
        selectedMembers.forEach(id => formData.append('members[]', id));

        fetch('internal_messages.php?action=update_group', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    bootstrap.Modal.getInstance(document.getElementById('editGroupModal')).hide();
                    loadUsers();
                    // تحديث عنوان الدردشة إذا كانت هي المفتوحة
                    if (currentChatType === 'group' && currentChatId == groupId) {
                        document.querySelector('#chatTitle span.fw-bold').innerText = name;
                    }
                } else {
                    alert(data.message);
                }
            });
    });

    // حذف المجموعة
    document.getElementById('deleteGroupBtn').addEventListener('click', function() {
        const groupId = document.getElementById('editGroupId').value;
        if (confirm('هل أنت متأكد من حذف هذه المجموعة وجميع رسائلها؟ لا يمكن التراجع عن هذا الإجراء.')) {
            const formData = new FormData();
            formData.append('group_id', groupId);

            fetch('internal_messages.php?action=delete_group', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        bootstrap.Modal.getInstance(document.getElementById('editGroupModal')).hide();
                        loadUsers();
                        if (currentChatType === 'group' && currentChatId == groupId) {
                            currentChatId = null;
                            document.getElementById('chatTitle').innerText = 'اختر محادثة للبدء';
                            document.getElementById('messagesContainer').innerHTML = '<div class="text-center text-muted mt-5"><p>اختر محادثة من القائمة الجانبية</p></div>';
                        }
                    } else {
                        alert(data.message);
                    }
                });
        }
    });

    // فتح محادثة
    function openChat(type, id, name, image = null, isOnline = false, lastSeen = null) {
        currentChatType = type;
        currentChatId = id;
        selectedMessageIds.clear();
        isSelectionMode = false;
        clearEditingMessage();
        document.getElementById('chatBulkActions').classList.add('d-none');
        document.getElementById('chatHeader').classList.remove('d-none');

        // إظهار/إخفاء أزرار المكالمات
        const callButtons = document.getElementById('callButtons');
        if (type === 'user') {
            callButtons.classList.remove('d-none');
            callButtons.classList.add('d-flex');
            currentCallPartnerId = id;
            currentCallPartnerName = name;
            currentCallPartnerImage = image;
        } else {
            callButtons.classList.add('d-none');
            callButtons.classList.remove('d-flex');
            currentCallPartnerId = null;
            currentCallPartnerName = null;
            currentCallPartnerImage = null;
        }

        // في الموبايل: إخفاء القائمة وإظهار المحادثة
        if (window.innerWidth < 768) {
            document.getElementById('sidePanel').classList.add('hide-mobile');
            document.getElementById('mainChatPanel').classList.add('show-mobile');
        }

        const chatTitle = document.getElementById('chatTitle');
        if (type === 'user') {
            const imgHtml = image ?
                `<img src="../assets/uploads/profiles/${image}" class="rounded-circle me-2" width="35" height="35" style="object-fit: cover;">` :
                `<div class="rounded-circle me-2 bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 35px; height: 35px;"><i class="fas fa-user fa-sm"></i></div>`;

            let subText = isOnline ? '<span class="text-success small">متصل الآن</span>' : '';
            if (!isOnline && lastSeen) {
                const lsDate = new Date(lastSeen);
                subText = `<span class="text-muted small">آخر ظهور: ${lsDate.toLocaleString('ar-SA')}</span>`;
            }

            chatTitle.innerHTML = `
                    <div class="d-flex align-items-center">
                        ${imgHtml}
                        <div class="d-flex flex-column">
                            <span class="fw-bold">${name}</span>
                            ${subText}
                        </div>
                    </div>
                `;
        } else {
            chatTitle.innerHTML = `
                    <div class="d-flex align-items-center justify-content-between w-100">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-users me-2 text-primary fa-lg"></i>
                            <div class="d-flex flex-column">
                                <span class="fw-bold">${name}</span>
                                <small id="groupMemberStatus" class="text-muted small" style="font-size: 0.75rem;">جاري التحميل...</small>
                            </div>
                        </div>
                        <?php if ($is_admin): ?>
                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openEditGroupModal(${id})">
                            <i class="fas fa-cog me-1"></i> الإعدادات
                        </button>
                        <?php endif; ?>
                    </div>
                `;
            updateGroupHeaderStatus(id);
        }

        // تحديث التحديد في القائمة
        document.querySelectorAll('.list-group-item').forEach(item => item.classList.remove('active'));

        if (messageRefreshInterval) clearInterval(messageRefreshInterval);
        loadMessages();
        messageRefreshInterval = setInterval(loadMessages, 3000);
    }

    // تحديث حالة المجموعة في الرأس (الأعضاء المتصلين والعدد الإجمالي)
    function updateGroupHeaderStatus(groupId) {
        if (currentChatType !== 'group' || currentChatId != groupId) return;

        fetch(`internal_messages.php?action=get_group_info&group_id=${groupId}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    const statusEl = document.getElementById('groupMemberStatus');
                    if (statusEl) {
                        statusEl.innerHTML = `${data.group.total_members} عضو • <span class="text-success">${data.group.online_members} متصل</span>`;
                    }
                }
            });
    }

    // تحميل الرسائل
    function loadMessages() {
        if (!currentChatId) return;

        // تحديث حالة المجموعة إذا كانت مجموعة
        if (currentChatType === 'group') {
            updateGroupHeaderStatus(currentChatId);
        }

        const lastId = currentVisibleMessageIds.length > 0 ? Math.max(...currentVisibleMessageIds) : 0;

        const url = currentChatType === 'user' ?
            `internal_messages.php?action=fetch&user=${currentChatId}&last_id=${lastId}` :
            `internal_messages.php?action=fetch_group&group=${currentChatId}&last_id=${lastId}`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('messagesContainer');
                const isAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 100;

                if (!data.messages || data.messages.length === 0) {
                    // لا توجد رسائل جديدة
                    return;
                }

                // إضافة الرسائل الجديدة فقط
                data.messages.forEach(msg => {
                    // تحقق أن الرسالة ليست موجودة بالفعل
                    if (currentVisibleMessageIds.includes(Number(msg.id))) {
                        return;
                    }

                    currentVisibleMessageIds.push(Number(msg.id));
                    currentMessagesCache.push(msg);

                    // Check if message is a signaling message
                    if (msg.message && msg.message.startsWith('{"type":"webrtc_signal"')) {
                        try {
                            const signalData = JSON.parse(msg.message);

                            // Skip if already processed
                            if (msg.id && processedSignalMsgIds.has(msg.id)) {
                                return;
                            }

                            if (!msg.is_own && ['accepted', 'rejected', 'busy', 'cancelled', 'missed', 'ended', 'expired'].includes(signalData.signalType)) {
                                processedSignalMsgIds.add(msg.id);
                                if (!currentCallId || !signalData.callId || String(signalData.callId) === String(currentCallId)) {
                                    handleRemoteCallStatus(signalData.signalType);
                                }
                                return;
                            }

                            if (!msg.is_own && !isCallActive && signalData.signalType === 'offer') {
                                // Handle incoming call signal
                                processedSignalMsgIds.add(msg.id);
                                currentCallType = signalData.callType;
                                currentCallPartnerId = msg.sender_id;
                                handleIncomingCall(signalData, msg.sender_id, msg.sender_name);
                            } else if (!msg.is_own && signalData.signalType === 'answer') {
                                // Handle answer signal
                                processedSignalMsgIds.add(msg.id);
                                currentCallStatus = 'accepted';
                                clearCallRingTimeout();
                                stopAllCallAudio();
                                if (peer) {
                                    peer.signal(signalData.data);
                                    // Update call status
                                    const remotePlaceholderStatus = document.querySelector('#remotePlaceholder .call-status');
                                    if (remotePlaceholderStatus) {
                                        remotePlaceholderStatus.innerHTML = `<i class="fas fa-circle fa-xs text-success me-1"></i><span>متصل</span>`;
                                    }
                                    // Also process any pending ICE candidates!
                                    if (pendingIceCandidates.length > 0) {
                                        pendingIceCandidates.forEach(candidate => {
                                            peer.signal(candidate);
                                        });
                                        pendingIceCandidates = [];
                                    }
                                }
                            } else if (!msg.is_own && signalData.signalType === 'candidate') {
                                // Handle ICE candidate
                                processedSignalMsgIds.add(msg.id);
                                if (peer) {
                                    peer.signal(signalData.data);
                                } else {
                                    pendingIceCandidates.push(signalData.data);
                                }
                            }
                            // Don't render signaling messages
                            return;
                        } catch (e) {
                            console.error('Error processing signaling message:', e);
                            // If it's not valid JSON, just render it as a normal message
                        }
                    }

                    const msgEl = document.createElement('div');
                    const msgId = Number(msg.id);
                    msgEl.className = `mb-3 d-flex message-row ${msg.is_own ? 'justify-content-end' : 'justify-content-start'} ${isSelectionMode ? 'is-selection-mode' : ''} ${selectedMessageIds.has(msgId) ? 'is-selected' : ''}`;
                    msgEl.dataset.messageId = msg.id;

                    const senderImg = msg.sender_image ?
                        `../assets/uploads/profiles/${msg.sender_image}` :
                        null;

                    const imgHtml = !msg.is_own ? (senderImg ?
                        `<img src="${senderImg}" class="rounded-circle me-2" width="30" height="30" style="object-fit: cover;" title="${msg.sender_name}">` :
                        `<div class="rounded-circle me-2 bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 30px; height: 30px;" title="${msg.sender_name}"><i class="fas fa-user fa-xs"></i></div>`) : '';

                    const actionsHtml = `
                            <div class="dropdown message-actions">
                                <button class="btn btn-link btn-sm text-muted p-0" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    ${msg.is_own ? `<li><a class="dropdown-item py-1 px-3 small" href="#" onclick="editMessage(${msg.id}, '${String(msg.message || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'").replace(/\n/g, "\\n")}'); return false;"><i class="fas fa-edit me-2"></i> تعديل</a></li>` : ''}
                                    <li><a class="dropdown-item py-1 px-3 small" href="#" onclick="deleteMessage(${msg.id}, 'for_me'); return false;"><i class="fas fa-user-times me-2"></i> حذف لدي</a></li>
                                    ${msg.is_own || (currentChatType === 'group' && <?php echo $is_admin ? 'true' : 'false'; ?>) ? `<li><a class="dropdown-item py-1 px-3 small text-danger" href="#" onclick="deleteMessage(${msg.id}, 'for_everyone'); return false;"><i class="fas fa-trash-alt me-2"></i> حذف لدى الجميع</a></li>` : ''}
                                </ul>
                            </div>
                        `;

                    msgEl.innerHTML = `
                            ${!msg.is_own ? imgHtml : ''}
                            <div class="message-wrapper">
                                <div class="message-bubble rounded-3 p-3 ${msg.is_own ? 'msg-own' : 'msg-other'}">
                                    ${currentChatType === 'group' && !msg.is_own ? `<div class="fw-bold small mb-1" style="color: var(--primary-color);">${msg.sender_name}</div>` : ''}
                                    ${renderAttachment(msg)}
                                    ${msg.message ? `<p class="mb-0" style="word-wrap: break-word; white-space: pre-wrap;">${msg.message}</p>` : ''}
                                    <div class="d-flex justify-content-between align-items-center mt-1" style="opacity: 0.7; font-size: 0.7rem;">
                                        <div class="d-flex align-items-center gap-1">
                                            <span>${new Date(msg.created_at).toLocaleTimeString('ar-SA', {hour: '2-digit', minute:'2-digit'})}</span>
                                            ${msg.is_edited == 1 ? '<span class="ms-1">(معدلة)</span>' : ''}
                                        </div>
                                        ${msg.is_own ? (msg.is_read == 1 ? '<i class="fas fa-check-double ms-1 text-white"></i>' : '<i class="fas fa-check ms-1"></i>') : ''}
                                    </div>
                                </div>
                                ${actionsHtml}
                            </div>
                        `;
                    msgEl.addEventListener('click', (event) => {
                        if (!isSelectionMode || event.target.closest('.message-actions, a, button, audio, video')) return;
                        toggleMessageSelection(msg.id);
                    });
                    msgEl.addEventListener('pointerdown', (event) => {
                        if (event.target.closest('.message-actions, a, button, audio, video')) return;
                        clearTimeout(longPressTimer);
                        longPressTimer = setTimeout(() => toggleMessageSelection(msg.id), 520);
                    });
                    ['pointerup', 'pointerleave', 'pointercancel'].forEach(eventName => {
                        msgEl.addEventListener(eventName, () => clearTimeout(longPressTimer));
                    });
                    container.appendChild(msgEl);
                });

                if (isAtBottom) {
                    container.scrollTop = container.scrollHeight;
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                document.getElementById('messagesContainer').innerHTML = '<div class="text-center text-danger mt-5"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><p>حدث خطأ أثناء تحميل الرسائل. يرجى المحاولة مرة أخرى.</p></div>';
            });
    }

    // تعديل رسالة
    function editMessage(id, oldMessage) {
        setEditingMessage(id, oldMessage);
        return;
        const newMessage = prompt('تعديل الرسالة:', oldMessage);
        if (newMessage !== null && newMessage.trim() !== '' && newMessage !== oldMessage) {
            const action = currentChatType === 'user' ? 'edit_message' : 'edit_group_message';
            const formData = new FormData();
            formData.append('message_id', id);
            formData.append('message', newMessage);

            fetch(`internal_messages.php?action=${action}`, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        loadMessages();
                    } else {
                        alert(data.message || 'حدث خطأ أثناء التعديل');
                    }
                });
        }
    }

    // حذف رسالة
    async function deleteMessage(id, type = 'for_me') {
        const title = type === 'for_everyone' ? 'هل أنت متأكد من حذف هذه الرسالة لدى الجميع؟' : 'هل أنت متأكد من حذف هذه الرسالة لديك فقط؟';
        const ok = await confirmDelete(title, 'لا يمكن التراجع عن هذا الإجراء بعد تنفيذه.');
        if (!ok) return;

        const data = await deleteMessageRequest(id, type);
        if (data.status === 'success') {
            loadMessages();
            Swal.fire({
                icon: 'success',
                title: 'تم الحذف',
                timer: 1200,
                showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'تعذر حذف الرسالة',
                text: data.message || 'حدث خطأ أثناء الحذف'
            });
        }
        return;
        const confirmMsg = type === 'for_everyone' ? 'هل أنت متأكد من حذف هذه الرسالة لدى الجميع؟' : 'هل أنت متأكد من حذف هذه الرسالة لديك فقط؟';
        if (confirm(confirmMsg)) {
            const action = currentChatType === 'user' ? 'delete_message' : 'delete_group_message';
            const formData = new FormData();
            formData.append('message_id', id);
            formData.append('type', type);

            fetch(`internal_messages.php?action=${action}`, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        loadMessages();
                    } else {
                        alert(data.message || 'حدث خطأ أثناء الحذف');
                    }
                });
        }
    }

    // إرسال رسالة
    document.getElementById('attachBtn').addEventListener('click', function() {
        openAttachmentSheet();
    });

    document.getElementById('imageUpload').addEventListener('change', function() {
        handleSelectedFile(this.files[0] || null);
    });

    document.getElementById('videoUpload').addEventListener('change', function() {
        handleSelectedFile(this.files[0] || null);
    });

    document.getElementById('documentUpload').addEventListener('change', function() {
        handleSelectedFile(this.files[0] || null);
    });

    document.getElementById('audioUpload').addEventListener('change', function() {
        handleSelectedFile(this.files[0] || null);
    });

    document.getElementById('attachmentSheetBackdrop').addEventListener('click', closeAttachmentSheet);

    document.querySelectorAll('[data-attach-choice]').forEach(button => {
        button.addEventListener('click', () => {
            const choice = button.dataset.attachChoice;
            closeAttachmentSheet();
            if (choice === 'image') chooseFileInput('imageUpload', 'image/*');
            if (choice === 'video') chooseFileInput('videoUpload', 'video/*');
            if (choice === 'document') chooseFileInput('documentUpload', '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar');
            if (choice === 'audio') chooseFileInput('audioUpload', '.mp3,.wav,.aac,.ogg,.m4a,audio/mpeg,audio/wav,audio/aac,audio/ogg,audio/mp4');
            if (choice === 'camera') openCameraModal();
            if (choice === 'location') sendLocationNow();
            if (choice === 'contact') sendContactCard();
        });
    });

    const cameraComposerBtn = document.getElementById('cameraBtn');
    if (cameraComposerBtn) {
        cameraComposerBtn.addEventListener('click', function() {
            openCameraModal();
        });
    }

    const locationComposerBtn = document.getElementById('locationBtn');
    if (locationComposerBtn) {
        locationComposerBtn.addEventListener('click', function() {
            sendLocationNow(this);
        });
    }

    function isTouchVoiceMode() {
        return window.matchMedia('(max-width: 767.98px), (pointer: coarse)').matches;
    }

    function setVoiceHintState(state) {
        const hint = document.getElementById('voiceGestureHint');
        if (!hint) return;
        hint.classList.toggle('d-none', state === 'hidden');
        hint.classList.toggle('is-lock-target', state === 'lock-target');
        const text = hint.querySelector('span');
        if (text) {
            text.textContent = state === 'locked' ? 'تم تثبيت التسجيل، اضغط للإرسال' : 'اسحب للأعلى للتثبيت';
        }
    }

    function resetVoiceButton(recordButton) {
        recordButton.classList.remove('is-recording', 'is-locked-recording');
        recordButton.innerHTML = '<i class="fas fa-microphone"></i>';
        setVoiceHintState('hidden');
        voiceRecordingLocked = false;
        voicePressActive = false;
        voicePointerId = null;
    }

    async function startVoiceRecording(recordButton) {
        if (!currentChatId) {
            alert('اختر محادثة أولاً');
            return false;
        }

        if (mediaRecorder && mediaRecorder.state === 'recording') {
            return true;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('المتصفح لا يدعم تسجيل الصوت');
            return false;
        }

        if (!isMediaFeatureAllowed('microphone')) {
            showToast('error', 'تعذر تشغيل الميكروفون', 'إعدادات الصفحة أو الإطار تمنع استخدام الميكروفون.');
            return false;
        }

        try {
            voiceCancelCurrent = false;
            voiceRecorderStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                }
            });
            recordedChunks = [];
            const options = getRecorderOptions('audio');
            mediaRecorder = new MediaRecorder(voiceRecorderStream, options);
            mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) recordedChunks.push(event.data);
            };
            mediaRecorder.onstop = () => {
                if (voiceRecorderStream) {
                    voiceRecorderStream.getTracks().forEach(track => track.stop());
                    voiceRecorderStream = null;
                }
                resetVoiceButton(recordButton);
                setPresence('idle');

                if (voiceCancelCurrent) {
                    recordedChunks = [];
                    voiceCancelCurrent = false;
                    return;
                }

                if (!recordedChunks.length) {
                    showToast('error', 'لم يتم تسجيل صوت', 'اضغط مرة أخرى واسمح للمتصفح باستخدام الميكروفون.');
                    return;
                }

                const mimeType = mediaRecorder.mimeType || options.mimeType || 'audio/webm';
                const ext = recorderExtension(mimeType, 'webm');
                const blob = new Blob(recordedChunks, {
                    type: mimeType
                });
                if (!blob.size) {
                    showToast('error', 'لم يتم تسجيل صوت', 'لم تصل أي بيانات من الميكروفون.');
                    return;
                }

                recordedAudioFile = makeUploadFile(blob, `voice_${Date.now()}.${ext}`);
                selectedAttachmentFile = null;
                document.getElementById('imageUpload').value = '';
                updateAttachmentPreview(recordedAudioFile);
                sendCurrentMessage();
            };
            mediaRecorder.onerror = (event) => {
                console.error('MediaRecorder error:', event.error || event);
                voiceCancelCurrent = true;
                if (voiceRecorderStream) {
                    voiceRecorderStream.getTracks().forEach(track => track.stop());
                    voiceRecorderStream = null;
                }
                resetVoiceButton(recordButton);
                setPresence('idle');
                showToast('error', 'تعذر تسجيل الصوت', explainMediaError(event.error || event, 'الميكروفون'));
            };
            mediaRecorder.start(250);
            recordButton.classList.add('is-recording');
            recordButton.innerHTML = '<i class="fas fa-stop"></i>';
            setVoiceHintState(isTouchVoiceMode() ? 'visible' : 'hidden');
            setPresence('recording');
            return true;
        } catch (error) {
            console.error('Recording error:', error);
            resetVoiceButton(recordButton);
            setPresence('idle');
            showToast('error', 'تعذر تشغيل الميكروفون', explainMediaError(error, 'الميكروفون'));
            return false;
        }
    }

    function stopVoiceRecording(cancel = false) {
        voiceCancelCurrent = cancel;
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        }
    }

    const recordBtn = document.getElementById('recordBtn');
    recordBtn.addEventListener('click', async function(event) {
        if (Date.now() < voiceSuppressClickUntil) {
            event.preventDefault();
            return;
        }

        if (isTouchVoiceMode()) {
            if (mediaRecorder && mediaRecorder.state === 'recording' && voiceRecordingLocked) {
                stopVoiceRecording(false);
            }
            return;
        }

        if (mediaRecorder && mediaRecorder.state === 'recording') {
            stopVoiceRecording(false);
            return;
        }
        await startVoiceRecording(this);
    });

    recordBtn.addEventListener('pointerdown', async function(event) {
        if (!isTouchVoiceMode() || event.button > 0) return;
        event.preventDefault();
        if (mediaRecorder && mediaRecorder.state === 'recording') return;

        voicePressActive = true;
        voicePressStartY = event.clientY;
        voicePointerId = event.pointerId;
        voiceSuppressClickUntil = Date.now() + 450;
        this.setPointerCapture?.(event.pointerId);

        const started = await startVoiceRecording(this);
        if (!started) {
            voicePressActive = false;
            voicePointerId = null;
        }
    });

    recordBtn.addEventListener('pointermove', function(event) {
        if (!voicePressActive || voicePointerId !== event.pointerId || voiceRecordingLocked) return;
        const movedUp = voicePressStartY - event.clientY;
        setVoiceHintState(movedUp > 42 ? 'lock-target' : 'visible');
        if (movedUp > 72) {
            voiceRecordingLocked = true;
            voicePressActive = false;
            this.classList.add('is-locked-recording');
            setVoiceHintState('locked');
            this.releasePointerCapture?.(event.pointerId);
        }
    });

    recordBtn.addEventListener('pointerup', function(event) {
        if (!isTouchVoiceMode() || voicePointerId !== event.pointerId) return;
        event.preventDefault();
        voiceSuppressClickUntil = Date.now() + 450;
        if (!voiceRecordingLocked && mediaRecorder && mediaRecorder.state === 'recording') {
            stopVoiceRecording(false);
        }
        voicePressActive = false;
        voicePointerId = null;
    });

    recordBtn.addEventListener('pointercancel', function(event) {
        if (!isTouchVoiceMode() || voicePointerId !== event.pointerId) return;
        stopVoiceRecording(true);
        voicePressActive = false;
        voicePointerId = null;
    });

    document.getElementById('messageForm').addEventListener('submit', function(e) {
        e.preventDefault();
        sendCurrentMessage();
        return;

        if (!currentChatId) {
            alert('اختر محادثة أولاً');
            return;
        }

        const formData = new FormData();
        const message = document.getElementById('messageInput').value;
        const image = document.getElementById('imageUpload').files[0];

        if (!message && !image) return;

        formData.append('message', message);
        if (image) formData.append('chat_image', image);

        if (currentChatType === 'user') {
            formData.append('receiver_id', currentChatId);
            fetch('internal_messages.php?action=send', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('messageInput').value = '';
                        document.getElementById('imageUpload').value = '';
                        loadMessages();
                    }
                });
        } else {
            formData.append('group_id', currentChatId);
            fetch('internal_messages.php?action=send_group', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('messageInput').value = '';
                        document.getElementById('imageUpload').value = '';
                        loadMessages();
                    }
                });
        }
    });

    document.getElementById('cancelEditBtn').addEventListener('click', function() {
        document.getElementById('messageInput').value = '';
        clearEditingMessage();
    });

    document.getElementById('toggleSelectMessagesBtn').addEventListener('click', function() {
        if (!currentChatId) return;
        setSelectionMode(!isSelectionMode);
    });

    document.getElementById('deleteSelectedMessagesBtn').addEventListener('click', async function() {
        updateSelectedMessages();
        const ids = Array.from(selectedMessageIds);
        if (!ids.length) {
            Swal.fire({
                icon: 'info',
                title: 'اختر رسالة واحدة على الأقل'
            });
            return;
        }

        const type = await chooseDeleteType(`حذف ${ids.length} رسالة محددة؟`);
        if (!type) return;

        const ok = await confirmDelete('تأكيد حذف الرسائل المحددة', `سيتم حذف ${ids.length} رسالة.`);
        if (!ok) return;

        await deleteMessages(ids, type);
    });

    document.getElementById('cancelSelectionBtn').addEventListener('click', function() {
        setSelectionMode(false);
    });

    document.getElementById('copySelectedMessagesBtn').addEventListener('click', copySelectedMessages);
    document.getElementById('shareSelectedMessagesBtn').addEventListener('click', shareSelectedMessages);
    document.getElementById('replySelectedMessagesBtn').addEventListener('click', replyToSelectedMessage);
    document.getElementById('forwardSelectedMessagesBtn').addEventListener('click', forwardSelectedMessages);

    document.getElementById('deleteAllMessagesBtn').addEventListener('click', async function() {
        await deleteAllCurrentConversation();
    });

    document.querySelectorAll('[data-chat-menu]').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            handleChatMenuAction(this.dataset.chatMenu);
        });
    });

    document.getElementById('capturePhotoBtn').addEventListener('click', captureCameraPhoto);
    document.getElementById('recordVideoBtn').addEventListener('click', toggleCameraVideoRecording);
    document.getElementById('cameraModal').addEventListener('hidden.bs.modal', closeCameraModal);
    document.getElementById('cameraModal').addEventListener('hide.bs.modal', function() {
        if (cameraRecorder && cameraRecorder.state === 'recording') {
            cancelCameraRecording = true;
            cameraRecorder.stop();
        }
    });

    // تبديل إعدادات التنبيهات
    document.getElementById('soundToggle').addEventListener('change', function() {
        fetch('internal_messages.php?action=toggle_notification', {
            method: 'POST',
            body: new URLSearchParams({
                type: 'sound',
                enabled: this.checked ? 1 : 0
            })
        });
    });

    document.getElementById('notificationToggle').addEventListener('change', function() {
        fetch('internal_messages.php?action=toggle_notification', {
            method: 'POST',
            body: new URLSearchParams({
                type: 'notification',
                enabled: this.checked ? 1 : 0
            })
        });
    });

    // تحميل البيانات الأولية بعد تحميل DOM
    document.addEventListener('DOMContentLoaded', () => {
        loadUsers();
        // تحديث قائمة المحادثات كل 5 ثوانٍ
        if (usersRefreshInterval) clearInterval(usersRefreshInterval);
        usersRefreshInterval = setInterval(() => {
            if (!isLoadingUsers) {
                loadUsers();
            }
        }, 5000);

        // فتح الـ modal لإنشاء مجموعة
        const createGroupModal = document.getElementById('createGroupModal');
        if (createGroupModal) {
            createGroupModal.addEventListener('show.bs.modal', function() {
                fetch('internal_messages.php?action=get_users')
                    .then(r => r.json())
                    .then(data => {
                        const membersList = document.getElementById('membersList');
                        membersList.innerHTML = '';
                        data.users.forEach(user => {
                            const div = document.createElement('div');
                            div.className = 'form-check mb-2';
                            div.innerHTML = `
                                    <input class="form-check-input member-checkbox" type="checkbox" value="${user.id}" id="user_${user.id}">
                                    <label class="form-check-label" for="user_${user.id}">
                                        ${user.full_name} (@${user.username})
                                    </label>
                                `;
                            membersList.appendChild(div);
                        });
                    });
            });
        }

        // إنشاء مجموعة
        const createGroupBtn = document.getElementById('createGroupBtn');
        if (createGroupBtn) {
            createGroupBtn.addEventListener('click', function() {
                const name = document.getElementById('groupName').value;
                const description = document.getElementById('groupDescription').value;
                const selectedMembers = Array.from(document.querySelectorAll('.member-checkbox:checked')).map(cb => cb.value);

                if (!name) {
                    alert('يرجى إدخال اسم المجموعة');
                    return;
                }

                const formData = new FormData();
                formData.append('name', name);
                formData.append('description', description);
                selectedMembers.forEach(id => formData.append('members[]', id));

                fetch('internal_messages.php?action=create_group', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            bootstrap.Modal.getInstance(createGroupModal).hide();
                            loadGroups();
                            // إعادة تعيين النموذج
                            document.getElementById('groupName').value = '';
                            document.getElementById('groupDescription').value = '';
                        } else {
                            alert(data.message || 'حدث خطأ أثناء إنشاء المجموعة');
                        }
                    });
            });
        }

        // زر العودة لقائمة المحادثات في الموبايل
        const backToListBtn = document.getElementById('backToListBtn');
        if (backToListBtn) {
            backToListBtn.addEventListener('click', function() {
                document.getElementById('sidePanel').classList.remove('hide-mobile');
                document.getElementById('mainChatPanel').classList.remove('show-mobile');
                currentChatId = null;
                document.getElementById('chatBulkActions').classList.add('d-none');
                selectedMessageIds.clear();
                isSelectionMode = false;
                clearEditingMessage();
                if (messageRefreshInterval) clearInterval(messageRefreshInterval);
            });
        }

        // تحسين التعامل مع لوحة المفاتيح في الهواتف
        const messageInput = document.getElementById('messageInput');
        const messagesContainer = document.getElementById('messagesContainer');

        if (window.innerWidth < 768 && messageInput && messagesContainer) {
            messageInput.addEventListener('focus', function() {
                setTimeout(() => {
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    // التمرير للعنصر نفسه للتأكد من ظهوره
                    messageInput.scrollIntoView({
                        behavior: 'smooth',
                        block: 'end'
                    });
                }, 300);
            });

            // مراقبة الـ Visual Viewport للتعامل مع ارتفاع لوحة المفاتيح بشكل أدق
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', () => {
                    if (currentChatId && document.getElementById('mainChatPanel').classList.contains('show-mobile')) {
                        const offset = window.innerHeight - window.visualViewport.height;
                        if (offset > 100) { // لوحة المفاتيح مفتوحة
                            document.getElementById('mainChatPanel').style.bottom = `${offset}px`;
                        } else {
                            document.getElementById('mainChatPanel').style.bottom = '0';
                        }
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                });
            }
        }

        // معالجة تغيير حجم النافذة للتأكد من ظهور العناصر بشكل صحيح
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                document.getElementById('sidePanel').classList.remove('hide-mobile');
                document.getElementById('mainChatPanel').classList.remove('show-mobile');
            } else {
                if (currentChatId) {
                    document.getElementById('sidePanel').classList.add('hide-mobile');
                    document.getElementById('mainChatPanel').classList.add('show-mobile');
                }
            }
        });

        // Initialize call modal and buttons
        initCallModal();
        cleanupStaleCallsOnPageLoad();
        const audioBtn = document.getElementById('audioCallBtn');
        const videoBtn = document.getElementById('videoCallBtn');
        const acceptCallBtn = document.getElementById('acceptCallBtn');
        const rejectCallBtn = document.getElementById('rejectCallBtn');
        const toggleAudioBtn = document.getElementById('toggleAudioBtn');
        const toggleSpeakerBtn = document.getElementById('toggleSpeakerBtn');
        const toggleVideoBtn = document.getElementById('toggleVideoBtn');
        const switchCameraBtn = document.getElementById('switchCameraBtn');
        const shareScreenBtn = document.getElementById('shareScreenBtn');
        const addParticipantsBtn = document.getElementById('addParticipantsBtn');
        const toggleChatBtn = document.getElementById('toggleChatBtn');
        const raiseHandBtn = document.getElementById('raiseHandBtn');
        const reactionsBtn = document.getElementById('reactionsBtn');
        const endCallBtn = document.getElementById('endCallBtn');
        const moreControlsBtn = document.getElementById('moreControlsBtn');

        // Remove any previous bindings to prevent duplicates
        [audioBtn, videoBtn, acceptCallBtn, rejectCallBtn, toggleAudioBtn, toggleSpeakerBtn, toggleVideoBtn, switchCameraBtn, shareScreenBtn, addParticipantsBtn, toggleChatBtn, raiseHandBtn, reactionsBtn, endCallBtn, moreControlsBtn].forEach(btn => {
            if (btn) {
                const newBtn = btn.cloneNode(true);
                btn.parentNode.replaceChild(newBtn, btn);
            }
        });

        // Re-fetch elements after cloning
        const audioBtn_new = document.getElementById('audioCallBtn');
        const videoBtn_new = document.getElementById('videoCallBtn');
        const acceptCallBtn_new = document.getElementById('acceptCallBtn');
        const rejectCallBtn_new = document.getElementById('rejectCallBtn');
        const toggleAudioBtn_new = document.getElementById('toggleAudioBtn');
        const toggleSpeakerBtn_new = document.getElementById('toggleSpeakerBtn');
        const toggleVideoBtn_new = document.getElementById('toggleVideoBtn');
        const switchCameraBtn_new = document.getElementById('switchCameraBtn');
        const shareScreenBtn_new = document.getElementById('shareScreenBtn');
        const addParticipantsBtn_new = document.getElementById('addParticipantsBtn');
        const toggleChatBtn_new = document.getElementById('toggleChatBtn');
        const raiseHandBtn_new = document.getElementById('raiseHandBtn');
        const reactionsBtn_new = document.getElementById('reactionsBtn');
        const endCallBtn_new = document.getElementById('endCallBtn');

        bindCallButton(audioBtn_new, () => startCall('audio', 'user', currentCallPartnerId));
        bindCallButton(videoBtn_new, () => startCall('video', 'user', currentCallPartnerId));
        bindCallButton(acceptCallBtn_new, acceptCall);
        bindCallButton(rejectCallBtn_new, rejectCall);
        bindCallButton(toggleAudioBtn_new, toggleAudio);
        bindCallButton(toggleSpeakerBtn_new, toggleSpeaker);
        bindCallButton(toggleVideoBtn_new, toggleVideo);
        bindCallButton(switchCameraBtn_new, switchCamera);
        bindCallButton(shareScreenBtn_new, shareScreen);
        bindCallButton(addParticipantsBtn_new, addParticipants);
        bindCallButton(toggleChatBtn_new, toggleChat);
        bindCallButton(raiseHandBtn_new, raiseHand);
        bindCallButton(reactionsBtn_new, toggleReactions);
        bindCallButton(endCallBtn_new, endCall);

        const moreControlsBtn_new = document.getElementById('moreControlsBtn');
        bindCallButton(moreControlsBtn_new, toggleMoreControls);

        // Bind reaction buttons
        const reactionsPanel = document.getElementById('reactionsPanel');
        if (reactionsPanel) {
            reactionsPanel.querySelectorAll('.reaction-btn').forEach(btn => {
                btn.addEventListener('click', () => sendReaction(btn.dataset.reaction));
            });
        }
        if (document.getElementById('callModal')) {
            document.getElementById('callModal').addEventListener('hidden.bs.modal', () => {
                if (currentCallId && !callCleanupInProgress && !terminalCallStatuses.has(currentCallStatus)) {
                    endCall();
                }
            });
        }
    });

    // ==================== CALL FUNCTIONS ====================

    function bindCallButton(button, handler) {
        if (!button || button.dataset.callBound === '1') return;
        button.dataset.callBound = '1';
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            handler(event);
        });
    }

    function initCallModal() {
        callModal = new bootstrap.Modal(document.getElementById('callModal'));
    }

    function cleanupStaleCallsOnPageLoad() {
        fetch('internal_messages.php?action=cleanup_my_calls', {
            method: 'POST',
            keepalive: true
        }).catch(() => {});
    }

    function initAudioContext() {
        if (!audioContext) {
            audioContext = new(window.AudioContext || window.webkitAudioContext)();
        }
    }

    function startRingtone() {
        initAudioContext();
        if (isRingtonePlaying || ringtoneInterval) return;
        isRingtonePlaying = true;

        function playRing() {
            // Create oscillator for ring tone
            const osc = audioContext.createOscillator();
            const gain = audioContext.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(440, audioContext.currentTime); // A4
            osc.connect(gain);
            gain.connect(audioContext.destination);

            gain.gain.setValueAtTime(0, audioContext.currentTime);
            gain.gain.linearRampToValueAtTime(0.3, audioContext.currentTime + 0.05);
            gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.4);

            osc.start(audioContext.currentTime);
            osc.stop(audioContext.currentTime + 0.4);

            // Second tone
            const secondToneTimeout = setTimeout(() => {
                if (!isRingtonePlaying) return;
                const osc2 = audioContext.createOscillator();
                const gain2 = audioContext.createGain();
                osc2.type = 'sine';
                osc2.frequency.setValueAtTime(480, audioContext.currentTime); // B4
                osc2.connect(gain2);
                gain2.connect(audioContext.destination);

                gain2.gain.setValueAtTime(0, audioContext.currentTime);
                gain2.gain.linearRampToValueAtTime(0.3, audioContext.currentTime + 0.05);
                gain2.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.4);

                osc2.start(audioContext.currentTime);
                osc2.stop(audioContext.currentTime + 0.4);
            }, 200);
            ringtoneToneTimeouts.push(secondToneTimeout);
        }

        playRing();
        ringtoneInterval = setInterval(playRing, 1500);
    }

    function stopRingtone() {
        if (ringtoneInterval) {
            clearInterval(ringtoneInterval);
            ringtoneInterval = null;
        }
        ringtoneToneTimeouts.forEach(timeoutId => clearTimeout(timeoutId));
        ringtoneToneTimeouts = [];
        isRingtonePlaying = false;
        const audio = document.getElementById('ringtoneAudio');
        if (audio) {
            audio.pause();
            audio.currentTime = 0;
        }
    }

    function startDialtone() {
        initAudioContext();
        if (isDialtonePlaying || dialtoneInterval) return;
        isDialtonePlaying = true;

        function playDial() {
            const osc = audioContext.createOscillator();
            const gain = audioContext.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(350, audioContext.currentTime);
            osc.connect(gain);
            gain.connect(audioContext.destination);

            gain.gain.setValueAtTime(0, audioContext.currentTime);
            gain.gain.linearRampToValueAtTime(0.2, audioContext.currentTime + 0.05);
            gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.3);

            osc.start(audioContext.currentTime);
            osc.stop(audioContext.currentTime + 0.3);
        }

        playDial();
        dialtoneInterval = setInterval(playDial, 500);
    }

    function stopDialtone() {
        if (dialtoneInterval) {
            clearInterval(dialtoneInterval);
            dialtoneInterval = null;
        }
        isDialtonePlaying = false;
        const audio = document.getElementById('dialtoneAudio');
        if (audio) {
            audio.pause();
            audio.currentTime = 0;
        }
    }

    // ==== الميزات الجديدة: التبويبات والمكالمات ====

    // إدارة التبويبات
    let currentSidebarTab = 'chats';
    document.querySelectorAll('#sidebarTabs [data-tab]').forEach(btn => {
        btn.addEventListener('click', function() {
            const tab = this.dataset.tab;
            switchSidebarTab(tab);
        });
    });

    function switchSidebarTab(tab) {
        currentSidebarTab = tab;
        // إزالة active من جميع الأزرار
        document.querySelectorAll('#sidebarTabs [data-tab]').forEach(b => b.classList.remove('active'));
        // إضافة active للزر المختار
        const activeBtn = document.querySelector(`#sidebarTabs [data-tab="${tab}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        // إخفاء جميع المحتويات
        document.querySelectorAll('#sidebarTabContent .tab-pane').forEach(p => {
            p.classList.remove('show', 'active');
        });

        // إظهار المحتوى المختار
        const activePane = document.getElementById(`tabContent-${tab}`);
        if (activePane) {
            activePane.classList.add('show', 'active');
        }

        // تحميل المحتوى حسب التبويب
        if (tab === 'chats') {
            loadUsers();
        } else if (tab === 'groups') {
            loadGroups();
        } else if (tab === 'calls') {
            loadCalls();
            loadMissedCalls();
        }
    }

    // تحميل المجموعات
    function loadGroups() {
        fetch('internal_messages.php?action=get_groups')
            .then(r => r.json())
            .then(data => {
                const groupsList = document.getElementById('groupsList');
                if (!groupsList) return;
                groupsList.innerHTML = '';
                if (data.groups && data.groups.length > 0) {
                    data.groups.forEach(group => {
                        const item = document.createElement('div');
                        item.className = 'list-group-item list-group-item-action d-flex align-items-center gap-3 p-3';
                        item.innerHTML = `
                                <div class="rounded-circle bg-primary bg-gradient d-flex align-items-center justify-content-center text-white" style="width:45px;height:45px;">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="flex-grow-1 min-width-0">
                                    <div class="fw-bold text-truncate">${group.name}</div>
                                    <div class="text-muted small text-truncate">${group.description || 'لا يوجد وصف'} • ${group.msg_count || 0} رسالة</div>
                                </div>
                            `;
                        item.addEventListener('click', () => openChat('group', group.id, group.name));
                        groupsList.appendChild(item);
                    });
                } else {
                    groupsList.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-users fa-3x mb-3 opacity-50"></i><div>لا توجد مجموعات بعد</div></div>';
                }
            });
    }

    // تحميل المكالمات
    function loadCalls() {
        fetch('internal_messages.php?action=get_calls')
            .then(r => r.json())
            .then(data => {
                const callsList = document.getElementById('callsList');
                if (!callsList) return;
                callsList.innerHTML = '';
                if (data.calls && data.calls.length > 0) {
                    data.calls.forEach(call => {
                        renderCallItem(callsList, call);
                    });
                } else {
                    callsList.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-phone-slash fa-3x mb-3 opacity-50"></i><div>لا توجد مكالمات بعد</div></div>';
                }
            });
    }

    // تحميل المكالمات الفائتة
    function loadMissedCalls() {
        fetch('internal_messages.php?action=get_missed_calls')
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('missedCallsBadge');
                if (!badge) return;
                const missedCount = data.missed_calls ? data.missed_calls.length : 0;
                if (missedCount > 0) {
                    badge.textContent = missedCount;
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }
            });
    }

    // عرض عنصر مكالمة
    function renderCallItem(container, call) {
        const isIncoming = call.receiver_id && call.receiver_id == <?php echo $current_user_id; ?>;
        const name = isIncoming ? call.caller_name : (call.receiver_name || call.group_name || 'مجموعة');
        const isMissed = call.status === 'missed';

        const item = document.createElement('div');
        item.className = `list-group-item list-group-item-action d-flex align-items-center gap-3 p-3 ${isMissed ? 'bg-light border-start border-4 border-danger' : ''}`;

        let callIcon, callColor;
        if (call.call_type === 'video') {
            callIcon = 'fa-video';
            callColor = 'primary';
        } else {
            callIcon = 'fa-phone-alt';
            callColor = 'success';
        }

        let arrowIcon = isIncoming ? 'fa-arrow-down' : 'fa-arrow-up';
        let arrowColor = isMissed ? 'text-danger' : 'text-success';

        let durationText = '';
        if (call.duration) {
            const mins = Math.floor(call.duration / 60);
            const secs = call.duration % 60;
            durationText = ` • ${mins}:${secs.toString().padStart(2, '0')}`;
        }

        const date = new Date(call.created_at);
        const timeAgo = getTimeAgo(date);

        const profileImage = isIncoming ? call.caller_image : call.receiver_image;
        const imgHtml = profileImage ?
            `<img src="../assets/uploads/profiles/${profileImage}" class="rounded-circle" width="45" height="45" style="object-fit: cover;">` :
            `<div class="rounded-circle bg-secondary bg-gradient d-flex align-items-center justify-content-center text-white" style="width:45px;height:45px;">
                    <i class="fas fa-user"></i>
                </div>`;

        item.innerHTML = `
                <div class="position-relative">
                    ${imgHtml}
                    <span class="position-absolute bottom-0 end-0 bg-${callColor} text-white rounded-circle p-1" style="font-size:0.7rem;">
                        <i class="fas ${callIcon}"></i>
                    </span>
                </div>
                <div class="flex-grow-1 min-width-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-truncate">${name}</span>
                        <span class="text-muted small">${timeAgo}</span>
                    </div>
                    <div class="text-muted small d-flex align-items-center gap-2">
                        <i class="fas ${arrowIcon} ${arrowColor}"></i>
                        <span>${getCallStatusText(call.status)}${durationText}</span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-${callColor} rounded-circle" title="إعادة الاتصال" onclick="retryCall('${call.call_type}', ${call.receiver_id || 0}, ${call.group_id || 0})">
                        <i class="fas ${callIcon}"></i>
                    </button>
                </div>
            `;

        container.appendChild(item);
    }

    // إعادة الاتصال
    function retryCall(type, userId, groupId) {
        if (userId) {
            startCall(type, 'user', userId);
        } else if (groupId) {
            startCall(type, 'group', groupId);
        }
    }

    // الحصول على نص حالة المكالمة
    function getCallStatusText(status) {
        const statuses = {
            'calling': 'جاري الاتصال',
            'ringing': 'يرن',
            'accepted': 'تم الرد',
            'rejected': 'تم الرفض',
            'missed': ' مكالمة فائتة',
            'ended': 'تم الانتهاء',
            'busy': 'مشغول',
            'cancelled': 'ملغاة',
            'expired': 'منتهية'
        };
        return statuses[status] || status;
    }

    // دالة مساعدة لحساب الوقت المنقضي
    function getTimeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);
        let interval = seconds / 31536000;
        if (interval > 1) return Math.floor(interval) + " سنة مضت";
        interval = seconds / 2592000;
        if (interval > 1) return Math.floor(interval) + " شهر مضت";
        interval = seconds / 86400;
        if (interval > 1) return Math.floor(interval) + " يوم مضت";
        interval = seconds / 3600;
        if (interval > 1) return Math.floor(interval) + " ساعة مضت";
        interval = seconds / 60;
        if (interval > 1) return Math.floor(interval) + " دقيقة مضت";
        return "الآن";
    }

    // تحديث دوال المكالمات لاستخدام قاعدة البيانات
    // دالة بدء مكالمة محدثة
    const terminalCallStatuses = new Set(['rejected', 'busy', 'cancelled', 'missed', 'ended', 'expired']);
    const activeCallStatuses = new Set(['calling', 'ringing', 'accepted']);

    function setCallTypeUI(type, direction = 'outgoing') {
        const isVideo = type === 'video';
        const icon = document.getElementById('callTypeIcon');
        const text = document.getElementById('callTypeText');
        const meta = document.getElementById('callMetaTypeText');
        if (icon) icon.className = isVideo ? 'fas fa-video' : 'fas fa-phone-alt';
        if (text) text.textContent = isVideo ?
            (direction === 'incoming' ? 'مكالمة فيديو واردة' : 'مكالمة فيديو') :
            (direction === 'incoming' ? 'مكالمة صوتية واردة' : 'مكالمة صوتية');
        if (meta) meta.textContent = isVideo ? 'فيديو' : 'صوت';
    }

    function setCallParticipantUI(name, image = null) {
        const nameEl = document.getElementById('callingUser');
        const img = document.getElementById('callAvatarImg');
        const icon = document.getElementById('callAvatarIcon');
        if (nameEl) nameEl.textContent = name || 'جاري الاتصال...';
        if (img && image) {
            img.src = `../assets/uploads/profiles/${image}`;
            img.classList.remove('d-none');
            if (icon) icon.classList.add('d-none');
        } else {
            if (img) {
                img.removeAttribute('src');
                img.classList.add('d-none');
            }
            if (icon) icon.classList.remove('d-none');
        }
    }

    function setCallStatusUI(text, ringing = false) {
        const statusText = document.getElementById('callStatusText');
        const avatar = document.getElementById('callAvatarWrap');
        if (statusText) statusText.textContent = text;
        if (avatar) avatar.classList.toggle('is-ringing', ringing);
    }

    function formatCallDuration(totalSeconds) {
        const mins = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
        const secs = Math.floor(totalSeconds % 60).toString().padStart(2, '0');
        return `${mins}:${secs}`;
    }

    function startCallDurationTimer() {
        if (callDurationInterval) clearInterval(callDurationInterval);
        callAcceptedAt = Date.now();
        const durationEl = document.getElementById('callDurationText');
        if (durationEl) durationEl.textContent = '00:00';
        callDurationInterval = setInterval(() => {
            if (!callAcceptedAt || !durationEl) return;
            durationEl.textContent = formatCallDuration(Math.floor((Date.now() - callAcceptedAt) / 1000));
        }, 1000);
    }

    function stopCallDurationTimer() {
        if (callDurationInterval) {
            clearInterval(callDurationInterval);
            callDurationInterval = null;
        }
        callAcceptedAt = null;
    }

    function stopAllCallAudio() {
        stopRingtone();
        stopDialtone();
        ['ringtoneAudio', 'dialtoneAudio'].forEach(id => {
            const audio = document.getElementById(id);
            if (audio) {
                audio.pause();
                audio.currentTime = 0;
            }
        });
    }

    function resetCallControlsUI() {
        const shareScreenBtn = document.getElementById('shareScreenBtn');
        if (shareScreenBtn) {
            shareScreenBtn.classList.remove('active', 'off');
            shareScreenBtn.innerHTML = '<i class="fas fa-desktop"></i>';
        }
        ['toggleAudioBtn', 'toggleVideoBtn', 'toggleSpeakerBtn', 'raiseHandBtn'].forEach(id => {
            const btn = document.getElementById(id);
            if (btn) btn.classList.remove('off', 'active', 'hand-raised');
        });
        // إعادة تسميات الأزرار
        const audioLabel = document.getElementById('audioLabel');
        if (audioLabel) audioLabel.textContent = 'ميكروفون';
        const speakerLabel = document.getElementById('speakerLabel');
        if (speakerLabel) speakerLabel.textContent = 'مكبر';
        const videoLabel = document.getElementById('videoLabel');
        if (videoLabel) videoLabel.textContent = 'كاميرا';
        const reactionsPanel = document.getElementById('reactionsPanel');
        if (reactionsPanel) reactionsPanel.style.display = 'none';
    }

    /**
     * دالة مركزية لإظهار/إخفاء أزرار المكالمة حسب الحالة
     * @param {'incoming'|'outgoing'|'active'|'hidden'} state
     * @param {'audio'|'video'} callType
     */
    function setCallControlsState(state, callType) {
        const incomingBar = document.getElementById('incomingCallUI');
        const activeBar = document.getElementById('callControls');
        const videoWraps = document.querySelectorAll('.call-video-only');

        // إخفاء كل شيء أولاً
        if (incomingBar) incomingBar.style.display = 'none';
        if (activeBar) activeBar.style.display = 'none';

        const isVideo = (callType === 'video');

        if (state === 'incoming') {
            // — مكالمة واردة: رد + رفض فقط
            if (incomingBar) {
                incomingBar.style.display = 'flex';
                const label = document.getElementById('incomingCallLabel');
                if (label) label.textContent = isVideo ? 'مكالمة فيديو واردة' : 'مكالمة صوتية واردة';
            }
        } else if (state === 'outgoing' || state === 'active') {
            // — مكالمة صادرة أو جارية
            if (activeBar) activeBar.style.display = 'flex';
            // أزرار الفيديو: تظهر فقط لمكالمات الفيديو
            videoWraps.forEach(el => {
                el.style.display = isVideo ? 'flex' : 'none';
            });

            // تحديث عناصر قائمة المزيد أيضاً
            const switchCam = document.getElementById('switchCameraBtnMore');
            const shareScr = document.getElementById('shareScreenBtnMore');
            if (switchCam) switchCam.style.display = isVideo ? 'flex' : 'none';
            if (shareScr) shareScr.style.display = isVideo ? 'flex' : 'none';
        }
        // state === 'hidden': كل شيء مخفي (already done above)

        // إخفاء قائمة المزيد عند تغيير الحالة
        const moreMenu = document.getElementById('moreControlsMenu');
        if (moreMenu) moreMenu.style.display = 'none';
    }

    // دالة تبديل قائمة المزيد
    function toggleMoreControls() {
        const moreMenu = document.getElementById('moreControlsMenu');
        if (!moreMenu) return;

        const isHidden = (moreMenu.style.display === 'none');
        moreMenu.style.display = isHidden ? 'flex' : 'none';

        if (isHidden) {
            // تحديث ظهور العناصر داخل القائمة حسب نوع المكالمة
            const isVideo = (currentCallType === 'video');
            const videoItems = moreMenu.querySelectorAll('.call-video-only');
            videoItems.forEach(el => {
                el.style.display = isVideo ? 'flex' : 'none';
            });
        }
    }

    // إغلاق قائمة المزيد عند الضغط خارجها
    document.addEventListener('click', (e) => {
        const moreMenu = document.getElementById('moreControlsMenu');
        const moreBtn = document.getElementById('moreControlsBtn');
        if (moreMenu && moreMenu.style.display === 'flex' && !moreMenu.contains(e.target) && !moreBtn.contains(e.target)) {
            moreMenu.style.display = 'none';
        }
    });

    function showBusyCallNotice(message, retryHandler = null) {
        stopAllCallAudio();
        clearCallRingTimeout();
        const text = message || 'المستخدم مشغول حاليا في مكالمة أخرى.';
        if (window.Swal) {
            Swal.fire({
                icon: 'info',
                title: 'المستخدم مشغول',
                text,
                showCancelButton: Boolean(retryHandler),
                confirmButtonText: retryHandler ? 'إعادة المحاولة لاحقا' : 'موافق',
                cancelButtonText: 'موافق'
            }).then(result => {
                if (retryHandler && result.isConfirmed) {
                    setTimeout(retryHandler, 3000);
                }
            });
        } else {
            showToast('warning', text);
        }
    }

    function clearCallTimers() {
        if (callRingTimeout) {
            clearTimeout(callRingTimeout);
            callRingTimeout = null;
        }
        if (callStatusInterval) {
            clearInterval(callStatusInterval);
            callStatusInterval = null;
        }
        stopCallDurationTimer();
    }

    function clearCallRingTimeout() {
        if (callRingTimeout) {
            clearTimeout(callRingTimeout);
            callRingTimeout = null;
        }
    }

    async function updateCallStatus(status) {
        if (!currentCallId) return null;
        const formData = new FormData();
        formData.append('call_id', currentCallId);
        formData.append('status', status);
        const response = await fetch('internal_messages.php?action=update_call_status', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.status !== 'success') {
            throw new Error(data.message || 'Call status update failed');
        }
        currentCallStatus = status;
        return data;
    }

    async function getActiveCall(callId) {
        if (!callId) return null;
        try {
            const response = await fetch(`internal_messages.php?action=get_active_call&call_id=${encodeURIComponent(callId)}`);
            const data = await response.json();
            if (data.status !== 'success' || !data.call || !activeCallStatuses.has(data.call.status)) {
                return null;
            }
            return data.call;
        } catch (error) {
            console.error('Active call check error:', error);
            return null;
        }
    }

    function startCallStatusPolling() {
        if (!currentCallId || callStatusInterval) return;
        callStatusInterval = setInterval(async () => {
            try {
                const response = await fetch(`internal_messages.php?action=get_call_status&call_id=${currentCallId}`);
                const data = await response.json();
                if (data.status !== 'success' || !data.call) return;
                const remoteStatus = data.call.status;
                if (remoteStatus === currentCallStatus) return;
                currentCallStatus = remoteStatus;
                if (remoteStatus === 'accepted') {
                    clearCallRingTimeout();
                    stopAllCallAudio();
                    setCallStatusUI('متصل', false);
                    if (!callDurationInterval) startCallDurationTimer();
                } else if (terminalCallStatuses.has(remoteStatus)) {
                    finishCallLocally(remoteStatus);
                }
            } catch (error) {
                console.error('Call status polling error:', error);
            }
        }, 1000);
    }

    function finishCallLocally(status = 'ended') {
        if (callCleanupInProgress) return;
        callCleanupInProgress = true;
        clearCallTimers();
        stopAllCallAudio();

        if (peer) {
            try {
                peer.destroy();
            } catch (e) {
                console.error('Error destroying peer:', e);
            }
            peer = null;
        }
        if (localStream) {
            localStream.getTracks().forEach(track => track.stop());
            localStream = null;
        }
        isScreenSharing = false;
        originalVideoTrack = null;
        resetCallControlsUI();

        const remoteVideo = document.getElementById('remoteVideo');
        const localVideo = document.getElementById('localVideo');
        if (remoteVideo) {
            remoteVideo.srcObject = null;
            remoteVideo.style.display = 'none';
        }
        const remotePlaceholder = document.getElementById('remotePlaceholder');
        if (remotePlaceholder) remotePlaceholder.style.display = 'flex';
        if (localVideo) {
            localVideo.srcObject = null;
            localVideo.style.display = 'none';
        }
        const localVideoWrapper = document.querySelector('.local-video-wrapper');
        if (localVideoWrapper) localVideoWrapper.style.display = 'none';
        // — إخفاء جميع أزرار المكالمة عند الانتهاء
        setCallControlsState('hidden', 'audio');

        isCallActive = false;
        isCaller = false;
        isAudioMuted = false;
        isVideoMuted = false;
        currentCallType = null;
        currentCallTargetType = 'user';
        currentCallTargetId = null;
        currentCallStatus = terminalCallStatuses.has(status) ? status : currentCallStatus;
        currentCallId = null;
        pendingSignal = null;
        pendingIceCandidates = [];

        if (callModal) callModal.hide();
        if (currentSidebarTab === 'calls') {
            loadCalls();
            loadMissedCalls();
        }
        setTimeout(() => {
            callCleanupInProgress = false;
        }, 0);
    }

    async function finishCall(status = 'ended', notify = true) {
        if (callCleanupInProgress) return;
        const callId = currentCallId;
        if (callId && currentCallStatus !== status) {
            try {
                await updateCallStatus(status);
            } catch (error) {
                console.error('Call status update error:', error);
                showToast('error', 'حدث خطأ في تحديث حالة المكالمة', error.message);
            }
        }
        if (notify && callId) {
            sendSignalingMessage(status, {
                status
            });
        }
        finishCallLocally(status);
    }

    function armRingTimeout() {
        if (callRingTimeout) clearTimeout(callRingTimeout);
        callRingTimeout = setTimeout(() => {
            if (currentCallId && currentCallStatus !== 'accepted') {
                finishCall('missed', true);
            }
        }, CALL_RING_TIMEOUT_MS);
    }

    function handleRemoteCallStatus(status) {
        if (status === 'accepted') {
            currentCallStatus = 'accepted';
            clearCallRingTimeout();
            stopAllCallAudio();
            setCallStatusUI('متصل', false);
            return;
        }
        if (status === 'busy') {
            showBusyCallNotice('المستخدم مشغول حاليا في مكالمة أخرى.');
        }
        if (terminalCallStatuses.has(status)) {
            finishCallLocally(status);
        }
    }

    function resolveCallTarget(targetType = null, targetId = null) {
        const resolvedType = targetType || currentChatType || 'user';
        const resolvedId = targetId || (resolvedType === 'user' ? (currentCallPartnerId || currentChatId) : currentChatId);
        return {
            targetType: resolvedType,
            targetId: Number(resolvedId || 0)
        };
    }

    function startCall(callType, targetType = null, targetId = null) {
        if (currentCallId && !terminalCallStatuses.has(currentCallStatus)) {
            showToast('info', 'توجد مكالمة نشطة بالفعل');
            return;
        }

        const target = resolveCallTarget(targetType, targetId);
        targetType = target.targetType;
        targetId = target.targetId;

        if (!targetId) {
            showToast('warning', 'اختر محادثة أولاً');
            return;
        }

        clearCallTimers();
        stopAllCallAudio();
        currentCallType = callType;
        currentCallTargetType = targetType;
        currentCallTargetId = targetId;

        // إرسال إلى قاعدة البيانات
        const formData = new FormData();
        formData.append('call_type', callType);
        formData.append('target_type', targetType);
        formData.append('target_id', targetId);

        fetch('internal_messages.php?action=start_call', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    currentCallId = data.call_id;
                    currentCallStatus = 'calling';
                    startCallStatusPolling();
                    armRingTimeout();
                    // Now call the original function (start WebRTC)
                    initiateWebRTCCall(callType, targetType, targetId);
                } else if (data.status === 'busy') {
                    showBusyCallNotice(data.message || 'المستخدم مشغول حاليا في مكالمة أخرى.', () => startCall(callType, targetType, targetId));
                } else if (data.status === 'error') {
                    showToast('error', data.message || 'حدث خطأ أثناء بدء المكالمة');
                }
            })
            .catch(error => {
                console.error('Start call error:', error);
                showToast('error', 'تعذر بدء المكالمة', error.message);
            });
    }

    async function sendSignalingMessage(type, data) {
        const target = getCurrentTargetPayload();
        if (!target) {
            console.warn('Cannot send WebRTC signal without an active chat target');
            return false;
        }

        const signalingMsg = JSON.stringify({
            type: 'webrtc_signal',
            signalType: type,
            data: data,
            callType: currentCallType,
            callId: currentCallId
        });

        const formData = new FormData();
        formData.append('message', signalingMsg);
        formData.append(target.key, target.value);

        try {
            const response = await fetch(`internal_messages.php?action=${target.action}`, {
                method: 'POST',
                body: formData
            });
            const raw = await response.text();
            const payload = raw ? JSON.parse(raw) : null;
            if (!payload || payload.status !== 'success') {
                throw new Error((payload && payload.message) || 'Empty signaling response');
            }
            return true;
        } catch (error) {
            console.error('Error sending WebRTC signal:', error);
            showToast('error', 'تعذر إرسال إشارة المكالمة', error.message);
            return false;
        }
    }

    function setupPeerEvents() {
        peer.on('signal', signal => {
            if (signal.type === 'offer') {
                sendSignalingMessage('offer', signal);
            } else if (signal.candidate) {
                sendSignalingMessage('candidate', signal);
            }
        });

        peer.on('stream', stream => {
            stopDialtone();
            clearCallRingTimeout();
            const remoteVideo = document.getElementById('remoteVideo');
            const remotePlaceholder = document.getElementById('remotePlaceholder');

            if (remoteVideo) {
                remoteVideo.srcObject = stream;
                remoteVideo.style.display = currentCallType === 'video' ? 'block' : 'none';
            }
            if (remotePlaceholder) {
                remotePlaceholder.style.display = currentCallType === 'video' ? 'none' : 'flex';
            }
            isCallActive = true;
            setCallStatusUI('متصل', false);
            if (!callDurationInterval) startCallDurationTimer();
            showToast('success', 'تم الاتصال بنجاح');
        });

        peer.on('connect', () => {
            stopDialtone();
            clearCallRingTimeout();
            isCallActive = true;
            setCallStatusUI('متصل', false);
            if (!callDurationInterval) startCallDurationTimer();
            showToast('success', 'تم الاتصال بنجاح');
        });

        peer.on('close', () => {
            finishCall('ended', true);
        });

        peer.on('error', err => {
            console.error('Peer error:', err);
            showToast('error', 'حدث خطأ في المكالمة', err.message);
            finishCall('ended', true);
        });
    }

    function toggleAudio() {
        if (!localStream) return;
        isAudioMuted = !isAudioMuted;
        const audioTracks = localStream.getAudioTracks();
        if (audioTracks.length > 0) {
            audioTracks[0].enabled = !isAudioMuted;
        }
        const btn = document.getElementById('toggleAudioBtn');
        if (btn) {
            btn.innerHTML = isAudioMuted ? '<i class="fas fa-microphone-slash"></i>' : '<i class="fas fa-microphone"></i>';
            btn.classList.toggle('off', isAudioMuted);
        }
        const audioLabel = document.getElementById('audioLabel');
        if (audioLabel) audioLabel.textContent = isAudioMuted ? 'مكتوم' : 'ميكروفون';
        showToast('info', isAudioMuted ? 'تم كتم الصوت' : 'تم تشغيل الصوت');
    }

    function toggleVideo() {
        if (!localStream || currentCallType !== 'video') return;
        isVideoMuted = !isVideoMuted;
        const videoTracks = localStream.getVideoTracks();
        if (videoTracks.length > 0) {
            videoTracks[0].enabled = !isVideoMuted;
        }
        const btn = document.getElementById('toggleVideoBtn');
        if (btn) {
            btn.innerHTML = isVideoMuted ? '<i class="fas fa-video-slash"></i>' : '<i class="fas fa-video"></i>';
            btn.classList.toggle('off', isVideoMuted);
        }
        const videoLabel = document.getElementById('videoLabel');
        if (videoLabel) videoLabel.textContent = isVideoMuted ? 'موقفة' : 'كاميرا';
        showToast('info', isVideoMuted ? 'تم إيقاف الفيديو' : 'تم تشغيل الفيديو');
    }

    let isSpeakerHigh = true;

    function toggleSpeaker() {
        const remoteVideo = document.getElementById('remoteVideo');
        if (remoteVideo) {
            isSpeakerHigh = !isSpeakerHigh;
            // تبديل مستوى الصوت بدلاً من الكتم
            remoteVideo.volume = isSpeakerHigh ? 1.0 : 0.3;
            remoteVideo.muted = false; // التأكد من أنه غير مكتوم

            const btn = document.getElementById('toggleSpeakerBtn');
            if (btn) {
                btn.innerHTML = isSpeakerHigh ? '<i class="fas fa-volume-up"></i>' : '<i class="fas fa-volume-down"></i>';
                btn.classList.toggle('off', !isSpeakerHigh);
            }
            const speakerLabel = document.getElementById('speakerLabel');
            if (speakerLabel) speakerLabel.textContent = isSpeakerHigh ? 'صوت عالٍ' : 'صوت منخفض';
            showToast('info', isSpeakerHigh ? 'مكبر الصوت: عالٍ' : 'مكبر الصوت: منخفض');
        }
    }

    let facingMode = 'user';

    function switchCamera() {
        if (!localStream) return;
        const oldVideoTrack = localStream.getVideoTracks()[0] || null;
        facingMode = (facingMode === 'user') ? 'environment' : 'user';

        const constraints = {
            video: {
                facingMode: facingMode
            },
            audio: false
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(stream => {
                const newVideoTrack = stream.getVideoTracks()[0];
                if (!newVideoTrack) throw new Error('No camera track available');

                if (oldVideoTrack) {
                    localStream.removeTrack(oldVideoTrack);
                    oldVideoTrack.stop();
                }

                localStream.addTrack(newVideoTrack);
                const localVideo = document.getElementById('localVideo');
                if (localVideo) localVideo.srcObject = localStream;

                if (peer && oldVideoTrack) {
                    peer.replaceTrack(oldVideoTrack, newVideoTrack, localStream);
                }

                showToast('info', 'تم تبديل الكاميرا');
                // إغلاق القائمة بعد التنفيذ
                const moreMenu = document.getElementById('moreControlsMenu');
                if (moreMenu) moreMenu.style.display = 'none';
            })
            .catch(err => {
                console.error('Switch camera error:', err);
                showToast('error', 'تعذر تبديل الكاميرا');
            });
    }

    let isScreenSharing = false;
    let originalVideoTrack = null;

    function shareScreen() {
        if (!localStream) return;

        // جلب الزر من القائمة أو الواجهة الأساسية (للأمان)
        const btn = document.getElementById('shareScreenBtnMore') || document.getElementById('shareScreenBtn');

        if (isScreenSharing) {
            // إيقاف مشاركة الشاشة
            if (originalVideoTrack) {
                const screenTrack = localStream.getVideoTracks()[0] || null;
                if (screenTrack) {
                    localStream.removeTrack(screenTrack);
                    screenTrack.stop();
                }
                localStream.addTrack(originalVideoTrack);
                const localVideo = document.getElementById('localVideo');
                if (localVideo) localVideo.srcObject = localStream;
                if (peer && screenTrack) {
                    peer.replaceTrack(screenTrack, originalVideoTrack, localStream);
                }
                if (btn) {
                    btn.classList.remove('active');
                    btn.innerHTML = '<i class="fas fa-desktop"></i> <span>مشاركة الشاشة</span>';
                }
                isScreenSharing = false;
                originalVideoTrack = null;
                showToast('info', 'تم إيقاف مشاركة الشاشة');
            }
        } else {
            // بدء مشاركة الشاشة
            navigator.mediaDevices.getDisplayMedia({
                    video: true,
                    audio: false
                })
                .then(stream => {
                    originalVideoTrack = localStream.getVideoTracks()[0];
                    const screenTrack = stream.getVideoTracks()[0];

                    const videoTracks = localStream.getVideoTracks();
                    videoTracks.forEach(track => localStream.removeTrack(track));

                    localStream.addTrack(screenTrack);
                    const localVideo = document.getElementById('localVideo');
                    if (localVideo) localVideo.srcObject = localStream;

                    if (peer) {
                        peer.replaceTrack(originalVideoTrack, screenTrack, localStream);
                    }

                    if (btn) {
                        btn.classList.add('active');
                        btn.innerHTML = '<i class="fas fa-stop-circle"></i> <span>إيقاف المشاركة</span>';
                    }

                    isScreenSharing = true;
                    showToast('info', 'تم مشاركة الشاشة');

                    // إغلاق القائمة بعد البدء
                    const moreMenu = document.getElementById('moreControlsMenu');
                    if (moreMenu) moreMenu.style.display = 'none';

                    screenTrack.onended = () => shareScreen();
                })
                .catch(err => {
                    console.error('Screen share error:', err);
                    showToast('error', 'تعذر مشاركة الشاشة');
                });
        }
    }

    function addParticipants() {
        showToast('info', 'إضافة مشاركين قادمة قريباً');
    }

    function toggleChat() {
        showToast('info', 'التحكم في المحادثة قادم قريباً');
    }

    let handRaised = false;

    function raiseHand() {
        handRaised = !handRaised;
        const btn = document.getElementById('raiseHandBtn');
        if (btn) btn.classList.toggle('hand-raised', handRaised);
        showToast('info', handRaised ? 'تم رفع اليد' : 'تم خفض اليد');
        addCallNotification(handRaised ? 'تم رفع اليد' : 'تم خفض اليد', 'info');
    }

    function toggleReactions() {
        const panel = document.getElementById('reactionsPanel');
        if (panel) {
            const isVisible = panel.style.display !== 'none';
            panel.style.display = isVisible ? 'none' : 'flex';
        }
    }

    function sendReaction(reaction) {
        showToast('success', `تم إرسال التفاعل: ${reaction}`);
        addCallNotification(reaction, 'reaction');
        toggleReactions();
    }

    function addCallNotification(text, type = 'info') {
        const container = document.getElementById('callNotifications');
        if (!container) return;
        const notification = document.createElement('div');
        notification.className = 'call-notification';
        notification.textContent = text;
        if (type === 'reaction') {
            notification.style.fontSize = '2rem';
            notification.style.justifyContent = 'center';
        }
        container.appendChild(notification);
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    function initiateWebRTCCall(type, targetType, targetId) {
        isCaller = true;
        isCallActive = true;
        currentCallType = type;

        const titleText = document.getElementById('chatTitle').textContent;
        setCallTypeUI(type, 'outgoing');
        setCallParticipantUI(titleText || 'جاري الاتصال...', currentCallPartnerImage);
        setCallStatusUI('جار الاتصال...', true);
        const participantsText = document.getElementById('callParticipantsText');
        if (participantsText) participantsText.textContent = targetType === 'group' ? 'مجموعة' : '1';

        callModal.show();
        // — مكالمة صادرة: إظهار أزرار التحكم (إنهاء + مكبر + ميكروفون)
        setCallControlsState('outgoing', type);

        startDialtone();

        const constraints = {
            audio: true,
            video: type === 'video'
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(stream => {
                localStream = stream;
                const localVideo = document.getElementById('localVideo');
                if (localVideo) {
                    localVideo.srcObject = stream;
                    localVideo.style.display = 'block';
                    const localVideoWrapper = document.querySelector('.local-video-wrapper');
                    if (localVideoWrapper) {
                        localVideoWrapper.style.display = type === 'video' ? 'block' : 'none';
                    }
                }

                peer = new SimplePeer({
                    initiator: true,
                    trickle: true,
                    stream: stream,
                    config: {
                        iceServers: [{
                                urls: 'stun:stun.l.google.com:19302'
                            },
                            {
                                urls: 'stun:global.stun.twilio.com:3478'
                            }
                        ]
                    }
                });

                setupPeerEvents();
                // تمت إزالة showToast('info') هنا لتجنب إزعاج المستخدم، حيث تظهر الحالة بالفعل في واجهة المكالمة
            })
            .catch(err => {
                console.error('Call error:', err);
                isCallActive = false;
                const errorMsg = explainMediaError(err, 'الكاميرا أو الميكروفون');
                showToast('error', 'تعذر بدء المكالمة', errorMsg);
                finishCall('cancelled', true);
            });
    }

    // تحديث handleIncomingCall لتسجيل حالة مكالمة
    async function handleIncomingCall(signalMsg, senderId, senderName) {
        const activeCall = await getActiveCall(signalMsg.callId);
        if (!activeCall) {
            if (signalMsg.callId) processedSignalMsgIds.add(`inactive-call-${signalMsg.callId}`);
            stopAllCallAudio();
            clearCallTimers();
            return;
        }

        if (isCallActive) {
            const activeCallId = currentCallId;
            currentCallId = signalMsg.callId || currentCallId;
            currentCallType = signalMsg.callType || currentCallType;
            updateCallStatus('busy').catch(error => console.error('Busy status update error:', error));
            sendSignalingMessage('busy', {
                status: 'busy'
            });
            currentCallId = activeCallId;
            showToast('info', 'يوجد مكالمة نشطة بالفعل');
            return;
        }

        isCaller = false;
        pendingSignal = signalMsg;
        currentCallId = signalMsg.callId || null;
        currentCallStatus = 'ringing';
        setCallTypeUI(currentCallType || activeCall.call_type || 'audio', 'incoming');
        setCallParticipantUI(senderName || 'اتصال وارد');
        setCallStatusUI('اتصال وارد', true);
        startCallStatusPolling();
        updateCallStatus('ringing').catch(error => console.error('Ringing status update error:', error));
        armRingTimeout();

        startRingtone();

        // — مكالمة واردة: إظهار زر الرد والرفض فقط
        setCallControlsState('incoming', activeCall.call_type || currentCallType || 'audio');
        callModal.show();
    }

    // تحديث زر الرد
    function acceptCall() {
        stopRingtone();
        if (!pendingSignal || !pendingSignal.data) {
            showToast('error', 'تعذر الرد على المكالمة', 'Missing call signal');
            finishCall('ended', true);
            return;
        }

        const type = pendingSignal.callType || 'video';
        currentCallType = type;
        currentCallId = pendingSignal.callId || currentCallId;

        // — بعد الرد: إخفاء أزرار الواردة وإظهار أزرار التحكم
        setCallControlsState('active', type);

        setCallStatusUI('جار الاتصال...', false);

        isCallActive = true;
        currentCallType = type;
        currentCallId = pendingSignal.callId || currentCallId;

        const constraints = {
            audio: true,
            video: type === 'video'
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(stream => {
                clearCallRingTimeout();
                currentCallStatus = 'accepted';
                setCallStatusUI('متصل', false);
                startCallDurationTimer();
                updateCallStatus('accepted').catch(error => console.error('Accepted status update error:', error));
                sendSignalingMessage('accepted', {
                    status: 'accepted'
                });
                localStream = stream;
                const localVideo = document.getElementById('localVideo');
                if (localVideo) {
                    localVideo.srcObject = stream;
                    localVideo.style.display = 'block';
                    const localVideoWrapper = document.querySelector('.local-video-wrapper');
                    if (localVideoWrapper) localVideoWrapper.style.display = type === 'video' ? 'block' : 'none';
                }

                peer = new SimplePeer({
                    initiator: false,
                    trickle: true,
                    stream: stream,
                    config: {
                        iceServers: [{
                                urls: 'stun:stun.l.google.com:19302'
                            },
                            {
                                urls: 'stun:global.stun.twilio.com:3478'
                            }
                        ]
                    }
                });

                setupPeerEvents();
                peer.signal(pendingSignal.data);

                peer.on('signal', signal => {
                    if (signal.type === 'answer') {
                        sendSignalingMessage('answer', signal);
                    } else if (signal.candidate) {
                        sendSignalingMessage('candidate', signal);
                    }
                });
            })
            .catch(err => {
                console.error('Incoming call error:', err);
                isCallActive = false;
                showToast('error', 'تعذر الرد على المكالمة');
                finishCall('cancelled', true);
            });
    }

    // تحديث زر الرفض
    function rejectCall() {
        // إرسال إشارة رفض
        currentCallId = (pendingSignal && pendingSignal.callId) || currentCallId;
        finishCall('rejected', true);
    }

    // تحديث endCall لتحديث الحالة في قاعدة البيانات
    /**
     * إنهاء المكالمة الحالية
     * هذه الدالة تستدعي finishCall التي تعالج كل التفاصيل
     */
    function endCall() {
        if (callCleanupInProgress) return;
        finishCall('ended', true);
    }

    // Header call buttons are bound in DOMContentLoaded

    // تحميل المكالمات الفائتة كل 10 ثوانٍ
    missedCallsInterval = setInterval(loadMissedCalls, 10000);

    window.addEventListener('beforeunload', () => {
        if (!currentCallId || terminalCallStatuses.has(currentCallStatus)) return;
        const status = currentCallStatus === 'accepted' ? 'ended' : (isCaller ? 'cancelled' : 'missed');
        const formData = new FormData();
        formData.append('call_id', currentCallId);
        formData.append('status', status);
        navigator.sendBeacon('internal_messages.php?action=update_call_status', formData);
    });

    // تحميل المجموعات عند التحميل الأولي
    window.addEventListener('DOMContentLoaded', () => {
        clearCallTimers();
        stopAllCallAudio();
        currentCallId = null;
        currentCallStatus = null;
        pendingSignal = null;
        pendingIceCandidates = [];
        setTimeout(loadGroups, 100);
        setTimeout(loadMissedCalls, 200);
    });
</script>

<!-- Call Modals and UI -->
<div class="modal fade" id="callModal" tabindex="-1" aria-labelledby="callModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content call-modal-content">
            <div class="call-background"></div>
            <!-- Incoming Call UI -->
            <div id="incomingCallUI" class="call-ui incoming-call-ui d-none">
                <div class="incoming-call-header">
                    <img id="incomingCallerAvatar" src="../assets/uploads/default.png" alt="Caller Avatar" class="incoming-caller-avatar">
                    <h2 id="incomingCallerName" class="incoming-caller-name"></h2>
                    <p class="incoming-call-status">مكالمة واردة...</p>
                </div>
                <div class="incoming-call-actions">
                    <button id="rejectCallBtn" class="call-action-btn reject-btn">
                        <i class="fas fa-phone-slash"></i>
                        <span>رفض</span>
                    </button>
                    <button id="acceptCallBtn" class="call-action-btn accept-btn">
                        <i class="fas fa-phone"></i>
                        <span>قبول</span>
                    </button>
                </div>
            </div>

            <!-- Active Call UI -->
            <div id="activeCallUI" class="call-ui active-call-ui d-none">
                <div class="remote-video-wrapper">
                    <video id="remoteVideo" autoplay playsinline class="remote-video d-none"></video>
                    <div id="remotePlaceholder" class="remote-placeholder">
                        <i class="fas fa-user-circle"></i>
                        <span id="remoteUserName"></span>
                        <span id="callStatusText" class="call-status-text">جاري الاتصال...</span>
                        <span id="callDuration" class="call-duration d-none">00:00</span>
                    </div>
                </div>
                <div class="local-video-wrapper">
                    <video id="localVideo" muted autoplay playsinline class="local-video d-none"></video>
                    <canvas id="localVideoCanvas" class="local-video-canvas d-none"></canvas>
                    <div id="localVideoOverlay" class="local-video-overlay d-none">
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>

                <div class="call-controls-bar">
                    <div class="call-controls">
                        <button id="toggleAudioBtn" class="call-control-btn active" title="كتم الصوت">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button id="toggleVideoBtn" class="call-control-btn active" title="إيقاف الفيديو">
                            <i class="fas fa-video"></i>
                        </button>
                        <button id="endCallBtn" class="call-control-btn end-call-btn" title="إنهاء المكالمة">
                            <i class="fas fa-phone-slash"></i>
                        </button>
                        <button id="moreControlsBtn" class="call-control-btn" title="المزيد">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>

                <!-- More Controls Menu -->
                <div id="moreControlsMenu" class="more-controls-menu d-none">
                    <button id="switchCameraBtn" class="more-control-item" title="تبديل الكاميرا">
                        <i class="fas fa-sync-alt"></i>
                        <span>تبديل الكاميرا</span>
                    </button>
                    <button id="shareScreenBtn" class="more-control-item" title="مشاركة الشاشة">
                        <i class="fas fa-desktop"></i>
                        <span>مشاركة الشاشة</span>
                    </button>
                    <button id="raiseHandBtn" class="more-control-item" title="رفع اليد">
                        <i class="fas fa-hand-paper"></i>
                        <span>رفع اليد</span>
                    </button>
                    <button id="toggleSpeakerBtn" class="more-control-item" title="تبديل السماعة">
                        <i class="fas fa-volume-up"></i>
                        <span>تبديل السماعة</span>
                    </button>
                    <button id="toggleReactionsBtn" class="more-control-item" title="التفاعلات">
                        <i class="fas fa-smile"></i>
                        <span>التفاعلات</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Audio elements for ringtones and dialtones -->
<audio id="ringtoneAudio" src="../assets/audio/ringtone.mp3" loop></audio>
<audio id="dialtoneAudio" src="../assets/audio/dialtone.mp3" loop></audio>
<audio id="notificationAudio" src="../assets/audio/notification.mp3"></audio>

<?php require_once 'footer.php'; ?>
