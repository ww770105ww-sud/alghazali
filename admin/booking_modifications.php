<?php
/**
 * صفحة طلبات تعديل الحجوزات — booking_modifications.php
 * عرض: قائمة الطلبات / تقديم طلب تعديل جديد / موافقة أو رفض الطلب
 */
ob_start();
define('SYSTEM_ACCESS', true);
require_once __DIR__ . '/header.php';

if (!has_permission('bookings_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit;
}

$user_id   = (int)($_SESSION['admin_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'employee';
$is_admin  = in_array($user_role, ['admin','developer'], true);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

$error   = null;
$success = null;

// ─── (أ) تقديم طلب تعديل ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في التحقق من الطلب (CSRF).';
    } elseif (!has_permission('booking_modify_request') && !$is_admin) {
        $error = 'ليس لديك صلاحية تقديم طلب تعديل.';
    } else {
        try {
            $mod_id = 0;
            $stmt = $pdo->prepare("CALL sp_request_booking_modification(?,?,?,?,?,?,?,?,?,?,?,?,@mod_id)");
            $stmt->execute([
                (int)$_POST['booking_id'],
                $user_id,
                trim($_POST['modification_reason'] ?? ''),
                (int)($_POST['new_from_city_id'] ?? 0)   ?: null,
                (int)($_POST['new_to_city_id'] ?? 0)     ?: null,
                !empty($_POST['new_departure_date']) ? $_POST['new_departure_date'] : null,
                !empty($_POST['new_return_date'])    ? $_POST['new_return_date']    : null,
                in_array(($_POST['new_trip_type'] ?? ''),    ['one_way','round_trip'], true) ? $_POST['new_trip_type'] : null,
                in_array(($_POST['new_service_type'] ?? ''), ['bus','flight'], true)        ? $_POST['new_service_type'] : null,
                in_array(($_POST['new_bus_type'] ?? ''),     ['tourist','regular'], true)    ? $_POST['new_bus_type'] : null,
                isset($_POST['new_ticket_price'])    ? (float)$_POST['new_ticket_price'] : null,
                trim($_POST['new_notes'] ?? ''),
                isset($_POST['old_ticket_price'])    ? (float)$_POST['old_ticket_price'] : null,
            ]);
            $stmt->closeCursor();
            $res = $pdo->query("SELECT @mod_id AS id")->fetch();
            $mod_id = (int)($res['id'] ?? 0);
            $success = $mod_id > 0
                ? "✅ تم تقديم طلب التعديل بنجاح — رقم الطلب: <b>#{$mod_id}</b> — قيد المراجعة."
                : '⚠️ تم تقديم الطلب ولكن لم يتم إرجاع معرفه. تحقق من السجل.';
        } catch (Throwable $e) {
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

// ─── (ب) الموافقة / الرفض على طلب تعديل ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['approve_request']) || isset($_POST['reject_request']))) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في التحقق من الطلب (CSRF).';
    } elseif (!has_permission('booking_modify_approve') && !$is_admin) {
        $error = 'ليس لديك صلاحية الموافقة على التعديلات.';
    } else {
        try {
            $mod_id = (int)$_POST['mod_id'];
            $is_approved = isset($_POST['approve_request']) ? 1 : 0;
            $notes = trim($_POST['review_notes'] ?? '');
            $out_booking_id = 0;
            $stmt = $pdo->prepare("CALL sp_approve_booking_modification(?,?,?,?,@out_bid)");
            $stmt->execute([$mod_id, $user_id, $notes, $is_approved]);
            $stmt->closeCursor();
            $res = $pdo->query("SELECT @out_bid AS bid")->fetch();
            $out_booking_id = (int)($res['bid'] ?? 0);
            $success = $is_approved
                ? "✅ تمت الموافقة على تعديل الحجز رقم <b>#{$out_booking_id}</b> وتطبيق التغييرات."
                : "❌ تم رفض طلب التعديل رقم <b>#{$mod_id}</b>.";
        } catch (Throwable $e) {
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

// ─── (ج) فلترة القائمة ──────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where = ['1=1']; $params = [];
if ($statusFilter !== 'all') { $where[] = "bm.approval_status = ?"; $params[] = $statusFilter; }
if ($branch_id > 0 && !$is_admin) { $where[] = "bfb.branch_id = ?"; $params[] = $branch_id; }
if ($search !== '') {
    $where[] = "(bm.id = ? OR bfb.booking_number LIKE ? OR bfb.traveler_name LIKE ? OR bm.modification_reason LIKE ?)";
    $params[] = ctype_digit($search) ? (int)$search : 0;
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}
$whereSQL = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM booking_modifications bm JOIN bus_flight_bookings bfb ON bfb.id = bm.booking_id WHERE $whereSQL");
$countStmt->execute($params); $total = (int)$countStmt->fetchColumn();

$rows = $pdo->prepare("
    SELECT bm.*, bfb.booking_number, bfb.traveler_name, bfb.service_type, bfb.from_city_id, bfb.to_city_id,
           fc.city_name AS from_city_name, tc.city_name AS to_city_name,
           req.full_name AS requested_by_name, rev.full_name AS reviewed_by_name
      FROM booking_modifications bm
      JOIN bus_flight_bookings bfb ON bfb.id = bm.booking_id
      LEFT JOIN cities fc ON fc.id = COALESCE(bm.new_from_city_id, bfb.from_city_id)
      LEFT JOIN cities tc ON tc.id = COALESCE(bm.new_to_city_id,   bfb.to_city_id)
      LEFT JOIN users req ON req.id = bm.requested_by
      LEFT JOIN users rev ON rev.id = bm.reviewed_by
     WHERE $whereSQL
     ORDER BY bm.id DESC LIMIT $perPage OFFSET $offset
");
$rows->execute($params); $rows = $rows->fetchAll();
$pages = ceil($total / $perPage);

// قائمة المدن للفورم
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name")->fetchAll();
?>
<div class="container-fluid mt-4" dir="rtl">
    <?php if ($error):   ?><div class="alert alert-danger shadow-sm rounded-4 mb-3"><?= $error ?></div><?php endif ?>
    <?php if ($success): ?><div class="alert alert-success shadow-sm rounded-4 mb-3"><?= $success ?></div><?php endif ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h3 class="fw-bold mb-0">
            <i class="fas fa-edit text-warning me-2"></i>
            طلبات تعديل الحجوزات
            <span class="badge bg-warning-subtle text-warning rounded-pill fs-6 ms-2"><?= number_format($total) ?></span>
        </h3>
        <button class="btn btn-warning rounded-pill shadow-sm px-4 text-white" data-bs-toggle="modal" data-bs-target="#newModModal">
            <i class="fas fa-plus-circle me-1"></i> تقديم طلب تعديل جديد
        </button>
    </div>

    <!-- الفلاتر -->
    <form class="card shadow-sm rounded-4 p-3 mb-4" method="get">
        <div class="row g-3 align-items-end">
            <div class="col-lg-6">
                <label class="small text-muted">بحث</label>
                <input type="text" class="form-control rounded-3" name="q" value="<?= h($search) ?>"
                    placeholder="رقم الطلب / رقم الحجز / اسم المسافر / سبب التعديل">
            </div>
            <div class="col-lg-3">
                <label class="small text-muted">حالة الطلب</label>
                <select class="form-select rounded-3" name="status">
                    <option value="all"       <?= $statusFilter==='all'?'selected':'' ?>>الكل</option>
                    <option value="pending"   <?= $statusFilter==='pending'?'selected':'' ?>>قيد الانتظار</option>
                    <option value="approved"  <?= $statusFilter==='approved'?'selected':'' ?>>تمت الموافقة</option>
                    <option value="rejected"  <?= $statusFilter==='rejected'?'selected':'' ?>>مرفوض</option>
                    <option value="cancelled" <?= $statusFilter==='cancelled'?'selected':'' ?>>ألغي الطلب</option>
                </select>
            </div>
            <div class="col-lg-3 d-flex gap-2">
                <button class="btn btn-outline-primary rounded-3 flex-grow-1"><i class="fas fa-filter me-1"></i> فلترة</button>
                <a href="booking_modifications.php" class="btn btn-outline-secondary rounded-3"><i class="fas fa-redo me-1"></i> مسح</a>
            </div>
        </div>
    </form>

    <!-- الجدول -->
    <div class="card shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-nowrap">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>رقم الطلب</th><th>الحجز</th><th>المسافر</th>
                        <th>التعديلات المقترحة</th><th>الفرق المالي</th><th>الحالة</th>
                        <th>المقدّم</th><th>المراجع</th><th>تاريخ الطلب</th><th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="11" class="text-center text-muted py-5">⚠️ لا توجد طلبات مطابقة للبحث.</td></tr>
                <?php endif ?>
                <?php foreach ($rows as $i => $r):
                    $changes = [];
                    if ($r['new_departure_date'])  $changes[] = "تاريخ الانطلاق: <b>{$r['new_departure_date']}</b>";
                    if ($r['new_return_date'])     $changes[] = "تاريخ العودة: <b>{$r['new_return_date']}</b>";
                    if ($r['new_trip_type'])       $changes[] = "نوع الرحلة: <b>" . ($r['new_trip_type'] === 'round_trip' ? 'ذهاب وعودة' : 'ذهاب فقط') . "</b>";
                    if ($r['new_service_type'])    $changes[] = "نوع الخدمة: <b>" . ($r['new_service_type'] === 'bus' ? 'باص' : 'طيران') . "</b>";
                    if ($r['new_bus_type'])        $changes[] = "نوع الباص: <b>" . ($r['new_bus_type'] === 'tourist' ? 'سياحي' : 'عادي') . "</b>";
                    if ($r['new_from_city_id'])    $changes[] = "من مدينة: <b>{$r['from_city_name']}</b>";
                    if ($r['new_to_city_id'])      $changes[] = "إلى مدينة: <b>{$r['to_city_name']}</b>";
                    if ($r['new_ticket_price'] !== null) $changes[] = "السعر الجديد: <b>" . number_format((float)$r['new_ticket_price'],2) . "</b>";
                    if (!$changes) $changes[] = '<span class="text-muted">تعديلات في الملاحظات فقط</span>';
                ?>
                    <tr>
                        <td><?= $i + 1 + $offset ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary rounded-pill fs-6 px-3 py-1">#<?= (int)$r['id'] ?></span></td>
                        <td><a href="bus_flight_bookings_details.php?id=<?= (int)$r['booking_id'] ?>" target="_blank" class="fw-semibold"><?= h($r['booking_number']) ?></a></td>
                        <td>
                            <div class="fw-bold"><?= h($r['traveler_name']) ?></div>
                            <small class="text-muted"><?= h($r['service_type'] === 'flight' ? 'طيران' : 'باص') ?></small>
                        </td>
                        <td>
                            <div class="mb-1"><b class="text-danger">السبب:</b> <?= h(mb_substr($r['modification_reason'], 0, 60)) ?><?= mb_strlen($r['modification_reason'])>60?'...':'' ?></div>
                            <div class="small"><?= implode(' • ', $changes) ?></div>
                            <?php if ($r['new_notes']): ?><small class="d-block text-muted mt-1">ملاحظات: <?= h(mb_substr($r['new_notes'],0,50)) ?></small><?php endif ?>
                        </td>
                        <td>
                            <?php $diff = (float)$r['price_difference'];
                                if ($diff > 0) echo '<span class="badge bg-danger rounded-pill">➕ ' . number_format($diff,2) . '</span>';
                                elseif ($diff < 0) echo '<span class="badge bg-success rounded-pill">➖ ' . number_format(-$diff,2) . '</span>';
                                else echo '<span class="text-muted">بدون فرق</span>';
                            ?>
                        </td>
                        <td>
                            <?php
                                $map = [
                                    'pending'   => ['warning', 'قيد الانتظار'],
                                    'approved'  => ['success', 'تمت الموافقة'],
                                    'rejected'  => ['danger',  'مرفوض'],
                                    'cancelled' => ['secondary', 'ألغي الطلب'],
                                ];
                                [$cls, $txt] = $map[$r['approval_status']] ?? ['secondary', $r['approval_status']];
                            ?>
                            <span class="badge bg-<?= $cls ?> rounded-pill px-3 py-1"><?= $txt ?></span>
                        </td>
                        <td><small><?= h($r['requested_by_name'] ?? '-') ?></small></td>
                        <td><small><?= h($r['reviewed_by_name'] ?? '<span class="text-muted">—</span>') ?></small></td>
                        <td><small><?= h(date('Y-m-d', strtotime($r['created_at']))) ?></small></td>
                        <td>
                            <?php if ($r['approval_status'] === 'pending' && (has_permission('booking_modify_approve') || $is_admin)): ?>
                                <div class="d-flex gap-1 flex-wrap">
                                    <button class="btn btn-sm btn-success rounded-3" data-bs-toggle="modal" data-bs-target="#approveModal_<?= (int)$r['id'] ?>">
                                        <i class="fas fa-check me-1"></i> موافقة
                                    </button>
                                    <button class="btn btn-sm btn-danger rounded-3"  data-bs-toggle="modal" data-bs-target="#rejectModal_<?= (int)$r['id'] ?>">
                                        <i class="fas fa-times me-1"></i> رفض
                                    </button>
                                </div>
                                <!-- الموافقة -->
                                <div class="modal fade" id="approveModal_<?= (int)$r['id'] ?>" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content rounded-4">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="mod_id" value="<?= (int)$r['id'] ?>">
                                    <div class="modal-header bg-success text-white rounded-top-4"><h5 class="modal-title">الموافقة على الطلب #<?= (int)$r['id'] ?></h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body"><textarea class="form-control rounded-3" rows="3" name="review_notes" placeholder="ملاحظات الموافقة (اختياري)"></textarea></div>
                                    <div class="modal-footer"><button class="btn btn-light rounded-pill" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-success rounded-pill px-4" name="approve_request">تأكيد الموافقة</button></div>
                                </form></div></div>
                                <!-- الرفض -->
                                <div class="modal fade" id="rejectModal_<?= (int)$r['id'] ?>" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content rounded-4">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="mod_id" value="<?= (int)$r['id'] ?>">
                                    <div class="modal-header bg-danger text-white rounded-top-4"><h5 class="modal-title">رفض الطلب #<?= (int)$r['id'] ?></h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body"><textarea class="form-control rounded-3" rows="3" name="review_notes" placeholder="سبب الرفض *" required></textarea></div>
                                    <div class="modal-footer"><button class="btn btn-light rounded-pill" data-bs-dismiss="modal">رجوع</button><button class="btn btn-danger rounded-pill px-4" name="reject_request">تأكيد الرفض</button></div>
                                </form></div></div>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
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

<!-- Modal: تقديم طلب تعديل جديد -->
<div class="modal fade" id="newModModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <form method="post" class="modal-content rounded-4 border-0">
            <?= csrf_input() ?>
            <input type="hidden" name="submit_request" value="1">
            <div class="modal-header bg-warning-subtle border-0 rounded-top-4">
                <h5 class="modal-title fw-bold text-warning-emphasis"><i class="fas fa-edit me-2"></i> تقديم طلب تعديل حجز</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info small rounded-3"><b>💡 ملاحظة:</b> اترك أي حقل فارغاً إذا كنت لا ترغب بتعديله.</div>
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label small fw-bold">اختر الحجز *</label>
                        <select class="form-select form-select-lg rounded-3" name="booking_id" id="mod_booking_select" required>
                            <option value="">-- جاري تحميل الحجوزات... --</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-bold">سبب التعديل *</label>
                        <textarea class="form-control rounded-3" rows="2" name="modification_reason" required></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">من مدينة</label>
                        <select class="form-select rounded-3" name="new_from_city_id"><option value="">بدون تغيير</option><?php foreach ($cities as $c):?><option value="<?= $c['id'] ?>"><?= h($c['city_name']) ?></option><?php endforeach ?></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">إلى مدينة</label>
                        <select class="form-select rounded-3" name="new_to_city_id"><option value="">بدون تغيير</option><?php foreach ($cities as $c):?><option value="<?= $c['id'] ?>"><?= h($c['city_name']) ?></option><?php endforeach ?></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">نوع الخدمة</label>
                        <select class="form-select rounded-3" name="new_service_type"><option value="">بدون تغيير</option><option value="bus">باص</option><option value="flight">طيران</option></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">نوع الرحلة</label>
                        <select class="form-select rounded-3" name="new_trip_type"><option value="">بدون تغيير</option><option value="one_way">ذهاب فقط</option><option value="round_trip">ذهاب وعودة</option></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">نوع الباص</label>
                        <select class="form-select rounded-3" name="new_bus_type"><option value="">بدون تغيير</option><option value="tourist">سياحي</option><option value="regular">عادي</option></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">تاريخ الانطلاق</label>
                        <input type="date" class="form-control rounded-3" name="new_departure_date">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">تاريخ العودة</label>
                        <input type="date" class="form-control rounded-3" name="new_return_date">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">السعر القديم (لحساب الفرق)</label>
                        <input type="number" step="0.01" class="form-control rounded-3" name="old_ticket_price" placeholder="0.00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">السعر الجديد</label>
                        <input type="number" step="0.01" class="form-control rounded-3" name="new_ticket_price" placeholder="0.00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">الفرق التلقائي</label>
                        <input type="text" class="form-control rounded-3 bg-light" id="price_diff_preview" readonly placeholder="سيُظهر الفرق هنا">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-bold">ملاحظات إضافية على التعديل</label>
                        <textarea class="form-control rounded-3" rows="2" name="new_notes"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-warning text-white rounded-pill px-5 shadow-sm"><i class="fas fa-paper-plane me-1"></i> تقديم الطلب للموافقة</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('[name="old_ticket_price"], [name="new_ticket_price"]').forEach(function(el){
    el.addEventListener('input', calcDiff);
});
function calcDiff() {
    const oldV = parseFloat(document.querySelector('[name="old_ticket_price"]').value) || 0;
    const newV = parseFloat(document.querySelector('[name="new_ticket_price"]').value) || 0;
    const diff = newV - oldV;
    const box  = document.getElementById('price_diff_preview');
    if (!box) return;
    if (!diff) box.value = 'بدون فرق';
    else if (diff > 0) box.value = '➕ زيادة: ' + diff.toFixed(2);
    else              box.value = '➖ خصم: ' + Math.abs(diff).toFixed(2);
}
// تحميل الحجوزات النشطة
fetch('ajax/ajax_get_active_bookings.php').then(r=>r.json()).then(data => {
    const el = document.getElementById('mod_booking_select');
    if (el) el.innerHTML = '<option value="">اختر الحجز...</option>' +
        data.map(b => `<option value="${b.id}">${b.booking_number} — ${b.traveler_name}</option>`).join('');
}).catch(() => {});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/footer.php';
