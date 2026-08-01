<?php
require_once 'includes/db.php';
try {
    $pdo->exec("CALL sp_rebuild_balances()");
    echo "Balances rebuilt successfully!<br>";
    // Show total balances count
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM account_balances_unified");
    $result = $stmt->fetch();
    echo "Total account balances: " . $result['total'];
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>