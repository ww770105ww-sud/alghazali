<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';

// التحقق من الصلاحية
if (!has_permission('users_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// جلب الحسابات المحاسبية المتاحة للمستخدمين
$available_accounts = get_available_accounts_for_entity('user');

// جلب الأدوار والفروع والوكلاء والموظفين للنماذج
$roles_list = $pdo->query("SELECT id, display_name, description FROM roles ORDER BY id ASC")->fetchAll();
$branches_list = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();
$agents_list = $pdo->query("SELECT id, agent_name FROM agents WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();
$employees_list = $pdo->query("SELECT id, full_name FROM employees WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();

// جلب الحسابات المالية (الصناديق والبنوك) للمودال من النظام الموحد
$cash_bank_accounts = $pdo->query("SELECT id, account_name_ar as account_name FROM unified_accounts WHERE (account_code LIKE '101%' OR account_code LIKE '102%') AND is_active = 1 ORDER BY account_name_ar")->fetchAll();

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// إضافة مستخدم جديد
if (isset($_POST['add_user'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='users.php';</script>");
    }
    if (!has_permission('users_create')) {
        $error = "ليس لديك صلاحية لإضافة مستخدم جديد";
    } else {
        $username = $_POST['username'];
        $full_name = $_POST['full_name'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role_id = $_POST['role_id'];
        $user_type = $_POST['user_type'];
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
        $agent_id = !empty($_POST['agent_id']) ? $_POST['agent_id'] : null;
        $employee_id = !empty($_POST['employee_id']) ? $_POST['employee_id'] : null;
        $branch_scope = $_POST['branch_scope'];
        $status = $_POST['status'];
        $chart_account_id = !empty($_POST['chart_account_id']) ? $_POST['chart_account_id'] : null;

        // جلب اسم الدور لتخزينه في عمود role (ENUM)
        $role_stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $role_stmt->execute([$role_id]);
        $role_name = $role_stmt->fetchColumn() ?: 'editor';

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role_id, user_type, branch_id, agent_id, employee_id, branch_scope, status, chart_account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password, $full_name, $role_id, $user_type, $branch_id, $agent_id, $employee_id, $branch_scope, $status, $chart_account_id]);
            $new_user_id = $pdo->lastInsertId();

            // إنشاء حساب في شجرة الحسابات تلقائياً (النظام الجديد) إذا لم يتم اختيار حساب
            if (!$chart_account_id) {
                $parent_code = get_parent_account_code_by_entity('user');
                $new_chart_account_id = create_sub_account($parent_code, "المستخدم: " . $full_name, $new_user_id, 'user');

                if ($new_chart_account_id) {
                    $pdo->prepare("UPDATE users SET chart_account_id = ? WHERE id = ?")->execute([$new_chart_account_id, $new_user_id]);
                }
            }

            // حفظ الفروع المخصصة إذا كان النطاق فروع مخصصة أو فرع واحد
            if ($branch_scope === 'custom_branches' && !empty($_POST['selected_branches'])) {
                $stmt_branch = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)");
                foreach ($_POST['selected_branches'] as $b_id) {
                    $stmt_branch->execute([$new_user_id, $b_id]);
                }
            } elseif ($branch_scope === 'single_branch' && !empty($branch_id)) {
                $stmt_branch = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)");
                $stmt_branch->execute([$new_user_id, $branch_id]);
            }

            $pdo->commit();
            echo "<script>location.href='users.php?success=1';</script>";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
        }
    }
}

// تحديث مستخدم
if (isset($_POST['update_user'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='users.php';</script>");
    }
    if (!has_permission('users_edit')) {
        $error = "ليس لديك صلاحية لتعديل بيانات المستخدم";
    } else {
        $user_id = $_POST['user_id'];
        $username = $_POST['username'];
        $full_name = $_POST['full_name'];
        $role_id = $_POST['role_id'];
        $user_type = $_POST['user_type'];
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
        $agent_id = !empty($_POST['agent_id']) ? $_POST['agent_id'] : null;
        $employee_id = !empty($_POST['employee_id']) ? $_POST['employee_id'] : null;
        $branch_scope = $_POST['branch_scope'];
        $status = $_POST['status'];
        $chart_account_id = !empty($_POST['chart_account_id']) ? $_POST['chart_account_id'] : null;

        // جلب اسم الدور لتخزينه في عمود role (ENUM)
        $role_stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $role_stmt->execute([$role_id]);
        $role_name = $role_stmt->fetchColumn() ?: 'editor';

        try {
            $pdo->beginTransaction();
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, full_name = ?, role_id = ?, user_type = ?, branch_id = ?, agent_id = ?, employee_id = ?, branch_scope = ?, status = ?, chart_account_id = ? WHERE id = ?");
                $stmt->execute([$username, $password, $full_name, $role_id, $user_type, $branch_id, $agent_id, $employee_id, $branch_scope, $status, $chart_account_id, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, role_id = ?, user_type = ?, branch_id = ?, agent_id = ?, employee_id = ?, branch_scope = ?, status = ?, chart_account_id = ? WHERE id = ?");
                $stmt->execute([$username, $full_name, $role_id, $user_type, $branch_id, $agent_id, $employee_id, $branch_scope, $status, $chart_account_id, $user_id]);
            }

            // تحديث الفروع المخصصة
            $pdo->prepare("DELETE FROM user_branches WHERE user_id = ?")->execute([$user_id]);

            if ($branch_scope === 'custom_branches' && !empty($_POST['selected_branches'])) {
                $stmt_branch = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)");
                foreach ($_POST['selected_branches'] as $b_id) {
                    $stmt_branch->execute([$user_id, $b_id]);
                }
            } elseif ($branch_scope === 'single_branch' && !empty($branch_id)) {
                $stmt_branch = $pdo->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)");
                $stmt_branch->execute([$user_id, $branch_id]);
            }

            $pdo->commit();
            echo "<script>location.href='users.php?success=2';</script>";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
        }
    }
}

// حذف مستخدم عبر POST + CSRF
if (isset($_POST['delete_user'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='users.php';</script>");
    }
    if (!has_permission('users_delete')) {
        $error = "ليس لديك صلاحية لحذف المستخدم";
    } else {
        $user_id = (int)$_POST['delete_user'];
        if ($user_id != $_SESSION['admin_id']) {
            if (is_user_involved_in_any_transaction($pdo, $user_id)) {
                $error = "لا يمكن حذف المستخدم لارتباطه بسجلات وعمليات قائمة.";
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    echo "<script>location.href='users.php?success=3';</script>";
                } catch (PDOException $e) {
                    $error = "حدث خطأ غير متوقع أثناء حذف المستخدم."; // More generic error for other issues
                }
            }
        } else {
            $error = "لا يمكنك حذف حسابك الحالي!";
        }
    }
}

// إدارة حسابات المستخدم المالية
if (isset($_POST['manage_user_accounts'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("<script>alert('رمز الأمان غير صالح'); location.href='users.php';</script>");
    }
    if (!has_permission('users_edit')) {
        $error = "ليس لديك صلاحية لتعديل حسابات المستخدم";
    } else {
        $user_id = $_POST['user_id'];
        $branch_id = $_POST['branch_id'];
        $allowed_accounts = $_POST['allowed_accounts'] ?? [];
        $default_account = $_POST['default_account'] ?? null;

        try {
            $pdo->beginTransaction();
            // مسح القديم للفرع والمستخدم المحددين
            $pdo->prepare("DELETE FROM user_cash_bank_accounts WHERE user_id = ? AND branch_id = ?")->execute([$user_id, $branch_id]);

            if (!empty($allowed_accounts)) {
                $stmt_acc = $pdo->prepare("INSERT INTO user_cash_bank_accounts (user_id, branch_id, account_id, is_default) VALUES (?, ?, ?, ?)");
                foreach ($allowed_accounts as $acc_id) {
                    $is_default = ($acc_id == $default_account) ? 1 : 0;
                    $stmt_acc->execute([$user_id, $branch_id, $acc_id, $is_default]);
                }
            }
            $pdo->commit();
            echo "<script>location.href='users.php?success=4';</script>";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "حدث خطأ أثناء تحديث الحسابات: " . $e->getMessage();
        }
    }
}

$users_raw = $pdo->query("
    SELECT u.*, r.display_name as role_name, b.branch_name, a.agent_name, e.full_name as employee_name,
           coa.account_name_ar as coa_name, coa.account_code as coa_code
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN branches b ON u.branch_id = b.id
    LEFT JOIN agents a ON u.agent_id = a.id
    LEFT JOIN employees e ON u.employee_id = e.id
    LEFT JOIN unified_accounts coa ON u.chart_account_id = coa.id
    ORDER BY u.created_at DESC
")->fetchAll();

$users = [];
foreach ($users_raw as $user) {
    // جلب الفروع التي يديرها المستخدم
    $stmt_b = $pdo->prepare("SELECT branch_id FROM user_branches WHERE user_id = ?");
    $stmt_b->execute([$user['id']]);
    $user['managed_branches'] = $stmt_b->fetchAll(PDO::FETCH_COLUMN);
    $users[] = $user;
}
?>

<div class="container-fluid py-4">
    <style>
        #addUserModal .modal-content,
        [id^="editUserModal"] .modal-content {
            overflow: hidden;
        }

        #addUserModal .modal-content > form,
        [id^="editUserModal"] .modal-content > form {
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 3.5rem);
            min-height: 0;
        }

        #addUserModal .modal-body,
        [id^="editUserModal"] .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
        }

        #addUserModal .modal-footer,
        [id^="editUserModal"] .modal-footer {
            flex: 0 0 auto;
            position: sticky;
            bottom: 0;
            z-index: 3;
            border-top: 1px solid #e2e8f0 !important;
            box-shadow: 0 -10px 24px rgba(15, 23, 42, 0.08) !important;
        }

        #addUserModal .modal-footer .btn,
        [id^="editUserModal"] .modal-footer .btn {
            min-width: 130px;
            white-space: nowrap;
        }

        @media (max-width: 576px) {
            #addUserModal .modal-dialog,
            [id^="editUserModal"] .modal-dialog {
                margin: 0.5rem;
            }

            #addUserModal .modal-content > form,
            [id^="editUserModal"] .modal-content > form {
                max-height: calc(100vh - 1rem);
            }

            #addUserModal .modal-footer,
            [id^="editUserModal"] .modal-footer {
                gap: 0.5rem;
                justify-content: stretch;
            }

            #addUserModal .modal-footer .btn,
            [id^="editUserModal"] .modal-footer .btn {
                flex: 1 1 0;
                padding-inline: 0.75rem !important;
            }
        }
    </style>

    <!-- حاوية التنبيهات (Toast Container) -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
        <?php if (isset($_GET['success']) || isset($error)): ?>
            <?php
            $toast_type = isset($error) ? 'danger' : 'success';
            $toast_msg = '';
            if (isset($error)) {
                $toast_msg = $error;
            } else {
                if ($_GET['success'] == 1) $toast_msg = "تمت إضافة المستخدم بنجاح.";
                if ($_GET['success'] == 2) $toast_msg = "تم تحديث بيانات المستخدم بنجاح.";
                if ($_GET['success'] == 3) $toast_msg = "تم حذف المستخدم بنجاح.";
                if ($_GET['success'] == 4) $toast_msg = "تم تحديث الحسابات المالية المسموحة للمستخدم بنجاح.";
            }
            ?>
            <div id="liveToast" class="toast show align-items-center text-white bg-<?= $toast_type ?> border-0 shadow-lg rounded-4" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body d-flex align-items-center">
                        <i class="fas <?= $toast_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> fs-5 me-2"></i>
                        <div>
                            <strong class="d-block small">تنبيه النظام</strong>
                            <span class="small"><?= $toast_msg ?></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-users-cog me-2"></i> إدارة المستخدمين</h3>
            <p class="text-muted small mb-0">إدارة حسابات الموظفين وتعيين الأدوار والصلاحيات</p>
        </div>
        <?php if (has_permission('users_create')): ?>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-1"></i> إضافة مستخدم جديد
            </button>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3 border-0 text-secondary small text-uppercase fw-bold">المستخدم</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">نوع الحساب</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">الدور</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">الكيان المرتبط</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">نطاق الوصول</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">الحساب المحاسبي</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">الحالة</th>
                            <th class="text-center border-0 text-secondary small text-uppercase fw-bold">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm me-3 bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></div>
                                            <small class="text-muted font-monospace">@<?php echo htmlspecialchars($user['username']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $types = [
                                        'admin' => '<span class="badge bg-dark rounded-pill">مدير</span>',
                                        'developer' => '<span class="badge bg-danger rounded-pill">مطور</span>',
                                        'branch' => '<span class="badge bg-primary rounded-pill">فرع</span>',
                                        'agent' => '<span class="badge bg-info rounded-pill">وكيل</span>',
                                        'employee' => '<span class="badge bg-success rounded-pill">موظف</span>',
                                        'other' => '<span class="badge bg-secondary rounded-pill">آخر</span>'
                                    ];
                                    echo $types[$user['user_type']] ?? '<span class="badge bg-secondary rounded-pill">غير محدد</span>';
                                    ?>
                                </td>
                                <td>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3">
                                        <i class="fas fa-shield-alt me-1"></i> <?php echo htmlspecialchars($user['role_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['branch_name']): ?>
                                        <div class="small fw-bold text-dark"><i class="fas fa-code-branch me-1 text-muted"></i> <?php echo htmlspecialchars($user['branch_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($user['agent_name']): ?>
                                        <div class="small fw-bold text-dark"><i class="fas fa-user-tie me-1 text-muted"></i> <?php echo htmlspecialchars($user['agent_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($user['employee_name']): ?>
                                        <div class="small fw-bold text-dark"><i class="fas fa-id-card me-1 text-muted"></i> <?php echo htmlspecialchars($user['employee_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!$user['branch_name'] && !$user['agent_name'] && !$user['employee_name']): ?>
                                        <span class="badge bg-light text-secondary border rounded-pill">عام (بدون تقييد)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $scopes = [
                                        'single_branch' => '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill">فرع واحد</span>',
                                        'all_branches' => '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">جميع الفروع</span>',
                                        'custom_branches' => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">فروع مخصصة</span>'
                                    ];
                                    echo $scopes[$user['branch_scope']] ?? $user['branch_scope'];
                                    ?>
                                </td>
                                <td>
                                    <?php if ($user['coa_name']): ?>
                                        <div class="small fw-bold text-primary"><?php echo htmlspecialchars($user['coa_name']); ?></div>
                                        <div class="small text-muted extra-small"><?php echo htmlspecialchars($user['coa_code']); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted extra-small">غير مرتبط</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['status'] == 'active'): ?>
                                        <form method="POST" action="ajax_toggle_status.php" class="d-inline-block mb-0">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="entity" value="users">
                                            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="status" value="inactive">
                                            <button type="submit" class="status-toggle-badge active border-0 bg-transparent p-0">
                                                <i class="fas fa-check-circle"></i> نشط
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="ajax_toggle_status.php" class="d-inline-block mb-0">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="entity" value="users">
                                            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="status-toggle-badge inactive border-0 bg-transparent p-0">
                                                <i class="fas fa-times-circle"></i> معطل
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-light btn-sm rounded-circle" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v text-muted"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow rounded-3">
                                            <?php if (has_permission('users_edit')): ?>
                                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id']; ?>"><i class="fas fa-edit me-2 text-primary"></i> تعديل البيانات</a></li>
                                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#manageAccountsModal<?php echo $user['id']; ?>"><i class="fas fa-university me-2 text-success"></i> الحسابات المالية</a></li>
                                            <?php endif; ?>

                                            <?php if (has_permission('users_delete') && $user['id'] != $_SESSION['admin_id']): ?>
                                                <li>
                                                    <hr class="dropdown-divider">
                                                </li>
                                                <li>
                                                    <form method="POST" class="mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم؟')">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="delete_user" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash me-2"></i> حذف المستخدم</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
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

<!-- Modals for editing users -->
<?php foreach ($users as $user): ?>
    <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <div class="modal-header bg-primary text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-user-edit me-2"></i> تعديل المستخدم</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">

                        <div class="card border-0 shadow-sm rounded-4 mb-3">
                            <div class="card-body p-4">
                                <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i> البيانات الشخصية</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted">اسم المستخدم</label>
                                        <input type="text" name="username" class="form-control rounded-3" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted">الاسم الكامل</label>
                                        <input type="text" name="full_name" class="form-control rounded-3" value="<?php echo htmlspecialchars($user['full_name']); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted">كلمة المرور الجديدة</label>
                                        <input type="password" name="password" class="form-control rounded-3" placeholder="اتركها فارغة للإبقاء على الحالية">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted">نوع الحساب</label>
                                        <select name="user_type" class="form-select rounded-3" required>
                                            <option value="employee" <?php echo $user['user_type'] == 'employee' ? 'selected' : ''; ?>>موظف</option>
                                            <option value="branch" <?php echo $user['user_type'] == 'branch' ? 'selected' : ''; ?>>فرع</option>
                                            <option value="agent" <?php echo $user['user_type'] == 'agent' ? 'selected' : ''; ?>>وكيل</option>
                                            <option value="admin" <?php echo $user['user_type'] == 'admin' ? 'selected' : ''; ?>>مدير</option>
                                            <option value="developer" <?php echo $user['user_type'] == 'developer' ? 'selected' : ''; ?>>مطور</option>
                                            <option value="other" <?php echo $user['user_type'] == 'other' ? 'selected' : ''; ?>>آخر</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted">الحالة</label>
                                        <select name="status" class="form-select rounded-3">
                                            <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>نشط</option>
                                            <option value="inactive" <?php echo $user['status'] == 'inactive' ? 'selected' : ''; ?>>معطل</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-4">
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-shield-alt me-2"></i> الصلاحيات والوصول</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted">الدور (الصلاحية)</label>
                                        <select name="role_id" class="form-select rounded-3 role-select" required data-user-id="<?php echo $user['id']; ?>">
                                            <?php foreach ($roles_list as $role): ?>
                                                <option value="<?php echo $role['id']; ?>" <?php echo $user['role_id'] == $role['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($role['display_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text extra-small"><a href="roles.php" target="_blank" class="text-decoration-none">إدارة الأدوار <i class="fas fa-external-link-alt ms-1"></i></a></div>
                                    </div>
                                    <div class="col-md-6 scope-container" style="<?php echo $user['role_id'] == 3 ? '' : 'display:none;'; ?>">
                                        <label class="form-label fw-bold small text-muted">نطاق الوصول</label>
                                        <select name="branch_scope" class="form-select rounded-3 scope-select" data-user-id="<?php echo $user['id']; ?>">
                                            <option value="single_branch" <?php echo $user['branch_scope'] == 'single_branch' ? 'selected' : ''; ?>>فرع واحد</option>
                                            <option value="all_branches" <?php echo $user['branch_scope'] == 'all_branches' ? 'selected' : ''; ?>>جميع الفروع</option>
                                            <option value="custom_branches" <?php echo $user['branch_scope'] == 'custom_branches' ? 'selected' : ''; ?>>فروع مخصصة</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 branch-container" style="<?php echo ($user['branch_scope'] == 'single_branch') ? '' : 'display:none;'; ?>">
                                        <label class="form-label fw-bold small text-muted">الفرع</label>
                                        <select name="branch_id" class="form-select rounded-3">
                                            <option value="">لا يوجد (عام)</option>
                                            <?php foreach ($branches_list as $branch): ?>
                                                <option value="<?php echo $branch['id']; ?>" <?php echo $user['branch_id'] == $branch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 custom-branches-container" style="<?php echo $user['branch_scope'] == 'custom_branches' ? '' : 'display:none;'; ?>">
                                        <label class="form-label fw-bold small text-muted">اختر الفروع المخصصة</label>
                                        <select name="selected_branches[]" class="form-select rounded-3" multiple size="4">
                                            <?php foreach ($branches_list as $branch): ?>
                                                <option value="<?php echo $branch['id']; ?>" <?php echo in_array($branch['id'], $user['managed_branches']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted extra-small">اضغط Ctrl لاختيار أكثر من فرع</small>
                                    </div>
                                    <div class="col-md-6 agent-container" style="<?php echo $user['user_type'] == 'agent' ? '' : 'display:none;'; ?>">
                                        <label class="form-label fw-bold small text-muted">الوكيل</label>
                                        <select name="agent_id" class="form-select rounded-3">
                                            <option value="">لا يوجد</option>
                                            <?php foreach ($agents_list as $agent): ?>
                                                <option value="<?php echo $agent['id']; ?>" <?php echo $user['agent_id'] == $agent['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($agent['agent_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 employee-container" style="<?php echo $user['user_type'] == 'employee' ? '' : 'display:none;'; ?>">
                                        <label class="form-label fw-bold small text-muted">الموظف المرتبط</label>
                                        <select name="employee_id" class="form-select rounded-3">
                                            <option value="">لا يوجد</option>
                                            <?php foreach ($employees_list as $emp): ?>
                                                <option value="<?php echo $emp['id']; ?>" <?php echo $user['employee_id'] == $emp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['full_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 rounded-bottom-4 shadow-sm">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_user" class="btn btn-primary rounded-pill px-5 fw-bold">حفظ التغييرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal إدارة الحسابات المالية للمستخدم -->
    <div class="modal fade" id="manageAccountsModal<?php echo $user['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <div class="modal-header bg-success text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-university me-2"></i> إدارة الحسابات المالية للمستخدم</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <input type="hidden" name="branch_id" value="<?php echo $user['branch_id'] ?: 0; ?>">

                        <div class="alert alert-info border-0 shadow-sm rounded-4 small mb-3">
                            <i class="fas fa-info-circle me-1"></i> حدد الصناديق والبنوك التي يُسمح للمستخدم <strong><?php echo htmlspecialchars($user['username']); ?></strong> بالتعامل معها في هذا الفرع.
                        </div>

                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-3">
                                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3 small">الصناديق والبنوك المتاحة</h6>
                                <?php
                                // جلب الحسابات المرتبطة حالياً بهذا المستخدم
                                $stmt_curr = $pdo->prepare("SELECT account_id, is_default FROM user_cash_bank_accounts WHERE user_id = ? AND branch_id = ?");
                                $stmt_curr->execute([$user['id'], $user['branch_id'] ?: 0]);
                                $curr_accounts = $stmt_curr->fetchAll(PDO::FETCH_KEY_PAIR);
                                ?>
                                <div class="list-group list-group-flush">
                                    <?php if (empty($cash_bank_accounts)): ?>
                                        <div class="text-center text-muted py-3">لا توجد حسابات مالية معرفة في النظام</div>
                                    <?php else: ?>
                                        <?php foreach ($cash_bank_accounts as $acc): ?>
                                            <div class="list-group-item border-0 d-flex align-items-center px-0">
                                                <div class="form-switch flex-grow-1">
                                                    <label class="form-check-label small fw-bold" for="u_acc_<?php echo $user['id'] . '_' . $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_name']); ?></label>
                                                    <input class="form-check-input" type="checkbox" name="allowed_accounts[]" value="<?php echo $acc['id']; ?>" id="u_acc_<?php echo $user['id'] . '_' . $acc['id']; ?>" <?php echo isset($curr_accounts[$acc['id']]) ? 'checked' : ''; ?>>
                                                </div>
                                                <div class="form-check ms-3">
                                                    <input class="form-check-input" type="radio" name="default_account" value="<?php echo $acc['id']; ?>" id="u_def_<?php echo $user['id'] . '_' . $acc['id']; ?>" <?php echo (isset($curr_accounts[$acc['id']]) && $curr_accounts[$acc['id']] == 1) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label extra-small text-muted" for="u_def_<?php echo $user['id'] . '_' . $acc['id']; ?>">افتراضي</label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-white border-top-0 py-3 rounded-bottom-4 shadow-sm">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="manage_user_accounts" class="btn btn-success rounded-pill px-5 fw-bold">حفظ الحسابات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Modal إضافة مستخدم جديد -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-success text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i> إضافة مستخدم جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <div class="card border-0 shadow-sm rounded-4 mb-3">
                        <div class="card-body p-4">
                            <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i> البيانات الشخصية</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">اسم المستخدم</label>
                                    <input type="text" name="username" class="form-control rounded-3" placeholder="أدخل اسم المستخدم" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">الاسم الكامل</label>
                                    <input type="text" name="full_name" class="form-control rounded-3" placeholder="أدخل الاسم الكامل">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">كلمة المرور</label>
                                    <input type="password" name="password" class="form-control rounded-3" placeholder="أدخل كلمة المرور" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">نوع الحساب</label>
                                    <select name="user_type" class="form-select rounded-3" required>
                                        <option value="employee">موظف</option>
                                        <option value="branch">فرع</option>
                                        <option value="agent">وكيل</option>
                                        <option value="admin">مدير</option>
                                        <option value="developer">مطور</option>
                                        <option value="other">آخر</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">الحالة</label>
                                    <select name="status" class="form-select rounded-3">
                                        <option value="active">نشط</option>
                                        <option value="inactive">معطل</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-shield-alt me-2"></i> الصلاحيات والوصول</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">الدور (الصلاحية)</label>
                                    <select name="role_id" id="add_role_id" class="form-select rounded-3" required>
                                        <option value="">اختر الدور</option>
                                        <?php foreach ($roles_list as $role): ?>
                                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['display_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6" id="add_scope_container" style="display:none;">
                                    <label class="form-label fw-bold small text-muted">نطاق الوصول</label>
                                    <select name="branch_scope" id="add_branch_scope" class="form-select rounded-3">
                                        <option value="single_branch">فرع واحد</option>
                                        <option value="all_branches">جميع الفروع</option>
                                        <option value="custom_branches">فروع مخصصة</option>
                                    </select>
                                </div>
                                <div class="col-md-6" id="add_branch_container">
                                    <label class="form-label fw-bold small text-muted">الفرع</label>
                                    <select name="branch_id" class="form-select rounded-3">
                                        <option value="">لا يوجد (عام)</option>
                                        <?php foreach ($branches_list as $branch): ?>
                                            <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6" id="add_custom_branches_container" style="display:none;">
                                    <label class="form-label fw-bold small text-muted">اختر الفروع المخصصة</label>
                                    <select name="selected_branches[]" class="form-select rounded-3" multiple size="4">
                                        <?php foreach ($branches_list as $branch): ?>
                                            <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted extra-small">اضغط Ctrl لاختيار أكثر من فرع</small>
                                </div>
                                <div class="col-md-6" id="add_agent_container" style="display:none;">
                                    <label class="form-label fw-bold small text-muted">الوكيل</label>
                                    <select name="agent_id" class="form-select rounded-3">
                                        <option value="">لا يوجد</option>
                                        <?php foreach ($agents_list as $agent): ?>
                                            <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['agent_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6" id="add_employee_container">
                                    <label class="form-label fw-bold small text-muted">الموظف المرتبط</label>
                                    <select name="employee_id" class="form-select rounded-3">
                                        <option value="">لا يوجد</option>
                                        <?php foreach ($employees_list as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top-0 py-3 rounded-bottom-4 shadow-sm">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_user" class="btn btn-success rounded-pill px-5 fw-bold">إضافة المستخدم</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // منطق إضافة مستخدم جديد
        const addRoleId = document.getElementById('add_role_id');
        const addScopeContainer = document.getElementById('add_scope_container');
        const addBranchScope = document.getElementById('add_branch_scope');
        const addBranchContainer = document.getElementById('add_branch_container');
        const addCustomBranchesContainer = document.getElementById('add_custom_branches_container');
        const addAgentContainer = document.getElementById('add_agent_container');
        const addEmployeeContainer = document.getElementById('add_employee_container');
        const addUserType = document.querySelector('select[name="user_type"]');

        function toggleAddFields() {
            const roleId = addRoleId.value;
            const userType = addUserType.value;
            const scope = addBranchScope.value;

            // إظهار نطاق الوصول فقط لمدير الفرع (ID=3)
            if (roleId == '3') {
                addScopeContainer.style.display = 'block';

                if (scope === 'single_branch') {
                    addBranchContainer.style.display = 'block';
                    addCustomBranchesContainer.style.display = 'none';
                } else if (scope === 'custom_branches') {
                    addBranchContainer.style.display = 'none';
                    addCustomBranchesContainer.style.display = 'block';
                } else {
                    addBranchContainer.style.display = 'none';
                    addCustomBranchesContainer.style.display = 'none';
                }
            } else {
                addScopeContainer.style.display = 'none';
                addBranchContainer.style.display = (userType === 'branch') ? 'block' : 'none';
                addCustomBranchesContainer.style.display = 'none';
            }

            // إظهار الوكيل والموظف حسب نوع الحساب
            addAgentContainer.style.display = (userType === 'agent') ? 'block' : 'none';
            addEmployeeContainer.style.display = (userType === 'employee') ? 'block' : 'none';
        }

        if (addRoleId) {
            addRoleId.addEventListener('change', toggleAddFields);
            addBranchScope.addEventListener('change', toggleAddFields);
            addUserType.addEventListener('change', toggleAddFields);
        }

        // منطق تعديل مستخدم (للمودالات المتعددة)
        document.querySelectorAll('.role-select').forEach(select => {
            select.addEventListener('change', function() {
                const userId = this.dataset.userId;
                const modal = this.closest('.modal');
                const scopeContainer = modal.querySelector('.scope-container');
                const userType = modal.querySelector('select[name="user_type"]').value;

                if (this.value == '3') {
                    scopeContainer.style.display = 'block';
                } else {
                    scopeContainer.style.display = 'none';
                    modal.querySelector('.branch-container').style.display = (userType === 'branch') ? 'block' : 'none';
                    modal.querySelector('.custom-branches-container').style.display = 'none';
                }
            });
        });

        document.querySelectorAll('.scope-select').forEach(select => {
            select.addEventListener('change', function() {
                const modal = this.closest('.modal');
                const branchContainer = modal.querySelector('.branch-container');
                const customBranchesContainer = modal.querySelector('.custom-branches-container');

                if (this.value === 'single_branch') {
                    branchContainer.style.display = 'block';
                    customBranchesContainer.style.display = 'none';
                } else if (this.value === 'custom_branches') {
                    branchContainer.style.display = 'none';
                    customBranchesContainer.style.display = 'block';
                } else {
                    branchContainer.style.display = 'none';
                    customBranchesContainer.style.display = 'none';
                }
            });
        });

        document.querySelectorAll('select[name="user_type"]').forEach(select => {
            if (select.name === 'user_type' && !select.id.includes('add')) {
                select.addEventListener('change', function() {
                    const modal = this.closest('.modal');
                    const roleId = modal.querySelector('.role-select').value;

                    modal.querySelector('.agent-container').style.display = (this.value === 'agent') ? 'block' : 'none';
                    modal.querySelector('.employee-container').style.display = (this.value === 'employee') ? 'block' : 'none';

                    if (roleId != '3') {
                        modal.querySelector('.branch-container').style.display = (this.value === 'branch') ? 'block' : 'none';
                    }
                });
            }
        });
        // إخفاء التنبيهات تلقائياً بعد 5 ثواني
        const toastEl = document.getElementById('liveToast');
        if (toastEl) {
            setTimeout(() => {
                const toast = new bootstrap.Toast(toastEl);
                toast.hide();
            }, 5000);
        }
    });
</script>

<?php require_once 'footer.php'; ?>
