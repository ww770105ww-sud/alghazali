<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db = 'alghazali';

// Path to backup file
$backupFile = __DIR__ . '/tools/database/ghazali (15).sql';

if (!file_exists($backupFile)) {
    die("Backup file not found: $backupFile\n");
}

// First, drop and recreate the database
try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database dropped and recreated successfully.\n";
} catch (PDOException $e) {
    die("Error recreating database: " . $e->getMessage() . "\n");
}

echo "Restoring database from complete backup...\n";

// Path to mysql executable on XAMPP
$mysqlPath = 'C:\\xampp\\mysql\\bin\\mysql.exe';

// Read backup file and remove USE statement
$sqlContent = file_get_contents($backupFile);
$sqlContent = preg_replace('/^USE `ghazali`;$/m', '', $sqlContent);

// Write modified SQL to temp file
$tempSqlFile = __DIR__ . '/temp_full_backup.sql';
file_put_contents($tempSqlFile, $sqlContent);

// Command to restore
$command = '"' . $mysqlPath . '" -h' . $host . ' -u' . $user . ' -p' . $pass . ' ' . $db . ' < "' . $tempSqlFile . '"';

echo "Running restore command...\n";
exec($command, $output, $exitCode);

// Clean up temp file
unlink($tempSqlFile);

if ($exitCode === 0) {
    echo "Database restored successfully!\n";
    echo "\nNow running migration script to add new features...\n";
    
    // Now run migration script
    require_once __DIR__ . '/migrate_new_features.php';
} else {
    echo "Error restoring database. Exit code: $exitCode\n";
    echo "Output:\n" . implode("\n", $output) . "\n";
}
?>
