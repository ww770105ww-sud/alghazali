-- =========================================================================
-- PATCH V4 — دمج المصروفات في نفس السندات الحالية (financial_transactions)
-- =========================================================================
-- الهدف: إزالة كيان expense_vouchers المنفصل وتحويل المصروف
--        إلى نوع (transaction_type = 'expense') ضمن نفس الجدول المركزي.
--        الحفاظ على الموافقات والميزانيات وربطها مباشرة بالمعاملة.
-- =========================================================================

DELIMITER $$

-- =========================================================================
-- §0. إجراءات مساعدة صامتة لتعديل الأعمدة والفهات دون توقف (تجاهل الخطأ إذا كان العمود/الفهرس موجود/مفقود)
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_silent_column_op`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_silent_column_op`(IN p_sql TEXT)
    MODIFIES SQL DATA SQL SECURITY INVOKER
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    SET @sv_sql = p_sql;
    PREPARE stmt_silent_col FROM @sv_sql;
    EXECUTE stmt_silent_col;
    DEALLOCATE PREPARE stmt_silent_col;
END$$

DROP PROCEDURE IF EXISTS `sp_silent_index_op`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_silent_index_op`(IN p_sql TEXT)
    MODIFIES SQL DATA SQL SECURITY INVOKER BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    SET @sv_sql = p_sql;
    PREPARE stmt_silent_idx FROM @sv_sql;
    EXECUTE stmt_silent_idx;
    DEALLOCATE PREPARE stmt_silent_idx;
END$$

-- =========================================================================
-- §1. إزالة FK القديم + تحويل expense_approvals إلى transaction_approvals
-- =========================================================================
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE expense_approvals DROP FOREIGN KEY IF EXISTS fk_ea_voucher'
))$$

DROP TABLE IF EXISTS `transaction_approvals`$$
CREATE TABLE `transaction_approvals` (
    `id`                 INT            NOT NULL AUTO_INCREMENT,
    `financial_transaction_id` INT       NOT NULL,
    `approval_level`     INT            NOT NULL DEFAULT 1
        COMMENT 'مستوى الموافقة 1,2,3...',
    `approver_role_id`   INT            NULL,
    `approver_user_id`   INT            NULL,
    `min_amount`         DECIMAL(18,4)  NOT NULL DEFAULT 0.0000,
    `max_amount`         DECIMAL(18,4)  NULL,
    `action_taken`       VARCHAR(50)    NULL
        COMMENT 'NULL (pending) | approved | rejected | delegated',
    `action_by`          INT            NULL,
    `action_at`          DATETIME       NULL,
    `comment`            TEXT           NULL,
    `created_at`         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ta_transaction` (`financial_transaction_id`),
    KEY `idx_ta_level`       (`approval_level`),
    KEY `idx_ta_action`      (`action_taken`),
    KEY `idx_ta_role`        (`approver_role_id`),
    KEY `idx_ta_user`        (`approver_user_id`),
    CONSTRAINT `fk_ta_transaction`
        FOREIGN KEY (`financial_transaction_id`)
        REFERENCES `financial_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='موافقات المعاملات المالية (المصروفات وغيرها حسب المبلغ والمستوى)'$$

-- =========================================================================
-- §2. نقل بيانات الموافقات القديمة (إن وجدت) من expense_approvals
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_migrate_expense_vouchers_into_transactions`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_migrate_expense_vouchers_into_transactions`()
    MODIFIES SQL DATA SQL SECURITY INVOKER
sp_mig_body:BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_ev_id INT;
    DECLARE v_ev_num VARCHAR(50);
    DECLARE v_ev_branch INT;
    DECLARE v_ev_date DATE;
    DECLARE v_exp_acc INT;
    DECLARE v_cb_acc INT;
    DECLARE v_supplier INT;
    DECLARE v_cc INT;
    DECLARE v_cur INT;
    DECLARE v_rate DECIMAL(18,6);
    DECLARE v_total DECIMAL(18,4);
    DECLARE v_equiv DECIMAL(18,4);
    DECLARE v_tax DECIMAL(18,4);
    DECLARE v_desc TEXT;
    DECLARE v_ref VARCHAR(100);
    DECLARE v_budget INT;
    DECLARE v_status VARCHAR(50);
    DECLARE v_appr_status VARCHAR(50);
    DECLARE v_created_by INT;
    DECLARE v_posted_by INT;
    DECLARE v_cancelled_by INT;
    DECLARE v_posted_at DATETIME;
    DECLARE v_cancelled_at DATETIME;
    DECLARE v_created_ip VARCHAR(45);

    DECLARE cur CURSOR FOR
        SELECT id, voucher_number, branch_id, voucher_date,
               expense_account_id, cash_bank_account_id, supplier_id,
               cost_center_id, currency_id, exchange_rate, total_amount,
               equivalent_amount, tax_amount, description, reference_number,
               budget_id, status, approval_status, created_by, posted_by,
               cancelled_by, posted_at, cancelled_at, created_ip
          FROM expense_vouchers;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    -- إذا كان الجدول فارغاً فلا داعي للهجرة
    IF (SELECT COUNT(*) FROM expense_vouchers) = 0 THEN
        LEAVE sp_mig_body;
    END IF;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_ev_id, v_ev_num, v_ev_branch, v_ev_date,
                       v_exp_acc, v_cb_acc, v_supplier,
                       v_cc, v_cur, v_rate, v_total,
                       v_equiv, v_tax, v_desc, v_ref,
                       v_budget, v_status, v_appr_status, v_created_by, v_posted_by,
                       v_cancelled_by, v_posted_at, v_cancelled_at, v_created_ip;
        IF v_done = 1 THEN
            LEAVE read_loop;
        END IF;

        -- تجنب تكرار السند في حالة تشغيل الهجرة مرتين
        IF EXISTS (
            SELECT 1 FROM financial_transactions
             WHERE source_system = 'legacy_expense_voucher'
               AND source_id = v_ev_id
        ) THEN
            ITERATE read_loop;
        END IF;

        INSERT INTO financial_transactions (
            transaction_number, transaction_date, transaction_type,
            reference_type, reference_id, branch_id,
            party_account_id, cash_bank_account_id, entity_type, entity_id,
            currency_id, amount, exchange_rate, equivalent_amount,
            status, description,
            cost_center_id, supplier_id,
            budget_id, tax_amount, approval_status,
            source_system, source_id,
            created_by, posted_by, cancelled_by,
            created_at, posted_at, cancelled_at, created_ip
        ) VALUES (
            v_ev_num, v_ev_date, 'expense',
            'expense_voucher', v_ev_id, v_ev_branch,
            v_exp_acc, v_cb_acc, 'expense', v_exp_acc,
            v_cur, v_total, v_rate, v_equiv,
            v_status, v_desc,
            v_cc, v_supplier,
            v_budget, v_tax, v_appr_status,
            'legacy_expense_voucher', v_ev_id,
            v_created_by, v_posted_by, v_cancelled_by,
            NOW(), v_posted_at, v_cancelled_at, v_created_ip
        );
        SET @new_ft_id = LAST_INSERT_ID();

        -- نقل الموافقات
        INSERT INTO transaction_approvals
            (financial_transaction_id, approval_level, approver_role_id,
             approver_user_id, min_amount, max_amount, action_taken,
             action_by, action_at, comment, created_at)
        SELECT @new_ft_id, approval_level, approver_role_id,
               approver_user_id, min_amount, max_amount, action_taken,
               action_by, action_at, comment, created_at
          FROM expense_approvals WHERE expense_voucher_id = v_ev_id;

        -- إذا كان السند مترسلاً فننشئ أسطر اليومية ونحدث الأرصدة
        IF v_status = 'posted' AND v_exp_acc IS NOT NULL AND v_cb_acc IS NOT NULL THEN
            INSERT INTO journal_lines
                (financial_transaction_id, account_id, debit, credit,
                 currency_id, description, cost_center_id) VALUES
                (@new_ft_id, v_exp_acc, v_total, 0, v_cur, v_desc, v_cc),
                (@new_ft_id, v_cb_acc,  0,        v_total, v_cur, v_desc, v_cc);

            BEGIN
                DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
                CALL sp_update_account_balances();
            END;
        END IF;
    END LOOP;
    CLOSE cur;
END$$
DELIMITER ;

-- =========================================================================
-- §3. إضافة أعمدة المصروفات + الموافقات + الميزانية مباشرة إلى financial_transactions
-- =========================================================================
DELIMITER $$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ',
    'ADD COLUMN `cost_center_id` INT NULL AFTER `description`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ',
    'ADD COLUMN `supplier_id` INT NULL AFTER `cost_center_id`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ',
    'ADD COLUMN `budget_id` INT NULL AFTER `supplier_id`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ',
    'ADD COLUMN `tax_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 AFTER `budget_id`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ',
    'ADD COLUMN `approval_status` VARCHAR(50) NULL AFTER `tax_amount` ',
    'COMMENT ''pending | level_1_approved | level_2_approved | fully_approved | rejected'''
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ',
    'ADD COLUMN `source_system` VARCHAR(80) NULL AFTER `approval_status`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ',
    'ADD COLUMN `source_id` BIGINT NULL AFTER `source_system`'
))$$

CALL sp_silent_index_op('ALTER TABLE financial_transactions ADD KEY `idx_ft_costcenter` (`cost_center_id`)')$$
CALL sp_silent_index_op('ALTER TABLE financial_transactions ADD KEY `idx_ft_supplier`   (`supplier_id`)')$$
CALL sp_silent_index_op('ALTER TABLE financial_transactions ADD KEY `idx_ft_budget`     (`budget_id`)')$$
CALL sp_silent_index_op('ALTER TABLE financial_transactions ADD KEY `idx_ft_apprstatus` (`approval_status`)')$$
CALL sp_silent_index_op('ALTER TABLE financial_transactions ADD KEY `idx_ft_source`     (`source_system`, `source_id`)')$$

CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions DROP CONSTRAINT IF EXISTS `ft_chk_approval_status`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ADD CONSTRAINT `ft_chk_approval_status` ',
    'CHECK (`approval_status` IN (NULL,''pending'',''level_1_approved'',''level_2_approved'',''fully_approved'',''rejected''))'
))$$

CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions DROP CONSTRAINT IF EXISTS `ft_chk_tax_nonneg`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ADD CONSTRAINT `ft_chk_tax_nonneg` ',
    'CHECK (`tax_amount` >= 0)'
))$$

-- =========================================================================
-- §4. تنفيذ الهجرة من expense_vouchers → financial_transactions (إن وجدت بيانات)
-- =========================================================================
CALL sp_migrate_expense_vouchers_into_transactions()$$

-- =========================================================================
-- §5. تعديل CHECK transaction_type للتأكيد على وجود expense ضمن الأنواع المركزية
-- =========================================================================
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions DROP CONSTRAINT IF EXISTS `ft_chk_transaction_type`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ADD CONSTRAINT `ft_chk_transaction_type` CHECK (`transaction_type` IN (',
    '''invoice'',''purchase'',''receipt'',''payment'',''expense'',',
    '''refund'',''transfer'',''adjustment'',''opening'',''payroll'',''journal''',
    '))'
))$$

-- =========================================================================
-- §6. تعديل CHECK entity_type للتأكيد على وجود expense كـ entity_type موثّق
-- =========================================================================
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions DROP CONSTRAINT IF EXISTS `ft_chk_entity_type`'
))$$
CALL sp_silent_column_op(CONCAT(
    'ALTER TABLE financial_transactions ADD CONSTRAINT `ft_chk_entity_type` CHECK (`entity_type` IN (',
    '''customer'',''supplier'',''employee'',''bank'',''cash'',''expense'',''revenue'',''inventory'',''other''',
    '))'
))$$

-- =========================================================================
-- §7. إعادة تعريف sp_create_payment_voucher مع إزالة حظر المصروفات
--     والسماح بتمرير المعاملات المباشرة (expense) من خلال نفس المسار
-- =========================================================================
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
    COMMENT '[V4] إنشاء سند صرف مركزي (للموردين أو للمصروفات المباشرة على حد سواء)'
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
    DECLARE v_effective_type VARCHAR(50) DEFAULT 'payment';

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

    SET p_reference_type = fn_sanitize_safe(p_reference_type, 1);
    SET p_trx_num_in     = fn_sanitize_safe(p_trx_num_in,     1);
    SET p_description    = fn_sanitize_safe(p_description,    0);

    -- ★ التعديل الأساسي: تحديد نوع المعاملة بناءً على نوع الحساب
    -- إذا كان الحساب طرفًا حساب مصروف → نصنف المعاملة كـ expense
    -- وتبقى داخل نفس الجدول المركزي financial_transactions
    IF p_party_account_id IS NOT NULL THEN
        SELECT account_type INTO v_party_acc_type
          FROM unified_accounts WHERE id = p_party_account_id LIMIT 1;

        IF v_party_acc_type = 'expense' THEN
            SET v_effective_type = 'expense';
        END IF;
    END IF;

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
                    'تعذر إتمام العملية: عملة السند لا تطابق عملة الحساب الطرفي.';
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

    SET v_trx_num = COALESCE(NULLIF(TRIM(p_trx_num_in), ''),
                             fn_get_next_sequence(IF(v_effective_type = 'expense', 'expense', 'payment')));

    INSERT INTO financial_transactions (
        transaction_number, transaction_date, transaction_type,
        reference_type, reference_id, branch_id,
        party_account_id, cash_bank_account_id, entity_type, entity_id,
        currency_id, amount, exchange_rate, equivalent_amount,
        status, description,
        cost_center_id, supplier_id,
        budget_id, tax_amount, approval_status,
        created_by, created_at, created_ip, created_user_agent
    ) VALUES (
        v_trx_num, CURDATE(), v_effective_type,
        NULLIF(TRIM(p_reference_type), ''), p_reference_id, p_branch_id,
        p_party_account_id, p_cash_bank_account_id,
        v_effective_type, p_party_account_id,
        p_currency_id, COALESCE(p_amount, 0),
        COALESCE(NULLIF(p_equivalent_amount, 0), 1.0),
        COALESCE(NULLIF(p_equivalent_amount, 0), 0),
        IF(v_effective_type = 'expense', 'pending_approval', 'draft'),
        p_description,
        NULL, NULL,
        NULL, 0.0000,
        IF(v_effective_type = 'expense', 'pending', NULL),
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

    -- ★ إذا كانت المصروف يتطلب موافقات، ننشئ سجلّات الموافقة التلقائية هنا
    IF v_effective_type = 'expense' THEN
        -- Level 1: موافقة إدارية من المدير المباشر للمبلغ < 5,000
        INSERT INTO transaction_approvals
            (financial_transaction_id, approval_level, approver_role_id, approver_user_id,
             min_amount, max_amount, action_taken, created_at)
        VALUES (p_transaction_id, 1, NULL, NULL, 0, 5000, NULL, NOW());

        -- Level 2: موافقة مالية من مدير المالية للمبلغ >= 5,000
        IF COALESCE(p_amount, 0) >= 5000 THEN
            INSERT INTO transaction_approvals
                (financial_transaction_id, approval_level, approver_role_id, approver_user_id,
                 min_amount, max_amount, action_taken, created_at)
            VALUES (p_transaction_id, 2, NULL, NULL, 5000, 999999999999.9999, NULL, NOW());
        END IF;
    END IF;

    SET v_new_json = JSON_OBJECT(
        'id',                   CAST(p_transaction_id AS CHAR),
        'transaction_number',   v_trx_num,
        'transaction_type',     v_effective_type,
        'amount',               CAST(COALESCE(p_amount, 0) AS CHAR),
        'currency_id',          CAST(p_currency_id AS CHAR),
        'party_account_id',     CAST(p_party_account_id AS CHAR),
        'party_account_type',   COALESCE(v_party_acc_type, ''),
        'cash_bank_account_id', CAST(p_cash_bank_account_id AS CHAR),
        'status',               IF(v_effective_type = 'expense', 'pending_approval', 'draft'),
        'approval_status',      IF(v_effective_type = 'expense', 'pending', NULL),
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

-- =========================================================================
-- §8. إجراء مركزي جديد لإنشاء المصروف ضمن نفس المسار: sp_create_expense_as_payment
--     مجرد Wrapper يسمي الإجراء باسم صريح للمبرمجين مع تمرير المعاملات إلى
--     نفس sp_create_payment_voucher المركزي (مع وضع علامة خاصة للمصروف).
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_create_expense_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_expense_voucher`(
    IN `p_branch_id`            INT,
    IN `p_expense_account_id`   INT,
    IN `p_cash_bank_account_id` INT,
    IN `p_total_amount`         DECIMAL(18,4),
    IN `p_currency_id`          INT,
    IN `p_equivalent_amount`    DECIMAL(18,4),
    IN `p_voucher_date`         DATE,
    IN `p_description`          TEXT,
    IN `p_reference_number`     VARCHAR(100),
    IN `p_cost_center_id`       INT,
    IN `p_supplier_id`          INT,
    IN `p_budget_id`            INT,
    IN `p_created_by`           INT,
    OUT `p_transaction_id`      INT,
    OUT `p_transaction_number`  VARCHAR(50)
)
    MODIFIES SQL DATA SQL SECURITY INVOKER
    COMMENT '[V4] إنشاء مصروف — عبر نفس المسار المركزي sp_create_payment_voucher'
sp_cev_body:BEGIN
    DECLARE v_acc_type VARCHAR(50);
    DECLARE v_budget_ok TINYINT(1);
    DECLARE v_created_ip VARCHAR(45) DEFAULT NULL;
    DECLARE v_created_ua TEXT        DEFAULT NULL;
    DECLARE v_trx_num    VARCHAR(50);
    DECLARE v_new_json   JSON;
    DECLARE v_equiv_amt  DECIMAL(18,4);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'p_expense_account_id',   CAST(p_expense_account_id AS CHAR),
            'p_cash_bank_account_id', CAST(p_cash_bank_account_id AS CHAR),
            'p_total_amount',         CAST(p_total_amount AS CHAR),
            'p_budget_id',            CAST(p_budget_id AS CHAR),
            'mysql_errno',            CAST(@err_no AS CHAR),
            'sqlstate',               @err_sqlstate
        );
        CALL sp_log_error('sp_create_expense_voucher', @err_msg, p_created_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء مصروف. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_expense_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'يجب تحديد حساب المصروف.';
    END IF;

    SELECT account_type INTO v_acc_type
      FROM unified_accounts WHERE id = p_expense_account_id LIMIT 1;
    IF v_acc_type <> 'expense' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'الحساب المختار ليس حساب مصروف صالح.';
    END IF;

    IF p_cash_bank_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'يجب تحديد حساب الصندوق أو البنك.';
    END IF;

    IF COALESCE(p_total_amount, 0) <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'يجب أن يكون مبلغ المصروف أكبر من صفر.';
    END IF;

    -- فحص توفر الميزانية
    IF p_budget_id IS NOT NULL THEN
        SET v_budget_ok = fn_check_budget_availability(
            p_budget_id, p_expense_account_id, p_total_amount
        );
        IF v_budget_ok = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'لا يوجد رصيد متاح كافٍ ضمن الميزانية لهذا البند.';
        END IF;
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

    SET v_equiv_amt = COALESCE(NULLIF(p_equivalent_amount, 0),
                               COALESCE(p_total_amount, 0));

    SET v_trx_num = fn_get_next_sequence('expense');

    INSERT INTO financial_transactions (
        transaction_number, transaction_date, transaction_type,
        reference_type, reference_id, branch_id,
        party_account_id, cash_bank_account_id, entity_type, entity_id,
        currency_id, amount, exchange_rate, equivalent_amount,
        status, description,
        cost_center_id, supplier_id, budget_id, tax_amount, approval_status,
        source_system, source_id,
        created_by, created_at, created_ip, created_user_agent
    ) VALUES (
        v_trx_num, COALESCE(p_voucher_date, CURDATE()), 'expense',
        'expense_ref', p_reference_number, p_branch_id,
        p_expense_account_id, p_cash_bank_account_id, 'expense', p_expense_account_id,
        p_currency_id, p_total_amount,
        COALESCE(NULLIF(p_equivalent_amount, 0), 1.0),
        v_equiv_amt,
        'pending_approval', p_description,
        p_cost_center_id, p_supplier_id, p_budget_id, 0, 'pending',
        'unified_expense', NULL,
        p_created_by, NOW(), v_created_ip, v_created_ua
    );
    SET p_transaction_id     = LAST_INSERT_ID();
    SET p_transaction_number = v_trx_num;

    -- حجز مبلغ المصروف ضمن الميزانية (التزام)
    IF p_budget_id IS NOT NULL THEN
        CALL sp_consume_budget(p_budget_id, p_expense_account_id, p_total_amount, 'commit');
    END IF;

    -- سلسلة الموافقات التلقائية
    INSERT INTO transaction_approvals
        (financial_transaction_id, approval_level, approver_role_id, approver_user_id,
         min_amount, max_amount, action_taken, created_at)
    VALUES (p_transaction_id, 1, NULL, NULL, 0, 5000, NULL, NOW());

    IF p_total_amount >= 5000 THEN
        INSERT INTO transaction_approvals
            (financial_transaction_id, approval_level, approver_role_id, approver_user_id,
             min_amount, max_amount, action_taken, created_at)
        VALUES (p_transaction_id, 2, NULL, NULL, 5000, 999999999999.9999, NULL, NOW());
    END IF;

    SET v_new_json = JSON_OBJECT(
        'id',                   CAST(p_transaction_id AS CHAR),
        'transaction_number',   v_trx_num,
        'transaction_type',     'expense',
        'amount',               CAST(p_total_amount AS CHAR),
        'currency_id',          CAST(p_currency_id AS CHAR),
        'expense_account_id',   CAST(p_expense_account_id AS CHAR),
        'cash_bank_account_id', CAST(p_cash_bank_account_id AS CHAR),
        'cost_center_id',       CAST(p_cost_center_id AS CHAR),
        'supplier_id',          CAST(p_supplier_id AS CHAR),
        'budget_id',            CAST(p_budget_id AS CHAR),
        'approval_status',      'pending',
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

-- =========================================================================
-- §9. إجراء ترحيل المصروف المركزي (بنفس مسار ترحيل سند الصرف)
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_post_expense_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_expense_voucher`(
    IN `p_transaction_id` INT,
    IN `p_posted_by`      INT
)
    MODIFIES SQL DATA SQL SECURITY INVOKER
    COMMENT '[V4] ترحيل المصروف ضمن financial_transactions + اليوميات + تحديث الأرصدة + استهلاك الميزانية'
sp_post_ev_body:BEGIN
    DECLARE v_trx_type       VARCHAR(50);
    DECLARE v_trx_status     VARCHAR(50);
    DECLARE v_trx_appr       VARCHAR(50);
    DECLARE v_exp_acc_id     INT;
    DECLARE v_cb_acc_id      INT;
    DECLARE v_currency_id    INT;
    DECLARE v_amount         DECIMAL(18,4);
    DECLARE v_cc             INT;
    DECLARE v_desc           TEXT;
    DECLARE v_budget_id      INT;
    DECLARE v_needs_level2   TINYINT(1);
    DECLARE v_posted_ip      VARCHAR(45) DEFAULT NULL;
    DECLARE v_posted_ua      TEXT        DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'p_transaction_id', CAST(p_transaction_id AS CHAR),
            'p_posted_by',      CAST(p_posted_by AS CHAR),
            'mysql_errno',      CAST(@err_no AS CHAR),
            'sqlstate',         @err_sqlstate
        );
        CALL sp_log_error('sp_post_expense_voucher', @err_msg, p_posted_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر ترحيل المصروف. يرجى مراجعة حالة الموافقات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    -- 1. جلب المعاملة
    SELECT ft.transaction_type, ft.status, ft.approval_status,
           ft.party_account_id, ft.cash_bank_account_id,
           ft.currency_id, ft.amount, ft.cost_center_id,
           ft.description, ft.budget_id
      INTO v_trx_type, v_trx_status, v_trx_appr,
           v_exp_acc_id, v_cb_acc_id,
           v_currency_id, v_amount, v_cc,
           v_desc, v_budget_id
      FROM financial_transactions ft
     WHERE ft.id = p_transaction_id
       AND ft.transaction_type = 'expense'
     LIMIT 1;

    IF v_trx_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معاملة المصروف غير موجودة أو ليست من نوع مصروف.';
    END IF;

    IF v_trx_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'لا يمكن ترحيل المصروف إلا بعد الموافقة الكاملة.';
    END IF;

    IF v_exp_acc_id IS NULL OR v_cb_acc_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'بيانات حسابات المصروف أو الصندوق غير مكتملة.';
    END IF;

    -- 2. التحقق من سلسلة الموافقات
    SET v_needs_level2 = (v_amount >= 5000);

    IF EXISTS (
        SELECT 1 FROM transaction_approvals
         WHERE financial_transaction_id = p_transaction_id
           AND action_taken <> 'approved'
           AND (approval_level = 1 OR (v_needs_level2 = 1 AND approval_level = 2))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هناك خطوة موافقة معلّقة لم تكتمل بعد.';
    END IF;

    -- 3. إنشاء أسطر القيد اليومية (مدين مصروف، دائن صندوق/بنك)
    INSERT INTO journal_lines
        (financial_transaction_id, account_id, debit, credit, currency_id, description, cost_center_id)
    VALUES
        (p_transaction_id, v_exp_acc_id, v_amount, 0,        v_currency_id, v_desc, v_cc),
        (p_transaction_id, v_cb_acc_id,  0,        v_amount, v_currency_id, v_desc, v_cc);

    -- 4. استهلاك الميزانية النهائي (تحويل الالتزام إلى استهلاك فعلي)
    IF v_budget_id IS NOT NULL THEN
        CALL sp_consume_budget(v_budget_id, v_exp_acc_id, v_amount, 'consume');
    END IF;

    -- 5. تحديث حالة المعاملة
    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_posted_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_posted_by LIMIT 1);
        END;
    END IF;

    UPDATE financial_transactions
       SET status    = 'posted',
           posted_by = p_posted_by,
           posted_at = NOW(),
           posted_ip = v_posted_ip
     WHERE id = p_transaction_id;

    -- 6. تحديث أرصدة الحسابات
    CALL sp_update_account_balances();

    -- 7. سجلّ التدقيق
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_posted_by, 0), 'post', 'financial_transactions', p_transaction_id,
        JSON_OBJECT('old_status', v_trx_status),
        JSON_OBJECT('new_status', 'posted', 'new_journal_lines', 2),
        v_posted_ip, v_posted_ua, NOW()
    );

    COMMIT;
END$$

-- =========================================================================
-- §10. إجراء الموافقة على المصروف (المركزي → يعمل على transaction_approvals)
-- =========================================================================
DROP PROCEDURE IF EXISTS `sp_process_expense_approval`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_process_expense_approval`(
    IN `p_transaction_id` INT,
    IN `p_approver_id`    INT,
    IN `p_level`          INT,
    IN `p_approved`       TINYINT(1),
    IN `p_comment`        TEXT
)
    MODIFIES SQL DATA SQL SECURITY INVOKER
    COMMENT '[V4] صرف / رفض موافقة المستوى على مصروف ضمن financial_transactions'
sp_proc_appr_body:BEGIN
    DECLARE v_curr_status    VARCHAR(50);
    DECLARE v_curr_appr      VARCHAR(50);
    DECLARE v_budget_id      INT;
    DECLARE v_exp_acc_id     INT;
    DECLARE v_amount         DECIMAL(18,4);
    DECLARE v_max_level      INT;
    DECLARE v_done_levels    INT;
    DECLARE v_action_ip      VARCHAR(45) DEFAULT NULL;
    DECLARE v_action_ua      TEXT        DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_ctx JSON;
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'p_transaction_id', CAST(p_transaction_id AS CHAR),
            'p_approver_id',    CAST(p_approver_id AS CHAR),
            'p_level',          CAST(p_level AS CHAR),
            'p_approved',       CAST(p_approved AS CHAR),
            'mysql_errno',      CAST(@err_no AS CHAR),
            'sqlstate',         @err_sqlstate
        );
        CALL sp_log_error('sp_process_expense_approval', @err_msg, p_approver_id, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر تنفيذ عملية الموافقة. يرجى المحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    SELECT ft.status, ft.approval_status, ft.budget_id, ft.party_account_id, ft.amount
      INTO v_curr_status, v_curr_appr, v_budget_id, v_exp_acc_id, v_amount
      FROM financial_transactions ft
     WHERE ft.id = p_transaction_id AND ft.transaction_type = 'expense'
     LIMIT 1;

    IF v_curr_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معاملة المصروف غير موجودة.';
    END IF;

    IF v_curr_status IN ('posted','cancelled','reversed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'لا يمكن تعديل الموافقة على معاملة منتهية.';
    END IF;

    IF p_approver_id IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_action_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_approver_id LIMIT 1);
        END;
    END IF;

    IF p_approved = 0 THEN
        -- رفض → إلغاء الالتزام الميزاني إن وجد، ووضع الحالة rejected
        UPDATE transaction_approvals
           SET action_taken = 'rejected',
               action_by    = p_approver_id,
               action_at    = NOW(),
               comment      = p_comment
         WHERE financial_transaction_id = p_transaction_id AND approval_level = p_level;

        UPDATE financial_transactions
           SET status          = 'rejected',
               approval_status = 'rejected',
               cancelled_by    = p_approver_id,
               cancelled_at    = NOW()
         WHERE id = p_transaction_id;

        IF v_budget_id IS NOT NULL THEN
            CALL sp_consume_budget(v_budget_id, v_exp_acc_id, v_amount, 'uncommit');
        END IF;

        INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, created_at)
        VALUES (COALESCE(p_approver_id, 0), 'reject', 'financial_transactions', p_transaction_id,
                JSON_OBJECT('rejection_comment', COALESCE(p_comment, '')), v_action_ip, NOW());
        COMMIT;
        LEAVE sp_proc_appr_body;
    END IF;

    -- موافقة المستوى المطلوب
    UPDATE transaction_approvals
       SET action_taken = 'approved',
           action_by    = p_approver_id,
           action_at    = NOW(),
           comment      = p_comment
     WHERE financial_transaction_id = p_transaction_id AND approval_level = p_level;

    -- عدد المستويات الكلي + عدد المستويات المنجزة
    SELECT COUNT(*) INTO v_max_level
      FROM transaction_approvals WHERE financial_transaction_id = p_transaction_id;

    SELECT COUNT(*) INTO v_done_levels
      FROM transaction_approvals
     WHERE financial_transaction_id = p_transaction_id
       AND action_taken = 'approved';

    UPDATE financial_transactions
       SET approval_status = CASE
            WHEN v_max_level = 1 AND v_done_levels = 1 THEN 'fully_approved'
            WHEN v_done_levels = 1 AND v_max_level > 1 THEN 'level_1_approved'
            WHEN v_done_levels >= 2 THEN 'fully_approved'
            ELSE 'pending'
       END,
           status = CASE
            WHEN (v_done_levels = v_max_level) THEN 'approved'
            ELSE 'pending_approval'
       END
     WHERE id = p_transaction_id;

    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, created_at)
    VALUES (COALESCE(p_approver_id, 0), 'approve', 'financial_transactions', p_transaction_id,
            JSON_OBJECT('level', CAST(p_level AS CHAR), 'comment', COALESCE(p_comment, '')),
            v_action_ip, NOW());

    COMMIT;
END$$

DELIMITER ;

-- =========================================================================
-- نهاية PATCH V4 — المصروف الآن ضمن نفس السندات المركزية
-- =========================================================================
