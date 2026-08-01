<?php
require_once 'includes/db.php';

echo "<h1>Database Structure Analysis</h1>";

// List all tables
echo "<h2>Tables</h2>";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "<ul>";
foreach ($tables as $table) {
    echo "<li><a href='#$table'>$table</a></li>";
}
echo "</ul>";

// Show table details
foreach ($tables as $table) {
    echo "<h3 id='$table'>Table: $table</h3>";
    echo "<pre>";
    $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
    print_r($pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";
}

// List stored procedures
echo "<h2>Stored Procedures</h2>";
$procedures = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
echo "<ul>";
foreach ($procedures as $proc) {
    echo "<li>{$proc['Name']}</li>";
}
echo "</ul>";

// List stored functions
echo "<h2>Stored Functions</h2>";
$functions = $pdo->query("SHOW FUNCTION STATUS WHERE Db = DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
echo "<ul>";
foreach ($functions as $func) {
    echo "<li>{$func['Name']}</li>";
}
echo "</ul>";
?>
