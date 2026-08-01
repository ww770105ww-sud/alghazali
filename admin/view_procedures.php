<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فحص الإجراءات المخزنة</title>
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
// Check stored procedures
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>الإجراءات المخزنة (Stored Procedures)</h2>";
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = '$db'");
    if ($stmt->rowCount() > 0) {
        echo "<table>";
        echo "<tr><th>اسم الإجراء</th><th>تاريخ الإنشاء</th><th>تاريخ التعديل</th></tr>";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Created']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Modified']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد إجراءات مخزنة!</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>
