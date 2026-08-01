<?php
include_once '../includes/db.php';
include_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$current_user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
if (!$current_user_id || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'developer')) {
    header("Location: index.php");
    exit();
}

$page_title = "تقارير الرسائل";

// جلب الإحصائيات
$new_messages_count = $pdo->query("SELECT COUNT(*) FROM internal_messages WHERE created_at >= CURDATE() - INTERVAL 1 DAY")->fetchColumn();
$new_group_messages_count = $pdo->query("SELECT COUNT(*) FROM internal_group_messages WHERE created_at >= CURDATE() - INTERVAL 1 DAY")->fetchColumn();
$total_new_count = $new_messages_count + $new_group_messages_count;

$edited_messages_count = $pdo->query("SELECT COUNT(*) FROM internal_messages WHERE is_edited = 1 AND (is_deleted_for_all = 0 OR is_deleted_for_all IS NULL)")->fetchColumn();
$edited_group_messages_count = $pdo->query("SELECT COUNT(*) FROM internal_group_messages WHERE is_edited = 1 AND is_deleted = 0")->fetchColumn();
$total_edited_count = $edited_messages_count + $edited_group_messages_count;

$deleted_messages_count = $pdo->query("SELECT COUNT(*) FROM internal_messages WHERE (is_deleted_for_all = 1 OR is_deleted_by_sender = 1 OR is_deleted_by_receiver = 1)")->fetchColumn();
$deleted_group_messages_count = $pdo->query("SELECT COUNT(*) FROM internal_group_messages WHERE is_deleted = 1")->fetchColumn();
$total_deleted_count = $deleted_messages_count + $deleted_group_messages_count;

// جلب الرسائل حسب الفلتر (دمج الرسائل الفردية والجماعية)
$filter = $_GET['filter'] ?? 'all';

$sql_internal = "SELECT im.id, im.sender_id, im.message, im.original_message, im.created_at, im.updated_at, im.is_edited, 
                (CASE WHEN im.is_deleted_for_all = 1 OR im.is_deleted_by_sender = 1 OR im.is_deleted_by_receiver = 1 THEN 1 ELSE 0 END) as is_deleted, 
                s.full_name as sender_name, r.full_name as receiver_name, 'فردية' as type 
                FROM internal_messages im 
                JOIN users s ON im.sender_id = s.id 
                JOIN users r ON im.receiver_id = r.id";

$sql_group = "SELECT gm.id, gm.sender_id, gm.message, gm.original_message, gm.created_at, gm.updated_at, gm.is_edited, gm.is_deleted, 
             s.full_name as sender_name, g.name as receiver_name, 'جماعية' as type 
             FROM internal_group_messages gm 
             JOIN users s ON gm.sender_id = s.id 
             JOIN internal_groups g ON gm.group_id = g.id";

$where_internal = [];
$where_group = [];

switch ($filter) {
    case 'edited':
        $where_internal[] = "im.is_edited = 1 AND (im.is_deleted_for_all = 0 OR im.is_deleted_for_all IS NULL)";
        $where_group[] = "gm.is_edited = 1 AND gm.is_deleted = 0";
        break;
    case 'deleted':
        $where_internal[] = "(im.is_deleted_for_all = 1 OR im.is_deleted_by_sender = 1 OR im.is_deleted_by_receiver = 1)";
        $where_group[] = "gm.is_deleted = 1";
        break;
    case 'new':
        $where_internal[] = "im.created_at >= CURDATE() - INTERVAL 1 DAY AND (im.is_deleted_for_all = 0 OR im.is_deleted_for_all IS NULL)";
        $where_group[] = "gm.created_at >= CURDATE() - INTERVAL 1 DAY AND gm.is_deleted = 0";
        break;
}

if (!empty($where_internal)) $sql_internal .= " WHERE " . implode(" AND ", $where_internal);
if (!empty($where_group)) $sql_group .= " WHERE " . implode(" AND ", $where_group);

$final_sql = "($sql_internal) UNION ALL ($sql_group) ORDER BY created_at DESC LIMIT 100";
$messages = $pdo->query($final_sql)->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">تقارير الرسائل الداخلية</h1>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="row">
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">رسائل جديدة (آخر 24 ساعة)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_new_count; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-envelope fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">رسائل معدلة</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_edited_count; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-edit fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">رسائل محذوفة</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_deleted_count; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-trash-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول الرسائل -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">عرض الرسائل</h6>
            <div class="btn-group">
                <a href="?filter=all" class="btn btn-sm btn-outline-secondary <?php echo $filter == 'all' ? 'active' : ''; ?>">الكل</a>
                <a href="?filter=new" class="btn btn-sm btn-outline-primary <?php echo $filter == 'new' ? 'active' : ''; ?>">الجديدة</a>
                <a href="?filter=edited" class="btn btn-sm btn-outline-warning <?php echo $filter == 'edited' ? 'active' : ''; ?>">المعدلة</a>
                <a href="?filter=disappeared" class="btn btn-sm btn-outline-info <?php echo $filter == 'disappeared' ? 'active' : ''; ?>">المخفية</a>
                <a href="?filter=deleted" class="btn btn-sm btn-outline-danger <?php echo $filter == 'deleted' ? 'active' : ''; ?>">المحذوفة</a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>النوع</th>
                            <th>من</th>
                            <th>إلى / المجموعة</th>
                            <th>الرسالة</th>
                            <th>الرسالة الأصلية</th>
                            <th>الوقت</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $msg): ?>
                            <tr>
                                <td>
                                    <span class="badge <?php echo $msg['type'] == 'فردية' ? 'bg-secondary' : 'bg-dark'; ?>">
                                        <?php echo $msg['type']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($msg['sender_name']); ?></td>
                                <td><?php echo htmlspecialchars($msg['receiver_name']); ?></td>
                                <td>
                                    <?php if ($msg['is_deleted']): ?>
                                        <div class="text-danger small italic"><i class="fas fa-trash-alt me-1"></i> هذه الرسالة محذوفة</div>
                                        <div class="text-muted opacity-50"><?php echo htmlspecialchars($msg['message']); ?></div>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($msg['message']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($msg['original_message'] ?? '-'); ?></td>
                                <td>
                                    <div class="small">إنشاء: <?php echo $msg['created_at']; ?></div>
                                    <?php if ($msg['is_edited'] || $msg['is_deleted']): ?>
                                        <div class="small text-info">تحديث: <?php echo $msg['updated_at']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($msg['is_deleted']): ?>
                                        <span class="badge bg-danger">محذوفة</span>
                                    <?php elseif ($msg['is_edited']): ?>
                                        <span class="badge bg-warning text-dark">معدلة</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">جديدة</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include 'footer.php'; ?>
