<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Find db.php path
$dbPath = __DIR__ . '/../includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = __DIR__ . '/includes/db.php';
}
if (file_exists($dbPath)) {
    require_once $dbPath;
} else {
    die('db.php not found');
}

echo '<h1>تفاصيل الفاتورة</h1>';

// Step 0: List all tables in database
echo '<div style="background: #f0f0f0; padding: 15px; margin-bottom: 20px;">';
echo '<h2>0. جميع الجداول في قاعدة البيانات</h2>';
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo '<ul style="columns: 3;">';
    foreach ($tables as $table) {
        echo '<li>' . htmlspecialchars($table) . '</li>';
    }
    echo '</ul>';
} catch (PDOException $e) {
    echo '<p>خطأ: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '</div>';

// Step 1: Show all columns in invoices table
echo '<hr><h2>1. أعمدة جدول invoices</h2>';
try {
    $stmt = $pdo->query("DESCRIBE invoices");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo '<ul>';
    foreach ($columns as $col) {
        echo '<li><strong>' . htmlspecialchars($col['Field']) . '</strong> - ' . htmlspecialchars($col['Type']) . '</li>';
    }
    echo '</ul>';
} catch (PDOException $e) {
    echo '<p>خطأ: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// Step 2: List all invoices
echo '<hr><h2>1. جميع الفواتير</h2>';
$stmt = $pdo->query("SELECT * FROM invoices ORDER BY created_at DESC LIMIT 50");
$allInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($allInvoices) {
    echo '<ul>';
    foreach ($allInvoices as $inv) {
        $invNumber = $inv['invoice_number'] ?? $inv['id'];
        $invDate = $inv['created_at'] ?? '';
        echo '<li>#' . htmlspecialchars($inv['id']) . ': <strong>' . htmlspecialchars($invNumber) . '</strong> - ' . htmlspecialchars($invDate) . '</li>';
    }
    echo '</ul>';
} else {
    echo '<p>لا توجد فواتير!</p>';
}

$searchNumber = $_GET['invoice'] ?? 'PI-000018'; // Default to PI-000018 since that's the last purchase invoice
echo '<hr><h2>البحث عن ' . htmlspecialchars($searchNumber) . '</h2>';

// Add a search form
echo '<form method="get" style="margin-bottom:20px;">
    <label>ابحث برقم الفاتورة: </label>
    <input type="text" name="invoice" value="' . htmlspecialchars($searchNumber) . '" style="padding:5px;">
    <button type="submit" style="padding:5px 10px;">بحث</button>
</form>';

// Step 3: Get main invoice
echo '<h3>الفاتورة الرئيسية</h3>';
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ?");
$stmt->execute([$searchNumber]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if ($invoice) {
    echo '<pre>';
    print_r($invoice);
    echo '</pre>';
    
    $invoiceId = $invoice['id'];
    
    // Step 4: Get linked source (passport transaction, etc.)
    echo '<h3>المصدر المرتبط بالفاتورة</h3>';
    if (!empty($invoice['source_type']) && !empty($invoice['source_id'])) {
        echo '<p><strong>نوع المصدر:</strong> ' . htmlspecialchars($invoice['source_type']) . '</p>';
        echo '<p><strong>رقم المصدر:</strong> ' . htmlspecialchars($invoice['source_id']) . '</p>';
        
        // Try to get source details
        try {
            if ($invoice['source_type'] == 'umrah' || $invoice['source_type'] == 'Passport' || $invoice['source_type'] == 'FamilyVisit' || $invoice['source_type'] == 'passport_transaction') {
                $stmt = $pdo->prepare("SELECT * FROM passport_transactions WHERE id = ?");
                $stmt->execute([$invoice['source_id']]);
                $source = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($source) {
                    echo '<h4>تفاصيل معاملة الجوازات:</h4>';
                    echo '<pre>';
                    print_r($source);
                    echo '</pre>';
                }
            }
        } catch (PDOException $e) {
            echo '<p>خطأ في جلب المصدر: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
    
    // Step 5: Get financial transactions
    echo '<h3>المعاملات المالية المرتبطة</h3>';
    try {
        $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE reference_id = ? AND reference_type = 'invoice'");
        $stmt->execute([$invoiceId]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<pre>';
        print_r($transactions);
        echo '</pre>';
        
        // Step 6: Get journal lines for these transactions
        if (!empty($transactions)) {
            echo '<h3>بنود القيود المحاسبية</h3>';
            $transactionIds = array_column($transactions, 'id');
            $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
            $stmt = $pdo->prepare("SELECT jl.*, ua.account_code, ua.account_name_ar FROM journal_lines jl JOIN unified_accounts ua ON jl.account_id = ua.id WHERE jl.financial_transaction_id IN ($placeholders)");
            $stmt->execute($transactionIds);
            $journalLines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo '<pre>';
            print_r($journalLines);
            echo '</pre>';
        }
    } catch (PDOException $e) {
        echo '<p>خطأ في جلب المعاملات: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
} else {
    echo '<p>لم يتم العثور على الفاتورة بهذا الرقم! ربما الرقم مختلف، تحقق من القائمة أعلاه!</p>';
}

echo '<br><a href="financial_accounts.php">الرجوع إلى الصفحة السابقة</a>';
