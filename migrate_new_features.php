<?php
/**
 * Database Migration Script for New Features
 * Features:
 * - Time/Date/Timezone Settings
 * - User Activity Logs
 * - Session Management
 * - Attendance Enhancements
 */

require_once __DIR__ . '/includes/db.php';

echo "Starting database migration...\n";

try {
    $pdo->beginTransaction();

    // 1. Add time/date related settings to system_settings
    $new_settings = [
        'timezone' => 'Asia/Riyadh',
        'date_format' => 'Y-m-d',
        'time_format' => '24',
        'first_day_of_week' => 'sunday',
        'auto_sync_time' => '1',
        'attendance_early_minutes' => '15',
        'attendance_late_minutes' => '10',
        'departure_early_minutes' => '10',
        'departure_late_minutes' => '60',
        'prevent_outside_attendance' => '1',
        'allow_multiple_sessions' => '0',
        'session_behavior' => 'terminate_old' // or 'reject_new'
    ];

    foreach ($new_settings as $key => $value) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
        echo "Added/Checked setting: $key\n";
    }

    // 2. Create user_activity_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            full_name VARCHAR(255),
            activity_type VARCHAR(100) NOT NULL,
            activity_description TEXT,
            ip_address VARCHAR(50),
            user_agent TEXT,
            device_type VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_activity_type (activity_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Created/Checked table: user_activity_logs\n";

    // 3. Create user_sessions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(255) NOT NULL,
            ip_address VARCHAR(50),
            user_agent TEXT,
            device_type VARCHAR(50),
            browser VARCHAR(100),
            operating_system VARCHAR(100),
            timezone VARCHAR(100),
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL,
            status ENUM('active', 'ended', 'terminated') DEFAULT 'active',
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id),
            INDEX idx_status (status),
            INDEX idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Add new columns if they don't exist
    try {
        $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS browser VARCHAR(100)");
        $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS operating_system VARCHAR(100)");
        $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS timezone VARCHAR(100)");
    } catch (PDOException $e) {
        // Ignore errors (columns might already exist)
    }
    echo "Created/Checked table: user_sessions\n";

    // 4. Enhance attendance table (if exists)
    try {
        // Check if attendance table exists first
        $stmt = $pdo->query("SHOW TABLES LIKE 'attendance'");
        if ($stmt->rowCount() > 0) {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS status ENUM('early', 'on_time', 'late', 'left_early', 'normal', 'left_late') DEFAULT 'on_time'");
            $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS attempt_log TEXT");
            echo "Enhanced table: attendance\n";
        }
    } catch (PDOException $e) {
        echo "Note: attendance table might not exist yet or columns already added\n";
    }

    // 5. Create attendance_attempts table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendance_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            attempt_type ENUM('check_in', 'check_out') NOT NULL,
            attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('success', 'rejected') NOT NULL,
            rejection_reason TEXT,
            ip_address VARCHAR(50),
            device_info TEXT,
            INDEX idx_user_id (user_id),
            INDEX idx_attempt_time (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Created/Checked table: attendance_attempts\n";

    $pdo->commit();
    echo "\nMigration completed successfully!\n";

} catch (PDOException $e) {
    // Only rollback if transaction is still active
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (PDOException $e2) {
        // Ignore rollback errors
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>