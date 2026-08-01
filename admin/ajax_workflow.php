<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول إلى هذه الصفحة']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
rate_limit('ajax_workflow:' . $action, 30, 60);
require_csrf_for_actions(['process_transition']);

if ($action === 'process_transition') {
    $passport_id = $_POST['passport_id'] ?? null;
    $to_step_id = $_POST['to_step_id'] ?? null;
    $notes = $_POST['notes'] ?? '';
    $user_id = $_SESSION['admin_id'];

    if (!$passport_id || !$to_step_id) {
        echo json_encode(['success' => false, 'message' => 'البيانات غير مكتملة']);
        exit();
    }

    // التحقق من صلاحيات المستخدم للانتقال
    // في الإصدارات القادمة سنضيف التحقق باستخدام get_allowed_transitions

    if (change_transaction_status($passport_id, $to_step_id, $user_id, $notes)) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث حالة المعاملة بنجاح']);
    } else {
        echo json_encode(['success' => false, 'message' => 'فشل تحديث حالة المعاملة. يرجى التحقق من صلاحياتك والبيانات المدخلة.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'إجراء غير صحيح']);
}
?>