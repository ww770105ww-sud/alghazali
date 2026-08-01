<?php
require_once '../includes/config.php';

echo "<h2>حساب العميل 11201004</h2>";
$stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, normal_balance FROM unified_accounts WHERE account_code = '11201004'");
$stmt->execute();
$customerAccount = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($customerAccount);
echo "</pre>";

echo "<h2>أرصدة الحساب في account_balances_unified</h2>";
if ($customerAccount) {
    $stmt2 = $pdo->prepare("SELECT * FROM account_balances_unified WHERE account_id = ?");
    $stmt2->execute([$customerAccount['id']]);
    $balances = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($balances);
    echo "</pre>";
}

echo "<h2>حركات الحساب في journal_lines</h2>";
if ($customerAccount) {
    $stmt3 = $pdo->prepare("SELECT jl.*, ft.status FROM journal_lines jl JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id WHERE jl.account_id = ? AND ft.status = 'posted'");
    $stmt3->execute([$customerAccount['id']]);
    $journalLines = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($journalLines);
    echo "</pre>";
}
?>