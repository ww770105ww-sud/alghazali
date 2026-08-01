<?php
require_once 'includes/db.php';
echo "<h2>Actual Account Types in Database:</h2>";
$stmt = $pdo->query("SELECT DISTINCT account_type, COUNT(*) as count FROM unified_accounts GROUP BY account_type");
$existingTypes = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $type = $row['account_type'];
    if (!empty($type)) {
        $existingTypes[] = $type;
        echo "<p>" . htmlspecialchars($type) . " (" . $row['count'] . " accounts)</p>";
    }
}

echo "<hr><h2>Parent Accounts (with their account_type):</h2>";
$parentAccounts = $pdo->query("
    SELECT u.id, u.account_code, u.account_name_ar, u.account_type 
    FROM unified_accounts u 
    WHERE u.id IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) 
    OR u.parent_id IS NULL 
    ORDER BY u.account_code
")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='8'><tr><th>Code</th><th>Name</th><th>Type</th></tr>";
foreach ($parentAccounts as $acc) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($acc['account_code']) . "</td>";
    echo "<td>" . htmlspecialchars($acc['account_name_ar']) . "</td>";
    echo "<td>" . htmlspecialchars($acc['account_type']) . "</td>";
    echo "</tr>";
}
echo "</table>";
?>