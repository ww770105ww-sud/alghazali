<?php
$page_title = "تشخيص الموديولات";
require_once 'header.php';

// Check if user is developer or admin
if (!isset($user_role) || !in_array($user_role, ['developer', 'admin'])) {
    header("Location: index.php");
    exit;
}

if (!function_exists('get_module_definitions')) {
    function get_module_definitions()
    {
        return [
            'enable_bus_bookings' => 'Bus bookings',
            'enable_flight_bookings' => 'Flight bookings',
            'enable_passport_transactions' => 'Passport transactions',
            'enable_work_visa' => 'Work visa',
            'enable_family_visit' => 'Family visit',
            'enable_postal_services' => 'Postal services',
            'enable_umrah' => 'Umrah',
            'enable_hajj' => 'Hajj',
            'enable_crm' => 'CRM',
        ];
    }
}

if (!function_exists('normalize_bool_setting')) {
    function normalize_bool_setting($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}

if (!function_exists('reload_module_settings_cache')) {
    function reload_module_settings_cache()
    {
        unset($_SESSION['settings_cache'], $_SESSION['module_settings_cache']);
    }
}

// Define modules list from the single module registry.
$modules_list = get_module_definitions();

// Reload modules action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reload_modules'])) {
    reload_module_settings_cache();
    $_SESSION['flash_message'] = [
        'type' => 'success',
        'title' => 'تم بنجاح',
        'body' => 'تم إعادة تحميل الموديولات'
    ];
    header("Location: modules_diagnostic.php");
    exit;
}

// Get latest audit log for each module
$latest_audit = [];
try {
    foreach ($modules_list as $key => $name) {
        $stmt = $pdo->prepare("
            SELECT * FROM module_audit_log 
            WHERE module_key = ? 
            ORDER BY changed_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$key]);
        $latest_audit[$key] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Table may not exist yet
}
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-stethoscope me-2 text-primary"></i> تشخيص الموديولات</h4>
            <p class="text-muted mb-0">عرض حالة الموديولات في قاعدة البيانات وحالتها الفعلية</p>
        </div>
        <form method="POST" class="d-flex gap-3">
            <button type="submit" name="reload_modules" class="btn btn-primary">
                <i class="fas fa-sync-alt me-2"></i> إعادة تحميل الموديولات
            </button>
            <a href="settings.php?tab=modules" class="btn btn-outline-secondary">
                <i class="fas fa-cog me-2"></i> إعدادات الموديولات
            </a>
        </form>
    </div>

    <!-- Modules Status Table -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i> حالة الموديولات</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>اسم الموديول</th>
                            <th>المفتاح</th>
                            <th>الحالة في قاعدة البيانات</th>
                            <th>الحالة الفعلية في الصفحة</th>
                            <th>آخر تحديث</th>
                            <th>المستخدم الذي قام بالتحديث</th>
                            <th>مفعل/معطل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modules_list as $key => $name): ?>
                            <?php
                            $db_row = null;
                            $db_value = null;
                            $db_updated_at = null;
                            try {
                                $db_stmt = $pdo->prepare("SELECT setting_value, updated_at FROM system_settings WHERE setting_key = ? LIMIT 1");
                                $db_stmt->execute([$key]);
                                $db_row = $db_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($db_row) {
                                    $db_value = normalize_bool_setting($db_row['setting_value']) ? 1 : 0;
                                    $db_updated_at = $db_row['updated_at'] ?? null;
                                }
                            } catch (Exception $e) {
                                $db_row = null;
                            }

                            $actual_value = get_module_status($pdo, $key) ? 1 : 0;
                            $status_match = ($db_row !== null && $db_value === $actual_value);
                            $last_changed = $latest_audit[$key]['changed_at'] ?? 'لم يتم التعديل بعد';
                            if ($db_updated_at) {
                                $last_changed = $db_updated_at;
                            }
                            $last_user = $latest_audit[$key]['username'] ?? 'N/A';
                            $diagnostic_error = $db_row === null ? 'المفتاح غير موجود في قاعدة البيانات' : (!$status_match ? 'تعارض بين قاعدة البيانات والحالة الفعلية' : '');
                            ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($name); ?></td>
                                <td><code class="small"><?php echo htmlspecialchars($key); ?></code></td>
                                <td>
                                    <?php if ($db_row !== null): ?>
                                        <span class="badge bg-<?php echo $db_value ? 'success' : 'secondary'; ?>">
                                            <?php echo $db_value ? 'مفعل' : 'معطل'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">غير موجود</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $actual_value ? 'success' : 'secondary'; ?>">
                                        <?php echo $actual_value ? 'مفعل' : 'معطل'; ?>
                                    </span>
                                </td>
                                <td class="text-nowrap small"><?php echo htmlspecialchars($last_changed); ?></td>
                                <td class="small"><?php echo htmlspecialchars($last_user); ?></td>
                                <td>
                                    <?php if ($status_match): ?>
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i> مطابق</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" title="<?php echo htmlspecialchars($diagnostic_error); ?>"><i class="fas fa-times me-1"></i> غير مطابق</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Audit Log Table -->
    <?php
    $audit_logs = [];
    try {
        $audit_logs = $pdo->query("
            SELECT * FROM module_audit_log 
            ORDER BY changed_at DESC 
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // No table yet
    }
    ?>
    <?php if (!empty($audit_logs)): ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i> سجل عمليات الموديولات</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>الوقت</th>
                                <th>الموديول</th>
                                <th>المستخدم</th>
                                <th>القيمة القديمة</th>
                                <th>القيمة الجديدة</th>
                                <th>IP</th>
                                <th>الجهاز</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td class="text-nowrap small"><?php echo htmlspecialchars($log['changed_at']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($log['module_name']); ?></td>
                                    <td><?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo $log['old_value'] ? 'مفعل' : 'معطل'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo $log['new_value'] ? 'مفعل' : 'معطل'; ?>
                                        </span>
                                    </td>
                                    <td><code class="small"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></code></td>
                                    <td class="small text-muted">
                                        <?php 
                                        if ($log['user_agent']) {
                                            $ua = parseUserAgent($log['user_agent']);
                                            echo htmlspecialchars($ua['os'] . ' / ' . $ua['browser']);
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
