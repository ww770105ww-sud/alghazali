<?php
$page_title = "سجل المستخدمين والنشاط";
require_once 'header.php';

requireAdminAccess();

$settings = getSettings($pdo);
$user_stats = getUserStats();

$filter_user       = $_GET['user_id'] ?? '';
$filter_type       = $_GET['activity_type'] ?? '';
$filter_date_from  = $_GET['date_from'] ?? '';
$filter_date_to    = $_GET['date_to'] ?? '';
$limit_raw         = $_GET['limit'] ?? 50;
$limit = (int)$limit_raw;
if ($limit <= 0)  $limit = 50;
if ($limit > 1000) $limit = 1000;

$sql = "SELECT ual.*, u.username, u.full_name
        FROM user_activity_logs ual
        LEFT JOIN users u ON ual.user_id = u.id
        WHERE 1=1";
$params = [];
$param_types = [];

if (!empty($filter_user)) {
    $sql .= " AND ual.user_id = ?";
    $params[] = (int)$filter_user;
    $param_types[] = \PDO::PARAM_INT;
}
if (!empty($filter_type)) {
    $sql .= " AND ual.activity_type = ?";
    $params[] = (string)$filter_type;
    $param_types[] = \PDO::PARAM_STR;
}
if (!empty($filter_date_from)) {
    $sql .= " AND DATE(ual.created_at) >= ?";
    $params[] = (string)$filter_date_from;
    $param_types[] = \PDO::PARAM_STR;
}
if (!empty($filter_date_to)) {
    $sql .= " AND DATE(ual.created_at) <= ?";
    $params[] = (string)$filter_date_to;
    $param_types[] = \PDO::PARAM_STR;
}
$sql .= " ORDER BY ual.created_at DESC LIMIT ?";
$params[] = $limit;
$param_types[] = \PDO::PARAM_INT;

$stmt = $pdo->prepare($sql);
foreach ($params as $idx => $val) {
    $type = $param_types[$idx] ?? \PDO::PARAM_STR;
    $stmt->bindValue($idx + 1, $val, $type);
}
$stmt->execute();
$activity_logs = $stmt->fetchAll();

$users = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name")->fetchAll();

$activity_types = $pdo->query("SELECT DISTINCT activity_type FROM user_activity_logs ORDER BY activity_type")
                    ->fetchAll(\PDO::FETCH_COLUMN);
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-user-check fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo (int)$user_stats['active_users']; ?></div>
                            <div class="small opacity-75">مستخدمين نشطين</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-secondary text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-user-slash fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo (int)$user_stats['inactive_users']; ?></div>
                            <div class="small opacity-75">مستخدمين غير نشطين</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-users fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo (int)$user_stats['online_now']; ?></div>
                            <div class="small opacity-75">متصلين الآن</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-info text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-desktop fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo (int)$user_stats['active_sessions']; ?></div>
                            <div class="small opacity-75">جلسات نشطة</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-sign-in-alt fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo (int)$user_stats['logins_today']; ?></div>
                            <div class="small opacity-75">تسجيلات دخول اليوم</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-danger text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-sign-out-alt fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo (int)$user_stats['logouts_today']; ?></div>
                            <div class="small opacity-75">تسجيلات خروج اليوم</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">المستخدم</label>
                    <select name="user_id" class="form-select">
                        <option value="">جميع المستخدمين</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filter_user == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">نوع النشاط</label>
                    <select name="activity_type" class="form-select">
                        <option value="">جميع الأنشطة</option>
                        <?php foreach ($activity_types as $type):
                            $meta = getActivityTypeLabel($type);
                        ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_type == $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($meta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">عدد النتائج</label>
                    <select name="limit" class="form-select">
                        <option value="25"  <?php echo $limit == 25  ? 'selected' : ''; ?>>25</option>
                        <option value="50"  <?php echo $limit == 50  ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="500" <?php echo $limit == 500 ? 'selected' : ''; ?>>500</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> بحث</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i> سجل النشاط</h5>
            <div class="btn-group" role="group">
                <button class="btn btn-outline-success btn-sm" onclick="exportToCSV()">
                    <i class="fas fa-file-csv me-1"></i> تصدير CSV
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> طباعة
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="activity-log-table">
                    <thead class="bg-light">
                        <tr>
                            <th>المستخدم</th>
                            <th>نوع النشاط</th>
                            <th>الوصف</th>
                            <th>IP</th>
                            <th>المتصفح</th>
                            <th>نوع الجهاز</th>
                            <th>الوقت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activity_logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-2 d-block"></i>
                                    لا توجد سجلات
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activity_logs as $log):
                                $typeMeta = getActivityTypeLabel($log['activity_type'] ?? '');
                                $dt_en = $log['device_type'] ?? '';
                                $dt_ar = getDeviceTypeLabel($dt_en);
                                $dt_class = 'desktop';
                                if (in_array(strtolower($dt_en), ['mobile', 'جوال'], true)) $dt_class = 'mobile';
                                elseif (in_array(strtolower($dt_en), ['tablet', 'تابلت'], true)) $dt_class = 'tablet';
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? 'N/A'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($log['username'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo htmlspecialchars($typeMeta['class']); ?>">
                                            <?php echo htmlspecialchars($typeMeta['label']); ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo htmlspecialchars($log['activity_description'] ?? ''); ?>
                                    </td>
                                    <td><code class="small"><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></code></td>
                                    <td class="small" title="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>">
                                        <?php echo htmlspecialchars(!empty($log['browser']) ? $log['browser'] : mb_substr($log['user_agent'] ?? '', 0, 50)); ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-<?php
                                            echo $dt_class === 'mobile'  ? 'mobile-alt'  :
                                                 ($dt_class === 'tablet' ? 'tablet-alt' : 'desktop');
                                        ?> me-1"></i>
                                        <?php echo htmlspecialchars($dt_ar); ?>
                                    </td>
                                    <td class="text-nowrap small">
                                        <?php echo format_date_display($log['created_at'], true); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('activity-log-table');
    if (!table) return;
    let csv = '\uFEFF';
    const rows = table.querySelectorAll('tr');
    rows.forEach(function (row, rIdx) {
        const cols = row.querySelectorAll('th, td');
        const rowData = [];
        cols.forEach(function (col) {
            let text = col.innerText.replace(/\s+/g, ' ').trim();
            text = '"' + text.replace(/"/g, '""') + '"';
            rowData.push(text);
        });
        csv += rowData.join(',') + '\r\n';
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const d = new Date();
    const fname = 'activity_log_' + d.getFullYear() +
        String(d.getMonth() + 1).padStart(2, '0') +
        String(d.getDate()).padStart(2, '0') + '.csv';
    a.download = fname;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php require_once 'footer.php'; ?>
