<?php
require_once 'includes/db.php';
$stmt = $pdo->query("SHOW CREATE TABLE account_balances_unified");
echo "<pre>" . htmlspecialchars($stmt->fetch(PDO::FETCH_ASSOC)['Create Table']) . "</pre>";
?>