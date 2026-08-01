<?php
ob_start();
require_once 'header.php';

// Check and add missing attendance_location_id column if needed
try {
    $check = $pdo->query("SHOW COLUMNS FROM employees LIKE 'attendance_location_id'");
    $columnExists = $check->fetch();
    
    if (!$columnExists) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN attendance_location_id INT DEFAULT NULL AFTER shift_id");
    }
} catch (PDOException $e) {
    // Ignore errors, we'll handle it in the queries
}

// جلب المسميات الوظيفية
$job_titles_list = $pdo->query("SELECT id, title_name FROM job_titles ORDER BY title_name ASC")->fetchAll(PDO::FETCH_ASSOC);
// جلب بيانات الرواتب والفترات المرتبطة بالمسميات الوظيفية
$job_salaries_data = $pdo->query("
    SELECT sjts.job_title_id, sjts.salary, ws.shift_name, ws.start_time, ws.end_time 
    FROM shift_job_title_salaries sjts 
    JOIN work_shifts ws ON sjts.shift_id = ws.id
")->fetchAll(PDO::FETCH_ASSOC);
$job_salaries_json = json_encode($job_salaries_data);
// جلب فترات الدوام
$work_shifts_list = $pdo->query("SELECT id, shift_name, start_time, end_time FROM work_shifts ORDER BY shift_name ASC")->fetchAll(PDO::FETCH_ASSOC);
// جلب مواقع الحضور
$attendance_locations = $pdo->query("SELECT id, name, latitude, longitude, radius_meters FROM attendance_locations WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
require_once dirname(__DIR__) . '/includes/accounting_functions.php';
require_once dirname(__DIR__) . '/includes/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

// التحقق من الصلاحية
if (!has_permission('employees_view')) {
    die("غير مصرح لك بالوصول لهذه الصفحة.");
}

if (isset($_GET['delete'])) {
    $error = "تم تعطيل تنفيذ الإجراءات الحساسة عبر الرابط المباشر. استخدم النماذج الداخلية المحمية فقط.";
}

// جلب الفروع
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();

// جلب الحسابات المحاسبية المتاحة للموظفين (كود يبدأ بـ 21103)
$available_accounts = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code LIKE BINARY '21103%' AND id NOT IN (SELECT account_id FROM employees WHERE account_id IS NOT NULL)")->fetchAll();

// جلب قائمة المستخدمين لربط الموظف بمستخدم (اختياري)
$users_list = $pdo->query("SELECT id, username FROM users WHERE status = 'active'")->fetchAll();

// إضافة موظف جديد
if (isset($_POST['add_employee'])) {
    if (!has_permission('employees_create')) {
        $error = "ليس لديك صلاحية لإضافة موظف";
    } else {
        $full_name = $_POST['full_name'];
        $phone = $_POST['phone'];
        $email = $_POST['email'] ?? '';
        $address = $_POST['address'] ?? '';
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
        $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : null;
        $job_title_id = !empty($_POST['job_title_id']) ? $_POST['job_title_id'] : null;
        $shift_id = !empty($_POST['shift_id']) ? $_POST['shift_id'] : null;
        $job_title = ""; // للتوافق مع الكود القديم إذا لزم الأمر
        $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $photo = "";
        if (!empty($_FILES['photo']['name'])) {
            $target_dir = "../uploads/employees/";
            $file_ext = pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION);
            $photo = "uploads/employees/" . time() . "." . $file_ext;
            move_uploaded_file($_FILES["photo"]["tmp_name"], "../" . $photo);
        }
        $department = $_POST['department'] ?? '';
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?? '';
        $account_id = !empty($_POST['account_id']) ? $_POST['account_id'] : null;
        $attendance_location_id = !empty($_POST['attendance_location_id']) ? $_POST['attendance_location_id'] : null;

        try {
            $pdo->beginTransaction();
            
            // 1. إضافة الموظف
            $stmt = $pdo->prepare("INSERT INTO employees (full_name, phone, email, address, branch_id, manager_id, job_title_id, department, status, notes, account_id, hire_date, shift_id, photo, attendance_location_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$full_name, $phone, $email, $address, $branch_id, $manager_id, $job_title_id, $department, $status, $notes, $account_id, $hire_date, $shift_id, $photo, $attendance_location_id]);
            $new_emp_id = $pdo->lastInsertId();

            // 2. إنشاء حساب تلقائي إذا لم يتم اختياره أو استخدام الحساب الموجود
            $account_id_to_process = $account_id;
            if (!$account_id_to_process) {
                $account_id_to_process = php_handle_entity_account_creation($pdo, 'employee', $new_emp_id, $full_name);
                // تحديث جدول employees بمعرف الحساب الجديد إذا تم إنشاؤه تلقائياً
                $stmt_update_employee_account = $pdo->prepare("UPDATE employees SET account_id = ? WHERE id = ?");
                $stmt_update_employee_account->execute([$account_id_to_process, $new_emp_id]);
            }

            // 3. التأكد من تفعيل العملة الأساسية للحساب المرتبط (سواء كان جديداً أو موجوداً)
            if ($account_id_to_process) {
                $currencyExchange = new CurrencyExchange($pdo);
                $baseCurrency = $currencyExchange->getBaseCurrency();
                $base_currency_id = $baseCurrency['id'] ?? null;

                if ($base_currency_id) {
                    $stmt_check_balance = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
                    $stmt_check_balance->execute([$account_id_to_process, $base_currency_id]);
                    if ($stmt_check_balance->fetchColumn() == 0) {
                        $stmt_insert_balance = $pdo->prepare("INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) VALUES (?, ?, 0, 0, 0)");
                        $stmt_insert_balance->execute([$account_id_to_process, $base_currency_id]);
                    }
                }
            }

            $pdo->commit();
            echo "<script>location.href='employees.php?success=1';</script>"; exit();
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
        }
    }
}

// تحديث بيانات موظف
if (isset($_POST['update_employee'])) {
    if (!has_permission('employees_edit')) {
        $error = "ليس لديك صلاحية لتعديل بيانات موظف";
    } else {
        $id = $_POST['id'];
        $full_name = $_POST['full_name'];
        $phone = $_POST['phone'];
        $email = $_POST['email'] ?? '';
        $address = $_POST['address'] ?? '';
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
        $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : null;
        $job_title_id = !empty($_POST['job_title_id']) ? $_POST['job_title_id'] : null;
        $shift_id = !empty($_POST['shift_id']) ? $_POST['shift_id'] : null;
        $job_title = ""; // للتوافق مع الكود القديم إذا لزم الأمر
        $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $photo = "";
        if (!empty($_FILES['photo']['name'])) {
            $target_dir = "../uploads/employees/";
            $file_ext = pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION);
            $photo = "uploads/employees/" . time() . "." . $file_ext;
            move_uploaded_file($_FILES["photo"]["tmp_name"], "../" . $photo);
        }
        $department = $_POST['department'] ?? '';
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?? '';
        $account_id = !empty($_POST['account_id']) ? $_POST['account_id'] : null;
        $attendance_location_id = !empty($_POST['attendance_location_id']) ? $_POST['attendance_location_id'] : null;

        try {
            $pdo->beginTransaction();
            
            $params = [$full_name, $phone, $email, $address, $branch_id, $manager_id, $job_title_id, $department, $status, $notes, $account_id, $hire_date, $shift_id, $attendance_location_id];
            if (!empty($_FILES["photo"]["name"])) {
                $params[] = $photo;
            }
            $params[] = $id;
            $stmt = $pdo->prepare("UPDATE employees SET full_name = ?, phone = ?, email = ?, address = ?, branch_id = ?, manager_id = ?, job_title_id = ?, department = ?, status = ?, notes = ?, account_id = ?, hire_date = ?, shift_id = ?, attendance_location_id = ?" . (!empty($_FILES["photo"]["name"]) ? ", photo = ?" : "") . " WHERE id = ?");
            $stmt->execute($params);


            // بعد تحديث بيانات الموظف، نحصل على معرف الحساب المحاسبي النهائي
            // قد يكون الحساب قد تم ربطه الآن أو كان مرتبطاً بالفعل أو تم إلغاء ربطه
            $stmt_get_final_account_id = $pdo->prepare("SELECT account_id FROM employees WHERE id = ?");
            $stmt_get_final_account_id->execute([$id]);
            $final_employee_account_id = $stmt_get_final_account_id->fetchColumn();

            $account_id_to_process = $final_employee_account_id;

            // إذا لم يكن هناك حساب مرتبط بالموظف بعد التحديث (أو تم إلغاء ربطه) ولكن الموظف موجود، نقوم بإنشاء حساب جديد له
            if (!$account_id_to_process) {
                // التأكد من أن الموظف لا يزال موجوداً قبل إنشاء حساب له
                $stmt_check_employee_exists = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id = ? AND deleted_at IS NULL");
                $stmt_check_employee_exists->execute([$id]);
                if ($stmt_check_employee_exists->fetchColumn() > 0) {
                    $account_id_to_process = php_handle_entity_account_creation($pdo, 'employee', $id, $full_name);
                    // تحديث الموظف بمعرف الحساب الجديد
                    $pdo->prepare("UPDATE employees SET account_id = ? WHERE id = ?")->execute([$account_id_to_process, $id]);
                }
            }

            // مزامنة الاسم مع شجرة الحسابات الموحدة
            if ($account_id_to_process) {
                $pdo->prepare("UPDATE unified_accounts SET account_name_ar = ? WHERE id = ?")->execute([$full_name, $account_id_to_process]);

                // التأكد من تفعيل العملة الأساسية للحساب المرتبط
                $currencyExchange = new CurrencyExchange($pdo);
                $baseCurrency = $currencyExchange->getBaseCurrency();
                $base_currency_id = $baseCurrency['id'] ?? null;

                if ($base_currency_id) {
                    $stmt_check_balance = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
                    $stmt_check_balance->execute([$account_id_to_process, $base_currency_id]);
                    if ($stmt_check_balance->fetchColumn() == 0) {
                        $stmt_insert_balance = $pdo->prepare("INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) VALUES (?, ?, 0, 0, 0)");
                        $stmt_insert_balance->execute([$account_id_to_process, $base_currency_id]);
                    }
                }
            }

            $pdo->commit();
            header("Location: employees.php?success=2");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
        }
    }
}

// حذف موظف (أرشفة) عبر POST + CSRF
if (isset($_POST['delete_employee'])) {
    if (!has_permission('employees_delete')) {
        $error = "ليس لديك صلاحية لحذف الموظف";
    } else {
        $id = (int)$_POST['delete_employee'];
        try {
            $pdo->beginTransaction();

            // جلب معرف الحساب المحاسبي
            $stmt_emp = $pdo->prepare("SELECT account_id FROM employees WHERE id = ?");
            $stmt_emp->execute([$id]);
            $account_id = $stmt_emp->fetchColumn();

            // التحقق مما إذا كان الموظف مرتبطاً بسجلات (مثل مدير لموظفين آخرين)
            $check_sub = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE manager_id = ? AND deleted_at IS NULL");
            $check_sub->execute([$id]);
            if ($check_sub->fetchColumn() > 0) {
                throw new Exception("لا يمكن حذف الموظف لوجود موظفين آخرين تحت إدارته. يرجى تغيير مديرهم أولاً.");
            }

            // إذا كان هناك حساب محاسبي مرتبط، نحاول حذفه أيضاً
            if ($account_id) {
                if (!can_delete_account($account_id)) {
                    throw new Exception("لا يمكن حذف الموظف لوجود عمليات مالية مسجلة على حسابه المحاسبي. يمكنك تغيير حالته إلى (متوقف) بدلاً من ذلك.");
                }

                // حذف الأرصدة المرتبطة بالحساب
                $stmt_del_bal = $pdo->prepare("UPDATE account_balances_unified SET is_frozen = 1 WHERE account_id = ?");
                $stmt_del_bal->execute([$account_id]);

                // حذف الحساب من شجرة الحسابات الموحدة
                $stmt_del_acc = $pdo->prepare("UPDATE unified_accounts SET account_status = 'inactive' WHERE id = ?");
                $stmt_del_acc->execute([$account_id]);
            }

            // حذف الموظف (أرشفة)
            $stmt = $pdo->prepare("UPDATE employees SET deleted_at = NOW(), status = 'inactive', account_id = NULL WHERE id = ?");
            $stmt->execute([$id]);

            $pdo->commit();
            header("Location: employees.php?success=3");
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "حدث خطأ أثناء الحذف: " . $e->getMessage();
        }
    }
}

// جلب الموظفين مع حالة التحضير اليوم
$today = date('Y-m-d');
$employees = $pdo->query("
    SELECT e.*, b.branch_name, ua.account_name_ar as coa_name, ua.account_code as coa_code,
           m.full_name as manager_name,
           jt.title_name as job_title_name, ws.shift_name, ws.start_time, ws.end_time,
           att.check_in, att.check_out
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    LEFT JOIN unified_accounts ua ON e.account_id = ua.id
    LEFT JOIN employees m ON e.manager_id = m.id
    LEFT JOIN job_titles jt ON e.job_title_id = jt.id
    LEFT JOIN work_shifts ws ON e.shift_id = ws.id
    LEFT JOIN employee_attendance att ON e.id = att.employee_id AND att.attendance_date = '$today'
    WHERE e.deleted_at IS NULL 
    ORDER BY e.created_at DESC
")->fetchAll();

// جلب الأرصدة لكل الموظفين
$employee_account_ids = array_filter(array_column($employees, 'account_id'));
$balances = [];
if (!empty($employee_account_ids)) {
    $placeholders = implode(',', array_fill(0, count($employee_account_ids), '?'));
    $bal_stmt = $pdo->prepare("
        SELECT ab.*, c.currency_name, c.currency_symbol 
        FROM account_balances_unified ab 
        JOIN currencies c ON ab.currency_id = c.id 
        WHERE ab.account_id IN ($placeholders)
    ");
    $bal_stmt->execute(array_values($employee_account_ids));
    while ($row = $bal_stmt->fetch()) {
        $balances[$row['account_id']][] = $row;
    }
}
?>

<div class="container-fluid py-4 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="fas fa-users-cog me-2 text-primary"></i> إدارة الموظفين</h2>
        <div class="d-flex gap-2">
            <button id="locationPermissionBtn" class="btn btn-outline-info rounded-pill px-4" onclick="requestLocationPermission()">
                <i class="fas fa-map-marker-alt me-2"></i> السماح بالوصول للموقع
            </button>
            <a href="attendance_report.php" class="btn btn-outline-warning rounded-pill px-4">
                <i class="fas fa-clipboard-list me-2"></i> سجل الدوام
            </a>
            <button class="btn btn-outline-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#hierarchyModal">
                <i class="fas fa-sitemap me-2"></i> عرض التسلسل الهرمي
            </button>
            <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                <i class="fas fa-plus me-2"></i> إضافة موظف جديد
            </button>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4">
            <?php echo h($_GET['success'] == 1 ? 'تمت إضافة الموظف بنجاح.' : 'تم تحديث بيانات الموظف بنجاح.'); ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">قائمة الموظفين</h5>
            <div class="search-box" style="width: 300px;">
                <input type="text" id="employeeSearch" class="form-control rounded-pill border-light bg-light" placeholder="بحث عن موظف...">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="employeesTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">الموظف</th>
                            <th>المسمى الوظيفي / القسم</th>
                            <th>الفرع</th>
                            <th>الحساب المالي</th>
                            <th>الرصيد الحالي</th>
                            <th>الحالة</th>
                            <th class="text-center pe-4">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td class="ps-4" data-label="الموظف">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm me-3" style="width: 45px; height: 45px;">
                                            <?php if (!empty($emp['photo']) && file_exists("../" . $emp['photo'])): ?>
                                                <img src="../<?php echo htmlspecialchars($emp['photo']); ?>" class="rounded-circle w-100 h-100 object-fit-cover border shadow-sm" alt="صورة الموظف">
                                            <?php else: ?>
                                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center w-100 h-100">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($emp['phone']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                 <td data-label="المسمى الوظيفي / القسم">
                                    <div><?php echo htmlspecialchars($emp['job_title_name'] ?: $emp['job_title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($emp['department']); ?></small>
                                    <?php if ($emp['manager_name']): ?>
                                        <div class="small mt-1 text-primary"><i class="fas fa-sitemap me-1"></i> مدير: <?php echo htmlspecialchars($emp['manager_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                 <td data-label="الفرع"><span class="badge bg-light text-dark fw-normal"><?php echo htmlspecialchars($emp['branch_name'] ?? 'عام'); ?></span></td>
                                 <td data-label="الحساب المالي">
                                    <?php if ($emp['coa_code']): ?>
                                        <code class="text-primary"><?php echo $emp['coa_code']; ?></code>
                                        <div class="small text-muted"><?php echo $emp['coa_name']; ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">غير مرتبط</span>
                                    <?php endif; ?>
                                </td>
                                 <td data-label="الرصيد الحالي">
                                    <?php 
                                    if ($emp['account_id'] && isset($balances[$emp['account_id']])) {
                                        foreach ($balances[$emp['account_id']] as $bal) {
                                            echo '<div class="mb-1 small">' . format_account_balance($bal['current_balance'], $emp['normal_balance'] ?? 'liability', $bal['currency_name']) . '</div>';
                                        }
                                    } else {
                                        echo '<span class="text-muted small">0.00</span>';
                                    }
                                    ?>
                                </td>
                                 <td data-label="الحالة">
                                    <?php if ($emp['status'] == 'active'): ?>
                                        <form method="POST" action="ajax_toggle_status.php" class="d-inline-block mb-0">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="entity" value="employees">
                                            <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                            <input type="hidden" name="status" value="inactive">
                                            <button type="submit" class="status-toggle-badge active border-0 bg-transparent p-0">
                                                <i class="fas fa-check-circle"></i> على رأس العمل
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="ajax_toggle_status.php" class="d-inline-block mb-0">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="entity" value="employees">
                                            <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="status-toggle-badge inactive border-0 bg-transparent p-0">
                                                <i class="fas fa-pause-circle"></i> متوقف
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4">
                                    <div class="btn-group">
                                        <?php if (!$emp['check_in']): ?>
                                            <button class="btn btn-sm btn-light rounded-circle me-1" onclick="markAttendance(<?php echo $emp['id']; ?>, 'check_in')" title="تحضير دخول"><i class="fas fa-sign-in-alt text-primary"></i></button>
                                        <?php elseif (!$emp['check_out']): ?>
                                            <button class="btn btn-sm btn-light rounded-circle me-1" onclick="markAttendance(<?php echo $emp['id']; ?>, 'check_out')" title="تسجيل انصراف"><i class="fas fa-sign-out-alt text-warning"></i></button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light rounded-circle me-1 disabled" title="تم التحضير"><i class="fas fa-check-double text-success"></i></button>
                                        <?php endif; ?>
                                        <a href="print_employee_card.php?id=<?php echo $emp['id']; ?>" target="_blank" class="btn btn-sm btn-light rounded-circle me-1" title="طباعة البطاقة"><i class="fas fa-id-card text-success"></i></a>
                                        <button class="btn btn-sm btn-light rounded-circle edit-btn" data-bs-toggle="modal" data-bs-target="#editEmployeeModal<?php echo $emp['id']; ?>" title="تعديل"><i class="fas fa-edit text-primary"></i></button>
                                        <form method="POST" class="d-inline-block mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الموظف؟ سيتم حذف حسابه المالي أيضاً إذا لم يكن عليه حركات.')">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="delete_employee" value="<?php echo $emp['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-light rounded-circle ms-1" title="حذف"><i class="fas fa-trash text-danger"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            <!-- Modal تعديل -->
                            <div class="modal fade" id="editEmployeeModal<?php echo $emp['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content border-0 rounded-4 shadow">
                                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title fw-bold">تعديل بيانات موظف</h5>
                                                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <div class="row">
                                                    <div class="col-md-12 mb-3 text-center">
                                                        <label class="form-label d-block fw-bold">صورة الموظف</label>
                                                        <input type="file" name="photo" class="form-control" accept="image/*">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">الاسم الكامل</label>
                                                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($emp['full_name']); ?>" required>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">رقم الهاتف</label>
                                                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($emp['phone']); ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">المسمى الوظيفي</label>
                                                        <select name="job_title_id" class="form-select" onchange="updateJobDetails(this.value, '<?php echo $emp['id']; ?>')" required>
                                                            <option value="">-- اختر المسمى الوظيفي --</option>
                                                            <?php foreach ($job_titles_list as $jt): ?>
                                                                <option value="<?php echo $jt['id']; ?>" <?php echo $emp['job_title_id'] == $jt['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($jt['title_name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">تاريخ بداية العمل</label>
                                                        <input type="date" name="hire_date" class="form-control" value="<?php echo $emp['hire_date']; ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">إجمالي الراتب</label>
                                                        <input type="number" step="0.01" id="salary_<?php echo $emp['id']; ?>" name="salary" class="form-control bg-light" readonly value="<?php echo number_format($emp['salary'] ?? 0, 2, '.', ''); ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">بداية الدوام</label>
                                                        <input type="time" id="shift_start_<?php echo $emp['id']; ?>" name="shift_start" class="form-control bg-light" readonly value="<?php echo $emp['shift_start'] ?? ''; ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">نهاية الدوام</label>
                                                        <input type="time" id="shift_end_<?php echo $emp['id']; ?>" name="shift_end" class="form-control bg-light" readonly value="<?php echo $emp['shift_end'] ?? ''; ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">فترات الدوام</label>
                                                        <div id="shift_details_<?php echo $emp['id']; ?>" class="form-control bg-light" style="min-height: 38px; height: auto;">
                                                            <?php 
                                                            if (!empty($emp['job_title_id'])) {
                                                                $relatedData = array_filter($job_salaries_data, function($item) use ($emp) {
                                                                    return $item['job_title_id'] == $emp['job_title_id'];
                                                                });
                                                                foreach ($relatedData as $item) {
                                                                    echo '<span class="badge bg-primary me-1">' . htmlspecialchars($item['shift_name']) . '</span>';
                                                                }
                                                                if (empty($relatedData)) echo '---';
                                                            } else {
                                                                echo '---';
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">البريد الإلكتروني</label>
                                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($emp['email']); ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">العنوان</label>
                                                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($emp['address']); ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">القسم</label>
                                                        <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($emp['department']); ?>">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">المدير المباشر</label>
                                                        <select name="manager_id" class="form-select">
                                                            <option value="">لا يوجد (مدير رئيسي)</option>
                                                            <?php foreach ($employees as $m): ?>
                                                                <?php if ($m['id'] != $emp['id']): ?>
                                                                    <option value="<?php echo $m['id']; ?>" <?php echo $emp['manager_id'] == $m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['full_name']); ?></option>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">الفرع</label>
                                                        <select name="branch_id" class="form-select">
                                                            <option value="">عام (المركز الرئيسي)</option>
                                                            <?php foreach ($branches as $b): ?>
                                                                <option value="<?php echo $b['id']; ?>" <?php echo $emp['branch_id'] == $b['id'] ? 'selected' : ''; ?>><?php echo $b['branch_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">الحساب المحاسبي (الشجرة الموحدة)</label>
                                                        <select name="account_id" class="form-select">
                                                            <option value="">-- إنشاء تلقائي --</option>
                                                            <?php 
                                                            // Get available accounts, excluding those used by other employees
                                                            $edit_available_accounts = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code LIKE BINARY '21103%' AND id NOT IN (SELECT account_id FROM employees WHERE account_id IS NOT NULL AND id != " . $emp['id'] . ")")->fetchAll();
                                                            foreach ($edit_available_accounts as $acc_item): 
                                                            ?>
                                                                <option value="<?php echo $acc_item['id']; ?>" <?php echo $emp['account_id'] == $acc_item['id'] ? 'selected' : ''; ?>><?php echo $acc_item['account_code'] . ' - ' . $acc_item['account_name_ar']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">الحالة</label>
                                                        <select name="status" class="form-select">
                                                            <option value="active" <?php echo $emp['status'] == 'active' ? 'selected' : ''; ?>>على رأس العمل</option>
                                                            <option value="inactive" <?php echo $emp['status'] == 'inactive' ? 'selected' : ''; ?>>متوقف</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label fw-bold">موقع الحضور المخصص</label>
                                                        <select name="attendance_location_id" class="form-select">
                                                            <option value="">-- أي موقع مسموح --</option>
                                                            <?php foreach ($attendance_locations as $loc): ?>
                                                                <option value="<?php echo $loc['id']; ?>" <?php echo $emp['attendance_location_id'] == $loc['id'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($loc['name']); ?> (<?php echo htmlspecialchars($loc['latitude']); ?>, <?php echo htmlspecialchars($loc['longitude']); ?> - نصف قطر: <?php echo htmlspecialchars($loc['radius_meters']); ?>م)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-12 mb-3">
                                                        <label class="form-label fw-bold">ملاحظات</label>
                                                        <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($emp['notes']); ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0">
                                                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                                                <button type="submit" name="update_employee" class="btn btn-primary rounded-pill px-4">حفظ التعديلات</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 rounded-4 shadow">
            <form method="POST" enctype="multipart/form-data">
                            <?php echo csrf_input(); ?>
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">إضافة موظف جديد</h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-12 mb-4 text-center">
                            <label class="form-label fw-bold d-block">صورة الموظف</label>
                            <div class="position-relative d-inline-block">
                                <div id="photoPreview" class="bg-light rounded-circle border d-flex align-items-center justify-content-center shadow-sm" style="width: 120px; height: 120px; overflow: hidden;">
                                    <i class="fas fa-user fa-3x text-muted"></i>
                                </div>
                                <label for="photoInput" class="btn btn-sm btn-primary rounded-circle position-absolute bottom-0 end-0 shadow">
                                    <i class="fas fa-camera"></i>
                                </label>
                                <input type="file" id="photoInput" name="photo" class="d-none" accept="image/*" onchange="previewImage(this)">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الاسم الكامل</label>
                            <input type="text" name="full_name" class="form-control" required placeholder="أدخل اسم الموظف الرباعي">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">رقم الهاتف</label>
                            <input type="text" name="phone" class="form-control" placeholder="00967...">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">المسمى الوظيفي</label>
                            <select name="job_title_id" class="form-select" onchange="updateJobDetails(this.value)" required>
                                <option value="">-- اختر المسمى الوظيفي --</option>
                                <?php foreach ($job_titles_list as $jt): ?>
                                    <option value="<?php echo $jt['id']; ?>"><?php echo htmlspecialchars($jt['title_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">تاريخ بداية العمل</label>
                            <input type="date" name="hire_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">إجمالي الراتب</label>
                            <input type="number" step="0.01" id="emp_salary" name="salary" class="form-control bg-light" readonly placeholder="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">بداية الدوام</label>
                            <input type="time" id="emp_shift_start" name="shift_start" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">نهاية الدوام</label>
                            <input type="time" id="emp_shift_end" name="shift_end" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">فترات الدوام</label>
                            <div id="shift_details" class="form-control bg-light" style="min-height: 38px; height: auto;">---</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">القسم</label>
                            <input type="text" name="department" class="form-control" placeholder="مثلاً: قسم التأشيرات">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">المدير المباشر</label>
                            <select name="manager_id" class="form-select">
                                <option value="">لا يوجد (مدير رئيسي)</option>
                                <?php foreach ($employees as $m): ?>
                                    <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحساب المحاسبي (الشجرة الموحدة)</label>
                            <select name="account_id" class="form-select">
                                <option value="">-- إنشاء تلقائي --</option>
                                <?php foreach ($available_accounts as $acc_item): ?>
                                    <option value="<?php echo $acc_item['id']; ?>"><?php echo $acc_item['account_code'] . ' - ' . $acc_item['account_name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الفرع</label>
                            <select name="branch_id" class="form-select">
                                <option value="">عام (المركز الرئيسي)</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" class="form-select">
                                <option value="active">على رأس العمل</option>
                                <option value="inactive">متوقف</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">موقع الحضور المخصص</label>
                            <select name="attendance_location_id" class="form-select">
                                <option value="">-- أي موقع مسموح --</option>
                                <?php foreach ($attendance_locations as $loc): ?>
                                    <option value="<?php echo $loc['id']; ?>">
                                        <?php echo htmlspecialchars($loc['name']); ?> (<?php echo htmlspecialchars($loc['latitude']); ?>, <?php echo htmlspecialchars($loc['longitude']); ?> - نصف قطر: <?php echo htmlspecialchars($loc['radius_meters']); ?>م)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_employee" class="btn btn-primary rounded-pill px-4">إضافة الموظف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal التسلسل الهرمي -->
<div class="modal fade" id="hierarchyModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-sitemap me-2"></i> التسلسل الهرمي للموظفين</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-end" dir="rtl">
                <div class="hierarchy-container">
                    <?php
                    if (!function_exists('renderEmployeeHierarchy')) {
                        function renderEmployeeHierarchy($employees, $managerId = null, $level = 0) {
                            $found = false;
                            foreach ($employees as $emp) {
                                if ($emp['manager_id'] == $managerId) {
                                    if (!$found) {
                                        echo '<ul class="list-unstyled ms-' . ($level > 0 ? '4 border-start ps-3' : '0') . '">';
                                        $found = true;
                                    }
                                    echo '<li class="mb-3">';
                                    echo '<div class="card border-0 shadow-sm rounded-3 p-3 bg-white d-inline-block" style="min-width: 250px;">';
                                    echo '<div class="d-flex align-items-center">';
                                    echo '<div class="avatar-sm bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;"><i class="fas fa-user"></i></div>';
                                    echo '<div>';
                                    echo '<div class="fw-bold">' . htmlspecialchars($emp['full_name']) . '</div>';
                                    $title = !empty($emp['job_title_name']) ? $emp['job_title_name'] : (!empty($emp['job_title']) ? $emp['job_title'] : 'غير محدد');
                                    echo '<small class="text-muted">' . htmlspecialchars($title) . ' (' . htmlspecialchars($emp['department'] ?: 'بدون قسم') . ')</small>';
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                    renderEmployeeHierarchy($employees, $emp['id'], $level + 1);
                                    echo '</li>';
                                }
                            }
                            if ($found) echo '</ul>';
                        }
                    }
                    
                    if (empty($employees)) {
                        echo '<div class="text-center py-5 text-muted">لا توجد بيانات موظفين</div>';
                    } else {
                        renderEmployeeHierarchy($employees);
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// بحث ديناميكي في الجدول
document.getElementById('employeeSearch').addEventListener('keyup', function() {
    var value = this.value.toLowerCase();
    var rows = document.querySelectorAll('#employeesTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().indexOf(value) > -1 ? '' : 'none';
    });
});
function markAttendance(empId, action) {
    // Function to get browser info
    function getBrowserInfo() {
        const ua = navigator.userAgent;
        let browserName = "Unknown";
        if (ua.indexOf("Firefox") > -1) browserName = "Firefox";
        else if (ua.indexOf("SamsungBrowser") > -1) browserName = "Samsung Browser";
        else if (ua.indexOf("Opera") > -1 || ua.indexOf("OPR") > -1) browserName = "Opera";
        else if (ua.indexOf("Trident") > -1) browserName = "Internet Explorer";
        else if (ua.indexOf("Edge") > -1) browserName = "Edge";
        else if (ua.indexOf("Chrome") > -1) browserName = "Chrome";
        else if (ua.indexOf("Safari") > -1) browserName = "Safari";
        return browserName;
    }

    // Function to get device info
    function getDeviceInfo() {
        const ua = navigator.userAgent;
        let device = "Unknown Device";
        if (/Android/i.test(ua)) device = "Android";
        else if (/BlackBerry/i.test(ua)) device = "BlackBerry";
        else if (/iPhone|iPad|iPod/i.test(ua)) device = "iOS";
        else if (/Opera Mini/i.test(ua)) device = "Opera Mini";
        else if (/Windows Phone/i.test(ua)) device = "Windows Phone";
        else if (/Windows NT 10.0/i.test(ua)) device = "Windows 10/11";
        else if (/Windows NT 6.3/i.test(ua)) device = "Windows 8.1";
        else if (/Windows NT 6.2/i.test(ua)) device = "Windows 8";
        else if (/Windows NT 6.1/i.test(ua)) device = "Windows 7";
        else if (/Macintosh|Mac OS X/i.test(ua)) device = "macOS";
        else if (/Linux/i.test(ua)) device = "Linux";
        return device;
    }

    // Show loading while getting location
    Swal.fire({
        title: 'جارٍ الحصول على موقعك...',
        text: 'يرجى الانتظار بينما نقوم بتحديد موقعك',
        timer: 10000,
        timerProgressBar: true,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    // Get geolocation FIRST (so browser asks for location permission!)
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const latitude = position.coords.latitude;
                const longitude = position.coords.longitude;
                const browser = getBrowserInfo();
                const device = getDeviceInfo();

                // Now show confirm dialog!
                Swal.fire({
                    title: action === 'check_in' ? 'تحضير دخول؟' : 'تسجيل انصراف؟',
                    text: "هل تريد تأكيد هذه العملية؟",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'نعم، قم بالتنفيذ',
                    cancelButtonText: 'إلغاء'
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitAttendance(empId, action, latitude, longitude, browser, device);
                    }
                });
            },
            (error) => {
                Swal.close(); // Close the loading dialog first
                if (error.code === error.PERMISSION_DENIED) {
                    // Show detailed instructions modal
                    locationPermModal.show();
                } else {
                    let errorMsg = '';
                    switch(error.code) {
                        case error.POSITION_UNAVAILABLE:
                            errorMsg = "لم يتم تحديد الموقع، يرجى المحاولة مرة أخرى";
                            break;
                        case error.TIMEOUT:
                            errorMsg = "الطلب استغرق وقتاً طويلاً، يرجى المحاولة مرة أخرى";
                            break;
                        case error.UNKNOWN_ERROR:
                            errorMsg = "حدث خطأ غير معروف أثناء محاولة جلب الموقع";
                            break;
                    }
                    Swal.fire('خطأ في جلب الموقع', errorMsg, 'error');
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    } else {
        Swal.fire('خطأ', 'المتصفح لا يدعم خدمة الجغرافيا', 'error');
    }

    function submitAttendance(empId, action, lat, lng, browser, device) {
        fetch("ajax_attendance.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "employee_id=" + empId + "&action=" + action + "&latitude=" + encodeURIComponent(lat) + "&longitude=" + encodeURIComponent(lng) + "&browser=" + encodeURIComponent(browser) + "&device=" + encodeURIComponent(device)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let msg = data.message;
                if (data.late > 0) {
                    msg += `\nتأخير: ${data.late} دقيقة\nخصم: ${data.deduction}`;
                }
                Swal.fire({
                    title: 'تم بنجاح',
                    text: msg,
                    icon: 'success'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('خطأ', data.message || 'حدث خطأ في التحضير', 'error');
            }
        })
        .catch(error => {
            Swal.fire('خطأ', 'حدث خطأ في الاتصال بالخادم', 'error');
        });
    }
}

// بيانات الرواتب والفترات من PHP
const jobSalariesData = <?php echo $job_salaries_json; ?>;

function updateJobDetails(jobTitleId, empId = '') {
    const salaryInput = empId ? document.getElementById('salary_' + empId) : document.getElementById('emp_salary');
    const startInput = empId ? document.getElementById('shift_start_' + empId) : document.getElementById('emp_shift_start');
    const endInput = empId ? document.getElementById('shift_end_' + empId) : document.getElementById('emp_shift_end');
    const detailsDiv = empId ? document.getElementById('shift_details_' + empId) : document.getElementById('shift_details');
    
    if (!jobTitleId) {
        salaryInput.value = '0.00';
        startInput.value = '';
        endInput.value = '';
        detailsDiv.innerHTML = '---';
        return;
    }
    
    const relatedData = jobSalariesData.filter(item => item.job_title_id == jobTitleId);
    
    if (relatedData.length > 0) {
        // حساب إجمالي الراتب
        let totalSalary = 0;
        let shiftsHtml = '';
        let minStart = relatedData[0].start_time;
        let maxEnd = relatedData[0].end_time;
        
        relatedData.forEach((item, index) => {
            totalSalary += parseFloat(item.salary);
            shiftsHtml += `<span class="badge bg-primary me-1">${item.shift_name}</span>`;
            
            if (item.start_time < minStart) minStart = item.start_time;
            if (item.end_time > maxEnd) maxEnd = item.end_time;
        });
        
        salaryInput.value = totalSalary.toFixed(2);
        startInput.value = minStart;
        endInput.value = maxEnd;
        detailsDiv.innerHTML = shiftsHtml + ` <small class="text-muted">(${relatedData.length} فترة)</small>`;
    } else {
        salaryInput.value = '0.00';
        startInput.value = '';
        endInput.value = '';
        detailsDiv.innerHTML = '<span class="text-danger small">لا توجد فترات مرتبطة</span>';
    }
}

function previewImage(input) {
    const preview = document.getElementById('photoPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" class="w-100 h-100 object-fit-cover">`;
        }
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.innerHTML = `<i class="fas fa-user fa-3x text-muted"></i>`;
    }
}

function requestLocationPermission() {
    // Check if we're in a secure context (for warning purposes only)
    const isSecure = window.isSecureContext;
    const isLocalhost = window.location.hostname === 'localhost' || 
                       window.location.hostname === '127.0.0.1' ||
                       window.location.hostname === '::1';
    
    // Function to show secure context warning
    function showSecureContextWarning() {
        const btn = document.getElementById('locationPermissionBtn');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> قد تحتاج HTTPS أو localhost';
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-warning');
            btn.onclick = function() {
                alert('خدمة تحديد الموقع في معظم المتصفحات تعمل فقط عبر HTTPS أو على localhost.\n\nالرجاء:\n1. استخدام https:// بدلاً من http://\n2. أو استخدام localhost بدلاً من الـ IP\n3. أو في Chrome: اذهب إلى chrome://flags/#unsafely-treat-insecure-origin-as-secure وأضف http://192.168.0.93:8000');
            };
        }
    }
    
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                // Permission granted, show success and hide button
                alert('تم السماح بالوصول للموقع بنجاح!');
                const btn = document.getElementById('locationPermissionBtn');
                if (btn) btn.style.display = 'none';
            },
            function(error) {
                if (error.code === error.PERMISSION_DENIED) {
                    // Show detailed instructions modal
                    locationPermModal.show();
                } else {
                    // For other errors, if not secure, show warning too
                    if (!isSecure && !isLocalhost) {
                        showSecureContextWarning();
                    } else {
                        let errorMsg;
                        switch(error.code) {
                            case error.POSITION_UNAVAILABLE:
                                errorMsg = 'لم يتم تحديد الموقع.';
                                break;
                            case error.TIMEOUT:
                                errorMsg = 'الطلب استغرق وقتاً طويلاً.';
                                break;
                            default:
                                errorMsg = 'حدث خطأ غير معروف.';
                        }
                        alert(errorMsg);
                    }
                }
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    } else {
        showSecureContextWarning();
    }
}

let locationPermModal;

// Function to check geolocation permission and update button visibility
async function checkGeolocationPermission() {
    const btn = document.getElementById('locationPermissionBtn');
    if (!btn) return;
    
    if (!navigator.geolocation || !navigator.permissions) {
        // If permissions API is not available, show the button by default
        btn.style.display = '';
        return;
    }
    
    try {
        const permission = await navigator.permissions.query({ name: 'geolocation' });
        
        // Update button visibility based on permission state
        if (permission.state === 'granted') {
            btn.style.display = 'none'; // Hide button if permission granted
        } else {
            btn.style.display = ''; // Show button if denied or prompt
        }
        
        // Listen for permission changes
        permission.onchange = function() {
            if (this.state === 'granted') {
                btn.style.display = 'none';
            } else {
                btn.style.display = '';
            }
        };
    } catch (error) {
        // If there's an error, show the button by default
        btn.style.display = '';
    }
}

// Request location permission on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize location permission modal
    locationPermModal = new bootstrap.Modal(document.getElementById('locationPermissionModal'));
    
    // Check geolocation permission and update button
    checkGeolocationPermission();
    
    // Request location permission automatically on page load (for attendance features)
    setTimeout(function() {
        // Always try geolocation, even on HTTP
        if (navigator.geolocation) {
            requestLocationPermission();
        }
    }, 500); // Small delay to let the page load
});
</script>

    <!-- Location Permission Instructions Modal -->
    <div class="modal fade" id="locationPermissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">رجاءً السماح بالوصول للموقع</h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        للتمكن من تسجيل الحضور والانصراف، تحتاج إلى السماح للمتصفح بالوصول إلى موقعك.
                    </div>

                    <!-- Android Chrome Instructions -->
                    <div class="mb-3">
                        <h6 class="fw-bold text-primary mb-2">لأجهزة أندرويد (Chrome):</h6>
                        <ol class="pe-3">
                            <li>اضغط على الأيقونة <i class="fas fa-lock"></i> أو <i class="fas fa-info-circle"></i> بجانب عنوان الصفحة في أعلى المتصفح</li>
                            <li>اختر "إعدادات الموقع" أو "الموقع"</li>
                            <li>اختر "سماح"</li>
                        </ol>
                    </div>

                    <!-- iOS Safari Instructions -->
                    <div class="mb-3">
                        <h6 class="fw-bold text-primary mb-2">لأجهزة أبل (Safari):</h6>
                        <ol class="pe-3">
                            <li>اذهب إلى "إعدادات" جهازك</li>
                            <li>ابحث عن "Safari" واضغط عليه</li>
                            <li>اختر "الموقع"</li>
                            <li>اختر "أثناء الاستخدام" أو "سماح"</li>
                        </ol>
                    </div>

                    <!-- Desktop Chrome/Firefox Instructions -->
                    <div class="mb-3">
                        <h6 class="fw-bold text-primary mb-2">لأجهزة سطح المكتب:</h6>
                        <ol class="pe-3">
                            <li>اضغط على الأيقونة <i class="fas fa-lock"></i> أو <i class="fas fa-info-circle"></i> بجانب عنوان الصفحة</li>
                            <li>ابحث عن إعدادات "الموقع" أو "Location"</li>
                            <li>اختر "سماح" أو "Allow"</li>
                        </ol>
                    </div>

                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-lightbulb me-2"></i>
                        بعد السماح بالوصول للموقع، يرجى تحديث الصفحة والمحاولة مرة أخرى.
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-primary" onclick="location.reload()">تحديث الصفحة</button>
                </div>
            </div>
        </div>
    </div>

<?php 
require_once 'footer.php'; 
ob_end_flush();
?>
