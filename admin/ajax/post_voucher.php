<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/functions.php';
require_once '../../includes/accounting_functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

function has_permission_v3_ajax($permission_code)
{
    global $pdo;
    $user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
    $user_role_id = (int)($_SESSION['role_id'] ?? 0);
    if ($user_role === 'developer' || $user_role_id === 2) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions_unified rp JOIN unified_permissions p ON rp.permission_id = p.id WHERE rp.role_id = ? AND p.permission_code = ?");
    $stmt->execute([$user_role_id, $permission_code]);
    return $stmt->fetchColumn() > 0;
}

$id      = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'];

if (!has_permission_v3_ajax('voucher_post')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لترحيل السندات.']);
    exit;
}

try {
    // 1. جلب السند
    $stmt = $pdo->prepare("
        SELECT ft.*,
               (SELECT COUNT(*) FROM journal_lines WHERE financial_transaction_id = ft.id) AS lines_count
        FROM financial_transactions ft
        WHERE ft.id = ? AND ft.status IN ('draft', 'cancelled')
    ");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) throw new Exception("السند غير موجود أو مرحل مسبقاً.");

    // 2. ترحيل السند باستخدام دوال PHP
    if ($voucher['transaction_type'] == 'receipt') {
        php_post_receipt_voucher($pdo, $id, $user_id);
    } else {
        php_post_payment_voucher($pdo, $id, $user_id);
    }

    // 3. تسجيل في audit_log
    $voucher_after = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $voucher_after->execute([$id]);
    $voucher_after = $voucher_after->fetch(PDO::FETCH_ASSOC);
    log_audit($pdo, 'post', 'financial_transactions', $id, $voucher, $voucher_after,
        "ترحيل سند " . ($voucher['transaction_type'] == 'receipt' ? 'قبض' : 'صرف'));

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
