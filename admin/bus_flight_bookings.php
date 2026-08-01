<?php
ob_start();
require_once 'header.php';
require_once '../core/bookings/BookingServiceFactory.php';
require_once '../core/bookings/BookingCreatorService.php';
require_once '../core/bookings/BookingFinancialUpdater.php';
require_once '../core/bookings/BookingValidator.php';
require_once '../core/bookings/BookingWorkflowService.php';

$forcedBookingServiceType = $forcedBookingServiceType ?? null;
$pageBookingModule = BookingServiceFactory::make($forcedBookingServiceType);
$bookingPageUrl = basename($_SERVER['PHP_SELF']);
$allowedBookingServiceTypes = $pageBookingModule->getAllowedServiceTypes();
$bookingFinancialSourceTypes = $pageBookingModule->getFinancialSourceTypes();
$bookingWorkflowTransactionType = $pageBookingModule->getWorkflowTransactionType();
$bookingFinancialSourceTypesSql = "'" . implode("','", array_map(static function ($value) use ($pdo) {
    return substr($pdo->quote($value), 1, -1);
}, $bookingFinancialSourceTypes)) . "'";

if ($pageBookingModule->isScoped()) {
    $_GET['service_type'] = $pageBookingModule->getServiceType();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_POST['service_type'] = $pageBookingModule->getServiceType();
    }
}


// Ensure $is_admin is defined (in case header.php didn't for some reason)
$user_role = $currentUser['role_name'] ?? $_SESSION['role'] ?? 'employee';
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

$auto_invoice_generation = isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true);
$base_currency = $pdo->query("SELECT * FROM currencies WHERE is_default = 1")->fetch();
$base_currency_symbol = $base_currency['currency_symbol'] ?? 'ر.ي';

// Check if the booking module is enabled
if ($forcedBookingServiceType === 'bus') {
    if (!get_module_status($pdo, 'enable_bus_bookings') && $_SESSION['role'] !== 'developer') {
        die("<div style='text-align:center; padding:50px; font-family:Tahoma;'><h3>عذراً، حجوزات الباصات معطلة حالياً من قبل الإدارة.</h3><a href='index.php'>العودة للرئيسية</a></div>");
    }
} elseif ($forcedBookingServiceType === 'flight') {
    if (!get_module_status($pdo, 'enable_flight_bookings') && $_SESSION['role'] !== 'developer') {
        die("<div style='text-align:center; padding:50px; font-family:Tahoma;'><h3>عذراً، حجوزات الطيران معطلة حالياً من قبل الإدارة.</h3><a href='index.php'>العودة للرئيسية</a></div>");
    }
} else {
    if (!get_module_status($pdo, 'enable_bus_bookings') && !get_module_status($pdo, 'enable_flight_bookings') && $_SESSION['role'] !== 'developer') {
        die("<div style='text-align:center; padding:50px; font-family:Tahoma;'><h3>عذراً، حجوزات الباصات والطيران معطلة حالياً من قبل الإدارة.</h3><a href='index.php'>العودة للرئيسية</a></div>");
    }
}

// معالجة إعادة تعيين الفواتير إلى مسودة (مثل invoices.php)
if (isset($_GET['reset_invoice']) || isset($_GET['unpost_invoice'])) {
    try {
        $id = isset($_GET['reset_invoice']) ? (int)$_GET['reset_invoice'] : (int)$_GET['unpost_invoice'];
        $type = $_GET['reset_type'] ?? 'sales'; // sales, purchase, all
        $user_id = $_SESSION['admin_id'];

        // جلب الإعدادات
        $settings = getSettings($pdo);

        // جلب بيانات الفاتورة
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['invoice_status'] != 'posted') {
            header('Location: ' . $bookingPageUrl . '?posted=1&error=not_posted');
            exit;
        }

        // البحث عن الفاتورة المرتبطة
        $s_pref = getServiceInvoiceConfig($row['source_type'], $settings)['sales_prefix'];
        $p_pref = getServiceInvoiceConfig($row['source_type'], $settings)['purchase_prefix'];

        $pur_id = null;
        $sal_id = null;
        if ($row['invoice_category'] == 'sales') {
            $linked_num = str_replace($s_pref, $p_pref, $row['invoice_number']);
            $stmt_linked = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND invoice_category = 'purchase' LIMIT 1");
            $stmt_linked->execute([$linked_num]);
            $pur_id = $stmt_linked->fetchColumn();
        } else {
            $linked_num = str_replace($p_pref, $s_pref, $row['invoice_number']);
            $stmt_linked = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND invoice_category = 'sales' LIMIT 1");
            $stmt_linked->execute([$linked_num]);
            $sal_id = $stmt_linked->fetchColumn();
        }

        // تحديد الفواتير المراد إعادة تعيينها
        $ids_to_reset = [];
        if ($type == 'all') {
            $ids_to_reset[] = $row['id'];
            if ($pur_id) $ids_to_reset[] = $pur_id;
            if ($sal_id) $ids_to_reset[] = $sal_id;
        } elseif ($type == 'purchase') {
            if ($row['invoice_category'] == 'purchase') $ids_to_reset[] = $row['id'];
            elseif ($pur_id) $ids_to_reset[] = $pur_id;
        } else { // sales
            if ($row['invoice_category'] == 'sales') $ids_to_reset[] = $row['id'];
            elseif ($sal_id) $ids_to_reset[] = $sal_id;
        }

        foreach ($ids_to_reset as $reset_id) {
            // حذف القيود المحاسبية المرتبطة بالفاتورة
            $pdo->prepare("DELETE FROM journal_lines WHERE invoice_id = ?")->execute([$reset_id]);

            // تحديث حالة الفاتورة إلى مسودة
            $pdo->prepare("UPDATE invoices SET invoice_status = 'draft', posted_by = NULL, posted_at = NULL WHERE id = ?")->execute([$reset_id]);

            // تسجيل السجل
        log_audit($pdo, 'reset_to_draft', 'invoices', $reset_id, ['status' => 'posted'], ['status' => 'draft'], "إعادة تعيين الفاتورة إلى مسودة");
        }

        $_SESSION['flash_message'] = [
            'type' => 'success',
            'title' => 'تم بنجاح!',
            'body' => 'تم إعادة تعيين الفاتورة إلى مسودة.'
        ];

        header('Location: ' . $bookingPageUrl . '?posted=1');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_message'] = [
            'type' => 'danger',
            'title' => 'خطأ!',
            'body' => 'خطأ في إعادة التعيين: ' . $e->getMessage()
        ];
        header('Location: ' . $bookingPageUrl . '?posted=1');
        exit;
    }
}

// Check permissions
if (!has_permission('bookings_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// إضافة عمود account_id إذا لم يكن موجوداً
try {
    $pdo->exec("ALTER TABLE bus_flight_bookings ADD COLUMN account_id INT NULL AFTER customer_id");
} catch (Exception $e) {
    // العمود موجود بالفعل
}

// Handle Add New Booking Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_booking'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ!', 'body' => 'خطأ في التحقق من الطلب (CSRF).'];
        header('Location: ' . $bookingPageUrl);
        exit();
    }
    $errors = [];

    // تعيين الفرع أولاً من النموذج أو الجلسة
    $branch_id = filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: ($currentUser['branch_id'] ?? $_SESSION['branch_id'] ?? null);

    // 1. Validate and Sanitize Input
    $traveler_name = filter_input(INPUT_POST, 'traveler_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $mobile_number = filter_input(INPUT_POST, 'mobile_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $date_of_birth = filter_input(INPUT_POST, 'date_of_birth');
    $place_of_birth = filter_input(INPUT_POST, 'place_of_birth', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $nationality_id = filter_input(INPUT_POST, 'nationality_id', FILTER_VALIDATE_INT) ?: NULL;
    $id_type = filter_input(INPUT_POST, 'id_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $id_number = filter_input(INPUT_POST, 'id_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $id_issue_place = filter_input(INPUT_POST, 'id_issue_place', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $id_issue_date = filter_input(INPUT_POST, 'id_issue_date');

    $booking_date = filter_input(INPUT_POST, 'booking_date');
    $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $submittedBookingModule = BookingServiceFactory::make($forcedBookingServiceType ?: $service_type);
    $normalizedBookingForm = $submittedBookingModule->normalizeFormData([
        'service_type' => $service_type,
        'bus_type' => filter_input(INPUT_POST, 'bus_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
    ]);
    $service_type = $normalizedBookingForm['service_type'];
    $bus_type = $normalizedBookingForm['bus_type'];
    $trip_type = filter_input(INPUT_POST, 'trip_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $from_city_id = filter_input(INPUT_POST, 'from_city_id', FILTER_VALIDATE_INT);
    $to_city_id = filter_input(INPUT_POST, 'to_city_id', FILTER_VALIDATE_INT);
    $departure_date = filter_input(INPUT_POST, 'departure_date');
    $return_date = filter_input(INPUT_POST, 'return_date'); // Optional
    $operation_date = filter_input(INPUT_POST, 'operation_date'); // تاريخ العملية
    $supplier_id = filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $sale_currency_id  = filter_input(INPUT_POST, 'sale_currency_id',  FILTER_VALIDATE_INT); // عملة البيع
    $currency_id       = filter_input(INPUT_POST, 'currency_id',        FILTER_VALIDATE_INT); // عملة الشراء/التكلفة
    $sale_price        = filter_input(INPUT_POST, 'sale_price',         FILTER_VALIDATE_FLOAT);
    $discount          = (float)($_POST['discount'] ?? 0); // الخصم
    $purchase_price    = filter_input(INPUT_POST, 'purchase_price',     FILTER_VALIDATE_FLOAT);
    $exchange_rate     = (float)($_POST['exchange_rate'] ?? 1); // سعر الصرف بين عملة الشراء وعملة البيع
    $amount_received   = filter_input(INPUT_POST, 'amount_received',    FILTER_VALIDATE_FLOAT);
    $delivery_type     = filter_input(INPUT_POST, 'delivery_type',      FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $account_id        = filter_input(INPUT_POST, 'account_id',         FILTER_VALIDATE_INT);
    $customer_id       = filter_input(INPUT_POST, 'customer_id',        FILTER_VALIDATE_INT);
    $agent_id          = filter_input(INPUT_POST, 'agent_id',           FILTER_VALIDATE_INT);
    $record_purchase   = filter_input(INPUT_POST, 'record_purchase',    FILTER_VALIDATE_INT);
    if (!$record_purchase && $record_purchase !== 0) $record_purchase = 1;
    if (!$operation_date) $operation_date = filter_input(INPUT_POST, 'invoice_date');
    if ($sale_price === null || $sale_price === false) $sale_price = filter_input(INPUT_POST, 'total_amount', FILTER_VALIDATE_FLOAT);
    if ($purchase_price === null || $purchase_price === false) $purchase_price = filter_input(INPUT_POST, 'cost_amount', FILTER_VALIDATE_FLOAT);
    if ($amount_received === null || $amount_received === false) $amount_received = filter_input(INPUT_POST, 'received_amount', FILTER_VALIDATE_FLOAT);
    // إذا لم تُرسل عملة البيع، نستخدم عملة الشراء كافتراضي
    if (!$sale_currency_id) $sale_currency_id = $currency_id;

    if (($delivery_type === 'credit') && empty($customer_id) && !empty($account_id)) {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE account_id = ? LIMIT 1");
        $stmt->execute([$account_id]);
        $customer_id = (int)($stmt->fetchColumn() ?: 0);
        if ($customer_id <= 0) {
            $stmtAcc = $pdo->prepare("SELECT account_name_ar, account_status FROM unified_accounts WHERE id = ? LIMIT 1");
            $stmtAcc->execute([$account_id]);
            $accRow = $stmtAcc->fetch(PDO::FETCH_ASSOC);
            if ($accRow) {
                $custStatus = (($accRow['account_status'] ?? '') === 'inactive' || ($accRow['account_status'] ?? '') === 'closed') ? 'inactive' : 'active';
                $stmtIns = $pdo->prepare("INSERT INTO customers (full_name, account_id, created_at, status) VALUES (?, ?, NOW(), ?)");
                $stmtIns->execute([($accRow['account_name_ar'] ?? ''), $account_id, $custStatus]);
                $customer_id = (int)$pdo->lastInsertId();
            }
        }
        if ($customer_id <= 0) {
            $customer_id = null;
        }
    }
    if (($delivery_type === 'agent') && empty($agent_id) && !empty($account_id)) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE account_id = ? LIMIT 1");
        $stmt->execute([$account_id]);
        $agent_id = (int)($stmt->fetchColumn() ?: 0);
        if ($agent_id <= 0) {
            $agent_id = null;
        }
    }
    $bookingValidator = new BookingValidator($pdo);
    $errors = $bookingValidator->validateCreate([
        'traveler_name' => $traveler_name,
        'mobile_number' => $mobile_number,
        'booking_date' => $booking_date,
        'operation_date' => $operation_date,
        'gender' => $gender,
        'service_type' => $service_type,
        'trip_type' => $trip_type,
        'from_city_id' => $from_city_id,
        'to_city_id' => $to_city_id,
        'departure_date' => $departure_date,
        'return_date' => $return_date,
        'supplier_id' => $supplier_id,
        'branch_id' => $branch_id,
        'customer_id' => $customer_id,
        'purchase_currency_id' => $currency_id,
        'sale_price' => $sale_price,
        'discount' => $discount,
        'purchase_price' => $purchase_price,
        'amount_received' => $amount_received,
        'delivery_type' => $delivery_type,
        'account_id' => $account_id,
    ]);

    if (empty($errors)) {
        try {
            $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $branch_id = $_POST['branch_id'] ?? $currentUser['branch_id'] ?? $_SESSION['branch_id'] ?? null;
            $form_agent_id = $agent_id ?: ($currentUser['agent_id'] ?? $_SESSION['agent_id'] ?? null);
            $bookingCreatorService = new BookingCreatorService(
                $pdo,
                (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 1),
                $submittedBookingModule
            );

            $creationResult = $bookingCreatorService->createBooking([
                'traveler_name' => $traveler_name,
                'mobile_number' => $mobile_number,
                'date_of_birth' => $date_of_birth,
                'place_of_birth' => $place_of_birth,
                'gender' => $gender,
                'nationality_id' => $nationality_id,
                'id_type' => $id_type,
                'id_number' => $id_number,
                'id_issue_place' => $id_issue_place,
                'id_issue_date' => $id_issue_date,
                'booking_date' => $booking_date,
                'service_type' => $service_type,
                'bus_type' => $bus_type,
                'trip_type' => $trip_type,
                'from_city_id' => $from_city_id,
                'to_city_id' => $to_city_id,
                'departure_date' => $departure_date,
                'return_date' => ($trip_type === 'round_trip' ? $return_date : null),
                'supplier_id' => $supplier_id,
                'customer_id' => $customer_id,
                'account_id' => $account_id,
                'notes' => $notes,
                'description' => $description,
                'branch_id' => $branch_id,
                'agent_id' => $form_agent_id,
                'operation_date' => $operation_date,
            ], [
                'sale_price' => $sale_price,
                'discount' => $discount,
                'purchase_price' => $purchase_price,
                'sale_currency_id' => $sale_currency_id,
                'purchase_currency_id' => $currency_id,
                'exchange_rate' => $exchange_rate,
                'amount_received' => $amount_received,
                'delivery_type' => $delivery_type,
            ]);

            $_SESSION['flash_message'] = [
                'type' => 'success',
                'title' => 'تم بنجاح!',
                'body' => 'تم إضافة الحجز الجديد بنجاح برقم: ' . $creationResult['booking_number']
            ];
            header('Location: ' . $bookingPageUrl);
            exit();
        } catch (Exception $e) {
            $_SESSION['flash_message'] = [
                'type' => 'danger',
                'title' => 'خطأ!',
                'body' => 'حدث خطأ أثناء إضافة الحجز: ' . $e->getMessage()
            ];
            header('Location: ' . $bookingPageUrl);
            exit();
        }
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'danger',
            'title' => 'خطأ في الإدخال!',
            'body' => implode('<br>', $errors)
        ];
        header('Location: ' . $bookingPageUrl);
        exit();
    }
}


// Handle Confirm/Cancel actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $booking_id = (int)$_GET['id'];
    $user_id = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 1);

    try {
        $bookingWorkflowService = new BookingWorkflowService($pdo, $user_id, $bookingWorkflowTransactionType);

        if ($action === 'confirm') {
            if (!has_permission('bookings_confirm')) throw new Exception('ليس لديك صلاحية لتأكيد الحجز');
            $bookingWorkflowService->handleQuickAction($booking_id, 'confirm');
            $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التأكيد', 'body' => 'تم تأكيد الحجز بنجاح'];
        } elseif ($action === 'cancel') {
            if (!has_permission('bookings_cancel')) throw new Exception('ليس لديك صلاحية لإلغاء الحجز');
            $bookingWorkflowService->handleQuickAction($booking_id, 'cancel');
            $_SESSION['flash_message'] = ['type' => 'warning', 'title' => 'تم الإلغاء', 'body' => 'تم إلغاء الحجز بنجاح'];
        }
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => $e->getMessage()];
    }
    header('Location: ' . $bookingPageUrl);
    exit();
}

// معالجة تغيير الحالة عبر سير العمل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_workflow_status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ!', 'body' => 'خطأ في التحقق من الطلب (CSRF).'];
        header('Location: ' . $bookingPageUrl);
        exit();
    }
    $booking_id = (int)$_POST['booking_id'];
    $to_status_id = (int)$_POST['to_status_id'];
    $notes = $_POST['workflow_notes'] ?? '';
    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
    $extra_fields = $_POST['extra_fields'] ?? [];
    $transition_id = $_POST['transition_id'] ?? null;

    if ($booking_id > 0 && $to_status_id > 0) {
        $bookingWorkflowService = new BookingWorkflowService($pdo, (int)$user_id, $bookingWorkflowTransactionType);
        if ($bookingWorkflowService->changeWorkflowStatus($booking_id, $to_status_id, $notes, $extra_fields, $transition_id)) {
            $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم نقل الحجز إلى المرحلة الجديدة بنجاح'];
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'فشل التحديث', 'body' => 'حدث خطأ أثناء محاولة تحديث الحالة'];
        }
    }
    header("Location: " . $bookingPageUrl);
    exit();
}

// Handle Request Approval (Cancellation/Modification)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_approval'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ!', 'body' => 'خطأ في التحقق من الطلب (CSRF).'];
        header('Location: ' . $bookingPageUrl);
        exit();
    }
    if (!has_permission('bookings_request_approval')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لإنشاء طلبات اعتماد'];
        header('Location: ' . $bookingPageUrl);
        exit();
    }
    $booking_id = (int)$_POST['booking_id'];
    $to_status_id = (int)$_POST['to_status_id'];
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $notes = $_POST['notes'] ?? '';
    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
    $role_id = $_SESSION['role_id'] ?? null;

    try {
        $bookingWorkflowService = new BookingWorkflowService($pdo, (int)$user_id, $bookingWorkflowTransactionType);
        $bookingWorkflowService->requestApproval($booking_id, $to_status_id, $discount_amount, $notes, $role_id);
        $_SESSION['flash_message'] = ['type' => 'info', 'title' => 'تم إرسال الطلب', 'body' => 'تم إرسال طلب الاعتماد للمدير بنجاح.'];
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => $e->getMessage()];
    }
    header('Location: ' . $bookingPageUrl);
    exit();
}

// Handle Update Booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking'])) {
    if (!has_permission('bookings_edit')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لتعديل الحجوزات'];
        header('Location: ' . $bookingPageUrl);
        exit();
    }
    $booking_id      = (int)$_POST['booking_id'];
    $traveler_name   = $_POST['traveler_name'];
    $mobile_number   = $_POST['mobile_number'];
    $gender          = $_POST['gender'] ?? null;
    $date_of_birth   = $_POST['date_of_birth'] ?? null;
    $place_of_birth  = $_POST['place_of_birth'] ?? null;
    $nationality_id  = isset($_POST['nationality_id']) ? (int)$_POST['nationality_id'] : null;
    $id_type         = $_POST['id_type'] ?? null;
    $id_number       = $_POST['id_number'] ?? null;
    $service_type    = $_POST['service_type'];
    $submittedBookingModule = BookingServiceFactory::make($forcedBookingServiceType ?: $service_type);
    $normalizedBookingForm = $submittedBookingModule->normalizeFormData([
        'service_type' => $service_type,
        'bus_type' => $_POST['bus_type'] ?? null,
    ]);
    $service_type = $normalizedBookingForm['service_type'];
    $bus_type = $normalizedBookingForm['bus_type'];
    $trip_type       = $_POST['trip_type'] ?? 'one_way';
    $from_city_id    = (int)$_POST['from_city_id'];
    $to_city_id      = (int)$_POST['to_city_id'];
    $description     = $_POST['description'];
    $booking_date    = $_POST['booking_date'];
    $departure_date  = $_POST['departure_date'];
    $return_date     = $_POST['return_date'] ?? null;
    $id_issue_place  = $_POST['id_issue_place'] ?? null;
    $id_issue_date   = $_POST['id_issue_date'] ?? null;
    $supplier_id     = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $notes           = $_POST['notes'];
    $branch_id       = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;

    // الحقول المالية
    $edit_operation_date   = $_POST['operation_date'] ?? ($_POST['invoice_date'] ?? null);
    $edit_sale_price       = isset($_POST['sale_price']) ? (float)$_POST['sale_price'] : (isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : null);
    $edit_discount         = isset($_POST['discount'])         ? (float)$_POST['discount']         : 0;
    $edit_purchase_price   = isset($_POST['purchase_price']) ? (float)$_POST['purchase_price'] : (isset($_POST['cost_amount']) ? (float)$_POST['cost_amount'] : null);
    $edit_sale_currency_id = isset($_POST['sale_currency_id']) ? (int)$_POST['sale_currency_id']   : null;
    $edit_currency_id      = isset($_POST['currency_id'])      ? (int)$_POST['currency_id']        : null;
    $edit_exchange_rate    = isset($_POST['exchange_rate'])    ? (float)$_POST['exchange_rate']    : 1;
    $edit_delivery_type    = $_POST['delivery_type'] ?? null;
    $edit_customer_id      = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $edit_agent_id         = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
    $edit_account_id       = isset($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $edit_amount_received  = isset($_POST['amount_received']) ? (float)$_POST['amount_received'] : (isset($_POST['received_amount']) ? (float)$_POST['received_amount'] : null);

    if (($edit_delivery_type === 'credit') && empty($edit_customer_id) && !empty($edit_account_id)) {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE account_id = ? LIMIT 1");
        $stmt->execute([$edit_account_id]);
        $edit_customer_id = (int)($stmt->fetchColumn() ?: 0);
        if ($edit_customer_id <= 0) {
            $edit_customer_id = null;
        }
    }
    if (($edit_delivery_type === 'agent') && empty($edit_agent_id) && !empty($edit_account_id)) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE account_id = ? LIMIT 1");
        $stmt->execute([$edit_account_id]);
        $edit_agent_id = (int)($stmt->fetchColumn() ?: 0);
        if ($edit_agent_id <= 0) {
            $edit_agent_id = null;
        }
    }

    $bookingValidator = new BookingValidator($pdo);
    $errors = $bookingValidator->validateUpdate([
        'booking_date' => $booking_date,
        'date_of_birth' => $date_of_birth,
        'id_issue_date' => $id_issue_date,
        'departure_date' => $departure_date,
        'from_city_id' => $from_city_id,
        'to_city_id' => $to_city_id,
    ]);
    if (!empty($errors)) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ في الإدخال!', 'body' => implode('<br>', $errors)];
        header('Location: ' . $bookingPageUrl);
        exit();
    }

    try {
        $bookingFinancialUpdater = new BookingFinancialUpdater(
            $pdo,
            (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 1)
        );

        $bookingFinancialUpdater->updateBookingAndFinancials($booking_id, [
            'traveler_name' => $traveler_name,
            'mobile_number' => $mobile_number,
            'gender' => $gender,
            'date_of_birth' => $date_of_birth,
            'place_of_birth' => $place_of_birth,
            'nationality_id' => $nationality_id,
            'id_type' => $id_type,
            'id_number' => $id_number,
            'service_type' => $service_type,
            'bus_type' => $bus_type,
            'trip_type' => $trip_type,
            'from_city_id' => $from_city_id,
            'to_city_id' => $to_city_id,
            'supplier_id' => $supplier_id,
            'description' => $description,
            'booking_date' => $booking_date,
            'departure_date' => $departure_date,
            'return_date' => ($trip_type === 'round_trip' ? $return_date : null),
            'id_issue_place' => $id_issue_place,
            'id_issue_date' => $id_issue_date,
            'notes' => $notes,
            'branch_id' => $branch_id,
            'customer_id' => $edit_customer_id,
            'account_id' => $edit_account_id,
            'operation_date' => $edit_operation_date,
            'agent_id' => $edit_agent_id,
        ], [
            'sale_price' => $edit_sale_price,
            'discount' => $edit_discount,
            'purchase_price' => $edit_purchase_price,
            'sale_currency_id' => $edit_sale_currency_id,
            'purchase_currency_id' => $edit_currency_id,
            'exchange_rate' => $edit_exchange_rate,
            'delivery_type' => $edit_delivery_type,
            'customer_id' => $edit_customer_id,
            'account_id' => $edit_account_id,
            'agent_id' => $edit_agent_id,
            'amount_received' => $edit_amount_received,
            'operation_date' => $edit_operation_date,
            'description' => $description,
            'source_type' => $submittedBookingModule->getFinanceSourceType(),
        ]);

        $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم تحديث بيانات الحجز والفواتير المرتبطة بنجاح'];
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => $e->getMessage()];
    }
    header('Location: ' . $bookingPageUrl);
    exit();
}

// Fetch filter data
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name FROM currencies ORDER BY currency_name ASC")->fetchAll();
$booking_statuses = $pdo->query("SELECT id, status_name FROM statuses WHERE status_name IN ('حجز جديد', 'مؤكد', 'ملغي', 'معدل') ORDER BY status_name ASC")->fetchAll();
$customers = $pdo->query("SELECT id, full_name FROM customers ORDER BY full_name ASC")->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name ASC")->fetchAll();
// جلب الموردين مع أكواد حساباتهم مثل invoices.php
$parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$parent_stmt_suppliers->execute();
$suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

$suppliers_stmt = $pdo->prepare("
    SELECT coa.*,
           (SELECT id FROM suppliers WHERE account_id = coa.id LIMIT 1) as supplier_id
    FROM unified_accounts coa
    WHERE coa.parent_id = ? AND coa.account_status = 'active'
    ORDER BY coa.account_code ASC
");
$suppliers_stmt->execute([$suppliers_parent_id]);
$suppliers_with_codes = [];
while ($row = $suppliers_stmt->fetch()) {
    $row['display_name'] = $row['account_code'] . ' - ' . $row['account_name_ar'];
    $suppliers_with_codes[] = $row;
}

$suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name ASC")->fetchAll();
$countries = $pdo->query("SELECT id, country_name FROM countries ORDER BY country_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll();
// جلب الكيانات مع حساباتها (مثل invoices.php)
$customers_entities = $pdo->query("
    SELECT c.id as id, c.account_id as account_id, c.full_name as name, ua.account_code
    FROM customers c
    JOIN unified_accounts ua ON c.account_id = ua.id
    WHERE c.status = 'active' AND c.deleted_at IS NULL
    ORDER BY c.full_name ASC
")->fetchAll();

$agents_entities = $pdo->query("
    SELECT a.id, a.agent_name as name, a.account_id as account_id, acc.account_code
    FROM agents a
    JOIN unified_accounts acc ON a.account_id = acc.id
    WHERE a.status = 'active' AND a.deleted_at IS NULL
    ORDER BY a.agent_name ASC
")->fetchAll();

$cashboxes_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '101%' AND account_code != '101' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$cash_accounts = $cashboxes_entities; // توحيد المسمى مع invoices.php

$banks_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '102%' AND account_code != '102' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$bank_accounts = $banks_entities; // توحيد المسمى مع invoices.php

// جميع الحسابات الموحدة (للإختيار عند نوع التوصيل 'آجل')
$all_unified_accounts = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_status = 'active' 
    ORDER BY account_code ASC
")->fetchAll();


// Build dynamic query for bookings
$where_clauses = [];
$params = [];

// Apply user-specific filtering (scope of visibility)
$session_user_type = $_SESSION['user_type'] ?? '';
if ($session_user_type === 'agent' && isset($_SESSION['agent_id'])) {
    $where_clauses[] = "b.agent_id = ?";
    $params[] = $_SESSION['agent_id'];
} elseif ($session_user_type === 'branch' && isset($_SESSION['branch_id'])) {
    $where_clauses[] = "b.branch_id = ?";
    $params[] = $_SESSION['branch_id'];
}
// Add more scope filtering as per requirements (e.g., employee)

if (isset($_GET['from_date']) && !empty($_GET['from_date'])) {
    $where_clauses[] = "b.booking_date >= ?";
    $params[] = $_GET['from_date'];
}
if (isset($_GET['to_date']) && !empty($_GET['to_date'])) {
    $where_clauses[] = "b.booking_date <= ?";
    $params[] = $_GET['to_date'];
}
if (isset($_GET['service_type']) && !empty($_GET['service_type'])) {
    $where_clauses[] = "b.service_type = ?";
    $params[] = $_GET['service_type'];
}
if (isset($_GET['status_id']) && !empty($_GET['status_id'])) {
    $where_clauses[] = "b.status_id = ?";
    $params[] = $_GET['status_id'];
}
if (isset($_GET['from_city_id']) && !empty($_GET['from_city_id'])) {
    $where_clauses[] = "b.from_city_id = ?";
    $params[] = $_GET['from_city_id'];
}
if (isset($_GET['to_city_id']) && !empty($_GET['to_city_id'])) {
    $where_clauses[] = "b.to_city_id = ?";
    $params[] = $_GET['to_city_id'];
}
if (isset($_GET['supplier_id']) && !empty($_GET['supplier_id'])) {
    $where_clauses[] = "b.supplier_id = ?";
    $params[] = $_GET['supplier_id'];
}
if (isset($_GET['currency_id']) && !empty($_GET['currency_id'])) {
    $where_clauses[] = "inv.currency_id = ?";
    $params[] = $_GET['currency_id'];
}
if (isset($_GET['payment_type']) && !empty($_GET['payment_type'])) {
    $where_clauses[] = "inv.delivery_type = ?";
    $params[] = $_GET['payment_type'];
}
if (isset($_GET['created_by_user_id']) && !empty($_GET['created_by_user_id'])) {
    $where_clauses[] = "b.created_by = ?";
    $params[] = $_GET['created_by_user_id'];
}
if (isset($_GET['branch_id']) && !empty($_GET['branch_id'])) {
    $where_clauses[] = "b.branch_id = ?";
    $params[] = $_GET['branch_id'];
}
if (isset($_GET['agent_id']) && !empty($_GET['agent_id'])) {
    $where_clauses[] = "b.agent_id = ?";
    $params[] = $_GET['agent_id'];
}

// حقل البحث الديناميكي (رقم الحجز، اسم المسافر، رقم الجوال)
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where_clauses[] = "(b.booking_number LIKE ? OR b.traveler_name LIKE ? OR b.mobile_number LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$query = "
    SELECT
        b.*,
        COALESCE(inv.total_amount, 0) AS sale_price,
        COALESCE(inv_p.total_amount, 0) AS purchase_price,

        -- حساب المبلغ المحصل (البيع) - نفس منطق invoices.php
        (
            IFNULL((
                SELECT SUM(jl.debit)
                FROM journal_lines jl
                JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                AND jl.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
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
        ) AS amount_received,

        -- حساب المبلغ المسدد للمورد (الشراء) - نفس منطق invoices.php
        (
            IFNULL((
                SELECT SUM(jl_p.credit)
                FROM journal_lines jl_p
                JOIN financial_transactions ft_ip ON jl_p.financial_transaction_id = ft_ip.id
                WHERE ft_ip.reference_id = inv_p.id AND ft_ip.reference_type = 'invoice' AND ft_ip.status = 'posted'
                AND jl_p.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
                )
            ), 0) +
            IFNULL((
                SELECT SUM(pa_p.allocated_amount)
                FROM payment_allocations pa_p
                JOIN financial_transactions ft_p ON pa_p.financial_transaction_id = ft_p.id
                WHERE pa_p.invoice_id = inv_p.id AND ft_p.status = 'posted'
                AND ft_p.id NOT IN (
                    SELECT id FROM financial_transactions
                    WHERE reference_id = inv_p.id AND reference_type = 'invoice'
                )
            ), 0)
        ) AS purchase_received,

        ((COALESCE(inv.total_amount, 0) - COALESCE(inv.discount, 0)) - (
            IFNULL((
                SELECT SUM(jl.debit)
                FROM journal_lines jl
                JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                AND jl.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
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
        )) AS remaining_amount,

        -- حساب الربح بناءً على فرق البيع والتكلفة المخزنة في فاتورة البيع
        (IFNULL(inv.total_amount, 0) - IFNULL(inv.discount, 0) - IFNULL(inv.cost_amount, 0)) AS profit,
        b.customer_id,
        b.agent_id,
        b.account_id,
        inv.delivery_type,


        COALESCE(inv.delivery_type, 'cash') AS payment_type,
        COALESCE(inv.currency_id, 1) AS currency_id,
        c_from.city_name AS from_city_name,
        c_to.city_name AS to_city_name,
        curr.currency_name,
        curr.currency_symbol,
        bs.status_name AS booking_status_name,
        bs.status_color AS booking_status_color,
        cust.full_name AS customer_full_name,
        ua.account_name_ar AS account_name,
        ua.account_code AS account_code,
        ua_inv.account_name_ar AS invoice_account_name,
        ua_inv.account_code AS invoice_account_code,
        u.full_name AS created_by_user_full_name,
        s.supplier_name,
        inv.id AS sales_invoice_id, inv.invoice_status AS sales_status, inv.invoice_number AS sales_invoice_number,
        inv_p.id AS purchase_invoice_id, inv_p.invoice_status AS purchase_status, inv_p.invoice_number AS purchase_invoice_number
    FROM bus_flight_bookings b
    LEFT JOIN cities c_from ON b.from_city_id = c_from.id
    LEFT JOIN cities c_to ON b.to_city_id = c_to.id
    LEFT JOIN invoices inv ON (
        inv.id = b.sales_invoice_id 
        OR inv.id = b.invoice_id 
        OR (inv.source_type IN ($bookingFinancialSourceTypesSql) AND inv.source_id = b.id AND inv.invoice_category = 'sales')
    )
    LEFT JOIN invoices inv_p ON (
        inv_p.id = b.purchase_invoice_id 
        OR (inv_p.source_type IN ($bookingFinancialSourceTypesSql) AND inv_p.source_id = b.id AND inv_p.invoice_category = 'purchase')
    )
    LEFT JOIN currencies curr ON COALESCE(inv.currency_id, 1) = curr.id
    LEFT JOIN statuses bs ON b.status_id = bs.id
    LEFT JOIN customers cust ON b.customer_id = cust.id
    LEFT JOIN unified_accounts ua ON b.account_id = ua.id
    LEFT JOIN unified_accounts ua_inv ON inv.account_id = ua_inv.id
    LEFT JOIN users u ON b.created_by = u.id
    LEFT JOIN suppliers s ON b.supplier_id = s.id
";

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

$query .= " ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// فحص صلاحية مشاهدة كافة البيانات
if (!has_permission('bookings_view_all') && !$is_admin) {
    // إذا لم تكن لديه الصلاحية وليس مديراً، يرى فقط الحجوزات التي أنشأها بنفسه
    $filtered_bookings = [];
    foreach ($bookings as $b) {
        if ($b['created_by'] == $_SESSION['user_id']) {
            $filtered_bookings[] = $b;
        }
    }
    $bookings = $filtered_bookings;
}

// جلب معرفات الحجوزات التي لها طلبات اعتماد معلقة
$pending_bookings_ids = $pdo->query("SELECT booking_id FROM workflow_approval_requests WHERE status = 'pending' AND booking_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// Flash message display (if any) - assuming it's handled in header.php or a common place
if (isset($_SESSION['flash_message'])) {
    $msg = $_SESSION['flash_message'];
    $msgType = in_array(($msg['type'] ?? ''), ['success', 'info', 'warning', 'danger'], true) ? $msg['type'] : 'info';
    $msgIconMap = [
        'success' => 'fa-circle-check',
        'info' => 'fa-circle-info',
        'warning' => 'fa-triangle-exclamation',
        'danger' => 'fa-circle-xmark',
    ];
    $msgTitle = htmlspecialchars((string)($msg['title'] ?? 'تنبيه'));
    $msgBody = nl2br(htmlspecialchars((string)($msg['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
    echo sprintf(
        '<div class="booking-alert-stack mb-4">
            <div class="booking-page-alert booking-page-alert-%s alert alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
                <div class="booking-page-alert-icon">
                    <i class="fas %s"></i>
                </div>
                <div class="booking-page-alert-content">
                    <div class="booking-page-alert-title">%s</div>
                    <div class="booking-page-alert-text">%s</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>',
        $msgType,
        $msgIconMap[$msgType] ?? 'fa-circle-info',
        $msgTitle,
        $msgBody
    );
    unset($_SESSION['flash_message']);
}
?>

<style>
    .clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .finance-mini-card {
        border-radius: 18px;
        padding: 0.75rem 0.85rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(248, 250, 252, 0.86);
        min-width: 165px;
    }

    body.theme-dark .finance-mini-card {
        background: rgba(30, 41, 59, 0.85);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .finance-mini-card .mini-label {
        font-size: 0.72rem;
        color: var(--text-muted);
        margin-bottom: 0.18rem;
    }

    .finance-mini-card .mini-name {
        font-size: 0.9rem;
        font-weight: 800;
        color: var(--text-bold);
        line-height: 1.5;
        margin-bottom: 0.45rem;
    }

    .finance-mini-card .mini-amount {
        font-size: 1.02rem;
        font-weight: 900;
        color: #2563eb;
    }

    .route-stack {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        gap: 0.3rem;
        min-width: 120px;
        padding: 0.5rem 0.75rem;
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(248, 250, 252, 0.72);
    }

    body.theme-dark .route-stack {
        background: rgba(30, 41, 59, 0.78);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .route-city {
        font-size: 0.82rem;
        line-height: 1.45;
        text-align: center;
    }

    .route-city.from {
        color: var(--text-muted);
        font-weight: 600;
    }

    .route-city.to {
        color: var(--text-bold);
        font-weight: 800;
    }

    .route-arrow {
        color: #2563eb;
        opacity: 0.7;
        font-size: 0.95rem;
        line-height: 1;
    }

    .payment-stack {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }

    .payment-box {
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 0.75rem;
        padding: 0.5rem;
        background: rgba(255, 255, 255, 0.7);
    }

    body.theme-dark .payment-box {
        background: rgba(30, 41, 59, 0.78);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .payment-box-title {
        font-size: 0.75rem;
        font-weight: 800;
        margin-bottom: 0.35rem;
    }

    .amount-summary-card {
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 0.75rem;
        padding: 0.65rem 0.75rem;
        background: rgba(255, 255, 255, 0.7);
        min-width: 150px;
    }

    body.theme-dark .amount-summary-card {
        background: rgba(30, 41, 59, 0.78);
        border-color: rgba(148, 163, 184, 0.12);
    }

    .booking-alert-stack {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
    }

    .booking-page-alert {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem 3.25rem 1rem 1rem;
        overflow: hidden;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid transparent;
    }

    .booking-page-alert::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: currentColor;
        opacity: 0.9;
    }

    .booking-page-alert .btn-close {
        position: absolute;
        top: 1rem;
        left: 1rem;
        opacity: 0.75;
    }

    .booking-page-alert-icon {
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1.05rem;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.35);
    }

    .booking-page-alert-content {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        min-width: 0;
    }

    .booking-page-alert-title {
        font-weight: 800;
        font-size: 0.98rem;
        line-height: 1.35;
    }

    .booking-page-alert-text {
        font-size: 0.88rem;
        line-height: 1.65;
        opacity: 0.92;
    }

    .booking-page-alert-success {
        color: #166534;
        background: linear-gradient(135deg, rgba(236, 253, 245, 0.98) 0%, rgba(220, 252, 231, 0.95) 100%);
        border-color: rgba(34, 197, 94, 0.18);
    }

    .booking-page-alert-success .booking-page-alert-icon {
        color: #15803d;
        background: rgba(34, 197, 94, 0.14);
    }

    .booking-page-alert-info {
        color: #1d4ed8;
        background: linear-gradient(135deg, rgba(239, 246, 255, 0.98) 0%, rgba(219, 234, 254, 0.95) 100%);
        border-color: rgba(59, 130, 246, 0.2);
    }

    .booking-page-alert-info .booking-page-alert-icon {
        color: #2563eb;
        background: rgba(59, 130, 246, 0.14);
    }

    .booking-page-alert-warning {
        color: #9a3412;
        background: linear-gradient(135deg, rgba(255, 247, 237, 0.98) 0%, rgba(255, 237, 213, 0.96) 100%);
        border-color: rgba(249, 115, 22, 0.2);
    }

    .booking-page-alert-warning .booking-page-alert-icon {
        color: #ea580c;
        background: rgba(249, 115, 22, 0.14);
    }

    .booking-page-alert-danger {
        color: #991b1b;
        background: linear-gradient(135deg, rgba(254, 242, 242, 0.98) 0%, rgba(254, 226, 226, 0.95) 100%);
        border-color: rgba(239, 68, 68, 0.18);
    }

    .booking-page-alert-danger .booking-page-alert-icon {
        color: #dc2626;
        background: rgba(239, 68, 68, 0.14);
    }

    body.theme-dark .booking-page-alert,
    body.dark-mode .booking-page-alert {
        box-shadow: 0 18px 40px rgba(2, 6, 23, 0.28), inset 0 1px 0 rgba(255,255,255,0.03);
    }

    body.theme-dark .booking-page-alert .btn-close,
    body.dark-mode .booking-page-alert .btn-close {
        filter: invert(1) grayscale(1);
    }

    body.theme-dark .booking-page-alert-success,
    body.dark-mode .booking-page-alert-success {
        color: #bbf7d0;
        background: linear-gradient(135deg, rgba(20, 83, 45, 0.88) 0%, rgba(21, 128, 61, 0.18) 100%);
        border-color: rgba(34, 197, 94, 0.2);
    }

    body.theme-dark .booking-page-alert-success .booking-page-alert-icon,
    body.dark-mode .booking-page-alert-success .booking-page-alert-icon {
        color: #86efac;
        background: rgba(34, 197, 94, 0.16);
    }

    body.theme-dark .booking-page-alert-info,
    body.dark-mode .booking-page-alert-info {
        color: #bfdbfe;
        background: linear-gradient(135deg, rgba(30, 64, 175, 0.28) 0%, rgba(15, 23, 42, 0.92) 100%);
        border-color: rgba(96, 165, 250, 0.18);
    }

    body.theme-dark .booking-page-alert-info .booking-page-alert-icon,
    body.dark-mode .booking-page-alert-info .booking-page-alert-icon {
        color: #93c5fd;
        background: rgba(59, 130, 246, 0.18);
    }

    body.theme-dark .booking-page-alert-warning,
    body.dark-mode .booking-page-alert-warning {
        color: #fdba74;
        background: linear-gradient(135deg, rgba(124, 45, 18, 0.4) 0%, rgba(15, 23, 42, 0.92) 100%);
        border-color: rgba(251, 146, 60, 0.2);
    }

    body.theme-dark .booking-page-alert-warning .booking-page-alert-icon,
    body.dark-mode .booking-page-alert-warning .booking-page-alert-icon {
        color: #fb923c;
        background: rgba(249, 115, 22, 0.18);
    }

    body.theme-dark .booking-page-alert-danger,
    body.dark-mode .booking-page-alert-danger {
        color: #fecaca;
        background: linear-gradient(135deg, rgba(127, 29, 29, 0.42) 0%, rgba(15, 23, 42, 0.92) 100%);
        border-color: rgba(248, 113, 113, 0.18);
    }

    body.theme-dark .booking-page-alert-danger .booking-page-alert-icon,
    body.dark-mode .booking-page-alert-danger .booking-page-alert-icon {
        color: #fca5a5;
        background: rgba(239, 68, 68, 0.18);
    }

    .amount-summary-card .mini-label {
        font-size: 0.72rem;
        color: var(--text-muted);
        margin-bottom: 0.2rem;
    }

    .booking-form-dialog {
        width: min(1200px, calc(100vw - 2rem));
        max-width: 1200px;
    }

    .booking-form-content {
        max-height: 92vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .booking-form-body {
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .booking-form-body .row {
        align-items: start;
    }

    .booking-form-body h5 {
        font-size: 0.875rem;
        padding-bottom: 0.3rem;
        margin-bottom: 0.5rem;
        border-bottom: 1px solid rgba(13, 110, 253, .14);
    }

    .booking-form-body .form-label {
        min-height: 1rem;
        font-size: 0.8rem;
        margin-bottom: 0.2rem;
    }

    .booking-form-body .form-control,
    .booking-form-body .form-select,
    .booking-form-body .select2-container .select2-selection--single {
        min-height: 32px;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }

    .booking-form-body textarea.form-control {
        min-height: 50px;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }

    .booking-form-footer {
        position: sticky;
        bottom: 0;
        z-index: 2;
        gap: 0.5rem;
        padding: 0.5rem 1rem !important;
    }

    @media (min-width: 992px) {
        .booking-form-body {
            padding: 0.75rem 1rem !important;
        }

        .booking-form-body .row {
            row-gap: 0.5rem !important;
        }
    }

    @media (max-width: 575.98px) {
        .booking-form-dialog {
            width: 100%;
            max-width: 100%;
        }

        .booking-form-content {
            height: 100%;
            max-height: 100%;
            border-radius: 0 !important;
        }

        .booking-form-body {
            padding: 0.5rem !important;
        }

        .booking-form-footer {
            padding: 0.5rem !important;
            flex-direction: column-reverse;
            align-items: stretch;
        }

        .booking-form-footer .btn {
            width: 100%;
        }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="<?php echo h($pageBookingModule->getPageIcon()); ?> me-2"></i> <?php echo h($pageBookingModule->getPageTitle()); ?></h3>
            <p class="text-muted small mb-0"><?php echo h($pageBookingModule->getPageDescription()); ?></p>
        </div>
        <?php if (has_permission('bookings_create')): ?>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addBookingModal">
                <i class="fas fa-plus me-1"></i> إضافة حجز جديد
            </button>
        <?php endif; ?>
    </div>

    <!-- Filters Form -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
            <form method="GET" action="<?php echo h($bookingPageUrl); ?>" id="filterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="search" class="form-label small fw-bold text-primary"><i class="fas fa-search me-1"></i> بحث شامل (اسم، جوال، رقم حجز)</label>
                        <input type="text" class="form-control rounded-3" id="search" name="search" placeholder="ابحث هنا..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="from_date" class="form-label small text-muted">من تاريخ</label>
                        <input type="date" class="form-control rounded-3" id="from_date" name="from_date" value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="to_date" class="form-label small text-muted">إلى تاريخ</label>
                        <input type="date" class="form-control rounded-3" id="to_date" name="to_date" value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="status_id" class="form-label small text-muted">الحالة</label>
                        <select class="form-select rounded-3" id="status_id" name="status_id">
                            <option value="">الكل</option>
                            <?php foreach ($booking_statuses as $status): ?>
                                <option value="<?php echo $status['id']; ?>" <?php echo (h($_GET['status_id'] ?? '') == $status['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status['status_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary rounded-3 w-100 shadow-sm"><i class="fas fa-filter me-1"></i> تصفية</button>
                        <a href="<?php echo h($bookingPageUrl); ?>" class="btn btn-light rounded-3 border" title="إعادة تعيين"><i class="fas fa-redo"></i></a>
                    </div>
                </div>

                <!-- خيارات إضافية مخفية -->
                <div class="collapse mt-3" id="moreFilters">
                    <div class="row g-3">
                        <?php if (!$pageBookingModule->isScoped()): ?>
                            <div class="col-md-3">
                                <label for="service_type" class="form-label small text-muted">نوع الخدمة</label>
                                <select class="form-select rounded-3" id="service_type" name="service_type">
                                    <option value="">الكل</option>
                                    <?php foreach ($allowedBookingServiceTypes as $serviceTypeValue => $serviceTypeLabel): ?>
                                        <option value="<?php echo h($serviceTypeValue); ?>" <?php echo (($_GET['service_type'] ?? '') == $serviceTypeValue) ? 'selected' : ''; ?>><?php echo h($serviceTypeLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="service_type" value="<?php echo h($pageBookingModule->getServiceType()); ?>">
                            <div class="col-md-3">
                                <label class="form-label small text-muted">الخدمة الحالية</label>
                                <div class="form-control rounded-3 bg-light text-primary fw-bold"><?php echo h($pageBookingModule->getPageTitle()); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="col-md-3">
                            <label for="supplier_id" class="form-label small text-muted">المورد (جهة التكلفة)</label>
                            <select class="form-select rounded-3" id="supplier_id" name="supplier_id">
                                <option value="">الكل</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>" <?php echo (($_GET['supplier_id'] ?? '') == $supplier['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="payment_type" class="form-label small text-muted">نوع التوصيل</label>
                            <select class="form-select rounded-3" id="payment_type" name="payment_type">
                                <option value="">الكل</option>
                                <option value="cash" <?php echo (($_GET['payment_type'] ?? '') == 'cash') ? 'selected' : ''; ?>>نقد</option>
                                <option value="credit" <?php echo (($_GET['payment_type'] ?? '') == 'credit') ? 'selected' : ''; ?>>أجل</option>
                                <option value="bank_transfer" <?php echo (($_GET['payment_type'] ?? '') == 'bank_transfer') ? 'selected' : ''; ?>>تحويل بنكي</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-2">
                    <button type="button" class="btn btn-link btn-sm text-decoration-none" data-bs-toggle="collapse" data-bs-target="#moreFilters">
                        <i class="fas fa-chevron-down me-1"></i> خيارات بحث إضافية
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3 border-0 text-secondary small text-uppercase fw-bold">رقم الفاتورة</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">التاريخ</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">خط السير</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">المورد والشراء</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">الحساب والبيع</th>
                            <th class="text-end border-0 text-secondary small text-uppercase fw-bold">المستلم</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">حالة الدفع والترحيل</th>
                            <th class="text-center border-0 text-secondary small text-uppercase fw-bold">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">لا توجد حجوزات لعرضها حالياً.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $workflows_cache = [];
                            foreach ($bookings as $booking):
                                // جلب سير العمل (مع التخزين المؤقت للفرع)
                                $bid = $booking['branch_id'] ?: 0;
                                if (!isset($workflows_cache[$bid])) {
                                    $workflows_cache[$bid] = get_workflow_for_transaction($bookingWorkflowTransactionType, $booking['branch_id']);
                                }
                                $workflow = $workflows_cache[$bid];
                                $allowed_transitions = [];
                                $current_step_id = null;

                                if ($workflow) {
                                    // جلب الخطوة الحالية بناءً على الحالة
                                    $stmt_curr = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ? LIMIT 1");
                                    $stmt_curr->execute([$workflow['id'], $booking['status_id']]);
                                    $current_step_id = $stmt_curr->fetchColumn();

                                    if ($current_step_id) {
                                        $allowed_transitions = get_allowed_transitions($workflow['id'], $current_step_id, $_SESSION['role_id'] ?? null, $_SESSION['user_id'] ?? null);
                                    }
                                }
                            ?>
                                <tr>
                                    <!-- رقم الفاتورة -->
                                    <td class="px-4 py-3 fw-bold small text-primary">
                                        <?php if ($booking['sales_invoice_number'] || $booking['purchase_invoice_number']): ?>
                                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                                <?php if ($booking['sales_invoice_number']): ?>
                                                    <span class="badge bg-primary-subtle text-primary rounded-pill px-2 py-1"><?php echo htmlspecialchars($booking['sales_invoice_number']); ?></span>
                                                <?php endif; ?>
                                                <?php if ($booking['purchase_invoice_number']): ?>
                                                    <span class="badge bg-warning-subtle text-warning rounded-pill px-2 py-1"><?php echo htmlspecialchars($booking['purchase_invoice_number']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">---</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- التاريخ -->
                                    <td class="small"><?php echo htmlspecialchars($booking['booking_date']); ?></td>
                                    <!-- خط السير -->
                                    <td class="small">
                                        <?php
                                        $fromCity = trim((string)($booking['from_city_name'] ?? ''));
                                        $toCity = trim((string)($booking['to_city_name'] ?? ''));
                                        ?>
                                        <?php if ($fromCity === '' && $toCity === ''): ?>
                                            <span class="text-muted small">---</span>
                                        <?php else: ?>
                                            <div class="route-stack">
                                                <div class="route-city from"><?php echo htmlspecialchars($fromCity !== '' ? $fromCity : '---'); ?></div>
                                                <div class="route-arrow"><i class="fas fa-arrow-down"></i></div>
                                                <div class="route-city to"><?php echo htmlspecialchars($toCity !== '' ? $toCity : '---'); ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mt-1">
                                            <?php if ($pageBookingModule->isBusService($booking['service_type'])): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small"><i class="fas fa-bus me-1"></i> باص</span>
                                            <?php else: ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill small"><i class="fas fa-plane me-1"></i> طيران</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php $currencyMark = trim((string)(($booking['currency_symbol'] ?? '') ?: $base_currency_symbol)); ?>
                                    <td class="small">
                                        <div class="finance-mini-card">
                                            <div class="mini-label">المورد</div>
                                            <div class="mini-name clamp-2"><?php echo htmlspecialchars($booking['supplier_name'] ?: '---'); ?></div>
                                            <div class="mini-label">سعر الشراء</div>
                                            <div class="mini-amount" style="color:#dc2626;">
                                                <?php
                                                $purchaseAmount = (float)($booking['purchase_price'] ?? 0);
                                                echo number_format($purchaseAmount, 2);
                                                echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : '';
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="small">
                                        <?php
                                        $invAccountCode = trim((string)($booking['invoice_account_code'] ?? ''));
                                        $invAccountName = trim((string)($booking['invoice_account_name'] ?? ''));
                                        $invAccountDisplay = $invAccountName !== '' ? $invAccountName : (trim((string)($booking['account_name'] ?? '')) !== '' ? trim((string)($booking['account_name'] ?? '')) : '---');
                                        ?>
                                        <div class="finance-mini-card">
                                            <div class="mini-label">الحساب المتأثر</div>
                                            <div class="mini-name clamp-2"><?php echo htmlspecialchars($invAccountDisplay); ?></div>
                                            <div class="mini-label">سعر البيع</div>
                                            <div class="mini-amount" style="color:#16a34a;">
                                                <?php
                                                $saleNetAmountInline = (float)($booking['sale_price'] ?? 0) - (float)($booking['discount'] ?? 0);
                                                echo number_format($saleNetAmountInline, 2);
                                                echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : '';
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                    <?php $net_amount = $booking['sale_price'] - ($booking['discount'] ?? 0); ?>
                                    <!-- المستلم + المتبقي -->
                                    <td class="small">
                                        <?php $remaining = $net_amount - $booking['amount_received']; ?>
                                        <div class="amount-summary-card text-end">
                                            <div class="mini-label">المستلم</div>
                                            <div class="fw-bold text-success"><?php echo number_format((float)$booking['amount_received'], 2); ?><?php echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : ''; ?></div>
                                            <div class="mini-label mt-2">المتبقي</div>
                                            <div class="fw-bold text-danger"><?php echo number_format((float)$remaining, 2); ?><?php echo $currencyMark !== '' ? ' ' . htmlspecialchars($currencyMark) : ''; ?></div>
                                        </div>
                                    </td>
                                    <!-- حالة الدفع والترحيل -->
                                    <td class="small text-start">
                                        <?php
                                        $pay_badges = [
                                            'unpaid' => '<span class="badge bg-danger-subtle text-danger rounded-pill">غير مدفوع</span>',
                                            'partial' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                            'partially_paid' => '<span class="badge bg-warning-subtle text-warning rounded-pill">مدفوع جزئياً</span>',
                                            'fully_paid' => '<span class="badge bg-success-subtle text-success rounded-pill">مدفوع بالكامل</span>',
                                            'awaiting_approval' => '<span class="badge bg-info-subtle text-info rounded-pill">بانتظار الاعتماد</span>'
                                        ];
                                        $invoice_badges = [
                                            'draft' => '<span class="badge bg-secondary-subtle text-secondary rounded-pill">مسودة</span>',
                                            'posted' => '<span class="badge bg-primary-subtle text-primary rounded-pill">مرحل</span>',
                                            'cancelled' => '<span class="badge bg-danger-subtle text-danger rounded-pill">ملغي</span>'
                                        ];

                                        $net_amount = (float)($booking['sale_price'] ?? 0) - (float)($booking['discount'] ?? 0);
                                        $remaining = $net_amount - (float)($booking['amount_received'] ?? 0);
                                        $salesPayKey = ($net_amount > 0 && (float)$booking['amount_received'] >= $net_amount - 0.01)
                                            ? 'fully_paid'
                                            : ((float)$booking['amount_received'] > 0 ? 'partial' : 'unpaid');

                                        $purchase_paid = (float)($booking['purchase_paid'] ?? 0);
                                        $purchase_total = (float)($booking['purchase_price'] ?? 0);
                                        $purchase_remaining = $purchase_total - $purchase_paid;
                                        $purchasePayKey = ($purchase_total > 0 && $purchase_paid >= $purchase_total - 0.01)
                                            ? 'fully_paid'
                                            : ($purchase_paid > 0 ? 'partial' : 'unpaid');
                                        ?>
                                        <div class="payment-stack">
                                            <div class="payment-box small">
                                                <div class="payment-box-title text-success">البيع</div>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php echo $pay_badges[$salesPayKey] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                                    <?php echo $invoice_badges[$booking['sales_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>'; ?>
                                                </div>
                                            </div>
                                            <div class="payment-box small">
                                                <div class="payment-box-title text-primary">الشراء</div>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php echo ($purchase_total > 0) ? ($pay_badges[$purchasePayKey] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : '<span class="badge bg-light text-dark rounded-pill">لا توجد</span>'; ?>
                                                    <?php echo ($purchase_total > 0) ? ($invoice_badges[$booking['purchase_status'] ?? 'draft'] ?? '<span class="badge bg-light text-dark rounded-pill">---</span>') : ''; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $hasPostedInvoice = (($booking['sales_status'] ?? '') === 'posted') || (($booking['purchase_status'] ?? '') === 'posted');
                                        ?>
                                        <div class="btn-group shadow-sm">
                                            <?php if (has_permission('bookings_edit') && !$hasPostedInvoice): ?>
                                                <button class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#editBookingModal<?php echo $booking['id']; ?>" title="تعديل">
                                                    <i class="fas fa-edit text-primary"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (has_permission('bookings_print')): ?>
                                                <a href="bus_flight_bookings_print.php?id=<?php echo $booking['id']; ?>" target="_blank" class="btn btn-sm btn-light border" title="طباعة الحجز">
                                                    <i class="fas fa-print text-secondary"></i>
                                                </a>
                                                <?php if ($booking['booking_status_name'] === 'مؤكد'): ?>
                                                    <a href="bus_flight_bookings_ticket.php?id=<?php echo $booking['id']; ?>" target="_blank" class="btn btn-sm btn-light border" title="طباعة التذكرة">
                                                        <i class="fas fa-ticket-alt text-primary"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php
                                            // التحقق من وجود سند استرداد لهذا الحجز (استخدام جدول المستندات الجديد)
                                            $stmt_refund = $pdo->prepare("
                                                SELECT id
                                                FROM financial_transactions
                                                WHERE (reference_type = ? OR reference_type IN ('bus_flight_booking', 'bus_flight_bookings'))
                                                  AND reference_id = ?
                                                  AND transaction_type = 'payment'
                                                ORDER BY id DESC
                                                LIMIT 1
                                            ");
                                            $stmt_refund->execute([$booking['service_type'], $booking['id']]);
                                            $refund_id = $stmt_refund->fetchColumn();

                                            if ($booking['booking_status_name'] === 'تم إلغاء الحجز' && $refund_id): ?>
                                                <a href="payments_print.php?id=<?php echo $refund_id; ?>" target="_blank" class="btn btn-sm btn-light border" title="طباعة سند الاسترداد">
                                                    <i class="fas fa-file-invoice-dollar text-success"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php
                                            // التحقق من وجود فواتير قابلة للترحيل
                                            $can_post_sales = $booking['sales_invoice_id'] && $booking['sales_status'] == 'draft';
                                            $can_post_purchase = $booking['purchase_invoice_id'] && $booking['purchase_status'] == 'draft';
                                            $can_post_all = $can_post_sales && $can_post_purchase;
                                            $sales_deletable_status = !$booking['sales_invoice_id'] || in_array(($booking['sales_status'] ?? ''), ['draft', 'cancelled'], true);
                                            $purchase_deletable_status = !$booking['purchase_invoice_id'] || in_array(($booking['purchase_status'] ?? ''), ['draft', 'cancelled'], true);
                                            $all_existing_invoices_deletable = $sales_deletable_status && $purchase_deletable_status;
                                            $can_delete_sales = $booking['sales_invoice_id'] && $all_existing_invoices_deletable;
                                            $can_delete_purchase = $booking['purchase_invoice_id'] && $all_existing_invoices_deletable;
                                            $can_delete_all = ($booking['sales_invoice_id'] || $booking['purchase_invoice_id']) && $all_existing_invoices_deletable;

                                            if ($can_post_sales || $can_post_purchase):
                                            ?>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-file-invoice-dollar me-1"></i> ترحيل
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <?php if ($can_post_all): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد الترحيل المالي" data-confirm-text="هل أنت متأكد من ترحيل الفواتير (البيع والشراء) معاً؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="post_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['sales_invoice_id']; ?>">
                                                                    <input type="hidden" name="post_scope" value="all">
                                                                    <input type="hidden" name="linked_invoice_id" value="<?php echo $booking['purchase_invoice_id']; ?>">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item text-success fw-bold">
                                                                        <i class="fas fa-check-double me-2"></i> ترحيل الكل (بيع + شراء)
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <hr class="dropdown-divider">
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($can_post_sales): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد ترحيل فاتورة البيع" data-confirm-text="هل أنت متأكد من ترحيل فاتورة البيع؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="post_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['sales_invoice_id']; ?>">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item text-primary">
                                                                        <i class="fas fa-file-invoice-dollar me-2"></i> ترحيل فاتورة البيع
                                                                        <span class="badge bg-primary-subtle text-primary ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($can_post_purchase): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد ترحيل فاتورة الشراء" data-confirm-text="هل أنت متأكد من ترحيل فاتورة الشراء؟" data-confirm-icon="warning" data-confirm-button="نعم، رحّل" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="post_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['purchase_invoice_id']; ?>">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item text-warning">
                                                                        <i class="fas fa-file-invoice me-2"></i> ترحيل فاتورة الشراء
                                                                        <span class="badge bg-warning-subtle text-warning ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                        <li class="dropdown-header text-muted small">حالة الفواتير: مسودة (draft)</li>

                                                        <?php if ($booking['sales_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-info" href="invoices.php?action=view&id=<?php echo $booking['sales_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-eye me-2"></i> عرض فاتورة البيع
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['purchase_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-info" href="invoices.php?action=view&id=<?php echo $booking['purchase_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-eye me-2"></i> عرض فاتورة الشراء
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php elseif ($booking['sales_invoice_id'] || $booking['purchase_invoice_id']): ?>
                                                <!-- الفواتير مُرحلة - عرض روابط وزر التراجع -->
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-check me-1"></i> مُرحل
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <li>
                                                            <h6 class="dropdown-header small fw-bold text-danger">إعادة التعيين إلى مسودة</h6>
                                                        </li>
                                                        <?php if ($booking['sales_invoice_id'] && $booking['sales_status'] == 'posted' && $booking['purchase_invoice_id'] && $booking['purchase_status'] == 'posted'): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء الترحيل" data-confirm-text="إلغاء ترحيل البيع والشراء معاً؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="reset_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['sales_invoice_id']; ?>">
                                                                    <input type="hidden" name="reset_type" value="all">
                                                                    <input type="hidden" name="linked_invoice_id" value="<?php echo $booking['purchase_invoice_id']; ?>">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item py-2 text-danger">
                                                                        <i class="fas fa-sync me-2"></i> إلغاء ترحيل الكل
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <hr class="dropdown-divider">
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['sales_invoice_id'] && $booking['sales_status'] == 'posted'): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء ترحيل البيع" data-confirm-text="إلغاء ترحيل فاتورة البيع؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="reset_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['sales_invoice_id']; ?>">
                                                                    <input type="hidden" name="reset_type" value="sales">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item py-2 text-warning">
                                                                        <i class="fas fa-undo me-2"></i> إلغاء ترحيل البيع
                                                                        <span class="badge bg-warning-subtle text-warning ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['purchase_invoice_id'] && $booking['purchase_status'] == 'posted'): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد إلغاء ترحيل الشراء" data-confirm-text="إلغاء ترحيل فاتورة الشراء؟" data-confirm-icon="warning" data-confirm-button="نعم، ألغِ الترحيل" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="reset_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['purchase_invoice_id']; ?>">
                                                                    <input type="hidden" name="reset_type" value="purchase">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item py-2 text-secondary">
                                                                        <i class="fas fa-history me-2"></i> إلغاء ترحيل الشراء
                                                                        <span class="badge bg-secondary-subtle text-secondary ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>

                                                        <?php if ($booking['sales_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-success" href="invoices.php?action=view&id=<?php echo $booking['sales_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-file-invoice-dollar me-2"></i> عرض فاتورة البيع
                                                                    <span class="badge bg-success ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['purchase_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-success" href="invoices.php?action=view&id=<?php echo $booking['purchase_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-file-invoice me-2"></i> عرض فاتورة الشراء
                                                                    <span class="badge bg-success ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($can_delete_sales || $can_delete_purchase): ?>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-danger dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-trash-alt me-1"></i> حذف
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <?php if ($can_delete_all): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف الكل" data-confirm-text="سيتم حذف الحجز وكل الفواتير والمعاملات المالية المرتبطة به. هل تريد المتابعة؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="delete_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['sales_invoice_id']; ?>">
                                                                    <input type="hidden" name="delete_scope" value="both">
                                                                    <input type="hidden" name="linked_id" value="<?php echo $booking['purchase_invoice_id']; ?>">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item text-danger fw-bold">
                                                                        <i class="fas fa-trash-alt me-2"></i> حذف الكل
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                        <?php endif; ?>

                                                        <?php if ($can_delete_sales): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف فاتورة البيع" data-confirm-text="سيتم حذف فاتورة البيع والحجز والمعاملة المالية المرتبطة. هل تريد المتابعة؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="delete_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['sales_invoice_id']; ?>">
                                                                    <input type="hidden" name="delete_scope" value="self">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item text-danger">
                                                                        <i class="fas fa-file-invoice-dollar me-2"></i> حذف فاتورة البيع
                                                                        <span class="badge bg-danger-subtle text-danger ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($can_delete_purchase): ?>
                                                            <li>
                                                                <form method="post" action="invoices.php" class="mb-0 js-confirm-submit" data-confirm-title="تأكيد حذف فاتورة الشراء" data-confirm-text="سيتم حذف فاتورة الشراء والحجز والمعاملة المالية المرتبطة. هل تريد المتابعة؟" data-confirm-icon="warning" data-confirm-button="نعم، احذف" data-cancel-button="تراجع">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="invoice_action" value="delete_invoice">
                                                                    <input type="hidden" name="invoice_id" value="<?php echo $booking['purchase_invoice_id']; ?>">
                                                                    <input type="hidden" name="delete_scope" value="self">
                                                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($bookingPageUrl); ?>">
                                                                    <button type="submit" class="dropdown-item text-danger">
                                                                        <i class="fas fa-file-invoice me-2"></i> حذف فاتورة الشراء
                                                                        <span class="badge bg-danger-subtle text-danger ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <li><hr class="dropdown-divider"></li>
                                                        <li class="dropdown-header text-muted small">متاح فقط عند كون الفاتورة مسودة أو ملغية</li>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (has_permission('bookings_view_details')): ?>
                                                <a href="bus_flight_bookings_details.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-light border" title="عرض التفاصيل">
                                                    <i class="fas fa-eye text-info"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php
                                            $has_pending_req = in_array($booking['id'], $pending_bookings_ids);
                                            $can_request_change = has_permission('bookings_request_approval') && !in_array($booking['booking_status_name'], ['تم إلغاء الحجز', 'سافر', 'مسافر']);

                                            if ($can_request_change && !$hasPostedInvoice): ?>
                                                <button class="btn btn-sm btn-light border <?= $has_pending_req ? 'opacity-50' : '' ?>"
                                                    <?= $has_pending_req ? 'onclick="alert(\'يوجد طلب سابق، يرجى الانتظار موافقة المدير\'); return false;"' : 'data-bs-toggle="modal" data-bs-target="#requestCancelModal' . $booking['id'] . '"' ?>
                                                    title="طلب إلغاء">
                                                    <i class="fas fa-times-circle text-danger"></i>
                                                </button>
                                                <button class="btn btn-sm btn-light border <?= $has_pending_req ? 'opacity-50' : '' ?>"
                                                    <?= $has_pending_req ? 'onclick="alert(\'يوجد طلب سابق، يرجى الانتظار موافقة المدير\'); return false;"' : 'data-bs-toggle="modal" data-bs-target="#requestModModal' . $booking['id'] . '"' ?>
                                                    title="طلب تعديل">
                                                    <i class="fas fa-edit text-warning"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (has_permission('bookings_change_workflow') && !empty($allowed_transitions)): ?>
                                                <div class="dropdown d-inline-block">
                                                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="تغيير المرحلة">
                                                        <i class="fas fa-random text-success"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                                                        <li>
                                                            <h6 class="dropdown-header extra-small text-muted">نقل إلى:</h6>
                                                        </li>
                                                        <?php foreach ($allowed_transitions as $trans): ?>
                                                            <li>
                                                                <a class="dropdown-item small d-flex align-items-center justify-content-between py-2"
                                                                    href="#"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#workflowModal_<?= $booking['id'] ?>_<?= $trans['to_step_id'] ?>">
                                                                    <span><i class="fas fa-chevron-left me-2 text-<?= $trans['color'] ?: 'primary' ?>"></i> <?= htmlspecialchars($trans['to_step_name']) ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- =================================================================================================== -->
<!--                                        SECTION: MODALS                                              -->
<!-- =================================================================================================== -->

<!-- Add Booking Modal -->
<div class="modal fade" id="addBookingModal">
    <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-centered booking-form-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4 booking-form-content">
            <form method="POST" action="<?php echo h($bookingPageUrl); ?>">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="source_type" value="<?php echo h($pageBookingModule->getFinanceSourceType()); ?>">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة حجز جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light booking-form-body">
                    <div class="row g-3">
                        <!-- Passenger and Branch Details -->
                        <div class="col-12">
                            <h5 class="text-primary fw-bold mb-3"><i class="fas fa-user me-2"></i> بيانات المسافر والفرع</h5>
                        </div>

                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label for="add_traveler_name" class="form-label fw-bold text-primary mb-2">اسم المسافر <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_traveler_name" name="traveler_name" oninput="updateDescription()" required>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label for="add_mobile_number" class="form-label fw-bold text-primary mb-2">رقم الجوال <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_mobile_number" name="mobile_number" required>
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label for="add_gender" class="form-label fw-bold text-primary mb-2">الجنس <span class="text-danger">*</span></label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_gender" name="gender" required>
                                <option value="male">ذكر</option>
                                <option value="female">أنثى</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label for="add_nationality_id" class="form-label fw-bold text-primary mb-2">الجنسية</label>
                            <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="add_nationality_id" name="nationality_id">
                                <option value="">اختر الجنسية</option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?php echo $country['id']; ?>"><?php echo htmlspecialchars($country['country_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label for="add_date_of_birth" class="form-label fw-bold text-primary mb-2">تاريخ الميلاد</label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_date_of_birth" name="date_of_birth" max="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label for="add_place_of_birth" class="form-label fw-bold text-primary mb-2">مكان الميلاد</label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_place_of_birth" name="place_of_birth">
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label for="add_id_type" class="form-label fw-bold text-primary mb-2">نوع الهوية</label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_id_type" name="id_type">
                                <option value="passport">جواز سفر</option>
                                <option value="national_id">بطاقة وطنية</option>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <label for="id_number" class="form-label fw-bold text-primary mb-2">رقم الهوية</label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="id_number" name="id_number">
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label for="add_id_issue_place" class="form-label fw-bold text-primary mb-2">مكان الإصدار</label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_id_issue_place" name="id_issue_place">
                        </div>
                        <div class="col-xl-2 col-lg-4 col-md-6">
                            <label for="add_id_issue_date" class="form-label fw-bold text-primary mb-2">تاريخ الإصدار</label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_id_issue_date" name="id_issue_date" min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <!-- Separator -->
                        <div class="col-12">
                            <hr class="my-2 border-primary opacity-25">
                        </div>

                        <!-- Booking Details -->
                        <div class="col-12 mt-2">
                            <h5 class="text-primary fw-bold mb-2"><i class="fas fa-ticket-alt me-2"></i> بيانات الرحلة</h5>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label for="add_booking_date" class="form-label fw-bold text-primary mb-2">تاريخ الحجز <span class="text-danger">*</span></label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_booking_date" name="booking_date" value="<?php echo date('Y-m-d'); ?>" required>
                            <div id="add_booking_date_day" class="text-muted small mt-1"></div>
                            <div id="add_booking_date_warning" class="text-danger small mt-1"></div>
                        </div>
                        <?php if (!$pageBookingModule->isScoped()): ?>
                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label for="add_service_type" class="form-label fw-bold text-primary mb-2">نوع الخدمة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 shadow-sm border-2" id="add_service_type" name="service_type" onchange="updateDescription()" required>
                                    <option value="">اختر النوع</option>
                                    <?php foreach ($allowedBookingServiceTypes as $serviceTypeValue => $serviceTypeLabel): ?>
                                        <option value="<?php echo h($serviceTypeValue); ?>"><?php echo h($serviceTypeLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" id="add_service_type_fixed" name="service_type" value="<?php echo h($pageBookingModule->getServiceType()); ?>">
                        <?php endif; ?>
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label for="add_trip_type" class="form-label fw-bold text-primary mb-2">نوع الرحلة <span class="text-danger">*</span></label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_trip_type" name="trip_type" onchange="const returnField = document.getElementById('add_return_date_field'); if(this.value === 'round_trip') { returnField.style.display = 'block'; } else { returnField.style.display = 'none'; document.getElementById('add_return_date').value = ''; document.getElementById('add_return_date_day').textContent = ''; document.getElementById('add_return_date_warning').textContent = ''; }" required>
                                <option value="one_way">ذهاب فقط</option>
                                <option value="round_trip">ذهاب وعودة</option>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-3 col-md-6">
                            <label for="add_from_city_id" class="form-label fw-bold text-primary mb-2">من مدينة <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="add_from_city_id" name="from_city_id" onchange="updateDescription()" required>
                                <option value="">اختر</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo $city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-3 col-lg-3 col-md-6">
                            <label for="add_to_city_id" class="form-label fw-bold text-primary mb-2">إلى مدينة <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="add_to_city_id" name="to_city_id" onchange="updateDescription()" required>
                                <option value="">اختر</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo $city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label for="add_departure_date" class="form-label fw-bold text-primary mb-2">تاريخ المغادرة <span class="text-danger">*</span></label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_departure_date" name="departure_date" required>
                            <div id="add_departure_date_day" class="text-muted small mt-1"></div>
                            <div id="add_departure_date_warning" class="text-danger small mt-1"></div>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-4" id="add_return_date_field" style="display: none;">
                            <label for="add_return_date" class="form-label fw-bold text-primary mb-2">تاريخ العودة</label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_return_date" name="return_date">
                            <div id="add_return_date_day" class="text-muted small mt-1"></div>
                            <div id="add_return_date_warning" class="text-danger small mt-1"></div>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-4" id="add_bus_type_field" style="display: none;">
                            <label for="add_bus_type" class="form-label fw-bold text-primary mb-2">نوع الباص</label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_bus_type" name="bus_type">
                                <option value="">اختر</option>
                                <option value="tourist">سياحي</option>
                                <option value="regular">عادي</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="add_notes" class="form-label fw-bold text-primary mb-2">ملاحظات إضافية</label>
                            <textarea class="form-control rounded-3 shadow-sm border-2" id="add_notes" name="notes" rows="2"></textarea>
                        </div>


                        <!-- Separator -->
                        <div class="col-12">
                            <hr class="my-2 border-primary opacity-25">
                        </div>

                        <!-- Financial Details - Unified Invoice Style -->
                        <?php
                        // إعداد بيانات الفاتورة الحالية
                        $current_invoice = [
                            'invoice_date' => normalize_datetime_db(null),
                            'branch_id' => $currentUser['branch_id'] ?? null,
                            'source_type' => $pageBookingModule->getFinanceSourceType(),
                            'delivery_type' => '',
                            'total_amount' => 0,
                            'discount' => 0,
                            'cost_amount' => 0,
                            'amount_received' => 0,
                            'currency_id' => 1,
                            'description' => ''
                        ];
                        $financial_fields_select2_parent = '#addBookingModal';
                        $financial_fields_show_service_select = false;
                        $financial_fields_hide_service_accounts = true;
                        include '../includes/financial_fields.php';
                        ?>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 pt-3 booking-form-footer">
                    <button type="button" class="btn btn-secondary btn-lg rounded-pill px-5 py-2" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>إلغاء</button>
                    <button type="submit" name="add_new_booking" class="btn btn-primary btn-lg rounded-pill px-5 py-2"><i class="fas fa-save me-2"></i>حفظ الحجز</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Booking-specific Modals -->
<?php foreach ($bookings as $booking):
    // إعادة حساب الانتقالات المسموحة لهذا الحجز في حلقة المودالات
    $bid = $booking['branch_id'] ?: 0;
    $workflow = $workflows_cache[$bid];
    $allowed_transitions = [];
    if ($workflow) {
        $stmt_curr = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ? LIMIT 1");
        $stmt_curr->execute([$workflow['id'], $booking['status_id']]);
        $current_step_id = $stmt_curr->fetchColumn();
        if ($current_step_id) {
            $allowed_transitions = get_allowed_transitions($workflow['id'], $current_step_id, $_SESSION['role_id'] ?? null, $_SESSION['user_id'] ?? null);
        }
    }
?>
    <!-- 1. Edit Booking Modal -->
    <div class="modal fade" id="editBookingModal<?php echo $booking['id']; ?>">
        <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-centered booking-form-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4 booking-form-content">
                <form method="POST" action="<?php echo h($bookingPageUrl); ?>">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                    <div class="modal-header bg-primary text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات الحجز</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light text-start booking-form-body">
                        <div class="row g-3">
                            <!-- Passenger and Branch Details -->
                            <div class="col-12">
                                <h5 class="text-primary fw-bold mb-3"><i class="fas fa-user me-2"></i> بيانات المسافر والفرع</h5>
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">اسم المسافر <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2 traveler-name-edit" name="traveler_name" value="<?php echo htmlspecialchars($booking['traveler_name']); ?>" required>
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">رقم الجوال <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="mobile_number" value="<?php echo htmlspecialchars($booking['mobile_number']); ?>" required>
                            </div>

                            <div class="col-xl-2 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">الجنس <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 shadow-sm border-2" name="gender" required>
                                    <option value="male" <?= $booking['gender'] == 'male' ? 'selected' : '' ?>>ذكر</option>
                                    <option value="female" <?= $booking['gender'] == 'female' ? 'selected' : '' ?>>أنثى</option>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">الجنسية</label>
                                <select class="form-select select2-modal rounded-3 shadow-sm border-2" name="nationality_id">
                                    <option value="">اختر</option>
                                    <?php foreach ($countries as $country): ?>
                                        <option value="<?php echo $country['id']; ?>" <?= $booking['nationality_id'] == $country['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($country['country_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ الميلاد</label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2" name="date_of_birth" value="<?php echo $booking['date_of_birth']; ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">مكان الميلاد</label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="place_of_birth" value="<?php echo htmlspecialchars($booking['place_of_birth'] ?? ''); ?>">
                            </div>

                            <div class="col-xl-2 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">نوع الهوية</label>
                                <select class="form-select rounded-3 shadow-sm border-2" name="id_type">
                                    <option value="passport" <?= $booking['id_type'] == 'passport' ? 'selected' : '' ?>>جواز سفر</option>
                                    <option value="national_id" <?= $booking['id_type'] == 'national_id' ? 'selected' : '' ?>>بطاقة وطنية</option>
                                </select>
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">رقم الهوية</label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="id_number" value="<?php echo htmlspecialchars($booking['id_number'] ?? ''); ?>">
                            </div>

                            <div class="col-xl-2 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">مكان إصدار الهوية</label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="id_issue_place" value="<?php echo htmlspecialchars($booking['id_issue_place'] ?? ''); ?>">
                            </div>

                            <div class="col-xl-2 col-lg-4 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ الإصدار</label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2" name="id_issue_date" value="<?php echo $booking['id_issue_date'] ?? ''; ?>" min="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <!-- Separator -->
                            <div class="col-12">
                                <hr class="my-2 border-primary opacity-25">
                            </div>

                            <!-- Booking Details -->
                            <div class="col-12 mt-2">
                                <h5 class="text-primary fw-bold mb-2"><i class="fas fa-ticket-alt me-2"></i> بيانات الرحلة</h5>
                            </div>

                            <div class="col-xl-2 col-lg-3 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ الحجز <span class="text-danger">*</span></label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2 edit-booking-date" name="booking_date" value="<?php echo $booking['booking_date']; ?>" required>
                                <div class="edit-booking-date-day text-muted small mt-1"></div>
                                <div class="edit-booking-date-warning text-danger small mt-1"></div>
                            </div>

                            <?php if (!$pageBookingModule->isScoped()): ?>
                                <div class="col-xl-2 col-lg-2 col-md-2">
                                    <label class="form-label fw-bold text-primary mb-2 small">نوع الخدمة <span class="text-danger">*</span></label>
                                    <select class="form-select rounded-3 shadow-sm border-2 service-type-edit" name="service_type" onchange="const modal = this.closest('.modal-content'); const busTypeField = modal.querySelector('.bus-type-edit-field'); if(this.value === 'bus') busTypeField.style.display = 'block'; else busTypeField.style.display = 'none';" required>
                                        <?php foreach ($allowedBookingServiceTypes as $serviceTypeValue => $serviceTypeLabel): ?>
                                            <option value="<?php echo h($serviceTypeValue); ?>" <?= $booking['service_type'] == $serviceTypeValue ? 'selected' : '' ?>><?php echo h($serviceTypeLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="service_type" value="<?php echo h($pageBookingModule->getServiceType()); ?>">
                            <?php endif; ?>

                            <div class="col-xl-2 col-lg-3 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">نوع الرحلة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 shadow-sm border-2 trip-type-edit" name="trip_type" onchange="const modal = this.closest('.modal-content'); const returnField = modal.querySelector('.return-date-edit-field'); if(this.value === 'round_trip') returnField.style.display = 'block'; else returnField.style.display = 'none';" required>
                                    <option value="one_way" <?= ($booking['trip_type'] ?? 'one_way') == 'one_way' ? 'selected' : '' ?>>ذهاب فقط</option>
                                    <option value="round_trip" <?= ($booking['trip_type'] ?? '') == 'round_trip' ? 'selected' : '' ?>>ذهاب وعودة</option>
                                </select>
                            </div>

                            <div class="col-xl-3 col-lg-3 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">من مدينة <span class="text-danger">*</span></label>
                                <select class="form-select select2-modal rounded-3 shadow-sm border-2 from-city-edit" name="from_city_id" required>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo $city['id']; ?>" <?= $booking['from_city_id'] == $city['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-xl-3 col-lg-3 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">إلى مدينة <span class="text-danger">*</span></label>
                                <select class="form-select select2-modal rounded-3 shadow-sm border-2 to-city-edit" name="to_city_id" required>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo $city['id']; ?>" <?= $booking['to_city_id'] == $city['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-3 col-md-6">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ المغادرة <span class="text-danger">*</span></label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2 edit-departure-date" name="departure_date" value="<?php echo $booking['departure_date']; ?>" required>
                                <div class="edit-departure-date-day text-muted small mt-1"></div>
                                <div class="edit-departure-date-warning text-danger small mt-1"></div>
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-4 return-date-edit-field" style="<?= ($booking['trip_type'] ?? '') == 'round_trip' ? 'display: block;' : 'display: none;' ?>">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ العودة</label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2 edit-return-date" name="return_date" value="<?php echo $booking['return_date'] ?? ''; ?>">
                                <div class="edit-return-date-day text-muted small mt-1"></div>
                                <div class="edit-return-date-warning text-danger small mt-1"></div>
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-4 bus-type-edit-field" style="<?= $pageBookingModule->isBusService($booking['service_type']) ? 'display: block;' : 'display: none;' ?>">
                                <label class="form-label fw-bold text-primary mb-2 small">نوع الباص</label>
                                <select class="form-select rounded-3 shadow-sm border-2" name="bus_type">
                                    <option value="">اختر النوع</option>
                                    <option value="tourist" <?= $booking['bus_type'] == 'tourist' ? 'selected' : '' ?>>سياحي (Tourist)</option>
                                    <option value="regular" <?= $booking['bus_type'] == 'regular' ? 'selected' : '' ?>>عادي (Regular)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold text-primary mb-2 small">ملاحظات إضافية</label>
                                <textarea class="form-control rounded-3 shadow-sm border-2" name="notes" rows="2"><?php echo htmlspecialchars($booking['notes']); ?></textarea>
                            </div>

                            <!-- Separator -->
                            <div class="col-12">
                                <hr class="my-2 border-primary opacity-25">
                            </div>

                            <!-- Financial Details -->
                            <?php
                            // جلب حالة الفاتورتين لتحديد إمكانية التعديل
                            $inv_status_edit = 'draft';
                            $pur_inv_edit = null;
                            $si = null;
                            if ($booking['invoice_id']) {
                                $stmt_inv_st = $pdo->prepare("SELECT invoice_status, currency_id, discount, invoice_date AS transaction_date FROM invoices WHERE id = ?");
                                $stmt_inv_st->execute([$booking['invoice_id']]);
                                $si = $stmt_inv_st->fetch();
                                $inv_status_edit = $si['invoice_status'] ?? 'posted';
                                // فاتورة الشراء
                                $stmt_pur_edit = $pdo->prepare("SELECT id, invoice_status, total_amount, currency_id FROM invoices WHERE source_type IN ($bookingFinancialSourceTypesSql) AND source_id = ? AND invoice_category = 'purchase' LIMIT 1");
                                $stmt_pur_edit->execute([$booking['id']]);
                                $pur_inv_edit = $stmt_pur_edit->fetch();
                            }
                            $can_edit_financials = ($inv_status_edit === 'draft');
                            ?>

                            <div class="col-12 mt-4">
                                <h5 class="text-primary fw-bold mb-3">
                                    <i class="fas fa-dollar-sign me-2"></i> البيانات المالية
                                    <?php if ($can_edit_financials): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle ms-2 small">قابلة للتعديل</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-2 small"><i class="fas fa-lock me-1"></i>مؤمنة (الفاتورة مرحّلة)</span>
                                    <?php endif; ?>
                                </h5>
                            </div>

                            <?php if ($can_edit_financials): ?>
                                <?php
                                $editBookingFinanceModule = BookingServiceFactory::make($booking['service_type'] ?? null);
                                $sale_inv_currency_id = $si['currency_id'] ?? $booking['currency_id'];
                                $pur_inv_currency_id  = $pur_inv_edit['currency_id'] ?? $booking['currency_id'];
                                $current_invoice = [
                                    'invoice_date' => $si['transaction_date'] ?? ($booking['operation_date'] ?? date('Y-m-d')),
                                    'branch_id' => $booking['branch_id'] ?? ($currentUser['branch_id'] ?? null),
                                    'source_type' => $editBookingFinanceModule->getFinanceSourceType(),
                                    'delivery_type' => $booking['delivery_type'] ?? '',
                                    'account_id' => $booking['account_id'] ?? null,
                                    'customer_id' => $booking['customer_id'] ?? null,
                                    'agent_id' => $booking['agent_id'] ?? null,
                                    'supplier_id' => $booking['supplier_id'] ?? null,
                                    'total_amount' => $booking['sale_price'] ?? 0,
                                    'discount' => $si['discount'] ?? 0,
                                    'cost_amount' => $booking['purchase_price'] ?? 0,
                                    'received_amount' => $booking['amount_received'] ?? 0,
                                    'sale_currency_id' => $sale_inv_currency_id,
                                    'currency_id' => $pur_inv_currency_id,
                                    'exchange_rate' => 1,
                                    'description' => $booking['description'] ?? '',
                                    'record_purchase' => !empty($booking['purchase_invoice_id']) ? '1' : '1',
                                ];
                                $financial_fields_prefix = 'edit_' . $booking['id'] . '_';
                                $financial_fields_select2_parent = '#editBookingModal' . $booking['id'];
                                $financial_fields_form_selector = '#editBookingModal' . $booking['id'] . ' form';
                                $financial_fields_show_service_select = false;
                                $financial_fields_hide_service_accounts = true;
                                include '../includes/financial_fields.php';
                                ?>
                            <?php else: ?>
                                <!-- الفاتورة مرحّلة - عرض فقط -->
                                <div class="col-12">
                                    <div class="p-3 bg-white rounded-4 border shadow-sm">
                                        <div class="row text-center g-3">
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">سعر البيع</div>
                                                <div class="h5 fw-bold mb-0"><?= number_format($booking['sale_price'], 2) ?> <small><?= htmlspecialchars($booking['currency_name']) ?></small></div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">التكلفة</div>
                                                <div class="h5 fw-bold text-warning mb-0"><?= number_format($booking['purchase_price'], 2) ?></div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">الربح</div>
                                                <div class="h5 fw-bold text-success mb-0"><?= number_format($booking['profit'], 2) ?></div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">الحالة</div>
                                                <div><span class="badge bg-posted rounded-pill">مرحّلة</span></div>
                                            </div>
                                        </div>
                                        <div class="text-center mt-3 pt-3 border-top">
                                            <a href="invoice_details.php?id=<?= $booking['invoice_id'] ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-4 me-2"><i class="fas fa-external-link-alt me-1"></i>تفاصيل الفاتورة</a>
                                            <a href="invoices.php?q=<?= urlencode($booking['booking_number']) ?>" target="_blank" class="btn btn-sm btn-outline-info rounded-pill px-4"><i class="fas fa-search me-1"></i>تتبع الفواتير</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="modal-footer bg-light border-0 booking-form-footer">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_booking" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ التعديلات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Workflow Modals -->
    <?php if (!empty($allowed_transitions)):
        $all_fields_info = get_all_workflow_fields();
        foreach ($allowed_transitions as $trans):
            $step_fields = get_step_fields($trans['to_step_id']);
    ?>
            <div class="modal fade" id="workflowModal_<?= $booking['id'] ?>_<?= $trans['to_step_id'] ?>">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg rounded-4">
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                            <input type="hidden" name="to_status_id" value="<?= (int)$pdo->query("SELECT status_id FROM workflow_steps WHERE id = " . $trans['to_step_id'])->fetchColumn() ?>">
                            <input type="hidden" name="transition_id" value="<?= $trans['transition_id'] ?>">
                            <div class="modal-header bg-<?= $trans['color'] ?: 'primary' ?> text-white border-0 py-3">
                                <h6 class="modal-title fw-bold">نقل الحجز رقم <?= $booking['booking_number'] ?> إلى: <?= htmlspecialchars($trans['to_step_name']) ?></h6>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4 text-start">
                                <p class="text-muted small mb-3">هل أنت متأكد من رغبتك في نقل الحجز إلى مرحلة "<?= htmlspecialchars($trans['to_step_name']) ?>"؟</p>

                                <?php if (!empty($step_fields)): ?>
                                    <div class="row g-3 mb-3">
                                        <?php foreach ($step_fields as $fkey):
                                            if (!isset($all_fields_info[$fkey])) continue;
                                            $ftype = 'text';
                                            $fvalue = '';
                                            if (strpos($fkey, 'date') !== false || strpos($fkey, 'datetime') !== false) {
                                                $ftype = (strpos($fkey, 'datetime') !== false) ? 'datetime-local' : 'date';
                                                if (in_array($fkey, ['confirm_datetime', 'mod_datetime', 'cancel_datetime'])) $fvalue = date('Y-m-d\TH:i');
                                            }
                                            if (strpos($fkey, 'amount') !== false || strpos($fkey, 'price') !== false) $ftype = 'number';
                                        ?>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold"><?= $all_fields_info[$fkey] ?></label>
                                                <?php if ($fkey == 'is_cancelled'): ?>
                                                    <select name="extra_fields[<?= $fkey ?>]" class="form-select rounded-3">
                                                        <option value="0">لا</option>
                                                        <option value="1">نعم</option>
                                                    </select>
                                                <?php elseif (strpos($fkey, 'reason') !== false || $fkey == 'notes'): ?>
                                                    <textarea name="extra_fields[<?= $fkey ?>]" class="form-control rounded-3" rows="2"></textarea>
                                                <?php else: ?>
                                                    <input type="<?= $ftype ?>" name="extra_fields[<?= $fkey ?>]" class="form-control rounded-3" step="0.01" value="<?= $fvalue ?>">
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-0">
                                    <label class="form-label small fw-bold">ملاحظات التحويل</label>
                                    <textarea class="form-control rounded-3" name="workflow_notes" rows="3" placeholder="أدخل أي ملاحظات اختيارية هنا..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer bg-light border-0">
                                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                                <button type="submit" name="change_workflow_status" class="btn btn-<?= $trans['color'] ?: 'primary' ?> rounded-pill px-4">تأكيد النقل</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
    <?php endforeach;
    endif; ?>

    <!-- 3. Cancel Request Modal -->
    <div class="modal fade" id="requestCancelModal<?php echo $booking['id']; ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                    <input type="hidden" name="to_status_id" value="<?= (int)$pdo->query("SELECT id FROM statuses WHERE status_name = 'تم إلغاء الحجز' LIMIT 1")->fetchColumn() ?>">
                    <div class="modal-header bg-danger text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-times-circle me-2"></i> طلب إلغاء الحجز</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light text-start">
                        <div class="alert alert-warning small rounded-3 border-0 shadow-sm mb-3">
                            <i class="fas fa-info-circle me-2"></i> سيتم إرسال هذا الطلب للمدير للموافقة عليه. عند الموافقة سيتم خصم الغرامة المحددة وإرجاع الباقي من الصندوق.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">إجمالي مبلغ الحجز</label>
                                <input type="text" class="form-control rounded-3 bg-white fw-bold" value="<?= number_format($booking['sale_price'], 2) ?> <?= htmlspecialchars($booking['currency_name']) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">المبلغ المخصوم (الغرامة)</label>
                                <input type="number" step="0.01" name="discount_amount" class="form-control rounded-3 fw-bold border-danger discount-input" value="0" required oninput="calculateNetAmount(this, <?= $booking['sale_price'] ?>)">
                            </div>
                            <div class="col-md-12 text-center">
                                <div class="p-2 bg-white rounded-3 border">
                                    <span class="small text-muted d-block">المبلغ الصافي بعد الخصم (الذي سيتم استرداده)</span>
                                    <span class="h5 fw-bold text-success mb-0 net-amount-display"><?= number_format($booking['amount_received'], 2) ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($booking['currency_name']) ?></small>
                                </div>
                                <div class="mt-1 small text-muted">المبلغ المدفوع حالياً: <?= number_format($booking['amount_received'], 2) ?></div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">سبب الإلغاء / ملاحظات</label>
                                <textarea name="notes" class="form-control rounded-3" rows="3" placeholder="أدخل سبب الإلغاء هنا..." required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="request_approval" class="btn btn-danger rounded-pill px-5 fw-bold shadow">إرسال طلب الإلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 4. Modification Request Modal -->
    <div class="modal fade" id="requestModModal<?php echo $booking['id']; ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                    <input type="hidden" name="to_status_id" value="<?= (int)$pdo->query("SELECT id FROM statuses WHERE status_name = 'تم تعديل الحجز' LIMIT 1")->fetchColumn() ?>">
                    <div class="modal-header bg-warning text-dark border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> طلب تعديل الحجز</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light text-start">
                        <div class="alert alert-info small rounded-3 border-0 shadow-sm mb-3">
                            <i class="fas fa-info-circle me-2"></i> سيتم إرسال طلب التعديل للمدير للموافقة عليه.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">إجمالي مبلغ الحجز</label>
                                <input type="text" class="form-control rounded-3 bg-white fw-bold" value="<?= number_format($booking['sale_price'], 2) ?> <?= htmlspecialchars($booking['currency_name']) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">المبلغ المخصوم (غرامة التعديل)</label>
                                <input type="number" step="0.01" name="discount_amount" class="form-control rounded-3 fw-bold border-warning discount-input" value="0" oninput="calculateNetAmount(this, <?= $booking['sale_price'] ?>)">
                            </div>
                            <div class="col-md-12 text-center">
                                <div class="p-2 bg-white rounded-3 border">
                                    <span class="small text-muted d-block">المبلغ الصافي بعد الخصم</span>
                                    <span class="h5 fw-bold text-primary mb-0 net-amount-display"><?= number_format($booking['sale_price'], 2) ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($booking['currency_name']) ?></small>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">تفاصيل التعديل المطلوب / ملاحظات</label>
                                <textarea name="notes" class="form-control rounded-3" rows="3" placeholder="أدخل تفاصيل التعديل المطلوبة هنا..." required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="request_approval" class="btn btn-warning rounded-pill px-5 fw-bold shadow text-dark">إرسال طلب التعديل</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    /**
     * تحديث البيان التلقائي في مودال الإضافة
     */
    function updateDescription() {
        const travelerName = $('#add_traveler_name').val() || '';
        const fromCityId = $('#add_from_city_id').val();
        const toCityId = $('#add_to_city_id').val();
        if (fromCityId && toCityId && fromCityId === toCityId) {
            $('#add_to_city_id').val('').trigger('change');
            alert('يجب اختيار مدينتين مختلفتين (من مدينة / إلى مدينة).');
            return;
        }
        const fromCity = $('#add_from_city_id option:selected').text();
        const toCity = $('#add_to_city_id option:selected').text();

        let description = 'حجز تذكرة';
        if (fromCity && fromCity !== 'اختر' && fromCity !== '') description += ' من ' + fromCity;
        if (toCity && toCity !== 'اختر' && toCity !== '') description += ' إلى ' + toCity;
        if (travelerName) description += ' للمسافر ' + travelerName;

        $('#addBookingModal [name="description"]').val(description);
    }

    function getAddBookingServiceType() {
        return $('#add_service_type').val() || $('#add_service_type_fixed').val() || '';
    }

    /**
     * تحديث البيان التلقائي في مودالات التعديل
     */
    function updateEditDescription(modal) {
        const $modal = $(modal);
        const travelerName = $modal.find('input[name="traveler_name"]').val() || '';
        const fromCityId = $modal.find('select[name="from_city_id"]').val();
        const toCityId = $modal.find('select[name="to_city_id"]').val();
        if (fromCityId && toCityId && fromCityId === toCityId) {
            $modal.find('select[name="to_city_id"]').val('').trigger('change');
            alert('يجب اختيار مدينتين مختلفتين (من مدينة / إلى مدينة).');
            return;
        }
        const fromCity = $modal.find('select[name="from_city_id"] option:selected').text();
        const toCity = $modal.find('select[name="to_city_id"] option:selected').text();

        let description = 'حجز تذكرة';
        if (fromCity && fromCity !== 'اختر' && fromCity !== '') description += ' من ' + fromCity;
        if (toCity && toCity !== 'اختر' && toCity !== '') description += ' إلى ' + toCity;
        if (travelerName) description += ' للمسافر ' + travelerName;

        $modal.find('[name="description"]').val(description);
    }

    // دالة لجلب اسم اليوم بالعربية
    function getArabicDayName(dateStr) {
        const date = new Date(dateStr + 'T00:00:00');
        const days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        return days[date.getDay()];
    }

    // دالة لمعالجة تاريخ الحجز
    function handleBookingDate(input, dayDisplay, warningDisplay) {
        const dateVal = input.value;
        if (!dateVal) {
            dayDisplay.textContent = '';
            warningDisplay.textContent = '';
            return;
        }

        // عرض اسم اليوم
        dayDisplay.textContent = 'اليوم: ' + getArabicDayName(dateVal);

        // تحذير إذا كان التاريخ في الماضي
        const today = new Date();
        today.setHours(0,0,0,0);
        const selectedDate = new Date(dateVal + 'T00:00:00');
        if (selectedDate < today) {
            warningDisplay.textContent = '⚠️ تاريخ الحجز في الماضي!';
        } else {
            warningDisplay.textContent = '';
        }
    }

    // دالة لمعالجة تاريخ المغادرة
    function handleDepartureDate(input, dayDisplay, warningDisplay, returnInput = null, returnDay = null, returnWarning = null) {
        const dateVal = input.value;
        if (!dateVal) {
            dayDisplay.textContent = '';
            warningDisplay.textContent = '';
            return;
        }

        // عرض اسم اليوم
        dayDisplay.textContent = 'اليوم: ' + getArabicDayName(dateVal);

        // تحذير إذا كان التاريخ في الماضي
        const today = new Date();
        today.setHours(0,0,0,0);
        const selectedDate = new Date(dateVal + 'T00:00:00');
        if (selectedDate < today) {
            warningDisplay.textContent = '⚠️ تاريخ المغادرة لا يمكن أن يكون في الماضي!';
        } else {
            warningDisplay.textContent = '';
        }

        // إذا كان هناك تاريخ عودة، تحقق منه
        if (returnInput && returnInput.value) {
            handleReturnDate(returnInput, returnDay, returnWarning, dateVal);
        }
    }

    // دالة لمعالجة تاريخ العودة
    function handleReturnDate(input, dayDisplay, warningDisplay, departureDateVal) {
        const dateVal = input.value;
        if (!dateVal) {
            dayDisplay.textContent = '';
            warningDisplay.textContent = '';
            return;
        }

        // عرض اسم اليوم
        dayDisplay.textContent = 'اليوم: ' + getArabicDayName(dateVal);

        // تحقق أن تاريخ العودة بعد تاريخ المغادرة
        if (departureDateVal) {
            const depDate = new Date(departureDateVal + 'T00:00:00');
            const retDate = new Date(dateVal + 'T00:00:00');
            if (retDate <= depDate) {
                warningDisplay.textContent = '⚠️ تاريخ العودة يجب أن يكون بعد تاريخ المغادرة!';
            } else {
                warningDisplay.textContent = '';
            }
        }
    }

    /**
     * تحديث صافي المبلغ في مودالات الوورك فلو
     */
    function calculateNetAmount(input, total) {
        const discount = parseFloat(input.value) || 0;
        const net = Math.max(0, total - discount);
        const modal = input.closest('.modal');
        const netInput = modal.querySelector('input[name*="net_amount"]');
        if (netInput) netInput.value = net.toFixed(2);
    }

    $(document).ready(function() {
        console.log("Booking script loaded");

        // إضافة مستمعي الأحداث لنموذج الإضافة
        const addBookingDate = document.getElementById('add_booking_date');
        const addBookingDay = document.getElementById('add_booking_date_day');
        const addBookingWarning = document.getElementById('add_booking_date_warning');
        if (addBookingDate) {
            handleBookingDate(addBookingDate, addBookingDay, addBookingWarning);
            addBookingDate.addEventListener('change', function() {
                handleBookingDate(this, addBookingDay, addBookingWarning);
            });
        }

        const addDepartureDate = document.getElementById('add_departure_date');
        const addDepartureDay = document.getElementById('add_departure_date_day');
        const addDepartureWarning = document.getElementById('add_departure_date_warning');
        const addReturnDate = document.getElementById('add_return_date');
        const addReturnDay = document.getElementById('add_return_date_day');
        const addReturnWarning = document.getElementById('add_return_date_warning');

        if (addDepartureDate) {
            handleDepartureDate(addDepartureDate, addDepartureDay, addDepartureWarning, addReturnDate, addReturnDay, addReturnWarning);
            addDepartureDate.addEventListener('change', function() {
                handleDepartureDate(this, addDepartureDay, addDepartureWarning, addReturnDate, addReturnDay, addReturnWarning);
            });
        }

        if (addReturnDate) {
            addReturnDate.addEventListener('change', function() {
                const depVal = addDepartureDate ? addDepartureDate.value : null;
                handleReturnDate(this, addReturnDay, addReturnWarning, depVal);
            });
        }

        // إضافة مستمعي الأحداث لنماذج التعديل
        $(document).on('change', '.edit-booking-date', function() {
            const modal = $(this).closest('.modal-content')[0];
            const dayEl = modal.querySelector('.edit-booking-date-day');
            const warnEl = modal.querySelector('.edit-booking-date-warning');
            handleBookingDate(this, dayEl, warnEl);
        });

        $(document).on('change', '.edit-departure-date', function() {
            const modal = $(this).closest('.modal-content')[0];
            const dayEl = modal.querySelector('.edit-departure-date-day');
            const warnEl = modal.querySelector('.edit-departure-date-warning');
            const retInput = modal.querySelector('.edit-return-date');
            const retDay = modal.querySelector('.edit-return-date-day');
            const retWarn = modal.querySelector('.edit-return-date-warning');
            handleDepartureDate(this, dayEl, warnEl, retInput, retDay, retWarn);
        });

        $(document).on('change', '.edit-return-date', function() {
            const modal = $(this).closest('.modal-content')[0];
            const dayEl = modal.querySelector('.edit-return-date-day');
            const warnEl = modal.querySelector('.edit-return-date-warning');
            const depInput = modal.querySelector('.edit-departure-date');
            const depVal = depInput ? depInput.value : null;
            handleReturnDate(this, dayEl, warnEl, depVal);
        });

        // تشغيل الدوال مبدئياً عند فتح المودالات
        $(document).on('shown.bs.modal', '.modal', function() {
            const modal = $(this)[0];

            // لنموذج الإضافة
            const addBkDate = modal.querySelector('#add_booking_date');
            const addBkDay = modal.querySelector('#add_booking_date_day');
            const addBkWarn = modal.querySelector('#add_booking_date_warning');
            if (addBkDate) handleBookingDate(addBkDate, addBkDay, addBkWarn);

            const addDepDate = modal.querySelector('#add_departure_date');
            const addDepDay = modal.querySelector('#add_departure_date_day');
            const addDepWarn = modal.querySelector('#add_departure_date_warning');
            const addRetDate = modal.querySelector('#add_return_date');
            const addRetDay = modal.querySelector('#add_return_date_day');
            const addRetWarn = modal.querySelector('#add_return_date_warning');
            if (addDepDate) handleDepartureDate(addDepDate, addDepDay, addDepWarn, addRetDate, addRetDay, addRetWarn);
            if (addRetDate && addDepDate) handleReturnDate(addRetDate, addRetDay, addRetWarn, addDepDate.value);

            // لنموذج التعديل
            const editBkDate = modal.querySelector('.edit-booking-date');
            const editBkDay = modal.querySelector('.edit-booking-date-day');
            const editBkWarn = modal.querySelector('.edit-booking-date-warning');
            if (editBkDate) handleBookingDate(editBkDate, editBkDay, editBkWarn);

            const editDepDate = modal.querySelector('.edit-departure-date');
            const editDepDay = modal.querySelector('.edit-departure-date-day');
            const editDepWarn = modal.querySelector('.edit-departure-date-warning');
            const editRetDate = modal.querySelector('.edit-return-date');
            const editRetDay = modal.querySelector('.edit-return-date-day');
            const editRetWarn = modal.querySelector('.edit-return-date-warning');
            if (editDepDate) handleDepartureDate(editDepDate, editDepDay, editDepWarn, editRetDate, editRetDay, editRetWarn);
            if (editRetDate && editDepDate) handleReturnDate(editRetDate, editRetDay, editRetWarn, editDepDate.value);
        });

        async function showPageDialog({ title, text, icon = 'info', confirmText = 'حسناً', cancelText = 'إلغاء', showCancel = false }) {
            const isDark = document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode');
            if (window.Swal && typeof window.Swal.fire === 'function') {
                return await window.Swal.fire({
                    title,
                    text,
                    icon,
                    showCancelButton: showCancel,
                    confirmButtonText: confirmText,
                    cancelButtonText: cancelText,
                    reverseButtons: true,
                    background: isDark ? '#0b1220' : '#ffffff',
                    color: isDark ? '#e2e8f0' : '#0f172a',
                    confirmButtonColor: isDark ? '#2563eb' : undefined,
                    cancelButtonColor: isDark ? '#475569' : undefined
                });
            }

            if (showCancel) {
                return { isConfirmed: window.confirm(text || title || '') };
            }

            window.alert(text || title || '');
            return { isConfirmed: true };
        }

        async function confirmDialog({ title, text, icon, confirmText, cancelText }) {
            const res = await showPageDialog({
                title,
                text,
                icon: icon || 'question',
                confirmText: confirmText || 'تأكيد',
                cancelText: cancelText || 'إلغاء',
                showCancel: true
            });
            return !!res.isConfirmed;
        }

        $(document).on('submit', 'form.js-confirm-submit', async function(e) {
            if (this.dataset.confirmed === '1') {
                this.dataset.confirmed = '0';
                return true;
            }

            e.preventDefault();
            const ok = await confirmDialog({
                title: this.dataset.confirmTitle || 'تأكيد العملية',
                text: this.dataset.confirmText || 'هل تريد المتابعة؟',
                icon: this.dataset.confirmIcon || 'warning',
                confirmText: this.dataset.confirmButton || 'نعم',
                cancelText: this.dataset.cancelButton || 'إلغاء'
            });

            if (ok) {
                this.dataset.confirmed = '1';
                this.requestSubmit ? this.requestSubmit() : this.submit();
            }

            return false;
        });

        // تهيئة Select2 للمودالات
        $('.select2-modal').each(function() {
            const $p = $(this).closest('.modal');
            $(this).select2({
                dropdownParent: $p,
                width: '100%'
            });
        });

        // مستمعات عامة
        $('#add_traveler_name').on('input', updateDescription);
        $('#add_from_city_id, #add_to_city_id, #add_service_type, #add_service_type_fixed').on('change', updateDescription);

        $('#add_trip_type').on('change', function() {
            if ($(this).val() === 'round_trip') $('#add_return_date_field').show();
            else $('#add_return_date_field').hide().find('input').val('');
        });

        const syncAddServiceTypeUI = function() {
            if (getAddBookingServiceType() === 'bus') {
                $('#add_bus_type_field').show();
            } else {
                $('#add_bus_type_field').hide().find('select').val('');
            }
        };

        $('#add_service_type, #add_service_type_fixed').on('change', syncAddServiceTypeUI);
        syncAddServiceTypeUI();

        $(document).on('input', '.traveler-name-edit', function() { updateEditDescription($(this).closest('.modal-content')); });
        $(document).on('change', '.from-city-edit, .to-city-edit, .service-type-edit', function() { updateEditDescription($(this).closest('.modal-content')); });

        // التحقق عند الإرسال
        $('#addBookingModal form').on('submit', function(e) {
            const sale = parseFloat($('#total_amount').val()) || 0;
            const purchase = parseFloat($('#cost_amount').val()) || 0;
            const received = parseFloat($('#received_amount').val()) || 0;
            const fromCity = $('#add_from_city_id').val();
            const toCity = $('#add_to_city_id').val();

            if (fromCity === toCity) {
                e.preventDefault();
                showPageDialog({
                    title: 'بيانات غير صحيحة',
                    text: 'يجب اختيار مدينتين مختلفتين.',
                    icon: 'warning'
                });
                return false;
            }
            if (received > sale) {
                e.preventDefault();
                showPageDialog({
                    title: 'تحذير مالي',
                    text: 'المبلغ الواصل لا يمكن أن يتجاوز سعر البيع.',
                    icon: 'warning'
                });
                return false;
            }
            if (purchase > sale) {
                e.preventDefault();
                confirmDialog({
                    title: 'تأكيد متابعة الحفظ',
                    text: 'سعر الشراء أكبر من البيع، هل تريد الاستمرار؟',
                    icon: 'warning',
                    confirmText: 'نعم، استمر',
                    cancelText: 'تراجع'
                }).then(ok => {
                    if (ok) {
                        const form = this;
                        form.dataset.confirmed = '1';
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                    }
                });
                return false;
            }
        });
    });
</script>
<?php
require_once 'footer.php';
ob_end_flush();
?>
