<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$roleName = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
$roleId = (int)($_SESSION['role_id'] ?? 0);
$isDeveloper = isset($_SESSION['admin_id']) && ($roleId === 2 || $roleName === 'developer');

if (!$isDeveloper) {
    http_response_code(403);
    exit('غير مصرح لك بتشغيل أدوات إصلاح الأرصدة.');
}

echo "<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f7; padding: 20px; direction: rtl; }
    .success { background: #d4edda; padding: 15px; border-radius: 8px; margin: 10px 0; color: #155724; }
    .error { background: #f8d7da; padding: 15px; border-radius: 8px; margin: 10px 0; color: #721c24; }
    .info { background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 10px 0; color: #0c5460; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);}
    th { background: #007aff; color: white; padding: 12px; text-align: right; }
    td { padding: 10px; border-bottom: 1px solid #eee; text-align: right; }
</style>";

echo "<h1>🛠️ إصلاح وتحديث جميع أرصدة الحسابات</h1>";

try {
    $pdo->beginTransaction();
    echo "<div class='info'> بداية العملية...</div>";

    // الخطوة 1: حذف جميع الأرصدة القديمة
    echo "<h3>الخطوة 1: حذف جميع الأرصدة الحالية</h3>";
    $delete_old = $pdo->exec("DELETE FROM account_balances_unified");
    echo "<div class='success'> تم حذف $delete_old سجل من جدول الأرصدة!</div>";

    // الخطوة 2: إعادة حساب جميع الأرصدة من الدفتر اليومي
    echo "<h3>الخطوة 2: إعادة حساب الأرصدة من الدفتر اليومي</h3>";

    // جلب جميع الحسابات
    $stmt_accounts = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts ORDER BY account_code ASC");
    $accounts = $stmt_accounts->fetchAll(PDO::FETCH_ASSOC);

    $total_processed = 0;
    foreach ($accounts as $account) {
        // جلب جميع العملات المستخدمة مع هذا الحساب على مستوى الحساب كله
        $stmt_currencies = $pdo->prepare("
            SELECT DISTINCT jl.currency_id, c.currency_code, c.currency_name
            FROM journal_lines jl
            LEFT JOIN currencies c ON jl.currency_id = c.id
            WHERE jl.account_id = ?
        ");
        $stmt_currencies->execute([$account['id']]);
        $currencies = $stmt_currencies->fetchAll(PDO::FETCH_ASSOC);

        // دائماً نضيف العملة الافتراضية
        $default_currency = $pdo->query("SELECT id, currency_code, currency_name FROM currencies WHERE is_default = 1 LIMIT 1")->fetch();
        $added_default = false;
        foreach ($currencies as $c) {
            if ($c['currency_id'] === $default_currency['id']) {
                $added_default = true;
                break;
            }
        }
        if (!$added_default && $default_currency) {
            $currencies[] = [
                'currency_id' => $default_currency['id'],
                'currency_code' => $default_currency['currency_code'],
                'currency_name' => $default_currency['currency_name']
            ];
        }

        foreach ($currencies as $curr) {
            $currency_id = $curr['currency_id'];

            // حساب إجمالي المدين والدائن من المعاملات المرحلة فقط
            $stmt_calc = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN ft.status = 'posted' THEN jl.debit ELSE 0 END), 0) as total_debit,
                    COALESCE(SUM(CASE WHEN ft.status = 'posted' THEN jl.credit ELSE 0 END), 0) as total_credit
                FROM journal_lines jl
                JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
                WHERE jl.account_id = ? 
                  AND jl.currency_id = ?
            ");
            $stmt_calc->execute([$account['id'], $currency_id]);
            $result = $stmt_calc->fetch(PDO::FETCH_ASSOC);

            $total_debit = $result['total_debit'];
            $total_credit = $result['total_credit'];

            // جلب سعر الصرف
            $stmt_rate = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE id = ?");
            $stmt_rate->execute([$currency_id]);
            $rate = $stmt_rate->fetchColumn() ?: 1;
            $currency_code = $curr['currency_code'];

            // حساب الرصيد الحالي
            $current_balance = $total_debit - $total_credit;
            $current_balance_base = $current_balance * $rate;

            // إدراج سجل الأرصدة الجديد
            $stmt_insert = $pdo->prepare("
                INSERT INTO account_balances_unified (
                    account_id, branch_id, currency_id, opening_balance, current_balance, current_balance_base,
                    currency_code, opening_balance_base, is_frozen
                ) VALUES (?, ?, ?, 0, ?, ?, ?, 0, 0)
            ");
            $stmt_insert->execute([
                $account['id'], null, $currency_id, $current_balance, $current_balance_base, $currency_code]);
            $total_processed++;

            // عرض للعميل 11201001 فقط
            if ($account['account_code'] === '11201001') {
                echo "<div class='info'>";
                echo "<strong>الحساب: {$account['account_code']} - {$account['account_name_ar']}</strong><br>";
                echo "العملة: {$curr['currency_name']} ({$currency_code})<br>";
                echo "إجمالي المدين: " . number_format($total_debit, 2) . "<br>";
                echo "إجمالي الدائن: " . number_format($total_credit, 2) . "<br>";
                echo "الرصيد الحالي: " . number_format($current_balance, 2) . "<br>";
                echo "</div>";
            }
        }
    }

    $pdo->commit();
    echo "<div class='success' style='font-size:1.2rem;'><strong>✅ إصلاح وتحديث جميع الأرصدة بنجاح! تمت معالجة $total_processed سجل!</strong></div>";
    echo "<p><a href='check_customer_11201001.php'>🔙 العودة لصفحة العميل</a></p>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div class='error'><strong>❌ خطأ:</strong> " . $e->getMessage() . "</div>";
    echo "<pre style='background: #ffeeee; padding: 15px; border-radius: 8px;'>" . $e->getTraceAsString() . "</pre>";
}
?>
