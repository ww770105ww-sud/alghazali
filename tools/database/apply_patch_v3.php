<?php
/**
 * تطبيق باتش V3: إصلاح الإجراءات المخزنة + نظام المصروفات والميزانيات
 * المسار: tools/database/apply_patch_v3.php
 *
 * الطريقة: افتح الرابط التالي في المتصفح:
 *   http://localhost:8080/alghazali/tools/database/apply_patch_v3.php
 * أو نفّذ من سطر الأوامر:
 *   php tools/database/apply_patch_v3.php
 */

// محاولة تحميل الاتصال بقاعدة البيانات من المسارات المحتملة
$pdo = null;
$dbCandidates = [
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/../../admin/includes/db.php',
    __DIR__ . '/../../config/db.php',
];
$dbLoaded = false;

// إذا كنا في وضع CLI، نتجنب session_start المتواجد في session_config عن طريق الاتصال المباشر
if (php_sapi_name() === 'cli') {
    $dbHost = '127.0.0.1';
    $dbName = 'alghazali';
    $dbUser = 'root';
    $dbPass = '738155';

    $envCandidates = [
        __DIR__ . '/../../.env',
        __DIR__ . '/../../.env.local',
        __DIR__ . '/../../config.php',
    ];
    foreach ($envCandidates as $envFile) {
        if (!file_exists($envFile)) continue;
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'DB_HOST')      !== false && strpos($line, '=') !== false)
                $dbHost = trim(explode('=', $line, 2)[1], " \t\n\r\"';");
            if (strpos($line, 'DB_DATABASE')  !== false && strpos($line, '=') !== false)
                $dbName = trim(explode('=', $line, 2)[1], " \t\n\r\"';");
            if (strpos($line, 'DB_NAME')      !== false && strpos($line, '=') !== false)
                $dbName = trim(explode('=', $line, 2)[1], " \t\n\r\"';");
            if (strpos($line, 'DB_USER')      !== false && strpos($line, '=') !== false)
                $dbUser = trim(explode('=', $line, 2)[1], " \t\n\r\"';");
            if (strpos($line, 'DB_USERNAME')  !== false && strpos($line, '=') !== false)
                $dbUser = trim(explode('=', $line, 2)[1], " \t\n\r\"';");
            if (strpos($line, 'DB_PASS')      !== false && strpos($line, '=') !== false)
                $dbPass = trim(explode('=', $line, 2)[1], " \t\n\r\"';");
            if (strpos($line, 'DB_PASSWORD')  !== false && strpos($line, '=') !== false)
                $dbPass = trim(explode('=', $line, 2)[1], " \t\n\r\"';");
        }
    }

    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        $dbLoaded = true;
    } catch (Throwable $e) {
        // فشل الاتصال المباشر - سنحمل ملف الاتصال العادي
    }
}

if (!$dbLoaded) {
    foreach ($dbCandidates as $candidate) {
        if (file_exists($candidate)) {
            try {
                require_once $candidate;
                if (isset($pdo) && $pdo instanceof PDO) {
                    $dbLoaded = true;
                    break;
                }
            } catch (Throwable $e) {
                // تجاهل الخطأ (مثل session_start في CLI) وسنحاول الاتصال المباشر أدناه
            }
        }
    }
}

if (!$dbLoaded || !isset($pdo) || !($pdo instanceof PDO)) {
    // الاتصال المباشر النهائي كملاذ أخير
    $dbHost = '127.0.0.1';
    $dbName = 'alghazali';
    $dbUser = 'root';
    $dbPass = '738155';

    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        $dbLoaded = true;
    } catch (Throwable $e) {
        die(
            "<h1>❌ خطأ: تعذر الاتصال بقاعدة البيانات</h1>" .
            "<p>المعلومات المستخدمة: Host=$dbHost, DB=$dbName, User=$dbUser</p>" .
            "<p>الخطأ: " . htmlspecialchars($e->getMessage()) . "</p>" .
            "<p>يرجى التأكد من تشغيل MySQL وإعدادات الاتصال.</p>"
        );
    }
}

header('Content-Type: text/html; charset=utf-8');
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

echo "<h1>🚀 تطبيق باتش V3: الإجراءات المخزنة + نظام المصروفات + الميزانيات</h1><hr><pre>";

$sqlFile = __DIR__ . '/patch_v3_stored_procedures_and_expenses.sql';
if (!file_exists($sqlFile)) {
    die("❌ لم يتم العثور على ملف الباتش: " . basename($sqlFile));
}

echo "📂 قراءة ملف الباتش... " . number_format(filesize($sqlFile)) . " بايت\n\n";

$sqlRaw = file_get_contents($sqlFile);

// تقسيم الباتش إلى مجموعات بين DELIMITER
$delimiterCollapsed = str_replace(["\r\n", "\r"], "\n", $sqlRaw);
$tokens             = preg_split('/\n*DELIMITER\s+(\$\$|;)\n*/i', $delimiterCollapsed, -1, PREG_SPLIT_DELIM_CAPTURE);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

function pdo_flush_and_exec(PDO $pdo, $sql) {
    static $lastStmt = null;
    if ($lastStmt instanceof PDOStatement) {
        try {
            $lastStmt->closeCursor();
        } catch (Throwable $e) {}
        $lastStmt = null;
    }
    try {
        do {
            if ($pdo instanceof PDO) {
                $dummy = $pdo->query("SELECT 1");
                if ($dummy instanceof PDOStatement) {
                    $dummy->closeCursor();
                    break;
                }
            }
        } while (0);
    } catch (Throwable $e) {}

    $usesResult = (bool)preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN|CHECK|ANALYZE|OPTIMIZE|REPAIR)\s+/i', $sql);

    if ($usesResult) {
        $stmt = $pdo->query($sql);
        if ($stmt instanceof PDOStatement) {
            while ($stmt->fetch()) {}
            do {
                try {
                    while ($stmt->fetch()) {}
                } catch (Throwable $e) { break; }
            } while (method_exists($stmt, 'nextRowset') && $stmt->nextRowset());
            $stmt->closeCursor();
            $lastStmt = null;
        }
        return true;
    }

    if (preg_match('/^\s*(PREPARE|EXECUTE|DEALLOCATE\s+PREPARE)\s+/i', $sql)) {
        $stmt = $pdo->query($sql);
        if ($stmt instanceof PDOStatement) {
            do {
                try {
                    while ($stmt->fetch()) {}
                } catch (Throwable $e) { break; }
            } while (method_exists($stmt, 'nextRowset') && $stmt->nextRowset());
            $stmt->closeCursor();
        }
        $lastStmt = null;
        return true;
    }

    return $pdo->exec($sql);
}

$okCount    = 0;
$errCount   = 0;
$warnings   = [];
$stmtNumber = 0;
$currentDelimiter = ';';
$haltProcessing = false;

$totalTokens = count($tokens);
for ($i = 0; $i < $totalTokens && !$haltProcessing; $i++) {
    $chunk = trim($tokens[$i]);
    if ($chunk === '') {
        if ($i + 1 < $totalTokens && in_array(strtoupper($tokens[$i + 1]), ['$$', ';'])) {
            $currentDelimiter = $tokens[$i + 1];
            $i++;
        }
        continue;
    }

    if (in_array(strtoupper($chunk), ['$$', ';'])) {
        $currentDelimiter = $chunk;
        continue;
    }

    if (strtoupper($currentDelimiter) === '$$') {
        $statements = [];
        $buf = '';
        $inS = false;
        $inD = false;
        $inB = false;
        for ($k = 0, $len = strlen($chunk); $k < $len; $k++) {
            $ch = $chunk[$k];
            $buf .= $ch;
            if ($ch === "'"  && !$inD && !$inB) $inS = !$inS;
            if ($ch === '"'  && !$inS && !$inB) $inD = !$inD;
            if ($ch === '`'  && !$inS && !$inD) $inB = !$inB;
            if (!$inS && !$inD && !$inB && $ch === '$' && $k + 1 < $len && $chunk[$k + 1] === '$') {
                $buf = substr($buf, 0, -1);
                $candidate = trim($buf);
                if ($candidate !== '' && $candidate !== ';') {
                    $candidate = rtrim($candidate, " \t\n\r;");
                    if ($candidate !== '') {
                        $statements[] = $candidate . ';';
                    }
                }
                $buf = '';
                $k++;
            }
        }
        $candidate = trim($buf);
        if ($candidate !== '' && $candidate !== ';') {
            $candidate = rtrim($candidate, " \t\n\r;$");
            if ($candidate !== '') {
                $statements[] = $candidate . ';';
            }
        }
    } else {
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inBack   = false;
        for ($k = 0, $len = strlen($chunk); $k < $len; $k++) {
            $ch = $chunk[$k];
            $buffer .= $ch;
            if ($ch === "'"  && !$inDouble && !$inBack) $inSingle = !$inSingle;
            if ($ch === '"'  && !$inSingle && !$inBack) $inDouble = !$inDouble;
            if ($ch === '`'  && !$inSingle && !$inDouble) $inBack   = !$inBack;
            if ($ch === ';'  && !$inSingle && !$inDouble && !$inBack) {
                $candidate = trim($buffer);
                if ($candidate !== '' && $candidate !== ';') {
                    $statements[] = $candidate;
                }
                $buffer = '';
            }
        }
        $candidate = trim($buffer);
        if ($candidate !== '' && $candidate !== ';') {
            $statements[] = $candidate;
        }
    }

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || $statement === ';') continue;

        $stmtNumber++;
        $preview = mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 90);

        try {
            pdo_flush_and_exec($pdo, $statement);
            $okCount++;
            if ($stmtNumber % 20 === 0) {
                echo "  ✅  #$stmtNumber -> $preview ...\n";
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();

            $isWarning = false;
            if (
                stripos($msg, 'Unknown table') !== false ||
                stripos($msg, 'Duplicate entry') !== false ||
                stripos($msg, 'Duplicate key name') !== false ||
                stripos($msg, 'already exists') !== false ||
                stripos($msg, 'Can\'t DROP') !== false ||
                stripos($msg, 'Check that') !== false
            ) {
                $isWarning = true;
                $warnings[] = "⚠️  #$stmtNumber $preview\n   → $msg";
            }

            if ($isWarning) {
                echo "  ⚠️   #$stmtNumber $preview\n";
                echo "     $msg\n";
            } else {
                $errCount++;
                echo "  ❌ #$stmtNumber $preview\n";
                echo "     {$msg}\n";
                echo str_repeat('-', 80) . "\n";
                echo "بيانات الاستعلام الكاملة:\n";
                echo $statement . "\n";
                echo str_repeat('=', 80) . "\n\n";

                echo "هل تريد الاستمرار في تطبيق بقية الباتش؟\n";
                echo "اكتب yes للاستمرار أو أي شيء آخر للإيقاف: ";
                if (php_sapi_name() === 'cli') {
                    $answer = trim(fgets(STDIN));
                } else {
                    echo "\n<form method=\"post\"><button type=\"submit\" name=\"continue\" value=\"1\">أكمل التطبيق</button></form>";
                    if (!isset($_POST['continue'])) {
                        $haltProcessing = true;
                        break;
                    }
                    $answer = 'yes';
                }
                if (strtolower($answer) !== 'yes') {
                    echo "\n⛔ تم الإيقاف بواسطة المستخدم.\n";
                    $haltProcessing = true;
                    break;
                }
            }
        }
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "🏁 النتيجة النهائية:\n";
echo "  - الاستعلامات الناجحة: $okCount\n";
echo "  - التحذيرات:            " . count($warnings) . "\n";
echo "  - الأخطاء:              $errCount\n\n";

if (count($warnings)) {
    echo "📋 قائمة التحذيرات:\n";
    foreach ($warnings as $w) echo "  $w\n\n";
}

echo "\n✅ منقولة إلى قاعدة البيانات. قم بتجربة الصفحات الآن!";
echo "</pre>";
