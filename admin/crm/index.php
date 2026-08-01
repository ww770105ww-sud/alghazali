
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

// Get CRM settings
function getCrmSettings($pdo) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM crm_settings");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}
$crmSettings = getCrmSettings($pdo);

// Get statistics
$stats = [];
try {
    // Total conversations
    $stats['total_conversations'] = $pdo->query("SELECT COUNT(*) FROM crm_conversations WHERE deleted_at IS NULL")->fetchColumn();
    // Total contacts
    $stats['total_contacts'] = $pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE deleted_at IS NULL")->fetchColumn();
    // Total messages
    $stats['total_messages'] = $pdo->query("SELECT COUNT(*) FROM crm_messages WHERE deleted_at IS NULL")->fetchColumn();
    // Unread messages
    $stats['unread_messages'] = $pdo->query("SELECT SUM(unread_count) FROM crm_conversations WHERE deleted_at IS NULL")->fetchColumn();
    // Total campaigns
    $stats['total_campaigns'] = $pdo->query("SELECT COUNT(*) FROM crm_campaigns WHERE deleted_at IS NULL")->fetchColumn();
    // Total deals
    $stats['total_deals'] = $pdo->query("SELECT COUNT(*) FROM crm_deals WHERE deleted_at IS NULL")->fetchColumn();
} catch (PDOException $e) {
    $stats = [];
}

// Get recent conversations
$recentConversations = [];
try {
    $stmt = $pdo->query("
        SELECT c.*, con.first_name, con.last_name, con.whatsapp_number
        FROM crm_conversations c
        LEFT JOIN crm_contacts con ON c.contact_id = con.id
        WHERE c.deleted_at IS NULL
        ORDER BY c.last_message_at DESC
        LIMIT 10
    ");
    $recentConversations = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentConversations = [];
}

// Get recent contacts
$recentContacts = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM crm_contacts
        WHERE deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $recentContacts = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentContacts = [];
}

?>

<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">لوحة التحكم CRM</h1>
            <p class="text-muted small mb-0">مرحباً بك في نظام إدارة العملاء</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn-apple-primary" data-bs-toggle="modal" data-bs-target="#newContactModal">
                <i class="fas fa-plus me-2"></i> جهة اتصال جديدة
            </button>
            <button class="btn btn-light" onclick="window.location.href='inbox.php'">
                <i class="fas fa-inbox me-2"></i> صندوق الوارد
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="apple-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted small mb-1">المحادثات</p>
                        <h3 class="fw-bold mb-0"><?= h($stats['total_conversations'] ?? 0) ?></h3>
                    </div>
                    <div class="icon-box bg-primary rounded-circle p-3">
                        <i class="fas fa-comments text-white fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="apple-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted small mb-1">العملاء</p>
                        <h3 class="fw-bold mb-0"><?= h($stats['total_contacts'] ?? 0) ?></h3>
                    </div>
                    <div class="icon-box bg-success rounded-circle p-3">
                        <i class="fas fa-users text-white fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="apple-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted small mb-1">الرسائل</p>
                        <h3 class="fw-bold mb-0"><?= h($stats['total_messages'] ?? 0) ?></h3>
                    </div>
                    <div class="icon-box bg-info rounded-circle p-3">
                        <i class="fas fa-envelope text-white fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="apple-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted small mb-1">غير مقروء</p>
                        <h3 class="fw-bold mb-0 text-danger"><?= h($stats['unread_messages'] ?? 0) ?></h3>
                    </div>
                    <div class="icon-box bg-danger rounded-circle p-3">
                        <i class="fas fa-bell text-white fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Recent Conversations -->
        <div class="col-xl-8">
            <div class="apple-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">آخر المحادثات</h5>
                    <a href="inbox.php" class="text-primary small text-decoration-none">عرض الكل</a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (count($recentConversations) > 0): ?>
                            <?php foreach ($recentConversations as $conv): ?>
                                <a href="inbox.php?conversation=<?= h($conv['id']) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 p-3">
                                    <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0 fw-bold"><?= h($conv['first_name'] . ' ' . $conv['last_name']) ?></h6>
                                            <small class="text-muted"><?= h($conv['last_message_at'] ? date('H:i', strtotime($conv['last_message_at'])) : '') ?></small>
                                        </div>
                                        <small class="text-muted"><?= h($conv['status'] === 'open' ? 'مفتوحة' : 'مغلقة') ?></small>
                                    </div>
                                    <?php if ($conv['unread_count'] > 0): ?>
                                        <span class="badge bg-danger rounded-pill"><?= h($conv['unread_count']) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>لا توجد محادثات بعد</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Contacts -->
        <div class="col-xl-4">
            <div class="apple-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">آخر العملاء</h5>
                    <a href="contacts.php" class="text-primary small text-decoration-none">عرض الكل</a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (count($recentContacts) > 0): ?>
                            <?php foreach ($recentContacts as $contact): ?>
                                <a href="contact.php?id=<?= h($contact['id']) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 p-3">
                                    <div class="avatar bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-0 fw-bold"><?= h($contact['first_name'] . ' ' . $contact['last_name']) ?></h6>
                                        <small class="text-muted"><?= h($contact['whatsapp_number'] ?? $contact['phone']) ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-users fa-3x mb-3"></i>
                                <p>لا توجد جهات اتصال بعد</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="apple-card mt-4">
                <div class="card-header">
                    <h5 class="fw-bold mb-0">روابط سريعة</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="inbox.php" class="btn btn-light text-start"><i class="fas fa-inbox me-2 text-primary"></i> صندوق الوارد</a>
                        <a href="contacts.php" class="btn btn-light text-start"><i class="fas fa-users me-2 text-success"></i> الجهات الاتصال</a>
                        <a href="deals.php" class="btn btn-light text-start"><i class="fas fa-handshake me-2 text-warning"></i> الصفقات</a>
                        <a href="campaigns.php" class="btn btn-light text-start"><i class="fas fa-bullhorn me-2 text-info"></i> الحملات</a>
                        <a href="settings.php" class="btn btn-light text-start"><i class="fas fa-cog me-2 text-secondary"></i> الإعدادات</a>
                    </div>
                </div>
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
                    <div class="mb-3">
                        <label class="form-label">الاسم الأول</label>
                        <input type="text" class="form-control" name="first_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الاسم الأخير</label>
                        <input type="text" class="form-control" name="last_name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم WhatsApp</label>
                        <input type="text" class="form-control" name="whatsapp_number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" class="form-control" name="email">
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
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>

