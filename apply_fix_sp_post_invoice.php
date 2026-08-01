<?php
require_once 'includes/db.php';
$sql = file_get_contents('fix_sp_post_invoice_postal.sql');
try {
    $pdo->exec($sql);
    echo "Successfully applied fix!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>