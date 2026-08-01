<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Start transaction
$pdo->beginTransaction();

try {
    // Get all passport transactions
    $stmt = $pdo->query("SELECT id, sales_invoice_id, purchase_invoice_id FROM passport_transactions");
    $transactions = $stmt->fetchAll();

    foreach ($transactions as $t) {
        // Delete related invoices
        if ($t['sales_invoice_id']) {
            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$t['sales_invoice_id']]);
        }
        if ($t['purchase_invoice_id']) {
            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$t['purchase_invoice_id']]);
        }

        // Delete the transaction
        $pdo->prepare("DELETE FROM passport_transactions WHERE id = ?")->execute([$t['id']]);
    }

    // Commit
    $pdo->commit();

    echo '<div style="padding: 20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; text-align: center; font-size: 18px; margin: 50px;">';
    echo '✅ تم حذف جميع المعاملات القديمة بنجاح!';
    echo '<br><br><a href="passport_transactions.php" style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">العودة لصفحة المعاملات</a>';
    echo '</div>';

} catch (Exception $e) {
    $pdo->rollBack();
    echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; text-align: center; font-size: 18px; margin: 50px;">';
    echo '❌ حدث خطأ أثناء الحذف: ' . htmlspecialchars($e->getMessage());
    echo '<br><br><a href="passport_transactions.php" style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">العودة لصفحة المعاملات</a>';
    echo '</div>';
}
?>