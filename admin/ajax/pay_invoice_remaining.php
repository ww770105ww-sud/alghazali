<?php
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

function has_permission_v3_ajax($permission_code)
{
    global $pdo;
    $user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
    $user_role_id = (int)($_SESSION['role_id'] ?? 0);
    if ($user_role === 'developer' || $user_role_id === 2) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions_unified rp JOIN unified_permissions p ON rp.permission_id = p.id WHERE rp.role_id = ? AND p.permission_code = ?");
    $stmt->execute([$user_role_id, $permission_code]);
    return $stmt->fetchColumn() > 0;
}

if (!has_permission_v3_ajax('voucher_create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإنشاء سندات السداد.']);
    exit;
}

$invoice_id = $_POST['invoice_id'] ?? 0;
$pay_amount = (float)($_POST['pay_amount'] ?? 0);
$payment_currency_id = $_POST['payment_currency_id'] ?? 0;
$invoice_currency_id = $_POST['invoice_currency_id'] ?? 0;
$exchange_rate = (float)($_POST['exchange_rate'] ?? 1);
$financial_account_id = $_POST['financial_account_id'] ?? 0;
$party_type = $_POST['party_type'] ?? '';
$party_id = $_POST['party_id'] ?? 0;
$invoice_category = $_POST['invoice_category'] ?? '';
$payment_desc = $_POST['payment_desc'] ?? '';
$admin_id = $_SESSION['admin_id'];
$branch_id = $_SESSION['branch_id'] ?? 1;

if (!$invoice_id || $pay_amount <= 0 || !$financial_account_id || !$party_type || !$party_id) {
    echo json_encode(['success' => false, 'message' => 'جميع الحقول مطلوبة']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. حساب مبلغ التوزيع (بعملة الفاتورة)
    $allocated_amount = ($payment_currency_id == $invoice_currency_id) ? $pay_amount : ($pay_amount / $exchange_rate);

    // التحقق من عدم وجود سند مسودة لنفس الفاتورة بنفس المبلغ في نفس الوقت
    $stmt_check = $pdo->prepare("
        SELECT COUNT(*) 
        FROM payment_allocations pa
        JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
        WHERE pa.invoice_id = ? 
        AND pa.allocated_amount = ? 
        AND ft.status = 'draft' 
        AND ft.created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt_check->execute([$invoice_id, $allocated_amount]);
    if ($stmt_check->fetchColumn() > 0) {
        throw new Exception("يوجد سند مسودة بنفس المبلغ لهذه الفاتورة تم إنشاؤه خلال الدقيقة الأخيرة. يرجى التحقق من سندات القبض المسودة.");
    }

    // 2. تحديد نوع الطرف وحسابه المالي (1121 للعملاء)
    $stmt_inv = $pdo->prepare("SELECT customer_id, agent_id, account_id, delivery_type, invoice_category FROM invoices WHERE id = ?");
    $stmt_inv->execute([$invoice_id]);
    $inv_data = $stmt_inv->fetch();

    $party_account_id = null;
    $final_party_type = $party_type;
    $final_party_id = $party_id;

    // إذا كانت الفاتورة مرتبطة بعميل أو وكيل (آجل)، نستخدم حسابه (الذي يبدأ بـ 1121 عادة)
    if ($inv_data['customer_id']) {
        $final_party_type = 'customer';
        $final_party_id = $inv_data['customer_id'];
        $stmt_p = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
        $stmt_p->execute([$final_party_id]);
        $party_account_id = $stmt_p->fetchColumn();
    } elseif ($inv_data['agent_id']) {
        $final_party_type = 'agent';
        $final_party_id = $inv_data['agent_id'];
        $stmt_p = $pdo->prepare("SELECT account_id FROM agents WHERE id = ?");
        $stmt_p->execute([$final_party_id]);
        $party_account_id = $stmt_p->fetchColumn();
    } elseif ($inv_data['invoice_category'] == 'sales' && ($inv_data['delivery_type'] == 'cash' || $inv_data['delivery_type'] == 'bank_transfer' || $inv_data['delivery_type'] == 'draft')) {
        // للفواتير النقدية التي بها متبقي، نستخدم حساب العملاء العام (1121)
        $stmt_coa = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code LIKE '1121%' ORDER BY account_code ASC LIMIT 1");
        $stmt_coa->execute();
        $party_account_id = $stmt_coa->fetchColumn();
        
        $final_party_type = 'account';
        $final_party_id = $party_account_id;
    } else {
        // إذا كانت نقدية وغير محددة، نستخدم الحساب العام للفاتورة (الصندوق أو البنك)
        $party_account_id = $inv_data['account_id'];
        $final_party_type = 'account';
        $final_party_id = $party_account_id;
    }

    if (!$party_account_id) {
        throw new Exception("فشل في تحديد حساب الطرف المدين/الدائن (العميل/المورد)");
    }

    // 3. إنشاء السند (قبض للمبيعات، صرف للمشتريات)
    $voucher_id = 0;
    
    // جلب سعر صرف العملة المختارة
    $stmt_curr = $pdo->prepare("SELECT exchange_rate_buy FROM currencies WHERE id = ?");
    $stmt_curr->execute([$payment_currency_id]);
    $sys_exchange_rate = $stmt_curr->fetchColumn() ?: 1.0;

    if ($invoice_category == 'sales') {
        $stmt = $pdo->prepare("CALL sp_create_receipt_voucher(?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL, @v_id, @v_num)");
        $stmt->execute([$branch_id, $final_party_type, $final_party_id, $pay_amount, $payment_currency_id, $sys_exchange_rate, $financial_account_id, $party_account_id, $payment_desc, $admin_id]);
        $stmt->closeCursor();
        
        $res = $pdo->query("SELECT @v_id as id, @v_num as num")->fetch();
        $voucher_id = $res['id'];
        $voucher_number = $res['num'];

        // 4. إدراج التوزيع (حتى لو كان السند مسودة، نربطه بالفاتورة)
        $stmt_alloc = $pdo->prepare("INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)");
        $stmt_alloc->execute([$voucher_id, $invoice_id, $allocated_amount]);

        // ملاحظة: لن نقوم بتحديث الفاتورة (amount_received) أو ترحيل السند هنا
        // سيتم التحديث عند قيام المستخدم بترحيل السند يدوياً من السجل

    } else {
        $stmt = $pdo->prepare("CALL sp_create_payment_voucher(?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL, @v_id, @v_num)");
        $stmt->execute([$branch_id, $final_party_type, $final_party_id, $pay_amount, $payment_currency_id, $sys_exchange_rate, $financial_account_id, $party_account_id, $payment_desc, $admin_id]);
        $stmt->closeCursor();
        
        $res = $pdo->query("SELECT @v_id as id, @v_num as num")->fetch();
        $voucher_id = $res['id'];

        // 4. إدراج التوزيع
        $stmt_alloc = $pdo->prepare("INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)");
        $stmt_alloc->execute([$voucher_id, $invoice_id, $allocated_amount]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'voucher_number' => $voucher_number]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
