<?php
$page_title = 'الإشعارات';
require_once 'header.php';

$agent_id = $_SESSION['agent_id'] ?? null;
$branch_id = $_SESSION['branch_id'] ?? null;
$user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

// جلب كل الإشعارات مع الترقيم (Pagination)
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// استعلام محسن لجلب الإشعارات الموجهة للمستخدم الحالي
$where_conditions = [];
$params = [];

if ($user_id) {
    $where_conditions[] = "user_id = ?";
    $params[] = $user_id;
}

if ($user_role) {
    $where_conditions[] = "role_id = ?";
    $params[] = $user_role;
}

if ($agent_id) {
    $where_conditions[] = "agent_id = ?";
    $params[] = $agent_id;
}

if ($branch_id) {
    $where_conditions[] = "branch_id = ?";
    $params[] = $branch_id;
}

// إذا لم يكن هناك شروط، أظهر جميع الإشعارات (للمدراء)
if (empty($where_conditions)) {
    $where_clause = "1=1";
} else {
    $where_clause = implode(" OR ", $where_conditions);
}

// فلترة الإشعارات
$filter = $_GET['filter'] ?? 'all'; // all, unread, read

$filter_clause = "";
if ($filter === 'unread') {
    $filter_clause = " AND is_read = 0";
} elseif ($filter === 'read') {
    $filter_clause = " AND is_read = 1";
}

$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $where_clause $filter_clause");
$stmt_count->execute($params);
$total_notifs = $stmt_count->fetchColumn();
$total_pages = ceil($total_notifs / $limit);

$stmt = $pdo->prepare("
    SELECT n.* FROM notifications n
    WHERE $where_clause $filter_clause
    ORDER BY n.is_read ASC, n.created_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $limit;
$params[] = $offset;
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// تحديد إشعار معين كمقروء إذا تم تمرير notif_id
if (isset($_GET['notif_id'])) {
    $notif_id = (int)$_GET['notif_id'];
    // تحقق من أن الإشعار موجه لهذا المستخدم قبل تحديده كمقروء
    $check_stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND ($where_clause)");
    $check_params = array_merge([$notif_id], $params);
    array_pop($check_params); // إزالة LIMIT و OFFSET
    array_pop($check_params);
    $check_stmt->execute($check_params);
    if ($check_stmt->fetch()) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$notif_id]);
    }
}

// لا نحتاج لتحديد الكل كمقروء عند فتح الصفحة
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-bell me-2"></i> الإشعارات الواردة</h5>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAllAsRead()">
                            <i class="fas fa-check-double me-1"></i> تحديد الكل كمقروء
                        </button>
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="?filter=all" class="btn btn-outline-primary <?php echo $filter === 'all' ? 'active' : ''; ?>">الكل</a>
                            <a href="?filter=unread" class="btn btn-outline-warning <?php echo $filter === 'unread' ? 'active' : ''; ?>">غير مقروءة</a>
                            <a href="?filter=read" class="btn btn-outline-success <?php echo $filter === 'read' ? 'active' : ''; ?>">مقروءة</a>
                        </div>
                        <span class="badge bg-secondary rounded-pill"><?php echo $total_notifs; ?> إشعار</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (count($notifications) > 0): ?>
                            <?php foreach ($notifications as $notif): ?>
                                <?php
                                // تحديد الرابط المناسب
                                $link = htmlspecialchars($notif['link']) ?: '#';
                                
                                // تحديد الرابط بناءً على source_type
                                if (isset($notif['source_type'])) {
                                    switch ($notif['source_type']) {
                                        case 'flight_booking':
                                        case 'bus_booking':
                                            $link = 'bus_flight_bookings.php';
                                            break;
                                        case 'passport_travel':
                                            $link = 'passport_transactions.php';
                                            break;
                                    }
                                }
                                
                                // الاحتيال القديم كحالة احتياطية
                                if ($link === '#') {
                                    if (strpos($notif['title'], 'معاملة') !== false || strpos($notif['message'], 'معاملة') !== false) {
                                        $link = 'passports.php';
                                    } elseif (strpos($notif['title'], 'تأشيرة') !== false || strpos($notif['message'], 'تأشيرة') !== false) {
                                        $link = 'work_visa.php';
                                    } elseif (strpos($notif['title'], 'حجز') !== false || strpos($notif['message'], 'حجز') !== false) {
                                        $link = 'bus_flight_bookings.php';
                                    }
                                }

                                // تحديد الأيقونة حسب النوع
                                $icon_class = 'fas fa-info-circle text-info';
                                if ($notif['type'] === 'warning') {
                                    $icon_class = 'fas fa-exclamation-triangle text-warning';
                                } elseif ($notif['type'] === 'success') {
                                    $icon_class = 'fas fa-check-circle text-success';
                                } elseif ($notif['type'] === 'danger') {
                                    $icon_class = 'fas fa-times-circle text-danger';
                                }
                                ?>
                                <div class="list-group-item py-3 px-4 border-start border-4 <?php echo $notif['is_read'] ? 'bg-light' : 'border-primary bg-primary bg-opacity-5'; ?>">
                                    <div class="d-flex align-items-start gap-3">
                                        <div class="flex-shrink-0 mt-1">
                                            <i class="<?php echo $icon_class; ?> fa-lg"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="fw-bold mb-0 <?php echo $notif['is_read'] ? 'text-muted' : 'text-dark'; ?>">
                                                    <?php echo htmlspecialchars($notif['title']); ?>
                                                    <?php if (!$notif['is_read']): ?>
                                                        <span class="badge bg-primary rounded-pill ms-2 extra-small">جديد</span>
                                                    <?php endif; ?>
                                                </h6>
                                                <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($notif['created_at'])); ?></small>
                                            </div>
                                            <p class="mb-2 <?php echo $notif['is_read'] ? 'text-muted' : 'text-dark'; ?> small lh-base"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></p>
                                            <?php if ($link !== '#'): ?>
                                                <a href="<?php echo $link; ?>?notif_id=<?php echo $notif['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill">
                                                    <i class="fas fa-external-link-alt me-1"></i> عرض التفاصيل
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                                <h6 class="text-muted">لا توجد إشعارات <?php echo $filter === 'unread' ? 'غير مقروءة' : ($filter === 'read' ? 'مقروءة' : ''); ?> حتى الآن</h6>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="card-footer py-3">
                        <nav>
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                        <a class="page-link rounded-pill mx-1" href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function markAllAsRead() {
        if (confirm('هل أنت متأكد من أنك تريد تحديد جميع الإشعارات كمقروءة؟')) {
            fetch('ajax_work_visa.php?action=mark_notifs_read')
                .then(res => res.json())
                .then(res => {
                    if (res.status === 'success') {
                        // إعادة تحميل الصفحة لتحديث العرض
                        window.location.reload();
                    } else {
                        alert('حدث خطأ أثناء تحديث الإشعارات');
                    }
                })
                .catch(() => {
                    alert('حدث خطأ في الاتصال');
                });
        }
    }
</script>

<?php require_once 'footer.php'; ?>
