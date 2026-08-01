<?php
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/crm_functions.php';

// Check if CRM is enabled
if (!is_crm_enabled()) {
    echo "<script>alert('وحدة CRM غير مفعلة حالياً'); location.href='../index.php';</script>";
    exit;
}

if (!has_permission_v3('crm_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit;
}

try {
    $stmt = $pdo->query("SELECT * FROM crm_activity_logs ORDER BY created_at DESC LIMIT 100");
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $logs = [];
}
?>
<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">سجل النشاط</h1>
            <p class="text-muted small mb-0">عرض جميع الأنشطة في CRM</p>
        </div>
        <a href="index.php" class="btn btn-light"><i class="fas fa-arrow-left me-2"></i> العودة</a>
    </div>

    <div class="apple-card">
        <div class="card-body p-0">
            <?php if (count($logs) > 0): ?>
                <div class="table-responsive">
                    <table class="apple-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>الإجراء</th>
                                <th>النموذج</th>
                                <th>المستخدم</th>
                                <th>الوقت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= h($log['id']) ?></td>
                                    <td><?= h($log['action']) ?></td>
                                    <td><?= h($log['model']) ?></td>
                                    <td><?= h($log['user_id']) ?></td>
                                    <td><?= h($log['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-5 text-center text-muted">
                    <i class="fas fa-history fa-3x mb-3"></i>
                    <p>لا توجد سجلات حتى الآن</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
