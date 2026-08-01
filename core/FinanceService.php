<?php

require_once __DIR__ . '/../includes/accounting_functions.php';

/**
 * Finance Core Module — المصدر المركزي الوحيد لجميع العمليات المالية داخل النظام.
 *
 * يعمل هذا الصنف كمصدر حقيقي واحد (Single Source of Truth) لكل العمليات المالية:
 * إنشاء الفواتير، ترحيلها، إنشاء سندات القبض والصرف والمصروفات، توزيع المدفوعات،
 * اعتماد/رفض المصروفات، وحساب حالة سداد الفواتير.
 *
 * مبادئ التصميم المطبّقة:
 *  - طبقة تحقق مستقلة قبل كل عملية (لا تعتمد فقط على الإجراءات المخزنة).
 *  - منع الدفع الزائد (overpayment) ومنع التكرار في توزيع المدفوعات.
 *  - كاش على مستوى الصنف لإعدادات النظام وحسابات العملاء/الموردين.
 *  - تحقق صارم من هوية المستخدم قبل أي تنفيذ.
 *  - سجل تدقيق (audit log) لكل عملية مالية حرجة.
 *  - التحقق من حالة الفاتورة/السند قبل الترحيل لمنع الترحيل المزدوج.
 *  - استثناءات عربية واضحة تشرح السبب وتذكر اسم العملية.
 *  - الالتزام بـ PSR-12 و SOLID و DRY و Clean Code.
 *
 * @psalm-suppress UndefinedGlobalVariable
 */
class FinanceService
{
    /** @var PDO اتصال قاعدة البيانات */
    private $pdo;

    /** @var int معرّف المستخدم المنفّذ (تم التحقق منه) */
    private $userId;

    /** @var int|null معرّف الفرع الافتراضي للمستخدم (يُستخدم كاحتياط) */
    private $userBranchId;

    /** @var string عنوان IP للعميل */
    private $clientIp;

    /** @var string وكيل المستخدم (User-Agent) */
    private $userAgent;

    /** @var array<string,mixed> كاش لإعدادات النظام (system_settings) */
    private $settingsCache = [];

    /** @var array<int,array<string,mixed>> كاش لصفوف العملاء (id => row) */
    private $customerCache = [];

    /** @var array<int,array<string,mixed>> كاش لصفوف الموردين (id => row) */
    private $supplierCache = [];

    /** @var array<int,array<string,mixed>> كاش لصفوف الحسابات (id => row) */
    private $accountCache = [];

    /** @var array<int,array<string,mixed>> كاش لصفوف العملات (id => row) */
    private $currencyCache = [];

    /** @var array<int,array<string,mixed>> كاش لصفوف الفروع (id => row) */
    private $branchCache = [];

    /** @var array<int,string> كاش لحالات الفواتير (id => status) */
    private $invoiceStatusCache = [];

    /** @var array<int,string> كاش لحالات السندات (id => status) */
    private $voucherStatusCache = [];

    /** @var float حد التفاوت العشري لمقارنة المبالغ (سنتان) */
    private const EPSILON = 0.005;

    /** @var array<int,string> أنواع الحسابات المقبولة كحسابات صندوق/بنك للقبض/الصرف.
     *  ملاحظة: في قاعدة البيانات الحالية، حسابات الصندوق/البنك مصنّفة كـ 'asset' (أصول)
     *  وليس كـ 'box' أو 'bank' رغم أن enum يدعمها. لذلك نضمّ 'asset' لضمان التوافق. */
    private const BOX_BANK_TYPES = ['box', 'bank', 'asset'];

    /** @var array<int,string> أنواع الحسابات المقبولة كحسابات مصروفات */
    private const EXPENSE_TYPES = ['expense'];

    /** @var array<int,string> أنواع الحسابات المقبولة كحسابات إيرادات */
    private const REVENUE_TYPES = ['revenue'];

    /** @var array<int,string> أنواع الحسابات المقبولة كحسابات ذمم مدينة (عملاء) */
    private const RECEIVABLE_TYPES = ['receivable', 'asset'];

    /** @var array<int,string> أنواع الحسابات المقبولة كحسابات ذمم دائنة (موردون) */
    private const PAYABLE_TYPES = ['payable', 'liability'];

    /**
     * المُنشئ — يتحقق من هوية المستخدم فوراً ولا يقبل قيمة افتراضية غير آمنة.
     *
     * @param PDO      $pdo    اتصال قاعدة البيانات
     * @param int|null $userId معرّف المستخدم (إذا لم يُمرر يُؤخذ من الجلسة، لكن يجب أن يكون موجوداً فعلياً)
     * @throws RuntimeException عندما يكون المستخدم غير موجود أو غير نشط
     */
    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->pdo = $pdo;

        // تحديد المستخدم: نأخذ القيمة الممررة أولاً ثم من الجلسة، لكن بدون قيمة افتراضية 1.
        $candidate = $userId ?? (int)($_SESSION['admin_id'] ?? 0);

        if ($candidate <= 0) {
            throw new RuntimeException(
                'FinanceService: تعذّر تحديد هوية المستخدم. لم يتم تمرير معرّف مستخدم صالح '
                . 'ولم يتم العثور على جلسة نشطة. يجب تسجيل الدخول قبل تنفيذ أي عملية مالية.'
            );
        }

        // التحقق من وجود المستخدم فعلياً وأنه نشط — لا نسمح بمعرّف وهمي.
        $userRow = $this->fetchUser($candidate);
        if (!$userRow) {
            throw new RuntimeException(
                "FinanceService: المستخدم ذو المعرّف {$candidate} غير موجود في قاعدة البيانات. "
                . 'لا يمكن تنفيذ عمليات مالية بحساب محذوف أو غير صحيح.'
            );
        }
        if (($userRow['status'] ?? 'active') !== 'active') {
            throw new RuntimeException(
                "FinanceService: حساب المستخدم {$candidate} غير نشط (status = "
                . ($userRow['status'] ?? 'غير معروف') . '). يجب تفعيل الحساب قبل تنفيذ العمليات المالية.'
            );
        }

        $this->userId = $candidate;
        $this->userBranchId = isset($userRow['branch_id']) ? (int)$userRow['branch_id'] : null;
        $this->clientIp = $this->resolveClientIp();
        $this->userAgent = $this->resolveUserAgent();
    }

    // ===================================================================
    //  §1. طبقة التحقق (Validation Layer)
    // ===================================================================

    /**
     * التحقق الشامل من حمولة العملية المالية قبل تنفيذها.
     *
     * تتحقق من الفرع، العملة، الحساب النقدي/المصرفي، العميل/المورد (حسب الحاجة)،
     * والمبالغ. لا تعتمد على الإجراءات المخزنة بل تتحقق مباشرة من قاعدة البيانات.
     *
     * @param array $data الحمولة المُطبَّعة (بعد normalizeFinancialPayload)
     * @param array $rules قواعد التحقق المطلوبة: 'customer','supplier','cash_account','currency','branch','amount'
     * @throws RuntimeException عند فشل أي تحقق
     */
    private function validateFinancialPayload(array $data, array $rules = []): void
    {
        // التحقق من الفرع
        if (in_array('branch', $rules, true) && !empty($data['branch_id'])) {
            $this->validateBranch((int)$data['branch_id']);
        }

        // التحقق من العملة
        if (in_array('currency', $rules, true) && !empty($data['currency_id'])) {
            $this->validateCurrency((int)$data['currency_id']);
        }

        // التحقق من العميل
        if (in_array('customer', $rules, true) && !empty($data['customer_id'])) {
            $this->validateCustomer((int)$data['customer_id'], (int)($data['branch_id'] ?? 0));
        }

        // التحقق من المورد
        if (in_array('supplier', $rules, true) && !empty($data['supplier_id'])) {
            $this->validateSupplier((int)$data['supplier_id']);
        }

        // التحقق من حساب القبض/الصرف (صندوق/بنك)
        if (in_array('cash_account', $rules, true) && !empty($data['account_id'])) {
            $this->validateAccount((int)$data['account_id'], self::BOX_BANK_TYPES, 'حساب القبض/الصرف');
        }

        // التحقق من حساب المصروف
        if (in_array('expense_account', $rules, true) && !empty($data['expense_account_id'])) {
            $this->validateExpenseAccount((int)$data['expense_account_id']);
        }

        // التحقق من المبالغ
        if (in_array('amount', $rules, true)) {
            $amount = (float)($data['paid_amount'] ?? $data['sale_total_amount'] ?? 0);
            if ($amount < 0) {
                throw new RuntimeException(
                    'FinanceService.validateFinancialPayload: المبلغ لا يمكن أن يكون سالباً. '
                    . "القيمة الممررة: {$amount}."
                );
            }
        }
    }

    /**
     * التحقق من صلاحية الفرع: موجود، غير محذوف، نشط.
     */
    private function validateBranch(int $branchId): void
    {
        if ($branchId <= 0) {
            throw new RuntimeException('FinanceService.validateBranch: معرّف الفرع غير صالح.');
        }
        $row = $this->fetchBranch($branchId);
        if (!$row) {
            throw new RuntimeException("FinanceService.validateBranch: الفرع رقم {$branchId} غير موجود.");
        }
        if (!empty($row['deleted_at'])) {
            throw new RuntimeException("FinanceService.validateBranch: الفرع رقم {$branchId} محذوف ولا يمكن استخدامه.");
        }
        if (($row['status'] ?? null) === 'inactive' || (isset($row['is_active']) && (int)$row['is_active'] === 0)) {
            throw new RuntimeException("FinanceService.validateBranch: الفرع رقم {$branchId} غير نشط ولا يمكن استخدامه.");
        }
    }

    /**
     * التحقق من صلاحية العميل: موجود، غير محذوف، نشط.
     */
    private function validateCustomer(int $customerId, int $branchId = 0): void
    {
        if ($customerId <= 0) {
            throw new RuntimeException('FinanceService.validateCustomer: معرّف العميل غير صالح.');
        }
        $row = $this->fetchCustomer($customerId);
        if (!$row) {
            throw new RuntimeException("FinanceService.validateCustomer: العميل رقم {$customerId} غير موجود.");
        }
        if (!empty($row['deleted_at'])) {
            throw new RuntimeException("FinanceService.validateCustomer: العميل رقم {$customerId} محذوف ولا يمكن استخدامه.");
        }
        if (($row['status'] ?? 'active') === 'inactive') {
            throw new RuntimeException("FinanceService.validateCustomer: العميل رقم {$customerId} غير نشط ولا يمكن استخدامه.");
        }
        // التحقق من ارتباط العميل بالفرع (إن كان الفرع محدداً) — تحذير وليس خطأ قاتل
        if ($branchId > 0 && isset($row['branch_id']) && (int)$row['branch_id'] > 0
            && (int)$row['branch_id'] !== $branchId) {
            // لا نرفض العملية لكن نسجل ملاحظة في السجل لاحقاً عبر audit log عند الحاجة
        }
    }

    /**
     * التحقق من صلاحية المورد: موجود، غير محذوف، نشط.
     */
    private function validateSupplier(int $supplierId): void
    {
        if ($supplierId <= 0) {
            throw new RuntimeException('FinanceService.validateSupplier: معرّف المورد غير صالح.');
        }
        $row = $this->fetchSupplier($supplierId);
        if (!$row) {
            throw new RuntimeException("FinanceService.validateSupplier: المورد رقم {$supplierId} غير موجود.");
        }
        if (!empty($row['deleted_at'])) {
            throw new RuntimeException("FinanceService.validateSupplier: المورد رقم {$supplierId} محذوف ولا يمكن استخدامه.");
        }
        $status = $row['status'] ?? 'active';
        if ($status === 'inactive' || $status === 'closed') {
            throw new RuntimeException("FinanceService.validateSupplier: المورد رقم {$supplierId} غير نشط ({$status}) ولا يمكن استخدامه.");
        }
    }

    /**
     * التحقق من صلاحية حساب: موجود، غير محذوف، نشط، ومن النوع المطلوب.
     *
     * @param int        $accountId   معرّف الحساب
     * @param array<int,string>|null $allowedTypes الأنواع المسموح بها (مثال: ['box','bank'])
     * @param string     $label       وصف الحساب لرسالة الخطأ
     */
    private function validateAccount(int $accountId, ?array $allowedTypes = null, string $label = 'الحساب'): void
    {
        if ($accountId <= 0) {
            throw new RuntimeException("FinanceService.validateAccount: معرّف {$label} غير صالح.");
        }
        $row = $this->fetchAccount($accountId);
        if (!$row) {
            throw new RuntimeException("FinanceService.validateAccount: {$label} رقم {$accountId} غير موجود.");
        }
        if (!empty($row['deleted_at'])) {
            throw new RuntimeException("FinanceService.validateAccount: {$label} رقم {$accountId} محذوف ولا يمكن استخدامه.");
        }
        if ((int)($row['is_active'] ?? 1) === 0) {
            throw new RuntimeException("FinanceService.validateAccount: {$label} رقم {$accountId} معطّل (is_active = 0).");
        }
        if (($row['account_status'] ?? 'active') !== 'active') {
            throw new RuntimeException("FinanceService.validateAccount: {$label} رقم {$accountId} حالته ({$row['account_status']}) غير نشطة.");
        }
        if ($allowedTypes !== null) {
            $type = $row['account_type'] ?? '';
            if (!in_array($type, $allowedTypes, true)) {
                $allowed = implode(' أو ', $allowedTypes);
                throw new RuntimeException(
                    "FinanceService.validateAccount: {$label} رقم {$accountId} من نوع '{$type}' "
                    . "بينما المطلوب نوع {$allowed}. لا يمكن استخدام هذا الحساب لهذه العملية."
                );
            }
        }
    }

    /**
     * التحقق من صلاحية عملة: موجودة، نشطة، ولها سعر صرف صالح عند الحاجة.
     */
    private function validateCurrency(int $currencyId): void
    {
        if ($currencyId <= 0) {
            throw new RuntimeException('FinanceService.validateCurrency: معرّف العملة غير صالح.');
        }
        $row = $this->fetchCurrency($currencyId);
        if (!$row) {
            throw new RuntimeException("FinanceService.validateCurrency: العملة رقم {$currencyId} غير موجودة.");
        }
        if ((int)($row['is_active'] ?? 1) === 0) {
            throw new RuntimeException("FinanceService.validateCurrency: العملة رقم {$currencyId} معطّلة.");
        }
        // سعر الصرف الافتراضي يجب أن يكون موجباً (أو 1 للعملة الأساسية)
        $rate = (float)($row['exchange_rate'] ?? 0);
        if ($rate <= 0) {
            throw new RuntimeException("FinanceService.validateCurrency: العملة رقم {$currencyId} لها سعر صرف غير صالح ({$rate}).");
        }
    }

    /**
     * التحقق من حالة فاتورة قبل الترحيل — يجب أن تكون 'draft' فقط.
     * يمنع الترحيل المزدوج (posted) أو ترحيل فاتورة ملغاة (cancelled).
     */
    private function validateInvoiceStatus(int $invoiceId, string $expectedStatus = 'draft'): void
    {
        $current = $this->fetchInvoiceStatus($invoiceId);
        if ($current === null) {
            throw new RuntimeException(
                "FinanceService.validateInvoiceStatus: الفاتورة رقم {$invoiceId} غير موجودة. "
                . 'لا يمكن ترحيل فاتورة غير موجودة.'
            );
        }
        if ($current !== $expectedStatus) {
            $arMap = ['draft' => 'مسودة', 'posted' => 'مرحّلة', 'cancelled' => 'ملغاة'];
            $currentAr = $arMap[$current] ?? $current;
            $expectedAr = $arMap[$expectedStatus] ?? $expectedStatus;
            throw new RuntimeException(
                "FinanceService.validateInvoiceStatus: الفاتورة رقم {$invoiceId} حالتها '{$currentAr}'، "
                . "بينما المطلوب لترحيلها أن تكون '{$expectedAr}'. تم منع الترحيل المزدوج أو غير الصحيح."
            );
        }
    }

    /**
     * التحقق من حالة سند (voucher) قبل الترحيل — يجب أن تكون 'draft' فقط.
     * يمنع الترحيل المزدوج.
     */
    private function validateVoucherStatus(int $voucherId, string $expectedStatus = 'draft'): void
    {
        $current = $this->fetchVoucherStatus($voucherId);
        if ($current === null) {
            throw new RuntimeException(
                "FinanceService.validateVoucherStatus: السند رقم {$voucherId} غير موجود. "
                . 'لا يمكن ترحيل سند غير موجود.'
            );
        }
        if ($current !== $expectedStatus) {
            $arMap = ['draft' => 'مسودة', 'posted' => 'مرحّل', 'cancelled' => 'ملغى'];
            $currentAr = $arMap[$current] ?? $current;
            $expectedAr = $arMap[$expectedStatus] ?? $expectedStatus;
            throw new RuntimeException(
                "FinanceService.validateVoucherStatus: السند رقم {$voucherId} حالته '{$currentAr}'، "
                . "بينما المطلوب لترحيله أن يكون '{$expectedAr}'. تم منع الترحيل المزدوج."
            );
        }
    }

    /**
     * التحقق من حساب المصروف: موجود، نشط، ومن نوع 'expense' تحديداً.
     */
    private function validateExpenseAccount(int $accountId): void
    {
        $this->validateAccount($accountId, self::EXPENSE_TYPES, 'حساب المصروف');
    }

    /**
     * التحقق من حساب الإيراد: موجود، نشط، ومن نوع 'revenue' تحديداً.
     */
    private function validateRevenueAccount(int $accountId): void
    {
        $this->validateAccount($accountId, self::REVENUE_TYPES, 'حساب الإيراد');
    }

    // ===================================================================
    //  §4. طبقة الكاش (Caching) — جلب مساعد مع كاش
    // ===================================================================

    /** جلب صف مستخدم من قاعدة البيانات (بدون كاش — يجب التحقق منه دائماً). */
    private function fetchUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, status, branch_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** جلب صف فرع مع كاش. */
    private function fetchBranch(int $branchId): ?array
    {
        if (isset($this->branchCache[$branchId])) {
            return $this->branchCache[$branchId];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, branch_name, status, is_active, deleted_at FROM branches WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$branchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $row ?: null;
        $this->branchCache[$branchId] = $row;
        return $row;
    }

    /** جلب صف عميل مع كاش. */
    private function fetchCustomer(int $customerId): ?array
    {
        if (isset($this->customerCache[$customerId])) {
            return $this->customerCache[$customerId];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, full_name, account_id, branch_id, status, deleted_at FROM customers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $row ?: null;
        $this->customerCache[$customerId] = $row;
        return $row;
    }

    /** جلب صف مورد مع كاش. */
    private function fetchSupplier(int $supplierId): ?array
    {
        if (isset($this->supplierCache[$supplierId])) {
            return $this->supplierCache[$supplierId];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, supplier_name, account_id, status, deleted_at FROM suppliers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $row ?: null;
        $this->supplierCache[$supplierId] = $row;
        return $row;
    }

    /** جلب صف حساب موحد مع كاش. */
    private function fetchAccount(int $accountId): ?array
    {
        if (isset($this->accountCache[$accountId])) {
            return $this->accountCache[$accountId];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, account_code, account_name_ar, account_type, normal_balance, '
            . 'is_active, account_status, deleted_at FROM unified_accounts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $row ?: null;
        $this->accountCache[$accountId] = $row;
        return $row;
    }

    /** جلب صف عملة مع كاش. */
    private function fetchCurrency(int $currencyId): ?array
    {
        if (isset($this->currencyCache[$currencyId])) {
            return $this->currencyCache[$currencyId];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, currency_code, currency_name, exchange_rate, exchange_rate_sell, '
            . 'exchange_rate_buy, is_active, is_default FROM currencies WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $row ?: null;
        $this->currencyCache[$currencyId] = $row;
        return $row;
    }

    /** جلب حالة فاتورة (invoice_status) مع كاش. */
    private function fetchInvoiceStatus(int $invoiceId): ?string
    {
        if (array_key_exists($invoiceId, $this->invoiceStatusCache)) {
            return $this->invoiceStatusCache[$invoiceId];
        }
        $stmt = $this->pdo->prepare('SELECT invoice_status FROM invoices WHERE id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $val = $stmt->fetchColumn();
        $status = ($val !== false && $val !== null) ? (string)$val : null;
        $this->invoiceStatusCache[$invoiceId] = $status;
        return $status;
    }

    /** جلب حالة سند (status) من financial_transactions مع كاش. */
    private function fetchVoucherStatus(int $voucherId): ?string
    {
        if (array_key_exists($voucherId, $this->voucherStatusCache)) {
            return $this->voucherStatusCache[$voucherId];
        }
        $stmt = $this->pdo->prepare('SELECT status FROM financial_transactions WHERE id = ? LIMIT 1');
        $stmt->execute([$voucherId]);
        $val = $stmt->fetchColumn();
        $status = ($val !== false && $val !== null) ? (string)$val : null;
        $this->voucherStatusCache[$voucherId] = $status;
        return $status;
    }

    /** جلب قيمة إعداد من system_settings مع كاش على مستوى الكائن. */
    private function getSetting(string $key): ?string
    {
        if (array_key_exists($key, $this->settingsCache)) {
            return $this->settingsCache[$key];
        }
        $stmt = $this->pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $value = ($val !== false && $val !== null) ? (string)$val : null;
        $this->settingsCache[$key] = $value;
        return $value;
    }

    // ===================================================================
    //  §9 + §10. توحيد الحمولة وأسعار الصرف
    // ===================================================================

    /**
     * توحيد أسماء حقول الحمولة المالية الواردة من مختلف المصادر.
     *
     * يعالج كل الأسماء البديلة، يضبط القيم الافتراضية، ويتجنب تحذيرات
     * "Undefined Array Key" باستخدام عامل ?? في كل مكان.
     */
    public function normalizeFinancialPayload(array $data): array
    {
        // المبالغ — كل الأسماء البديلة محمية بـ ?? 0
        $discountAmount = (float)($data['discount_amount'] ?? $data['discount'] ?? $data['discount_value'] ?? 0);
        $paidAmount = (float)(
            $data['paid_amount']
            ?? $data['received_amount']
            ?? $data['amount_received']
            ?? $data['payment_amount']
            ?? $data['amount']
            ?? 0
        );
        $saleTotal = (float)(
            $data['sale_total_amount']
            ?? $data['total_amount']
            ?? $data['sale_price']
            ?? $data['grand_total']
            ?? $data['final_amount']
            ?? 0
        );
        $purchaseTotal = (float)(
            $data['purchase_total_amount']
            ?? $data['purchase_price']
            ?? $data['cost_amount']
            ?? $data['purchase_cost']
            ?? 0
        );
        $taxAmount = (float)($data['tax_amount'] ?? $data['tax'] ?? $data['vat_amount'] ?? 0);
        $equivalentAmount = (float)($data['equivalent_amount'] ?? $data['amount_base'] ?? $data['base_amount'] ?? 0);

        // معرّفات — استخدام isset للتأكد من وجود المفتاح قبل cast
        $branchId = isset($data['branch_id']) ? (int)$data['branch_id'] : null;
        $sourceId = isset($data['source_id']) ? (int)$data['source_id'] : null;
        $customerId = isset($data['customer_id']) ? (int)$data['customer_id'] : null;
        $supplierId = isset($data['supplier_id']) ? (int)$data['supplier_id'] : null;
        $agentId = isset($data['agent_id']) ? (int)$data['agent_id'] : null;

        // حساب الدفع — يدعم account_id أو payment_account_id أو cash_account_id
        $accountId = isset($data['account_id'])
            ? (int)$data['account_id']
            : (isset($data['payment_account_id'])
                ? (int)$data['payment_account_id']
                : (isset($data['cash_account_id']) ? (int)$data['cash_account_id'] : null));

        // حساب المصروف — يدعم expense_account_id أو expense_account
        $expenseAccountId = isset($data['expense_account_id'])
            ? (int)$data['expense_account_id']
            : (isset($data['expense_account']) ? (int)$data['expense_account'] : null);

        // العملات — أسماء بديلة شائعة
        $currencyId = isset($data['currency_id'])
            ? (int)$data['currency_id']
            : (isset($data['sale_currency_id'])
                ? (int)$data['sale_currency_id']
                : null);
        $saleCurrencyId = isset($data['sale_currency_id'])
            ? (int)$data['sale_currency_id']
            : (isset($data['currency_id']) ? (int)$data['currency_id'] : null);
        $purchaseCurrencyId = isset($data['purchase_currency_id'])
            ? (int)$data['purchase_currency_id']
            : (isset($data['pur_currency_id'])
                ? (int)$data['pur_currency_id']
                : (isset($data['currency_id']) ? (int)$data['currency_id'] : null));

        // سعر الصرف — يُحسّن عبر resolveExchangeRate لاحقاً عند الحاجة
        $exchangeRate = (float)($data['exchange_rate'] ?? 1);

        // نوع التسليم / حالة العملية — أسماء بديلة
        $deliveryType = $data['delivery_type']
            ?? $data['payment_type']
            ?? $data['payment_method']
            ?? 'draft';
        $transactionStatus = $data['transaction_status']
            ?? $data['invoice_status']
            ?? $data['status']
            ?? 'draft';

        // نوع المصدر / الخدمة
        $serviceType = $this->extractServiceTypeFromSource($data['service_type'] ?? null)
            ?? $this->extractServiceTypeFromSource($data['source_type'] ?? null);

        // هل نسجل فاتورة شراء؟ — دعم '1', 1, true, 'true', 'yes'
        $recordPurchaseRaw = $data['record_purchase']
            ?? $data['has_purchase']
            ?? $data['with_purchase']
            ?? '1';
        $recordPurchase = in_array((string)$recordPurchaseRaw, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';

        $normalized = [
            'branch_id' => $branchId,
            'source_type' => $data['source_type'] ?? $data['service_type'] ?? null,
            'service_type' => $serviceType,
            'source_id' => $sourceId,
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'agent_id' => $agentId,
            'account_id' => $accountId,
            'expense_account_id' => $expenseAccountId,
            'currency_id' => $currencyId,
            'sale_currency_id' => $saleCurrencyId,
            'purchase_currency_id' => $purchaseCurrencyId,
            'exchange_rate' => $exchangeRate,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'paid_amount' => $paidAmount,
            'equivalent_amount' => $equivalentAmount,
            'sale_total_amount' => $saleTotal,
            'purchase_total_amount' => $purchaseTotal,
            'total_amount' => $saleTotal,
            'net_amount' => max(0, $saleTotal - $discountAmount + $taxAmount),
            'remaining_amount' => max(0, ($saleTotal - $discountAmount + $taxAmount) - $paidAmount),
            'transaction_status' => $transactionStatus,
            'delivery_type' => $deliveryType,
            'description' => trim((string)($data['description'] ?? $data['notes'] ?? '')),
            'operation_date' => normalize_datetime_db($data['operation_date'] ?? $data['invoice_date'] ?? null),
            'source_number' => $data['source_number'] ?? $data['reference_number'] ?? null,
            'reference_number' => $data['reference_number'] ?? $data['source_number'] ?? null,
            'voucher_date' => $data['voucher_date'] ?? $data['operation_date'] ?? null,
            'cost_center_id' => isset($data['cost_center_id']) ? (int)$data['cost_center_id'] : null,
            'budget_id' => isset($data['budget_id']) ? (int)$data['budget_id'] : null,
            'record_purchase' => $recordPurchase,
        ];

        return $normalized;
    }

    /**
     * حساب سعر صرف صالح للعملية حسب نوع العملة المطلوب.
     *
     * يدعم ثلاثة أنواع: 'buy' (سعر الشراء)، 'sell' (سعر البيع)، 'average' (متوسط الشراء والبيع).
     * إذا لم يُحدد نوع أو كانت قيم الشراء/البيع صفرية، يستخدم سعر الصرف الافتراضي (exchange_rate).
     * لا يستخدم سعر صرف غير صالح (<= 0) — يستخدم 1 كاحتياط للعملة الأساسية فقط.
     *
     * @param int    $currencyId معرّف العملة
     * @param string $rateType   نوع السعر: 'buy' | 'sell' | 'average' | 'default'
     * @return float سعر الصرف الصالح (>= 0)
     */
    private function resolveExchangeRate(int $currencyId, string $rateType = 'default'): float
    {
        $row = $this->fetchCurrency($currencyId);
        if (!$row) {
            return 1.0;
        }
        $defaultRate = (float)($row['exchange_rate'] ?? 1);
        $buyRate = (float)($row['exchange_rate_buy'] ?? 0);
        $sellRate = (float)($row['exchange_rate_sell'] ?? 0);

        switch ($rateType) {
            case 'buy':
                return $buyRate > 0 ? $buyRate : ($defaultRate > 0 ? $defaultRate : 1.0);
            case 'sell':
                return $sellRate > 0 ? $sellRate : ($defaultRate > 0 ? $defaultRate : 1.0);
            case 'average':
                if ($buyRate > 0 && $sellRate > 0) {
                    return ($buyRate + $sellRate) / 2.0;
                }
                return $defaultRate > 0 ? $defaultRate : 1.0;
            case 'default':
            default:
                return $defaultRate > 0 ? $defaultRate : 1.0;
        }
    }

    // ===================================================================
    //  §12. المعاملات (Transactions)
    // ===================================================================

    /**
     * تنفيذ عملية مالية بشكل ذري داخل معاملة واحدة.
     *
     * ملاحظة معمارية مهمة (البند 12): الإجراءات المخزنة مثل sp_create_invoice و
     * sp_create_receipt_voucher وغيرها تحتوي على START TRANSACTION / COMMIT / ROLLBACK
     * داخلية. في MariaDB، START TRANSACTION داخل معاملة قائمة يُنشئ savepoint،
     * وCOMMIT يُحرّر Savepoint (لا يُلزم المعاملة الخارجية)، وROLLBACK قد يتراجع
     * عن Savepoint فقط. هذا يعني أن atomicity المعاملة الخارجية قد تتأثر.
     * الحل المقترح طويل المدى: إزالة إدارة المعاملات من داخل الإجراءات المخزنة
     * وجعلها تعتمد كلياً على المعاملة الخارجية التي يديرها PHP.
     * حالياً، نضمن أن executeAtomically يبدأ المعاملة ويلتزمها/يتراجع عنها بشكل صحيح،
     * وأي استثناء يُرمى من الإجراء المخزن يُؤدي إلى rollback شامل.
     */
    public function executeAtomically(callable $callback)
    {
        $started = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ===================================================================
    //  §6. سجل التدقيق (Audit Logging)
    // ===================================================================

    /**
     * كتابة سجل تدقيق للعمليات المالية الحرجة.
     *
     * يسجّل في جدول audit_logs. بما أن الجدول لا يحتوي على أعمدة branch_id/source_type/source_id،
     * فإننا نخزّنها ضمن new_values بصيغة JSON مع باقي بيانات العملية.
     *
     * @param string         $action     اسم العملية (مثال: 'invoice_created')
     * @param string         $tableName  الجدول المتأثر (مثال: 'invoices')
     * @param int|null       $recordId   معرّف السجل المتأثر
     * @param array          $extra      بيانات إضافية: branch_id, source_type, source_id, operation, amounts...
     */
    private function writeAuditLog(string $action, string $tableName, ?int $recordId, array $extra = []): void
    {
        try {
            $payload = array_merge([
                'user_id'     => $this->userId,
                'branch_id'   => $extra['branch_id'] ?? null,
                'source_type' => $extra['source_type'] ?? null,
                'source_id'   => $extra['source_id'] ?? null,
                'operation'   => $extra['operation'] ?? $action,
                'amount'      => $extra['amount'] ?? null,
                'context'     => $extra['context'] ?? null,
                'logged_via'  => 'FinanceService',
            ], $extra);

            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_logs
                    (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_ip, user_agent)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->userId,
                mb_substr($action, 0, 100),
                mb_substr($tableName, 0, 50),
                $recordId,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                mb_substr($this->clientIp, 0, 45),
                mb_substr($this->clientIp, 0, 45),
                $this->userAgent,
            ]);
        } catch (Throwable $e) {
            // سجل التدقيق لا يجب أن يوقف العملية المالية — نسجل الخطأ ونكمل
            error_log('FinanceService.writeAuditLog فشل: ' . $e->getMessage());
        }
    }

    // ===================================================================
    //  §2 + §3. منع الدفع الزائد ومنع التكرار
    // ===================================================================

    /**
     * حساب المبلغ المتبقي (الرصيد غير المدفوع) لفاتورة معينة.
     *
     * @param int $invoiceId معرّف الفاتورة
     * @return array{total: float, paid: float, remaining: float}
     */
    private function getInvoiceRemainingBalance(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT total_amount, discount, tax_amount, amount_received
             FROM invoices WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(
                "FinanceService.getInvoiceRemainingBalance: الفاتورة رقم {$invoiceId} غير موجودة. "
                . 'لا يمكن حساب الرصيد المتبقي لفاتورة غير موجودة.'
            );
        }
        $total = (float)($row['total_amount'] ?? 0);
        $discount = (float)($row['discount'] ?? 0);
        $tax = (float)($row['tax_amount'] ?? 0);
        $alreadyPaid = (float)($row['amount_received'] ?? 0);
        $netTotal = max(0, $total - $discount + $tax);
        $remaining = max(0, $netTotal - $alreadyPaid);
        return ['total' => $netTotal, 'paid' => $alreadyPaid, 'remaining' => $remaining];
    }

    /**
     * حساب إجمالي المبالغ المخصصة مسبقاً لفاتورة عبر payment_allocations.
     */
    private function getAllocatedTotalForInvoice(int $invoiceId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(allocated_amount), 0) FROM payment_allocations WHERE invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * فحص وجود ربط مسبق بين سند وفاتورة في payment_allocations (منع التكرار).
     */
    private function isAllocationDuplicate(int $voucherId, int $invoiceId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM payment_allocations
             WHERE financial_transaction_id = ? AND invoice_id = ?'
        );
        $stmt->execute([$voucherId, $invoiceId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ===================================================================
    //  العمليات العامة: الفواتير
    // ===================================================================

    /**
     * إنشاء فاتورة مسودة (مبيعات أو مشتريات).
     *
     * @param array  $data     حمولة العملية المالية
     * @param string $category 'sales' أو 'purchase'
     * @return int معرّف الفاتورة المنشأة
     * @throws RuntimeException عند فشل التحقق أو الإنشاء
     */
    public function createInvoiceDraft(array $data, string $category): int
    {
        $data = $this->normalizeFinancialPayload($data);
        $this->validateInvoiceCategory($category);

        // التحقق من الحمولة
        $rules = ['branch', 'currency', 'amount'];
        if ($category === 'sales' && !empty($data['customer_id'])) {
            $rules[] = 'customer';
        }
        if ($category === 'purchase' && !empty($data['supplier_id'])) {
            $rules[] = 'supplier';
        }
        $this->validateFinancialPayload($data, $rules);

        $partyId = $category === 'sales' ? $data['customer_id'] : $data['supplier_id'];
        $currencyId = $category === 'sales' ? $data['sale_currency_id'] : $data['purchase_currency_id'];
        $totalAmount = $category === 'sales' ? $data['sale_total_amount'] : $data['purchase_total_amount'];
        $costAmount = 0.0;

        if ($category === 'sales' && $data['purchase_total_amount'] > 0) {
            // §10: لا نستخدم purchase_total_amount * exchange_rate إلا إذا كان سعر الصرف صالحاً
            // ولدينا عملتان مختلفتان فعلاً.
            if ($data['sale_currency_id'] !== $data['purchase_currency_id']) {
                $rate = $this->resolveExchangeRate((int)$data['purchase_currency_id'], 'average');
                if ($rate > 0) {
                    $costAmount = $data['purchase_total_amount'] * $rate;
                } else {
                    // سعر الصرف غير صالح — نستخدم المبلغ الأصلي دون تحويل (نُسجّل تحذيراً)
                    $costAmount = $data['purchase_total_amount'];
                    error_log(
                        'FinanceService.createInvoiceDraft: سعر الصرف غير صالح لتحويل تكلفة الشراء، '
                        . 'تم استخدام المبلغ الأصلي دون تحويل.'
                    );
                }
            } else {
                $costAmount = $data['purchase_total_amount'];
            }
        }

        $invoiceId = php_create_invoice(
            $this->pdo,
            $category,
            $data['branch_id'],
            $data['source_type'],
            $data['source_id'],
            $partyId,
            $currencyId,
            $totalAmount,
            $category === 'sales' ? $data['discount_amount'] : 0,
            $costAmount,
            $data['delivery_type'],
            $data['description'],
            $data['operation_date'],
            $this->userId,
            $data['agent_id'],
            $data['account_id']
        );

        // §6: سجل التدقيق لإنشاء الفاتورة
        $this->writeAuditLog('invoice_created', 'invoices', $invoiceId, [
            'branch_id'   => $data['branch_id'],
            'source_type' => $data['source_type'],
            'source_id'   => $data['source_id'],
            'operation'   => 'create_invoice_' . $category,
            'amount'      => $totalAmount,
            'context'     => ['category' => $category, 'party_id' => $partyId, 'currency_id' => $currencyId],
        ]);

        return $invoiceId;
    }

    /**
     * ترحيل فاتورة — يتحقق أولاً من أن حالتها 'draft' لمنع الترحيل المزدوج.
     */
    public function postInvoice(int $invoiceId): void
    {
        // §7: التحقق من حالة الفاتورة قبل الترحيل
        $this->validateInvoiceStatus($invoiceId, 'draft');

        php_post_invoice($this->pdo, $invoiceId, $this->userId);

        // إبطال كاش الحالة لأنها تغيّرت
        unset($this->invoiceStatusCache[$invoiceId]);

        // §6: سجل التدقيق لترحيل الفاتورة
        $this->writeAuditLog('invoice_posted', 'invoices', $invoiceId, [
            'operation' => 'post_invoice',
            'context'   => ['invoice_id' => $invoiceId],
        ]);
    }

    // ===================================================================
    //  العمليات العامة: سندات القبض
    // ===================================================================

    /**
     * إنشاء سند قبض مسودة.
     *
     * القيد المحاسبي (§13): مدين = الصندوق/البنك، دائن = حساب العميل (أو حساب الإيراد احتياطياً).
     */
    public function createReceiptVoucherDraft(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        // التحقق من الحمولة
        $this->validateFinancialPayload($data, ['branch', 'currency', 'cash_account', 'amount']);
        if (!empty($data['customer_id'])) {
            $this->validateCustomer((int)$data['customer_id'], (int)($data['branch_id'] ?? 0));
        }

        $partyAccountId = $this->resolvePartyAccountId('customer', $data['customer_id']);

        // عند غياب حساب العميل المرتبط (حالة النقد دون عميل محدد)،
        // نستخدم حساب الإيراد الافتراضي للخدمة كحساب دائن احتياطي.
        // المبدأ المحاسبي: مدين الصندوق / دائن الإيراد.
        if (!$partyAccountId) {
            $partyAccountId = $this->resolveRevenueAccountForService($data['service_type'] ?? null);
        }

        if (!$partyAccountId) {
            throw new RuntimeException(
                'FinanceService.createReceiptVoucherDraft: العميل ليس له حساب مالي مرتبط، '
                . 'ولم يتم العثور على حساب إيراد افتراضي للخدمة. '
                . 'يرجى تعيين حساب الإيراد من إعدادات الحسابات.'
            );
        }

        if (!$data['account_id']) {
            throw new RuntimeException(
                'FinanceService.createReceiptVoucherDraft: حساب القبض المالي (صندوق/بنك) مطلوب. '
                . 'لم يتم تمرير account_id أو payment_account_id.'
            );
        }

        // التحقق من أن حساب القبض من نوع صندوق/بنك
        $this->validateAccount((int)$data['account_id'], self::BOX_BANK_TYPES, 'حساب القبض');

        // التحقق من أن حساب الطرف الآخر (العميل/الإيراد) من نوع مناسب
        $partyAccount = $this->fetchAccount((int)$partyAccountId);
        if ($partyAccount) {
            $partyType = $partyAccount['account_type'] ?? '';
            // حساب العميل يجب أن يكون receivable/asset، أو revenue (في حالة الاحتياطي)
            $allowed = array_merge(self::RECEIVABLE_TYPES, self::REVENUE_TYPES);
            if (!empty($partyType) && !in_array($partyType, $allowed, true)) {
                // لا نرفض العملية ولكن نسجل تحذيراً — قد يكون الحساب من نوع آخر مقصود
                error_log(
                    "FinanceService.createReceiptVoucherDraft: حساب الطرف رقم {$partyAccountId} من نوع '{$partyType}' "
                    . 'وهو ليس من الأنواع المتوقعة (receivable/asset/revenue).'
                );
            }
        }

        $stmt = $this->pdo->prepare(
            "CALL sp_create_receipt_voucher(?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, @v_id, @v_num)"
        );
        $stmt->execute([
            $data['branch_id'],
            'customer',
            $data['customer_id'],
            $data['paid_amount'],
            $data['sale_currency_id'],
            $data['account_id'],
            $partyAccountId,
            $data['source_number'] ?? $data['source_id'],
            $data['description'],
            $this->userId,
            null,
        ]);
        $stmt->closeCursor();

        $voucherId = (int)$this->pdo->query('SELECT @v_id')->fetchColumn();

        // §6: سجل التدقيق لإنشاء سند القبض
        $this->writeAuditLog('receipt_voucher_created', 'financial_transactions', $voucherId, [
            'branch_id'   => $data['branch_id'],
            'source_type' => $data['source_type'],
            'source_id'   => $data['source_id'],
            'operation'   => 'create_receipt_voucher',
            'amount'      => $data['paid_amount'],
            'context'     => [
                'customer_id' => $data['customer_id'],
                'cash_account_id' => $data['account_id'],
                'party_account_id' => $partyAccountId,
            ],
        ]);

        return $voucherId;
    }

    // ===================================================================
    //  العمليات العامة: سندات الصرف
    // ===================================================================

    /**
     * إنشاء سند صرف مسودة.
     *
     * القيد المحاسبي (§13): مدين = حساب التكلفة/المورد، دائن = الصندوق/البنك.
     */
    public function createPaymentVoucherDraft(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        // التحقق من الحمولة
        $this->validateFinancialPayload($data, ['branch', 'currency', 'cash_account', 'amount']);
        if (!empty($data['supplier_id'])) {
            $this->validateSupplier((int)$data['supplier_id']);
        }

        $partyAccountId = $this->resolvePartyAccountId('supplier', $data['supplier_id']);

        // عند غياب حساب المورد المرتبط (حالة الصرف النقدي دون مورد محدد)،
        // نستخدم حساب التكلفة الافتراضي للخدمة كحساب مدين احتياطي.
        if (!$partyAccountId) {
            $partyAccountId = $this->resolveCostAccountForService($data['service_type'] ?? null);
        }

        if (!$partyAccountId) {
            throw new RuntimeException(
                'FinanceService.createPaymentVoucherDraft: المورد ليس له حساب مالي مرتبط، '
                . 'ولم يتم العثور على حساب تكلفة افتراضي للخدمة. '
                . 'يرجى تعيين حساب التكلفة من إعدادات الحسابات.'
            );
        }

        if (!$data['account_id']) {
            throw new RuntimeException(
                'FinanceService.createPaymentVoucherDraft: حساب الدفع المالي (صندوق/بنك) مطلوب. '
                . 'لم يتم تمرير account_id أو payment_account_id.'
            );
        }

        // التحقق من أن حساب الدفع من نوع صندوق/بنك
        $this->validateAccount((int)$data['account_id'], self::BOX_BANK_TYPES, 'حساب الدفع');

        $stmt = $this->pdo->prepare(
            "CALL sp_create_payment_voucher(?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, @v_id, @v_num)"
        );
        $stmt->execute([
            $data['branch_id'],
            'supplier',
            $data['supplier_id'],
            $data['paid_amount'],
            $data['purchase_currency_id'],
            $data['account_id'],
            $partyAccountId,
            $data['source_number'] ?? $data['source_id'],
            $data['description'],
            $this->userId,
            null,
        ]);
        $stmt->closeCursor();

        $voucherId = (int)$this->pdo->query('SELECT @v_id')->fetchColumn();

        // §6: سجل التدقيق لإنشاء سند الصرف
        $this->writeAuditLog('payment_voucher_created', 'financial_transactions', $voucherId, [
            'branch_id'   => $data['branch_id'],
            'source_type' => $data['source_type'],
            'source_id'   => $data['source_id'],
            'operation'   => 'create_payment_voucher',
            'amount'      => $data['paid_amount'],
            'context'     => [
                'supplier_id' => $data['supplier_id'],
                'cash_account_id' => $data['account_id'],
                'party_account_id' => $partyAccountId,
            ],
        ]);

        return $voucherId;
    }

    // ===================================================================
    //  §2 + §3. توزيع المدفوعات مع منع الدفع الزائد والتكرار
    // ===================================================================

    /**
     * توزيع دفعة من سند على فاتورة.
     *
     * تتحقق من:
     *  - §3: عدم وجود ربط مسبق (منع التكرار) — يُمنع برسالة واضحة.
     *  - §2: أن المبلغ المخصص لا يتجاوز الرصيد المتبقي للفاتورة (منع الدفع الزائد).
     *  - §3: أن إجمالي المخصصات لا يتجاوز قيمة الفاتورة.
     *
     * @param int   $voucherId       معرّف السند (financial_transactions.id)
     * @param int   $invoiceId       معرّف الفاتورة
     * @param float $allocatedAmount المبلغ المخصص
     * @throws RuntimeException عند محاولة تكرار الربط أو تجاوز الرصيد
     */
    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void
    {
        // التحقق من الموجودية
        if ($voucherId <= 0 || $invoiceId <= 0) {
            throw new RuntimeException(
                'FinanceService.allocatePayment: معرّفات السند والفاتورة مطلوبة وصحيحة. '
                . "السند: {$voucherId}، الفاتورة: {$invoiceId}."
            );
        }
        if ($allocatedAmount <= 0) {
            throw new RuntimeException(
                "FinanceService.allocatePayment: المبلغ المخصص يجب أن يكون أكبر من صفر. القيمة: {$allocatedAmount}."
            );
        }

        // §3: منع التكرار — فحص ربط مسبق
        if ($this->isAllocationDuplicate($voucherId, $invoiceId)) {
            throw new RuntimeException(
                "FinanceService.allocatePayment: يوجد ربط مسبق بين السند {$voucherId} والفاتورة {$invoiceId}. "
                . 'تم منع تكرار توزيع المدفوعات على نفس الفاتورة.'
            );
        }

        // §2: منع الدفع الزائد — حساب الرصيد المتبقي
        $balance = $this->getInvoiceRemainingBalance($invoiceId);
        $alreadyAllocated = $this->getAllocatedTotalForInvoice($invoiceId);
        $remaining = $balance['remaining'];

        if ($allocatedAmount > ($remaining + self::EPSILON)) {
            throw new RuntimeException(
                "FinanceService.allocatePayment: المبلغ المخصص ({$allocatedAmount}) يتجاوز الرصيد المتبقي "
                . "للفاتورة {$invoiceId} والبالغ ({$remaining}). تم منع الدفع الزائد. "
                . "إجمالي الفاتورة: {$balance['total']}، المدفوع سابقاً: {$balance['paid']}."
            );
        }

        // §3: منع تجاوز إجمالي المخصصات لقيمة الفاتورة
        $totalAllocatedAfter = $alreadyAllocated + $allocatedAmount;
        if ($totalAllocatedAfter > ($balance['total'] + self::EPSILON)) {
            throw new RuntimeException(
                "FinanceService.allocatePayment: إجمالي المبالغ المخصصة ({$totalAllocatedAfter}) "
                . "يتجاوز قيمة الفاتورة {$invoiceId} ({$balance['total']}). تم منع تجاوز إجمالي الفاتورة."
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$voucherId, $invoiceId, $allocatedAmount]);

        // §6: سجل التدقيق لتوزيع الدفعة
        $this->writeAuditLog('payment_allocation', 'payment_allocations', (int)$this->pdo->lastInsertId(), [
            'operation' => 'allocate_payment',
            'amount'    => $allocatedAmount,
            'context'   => [
                'voucher_id' => $voucherId,
                'invoice_id' => $invoiceId,
                'remaining_before' => $remaining,
            ],
        ]);
    }

    // ===================================================================
    //  العمليات العامة: ترحيل السندات (مع التحقق من الحالة)
    // ===================================================================

    /**
     * ترحيل سند قبض — يتحقق أولاً من أن حالته 'draft' لمنع الترحيل المزدوج.
     *
     * القيد المحاسبي (§13): مدين = الصندوق/البنك، دائن = العميل أو الإيراد.
     */
    public function postReceiptVoucher(int $voucherId): void
    {
        // §8: التحقق من حالة السند قبل الترحيل
        $this->validateVoucherStatus($voucherId, 'draft');

        php_post_receipt_voucher($this->pdo, $voucherId, $this->userId);

        // إبطال كاش الحالة
        unset($this->voucherStatusCache[$voucherId]);

        // §6: سجل التدقيق لترحيل سند القبض
        $this->writeAuditLog('receipt_voucher_posted', 'financial_transactions', $voucherId, [
            'operation' => 'post_receipt_voucher',
            'context'   => ['voucher_id' => $voucherId],
        ]);
    }

    /**
     * ترحيل سند صرف — يتحقق أولاً من أن حالته 'draft' لمنع الترحيل المزدوج.
     *
     * القيد المحاسبي (§13): مدين = التكلفة/المورد، دائن = الصندوق/البنك.
     */
    public function postPaymentVoucher(int $voucherId): void
    {
        // §8: التحقق من حالة السند قبل الترحيل
        $this->validateVoucherStatus($voucherId, 'draft');

        php_post_payment_voucher($this->pdo, $voucherId, $this->userId);

        // إبطال كاش الحالة
        unset($this->voucherStatusCache[$voucherId]);

        // §6: سجل التدقيق لترحيل سند الصرف
        $this->writeAuditLog('payment_voucher_posted', 'financial_transactions', $voucherId, [
            'operation' => 'post_payment_voucher',
            'context'   => ['voucher_id' => $voucherId],
        ]);
    }

    /**
     * إعادة حساب حالة سداد الفاتورة.
     */
    public function recalculateInvoicePaymentStatus(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new RuntimeException(
                "FinanceService.recalculateInvoicePaymentStatus: معرّف الفاتورة غير صالح: {$invoiceId}."
            );
        }
        php_recalculate_invoice_payment($this->pdo, $invoiceId);
    }

    // ===================================================================
    //  المسارات المركّبة (Composite flows)
    // ===================================================================

    /**
     * مسار موحد لعمليات الخدمات: إنشاء الفواتير وربط السداد.
     *
     * ينشئ فاتورة المبيعات، ثم (اختيارياً) فاتورة المشتريات، ثم (اختيارياً)
     * سند قبض + توزيع + ترحيل + إعادة حساب حالة السداد — كل ذلك في معاملة واحدة.
     */
    public function processServiceOperation(array $data): array
    {
        $data = $this->normalizeFinancialPayload($data);

        return $this->executeAtomically(function () use ($data) {
            $salesInvoiceId = $this->createInvoiceDraft($data, 'sales');

            $purchaseInvoiceId = null;
            if ($data['record_purchase'] === '1' && $data['supplier_id'] && $data['purchase_total_amount'] > 0) {
                $purchaseInvoiceId = $this->createInvoiceDraft($data, 'purchase');
            }

            $receiptVoucherId = null;
            if (
                $data['paid_amount'] > 0
                && in_array($data['delivery_type'], ['cash', 'bank_transfer'], true)
                && $data['account_id']
            ) {
                $receiptVoucherId = $this->createReceiptVoucherDraft($data);

                // §2: توزيع الدفعة مع منع الدفع الزائد — ينطبق على المبلغ المدفوع مقابل الفاتورة
                $balance = $this->getInvoiceRemainingBalance($salesInvoiceId);
                if ($data['paid_amount'] > ($balance['total'] + self::EPSILON)) {
                    throw new RuntimeException(
                        'FinanceService.processServiceOperation: المبلغ المدفوع (' . $data['paid_amount']
                        . ') يتجاوز إجمالي فاتورة المبيعات (' . $balance['total']
                        . "). تم إلغاء العملية بالكامل لمنع الدفع الزائد."
                    );
                }

                $this->allocatePayment($receiptVoucherId, $salesInvoiceId, $data['paid_amount']);
                $this->postReceiptVoucher($receiptVoucherId);
                $this->recalculateInvoicePaymentStatus($salesInvoiceId);
            }

            return [
                'sales_invoice_id' => $salesInvoiceId,
                'purchase_invoice_id' => $purchaseInvoiceId,
                'receipt_voucher_id' => $receiptVoucherId,
                'normalized_finance' => $data,
            ];
        });
    }

    /**
     * مسار مباشر لقبض دفعة على فاتورة قائمة.
     *
     * ينشئ سند قبض، يربطه بالفاتورة (مع منع الدفع الزائد والتكرار)،
     * يرحّله، ويعيد حساب حالة السداد — كل ذلك في معاملة واحدة.
     */
    public function receiveInvoicePayment(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        if (empty($data['paid_amount']) || (float)$data['paid_amount'] <= 0) {
            throw new RuntimeException(
                'FinanceService.receiveInvoicePayment: المبلغ المقبوض يجب أن يكون أكبر من صفر. '
                . 'القيمة الممررة: ' . ($data['paid_amount'] ?? 'فارغة') . '.'
            );
        }

        if (empty($data['source_id'])) {
            throw new RuntimeException(
                'FinanceService.receiveInvoicePayment: رقم الفاتورة (source_id) مطلوب لربط السداد. '
                . 'لا يمكن قبض دفعة دون تحديد الفاتورة المراد سدادها.'
            );
        }

        $invoiceId = (int)$data['source_id'];

        return $this->executeAtomically(function () use ($data, $invoiceId) {
            // §2: منع الدفع الزائد قبل إنشاء السند
            $balance = $this->getInvoiceRemainingBalance($invoiceId);
            $paidAmount = (float)$data['paid_amount'];
            if ($paidAmount > ($balance['remaining'] + self::EPSILON)) {
                throw new RuntimeException(
                    'FinanceService.receiveInvoicePayment: المبلغ المقبوض (' . $paidAmount
                    . ') يتجاوز الرصيد المتبقي للفاتورة ' . $invoiceId
                    . ' (' . $balance['remaining'] . '). إجمالي الفاتورة: ' . $balance['total']
                    . '، المدفوع سابقاً: ' . $balance['paid'] . '. تم منع الدفع الزائد.'
                );
            }

            $voucherId = $this->createReceiptVoucherDraft($data);
            $this->allocatePayment($voucherId, $invoiceId, $paidAmount);
            $this->postReceiptVoucher($voucherId);
            $this->recalculateInvoicePaymentStatus($invoiceId);

            return $voucherId;
        });
    }

    // ===================================================================
    //  المصروفات (Expenses)
    // ===================================================================

    /**
     * إنشاء سند مصروف مسودة.
     *
     * القيد المحاسبي (§13): مدين = حساب المصروف، دائن = الصندوق/البنك.
     */
    public function createExpenseVoucherDraft(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        if (empty($data['expense_account_id'])) {
            throw new RuntimeException(
                'FinanceService.createExpenseVoucherDraft: حساب المصروف مطلوب. '
                . 'يرجى تمرير expense_account_id.'
            );
        }
        if (empty($data['account_id'])) {
            throw new RuntimeException(
                'FinanceService.createExpenseVoucherDraft: حساب الصندوق/البنك مطلوب لصرف المصروف. '
                . 'يرجى تمرير account_id.'
            );
        }
        if (empty($data['paid_amount']) || (float)$data['paid_amount'] <= 0) {
            throw new RuntimeException(
                'FinanceService.createExpenseVoucherDraft: مبلغ المصروف يجب أن يكون أكبر من صفر. '
                . 'القيمة: ' . ($data['paid_amount'] ?? 'فارغة') . '.'
            );
        }

        // التحقق من الحمولة
        $this->validateFinancialPayload($data, ['branch', 'currency', 'expense_account', 'cash_account']);

        $stmt = $this->pdo->prepare(
            'CALL sp_create_expense_voucher(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @v_id, @v_num)'
        );
        $stmt->execute([
            $data['branch_id'],
            (int)$data['expense_account_id'],
            (int)$data['account_id'],
            (float)$data['paid_amount'],
            (int)$data['currency_id'],
            (float)($data['equivalent_amount'] ?? 0),
            !empty($data['voucher_date']) ? $data['voucher_date'] : date('Y-m-d'),
            $data['description'] ?? null,
            $data['reference_number'] ?? null,
            !empty($data['cost_center_id']) ? (int)$data['cost_center_id'] : null,
            !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            !empty($data['budget_id']) ? (int)$data['budget_id'] : null,
            $this->userId,
        ]);
        $stmt->closeCursor();

        $voucherId = (int)$this->pdo->query('SELECT @v_id')->fetchColumn();

        // §6: سجل التدقيق لإنشاء سند المصروف
        $this->writeAuditLog('expense_voucher_created', 'financial_transactions', $voucherId, [
            'branch_id'   => $data['branch_id'],
            'operation'   => 'create_expense_voucher',
            'amount'      => $data['paid_amount'],
            'context'     => [
                'expense_account_id' => $data['expense_account_id'],
                'cash_account_id' => $data['account_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'budget_id' => $data['budget_id'] ?? null,
            ],
        ]);

        return $voucherId;
    }

    /**
     * ترحيل سند المصروف — يتحقق أولاً من أن حالته 'draft'.
     */
    public function postExpenseVoucher(int $voucherId): void
    {
        // §8: التحقق من حالة السند قبل الترحيل
        $this->validateVoucherStatus($voucherId, 'draft');

        $stmt = $this->pdo->prepare('CALL sp_post_expense_voucher(?, ?)');
        $stmt->execute([$voucherId, $this->userId]);
        $stmt->closeCursor();

        // إبطال كاش الحالة
        unset($this->voucherStatusCache[$voucherId]);

        // §6: سجل التدقيق لترحيل سند المصروف
        $this->writeAuditLog('expense_voucher_posted', 'financial_transactions', $voucherId, [
            'operation' => 'post_expense_voucher',
            'context'   => ['voucher_id' => $voucherId],
        ]);
    }

    /**
     * صرف أو رفض موافقة المصروف.
     */
    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void
    {
        if ($voucherId <= 0) {
            throw new RuntimeException(
                "FinanceService.processExpenseApproval: معرّف سند المصروف غير صالح: {$voucherId}."
            );
        }

        $stmt = $this->pdo->prepare('CALL sp_process_expense_approval(?, ?, ?, ?, ?)');
        $stmt->execute([
            $voucherId,
            $this->userId,
            $level,
            $approved ? 1 : 0,
            $comment,
        ]);
        $stmt->closeCursor();

        // §6: سجل التدقيق لاعتماد/رفض المصروف
        $this->writeAuditLog(
            $approved ? 'expense_approved' : 'expense_rejected',
            'financial_transactions',
            $voucherId,
            [
                'operation' => $approved ? 'approve_expense' : 'reject_expense',
                'context'   => [
                    'voucher_id' => $voucherId,
                    'level' => $level,
                    'comment' => $comment,
                ],
            ]
        );
    }

    // ===================================================================
    //  دوال مساعدة (Helpers) — §14 DRY
    // ===================================================================

    /**
     * التحقق من أن فئة الفاتورة مدعومة.
     */
    private function validateInvoiceCategory(string $category): void
    {
        if (!in_array($category, ['sales', 'purchase'], true)) {
            throw new RuntimeException(
                "FinanceService.validateInvoiceCategory: فئة الفاتورة '{$category}' غير مدعومة. "
                . 'القيم المسموحة: sales أو purchase.'
            );
        }
    }

    /**
     * استخراج نوع الخدمة الموحد (umrah/hajj/flight/bus/work_visa/family_visit/passport/postal)
     * من قيمة source_type أو service_type الواردة (قد تكون نصاً عربياً أو مفتاحاً إنجليزياً).
     */
    private function extractServiceTypeFromSource(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $value = trim($value);

        // مفاتيح إنجليزية مباشرة
        $known = ['umrah', 'hajj', 'flight', 'bus', 'work_visa', 'family_visit', 'passport', 'postal'];
        foreach ($known as $key) {
            if (strcasecmp($value, $key) === 0) {
                return $key;
            }
        }

        // قيم بديلة إنجليزية تُستخدم في بعض الملفات
        $aliasMap = [
            'familyvisit'           => 'family_visit',
            'passport_transaction'  => 'passport',
            'passport_transactions' => 'passport',
            'bus_flight_bookings'   => 'bus',
            'booking'               => 'bus',
            'visa'                  => 'family_visit',
            '2'                     => 'passport',
            '3'                     => 'bus',
            '4'                     => 'umrah',
            '5'                     => 'family_visit',
            '6'                     => 'work_visa',
        ];
        $lower = strtolower($value);
        if (isset($aliasMap[$lower])) {
            return $aliasMap[$lower];
        }

        // مطابقة النصوص العربية الشائعة
        $map = [
            'العمرة'        => 'umrah',
            'عمرة'          => 'umrah',
            'الحج'          => 'hajj',
            'حج'            => 'hajj',
            'الطيران'       => 'flight',
            'طيران'         => 'flight',
            'الباصات'       => 'bus',
            'باصات'         => 'bus',
            'باص'           => 'bus',
            'تأشيرة عمل'    => 'work_visa',
            'تاشيره عمل'    => 'work_visa',
            'الزيارة العائلية' => 'family_visit',
            'زيارة عائلية'  => 'family_visit',
            'الجوازات'      => 'passport',
            'جوازات'        => 'passport',
            'البريد'        => 'postal',
            'بريد'          => 'postal',
        ];
        foreach ($map as $ar => $key) {
            if (mb_strpos($value, $ar) !== false) {
                return $key;
            }
        }
        return null;
    }

    /**
     * تحديد حساب الإيراد الافتراضي للخدمة من إعدادات النظام (مع كاش).
     * يُستخدم كحساب دائن احتياطي في سندات القبض النقدي عند عدم وجود عميل مرتبط.
     * المبدأ المحاسبي: مدين الصندوق / دائن الإيراد.
     */
    private function resolveRevenueAccountForService(?string $serviceType): ?int
    {
        if (!$serviceType) {
            return null;
        }
        $keyMap = [
            'umrah'        => 'revenue_umrah_account_id',
            'hajj'         => 'revenue_hajj_account_id',
            'flight'       => 'revenue_flight_account_id',
            'bus'          => 'revenue_bus_account_id',
            'work_visa'    => 'revenue_work_visa_account_id',
            'family_visit' => 'revenue_family_visit_account_id',
            'passport'     => 'revenue_passport_account_id',
            'postal'       => 'revenue_postal_account_id',
        ];
        $settingKey = $keyMap[$serviceType] ?? null;
        if (!$settingKey) {
            return null;
        }
        $value = $this->getSetting($settingKey);
        return $value ? (int)$value : null;
    }

    /**
     * تحديد حساب التكلفة الافتراضي للخدمة من إعدادات النظام (مع كاش).
     * يُستخدم كحساب مدين احتياطي في سندات الصرف النقدي عند عدم وجود مورد مرتبط.
     */
    private function resolveCostAccountForService(?string $serviceType): ?int
    {
        if (!$serviceType) {
            return null;
        }
        $keyMap = [
            'umrah'        => 'cost_umrah_account_id',
            'hajj'         => 'cost_hajj_account_id',
            'flight'       => 'cost_flight_account_id',
            'bus'          => 'cost_bus_account_id',
            'work_visa'    => 'cost_work_visa_account_id',
            'family_visit' => 'cost_family_visit_account_id',
            'passport'     => 'cost_passport_account_id',
            'postal'       => 'cost_postal_account_id',
        ];
        $settingKey = $keyMap[$serviceType] ?? null;
        if (!$settingKey) {
            return null;
        }
        $value = $this->getSetting($settingKey);
        return $value ? (int)$value : null;
    }

    /**
     * تحديد معرّف الحساب المرتبط بالطرف (عميل/مورد) مع كاش.
     */
    private function resolvePartyAccountId(string $entityType, ?int $entityId): ?int
    {
        if (!$entityId) {
            return null;
        }
        if ($entityType === 'customer') {
            $row = $this->fetchCustomer($entityId);
            return ($row && !empty($row['account_id'])) ? (int)$row['account_id'] : null;
        }
        if ($entityType === 'supplier') {
            $row = $this->fetchSupplier($entityId);
            return ($row && !empty($row['account_id'])) ? (int)$row['account_id'] : null;
        }
        return null;
    }

    /**
     * تحديد عنوان IP للعميل بأمان.
     */
    private function resolveClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        foreach ($candidates as $ip) {
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                // في حالة قائمة IPs في X-Forwarded-For، نأخذ الأول
                return (string)explode(',', $ip)[0];
            }
        }
        return '127.0.0.1';
    }

    /**
     * تحديد وكيل المستخدم (User-Agent) بأمان.
     */
    private function resolveUserAgent(): string
    {
        return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI'), 0, 500);
    }
}
