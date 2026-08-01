<?php

require_once __DIR__ . '/../FinanceService.php';

class BookingFinancialUpdater
{
    /** @var PDO */
    private $pdo;

    /** @var FinanceService */
    private $financeService;

    /** @var int */
    private $userId;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->financeService = new FinanceService($pdo, $userId);
    }

    public function updateBookingAndFinancials(int $bookingId, array $bookingData, array $financeData): void
    {
        $this->financeService->executeAtomically(function () use ($bookingId, $bookingData, $financeData) {
            $this->updateBookingRow($bookingId, $bookingData);

            $bookingContext = $this->fetchBookingContext($bookingId);
            if (!$bookingContext) {
                throw new Exception('تعذر تحميل بيانات الحجز بعد التحديث.');
            }

            $salesInvoiceId = (int)($bookingContext['sales_invoice_id'] ?: $bookingContext['invoice_id']);
            if ($salesInvoiceId <= 0) {
                return;
            }

            $salesInvoice = $this->fetchInvoiceById($salesInvoiceId);
            if (!$salesInvoice) {
                return;
            }

            $resolvedFinance = $this->updateDraftSalesInvoice($salesInvoice, $bookingContext, $financeData);

            if ($salesInvoice['invoice_status'] === 'draft' && array_key_exists('amount_received', $financeData)) {
                $this->replaceDraftSalesInvoicePayment($salesInvoice, $bookingContext, $resolvedFinance);
            }

            $this->updateDraftPurchaseInvoice($bookingContext, $resolvedFinance);
        });
    }

    private function updateBookingRow(int $bookingId, array $bookingData): void
    {
        $this->pdo->prepare("
            UPDATE bus_flight_bookings SET
            traveler_name = ?, mobile_number = ?, gender = ?, date_of_birth = ?,
            place_of_birth = ?, nationality_id = ?, id_type = ?, id_number = ?,
            service_type = ?, bus_type = ?, trip_type = ?, from_city_id = ?, to_city_id = ?,
            supplier_id = ?, description = ?, booking_date = ?, departure_date = ?, return_date = ?, id_issue_place = ?,
            id_issue_date = ?, notes = ?, branch_id = ?,
            customer_id = ?, account_id = ?, operation_date = ?, agent_id = ?
            WHERE id = ?
        ")->execute([
            $bookingData['traveler_name'],
            $bookingData['mobile_number'],
            $bookingData['gender'],
            $bookingData['date_of_birth'],
            $bookingData['place_of_birth'],
            $bookingData['nationality_id'],
            $bookingData['id_type'],
            $bookingData['id_number'],
            $bookingData['service_type'],
            $bookingData['bus_type'],
            $bookingData['trip_type'],
            $bookingData['from_city_id'],
            $bookingData['to_city_id'],
            $bookingData['supplier_id'],
            $bookingData['description'],
            $bookingData['booking_date'],
            $bookingData['departure_date'],
            $bookingData['return_date'],
            $bookingData['id_issue_place'],
            $bookingData['id_issue_date'],
            $bookingData['notes'],
            $bookingData['branch_id'],
            $bookingData['customer_id'],
            $bookingData['account_id'],
            $bookingData['operation_date'],
            $bookingData['agent_id'],
            $bookingId
        ]);
    }

    private function fetchBookingContext(int $bookingId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT invoice_id, sales_invoice_id, purchase_invoice_id, branch_id, booking_number, traveler_name
            FROM bus_flight_bookings
            WHERE id = ?
        ");
        $stmt->execute([$bookingId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function fetchInvoiceById(int $invoiceId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function updateDraftSalesInvoice(array $salesInvoice, array $bookingContext, array $financeData): array
    {
        $resolved = [
            'sale_price' => $financeData['sale_price'] ?? (float)$salesInvoice['total_amount'],
            'discount' => $financeData['discount'] ?? (float)($salesInvoice['discount'] ?? 0),
            'purchase_price' => $financeData['purchase_price'] ?? (float)$salesInvoice['cost_amount'],
            'sale_currency_id' => $financeData['sale_currency_id'] ?? (int)$salesInvoice['currency_id'],
            'purchase_currency_id' => $financeData['purchase_currency_id'] ?? ($financeData['sale_currency_id'] ?? (int)$salesInvoice['currency_id']),
            'delivery_type' => $financeData['delivery_type'] ?? $salesInvoice['delivery_type'],
            'customer_id' => $financeData['customer_id'] ?? $salesInvoice['customer_id'],
            'account_id' => $financeData['account_id'] ?? $salesInvoice['account_id'],
            'agent_id' => $financeData['agent_id'] ?? $salesInvoice['agent_id'],
            'operation_date' => $financeData['operation_date'] ?? $salesInvoice['invoice_date'],
            'description' => $financeData['description'] ?: $salesInvoice['description'],
            'source_type' => $financeData['source_type'] ?? $salesInvoice['source_type'],
            'amount_received' => $financeData['amount_received'] ?? null,
            'exchange_rate' => (float)($financeData['exchange_rate'] ?? 1),
            'source_number' => $bookingContext['booking_number'],
            'traveler_name' => $bookingContext['traveler_name'],
            'branch_id' => $bookingContext['branch_id'],
        ];

        if ($salesInvoice['invoice_status'] === 'draft') {
            $costAmount = $resolved['purchase_price'];
            if (
                array_key_exists('purchase_price', $financeData)
                && $financeData['purchase_price'] !== null
                && $resolved['sale_currency_id'] != $resolved['purchase_currency_id']
                && $resolved['exchange_rate'] > 0
            ) {
                $costAmount = $resolved['purchase_price'] * $resolved['exchange_rate'];
            }

            $this->pdo->prepare("
                UPDATE invoices
                SET total_amount = ?, discount = ?, cost_amount = ?, currency_id = ?,
                    description = ?, delivery_type = ?, customer_id = ?, agent_id = ?,
                    account_id = ?, invoice_date = ?, source_type = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $resolved['sale_price'],
                $resolved['discount'],
                $costAmount,
                $resolved['sale_currency_id'],
                $resolved['description'],
                $resolved['delivery_type'],
                $resolved['customer_id'],
                $resolved['agent_id'],
                $resolved['account_id'],
                $resolved['operation_date'],
                $resolved['source_type'],
                $salesInvoice['id'],
            ]);
        }

        return $resolved;
    }

    private function replaceDraftSalesInvoicePayment(array $salesInvoice, array $bookingContext, array $resolvedFinance): void
    {
        $paidAmount = (float)$resolvedFinance['amount_received'];
        $netSale = max(0, (float)$resolvedFinance['sale_price'] - (float)$resolvedFinance['discount']);

        if ($paidAmount > $netSale) {
            throw new Exception("المبلغ الواصل لا يمكن أن يكون أكبر من صافي سعر البيع ($netSale)");
        }

        $this->deletePostedReceiptVouchers($salesInvoice['id']);

        if ($paidAmount <= 0) {
            $this->financeService->recalculateInvoicePaymentStatus((int)$salesInvoice['id']);
            return;
        }

        if (!in_array($resolvedFinance['delivery_type'], ['cash', 'bank_transfer'], true)) {
            throw new Exception('لا يمكن تسجيل مبلغ واصل إلا مع نقد أو تحويل بنكي.');
        }

        if (empty($resolvedFinance['account_id'])) {
            throw new Exception('حساب القبض المالي مطلوب عند وجود مبلغ واصل.');
        }

        if (empty($resolvedFinance['customer_id'])) {
            throw new Exception('العميل مطلوب عند وجود مبلغ واصل.');
        }

        $this->financeService->receiveInvoicePayment([
            'source_id' => (int)$salesInvoice['id'],
            'branch_id' => (int)$bookingContext['branch_id'],
            'customer_id' => (int)$resolvedFinance['customer_id'],
            'sale_currency_id' => (int)$resolvedFinance['sale_currency_id'],
            'account_id' => (int)$resolvedFinance['account_id'],
            'paid_amount' => $paidAmount,
            'description' => "دفعة للحجز رقم: {$bookingContext['booking_number']} للمسافر {$resolvedFinance['traveler_name']}",
            'source_number' => $bookingContext['booking_number'],
            'delivery_type' => $resolvedFinance['delivery_type'],
        ]);
    }

    private function deletePostedReceiptVouchers(int $invoiceId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT ft.id
            FROM payment_allocations pa
            JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
            WHERE pa.invoice_id = ?
              AND ft.status = 'posted'
              AND NOT (ft.reference_id = ? AND ft.reference_type = 'invoice')
        ");
        $stmt->execute([$invoiceId, $invoiceId]);
        $voucherIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($voucherIds as $voucherId) {
            $this->pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$voucherId]);
            $this->pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$voucherId]);
            $this->pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$voucherId]);
        }
    }

    private function updateDraftPurchaseInvoice(array $bookingContext, array $resolvedFinance): void
    {
        $purchaseInvoiceId = (int)$bookingContext['purchase_invoice_id'];
        if ($purchaseInvoiceId <= 0) {
            return;
        }

        $purchaseInvoice = $this->fetchInvoiceById($purchaseInvoiceId);
        if (!$purchaseInvoice || $purchaseInvoice['invoice_status'] !== 'draft') {
            return;
        }

        $this->pdo->prepare("
            UPDATE invoices
            SET total_amount = ?, currency_id = ?, description = ?, invoice_date = ?, source_type = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $resolvedFinance['purchase_price'],
            $resolvedFinance['purchase_currency_id'],
            $resolvedFinance['description'],
            $resolvedFinance['operation_date'],
            $resolvedFinance['source_type'],
            $purchaseInvoice['id'],
        ]);
    }
}
