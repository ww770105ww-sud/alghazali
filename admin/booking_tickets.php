<?php
/**
 * booking_tickets.php — صفحة التذاكر الرقمية للحجوزات
 * عرض / إصدار / طباعة  / إبطال التذاكر
 * الملف: admin/booking_tickets.php
 */

ob_start();
define('SYSTEM_ACCESS', true);
require_once __DIR__ . '/header.php';

if (!has_permission('bookings_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit;
}

$user_id     = (int)($_SESSION['admin_id'] ?? 0);
$user_role   = $_SESSION['role'] ?? 'employee';
$is_admin    = in_array($user_role, ['admin','developer'], true);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

$error   = null;
$success = null;

// ─── (أ) إصدار تذكرة جديدة ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_ticket'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في التحقق من الطلب (CSRF).';
    } elseif (!has_permission('booking_ticket_issue') && !$is_admin) {
        $error = 'ليس لديك صلاحية إصدار التذاكر الرقمية.';
    } else {
        try {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $stmt = $pdo->prepare("CALL sp_generate_ticket(?,?,?,?,?,?,?, @tid, @tnum)");
            $stmt->execute([
                $booking_id,
                $user_id,
                trim($_POST['seat_number'] ?? ''),
                trim($_POST['pnr'] ?? ''),
                trim($_POST['supplier_reference'] ?? ''),
                trim($_POST['bus_flight_number'] ?? ''),
                rtrim(trim($_POST['public_base_url'] ?? (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/alghazali/ticket/verify'), '/')
            ]);
            $stmt->closeCursor();
            $ticket = $pdo->query("SELECT @tid AS id, @tnum AS number")->fetch();
            $success = "✅ تم إصدار التذكرة بنجاح — رقم التذكرة: <b>{$ticket['number']}</b>";
        } catch (Throwable $e) {
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

// ─── (ب) إبطال تذكرة ──────────────────────────────────────────────────
if (isset($_GET['void']) && ctype_digit($_GET['void'])) {
    $tid = (int)$_GET['void'];
    if (!has_permission('booking_ticket_issue') && !$is_admin) {
        $error = 'ليس لديك صلاحية إبطال التذاكر.';
    } else {
        try {
            $pdo->prepare("UPDATE booking_tickets SET is_void = 1, updated_at = NOW() WHERE id = ?")->execute([$tid]);
            log_audit($pdo, 'void_ticket', 'booking_tickets', $tid, [], ['is_void' => 1], 'إبطال تذكرة رقمية');
            $success = '✅ تم إبطال التذكرة بنجاح.';
        } catch (Throwable $e) {
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

// ─── (ج) فلترة البيانات ───────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$vFilter  = $_GET['validity'] ?? 'all';
$sFilter  = $_GET['service']  ?? 'all';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;

$where = ['1=1']; $params = [];
if ($search !== '') {
    $where[] = "(bt.ticket_number LIKE ? OR bt.traveler_name LIKE ? OR bfb.booking_number LIKE ? OR bt.verification_token LIKE ? OR bt.pnr LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like,$like,$like,$like,$like]);
}
if ($branch_id > 0 && !$is_admin) { $where[] = "bfb.branch_id = ?"; $params[] = $branch_id; }
if ($vFilter === 'valid')   $where[] = "bt.is_void = 0";
if ($vFilter === 'void')    $where[] = "bt.is_void = 1";
if ($sFilter !== 'all')    { $where[] = "bt.service_type = ?"; $params[] = $sFilter; }
$whereSQL = implode(' AND ', $where);

$total = (int)$pdo->prepare("
    SELECT COUNT(*) FROM booking_tickets bt
    JOIN bus_flight_bookings bfb ON bfb.id = bt.booking_id
    WHERE $whereSQL
")->execute($params) ? 0 : 0;
$totalStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM booking_tickets bt JOIN bus_flight_bookings bfb ON bfb.id=bt.booking_id WHERE $whereSQL");
$totalStmt->execute($params); $total = (int)$totalStmt->fetchColumn();

$tickets = $pdo->prepare("
    SELECT bt.*, bfb.booking_number, bfb.branch_id, u.full_name AS issued_by_name
      FROM booking_tickets bt
      JOIN bus_flight_bookings bfb ON bfb.id = bt.booking_id
      LEFT JOIN users u ON u.id = bt.issued_by
     WHERE $whereSQL
     ORDER BY bt.id DESC LIMIT $perPage OFFSET $offset
");
$tickets->execute($params);
$tickets = $tickets->fetchAll();

$pages = ceil($total / $perPage);
?>
<div class="container-fluid mt-4" dir="rtl">
    <?php if ($error):   ?><div class="alert alert-danger shadow-sm rounded-4 mb-3"><?= $error ?></div><?php endif ?>
    <?php if ($success): ?><div class="alert alert-success shadow-sm rounded-4 mb-3"><?= $success ?></div><?php endif ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h3 class="fw-bold mb-0">
            <i class="fas fa-ticket-alt text-primary me-2"></i>
            التذاكر الرقمية للحجوزات
            <span class="badge bg-primary-subtle text-primary rounded-pill fs-6 ms-2"><?= number_format($total) ?></span>
        </h3>
        <button class="btn btn-primary rounded-pill shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#issueTicketModal">
            <i class="fas fa-plus-circle me-1"></i> إصدار تذكرة جديدة
        </button>
    </div>

    <!-- الفلاتر -->
    <form class="card shadow-sm rounded-4 p-3 mb-4" method="get">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="small text-muted">بحث عام</label>
                <input type="text" class="form-control rounded-3" name="q"
                       value="<?= h($search) ?>" placeholder="رقم التذكرة / اسم المسافر / رقم الحجز / PNR">
            </div>
            <div class="col-lg-2">
                <label class="small text-muted">نوع الخدمة</label>
                <select class="form-select rounded-3" name="service">
                    <option value="all" <?= $sFilter==='all'?'selected':'' ?>>الكل</option>
                    <option value="bus"    <?= $sFilter==='bus'?'selected':'' ?>>باص</option>
                    <option value="flight" <?= $sFilter==='flight'?'selected':'' ?>>طيران</option>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="small text-muted">صحة التذكرة</label>
                <select class="form-select rounded-3" name="validity">
                    <option value="all"   <?= $vFilter==='all'?'selected':'' ?>>الكل</option>
                    <option value="valid" <?= $vFilter==='valid'?'selected':'' ?>>صالحة فقط</option>
                    <option value="void"  <?= $vFilter==='void'?'selected':'' ?>>ملغاة فقط</option>
                </select>
            </div>
            <div class="col-lg-4 d-flex gap-2">
                <button class="btn btn-outline-primary rounded-3 flex-grow-1"><i class="fas fa-filter me-1"></i> فلترة</button>
                <a href="booking_tickets.php" class="btn btn-outline-secondary rounded-3"><i class="fas fa-redo me-1"></i> مسح</a>
            </div>
        </div>
    </form>

    <!-- الجدول -->
    <div class="card shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-nowrap">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>رقم التذكرة</th><th>الحجز</th><th>المسافر</th>
                        <th>النوع</th><th>المسار</th><th>تاريخ الانطلاق</th>
                        <th>السعر</th><th>الحالة</th><th>الصادر من</th><th>تاريخ الإصدار</th><th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$tickets): ?>
                    <tr><td colspan="12" class="text-center text-muted py-5">⚠️ لا توجد تذاكر مطابقة للبحث.</td></tr>
                <?php endif ?>
                <?php foreach ($tickets as $i => $t): ?>
                    <tr class="<?= $t['is_void'] ? 'table-danger' : '' ?>">
                        <td><?= $i + 1 + $offset ?></td>
                        <td>
                            <span class="badge bg-info-subtle text-info rounded-pill fs-6 px-3 py-1"><?= h($t['ticket_number']) ?></span>
                        </td>
                        <td><a href="bus_flight_bookings_details.php?id=<?= (int)$t['booking_id'] ?>" target="_blank" class="fw-semibold"><?= h($t['booking_number']) ?></a></td>
                        <td>
                            <div class="fw-bold"><?= h($t['traveler_name']) ?></div>
                            <small class="text-muted"><?= h($t['id_type']) ?>: <?= h($t['id_number']) ?></small>
                        </td>
                        <td>
                            <?php if ($t['service_type']==='flight'): ?>
                                <span class="badge bg-primary rounded-pill"><i class="fas fa-plane me-1"></i> طيران</span>
                            <?php else: ?>
                                <span class="badge bg-success rounded-pill"><i class="fas fa-bus me-1"></i> باص</span>
                            <?php endif ?>
                            <small class="d-block text-muted mt-1"><?= h($t['trip_type'] === 'one_way' ? 'ذهاب' : 'ذهاب وعودة') ?></small>
                        </td>
                        <td><b><?= h($t['departure_city_name']) ?></b> <i class="fas fa-arrow-left text-muted mx-1"></i> <b><?= h($t['arrival_city_name']) ?></b></td>
                        <td><?= $t['departure_datetime'] ? h(date('Y-m-d', strtotime($t['departure_datetime']))) : '<span class="text-muted">—</span>' ?></td>
                        <td class="fw-bold text-primary"><?= number_format((float)$t['total_amount'], 2) ?> <small class="text-muted"><?= h($t['currency_code']) ?></small></td>
                        <td>
                            <?php if ($t['is_void']): ?>
                                <span class="badge bg-danger rounded-pill">ملغاة</span>
                            <?php else: ?>
                                <span class="badge bg-success rounded-pill">صالحة</span>
                            <?php endif ?>
                        </td>
                        <td><small><?= h($t['issued_by_name'] ?? '-') ?></small></td>
                        <td><small><?= h(date('Y-m-d', strtotime($t['issued_at']))) ?></small></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a class="btn btn-sm btn-outline-info rounded-3" href="bus_flight_bookings_ticket.php?id=<?= (int)$t['booking_id'] ?>&ticket=<?= (int)$t['id'] ?>" target="_blank">
                                    <i class="fas fa-print me-1"></i> طباعة
                                </a>
                                <?php if (!$t['is_void'] && (has_permission('booking_ticket_issue') || $is_admin)): ?>
                                    <a class="btn btn-sm btn-outline-danger rounded-3"
                                       href="booking_tickets.php?void=<?= (int)$t['id'] ?>"
                                       onclick="return confirm('هل أنت متأكد من إبطال هذه التذكرة؟')">
                                        <i class="fas fa-ban"></i>
                                    </a>
                                <?php endif ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <!-- ترقيم الصفحات -->
        <?php if ($pages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted">الصفحة <?= $page ?> من <?= $pages ?> (إجمالي <?= $total ?>)</small>
                <nav>
                    <ul class="pagination mb-0">
                        <?php for ($i = max(1, $page-3); $i <= min($pages, $page+3); $i++): ?>
                            <li class="page-item <?= $i===$page?'active':'' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor ?>
                    </ul>
                </nav>
            </div>
        <?php endif ?>
    </div>
</div>

<!-- Modal: إصدار تذكرة جديدة -->
<div class="modal fade" id="issueTicketModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content rounded-4 border-0">
            <?= csrf_input() ?>
            <input type="hidden" name="issue_ticket" value="1">
            <div class="modal-header bg-primary text-white rounded-top-4">
                <h5 class="modal-title"><i class="fas fa-ticket-alt me-2"></i> إصدار تذكرة رقمية جديدة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold">اختر الحجز *</label>
                        <select name="booking_id" id="selectBookingId" class="form-select form-select-lg rounded-3" required></select>
                        <small class="text-muted">سيظهر أسماء الحجوزات التي لم يتم إصدار تذاكرها بعد أو التي تحتاج لإصدار نسخة محدثة.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">رقم المقعد</label>
                        <input type="text" name="seat_number" class="form-control rounded-3" placeholder="A17 / 12-B">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">PNR الرحلة</label>
                        <input type="text" name="pnr" class="form-control rounded-3" placeholder="SUP-XYZ">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">رقم الباص / الرحلة</label>
                        <input type="text" name="bus_flight_number" class="form-control rounded-3" placeholder="BUS-09 / SV-882">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">مرجع المورد</label>
                        <input type="text" name="supplier_reference" class="form-control rounded-3" placeholder="SUP-4491">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">الرابط العام للتحقق من التذكرة (Base URL)</label>
                        <div class="input-group">
                            <input type="url" name="public_base_url" id="pbu" class="form-control rounded-3"
                                value="<?= htmlspecialchars((isset($_SERVER['HTTPS'])?'https://':'http://').($_SERVER['HTTP_HOST']??'localhost').'/alghazali/public_ticket_verify.php') ?>">
                            <button class="btn btn-outline-primary" type="button" onclick="document.getElementById('pbu').value='<?= htmlspecialchars((isset($_SERVER['HTTPS'])?'https://':'http://').($_SERVER['HTTP_HOST']??'localhost').'/alghazali/public_ticket_verify.php') ?>'">
                                إعادة ضبط
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary rounded-pill px-5 shadow-sm">
                    <i class="fas fa-check-circle me-1"></i> إصدار التذكرة
                </button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript: تحميل قائمة الحجوزات عبر AJAX للبحث الديناميكي -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('selectBookingId');
    if (!el) return;
    const tm = setTimeout(() => { /* placeholder for future remote select2 */ }, 100);
    // التحميل الابتدائي لأحدث 50 حجزاً بدون تذكرة
    fetch('ajax/ajax_get_bookings_without_ticket.php')
        .then(r => r.json()).then(data => {
            el.innerHTML = data.map(b => `<option value="${b.id}">${b.booking_number} — ${b.traveler_name} (${b.from_city} → ${b.to_city})</option>`).join('');
        }).catch(() => { el.innerHTML = '<option>تعذر تحميل الحجوزات</option>'; });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/footer.php';
