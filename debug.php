<?php
require_once 'includes/db.php';

echo "<h1>Database Debug Info</h1>";

// List all tables
echo "<h2>Tables in Database:</h2>";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "<ul>";
foreach ($tables as $table) {
    echo "<li><strong>$table</strong></li>";
}
echo "</ul>";

// Check users table
if (in_array('users', $tables)) {
    echo "<h2>Users Table Columns:</h2>";
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($cols as $col) {
        echo "<li>{$col['Field']} ({$col['Type']})</li>";
    }
    echo "</ul>";
    
    echo "<h2>Users Table Data:</h2>";
    $users = $pdo->query("SELECT * FROM users LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($users);
    echo "</pre>";
}

// Check employees table
if (in_array('employees', $tables)) {
    echo "<h2>Employees Table Columns:</h2>";
    $cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($cols as $col) {
        echo "<li>{$col['Field']} ({$col['Type']})</li>";
    }
    echo "</ul>";
    
    echo "<h2>Employees Table Data:</h2>";
    $employees = $pdo->query("SELECT * FROM employees LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($employees);
    echo "</pre>";
}

// Check attendance_locations table
if (in_array('attendance_locations', $tables)) {
    echo "<h2>Attendance Locations Table Columns:</h2>";
    $cols = $pdo->query("SHOW COLUMNS FROM attendance_locations")->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($cols as $col) {
        echo "<li>{$col['Field']} ({$col['Type']})</li>";
    }
    echo "</ul>";
    
    echo "<h2>Attendance Locations Data:</h2>";
    $locations = $pdo->query("SELECT * FROM attendance_locations")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($locations);
    echo "</pre>";
}
?>