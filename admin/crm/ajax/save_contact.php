
<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Check CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'CSRF token invalid']);
    exit;
}

// Check permissions
if (!has_permission_v3('crm_edit')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Get input
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $whatsappNumber = trim($_POST['whatsapp_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    if (empty($firstName) || empty($whatsappNumber)) {
        throw new Exception('الاسم ورقم WhatsApp مطلوبين');
    }
    
    // Insert contact
    $stmt = $pdo->prepare("
        INSERT INTO crm_contacts (first_name, last_name, whatsapp_number, phone, email, address, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $firstName,
        $lastName,
        $whatsappNumber,
        $phone,
        $email,
        $address,
        $_SESSION['admin_id']
    ]);
    
    $contactId = $pdo->lastInsertId();
    
    // Auto-create a conversation
    $stmt = $pdo->prepare("
        INSERT INTO crm_conversations (contact_id, channel, status, created_by, created_at)
        VALUES (?, 'whatsapp', 'open', ?, NOW())
    ");
    $stmt->execute([$contactId, $_SESSION['admin_id']]);
    
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'contact_id' => $contactId]);
} catch (Exception $e) {
    $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;

