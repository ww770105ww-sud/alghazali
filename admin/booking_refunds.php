<?php
ob_start();
define('SYSTEM_ACCESS', true);
require_once __DIR__ . '/header.php';
if (!has_permission('bookings_view')) { echo "<script>alert('غير مصرح'); location.href='index.php';</script>"; exit; }
$user_id   = (int)($_SESSION['admin_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'employee';
$is_admin  = in_array($user_role, ['admin','developer'], true);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$error = null; $success = null;

// ─── (أ) تقديم إلغاء + استرداد ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_cancel'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error='خطأ CSRF'; }
    elseif (!has_permission('booking_refund_process') && !$is_admin) { $error='ليس لديك صلاحية'; }
    else {
        try {
            $stmt = $pdo->prepare("CALL sp_cancel_booking(?,?,?,?,?,?,?,?,?,?,?,?,@r_id,@f_id)");
            $stmt->execute([
                (int)$_POST['booking_id'], $user_id, trim($_POST['cancel_reason'] ?? ''),
                (int)($_POST['cancellation_policy_id'] ?? 0) ?: null,
                (float)($_POST['penalty_percent'] ?? 0),
                (float)($_POST['penalty_amount']  ?? 0),
                (float)($_POST['refund_amount']   ?? 0),
                trim($_POST['refund_method'] ?? 'cash'),
                !empty($_POST['refund_reference']) ? trim($_POST['refund_reference']) : null,
                !empty($_POST['refund_notes']) ? trim($_POST['refund_notes']) : null,
                (isset($_POST['is_instant']) && $_POST['is_instant']==='1') ? 1 : 0,
                (int)($_POST['account_id_debit']  ?? 0) ?: null,
                (int)($_POST['account_id_credit'] ?? 0) ?: null,
            ]);
            $stmt->closeCursor();
            $res = $pdo->query("SELECT @r_id AS r, @f_id AS f")->fetch();
            $rid = (int)($res['r'] ?? 0); $fid = (int)($res['f'] ?? 0);
            $success = $rid > 0 ? "✅ تم الإلغاء — رقم طلب الاسترداد <b>#{$rid}</b>" : '⚠️ تم الإلغاء ولكن لم يتم إنشاء طلب استرداد.';
            if ($fid > 0) $success .= " — المعاملة المالية #{$fid}";
        } catch (Throwable $e) { $error = 'خطأ: '.$e->getMessage(); }
    }
}

// ─── (ب) معالجة طلب استرداد (اكتمل / فشل) ─────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['process_refund'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error='خطأ CSRF'; }
    elseif (!has_permission('booking_refund_process') && !$is_admin) { $error='ليس لديك صلاحية'; }
    else {
        try {
            $rid = (int)$_POST['refund_id']; $sts = $_POST['new_status'];
            $notes = trim($_POST['process_notes'] ?? ''); $ref = trim($_POST['new_refund_reference'] ?? '');
            $rowStmt = $pdo->prepare("SELECT status, booking_id FROM booking_refunds WHERE id=?");
            $rowStmt->execute([$rid]); $row = $rowStmt->fetch();
            if (!$row) throw new Exception('طلب الاسترداد غير موجود');
            if (!in_array($sts,['completed','failed'],true)) throw new Exception('حالة غير صالحة');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE booking_refunds SET status=?, processed_at=NOW(), refund_reference=COALESCE(NULLIF(?,''),refund_reference), processing_notes=?, processed_by=? WHERE id=?")
                ->execute([$sts,$ref,$notes,$user_id,$rid]);
            if ($sts==='completed') $pdo->prepare("UPDATE bus_flight_bookings SET refund_status='completed', refund_processed_at=NOW() WHERE id=?")->execute([$row['booking_id']]);
            else $pdo->prepare("UPDATE bus_flight_bookings SET refund_status='failed' WHERE id=?")->execute([$row['booking_id']]);
            log_audit($pdo,'process_refund','booking_refunds',$rid,['status'=>$row['status']],['status'=>$sts],'معالجة طلب استرداد');
            $pdo->commit();
            $success = "✅ تم تحديث طلب الاسترداد #{$rid} بنجاح.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'خطأ: '.$e->getMessage();
        }
    }
}

// ─── (ج) فلترة + عرض الطلبات ──────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$methodFilter = $_GET['method'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 25; $offset = ($page-1)*$perPage;
$where=['1=1']; $params=[];
if ($statusFilter!=='all') { $where[]="br.status=?"; $params[]=$statusFilter; }
if ($methodFilter!=='all') { $where[]="br.refund_method=?"; $params[]=$methodFilter; }
if ($branch_id>0 && !$is_admin) { $where[]="bfb.branch_id=?"; $params[]=$branch_id; }
if ($search!=='') {
    $where[]="(br.id=? OR bfb.booking_number LIKE ? OR bfb.traveler_name LIKE ? OR br.refund_reference LIKE ? OR br.cancel_reason LIKE ?)";
    $params[]=ctype_digit($search)?(int)$search:0;
    $like="%$search%"; $params=array_merge($params,[$like,$like,$like,$like]);
}
$whereSQL = implode(' AND ', $where);
$c = $pdo->prepare("SELECT COUNT(*) FROM booking_refunds br JOIN bus_flight_bookings bfb ON bfb.id=br.booking_id WHERE $whereSQL");
$c->execute($params); $total=(int)$c->fetchColumn();
$rows = $pdo->prepare("
    SELECT br.*, bfb.booking_number, bfb.traveler_name, bfb.service_type, bfb.total_amount,
           u.full_name AS req_name, p.full_name AS proc_name
      FROM booking_refunds br
      JOIN bus_flight_bookings bfb ON bfb.id=br.booking_id
      LEFT JOIN users u ON u.id=br.requested_by
      LEFT JOIN users p ON p.id=br.processed_by
     WHERE $whereSQL ORDER BY br.id DESC LIMIT $perPage OFFSET $offset
");
$rows->execute($params); $rows=$rows->fetchAll(); $pages=ceil($total/$perPage);
$stats = $pdo->query("SELECT
    SUM(status='pending') AS p_pend, SUM(status='completed') AS p_done, SUM(status='failed') AS p_fail,
    SUM(IF(status IN('pending','instant'),refund_amount,0)) AS pend_amt,
    SUM(IF(status='completed',refund_amount,0)) AS done_amt, SUM(penalty_amount) AS total_pen
    FROM booking_refunds")->fetch();
?>
<div class="container-fluid mt-4" dir="rtl">
    <?php if ($error): ?><div class="alert alert-danger rounded-4 shadow-sm mb-3"><?= $error ?></div><?php endif ?>
    <?php if ($success): ?><div class="alert alert-success rounded-4 shadow-sm mb-3"><?= $success ?></div><?php endif ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">قيد المعالجة</div><div class="fs-3 fw-bold text-warning"><?=(int)($stats['p_pend']??0)?></div></div><i class="fas fa-hourglass-half text-warning fs-2"></i></div><div class="text-muted small mt-2">قيد: <b class="text-warning"><?=number_format((float)($stats['pend_amt']??0),2)?></b></div></div></div>
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">مكتمل</div><div class="fs-3 fw-bold text-success"><?=(int)($stats['p_done']??0)?></div></div><i class="fas fa-check-circle text-success fs-2"></i></div><div class="text-muted small mt-2">مسترد: <b class="text-success"><?=number_format((float)($stats['done_amt']??0),2)?></b></div></div></div>
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">فاشل</div><div class="fs-3 fw-bold text-danger"><?=(int)($stats['p_fail']??0)?></div></div><i class="fas fa-exclamation-circle text-danger fs-2"></i></div></div></div>
        <div class="col-md-3"><div class="card rounded-4 p-3 shadow-sm"><div class="d-flex justify-content-between"><div><div class="text-muted small">إجمالي الغرامات</div><div class="fs-3 fw-bold text-info"><?=number_format((float)($stats['total_pen']??0),2)?></div></div><i class="fas fa-coins text-info fs-2"></i></div></div></div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h3 class="fw-bold mb-0"><i class="fas fa-hand-holding-usd text-success me-2"></i>طلبات الاسترداد المالي <span class="badge bg-success-subtle text-success rounded-pill ms-2"><?=number_format($total)?></span></h3>
        <button class="btn btn-success rounded-pill shadow-sm px-4 text-white" data-bs-toggle="modal" data-bs-target="#newCancel"><i class="fas fa-ban me-1"></i> إلغاء حجز + استرداد</button>
    </div>
    <form class="card rounded-4 p-3 shadow-sm mb-4" method="get">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4"><label class="small text-muted">بحث عام</label><input class="form-control rounded-3" name="q" value="<?=h($search)?>" placeholder="رقم الطلب / الحجز / المسافر / سبب الإلغاء"></div>
            <div class="col-lg-2"><label class="small text-muted">الحالة</label><select class="form-select rounded-3" name="status">
                <option value="all"<?=$statusFilter==='all'?' selected':''?>>الكل</option>
                <option value="pending"<?=$statusFilter==='pending'?' selected':''?>>قيد المعالجة</option>
                <option value="instant"<?=$statusFilter==='instant'?' selected':''?>>فوري</option>
                <option value="completed"<?=$statusFilter==='completed'?' selected':''?>>مكتمل</option>
                <option value="failed"<?=$statusFilter==='failed'?' selected':''?>>فاشل</option>
                <option value="void"<?=$statusFilter==='void'?' selected':''?>>ملغي</option>
            </select></div>
            <div class="col-lg-2"><label class="small text-muted">الطريقة</label><select class="form-select rounded-3" name="method">
                <option value="all"<?=$methodFilter==='all'?' selected':''?>>الكل</option>
                <?php foreach(['cash'=>'نقدي','bank'=>'بنكي','card'=>'بطاقة','wallet'=>'محفظة','credit_note'=>'سند دائن','other'=>'أخرى'] as $k=>$v):?><option value="<?=$k?>"<?=$methodFilter===$k?' selected':''?>><?=$v?></option><?php endforeach ?>
            </select></div>
            <div class="col-lg-4 d-flex gap-2">
                <button class="btn btn-outline-success rounded-3 flex-grow-1"><i class="fas fa-filter me-1"></i> فلترة</button>
                <a href="booking_refunds.php" class="btn btn-outline-secondary rounded-3"><i class="fas fa-redo me-1"></i> مسح</a>
            </div>
        </div>
    </form>
    <div class="card rounded-4 shadow-sm">
        <div class="table-responsive"><table class="table table-hover align-middle mb-0 text-nowrap">
            <thead class="table-light"><tr>
                <th>#</th><th>الطلب</th><th>الحجز</th><th>المسافر</th>
                <th>سبب الإلغاء</th><th>الغرامة</th><th>المسترد</th><th>الطريقة</th><th>الحالة</th><th>التاريخ</th><th>إجراءات</th>
            </tr></thead>
            <tbody>
            <?php if(!$rows):?><tr><td colspan="11" class="text-center text-muted py-5">⚠️ لا توجد طلبات مطابقة للبحث.</td></tr><?php endif ?>
            <?php foreach($rows as $i=>$r):
                $meths=['cash'=>'💵 نقدي','bank'=>'🏦 بنكي','card'=>'💳 بطاقة','wallet'=>'📱 محفظة','credit_note'=>'📄 سند دائن','other'=>'⚙️ أخرى'];
                $sm=['pending'=>['warning','قيد المعالجة'],'instant'=>['primary','فوري'],'completed'=>['success','مكتمل'],'failed'=>['danger','فاشل'],'void'=>['secondary','ملغي']];
                [$cls,$txt]=$sm[$r['status']]??['secondary',$r['status']];
            ?>
            <tr>
                <td><?=$i+1+$offset?></td>
                <td><span class="badge bg-info-subtle text-info rounded-pill fs-6 px-3">#<?=(int)$r['id']?></span></td>
                <td><a href="bus_flight_bookings_details.php?id=<?=(int)$r['booking_id']?>" target="_blank" class="fw-semibold"><?=h($r['booking_number'])?></a></td>
                <td><div class="fw-bold"><?=h($r['traveler_name'])?></div><small class="text-muted"><?=$r['service_type']==='flight'?'✈️ طيران':'🚌 باص'?></small></td>
                <td><small><?=h(mb_substr($r['cancel_reason']??'',0,50))?><?=mb_strlen($r['cancel_reason']??'')>50?'...':''?></small></td>
                <td class="text-danger"><?=number_format((float)$r['penalty_amount'],2)?><?=$r['penalty_percent']>0?' <small class="text-muted">('.(float)$r['penalty_percent'].'%)</small>':''?></td>
                <td class="fw-bold text-success"><?=number_format((float)$r['refund_amount'],2)?></td>
                <td><span class="badge bg-light text-dark rounded-pill"><?=$meths[$r['refund_method']]??h($r['refund_method'])?></span></td>
                <td><span class="badge bg-<?=$cls?> rounded-pill px-3 py-1"><?=$txt?></span></td>
                <td><small><?=h(date('Y-m-d',strtotime($r['requested_at'])))?></small></td>
                <td>
                    <?php if(in_array($r['status'],['pending','instant'],true) && (has_permission('booking_refund_process')||$is_admin)):?>
                    <button class="btn btn-sm btn-outline-success rounded-3" data-bs-toggle="modal" data-bs-target="#proc_<?=(int)$r['id']?>"><i class="fas fa-cog me-1"></i> معالجة</button>
                    <div class="modal fade" id="proc_<?=(int)$r['id']?>" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content rounded-4">
                        <?=csrf_input()?><input type="hidden" name="refund_id" value="<?=(int)$r['id']?>">
                        <div class="modal-header bg-success text-white rounded-top-4"><h5 class="modal-title">معالجة طلب #<?=(int)$r['id']?></h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body p-4">
                            <div class="mb-2">المسافر: <b><?=h($r['traveler_name'])?></b> — المسترد: <b class="text-success fs-5"><?=number_format((float)$r['refund_amount'],2)?></b></div>
                            <label class="small fw-bold">الحالة</label>
                            <select class="form-select rounded-3 mb-3" name="new_status" required>
                                <option value="completed">✅ مكتمل</option><option value="failed">❌ فاشل</option>
                            </select>
                            <label class="small fw-bold">مرجع العملية</label><input class="form-control rounded-3 mb-3" name="new_refund_reference">
                            <label class="small fw-bold">ملاحظات</label><textarea class="form-control rounded-3" rows="3" name="process_notes"></textarea>
                        </div>
                        <div class="modal-footer"><button class="btn btn-light rounded-pill" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-success rounded-pill px-4" name="process_refund">حفظ</button></div>
                    </form></div></div>
                    <?php else:?><span class="text-muted small">—</span><?php endif ?>
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
<!-- Modal: إلغاء + استرداد جديد -->
<div class="modal fade" id="newCancel" tabindex="-1"><div class="modal-dialog modal-xl"><form method="post" class="modal-content rounded-4">
    <?=csrf_input()?><input type="hidden" name="submit_cancel" value="1">
    <div class="modal-header bg-success-subtle rounded-top-4"><h5 class="modal-title fw-bold text-success-emphasis"><i class="fas fa-ban me-2"></i>إلغاء حجز + استرداد مالي</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-4">
        <div class="alert alert-danger small rounded-3">⚠️ سيتم تغيير حالة الحجز إلى ملغي وإنشاء طلب استرداد فور تأكيدك.</div>
        <div class="row g-3">
            <div class="col-md-12"><label class="small fw-bold">اختر الحجز *</label><select id="refSel" class="form-select form-select-lg rounded-3" name="booking_id" required><option value="">-- جاري التحميل... --</option></select></div>
            <div class="col-md-12"><label class="small fw-bold">سبب الإلغاء *</label><textarea class="form-control rounded-3" rows="2" name="cancel_reason" required></textarea></div>
            <div class="col-md-4"><label class="small fw-bold">نسبة الغرامة %</label><input id="r_pct" type="number" step="0.01" class="form-control rounded-3" name="penalty_percent" value="0"></div>
            <div class="col-md-4"><label class="small fw-bold">قيمة الغرامة</label><input id="r_amt" type="number" step="0.01" class="form-control rounded-3" name="penalty_amount" value="0"></div>
            <div class="col-md-4"><label class="small fw-bold">المبلغ المسترد *</label><input id="r_ref" type="number" step="0.01" class="form-control rounded-3 fw-bold text-success" name="refund_amount" value="0" required></div>
            <div class="col-md-3"><label class="small fw-bold">طريقة الاسترداد *</label><select class="form-select rounded-3" name="refund_method" required>
                <option value="cash">💵 نقدي</option><option value="bank">🏦 بنكي</option><option value="card">💳 بطاقة</option><option value="wallet">📱 محفظة</option><option value="credit_note">📄 سند دائن</option><option value="other">⚙️ أخرى</option>
            </select></div>
            <div class="col-md-3"><label class="small fw-bold">الاسترداد؟</label><select class="form-select rounded-3" name="is_instant">
                <option value="1">✅ فوري (معاملة مالية)</option><option value="0">⏳ مؤجل (طلب قيد)</option>
            </select></div>
            <div class="col-md-3"><label class="small fw-bold">مرجع العملية</label><input class="form-control rounded-3" name="refund_reference"></div>
            <div class="col-md-3"><label class="small fw-bold">ملاحظات</label><input class="form-control rounded-3" name="refund_notes"></div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-danger text-white rounded-pill px-5 shadow-sm" onclick="return confirm('هل أنت متأكد من إلغاء هذا الحجز؟')" type="submit"><i class="fas fa-ban me-1"></i> تأكيد الإلغاء والاسترداد</button></div>
</form></div></div>
<script>
fetch('ajax/ajax_get_active_bookings.php').then(r=>r.json()).then(d=>{const el=document.getElementById('refSel');if(el)el.innerHTML='<option value="">اختر الحجز...</option>'+d.map(b=>`<option value="${b.id}">${b.booking_number} — ${b.traveler_name} (${(b.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2})})</option>`).join('');}).catch(()=>{});
</script>
<?php $content = ob_get_clean(); require_once __DIR__.'/footer.php';
