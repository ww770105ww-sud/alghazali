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

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'];

try {
    $pdo->beginTransaction();

    // 1. جلب السند للتحقق
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) throw new Exception("السند غير موجود.");

    // ======================================================
    // حماية كاملة من الحذف للسندات المرتبطة بالعكس
    // ======================================================
    if ($voucher['is_reversed'] || $voucher['reversal_voucher_id']) {
        log_audit($pdo, 'delete_attempt', 'financial_transactions', $id, $voucher, null, "محاولة حذف فاشلة: السند لديه سند عكسي مرتبط به.");
        throw new Exception("لا يمكن حذف هذا السند لأنه يوجد له سند عكسي مرتبط به.");
    }

    if ($voucher['original_voucher_id']) {
        log_audit($pdo, 'delete_attempt', 'financial_transactions', $id, $voucher, null, "محاولة حذف فاشلة: السند هو سند عكسي مرتبط بسند آخر.");
        throw new Exception("لا يمكن حذف هذا السند لأنه سند عكسي مرتبط بسند آخر.");
    }

    // ======================================================
    // التحقق من الصلاحيات: تفصيلي حسب نوع السند (أصلي/عكسي × قبض/صرف)
    // ======================================================
    $user_role_id = (int)($_SESSION['role_id'] ?? 0);
    $user_role    = strtolower($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');

    // السماح المطلق للمطور (role_id=2 أو الاسم developer)
    $is_super = ($user_role === 'developer' || $user_role_id === 2 || $user_role === 'admin');

    if (!$is_super) {
        $is_reversal = !empty($voucher['original_voucher_id']) || ($voucher['reference_type'] ?? '') === 'reversal';
        $ttype       = strtolower($voucher['transaction_type'] ?? '');

        $perm_needed = null;
        if (!$is_reversal) {
            if ($ttype === 'receipt') $perm_needed = 'receipt_delete_original';
            elseif ($ttype === 'payment') $perm_needed = 'payment_delete_original';
        } else {
            if ($ttype === 'receipt') $perm_needed = 'receipt_delete_reversal';
            elseif ($ttype === 'payment') $perm_needed = 'payment_delete_reversal';
        }

        $allowed = false;
        // الصلاحية العامة القديمة للتوافق الخلفي
        if (has_permission_v3($pdo, $user_role_id, 'voucher_delete')) $allowed = true;
        // الصلاحية التفصيلية
        if ($perm_needed && has_permission_v3($pdo, $user_role_id, $perm_needed)) $allowed = true;

        if (!$allowed) {
            log_audit($pdo, 'delete_denied', 'financial_transactions', $id, $voucher, null, "محاولة حذف مرفوضة: المستخدم ليس لديه صلاحية {$perm_needed}");
            throw new Exception("ليس لديك صلاحية لحذف هذا السند ({$perm_needed}).");
        }
    }

    // 2. تنفيذ الحذف
    if ($voucher['status'] == 'posted') {
        // إذا كان مرحلاً، يجب عكس الأرصدة أولاً
        if (function_exists('apply_transaction_balances')) {
            apply_transaction_balances($pdo, (int)$id, -1);
        }
    }

    // حذف أسطر القيد والتوزيعات
    $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$id]);

    // تسجيل العملية
    log_audit($pdo, 'delete', 'financial_transactions', $id, $voucher, null, "حذف نهائي للسند رقم: " . $voucher['transaction_number']);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * دالة التحقق من الصلاحية - تعتمد على البحث في role_permissions_unified
 * @param PDO    $pdo          اتصال قاعدة البيانات
 * @param int    $role_id      رقم تعريف الدور
 * @param string $code         كود الصلاحية
 */
function has_permission_v3($pdo, $role_id, $code) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
              FROM role_permissions_unified rp
              JOIN unified_permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ? AND p.permission_code = ?
        ");
        $stmt->execute([(int)$role_id, $code]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $t) {
        error_log("has_permission_v3 DB error: " . $t->getMessage());
        return false;
    }
}
