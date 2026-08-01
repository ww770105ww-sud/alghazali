<?php
require_once 'includes/db.php';
echo "<h1>Checking & Fixing InnoDB Tables</h1>";
echo "<h2>Step 1: List all tables and check status</h2>";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "<h3>Table: $table</h3>";
    try {
        $check = $pdo->query("CHECK TABLE `$table`")->fetchAll();
        echo "<pre>";
        print_r($check);
        echo "</pre>";

        // Try to repair if needed
        if (in_array('error', array_column($check, 'Msg_type')) || in_array('warning', array_column($check, 'Msg_type'))) {
            echo "<h4>Attempting repair...</h4>";
            $repair = $pdo->query("REPAIR TABLE `$table`")->fetchAll();
            echo "<pre>";
            print_r($repair);
            echo "</pre>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<h2>Step 2: Check InnoDB engine status</h2>";
try {
    $innodb = $pdo->query("SHOW ENGINE INNODB STATUS")->fetch(PDO::FETCH_ASSOC);
    echo "<textarea style='width:100%; height:500px'>" . htmlspecialchars($innodb['Status']) . "</textarea>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error getting InnoDB status: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Step 3: Create a backup of users table data (if possible)</h2>";
try {
    $users = $pdo->query("SELECT * FROM users")->fetchAll();
    echo "<p style='color:green'>✅ Successfully retrieved " . count($users) . " users! Saving backup...</p>";
    $backup_file = 'backup_users_' . date('YmdHis') . '.json';
    file_put_contents($backup_file, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "<p style='color:blue'>Backup saved to: <a href='$backup_file'>$backup_file</a></p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Could not retrieve users data: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
