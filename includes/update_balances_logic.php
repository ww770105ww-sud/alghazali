<?php
/**
 * منطق بديل لتحديث الأرصدة بدلاً من الإجراء المخزن المفقود
 */
if (isset($this_pdo) && isset($this_trx_id)) {
    // First, get branch_id from financial_transactions or journal_lines
    $stmt_branch = $this_pdo->prepare("SELECT branch_id FROM financial_transactions WHERE id = ?");
    $stmt_branch->execute([$this_trx_id]);
    $branch_id = $stmt_branch->fetchColumn();

    $stmt_lines = $this_pdo->prepare("SELECT account_id, debit, credit, currency_id, branch_id FROM journal_lines WHERE financial_transaction_id = ?");
    $stmt_lines->execute([$this_trx_id]);
    $lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);

    foreach ($lines as $line) {
        $account_id = $line['account_id'];
        $currency_id = $line['currency_id'];
        $amount = $line['debit'] - $line['credit'];

        // جلب سعر الصرف للعملة الأساسية
        $stmt_curr = $this_pdo->prepare("SELECT exchange_rate, currency_code FROM currencies WHERE id = ?");
        $stmt_curr->execute([$currency_id]);
        $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
        $rate = (float)($curr['exchange_rate'] ?? 1);
        $currency_code = $curr['currency_code'] ?? '';
        $amount_base = $amount * $rate;

        // تفعيل العملة أصبح على مستوى الحساب + العملة لجميع الفروع.
        $stmt_check = $this_pdo->prepare("
            SELECT id FROM account_balances_unified 
            WHERE account_id = ? AND currency_id = ?
            LIMIT 1
        ");
        $stmt_check->execute([$account_id, $currency_id]);
        $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            // Update existing row
            $stmt_upd = $this_pdo->prepare("
                UPDATE account_balances_unified 
                SET 
                    current_balance = current_balance + ?, 
                    current_balance_base = current_balance_base + ?,
                    currency_code = ?
                WHERE id = ?
            ");
            $stmt_upd->execute([$amount, $amount_base, $currency_code, $exists['id']]);
        } else {
            // Insert new row with all required columns
            $stmt_ins = $this_pdo->prepare("
                INSERT INTO account_balances_unified (
                    account_id, branch_id, currency_id, currency_code,
                    opening_balance, current_balance, current_balance_base,
                    opening_balance_base, credit_limit, debit_limit, is_frozen
                ) VALUES (?, ?, ?, ?, 0, ?, ?, 0, 0, 0, 0)
            ");
            $stmt_ins->execute([$account_id, null, $currency_id, $currency_code, $amount, $amount_base]);
        }
    }
}
?>
