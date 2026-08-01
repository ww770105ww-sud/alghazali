<?php
require_once 'includes/db.php';

echo "<h1>System Settings Check</h1>";
echo "<h2>All Settings:</h2>";
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY setting_key");
echo "<table border='1' cellpadding='5'><tr><th>Key</th><th>Value</th></tr>";
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr><td>".htmlspecialchars($row['setting_key'])."</td><td>".htmlspecialchars($row['setting_value'])."</td></tr>";
}
echo "</table>";
?>