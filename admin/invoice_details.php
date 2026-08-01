<?php
ob_start();
/** @var bool $is_admin */
/** @var string $user_role */
/** @var array $settings */
require_once 'header.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return_to = !empty($_GET['return_to']) ? $_GET['return_to'] : 'invoices.php';

// 1. جلب بيانات الفاتورة الأساسية المطلوبة
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$main_inv = $stmt->fetch();

if (!$main_inv) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger'>الفاتورة غير موجودة</div><a href='" . htmlspecialchars($return_to) . "' class='btn btn-primary'>العودة للقائمة</a></div>";
    require_once 'footer.php';
    exit;
}

// جلب سجل العمليات للفاتورة
$audit_logs = [];
try {
    $stmt_audit = $pdo->prepare("SELECT * FROM audit_logs WHERE table_name = 'invoices' AND record_id = ? ORDER BY created_at DESC");
    $stmt_audit->execute([$invoice_id]);
    $audit_logs = $stmt_audit->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// جلب أسماء المستخدمين للسجل
$audit_user_names = [];
if (!empty($audit_logs)) {
    $audit_uids = [];
    foreach ($audit_logs as $alog) {
        if ($alog['user_id']) $audit_uids[] = $alog['user_id'];
    }
    $audit_uids = array_unique($audit_uids);
    if (!empty($audit_uids)) {
        $u_placeholders = implode(',', array_fill(0, count($audit_uids), '?'));
        $stmt_u = $pdo->prepare("SELECT id, full_name, username FROM users WHERE id IN ($u_placeholders)");
        $stmt_u->execute(array_values($audit_uids));
        $users_res = $stmt_u->fetchAll();
        foreach ($users_res as $u) {
            $audit_user_names[$u['id']] = $u['full_name'] ?: $u['username'];
        }
    }
}

$remaining_amount = $main_inv['net_amount'] - $main_inv['amount_received'];

// جلب تفاصيل الخدمة المرتبطة
function getServiceDetails($pdo, $type, $id)
{
    if (empty($id)) return null;

    if (
        $type == 'Passport'
        || $type == 'umrah'
        || $type == 'hajj'
        || is_umrah_service($type)
        || is_hajj_service($type)
        || $type == 'FamilyVisit'
        || $type == 'passport_transaction'
    ) {
        $stmt = $pdo->prepare("SELECT full_name, passport_number FROM passport_transactions WHERE id = ?");
        $stmt->execute([$id]);
        $res = $stmt->fetch();

        // Fallback to old passports table if not found in new table
        if (!$res) {
            $stmt = $pdo->prepare("SELECT full_name, passport_number FROM passports WHERE id = ?");
            $stmt->execute([$id]);
            $res = $stmt->fetch();
        }
        return $res;
    } elseif ($type === 'تذاكر طيران وبصات') {
        $stmt = $pdo->prepare("SELECT traveler_name as full_name, booking_number as passport_number FROM bus_flight_bookings WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } elseif (is_postal_service($type) || $type === 'postal') {
        $stmt = $pdo->prepare("
            SELECT CONCAT(sender_full_name, ' -> ', recipient_full_name) AS full_name,
                   tracking_number AS passport_number
            FROM postal_shipments
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    return null;
}

// 3. دالة لجلب تفاصيل فاتورة كاملة مع حساباتها وسدادها
function getFullInvoiceData($pdo, $inv_num)
{
    $stmt = $pdo->prepare("
        SELECT i.*, b.branch_name, c.currency_name, c.currency_symbol, c.exchange_rate,
               u.full_name as creator_name,
               p.full_name as poster_name,
               coa_pay.account_name_ar as payment_account_name
        FROM invoices i
        JOIN branches b ON i.branch_id = b.id
        JOIN currencies c ON i.currency_id = c.id
        LEFT JOIN users u ON i.created_by = u.id
        LEFT JOIN users p ON i.posted_by = p.id
        LEFT JOIN unified_accounts coa_pay ON i.account_id = coa_pay.id
        WHERE i.invoice_number = ?
    ");
    $stmt->execute([$inv_num]);
    $inv = $stmt->fetch();

    if (!$inv) return null;

    $is_sales_invoice = ($inv['invoice_category'] == 'sales');
    $target_transaction_type = $is_sales_invoice ? 'receipt' : 'payment';

    // 1. حساب المبلغ الابتدائي (من القيد المحاسبي المباشر للفاتورة)
    $stmt_init = $pdo->prepare("
        SELECT ft.id, ft.transaction_number, ft.transaction_date, ft.posted_at, ft.amount, u.full_name as creator_name
        FROM financial_transactions ft
        LEFT JOIN users u ON ft.created_by = u.id
        WHERE ft.reference_id = ? AND ft.reference_type = 'invoice' AND ft.status = 'posted'
        LIMIT 1
    ");
    $stmt_init->execute([$inv['id']]);
    $init_ft = $stmt_init->fetch();

    // المبلغ الابتدائي هو ما تم دفعه عند إنشاء الفاتورة: نستخدم amount_received من جدول الفواتير مباشرة
    $initial_paid = (float)$inv['amount_received'];

    // 2. جلب سجل التحصيلات/المدفوعات اللاحقة
    // لفواتير البيع: سندات قبض (receipt)
    // لفواتير الشراء: سندات صرف (payment)
    $stmt_payments = $pdo->prepare("
        SELECT 
            COALESCE(pa.allocated_amount, ft.amount) as allocated_amount,
            ft.transaction_number,
            ft.transaction_date,
            ft.posted_at,
            u.full_name as payment_by,
            ft.status as payment_status,
            ft.id as voucher_id,
            ft.amount as payment_total_amount,
            curr.currency_symbol as payment_currency_symbol,
            ? as transaction_type
        FROM financial_transactions ft
        LEFT JOIN (
            SELECT 
                financial_transaction_id,
                invoice_id,
                SUM(allocated_amount) as allocated_amount
            FROM payment_allocations
            GROUP BY financial_transaction_id, invoice_id
        ) pa ON ft.id = pa.financial_transaction_id AND pa.invoice_id = ?
        LEFT JOIN currencies curr ON ft.currency_id = curr.id
        LEFT JOIN users u ON ft.created_by = u.id
        WHERE (
               pa.invoice_id = ?
               OR (ft.reference_id = ? AND ft.reference_type = ? AND ft.transaction_type = ?)
              )
        AND ft.status IN ('posted', 'draft')
        AND ft.transaction_type = ?
        GROUP BY ft.id
        ORDER BY ft.transaction_date DESC
    ");
    $stmt_payments->execute([
        $target_transaction_type,
        $inv['id'],
        $inv['id'],
        $inv['source_id'],
        $inv['source_type'],
        $target_transaction_type,
        $target_transaction_type
    ]);
    $payments_res = $stmt_payments->fetchAll();

    // إزالة التكرارات (تأكيد إضافي)
    $seen_vouchers = [];
    $unique_payments = [];
    foreach ($payments_res as $p) {
        $voucher_id = $p['voucher_id'];
        if (!in_array($voucher_id, $seen_vouchers)) {
            $seen_vouchers[] = $voucher_id;
            $unique_payments[] = $p;
        }
    }
    $payments_res = $unique_payments;

        // تصحيح المبالغ الموزعة للسندات المرتبطة بالمصدر مباشرة
        foreach ($payments_res as &$p) {
            if (empty($p['allocated_amount'])) {
                $p['allocated_amount'] = $p['payment_total_amount'];
            }
        }

        $inv['payments'] = $payments_res;

        // 3. إضافة السداد الابتدائي إلى القائمة فقط إذا لم يكن موجودًا بالفعل في payments
        $initial_already_exists = false;
        foreach ($inv['payments'] as $p) {
            if ($p['voucher_id'] == $init_ft['id']) {
                $initial_already_exists = true;
                break;
            }
        }

        if ($initial_paid > 0 && $init_ft && !$initial_already_exists) {
            array_unshift($inv['payments'], [
                'allocated_amount' => $initial_paid,
                'transaction_number' => $init_ft['transaction_number'],
                'transaction_date' => $init_ft['transaction_date'],
                'posted_at' => $init_ft['posted_at'],
                'payment_by' => $init_ft['creator_name'] ?? $inv['creator_name'],
                'payment_status' => 'posted',
                'voucher_id' => $init_ft['id'],
                'payment_total_amount' => $init_ft['amount'],
                'payment_currency_symbol' => $inv['currency_symbol'],
                'transaction_type' => 'initial'
            ]);
        }

    // 4. الإجمالي المستلم = الابتدائي + اللاحق (المرحل وغير المرحل)
    $extra_received = 0;
    $extra_received_posted = 0;
    $extra_received_unposted = 0;

    foreach($inv['payments'] as $p) {
        if ($p['transaction_type'] != 'initial') {
            $extra_received += (float)$p['allocated_amount'];
            if ($p['payment_status'] == 'posted') {
                $extra_received_posted += (float)$p['allocated_amount'];
            } else {
                $extra_received_unposted += (float)$p['allocated_amount'];
            }
        }
    }
    $inv['calculated_amount_received'] = $initial_paid + $extra_received_posted;
    $inv['total_allocated_including_unposted'] = $initial_paid + $extra_received;

    // تحديد الجهة (عميل/مورد/وكيل/حساب) بناءً على نوع الفاتورة
    if ($inv['invoice_category'] == 'sales') {
        if ($inv['customer_id']) {
            $inv['party_type'] = 'customer';
            $inv['party_id'] = $inv['customer_id'];
        } elseif ($inv['agent_id']) {
            $inv['party_type'] = 'agent';
            $inv['party_id'] = $inv['agent_id'];
        } elseif ($inv['delivery_type'] == 'cash' || $inv['delivery_type'] == 'bank_transfer' || $inv['delivery_type'] == 'draft') {
            // للفواتير النقدية التي بها متبقي، نوجه السداد لحساب العملاء العام (1121)
            $stmt_coa = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code LIKE '1121%' ORDER BY account_code ASC LIMIT 1");
            $stmt_coa->execute();
            $coa_id = $stmt_coa->fetchColumn();

            if ($coa_id) {
                $inv['party_type'] = 'account';
                $inv['party_id'] = $coa_id;
            } else {
                $inv['party_type'] = 'account';
                $inv['party_id'] = $inv['account_id'];
            }
        } elseif ($inv['account_id']) {
            $inv['party_type'] = 'account';
            $inv['party_id'] = $inv['account_id'];
        } else {
            $inv['party_type'] = 'unknown_sales_party';
            $inv['party_id'] = 0;
        }
    } elseif ($inv['invoice_category'] == 'purchase') {
        if ($inv['supplier_id']) {
            $inv['party_type'] = 'supplier';
            $inv['party_id'] = $inv['supplier_id'];
        } elseif ($inv['account_id']) {
            $inv['party_type'] = 'account';
            $inv['party_id'] = $inv['account_id'];
        } else {
            $inv['party_type'] = 'unknown_purchase_party';
            $inv['party_id'] = 0;
        }
    } else {
        if ($inv['customer_id']) {
            $inv['party_type'] = 'customer';
            $inv['party_id'] = $inv['customer_id'];
        } elseif ($inv['agent_id']) {
            $inv['party_type'] = 'agent';
            $inv['party_id'] = $inv['agent_id'];
        } elseif ($inv['supplier_id']) {
            $inv['party_type'] = 'supplier';
            $inv['party_id'] = $inv['supplier_id'];
        } elseif ($inv['account_id']) {
            $inv['party_type'] = 'account';
            $inv['party_id'] = $inv['account_id'];
        } else {
            $inv['party_type'] = 'branch';
            $inv['party_id'] = $inv['branch_id'];
        }
    }

    $inv['party_name'] = getPartyNameV2($pdo, $inv['party_type'], $inv['party_id']);

    return $inv;
}

function getPartyNameV2($pdo, $type, $id)
{
    if (!$type) return "غير محدد";

    $table_map = [
        'customer' => 'customers',
        'agent' => 'agents',
        'supplier' => 'suppliers',
        'branch' => 'branches',
        'account' => 'unified_accounts'
    ];
    $name_col = [
        'customer' => 'full_name',
        'agent' => 'agent_name',
        'supplier' => 'supplier_name',
        'branch' => 'branch_name',
        'account' => 'account_name_ar'
    ];

    if ($type === 'unknown_sales_party') {
        return "عميل غير محدد";
    } elseif ($type === 'unknown_purchase_party') {
        return "مورد غير محدد";
    } elseif (!isset($table_map[$type])) {
        return "جهة غير معروفة";
    }

    if (!$id) return "غير محدد";

    $stmt = $pdo->prepare("SELECT " . $name_col[$type] . " FROM " . $table_map[$type] . " WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: "غير موجود";
}

// 2. تحديد أرقام فواتير البيع والشراء المرتبطة ديناميكياً بناءً على إعدادات الخدمة
$inv_config = getServiceInvoiceConfig($main_inv['source_type'], $settings);
$s_pref = $inv_config['sales_prefix'];
$p_pref = $inv_config['purchase_prefix'];

// استخراج الرقم التسلسلي بطريقة تدعم التنسيقات القديمة والجديدة
$numeric_suffix = preg_replace('/^[A-Z-]+/', '', $main_inv['invoice_number']);

// محاولة جلب الفواتير المرتبطة (الأفضل عبر source_id إذا كان موجوداً)
$sale_inv = null;
$pur_inv = null;

if (!empty($main_inv['source_id']) && $main_inv['source_id'] != 0) {
    $stmt_link = $pdo->prepare("SELECT invoice_number FROM invoices WHERE source_type = ? AND source_id = ? AND invoice_category = 'sales' LIMIT 1");
    $stmt_link->execute([$main_inv['source_type'], $main_inv['source_id']]);
    $sale_num = $stmt_link->fetchColumn();

    $stmt_link = $pdo->prepare("SELECT invoice_number FROM invoices WHERE source_type = ? AND source_id = ? AND invoice_category = 'purchase' LIMIT 1");
    $stmt_link->execute([$main_inv['source_type'], $main_inv['source_id']]);
    $pur_num = $stmt_link->fetchColumn();
} else {
    // Fallback للربط عبر الرقم التسلسلي (للسجلات التي ليس لها source_id)
    $sale_num = $s_pref . $numeric_suffix;
    $pur_num = $p_pref . $numeric_suffix;
}

$sale_inv = getFullInvoiceData($pdo, $sale_num ?: ($s_pref . $numeric_suffix));
$pur_inv = getFullInvoiceData($pdo, $pur_num ?: ($p_pref . $numeric_suffix));

// Fallback نهائي للسجلات القديمة SAL-/PUR- أو SI-/PI-
if (!$sale_inv) $sale_inv = getFullInvoiceData($pdo, "SI-" . $numeric_suffix);
if (!$sale_inv) $sale_inv = getFullInvoiceData($pdo, "SAL-" . $numeric_suffix);
if (!$pur_inv) $pur_inv = getFullInvoiceData($pdo, "PI-" . $numeric_suffix);
if (!$pur_inv) $pur_inv = getFullInvoiceData($pdo, "PUR-" . $numeric_suffix);

// إذا لم نجد شيئاً وكان المستند الحالي هو الفاتورة
if (!$sale_inv && $main_inv['invoice_category'] == 'sales') $sale_inv = getFullInvoiceData($pdo, $main_inv['invoice_number']);
if (!$pur_inv && $main_inv['invoice_category'] == 'purchase') $pur_inv = getFullInvoiceData($pdo, $main_inv['invoice_number']);

// جلب تفاصيل الخدمة (من أي منهما)
$service_info = getServiceDetails($pdo, $main_inv['source_type'], $main_inv['source_id']);

// جلب تفاصيل القيد المحاسبي (Revenue, Cost, Profit Accounts)
function getJournalEntryDetails($pdo, $sale_id, $pur_id)
{
    $ids = array_filter([(int)$sale_id, (int)$pur_id]);
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT
            ft.id as transaction_id,
            ft.transaction_number,
            ft.transaction_date,
            ft.amount as total_amount,
            ft.status,
            coa.account_code,
            coa.account_name_ar,
            jl.debit,
            jl.credit,
            jl.currency_id,
            curr.currency_symbol,
            coa.account_type
        FROM financial_transactions ft
        JOIN journal_lines jl ON ft.id = jl.financial_transaction_id
        JOIN unified_accounts coa ON jl.account_id = coa.id
        LEFT JOIN currencies curr ON jl.currency_id = curr.id
        WHERE ft.reference_type = 'invoice'
        AND ft.reference_id IN ($placeholders)
        AND ft.status = 'posted'
        ORDER BY ft.transaction_date DESC, coa.account_code
    ");
    $stmt->execute(array_values($ids));
    return $stmt->fetchAll();
}

$journal_details = getJournalEntryDetails($pdo, $sale_inv['id'] ?? 0, $pur_inv['id'] ?? 0);

function normalize_account_type($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';

    $low = mb_strtolower($value, 'UTF-8');
    $map = [
        'revenue' => ['revenue', 'income', 'إيراد', 'ايراد', 'إيرادات', 'ايرادات'],
        'expense' => ['expense', 'cost', 'مصروف', 'مصروفات', 'تكلفة', 'تكاليف'],
        'box' => ['box', 'cash', 'صندوق', 'نقد'],
        'bank' => ['bank', 'بنك'],
        'receivable' => ['receivable', 'ar', 'العملاء', 'ذمم مدينة'],
        'payable' => ['payable', 'ap', 'الموردين', 'ذمم دائنة'],
    ];

    foreach ($map as $normalized => $aliases) {
        foreach ($aliases as $alias) {
            if (mb_strtolower((string)$alias, 'UTF-8') === $low) {
                return $normalized;
            }
        }
    }
    return $value;
}

// حساب المجاميع
$revenue_accounts = array_filter($journal_details, function ($j) {
    return normalize_account_type($j['account_type'] ?? '') === 'revenue';
});
$cost_accounts = array_filter($journal_details, function ($j) {
    return normalize_account_type($j['account_type'] ?? '') === 'expense';
});
$profit_accounts = array_filter($journal_details, function ($j) {
    // Look for accounts with "أرباح" in the name, or use the service's profit account
    // Alternatively, just get any revenue account that's not the main revenue account
    return normalize_account_type($j['account_type'] ?? '') === 'revenue' && !empty($j['credit']);
});
$cash_accounts = array_filter($journal_details, function ($j) {
    $t = normalize_account_type($j['account_type'] ?? '');
    return $t === 'box' || $t === 'bank';
});

$total_revenue = array_sum(array_column($revenue_accounts, 'credit'));
$total_cost = array_sum(array_column($cost_accounts, 'debit'));
$total_cash_received = array_sum(array_column($cash_accounts, 'debit'));

// Get service config to find profit account
$profit_account = null;
if ($main_inv) {
    $srv_config = getServiceInvoiceConfig($main_inv['source_type'], $settings);
    if (isset($srv_config['profit_account_id'])) {
        $stmt_profit_acc = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = ?");
        $stmt_profit_acc->execute([$srv_config['profit_account_id']]);
        $profit_account = $stmt_profit_acc->fetch();
    }
}

// Fallback للبيانات التقديرية إذا لم يتم الترحيل بعد (Draft)
if ($total_revenue == 0 && $sale_inv) {
    $total_revenue = (float)$sale_inv['total_amount'] - (float)$sale_inv['discount'];
}
if ($total_cost == 0 && $pur_inv) {
    $total_cost = (float)$pur_inv['total_amount'];
} elseif ($total_cost == 0 && $sale_inv && $sale_inv['cost_amount'] > 0) {
    $total_cost = (float)$sale_inv['cost_amount'];
}

if ($total_cash_received == 0) {
    $total_cash_received = ($sale_inv['calculated_amount_received'] ?? 0);
}

$net_diff = $total_revenue - $total_cost;
$net_profit = $net_diff > 0 ? $net_diff : 0;
$net_loss = $net_diff < 0 ? abs($net_diff) : 0;

// جلب الصناديق والعملات للمودال
$financial_accounts = $pdo->query("SELECT id, account_name_ar as account_name, account_code FROM unified_accounts WHERE (account_code LIKE '11101%' OR account_code LIKE '11102%') AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) AND is_active = 1")->fetchAll();
$currencies_list = $pdo->query("SELECT id, currency_name, currency_symbol, exchange_rate_buy, exchange_rate_sell, is_default FROM currencies WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <h3 class="fw-bold"><i class="fas fa-layer-group me-2 text-primary"></i> عرض تفاصيل العملية #<?php echo $numeric_suffix; ?></h3>
        <div>
            <?php if (($sale_inv && $sale_inv['invoice_status'] == 'posted') || ($pur_inv && $pur_inv['invoice_status'] == 'posted')): ?>
                <div class="dropdown d-inline-block me-2">
                    <button class="btn btn-warning rounded-pill px-4 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-undo me-2"></i> إلغاء الترحيل
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                        <li><h6 class="dropdown-header fw-bold">إعادة التعيين إلى مسودة</h6></li>
                        <?php if ($sale_inv && $sale_inv['invoice_status'] == 'posted' && $pur_inv && $pur_inv['invoice_status'] == 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('إلغاء ترحيل البيع والشراء معاً؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="reset_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $main_inv['id']; ?>">
                                    <input type="hidden" name="reset_type" value="all">
                                    <input type="hidden" name="linked_invoice_id" value="<?php echo $pur_inv['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-sync me-2 text-danger"></i> إلغاء ترحيل الكل</button>
                                </form>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <?php if ($sale_inv && $sale_inv['invoice_status'] == 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('إلغاء ترحيل فاتورة البيع؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="reset_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $sale_inv['id']; ?>">
                                    <input type="hidden" name="reset_type" value="sales">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-undo me-2 text-warning"></i> إلغاء ترحيل البيع</button>
                                </form>
                            </li>
                        <?php endif; ?>

                        <?php if ($pur_inv && $pur_inv['invoice_status'] == 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('إلغاء ترحيل فاتورة الشراء؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="reset_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $pur_inv['id']; ?>">
                                    <input type="hidden" name="reset_type" value="purchase">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-history me-2 text-secondary"></i> إلغاء ترحيل الشراء</button>
                                </form>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (($sale_inv && $sale_inv['invoice_status'] != 'posted') || ($pur_inv && $pur_inv['invoice_status'] != 'posted')): ?>
                <div class="dropdown d-inline-block me-2">
                    <button class="btn btn-success rounded-pill px-4 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-check-double me-2"></i> ترحيل الفاتورة
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                        <li><h6 class="dropdown-header fw-bold">ترحيل محاسبياً (Post)</h6></li>
                        <?php if ($sale_inv && $sale_inv['invoice_status'] != 'posted' && $pur_inv && $pur_inv['invoice_status'] != 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('ترحيل البيع والشراء معاً؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="post_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $main_inv['id']; ?>">
                                    <input type="hidden" name="post_scope" value="all">
                                    <input type="hidden" name="linked_invoice_id" value="<?php echo $pur_inv['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-check-double me-2 text-success"></i> ترحيل الكل</button>
                                </form>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <?php if ($sale_inv && $sale_inv['invoice_status'] != 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('ترحيل فاتورة البيع؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="post_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $sale_inv['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i> ترحيل البيع</button>
                                </form>
                            </li>
                        <?php endif; ?>

                        <?php if ($pur_inv && $pur_inv['invoice_status'] != 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('ترحيل فاتورة الشراء؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="post_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $pur_inv['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-file-invoice me-2 text-warning"></i> ترحيل الشراء</button>
                                </form>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (($sale_inv && $sale_inv['invoice_status'] != 'posted') || ($pur_inv && $pur_inv['invoice_status'] != 'posted')): ?>
                <div class="dropdown d-inline-block me-2">
                    <button class="btn btn-danger rounded-pill px-4 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-trash me-2"></i> حذف الفاتورة
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                        <li><h6 class="dropdown-header fw-bold">حذف نهائي (Delete)</h6></li>
                        <?php if ($sale_inv && $sale_inv['invoice_status'] != 'posted' && $pur_inv && $pur_inv['invoice_status'] != 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('حذف البيع والشراء معاً؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="delete_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $sale_inv['id']; ?>">
                                    <input type="hidden" name="delete_scope" value="both">
                                    <input type="hidden" name="linked_id" value="<?php echo $pur_inv['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2 text-danger"><i class="fas fa-trash-alt me-2"></i> حذف الكل</button>
                                </form>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>

                        <?php if ($sale_inv && $sale_inv['invoice_status'] != 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('حذف فاتورة البيع؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="delete_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $sale_inv['id']; ?>">
                                    <input type="hidden" name="delete_scope" value="self">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-trash me-2"></i> حذف البيع</button>
                                </form>
                            </li>
                        <?php endif; ?>

                        <?php if ($pur_inv && $pur_inv['invoice_status'] != 'posted'): ?>
                            <li>
                                <form method="post" action="invoices.php" class="mb-0" onsubmit="return confirm('حذف فاتورة الشراء؟')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="invoice_action" value="delete_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo $pur_inv['id']; ?>">
                                    <input type="hidden" name="delete_scope" value="self">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                                    <button type="submit" class="dropdown-item py-2"><i class="fas fa-trash me-2 text-warning"></i> حذف الشراء</button>
                                </form>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 me-2"><i class="fas fa-print me-2"></i> طباعة الكل</button>
            <a href="<?php echo htmlspecialchars($return_to); ?>" class="btn btn-light rounded-pill px-4 border">العودة للقائمة</a>
        </div>
    </div>

    <!-- ملخص العملية (الربح) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="fas fa-chart-bar text-primary me-2"></i> ملخص الحسابات (Financial Summary)</h5>
                    <div class="text-end">
                        <span class="badge bg-light text-primary rounded-pill px-3 py-2 border">
                            <i class="fas fa-tag me-1"></i>
                            <?php
                                $stype = $main_inv['source_type'];
                                if ($stype === 'passport_transaction') echo 'معاملة جوازات';
                                else echo $stype;
                            ?>
                        </span>
                        <div class="mt-2 small text-muted"><?php echo h(format_datetime_display($main_inv['invoice_date'])); ?></div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <!-- بطاقة الإيرادات -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 bg-success text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-file-invoice-dollar fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1">الإيرادات (Revenue)</small>
                                <h4 class="fw-bold mb-2"><?php echo number_format($total_revenue, 2); ?> <small class="fs-6"><?php echo $sale_inv['currency_symbol'] ?? ''; ?></small></h4>
                                <?php if ($sale_inv && $sale_inv['discount'] > 0): ?>
                                    <div class="p-1 bg-white bg-opacity-25 rounded-3 extra-small mb-1">
                                        شامل خصم: <?php echo number_format($sale_inv['discount'], 2); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($revenue_accounts)): ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small text-truncate">
                                        <?php echo htmlspecialchars($revenue_accounts[array_key_first($revenue_accounts)]['account_name_ar']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- بطاقة التكاليف -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 bg-danger text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-hand-holding-usd fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1">التكاليف (Cost)</small>
                                <h4 class="fw-bold mb-2"><?php echo number_format($total_cost, 2); ?> <small class="fs-6"><?php echo $sale_inv['currency_symbol'] ?? ''; ?></small></h4>
                                <?php if (!empty($cost_accounts)): ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small text-truncate">
                                        <?php echo htmlspecialchars($cost_accounts[array_key_first($cost_accounts)]['account_name_ar']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- بطاقة الصناديق (المبلغ الواصل) -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 bg-info text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-cash-register fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1">الواصل (الصناديق)</small>
                                <h4 class="fw-bold mb-2"><?php echo number_format($total_cash_received, 2); ?> <small class="fs-6"><?php echo $sale_inv['currency_symbol'] ?? ''; ?></small></h4>
                                <?php if (!empty($cash_accounts)): ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small text-truncate">
                                        <?php echo htmlspecialchars($cash_accounts[array_key_first($cash_accounts)]['account_name_ar']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="p-2 bg-white bg-opacity-25 rounded-3 small">
                                        لم يتم استلام مبالغ
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- بطاقة الأرباح -->
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 <?php echo $net_diff >= 0 ? 'bg-primary' : 'bg-dark'; ?> text-white">
                            <div class="card-body p-3 text-center">
                                <div class="mb-2"><i class="fas fa-chart-line fa-2x opacity-50"></i></div>
                                <small class="opacity-75 d-block mb-1"><?php echo $net_diff >= 0 ? 'الأرباح (Profit)' : 'الخسارة (Loss)'; ?></small>
                                <h4 class="fw-bold mb-2"><?php echo number_format(abs($net_diff), 2); ?> <small class="fs-6"><?php echo $sale_inv['currency_symbol'] ?? ''; ?></small></h4>
                                <div class="p-2 bg-white bg-opacity-25 rounded-3 small text-truncate">
                    <?php 
                    if ($net_diff >= 0 && $profit_account) {
                        echo htmlspecialchars($profit_account['account_name_ar']);
                    } else {
                        echo $net_diff >= 0 ? 'ربح صافي' : 'خسارة محققة';
                    }
                    ?>
                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- قسم حسابات الأرباح والتكاليف والإيرادات -->
    <?php if (!empty($journal_details) && $main_inv['invoice_status'] == 'posted'): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="mt-3">
                    <h5 class="fw-bold mb-3"><i class="fas fa-book me-2 text-primary"></i>تفاصيل القيود المحاسبية (Journal Entries)</h5>

                    <?php
                    $grouped_entries = [];
                    foreach ($journal_details as $jd) {
                        $grouped_entries[$jd['transaction_number']][] = $jd;
                    }

                    foreach ($grouped_entries as $trx_num => $lines):
                        $trx_debit = array_sum(array_column($lines, 'debit'));
                        $trx_credit = array_sum(array_column($lines, 'credit'));
                    ?>
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-light py-3 border-0 d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold">
                                    رقم القيد: <span class="badge bg-primary rounded-pill px-3"><?php echo $trx_num; ?></span>
                                    <span class="ms-3 text-muted small"><i class="far fa-calendar-alt me-1"></i><?php echo h(format_datetime_display($lines[0]['transaction_date'])); ?></span>
                                </h6>
                                <div class="small fw-bold">
                                    الحالة: <span class="text-success"><i class="fas fa-check-circle me-1"></i>مرحل</span>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 align-middle">
                                        <thead class="bg-light">
                                            <tr>
                                                <th class="ps-4" style="width: 40%;"><i class="fas fa-money-check-alt me-2"></i>الحساب</th>
                                                <th class="text-center"><i class="fas fa-tags me-2"></i>النوع</th>
                                                <th class="text-end" style="width: 15%;"><i class="fas fa-arrow-alt-circle-down me-2"></i>مدين (Debit)</th>
                                                <th class="text-end" style="width: 15%;"><i class="fas fa-arrow-alt-circle-up me-2"></i>دائن (Credit)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lines as $jd): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($jd['account_name_ar']); ?></div>
                                                        <small class="text-muted"><?php echo $jd['account_code']; ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php
                                                        $normalizedAccountType = normalize_account_type($jd['account_type'] ?? '');
                                                        $badge_color = match ($normalizedAccountType) {
                                                            'revenue' => 'success',
                                                            'expense' => 'danger',
                                                            'box', 'bank' => 'info',
                                                            'receivable' => 'primary',
                                                            'payable' => 'warning',
                                                            default => 'secondary'
                                                        };
                                                        $type_label = match ($normalizedAccountType) {
                                                            'revenue' => 'إيراد',
                                                            'expense' => 'تكلفة',
                                                            'box' => 'صندوق',
                                                            'bank' => 'بنك',
                                                            'receivable' => 'العملاء',
                                                            'payable' => 'الموردين',
                                                            default => $jd['account_code'] ?? 'أخرى'
                                                        };
                                                        ?>
                                                        <span class="badge bg-<?php echo $badge_color; ?> rounded-pill px-3 py-2">
                                                            <?php echo $type_label; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end fw-bold <?php echo $jd['debit'] > 0 ? 'text-primary' : 'text-muted'; ?>">
                                                        <?php echo $jd['debit'] > 0 ? number_format($jd['debit'], 2) : '-'; ?>
                                                    </td>
                                                    <td class="text-end fw-bold <?php echo $jd['credit'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                                        <?php echo $jd['credit'] > 0 ? number_format($jd['credit'], 2) : '-'; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="bg-light fw-bold border-top">
                                            <tr>
                                                <td colspan="2" class="ps-4">إجمالي القيد</td>
                                                <td class="text-end text-primary"><?php echo number_format($trx_debit, 2); ?></td>
                                                <td class="text-end text-success"><?php echo number_format($trx_credit, 2); ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- فاتورة البيع (إيراد) -->
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-success text-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i>فاتورة البيع (للعميل)</h6>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if ($sale_inv && $sale_inv['invoice_status'] == 'posted'): ?>
                            <span class="badge bg-white text-success rounded-pill px-3"><i class="fas fa-check-double me-1"></i>مرحلة</span>
                        <?php else: ?>
                            <span class="badge bg-white text-warning rounded-pill px-3"><i class="fas fa-file-alt me-1"></i>مسودة</span>
                        <?php endif; ?>
                        <span class="badge bg-white text-success rounded-pill"><?php echo $sale_inv['invoice_number'] ?? 'غير موجودة'; ?></span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if ($sale_inv): ?>
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="text-muted small d-block">العميل</label>
                                <div class="fw-bold"><?php echo $sale_inv['party_name']; ?></div>
                            </div>
                            <div class="col-6 text-end">
                                <label class="text-muted small d-block">حالة السداد</label>
                                <?php
                                // استخدام المبلغ المحسوب ديناميكياً من السندات المرحّلة فقط
                                $received = round((float)$sale_inv['calculated_amount_received'], 2);
                                $total = round((float)$sale_inv['total_amount'] - (float)$sale_inv['discount'], 2);
                                if ($received >= $total && $total > 0) {
                                    echo '<span class="badge bg-success-subtle text-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i>مدفوع بالكامل</span>';
                                } elseif ($received > 0) {
                                    echo '<span class="badge bg-info-subtle text-info rounded-pill px-3"><i class="fas fa-adjust me-1"></i>مدفوع جزئياً</span>';
                                } else {
                                    echo '<span class="badge bg-danger-subtle text-danger rounded-pill px-3"><i class="fas fa-exclamation-circle me-1"></i>غير مدفوع</span>';
                                }
                                ?>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">إجمالي الفاتورة</label>
                                <div class="fw-bold text-dark"><?php echo number_format((float)$sale_inv['total_amount'], 2); ?> <?php echo $sale_inv['currency_symbol']; ?></div>
                            </div>
                            <?php if ($sale_inv['discount'] > 0): ?>
                            <div class="col-6 text-end">
                                <label class="text-muted small d-block">الخصم</label>
                                <div class="fw-bold text-danger"><?php echo number_format($sale_inv['discount'], 2); ?> <?php echo $sale_inv['currency_symbol']; ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="col-6 mt-2">
                                <label class="text-muted small d-block">الصافي المطلوب</label>
                                <div class="fw-bold text-success fs-5"><?php echo number_format($total, 2); ?> <?php echo $sale_inv['currency_symbol']; ?></div>
                            </div>
                            <div class="col-6 text-end mt-2">
                                <label class="text-muted small d-block">المستلم</label>
                                <div class="fw-bold text-info fs-5"><?php echo number_format($received, 2); ?></div>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">المتبقي</label>
                                <div class="fw-bold text-danger"><?php echo number_format($total - $received, 2); ?></div>
                            </div>
                            <?php
                            $actual_remaining = round($total - (float)$sale_inv['total_allocated_including_unposted'], 2);
                            if ($actual_remaining > 0.01 && $sale_inv['invoice_status'] == 'posted' && $sale_inv['invoice_category'] == 'sales'): ?>
                                <div class="col-12 mt-3">
                                    <button type="button" class="btn btn-sm btn-primary rounded-pill w-100"
                                        data-bs-toggle="modal" data-bs-target="#payRemainingModal"
                                        data-invoice-id="<?php echo $sale_inv['id']; ?>"
                                        data-invoice-currency-id="<?php echo $sale_inv['currency_id']; ?>"
                                        data-invoice-currency-rate="<?php echo $sale_inv['exchange_rate'] ?? 1.0; ?>"
                                        data-remaining-amount="<?php echo $actual_remaining; ?>"
                                        data-currency-symbol="<?php echo $sale_inv['currency_symbol']; ?>"
                                        data-party-type="<?php echo $sale_inv['party_type']; ?>"
                                        data-party-id="<?php echo $sale_inv['party_id']; ?>"
                                        data-invoice-category="<?php echo $sale_inv['invoice_category']; ?>"
                                        data-invoice-number="<?php echo $sale_inv['invoice_number']; ?>"
                                        data-party-name="<?php echo $sale_inv['party_name']; ?>">
                                        <i class="fas fa-money-bill-wave me-2"></i> تسديد المبلغ المتبقي
                                    </button>
                                </div>
                            <?php elseif ($actual_remaining <= 0.01 && $sale_inv['total_amount'] - $sale_inv['calculated_amount_received'] > 0): ?>
                                <div class="col-12 mt-3">
                                    <div class="alert alert-warning small py-2 mb-0 rounded-pill text-center">
                                        <i class="fas fa-info-circle me-1"></i> هناك مبالغ غير مرحلة تغطي المتبقي
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <h6 class="fw-bold mb-3 border-bottom pb-2">سجل التحصيلات</h6>
                        <table class="table table-sm small">
                            <thead class="table-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>السند</th>
                                    <th>المبلغ</th>
                                    <th>بواسطة</th>
                                    <th>الحالة</th>
                                    <th>إدارة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sale_inv['payments'] as $p): ?>
                                    <tr>
                                        <td><?php echo $p['transaction_date']; ?></td>
                                        <td class="fw-bold"><?php echo $p['transaction_number']; ?></td>
                                        <td class="text-success fw-bold"><?php echo number_format($p['allocated_amount'], 2); ?> <?php echo $p['payment_currency_symbol']; ?></td>
                                        <td><?php echo $p['payment_by']; ?></td>
                                        <td>
                                            <?php
                                            $status_class = match($p['payment_status']) {
                                                'posted' => 'bg-success',
                                                'canceled' => 'bg-danger',
                                                'draft' => 'bg-warning text-dark',
                                                default => 'bg-secondary'
                                            };
                                            $status_text = match($p['payment_status']) {
                                                'posted' => 'مرحل',
                                                'canceled' => 'ملغي',
                                                'draft' => 'مسودة',
                                                default => 'أخرى'
                                            };
                                            ?>
                                            <span class="badge <?php echo $status_class; ?> rounded-pill px-2">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light rounded-circle p-0" style="width: 24px; height: 24px;" data-bs-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v small"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 small">
                                                    <?php if (in_array($p['payment_status'], ['draft', 'cancelled']) && $p['transaction_type'] != 'initial'): ?>
                                                        <?php
                                                        $edit_url = ($p['transaction_type'] == 'receipt' || $p['transaction_number'][0] == 'R')
                                                            ? "receipts.php?edit=" . $p['voucher_id']
                                                            : "payments.php?edit=" . $p['voucher_id'];
                                                        ?>
                                                        <li><a class="dropdown-item py-2" href="<?php echo $edit_url; ?>"><i class="fas fa-edit me-2 text-primary"></i>تعديل</a></li>
                                                    <?php endif; ?>

                                                    <?php if ($p['payment_status'] == 'draft' && $p['transaction_type'] != 'initial'): ?>
                                                        <li><a class="dropdown-item py-2 text-success" href="javascript:void(0)" onclick="postVoucher(<?php echo $p['voucher_id']; ?>, 'receipt')"><i class="fas fa-check-circle me-2"></i>ترحيل</a></li>
                                                    <?php endif; ?>

                                                    <?php if ($p['payment_status'] == 'posted' && $p['transaction_type'] != 'initial' && has_permission('vouchers_unpost')): ?>
                                                        <li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="unpostVoucher(<?php echo $p['voucher_id']; ?>, 'receipt')"><i class="fas fa-undo me-2"></i>إلغاء ترحيل</a></li>
                                                    <?php endif; ?>

                                                    <?php if ($p['payment_status'] != 'posted' && $p['transaction_type'] != 'initial'): ?>
                                                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteVoucher(<?php echo $p['voucher_id']; ?>, 'receipt')"><i class="fas fa-trash-alt me-2"></i>حذف</a></li>
                                                    <?php endif; ?>

                                                    <li><a class="dropdown-item py-2" href="<?php echo ($p['transaction_type'] == 'receipt' || substr($p['transaction_number'], 0, 3) == 'RCT') ? 'receipts.php?search_num=' . $p['transaction_number'] : 'payments.php?search_num=' . $p['transaction_number']; ?>"><i class="fas fa-external-link-alt me-2 text-info"></i>فتح السند</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                if (empty($sale_inv['payments'])) echo '<tr><td colspan="4" class="text-center text-muted">لا يوجد تحصيلات</td></tr>'; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">لا توجد فاتورة بيع مسجلة لهذه العملية.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- فاتورة الشراء (تكلفة) -->
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-danger text-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-shopping-cart me-2"></i>فاتورة الشراء (للمورد)</h6>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if ($pur_inv && $pur_inv['invoice_status'] == 'posted'): ?>
                            <span class="badge bg-white text-danger rounded-pill px-3"><i class="fas fa-check-double me-1"></i>مرحلة</span>
                        <?php else: ?>
                            <span class="badge bg-white text-warning rounded-pill px-3"><i class="fas fa-file-alt me-1"></i>مسودة</span>
                        <?php endif; ?>
                        <span class="badge bg-white text-danger rounded-pill"><?php echo $pur_inv['invoice_number'] ?? 'غير موجودة'; ?></span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if ($pur_inv): ?>
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="text-muted small d-block">المورد</label>
                                <div class="fw-bold"><?php echo $pur_inv['party_name']; ?></div>
                            </div>
                            <div class="col-6 text-end">
                                <label class="text-muted small d-block">حالة السداد للمورد</label>
                                <?php
                                $p_received = round((float)$pur_inv['calculated_amount_received'], 2);
                                $p_total = round((float)$pur_inv['total_amount'], 2);
                                if ($p_received >= $p_total && $p_total > 0) {
                                    echo '<span class="badge bg-success-subtle text-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i>مسدد بالكامل</span>';
                                } elseif ($p_received > 0) {
                                    echo '<span class="badge bg-info-subtle text-info rounded-pill px-3"><i class="fas fa-adjust me-1"></i>مسدد جزئياً</span>';
                                } else {
                                    echo '<span class="badge bg-danger-subtle text-danger rounded-pill px-3"><i class="fas fa-exclamation-circle me-1"></i>غير مسدد</span>';
                                }
                                ?>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">مبلغ التكلفة</label>
                                <div class="fw-bold text-danger fs-5"><?php echo number_format($p_total, 2); ?> <?php echo $pur_inv['currency_symbol']; ?></div>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">المسدد له</label>
                                <div class="fw-bold text-info"><?php echo number_format($p_received, 2); ?></div>
                            </div>
                            <div class="col-6">
                                <label class="text-muted small d-block">المتبقي (مديونية)</label>
                                <div class="fw-bold text-danger"><?php echo number_format($p_total - $p_received, 2); ?></div>
                            </div>
                            <?php
                            $actual_remaining_pur = round($p_total - (float)$pur_inv['total_allocated_including_unposted'], 2);
                            if ($actual_remaining_pur > 0.01 && $pur_inv['invoice_status'] == 'posted'): ?>
                                <div class="col-12 mt-3">
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill w-100"
                                        data-bs-toggle="modal" data-bs-target="#payRemainingModal"
                                        data-invoice-id="<?php echo $pur_inv['id']; ?>"
                                        data-invoice-currency-id="<?php echo $pur_inv['currency_id']; ?>"
                                        data-invoice-currency-rate="<?php echo $pur_inv['exchange_rate'] ?? 1.0; ?>"
                                        data-remaining-amount="<?php echo $actual_remaining_pur; ?>"
                                        data-currency-symbol="<?php echo $pur_inv['currency_symbol']; ?>"
                                        data-party-type="<?php echo $pur_inv['party_type']; ?>"
                                        data-party-id="<?php echo $pur_inv['party_id']; ?>"
                                        data-invoice-category="purchase"
                                        data-invoice-number="<?php echo $pur_inv['invoice_number']; ?>"
                                        data-party-name="<?php echo $pur_inv['party_name']; ?>">
                                        <i class="fas fa-hand-holding-usd me-2"></i> سداد مديونية المورد
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <h6 class="fw-bold mb-3 border-bottom pb-2">سجل المدفوعات للمورد</h6>
                        <table class="table table-sm small">
                            <thead class="table-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>السند</th>
                                    <th>المبلغ</th>
                                    <th>بواسطة</th>
                                    <th>الحالة</th>
                                    <th>إدارة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pur_inv['payments'] as $p): ?>
                                    <tr>
                                        <td><?php echo $p['transaction_date']; ?></td>
                                        <td class="fw-bold"><?php echo $p['transaction_number']; ?></td>
                                        <td class="text-danger fw-bold"><?php echo number_format($p['allocated_amount'], 2); ?> <?php echo $p['payment_currency_symbol']; ?></td>
                                        <td><?php echo $p['payment_by']; ?></td>
                                        <td>
                                            <?php
                                            $status_class = match($p['payment_status']) {
                                                'posted' => 'bg-success',
                                                'canceled' => 'bg-danger',
                                                'draft' => 'bg-warning text-dark',
                                                default => 'bg-secondary'
                                            };
                                            $status_text = match($p['payment_status']) {
                                                'posted' => 'مرحل',
                                                'canceled' => 'ملغي',
                                                'draft' => 'مسودة',
                                                default => 'أخرى'
                                            };
                                            ?>
                                            <span class="badge <?php echo $status_class; ?> rounded-pill px-2">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light rounded-circle p-0" style="width: 24px; height: 24px;" data-bs-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v small"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 small">
                                                    <?php if (in_array($p['payment_status'], ['draft', 'cancelled']) && $p['transaction_type'] != 'initial'): ?>
                                                        <?php
                                                        $edit_url = ($p['transaction_type'] == 'payment' || substr($p['transaction_number'], 0, 3) == 'PMT')
                                                            ? "payments.php?edit=" . $p['voucher_id']
                                                            : "receipts.php?edit=" . $p['voucher_id'];
                                                        ?>
                                                        <li><a class="dropdown-item py-2" href="<?php echo $edit_url; ?>"><i class="fas fa-edit me-2 text-primary"></i>تعديل</a></li>
                                                    <?php endif; ?>

                                                    <?php if ($p['payment_status'] == 'draft' && $p['transaction_type'] != 'initial'): ?>
                                                        <li><a class="dropdown-item py-2 text-success" href="javascript:void(0)" onclick="postVoucher(<?php echo $p['voucher_id']; ?>, 'payment')"><i class="fas fa-check-circle me-2"></i>ترحيل</a></li>
                                                    <?php endif; ?>

                                                    <?php if ($p['payment_status'] == 'posted' && $p['transaction_type'] != 'initial' && has_permission('vouchers_unpost')): ?>
                                                        <li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="unpostVoucher(<?php echo $p['voucher_id']; ?>, 'payment')"><i class="fas fa-undo me-2"></i>إلغاء ترحيل</a></li>
                                                    <?php endif; ?>

                                                    <?php if ($p['payment_status'] != 'posted' && $p['transaction_type'] != 'initial'): ?>
                                                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteVoucher(<?php echo $p['voucher_id']; ?>, 'payment')"><i class="fas fa-trash-alt me-2"></i>حذف</a></li>
                                                    <?php endif; ?>

                                                    <li><a class="dropdown-item py-2" href="<?php echo ($p['transaction_type'] == 'payment' || substr($p['transaction_number'], 0, 3) == 'PMT') ? 'payments.php?search_num=' . $p['transaction_number'] : 'receipts.php?search_num=' . $p['transaction_number']; ?>"><i class="fas fa-external-link-alt me-2 text-info"></i>فتح السند</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                if (empty($pur_inv['payments'])) echo '<tr><td colspan="4" class="text-center text-muted">لا يوجد مدفوعات</td></tr>'; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">لا توجد فاتورة شراء مسجلة (قد تكون التكلفة مسجلة داخلياً فقط).</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- بيانات الخدمة والمستندات -->
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-light py-3 border-0">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-info-circle me-2"></i>بيانات الخدمة والمسافر</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-3">
                            <label class="text-muted small d-block">اسم المسافر / العميل</label>
                            <div class="fw-bold fs-5 text-primary"><?php echo htmlspecialchars($service_info['full_name'] ?? '---'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small d-block">رقم الجواز / المرجع</label>
                            <div class="fw-bold fs-5"><?php echo htmlspecialchars($service_info['passport_number'] ?? '---'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small d-block">نوع الخدمة</label>
                            <div class="fw-bold fs-5">
                                <?php
                                    $stype = $main_inv['source_type'];
                                    if ($stype === 'passport_transaction') echo 'معاملة جوازات';
                                    else echo $stype;
                                ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small d-block">الحالة المحاسبية</label>
                            <div class="mt-1">
                                <?php if (($sale_inv && $sale_inv['invoice_status'] == 'posted') && ($pur_inv ? $pur_inv['invoice_status'] == 'posted' : true)): ?>
                                    <span class="badge bg-success rounded-pill px-3 py-2 w-100"><i class="fas fa-check-circle me-1"></i> مرحلة بالكامل</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark rounded-pill px-3 py-2 w-100"><i class="fas fa-clock me-1"></i> بانتظار الترحيل</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small d-block">البيان / الوصف</label>
                            <div class="p-3 bg-light rounded-3 mt-1"><?php echo $main_inv['description'] ?: 'لا يوجد وصف مضاف.'; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- قسم تاريخ العمليات -->
<?php if (!empty($audit_logs)): ?>
<div class="container-fluid mb-5">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom-0">
            <h5 class="fw-bold mb-0 text-primary">
                <i class="fas fa-history me-2"></i> تاريخ العمليات والتعديلات
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="accordion accordion-flush" id="invoiceAuditAccordion">
                <?php foreach ($audit_logs as $index => $log): ?>
                    <?php
                        $ua_info = parseUserAgent($log['user_agent'] ?? '');
                        $u_name = $audit_user_names[$log['user_id']] ?? 'مستخدم مجهول';
                        $action_trans = translateAction($log['action']);
                        $ip = $log['ip_address'] ?? $log['user_ip'] ?? '-';
                        $item_id = "auditItem" . $index;
                        $header_id = "auditHeader" . $index;
                    ?>
                    <div class="accordion-item border-top">
                        <h2 class="accordion-header" id="<?php echo $header_id; ?>">
                            <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>">
                                <div class="d-flex justify-content-between align-items-center w-100 me-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light p-2 rounded-circle me-3 text-primary" style="width:35px; height:35px; display:flex; align-items:center; justify-content:center;">
                                            <i class="fas fa-user-edit small"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark small">
                                                <span class="badge bg-primary bg-opacity-10 text-primary me-2"><?php echo $action_trans; ?></span>
                                                بواسطة: <?php echo htmlspecialchars($u_name); ?>
                                            </div>
                                            <div class="text-muted extra-small mt-1">
                                                <i class="far fa-clock me-1"></i> <?php echo $log['created_at']; ?>
                                                <span class="mx-2">|</span>
                                                <i class="fas fa-network-wired me-1"></i> <?php echo htmlspecialchars($ip); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-end d-none d-md-block">
                                        <?php if (is_array($ua_info)): ?>
                                            <div class="small text-muted">
                                                <i class="<?php echo $ua_info['icon']; ?> me-1"></i> <?php echo $ua_info['browser']; ?>
                                                <span class="mx-1">/</span>
                                                <i class="<?php echo $ua_info['os_icon']; ?> me-1"></i> <?php echo $ua_info['os']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div id="<?php echo $item_id; ?>" class="accordion-collapse collapse" data-bs-parent="#invoiceAuditAccordion">
                            <div class="accordion-body bg-light bg-opacity-50 p-4">
                                <?php echo renderAuditModalContent($log['old_values'], $log['new_values'], $item_id, $log['action']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal تسديد المتبقي -->
<div class="modal fade" id="payRemainingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form id="payRemainingForm">
                <div class="modal-header border-bottom border-light p-4">
                    <h5 class="fw-bold mb-0">تسديد المبلغ المتبقي للفاتورة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="invoice_id" id="modal_invoice_id" value="">
                    <input type="hidden" name="invoice_currency_id" id="modal_invoice_currency_id" value="">
                    <input type="hidden" name="party_type" id="modal_party_type" value="">
                    <input type="hidden" name="party_id" id="modal_party_id" value="">
                    <input type="hidden" name="invoice_category" id="modal_invoice_category" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="mb-4 text-center p-3 bg-light rounded-4">
                        <label class="text-muted small d-block mb-1">المبلغ المتبقي (بعملة الفاتورة)</label>
                        <span class="fs-2 fw-bold text-success" id="display_remaining_orig"></span>
                        <span class="text-muted ms-1 small" id="display_invoice_currency_symbol"></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">الجهة (العميل/المورد)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-light-subtle"><i class="fas fa-user"></i></span>
                            <input type="text" id="display_party_name" class="form-control rounded-end-3 border-light-subtle bg-light fw-bold" readonly>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">عملة السداد</label>
                            <select name="payment_currency_id" id="payment_currency_id" class="form-select rounded-3 border-light-subtle bg-light" required>
                                <?php foreach ($currencies_list as $curr): ?>
                                    <option value="<?php echo $curr['id']; ?>"
                                        data-buy="<?php echo $curr['exchange_rate_buy']; ?>"
                                        data-sell="<?php echo $curr['exchange_rate_sell']; ?>"
                                        data-symbol="<?php echo $curr['currency_symbol']; ?>">
                                        <?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_symbol']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">سعر الصرف</label>
                            <input type="number" step="0.000001" name="exchange_rate" id="exchange_rate" class="form-control rounded-3 border-light-subtle bg-light" value="1.000000" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">المبلغ بعملة السداد</label>
                        <div class="input-group">
                            <input type="number" step="0.01" name="pay_amount" id="pay_amount" class="form-control form-control-lg rounded-start-3 border-light-subtle bg-light fw-bold"
                                value="<?php echo round($remaining_amount, 2); ?>" required>
                            <span class="input-group-text rounded-end-3 bg-white border-light-subtle" id="pay_currency_symbol"></span>
                        </div>
                        <div id="exchange_info" class="form-text extra-small text-primary mt-1 d-none">
                            <i class="fas fa-info-circle me-1"></i> يعادل <span id="equivalent_orig">0.00</span> <span id="equivalent_orig_currency_symbol"></span> من قيمة الفاتورة
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">الصندوق / البنك</label>
                        <select name="financial_account_id" class="form-select form-select-lg rounded-3 border-light-subtle bg-light" required>
                            <option value="">اختر الحساب...</option>
                            <?php foreach ($financial_accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>"><?php echo $acc['account_name']; ?> (<?php echo $acc['account_code']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-bold small">البيان</label>
                        <textarea name="payment_desc" id="payment_desc" class="form-control rounded-3 border-light-subtle bg-light" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-light p-4 pt-0">
                    <button type="button" class="btn btn-link text-muted text-decoration-none" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success rounded-pill px-5 shadow-sm fw-bold">إتمام التسديد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // متغير CSRF token عالمي
    var csrfToken = <?php echo function_exists('generate_csrf_token') ? json_encode(generate_csrf_token()) : '""'; ?>;
    
    $(document).ready(function() {
        // Handle payRemainingModal show event
        var payRemainingModal = document.getElementById('payRemainingModal');
        payRemainingModal.addEventListener('show.bs.modal', function(event) {
            // Button that triggered the modal
            var button = event.relatedTarget;

            // Extract info from data-bs-* attributes
            var invoiceId = button.getAttribute('data-invoice-id');
            var invoiceCurrencyId = button.getAttribute('data-invoice-currency-id');
            var invoiceCurrencyRate = button.getAttribute('data-invoice-currency-rate') || 1.0;
            var remainingAmount = button.getAttribute('data-remaining-amount');
            var currencySymbol = button.getAttribute('data-currency-symbol');
            var partyType = button.getAttribute('data-party-type');
            var partyId = button.getAttribute('data-party-id');
            var invoiceCategory = button.getAttribute('data-invoice-category');
            var invoiceNumber = button.getAttribute('data-invoice-number');
            var partyName = button.getAttribute('data-party-name');

            // Update the modal's content.
            var modalInvoiceId = payRemainingModal.querySelector('#modal_invoice_id');
            var modalInvoiceCurrencyId = payRemainingModal.querySelector('#modal_invoice_currency_id');
            $(modalInvoiceCurrencyId).data('rate', invoiceCurrencyRate); // Store invoice rate here
            var modalPartyType = payRemainingModal.querySelector('#modal_party_type');
            var modalPartyId = payRemainingModal.querySelector('#modal_party_id');
            var modalInvoiceCategory = payRemainingModal.querySelector('#modal_invoice_category');
            var displayRemainingOrig = payRemainingModal.querySelector('#display_remaining_orig');
            var displayInvoiceCurrencySymbol = payRemainingModal.querySelector('#display_invoice_currency_symbol');
            var displayPartyNameInput = payRemainingModal.querySelector('#display_party_name');
            var payAmountInput = payRemainingModal.querySelector('#pay_amount');
            var paymentDescTextarea = payRemainingModal.querySelector('#payment_desc');
            var paymentCurrencySelect = payRemainingModal.querySelector('#payment_currency_id');
            var equivalentOrigCurrencySymbol = payRemainingModal.querySelector('#equivalent_orig_currency_symbol');

            modalInvoiceId.value = invoiceId;
            modalInvoiceCurrencyId.value = invoiceCurrencyId;
            modalPartyType.value = partyType;
            modalPartyId.value = partyId;
            modalInvoiceCategory.value = invoiceCategory;
            displayRemainingOrig.textContent = parseFloat(remainingAmount).toFixed(2);
            displayInvoiceCurrencySymbol.textContent = currencySymbol;
            if (displayPartyNameInput) {
                displayPartyNameInput.value = partyName;
            }
            if (payAmountInput) {
                payAmountInput.value = parseFloat(remainingAmount).toFixed(2);
            }
            if (paymentDescTextarea) {
                paymentDescTextarea.value = `تسديد المتبقي من الفاتورة #${invoiceNumber} - ${partyName}`;
            }
            if (equivalentOrigCurrencySymbol) {
                equivalentOrigCurrencySymbol.textContent = currencySymbol;
            }

            // Set selected currency in the dropdown and trigger change event for updateExchange
            if (paymentCurrencySelect && invoiceCurrencyId) {
                $(paymentCurrencySelect).val(invoiceCurrencyId).trigger('change');
            }

            // Re-initialize select2 for dynamically loaded content if needed
            $('.select2-modal-payment').select2({
                dropdownParent: $('#payRemainingModal'),
                width: '100%'
            });

            updateExchange(); // Call existing function to update exchange rates if currency selection changes
        });

        function updateExchange() {
            let paymentCurrencyId = $('#payment_currency_id').val();
            let selectedOption = $('#payment_currency_id option:selected');
            let symbol = selectedOption.data('symbol');
            let payBuyRate = parseFloat(selectedOption.data('buy') || 1);
            let paySellRate = parseFloat(selectedOption.data('sell') || 1);

            let currentInvCurrencyId = $('#modal_invoice_currency_id').val();
            let invRate = parseFloat($('#modal_invoice_currency_id').data('rate') || 1);
            let currentRemainingOrig = parseFloat($('#display_remaining_orig').text() || 0);

            $('#pay_currency_symbol').text(symbol);

            if (paymentCurrencyId == currentInvCurrencyId) {
                $('#exchange_rate').val('1.000000').prop('readonly', true);
                $('#pay_amount').val(currentRemainingOrig.toFixed(2));
                $('#exchange_info').addClass('d-none');
            } else {
                $('#exchange_rate').prop('readonly', false);
                $('#exchange_info').removeClass('d-none');

                // معدل التحويل = سعر صرف عملة الفاتورة / سعر صرف عملة السداد
                // ملاحظة: نفترض أن الأسعار مخزنة كـ (1 وحدة عملة = X من العملة الأساسية)
                let rate = invRate / paySellRate;
                $('#exchange_rate').val(rate.toFixed(6));

                let payAmt = currentRemainingOrig * rate;
                $('#pay_amount').val(payAmt.toFixed(2));
                $('#equivalent_orig').text(currentRemainingOrig.toFixed(2));
            }
        }

        $('#payment_currency_id').on('change', updateExchange);

        $('#pay_amount, #exchange_rate').on('input', function() {
            let payAmt = parseFloat($('#pay_amount').val() || 0);
            let rate = parseFloat($('#exchange_rate').val() || 1);
            let paymentCurrencyId = $('#payment_currency_id').val();
            let currentInvCurrencyId = $('#modal_invoice_currency_id').val();
            let remainingOrig = parseFloat($('#display_remaining_orig').text() || 0);

            if (paymentCurrencyId != currentInvCurrencyId && rate > 0) {
                let eqOrig = payAmt / rate;
                $('#equivalent_orig').text(eqOrig.toFixed(2));

                // منع تجاوز المتبقي عند التحويل
                if (eqOrig > (remainingOrig + 0.01)) { // +0.01 لتجنب أخطاء التقريب
                    Swal.fire({
                        icon: 'warning',
                        title: 'تنبيه',
                        text: 'لا يمكن أن يتجاوز المبلغ المدفوع القيمة المتبقية للفاتورة (' + remainingOrig.toFixed(2) + ')',
                        confirmButtonText: 'حسناً'
                    });
                    let maxPay = remainingOrig * rate;
                    $('#pay_amount').val(maxPay.toFixed(2));
                    $('#equivalent_orig').text(remainingOrig.toFixed(2));
                }
            } else if (paymentCurrencyId == currentInvCurrencyId) {
                if (payAmt > remainingOrig) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'تنبيه',
                        text: 'لا يمكن أن يتجاوز المبلغ المدفوع القيمة المتبقية للفاتورة (' + remainingOrig.toFixed(2) + ')',
                        confirmButtonText: 'حسناً'
                    });
                    $('#pay_amount').val(remainingOrig.toFixed(2));
                }
            }
        });

        // إزالة الحدث القديم قبل إضافة الجديد لمنع التكرار
        $('#payRemainingForm').off('submit').on('submit', function(e) {
            e.preventDefault();

            // التأكد من عدم إرسال النموذج مراراً
            if ($(this).data('submitting')) {
                return;
            }
            $(this).data('submitting', true);

            let formData = $(this).serialize();
            let submitBtn = $(this).find('button[type="submit"]');
            let originalText = submitBtn.html();

            submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> جاري الحفظ...');

            $.ajax({
                url: 'ajax/pay_invoice_remaining.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'تمت العملية',
                            text: 'تم تسديد المبلغ وتحديث الفاتورة بنجاح',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'خطأ',
                            text: response.message || 'حدث خطأ أثناء حفظ العملية'
                        });
                        submitBtn.prop('disabled', false).html(originalText);
                        $('#payRemainingForm').data('submitting', false);
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'خطأ',
                        text: 'حدث خطأ في الاتصال بالسيرفر'
                    });
                    submitBtn.prop('disabled', false).html(originalText);
                    $('#payRemainingForm').data('submitting', false);
                }
            });
        });
    });

    function deleteVoucher(id, type) {
        let title = (type === 'payment') ? 'حذف سند صرف' : 'حذف سند قبض';
        Swal.fire({
            title: title,
            text: "هل أنت متأكد من حذف هذا السند؟ سيتم إلغاء تأثيره المحاسبي وتوزيعه على الفواتير.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/delete_voucher.php',
                    type: 'POST',
                    data: {
                        id: id,
                        type: type,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('تم الحذف', 'تم حذف السند بنجاح', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('خطأ', response.message || 'حدث خطأ أثناء الحذف', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('خطأ', 'حدث خطأ في الاتصال بالسيرفر', 'error');
                    }
                });
            }
        });
    }

    function postVoucher(id, type) {
        let title = (type === 'payment') ? 'ترحيل سند صرف' : 'ترحيل سند قبض';
        Swal.fire({
            title: title,
            text: "هل أنت متأكد من ترحيل هذا السند؟ سيتم تسجيله في الحسابات ولا يمكن تعديله بعد الترحيل.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، ترحيل',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/post_voucher.php',
                    type: 'POST',
                    data: {
                        id: id,
                        type: type,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('تم الترحيل', 'تم ترحيل السند بنجاح', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('خطأ', response.message || 'حدث خطأ أثناء الترحيل', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('خطأ', 'حدث خطأ في الاتصال بالسيرفر', 'error');
                    }
                });
            }
        });
    }

    function unpostVoucher(id, type) {
        let title = (type === 'payment') ? 'إلغاء ترحيل سند صرف' : 'إلغاء ترحيل سند قبض';
        Swal.fire({
            title: title,
            text: "هل أنت متأكد من إلغاء ترحيل هذا السند؟ سيعود السند إلى حالة مسودة وسيتم إلغاء تأثيره المحاسبي.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f39c12',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، إلغاء الترحيل',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/unpost_voucher.php',
                    type: 'POST',
                    data: {
                        id: id,
                        type: type,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('تم إلغاء الترحيل', 'سيعود السند لحالة مسودة ويمكن تعديله الآن.', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('خطأ', response.message || 'حدث خطأ أثناء إلغاء الترحيل', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('خطأ', 'حدث خطأ في الاتصال بالسيرفر', 'error');
                    }
                });
            }
        });
    }
</script>

<?php require_once 'footer.php'; ?>
