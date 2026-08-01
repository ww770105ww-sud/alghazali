<?php
/**
 * تطبيق سكربت إصلاح توقيع الإجراءات للتوافق مع كود PHP
 * (يحل خطأ 1318: Incorrect number of arguments)
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';
$charset = 'utf8mb4';

$sqlFile = __DIR__ . '/database/fix_procedure_signatures_php_compat.sql';
$logFile = __DIR__ . '/database/fix_signatures_' . date('Ymd_His') . '.log';

function log_msg($msg, $logFile) {
    $line = "[" . date('Y-m-d H:i:s') . "] " . trim($msg) . PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

echo "=== تطبيق سكربت إصلاح تواقيع الإجراءات: $db ===\n\n";
log_msg("START: $sqlFile", $logFile);

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    log_msg("✅ الاتصال نجاح", $logFile);
} catch (Exception $e) {
    die("❌ فشل الاتصال: " . $e->getMessage() . "\n");
}

$sql = file_get_contents($sqlFile);
$delimiter = ';';
$buffer    = '';
$tokens    = preg_split('/(\r\n|\n|\r)/', $sql);
$cnt       = 0;
$err       = 0;

foreach ($tokens as $rawLine) {
    $line = rtrim($rawLine);
    $trimmed = trim($line);
    if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;

    if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
        $newDelim = trim($m[1]);
        if ($newDelim === '') continue;
        $rem = trim($buffer);
        if ($rem !== '') {
            foreach (preg_split('/' . preg_quote($delimiter, '/') . '/', $rem) as $piece) {
                $piece = trim($piece);
                if ($piece === '') continue;
                try { $pdo->exec($piece); $cnt++; }
                catch (Exception $e) { $err++; log_msg("❌ $cnt: " . $e->getMessage(), $logFile); }
            }
            $buffer = '';
        }
        $delimiter = $newDelim;
        log_msg("🔁 DELIMITER -> $delimiter", $logFile);
        continue;
    }

    $buffer .= $line . "\n";
    while (true) {
        $pos = strpos($buffer, $delimiter);
        if ($pos === false) break;
        $stmt = trim(substr($buffer, 0, $pos));
        $buffer = substr($buffer, $pos + strlen($delimiter));
        if ($stmt === '') continue;
        try { $pdo->exec($stmt); $cnt++; }
        catch (Exception $e) { $err++; log_msg("❌ خطأ $cnt: " . $e->getMessage(), $logFile); }
    }
}

$rem = trim($buffer);
if ($rem !== '') {
    foreach (preg_split('/' . preg_quote($delimiter, '/') . '/', $rem) as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;
        try { $pdo->exec($piece); $cnt++; }
        catch (Exception $e) { $err++; log_msg("❌ $cnt: " . $e->getMessage(), $logFile); }
    }
}

log_msg("\n✅ الجمل المنفذة: $cnt | ❌ الأخطاء: $err", $logFile);

// تحقق من عدد الأرجام لكل إجراء
log_msg("\n===== تحقق النهائي من عدد أرجام الإجراءات =====", $logFile);
$checks = [
    ['sp_create_invoice'          , 18],  // 17IN+1OUT
    ['sp_post_invoice'            ,  5],  // 2IN أساسي + 3 اختياري
    ['sp_create_receipt_voucher'  , 14],  // 12IN+2OUT
    ['sp_create_payment_voucher'  , 14],  // 12IN+2OUT
    ['sp_post_receipt_voucher'    ,  6],  // 4IN أساسي + 2 اختياري (DEFAULT)
    ['sp_post_payment_voucher'    ,  6],  // 4IN أساسي + 2 اختياري (DEFAULT)
];
foreach ($checks as [$sp, $_min_expected]) {
    try {
        $s = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.PARAMETERS
                           WHERE SPECIFIC_SCHEMA='$db' AND SPECIFIC_NAME='$sp'");
        $r = $s->fetch();
        $c = (int)$r['c'];
        log_msg("  - $sp => $c arguments", $logFile);
    } catch (Exception $e) {
        log_msg("  ⚠️  $sp: " . $e->getMessage(), $logFile);
    }
}

log_msg("\n🏁 تم الانتهاء\n", $logFile);
echo "\n🏁 تم الانتهاء. المجموع الكلي: $cnt جملة | أخطاء: $err\n";
