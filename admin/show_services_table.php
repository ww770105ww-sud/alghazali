<?php
require_once '../includes/db.php';

echo '<style>
    body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
    h1 { color: #333; text-align: center; }
    .table-container { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 20px; max-width: 1200px; margin: 0 auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: right; border-bottom: 1px solid #eee; }
    th { background: #007bff; color: white; font-weight: bold; }
    tr:hover { background: #f9f9f9; }
    .back-link { display: block; text-align: center; margin-top: 20px; }
    .back-link a { padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; }
</style>';

echo '<div class="table-container">';
echo '<h1>📋 بنية جدول الخدمات (services)</h1>';

// Get table structure
$stmt = $pdo->query("DESCRIBE services");
$columns = $stmt->fetchAll();

echo '<table>';
echo '<tr><th>اسم الحقل</th><th>النوع</th><th>يسمح بقيمة فارغة؟</th><th>المفتاح</th><th>القيمة الافتراضية</th><th>إضافات</th></tr>';
foreach ($columns as $col) {
    echo '<tr>';
    echo '<td><strong>' . htmlspecialchars($col['Field']) . '</strong></td>';
    echo '<td>' . htmlspecialchars($col['Type']) . '</td>';
    echo '<td>' . ($col['Null'] === 'YES' ? 'نعم' : 'لا') . '</td>';
    echo '<td>' . htmlspecialchars($col['Key']) . '</td>';
    echo '<td>' . htmlspecialchars($col['Default'] ?? 'NULL') . '</td>';
    echo '<td>' . htmlspecialchars($col['Extra']) . '</td>';
    echo '</tr>';
}
echo '</table>';

// Show sample data
echo '<br><h2>📊 عينة من البيانات في جدول الخدمات</h2>';
$stmt = $pdo->query("SELECT * FROM services LIMIT 10");
$services = $stmt->fetchAll();

if (!empty($services)) {
    echo '<table>';
    echo '<tr>';
    foreach (array_keys($services[0]) as $key) {
        echo '<th>' . htmlspecialchars($key) . '</th>';
    }
    echo '</tr>';
    foreach ($services as $service) {
        echo '<tr>';
        foreach ($service as $value) {
            echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo '<p style="text-align: center; color: #666;">لا توجد بيانات في الجدول</p>';
}

echo '<div class="back-link"><a href="services.php">العودة لصفحة إدارة الخدمات</a></div>';
echo '</div>';
?>