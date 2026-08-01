<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

$expense_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($expense_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($expense) {
        echo json_encode($expense);
    } else {
        echo json_encode(null);
    }
} else {
    echo json_encode(null);
}
?>
