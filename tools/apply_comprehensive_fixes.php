<?php
/**
 * تطبيق سكربت الإصلاح الشامل لقاعدة بيانات الغزالي
 * - يقرأ ملف alghazali_comprehensive_fixes.sql بتقسيم صحيح لـ DELIMITER
 * - يطبق الجمل واحدة تلو الأخرى مع تسجيل الأخطاء
 * - يعرض تقريراً نهائياً عن كل إجراء ودالة تم إنشاؤها
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';
$charset = 'utf8mb4';

$sqlFile = __DIR__ . '/database/alghazali_comprehensive_fixes.sql';
$logFile = __DIR__ . '/database/fix_application_' . date('Ymd_His') . '.log';

function log_msg($msg, $logFile) {
    $line = "[" . date('Y-m-d H:i:s') . "] " . trim($msg) . PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

echo "=== تطبيق سكربت الإصلاح الشامل على قاعدة البيانات: $db ===\n\n";
log_msg("START: تطبيق السكربت $sqlFile", $logFile);

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
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

// تقسيم السكربت حسب DELIMITER مع دعم DELIMITER $$ / DELIMITER ;
$delimiter = ';';
$buffer    = '';
$tokens    = preg_split('/(\r\n|\n|\r)/', $sql);
$stmtCount = 0;
$errors    = 0;

foreach ($tokens as $rawLine) {
    $line = rtrim($rawLine);
    $trimmed = trim($line);

    if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
        continue;
    }

    if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
        $newDelim = trim($m[1]);
        if ($newDelim === '') continue;
        // تنفيذ ما في الـ buffer أولاً
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
                    log_msg("❌ خطأ في الجملة $stmtCount: " . $e->getMessage(), $logFile);
                    log_msg("   الجملة: " . mb_substr($piece, 0, 200), $logFile);
                }
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

        try {
            $pdo->exec($stmt);
            $stmtCount++;
        } catch (Exception $e) {
            $errors++;
            log_msg("❌ خطأ في الجملة $stmtCount: " . $e->getMessage(), $logFile);
            log_msg("   الجملة (الأولى 300 حرف): " . mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 300), $logFile);
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
            log_msg("❌ خطأ في الجملة $stmtCount: " . $e->getMessage(), $logFile);
            log_msg("   الجملة: " . mb_substr($piece, 0, 200), $logFile);
        }
    }
}

log_msg("\n==============================================", $logFile);
log_msg("✅ عدد الجمل المنفذة: $stmtCount", $logFile);
log_msg("❌ عدد الأخطاء         : $errors", $logFile);

// تقرير الإجراءات والدوال الحالية
log_msg("\n===== الإجراءات والدوال الحالية =====", $logFile);
try {
    $stmt = $pdo->query("SELECT ROUTINE_NAME, ROUTINE_TYPE, SECURITY_TYPE
                           FROM information_schema.ROUTINES
                          WHERE ROUTINE_SCHEMA = '$db'
                       ORDER BY ROUTINE_TYPE, ROUTINE_NAME");
    foreach ($stmt->fetchAll() as $r) {
        log_msg("  - {$r['ROUTINE_NAME']} ({$r['ROUTINE_TYPE']}, SECURITY={$r['SECURITY_TYPE']})", $logFile);
    }
} catch (Exception $e) {
    log_msg("⚠️  تعذر جلب قائمة الروتينز: " . $e->getMessage(), $logFile);
}

// تقرير الفهارس
log_msg("\n===== الفهارس الجديدة =====", $logFile);
$checks = [
    ['invoices',   'idx_invoices_invoice_status'],
    ['invoices',   'idx_invoices_payment_status'],
    ['invoices',   'idx_invoices_branch_id'],
    ['invoices',   'idx_invoices_created_at'],
    ['financial_transactions', 'idx_ft_status'],
    ['financial_transactions', 'idx_ft_reference_type'],
    ['financial_transactions', 'idx_ft_reference_id'],
    ['financial_transactions', 'idx_ft_created_at'],
    ['journal_lines', 'idx_jl_financial_transaction_id'],
    ['journal_lines', 'idx_jl_account_id'],
    ['journal_lines', 'idx_jl_currency_id'],
    ['payment_allocations', 'idx_pa_financial_transaction_id'],
    ['payment_allocations', 'idx_pa_invoice_id'],
];
foreach ($checks as [$tbl, $idx]) {
    try {
        $s = $pdo->query("SHOW INDEX FROM `$tbl` WHERE Key_name = '$idx'");
        if ($s->rowCount() > 0) {
            log_msg("  ✓ $tbl.$idx موجود", $logFile);
        } else {
            log_msg("  ✗ $tbl.$idx مفقود", $logFile);
        }
    } catch (Exception $e) {
        log_msg("  ⚠️  $tbl.$idx خطأ: " . $e->getMessage(), $logFile);
    }
}

log_msg("\n===== Triggers الحالية =====", $logFile);
try {
    $stmt = $pdo->query("SHOW TRIGGERS");
    foreach ($stmt->fetchAll() as $t) {
        log_msg("  - {$t['Trigger']} ({$t['Event']} {$t['Timing']} ON {$t['Table']})", $logFile);
    }
} catch (Exception $e) {
    log_msg("⚠️  تعذر جلب قائمة المحفزات: " . $e->getMessage(), $logFile);
}

// ====================================================================
// المرحلة الثانية: تطبيق باتش حماية SQL Injection
// (إذا كان الملف متاحاً — يضيف دوال التنقية ويعيد إنشاء الإجراءات الستة)
// ====================================================================
$sqlFile2 = __DIR__ . '/database/sqli_defense_patch.sql';
if (file_exists($sqlFile2)) {
    log_msg("\n===== المرحلة 2: تطبيق باتش حماية SQL Injection =====", $logFile);

    $sql2 = file_get_contents($sqlFile2);
    $delim2 = ';';
    $buf2   = '';
    $tokens2 = preg_split('/(\r\n|\n|\r)/', $sql2);
    $cnt2    = 0;
    $err2    = 0;

    foreach ($tokens2 as $rawLine) {
        $line = rtrim($rawLine);
        $trimmed = trim($line);

        if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
            continue;
        }

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
            $newDelim = trim($m[1]);
            if ($newDelim === '') continue;
            $remaining = trim($buf2);
            if ($remaining !== '') {
                $parts = preg_split('/' . preg_quote($delim2, '/') . '/', $remaining);
                foreach ($parts as $piece) {
                    $piece = trim($piece);
                    if ($piece === '') continue;
                    try {
                        $pdo->exec($piece);
                        $cnt2++;
                    } catch (Exception $e) {
                        $err2++;
                        log_msg("  ❌ [SQLi] خطأ $cnt2: " . $e->getMessage(), $logFile);
                    }
                }
                $buf2 = '';
            }
            $delim2 = $newDelim;
            log_msg("  🔁 DELIMITER -> $delim2", $logFile);
            continue;
        }

        $buf2 .= $line . "\n";

        while (true) {
            $pos = strpos($buf2, $delim2);
            if ($pos === false) break;
            $stmtPiece = trim(substr($buf2, 0, $pos));
            $buf2 = substr($buf2, $pos + strlen($delim2));
            if ($stmtPiece === '') continue;
            try {
                $pdo->exec($stmtPiece);
                $cnt2++;
            } catch (Exception $e) {
                $err2++;
                log_msg("  ❌ [SQLi] خطأ $cnt2: " . $e->getMessage(), $logFile);
            }
        }
    }

    $remaining = trim($buf2);
    if ($remaining !== '') {
        $parts = preg_split('/' . preg_quote($delim2, '/') . '/', $remaining);
        foreach ($parts as $piece) {
            $piece = trim($piece);
            if ($piece === '') continue;
            try {
                $pdo->exec($piece);
                $cnt2++;
            } catch (Exception $e) {
                $err2++;
            }
        }
    }

    $errors += $err2;
    $stmtCount += $cnt2;
    log_msg("  ✅ جمل المرحلة الثانية: $cnt2 | أخطاء: $err2", $logFile);

    log_msg("\n===== التحقق من دوال التنقية والإجراءات =====", $logFile);
    foreach (['fn_sanitize_input', 'fn_sanitize_text'] as $f) {
        try {
            $s = $pdo->query("SELECT SECURITY_TYPE FROM information_schema.ROUTINES
                               WHERE ROUTINE_SCHEMA = '$db' AND ROUTINE_NAME = '$f'");
            $r = $s->fetch();
            log_msg($r ? "  ✓ $f (SECURITY={$r['SECURITY_TYPE']})" : "  ✗ $f مفقود", $logFile);
        } catch (Exception $e) {
            log_msg("  ⚠️  $f: " . $e->getMessage(), $logFile);
        }
    }
    $sp_list = ['sp_create_invoice','sp_post_invoice','sp_create_payment_voucher',
                'sp_create_receipt_voucher','sp_post_payment_voucher','sp_post_receipt_voucher'];
    foreach ($sp_list as $sp) {
        try {
            $s = $pdo->query("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES
                               WHERE ROUTINE_SCHEMA = '$db' AND ROUTINE_NAME = '$sp'");
            $r = $s->fetch();
            $ok = $r && (stripos($r['ROUTINE_DEFINITION'], 'fn_sanitize_input') !== false ||
                         stripos($r['ROUTINE_DEFINITION'], 'fn_sanitize_text')  !== false);
            log_msg($ok ? "  ✓ $sp يطبق التنقية" : "  ⚠️  $sp لا يطبق التنقية", $logFile);
        } catch (Exception $e) {
            log_msg("  ⚠️  $sp: " . $e->getMessage(), $logFile);
        }
    }
} else {
    log_msg("\nℹ️  باتش SQLi غير موجود (تم التخطي): $sqlFile2", $logFile);
}

// ====================================================================
// المرحلة الثالثة: توافق توقيع الإجراءات مع استدعاءات PHP
// (هذه المرحلة ضرورية لتفادي خطأ 1318: Incorrect number of arguments
//  الذي يظهر لأن الإصدارات الكاملة للإجراءات لديها أرجام أكثر من PHP)
// ====================================================================
$sqlFile3 = __DIR__ . '/database/fix_procedure_signatures_php_compat.sql';
if (file_exists($sqlFile3)) {
    log_msg("\n===== المرحلة 3: توافق توقيع الإجراءات مع PHP =====", $logFile);

    $sql3 = file_get_contents($sqlFile3);
    $delim3 = ';';
    $buf3   = '';
    $tokens3 = preg_split('/(\r\n|\n|\r)/', $sql3);
    $cnt3    = 0;
    $err3    = 0;

    foreach ($tokens3 as $rawLine) {
        $line = rtrim($rawLine);
        $trimmed = trim($line);

        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
            $newDelim = trim($m[1]);
            if ($newDelim === '') continue;
            $remaining = trim($buf3);
            if ($remaining !== '') {
                $parts = preg_split('/' . preg_quote($delim3, '/') . '/', $remaining);
                foreach ($parts as $piece) {
                    $piece = trim($piece);
                    if ($piece === '') continue;
                    try {
                        $pdo->exec($piece);
                        $cnt3++;
                    } catch (Exception $e) {
                        $err3++;
                        log_msg("  ❌ [SIG] خطأ $cnt3: " . $e->getMessage(), $logFile);
                    }
                }
                $buf3 = '';
            }
            $delim3 = $newDelim;
            log_msg("  🔁 DELIMITER -> $delim3", $logFile);
            continue;
        }

        $buf3 .= $line . "\n";

        while (true) {
            $pos = strpos($buf3, $delim3);
            if ($pos === false) break;
            $stmtPiece = trim(substr($buf3, 0, $pos));
            $buf3 = substr($buf3, $pos + strlen($delim3));
            if ($stmtPiece === '') continue;
            try {
                $pdo->exec($stmtPiece);
                $cnt3++;
            } catch (Exception $e) {
                $err3++;
                log_msg("  ❌ [SIG] خطأ $cnt3: " . $e->getMessage(), $logFile);
            }
        }
    }

    $remaining = trim($buf3);
    if ($remaining !== '') {
        $parts = preg_split('/' . preg_quote($delim3, '/') . '/', $remaining);
        foreach ($parts as $piece) {
            $piece = trim($piece);
            if ($piece === '') continue;
            try {
                $pdo->exec($piece);
                $cnt3++;
            } catch (Exception $e) {
                $err3++;
            }
        }
    }

    $errors += $err3;
    $stmtCount += $cnt3;
    log_msg("  ✅ جمل المرحلة الثالثة: $cnt3 | أخطاء: $err3", $logFile);

    log_msg("\n===== تحقق عدد أرجام الإجراءات (التوافق مع PHP) =====", $logFile);
    $sigChecks = [
        ['sp_create_invoice'         , 18],
        ['sp_post_invoice'           ,  2],
        ['sp_create_receipt_voucher' , 14],
        ['sp_create_payment_voucher' , 14],
    ];
    foreach ($sigChecks as [$spName, $expectedCount]) {
        try {
            $s = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.PARAMETERS
                               WHERE SPECIFIC_SCHEMA = '$db' AND SPECIFIC_NAME = '$spName'");
            $r = $s->fetch();
            $actual = (int)$r['c'];
            $ok = $actual === $expectedCount;
            log_msg($ok
                ? "  ✓ $spName => $actual أرجام (متوافق)"
                : "  ✗ $spName => $actual أرجام، المتوقع $expectedCount", $logFile);
        } catch (Exception $e) {
            log_msg("  ⚠️  $spName: " . $e->getMessage(), $logFile);
        }
    }
} else {
    log_msg("\nℹ️  سكربت تواقيع PHP غير موجود (تم التخطي): $sqlFile3", $logFile);
}

log_msg("\n==============================================", $logFile);
log_msg("✅ المجموع النهائي للجمل المنفذة: $stmtCount", $logFile);
log_msg("❌ المجموع النهائي للأخطاء         : $errors", $logFile);

log_msg("\n🏁 النهاية - ملف السجل: $logFile\n", $logFile);
echo "\n🏁 تم الانتهاء. المجموع الكلي: $stmtCount جملة | أخطاء: $errors\n";
