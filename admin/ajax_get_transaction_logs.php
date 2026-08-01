<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([]);
    exit;
}

$id = (int)$_GET['id'];

try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.username 
        FROM financial_transaction_logs l
        JOIN users u ON l.changed_by = u.id
        WHERE l.transaction_id = ?
        ORDER BY l.changed_at DESC
    ");
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($logs);
} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    echo json_encode(['error' => 'حدث خطأ داخلي في النظام']);
}

