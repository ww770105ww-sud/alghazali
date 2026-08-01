<?php
require 'includes/db.php';

echo "=== Services ===\n";
$servicesStmt = $pdo->query("SELECT * FROM services");
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($services as $s) {
    echo "ID: {$s['id']}, Name: {$s['service_name']}, Revenue: {$s['revenue_account_id']}, Cost: {$s['cost_account_id']}\n";
}

echo "\n=== System Settings ===\n";
$settingsStmt = $pdo->query("SELECT * FROM system_settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
echo "default_sales_account_id: " . ($settings['default_sales_account_id'] ?? 'NOT SET') . "\n";
echo "default_cost_account_id: " . ($settings['default_cost_account_id'] ?? 'NOT SET') . "\n";
foreach ($settings as $k => $v) {
    if (strpos($k, 'postal') !== false) {
        echo "{$k} = {$v}\n";
    }
}

echo "\n=== Unified Accounts ===\n";
$accountsStmt = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_status=1 LIMIT 20");
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($accounts as $a) {
    echo "ID: {$a['id']}, Code: {$a['account_code']}, Name: {$a['account_name_ar']}\n";
}

