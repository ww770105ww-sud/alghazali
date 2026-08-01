<?php
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

$rows = $pdo->query("SELECT id, permission_code, display_name FROM unified_permissions WHERE id >= 250 ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    echo $r['id'] . ' | ' . str_pad($r['permission_code'], 32) . ' | ' . $r['display_name'] . PHP_EOL;
}
echo PHP_EOL;

// Now check for remaining rows that still have ???
$all = $pdo->query("SELECT id, permission_code, display_name FROM unified_permissions ORDER BY id")->fetchAll();
$broken = 0;
foreach ($all as $r) {
    if (strpos($r['display_name'], '?') !== false || strpos($r['display_name'], '???') !== false) {
        $broken++;
        echo 'BROKEN: ' . $r['id'] . ' | ' . $r['permission_code'] . ' | ' . $r['display_name'] . PHP_EOL;
    }
}
echo PHP_EOL . 'Broken rows: ' . $broken . ' out of ' . count($all) . PHP_EOL;
