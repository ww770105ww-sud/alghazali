<?php
require_once 'includes/session_config.php';
require_once 'includes/db.php';

echo "<h2>Session Check:</h2>";
echo "<pre>";
var_dump($_SESSION);
echo "</pre>";

echo "<h2>Current User ID:</h2>";
$current_user_id = $_SESSION['admin_id'] ?? null;
echo $current_user_id ? $current_user_id : "Not logged in!";

echo "<h2>Users in Database:</h2>";
try {
    $stmt = $pdo->query("SELECT id, username, full_name FROM users LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    var_dump($users);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>