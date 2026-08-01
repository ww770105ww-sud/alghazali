<?php
require_once '../includes/db.php';
require_once '../includes/CurrencyExchange.php';

$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
$currency_id = isset($_GET['currency_id']) ? intval($_GET['currency_id']) : 0;

if ($account_id > 0 && $currency_id > 0) {
    $exchange = new CurrencyExchange($pdo);
    $balance = $exchange->getAccountBalance($account_id, $currency_id);
    
    header('Content-Type: application/json');
    echo json_encode(['balance' => $balance]);
} else {
    echo json_encode(['balance' => 0]);
}
?>
