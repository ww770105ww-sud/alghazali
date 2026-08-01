<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/ServiceFinancialEngine.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'غير مصرح']);
    exit();
}

function get_request_csrf_token(): string
{
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== '') return (string)$token;
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($hdr !== '') return (string)$hdr;
    $hdr2 = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return (string)$hdr2;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = trim((string)($method === 'POST' ? ($_POST['action'] ?? ($_REQUEST['action'] ?? '')) : ($_GET['action'] ?? ($_REQUEST['action'] ?? ''))));
$requestId = (int)($_REQUEST['id'] ?? 0);

// اجعل طلب التفاصيل أكثر مرونة حتى لا يسقط إلى "طلب غير صالح" عند غياب action من المتصفح/الكاش.
if ($method === 'GET' && $action === '' && $requestId > 0) {
    $action = 'view_details';
}

if ($method === 'GET') {
    if ($action === 'view_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'طلب غير صالح';
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT
                p.*,
                st.status_name,
                st.status_color,
                br.branch_name,
                ag.agent_name,
                sup.supplier_name,
                inv.id AS sales_invoice_id,
                inv.invoice_number AS sales_invoice_number,
                inv.total_amount AS sales_amount,
                inv.discount AS sales_discount,
                inv.invoice_status AS sales_status,
                (
                    IFNULL((
                        SELECT SUM(jl.debit)
                        FROM journal_lines jl
                        JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                        WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                        AND jl.account_id IN (
                            SELECT id FROM unified_accounts
                            WHERE account_type IN ('box', 'bank')
                        )
                    ), 0) +
                    IFNULL((
                        SELECT SUM(pa.allocated_amount)
                        FROM payment_allocations pa
                        JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                        WHERE pa.invoice_id = inv.id AND ft.status = 'posted'
                        AND ft.id NOT IN (
                            SELECT id FROM financial_transactions
                            WHERE reference_id = inv.id AND reference_type = 'invoice'
                        )
                    ), 0)
                ) AS sales_received,
                pur.id AS purchase_invoice_id,
                pur.invoice_number AS purchase_invoice_number,
                pur.total_amount AS purchase_amount,
                pur.discount AS purchase_discount,
                pur.invoice_status AS purchase_status,
                (
                    IFNULL((
                        SELECT SUM(jl_p.credit)
                        FROM journal_lines jl_p
                        JOIN financial_transactions ft_ip ON jl_p.financial_transaction_id = ft_ip.id
                        WHERE ft_ip.reference_id = pur.id AND ft_ip.reference_type = 'invoice' AND ft_ip.status = 'posted'
                        AND jl_p.account_id IN (
                            SELECT id FROM unified_accounts
                            WHERE account_type IN ('box', 'bank')
                        )
                    ), 0) +
                    IFNULL((
                        SELECT SUM(pa_p.allocated_amount)
                        FROM payment_allocations pa_p
                        JOIN financial_transactions ft_p ON pa_p.financial_transaction_id = ft_p.id
                        WHERE pa_p.invoice_id = pur.id AND ft_p.status = 'posted'
                        AND ft_p.id NOT IN (
                            SELECT id FROM financial_transactions
                            WHERE reference_id = pur.id AND reference_type = 'invoice'
                        )
                    ), 0)
                ) AS purchase_paid
            FROM passports p
            LEFT JOIN statuses st ON p.status_id = st.id
            LEFT JOIN branches br ON p.branch_id = br.id
            LEFT JOIN agents ag ON p.agent_id = ag.id
            LEFT JOIN suppliers sup ON p.supplier_id = sup.id
            LEFT JOIN invoices inv ON inv.id = p.sales_invoice_id
            LEFT JOIN invoices pur ON pur.id = p.purchase_invoice_id
            WHERE p.id = ? AND p.transaction_type = 'hajj' AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'المعاملة غير موجودة';
            exit();
        }

        header('Content-Type: text/html; charset=utf-8');

        $fullName = trim((string)($p['full_name'] ?? ''));
        $passportNumber = trim((string)($p['passport_number'] ?? ''));
        $visaNumber = trim((string)($p['visa_number'] ?? ''));
        $statusName = trim((string)($p['status_name'] ?? '---'));
        $statusColor = trim((string)($p['status_color'] ?? '#6c757d'));
        $salesNet = (float)($p['sales_amount'] ?? 0) - (float)($p['sales_discount'] ?? 0);
        $salesReceived = (float)($p['sales_received'] ?? 0);
        $salesRemaining = max(0, $salesNet - $salesReceived);
        $purchaseNet = (float)($p['purchase_amount'] ?? 0) - (float)($p['purchase_discount'] ?? 0);
        $purchasePaid = (float)($p['purchase_paid'] ?? 0);
        $purchaseRemaining = max(0, $purchaseNet - $purchasePaid);

        echo '<div class="p-3">';
        echo '<ul class="nav nav-tabs px-3 pt-3" role="tablist">';
        echo '  <li class="nav-item" role="presentation"><button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">البيانات</button></li>';
        echo '  <li class="nav-item" role="presentation"><button class="nav-link" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial" type="button" role="tab">المالية</button></li>';
        echo '</ul>';

        echo '<div class="tab-content p-3">';

        echo '<div class="tab-pane fade show active" id="info" role="tabpanel">';
        echo '  <div class="row g-3">';
        echo '    <div class="col-md-6">';
        echo '      <div class="p-3 bg-light rounded-4">';
        echo '        <div class="fw-bold mb-1">' . h($fullName !== '' ? $fullName : '---') . '</div>';
        echo '        <div class="small text-muted mb-2">رقم الجواز: <span class="font-monospace">' . h($passportNumber !== '' ? $passportNumber : '---') . '</span></div>';
        echo '        <div><span class="badge rounded-pill px-3 py-2 shadow-sm" style="background-color:' . h($statusColor) . ';color:#fff;">' . h($statusName) . '</span></div>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="col-md-6">';
        echo '      <div class="p-3 bg-light rounded-4">';
        echo '        <div class="small text-muted">التأشيرة</div>';
        echo '        <div class="fw-bold font-monospace">' . h($visaNumber !== '' ? $visaNumber : '---') . '</div>';
        echo '        <div class="small text-muted mt-2">انتهاء التأشيرة: ' . h((string)($p['visa_expiry_date'] ?? '---')) . '</div>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        echo '<div class="tab-pane fade" id="financial" role="tabpanel">';
        echo '  <div class="row g-3">';
        echo '    <div class="col-lg-6">';
        echo '      <div class="p-3 border rounded-4 bg-white">';
        echo '        <div class="fw-bold mb-2">فاتورة البيع</div>';
        if (!empty($p['sales_invoice_id'])) {
            echo '        <div class="small text-muted mb-2"><a href="invoice_details.php?id=' . (int)$p['sales_invoice_id'] . '" target="_blank">' . h((string)($p['sales_invoice_number'] ?? '')) . '</a></div>';
        } else {
            echo '        <div class="small text-muted mb-2">لا توجد</div>';
        }
        echo '        <div class="d-flex justify-content-between small"><span>الإجمالي</span><span class="fw-bold text-success">' . number_format($salesNet, 2) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المحصل</span><span class="fw-bold text-primary">' . number_format($salesReceived, 2) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المتبقي</span><span class="fw-bold text-danger">' . number_format($salesRemaining, 2) . '</span></div>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="col-lg-6">';
        echo '      <div class="p-3 border rounded-4 bg-white">';
        echo '        <div class="fw-bold mb-2">فاتورة الشراء</div>';
        if (!empty($p['purchase_invoice_id'])) {
            echo '        <div class="small text-muted mb-2"><a href="invoice_details.php?id=' . (int)$p['purchase_invoice_id'] . '" target="_blank">' . h((string)($p['purchase_invoice_number'] ?? '')) . '</a></div>';
        } else {
            echo '        <div class="small text-muted mb-2">لا توجد</div>';
        }
        echo '        <div class="d-flex justify-content-between small"><span>الإجمالي</span><span class="fw-bold text-danger">' . number_format($purchaseNet, 2) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المدفوع</span><span class="fw-bold text-primary">' . number_format($purchasePaid, 2) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المتبقي</span><span class="fw-bold text-danger">' . number_format($purchaseRemaining, 2) . '</span></div>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
        exit();
    }

    if ($action === 'get_hajj_for_edit') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'طلب غير صالح';
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM passports WHERE id = ? AND transaction_type = 'hajj' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'المعاملة غير موجودة';
            exit();
        }

        $suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE deleted_at IS NULL ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="p-3">';
        echo '<div class="row g-3">';

        echo '<div class="col-md-6"><label class="form-label fw-bold">الاسم</label><input type="text" name="full_name" class="form-control rounded-3" value="' . h((string)($p['full_name'] ?? '')) . '" required></div>';
        echo '<div class="col-md-6"><label class="form-label fw-bold">الاسم (EN)</label><input type="text" name="full_name_en" class="form-control rounded-3" value="' . h((string)($p['full_name_en'] ?? '')) . '"></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">رقم الجواز</label><input type="text" name="passport_number" class="form-control rounded-3 font-monospace" value="' . h((string)($p['passport_number'] ?? '')) . '" required></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">الجنسية</label><input type="text" name="nationality" class="form-control rounded-3" value="' . h((string)($p['nationality'] ?? '')) . '"></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">رقم الجوال</label><input type="text" name="phone_number" class="form-control rounded-3" value="' . h((string)($p['phone_number'] ?? '')) . '"></div>';

        $gender = (string)($p['gender'] ?? '');
        echo '<div class="col-md-4"><label class="form-label fw-bold">الجنس</label><select name="gender" class="form-select rounded-3"><option value="">---</option><option value="Male"' . ($gender === 'Male' ? ' selected' : '') . '>Male</option><option value="Female"' . ($gender === 'Female' ? ' selected' : '') . '>Female</option><option value="Other"' . ($gender === 'Other' ? ' selected' : '') . '>Other</option></select></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">تاريخ الميلاد</label><input type="date" name="date_of_birth" class="form-control rounded-3" value="' . h((string)($p['date_of_birth'] ?? '')) . '"></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">انتهاء الجواز</label><input type="date" name="passport_expiry_date" class="form-control rounded-3" value="' . h((string)($p['passport_expiry_date'] ?? '')) . '"></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">إصدار الجواز</label><input type="date" name="passport_issue_date" class="form-control rounded-3" value="' . h((string)($p['passport_issue_date'] ?? '')) . '"></div>';

        echo '<div class="col-md-4"><label class="form-label fw-bold">رقم التأشيرة</label><input type="text" name="visa_number" class="form-control rounded-3" value="' . h((string)($p['visa_number'] ?? '')) . '"></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">انتهاء التأشيرة</label><input type="date" name="visa_expiry_date" class="form-control rounded-3" value="' . h((string)($p['visa_expiry_date'] ?? '')) . '"></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">تاريخ إصدار التأشيرة</label><input type="date" name="visa_issue_date" class="form-control rounded-3" value="' . h((string)($p['visa_issue_date'] ?? '')) . '"></div>';

        $isOutside = (int)($p['is_outside_ksa'] ?? 0);
        echo '<div class="col-md-4"><label class="form-label fw-bold">خارج المملكة</label><select name="is_outside_ksa" class="form-select rounded-3"><option value="0"' . ($isOutside ? '' : ' selected') . '>لا</option><option value="1"' . ($isOutside ? ' selected' : '') . '>نعم</option></select></div>';

        $supplierId = (int)($p['supplier_id'] ?? 0);
        echo '<div class="col-md-4"><label class="form-label fw-bold">المورد</label><select name="supplier_id" class="form-select rounded-3"><option value="">---</option>';
        foreach ($suppliers as $s) {
            $sid = (int)$s['id'];
            echo '<option value="' . $sid . '"' . ($supplierId === $sid ? ' selected' : '') . '>' . h((string)$s['supplier_name']) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="col-12"><label class="form-label fw-bold">الوصف</label><textarea name="description" class="form-control rounded-3" rows="2">' . h((string)($p['description'] ?? '')) . '</textarea></div>';
        echo '<div class="col-12"><label class="form-label fw-bold">ملاحظات</label><textarea name="notes" class="form-control rounded-3" rows="2">' . h((string)($p['notes'] ?? '')) . '</textarea></div>';

        echo '</div>';
        echo '</div>';
        exit();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

if ($method === 'POST') {
    $csrf = get_request_csrf_token();
    if (!verify_csrf_token($csrf)) {
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'رمز الحماية غير صالح']);
        exit();
    }

    // Save Umrah Host
    if ($action === 'save_host') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $host_name = $_POST['host_name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $address = $_POST['address'] ?? '';
            $national_address = $_POST['national_address'] ?? '';

            if (empty($host_name)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم المستضيف مطلوب']);
                exit();
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE umrah_hosts SET host_name=?, phone=?, address=?, national_address=? WHERE id=?");
                $stmt->execute([$host_name, $phone, $address, $national_address, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO umrah_hosts (host_name, phone, address, national_address) VALUES (?, ?, ?, ?)");
                $stmt->execute([$host_name, $phone, $address, $national_address]);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم الحفظ بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Delete Umrah Host
    if ($action === 'delete_host') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المستضيف مطلوب']);
                exit();
            }
            $stmt = $pdo->prepare("DELETE FROM umrah_hosts WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم الحذف بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Save Umrah Guarantor
    if ($action === 'save_guarantor') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $guarantor_name = $_POST['guarantor_name'] ?? '';
            $identity_type = $_POST['identity_type'] ?? '';
            $identity_number = $_POST['identity_number'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $guarantor_type = $_POST['guarantor_type'] ?? 'individual';
            $address = $_POST['address'] ?? '';

            if (empty($guarantor_name)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم الضامن مطلوب']);
                exit();
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE umrah_guarantors SET guarantor_name=?, identity_type=?, identity_number=?, phone=?, guarantor_type=?, address=? WHERE id=?");
                $stmt->execute([$guarantor_name, $identity_type, $identity_number, $phone, $guarantor_type, $address, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO umrah_guarantors (guarantor_name, identity_type, identity_number, phone, guarantor_type, address) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$guarantor_name, $identity_type, $identity_number, $phone, $guarantor_type, $address]);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم الحفظ بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Delete Umrah Guarantor
    if ($action === 'delete_guarantor') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف الضامن مطلوب']);
                exit();
            }
            $stmt = $pdo->prepare("DELETE FROM umrah_guarantors WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم الحذف بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Add Umrah Transaction
    if ($action === 'add_hajj') {
        try {
            $pdo->beginTransaction();

            // Validate required fields
            $full_name = $_POST['full_name'] ?? '';
            $passport_number = $_POST['passport_number'] ?? '';
            if (empty($full_name) || empty($passport_number)) {
                throw new Exception('الاسم الكامل ورقم الجواز مطلوبين');
            }

            // Get settings
            $settings = getSettings($pdo);
            $hajj_service_name = 'خدمات الحج';

            // Get current user data
            $stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt_user->execute([$_SESSION['admin_id']]);
            $currentUser = $stmt_user->fetch(PDO::FETCH_ASSOC);

            // Get default status
            $default_status_id = $pdo->query("SELECT id FROM statuses WHERE status_name = 'معاملة جديدة' LIMIT 1")->fetchColumn();
            if (!$default_status_id) {
                $default_status_id = $pdo->query("SELECT id FROM statuses LIMIT 1")->fetchColumn();
            }

            $selected_branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : ($_SESSION['branch_id'] ?? null);

            // Extract customer_id and agent_id
            $customer_id = null;
            $agent_id = null;
            $account_id = $_POST['account_id'] ?? null;
            $delivery_type = $_POST['delivery_type'] ?? $_POST['payment_type'] ?? 'cash';
            if ($delivery_type === 'credit') {
                $customer_id = $_POST['customer_id'] ?? $_POST['customer_id_hidden'] ?? null;
            } elseif ($delivery_type === 'agent') {
                $agent_id = $_POST['agent_id'] ?? $_POST['agent_id_hidden'] ?? null;
            }

            // Insert into passports
            $insert_passport = $pdo->prepare("
                INSERT INTO passports (
                    full_name, 
                    full_name_en, 
                    passport_number, 
                    passport_issue_date, 
                    passport_expiry_date, 
                    nationality, 
                    gender, 
                    date_of_birth, 
                    phone_number, 
                    operation_date, 
                    service_id, 
                    transaction_type, 
                    service_type, 
                    status_id, 
                    workflow_id, 
                    status_changed_by, 
                    created_by, 
                    branch_id, 
                    agent_id, 
                    customer_id, 
                    supplier_id, 
                    host_id, 
                    guarantor_id, 
                    is_outside_ksa, 
                    visa_number, 
                    visa_issue_date, 
                    visa_expiry_date, 
                    description, 
                    notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insert_passport->execute([
                $full_name,
                $_POST['full_name_en'] ?? '',
                $passport_number,
                $_POST['passport_issue_date'] ?? null,
                $_POST['passport_expiry_date'] ?? null,
                $_POST['nationality'] ?? '',
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['phone_number'] ?? '',
                normalize_datetime_db($_POST['invoice_date'] ?? null),
                $_POST['service_id'] ?? null,
                'hajj',
                'hajj',
                $default_status_id,
                isset($settings['umrah_workflow_enabled']) && $settings['umrah_workflow_enabled'] ? get_workflow_id_by_transaction_type($pdo, 'hajj') : null,
                $_SESSION['admin_id'],
                $_SESSION['admin_id'],
                $selected_branch_id,
                $agent_id,
                $customer_id,
                $_POST['supplier_id'] ?? null,
                null,
                null,
                $_POST['is_outside_ksa'] ?? 0,
                $_POST['visa_number'] ?? '',
                $_POST['visa_issue_date'] ?? null,
                $_POST['visa_expiry_date'] ?? null,
                $_POST['description'] ?? '',
                $_POST['notes'] ?? ''
            ]);

            $passport_id = $pdo->lastInsertId();

            // Handle Financial Engine
            $service_id = $_POST['service_id'] ?? null;
            if ($service_id) {
                $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['admin_id']);
                $financeResults = $financialEngine->processServiceFinance([
                    'source_type'     => $hajj_service_name,
                    'service_type'    => 'hajj',
                    'source_id'       => $passport_id,
                    'source_number'   => 'HJ-'.$passport_id,
                    'branch_id'       => $selected_branch_id,
                    'customer_id'     => $customer_id,
                    'agent_id'        => $agent_id,
                    'supplier_id'     => $_POST['supplier_id'] ?? null,
                    'sale_price'      => $_POST['total_amount'] ?? 0,
                    'discount'        => $_POST['discount'] ?? 0,
                    'purchase_price'  => $_POST['cost_amount'] ?? 0,
                    'sale_currency_id'=> $_POST['sale_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'pur_currency_id' => $_POST['main_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'exchange_rate'   => $_POST['invoice_exchange_rate'] ?? 1,
                    'amount_received' => $_POST['received_amount'] ?? $_POST['amount_received'] ?? 0,
                    'payment_account_id' => $account_id,
                    'delivery_type'   => $delivery_type,
                    'record_purchase' => $_POST['record_purchase'] ?? 1,
                    'description'     => trim((string)($_POST['description'] ?? '')) !== '' ? trim((string)$_POST['description']) : ('معاملة حج رقم: ' . $passport_id . ' - ' . $full_name),
                    'operation_date'  => normalize_datetime_db($_POST['invoice_date'] ?? null)
                ]);

                // Update passports with invoice ids
                $update_passport = $pdo->prepare("
                    UPDATE passports 
                    SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                    WHERE id = ?
                ");
                $update_passport->execute([
                    $financeResults['sales_invoice_id'],
                    $financeResults['purchase_invoice_id'] ?? null,
                    $passport_id
                ]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'تم حفظ المعاملة بنجاح']);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'change_status') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $status_id = (int)($_POST['status_id'] ?? 0);
            if ($id <= 0 || $status_id <= 0) {
                throw new Exception('بيانات غير مكتملة');
            }

            $visa_number = $_POST['visa_number'] ?? null;
            $visa_issue_date = $_POST['visa_issue_date'] ?? null;
            $visa_expiry_date = $_POST['visa_expiry_date'] ?? null;
            $is_outside_ksa = isset($_POST['is_outside_ksa']) ? (int)$_POST['is_outside_ksa'] : null;

            $stmt = $pdo->prepare("
                UPDATE passports
                SET status_id = ?,
                    visa_number = ?,
                    visa_issue_date = ?,
                    visa_expiry_date = ?,
                    is_outside_ksa = COALESCE(?, is_outside_ksa),
                    status_changed_at = NOW(),
                    status_changed_by = ?,
                    updated_at = NOW()
                WHERE id = ? AND transaction_type = 'hajj' AND deleted_at IS NULL
            ");
            $stmt->execute([$status_id, $visa_number, $visa_issue_date, $visa_expiry_date, $is_outside_ksa, (int)$_SESSION['admin_id'], $id]);

            echo json_encode(['success' => true, 'status' => 'success', 'message' => 'تم تحديث الحالة بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'update_traveler') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('معرف غير صالح');
            }

            $stmt = $pdo->prepare("
                UPDATE passports
                SET full_name = ?,
                    full_name_en = ?,
                    nationality = ?,
                    gender = ?,
                    date_of_birth = ?,
                    passport_number = ?,
                    passport_issue_date = ?,
                    passport_expiry_date = ?,
                    phone_number = ?,
                    supplier_id = ?,
                    host_id = ?,
                    guarantor_id = ?,
                    is_outside_ksa = ?,
                    visa_number = ?,
                    visa_issue_date = ?,
                    visa_expiry_date = ?,
                    description = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND transaction_type = 'hajj' AND deleted_at IS NULL
            ");
            $stmt->execute([
                $_POST['full_name'] ?? '',
                $_POST['full_name_en'] ?? null,
                $_POST['nationality'] ?? null,
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['passport_number'] ?? '',
                $_POST['passport_issue_date'] ?? null,
                $_POST['passport_expiry_date'] ?? null,
                $_POST['phone_number'] ?? null,
                ($_POST['supplier_id'] ?? '') !== '' ? (int)$_POST['supplier_id'] : null,
                null,
                null,
                isset($_POST['is_outside_ksa']) ? (int)$_POST['is_outside_ksa'] : 0,
                $_POST['visa_number'] ?? null,
                $_POST['visa_issue_date'] ?? null,
                $_POST['visa_expiry_date'] ?? null,
                $_POST['description'] ?? null,
                $_POST['notes'] ?? null,
                $id
            ]);

            echo json_encode(['success' => true, 'status' => 'success', 'message' => 'تم حفظ التعديلات بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'delete_hajj') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('معرف غير صالح');
            }

            $stmt = $pdo->prepare("SELECT sales_invoice_id, purchase_invoice_id FROM passports WHERE id = ? AND transaction_type = 'hajj' AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('المعاملة غير موجودة');
            }

            $invoiceIds = [];
            if (!empty($row['sales_invoice_id'])) $invoiceIds[] = (int)$row['sales_invoice_id'];
            if (!empty($row['purchase_invoice_id'])) $invoiceIds[] = (int)$row['purchase_invoice_id'];

            if (!empty($invoiceIds)) {
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                $stmtInv = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE id IN ($placeholders) AND invoice_status = 'posted'");
                $stmtInv->execute($invoiceIds);
                if ((int)$stmtInv->fetchColumn() > 0) {
                    throw new Exception('لا يمكن حذف المعاملة لوجود فواتير مرحلة. قم بإلغاء الترحيل أولاً.');
                }
            }

            $stmtDel = $pdo->prepare("
                UPDATE passports
                SET deleted_at = NOW(),
                    cancellation_date = CURDATE(),
                    cancellation_reason = 'حذف بواسطة المستخدم',
                    updated_at = NOW()
                WHERE id = ? AND transaction_type = 'hajj' AND deleted_at IS NULL
            ");
            $stmtDel->execute([$id]);

            echo json_encode(['success' => true, 'status' => 'success', 'message' => 'تم حذف المعاملة']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }
}

echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
?>
