<?php
require_once __DIR__ . '/includes/db.php';

echo "Columns in user_activity_logs:\n";
$cols = $pdo->query("DESCRIBE user_activity_logs")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "- {$c['Field']} ({$c['Type']})\n";
}

// Insert test log using only existing columns
$user = $pdo->query("SELECT id FROM users LIMIT 1")->fetch();
if ($user) {
    echo "\nInserting test log...\n";
    $stmt = $pdo->prepare("
        INSERT INTO user_activity_logs 
        (user_id, activity_type, activity_description, ip_address, user_agent)
        VALUES 
        (?, 'login', 'تسجيل دخول تجريبي من سكريبت', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
    ");
    $stmt->execute([$user['id']]);
    echo "✅ Done! Added test log!\n";
}
?>
