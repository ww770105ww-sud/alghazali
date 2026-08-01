<?php
require_once '../includes/db.php';

echo "<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f7; padding: 20px; direction: rtl; }
    h1, h3 { color: #007aff; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    th { background: #007aff; color: white; padding: 12px; text-align: right; }
    td { padding: 10px; border-bottom: 1px solid #eee; text-align: right; }
    tr:hover { background: #f9f9f9; }
    pre { background: #eee; padding: 15px; border-radius: 8px; }
    .warning { background: #fff3cd; padding: 15px; border-radius: 8px; margin: 10px 0; }
    .info { background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 10px 0; }
</style>";

echo "<h1>💳 تحقق من رصيد الحساب 11201001 - العميل أحمد علي</h1>";

try {
    // Step 1: Get the account info
    $stmt_account = $pdo->prepare("SELECT * FROM unified_accounts WHERE account_code = ?");
    $stmt_account->execute(['11201001']);
    $account = $stmt_account->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo "<p style='color:red; font-size: 1.2rem;'>⚠️ الحساب غير موجود!</p>";
        exit;
    }

    echo "<h3>📋 معلومات الحساب:</h3>";
    echo "<table>";
    foreach ($account as $key => $value) {
        echo "<tr><th>" . htmlspecialchars($key) . "</th><td>" . htmlspecialchars($value) . "</td></tr>";
    }
    echo "</table>";

    $account_id = $account['id'];

    // Step 2: Get the account balances with branch info
    echo "<h3>💰 أرصدة الحساب (مع معلومات الفرع):</h3>";
    $stmt_balances = $pdo->prepare("
        SELECT abu.*, b.branch_name, c.currency_name, c.currency_code 
        FROM account_balances_unified abu 
        LEFT JOIN branches b ON abu.branch_id = b.id 
        LEFT JOIN currencies c ON abu.currency_id = c.id 
        WHERE abu.account_id = ?
    ");
    $stmt_balances->execute([$account_id]);
    $balances = $stmt_balances->fetchAll(PDO::FETCH_ASSOC);

    if (empty($balances)) {
        echo "<p style='color: #ff9500;'>❌ لا توجد أرصدة مسجلة لهذا الحساب!</p>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>الفرع</th><th>العملة</th><th>الرصيد الافتتاحي</th><th>الرصيد الحالي</th><th>الرصيد بالعملة الأساسية</th><th>الحالة</th></tr>";
        foreach ($balances as $b) {
            echo "<tr>";
            echo "<td>{$b['id']}</td>";
            echo "<td>" . ($b['branch_name'] ?? 'بدون فرع') . "</td>";
            echo "<td>{$b['currency_name']} ({$b['currency_code']})</td>";
            echo "<td>" . number_format($b['opening_balance'], 2) . "</td>";
            $balance_class = ($b['current_balance'] < 0) ? "color: #ff3b30;" : "color: #007aff;";
            echo "<td style='font-weight: bold; {$balance_class}'>" . number_format($b['current_balance'], 2) . "</td>";
            echo "<td>" . number_format($b['current_balance_base'], 2) . "</td>";
            echo "<td>" . ($b['is_frozen'] == 1 ? '<span style="color:red;">مجمد</span>' : '<span style="color:green;">نشط</span>') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Step3: Get all journal lines for this account with more details
    echo "<h3>📊 خطوط الدفتر اليومي (journal_lines) مع تفاصيل كاملة:</h3>";
    $stmt_journal = $pdo->prepare("
        SELECT 
            jl.*, 
            ft.*,
            c.currency_code
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        LEFT JOIN currencies c ON jl.currency_id = c.id
        WHERE jl.account_id = ?
        ORDER BY jl.id
    ");
    $stmt_journal->execute([$account_id]);
    $journal_lines = $stmt_journal->fetchAll(PDO::FETCH_ASSOC);

    if (empty($journal_lines)) {
        echo "<p style='color: #8e8e93;'>ℹ️ لا توجد خطوط دفتر يومي لهذا الحساب!</p>";
    } else {
        echo "<table>";
        echo "<tr>
                <th>ID الخط</th>
                <th>رقم المعاملة</th>
                <th>المرجع</th>
                <th>نوع المعاملة</th>
                <th>تاريخ المعاملة</th>
                <th>حالة المعاملة</th>
                <th>مدين</th>
                <th>دائن</th>
                <th>العملة</th>
                <th>الفرع</th>
            </tr>";
        $total_debit = 0;
        $total_credit = 0;
        foreach ($journal_lines as $jl) {
            $total_debit += $jl['debit'];
            $total_credit += $jl['credit'];

            echo "<tr>";
            echo "<td>{$jl['id']}</td>";
            echo "<td>" . htmlspecialchars($jl['transaction_number']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['reference_number']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['transaction_type'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($jl['transaction_date'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($jl['status']) . "</td>";
            echo "<td style='text-align:right;'>" . number_format($jl['debit'],2) . "</td>";
            echo "<td style='text-align:right;'>" . number_format($jl['credit'],2) . "</td>";
            echo "<td>" . htmlspecialchars($jl['currency_code']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['branch_id'] ?? 'بدون') . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        echo "<div style='background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 15px;'>";
        echo "<h4>📈 ملخص المعاملات:</h4>";
        echo "<p style='font-size:1.1rem;'><strong>إجمالي المدين:</strong>: " . number_format($total_debit,2) . "</p>";
        echo "<p style='font-size:1.1rem;'><strong>إجمالي الدائن:</strong>: " . number_format($total_credit,2) . "</p>";
        $net_balance = $total_debit - $total_credit;
        echo "<p style='font-size:1.1rem; color: " . ($net_balance > 0 ? "#007aff" : ($net_balance < 0 ? "#ff3b30" : "#8e8e93")) . ";'><strong>الفرق:</strong> " . number_format($net_balance,2) . "</p>";
        echo "</div>";
    }
    
    // Step 4: Check for missing credit entries or unposted transactions
    echo "<h3>🔍 تحليل مصدر الرصيد:</h3>";
    echo "<div class='info'>";
    echo "<strong>✅ تم إصلاح كود حذف الفواتير!</strong><br>";
    echo "الآن عند حذف أي فاتورة، سيتم حذف كل ما يلي تلقائياً:<br>";
    echo "1. تخصيصات المدفوعات<br>";
    echo "2. خطوط الدفتر اليومي<br>";
    echo "3. المعاملات المالية<br>";
    echo "4. الأرصدة سيتم تحديثها تلقائياً<br>";
    echo "</div>";
    
    echo "<div class='info'>";
    echo "<strong>💡 لإصلاح الأرصدة الحالية:</strong><br>";
    echo "- استخدم الأداة أدناه لإعادة حساب جميع الأرصدة من الصفر!<br>";
    echo "- أو استخدم أداة حذف الفواتير المتاحة هنا <a href='check_invoices_11201001.php' target='_blank'>check_invoices_11201001.php</a><br>";
    echo "</div>";
    
    // Show the recalculate tool link
    echo "<p><a href='tools/fix_all_account_balances.php' target='_blank' style='background: #ff9500; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; margin-right:10px;'>🔄 تشغيل إعادة حساب جميع الأرصدة من الصفر</a></p>";
    echo "<p><a href='check_invoices_11201001.php' target='_blank' style='background: #007aff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px;'>🗑️ حذف الفواتير القديمة وكل ما يتعلق بها</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red; font-size: 1.1rem;'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #ffeeee; padding: 15px; border-radius: 8px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
