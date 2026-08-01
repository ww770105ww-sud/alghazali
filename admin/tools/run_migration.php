<?php
// Use default XAMPP MySQL settings
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ تم الاتصال بقاعدة البيانات بنجاح!\n\n";
    
    // List of migration statements
    $migrations = [
        // --- family_visit_requests ---
        "ALTER TABLE `family_visit_requests` ADD COLUMN IF NOT EXISTS `sales_invoice_id` INT DEFAULT NULL AFTER `status_id`",
        "ALTER TABLE `family_visit_requests` ADD COLUMN IF NOT EXISTS `purchase_invoice_id` INT DEFAULT NULL AFTER `sales_invoice_id`",
        "ALTER TABLE `family_visit_requests` ADD COLUMN IF NOT EXISTS `auto_invoice_generated` TINYINT(1) DEFAULT '0' AFTER `purchase_invoice_id`",
        
        // --- Indexes for family_visit_requests ---
        "ALTER TABLE `family_visit_requests` ADD KEY IF NOT EXISTS `idx_family_sales_invoice` (`sales_invoice_id`)",
        "ALTER TABLE `family_visit_requests` ADD KEY IF NOT EXISTS `idx_family_purchase_invoice` (`purchase_invoice_id`)",
        
        // --- Drop old fields from bus_flight_bookings (optional, you can run later) ---
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `sale_price`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `cost_price`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `currency_id`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `payment_type`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `payment_status`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `amount_received`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `discount`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `tax_rate`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `tax_amount`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `net_amount`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `revenue_entry_id`",
        // "ALTER TABLE `bus_flight_bookings` DROP COLUMN IF EXISTS `cost_entry_id`",
        
        // --- Add service_id to invoices ---
        "ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `service_id` INT DEFAULT NULL AFTER `source_id`",
        "ALTER TABLE `invoices` ADD KEY IF NOT EXISTS `idx_invoice_service_id` (`service_id`)",
    ];
    
    foreach ($migrations as $sql) {
        echo "Executing: " . substr($sql, 0, 80) . "...\n";
        try {
            $pdo->exec($sql);
            echo "  ✅ تم!\n";
        } catch (Exception $e) {
            echo "  ⚠️ تحذير/تم مسبقاً: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 تم تطبيق جميع التحديثات بنجاح!\n";
    
} catch (PDOException $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n";
    echo "يرجى التأكد من تشغيل MySQL في XAMPP!\n";
}
