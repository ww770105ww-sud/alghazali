<?php
/**
 * financial_fields.php — الحقول المالية الموحدة (مطابق لمنطق invoices.php)
 *
 * متغيرات اختيارية قبل التضمين:
 * @var array  $current_invoice
 * @var string $financial_fields_prefix          بادئة معرفات الحقول (مثلاً edit_)
 * @var string $financial_fields_api_url         مسار API (افتراضي: invoices.php)
 * @var string $financial_fields_select2_parent  محدد jQuery لـ dropdownParent (مثلاً #addUmrahModal)
 * @var string $financial_fields_form_selector   محدد النموذج للتحقق قبل الإرسال
 * @var bool   $financial_fields_manual_init   true لتعطيل التهيئة التلقائية
 * @var bool   $financial_fields_show_service_select  false لإخفاء قائمة الخدمة واستخدام source_type مخفي
 * @var bool   $financial_fields_show_host_guarantor  true لإظهار حقول المستضيف والضمان
 * @var array  $financial_fields_hosts           مصفوفة المستضيفين (id, host_name)
 * @var array  $financial_fields_guarantors      مصفوفة الضمانات (id, guarantor_name)
 * @var int    $financial_fields_selected_host   معرف المستضيف المحدد مسبقاً
 * @var int    $financial_fields_selected_guarantor معرف الضمان المحدد مسبقاً
 * @var bool   $financial_fields_show_supplier  true لإظهار حقل المورد (الإفتراضي: true)
 * @var bool   $financial_fields_show_cost_currency true لإظهار حقل عملة التكلفة (الإفتراضي: true)
 * @var bool   $financial_fields_show_cost_amount true لإظهار حقل سعر التكلفة (الإفتراضي: true)
 * @var bool   $financial_fields_show_discount true لإظهار حقل مبلغ الخصم (الإفتراضي: true)
 */

if (!isset($pdo)) {
    return;
}

if (!function_exists('ff_h')) {
    function ff_h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$ff_prefix = $financial_fields_prefix ?? '';
$ff_api_url = $financial_fields_api_url ?? 'invoices.php';
$ff_select2_parent = $financial_fields_select2_parent ?? null;
$ff_form_selector = $financial_fields_form_selector ?? null;
$ff_manual_init = $financial_fields_manual_init ?? false;
$ff_show_service_select = $financial_fields_show_service_select ?? null;
$ff_hide_service_accounts = $financial_fields_hide_service_accounts ?? false;
$ff_hide_current_service_label = $financial_fields_hide_current_service_label ?? false;
$ff_show_notes_field = $financial_fields_show_notes_field ?? false;
$ff_invoice_date_label = $financial_fields_invoice_date_label ?? 'تاريخ الفاتورة';
$ff_header_layout = $financial_fields_header_layout ?? 'inline';
$ff_header_col_class = ($ff_header_layout === 'stacked') ? 'col-12' : 'col-md-4';
$ff_header_fields_col_class = ($ff_header_layout === 'split_rows') ? 'col-md-4' : $ff_header_col_class;
$ff_title_layout = $financial_fields_title_layout ?? (($ff_header_layout === 'split_rows') ? 'block' : 'legend');
$ff_show_host_guarantor = $financial_fields_show_host_guarantor ?? false;
$ff_hosts = $financial_fields_hosts ?? [];
$ff_guarantors = $financial_fields_guarantors ?? [];
$ff_selected_host = $financial_fields_selected_host ?? null;
$ff_selected_guarantor = $financial_fields_selected_guarantor ?? null;
$ff_show_supplier = $financial_fields_show_supplier ?? true;
$ff_show_cost_currency = $financial_fields_show_cost_currency ?? true;
$ff_show_cost_amount = $financial_fields_show_cost_amount ?? true;
$ff_show_discount = $financial_fields_show_discount ?? true;

if (!isset($current_invoice) || !is_array($current_invoice)) {
    $current_invoice = [];
}

$ff_source_type = $current_invoice['source_type'] ?? 'general';
if ($ff_show_service_select === null) {
    $ff_show_service_select = ($ff_source_type === '' || $ff_source_type === 'general');
}

// --- تحميل البيانات (نفس invoices.php) ---
if (!isset($settings)) {
    $settings = getSettings($pdo);
}

$ff_default_delivery_type = $settings['default_delivery_type'] ?? 'draft';
$ff_initial_delivery_type = $current_invoice['delivery_type'] ?? '';
if ($ff_initial_delivery_type === '' || $ff_initial_delivery_type === null) {
    $ff_initial_delivery_type = $ff_default_delivery_type;
}

if (!isset($base_currency)) {
    $base_currency = $pdo->query("SELECT * FROM currencies WHERE is_default = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
}

if (!isset($currencies)) {
    $currencies = $pdo->query(
        "SELECT id, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell, is_default
         FROM currencies ORDER BY is_default DESC, currency_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($branches)) {
    $branches = $pdo->query(
        "SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($services)) {
    $services = $pdo->query(
        "SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (!function_exists('ff_normalize_suppliers_list')) {
    /**
     * توحيد مفاتيح الموردين (invoices.php يستخدم id، بعض الصفحات تمرّر account_id)
     */
    function ff_normalize_suppliers_list(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (empty($row['id']) && !empty($row['account_id'])) {
                $row['id'] = $row['account_id'];
            }
            if (empty($row['display_name'])) {
                $name = $row['account_name_ar'] ?? $row['supplier_name'] ?? '';
                $row['display_name'] = $name;
            }
            $normalized[] = $row;
        }
        return $normalized;
    }
}

if (!isset($suppliers_with_codes)) {
    $parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
    $parent_stmt_suppliers->execute();
    $suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

    $suppliers_with_codes = [];
    if ($suppliers_parent_id) {
        $suppliers_stmt = $pdo->prepare("
            SELECT coa.*,
                   (SELECT id FROM suppliers WHERE account_id = coa.id LIMIT 1) as supplier_id
            FROM unified_accounts coa
            WHERE coa.parent_id = ? AND coa.account_status = 'active'
            ORDER BY coa.account_code ASC
        ");
        $suppliers_stmt->execute([$suppliers_parent_id]);
        while ($row = $suppliers_stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['display_name'] = $row['account_name_ar'] ?? '';
            $suppliers_with_codes[] = $row;
        }
    }
}
$suppliers_with_codes = ff_normalize_suppliers_list($suppliers_with_codes ?? []);

if (!function_exists('get_accounts_under_parent')) {
    function get_accounts_under_parent($pdo, $parent_account_code, $entity_type = null)
    {
        $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $stmt_parent->execute([$parent_account_code]);
        $parent_id = $stmt_parent->fetchColumn();
        if (!$parent_id) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT ua.id, ua.account_code, ua.account_name_ar,
                   (SELECT id FROM customers WHERE account_id = ua.id LIMIT 1) as customer_id,
                   (SELECT id FROM agents WHERE account_id = ua.id LIMIT 1) as agent_id,
                   (SELECT id FROM suppliers WHERE account_id = ua.id LIMIT 1) as supplier_id
            FROM unified_accounts ua
            WHERE ua.parent_id = ? AND ua.account_status = 'active'
            ORDER BY ua.account_code ASC
        ");
        $stmt->execute([$parent_id]);
        $accounts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $display_name = $row['account_name_ar'] ?? '';
            if ($entity_type === 'customer' && empty($row['customer_id'])) {
                $display_name .= ' (legacy غير مربوط)';
            } elseif ($entity_type === 'agent' && empty($row['agent_id'])) {
                $display_name .= ' (legacy غير مربوط)';
            }
            $row['display_name'] = $display_name;
            $row['name'] = $row['account_name_ar'] ?? '';
            $accounts[] = $row;
        }
        return $accounts;
    }
}

if (!function_exists('ff_build_service_config')) {
    function ff_build_service_config(PDO $pdo, array $settings, string $sourceType): array
    {
        $cfg = getServiceInvoiceConfig($sourceType, $settings);

        $serviceStmt = $pdo->prepare("
            SELECT revenue_account_id, cost_account_id, profit_account_id
            FROM services
            WHERE service_name = ?
            LIMIT 1
        ");
        $serviceStmt->execute([$sourceType]);
        $serviceCfg = $serviceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach (['revenue_account_id', 'cost_account_id', 'profit_account_id'] as $accountKey) {
            if (!empty($serviceCfg[$accountKey])) {
                $cfg[$accountKey] = (int)$serviceCfg[$accountKey];
            }
        }

        $resolveAccountName = static function ($accountId) use ($pdo): string {
            if (empty($accountId)) {
                return '';
            }

            $stmt = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);

            return $acc ? ($acc['account_name_ar'] ?? '') : '';
        };

        return [
            'revenue_account_id' => $cfg['revenue_account_id'] ?? null,
            'revenue_account_name' => $resolveAccountName($cfg['revenue_account_id'] ?? null),
            'cost_account_id' => $cfg['cost_account_id'] ?? null,
            'cost_account_name' => $resolveAccountName($cfg['cost_account_id'] ?? null),
            'profit_account_id' => $cfg['profit_account_id'] ?? null,
            'profit_account_name' => $resolveAccountName($cfg['profit_account_id'] ?? null),
        ];
    }
}

if (!isset($cashboxes_entities)) {
    $cashboxes_entities = get_accounts_under_parent($pdo, '11101');
}
if (!isset($banks_entities)) {
    $banks_entities = get_accounts_under_parent($pdo, '11102');
}
if (!isset($customers_entities)) {
    $customers_entities = get_accounts_under_parent($pdo, '11201', 'customer');
}
if (!isset($agents_entities)) {
    $agents_entities = get_accounts_under_parent($pdo, '11203', 'agent');
}

if (!isset($service_configs)) {
    $service_configs = [];
    foreach ($services as $s) {
        $service_configs[$s['service_name']] = ff_build_service_config($pdo, $settings, (string)$s['service_name']);
    }
}

if (!isset($service_configs[$ff_source_type]) && $ff_source_type !== '') {
    $service_configs[$ff_source_type] = ff_build_service_config($pdo, $settings, (string)$ff_source_type);
}

$default_currency_id = 1;
foreach ($currencies as $curr) {
    if (!empty($curr['is_default'])) {
        $default_currency_id = (int)$curr['id'];
        break;
    }
}

$p = $ff_prefix;
$cid = static function ($name) use ($p) {
    return $p . $name;
};

$val = static function ($key, $default = '') use ($current_invoice) {
    return $current_invoice[$key] ?? $default;
};
?>

<fieldset class="border p-3 mb-4 financial-fields-block" data-ff-prefix="<?php echo ff_h($p); ?>">
    <!-- <?php if ($ff_title_layout === 'block'): ?>
        <div class="financial-fields-title fw-bold fs-4 text-white mb-3 d-flex align-items-center gap-2">
            <span>💰</span>
            <span>البيانات المالية (الفاتورة)</span>
        </div>
    <?php else: ?>
        <legend class="w-auto px-2">💰 البيانات المالية (الفاتورة)</legend>
    <?php endif; ?> -->
    <div id="<?php echo ff_h($cid('ff_warning')); ?>" class="alert alert-warning py-2 px-3 small d-none" role="alert"></div>

    <!-- Hidden fields for form functionality -->
    <input type="hidden" name="invoice_date" id="<?php echo ff_h($cid('invoice_date')); ?>"
           value="<?php echo ff_h(format_datetime_local_value($val('invoice_date', normalize_datetime_db(null)))); ?>">
    <input type="hidden" name="branch_id" id="<?php echo ff_h($cid('branch_id')); ?>"
           value="<?php echo ff_h($val('branch_id', (count($branches) === 1) ? $branches[0]['id'] : '')); ?>">
    <input type="hidden" name="source_type" id="<?php echo ff_h($cid('source_type_hidden')); ?>" value="<?php echo ff_h($ff_source_type); ?>">

    <?php if ($ff_show_host_guarantor): ?>
        <!-- القسم 2: المستضيف والضمان والمورد -->
        <div class="form-section-card mb-4">
            <div class="form-section-header">
                <i class="fas fa-shield-alt text-warning"></i>
                <h6>2. المستضيف والضمان والمورد</h6>
            </div>
            <div class="form-section-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">المستضيف</label>
                        <div class="input-group">
                            <select name="host_id" id="<?php echo ff_h($cid('host_id')); ?>" class="form-select" required>
                                <option value="">-- اختر مستضيف --</option>
                                <?php foreach ($ff_hosts as $sh): ?>
                                    <option value="<?php echo ff_h($sh['id']); ?>" <?php echo ((string)$ff_selected_host === (string)$sh['id']) ? 'selected' : ''; ?>>
                                        <?php echo ff_h($sh['host_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#quickAddHostModal"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الضمان</label>
                        <div class="input-group">
                            <select name="guarantor_id" id="<?php echo ff_h($cid('guarantor_id')); ?>" class="form-select" required>
                                <option value="">-- اختر ضامن --</option>
                                <?php foreach ($ff_guarantors as $sg): ?>
                                    <option value="<?php echo ff_h($sg['id']); ?>" <?php echo ((string)$ff_selected_guarantor === (string)$sg['id']) ? 'selected' : ''; ?>>
                                        <?php echo ff_h($sg['guarantor_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#quickAddGuarantorModal"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                    <?php if ($ff_show_supplier): ?>
                    <div class="col-md-4">
                        <label class="form-label">المورد (جهة التكلفة)</label>
                        <select name="supplier_id" id="<?php echo ff_h($cid('supplier_id')); ?>" class="form-select select2-financial" required>
                            <option value="">-- اختر المورد --</option>
                            <?php foreach ($suppliers_with_codes as $s): ?>
                                <?php
                                if (empty($s['supplier_id'])) {
                                    continue;
                                }
                                $supplier_account_id = $s['id'] ?? $s['account_id'] ?? '';
                                ?>
                                <option value="<?php echo ff_h($s['supplier_id']); ?>" data-account="<?php echo ff_h($supplier_account_id); ?>"
                                    <?php echo ((string)$val('supplier_id') === (string)$s['supplier_id']) ? 'selected' : ''; ?>>
                                    <?php echo ff_h($s['display_name'] ?? ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true)): ?>
                            <input type="hidden" name="record_purchase" id="<?php echo ff_h($cid('record_purchase')); ?>" value="1">
                        <?php else: ?>
                            <div class="mt-2 p-2 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25">
                                <label class="form-label extra-small fw-bold text-primary mb-1"><i class="fas fa-question-circle me-1"></i> هل تريد إنشاء فاتورة شراء للمورد؟</label>
                                <select name="record_purchase" id="<?php echo ff_h($cid('record_purchase')); ?>" class="form-select form-select-sm border-primary" required>
                                    <option value="" disabled <?php echo ($val('record_purchase', '') === '') ? 'selected' : ''; ?>>-- يجب الاختيار --</option>
                                    <option value="1" <?php echo ($val('record_purchase', '1') == '1') ? 'selected' : ''; ?>>نعم، تسجيل مديونية</option>
                                    <option value="0" <?php echo ($val('record_purchase', '1') == '0') ? 'selected' : ''; ?>>لا، مبيعات فقط</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div id="<?php echo ff_h($cid('supplier_balance_info')); ?>" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted"><i class="fas fa-wallet me-1"></i> رصيد المكتب عند المورد:</span>
                                <span id="<?php echo ff_h($cid('supplier_unified_balance_display')); ?>" class="fw-bold"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الدائن المسموح:</span>
                                <span id="<?php echo ff_h($cid('supplier_unified_limit_display')); ?>" class="fw-bold text-success"></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-<?php echo $ff_show_supplier ? '12' : '12'; ?> mt-2">
                        <label class="form-label">ملاحظات سريعة</label>
                        <input type="text" name="notes" id="<?php echo ff_h($cid('quick_notes')); ?>" class="form-control" placeholder="ملاحظات إضافية..." value="<?php echo ff_h($val('notes', '')); ?>">
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- القسم 3: البيانات المالية (الفاتورة) -->
    <div class="form-section-card mb-4">
        <div class="form-section-body">
            <div class="row g-3 mb-3 p-3 bg-light rounded-4 border border-dashed">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">نوع التوصيل</label>
                    <select name="delivery_type" id="<?php echo ff_h($cid('delivery_type')); ?>" class="form-select" required>
                        <option value="" disabled>-- اختر النوع --</option>
                        <option value="draft" <?php echo ($ff_initial_delivery_type === 'draft') ? 'selected' : ''; ?>>مسودة (Draft)</option>
                        <option value="cash" <?php echo ($ff_initial_delivery_type === 'cash') ? 'selected' : ''; ?>>نقدي (Cash)</option>
                        <option value="credit" <?php echo ($ff_initial_delivery_type === 'credit') ? 'selected' : ''; ?>>آجل (Credit)</option>
                        <option value="bank_transfer" <?php echo ($ff_initial_delivery_type === 'bank_transfer') ? 'selected' : ''; ?>>تحويل بنكي</option>
                        <option value="agent" <?php echo ($ff_initial_delivery_type === 'agent') ? 'selected' : ''; ?>>على الوكيل</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted" id="<?php echo ff_h($cid('account_label')); ?>">الحساب المتأثر</label>
                    <select name="account_id" id="<?php echo ff_h($cid('account_select')); ?>" class="form-select select2-financial" required disabled>
                        <option value="">-- اختر نوع التوصيل أولاً --</option>
                    </select>
                    <input type="hidden" name="customer_id" id="<?php echo ff_h($cid('customer_id_hidden')); ?>" value="<?php echo ff_h($val('customer_id', '')); ?>">
                    <input type="hidden" name="agent_id" id="<?php echo ff_h($cid('agent_id_hidden')); ?>" value="<?php echo ff_h($val('agent_id', '')); ?>">
                    <div id="<?php echo ff_h($cid('account_balance_info')); ?>" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted"><i class="fas fa-wallet me-1"></i> صافي الرصيد الموحد:</span>
                            <span id="<?php echo ff_h($cid('unified_balance_display')); ?>" class="fw-bold"></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الائتماني:</span>
                            <span id="<?php echo ff_h($cid('unified_limit_display')); ?>" class="fw-bold text-danger"></span>
                        </div>
                    </div>
                </div>
                <?php if ($ff_show_discount): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-danger">مبلغ الخصم</label>
                    <input type="number" step="0.01" name="discount" id="<?php echo ff_h($cid('discount')); ?>" class="form-control"
                           value="<?php echo ff_h($val('discount', 0)); ?>" data-original-discount="0">
                </div>
                <?php endif; ?>
                <div class="<?php echo $ff_show_discount ? 'col-md-3' : 'col-md-6'; ?>" id="<?php echo ff_h($cid('received_amount_field')); ?>" style="display: none;">
                    <label class="form-label small fw-bold text-muted">المبلغ الواصل (المقبوض)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-primary"><i class="fas fa-hand-holding-usd"></i></span>
                        <input type="number" step="0.01" name="received_amount" id="<?php echo ff_h($cid('received_amount')); ?>"
                               class="form-control fw-bold border-primary text-primary" placeholder="0.00"
                               value="<?php echo ff_h($val('received_amount', $val('amount_received', 0))); ?>">
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 p-3 bg-white border rounded-4 shadow-sm">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-primary">عملة البيع</label>
                    <select name="sale_currency_id" id="<?php echo ff_h($cid('sale_currency_id')); ?>" class="form-select">
                        <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo ff_h($curr['id']); ?>"
                                    data-symbol="<?php echo ff_h($curr['currency_symbol'] ?? ''); ?>"
                                    data-buy="<?php echo ff_h($curr['exchange_rate_buy'] ?? 1); ?>"
                                    data-sell="<?php echo ff_h($curr['exchange_rate_sell'] ?? 1); ?>"
                                    data-rate="<?php echo ff_h($curr['exchange_rate'] ?? 1); ?>"
                                    data-currency-name="<?php echo ff_h($curr['currency_name'] ?? ''); ?>"
                                <?php echo ((string)$val('sale_currency_id', $default_currency_id) === (string)$curr['id']) ? 'selected' : ''; ?>>
                                <?php echo ff_h($curr['currency_name'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-primary"> سعر البيع</label>
                    <input type="number" step="0.01" name="total_amount" id="<?php echo ff_h($cid('total_amount')); ?>" class="form-control fw-bold text-primary" required
                           value="<?php echo ff_h($val('total_amount', 0)); ?>"
                           data-original-price="<?php echo ff_h($val('total_amount', 0)); ?>"
                           data-service-currency-id="<?php echo ff_h($val('sale_currency_id', $default_currency_id)); ?>">
                    <div id="<?php echo ff_h($cid('sales_exchange_info')); ?>" class="extra-small text-muted mt-1" style="display: none;"></div>
                    <div id="<?php echo ff_h($cid('total_amount_words')); ?>" class="extra-small text-success mt-1"></div>
                </div>
                <?php if ($ff_show_cost_currency): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted" id="<?php echo ff_h($cid('main_currency_label')); ?>">عملة التكلفة (المورد)</label>
                    <select name="currency_id" id="<?php echo ff_h($cid('main_currency_id')); ?>" class="form-select">
                        <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo ff_h($curr['id']); ?>"
                                    data-symbol="<?php echo ff_h($curr['currency_symbol'] ?? ''); ?>"
                                    data-buy="<?php echo ff_h($curr['exchange_rate_buy'] ?? 1); ?>"
                                    data-sell="<?php echo ff_h($curr['exchange_rate_sell'] ?? 1); ?>"
                                    data-rate="<?php echo ff_h($curr['exchange_rate'] ?? 1); ?>"
                                    data-currency-name="<?php echo ff_h($curr['currency_name'] ?? ''); ?>"
                                <?php echo ((string)$val('currency_id', $default_currency_id) === (string)$curr['id']) ? 'selected' : ''; ?>>
                                <?php echo ff_h($curr['currency_name'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($ff_show_cost_amount): ?>
                <div class="col-md-<?php echo $ff_show_cost_currency ? 3 : 6; ?>">
                    <label class="form-label small fw-bold text-warning">سعر التكلفة</label>
                    <div class="input-group">
                        <input type="number" step="0.01" name="cost_amount" id="<?php echo ff_h($cid('cost_amount')); ?>" class="form-control fw-bold text-warning"
                               value="<?php echo ff_h($val('cost_amount', 0)); ?>"
                               data-original-cost="<?php echo ff_h($val('cost_amount', 0)); ?>"
                               data-cost-service-currency-id="<?php echo ff_h($val('currency_id', $default_currency_id)); ?>">
                        <span class="input-group-text bg-light" id="<?php echo ff_h($cid('cost_currency_display')); ?>">
                            <?php
                            // Show default currency name initially
                            $default_cost_curr = null;
                            foreach ($currencies as $curr) {
                                if ($curr['id'] == $val('currency_id', $default_currency_id)) {
                                    $default_cost_curr = $curr;
                                    break;
                                }
                            }
                            echo ff_h($default_cost_curr['currency_name'] ?? '');
                            ?>
                        </span>
                    </div>
                    <div id="<?php echo ff_h($cid('cost_amount_words')); ?>" class="extra-small text-warning mt-1"></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Exchange Rate and Equivalent Cost -->
            <div class="row g-3 mt-3">
                <!-- Exchange Rate (Only visible when currencies differ) -->
                <div class="col-md-4" id="<?php echo ff_h($cid('exchange_rate_container')); ?>" style="display: none;">
                    <div class="p-2 bg-light border border-dashed rounded-4 h-100">
                        <div class="row g-3 align-items-end">
                            <div class="col-12">
                                <label class="form-label extra-small fw-bold text-muted" id="<?php echo ff_h($cid('exchange_rate_label')); ?>">سعر الصرف</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white">1 <span class="<?php echo ff_h($p); ?>pur-symbol"></span> =</span>
                                    <input type="number" step="0.000001" name="exchange_rate" id="<?php echo ff_h($cid('invoice_exchange_rate')); ?>"
                                           class="form-control text-center fw-bold"
                                           value="<?php echo ff_h($val('exchange_rate', '1.000000')); ?>">
                                    <span class="input-group-text bg-white"><span class="<?php echo ff_h($p); ?>sale-symbol"></span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Equivalent Cost (Always visible) -->
                <div class="col-md-3">
                    <div class="p-2 bg-light border border-dashed rounded-4 h-100">
                        <label class="form-label extra-small fw-bold text-muted" id="<?php echo ff_h($cid('equivalent_cost_label')); ?>">التكلفة المعادلة</label>
                        <input type="text" id="<?php echo ff_h($cid('equivalent_cost_display')); ?>" class="form-control form-control-sm bg-white" readonly>
                    </div>
                </div>
            </div>

            <?php if (!$ff_show_host_guarantor && $ff_show_supplier): ?>
                <div class="row g-3 mb-3 p-3 bg-light border rounded-4 shadow-sm">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">المورد (جهة التكلفة)</label>
                        <select name="supplier_id" id="<?php echo ff_h($cid('supplier_id')); ?>" class="form-select select2-financial" required>
                            <option value="">-- اختر المورد --</option>
                            <?php foreach ($suppliers_with_codes as $s): ?>
                                <?php
                                if (empty($s['supplier_id'])) {
                                    continue;
                                }
                                $supplier_account_id = $s['id'] ?? $s['account_id'] ?? '';
                                ?>
                                <option value="<?php echo ff_h($s['supplier_id']); ?>" data-account="<?php echo ff_h($supplier_account_id); ?>"
                                    <?php echo ((string)$val('supplier_id') === (string)$s['supplier_id']) ? 'selected' : ''; ?>>
                                    <?php echo ff_h($s['display_name'] ?? ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true)): ?>
                            <input type="hidden" name="record_purchase" id="<?php echo ff_h($cid('record_purchase')); ?>" value="1">
                        <?php else: ?>
                            <div class="mt-2 p-2 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25">
                                <label class="form-label extra-small fw-bold text-primary mb-1"><i class="fas fa-question-circle me-1"></i> هل تريد إنشاء فاتورة شراء للمورد؟</label>
                                <select name="record_purchase" id="<?php echo ff_h($cid('record_purchase')); ?>" class="form-select form-select-sm border-primary" required>
                                    <option value="" disabled <?php echo ($val('record_purchase', '') === '') ? 'selected' : ''; ?>>-- يجب الاختيار --</option>
                                    <option value="1" <?php echo ($val('record_purchase', '1') == '1') ? 'selected' : ''; ?>>نعم، تسجيل مديونية</option>
                                    <option value="0" <?php echo ($val('record_purchase', '1') == '0') ? 'selected' : ''; ?>>لا، مبيعات فقط</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div id="<?php echo ff_h($cid('supplier_balance_info')); ?>" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted"><i class="fas fa-wallet me-1"></i> رصيد المكتب عند المورد:</span>
                                <span id="<?php echo ff_h($cid('supplier_unified_balance_display')); ?>" class="fw-bold"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الدائن المسموح:</span>
                                <span id="<?php echo ff_h($cid('supplier_unified_limit_display')); ?>" class="fw-bold text-success"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

    <!-- حسابات الخدمة -->
    <div class="row g-3 mb-3 p-3 bg-light rounded-4 border border-dashed <?php echo $ff_hide_service_accounts ? 'd-none' : ''; ?>">
        <div class="col-12 mb-2">
            <h6 class="fw-bold text-primary mb-0"><i class="fas fa-book me-2"></i> حسابات الخدمة</h6>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-success">حساب الإيرادات</label>
            <input type="text" id="<?php echo ff_h($cid('service_revenue_account')); ?>" class="form-control bg-white" readonly
                   placeholder="<?php echo ff_h($ff_show_service_select ? 'اختر نوع الخدمة أولاً' : 'يتم جلب الحساب حسب الخدمة الحالية'); ?>">
            <input type="hidden" name="revenue_account_id" id="<?php echo ff_h($cid('service_revenue_account_id')); ?>" value="">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-danger">حساب التكلفة</label>
            <input type="text" id="<?php echo ff_h($cid('service_cost_account')); ?>" class="form-control bg-white" readonly
                   placeholder="<?php echo ff_h($ff_show_service_select ? 'اختر نوع الخدمة أولاً' : 'يتم جلب الحساب حسب الخدمة الحالية'); ?>">
            <input type="hidden" name="cost_account_id" id="<?php echo ff_h($cid('service_cost_account_id')); ?>" value="">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-warning">حساب الأرباح</label>
            <input type="text" id="<?php echo ff_h($cid('service_profit_account')); ?>" class="form-control bg-white" readonly
                   placeholder="<?php echo ff_h($ff_show_service_select ? 'اختر نوع الخدمة أولاً' : 'يتم جلب الحساب حسب الخدمة الحالية'); ?>">
            <input type="hidden" name="profit_account_id" id="<?php echo ff_h($cid('service_profit_account_id')); ?>" value="">
        </div>
    </div>

    <!-- الوصف -->
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label small fw-bold text-muted">البيان / الوصف (يظهر في القيد المحاسبي)</label>
            <textarea name="description" id="<?php echo ff_h($cid('description')); ?>" class="form-control" rows="2"
                      placeholder="اكتب تفاصيل الفاتورة هنا..."><?php echo ff_h($val('description', '')); ?></textarea>
        </div>
        <?php if ($ff_show_notes_field): ?>
            <div class="col-12">
                <label class="form-label small fw-bold text-muted">ملاحظات سريعة</label>
                <textarea name="notes" id="<?php echo ff_h($cid('notes')); ?>" class="form-control" rows="2"
                          placeholder="ملاحظات داخل الفاتورة..."><?php echo ff_h($val('notes', '')); ?></textarea>
            </div>
        <?php endif; ?>
        </div>
    </div>
</fieldset>

<script>
(function() {
    var ffConfig = {
        prefix: <?php echo json_encode($p); ?>,
        apiUrl: <?php echo json_encode($ff_api_url); ?>,
        select2Parent: <?php echo json_encode($ff_select2_parent); ?>,
        formSelector: <?php echo json_encode($ff_form_selector); ?>,
        baseSymbol: <?php echo json_encode($base_currency['currency_symbol'] ?? ''); ?>,
        requireCostCenter: <?php echo !empty($settings['require_cost_center']) ? 'true' : 'false'; ?>,
        fixedSourceType: <?php echo json_encode($ff_show_service_select ? '' : $ff_source_type); ?>,
        initialAccountId: <?php echo json_encode($val('account_id', '')); ?>,
        initialDeliveryType: <?php echo json_encode($ff_initial_delivery_type); ?>,
        defaultCashAccountId: <?php echo json_encode($settings['default_cash_account_id'] ?? ''); ?>,
        defaultBankAccountId: <?php echo json_encode($settings['default_bank_account_id'] ?? ''); ?>
    };

    var entitiesData = {
        cashboxes: <?php echo json_encode($cashboxes_entities); ?>,
        customers: <?php echo json_encode($customers_entities); ?>,
        banks: <?php echo json_encode($banks_entities); ?>,
        agents: <?php echo json_encode($agents_entities); ?>
    };
    var serviceConfigs = <?php echo json_encode($service_configs); ?>;

    window.entitiesData = entitiesData;

    function pid(name) {
        return ffConfig.prefix + name;
    }

    var ffWarnTimer = null;
    function showWarning(message) {
        var $box = $('#' + pid('ff_warning'));
        if (!$box.length) {
            alert(message);
            return;
        }
        $box.text(message).removeClass('d-none');
        if (ffWarnTimer) {
            clearTimeout(ffWarnTimer);
        }
        ffWarnTimer = setTimeout(function() {
            $box.addClass('d-none').text('');
        }, 4000);
    }

    function asNumber(value) {
        var n = parseFloat(value);
        return isNaN(n) ? 0 : n;
    }

    // Tafqeet: Convert number to Arabic words
    function tafqeet(n, currencyName) {
        if (n === "" || isNaN(n) || n == 0) return "";
        
        const ones = ["", "واحد", "اثنان", "ثلاثة", "أربعة", "خمسة", "ستة", "سبعة", "ثمانية", "تسعة", "عشرة", "أحد عشر", "اثنا عشر", "ثلاثة عشر", "أربعة عشر", "خمسة عشر", "ستة عشر", "سبعة عشر", "ثمانية عشر", "تسعة عشر"];
        const tens = ["", "", "عشرون", "ثلاثون", "أربعون", "خمسون", "ستون", "سبعون", "ثمانون", "تسعون"];
        const hundreds = ["", "مائة", "مائتان", "ثلاثمائة", "أربعمائة", "خمسمائة", "ستمائة", "سبعمائة", "ثمانمائة", "تسعمائة"];
        
        function convertPart(num) {
            let partStr = "";
            const h = Math.floor(num / 100);
            const t = Math.floor((num % 100) / 10);
            const o = num % 10;
            
            if (h > 0) partStr += hundreds[h] + (num % 100 > 0 ? " و " : "");
            if (t > 1) {
                partStr += ones[o] + (o > 0 ? " و " : "") + tens[t];
            } else {
                partStr += ones[num % 100];
            }
            return partStr;
        }
        
        let result = "";
        let amount = Math.floor(n);
        let fractions = Math.round((n - amount) * 100);
        
        if (amount === 0) {
            result = "صفر";
        } else {
            if (amount >= 1000000) {
                const m = Math.floor(amount / 1000000);
                if (m === 1) result += "مليون";
                else if (m === 2) result += "مليونان";
                else if (m <= 10) result += convertPart(m) + " ملايين";
                else result += convertPart(m) + " مليون";
                amount %= 1000000;
                if (amount > 0) result += " و ";
            }
            if (amount >= 1000) {
                const k = Math.floor(amount / 1000);
                if (k === 1) result += "ألف";
                else if (k === 2) result += "ألفان";
                else if (k <= 10) result += convertPart(k) + " آلاف";
                else result += convertPart(k) + " ألف";
                amount %= 1000;
                if (amount > 0) result += " و ";
            }
            if (amount > 0) result += convertPart(amount);
        }
        
        result = "فقط " + result + " " + currencyName;
        if (fractions > 0) {
            result += " و " + convertPart(fractions) + " هللة";
        }
        return result + " لا غير";
    }

    function updateAmountInWords() {
        var p = ffConfig.prefix;
        // Update total amount in words (sale currency)
        var totalAmount = asNumber($('#' + pid('total_amount')).val());
        var saleCurrencyOpt = $('#' + pid('sale_currency_id') + ' option:selected');
        var saleCurrencyName = saleCurrencyOpt.text().trim() || 'ريال';
        $('#' + pid('total_amount_words')).text(tafqeet(totalAmount, saleCurrencyName));
        
        // Update cost amount in words (cost currency)
        var costAmount = asNumber($('#' + pid('cost_amount')).val());
        var costCurrencyOpt = $('#' + pid('main_currency_id') + ' option:selected');
        var costCurrencyName = costCurrencyOpt.text().trim() || 'ريال';
        $('#' + pid('cost_amount_words')).text(tafqeet(costAmount, costCurrencyName));
    }

    function getRateToSaleCurrency() {
        var saleCurrencyId = $('#' + pid('sale_currency_id')).val();
        var mainCurrencyId = $('#' + pid('main_currency_id')).val();
        var rate = asNumber($('#' + pid('invoice_exchange_rate')).val()) || 1;
        return (saleCurrencyId && mainCurrencyId && saleCurrencyId != mainCurrencyId) ? rate : 1;
    }

    function getCostInSaleCurrency() {
        var cost = asNumber($('#' + pid('cost_amount')).val());
        var rate = getRateToSaleCurrency();
        return cost * rate;
    }

    function setNumericIfChanged($el, value) {
        var current = asNumber($el.val());
        if (Math.abs(current - value) > 0.000001) {
            $el.val((Math.round(value * 100) / 100).toFixed(2));
        }
    }

    function enforceFinancialRules(showMessages) {
        var $total = $('#' + pid('total_amount'));
        var $cost = $('#' + pid('cost_amount'));
        var $discount = $('#' + pid('discount'));
        var $received = $('#' + pid('received_amount'));

        var total = asNumber($total.val());
        var rate = getRateToSaleCurrency();
        var maxCostMain = rate > 0 ? (total / rate) : total;
        var costMain = asNumber($cost.val());
        var costSale = costMain * rate;

        if (costSale > total + 0.000001) {
            setNumericIfChanged($cost, Math.max(0, maxCostMain));
            calculateEquivalent();
            if (showMessages) {
                showWarning('سعر التكلفة يجب أن يكون أقل من أو يساوي إجمالي سعر البيع.');
            }
        }

        var discount = asNumber($discount.val());
        var profit = Math.max(0, total - getCostInSaleCurrency());
        if (discount > profit + 0.000001) {
            setNumericIfChanged($discount, profit);
            if (showMessages) {
                showWarning('مبلغ الخصم لا يمكن أن يتجاوز مبلغ الربح.');
            }
        }

        var received = asNumber($received.val());
        if (received > total + 0.000001) {
            setNumericIfChanged($received, total);
            if (showMessages) {
                showWarning('المبلغ الواصل (المقبوض) يجب أن يكون أقل من أو يساوي إجمالي سعر البيع.');
            }
        }

        $received.attr('max', total.toFixed(2));
        $cost.attr('max', Math.max(0, maxCostMain).toFixed(2));
        $discount.attr('max', Math.max(0, profit).toFixed(2));

        $cost[0] && $cost[0].setCustomValidity(getCostInSaleCurrency() > total + 0.000001 ? 'سعر التكلفة أكبر من إجمالي سعر البيع' : '');
        $received[0] && $received[0].setCustomValidity(asNumber($received.val()) > total + 0.000001 ? 'المبلغ الواصل أكبر من إجمالي سعر البيع' : '');
        $discount[0] && $discount[0].setCustomValidity(asNumber($discount.val()) > profit + 0.000001 ? 'مبلغ الخصم أكبر من مبلغ الربح' : '');
    }

    function updateServiceAccounts(serviceName) {
        var p = ffConfig.prefix;
        var config = serviceConfigs[serviceName];
        if (!config) {
            var emptyPlaceholder = ffConfig.fixedSourceType ? 'لم يتم إعداد حسابات لهذه الخدمة' : 'اختر نوع الخدمة أولاً';
            $('#' + pid('service_revenue_account')).val('').attr('placeholder', emptyPlaceholder);
            $('#' + pid('service_cost_account')).val('').attr('placeholder', emptyPlaceholder);
            $('#' + pid('service_profit_account')).val('').attr('placeholder', emptyPlaceholder);
            $('#' + pid('service_revenue_account_id')).val('');
            $('#' + pid('service_cost_account_id')).val('');
            $('#' + pid('service_profit_account_id')).val('');
            return;
        }
        $('#' + pid('service_revenue_account')).val(config.revenue_account_name || 'لم يتم إعداد الحساب');
        $('#' + pid('service_cost_account')).val(config.cost_account_name || 'لم يتم إعداد الحساب');
        $('#' + pid('service_profit_account')).val(config.profit_account_name || 'لم يتم إعداد الحساب');
        $('#' + pid('service_revenue_account_id')).val(config.revenue_account_id || '');
        $('#' + pid('service_cost_account_id')).val(config.cost_account_id || '');
        $('#' + pid('service_profit_account_id')).val(config.profit_account_id || '');
    }

    function updateCurrencyDropdown(currencySelectId, accountId) {
        var select = $('#' + currencySelectId);
        var currentValue = select.val();

        function populate(currencies) {
            select.empty();
            (currencies || []).forEach(function(curr) {
                var isSelected = (String(curr.id) === String(currentValue)) || curr.is_default;
                select.append($('<option>', {
                    value: curr.id,
                    'data-symbol': curr.currency_symbol || '',
                    'data-buy': curr.exchange_rate_buy ?? 1,
                    'data-sell': curr.exchange_rate_sell ?? 1,
                    'data-rate': curr.exchange_rate ?? 1,
                    selected: isSelected
                }).text(curr.currency_name || ''));
            });
            updateLogic();
        }

        if (!accountId) {
            $.get(ffConfig.apiUrl, { action: 'get_active_currencies', account_id: 'all' }, function(response) {
                var currencies = typeof response === 'string' ? JSON.parse(response) : response;
                populate(currencies);
            });
            return;
        }

        $.get(ffConfig.apiUrl, { action: 'get_active_currencies', account_id: accountId }, function(currencies) {
            if (!currencies || currencies.length === 0) {
                $.get(ffConfig.apiUrl, { action: 'get_active_currencies', account_id: 'all' }, function(response) {
                    populate(typeof response === 'string' ? JSON.parse(response) : response);
                });
                return;
            }
            populate(currencies);
        }, 'json');
    }

    function updateLogic() {
        var p = ffConfig.prefix;
        var recordPurchase = $('#' + pid('record_purchase')).val() === '1';
        var purCurrencyId = $('#' + pid('main_currency_id')).val();
        var saleCurrencyId = $('#' + pid('sale_currency_id')).val();

        $('#' + pid('sale_currency_field')).show();
        $('#' + pid('main_currency_label')).text(recordPurchase ? 'عملة التكلفة (المورد)' : 'العملة');

        if (purCurrencyId && saleCurrencyId && purCurrencyId != saleCurrencyId) {
            $('#' + pid('exchange_rate_container')).show();
            var purOpt = $('#' + pid('main_currency_id') + ' option:selected');
            var saleOpt = $('#' + pid('sale_currency_id') + ' option:selected');
            var purSymbol = purOpt.data('symbol') || '---';
            var saleSymbol = saleOpt.data('symbol') || '---';
            var rate = (parseFloat(purOpt.data('buy')) || 1) / (parseFloat(saleOpt.data('sell')) || 1);

            $('.' + p + 'pur-symbol').text(purSymbol);
            $('.' + p + 'sale-symbol').text(saleSymbol);
            $('#' + pid('exchange_rate_label')).html('1 ' + purSymbol + ' = ? ' + saleSymbol);
            $('#' + pid('invoice_exchange_rate')).val(rate.toFixed(6));
        } else {
            $('#' + pid('invoice_exchange_rate')).val('1.000000');
            $('#' + pid('exchange_rate_container')).hide();
        }
        calculateEquivalent();
        updateAmountInWords();
    }

    function calculateEquivalent() {
        var p = ffConfig.prefix;
        var cost = parseFloat($('#' + pid('cost_amount')).val()) || 0;
        var saleCurrencyId = $('#' + pid('sale_currency_id')).val();
        var mainCurrencyId = $('#' + pid('main_currency_id')).val();
        var rate = parseFloat($('#' + pid('invoice_exchange_rate')).val()) || 1;
        var equivalent = (saleCurrencyId != mainCurrencyId) ? cost * rate : cost;
        var saleSymbol = $('#' + pid('sale_currency_id') + ' option:selected').data('symbol') || 'ر.ي';
        $('#' + pid('equivalent_cost_display')).val(equivalent.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' ' + saleSymbol);
        updateAmountInWords();
    }

    function validateDiscount() {
        var p = ffConfig.prefix;
        var total = parseFloat($('#' + pid('total_amount')).val()) || 0;
        var discount = parseFloat($('#' + pid('discount')).val()) || 0;
        var cost = parseFloat($('#' + pid('cost_amount')).val()) || 0;
        var saleCurrencyId = $('#' + pid('sale_currency_id')).val();
        var mainCurrencyId = $('#' + pid('main_currency_id')).val();
        var rate = parseFloat($('#' + pid('invoice_exchange_rate')).val()) || 1;
        var costInSaleCurrency = (saleCurrencyId != mainCurrencyId) ? cost * rate : cost;
        var netPrice = total - discount;

        if (discount > 0 && netPrice < costInSaleCurrency - 0.01) {
            $('#' + pid('discount')).addClass('is-invalid');
            var maxAllowed = Math.max(0, total - costInSaleCurrency);
            var errorMsg = 'عفواً! لا يمكن أن يقل السعر الصافي عن التكلفة (' + costInSaleCurrency.toFixed(2) + '). أقصى خصم مسموح: ' + maxAllowed.toFixed(2);
            if (!$('#' + pid('discount_error')).length) {
                $('#' + pid('discount')).after('<div id="' + pid('discount_error') + '" class="invalid-feedback extra-small fw-bold"></div>');
            }
            $('#' + pid('discount_error')).text(errorMsg);
            return false;
        }
        $('#' + pid('discount')).removeClass('is-invalid');
        $('#' + pid('discount_error')).remove();
        return true;
    }

    function updateConvertedPrices(skipDiscount) {
        var p = ffConfig.prefix;
        var priceOrig = parseFloat($('#' + pid('total_amount')).attr('data-original-price')) || 0;
        var priceCurrId = $('#' + pid('total_amount')).attr('data-service-currency-id');
        var saleCurrId = $('#' + pid('sale_currency_id')).val();

        if (priceOrig > 0) {
            $('#' + pid('total_amount')).prop('readonly', true);
            var convBase = priceOrig;
            if (saleCurrId && priceCurrId && saleCurrId != priceCurrId) {
                var saleOpt = $('#' + pid('sale_currency_id') + ' option:selected');
                var serviceOpt = $('#' + pid('sale_currency_id') + ' option[value="' + priceCurrId + '"]').length
                    ? $('#' + pid('sale_currency_id') + ' option[value="' + priceCurrId + '"]')
                    : $('#' + pid('main_currency_id') + ' option[value="' + priceCurrId + '"]');
                if (serviceOpt.length) {
                    var rate = (parseFloat(serviceOpt.data('buy')) || 1) / (parseFloat(saleOpt.data('sell')) || 1);
                    convBase = priceOrig * rate;
                    $('#' + pid('sales_exchange_info')).html('<i class="fas fa-sync-alt me-1"></i> 1 ' + (serviceOpt.data('symbol') || '---') + ' = ' + rate.toFixed(4) + ' ' + (saleOpt.data('symbol') || '---')).show();
                }
            } else {
                $('#' + pid('sales_exchange_info')).hide();
            }
            $('#' + pid('total_amount')).val(convBase.toFixed(2));
        } else {
            $('#' + pid('total_amount')).prop('readonly', false);
            $('#' + pid('sales_exchange_info')).hide();
        }

        var costOrig = parseFloat($('#' + pid('cost_amount')).attr('data-original-cost')) || 0;
        var costCurrId = $('#' + pid('cost_amount')).attr('data-cost-service-currency-id');
        var mainCurrId = $('#' + pid('main_currency_id')).val();
        if (costOrig > 0) {
            $('#' + pid('cost_amount')).prop('readonly', true);
            var convCost = costOrig;
            if (mainCurrId && costCurrId && mainCurrId != costCurrId) {
                var mainOpt = $('#' + pid('main_currency_id') + ' option:selected');
                var costSrvOpt = $('#' + pid('main_currency_id') + ' option[value="' + costCurrId + '"]').length
                    ? $('#' + pid('main_currency_id') + ' option[value="' + costCurrId + '"]')
                    : $('#' + pid('sale_currency_id') + ' option[value="' + costCurrId + '"]');
                if (costSrvOpt.length) {
                    convCost = costOrig * ((parseFloat(costSrvOpt.data('buy')) || 1) / (parseFloat(mainOpt.data('sell')) || 1));
                }
            }
            $('#' + pid('cost_amount')).val(convCost.toFixed(2));
        } else {
            $('#' + pid('cost_amount')).prop('readonly', false);
        }

        validateDiscount();
        calculateEquivalent();
    }

    function handleDeliveryType(type) {
        var p = ffConfig.prefix;
        var label = 'الحساب المتأثر';
        var $sel = $('#' + pid('account_select'));

        if (!type) {
            $sel.prop('disabled', false).empty().append('<option value="">-- اختر نوع التوصيل أولاً --</option>').trigger('change');
            $('#' + pid('account_label')).text('الحساب المتأثر');
            $('#' + pid('received_amount_field')).hide();
            return;
        }

        $sel.prop('disabled', true).empty().append('<option value="">-- جارٍ التحميل... --</option>');

        $.ajax({
            url: 'ajax_get_financial_entities.php',
            type: 'GET',
            data: { type: type },
            dataType: 'json',
            success: function(response) {
                if (!response || !response.success) {
                    alert('حدث خطأ أثناء جلب البيانات: ' + (response?.message || 'خطأ غير معروف'));
                    $sel.prop('disabled', true).empty().append('<option value="">-- فشل تحميل البيانات --</option>');
                    return;
                }

                var list = response.entities || [];
                $sel.prop('disabled', false);
                if (type === 'cash') {
                    label = 'الحساب: الصناديق';
                    $('#' + pid('received_amount_field')).show();
                } else if (type === 'credit') {
                    label = 'الحساب: العملاء';
                    $('#' + pid('received_amount_field')).hide();
                } else if (type === 'bank_transfer') {
                    label = 'الحساب: البنوك';
                    $('#' + pid('received_amount_field')).hide();
                } else if (type === 'agent') {
                    label = 'الحساب: الوكلاء';
                    $('#' + pid('received_amount_field')).hide();
                } else {
                    $('#' + pid('received_amount_field')).hide();
                }

                $('#' + pid('account_label')).text(label);
                $sel.empty().append('<option value="">-- اختر --</option>');
                list.forEach(function(item) {
                    var displayName = item.display_name || ((item.account_code || '') + ' - ' + (item.name || item.account_name_ar || ''));
                    $sel.append('<option value="' + item.id + '" data-customer-id="' + (item.customer_id || '') + '" data-agent-id="' + (item.agent_id || '') + '">' + displayName + '</option>');
                });

                var targetAccountId = '';
                if (ffConfig.initialAccountId) {
                    targetAccountId = String(ffConfig.initialAccountId);
                } else if (type === 'cash' && ffConfig.defaultCashAccountId) {
                    targetAccountId = String(ffConfig.defaultCashAccountId);
                } else if (type === 'bank_transfer' && ffConfig.defaultBankAccountId) {
                    targetAccountId = String(ffConfig.defaultBankAccountId);
                } else if (list.length === 1) {
                    targetAccountId = String(list[0].id || '');
                }

                if (targetAccountId && $sel.find('option[value="' + targetAccountId + '"]').length) {
                    $sel.val(targetAccountId);
                }

                $sel.trigger('change');
            },
            error: function(xhr, status, error) {
                alert('حدث خطأ أثناء الاتصال بالخادم: ' + error);
                $sel.prop('disabled', true).empty().append('<option value="">-- فشل الاتصال --</option>');
            }
        });
    }

    function setCustomerAgentHidden(customerId, agentId) {
        $('#' + pid('customer_id_hidden')).val(customerId || '');
        $('#' + pid('agent_id_hidden')).val(agentId || '');
    }

    function fetchAccountBalance(accountId) {
        if (!accountId) {
            $('#' + pid('account_balance_info')).addClass('d-none');
            return;
        }
        $.get('ajax_get_account_balances.php', { account_id: accountId }, function(data) {
            if (!data || !data.length) {
                $('#' + pid('account_balance_info')).addClass('d-none');
                return;
            }
            var totalNetBalanceBase = 0;
            var creditLimitBase = parseFloat(data[0].credit_limit_base) || 0;
            var normalBalance = data[0].normal_balance;
            data.forEach(function(bal) {
                totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
            });

            // Adjust balance based on normal balance
            var adjustedBalance = totalNetBalanceBase;
            if (normalBalance === 'credit') {
                adjustedBalance = -totalNetBalanceBase;
            }

            var statusText = '';
            var statusClass = '';
            if (Math.abs(adjustedBalance) < 0.01) {
                statusText = '(متعادل)';
                statusClass = 'text-muted';
            } else {
                statusText = adjustedBalance > 0 ? '(عليه)' : '(له)';
                statusClass = adjustedBalance > 0 ? 'text-danger' : 'text-success';
            }

            $('#' + pid('unified_balance_display')).html(
                '<span class="' + statusClass + '">' + Math.abs(adjustedBalance).toLocaleString(undefined, { minimumFractionDigits: 2 }) +
                '</span> <small class="text-muted">' + ffConfig.baseSymbol + '</small> ' + statusText
            );
            $('#' + pid('unified_limit_display')).text(
                creditLimitBase > 0 ? creditLimitBase.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' ' + ffConfig.baseSymbol : 'غير محدد'
            );
            $('#' + pid('account_balance_info')).removeClass('d-none');
        });
    }

    function fetchSupplierBalance(accountId) {
        var $infoBox = $('#' + pid('supplier_balance_info'));
        if (!accountId) {
            $infoBox.addClass('d-none');
            return;
        }
        $.get('ajax_get_account_balances.php', { account_id: accountId }, function(data) {
            if (!data || !data.length) {
                $infoBox.addClass('d-none');
                return;
            }
            var totalNetBalanceBase = 0;
            var debitLimitBase = parseFloat(data[0].debit_limit_base) || 0;
            var normalBalance = data[0].normal_balance;
            data.forEach(function(bal) {
                totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
            });

            // Adjust balance for supplier (usually credit normal balance)
            var adjustedBalance = totalNetBalanceBase;
            if (normalBalance === 'credit') {
                adjustedBalance = -totalNetBalanceBase;
            }

            // For supplier:
            // If adjustedBalance < 0 → they owe us (لنا عنده)
            // If adjustedBalance > 0 → we owe them (له عندنا)
            var statusText = adjustedBalance < 0 ? '(لنا عنده)' : '(له عندنا)';
            var statusClass = adjustedBalance < 0 ? 'text-success' : 'text-danger';

            $('#' + pid('supplier_unified_balance_display')).html(
                '<span class="' + statusClass + '">' + Math.abs(adjustedBalance).toLocaleString(undefined, { minimumFractionDigits: 2 }) +
                '</span> <small class="text-muted">' + ffConfig.baseSymbol + '</small> ' + statusText
            );
            $('#' + pid('supplier_unified_limit_display')).text(
                debitLimitBase > 0 ? debitLimitBase.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' ' + ffConfig.baseSymbol : 'غير محدد'
            );
            $infoBox.removeClass('d-none');
        });
    }

    function bindEvents() {
        var p = ffConfig.prefix;
        var ns = '.financialFields' + p;

        $('#' + pid('delivery_type')).off(ns).on('change' + ns, function() {
            handleDeliveryType($(this).val());
        });

        $('#' + pid('account_select')).off('select2:opening' + ns).on('select2:opening' + ns, function(e) {
            var type = $('#' + pid('delivery_type')).val();
            if (!type) {
                e.preventDefault();
                showWarning('يجب أولاً اختيار نوع التوصيل.');
                return false;
            }
        });

        $(document).off('mousedown' + ns, '#select2-' + pid('account_select') + '-container').on('mousedown' + ns, '#select2-' + pid('account_select') + '-container', function() {
            var type = $('#' + pid('delivery_type')).val();
            if (!type) {
                showWarning('يجب أولاً اختيار نوع التوصيل.');
            }
        });

        $('#' + pid('account_select')).off(ns).on('change' + ns, function() {
            var customerId = $(this).find(':selected').data('customer-id');
            var agentId = $(this).find(':selected').data('agent-id');
            var accountId = $(this).val();
            setCustomerAgentHidden(customerId, agentId);
            updateCurrencyDropdown(pid('sale_currency_id'), accountId);
            fetchAccountBalance(accountId);
            $(document).trigger('financialFields:accountChanged', [{ customerId: customerId, agentId: agentId, accountId: accountId, prefix: p }]);
        });

        $('#' + pid('supplier_id')).off(ns).on('change' + ns, function() {
            var supplierId = $(this).val();
            var accountId = $(this).find(':selected').data('account');
            if (supplierId) {
                $.get(ffConfig.apiUrl, {
                    action: 'get_account_from_entity',
                    entity_type: 'supplier',
                    entity_id: supplierId
                }, function(data) {
                    if (data && data.account_id) {
                        updateCurrencyDropdown(pid('main_currency_id'), data.account_id);
                    }
                }, 'json');
            } else {
                updateCurrencyDropdown(pid('main_currency_id'), null);
            }
            fetchSupplierBalance(accountId);
            $(document).trigger('financialFields:supplierChanged', [{ supplierId: supplierId, accountId: accountId, prefix: p }]);
        });

        $('#' + pid('main_currency_id') + ', #' + pid('sale_currency_id') + ', #' + pid('record_purchase'))
            .off(ns).on('change' + ns, function() {
                // Update sale currency display
                if (this.id === pid('sale_currency_id')) {
                    const selectedOption = $(this).find('option:selected');
                    $('#' + pid('sale_currency_display')).text(selectedOption.data('currency-name'));
                }
                // Update cost currency display
                if (this.id === pid('main_currency_id')) {
                    const selectedOption = $(this).find('option:selected');
                    $('#' + pid('cost_currency_display')).text(selectedOption.data('currency-name'));
                }
                updateLogic();
                updateConvertedPrices();
                enforceFinancialRules(false);
                updateAmountInWords(); // Add this to update the amount in words when currency changes!
            });

        $('#' + pid('invoice_exchange_rate') + ', #' + pid('cost_amount'))
            .off(ns).on('input' + ns, function() {
                enforceFinancialRules(false);
                calculateEquivalent();
                updateConvertedPrices();
                updateAmountInWords();
            });

        $('#' + pid('discount')).off(ns).on('input' + ns, function() {
            enforceFinancialRules(true);
            updateConvertedPrices(true);
            updateAmountInWords();
        });

        $('#' + pid('total_amount')).off(ns).on('input' + ns, function() {
            enforceFinancialRules(true);
            validateDiscount();
            updateAmountInWords();
        });

        $('#' + pid('received_amount')).off(ns).on('input' + ns, function() {
            enforceFinancialRules(true);
        });

        var serviceSelector = ffConfig.fixedSourceType ? null : ('#' + pid('service_id'));
        if (serviceSelector && $(serviceSelector).length) {
            $(serviceSelector).off(ns).on('change' + ns, function() {
                updateServiceAccounts($(this).val());
                $(document).trigger('financialFields:serviceChanged', [{ serviceName: $(this).val(), prefix: p }]);
            });
        }

        if (ffConfig.formSelector) {
            $(ffConfig.formSelector).off('submit' + ns).on('submit' + ns, function(e) {
                var recordVal = $('#' + pid('record_purchase')).val();
                if (recordVal === null || recordVal === '') {
                    e.preventDefault();
                    alert('يرجى اختيار ما إذا كنت تريد تسجيل مديونية للمورد أم لا.');
                    $('#' + pid('record_purchase')).focus();
                    return false;
                }
                if (!validateDiscount()) {
                    e.preventDefault();
                    alert('عفواً! لا يمكن حفظ الفاتورة لأن السعر بعد الخصم أقل من سعر التكلفة.');
                    $('#' + pid('discount')).focus();
                    return false;
                }
                enforceFinancialRules(true);
                if ($('#' + pid('cost_amount'))[0] && !$('#' + pid('cost_amount'))[0].checkValidity()) {
                    e.preventDefault();
                    $('#' + pid('cost_amount'))[0].reportValidity();
                    return false;
                }
                if ($('#' + pid('received_amount'))[0] && !$('#' + pid('received_amount'))[0].checkValidity()) {
                    e.preventDefault();
                    $('#' + pid('received_amount'))[0].reportValidity();
                    return false;
                }
                if ($('#' + pid('discount'))[0] && !$('#' + pid('discount'))[0].checkValidity()) {
                    e.preventDefault();
                    $('#' + pid('discount'))[0].reportValidity();
                    return false;
                }
                if (ffConfig.requireCostCenter) {
                    var branchId = $('#' + pid('branch_id')).val();
                    if (!branchId) {
                        e.preventDefault();
                        alert('عفواً! اختيار الفرع (مركز التكلفة) إلزامي حسب إعدادات النظام.');
                        $('#' + pid('branch_id')).focus();
                        return false;
                    }
                }
            });
        }
    }

    function initFinancialFields() {
        if (typeof jQuery === 'undefined') {
            return;
        }

        if ($.fn.select2 && ffConfig.select2Parent) {
            $('.financial-fields-block[data-ff-prefix="' + ffConfig.prefix + '"] .select2-financial').select2({
                dropdownParent: $(ffConfig.select2Parent),
                width: '100%'
            });
        }

        bindEvents();

        var serviceName = ffConfig.fixedSourceType || ($('#' + pid('service_id')).val() || $('#' + pid('source_type_hidden')).val() || 'general');
        updateServiceAccounts(serviceName);

        if (ffConfig.initialDeliveryType) {
            handleDeliveryType(ffConfig.initialDeliveryType);
            if (ffConfig.initialAccountId) {
                setTimeout(function() {
                    $('#' + pid('account_select')).val(ffConfig.initialAccountId).trigger('change');
                }, 100);
            }
        }

        updateLogic();
        calculateEquivalent();
        updateConvertedPrices();
        enforceFinancialRules(false);
        updateAmountInWords();
    }

    window.handleDeliveryType = function(type, selectId, labelId, receivedFieldId) {
        handleDeliveryType(type);
    };
    window.updateLogic = updateLogic;
    window.calculateEquivalent = calculateEquivalent;
    window.updateConvertedPrices = updateConvertedPrices;
    window.validateDiscount = validateDiscount;
    window.updateCurrencyDropdown = updateCurrencyDropdown;

    window.FinancialFields = {
        config: ffConfig,
        init: initFinancialFields,
        updateLogic: updateLogic,
        calculateEquivalent: calculateEquivalent,
        updateConvertedPrices: updateConvertedPrices,
        validateDiscount: validateDiscount,
        handleDeliveryType: handleDeliveryType,
        updateServiceAccounts: updateServiceAccounts,
        updateCurrencyDropdown: updateCurrencyDropdown
    };

    <?php if (!$ff_manual_init): ?>
    if (typeof jQuery !== 'undefined') {
        jQuery(function() {
            initFinancialFields();
        });
    }
    <?php endif; ?>
})();
</script>
