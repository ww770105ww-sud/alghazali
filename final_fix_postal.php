<?php
require_once 'includes/db.php';

try {
    // Drop the existing procedure first (safely)
    $stmt = $pdo->prepare("DROP PROCEDURE IF EXISTS sp_post_invoice");
    $stmt->execute();

    // Now recreate it with the postal service fix
    $sql = "CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_invoice` (IN `p_invoice_id` INT, IN `p_posted_by` INT)
PROC_BODY: BEGIN
    DECLARE v_invoice_category ENUM('sales','purchase');
    DECLARE v_invoice_number VARCHAR(50);
    DECLARE v_invoice_date DATE;
    DECLARE v_branch_id INT;
    DECLARE v_source_type VARCHAR(50);
    DECLARE v_payment_type ENUM('cash','credit','bank_transfer','agent','branch','draft');
    DECLARE v_customer_id INT;
    DECLARE v_supplier_id INT;
    DECLARE v_agent_id INT;
    DECLARE v_branch_entity_id INT;
    DECLARE v_currency_id INT;
    DECLARE v_total_amount DECIMAL(15,2);
    DECLARE v_discount DECIMAL(15,2);
    DECLARE v_net_amount DECIMAL(15,2);
    DECLARE v_description TEXT;
    DECLARE v_transaction_id INT;
    DECLARE v_transaction_number VARCHAR(50);
    DECLARE v_status VARCHAR(50);
    DECLARE v_account_id INT;
    DECLARE v_customer_account_id INT;
    DECLARE v_supplier_account_id INT;
    DECLARE v_amount_received DECIMAL(15,2);
    
    DECLARE v_revenue_account_id INT DEFAULT NULL;
    DECLARE v_cost_account_id INT DEFAULT NULL;
    DECLARE v_cash_account_id INT DEFAULT NULL;
    DECLARE v_bank_account_id INT DEFAULT NULL;
    
    DECLARE v_acc_exists INT DEFAULT 0;
    DECLARE v_existing_ft_id INT DEFAULT NULL;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    SELECT invoice_category, invoice_number, invoice_date, branch_id, source_type,
           delivery_type, customer_id, supplier_id, agent_id, branch_entity_id,
           currency_id, total_amount, discount, description, invoice_status,
           account_id, customer_account_id, supplier_account_id,
           COALESCE(amount_received, 0)
    INTO v_invoice_category, v_invoice_number, v_invoice_date, v_branch_id, v_source_type,
         v_payment_type, v_customer_id, v_supplier_id, v_agent_id, v_branch_entity_id,
         v_currency_id, v_total_amount, v_discount, v_description, v_status,
         v_account_id, v_customer_account_id, v_supplier_account_id,
         v_amount_received
    FROM invoices WHERE id = p_invoice_id;
    
    IF v_invoice_category IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفاتورة غير موجودة';
    END IF;
    
    IF v_status = 'posted' THEN
        LEAVE PROC_BODY;
    END IF;

    SELECT id INTO v_existing_ft_id
    FROM financial_transactions
    WHERE reference_type = 'invoice'
      AND reference_id = p_invoice_id
      AND status IN ('draft', 'posted')
    LIMIT 1;

    IF v_existing_ft_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'توجد حركة مالية مرتبطة بهذه الفاتورة بالفعل';
    END IF;

    SET v_net_amount = v_total_amount - v_discount;
    
    START TRANSACTION;
    
    -- Determine revenue and cost accounts based on source type (including postal!)
    IF v_source_type IN ('bus', 'حجوزات الباصات') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_bus_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_bus_account_id';
    ELSEIF v_source_type IN ('flight', 'الطيران', 'حجوزات الطيران') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_flight_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_flight_account_id';
    ELSEIF v_source_type IN ('BusFlight', 'bus_flight', 'busflight', 'تذاكر طيران وبصات', 'حجوزات الباصات والطيران', 'النقل البري') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_flight_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_flight_account_id';
    ELSEIF v_source_type IN ('umrah', 'حج وعمرة', 'قسم العمرة', 'خدمات العمرة', 'خدمات الحج والعمرة') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_umrah_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_umrah_account_id';
    ELSEIF v_source_type IN ('work_visa', 'فيز العمل') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_work_visa_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_work_visa_account_id';
    ELSEIF v_source_type IN ('FamilyVisit', 'family_visit', 'الزيارة العائلية') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_family_visit_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_family_visit_account_id';
    ELSEIF v_source_type IN ('Passport', 'passport', 'جوازت السفر', 'معاملات جوازات') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'passports_revenue_account';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'passports_cost_account';
    ELSEIF v_source_type IN ('postal_services', 'postal', 'الخدمات البريدية', 'خدمات البريد') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_postal_services_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_postal_services_account_id';
    ELSE
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_bus_account_id' LIMIT 1;
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_bus_account_id' LIMIT 1;
    END IF;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_cash_account_id FROM system_settings WHERE setting_key = 'default_cash_account_id';
    SELECT CAST(setting_value AS UNSIGNED) INTO v_bank_account_id FROM system_settings WHERE setting_key = 'default_bank_account_id';

    IF v_account_id IS NULL THEN
        IF v_invoice_category = 'sales' THEN SET v_account_id = v_customer_account_id;
        ELSE SET v_account_id = v_supplier_account_id; END IF;
    END IF;

    SET v_transaction_number = fn_get_next_sequence('journal');
    INSERT INTO financial_transactions (
        transaction_number, transaction_date, branch_id,
        transaction_type, status, reference_type, reference_id,
        currency_id, amount, description, created_by, posted_at, posted_by
    ) VALUES (
        v_transaction_number, v_invoice_date, v_branch_id,
        'invoice', 'posted', 'invoice', p_invoice_id,
        v_currency_id, v_net_amount,
        CONCAT('إثبات فاتورة رقم ', v_invoice_number, ' - ', IFNULL(v_description,'')),
        p_posted_by, NOW(), p_posted_by
    );
    SET v_transaction_id = LAST_INSERT_ID();

    IF v_invoice_category = 'sales' THEN
        INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
        VALUES (v_transaction_id, v_revenue_account_id, 0, v_net_amount, v_currency_id, CONCAT('إيراد خدمات - فاتورة ', v_invoice_number));

        IF v_amount_received >= v_net_amount THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, v_net_amount, 0, v_currency_id, CONCAT('تحصيل كامل - فاتورة ', v_invoice_number));
        ELSEIF v_amount_received = 0 THEN
            SET v_acc_exists = IFNULL(v_customer_account_id, v_account_id);
            IF (v_acc_exists IS NULL OR v_acc_exists = 0) AND v_customer_id IS NOT NULL THEN
                SELECT account_id INTO v_acc_exists FROM customers WHERE id = v_customer_id;
            END IF;
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, v_net_amount, 0, v_currency_id, CONCAT('مبيعات آجلة - فاتورة ', v_invoice_number));
        ELSE
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, v_amount_received, 0, v_currency_id, CONCAT('تحصيل جزئي (واصل) - فاتورة ', v_invoice_number));
            
            SET v_acc_exists = v_customer_account_id;
            IF (v_acc_exists IS NULL OR v_acc_exists = 0 OR v_acc_exists = v_account_id) AND v_customer_id IS NOT NULL THEN
                SELECT account_id INTO v_acc_exists FROM customers WHERE id = v_customer_id;
            END IF;
            IF v_acc_exists IS NULL OR v_acc_exists = 0 THEN
                SELECT CAST(setting_value AS UNSIGNED) INTO v_acc_exists FROM system_settings WHERE setting_key = 'customer_receivable_account_id' LIMIT 1;
            END IF;

            IF v_acc_exists IS NOT NULL AND v_acc_exists > 0 THEN
                INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
                VALUES (v_transaction_id, v_acc_exists, (v_net_amount - v_amount_received), 0, v_currency_id, CONCAT('متبقي مديونية - فاتورة ', v_invoice_number));
            ELSE
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ: تعذر العثور على حساب ذمم مدينة لتسجيل المتبقي.';
            END IF;
        END IF;
    ELSE
        INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
        VALUES (v_transaction_id, v_cost_account_id, v_net_amount, 0, v_currency_id, CONCAT('تكلفة خدمات - فاتورة ', v_invoice_number));

        IF v_amount_received >= v_net_amount THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, 0, v_net_amount, v_currency_id, CONCAT('سداد نقدي كامل - فاتورة ', v_invoice_number));
        ELSEIF v_amount_received = 0 THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, 0, v_net_amount, v_currency_id, CONCAT('استحقاق مورد آجل - فاتورة ', v_invoice_number));
        ELSE
            SET v_acc_exists = CASE WHEN v_payment_type = 'bank_transfer' THEN v_bank_account_id ELSE v_cash_account_id END;
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, 0, v_amount_received, v_currency_id, CONCAT('سداد جزئي - فاتورة ', v_invoice_number));
            
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, 0, (v_net_amount - v_amount_received), v_currency_id, CONCAT('متبقي استحقاق للمورد - فاتورة ', v_invoice_number));
        END IF;
    END IF;

    UPDATE invoices SET invoice_status = 'posted', posted_at = NOW(), posted_by = p_posted_by WHERE id = p_invoice_id;
    COMMIT;
END";

    $pdo->exec($sql);

    // Now add the missing system settings
    $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
    $stmt->execute(['revenue_postal_services_account_id', null]);
    $stmt->execute(['cost_postal_services_account_id', null]);

    echo "Successfully applied the fix for postal services!";

} catch (Exception $e) {
    echo "Error applying fix: " . $e->getMessage();
}
?>