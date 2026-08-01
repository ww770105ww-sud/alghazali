<?php
require 'includes/db.php';

$invoice_id = 329;

$stmt_inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt_inv->execute([$invoice_id]);
$invoice = $stmt_inv->fetch();

echo "<h3>Invoice #$invoice_id Details:</h3>";
echo "<pre>";
print_r($invoice);
echo "</pre>";

echo "<h3>System Settings:</h3>";
$stmt_set = $pdo->query("SELECT * FROM system_settings");
$settings = [];
while($s = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
    $settings[$s['setting_key']] = $s['setting_value'];
}
echo "<pre>";
print_r($settings);
echo "</pre>";

echo "<h3>Journal Entries for this invoice:</h3>";
$stmt_journal = $pdo->prepare("
    SELECT jl.*, ua.account_code, ua.account_name_ar FROM journal_lines jl 
    JOIN unified_accounts ua ON jl.account_id = ua.id
    JOIN financial_transactions ft ON ft.id = jl.financial_transaction_id
    WHERE ft.reference_type = 'invoice' AND ft.reference_id = ?
");
$stmt_journal->execute([$invoice_id]);
$journal = $stmt_journal->fetchAll();
echo "<pre>";
print_r($journal);
echo "</pre>";

echo "<h3>Service Config for source type: " . $invoice['source_type'] . "</h3>";
require 'includes/functions.php';
$config = getServiceInvoiceConfig($invoice['source_type'], $settings);
echo "<pre>";
print_r($config);
echo "</pre>";
?>