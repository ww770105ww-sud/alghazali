<?php

/**
 * Currency Exchange Class
 * نظام تصريف العملات المتوافق مع قاعدة البيانات الموحدة
 * وكالة الغزالي للسفريات والسياحة
 *
 * @version 2.0
 * @updated 2026-04-17
 */

class CurrencyExchange
{
    private $conn;
    private $error;
    private $success;

    /**
     * Constructor
     * @param PDO $connection
     */
    public function __construct($connection)
    {
        $this->conn = $connection;
        $this->error = null;
        $this->success = null;
    }

    /**
     * جلب آخر خطأ
     * @return string|null
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * جلب رسالة النجاح
     * @return string|null
     */
    public function getSuccess()
    {
        return $this->success;
    }

    /**
     * جلب جميع العملات النشطة
     * @return array
     */
    public function getAllCurrencies()
    {
        try {
            $sql = "SELECT
                        id,
                        currency_name,
                        currency_code,
                        currency_symbol,
                        exchange_rate_sell,
                        exchange_rate_buy,
                        is_default,
                        is_active
                    FROM currencies
                    WHERE is_active = 1
                    ORDER BY is_default DESC, currency_name ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب العملات: " . $e->getMessage();
            return [];
        }
    }

    /**
     * جلب العملة الأساسية
     * @return array|null
     */
    public function getBaseCurrency()
    {
        try {
            $sql = "SELECT
                        id,
                        currency_name,
                        currency_code,
                        currency_symbol,
                        exchange_rate_sell,
                        exchange_rate_buy
                    FROM currencies
                    WHERE is_default = 1
                    LIMIT 1";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب العملة الأساسية: " . $e->getMessage();
            return null;
        }
    }

    /**
     * جلب عملة معينة بواسطة المعرف
     * @param int $currency_id
     * @return array|null
     */
    public function getCurrencyById($currency_id)
    {
        try {
            $sql = "SELECT
                        id,
                        currency_name,
                        currency_code,
                        currency_symbol,
                        exchange_rate_sell,
                        exchange_rate_buy,
                        is_default
                    FROM currencies
                    WHERE id = ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$currency_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب العملة: " . $e->getMessage();
            return null;
        }
    }

    /**
     * جلب الحسابات المالية المتاحة (صناديق، بنوك، عملاء، موردين)
     * @return array
     */
    public function getAvailableAccounts()
    {
        try {
            // جلب الحسابات الفرعية مع معرف الحساب الأب وتصنيف النوع
            $sql = "SELECT
                        t1.id,
                        t1.account_code,
                        t1.account_name_ar,
                        t1.parent_id,
                        CASE 
                            WHEN t1.account_code LIKE '11101%' THEN 'box'
                            WHEN t1.account_code LIKE '11102%' THEN 'bank'
                            WHEN t1.account_code LIKE '1121%' THEN 'customer'
                            WHEN t1.account_code LIKE '1122%' THEN 'agent'
                            WHEN t1.account_code LIKE '1123%' THEN 'branch'
                            WHEN t1.account_code LIKE '2111%' THEN 'supplier'
                            WHEN t1.account_code LIKE '2112%' THEN 'employee'
                            ELSE 'other'
                        END as custom_type
                    FROM unified_accounts t1
                    WHERE t1.is_active = 1
                    AND NOT EXISTS (
                        SELECT 1 FROM unified_accounts t2 WHERE t2.parent_id = t1.id
                    )
                    ORDER BY t1.account_code ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب الحسابات: " . $e->getMessage();
            return [];
        }
    }

    /**
     * جلب الحسابات الأب الرئيسية للتصنيفات المطلوبة
     * @return array
     */
    public function getParentAccounts()
    {
        try {
            $codes = ['101', '102', '1121', '1122', '1123', '2111', '2112'];
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            
            $sql = "SELECT id, account_code, account_name_ar,
                    CASE 
                        WHEN account_code = '101' THEN 'box'
                        WHEN account_code = '102' THEN 'bank'
                        WHEN account_code = '1121' THEN 'customer'
                        WHEN account_code = '1122' THEN 'agent'
                        WHEN account_code = '1123' THEN 'branch'
                        WHEN account_code = '2111' THEN 'supplier'
                        WHEN account_code = '2112' THEN 'employee'
                    END as custom_type
                    FROM unified_accounts 
                    WHERE account_code IN ($placeholders) 
                    AND is_active = 1";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($codes);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * تحويل مبلغ من عملة إلى أخرى (حسابياً فقط - بدون تسجيل)
     * @param float $amount المبلغ المراد تحويله
     * @param int $from_currency_id معرف العملة المصدر
     * @param int $to_currency_id معرف العملة الهدف
     * @return float المبلغ المحول
     */
    public function convertAmount($amount, $from_currency_id, $to_currency_id)
    {
        try {
            if ($from_currency_id == $to_currency_id) {
                return $amount;
            }

            // جلب أسعار الصرف
            $fromCurrency = $this->getCurrencyById($from_currency_id);
            $toCurrency = $this->getCurrencyById($to_currency_id);

            if (!$fromCurrency || !$toCurrency) {
                return 0;
            }

            // التحويل عبر العملة الأساسية
            $baseAmount = $amount * $fromCurrency['exchange_rate_sell'];
            $convertedAmount = $baseAmount / $toCurrency['exchange_rate_sell'];

            return round($convertedAmount, 2);
        } catch (Exception $e) {
            $this->error = "خطأ في تحويل المبلغ: " . $e->getMessage();
            return 0;
        }
    }

    /**
     * التحقق من تفعيل العملة لحساب معين
     * @param int $account_id
     * @param int $currency_id
     * @return bool
     */
    public function isCurrencyEnabledForAccount($account_id, $currency_id)
    {
        try {
            $sql = "SELECT 1 FROM account_balances_unified 
                    WHERE account_id = ? AND currency_id = ? 
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$account_id, $currency_id]);
            return (bool)$stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * تنفيذ عملية تصريف عملات
     * @param array $data بيانات العملية
     * @return array|false نتيجة العملية
     */
    public function executeExchange($data)
    {
        try {
            // التحقق من البيانات المطلوبة
            $required = [
                'branch_id',
                'from_currency_id',
                'from_amount',
                'to_currency_id',
                'to_amount',
                'exchange_rate',
                'from_account_id',
                'to_account_id',
                'created_by'
            ];

            foreach ($required as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    $this->error = "الحقل $field مطلوب";
                    return false;
                }
            }

            // 1. التحقق من تفعيل العملة في حساب الدفع
            if (!$this->isCurrencyEnabledForAccount($data['from_account_id'], $data['from_currency_id'])) {
                $this->error = "عملة الدفع غير مفعلة لهذا الحساب. يرجى تفعيل العملة للحساب أولاً.";
                return false;
            }

            // 2. التحقق من تفعيل العملة في حساب الاستلام
            if (!$this->isCurrencyEnabledForAccount($data['to_account_id'], $data['to_currency_id'])) {
                $this->error = "عملة الاستلام غير مفعلة لحساب الاستلام. يرجى تفعيل العملة للحساب أولاً.";
                return false;
            }

            // 3. التحقق من توفر الرصيد الكافي في حساب الدفع
            $current_balance = $this->getAccountBalance($data['from_account_id'], $data['from_currency_id']);
            if ($current_balance < $data['from_amount']) {
                $this->error = "عذراً، الرصيد الحالي غير كافٍ. الرصيد المتوفر: " . number_format($current_balance, 2);
                return false;
            }

            // التحقق من أن العملتين مختلفتين
            if ($data['from_currency_id'] == $data['to_currency_id']) {
                $this->error = "يجب أن تكون العملتان مختلفتين";
                return false;
            }

            // استدعاء الإجراء المخزن
            $sql = "CALL sp_currency_exchange(
                :branch_id,
                :from_currency_id,
                :from_amount,
                :to_currency_id,
                :to_amount,
                :exchange_rate,
                :from_account_id,
                :to_account_id,
                :notes,
                :created_by,
                @transaction_id,
                @transaction_number,
                @profit_loss
            )";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                'branch_id' => $data['branch_id'],
                'from_currency_id' => $data['from_currency_id'],
                'from_amount' => $data['from_amount'],
                'to_currency_id' => $data['to_currency_id'],
                'to_amount' => $data['to_amount'],
                'exchange_rate' => $data['exchange_rate'],
                'from_account_id' => $data['from_account_id'],
                'to_account_id' => $data['to_account_id'],
                'notes' => $data['notes'] ?? '',
                'created_by' => $data['created_by']
            ]);

            // استرجاع القيم المُخرجة
            $result = $this->conn->query("
                SELECT
                    @transaction_id as transaction_id,
                    @transaction_number as transaction_number,
                    @profit_loss as profit_loss
            ")->fetch(PDO::FETCH_ASSOC);

            $this->success = "تمت عملية التصريف بنجاح! رقم المعاملة: " . $result['transaction_number'];

            return [
                'success' => true,
                'transaction_id' => $result['transaction_id'],
                'transaction_number' => $result['transaction_number'],
                'profit_loss' => $result['profit_loss']
            ];
        } catch (PDOException $e) {
            $this->error = "خطأ في تنفيذ عملية التصريف: " . $e->getMessage();
            return false;
        }
    }

    /**
     * جلب سجل عمليات التصريف
     * @param int $limit عدد السجلات
     * @param string $from_date تاريخ البداية (Y-m-d)
     * @param string $to_date تاريخ النهاية (Y-m-d)
     * @return array
     */
    public function getExchangeHistory($limit = 50, $from_date = null, $to_date = null)
    {
        try {
            $sql = "SELECT
                        cet.*,
                        fc.currency_code as from_currency_code,
                        fc.currency_symbol as from_currency_symbol,
                        fc.currency_name as from_currency_name,
                        tc.currency_code as to_currency_code,
                        tc.currency_symbol as to_currency_symbol,
                        tc.currency_name as to_currency_name,
                        u.full_name as created_by_name,
                        b.branch_name,
                        fa.account_name_ar as from_account_name,
                        ta.account_name_ar as to_account_name,
                        ft.status as financial_status
                    FROM currency_exchange_transactions cet
                    JOIN currencies fc ON cet.from_currency_id = fc.id
                    JOIN currencies tc ON cet.to_currency_id = tc.id
                    JOIN unified_accounts fa ON cet.from_account_id = fa.id
                    JOIN unified_accounts ta ON cet.to_account_id = ta.id
                    LEFT JOIN users u ON cet.created_by = u.id
                    LEFT JOIN branches b ON cet.branch_id = b.id
                    LEFT JOIN financial_transactions ft ON cet.transaction_number = ft.transaction_number
                    WHERE 1=1";

            $params = [];

            if ($from_date) {
                $sql .= " AND cet.transaction_date >= ?";
                $params[] = $from_date;
            }

            if ($to_date) {
                $sql .= " AND cet.transaction_date <= ?";
                $params[] = $to_date;
            }

            $sql .= " ORDER BY cet.created_at DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $result;
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب سجل التصريف: " . $e->getMessage();
            return [];
        }
    }

    /**
     * جلب تفاصيل عملية تصريف محددة
     * @param int $transaction_id رقم المعاملة
     * @return array|null
     */
    public function getExchangeDetails($transaction_id)
    {
        try {
            $sql = "SELECT
                        cet.*,
                        fc.currency_code as from_currency_code,
                        fc.currency_symbol as from_currency_symbol,
                        fc.currency_name as from_currency_name,
                        tc.currency_code as to_currency_code,
                        tc.currency_symbol as to_currency_symbol,
                        tc.currency_name as to_currency_name,
                        u.full_name as created_by_name,
                        b.branch_name,
                        fa.account_name_ar as from_account_name,
                        fa.account_code as from_account_code,
                        ta.account_name_ar as to_account_name,
                        ta.account_code as to_account_code,
                        ft.status as financial_status,
                        ft.posted_at
                    FROM currency_exchange_transactions cet
                    JOIN currencies fc ON cet.from_currency_id = fc.id
                    JOIN currencies tc ON cet.to_currency_id = tc.id
                    JOIN unified_accounts fa ON cet.from_account_id = fa.id
                    JOIN unified_accounts ta ON cet.to_account_id = ta.id
                    LEFT JOIN users u ON cet.created_by = u.id
                    LEFT JOIN branches b ON cet.branch_id = b.id
                    LEFT JOIN financial_transactions ft ON cet.transaction_number = ft.transaction_number
                    WHERE cet.id = ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$transaction_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب تفاصيل التصريف: " . $e->getMessage();
            return null;
        }
    }

    /**
     * جلب قيود اليومية لعملية تصريف
     * @param string $transaction_number رقم المعاملة
     * @return array
     */
    public function getExchangeJournalLines($transaction_number)
    {
        try {
            $sql = "SELECT
                        jl.debit,
                        jl.credit,
                        ua.account_code,
                        ua.account_name_ar,
                        ua.account_type,
                        c.currency_code
                    FROM journal_lines jl
                    JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
                    JOIN unified_accounts ua ON jl.account_id = ua.id
                    JOIN currencies c ON jl.currency_id = c.id
                    WHERE ft.transaction_number = ?
                    ORDER BY jl.debit DESC, jl.credit DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$transaction_number]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب قيود اليومية: " . $e->getMessage();
            return [];
        }
    }

    /**
     * تحديث أسعار الصرف
     * @param array $rates مصفوفة الأسعار [currency_id => ['sell' => x, 'buy' => y]]
     * @return bool
     */
    public function updateExchangeRates($rates)
    {
        try {
            $this->conn->beginTransaction();

            foreach ($rates as $currency_id => $rate_data) {
                $sql = "UPDATE currencies
                        SET exchange_rate_sell = ?,
                            exchange_rate_buy = ?,
                            last_updated = NOW()
                        WHERE id = ? AND is_default = 0"; // لا يمكن تعديل العملة الأساسية

                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    $rate_data['sell'],
                    $rate_data['buy'],
                    $currency_id
                ]);
            }

            $this->conn->commit();
            $this->success = "تم تحديث أسعار الصرف بنجاح";
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            $this->error = "خطأ في تحديث أسعار الصرف: " . $e->getMessage();
            return false;
        }
    }

    /**
     * جلب إحصائيات تصريف العملات
     * @param string $period (today, week, month, year, all)
     * @return array
     */
    public function getStatistics($period = 'month')
    {
        try {
            $date_condition = "";

            switch ($period) {
                case 'today':
                    $date_condition = "AND DATE(transaction_date) = CURDATE()";
                    break;
                case 'week':
                    $date_condition = "AND YEARWEEK(transaction_date) = YEARWEEK(NOW())";
                    break;
                case 'month':
                    $date_condition = "AND MONTH(transaction_date) = MONTH(NOW()) AND YEAR(transaction_date) = YEAR(NOW())";
                    break;
                case 'year':
                    $date_condition = "AND YEAR(transaction_date) = YEAR(NOW())";
                    break;
            }

            $sql = "SELECT
                        COUNT(*) as total_transactions,
                        SUM(from_amount * cet.exchange_rate) as total_base_amount,
                        fc.currency_code as from_currency,
                        tc.currency_code as to_currency
                    FROM currency_exchange_transactions cet
                    JOIN currencies fc ON cet.from_currency_id = fc.id
                    JOIN currencies tc ON cet.to_currency_id = tc.id
                    WHERE 1=1 $date_condition
                    GROUP BY fc.currency_code, tc.currency_code";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = "خطأ في جلب الإحصائيات: " . $e->getMessage();
            return [];
        }
    }

    /**
     * حساب الربح/الخسارة المتوقع من عملية تصريف
     * @param float $from_amount المبلغ المصدر
     * @param int $from_currency_id العملة المصدر
     * @param float $to_amount المبلغ الهدف
     * @param int $to_currency_id العملة الهدف
     * @return float الربح/الخسارة بالعملة الأساسية
     */
    public function calculateExpectedProfitLoss($from_amount, $from_currency_id, $to_amount, $to_currency_id)
    {
        try {
            $fromCurrency = $this->getCurrencyById($from_currency_id);
            $toCurrency = $this->getCurrencyById($to_currency_id);

            if (!$fromCurrency || !$toCurrency) {
                return 0;
            }

            $base_from = $from_amount * $fromCurrency['exchange_rate_sell'];
            $base_to = $to_amount * $toCurrency['exchange_rate_sell'];

            return round($base_to - $base_from, 2);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * جلب رصيد حساب معين لعملة معينة
     * @param int $account_id
     * @param int $currency_id
     * @return float
     */
    public function getAccountBalance($account_id, $currency_id)
    {
        try {
            $sql = "SELECT current_balance 
                    FROM account_balances_unified 
                    WHERE account_id = ? AND currency_id = ? 
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$account_id, $currency_id]);
            return (float)$stmt->fetchColumn() ?: 0.0;
        } catch (PDOException $e) {
            return 0.0;
        }
    }

    /**
     * حذف عملية تصريف
     * @param int $id معرف العملية
     * @return bool
     */
    public function deleteExchange($id)
    {
        try {
            $this->conn->beginTransaction();

            // 1. جلب رقم المعاملة للتأكد من حذف السجلات المالية المرتبطة
            $stmt = $this->conn->prepare("SELECT transaction_number FROM currency_exchange_transactions WHERE id = ?");
            $stmt->execute([$id]);
            $cet = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cet) {
                $num = $cet['transaction_number'];

                // 2. التحقق من حالة الترحيل
                $stmt_ft = $this->conn->prepare("SELECT id, status FROM financial_transactions WHERE transaction_number = ?");
                $stmt_ft->execute([$num]);
                $ft = $stmt_ft->fetch(PDO::FETCH_ASSOC);

                if ($ft && $ft['status'] === 'posted') {
                    throw new Exception("لا يمكن حذف عملية مرحلة. يرجى إلغاء الترحيل أولاً.");
                }

                // 3. حذف قيود اليومية والسجلات المالية
                if ($ft) {
                    $this->conn->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ft['id']]);
                    $this->conn->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$ft['id']]);
                }

                // 4. حذف سجل التصريف
                $stmt_del = $this->conn->prepare("DELETE FROM currency_exchange_transactions WHERE id = ?");
                $stmt_del->execute([$id]);

                $this->conn->commit();
                $this->success = "تم حذف العملية وكافة سجلاتها المالية بنجاح";
                return true;
            } else {
                throw new Exception("لم يتم العثور على العملية.");
            }
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            $this->error = "خطأ في حذف العملية: " . $e->getMessage();
            return false;
        }
    }

    /**
     * تحديث ملاحظات عملية تصريف
     * @param int $id معرف العملية
     * @param string $notes الملاحظات الجديدة
     * @return bool
     */
    public function updateExchangeNotes($id, $notes)
    {
        try {
            $sql = "UPDATE currency_exchange_transactions SET notes = ? WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$notes, $id]);

            if ($stmt->rowCount() >= 0) {
                $this->success = "تم تحديث الملاحظات بنجاح";
                return true;
            } else {
                $this->error = "فشل في تحديث الملاحظات";
                return false;
            }
        } catch (PDOException $e) {
            $this->error = "خطأ في تحديث الملاحظات: " . $e->getMessage();
            return false;
        }
    }
}
