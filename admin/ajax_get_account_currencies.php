<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

$account_id = (int)($_GET['account_id'] ?? 0);

if ($account_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid account ID']);
    exit();
}

try {
    // Return all active currencies (since account-specific currencies aren't implemented)
    $stmt = $pdo->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY currency_name");
    $currencies = $stmt->fetchAll();
    
    echo json_encode(['status' => 'success', 'currencies' => $currencies]);
} catch (Exception $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'حدث خطأ داخلي في النظام']);
}
?>
