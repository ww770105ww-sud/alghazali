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
?>
<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">المساعد الافتراضي</h1>
            <p class="text-muted small mb-0">قريباً...</p>
        </div>
        <a href="index.php" class="btn btn-light"><i class="fas fa-arrow-left me-2"></i> العودة</a>
    </div>
    <div class="apple-card">
        <div class="card-body text-center py-5">
            <i class="fas fa-comments fa-5x mb-4 text-muted opacity-25"></i>
            <h3 class="text-muted">قريباً</h3>
            <p class="text-muted">هذه الميزة قيد التطوير</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../footer.php'; ?>
