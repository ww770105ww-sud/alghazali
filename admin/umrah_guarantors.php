<?php
require_once 'header.php';

if (!has_permission('umrah_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// دالة لرفع الملفات
function upload_image($file_input_name, $target_dir = '../assets/uploads/umrah/') {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $file_name = time() . '_' . basename($_FILES[$file_input_name]['name']);
        $target_file = $target_dir . $file_name;
        if (move_uploaded_file($_FILES[$file_input_name]['tmp_name'], $target_file)) {
            return $file_name;
        }
    }
    return null;
}

// معالجة إضافة ضامن جديد
if (isset($_POST['add_guarantor'])) {
    if (has_permission('umrah_create')) {
        try {
            $id_image_front = upload_image('id_image_front');
            $id_image_back = upload_image('id_image_back');

            $agent_id = $_SESSION['agent_id'] ?? null;
            $branch_id = $_SESSION['branch_id'] ?? null;

            // التحقق من التكرار (عن طريق الاسم أو رقم الهوية إذا وجد)
            $check = $pdo->prepare("SELECT id FROM umrah_guarantors WHERE (guarantor_name = ? OR (identity_number = ? AND identity_number != '')) AND (agent_id = ? OR branch_id = ?)");
            $check->execute([$_POST['guarantor_name'], $_POST['identity_number'], $agent_id, $branch_id]);
            if ($check->fetch()) {
                $error = 'هذا الضامن مسجل مسبقاً بنفس الاسم أو رقم الهوية';
            } else {
                $stmt = $pdo->prepare('INSERT INTO umrah_guarantors (guarantor_name, identity_number, identity_type, phone, address, guarantor_type, id_image_front, id_image_back, agent_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $_POST['guarantor_name'], 
                    $_POST['identity_number'], 
                    $_POST['identity_type'], 
                    $_POST['phone'], 
                    $_POST['address'], 
                    $_POST['guarantor_type'], 
                    $id_image_front, 
                    $id_image_back,
                    $agent_id,
                    $branch_id
                ]);
                echo "<script>location.href='umrah_guarantors.php?success=1';</script>";
            }
        } catch (PDOException $e) {
            $error = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}

// معالجة تحديث ضامن
if (isset($_POST['update_guarantor'])) {
    if (has_permission('umrah_edit')) {
        try {
            $current_data = $pdo->prepare('SELECT id_image_front, id_image_back FROM umrah_guarantors WHERE id = ?');
            $current_data->execute([$_POST['id']]);
            $images = $current_data->fetch(PDO::FETCH_ASSOC);

            $id_image_front = upload_image('id_image_front') ?? $images['id_image_front'];
            $id_image_back = upload_image('id_image_back') ?? $images['id_image_back'];

            $stmt = $pdo->prepare('UPDATE umrah_guarantors SET guarantor_name = ?, identity_number = ?, identity_type = ?, phone = ?, address = ?, guarantor_type = ?, id_image_front = ?, id_image_back = ? WHERE id = ?');
            $stmt->execute([
                $_POST['guarantor_name'], 
                $_POST['identity_number'], 
                $_POST['identity_type'], 
                $_POST['phone'], 
                $_POST['address'], 
                $_POST['guarantor_type'], 
                $id_image_front, 
                $id_image_back, 
                $_POST['id']
            ]);
            echo "<script>location.href='umrah_guarantors.php?success=2';</script>";
        } catch (PDOException $e) {
            $error = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}

// معالجة حذف ضامن
if (isset($_GET['delete'])) {
    if (has_permission('umrah_delete')) {
        try {
            // First, delete related images if they exist
            $stmt = $pdo->prepare('SELECT id_image_front, id_image_back FROM umrah_guarantors WHERE id = ?');
            $stmt->execute([$_GET['delete']]);
            if ($images = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($images['id_image_front'] && file_exists('../assets/uploads/umrah/' . $images['id_image_front'])) {
                    unlink('../assets/uploads/umrah/' . $images['id_image_front']);
                }
                if ($images['id_image_back'] && file_exists('../assets/uploads/umrah/' . $images['id_image_back'])) {
                    unlink('../assets/uploads/umrah/' . $images['id_image_back']);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM umrah_guarantors WHERE id = ?');
            $stmt->execute([$_GET['delete']]);
            echo "<script>location.href='umrah_guarantors.php?success=3';</script>";
        } catch (PDOException $e) {
            $error = 'لا يمكن حذف الضامن لارتباطه بمعاملات أخرى.';
        }
    }
}

// تصفية البيانات حسب صلاحيات المستخدم
$filter = get_entity_filter('g', 'branch_id', 'agent_id', 'employee_id', null);
$sql = "SELECT g.*, a.agent_name, b.branch_name, COUNT(ud.id) as muatamer_count 
        FROM umrah_guarantors g 
        LEFT JOIN umrah_details ud ON g.id = ud.guarantor_id 
        LEFT JOIN agents a ON g.agent_id = a.id
        LEFT JOIN branches b ON g.branch_id = b.id
        WHERE {$filter['clause']}
        GROUP BY g.id 
        ORDER BY g.guarantor_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($filter['params']);
$guarantors = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-user-shield text-success me-2"></i> إدارة الضامنين</h3>
        <?php if (has_permission('umrah_create')): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGuarantorModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة ضامن جديد
        </button>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">تمت العملية بنجاح.</div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">اسم الضامن</th>
                            <th>الهوية</th>
                            <th>الهاتف</th>
                            <th>الوكيل / الفرع</th>
                            <th>نوع الضمان</th>
                            <th>المرفقات</th>
                            <th>عدد المعتمرين</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($guarantors as $g): ?>
                        <tr>
                            <td class="px-4 fw-bold"><?php echo htmlspecialchars($g['guarantor_name']); ?></td>
                            <td>
                                <small class="text-muted"><?php echo htmlspecialchars($g['identity_type'] ?? 'غير محدد'); ?></small><br>
                                <?php echo htmlspecialchars($g['identity_number'] ?? '-'); ?>
                            </td>
                            <td><?php echo htmlspecialchars($g['phone']); ?></td>
                            <td>
                                <small class="text-primary fw-bold">
                                    <?php echo htmlspecialchars($g['agent_name'] ?: ($g['branch_name'] ?: 'الإدارة العامة')); ?>
                                </small>
                            </td>
                            <td><?php echo ($g['guarantor_type'] == 'company') ? 'شركة' : 'فرد'; ?></td>
                            <td>
                                <?php if ($g['id_image_front']): ?>
                                    <a href="../assets/uploads/umrah/<?php echo $g['id_image_front']; ?>" target="_blank" class="btn btn-sm btn-outline-info mb-1" title="الهوية (أمام)"><i class="fas fa-id-card"></i></a>
                                <?php endif; ?>
                                <?php if ($g['id_image_back']): ?>
                                    <a href="../assets/uploads/umrah/<?php echo $g['id_image_back']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary mb-1" title="الهوية (خلف)"><i class="fas fa-id-card"></i></a>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-primary rounded-pill"><?php echo $g['muatamer_count']; ?></span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-success view-btn" 
                                        data-id="<?php echo $g['id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($g['guarantor_name']); ?>" 
                                        data-identity-number="<?php echo htmlspecialchars($g['identity_number'] ?? ''); ?>"
                                        data-identity-type="<?php echo htmlspecialchars($g['identity_type'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($g['phone']); ?>" 
                                        data-address="<?php echo htmlspecialchars($g['address']); ?>" 
                                        data-type="<?php echo ($g['guarantor_type'] == 'company') ? 'شركة' : 'فرد'; ?>"
                                        data-front="<?php echo $g['id_image_front']; ?>"
                                        data-back="<?php echo $g['id_image_back']; ?>"
                                        data-owner="<?php echo htmlspecialchars($g['agent_name'] ?: ($g['branch_name'] ?: 'الإدارة العامة')); ?>"
                                        data-count="<?php echo $g['muatamer_count']; ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if (has_permission('umrah_edit')): ?>
                                <button class="btn btn-sm btn-outline-primary edit-btn" 
                                        data-id="<?php echo $g['id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($g['guarantor_name']); ?>" 
                                        data-identity-number="<?php echo htmlspecialchars($g['identity_number'] ?? ''); ?>"
                                        data-identity-type="<?php echo htmlspecialchars($g['identity_type'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($g['phone']); ?>" 
                                        data-address="<?php echo htmlspecialchars($g['address']); ?>" 
                                        data-type="<?php echo htmlspecialchars($g['guarantor_type']); ?>"
                                        data-front="<?php echo $g['id_image_front']; ?>"
                                        data-back="<?php echo $g['id_image_back']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (has_permission('umrah_delete')): ?>
                                <a href="?delete=<?php echo $g['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد؟ سيتم حذف الضامن وصور الهوية المرتبطة به.')"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($guarantors)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">لا يوجد ضامنين مضافين حالياً.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addGuarantorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة ضامن جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الضامن</label>
                        <input type="text" name="guarantor_name" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">نوع الهوية</label>
                            <select name="identity_type" class="form-select">
                                <option value="هوية وطنية">هوية وطنية</option>
                                <option value="جواز سفر">جواز سفر</option>
                                <option value="إقامة">إقامة</option>
                                <option value="سجل تجاري">سجل تجاري</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">رقم الهوية</label>
                            <input type="text" name="identity_number" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الهاتف</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <input type="text" name="address" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع الضمان</label>
                        <select name="guarantor_type" class="form-select">
                            <option value="individual">فرد</option>
                            <option value="company">شركة / مؤسسة</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">صورة الهوية (الأمام)</label>
                            <input type="file" name="id_image_front" class="form-control" accept="image/*">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">صورة الهوية (الخلف)</label>
                            <input type="file" name="id_image_back" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_guarantor" class="btn btn-primary">حفظ الضامن</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editGuarantorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">تعديل بيانات الضامن</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الضامن</label>
                        <input type="text" name="guarantor_name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">نوع الهوية</label>
                            <select name="identity_type" id="edit_identity_type" class="form-select">
                                <option value="هوية وطنية">هوية وطنية</option>
                                <option value="جواز سفر">جواز سفر</option>
                                <option value="إقامة">إقامة</option>
                                <option value="سجل تجاري">سجل تجاري</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">رقم الهوية</label>
                            <input type="text" name="identity_number" id="edit_identity_number" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الهاتف</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <input type="text" name="address" id="edit_address" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع الضمان</label>
                        <select name="guarantor_type" id="edit_type" class="form-select">
                            <option value="individual">فرد</option>
                            <option value="company">شركة / مؤسسة</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صورة الهوية (الأمام)</label>
                        <input type="file" name="id_image_front" class="form-control" accept="image/*">
                        <small class="text-muted" id="current_front_info"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صورة الهوية (الخلف)</label>
                        <input type="file" name="id_image_back" class="form-control" accept="image/*">
                        <small class="text-muted" id="current_back_info"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_guarantor" class="btn btn-primary">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewGuarantorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="view_name"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 border-end">
                        <p class="mb-2"><strong>نوع الهوية:</strong> <span id="view_identity_type"></span></p>
                        <p class="mb-2"><strong>رقم الهوية:</strong> <span id="view_identity_number"></span></p>
                        <p class="mb-2"><strong>الهاتف:</strong> <span id="view_phone"></span></p>
                        <p class="mb-2"><strong>العنوان:</strong> <span id="view_address"></span></p>
                        <p class="mb-2"><strong>الوكيل / الفرع:</strong> <span id="view_owner" class="text-primary fw-bold"></span></p>
                        <p class="mb-2"><strong>نوع الضمان:</strong> <span id="view_type" class="badge bg-secondary"></span></p>
                        <p class="mb-0"><strong>المعتمرون المكفولون:</strong> <span id="view_count" class="badge bg-primary rounded-pill"></span></p>
                    </div>
                    <div class="col-md-6 text-center">
                        <div class="mb-3">
                            <label class="d-block text-muted small mb-1">صورة الهوية (الأمام)</label>
                            <img id="view_front_img" src="" class="img-fluid rounded border shadow-sm" style="max-height: 200px;" alt="صورة الهوية من الأمام">
                        </div>
                        <div>
                            <label class="d-block text-muted small mb-1">صورة الهوية (الخلف)</label>
                            <img id="view_back_img" src="" class="img-fluid rounded border shadow-sm" style="max-height: 200px;" alt="صورة الهوية من الخلف">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit_id').value = this.dataset.id;
        document.getElementById('edit_name').value = this.dataset.name;
        document.getElementById('edit_identity_number').value = this.dataset.identityNumber;
        document.getElementById('edit_identity_type').value = this.dataset.identityType;
        document.getElementById('edit_phone').value = this.dataset.phone;
        document.getElementById('edit_address').value = this.dataset.address;
        document.getElementById('edit_type').value = this.dataset.type;
        
        const frontImg = this.dataset.front;
        const backImg = this.dataset.back;
        
        document.getElementById('current_front_info').innerHTML = frontImg ? `<a href="../assets/uploads/umrah/${frontImg}" target="_blank" class="text-info"><i class="fas fa-external-link-alt me-1"></i>عرض الصورة الحالية</a>` : '<span class="text-danger">لا توجد صورة مرفقة</span>';
        document.getElementById('current_back_info').innerHTML = backImg ? `<a href="../assets/uploads/umrah/${backImg}" target="_blank" class="text-info"><i class="fas fa-external-link-alt me-1"></i>عرض الصورة الحالية</a>` : '<span class="text-danger">لا توجد صورة مرفقة</span>';
        
        const modal = new bootstrap.Modal(document.getElementById('editGuarantorModal'));
        modal.show();
    });
});

document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('view_name').innerText = this.dataset.name;
        document.getElementById('view_identity_number').innerText = this.dataset.identityNumber || 'غير متوفر';
        document.getElementById('view_identity_type').innerText = this.dataset.identityType || 'غير متوفر';
        document.getElementById('view_phone').innerText = this.dataset.phone || 'غير متوفر';
        document.getElementById('view_address').innerText = this.dataset.address || 'غير متوفر';
        document.getElementById('view_owner').innerText = this.dataset.owner;
        document.getElementById('view_type').innerText = this.dataset.type;
        document.getElementById('view_count').innerText = this.dataset.count;
        
        const frontImg = this.dataset.front;
        const backImg = this.dataset.back;
        const basePath = '../assets/uploads/umrah/';
        
        const viewFront = document.getElementById('view_front_img');
        const viewBack = document.getElementById('view_back_img');
        
        viewFront.src = frontImg ? basePath + frontImg : 'https://via.placeholder.com/300x200.png?text=No+Image';
        viewBack.src = backImg ? basePath + backImg : 'https://via.placeholder.com/300x200.png?text=No+Image';
        
        const modal = new bootstrap.Modal(document.getElementById('viewGuarantorModal'));
        modal.show();
    });
});
</script>

<?php require_once 'footer.php'; ?>
