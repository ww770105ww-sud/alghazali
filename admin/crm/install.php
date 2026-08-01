
<?php
require_once __DIR__ . '/../../includes/db.php';

echo "<h1>CRM Installation</h1>";
echo "<pre>";

try {
    // Check if tables exist
    $stmt = $pdo->query("SHOW TABLES LIKE 'crm_%'");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($existingTables) > 0) {
        echo "<p style='color: orange;'>Some CRM tables already exist. Skipping creation of existing tables...</p>";
    }
    
    // Read and execute SQL file
    $sqlFile = __DIR__ . '/../../tools/database/20260626_create_crm_tables.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        
        // Execute SQL (simple approach for this case)
        $pdo->exec($sql);
        
        echo "<p style='color: green;'>✅ Tables created/updated successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ SQL file not found!</p>";
    }
    
    // Add permissions (if unified permissions system exists)
    try {
        // Try to add CRM permissions
        $permissions = [
            'crm_view' => 'View CRM',
            'crm_edit' => 'Edit CRM',
            'crm_delete' => 'Delete CRM records'
        ];
        
        foreach ($permissions as $code => $name) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO unified_permissions (permission_code, permission_name, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$code, $name]);
        }
        echo "<p style='color: green;'>✅ Permissions added!</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Could not add permissions: " . $e->getMessage() . "</p>";
    }
    
    echo "<hr>";
    echo "<p><strong>Next steps:</strong></p>";
    echo "<ol>";
    echo "<li>Go to <a href='settings.php'>CRM Settings</a> and enter your WhatsApp Business API credentials</li>";
    echo "<li>Copy the Webhook URL from Settings and add it to your Meta App dashboard</li>";
    echo "<li>Start using the CRM!</li>";
    echo "</ol>";
    echo "<p><a href='index.php'>Go to CRM Dashboard →</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}

echo "</pre>";
?>

