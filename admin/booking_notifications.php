<?php
/**
 * صفحة إشعارات الحجوزات — booking_notifications.php
 * عرض كل الإشعارات المرسلة + إنشاء إشعار جديد يدوي + إرسال مجدول
 * يعتمد على الجدول booking_notifications الذي يكتمل تلقائياً عند استدعاء SPs
 */
ob_start();
define('SYSTEM_ACCESS', true);
require_once __DIR__ . '/header.php';
if (!has_permission('bookings_view')) { echo "<script>alert('غير مصرح'); location.href='index.php';</script>"; exit; }
$user_id   = (int)($_SESSION['admin_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'employee';
$is_admin  = in_array($user_role, ['admin','developer'], true);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$error = null; $success = null;

// ─── (أ) إنشاء إشعار جديد يدوي ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_notif'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error='خطأ CSRF'; }
    elseif (!has_permission('booking_notification_create') && !$is_admin) { $error='ليس لديك صلاحية إنشاء إشعارات'; }
    else {
        try {
            $out_id = 0;
            $stmt = $pdo->prepare("CALL sp_create_booking_notification(?,?,?,?,?,?,?,?,@nid)");
            $stmt->execute([
                (int)$_POST['booking_id'] ?: null,
                (int)$_POST['user_id']    ?: null,
                !empty($_POST['customer_mobile']) ? trim($_POST['customer_mobile']) : null,
                !empty($_POST['customer_email'])  ? trim($_POST['customer_email'])  : null,
                trim($_POST['notification_type'] ?? 'info'),
                trim($_POST['title'] ?? ''),
                trim($_POST['message'] ?? ''),
                !empty($_POST['scheduled_at']) ? $_POST['scheduled_at'] : null,
                trim($_POST['extra_data_json'] ?? null),
            ]);
            $stmt->closeCursor();
            $res = $pdo->query("SELECT @nid AS id")->fetch();
            $out_id = (int)($res['id'] ?? 0);
            $success = $out_id > 0 ? "✅ تم إنشاء الإشعار #{$out_id} بنجاح." : '⚠️ تم تسجيل الطلب ولكن لم يتم إرجاع معرف الإشعار.';
        } catch (Throwable $e) { $error = 'خطأ: '.$e->getMessage(); }
    }
}

// ─── (ب) إعادة إرسال الإشعار أو تحديث حالته إلى مقروء/إلغاء ───────
if (isset($_GET['mark_read']) && ctype_digit($_GET['mark_read'])) {
    try {
        $nid = (int)$_GET['mark_read'];
        $pdo->prepare("UPDATE booking_notifications SET is_read=1, read_at=NOW() WHERE id=?")->execute([$nid]);
        $success = '✅ تم تحديد الإشعار كمقروء.';
    } catch (Throwable $e) { $error = 'خطأ: '.$e->getMessage(); }
}
if (isset($_GET['cancel']) && ctype_digit($_GET['cancel'])) {
    try {
        $nid = (int)$_GET['cancel'];
        $pdo->prepare("UPDATE booking_notifications SET status='cancelled' WHERE id=? AND status='pending'")->execute([$nid]);
        $success = '✅ تم إلغاء الإشعار المجدول.';
    } catch (Throwable $e) { $error = 'خطأ: '.$e->getMessage(); }
}

// ─── (ج) فلترة + عرض الإشعارات ───────────────────────────────────
$typeFilter   = $_GET['type'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$chanFilter   = $_GET['channel'] ?? 'all';
$readFilter   = $_GET['read'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1)); $perPage = 25; $offset = ($page-1)*$perPage;
$where=['1=1']; $params=[];
if ($typeFilter!=='all')   { $where[]="bn.notification_type=?"; $params[]=$typeFilter; }
if ($statusFilter!=='all') { $where[]="bn.status=?"; $params[]=$statusFilter; }
if ($chanFilter!=='all')   { $where[]="bn.channel=?"; $params[]=$chanFilter; }
if ($readFilter==='read')  { $where[]="bn.is_read=1"; }
if ($readFilter==='unread'){ $where[]="bn.is_read=0"; }
if ($branch_id>0 && !$is_admin) { $where[]="bfb.branch_id=?"; $params[]=$branch_id; }
if ($search!=='') {
    $where[]="(bn.id=? OR bfb.booking_number LIKE ? OR bfb.traveler_name LIKE ? OR bn.title LIKE ? OR bn.message LIKE ? OR bn.sent_to_mobile LIKE ?)";
    $params[]=ctype_digit($search)?(int)$search:0;
    $like="%$search%"; $params=array_merge($params,[$like,$like,$like,$like,$like]);
}
$whereSQL = implode(' AND ', $where);
$c = $pdo->prepare("SELECT COUNT(*) FROM booking_notifications bn LEFT JOIN bus_flight_bookings bfb ON bfb.id=bn.booking_id WHERE $whereSQL");
$c->execute($params); $total=(int)$c->fetchColumn();
$rows = $pdo->prepare("
    SELECT bn.*, bfb.booking_number, bfb.traveler_name, bfb.service_type,
           u.full_name AS created_by_name
      FROM booking_notifications bn
      LEFT JOIN bus_flight_bookings bfb ON bfb.id=bn.booking_id
      LEFT JOIN users u ON u.id=bn.created_by
     WHERE $whereSQL
     ORDER BY bn.id DESC LIMIT $perPage OFFSET $offset
");
$rows->execute($params); $rows=$rows->fetchAll(); $pages=ceil($total/$perPage);
$stats = $pdo->query("SELECT
    SUM(status='pending') AS p_pending, SUM(status='sent') AS p_sent, SUM(status='failed') AS p_fail,
    SUM(is_read=0 AND status='sent') AS p_unread
    FROM booking_notifications")->fetch();
?>
<div class="container-fluid mt-4" dir="rtl">
    <?php if ($error):?><div class="alert alert-danger rounded-4 shadow-sm mb-3"><?=$error?></div><?php endif ?>
    <?php if ($success):?><div class="alert alert-success rounded-4 shadow-sm mb-3"><?=$success?></div><?php endif ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">معلقة / مجدولة</div><div class="fs-3 fw-bold text-warning"><?=(int)($stats['p_pending']??0)?></div></div><i class="fas fa-clock text-warning fs-2"></i></div></div></div>
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">تم الإرسال</div><div class="fs-3 fw-bold text-success"><?=(int)($stats['p_sent']??0)?></div></div><i class="fas fa-paper-plane text-success fs-2"></i></div></div></div>
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">فشلت</div><div class="fs-3 fw-bold text-danger"><?=(int)($stats['p_fail']??0)?></div></div><i class="fas fa-exclamation-circle text-danger fs-2"></i></div></div></div>
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">غير مقروءة</div><div class="fs-3 fw-bold text-primary"><?=(int)($stats['p_unread']??0)?></div></div><i class="fas fa-bell text-primary fs-2"></i></div></div></div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h3 class="fw-bold mb-0"><i class="fas fa-bell text-primary me-2"></i>إشعارات الحجوزات <span class="badge bg-primary-subtle text-primary rounded-pill ms-2"><?=number_format($total)?></span></h3>
        <button class="btn btn-primary rounded-pill shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#newNotif"><i class="fas fa-plus-circle me-1"></i> إنشاء إشعار جديد</button>
    </div>
    <form class="card rounded-4 p-3 shadow-sm mb-4" method="get">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4"><label class="small text-muted">بحث عام</label><input class="form-control rounded-3" name="q" value="<?=h($search)?>" placeholder="رقم الإشعار / الحجز / اسم المسافر / عنوان الرسالة"></div>
            <div class="col-lg-2"><label class="small text-muted">نوع الإشعار</label><select class="form-select rounded-3" name="type">
                <option value="all"<?=$typeFilter==='all'?' selected':''?>>الكل</option>
                <?php foreach(['info'=>'معلومات','confirmation'=>'تأكيد','cancellation'=>'إلغاء','refund'=>'استرداد','modification'=>'تعديل','ticket'=>'تذكرة','reminder'=>'تذكير','warning'=>'تحذير','error'=>'خطأ','system'=>'نظام'] as $k=>$v):?><option value="<?=$k?>"<?=$typeFilter===$k?' selected':''?>><?=$v?></option><?php endforeach ?>
            </select></div>
            <div class="col-lg-2"><label class="small text-muted">الحالة</label><select class="form-select rounded-3" name="status">
                <option value="all"<?=$statusFilter==='all'?' selected':''?>>الكل</option>
                <option value="pending"<?=$statusFilter==='pending'?' selected':''?>>معلق / مجدول</option>
                <option value="sent"<?=$statusFilter==='sent'?' selected':''?>>مُرسل</option>
                <option value="delivered"<?=$statusFilter==='delivered'?' selected':''?>>مُستلم</option>
                <option value="read"<?=$statusFilter==='read'?' selected':''?>>مقروء</option>
                <option value="failed"<?=$statusFilter==='failed'?' selected':''?>>فشل</option>
                <option value="cancelled"<?=$statusFilter==='cancelled'?' selected':''?>>ألغي</option>
            </select></div>
            <div class="col-lg-2"><label class="small text-muted">الحالة قراءة</label><select class="form-select rounded-3" name="read">
                <option value="all"<?=$readFilter==='all'?' selected':''?>>الكل</option>
                <option value="read"<?=$readFilter==='read'?' selected':''?>>مقروءة</option>
                <option value="unread"<?=$readFilter==='unread'?' selected':''?>>غير مقروءة</option>
            </select></div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-outline-primary rounded-3 flex-grow-1"><i class="fas fa-filter me-1"></i> فلترة</button>
                <a href="booking_notifications.php" class="btn btn-outline-secondary rounded-3"><i class="fas fa-redo me-1"></i></a>
            </div>
        </div>
    </form>
    <div class="card rounded-4 shadow-sm">
        <div class="table-responsive"><table class="table table-hover align-middle mb-0 text-nowrap">
            <thead class="table-light"><tr>
                <th>#</th><th>الإشعار</th><th>الحجز / المستقبل</th><th>العنوان والرسالة</th>
                <th>النوع</th><th>القناة</th><th>الحالة</th><th>تاريخ الإرسال</th><th>إجراءات</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows):?><tr><td colspan="9" class="text-center text-muted py-5">⚠️ لا توجد إشعارات مطابقة للبحث.</td></tr><?php endif ?>
            <?php foreach($rows as $i=>$r):
                $ic = ['info'=>'fa-info-circle text-info','confirmation'=>'fa-check-circle text-success','cancellation'=>'fa-times-circle text-danger','refund'=>'fa-hand-holding-usd text-success','modification'=>'fa-edit text-warning','ticket'=>'fa-ticket-alt text-primary','reminder'=>'fa-bell text-warning','warning'=>'fa-exclamation-triangle text-warning','error'=>'fa-skull-crossbones text-danger','system'=>'fa-cogs text-secondary'];
                $icons = $ic[$r['notification_type']] ?? 'fa-info-circle text-secondary';
                $sts = ['pending'=>['warning','معلق'],'sent'=>['primary','مُرسل'],'delivered'=>['info','مُستلم'],'read'=>['success','مقروء'],'failed'=>['danger','فشل'],'cancelled'=>['secondary','ألغي']];
                [$c2,$t2] = $sts[$r['status']] ?? ['secondary',$r['status']];
            ?>
            <tr class="<?=$r['is_read']==0 && $r['status']!=='pending'?'table-info':''?>">
                <td><?=$i+1+$offset?></td>
                <td>
                    <i class="fas <?=$icons?> fa-lg me-1"></i>
                    <span class="badge bg-secondary-subtle text-secondary rounded-pill px-3 py-1">#<?=(int)$r['id']?></span>
                </td>
                <td>
                    <?php if($r['booking_id']):?>
                        <a href="bus_flight_bookings_details.php?id=<?=(int)$r['booking_id']?>" target="_blank" class="fw-semibold d-block"><?=h($r['booking_number'])?></a>
                        <small class="text-muted d-block"><?=h($r['traveler_name'] ?? '')?></small>
                    <?php endif ?>
                    <?php if($r['user_id']):?><small class="d-block">👤 user_id: #<?=(int)$r['user_id']?></small><?php endif ?>
                    <?php if($r['sent_to_mobile']):?><small class="d-block">📱 <?=h($r['sent_to_mobile'])?></small><?php endif ?>
                    <?php if($r['sent_to_email']):?><small class="d-block">✉️ <?=h($r['sent_to_email'])?></small><?php endif ?>
                </td>
                <td>
                    <div class="fw-bold"><?=h(mb_substr($r['title'],0,55))?><?=mb_strlen($r['title'])>55?'...':''?></div>
                    <small class="text-muted d-block mt-1"><?=h(mb_substr(strip_tags($r['message']),0,80))?><?=mb_strlen(strip_tags($r['message']))>80?'...':''?></small>
                    <?php if($r['scheduled_at']):?><small class="badge bg-warning-subtle text-warning rounded-pill mt-1">⏰ مجدول: <?=h(date('d/m/Y H:i', strtotime($r['scheduled_at'])))?></small><?php endif ?>
                </td>
                <td><span class="badge bg-light text-dark rounded-pill"><?=h($r['notification_type'])?></span></td>
                <td><span class="badge bg-secondary-subtle text-secondary rounded-pill"><?=h($r['channel'])?></span></td>
                <td><span class="badge bg-<?=$c2?> rounded-pill px-3 py-1"><?=$t2?></span>
                    <?php if(!$r['is_read']):?><small class="text-primary ms-1">●</small><?php endif ?>
                </td>
                <td><small><?=$r['sent_at']?h(date('d/m/Y H:i', strtotime($r['sent_at']))):'<span class="text-muted">—</span>'?></small>
                    <?php if($r['created_by_name']):?><small class="d-block text-muted">بواسطة: <?=h($r['created_by_name'])?></small><?php endif ?>
                </td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <?php if($r['status']==='pending'):?><a href="booking_notifications.php?cancel=<?=(int)$r['id']?>" class="btn btn-sm btn-outline-secondary rounded-3" onclick="return confirm('هل تريد إلغاء هذا الإشعار المجدول؟')"><i class="fas fa-ban"></i></a><?php endif ?>
                        <?php if($r['is_read']==0 && $r['status']!=='pending'):?><a href="booking_notifications.php?mark_read=<?=(int)$r['id']?>" class="btn btn-sm btn-outline-primary rounded-3" title="تحديد كمقروء"><i class="fas fa-check-double"></i></a><?php endif ?>
                    </div>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php if($pages>1):?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted">الصفحة <?=$page?> من <?=$pages?> — إجمالي <?=$total?></small>
            <nav><ul class="pagination mb-0"><?php for($i=max(1,$page-3);$i<=min($pages,$page+3);$i++):?><li class="page-item <?=($i===$page)?'active':''?>"><a class="page-link" href="?<?=http_build_query(array_merge($_GET,['page'=>$i]))?>"><?=$i?></a></li><?php endfor?></ul></nav>
        </div>
        <?php endif ?>
    </div>
</div>
<!-- Modal: إنشاء إشعار يدوي -->
<div class="modal fade" id="newNotif" tabindex="-1"><div class="modal-dialog modal-xl"><form method="post" class="modal-content rounded-4">
    <?=csrf_input()?><input type="hidden" name="create_notif" value="1">
    <div class="modal-header bg-primary text-white rounded-top-4"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>إنشاء إشعار جديد</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-4">
        <div class="row g-3">
            <div class="col-md-6"><label class="small fw-bold">الحجز المرتبط (اختياري)</label><select id="notifSel" class="form-select form-select-lg rounded-3" name="booking_id"><option value="">بدون حجز</option></select></div>
            <div class="col-md-6"><label class="small fw-bold">مستخدم لوحة التحكم المستلم (اختياري)</label><?php
                $usrs = $pdo->query("SELECT id, full_name, username FROM users WHERE status='active' ORDER BY full_name")->fetchAll();
            ?><select class="form-select form-select-lg rounded-3" name="user_id"><option value="">-- لا يوجد --</option><?php foreach($usrs as $u):?><option value="<?=$u['id']?>"><?=h($u['full_name'])?> (<?=h($u['username'])?>)</option><?php endforeach ?></select></div>
            <div class="col-md-3"><label class="small fw-bold">جوال العميل</label><input class="form-control rounded-3" name="customer_mobile" placeholder="+9665XXXXXXX"></div>
            <div class="col-md-3"><label class="small fw-bold">بريد العميل</label><input class="form-control rounded-3" name="customer_email" type="email" placeholder="customer@mail.com"></div>
            <div class="col-md-3"><label class="small fw-bold">نوع الإشعار *</label><select class="form-select rounded-3" name="notification_type" required>
                <?php foreach(['info'=>'معلومات','confirmation'=>'تأكيد','cancellation'=>'إلغاء','refund'=>'استرداد','modification'=>'تعديل','ticket'=>'تذكرة','reminder'=>'تذكير','warning'=>'تحذير','error'=>'خطأ','system'=>'نظام'] as $k=>$v):?><option value="<?=$k?>"<?=$k==='info'?' selected':''?>><?=$v?></option><?php endforeach ?>
            </select></div>
            <div class="col-md-3"><label class="small fw-bold">تاريخ الإرسال المجدول</label><input type="datetime-local" class="form-control rounded-3" name="scheduled_at"><small class="text-muted">دون تحديد = فوري فور الإنشاء</small></div>
            <div class="col-md-12"><label class="small fw-bold">عنوان الإشعار *</label><input class="form-control rounded-3" name="title" required maxlength="250" placeholder="مثال: تذكير قبل موعد الرحلة بـ 24 ساعة"></div>
            <div class="col-md-12"><label class="small fw-bold">نص الرسالة *</label><textarea class="form-control rounded-3" rows="5" name="message" required placeholder="اكتب نص الرسالة هنا..."></textarea></div>
            <div class="col-md-12"><label class="small fw-bold">بيانات إضافية (JSON اختياري)</label><textarea class="form-control rounded-3" rows="2" name="extra_data_json" placeholder='{"link":"https://example.com/ticket/xxx","priority":"high"}'></textarea></div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary rounded-pill px-5 shadow-sm" type="submit"><i class="fas fa-paper-plane me-1"></i> حفظ وجدولة الإشعار</button></div>
</form></div></div>
<script>
fetch('ajax/ajax_get_active_bookings.php').then(r=>r.json()).then(d=>{const el=document.getElementById('notifSel');if(el)el.innerHTML='<option value="">بدون حجز</option>'+d.map(b=>`<option value="${b.id}">${b.booking_number} — ${b.traveler_name}</option>`).join('');}).catch(()=>{});
</script>
<?php $content = ob_get_clean(); require_once __DIR__ . '/footer.php';
