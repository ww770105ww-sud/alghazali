<?php
require 'includes/db.php';

echo "=== Services Table ===\n";
$stmt = $pdo->query("SELECT * FROM services");
var_dump($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== System Settings ===\n";
$stmt2 = $pdo->query("SELECT * FROM system_settings");
var_dump($stmt2->fetchAll(PDO::FETCH_ASSOC));
