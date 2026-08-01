
<?php
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/crm_functions.php';

// Check if CRM is enabled
if (!is_crm_enabled()) {
    echo "<script>alert('وحدة CRM غير مفعلة حالياً'); location.href='../index.php';</script>";
    exit;
}

if (!has_permission_v3('crm_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit;
}

$contactId = $_GET['id'] ?? null;
if (!$contactId) {
    header('Location: contacts.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM crm_contacts WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch();
} catch (PDOException $e) {
    $contact = null;
}

if (!$contact) {
    header('Location: contacts.php');
    exit;
}

// Get conversations
try {
    $stmt = $pdo->prepare("SELECT * FROM crm_conversations WHERE contact_id = ? AND deleted_at IS NULL ORDER BY created_at DESC");
    $stmt->execute([$contactId]);
    $conversations = $stmt->fetchAll();
} catch (PDOException $e) {
    $conversations = [];
}

?>

<div class="apple-container">
    <div class="apple-header">
        <div class="d-flex align-items-center gap-3">
            <a href="contacts.php" class="btn btn-light"><i class="fas fa-arrow-left"></i></a>
            <div>
                <h1 class="h3 fw-bold mb-1"><?= h($contact['first_name'] . ' ' . $contact['last_name']) ?></h1>
                <p class="text-muted small mb-0">إدارة بيانات العميل</p>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn-apple-primary" onclick="window.location.href='inbox.php?conversation_id=<?= $contactId ?>'">
                <i class="fas fa-comments me-2"></i> إرسال رسالة
            </button>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="apple-card">
                <div class="card-header">
                    <h5 class="fw-bold mb-0">تفاصيل العميل</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width:120px;height:120px;font-size:3rem;">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                    <h5 class="text-center mb-4"><?= h($contact['first_name'] . ' ' . $contact['last_name']) ?></h5>
                    <hr>
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">رقم WhatsApp</small>
                        <strong><?= h($contact['whatsapp_number'] ?? '-') ?></strong>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">رقم الهاتف</small>
                        <strong><?= h($contact['phone'] ?? '-') ?></strong>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">البريد الإلكتروني</small>
                        <strong><?= h($contact['email'] ?? '-') ?></strong>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">العنوان</small>
                        <strong><?= h($contact['address'] ?? '-') ?></strong>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">المرحلة</small>
                        <?php
                        $stageLabels = ['lead' => 'قيادة', 'prospect' => 'عميل محتمل', 'customer' => 'عميل', 'lost' => 'مفقود'];
                        $stageColors = ['lead' => 'bg-info', 'prospect' => 'bg-warning', 'customer' => 'bg-success', 'lost' => 'bg-danger'];
                        ?>
                        <span class="apple-badge <?= $stageColors[$contact['stage']] ?>">
                            <?= h($stageLabels[$contact['stage']]) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="apple-card mb-4">
                <div class="card-header">
                    <h5 class="fw-bold mb-0">المحادثات</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (count($conversations) > 0): ?>
                            <?php foreach ($conversations as $conv): ?>
                                <a href="inbox.php?conversation=<?= h($conv['id']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-3">
                                    <div>
                                        <h6 class="mb-1">محادثة #<?= h($conv['id']) ?></h6>
                                        <small class="text-muted">
                                            <?= h($conv['created_at']) ?>
                                        </small>
                                    </div>
                                    <?php if ($conv['unread_count'] > 0): ?>
                                        <span class="badge bg-danger rounded-pill"><?= h($conv['unread_count']) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">لا توجد محادثات</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>

