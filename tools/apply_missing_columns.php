<?php
/**
 * إضافة الأعمدة المفقودة في جدول invoices (created_ip, created_user_agent)
 * مع IF NOT EXISTS لضمان عدم حدوث خطأ إذا كانت موجودة مسبقاً
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db   = 'ghazali';
$charset = 'utf8mb4';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style=\"direction:rtl;text-align:right;font-family:Tahoma;font-size:13px;background:#fff;padding:20px\">\n";
}

echo "🔧 إضافة أعمدة IP / User Agent المفقودة في جدول invoices:\n";
echo "═══════════════════════════════════════════════════\n\n";

function addColumnIfMissing($pdo, $table, $column, $definition) {
    $exists = $pdo->query("
        SELECT COUNT(*)
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '$table'
           AND COLUMN_NAME = '$column'
    ")->fetchColumn();

    if (!$exists) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "✅ تم إضافة العمود: $column\n";
            return true;
        } catch (Exception $e) {
            echo "❌ فشل إضافة العمود $column: " . $e->getMessage() . "\n";
            return false;
        }
    } else {
        echo "ℹ️  العمود موجود مسبقاً: $column\n";
        return true;
    }
}

// ---------- جدول invoices ----------
addColumnIfMissing($pdo, 'invoices', 'created_ip',
    "VARCHAR(45) NULL DEFAULT NULL COMMENT 'IP المستخدم الذي أنشأ الفاتورة' AFTER `created_by`");
addColumnIfMissing($pdo, 'invoices', 'created_user_agent',
    "TEXT NULL DEFAULT NULL COMMENT 'متصفح المستخدم الذي أنشأ الفاتورة' AFTER `created_ip`");
addColumnIfMissing($pdo, 'invoices', 'posted_ip',
    "VARCHAR(45) NULL DEFAULT NULL COMMENT 'IP المستخدم الذي رحّل الفاتورة' AFTER `posted_by`");

echo "\n";
echo "✅ اكتملت إضافة الأعمدة.\n";
echo "\n";

// ---------- جدول customers: إضافة أعمدة للحد الائتماني الافتراضي (اختياري) ----------
echo "🔧 التحقق من أعمدة الحد الائتماني في جدول customers:\n";
echo "─────────────────────────────────────────────────\n";

addColumnIfMissing($pdo, 'customers', 'default_credit_limit',
    "DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'الحد الائتماني الافتراضي بالعملة الأساسية'");

echo "\n";
echo "📋 قائمة الأعمدة النهائية في جدول invoices (أعمدة IP فقط):\n";
echo "─────────────────────────────────────────────────\n";

$cols = $pdo->query("
    SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'invoices'
       AND COLUMN_NAME LIKE '%ip%' OR COLUMN_NAME LIKE '%user_agent%'
")->fetchAll();
foreach ($cols as $c) {
    echo "  • {$c['COLUMN_NAME']} ({$c['DATA_TYPE']}) | NULL: {$c['IS_NULLABLE']} | DEFAULT: {$c['COLUMN_DEFAULT']}\n";
}
echo "\n✅ جاهز!\n";

if (PHP_SAPI !== 'cli') {
    echo "</pre>\n";
}
