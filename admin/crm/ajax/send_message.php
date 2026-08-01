
<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/WhatsAppService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $conversationId = $_POST['conversation_id'] ?? null;
    $messageText = trim($_POST['message'] ?? '');
    
    if (!$conversationId || empty($messageText)) {
        throw new Exception('Missing parameters');
    }
    
    // Get conversation & contact
    $stmt = $pdo->prepare("
        SELECT c.*, con.whatsapp_number
        FROM crm_conversations c
        JOIN crm_contacts con ON c.contact_id = con.id
        WHERE c.id = ? AND c.deleted_at IS NULL
    ");
    $stmt->execute([$conversationId]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        throw new Exception('Conversation not found');
    }
    
    // Send via WhatsApp
    $waService = new WhatsAppService($pdo);
    $result = $waService->sendTextMessage($conversation['whatsapp_number'], $messageText);
    
    if (!$result['success']) {
        $errorMsg = $result['response']['error']['message'] ?? 'Failed to send message';
        throw new Exception($errorMsg);
    }
    
    $wamId = $result['response']['messages'][0]['id'] ?? null;
    
    // Save to DB
    $stmt = $pdo->prepare("
        INSERT INTO crm_messages (conversation_id, contact_id, sender_id, sender_type, message_type, content, whatsapp_message_id, status, created_at)
        VALUES (?, ?, ?, 'agent', 'text', ?, ?, 'sent', NOW())
    ");
    $stmt->execute([
        $conversationId,
        $conversation['contact_id'],
        $_SESSION['admin_id'],
        $messageText,
        $wamId
    ]);
    
    // Update conversation
    $pdo->prepare("
        UPDATE crm_conversations 
        SET last_message_at = NOW(), updated_at = NOW() 
        WHERE id = ?
    ")->execute([$conversationId]);
    
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;

