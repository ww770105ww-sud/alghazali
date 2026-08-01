<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../includes/db.php';

echo "<pre style='direction: rtl; font-family: Tahoma;'>";
echo "=== بدء تطبيق تصحيح إدارة المعاملات ===\n\n";

$sqlFile = __DIR__ . '/patch_transactions.sql';
if (!file_exists($sqlFile)) {
    die("❌ ملف التصحيح غير موجود: $sqlFile");
}

$sqlContent = file_get_contents($sqlFile);

$delimiter = '$$';
$parts = explode($delimiter, $sqlContent);

$statementCount = 0;
$successCount = 0;
$errorList = [];

foreach ($parts as $part) {
    $part = trim($part);
    if (empty($part)) continue;
    
    if (preg_match('/^DELIMITER\s+/i', $part)) continue;
    if ($part === ';') continue;
    if ($part === 'DELIMITER ;') continue;
    
    $stmt = trim($part, " \t\n\r\0\x0B;");
    if (empty($stmt)) continue;
    
    $statementCount++;
    try {
        $pdo->exec($stmt);
        echo "✅ [الاستعلام $statementCount] تم التنفيذ بنجاح\n";
        $successCount++;
    } catch (PDOException $e) {
        $errMsg = $e->getMessage();
        echo "❌ [الاستعلام $statementCount] خطأ: $errMsg\n";
        $errorList[] = ['stmt' => substr($stmt, 0, 150), 'error' => $errMsg];
    }
}

echo "\n=== النتيجة النهائية ===\n";
echo "عدد الاستعلامات: $statementCount\n";
echo "نجاح: $successCount\n";
echo "أخطاء: " . count($errorList) . "\n";

if (count($errorList) > 0) {
    echo "\n=== تفاصيل الأخطاء ===\n";
    foreach ($errorList as $idx => $err) {
        echo "خطأ #" . ($idx + 1) . ":\n";
        echo "  الاستعلام: " . $err['stmt'] . "...\n";
        echo "  الخطأ: " . $err['error'] . "\n\n";
    }
}

echo "\n=== التحقق من الإجراءات المخزنة ===\n";
$procs = ['sp_create_invoice', 'sp_create_receipt_voucher', 'sp_create_payment_voucher',
          'sp_post_invoice', 'sp_post_receipt_voucher', 'sp_post_payment_voucher', 'sp_unpost_invoice'];

foreach ($procs as $proc) {
    $stmt = $pdo->query("SHOW CREATE PROCEDURE `$proc`");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['Create Procedure'])) {
        $body = $result['Create Procedure'];
        $hasTx = (strpos($body, 'START TRANSACTION') !== false);
        $hasHandler = (strpos($body, 'DECLARE EXIT HANDLER FOR SQLEXCEPTION') !== false);
        $hasRollback = (strpos($body, 'ROLLBACK') !== false);
        $hasCommit = (strpos($body, 'COMMIT') !== false);
        $hasResignal = (strpos($body, 'RESIGNAL') !== false);
        
        $status = [];
        $status[] = $hasTx ? 'START TRANSACTION✅' : 'START TRANSACTION❌';
        $status[] = $hasHandler ? 'HANDLER✅' : 'HANDLER❌';
        $status[] = $hasRollback ? 'ROLLBACK✅' : 'ROLLBACK❌';
        $status[] = $hasResignal ? 'RESIGNAL✅' : 'RESIGNAL❌';
        $status[] = $hasCommit ? 'COMMIT✅' : 'COMMIT❌';
        
        echo "🔹 $proc: " . implode(' | ', $status) . "\n";
    } else {
        echo "🔹 $proc: ❌ غير موجود\n";
    }
}

echo "</pre>";
