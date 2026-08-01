<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // No HTML errors!
/**
 * ajax_exchange_helper.php - مساعدة AJAX لمعالجة تحويل العملات (تحويل العملة)
 */
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/security.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    security_json_error('Unauthorized', 401);
}

$action = $_GET['action'] ?? '';
rate_limit('ajax_exchange_helper:' . $action, 60, 60);
require_csrf_for_actions(['update_exchange', 'delete_exchange']);

/**
 * الحصول على رصيد الحساب من جدول account_balances_unified
 */
function get_account_balance_unified($pdo, $account_id)
{
    $stmt = $pdo->prepare("
        SELECT SUM(current_balance) as balance
        FROM account_balances_unified
        WHERE account_id = ?
    ");
    $stmt->execute([$account_id]);
    return (float)($stmt->fetchColumn() ?: 0);
}

function get_account_currency_balance($pdo, $account_id, $currency_id)
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(current_balance), 0) AS current_balance
        FROM account_balances_unified
        WHERE account_id = ? AND currency_id = ?
    ");
    $stmt->execute([$account_id, $currency_id]);
    return (float)($stmt->fetchColumn() ?: 0);
}

function assert_sufficient_account_balance($pdo, $account_id, $currency_id, $amount)
{
    $balance = get_account_currency_balance($pdo, $account_id, $currency_id);
    if ($balance + 0.000001 < $amount) {
        throw new Exception('رصيد الحساب غير كافي لإتمام هذه العملية. الرصيد الحالي: ' . number_format($balance, 2));
    }
}

function create_exchange_accounting_entries($pdo, array $data, $exchange_id, $transaction_number)
{
    $date = $data['date'];
    $from_account_id = (int)$data['from_account_id'];
    $from_currency_id = (int)$data['from_currency_id'];
    $from_amount = (float)$data['from_amount'];
    $to_account_id = (int)$data['to_account_id'];
    $to_currency_id = (int)$data['to_currency_id'];
    $to_amount = (float)$data['to_amount'];
    $exchange_rate = (float)$data['exchange_rate'];
    $user_id = $_SESSION['admin_id'];
    $branch_id = $_SESSION['branch_id'] ?? null;

    assert_sufficient_account_balance($pdo, $from_account_id, $from_currency_id, $from_amount);

    $description = "تحويل العملة: $transaction_number | من $from_amount إلى $to_amount";

    php_create_financial_entry(
        $pdo,
        $date,
        'exchange',
        null,
        null,
        $to_account_id,
        null,
        $to_amount,
        $to_currency_id,
        $description,
        $user_id,
        $branch_id,
        null,
        null,
        'exchange',
        $exchange_id,
        true
    );

    php_create_financial_entry(
        $pdo,
        $date,
        'exchange',
        null,
        null,
        null,
        $from_account_id,
        $from_amount,
        $from_currency_id,
        $description,
        $user_id,
        $branch_id,
        null,
        null,
        'exchange',
        $exchange_id,
        true
    );

    $stmt_rates = $pdo->prepare("SELECT id, exchange_rate FROM currencies WHERE id IN (?, ?)");
    $stmt_rates->execute([$from_currency_id, $to_currency_id]);
    $ref_rates = $stmt_rates->fetchAll(PDO::FETCH_UNIQUE);
    $from_ref_rate = (float)($ref_rates[$from_currency_id]['exchange_rate'] ?? 1);
    $to_ref_rate = (float)($ref_rates[$to_currency_id]['exchange_rate'] ?? 1);

    $from_value_base = $from_amount * $from_ref_rate;
    $actual_to_amount = $to_amount > 0 ? $to_amount : ($from_amount * $exchange_rate);
    $to_value_base = $actual_to_amount * $to_ref_rate;
    $diff_base = round($to_value_base - $from_value_base, 2);

    if (abs($diff_base) <= 0.01) {
        return;
    }

    $base_currency_id = (int)($pdo->query("SELECT id FROM currencies WHERE is_default = 1 LIMIT 1")->fetchColumn() ?: $to_currency_id);
    $profit_account_id = (int)($pdo->query("SELECT id FROM unified_accounts WHERE account_code = '402' LIMIT 1")->fetchColumn() ?: 17);
    $loss_account_id = (int)($pdo->query("SELECT id FROM unified_accounts WHERE account_code = '502' LIMIT 1")->fetchColumn() ?: 20);

    if ($diff_base > 0) {
        php_create_financial_entry($pdo, $date, 'exchange_diff', null, null, null, $profit_account_id, abs($diff_base), $base_currency_id, "ربح فروقات العملة - عملية $transaction_number", $user_id, $branch_id, null, null, 'exchange', $exchange_id, true);
    } else {
        php_create_financial_entry($pdo, $date, 'exchange_diff', null, null, $loss_account_id, null, abs($diff_base), $base_currency_id, "خسارة فروقات العملة - عملية $transaction_number", $user_id, $branch_id, null, null, 'exchange', $exchange_id, true);
    }
}

if ($action === 'get_account_balance_by_currency') {
    $account_id = (int)($_GET['account_id'] ?? 0);
    $currency_id = (int)($_GET['currency_id'] ?? 0);

    if (!$account_id || !$currency_id) {
        header('Content-Type: application/json');
        echo json_encode(['balance' => 0, 'formatted' => '0.00']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT ab.current_balance, c.currency_name, c.currency_symbol, ua.normal_balance
            FROM account_balances_unified ab
            JOIN currencies c ON ab.currency_id = c.id
            JOIN unified_accounts ua ON ab.account_id = ua.id
            WHERE ab.account_id = ? AND ab.currency_id = ?
        ");
        $stmt->execute([$account_id, $currency_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $balance = (float)$row['current_balance'];
            // تنسيق عرض الرصيد مع اعتبار الجانب الطبيعي (مدين / دائن)
            $html = format_account_balance($balance, $row['normal_balance'], $row['currency_name']);
            $formatted = strip_tags($html);

            header('Content-Type: application/json');
            echo json_encode([
                'balance' => $balance,
                'formatted' => $formatted,
                'currency_name' => $row['currency_name'],
                'currency_symbol' => $row['currency_symbol']
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['balance' => 0, 'formatted' => '0.00']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['error' => 'حدث خطأ داخلي في النظام']);
    }
    exit;
}

if ($action === 'get_accounts_by_type') {
    $type = $_GET['type'] ?? '';
    $accounts = [];

    try {
        // جلب الحسابات من unified_accounts
        // جلب فقط الحسابات "الأوراق" (leaf) التي لا تحتوي على أي حساب تابع
        $base_query = "
            SELECT id, account_name_ar as name, account_code
            FROM unified_accounts
            WHERE account_status = 'active'

        ";

        switch ($type) {
            case 'box': // الصناديق
                $items = $pdo->query("$base_query AND account_code LIKE '11101%' AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code")->fetchAll();
                break;
            case 'bank': // البنوك
                $items = $pdo->query("$base_query AND account_code LIKE '11102%' AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code")->fetchAll();
                break;
            case 'customer': // العملاء
                $items = $pdo->query("$base_query AND account_code LIKE '11201%' AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code")->fetchAll();
                break;
            case 'supplier': // الموردين
                $items = $pdo->query("$base_query AND account_code LIKE '21101%' AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code")->fetchAll();
                break;
            case 'agent': // الوكلاء
                $items = $pdo->query("$base_query AND account_code LIKE '11203%' AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code")->fetchAll();
                break;
            case 'branch': // الفروع
                $items = $pdo->query("$base_query AND account_code LIKE '11202%' AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code")->fetchAll();
                break;
            case 'general': // عام
                $items = $pdo->query("$base_query AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code")->fetchAll();
                break;
            default:
                $items = [];
        }

        foreach ($items as $item) {
            $accounts[] = [
                'id' => $item['id'],
                'code' => $item['account_code'],
                'name' => $item['name']
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($accounts);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode([]); // Return empty if error
    }
    exit;
}

if ($action === 'get_exchange_rate') {
    $from_currency = (int)($_GET['from'] ?? 0);
    $to_currency = (int)($_GET['to'] ?? 0);
    $type = $_GET['type'] ?? 'sell';

    try {
        // جلب سعر الصرف بين العملات
        $rate = get_exchange_rate($from_currency, $to_currency, $type);
        header('Content-Type: application/json');
        echo json_encode(['rate' => (float)$rate]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['rate' => 1.0]); // Default to 1 if error
    }
    exit;
}

if ($action === 'get_exchange') {
    $id = (int)($_GET['id'] ?? 0);

    if (!$id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'معرّف العملية مطلوب']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM currency_exchange_transactions WHERE id = ?");
        $stmt->execute([$id]);
        $exchange = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exchange) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'exchange' => $exchange]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'العملية غير موجودة']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
    }
    exit;
}

if ($action === 'update_exchange') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة']);
        exit;
    }

    $id = (int)$input['id'];
    $date = $input['date'];
    $from_account_type = $input['from_account_type'];
    $from_account_id = (int)$input['from_account_id'];
    $from_currency_id = (int)$input['from_currency_id'];
    $from_amount = (float)$input['from_amount'];
    $to_account_type = $input['to_account_type'];
    $to_account_id = (int)$input['to_account_id'];
    $to_currency_id = (int)$input['to_currency_id'];
    $to_amount = (float)$input['to_amount'];
    $exchange_rate = (float)$input['exchange_rate'];
    $notes = $input['notes'] ?? '';

    // التحقق من صلاحيات المستخدم
    $user_role = $_SESSION['role'] ?? 'employee';
    $is_admin = ($user_role === 'admin' || $user_role === 'developer');
    if (!$is_admin && !in_array($user_role, ['accountant', 'branch_manager'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لتعديل العملية']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt_exchange = $pdo->prepare("SELECT transaction_number FROM currency_exchange_transactions WHERE id = ?");
        $stmt_exchange->execute([$id]);
        $existing_exchange = $stmt_exchange->fetch(PDO::FETCH_ASSOC);
        if (!$existing_exchange) {
            throw new Exception('العملية غير موجودة.');
        }

        // 1. حذف القيود السابقة؛ الـ triggers ستعكس الأرصدة تلقائياً.
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id IN (SELECT id FROM financial_transactions WHERE reference_type = 'exchange' AND reference_id = ?)")->execute([$id]);
        $pdo->prepare("DELETE FROM financial_transactions WHERE reference_type = 'exchange' AND reference_id = ?")->execute([$id]);

        // 2. تحديث بيانات عملية الصرف
        $stmt = $pdo->prepare("
            UPDATE currency_exchange_transactions SET
                transaction_date = ?,
                from_account_id = ?, from_account_type = ?, from_currency_id = ?, from_amount = ?,
                to_account_id = ?, to_account_type = ?, to_currency_id = ?, to_amount = ?,
                exchange_rate = ?, notes = ?, created_by = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $date, $from_account_id, $from_account_type, $from_currency_id, $from_amount,
            $to_account_id, $to_account_type, $to_currency_id, $to_amount,
            $exchange_rate, $notes, $_SESSION['admin_id'], $id
        ]);

        create_exchange_accounting_entries($pdo, [
            'date' => $date,
            'from_account_id' => $from_account_id,
            'from_currency_id' => $from_currency_id,
            'from_amount' => $from_amount,
            'to_account_id' => $to_account_id,
            'to_currency_id' => $to_currency_id,
            'to_amount' => $to_amount,
            'exchange_rate' => $exchange_rate,
        ], $id, $existing_exchange['transaction_number']);

        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'تم تحديث العملية بنجاح']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header('Content-Type: application/json');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
    }
    exit;
}

if ($action === 'delete_exchange') {
    $id = (int)($_GET['id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // 1. حذف جميع القيود المحاسبية؛ الـ triggers ستعكس الأرصدة تلقائياً.
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id IN (SELECT id FROM financial_transactions WHERE reference_type = 'exchange' AND reference_id = ?)")->execute([$id]);

        // 2. حذف المعاملات المالية
        $pdo->prepare("DELETE FROM financial_transactions WHERE reference_type = 'exchange' AND reference_id = ?")->execute([$id]);

        // 3. حذف عملية الصرف نفسها
        $pdo->prepare("DELETE FROM currency_exchange_transactions WHERE id = ?")->execute([$id]);

        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'تم حذف العملية والقيود المحاسبية المرتبطة بها بنجاح']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header('Content-Type: application/json');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
    }
    exit;
}
