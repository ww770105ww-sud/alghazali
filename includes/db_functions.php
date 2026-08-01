<?php
// db_functions.php - دوال مساعدة للتعامل مع قاعدة البيانات

/**
 * تنفيذ عملية تصريف عملات
 */
function executeCurrencyExchange($conn, $data)
{
    try {
        // التحقق من صحة البيانات المطلوبة
        $required_fields = ['branch_id', 'from_currency_id', 'from_amount', 'to_currency_id', 'to_amount', 'exchange_rate', 'from_account_id', 'to_account_id', 'created_by'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new Exception("الحقل المطلوب مفقود: {$field}");
            }
        }

        // التحقق من أن المبالغ موجبة
        if ($data['from_amount'] <= 0 || $data['to_amount'] <= 0) {
            throw new Exception("يجب أن تكون المبالغ موجبة");
        }

        // التحقق من أن العملات مختلفة
        if ($data['from_currency_id'] == $data['to_currency_id']) {
            throw new Exception("لا يمكن تصريف العملة لنفسها");
        }

        // التحقق من أن الحسابات مختلفة
        if ($data['from_account_id'] == $data['to_account_id']) {
            throw new Exception("يجب أن تكون الحسابات مختلفة");
        }

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

        $stmt = $conn->prepare($sql);
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

        $result = $conn->query("SELECT @transaction_id as id, @transaction_number as number, @profit_loss as profit")->fetch();

        if (!$result || !$result['id']) {
            throw new Exception("فشل في تنفيذ عملية التصريف");
        }

        return [
            'success' => true,
            'transaction_id' => $result['id'],
            'transaction_number' => $result['number'],
            'profit_loss' => $result['profit']
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * جلب تفاصيل معاملة تصريف محددة
 */
function getCurrencyExchangeDetails($conn, $transaction_id)
{
    try {
        $sql = "SELECT
                    cet.*,
                    fc.currency_name as from_currency_name,
                    fc.currency_code as from_currency_code,
                    fc.currency_symbol as from_currency_symbol,
                    tc.currency_name as to_currency_name,
                    tc.currency_code as to_currency_code,
                    tc.currency_symbol as to_currency_symbol,
                    fa.account_name_ar as from_account_name,
                    ta.account_name_ar as to_account_name,
                    u.full_name as created_by_name,
                    b.branch_name
                FROM currency_exchange_transactions cet
                JOIN currencies fc ON cet.from_currency_id = fc.id
                JOIN currencies tc ON cet.to_currency_id = tc.id
                JOIN unified_accounts fa ON cet.from_account_id = fa.id
                JOIN unified_accounts ta ON cet.to_account_id = ta.id
                LEFT JOIN users u ON cet.created_by = u.id
                LEFT JOIN branches b ON cet.branch_id = b.id
                WHERE cet.id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$transaction_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            throw new Exception("المعاملة غير موجودة");
        }

        return [
            'success' => true,
            'data' => $result
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * جلب سجل عمليات التصريف مع فلترة
 */
function getCurrencyExchangeHistory($conn, $filters = [])
{
    try {
        $where = "WHERE 1=1";
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where .= " AND cet.branch_id = ?";
            $params[] = $filters['branch_id'];
        }

        if (!empty($filters['from_currency_id'])) {
            $where .= " AND cet.from_currency_id = ?";
            $params[] = $filters['from_currency_id'];
        }

        if (!empty($filters['to_currency_id'])) {
            $where .= " AND cet.to_currency_id = ?";
            $params[] = $filters['to_currency_id'];
        }

        if (!empty($filters['date_from'])) {
            $where .= " AND DATE(cet.created_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND DATE(cet.created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['status'])) {
            $where .= " AND cet.status = ?";
            $params[] = $filters['status'];
        }

        $limit = $filters['limit'] ?? 50;
        $offset = $filters['offset'] ?? 0;

        $sql = "SELECT
                    cet.*,
                    fc.currency_name as from_currency_name,
                    fc.currency_code as from_currency_code,
                    fc.currency_symbol as from_currency_symbol,
                    tc.currency_name as to_currency_name,
                    tc.currency_code as to_currency_code,
                    tc.currency_symbol as to_currency_symbol,
                    fa.account_name_ar as from_account_name,
                    ta.account_name_ar as to_account_name,
                    u.full_name as created_by_name,
                    b.branch_name
                FROM currency_exchange_transactions cet
                JOIN currencies fc ON cet.from_currency_id = fc.id
                JOIN currencies tc ON cet.to_currency_id = tc.id
                JOIN unified_accounts fa ON cet.from_account_id = fa.id
                JOIN unified_accounts ta ON cet.to_account_id = ta.id
                LEFT JOIN users u ON cet.created_by = u.id
                LEFT JOIN branches b ON cet.branch_id = b.id
                {$where}
                ORDER BY cet.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $conn->prepare($sql);
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        return [
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * إلغاء عملية تصريف
 */
function cancelCurrencyExchange($conn, $transaction_id, $cancelled_by)
{
    try {
        $conn->beginTransaction();

        // جلب تفاصيل العملية
        $details = getCurrencyExchangeDetails($conn, $transaction_id);
        if (!$details['success']) {
            throw new Exception($details['error']);
        }

        $exchange = $details['data'];

        if ($exchange['status'] !== 'active') {
            throw new Exception("لا يمكن إلغاء عملية تم إلغاؤها مسبقاً");
        }

        // إلغاء العملية
        $sql = "UPDATE currency_exchange_transactions
                SET status = 'cancelled',
                    cancelled_at = NOW(),
                    cancelled_by = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$cancelled_by, $transaction_id]);

        // عكس القيود المحاسبية إذا كانت مرحلة
        if ($exchange['posted']) {
            // يمكن إضافة منطق عكس القيود هنا
            // للآن سنترك تعليق فقط
        }

        $conn->commit();

        return [
            'success' => true,
            'message' => 'تم إلغاء عملية التصريف بنجاح'
        ];
    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * جلب إحصائيات عمليات التصريف
 */
function getCurrencyExchangeStatistics($conn, $filters = [])
{
    try {
        $where = "";
        $params = [];

        if (!empty($filters['date_from'])) {
            $where .= " AND DATE(created_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND DATE(created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['branch_id'])) {
            $where .= " AND branch_id = ?";
            $params[] = $filters['branch_id'];
        }

        $sql = "SELECT
                    COUNT(*) as total_exchanges,
                    SUM(from_amount) as total_from_amount,
                    SUM(to_amount) as total_to_amount,
                    AVG(exchange_rate) as avg_exchange_rate,
                    MIN(created_at) as first_exchange,
                    MAX(created_at) as last_exchange
                FROM currency_exchange_transactions
                WHERE 1=1 {$where}";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // حساب إجمالي الربح/الخسارة من العمليات
        $profit_sql = "SELECT SUM((to_amount / exchange_rate) - from_amount) as total_profit_loss
                      FROM currency_exchange_transactions
                      WHERE 1=1 {$where}";

        $profit_stmt = $conn->prepare($profit_sql);
        $profit_stmt->execute($params);
        $profit_result = $profit_stmt->fetch(PDO::FETCH_ASSOC);

        $result['total_profit_loss'] = $profit_result['total_profit_loss'] ?? 0;

        return [
            'success' => true,
            'data' => $result
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * تحويل مبلغ من عملة إلى أخرى باستخدام دالة قاعدة البيانات
 */
function convertCurrency($conn, $amount, $from_currency_id, $to_currency_id)
{
    try {
        $sql = "SELECT fn_convert_currency(?, ?, ?) as converted";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$amount, $from_currency_id, $to_currency_id]);
        $result = $stmt->fetchColumn();

        return [
            'success' => true,
            'converted_amount' => $result
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * جلب سعر الصرف الحالي
 */
function getExchangeRate($conn, $currency_id, $type = 'sell')
{
    try {
        $sql = "SELECT fn_get_exchange_rate(?, ?) as rate";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$currency_id, $type]);
        $result = $stmt->fetchColumn();

        return [
            'success' => true,
            'rate' => $result
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * تحديث أسعار الصرف
 */
function updateExchangeRates($conn, $rates)
{
    try {
        $conn->beginTransaction();

        foreach ($rates as $currency_id => $rate_data) {
            $sql = "UPDATE currencies
                    SET exchange_rate_sell = ?,
                        exchange_rate_buy = ?,
                        last_updated = NOW()
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $rate_data['sell'],
                $rate_data['buy'],
                $currency_id
            ]);
        }

        $conn->commit();

        return [
            'success' => true,
            'message' => 'تم تحديث أسعار الصرف بنجاح'
        ];
    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * دالة مساعدة لتنفيذ استعلامات آمنة
 */
function safeQuery($conn, $sql, $params = [])
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return [
            'success' => true,
            'stmt' => $stmt
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * دالة مساعدة لجلب صف واحد
 */
function safeFetch($conn, $sql, $params = [])
{
    $result = safeQuery($conn, $sql, $params);
    if (!$result['success']) {
        return $result;
    }

    try {
        $data = $result['stmt']->fetch(PDO::FETCH_ASSOC);
        return [
            'success' => true,
            'data' => $data
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * دالة مساعدة لجلب جميع الصفوف
 */
function safeFetchAll($conn, $sql, $params = [])
{
    $result = safeQuery($conn, $sql, $params);
    if (!$result['success']) {
        return $result;
    }

    try {
        $data = $result['stmt']->fetchAll(PDO::FETCH_ASSOC);
        return [
            'success' => true,
            'data' => $data
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
