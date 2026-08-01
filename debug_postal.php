<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

echo "<h1>Debug Postal Services</h1>";

// Check services table
echo "<h2>Services Table</h2>";
$stmt = $pdo->query("SELECT * FROM services");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Name</th><th>Revenue ID</th><th>Cost ID</th><th>Profit ID</th></tr>";
foreach ($services as $s) {
    echo "<tr>";
    echo "<td>{$s['id']}</td>";
    echo "<td>{$s['service_name']}</td>";
    echo "<td>{$s['revenue_account_id']}</td>";
    echo "<td>{$s['cost_account_id']}</td>";
    echo "<td>{$s['profit_account_id']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check system_settings
echo "<h2>System Settings</h2>";
$stmt = $pdo->query("SELECT * FROM system_settings");
$settings = [];
echo "<table border='1'>";
echo "<tr><th>Key</th><th>Value</th></tr>";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
    echo "<tr><td>{$row['setting_key']}</td><td>{$row['setting_value']}</td></tr>";
}
echo "</table>";

// Check getServiceInvoiceConfig for 'خدمات البريد'
echo "<h2>getServiceInvoiceConfig('خدمات البريد')</h2>";
$config = getServiceInvoiceConfig('خدمات البريد', $settings);
echo "<pre>";
print_r($config);
echo "</pre>";

// Check unified accounts
echo "<h2>Unified Accounts (first 20)</h2>";
$stmt = $pdo->query("SELECT id, account_code, account_name_ar, account_status, account_type FROM unified_accounts LIMIT 20");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Code</th><th>Name AR</th><th>Status</th><th>Type</th></tr>";
foreach ($accounts as $a) {
    echo "<tr>";
    echo "<td>{$a['id']}</td>";
    echo "<td>{$a['account_code']}</td>";
    echo "<td>{$a['account_name_ar']}</td>";
    echo "<td>{$a['account_status']}</td>";
    echo "<td>{$a['account_type']}</td>";
    echo "</tr>";
}
echo "</table>";
?>
