<?php
/**
 * Database Security & Financial Integrity Audit
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
$host = 'localhost';
$dbname = 'ghazali';
$username = 'root';
$password = '738155';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

echo "<h1>🔒 Ghazali Database Security & Financial Integrity Audit</h1>";
echo "<p><strong>Audit Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<hr>";

// ========================================================
// 1. List all tables
// ========================================================
echo "<h2>📊 Step 1: List all Tables</h2>";
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "<ul>";
foreach ($tables as $table) {
    echo "<li>$table</li>";
}
echo "</ul>";

// ========================================================
// 2. Check balance tables
// ========================================================
echo "<hr><h2>💰 Step 2: Identify Balance/Account Tables</h2>";
$balanceTables = [];
$relevantKeywords = ['balance', 'account', 'wallet', 'ledger', 'journal', 'transaction', 'invoice', 'payment', 'receipt', 'audit'];

foreach ($tables as $table) {
    foreach ($relevantKeywords as $keyword) {
        if (stripos($table, $keyword) !== false) {
            if (!in_array($table, $balanceTables)) $balanceTables[] = $table;
        }
    }
}

echo "<h3>Relevant Financial Tables:</h3><ul>";
foreach ($balanceTables as $table) {
    echo "<li><strong>$table</strong>";
    
    // Show structure
    $describeStmt = $pdo->query("DESCRIBE `$table`");
    $columns = $describeStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<br><small>Columns: ";
    $colNames = [];
    foreach ($columns as $col) {
        $colNames[] = $col['Field'];
    }
    echo implode(', ', $colNames);
    echo "</small></li>";
}
echo "</ul>";

// ========================================================
// 3. Check Foreign Keys
// ========================================================
echo "<hr><h2>🔗 Step 3: Check Foreign Keys</h2>";
$fkeys = $pdo->query("
    SELECT
        TABLE_NAME,
        COLUMN_NAME,
        CONSTRAINT_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = '$dbname'
      AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Found Foreign Keys:</h3>";
if (empty($fkeys)) {
    echo "<p style='color:red'><strong>⚠️ No Foreign Keys Found!</strong> This is a security risk!</p>";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>
        <tr style='background:#eee'>
            <th>Table</th><th>Column</th><th>Constraint</th><th>References</th><th>Ref Column</th>
        </tr>";
    foreach ($fkeys as $fk) {
        echo "<tr>
            <td>{$fk['TABLE_NAME']}</td>
            <td>{$fk['COLUMN_NAME']}</td>
            <td>{$fk['CONSTRAINT_NAME']}</td>
            <td>{$fk['REFERENCED_TABLE_NAME']}</td>
            <td>{$fk['REFERENCED_COLUMN_NAME']}</td>
        </tr>";
    }
    echo "</table>";
}

// ========================================================
// 4. Check Triggers
// ========================================================
echo "<hr><h2>⚡ Step 4: Check Triggers</h2>";
$triggers = $pdo->query("SHOW TRIGGERS FROM `$dbname`")->fetchAll(PDO::FETCH_ASSOC);

if (empty($triggers)) {
    echo "<p style='color:orange'><strong>⚠️ No Triggers Found!</strong> Balances may be updated manually!</p>";
} else {
    echo "<h3>Found Triggers:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>
        <tr style='background:#eee'>
            <th>Trigger</th><th>Table</th><th>Event</th><th>Timing</th><th>Statement</th>
        </tr>";
    foreach ($triggers as $t) {
        echo "<tr>
            <td>{$t['Trigger']}</td>
            <td>{$t['Table']}</td>
            <td>{$t['Event']}</td>
            <td>{$t['Timing']}</td>
            <td><small>" . substr($t['Statement'],0,500) . "...</small></td>
        </tr>";
    }
    echo "</table>";
}

// ========================================================
// 5. Check Stored Procedures
// ========================================================
echo "<hr><h2>📦 Step 5: Check Stored Procedures/Functions</h2>";
$procs = $pdo->query("
    SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION 
    FROM information_schema.ROUTINES 
    WHERE ROUTINE_SCHEMA = '$dbname'
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($procs)) {
    echo "<p style='color:orange'><strong>⚠️ No Stored Procedures Found!</strong></p>";
} else {
    echo "<h3>Found Routines:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>
        <tr style='background:#eee'>
            <th>Name</th><th>Type</th><th>Definition</th>
        </tr>";
    foreach ($procs as $proc) {
        echo "<tr>
            <td>{$proc['ROUTINE_NAME']}</td>
            <td>{$proc['ROUTINE_TYPE']}</td>
            <td><small>" . htmlspecialchars(substr($proc['ROUTINE_DEFINITION'],0,1000)) . "...</small></td>
        </tr>";
    }
    echo "</table>";
}

// ========================================================
// 6. Check Audit/Log Tables
// ========================================================
echo "<hr><h2>📝 Step 6: Check for Audit/Log Tables</h2>";
$auditTables = [];
foreach ($tables as $t) {
    if (stripos($t, 'audit') !== false || stripos($t, 'log') !== false || stripos($t, 'history') !== false) {
        $auditTables[] = $t;
    }
}

if (empty($auditTables)) {
    echo "<p style='color:red'><strong>⚠️ No Audit/Log Tables Found! Critical security risk!</strong></p>";
} else {
    echo "<h3>Found Audit Log Tables:</h3><ul>";
    foreach ($auditTables as $t) {
        echo "<li>$t</li>";
        // Check row count
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM `$t`")->fetch(PDO::FETCH_ASSOC)['cnt'];
        echo "<small>Row count: $count</small><br>";
    }
    echo "</ul>";
}

// ========================================================
// 7. Verify Balance Integrity (account_balances_unified vs journal_lines)
// ========================================================
echo "<hr><h2>⚖️ Step 7: Verify Balance Integrity</h2>";
if (in_array('account_balances_unified', $tables) && in_array('journal_lines', $tables) && in_array('financial_transactions', $tables)) {
    echo "<h3>Checking if account_balances_unified matches journal_lines totals...</h3>";
    
    echo "<h4>First 10 accounts from account_balances_unified:</h4>";
    $balances = $pdo->query("
        SELECT 
            abu.account_id,
            ua.account_name_ar,
            ua.account_code,
            abu.currency_id,
            c.currency_name,
            abu.current_balance as current_balance_from_table
        FROM account_balances_unified abu
        LEFT JOIN unified_accounts ua ON abu.account_id = ua.id
        LEFT JOIN currencies c ON abu.currency_id = c.id
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>
        <tr style='background:#eee'>
            <th>Account ID</th><th>Code</th><th>Name</th><th>Currency</th><th>Balance from table</th><th>Calculated from journal</th><th>Match?</th>
        </tr>";

    $totalMismatches = 0;
    foreach ($balances as $bal) {
        // Calculate balance from journal_lines
        $calcStmt = $pdo->prepare("
            SELECT 
                CASE COALESCE(ua.normal_balance, 'debit')
                    WHEN 'credit' THEN (SUM(COALESCE(jl.credit,0)) - SUM(COALESCE(jl.debit,0)))
                    ELSE (SUM(COALESCE(jl.debit,0)) - SUM(COALESCE(jl.credit,0)))
                END as calculated_balance
            FROM journal_lines jl
            INNER JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
            LEFT JOIN unified_accounts ua ON jl.account_id = ua.id
            WHERE jl.account_id = ?
              AND jl.currency_id = ?
              AND ft.status = 'posted'
        ");
        $calcStmt->execute([$bal['account_id'], $bal['currency_id']]);
        $calcBal = $calcStmt->fetch(PDO::FETCH_ASSOC)['calculated_balance'] ?? 0;
        
        $match = (abs((float)$bal['current_balance_from_table'] - (float)$calcBal) < 0.01) ? "<span style='color:green'>✅ Yes</span>" : "<span style='color:red'>❌ NO!</span>";
        if ($match !== "<span style='color:green'>✅ Yes</span>") $totalMismatches++;

        echo "<tr>
            <td>{$bal['account_id']}</td>
            <td>{$bal['account_code']}</td>
            <td>{$bal['account_name_ar']}</td>
            <td>{$bal['currency_name']}</td>
            <td>" . number_format($bal['current_balance_from_table'],2) . "</td>
            <td>" . number_format($calcBal,2) . "</td>
            <td>$match</td>
        </tr>";
    }
    echo "</table>";

    if ($totalMismatches > 0) {
        echo "<p style='color:red'><strong>⚠️ Found $totalMismatches mismatches! Balances may have been modified manually!</strong></p>";
    }
} else {
    echo "<p style='color:orange'><strong>⚠️ Could not find required tables (account_balances_unified, journal_lines, financial_transactions)!</strong></p>";
}

// ========================================================
// 8. Summary & Recommendations
// ========================================================
echo "<hr><h2>✅ Audit Summary & Recommendations</h2>";
echo "<h3>🟢 Overall Status:</h3>";
if (empty($auditTables) && empty($triggers)) {
    echo "<p style='color:red'><strong>⚠️ SYSTEM IS NOT FULLY SECURED!</strong></p>";
} else {
    echo "<p style='color:green'><strong>✅ System has some security features!</strong></p>";
}

echo "<h3>🛠️ Recommendations:</h3>";
echo "<ol>
    <li><strong>Never allow direct UPDATE/DELETE on account_balances_unified!</strong> Use only stored procedures or code that updates via journal entries.</li>
    <li>Add <strong>Audit Triggers</strong> on all financial tables to log <em>every</em> change!</li>
    <li>Use <strong>Transactions</strong> for all financial operations to ensure atomicity!</li>
    <li>Add <strong>Foreign Keys</strong> between journal_lines ↔ financial_transactions ↔ invoices, etc., to enforce referential integrity!</li>
    <li>Restrict database user permissions! Never use 'root' in production!</li>
</ol>";

echo "<hr><h2>🔚 Audit Complete!</h2>";
?>