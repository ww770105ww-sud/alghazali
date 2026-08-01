<?php
require_once '../includes/db.php';

try {
    // Check if columns already exist
    $check1 = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'source_type'");
    if (!$check1->fetch()) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN source_type VARCHAR(100) NULL AFTER link");
        echo "Added source_type column to notifications table.<br>";
    } else {
        echo "source_type column already exists.<br>";
    }

    $check2 = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'source_id'");
    if (!$check2->fetch()) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN source_id INT(11) NULL AFTER source_type");
        echo "Added source_id column to notifications table.<br>";
    } else {
        echo "source_id column already exists.<br>";
    }

    // Add index
    $pdo->exec("CREATE INDEX idx_notifications_source ON notifications (source_type, source_id)");
    
    echo "Successfully updated notifications table!";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
