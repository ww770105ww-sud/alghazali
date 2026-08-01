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
rate_limit('ajax_postal_services:' . $action, 60, 60);
require_csrf_for_actions(['post_finance', 'unpost_finance', 'cancel_invoices']);

function postal_collect_invoice_ids_by_scope(array $shipmentRow, string $scope): array
{
    $scope = strtolower(trim($scope));
    $salesId = !empty($shipmentRow['sales_invoice_id']) ? (int)$shipmentRow['sales_invoice_id'] : null;
    $purchaseId = !empty($shipmentRow['purchase_invoice_id']) ? (int)$shipmentRow['purchase_invoice_id'] : null;

    if ($scope === 'sales') {
        return array_values(array_filter([$salesId]));
    }
    if ($scope === 'purchase') {
        return array_values(array_filter([$purchaseId]));
    }

    return array_values(array_filter([$salesId, $purchaseId]));
}

function postal_find_latest_invoice_id(PDO $pdo, int $shipmentId, string $category): ?int
{
    $category = $category === 'purchase' ? 'purchase' : 'sales';
    $stmt = $pdo->prepare("
        SELECT id
        FROM invoices
        WHERE source_type IN ('خدمات البريد', 'postal')
          AND source_id = ?
          AND invoice_category = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$shipmentId, $category]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

function postal_reset_invoice_to_draft(PDO $pdo, int $invoiceId, int $userId): void
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

function postal_cancel_invoice(PDO $pdo, int $invoiceId, int $userId, string $note): void
{
    $stmt = $pdo->prepare("SELECT id, invoice_status FROM invoices WHERE id = ? LIMIT 1");
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

if ($action === 'post_finance') {
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
        $stmt = $pdo->prepare("SELECT id, sales_invoice_id, purchase_invoice_id FROM postal_shipments WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$shipment) {
            throw new Exception('الشحنة غير موجودة');
        }

        $invoiceIds = postal_collect_invoice_ids_by_scope($shipment, $scope);
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
    exit;
}

if ($action === 'unpost_finance') {
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
        $stmt = $pdo->prepare("SELECT id, sales_invoice_id, purchase_invoice_id FROM postal_shipments WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$shipment) {
            throw new Exception('الشحنة غير موجودة');
        }

        $invoiceIds = postal_collect_invoice_ids_by_scope($shipment, $scope);
        if (empty($invoiceIds)) {
            throw new Exception('لا توجد فاتورة مطابقة لنطاق إلغاء الترحيل المحدد');
        }

        $userId = (int)($_SESSION['admin_id'] ?? 0);
        $resetCount = 0;
        foreach ($invoiceIds as $invoiceId) {
            $stmtInv = $pdo->prepare("SELECT invoice_status FROM invoices WHERE id = ?");
            $stmtInv->execute([$invoiceId]);
            if ($stmtInv->fetchColumn() === 'posted') {
                postal_reset_invoice_to_draft($pdo, (int)$invoiceId, $userId);
                $resetCount++;
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "تم إلغاء ترحيل {$resetCount} فاتورة بنجاح"]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'cancel_invoices') {
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
        $stmt = $pdo->prepare("SELECT id, sales_invoice_id, purchase_invoice_id FROM postal_shipments WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$shipment) {
            throw new Exception('الشحنة غير موجودة');
        }

        $salesInvoiceId = !empty($shipment['sales_invoice_id']) ? (int)$shipment['sales_invoice_id'] : postal_find_latest_invoice_id($pdo, $id, 'sales');
        $purchaseInvoiceId = !empty($shipment['purchase_invoice_id']) ? (int)$shipment['purchase_invoice_id'] : postal_find_latest_invoice_id($pdo, $id, 'purchase');

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
            $note = ($t['type'] === 'sales') ? 'حذف فاتورة البيع لخدمات البريد' : 'حذف فاتورة الشراء لخدمات البريد';
            postal_cancel_invoice($pdo, (int)$t['invoice_id'], $userId, $note);
            $cancelled++;
        }

        $updateParts = [];
        if ($scope === 'sales' || $scope === 'all') {
            $updateParts[] = "sales_invoice_id = NULL";
        }
        if ($scope === 'purchase' || $scope === 'all') {
            $updateParts[] = "purchase_invoice_id = NULL";
        }
        if (!empty($updateParts)) {
            $sql = "UPDATE postal_shipments SET " . implode(", ", $updateParts) . " WHERE id = ?";
            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute([$id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "تم حذف {$cancelled} فاتورة بنجاح"]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'طلب غير صالح']);
