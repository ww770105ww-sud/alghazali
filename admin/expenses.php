<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';

// التحقق من الصلاحيات هنا إذا لزم الأمر

// جلب فئات المصاريف (الحالة عبارة عن tinyint: 1 = نشط)
$expense_categories = $pdo->query("SELECT id, category_name_ar, account_id FROM expenses_categories WHERE status = 1 AND deleted_at IS NULL ORDER BY category_name_ar")->fetchAll();

// جلب العملات
$currencies = $pdo->query("SELECT id, currency_name, currency_code FROM currencies WHERE is_active = 1")->fetchAll();

// جلب حسابات المصروفات (الحسابات التي تستلم المبلغ - من شجرة الحسابات نوع expense)
$expense_accounts_stmt = $pdo->prepare("
    SELECT id, account_code, account_name_ar
    FROM unified_accounts
    WHERE account_status = 'active'
      AND deleted_at IS NULL
      AND account_type = 'expense'
      AND parent_id IS NOT NULL
    ORDER BY account_code
");
$expense_accounts_stmt->execute();
$expense_accounts = $expense_accounts_stmt->fetchAll();

// جلب الحسابات المحاسبية المتاحة للدفع منها (صناديق وبنوك من النظام الموحد)
// صناديق = account_code يبدأ بـ 11101 وله أب، بنوك = account_code يبدأ بـ 11102 وله أب
$payment_accounts_stmt = $pdo->query("
    SELECT id, account_name_ar, account_code, parent_id,
           CASE
               WHEN account_code LIKE '11101%' AND parent_id IS NOT NULL THEN 'box'
               WHEN account_code LIKE '11102%' AND parent_id IS NOT NULL THEN 'bank'
               ELSE 'other'
           END as derived_type
    FROM unified_accounts
    WHERE account_status = 'active'
      AND deleted_at IS NULL
      AND parent_id IS NOT NULL
      AND (account_code LIKE '11101%' OR account_code LIKE '11102%')
    ORDER BY account_code
");
$payment_accounts = $payment_accounts_stmt->fetchAll();

function resolve_expense_branch_id(PDO $pdo, $paid_from_account_id = null, $fallback_branch_id = null)
{
    $branch_id = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;

    if (!$branch_id && function_exists('get_current_user_pricing_context')) {
        $ctx = get_current_user_pricing_context($pdo);
        if (!empty($ctx['branch_id'])) {
            $branch_id = (int)$ctx['branch_id'];
        }
    }

    if (!$branch_id && !empty($paid_from_account_id)) {
        $stmt_branch = $pdo->prepare("SELECT branch_id FROM unified_accounts WHERE id = ? LIMIT 1");
        $stmt_branch->execute([(int)$paid_from_account_id]);
        $account_branch_id = $stmt_branch->fetchColumn();
        if (!empty($account_branch_id)) {
            $branch_id = (int)$account_branch_id;
        }
    }

    if (!$branch_id && !empty($fallback_branch_id)) {
        $branch_id = (int)$fallback_branch_id;
    }

    if (!$branch_id) {
        $stmt_single_branch = $pdo->query("SELECT id FROM branches WHERE COALESCE(is_active, 1) = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 2");
        $branch_rows = $stmt_single_branch->fetchAll(PDO::FETCH_COLUMN);
        if (count($branch_rows) === 1) {
            $branch_id = (int)$branch_rows[0];
        }
    }

    return $branch_id ?: null;
}


// إضافة مصروف جديد
if (isset($_POST['add_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $expense_date = $_POST['expense_date'];
        $category_id = $_POST['category_id'];
        $amount = $_POST['amount'];
        $currency_id = $_POST['currency_id'];
        $description = $_POST['description'];
        $notes = $_POST['notes'];
        $payment_method = $_POST['payment_method'];
        $paid_from_account_id = !empty($_POST['paid_from_account_id']) ? $_POST['paid_from_account_id'] : null;
        $expense_account_id = !empty($_POST['expense_account_id']) ? $_POST['expense_account_id'] : null;
        $created_by = (int)($_SESSION['admin_id'] ?? 0);
        $branch_id = resolve_expense_branch_id($pdo, $paid_from_account_id);

        $errors = [];

        if (!$created_by) {
            $errors[] = "تعذر تحديد المستخدم الحالي. أعد تسجيل الدخول ثم حاول مرة أخرى.";
        }

        // تحديد حساب المصروف المستلم للمبلغ: الأولوية للحقل الجديد، إن لم يُحدد أخذ حساب الفئة وإنشاؤه إن لزم
        if ($expense_account_id) {
            // تحقق من وجوده ونوعه expense
            $stmt_acc = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ? AND account_type = 'expense' AND account_status = 'active' AND deleted_at IS NULL");
            $stmt_acc->execute([$expense_account_id]);
            if (!$stmt_acc->fetchColumn()) {
                $errors[] = "حساب المصروف المحدد غير صالح.";
            }
            $expense_chart_account_id = (int)$expense_account_id;
        } else {
            // احتياطي: جلب حساب الفئة تلقائياً وإنشاؤه إن لم يكن
            $stmt_cat = $pdo->prepare("SELECT id, category_name_ar, account_id FROM expenses_categories WHERE id = ?");
            $stmt_cat->execute([$category_id]);
            $cat_row = $stmt_cat->fetch(PDO::FETCH_ASSOC);
            $expense_chart_account_id = $cat_row['account_id'] ?? null;

            if (!$expense_chart_account_id && $cat_row) {
                $parent_code = get_parent_account_code_by_entity('expense_category');
                $new_chart_account_id = create_sub_account($parent_code, "فئة مصروف: " . $cat_row['category_name_ar'], $cat_row['id'], 'expense_category');
                if ($new_chart_account_id) {
                    $stmt_link = $pdo->prepare("UPDATE expenses_categories SET account_id = ? WHERE id = ?");
                    $stmt_link->execute([$new_chart_account_id, $cat_row['id']]);
                    $expense_chart_account_id = $new_chart_account_id;
                }
            }
            if (!$expense_chart_account_id) {
                $errors[] = "يجب تحديد حساب المصروف المستلم للمبلغ.";
            }
        }

        if ($payment_method != 'check' && !$paid_from_account_id) {
            $errors[] = "يجب تحديد الحساب المدفوع منه (صندوق/بنك).";
        }

        if (!$branch_id) {
            $errors[] = "تعذر تحديد الفرع لهذا المصروف. اربط المستخدم بفرع أو اختر حساب دفع مرتبط بفرع.";
        }

        if (empty($errors)) {
            try {
                // --- التحقق من إغلاق الفترة المالية ---
                if (is_period_closed($pdo, $expense_date)) {
                    throw new Exception("تنبيه: لا يمكن تسجيل مصروف. التاريخ المحدد ($expense_date) يقع ضمن فترة مالية مغلقة.");
                }

                // 1. تسجيل المصروف كمسودة (بدون قيد محاسبي)
                $stmt = $pdo->prepare("INSERT INTO expenses (expense_date, category_id, expense_account_id, amount, currency_id, description, notes, payment_method, paid_from_account_id, created_by, branch_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
                $stmt->execute([$expense_date, $category_id, $expense_chart_account_id, $amount, $currency_id, $description, $notes, $payment_method, $paid_from_account_id, $created_by, $branch_id]);
                $expense_id = (int)$pdo->lastInsertId();

                // 2. لا يتم إنشاء قيد محاسبي للمسودة، سيتم عند الترحيل

                $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تمت إضافة المصروف بنجاح.'];
                echo "<script>location.href='expenses.php';</script>";
                exit();
            } catch (Exception $e) {
                $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
            }
        }
    }
}

// تحديث مصروف
if (isset($_POST['update_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $id = $_POST['id'];
        $expense_date = $_POST['expense_date'];
        $category_id = $_POST['category_id'];
        $amount = $_POST['amount'];
        $currency_id = $_POST['currency_id'];
        $description = $_POST['description'];
        $notes = $_POST['notes'];
        $payment_method = $_POST['payment_method'];
        $paid_from_account_id = !empty($_POST['paid_from_account_id']) ? $_POST['paid_from_account_id'] : null;
        $expense_account_id = !empty($_POST['expense_account_id']) ? $_POST['expense_account_id'] : null;

        $errors = [];

        // تحديد حساب المصروف المستلم للمبلغ: الأولوية للحقل المُدخل صراحةً
        if ($expense_account_id) {
            $stmt_acc = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ? AND account_type = 'expense' AND account_status = 'active' AND deleted_at IS NULL");
            $stmt_acc->execute([$expense_account_id]);
            if (!$stmt_acc->fetchColumn()) {
                $errors[] = "حساب المصروف المحدد غير صالح.";
            }
            $expense_chart_account_id = (int)$expense_account_id;
        } else {
            // احتياطي: حساب الفئة
            $stmt_cat = $pdo->prepare("SELECT id, category_name_ar, account_id FROM expenses_categories WHERE id = ?");
            $stmt_cat->execute([$category_id]);
            $cat_row = $stmt_cat->fetch(PDO::FETCH_ASSOC);
            $expense_chart_account_id = $cat_row['account_id'] ?? null;

            if (!$expense_chart_account_id && $cat_row) {
                $parent_code = get_parent_account_code_by_entity('expense_category');
                $new_chart_account_id = create_sub_account($parent_code, "فئة مصروف: " . $cat_row['category_name_ar'], $cat_row['id'], 'expense_category');
                if ($new_chart_account_id) {
                    $stmt_link = $pdo->prepare("UPDATE expenses_categories SET account_id = ? WHERE id = ?");
                    $stmt_link->execute([$new_chart_account_id, $cat_row['id']]);
                    $expense_chart_account_id = $new_chart_account_id;
                }
            }
            if (!$expense_chart_account_id) {
                $errors[] = "يجب تحديد حساب المصروف المستلم للمبلغ.";
            }
        }

        if ($payment_method != 'check' && !$paid_from_account_id) {
            $errors[] = "يجب تحديد الحساب المدفوع منه (صندوق/بنك).";
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // --- التحقق من إغلاق الفترة المالية ---
                if (is_period_closed($pdo, $expense_date)) {
                    throw new Exception("تنبيه: لا يمكن تعديل مصروف. التاريخ المحدد ($expense_date) يقع ضمن فترة مالية مغلقة.");
                }

                $old_expense_stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
                $old_expense_stmt->execute([$id]);
                $old_expense = $old_expense_stmt->fetch(PDO::FETCH_ASSOC);

                $branch_id = resolve_expense_branch_id($pdo, $paid_from_account_id, $old_expense['branch_id'] ?? null);
                if (!$branch_id) {
                    throw new Exception("تعذر تحديد الفرع لهذا المصروف.");
                }

                $stmt = $pdo->prepare("UPDATE expenses SET expense_date = ?, category_id = ?, expense_account_id = ?, amount = ?, currency_id = ?, description = ?, notes = ?, payment_method = ?, paid_from_account_id = ? WHERE id = ?");
                $stmt->execute([$expense_date, $category_id, $expense_chart_account_id, $amount, $currency_id, $description, $notes, $payment_method, $paid_from_account_id, $id]);

                $admin_id = (int)($_SESSION['admin_id'] ?? 0);
                $amount_f = (float)$amount;

                if ($paid_from_account_id && $expense_chart_account_id) {
                    if (!empty($old_expense['transaction_id'])) {
                        php_delete_financial_transaction_and_reverse($pdo, (int)$old_expense['transaction_id']);
                    }
                    $entry_desc = "تحديث مصروف: " . $description;
                    $tid = php_create_financial_entry(
                        $pdo,
                        $expense_date,
                        'journal',
                        'other',
                        0,
                        (int)$expense_chart_account_id,
                        (int)$paid_from_account_id,
                        $amount_f,
                        (int)$currency_id,
                        $entry_desc,
                        $admin_id,
                        $branch_id,
                        null,
                        null,
                        'expense',
                        (int)$id,
                        true
                    );
                    if (!$tid) {
                        throw new Exception("فشل إنشاء القيد المحاسبي عند التحديث.");
                    }
                    $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE id = ?")->execute([$tid, $id]);
                } elseif (!empty($old_expense['transaction_id'])) {
                    php_delete_financial_transaction_and_reverse($pdo, (int)$old_expense['transaction_id']);
                    $pdo->prepare("UPDATE expenses SET transaction_id = NULL WHERE id = ?")->execute([$id]);
                }

                $pdo->commit();
                $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم تحديث المصروف بنجاح.'];
                echo "<script>location.href='expenses.php';</script>";
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
            }
        }
    }
}

// ترحيل مصروف
if (isset($_POST['post_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $id = (int)($_POST['id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND status = 'draft' AND deleted_at IS NULL");
            $stmt->execute([$id]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$expense) {
                throw new Exception("المصروف غير موجود أو تم ترحيله بالفعل.");
            }

            // --- التحقق من إغلاق الفترة المالية ---
            if (is_period_closed($pdo, $expense['expense_date'])) {
                throw new Exception("تنبيه: لا يمكن ترحيل مصروف. التاريخ المحدد (" . $expense['expense_date'] . ") يقع ضمن فترة مالية مغلقة.");
            }

            // إنشاء قيد محاسبي
            if ($expense['paid_from_account_id'] && $expense['expense_account_id']) {
                $created_by = (int)($_SESSION['admin_id'] ?? 0);
                $amount_f = (float)$expense['amount'];
                $entry_desc = "ترحيل مصروف: " . $expense['description'];

                $tid = php_create_financial_entry(
                    $pdo,
                    $expense['expense_date'],
                    'journal',
                    'other',
                    0,
                    (int)$expense['expense_account_id'],
                    (int)$expense['paid_from_account_id'],
                    $amount_f,
                    (int)$expense['currency_id'],
                    $entry_desc,
                    $created_by,
                    (int)$expense['branch_id'],
                    null,
                    null,
                    'expense',
                    (int)$expense['id'],
                    false // not an update
                );

                if (!$tid) {
                    throw new Exception("فشل إنشاء القيد المحاسبي عند الترحيل.");
                }

                // تحديث حالة المصروف وربطه بالقيد
                $update_stmt = $pdo->prepare("UPDATE expenses SET status = 'posted', transaction_id = ? WHERE id = ?");
                $update_stmt->execute([$tid, $id]);
            } else {
                // إذا لم تكن هناك حسابات لإنشاء قيد، فقط نغير الحالة
                $update_stmt = $pdo->prepare("UPDATE expenses SET status = 'posted' WHERE id = ?");
                $update_stmt->execute([$id]);
            }

            $pdo->commit();
            $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم ترحيل المصروف بنجاح.'];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_message'] = ['type' => 'danger', 'body' => 'خطأ أثناء الترحيل: ' . $e->getMessage()];
        }

        // Redirect back to avoid resubmission
        echo "<script>location.href='expenses.php';</script>";
        exit();
    }
}

// حذف مصروف (أرشفة)
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $id = $_GET['delete'];
        try {
            $pdo->beginTransaction();

            // جلب المصروف لحذف قيده المالي
            $expense_to_delete_stmt = $pdo->prepare("SELECT transaction_id FROM expenses WHERE id = ?");
            $expense_to_delete_stmt->execute([$id]);
            $transaction_id_to_delete = $expense_to_delete_stmt->fetchColumn();

            if ($transaction_id_to_delete) {
                php_delete_financial_transaction_and_reverse($pdo, (int)$transaction_id_to_delete);
            }

            $stmt = $pdo->prepare("UPDATE expenses SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            $pdo->commit();
            $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم حذف المصروف بنجاح.'];
            echo "<script>location.href='expenses.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "حدث خطأ أثناء الحذف: " . $e->getMessage();
        }
    }
}

// جلب المصاريف
$expenses_stmt = $pdo->prepare("
    SELECT e.*, ec.category_name_ar, c.currency_code, coa.account_name_ar as paid_from_account_name, coa.account_code as paid_from_account_code, u.username
    FROM expenses e
    JOIN expenses_categories ec ON e.category_id = ec.id
    JOIN currencies c ON e.currency_id = c.id
    LEFT JOIN unified_accounts coa ON e.paid_from_account_id = coa.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.deleted_at IS NULL
    ORDER BY e.expense_date DESC, e.id DESC
");
$expenses_stmt->execute();
$expenses = $expenses_stmt->fetchAll();

$page_title = "إدارة المصاريف";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-money-bill-wave me-2"></i> إدارة المصاريف</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة مصروف جديد
        </button>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show rounded-4 shadow-sm border-0">
            <?php echo $_SESSION['flash_message']['body']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">التاريخ</th>
                            <th>الفئة</th>
                            <th>المبلغ</th>
                            <th>الوصف</th>
                            <th>الحالة</th>
                            <th>طريقة الدفع</th>
                            <th>الحساب المدفوع منه</th>
                            <th>بواسطة</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td class="px-4 py-3"><?php echo h($expense['expense_date']); ?></td>
                                <td><?php echo htmlspecialchars($expense['category_name_ar']); ?></td>
                                <td class="fw-bold text-danger"><?php echo number_format($expense['amount'], 2) . ' ' . h($expense['currency_code']); ?></td>
                                <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                <td>
                                    <?php if ($expense['status'] == 'draft'): ?>
                                        <span class="badge bg-warning text-dark">مسودة</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">مرحل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $payment_method_text = [
                                        'cash' => 'نقدي',
                                        'bank_transfer' => 'تحويل بنكي',
                                        'check' => 'شيك'
                                    ];
                                    echo $payment_method_text[$expense['payment_method']] ?? $expense['payment_method'];
                                    ?>
                                </td>
                                <td>
                                    <?php if ($expense['paid_from_account_name']): ?>
                                        <div class="small fw-bold text-primary"><?php echo htmlspecialchars($expense['paid_from_account_name']); ?></div>
                                        <div class="small text-muted"><?php echo h($expense['paid_from_account_code']); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">غير محدد</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($expense['username']); ?></td>
                                <td class="text-center">
                                    <?php if ($expense['status'] == 'draft'): ?>
                                        <form method="POST" action="expenses.php" style="display: inline;">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="id" value="<?php echo $expense['id']; ?>">
                                            <button type="submit" name="post_expense" class="btn btn-sm btn-success me-1">
                                                <i class="fas fa-check-circle"></i> ترحيل
                                            </button>
                                        </form>
                                        <button class="btn btn-sm btn-outline-primary me-1 edit-expense-btn" data-id="<?php echo $expense['id']; ?>">
                                            <i class="fas fa-edit"></i> تعديل
                                        </button>
                                        <a href="expenses.php?delete=<?php echo $expense['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من حذف هذا المصروف؟')">
                                            <i class="fas fa-trash"></i> حذف
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">تم الترحيل</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">لا توجد مصروفات مسجلة.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة مصروف -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> إضافة مصروف جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">التاريخ</label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">فئة المصروف</label>
                            <select name="category_id" id="add_category_id" class="form-select" required onchange="syncExpenseAccountFromCategory(this.value, 'add_expense_account_id')">
                                <option value="">اختر الفئة</option>
                                <?php foreach ($expense_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" data-account="<?php echo (int)$category['account_id']; ?>"><?php echo htmlspecialchars($category['category_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">حساب المصروف (المستلم للمبلغ)</label>
                            <select name="expense_account_id" id="add_expense_account_id" class="form-select" required>
                                <option value="">اختر حساب المصروف</option>
                                <?php foreach ($expense_accounts as $acc): ?>
                                    <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">المبلغ</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="أدخل المبلغ" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" class="form-select" required>
                                <option value="">اختر العملة</option>
                                <?php foreach ($currencies as $currency): ?>
                                    <option value="<?php echo $currency['id']; ?>"><?php echo h($currency['currency_name']) . ' (' . h($currency['currency_code']) . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">طريقة الدفع</label>
                            <select name="payment_method" class="form-select" required onchange="togglePaidFromAccount(this.value)">
                                <option value="">اختر طريقة الدفع</option>
                                <option value="cash">نقدي</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                                <option value="check">شيك</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="paid_from_account_div">
                            <label class="form-label fw-bold">الحساب المدفوع منه (صندوق/بنك)</label>
                            <select name="paid_from_account_id" id="paid_from_account_id" class="form-select">
                                <option value="">اختر حساب الدفع</option>
                                <?php foreach ($payment_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" data-type="<?php echo h($account['derived_type']); ?>"><?php echo h($account['account_code']) . ' - ' . h($account['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">الوصف</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="وصف موجز للمصروف"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="ملاحظات إضافية"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_expense" class="btn btn-primary px-4">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل مصروف (ديناميكي) -->
<div class="modal fade" id="editExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> تعديل مصروف</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="editExpenseModalContent">
                    <input type="hidden" name="id" id="edit_expense_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">التاريخ</label>
                            <input type="date" name="expense_date" id="edit_expense_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">فئة المصروف</label>
                            <select name="category_id" id="edit_expense_category_id" class="form-select" required onchange="syncExpenseAccountFromCategory(this.value, 'edit_expense_account_id')">
                                <option value="">اختر الفئة</option>
                                <?php foreach ($expense_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" data-account="<?php echo (int)$category['account_id']; ?>"><?php echo htmlspecialchars($category['category_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">حساب المصروف (المستلم للمبلغ)</label>
                            <select name="expense_account_id" id="edit_expense_account_id" class="form-select" required>
                                <option value="">اختر حساب المصروف</option>
                                <?php foreach ($expense_accounts as $acc): ?>
                                    <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">المبلغ</label>
                            <input type="number" step="0.01" name="amount" id="edit_expense_amount" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" id="edit_expense_currency_id" class="form-select" required>
                                <option value="">اختر العملة</option>
                                <?php foreach ($currencies as $currency): ?>
                                    <option value="<?php echo $currency['id']; ?>"><?php echo h($currency['currency_name']) . ' (' . h($currency['currency_code']) . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">طريقة الدفع</label>
                            <select name="payment_method" id="edit_expense_payment_method" class="form-select" required onchange="togglePaidFromAccount(this.value, 'edit')">
                                <option value="">اختر طريقة الدفع</option>
                                <option value="cash">نقدي</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                                <option value="check">شيك</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="edit_paid_from_account_div">
                            <label class="form-label fw-bold">الحساب المدفوع منه (صندوق/بنك)</label>
                            <select name="paid_from_account_id" id="edit_expense_paid_from_account_id" class="form-select">
                                <option value="">اختر حساب الدفع</option>
                                <?php foreach ($payment_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" data-type="<?php echo h($account['derived_type']); ?>"><?php echo h($account['account_code']) . ' - ' . h($account['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">الوصف</label>
                            <textarea name="description" id="edit_expense_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" id="edit_expense_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_expense" class="btn btn-primary px-4">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

<script>
    // حفظ الخيارات الأصلية للحسابات (الصناديق + البنوك) لاستخدامها في إعادة الفلترة
    var originalAddOptions = [];
    var originalEditOptions = [];

    document.addEventListener('DOMContentLoaded', function() {
        // حفظ نسخة من الخيارات الأصلية عند تحميل الصفحة
        var addSelect = document.getElementById('paid_from_account_id');
        var editSelect = document.getElementById('edit_expense_paid_from_account_id');
        if (addSelect) originalAddOptions = Array.from(addSelect.querySelectorAll('option'));
        if (editSelect) originalEditOptions = Array.from(editSelect.querySelectorAll('option'));
    });

    function filterPaymentAccounts(selectEl, originalOptions, accountType) {
        var currentValue = selectEl.value;
        selectEl.innerHTML = '';

        originalOptions.forEach(function(opt) {
            var optType = opt.getAttribute('data-type');
            if (opt.value === '' || optType === accountType) {
                var newOpt = document.createElement('option');
                newOpt.value = opt.value;
                newOpt.textContent = opt.textContent;
                if (opt.getAttribute('data-type')) {
                    newOpt.setAttribute('data-type', opt.getAttribute('data-type'));
                }
                if (opt.value === currentValue) {
                    newOpt.selected = true;
                }
                selectEl.appendChild(newOpt);
            }
        });

        // إذا كانت القيمة المختارة غير موجودة بعد الفلترة، أفرغها
        var stillExists = Array.from(selectEl.options).some(function(o) {
            return o.value === currentValue;
        });
        if (!stillExists) {
            selectEl.value = '';
        }
    }

    function togglePaidFromAccount(paymentMethod, mode = 'add') {
        const divId = (mode === 'add') ? 'paid_from_account_div' : 'edit_paid_from_account_div';
        const selectId = (mode === 'add') ? 'paid_from_account_id' : 'edit_expense_paid_from_account_id';
        const paidFromAccountDiv = document.getElementById(divId);
        const paidFromAccountSelect = document.getElementById(selectId);
        const originalOpts = (mode === 'add') ? originalAddOptions : originalEditOptions;

        if (paymentMethod === 'cash') {
            paidFromAccountDiv.style.display = 'block';
            paidFromAccountSelect.setAttribute('required', 'required');
            // فلترة الخيارات لإظهار الصناديق فقط
            filterPaymentAccounts(paidFromAccountSelect, originalOpts, 'box');
        } else if (paymentMethod === 'bank_transfer') {
            paidFromAccountDiv.style.display = 'block';
            paidFromAccountSelect.setAttribute('required', 'required');
            // فلترة الخيارات لإظهار البنوك فقط
            filterPaymentAccounts(paidFromAccountSelect, originalOpts, 'bank');
        } else {
            paidFromAccountDiv.style.display = 'none';
            paidFromAccountSelect.removeAttribute('required');
            paidFromAccountSelect.value = '';
        }
    }

    // مزامنة اختيار الفئة مع تعيين حساب المصروف تلقائياً إن كان للفئة حساب مرتبط
    function syncExpenseAccountFromCategory(categoryId, targetSelectId) {
        var targetSelect = document.getElementById(targetSelectId);
        if (!targetSelect) return;

        if (!categoryId) {
            targetSelect.value = '';
            return;
        }

        var categorySelect = targetSelectId.indexOf('edit_') === 0 ?
            document.getElementById('edit_expense_category_id') :
            document.getElementById('add_category_id');

        var selectedOpt = categorySelect.querySelector('option[value="' + categoryId + '"]');
        if (selectedOpt) {
            var accountId = selectedOpt.getAttribute('data-account');
            if (accountId && accountId !== '0') {
                var accountExists = targetSelect.querySelector('option[value="' + accountId + '"]');
                if (accountExists) {
                    targetSelect.value = accountId;
                    return;
                }
            }
        }
        // إن لم يكن هناك حساب مرتبط بالفئة، أبقِ القيمة الحالية للمستخدم يختار يدوياً
    }

    $(document).ready(function() {
        // Event listener for add modal payment method change
        $('select[name="payment_method"]').on('change', function() {
            togglePaidFromAccount(this.value, 'add');
        });

        // Event listener for edit modal payment method change
        $('#edit_expense_payment_method').on('change', function() {
            togglePaidFromAccount(this.value, 'edit');
        });

        $('.edit-expense-btn').on('click', function() {
            var expenseId = $(this).data('id');
            $.ajax({
                url: 'ajax_get_expense.php',
                type: 'GET',
                data: {
                    id: expenseId
                },
                dataType: 'json',
                success: function(expense) {
                    if (expense) {
                        $('#edit_expense_id').val(expense.id);
                        $('#edit_expense_date').val(expense.expense_date);
                        $('#edit_expense_category_id').val(expense.category_id);
                        $('#edit_expense_amount').val(expense.amount);
                        $('#edit_expense_currency_id').val(expense.currency_id);
                        $('#edit_expense_description').val(expense.description);
                        $('#edit_expense_notes').val(expense.notes);
                        $('#edit_expense_payment_method').val(expense.payment_method);
                        $('#edit_expense_paid_from_account_id').val(expense.paid_from_account_id);

                        // تعيين حساب المصروف إن وجد (الأولوية للعمود الجديد)
                        var targetAccountId = expense.expense_account_id || null;
                        if (targetAccountId) {
                            $('#edit_expense_account_id').val(targetAccountId);
                        } else {
                            // احتياطي: مزامنة من الفئة
                            syncExpenseAccountFromCategory(expense.category_id, 'edit_expense_account_id');
                        }

                        togglePaidFromAccount(expense.payment_method, 'edit');
                        // بعد الفلترة، أعِد تعيين القيمة المختارة للتأكد
                        setTimeout(function() {
                            $('#edit_expense_paid_from_account_id').val(expense.paid_from_account_id);
                        }, 10);

                        $('#editExpenseModal').modal('show');
                    } else {
                        alert('المصروف غير موجود.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error(xhr.responseText);
                    alert('حدث خطأ أثناء جلب بيانات المصروف.');
                }
            });
        });
    });
</script>
