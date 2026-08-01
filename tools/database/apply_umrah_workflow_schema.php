<?php
/**
 * تطبيق باتش الأعمدة والجداول المفقودة لنظام سير العمل للعمرة
 */
$DB_HOST='127.0.0.1';$DB_USER='root';$DB_PASS='738155';$DB_NAME='ghazali';
$pdo=new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
$sqlFile=__DIR__.'/patch_umrah_workflow_missing_schema.sql';
$sql=file_get_contents($sqlFile);

echo "<h3>تطبيق باتش سير العمل للعمرة</h3>";
echo "<pre style='background:#f6f8fa;padding:15px;border-radius:8px;border:1px solid #d0d7de'>";

// تقسيم الأسطر وتتبع حالة DELIMITER
$lines = preg_split('/\r\n|\r|\n/', $sql);
$delimiter = ';';
$buffer = '';
$stmts = [];
$inDelimiterSet = false;
foreach ($lines as $line) {
    $trim = trim($line);
    if (preg_match('/^DELIMITER\s+(.+)$/i', $trim, $m)) {
        $delimiter = trim($m[1]);
        if ($buffer !== '') { $stmts[] = $buffer; $buffer = ''; }
        continue;
    }
    if ($trim === '' || strpos($trim, '--') === 0) {
        // لا نشمل التعليقات المنفردة فقط، نحتفظ بها داخل الاستعلام إذا كانت ضمنها
        // ولكن التعليقة منفردة سطر لا تُؤثر فالصافي
        $buffer .= $line."\n";
        // تأكد أن السطر لا يحتوي على delimiter فعلي مع بياناته قبله
        $check = preg_replace('/--.*$/s', '', $buffer);
        if (strlen(trim($check)) > 0 && substr(rtrim($check), -strlen($delimiter)) === $delimiter && $delimiter !== '') {
            $stmts[] = $buffer; $buffer = '';
        }
        continue;
    }
    $buffer .= $line."\n";
    // تحقق فقط إذا لم يكن هناك تعليقات "CREATE PROCEDURE" التي تتطلب multiple statements
    $simpleCheck = preg_replace('/--.*$/ms', '', $buffer);
    // إذا وجدنا delimiter في نهاية
    if (strlen(trim($simpleCheck))>0 && substr(rtrim($simpleCheck), -strlen($delimiter)) === $delimiter) {
        $stmts[] = $buffer; $buffer = '';
    }
}
if (trim($buffer) !== '') { $stmts[] = $buffer; }

$ok=0; $fail=0; $errors=[];
foreach ($stmts as $idx => $raw) {
    $clean = trim($raw);
    if ($clean === '') continue;
    // إزالة delimiter من النهاية
    if (substr($clean, -strlen($delimiter)) === $delimiter) {
        $clean = substr($clean, 0, -strlen($delimiter));
    }
    $clean = trim($clean);
    if ($clean === '') continue;
    try {
        $pdo->exec($clean);
        $ok++;
        $short = mb_substr(preg_replace('/\s+/',' ',$clean), 0, 110);
        echo "✅ #{$idx}: ".htmlspecialchars($short)." ...\n";
    } catch (Throwable $e) {
        $fail++;
        $errors[] = ['stmt'=>$clean, 'err'=>$e->getMessage()];
        echo "❌ #{$idx}: ".$e->getMessage()."\n";
        echo "   قيد التنفيذ: ".htmlspecialchars(mb_substr($clean,0,250))."\n\n";
    }
}
echo str_repeat('-',80)."\n";
echo "✅ الإجمالي: ناجح: $ok  |  فاشل: $fail\n\n";
if (!empty($errors)) {
    echo "قائمة الأخطاء:\n";
    foreach ($errors as $er) echo " - ".htmlspecialchars($er['err'])."\n";
} else {
    echo "🎉 تم تطبيق الباتش بالكامل بدون أخطاء!\n";
}

echo "\n".str_repeat('=',80)."\nالتحقق من النتيجة:\n";
// تحقق من الأعمدة والجداول الجديدة
$verify = [
    'COLUMN passports.workflow_step_id' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='passports' AND COLUMN_NAME='workflow_step_id'",
    'TABLE workflow_checklist'    => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_checklist'",
    'TABLE workflow_logs'         => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_logs'",
    'TABLE document_requirements' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_requirements'",
    'ROWS document_requirements'  => "SELECT COUNT(*) FROM document_requirements WHERE transaction_type='umrah'",
];
foreach ($verify as $label => $q) {
    $v=$pdo->query($q)->fetchColumn();
    echo "   • ".str_pad($label,36)." = $v\n";
}
echo "</pre>";
