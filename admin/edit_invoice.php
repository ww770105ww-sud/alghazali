<?php
ob_start();
require_once 'header.php';

// التحقق من الصلاحيات
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return_to = !empty($_GET['return_to']) ? $_GET['return_to'] : 'invoices.php';

if (isset($_GET['success'])) {
    $success_msg = "تم تحديث البيانات بنجاح.";
}

// 1. جلب بيانات الفاتورة الأساسية
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$main_inv = $stmt->fetch();

if (!$main_inv) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger'>الفاتورة غير موجودة</div><a href='invoices.php' class='btn btn-primary'>العودة للقائمة</a></div>";
    require_once 'footer.php';
    exit;
}

// 2. تحديد الفواتير (البيع والشراء) المرتبطة
$suffix = preg_replace('/^[A-Za-z-]+/', '', $main_inv['invoice_number']);

// Try both prefixes for sales invoice (SAL- or SI-)
$stmt_sale = $pdo->prepare("SELECT * FROM invoices WHERE (invoice_number = ? OR invoice_number = ?) AND invoice_category = 'sales' LIMIT 1");
$stmt_sale->execute(["SAL-" . $suffix, "SI-" . $suffix]);
$sale_inv = $stmt_sale->fetch();

// Try both prefixes for purchase invoice (PUR- or PI-)
$stmt_pur = $pdo->prepare("SELECT * FROM invoices WHERE (invoice_number = ? OR invoice_number = ?) AND invoice_category = 'purchase' LIMIT 1");
$stmt_pur->execute(["PUR-" . $suffix, "PI-" . $suffix]);
$pur_inv = $stmt_pur->fetch();

// Determine which purchase prefix to use (use existing if available, else PI-)
if ($pur_inv) {
    $pur_num = $pur_inv['invoice_number'];
} else {
    $pur_num = "PI-" . $suffix;
}

// التحقق من الحالات
$is_sale_posted = false;
if ($sale_inv) {
    $is_sale_posted = ($sale_inv['invoice_status'] == 'posted');
} elseif ($main_inv['invoice_category'] == 'sales') {
    $is_sale_posted = ($main_inv['invoice_status'] == 'posted');
}
$is_pur_posted = ($pur_inv && $pur_inv['invoice_status'] == 'posted');

$has_posted = ($is_sale_posted || $is_pur_posted);

// تحديد فاتورة البيع الصحيحة مبكرًا لتستخدم في عرض الحقول
$sales_invoice = null;
if ($sale_inv) {
    $sales_invoice = $sale_inv;
} elseif ($main_inv['invoice_category'] == 'sales') {
    $sales_invoice = $main_inv;
}

$success_msg = "";
$error_msg = "";

// معالجة تحديث الفاتورة
if (isset($_POST['update_invoice'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        try {
        $pdo->beginTransaction();

        // البيانات المشتركة
        $invoice_date = normalize_datetime_db($_POST['invoice_date'] ?? $main_inv['invoice_date']);
        $branch_id = $_POST['branch_id'] ?? $main_inv['branch_id'];
        $source_type = $_POST['source_type'] ?? $main_inv['source_type'];
        $description = $_POST['description'] ?? $main_inv['description'];
        $currency_id = $_POST['currency_id'] ?? $main_inv['currency_id']; // عملة المورد/الشراء
        $sale_currency_id = $_POST['sale_currency_id'] ?? $currency_id; // عملة البيع
        $exchange_rate = (float)($_POST['exchange_rate'] ?? 1);

        // تحديث فاتورة البيع إذا لم تكن مرحلة
        // تحديد فاتورة البيع الصحيحة للتحديث
        $invoice_to_update = null;
        if ($sale_inv && !$is_sale_posted) {
            $invoice_to_update = $sale_inv;
        } elseif ($main_inv['invoice_category'] == 'sales' && !$is_sale_posted) {
            $invoice_to_update = $main_inv;
            $is_sale_posted = ($invoice_to_update['invoice_status'] == 'posted');
        }
        
        if ($invoice_to_update) {
            $total_amount = (float)($_POST['total_amount'] ?? $invoice_to_update['total_amount']);
            $cost_amount = (float)($_POST['cost_amount'] ?? $invoice_to_update['cost_amount']);
            
            // تحويل التكلفة لعملة البيع إذا اختلفتا
            $cost_in_sale_currency = $cost_amount;
            if ($sale_currency_id != $currency_id && $exchange_rate > 0) {
                $cost_in_sale_currency = $cost_amount * $exchange_rate;
            }

            $delivery_type = $_POST['delivery_type'] ?? $invoice_to_update['delivery_type'];
            $account_id = $_POST['account_id'] ?? $invoice_to_update['account_id'];
            $amount_received = (float)($_POST['amount_received'] ?? $invoice_to_update['amount_received']);
            $supplier_id = $_POST['supplier_id'] ?? $invoice_to_update['supplier_id'];

            // جلب البيانات القديمة
            $stmt_old_sale = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_old_sale->execute([$invoice_to_update['id']]);
            $old_sale = $stmt_old_sale->fetch(PDO::FETCH_ASSOC);

            $stmt_up_sale = $pdo->prepare("UPDATE invoices SET 
                invoice_date = ?, branch_id = ?, source_type = ?, description = ?,
                total_amount = ?, cost_amount = ?, currency_id = ?, 
                delivery_type = ?, account_id = ?, amount_received = ?, supplier_id = ?,
                updated_at = CURRENT_TIMESTAMP, updated_by = ?
                WHERE id = ?");
            $stmt_up_sale->execute([
                $invoice_date,
                $branch_id,
                $source_type,
                $description,
                $total_amount,
                $cost_in_sale_currency,
                $sale_currency_id,
                $delivery_type,
                $account_id,
                $amount_received,
                $supplier_id,
                $_SESSION['admin_id'],
                $invoice_to_update['id']
            ]);

            // جلب البيانات الجديدة
            $stmt_new_sale = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_new_sale->execute([$invoice_to_update['id']]);
            $new_sale = $stmt_new_sale->fetch(PDO::FETCH_ASSOC);

            // التحقق من وجود تغييرات حقيقية قبل التسجيل
            $has_changes_sale = false;
            foreach ($new_sale as $key => $val) {
                if (in_array($key, ['updated_at', 'updated_by'])) continue;
                if (normalizeAuditCompareValue($val) !== normalizeAuditCompareValue($old_sale[$key] ?? null)) {
                    $has_changes_sale = true;
                    break;
                }
            }

            if ($has_changes_sale) {
                log_audit($pdo, 'update', 'invoices', $invoice_to_update['id'], $old_sale, $new_sale, "تعديل فاتورة بيع");
            }
        }

        // تحديث فاتورة الشراء إذا لم تكن مرحلة
        if ($pur_inv && !$is_pur_posted) {
            // جلب البيانات القديمة
            $stmt_old_pur = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_old_pur->execute([$pur_inv['id']]);
            $old_pur = $stmt_old_pur->fetch(PDO::FETCH_ASSOC);

            $cost_amount = (float)($_POST['cost_amount'] ?? $pur_inv['total_amount']);
            $supplier_id = $_POST['supplier_id'] ?? $pur_inv['supplier_id'];
            $invoice_currency_id = $_POST['currency_id'] ?? $pur_inv['currency_id'];

            // جلب account_id المورد
            $stmt_sup_acc = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt_sup_acc->execute([$supplier_id]);
            $supplier_account_id = $stmt_sup_acc->fetchColumn();

            $stmt_up_pur = $pdo->prepare("UPDATE invoices SET 
                invoice_date = ?, branch_id = ?, source_type = ?, supplier_id = ?,
                currency_id = ?, total_amount = ?, account_id = ?, description = ?,
                updated_at = CURRENT_TIMESTAMP, updated_by = ?
                WHERE id = ?");
            $stmt_up_pur->execute([
                $invoice_date,
                $branch_id,
                $source_type,
                $supplier_id,
                $invoice_currency_id,
                $cost_amount,
                $supplier_account_id,
                $description,
                $_SESSION['admin_id'],
                $pur_inv['id']
            ]);

            // جلب البيانات الجديدة
            $stmt_new_pur = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_new_pur->execute([$pur_inv['id']]);
            $new_pur = $stmt_new_pur->fetch(PDO::FETCH_ASSOC);

            // التحقق من وجود تغييرات حقيقية قبل التسجيل
            $has_changes_pur = false;
            foreach ($new_pur as $key => $val) {
                if (in_array($key, ['updated_at', 'updated_by'])) continue;
                if (normalizeAuditCompareValue($val) !== normalizeAuditCompareValue($old_pur[$key] ?? null)) {
                    $has_changes_pur = true;
                    break;
                }
            }

            if ($has_changes_pur) {
                log_audit($pdo, 'update', 'invoices', $pur_inv['id'], $old_pur, $new_pur, "تعديل فاتورة شراء");
            }
        } else if (!$pur_inv && isset($_POST['record_purchase']) && $_POST['record_purchase'] == '1') {
            // إنشاء فاتورة شراء جديدة إذا لم تكن موجودة وتم تفعيل الخيار
            $supplier_id = $_POST['supplier_id'];
            $cost_amount = (float)$_POST['cost_amount'];

            $stmt_sup_acc = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt_sup_acc->execute([$supplier_id]);
            $supplier_account_id = $stmt_sup_acc->fetchColumn();

            $stmt_ins_pur = $pdo->prepare("INSERT INTO invoices (
                invoice_number, invoice_date, branch_id, invoice_category,
                source_type, supplier_id, currency_id, total_amount, discount, 
                cost_amount, payment_type, delivery_type, account_id, 
                amount_received, description, invoice_status, created_by
            ) VALUES (?, ?, ?, 'purchase', ?, ?, ?, ?, 0, 0, 'credit', 'credit', ?, 0, ?, 'draft', ?)");

            $stmt_ins_pur->execute([
                $pur_num,
                $invoice_date,
                $branch_id,
                $source_type,
                $supplier_id,
                $currency_id,
                $cost_amount,
                $supplier_account_id,
                $description,
                $_SESSION['admin_id']
            ]);
            $new_pur_id = $pdo->lastInsertId();

            // جلب البيانات الجديدة للسجل
            $stmt_new_pur = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_new_pur->execute([$new_pur_id]);
            $new_pur_data = $stmt_new_pur->fetch(PDO::FETCH_ASSOC);
            log_audit($pdo, 'create', 'invoices', $new_pur_id, null, $new_pur_data, "إنشاء فاتورة شراء جديدة");
        }

        $pdo->commit();
        $success_msg = "تم تحديث البيانات بنجاح.";
        
        // إعادة التحميل لضمان ظهور البيانات الجديدة
        $return_param = !empty($_GET['return_to']) ? '&return_to=' . urlencode($_GET['return_to']) : '';
        header("Location: edit_invoice.php?id=$invoice_id&success=1$return_param");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "خطأ في التحديث: " . $e->getMessage();
    }
}
}

// جلب البيانات للقوائم
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies ORDER BY is_default DESC, currency_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE deleted_at IS NULL ORDER BY supplier_name ASC")->fetchAll();
$services = [
    ['service_name' => 'تذاكر طيران وبصات'],
    ['service_name' => 'جوازت السفر'],
    ['service_name' => 'حج وعمرة'],
    ['service_name' => 'الزيارة العائلية'],
    ['service_name' => 'فيز العمل']
];

// جلب الكيانات مع حساباتها المحاسبية
$customers_entities = $pdo->query("SELECT id as account_id, account_name_ar as name, account_code FROM unified_accounts WHERE account_code LIKE '1121%' AND account_code != '1121' AND account_status = 'active' ORDER BY account_name_ar ASC")->fetchAll();
$agents_entities = $pdo->query("SELECT id as account_id, account_name_ar as name, account_code FROM unified_accounts WHERE account_code LIKE '1122%' AND account_code != '1122' AND account_status = 'active' ORDER BY account_name_ar ASC")->fetchAll();
$cashboxes_entities = $pdo->query("SELECT id as account_id, account_name_ar as name, account_code FROM unified_accounts WHERE account_code LIKE '101%' AND account_code != '101' AND account_status = 'active' ORDER BY account_name_ar ASC")->fetchAll();
$banks_entities = $pdo->query("SELECT id as account_id, account_name_ar as name, account_code FROM unified_accounts WHERE account_code LIKE '102%' AND account_code != '102' AND account_status = 'active' ORDER BY account_name_ar ASC")->fetchAll();

// جلب الحساب المرتبط بفاتورة الشراء للمورد (إذا وجدت)
$pur_account_id = $pur_inv['account_id'] ?? null;
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-edit me-2 text-primary"></i> تعديل فاتورة #<?php echo $main_inv['invoice_number']; ?></h3>
        <a href="<?php echo htmlspecialchars($return_to); ?>" class="btn btn-light rounded-pill px-4 border shadow-sm">العودة للقائمة</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-check-circle me-2"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <form method="POST">
            <?php echo csrf_input(); ?>
            <div class="card-body p-4">
                <div class="row g-4">
                    <!-- البيانات الأساسية -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold">تاريخ الفاتورة</label>
                        <input type="datetime-local" name="invoice_date" class="form-control rounded-3" value="<?php echo h(format_datetime_local_value($main_inv['invoice_date'])); ?>" required <?php echo $has_posted ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">الفرع المسؤول</label>
                        <select name="branch_id" class="form-select rounded-3" required <?php echo $has_posted ? 'disabled' : ''; ?>>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo ($main_inv['branch_id'] == $b['id']) ? 'selected' : ''; ?>><?php echo $b['branch_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">نوع الخدمة</label>
                        <select name="source_type" class="form-select rounded-3" <?php echo $has_posted ? 'disabled' : ''; ?>>
                            <option value="general" <?php echo ($main_inv['source_type'] == 'general') ? 'selected' : ''; ?>>عام (General)</option>
                            <?php foreach ($services as $s): ?>
                                <option value="<?php echo $s['service_name']; ?>" <?php echo ($main_inv['source_type'] == $s['service_name']) ? 'selected' : ''; ?>><?php echo $s['service_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">العملة (التكلفة/المورد)</label>
                        <select name="currency_id" id="main_currency_id" class="form-select rounded-3" required <?php echo $has_posted ? 'disabled' : ''; ?>>
                            <?php foreach ($currencies as $curr): ?>
                                <option value="<?php echo $curr['id']; ?>" 
                                        data-symbol="<?php echo $curr['currency_symbol']; ?>"
                                        data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>"
                                        data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>"
                                        <?php echo ($main_inv['currency_id'] == $curr['id']) ? 'selected' : ''; ?>>
                                    <?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_symbol']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check form-switch mt-2" id="allow_diff_currency_container">
                            <input class="form-check-input" type="checkbox" id="allow_diff_currency" value="1" <?php echo ($sale_inv['currency_id'] != $main_inv['currency_id']) ? 'checked' : ''; ?> <?php echo $has_posted ? 'disabled' : ''; ?>>
                            <label class="form-check-label small text-muted" for="allow_diff_currency">السماح باختلاف عملة البيع عن الشراء</label>
                        </div>
                    </div>

                    <div class="col-md-6" id="sale_currency_field" style="<?php echo ($sale_inv['currency_id'] == $main_inv['currency_id']) ? 'display:none;' : ''; ?>">
                        <label class="form-label fw-bold">عملة البيع (للعميل)</label>
                        <select name="sale_currency_id" id="sale_currency_id" class="form-select rounded-3" <?php echo $is_sale_posted ? 'disabled' : ''; ?>>
                            <?php foreach ($currencies as $curr): ?>
                                <option value="<?php echo $curr['id']; ?>" 
                                        data-symbol="<?php echo $curr['currency_symbol']; ?>"
                                        data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>"
                                        data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>"
                                        <?php echo (($sale_inv['currency_id'] ?? $main_inv['currency_id']) == $curr['id']) ? 'selected' : ''; ?>>
                                    <?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_symbol']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- قسم سعر الصرف -->
                    <div id="exchange_rate_container" class="col-12" style="<?php echo ($sale_inv['currency_id'] == $main_inv['currency_id']) ? 'display:none;' : ''; ?>">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">سعر الصرف (1 <span class="pur-symbol"></span> = ? <span class="sale-symbol"></span>)</label>
                                    <?php 
                                        $current_rate = 1.0;
                                        if ($sale_inv['currency_id'] != $main_inv['currency_id'] && ($pur_inv['total_amount'] ?? $sale_inv['cost_amount']) > 0) {
                                            $current_rate = $sale_inv['cost_amount'] / ($pur_inv['total_amount'] ?? $sale_inv['cost_amount']);
                                        }
                                    ?>
                                    <input type="number" step="0.000001" name="exchange_rate" id="invoice_exchange_rate" class="form-control apple-input" value="<?php echo number_format($current_rate, 6, '.', ''); ?>" <?php echo $has_posted ? 'disabled' : ''; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">التكلفة بمعادلة البيع</label>
                                    <input type="text" id="equivalent_cost_display" class="form-control apple-input bg-white" readonly placeholder="0.00">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- البيانات المالية -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold">إجمالي سعر البيع (للعميل)</label>
                        <input type="number" step="0.01" name="total_amount" id="total_amount" class="form-control rounded-3" value="<?php echo ($sales_invoice['total_amount'] ?? 0); ?>" required <?php echo $is_sale_posted ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">إجمالي سعر التكلفة (للمورد)</label>
                        <input type="number" step="0.01" name="cost_amount" id="cost_amount" class="form-control rounded-3" value="<?php echo $pur_inv ? $pur_inv['total_amount'] : ($sales_invoice['cost_amount'] ?? 0); ?>" <?php echo $is_pur_posted ? 'disabled' : ''; ?>>
                    </div>
                    <?php
                    $amount_received = 0;
                    if ($sales_invoice) {
                        $amount_received = (float)($sales_invoice['amount_received'] ?? 0);
                        
                        if ($sales_invoice['invoice_status'] == 'posted') {
                            // إذا كانت الفاتورة مرحلة، نحسب الواصل من توزيعات السداد المرتبطة بها
                            $stmt_received = $pdo->prepare("
                                SELECT SUM(allocated_amount) 
                                FROM payment_allocations pa
                                JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                                WHERE pa.invoice_id = ? AND ft.status = 'posted'
                            ");
                            $stmt_received->execute([$sales_invoice['id']]);
                            $total_alloc = $stmt_received->fetchColumn();
                            if ($total_alloc > 0) {
                                $amount_received = (float)$total_alloc;
                            }
                        }
                    }
                    ?>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">المبلغ الواصل من العميل</label>
                        <input type="number" step="0.01" name="amount_received" id="amount_received" class="form-control rounded-3 bg-light" value="<?php echo $amount_received; ?>" <?php echo $is_sale_posted ? 'disabled' : ''; ?>>
                    </div>

                    <!-- طريقة التسوية -->
                    <div class="col-md-6">
                        <label class="form-label fw-bold">نوع التحصيل (البيع)</label>
                        <select name="delivery_type" id="delivery_type" class="form-select rounded-3" required <?php echo $is_sale_posted ? 'disabled' : ''; ?>>
                            <option value="draft" <?php echo (($sales_invoice['delivery_type'] ?? '') == 'draft') ? 'selected' : ''; ?>>مسودة</option>
                            <option value="cash" <?php echo (($sales_invoice['delivery_type'] ?? '') == 'cash') ? 'selected' : ''; ?>>نقد</option>
                            <option value="credit" <?php echo (($sales_invoice['delivery_type'] ?? '') == 'credit') ? 'selected' : ''; ?>>آجل</option>
                            <option value="bank_transfer" <?php echo (($sales_invoice['delivery_type'] ?? '') == 'bank_transfer') ? 'selected' : ''; ?>>تحويل بنكي</option>
                            <option value="agent" <?php echo (($sales_invoice['delivery_type'] ?? '') == 'agent') ? 'selected' : ''; ?>>وكيل</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="account_field">
                        <label class="form-label fw-bold" id="account_label">الحساب المتأثر</label>
                        <select name="account_id" id="account_select" class="form-select rounded-3" <?php echo $is_sale_posted ? 'disabled' : ''; ?>>
                            <option value="">-- اختر الحساب --</option>
                            <!-- سيتم ملؤه بواسطة JS -->
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">مزود الخدمة (المورد)</label>
                        <select name="supplier_id" class="form-select rounded-3" <?php echo $is_pur_posted ? 'disabled' : ''; ?>>
                            <option value="">-- اختر مورد --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo (($pur_inv['supplier_id'] ?? $sales_invoice['supplier_id'] ?? 0) == $s['id']) ? 'selected' : ''; ?>><?php echo $s['supplier_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="record_purchase" id="record_purchase" value="1"
                                <?php echo ($pur_inv) ? 'checked' : ''; ?>
                                <?php echo ($is_pur_posted) ? 'onclick="return preventUncheckPosted(this)"' : ''; ?>
                                <?php echo ($is_pur_posted) ? 'disabled' : ''; ?>>
                            <label class="form-check-label fw-bold text-primary" for="record_purchase">
                                تحديث/تسجيل مديونية (آجل علينا) للمورد
                                <?php if ($is_pur_posted): ?>
                                    <span class="badge bg-success ms-2 small">مرحلة</span>
                                <?php endif; ?>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">البيان / الوصف</label>
                        <textarea name="description" class="form-control rounded-3" rows="3" <?php echo $has_posted ? 'disabled' : ''; ?>><?php echo $main_inv['description']; ?></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-light p-4 text-end">
                <button type="submit" name="update_invoice" class="btn btn-primary rounded-pill px-5 shadow-sm fw-bold">
                    <i class="fas fa-save me-2"></i> حفظ التغييرات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    $(document).ready(function() {
        const entitiesData = {
            cashboxes: <?php echo json_encode($cashboxes_entities); ?>,
            customers: <?php echo json_encode($customers_entities); ?>,
            banks: <?php echo json_encode($banks_entities); ?>,
            agents: <?php echo json_encode($agents_entities); ?>
        };

        const currentAccountId = "<?php echo $sales_invoice['account_id'] ?? ''; ?>";
        const currentPurAccountId = "<?php echo $pur_account_id ?? ''; ?>";

        $('#delivery_type').change(function() {
            handleDeliveryType($(this).val(), entitiesData, currentAccountId);
        });

        // تشغيل عند التحميل
        handleDeliveryType($('#delivery_type').val(), entitiesData, currentAccountId);
        
        // جلب حساب المورد عند تغيير المورد
        $('select[name="supplier_id"]').change(function() {
            let supplierId = $(this).val();
            if (supplierId) {
                // في حالة التعديل، الحساب موجود مسبقاً في $pur_account_id
            }
        });

        // مراقبة تغيير العملات
        $('#main_currency_id, #sale_currency_id, #record_purchase, #allow_diff_currency').on('change', function(e) {
            const isSelf = $(e.target).attr('id');
            const allowDiff = $('#allow_diff_currency').is(':checked');
            
            // إذا لم يتم تفعيل اختلاف العملات، نقوم بمزامنتها
            if (!allowDiff && (isSelf === 'main_currency_id' || isSelf === 'sale_currency_id')) {
                if (isSelf === 'main_currency_id') {
                    $('#sale_currency_id').val($('#main_currency_id').val());
                } else if (isSelf === 'sale_currency_id') {
                    $('#main_currency_id').val($('#sale_currency_id').val());
                }
            }

            updateCurrencyLogic();
        });

        $('#invoice_exchange_rate, #cost_amount').on('input', function() {
            calculateEquivalentCost();
        });

        function updateCurrencyLogic() {
            const purCurrencyId = $('#main_currency_id').val();
            const saleCurrencyId = $('#sale_currency_id').val();
            const allowDiff = $('#allow_diff_currency').is(':checked');
            
            if (allowDiff) {
                $('#sale_currency_field').show();
            } else {
                $('#sale_currency_field').hide();
            }

            // التعامل مع اختلاف العملات
            if (allowDiff && purCurrencyId != saleCurrencyId) {
                $('#exchange_rate_container').show();
                
                const purOpt = $('#main_currency_id option:selected');
                const saleOpt = $('#sale_currency_id option:selected');
                
                $('.pur-symbol').text(purOpt.data('symbol'));
                $('.sale-symbol').text(saleOpt.data('symbol'));

                // حساب سعر الصرف الافتراضي إذا كان 1 أو فارغ
                if ($('#invoice_exchange_rate').val() == "1.000000" || $('#invoice_exchange_rate').val() == "") {
                    const purBuy = parseFloat(purOpt.data('buy')) || 1;
                    const saleSell = parseFloat(saleOpt.data('sell')) || 1;
                    const defaultRate = purBuy / saleSell;
                    $('#invoice_exchange_rate').val(defaultRate.toFixed(6));
                }
            } else {
                $('#exchange_rate_container').hide();
            }
            
            calculateEquivalentCost();
        }

        function calculateEquivalentCost() {
            const cost = parseFloat($('#cost_amount').val()) || 0;
            const rate = parseFloat($('#invoice_exchange_rate').val()) || 1;
            const equivalent = cost * rate;
            
            const saleSymbol = $('#sale_currency_id option:selected').data('symbol');
            $('#equivalent_cost_display').val(equivalent.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + (saleSymbol || ''));
        }

        // تشغيل أولي
        updateCurrencyLogic();

        // تحقق من المبالغ في صفحة التعديل
        $('#amount_received, #cost_amount').on('input', function() {
            const total = parseFloat($('#total_amount').val()) || 0;
            const val = parseFloat($(this).val()) || 0;
            const label = $(this).attr('id') === 'amount_received' ? 'المبلغ الواصل' : 'إجمالي التكلفة';

            if (val > total && total > 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'تنبيه',
                    text: `لا يمكن أن يكون ${label} أكبر من إجمالي سعر البيع (${total})`,
                    confirmButtonText: 'حسناً'
                });
                $(this).val(total);
            }
        });
    });

    function preventUncheckPosted(el) {
        if (!el.checked) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'لا يمكن إلغاء فاتورة الشراء لأنها مرحلة بالفعل ومسجلة في الحسابات. يجب إلغاء ترحيل فاتورة الشراء أولاً من قائمة الفواتير.'
            });
            el.checked = true;
            return false;
        }
        return true;
    }

    function handleDeliveryType(type, entitiesData, selectedId) {
        $('#account_field').hide();
        $('#account_select').empty().append('<option value="">-- اختر الحساب --</option>');

        let list = [];
        let label = 'الحساب المتأثر';

        if (type === 'cash') {
            $('#account_field').show();
            label = 'الحساب: الصناديق';
            list = entitiesData.cashboxes;
        } else if (type === 'credit') {
            $('#account_field').show();
            label = 'الحساب: العملاء';
            list = entitiesData.customers;
        } else if (type === 'bank_transfer') {
            $('#account_field').show();
            label = 'الحساب: البنوك';
            list = entitiesData.banks;
        } else if (type === 'agent') {
            $('#account_field').show();
            label = 'الحساب: الوكلاء';
            list = entitiesData.agents;
        }

        $('#account_label').text(label);
        list.forEach(item => {
            let selected = (item.account_id == selectedId) ? 'selected' : '';
            $('#account_select').append(`<option value="${item.account_id}" ${selected}>${item.name} (${item.account_code})</option>`);
        });
    }
</script>

<?php require_once 'footer.php'; ?>
