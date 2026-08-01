<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حقول جدول العملاء</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: right; }
        th { background-color: #f5f5f5; }
        h2 { color: #333; }
    </style>
</head>
<body>
<?php
// Database connection
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>1. هيكل جدول customers</h2>";
    $stmt = $pdo->query("DESCRIBE customers");
    echo "<table>";
    echo "<tr><th>اسم الحقل</th><th>النوع</th><th>يقبل NULL؟</th><th>مفتاح</th><th>القيمة الافتراضية</th><th>إضافي</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>2. بيانات تجريبية من customers</h2>";
    $stmt_data = $pdo->query("SELECT * FROM customers LIMIT 3");
    if ($stmt_data->rowCount() > 0) {
        $first_row = $stmt_data->fetch(PDO::FETCH_ASSOC);
        echo "<table>";
        echo "<tr>";
        foreach (array_keys($first_row) as $col) {
            echo "<th>" . htmlspecialchars($col) . "</th>";
        }
        echo "</tr>";
        $stmt_data->execute();
        while ($row = $stmt_data->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد بيانات في جدول customers.</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>
