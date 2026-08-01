<?php
require_once __DIR__ . '/../includes/db.php';
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

if (!isset($_GET['service_id'])) {
    echo json_encode(['success' => false, 'message' => 'service_id required']);
    exit();
}

$serviceId = (int)$_GET['service_id'];

$stmt = $pdo->prepare("
    SELECT 
        revenue_account_id, 
        cost_account_id, 
        profit_account_id 
    FROM services 
    WHERE id = ?
");
$stmt->execute([$serviceId]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if ($service) {
    // Fetch account details
    function getAccountDetails($pdo, $accountId)
    {
        if (empty($accountId)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account) {
            return [
                'id' => $account['id'],
                'name' => $account['account_code'] . ' - ' . $account['account_name_ar']
            ];
        }
        return null;
    }

    $revenueAccount = getAccountDetails($pdo, $service['revenue_account_id']);
    $costAccount = getAccountDetails($pdo, $service['cost_account_id']);
    $profitAccount = getAccountDetails($pdo, $service['profit_account_id']);

    echo json_encode([
        'success' => true,
        'revenue_account_id' => $revenueAccount['id'] ?? null,
        'revenue_account_name' => $revenueAccount['name'] ?? 'لم يتم إعداد الحساب',
        'cost_account_id' => $costAccount['id'] ?? null,
        'cost_account_name' => $costAccount['name'] ?? 'لم يتم إعداد الحساب',
        'profit_account_id' => $profitAccount['id'] ?? null,
        'profit_account_name' => $profitAccount['name'] ?? 'لم يتم إعداد الحساب'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Service not found']);
}
?>