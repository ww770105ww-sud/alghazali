<?php
/**
 * تغيير حالة الكيانات (تفعيل/تعطيل) بشكل سريع
 */
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed");
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die("Invalid CSRF token");
}

$entity = $_POST['entity'] ?? '';
$id = intval($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

$allowed_entities = ['users', 'branches', 'agents', 'employees', 'customers', 'suppliers'];
$allowed_statuses = ['active', 'inactive', 'closed'];
$entity_permissions = [
    'users' => 'users_edit',
    'employees' => 'employees_edit',
    'branches' => 'manage_financial_accounts',
    'agents' => 'manage_financial_accounts',
    'customers' => 'manage_financial_accounts',
    'suppliers' => 'manage_financial_accounts',
];

if (in_array($entity, $allowed_entities) && $id > 0 && in_array($status, $allowed_statuses)) {
    $required_permission = $entity_permissions[$entity] ?? null;
    if ($required_permission && !has_permission($required_permission)) {
        http_response_code(403);
        die("Forbidden");
    }

    try {
        $stmt = $pdo->prepare("UPDATE $entity SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        // تسجيل في سجل العمليات
        log_audit($pdo, 'update_status', $entity, $id, null, ['status' => $status], "تغيير الحالة إلى $status عبر التبديل السريع");
        
        // العودة للصفحة السابقة
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit();
    } catch (PDOException $e) {
        die("Error updating status: " . $e->getMessage());
    }
} else {
    die("Invalid request parameters");
}
