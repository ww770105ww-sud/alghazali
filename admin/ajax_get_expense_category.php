<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($category_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM expenses_categories WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($category) {
        echo json_encode($category);
    } else {
        echo json_encode(null);
    }
} else {
    echo json_encode(null);
}
?>
