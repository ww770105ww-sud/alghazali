<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/CurrencyExchange.php';

$nationalities = [
    'أفغاني', 'ألباني', 'جزائري', 'أمريكي', 'أندوري', 'أنغولي', 'أرجنتيني', 'أرميني', 'أسترالي', 'نمساوي', 'أذربيجاني',
    'بحريني', 'بنغلاديشي', 'بلجيكي', 'بوليفي', 'بوسني', 'برازيلي', 'بريطاني', 'بلغاري', 'كمبودي', 'كندي', 'تشيلي', 'صيني', 'كولومبي', 'كوستاريكي', 'كرواتي', 'كوبي', 'قبرصي', 'تشيكي',
    'دنماركي', 'دومينيكي', 'إكوادوري', 'مصري', 'سلفادوري', 'إستوني', 'إثيوبي', 'فيجي', 'فنلندي', 'فرنسي', 'ألماني', 'غاني', 'يوناني', 'غواتيمالي', 'هندوراسي', 'هندي', 'إندونيسي', 'إيراني', 'عراقي', 'أيرلندي', 'إسرائيلي', 'إيطالي', 'جامايكي', 'ياباني', 'أردني', 'كازاخستاني', 'كيني', 'كويتي', 'لاوسي', 'لاتفي', 'لبناني', 'ليبي', 'ليتواني', 'ماليزي', 'مالديفي', 'مالطي', 'موريتاني', 'مكسيكي', 'مولدوفي', 'مغربي', 'نيبالي', 'هولندي', 'نيوزيلندي', 'نيجيري', 'نرويجي', 'عماني', 'باكستاني', 'فلسطيني', 'بنغلاديشي', 'بيروفي', 'فلبيني', 'بولندي', 'برتغالي', 'قطري', 'روماني', 'روسي', 'سعودي', 'سنغالي', 'صربي', 'سنغافوري', 'سلوفاكي', 'سلوفيني', 'صومالي', 'جنوب أفريقي', 'كوري جنوبي', 'إسباني', 'سريلانكي', 'سوداني', 'سويدي', 'سويسري', 'سوري', 'تايواني', 'تنزاني', 'تايلاندي', 'تونسي', 'تركي', 'أوغندي', 'أوكراني', 'إماراتي', 'أوروغوياني', 'فنزويلي', 'فيتنامي', 'يمني', 'زامبي', 'زيمبابوي',
];

$currencyExchange = new CurrencyExchange($pdo);
$baseCurrency = $currencyExchange->getBaseCurrency();
$base_currency_id = $baseCurrency['id'] ?? null;

// التحقق من الصلاحية
if (!has_permission('manage_financial_accounts')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['deactivate']) || isset($_GET['delete_permanent'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// إضافة عميل جديد
if (isset($_POST['add_customer_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='customers.php';</script>");
    }
    $account_name = $_POST['account_name'];
    $branch_id = $_POST['branch_id'] ?: null;
    $opening_balance = $_POST['opening_balance'] ?? 0;
    $currency_id = $_POST['currency_id'] ?? 1;
    $status = $_POST['status'] == 'active' ? 'active' : $_POST['status'];
    $phone = $_POST['phone'] ?? null;
    $whatsapp = $_POST['whatsapp'] ?? null;
    $nationality = $_POST['nationality'] ?? null;
    $start_date = $_POST['start_date'] ?? null;
    $address = $_POST['address'] ?? null;
    $notes = $_POST['notes'] ?? null;

    try {
        $pdo->beginTransaction();

        // 1. العثور على الحساب الرئيسي للعملاء (11201)
        $parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11201'");
        $parent_stmt->execute();
        $parent_id = $parent_stmt->fetchColumn();
        
        if (!$parent_id) throw new Exception("الحساب الرئيسي للعملاء (11201) غير موجود.");

        // التحقق من عدم تكرار الاسم تحت نفس الأب
        $stmt_check_name = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE account_name_ar = ? AND parent_id = ?");
        $stmt_check_name->execute([$account_name, $parent_id]);
        if ($stmt_check_name->fetchColumn() > 0) {
            throw new Exception("اسم العميل موجود بالفعل.");
        }

        // 2. توليد الكود الجديد (11201001, 11201002, ...)
        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ? AND account_code LIKE '11201%'");
        $stmt_last->execute([$parent_id]);
        $last_code = $stmt_last->fetchColumn();
        
        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = "11201001";
        }

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, branch_id, account_status) VALUES (?, ?, 'عميل', 'debit', ?, ?, ?)");
        $stmt->execute([$new_code, $account_name, $parent_id, $branch_id, $status]);
        
        $new_account_id = $pdo->lastInsertId();
        
        // تفعيل الرصيد الافتتاحي في الجدول الموحد - فقط العملة الأساسية للنظام
        if ($base_currency_id) {
            $opening_balance_for_base = $_POST['opening_balance'] ?? 0;
            // Get currency code and exchange rate
            $stmt_curr = $pdo->prepare("SELECT currency_code, exchange_rate FROM currencies WHERE id = ?");
            $stmt_curr->execute([$base_currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            $rate = (float)($curr['exchange_rate'] ?? 1);
            $opening_balance_base = $opening_balance_for_base * $rate;
            
            $stmt_base_balance = $pdo->prepare("INSERT INTO account_balances_unified (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)");
            $stmt_base_balance->execute([$new_account_id, null, $base_currency_id, $currency_code, $opening_balance_for_base, $opening_balance_for_base, $opening_balance_base, $opening_balance_base]);
        }

        // Also insert into customers table!
        $insertCustomerStmt = $pdo->prepare("INSERT INTO customers (full_name, account_id, phone, whatsapp, nationality, start_date, address, notes, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
        $insertCustomerStmt->execute([$account_name, $new_account_id, $phone, $whatsapp, $nationality, $start_date, $address, $notes, $status]);
        
        $pdo->commit();
        echo "<script>location.href='customers.php?success=1';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
    }
}

// تحديث عميل
if (isset($_POST['update_customer_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='customers.php';</script>");
    }
    $id = $_POST['id'];
    $account_name = $_POST['account_name'];
    $new_status = $_POST['status'];
    $branch_id = $_POST['branch_id'] ?: null;
    $phone = $_POST['phone'] ?? null;
    $whatsapp = $_POST['whatsapp'] ?? null;
    $nationality = $_POST['nationality'] ?? null;
    $start_date = $_POST['start_date'] ?? null;
    $address = $_POST['address'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    try {
        $pdo->beginTransaction();
        
        // الحصول على الحالة الحالية
        $stmt_get_current = $pdo->prepare("SELECT account_status FROM unified_accounts WHERE id = ?");
        $stmt_get_current->execute([$id]);
        $current_status = $stmt_get_current->fetchColumn();
        
        // إذا كنا نريد تغيير الحالة إلى مغلق، تحقق من أن الرصيد صفر
        if ($new_status === 'closed' && $current_status !== 'closed') {
            $stmt_check_balance = $pdo->prepare("SELECT SUM(current_balance) as total FROM account_balances_unified WHERE account_id = ?");
            $stmt_check_balance->execute([$id]);
            $total_balance = (float)$stmt_check_balance->fetchColumn();
            if ($total_balance != 0) {
                throw new Exception("لا يمكن تغيير الحالة إلى مغلق لأن الرصيد ليس صفرًا.");
            }
        }
        
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_name_ar = ?, account_status = ?, branch_id = ? WHERE id = ?");
        $stmt->execute([$account_name, $new_status, $branch_id, $id]);

        // Update customers table as well
        $updateCustomerStmt = $pdo->prepare("UPDATE customers SET full_name = ?, phone = ?, whatsapp = ?, nationality = ?, start_date = ?, address = ?, notes = ?, status = ?, updated_at = NOW() WHERE account_id = ?");
        $updateCustomerStmt->execute([$account_name, $phone, $whatsapp, $nationality, $start_date, $address, $notes, $new_status, $id]);
        
        $pdo->commit();
        echo "<script>location.href='customers.php?success=2';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
    }
}

// تحويل إلى خامل عبر POST + CSRF
if (isset($_POST['deactivate_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='customers.php';</script>");
    }
    $id = (int)$_POST['deactivate_account'];
    try {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        echo "<script>location.href='customers.php?success=3';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ أثناء التحويل إلى خامل: " . $e->getMessage();
    }
}

// حذف نهائي عبر POST + CSRF
if (isset($_POST['delete_account_permanent'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='customers.php';</script>");
    }
    $id = (int)$_POST['delete_account_permanent'];
    try {
        $pdo->beginTransaction();
        
        // تحقق من أن الرصيد صفر
        $stmt_check_balance = $pdo->prepare("SELECT SUM(current_balance) as total FROM account_balances_unified WHERE account_id = ?");
        $stmt_check_balance->execute([$id]);
        $total_balance = (float)$stmt_check_balance->fetchColumn();
        if ($total_balance != 0) {
            throw new Exception("لا يمكن حذف الحساب نهائيًا لأن الرصيد ليس صفرًا.");
        }

        // التحقق من إمكانية حذف الحساب وعدم وجود حركات مالية مرتبطة
        if (!can_delete_account($id)) {
            throw new Exception("لا يمكن حذف الحساب نهائيًا لوجود عمليات مالية مرتبطة به. يمكنك تغيير حالته إلى خامل بدلاً من ذلك.");
        }

        // حذف الأرصدة المرتبطة بالحساب
        $stmt_del_bal = $pdo->prepare("DELETE FROM account_balances_unified WHERE account_id = ?");
        $stmt_del_bal->execute([$id]);

        // حذف من جدول customers
        $stmt_del_customer = $pdo->prepare("DELETE FROM customers WHERE account_id = ?");
        $stmt_del_customer->execute([$id]);

        // حذف الحساب من شجرة الحسابات الموحدة
        $stmt = $pdo->prepare("DELETE FROM unified_accounts WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        echo "<script>location.href='customers.php?success=4';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الحذف النهائي: " . $e->getMessage();
    }
}

// جلب معرف الأب للعملاء (11201)
$parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11201'");
$parent_stmt->execute();
$customers_parent_id = $parent_stmt->fetchColumn();

// جلب العملاء من النظام الموحد
$where = "WHERE coa.parent_id = ? AND (coa.account_status = 'active' OR coa.account_status = 'dormant')";
$params = [$customers_parent_id];
if (!empty($_GET['q'])) {
    $where .= " AND (coa.account_name_ar LIKE ? OR coa.account_code LIKE ?)";
    $q = "%" . $_GET['q'] . "%";
    $params[] = $q; $params[] = $q;
}

$customers_stmt = $pdo->prepare("
    SELECT coa.*, p.account_name_ar as parent_name, b.branch_name,
           c.id as customer_id, c.phone, c.whatsapp, c.nationality,
           c.start_date, c.address, c.notes, c.created_at, c.updated_at, c.status as customer_status
    FROM unified_accounts coa
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id
    LEFT JOIN branches b ON coa.branch_id = b.id
    LEFT JOIN customers c ON coa.id = c.account_id
    $where
    ORDER BY coa.account_code ASC
");
$customers_stmt->execute($params);
$customers = $customers_stmt->fetchAll();

// جلب الأرصدة لكل العملاء دفعة واحدة من account_balances_unified
$customer_ids = array_column($customers, 'id');
$balances = [];
$customer_totals = []; // لتخزين إجمالي لكل عميل
$total_debit = 0; // إجمالي لنا (العميل يدين لنا)
$total_credit = 0; // إجمالي علينا (ندين للعميل)
if (!empty($customer_ids)) {
    $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT
            abu.account_id,
            abu.currency_id,
            c.currency_name,
            c.currency_symbol,
            c.currency_code,
            abu.current_balance,
            abu.current_balance_base,
            ua.normal_balance
        FROM account_balances_unified abu
        LEFT JOIN currencies c ON abu.currency_id = c.id
        LEFT JOIN unified_accounts ua ON abu.account_id = ua.id
        WHERE abu.account_id IN ($placeholders)
        ORDER BY abu.account_id ASC, c.currency_name ASC
    ");
    $bal_stmt->execute($customer_ids);
    while ($row = $bal_stmt->fetch()) {
        $balances[$row['account_id']][] = $row;
        
        // Initialize customer totals if not set
        if (!isset($customer_totals[$row['account_id']])) {
            $customer_totals[$row['account_id']] = ['debit' => 0, 'credit' => 0];
        }
        
        // Calculate per-customer totals
        if ($row['current_balance_base'] > 0) {
            $customer_totals[$row['account_id']]['debit'] += $row['current_balance_base'];
            $total_debit += $row['current_balance_base'];
        } else {
            $customer_totals[$row['account_id']]['credit'] += abs($row['current_balance_base']);
            $total_credit += abs($row['current_balance_base']);
        }
    }
}

$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();

$page_title = "إدارة العملاء";
?>

<style>
        /* Ensure modal footer is visible in dark theme */
        #addCustomerModal .modal-footer,
        #editCustomerModal .modal-footer {
            background-color: #f8f9fa !important;
            border-top: 1px solid #dee2e6 !important;
            display: flex !important;
            justify-content: flex-end !important;
            gap: 1rem !important;
            padding: 1.5rem !important;
            position: sticky !important;
            bottom: 0 !important;
            z-index: 1051 !important;
        }

        /* Ensure modal header is visible */
        #addCustomerModal .modal-header,
        #editCustomerModal .modal-header {
            background-color: #0d6efd !important;
            color: white !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1051 !important;
        }

        #addCustomerModal .modal-footer button,
        #editCustomerModal .modal-footer button {
            z-index: 1060 !important;
            opacity: 1 !important;
            visibility: visible !important;
            position: relative !important;
        }

        /* Make save button more prominent */
        #addCustomerModal .btn-primary,
        #editCustomerModal .btn-warning {
            font-size: 1rem !important;
            padding: 0.75rem 2rem !important;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3) !important;
        }

        /* Make modal body scrollable */
        #addCustomerModal .modal-body,
        #editCustomerModal .modal-body {
            overflow-y: auto !important;
            max-height: calc(90vh - 140px) !important;
        }

        /* Make modal content height better */
        #addCustomerModal .modal-content,
        #editCustomerModal .modal-content {
            max-height: 90vh !important;
        }

        /* Ensure modal dialog is centered and visible */
        #addCustomerModal .modal-dialog,
        #editCustomerModal .modal-dialog {
            margin: 1.75rem auto !important;
        }

        /* Force modal to show correctly in dark mode */
        body.theme-dark #addCustomerModal .modal-content,
        body.theme-dark #editCustomerModal .modal-content {
            background-color: #111827 !important;
        }

        body.theme-dark #addCustomerModal .modal-footer,
        body.theme-dark #editCustomerModal .modal-footer {
            background-color: #0f1e35 !important;
            border-top: 1px solid #1e2d45 !important;
        }
</style>


<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-users me-2 text-primary"></i> إدارة العملاء</h3>
            <div class="d-flex align-items-center">
                <p class="text-muted small mb-0 me-3">إدارة وتعديل حسابات العملاء في شجرة الحسابات</p>
                <div class="ms-2 px-3 py-1 bg-success bg-opacity-10 border border-success border-opacity-20 rounded-pill shadow-sm small me-2">
                    <i class="fas fa-arrow-down me-1 text-success"></i>
                    إجمالي لنا: <span class="fw-bold text-success"><?php echo number_format($total_debit, 2); ?></span> <?php echo htmlspecialchars($baseCurrency['currency_name'] ?? ''); ?>
                </div>
                <div class="ms-2 px-3 py-1 bg-danger bg-opacity-10 border border-danger border-opacity-20 rounded-pill shadow-sm small">
                    <i class="fas fa-arrow-up me-1 text-danger"></i>
                    إجمالي علينا: <span class="fw-bold text-danger"><?php echo number_format($total_credit, 2); ?></span> <?php echo htmlspecialchars($baseCurrency['currency_name'] ?? ''); ?>
                </div>
            </div>
        </div>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة عميل جديد
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php
            if ($_GET['success'] == 1) echo "تمت إضافة العميل بنجاح.";
            if ($_GET['success'] == 2) echo "تم تحديث بيانات العميل بنجاح.";
            if ($_GET['success'] == 3) echo "تم تحويل العميل إلى خامل بنجاح.";
            if ($_GET['success'] == 4) echo "تم حذف العميل نهائيًا بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 py-3">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="customerSearch" class="form-control bg-light border-0" placeholder="بحث سريع باسم أو كود العميل...">
                    </div>
                </div>
                <div class="col-md-8 text-end">
                    <form method="GET" class="d-inline-flex">
                        <input type="text" name="q" class="form-control form-control-sm me-2" placeholder="بحث متقدم..." value="<?php echo h($_GET['q'] ?? ''); ?>">
                        <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3">بحث</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center" id="customersTable">
                    <thead class="bg-light text-secondary small text-uppercase fw-bold">
                        <tr>
                            <th class="px-4 py-3" colspan="8">تفاصيل الحساب المالي</th>
                        </tr>
                        <tr>
                            <th class="px-4 py-3">كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>الفرع</th>
                            <th>الرصيد الحالي</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                        <tr class="border-top">
                            <td class="px-4">
                                <code class="text-primary fw-bold"><?php echo $customer['account_code']; ?></code>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($customer['account_name_ar']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-normal">
                                    <i class="fas fa-building me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($customer['branch_name'] ?? 'عام'); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                // Display per-currency balances
                                if (isset($balances[$customer['id']]) && !empty($balances[$customer['id']])) {
                                    foreach ($balances[$customer['id']] as $bal) {
                                        echo '<div class="mb-1 small">' . format_account_balance($bal['current_balance'], $bal['normal_balance'], $bal['currency_name']) . '</div>';
                                    }
                                } else {
                                    // If no balance records, show 0.00 in base currency
                                    echo '<div class="mb-1 small text-muted">0.00 ' . htmlspecialchars($baseCurrency['currency_name'] ?? '') . '</div>';
                                }
                                
                                // Display per-customer totals in base currency
                                $cust_debit = $customer_totals[$customer['id']]['debit'] ?? 0;
                                $cust_credit = $customer_totals[$customer['id']]['credit'] ?? 0;
                                if ($cust_debit > 0 || $cust_credit > 0) {
                                    echo '<hr class="my-2">';
                                    if ($cust_debit > 0) {
                                        echo '<div class="small text-success"><i class="fas fa-arrow-down me-1"></i> لنا: ' . number_format($cust_debit, 2) . ' ' . htmlspecialchars($baseCurrency['currency_name'] ?? '') . '</div>';
                                    }
                                    if ($cust_credit > 0) {
                                        echo '<div class="small text-danger"><i class="fas fa-arrow-up me-1"></i> علينا: ' . number_format($cust_credit, 2) . ' ' . htmlspecialchars($baseCurrency['currency_name'] ?? '') . '</div>';
                                    }
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo get_account_status_label($customer['account_status']); ?>
                            </td>
                            <td rowspan="1">
                                <div class="btn-group">
                                    <a href="account_statement.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-light border-0" title="كشف حساب">
                                        <i class="fas fa-file-invoice-dollar text-primary"></i>
                                    </a>
                                    <button class="btn btn-sm btn-light border-0 edit-customer" 
                                            data-id="<?php echo $customer['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($customer['account_name_ar']); ?>"
                                            data-branch="<?php echo $customer['branch_id']; ?>"
                                            data-status="<?php echo $customer['account_status']; ?>"
                                            data-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                            data-whatsapp="<?php echo htmlspecialchars($customer['whatsapp'] ?? ''); ?>"
                                            data-identity_number="<?php echo htmlspecialchars($customer['identity_number'] ?? ''); ?>"
                                            data-passport_number="<?php echo htmlspecialchars($customer['passport_number'] ?? ''); ?>"
                                            data-nationality="<?php echo htmlspecialchars($customer['nationality'] ?? ''); ?>"
                                            data-start_date="<?php echo htmlspecialchars($customer['start_date'] ?? ''); ?>"
                                            data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                            data-notes="<?php echo htmlspecialchars($customer['notes'] ?? ''); ?>"
                                            title="تعديل"><i class="fas fa-edit text-warning"></i></button>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من تحويل هذا العميل إلى خامل؟')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="deactivate_account" value="<?php echo $customer['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border-0" title="تحويل إلى خامل"><i class="fas fa-pause text-secondary"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا العميل نهائيًا؟ هذا الإجراء لا يمكن التراجع عنه!')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_account_permanent" value="<?php echo $customer['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border-0" title="حذف نهائي"><i class="fas fa-trash text-danger"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة عميل -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 d-flex flex-column">
            <form method="POST" class="d-flex flex-column h-100">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white border-0 py-3">
            <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة عميل جديد</h5>
            <div class="d-flex gap-2">
                <button type="submit" name="add_customer_account" class="btn btn-light text-primary fw-bold">
                    <i class="fas fa-save me-1"></i> حفظ
                </button>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
            </div>
        </div>
        <div class="modal-body p-4 flex-grow-1 overflow-auto">
            <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">اسم العميل <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" class="form-control rounded-3" placeholder="مثلاً: محمد أحمد" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">رقم الهاتف</label>
                            <input type="text" name="phone" class="form-control rounded-3" placeholder="مثلاً: +967771234567">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">رقم الواتساب</label>
                            <input type="text" name="whatsapp" class="form-control rounded-3" placeholder="مثلاً: 731234567">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">الجنسية</label>
                            <select name="nationality" class="form-select rounded-3">
                                <option value="">-- اختر الجنسية --</option>
                                <?php foreach ($nationalities as $nat): ?>
                                    <option value="<?php echo htmlspecialchars($nat); ?>"><?php echo htmlspecialchars($nat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">تاريخ البدء</label>
                            <input type="date" name="start_date" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">الفرع المربوط به <span class="text-danger">*</span></label>
                            <select name="branch_id" class="form-select rounded-3" required>
                                <option value="">-- اختر الفرع --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">العنوان</label>
                            <textarea name="address" class="form-control rounded-3" rows="2" placeholder="العنوان التفصيلي"></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الملاحظات</label>
                            <textarea name="notes" class="form-control rounded-3" rows="2" placeholder="ملاحظات إضافية"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">العملة</label>
                            <select name="currency_id" class="form-select rounded-3">
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $base_currency_id) ? 'selected' : ''; ?>>
                                        <?php echo $c['currency_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">الرصيد الافتتاحي</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control rounded-3" value="0.00">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الحالة</label>
                            <select name="status" class="form-select rounded-3">
                                <option value="active">نشط</option>
                                <option value="closed">مغلق (للتصفية)</option>
                                <option value="dormant">راكد (غير مستخدم)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm flex-shrink-0">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_customer_account" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ العميل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل عميل -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 d-flex flex-column">
            <form method="POST" class="d-flex flex-column h-100">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات العميل</h5>
                    <div class="d-flex gap-2">
                        <button type="submit" name="update_customer_account" class="btn btn-light text-dark fw-bold">
                            <i class="fas fa-save me-1"></i> حفظ
                        </button>
                        <button type="button" class="btn-close ms-2" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-4 flex-grow-1 overflow-auto">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">اسم العميل <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">رقم الهاتف</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control rounded-3" placeholder="مثلاً: +967771234567">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold small">رقم الواتساب</label>
                            <input type="text" name="whatsapp" id="edit_whatsapp" class="form-control rounded-3" placeholder="مثلاً: 731234567">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">الجنسية</label>
                            <select name="nationality" id="edit_nationality" class="form-select rounded-3">
                                <option value="">-- اختر الجنسية --</option>
                                <?php foreach ($nationalities as $nat): ?>
                                    <option value="<?php echo htmlspecialchars($nat); ?>"><?php echo htmlspecialchars($nat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">تاريخ البدء</label>
                            <input type="date" name="start_date" id="edit_start_date" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">الفرع المربوط به</label>
                            <select name="branch_id" id="edit_branch" class="form-select rounded-3">
                                <option value="">-- عام (بدون فرع) --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">العنوان</label>
                            <textarea name="address" id="edit_address" class="form-control rounded-3" rows="2" placeholder="العنوان التفصيلي"></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الملاحظات</label>
                            <textarea name="notes" id="edit_notes" class="form-control rounded-3" rows="2" placeholder="ملاحظات إضافية"></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الحالة</label>
                            <select name="status" id="edit_status" class="form-select rounded-3">
                                <option value="active">نشط</option>
                                <option value="inactive">خامل</option>
                                <option value="closed">مغلق (للتصفية)</option>
                                <option value="dormant">راكد (غير مستخدم)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light shadow-sm flex-shrink-0">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_customer_account" class="btn btn-warning rounded-pill px-5 fw-bold shadow">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.edit-customer').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var branch = $(this).data('branch');
        var status = $(this).data('status');
        var phone = $(this).data('phone');
        var whatsapp = $(this).data('whatsapp');
        var identity_number = $(this).data('identity_number');
        var passport_number = $(this).data('passport_number');
        var nationality = $(this).data('nationality');
        var start_date = $(this).data('start_date');
        var address = $(this).data('address');
        var notes = $(this).data('notes');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_branch').val(branch);
        $('#edit_status').val(status);
        $('#edit_phone').val(phone);
        $('#edit_whatsapp').val(whatsapp);
        $('#edit_identity_number').val(identity_number);
        $('#edit_passport_number').val(passport_number);
        $('#edit_nationality').val(nationality);
        $('#edit_start_date').val(start_date);
        $('#edit_address').val(address);
        $('#edit_notes').val(notes);
        
        $('#editCustomerModal').modal('show');
    });

    // بحث ديناميكي في الجدول
    $("#customerSearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#customersTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});
</script>

<?php require_once 'footer.php'; ?>
