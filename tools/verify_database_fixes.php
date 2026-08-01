<?php
/**
 * فحوصات التحقق النهائية بعد تطبيق إصلاحات قاعدة البيانات
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
$pdo->exec("SET NAMES utf8mb4");

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style=\"direction:rtl;text-align:right;font-family:Tahoma;font-size:13px;background:#fff;padding:20px\">\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "📋 تقرير فحص صحة الإصلاحات - قاعدة البيانات: $db\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$testsPassed = 0;
$testsFailed = 0;

function testResult($label, $pass, $detail = '') {
    global $testsPassed, $testsFailed;
    if ($pass) {
        echo "✅ " . trim($label) . "\n";
        $testsPassed++;
    } else {
        echo "❌ " . trim($label) . "\n";
        $testsFailed++;
    }
    if ($detail !== '') {
        echo "   ↳ " . trim($detail) . "\n";
    }
    echo "\n";
}

// ---------------------------------------------------------------------
// الفحص الأول: وجود جميع الدوال والإجراءات + SQL SECURITY INVOKER
// ---------------------------------------------------------------------
echo "🔹 [فقرة 1+9] فحص الإجراءات والدوال المطلوبة:\n";
echo "───────────────────────────────────────────────────────────────\n";

$requiredObjects = [
    ['type' => 'FUNCTION', 'name' => 'fn_sanitize_safe'],
    ['type' => 'FUNCTION', 'name' => 'fn_convert_currency'],
    ['type' => 'FUNCTION', 'name' => 'fn_convert_to_base_currency'],
    ['type' => 'PROCEDURE', 'name' => 'sp_recalculate_invoice_payment'],
    ['type' => 'PROCEDURE', 'name' => 'sp_create_invoice'],
    ['type' => 'PROCEDURE', 'name' => 'sp_create_receipt_voucher'],
    ['type' => 'PROCEDURE', 'name' => 'sp_create_payment_voucher'],
    ['type' => 'PROCEDURE', 'name' => 'sp_post_receipt_voucher'],
    ['type' => 'PROCEDURE', 'name' => 'sp_post_payment_voucher'],
    ['type' => 'PROCEDURE', 'name' => 'sp_unpost_invoice'],
    ['type' => 'PROCEDURE', 'name' => 'sp_update_account_balances'],
    ['type' => 'PROCEDURE', 'name' => 'sp_rebuild_balances'],
    ['type' => 'PROCEDURE', 'name' => 'sp_ensure_opening_balance'],
    ['type' => 'PROCEDURE', 'name' => 'sp_post_invoice'],
    ['type' => 'PROCEDURE', 'name' => 'sp_validate_journal_balance'],
];

$countInvoker = 0;
foreach ($requiredObjects as $obj) {
    $row = $pdo->query("
        SELECT security_type, definer
          FROM mysql.proc
         WHERE db = DATABASE()
           AND type = '{$obj['type']}'
           AND name = '{$obj['name']}'
         LIMIT 1
    ")->fetch();

    $exists = (bool)$row;
    $isInvoker = $exists && $row['security_type'] === 'INVOKER';
    if ($isInvoker) $countInvoker++;

    testResult(
        "{$obj['type']} {$obj['name']}",
        $exists && $isInvoker,
        $exists ? ("SECURITY={$row['security_type']} | DEF={$row['definer']}") : "غير موجود!"
    );
}

echo "\n📊 ملخص الأمان: $countInvoker / " . count($requiredObjects) . " كائنات تعمل ب SQL SECURITY INVOKER\n\n";

// ---------------------------------------------------------------------
// الفحص الثاني: دوال تنقية المدخلات (فقرة 2)
// ---------------------------------------------------------------------
echo "🔹 [فقرة 2] فحص عمل دوال التنقية:\n";
echo "───────────────────────────────────────────────────────────────\n";

$sanitizeTest = $pdo->query("SELECT fn_sanitize_safe(\"test'; DROP users--\", 1) AS r1, fn_sanitize_safe('وصفة عادية', 0) AS r2")->fetch();
testResult(
    "dالتالي fn_sanitize_safe تعطل حقن SQL",
    strpos($sanitizeTest['r1'], 'DROP') === false && strpos($sanitizeTest['r1'], "--") === false,
    "النتيجة: '{$sanitizeTest['r1']}' | الوصف: '{$sanitizeTest['r2']}'"
);

// ---------------------------------------------------------------------
// الفحص الثالث: دوال تحويل العملات (فقرة 8)
// ---------------------------------------------------------------------
echo "🔹 [فقرة 8] فحص دوال تحويل العملات:\n";
echo "───────────────────────────────────────────────────────────────\n";

// جلب معرفي عملتين مختلفتين (إن وجدتا)
$currs = $pdo->query("SELECT id, currency_code, exchange_rate FROM currencies ORDER BY id LIMIT 3")->fetchAll();
if (count($currs) >= 2) {
    $c1 = $currs[0];
    $c2 = $currs[1];
    $row = $pdo->query("
        SELECT
            fn_convert_currency(100, {$c1['id']}, {$c2['id']}) AS conv,
            fn_convert_to_base_currency(100, {$c1['id']}) AS base,
            fn_convert_currency(100, 99999, {$c2['id']}) AS missing_from
    ")->fetch();

    testResult(
        "fn_convert_currency تعيد قيمة منطقية",
        $row['conv'] > 0 && $row['conv'] !== null,
        "100 {$c1['currency_code']} = {$row['conv']} {$c2['currency_code']}"
    );
    testResult(
        "fn_convert_to_base_currency تعيد قيمة صحيحة",
        $row['base'] > 0,
        "100 {$c1['currency_code']} = {$row['base']} بالعملة الأساسية"
    );
    testResult(
        "fn_convert_currency تتعامل مع عملة غير موجودة (تعيد المبلغ)",
        $row['missing_from'] == 100,
        "قيمة الاختبار = {$row['missing_from']} (المتوقع: 100)"
    );
} else {
    echo "⚠️  تخطي فحص العملات: لا توجد عملات كافية في الجدول\n\n";
}

// ---------------------------------------------------------------------
// الفحص الرابع: توازن القيود المحاسبية (فقرة 2)
// ---------------------------------------------------------------------
echo "🔹 [فقرة 2+3] فحص توازن القيود المحاسبية:\n";
echo "───────────────────────────────────────────────────────────────\n";

$imbalanced = $pdo->query("
    SELECT
        ft.id,
        ft.transaction_number,
        SUM(COALESCE(jl.debit, 0))  AS tot_debit,
        SUM(COALESCE(jl.credit, 0)) AS tot_credit,
        ABS(SUM(COALESCE(jl.debit, 0)) - SUM(COALESCE(jl.credit, 0))) AS diff
      FROM financial_transactions ft
      JOIN journal_lines jl ON jl.financial_transaction_id = ft.id
     WHERE ft.status = 'posted'
     GROUP BY ft.id, ft.transaction_number
    HAVING diff > 0.01
     LIMIT 10
")->fetchAll();

testResult(
    "جميع المعاملات المرحلة متوازنة (مدين = دائن)",
    count($imbalanced) === 0,
    count($imbalanced) . " معاملات غير متوازنة"
);

foreach ($imbalanced as $ib) {
    echo "   ⚠️  #{$ib['id']} [{$ib['transaction_number']}] — مدين={$ib['tot_debit']} دائن={$ib['tot_credit']} فرق={$ib['diff']}\n";
}
echo "\n";

// ---------------------------------------------------------------------
// الفحص الخامس: sp_rebuild_balances لا يضيف صفوفاً صفرية جديدة (فقرة 3)
// ---------------------------------------------------------------------
echo "🔹 [فقرة 3] فحص إعادة بناء الأرصدة بدون صفوف صفرية:\n";
echo "───────────────────────────────────────────────────────────────\n";

$countBefore = $pdo->query("SELECT COUNT(*) FROM account_balances_unified")->fetchColumn();
$zeroBefore = $pdo->query("SELECT COUNT(*) FROM account_balances_unified WHERE opening_balance = 0 AND current_balance = 0")->fetchColumn();

// إعادة البناء
$pdo->exec("CALL sp_rebuild_balances()");

$countAfter = $pdo->query("SELECT COUNT(*) FROM account_balances_unified")->fetchColumn();
$zeroAfter = $pdo->query("SELECT COUNT(*) FROM account_balances_unified WHERE opening_balance = 0 AND current_balance = 0")->fetchColumn();

testResult(
    "sp_rebuild_balances لا يضيف صفوفاً جديدة",
    $countBefore === $countAfter,
    "قبل: $countBefore | بعد: $countAfter"
);
testResult(
    "عدد الصفوف الصفرية لا يزيد بعد إعادة البناء",
    $zeroAfter <= $zeroBefore,
    "الأرصدة الصفرية قبل: $zeroBefore | بعد: $zeroAfter"
);
echo "\n";

// ---------------------------------------------------------------------
// الفحص السادس: توقيعات الإجراءات متوافقة مع PHP القديم (17+1, 12+2)
// ---------------------------------------------------------------------
echo "🔹 فحص توافق توقيعات الإجراءات مع PHP:\n";
echo "───────────────────────────────────────────────────────────────\n";

// فحص توقيع sp_create_invoice
$paramsCreateInv = $pdo->query("
    SELECT COUNT(*) AS n
      FROM information_schema.PARAMETERS
     WHERE SPECIFIC_SCHEMA = DATABASE()
       AND SPECIFIC_NAME = 'sp_create_invoice'
       AND ORDINAL_POSITION > 0
")->fetchColumn();

$outsCreateInv = $pdo->query("
    SELECT COUNT(*) AS n FROM information_schema.PARAMETERS
     WHERE SPECIFIC_SCHEMA = DATABASE()
       AND SPECIFIC_NAME = 'sp_create_invoice'
       AND PARAMETER_MODE = 'OUT'
")->fetchColumn();

$insCreateInv = $paramsCreateInv - $outsCreateInv;

testResult(
    "sp_create_invoice: التوقيع 17IN + 1OUT = 18",
    $insCreateInv == 17 && $outsCreateInv == 1,
    "الواقع: {$insCreateInv}IN + {$outsCreateInv}OUT = {$paramsCreateInv}"
);

// سندات القبض والصرف 12IN + 2OUT = 14
foreach (['sp_create_receipt_voucher', 'sp_create_payment_voucher'] as $proc) {
    $total = $pdo->query("SELECT COUNT(*) FROM information_schema.PARAMETERS
                            WHERE SPECIFIC_SCHEMA = DATABASE() AND SPECIFIC_NAME = '$proc'")->fetchColumn();
    $outs = $pdo->query("SELECT COUNT(*) FROM information_schema.PARAMETERS
                           WHERE SPECIFIC_SCHEMA = DATABASE() AND SPECIFIC_NAME = '$proc' AND PARAMETER_MODE = 'OUT'")->fetchColumn();
    $ins = $total - $outs;
    testResult(
        "$proc: التوقيع 12IN + 2OUT = 14",
        $ins == 12 && $outs == 2,
        "الواقع: {$ins}IN + {$outs}OUT = {$total}"
    );
}
echo "\n";

// ---------------------------------------------------------------------
// الفحص السابع: أعمدة IP موجودة وبيانات financial_transactions قابلة للتعبئة
// ---------------------------------------------------------------------
echo "🔹 [فقرة 9] فحص وجود أعمدة IP في financial_transactions:\n";
echo "───────────────────────────────────────────────────────────────\n";

$requiredCols = ['created_ip', 'created_user_agent', 'updated_ip', 'posted_ip', 'cancelled_ip'];
$colsOK = true;
$missingCols = [];
foreach ($requiredCols as $col) {
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'financial_transactions'
           AND COLUMN_NAME = '$col'
    ")->fetchColumn();
    if (!$exists) {
        $colsOK = false;
        $missingCols[] = $col;
    }
}
testResult(
    "جميع أعمدة IP/UserAgent موجودة في financial_transactions",
    $colsOK,
    $colsOK ? implode(', ', $requiredCols) : "الأعمدة المفقودة: " . implode(', ', $missingCols)
);

// ---------------------------------------------------------------------
// الفحص الثامن: وجود أعمدة IP في invoices أيضاً
// ---------------------------------------------------------------------
$invCols = ['created_ip', 'created_user_agent'];
$invColsOK = true;
$invMissing = [];
foreach ($invCols as $col) {
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'invoices'
           AND COLUMN_NAME = '$col'
    ")->fetchColumn();
    if (!$exists) {
        $invColsOK = false;
        $invMissing[] = $col;
    }
}
testResult(
    "أعمدة IP/UserAgent موجودة في invoices",
    $invColsOK,
    $invColsOK ? "تم" : "المفقودة: " . implode(', ', $invMissing)
);
echo "\n";

// ---------------------------------------------------------------------
// الفحص التاسع: وجود جدول sequence_numbers ل fn_get_next_sequence
// ---------------------------------------------------------------------
echo "🔹 فحص جداول الدعم:\n";
echo "───────────────────────────────────────────────────────────────\n";

$seqExists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sequence_numbers'")->fetchColumn();
$abuExists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_balances_unified'")->fetchColumn();
$auditExists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'")->fetchColumn();

testResult("جدول sequence_numbers موجود", (bool)$seqExists);
testResult("جدول account_balances_unified موجود", (bool)$abuExists);
testResult("جدول audit_logs موجود", (bool)$auditExists);
echo "\n";

// ---------------------------------------------------------------------
// الفحص العاشر: سجل التدقيق (audit_logs) لديه سجلات
// ---------------------------------------------------------------------
$auditCount = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$auditRecent = $pdo->query("SELECT action, table_name, record_id FROM audit_logs ORDER BY id DESC LIMIT 3")->fetchAll();

testResult(
    "جدول التدقيق يعمل (يحتوي على بيانات)",
    $auditCount > 0,
    "عدد السجلات: $auditCount | آخر ثلاثة: " . json_encode($auditRecent, JSON_UNESCAPED_UNICODE)
);

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "📊 ملخص الفحوصات:\n";
echo "   ✅ نجح: $testsPassed\n";
echo "   ❌ فشل: $testsFailed\n";
echo "   📊 نسبة النجاح: " . ($testsPassed + $testsFailed > 0 ? round($testsPassed * 100 / ($testsPassed + $testsFailed), 1) : 0) . "%\n";
echo "═══════════════════════════════════════════════════════════════\n";

if ($testsFailed === 0) {
    echo "\n🎉 جميع الفحوصات اجتازت بنجاح! قاعدة البيانات جاهزة للاستخدام.\n";
} else {
    echo "\n⚠️  هناك فحوصات فاشلة. يرجى مراجعة التفاصيل أعلاه.\n";
}

if (PHP_SAPI !== 'cli') {
    echo "</pre>\n";
}
