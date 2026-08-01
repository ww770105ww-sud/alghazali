<?php
require_once '../includes/db.php';

$entity_id = $_GET['entity_id'] ?? 0;
$type = $_GET['type'] ?? 'sales'; // sales أو purchase

try {
    if ($type == 'sales') {
        $stmt = $pdo->prepare("
            SELECT i.*, c.currency_code, c.currency_symbol, 
                   (i.net_amount - i.amount_received) as remaining 
            FROM invoices i 
            JOIN currencies c ON i.currency_id = c.id 
            WHERE i.customer_id = ? 
              AND i.payment_status IN ('unpaid', 'partial') 
              AND i.invoice_status = 'posted' 
              AND i.invoice_category = 'sales' 
            ORDER BY i.invoice_date ASC 
        ");
        $stmt->execute([$entity_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT i.*, c.currency_code, c.currency_symbol, 
                   (i.net_amount - i.amount_received) as remaining 
            FROM invoices i 
            JOIN currencies c ON i.currency_id = c.id 
            WHERE i.supplier_id = ? 
              AND i.payment_status IN ('unpaid', 'partial') 
              AND i.invoice_status = 'posted' 
              AND i.invoice_category = 'purchase' 
            ORDER BY i.invoice_date ASC 
        ");
        $stmt->execute([$entity_id]);
    }

    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($invoices);
} catch (Exception $e) {
    header('Content-Type: application/json');
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    echo json_encode(['error' => 'حدث خطأ داخلي في النظام']);
}

