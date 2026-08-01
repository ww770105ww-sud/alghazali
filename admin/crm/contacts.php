
<?php
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/crm_functions.php';

// Check if CRM is enabled
if (!is_crm_enabled()) {
    echo "<script>alert('وحدة CRM غير مفعلة حالياً'); location.href='../index.php';</script>";
    exit;
}

// Check permission
if (!has_permission_v3('crm_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// Get contacts
try {
    $stmt = $pdo->query("
        SELECT c.*, comp.name as company_name, u.username as assigned_username
        FROM crm_contacts c
        LEFT JOIN crm_companies comp ON c.company_id = comp.id
        LEFT JOIN users u ON c.assigned_to = u.id
        WHERE c.deleted_at IS NULL
        ORDER BY c.created_at DESC
    ");
    $contacts = $stmt->fetchAll();
} catch (PDOException $e) {
    $contacts = [];
}

?>

<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">الجهات الاتصال</h1>
            <p class="text-muted small mb-0">إدارة العملاء والجهات الاتصال</p>
        </div>
        <button class="btn-apple-primary" data-bs-toggle="modal" data-bs-target="#newContactModal">
            <i class="fas fa-plus me-2"></i> إضافة جهة اتصال
        </button>
    </div>

    <div class="apple-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="apple-table">
                    <thead>
                        <tr>
                            <th>الاسم</th>
                            <th>رقم WhatsApp</th>
                            <th>البريد الإلكتروني</th>
                            <th>الشركة</th>
                            <th>المرحلة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($contacts) > 0): ?>
                            <?php foreach ($contacts as $contact): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 fw-bold"><?= h($contact['first_name'] . ' ' . $contact['last_name']) ?></h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= h($contact['whatsapp_number'] ?? $contact['phone']) ?></td>
                                    <td><?= h($contact['email'] ?? '-') ?></td>
                                    <td><?= h($contact['company_name'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $stageLabels = [
                                            'lead' => 'قيادة',
                                            'prospect' => 'عميل محتمل',
                                            'customer' => 'عميل',
                                            'lost' => 'مفقود'
                                        ];
                                        $stageColors = [
                                            'lead' => 'bg-info',
                                            'prospect' => 'bg-warning',
                                            'customer' => 'bg-success',
                                            'lost' => 'bg-danger'
                                        ];
                                        ?>
                                        <span class="apple-badge <?= $stageColors[$contact['stage']] ?? 'bg-secondary' ?>">
                                            <?= h($stageLabels[$contact['stage']] ?? $contact['stage']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="contact.php?id=<?= h($contact['id']) ?>" class="btn btn-sm btn-light"><i class="fas fa-eye"></i></a>
                                            <button class="btn btn-sm btn-light" onclick="startNewConversation(<?= h($contact['id']) ?>)"><i class="fas fa-comments"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <i class="fas fa-users fa-3x mb-3 text-muted"></i>
                                    <p class="text-muted mb-0">لا توجد جهات اتصال بعد</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- New Contact Modal -->
<div class="modal fade" id="newContactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content apple-modal">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">إضافة جهة اتصال جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="newContactForm">
                    <?= csrf_input() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">الاسم الأول</label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الاسم الأخير</label>
                            <input type="text" class="form-control" name="last_name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">رقم WhatsApp</label>
                            <input type="text" class="form-control" name="whatsapp_number" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">البريد الإلكتروني</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">العنوان</label>
                            <textarea class="form-control" name="address" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn-apple-primary" onclick="saveNewContact()">حفظ</button>
            </div>
        </div>
    </div>
</div>

<script>
function saveNewContact() {
    const form = document.getElementById('newContactForm');
    const formData = new FormData(form);
    formData.append('add_contact', '1');
    
    fetch('ajax/save_contact.php', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    }).catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ أثناء الحفظ');
    });
}

function startNewConversation(contactId) {
    fetch('ajax/start_conversation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'contact_id=' + encodeURIComponent(contactId) + '&csrf_token=<?= generate_csrf_token() ?>'
    }).then(response => response.json()).then(data => {
        if (data.success && data.conversation_id) {
            window.location.href = 'inbox.php?conversation=' + data.conversation_id;
        } else {
            alert(data.message);
        }
    }).catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ');
    });
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>

