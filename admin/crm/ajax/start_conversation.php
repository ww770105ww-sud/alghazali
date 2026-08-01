
<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

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
    $contactId = $_POST['contact_id'] ?? null;
    if (!$contactId) {
        throw new Exception('Contact ID is required');
    }
    
    // Check if there's an existing open conversation
    $stmt = $pdo->prepare("SELECT id FROM crm_conversations WHERE contact_id = ? AND status = 'open' AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$contactId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'conversation_id' => $existing['id']]);
        exit;
    }
    
    // Create new conversation
    $stmt = $pdo->prepare("
        INSERT INTO crm_conversations (contact_id, channel, status, created_by, created_at)
        VALUES (?, 'whatsapp', 'open', ?, NOW())
    ");
    $stmt->execute([$contactId, $_SESSION['admin_id']]);
    
    $conversationId = $pdo->lastInsertId();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'conversation_id' => $conversationId]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;

