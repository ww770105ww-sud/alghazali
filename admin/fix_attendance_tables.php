<?php
require_once 'includes/db.php';

try {
    // Create attendance_locations table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS attendance_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        latitude DECIMAL(10, 7) NOT NULL,
        longitude DECIMAL(10, 7) NOT NULL,
        radius_meters INT NOT NULL DEFAULT 100,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $pdo->exec($sql);
    echo "✅ attendance_locations table created or already exists!<br>";

    // Check tables: employees first, then users
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $target_table = null;
    if (in_array('employees', $tables)) {
        $target_table = 'employees';
    } elseif (in_array('users', $tables)) {
        $target_table = 'users';
    }
    
    if ($target_table) {
        $check_col = $pdo->query("SHOW COLUMNS FROM `$target_table` LIKE 'attendance_location_id'");
        if ($check_col->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$target_table` ADD COLUMN attendance_location_id INT NULL AFTER id, ADD INDEX (attendance_location_id)");
            echo "✅ attendance_location_id column added to $target_table table!<br>";
        } else {
            echo "ℹ️ attendance_location_id column already exists in $target_table!<br>";
        }
    } else {
        echo "⚠️ No users or employees table found!<br>";
    }
    
    echo "🎉 All done!";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>