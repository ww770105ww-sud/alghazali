<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$action = $_GET['action'] ?? '';

if (!in_array($action, ['add', 'update'], true)) {
    header('Location: postal_services.php');
    exit();
}

if ($action === 'add' && !has_permission('family_visit_add')) {
    $_SESSION['error'] = 'ليس لديك صلاحية إضافة معاملة بريدية.';
    header('Location: postal_services.php');
    exit();
}

if ($action === 'update' && !has_permission('family_visit_edit')) {
    $_SESSION['error'] = 'ليس لديك صلاحية تعديل الشحنة البريدية.';
    header('Location: postal_services.php');
    exit();
}

if (!function_exists('generate_next_postal_tracking_number')) {
    function generate_next_postal_tracking_number(PDO $pdo): string
    {
        $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM postal_shipments");
        $nextNumber = (int)$stmt->fetchColumn();
        return 'POST-' . str_pad((string)max(1, $nextNumber), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('build_postal_accounting_description')) {
    function build_postal_accounting_description(?string $senderCityName, ?string $recipientCityName, ?string $senderFullName): string
    {
        $senderCityName = trim((string)$senderCityName);
        $recipientCityName = trim((string)$recipientCityName);
        $senderFullName = trim((string)$senderFullName);

        $description = 'ارسال رساله';
        if ($senderCityName !== '') {
            $description .= ' من ' . $senderCityName;
        }
        if ($recipientCityName !== '') {
            $description .= ' الى ' . $recipientCityName;
        }
        if ($senderFullName !== '') {
            $description .= ' للاخ ' . $senderFullName;
        }

        return trim($description);
    }
}

try {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        throw new Exception('خطأ في التحقق من الطلب (CSRF).');
    }

    $trackingNumber = '';
    $shipmentName = trim((string)($_POST['shipment_name'] ?? ''));
    $contentDescription = trim((string)($_POST['content_description'] ?? ''));
    $expectedSendDate = trim((string)($_POST['expected_send_date'] ?? ''));
    $senderFullName = trim((string)($_POST['sender_full_name'] ?? ''));
    $senderPhone = trim((string)($_POST['sender_phone'] ?? ''));
    $recipientFullName = trim((string)($_POST['recipient_full_name'] ?? ''));
    $recipientPhone = trim((string)($_POST['recipient_phone'] ?? ''));
    $courierName = trim((string)($_POST['courier_name'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($shipmentName === '' || $senderFullName === '' || $recipientFullName === '') {
        throw new Exception('يرجى تعبئة الحقول الأساسية للشحنة البريدية.');
    }

    $settings = getSettings($pdo);
    $senderCityId = !empty($_POST['sender_city_id']) ? (int)$_POST['sender_city_id'] : null;
    $recipientCityId = !empty($_POST['recipient_city_id']) ? (int)$_POST['recipient_city_id'] : null;
    $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $supplierId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $operationDate = normalize_datetime_db($_POST['invoice_date'] ?? ($_POST['operation_date'] ?? null));
    $senderCityName = '';
    $recipientCityName = '';

    if ($senderCityId) {
        $stmtCity = $pdo->prepare("SELECT city_name FROM cities WHERE id = ? LIMIT 1");
        $stmtCity->execute([$senderCityId]);
        $senderCityName = (string)($stmtCity->fetchColumn() ?: '');
    }

    if ($recipientCityId) {
        $stmtCity = $pdo->prepare("SELECT city_name FROM cities WHERE id = ? LIMIT 1");
        $stmtCity->execute([$recipientCityId]);
        $recipientCityName = (string)($stmtCity->fetchColumn() ?: '');
    }

    if ($action === 'update') {
        $shipmentId = (int)($_POST['shipment_id'] ?? 0);
        if ($shipmentId <= 0) {
            throw new Exception('معرف الشحنة غير صالح.');
        }

        $stmtCheck = $pdo->prepare("SELECT id, tracking_number FROM postal_shipments WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmtCheck->execute([$shipmentId]);
        $currentShipment = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$currentShipment) {
            throw new Exception('الشحنة البريدية غير موجودة.');
        }

        $description = trim((string)($_POST['description'] ?? ''));
        if ($description === '') {
            $description = build_postal_accounting_description($senderCityName, $recipientCityName, $senderFullName);
        }

        $target = normalize_service_target($pdo, $_POST['agent_id'] ?? null, $_POST['branch_id'] ?? null);
        $stmtUpdateShipment = $pdo->prepare("
            UPDATE postal_shipments
            SET shipment_name = ?,
                content_description = ?,
                expected_send_date = ?,
                sender_full_name = ?,
                sender_city_id = ?,
                sender_phone = ?,
                recipient_full_name = ?,
                recipient_city_id = ?,
                recipient_phone = ?,
                courier_name = ?,
                notes = ?,
                description = ?,
                operation_date = ?,
                customer_id = ?,
                supplier_id = ?,
                agent_id = ?,
                branch_id = ?
            WHERE id = ?
        ");
        $stmtUpdateShipment->execute([
            $shipmentName,
            $contentDescription !== '' ? $contentDescription : null,
            $expectedSendDate !== '' ? $expectedSendDate : null,
            $senderFullName,
            $senderCityId,
            $senderPhone !== '' ? $senderPhone : null,
            $recipientFullName,
            $recipientCityId,
            $recipientPhone !== '' ? $recipientPhone : null,
            $courierName !== '' ? $courierName : null,
            $notes !== '' ? $notes : null,
            $description,
            $operationDate,
            $customerId,
            $supplierId,
            $target['agent_id'],
            $target['branch_id'],
            $shipmentId,
        ]);

        $_SESSION['success'] = 'تم تحديث الشحنة البريدية بنجاح.';
    } else {
        $pdo->beginTransaction();

        $serviceId = resolve_service_id($pdo, 'خدمات البريد');
        if ($serviceId <= 0) {
            $serviceId = resolve_service_id($pdo, 'postal');
        }
        if ($serviceId <= 0) {
            throw new Exception('خدمة خدمات البريد غير مهيأة بعد. شغّل سكربت الإعداد أولاً.');
        }

        try {
            $pricingData = resolve_transaction_pricing(
                $pdo,
                $serviceId,
                $_POST['agent_id'] ?? null,
                $_POST['branch_id'] ?? null,
                $_POST
            );
        } catch (Throwable $pricingError) {
            $target = normalize_service_target($pdo, $_POST['agent_id'] ?? null, $_POST['branch_id'] ?? null);
            $pricingData = [
                'target' => $target,
                'currency_id' => !empty($_POST['currency_id']) ? (int)$_POST['currency_id'] : (int)($settings['base_currency_id'] ?? 1),
                'purchase_price' => (float)($_POST['cost_amount'] ?? 0),
                'sale_price' => (float)($_POST['total_amount'] ?? 0),
                'pricing' => null,
            ];
        }

        $agentId = $pricingData['target']['agent_id'];
        $branchId = $pricingData['target']['branch_id'];
        $trackingNumber = generate_next_postal_tracking_number($pdo);

        $description = trim((string)($_POST['description'] ?? ''));
        if ($description === '') {
            $description = build_postal_accounting_description($senderCityName, $recipientCityName, $senderFullName);
        }

        $stmt = $pdo->prepare("
            INSERT INTO postal_shipments (
                tracking_number,
                shipment_name,
                content_description,
                expected_send_date,
                sender_full_name,
                sender_city_id,
                sender_phone,
                recipient_full_name,
                recipient_city_id,
                recipient_phone,
                courier_name,
                notes,
                description,
                operation_date,
                customer_id,
                supplier_id,
                agent_id,
                branch_id,
                created_by,
                status_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $trackingNumber,
            $shipmentName,
            $contentDescription !== '' ? $contentDescription : null,
            $expectedSendDate !== '' ? $expectedSendDate : null,
            $senderFullName,
            $senderCityId,
            $senderPhone !== '' ? $senderPhone : null,
            $recipientFullName,
            $recipientCityId,
            $recipientPhone !== '' ? $recipientPhone : null,
            $courierName !== '' ? $courierName : null,
            $notes !== '' ? $notes : null,
            $description,
            $operationDate,
            $customerId,
            $supplierId,
            $agentId,
            $branchId,
            (int)$_SESSION['admin_id'],
        ]);

        $shipmentId = (int)$pdo->lastInsertId();

        require_once '../includes/ServiceFinancialEngine.php';
        $financialEngine = new ServiceFinancialEngine($pdo, (int)$_SESSION['admin_id']);
        $financeResults = $financialEngine->processServiceFinance([
            'service_type' => 'خدمات البريد',
            'source_id' => $shipmentId,
            'source_number' => $trackingNumber,
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'agent_id' => $agentId,
            'supplier_id' => $supplierId,
            'sale_total_amount' => (float)($_POST['total_amount'] ?? 0),
            'discount' => (float)($_POST['discount'] ?? 0),
            'purchase_total_amount' => (float)($_POST['cost_amount'] ?? 0),
            'sale_currency_id' => $_POST['currency_id'] ?? ($pricingData['currency_id'] ?? ($settings['base_currency_id'] ?? 1)),
            'pur_currency_id' => $_POST['currency_id'] ?? ($pricingData['currency_id'] ?? ($settings['base_currency_id'] ?? 1)),
            'exchange_rate' => (float)($_POST['exchange_rate'] ?? 1),
            'amount_received' => (float)($_POST['amount_received'] ?? 0),
            'payment_account_id' => $_POST['account_id'] ?? null,
            'delivery_type' => $_POST['delivery_type'] ?? ($settings['default_delivery_type'] ?? 'draft'),
            'description' => $description,
            'operation_date' => $operationDate,
        ]);

        $stmtUpdate = $pdo->prepare("
            UPDATE postal_shipments
            SET sales_invoice_id = ?,
                purchase_invoice_id = ?,
                auto_invoice_generated = 1
            WHERE id = ?
        ");
        $stmtUpdate->execute([
            $financeResults['sales_invoice_id'] ?? null,
            $financeResults['purchase_invoice_id'] ?? null,
            $shipmentId,
        ]);

        $pdo->commit();

        $_SESSION['success'] = 'تمت إضافة الشحنة البريدية بنجاح.';
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (($e instanceof PDOException) && (int)($e->errorInfo[1] ?? 0) === 1062) {
        $_SESSION['error'] = 'رقم التتبع مستخدم مسبقاً. يرجى إدخال رقم تتبع مختلف.';
    } else {
        $_SESSION['error'] = 'تعذر حفظ الشحنة البريدية: ' . $e->getMessage();
    }
}

header('Location: postal_services.php');
exit();
