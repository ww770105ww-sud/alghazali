<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/security.php';
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_family_visit:' . $action, 60, 60);
require_csrf_for_actions(['update_request_status', 'update_visa_info', 'process_transition', 'post_finance', 'unpost_finance', 'cancel_invoices']);

function family_visit_collect_invoice_ids_by_scope(array $requestRow, string $scope): array
{
    $scope = strtolower(trim($scope));
    $salesId = !empty($requestRow['sales_invoice_id']) ? (int)$requestRow['sales_invoice_id'] : (!empty($requestRow['invoice_id']) ? (int)$requestRow['invoice_id'] : null);
    $purchaseId = !empty($requestRow['purchase_invoice_id']) ? (int)$requestRow['purchase_invoice_id'] : null;
    $map = [
        'sales' => $salesId,
        'purchase' => $purchaseId,
    ];

    if ($scope === 'sales') {
        return array_values(array_filter([$map['sales']]));
    }
    if ($scope === 'purchase') {
        return array_values(array_filter([$map['purchase']]));
    }

    return array_values(array_filter([$map['sales'], $map['purchase']]));
}

function family_visit_reset_invoice_to_draft(PDO $pdo, int $invoiceId, int $userId): void
{
    $stmtOld = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmtOld->execute([$invoiceId]);
    $oldInvoice = $stmtOld->fetch(PDO::FETCH_ASSOC);
    if (!$oldInvoice) {
        return;
    }

    $invoiceNumber = $oldInvoice['invoice_number'] ?? null;
    if ($invoiceNumber) {
        $numericInvNum = preg_replace('/[^0-9]/', '', $invoiceNumber);
        $stmtFt = $pdo->prepare("
            SELECT id FROM financial_transactions
            WHERE
                (transaction_number = ? OR reference_number = ?)
                OR
                (transaction_number = ? OR reference_number = ?)
                OR
                (reference_id = ? AND reference_type = 'invoice')
        ");
        $stmtFt->execute([
            $invoiceNumber,
            $invoiceNumber,
            $numericInvNum,
            $numericInvNum,
            $invoiceId
        ]);

        foreach ($stmtFt->fetchAll(PDO::FETCH_COLUMN) as $ftId) {
            $stmtVoucher = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
            $stmtVoucher->execute([$ftId]);
            $voucher = $stmtVoucher->fetch(PDO::FETCH_ASSOC);
            if (!$voucher || ($voucher['status'] ?? '') !== 'posted') {
                continue;
            }

            if (!balances_triggers_enabled($pdo)) {
                apply_transaction_balances($pdo, (int)$ftId, -1);
            }

            $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ftId]);
            $stmtResetVoucher = $pdo->prepare("
                UPDATE financial_transactions
                SET status = 'cancelled',
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = ?
                WHERE id = ?
            ");
            $stmtResetVoucher->execute([$userId, $ftId]);

            $stmtAllocs = $pdo->prepare("SELECT DISTINCT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
            $stmtAllocs->execute([$ftId]);
            foreach ($stmtAllocs->fetchAll(PDO::FETCH_COLUMN) as $linkedInvoiceId) {
                php_recalculate_invoice_payment($pdo, $linkedInvoiceId);
            }
        }
    }

    $stmt = $pdo->prepare("UPDATE invoices SET invoice_status = 'draft', posted_at = NULL, posted_by = NULL, payment_status = 'unpaid' WHERE id = ?");
    $stmt->execute([$invoiceId]);
}

function family_visit_find_latest_invoice_id(PDO $pdo, int $requestId, string $category): ?int
{
    $category = $category === 'purchase' ? 'purchase' : 'sales';
    $stmt = $pdo->prepare("
        SELECT id
        FROM invoices
        WHERE source_type = 'FamilyVisit' AND source_id = ? AND invoice_category = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$requestId, $category]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

function family_visit_cancel_invoice(PDO $pdo, int $invoiceId, int $userId, string $note): void
{
    $stmt = $pdo->prepare("SELECT id, invoice_status, invoice_number FROM invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return;
    }
    if (($inv['invoice_status'] ?? '') === 'posted') {
        throw new Exception('لا يمكن حذف فاتورة مُرحلة. قم بإلغاء الترحيل أولاً.');
    }

    $stmtFt = $pdo->prepare("
        SELECT id, status
        FROM financial_transactions
        WHERE reference_type = 'invoice'
          AND reference_id = ?
          AND status IN ('draft', 'posted')
    ");
    $stmtFt->execute([(int)$invoiceId]);
    foreach ($stmtFt->fetchAll(PDO::FETCH_ASSOC) as $ft) {
        if (($ft['status'] ?? '') === 'posted') {
            throw new Exception('يوجد قيد مُرحل مرتبط بهذه الفاتورة. قم بإلغاء الترحيل أولاً.');
        }
        $ftId = (int)$ft['id'];
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ftId]);
        $pdo->prepare("UPDATE financial_transactions SET status='cancelled', updated_at=CURRENT_TIMESTAMP, updated_by=? WHERE id=?")->execute([$userId, $ftId]);
    }

    $stmtUpd = $pdo->prepare("
        UPDATE invoices
        SET invoice_status = 'cancelled',
            updated_at = CURRENT_TIMESTAMP,
            updated_by = ?,
            description = CONCAT(COALESCE(description, ''), ?)
        WHERE id = ?
    ");
    $suffix = ' | ' . trim($note) . ' (' . date('Y-m-d H:i') . ')';
    $stmtUpd->execute([$userId, $suffix, $invoiceId]);
}

if ($action === 'get_requirements') {
    $relationship_id = $_GET['relationship_id'] ?? null;
    $age = $_GET['age'] ?? null;
    $gender = $_GET['gender'] ?? null;

    if (!$relationship_id) {
        echo json_encode([]);
        exit();
    }

    $sql = "SELECT * FROM family_requirements WHERE relationship_id = ?";
    $params = [$relationship_id];

    if ($age !== null) {
        $sql .= " AND min_age <= ? AND max_age >= ?";
        $params[] = $age;
        $params[] = $age;
    }

    if ($gender) {
        $sql .= " AND (gender = ? OR gender = 'both')";
        $params[] = $gender;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

elseif ($action === 'get_request_details') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
        exit();
    }

    // Get Request
    $stmt = $pdo->prepare("
        SELECT r.*, s.status_name, s.status_color, 
               ag.agent_name, br.branch_name, u.username as creator_name
        FROM family_visit_requests r
        LEFT JOIN statuses s ON r.status_id = s.id
        LEFT JOIN agents ag ON r.agent_id = ag.id
        LEFT JOIN branches br ON r.branch_id = br.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    // تنفيذ التحقق من عزل البيانات (Data Isolation Security)
    if ($request) {
        // التحقق من صلاحيات عرض الطلب (عزل البيانات)
        $stmt_u = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt_u->execute([$_SESSION['admin_id']]);
        $currU = $stmt_u->fetch();

        $is_super_user = in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer']);
        $can_view_all = has_permission('view_all_passports');
        
        if (!$is_super_user && !$can_view_all) {
            if (!empty($currU['agent_id']) && $request['agent_id'] != $currU['agent_id']) {
                echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية عرض هذا الطلب (مرتبط بوكيل آخر)']);
                exit();
            }
            if (!empty($currU['branch_id']) && $request['branch_id'] != $currU['branch_id']) {
                echo json_encode(['status' => 'error', 'message' => 'ليس لديك صلاحية عرض هذا الطلب (مرتبط بفرع آخر)']);
                exit();
            }
        }
    }

    if (!$request) {
        echo json_encode(['status' => 'error', 'message' => 'Request not found']);
        exit();
    }

    $hasComingFromCity = !empty($pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'coming_from_city_id'")->fetchAll(PDO::FETCH_ASSOC));
    $hasReceivedDocs = !empty($pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'received_documents'")->fetchAll(PDO::FETCH_ASSOC));

    $selectCols = "i.*, rel.name_ar as relationship_name, s.status_name as individual_status";
    $joins = "";
    if ($hasComingFromCity) {
        $selectCols .= ", c.city_name as coming_from_city_name";
        $joins .= " LEFT JOIN cities c ON i.coming_from_city_id = c.id ";
    }
    if (!$hasReceivedDocs) {
        $selectCols .= ", NULL as received_documents";
    }

    // Get Individuals
    $stmt_ind = $pdo->prepare("
        SELECT $selectCols
        FROM family_visit_individuals i
        LEFT JOIN family_relationships rel ON i.relationship_id = rel.id
        LEFT JOIN statuses s ON i.status_id = s.id
        $joins
        WHERE i.request_id = ?
    ");
    $stmt_ind->execute([$id]);
    $request['individuals'] = $stmt_ind->fetchAll(PDO::FETCH_ASSOC);

    // Get Attachments for each individual
    $attachmentsTableExists = false;
    try {
        $attachmentsTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'family_individual_attachments'")->fetchColumn();
    } catch (Throwable $e) {
        $attachmentsTableExists = false;
    }

    foreach ($request['individuals'] as &$ind) {
        if (!$attachmentsTableExists) {
            $ind['attachments'] = [];
            continue;
        }
        $stmt_att = $pdo->prepare("SELECT * FROM family_individual_attachments WHERE individual_id = ?");
        $stmt_att->execute([$ind['id']]);
        $ind['attachments'] = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['status' => 'success', 'data' => $request]);
}

elseif ($action === 'get_service_price') {
    $service_id = 5; // ID خدمة زيارة الأسرة في جدول services
    $branch_id = $_GET['branch_id'] ?? null;
    $agent_id = $_GET['agent_id'] ?? null;
    $customer_id = $_GET['customer_id'] ?? null;
    $supplier_id = $_GET['supplier_id'] ?? null;

    // إذا لم يتم تحديد أي جهة، استخدام معلومات المستخدم الحالي (الوكيل أو الفرع)
    if (!$agent_id && !$branch_id && !$customer_id && !$supplier_id) {
        $stmt_u = $pdo->prepare("SELECT agent_id, branch_id FROM users WHERE id = ?");
        $stmt_u->execute([$_SESSION['admin_id']]);
        $u_info = $stmt_u->fetch();
        $agent_id = $u_info['agent_id'];
        $branch_id = $u_info['branch_id'];
    }

    try {
        $price = get_service_price_config($pdo, $service_id, $agent_id, $branch_id, $customer_id, $supplier_id);

        if ($price) {
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'price' => (float) $price['purchase_price'],
                    'purchase_price' => (float) $price['purchase_price'],
                    'sale_price' => (float) $price['sale_price'],
                    'currency_id' => $price['currency_id'],
                    'currency_name' => $price['currency_name'],
                    'currency_symbol' => $price['currency_symbol'],
                    'agent_price' => (float) ($price['agent_price'] ?? 0),
                    'branch_price' => (float) ($price['branch_price'] ?? 0),
                    'target_type' => $price['target_type']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'لم يتم إعداد سعر الخدمة لهذه الحالة، يرجى التحقق من إعدادات الأسعار']);
        }
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ داخلي في النظام']);
    }
}

elseif ($action === 'update_request_status') {
    $id = $_POST['id'] ?? $_GET['id'] ?? null;
    $status_id = $_POST['status_id'] ?? $_GET['status_id'] ?? null;

    if (!$id || !$status_id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID or Status']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Update Request
        $stmt = $pdo->prepare("UPDATE family_visit_requests SET status_id = ? WHERE id = ?");
        $stmt->execute([$status_id, $id]);

        // Update all individuals in this request
        $stmt_ind = $pdo->prepare("UPDATE family_visit_individuals SET status_id = ? WHERE request_id = ?");
        $stmt_ind->execute([$status_id, $id]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ داخلي في النظام']);
    }
}

elseif ($action === 'update_visa_info') {
    $id = $_POST['id'] ?? $_GET['id'] ?? null;
    $visa_no = $_POST['visa_no'] ?? $_GET['visa_no'] ?? '';
    $duration = intval($_POST['duration'] ?? $_GET['duration'] ?? 30);

    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
        exit();
    }

    $expiry_date = date('Y-m-d', strtotime("+$duration days"));

    $stmt = $pdo->prepare("UPDATE family_visit_requests SET visa_no = ?, visa_duration = ?, visa_expiry_date = ? WHERE id = ?");
    $stmt->execute([$visa_no, $duration, $expiry_date, $id]);

    echo json_encode(['status' => 'success']);
}

elseif ($action === 'post_finance') {
    $role = strtolower((string)($_SESSION['role'] ?? ''));
    $canPost = has_permission('family_visit_financial_post') || has_permission('work_visa_financial_post') || in_array($role, ['admin', 'developer'], true);
    if (!$canPost) {
        echo json_encode(['status' => 'error', 'message' => 'لا تملك صلاحية الترحيل المالي']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $scope = strtolower(trim((string)($_POST['scope'] ?? 'all')));
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, sales_invoice_id, purchase_invoice_id, invoice_id FROM family_visit_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            throw new Exception('الطلب غير موجود');
        }

        $invoiceIds = family_visit_collect_invoice_ids_by_scope($req, $scope);
        if (empty($invoiceIds)) {
            throw new Exception('لا توجد فاتورة مطابقة لنطاق الترحيل المحدد');
        }

        $userId = (int)($_SESSION['admin_id'] ?? 0);
        $postedCount = 0;
        foreach ($invoiceIds as $invoiceId) {
            $stmtInv = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ?");
            $stmtInv->execute([$invoiceId]);
            if ($stmtInv->fetchColumn() === 'draft') {
                php_post_invoice($pdo, (int)$invoiceId, $userId, true);
                $postedCount++;
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "تم ترحيل {$postedCount} فاتورة بنجاح"]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

elseif ($action === 'unpost_finance') {
    $role = strtolower((string)($_SESSION['role'] ?? ''));
    $canPost = has_permission('family_visit_financial_post') || has_permission('work_visa_financial_post') || in_array($role, ['admin', 'developer'], true);
    if (!$canPost) {
        echo json_encode(['status' => 'error', 'message' => 'لا تملك صلاحية إلغاء الترحيل المالي']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $scope = strtolower(trim((string)($_POST['scope'] ?? 'all')));
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, sales_invoice_id, purchase_invoice_id, invoice_id FROM family_visit_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            throw new Exception('الطلب غير موجود');
        }

        $invoiceIds = family_visit_collect_invoice_ids_by_scope($req, $scope);
        if (empty($invoiceIds)) {
            throw new Exception('لا توجد فاتورة مطابقة لنطاق إلغاء الترحيل المحدد');
        }

        $userId = (int)($_SESSION['admin_id'] ?? 0);
        $resetCount = 0;
        foreach ($invoiceIds as $invoiceId) {
            $stmtInv = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ?");
            $stmtInv->execute([$invoiceId]);
            if ($stmtInv->fetchColumn() === 'posted') {
                family_visit_reset_invoice_to_draft($pdo, (int)$invoiceId, $userId);
                $resetCount++;
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "تم إلغاء ترحيل {$resetCount} فاتورة بنجاح"]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

elseif ($action === 'cancel_invoices') {
    $role = strtolower((string)($_SESSION['role'] ?? ''));
    $canCancel = has_permission('family_visit_financial_post') || has_permission('work_visa_financial_post') || in_array($role, ['admin', 'developer'], true);
    if (!$canCancel) {
        echo json_encode(['status' => 'error', 'message' => 'لا تملك صلاحية حذف الفواتير']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $scope = strtolower(trim((string)($_POST['scope'] ?? 'all')));
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, sales_invoice_id, purchase_invoice_id, invoice_id FROM family_visit_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            throw new Exception('الطلب غير موجود');
        }

        $salesInvoiceId = !empty($req['sales_invoice_id']) ? (int)$req['sales_invoice_id'] : (!empty($req['invoice_id']) ? (int)$req['invoice_id'] : null);
        $purchaseInvoiceId = !empty($req['purchase_invoice_id']) ? (int)$req['purchase_invoice_id'] : null;

        if (!$salesInvoiceId) {
            $salesInvoiceId = family_visit_find_latest_invoice_id($pdo, $id, 'sales');
        }
        if (!$purchaseInvoiceId) {
            $purchaseInvoiceId = family_visit_find_latest_invoice_id($pdo, $id, 'purchase');
        }

        $targets = [];
        if ($scope === 'sales') {
            if ($salesInvoiceId) $targets[] = ['type' => 'sales', 'invoice_id' => $salesInvoiceId];
        } elseif ($scope === 'purchase') {
            if ($purchaseInvoiceId) $targets[] = ['type' => 'purchase', 'invoice_id' => $purchaseInvoiceId];
        } else {
            if ($salesInvoiceId) $targets[] = ['type' => 'sales', 'invoice_id' => $salesInvoiceId];
            if ($purchaseInvoiceId) $targets[] = ['type' => 'purchase', 'invoice_id' => $purchaseInvoiceId];
        }

        if (empty($targets)) {
            throw new Exception('لا توجد فواتير للحذف');
        }

        $userId = (int)($_SESSION['admin_id'] ?? 0);
        $cancelled = 0;
        foreach ($targets as $t) {
            $note = ($t['type'] === 'sales') ? 'حذف فاتورة البيع للزيارة العائلية' : 'حذف فاتورة الشراء للزيارة العائلية';
            family_visit_cancel_invoice($pdo, (int)$t['invoice_id'], $userId, $note);
            $cancelled++;
        }

        $updateParts = [];
        $updateParams = [];
        if ($scope === 'sales' || $scope === 'all') {
            $updateParts[] = "sales_invoice_id = NULL";
            $updateParts[] = "invoice_id = NULL";
        }
        if ($scope === 'purchase' || $scope === 'all') {
            $updateParts[] = "purchase_invoice_id = NULL";
        }
        if (!empty($updateParts)) {
            $sql = "UPDATE family_visit_requests SET " . implode(", ", $updateParts) . " WHERE id = ?";
            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute([$id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "تم حذف {$cancelled} فاتورة بنجاح"]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>

