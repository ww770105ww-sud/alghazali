-- =========================================================================
-- PATCH V3.1 (MariaDB-compatible): تحسينات شاملة لإجراءات قاعدة بيانات نظام غزالي
-- توافق: MariaDB (XAMPP) + MySQL 5.7+/8.0+
-- ملاحظة: تمت إزالة كلمة STACKED من GET DIAGNOSTICS لأن MariaDB لا تدعمها
-- Author: ERP Improvements
-- Sections:
--   1. sp_log_error (تسجيل الأخطاء)
--   2. sp_post_receipt_voucher (تمهيد + ترحيل سند القبض)
--   3. sp_post_payment_voucher (تمهيد + ترحيل سند الصرف)
--   4. sp_unpost_invoice      (إلغاء ترحيل الفاتورة)
--   5. تنظيف قاعدة البيانات + ENUM -> VARCHAR+CHECK
--   6. نظام المصروفات والموافقات والميزانيات
-- =========================================================================

DELIMITER $$

-- ========================================================
-- §1. إجراء تسجيل الأخطاء sp_log_error
-- ========================================================
DROP PROCEDURE IF EXISTS `sp_log_error`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_log_error`(
    IN p_procedure_name  VARCHAR(100),
    IN p_error_message   TEXT,
    IN p_user_id         INT,
    IN p_context_json    JSON
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[محاسبي] تسجيل أخطاء الإجراءات المخزنة في audit_logs'
sp_log_error_body:BEGIN
    DECLARE v_ip VARCHAR(45) DEFAULT NULL;
    DECLARE v_ua TEXT        DEFAULT NULL;

    IF p_user_id IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_user_id LIMIT 1
            );
        END;
    END IF;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_user_id, 0), 'error', COALESCE(p_procedure_name, 'unknown'), 0,
        COALESCE(CAST(p_context_json AS CHAR), '{}'),
        JSON_OBJECT(
            'error_message', LEFT(COALESCE(p_error_message, ''), 2000),
            'procedure_name', COALESCE(p_procedure_name, 'unknown'),
            'logged_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
        ),
        v_ip, v_ua, NOW()
    );
END$$


-- ========================================================
-- §2. إصلاح sp_post_receipt_voucher (ترحيل سند القبض)
-- ========================================================
DROP PROCEDURE IF EXISTS `sp_post_receipt_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_receipt_voucher`(
    IN p_transaction_id  INT,
    IN p_posted_by       INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[V2] ترحيل سند قبض — TX + EXIT HANDLER + رسائل عامة + تدقيق كامل'
sp_post_rv_body:BEGIN
    DECLARE v_status          VARCHAR(20);
    DECLARE v_amount          DECIMAL(18,4);
    DECLARE v_currency_id     INT;
    DECLARE v_exchange_rate   DECIMAL(18,6);
    DECLARE v_party_account_id INT;
    DECLARE v_cash_bank_id    INT;
    DECLARE v_description     TEXT;
    DECLARE v_created_by      INT;
    DECLARE v_before_json     JSON;
    DECLARE v_after_json      JSON;
    DECLARE v_inv_id          INT;
    DECLARE v_alloc_amount    DECIMAL(18,4);
    DECLARE v_inv_net         DECIMAL(18,2);
    DECLARE v_inv_received    DECIMAL(18,2);
    DECLARE v_alloc_rem       DECIMAL(18,2);
    DECLARE v_posted_ip       VARCHAR(45) DEFAULT NULL;
    DECLARE v_posted_ua       TEXT        DEFAULT NULL;
    DECLARE v_ar_account_id   INT;
    DECLARE v_cash_account_id INT;
    DECLARE done INT DEFAULT 0;
    DECLARE cur CURSOR FOR
        SELECT invoice_id, allocated_amount
          FROM payment_allocations
         WHERE financial_transaction_id = p_transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'transaction_id', CAST(p_transaction_id AS CHAR),
            'posted_by',      CAST(p_posted_by AS CHAR),
            'mysql_errno',    CAST(@err_no AS CHAR),
            'sqlstate',       @err_sqlstate
        );
        CALL sp_log_error('sp_post_receipt_voucher', @err_msg, p_posted_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    -- (أ) التحقق من المدخلات
    IF p_transaction_id IS NULL OR p_transaction_id <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: معرف السند غير صالح.';
    END IF;

    -- (ب) جلب بيانات السند + التأكد من وجوده مع النوع الصحيح
    SELECT status, amount, currency_id, exchange_rate,
           party_account_id, cash_bank_account_id, description,
           COALESCE(created_by, 0)
      INTO v_status, v_amount, v_currency_id, v_exchange_rate,
           v_party_account_id, v_cash_bank_id, v_description, v_created_by
      FROM financial_transactions
     WHERE id = p_transaction_id AND transaction_type = 'receipt'
     LIMIT 1;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: السند المطلوب غير متوفر.';
    END IF;

    IF v_status = 'posted' THEN
        LEAVE sp_post_rv_body;
    END IF;

    IF v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: حالة السند الحالية لا تسمح بالترحيل.';
    END IF;

    IF COALESCE(v_amount, 0) <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: مبلغ السند يجب أن يكون أكبر من صفر.';
    END IF;

    -- (ج) جلب معرفات الحسابات الافتراضية إذا لم يتم تمريرها
    SET v_ar_account_id = COALESCE(
        v_party_account_id,
        (SELECT id FROM unified_accounts WHERE account_type = 'accounts_receivable' LIMIT 1)
    );
    SET v_cash_account_id = COALESCE(
        v_cash_bank_id,
        (SELECT id FROM unified_accounts WHERE account_type IN ('box','bank') LIMIT 1)
    );

    IF v_ar_account_id IS NULL OR v_cash_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: بعض الحسابات المحاسبية الأساسية غير مهيأة.';
    END IF;

    -- (د) جلب عنوان IP للمُرحّل (اختياري)
    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_posted_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_posted_by LIMIT 1
            );
        END;
    END IF;

    -- (هـ) لحظة ما قبل الترحيل
    SET v_before_json = JSON_OBJECT(
        'id',                   CAST(p_transaction_id AS CHAR),
        'status',               v_status,
        'amount',               CAST(v_amount AS CHAR),
        'currency_id',          CAST(v_currency_id AS CHAR),
        'party_account_id',     CAST(v_party_account_id AS CHAR),
        'cash_bank_account_id', CAST(v_cash_bank_id AS CHAR)
    );

    -- (و) إنشاء قيود اليومية (القبض: مدين = صندوق/بنك ، دائن = ذمم مدينة / العميل)
    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        p_transaction_id, 1,
        v_cash_account_id, 'asset', v_currency_id,
        v_amount, 0,
        v_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,
        v_description, 'cash_debit', NOW()
    );

    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        p_transaction_id, 2,
        v_ar_account_id, 'customer', v_currency_id,
        0, v_amount,
        0, v_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
        v_description, 'customer_credit', NOW()
    );

    -- (ز) التأكد من توازن قيود اليومية
    CALL sp_validate_journal_balance(p_transaction_id);

    -- (ح) تحديث حالة المعاملة إلى "مرحّل"
    UPDATE financial_transactions
       SET status       = 'posted',
           posted_by    = p_posted_by,
           posted_at    = NOW(),
           posted_ip    = v_posted_ip,
           updated_ip   = v_posted_ip
     WHERE id = p_transaction_id;

    -- (ط) معالجة توزيعات المبالغ على الفواتير + إعادة حساب المستحقات
    OPEN cur;
    cur_loop: LOOP
        FETCH cur INTO v_inv_id, v_alloc_amount;
        IF done = 1 THEN LEAVE cur_loop; END IF;
        IF v_inv_id IS NOT NULL AND v_alloc_amount > 0 THEN
            SELECT COALESCE(net_amount, total_amount - discount)
              INTO v_inv_net
              FROM invoices WHERE id = v_inv_id;

            IF v_inv_net IS NOT NULL THEN
                SELECT COALESCE(SUM(pa.allocated_amount), 0) INTO v_inv_received
                  FROM payment_allocations pa
                  JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
                 WHERE pa.invoice_id = v_inv_id
                   AND ft.status = 'posted'
                   AND pa.financial_transaction_id <> p_transaction_id;

                SET v_alloc_rem = v_inv_net - v_inv_received;
                IF v_alloc_amount > (v_alloc_rem + 0.01) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'تعذر إتمام العملية: توزيع مبلغ السند يتجاوز رصيد أحد الفواتير.';
                END IF;

                CALL sp_recalculate_invoice_payment(v_inv_id);
            END IF;
        END IF;
    END LOOP cur_loop;
    CLOSE cur;

    -- (ي) سجل المراجعة
    SET v_after_json = JSON_OBJECT(
        'id',                   CAST(p_transaction_id AS CHAR),
        'status',               'posted',
        'posted_by',            CAST(p_posted_by AS CHAR),
        'amount',               CAST(v_amount AS CHAR),
        'journal_lines_count',  CAST(2 AS CHAR)
    );

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_posted_by, v_created_by, 0),
        'post', 'financial_transactions', p_transaction_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_posted_ip, v_posted_ua, NOW()
    );

    -- (ك) تحديث الأرصدة
    CALL sp_update_account_balances();

    COMMIT;
END$$


-- ========================================================
-- §3. إصلاح sp_post_payment_voucher (ترحيل سند الصرف)
-- ========================================================
DROP PROCEDURE IF EXISTS `sp_post_payment_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_payment_voucher`(
    IN p_transaction_id  INT,
    IN p_posted_by       INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[V2] ترحيل سند صرف — TX + EXIT HANDLER + رسائل عامة + عكس القيد + AP'
sp_post_pv_body:BEGIN
    DECLARE v_status          VARCHAR(20);
    DECLARE v_amount          DECIMAL(18,4);
    DECLARE v_currency_id     INT;
    DECLARE v_exchange_rate   DECIMAL(18,6);
    DECLARE v_party_account_id INT;
    DECLARE v_cash_bank_id    INT;
    DECLARE v_description     TEXT;
    DECLARE v_created_by      INT;
    DECLARE v_before_json     JSON;
    DECLARE v_after_json      JSON;
    DECLARE v_inv_id          INT;
    DECLARE v_alloc_amount    DECIMAL(18,4);
    DECLARE v_inv_net         DECIMAL(18,2);
    DECLARE v_inv_paid        DECIMAL(18,2);
    DECLARE v_alloc_rem       DECIMAL(18,2);
    DECLARE v_posted_ip       VARCHAR(45) DEFAULT NULL;
    DECLARE v_posted_ua       TEXT        DEFAULT NULL;
    DECLARE v_ap_account_id   INT;
    DECLARE v_cash_account_id INT;
    DECLARE done INT DEFAULT 0;
    DECLARE cur CURSOR FOR
        SELECT invoice_id, allocated_amount
          FROM payment_allocations
         WHERE financial_transaction_id = p_transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'transaction_id', CAST(p_transaction_id AS CHAR),
            'posted_by',      CAST(p_posted_by AS CHAR),
            'mysql_errno',    CAST(@err_no AS CHAR),
            'sqlstate',       @err_sqlstate
        );
        CALL sp_log_error('sp_post_payment_voucher', @err_msg, p_posted_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_transaction_id IS NULL OR p_transaction_id <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: معرف السند غير صالح.';
    END IF;

    -- جلب بيانات سند الصرف + التأكد من وجوده
    SELECT status, amount, currency_id, exchange_rate,
           party_account_id, cash_bank_account_id, description,
           COALESCE(created_by, 0)
      INTO v_status, v_amount, v_currency_id, v_exchange_rate,
           v_party_account_id, v_cash_bank_id, v_description, v_created_by
      FROM financial_transactions
     WHERE id = p_transaction_id AND transaction_type = 'payment'
     LIMIT 1;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: السند المطلوب غير متوفر.';
    END IF;

    IF v_status = 'posted' THEN
        LEAVE sp_post_pv_body;
    END IF;

    IF v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: حالة السند الحالية لا تسمح بالترحيل.';
    END IF;

    IF COALESCE(v_amount, 0) <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: مبلغ السند يجب أن يكون أكبر من صفر.';
    END IF;

    -- حسابات ذمم دائنة (حسابات دائنة - موردين) + الصندوق/البنك
    SET v_ap_account_id = COALESCE(
        v_party_account_id,
        (SELECT id FROM unified_accounts WHERE account_type = 'accounts_payable' LIMIT 1)
    );
    SET v_cash_account_id = COALESCE(
        v_cash_bank_id,
        (SELECT id FROM unified_accounts WHERE account_type IN ('box','bank') LIMIT 1)
    );

    IF v_ap_account_id IS NULL OR v_cash_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: بعض الحسابات المحاسبية الأساسية غير مهيأة.';
    END IF;

    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_posted_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_posted_by LIMIT 1
            );
        END;
    END IF;

    SET v_before_json = JSON_OBJECT(
        'id',                   CAST(p_transaction_id AS CHAR),
        'status',               v_status,
        'amount',               CAST(v_amount AS CHAR),
        'currency_id',          CAST(v_currency_id AS CHAR),
        'party_account_id',     CAST(v_party_account_id AS CHAR),
        'cash_bank_account_id', CAST(v_cash_bank_id AS CHAR)
    );

    -- ===================================================================
    -- قيد الصرف: (عكس القبض تماماً)
    -- مدين = ذمم دائنة (المورد / accounts_payable)
    -- دائن = صندوق / بنك
    -- ===================================================================
    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        p_transaction_id, 1,
        v_ap_account_id, 'supplier', v_currency_id,
        v_amount, 0,
        v_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,
        v_description, 'supplier_debit', NOW()
    );

    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        p_transaction_id, 2,
        v_cash_account_id, 'asset', v_currency_id,
        0, v_amount,
        0, v_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
        v_description, 'cash_credit', NOW()
    );

    CALL sp_validate_journal_balance(p_transaction_id);

    UPDATE financial_transactions
       SET status       = 'posted',
           posted_by    = p_posted_by,
           posted_at    = NOW(),
           posted_ip    = v_posted_ip,
           updated_ip   = v_posted_ip
     WHERE id = p_transaction_id;

    -- معالجة توزيعات المدفوعات على فواتير المورد
    OPEN cur;
    cur_loop: LOOP
        FETCH cur INTO v_inv_id, v_alloc_amount;
        IF done = 1 THEN LEAVE cur_loop; END IF;
        IF v_inv_id IS NOT NULL AND v_alloc_amount > 0 THEN
            SELECT COALESCE(net_amount, total_amount - discount)
              INTO v_inv_net
              FROM invoices WHERE id = v_inv_id;

            IF v_inv_net IS NOT NULL THEN
                SELECT COALESCE(SUM(pa.allocated_amount), 0) INTO v_inv_paid
                  FROM payment_allocations pa
                  JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
                 WHERE pa.invoice_id = v_inv_id
                   AND ft.status = 'posted'
                   AND pa.financial_transaction_id <> p_transaction_id;

                SET v_alloc_rem = v_inv_net - v_inv_paid;
                IF v_alloc_amount > (v_alloc_rem + 0.01) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'تعذر إتمام العملية: توزيع مبلغ السند يتجاوز رصيد أحد الفواتير.';
                END IF;

                CALL sp_recalculate_invoice_payment(v_inv_id);
            END IF;
        END IF;
    END LOOP cur_loop;
    CLOSE cur;

    SET v_after_json = JSON_OBJECT(
        'id',                   CAST(p_transaction_id AS CHAR),
        'status',               'posted',
        'posted_by',            CAST(p_posted_by AS CHAR),
        'amount',               CAST(v_amount AS CHAR),
        'journal_lines_count',  CAST(2 AS CHAR)
    );

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_posted_by, v_created_by, 0),
        'post', 'financial_transactions', p_transaction_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_posted_ip, v_posted_ua, NOW()
    );

    CALL sp_update_account_balances();

    COMMIT;
END$$


-- ========================================================
-- §4. إصلاح sp_unpost_invoice (إلغاء ترحيل الفاتورة)
-- ========================================================
DROP PROCEDURE IF EXISTS `sp_unpost_invoice`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_unpost_invoice`(
    IN p_invoice_id  INT,
    IN p_posted_by   INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[V2] إلغاء ترحيل فاتورة — TX + EXIT HANDLER + تدقيق + تحديث أرصدة + توزيعات'
sp_unpost_invoice_body:BEGIN
    DECLARE v_status        VARCHAR(20);
    DECLARE v_before_json   JSON;
    DECLARE v_after_json    JSON;
    DECLARE v_cancelled_ip  VARCHAR(45) DEFAULT NULL;
    DECLARE v_cancelled_ua  TEXT        DEFAULT NULL;
    DECLARE v_created_by    INT;
    DECLARE v_inv_id        INT;
    DECLARE v_category      VARCHAR(20);
    DECLARE v_tx_id         INT;
    DECLARE done INT DEFAULT 0;

    -- المؤشر 1: فواتير متأثرة بتوزيعات الدفع
    DECLARE cur_invoices CURSOR FOR
        SELECT DISTINCT pa.invoice_id
          FROM payment_allocations pa
          JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
         WHERE ft.reference_id   = p_invoice_id
           AND ft.reference_type = 'invoice'
           AND ft.transaction_type IN ('invoice','purchase');

    -- المؤشر 2: جميع فواتير العميل/المورد لإعادة حساب التوزيعات بعد الحذف
    DECLARE cur_party CURSOR FOR
        SELECT DISTINCT i.id
          FROM invoices i
         WHERE i.id IN (
             SELECT DISTINCT invoice_id FROM payment_allocations pa
              WHERE EXISTS (
                  SELECT 1 FROM financial_transactions ft
                   WHERE ft.id = pa.financial_transaction_id
                     AND ((ft.reference_id = p_invoice_id AND ft.reference_type = 'invoice') OR
                          (pa.invoice_id = p_invoice_id))
              )
         );
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'invoice_id', CAST(p_invoice_id AS CHAR),
            'posted_by',  CAST(p_posted_by AS CHAR),
            'mysql_errno',CAST(@err_no AS CHAR),
            'sqlstate',   @err_sqlstate
        );
        CALL sp_log_error('sp_unpost_invoice', @err_msg, p_posted_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_invoice_id IS NULL OR p_invoice_id <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: معرف الفاتورة غير صالح.';
    END IF;

    SELECT invoice_status, COALESCE(created_by, 0), invoice_category
      INTO v_status, v_created_by, v_category
      FROM invoices
     WHERE id = p_invoice_id
     LIMIT 1;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: الفاتورة المطلوبة غير متوفرة.';
    END IF;

    IF v_status = 'draft' THEN
        LEAVE sp_unpost_invoice_body;
    END IF;

    IF v_status NOT IN ('posted','partial') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: حالة الفاتورة الحالية لا تسمح بالإلغاء.';
    END IF;

    -- تأمين: وجود سندات قبض/صرف مرتبطة ومُرحّلة تمنع الإلغاء
    IF EXISTS (
        SELECT 1 FROM payment_allocations pa
          JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
         WHERE pa.invoice_id = p_invoice_id AND ft.status = 'posted'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية: توجد سندات قبض أو صرف مرتبطة بالفاتورة وقائمة.';
    END IF;

    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_cancelled_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_posted_by LIMIT 1
            );
        END;
    END IF;

    SET v_before_json = JSON_OBJECT(
        'id',               CAST(p_invoice_id AS CHAR),
        'invoice_category', v_category,
        'invoice_status',   v_status
    );

    -- (1) حذف قيود اليومية المرتبطة بترحيل الفاتورة
    DELETE jl
      FROM journal_lines jl
      JOIN financial_transactions ft
        ON ft.id = jl.financial_transaction_id
     WHERE ft.reference_id   = p_invoice_id
       AND ft.reference_type = 'invoice'
       AND ft.transaction_type IN ('invoice','purchase');

    -- (2) تحديث حالة معاملات الفاتورة المالية إلى ملغى
    UPDATE financial_transactions
       SET status       = 'cancelled',
           cancelled_by = p_posted_by,
           cancelled_at = NOW(),
           cancelled_ip = v_cancelled_ip,
           updated_ip   = v_cancelled_ip
     WHERE reference_id   = p_invoice_id
       AND reference_type = 'invoice'
       AND transaction_type IN ('invoice','purchase');

    -- (3) عكس حالة الفاتورة إلى مسودة
    UPDATE invoices
       SET invoice_status = 'draft',
           posted_by   = NULL,
           posted_at   = NULL
     WHERE id = p_invoice_id;

    -- (4) إعادة حساب توزيعات الفواتير المتأثرة
    SET done = 0;
    OPEN cur_invoices;
    cur1_loop: LOOP
        FETCH cur_invoices INTO v_inv_id;
        IF done = 1 THEN LEAVE cur1_loop; END IF;
        IF v_inv_id IS NOT NULL THEN
            CALL sp_recalculate_invoice_payment(v_inv_id);
        END IF;
    END LOOP cur1_loop;
    CLOSE cur_invoices;

    -- إعادة حساب فاتورة نفسها
    CALL sp_recalculate_invoice_payment(p_invoice_id);

    SET v_after_json = JSON_OBJECT(
        'id',               CAST(p_invoice_id AS CHAR),
        'invoice_status',   'draft',
        'cancelled_by',     CAST(p_posted_by AS CHAR),
        'cancelled_at',     DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
    );

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_posted_by, v_created_by, 0),
        'unpost', 'invoices', p_invoice_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_cancelled_ip, v_cancelled_ua, NOW()
    );

    -- (5) تحديث أرصدة الحسابات
    CALL sp_update_account_balances();

    COMMIT;
END$$


DELIMITER ;

-- =========================================================================
-- §5. تنظيف قاعدة البيانات + تحويل ENUM إلى VARCHAR مع CHECK
-- =========================================================================

-- (أ) إزالة جداول النسخ الاحتياطي القديمة
DROP TABLE IF EXISTS `account_balances_unified_backup`;

-- إزالة جميع جداول services_backup_*
SET @drop_backup_tables = (
    SELECT GROUP_CONCAT('DROP TABLE IF EXISTS `', table_name, '`' SEPARATOR '; ')
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name REGEXP '^services_backup_'
);
SET @drop_backup_tables = COALESCE(@drop_backup_tables, 'SELECT 1');
PREPARE stmt_drop FROM @drop_backup_tables;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;


-- ---------------------------------------------------------------------
-- (ب) تحويل ENUM → VARCHAR مع CHECK Constraints
-- ملاحظة: تُطبّق معالجة آمنة (يُسقط القيد إن كان موجوداً قبل إعادة إنشائه)
-- ---------------------------------------------------------------------

-- ===== invoices.invoice_category =====
ALTER TABLE `invoices`
    MODIFY COLUMN `invoice_category` VARCHAR(50) NOT NULL
    COMMENT 'فئة الفاتورة: sales | purchase';
ALTER TABLE `invoices`
    DROP CONSTRAINT IF EXISTS `invoices_chk_invoice_category`;
ALTER TABLE `invoices`
    ADD CONSTRAINT `invoices_chk_invoice_category`
    CHECK (`invoice_category` IN ('sales','purchase'));

-- ===== invoices.payment_type =====
ALTER TABLE `invoices`
    MODIFY COLUMN `payment_type` VARCHAR(50) NULL
    COMMENT 'نوع الدفع: cash | credit | bank_transfer | etc.';
ALTER TABLE `invoices`
    DROP CONSTRAINT IF EXISTS `invoices_chk_payment_type`;
ALTER TABLE `invoices`
    ADD CONSTRAINT `invoices_chk_payment_type`
    CHECK (`payment_type` IN ('cash','credit','bank_transfer','cheque','online','partial','other'));

-- ===== invoices.delivery_type =====
ALTER TABLE `invoices`
    MODIFY COLUMN `delivery_type` VARCHAR(50) NULL
    COMMENT 'نوع التسليم: cash | credit';
ALTER TABLE `invoices`
    DROP CONSTRAINT IF EXISTS `invoices_chk_delivery_type`;
ALTER TABLE `invoices`
    ADD CONSTRAINT `invoices_chk_delivery_type`
    CHECK (`delivery_type` IN ('cash','credit','prepaid'));

-- ===== invoices.payment_status =====
ALTER TABLE `invoices`
    MODIFY COLUMN `payment_status` VARCHAR(50) NOT NULL DEFAULT 'unpaid'
    COMMENT 'حالة الدفع: unpaid | partial | paid | overpaid';
ALTER TABLE `invoices`
    DROP CONSTRAINT IF EXISTS `invoices_chk_payment_status`;
ALTER TABLE `invoices`
    ADD CONSTRAINT `invoices_chk_payment_status`
    CHECK (`payment_status` IN ('unpaid','partial','paid','overpaid','refunded','cancelled'));

-- ===== invoices.invoice_status =====
ALTER TABLE `invoices`
    MODIFY COLUMN `invoice_status` VARCHAR(50) NOT NULL DEFAULT 'draft'
    COMMENT 'حالة الفاتورة: draft | review | approved | posted | partial | cancelled';
ALTER TABLE `invoices`
    DROP CONSTRAINT IF EXISTS `invoices_chk_invoice_status`;
ALTER TABLE `invoices`
    ADD CONSTRAINT `invoices_chk_invoice_status`
    CHECK (`invoice_status` IN ('draft','review','approved','posted','partial','pending','cancelled','returned','closed'));


-- ===== financial_transactions.transaction_type =====
ALTER TABLE `financial_transactions`
    MODIFY COLUMN `transaction_type` VARCHAR(50) NOT NULL
    COMMENT 'نوع المعاملة المالية';
ALTER TABLE `financial_transactions`
    DROP CONSTRAINT IF EXISTS `ft_chk_transaction_type`;
ALTER TABLE `financial_transactions`
    ADD CONSTRAINT `ft_chk_transaction_type`
    CHECK (`transaction_type` IN (
        'invoice','purchase','receipt','payment','expense',
        'refund','transfer','adjustment','opening','payroll','journal'
    ));

-- ===== financial_transactions.status =====
ALTER TABLE `financial_transactions`
    MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'draft'
    COMMENT 'حالة المعاملة المالية';
ALTER TABLE `financial_transactions`
    DROP CONSTRAINT IF EXISTS `ft_chk_status`;
ALTER TABLE `financial_transactions`
    ADD CONSTRAINT `ft_chk_status`
    CHECK (`status` IN ('draft','review','pending_approval','approved','posted','reconciled','cancelled','reversed','pending'));

-- ===== financial_transactions.entity_type =====
ALTER TABLE `financial_transactions`
    MODIFY COLUMN `entity_type` VARCHAR(50) NULL
    COMMENT 'نوع الكيان المرتبط بالمعاملة';
ALTER TABLE `financial_transactions`
    DROP CONSTRAINT IF EXISTS `ft_chk_entity_type`;
ALTER TABLE `financial_transactions`
    ADD CONSTRAINT `ft_chk_entity_type`
    CHECK (`entity_type` IN ('customer','supplier','employee','bank','cash','expense','revenue','inventory','other'));


-- ===== passports.gender =====
ALTER TABLE `passports`
    MODIFY COLUMN `gender` VARCHAR(20) NULL
    COMMENT 'الجندر: male | female';
ALTER TABLE `passports`
    DROP CONSTRAINT IF EXISTS `passports_chk_gender`;
ALTER TABLE `passports`
    ADD CONSTRAINT `passports_chk_gender`
    CHECK (`gender` IN ('male','female','Male','Female','ذكر','أنثى','M','F','m','f'));

-- ===== passports.transaction_type =====
ALTER TABLE `passports`
    MODIFY COLUMN `transaction_type` VARCHAR(50) NULL
    COMMENT 'نوع معاملة جواز السفر';
ALTER TABLE `passports`
    DROP CONSTRAINT IF EXISTS `passports_chk_transaction_type`;
ALTER TABLE `passports`
    ADD CONSTRAINT `passports_chk_transaction_type`
    CHECK (`transaction_type` IN (
        'new','renewal','replacement','transfer','cancellation','other',
        'hajj','umrah','work_visa','family_visit','tourism','study','business',
        'passport','visa','extension','endorsement'
    ));

-- ===== passports.service_type =====
ALTER TABLE `passports`
    MODIFY COLUMN `service_type` VARCHAR(50) NULL
    COMMENT 'نوع الخدمة المرتبطة بجواز السفر';
ALTER TABLE `passports`
    DROP CONSTRAINT IF EXISTS `passports_chk_service_type`;
ALTER TABLE `passports`
    ADD CONSTRAINT `passports_chk_service_type`
    CHECK (`service_type` IN (
        'umrah','hajj','work_visa','family_visit','tourism','study','business',
        'passport','other','visa','residency','driving_license','medical_treatment'
    ));


-- =========================================================================
-- §6. نظام المصروفات والموافقات والميزانيات
-- =========================================================================
-- §6.1 الجداول المطلوبة
-- =========================================================================

-- جدول سندات المصروفات
CREATE TABLE IF NOT EXISTS `expense_vouchers` (
    `id`                       INT            NOT NULL AUTO_INCREMENT,
    `voucher_number`           VARCHAR(50)    NOT NULL,
    `branch_id`                INT            NULL,
    `voucher_date`             DATE           NOT NULL,
    `expense_account_id`       INT            NOT NULL
        COMMENT 'حساب المصروف (من unified_accounts حيث type = expense)',
    `cash_bank_account_id`     INT            NOT NULL
        COMMENT 'حساب الصندوق أو البنك الذي يُدفع منه المبلغ',
    `supplier_id`              INT            NULL,
    `cost_center_id`           INT            NULL,
    `currency_id`              INT            NOT NULL,
    `exchange_rate`            DECIMAL(18,6)  NOT NULL DEFAULT 1.0,
    `total_amount`             DECIMAL(18,4)  NOT NULL,
    `equivalent_amount`        DECIMAL(18,4)  NOT NULL DEFAULT 0.0000,
    `tax_amount`               DECIMAL(18,4)  NOT NULL DEFAULT 0.0000,
    `description`              TEXT           NULL,
    `reference_number`         VARCHAR(100)   NULL COMMENT 'رقم مرجعي / رقم فاتورة المورد',
    `receipt_attached`         TINYINT(1)     NOT NULL DEFAULT 0,
    `budget_id`                INT            NULL,
    `status`                   VARCHAR(50)    NOT NULL DEFAULT 'draft'
        COMMENT 'draft | pending_approval | approved | rejected | posted | cancelled',
    `approval_status`          VARCHAR(50)    NULL
        COMMENT 'pending | level_1_approved | level_2_approved | fully_approved | rejected',
    `created_by`               INT            NULL,
    `posted_by`                INT            NULL,
    `cancelled_by`             INT            NULL,
    `created_at`               DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `posted_at`                DATETIME       NULL,
    `cancelled_at`             DATETIME       NULL,
    `created_ip`               VARCHAR(45)    NULL,
    `posted_ip`                VARCHAR(45)    NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_expense_voucher_number` (`voucher_number`),
    KEY `idx_expense_status`   (`status`),
    KEY `idx_expense_account`  (`expense_account_id`),
    KEY `idx_expense_cashbank` (`cash_bank_account_id`),
    KEY `idx_expense_costcntr` (`cost_center_id`),
    KEY `idx_expense_supplier` (`supplier_id`),
    KEY `idx_expense_budget`   (`budget_id`),
    KEY `idx_expense_branch`   (`branch_id`),
    KEY `idx_expense_created`  (`created_by`),
    KEY `idx_expense_date`     (`voucher_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سندات المصروفات التشغيلية والإدارية';

ALTER TABLE `expense_vouchers`
    DROP CONSTRAINT IF EXISTS `expv_chk_status`;
ALTER TABLE `expense_vouchers`
    ADD CONSTRAINT `expv_chk_status`
    CHECK (`status` IN ('draft','pending_approval','approved','rejected','posted','cancelled'));

ALTER TABLE `expense_vouchers`
    DROP CONSTRAINT IF EXISTS `expv_chk_approval`;
ALTER TABLE `expense_vouchers`
    ADD CONSTRAINT `expv_chk_approval`
    CHECK (`approval_status` IN (NULL,'pending','level_1_approved','level_2_approved','fully_approved','rejected'));

ALTER TABLE `expense_vouchers`
    DROP CONSTRAINT IF EXISTS `expv_chk_amount`;
ALTER TABLE `expense_vouchers`
    ADD CONSTRAINT `expv_chk_amount`
    CHECK (`total_amount` >= 0 AND `tax_amount` >= 0);


-- جدول خطوات موافقات المصروفات
CREATE TABLE IF NOT EXISTS `expense_approvals` (
    `id`                 INT            NOT NULL AUTO_INCREMENT,
    `expense_voucher_id` INT            NOT NULL,
    `approval_level`     INT            NOT NULL DEFAULT 1
        COMMENT 'مستوى الموافقة 1,2,3...',
    `approver_role_id`   INT            NULL,
    `approver_user_id`   INT            NULL
        COMMENT 'إذا كان الموافقة لمستخدم محدد وليس دوراً',
    `min_amount`         DECIMAL(18,4)  NOT NULL DEFAULT 0.0000,
    `max_amount`         DECIMAL(18,4)  NULL,
    `action_taken`       VARCHAR(50)    NULL
        COMMENT 'NULL (pending) | approved | rejected | delegated',
    `action_by`          INT            NULL,
    `action_at`          DATETIME       NULL,
    `comment`            TEXT           NULL,
    `created_at`         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ea_voucher`     (`expense_voucher_id`),
    KEY `idx_ea_level`       (`approval_level`),
    KEY `idx_ea_action`      (`action_taken`),
    KEY `idx_ea_role`        (`approver_role_id`),
    KEY `idx_ea_user`        (`approver_user_id`),
    CONSTRAINT `fk_ea_voucher`
        FOREIGN KEY (`expense_voucher_id`) REFERENCES `expense_vouchers` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='موافقات المصروفات حسب المستوى والمبلغ';

ALTER TABLE `expense_approvals`
    DROP CONSTRAINT IF EXISTS `ea_chk_action`;
ALTER TABLE `expense_approvals`
    ADD CONSTRAINT `ea_chk_action`
    CHECK (`action_taken` IN (NULL,'approved','rejected','delegated','escalated'));


-- جدول الميزانيات السنوية/الفصلية
CREATE TABLE IF NOT EXISTS `budgets` (
    `id`              INT            NOT NULL AUTO_INCREMENT,
    `budget_name`     VARCHAR(200)   NOT NULL,
    `fiscal_year`     INT            NOT NULL,
    `budget_period`   VARCHAR(20)    NOT NULL DEFAULT 'annual'
        COMMENT 'annual | quarterly | monthly',
    `period_number`   INT            NULL,
    `currency_id`     INT            NOT NULL,
    `total_budget`    DECIMAL(18,4)  NOT NULL,
    `status`          VARCHAR(50)    NOT NULL DEFAULT 'draft'
        COMMENT 'draft | approved | active | closed | archived',
    `cost_center_id`  INT            NULL,
    `description`     TEXT           NULL,
    `approved_by`     INT            NULL,
    `created_by`      INT            NULL,
    `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `approved_at`     DATETIME       NULL,
    PRIMARY KEY (`id`),
    KEY `idx_bgt_year`     (`fiscal_year`),
    KEY `idx_bgt_period`   (`budget_period`),
    KEY `idx_bgt_status`   (`status`),
    KEY `idx_bgt_costcntr` (`cost_center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الميزانيات السنوية والفصلية';

ALTER TABLE `budgets`
    DROP CONSTRAINT IF EXISTS `bgt_chk_period`;
ALTER TABLE `budgets`
    ADD CONSTRAINT `bgt_chk_period`
    CHECK (`budget_period` IN ('annual','semi_annual','quarterly','monthly'));

ALTER TABLE `budgets`
    DROP CONSTRAINT IF EXISTS `bgt_chk_status`;
ALTER TABLE `budgets`
    ADD CONSTRAINT `bgt_chk_status`
    CHECK (`status` IN ('draft','submitted','approved','active','closed','archived'));

ALTER TABLE `budgets`
    DROP CONSTRAINT IF EXISTS `bgt_chk_total`;
ALTER TABLE `budgets`
    ADD CONSTRAINT `bgt_chk_total`
    CHECK (`total_budget` >= 0);


-- تخصيصات الميزانية على الحسابات (بنود المصروفات)
CREATE TABLE IF NOT EXISTS `budget_allocations` (
    `id`                  INT            NOT NULL AUTO_INCREMENT,
    `budget_id`           INT            NOT NULL,
    `expense_account_id`  INT            NOT NULL,
    `allocated_amount`    DECIMAL(18,4)  NOT NULL,
    `consumed_amount`     DECIMAL(18,4)  NOT NULL DEFAULT 0.0000,
    `committed_amount`    DECIMAL(18,4)  NOT NULL DEFAULT 0.0000
        COMMENT 'المبالغ في المصروفات قيد الموافقة',
    `notes`               TEXT           NULL,
    `created_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bgt_account` (`budget_id`,`expense_account_id`),
    KEY `idx_balloc_account` (`expense_account_id`),
    CONSTRAINT `fk_balloc_budget`
        FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تخصيصات الميزانية على بنود المصروفات';

ALTER TABLE `budget_allocations`
    DROP CONSTRAINT IF EXISTS `balloc_chk_amounts`;
ALTER TABLE `budget_allocations`
    ADD CONSTRAINT `balloc_chk_amounts`
    CHECK (`allocated_amount` >= 0 AND `consumed_amount` >= 0 AND `committed_amount` >= 0);


-- تقرير عرضي: ملخص المصروفات حسب الحساب
CREATE OR REPLACE VIEW `v_expense_summary_by_category` AS
SELECT
    EXTRACT(YEAR_MONTH FROM ev.voucher_date)                    AS period_ym,
    DATE_FORMAT(ev.voucher_date, '%Y-%m')                       AS period_label,
    COALESCE(ua.account_name_ar, CONCAT('حساب ', ua.account_code)) AS expense_account_name,
    ua.account_code                                             AS expense_account_code,
    COALESCE(cc.center_name_ar, CONCAT('مركز ', cc.center_code)) AS cost_center_name,
    ev.status                                                   AS voucher_status,
    COUNT(*)                                                    AS voucher_count,
    SUM(ev.total_amount)                                        AS total_amount,
    SUM(ev.tax_amount)                                          AS total_tax,
    SUM(ev.equivalent_amount)                                   AS total_equivalent,
    SUM(CASE WHEN ev.budget_id IS NULL THEN ev.total_amount ELSE 0 END) AS amount_without_budget
FROM expense_vouchers ev
JOIN unified_accounts ua  ON ua.id = ev.expense_account_id
LEFT JOIN cost_centers cc ON cc.id = ev.cost_center_id
WHERE ev.status <> 'cancelled'
GROUP BY period_ym, period_label, expense_account_name, expense_account_code,
         cost_center_name, voucher_status
ORDER BY period_ym DESC, total_amount DESC;


-- تقرير عرضي: مقارنة الميزانية مقابل الفعلي
CREATE OR REPLACE VIEW `v_budget_vs_actual` AS
SELECT
    b.fiscal_year,
    b.budget_name,
    b.budget_period,
    b.period_number,
    b.status                                                    AS budget_status,
    COALESCE(cc.center_name_ar, CONCAT('مركز ', cc.center_code)) AS cost_center_name,
    COALESCE(ua.account_name_ar, CONCAT('حساب ', ua.account_code)) AS expense_account,
    ba.allocated_amount,
    ba.consumed_amount,
    ba.committed_amount,
    (ba.allocated_amount - ba.consumed_amount - ba.committed_amount) AS remaining_amount,
    CASE WHEN ba.allocated_amount > 0
         THEN ROUND(((ba.consumed_amount + ba.committed_amount) / ba.allocated_amount) * 100, 2)
         ELSE 0 END                                             AS consumption_percent,
    CASE WHEN ba.allocated_amount > 0
              AND (ba.consumed_amount + ba.committed_amount) > ba.allocated_amount
         THEN 'overrun'
         WHEN ba.allocated_amount > 0
              AND ((ba.consumed_amount + ba.committed_amount) / ba.allocated_amount) >= 0.80
         THEN 'warning'
         ELSE 'on_track' END                                    AS budget_status_flag
FROM budget_allocations ba
JOIN budgets           b  ON b.id  = ba.budget_id
JOIN unified_accounts  ua ON ua.id = ba.expense_account_id
LEFT JOIN cost_centers cc ON cc.id = b.cost_center_id;


DELIMITER $$

-- =========================================================================
-- §6.2 التحقق من توفر رصيد الميزانية
-- =========================================================================
DROP FUNCTION IF EXISTS `fn_check_budget_availability`$$
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_check_budget_availability`(
    p_budget_id          INT,
    p_expense_account_id INT,
    p_requested_amount   DECIMAL(18,4)
) RETURNS TINYINT(1)
    READS SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الميزانيات] هل يوجد رصيد كافٍ في الميزانية لهذا البند؟ (1=نعم 0=لا)'
BEGIN
    DECLARE v_alloc   DECIMAL(18,4) DEFAULT 0;
    DECLARE v_used    DECIMAL(18,4) DEFAULT 0;
    DECLARE v_remain  DECIMAL(18,4) DEFAULT 0;

    IF p_budget_id IS NULL OR p_expense_account_id IS NULL THEN
        RETURN 1;
    END IF;

    SELECT allocated_amount, (consumed_amount + committed_amount)
      INTO v_alloc, v_used
      FROM budget_allocations
     WHERE budget_id          = p_budget_id
       AND expense_account_id = p_expense_account_id
     LIMIT 1;

    IF v_alloc IS NULL THEN
        RETURN 0;
    END IF;

    SET v_remain = v_alloc - v_used;
    RETURN (v_remain + 0.01) >= COALESCE(p_requested_amount, 0);
END$$


-- =========================================================================
-- §6.3 استهلاك رصيد الميزانية / إرجاعه
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_consume_budget`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_consume_budget`(
    IN p_budget_id          INT,
    IN p_expense_account_id INT,
    IN p_amount             DECIMAL(18,4),
    IN p_mode               VARCHAR(20)
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الميزانيات] استهلاك/إرجاع رصيد بند ميزاني: mode=(consume|release|commit|uncommit)'
sp_budget_body:BEGIN
    DECLARE v_mode VARCHAR(20);

    IF p_budget_id IS NULL OR p_expense_account_id IS NULL THEN
        LEAVE sp_budget_body;
    END IF;

    SET v_mode = LOWER(TRIM(p_mode));

    IF v_mode = 'consume' THEN
        UPDATE budget_allocations
           SET consumed_amount = consumed_amount + COALESCE(p_amount, 0),
               committed_amount = GREATEST(committed_amount - COALESCE(p_amount, 0), 0)
         WHERE budget_id          = p_budget_id
           AND expense_account_id = p_expense_account_id;
    ELSEIF v_mode = 'release' THEN
        UPDATE budget_allocations
           SET consumed_amount = GREATEST(consumed_amount - COALESCE(p_amount, 0), 0)
         WHERE budget_id          = p_budget_id
           AND expense_account_id = p_expense_account_id;
    ELSEIF v_mode = 'commit' THEN
        UPDATE budget_allocations
           SET committed_amount = committed_amount + COALESCE(p_amount, 0)
         WHERE budget_id          = p_budget_id
           AND expense_account_id = p_expense_account_id;
    ELSEIF v_mode = 'uncommit' THEN
        UPDATE budget_allocations
           SET committed_amount = GREATEST(committed_amount - COALESCE(p_amount, 0), 0)
         WHERE budget_id          = p_budget_id
           AND expense_account_id = p_expense_account_id;
    END IF;
END$$


-- =========================================================================
-- §6.4 إنشاء سند مصروف جديد sp_create_expense_voucher
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_create_expense_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_expense_voucher`(
    IN p_branch_id            INT,
    IN p_voucher_date         DATE,
    IN p_expense_account_id   INT,
    IN p_cash_bank_account_id INT,
    IN p_supplier_id          INT,
    IN p_cost_center_id       INT,
    IN p_currency_id          INT,
    IN p_exchange_rate        DECIMAL(18,6),
    IN p_total_amount         DECIMAL(18,4),
    IN p_tax_amount           DECIMAL(18,4),
    IN p_description          TEXT,
    IN p_reference_number     VARCHAR(100),
    IN p_budget_id            INT,
    IN p_created_by           INT,
    IN p_voucher_num_in       VARCHAR(50),
    OUT p_voucher_id          INT,
    OUT p_voucher_number      VARCHAR(50)
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[المصروفات] إنشاء سند مصروف جديد — مع فحص الميزانية وإنشاء موافقات'
sp_create_ev_body:BEGIN
    DECLARE v_acc_type         VARCHAR(50);
    DECLARE v_cash_type        VARCHAR(50);
    DECLARE v_budget_ok        TINYINT(1);
    DECLARE v_created_ip       VARCHAR(45) DEFAULT NULL;
    DECLARE v_created_ua       TEXT        DEFAULT NULL;
    DECLARE v_equivalent       DECIMAL(18,4);
    DECLARE v_need_approval    TINYINT(1) DEFAULT 0;
    DECLARE v_min_level        INT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'expense_account',  CAST(p_expense_account_id AS CHAR),
            'amount',           CAST(p_total_amount AS CHAR),
            'created_by',       CAST(p_created_by AS CHAR),
            'mysql_errno',      CAST(@err_no AS CHAR),
            'sqlstate',         @err_sqlstate
        );
        CALL sp_log_error('sp_create_expense_voucher', @err_msg, p_created_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية. يرجى مراجعة بيانات المصروف والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    -- (1) التحقق من وجود الحسابات ونوعها (expense + asset/bank/box)
    IF p_expense_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء سند المصروف: حساب المصروف غير محدد.';
    END IF;

    SELECT account_type INTO v_acc_type
      FROM unified_accounts WHERE id = p_expense_account_id LIMIT 1;

    IF v_acc_type IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء سند المصروف: حساب المصروف غير موجود.';
    END IF;

    IF v_acc_type <> 'expense' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء سند المصروف: الحساب المحدد ليس حساب مصروفات. استخدم سند صرف للموردين.';
    END IF;

    IF p_cash_bank_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء سند المصروف: حساب الصندوق/البنك غير محدد.';
    END IF;

    SELECT account_type INTO v_cash_type
      FROM unified_accounts WHERE id = p_cash_bank_account_id LIMIT 1;

    IF v_cash_type IS NULL OR v_cash_type NOT IN ('box','bank','asset') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء سند المصروف: حساب الصندوق/البنك غير صالح.';
    END IF;

    IF COALESCE(p_total_amount, 0) <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء سند المصروف: مبلغ المصروف يجب أن يكون أكبر من صفر.';
    END IF;

    -- (2) فحص توفر الميزانية
    IF p_budget_id IS NOT NULL THEN
        SET v_budget_ok = fn_check_budget_availability(
            p_budget_id, p_expense_account_id, p_total_amount
        );
        IF v_budget_ok = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'تعذر إنشاء سند المصروف: الرصيد الميزاني غير كافٍ لهذا البند.';
        END IF;
    END IF;

    -- (3) جلب عنوان IP لصانع السند
    IF p_created_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_created_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_created_by LIMIT 1
            );
        END;
    END IF;

    -- (4) إنشاء رقم السند التسلسلي
    SET p_voucher_number = COALESCE(
        NULLIF(TRIM(p_voucher_num_in), ''),
        fn_get_next_sequence('expense_voucher')
    );

    SET v_equivalent = COALESCE(
        NULLIF(p_total_amount * COALESCE(NULLIF(p_exchange_rate, 0), 1.0), 0),
        p_total_amount
    );

    -- (5) تحديد ما إذا كان يحتاج موافقة حسب المبلغ (قاعدة مثال: > 5000 يحتاج موافقة)
    SET v_need_approval = CASE WHEN p_total_amount > 5000 THEN 1 ELSE 0 END;

    -- (6) إنشاء سند المصروف
    INSERT INTO expense_vouchers (
        voucher_number, branch_id, voucher_date,
        expense_account_id, cash_bank_account_id, supplier_id,
        cost_center_id, currency_id, exchange_rate,
        total_amount, equivalent_amount, tax_amount,
        description, reference_number, budget_id,
        status, approval_status,
        created_by, created_at, created_ip
    ) VALUES (
        p_voucher_number, p_branch_id, COALESCE(p_voucher_date, CURDATE()),
        p_expense_account_id, p_cash_bank_account_id, p_supplier_id,
        p_cost_center_id, p_currency_id, COALESCE(NULLIF(p_exchange_rate, 0), 1.0),
        p_total_amount, v_equivalent, COALESCE(p_tax_amount, 0),
        p_description, p_reference_number, p_budget_id,
        CASE WHEN v_need_approval = 1 THEN 'pending_approval' ELSE 'draft' END,
        CASE WHEN v_need_approval = 1 THEN 'pending' ELSE NULL END,
        p_created_by, NOW(), v_created_ip
    );
    SET p_voucher_id = LAST_INSERT_ID();

    -- (7) خصم الرصيد الميزاني "قيد الالتزام" إذا كان هناك ميزانية
    IF p_budget_id IS NOT NULL THEN
        CALL sp_consume_budget(p_budget_id, p_expense_account_id, p_total_amount, 'commit');
    END IF;

    -- (8) إنشاء سجلات الموافقة إذا لزم الأمر
    IF v_need_approval = 1 THEN
        -- المستوى 1: موافقة رئيس القسم للمبالغ من 5000 إلى 20000
        IF p_total_amount BETWEEN 5000 AND 20000 THEN
            INSERT INTO expense_approvals
                (expense_voucher_id, approval_level, min_amount, max_amount, approver_role_id)
            VALUES (p_voucher_id, 1, 5000, 20000,
                    (SELECT id FROM roles WHERE name IN ('branch_manager','admin') LIMIT 1));
        END IF;
        -- المستوى 2: موافقة المدير المالي للمبالغ > 20000
        IF p_total_amount > 20000 THEN
            INSERT INTO expense_approvals
                (expense_voucher_id, approval_level, min_amount, max_amount, approver_role_id)
            VALUES
                (p_voucher_id, 1, 5000, 20000,
                    (SELECT id FROM roles WHERE name IN ('branch_manager','admin') LIMIT 1)),
                (p_voucher_id, 2, 20000, 999999999,
                    (SELECT id FROM roles WHERE name IN ('finance_manager','super_admin','owner') LIMIT 1));
        END IF;
    END IF;

    -- (9) سجل مراجعة
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_created_by, 0),
        'create', 'expense_vouchers', p_voucher_id,
        NULL,
        JSON_OBJECT(
            'voucher_number',    p_voucher_number,
            'expense_account',   CAST(p_expense_account_id AS CHAR),
            'cash_account',      CAST(p_cash_bank_account_id AS CHAR),
            'total_amount',      CAST(p_total_amount AS CHAR),
            'currency_id',       CAST(p_currency_id AS CHAR),
            'budget_id',         CAST(p_budget_id AS CHAR),
            'need_approval',     CAST(v_need_approval AS CHAR),
            'status',            CASE WHEN v_need_approval=1 THEN 'pending_approval' ELSE 'draft' END
        ),
        v_created_ip, v_created_ua, NOW()
    );

    COMMIT;
END$$


-- =========================================================================
-- §6.5 الموافقة على المصروف أو رفضه
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_process_expense_approval`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_process_expense_approval`(
    IN p_voucher_id  INT,
    IN p_approver_id INT,
    IN p_action      VARCHAR(20),
    IN p_comment     TEXT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الموافقات] موافقة/رفض مصروف — يعالج المستويات ويُحدث الحالة'
sp_process_ea_body:BEGIN
    DECLARE v_curr_status       VARCHAR(50);
    DECLARE v_curr_approval     VARCHAR(50);
    DECLARE v_budget_id         INT;
    DECLARE v_exp_acc_id        INT;
    DECLARE v_amount            DECIMAL(18,4);
    DECLARE v_total_levels      INT;
    DECLARE v_approved_levels   INT;
    DECLARE v_action_ip         VARCHAR(45) DEFAULT NULL;
    DECLARE v_action_ua         TEXT        DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'voucher_id',   CAST(p_voucher_id AS CHAR),
            'approver_id',  CAST(p_approver_id AS CHAR),
            'action',       p_action,
            'mysql_errno',  CAST(@err_no AS CHAR),
            'sqlstate',     @err_sqlstate
        );
        CALL sp_log_error('sp_process_expense_approval', @err_msg, p_approver_id, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية. يرجى مراجعة الصلاحيات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_voucher_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر معالجة الموافقة: معرف المصروف غير صالح.';
        ROLLBACK;
    END IF;

    IF LOWER(p_action) NOT IN ('approve','reject') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر معالجة الموافقة: نوع الإجراء غير صالح.';
        ROLLBACK;
    END IF;

    SELECT status, approval_status, budget_id, expense_account_id, total_amount
      INTO v_curr_status, v_curr_approval, v_budget_id, v_exp_acc_id, v_amount
      FROM expense_vouchers WHERE id = p_voucher_id LIMIT 1;

    IF v_curr_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر معالجة الموافقة: سند المصروف غير موجود.';
        ROLLBACK;
    END IF;

    IF v_curr_status <> 'pending_approval' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر معالجة الموافقة: المصروف ليس قيد الموافقة حالياً.';
        ROLLBACK;
    END IF;

    IF p_approver_id IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_action_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_approver_id LIMIT 1
            );
        END;
    END IF;

    IF LOWER(p_action) = 'reject' THEN
        UPDATE expense_vouchers
           SET status          = 'rejected',
               approval_status = 'rejected'
         WHERE id = p_voucher_id;

        UPDATE expense_approvals
           SET action_taken = 'rejected',
               action_by    = p_approver_id,
               action_at    = NOW(),
               comment      = p_comment
         WHERE expense_voucher_id = p_voucher_id
           AND action_taken IS NULL
         ORDER BY approval_level ASC
         LIMIT 1;

        IF v_budget_id IS NOT NULL THEN
            CALL sp_consume_budget(v_budget_id, v_exp_acc_id, v_amount, 'uncommit');
        END IF;

        INSERT INTO audit_logs (user_id, action, table_name, record_id,
                                old_values, new_values, ip_address, user_agent, created_at)
        VALUES (
            COALESCE(p_approver_id, 0),
            'reject', 'expense_vouchers', p_voucher_id,
            JSON_OBJECT('old_status', v_curr_status,
                        'old_approval', v_curr_approval),
            JSON_OBJECT('new_status','rejected','comment',LEFT(p_comment,500)),
            v_action_ip, v_action_ua, NOW()
        );

        COMMIT;
        LEAVE sp_process_ea_body;
    END IF;

    -- الموافقة: تحديث أول سجل موافقة معلق
    UPDATE expense_approvals
       SET action_taken = 'approved',
           action_by    = p_approver_id,
           action_at    = NOW(),
           comment      = p_comment
     WHERE expense_voucher_id = p_voucher_id
       AND action_taken IS NULL
     ORDER BY approval_level ASC
     LIMIT 1;

    SELECT COUNT(*), SUM(CASE WHEN action_taken = 'approved' THEN 1 ELSE 0 END)
      INTO v_total_levels, v_approved_levels
      FROM expense_approvals
     WHERE expense_voucher_id = p_voucher_id;

    IF v_total_levels = v_approved_levels THEN
        UPDATE expense_vouchers
           SET status          = 'approved',
               approval_status = 'fully_approved'
         WHERE id = p_voucher_id;
    ELSE
        UPDATE expense_vouchers
           SET approval_status = CONCAT('level_', v_approved_levels, '_approved')
         WHERE id = p_voucher_id;
    END IF;

    INSERT INTO audit_logs (user_id, action, table_name, record_id,
                            old_values, new_values, ip_address, user_agent, created_at)
    VALUES (
        COALESCE(p_approver_id, 0),
        'approve', 'expense_vouchers', p_voucher_id,
        JSON_OBJECT('old_status', v_curr_status,
                    'approved_levels', CAST(v_approved_levels AS CHAR),
                    'total_levels', CAST(v_total_levels AS CHAR)),
        JSON_OBJECT('comment', LEFT(p_comment, 500)),
        v_action_ip, v_action_ua, NOW()
    );

    COMMIT;
END$$


-- =========================================================================
-- §6.6 ترحيل المصروف (إنشاء قيود اليومية + استهلاك الميزانية نهائياً)
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_post_expense_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_expense_voucher`(
    IN p_voucher_id INT,
    IN p_posted_by  INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[المصروفات] ترحيل المصروف — قيد يومية مدين المصروف + دائن الصندوق + تحديث أرصدة'
sp_post_ev_body:BEGIN
    DECLARE v_status              VARCHAR(50);
    DECLARE v_exp_acc_id          INT;
    DECLARE v_cash_acc_id         INT;
    DECLARE v_currency_id         INT;
    DECLARE v_exchange_rate       DECIMAL(18,6);
    DECLARE v_total_amount        DECIMAL(18,4);
    DECLARE v_tax_amount          DECIMAL(18,4);
    DECLARE v_description         TEXT;
    DECLARE v_budget_id           INT;
    DECLARE v_created_by          INT;
    DECLARE v_before_json         JSON;
    DECLARE v_after_json          JSON;
    DECLARE v_posted_ip           VARCHAR(45) DEFAULT NULL;
    DECLARE v_posted_ua           TEXT        DEFAULT NULL;
    DECLARE v_branch_id           INT;
    DECLARE v_cost_center_id      INT;
    DECLARE v_supplier_id         INT;
    DECLARE v_voucher_number      VARCHAR(50);
    DECLARE v_tx_id               INT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'voucher_id',   CAST(p_voucher_id AS CHAR),
            'posted_by',    CAST(p_posted_by AS CHAR),
            'mysql_errno',  CAST(@err_no AS CHAR),
            'sqlstate',     @err_sqlstate
        );
        CALL sp_log_error('sp_post_expense_voucher', @err_msg, p_posted_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_voucher_id IS NULL OR p_voucher_id <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر ترحيل المصروف: معرف السند غير صالح.';
    END IF;

    SELECT ev.status, ev.expense_account_id, ev.cash_bank_account_id,
           ev.currency_id, ev.exchange_rate, ev.total_amount, ev.tax_amount,
           ev.description, ev.budget_id, COALESCE(ev.created_by, 0),
           ev.branch_id, ev.cost_center_id, ev.supplier_id, ev.voucher_number
      INTO v_status, v_exp_acc_id, v_cash_acc_id,
           v_currency_id, v_exchange_rate, v_total_amount, v_tax_amount,
           v_description, v_budget_id, v_created_by,
           v_branch_id, v_cost_center_id, v_supplier_id, v_voucher_number
      FROM expense_vouchers ev
     WHERE ev.id = p_voucher_id
     LIMIT 1;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر ترحيل المصروف: السند غير موجود.';
    END IF;

    IF v_status = 'posted' THEN
        LEAVE sp_post_ev_body;
    END IF;

    IF v_status NOT IN ('draft','approved') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر ترحيل المصروف: الحالة الحالية لا تسمح بالترحيل.';
    END IF;

    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_posted_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_posted_by LIMIT 1
            );
        END;
    END IF;

    -- (1) إنشاء معاملة مالية من نوع "expense"
    INSERT INTO financial_transactions (
        transaction_number, transaction_date, transaction_type,
        reference_type, reference_id, branch_id,
        party_account_id, cash_bank_account_id,
        currency_id, amount, exchange_rate,
        status, description,
        created_by, posted_by, created_at, posted_at,
        created_ip, posted_ip
    ) VALUES (
        v_voucher_number, CURDATE(), 'expense',
        'expense_voucher', p_voucher_id, v_branch_id,
        v_supplier_id, v_cash_acc_id,
        v_currency_id, v_total_amount, COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
        'posted', v_description,
        COALESCE(v_created_by, p_posted_by, 0), p_posted_by, NOW(), NOW(),
        v_posted_ip, v_posted_ip
    );
    SET v_tx_id = LAST_INSERT_ID();

    -- (2) قيد اليومية:
    --     مدين = حساب المصروف (expense)
    --     دائن = حساب الصندوق/البنك (asset)
    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        v_tx_id, 1,
        v_exp_acc_id, 'expense', v_currency_id,
        v_total_amount, 0,
        v_total_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,
        v_description, 'expense_debit', NOW()
    );

    IF COALESCE(v_tax_amount, 0) > 0 THEN
        -- قيد ضريبة القيمة المضافة على المصروف (ذمم مدينة ضريبة مدين)
        INSERT INTO journal_lines (
            financial_transaction_id, line_number,
            account_id, account_type, currency_id,
            debit, credit, base_debit, base_credit,
            description, line_type, created_at
        ) VALUES (
            v_tx_id, 2,
            (SELECT id FROM unified_accounts WHERE account_type = 'vat_receivable' LIMIT 1),
            'asset', v_currency_id,
            v_tax_amount, 0,
            v_tax_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,
            'ضريبة مدخلة على المصروف', 'vat_debit', NOW()
        );
    END IF;

    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        v_tx_id, IF(COALESCE(v_tax_amount,0)>0, 3, 2),
        v_cash_acc_id, 'asset', v_currency_id,
        0, v_total_amount + COALESCE(v_tax_amount, 0),
        0, (v_total_amount + COALESCE(v_tax_amount, 0)) * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
        v_description, 'cash_credit', NOW()
    );

    CALL sp_validate_journal_balance(v_tx_id);

    -- (3) تحديث حالة سند المصروف إلى مرحل
    UPDATE expense_vouchers
       SET status       = 'posted',
           posted_by    = p_posted_by,
           posted_at    = NOW(),
           posted_ip    = v_posted_ip
     WHERE id = p_voucher_id;

    -- (4) استهلاك رصيد الميزانية نهائياً (تحويل من committed إلى consumed)
    IF v_budget_id IS NOT NULL THEN
        CALL sp_consume_budget(v_budget_id, v_exp_acc_id, v_total_amount, 'consume');
    END IF;

    -- (5) سجل المراجعة
    SET v_before_json = JSON_OBJECT('id', CAST(p_voucher_id AS CHAR), 'old_status', v_status);
    SET v_after_json  = JSON_OBJECT(
        'id',                  CAST(p_voucher_id AS CHAR),
        'new_status',          'posted',
        'transaction_id',      CAST(v_tx_id AS CHAR),
        'posted_by',           CAST(p_posted_by AS CHAR),
        'journal_lines_count', CAST(IF(COALESCE(v_tax_amount,0)>0,3,2) AS CHAR)
    );
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_posted_by, v_created_by, 0),
        'post', 'expense_vouchers', p_voucher_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_posted_ip, v_posted_ua, NOW()
    );

    -- (6) تحديث الأرصدة
    CALL sp_update_account_balances();

    COMMIT;
END$$


-- =========================================================================
-- §6.7 إنشاء ميزانية جديدة مع تخصيصاتها
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_create_budget`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_budget`(
    IN p_budget_name    VARCHAR(200),
    IN p_fiscal_year    INT,
    IN p_budget_period  VARCHAR(20),
    IN p_period_number  INT,
    IN p_currency_id    INT,
    IN p_cost_center_id INT,
    IN p_description    TEXT,
    IN p_allocations    JSON,
    IN p_created_by     INT,
    OUT p_budget_id     INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الميزانيات] إنشاء ميزانية جديدة مع تخصيصاتها على بنود المصروفات. p_allocations = مصفوفة JSON بتنسيق [{expense_account_id, allocated_amount}, ...]'
sp_create_bgt_body:BEGIN
    DECLARE v_i         INT DEFAULT 0;
    DECLARE v_acc_id    INT;
    DECLARE v_alloc_amt DECIMAL(18,4);
    DECLARE v_total_bgt DECIMAL(18,4) DEFAULT 0;
    DECLARE v_created_ip VARCHAR(45) DEFAULT NULL;
    DECLARE v_created_ua TEXT        DEFAULT NULL;
    DECLARE v_acc_ids   JSON;
    DECLARE v_n         INT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'fiscal_year',  CAST(p_fiscal_year AS CHAR),
            'created_by',   CAST(p_created_by AS CHAR),
            'mysql_errno',  CAST(@err_no AS CHAR),
            'sqlstate',     @err_sqlstate
        );
        CALL sp_log_error('sp_create_budget', @err_msg, p_created_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء الميزانية. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF COALESCE(p_fiscal_year, 0) <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء الميزانية: السنة المالية غير صالحة.';
    END IF;

    IF p_currency_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء الميزانية: العملة غير محددة.';
    END IF;

    IF p_allocations IS NULL OR JSON_LENGTH(p_allocations) = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء الميزانية: لا توجد تخصيصات بنود مصروفات.';
    END IF;

    IF p_created_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_created_ip = (
                SELECT SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                                 ORDER BY id DESC SEPARATOR '|'), '|', 1)
                  FROM audit_logs WHERE user_id = p_created_by LIMIT 1
            );
        END;
    END IF;

    -- (1) حساب الإجمالي للتخصيصات
    SET v_n = JSON_LENGTH(p_allocations);
    WHILE v_i < v_n DO
        SET v_acc_id    = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_allocations, CONCAT('$[',v_i,'].expense_account_id'))) AS SIGNED);
        SET v_alloc_amt = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_allocations, CONCAT('$[',v_i,'].allocated_amount'))) AS DECIMAL(18,4));
        SET v_total_bgt = v_total_bgt + COALESCE(v_alloc_amt, 0);
        SET v_i = v_i + 1;
    END WHILE;

    -- (2) إنشاء رئيس الميزانية
    INSERT INTO budgets (
        budget_name, fiscal_year, budget_period, period_number,
        currency_id, total_budget, cost_center_id, description, created_by, created_at
    ) VALUES (
        p_budget_name, p_fiscal_year, p_budget_period, p_period_number,
        p_currency_id, v_total_bgt, p_cost_center_id, p_description, p_created_by, NOW()
    );
    SET p_budget_id = LAST_INSERT_ID();

    -- (3) إدخال التخصيصات
    SET v_i = 0;
    WHILE v_i < v_n DO
        SET v_acc_id    = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_allocations, CONCAT('$[',v_i,'].expense_account_id'))) AS SIGNED);
        SET v_alloc_amt = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_allocations, CONCAT('$[',v_i,'].allocated_amount'))) AS DECIMAL(18,4));
        IF v_acc_id IS NOT NULL AND v_alloc_amt > 0 THEN
            INSERT INTO budget_allocations
                (budget_id, expense_account_id, allocated_amount)
            VALUES (p_budget_id, v_acc_id, v_alloc_amt)
            ON DUPLICATE KEY UPDATE allocated_amount = v_alloc_amt;
        END IF;
        SET v_i = v_i + 1;
    END WHILE;

    -- (4) سجل مراجعة
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_created_by, 0),
        'create', 'budgets', p_budget_id,
        NULL,
        JSON_OBJECT(
            'budget_name',   p_budget_name,
            'fiscal_year',   CAST(p_fiscal_year AS CHAR),
            'budget_period', p_budget_period,
            'total_budget',  CAST(v_total_bgt AS CHAR),
            'allocations_count', CAST(v_n AS CHAR)
        ),
        v_created_ip, v_created_ua, NOW()
    );

    COMMIT;
END$$


DELIMITER ;

-- =========================================================================
-- §6.9 تعبئة أولية لأرقام التسلسلية (إذا لم تكن موجودة)
-- =========================================================================
INSERT INTO sequence_numbers (sequence_name, last_number, year)
SELECT 'expense_voucher', 0, YEAR(NOW())
 WHERE NOT EXISTS (SELECT 1 FROM sequence_numbers WHERE sequence_name = 'expense_voucher');


-- =========================================================================
-- §7. تعديل sp_create_payment_voucher: التحقق من نوع الحساب
-- يمنع استخدام سند الصرف العادي لحسابات المصروفات (يجب استخدام sp_create_expense_voucher بدلاً منه)
-- يُبقي هذا الإجراء للموردين وذمم الدائنة فقط
-- =========================================================================
DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_create_payment_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_payment_voucher`(
    IN `p_branch_id` INT,
    IN `p_reference_type` VARCHAR(50),
    IN `p_reference_id` INT,
    IN `p_amount` DECIMAL(18,4),
    IN `p_currency_id` INT,
    IN `p_equivalent_amount` DECIMAL(18,4),
    IN `p_cash_bank_account_id` INT,
    IN `p_party_account_id` INT,
    IN `p_trx_num_in` VARCHAR(50),
    IN `p_description` TEXT,
    IN `p_created_by` INT,
    IN `p_invoice_allocations` JSON,
    OUT `p_transaction_id` INT,
    OUT `p_transaction_number` VARCHAR(50)
)
    MODIFIES SQL DATA SQL SECURITY INVOKER
    COMMENT '[V3] إنشاء سند صرف — + فحص نوع الحساب: للموردين فقط (لا للمصروفات)'
sp_create_pv_body:BEGIN
    DECLARE v_inv_id         INT;
    DECLARE v_alloc_amount   DECIMAL(18,4);
    DECLARE v_inv_net        DECIMAL(18,2);
    DECLARE v_inv_received   DECIMAL(18,2);
    DECLARE v_alloc_rem      DECIMAL(18,2);
    DECLARE v_new_json       JSON;
    DECLARE v_trx_num        VARCHAR(50);
    DECLARE v_i              INT DEFAULT 0;
    DECLARE v_created_ip     VARCHAR(45) DEFAULT NULL;
    DECLARE v_created_ua     TEXT        DEFAULT NULL;
    DECLARE v_party_currency INT         DEFAULT NULL;
    DECLARE v_party_acc_type VARCHAR(50);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'p_party_account_id', CAST(p_party_account_id AS CHAR),
            'p_amount',          CAST(p_amount AS CHAR),
            'p_created_by',      CAST(p_created_by AS CHAR),
            'mysql_errno',       CAST(@err_no AS CHAR),
            'sqlstate',          @err_sqlstate
        );
        CALL sp_log_error('sp_create_payment_voucher', @err_msg, p_created_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إتمام العملية. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    -- ★ فحص جديد: لا تستخدم هذا الإجراء لحسابات المصروفات
    IF p_party_account_id IS NOT NULL THEN
        SELECT account_type INTO v_party_acc_type
          FROM unified_accounts WHERE id = p_party_account_id LIMIT 1;

        IF v_party_acc_type IS NOT NULL AND v_party_acc_type = 'expense' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'تعذر إنشاء السند: الحساب المحدد هو حساب مصروفات. استخدم إجراء إنشاء سند المصروف sp_create_expense_voucher بدلاً من سند الصرف العادي.';
        END IF;

        -- التأكيد على أن الحساب من النوع الصحيح (مورد / ذمم دائنة)
        IF v_party_acc_type IS NOT NULL
           AND v_party_acc_type NOT IN ('supplier','accounts_payable','liability','asset','bank','box') THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'تعذر إنشاء السند: نوع الحساب المحدد غير مناسب لسند الصرف. للموردين استخدم حساباً من النوع supplier أو accounts_payable.';
        END IF;
    END IF;

    SET p_reference_type = fn_sanitize_safe(p_reference_type, 1);
    SET p_trx_num_in     = fn_sanitize_safe(p_trx_num_in,     1);
    SET p_description    = fn_sanitize_safe(p_description,    0);

    IF p_party_account_id IS NOT NULL AND p_currency_id IS NOT NULL THEN
        BEGIN
            DECLARE v_match INT DEFAULT 0;
            SELECT DISTINCT currency_id INTO v_party_currency
              FROM account_balances_unified
             WHERE account_id = p_party_account_id
             ORDER BY CASE WHEN currency_id = p_currency_id THEN 0 ELSE 1 END
             LIMIT 1;
            SELECT COUNT(*) INTO v_match
              FROM account_balances_unified
             WHERE account_id = p_party_account_id AND currency_id = p_currency_id;
            IF v_match = 0 AND v_party_currency IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'تعذر إتمام العملية: عملة السند لا تطابق عملة حساب المورد.';
            END IF;
        END;
    END IF;

    IF p_created_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_created_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_created_by LIMIT 1);
        END;
    END IF;

    SET v_trx_num = COALESCE(NULLIF(TRIM(p_trx_num_in), ''), fn_get_next_sequence('payment'));

    INSERT INTO financial_transactions (
        transaction_number, transaction_date, transaction_type,
        reference_type, reference_id, branch_id,
        party_account_id, cash_bank_account_id,
        currency_id, amount, exchange_rate,
        status, description,
        created_by, created_at, created_ip, created_user_agent
    ) VALUES (
        v_trx_num, CURDATE(), 'payment',
        NULLIF(TRIM(p_reference_type), ''), p_reference_id, p_branch_id,
        p_party_account_id, p_cash_bank_account_id,
        p_currency_id, COALESCE(p_amount, 0),
        COALESCE(NULLIF(p_equivalent_amount, 0), 1.0),
        'draft', p_description,
        p_created_by, NOW(), v_created_ip, v_created_ua
    );
    SET p_transaction_id     = LAST_INSERT_ID();
    SET p_transaction_number = v_trx_num;

    IF p_invoice_allocations IS NOT NULL AND JSON_LENGTH(p_invoice_allocations) > 0 THEN
        SET v_i = 0;
        WHILE v_i < JSON_LENGTH(p_invoice_allocations) DO
            SET v_inv_id       = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_invoice_allocations, CONCAT('$[',v_i,'].invoice_id'))) AS SIGNED);
            SET v_alloc_amount = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_invoice_allocations, CONCAT('$[',v_i,'].amount')))     AS DECIMAL(18,4));
            IF v_inv_id IS NOT NULL AND v_alloc_amount > 0 THEN
                SELECT COALESCE(net_amount, total_amount - discount)
                  INTO v_inv_net
                  FROM invoices WHERE id = v_inv_id;
                IF v_inv_net IS NOT NULL THEN
                    SELECT COALESCE(SUM(pa.allocated_amount), 0) INTO v_inv_received
                      FROM payment_allocations pa
                      JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
                     WHERE pa.invoice_id = v_inv_id
                       AND ft.status = 'posted'
                       AND pa.financial_transaction_id <> p_transaction_id;
                    SET v_alloc_rem = v_inv_net - v_inv_received;
                    IF v_alloc_amount > (v_alloc_rem + 0.01) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                            'تعذر إتمام العملية: مخصصات إحدى الفواتير تتجاوز المبلغ المتبقي.';
                    END IF;
                    INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount)
                    VALUES (p_transaction_id, v_inv_id, v_alloc_amount);
                END IF;
            END IF;
            SET v_i = v_i + 1;
        END WHILE;
    END IF;

    SET v_new_json = JSON_OBJECT(
        'id',                   CAST(p_transaction_id AS CHAR),
        'transaction_number',   v_trx_num,
        'transaction_type',     'payment',
        'amount',               CAST(COALESCE(p_amount, 0) AS CHAR),
        'currency_id',          CAST(p_currency_id AS CHAR),
        'party_account_id',     CAST(p_party_account_id AS CHAR),
        'party_account_type',   COALESCE(v_party_acc_type, ''),
        'cash_bank_account_id', CAST(p_cash_bank_account_id AS CHAR),
        'status',               'draft',
        'created_by',           CAST(p_created_by AS CHAR)
    );
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_created_by, 0), 'create', 'financial_transactions', p_transaction_id,
        NULL, CAST(v_new_json AS CHAR), v_created_ip, v_created_ua, NOW()
    );

    COMMIT;
END$$

DELIMITER ;

-- =========================================================================
-- نهاية PATCH الكامل
-- =========================================================================
