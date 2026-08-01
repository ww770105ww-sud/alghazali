<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/functions.php';
require_once '../../includes/accounting_functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

$id = $_POST['id'] ?? 0;
$reason = $_POST['reason'] ?? 'إلغاء السند';
$user_id = $_SESSION['admin_id'];
$user_ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $pdo->beginTransaction();

    // 1. جلب بيانات السند الأصلي
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) throw new Exception("السند غير موجود.");
    if ($voucher['status'] === 'cancelled') throw new Exception("هذا السند ملغي بالفعل.");
    if ($voucher['status'] === 'reversed') throw new Exception("هذا السند تم عكسه بالفعل.");
    if ($voucher['is_reversed']) throw new Exception("هذا السند تم عكسه بالفعل.");
    if ($voucher['original_voucher_id']) throw new Exception("لا يمكن إلغاء سند عكسي.");

    // ======================================================
    // التحقق من الصلاحيات التفصيلية لعكس السند
    // ======================================================
    $user_role_id = (int)($_SESSION['role_id'] ?? 0);
    $user_role    = strtolower($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');
    $is_super = ($user_role === 'developer' || $user_role_id === 2 || $user_role === 'admin');

    if (!$is_super) {
        $ttype        = strtolower($voucher['transaction_type'] ?? '');
        $perm_needed  = null;
        if ($ttype === 'receipt') $perm_needed = 'receipt_reverse';
        elseif ($ttype === 'payment') $perm_needed = 'payment_reverse';

        $allowed = false;
        // دالة التحقق من الصلاحيات (مباشرة هنا لتجنب الاعتماد على ملف خارجي)
        $has_perm = static function ($code) use ($pdo, $user_role_id) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM role_permissions_unified rp
                    JOIN unified_permissions p ON p.id = rp.permission_id
                    WHERE rp.role_id = ? AND p.permission_code = ?
                ");
                $stmt->execute([$user_role_id, $code]);
                return (int)$stmt->fetchColumn() > 0;
            } catch (Throwable $t) { return false; }
        };

        // الصلاحية العامة للتوافق الخلفي
        if ($has_perm('voucher_reverse')) $allowed = true;
        // الصلاحية التفصيلية
        if ($perm_needed && $has_perm($perm_needed)) $allowed = true;

        // استثناءات قديمة للباك وارك
        if ($user_role === 'accountant') $allowed = true;

        if (!$allowed) {
            log_audit($pdo, 'reverse_denied', 'financial_transactions', $id, $voucher, null, "محاولة عكس سند مرفوضة: المستخدم ليس لديه صلاحية {$perm_needed}");
            throw new Exception("ليس لديك صلاحية لعكس هذا السند ({$perm_needed}).");
        }
    }

    // التحقق من وجود سند عكسي مرتبط به مسبقاً
    $stmt_check = $pdo->prepare("SELECT id FROM financial_transactions WHERE original_voucher_id = ? LIMIT 1");
    $stmt_check->execute([$id]);
    if ($stmt_check->fetch()) throw new Exception("يوجد بالفعل سند عكسي مرتبط بهذا السند.");

    // 2. التحقق من إعداد الإلغاء
    $stmt_setting = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'allow_cancel_without_reverse' LIMIT 1");
    $stmt_setting->execute();
    $allow_cancel_without_reverse = (int)($stmt_setting->fetchColumn() ?: 0);

    $reversal_id = null;
    $new_status = 'cancelled';

    if ($allow_cancel_without_reverse === 0 && $voucher['status'] === 'posted') {
        // ======================================================
        // الحالة الأولى: إنشاء سند عكسي
        // ======================================================
        $new_status = 'reversed';
        
        // تحديد نوع السند العكسي (عكس النوع الأصلي)
        $rev_type = ($voucher['transaction_type'] === 'receipt') ? 'payment' : 'receipt';
        $rev_number = fn_get_next_sequence($pdo, $rev_type);
        $rev_desc = 'سند عكسي للسند رقم: ' . $voucher['transaction_number'] . ' | السبب: ' . $reason;

        // إنشاء السند العكسي
        $stmt_rev = $pdo->prepare("
            INSERT INTO financial_transactions (
                transaction_number, transaction_date, branch_id, transaction_type, 
                entity_type, entity_id, amount, currency_id, exchange_rate, 
                cash_bank_account_id, party_account_id, cost_center_id, 
                reference_number, description, created_by, status, 
                original_voucher_id, reference_type, reference_id
            ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, 'reversal', ?)
        ");
        $stmt_rev->execute([
            $rev_number, $voucher['branch_id'], $rev_type,
            $voucher['entity_type'], $voucher['entity_id'], $voucher['amount'],
            $voucher['currency_id'], $voucher['exchange_rate'],
            $voucher['cash_bank_account_id'], $voucher['party_account_id'],
            $voucher['cost_center_id'], $voucher['transaction_number'],
            $rev_desc, $user_id, $id, $id
        ]);
        $reversal_id = $pdo->lastInsertId();

        // جلب أسطر القيد الأصلية لعكسها
        $stmt_lines = $pdo->prepare("SELECT * FROM journal_lines WHERE financial_transaction_id = ?");
        $stmt_lines->execute([$id]);
        $original_lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);

        foreach ($original_lines as $line) {
            $pdo->prepare("
                INSERT INTO journal_lines (
                    financial_transaction_id, account_id, debit, credit, 
                    currency_id, branch_id, cost_center_id, description
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $reversal_id, $line['account_id'],
                $line['credit'], // عكس: الدائن يصبح مديناً
                $line['debit'],  // عكس: المدين يصبح دائناً
                $line['currency_id'], $line['branch_id'],
                $line['cost_center_id'], 'عكس قيد السند: ' . $voucher['transaction_number']
            ]);
        }

        // تحديث الأرصدة للسند العكسي
        if (function_exists('apply_transaction_balances')) {
            apply_transaction_balances($pdo, (int)$reversal_id, 1);
        }

    } else {
        // ======================================================
        // الحالة الثانية: إلغاء السند مباشرة
        // ======================================================
        if ($voucher['status'] === 'posted') {
            // عكس الأرصدة للسند الأصلي لأنه تم ترحيله
            if (function_exists('apply_transaction_balances')) {
                apply_transaction_balances($pdo, (int)$id, -1);
            }
        }
        $new_status = 'cancelled';
    }

    // 3. تحديث السند الأصلي
    $update_stmt = $pdo->prepare("
        UPDATE financial_transactions 
        SET status = ?, 
            is_reversed = ?, 
            reversal_voucher_id = ?, 
            cancelled_at = NOW(), 
            cancelled_by = ?, 
            cancelled_ip = ?, 
            cancellation_reason = ? 
        WHERE id = ?
    ");
    $update_stmt->execute([
        $new_status, 
        ($new_status === 'reversed' ? 1 : 0), 
        $reversal_id, 
        $user_id, $user_ip, $reason, $id
    ]);

    // 4. إعادة حساب مبالغ الفواتير المرتبطة (إن وجدت)
    $stmt_alloc = $pdo->prepare("SELECT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
    $stmt_alloc->execute([$id]);
    $invoice_ids = $stmt_alloc->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($invoice_ids)) {
        php_recalculate_invoice_payments($pdo, $invoice_ids);
    }

    // 5. تسجيل في Audit Log
    $audit_msg = ($new_status === 'reversed') 
        ? "تم عكس السند بنجاح. السند العكسي: " . $reversal_id 
        : "تم إلغاء السند مباشرة.";
    log_audit($pdo, 'cancel', 'financial_transactions', $id, $voucher, null, $audit_msg . " السبب: " . $reason);

    $pdo->commit();
    echo json_encode(['success' => true, 'reversal_id' => $reversal_id, 'status' => $new_status]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
