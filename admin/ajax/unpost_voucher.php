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

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit;
}

if (!has_permission('vouchers_unpost')) {
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإلغاء ترحيل السندات']);
    exit;
}

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'];
$user_ip = $_SERVER['REMOTE_ADDR'];

try {
    $pdo->beginTransaction();

    // 1. جلب السند
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) throw new Exception("السند غير موجود.");

    if ($voucher['status'] !== 'posted') {
        throw new Exception("السند ليس في حالة ترحيل.");
    }

    if (!balances_triggers_enabled($pdo)) {
        apply_transaction_balances($pdo, (int)$id, -1);
    }

    // 2. حذف سطور القيد فقط، والـ triggers ستعكس الأرصدة تلقائياً.
    $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$id]);

    // 3. تحديث حالة السند إلى مسودة
    $stmt_reset = $pdo->prepare("UPDATE financial_transactions SET status = 'draft', posted_at = NULL, posted_by = NULL WHERE id = ?");
    $stmt_reset->execute([$id]);

    // 4. إعادة حساب مبالغ الفواتير المرتبطة (لأن التوزيعات ما زالت موجودة ولكن السند لم يعد مرحلاً)
    $stmt_allocs = $pdo->prepare("SELECT DISTINCT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
    $stmt_allocs->execute([$id]);
    $invoice_ids = $stmt_allocs->fetchAll(PDO::FETCH_COLUMN);

    foreach ($invoice_ids as $inv_id) {
        php_recalculate_invoice_payment($pdo, $inv_id);
    }

    // 5. تسجيل في audit_log
    $stmt_after = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt_after->execute([$id]);
    $voucher_after = $stmt_after->fetch(PDO::FETCH_ASSOC);
    log_audit($pdo, 'unpost', 'financial_transactions', $id, $voucher, $voucher_after, "إلغاء ترحيل سند " . ($voucher['transaction_type'] == 'receipt' ? 'قبض' : 'صرف'));

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
