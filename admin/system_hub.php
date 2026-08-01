<?php
$page_title = "مركز تهيئة النظام";
require_once 'header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-secondary text-white p-4">
                <div class="d-flex align-items-center">
                    <div class="hub-icon-large me-4">
                        <i class="fas fa-cogs fa-3x opacity-50"></i>
                    </div>
                    <div>
                        <h2 class="fw-bold mb-1">مركز تهيئة النظام</h2>
                        <p class="mb-0 opacity-75">إدارة الإعدادات العامة، صلاحيات المستخدمين، ونظام سير العمل.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Section 1: General Settings -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-sliders-h me-2 text-primary"></i> الإعدادات الأساسية</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <a href="settings.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-cog me-2 text-secondary"></i> إعدادات النظام العامة
                        </a>
                        <a href="currencies.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-money-bill-wave me-2 text-success"></i> إدارة العملات وأسعار الصرف
                        </a>
                        <a href="manage_branches.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-code-branch me-2 text-info"></i> إدارة الفروع والمكاتب
                        </a>
                        <a href="branches.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-book me-2 text-secondary"></i> حسابات الفروع (الحسابات المالية)
                        </a>
                        <a href="countries.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-globe me-2 text-success"></i> إدارة الدول
                        </a>
                        <a href="cities.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-city me-2 text-primary"></i> إدارة المدن
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Users & Permissions -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-user-shield me-2 text-warning"></i> المستخدمين والصلاحيات</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <a href="users.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-users-cog me-2 text-primary"></i> إدارة مستخدمي النظام
                        </a>
                        <a href="roles.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-shield-alt me-2 text-danger"></i> الأدوار وصلاحيات الوصول
                        </a>
                        <a href="employees.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-id-card me-2 text-info"></i> سجل الموظفين
                        </a>
                        <a href="attendance_dashboard.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-chart-line me-2 text-purple"></i> لوحة إحصائيات الحضور
                        </a>
                        <a href="attendance_report.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-clipboard-list me-2 text-warning"></i> تقرير سجل الدوام
                        </a>
                        <a href="job_settings.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-briefcase me-2 text-success"></i> الرواتب وفترات الدوام
                        </a>
                        <a href="user_activity_log.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-history me-2 text-secondary"></i> سجل المستخدمين والنشاط
                        </a>
                        <a href="user_sessions.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-users-cog me-2 text-info"></i> إدارة الجلسات
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Workflow & Services -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-project-diagram me-2 text-info"></i> العمليات والخدمات</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <a href="workflow.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-route me-2 text-info"></i> إدارة مسارات سير العمل (Workflow)
                        </a>
                        <a href="workflow_fields.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-list-ul me-2 text-primary"></i> إدارة حقول سير العمل (الديناميكية)
                        </a>
                        <a href="workflow_step_fields.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-link me-2 text-success"></i> ربط الحقول بالخطوات (العلاقات)
                        </a>
                        <a href="services.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-briefcase me-2 text-secondary"></i> تعريف خدمات النظام
                        </a>
                        <a href="service_prices.php" class="list-group-item list-group-item-action border-0 py-3 rounded-3 mb-2 bg-light">
                            <i class="fas fa-tags me-2 text-warning"></i> أسعار الخدمات والعمولات
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.list-group-item-action:hover {
    transform: translateX(-5px);
    transition: all 0.2s ease-in-out;
    background-color: #f0f2f5 !important;
}
</style>

<?php require_once 'footer.php'; ?>
