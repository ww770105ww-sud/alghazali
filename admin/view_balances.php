<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فحص الأرصدة</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: right; }
        th { background-color: #f5f5f5; }
        h2, h3 { color: #333; }
        .red { color: red; }
    </style>
</head>
<body>
<?php
// Check account_balances_unified for negative balances
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>فحص الأرصدة</h2>";
    
    // Get negative balances
    echo "<h3>الأرصدة السالبة</h3>";
    $stmt = $pdo->query("
        SELECT ab.*, ua.account_name_ar, ua.account_type, ua.account_code
        FROM account_balances_unified ab
        JOIN unified_accounts ua ON ab.account_id = ua.id
        WHERE ab.current_balance < 0
    ");
    if ($stmt->rowCount() > 0) {
        echo "<table>";
        echo "<tr><th>رقم الحساب</th><th>كود الحساب</th><th>اسم الحساب</th><th>نوع الحساب</th><th>العملة</th><th>الرصيد الحالي</th></tr>";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['account_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['account_code']) . "</td>";
            echo "<td>" . htmlspecialchars($row['account_name_ar']) . "</td>";
            echo "<td>" . htmlspecialchars($row['account_type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['currency_id']) . "</td>";
            echo "<td class='red'>" . number_format($row['current_balance'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد أرصدة سالبة!</p>";
    }
    
    // فحص الفواتير مع تغييرات الحالة معطل حالياً - تحقق من view_audit.php لأسماء الحقول
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>
