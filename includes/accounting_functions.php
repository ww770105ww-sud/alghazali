<?php
require_once 'session_config.php';
require_once 'CurrencyExchange.php';
require_once __DIR__ . '/functions.php';

// Initialize currency exchange when needed
function get_currency_exchange($pdo) {
    static $currencyExchange = null;
    if ($currencyExchange === null) {
        $currencyExchange = new CurrencyExchange($pdo);
    }
    return $currencyExchange;
}

function get_base_currency($pdo) {
    static $baseCurrency = null;
    static $base_currency_id = null;
    if ($baseCurrency === null) {
        $currencyExchange = get_currency_exchange($pdo);
        $baseCurrency = $currencyExchange->getBaseCurrency();
        $base_currency_id = $baseCurrency['id'] ?? null;
    }
    return ['currency' => $baseCurrency, 'id' => $base_currency_id];
}

/**
 * النظام المحاسبي الموحد - وكالة الغزالي للسفريات والسياحة
 * النسخة النهائية الموحدة المتوافقة مع الإجراءات المخزنة الجديدة
 */

function balances_triggers_enabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND TRIGGER_NAME IN ('trg_journal_lines_after_insert', 'trg_journal_lines_after_update', 'trg_journal_lines_after_delete')
        ");
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function apply_transaction_balances(PDO $pdo, int $financialTransactionId, int $direction = 1): void
{
    $financialTransactionId = (int)$financialTransactionId;
    if ($financialTransactionId < 1) {
        return;
    }
    $direction = $direction >= 0 ? 1 : -1;

    // First get the branch_id from financial_transactions
    $stmt_branch = $pdo->prepare("SELECT COALESCE(branch_id, 1) as branch_id FROM financial_transactions WHERE id = ?");
    $stmt_branch->execute([$financialTransactionId]);
    $branch_id = $stmt_branch->fetchColumn() ?: 1;

    $stmt = $pdo->prepare("
        SELECT
            jl.account_id,
            jl.currency_id,
            CASE 
                WHEN COALESCE(ua.normal_balance, 'debit') = 'credit' 
                THEN SUM(COALESCE(jl.credit, 0) - COALESCE(jl.debit, 0)) 
                ELSE SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)) 
            END AS delta_amount,
            COALESCE(MAX(c.exchange_rate), 1.0) AS exchange_rate
        FROM journal_lines jl
        INNER JOIN financial_transactions ft ON ft.id = jl.financial_transaction_id
        LEFT JOIN currencies c ON c.id = jl.currency_id
        LEFT JOIN unified_accounts ua ON ua.id = jl.account_id
        WHERE jl.financial_transaction_id = ?
        GROUP BY jl.account_id, jl.currency_id
    ");
    $stmt->execute([$financialTransactionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) {
        return;
    }

    $sqlWithLastUpdated = "
        INSERT INTO account_balances_unified (
            account_id, branch_id, currency_id,
            opening_balance, current_balance, current_balance_base,
            last_updated
        ) VALUES (?, ?, ?, 0, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            current_balance = current_balance + VALUES(current_balance),
            current_balance_base = current_balance_base + VALUES(current_balance_base),
            last_updated = NOW()
    ";
    $sqlWithoutLastUpdated = "
        INSERT INTO account_balances_unified (
            account_id, branch_id, currency_id,
            opening_balance, current_balance, current_balance_base
        ) VALUES (?, ?, ?, 0, ?, ?)
        ON DUPLICATE KEY UPDATE
            current_balance = current_balance + VALUES(current_balance),
            current_balance_base = current_balance_base + VALUES(current_balance_base)
    ";

    try {
        $ins = $pdo->prepare($sqlWithLastUpdated);
        foreach ($rows as $r) {
            $delta = (float)($r['delta_amount'] ?? 0) * $direction;
            $rate = (float)($r['exchange_rate'] ?? 1.0);
            $deltaBase = $delta * $rate;
            $ins->execute([(int)$r['account_id'], $branch_id, (int)$r['currency_id'], $delta, $deltaBase]);
        }
    } catch (Throwable $e) {
        $ins = $pdo->prepare($sqlWithoutLastUpdated);
        foreach ($rows as $r) {
            $delta = (float)($r['delta_amount'] ?? 0) * $direction;
            $rate = (float)($r['exchange_rate'] ?? 1.0);
            $deltaBase = $delta * $rate;
            $ins->execute([(int)$r['account_id'], $branch_id, (int)$r['currency_id'], $delta, $deltaBase]);
        }
    }
}

/**
 * التحقق مما إذا كان التاريخ يقع ضمن فترة مالية مغلقة
 */
function is_period_closed($pdo, $date)
{
    $start_date = get_setting('fiscal_start_date');
    $end_date = get_setting('fiscal_end_date');

    $check_time = strtotime($date);

    // إذا تم تحديد تاريخ بداية، يجب ألا تكون العملية قبله
    if ($start_date) {
        $start_time = strtotime($start_date);
        if ($check_time < $start_time) return true;
    }

    // إذا تم تحديد تاريخ نهاية، يجب ألا تكون العملية بعده
    if ($end_date) {
        $end_time = strtotime($end_date);
        if ($check_time > $end_time) return true;
    }

    return false;
}

/**
 * التحقق مما إذا كان يمكن حذف حساب محاسبي
 */
function can_delete_account($account_id)
{
    global $pdo;
    try {
        // 1. التحقق من وجود حركات في دفتر اليومية
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id = ?");
        $stmt->execute([$account_id]);
        if ($stmt->fetchColumn() > 0) return false;

        // 2. التحقق من وجود حركات مالية (سندات) مرتبطة مباشرة
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM financial_transactions WHERE party_account_id = ? OR cash_bank_account_id = ?");
        $stmt->execute([$account_id, $account_id]);
        if ($stmt->fetchColumn() > 0) return false;

        // 3. التحقق من وجود أرصدة غير صفرية
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND (opening_balance != 0 OR current_balance != 0)");
        $stmt->execute([$account_id]);
        if ($stmt->fetchColumn() > 0) return false;

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * جلب قيمة إعداد معين
 */
function get_setting($key, $default = null)
{
    global $pdo;
    static $settings_cache = null;
    if ($settings_cache === null) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'system_settings'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
                $settings_cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } else {
                $settings_cache = [];
            }
        } catch (PDOException $e) {
            $settings_cache = [];
        }
    }
    return $settings_cache[$key] ?? $default;
}

/**
 * جلب رقم تسلسلي جديد من النظام الموحد بناءً على الخدمة ونوع الحركة
 */
function generate_unified_number($pdo, $type, $service_type = null)
{
    $prefix = '';
    $digits = 6;

    // جلب الإعدادات بناءً على الخدمة
    if ($service_type) {
        $srv_map = [
            'النقل البري' => ['bus'],
            'تذاكر طيران وبصات' => ['flight'],
            'حجوزات الباصات والطيران' => ['flight'],
            'الطيران' => ['flight'],
            'حجوزات الطيران' => ['flight'],
            'حجوزات الباصات' => ['bus'],
            'bus' => ['bus'],
            'flight' => ['flight'],
            'تأشيرة عمل' => ['work_visa'],
            'work_visa' => ['work_visa'],
            'قسم العمرة' => ['umrah'],
            'زيارة عائلية' => ['family_visit'],
            'الزيارة العائلية' => ['family_visit'],
            'family_visit' => ['family_visit'],
            'معاملات الجوازات' => ['passport'],
            'passport' => ['passport']
        ];

        $srv_keys = $srv_map[$service_type] ?? [];
        foreach ($srv_keys as $srv_key) {
            $candidatePrefix = get_setting("srv_{$srv_key}_" . ($type == 'purchase' ? 'purchase' : 'sales') . "_prefix");
            $candidateDigits = get_setting("srv_{$srv_key}_digits", null);

            if (!empty($candidatePrefix)) {
                $prefix = $candidatePrefix;
            }
            if (!empty($candidateDigits)) {
                $digits = (int)$candidateDigits;
            }
        }
    }

    // إذا لم تتوفر إعدادات خاصة بالخدمة، نستخدم الإعدادات العامة
    if (empty($prefix)) {
        $prefix = get_setting($type == 'purchase' ? 'purchase_invoice_prefix' : 'sales_invoice_prefix', ($type == 'purchase' ? 'PI-' : 'SI-'));
        $digits = get_setting('invoice_number_digits', 6);
    }

    $year = date('y');
    $full_prefix = str_replace('{year}', $year, $prefix);

    // البحث عن آخر رقم مستخدم بهذا البادئة
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(invoice_number, LENGTH(?) + 1) AS UNSIGNED)) FROM invoices WHERE invoice_number LIKE ?");
    $stmt->execute([$full_prefix, $full_prefix . '%']);
    $last_num = (int)$stmt->fetchColumn();

    return $full_prefix . str_pad($last_num + 1, $digits, '0', STR_PAD_LEFT);
}

/**
 * إنشاء فاتورة موحدة (بدون ترحيل تلقائي) - للترحيل اليدوي
 */
function php_create_invoice(
    $pdo,
    $category, // 'sales' or 'purchase'
    $branch_id,
    $source_type,
    $source_id,
    $party_id, // customer_id or supplier_id
    $currency_id,
    $total_amount,
    $discount = 0,
    $cost_amount = 0,
    $payment_type = 'cash',
    $description = '',
    $invoice_date = null,
    $created_by = null,
    $agent_id = null,
    $branch_entity_id = null,
    $cost_center_id = null
) {
    try {
        // التحقق من إغلاق الفترة المالية
        $invoice_date = normalize_datetime_db($invoice_date, 'now');
        if (is_period_closed($pdo, $invoice_date)) {
            throw new Exception("تنبيه: لا يمكن إنشاء الفاتورة. التاريخ المحدد ($invoice_date) يقع ضمن فترة مالية مغلقة.");
        }

        $customer_id = ($category == 'sales') ? $party_id : null;
        $supplier_id = ($category == 'purchase') ? $party_id : null;
        $user_id = $created_by ?: ($_SESSION['admin_id'] ?? 1);

        // جلب الإعدادات لتوليد رقم الفاتورة
        $settings = [];
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // توليد رقم الفاتورة
        $invoice_data = generateInvoiceNumber($pdo, $source_type, $category, $settings);
        $invoice_number = $invoice_data['number'];

        // إنشاء الفاتورة في وضع المسودة (بدون ترحيل)
        $stmt = $pdo->prepare("CALL sp_create_invoice(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @invoice_id)");
        $stmt->execute([
            $category,
            $branch_id,
            $source_type,
            $source_id,
            $customer_id,
            $supplier_id,
            $agent_id,
            $branch_entity_id,
            $currency_id,
            $total_amount,
            $discount,
            $cost_amount,
            $payment_type,
            $description,
            $user_id,
            $cost_center_id,
            $invoice_number
        ]);
        $stmt->closeCursor();

        $invoice_id = $pdo->query("SELECT @invoice_id")->fetchColumn();

        // جلب حساب العميل أو المورد، مع fallback للحساب المختار في التدفقات legacy
        $customer_account_id = null;
        $supplier_account_id = null;
        if ($customer_id) {
            $stmt = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customer_account_id = $stmt->fetchColumn() ?: null;
        }
        if ($supplier_id) {
            $stmt = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt->execute([$supplier_id]);
            $supplier_account_id = $stmt->fetchColumn() ?: null;
        }

        if (!$customer_account_id && $category == 'sales' && in_array($payment_type, ['credit', 'credit_doc', 'on_account'], true) && $branch_entity_id) {
            $customer_account_id = $branch_entity_id;
        }

        // تحديث الحسابات في الفاتورة
        $update_sql = "
            UPDATE invoices
            SET invoice_date = ?,
                account_id = ?,
                customer_account_id = ?,
                supplier_account_id = ?,
                delivery_type = ?,
                payment_type = ?
            WHERE id = ?
        ";
        $account_id_for_update = null;
        if ($category == 'sales') {
            $account_id_for_update = $customer_account_id ?: $branch_entity_id;
        } else {
            $account_id_for_update = $supplier_account_id ?: $branch_entity_id;
        }
        $pdo->prepare($update_sql)->execute([
            $invoice_date,
            $account_id_for_update,
            $customer_account_id,
            $supplier_account_id,
            $payment_type,
            $payment_type,
            $invoice_id
        ]);

        // الفاتورة تبقى في وضع المسودة - الترحيل يدوي لاحقاً
        return $invoice_id;
    } catch (Exception $e) {
        error_log("Error in php_create_invoice: " . $e->getMessage());
        throw $e;
    }
}

/**
 * ترحيل فاتورة يدوياً (POST)
 * يتم استدعاء هذه الدالة عند الترحيل اليدوي
 */
function php_post_invoice($pdo, $invoice_id, $posted_by = null, $use_outer_transaction = false) {
    try {
        $user_id = $posted_by ?: ($_SESSION['admin_id'] ?? 1);

        // التحقق من حالة الفاتورة
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            throw new Exception("الفاتورة غير موجودة");
        }

        if ($invoice['invoice_status'] === 'posted') {
            throw new Exception("الفاتورة مُرحلة بالفعل");
        }

        $stmt_existing = $pdo->prepare("
            SELECT id, status
            FROM financial_transactions
            WHERE reference_type = 'invoice'
              AND reference_id = ?
              AND status IN ('draft', 'posted')
            LIMIT 1
        ");
        $stmt_existing->execute([$invoice_id]);
        $existing_transaction = $stmt_existing->fetch(PDO::FETCH_ASSOC);
        if ($existing_transaction) {
            if ($existing_transaction['status'] === 'posted') {
                throw new Exception("توجد حركة مالية سابقة مرتبطة بهذه الفاتورة، ولا يمكن ترحيلها مرة أخرى");
            }

            $stmt_existing_lines = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE financial_transaction_id = ?");
            $stmt_existing_lines->execute([$existing_transaction['id']]);
            $existing_lines_count = (int)$stmt_existing_lines->fetchColumn();

            if ($existing_lines_count > 0) {
                throw new Exception("توجد حركة مسودة مرتبطة بهذه الفاتورة وما زالت تحتوي على قيود يومية، يرجى مراجعتها قبل إعادة الترحيل");
            }

            // Clean up legacy draft transactions left behind by invoice reset/unpost.
            $pdo->prepare("
                UPDATE financial_transactions
                SET status = 'cancelled',
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = ?
                WHERE id = ?
            ")->execute([$user_id, $existing_transaction['id']]);
        }

        // جلب الإعدادات
        $settings = [];
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // جلب تكوين الخدمة لحساب الإيرادات/التكاليف/الأرباح
        $srv_config = getServiceInvoiceConfig($invoice['source_type'], $settings);
        
        // تحقق من جدول الخدمات لحسابات محددة للخدمة
        if (!empty($invoice['source_type'])) {
            $serviceStmt = $pdo->prepare("
                SELECT revenue_account_id, cost_account_id, profit_account_id
                FROM services
                WHERE service_name = ?
                LIMIT 1
            ");
            $serviceStmt->execute([$invoice['source_type']]);
            $serviceCfg = $serviceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
            foreach (['revenue_account_id', 'cost_account_id', 'profit_account_id'] as $accountKey) {
                if (!empty($serviceCfg[$accountKey])) {
                    $srv_config[$accountKey] = (int)$serviceCfg[$accountKey];
                }
            }
        }
        
        // التحقق من الحدود المالية قبل الترحيل
        if ($invoice['customer_id']) {
            $stmt_acc = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
            $stmt_acc->execute([$invoice['customer_id']]);
            $acc_id = $stmt_acc->fetchColumn();
            if ($acc_id) check_account_limits($pdo, $acc_id, $invoice['currency_id'], $invoice['total_amount']);
        } elseif ($invoice['supplier_id']) {
            $stmt_acc = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt_acc->execute([$invoice['supplier_id']]);
            $acc_id = $stmt_acc->fetchColumn();
            if ($acc_id) check_account_limits($pdo, $acc_id, $invoice['currency_id'], $invoice['total_amount']);
        }

        // بدء معاملة فقط إذا لم يكن هناك معاملة بالفعل
        if (!$use_outer_transaction) {
            $pdo->beginTransaction();
        }

        // 1. تحديث حالة الفاتورة إلى posted أولاً
        $pdo->prepare("UPDATE invoices SET invoice_status = 'posted', posted_by = ?, posted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id, $invoice_id]);

        // 2. إنشاء المعاملة المالية والقيود اليومية
        $invoice_date = normalize_datetime_db($invoice['invoice_date'] ?? null, 'now');
        $description = $invoice['description'] ?? 'فاتورة ' . ($invoice['invoice_category'] == 'sales' ? 'بيع' : 'شراء') . ' رقم ' . $invoice['invoice_number'];

        // --- لفواتير البيع ---
        if ($invoice['invoice_category'] == 'sales') {
            // جلب حسابات الخدمة (الإيرادات فقط - تكاليف وأرباح ترحل مع فاتورة الشراء)
            $revenue_account_id = $srv_config['revenue_account_id'] ?? ($settings['default_sales_account_id'] ?? null);
            
            // إذا لم يتم العثور على حساب إيرادات، احصل على أول حساب إيرادات نشط
            if (empty($revenue_account_id)) {
                $stmt_rev = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_status = 1 AND (account_type LIKE '%revenue%' OR account_name_ar LIKE '%إيرادات%' OR account_code LIKE '4%') LIMIT 1");
                $stmt_rev->execute();
                $revenue_account_id = $stmt_rev->fetchColumn();
                
                // إذا لم يوجد حساب إيرادات، استخدم أول حساب نشط
                if (empty($revenue_account_id)) {
                    $stmt_rev = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_status = 1 LIMIT 1");
                    $stmt_rev->execute();
                    $revenue_account_id = $stmt_rev->fetchColumn();
                }
                
                if (empty($revenue_account_id)) {
                    throw new Exception("لم يتم العثور على حساب إيرادات صالح للترحيل.");
                }
            }
            
            // جلب حساب العميل أو الوكيل أو الفرع أو الصندوق/البنك
            $party_account_id = null;
            if ($invoice['customer_account_id']) {
                $party_account_id = $invoice['customer_account_id'];
            } elseif ($invoice['customer_id']) {
                $stmt_customer = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
                $stmt_customer->execute([$invoice['customer_id']]);
                $party_account_id = $stmt_customer->fetchColumn();
            } elseif ($invoice['agent_id']) {
                $stmt_agent = $pdo->prepare("SELECT account_id FROM agents WHERE id = ?");
                $stmt_agent->execute([$invoice['agent_id']]);
                $party_account_id = $stmt_agent->fetchColumn();
            } elseif ($invoice['account_id']) {
                // Fallback: use account_id from invoice if it's a customer/agent account
                $party_account_id = $invoice['account_id'];
            }
            
            // إذا لم يتم العثور على حساب العميل، انشئ حسابًا تلقائيًا أو استخدم أول حساب مدين نشط
            if (empty($party_account_id)) {
                if ($invoice['customer_id']) {
                    $party_account_id = php_handle_entity_account_creation($pdo, 'customer', $invoice['customer_id'], 'عميل افتراضي');
                } elseif ($invoice['agent_id']) {
                    $party_account_id = php_handle_entity_account_creation($pdo, 'agent', $invoice['agent_id'], 'وكيل افتراضي');
                }
            }
            
            // إذا لم يكن هناك حساب بعد، استخدم أول حساب مدين نشط
            if (empty($party_account_id)) {
                $stmt_party = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_status = 1 AND normal_balance = 'debit' LIMIT 1");
                $stmt_party->execute();
                $party_account_id = $stmt_party->fetchColumn();
            }
            
            if (empty($party_account_id)) {
                throw new Exception("لم يتم العثور على حساب عميل/وكيل صالح للترحيل.");
            }
            
            $cash_bank_account_id = $invoice['account_id'];
            
            $net_amount = (float)$invoice['total_amount'] - (float)$invoice['discount'];

            // توليد رقم المعاملة
            $trx_num = fn_get_next_sequence($pdo, 'invoice');
            
            // إنشاء المعاملة المالية
            $stmt_trx = $pdo->prepare("INSERT INTO financial_transactions (transaction_number, transaction_date, reference_type, reference_id, description, transaction_type, amount, currency_id, branch_id, created_by, status) VALUES (?, ?, 'invoice', ?, ?, 'invoice', ?, ?, ?, ?, 'posted')");
            $stmt_trx->execute([$trx_num, $invoice_date, $invoice_id, $description, $net_amount, $invoice['currency_id'], $invoice['branch_id'], $user_id]);
            $trx_id = $pdo->lastInsertId();

            // --- إنشاء قيود اليومية ---
            
            // 1. إذا كان الدفع نقداً أو تحويل بنكي: مدين للصندوق/البنك
            if (($invoice['delivery_type'] == 'cash' || $invoice['delivery_type'] == 'bank_transfer') && $cash_bank_account_id) {
                $received = (float)$invoice['amount_received'] ?? $net_amount;
                if ($received > 0) {
                    $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                    $stmt_jrl->execute([$trx_id, $cash_bank_account_id, $received, $invoice['currency_id'], $invoice['branch_id'], 'إيداع نقدي/تحويل بنكي لفواتير البيع']);
                }
                // 2. إذا كان هناك باقي: مدين للعميل
                if ($net_amount > $received) {
                    $remaining = $net_amount - $received;
                    if ($party_account_id) {
                        $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                        $stmt_jrl->execute([$trx_id, $party_account_id, $remaining, $invoice['currency_id'], $invoice['branch_id'], 'مديونية العميل (المتبقي)']);
                    }
                }
            }
            // 3. إذا كان دفعة آجلة بالكامل
            elseif ($invoice['delivery_type'] == 'credit' || $invoice['delivery_type'] == 'on_account') {
                if ($party_account_id) {
                    $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                    $stmt_jrl->execute([$trx_id, $party_account_id, $net_amount, $invoice['currency_id'], $invoice['branch_id'], 'مديونية العميل']);
                }
            }

            // 4. دائن للإيرادات
            if ($revenue_account_id) {
                $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, 0, ?, ?, ?, ?)");
                $stmt_jrl->execute([$trx_id, $revenue_account_id, $net_amount, $invoice['currency_id'], $invoice['branch_id'], 'إيرادات فاتورة بيع']);
            }

            // التحقق من توازن القيد قبل تطبيق الأرصدة
            validate_journal_balance($pdo, (int)$trx_id);

            if (!balances_triggers_enabled($pdo)) {
                apply_transaction_balances($pdo, (int)$trx_id, 1);
            }
        }
        // --- لفواتير الشراء ---
        else {
            // جلب حساب المورد
            $party_account_id = null;
            if ($invoice['supplier_account_id']) {
                $party_account_id = $invoice['supplier_account_id'];
            } elseif ($invoice['supplier_id']) {
                $stmt_supp = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
                $stmt_supp->execute([$invoice['supplier_id']]);
                $party_account_id = $stmt_supp->fetchColumn();
            }
            
            // إذا لم يتم العثور على حساب مورد، انشئ حسابًا تلقائيًا أو استخدم أول حساب مورد
            if (empty($party_account_id) && $invoice['supplier_id']) {
                $party_account_id = php_handle_entity_account_creation($pdo, 'supplier', $invoice['supplier_id'], 'مورد افتراضي');
            }
            
            // إذا لم يكن هناك حساب بعد، استخدم أول حساب دائن نشط
            if (empty($party_account_id)) {
                $stmt_party = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_status = 1 AND normal_balance = 'credit' LIMIT 1");
                $stmt_party->execute();
                $party_account_id = $stmt_party->fetchColumn();
            }
            
            if (empty($party_account_id)) {
                throw new Exception("لم يتم العثور على حساب مورد صالح للترحيل.");
            }

            // جلب حساب التكاليف للخدمة
            $cost_account_id = $srv_config['cost_account_id'] ?? ($settings['default_cost_account_id'] ?? null);
            
            // إذا لم يتم العثور على حساب تكاليف، احصل على أول حساب تكاليف نشط
            if (empty($cost_account_id)) {
                $stmt_cost = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_status = 1 AND (account_type LIKE '%cost%' OR account_type LIKE '%expense%' OR account_name_ar LIKE '%تكاليف%' OR account_name_ar LIKE '%مصروف%' OR account_code LIKE '5%' OR account_code LIKE '6%') LIMIT 1");
                $stmt_cost->execute();
                $cost_account_id = $stmt_cost->fetchColumn();
                
                // إذا لم يوجد حساب تكاليف، استخدم أول حساب نشط
                if (empty($cost_account_id)) {
                    $stmt_cost = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_status = 1 LIMIT 1");
                    $stmt_cost->execute();
                    $cost_account_id = $stmt_cost->fetchColumn();
                }
                
                if (empty($cost_account_id)) {
                    throw new Exception("لم يتم العثور على حساب تكاليف صالح للترحيل.");
                }
            }
            
            $total_amount = (float)$invoice['total_amount'];
            
            // توليد رقم المعاملة
            $trx_num = fn_get_next_sequence($pdo, 'purchase');
            
            // إنشاء المعاملة المالية
            $stmt_trx = $pdo->prepare("INSERT INTO financial_transactions (transaction_number, transaction_date, reference_type, reference_id, description, transaction_type, amount, currency_id, branch_id, created_by, status) VALUES (?, ?, 'invoice', ?, ?, 'purchase', ?, ?, ?, ?, 'posted')");
            $stmt_trx->execute([$trx_num, $invoice_date, $invoice_id, $description, $total_amount, $invoice['currency_id'], $invoice['branch_id'], $user_id]);
            $trx_id = $pdo->lastInsertId();

            // --- إنشاء قيود اليومية ---
            // 1. مدين للتكاليف
            if ($cost_account_id) {
                $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                $stmt_jrl->execute([$trx_id, $cost_account_id, $total_amount, $invoice['currency_id'], $invoice['branch_id'], 'تكلفة فاتورة شراء']);
            }

            // 2. دائن للمورد
            if ($party_account_id) {
                $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, 0, ?, ?, ?, ?)");
                $stmt_jrl->execute([$trx_id, $party_account_id, $total_amount, $invoice['currency_id'], $invoice['branch_id'], 'مديونية للمورد']);
            }


            // التحقق من توازن القيد قبل تطبيق الأرصدة
            validate_journal_balance($pdo, (int)$trx_id);

            if (!balances_triggers_enabled($pdo)) {
                apply_transaction_balances($pdo, (int)$trx_id, 1);
            }
        }

        if (!$use_outer_transaction) {
            $pdo->commit();
        }
        return true;

    } catch (Exception $e) {
        if (!$use_outer_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in php_post_invoice: " . $e->getMessage());
        throw $e;
    }
}

/**
 * إنشاء فاتورة موحدة وترحيلها محاسبياً (للترحيل التلقائي عند الحاجة)
 * @deprecated استخدم php_create_invoice + php_post_invoice للترحيل اليدوي
 */
function php_create_invoice_and_post(
    $pdo,
    $category, // 'sales' or 'purchase'
    $branch_id,
    $source_type,
    $source_id,
    $party_id, // customer_id or supplier_id
    $currency_id,
    $total_amount,
    $discount = 0,
    $cost_amount = 0,
    $payment_type = 'cash',
    $description = '',
    $invoice_date = null,
    $created_by = null,
    $agent_id = null,
    $branch_entity_id = null,
    $cost_center_id = null
) {
    // إنشاء الفاتورة أولاً
    $invoice_id = php_create_invoice(
        $pdo,
        $category,
        $branch_id,
        $source_type,
        $source_id,
        $party_id,
        $currency_id,
        $total_amount,
        $discount,
        $cost_amount,
        $payment_type,
        $description,
        $invoice_date,
        $created_by,
        $agent_id,
        $branch_entity_id,
        $cost_center_id
    );

    // ثم ترحيلها تلقائياً إذا لم تكن مسودة
    if ($payment_type !== 'draft') {
        php_post_invoice($pdo, $invoice_id, $created_by);
    }

    return $invoice_id;
}

/**
 * تسجيل سجل تغييرات للحركات المالية
 */
function log_financial_transaction_change($pdo, $transaction_id, $type, $details = null)
{
    try {
        // التحقق من إغلاق الفترة المالية
        $trx_date = date('Y-m-d');
        if (is_period_closed($pdo, $trx_date)) {
            throw new Exception("تنبيه: لا يمكن تنفيذ العملية. التاريخ المحدد ($trx_date) يقع ضمن فترة مالية مغلقة.");
        }

        $user_id = $_SESSION['admin_id'] ?? 1;
        $stmt = $pdo->prepare("INSERT INTO financial_transaction_logs (transaction_id, changed_by, change_type, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$transaction_id, $user_id, $type, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Exception $e) {
        error_log("Error in log_financial_transaction_change: " . $e->getMessage());
    }
}

/**
 * إنشاء أو تعديل سند قبض/صرف موحد وترحيله
 */
function php_create_voucher_and_post(
    $pdo,
    $type, // 'receipt' or 'payment'
    $branch_id,
    $entity_type,
    $entity_id,
    $amount,
    $currency_id,
    $cash_bank_account_id,
    $party_account_id,
    $description = '',
    $reference = '',
    $allocations_json = null,
    $cost_center_id = null,
    $edit_id = null,
    $auto_post = true
) {
    try {
        $user_id = $_SESSION['admin_id'] ?? 1;
        $old_data = null;

        if ($edit_id) {
            // جلب البيانات القديمة قبل التعديل
            $stmt_old = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
            $stmt_old->execute([$edit_id]);
            $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);

            // إلغاء القيد القديم قبل التعديل (عكس الأرصدة)
            // First, reverse the balances
            if ($old_data['status'] == 'posted') {
                if (!balances_triggers_enabled($pdo)) {
                    apply_transaction_balances($pdo, (int)$edit_id, -1);
                }
                $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$edit_id]);
            }

            // تحديث حالة السند إلى مسودة
            $pdo->prepare("UPDATE financial_transactions SET status = 'cancelled' WHERE id = ?")->execute([$edit_id]);
        }

        if ($type == 'receipt') {
            // التحقق من الحدود المالية (سند القبض هو Credit للطرف الآخر)
            $stmt_norm = $pdo->prepare("SELECT normal_balance FROM unified_accounts WHERE id = ?");
            $stmt_norm->execute([$party_account_id]);
            $norm = $stmt_norm->fetchColumn();
            $change = ($norm == 'debit') ? -$amount : $amount;
            check_account_limits($pdo, $party_account_id, $currency_id, $change);
        } else {
            // التحقق من الحدود المالية (سند الصرف هو Debit للطرف الآخر)
            $stmt_norm = $pdo->prepare("SELECT normal_balance FROM unified_accounts WHERE id = ?");
            $stmt_norm->execute([$party_account_id]);
            $norm = $stmt_norm->fetchColumn();
            $change = ($norm == 'debit') ? $amount : -$amount;
            check_account_limits($pdo, $party_account_id, $currency_id, $change);
        }

        // Get exchange rate for the currency
        $stmt_curr = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE id = ?");
        $stmt_curr->execute([$currency_id]);
        $exchange_rate = (float)($stmt_curr->fetchColumn() ?: 1);

        if ($edit_id) {
            // تحديث السند بدلاً من إنشائه
            $stmt = $pdo->prepare("UPDATE financial_transactions SET
                transaction_date = CURRENT_DATE, branch_id = ?, entity_type = ?, entity_id = ?,
                amount = ?, currency_id = ?, cash_bank_account_id = ?, party_account_id = ?,
                reference_number = ?, description = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?,
                status = 'draft', cost_center_id = ?, exchange_rate = ?
                WHERE id = ?");
            $stmt->execute([$branch_id, $entity_type, $entity_id, $amount, $currency_id, $cash_bank_account_id, $party_account_id, $reference, $description, $user_id, $cost_center_id, $exchange_rate, $edit_id]);
            $voucher_id = $edit_id;
        } else {
            // إنشاء سند جديد
            $transaction_number = fn_get_next_sequence($pdo, $type);
            $stmt = $pdo->prepare("INSERT INTO financial_transactions (
                transaction_number, transaction_date, branch_id, transaction_type,
                entity_type, entity_id, amount, currency_id, cash_bank_account_id, party_account_id,
                reference_number, description, cost_center_id, created_by, exchange_rate, status
            ) VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $transaction_number, $branch_id, $type,
                $entity_type, $entity_id, $amount, $currency_id, $cash_bank_account_id, $party_account_id,
                $reference, $description, $cost_center_id, $user_id, $exchange_rate, 'draft'
            ]);
            $voucher_id = $pdo->lastInsertId();
        }

        // ترحيل السند
        if ($auto_post) {
            if ($type == 'receipt') {
                php_post_receipt_voucher($pdo, $voucher_id, $user_id);
            } else {
                php_post_payment_voucher($pdo, $voucher_id, $user_id);
            }
        }

        // تسجيل السجل
        if ($edit_id) {
            $new_data = ['amount' => $amount, 'description' => $description, 'currency_id' => $currency_id, 'account_id' => $cash_bank_account_id];
            $changes = [];
            foreach ($new_data as $key => $val) {
                if (isset($old_data[$key]) && $old_data[$key] != $val) {
                    $changes[$key] = ['old' => $old_data[$key], 'new' => $val];
                }
            }
            log_financial_transaction_change($pdo, $voucher_id, 'update', ['changes' => $changes]);
        } else {
            log_financial_transaction_change($pdo, $voucher_id, 'create');
        }

        // Handle allocations if provided
        if ($allocations_json && !$edit_id) {
            $allocations = json_decode($allocations_json, true);
            if (is_array($allocations)) {
                foreach ($allocations as $alloc) {
                    if (isset($alloc['invoice_id']) && isset($alloc['amount'])) {
                        $stmt_alloc = $pdo->prepare("INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)");
                        $stmt_alloc->execute([$voucher_id, $alloc['invoice_id'], (float)$alloc['amount']]);
                        php_recalculate_invoice_payment($pdo, $alloc['invoice_id']);
                    }
                }
            }
        }

        return $voucher_id;
    } catch (Exception $e) {
        error_log("Error in php_create_voucher_and_post: " . $e->getMessage());
        throw $e;
    }
}

/**
 * إنشاء حساب تلقائي للكيانات في شجرة الحسابات الموحدة
 */
function php_get_next_child_account_code($pdo, $parent_code)
{
    $parent_code = trim((string)$parent_code);
    if ($parent_code === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT account_code
        FROM unified_accounts
        WHERE account_code LIKE ?
          AND account_code <> ?
        ORDER BY LENGTH(account_code) DESC, account_code DESC
        LIMIT 1
    ");
    $stmt->execute([$parent_code . '%', $parent_code]);
    $last_code = $stmt->fetchColumn();

    if (!$last_code) {
        return $parent_code . '001';
    }

    $suffix = substr((string)$last_code, strlen($parent_code));
    $next_number = ((int)$suffix) + 1;
    $suffix_length = max(3, strlen((string)$suffix));

    return $parent_code . str_pad((string)$next_number, $suffix_length, '0', STR_PAD_LEFT);
}

function php_handle_entity_account_creation($pdo, $entity_type, $entity_id, $entity_name)
{
    try {
        $parent_code = '';
        $type = '';
        $normal = '';

        switch ($entity_type) {
            case 'customer':
                $parent_code = '11201';
                $type = 'عميل';
                $normal = 'debit';
                break;
            case 'agent':
                $parent_code = '11203';
                $type = 'agent';
                $normal = 'debit';
                break;
            case 'branch':
                $parent_code = '11202';
                $type = 'branch';
                $normal = 'debit';
                break;
            case 'supplier':
                $parent_code = '21101';
                $type = 'مورد';
                $normal = 'credit';
                break;
            case 'employee':
                $parent_code = '21103';
                $type = 'liability';
                $normal = 'credit';
                break;
        }

        if (!$parent_code) return false;

        $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $stmt_parent->execute([$parent_code]);
        $parent_id = $stmt_parent->fetchColumn();

        if (!$parent_id) return false;

        $table = ($entity_type == 'customer') ? 'customers' : (($entity_type == 'agent') ? 'agents' : (($entity_type == 'branch') ? 'branches' : (($entity_type == 'supplier') ? 'suppliers' : 'employees')));

        $stmt_entity = $pdo->prepare("SELECT account_id FROM `$table` WHERE id = ? LIMIT 1");
        $stmt_entity->execute([$entity_id]);
        $entity_account_id = $stmt_entity->fetchColumn();
        if ($entity_account_id) {
            $stmt_existing_entity = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ?");
            $stmt_existing_entity->execute([$entity_account_id]);
            $existing_entity_account_id = $stmt_existing_entity->fetchColumn();
            if ($existing_entity_account_id) {
                return (int)$existing_entity_account_id;
            }
        }

        $account_code = php_get_next_child_account_code($pdo, $parent_code);
        if (!$account_code) return false;

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (parent_id, account_code, account_name_ar, account_type, owner_type, normal_balance) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$parent_id, $account_code, $entity_name, $type, $entity_type, $normal]);
        $account_id = $pdo->lastInsertId();

        // Add the base currency to the account balances unified table
        $base = get_base_currency($pdo);
        $base_currency_id = $base['id'];
        if ($base_currency_id) {
            $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
            $stmt_curr->execute([$base_currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            
            $stmt_base_balance = $pdo->prepare("INSERT IGNORE INTO account_balances_unified (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) VALUES (?, NULL, ?, ?, 0, 0, 0, 0, 0, 0, 0)");
            $stmt_base_balance->execute([$account_id, $base_currency_id, $currency_code]);
        }

        $pdo->prepare("UPDATE `$table` SET account_id = ? WHERE id = ?")->execute([$account_id, $entity_id]);

        return $account_id;
    } catch (PDOException $e) {
        error_log("Error: " . $e->getMessage());
        return false;
    }
}

/**
 * جلب سعر الصرف بين عملتين
 */
function get_exchange_rate($from_currency_id, $to_currency_id, $type = 'sell')
{
    global $pdo;

    if ($from_currency_id == $to_currency_id) return 1.0;

    $stmt = $pdo->prepare("SELECT id, exchange_rate, exchange_rate_sell, exchange_rate_buy FROM currencies WHERE id IN (?, ?)");
    $stmt->execute([$from_currency_id, $to_currency_id]);
    $currencies = $stmt->fetchAll(PDO::FETCH_UNIQUE);

    if (count($currencies) < 2) return 0;

    $from = $currencies[$from_currency_id];
    $to = $currencies[$to_currency_id];

    // السعر المستخدم (بيع أو شراء أو افتراضي)
    $from_rate = ($type == 'sell' && $from['exchange_rate_sell'] > 0) ? (float)$from['exchange_rate_sell'] : (float)$from['exchange_rate'];
    $to_rate = ($type == 'sell' && $to['exchange_rate_sell'] > 0) ? (float)$to['exchange_rate_sell'] : (float)$to['exchange_rate'];

    if ($type == 'buy') {
        if ($from['exchange_rate_buy'] > 0) $from_rate = (float)$from['exchange_rate_buy'];
        if ($to['exchange_rate_buy'] > 0) $to_rate = (float)$to['exchange_rate_buy'];
    }

    // التحويل: (1 وحدة من المصدر = كم وحدة من الهدف)
    // القاعدة: (سعر المصدر مقابل الريال) / (سعر الهدف مقابل الريال)
    if ($to_rate == 0) return 0;
    return round($from_rate / $to_rate, 6);
}

/**
 * التحقق من الحدود المالية للحساب قبل تنفيذ العملية (موحد بالعملة الأساسية)
 */
function check_account_limits($pdo, $account_id, $currency_id, $amount_change)
{
    // جلب الإعدادات العامة للتحقق هل الرقابة مفعلة أم لا
    $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('enable_customer_limit_check', 'enable_supplier_limit_check', 'enable_debit_limit_check')");
    $settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);

    $enable_customer_check = (bool)($settings['enable_customer_limit_check'] ?? true);
    $enable_supplier_check = (bool)($settings['enable_supplier_limit_check'] ?? true);
    $enable_debit_limit_check = (bool)($settings['enable_debit_limit_check'] ?? true);

    // 1. التحقق من حالة التجميد للعملة المحددة (دائماً يتم التحقق منها بغض النظر عن الإعدادات)
    $stmt_freeze = $pdo->prepare("
        SELECT COALESCE(MAX(is_frozen), 0) AS is_frozen, MAX(currency_code) AS currency_code
        FROM account_balances_unified
        WHERE account_id = ? AND currency_id = ?
    ");
    $stmt_freeze->execute([$account_id, $currency_id]);
    $freeze_info = $stmt_freeze->fetch(PDO::FETCH_ASSOC);
    if (!$freeze_info) {
        // If account balance doesn't exist, create it!
        $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
        $stmt_curr->execute([$currency_id]);
        $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
        $currency_code = $curr['currency_code'] ?? '';
        
        $stmt_ins_balance = $pdo->prepare("INSERT IGNORE INTO account_balances_unified (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) VALUES (?, NULL, ?, ?, 0, 0, 0, 0, 0, 0, 0)");
        $stmt_ins_balance->execute([$account_id, $currency_id, $currency_code]);
    } else {
        if ($freeze_info['is_frozen'] == 1) {
            $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
            $stmt_curr->execute([$currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            throw new Exception("تنبيه: لا يمكن تنفيذ العملية. التعامل بعملة " . $currency_code . " مجمد حالياً لهذا الحساب.");
        }
    }

    // 2. جلب الحد الائتماني والدائن الموحد وطبيعة الحساب من جدول الحسابات
    $stmt_ua = $pdo->prepare("SELECT credit_limit_base, debit_limit_base, normal_balance, account_type FROM unified_accounts WHERE id = ?");
    $stmt_ua->execute([$account_id]);
    $ua_info = $stmt_ua->fetch(PDO::FETCH_ASSOC);
    if (!$ua_info) return true;

    // التحقق من الإعدادات العامة قبل المتابعة
    if ($ua_info['normal_balance'] == 'debit' && !$enable_customer_check) return true;
    if ($ua_info['normal_balance'] == 'credit' && !$enable_supplier_check) return true;

    $limit = (float)$ua_info['credit_limit_base'];
    $debit_limit = (float)$ua_info['debit_limit_base'];

    // 3. حساب إجمالي الرصيد الحالي لكل العملات بالعملة الأساسية
    $stmt_total = $pdo->prepare("SELECT SUM(current_balance_base) FROM account_balances_unified WHERE account_id = ?");
    $stmt_total->execute([$account_id]);
    $current_total_base = (float)$stmt_total->fetchColumn();

    // 4. تحويل مبلغ الحركة الجديد للعملة الأساسية
    $stmt_curr = $pdo->prepare("SELECT exchange_rate, exchange_rate_sell, exchange_rate_buy FROM currencies WHERE id = ?");
    $stmt_curr->execute([$currency_id]);
    $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
    if (!$curr) return true;

    $rate = 1.0;
    if ($amount_change > 0) {
        $rate = (float)($curr['exchange_rate_sell'] > 0 ? $curr['exchange_rate_sell'] : $curr['exchange_rate']);
    } else {
        $rate = (float)($curr['exchange_rate_buy'] > 0 ? $curr['exchange_rate_buy'] : $curr['exchange_rate']);
    }
    $amount_change_base = $amount_change * $rate;

    $new_total_base = $current_total_base + $amount_change_base;

    // 5. التحقق من الحدود الموحدة (بالعملة الأساسية)
    $abs_credit_limit = abs($limit);
    $abs_debit_limit = abs($debit_limit);

    if ($ua_info['normal_balance'] == 'debit') {
        // حساب مدين (مثل العملاء): الرصيد الموجب يعني مديونية للعميل لنا
        // إذا كان الرصيد الجديد سيصبح موجباً (مديونية) ولم يكن هناك حد ائتماني مسموح به، أو تجاوز الحد
        if ($new_total_base > 0.01) { // 0.01 لتجنب مشاكل الكسور البسيطة
            if ($abs_credit_limit <= 0) {
                // إذا لم يوجد رصيد دائن سابق يغطي الفاتورة، ولا يوجد حد ائتماني
                throw new Exception("تنبيه: لا يمكن تنفيذ العملية. العميل لا يمتلك رصيد كافٍ ولا يوجد له حد ائتماني مسموح به.");
            } elseif ($new_total_base > $abs_credit_limit) {
                throw new Exception("تنبيه: تجاوز الحد الائتماني الموحد (مديونية العميل). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_credit_limit, 2) . ") بالعملة الأساسية.");
            }
        }

        // الحد الدائن (debit_limit): يمنع زيادة مديونيتنا للعميل (الرصيد السالب - فائض الإيداع)
        if ($enable_debit_limit_check && $abs_debit_limit > 0 && $new_total_base < -$abs_debit_limit) {
            throw new Exception("تنبيه: تجاوز الحد الدائن الموحد (فائض إيداع العميل). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_debit_limit, 2) . ") بالعملة الأساسية.");
        }
    } else {
        // حساب دائن (مثل الموردين): الرصيد الموجب يعني مديونيتنا نحن للمورد
        // إذا كان الرصيد سيصبح موجباً (مديونية علينا للمورد)
        if ($new_total_base > 0.01 && $enable_debit_limit_check) {
            if ($abs_debit_limit <= 0) {
                throw new Exception("تنبيه: لا يمكن تنفيذ العملية. لا يوجد رصيد كافٍ ولا يوجد حد مديونية مسموح به للمورد.");
            } elseif ($new_total_base > $abs_debit_limit) {
                throw new Exception("تنبيه: تجاوز الحد الدائن الموحد (مديونية المكتب للمورد). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_debit_limit, 2) . ") بالعملة الأساسية.");
            }
        }

        // الحد الائتماني (credit_limit): يمنع زيادة مديونية المورد لنا (الرصيد السالب - دفعات مقدمة للمورد)
        if ($abs_credit_limit > 0 && $new_total_base < -$abs_credit_limit) {
            throw new Exception("تنبيه: تجاوز الحد الائتماني الموحد (مديونية المورد للمكتب). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_credit_limit, 2) . ") بالعملة الأساسية.");
        }
    }

    return true;
}

/**
 * إلغاء حركة مالية وعكس تأثيرها على الأرصدة عبر قيد عكسي
 */
function php_cancel_transaction($pdo, $id)
{
    try {
        $user_id = $_SESSION['admin_id'] ?? 1;
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // 1. جلب بيانات الحركة
        $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
        $stmt->execute([$id]);
        $trx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trx || $trx['status'] == 'cancelled') return false;

        // 2. إذا كانت المعاملة مرحّلة، ننشئ قيداً عكسياً
        if ($trx['status'] == 'posted') {
            $stmt_lines = $pdo->prepare("SELECT * FROM journal_lines WHERE financial_transaction_id = ?");
            $stmt_lines->execute([$id]);
            $original_lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($original_lines)) {
                $rev_type = $trx['transaction_type'];
                $rev_number = fn_get_next_sequence($pdo, $rev_type);

                $stmt_rev = $pdo->prepare("
                    INSERT INTO financial_transactions (
                        transaction_number, transaction_date, branch_id,
                        transaction_type, entity_type, entity_id,
                        amount, currency_id, exchange_rate,
                        cash_bank_account_id, party_account_id,
                        cost_center_id, reference_number,
                        description, created_by, status,
                        reference_type, reference_id
                    ) VALUES (
                        ?, CURDATE(), ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, 'posted',
                        'reversal', ?
                    )
                ");
                $rev_desc = 'قيد عكسي للإلغاء | معاملة رقم: ' . $trx['transaction_number'];
                $stmt_rev->execute([
                    $rev_number,
                    $trx['branch_id'],
                    $rev_type,
                    $trx['entity_type'],
                    $trx['entity_id'],
                    $trx['amount'],
                    $trx['currency_id'],
                    $trx['exchange_rate'] ?? 1,
                    $trx['cash_bank_account_id'],
                    $trx['party_account_id'],
                    $trx['cost_center_id'],
                    $trx['reference_number'],
                    $rev_desc,
                    $user_id,
                    $id,
                ]);
                $reversal_id = $pdo->lastInsertId();

                foreach ($original_lines as $line) {
                    $pdo->prepare("
                        INSERT INTO journal_lines
                            (financial_transaction_id, account_id, debit, credit,
                             currency_id, branch_id, cost_center_id, description)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $reversal_id,
                        $line['account_id'],
                        $line['credit'],   // Credit becomes debit
                        $line['debit'],    // Debit becomes credit
                        $line['currency_id'],
                        $line['branch_id'],
                        $line['cost_center_id'] ?? null,
                        'قيد عكسي: ' . ($line['description'] ?? ''),
                    ]);
                }

                if (!balances_triggers_enabled($pdo)) {
                    apply_transaction_balances($pdo, (int)$reversal_id, 1);
                }
            } else {
                if (!balances_triggers_enabled($pdo)) {
                    apply_transaction_balances($pdo, (int)$id, -1);
                }
            }
        }

        // 3. تحديث حالة المعاملة الأصلية
        $stmt_upd = $pdo->prepare("
            UPDATE financial_transactions 
            SET status = 'cancelled', 
                updated_at = CURRENT_TIMESTAMP, 
                updated_by = ?,
                cancelled_at = NOW(),
                cancelled_by = ?,
                cancelled_ip = ?,
                cancellation_reason = 'إلغاء المعاملة'
            WHERE id = ?
        ");
        $stmt_upd->execute([$user_id, $user_id, $user_ip, $id]);

        // 4. إعادة حساب مبالغ الفواتير المرتبطة
        $invoice_ids = php_get_transaction_invoice_ids($pdo, $id);
        if (!empty($invoice_ids)) {
            php_recalculate_invoice_payments($pdo, $invoice_ids);
        }

        // 5. تسجيل في السجل
        log_financial_transaction_change($pdo, $id, 'cancel');

        return true;
    } catch (Exception $e) {
        error_log("Error in php_cancel_transaction: " . $e->getMessage());
        throw $e;
    }
}

/**
 * تنسيق عرض رصيد الحساب
 */
function format_account_balance($balance, $normal_balance = 'debit', $currency = 'YER')
{
    $abs_balance = abs($balance);
    $status = '';
    $class = '';

    if ($balance == 0) {
        return '<span class="text-muted">0.00</span>';
    }

    if ($normal_balance == 'debit') {
        $status = ($balance > 0) ? 'عليه (مدين)' : 'له (دائن)';
        $class = ($balance > 0) ? 'text-success' : 'text-danger';
    } else {
        $status = ($balance > 0) ? 'عليه (مدين)' : 'له (دائن)';
        $class = ($balance > 0) ? 'text-danger' : 'text-success';
    }

    return '<span class="fw-bold ' . $class . '">' . number_format($abs_balance, 2) . ' <small>' . $currency . '</small> <small class="text-muted opacity-75">' . $status . '</small></span>';
}

/**
 * جلب حالة إجمالي الأرصدة لعرضها في الهيدر
 */
function get_total_balance_status($total, $type = 'asset', $currency = 'YER')
{
    $class = ($total >= 0) ? 'text-success' : 'text-danger';
    $label = ($type == 'asset') ? ($total >= 0 ? 'إجمالي لنا' : 'إجمالي علينا') : ($total >= 0 ? 'إجمالي علينا' : 'إجمالي لنا');

    return '<span class="' . $class . ' fw-bold">' . $label . ': ' . number_format(abs($total), 2) . ' ' . $currency . '</span>';
}

/**
 * جلب شجرة الحسابات بشكل هرمي (قائمة مسطحة مرتبة)
 */
function build_flat_tree($accounts, $parent_id, $depth, &$tree)
{
    foreach ($accounts as $account) {
        if ($account['parent_id'] == $parent_id) {
            $account['depth'] = $depth;
            $account['display_name'] = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth) . $account['account_code'] . ' - ' . $account['account_name_ar'];
            $tree[] = $account;
            build_flat_tree($accounts, $account['id'], $depth + 1, $tree);
        }
    }
}

function get_hierarchical_accounts($pdo, $filters = [])
{
    $where = "WHERE 1=1";
    $params = [];
    
    if (!empty($filters['account_type'])) {
        $where .= " AND ua.account_type = ?";
        $params[] = $filters['account_type'];
    }
    
    if (!empty($filters['account_code_like'])) {
        $where .= " AND ua.account_code LIKE ?";
        $params[] = $filters['account_code_like'];
    }
    
    if (!empty($filters['account_status'])) {
        $where .= " AND ua.account_status = ?";
        $params[] = $filters['account_status'];
    }

    $stmt = $pdo->prepare("
        SELECT ua.id, ua.account_code, ua.account_name_ar, ua.parent_id,
               c.id as customer_id,
               a.id as agent_id,
               s.id as supplier_id
        FROM unified_accounts ua
        LEFT JOIN customers c ON ua.id = c.account_id
        LEFT JOIN agents a ON ua.id = a.account_id
        LEFT JOIN suppliers s ON ua.id = s.account_id
        $where ORDER BY ua.account_code
    ");
    $stmt->execute($params);
    $accounts = $stmt->fetchAll();

    $tree = [];
    build_flat_tree($accounts, null, 0, $tree);
    return $tree;
}

/**
 * التحقق من صحة الحساب (نشط، لا يحتوي على أبناء)
 */
function validate_postable_account($pdo, $account_id) {
    // التحقق من أن الحساب موجود
    $stmt = $pdo->prepare("SELECT account_status FROM unified_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        return ['valid' => false, 'message' => 'الحساب المحدد غير موجود.'];
    }

    // التحقق من أن الحساب نشط
    if ($account['account_status'] !== 'active') {
        return ['valid' => false, 'message' => 'الحساب المحدد غير نشط.'];
    }

    // التحقق من أن الحساب لا يحتوي على أبناء
    $stmt_children = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE parent_id = ?");
    $stmt_children->execute([$account_id]);
    if ($stmt_children->fetchColumn() > 0) {
        return ['valid' => false, 'message' => 'الحساب المحدد يحتوي على حسابات فرعية ولا يمكن استخدامه.'];
    }

    return ['valid' => true];
}

/**
 * جلب الحسابات التشغيلية فقط للصناديق والبنوك
 */
function get_cash_bank_postable_accounts($pdo) {
    // جلب الحسابات التي تبدأ ب11101 (صناديق) أو 11102 (بنوك)، نشطة، ولا تُستخدم كـ parent_id لأي حساب آخر
    $stmt = $pdo->prepare("
        SELECT ua.id, ua.account_code, ua.account_name_ar, 
               ua.account_name_ar as name,
               ua.account_name_ar as account_name,
               CONCAT(ua.account_code, ' - ', ua.account_name_ar) as display_name
        FROM unified_accounts ua
        WHERE (ua.account_code LIKE '11101%' OR ua.account_code LIKE '11102%')
        AND ua.account_status = 'active'
        AND ua.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
        ORDER BY ua.account_code
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * تصفية الحسابات لتكون فقط الحسابات التشغيلية (لا تحتوي على أبناء)
 */
function filter_postable_accounts($accounts, $pdo) {
    // جلب جميع الأرقام id التي تُستخدم كـ parent_id
    $stmt = $pdo->prepare("SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL");
    $stmt->execute();
    $parent_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // تصفية الحسابات التي ليست في parent_ids
    return array_values(array_filter($accounts, function($account) use ($parent_ids) {
        return !in_array($account['id'], $parent_ids);
    }));
}

/**
 * التحقق التلقائي من وجود أعمدة العملات والحدود المالية
 */
function ensure_multi_currency_columns($pdo)
{
    try {
        $table = 'account_balances_unified';
        $columns = [
            'currency_code' => "VARCHAR(10) AFTER currency_id",
            'credit_limit' => "DECIMAL(18,2) DEFAULT 0.00 AFTER current_balance",
            'debit_limit' => "DECIMAL(18,2) DEFAULT 0.00 AFTER credit_limit"
        ];

        foreach ($columns as $col => $def) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $stmt->execute([$table, $col]);
            if ($stmt->fetchColumn() == 0) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            }
        }

        if ($check_index->fetchColumn() == 0) {
            $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `acc_curr_code` (account_id, currency_code)");
        }
    } catch (Exception $e) {
        // فشل صامت في البيئة الحية لتجنب تعطل النظام
    }
}

/**
 * جلب معرفات الفواتير المرتبطة بمعاملة مالية محددة
 */
function php_get_transaction_invoice_ids($pdo, $transaction_id)
{
    $stmt = $pdo->prepare("SELECT invoice_id FROM payment_allocations WHERE financial_transaction_id = ? AND invoice_id IS NOT NULL");
    $stmt->execute([$transaction_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * إعادة حساب المبالغ المدفوعة وحالة الدفع لفاتورة محددة
 */
function php_recalculate_invoice_payment($pdo, $invoice_id)
{
    if (!$invoice_id) {
        return;
    }
    
    try {
        // جلب المبلغ الإجمالي للفاتورة
        $stmt_inv = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = ?");
        $stmt_inv->execute([$invoice_id]);
        $total_amount = $stmt_inv->fetchColumn();
        if ($total_amount === false) {
            return;
        }
        
        // حساب إجمالي المبالغ المدفوعة
        $stmt_paid = $pdo->prepare("
            SELECT COALESCE(SUM(pa.amount), 0)
            FROM payment_allocations pa
            JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
            WHERE pa.invoice_id = ? AND ft.status IN ('draft', 'posted')
        ");
        $stmt_paid->execute([$invoice_id]);
        $amount_received = $stmt_paid->fetchColumn();
        
        // تحديث الحالة
        $payment_status = 'unpaid';
        if ($amount_received >= $total_amount) {
            $payment_status = 'paid';
        } elseif ($amount_received > 0) {
            $payment_status = 'partially_paid';
        }
        
        $pdo->prepare("
            UPDATE invoices
            SET amount_received = ?, payment_status = ?
            WHERE id = ?
        ")->execute([$amount_received, $payment_status, $invoice_id]);
    } catch (Exception $e) {
        // تجاهل الأخطاء بهدف عدم تعطل النظام
    }
}

/**
 * إعادة حساب المبالغ المدفوعة وحالة الدفع لمجموعة من الفواتير
 */
function php_recalculate_invoice_payments($pdo, $invoice_ids)
{
    if (empty($invoice_ids)) {
        return;
    }
    
    foreach ($invoice_ids as $invoice_id) {
        php_recalculate_invoice_payment($pdo, $invoice_id);
    }
}

/**
 * حذف حركة مالية مع عكس أثرها على الأرصدة (منطق متوافق مع حذف السندات بالقيد العكسي).
 */
function php_delete_financial_transaction_and_reverse($pdo, $transaction_id)
{
    $transaction_id = (int)$transaction_id;
    if ($transaction_id < 1) {
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$transaction_id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        return;
    }

    $invoice_ids = php_get_transaction_invoice_ids($pdo, $transaction_id);

    if ($voucher['status'] === 'posted') {
        // 1. جلب أسطر القيد الأصلية
        $stmt_lines = $pdo->prepare("SELECT * FROM journal_lines WHERE financial_transaction_id = ?");
        $stmt_lines->execute([$transaction_id]);
        $original_lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($original_lines)) {
            $user_id = $_SESSION['admin_id'] ?? 1;
            $rev_type = $voucher['transaction_type'];
            $rev_number = fn_get_next_sequence($pdo, $rev_type);

            // 2. إنشاء معاملة عكسية جديدة
            $stmt_rev = $pdo->prepare("
                INSERT INTO financial_transactions (
                    transaction_number, transaction_date, branch_id,
                    transaction_type, entity_type, entity_id,
                    amount, currency_id, exchange_rate,
                    cash_bank_account_id, party_account_id,
                    cost_center_id, reference_number,
                    description, created_by, status,
                    reference_type, reference_id
                ) VALUES (
                    ?, CURDATE(), ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, 'posted',
                    'reversal', ?
                )
            ");
            $rev_desc = 'قيد عكسي للإلغاء | رقم المعاملة الأصلية: ' . $voucher['transaction_number'];
            $stmt_rev->execute([
                $rev_number,
                $voucher['branch_id'],
                $rev_type,
                $voucher['entity_type'],
                $voucher['entity_id'],
                $voucher['amount'],
                $voucher['currency_id'],
                $voucher['exchange_rate'] ?? 1,
                $voucher['cash_bank_account_id'],
                $voucher['party_account_id'],
                $voucher['cost_center_id'],
                $voucher['reference_number'],
                $rev_desc,
                $user_id,
                $transaction_id,
            ]);
            $reversal_id = $pdo->lastInsertId();

            // 3. إنشاء أسطر القيد العكسية (عكس المدين والدائن)
            foreach ($original_lines as $line) {
                $pdo->prepare("
                    INSERT INTO journal_lines
                        (financial_transaction_id, account_id, debit, credit,
                         currency_id, branch_id, cost_center_id, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $reversal_id,
                    $line['account_id'],
                    $line['credit'],   // Credit becomes debit
                    $line['debit'],    // Debit becomes credit
                    $line['currency_id'],
                    $line['branch_id'],
                    $line['cost_center_id'] ?? null,
                    'قيد عكسي: ' . ($line['description'] ?? ''),
                ]);
            }

            if (!balances_triggers_enabled($pdo)) {
                apply_transaction_balances($pdo, (int)$reversal_id, 1);
            }
        } else {
            if (!balances_triggers_enabled($pdo)) {
                apply_transaction_balances($pdo, (int)$transaction_id, -1);
            }
        }

        // 4. تحديث حالة المعاملة الأصلية إلى cancelled بدلاً من حذفها
        $user_id = $_SESSION['admin_id'] ?? 1;
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $pdo->prepare("
            UPDATE financial_transactions
            SET status = 'cancelled',
                cancelled_at = NOW(),
                cancelled_by = ?,
                cancelled_ip = ?,
                cancellation_reason = 'إلغاء وعكس العملية المحاسبية'
            WHERE id = ?
        ")->execute([$user_id, $user_ip, $transaction_id]);

    } else {
        // إذا لم تكن مرحّلة (draft)، نقوم بالحذف المباشر لأسطر القيد والتوزيعات والمعاملة
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$transaction_id]);
        $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$transaction_id]);
        $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$transaction_id]);
    }

    php_recalculate_invoice_payments($pdo, $invoice_ids);
}

/**
 * إنشاء أسطر القيد لحركة مالية موجودة مسبقاً وتحديث الأرصدة
 */
function php_create_journal_lines(
    $pdo,
    $transaction_id,
    $debit_account_id,
    $credit_account_id,
    $amount,
    $currency_id,
    $description,
    $cost_center_id = null
) {
    try {
        // 1. إنشاء أسطر القيد (journal_lines)
        $stmt_line = $pdo->prepare("INSERT INTO journal_lines
            (financial_transaction_id, account_id, debit, credit, currency_id, description, cost_center_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        // الطرف المدين
        if ($debit_account_id) {
            $stmt_line->execute([$transaction_id, $debit_account_id, $amount, 0, $currency_id, $description, $cost_center_id]);
        }

        // الطرف الدائن
        if ($credit_account_id) {
            $stmt_line->execute([$transaction_id, $credit_account_id, 0, $amount, $currency_id, $description, $cost_center_id]);
        }

        return true;
    } catch (Exception $e) {
        error_log("Error in php_create_journal_lines: " . $e->getMessage());
        throw $e;
    }
}

/**
 * إنشاء قيد مالي يدوي أو آلي (طرف مدين وطرف دائن)
 */
function php_create_financial_entry(
    $pdo,
    $date,
    $type,
    $entity_type,
    $entity_id,
    $debit_account_id,
    $credit_account_id,
    $amount,
    $currency_id,
    $description,
    $user_id,
    $branch_id = null,
    $agent_id = null,
    $cost_center_id = null,
    $ref_type = null,
    $ref_id = null,
    $use_outer_transaction = false
) {
    try {
        if (!$use_outer_transaction) {
            $pdo->beginTransaction();
        }

        // 1. توليد رقم العملية
        $stmt_seq = $pdo->prepare("SELECT IFNULL(MAX(id), 0) + 1 as seq FROM financial_transactions");
        $stmt_seq->execute();
        $trx_number = 'JRN-' . str_pad($stmt_seq->fetchColumn(), 6, '0', STR_PAD_LEFT);

        // 2. إنشاء رأس العملية (financial_transactions)
        $stmt_trx = $pdo->prepare("INSERT INTO financial_transactions
            (transaction_number, transaction_date, branch_id, transaction_type, status,
             entity_type, entity_id, currency_id, amount, reference_type, reference_id,
             description, created_by, posted_at, posted_by, cost_center_id, created_at)
            VALUES (?, ?, ?, ?, 'posted', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW())");

        $stmt_trx->execute([
            $trx_number,
            $date,
            $branch_id,
            $type,
            $entity_type,
            $entity_id,
            $currency_id,
            $amount,
            $ref_type,
            $ref_id,
            $description,
            $user_id,
            $user_id,
            $cost_center_id
        ]);

        $transaction_id = $pdo->lastInsertId();

        // 3. إنشاء أسطر القيد (journal_lines)
        $stmt_line = $pdo->prepare("INSERT INTO journal_lines
            (financial_transaction_id, account_id, debit, credit, currency_id, description, cost_center_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        // الطرف المدين
        if ($debit_account_id) {
            $stmt_line->execute([$transaction_id, $debit_account_id, $amount, 0, $currency_id, $description, $cost_center_id]);
        }

        // الطرف الدائن
        if ($credit_account_id) {
            $stmt_line->execute([$transaction_id, $credit_account_id, 0, $amount, $currency_id, $description, $cost_center_id]);
        }

        if (!$use_outer_transaction) {
            $pdo->commit();
        }
        return $transaction_id;
    } catch (Exception $e) {
        if (!$use_outer_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in php_create_financial_entry: " . $e->getMessage());
        // عرض الخطأ للمستخدم في البيئة الحالية لتسهيل التشخيص
        if (isset($_SESSION['admin_id'])) {
            echo "<script>console.error('Accounting Error: " . addslashes($e->getMessage()) . "');</script>";
        }
        if ($use_outer_transaction) {
            throw $e;
        }
        return false;
    }
}



function php_post_receipt_voucher($pdo, $voucher_id, $user_id) {
    // 1. Get the voucher details
    $stmt_voucher = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt_voucher->execute([$voucher_id]);
    $voucher = $stmt_voucher->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        throw new Exception("السند غير موجود");
    }

    if ($voucher['status'] === 'cancelled') {
        throw new Exception("لا يمكن ترحيل سند ملغي");
    }

    $stmt_lines = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE financial_transaction_id = ?");
    $stmt_lines->execute([$voucher_id]);
    $existing_lines_count = (int)$stmt_lines->fetchColumn();

    if ($voucher['status'] === 'posted' && $existing_lines_count > 0) {
        return true;
    }

    // 2. Create journal lines: Debit cash/bank account, Credit party account
    if ($existing_lines_count === 0) {
        php_create_journal_lines(
            $pdo,
            $voucher_id,
            $voucher['cash_bank_account_id'],
            $voucher['party_account_id'],
            $voucher['amount'],
            $voucher['currency_id'],
            $voucher['description'] ?? '',
            $voucher['cost_center_id']
        );
    }

    // 3. Update voucher status to posted
    $stmt_update = $pdo->prepare("
        UPDATE financial_transactions 
        SET status = 'posted', 
            posted_by = ?, 
            posted_at = NOW(), 
            posted_ip = ?
        WHERE id = ?
          AND status = 'draft'
    ");
    $stmt_update->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $voucher_id]);

    // 3.5 التحقق من توازن القيد قبل تطبيق الأرصدة
    validate_journal_balance($pdo, (int)$voucher_id);

    if (!balances_triggers_enabled($pdo)) {
        apply_transaction_balances($pdo, (int)$voucher_id, 1);
    }

    // 4. Recalculate any linked invoices
    php_recalculate_invoice_payments($pdo, php_get_transaction_invoice_ids($pdo, $voucher_id));

    return true;
}

/**
 * توليد رقم تسلسلي آمن ذري باستخدام جدول sequence_numbers مع قفل الصف.
 * الصيغة: PREFIX-YY-NNNNN (مثال: RCT-26-00014)
 */
function fn_get_next_sequence($pdo, $type) {
    $prefixes = [
        'receipt'  => 'RCT',
        'payment'  => 'PMT',
        'invoice'  => 'INV',
        'purchase' => 'PUR',
        'journal'  => 'JRN',
        'exch'     => 'EXH',
    ];
    $seq_name = strtolower($type);
    $prefix   = $prefixes[$seq_name] ?? strtoupper(substr($type, 0, 3));
    $year     = date('y'); // السنة بخانتين

    try {
        // استخدام INSERT ... ON DUPLICATE KEY لتحديث ذري آمن
        $pdo->prepare("
            INSERT INTO sequence_numbers (sequence_name, last_number, year)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE
                last_number = IF(year = ?, last_number + 1, 1),
                year        = ?
        ")->execute([$seq_name, $year, $year, $year]);

        $stmt = $pdo->prepare("SELECT last_number FROM sequence_numbers WHERE sequence_name = ?");
        $stmt->execute([$seq_name]);
        $next = (int)$stmt->fetchColumn();

        return $prefix . '-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        // Fallback آمن: استخدام timestamp+type لضمان الفريدية
        error_log('fn_get_next_sequence fallback: ' . $e->getMessage());
        return $prefix . '-' . $year . '-' . str_pad(time() % 99999, 5, '0', STR_PAD_LEFT);
    }
}

/**
 * التحقق من توازن القيد المزدوج (SUM debit = SUM credit).
 * يرمي Exception إذا كان القيد غير متوازن — يُستدعى قبل COMMIT.
 */
function validate_journal_balance($pdo, $transaction_id) {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(debit),  0) AS total_debit,
            COALESCE(SUM(credit), 0) AS total_credit
        FROM journal_lines
        WHERE financial_transaction_id = ?
    ");
    $stmt->execute([$transaction_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $debit  = (float)($row['total_debit']  ?? 0);
    $credit = (float)($row['total_credit'] ?? 0);
    $diff   = abs($debit - $credit);

    if ($diff > 0.01) {
        throw new Exception(
            sprintf(
                'القيد غير متوازن (معرف: %d) | مدين: %.4f | دائن: %.4f | الفرق: %.4f',
                $transaction_id, $debit, $credit, $diff
            )
        );
    }

    return true;
}

function php_post_payment_voucher($pdo, $voucher_id, $user_id, $use_outer_transaction = false) {
    $voucher = null;
    $transaction_started = false;

    try {
        if (!$use_outer_transaction && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transaction_started = true;
        }

        // 1. Get the voucher details
        $stmt_voucher = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
        $stmt_voucher->execute([$voucher_id]);
        $voucher = $stmt_voucher->fetch(PDO::FETCH_ASSOC);
        if (!$voucher) {
            throw new Exception("السند غير موجود");
        }

        if ($voucher['status'] === 'cancelled') {
            throw new Exception("لا يمكن ترحيل سند ملغي");
        }

        // 1.5 إذا كان المصروف ولم يمر عبر الـ SP المركزي بعد
        // (معرفة من خلال عدم وجود أسطر اليومية أو أن status = approved)
        if (($voucher['transaction_type'] === 'expense') && $voucher['status'] === 'approved') {
            try {
                $stmt_sp = $pdo->prepare("CALL sp_post_expense_voucher(?, ?)");
                $stmt_sp->execute([$voucher_id, $user_id]);
                $stmt_sp->closeCursor();
                while ($stmt_sp->nextRowset()) { /* noop */ }
                if ($transaction_started && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return true;
            } catch (Exception $e) {
                // إذا فشل الـ SP المركزي، نكمل بالطريقة PHP العادية
                error_log("sp_post_expense_voucher fell back: " . $e->getMessage());
            }
        }

        $stmt_lines = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE financial_transaction_id = ?");
        $stmt_lines->execute([$voucher_id]);
        $existing_lines_count = (int)$stmt_lines->fetchColumn();

        if ($voucher['status'] === 'posted' && $existing_lines_count > 0) {
            if ($transaction_started && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return true;
        }

        // 2. Create journal lines: Credit cash/bank account, Debit party account
        if ($existing_lines_count === 0) {
            php_create_journal_lines(
                $pdo,
                $voucher_id,
                $voucher['party_account_id'],
                $voucher['cash_bank_account_id'],
                $voucher['amount'],
                $voucher['currency_id'],
                $voucher['description'] ?? '',
                $voucher['cost_center_id']
            );
        }

        // 3. Update voucher status to posted
        $stmt_update = $pdo->prepare("
            UPDATE financial_transactions 
            SET status = 'posted', 
                posted_by = ?, 
                posted_at = NOW(), 
                posted_ip = ?
            WHERE id = ?
              AND status IN ('draft','approved','pending_approval')
        ");
        $stmt_update->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $voucher_id]);

        // 3.5 التحقق من توازن القيد قبل تطبيق الأرصدة
        validate_journal_balance($pdo, (int)$voucher_id);

        if (!balances_triggers_enabled($pdo)) {
            apply_transaction_balances($pdo, (int)$voucher_id, 1);
        }

        // 4. Recalculate any linked invoices
        php_recalculate_invoice_payments($pdo, php_get_transaction_invoice_ids($pdo, $voucher_id));

        if ($transaction_started && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return true;
    } catch (Exception $e) {
        if ($transaction_started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("php_post_payment_voucher error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * جلب تسمية حالة الحساب (نشط، خامل، مغلق)
 */
function get_account_status_label($status)
{

    switch ($status) {
        case 'active':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3">نشط</span>';
        case 'dormant':
        case 'inactive':
            return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3">خامل</span>';
        case 'closed':
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3">مغلق</span>';
        default:
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3">' . htmlspecialchars($status) . '</span>';
    }
}

/**
 * ترحيل فاتورة خدمة محاسبياً باستخدام الإجراء المخزن
 */
function php_post_service_invoice($pdo, $invoice_id, $posted_by)
{
    try {
        $stmt = $pdo->prepare("CALL sp_post_invoice(?, ?)");
        $stmt->execute([$invoice_id, $posted_by]);
        return true;
    } catch (Exception $e) {
        error_log("خطأ في ترحيل الفاتورة: " . $e->getMessage());
        return false;
    }
}

/**
 * جلب الحسابات المتاحة لكيان معين (مثلاً فئات المصاريف)
 */
function get_available_accounts_for_entity($entity_type)
{
    global $pdo;
    $parent_code = '';
    switch ($entity_type) {
        case 'expense_category':
            $parent_code = '501';
            break;
        case 'income_category':
            $parent_code = '401';
            break;
    }

    if (!$parent_code) return [];

    $stmt = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code LIKE ? AND account_code != ? ORDER BY account_code");
    $stmt->execute([$parent_code . '%', $parent_code]);
    return $stmt->fetchAll();
}

/**
 * جلب كود الحساب الأب لكيان معين
 */
function get_parent_account_code_by_entity($entity_type)
{
    switch ($entity_type) {
        case 'expense_category':
            return '501';
        case 'income_category':
            return '401';
        default:
            return null;
    }
}

/**
 * إنشاء حساب فرعي جديد
 */
function create_sub_account($parent_code, $account_name, $entity_id = null, $entity_type = null)
{
    global $pdo;
    try {
        $stmt_parent = $pdo->prepare("SELECT id, account_type, normal_balance FROM unified_accounts WHERE account_code = ?");
        $stmt_parent->execute([$parent_code]);
        $parent = $stmt_parent->fetch();

        if (!$parent) return false;

        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ?");
        $stmt_last->execute([$parent['id']]);
        $last_code = $stmt_last->fetchColumn();

        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = $parent_code . '001';
        }

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$new_code, $account_name, $parent['account_type'], $parent['normal_balance'], $parent['id']]);

        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error in create_sub_account: " . $e->getMessage());
        return false;
    }
}

/**
 * ترحيل الخدمات للنظام الموحد
 * محدث: يقرأ الأسعار من service_prices بدلاً من الأعمدة المحذوفة
 */
function post_service_to_unified($pdo, $type, $id, $user_id)
{
    if ($type == 'passport') {
        // جلب بيانات الجواز الأساسية
        $stmt = $pdo->prepare("SELECT p.*, s.id as service_id
                              FROM passports p
                              LEFT JOIN services s ON s.service_key = p.transaction_type
                              WHERE p.id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();

        if (!$p || $p['invoice_id']) return;

        // جلب بيانات التسعير من جدول service_prices
        $price_stmt = $pdo->prepare("SELECT sp.*, c.id as currency_id
                                     FROM service_prices sp
                                     LEFT JOIN currencies c ON c.id = sp.currency_id
                                     WHERE sp.service_id = ?
                                     AND (sp.branch_id = ? OR sp.agent_id = ?)
                                     ORDER BY sp.created_at DESC
                                     LIMIT 1");
        $price_stmt->execute([$p['service_id'], $p['branch_id'], $p['agent_id']]);
        $price_data = $price_stmt->fetch();

        // إذا لم يوجد تسعير محدد، استخدم العملة الافتراضية والقيم الصفرية
        $currency_id = $price_data['currency_id'] ?? 1; // العملة الافتراضية
        $sale_price = $price_data['sale_price'] ?? 0;
        $purchase_price = $price_data['purchase_price'] ?? 0;

        $inv_id = php_create_invoice_and_post(
            $pdo,
            'sales',
            $p['branch_id'],
            'passport',
            $p['id'],
            $p['customer_id'],
            $currency_id,
            $sale_price,
            0, // discount
            $purchase_price,
            'draft',
            "فاتورة جواز: " . $p['full_name'],
            $user_id
        );

        $pdo->prepare("UPDATE passports SET invoice_id = ? WHERE id = ?")
            ->execute([$inv_id, $id]);
    }
    // يمكن إضافة الحالات الأخرى هنا (bus_flight, family_visit)
}
