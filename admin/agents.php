<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/CurrencyExchange.php';

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

// إضافة وكيل جديد
if (isset($_POST['add_agent_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='agents.php';</script>");
    }
    $account_name = $_POST['account_name'];
    $branch_id = $_POST['branch_id'] ?: null;
    $opening_balance = $_POST['opening_balance'] ?? 0;
    $currency_id = $_POST['currency_id'] ?? 1;
    $status = $_POST['status'] == 'active' ? 'active' : $_POST['status'];

    try {
        $pdo->beginTransaction();

        // 1. العثور على الحساب الرئيسي للوكلاء (11203)
        $parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11203'");
        $parent_stmt->execute();
        $parent_id = $parent_stmt->fetchColumn();
        
        if (!$parent_id) throw new Exception("الحساب الرئيسي للوكلاء (11203) غير موجود.");

        // التحقق من عدم تكرار الاسم تحت نفس الأب
        $stmt_check_name = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE account_name_ar = ? AND parent_id = ?");
        $stmt_check_name->execute([$account_name, $parent_id]);
        if ($stmt_check_name->fetchColumn() > 0) {
            throw new Exception("اسم الوكيل موجود بالفعل.");
        }

        // 2. توليد الكود الجديد (11203001, 11203002, ...)
        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ? AND account_code LIKE '11203%'");
        $stmt_last->execute([$parent_id]);
        $last_code = $stmt_last->fetchColumn();
        
        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = "11203001";
        }

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, branch_id, account_status) VALUES (?, ?, 'وكيل', 'debit', ?, ?, ?)");
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

        $pdo->commit();
        echo "<script>location.href='agents.php?success=1';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
    }
}

// تحديث وكيل
if (isset($_POST['update_agent_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='agents.php';</script>");
    }
    $id = $_POST['id'];
    $account_name = $_POST['account_name'];
    $new_status = $_POST['status'];
    $branch_id = $_POST['branch_id'] ?: null;
    
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
        
        $pdo->commit();
        echo "<script>location.href='agents.php?success=2';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
    }
}

// تحويل إلى خامل عبر POST + CSRF
if (isset($_POST['deactivate_account'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='agents.php';</script>");
    }
    $id = (int)$_POST['deactivate_account'];
    try {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);
        echo "<script>location.href='agents.php?success=3';</script>";
        exit();
    } catch (Exception $e) {
        $error = "حدث خطأ أثناء التحويل إلى خامل: " . $e->getMessage();
    }
}

// حذف نهائي عبر POST + CSRF
if (isset($_POST['delete_account_permanent'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='agents.php';</script>");
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

        // حذف الحساب من شجرة الحسابات الموحدة
        $stmt = $pdo->prepare("DELETE FROM unified_accounts WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        echo "<script>location.href='agents.php?success=4';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الحذف النهائي: " . $e->getMessage();
    }
}

// جلب معرف الأب للوكلاء (11203)
$parent_stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11203'");
$parent_stmt->execute();
$agents_parent_id = $parent_stmt->fetchColumn();

// جلب الوكلاء من النظام الموحد
$where = "WHERE coa.parent_id = ? AND (coa.account_status = 'active' OR coa.account_status = 'dormant')";
$params = [$agents_parent_id];
if (!empty($_GET['q'])) {
    $where .= " AND (coa.account_name_ar LIKE ? OR coa.account_code LIKE ?)";
    $q = "%" . $_GET['q'] . "%";
    $params[] = $q; $params[] = $q;
}

$agents_stmt = $pdo->prepare("
    SELECT coa.*, p.account_name_ar as parent_name, b.branch_name
    FROM unified_accounts coa
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id
    LEFT JOIN branches b ON coa.branch_id = b.id
    $where
    ORDER BY coa.account_code ASC
");
$agents_stmt->execute($params);
$agents = $agents_stmt->fetchAll();

// جلب الأرصدة لكل الوكلاء دفعة واحدة
$agent_ids = array_column($agents, 'id');
$balances = [];
if (!empty($agent_ids)) {
    $placeholders = implode(',', array_fill(0, count($agent_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT
            jl.account_id,
            jl.currency_id,
            c.currency_name,
            c.currency_symbol,
            c.currency_code,
            SUM(jl.debit - jl.credit) AS net_balance,
            SUM((jl.debit - jl.credit) * COALESCE(c.exchange_rate, 1)) AS net_balance_base
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        LEFT JOIN currencies c ON jl.currency_id = c.id
        WHERE ft.status = 'posted' AND jl.account_id IN ($placeholders)
        GROUP BY jl.account_id, jl.currency_id, c.currency_name, c.currency_symbol, c.currency_code
        ORDER BY jl.account_id ASC, c.currency_name ASC
    ");
    $bal_stmt->execute($agent_ids);
    while ($row = $bal_stmt->fetch()) {
        $balances[$row['account_id']][] = $row;
    }
}

$total_balance = 0;
$currency_name = '';
if (!empty($agents)) {
    foreach ($agents as $a) {
        if (isset($balances[$a['id']])) {
            foreach ($balances[$a['id']] as $bal) {
                $total_balance += (float)$bal['net_balance_base'];
                if (!$currency_name) $currency_name = $bal['currency_name'];
            }
        }
    }
}

$currencies = $pdo->query("SELECT id, currency_name, is_default FROM currencies WHERE is_active = 1")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();

$page_title = "إدارة الوكلاء";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-user-tie me-2 text-primary"></i> إدارة الوكلاء</h3>
            <div class="d-flex align-items-center">
                <p class="text-muted small mb-0 me-3">إدارة وتعديل حسابات الوكلاء في شجرة الحسابات</p>
                <div class="ms-2 px-3 py-1 bg-white border rounded-pill shadow-sm small">
                    <?php echo get_total_balance_status($total_balance, 'asset', $currency_name); ?>
                </div>
            </div>
        </div>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addAgentModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة وكيل جديد
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php
            if ($_GET['success'] == 1) echo "تمت إضافة الوكيل بنجاح.";
            if ($_GET['success'] == 2) echo "تم تحديث بيانات الوكيل بنجاح.";
            if ($_GET['success'] == 3) echo "تم تحويل الوكيل إلى خامل بنجاح.";
            if ($_GET['success'] == 4) echo "تم حذف الوكيل نهائيًا بنجاح.";
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
                        <input type="text" id="agentSearch" class="form-control bg-light border-0" placeholder="بحث سريع باسم أو كود الوكيل...">
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
                <table class="table table-hover align-middle mb-0 text-center" id="agentsTable">
                    <thead class="bg-light text-secondary small text-uppercase fw-bold">
                        <tr>
                            <th class="px-4 py-3">كود الحساب</th>
                            <th>اسم الوكيل</th>
                            <th>الفرع</th>
                            <th>الرصيد الحالي</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agents as $agent): ?>
                        <tr>
                            <td class="px-4">
                                <code class="text-primary fw-bold"><?php echo $agent['account_code']; ?></code>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($agent['account_name_ar']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-normal">
                                    <i class="fas fa-building me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($agent['branch_name'] ?? 'عام'); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if (isset($balances[$agent['id']])) {
                                    foreach ($balances[$agent['id']] as $bal) {
                                        echo '<div class="mb-1 small">' . format_account_balance($bal['net_balance'], $agent['normal_balance'], $bal['currency_name']) . '</div>';
                                    }
                                } else {
                                    echo '<span class="text-muted small">0.00</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo get_account_status_label($agent['account_status']); ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="account_statement.php?id=<?php echo $agent['id']; ?>" class="btn btn-sm btn-light border-0" title="كشف حساب">
                                        <i class="fas fa-file-invoice-dollar text-primary"></i>
                                    </a>
                                    <button class="btn btn-sm btn-light border-0 edit-agent" 
                                            data-id="<?php echo $agent['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($agent['account_name_ar']); ?>"
                                            data-branch="<?php echo $agent['branch_id']; ?>"
                                            data-status="<?php echo $agent['account_status']; ?>"
                                            title="تعديل"><i class="fas fa-edit text-warning"></i></button>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من تحويل هذا الوكيل إلى خامل؟')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="deactivate_account" value="<?php echo $agent['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border-0" title="تحويل إلى خامل"><i class="fas fa-pause text-secondary"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الوكيل نهائيًا؟ هذا الإجراء لا يمكن التراجع عنه!')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_account_permanent" value="<?php echo $agent['id']; ?>">
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

<!-- Modal إضافة وكيل -->
<div class="modal fade" id="addAgentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة وكيل جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">اسم الوكيل <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" class="form-control rounded-3" placeholder="مثلاً: وكيل الأحمد" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الفرع المربوط به <span class="text-danger">*</span></label>
                            <select name="branch_id" class="form-select rounded-3" required>
                                <option value="">-- اختر الفرع --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
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
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_agent_account" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ الوكيل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل وكيل -->
<div class="modal fade" id="editAgentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-warning text-dark border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات الوكيل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">اسم الوكيل <span class="text-danger">*</span></label>
                            <input type="text" name="account_name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold small">الفرع المربوط به</label>
                            <select name="branch_id" id="edit_branch" class="form-select rounded-3">
                                <option value="">-- عام (بدون فرع) --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
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
                <div class="modal-footer border-0 p-4 bg-light shadow-sm">
                    <button type="button" class="btn btn-white rounded-pill px-4 border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_agent_account" class="btn btn-warning rounded-pill px-5 fw-bold shadow">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.edit-agent').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var branch = $(this).data('branch');
        var status = $(this).data('status');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_branch').val(branch);
        $('#edit_status').val(status);
        
        $('#editAgentModal').modal('show');
    });

    // بحث ديناميكي في الجدول
    $("#agentSearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#agentsTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});
</script>

<?php require_once 'footer.php'; ?>
