<?php
require_once 'header.php';

$settings = getSettings($pdo);
$default_max_muatamers = $settings['umrah_default_max_muatamers'] ?? 5;

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

// معالجة إضافة مستضيف جديد
if (isset($_POST['add_host'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "خطأ في التحقق من الطلب (CSRF).";
        header("Location: umrah_hosts.php");
        exit();
    } elseif (has_permission('umrah_create')) {
        try {
            $iqama_image = upload_image('iqama_image');
            $national_address_image = upload_image('national_address_image');

            $agent_id = $_SESSION['agent_id'] ?? null;
            $branch_id = $_SESSION['branch_id'] ?? null;

            // التحقق من التكرار
            $check = $pdo->prepare("SELECT id FROM umrah_hosts WHERE host_name = ? AND phone = ? AND (agent_id = ? OR branch_id = ?)");
            $check->execute([$_POST['host_name'], $_POST['phone'], $agent_id, $branch_id]);
            if ($check->fetch()) {
                $_SESSION['error'] = 'هذا المستضيف مسجل مسبقاً بنفس الاسم ورقم الهاتف';
                header("Location: umrah_hosts.php");
                exit();
            } else {
                $stmt = $pdo->prepare('INSERT INTO umrah_hosts (host_name, phone, address, iqama_image, national_address_image, agent_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $_POST['host_name'],
                    $_POST['phone'],
                    $_POST['address'],
                    $iqama_image,
                    $national_address_image,
                    $agent_id,
                    $branch_id
                ]);
                $_SESSION['success'] = 'تم إضافة المستضيف بنجاح';
                header("Location: umrah_hosts.php");
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'حدث خطأ: ' . $e->getMessage();
            header("Location: umrah_hosts.php");
            exit();
        }
    }
}

// معالجة تحديث مستضيف
if (isset($_POST['update_host'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "خطأ في التحقق من الطلب (CSRF).";
        header("Location: umrah_hosts.php");
        exit();
    } elseif (has_permission('umrah_edit')) {
        try {
            $current_data = $pdo->prepare('SELECT iqama_image, national_address_image FROM umrah_hosts WHERE id = ?');
            $current_data->execute([$_POST['id']]);
            $images = $current_data->fetch();

            $iqama_image = upload_image('iqama_image') ?? $images['iqama_image'];
            $national_address_image = upload_image('national_address_image') ?? $images['national_address_image'];

            $stmt = $pdo->prepare('UPDATE umrah_hosts SET host_name = ?, phone = ?, address = ?, iqama_image = ?, national_address_image = ? WHERE id = ?');
            $stmt->execute([
                $_POST['host_name'],
                $_POST['phone'],
                $_POST['address'],
                $iqama_image,
                $national_address_image,
                $_POST['id']
            ]);
            $_SESSION['success'] = 'تم تحديث المستضيف بنجاح';
            header("Location: umrah_hosts.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = 'حدث خطأ: ' . $e->getMessage();
            header("Location: umrah_hosts.php");
            exit();
        }
    }
}

// معالجة حذف مستضيف
if (isset($_GET['delete'])) {
    if (has_permission('umrah_delete')) {
        try {
            $host_id = intval($_GET['delete']);
            
            // Check number of mu'tamireen
            $check_count = $pdo->prepare("SELECT COUNT(*) as cnt FROM passports WHERE host_id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL");
            $check_count->execute([$host_id]);
            $count_data = $check_count->fetch();
            
            if ($count_data['cnt'] > 0) {
                $_SESSION['error'] = 'لا يمكن حذف المستضيف لأنه يحتوي على ' . $count_data['cnt'] . ' معتمر/معتمرين.';
                header("Location: umrah_hosts.php");
                exit();
            }
            
            // First delete related images
            $stmt = $pdo->prepare('SELECT iqama_image, national_address_image FROM umrah_hosts WHERE id = ?');
            $stmt->execute([$host_id]);
            $images = $stmt->fetch();
            if ($images) {
                if ($images['iqama_image'] && file_exists('../assets/uploads/umrah/' . $images['iqama_image'])) {
                    unlink('../assets/uploads/umrah/' . $images['iqama_image']);
                }
                if ($images['national_address_image'] && file_exists('../assets/uploads/umrah/' . $images['national_address_image'])) {
                    unlink('../assets/uploads/umrah/' . $images['national_address_image']);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM umrah_hosts WHERE id = ?');
            $stmt->execute([$host_id]);
            $_SESSION['success'] = 'تم حذف المستضيف بنجاح';
            header("Location: umrah_hosts.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = 'لا يمكن حذف المستضيف لارتباطه بمعاملات أخرى.';
            header("Location: umrah_hosts.php");
            exit();
        }
    }
}

// معالجة تحويل مستضيف إلى ضامن
if (isset($_GET['convert_to_guarantor'])) {
    if (has_permission('umrah_create')) {
        try {
            $host_id = intval($_GET['convert_to_guarantor']);

            // جلب بيانات المستضيف
            $stmt = $pdo->prepare('SELECT * FROM umrah_hosts WHERE id = ?');
            $stmt->execute([$host_id]);
            $host = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$host) {
                $_SESSION['error'] = 'المستضيف غير موجود.';
                header("Location: umrah_hosts.php");
                exit();
            } else {
                // التحقق من أن هذا المستضيف لم يتم تحويله من قبل
                $check = $pdo->prepare('SELECT id FROM umrah_guarantors WHERE guarantor_name = ? AND phone = ? AND (agent_id = ? OR branch_id = ?)');
                $check->execute([$host['host_name'], $host['phone'], $host['agent_id'], $host['branch_id']]);
                if ($check->fetch()) {
                    $_SESSION['error'] = 'هذا المستضيف تم تحويله إلى ضامن من قبل.';
                    header("Location: umrah_hosts.php");
                    exit();
                } else {
                    // نسخ الصور (إذا وجدت)
                    $id_image_front = $host['iqama_image'];
                    $id_image_back = $host['national_address_image'];

                    // إدراج الضامن الجديد
                    $stmt = $pdo->prepare('INSERT INTO umrah_guarantors (guarantor_name, identity_number, identity_type, phone, address, guarantor_type, id_image_front, id_image_back, agent_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $host['host_name'],
                        '', // رقم الهوية (سيتم إضافته لاحقاً)
                        'إقامة', // نوع الهوية الافتراضي
                        $host['phone'],
                        $host['address'],
                        'individual',
                        $id_image_front,
                        $id_image_back,
                        $host['agent_id'],
                        $host['branch_id']
                    ]);

                    $_SESSION['success'] = 'تم تحويل المستضيف إلى ضامن بنجاح! يمكنك العثور عليه في <a href="umrah_guarantors.php" class="alert-link">صفحة الضامنين</a>.';
                    header("Location: umrah_hosts.php");
                    exit();
                }
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'حدث خطأ أثناء التحويل: ' . $e->getMessage();
            header("Location: umrah_hosts.php");
            exit();
        }
    }
}

// تصفية البيانات حسب الصلاحيات
$filter = get_entity_filter('h', 'branch_id', 'agent_id', 'employee_id', null);
$sql = "SELECT h.*, a.agent_name, b.branch_name,
        (SELECT COUNT(*) FROM passports WHERE host_id = h.id AND transaction_type = 'umrah' AND deleted_at IS NULL) as muatamer_count
        FROM umrah_hosts h
        LEFT JOIN agents a ON h.agent_id = a.id
        LEFT JOIN branches b ON h.branch_id = b.id
        WHERE {$filter['clause']}
        ORDER BY h.host_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($filter['params']);
$hosts = $stmt->fetchAll();

?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-house-user text-info me-2"></i> إدارة المستضيفين</h3>
        <?php if (has_permission('umrah_create')): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHostModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة مستضيف جديد
        </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">اسم المستضيف</th>
                            <th>رقم الهاتف</th>
                            <th>العنوان</th>
                            <th>الوكيل / الفرع</th>
                            <th>المرفقات</th>
                            <th>عدد المعتمرين</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hosts as $host): ?>
                        <tr>
                            <td class="px-4 fw-bold"><?php echo htmlspecialchars($host['host_name']); ?></td>
                            <td><?php echo htmlspecialchars($host['phone']); ?></td>
                            <td><?php echo htmlspecialchars($host['address']); ?></td>
                            <td>
                                <small class="text-primary fw-bold">
                                    <?php echo htmlspecialchars($host['agent_name'] ?: ($host['branch_name'] ?: 'الإدارة العامة')); ?>
                                </small>
                            </td>
                            <td>
                                <?php if ($host['iqama_image']): ?>
                                    <a href="../assets/uploads/umrah/<?php echo $host['iqama_image']; ?>" target="_blank" class="btn btn-sm btn-outline-info" title="صورة الإقامة"><i class="fas fa-id-card"></i></a>
                                <?php endif; ?>
                                <?php if ($host['national_address_image']): ?>
                                    <a href="../assets/uploads/umrah/<?php echo $host['national_address_image']; ?>" target="_blank" class="btn btn-sm btn-outline-success" title="العنوان الوطني"><i class="fas fa-map-marked-alt"></i></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $current = $host['muatamer_count'];
                                ?>
                                <span class="badge bg-primary rounded-pill">
                                    <?php echo $current; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-success view-btn"
                                        data-id="<?php echo $host['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($host['host_name']); ?>"
                                        data-phone="<?php echo htmlspecialchars($host['phone']); ?>"
                                        data-address="<?php echo htmlspecialchars($host['address']); ?>"
                                        data-iqama-img="<?php echo $host['iqama_image']; ?>"
                                        data-national-img="<?php echo $host['national_address_image']; ?>"
                                        data-owner="<?php echo htmlspecialchars($host['agent_name'] ?: ($host['branch_name'] ?: 'الإدارة العامة')); ?>"
                                        data-count="<?php echo $host['muatamer_count']; ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if (has_permission('umrah_create')): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning convert-btn" data-id="<?php echo $host['id']; ?>" title="تحويل إلى ضامن">
                                    <i class="fas fa-user-shield"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (has_permission('umrah_edit')): ?>
                                <button class="btn btn-sm btn-outline-primary edit-btn"
                                        data-id="<?php echo $host['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($host['host_name']); ?>"
                                        data-phone="<?php echo htmlspecialchars($host['phone']); ?>"
                                        data-address="<?php echo htmlspecialchars($host['address']); ?>"
                                        data-iqama-img="<?php echo $host['iqama_image']; ?>"
                                        data-national-img="<?php echo $host['national_address_image']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (has_permission('umrah_delete')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger delete-btn" data-id="<?php echo $host['id']; ?>"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($hosts)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">لا يوجد مستضيفون مضافون حالياً.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addHostModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">إضافة مستضيف جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المستضيف</label>
                        <input type="text" name="host_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <input type="text" name="address" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صورة الإقامة</label>
                        <input type="file" name="iqama_image" class="form-control" accept="image/*">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صورة العنوان الوطني</label>
                        <input type="file" name="national_address_image" class="form-control" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_host" class="btn btn-primary">حفظ المستضيف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editHostModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">تعديل بيانات المستضيف</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المستضيف</label>
                        <input type="text" name="host_name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <input type="text" name="address" id="edit_address" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صورة الإقامة</label>
                        <input type="file" name="iqama_image" class="form-control" accept="image/*">
                        <small class="text-muted d-block mt-1" id="current_iqama_info"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">صورة العنوان الوطني</label>
                        <input type="file" name="national_address_image" class="form-control" accept="image/*">
                        <small class="text-muted d-block mt-1" id="current_national_info"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_host" class="btn btn-primary">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewHostModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="view_name"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 border-end">
                        <p class="mb-2"><strong>رقم الهاتف:</strong> <span id="view_phone"></span></p>
                        <p class="mb-2"><strong>العنوان:</strong> <span id="view_address"></span></p>
                        <p class="mb-2"><strong>الوكيل / الفرع:</strong> <span id="view_owner" class="text-primary fw-bold"></span></p>
                        <p class="mb-0"><strong>المعتمرون المستضافون:</strong> <span id="view_count" class="badge bg-primary rounded-pill"></span></p>
                    </div>
                    <div class="col-md-6 text-center">
                        <div class="mb-3">
                            <label class="d-block text-muted small mb-1">صورة الإقامة</label>
                            <img id="view_iqama_img" src="" class="img-fluid rounded border shadow-sm" style="max-height: 200px;" alt="صورة الإقامة">
                        </div>
                        <div>
                            <label class="d-block text-muted small mb-1">صورة العنوان الوطني</label>
                            <img id="view_national_img" src="" class="img-fluid rounded border shadow-sm" style="max-height: 200px;" alt="صورة العنوان الوطني">
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
        document.getElementById('edit_phone').value = this.dataset.phone;
        document.getElementById('edit_address').value = this.dataset.address;

        const iqamaImg = this.dataset.iqamaImg;
        const nationalImg = this.dataset.nationalImg;

        document.getElementById('current_iqama_info').innerHTML = iqamaImg && iqamaImg !== 'null' ? `<a href="../assets/uploads/umrah/${iqamaImg}" target="_blank" class="text-info"><i class="fas fa-external-link-alt me-1"></i>عرض الصورة الحالية</a>` : '<span class="text-danger">لا توجد صورة مرفقة</span>';
        document.getElementById('current_national_info').innerHTML = nationalImg && nationalImg !== 'null' ? `<a href="../assets/uploads/umrah/${nationalImg}" target="_blank" class="text-info"><i class="fas fa-external-link-alt me-1"></i>عرض الصورة الحالية</a>` : '<span class="text-danger">لا توجد صورة مرفقة</span>';

        new bootstrap.Modal(document.getElementById('editHostModal')).show();
    });
});

document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('view_name').innerText = this.dataset.name;
        document.getElementById('view_phone').innerText = this.dataset.phone || 'غير متوفر';
        document.getElementById('view_address').innerText = this.dataset.address || 'غير متوفر';
        document.getElementById('view_owner').innerText = this.dataset.owner;
        document.getElementById('view_count').innerText = this.dataset.count;

        const iqamaImg = this.dataset.iqamaImg;
        const nationalImg = this.dataset.nationalImg;
        const basePath = '../assets/uploads/umrah/';

        const viewIqama = document.getElementById('view_iqama_img');
        const viewNational = document.getElementById('view_national_img');

        viewIqama.src = iqamaImg ? basePath + iqamaImg : 'https://via.placeholder.com/300x200.png?text=No+Image';
        viewNational.src = nationalImg ? basePath + nationalImg : 'https://via.placeholder.com/300x200.png?text=No+Image';

        new bootstrap.Modal(document.getElementById('viewHostModal')).show();
    });
});

// Handle convert and delete buttons with SweetAlert
document.querySelectorAll('.convert-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;
        const isConfirmed = await window.Swal.fire({
            title: 'تأكيد التحويل',
            text: 'هل أنت متأكد من تحويل هذا المستضيف إلى ضامن؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، تحويل',
            cancelButtonText: 'إلغاء'
        });
        if (isConfirmed.isConfirmed) {
            window.location.href = `?convert_to_guarantor=${id}`;
        }
    });
});

document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;
        const isConfirmed = await window.Swal.fire({
            title: 'تأكيد الحذف',
            text: 'هل أنت متأكد من حذف هذا المستضيف؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'إلغاء'
        });
        if (isConfirmed.isConfirmed) {
            window.location.href = `?delete=${id}`;
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
