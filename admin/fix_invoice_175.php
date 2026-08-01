<?php
require_once '../includes/db.php';
require_once '../includes/ServiceFinancialEngine.php';
session_start();

echo "<style>body{font-family:Arial,sans-serif;padding:2rem;direction:rtl}</style>";
echo "<h1>إصلاح فاتورة #175 ومعاملة الجوازات المرتبطة</h1>";

try {
    $pdo->beginTransaction();

    // جلب المعاملة المرتبطة بالفاتورة
    $stmt_inv = $pdo->prepare("SELECT source_id FROM invoices WHERE id = 175");
    $stmt_inv->execute();
    $source_id = $stmt_inv->fetchColumn();
    
    if (!$source_id) {
        echo "<p style='color:red'>لم يتم العثور على المعاملة المرتبطة بالفاتورة!</p>";
        exit;
    }
    echo "<p>المعاملة المرتبطة: رقم $source_id</p>";

    // جلب بيانات المعاملة
    $stmt_trx = $pdo->prepare("SELECT * FROM passport_transactions WHERE id = ?");
    $stmt_trx->execute([$source_id]);
    $trx = $stmt_trx->fetch(PDO::FETCH_ASSOC);

    if (!$trx) {
        echo "<p style='color:red'>لم يتم العثور على المعاملة!</p>";
        exit;
    }

    // حذف الفواتير القديمة
    $stmt_old_invoices = $pdo->prepare("SELECT id FROM invoices WHERE source_type = 'passport_transaction' AND source_id = ?");
    $stmt_old_invoices->execute([$source_id]);
    $old_invoices = $stmt_old_invoices->fetchAll(PDO::FETCH_COLUMN);

    foreach ($old_invoices as $old_inv_id) {
        echo "<p>حذف الفاتورة القديمة #$old_inv_id...</p>";
        $pdo->prepare("DELETE FROM payment_allocations WHERE invoice_id = ?")->execute([$old_inv_id]);
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id IN (SELECT id FROM financial_transactions WHERE invoice_id = ?)")->execute([$old_inv_id]);
        $pdo->prepare("DELETE FROM journal_entries WHERE id IN (SELECT journal_entry_id FROM journal_lines WHERE invoice_id = ?)")->execute([$old_inv_id]);
        $pdo->prepare("DELETE FROM financial_transactions WHERE invoice_id = ?")->execute([$old_inv_id]);
        $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$old_inv_id]);
    }

    // جلب سعر التكلفة من نوع المعاملة
    $stmt_type = $pdo->prepare("SELECT default_cost FROM passport_transaction_types WHERE id = ?");
    $stmt_type->execute([$trx['transaction_type_id']]);
    $type = $stmt_type->fetch(PDO::FETCH_ASSOC);
    $purchase_price = $type['default_cost'] ?? 0;

    // إعادة إنشاء الفواتير باستخدام المحرك المالي
    $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['admin_id'] ?? 1);
    $financeResults = $financialEngine->processServiceFinance([
        'service_type'    => 'passport_transaction',
        'source_id'       => $source_id,
        'source_number'   => $trx['transaction_number'],
        'branch_id'       => $trx['branch_id'],
        'customer_id'     => $trx['customer_id'],
        'agent_id'        => $trx['agent_id'],
        'supplier_id'     => null, // لا نعرف؟ لكن يمكن تعديل لاحقاً
        'sale_price'      => $trx['total_amount'] ?? 0,
        'discount'        => 0,
        'purchase_price'  => $purchase_price,
        'sale_currency_id'=> 1, // افتراض عملة افتراضية
        'pur_currency_id' => 1,
        'exchange_rate'   => 1,
        'amount_received' => $trx['amount_received'] ?? 0,
        'payment_account_id' => null,
        'delivery_type'   => $trx['delivery_type'] ?? 'credit',
        'description'     => "معاملة جواز رقم: " . $trx['transaction_number'] . " للمسافر: " . $trx['full_name'],
        'operation_date'  => $trx['operation_date']
    ], true);

    // تحديث ربط الفواتير في المعاملة
    $pdo->prepare("UPDATE passport_transactions SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 WHERE id = ?")
        ->execute([
            $financeResults['sales_invoice_id'], 
            $financeResults['purchase_invoice_id'] ?? null, 
            $source_id
        ]);

    $pdo->commit();

    echo "<p style='color:green; font-size:1.2rem;'>✅ تم إصلاح الفواتير والقيود المالية بنجاح!</p>";
    echo "<p>فاتورة مبيعات جديدة: #" . $financeResults['sales_invoice_id'] . "</p>";
    if ($financeResults['purchase_invoice_id']) {
        echo "<p>فاتورة شراء جديدة: #" . $financeResults['purchase_invoice_id'] . "</p>";
    }

    echo "<p><a href='passport_transactions.php'>الرجوع لمعاملات الجوازات</a></p>";
    echo "<p><a href='invoice_details.php?id=" . $financeResults['sales_invoice_id'] . "'>عرض فاتورة المبيعات الجديدة</a></p>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<p style='color:red'>خطأ: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
