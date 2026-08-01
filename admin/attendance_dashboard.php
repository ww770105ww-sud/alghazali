<?php
$page_title = "لوحة إحصائيات الحضور والانصراف";
require_once 'header.php';

$settings = getSettings($pdo);
$date = $_GET['date'] ?? date('Y-m-d');
$stats = getAttendanceStats($date);
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i> لوحة إحصائيات الحضور والانصراف</h4>
            <small class="text-muted">تحديث فوري</small>
        </div>
        <div class="d-flex align-items-center">
            <label class="me-2 mb-0">تاريخ:</label>
            <input type="date" id="date_filter" class="form-control" value="<?php echo htmlspecialchars($date); ?>" style="width: 180px;">
            <button class="btn btn-primary ms-2" onclick="refreshDashboard()">
                <i class="fas fa-sync-alt me-1"></i> تحديث
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-users fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['present']; ?></div>
                            <div class="small opacity-75">الحاضرون اليوم</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-warning text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-clock fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['late']; ?></div>
                            <div class="small opacity-75">المتأخرون اليوم</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-danger text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-user-times fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['absent']; ?></div>
                            <div class="small opacity-75">الغائبون اليوم</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-info text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-sign-out-alt fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['left_early']; ?></div>
                            <div class="small opacity-75">المنصرفون مبكراً</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-hourglass-half fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['total_hours']; ?></div>
                            <div class="small opacity-75">إجمالي ساعات العمل</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 bg-secondary text-white h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-tachometer-alt fa-2x me-3 opacity-75"></i>
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['avg_work_hours']; ?></div>
                            <div class="small opacity-75">متوسط ساعات العمل</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Summary -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="fas fa-cog me-2 text-primary"></i> إعدادات الحضور الحالية</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-sign-in-alt me-1"></i>
                        <strong>السماح بالحضور قبل:</strong> <?php echo $settings['attendance_early_minutes']; ?> د
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-sign-in-alt me-1"></i>
                        <strong>السماح بالحضور بعد:</strong> <?php echo $settings['attendance_late_minutes']; ?> د
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-sign-out-alt me-1"></i>
                        <strong>السماح بالانصراف قبل:</strong> <?php echo $settings['departure_early_minutes']; ?> د
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-sign-out-alt me-1"></i>
                        <strong>السماح بالانصراف بعد:</strong> <?php echo $settings['departure_late_minutes']; ?> د
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <div class="alert <?php echo $settings['prevent_outside_attendance'] ? 'alert-danger' : 'alert-success'; ?> mb-0">
                    <i class="fas fa-shield-alt me-1"></i>
                    <strong>تقييد الحضور والانصراف:</strong>
                    <?php echo $settings['prevent_outside_attendance'] ? 'مفعل (لا يمكن تسجيل خارج الفترات المسموح بها)' : 'غير مفعل'; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="fas fa-link me-2 text-primary"></i> روابط سريعة</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-2">
                    <a href="settings.php?tab=time_date" class="btn btn-outline-primary btn-block w-100">
                        <i class="fas fa-cog me-1"></i> تعديل إعدادات الحضور
                    </a>
                </div>
                <div class="col-md-4 mb-2">
                    <a href="attendance_report.php" class="btn btn-outline-info btn-block w-100">
                        <i class="fas fa-file-alt me-1"></i> عرض تقرير الحضور
                    </a>
                </div>
                <div class="col-md-4 mb-2">
                    <a href="employees.php" class="btn btn-outline-success btn-block w-100">
                        <i class="fas fa-users me-1"></i> إدارة الموظفين
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto refresh every 30 seconds
setInterval(function() {
    refreshDashboard();
}, 30000);

function refreshDashboard() {
    var date = document.getElementById('date_filter').value;
    window.location.href = 'attendance_dashboard.php?date=' + date;
}
</script>

<?php require_once 'footer.php'; ?>