<?php
require_once __DIR__ . '/includes/db.php';

// Check enable_postal_services, enable_hajj, enable_crm
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?, ?)");
$stmt->execute(['enable_postal_services', 'enable_hajj', 'enable_crm']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Current DB values ===\n";
foreach ($rows as $row) {
    echo "{$row['setting_key']}: {$row['setting_value']}\n";
}
