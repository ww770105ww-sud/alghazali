<?php
/**
 * اختبار عرض أسماء الصلاحيات العربية بعد الإصلاح
 * يقوم بجلب نفس البيانات التي تجلبها صفحة roles.php
 */
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: text/html; charset=utf-8');

$sql = "SELECT up.id, up.permission_code, up.display_name, up.category
          FROM unified_permissions up
         WHERE up.is_active = 1
           AND up.category IN ('users','finance')
         ORDER BY up.category, up.display_name";
$rows = $pdo->query($sql)->fetchAll();

echo "<h2 style='direction:rtl;text-align:right'>اختبار عرض الصلاحيات (sample):</h2>";
echo "<table border='1' cellpadding='8' style='direction:rtl;text-align:right'>";
echo "<tr><th>الرقم</th><th>الكود</th><th>الاسم العربي</th><th>التصنيف</th></tr>";
foreach ($rows as $r) {
    echo "<tr>
        <td>{$r['id']}</td>
        <td><code>" . htmlspecialchars($r['permission_code']) . "</code></td>
        <td>" . htmlspecialchars($r['display_name']) . "</td>
        <td>" . htmlspecialchars($r['category']) . "</td>
    </tr>";
}
echo "</table>";
