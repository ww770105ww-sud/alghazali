<?php
/**
 * سكربت تطبيق الهجرة: 014_booking_enhancement_system.sql
 * على قاعدة البيانات الفعلية (alghazali)
 *
 * طريقة الاستخدام:
 *   1- تأكد أن MySQL شغال في XAMPP (منفذ 3306 عادةً)
 *   2- شغّل السكربت عبر المتصفح أو PHP CLI:
 *      php tools/database/run_014_migration.php
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
set_time_limit(0);

// استدعاء إعدادات الاتصال من db.php
define('SYSTEM_ACCESS', true);
require_once __DIR__ . '/../../includes/db.php';

$sqlFile = __DIR__ . '/014_booking_enhancement_system.sql';
if (!file_exists($sqlFile)) {
    die('<div style="padding:20px;font-family:Arial;color:#a00;background:#fee;border:1px solid #faa;border-radius:8px;">
        <h3>❌ ملف الهجرة غير موجود</h3>
        <p>' . htmlspecialchars($sqlFile) . '</p></div>');
}

// قراءة الملف كاملاً
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    die('<div style="padding:20px;font-family:Arial;color:#a00;">❌ فشل قراءة ملف SQL</div>');
}

echo '<div dir="rtl" style="font-family:Tahoma,Arial;padding:20px;background:#f8f9fa;min-height:100vh;">';
echo '<h2 style="color:#1a73e8;border-bottom:3px solid #1a73e8;padding-bottom:8px;">
        🚀 بدء تطبيق الهجرة: نظام تحسينات الحجوزات المتكامل
      </h2>';
echo '<p><b>ملف الهجرة:</b> ' . htmlspecialchars(basename($sqlFile)) . '<br>';
echo '<b>حجم الملف:</b> ' . number_format(filesize($sqlFile) / 1024, 2) . ' كيلوبايت</p>';
echo '<hr>';

// إزالة التعليقات متعددة الأسطر والتفاعلية /* */ و -- لتقليل أخطاء التحليل
function split_sql_statements(string $sql): array {
    $statements = [];
    // تغيير محدد النهايات المؤقتاً إلى $$ لكي لا نفصل بداخل الإجراءات
    $inDelimiter = false;
    $buffer = '';
    $length = mb_strlen($sql, 'UTF-8');
    $i = 0;
    $inString = false;
    $quote = '';
    $singleLineComment = false;
    $multiLineComment = false;

    // طريقة أسهل: إزالة "DELIMITER $$" و "DELIMITER ;" مؤقتاً ثم الانقسام ب $$ بدل ;
    // لكن لتبسيط نستخدم تقسيم يدوي ذكي
    // المرجع: نحلل حرفاً بحرف مع تتبع الاقتباسات والـ DELIMITER

    $tokens = preg_split('/\r\n|\r|\n/', $sql);
    $currentStmt = '';
    $currentDelimiter = ';';

    foreach ($tokens as $line) {
        $trimmed = trim($line);

        // تمرير سطور فارغة
        if ($trimmed === '') continue;

        // كشف تغيير DELIMITER
        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $m)) {
            $currentDelimiter = trim($m[1]);
            // إذا انتهى الإجراء نضيف ما قبل التغيير إن وجد
            if (trim($currentStmt) !== '') {
                $statements[] = $currentStmt;
                $currentStmt = '';
            }
            continue;
        }

        // التحقق من نهاية الجملة الحالية
        $currentStmt .= $line . "\n";
        $delimiterPos = 0;
        $lenOfCurrentStmt = mb_strlen($currentStmt, 'UTF-8');
        $lenOfDelimiter   = mb_strlen($currentDelimiter, 'UTF-8');

        // فقط إذا كان نص السطر (أو تراكمه) ينتهي بـ الديلميتر الحالي
        if ($lenOfCurrentStmt >= $lenOfDelimiter) {
            $endPart = mb_substr(rtrim($currentStmt), -$lenOfDelimiter, $lenOfDelimiter, 'UTF-8');
            if ($endPart === $currentDelimiter) {
                // إزالة الديلميتر من النهاية
                $cleanStmt = rtrim($currentStmt);
                $cleanStmt = mb_substr($cleanStmt, 0, mb_strlen($cleanStmt, 'UTF-8') - $lenOfDelimiter, 'UTF-8');
                $cleanStmt = trim($cleanStmt);
                if ($cleanStmt !== '') {
                    $statements[] = $cleanStmt;
                }
                $currentStmt = '';
            }
        }
    }

    // ما تبقى بعد آخر سطر
    if (trim($currentStmt) !== '') {
        $statements[] = $currentStmt;
    }

    return $statements;
}

$statements = split_sql_statements($sql);
$total = count($statements);
echo "<p><b>عدد الاستعلامات التي سيتم تنفيذها:</b> <span style=\"color:#1a73e8;font-weight:bold;font-size:18px;\">$total</span></p>";

echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;max-height:500px;overflow:auto;">';

$success = 0;
$warnings = 0;
$errors = 0;
$errorList = [];

foreach ($statements as $idx => $stmt) {
    $display = mb_substr(trim(preg_replace('/\s+/', ' ', $stmt)), 0, 140, 'UTF-8');
    $num = $idx + 1;

    try {
        $result = $pdo->exec($stmt);
        echo "<div style=\"padding:4px 8px;color:#155724;\">✅ <b>#{$num}</b> / $total : " . htmlspecialchars($display) . " <span style=\"color:#888;font-size:11px;\">(affected: $result)</span></div>";
        $success++;
    } catch (PDOException $e) {
        $errMsg = $e->getMessage();
        // بعض الأخطاء مقبولة مثل "Duplicate column name" لأننا استخدمنا IF NOT EXISTS
        $isWarning = (
            stripos($errMsg, 'Duplicate column name') !== false ||
            stripos($errMsg, 'Duplicate key name') !== false ||
            stripos($errMsg, 'already exists') !== false ||
            stripos($errMsg, 'Cant DROP') !== false
        );

        if ($isWarning) {
            echo "<div style=\"padding:4px 8px;color:#856404;background:#fff3cd;\">⚠️ <b>#{$num}</b> تحذير: " . htmlspecialchars($display) . " <small>(" . htmlspecialchars($errMsg) . ")</small></div>";
            $warnings++;
        } else {
            echo "<div style=\"padding:4px 8px;color:#721c24;background:#f8d7da;\">❌ <b>#{$num}</b> خطأ: " . htmlspecialchars($display) . " <br><b>الرسالة:</b> " . htmlspecialchars($errMsg) . "</div>";
            $errors++;
            $errorList[] = [
                'no'   => $num,
                'stmt' => $display,
                'msg'  => $errMsg,
            ];
        }
    }

    @ob_flush();
    @flush();
}

echo '</div>';

echo '<hr>';
echo '<h3 style="color:#0a6;">📊 النتيجة النهائية:</h3>';
echo '<ul style="font-size:16px;line-height:2;">';
echo '<li>✅ استعلامات نجحت: <b style="color:#0a6;">' . number_format($success) . '</b></li>';
echo '<li>⚠️ تحذيرات (عمود موجود مسبقاً): <b style="color:#a67;">' . number_format($warnings) . '</b></li>';
echo '<li>❌ أخطاء حقيقية: <b style="color:#c44;">' . number_format($errors) . '</b></li>';
echo '</ul>';

if ($errors === 0) {
    echo '<div style="padding:20px;background:#d4edda;border:1px solid #28a745;border-radius:12px;margin:16px 0;">
            <h2 style="color:#155724;margin:0 0 8px;">🎉 تم تطبيق الهجرة بنجاح!</h2>
            <p style="margin:0;">تم إنشاء وتحديث جميع الجداول والأعمدة والإجراءات المخزنة (Stored Procedures) بنجاح على قاعدة البيانات alghazali.</p>
          </div>';

    // اختبار سريع للتأكد من وجود الإجراءات
    $check = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.ROUTINES 
                            WHERE ROUTINE_SCHEMA = DATABASE() 
                            AND ROUTINE_NAME IN (
                              'sp_confirm_booking','sp_request_booking_modification',
                              'sp_approve_booking_modification','sp_cancel_booking',
                              'sp_update_booking_stage','sp_generate_ticket',
                              'sp_create_booking_notification'
                            )")->fetch();
    echo '<p>🔍 عدد الإجراءات المخزنة الجديدة التي تم إنشاؤها: <b style="font-size:20px;color:#1a73e8;">' . $check['c'] . ' / 7</b></p>';

    $tables = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.TABLES 
                            WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME IN (
                              'booking_workflow','booking_modifications','booking_refunds',
                              'booking_notifications','booking_tickets'
                            )")->fetch();
    echo '<p>🗂️ عدد الجداول الجديدة: <b style="font-size:20px;color:#1a73e8;">' . $tables['c'] . ' / 5</b></p>';

    echo '<br><a href="/admin/index.php" style="background:#1a73e8;color:#fff;padding:12px 28px;text-decoration:none;border-radius:8px;font-weight:bold;">← العودة إلى لوحة التحكم</a>';
} else {
    echo '<div style="padding:20px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:12px;margin:16px 0;">
            <h2 style="color:#721c24;margin:0 0 8px;">⚠️ حدثت بعض الأخطاء</h2>
            <p style="margin:0;">يوجد ' . count($errorList) . ' خطأ/أخطاء برجاء مراجعة سجل الأخطاء أعلاه وإصلاحها قبل إعادة المحاولة.</p>
          </div>';
    echo '<ol>';
    foreach ($errorList as $e) {
        echo '<li>#' . $e['no'] . ' — ' . htmlspecialchars($e['stmt']) . '<br><b style="color:#a00;">' . htmlspecialchars($e['msg']) . '</b></li>';
    }
    echo '</ol>';
}

echo '</div>';
