<?php
require_once __DIR__ . '/../includes/db.php';
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

if (!isset($_GET['type'])) {
    echo json_encode(['success' => false, 'message' => 'Type parameter is required']);
    exit();
}

$type = $_GET['type'];

if (!function_exists('get_accounts_under_parent')) {
    function get_accounts_under_parent($pdo, $parent_account_code, $entityType = null)
    {
        $stmt = $pdo->prepare("
            SELECT ua.id, ua.account_code, ua.account_name_ar,
                   (SELECT id FROM customers WHERE account_id = ua.id LIMIT 1) as customer_id,
                   (SELECT id FROM agents WHERE account_id = ua.id LIMIT 1) as agent_id
            FROM unified_accounts ua
            WHERE ua.account_code LIKE ?
              AND ua.account_status IN ('active', 'dormant')
              AND ua.id NOT IN (
                  SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL
              )
            ORDER BY ua.account_code ASC
        ");
        $stmt->execute([$parent_account_code . '%']);
        $accounts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($entityType === 'agent' && empty($row['agent_id'])) {
                continue;
            }
            $row['display_name'] = ($row['account_code'] ?? '') . ' - ' . ($row['account_name_ar'] ?? '');
            $row['name'] = $row['account_name_ar'] ?? '';
            $accounts[] = $row;
        }
        return $accounts;
    }
}

$entities = [];
switch ($type) {
    case 'draft':
        echo json_encode(['success' => true, 'entities' => []]);
        exit;
    case 'cash':
        $entities = get_accounts_under_parent($pdo, '11101');
        break;
    case 'credit':
        $entities = get_accounts_under_parent($pdo, '11201', 'customer');
        break;
    case 'bank_transfer':
        $entities = get_accounts_under_parent($pdo, '11102');
        break;
    case 'agent':
        $entities = get_accounts_under_parent($pdo, '11203', 'agent');
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
        exit;
}

echo json_encode(['success' => true, 'entities' => $entities]);
