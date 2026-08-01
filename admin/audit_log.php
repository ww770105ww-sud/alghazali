<?php
$page_title = "سجل التدقيق";
require_once 'header.php';

$logs = [];
$source_table = '';
$error_message = null;

// معالجة الفلاتر
$where_clauses = [];
$params = [];

if (!empty($_GET['user_id'])) {
    $where_clauses[] = 'user_id = ?';
    $params[] = $_GET['user_id'];
}

if (!empty($_GET['action'])) {
    $where_clauses[] = 'action = ?';
    $params[] = $_GET['action'];
}

if (!empty($_GET['table_name'])) {
    $where_clauses[] = 'table_name = ?';
    $params[] = $_GET['table_name'];
}

if (!empty($_GET['date_from'])) {
    $where_clauses[] = 'DATE(created_at) >= ?';
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_clauses[] = 'DATE(created_at) <= ?';
    $params[] = $_GET['date_to'];
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$limit = min($limit, 1000); // حد أقصى 1000 سجل

try {
    $query = "SELECT * FROM audit_logs $where_sql ORDER BY created_at DESC LIMIT $limit";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $source_table = 'audit_logs';
} catch (PDOException $e) {
    $error_message = 'لا يمكن الوصول إلى جدول سجل التدقيق (audit_logs) في قاعدة البيانات.';
}

$user_names = [];
$customer_account_names = [];
$customer_names = [];
$branch_names = [];
$currency_names = [];
$supplier_names = [];
$account_names = [];
if (!empty($logs)) {
    $user_ids = array_values(array_unique(array_filter(array_column($logs, 'user_id'))));

    // جمع كل معرفات المستخدمين المحتملة من قيم JSON القديمة/الجديدة
    foreach ($logs as $log_item) {
        foreach (['old_values', 'new_values'] as $json_key) {
            $payload = $log_item[$json_key] ?? '';
            if (!$payload) {
                continue;
            }
            $decoded_payload = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_payload)) {
                continue;
            }
            foreach (['created_by', 'updated_by', 'posted_by', 'cancelled_by', 'canceled_by', 'user_id'] as $user_field) {
                if (isset($decoded_payload[$user_field]) && is_numeric($decoded_payload[$user_field])) {
                    $user_ids[] = (int)$decoded_payload[$user_field];
                }
            }
        }
    }

    $user_ids = array_values(array_unique(array_filter($user_ids)));
    if (!empty($user_ids)) {
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $stmt_users = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id IN ($placeholders)");
        $stmt_users->execute($user_ids);
        $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $user) {
            $user_names[$user['id']] = $user['full_name'] ? $user['full_name'] : $user['username'];
        }
    }

    // خريطة حساب العميل -> اسم العميل لعرضها بدل رقم الحساب في سجل التدقيق
    try {
        $stmt_customer_accounts = $pdo->query("SELECT account_id, full_name FROM customers WHERE account_id IS NOT NULL AND account_id > 0");
        $customer_rows = $stmt_customer_accounts->fetchAll(PDO::FETCH_ASSOC);
        foreach ($customer_rows as $customer_row) {
            $accId = (int)($customer_row['account_id'] ?? 0);
            if ($accId > 0) {
                $customer_account_names[$accId] = $customer_row['full_name'] ?: ('عميل #' . $accId);
            }
        }
    } catch (Exception $e) {
        // تجاهل الخطأ للحفاظ على استمرار الصفحة حتى لو فشل الاستعلام.
    }

    // خرائط أسماء مرجعية لعرض القيم النصية بدل المعرفات الرقمية.
    try {
        $stmt_customers = $pdo->query("SELECT id, full_name FROM customers");
        foreach ($stmt_customers->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)($row['id'] ?? 0);
            if ($cid > 0) {
                $customer_names[$cid] = $row['full_name'] ?: ('عميل #' . $cid);
            }
        }
    } catch (Exception $e) {}

    try {
        $stmt_branches = $pdo->query("SELECT id, branch_name FROM branches");
        foreach ($stmt_branches->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bid = (int)($row['id'] ?? 0);
            if ($bid > 0) {
                $branch_names[$bid] = $row['branch_name'] ?: ('فرع #' . $bid);
            }
        }
    } catch (Exception $e) {}

    try {
        $stmt_currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies");
        foreach ($stmt_currencies->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $curId = (int)($row['id'] ?? 0);
            if ($curId > 0) {
                $curName = trim((string)($row['currency_name'] ?? ''));
                $curSymbol = trim((string)($row['currency_symbol'] ?? ''));
                if ($curName !== '' && $curSymbol !== '') {
                    $currency_names[$curId] = $curName . ' (' . $curSymbol . ')';
                } elseif ($curName !== '') {
                    $currency_names[$curId] = $curName;
                } else {
                    $currency_names[$curId] = 'عملة #' . $curId;
                }
            }
        }
    } catch (Exception $e) {}

    try {
        $stmt_suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers");
        foreach ($stmt_suppliers->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int)($row['id'] ?? 0);
            if ($sid > 0) {
                $supplier_names[$sid] = $row['supplier_name'] ?: ('مورد #' . $sid);
            }
        }
    } catch (Exception $e) {}

    try {
        $stmt_users = $pdo->query("SELECT id, username, full_name FROM users");
        foreach ($stmt_users->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int)($row['id'] ?? 0);
            if ($uid > 0) {
                $user_names[$uid] = $row['full_name'] ?: $row['username'];
            }
        }
    } catch (Exception $e) {}

    try {
        $stmt_accounts = $pdo->query("SELECT id, account_name_ar, account_code FROM unified_accounts");
        foreach ($stmt_accounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aid = (int)($row['id'] ?? 0);
            if ($aid > 0) {
                $accName = trim((string)($row['account_name_ar'] ?? ''));
                $accCode = trim((string)($row['account_code'] ?? ''));
                if ($accName !== '' && $accCode !== '') {
                    $account_names[$aid] = $accName . ' (' . $accCode . ')';
                } elseif ($accName !== '') {
                    $account_names[$aid] = $accName;
                } else {
                    $account_names[$aid] = 'حساب #' . $aid;
                }
            }
        }
    } catch (Exception $e) {}
}

// تم نقل الدوال المساعدة (parseUserAgent, translateTableName, etc.) إلى includes/functions.php لضمان قابليتها لإعادة الاستخدام

function hasAuditData($value)
{
    return !($value === null || $value === '' || trim((string)$value) === '');
}
?>

<style>
    .audit-data-viewer::-webkit-scrollbar { width: 4px; }
    .audit-data-viewer::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .table-hover tbody tr:hover { background-color: rgba(37, 99, 235, 0.02); }
    .badge-action { padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; }
    .bg-create { background: #dcfce7; color: #166534; }
    .bg-update { background: #fef9c3; color: #854d0e; }
    .bg-delete { background: #fee2e2; color: #991b1b; }
    .bg-unauthorized { background: #000; color: #fff; animation: pulse 2s infinite; }
    .audit-modal .modal-header { border-bottom: 1px solid #e5e7eb; }
    .audit-modal .modal-footer { border-top: 1px solid #e5e7eb; }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
</style>

<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold text-primary mb-1"><i class="fas fa-history me-2"></i> سجل التدقيق</h3>
                    <p class="text-muted mb-0">عرض أحدث السجلات المسجلة في نظام التدقيق.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger rounded-4 shadow-sm mb-4">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php else: ?>
        <!-- معلومات إحصائية -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm rounded-4 bg-primary text-white">
                    <div class="card-body p-3 text-center">
                        <h5 class="mb-1"><?php echo count($logs); ?></h5>
                        <small>عدد السجلات المعروضة</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm rounded-4 bg-success text-white">
                    <div class="card-body p-3 text-center">
                        <?php
                        $create_count = count(array_filter($logs, function ($log) {
                            return strpos(strtolower($log['action'] ?? ''), 'create') !== false || strpos($log['action'] ?? '', 'إضافة') !== false;
                        }));
                        ?>
                        <h5 class="mb-1"><?php echo $create_count; ?></h5>
                        <small>عمليات الإنشاء</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm rounded-4 bg-warning text-white">
                    <div class="card-body p-3 text-center">
                        <?php
                        $update_count = count(array_filter($logs, function ($log) {
                            return strpos(strtolower($log['action'] ?? ''), 'update') !== false || strpos($log['action'] ?? '', 'تعديل') !== false;
                        }));
                        ?>
                        <h5 class="mb-1"><?php echo $update_count; ?></h5>
                        <small>عمليات التعديل</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm rounded-4 bg-danger text-white">
                    <div class="card-body p-3 text-center">
                        <?php
                        $delete_count = count(array_filter($logs, function ($log) {
                            return strpos(strtolower($log['action'] ?? ''), 'delete') !== false || strpos($log['action'] ?? '', 'حذف') !== false;
                        }));
                        ?>
                        <h5 class="mb-1"><?php echo $delete_count; ?></h5>
                        <small>عمليات الحذف</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- نموذج الفلترة -->
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body p-3">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label small">المستخدم</label>
                        <select name="user_id" class="form-select form-select-sm">
                            <option value="">الكل</option>
                            <?php
                            $users_stmt = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name");
                            $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($all_users as $u) {
                                $selected = (isset($_GET['user_id']) && $_GET['user_id'] == $u['id']) ? 'selected' : '';
                                echo "<option value=\"{$u['id']}\" $selected>" . htmlspecialchars($u['full_name'] ?: $u['username']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">نوع العملية</label>
                        <select name="action" class="form-select form-select-sm">
                            <option value="">الكل</option>
                            <option value="create" <?php echo (isset($_GET['action']) && $_GET['action'] == 'create') ? 'selected' : ''; ?> style="background-color: #dcfce7; color: #166534;">إضافة</option>
                            <option value="update" <?php echo (isset($_GET['action']) && $_GET['action'] == 'update') ? 'selected' : ''; ?> style="background-color: #fef9c3; color: #854d0e;">تعديل</option>
                            <option value="delete" <?php echo (isset($_GET['action']) && $_GET['action'] == 'delete') ? 'selected' : ''; ?> style="background-color: #fee2e2; color: #991b1b;">حذف</option>
                            <option value="post" <?php echo (isset($_GET['action']) && $_GET['action'] == 'post') ? 'selected' : ''; ?> style="background-color: #e0f2fe; color: #075985;">ترحيل</option>
                            <option value="unpost" <?php echo (isset($_GET['action']) && $_GET['action'] == 'unpost') ? 'selected' : ''; ?> style="background-color: #f1f5f9; color: #475569;">إلغاء ترحيل</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">الجدول</label>
                        <select name="table_name" class="form-select form-select-sm">
                            <option value="">الكل</option>
                            <?php
                            $tables_stmt = $pdo->query("SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL ORDER BY table_name");
                            $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($tables as $table) {
                                $selected = (isset($_GET['table_name']) && $_GET['table_name'] == $table) ? 'selected' : '';
                                echo "<option value=\"$table\" $selected>" . htmlspecialchars($table) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">من تاريخ</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">إلى تاريخ</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small">الحد</label>
                        <select name="limit" class="form-select form-select-sm">
                            <option value="100" <?php echo (($_GET['limit'] ?? 200) == 100) ? 'selected' : ''; ?>>100</option>
                            <option value="200" <?php echo (($_GET['limit'] ?? 200) == 200) ? 'selected' : ''; ?>>200</option>
                            <option value="500" <?php echo (($_GET['limit'] ?? 200) == 500) ? 'selected' : ''; ?>>500</option>
                            <option value="1000" <?php echo (($_GET['limit'] ?? 200) == 1000) ? 'selected' : ''; ?>>1000</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search"></i> بحث
                        </button>
                        <a href="audit_log.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0 overflow-auto" style="max-height: 660px;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light sticky-top" style="top:0; z-index:1;">
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>المستخدم</th>
                            <th style="width: 20%;">الحدث والبيان</th>
                            <th>رقم السجل</th>
                            <th>التفاصيل</th>
                            <th>التغييرات</th>
                            <th>الجهاز والمتصفح</th>
                            <th>IP الشبكة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">لا توجد سجلات تدقيق حالياً.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $index => $log): ?>
                                <?php
                                $user_label = isset($log['user_id']) && isset($user_names[$log['user_id']])
                                    ? $user_names[$log['user_id']]
                                    : (isset($log['user_id']) ? 'مستخدم #' . $log['user_id'] : '-');
                                $action = $log['action'] ?? '-';
                                $table_name = $log['table_name'] ?? '-';
                                $record_id = $log['record_id'] ?? '-';
                                $created_at = $log['created_at'] ?? '-';
                                $old_data = $log['old_values'] ?? '';
                                $new_data = $log['new_values'] ?? '';
                                $ip_address = $log['ip_address'] ?? $log['user_ip'] ?? '-';
                                $user_agent = $log['user_agent'] ?? '';
                                $modal_id = 'auditModal' . $index;

                                // استخراج البيان من البيانات الجديدة أو القديمة
                                $log_description = '-';
                                $decoded_new = json_decode($new_data, true);
                                $decoded_old = json_decode($old_data, true);
                                if (!is_array($decoded_new)) $decoded_new = [];
                                if (!is_array($decoded_old)) $decoded_old = [];
                                if (isset($decoded_new['description'])) {
                                    $log_description = $decoded_new['description'];
                                } elseif (isset($decoded_old['description'])) {
                                    $log_description = $decoded_old['description'];
                                }
                                $form_name = getAuditFormName($table_name, $action, $decoded_new, $decoded_old);

                                // تنسيق الحدث
                                $action_class = 'bg-secondary-subtle text-secondary';
                                if (stripos($action, 'create') !== false) $action_class = 'bg-create';
                                elseif (stripos($action, 'update') !== false) $action_class = 'bg-update';
                                elseif (stripos($action, 'delete') !== false) $action_class = 'bg-delete';
                                elseif (stripos($action, 'unpost') !== false) $action_class = 'bg-light text-dark border';
                                elseif (stripos($action, 'post') !== false) $action_class = 'bg-info bg-opacity-10 text-info border border-info-subtle';
                                elseif (stripos($action, 'Unauthorized') !== false) $action_class = 'bg-unauthorized';
                                ?>
                                <tr>
                                    <td class="text-muted small"><?php echo $index + 1; ?></td>
                                    <td class="small fw-bold"><?php echo htmlspecialchars($created_at); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-2 text-primary" style="width:32px; height:32px; display:flex; align-items:center; justify-content:center;">
                                                <i class="fas fa-user small"></i>
                                            </div>
                                            <span class="small fw-bold"><?php echo htmlspecialchars($user_label); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <div class="d-flex align-items-center mb-1">
                                                <span class="badge-action <?php echo $action_class; ?> me-2"><?php echo translateAction($action); ?></span>
                                                <span class="fw-bold text-dark small"><?php echo htmlspecialchars($form_name); ?></span>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.75rem;">
                                                <i class="fas fa-layer-group me-1 opacity-50"></i> <?php echo translateTableName($table_name); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="fw-bold text-primary">#<?php echo htmlspecialchars($record_id); ?></td>
                                    <td class="small">
                                        <div class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($log_description); ?>">
                                            <?php echo htmlspecialchars($log_description); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#<?php echo $modal_id; ?>">
                                            <i class="fas fa-eye me-1"></i> عرض التفاصيل
                                        </button>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center small text-muted">
                                            <?php 
                                            $ua_info = parseUserAgent($user_agent);
                                            if (is_array($ua_info)): ?>
                                                <div class="me-2 text-primary" style="font-size: 1.2rem;">
                                                    <i class="<?php echo $ua_info['device_icon']; ?>" title="<?php echo $ua_info['device']; ?>"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark">
                                                        <i class="<?php echo $ua_info['icon']; ?> me-1"></i> <?php echo $ua_info['browser']; ?>
                                                    </div>
                                                    <div style="font-size: 0.75rem;">
                                                        <i class="<?php echo $ua_info['os_icon']; ?> me-1"></i> <?php echo $ua_info['os']; ?>
                                                        <span class="ms-1 badge bg-secondary-subtle text-secondary p-1" style="font-size: 0.6rem;"><?php echo $ua_info['device']; ?></span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span><?php echo $ua_info; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-network-wired me-2 text-muted small"></i>
                                            <span class="badge bg-light text-primary border small fw-bold" style="letter-spacing: 0.5px;">
                                                <?php 
                                                if ($ip_address === '::1' || $ip_address === '127.0.0.1') {
                                                    echo '<i class="fas fa-laptop-house me-1"></i> الجهاز المحلي';
                                                } else {
                                                    echo htmlspecialchars($ip_address);
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($logs)): ?>
            <?php foreach ($logs as $index => $log): ?>
                <?php
                $user_label = isset($log['user_id']) && isset($user_names[$log['user_id']])
                    ? $user_names[$log['user_id']]
                    : (isset($log['user_id']) ? 'مستخدم #' . $log['user_id'] : '-');
                $action = $log['action'] ?? '-';
                $old_data = $log['old_values'] ?? '';
                $new_data = $log['new_values'] ?? '';
                $ip_address = $log['ip_address'] ?? $log['user_ip'] ?? '-';
                $user_agent = $log['user_agent'] ?? '';
                $ua_info = parseUserAgent($user_agent);
                $modal_id = 'auditModal' . $index;

                // تنسيق الحدث للمودال
                $action_class = 'bg-secondary-subtle text-secondary';
                if (stripos($action, 'create') !== false) $action_class = 'bg-create';
                elseif (stripos($action, 'update') !== false) $action_class = 'bg-update';
                elseif (stripos($action, 'delete') !== false) $action_class = 'bg-delete';
                elseif (stripos($action, 'unpost') !== false) $action_class = 'bg-light text-dark border';
                elseif (stripos($action, 'post') !== false) $action_class = 'bg-info bg-opacity-10 text-info border border-info-subtle';
                ?>
                <div class="modal fade audit-modal" id="<?php echo $modal_id; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header bg-primary bg-opacity-10">
                                <h5 class="modal-title fw-bold text-primary">
                                    <i class="fas fa-search me-2"></i> تفاصيل التغييرات - سجل #<?php echo $index + 1; ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert bg-primary bg-opacity-10 border-0 rounded-4 mb-4">
                                    <div class="row align-items-center">
                                        <div class="col-md-6 border-start">
                                            <div class="d-flex align-items-center mb-2">
                                                <div class="bg-primary text-white p-2 rounded-circle me-3" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center;">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                                <div>
                                                    <small class="text-muted d-block">المسؤول عن الحدث</small>
                                                    <span class="fw-bold"><?php echo htmlspecialchars($user_label); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted d-block small">نوع الحدث</small>
                                                    <span class="badge-action <?php echo $action_class; ?>"><?php echo translateAction($log['action']); ?></span>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block small">تاريخ السجل</small>
                                                    <span class="fw-bold small"><?php echo $log['created_at']; ?></span>
                                                </div>
                                                <div class="col-12 mt-2">
                                                    <hr class="my-1 opacity-10">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <small class="text-muted small">
                                                            <i class="fas fa-network-wired me-1"></i> IP: 
                                                            <?php 
                                                            if ($ip_address === '::1' || $ip_address === '127.0.0.1') {
                                                                echo 'الجهاز المحلي (Localhost)';
                                                            } else {
                                                                echo htmlspecialchars($ip_address);
                                                            }
                                                            ?>
                                                        </small>
                                                        <?php if (is_array($ua_info)): ?>
                                                            <small class="text-muted small">
                                                                <i class="<?php echo $ua_info['device_icon']; ?> me-1"></i> <?php echo $ua_info['device']; ?> | 
                                                                <i class="<?php echo $ua_info['icon']; ?> me-1"></i> <?php echo $ua_info['browser']; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php echo renderAuditModalContent($old_data, $new_data, $modal_id, $action); ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">إغلاق</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
