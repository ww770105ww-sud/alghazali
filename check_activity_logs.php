<?php
require_once 'includes/db.php';
echo "<h1>Checking user_activity_logs table</h1>";
$count = $pdo->query("SELECT COUNT(*) AS cnt FROM user_activity_logs")->fetch()['cnt'];
echo "<h3>Total records: $count</h3>";

if ($count > 0) {
    $logs = $pdo->query("SELECT * FROM user_activity_logs ORDER BY created_at DESC LIMIT 10")->fetchAll();
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Type</th><th>Created At</th></tr>";
    foreach ($logs as $l) {
        echo "<tr><td>{$l['id']}</td><td>{$l['user_id']}</td><td>{$l['activity_type']}</td><td>{$l['created_at']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange;'>No activity logs found yet! Let's add some test data!</p>";

    // Add test data
    $test_user = $pdo->query("SELECT id FROM users LIMIT 1")->fetch();
    if ($test_user) {
        $stmt = $pdo->prepare("INSERT INTO user_activity_logs (user_id, activity_type, activity_description, ip_address, user_agent, device_type, browser, os, timezone) 
                               VALUES (?, 'login', 'تسجيل دخول تجريبي', '127.0.0.1', 'Mozilla/5.0', 'desktop', 'Chrome', 'Windows', 'Asia/Riyadh')");
        $stmt->execute([$test_user['id']]);
        echo "<p style='color:green;'>✅ Added test log! Refresh user_activity_log.php now!</p>";
    }
}
?>
