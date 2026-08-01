<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!$is_admin && !has_permission('view_service_prices')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// تحديث تلقائي للجدول عند الحاجة
try {
    $check_customer = $pdo->query("SHOW COLUMNS FROM `service_prices` LIKE 'customer_id'")->fetch();
    if (!$check_customer) {
        $pdo->exec("ALTER TABLE service_prices ADD COLUMN customer_id INT(11) DEFAULT NULL AFTER agent_id");
    }
    
    $check_supplier = $pdo->query("SHOW COLUMNS FROM `service_prices` LIKE 'supplier_id'")->fetch();
    if (!$check_supplier) {
        $pdo->exec("ALTER TABLE service_prices ADD COLUMN supplier_id INT(11) DEFAULT NULL AFTER customer_id");
    }
} catch (PDOException $e) {
    // تجاهل الأخطاء
}

// إضافة سعر خدمة جديد
if (isset($_POST['add_price'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='service_prices.php';</script>");
    }
    $service_id = $_POST['service_id'];
    $target_type = $_POST['target_type'];
    
    $branch_id = ($target_type === 'branch') ? $_POST['branch_id'] : null;
    $agent_id = ($target_type === 'agent') ? $_POST['agent_id'] : null;
    $customer_id = ($target_type === 'customer') ? $_POST['customer_id'] : null;
    $supplier_id = ($target_type === 'supplier') ? $_POST['supplier_id'] : null;
    
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $default_sale_price = floatval($_POST['default_sale_price'] ?? 0);
    $currency_id = $_POST['currency_id'];
    $status = $_POST['status'];

    try {
        // التحقق من وجود سعر افتراضي مسبق لنفس الخدمة والعملة
        if ($target_type === 'global') {
            $check = $pdo->prepare("SELECT id FROM service_prices WHERE service_id = ? AND currency_id = ? AND branch_id IS NULL AND agent_id IS NULL AND customer_id IS NULL AND supplier_id IS NULL");
            $check->execute([$service_id, $currency_id]);
            if ($check->fetch()) {
                throw new Exception("يوجد بالفعل سعر افتراضي عام مضاف لهذه الخدمة بنفس العملة.");
            }
        }

        $branch_price = $purchase_price;
        $agent_price = $purchase_price;

        $stmt = $pdo->prepare("INSERT INTO service_prices (service_id, branch_id, agent_id, customer_id, supplier_id, branch_price, agent_price, default_sale_price, currency_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$service_id, $branch_id, $agent_id, $customer_id, $supplier_id, $branch_price, $agent_price, $default_sale_price, $currency_id, $status]);
        echo "<script>location.href='service_prices.php?success=1';</script>";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// تحديث سعر خدمة
if (isset($_POST['update_price'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='service_prices.php';</script>");
    }
    $id = $_POST['id'];
    $service_id = $_POST['service_id'];
    $target_type = $_POST['target_type'];

    $branch_id = ($target_type === 'branch') ? $_POST['branch_id'] : null;
    $agent_id = ($target_type === 'agent') ? $_POST['agent_id'] : null;
    $customer_id = ($target_type === 'customer') ? $_POST['customer_id'] : null;
    $supplier_id = ($target_type === 'supplier') ? $_POST['supplier_id'] : null;

    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $default_sale_price = floatval($_POST['default_sale_price'] ?? 0);
    $currency_id = $_POST['currency_id'];
    $status = $_POST['status'];

    try {
        // التحقق من التعارض عند التحديث لعام
        if ($target_type === 'global') {
            $check = $pdo->prepare("SELECT id FROM service_prices WHERE service_id = ? AND currency_id = ? AND branch_id IS NULL AND agent_id IS NULL AND customer_id IS NULL AND supplier_id IS NULL AND id != ?");
            $check->execute([$service_id, $currency_id, $id]);
            if ($check->fetch()) {
                throw new Exception("يوجد بالفعل سعر افتراضي عام آخر مضاف لهذه الخدمة بنفس العملة.");
            }
        }

        $branch_price = $purchase_price;
        $agent_price = $purchase_price;

        $stmt = $pdo->prepare("UPDATE service_prices SET service_id = ?, branch_id = ?, agent_id = ?, customer_id = ?, supplier_id = ?, branch_price = ?, agent_price = ?, default_sale_price = ?, currency_id = ?, status = ? WHERE id = ?");
        $stmt->execute([$service_id, $branch_id, $agent_id, $customer_id, $supplier_id, $branch_price, $agent_price, $default_sale_price, $currency_id, $status, $id]);
        echo "<script>location.href='service_prices.php?success=2';</script>";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// حذف سعر خدمة عبر POST + CSRF
if (isset($_POST['delete_price'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='service_prices.php';</script>");
    }
    $id = (int)$_POST['delete_price'];
    try {
        $pdo->prepare("DELETE FROM service_prices WHERE id = ?")->execute([$id]);
        echo "<script>location.href='service_prices.php?success=3';</script>";
    } catch (PDOException $e) {
        $error = "حدث خطأ أثناء الحذف";
    }
}

$prices = $pdo->query("
    SELECT sp.*, s.service_name, b.branch_name, a.agent_name, cust.full_name as customer_name, sup.supplier_name, c.currency_name 
    FROM service_prices sp 
    JOIN services s ON sp.service_id = s.id 
    LEFT JOIN branches b ON sp.branch_id = b.id 
    LEFT JOIN agents a ON sp.agent_id = a.id 
    LEFT JOIN customers cust ON sp.customer_id = cust.id
    LEFT JOIN suppliers sup ON sp.supplier_id = sup.id
    LEFT JOIN currencies c ON sp.currency_id = c.id 
    ORDER BY s.service_name ASC, 
             (sp.branch_id IS NULL AND sp.agent_id IS NULL AND sp.customer_id IS NULL AND sp.supplier_id IS NULL) DESC,
             sp.id DESC
")->fetchAll();

$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active'")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents WHERE deleted_at IS NULL")->fetchAll();
$customers = $pdo->query("SELECT id, full_name as account_name FROM customers WHERE deleted_at IS NULL AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, supplier_name as account_name FROM suppliers WHERE deleted_at IS NULL AND status = 'active' ORDER BY supplier_name ASC")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name FROM currencies")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary mb-0">إدارة أسعار الخدمات</h3>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addPriceModal">
            <i class="fas fa-plus-circle me-2"></i> إضافة سعر جديد
        </button>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2"></i>
            <?php
            if ($_GET['success'] == 1) echo "تمت إضافة السعر بنجاح.";
            if ($_GET['success'] == 2) echo "تم تحديث السعر بنجاح.";
            if ($_GET['success'] == 3) echo "تم حذف السعر بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">الخدمة</th>
                            <th>الفرع / الوكيل</th>
                            <th>العملة</th>
                            <th>سعر الشراء</th>
                            <th>سعر البيع</th>
                            <th>الحالة</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prices as $price): 
                            $is_global = (!$price['branch_id'] && !$price['agent_id'] && !$price['customer_id'] && !$price['supplier_id']);
                        ?>
                            <tr class="<?php echo $is_global ? 'table-primary bg-opacity-10 fw-bold' : ''; ?>">
                                <td class="px-4 py-3">
                                    <?php if ($is_global): ?>
                                        <i class="fas fa-star text-warning me-1" title="سعر افتراضي عام"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($price['service_name']); ?>
                                </td>
                                <td>
                                    <?php if (!empty($price['agent_id'])): ?>
                                        <span class="badge bg-info-subtle text-info rounded-pill px-3">وكيل: <?php echo htmlspecialchars($price['agent_name']); ?></span>
                                    <?php elseif (!empty($price['branch_id'])): ?>
                                        <span class="badge bg-primary-subtle text-primary rounded-pill px-3">فرع: <?php echo htmlspecialchars($price['branch_name']); ?></span>
                                    <?php elseif (!empty($price['customer_id'])): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-3">عميل: <?php echo htmlspecialchars($price['customer_name']); ?></span>
                                    <?php elseif (!empty($price['supplier_id'])): ?>
                                        <span class="badge bg-warning-subtle text-warning rounded-pill px-3">مورد: <?php echo htmlspecialchars($price['supplier_name']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-primary text-white rounded-pill px-3 shadow-sm"><i class="fas fa-globe me-1"></i> السعر الافتراضي العام</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?php echo htmlspecialchars($price['currency_name']); ?></td>
                                <td class="text-primary fw-bold"><?php echo number_format($price['agent_price'] ?: $price['branch_price'], 2); ?></td>
                                <td class="text-success fw-bold"><?php echo number_format($price['default_sale_price'], 2); ?></td>
                                <td>
                                    <?php if ($price['status'] == 'active'): ?>
                                        <span class="badge bg-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editPriceModal<?php echo $price['id']; ?>">
                                        <i class="fas fa-edit"></i> تعديل
                                    </button>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا السعر؟')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_price" value="<?php echo $price['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i> حذف
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php foreach ($prices as $price): ?>
    <!-- Modal تعديل السعر -->
    <div class="modal fade" id="editPriceModal<?php echo $price['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <div class="modal-header bg-primary text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل سعر الخدمة</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="id" value="<?php echo $price['id']; ?>">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">الخدمة</label>
                            <select name="service_id" class="form-select rounded-pill" required>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo $service['id']; ?>" <?php echo $price['service_id'] == $service['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($service['service_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">نوع التخصيص</label>
                            <select name="target_type" class="form-select rounded-pill target-type-select" onchange="toggleTargetFields(this)">
                                <option value="global" <?php echo (!$price['branch_id'] && !$price['agent_id'] && !$price['customer_id'] && !$price['supplier_id']) ? 'selected' : ''; ?>>عام (للجميع)</option>
                                <option value="branch" <?php echo ($price['branch_id']) ? 'selected' : ''; ?>>مخصص لفرع محدد</option>
                                <option value="agent" <?php echo ($price['agent_id']) ? 'selected' : ''; ?>>مخصص لوكيل محدد</option>
                                <option value="customer" <?php echo ($price['customer_id']) ? 'selected' : ''; ?>>مخصص لعميل محدد</option>
                                <option value="supplier" <?php echo ($price['supplier_id']) ? 'selected' : ''; ?>>مخصص لمورد محدد</option>
                            </select>
                        </div>

                        <div class="mb-4 branch-field <?php echo ($price['branch_id']) ? '' : 'd-none'; ?>">
                            <label class="form-label fw-bold text-primary"><i class="fas fa-building me-1"></i> اختر الفرع المستهدف</label>
                            <select name="branch_id" class="form-select rounded-pill">
                                <option value="">-- اختر الفرع --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" <?php echo $price['branch_id'] == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4 agent-field <?php echo ($price['agent_id']) ? '' : 'd-none'; ?>">
                            <label class="form-label fw-bold text-info"><i class="fas fa-user-tie me-1"></i> اختر الوكيل المستهدف</label>
                            <select name="agent_id" class="form-select rounded-pill">
                                <option value="">-- اختر الوكيل --</option>
                                <?php foreach ($agents as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" <?php echo $price['agent_id'] == $a['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['agent_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4 customer-field <?php echo ($price['customer_id']) ? '' : 'd-none'; ?>">
                            <label class="form-label fw-bold text-success"><i class="fas fa-user me-1"></i> اختر العميل المستهدف</label>
                            <select name="customer_id" class="form-select rounded-pill">
                                <option value="">-- اختر العميل --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $price['customer_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4 supplier-field <?php echo ($price['supplier_id']) ? '' : 'd-none'; ?>">
                            <label class="form-label fw-bold text-warning"><i class="fas fa-truck me-1"></i> اختر المورد المستهدف</label>
                            <select name="supplier_id" class="form-select rounded-pill">
                                <option value="">-- اختر المورد --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $price['supplier_id'] == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">العملة</label>
                                <select name="currency_id" class="form-select rounded-pill" required>
                                    <?php foreach ($currencies as $curr): ?>
                                        <option value="<?php echo $curr['id']; ?>" <?php echo $price['currency_id'] == $curr['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($curr['currency_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold text-primary">سعر الشراء</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-pill"><i class="fas fa-shopping-cart text-primary"></i></span>
                                    <input type="number" step="0.01" name="purchase_price" class="form-control border-start-0 rounded-end-pill" value="<?php echo $price['agent_price'] ?: $price['branch_price']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold text-success">سعر البيع</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-pill"><i class="fas fa-tag text-success"></i></span>
                                    <input type="number" step="0.01" name="default_sale_price" class="form-control border-start-0 rounded-end-pill" value="<?php echo $price['default_sale_price']; ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" class="form-select rounded-pill">
                                <option value="active" <?php echo $price['status'] == 'active' ? 'selected' : ''; ?>>نشط</option>
                                <option value="inactive" <?php echo $price['status'] == 'inactive' ? 'selected' : ''; ?>>معطل</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_price" class="btn btn-primary rounded-pill px-4">حفظ التغييرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Modal إضافة سعر -->
<div class="modal fade" id="addPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة سعر خدمة جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-bold">الخدمة</label>
                        <select name="service_id" class="form-select rounded-pill" required>
                            <option value="">-- اختر الخدمة --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?php echo $service['id']; ?>"><?php echo htmlspecialchars($service['service_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">نوع التخصيص</label>
                        <select name="target_type" class="form-select rounded-pill target-type-select shadow-sm border-2" onchange="toggleTargetFields(this)">
                            <option value="global" selected>عام (السعر الافتراضي للجميع)</option>
                            <option value="branch">مخصص لفرع محدد</option>
                            <option value="agent">مخصص لوكيل محدد</option>
                            <option value="customer">مخصص لعميل محدد</option>
                            <option value="supplier">مخصص لمورد محدد</option>
                        </select>
                        <div class="form-text mt-2 small text-muted"><i class="fas fa-info-circle me-1"></i> السعر العام هو السعر الذي سيتم استخدامه لجميع العمليات ما لم يتم تحديد سعر مخصص لجهة معينة.</div>
                    </div>

                    <div class="mb-4 branch-field d-none">
                        <label class="form-label fw-bold text-primary"><i class="fas fa-building me-1"></i> اختر الفرع المستهدف</label>
                        <select name="branch_id" class="form-select rounded-pill">
                            <option value="">-- اختر الفرع --</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4 agent-field d-none">
                        <label class="form-label fw-bold text-info"><i class="fas fa-user-tie me-1"></i> اختر الوكيل المستهدف</label>
                        <select name="agent_id" class="form-select rounded-pill">
                            <option value="">-- اختر الوكيل --</option>
                            <?php foreach ($agents as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['agent_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4 customer-field d-none">
                        <label class="form-label fw-bold text-success"><i class="fas fa-user me-1"></i> اختر العميل المستهدف</label>
                        <select name="customer_id" class="form-select rounded-pill">
                            <option value="">-- اختر العميل --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['account_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4 supplier-field d-none">
                        <label class="form-label fw-bold text-warning"><i class="fas fa-truck me-1"></i> اختر المورد المستهدف</label>
                        <select name="supplier_id" class="form-select rounded-pill">
                            <option value="">-- اختر المورد --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['account_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" class="form-select rounded-pill" required>
                                <option value="">-- اختر --</option>
                                <?php foreach ($currencies as $curr): ?>
                                    <option value="<?php echo $curr['id']; ?>"><?php echo htmlspecialchars($curr['currency_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-primary">سعر الشراء</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 rounded-start-pill"><i class="fas fa-shopping-cart text-primary"></i></span>
                                <input type="number" step="0.01" name="purchase_price" class="form-control border-start-0 rounded-end-pill" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-success">سعر البيع</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 rounded-start-pill"><i class="fas fa-tag text-success"></i></span>
                                <input type="number" step="0.01" name="default_sale_price" class="form-control border-start-0 rounded-end-pill" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" class="form-select rounded-pill">
                                <option value="active">نشط</option>
                                <option value="inactive">معطل</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_price" class="btn btn-primary rounded-pill px-4">إضافة السعر</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleTargetFields(select) {
    const modal = select.closest('.modal-body');
    const branchField = modal.querySelector('.branch-field');
    const agentField = modal.querySelector('.agent-field');
    const customerField = modal.querySelector('.customer-field');
    const supplierField = modal.querySelector('.supplier-field');
    
    const branchSelect = branchField.querySelector('select');
    const agentSelect = agentField.querySelector('select');
    const customerSelect = customerField.querySelector('select');
    const supplierSelect = supplierField.querySelector('select');

    // Hide all
    branchField.classList.add('d-none');
    agentField.classList.add('d-none');
    customerField.classList.add('d-none');
    supplierField.classList.add('d-none');
    
    // Clear all
    branchSelect.value = '';
    agentSelect.value = '';
    customerSelect.value = '';
    supplierSelect.value = '';

    if (select.value === 'branch') {
        branchField.classList.remove('d-none');
    } else if (select.value === 'agent') {
        agentField.classList.remove('d-none');
    } else if (select.value === 'customer') {
        customerField.classList.remove('d-none');
    } else if (select.value === 'supplier') {
        supplierField.classList.remove('d-none');
    }
}
</script>

<?php require_once 'footer.php'; ?>
