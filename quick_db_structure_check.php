
<?php
require_once 'includes/db.php';

echo "<h1>Database Structure Check</h1>";

// Get all tables
echo "<h2>Tables</h2><ul>";
$stmt = $pdo->query("SHOW TABLES");
while($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $table = $row[0];
    echo "<li><a href='#table_$table'>$table</a></li>";
}
echo "</ul>";

// Stored procedures
echo "<h2>Stored Procedures</h2><ul>";
$stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()");
if($stmt->rowCount() > 0) {
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<li>" . htmlspecialchars($row['Name']) . " - " . htmlspecialchars($row['Comment']) . "</li>";
    }
} else { echo "<li>No stored procedures</li>"; }
echo "</ul>";

// Stored functions
echo "<h2>Stored Functions</h2><ul>";
$stmt = $pdo->query("SHOW FUNCTION STATUS WHERE Db = DATABASE()");
if($stmt->rowCount() >0) {
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<li>" . htmlspecialchars($row['Name']) . " - " . htmlspecialchars($row['Comment']) . "</li>";
    }
} else { echo "<li>No stored functions</li>"; }
echo "</ul>";

// Triggers
echo "<h2>Triggers</h2><ul>";
$stmt = $pdo->query("SHOW TRIGGERS");
if($stmt->rowCount() >0) {
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<li>" . htmlspecialchars($row['Trigger']) . " on " . htmlspecialchars($row['Table']) . " (" . htmlspecialchars($row['Timing']) . " " . htmlspecialchars($row['Event']) . ")</li>";
    }
} else { echo "<li>No triggers</li>"; }
echo "</ul>";

// Views
echo "<h2>Views</h2><ul>";
$stmt = $pdo->query("SHOW FULL TABLES WHERE TABLE_TYPE LIKE 'VIEW'");
if($stmt->rowCount() >0) {
    while($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo "<li>" . htmlspecialchars($row[0]) . "</li>";
    }
} else { echo "<li>No views</li>"; }
echo "</ul>";

echo "<hr>";
// For each table, get CREATE TABLE
echo "<h2>Table Details</h2>";
$stmt = $pdo->query("SHOW TABLES");
while($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $table = $row[0];
    echo "<h3 id='table_$table'>Table: " . htmlspecialchars($table) . "</h3>";
    // Show create table
    $showStmt = $pdo->query("SHOW CREATE TABLE `$table`");
    $createRow = $showStmt->fetch(PDO::FETCH_NUM);
    echo "<pre style='background: #f5f5f5; padding: 1rem; border-radius: 0.5rem; overflow: auto; max-width: 100%;'>" . htmlspecialchars($createRow[1]) . "</pre>";
}

echo "<hr><p>Done.</p>";
?>
