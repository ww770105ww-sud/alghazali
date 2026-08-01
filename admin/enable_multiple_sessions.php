<?php
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    // Update the setting in the database
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group)
                           VALUES ('allow_multiple_sessions', '1', 'general')
                           ON DUPLICATE KEY UPDATE setting_value = '1'");
    $stmt->execute();
    
    echo "<h2>تم بنجاح!</h2>";
    echo "<p>تم تفعيل خيار السماح بتعدد الجلسات!</p>";
    echo "<p><a href='../admin/index.php'>الرجوع للوحة التحكم</a></p>";
    
} catch (Exception $e) {
    echo "<h2>خطأ!</h2>";
    echo "<p>حدث خطأ: " . $e->getMessage() . "</p>";
}
?>