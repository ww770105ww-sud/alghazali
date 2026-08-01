DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_create_invoice`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_invoice` (IN `p_invoice_category` ENUM('sales','purchase'), IN `p_branch_id` INT, IN `p_source_type` VARCHAR(100), IN `p_source_id` INT, IN `p_customer_id` INT, IN `p_supplier_id` INT, IN `p_agent_id` INT, IN `p_service_id` INT, IN `p_currency_id` INT, IN `p_total_amount` DECIMAL(18,2), IN `p_discount` DECIMAL(15,2), IN `p_cost_amount` DECIMAL(15,2), IN `p_payment_type` VARCHAR(50), IN `p_description` TEXT, IN `p_created_by` INT, IN `p_cost_center_id` INT, IN `p_invoice_number` VARCHAR(50), OUT `p_invoice_id` INT)  MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '[PATCH:TX_ADDED][PHP-compat:17IN+1OUT] إنشاء فاتورة — TX ADDED — تنقية + عملة + IP/UA' sp_create_invoice_body:BEGIN
    DECLARE v_net_amount        DECIMAL(15,2);
    DECLARE v_party_account_id  INT;
    DECLARE v_tax_amount        DECIMAL(15,2) DEFAULT 0;
    DECLARE v_account_id        INT           DEFAULT NULL;
    DECLARE v_customer_acct_id  INT           DEFAULT NULL;
    DECLARE v_supplier_acct_id  INT           DEFAULT NULL;
    DECLARE v_payment_status    VARCHAR(20)   DEFAULT 'unpaid';
    DECLARE v_created_ip        VARCHAR(45)   DEFAULT NULL;
    DECLARE v_created_ua        TEXT          DEFAULT NULL;
    DECLARE v_party_currency    INT           DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SET p_invoice_number = fn_sanitize_safe(p_invoice_number, 1);
    SET p_source_type    = fn_sanitize_safe(p_source_type,    1);
    SET p_payment_type   = fn_sanitize_safe(p_payment_type,   1);
    SET p_description    = fn_sanitize_safe(p_description,    0);
    IF p_invoice_category = 'sales' THEN
        SET v_party_account_id = (SELECT account_id FROM customers WHERE id = p_customer_id);
    ELSE
        SET v_party_account_id = (SELECT account_id FROM suppliers WHERE id = p_supplier_id);
    END IF;
    SET v_account_id        = v_party_account_id;
    SET v_customer_acct_id  = CASE WHEN p_invoice_category='sales'    THEN v_party_account_id ELSE NULL END;
    SET v_supplier_acct_id  = CASE WHEN p_invoice_category='purchase' THEN v_party_account_id ELSE NULL END;
    IF v_party_account_id IS NOT NULL AND p_currency_id IS NOT NULL THEN
        BEGIN
            DECLARE v_match INT DEFAULT 0;
            SELECT DISTINCT currency_id INTO v_party_currency
              FROM account_balances_unified
             WHERE account_id = v_party_account_id
             ORDER BY CASE WHEN currency_id = p_currency_id THEN 0 ELSE 1 END
             LIMIT 1;
            SELECT COUNT(*) INTO v_match
              FROM account_balances_unified
             WHERE account_id = v_party_account_id AND currency_id = p_currency_id;
            IF v_match = 0 AND v_party_currency IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'sp_create_invoice: عملة الفاتورة لا تطابق عملة حساب العميل/المورد';
            END IF;
        END;
    END IF;
    IF v_created_ip IS NULL AND p_created_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_created_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_created_by LIMIT 1);
        END;
    END IF;
    SET v_net_amount = ROUND(
        COALESCE(p_total_amount, 0)
      - COALESCE(p_discount,   0)
      + v_tax_amount, 2);
    IF COALESCE(p_total_amount, 0) - COALESCE(p_discount, 0) > 0 THEN
        SET v_payment_status = 'unpaid';
    END IF;
    SET p_invoice_number = NULLIF(TRIM(p_invoice_number), '');
    IF p_invoice_number IS NULL THEN
        SET p_invoice_number = COALESCE(
            fn_get_next_sequence(CASE
                WHEN p_invoice_category='sales' THEN CASE p_source_type
                    WHEN 'BusFlight'     THEN 'busflight_invoice'
                    WHEN 'umrah'         THEN 'umrah_invoice'
                    WHEN 'work_visa'     THEN 'work_visa_invoice'
                    WHEN 'FamilyVisit'   THEN 'family_visit_invoice'
                    WHEN 'Passport'      THEN 'passport_invoice'
                    ELSE 'invoice' END
                ELSE CASE p_source_type
                    WHEN 'BusFlight'     THEN 'purchase_invoice'
                    ELSE 'purchase_invoice' END
                END),
            CONCAT('INV-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(LAST_INSERT_ID()+1, 4, '0'))
        );
    END IF;
    INSERT INTO invoices (
        invoice_number, invoice_date, due_date,
        branch_id, invoice_category, source_type, source_id,
        service_id, customer_id, supplier_id, agent_id, cost_center_id,
        currency_id, total_amount, discount, tax_amount, tax_rate,
        net_amount, cost_amount, payment_type, delivery_type,
        account_id, customer_account_id, supplier_account_id,
        amount_received, payment_status, invoice_status,
        description, created_by, created_at,
        created_ip, created_user_agent
    ) VALUES (
        p_invoice_number, NOW(), NULL,
        p_branch_id, p_invoice_category, NULLIF(TRIM(p_source_type), ''), p_source_id,
        p_service_id, p_customer_id, p_supplier_id, p_agent_id, p_cost_center_id,
        p_currency_id, COALESCE(p_total_amount, 0), COALESCE(p_discount, 0), v_tax_amount, 0,
        v_net_amount, COALESCE(p_cost_amount, 0), p_payment_type,
        CASE WHEN p_payment_type='cash' THEN 'cash' ELSE 'credit' END,
        v_account_id, v_customer_acct_id, v_supplier_acct_id,
        0, v_payment_status, 'draft',
        p_description, p_created_by, NOW(),
        v_created_ip, v_created_ua
    );
    SET p_invoice_id = LAST_INSERT_ID();
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_created_by, 0), 'create', 'invoices', p_invoice_id, NULL,
        JSON_OBJECT(
            'id',               p_invoice_id,
            'invoice_number',   p_invoice_number,
            'invoice_category', p_invoice_category,
            'branch_id',        CAST(p_branch_id AS CHAR),
            'customer_id',      CAST(p_customer_id AS CHAR),
            'supplier_id',      CAST(p_supplier_id AS CHAR),
            'service_id',       CAST(p_service_id AS CHAR),
            'currency_id',      CAST(p_currency_id AS CHAR),
            'total_amount',     CAST(COALESCE(p_total_amount, 0) AS CHAR),
            'discount',         CAST(COALESCE(p_discount, 0) AS CHAR),
            'net_amount',       CAST(v_net_amount AS CHAR),
            'payment_type',     p_payment_type,
            'payment_status',   v_payment_status,
            'invoice_status',   'draft',
            'created_by',       CAST(p_created_by AS CHAR)
        ),
        v_created_ip, v_created_ua, NOW()
    );

    COMMIT;
END$$

DROP PROCEDURE IF EXISTS `sp_create_receipt_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_receipt_voucher` (IN `p_branch_id` INT, IN `p_reference_type` VARCHAR(50), IN `p_reference_id` INT, IN `p_amount` DECIMAL(18,4), IN `p_currency_id` INT, IN `p_equivalent_amount` DECIMAL(18,4), IN `p_cash_bank_account_id` INT, IN `p_party_account_id` INT, IN `p_trx_num_in` VARCHAR(50), IN `p_description` TEXT, IN `p_created_by` INT, IN `p_invoice_allocations` JSON, OUT `p_transaction_id` INT, OUT `p_transaction_number` VARCHAR(50))  MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '[PATCH:TX_ADDED][PHP-compat:12IN+2OUT] إنشاء سند قبض — TX ADDED — تنقية + تدقيق + عملة' sp_create_rv_body:BEGIN
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

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

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
                    'sp_create_receipt_voucher: عملة السند لا تطابق عملة حساب العميل';
            END IF;
        END;
    END IF;
    IF p_created_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_created_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_created_by LIMIT 1);
        END;
    END IF;
    SET v_trx_num = COALESCE(NULLIF(TRIM(p_trx_num_in), ''), fn_get_next_sequence('receipt'));
    INSERT INTO financial_transactions (
        transaction_number, transaction_date, transaction_type,
        reference_type, reference_id, branch_id,
        party_account_id, cash_bank_account_id,
        currency_id, amount, exchange_rate,
        status, description,
        created_by, created_at, created_ip, created_user_agent
    ) VALUES (
        v_trx_num, CURDATE(), 'receipt',
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
                        SET @err_rv_msg = CONCAT(
                            'sp_create_receipt_voucher: المخصص لفاتورة #',
                            CAST(v_inv_id AS CHAR), ' = ', CAST(v_alloc_amount AS CHAR),
                            ' > المتبقي = ', CAST(v_alloc_rem AS CHAR));
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @err_rv_msg;
                    END IF;
                    INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount)
                    VALUES (p_transaction_id, v_inv_id, v_alloc_amount);
                END IF;
            END IF;
            SET v_i = v_i + 1;
        END WHILE;
    END IF;
    SET v_new_json = JSON_OBJECT(
        'id',                   p_transaction_id,
        'transaction_number',   v_trx_num,
        'transaction_type',     'receipt',
        'amount',               CAST(COALESCE(p_amount, 0) AS CHAR),
        'currency_id',          CAST(p_currency_id AS CHAR),
        'party_account_id',     CAST(p_party_account_id AS CHAR),
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

DROP PROCEDURE IF EXISTS `sp_create_payment_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_payment_voucher` (IN `p_branch_id` INT, IN `p_reference_type` VARCHAR(50), IN `p_reference_id` INT, IN `p_amount` DECIMAL(18,4), IN `p_currency_id` INT, IN `p_equivalent_amount` DECIMAL(18,4), IN `p_cash_bank_account_id` INT, IN `p_party_account_id` INT, IN `p_trx_num_in` VARCHAR(50), IN `p_description` TEXT, IN `p_created_by` INT, IN `p_invoice_allocations` JSON, OUT `p_transaction_id` INT, OUT `p_transaction_number` VARCHAR(50))  MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '[PATCH:TX_ADDED][PHP-compat:12IN+2OUT] إنشاء سند صرف — TX ADDED — تنقية + تدقيق + عملة' sp_create_pv_body:BEGIN
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

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

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
                    'sp_create_payment_voucher: عملة السند لا تطابق عملة حساب المورد';
            END IF;
        END;
    END IF;
    IF p_created_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_created_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
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
                        SET @err_pv_msg = CONCAT(
                            'sp_create_payment_voucher: المخصص لفاتورة #',
                            CAST(v_inv_id AS CHAR), ' = ', CAST(v_alloc_amount AS CHAR),
                            ' > المتبقي = ', CAST(v_alloc_rem AS CHAR));
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @err_pv_msg;
                    END IF;
                    INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount)
                    VALUES (p_transaction_id, v_inv_id, v_alloc_amount);
                END IF;
            END IF;
            SET v_i = v_i + 1;
        END WHILE;
    END IF;
    SET v_new_json = JSON_OBJECT(
        'id',                   p_transaction_id,
        'transaction_number',   v_trx_num,
        'transaction_type',     'payment',
        'amount',               CAST(COALESCE(p_amount, 0) AS CHAR),
        'currency_id',          CAST(p_currency_id AS CHAR),
        'party_account_id',     CAST(p_party_account_id AS CHAR),
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

DROP PROCEDURE IF EXISTS `sp_post_invoice`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_invoice` (IN `p_invoice_id` INT, IN `p_posted_by` INT)  MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '[PATCH:TX_ADDED][PHP-compat:2IN] ترحيل فاتورة — TX ADDED — توازن + تدقيق + حدود + IP' sp_post_invoice_body:BEGIN
    DECLARE v_category          ENUM('sales','purchase');
    DECLARE v_status            VARCHAR(20);
    DECLARE v_total_amount      DECIMAL(15,2);
    DECLARE v_net_amount        DECIMAL(15,2);
    DECLARE v_currency_id       INT;
    DECLARE v_discount          DECIMAL(15,2);
    DECLARE v_description       TEXT;
    DECLARE v_created_by        INT;
    DECLARE v_customer_id       INT;
    DECLARE v_supplier_id       INT;
    DECLARE v_branch_id         INT;
    DECLARE v_party_id          INT;
    DECLARE v_party_account_id  INT;
    DECLARE v_account_type      VARCHAR(50);
    DECLARE v_tax_amount        DECIMAL(15,2) DEFAULT 0;
    DECLARE v_party_currency    INT         DEFAULT NULL;
    DECLARE v_credit_limit      DECIMAL(18,2) DEFAULT NULL;
    DECLARE v_curr_balance      DECIMAL(18,2) DEFAULT 0;
    DECLARE v_revenue_acct      INT;
    DECLARE v_cogs_acct         INT;
    DECLARE v_ar_acct           INT;
    DECLARE v_ap_acct           INT;
    DECLARE v_tx_id             INT;
    DECLARE v_trx_num           VARCHAR(50);
    DECLARE v_posted_ip         VARCHAR(45) DEFAULT NULL;
    DECLARE v_created_ua        TEXT        DEFAULT NULL;
    DECLARE v_before_json       JSON;
    DECLARE v_after_json        JSON;
    DECLARE v_old_inv_json      JSON;
    DECLARE v_exchange_rate     DECIMAL(18,6);
    DECLARE v_cost_amount       DECIMAL(15,2);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    IF p_invoice_id IS NULL OR p_invoice_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_invoice: معرف الفاتورة غير صالح';
    END IF;
    SELECT invoice_category, invoice_status, total_amount,
           net_amount, currency_id, discount, description,
           COALESCE(created_by,0), customer_id, supplier_id, branch_id, exchange_rate, cost_amount
      INTO v_category, v_status, v_total_amount,
           v_net_amount, v_currency_id, v_discount, v_description,
           v_created_by, v_customer_id, v_supplier_id, v_branch_id, v_exchange_rate, v_cost_amount
      FROM invoices
     WHERE id = p_invoice_id
     LIMIT 1;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_invoice: الفاتورة غير موجودة';
    END IF;
    IF v_status = 'posted' THEN
        LEAVE sp_post_invoice_body;
    END IF;
    IF v_status NOT IN ('draft','review','approved','partial') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_invoice: حالة الفاتورة غير صالحة للترحيل';
    END IF;
    SET v_tax_amount = 0;
    SET v_revenue_acct = fn_get_default_leaf_account('revenue');
    SET v_cogs_acct    = fn_get_default_leaf_account('expense');
    SET v_ar_acct      = fn_get_default_leaf_account('accounts_receivable');
    SET v_ap_acct      = fn_get_default_leaf_account('accounts_payable');
    IF v_category = 'sales' THEN
        SET v_party_id         = v_customer_id;
        SET v_account_type     = 'customer';
        SET v_party_account_id = (SELECT account_id FROM customers WHERE id = v_customer_id);
    ELSE
        SET v_party_id         = v_supplier_id;
        SET v_account_type     = 'supplier';
        SET v_party_account_id = (SELECT account_id FROM suppliers WHERE id = v_supplier_id);
    END IF;
    IF v_party_account_id IS NOT NULL AND v_currency_id IS NOT NULL THEN
        BEGIN
            DECLARE v_match INT DEFAULT 0;
            SELECT DISTINCT currency_id INTO v_party_currency
              FROM account_balances_unified
             WHERE account_id = v_party_account_id
             ORDER BY CASE WHEN currency_id = v_currency_id THEN 0 ELSE 1 END
             LIMIT 1;
            SELECT COUNT(*) INTO v_match
              FROM account_balances_unified
             WHERE account_id = v_party_account_id AND currency_id = v_currency_id;
            IF v_match = 0 AND v_party_currency IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'sp_post_invoice: عملة الفاتورة لا تطابق عملة حساب العميل/المورد';
            END IF;
        END;
    END IF;
    IF v_category = 'sales' AND v_party_account_id IS NOT NULL AND v_currency_id IS NOT NULL THEN
        BEGIN
            SELECT credit_limit, current_balance
              INTO v_credit_limit, v_curr_balance
              FROM account_balances_unified
             WHERE account_id = v_party_account_id AND currency_id = v_currency_id
             LIMIT 1;
            SET v_credit_limit = COALESCE(v_credit_limit,
                (SELECT COALESCE(default_credit_limit, 0) FROM customers WHERE id = v_party_id));
            SET v_credit_limit = COALESCE(v_credit_limit, 0);
            IF v_credit_limit > 0 THEN
                IF (v_curr_balance + v_net_amount) > v_credit_limit THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                        'sp_post_invoice: تجاوز حد الائتمان المسموح للعميل';
                END IF;
            END IF;
        END;
    END IF;
    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_posted_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_posted_by LIMIT 1);
        END;
    END IF;
    SET v_old_inv_json = JSON_OBJECT(
        'id',               p_invoice_id,
        'invoice_category', v_category,
        'invoice_status',   v_status,
        'net_amount',       CAST(v_net_amount AS CHAR),
        'currency_id',      CAST(v_currency_id AS CHAR)
    );
    SET v_trx_num = fn_get_next_sequence('journal');
    INSERT INTO financial_transactions (
        transaction_number, transaction_date, transaction_type,
        reference_type, reference_id, branch_id,
        party_account_id, currency_id, amount, exchange_rate,
        description, status, posted_by, posted_at,
        created_by, created_at, created_ip, created_user_agent,
        posted_ip
    ) VALUES (
        v_trx_num, CURDATE(), CASE WHEN v_category='sales' THEN 'invoice' ELSE 'purchase' END,
        'invoice', p_invoice_id, v_branch_id,
        v_party_account_id, v_currency_id, v_total_amount,
        COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
        v_description, 'posted',
        p_posted_by, NOW(),
        v_created_by, NOW(), v_posted_ip, v_created_ua,
        v_posted_ip
    );
    SET v_tx_id = LAST_INSERT_ID();
    IF v_category = 'sales' THEN
        INSERT INTO journal_lines (
            financial_transaction_id, line_number,
            account_id, account_type, currency_id,
            debit, credit, base_debit, base_credit,
            description, line_type, created_at
        ) VALUES (
            v_tx_id, 1,
            COALESCE(v_party_account_id, v_ar_acct), 'customer', v_currency_id,
            v_net_amount, 0, v_net_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,
            v_description, 'main', NOW()
        );
        IF v_discount > 0 THEN
            INSERT INTO journal_lines (
                financial_transaction_id, line_number,
                account_id, account_type, currency_id,
                debit, credit, base_debit, base_credit,
                description, line_type, created_at
            ) VALUES (
                v_tx_id, 2,
                v_revenue_acct, 'revenue', v_currency_id,
                0, v_discount, 0, v_discount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
                'خصم على الفاتورة', 'discount', NOW()
            );
        END IF;
        INSERT INTO journal_lines (
            financial_transaction_id, line_number,
            account_id, account_type, currency_id,
            debit, credit, base_debit, base_credit,
            description, line_type, created_at
        ) VALUES (
            v_tx_id, 3,
            v_revenue_acct, 'revenue', v_currency_id,
            0, v_net_amount + v_discount, 0, (v_net_amount + v_discount) * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
            v_description, 'revenue', NOW()
        );
        IF COALESCE(v_cost_amount, 0) > 0 THEN
            INSERT INTO journal_lines (
                financial_transaction_id, line_number,
                account_id, account_type, currency_id,
                debit, credit, base_debit, base_credit,
                description, line_type, created_at
            ) VALUES (
                v_tx_id, 4,
                v_cogs_acct, 'expense', v_currency_id,
                v_cost_amount, 0,
                v_cost_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,
                'تكلفة البضاعة المباعة', 'cogs', NOW()
            );
            INSERT INTO journal_lines (
                financial_transaction_id, line_number,
                account_id, account_type, currency_id,
                debit, credit, base_debit, base_credit,
                description, line_type, created_at
            ) VALUES (
                v_tx_id, 5,
                v_revenue_acct, 'asset', v_currency_id,
                0, v_cost_amount,
                0, v_cost_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
                'تكلفة البضاعة', 'cogs_credit', NOW()
            );
        END IF;
    ELSE
        INSERT INTO journal_lines (
            financial_transaction_id, line_number,
            account_id, account_type, currency_id,
            debit, credit, base_debit, base_credit,
            description, line_type, created_at
        ) VALUES (
            v_tx_id, 1,
            v_cogs_acct, 'expense', v_currency_id,
            v_net_amount, 0,
            v_net_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,
            v_description, 'purchase_expense', NOW()
        );
        INSERT INTO journal_lines (
            financial_transaction_id, line_number,
            account_id, account_type, currency_id,
            debit, credit, base_debit, base_credit,
            description, line_type, created_at
        ) VALUES (
            v_tx_id, 2,
            COALESCE(v_party_account_id, v_ap_acct), 'supplier', v_currency_id,
            0, v_net_amount,
            0, v_net_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
            v_description, 'payable', NOW()
        );
    END IF;
    CALL sp_validate_journal_balance(v_tx_id);
    UPDATE invoices
       SET invoice_status = 'posted',
           posted_by    = p_posted_by,
           posted_at    = NOW()
     WHERE id = p_invoice_id;
    SET v_before_json = JSON_OBJECT(
        'id',               p_invoice_id,
        'invoice_category', v_category,
        'invoice_status',   v_status,
        'net_amount',       CAST(v_net_amount AS CHAR)
    );
    SET v_after_json = JSON_OBJECT(
        'id',               p_invoice_id,
        'invoice_category', v_category,
        'invoice_status',   'posted',
        'net_amount',       CAST(v_net_amount AS CHAR),
        'posted_by',        CAST(p_posted_by AS CHAR),
        'transaction_id',   CAST(v_tx_id AS CHAR)
    );
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_posted_by, v_created_by, 0),
        'post', 'invoices', p_invoice_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_posted_ip, v_created_ua, NOW()
    );
    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        COALESCE(p_posted_by, v_created_by, 0),
        'post', 'financial_transactions', v_tx_id,
        NULL, CAST(v_old_inv_json AS CHAR),
        v_posted_ip, v_created_ua, NOW()
    );
    CALL sp_recalculate_invoice_payment(p_invoice_id);
    CALL sp_update_account_balances();

    COMMIT;
END$$

DROP PROCEDURE IF EXISTS `sp_post_receipt_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_receipt_voucher` (IN `p_transaction_id` INT, IN `p_posted_by` INT)  MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '[PATCH:TX_ADDED] ترحيل سند قبض — TX ADDED — توازن + مخصصات + تدقيق + IP' sp_post_rv_body:BEGIN
    DECLARE v_status         VARCHAR(20);
    DECLARE v_amount         DECIMAL(18,4);
    DECLARE v_currency_id    INT;
    DECLARE v_exchange_rate  DECIMAL(18,6);
    DECLARE v_party_account_id INT;
    DECLARE v_cash_bank_id   INT;
    DECLARE v_description    TEXT;
    DECLARE v_created_by     INT;
    DECLARE v_tx_id          INT;
    DECLARE v_before_json    JSON;
    DECLARE v_after_json     JSON;
    DECLARE v_j_id           INT;
    DECLARE v_inv_id         INT;
    DECLARE v_alloc_amount   DECIMAL(18,4);
    DECLARE v_inv_net        DECIMAL(18,2);
    DECLARE v_inv_received   DECIMAL(18,2);
    DECLARE v_alloc_rem      DECIMAL(18,2);
    DECLARE v_posted_ip      VARCHAR(45) DEFAULT NULL;
    DECLARE v_posted_ua      TEXT        DEFAULT NULL;
    DECLARE done INT DEFAULT 0;
    DECLARE cur CURSOR FOR
        SELECT invoice_id, allocated_amount
          FROM payment_allocations
         WHERE financial_transaction_id = p_transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    IF p_transaction_id IS NULL OR p_transaction_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_receipt_voucher: معرف السند غير صالح';
    END IF;
    SELECT status, amount, currency_id, exchange_rate,
           party_account_id, cash_bank_account_id, description,
           COALESCE(created_by, 0)
      INTO v_status, v_amount, v_currency_id, v_exchange_rate,
           v_party_account_id, v_cash_bank_id, v_description, v_created_by
      FROM financial_transactions
     WHERE id = p_transaction_id AND transaction_type = 'receipt'
     LIMIT 1;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_receipt_voucher: السند غير موجود';
    END IF;
    IF v_status = 'posted' THEN LEAVE sp_post_rv_body; END IF;
    IF v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_receipt_voucher: الحالة غير صالحة للترحيل';
    END IF;
    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_posted_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_posted_by LIMIT 1);
        END;
    END IF;
    SET v_before_json = JSON_OBJECT(
        'id',                 p_transaction_id,
        'status',             v_status,
        'amount',             CAST(v_amount AS CHAR),
        'currency_id',        CAST(v_currency_id AS CHAR),
        'party_account_id',   CAST(v_party_account_id AS CHAR),
        'cash_bank_account_id', CAST(v_cash_bank_id AS CHAR)
    );
    SET v_j_id = p_transaction_id;
    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        v_j_id, 1,
        COALESCE(v_cash_bank_id,
            (SELECT id FROM unified_accounts WHERE account_type IN ('box','bank') LIMIT 1)),
        'asset', v_currency_id,
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
        v_j_id, 2,
        COALESCE(v_party_account_id,
            (SELECT id FROM unified_accounts WHERE account_type='accounts_receivable' LIMIT 1)),
        'customer', v_currency_id,
        0, v_amount,
        0, v_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
        v_description, 'customer_credit', NOW()
    );
    CALL sp_validate_journal_balance(v_j_id);
    UPDATE financial_transactions
       SET status         = 'posted',
           posted_by      = p_posted_by,
           posted_at      = NOW(),
           posted_ip      = v_posted_ip,
           updated_ip     = v_posted_ip
     WHERE id = p_transaction_id;
    SET done = 0;
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
                    SET @err_msg = CONCAT(
                        'sp_post_receipt_voucher: المخصص لفاتورة #',
                        CAST(v_inv_id AS CHAR), ' = ', CAST(v_alloc_amount AS CHAR),
                        ' > المتبقي = ', CAST(v_alloc_rem AS CHAR));
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @err_msg;
                END IF;
                CALL sp_recalculate_invoice_payment(v_inv_id);
            END IF;
        END IF;
    END LOOP;
    CLOSE cur;
    SET v_after_json = JSON_OBJECT(
        'id',                 p_transaction_id,
        'status',             'posted',
        'posted_by',          CAST(p_posted_by AS CHAR),
        'amount',             CAST(v_amount AS CHAR)
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

DROP PROCEDURE IF EXISTS `sp_post_payment_voucher`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_payment_voucher` (IN `p_transaction_id` INT, IN `p_posted_by` INT)  MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '[PATCH:TX_ADDED] ترحيل سند صرف — TX ADDED — توازن + مخصصات + تدقيق + IP' sp_post_pv_body:BEGIN
    DECLARE v_status         VARCHAR(20);
    DECLARE v_amount         DECIMAL(18,4);
    DECLARE v_currency_id    INT;
    DECLARE v_exchange_rate  DECIMAL(18,6);
    DECLARE v_party_account_id INT;
    DECLARE v_cash_bank_id   INT;
    DECLARE v_description    TEXT;
    DECLARE v_created_by     INT;
    DECLARE v_before_json    JSON;
    DECLARE v_after_json     JSON;
    DECLARE v_j_id           INT;
    DECLARE v_inv_id         INT;
    DECLARE v_alloc_amount   DECIMAL(18,4);
    DECLARE v_inv_net        DECIMAL(18,2);
    DECLARE v_inv_received   DECIMAL(18,2);
    DECLARE v_alloc_rem      DECIMAL(18,2);
    DECLARE v_posted_ip      VARCHAR(45) DEFAULT NULL;
    DECLARE v_posted_ua      TEXT        DEFAULT NULL;
    DECLARE done INT DEFAULT 0;
    DECLARE cur CURSOR FOR
        SELECT invoice_id, allocated_amount
          FROM payment_allocations
         WHERE financial_transaction_id = p_transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    IF p_transaction_id IS NULL OR p_transaction_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_payment_voucher: معرف السند غير صالح';
    END IF;
    SELECT status, amount, currency_id, exchange_rate,
           party_account_id, cash_bank_account_id, description,
           COALESCE(created_by, 0)
      INTO v_status, v_amount, v_currency_id, v_exchange_rate,
           v_party_account_id, v_cash_bank_id, v_description, v_created_by
      FROM financial_transactions
     WHERE id = p_transaction_id AND transaction_type = 'payment'
     LIMIT 1;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_payment_voucher: السند غير موجود';
    END IF;
    IF v_status = 'posted' THEN LEAVE sp_post_pv_body; END IF;
    IF v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_post_payment_voucher: الحالة غير صالحة للترحيل';
    END IF;
    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_posted_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_posted_by LIMIT 1);
        END;
    END IF;
    SET v_before_json = JSON_OBJECT(
        'id',                 p_transaction_id,
        'status',             v_status,
        'amount',             CAST(v_amount AS CHAR),
        'currency_id',        CAST(v_currency_id AS CHAR),
        'party_account_id',   CAST(v_party_account_id AS CHAR),
        'cash_bank_account_id', CAST(v_cash_bank_id AS CHAR)
    );
    SET v_j_id = p_transaction_id;
    INSERT INTO journal_lines (
        financial_transaction_id, line_number,
        account_id, account_type, currency_id,
        debit, credit, base_debit, base_credit,
        description, line_type, created_at
    ) VALUES (
        v_j_id, 1,
        COALESCE(v_party_account_id,
            (SELECT id FROM unified_accounts WHERE account_type='accounts_payable' LIMIT 1)),
        'supplier', v_currency_id,
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
        v_j_id, 2,
        COALESCE(v_cash_bank_id,
            (SELECT id FROM unified_accounts WHERE account_type IN ('box','bank') LIMIT 1)),
        'asset', v_currency_id,
        0, v_amount,
        0, v_amount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),
        v_description, 'cash_credit', NOW()
    );
    CALL sp_validate_journal_balance(v_j_id);
    UPDATE financial_transactions
       SET status         = 'posted',
           posted_by      = p_posted_by,
           posted_at      = NOW(),
           posted_ip      = v_posted_ip,
           updated_ip     = v_posted_ip
     WHERE id = p_transaction_id;
    SET done = 0;
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
                    SET @err_msg = CONCAT(
                        'sp_post_payment_voucher: المخصص لفاتورة #',
                        CAST(v_inv_id AS CHAR), ' = ', CAST(v_alloc_amount AS CHAR),
                        ' > المتبقي = ', CAST(v_alloc_rem AS CHAR));
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @err_msg;
                END IF;
                CALL sp_recalculate_invoice_payment(v_inv_id);
            END IF;
        END IF;
    END LOOP;
    CLOSE cur;
    SET v_after_json = JSON_OBJECT(
        'id',                 p_transaction_id,
        'status',             'posted',
        'posted_by',          CAST(p_posted_by AS CHAR),
        'amount',             CAST(v_amount AS CHAR)
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

DROP PROCEDURE IF EXISTS `sp_unpost_invoice`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_unpost_invoice` (IN `p_invoice_id` INT, IN `p_posted_by` INT)  MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '[PATCH:TX_ADDED] إلغاء ترحيل فاتورة — TX ADDED — تدقيق + IP' sp_unpost_invoice_body:BEGIN
    DECLARE v_status          VARCHAR(20);
    DECLARE v_before_json     JSON;
    DECLARE v_after_json      JSON;
    DECLARE v_cancelled_ip    VARCHAR(45) DEFAULT NULL;
    DECLARE v_cancelled_ua    TEXT        DEFAULT NULL;
    DECLARE v_created_by      INT;
    DECLARE v_inv_id          INT;
    DECLARE done INT DEFAULT 0;
    DECLARE cur CURSOR FOR
        SELECT DISTINCT pa.invoice_id
          FROM payment_allocations pa
          JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
         WHERE ft.reference_id   = p_invoice_id
           AND ft.reference_type = 'invoice'
           AND ft.transaction_type IN ('invoice','purchase');
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    IF p_invoice_id IS NULL OR p_invoice_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_unpost_invoice: معرف الفاتورة غير صالح';
    END IF;
    SELECT invoice_status, COALESCE(created_by,0)
      INTO v_status, v_created_by
      FROM invoices
     WHERE id = p_invoice_id
     LIMIT 1;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_unpost_invoice: الفاتورة غير موجودة';
    END IF;
    IF v_status = 'draft' THEN LEAVE sp_unpost_invoice_body; END IF;
    IF p_posted_by IS NOT NULL THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            SET v_cancelled_ip = (SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '') ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_posted_by LIMIT 1);
        END;
    END IF;
    SET v_before_json = JSON_OBJECT(
        'id',               p_invoice_id,
        'invoice_status',   v_status
    );
    DELETE jl
      FROM journal_lines jl
      JOIN financial_transactions ft
        ON ft.id = jl.financial_transaction_id
     WHERE ft.reference_id   = p_invoice_id
       AND ft.reference_type = 'invoice'
       AND ft.transaction_type IN ('invoice','purchase');
    UPDATE financial_transactions
       SET status        = 'cancelled',
           cancelled_by  = p_posted_by,
           cancelled_at  = NOW(),
           cancelled_ip  = v_cancelled_ip,
           updated_ip    = v_cancelled_ip
     WHERE reference_id = p_invoice_id
       AND reference_type = 'invoice'
       AND transaction_type IN ('invoice','purchase');
    UPDATE invoices
       SET invoice_status = 'draft',
           posted_by   = NULL,
           posted_at   = NULL
     WHERE id = p_invoice_id;
    SET done = 0;
    OPEN cur;
    cur_loop: LOOP
        FETCH cur INTO v_inv_id;
        IF done = 1 THEN LEAVE cur_loop; END IF;
        IF v_inv_id IS NOT NULL THEN
            CALL sp_recalculate_invoice_payment(v_inv_id);
        END IF;
    END LOOP;
    CLOSE cur;
    SET v_after_json = JSON_OBJECT(
        'id',               p_invoice_id,
        'invoice_status',   'draft',
        'cancelled_by',     CAST(p_posted_by AS CHAR),
        'cancelled_ip',     v_cancelled_ip
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
    CALL sp_update_account_balances();

    COMMIT;
END$$

DELIMITER ;
