<?php
/**
 * تشخيص تعارضات الـ collation في جداول قاعدة ghazali
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "===================================\n";
echo "تشخيص COLLATION في قاعدة $db\n";
echo "===================================\n\n";

// أي جداول لها أعمدة ب collation مختلف عن الافتراضي؟
$stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME, DATA_TYPE
                       FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = '$db'
                        AND DATA_TYPE IN ('varchar','char','text','mediumtext','longtext','tinytext')
                        AND COLLATION_NAME IS NOT NULL
                   ORDER BY COLLATION_NAME, TABLE_NAME, ORDINAL_POSITION");
$counts = [];
foreach ($stmt->fetchAll() as $r) {
    $key = $r['COLLATION_NAME'];
    if (!isset($counts[$key])) $counts[$key] = [];
    $counts[$key][] = $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'];
}
foreach ($counts as $collation => $cols) {
    echo "[$collation] => " . count($cols) . " عمود\n";
    foreach (array_slice($cols, 0, 10) as $c) {
        echo "  - $c\n";
    }
    if (count($cols) > 10) echo "  ... و " . (count($cols) - 10) . " أعمدة أخرى\n";
    echo "\n";
}

echo "===================================\n";
echo "الجداول ذات اختلاف في الـ collation بين الأعمدة:\n";
$tblCollations = [];
$stmt = $pdo->query("SELECT TABLE_NAME, COLLATION_NAME, COUNT(*) c
                       FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = '$db'
                        AND DATA_TYPE IN ('varchar','char','text','mediumtext','longtext','tinytext')
                        AND COLLATION_NAME IS NOT NULL
                   GROUP BY TABLE_NAME, COLLATION_NAME
                   ORDER BY TABLE_NAME, COLLATION_NAME");
foreach ($stmt->fetchAll() as $r) {
    $tblCollations[$r['TABLE_NAME']][] = $r['COLLATION_NAME'];
}
foreach ($tblCollations as $tbl => $colls) {
    if (count($colls) > 1) {
        echo "  - $tbl: " . implode(', ', $colls) . "\n";
    }
}
