<?php
/**
 * تطبيق سكربت إكمال الإصلاحات على قاعدة بيانات الغزالي
 * - الملف المستهدف: alghazali_remaining_fixes.sql
 * - يقرأ ملف SQL بتقسيم صحيح لـ DELIMITER (حلقة $$ -> ; -> $$)
 * - يسجل كل خطوة في ملف log معروض
 * - يرجع تقريراً نهائياً عن حالة الإجراءات والدوال بعد التطبيق
 *
 * التوافق: PHP 8.2+ / PDO / MariaDB 10.4+
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';
$charset = 'utf8mb4';

$sqlFile = __DIR__ . '/database/alghazali_remaining_fixes.sql';
$logFile = __DIR__ . '/database/remaining_fixes_' . date('Ymd_His') . '.log';

function log_msg($msg, $logFile) {
    $line = "[" . date('Y-m-d H:i:s') . "] " . trim($msg) . PHP_EOL;
    if (PHP_SAPI === 'cli') {
        echo $line;
    } else {
        echo nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'));
        flush();
    }
    file_put_contents($logFile, $line, FILE_APPEND);
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style=\"direction:rtl;text-align:right;font-family:Tahoma;font-size:13px;background:#fff;padding:20px;border:1px solid #ddd\">\n";
}

echo "=== سكربت إكمال الإصلاحات على قاعدة البيانات: $db ===\n\n";
log_msg("START: تحميل السكربت $sqlFile", $logFile);

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    log_msg("✅ الاتصال بقاعدة البيانات نجاح", $logFile);
} catch (Exception $e) {
    die("❌ فشل الاتصال: " . $e->getMessage() . "\n");
}

if (!file_exists($sqlFile)) {
    die("❌ ملف السكربت غير موجود: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    die("❌ فشل قراءة ملف السكربت\n");
}

// تحليل السكربت مع دعم DELIMITER متغير
$delimiter = ';';
$buffer    = '';
$tokens    = preg_split('/(\r\n|\n|\r)/', $sql);
$stmtCount = 0;
$errors    = 0;
$warns     = 0;

foreach ($tokens as $rawLine) {
    $line    = rtrim($rawLine);
    $trimmed = trim($line);

    // تخطي التعليقات والفواصل
    if ($trimmed === ''
        || strpos($trimmed, '--') === 0
        || (strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/') !== false && strlen($trimmed) < 300)) {
        continue;
    }

    // كشف تبديل DELIMITER (فقرة 1: نقل $$ -> ; بشكل صحيح)
    if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
        $newDelim = trim($m[1]);
        if ($newDelim === '') continue;

        $remaining = trim($buffer);
        if ($remaining !== '') {
            $buf2 = preg_split('/' . preg_quote($delimiter, '/') . '/', $remaining);
            foreach ($buf2 as $piece) {
                $piece = trim($piece);
                if ($piece === '') continue;
                try {
                    $pdo->exec($piece);
                    $stmtCount++;
                } catch (Exception $e) {
                    $errors++;
                    log_msg("❌ [DELIMITER-switch] خطأ #" . $stmtCount . ": " . $e->getMessage(), $logFile);
                    log_msg("   الجملة: " . mb_substr(preg_replace('/\s+/', ' ', $piece), 0, 250), $logFile);
                }
            }
            $buffer = '';
        }
        $delimiter = $newDelim;
        log_msg("🔁 تبديل DELIMITER -> [$delimiter]", $logFile);
        continue;
    }

    $buffer .= $line . "\n";

    // تنفيذ كلما وجدنا الـ delimiter الحالي
    while (true) {
        $pos = strpos($buffer, $delimiter);
        if ($pos === false) break;

        $stmt   = trim(substr($buffer, 0, $pos));
        $buffer = substr($buffer, $pos + strlen($delimiter));

        if ($stmt === '') continue;

        try {
            $pdo->exec($stmt);
            $stmtCount++;
            // تسجيل الأحداث الكبيرة فقط (DROP / CREATE)
            $stmtOneLine = preg_replace('/\s+/', ' ', $stmt);
            if (preg_match('/^(DROP\s+(PROCEDURE|FUNCTION|TRIGGER)|CREATE\s+(DEFINER=)?(PROCEDURE|FUNCTION|TRIGGER))/i', $stmtOneLine)) {
                if (preg_match('/`([^`]+)`/', $stmtOneLine, $mm)) {
                    log_msg("  + OK: {$mm[1]}", $logFile);
                }
            }
        } catch (Exception $e) {
            $msg = $e->getMessage();
            // تجاهل أخطاء "PROCEDURE/FUNCTION does not exist" عند DROP IF EXISTS
            if (preg_match('/^(PROCEDURE|FUNCTION|TRIGGER).*does not exist/i', $msg)
                || stripos($msg, 'Unknown table') !== false
                || stripos($msg, 'Duplicate key name') !== false) {
                $warns++;
                log_msg("⚠️  تنبيه #" . $stmtCount . ": " . mb_substr($msg, 0, 120), $logFile);
            } else {
                $errors++;
                log_msg("❌ خطأ #" . $stmtCount . ": " . $msg, $logFile);
                log_msg("   " . mb_substr($stmtOneLine, 0, 300), $logFile);
            }
            $stmtCount++;
        }
    }
}

// تنفيذ المتبقي في الـ buffer
$remaining = trim($buffer);
if ($remaining !== '') {
    $buf2 = preg_split('/' . preg_quote($delimiter, '/') . '/', $remaining);
    foreach ($buf2 as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;
        try {
            $pdo->exec($piece);
            $stmtCount++;
        } catch (Exception $e) {
            $errors++;
            log_msg("❌ [Final Buffer] خطأ: " . $e->getMessage(), $logFile);
        }
    }
}

log_msg("", $logFile);
log_msg("================ تقرير بعد التطبيق ================", $logFile);

// إعادة تشغيل sp_rebuild_balances للتأكد من توافق الأرصدة
try {
    $pdo->exec("CALL sp_rebuild_balances()");
    log_msg("✅ استدعاء sp_rebuild_balances() — تم", $logFile);
} catch (Exception $e) {
    $errors++;
    log_msg("❌ sp_rebuild_balances: " . $e->getMessage(), $logFile);
}

// تقرير قائمة الإجراءات والدوال المحدثة
$procs = [];
foreach ([
    'fn_sanitize_safe',
    'fn_convert_currency',
    'fn_convert_to_base_currency',
    'sp_recalculate_invoice_payment',
    'sp_create_invoice',
    'sp_create_receipt_voucher',
    'sp_create_payment_voucher',
    'sp_post_receipt_voucher',
    'sp_post_payment_voucher',
    'sp_unpost_invoice',
    'sp_update_account_balances',
    'sp_rebuild_balances',
    'sp_ensure_opening_balance',
    'sp_post_invoice',
] as $name) {
    try {
        $s = $pdo->prepare("SELECT name, type, is_deterministic, security_type, definer
                              FROM mysql.proc
                             WHERE db = DATABASE() AND name = ?
                             LIMIT 1");
        $s->execute([$name]);
        $row = $s->fetch();
        if ($row) {
            $procs[] = "  ✔ {$row['type']} {$name} | SECURITY: {$row['security_type']} | DEF: {$row['definer']}";
        } else {
            $procs[] = "  ❌ {$name} — غير موجود!";
            $errors++;
        }
    } catch (Exception $e) {
        $procs[] = "  ⚠️  {$name} — فشل الفحص: " . $e->getMessage();
    }
}
log_msg("--- قائمة الإجراءات والدوال المطبقة ---", $logFile);
foreach ($procs as $line) log_msg($line, $logFile);

// تقرير خاص بـ sp_rebuild_balances (فقرة 3: لا أرصدة افتتاحية صفرية جديدة)
try {
    $zeroRows = $pdo->query("
        SELECT COUNT(*) AS n
          FROM account_balances_unified
         WHERE opening_balance = 0 AND current_balance = 0
    ")->fetchColumn();
    log_msg("--- فقرة 3: أرصدة افتتاحية صفرية موجودة حالياً = $zeroRows ---", $logFile);
    log_msg("   (لا ينبغي زيادة هذا العدد بعد إعادة البناء في المستقبل)", $logFile);
} catch (Exception $e) {
    log_msg("⚠️  فشل فحص الأرصدة الصفرية: " . $e->getMessage(), $logFile);
}

// إعادة Foreign Key Checks
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

log_msg("", $logFile);
log_msg("================ ملخص التنفيذ ================", $logFile);
log_msg("✅ إجمالي الجمل المنفذة بنجاح: $stmtCount", $logFile);
log_msg("⚠️  تنبيهات (غير قاتلة): $warns", $logFile);
log_msg("❌ أخطاء: $errors", $logFile);
log_msg("📄 ملف السجل: $logFile", $logFile);
log_msg("DONE.", $logFile);

if (PHP_SAPI !== 'cli') {
    echo "</pre>\n";
}
