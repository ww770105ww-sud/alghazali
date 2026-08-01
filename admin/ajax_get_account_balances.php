<?php
require_once '../includes/db.php';

if (isset($_GET['account_id'])) {
    $account_id = intval($_GET['account_id']);
    
    try {
        // أولاً نحضر جميع الأرصدة المسجلة في account_balances_unified
        $stmt_saved_balances = $pdo->prepare("
            SELECT 
                abu.id,
                abu.currency_id,
                abu.branch_id,
                abu.opening_balance,
                abu.opening_balance_base,
                abu.is_frozen,
                abu.credit_limit,
                abu.debit_limit,
                c.currency_name, 
                c.currency_symbol, 
                c.currency_code, 
                c.is_default,
                c.exchange_rate, 
                c.exchange_rate_sell,
                c.exchange_rate_buy,
                ua.normal_balance,
                ua.credit_limit_base,
                ua.debit_limit_base
            FROM account_balances_unified abu
            LEFT JOIN currencies c ON abu.currency_id = c.id
            LEFT JOIN unified_accounts ua ON abu.account_id = ua.id
            WHERE abu.account_id = ?
        ");
        $stmt_saved_balances->execute([$account_id]);
        $saved_balances = $stmt_saved_balances->fetchAll(PDO::FETCH_ASSOC);
        
        // نحضر الرصيد المحسوب من journal_lines لكل عملة
        $stmt_calc_balances = $pdo->prepare("
            SELECT 
                jl.currency_id,
                SUM(jl.debit - jl.credit) AS calculated_current_balance,
                SUM((jl.debit - jl.credit) * COALESCE(c.exchange_rate, 1)) AS calculated_current_balance_base
            FROM journal_lines jl
            JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
            JOIN currencies c ON jl.currency_id = c.id 
            WHERE jl.account_id = ? AND ft.status = 'posted'
            GROUP BY jl.currency_id
        ");
        $stmt_calc_balances->execute([$account_id]);
        $calc_balances_raw = $stmt_calc_balances->fetchAll(PDO::FETCH_ASSOC);
        
        // نحول الرصيد المحسوب إلى مصفوفة بناءً على currency_id للوصول السريع
        $calc_balances = [];
        foreach ($calc_balances_raw as $cb) {
            $calc_balances[$cb['currency_id']] = $cb;
        }
        
        $saved_balances_by_currency = [];
        foreach ($saved_balances as $sb) {
            $currency_id = (int)($sb['currency_id'] ?? 0);
            if ($currency_id <= 0) {
                continue;
            }

            if (!isset($saved_balances_by_currency[$currency_id])) {
                $saved_balances_by_currency[$currency_id] = [
                    'id' => $sb['id'],
                    'currency_id' => $currency_id,
                    'branch_id' => null,
                    'branch_count' => 0,
                    'currency_name' => $sb['currency_name'],
                    'currency_symbol' => $sb['currency_symbol'],
                    'currency_code' => $sb['currency_code'],
                    'is_default' => $sb['is_default'],
                    'exchange_rate' => $sb['exchange_rate'],
                    'exchange_rate_sell' => $sb['exchange_rate_sell'],
                    'exchange_rate_buy' => $sb['exchange_rate_buy'],
                    'normal_balance' => $sb['normal_balance'],
                    'credit_limit_base' => $sb['credit_limit_base'],
                    'debit_limit_base' => $sb['debit_limit_base'],
                    'opening_balance' => 0,
                    'opening_balance_base' => 0,
                    'current_balance' => 0,
                    'current_balance_base' => 0,
                    'debit_limit' => 0,
                    'credit_limit' => 0,
                    'is_frozen' => 0
                ];
            }

            $saved_balances_by_currency[$currency_id]['branch_count']++;
            $saved_balances_by_currency[$currency_id]['opening_balance'] += (float)($sb['opening_balance'] ?? 0);
            $saved_balances_by_currency[$currency_id]['opening_balance_base'] += (float)($sb['opening_balance_base'] ?? 0);
            $saved_balances_by_currency[$currency_id]['debit_limit'] += (float)($sb['debit_limit'] ?? 0);
            $saved_balances_by_currency[$currency_id]['credit_limit'] += (float)($sb['credit_limit'] ?? 0);
            $saved_balances_by_currency[$currency_id]['is_frozen'] = max(
                (int)$saved_balances_by_currency[$currency_id]['is_frozen'],
                (int)($sb['is_frozen'] ?? 0)
            );
        }

        $final_balances = [];
        foreach ($saved_balances_by_currency as $currency_id => $sb) {
            $cb = $calc_balances[$currency_id] ?? null;
            $sb['current_balance'] = $cb ? (float)$cb['calculated_current_balance'] : 0;
            $sb['current_balance_base'] = $cb ? (float)$cb['calculated_current_balance_base'] : 0;
            $final_balances[] = $sb;

            unset($calc_balances[$currency_id]);
        }
        
        // نضيف العملات المتبقية من calc_balances التي لم تكن في account_balances_unified
        foreach ($calc_balances_raw as $cb) {
            $currency_id = (int)($cb['currency_id'] ?? 0);
            if (!isset($saved_balances_by_currency[$currency_id])) {
                // نحضر معلومات العملة والحساب
                $stmt_curr_acc = $pdo->prepare("
                    SELECT 
                        c.currency_name, 
                        c.currency_symbol, 
                        c.currency_code, 
                        c.is_default,
                        c.exchange_rate, 
                        c.exchange_rate_sell,
                        c.exchange_rate_buy,
                        ua.normal_balance,
                        ua.credit_limit_base,
                        ua.debit_limit_base
                    FROM currencies c
                    CROSS JOIN unified_accounts ua
                    WHERE c.id = ? AND ua.id = ?
                ");
                $stmt_curr_acc->execute([$currency_id, $account_id]);
                $curr_acc = $stmt_curr_acc->fetch(PDO::FETCH_ASSOC);
                
                if ($curr_acc) {
                    $final_balances[] = [
                        'id' => null,
                        'currency_id' => $currency_id,
                        'branch_id' => null,
                        'branch_count' => 0,
                        'currency_name' => $curr_acc['currency_name'],
                        'currency_symbol' => $curr_acc['currency_symbol'],
                        'currency_code' => $curr_acc['currency_code'],
                        'is_default' => $curr_acc['is_default'],
                        'exchange_rate' => $curr_acc['exchange_rate'],
                        'exchange_rate_sell' => $curr_acc['exchange_rate_sell'],
                        'exchange_rate_buy' => $curr_acc['exchange_rate_buy'],
                        'normal_balance' => $curr_acc['normal_balance'],
                        'credit_limit_base' => $curr_acc['credit_limit_base'],
                        'debit_limit_base' => $curr_acc['debit_limit_base'],
                        'opening_balance' => 0,
                        'opening_balance_base' => 0,
                        'current_balance' => (float)$cb['calculated_current_balance'],
                        'current_balance_base' => (float)$cb['calculated_current_balance_base'],
                        'debit_limit' => 0,
                        'credit_limit' => 0,
                        'is_frozen' => 0
                    ];
                }
            }
        }
        
        // إذا لم يكن هناك شيء على الإطلاق، نضيف الحساب فقط
        if (empty($final_balances)) {
            $stmt_account = $pdo->prepare("
                SELECT 
                    ua.normal_balance,
                    ua.credit_limit_base,
                    ua.debit_limit_base
                FROM unified_accounts ua 
                WHERE ua.id = ?
            ");
            $stmt_account->execute([$account_id]);
            $account = $stmt_account->fetch(PDO::FETCH_ASSOC);
            if ($account) {
                $final_balances[] = [
                    'id' => null,
                    'currency_id' => null,
                    'branch_id' => null,
                    'branch_count' => 0,
                    'currency_name' => null,
                    'currency_symbol' => null,
                    'currency_code' => null,
                    'is_default' => null,
                    'exchange_rate' => null,
                    'exchange_rate_sell' => null,
                    'exchange_rate_buy' => null,
                    'normal_balance' => $account['normal_balance'],
                    'credit_limit_base' => $account['credit_limit_base'],
                    'debit_limit_base' => $account['debit_limit_base'],
                    'current_balance' => 0,
                    'current_balance_base' => 0,
                    'opening_balance' => 0,
                    'opening_balance_base' => 0,
                    'debit_limit' => 0,
                    'credit_limit' => 0,
                    'is_frozen' => 0
                ];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($final_balances);
    } catch (Exception $e) {
        http_response_code(500);
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['error' => 'حدث خطأ أثناء جلب أرصدة الحساب']);
    }
}
