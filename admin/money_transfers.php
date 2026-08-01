<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// جلب المعاملات للربط
$ef_trans = get_entity_filter('p');
$where_trans = "WHERE " . $ef_trans['clause'];
$trans_stmt = $pdo->prepare("
    (SELECT p.id, p.full_name as full_name, p.passport_number as passport_number, p.agent_id, p.branch_id, 'work_visa' as service_type
     FROM passports p $where_trans AND transaction_type IN ('work_visa', '6'))
    UNION ALL
    (SELECT p.id, p.full_name as full_name, p.passport_number as passport_number, p.agent_id, p.branch_id, 'umrah' as service_type
     FROM passports p 
     JOIN umrah_details ud ON p.id = ud.passport_id
     WHERE " . get_entity_filter('p')['clause'] . " AND p.transaction_type = 'umrah')
    UNION ALL
    (SELECT fvr.id, fvr.owner_name as full_name, fvr.document_no as passport_number, fvr.agent_id, fvr.branch_id, 'family_visit' as service_type
     FROM family_visit_requests fvr WHERE " . get_entity_filter('fvr')['clause'] . ")
    ORDER BY id DESC LIMIT 100
");
$params_trans = array_merge($ef_trans['params'], get_entity_filter('p')['params'], get_entity_filter('fvr')['params']);
$trans_stmt->execute($params_trans);
$visas = $trans_stmt->fetchAll();

$page_title = 'نظام الحوالات المالية';
$user_id = $_SESSION['admin_id'];

// جلب بيانات المستخدم
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->execute([$user_id]);
$currentUser = $stmt_user->fetch();

// معالجة الحفظ
if (isset($_POST['add_transfer'])) {
    try {
        $pdo->beginTransaction();
        
        $transfer_type = $_POST['transfer_type'];
        $party_type = $_POST['party_type'];
        $party_id = $_POST['party_id'];
        $amount = $_POST['amount'];
        $currency_id = $_POST['currency_id'];
        $transfer_no = generate_transfer_no($pdo, $transfer_type);
        $passport_id = !empty($_POST['passport_id']) ? $_POST['passport_id'] : null;
        
        // معالجة الصورة
        $transfer_image = null;
        if (isset($_FILES['transfer_image']) && $_FILES['transfer_image']['error'] == 0) {
            $ext = pathinfo($_FILES['transfer_image']['name'], PATHINFO_EXTENSION);
            $filename = 'TRANS_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['transfer_image']['tmp_name'], '../assets/uploads/' . $filename);
            $transfer_image = $filename;
        }

        $stmt = $pdo->prepare("INSERT INTO money_transfers (
            transfer_no, transfer_type, party_type, party_id, passport_id, amount, currency_id, 
            transfer_number, network_name, transfer_date, transfer_image, notes, 
            status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        
        $stmt->execute([
            $transfer_no, $transfer_type, $party_type, $party_id, $passport_id, $amount, $currency_id,
            $_POST['transfer_number'], $_POST['network_name'], $_POST['transfer_date'], 
            $transfer_image, $_POST['notes'], $user_id
        ]);
        
        $transfer_id = $pdo->lastInsertId();
        $stmt_new = $pdo->prepare("SELECT * FROM money_transfers WHERE id = ?");
        $stmt_new->execute([$transfer_id]);
        $new_transfer = $stmt_new->fetch();
        log_audit($pdo, 'create', 'money_transfers', $transfer_id, null, $new_transfer, "إضافة حوالة مالية");
        
        $pdo->commit();
        header('Location: money_transfers.php?success=1');
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// معالجة الاعتماد (للمحاسب والمدير)
if (isset($_POST['approve_transfer'])) {
    if (!has_permission('approve_transfer')) {
        header('Location: money_transfers.php?error=no_permission');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        $id = $_POST['transfer_id'];
        
        // جلب بيانات الحوالة
        $stmt = $pdo->prepare("SELECT * FROM money_transfers WHERE id = ?");
        $stmt->execute([$id]);
        $transfer = $stmt->fetch();
        
        if ($transfer['status'] !== 'pending') throw new Exception("الحوالة ليست في حالة انتظار المراجعة");

        // تحديث حالة الحوالة
        $stmt = $pdo->prepare("UPDATE money_transfers SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$user_id, $id]);
        
        // تحديث الرصيد (إذا كانت الحوالة لوكيل أو فرع)
        if ($transfer['party_type'] === 'agent' || $transfer['party_type'] === 'branch') {
            // الحوالة الواردة تزيد الرصيد (دائن)، الصادرة تنقصه (مدين)
            $op = ($transfer['transfer_type'] === 'incoming') ? 'add' : 'subtract';
            update_party_balance($pdo, $transfer['party_type'], $transfer['party_id'], $transfer['amount'], $op);
        }
        
        $stmt_after = $pdo->prepare("SELECT * FROM money_transfers WHERE id = ?");
        $stmt_after->execute([$id]);
        $transfer_after = $stmt_after->fetch();

        log_audit($pdo, 'approve', 'money_transfers', $id, $transfer, $transfer_after, "اعتماد حوالة مالية");
        
        $pdo->commit();
        header('Location: money_transfers.php?success=approved');
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// الفلترة
$entity_filter = get_entity_filter('t', 'branch_id', 'agent_id', 'employee_id', 'created_by');
$where = "WHERE " . $entity_filter['clause'];
$params = $entity_filter['params'];

// تصحيح الفلترة بناءً على party_type
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
$stmt_u = $pdo->prepare("SELECT user_type, branch_id, agent_id FROM users WHERE id = ?");
$stmt_u->execute([$user_id]);
$u_info = $stmt_u->fetch();

if ($u_info && !in_array($u_info['user_type'], ['admin', 'developer'])) {
    if ($u_info['user_type'] == 'agent') {
        $where = " WHERE t.party_type = 'agent' AND t.party_id = ?";
        $params = [intval($u_info['agent_id'])];
    } elseif ($u_info['user_type'] == 'branch') {
        $where = " WHERE t.party_type = 'branch' AND t.party_id = ?";
        $params = [intval($u_info['branch_id'])];
    }
}

$transfers = $pdo->prepare("
    SELECT t.*, c.currency_name, u.full_name as creator_name, app.full_name as approver_name,
           CASE 
             WHEN t.party_type = 'agent' THEN (SELECT agent_name FROM agents WHERE id = t.party_id)
             WHEN t.party_type = 'branch' THEN (SELECT branch_name FROM branches WHERE id = t.party_id)
             ELSE 'جهة خارجية'
           END as party_name
    FROM money_transfers t
    LEFT JOIN currencies c ON t.currency_id = c.id
    LEFT JOIN users u ON t.created_by = u.id
    LEFT JOIN users app ON t.approved_by = app.id
    $where
    ORDER BY t.created_at DESC
");
$transfers->execute($params);
$transfers = $transfers->fetchAll();

$currencies = $pdo->query("SELECT * FROM currencies")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();

require_once 'header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-money-bill-transfer me-2 text-primary"></i> إدارة الحوالات المالية</h3>
        <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addTransferModal">
            <i class="fas fa-plus me-1"></i> إنشاء حوالة جديدة
        </button>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">رقم الحوالة</th>
                            <th>النوع</th>
                            <th>الجهة</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($transfers as $t): ?>
                        <tr>
                            <td class="px-4 fw-bold text-primary"><?php echo $t['transfer_no']; ?></td>
                            <td>
                                <?php 
                                    if($t['transfer_type'] === 'incoming') echo '<span class="badge bg-success-subtle text-success">واردة</span>';
                                    elseif($t['transfer_type'] === 'outgoing') echo '<span class="badge bg-danger-subtle text-danger">صادرة</span>';
                                    else echo '<span class="badge bg-info-subtle text-info">داخلية</span>';
                                ?>
                            </td>
                            <td><?php echo $t['party_name']; ?></td>
                            <td class="fw-bold"><?php echo number_format($t['amount'], 2); ?> <small><?php echo $t['currency_name']; ?></small></td>
                            <td>
                                <?php 
                                    $status_map = [
                                        'pending' => ['bg-warning', 'قيد الانتظار'],
                                        'approved' => ['bg-success', 'معتمدة'],
                                        'rejected' => ['bg-danger', 'مرفوضة'],
                                        'posted' => ['bg-primary', 'مرحلة']
                                    ];
                                    $s = $status_map[$t['status']] ?? ['bg-secondary', $t['status']];
                                    echo '<span class="badge '.$s[0].'">'.$s[1].'</span>';
                                ?>
                            </td>
                            <td class="small text-muted"><?php echo date('Y-m-d', strtotime($t['created_at'])); ?></td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-info" title="عرض التفاصيل"><i class="fas fa-eye"></i></button>
                                    <?php if ($t['status'] === 'pending' && has_permission('approve_transfer')): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من اعتماد الحوالة؟');">
                                        <input type="hidden" name="transfer_id" value="<?php echo $t['id']; ?>">
                                        <button type="submit" name="approve_transfer" class="btn btn-sm btn-outline-success" title="اعتماد"><i class="fas fa-check"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة حوالة -->
<div class="modal fade" id="addTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إنشاء حوالة مالية جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">نوع الحوالة</label>
                            <select name="transfer_type" class="form-select rounded-3" required>
                                <option value="incoming">واردة (قبض)</option>
                                <option value="outgoing">صادرة (صرف)</option>
                                <option value="internal">داخلية</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الجهة (الدافع/المستلم)</label>
                            <div class="input-group">
                                <select name="party_type" id="party_type_select" class="form-select rounded-start-3" style="max-width: 120px;">
                                    <option value="">النوع</option>
                                    <option value="customer">عميل</option>
                                    <option value="agent">وكيل</option>
                                    <option value="branch">فرع</option>
                                    <option value="employee">موظف</option>
                                    <option value="supplier">مورد</option>
                                    <option value="bank">بنك</option>
                                    <option value="box">صندوق</option>
                                    <option value="external">خارجي</option>
                                </select>
                                <select name="party_id" id="party_id_select" class="form-select rounded-end-3" disabled>
                                    <option value="">اختر النوع أولاً</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">المبلغ</label>
                            <input type="number" step="0.01" name="amount" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" class="form-select rounded-3" required>
                                <?php foreach($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['currency_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">رقم الحوالة (من الشبكة)</label>
                            <input type="text" name="transfer_number" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اسم الشبكة / البنك</label>
                            <input type="text" name="network_name" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">تاريخ الحوالة</label>
                            <input type="date" name="transfer_date" class="form-control rounded-3" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">صورة الإشعار</label>
                            <input type="file" name="transfer_image" class="form-control rounded-3" accept="image/*">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold small">ربط بمعاملة (اختياري)</label>
                            <select name="passport_id" class="form-select rounded-3">
                                <option value="">-- اختيار معاملة (اختياري) --</option>
                                <?php foreach($visas as $v): ?>
                                    <?php 
                                        $service_label = '';
                                        if($v['service_type'] == 'work_visa') $service_label = '[تأشيرة عمل]';
                                        elseif($v['service_type'] == 'umrah') $service_label = '[عمرة]';
                                        elseif($v['service_type'] == 'family_visit') $service_label = '[زيارة عائلية]';
                                    ?>
                                    <option value="<?php echo $v['id']; ?>">
                                        <?php echo $service_label; ?> <?php echo htmlspecialchars($v['full_name']); ?> - <?php echo htmlspecialchars($v['passport_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control rounded-3" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_transfer" class="btn btn-primary rounded-pill px-5 fw-bold">حفظ وإرسال للمراجعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('party_type_select').addEventListener('change', function() {
    const type = this.value;
    const partySelect = document.getElementById('party_id_select');
    
    if (type === 'external') {
        partySelect.innerHTML = '<option value="0">جهة خارجية (غير مسجلة)</option>';
        partySelect.disabled = false;
        return;
    }

    partySelect.innerHTML = '<option value="">جاري التحميل...</option>';
    partySelect.disabled = true;

    if (!type) {
        partySelect.innerHTML = '<option value="">اختر النوع أولاً</option>';
        return;
    }

    fetch(`ajax_admin_data.php?action=get_entities_by_type&type=${type}`)
        .then(response => response.json())
        .then(data => {
            partySelect.innerHTML = '<option value="">اختر الجهة / الحساب</option>';
            data.forEach(item => {
                partySelect.innerHTML += `<option value="${item.id}">${item.name}</option>`;
            });
            partySelect.disabled = false;
        })
        .catch(error => {
            console.error('Error fetching entities:', error);
            partySelect.innerHTML = '<option value="">خطأ في التحميل</option>';
        });
});
</script>
<?php require_once 'footer.php'; ?>
