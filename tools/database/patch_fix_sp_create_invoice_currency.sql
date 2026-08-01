-- ==========================================================
-- PATCH: إصلاح خطأ sp_create_invoice — شرط عملة الحساب
-- المشكلة: كان الإجراء يرفض إنشاء الفاتورة إذا لم يكن هناك
-- سجل رصيد بعملة الفاتورة المطلوبة لحساب العميل/المورد،
-- حتى لو كانت هناك عملات أخرى موجودة.
-- الحل: بدلاً من إرجاع خطأ، نقوم بإضافة عملة الفاتورة
-- تلقائياً برصيد 0 إلى account_balances_unified إذا لم تكن موجودة
-- ==========================================================

DROP PROCEDURE IF EXISTS `sp_create_invoice`;

DELIMITER $$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_invoice`(
    IN `p_invoice_category` ENUM('sales','purchase'),
    IN `p_branch_id` INT,
    IN `p_source_type` VARCHAR(100),
    IN `p_source_id` INT,
    IN `p_customer_id` INT,
    IN `p_supplier_id` INT,
    IN `p_agent_id` INT,
    IN `p_service_id` INT,
    IN `p_currency_id` INT,
    IN `p_total_amount` DECIMAL(18,2),
    IN `p_discount` DECIMAL(15,2),
    IN `p_cost_amount` DECIMAL(15,2),
    IN `p_payment_type` VARCHAR(50),
    IN `p_description` TEXT,
    IN `p_created_by` INT,
    IN `p_cost_center_id` INT,
    IN `p_invoice_number` VARCHAR(50),
    OUT `p_invoice_id` INT
)
MODIFIES SQL DATA
SQL SECURITY INVOKER
sp_create_invoice_body:BEGIN
    DECLARE v_net_amount        DECIMAL(15,2);
    DECLARE v_party_account_id  INT;
    DECLARE v_tax_amount        DECIMAL(15,2) DEFAULT 0;
    DECLARE v_account_id        INT           DEFAULT NULL;
    DECLARE v_customer_acct_id  INT           DEFAULT NULL;
    DECLARE v_supplier_acct_id  INT           DEFAULT NULL;
    DECLARE v_payment_status    VARCHAR(20)   DEFAULT 'unpaid';
    DECLARE v_created_ip        VARCHAR(45)   DEFAULT NULL;
    DECLARE v_created_ua        TEXT          DEFAULT NULL;

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

    -- جلب رقم حساب العميل أو المورد
    IF p_invoice_category = 'sales' THEN
        SET v_party_account_id = (SELECT account_id FROM customers WHERE id = p_customer_id);
    ELSE
        SET v_party_account_id = (SELECT account_id FROM suppliers WHERE id = p_supplier_id);
    END IF;

    SET v_account_id        = v_party_account_id;
    SET v_customer_acct_id  = CASE WHEN p_invoice_category='sales'    THEN v_party_account_id ELSE NULL END;
    SET v_supplier_acct_id  = CASE WHEN p_invoice_category='purchase' THEN v_party_account_id ELSE NULL END;

    -- ============== PATCH FIX: عملة الفاتورة / عملة الحساب ==============
    -- إذا كان هناك حساب للطرف و عملة للفاتورة و لم يكن هناك سجل رصيد بعملة الفاتورة،
    -- نقوم بإضافته تلقائياً برصيد 0 بدلاً من إرجاع خطأ 1644 (كان يوقف كل الفواتير الجديدة)
    IF v_party_account_id IS NOT NULL AND p_currency_id IS NOT NULL THEN
        BEGIN
            DECLARE v_exists INT DEFAULT 0;
            SELECT COUNT(*) INTO v_exists
              FROM account_balances_unified
             WHERE account_id = v_party_account_id AND currency_id = p_currency_id;
            IF v_exists = 0 THEN
                INSERT IGNORE INTO account_balances_unified
                    (account_id, currency_id, balance, debit_total, credit_total, debit_count, credit_count, last_updated)
                VALUES
                    (v_party_account_id, p_currency_id, 0, 0, 0, 0, 0, NOW());
            END IF;
        END;
    END IF;
    -- ============== نهاية الإصلاح ==============

    -- جلب آخر عنوان IP لهذا المستخدم من سجلات التدقيق (إن وجد)
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

DELIMITER ;
