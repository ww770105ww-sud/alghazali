<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فحص جدول التدقيق</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: right; }
        th { background-color: #f5f5f5; }
        h2, h3 { color: #333; }
    </style>
</head>
<body>
<?php
// Check audit_logs table structure
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>هيكل جدول audit_logs</h2>";
    $stmt = $pdo->query("DESCRIBE audit_logs");
    echo "<table>";
    echo "<tr><th>اسم الحقل</th><th>النوع</th><th>يقبل NULL</th><th>المفتاح</th><th>القيمة الافتراضية</th><th>إضافي</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>أول 10 صفوف من audit_logs</h2>";
    $stmt = $pdo->query("SELECT * FROM audit_logs LIMIT 10");
    if ($stmt->rowCount() > 0) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>
