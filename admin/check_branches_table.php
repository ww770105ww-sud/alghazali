<?php
require_once '../includes/db.php';
session_start();

echo "<style>body{font-family:Arial,sans-serif;padding:2rem;direction:rtl}</style>";
echo "<h1>معلومات جدول branches</h1>";

// Show table structure
echo "<h3>هيكل الجدول</h3>";
$columns = $pdo->query("DESCRIBE branches")->fetchAll();
echo "<table border='1' style='border-collapse: collapse; width:100%'><tr><th>اسم الحقل</th><th>النوع</th><th>السماح NULL</th><th>المفتاح</th><th>القيمة الافتراضية</th><th>إضافي</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    foreach ($col as $val) {
        echo "<td style='padding:0.5rem'>$val</td>";
    }
    echo "</tr>";
}
echo "</table>";

// Show data
echo "<h3>البيانات الموجودة</h3>";
$data = $pdo->query("SELECT * FROM branches")->fetchAll(PDO::FETCH_ASSOC);
if (empty($data)) {
    echo "<p style='color:red'>لا توجد بيانات في جدول branches!</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; width:100%'>";
    echo "<tr>";
    foreach (array_keys($data[0]) as $header) {
        echo "<th style='padding:0.5rem; background:#f0f0f0'>$header</th>";
    }
    echo "</tr>";
    foreach ($data as $row) {
        echo "<tr>";
        foreach ($row as $cell) {
            echo "<td style='padding:0.5rem'>$cell</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

echo "<p><a href='system_hub.php'>الرجوع لمركز النظام</a></p>";
