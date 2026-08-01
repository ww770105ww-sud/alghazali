<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فحص الإجراءات والدوال</title>
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
// Check stored procedures and functions
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>الإجراءات والدوال المخزنة</h2>";
    $stmt = $pdo->query("SELECT ROUTINE_TYPE, ROUTINE_NAME, CREATED, LAST_ALTERED FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '$db'");
    if ($stmt->rowCount() > 0) {
        echo "<table>";
        echo "<tr><th>النوع</th><th>الاسم</th><th>تاريخ الإنشاء</th><th>تاريخ التعديل</th></tr>";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['ROUTINE_TYPE']) . "</td>";
            echo "<td>" . htmlspecialchars($row['ROUTINE_NAME']) . "</td>";
            echo "<td>" . htmlspecialchars($row['CREATED']) . "</td>";
            echo "<td>" . htmlspecialchars($row['LAST_ALTERED']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد إجراءات أو دوال مخزنة!</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>
