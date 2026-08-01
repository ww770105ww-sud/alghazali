<?php

/**
 * إدارة الأدوار والصلاحيات الموحدة - وكالة الغزالي للسفريات والسياحة
 * تم التحديث لاستخدام جداول الصلاحيات الموحدة (unified_permissions)
 */

require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$settings = getSettings($pdo);

// 1. التحقق من الصلاحية
$current_admin_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
$user_role_id = $_SESSION['role_id'] ?? 0;
// المطور (developer) له كل الصلاحيات، عادة يكون role_id=2 أو اسمه developer
$is_super_user = (strtolower($current_admin_role) === 'developer' || $user_role_id == 2 || strtolower($current_admin_role) === 'admin');

if (!$is_super_user && !has_permission('roles_view')) {
    header('Location: index.php?error=no_permission');
    exit();
}

// 2. دوال مساعدة
function validate_role_name($name)
{
    return preg_match('/^[a-zA-Z0-9_]+$/', $name);
}

function get_category_name($cat, $translations)
{
    return $translations[$cat] ?? ($cat ?: 'أخرى');
}

// جلب جميع الصلاحيات من unified_permissions
function getAllPermissions($pdo) { 
    $stmt = $pdo->query("SELECT * FROM unified_permissions WHERE is_active = 1 ORDER BY category, display_name"); 
    return $stmt->fetchAll(PDO::FETCH_ASSOC); 
} 

// جلب الصلاحيات حسب الفئة
function getPermissionsGrouped($pdo) { 
    $permissions = getAllPermissions($pdo); 
    $grouped = []; 
    foreach ($permissions as $p) { 
        $cat = $p['category'] ?: 'other'; 
        $grouped[$cat][] = $p; 
    } 
    return $grouped; 
} 

// جلب صلاحيات دور معين
function getRolePermissions($pdo, $role_id) { 
    $stmt = $pdo->prepare(" 
        SELECT permission_id FROM role_permissions_unified 
        WHERE role_id = ? AND (target_type IS NULL OR target_type = '') 
    "); 
    $stmt->execute([$role_id]); 
    return $stmt->fetchAll(PDO::FETCH_COLUMN); 
} 

// إضافة صلاحيات لدور
function addRolePermissions($pdo, $role_id, $permission_ids, $granted_by) { 
    $stmt = $pdo->prepare("INSERT INTO role_permissions_unified (role_id, permission_id, granted_by, granted_at) VALUES (?, ?, ?, NOW())"); 
    foreach ($permission_ids as $perm_id) { 
        $stmt->execute([$role_id, $perm_id, $granted_by]); 
    } 
} 

// تحديث صلاحيات دور (حذف ثم إضافة)
function updateRolePermissions($pdo, $role_id, $permission_ids, $granted_by) { 
    $pdo->prepare("DELETE FROM role_permissions_unified WHERE role_id = ? AND (target_type IS NULL OR target_type = '')")->execute([$role_id]); 
    if (!empty($permission_ids)) {
        addRolePermissions($pdo, $role_id, $permission_ids, $granted_by); 
    }
} 

// التحقق من الصلاحية (لإظهار/إخفاء الأزرار) 
function hasPermissionLocal($permission_code, $role_id, $branch_id = null) { 
    global $pdo; 
    // مطور له كل الصلاحيات 
    if ($role_id == 2) return true; 
    
    $stmt = $pdo->prepare(" 
        SELECT COUNT(*) FROM role_permissions_unified rp 
        JOIN unified_permissions p ON rp.permission_id = p.id 
        WHERE rp.role_id = ? AND p.permission_code = ? 
        AND (rp.target_type IS NULL OR rp.target_type != 'branch' OR rp.target_id = ?) 
    "); 
    $stmt->execute([$role_id, $permission_code, $branch_id]); 
    return $stmt->fetchColumn() > 0; 
} 

// 3. إعدادات ومصفوفات
$category_translations = [
    'general' => 'عام',
    'users' => 'إدارة المستخدمين',
    'roles' => 'الأدوار والمستخدمين',
    'finance' => 'المالية والسندات',
    'accounting' => 'المحاسبة والتقارير',
    'umrah' => 'قسم العمرة',
    'work_visa' => 'تأشيرات العمل',
    'bookings' => 'الحجوزات',
    'passports' => 'الجوازات والمعاملات',
    'entities' => 'إدارة الأطراف',
    'workflow' => 'سير العمل',
    'settings' => 'إعدادات النظام',
    'reports' => 'التقارير',
    'other' => 'أخرى'
];

$permission_groups = [
    'admin' => [
        'title' => 'الصلاحيات الإدارية',
        'icon' => 'fa-user-shield',
        'categories' => ['roles', 'users', 'entities', 'workflow', 'settings', 'other']
    ],
    'finance' => [
        'title' => 'الصلاحيات المالية',
        'icon' => 'fa-file-invoice-dollar',
        'categories' => ['finance', 'accounting']
    ],
    'operations' => [
        'title' => 'صلاحيات العمليات',
        'icon' => 'fa-briefcase',
        'categories' => ['umrah', 'work_visa', 'bookings', 'passports']
    ]
];

$message = '';
$message_type = '';

// 4. معالجة الطلبات (POST & GET)

// إضافة دور جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_role'])) {
    if (!has_permission('roles_create')) {
        $message = "ليس لديك صلاحية لإضافة دور جديد";
        $message_type = "danger";
    } else {
        $name = trim($_POST['name'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $max_discount = floatval($_POST['max_discount_percentage'] ?? 0);

        if (empty($name) || empty($display_name)) {
            $message = "الاسم واسم العرض مطلوبان.";
            $message_type = "danger";
        } elseif (!validate_role_name($name)) {
            $message = "اسم الدور يجب أن يحتوي فقط على أحرف إنجليزية وأرقام وشرطة سفلية.";
            $message_type = "danger";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                $message = "اسم الدور موجود مسبقاً.";
                $message_type = "danger";
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO roles (name, display_name, description, max_discount_percentage) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $display_name, $description, $max_discount]);
                    $role_id = $pdo->lastInsertId();

                    if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                        addRolePermissions($pdo, $role_id, $_POST['permissions'], $_SESSION['admin_id']);
                    }
                    
                    $stmt_new = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
                    $stmt_new->execute([$role_id]);
                    $new_role = $stmt_new->fetch();
                    $new_role['permissions'] = $_POST['permissions'] ?? [];
                    
                    log_audit($pdo, 'create', 'roles', $role_id, null, $new_role, "إضافة دور جديد");
                    $pdo->commit();
                    header("Location: roles.php?success=1");
                    exit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "حدث خطأ أثناء الإضافة.";
                    $message_type = "danger";
                }
            }
        }
    }
}

// تحديث دور
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    if (!has_permission('roles_edit')) {
        $message = "ليس لديك صلاحية لتعديل بيانات الدور";
        $message_type = "danger";
    } else {
        $role_id = intval($_POST['id'] ?? 0);
        $display_name = trim($_POST['display_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $max_discount = floatval($_POST['max_discount_percentage'] ?? 0);

        if ($role_id <= 0 || empty($display_name)) {
            $message = "البيانات غير مكتملة.";
            $message_type = "danger";
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt_old = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
                $stmt_old->execute([$role_id]);
                $old_role = $stmt_old->fetch(); 

                $stmt = $pdo->prepare("UPDATE roles SET display_name = ?, description = ?, max_discount_percentage = ? WHERE id = ?");
                $stmt->execute([$display_name, $description, $max_discount, $role_id]);

                $permissions = $_POST['permissions'] ?? [];
                updateRolePermissions($pdo, $role_id, $permissions, $_SESSION['admin_id']);
                
                $stmt_new = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
                $stmt_new->execute([$role_id]);
                $new_role = $stmt_new->fetch();
                $new_role['permissions'] = $permissions;

                log_audit($pdo, 'update', 'roles', $role_id, $old_role, $new_role, "تعديل بيانات الدور");

                $pdo->commit();
                header("Location: roles.php?success=2");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "حدث خطأ أثناء التحديث.";
                $message_type = "danger";
            }
        }
    }
}

// حذف دور
if (isset($_GET['delete_role'])) {
    if (!has_permission('roles_delete')) {
        $message = "ليس لديك صلاحية لحذف الدور";
        $message_type = "danger";
    } else {
        $role_id = intval($_GET['delete_role']);
        try {
            $stmt_check = $pdo->prepare("SELECT is_system FROM roles WHERE id = ?");
            $stmt_check->execute([$role_id]);
            $role_data = $stmt_check->fetch();

            if (!$role_data) {
                $message = "الدور غير موجود.";
                $message_type = "danger";
            } elseif ($role_data['is_system']) {
                $message = "لا يمكن حذف الأدوار النظامية.";
                $message_type = "danger";
            } else {
                $check_users = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
                $check_users->execute([$role_id]);
                if ($check_users->fetchColumn() > 0) {
                    $message = "لا يمكن الحذف لوجود مستخدمين.";
                    $message_type = "danger";
                } else {
                    $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$role_id]);
                    header("Location: roles.php?success=3");
                    exit();
                }
            }
        } catch (Exception $e) {
            $message = "حدث خطأ أثناء الحذف.";
            $message_type = "danger";
        }
    }
}

// حفظ صلاحيات المحرر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_editor_permissions'])) {
    if (!$is_super_user) {
        $message = "ليس لديك صلاحية لتعديل الصلاحيات";
        $message_type = "danger";
    } else {
        try {
            $editor_perms = ['allow_editor_batches', 'allow_editor_passports', 'allow_editor_statuses', 'allow_editor_services', 'allow_editor_currencies', 'allow_editor_news', 'allow_editor_messages', 'allow_editor_subscribers', 'allow_editor_home_content', 'allow_editor_pages', 'allow_editor_slider', 'allow_editor_users', 'allow_editor_settings', 'allow_editor_work_visa', 'allow_editor_delete'];
            foreach ($editor_perms as $perm) {
                $value = isset($_POST[$perm]) ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'permissions') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$perm, $value]);
            }
            $message = "تم حفظ صلاحيات المحرر بنجاح";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "حدث خطأ أثناء الحفظ";
            $message_type = "danger";
        }
    }
}

require_once 'header.php';

// 5. جلب البيانات للعرض
$roles = $pdo->query("
    SELECT r.*, COUNT(rp.permission_id) as permissions_count
    FROM roles r
    LEFT JOIN role_permissions_unified rp ON r.id = rp.role_id AND (rp.target_type IS NULL OR rp.target_type = '')
    WHERE r.name NOT IN ('developer', 'مطور')
    GROUP BY r.id
    ORDER BY r.id ASC
")->fetchAll();

$grouped_permissions = getPermissionsGrouped($pdo);

$roles_perms_sample = [];
if (!empty($roles)) {
    $role_ids = array_column($roles, 'id');
    $placeholders = implode(',', array_fill(0, count($role_ids), '?'));
    $stmt_samples = $pdo->prepare("
        SELECT rp.role_id, p.display_name
        FROM role_permissions_unified rp
        JOIN unified_permissions p ON rp.permission_id = p.id
        WHERE rp.role_id IN ($placeholders) AND (rp.target_type IS NULL OR rp.target_type = '')
    ");
    $stmt_samples->execute($role_ids);
    while ($row = $stmt_samples->fetch()) {
        if (!isset($roles_perms_sample[$row['role_id']])) $roles_perms_sample[$row['role_id']] = [];
        if (count($roles_perms_sample[$row['role_id']]) < 4) {
            $roles_perms_sample[$row['role_id']][] = $row['display_name'];
        }
    }
}

$role_current_perms = [];
$stmt_current = $pdo->query("SELECT role_id, permission_id FROM role_permissions_unified WHERE target_type IS NULL OR target_type = ''");
while ($row = $stmt_current->fetch()) {
    $role_current_perms[$row['role_id']][] = $row['permission_id'];
}

if (isset($_GET['success'])) {
    $message_type = "success";
    switch ($_GET['success']) {
        case 1: $message = "تمت إضافة الدور الجديد بنجاح."; break;
        case 2: $message = "تم تحديث بيانات الدور بنجاح."; break;
        case 3: $message = "تم حذف الدور بنجاح."; break;
    }
}

$active_tab = $_GET['info'] ?? 'roles_list';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-user-shield me-2"></i> إدارة الأدوار والصلاحيات الموحدة</h3>
            <p class="text-muted small mb-0">التحكم في أدوار المستخدمين وصلاحياتهم وفق النظام الموحد</p>
        </div>
        <?php if (has_permission('roles_create')): ?>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                <i class="fas fa-plus-circle me-1"></i> إضافة دور جديد
            </button>
        <?php endif; ?>
    </div>

    <ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm border" id="rolesTabs">
        <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo $active_tab === 'roles_list' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#roles_list"><i class="fas fa-users-cog me-2"></i> إدارة الأدوار</button></li>
        <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo $active_tab === 'perm_matrix' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#perm_matrix"><i class="fas fa-table me-2"></i> مصفوفة الصلاحيات</button></li>
        <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo $active_tab === 'unified_financial_settings' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#unified_financial_settings"><i class="fas fa-file-invoice-dollar me-2"></i> الصلاحيات المالية</button></li>
        <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo $active_tab === 'editor_perms' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#editor_perms"><i class="fas fa-user-edit me-2"></i> صلاحيات المحرر</button></li>
        <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo $active_tab === 'users_stats' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#users_stats"><i class="fas fa-chart-pie me-2"></i> إحصائيات التوزيع</button></li>
    </ul>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show rounded-4 shadow-sm mb-4">
            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="tab-content">
        <!-- Tab 1: Roles List -->
        <div class="tab-pane fade <?php echo $active_tab === 'roles_list' ? 'show active' : ''; ?>" id="roles_list">
            <div class="row g-4">
                <?php foreach ($roles as $role):
                    $rid = $role['id'];
                    $is_sys = $role['is_system'];
                    $current_role_perms = $role_current_perms[$rid] ?? [];
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0 rounded-4 hover-shadow transition-all">
                            <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex justify-content-between">
                                <div>
                                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($role['display_name']); ?></h5>
                                    <span class="badge bg-light text-secondary border small"><?php echo htmlspecialchars($role['name']); ?></span>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm rounded-circle" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v text-muted"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow rounded-3">
                                        <?php if (has_permission('roles_edit')): ?>
                                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#editRoleModal<?php echo $rid; ?>"><i class="fas fa-edit me-2 text-primary"></i> تعديل</a></li>
                                        <?php endif; ?>
                                        <?php if (has_permission('roles_delete') && !$is_sys): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="roles.php?delete_role=<?php echo $rid; ?>" onclick="return confirm('حذف الدور؟')"><i class="fas fa-trash me-2"></i> حذف</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                            <div class="card-body px-4">
                                <p class="text-muted small mb-3"><?php echo htmlspecialchars($role['description'] ?: 'لا يوجد وصف'); ?></p>
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small fw-bold text-primary">الصلاحيات المفعّلة:</span>
                                    <span class="badge bg-success rounded-pill"><?php echo $role['permissions_count']; ?></span>
                                </div>
                                <div class="d-flex flex-wrap gap-1" style="max-height: 120px; overflow-y: auto; align-content: flex-start; padding: 5px; background: rgba(0,0,0,0.02); border-radius: 10px;">
                                    <?php
                                    $stmt_active = $pdo->prepare("
                                        SELECT p.display_name
                                        FROM role_permissions_unified rp
                                        JOIN unified_permissions p ON rp.permission_id = p.id
                                        WHERE rp.role_id = ? AND (rp.target_type IS NULL OR rp.target_type = '')
                                        ORDER BY p.category, p.display_name
                                    ");
                                    $stmt_active->execute([$rid]);
                                    $active_perms = $stmt_active->fetchAll(PDO::FETCH_COLUMN);
                                    
                                    if (!empty($active_perms)) {
                                        foreach ($active_perms as $s_name) {
                                            echo '<span class="badge bg-white text-primary border border-primary-subtle extra-small mb-1" style="font-weight: 500;">' . htmlspecialchars($s_name) . '</span>';
                                        }
                                    } else {
                                        echo '<span class="text-muted extra-small">لا توجد صلاحيات مفعّلة</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="card-footer bg-light border-top-0 p-3 rounded-bottom-4">
                                <button class="btn btn-outline-primary w-100 rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#editRoleModal<?php echo $rid; ?>">
                                    <i class="fas fa-cog me-2"></i> إدارة الصلاحيات
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Modal تعديل دور لكل دور في القائمة -->
                    <div class="modal fade role-edit-modal" id="editRoleModal<?php echo $rid; ?>" tabindex="-1">
                        <div class="modal-dialog modal-xl modal-dialog-scrollable">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                                <div class="modal-header bg-primary text-white border-0 py-3">
                                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل دور: <?php echo htmlspecialchars($role['display_name']); ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                    <div class="modal-body p-4 bg-light">
                                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                                            <div class="card-body p-4">
                                                <div class="row g-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold small">الاسم المعروض</label>
                                                        <input type="text" name="display_name" class="form-control rounded-3" value="<?php echo htmlspecialchars($role['display_name']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold small">نسبة الخصم القصوى (%)</label>
                                                        <input type="number" step="0.01" name="max_discount_percentage" class="form-control rounded-3" value="<?php echo $role['max_discount_percentage']; ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold small">الوصف</label>
                                                        <input type="text" name="description" class="form-control rounded-3" value="<?php echo htmlspecialchars($role['description']); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <h6 class="fw-bold mb-3"><i class="fas fa-lock me-2"></i> تعديل الصلاحيات</h6>
                                        <div class="row g-4">
                                            <?php foreach ($permission_groups as $g_key => $group): ?>
                                                <div class="col-12">
                                                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                                                        <div class="card-header bg-primary text-white py-2 px-3 d-flex align-items-center justify-content-between">
                                                            <h6 class="mb-0 small fw-bold"><i class="fas <?php echo $group['icon']; ?> me-2"></i> <?php echo $group['title']; ?></h6>
                                                            <div class="form-check form-switch m-0">
                                                                <input class="form-check-input group-select-all-cb" type="checkbox" id="group_sel_edit_<?php echo $rid; ?>_<?php echo $g_key; ?>" data-accordion="#accordion_edit_<?php echo $rid . '_' . $g_key; ?>" style="cursor:pointer;">
                                                                <label class="form-check-label small fw-bold ms-2" for="group_sel_edit_<?php echo $rid; ?>_<?php echo $g_key; ?>" style="cursor:pointer; font-size: 0.8rem;">تفعيل المجموعة كاملة</label>
                                                            </div>
                                                        </div>
                                                        <div class="card-body p-0">
                                                            <div class="accordion accordion-flush perm-accordion-group" id="accordion_edit_<?php echo $rid . '_' . $g_key; ?>" data-group-master="group_sel_edit_<?php echo $rid; ?>_<?php echo $g_key; ?>">
                                                                <?php 
                                                                $idx_g = 0;
                                                                foreach ($group['categories'] as $cat):
                                                                    if (!isset($grouped_permissions[$cat])) continue;
                                                                    $perms = $grouped_permissions[$cat];
                                                                    $cat_name = get_category_name($cat, $category_translations);
                                                                    $collapseId = "collapse_edit_{$rid}_{$g_key}_{$idx_g}";
                                                                ?>
                                                                    <div class="accordion-item">
                                                                        <h2 class="accordion-header">
                                                                            <div class="d-flex align-items-center py-2 px-3" style="gap: 12px;">
                                                                                <div class="form-check form-switch m-0 p-0 select-all-wrapper" data-target="#<?php echo $collapseId; ?>">
                                                                                    <input class="form-check-input select-all-cb" type="checkbox" id="select_all_edit_<?php echo $rid; ?>_<?php echo $g_key; ?>_<?php echo $idx_g; ?>" style="cursor:pointer;">
                                                                                    <label class="form-check-label small fw-bold text-muted" for="select_all_edit_<?php echo $rid; ?>_<?php echo $g_key; ?>_<?php echo $idx_g; ?>" style="cursor:pointer;">تحديد الكل</label>
                                                                                </div>
                                                                                <button class="accordion-button collapsed small fw-bold flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>">
                                                                                    <?php echo $cat_name; ?> <span class="badge bg-light text-primary border rounded-pill ms-auto extra-small"><?php echo count($perms); ?></span>
                                                                                </button>
                                                                            </div>
                                                                        </h2>
                                                                        <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse" data-bs-parent="#accordion_edit_<?php echo $rid . '_' . $g_key; ?>">
                                                                            <div class="accordion-body p-3">
                                                                                <div class="row g-2 perm-group" data-parent="select_all_edit_<?php echo $rid; ?>_<?php echo $g_key; ?>_<?php echo $idx_g; ?>">
                                                                                    <?php foreach ($perms as $p): 
                                                                                        $is_checked = in_array($p['id'], $current_role_perms);
                                                                                    ?>
                                                                                        <div class="col-md-4">
                                                                                            <label class="perm-card mb-2 <?php echo $is_checked ? 'is-active' : ''; ?>" for="p_e_<?php echo $rid; ?>_<?php echo $p['id']; ?>">
                                                                                                <span class="perm-title"><?php echo htmlspecialchars($p['display_name']); ?></span>
                                                                                                <input class="form-check-input perm-checkbox" type="checkbox" name="permissions[]" value="<?php echo $p['id']; ?>" id="p_e_<?php echo $rid; ?>_<?php echo $p['id']; ?>" <?php echo $is_checked ? 'checked' : ''; ?> data-original="<?php echo $is_checked ? '1' : '0'; ?>">
                                                                                            </label>
                                                                                        </div>
                                                                                    <?php endforeach; ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php $idx_g++; endforeach; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="modal-footer modal-footer-sticky bg-white border-top shadow-sm py-3 px-4">
                                        <div class="d-flex gap-2 w-100 justify-content-end">
                                            <button type="button" class="btn btn-outline-secondary rounded-pill px-5 fw-bold" data-bs-dismiss="modal">
                                                <i class="fas fa-times me-1"></i> إلغاء
                                            </button>
                                            <button type="submit" name="update_role" id="saveChangesBtn_<?php echo $rid; ?>" class="btn btn-primary rounded-pill px-6 fw-bold shadow-sm" disabled>
                                                <i class="fas fa-save me-1"></i> حفظ التغييرات
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tab 1.5: Permission Matrix -->
        <div class="tab-pane fade <?php echo $active_tab === 'perm_matrix' ? 'show active' : ''; ?>" id="perm_matrix">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="fas fa-table me-2 text-primary"></i> مصفوفة الصلاحيات الشاملة</h5>
                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="window.location.reload()"><i class="fas fa-sync-alt me-1"></i> تحديث البيانات</button>
                </div>
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-sm table-hover align-middle border sticky-header">
                        <thead class="bg-primary text-white sticky-top">
                            <tr>
                                <th class="px-3 py-2" style="min-width: 250px;">الصلاحية / الفئة</th>
                                <?php foreach ($roles as $role): ?>
                                    <th class="text-center px-2"><?php echo htmlspecialchars($role['display_name']); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permission_groups as $g_key => $group): ?>
                                <tr class="bg-light">
                                    <td colspan="<?php echo count($roles) + 1; ?>" class="fw-bold py-2 px-3 text-primary">
                                        <i class="fas <?php echo $group['icon']; ?> me-2"></i> <?php echo $group['title']; ?>
                                    </td>
                                </tr>
                                <?php 
                                foreach ($group['categories'] as $cat):
                                    if (!isset($grouped_permissions[$cat])) continue;
                                    $perms = $grouped_permissions[$cat];
                                    $cat_name = get_category_name($cat, $category_translations);
                                ?>
                                    <tr class="bg-white">
                                        <td colspan="<?php echo count($roles) + 1; ?>" class="ps-4 py-1 small fw-bold text-muted border-bottom">
                                            -- <?php echo $cat_name; ?>
                                        </td>
                                    </tr>
                                    <?php foreach ($perms as $p): ?>
                                        <tr>
                                            <td class="ps-5 py-1">
                                                <div class="fw-bold extra-small"><?php echo htmlspecialchars($p['display_name']); ?></div>
                                                <div class="text-muted font-monospace opacity-50" style="font-size: 0.6rem;"><?php echo $p['permission_code']; ?></div>
                                            </td>
                                            <?php foreach ($roles as $role): 
                                                $has_p = in_array($p['id'], $role_current_perms[$role['id']] ?? []);
                                            ?>
                                                <td class="text-center">
                                                    <i class="fas <?php echo $has_p ? 'fa-check-circle text-success' : 'fa-times-circle text-danger opacity-25'; ?>"></i>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 2: Financial Settings -->
        <div class="tab-pane fade <?php echo $active_tab === 'unified_financial_settings' ? 'show active' : ''; ?>" id="unified_financial_settings">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <h5 class="fw-bold mb-4"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i> الصلاحيات المالية الموحدة</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border rounded-3">
                        <thead class="bg-light">
                            <tr>
                                <th class="px-4 py-3">الدور</th>
                                <th class="text-center">رؤية المبلغ</th>
                                <th class="text-center">رؤية الرصيد</th>
                                <th class="text-center">الترحيل المالي</th>
                                <th class="text-center">تعديل المبالغ</th>
                                <th class="text-center">الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $financial_codes = ['col_amount_view', 'col_balance_view', 'voucher_post', 'transactions_edit_amount'];
                            foreach ($roles as $role):
                                $rid = $role['id'];
                            ?>
                                <tr>
                                    <td class="px-4 fw-bold"><?php echo htmlspecialchars($role['display_name']); ?></td>
                                    <?php foreach ($financial_codes as $code): 
                                        $has_it = hasPermissionLocal($code, $rid);
                                    ?>
                                        <td class="text-center"><i class="fas <?php echo $has_it ? 'fa-check-circle text-success' : 'fa-times-circle text-danger opacity-25'; ?> fs-5"></i></td>
                                    <?php endforeach; ?>
                                    <td class="text-center"><button class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editRoleModal<?php echo $rid; ?>">تعديل</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 3: Editor Permissions -->
        <div class="tab-pane fade <?php echo $active_tab === 'editor_perms' ? 'show active' : ''; ?>" id="editor_perms">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <h5 class="fw-bold mb-4"><i class="fas fa-user-edit me-2 text-primary"></i> إعدادات صلاحيات المحرر (Editor)</h5>
                <form method="POST">
                    <div class="row g-4">
                        <!-- Administrative Section -->
                        <div class="col-md-6">
                            <div class="card border-0 bg-light rounded-4 h-100">
                                <div class="card-header bg-white border-0 py-3 d-flex align-items-center justify-content-between">
                                    <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-user-shield me-2"></i> الإعدادات الإدارية</h6>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input editor-select-all" type="checkbox" id="editor_admin_select_all" data-section="editor_admin_group" style="cursor:pointer;">
                                        <label class="form-check-label small fw-bold text-muted" for="editor_admin_select_all" style="cursor:pointer;">تحديد الكل</label>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2" id="editor_admin_group">
                                        <?php
                                        $editor_admin_list = [
                                            'allow_editor_users' => 'إدارة المستخدمين', 
                                            'allow_editor_settings' => 'تعديل الإعدادات', 
                                            'allow_editor_news' => 'إدارة الأخبار', 
                                            'allow_editor_messages' => 'إدارة الرسائل', 
                                            'allow_editor_subscribers' => 'إدارة المشتركين', 
                                            'allow_editor_home_content' => 'محتوى الرئيسية', 
                                            'allow_editor_pages' => 'إدارة الصفحات', 
                                            'allow_editor_slider' => 'إدارة السلايدر', 
                                            'allow_editor_delete' => 'صلاحية الحذف'
                                        ];
                                        foreach ($editor_admin_list as $key => $label): ?>
                                            <div class="col-12">
                                                <label class="perm-card mb-2 <?php echo ($settings[$key] ?? 0) ? 'is-active' : ''; ?>" for="<?php echo $key; ?>">
                                                    <span class="perm-title"><?php echo $label; ?></span>
                                                    <input class="form-check-input perm-checkbox editor-perm-cb" type="checkbox" name="<?php echo $key; ?>" id="<?php echo $key; ?>" <?php echo ($settings[$key] ?? 0) ? 'checked' : ''; ?> data-original="<?php echo ($settings[$key] ?? 0) ? '1' : '0'; ?>">
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Financial/Operations Section -->
                        <div class="col-md-6">
                            <div class="card border-0 bg-light rounded-4 h-100">
                                <div class="card-header bg-white border-0 py-3 d-flex align-items-center justify-content-between">
                                    <h6 class="fw-bold mb-0 text-success"><i class="fas fa-file-invoice-dollar me-2"></i> الإعدادات المالية والتشغيلية</h6>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input editor-select-all" type="checkbox" id="editor_finance_select_all" data-section="editor_finance_group" style="cursor:pointer;">
                                        <label class="form-check-label small fw-bold text-muted" for="editor_finance_select_all" style="cursor:pointer;">تحديد الكل</label>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2" id="editor_finance_group">
                                        <?php
                                        $editor_finance_list = [
                                            'allow_editor_batches' => 'إدارة الدفعات', 
                                            'allow_editor_passports' => 'إدارة الجوازات', 
                                            'allow_editor_statuses' => 'إدارة الحالات', 
                                            'allow_editor_services' => 'إدارة الخدمات', 
                                            'allow_editor_currencies' => 'إدارة العملات', 
                                            'allow_editor_work_visa' => 'إدارة تأشيرات العمل'
                                        ];
                                        foreach ($editor_finance_list as $key => $label): ?>
                                            <div class="col-12">
                                                <label class="perm-card mb-2 <?php echo ($settings[$key] ?? 0) ? 'is-active' : ''; ?>" for="<?php echo $key; ?>">
                                                    <span class="perm-title"><?php echo $label; ?></span>
                                                    <input class="form-check-input perm-checkbox editor-perm-cb" type="checkbox" name="<?php echo $key; ?>" id="<?php echo $key; ?>" <?php echo ($settings[$key] ?? 0) ? 'checked' : ''; ?> data-original="<?php echo ($settings[$key] ?? 0) ? '1' : '0'; ?>">
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-center mt-4"><button type="submit" name="save_editor_permissions" class="btn btn-primary rounded-pill px-5 py-2 fw-bold shadow"><i class="fas fa-save me-2"></i> حفظ الإعدادات</button></div>
                </form>
            </div>
        </div>

        <!-- Tab 4: Users Stats -->
        <div class="tab-pane fade <?php echo $active_tab === 'users_stats' ? 'show active' : ''; ?>" id="users_stats">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light"><tr><th class="px-4 py-3">المستخدم</th><th>الدور</th><th class="text-center">عدد الصلاحيات</th><th>الحالة</th></tr></thead>
                    <tbody>
                        <?php
                        $users_stats = $pdo->query("SELECT u.full_name, u.username, u.status, r.display_name as role_name, (SELECT COUNT(*) FROM role_permissions_unified rp WHERE rp.role_id = u.role_id) as perms_count FROM users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY perms_count DESC")->fetchAll();
                        foreach ($users_stats as $us):
                        ?>
                            <tr>
                                <td class="px-4"><div class="fw-bold"><?php echo htmlspecialchars($us['full_name'] ?: $us['username']); ?></div><small class="text-muted">@<?php echo htmlspecialchars($us['username']); ?></small></td>
                                <td><span class="badge bg-info-subtle text-info rounded-pill"><?php echo htmlspecialchars($us['role_name'] ?: 'غير محدد'); ?></span></td>
                                <td class="text-center"><span class="badge bg-success rounded-pill px-3"><?php echo $us['perms_count']; ?> صلاحية</span></td>
                                <td><span class="badge <?php echo $us['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?> rounded-pill px-2"><?php echo $us['status'] === 'active' ? 'نشط' : 'معطل'; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة دور -->
<div class="modal fade role-edit-modal" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة دور جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4 bg-light">
                    <div class="card border-0 shadow-sm rounded-4 mb-4 p-4">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label fw-bold small">اسم الدور (English)</label><input type="text" name="name" class="form-control rounded-3 font-monospace" placeholder="super_admin" required></div>
                            <div class="col-md-4"><label class="form-label fw-bold small">الاسم المعروض</label><input type="text" name="display_name" class="form-control rounded-3" placeholder="مدير عام" required></div>
                            <div class="col-md-4"><label class="form-label fw-bold small">نسبة الخصم القصوى (%)</label><input type="number" step="0.01" name="max_discount_percentage" class="form-control rounded-3" value="0.00"></div>
                        </div>
                    </div>
                    <h6 class="fw-bold mb-3">تحديد الصلاحيات</h6>
                    <div class="row g-4">
                        <?php foreach ($permission_groups as $g_key => $group): ?>
                            <div class="col-12">
                                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                                    <div class="card-header bg-success text-white py-2 px-3 d-flex align-items-center justify-content-between">
                                        <h6 class="mb-0 small fw-bold"><i class="fas <?php echo $group['icon']; ?> me-2"></i> <?php echo $group['title']; ?></h6>
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input group-select-all-cb" type="checkbox" id="group_sel_add_<?php echo $g_key; ?>" data-accordion="#accordion_add_<?php echo $g_key; ?>" style="cursor:pointer;">
                                            <label class="form-check-label small fw-bold ms-2" for="group_sel_add_<?php echo $g_key; ?>" style="cursor:pointer; font-size: 0.8rem;">تفعيل المجموعة كاملة</label>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="accordion accordion-flush perm-accordion-group" id="accordion_add_<?php echo $g_key; ?>" data-group-master="group_sel_add_<?php echo $g_key; ?>">
                                            <?php 
                                            $idx_g = 0;
                                            foreach ($group['categories'] as $cat):
                                                if (!isset($grouped_permissions[$cat])) continue;
                                                $perms = $grouped_permissions[$cat];
                                                $cat_name = get_category_name($cat, $category_translations);
                                                $collapseId = "collapse_add_{$g_key}_{$idx_g}";
                                            ?>
                                                <div class="accordion-item">
                                                    <h2 class="accordion-header">
                                                        <div class="d-flex align-items-center py-2 px-3" style="gap: 12px;">
                                                            <div class="form-check form-switch m-0 p-0 select-all-wrapper" data-target="#<?php echo $collapseId; ?>">
                                                                <input class="form-check-input select-all-cb" type="checkbox" id="select_all_add_<?php echo $g_key; ?>_<?php echo $idx_g; ?>" style="cursor:pointer;">
                                                                <label class="form-check-label small fw-bold text-muted" for="select_all_add_<?php echo $g_key; ?>_<?php echo $idx_g; ?>" style="cursor:pointer;">تحديد الكل</label>
                                                            </div>
                                                            <button class="accordion-button collapsed small fw-bold flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>">
                                                                <?php echo $cat_name; ?> <span class="badge bg-light text-success border rounded-pill ms-auto extra-small"><?php echo count($perms); ?></span>
                                                            </button>
                                                        </div>
                                                    </h2>
                                                    <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse" data-bs-parent="#accordion_add_<?php echo $g_key; ?>">
                                                        <div class="accordion-body p-3">
                                                            <div class="row g-2 perm-group" data-parent="select_all_add_<?php echo $g_key; ?>_<?php echo $idx_g; ?>">
                                                                <?php foreach ($perms as $p): ?>
                                                                    <div class="col-md-4">
                                                                        <label class="perm-card mb-2" for="p_a_<?php echo $p['id']; ?>">
                                                                            <span class="perm-title"><?php echo htmlspecialchars($p['display_name']); ?></span>
                                                                            <input class="form-check-input perm-checkbox" type="checkbox" name="permissions[]" value="<?php echo $p['id']; ?>" id="p_a_<?php echo $p['id']; ?>">
                                                                        </label>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php $idx_g++; endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer modal-footer-sticky bg-white border-top shadow-sm py-3 px-4">
                    <div class="d-flex gap-2 w-100 justify-content-end">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-5 fw-bold" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> إلغاء
                        </button>
                        <button type="submit" name="add_role" class="btn btn-success rounded-pill px-6 fw-bold shadow-sm">
                            <i class="fas fa-plus-circle me-1"></i> إضافة الدور
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .hover-shadow:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; }
    .transition-all { transition: all 0.2s ease-in-out; }
    .extra-small { font-size: 0.75rem; }

    /* ===== التصميم العصري للبطاقات والمفاتيح ===== */
    .perm-card {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 15px !important;
        padding: 16px 20px !important;
        border: 1px solid rgba(226, 232, 240, 0.8) !important;
        border-radius: 18px !important;
        background: rgba(255, 255, 255, 0.7) !important;
        backdrop-filter: blur(12px) !important;
        -webkit-backdrop-filter: blur(12px) !important;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
        cursor: pointer !important;
        width: 100% !important;
        position: relative !important;
        overflow: hidden !important;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02), inset 0 1px 1px rgba(255,255,255,1) !important;
    }
    
    .perm-card:hover {
        transform: translateY(-4px) scale(1.01) !important;
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.06), inset 0 1px 1px rgba(255,255,255,1) !important;
        border-color: rgba(99, 102, 241, 0.3) !important;
    }

    .perm-card .perm-title {
        font-weight: 700 !important;
        font-size: 15px !important;
        color: #334155 !important;
        flex: 1;
        text-align: right;
        transition: color 0.3s ease;
    }

    /* Premium Rectangular Toggle Switch */
    .perm-card .form-check-input {
        appearance: none !important;
        -webkit-appearance: none !important;
        position: relative !important;
        width: 46px !important;
        height: 24px !important;
        background-color: #e2e8f0 !important;
        border-radius: 24px !important;
        transition: background-color 0.3s ease !important;
        cursor: pointer !important;
        border: none !important;
        margin: 0 !important;
        box-shadow: none !important;
        flex-shrink: 0 !important; /* <--- هذا يمنع الزر من الانضغاط وتغير شكله في الجوال */
    }

    /* Thumb (circle) same height as track */
    .perm-card .form-check-input::after {
        content: "" !important;
        position: absolute !important;
        height: 24px !important;
        width: 24px !important;
        left: 0 !important;
        bottom: 0 !important;
        background-color: #ffffff !important;
        border-radius: 50% !important;
        transition: transform 0.3s cubic-bezier(0.25, 0.1, 0.25, 1), width 0.2s ease !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2) !important;
    }

    /* Active State (ON) */
    .perm-card .form-check-input:checked {
        background-color: #3b82f6 !important;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.1) !important;
    }

    .perm-card .form-check-input:checked::after {
        transform: translateX(22px) !important;
    }
    
    /* Click animation */
    .perm-card .form-check-input:active::after {
        width: 28px !important;
    }
    .perm-card .form-check-input:checked:active::after {
        transform: translateX(18px) !important;
    }

    .perm-card.is-active .perm-title {
        color: #3b82f6 !important;
    }

    .perm-card.is-active {
        border-color: rgba(59, 130, 246, 0.4) !important;
        background: rgba(255, 255, 255, 0.9) !important;
    }

    /* ===== Dark Mode Apple iOS ===== */
    body.theme-dark .perm-card {
        background: rgba(30, 41, 59, 0.6) !important;
        border-color: rgba(255, 255, 255, 0.05) !important;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2), inset 0 1px 1px rgba(255,255,255,0.05) !important;
    }
    
    body.theme-dark .perm-card:hover {
        background: rgba(30, 41, 59, 0.8) !important;
        box-shadow: 0 10px 30px rgba(0,0,0,0.4) !important;
        border-color: rgba(56, 189, 248, 0.3) !important;
    }

    body.theme-dark .perm-card .perm-title {
        color: #f8fafc !important; 
    }

    body.theme-dark .perm-card.is-active .perm-title {
        color: #38bdf8 !important;
        text-shadow: none !important;
    }

    /* Dark mode background */
    body.theme-dark .perm-card .form-check-input:not(:checked) {
        background-color: #334155 !important;
        box-shadow: none !important;
    }
    
    body.theme-dark .perm-card .form-check-input:checked {
        background-color: #38bdf8 !important;
    }
    
    body.theme-dark .perm-card .form-check-input::after {
        background-color: #ffffff !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.3) !important;
    }

    .sticky-top { position: sticky; top: 0; z-index: 1020; }

    /* ===== إصلاحات المودالات: ظهور الفوتر والأزرار ===== */
    .role-edit-modal .modal-dialog {
        max-height: 92vh !important;
        margin: 1.5rem auto !important;
    }

    .role-edit-modal .modal-content {
        max-height: 92vh !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .role-edit-modal .modal-header {
        flex-shrink: 0 !important;
        z-index: 5 !important;
    }

    .role-edit-modal form {
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
        min-height: 0 !important;
    }

    .role-edit-modal .modal-body {
        flex: 1 1 auto !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        max-height: calc(92vh - 180px) !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: thin !important;
    }

    .role-edit-modal .modal-body::-webkit-scrollbar {
        width: 10px;
    }
    .role-edit-modal .modal-body::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.05);
    }
    .role-edit-modal .modal-body::-webkit-scrollbar-thumb {
        background: rgba(59, 130, 246, 0.5);
        border-radius: 5px;
    }

    .modal-footer-sticky {
        flex-shrink: 0 !important;
        position: sticky !important;
        bottom: 0 !important;
        z-index: 10 !important;
        border-top: 2px solid rgba(0,0,0,0.08) !important;
        background: linear-gradient(to bottom, rgba(255,255,255,0.98), #ffffff) !important;
    }

    .modal-footer-sticky .btn {
        min-width: 150px !important;
        padding-top: 0.65rem !important;
        padding-bottom: 0.65rem !important;
    }

    @media (max-width: 768px) {
        .role-edit-modal .modal-dialog {
            max-height: 96vh !important;
            margin: 0.5rem !important;
        }
        .role-edit-modal .modal-content {
            max-height: 96vh !important;
        }
        .role-edit-modal .modal-body {
            max-height: calc(96vh - 180px) !important;
        }
        .modal-footer-sticky .btn {
            min-width: auto !important;
            width: 100% !important;
        }
        .modal-footer-sticky > div {
            flex-direction: column-reverse !important;
            width: 100% !important;
        }
    }

    /* ===== دعم الوضع الليلي للمودالات ===== */
    body.theme-dark .role-edit-modal .modal-content {
        background: #1e293b !important;
    }

    body.theme-dark .role-edit-modal .modal-body {
        background: #0f172a !important;
        color: #e2e8f0 !important;
    }

    body.theme-dark .role-edit-modal .modal-body h6,
    body.theme-dark .role-edit-modal .modal-body label.form-label {
        color: #f1f5f9 !important;
    }

    body.theme-dark .role-edit-modal .modal-footer-sticky {
        background: linear-gradient(to bottom, rgba(30,41,59,0.98), #1e293b) !important;
        border-top-color: rgba(148, 163, 184, 0.15) !important;
    }

    body.theme-dark .role-edit-modal .modal-footer-sticky .btn.btn-primary {
        background-color: #38bdf8 !important;
        border-color: #38bdf8 !important;
        color: #0f172a !important;
    }

    body.theme-dark .role-edit-modal .modal-footer-sticky .btn.btn-success {
        background-color: #34d399 !important;
        border-color: #34d399 !important;
        color: #0f172a !important;
    }

    body.theme-dark .role-edit-modal .modal-footer-sticky .btn.btn-outline-secondary {
        border-color: rgba(148, 163, 184, 0.4) !important;
        color: #cbd5e1 !important;
    }
    body.theme-dark .role-edit-modal .modal-footer-sticky .btn.btn-outline-secondary:hover {
        background-color: rgba(148, 163, 184, 0.1) !important;
    }

    body.theme-dark .role-edit-modal .card-header.bg-primary {
        background-color: #1d4ed8 !important;
    }
    body.theme-dark .role-edit-modal .card-header.bg-success {
        background-color: #047857 !important;
    }
    body.theme-dark .role-edit-modal .bg-light {
        background-color: #1e293b !important;
    }
    body.theme-dark .role-edit-modal .card {
        background: #1e293b !important;
        border: 1px solid rgba(148, 163, 184, 0.1) !important;
    }
    body.theme-dark .role-edit-modal .accordion-button {
        background-color: #1e293b !important;
        color: #e2e8f0 !important;
    }
    body.theme-dark .role-edit-modal .accordion-button:not(.collapsed) {
        background-color: #0f172a !important;
        color: #38bdf8 !important;
    }
    body.theme-dark .role-edit-modal .accordion-body {
        background-color: #0f172a !important;
    }
    body.theme-dark .role-edit-modal .accordion-item {
        border-color: rgba(148, 163, 184, 0.1) !important;
    }

    /* ===== تأثير ظهور الأزرار عند التفعيل ===== */
    #saveChangesBtn_\31 {
        transition: all 0.3s ease !important;
    }
    [id^="saveChangesBtn_"] {
        transition: all 0.3s ease !important;
    }
    [id^="saveChangesBtn_"]:not(:disabled) {
        animation: pulse-glow 1.5s ease-in-out infinite !important;
    }
    @keyframes pulse-glow {
        0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
        50%      { box-shadow: 0 0 0 8px rgba(245, 158, 11, 0); }
    }

    .form-check-input.select-all-cb,
    .form-check-input.group-select-all-cb,
    .form-check-input.editor-select-all {
        width: 42px !important;
        height: 22px !important;
        cursor: pointer !important;
    }
    .form-check-input.select-all-cb:checked,
    .form-check-input.group-select-all-cb:checked,
    .form-check-input.editor-select-all:checked {
        background-color: #22c55e !important;
        border-color: #22c55e !important;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        function updateCardState(cb) {
            const card = cb.closest('.perm-card');
            if (card) {
                if (cb.checked) {
                    card.classList.add('is-active');
                } else {
                    card.classList.remove('is-active');
                }
            }
        }

        function updateSelectAllState(selectAllCbId) {
            const groupEl = document.querySelector('[data-parent="' + selectAllCbId + '"]');
            if (!groupEl) return;
            const cbs = groupEl.querySelectorAll('.perm-checkbox');
            if (cbs.length === 0) return;
            const allChecked = Array.from(cbs).every(cb => cb.checked);
            const someChecked = Array.from(cbs).some(cb => cb.checked);
            const selectAllCb = document.getElementById(selectAllCbId);
            if (selectAllCb) {
                selectAllCb.checked = allChecked;
                selectAllCb.indeterminate = (!allChecked && someChecked);
            }
        }

        function updateGroupSelectAllState(accordionId) {
            const accordion = document.getElementById(accordionId);
            if (!accordion) return;
            const masterId = accordion.getAttribute('data-group-master');
            if (!masterId) return;
            const allPermCbs = accordion.querySelectorAll('.perm-checkbox');
            if (allPermCbs.length === 0) return;
            const allChecked = Array.from(allPermCbs).every(cb => cb.checked);
            const someChecked = Array.from(allPermCbs).some(cb => cb.checked);
            const masterCb = document.getElementById(masterId);
            if (masterCb) {
                masterCb.checked = allChecked;
                masterCb.indeterminate = (!allChecked && someChecked);
            }
        }

        function updateCategorySelectAllsInAccordion(accordionId, isChecked) {
            const accordion = document.getElementById(accordionId);
            if (!accordion) return;
            const catSelectAlls = accordion.querySelectorAll('.select-all-cb');
            catSelectAlls.forEach(cb => {
                cb.checked = isChecked;
                cb.indeterminate = false;
            });
        }

        function checkEditModalChanges(rid) {
            const modal = document.getElementById('editRoleModal' + rid);
            if (!modal) return false;
            const cbs = modal.querySelectorAll('.perm-checkbox[data-original]');
            let hasChanged = false;
            cbs.forEach(cb => {
                const original = cb.getAttribute('data-original') === '1';
                if (cb.checked !== original) {
                    hasChanged = true;
                }
            });
            const allInputs = modal.querySelectorAll('input[name="display_name"], input[name="description"], input[name="max_discount_percentage"]');
            allInputs.forEach(inp => {
                if (inp.defaultValue !== undefined && inp.value !== inp.defaultValue) {
                    hasChanged = true;
                }
            });
            return hasChanged;
        }

        function updateSaveBtnState(rid) {
            const saveBtn = document.getElementById('saveChangesBtn_' + rid);
            if (!saveBtn) return;
            const hasChanged = checkEditModalChanges(rid);
            if (hasChanged) {
                saveBtn.disabled = false;
                saveBtn.classList.remove('btn-primary');
                saveBtn.classList.add('btn-warning');
                if (!saveBtn.querySelector('.change-badge')) {
                    const badge = document.createElement('span');
                    badge.className = 'change-badge badge bg-white text-warning ms-2 rounded-pill';
                    badge.textContent = 'تم التعديل';
                    saveBtn.appendChild(badge);
                }
            } else {
                saveBtn.disabled = true;
                saveBtn.classList.remove('btn-warning');
                saveBtn.classList.add('btn-primary');
                const badge = saveBtn.querySelector('.change-badge');
                if (badge) badge.remove();
            }
        }

        function checkEditorChanges() {
            const editorTab = document.getElementById('editor_perms');
            if (!editorTab) return false;
            const cbs = editorTab.querySelectorAll('.editor-perm-cb');
            let hasChanged = false;
            cbs.forEach(cb => {
                const original = cb.getAttribute('data-original') === '1';
                if (cb.checked !== original) {
                    hasChanged = true;
                }
            });
            return hasChanged;
        }

        function updateEditorSaveBtn() {
            const saveBtn = document.querySelector('button[name="save_editor_permissions"]');
            if (!saveBtn) return;
            const hasChanged = checkEditorChanges();
            if (hasChanged) {
                saveBtn.classList.remove('btn-primary');
                saveBtn.classList.add('btn-warning');
                if (!saveBtn.querySelector('.change-badge')) {
                    const badge = document.createElement('span');
                    badge.className = 'change-badge badge bg-white text-warning ms-2 rounded-pill';
                    badge.textContent = 'تم التعديل';
                    saveBtn.appendChild(badge);
                }
            } else {
                saveBtn.classList.remove('btn-warning');
                saveBtn.classList.add('btn-primary');
                const badge = saveBtn.querySelector('.change-badge');
                if (badge) badge.remove();
            }
        }

        document.querySelectorAll('.perm-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                updateCardState(this);
                const parentGroup = this.closest('[data-parent]');
                if (parentGroup) {
                    const parentId = parentGroup.getAttribute('data-parent');
                    if (parentId) updateSelectAllState(parentId);
                }
                const parentAccordion = this.closest('.perm-accordion-group');
                if (parentAccordion) {
                    updateGroupSelectAllState(parentAccordion.id);
                }
                const editorGroup = this.closest('#editor_admin_group, #editor_finance_group');
                if (editorGroup) {
                    const allCb = document.querySelector('.editor-select-all[data-section="' + editorGroup.id + '"]');
                    if (allCb) {
                        const groupCbs = editorGroup.querySelectorAll('.perm-checkbox');
                        const allChecked = Array.from(groupCbs).every(c => c.checked);
                        const someChecked = Array.from(groupCbs).some(c => c.checked);
                        allCb.checked = allChecked;
                        allCb.indeterminate = (!allChecked && someChecked);
                    }
                    updateEditorSaveBtn();
                }
                const editModal = this.closest('[id^="editRoleModal"]');
                if (editModal) {
                    const rid = editModal.id.replace('editRoleModal', '');
                    updateSaveBtnState(rid);
                }
            });
        });

        document.querySelectorAll('.select-all-cb').forEach(saCb => {
            saCb.addEventListener('change', function() {
                const saId = this.id;
                const targetGroup = document.querySelector('[data-parent="' + saId + '"]');
                if (!targetGroup) return;
                const cbs = targetGroup.querySelectorAll('.perm-checkbox');
                const isChecked = this.checked;
                cbs.forEach(cb => {
                    cb.checked = isChecked;
                    updateCardState(cb);
                });
                this.indeterminate = false;
                const parentAccordion = this.closest('.perm-accordion-group');
                if (parentAccordion) {
                    updateGroupSelectAllState(parentAccordion.id);
                }
                const editModal = this.closest('[id^="editRoleModal"]');
                if (editModal) {
                    const rid = editModal.id.replace('editRoleModal', '');
                    updateSaveBtnState(rid);
                }
            });
        });

        document.querySelectorAll('.group-select-all-cb').forEach(gsaCb => {
            gsaCb.addEventListener('change', function() {
                const accordionSel = this.getAttribute('data-accordion');
                if (!accordionSel) return;
                const accordion = document.querySelector(accordionSel);
                if (!accordion) return;
                const isChecked = this.checked;
                this.indeterminate = false;
                updateCategorySelectAllsInAccordion(accordion.id, isChecked);
                const permCbs = accordion.querySelectorAll('.perm-checkbox');
                permCbs.forEach(cb => {
                    cb.checked = isChecked;
                    updateCardState(cb);
                });
                const editModal = this.closest('[id^="editRoleModal"]');
                if (editModal) {
                    const rid = editModal.id.replace('editRoleModal', '');
                    updateSaveBtnState(rid);
                }
            });
        });

        document.querySelectorAll('.editor-select-all').forEach(saCb => {
            saCb.addEventListener('change', function() {
                const sectionId = this.getAttribute('data-section');
                const section = document.getElementById(sectionId);
                if (!section) return;
                const cbs = section.querySelectorAll('.perm-checkbox');
                const isChecked = this.checked;
                cbs.forEach(cb => {
                    cb.checked = isChecked;
                    updateCardState(cb);
                });
                this.indeterminate = false;
                updateEditorSaveBtn();
            });
        });

        function initEditorSelectAll() {
            ['editor_admin_group', 'editor_finance_group'].forEach(gid => {
                const group = document.getElementById(gid);
                if (!group) return;
                const allCb = document.querySelector('.editor-select-all[data-section="' + gid + '"]');
                if (!allCb) return;
                const cbs = group.querySelectorAll('.perm-checkbox');
                const allChecked = Array.from(cbs).every(c => c.checked);
                const someChecked = Array.from(cbs).some(c => c.checked);
                allCb.checked = allChecked;
                allCb.indeterminate = (!allChecked && someChecked);
            });
        }

        function initPermGroupSelectAll() {
            document.querySelectorAll('.perm-group[data-parent]').forEach(group => {
                const parentId = group.getAttribute('data-parent');
                const cbs = group.querySelectorAll('.perm-checkbox');
                const allCb = document.getElementById(parentId);
                if (!allCb || cbs.length === 0) return;
                const allChecked = Array.from(cbs).every(c => c.checked);
                const someChecked = Array.from(cbs).some(c => c.checked);
                allCb.checked = allChecked;
                allCb.indeterminate = (!allChecked && someChecked);
            });
        }

        function initGroupMasterSelectAll() {
            document.querySelectorAll('.perm-accordion-group').forEach(acc => {
                updateGroupSelectAllState(acc.id);
            });
        }

        function initFormDefaults() {
            document.querySelectorAll('[id^="editRoleModal"] input[name="display_name"], [id^="editRoleModal"] input[name="description"], [id^="editRoleModal"] input[name="max_discount_percentage"]').forEach(inp => {
                inp.defaultValue = inp.value;
                inp.addEventListener('input', function() {
                    const editModal = this.closest('[id^="editRoleModal"]');
                    if (editModal) {
                        const rid = editModal.id.replace('editRoleModal', '');
                        updateSaveBtnState(rid);
                    }
                });
            });
        }

        document.querySelectorAll('[id^="editRoleModal"]').forEach(modal => {
            modal.addEventListener('shown.bs.modal', function() {
                const rid = this.id.replace('editRoleModal', '');
                initPermGroupSelectAll();
                initGroupMasterSelectAll();
                initFormDefaults();
                updateSaveBtnState(rid);
            });
        });

        document.getElementById('addRoleModal').addEventListener('shown.bs.modal', function() {
            initPermGroupSelectAll();
            initGroupMasterSelectAll();
        });

        initEditorSelectAll();
        initPermGroupSelectAll();
        initGroupMasterSelectAll();
        initFormDefaults();

        document.querySelectorAll('.perm-checkbox').forEach(cb => updateCardState(cb));
    });
</script>

<?php require_once 'footer.php'; ?>