<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

echo "Inserting permissions...<br>";

$permissions = [
    [
        'permission_code' => 'umrah_show_supplier',
        'display_name' => 'إظهار حقل المورد (جهة التكلفة)',
        'category' => 'umrah',
        'is_active' => 1,
        'description' => 'إظهار حقل المورد في نموذج العمرة'
    ],
    [
        'permission_code' => 'umrah_show_cost_currency',
        'display_name' => 'إظهار حقل عملة التكلفة (المورد)',
        'category' => 'umrah',
        'is_active' => 1,
        'description' => 'إظهار حقل عملة التكلفة في نموذج العمرة'
    ],
    [
        'permission_code' => 'umrah_show_cost_amount',
        'display_name' => 'إظهار حقل سعر التكلفة',
        'category' => 'umrah',
        'is_active' => 1,
        'description' => 'إظهار حقل سعر التكلفة في نموذج العمرة'
    ],
    [
        'permission_code' => 'umrah_show_discount',
        'display_name' => 'إظهار حقل مبلغ الخصم',
        'category' => 'umrah',
        'is_active' => 1,
        'description' => 'إظهار حقل مبلغ الخصم في نموذج العمرة'
    ]
];

try {
    $pdo->beginTransaction();
    
    foreach ($permissions as $perm) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO unified_permissions 
            (permission_code, display_name, category, is_active, description) 
            VALUES (?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $perm['permission_code'],
            $perm['display_name'],
            $perm['category'],
            $perm['is_active'],
            $perm['description']
        ]);
        
        echo "Inserted/Ignored: {$perm['display_name']}<br>";
    }
    
    $pdo->commit();
    echo "<br>Success! All permissions processed.";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage();
}
