<?php
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_functions.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('ajax_work_visa.php: PDO is not initialized after loading db.php');
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'تعذر الاتصال بقاعدة البيانات. يرجى المحاولة لاحقاً.'
    ]);
    exit();
}

// session_start() is already called in includes/functions.php
$user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;

if (!$user_id) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// ظآ،ظقظآ ظآظعظآظق ظآظغ ظآظقظقظآظغظآظآظق ظآظقظآ­ظآظقظع
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->execute([$user_id]);
$currentUser = $stmt_user->fetch();

$action = $_GET['action'] ?? '';
if (!empty($action)) {
    if (ob_get_length()) ob_clean();
}
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_work_visa:' . $action, 60, 60);
require_csrf_for_actions([
    'approve_finance',
    'post_finance',
    'unpost_finance',
    'delete_finance',
    'process_transition',
    'relayer_verify_item',
    'update_checklist',
    'add_relayer_note',
    'mark_resolved',
    'mark_notifs_read',
    'mark_single_notif_read'
]);

function work_visa_parse_allowed_nationalities(?string $value): array
{
    if (!$value) {
        return [];
    }

    $items = preg_split('/[\r\n,،]+/u', $value);
    $items = array_map(static fn($item) => trim((string)$item), $items ?: []);
    return array_values(array_filter($items, static fn($item) => $item !== ''));
}

function work_visa_requirement_matches_gender(string $requirementGender, ?string $selectedGender): bool
{
    $requirementGender = strtolower(trim($requirementGender ?: 'both'));
    $selectedGender = strtolower(trim((string)$selectedGender));

    if ($requirementGender === 'both' || $requirementGender === '') {
        return true;
    }

    if ($selectedGender === '') {
        return false;
    }

    return $requirementGender === $selectedGender;
}

function work_visa_format_account_display(?array $row): string
{
    if (!$row) {
        return '---';
    }

    $code = trim((string)($row['account_code'] ?? ''));
    $name = trim((string)($row['account_name_ar'] ?? ''));
    if ($code !== '' && $name !== '') {
        return $code . ' - ' . $name;
    }

    return $name !== '' ? $name : '---';
}

function work_visa_load_invoice_details(PDO $pdo, $invoiceId): ?array
{
    if (empty($invoiceId)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT inv.id, inv.invoice_number, inv.invoice_date, inv.invoice_category, inv.delivery_type,
               inv.payment_status, inv.invoice_status, inv.total_amount, inv.amount_received,
               inv.description, inv.customer_id, inv.supplier_id, inv.agent_id, inv.account_id,
               cur.currency_name, cur.currency_symbol,
               acc.account_code, acc.account_name_ar,
               cust.full_name AS customer_name,
               sup.supplier_name,
               ag.agent_name
        FROM invoices inv
        LEFT JOIN currencies cur ON cur.id = inv.currency_id
        LEFT JOIN unified_accounts acc ON acc.id = inv.account_id
        LEFT JOIN customers cust ON cust.id = inv.customer_id
        LEFT JOIN suppliers sup ON sup.id = inv.supplier_id
        LEFT JOIN agents ag ON ag.id = inv.agent_id
        WHERE inv.id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return null;
    }

    $invoice['account_display'] = work_visa_format_account_display($invoice);
    $invoice['counterparty_name'] = $invoice['invoice_category'] === 'purchase'
        ? ($invoice['supplier_name'] ?: '---')
        : ($invoice['customer_name'] ?: ($invoice['agent_name'] ?: '---'));

    return $invoice;
}

function work_visa_reset_invoice_to_draft(PDO $pdo, int $invoiceId, int $userId): void
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

function work_visa_collect_invoice_ids_by_scope(array $passportRow, string $scope): array
{
    $scope = strtolower(trim($scope));
    $map = [
        'sales' => !empty($passportRow['sales_invoice_id']) ? (int)$passportRow['sales_invoice_id'] : null,
        'purchase' => !empty($passportRow['purchase_invoice_id']) ? (int)$passportRow['purchase_invoice_id'] : null,
    ];

    if ($scope === 'sales') {
        return array_values(array_filter([$map['sales']]));
    }
    if ($scope === 'purchase') {
        return array_values(array_filter([$map['purchase']]));
    }

    return array_values(array_filter([$map['sales'], $map['purchase']]));
}

function work_visa_has_posted_invoice(PDO $pdo, array $passportRow): bool
{
    $invoiceIds = work_visa_collect_invoice_ids_by_scope($passportRow, 'all');
    if (empty($invoiceIds)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE id IN ($placeholders) AND invoice_status = 'posted'");
    $stmt->execute($invoiceIds);
    return ((int)$stmt->fetchColumn()) > 0;
}

function work_visa_user_can_delete(array $passportRow): bool
{
    if (has_permission('admin') || has_permission('developer')) {
        return true;
    }

    if (!has_permission('work_visa_delete')) {
        return false;
    }

    $role = (string)($_SESSION['role'] ?? '');
    if ($role === 'agent') {
        return (int)($passportRow['agent_id'] ?? 0) === (int)($_SESSION['agent_id'] ?? 0);
    }

    if ($role === 'branch') {
        return (int)($passportRow['branch_id'] ?? 0) === (int)($_SESSION['branch_id'] ?? 0);
    }

    return in_array($role, ['admin', 'developer'], true);
}

function work_visa_assert_invoice_can_be_deleted(PDO $pdo, int $invoiceId): array
{
    $stmtInvoice = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmtInvoice->execute([$invoiceId]);
    $invoice = $stmtInvoice->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        throw new Exception('الفاتورة المطلوبة غير موجودة');
    }

    $allowedStatuses = ['draft', 'cancelled'];
    if (!in_array((string)($invoice['invoice_status'] ?? 'draft'), $allowedStatuses, true)) {
        throw new Exception('لا يمكن حذف فاتورة مرحلة. أعدها إلى المسودة أولاً.');
    }

    $stmtVouchers = $pdo->prepare("
        SELECT ft.id, ft.transaction_number, ft.transaction_type
        FROM payment_allocations pa
        JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
        WHERE pa.invoice_id = ?
          AND ft.status = 'posted'
          AND NOT (ft.reference_id = ? AND ft.reference_type = 'invoice')
    ");
    $stmtVouchers->execute([$invoiceId, $invoiceId]);
    $externalVouchers = $stmtVouchers->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($externalVouchers)) {
        $voucherNumbers = array_map(static function ($row) {
            return (string)($row['transaction_number'] ?? ('#' . ($row['id'] ?? '')));
        }, $externalVouchers);
        throw new Exception('لا يمكن الحذف لوجود سندات خارجية مرحلة مرتبطة بهذه الفاتورة: ' . implode('، ', $voucherNumbers));
    }

    return $invoice;
}

function work_visa_delete_invoice_record(PDO $pdo, int $invoiceId): void
{
    $invoice = work_visa_assert_invoice_can_be_deleted($pdo, $invoiceId);
    $invoiceNumber = (string)($invoice['invoice_number'] ?? '');
    $numericInvoiceNumber = preg_replace('/[^0-9]/', '', $invoiceNumber);
    $recalculateInvoiceIds = [];

    if ($invoiceNumber !== '') {
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
            $numericInvoiceNumber,
            $numericInvoiceNumber,
            $invoiceId
        ]);

        foreach ($stmtFt->fetchAll(PDO::FETCH_COLUMN) as $ftId) {
            $ftId = (int)$ftId;
            if ($ftId <= 0) {
                continue;
            }

            $stmtVoucher = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
            $stmtVoucher->execute([$ftId]);
            $voucher = $stmtVoucher->fetch(PDO::FETCH_ASSOC);
            if ($voucher && ($voucher['status'] ?? '') === 'posted' && !balances_triggers_enabled($pdo)) {
                apply_transaction_balances($pdo, $ftId, -1);
            }

            $stmtAllocs = $pdo->prepare("SELECT DISTINCT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
            $stmtAllocs->execute([$ftId]);
            foreach ($stmtAllocs->fetchAll(PDO::FETCH_COLUMN) as $linkedInvoiceId) {
                $linkedInvoiceId = (int)$linkedInvoiceId;
                if ($linkedInvoiceId > 0 && $linkedInvoiceId !== $invoiceId) {
                    $recalculateInvoiceIds[] = $linkedInvoiceId;
                }
            }

            $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$ftId]);
            $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ftId]);
            $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$ftId]);
        }
    }

    $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoiceId]);
    log_audit($pdo, 'delete', 'invoices', $invoiceId, $invoice, null, 'حذف فاتورة من معاملة فيز العمل');

    foreach (array_values(array_unique($recalculateInvoiceIds)) as $linkedInvoiceId) {
        php_recalculate_invoice_payment($pdo, (int)$linkedInvoiceId);
    }
}

if ($action === 'get_work_visa_details') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.*, prof.name_ar as profession_name, br.branch_name, ag.agent_name, cust.full_name as customer_name,
                   s.status_name, COALESCE(s.status_color, '#6c757d') as status_color,
                   COALESCE(u.full_name, u.username) as creator_name,
                   CONCAT(batch.batch_day, ' - ', batch.batch_month_name, ' - ', batch.batch_year) as batch_name
            FROM passports p
            LEFT JOIN professions prof ON p.profession_id = prof.id
            LEFT JOIN branches br ON p.branch_id = br.id
            LEFT JOIN agents ag ON p.agent_id = ag.id
            LEFT JOIN customers cust ON p.customer_id = cust.id
            LEFT JOIN statuses s ON p.status_id = s.id
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN batches batch ON p.batch_id = batch.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // ظآظقظغظآ­ظقظق ظقظق  ظآظقظآظقظآظآ­ظعظآ (Data Isolation Security)
        if ($data) {
            $is_super_user = in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer']);
            $can_view_all = has_permission('view_all_passports');

            if (!$is_super_user && !$can_view_all) {
                if (!empty($currentUser['agent_id']) && $data['agent_id'] != $currentUser['agent_id']) {
                    throw new Exception('لا تملك صلاحية الوصول إلى هذه المعاملة.');
                }
                if (!empty($currentUser['branch_id']) && $data['branch_id'] != $currentUser['branch_id']) {
                    throw new Exception('لا تملك صلاحية الوصول إلى هذه المعاملة ضمن هذا الفرع.');
                }
            }
        }

        if ($data) {
            // Get current step settings
            $stmt_step = $pdo->prepare("SELECT show_checklist FROM workflow_steps WHERE id = ?");
            $stmt_step->execute([$data['status_id']]);
            $step_settings = $stmt_step->fetch();
            $data['show_checklist'] = $step_settings['show_checklist'] ?? 0;

            // 1. Check if it's part of a group
            $stmt_members = $pdo->prepare("SELECT id, full_name, passport_number, status_id FROM passports WHERE parent_id = ? OR (id = ? AND parent_id IS NOT NULL)");
            $stmt_members->execute([$id, $id]);
            $data['group_members'] = $stmt_members->fetchAll(PDO::FETCH_ASSOC);

            // 2. Checklist
            $stmt_check = $pdo->prepare("
                SELECT pr.id as requirement_id, pr.requirement_name,
                       COALESCE(wvc.is_completed, 0) as is_completed,
                       COALESCE(wvc.relayer_verified, 0) as relayer_verified,
                       wvc.verified_at,
                       COALESCE(u.full_name, u.username) as verifier_name
                FROM profession_requirements pr
                LEFT JOIN work_visa_checklist wvc ON pr.id = wvc.requirement_id AND wvc.passport_id = ?
                LEFT JOIN users u ON wvc.verified_by = u.id
                WHERE pr.profession_id = ?
                  AND COALESCE(pr.gender, 'both') IN ('both', ?)
                GROUP BY pr.id
            ");
            $checklistGender = strtolower((string)($data['gender'] ?? ''));
            $checklistGender = $checklistGender === 'female' ? 'female' : ($checklistGender === 'male' ? 'male' : 'both');
            $stmt_check->execute([$id, $data['profession_id'] ?? 0, $checklistGender]);
            $data['checklist'] = $stmt_check->fetchAll(PDO::FETCH_ASSOC);

            // 3. Audit Logs
            $stmt_logs = $pdo->prepare("
                SELECT tsl.*, s_old.status_name as old_status, s_new.status_name as new_status,
                       COALESCE(u.full_name, u.username) as changer_name,
                       r.name as role_name
                FROM transaction_status_logs tsl
                LEFT JOIN statuses s_old ON tsl.old_status_id = s_old.id
                LEFT JOIN statuses s_new ON tsl.new_status_id = s_new.id
                LEFT JOIN users u ON tsl.changed_by = u.id
                LEFT JOIN roles r ON tsl.changed_role_id = r.id
                WHERE tsl.transaction_id = ?
                ORDER BY tsl.changed_at DESC
            ");
            $stmt_logs->execute([$id]);
            $data['audit_logs'] = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

            // 4. Workflow Transitions
            $transitions = [];
            $all_steps = [];
            $current_step_id = null;
            $workflow = get_workflow_for_transaction($data['transaction_type'] ?? 'work_visa', $data['branch_id'] ?? null);
            if ($workflow) {
                if (!empty($data['status_id']) || (isset($data['status_id']) && $data['status_id'] == 0)) {
                    $stmt_step = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ?");
                    $stmt_step->execute([$workflow['id'], $data['status_id']]);
                    $current_step_id = $stmt_step->fetchColumn();

                    if ($current_step_id) {
                        $role_id = $_SESSION['role_id'] ?? $currentUser['role_id'] ?? null;
                        $transitions = get_allowed_transitions($workflow['id'], $current_step_id, $role_id, $user_id);

                        // ظغظآظعظعظآ ظآظقظآظق ظغظقظآظقظآظغ ظآظق ظآظظظق ظآظقظق ظآ­ظآظقظآ ظآظقظثظآظآظآظق (ظقظق ظآ ظآظآظآظآظآ ظآظقظآ ظآظقظغظآظئظعظآ ظآظآظآ ظئظآظق ظغ ظقظئظغظقظقظآ)
                        if (is_array($transitions) && count($transitions) > 0) {
                            $all_verified = true;
                            if (empty($data['checklist'])) {
                                $all_verified = false; // ظقظآ ظغظثظآ،ظآ ظثظآظآظآظق ظآظآظقظآظق ظآظث ظقظق ظغظغظآ،ظقظآ ظآظآظآ
                            } else {
                                foreach ($data['checklist'] as $item) {
                                    if ($item['relayer_verified'] == 0) {
                                        $all_verified = false;
                                        break;
                                    }
                                }
                            }

                            if ($all_verified) {
                                $transitions = array_values($transitions);
                            }
                        }
                    }
                }

                // ظآ،ظقظآ ظآ،ظقظعظآ ظآظقظآظآظثظآظغ ظآظآظآ ظئظآظق  ظآظقظقظآظغظآظآظق ظقظآظعظق ظآظقظآظآ­ظعظآ (ظآظآظقظق /ظآظعظعظقظثظآظآ)
                if (in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer'], true)) {
                    $stmt_all = $pdo->prepare("SELECT id, step_name, status_id FROM workflow_steps WHERE workflow_id = ? ORDER BY sort_order ASC");
                    $stmt_all->execute([$workflow['id']]);
                    $all_steps = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            $data['transitions'] = is_array($transitions) ? $transitions : [];
            $data['all_workflow_steps'] = $all_steps;
            $data['current_step_id'] = $current_step_id;

            // 4.5 ظآظقظغظآ­ظقظق ظقظق  ظثظآ،ظثظآ ظآظقظآظآظغ ظآظآظغظقظآظآ ظقظآظقظقظآ
            $stmt_pending = $pdo->prepare("
                SELECT ar.*, ws_to.step_name as to_step_name
                FROM workflow_approval_requests ar
                JOIN workflow_steps ws_to ON ar.to_step_id = ws_to.id
                WHERE ar.passport_id = ? AND ar.status = 'pending'
                LIMIT 1
            ");
            $stmt_pending->execute([$id]);
            $data['pending_approval'] = $stmt_pending->fetch(PDO::FETCH_ASSOC) ?: null;

            // 5. Financial Data
            if (has_permission('work_visa_financial_view')) {
                // ظآظآظغظآظآظآظق ظآ،ظآظثظق ظآظقظقظآظغظق ظآظآظغ ظآظقظآ،ظآظعظآ ظقظآظآظآظآظآ ظقظآظقظآظق  ظآظقظآظآظغظقظآظآظآ
                $stmt_paid = $pdo->prepare("SELECT SUM(amount) FROM documents WHERE reference_id = ? AND reference_type = 'work_visa' AND document_type = 'Receipt_Voucher'");
                $stmt_paid->execute([$id]);
                $data['paid_amount'] = $stmt_paid->fetchColumn() ?: 0;

                $stmt_last_pay = $pdo->prepare("SELECT amount, document_number as receipt_number, document_date as date FROM documents WHERE reference_id = ? AND reference_type = 'work_visa' AND document_type = 'Receipt_Voucher' ORDER BY document_date DESC, id DESC LIMIT 1");
                $stmt_last_pay->execute([$id]);
                $last_pay = $stmt_last_pay->fetch(PDO::FETCH_ASSOC);
                $data['last_payment'] = $last_pay ?: null;

                if (!empty($data['agent_id'])) {
                    $stmt_acc = $pdo->prepare("SELECT ua.id, ua.account_name_ar as account_name FROM unified_accounts ua JOIN agents a ON ua.id = a.account_id WHERE a.id = ? LIMIT 1");
                    $stmt_acc->execute([$data['agent_id']]);
                    $data['linked_account'] = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: null;
                } elseif (!empty($data['branch_id'])) {
                    $stmt_acc = $pdo->prepare("SELECT ua.id, ua.account_name_ar as account_name FROM unified_accounts ua JOIN branches b ON ua.id = b.account_id WHERE b.id = ? LIMIT 1");
                    $stmt_acc->execute([$data['branch_id']]);
                    $data['linked_account'] = $stmt_acc->fetch(PDO::FETCH_ASSOC) ?: null;
                } else {
                    $data['linked_account'] = null;
                }

                $settings = getSettings($pdo);
                $serviceConfig = getServiceInvoiceConfig('فيز العمل', $settings);
                $resolveAccountName = static function ($accountId) use ($pdo) {
                    if (empty($accountId)) {
                        return 'غير محدد';
                    }
                    $stmtAcc = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
                    $stmtAcc->execute([$accountId]);
                    $acc = $stmtAcc->fetch(PDO::FETCH_ASSOC);
                    return $acc ? work_visa_format_account_display($acc) : 'غير محدد';
                };

                $data['service_accounts'] = [
                    'revenue_account_name' => $resolveAccountName($serviceConfig['revenue_account_id'] ?? null),
                    'cost_account_name' => $resolveAccountName($serviceConfig['cost_account_id'] ?? null),
                    'profit_account_name' => $resolveAccountName($serviceConfig['profit_account_id'] ?? null),
                ];

                $data['sales_invoice'] = work_visa_load_invoice_details($pdo, $data['sales_invoice_id'] ?? null);
                $data['purchase_invoice'] = work_visa_load_invoice_details($pdo, $data['purchase_invoice_id'] ?? null);
                $data['currency_symbol'] = $data['sales_invoice']['currency_symbol'] ?? ($data['purchase_invoice']['currency_symbol'] ?? '');
                $data['payment_status'] = $data['sales_invoice']['payment_status'] ?? 'unpaid';
            } else {
                $data['paid_amount'] = 0;
                $data['last_payment'] = null;
                $data['linked_account'] = null;
                $data['service_accounts'] = null;
                $data['sales_invoice'] = null;
                $data['purchase_invoice'] = null;
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تحميل البيانات.']);
    }
} elseif ($action === 'approve_finance') {
    if (!has_permission('work_visa_accounts_approve')) {
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit();
    }
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("UPDATE passports SET is_financial_approved = 1, financial_approved_at = NOW(), financial_approved_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $id]);
        echo json_encode(['status' => 'success', 'message' => 'تم اعتماد البيانات المالية بنجاح']);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
    }
} elseif ($action === 'post_finance') {
        if (!has_permission('work_visa_financial_post')) {
            echo json_encode(['status' => 'error', 'message' => 'لا تملك صلاحية الترحيل المالي']);
            exit;
        }
        $id = intval($_POST['id']);
        $scope = strtolower(trim((string)($_POST['scope'] ?? 'all')));
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM passports WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();

            if (!$p) throw new Exception("المعاملة غير موجودة");
            
            $user_id = $_SESSION['admin_id'];
            $posted_count = 0;
            $invoiceIds = work_visa_collect_invoice_ids_by_scope($p, $scope);
            if (empty($invoiceIds)) {
                throw new Exception('لا توجد فاتورة مطابقة لنطاق الترحيل المحدد');
            }

            foreach ($invoiceIds as $invoiceId) {
                if (!$invoiceId) {
                    continue;
                }
                $stmtInv = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ?");
                $stmtInv->execute([$invoiceId]);
                if ($stmtInv->fetchColumn() === 'draft') {
                    php_post_invoice($pdo, $invoiceId, $user_id, true);
                    $posted_count++;
                }
            }

            $stmt_upd = $pdo->prepare("UPDATE passports SET financial_posted_at = NOW(), financial_posted_by = ? WHERE id = ?");
            $stmt_upd->execute([$user_id, $id]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "تم ترحيل {$posted_count} فاتورة بنجاح"]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        } elseif ($action === 'unpost_finance') {
        if (!has_permission('work_visa_financial_post')) {
            echo json_encode(['status' => 'error', 'message' => 'لا تملك صلاحية إلغاء الترحيل المالي']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $scope = strtolower(trim((string)($_POST['scope'] ?? 'all')));
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT sales_invoice_id, purchase_invoice_id FROM passports WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                throw new Exception('المعاملة غير موجودة');
            }

            $userId = (int)($_SESSION['admin_id'] ?? 0);
            $resetCount = 0;
            $invoiceIds = work_visa_collect_invoice_ids_by_scope($p, $scope);
            if (empty($invoiceIds)) {
                throw new Exception('لا توجد فاتورة مطابقة لنطاق إلغاء الترحيل المحدد');
            }

            foreach ($invoiceIds as $invoiceId) {
                if (!$invoiceId) {
                    continue;
                }
                $stmtInv = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ?");
                $stmtInv->execute([$invoiceId]);
                if ($stmtInv->fetchColumn() === 'posted') {
                    work_visa_reset_invoice_to_draft($pdo, (int)$invoiceId, $userId);
                    $resetCount++;
                }
            }

            $hasPosted = work_visa_has_posted_invoice($pdo, $p);
            $stmtUpd = $pdo->prepare("UPDATE passports SET financial_posted_at = ?, financial_posted_by = ? WHERE id = ?");
            $stmtUpd->execute([$hasPosted ? date('Y-m-d H:i:s') : null, $hasPosted ? $userId : null, $id]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "تم إلغاء ترحيل {$resetCount} فاتورة بنجاح"]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        } elseif ($action === 'delete_finance') {
        $id = (int)($_POST['id'] ?? 0);
        $scope = strtolower(trim((string)($_POST['scope'] ?? 'all')));

        try {
            $stmt = $pdo->prepare("
                SELECT id, full_name, passport_number, agent_id, branch_id,
                       sales_invoice_id, purchase_invoice_id,
                       financial_posted_at, financial_posted_by, auto_invoice_generated
                FROM passports
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $passport = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$passport) {
                throw new Exception('المعاملة غير موجودة');
            }

            if (!work_visa_user_can_delete($passport)) {
                throw new Exception('لا تملك صلاحية حذف الفواتير لهذه المعاملة');
            }

            $invoiceMap = [
                'sales' => !empty($passport['sales_invoice_id']) ? (int)$passport['sales_invoice_id'] : null,
                'purchase' => !empty($passport['purchase_invoice_id']) ? (int)$passport['purchase_invoice_id'] : null,
            ];
            $selectedScopes = $scope === 'all' ? ['sales', 'purchase'] : [$scope];

            foreach ($selectedScopes as $selectedScope) {
                if (!array_key_exists($selectedScope, $invoiceMap)) {
                    throw new Exception('نطاق الحذف المطلوب غير صحيح');
                }
            }

            $selectedInvoiceIds = [];
            foreach ($selectedScopes as $selectedScope) {
                if (!empty($invoiceMap[$selectedScope])) {
                    $selectedInvoiceIds[] = (int)$invoiceMap[$selectedScope];
                }
            }

            if (empty($selectedInvoiceIds)) {
                throw new Exception('لا توجد فاتورة مطابقة لنطاق الحذف المحدد');
            }

            $pdo->beginTransaction();

            foreach (array_values(array_unique($selectedInvoiceIds)) as $invoiceId) {
                work_visa_delete_invoice_record($pdo, (int)$invoiceId);
            }

            $newSalesInvoiceId = $invoiceMap['sales'];
            $newPurchaseInvoiceId = $invoiceMap['purchase'];
            if (in_array('sales', $selectedScopes, true)) {
                $newSalesInvoiceId = null;
            }
            if (in_array('purchase', $selectedScopes, true)) {
                $newPurchaseInvoiceId = null;
            }

            $remainingInvoiceState = [
                'sales_invoice_id' => $newSalesInvoiceId,
                'purchase_invoice_id' => $newPurchaseInvoiceId,
            ];
            $stillPosted = work_visa_has_posted_invoice($pdo, $remainingInvoiceState);

            $stmtUpdate = $pdo->prepare("
                UPDATE passports
                SET sales_invoice_id = ?,
                    purchase_invoice_id = ?,
                    auto_invoice_generated = ?,
                    financial_posted_at = ?,
                    financial_posted_by = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $newSalesInvoiceId,
                $newPurchaseInvoiceId,
                ($newSalesInvoiceId || $newPurchaseInvoiceId) ? 1 : 0,
                $stillPosted ? ($passport['financial_posted_at'] ?: date('Y-m-d H:i:s')) : null,
                $stillPosted ? ($passport['financial_posted_by'] ?: $user_id) : null,
                $id
            ]);

            log_audit(
                $pdo,
                'delete',
                'passports',
                $id,
                [
                    'sales_invoice_id' => $passport['sales_invoice_id'],
                    'purchase_invoice_id' => $passport['purchase_invoice_id']
                ],
                [
                    'sales_invoice_id' => $newSalesInvoiceId,
                    'purchase_invoice_id' => $newPurchaseInvoiceId
                ],
                'حذف جزئي/كلي للفواتير المرتبطة بمعاملة فيز العمل'
            );

            $pdo->commit();

            $scopeLabel = $scope === 'sales' ? 'فاتورة البيع' : ($scope === 'purchase' ? 'فاتورة الشراء' : 'فاتورتي البيع والشراء');
            echo json_encode([
                'status' => 'success',
                'message' => "تم حذف {$scopeLabel} بنجاح من المعاملة"
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        } elseif ($action === 'get_step_fields') {
    $step_id = $_GET['step_id'] ?? null;
    if (!$step_id) {
        echo json_encode(['status' => 'error', 'message' => 'Step ID missing']);
        exit();
    }

    $fields = get_step_fields($step_id);
    // ظآ،ظقظآ ظآظعظآظق ظآظغ ظآظقظآ­ظقظثظق (labels, types)
    // ظقظق ظآ ظق ظعظغظآظآ ظقظآظعظثظعظآ ظآظآظعظآظآ ظقظق  ظآظآظقظآظظ ظآظقظآ­ظقظثظقظإ ظثظآظق ظقظثظق ظآظغظآ­ظثظعظقظقظآ ظقظقظآظقظثظقظآظغ ظآظآظآ
    $field_info = [];
    foreach ($fields as $field) {
        $field = trim($field);
        if (empty($field)) continue;

        switch ($field) {
            case 'batch_no':
                $field_info[] = ['name' => $field, 'label' => 'رقم الباتش / الدفعة', 'type' => 'text', 'required' => true];
                break;
            case 'request_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ الطلب', 'type' => 'date', 'required' => true];
                break;
            case 'main_branch_delivery_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ التسليم للفرع', 'type' => 'date', 'required' => true];
                break;
            case 'received_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ الاستلام', 'type' => 'date', 'required' => true];
                break;
            case 'sent_to_embassy_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ الإرسال للسفارة', 'type' => 'date', 'required' => true];
                break;
            case 'embassy_exit_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ الخروج من السفارة', 'type' => 'date', 'required' => true];
                break;
            case 'arrival_office_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ وصول المكتب', 'type' => 'date', 'required' => true];
                break;
            case 'visa_no':
                $field_info[] = ['name' => $field, 'label' => 'رقم التأشيرة', 'type' => 'text', 'required' => true];
                break;
            case 'visa_issue_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ إصدار التأشيرة', 'type' => 'date', 'required' => true];
                break;
            case 'visa_expiry_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ انتهاء التأشيرة', 'type' => 'date', 'required' => true];
                break;
            case 'transport_delivery_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ التسليم للنقل', 'type' => 'date', 'required' => true];
                break;
            case 'delivery_date':
                $field_info[] = ['name' => $field, 'label' => 'تاريخ التسليم للعميل', 'type' => 'date', 'required' => true];
                break;
            case 'customer_receiver_name':
                $field_info[] = ['name' => $field, 'label' => 'اسم مستلم المعاملة', 'type' => 'text', 'required' => true];
                break;
            case 'cancellation_reason':
                $field_info[] = ['name' => $field, 'label' => 'سبب الإلغاء', 'type' => 'textarea', 'required' => true];
                break;
            case 'reject_reason':
                $field_info[] = ['name' => $field, 'label' => 'سبب الرفض', 'type' => 'textarea', 'required' => true];
                break;

            // ظقظقظآ­ظعظآظآ ظآظقظق ظآظقظغظثظآظعظق ظقظآ ظآظع ظغظآظقظعظآظغ ظقظآظعظقظآ
            case 'visa_number':
                $field_info[] = ['name' => 'visa_no', 'label' => 'رقم التأشيرة', 'type' => 'text', 'required' => true];
                break;
            case 'office_name':
                $field_info[] = ['name' => 'arrival_office_date', 'label' => 'تاريخ وصول المكتب', 'type' => 'date', 'required' => true];
                break;
        }
    }

    echo json_encode(['status' => 'success', 'fields' => $field_info]);
    exit();
} elseif ($action === 'process_transition') {
    $passport_ids = $_POST['passport_id'] ?? null;
    $to_step_id = $_POST['to_step_id'] ?? null;
    $notes = $_POST['notes'] ?? '';

    if (empty($passport_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'يرجى اختيار معاملة واحدة على الأقل قبل تنفيذ النقل']);
        exit();
    }

    // ظغظآظئظآ ظقظق  ظآظق ظقظآ ظقظآظعظثظعظآ
    if (!is_array($passport_ids)) $passport_ids = [$passport_ids];
    $first_passport_id = $passport_ids[0];

    if (!$to_step_id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing data']);
        exit();
    }

    // ظآ،ظقظآ ظآظقظقظآظآ­ظقظآ ظآظقظآ­ظآظقظعظآ ظقظقظآ،ظثظآظآ (ظق ظآظآظآ ظآظقظآظثظق ظئظآظآظآظآ ظقظقظغظآ­ظقظق ظقظق  ظآظقظآظق ظغظقظآظق)
    $stmt_curr = $pdo->prepare("SELECT status_id, full_name, passport_number FROM passports WHERE id = ?");
    $stmt_curr->execute([$first_passport_id]);
    $passport = $stmt_curr->fetch();
    if (!$passport) {
        echo json_encode(['status' => 'error', 'message' => 'المعاملة غير موجودة']);
        exit();
    }
    $from_step_id = $passport['status_id'];

    // ظآظقظغظآ­ظقظق ظقظق  ظقظآظآظآظآ ظآظقظآظق ظغظقظآظق ظثظقظق ظغظغظآظقظآ ظآظآظغظقظآظآ
    $stmt_trans = $pdo->prepare("SELECT require_approval FROM workflow_transitions WHERE from_step_id = ? AND to_step_id = ? LIMIT 1");
    $stmt_trans->execute([$from_step_id, $to_step_id]);
    $require_approval = $stmt_trans->fetchColumn();

    // ظآ،ظقظآ ظآظقظآ­ظقظثظق ظآظقظقظآظقظثظآظآ ظقظقظقظآظآ­ظقظآ
    $required_fields = get_step_fields($to_step_id);
    $extra_data = [];
    if (!empty($required_fields)) {
        foreach ($required_fields as $field) {
            if (isset($_POST[$field])) {
                $extra_data[$field] = $_POST[$field];
            }
        }
    }

    if ($require_approval) {
        // ظآظقظغظآ­ظقظق ظقظق  ظثظآ،ظثظآ ظآظقظآ ظقظآظقظق ظقظآظآظقظآظق ظقظق ظعظآ ظآظقظقظآظآظقظقظآ ظثظآظقظثظآ،ظقظآ
        $stmt_check = $pdo->prepare("SELECT id FROM workflow_approval_requests WHERE passport_id = ? AND to_step_id = ? AND status = 'pending' LIMIT 1");
        $stmt_check->execute([$first_passport_id, $to_step_id]);
        if ($stmt_check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'يوجد طلب اعتماد قيد الانتظار لهذه المعاملة. يرجى انتظار الاعتماد أو إلغاء الطلب السابق.']);
            exit();
        }

        // ظآظق ظآظآظظ ظآظقظآ ظآظآظغظقظآظآ ظقظئظق ظقظآظآظقظقظآ ظقظآظغظآظآظآ
        try {
            $pdo->beginTransaction();
            foreach ($passport_ids as $id) {
                // ظآ،ظقظآ ظآظعظآظق ظآظغ ظئظق ظآ،ظثظآظآ ظقظقظآظآظآظآظآظآظغ
                $stmt_p = $pdo->prepare("SELECT full_name, status_id FROM passports WHERE id = ?");
                $stmt_p->execute([$id]);
                $p_data = $stmt_p->fetch();

                $stmt_app = $pdo->prepare("INSERT INTO workflow_approval_requests (passport_id, from_step_id, to_step_id, requested_by, requested_role_id, notes, extra_data) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_app->execute([$id, $p_data['status_id'], $to_step_id, $user_id, $_SESSION['role_id'] ?? null, $notes, json_encode($extra_data)]);
                $request_id = $pdo->lastInsertId();

                // ظآظآظآظآظق ظآظآظآظآظآ ظقظقظقظآظآظآظظ ظقظئظق ظآظقظآ
                $title = "طلب اعتماد نقل مرحلة";
                $message = "تم طلب اعتماد نقل المرحلة للمعاملة (" . $p_data['full_name'] . ").\nيرجى مراجعة الطلب والموافقة عليه.";
                $link = "workflow_approvals.php?id=" . $request_id;

                $stmt_admins = $pdo->query("SELECT id FROM users WHERE role_id = 1 OR role = 'admin'");
                $admins = $stmt_admins->fetchAll();

                foreach ($admins as $admin) {
                    $stmt_n = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, 'warning', ?)");
                    $stmt_n->execute([$admin['id'], $title, $message, $link, $user_id]);
                }
            }
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'تم إرسال طلب الاعتماد بنجاح']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log(basename(__FILE__) . ': ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
        }
    } else {
        // ظغظآظآ­ظعظق ظقظآظآظآظآ (ظآظآظقظآ change_transaction_status ظغظآظآظق ظآظقظقظآظعظثظعظآ)
        if (change_transaction_status($passport_ids, $to_step_id, $user_id, $notes, $extra_data)) {
            echo json_encode(['status' => 'success', 'message' => 'تم تنفيذ النقل بنجاح']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'فشل تنفيذ النقل']);
        }
    }
    exit();
} elseif ($action === 'get_full_details') {
    // Deleted this block because it's replaced by get_work_visa_details JSON response
    exit();
} elseif ($action === 'relayer_verify_item') {
    $passport_id = $_POST['passport_id'];
    $requirement_id = $_POST['requirement_id'];
    $verified = $_POST['verified']; // 1 or 0

    try {
        // ظآظآظآ ظئظآظق  ظعظآ­ظآظثظق ظآظقظغظآظآظآ،ظآ ظآظق  ظآظقظغظآظئظعظآ (verified = 0)
        if ($verified == 0) {
            // ظآظقظغظآ­ظقظق ظقظق  ظآظقظآظقظآظآ­ظعظآ: ظقظق ظعظقظقظئ ظآظقظآظآ­ظعظآ ظآظقظغظآظآظعظقظغ ظآظث ظقظق ظقظث ظقظآظعظآ/ظقظآظآظقظآ،ظغ
            $can_revert = has_permission('work_visa_edit_verified_docs') ||
                in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer'], true);

            if (!$can_revert) {
                echo json_encode(['status' => 'error', 'message' => 'لا تملك صلاحية إلغاء اعتماد المستندات']);
                exit();
            }
        }

        $stmt = $pdo->prepare("
            UPDATE work_visa_checklist
            SET relayer_verified = ?, verified_by = ?, verified_at = NOW()
            WHERE passport_id = ? AND requirement_id = ?
        ");
        $stmt->execute([$verified, $user_id, $passport_id, $requirement_id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
    }
} elseif ($action === 'update_checklist') {
    $passport_id = $_POST['passport_id'];
    $checklist_items = $_POST['checklist'] ?? []; // Array of {requirement_id, is_completed}

    $pdo->beginTransaction();
    try {
        foreach ($checklist_items as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO work_visa_checklist (passport_id, requirement_id, is_completed, verified_by, verified_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE is_completed = VALUES(is_completed), verified_by = VALUES(verified_by), verified_at = NOW()
            ");
            $stmt->execute([$passport_id, $item['requirement_id'], $item['is_completed'], $user_id]);
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
    }
} elseif ($action === 'add_relayer_note') {
    $passport_id = $_POST['passport_id'];
    $note = $_POST['note'];

    try {
        $pdo->beginTransaction();

        // ظغظآ­ظآظعظآ ظقظقظآظآ­ظآظآظغ ظآظقظآ،ظثظآظآ ظثظغظآظعظعظق ظقظآ ظئظظظعظآ ظقظآ­ظقظثظقظآ
        $stmt = $pdo->prepare("UPDATE passports SET relayer_notes = ?, is_resolved = 0 WHERE id = ?");
        $stmt->execute([$note, $passport_id]);

        // ظآ،ظقظآ ظآظعظآظق ظآظغ ظآظقظآ،ظثظآظآ ظقظآظآظآظآظق ظآظآظآظآظآ
        $stmt_p = $pdo->prepare("SELECT passport_number, agent_id, branch_id, full_name FROM passports WHERE id = ?");
        $stmt_p->execute([$passport_id]);
        $passport = $stmt_p->fetch();

        if ($passport) {
            $title = "ملاحظة من المُرحّل";
            $message = "تم إضافة ملاحظة على معاملة (" . $passport['full_name'] . ") - رقم الجواز: " . $passport['passport_number'] . ".\nالملاحظة: " . $note;
            $link = "work_visa.php?id=" . $passport_id;

            $stmt_n = $pdo->prepare("INSERT INTO notifications (agent_id, branch_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, ?, 'danger', ?)");
            $stmt_n->execute([$passport['agent_id'], $passport['branch_id'], $title, $message, $link, $user_id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'تم حفظ الملاحظة بنجاح']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
    }
} elseif ($action === 'mark_resolved') {
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("UPDATE passports SET is_resolved = 1 WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
    }
} elseif ($action === 'get_new_notifications') {
    header('Content-Type: application/json');
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $last_id = $_GET['last_id'] ?? 0;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM notifications
            WHERE (agent_id = ? OR branch_id = ? OR user_id = ?)
            AND id > ?
            ORDER BY id DESC
        ");
        $stmt->execute([$agent_id, $branch_id, $user_id, $last_id]);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'notifications' => $notifs]);
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
    }
    exit();
} elseif ($action === 'get_notifications') {
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;

    $sql = "SELECT * FROM notifications WHERE (agent_id = ? OR branch_id = ?) AND is_read = 0 ORDER BY created_at DESC LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id, $branch_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($notifications);
} elseif ($action === 'mark_notifs_read') {
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    $user_role = $_SESSION['role'] ?? null;

    // ظآظق ظآظظ ظآظآظغظآظقظآظق ظقظآ­ظآظق  ظقظقظآظآظآظآظآظآظغ
    $where_conditions = [];
    $params = [];

    if ($user_id) {
        $where_conditions[] = "user_id = ?";
        $params[] = $user_id;
    }

    if ($user_role) {
        $where_conditions[] = "role_id = ?";
        $params[] = $user_role;
    }

    if ($agent_id) {
        $where_conditions[] = "agent_id = ?";
        $params[] = $agent_id;
    }

    if ($branch_id) {
        $where_conditions[] = "branch_id = ?";
        $params[] = $branch_id;
    }

    // ظآظآظآ ظقظق ظعظئظق  ظقظق ظآظئ ظآظآظثظآظإ ظآ­ظآظآ ظآ،ظقظعظآ ظآظقظآظآظآظآظآظآظغ (ظقظقظقظآظآظآظظ)
    if (empty($where_conditions)) {
        $where_clause = "1=1";
        $params = [];
    } else {
        $where_clause = implode(" OR ", $where_conditions);
    }

    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE ($where_clause) AND is_read = 0");
    if ($stmt->execute($params)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
} elseif ($action === 'mark_single_notif_read') {
    $notif_id = $_GET['notif_id'] ?? null;
    if (!$notif_id) {
        echo json_encode(['status' => 'error', 'message' => 'Missing notification ID']);
        exit();
    }

    // ظآظقظغظآ­ظقظق ظقظق  ظآظق  ظآظقظآظآظآظآظآ ظقظثظآ،ظق ظقظقظآظآ ظآظقظقظآظغظآظآظق ظقظآظق ظغظآ­ظآظعظآظق ظئظقظقظآظثظظ
    $agent_id = $_SESSION['agent_id'] ?? null;
    $branch_id = $_SESSION['branch_id'] ?? null;
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    $user_role = $_SESSION['role'] ?? null;

    $where_conditions = [];
    $params = [$notif_id]; // notif_id ظآظثظقظآظق

    if ($user_id) {
        $where_conditions[] = "user_id = ?";
        $params[] = $user_id;
    }

    if ($user_role) {
        $where_conditions[] = "role_id = ?";
        $params[] = $user_role;
    }

    if ($agent_id) {
        $where_conditions[] = "agent_id = ?";
        $params[] = $agent_id;
    }

    if ($branch_id) {
        $where_conditions[] = "branch_id = ?";
        $params[] = $branch_id;
    }

    if (empty($where_conditions)) {
        $where_clause = "1=1";
        $params = [$notif_id];
    } else {
        $where_clause = implode(" OR ", $where_conditions);
    }

    // ظآظقظغظآ­ظقظق ظقظق  ظثظآ،ظثظآ ظآظقظآظآظآظآظآ
    $check_stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND ($where_clause)");
    $check_stmt->execute($params);
    if ($check_stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND is_read = 0");
        if ($stmt->execute([$notif_id])) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Notification not found or not accessible']);
    }
} elseif ($action === 'check_duplicate_work_visa') {
    $passport_number = trim((string)($_GET['passport_number'] ?? ''));
    $full_name = trim((string)($_GET['full_name'] ?? ''));
    $transaction_type = $_GET['transaction_type'] ?? 'work_visa';
    $agent_id = !empty($_GET['agent_id']) ? (int)$_GET['agent_id'] : null;
    $branch_id = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    if ($passport_number === '' && $full_name === '') {
        echo json_encode(['status' => 'success', 'duplicate' => false]);
        exit();
    }

    $conditions = [];
    $params = [];
    if ($passport_number !== '') {
        $conditions[] = "passport_number = ?";
        $params[] = $passport_number;
    }
    if ($full_name !== '') {
        $conditions[] = "full_name = ?";
        $params[] = $full_name;
    }

    $params[] = $transaction_type;
    $sql = "
        SELECT p.id, p.full_name, p.passport_number, p.created_at, p.agent_id, p.branch_id,
               s.status_name, ag.agent_name, br.branch_name
        FROM passports p
        LEFT JOIN statuses s ON s.id = p.status_id
        LEFT JOIN agents ag ON ag.id = p.agent_id
        LEFT JOIN branches br ON br.id = p.branch_id
        WHERE (" . implode(' OR ', $conditions) . ")
          AND p.transaction_type = ?
          AND p.deleted_at IS NULL
        ORDER BY p.id DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $duplicateByPassport = false;
    $duplicateByName = false;
    foreach ($matches as $match) {
        if ($passport_number !== '' && $match['passport_number'] === $passport_number) {
            $duplicateByPassport = true;
        }
        if ($full_name !== '' && $match['full_name'] === $full_name) {
            $duplicateByName = true;
        }
    }

    $scopeHint = 'مسجل مسبقاً في النظام';
    foreach ($matches as $match) {
        if ($agent_id && (int)$match['agent_id'] === $agent_id) {
            $scopeHint = 'مسجل مسبقاً ضمن معاملات الوكيل الحالي';
            break;
        }
        if ($branch_id && (int)$match['branch_id'] === $branch_id) {
            $scopeHint = 'مسجل مسبقاً ضمن معاملات الفرع الحالي';
        }
    }

    echo json_encode([
        'status' => 'success',
        'duplicate' => !empty($matches),
        'duplicate_by_passport' => $duplicateByPassport,
        'duplicate_by_name' => $duplicateByName,
        'scope_hint' => $scopeHint,
        'matches' => $matches,
    ]);
    exit();
} elseif ($action === 'get_profession_requirements') {
    $profession_id = $_GET['profession_id'] ?? null;
    $selected_gender = strtolower(trim((string)($_GET['gender'] ?? '')));
    if (!$profession_id) {
        echo json_encode(['requirements' => [], 'rules' => null]);
        exit();
    }

    $stmt_req = $pdo->prepare("SELECT id, requirement_name, COALESCE(gender, 'both') AS gender FROM profession_requirements WHERE profession_id = ? ORDER BY id ASC");
    $stmt_req->execute([$profession_id]);
    $allRequirements = $stmt_req->fetchAll(PDO::FETCH_ASSOC);
    $requirements = array_values(array_filter($allRequirements, static function ($row) use ($selected_gender) {
        return work_visa_requirement_matches_gender((string)($row['gender'] ?? 'both'), $selected_gender);
    }));

    $stmt_rules = $pdo->prepare("SELECT * FROM work_visa_rules WHERE profession_id = ?");
    $stmt_rules->execute([$profession_id]);
    $rules = $stmt_rules->fetch(PDO::FETCH_ASSOC);
    if ($rules) {
        $rules['allowed_nationalities_list'] = work_visa_parse_allowed_nationalities($rules['allowed_nationalities'] ?? '');
    }

    echo json_encode([
        'requirements' => $requirements,
        'requirements_all' => $allRequirements,
        'rules' => $rules
    ]);
    exit();
} elseif ($action === 'get_service_price') {
    $service_id = $_GET['service_id'] ?? null;
    $branch_id = !empty($_GET['branch_id']) ? $_GET['branch_id'] : null;
    $agent_id = !empty($_GET['agent_id']) ? $_GET['agent_id'] : null;

    if (!$service_id) {
        echo json_encode(['status' => 'error', 'message' => 'Service ID is required']);
        exit();
    }

    try {
        $target = normalize_service_target($pdo, $agent_id, $branch_id);
        $price = get_service_price_config($pdo, $service_id, $target['agent_id'], $target['branch_id']);
        $settings = getSettings($pdo);
        $serviceConfig = getServiceInvoiceConfig('فيز العمل', $settings);

        if ($price) {
            echo json_encode([
                'status' => 'success',
                'purchase_price' => (float) $price['purchase_price'],
                'sale_price' => (float) $price['sale_price'],
                'default_sale_price' => (float) $price['sale_price'],
                'agent_price' => (float) ($price['agent_price'] ?? 0),
                'branch_price' => (float) ($price['branch_price'] ?? 0),
                'currency_id' => $price['currency_id'],
                'currency_name' => $price['currency_name'] ?? '',
                'currency_symbol' => $price['currency_symbol'] ?? '',
                'revenue_account_name' => $serviceConfig['revenue_account_name'] ?? '',
                'cost_account_name' => $serviceConfig['cost_account_name'] ?? '',
                'profit_account_name' => $serviceConfig['profit_account_name'] ?? '',
                'target_type' => $price['target_type'],
                'user_role' => $_SESSION['role'] ?? ''
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'لم يتم العثور على تسعير لهذه الخدمة']);
        }
    } catch (Exception $e) {
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء تنفيذ العملية']);
    }
    exit();
}

