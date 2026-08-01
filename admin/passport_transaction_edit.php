<?php
ob_start();
require_once 'header.php';

// Check permissions
if (!has_permission('passport_transactions_edit')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: passport_transactions.php');
    exit();
}

$id = (int)$_GET['id'];

// Fetch transaction details
$stmt = $pdo->prepare("
    SELECT pt.*, 
           inv.currency_id, 
           inv.total_amount as sale_price, 
           inv.cost_amount as purchase_price,
           inv.delivery_type as payment_type,
           inv.account_id,
           inv.customer_id
    FROM passport_transactions pt
    LEFT JOIN invoices inv ON inv.source_type = 'passport_transaction' AND inv.source_id = pt.id AND inv.invoice_category = 'sales'
    WHERE pt.id = ?
");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    header('Location: passport_transactions.php');
    exit();
}

$page_title = "تعديل معاملة جوازات";
$settings = getSettings($pdo);

// Fetch auxiliary data
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol, is_default, exchange_rate, exchange_rate_buy, exchange_rate_sell FROM currencies WHERE is_active = 1 ORDER BY currency_name ASC")->fetchAll();

// Get service id for "جوازت السفر"
$passportService = $pdo->prepare("SELECT id FROM services WHERE service_name = ? LIMIT 1");
$passportService->execute(['جوازت السفر']);
$passportServiceId = $passportService->fetchColumn();

$passport_types = $pdo->prepare("SELECT id, type_name, default_cost, default_sale_price, currency_id, print_terms, service_id FROM passport_transaction_types WHERE is_active = 1 AND (service_id = ? OR service_id IS NULL) ORDER BY type_name ASC");
$passport_types->execute([$passportServiceId]);
$passport_types = $passport_types->fetchAll();

// جلب الموردين مع أكواد حساباتهم مثل شاشة الإضافة
$parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$parent_stmt_suppliers->execute();
$suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

if ($suppliers_parent_id) {
    $suppliers_stmt = $pdo->prepare("
        SELECT coa.*, s.id as supplier_id, s.supplier_name, coa.id as account_id
        FROM unified_accounts coa
        LEFT JOIN suppliers s ON s.account_id = coa.id
        WHERE coa.parent_id = ? AND (coa.account_status = 'active' OR coa.account_status = 'dormant')
        ORDER BY coa.account_code ASC
    ");
    $suppliers_stmt->execute([$suppliers_parent_id]);
    $suppliers_with_codes = $suppliers_stmt->fetchAll();
    foreach ($suppliers_with_codes as &$s) {
        $s['display_name'] = $s['account_code'] . ' - ' . $s['account_name_ar'];
    }
    unset($s);
} else {
    $suppliers_with_codes = [];
}

// Get entities for financial logic (similar to invoices.php)
$customers_entities = $pdo->query("
    SELECT c.id as id, c.account_id as account_id, c.full_name as name, ua.account_code
    FROM customers c
    JOIN unified_accounts ua ON c.account_id = ua.id
    WHERE c.status = 'active' AND c.deleted_at IS NULL
    ORDER BY c.full_name ASC
")->fetchAll();

$agents_entities = $pdo->query("
    SELECT a.id, a.agent_name as name, a.account_id as account_id, acc.account_code
    FROM agents a
    JOIN unified_accounts acc ON a.account_id = acc.id
    WHERE a.status = 'active' AND a.deleted_at IS NULL
    ORDER BY a.agent_name ASC
")->fetchAll();

$cashboxes_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE (account_code LIKE '101%' OR account_code LIKE '111%' OR account_type = 'box') 
      AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();

$banks_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE (account_code LIKE '102%' OR account_type = 'bank') 
      AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();

// Fetch invoices associated with this transaction
$stmt_invoices = $pdo->prepare("SELECT * FROM invoices WHERE source_type = 'passport_transaction' AND source_id = ?");
$stmt_invoices->execute([$id]);
$invoices = $stmt_invoices->fetchAll();

$sales_invoice = null;
$purchase_invoice = null;
foreach($invoices as $inv) {
    if ($inv['invoice_category'] == 'sales') $sales_invoice = $inv;
    if ($inv['invoice_category'] == 'purchase') $purchase_invoice = $inv;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-edit me-2"></i> تعديل معاملة جوازات</h3>
            <p class="text-muted small mb-0">تحديث بيانات المعاملة رقم: <?php echo htmlspecialchars($trx['transaction_number']); ?></p>
        </div>
        <a href="passport_transaction_view.php?id=<?php echo $id; ?>" class="btn btn-light border rounded-pill px-4 shadow-sm">
            <i class="fas fa-arrow-right me-1"></i> عودة للتفاصيل
        </a>
    </div>

    <form action="process_passport_transaction.php" method="POST" id="transactionForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="row g-4">
            <!-- Basic Information -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="fw-bold mb-0 text-primary small"><i class="fas fa-user-edit me-2"></i> بيانات المسافر والهوية</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">الاسم الكامل <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" name="full_name" value="<?php echo htmlspecialchars($trx['full_name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">رقم الهاتف <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" name="phone_number" value="<?php echo htmlspecialchars($trx['phone_number']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">مكان الميلاد</label>
                                <input type="text" class="form-control rounded-3" name="place_of_birth" value="<?php echo htmlspecialchars($trx['place_of_birth']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">تاريخ الميلاد</label>
                                <input type="date" class="form-control rounded-3" name="date_of_birth" value="<?php echo $trx['date_of_birth']; ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">نوع الهوية</label>
                                <select class="form-select rounded-3" name="id_type">
                                    <option value="passport" <?php echo $trx['id_type'] == 'passport' ? 'selected' : ''; ?>>جواز سفر</option>
                                    <option value="national_id" <?php echo $trx['id_type'] == 'national_id' ? 'selected' : ''; ?>>هوية وطنية</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">رقم الهوية</label>
                                <input type="text" class="form-control rounded-3" name="id_number" value="<?php echo htmlspecialchars($trx['id_number']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">من مدينة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3" name="from_city_id" required>
                                    <option value="">اختر مدينة...</option>
                                    <?php foreach($cities as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $trx['from_city_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">إلى مدينة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3" name="to_city_id" required>
                                    <option value="">اختر مدينة...</option>
                                    <?php foreach($cities as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $trx['to_city_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">تاريخ السفر</label>
                                <div class="input-group">
                                    <input type="date" class="form-control rounded-start-3" name="travel_date" id="travel_date" value="<?php echo $trx['travel_date'] ?? ''; ?>" min="<?php echo date('Y-m-d'); ?>">
                                    <span class="input-group-text bg-light border-start-0 rounded-end-3 fw-bold text-primary" id="travel_day_name" style="min-width: 100px; justify-content: center;">---</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transaction Details (Shown only if workflow is disabled or manually enabled) -->
                <?php if (!($settings['passport_workflow_enabled'] ?? 1)): ?>
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="fw-bold mb-0 text-primary small"><i class="fas fa-tasks me-2"></i> تفاصيل المعاملة</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label class="form-label small fw-bold">نوع المعاملة <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="transaction_type" id="type_both" value="both" <?php echo $trx['transaction_type'] == 'both' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="type_both">بطاقة وجواز</label>
                                
                                <input type="radio" class="btn-check" name="transaction_type" id="type_card" value="card_only" <?php echo $trx['transaction_type'] == 'card_only' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="type_card">بطاقة فقط</label>
                                
                                <input type="radio" class="btn-check" name="transaction_type" id="type_passport" value="passport_only" <?php echo $trx['transaction_type'] == 'passport_only' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="type_passport">جواز فقط</label>
                            </div>
                        </div>

                        <div id="card_section" style="<?php echo $trx['transaction_type'] == 'passport_only' ? 'display:none;' : ''; ?>">
                            <h6 class="fw-bold small text-muted mb-3"><i class="fas fa-id-card me-2"></i> بيانات البطاقة</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم معاملة البطاقة</label>
                                    <input type="text" class="form-control rounded-3" name="card_transaction_number" value="<?php echo htmlspecialchars($trx['card_transaction_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ معاملة البطاقة</label>
                                    <input type="date" class="form-control rounded-3" name="card_transaction_date" value="<?php echo $trx['card_transaction_date']; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم البطاقة</label>
                                    <input type="text" class="form-control rounded-3" name="card_number" value="<?php echo htmlspecialchars($trx['card_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ إصدار البطاقة</label>
                                    <input type="date" class="form-control rounded-3" name="card_issue_date" value="<?php echo $trx['card_issue_date']; ?>">
                                </div>
                            </div>
                        </div>

                        <div id="passport_section" style="<?php echo $trx['transaction_type'] == 'card_only' ? 'display:none;' : ''; ?>">
                            <h6 class="fw-bold small text-muted mb-3"><i class="fas fa-passport me-2"></i> بيانات الجواز</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم معاملة الجواز</label>
                                    <input type="text" class="form-control rounded-3" name="passport_transaction_number" value="<?php echo htmlspecialchars($trx['passport_transaction_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ معاملة الجواز</label>
                                    <input type="date" class="form-control rounded-3" name="passport_transaction_date" value="<?php echo $trx['passport_transaction_date']; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم الجواز</label>
                                    <input type="text" class="form-control rounded-3" name="passport_number" value="<?php echo htmlspecialchars($trx['passport_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ إصدار الجواز</label>
                                    <input type="date" class="form-control rounded-3" name="passport_issue_date" value="<?php echo $trx['passport_issue_date']; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">اسم العميل المستلم</label>
                            <input type="text" class="form-control rounded-3" name="delivery_receiver_name" value="<?php echo htmlspecialchars($trx['delivery_receiver_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="transaction_type" value="<?php echo $trx['transaction_type']; ?>">
                <?php endif; ?>
            </div>

            <!-- Financial Information -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 mb-4 bg-light">
                    <div class="card-header bg-primary text-white border-0 py-3 rounded-top-4">
                        <h5 class="fw-bold mb-0 small"><i class="fas fa-money-bill-wave me-2"></i> البيانات المالية</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <input type="hidden" name="transaction_number" value="<?php echo htmlspecialchars($trx['transaction_number']); ?>">
                            <input type="hidden" name="service_id" id="passport_service_id" value="<?php echo (int)$passportServiceId; ?>">

                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-primary">نوع المعاملة (التسعيرة) <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 border-primary select2-financial" name="transaction_type_id[]" id="transaction_type_id" multiple required>
                                    <?php foreach($passport_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                                data-cost="<?php echo $type['default_cost']; ?>" 
                                                data-sale="<?php echo $type['default_sale_price']; ?>"
                                                data-currency="<?php echo $type['currency_id']; ?>"
                                                data-terms="<?php echo htmlspecialchars($type['print_terms'] ?? ''); ?>"
                                                data-service-id="<?php echo $type['service_id'] ?? ''; ?>"
                                                <?php echo $trx['transaction_type_id'] == $type['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['type_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php
                        $current_invoice = [
                            'invoice_date' => normalize_datetime_db($trx['operation_date'] ?? null),
                            'branch_id' => $trx['branch_id'] ?? ($_SESSION['branch_id'] ?? null),
                            'source_type' => 'معاملات جوازات',
                            'delivery_type' => $sales_invoice['delivery_type'] ?? ($settings['default_delivery_type'] ?? 'draft'),
                            'total_amount' => $sales_invoice['total_amount'] ?? 0,
                            'discount' => $sales_invoice['discount'] ?? 0,
                            'cost_amount' => $purchase_invoice['total_amount'] ?? ($sales_invoice['cost_amount'] ?? 0),
                            'received_amount' => $sales_invoice['amount_received'] ?? 0,
                            'record_purchase' => $purchase_invoice ? 1 : 0,
                            'currency_id' => $purchase_invoice['currency_id'] ?? ($sales_invoice['currency_id'] ?? 1),
                            'sale_currency_id' => $sales_invoice['currency_id'] ?? 1,
                            'supplier_id' => $purchase_invoice['supplier_id'] ?? ($sales_invoice['supplier_id'] ?? null),
                            'account_id' => $sales_invoice['account_id'] ?? null,
                            'customer_id' => $sales_invoice['customer_id'] ?? null,
                            'agent_id' => $sales_invoice['agent_id'] ?? null,
                            'description' => $trx['description'] ?? ''
                        ];
                        $financial_fields_show_service_select = false;
                        $financial_fields_header_layout = 'split_rows';
                        $financial_fields_title_layout = 'block';
                        $financial_fields_hide_service_accounts = true;
                        include '../includes/financial_fields.php';
                        ?>

                        <div class="row g-3 mt-3">
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">ملاحظات إضافية</label>
                                <textarea class="form-control rounded-3 form-control-sm" name="notes" rows="1"><?php echo htmlspecialchars($trx['notes']); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill py-3 fw-bold shadow">
                        <i class="fas fa-save me-2"></i> تحديث بيانات المعاملة
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
body.theme-dark .select2-container--default .select2-selection--single,
body.theme-dark .select2-container--default .select2-selection--multiple {
    background-color: #1a2234 !important;
    border-color: #2d3748 !important;
    color: #e2e8f0 !important;
}
body.theme-dark .select2-container--default .select2-selection--single .select2-selection__rendered {
    color: #e2e8f0 !important;
}
body.theme-dark .select2-container--default .select2-selection--multiple .select2-selection__rendered {
    color: #e2e8f0 !important;
}
body.theme-dark .select2-container--default .select2-selection--multiple .select2-selection__choice {
    background: #334155 !important;
    border: 1px solid #475569 !important;
    color: #f8fafc !important;
}
body.theme-dark .select2-container--default .select2-selection--multiple .select2-selection__choice__display {
    color: #f8fafc !important;
}
body.theme-dark .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: #cbd5e1 !important;
    border-left-color: #475569 !important;
}
body.theme-dark .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    background: #475569 !important;
    color: #ffffff !important;
}
body.theme-dark .select2-container--default .select2-search--inline .select2-search__field {
    color: #e2e8f0 !important;
}
body.theme-dark .select2-container--default .select2-selection--single .select2-selection__arrow b {
    border-color: #e2e8f0 transparent transparent transparent !important;
}
body.theme-dark .select2-container--default .select2-dropdown {
    background-color: #1a2234 !important;
    border-color: #2d3748 !important;
}
body.theme-dark .select2-container--default .select2-results__option--selected {
    background-color: #2d3748 !important;
    color: #f8fafc !important;
}
body.theme-dark .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
    background-color: #2d3748 !important;
    color: #ffffff !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if ($.fn.select2) {
        $('.select2-financial').select2({
            width: '100%',
            dropdownAutoWidth: true,
            dropdownParent: $('body')
        });
    }

    const typeRadios = document.querySelectorAll('input[name="transaction_type"]');
    const cardSection = document.getElementById('card_section');
    const passportSection = document.getElementById('passport_section');

    function toggleSections() {
        const checkedRadio = document.querySelector('input[name="transaction_type"]:checked');
        const type = checkedRadio ? checkedRadio.value : (document.querySelector('input[name="transaction_type"]') ? document.querySelector('input[name="transaction_type"]').value : 'both');

        if (cardSection && passportSection) {
            if (type === 'both') {
                cardSection.style.display = 'block';
                passportSection.style.display = 'block';
            } else if (type === 'card_only') {
                cardSection.style.display = 'block';
                passportSection.style.display = 'none';
            } else if (type === 'passport_only') {
                cardSection.style.display = 'none';
                passportSection.style.display = 'block';
            }
        }
    }

    typeRadios.forEach(radio => radio.addEventListener('change', toggleSections));
    toggleSections();

    const transactionTypeSelect = document.getElementById('transaction_type_id');
    const totalAmountInput = document.querySelector('input[name="total_amount"]');
    const costAmountInput = document.querySelector('input[name="cost_amount"]');
    const passportServiceInput = document.getElementById('passport_service_id');
    const fullNameInput = document.querySelector('input[name="full_name"]');
    const descriptionInput = document.querySelector('textarea[name="description"]');

    function updateAutoDescription() {
        if (!descriptionInput || !transactionTypeSelect) {
            return;
        }

        const fullName = (fullNameInput ? fullNameInput.value : '').trim();
        const selectedServiceNames = Array.from(transactionTypeSelect.selectedOptions || [])
            .map(opt => (opt.textContent || '').trim())
            .filter(Boolean);

        let description = 'معاملة';
        if (fullName !== '') {
            description += ' للأخ ' + fullName;
        }
        if (selectedServiceNames.length > 0) {
            description += ' تشمل: ' + selectedServiceNames.join('، ');
        }

        descriptionInput.value = description;
    }

    function updateTotalsFromTransactionType() {
        let totalSale = 0;
        let totalCost = 0;
        let serviceCurrencyId = null;

        const selectedOptions = transactionTypeSelect.selectedOptions;
        for (let i = 0; i < selectedOptions.length; i++) {
            const opt = selectedOptions[i];
            totalSale += parseFloat(opt.getAttribute('data-sale')) || 0;
            totalCost += parseFloat(opt.getAttribute('data-cost')) || 0;
            if (!serviceCurrencyId) {
                serviceCurrencyId = opt.getAttribute('data-currency');
            }
        }

        if (totalAmountInput) {
            totalAmountInput.value = totalSale.toFixed(2);
            totalAmountInput.setAttribute('data-original-price', totalSale);
            if (serviceCurrencyId) {
                totalAmountInput.setAttribute('data-service-currency-id', serviceCurrencyId);
            }
            totalAmountInput.dispatchEvent(new Event('input'));
        }

        if (costAmountInput) {
            costAmountInput.value = totalCost.toFixed(2);
            costAmountInput.setAttribute('data-original-cost', totalCost);
            if (serviceCurrencyId) {
                costAmountInput.setAttribute('data-cost-service-currency-id', serviceCurrencyId);
            }
            costAmountInput.dispatchEvent(new Event('input'));
        }

        if (typeof updateConvertedPrices === 'function') {
            updateConvertedPrices();
        }
    }

    $('#transaction_type_id').on('select2:select select2:unselect change', function() {
        updateTotalsFromTransactionType();
        fetchPricing();
        updateAutoDescription();
    });

    transactionTypeSelect.addEventListener('change', function() {
        updateTotalsFromTransactionType();
        fetchPricing();
        updateAutoDescription();
    });

    function fetchPricing() {
        const selectedOptions = $('#transaction_type_id').find(':selected');
        let totalSalePrice = 0;
        let totalCostPrice = 0;
        let serviceId = <?php echo json_encode($passportServiceId); ?>;
        let currencyId = null;

        selectedOptions.each(function() {
            const salePrice = parseFloat($(this).data('sale')) || 0;
            const costPrice = parseFloat($(this).data('cost')) || 0;
            const typeServiceId = $(this).data('service-id');

            totalSalePrice += salePrice;
            totalCostPrice += costPrice;

            if (!currencyId) {
                currencyId = $(this).data('currency');
            }

            if (typeServiceId && serviceId === <?php echo json_encode($passportServiceId); ?>) {
                serviceId = typeServiceId;
            }
        });

        if (!serviceId) {
            serviceId = <?php echo json_encode($passportServiceId); ?>;
        }
        if (passportServiceInput) {
            passportServiceInput.value = serviceId || <?php echo (int)$passportServiceId; ?>;
        }

        const $totalAmountInput = $('input[name="total_amount"]');
        const $costAmountInput = $('input[name="cost_amount"]');

        $totalAmountInput.val(totalSalePrice.toFixed(2))
            .attr('data-original-price', totalSalePrice);
        $costAmountInput.val(totalCostPrice.toFixed(2))
            .attr('data-original-cost', totalCostPrice);

        if (currencyId) {
            $totalAmountInput.attr('data-service-currency-id', currencyId);
            $costAmountInput.attr('data-cost-service-currency-id', currencyId);
            const saleCurrencySelect = $('select[name="sale_currency_id"]');
            if (saleCurrencySelect.length > 0) {
                saleCurrencySelect.val(currencyId).trigger('change');
            }
        }

        if (typeof updateConvertedPrices === 'function') {
            updateConvertedPrices();
        }

        if (!serviceId) return;

        $.ajax({
            url: 'ajax_get_service_accounts.php',
            data: {
                service_id: serviceId
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#service_revenue_account').val(res.revenue_account_name || 'لم يتم إعداد الحساب');
                    $('#service_cost_account').val(res.cost_account_name || 'لم يتم إعداد الحساب');
                    $('#service_profit_account').val(res.profit_account_name || 'لم يتم إعداد الحساب');
                    $('#service_revenue_account_id').val(res.revenue_account_id || '');
                    $('#service_cost_account_id').val(res.cost_account_id || '');
                    $('#service_profit_account_id').val(res.profit_account_id || '');
                }
            }
        });
    }

    $(document).on('change', 'select[name="account_id"]', fetchPricing);
    $(document).on('change', 'select[name="supplier_id"]', fetchPricing);
    $(document).on('change', 'select[name="sale_currency_id"]', function() {
        if (typeof updateConvertedPrices === 'function') {
            updateConvertedPrices();
        }
    });

    if (fullNameInput) {
        fullNameInput.addEventListener('input', updateAutoDescription);
        fullNameInput.addEventListener('change', updateAutoDescription);
    }

    setTimeout(function() {
        updateTotalsFromTransactionType();
        fetchPricing();
        updateAutoDescription();
    }, 500);

    // City Validation Logic
    const fromCitySelect = document.querySelector('select[name="from_city_id"]');
    const toCitySelect = document.querySelector('select[name="to_city_id"]');
    
    function validateCities() {
        if (fromCitySelect && toCitySelect) {
            const fromId = fromCitySelect.value;
            const toId = toCitySelect.value;
            if (fromId && toId && fromId === toId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'تحذير',
                    text: 'لا يمكن أن تكون المدينة المنطلق نفس المدينة المقصود!',
                    confirmButtonText: 'حسناً'
                });
            }
        }
    }
    
    if (fromCitySelect) {
        fromCitySelect.addEventListener('change', validateCities);
    }
    if (toCitySelect) {
        toCitySelect.addEventListener('change', validateCities);
    }
    
    // Travel Date Day Name Logic
    const travelDateInput = document.getElementById('travel_date');
    const travelDayName = document.getElementById('travel_day_name');

    function updateTravelDay() {
        if (travelDateInput.value) {
            const date = new Date(travelDateInput.value);
            const days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
            travelDayName.textContent = days[date.getDay()];
        } else {
            travelDayName.textContent = '---';
        }
    }

    if (travelDateInput) {
        travelDateInput.addEventListener('change', updateTravelDay);
        updateTravelDay();
    }

    setTimeout(function() {
        updateTotalsFromTransactionType();
        fetchPricing();
        updateAutoDescription();
    }, 600);
});
</script>

<?php require_once 'footer.php'; ?>
