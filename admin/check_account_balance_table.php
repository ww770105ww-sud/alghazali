<?php
require_once '../includes/db.php';
echo "<style>body{font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; direction: rtl; background:#f5f5f7} pre{background:white; padding:15px; border-radius:8px}</style>";
echo "<h1>📋 بنية جدول account_balances_unified</h1>";

try {
    $stmt = $pdo->query("DESCRIBE account_balances_unified");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table style='border-collapse: collapse; width:100%; margin:20px 0;'>";
    echo "<tr style='background:#007aff; color:white;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach($columns as $col) {
        echo "<tr style='background:white; border-bottom:1px solid #ddd;'><td>" . $col['Field'] . "</td><td>" . $col['Type'] . "</td><td>" . $col['Null'] . "</td><td>" . $col['Key'] . "</td><td>" . $col['Default'] . "</td></tr>";
    }
    echo "</table>";
} catch(Exception $e) {
    echo "<pre style='color:red;'>Error: " . $e->getMessage() . "</pre>";
}
