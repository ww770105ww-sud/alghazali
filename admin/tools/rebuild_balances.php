<?php
require_once '../../includes/db.php';

try {
    echo "<h2>Start rebuilding balances...</h2>";
    
    // Call stored procedure
    $stmt = $pdo->query("CALL sp_rebuild_balances()");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h3>" . htmlspecialchars($result['message']) . "</h3>";
    echo "<p>Total account balances created/updated: " . $result['total_balances'] . "</p>";
    echo "<p><a href='../index.php'>Back to admin dashboard</a></p>";

} catch (Exception $e) {
    echo "<h3>Error rebuilding balances: " . $e->getMessage() . "</h3>";
}
?>