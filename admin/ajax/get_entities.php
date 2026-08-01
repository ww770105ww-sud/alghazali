<?php
require_once '../../includes/db.php';
require_once '../../includes/accounting_functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$type = $_GET['type'] ?? 'customer';

// خريطة أنواع الجهات إلى كود الحساب الأب
$type_to_parent_account_code = [
    'customer' => '11201',
    'agent' => '11203',
    'supplier' => '21101',
    'employee' => '2112%', // we can leave this as is for now
    'branch' => '1123%', // we can leave this as is for now
    'expense' => '512%', // we can leave this as is for now
    'bank' => '11102', // Bank accounts are under 11102
    'cash' => '11101' // Cash boxes are under 11101
];

$entities = [];
$error_message = null;

try {
    if (isset($type_to_parent_account_code[$type])) {
        $parent_code = $type_to_parent_account_code[$type];
        
        // Check if this is a parent account code (like 11201) or a pattern (like 1121%)
        if (strpos($parent_code, '%') === false) {
            // It's a parent account code - get its ID first
            $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
            $stmt_parent->execute([$parent_code]);
            $parent_id = $stmt_parent->fetchColumn();
            
            if ($parent_id) {
                // Now get all accounts under this parent_id
                $stmt = $pdo->prepare("
                    SELECT ua.id, ua.account_code, ua.account_name_ar as name,
                           ua.id as account_id,
                           c.id as customer_id,
                           a.id as agent_id,
                           s.id as supplier_id,
                           e.id as employee_id,
                           b.id as branch_id
                    FROM unified_accounts ua
                    LEFT JOIN customers c ON ua.id = c.account_id
                    LEFT JOIN agents a ON ua.id = a.account_id
                    LEFT JOIN suppliers s ON ua.id = s.account_id
                    LEFT JOIN employees e ON ua.id = e.account_id
                    LEFT JOIN branches b ON ua.id = b.account_id
                    WHERE ua.parent_id = ?
                      AND ua.account_status = 'active'
                      AND ua.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
                    ORDER BY ua.account_code
                ");
                $stmt->execute([$parent_id]);
                $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // It's a pattern - use LIKE
            $stmt = $pdo->prepare("
                SELECT ua.id, ua.account_code, ua.account_name_ar as name,
                       ua.id as account_id,
                       c.id as customer_id,
                       a.id as agent_id,
                       s.id as supplier_id,
                       e.id as employee_id,
                       b.id as branch_id
                FROM unified_accounts ua
                LEFT JOIN customers c ON ua.id = c.account_id
                LEFT JOIN agents a ON ua.id = a.account_id
                LEFT JOIN suppliers s ON ua.id = s.account_id
                LEFT JOIN employees e ON ua.id = e.account_id
                LEFT JOIN branches b ON ua.id = b.account_id
                WHERE ua.account_code LIKE ?
                  AND ua.account_status = 'active'
                  AND ua.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
                ORDER BY ua.account_code
            ");
            $stmt->execute([$parent_code]);
            $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // تحديد id بناءً على النوع
        foreach ($entities as &$ent) {
            switch ($type) {
                case 'customer':
                    if ($ent['customer_id']) $ent['id'] = $ent['customer_id'];
                    break;
                case 'agent':
                    if ($ent['agent_id']) $ent['id'] = $ent['agent_id'];
                    break;
                case 'supplier':
                    if ($ent['supplier_id']) $ent['id'] = $ent['supplier_id'];
                    break;
                case 'employee':
                    if ($ent['employee_id']) $ent['id'] = $ent['employee_id'];
                    break;
                case 'branch':
                    if ($ent['branch_id']) $ent['id'] = $ent['branch_id'];
                    break;
            }
        }
        unset($ent);
    }
} catch (PDOException $e) {
    error_log('Error in get_entities.php for type ' . $type . ': ' . $e->getMessage());
    $entities = [];
    $error_message = 'Database error: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode(['entities' => $entities, 'error' => $error_message]);
?>