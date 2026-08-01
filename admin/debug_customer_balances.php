<?php
require_once '../includes/config.php';

echo "<h2>Customer Accounts (unified_accounts)</h2>";
$stmt = $pdo->query("SELECT id, account_code, account_name_ar, account_type, parent_id FROM unified_accounts WHERE account_code LIKE '11201%' OR account_type IN ('customer', 'عميل') ORDER BY account_code");
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($customers);
echo "</pre>";

echo "<h2>Customer Balances (account_balances_unified)</h2>";
$customer_ids = array_column($customers, 'id');
if (!empty($customer_ids)) {
    $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT
            abu.account_id,
            abu.currency_id,
            c.currency_name,
            c.currency_symbol,
            c.currency_code,
            abu.current_balance,
            abu.current_balance_base,
            ua.normal_balance
        FROM account_balances_unified abu
        LEFT JOIN currencies c ON abu.currency_id = c.id
        LEFT JOIN unified_accounts ua ON abu.account_id = ua.id
        WHERE abu.account_id IN ($placeholders)
        ORDER BY abu.account_id ASC, c.currency_name ASC
    ");
    $bal_stmt->execute($customer_ids);
    $balances = $bal_stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($balances);
    echo "</pre>";
}
?>