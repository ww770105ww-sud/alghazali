
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

// Get conversations
try {
    $stmt = $pdo->query("
        SELECT c.*, con.first_name, con.last_name, con.whatsapp_number, con.email
        FROM crm_conversations c
        LEFT JOIN crm_contacts con ON c.contact_id = con.id
        WHERE c.deleted_at IS NULL
        ORDER BY c.last_message_at DESC, c.created_at DESC
    ");
    $conversations = $stmt->fetchAll();
} catch (PDOException $e) {
    $conversations = [];
}

// Get selected conversation
$selectedConversation = null;
$messages = [];
if (isset($_GET['conversation']) && !empty($_GET['conversation'])) {
    $convId = $_GET['conversation'];
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, con.first_name, con.last_name, con.whatsapp_number, con.email, con.phone
            FROM crm_conversations c
            LEFT JOIN crm_contacts con ON c.contact_id = con.id
            WHERE c.id = ? AND c.deleted_at IS NULL
        ");
        $stmt->execute([$convId]);
        $selectedConversation = $stmt->fetch();

        // Get messages
        if ($selectedConversation) {
            $stmt = $pdo->prepare("
                SELECT m.*, u.username as agent_username
                FROM crm_messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ? AND m.deleted_at IS NULL
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$convId]);
            $messages = $stmt->fetchAll();

            // Mark as read
            $pdo->prepare("UPDATE crm_conversations SET unread_count = 0 WHERE id = ?")->execute([$convId]);
        }
    } catch (PDOException $e) {
        $selectedConversation = null;
    }
}

?>

<style>
    .chat-wrapper {
        display: flex;
        height: calc(100vh - 150px);
        background: var(--apple-card-bg);
        border-radius: var(--apple-radius);
        overflow: hidden;
    }
    
    .chat-sidebar {
        width: 350px;
        border-left: 1px solid rgba(0,0,0,0.05);
        overflow-y: auto;
    }
    
    .chat-main {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" opacity="0.05"><rect fill="%23000" width="100" height="100"/></svg>');
    }
    
    .chat-header {
        padding: 1rem;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .chat-messages {
        flex-grow: 1;
        padding: 1rem;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .chat-input-area {
        padding: 1rem;
        border-top: 1px solid rgba(0,0,0,0.05);
    }
    
    .message {
        max-width: 70%;
        padding: 0.75rem 1rem;
        border-radius: 1rem;
        margin-bottom: 0.25rem;
    }
    
    .message-sent {
        background: var(--apple-blue);
        color: white;
        align-self: flex-end;
        border-bottom-right-radius: 0.25rem;
    }
    
    .message-received {
        background: white;
        color: #333;
        align-self: flex-start;
        border-bottom-left-radius: 0.25rem;
    }
    
    .conversation-item {
        padding: 1rem;
        cursor: pointer;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: background 0.2s;
    }
    
    .conversation-item:hover,
    .conversation-item.active {
        background: rgba(0,0,0,0.02);
    }
</style>

<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">صندوق الوارد</h1>
            <p class="text-muted small mb-0">إدارة المحادثات والرسائل</p>
        </div>
        <a href="index.php" class="btn btn-light">
            <i class="fas fa-arrow-left me-2"></i> العودة
        </a>
    </div>

    <div class="chat-wrapper">
        <!-- Conversations Sidebar -->
        <div class="chat-sidebar">
            <div class="p-3 border-bottom">
                <div class="input-group">
                    <input type="text" class="form-control" placeholder="البحث في المحادثات...">
                    <button class="btn btn-light" type="button"><i class="fas fa-search"></i></button>
                </div>
            </div>

            <?php if (count($conversations) > 0): ?>
                <?php foreach ($conversations as $conv): ?>
                    <div class="conversation-item <?= (isset($_GET['conversation']) && $_GET['conversation'] == $conv['id']) ? 'active' : '' ?>" onclick="window.location.href='inbox.php?conversation=<?= h($conv['id']) ?>'">
                        <div class="d-flex gap-3">
                            <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:50px;height:50px;">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold"><?= h($conv['first_name'] . ' ' . $conv['last_name']) ?></h6>
                                    <small class="text-muted"><?= h($conv['last_message_at'] ? date('H:i', strtotime($conv['last_message_at'])) : '') ?></small>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <small class="text-muted text-truncate" style="max-width: 200px;">
                                        <?php if ($conv['unread_count'] > 0): ?>
                                            <span class="fw-bold text-dark">رسائل جديدة</span>
                                        <?php else: ?>
                                            ...
                                        <?php endif; ?>
                                    </small>
                                    <?php if ($conv['unread_count'] > 0): ?>
                                        <span class="badge bg-danger rounded-pill"><?= h($conv['unread_count']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>لا توجد محادثات بعد</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Main Chat Area -->
        <div class="chat-main">
            <?php if ($selectedConversation): ?>
                <div class="chat-header">
                    <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:45px;height:45px;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-0 fw-bold"><?= h($selectedConversation['first_name'] . ' ' . $selectedConversation['last_name']) ?></h6>
                        <small class="text-muted"><?= h($selectedConversation['whatsapp_number'] ?? $selectedConversation['phone']) ?></small>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-light"><i class="fas fa-phone"></i></button>
                        <button class="btn btn-light"><i class="fas fa-video"></i></button>
                        <button class="btn btn-light" onclick="window.location.href='contact.php?id=<?= h($selectedConversation['contact_id']) ?>'">
                            <i class="fas fa-user"></i>
                        </button>
                    </div>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($messages as $msg): ?>
                        <div class="message <?= $msg['sender_type'] === 'customer' ? 'message-received' : 'message-sent' ?>">
                            <div class="mb-1">
                                <?php if ($msg['message_type'] === 'text'): ?>
                                    <?= nl2br(h($msg['content'])) ?>
                                <?php elseif ($msg['message_type'] === 'image'): ?>
                                    <img src="<?= h($msg['media_url']) ?>" class="img-fluid rounded" style="max-width: 300px;">
                                <?php else: ?>
                                    <i class="fas fa-file me-2"></i> <?= h($msg['media_name']) ?>
                                <?php endif; ?>
                            </div>
                            <small class="opacity-75" style="font-size: 11px;">
                                <?= h(date('H:i', strtotime($msg['created_at']))) ?>
                                <?php if ($msg['sender_type'] !== 'customer'): ?>
                                    <i class="fas fa-check ms-1"></i>
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="chat-input-area">
                    <form id="sendMessageForm" onsubmit="sendMessage(event)">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light"><i class="fas fa-paperclip"></i></button>
                            <input type="text" name="message" class="form-control" placeholder="اكتب رسالة..." id="messageInput">
                            <input type="hidden" name="conversation_id" value="<?= h($selectedConversation['id']) ?>">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <button type="submit" class="btn-apple-primary"><i class="fas fa-paper-plane"></i></button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
                    <i class="fas fa-comments fa-5x mb-4 opacity-25"></i>
                    <h4 class="mb-2">اختر محادثة</h4>
                    <p>اختر محادثة من القائمة على اليسار أو ابدأ محادثة جديدة</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function sendMessage(e) {
    e.preventDefault();
    const form = document.getElementById('sendMessageForm');
    const formData = new FormData(form);
    formData.append('send_message', '1');
    
    const btn = form.querySelector('button[type="submit"]');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    fetch('ajax/send_message.php', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            form.reset();
            location.reload();
        } else {
            alert(data.message);
        }
    }).catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ أثناء الإرسال');
    }).finally(() => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    });
}

// Auto scroll to bottom
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>

