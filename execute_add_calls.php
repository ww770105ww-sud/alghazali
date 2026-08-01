<?php
require_once 'includes/db.php';

$sql = file_get_contents('add_calls_table.sql');
try {
    $pdo->exec($sql);
    echo "✅ تم إنشاء جدول internal_calls بنجاح!";
} catch (Exception $e) {
    echo "❌ خطأ: " . $e->getMessage();
}
?>