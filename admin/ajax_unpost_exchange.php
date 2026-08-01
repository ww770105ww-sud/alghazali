<?php
require_once '../includes/db.php';
require_once '../includes/security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_unpost_exchange', 20, 60);
require_csrf();

$id = $_POST['id'] ?? 0;

try {
    $pdo->beginTransaction();

    // 1. جلب بيانات عملية الصرف
    $stmt = $pdo->prepare("SELECT transaction_number FROM currency_exchange_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $cet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cet) throw new Exception("عملية الصرف غير موجودة.");

    // 2. جلب المعاملة المالية المرتبطة
    $stmt_ft = $pdo->prepare("SELECT id, status FROM financial_transactions WHERE transaction_number = ?");
    $stmt_ft->execute([$cet['transaction_number']]);
    $ft = $stmt_ft->fetch(PDO::FETCH_ASSOC);
    if (!$ft) throw new Exception("المعاملة المالية غير موجودة.");

    if ($ft['status'] !== 'posted') throw new Exception("المعاملة ليست مُرحلة.");

    // 3. عكس الأرصدة عند الحاجة فقط، ثم حذف القيود ليقوم الـ trigger بالعكس تلقائيا.
    if (!balances_triggers_enabled($pdo)) {
        apply_transaction_balances($pdo, (int)$ft['id'], -1);
    }
    $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ft['id']]);

    // 4. تحديث حالة المعاملة إلى draft
    $pdo->prepare("UPDATE financial_transactions SET status = 'draft' WHERE id = ?")->execute([$ft['id']]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_unpost_exchange.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
}
?>
