<?php
require_once 'header.php';

$message = '';
$status = '';

// معالجة الحفظ (تحديث الحدود المالية الموحدة)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_unified_limit'])) {
    $account_id = $_POST['account_id'];
    $credit_limit_base = floatval($_POST['credit_limit_base']);
    $debit_limit_base = floatval($_POST['debit_limit_base']);

    try {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET credit_limit_base = ?, debit_limit_base = ? WHERE id = ?");
        $stmt->execute([$credit_limit_base, $debit_limit_base, $account_id]);

        $message = "تم تحديث الحدود المالية الموحدة بنجاح.";
        $status = "success";
    } catch (Exception $e) {
        $message = "خطأ: " . $e->getMessage();
        $status = "danger";
    }
}

// معالجة الحفظ (تفعيل العملة والرصيد الافتتاحي)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_currency'])) {
    $account_id = $_POST['account_id'];
    $currency_code = $_POST['currency_code'];
    $opening_balance = floatval($_POST['opening_balance']);

    try {
        $stmt_curr = $pdo->prepare("SELECT id, exchange_rate FROM currencies WHERE currency_code = ?");
        $stmt_curr->execute([$currency_code]);
        $currency_data = $stmt_curr->fetch(PDO::FETCH_ASSOC);
        $currency_id = $currency_data['id'];
        $exchange_rate = $currency_data['exchange_rate'] ?? 1;

        if (!$currency_id) {
            throw new Exception("العملة غير موجودة");
        }

        // هذه الشاشة تعمل على مستوى الحساب + العملة، وليس مستوى الفروع.
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
        $stmt_check->execute([$account_id, $currency_id]);
        $existing_count = (int)$stmt_check->fetchColumn();

        $opening_balance_base = $opening_balance * $exchange_rate;
        $current_balance_base = $opening_balance_base;

        if ($existing_count > 0) {
            throw new Exception("العملة مفعلة بالفعل لهذا الحساب.");
        } else {
            // إضافة رصيد جديد
            $stmt_insert = $pdo->prepare("INSERT INTO account_balances_unified 
                (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_insert->execute([$account_id, null, $currency_id, $currency_code, $opening_balance, $opening_balance, $opening_balance_base, $current_balance_base]);
        }

        $message = "تم تفعيل العملة بنجاح.";
        $status = "success";
    } catch (Exception $e) {
        $message = "خطأ: " . $e->getMessage();
        $status = "danger";
    }
}

// معالجة حذف العملة من الحساب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_currency'])) {
    $account_id = $_POST['account_id'];
    $currency_id = $_POST['currency_id'] ?? null;

    try {
        if (!$currency_id) {
            throw new Exception("العملة المطلوب حذفها غير محددة.");
        }

        // التحقق أن جميع صفوف هذه العملة للحساب رصيدها صفر قبل الحذف
        $stmt_check_balance = $pdo->prepare("
            SELECT 
                COUNT(*) AS row_count,
                COALESCE(SUM(ABS(current_balance)), 0) AS total_abs_balance
            FROM account_balances_unified
            WHERE account_id = ? AND currency_id = ?
        ");
        $stmt_check_balance->execute([$account_id, $currency_id]);
        $balance = $stmt_check_balance->fetch(PDO::FETCH_ASSOC);
        
        if (!$balance || (int)($balance['row_count'] ?? 0) === 0) {
            throw new Exception("العملة المطلوب حذفها غير موجودة");
        }
        
        if (floatval($balance['total_abs_balance']) > 0.00001) {
            throw new Exception("لا يمكن حذف العملة لأن الرصيد الحالي ليس صفرًا. يرجى تصفية الرصيد أولاً.");
        }

        // حذف جميع صفوف هذه العملة لهذا الحساب
        $stmt_delete = $pdo->prepare("DELETE FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
        $stmt_delete->execute([$account_id, $currency_id]);

        $message = "تم حذف العملة بنجاح.";
        $status = "success";
    } catch (Exception $e) {
        $message = "خطأ: " . $e->getMessage();
        $status = "danger";
    }
}

// معالجة الحفظ (تحديث الحدود المالية)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_limits'])) {
    $account_id = $_POST['account_id'];
    $currency_id = $_POST['currency_id'] ?? null;
    $credit_limit = floatval($_POST['credit_limit']);
    $debit_limit = floatval($_POST['debit_limit']);
    $is_frozen = isset($_POST['is_frozen']) ? 1 : 0;

    try {
        if (!$currency_id) {
            throw new Exception("يرجى اختيار العملة أولاً.");
        }

        // Fetch current balance for validation using currency_id
        $stmt_current_balance = $pdo->prepare("
            SELECT 
                COALESCE(SUM(ABS(current_balance)), 0) AS total_abs_balance,
                MAX(is_frozen) AS is_frozen
            FROM account_balances_unified
            WHERE account_id = ? AND currency_id = ?
        ");
        $stmt_current_balance->execute([$account_id, $currency_id]);
        $current_balance_row = $stmt_current_balance->fetch(PDO::FETCH_ASSOC);

        if (!$current_balance_row || ($current_balance_row['total_abs_balance'] === null && $current_balance_row['is_frozen'] === null)) {
            throw new Exception("العملة غير مفعلة لهذا الحساب.");
        }

        $current_balance = floatval($current_balance_row['total_abs_balance']);
        $old_is_frozen = (int)$current_balance_row['is_frozen'];

        // إذا كان يحاول التجميد (is_frozen=1) وكان الرصيد ليس صفراً، نمنعه
        if ($is_frozen === 1 && $old_is_frozen === 0 && $current_balance > 0.00001) {
            throw new Exception("لا يمكن تجميد العملة لأن الرصيد الحالي ليس صفراً (" . number_format($current_balance, 2) . "). يرجى تصفية الرصيد أولاً.");
        }

        // تحديث الحدود المالية وحالة التجميد
        $stmt = $pdo->prepare("UPDATE account_balances_unified 
            SET credit_limit = ?, debit_limit = ?, is_frozen = ? 
            WHERE account_id = ? AND currency_id = ?");
        $stmt->execute([$credit_limit, $debit_limit, $is_frozen, $account_id, $currency_id]);

        if ($stmt->rowCount() > 0) {
            $message = "تم تحديث الحدود المالية بنجاح.";
            $status = "success";
        } else {
            $message = "لم يتم إجراء أي تغييرات أو العملة غير مفعلة للحساب.";
            $status = "info";
        }
    } catch (Exception $e) {
        $message = "خطأ: " . $e->getMessage();
        $status = "danger";
    }
}

// جلب العملات
$currencies = $pdo->query("SELECT currency_code, currency_name, exchange_rate FROM currencies")->fetchAll();

// جلب العملة الافتراضية
$default_currency = $pdo->query("SELECT currency_name, currency_symbol, currency_code FROM currencies WHERE is_default = 1")->fetch();

// جلب إعدادات الرقابة المالية
$stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('enable_customer_limit_check', 'enable_supplier_limit_check', 'enable_debit_limit_check')");
$settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
$is_debit_check_enabled = (bool)($settings['enable_debit_limit_check'] ?? true);

// جلب معرفات الحسابات للكيانات النشطة وغير المحذوفة مع نوع الكيان
$account_type_map = []; // key: account_id, value: type (customer, agent, supplier, employee, branch)

// العملاء النشطين
$stmt = $pdo->query("SELECT DISTINCT account_id FROM customers WHERE deleted_at IS NULL AND status = 'active' AND account_id IS NOT NULL");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $acc_id) {
    $account_type_map[intval($acc_id)] = 'customer';
}

// الوكلاء النشطين
$stmt = $pdo->query("SELECT DISTINCT account_id FROM agents WHERE deleted_at IS NULL AND status = 'active' AND account_id IS NOT NULL");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $acc_id) {
    $account_type_map[intval($acc_id)] = 'agent';
}

// الموردين النشطين
$stmt = $pdo->query("SELECT DISTINCT account_id FROM suppliers WHERE deleted_at IS NULL AND status = 'active' AND account_id IS NOT NULL");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $acc_id) {
    $account_type_map[intval($acc_id)] = 'supplier';
}

// الموظفين النشطين
$stmt = $pdo->query("SELECT DISTINCT account_id FROM employees WHERE deleted_at IS NULL AND status = 'active' AND account_id IS NOT NULL");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $acc_id) {
    $account_type_map[intval($acc_id)] = 'employee';
}

// الفروع النشطة
$stmt = $pdo->query("SELECT DISTINCT account_id FROM branches WHERE deleted_at IS NULL AND status = 'active' AND account_id IS NOT NULL");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $acc_id) {
    $account_type_map[intval($acc_id)] = 'branch';
}

$active_entity_account_ids = array_keys($account_type_map);

// جلب جميع الحسابات النشطة
$all_accounts_raw = $pdo->query("
    SELECT id, account_code, account_name_ar, parent_id, credit_limit_base, debit_limit_base 
    FROM unified_accounts 
    WHERE is_active = 1 
    ORDER BY account_code
")->fetchAll();

// تصفية الحسابات الرئيسية ليعرض فقط الحسابات النشطة
$accounts = [];
foreach ($all_accounts_raw as $acc) {
    $accounts[] = $acc;
}

// تصنيف الحسابات حسب النوع
$categorized_accounts = [
    'customers' => [], 'suppliers' => [], 'banks' => [], 'boxes' => [], 
    'expenses' => [], 'employees' => [], 'agents' => [], 'branches' => [], 'other' => []
];

foreach ($accounts as $acc) {
    $code = $acc['account_code'];
    $acc_id = intval($acc['id']);
    $added = false;
    
    // تحقق أولاً إذا كان الحساب مرتبط بكيان نشط
    if (isset($account_type_map[$acc_id])) {
        $entity_type = $account_type_map[$acc_id];
        if ($entity_type === 'customer') {
            $categorized_accounts['customers'][] = $acc;
            $added = true;
        } elseif ($entity_type === 'supplier') {
            $categorized_accounts['suppliers'][] = $acc;
            $added = true;
        } elseif ($entity_type === 'employee') {
            $categorized_accounts['employees'][] = $acc;
            $added = true;
        } elseif ($entity_type === 'agent') {
            $categorized_accounts['agents'][] = $acc;
            $added = true;
        } elseif ($entity_type === 'branch') {
            $categorized_accounts['branches'][] = $acc;
            $added = true;
        }
    }
    
    // إذا لم يتم إضافته من خلال كيان، تحقق من كود الحساب
    if (!$added) {
        if (strpos($code, '11201') === 0) {
            $categorized_accounts['customers'][] = $acc;
        } elseif (strpos($code, '21101') === 0) {
            $categorized_accounts['suppliers'][] = $acc;
        } elseif (strpos($code, '11203') === 0) {
            $categorized_accounts['agents'][] = $acc;
        } elseif (strpos($code, '11202') === 0) {
            $categorized_accounts['branches'][] = $acc;
        } elseif (strpos($code, '21103') === 0) {
            $categorized_accounts['employees'][] = $acc;
        } elseif (strpos($code, '11102') === 0) {
            $categorized_accounts['banks'][] = $acc;
        } elseif (strpos($code, '11101') === 0) {
            $categorized_accounts['boxes'][] = $acc;
        } elseif (strpos($code, '501') === 0 || strpos($code, '502') === 0 || strpos($code, '503') === 0) {
            $categorized_accounts['expenses'][] = $acc;
        } else {
            $categorized_accounts['other'][] = $acc;
        }
    }
}
?>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary mb-0"><i class="fas fa-coins me-2"></i> إدارة العملات والحدود المالية</h3>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $status; ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas <?php echo $status === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- قسم اختيار الحساب -->
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><i class="fas fa-filter me-1 text-primary"></i> نوع الحساب</label>
                            <select id="account_type_filter" class="form-select rounded-pill shadow-sm">
                                <option value="all">-- الكل --</option>
                                <option value="customers">العملاء</option>
                                <option value="suppliers">الموردين</option>
                                <option value="banks">البنوك</option>
                                <option value="boxes">الصناديق</option>
                                <option value="expenses">المصروفات</option>
                                <option value="employees">الموظفين</option>
                                <option value="agents">الوكلاء</option>
                                <option value="branches">الفروع</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><i class="fas fa-user-tag me-1 text-primary"></i> اختر الحساب</label>
                            <select id="account_select" class="form-select rounded-pill select2 shadow-sm">
                                <option value="">-- اختر الحساب --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>" data-code="<?php echo $acc['account_code']; ?>"><?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- قسم ملخص الأرصدة المضافة حديثاً -->
                    <div id="account_balance_summary" class="mt-4 d-none">
                        <hr class="opacity-25">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0 text-secondary"><i class="fas fa-wallet me-2"></i> العملات المفعلة والأرصدة الحالية:</h6>
                        </div>
                        <div id="summary_cards" class="row g-2">
                            <!-- سيتم تعبئته بواسطة JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- قسم التبويبات (تفعيل العملة والحدود) -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 p-0 rounded-top-4 overflow-hidden">
                    <ul class="nav nav-tabs nav-justified border-0" id="managementTabs">
                        <li class="nav-item">
                            <button class="nav-link active py-3 fw-bold border-0" data-bs-toggle="tab" data-bs-target="#currency_tab">
                                <i class="fas fa-plus-circle me-2"></i> تفعيل العملة
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link py-3 fw-bold border-0" data-bs-toggle="tab" data-bs-target="#unified_limit_tab">
                                <i class="fas fa-shield-alt me-2"></i> الحد الموحد
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link py-3 fw-bold border-0" data-bs-toggle="tab" data-bs-target="#limits_tab">
                                <i class="fas fa-snowflake me-2"></i> تجميد العملات
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body p-4">
                    <div class="tab-content">
                        <!-- تبويب الحد الموحد -->
                        <div class="tab-pane fade" id="unified_limit_tab">
                            <form method="POST">
                                <input type="hidden" name="account_id" class="selected-account-id">
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-danger">الحد الائتماني (مديونية الطرف الآخر لنا)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0 rounded-start-pill"><i class="fas fa-arrow-up text-danger"></i></span>
                                        <input type="number" step="0.01" name="credit_limit_base" id="field_credit_limit_base" class="form-control shadow-sm" value="0.00" required>
                                        <span class="input-group-text bg-light border-0 rounded-end-pill extra-small"><?php echo $default_currency['currency_code']; ?></span>
                                    </div>
                                    <small class="text-muted d-block mt-1">أقصى مبلغ يسمح للعميل/الوكيل أن يتدين به منا (بالعملة الأساسية).</small>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold text-success">
                                        الحد الدائن (مديونيتنا نحن للطرف الآخر)
                                        <?php if (!$is_debit_check_enabled): ?>
                                            <span class="badge bg-danger-subtle text-danger extra-small ms-1" title="الرقابة معطلة من الإعدادات العامة"><i class="fas fa-exclamation-triangle"></i> معطل</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success extra-small ms-1" title="الرقابة مفعلة"><i class="fas fa-check-circle"></i> مفعل</span>
                                        <?php endif; ?>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0 rounded-start-pill"><i class="fas fa-arrow-down text-success"></i></span>
                                        <input type="number" step="0.01" name="debit_limit_base" id="field_debit_limit_base" class="form-control shadow-sm" value="0.00" required>
                                        <span class="input-group-text bg-light border-0 rounded-end-pill extra-small"><?php echo $default_currency['currency_code']; ?></span>
                                    </div>
                                    <small class="text-muted d-block mt-1">أقصى مبلغ يسمح لنا أن نتدين به من المورد أو فائض إيداع العميل (بالعملة الأساسية).</small>
                                </div>

                                <div class="alert alert-info border-0 rounded-4 small">
                                    <strong><i class="fas fa-info-circle me-1"></i> توضيح:</strong>
                                    <ul class="mb-0 mt-1 ps-3">
                                        <li><strong>الحد الائتماني:</strong> يحمي المكتب من زيادة ديون الآخرين (مثل مبيعات الآجل).</li>
                                        <li><strong>الحد الدائن:</strong> يحمي المكتب من زيادة التزاماته تجاه الآخرين (مثل المشتريات بالدين).</li>
                                    </ul>
                                </div>

                                <button type="submit" name="save_unified_limit" class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm action-btn d-none">
                                    حفظ الحدود الموحدة
                                </button>
                                <div class="alert alert-info py-2 rounded-3 text-center account-notice">
                                    <i class="fas fa-info-circle me-1"></i> يرجى اختيار حساب أولاً
                                </div>
                            </form>
                        </div>

                        <!-- تبويب تفعيل العملة -->
                        <div class="tab-pane fade show active" id="currency_tab">
                            <form method="POST">
                                <input type="hidden" name="account_id" class="selected-account-id">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">العملة <span class="text-danger">*</span></label>
                                    <select name="currency_code" id="new_currency_select" class="form-select rounded-pill" required>
                                        <?php foreach ($currencies as $curr): ?>
                                            <option value="<?php echo $curr['currency_code']; ?>"><?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_code']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold">الرصيد الافتتاحي <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0 rounded-start-pill"><i class="fas fa-money-bill-wave text-success"></i></span>
                                        <input type="number" step="0.01" name="opening_balance" class="form-control rounded-end-pill shadow-sm" value="0.00" required>
                                    </div>
                                </div>
                                <button type="submit" name="save_currency" class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm action-btn d-none">
                                    تفعيل العملة المحددة
                                </button>
                                <div class="alert alert-info py-2 rounded-3 text-center account-notice">
                                    <i class="fas fa-info-circle me-1"></i> يرجى اختيار حساب أولاً
                                </div>
                            </form>
                        </div>

                        <!-- تبويب تجميد العملات -->
                        <div class="tab-pane fade" id="limits_tab">
                            <form method="POST">
                                <input type="hidden" name="account_id" class="selected-account-id">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">العملة المفعلة <span class="text-danger">*</span></label>
                                    <select name="currency_id" id="active_currencies_select" class="form-select rounded-pill" required>
                                        <option value="">-- اختر العملة --</option>
                                    </select>
                                    <small class="text-muted d-block mt-1">تظهر هنا العملات المفعلة للحساب المختار فقط</small>
                                </div>
                                <div class="mb-4">
                                    <div class="form-check form-switch p-3 bg-light rounded-4 border">
                                        <input class="form-check-input ms-0" type="checkbox" name="is_frozen" id="field_is_frozen">
                                        <label class="form-check-label fw-bold text-danger me-5" for="field_is_frozen">
                                            <i class="fas fa-snowflake me-1"></i> تجميد التعامل بهذه العملة
                                        </label>
                                    </div>
                                    <small id="frozen_notice" class="text-danger mt-2 d-block fw-bold" style="display: none;"></small>
                                    <small class="text-muted mt-2 d-block">عند التجميد، لن يتمكن النظام من إجراء أي عمليات قبض أو صرف أو فواتير بهذه العملة لهذا الحساب.</small>
                                </div>
                                <input type="hidden" name="credit_limit" value="0.00">
                                <input type="hidden" name="debit_limit" value="0.00">
                                <button type="submit" name="save_limits" class="btn btn-warning w-100 rounded-pill py-2 fw-bold shadow-sm action-btn d-none">
                                    تحديث حالة التجميد
                                </button>
                                <div class="alert alert-info py-2 rounded-3 text-center account-notice">
                                    <i class="fas fa-info-circle me-1"></i> يرجى اختيار حساب أولاً
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- جدول العرض المفلتر -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 rounded-top-4 border-bottom">
                    <ul class="nav nav-pills card-header-pills" id="pills-tab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active rounded-pill fw-bold" id="pills-balances-tab" data-bs-toggle="pill" data-bs-target="#pills-balances" type="button" role="tab"><i class="fas fa-wallet me-1"></i> الأرصدة والحدود</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link rounded-pill fw-bold" id="pills-transactions-tab" data-bs-toggle="pill" data-bs-target="#pills-transactions" type="button" role="tab"><i class="fas fa-history me-1"></i> العمليات الأخيرة</button>
                        </li>
                    </ul>
                </div>
                <div class="card-body p-0">
                    <div class="tab-content" id="pills-tabContent">
                        <!-- تبويب الأرصدة -->
                        <div class="tab-pane fade show active" id="pills-balances" role="tabpanel">
                            <div id="filtered_balance_table" class="table-responsive d-none" style="max-height: 500px;">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light sticky-top">
                                        <tr>
                                    <th class="px-4 py-3">العملة</th>
                                    <th>الرصيد الافتتاحي</th>
                                    <th>الرصيد الحالي</th>
                                    <th>الرصيد بالعملة الأساسية</th>
                                    <th>الحدود المالية</th>
                                    <th class="text-center">الحالة</th>
                                    <th class="text-center">إجراءات</th>
                                </tr>
                                    </thead>
                                    <tbody id="filtered_balance_body">
                                        <!-- سيتم تعبئته بواسطة JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                            <div id="no_account_selected" class="p-5 text-center text-muted">
                                <i class="fas fa-user-circle fa-3x mb-3 opacity-25"></i>
                                <p>يرجى اختيار حساب لعرض أرصدته التفصيلية</p>
                            </div>
                        </div>
                        
                        <!-- تبويب العمليات الأخيرة -->
                        <div class="tab-pane fade" id="pills-transactions" role="tabpanel">
                            <div id="recent_transactions_table" class="table-responsive d-none" style="max-height: 500px;">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="bg-light sticky-top">
                                        <tr>
                                            <th class="px-3 py-2">التاريخ / الرقم</th>
                                            <th>البيان</th>
                                            <th>مدين</th>
                                            <th>دائن</th>
                                            <th>العملة</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recent_transactions_body">
                                        <!-- سيتم تعبئته بواسطة JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                            <div id="no_transactions_msg" class="p-5 text-center text-muted">
                                <i class="fas fa-exchange-alt fa-3x mb-3 opacity-25"></i>
                                <p>لا توجد عمليات مسجلة لهذا الحساب حالياً</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .nav-tabs .nav-link { color: #6c757d; background: #f8f9fa; }
    .nav-tabs .nav-link.active { color: #0d6efd; background: #fff; border-bottom: 3px solid #0d6efd !important; }
    .extra-small { font-size: 0.75rem; }
    .pulse-animation { animation: pulse 2s infinite; }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    .select2-container--bootstrap-5 .select2-selection { border-radius: 50px !important; }
</style>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    const categorizedAccounts = <?php echo json_encode($categorized_accounts); ?>;
    const allAccounts = <?php echo json_encode($accounts); ?>;
    const defaultCurrencyInfo = <?php echo json_encode($default_currency); ?>;
    let currentAccountBalances = [];

    $(document).ready(function() {
        $('.select2').select2({ theme: 'bootstrap-5', dir: 'rtl' });

        // PHP-provided account data
        const allAccountsData = <?php echo json_encode($accounts); ?>; // All accounts, flat list
        const categorizedAccountsData = <?php echo json_encode($categorized_accounts); ?>; // Categorized accounts

        // Load saved selections from localStorage
        let savedAccountType = localStorage.getItem('account_type_filter');
        let savedAccountId = localStorage.getItem('account_select_id');
        console.log('Loaded from localStorage - Type:', savedAccountType, 'ID:', savedAccountId);

        if (savedAccountType) {
            $('#account_type_filter').val(savedAccountType);
            console.log('Set account_type_filter to:', savedAccountType);
        }

        // Function to filter accounts based on type
        function filterAccounts() {
            console.log('filterAccounts called.');
            const selectedType = $('#account_type_filter').val();
            const $accountSelect = $('#account_select');
            let accountsToShow = [];

            if (selectedType === 'all') {
                // If 'all' is selected, combine all accounts from the flat list to preserve order if needed
                accountsToShow = allAccountsData;
            } else if (categorizedAccountsData[selectedType]) {
                accountsToShow = categorizedAccountsData[selectedType];
            }
            console.log('Accounts to show after filter:', accountsToShow);

            $accountSelect.empty().append('<option value="">-- اختر الحساب --</option>');
            accountsToShow.forEach(account => {
                $accountSelect.append(`<option value="${account.id}" data-code="${account.account_code}">${account.account_code} - ${account.account_name_ar}</option>`);
            });

            $accountSelect.val(''); // Reset to default

            // Restore savedAccountId only if it exists within the currently filtered accounts
            if (savedAccountId && accountsToShow.some(account => account.id == savedAccountId)) {
                $accountSelect.val(savedAccountId);
                console.log('Restored account_select to saved ID:', savedAccountId);
            } else {
                savedAccountId = ''; // Clear saved ID if not found in filtered list
                localStorage.removeItem('account_select_id');
                console.log('Cleared savedAccountId as it was not found in filtered list.');
            }

            $accountSelect.trigger('change'); // Trigger change to load balances if an account is selected
            $accountSelect.select2({
                placeholder: "ابحث عن حساب...",
                allowClear: true,
                width: '100%'
            });
        }

        // Initial filter when the page loads
        filterAccounts();

        // Trigger change for account type filter to ensure default 'All' or saved type is applied
        $('#account_type_filter').on('change', function() {
            console.log('account_type_filter changed to:', $(this).val());
            localStorage.setItem('account_type_filter', $(this).val());
            filterAccounts();
        });

        // Handle account selection change
        $('#account_select').on('change', function() {
            const accountId = $(this).val();
            console.log('account_select changed to ID:', accountId);
            if (accountId) {
                localStorage.setItem('account_select_id', accountId);
                console.log('Saved account_select_id to localStorage:', accountId);
                
                // جلب بيانات الحساب المختار لتعبئة الحد الموحد
                const accountData = allAccountsData.find(a => a.id == accountId);
                if (accountData) {
                    $('#field_credit_limit_base').val(accountData.credit_limit_base || '0.00');
                    $('#field_debit_limit_base').val(accountData.debit_limit_base || '0.00');
                }
                
                loadAccountBalances(accountId);
            } else {
                localStorage.removeItem('account_select_id');
                console.log('Removed account_select_id from localStorage.');
                // Clear displayed data if no account is selected
                $('#account_balance_summary').addClass('d-none');
                $('#no_account_selected').removeClass('d-none');
                $('#filtered_balance_body').empty();
                $('#summary_cards').empty();
                $('.action-btn').addClass('d-none');
                $('.account-notice').removeClass('d-none');
                $('.selected-account-id').val('');
                $('#active_currencies_select').empty().append('<option value="">-- اختر العملة --</option>');
                // Clear form fields in tabs
                $('#new_currency_select').val('').trigger('change');
                $('input[name="opening_balance"]').val('0.00');
                $('#field_credit_limit').val('0.00');
                $('#field_debit_limit').val('0.00');
                $('#field_is_frozen').prop('checked', false);
            }
        });

        // Ensure filterAccounts is called after all initial setup is done
        // And if a savedAccountId exists, trigger change to load its balances
        if (savedAccountId) {
            console.log('savedAccountId exists, triggering change for:', savedAccountId);
            // This needs a slight delay to ensure select2 is fully initialized
            setTimeout(() => {
                $('#account_select').val(savedAccountId).trigger('change');
            }, 100);
        }


        function loadAccountBalances(accountId) {
            console.log('loadAccountBalances called with accountId:', accountId);
            const $newCurrencySelect = $('#new_currency_select'); // Declared once at the top of the function

            if (!accountId) {
                console.log('No accountId provided to loadAccountBalances.');
                // Clear displayed data if no account is selected
                $('#account_balance_summary').addClass('d-none');
                $('#filtered_balance_table').addClass('d-none');
                $('#no_account_selected').removeClass('d-none');
                $('#recent_transactions_table').addClass('d-none');
                $('#filtered_balance_body').empty();
                $('#summary_cards').empty();
                $('.action-btn').addClass('d-none');
                $('.account-notice').removeClass('d-none');
                $('.selected-account-id').val('');
                $('#active_currencies_select').empty().append('<option value="">-- اختر العملة --</option>');
                // Clear form fields in tabs
                $newCurrencySelect.val('').trigger('change');
                $newCurrencySelect.find('option').show(); // Show all options when no account is selected
                $('input[name="opening_balance"]').val('0.00');
                $('#field_credit_limit').val('0.00');
                $('#field_debit_limit').val('0.00');
                $('#field_is_frozen').prop('checked', false);
                return;
            }

            $('.selected-account-id').val(accountId);
            $('.action-btn').removeClass('d-none');
            $('.account-notice').addClass('d-none');

            // جلب العملات والحدود المفعلة للحساب
            $.get('ajax_get_account_balances.php', { account_id: accountId }, function(data) {
                currentAccountBalances = data;
                const $activeCurrenciesSelect = $('#active_currencies_select'); // Renamed for clarity
                const $newCurrencySelect = $('#new_currency_select');
                const $summaryCards = $('#summary_cards');
                const $summarySection = $('#account_balance_summary');
                const $tableContainer = $('#filtered_balance_table');
                const $tableBody = $('#filtered_balance_body');
                const $noSelection = $('#no_account_selected');
                
                $activeCurrenciesSelect.empty().append('<option value="">-- اختر العملة --</option>');
                console.log('Populating active_currencies_select with:', currentAccountBalances);
                currentAccountBalances.forEach(bal => {
                    $activeCurrenciesSelect.append(`
                        <option value="${bal.currency_id}" 
                                data-code="${bal.currency_code}" 
                                data-credit="${bal.credit_limit || '0.00'}" 
                                data-debit="${bal.debit_limit || '0.00'}" 
                                data-balance="${bal.current_balance || 0}"
                                data-frozen="${bal.is_frozen || 0}">
                            ${bal.currency_name || ''} (${bal.currency_code || ''})
                        </option>`);
                });
                $summaryCards.empty();
                $tableBody.empty();
                
            // Show/hide action buttons and notices in the limits tab
            const $limitsTabActionBtn = $('#limits_tab button[name="save_limits"]');
            const $limitsTabNotice = $('#limits_tab .account-notice');
            if (currentAccountBalances.length > 0) {
                $limitsTabActionBtn.removeClass('d-none');
                $limitsTabNotice.addClass('d-none');
            } else {
                $limitsTabActionBtn.addClass('d-none');
                $limitsTabNotice.removeClass('d-none');
            }
                
            // Hide already activated currencies from the "Activate Currency" dropdown
            const activeCurrencyCodes = currentAccountBalances.map(bal => bal.currency_code);
            console.log('Active Currency Codes for hiding:', activeCurrencyCodes);
            $('#new_currency_select option').each(function() {
                const code = $(this).val();
                console.log('Checking currency:', code, 'Is active?', activeCurrencyCodes.includes(code));
                if (code !== '' && activeCurrencyCodes.includes(code)) {
                    $(this).hide();
                } else {
                    $(this).show();
                }
            });
            // Ensure the selected currency is visible. If not, select the first visible option.
             if ($newCurrencySelect.find('option:selected').is(':hidden')) {
                 const firstVisibleOption = $newCurrencySelect.find('option:visible:first');
                 if (firstVisibleOption.length) {
                     $newCurrencySelect.val(firstVisibleOption.val()).trigger('change');
                  } else {
                     $newCurrencySelect.val('').trigger('change'); // Fallback to empty if no visible options
                  }
             }
                
                if (data.length > 0) {
                    $summarySection.removeClass('d-none');
                    $tableContainer.removeClass('d-none');
                    $noSelection.addClass('d-none');
                    
                    // حساب صافي الرصيد الموحد بالعملة الأساسية من الحقل المحسوب في قاعدة البيانات
                    let totalNetBalanceBase = 0;
                    let systemBaseCurrency = null;

                    // البحث عن العملة الأساسية للنظام من البيانات المجلوبة
                    if (data && data.length > 0) {
                        systemBaseCurrency = data.find(bal => bal.is_default == 1);
                    }

                    data.forEach(bal => {
                        const currentBal = parseFloat(bal.current_balance) || 0;
                        const currentBalBase = parseFloat(bal.current_balance_base) || 0;
                        
                        totalNetBalanceBase += currentBalBase;

                        // تخطي إضافة بطاقة صغيرة للعملة الأساسية لتجنب التكرار مع البطاقة الكبيرة
                        if (bal.is_default == 1) return;

                        // إضافة بطاقة ملخص لكل عملة أخرى
                        let statusText = '';
                        let statusClass = '';
                        let debitCreditText = '';
                        
                        if (currentBal === 0) {
                            statusText = 'متعادل';
                            statusClass = 'bg-secondary';
                            debitCreditText = '-';
                        } else {
                            debitCreditText = currentBal > 0 ? 'دائن' : 'مدين';
                            if (bal.normal_balance === 'debit') {
                                statusText = currentBal > 0 ? 'له' : 'عليه';
                                statusClass = currentBal > 0 ? 'bg-success' : 'bg-danger';
                            } else {
                                statusText = currentBal > 0 ? 'عليه' : 'له';
                                statusClass = currentBal > 0 ? 'bg-danger' : 'bg-success';
                            }
                        }

                        $summaryCards.append(`
                            <div class="col-md-4 col-sm-6">
                                <div class="card border-0 shadow-sm h-100 rounded-3 overflow-hidden border-start border-4 ${currentBal >= 0 ? 'border-success' : 'border-danger'}">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="badge ${statusClass} rounded-pill px-2">${statusText}</span>
                                            <span class="fw-bold text-muted extra-small">${bal.currency_code}</span>
                                        </div>
                                        <div class="d-flex align-items-baseline gap-2">
                                            <h5 class="fw-bold mb-0 ${currentBal >= 0 ? 'text-success' : 'text-danger'}">
                                                ${Math.abs(currentBal).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </h5>
                                            <span class="extra-small fw-bold ${currentBal >= 0 ? 'text-success' : 'text-danger'}">(${debitCreditText})</span>
                                        </div>
                                        <div class="extra-small text-muted mt-1">${bal.currency_name}</div>
                                        ${parseInt(bal.branch_count || 0, 10) > 1 ? `<div class="extra-small text-info mt-1">مجمعة من ${bal.branch_count} فروع</div>` : ''}
                                    </div>
                                </div>
                            </div>
                        `);
                    });

                    // عرض صافي الرصيد الموحد بالعملة الأساسية
                    // استخدام معلومات العملة الافتراضية من الصفحة إذا لم تكن في البيانات
                    const baseSymbol = systemBaseCurrency ? systemBaseCurrency.currency_symbol : (defaultCurrencyInfo ? defaultCurrencyInfo.currency_symbol : '');
                    const baseCode = systemBaseCurrency ? systemBaseCurrency.currency_code : 'العملة الأساسية';

                    let netBaseStatusText = '';
                    let netBaseStatusClass = '';

                    if (Math.abs(totalNetBalanceBase) < 0.01) {
                        netBaseStatusText = '(متعادل)';
                        netBaseStatusClass = 'text-primary';
                    } else if (data[0].normal_balance === 'debit') { // طبيعة الحساب مدين
                        netBaseStatusText = totalNetBalanceBase > 0 ? '(له)' : '(عليه)';
                        netBaseStatusClass = totalNetBalanceBase > 0 ? 'text-success' : 'text-danger';
                    } else { // طبيعة الحساب دائن
                        netBaseStatusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                        netBaseStatusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
                    }
                    
                    // إضافة بطاقة إجمالي الصافي بالعملة الافتراضية
                    let netDebitCreditText = totalNetBalanceBase > 0 ? 'دائن' : (totalNetBalanceBase < 0 ? 'مدين' : '-');
                    let cardStatusClass = netBaseStatusClass.replace('text-', 'bg-');

                    $summaryCards.prepend(`
                        <div class="col-md-12">
                            <div class="card border-0 shadow-sm rounded-3 overflow-hidden border-top border-4 ${totalNetBalanceBase >= 0 ? 'border-success' : 'border-danger'}" style="background: linear-gradient(to left, #f8f9fa, #ffffff);">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="badge ${cardStatusClass} rounded-pill px-3">صافي الرصيد الموحد: ${netBaseStatusText}</span>
                                        <span class="fw-bold text-primary small">عملة النظام: ${baseCode}</span>
                                    </div>
                                    <div class="d-flex align-items-baseline gap-2">
                                        <h4 class="fw-bold mb-0 ${totalNetBalanceBase >= 0 ? 'text-success' : 'text-danger'}">
                                            ${Math.abs(totalNetBalanceBase).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </h4>
                                        <span class="fw-bold ${totalNetBalanceBase >= 0 ? 'text-success' : 'text-danger'}">
                                            ${baseSymbol} (${netDebitCreditText})
                                        </span>
                                    </div>
                                    <div class="extra-small text-muted mt-1">إجمالي أرصدة جميع العملات مقومة بسعر صرف عملة النظام (باستخدام الحقل الجديد)</div>
                                </div>
                            </div>
                        </div>
                    `);

                    // تحديث عرض الأرصدة في الجدول
                    data.forEach(bal => {
                        const openingBal = parseFloat(bal.opening_balance) || 0;
                        const currentBal = parseFloat(bal.current_balance) || 0;
                        const creditLimit = parseFloat(bal.credit_limit) || 0;
                        const debitLimit = parseFloat(bal.debit_limit) || 0;
                        const isFrozen = bal.is_frozen == 1;

                        // استخدام الرصيد بالعملة الأساسية من الحقل الجديد في قاعدة البيانات
                        let rowConvertedBalance = parseFloat(bal.current_balance_base) || 0;
                        
                        // تحديد حالة "له/عليه" لهذا السطر
                        let rowStatusText = '';
                        if (Math.abs(rowConvertedBalance) > 0.01) {
                            if (data[0].normal_balance === 'debit') {
                                rowStatusText = rowConvertedBalance > 0 ? '<span class="text-success extra-small fw-bold ms-1">(له)</span>' : '<span class="text-danger extra-small fw-bold ms-1">(عليه)</span>';
                            } else {
                                rowStatusText = rowConvertedBalance > 0 ? '<span class="text-danger extra-small fw-bold ms-1">(عليه)</span>' : '<span class="text-success extra-small fw-bold ms-1">(له)</span>';
                            }
                        }

                        let balanceStatusClass = 'text-success';
                        let balanceStatusText = '<i class="fas fa-check-circle me-1"></i> طبيعي';
                        if (isFrozen) {
                            balanceStatusClass = 'text-warning';
                            balanceStatusText = '<i class="fas fa-snowflake me-1"></i> مجمد';
                        } else if (creditLimit != 0 && currentBal < creditLimit) {
                            balanceStatusClass = 'text-danger';
                            balanceStatusText = '<i class="fas fa-exclamation-triangle me-1"></i> أقل من الائتمان';
                        } else if (debitLimit != 0 && currentBal > debitLimit) {
                            balanceStatusClass = 'text-danger';
                            balanceStatusText = '<i class="fas fa-exclamation-triangle me-1"></i> أعلى من الدائن';
                        }

                        const baseSymbol = systemBaseCurrency ? systemBaseCurrency.currency_symbol : '';

                        $tableBody.append(`
                            <tr>
                                <td class="px-4">
                                    <div class="fw-bold">${bal.currency_name || ''}</div>
                                    <div class="extra-small text-muted">${bal.currency_code || ''}</div>
                                    ${parseInt(bal.branch_count || 0, 10) > 1 ? `<div class="extra-small text-info">مجمعة من ${bal.branch_count} فروع</div>` : ''}
                                </td>
                                <td>
                                    <span class="fw-bold">${openingBal.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    <span class="text-muted extra-small">${bal.currency_symbol || ''}</span>
                                </td>
                                <td>
                                    <span class="fw-bold ${currentBal >= 0 ? 'text-success' : 'text-danger'}">${currentBal.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    <span class="text-muted extra-small">${bal.currency_symbol || ''}</span>
                                </td>
                                <td>
                                    <div class="fw-bold ${rowConvertedBalance >= 0 ? 'text-success' : 'text-danger'}">
                                        ${Math.abs(rowConvertedBalance).toLocaleString(undefined, { minimumFractionDigits: 2 })} 
                                        <small class="text-muted">${baseSymbol}</small>
                                        ${rowStatusText}
                                    </div>
                                    <div class="extra-small text-muted">مقومة بسعر الصرف الحالي</div>
                                </td>
                                <td>
                                    <div class="extra-small">
                                        <span class="text-muted">حد ائتماني (عليه):</span> 
                                        <span class="fw-bold text-danger">
                                            ${(parseFloat(allAccountsData.find(a => a.id == accountId).credit_limit_base) || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </span>
                                        <small class="text-muted">${baseSymbol}</small>
                                    </div>
                                    <div class="extra-small">
                                        <span class="text-muted">حد دائن (له):</span> 
                                        <span class="fw-bold text-success">
                                            ${(parseFloat(allAccountsData.find(a => a.id == accountId).debit_limit_base) || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </span>
                                        <small class="text-muted">${baseSymbol}</small>
                                    </div>
                                    <div class="extra-small text-muted">(حدود موحدة على الإجمالي)</div>
                                </td>
                                <td class="text-center">
                                    <span class="badge ${balanceStatusClass} rounded-pill">${balanceStatusText}</span>
                                </td>
                                <td class="text-center">
                                    ${bal.id ? `
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="account_id" value="${accountId}">
                                        <input type="hidden" name="currency_id" value="${bal.currency_id}">
                                        <button type="submit" name="delete_currency" class="btn btn-sm btn-danger rounded-pill" 
                                            onclick="return confirm('هل أنت متأكد من حذف هذه العملة من الحساب؟ سيتم حذف جميع صفوفها المرتبطة بهذا الحساب بعد التأكد أن الرصيد صفر.')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                    ` : ''}
                                </td>
                            </tr>
                        `);
                    });

                    // إعداد Select2 لقوائم العملات
                } else {
                    $('#account_balance_summary').addClass('d-none');
                    $('#filtered_balance_table').addClass('d-none');
                    $('#no_account_selected').removeClass('d-none');
                }
            });

            // جلب العمليات الأخيرة للحساب
            fetchRecentTransactions(accountId);
        }

        function fetchRecentTransactions(accountId) {
            const $tableContainer = $('#recent_transactions_table');
            const $tableBody = $('#recent_transactions_body');
            const $noTransactions = $('#no_transactions_msg');
            
            $.get('ajax/get_account_transactions.php', { account_id: accountId, limit: 15 }, function(res) {
                if (res.success && res.transactions.length > 0) {
                    $tableContainer.removeClass('d-none');
                    $noTransactions.addClass('d-none');
                    $tableBody.empty();
                    
                    res.transactions.forEach(tr => {
                        const debit = parseFloat(tr.debit) || 0;
                        const credit = parseFloat(tr.credit) || 0;
                        
                        $tableBody.append(`
                            <tr>
                                <td class="px-3">
                                    <div class="fw-bold extra-small text-primary">${tr.transaction_number}</div>
                                    <div class="extra-small text-muted">${tr.transaction_date}</div>
                                </td>
                                <td>
                                    <div class="extra-small fw-bold text-truncate" style="max-width: 200px;" title="${tr.main_description}">${tr.main_description}</div>
                                    <div class="extra-small text-muted text-truncate" style="max-width: 200px;">${tr.line_description || ''}</div>
                                </td>
                                <td class="fw-bold ${debit > 0 ? 'text-danger' : 'text-muted'}">${debit > 0 ? debit.toLocaleString() : '-'}</td>
                                <td class="fw-bold ${credit > 0 ? 'text-success' : 'text-muted'}">${credit > 0 ? credit.toLocaleString() : '-'}</td>
                                <td><span class="badge bg-light text-dark border extra-small">${tr.currency_code}</span></td>
                            </tr>
                        `);
                    });
                } else {
                    $tableContainer.addClass('d-none');
                    $noTransactions.removeClass('d-none');
                }
            });
        }

        // عند اختيار عملة في تبويب الحدود لتعبئة القيم الحالية
        $('#active_currencies_select').on('change', function() {
            const selected = $(this).find('option:selected');
            if ($(this).val()) {
                const balance = parseFloat(selected.data('balance')) || 0;
                const isFrozen = selected.data('frozen') == 1;

                $('#field_is_frozen').prop('checked', isFrozen);

                // إذا كان الرصيد غير صفر، لا نسمح بتجميد العملة (ولكن نسمح بإلغاء التجميد)
                if (balance !== 0 && !isFrozen) {
                    $('#field_is_frozen').prop('disabled', true);
                    $('#frozen_notice').text('لا يمكن تجميد العملة لأن الرصيد الحالي ليس صفراً (' + balance.toFixed(2) + ')').show();
                } else {
                    $('#field_is_frozen').prop('disabled', false);
                    $('#frozen_notice').hide();
                }
            } else {
                $('#field_credit_limit').val('0.00');
                $('#field_debit_limit').val('0.00');
                $('#field_is_frozen').prop('checked', false).prop('disabled', false);
                $('#frozen_notice').hide();
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>
