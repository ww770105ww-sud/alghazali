
<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/WhatsAppService.php';

// Load CRM settings
function getCrmSettings($pdo) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM crm_settings");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}
$crmSettings = getCrmSettings($pdo);

// Handle verification GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verifyToken = $crmSettings['verify_token'] ?? '';
    $hubMode = $_GET['hub_mode'] ?? '';
    $hubChallenge = $_GET['hub_challenge'] ?? '';
    $hubVerifyToken = $_GET['hub_verify_token'] ?? '';
    
    if ($hubMode === 'subscribe' && $hubVerifyToken === $verifyToken) {
        echo $hubChallenge;
        exit;
    }
    
    http_response_code(403);
    exit;
}

// Handle incoming webhook POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    
    if ($payload) {
        $waService = new WhatsAppService($pdo);
        $waService->processWebhook($payload);
    }
    
    http_response_code(200);
    exit;
}

http_response_code(405);
exit;

