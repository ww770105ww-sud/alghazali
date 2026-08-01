<?php
require_once __DIR__ . '/../core/FinanceService.php';
/**
 * المحرك المالي الموحد لخدمات وكالة الغزالي
 * يوحد منطق الفواتير، المدفوعات، والترحيل المحاسبي لجميع الخدمات
 */

class ServiceFinancialEngine {
    /** @var PDO */
    private $pdo;
    /** @var int|null */
    private $user_id;

    /**
     * @param PDO $pdo
     * @param int|null $user_id
     */
    public function __construct(PDO $pdo, ?int $user_id = null) {
        $this->pdo = $pdo;
        $this->user_id = $user_id ?: ($_SESSION['admin_id'] ?? 1);
    }

    /**
     * معالجة العملية المالية الكاملة للخدمة (إنشاء فواتير + دفعات + ترحيل)
     * @param array $data
     * @param bool $skip_transaction Whether to skip transaction handling (if you want to handle it yourself)
     * @return array
     * @throws Exception
     */
    public function processServiceFinance(array $data, bool $skip_transaction = false): array {
        $financeService = new FinanceService($this->pdo, $this->user_id);
        return $financeService->processServiceOperation($data);
    }

    /**
     * إنشاء فاتورة باستخدام الدوال المحاسبية الموحدة
     * @param array $params
     * @return int
     */
    private function createInvoice(array $params): int {
        $financeService = new FinanceService($this->pdo, $this->user_id);
        return $financeService->createInvoiceDraft($params, $params['category']);
    }

    /**
     * معالجة السند وربطه بالفاتورة
     * @param array $params
     * @return void
     * @throws Exception
     */
    private function processPayment(array $params): void {
        $financeService = new FinanceService($this->pdo, $this->user_id);
        $financeService->receiveInvoicePayment([
            'source_id' => $params['invoice_id'],
            'branch_id' => $params['branch_id'],
            'customer_id' => $params['customer_id'],
            'sale_currency_id' => $params['currency_id'],
            'account_id' => $params['account_id'],
            'paid_amount' => $params['amount'],
            'description' => $params['description'],
            'source_number' => $params['source_ref'] ?? null,
            'delivery_type' => 'cash',
        ]);
    }

    /**
     * تحديث حالة الدفع للفاتورة بناءً على التخصيصات
     * @param int $invoice_id
     * @return void
     */
    public function updateInvoicePaymentStatus(int $invoice_id): void {
        $financeService = new FinanceService($this->pdo, $this->user_id);
        $financeService->recalculateInvoicePaymentStatus($invoice_id);
    }
}
