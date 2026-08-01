<?php
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';

echo "<h2>Setting 'allow_multiple_sessions' to '1'</h2>";

$stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) 
                       VALUES ('allow_multiple_sessions', '1', 'general') 
                       ON DUPLICATE KEY UPDATE setting_value = '1'");
$stmt->execute();
echo "<p class='success'>Done! Rows affected: " . $stmt->rowCount() . "</p>";

// Also set session_behavior
$stmt2 = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) 
                        VALUES ('session_behavior', 'terminate_old', 'general') 
                        ON DUPLICATE KEY UPDATE setting_value = 'terminate_old'");
$stmt2->execute();
echo "<p class='success'>session_behavior set to 'terminate_old' too!</p>";

echo "<p><a href='settings.php?tab=time_date#time_date'>Go to settings page</a></p>";
?>