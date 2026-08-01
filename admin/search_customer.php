<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>بحث عن أحمد علي</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: right; }
        th { background-color: #f5f5f5; }
        h2, h3 { color: #333; }
        .red { color: red; }
        .green { color: green; }
    </style>
</head>
<body>
<?php
// Search for أحمد علي
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>بحث عن: أحمد علي</h2>";
    
    // Search in unified_accounts
    echo "<h3>1. البحث في unified_accounts:</h3>";
    $stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE account_name_ar LIKE ?");
    $stmt->execute(['%أحمد علي%']);
    $ua_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($ua_results) > 0) {
        echo "<table>";
        echo "<tr><th>id</th><th>account_code</th><th>account_name_ar</th><th>account_type</th><th>normal_balance</th><th>parent_id</th><th>branch_id</th><th>account_status</th></tr>";
        foreach ($ua_results as $row) {
            echo "<tr>";
            foreach ($row as $key => $value) {
                if (in_array($key, ['id', 'account_code', 'account_name_ar', 'account_type', 'normal_balance', 'parent_id', 'branch_id', 'account_status'])) {
                    echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
                }
            }
            echo "</tr>";
        }
        echo "</table>";
        
        // Get balances for these accounts
        echo "<h4>الأرصدة لهذه الحسابات:</h4>";
        $ua_ids = array_column($ua_results, 'id');
        if (!empty($ua_ids)) {
            $placeholders = implode(',', array_fill(0, count($ua_ids), '?'));
            $bal_stmt = $pdo->prepare("
                SELECT ab.*, c.currency_name, c.currency_symbol 
                FROM account_balances_unified ab 
                LEFT JOIN currencies c ON ab.currency_id = c.id 
                WHERE ab.account_id IN ($placeholders)
            ");
            $bal_stmt->execute($ua_ids);
            $balances = $bal_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($balances) > 0) {
                echo "<table>";
                echo "<tr><th>account_id</th><th>currency_name</th><th>opening_balance</th><th>current_balance</th></tr>";
                foreach ($balances as $bal) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($bal['account_id']) . "</td>";
                    echo "<td>" . htmlspecialchars($bal['currency_name'] ?? '') . "</td>";
                    echo "<td>" . number_format($bal['opening_balance'], 2) . "</td>";
                    echo "<td class='" . ($bal['current_balance'] >= 0 ? 'green' : 'red') . "'>" . number_format($bal['current_balance'], 2) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p>لا توجد أرصدة مسجلة لهذه الحسابات.</p>";
            }
        }
    } else {
        echo "<p>لا توجد نتائج في unified_accounts.</p>";
    }
    
    // Search in customers table
    echo "<h3>2. البحث في customers:</h3>";
    $stmt_c = $pdo->prepare("SELECT * FROM customers WHERE full_name LIKE ?");
    $stmt_c->execute(['%أحمد علي%']);
    $c_results = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($c_results) > 0) {
        echo "<table>";
        $columns = array_keys($c_results[0]);
        echo "<tr>";
        foreach ($columns as $col) {
            echo "<th>" . htmlspecialchars($col) . "</th>";
        }
        echo "</tr>";
        foreach ($c_results as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد نتائج في customers.</p>";
    }
    
    // Check for duplicates
    echo "<h3>3. التحقق من الأسماء المكررة في unified_accounts:</h3>";
    $dup_stmt = $pdo->query("
        SELECT account_name_ar, COUNT(*) as count, GROUP_CONCAT(account_code) as codes, GROUP_CONCAT(id) as ids
        FROM unified_accounts 
        WHERE account_type = 'عميل'
        GROUP BY account_name_ar 
        HAVING COUNT(*) > 1
    ");
    $duplicates = $dup_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($duplicates) > 0) {
        echo "<table>";
        echo "<tr><th>الاسم</th><th>عدد التكرارات</th><th>أكواد الحسابات</th><th>معرفات الحسابات</th></tr>";
        foreach ($duplicates as $dup) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($dup['account_name_ar']) . "</td>";
            echo "<td class='red'>" . htmlspecialchars($dup['count']) . "</td>";
            echo "<td>" . htmlspecialchars($dup['codes']) . "</td>";
            echo "<td>" . htmlspecialchars($dup['ids']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد أسماء مكررة بين العملاء.</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color:red;'>خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>
