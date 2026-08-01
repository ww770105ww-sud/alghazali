<?php
require_once 'includes/db.php';
echo '<pre>';
echo 'POST: ';
print_r($_POST);
echo "\n";
echo 'GET: ';
print_r($_GET);
echo "\n";
echo 'Services in DB: ';
$db_services = $pdo->query("SELECT id, service_name, revenue_account_id, cost_account_id, profit_account_id FROM services")->fetchAll(PDO::FETCH_ASSOC);
print_r($db_services);
echo "\n";
echo '</pre>';
