<?php
require_once '../includes/config.php';

echo "<h2>Rebuilding Balances...</h2>";
try {
    $pdo->exec("CALL sp_rebuild_balances()");
    echo "<div class='alert alert-success'>✅ Balances rebuilt successfully!</div>";
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>❌ Error: " . $e->getMessage() . "</div>";
}
?>