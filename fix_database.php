<?php
require_once __DIR__ . '/includes/db.php';

try {
    // First, let's create a new database with a different name
    $newDbName = 'ghazali_fixed';
    
    // Connect without db first to set max_allowed_packet
    $tempPdo = new PDO("mysql:host=127.0.0.1", 'root', '738155');
    $tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tempPdo->exec("SET GLOBAL max_allowed_packet = 1073741824");
    echo "Set max_allowed_packet to 1GB\n";
    
    // Drop new db if exists and recreate
    $tempPdo->exec("DROP DATABASE IF EXISTS `$newDbName`");
    $tempPdo->exec("CREATE DATABASE `$newDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Created new database: $newDbName\n";
    
    // Read backup file and modify it
    $backupFile = __DIR__ . '/storage/db_backups/backup_ghazali_20260620_025836.sql';
    $sql = file_get_contents($backupFile);
    
    // Remove "USE `ghazali`;" line
    $sql = preg_replace('/^USE `ghazali`;$/m', '', $sql);
    
    // Remove INSERT statements for views (documents)
    $sql = preg_replace('/^INSERT INTO `documents`.*?;$/sm', '', $sql);
    
    // Add SET FOREIGN_KEY_CHECKS=0 at the beginning
    $sql = "SET FOREIGN_KEY_CHECKS=0;\n" . $sql . "\nSET FOREIGN_KEY_CHECKS=1;\n";
    
    // Write modified SQL to temp file
    $tempSqlFile = __DIR__ . '/temp_backup.sql';
    file_put_contents($tempSqlFile, $sql);
    
    // Now restore using mysql command line
    $mysqlPath = 'C:\xampp\mysql\bin\mysql.exe';
    
    $command = sprintf(
        '"%s" -h127.0.0.1 -uroot -p738155 --max_allowed_packet=1G %s < "%s"',
        $mysqlPath,
        $newDbName,
        $tempSqlFile
    );
    
    exec($command, $output, $exitCode);
    
    // Clean up temp file
    unlink($tempSqlFile);
    
    if ($exitCode === 0) {
        echo "Restored backup to new database successfully.\n";
        echo "\nNow you need to:\n";
        echo "1. Stop MySQL in XAMPP\n";
        echo "2. Go to C:\\xampp\\mysql\\data\\\n";
        echo "3. Rename 'ghazali' folder to 'ghazali_old'\n";
        echo "4. Rename 'ghazali_fixed' folder to 'ghazali'\n";
        echo "5. Start MySQL again\n";
    } else {
        echo "Error restoring backup. Exit code: $exitCode\n";
        echo "Output:\n" . implode("\n", $output);
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>