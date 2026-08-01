<?php
/**
 * RESTORE DATABASE FROM BACKUP
 */

echo "<h1 style='color:blue'>🔄 RESTORING DATABASE...</h1>";

// Database config (from db.php)
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db_name = 'ghazali';
$charset = 'utf8mb4';

try {
    // Connect to MySQL server (without db first)
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "<p style='color:green'>✅ Connected to MySQL server successfully!</p>";

    // Drop & recreate database to ensure clean slate
    $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
    echo "<p style='color:orange'>ℹ️ Dropped existing database (if any)</p>";

    $pdo->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p style='color:green'>✅ Created new $db_name database</p>";

    $pdo->exec("USE `$db_name`");
    echo "<p style='color:green'>✅ Selected database $db_name</p>";

    // Path to backup file
    $backup_file = __DIR__ . '/storage/db_backups/backup_ghazali_20260620_025836.sql';
    if (!file_exists($backup_file)) {
        throw new Exception("Backup file not found: $backup_file");
    }
    echo "<p style='color:blue'>📄 Using backup file: " . basename($backup_file) . "</p>";

    // Read & split SQL file
    $sql = file_get_contents($backup_file);
    $statements = [];
    $current_statement = '';
    $delimiter = ';';
    $in_string = false;
    $string_char = '';

    for ($i = 0; $i < strlen($sql); $i++) {
        $char = $sql[$i];
        $next_char = ($i + 1 < strlen($sql)) ? $sql[$i + 1] : '';

        // Handle string delimiters
        if (!$in_string && ($char === "'" || $char === '"')) {
            $in_string = true;
            $string_char = $char;
            $current_statement .= $char;
            continue;
        } elseif ($in_string && $char === $string_char) {
            $in_string = false;
            $current_statement .= $char;
            continue;
        }

        // Handle DELIMITER statements
        if (!$in_string && strtoupper(substr($sql, $i, 9)) === 'DELIMITER') {
            $end = strpos($sql, "\n", $i);
            if ($end === false) $end = strlen($sql);
            $delimiter_part = trim(substr($sql, $i, $end - $i));
            $delimiter = trim(substr($delimiter_part, 9));
            $i = $end - 1;
            continue;
        }

        $current_statement .= $char;

        // Check for delimiter at end of statement
        if (!$in_string && substr($current_statement, -strlen($delimiter)) === $delimiter) {
            $statement = trim(substr($current_statement, 0, -strlen($delimiter)));
            if (!empty($statement)) {
                $statements[] = $statement;
            }
            $current_statement = '';
        }
    }

    // Add last statement
    if (!empty(trim($current_statement))) {
        $statements[] = trim($current_statement);
    }

    echo "<p style='color:blue'>📋 Found " . count($statements) . " SQL statements to execute</p>";

    // Execute each statement
    $count = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (Exception $e) {
            echo "<p style='color:red'>❌ Error executing statement #$count: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre style='background:#f5f5f5; padding:10px'>" . htmlspecialchars($stmt) . "</pre>";
        }
    }

    echo "<h2 style='color:green; margin-top:20px'>✅ DATABASE RESTORED SUCCESSFULLY!</h2>";
    echo "<p>Executed $count statements</p>";

    // Verify users table exists
    $check = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if ($check) {
        echo "<p style='color:green'>✅ Users table exists and is accessible!</p>";
        $users = $pdo->query("SELECT COUNT(*) as cnt FROM users")->fetch();
        echo "<p style='color:blue'>👥 Number of users in table: " . $users['cnt'] . "</p>";
    }

} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</h2>";
    echo "<pre style='background:#f8d7da; padding:10px'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Delete this script for security after running?
echo "<hr><p style='color:red'>⚠️ <strong>Note:</strong> You should delete this file (restore_database.php) after use for security!</p>";
?>
