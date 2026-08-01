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

// جلب بيانات المستخدم الحالي
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->execute([$_SESSION['admin_id']]);
$currentUser = $stmt_user->fetch();

// جلب الإعدادات العامة
$settings_data = getSettings($pdo);

$error = null;

$arabic_months = [
    1 => 'يناير',
    2 => 'فبراير',
    3 => 'مارس',
    4 => 'أبريل',
    5 => 'مايو',
    6 => 'يونيو',
    7 => 'يوليو',
    8 => 'أغسطس',
    9 => 'سبتمبر',
    10 => 'أكتوبر',
    11 => 'نوفمبر',
    12 => 'ديسمبر'
];

function emptyToNull($value)
{
    return (empty($value) || $value == '') ? null : $value;
}

function isWorkVisaTransaction($transactionType): bool
{
    return in_array((string)$transactionType, ['work_visa', '6'], true);
}

function syncWorkVisaProfile(PDO $pdo, int $passportId, array $data, ?int $userId = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO work_visa_profiles (
            passport_id, full_name, full_name_en, passport_number, nationality, gender,
            date_of_birth, passport_issue_date, passport_expiry_date, profession_id,
            phone_number, personal_photo, passport_image, passport_country_code,
            mrz_line_1, mrz_line_2, ocr_raw_text, created_by, updated_by
        ) VALUES (
            :passport_id, :full_name, :full_name_en, :passport_number, :nationality, :gender,
            :date_of_birth, :passport_issue_date, :passport_expiry_date, :profession_id,
            :phone_number, :personal_photo, :passport_image, :passport_country_code,
            :mrz_line_1, :mrz_line_2, :ocr_raw_text, :created_by, :updated_by
        )
        ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name),
            full_name_en = VALUES(full_name_en),
            passport_number = VALUES(passport_number),
            nationality = VALUES(nationality),
            gender = VALUES(gender),
            date_of_birth = VALUES(date_of_birth),
            passport_issue_date = VALUES(passport_issue_date),
            passport_expiry_date = VALUES(passport_expiry_date),
            profession_id = VALUES(profession_id),
            phone_number = VALUES(phone_number),
            personal_photo = COALESCE(VALUES(personal_photo), personal_photo),
            passport_image = COALESCE(VALUES(passport_image), passport_image),
            passport_country_code = VALUES(passport_country_code),
            mrz_line_1 = VALUES(mrz_line_1),
            mrz_line_2 = VALUES(mrz_line_2),
            ocr_raw_text = VALUES(ocr_raw_text),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':passport_id' => $passportId,
        ':full_name' => $data['full_name'] ?? null,
        ':full_name_en' => emptyToNull($data['full_name_en'] ?? null),
        ':passport_number' => $data['passport_number'] ?? null,
        ':nationality' => emptyToNull($data['nationality'] ?? null),
        ':gender' => emptyToNull($data['gender'] ?? null),
        ':date_of_birth' => emptyToNull($data['date_of_birth'] ?? null),
        ':passport_issue_date' => emptyToNull($data['passport_issue_date'] ?? null),
        ':passport_expiry_date' => emptyToNull($data['passport_expiry_date'] ?? null),
        ':profession_id' => emptyToNull($data['profession_id'] ?? null),
        ':phone_number' => emptyToNull($data['phone_number'] ?? null),
        ':personal_photo' => emptyToNull($data['personal_photo'] ?? null),
        ':passport_image' => emptyToNull($data['passport_image'] ?? null),
        ':passport_country_code' => emptyToNull($data['passport_country_code'] ?? null),
        ':mrz_line_1' => emptyToNull($data['mrz_line_1'] ?? null),
        ':mrz_line_2' => emptyToNull($data['mrz_line_2'] ?? null),
        ':ocr_raw_text' => emptyToNull($data['ocr_raw_text'] ?? null),
        ':created_by' => $userId,
        ':updated_by' => $userId,
    ]);
}

function computeInvoicePaymentStatus(float $totalAmount, float $discountAmount, float $receivedAmount): string
{
    $netAmount = max(0, $totalAmount - $discountAmount);
    if ($receivedAmount <= 0.000001) {
        return 'unpaid';
    }
    if ($receivedAmount + 0.000001 >= $netAmount) {
        return 'fully_paid';
    }
    return 'partial';
}

function resolveSupplierAccountId(PDO $pdo, ?int $supplierId): ?int
{
    if (!$supplierId) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
    $stmt->execute([$supplierId]);
    $accountId = $stmt->fetchColumn();
    return $accountId ? (int)$accountId : null;
}

function upsertPassportInvoiceDraft(PDO $pdo, ?int $invoiceId, string $category, array $payload, int $userId): ?int
{
    if ($invoiceId) {
        $stmtExisting = $pdo->prepare("SELECT id, invoice_status FROM invoices WHERE id = ? LIMIT 1");
        $stmtExisting->execute([$invoiceId]);
        $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (($existing['invoice_status'] ?? 'draft') !== 'draft') {
                return (int)$invoiceId;
            }

            $currencyId = $category === 'sales' ? ($payload['sale_currency_id'] ?? null) : ($payload['purchase_currency_id'] ?? null);
            $totalAmount = $category === 'sales' ? ($payload['sale_total_amount'] ?? 0) : ($payload['purchase_total_amount'] ?? 0);
            $discountAmount = $category === 'sales' ? ($payload['discount_amount'] ?? 0) : 0;
            $receivedAmount = $category === 'sales' ? ($payload['received_amount'] ?? 0) : 0;
            $paymentStatus = $category === 'sales'
                ? computeInvoicePaymentStatus((float)$totalAmount, (float)$discountAmount, (float)$receivedAmount)
                : 'unpaid';
            $netAmount = max(0, (float)$totalAmount - (float)$discountAmount);
            $accountId = $category === 'purchase'
                ? resolveSupplierAccountId($pdo, $payload['supplier_id'] ?? null)
                : ($payload['account_id'] ?? null);

            $stmtUpdate = $pdo->prepare("
                UPDATE invoices
                SET invoice_date = ?,
                    branch_id = ?,
                    source_type = ?,
                    source_id = ?,
                    customer_id = ?,
                    supplier_id = ?,
                    agent_id = ?,
                    account_id = ?,
                    currency_id = ?,
                    total_amount = ?,
                    discount = ?,
                    cost_amount = ?,
                    net_amount = ?,
                    amount_received = ?,
                    delivery_type = ?,
                    payment_status = ?,
                    description = ?
                WHERE id = ? AND invoice_status = 'draft'
            ");
            $stmtUpdate->execute([
                $payload['invoice_date'],
                $payload['branch_id'],
                $payload['source_type'],
                $payload['source_id'],
                $category === 'sales' ? ($payload['customer_id'] ?? null) : null,
                $category === 'purchase' ? ($payload['supplier_id'] ?? null) : null,
                $category === 'sales' ? ($payload['agent_id'] ?? null) : null,
                $accountId,
                $currencyId,
                $totalAmount,
                $discountAmount,
                $category === 'sales' ? ($payload['purchase_total_amount'] ?? 0) : $totalAmount,
                $netAmount,
                $receivedAmount,
                $payload['delivery_type'] ?? 'draft',
                $paymentStatus,
                $payload['description'] ?? null,
                $invoiceId,
            ]);
            return (int)$invoiceId;
        }
    }

    require_once '../core/FinanceService.php';
    $financeService = new FinanceService($pdo, $userId);
    return $financeService->createInvoiceDraft([
        'branch_id' => $payload['branch_id'],
        'source_type' => $payload['source_type'],
        'source_id' => $payload['source_id'],
        'source_number' => $payload['source_number'] ?? null,
        'customer_id' => $payload['customer_id'] ?? null,
        'supplier_id' => $payload['supplier_id'] ?? null,
        'agent_id' => $payload['agent_id'] ?? null,
        'account_id' => $category === 'purchase'
            ? resolveSupplierAccountId($pdo, $payload['supplier_id'] ?? null)
            : ($payload['account_id'] ?? null),
        'sale_currency_id' => $payload['sale_currency_id'] ?? null,
        'currency_id' => $payload['purchase_currency_id'] ?? null,
        'purchase_currency_id' => $payload['purchase_currency_id'] ?? null,
        'exchange_rate' => $payload['exchange_rate'] ?? 1,
        'discount_amount' => $payload['discount_amount'] ?? 0,
        'sale_total_amount' => $payload['sale_total_amount'] ?? 0,
        'purchase_total_amount' => $payload['purchase_total_amount'] ?? 0,
        'total_amount' => $payload['sale_total_amount'] ?? 0,
        'purchase_price' => $payload['purchase_total_amount'] ?? 0,
        'delivery_type' => $payload['delivery_type'] ?? 'draft',
        'description' => $payload['description'] ?? '',
        'operation_date' => $payload['invoice_date'] ?? null,
        'invoice_date' => $payload['invoice_date'] ?? null,
        'received_amount' => $payload['received_amount'] ?? 0,
    ], $category);
}

// معالجة طلب AJAX لجلب أسعار الخدمات
if (isset($_GET['get_service_price'])) {
    $service_id = $_GET['service_id'];

    try {
        $target = normalize_service_target($pdo, $_GET['agent_id'] ?? null, $_GET['branch_id'] ?? null);
        $price = get_service_price_config($pdo, $service_id, $target['agent_id'], $target['branch_id']);

        if ($price) {
            $price['user_role'] = $_SESSION['role'] ?? 'employee';
            $price['default_sale_price'] = $price['sale_price'];
        }

        echo json_encode($price ?: null);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// معالجة طلب AJAX لجلب الانتقالات المسموحة
if (isset($_GET['get_transitions'])) {
    $passport_id = $_GET['passport_id'];

    // جلب بيانات المعاملة الحالية
    $stmt = $pdo->prepare("SELECT p.status_id, p.transaction_type, p.branch_id, u.role_id
                           FROM passports p
                           JOIN users u ON u.id = ?
                           WHERE p.id = ?");
    $stmt->execute([$_SESSION['admin_id'], $passport_id]);
    $trx = $stmt->fetch();

    if (!$trx) {
        echo json_encode([]);
        exit();
    }

    // البحث عن سير العمل المناسب
    $workflow = get_workflow_for_transaction($trx['transaction_type'], $trx['branch_id']);

    if (!$workflow) {
        echo json_encode([]);
        exit();
    }

    // جلب الخطوة الحالية في سير العمل بناءً على status_id
    $stmt = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ?");
    $stmt->execute([$workflow['id'], $trx['status_id']]);
    $current_step_id = $stmt->fetchColumn();

    $transitions = [];
    if ($current_step_id) {
        $transitions = get_allowed_transitions($workflow['id'], $current_step_id, $trx['role_id'], $_SESSION['admin_id']);
    }

    // للمدير والمطور، إذا لم نجد خطوات أو انتقالات، نعرض له جميع الخطوات المتاحة في سير العمل كخيار يدوي
    if (empty($transitions) && has_permission('change_passport_status')) {
        $stmt = $pdo->prepare("SELECT id as to_step_id, step_name as to_step_name, color, status_id FROM workflow_steps WHERE workflow_id = ? AND status_id != ?");
        $stmt->execute([$workflow['id'], $trx['status_id']]);
        $transitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($transitions);
    exit();
}



// معالجة طلب AJAX لجلب سجل الحالات
if (isset($_GET['get_status_history'])) {
    $passport_id = $_GET['passport_id'];

    $stmt = $pdo->prepare("
        SELECT l.*, s1.status_name as old_name, s2.status_name as new_name, u.full_name as user_name, r.display_name as role_name
        FROM transaction_status_logs l
        LEFT JOIN statuses s1 ON l.old_status_id = s1.id
        LEFT JOIN statuses s2 ON l.new_status_id = s2.id
        LEFT JOIN users u ON l.changed_by = u.id
        LEFT JOIN roles r ON l.changed_role_id = r.id
        WHERE l.transaction_id = ?
        ORDER BY l.changed_at DESC
    ");
    $stmt->execute([$passport_id]);
    $logs = $stmt->fetchAll();

    if (empty($logs)) {
        echo '<div class="alert alert-info py-2 small">لا يوجد سجل حركات لهذه المعاملة بعد.</div>';
    } else {
        echo '<div class="timeline p-2">';
        foreach ($logs as $log) {
            echo '<div class="timeline-item mb-3 border-bottom pb-2">';
            echo '  <div class="d-flex justify-content-between mb-1">';
            echo '    <span class="fw-bold small">' . h($log['user_name'] ?: 'نظام') . ' <span class="text-muted">(' . h($log['role_name'] ?: 'موظف') . ')</span></span>';
            echo '    <span class="text-muted" style="font-size: 0.7rem;">' . date('Y-m-d H:i', strtotime($log['changed_at'])) . '</span>';
            echo '  </div>';
            echo '  <div class="small">';
            if ($log['old_name']) {
                echo '    <span class="text-muted">نقل من:</span> <span class="badge bg-light text-dark border">' . h($log['old_name']) . '</span>';
                echo '    <i class="fas fa-arrow-left mx-1 text-primary"></i>';
            }
            echo '    <span class="text-muted">إلى:</span> <span class="badge bg-primary">' . h($log['new_name']) . '</span>';
            echo '  </div>';
            if ($log['notes']) {
                echo '  <div class="mt-1 p-2 bg-light rounded small text-muted border-start border-primary border-3">' . h($log['notes']) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    exit();
}

// معالجة طلب الحذف
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    $redirect = $_GET['redirect'] ?? 'passports.php';

    // جلب نوع المعاملة للتحقق من الصلاحيات الخاصة
    $stmt_check = $pdo->prepare("SELECT transaction_type, agent_id, branch_id FROM passports WHERE id = ?");
    $stmt_check->execute([$id]);
    $passport = $stmt_check->fetch();

    if (!$passport) {
        header("Location: $redirect?error=not_found");
        exit();
    }

    $is_work_visa = ($passport['transaction_type'] == 'work_visa' || $passport['transaction_type'] == 6);
    $can_delete = false;

    if (has_permission('admin') || has_permission('developer')) {
        $can_delete = true;
    } elseif ($is_work_visa && has_permission('work_visa_delete')) {
        // التحقق من ملكية السجل للوكيل أو الفرع
        if ($_SESSION['role'] === 'agent' && $passport['agent_id'] == $_SESSION['agent_id']) {
            $can_delete = true;
        } elseif ($_SESSION['role'] === 'branch' && $passport['branch_id'] == $_SESSION['branch_id']) {
            $can_delete = true;
        } elseif ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'developer') {
            $can_delete = true;
        }
    } elseif (has_permission('delete_passport')) {
        $can_delete = true;
    }

    if ($can_delete) {
        $stmt = $pdo->prepare("DELETE FROM passports WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: $redirect?success=3");
        exit();
    } else {
        header("Location: $redirect?error=no_permission");
        exit();
    }
}

// معالجة تغيير حالة المعاملة عبر سير العمل
if (isset($_POST['change_status'])) {
    $passport_id = $_POST['passport_id'];
    $new_step_id = $_POST['new_step_id'];
    $user_id = $_SESSION['admin_id'];
    $notes = $_POST['notes'] ?? 'تغيير الحالة من تفاصيل المعاملة';

    if (change_transaction_status($passport_id, $new_step_id, $user_id, $notes)) {
        if (isset($_POST['ajax'])) {
            echo json_encode(['status' => 'success']);
            exit();
        }
        header("Location: " . ($_POST['redirect'] ?? 'work_visa.php') . "?success=status_changed");
    } else {
        if (isset($_POST['ajax'])) {
            echo json_encode(['status' => 'error', 'message' => 'فشل في تغيير الحالة']);
            exit();
        }
        header("Location: " . ($_POST['redirect'] ?? 'work_visa.php') . "?error=status_failed");
    }
    exit();
}



// دالة معالجة رفع الملفات
// معالجة الإضافة
if (isset($_POST['add_passport'])) {
    $user_id = $_SESSION['admin_id'];
    $branch_id = $_POST['branch_id'] ?? $currentUser['branch_id'];
    $agent_id = $_POST['agent_id'] ?? null;

    $transaction_type = $_POST['transaction_type'] ?? 'visa';
    $status_id = $_POST['status_id'] ?? null;
    $passport_number = $_POST['passport_number'];
    $full_name = $_POST['full_name'];
    $customer_id = null;
    $confirm_duplicate = isset($_POST['confirm_duplicate']) && (string)$_POST['confirm_duplicate'] === '1';
    $default_description = isWorkVisaTransaction($transaction_type)
        ? "معاملة تأشيرة عمل للأخ {$full_name}" . (!empty($passport_number) ? " - رقم الجواز {$passport_number}" : '')
        : "معاملة {$transaction_type} للمسافر: {$full_name}";
    $posted_description = trim((string)($_POST['description'] ?? ''));
    if ($posted_description === '') {
        $posted_description = $default_description;
    }

    // التحقق من تكرار البيانات بناءً على الإعدادات
    $allow_duplicate = $settings_data['allow_duplicate_work_visa'] ?? 0;

    if (!$allow_duplicate && !$confirm_duplicate) {
        // التحقق من تكرار رقم الجواز والاسم لنفس نوع المعاملة
        $stmt_check = $pdo->prepare("SELECT id, agent_id, branch_id FROM passports WHERE (passport_number = ? OR full_name = ?) AND transaction_type = ?");
        $stmt_check->execute([$passport_number, $full_name, $transaction_type]);
        $existing = $stmt_check->fetch();

        if ($existing) {
            $error_msg = "duplicate_passport";
            if ($existing['agent_id'] == $agent_id && !empty($agent_id)) {
                $error_msg = "duplicate_own";
            }
            header("Location: " . ($_POST['redirect'] ?? 'passports.php') . "?error=" . $error_msg . "&number=" . $passport_number . "&name=" . urlencode($full_name));
            exit();
        }
    } elseif (!$confirm_duplicate) {
        // إذا كان التكرار مسموحاً، نكتفي بالتحقق من رقم الجواز فقط إذا كان لنفس الوكيل ونفس النوع (لمنع الأخطاء التقنية البسيطة)
        $stmt_check = $pdo->prepare("SELECT id FROM passports WHERE passport_number = ? AND transaction_type = ? AND agent_id = ? AND status_id NOT IN (5, 14, 19)");
        $stmt_check->execute([$passport_number, $transaction_type, $agent_id]);
        if ($stmt_check->fetch() && !empty($agent_id)) {
            header("Location: " . ($_POST['redirect'] ?? 'passports.php') . "?error=duplicate_own&number=" . $passport_number);
            exit();
        }
    }

    // معالجة المرفقات
    $personal_photo = handleFileUpload('personal_photo', $passport_number, 'personal', $full_name);
    $passport_image = handleFileUpload('passport_image', $passport_number, 'passport', $full_name);
    $exit_image = handleFileUpload('exit_image', $passport_number, 'exit', $full_name);
    $authorization_image = handleFileUpload('authorization_image', $passport_number, 'auth', $full_name);
    $deportation_image = handleFileUpload('deportation_image', $passport_number, 'deport', $full_name);
    $letter_image = handleFileUpload('letter_image', $passport_number, 'letter', $full_name);
    $print_image = handleFileUpload('print_image', $passport_number, 'print', $full_name);

    // إذا لم يتم اختيار حالة يدوياً (مثلاً للوكلاء)، نحدد الحالة الافتراضية من سير العمل
    if (empty($status_id)) {
        $workflow = get_workflow_for_transaction($transaction_type, $branch_id);
        if ($workflow && $workflow['default_status_id']) {
            $stmt = $pdo->prepare("SELECT status_id FROM workflow_steps WHERE id = ?");
            $stmt->execute([$workflow['default_status_id']]);
            $status_id = $stmt->fetchColumn() ?: 1; // 1 كحالة افتراضية للنظام
        } else {
            $status_id = 1;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO passports (
        full_name, full_name_en, passport_number, status_id, batch_id, office_name,
        received_date, sent_to_embassy_date, embassy_exit_date, transport_delivery_date,
        delivery_date, cancellation_date, cancellation_reason, visa_number, visa_issue_date,
        transaction_type, branch_id, agent_id, user_id, customer_id,
        status_changed_at, status_changed_by,
        nationality, gender, date_of_birth, passport_issue_date, passport_expiry_date, profession_id, phone_number,
        personal_photo, passport_image, exit_image, authorization_image, deportation_image, letter_image, print_image,
        description, notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    try {
        $stmt->execute([
            $_POST['full_name'],
            $_POST['full_name_en'] ?? null,
            $passport_number,
            $status_id,
            emptyToNull($_POST['batch_id'] ?? null),
            $_POST['office_name'] ?? null,
            emptyToNull($_POST['received_date'] ?? null),
            emptyToNull($_POST['sent_to_embassy_date'] ?? null),
            emptyToNull($_POST['embassy_exit_date'] ?? null),
            emptyToNull($_POST['transport_delivery_date'] ?? null),
            emptyToNull($_POST['delivery_date'] ?? null),
            emptyToNull($_POST['cancellation_date'] ?? null),
            $_POST['cancellation_reason'] ?? null,
            emptyToNull($_POST['visa_number'] ?? null),
            emptyToNull($_POST['visa_issue_date'] ?? null),
            $transaction_type,
            $branch_id,
            $agent_id,
            $user_id,
            $customer_id,
            $user_id,
            $_POST['nationality'] ?? null,
            $_POST['gender'] ?? null,
            emptyToNull($_POST['date_of_birth'] ?? null),
            emptyToNull($_POST['passport_issue_date'] ?? null),
            emptyToNull($_POST['passport_expiry_date'] ?? null),
            emptyToNull($_POST['profession_id'] ?? null),
            $_POST['phone_number'] ?? null,
            $personal_photo,
            $passport_image,
            $exit_image,
            $authorization_image,
            $deportation_image,
            $letter_image,
            $print_image,
            $posted_description,
            $_POST['notes'] ?? null
        ]);
    } catch (PDOException $e) {
        file_put_contents('db_error.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
        header("Location: " . ($_POST['redirect'] ?? 'passports.php') . "?error=db_error&msg=" . urlencode($e->getMessage()));
        exit();
    }

    $passport_id = $pdo->lastInsertId();

    if (isWorkVisaTransaction($transaction_type)) {
        try {
            syncWorkVisaProfile($pdo, (int)$passport_id, [
                'full_name' => $_POST['full_name'] ?? null,
                'full_name_en' => $_POST['full_name_en'] ?? null,
                'passport_number' => $passport_number,
                'nationality' => $_POST['nationality'] ?? null,
                'gender' => $_POST['gender'] ?? null,
                'date_of_birth' => $_POST['date_of_birth'] ?? null,
                'passport_issue_date' => $_POST['passport_issue_date'] ?? null,
                'passport_expiry_date' => $_POST['passport_expiry_date'] ?? null,
                'profession_id' => $_POST['profession_id'] ?? null,
                'phone_number' => $_POST['phone_number'] ?? null,
                'personal_photo' => $personal_photo,
                'passport_image' => $passport_image,
                'passport_country_code' => $_POST['passport_country_code'] ?? null,
                'mrz_line_1' => $_POST['mrz_line_1'] ?? null,
                'mrz_line_2' => $_POST['mrz_line_2'] ?? null,
                'ocr_raw_text' => $_POST['ocr_raw_text'] ?? null,
            ], $user_id);
        } catch (Exception $e) {
            error_log("Work visa profile sync error (insert): " . $e->getMessage());
        }
    }

    // دمج المحرك المالي الموحد لجميع المعاملات التي تستخدم جدول passports
    try {
        require_once '../includes/ServiceFinancialEngine.php';
        $financialEngine = new ServiceFinancialEngine($pdo, $user_id);
        
        $sale_currency_id = $_POST['sale_currency_id'] ?? ($_POST['currency_id'] ?? 1);
        $purchase_currency_id = $_POST['currency_id'] ?? ($_POST['purchase_currency_id'] ?? $sale_currency_id);
        $exchange_rate = $_POST['exchange_rate'] ?? 1;
        $invoice_date = normalize_datetime_db($_POST['invoice_date'] ?? ($_POST['operation_date'] ?? null));
        $delivery_type = $_POST['delivery_type'] ?? ($_POST['payment_type'] ?? ($settings_data['default_delivery_type'] ?? 'draft'));
        $record_purchase = isset($_POST['record_purchase']) ? (string)$_POST['record_purchase'] : '1';
        $sale_total_amount = $_POST['total_amount'] ?? ($_POST['sale_price'] ?? 0);
        $purchase_total_amount = $_POST['cost_amount'] ?? ($_POST['purchase_price'] ?? 0);
        $received_amount = $_POST['received_amount'] ?? ($_POST['amount_received'] ?? 0);
        $description = $posted_description;
        if ($description === '') {
            $description = $default_description;
        }
        
        $financeResults = $financialEngine->processServiceFinance([
            'service_type'    => $transaction_type,
            'source_type'     => isWorkVisaTransaction($transaction_type) ? 'فيز العمل' : $transaction_type,
            'source_id'       => $passport_id,
            'source_number'   => $passport_number,
            'branch_id'       => $branch_id,
            'customer_id'     => $_POST['customer_id'] ?? null,
            'agent_id'        => $agent_id,
            'supplier_id'     => $_POST['supplier_id'] ?? null,
            'sale_price'      => $sale_total_amount,
            'total_amount'    => $sale_total_amount,
            'discount'        => $_POST['discount'] ?? 0,
            'purchase_price'  => $purchase_total_amount,
            'cost_amount'     => $purchase_total_amount,
            'sale_currency_id'=> $sale_currency_id,
            'currency_id'     => $purchase_currency_id,
            'pur_currency_id' => $purchase_currency_id,
            'exchange_rate'   => $exchange_rate,
            'received_amount' => $received_amount,
            'amount_received' => $received_amount,
            'payment_account_id' => $_POST['account_id'] ?? null,
            'delivery_type'   => $delivery_type,
            'description'     => $description,
            'record_purchase' => $record_purchase,
            'invoice_date'    => $invoice_date,
            'operation_date'  => $invoice_date
        ]);
        
        // ربط المعاملة بفواتير البيع والشراء
        $update_stmt = $pdo->prepare("
            UPDATE passports 
            SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
            WHERE id = ?
        ");
        $update_stmt->execute([
            $financeResults['sales_invoice_id'],
            $financeResults['purchase_invoice_id'] ?? null,
            $passport_id
        ]);
        
    } catch (Exception $e) {
        error_log("Financial Engine Error (passports.php): " . $e->getMessage());
        // لا نوقف العملية إذا فشل المحرك المالي لضمان عدم ضياع بيانات المعاملة الأساسية
    }

    header("Location: " . ($_POST['redirect'] ?? 'passports.php') . "?success=1");
    exit();
}

// معالجة التحديث
if (isset($_POST['update_passport'])) {
    $passport_id = $_POST['passport_id'];
    $user_id = $_SESSION['admin_id'];
    $notes = $_POST['status_notes'] ?? 'تحديث البيانات';

    // جلب الحالة القديمة
    $stmt_old = $pdo->prepare("SELECT status_id FROM passports WHERE id = ?");
    $stmt_old->execute([$passport_id]);
    $old_status_id = $stmt_old->fetchColumn();

    $existing_stmt = $pdo->prepare("SELECT transaction_type, agent_id, branch_id FROM passports WHERE id = ?");
    $existing_stmt->execute([$passport_id]);
    $existing_passport = $existing_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing_passport) {
        header("Location: " . ($_POST['redirect'] ?? 'passports.php') . "?error=not_found");
        exit();
    }

    $passport_number = $_POST['passport_number'];
    $full_name = $_POST['full_name'];

    // معالجة المرفقات الجديدة (فقط إذا تم رفع ملفات جديدة)
    $attachments_sql = "";
    $attachments_params = [];

    $file_types = [
        'personal_photo' => 'personal',
        'passport_image' => 'passport',
        'exit_image' => 'exit',
        'authorization_image' => 'auth',
        'deportation_image' => 'deport',
        'letter_image' => 'letter',
        'print_image' => 'print'
    ];

    foreach ($file_types as $key => $type) {
        $uploaded = handleFileUpload($key, $passport_number, $type, $full_name);
        if ($uploaded) {
            $attachments_sql .= ", $key = ?";
            $attachments_params[] = $uploaded;
        }
    }

    $sql = "UPDATE passports SET
        full_name = ?, full_name_en = ?, passport_number = ?, status_id = ?, batch_id = ?,
        office_name = ?, received_date = ?, sent_to_embassy_date = ?,
        embassy_exit_date = ?, transport_delivery_date = ?, delivery_date = ?, cancellation_date = ?, cancellation_reason = ?,
        visa_number = ?, visa_issue_date = ?,
        transaction_type = ?, branch_id = ?, agent_id = ?, customer_id = ?,
        nationality = ?, gender = ?, date_of_birth = ?, passport_issue_date = ?, passport_expiry_date = ?, profession_id = ?, phone_number = ?" . $attachments_sql;

    $params = [
        $_POST['full_name'],
        $_POST['full_name_en'] ?? null,
        $passport_number,
        $_POST['status_id'] ?? $old_status_id,
        emptyToNull($_POST['batch_id'] ?? null),
        $_POST['office_name'] ?? null,
        emptyToNull($_POST['received_date'] ?? null),
        emptyToNull($_POST['sent_to_embassy_date'] ?? null),
        emptyToNull($_POST['embassy_exit_date'] ?? null),
        emptyToNull($_POST['transport_delivery_date'] ?? null),
        emptyToNull($_POST['delivery_date'] ?? null),
        emptyToNull($_POST['cancellation_date'] ?? null),
        $_POST['cancellation_reason'] ?? null,
        emptyToNull($_POST['visa_number'] ?? null),
        emptyToNull($_POST['visa_issue_date'] ?? null),
        $_POST['transaction_type'] ?? 'visa',
        emptyToNull($_POST['branch_id'] ?? null),
        emptyToNull($_POST['agent_id'] ?? null),
        emptyToNull($_POST['customer_id'] ?? null),
        $_POST['nationality'] ?? null,
        $_POST['gender'] ?? null,
        emptyToNull($_POST['date_of_birth'] ?? null),
        emptyToNull($_POST['passport_issue_date'] ?? null),
        emptyToNull($_POST['passport_expiry_date'] ?? null),
        emptyToNull($_POST['profession_id'] ?? null),
        $_POST['phone_number'] ?? null
    ];

    $params = array_merge($params, $attachments_params);

    // إذا تغيرت الحالة، نستخدم دالة سير العمل
    $new_status_id = $_POST['status_id'] ?? $old_status_id;
    if ($old_status_id != $new_status_id) {
        // نتحقق إذا كان التغيير مسموح به عبر سير العمل (أو إذا كان مديراً)
        // للمدير والمطور نسمح بالتغيير المباشر
        if (has_permission('edit_passports')) {
            $sql .= ", status_changed_at = NOW(), status_changed_by = ?";
            $params[] = $user_id;

            // تسجيل في سجل الحالات
            $stmt_log = $pdo->prepare("INSERT INTO transaction_status_logs (transaction_id, old_status_id, new_status_id, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt_log->execute([$passport_id, $old_status_id, $new_status_id, $user_id, $notes]);
        } else {
            // للمستخدمين العاديين، نستخدم دالة change_transaction_status التي تتبع سير العمل
            if (isset($_POST['workflow_step_id'])) {
                change_transaction_status($passport_id, $_POST['workflow_step_id'], $user_id, $notes);
            }
        }
    }

    $sql .= " WHERE id = ?";
    $params[] = $passport_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if (isWorkVisaTransaction($_POST['transaction_type'] ?? $existing_passport['transaction_type'] ?? null)) {
        try {
            syncWorkVisaProfile($pdo, (int)$passport_id, [
                'full_name' => $_POST['full_name'] ?? null,
                'full_name_en' => $_POST['full_name_en'] ?? null,
                'passport_number' => $passport_number,
                'nationality' => $_POST['nationality'] ?? null,
                'gender' => $_POST['gender'] ?? null,
                'date_of_birth' => $_POST['date_of_birth'] ?? null,
                'passport_issue_date' => $_POST['passport_issue_date'] ?? null,
                'passport_expiry_date' => $_POST['passport_expiry_date'] ?? null,
                'profession_id' => $_POST['profession_id'] ?? null,
                'phone_number' => $_POST['phone_number'] ?? null,
                'passport_country_code' => $_POST['passport_country_code'] ?? null,
                'mrz_line_1' => $_POST['mrz_line_1'] ?? null,
                'mrz_line_2' => $_POST['mrz_line_2'] ?? null,
                'ocr_raw_text' => $_POST['ocr_raw_text'] ?? null,
            ], $user_id);
        } catch (Exception $e) {
            error_log("Work visa profile sync error (update): " . $e->getMessage());
        }

        try {
            $stmtFinancialLinks = $pdo->prepare("SELECT sales_invoice_id, purchase_invoice_id FROM passports WHERE id = ?");
            $stmtFinancialLinks->execute([$passport_id]);
            $financialLinks = $stmtFinancialLinks->fetch(PDO::FETCH_ASSOC) ?: [];

            $invoiceDate = normalize_datetime_db($_POST['invoice_date'] ?? null);
            $saleCurrencyId = (int)($_POST['sale_currency_id'] ?? ($_POST['currency_id'] ?? 1));
            $purchaseCurrencyId = (int)($_POST['currency_id'] ?? ($_POST['purchase_currency_id'] ?? $saleCurrencyId));
            $discountAmount = (float)($_POST['discount'] ?? 0);
            $saleTotalAmount = (float)($_POST['total_amount'] ?? ($_POST['sale_price'] ?? 0));
            $purchaseTotalAmount = (float)($_POST['cost_amount'] ?? ($_POST['purchase_price'] ?? 0));
            $receivedAmount = (float)($_POST['received_amount'] ?? ($_POST['amount_received'] ?? 0));
            $customerId = emptyToNull($_POST['customer_id'] ?? null);
            $agentId = emptyToNull($_POST['agent_id_hidden'] ?? ($_POST['agent_id'] ?? null));
            $accountId = emptyToNull($_POST['account_id'] ?? null);
            $supplierId = emptyToNull($_POST['supplier_id'] ?? null);
            $recordPurchase = isset($_POST['record_purchase']) ? (string)$_POST['record_purchase'] : '1';
            $financialDescription = trim((string)($_POST['description'] ?? ''));
            if ($financialDescription === '') {
                $financialDescription = "معاملة تأشيرة عمل للأخ {$full_name}" . (!empty($passport_number) ? " - رقم الجواز {$passport_number}" : '');
            }

            $financialPayload = [
                'branch_id' => emptyToNull($_POST['branch_id'] ?? null),
                'source_type' => 'فيز العمل',
                'source_id' => (int)$passport_id,
                'source_number' => $passport_number,
                'customer_id' => $customerId,
                'agent_id' => $agentId,
                'supplier_id' => $supplierId,
                'account_id' => $accountId,
                'sale_currency_id' => $saleCurrencyId,
                'purchase_currency_id' => $purchaseCurrencyId,
                'exchange_rate' => (float)($_POST['exchange_rate'] ?? 1),
                'discount_amount' => $discountAmount,
                'sale_total_amount' => $saleTotalAmount,
                'purchase_total_amount' => $purchaseTotalAmount,
                'received_amount' => $receivedAmount,
                'delivery_type' => $_POST['delivery_type'] ?? ($_POST['payment_type'] ?? ($settings_data['default_delivery_type'] ?? 'draft')),
                'invoice_date' => $invoiceDate,
                'description' => $financialDescription,
            ];

            $salesInvoiceId = upsertPassportInvoiceDraft(
                $pdo,
                !empty($financialLinks['sales_invoice_id']) ? (int)$financialLinks['sales_invoice_id'] : null,
                'sales',
                $financialPayload,
                $user_id
            );

            $purchaseInvoiceId = !empty($financialLinks['purchase_invoice_id']) ? (int)$financialLinks['purchase_invoice_id'] : null;
            if ($recordPurchase === '1' && !empty($supplierId) && $purchaseTotalAmount > 0) {
                $purchaseInvoiceId = upsertPassportInvoiceDraft(
                    $pdo,
                    $purchaseInvoiceId,
                    'purchase',
                    $financialPayload,
                    $user_id
                );
            }

            $stmtUpdateLinks = $pdo->prepare("
                UPDATE passports
                SET sales_invoice_id = COALESCE(?, sales_invoice_id),
                    purchase_invoice_id = COALESCE(?, purchase_invoice_id)
                WHERE id = ?
            ");
            $stmtUpdateLinks->execute([
                $salesInvoiceId,
                $purchaseInvoiceId,
                $passport_id
            ]);
        } catch (Exception $e) {
            error_log("Work visa finance sync error (update): " . $e->getMessage());
        }
    }

    // تسجيل الحركة في سجل التتبع (حتى لو لم تتغير الحالة)
    $stmt_log_edit = $pdo->prepare("INSERT INTO transaction_status_logs (transaction_id, new_status_id, changed_by, notes) VALUES (?, ?, ?, ?)");
    $stmt_log_edit->execute([$passport_id, $new_status_id, $user_id, 'تعديل بيانات المعاملة']);

    header("Location: " . ($_POST['redirect'] ?? 'passports.php') . "?success=2");
    exit();
}



require_once 'header.php';

// نظام العزل
$entity_filter = get_entity_filter('p');
$where_clauses = [$entity_filter['clause']];
$params = $entity_filter['params'];

// الفلاتر الإضافية
if (isset($_GET['status_filter']) && !empty($_GET['status_filter'])) {
    $where_clauses[] = "p.status_id = ?";
    $params[] = intval($_GET['status_filter']);
}

if (isset($_GET['batch_filter']) && !empty($_GET['batch_filter'])) {
    $where_clauses[] = "p.batch_id = ?";
    $params[] = intval($_GET['batch_filter']);
} else {
    // افتراضياً نعرض المعاملات في الدفعات المفتوحة فقط للمستخدمين العاديين
    // أما من لديه صلاحية رؤية الكل فيرى كل شيء إلا إذا اختار فلتراً
    if (!has_permission('view_all_passports')) {
        $where_clauses[] = "(b.is_closed = 0 OR b.is_closed IS NULL)";
    }
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$passports = $pdo->prepare("
    SELECT p.*, s.status_name, s.status_color, ser.service_name,
           b.batch_day, b.batch_month, b.batch_year, b.batch_month_name, b.is_closed as batch_closed,
           br.branch_name, ag.agent_name, COALESCE(u.full_name, u.username) as creator_name
    FROM passports p
    LEFT JOIN statuses s ON p.status_id = s.id
    LEFT JOIN services ser ON p.transaction_type = ser.id
    LEFT JOIN batches b ON p.batch_id = b.id
    LEFT JOIN branches br ON p.branch_id = br.id
    LEFT JOIN agents ag ON p.agent_id = ag.id
    LEFT JOIN users u ON p.user_id = u.id
    $where_sql
    ORDER BY p.created_at DESC
");
$passports->execute($params);
$passports = $passports->fetchAll();

$total_passports_count = count($passports);
$statuses = $pdo->query("SELECT * FROM statuses")->fetchAll();
$batches = $pdo->query("SELECT * FROM batches WHERE is_closed = 0 ORDER BY created_at DESC")->fetchAll();
$all_batches = $pdo->query("SELECT * FROM batches ORDER BY created_at DESC")->fetchAll();
$services = $pdo->query("SELECT * FROM services")->fetchAll();

// تحويل الحالات إلى JSON لاستخدامها في JavaScript
$statuses_json = json_encode($statuses);
?>

<style>
    body.page-fullscreen {
        overflow: hidden;
    }

    .page-fullscreen .container-fluid {
        position: fixed !important;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 99999;
        background: var(--bs-body-bg, #f8f9fa);
        overflow-y: auto;
        padding: 20px;
        margin: 0;
        width: 100vw !important;
        max-width: 100vw !important;
    }

    body.theme-dark.page-fullscreen .container-fluid {
        background: #0b1120;
    }

    #fsBtn {
        transition: all 0.2s;
    }

    /* تحسين ظهور الـ Modal فوق القائمة الجانبية وفي المنتصف */
    .modal {
        z-index: 10055 !important;
        /* أعلى من الـ Sidebar */
    }

    .modal-backdrop {
        z-index: 10050 !important;
    }

    .modal-dialog-centered {
        display: flex;
        align-items: center;
        min-height: calc(100% - var(--bs-modal-margin) * 2);
    }

    @media print {

        .no-print,
        .sidebar,
        .navbar,
        .btn,
        .modal,
        .header-navbar,
        .top-navbar,
        .footer,
        .content-header,
        .card-body form {
            display: none !important;
        }

        .main-wrapper,
        .page-wrapper,
        .content-wrapper {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            position: static !important;
        }

        .print-only {
            display: block !important;
        }

        body {
            background: white !important;
            margin: 0 !important;
            padding: 0 !important;
            color: #000 !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
        }

        #printSection {
            width: <?php echo ($settings_data['receipt_layout'] == 'A4' ? '210mm' : ($settings_data['receipt_layout'] == 'Thermal' ? '80mm' : '210mm')); ?> !important;
            height: <?php echo ($settings_data['receipt_layout'] == 'A4' ? '297mm' : ($settings_data['receipt_layout'] == 'Thermal' ? 'auto' : '148mm')); ?> !important;
            padding: 8mm !important;
            box-sizing: border-box !important;
            border: 2px solid #000 !important;
            border-radius: <?php echo ($settings_data['receipt_layout'] == 'Thermal' ? '0' : '20px'); ?> !important;
            margin: 0 auto !important;
            position: relative !important;
            overflow: hidden !important;
            background: #fff !important;
            font-size: <?php echo $settings_data['receipt_font_size'] ?: 14; ?>px !important;
        }

        .header-text-blue {
            color: <?php echo $settings_data['receipt_primary_color'] ?: '#0000FF'; ?> !important;
        }

        .box-rounded {
            border: 1.5px solid #000 !important;
            border-radius: 12px !important;
            padding: 4px 15px !important;
            display: inline-block !important;
            font-weight: bold !important;
            background: #fff !important;
        }

        .box-title {
            font-size: 1.6rem !important;
            min-width: 160px !important;
            text-align: center !important;
        }

        .box-service {
            font-size: 1.2rem !important;
            color: <?php echo $settings_data['receipt_primary_color'] ?: '#0000FF'; ?> !important;
            min-width: 220px !important;
            text-align: center !important;
            margin-top: 5px !important;
            border: 1.5px solid #000 !important;
        }

        .detail-box {
            border: 1.5px solid #000 !important;
            border-radius: 12px !important;
            display: flex !important;
            align-items: center !important;
            overflow: hidden !important;
            background: #fff !important;
            min-width: 180px !important;
            height: 35px !important;
            margin-bottom: 5px !important;
        }

        .detail-label {
            padding: 0 10px !important;
            font-weight: bold !important;
            font-size: 1rem !important;
            flex-shrink: 0 !important;
        }

        .detail-value {
            padding: 0 15px !important;
            font-weight: bold !important;
            font-size: 1.2rem !important;
            flex-grow: 1 !important;
            text-align: center !important;
            border-right: 1.5px solid #000 !important;
            height: 100% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .main-receipt-body {
            border-top: 1.5px solid #000 !important;
            padding-top: 15px !important;
            margin-top: 10px !important;
        }

        .receipt-line {
            margin-bottom: 15px !important;
            font-size: 1.2rem !important;
            display: flex !important;
            align-items: baseline !important;
            font-weight: bold !important;
        }

        .receipt-line span {
            flex-shrink: 0 !important;
        }

        .receipt-line strong {
            border-bottom: 1px dotted #000 !important;
            flex-grow: 1 !important;
            padding: 0 10px !important;
            color: #000 !important;
            margin-right: 5px !important;
            min-height: 25px !important;
            display: inline-block !important;
        }

        .terms-side {
            width: 40% !important;
            font-size: 0.85rem !important;
            color: <?php echo $settings_data['receipt_primary_color'] ?: '#0000FF'; ?> !important;
            line-height: 1.4 !important;
            padding-right: 15px !important;
            border-right: 1px solid #ddd !important;
        }

        .content-side {
            width: 60% !important;
            padding-left: 10px !important;
        }

        .print-footer-new {
            border-top: 1.5px solid #000 !important;
            margin-top: 15px !important;
            padding-top: 8px !important;
            font-size: 0.9rem !important;
            font-weight: bold !important;
            display: flex !important;
            justify-content: space-between !important;
        }

        .footer-bottom-text {
            color: #FF0000 !important;
            text-align: center !important;
            font-size: 0.85rem !important;
            margin-top: 8px !important;
            font-weight: bold !important;
            border-top: 1px solid #eee !important;
            padding-top: 5px !important;
        }

        .row.align-items-center.mb-4 {
            flex-direction: <?php echo ($settings_data['receipt_header_align'] == 'right' ? 'row' : ($settings_data['receipt_header_align'] == 'left' ? 'row-reverse' : 'row')); ?> !important;
        }
    }

    .print-only {
        display: none;
    }
</style>

<!-- نموذج الطباعة المحدث ليتطابق مع الصورة -->
<div id="printSection" class="print-only" dir="rtl">
    <!-- الترويسة -->
    <div class="row align-items-center mb-4">
        <div class="col-4 text-center header-text-blue" style="font-size: 0.9rem; line-height: 1.5;">
            <div class="fw-bold"><?php echo htmlspecialchars($settings['header_address_1'] ?? 'ذمار - معبر جوار'); ?></div>
            <div class="fw-bold"><?php echo htmlspecialchars($settings['header_address_2'] ?? 'مجمع الشايف امام مطعم الفخامة'); ?></div>
            <div class="fw-bold mb-1">حضرموت</div>
            <div class="fw-bold">تلفون: <?php echo htmlspecialchars($settings['header_phone_1'] ?? '00967772653605'); ?></div>
            <div class="fw-bold">هاتف: <?php echo htmlspecialchars($settings['header_phone_2'] ?? '00967773656316'); ?></div>
            <div class="fw-bold">تلفون: <?php echo htmlspecialchars($settings['header_phone_3'] ?? '00967770105284'); ?></div>
        </div>

        <div class="col-4 text-center">
            <?php
            $p_logo = !empty($settings['print_logo']) ? $settings['print_logo'] : $settings['site_logo'];
            if (!empty($p_logo)):
            ?>
                <img src="../assets/uploads/<?php echo $p_logo; ?>" alt="Logo" style="max-height: 90px; width: auto;">
            <?php else: ?>
                <i class="fas fa-bus text-danger" style="font-size: 5rem;"></i>
            <?php endif; ?>
        </div>

        <div class="col-4 text-center header-text-blue" style="line-height: 1.5;">
            <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($settings['header_company_name'] ?? 'مكتب مرتحل'); ?></h3>
            <div class="fw-bold fs-5">للسفريات والسياحة</div>
            <div class="fw-bold">وخدمات الحج والعمرة</div>
            <div class="fw-bold">حجز تذاكر باصات وطيران</div>
            <div class="fw-bold mt-2" style="border-top: 1px solid #000; display: inline-block; padding-top: 2px;">لصاحبها / محمد الغزالي</div>
        </div>
    </div>

    <!-- عنوان السند والخدمة -->
    <div class="text-center mb-4">
        <div class="box-rounded box-title shadow-sm">سند قبض</div>
        <div class="mt-2">
            <div class="box-rounded box-service shadow-sm" id="p_service_name">---</div>
        </div>
    </div>

    <!-- بيانات العملية -->
    <div class="row px-2 mb-3">
        <div class="col-4">
            <div class="detail-box shadow-sm">
                <span class="detail-label">رقم العملية :</span>
                <span class="detail-value" id="p_number">---</span>
            </div>
            <div class="detail-box shadow-sm">
                <span class="detail-label">التاريخ :</span>
                <span class="detail-value" id="p_date">---</span>
            </div>
        </div>
        <div class="col-4"></div>
        <div class="col-4 text-end d-flex flex-column align-items-end">
            <div class="detail-box shadow-sm" style="width: 100% !important;">
                <span class="detail-value" id="p_currency">---</span>
                <span class="detail-label" style="border-right: 1.5px solid #000;">العملة</span>
            </div>
            <div class="detail-box shadow-sm" style="width: 100% !important;">
                <span class="detail-value" id="p_amount_digit">---</span>
                <span class="detail-label" style="border-right: 1.5px solid #000;">المبلغ</span>
            </div>
        </div>
    </div>

    <!-- جسم السند -->
    <div class="main-receipt-body">
        <div class="row g-0">
            <!-- الشروط على اليمين (حسب الصورة) -->
            <div class="col-4 terms-side order-2">
                <div id="p_terms_combined">
                    <!-- سيتم تعبئتها ديناميكياً -->
                </div>
            </div>

            <!-- المحتوى على اليسار (حسب الصورة) -->
            <div class="col-8 content-side order-1 pe-3">
                <div class="receipt-line">
                    <span>استلمت من الأخ/ الإخوة : </span>
                    <strong id="p_payer">---</strong>
                </div>
                <div class="receipt-line">
                    <span>مبلغ وقدره : </span>
                    <strong id="p_amount_words">---</strong>
                </div>
                <div class="receipt-line">
                    <span>وذلك مقابل : </span>
                    <strong id="p_desc">---</strong>
                </div>
                <div class="row gx-2">
                    <div class="col-6">
                        <div class="receipt-line">
                            <span>تاريخ السفر : </span>
                            <strong id="p_travel_date">---</strong>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="receipt-line">
                            <span>المبلغ الباقي : </span>
                            <strong id="p_remaining_balance_new">---</strong>
                        </div>
                    </div>
                </div>
                <div class="receipt-line">
                    <span>المرجع الفاتورة : </span>
                    <strong id="p_ref_no">---</strong>
                </div>
            </div>
        </div>
    </div>

    <!-- التذييل -->
    <div class="print-footer-new">
        <div style="width: 33.33%;">طبع بواسطة : <span id="p_printed_by"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span></div>
        <div style="width: 33.33%; text-align: center;">تاريخ الطباعة: <span id="p_print_datetime"><?php echo date('d/m/Y H:i:s'); ?></span></div>
        <div style="width: 33.33%; text-align: left;">هذا الإشعار آلي ولا يحتاج ختم أو توقيع</div>
    </div>
    <div class="footer-bottom-text">
        I acknowledge that I have read and agree to the terms and conditions in the bond / أقر بأني اطلعت على الشروط والأحكام التي في السند وأوافق عليها
    </div>
</div>

<div class="container-fluid no-print">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-passport me-2 text-primary"></i> إدارة المعاملات / الجوازات <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill ms-2"><?php echo $total_passports_count; ?></span></h3>
        <div class="d-flex gap-2">
            <button id="fsBtn" class="btn btn-outline-secondary rounded-pill px-3" onclick="togglePageFullscreen()" title="ملء الشاشة">
                <i class="fas fa-expand" id="fsIcon"></i> <span id="fsText">ملء الشاشة</span>
            </button>
            <button class="btn btn-primary shadow-sm rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addPassportModal">
                <i class="fas fa-plus me-1"></i> إضافة معاملة جديدة
            </button>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            <?php
            if ($_GET['success'] == 1) echo 'تمت إضافة المعاملة بنجاح.';
            elseif ($_GET['success'] == 2) echo 'تم تحديث بيانات المعاملة بنجاح.';
            elseif ($_GET['success'] == 3) echo 'تم حذف المعاملة بنجاح.';
            elseif ($_GET['success'] == 'posted') echo 'تم ترحيل المعاملة مالياً بنجاح.';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3">
            <?php
            $error_msg = $_GET['error'] ?? '';
            $error_detail = $_GET['msg'] ?? '';
            $error_number = $_GET['number'] ?? '';
            $error_name = $_GET['name'] ?? '';
            
            if ($error_msg == 'not_found') {
                echo 'المعاملة المطلوبة غير موجودة.';
            } elseif ($error_msg == 'no_permission') {
                echo 'ليس لديك صلاحية لحذف هذه المعاملة.';
            } elseif ($error_msg == 'status_failed') {
                echo 'فشل في تغيير الحالة.';
            } elseif ($error_msg == 'duplicate_passport') {
                echo 'يوجد معاملة بنفس رقم الجواز أو الاسم لنفس النوع من الخدمة.';
                if ($error_number) echo "<br><small>رقم الجواز: " . htmlspecialchars($error_number) . "</small>";
                if ($error_name) echo "<br><small>الاسم: " . htmlspecialchars(urldecode($error_name)) . "</small>";
            } elseif ($error_msg == 'duplicate_own') {
                echo 'يوجد لديك معاملة بنفس رقم الجواز لنفس نوع الخدمة.';
                if ($error_number) echo "<br><small>رقم الجواز: " . htmlspecialchars($error_number) . "</small>";
            } elseif ($error_msg == 'db_error') {
                echo 'حدث خطأ في قاعدة البيانات.';
                if ($error_detail) {
                    $arabic_error = $error_detail;
                    if (strpos($error_detail, 'Duplicate entry') !== false) {
                        $arabic_error = 'تكرار في البيانات: هذا الرقم موجود بالفعل.';
                    } elseif (strpos($error_detail, 'Integrity constraint violation') !== false) {
                        $arabic_error = 'خطأ في التقييد: يوجد سجل مرتبط بهذه البيانات.';
                    }
                    echo "<br><small class='text-light'>" . htmlspecialchars($arabic_error) . "</small>";
                }
            } else {
                echo 'حدث خطأ غير متوقع.';
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4 border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-4"><input type="text" id="dynamicSearch" class="form-control bg-light border-0" placeholder="بحث بالاسم أو الرقم..."></div>
                <div class="col-md-3">
                    <select name="status_filter" class="form-select bg-light border-0" onchange="this.form.submit()">
                        <option value="">كل الحالات</option>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo (isset($_GET['status_filter']) && h($_GET['status_filter'] ?? '') == $s['id']) ? 'selected' : ''; ?>><?php echo $s['status_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="batch_filter" class="form-select bg-light border-0" onchange="this.form.submit()">
                        <option value="">كل الدفعات المفتوحة</option>
                        <?php foreach ($all_batches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo (isset($_GET['batch_filter']) && h($_GET['batch_filter'] ?? '') == $b['id']) ? 'selected' : ''; ?>>
                                دفعة <?php echo $b['batch_month_name'] ?: ($arabic_months[$b['batch_month']] ?? ''); ?> (<?php echo $b['batch_day'] . '/' . $b['batch_month']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><a href="passports.php" class="btn btn-outline-secondary w-100 rounded-pill">إعادة ضبط</a></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="passportsTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">المعاملة / الاسم</th>
                            <th>رقم الجواز</th>
                            <th>تاريخ الإضافة</th>
                            <th>المستخدم</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($passports as $p):
                        ?>
                            <tr>
                                <td class="px-4" data-label="المعاملة / الاسم">
                                    <?php if ($p['service_name'] !== 'فيز العمل'): ?>
                                        <div class="fw-bold text-primary"><?php echo htmlspecialchars($p['service_name']); ?></div>
                                    <?php endif; ?>
                                    <div class="small fw-bold text-dark"><?php echo htmlspecialchars($p['full_name']); ?></div>
                                </td>
                                 <td data-label="رقم الجواز"><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($p['passport_number']); ?></span></td>
                                 <td data-label="تاريخ الإضافة">
                                    <div class="text-primary small fw-bold" style="font-size: 0.75rem;">
                                        <i class="far fa-clock me-1"></i> <?php echo date('H:i', strtotime($p['created_at'])); ?>
                                    </div>
                                    <div class="text-muted small" style="font-size: 0.7rem;">
                                        <?php echo date('Y-m-d', strtotime($p['created_at'])); ?>
                                    </div>
                                </td>
                                 <td data-label="المستخدم">
                                    <div class="small fw-bold text-secondary">
                                        <i class="fas fa-user-edit me-1 opacity-50"></i> <?php echo htmlspecialchars($p['creator_name'] ?: '---'); ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">

                                        <button class="btn btn-sm btn-outline-info rounded-pill p-1 px-2" title="تعديل البيانات" data-bs-toggle="modal" data-bs-target="#editPassportModal<?php echo $p['id']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button class="btn btn-sm btn-outline-primary rounded-pill p-1 px-2" title="تغيير الحالة" data-bs-toggle="modal" data-bs-target="#editPassportModal<?php echo $p['id']; ?>" onclick="setTimeout(() => { document.querySelector('#statusSection<?php echo $p['id']; ?>').classList.add('show'); }, 500)">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>

                                        <a href="passports.php?delete_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-danger rounded-pill p-1 px-2" title="حذف" onclick="return confirm('هل أنت متأكد من الحذف؟')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>



                            <!-- Modal التعديل -->
                            <div class="modal fade" id="editPassportModal<?php echo $p['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-xl modal-dialog-centered">
                                    <div class="modal-content border-0 shadow rounded-4">
                                        <form method="POST">
                                            <div class="modal-header bg-info text-white border-0">
                                                <h5 class="modal-title fw-bold">تعديل بيانات المعاملة</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <input type="hidden" name="passport_id" value="<?php echo $p['id']; ?>">

                                                <div class="row g-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold">الاسم الكامل</label>
                                                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($p['full_name']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold">رقم الجواز</label>
                                                        <input type="text" name="passport_number" class="form-control" value="<?php echo htmlspecialchars($p['passport_number']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label fw-bold">نوع المعاملة</label>
                                                        <select name="transaction_type" class="form-select service-select" required>
                                                            <option value="">اختر خدمة</option>
                                                            <?php foreach ($services as $s): ?>
                                                                <option value="<?php echo $s['id']; ?>" <?php echo ($p['transaction_type'] == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['service_name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <label for="notes" class="form-label small text-muted mb-1">ملاحظات إضافية</label>
                                                        <textarea class="form-control form-control-sm rounded-3" id="notes" name="notes" rows="2"><?php echo htmlspecialchars($p['notes'] ?? ''); ?></textarea>
                                                    </div>

                                                    <!-- قسم الحالة القابل للطي -->
                                                    <div class="collapse col-12" id="statusSection<?php echo $p['id']; ?>">
                                                        <div class="card card-body bg-light-subtle border-primary border-opacity-25 rounded-4 shadow-sm mb-3">
                                                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-tasks me-2"></i> إدارة الحالة والمتابعة</h6>
                                                            <div class="row g-3">
                                                                <div class="col-md-5">
                                                                    <label class="form-label fw-bold small">الحالة الحالية</label>
                                                                    <div class="d-flex align-items-center gap-3 bg-white p-2 rounded-pill border shadow-sm">
                                                                        <span class="badge rounded-pill p-2 px-4 flex-grow-1" style="background-color: <?php echo $p['status_color']; ?>; color: #fff; font-size: 0.95rem;">
                                                                            <i class="fas fa-info-circle me-1"></i> <?php echo htmlspecialchars($p['status_name']); ?>
                                                                        </span>
                                                                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="collapse" data-bs-target="#workflowSection<?php echo $p['id']; ?>">
                                                                            <i class="fas fa-exchange-alt me-1"></i> تحديث الحالة
                                                                        </button>
                                                                    </div>

                                                                    <div class="collapse mt-3 p-3 bg-white rounded-4 border shadow-sm" id="workflowSection<?php echo $p['id']; ?>">
                                                                        <label class="form-label small fw-bold text-muted mb-2">اختر الحالة الجديدة المتاحة:</label>
                                                                        <div class="d-flex flex-wrap gap-2 transition-buttons-container" data-passport-id="<?php echo $p['id']; ?>">
                                                                            <!-- سيتم تحميل الأزرار عبر JS -->
                                                                            <div class="text-center w-100 py-2">
                                                                                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                                                            </div>
                                                                        </div>
                                                                        <input type="hidden" name="status_id" id="status_id_<?php echo $p['id']; ?>" value="<?php echo $p['status_id']; ?>">
                                                                        <input type="hidden" name="workflow_step_id" id="step_id_<?php echo $p['id']; ?>" value="">

                                                                        <div class="mt-3">
                                                                            <label class="form-label small fw-bold text-muted">ملاحظات التغيير:</label>
                                                                            <textarea name="status_notes" class="form-control form-control-sm rounded-3" rows="2" placeholder="أدخل سبباً أو ملاحظة لتغيير الحالة..."></textarea>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div class="col-md-7 border-start ps-4">
                                                                    <label class="form-label fw-bold small text-muted mb-3">تفاصيل المتابعة والمواعيد:</label>
                                                                    <div class="row g-2">
                                                                        <div class="col-md-4">
                                                                            <label class="form-label extra-small fw-bold">تاريخ الاستلام</label>
                                                                            <input type="date" name="received_date" class="form-control form-control-sm" value="<?php echo $p["received_date"]; ?>">
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <label class="form-label extra-small fw-bold">إرسال للسفارة</label>
                                                                            <input type="date" name="sent_to_embassy_date" class="form-control form-control-sm" value="<?php echo $p["sent_to_embassy_date"]; ?>">
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <label class="form-label extra-small fw-bold">خروج سفارة</label>
                                                                            <input type="date" name="embassy_exit_date" class="form-control form-control-sm" value="<?php echo $p["embassy_exit_date"]; ?>">
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <label class="form-label extra-small fw-bold">تاريخ التسليم</label>
                                                                            <input type="date" name="delivery_date" class="form-control form-control-sm" value="<?php echo $p["delivery_date"]; ?>">
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <label class="form-label extra-small fw-bold">رقم التأشيرة</label>
                                                                            <input type="text" name="visa_number" class="form-control form-control-sm" value="<?php echo htmlspecialchars($p["visa_number"]); ?>">
                                                                        </div>
                                                                        <div class="col-md-4">
                                                                            <label class="form-label extra-small fw-bold">تاريخ التأشيرة</label>
                                                                            <input type="date" name="visa_issue_date" class="form-control form-control-sm" value="<?php echo $p["visa_issue_date"]; ?>">
                                                                        </div>
                                                                        <div class="col-12">
                                                                            <label class="form-label extra-small fw-bold">سبب الإلغاء / الرفض</label>
                                                                            <input type="text" name="cancellation_reason" class="form-control form-control-sm" value="<?php echo htmlspecialchars($p["cancellation_reason"]); ?>">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="col-12 mt-4">
                                                        <ul class="nav nav-tabs border-0" role="tablist">
                                                            <?php if (has_permission('edit_passports')): ?>
                                                                <li class="nav-item">
                                                                    <a class="nav-link active border-0 fw-bold" data-bs-toggle="tab" href="#details<?php echo $p['id']; ?>">التفاصيل الإضافية</a>
                                                                </li>
                                                            <?php endif; ?>
                                                            <li class="nav-item">
                                                                <a class="nav-link <?php echo (!has_permission('edit_passports')) ? 'active' : ''; ?> border-0 fw-bold" data-bs-toggle="tab" href="#history<?php echo $p['id']; ?>" onclick="loadStatusHistory(<?php echo $p['id']; ?>)">سجل الحالات</a>
                                                            </li>
                                                        </ul>
                                                        <div class="tab-content mt-3">
                                                            <?php if (has_permission('edit_passports')): ?>
                                                                <div id="details<?php echo $p['id']; ?>" class="tab-pane active">
                                                                    <div class="row g-3">
                                                                        <div class="col-md-4">
                                                                            <label class="form-label fw-bold">اسم المكتب / الجهة</label>
                                                                            <input type="text" name="office_name" class="form-control" value="<?php echo htmlspecialchars($p['office_name']); ?>">
                                                                        </div>
                                                                        <div class="col-md-8">
                                                                            <label class="form-label fw-bold">ملاحظات إضافية</label>
                                                                            <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($p['notes'] ?? ''); ?></textarea>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div id="history<?php echo $p['id']; ?>" class="tab-pane <?php echo (!has_permission('edit_passports')) ? 'active show' : 'fade'; ?>">
                                                                <div class="status-history-container" id="history_content_<?php echo $p['id']; ?>">
                                                                    <div class="text-center py-4">
                                                                        <div class="spinner-border text-primary" role="status"></div>
                                                                        <p class="mt-2 text-muted small">جاري تحميل السجل...</p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0">
                                                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
                                                <button type="submit" name="update_passport" class="btn btn-info text-white rounded-pill px-4">حفظ التغييرات</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal الإضافة -->
<div class="modal fade" id="addPassportModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form method="POST">
                <div class="modal-header bg-primary text-white border-0">
                    <h5 class="modal-title fw-bold">إضافة معاملة جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الاسم الكامل للمسافر</label>
                            <input type="text" name="full_name" class="form-control" placeholder="أدخل اسم المسافر" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">رقم الجواز</label>
                            <input type="text" name="passport_number" class="form-control" placeholder="أدخل رقم الجواز" required>
                        </div>
                        <div class="col-md-4 main-customer-field d-none">
                            <label class="form-label fw-bold">العميل (اختياري)</label>
                            <select name="customer_id" class="form-select">
                                <option value="">اختر عميل</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">نوع المعاملة (الخدمة)</label>
                            <select name="transaction_type" class="form-select service-select" required>
                                <option value="">اختر خدمة</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['service_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($_SESSION['role'] === 'agent'): ?>
                            <input type="hidden" name="branch_id" value="<?php echo $currentUser['branch_id']; ?>">
                            <input type="hidden" name="agent_id" value="<?php echo $currentUser['agent_id']; ?>">
                        <?php elseif (in_array($_SESSION['role'], ['branch_manager', 'branch_user'])): ?>
                            <input type="hidden" name="branch_id" value="<?php echo $currentUser['branch_id']; ?>">
                            <input type="hidden" name="agent_id" value="">
                        <?php else: ?>
                            <input type="hidden" name="branch_id" value="<?php echo $currentUser['branch_id']; ?>">
                            <input type="hidden" name="agent_id" value="">
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label for="notes" class="form-label small text-muted mb-1">ملاحظات إضافية</label>
                            <textarea class="form-control form-control-sm rounded-3" id="notes" name="notes" rows="1"></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">الحالة الأولية</label>
                            <select name="status_id" class="form-select status-select" required <?php echo ($_SESSION['role'] === 'agent' || $_SESSION['role'] === 'branch_manager') ? 'readonly' : ''; ?>>
                                <?php foreach ($statuses as $s): ?>
                                    <?php
                                    // إذا كان وكيل أو فرع، يظهر له فقط حالة "جديد"
                                    if (($_SESSION['role'] === 'agent' || $_SESSION['role'] === 'branch_manager') && $s['id'] != 1) continue;
                                    ?>
                                    <option value="<?php echo $s["id"]; ?>" <?php echo ($s['id'] == 1) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s["status_name"]); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الدفعة (اختياري)</label>
                            <select name="batch_id" class="form-select">
                                <option value="">بدون دفعة</option>
                                <?php foreach ($batches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>">دفعة <?php echo $b['batch_month_name'] ?: ($arabic_months[$b['batch_month']] ?? ''); ?> (<?php echo $b['batch_day'] . '/' . $b['batch_month']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (in_array($_SESSION['role'], ['admin', 'developer', 'data_entry_relayer', 'accountant'])): ?>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">تاريخ الاستلام</label>
                                <input type="date" name="received_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="received_date" value="<?php echo date('Y-m-d'); ?>">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_passport" class="btn btn-primary rounded-pill px-4">إضافة المعاملة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('dynamicSearch').addEventListener('keyup', function() {
        let filter = this.value.toUpperCase();
        let table = document.getElementById('passportsTable');
        let tr = table.getElementsByTagName('tr');

        for (let i = 1; i < tr.length; i++) {
            let tdName = tr[i].getElementsByTagName('td')[0];
            let tdPassport = tr[i].getElementsByTagName('td')[1];
            if (tdName || tdPassport) {
                let nameValue = tdName.textContent || tdName.innerText;
                let passportValue = tdPassport.textContent || tdPassport.innerText;
                if (nameValue.toUpperCase().indexOf(filter) > -1 || passportValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    });

    function togglePageFullscreen() {
        const body = document.body;
        const icon = document.getElementById("fsIcon");
        const text = document.getElementById("fsText");
        body.classList.toggle("page-fullscreen");
        if (body.classList.contains("page-fullscreen")) {
            icon.classList.replace("fa-expand", "fa-compress");
            text.textContent = "الخروج";
        } else {
            icon.classList.replace("fa-compress", "fa-expand");
            text.textContent = "ملء الشاشة";
        }
    }

    // نظام سير العمل - جلب الانتقالات
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('show.bs.modal', function() {
            const container = this.querySelector('.transition-buttons-container');
            if (!container) return;

            const passportId = container.dataset.passportId;
            fetch(`passports.php?get_transitions=1&passport_id=${passportId}`)
                .then(response => response.json())
                .then(data => {
                    container.innerHTML = '';
                    if (data.length === 0) {
                        container.innerHTML = '<div class="alert alert-warning py-2 small w-100">لا توجد حالات متاحة حالياً وفق الصلاحيات الممنوحة لك.</div>';
                        return;
                    }

                    data.forEach(trans => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn btn-sm btn-outline-dark rounded-pill px-3 transition-btn';
                        btn.style.borderColor = trans.color;
                        btn.style.color = trans.color;
                        btn.innerHTML = `<i class="fas fa-arrow-left me-1 small"></i> ${trans.to_step_name}`;
                        btn.onclick = function() {
                            // إزالة التحديد عن الأزرار الأخرى
                            container.querySelectorAll('.transition-btn').forEach(b => {
                                b.classList.remove('active');
                                b.style.backgroundColor = 'transparent';
                                b.style.color = b.style.borderColor;
                            });

                            // تحديد الزر الحالي
                            this.classList.add('active');
                            this.style.backgroundColor = trans.color;
                            this.style.color = '#fff';

                            // تحديث القيم المخفية
                            document.getElementById(`status_id_${passportId}`).value = trans.status_id;
                            document.getElementById(`step_id_${passportId}`).value = trans.id;
                        };
                        container.appendChild(btn);
                    });
                });
        });
    });

    // تحميل سجل الحالات
    function loadStatusHistory(passportId) {
        const container = document.getElementById(`history_content_${passportId}`);
        fetch(`passports.php?get_status_history=1&passport_id=${passportId}`)
            .then(response => response.text())
            .then(html => {
                container.innerHTML = html;
            });
    }
</script>

<?php require_once 'footer.php'; ?>
