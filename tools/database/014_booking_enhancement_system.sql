-- =============================================================
-- 014_booking_enhancement_system.sql
-- نظام تحسينات الحجوزات المتكامل (تأكيد / تعديل / إلغاء / استرداد / تذاكر / إشعارات / سير عمل)
-- =============================================================
-- تاريخ الإصدار : 2026-07-31
-- التوافق       : MariaDB 10.4+ / MySQL 8.0+
-- الترميز       : utf8mb4_unicode_ci
-- المطلوب      : تشغيل هذا الملف بعد أن يكن sp_log_error موجوداً
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================
-- [الجزء الأول] الأعمدة الجديدة لجدول bus_flight_bookings
-- =============================================================

-- (1) أعمدة نظام التأكيد
ALTER TABLE `bus_flight_bookings`
    ADD COLUMN IF NOT EXISTS `is_confirmed`      TINYINT(1)       DEFAULT 0                 COMMENT 'هل الحجز مؤكد؟'
    AFTER `is_cancelled`,
    ADD COLUMN IF NOT EXISTS `confirmed_at`      DATETIME         NULL                    COMMENT 'تاريخ ووقت التأكيد'
    AFTER `is_confirmed`,
    ADD COLUMN IF NOT EXISTS `confirmed_by`      INT(11)          NULL                    COMMENT 'معرف المستخدم الذي أكد الحجز'
    AFTER `confirmed_at`,
    ADD COLUMN IF NOT EXISTS `confirmation_method` VARCHAR(50)    DEFAULT 'manual'          COMMENT 'طريقة التأكيد: manual | online | phone | email | whatsapp | sms'
    AFTER `confirmed_by`;

-- (2) أعمدة نظام الإلغاء والاسترداد المالي
ALTER TABLE `bus_flight_bookings`
    ADD COLUMN IF NOT EXISTS `refund_status`        VARCHAR(30)    DEFAULT 'none'                COMMENT 'حالة الاسترداد: none | requested | approved | processed | rejected'
    AFTER `cancel_datetime`,
    ADD COLUMN IF NOT EXISTS `refund_amount`        DECIMAL(18,4)  DEFAULT 0.0000              COMMENT 'المبلغ المرتجع عند الإلغاء'
    AFTER `refund_status`,
    ADD COLUMN IF NOT EXISTS `refund_processed_at`  DATETIME       NULL                        COMMENT 'تاريخ وإتمام عملية الاسترداد'
    AFTER `refund_amount`;

-- (3) أعمدة سير عمل الحجز
ALTER TABLE `bus_flight_bookings`
    ADD COLUMN IF NOT EXISTS `booking_stage`      VARCHAR(30)      DEFAULT 'pending'           COMMENT 'مرحلة الحجز: pending | confirmed | ticketed | departed | completed | cancelled | modified'
    AFTER `workflow_id`,
    ADD COLUMN IF NOT EXISTS `last_stage_changed_at` DATETIME      NULL                        COMMENT 'آخر وقت تم فيه تغيير المرحلة'
    AFTER `booking_stage`,
    ADD COLUMN IF NOT EXISTS `last_stage_changed_by` INT(11)       NULL                        COMMENT 'آخر مستخدم غير المرحلة'
    AFTER `last_stage_changed_at`;

-- =============================================================
-- [الجزء الثاني] إنشاء الجداول الجديدة (6 جداول)
-- =============================================================

-- (1) جدول سير عمل الحجز (workflow stages log)
DROP TABLE IF EXISTS `booking_workflow`;
CREATE TABLE `booking_workflow` (
    `id`                  INT(11)          NOT NULL AUTO_INCREMENT,
    `booking_id`          INT(11)          NOT NULL                COMMENT 'معرف الحجز',
    `from_stage`          VARCHAR(30)      NULL                    COMMENT 'المرحلة السابقة',
    `to_stage`            VARCHAR(30)      NOT NULL                COMMENT 'المرحلة الحالية',
    `transition_notes`    TEXT             NULL                    COMMENT 'ملاحظات الانتقال بين المرحلتين',
    `extra_data`          JSON             NULL                    COMMENT 'بيانات إضافية بصيغة JSON (خصم، ضريبة...)',
    `performed_by`        INT(11)          NOT NULL                COMMENT 'الذي قام بالانتقال',
    `performed_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_booking_workflow_booking` (`booking_id`),
    KEY `idx_booking_workflow_stage`   (`to_stage`),
    CONSTRAINT `fk_booking_workflow_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `bus_flight_bookings`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='سير عمل الحجز (تتبع المراحل من الإنشاء إلى الإكمال أو الإلغاء)';


-- (2) جدول طلبات تعديل الحجز
DROP TABLE IF EXISTS `booking_modifications`;
CREATE TABLE `booking_modifications` (
    `id`                      INT(11)          NOT NULL AUTO_INCREMENT,
    `booking_id`              INT(11)          NOT NULL                COMMENT 'الحجز المطلوب تعديله',
    `requested_by`            INT(11)          NOT NULL                COMMENT 'من طلب التعديل (موظف)',
    `modification_reason`     TEXT             NOT NULL                COMMENT 'سبب التعديل',

    -- البيانات الجديدة المقترحة (تُنقل إلى الحجز عند الموافقة)
    `new_from_city_id`        INT(11)          NULL,
    `new_to_city_id`          INT(11)          NULL,
    `new_departure_date`      DATE             NULL,
    `new_return_date`         DATE             NULL,
    `new_trip_type`           ENUM('one_way','round_trip') NULL,
    `new_service_type`        ENUM('bus','flight') NULL,
    `new_bus_type`            ENUM('tourist','regular') NULL,
    `new_ticket_price`        DECIMAL(18,4)    DEFAULT NULL            COMMENT 'التكلفة الجديدة للمسافر',
    `new_notes`               TEXT             NULL,
    `price_difference`        DECIMAL(18,4)    DEFAULT 0.0000          COMMENT 'الفرق المالي ( موجب = زيادة / سالب = خصم )',

    -- موافقة
    `approval_status`         VARCHAR(30)      DEFAULT 'pending'       COMMENT 'pending | approved | rejected | cancelled',
    `reviewed_by`             INT(11)          NULL,
    `reviewed_at`             DATETIME         NULL,
    `review_notes`            TEXT             NULL,

    -- الارتباطات المالية (عند الموافقة)
    `charge_invoice_id`       INT(11)          NULL                    COMMENT 'فاتورة فرق الزيادة (مبيعات)',
    `refund_voucher_id`       INT(11)          NULL                    COMMENT 'سند استرداد فرق الخصم',

    `created_at`              DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_booking_mod_booking`  (`booking_id`),
    KEY `idx_booking_mod_status`   (`approval_status`),
    CONSTRAINT `fk_booking_mod_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `bus_flight_bookings`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='طلبات تعديل الحجوزات مع الموافقة';


-- (3) جدول طلبات الاسترداد عند الإلغاء
DROP TABLE IF EXISTS `booking_refunds`;
CREATE TABLE `booking_refunds` (
    `id`                  INT(11)          NOT NULL AUTO_INCREMENT,
    `booking_id`          INT(11)          NOT NULL                COMMENT 'الحجز الملغى',
    `receipt_voucher_id`  INT(11)          NULL                    COMMENT 'رقم سند القبض الأصلي (الذي تم استلام المبلغ منه)',
    `payment_voucher_id`  INT(11)          NULL                    COMMENT 'رقم سند الصرف للاسترداد (عند الإتمام)',
    `refund_amount`       DECIMAL(18,4)    NOT NULL                COMMENT 'المبلغ المرتجع',
    `refund_currency_id`  INT(11)          NOT NULL                COMMENT 'عملة الاسترداد',
    `customer_account_id` INT(11)          NOT NULL                COMMENT 'حساب العميل',
    `cash_bank_account_id`INT(11)          NOT NULL                COMMENT 'حساب الصندوق/البنك الذي سيُدفع منه الاسترداد',
    `refund_method`       VARCHAR(30)      DEFAULT 'cash'          COMMENT 'cash | bank_transfer | wallet | cheque | card',
    `refund_reason`       TEXT             NOT NULL                COMMENT 'سبب الاسترداد (عادة سبب الإلغاء نفسه)',
    `is_partial`          TINYINT(1)       DEFAULT 0               COMMENT 'استرداد جزئي؟',

    -- حالة الموافقة
    `requested_by`        INT(11)          NOT NULL,
    `requested_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status`              VARCHAR(30)      DEFAULT 'requested'     COMMENT 'requested | approved | processed | rejected',
    `approved_by`         INT(11)          NULL,
    `approved_at`         DATETIME         NULL,
    `processed_by`        INT(11)          NULL,
    `processed_at`        DATETIME         NULL,
    `approval_notes`      TEXT             NULL,

    -- الارتباط المحاسبي
    `financial_transaction_id` INT(11)     NULL                    COMMENT 'رقم المعاملة المالية الناتجة',

    `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_booking_ref_booking`  (`booking_id`),
    KEY `idx_booking_ref_status`   (`status`),
    CONSTRAINT `fk_booking_ref_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `bus_flight_bookings`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='طلبات استرداد أموال الحجوزات الملغاة';


-- (4) جدول إشعارات الحجوزات
DROP TABLE IF EXISTS `booking_notifications`;
CREATE TABLE `booking_notifications` (
    `id`                  INT(11)          NOT NULL AUTO_INCREMENT,
    `booking_id`          INT(11)          NOT NULL                COMMENT 'الحجز المرتبط بالإشعار',
    `customer_id`         INT(11)          NULL,
    `user_id`             INT(11)          NULL                    COMMENT 'إذا كان الإشعار لمستخدم داخلي (موظف)',

    `notification_type`   VARCHAR(30)      NOT NULL                COMMENT 'confirmation | reminder | cancellation | modification | payment | departure | completed',
    `delivery_channel`    VARCHAR(30)      NOT NULL DEFAULT 'system' COMMENT 'email | whatsapp | sms | system',

    `subject_text`        VARCHAR(500)     NULL                    COMMENT 'عنوان الرسالة (موضوع البريد أو الرمز القصير)',
    `body_text`           TEXT             NOT NULL                COMMENT 'نص الإشعار',
    `extra_data`          JSON             NULL                    COMMENT 'بيانات إضافية كـ variables / template_id',

    -- الحالة
    `status`              VARCHAR(30)      DEFAULT 'pending'       COMMENT 'pending | sent | failed | delivered | read',
    `retry_count`         TINYINT(1)       DEFAULT 0               COMMENT 'عدد محاولات إعادة الإرسال',
    `last_error`          TEXT             NULL                    COMMENT 'خطأ آخر إن وجد',
    `provider_message_id` VARCHAR(255)     NULL                    COMMENT 'رمز الإثبات من مزود الخدمة',

    -- الجدولة (لتذكيرات المغادرة مثلاً)
    `scheduled_at`        DATETIME         NULL                    COMMENT 'وقت الإرسال المخطط له',
    `sent_at`             DATETIME         NULL                    COMMENT 'وقت الإرسال الفعلي',
    `delivered_at`        DATETIME         NULL,
    `read_at`             DATETIME         NULL,

    `sent_by`             INT(11)          NULL                    COMMENT 'من أرسل الإشعار (يدوياً أو 0 لو آلي)',
    `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_booking_notif_booking`      (`booking_id`),
    KEY `idx_booking_notif_type`         (`notification_type`),
    KEY `idx_booking_notif_status`       (`status`),
    KEY `idx_booking_notif_scheduled`    (`scheduled_at`),
    CONSTRAINT `fk_booking_notif_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `bus_flight_bookings`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='إشعارات الحجوزات (بريد/واتساب/رسائل/نظام)';


-- (5) جدول التذاكر الرقمية
DROP TABLE IF EXISTS `booking_tickets`;
CREATE TABLE `booking_tickets` (
    `id`                  INT(11)          NOT NULL AUTO_INCREMENT,
    `booking_id`          INT(11)          NOT NULL                COMMENT 'الحجز المرتبط بالتذكرة',
    `ticket_number`       VARCHAR(64)      NOT NULL                COMMENT 'رقم تذكرة فريد (مثال: TKT-BF-2026-00001)',

    -- معلومات الرحلة
    `service_type`        ENUM('bus','flight') NOT NULL,
    `trip_type`           ENUM('one_way','round_trip') NOT NULL,
    `departure_city_name` VARCHAR(200)     NOT NULL                COMMENT 'اسم مدينة الانطلاق (محفوظ نصياً كـ snapshot)',
    `arrival_city_name`   VARCHAR(200)     NOT NULL                COMMENT 'اسم مدينة الوصول',
    `departure_datetime`  DATETIME         NULL,
    `return_datetime`     DATETIME         NULL,
    `seat_number`         VARCHAR(30)      NULL                    COMMENT 'رقم المقعد',
    `pnr`                 VARCHAR(100)     NULL                    COMMENT 'رقم الحجز عند المورد',
    `supplier_reference`  VARCHAR(200)     NULL                    COMMENT 'مرجع المورد',
    `bus_flight_number`   VARCHAR(100)     NULL                    COMMENT 'رقم الرحلة أو رقم الباص',

    -- بيانات المسافر (كـ snapshot)
    `traveler_name`       VARCHAR(255)     NOT NULL,
    `id_type`             ENUM('passport','national_id') NOT NULL,
    `id_number`           VARCHAR(100)     NOT NULL,

    -- الأمان والتحقق
    `qr_code_data`        VARCHAR(500)     NULL                    COMMENT 'البيانات التي يُبنى عليها الـ QR (رابط التحقق أو JSON مُشفّر)',
    `verification_token`  VARCHAR(100)     NULL                    COMMENT 'رمز تحقق فريد لاستخدام الرابط العام',
    `ticket_hash`         VARCHAR(255)     NULL                    COMMENT 'توقيع رقمي بسيط للتأكد من عدم التلاعب',

    -- المالية
    `currency_code`       VARCHAR(10)      DEFAULT 'SAR',
    `ticket_price`        DECIMAL(18,4)    DEFAULT 0.0000,
    `tax_amount`          DECIMAL(18,4)    DEFAULT 0.0000,
    `total_amount`        DECIMAL(18,4)    DEFAULT 0.0000,

    -- حالة الطباعة
    `issued_by`           INT(11)          NOT NULL,
    `issued_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `first_printed_at`    DATETIME         NULL,
    `reprint_count`       INT(11)          DEFAULT 0,
    `is_void`             TINYINT(1)       DEFAULT 0,

    `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_booking_tickets_number` (`ticket_number`),
    UNIQUE KEY `uk_booking_tickets_token`  (`verification_token`),
    KEY `idx_booking_tickets_booking`      (`booking_id`),
    CONSTRAINT `fk_booking_tickets_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `bus_flight_bookings`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='التذاكر الرقمية للحجوزات مع QR ورمز تحقق';


-- =============================================================
-- [الجزء الثالث] المؤشرات الإضافية لتحسين الأداء
-- =============================================================

ALTER TABLE `bus_flight_bookings`
    ADD INDEX IF NOT EXISTS `idx_bf_booking_stage`    (`booking_stage`),
    ADD INDEX IF NOT EXISTS `idx_bf_confirmed`        (`is_confirmed`),
    ADD INDEX IF NOT EXISTS `idx_bf_cancelled`        (`is_cancelled`),
    ADD INDEX IF NOT EXISTS `idx_bf_departure_date`   (`departure_date`),
    ADD INDEX IF NOT EXISTS `idx_bf_refund_status`    (`refund_status`);

ALTER TABLE `booking_status_logs`
    ADD INDEX IF NOT EXISTS `idx_bsl_booking_created` (`booking_id`,`created_at`);


-- =============================================================
-- [الجزء الرابع] الإجراءات المخزنة (Stored Procedures)
-- =============================================================

DELIMITER $$

-- =============================================================
-- (1) sp_confirm_booking — تأكيد الحجز
-- =============================================================
DROP PROCEDURE IF EXISTS `sp_confirm_booking`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_confirm_booking`(
    IN `p_booking_id`           INT,
    IN `p_user_id`              INT,
    IN `p_confirmation_method`  VARCHAR(50)
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الحجوزات] تأكيد الحجز + تسجيل سير عمل + إرسال إشعار تأكيد'
sp_confirm_booking_body:
BEGIN
    DECLARE v_booking_exists        INT DEFAULT 0;
    DECLARE v_is_confirmed          TINYINT(1) DEFAULT 0;
    DECLARE v_is_cancelled          TINYINT(1) DEFAULT 0;
    DECLARE v_current_stage         VARCHAR(30);
    DECLARE v_traveler_name         VARCHAR(255);
    DECLARE v_mobile_number         VARCHAR(50);
    DECLARE v_booking_number        VARCHAR(50);
    DECLARE v_departure_date        DATE;
    DECLARE v_before_json           JSON;
    DECLARE v_after_json            JSON;
    DECLARE v_ctx                   JSON;
    DECLARE v_audit_ip              VARCHAR(45) DEFAULT NULL;
    DECLARE v_notif_body            TEXT;
    DECLARE v_notif_subject         VARCHAR(500);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'booking_id',          CAST(p_booking_id AS CHAR),
            'user_id',             CAST(p_user_id AS CHAR),
            'confirmation_method', COALESCE(p_confirmation_method, ''),
            'mysql_errno',         CAST(@err_no AS CHAR),
            'sqlstate',            @err_sqlstate
        );
        CALL sp_log_error('sp_confirm_booking', @err_msg, p_user_id, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر تأكيد الحجز. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    -- (أ) التحقق من البيانات المدخلة
    IF p_booking_id IS NULL OR p_booking_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف الحجز غير صالح.';
    END IF;
    IF p_user_id IS NULL OR p_user_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف المستخدم غير صالح.';
    END IF;
    IF p_confirmation_method NOT IN ('manual','online','phone','email','whatsapp','sms') THEN
        SET p_confirmation_method = 'manual';
    END IF;

    -- (ب) جلب الحجز
    SELECT 1, is_confirmed, is_cancelled, booking_stage,
           traveler_name, mobile_number, booking_number, departure_date
      INTO v_booking_exists, v_is_confirmed, v_is_cancelled, v_current_stage,
           v_traveler_name, v_mobile_number, v_booking_number, v_departure_date
      FROM bus_flight_bookings
     WHERE id = p_booking_id
     LIMIT 1;

    IF v_booking_exists <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز غير موجود.';
    END IF;

    IF v_is_cancelled = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'لا يمكن تأكيد حجز ملغى.';
    END IF;

    IF v_is_confirmed = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز مؤكد مسبقاً.';
    END IF;

    IF v_current_stage = 'cancelled' OR v_current_stage = 'completed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'المرحلة الحالية للحجز لا تسمح بالتأكيد.';
    END IF;

    -- (ج) جلب صورة قبل للتدقيق
    SET v_before_json = (SELECT JSON_OBJECT(
        'is_confirmed',   CAST(is_confirmed AS CHAR),
        'confirmed_at',   COALESCE(DATE_FORMAT(confirmed_at,'%Y-%m-%d %H:%i:%s'),''),
        'booking_stage',  COALESCE(booking_stage,'')
    ) FROM bus_flight_bookings WHERE id = p_booking_id);

    -- (د) تحديث الحجز
    UPDATE bus_flight_bookings
       SET is_confirmed         = 1,
           confirmed_at         = NOW(),
           confirmed_by         = p_user_id,
           confirmation_method  = p_confirmation_method,
           confirm_datetime     = NOW(),
           booking_stage        = 'confirmed',
           last_stage_changed_at = NOW(),
           last_stage_changed_by = p_user_id
     WHERE id = p_booking_id;

    -- (هـ) تسجيل سير العمل
    INSERT INTO booking_workflow (
        booking_id, from_stage, to_stage, transition_notes,
        extra_data, performed_by, performed_at
    ) VALUES (
        p_booking_id, v_current_stage, 'confirmed',
        CONCAT('تم تأكيد الحجز بطريقة: ', p_confirmation_method),
        JSON_OBJECT('confirmation_method', p_confirmation_method),
        p_user_id, NOW()
    );

    -- (و) سجل تغيير الحالة (booking_status_logs — القديم للتوافق)
    INSERT INTO booking_status_logs (
        booking_id, old_status_id, new_status_id,
        changed_by, notes, created_at
    ) VALUES (
        p_booking_id, NULL, NULL,
        p_user_id, CONCAT('التأكيد — ', p_confirmation_method), NOW()
    );

    -- (ز) صورة بعد + سجل التدقيق العام
    SET v_after_json = (SELECT JSON_OBJECT(
        'is_confirmed',        '1',
        'confirmed_at',        DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'),
        'confirmed_by',        CAST(p_user_id AS CHAR),
        'confirmation_method', p_confirmation_method,
        'booking_stage',       'confirmed'
    ) FROM DUAL);

    -- استخراج آخر IP للمستخدم من سجلات التدقيق (إن وجد)
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SET v_audit_ip = (
            SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_user_id LIMIT 1
        );
    END;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        p_user_id, 'confirm', 'bus_flight_bookings', p_booking_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_audit_ip, NULL, NOW()
    );

    -- (ح) إنشاء إشعار تأكيد للعميل
    SET v_notif_subject = CONCAT('تأكيد حجز رقم ', v_booking_number);
    SET v_notif_body = CONCAT(
        'عزيزي المسافر ', v_traveler_name, ',\n',
        'تم تأكيد حجزك بنجاح برقم ', v_booking_number, '.\n',
        'تاريخ الانطلاق: ', IFNULL(DATE_FORMAT(v_departure_date,'%Y-%m-%d'), 'غير محدد'), '\n',
        'يرجى الإحتفاظ بهذا الإشعار.\n',
        'مع تحيات إدارة الشركة.'
    );
    INSERT INTO booking_notifications (
        booking_id, customer_id, user_id,
        notification_type, delivery_channel,
        subject_text, body_text,
        status, scheduled_at, sent_at,
        sent_by, created_at
    ) VALUES (
        p_booking_id, NULL, NULL,
        'confirmation', 'system',
        v_notif_subject, v_notif_body,
        'pending', NOW(), NOW(),
        p_user_id, NOW()
    );

    COMMIT;
END$$


-- =============================================================
-- (2) sp_request_booking_modification — تقديم طلب تعديل
-- =============================================================
DROP PROCEDURE IF EXISTS `sp_request_booking_modification`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_request_booking_modification`(
    IN `p_booking_id`            INT,
    IN `p_requested_by`          INT,
    IN `p_modification_reason`   TEXT,
    IN `p_new_from_city_id`      INT,
    IN `p_new_to_city_id`        INT,
    IN `p_new_departure_date`    DATE,
    IN `p_new_return_date`       DATE,
    IN `p_new_trip_type`         VARCHAR(20),
    IN `p_new_service_type`      VARCHAR(20),
    IN `p_new_bus_type`          VARCHAR(20),
    IN `p_new_ticket_price`      DECIMAL(18,4),
    IN `p_new_notes`             TEXT,
    IN `p_old_ticket_price`      DECIMAL(18,4),
    OUT `p_modification_id`      INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الحجوزات] تقديم طلب تعديل حجز (قبل الموافقة)'
sp_req_mod_body:
BEGIN
    DECLARE v_exists           INT DEFAULT 0;
    DECLARE v_is_cancelled     TINYINT(1);
    DECLARE v_is_confirmed     TINYINT(1);
    DECLARE v_price_diff       DECIMAL(18,4) DEFAULT 0;
    DECLARE v_ctx              JSON;
    DECLARE v_audit_ip         VARCHAR(45) DEFAULT NULL;
    DECLARE v_before_json      JSON;
    DECLARE v_after_json       JSON;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'booking_id',   CAST(p_booking_id AS CHAR),
            'requested_by', CAST(p_requested_by AS CHAR),
            'mysql_errno',  CAST(@err_no AS CHAR),
            'sqlstate',     @err_sqlstate
        );
        CALL sp_log_error('sp_request_booking_modification', @err_msg, p_requested_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر تقديم طلب التعديل. يرجى المحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_booking_id IS NULL OR p_booking_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف الحجز غير صالح.';
    END IF;
    IF p_requested_by IS NULL OR p_requested_by <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف المستخدم غير صالح.';
    END IF;
    IF CHAR_LENGTH(TRIM(COALESCE(p_modification_reason,''))) < 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يجب ذكر سبب التعديل.';
    END IF;

    SELECT 1, is_cancelled, is_confirmed
      INTO v_exists, v_is_cancelled, v_is_confirmed
      FROM bus_flight_bookings
     WHERE id = p_booking_id LIMIT 1;

    IF v_exists <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز غير موجود.';
    END IF;
    IF v_is_cancelled = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'لا يمكن تعديل حجز ملغى.';
    END IF;

    -- لا يسمح بطلب تعديل جديد حالياً على نفس الحجز (تجنب التعارض)
    IF EXISTS(SELECT 1 FROM booking_modifications
               WHERE booking_id = p_booking_id
                 AND approval_status = 'pending' LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يوجد طلب تعديل قيد الانتظار على نفس الحجز.';
    END IF;

    -- حساب فرق السعر (إن تم إعطاؤه)
    IF p_new_ticket_price IS NOT NULL AND p_old_ticket_price IS NOT NULL THEN
        SET v_price_diff = p_new_ticket_price - p_old_ticket_price;
    END IF;

    SET v_before_json = JSON_OBJECT('action','create_modification_request');

    INSERT INTO booking_modifications (
        booking_id, requested_by, modification_reason,
        new_from_city_id, new_to_city_id, new_departure_date,
        new_return_date, new_trip_type, new_service_type,
        new_bus_type, new_ticket_price, new_notes, price_difference,
        approval_status, created_at, updated_at
    ) VALUES (
        p_booking_id, p_requested_by, p_modification_reason,
        NULLIF(p_new_from_city_id, 0), NULLIF(p_new_to_city_id, 0), p_new_departure_date,
        p_new_return_date, NULLIF(p_new_trip_type,''), NULLIF(p_new_service_type,''),
        NULLIF(p_new_bus_type,''), p_new_ticket_price, p_new_notes, v_price_diff,
        'pending', NOW(), NOW()
    );
    SET p_modification_id = LAST_INSERT_ID();

    SET v_after_json = JSON_OBJECT(
        'modification_id', CAST(p_modification_id AS CHAR),
        'booking_id',      CAST(p_booking_id AS CHAR),
        'price_difference',CAST(v_price_diff AS CHAR),
        'reason',          LEFT(p_modification_reason, 300)
    );

    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SET v_audit_ip = (
            SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_requested_by LIMIT 1
        );
    END;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        p_requested_by, 'insert', 'booking_modifications', p_modification_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_audit_ip, NULL, NOW()
    );

    COMMIT;
END$$


-- =============================================================
-- (3) sp_approve_booking_modification — الموافقة على التعديل
-- =============================================================
DROP PROCEDURE IF EXISTS `sp_approve_booking_modification`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_approve_booking_modification`(
    IN `p_modification_id`   INT,
    IN `p_reviewer_id`       INT,
    IN `p_review_notes`      TEXT,
    IN `p_is_approved`       TINYINT(1),
    OUT `p_booking_id`       INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الحجوزات] الموافقة/رفض طلب تعديل حجز + تطبيق التغييرات على الحجز'
sp_app_mod_body:
BEGIN
    DECLARE v_booking_id            INT DEFAULT NULL;
    DECLARE v_current_status        VARCHAR(30);
    DECLARE v_old_stage             VARCHAR(30);
    DECLARE v_new_from_city         INT;
    DECLARE v_new_to_city           INT;
    DECLARE v_new_departure_date    DATE;
    DECLARE v_new_return_date       DATE;
    DECLARE v_new_trip_type         VARCHAR(20);
    DECLARE v_new_service_type      VARCHAR(20);
    DECLARE v_new_bus_type          VARCHAR(20);
    DECLARE v_new_notes             TEXT;
    DECLARE v_price_diff            DECIMAL(18,4);
    DECLARE v_ctx                   JSON;
    DECLARE v_before_json           JSON;
    DECLARE v_after_json            JSON;
    DECLARE v_audit_ip              VARCHAR(45) DEFAULT NULL;
    DECLARE v_traveler_name         VARCHAR(255);
    DECLARE v_booking_number        VARCHAR(50);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'modification_id', CAST(p_modification_id AS CHAR),
            'reviewer_id',     CAST(p_reviewer_id AS CHAR),
            'is_approved',     CAST(p_is_approved AS CHAR),
            'mysql_errno',     CAST(@err_no AS CHAR),
            'sqlstate',        @err_sqlstate
        );
        CALL sp_log_error('sp_approve_booking_modification', @err_msg, p_reviewer_id, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر معالجة طلب التعديل. يرجى المحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_modification_id IS NULL OR p_modification_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف طلب التعديل غير صالح.';
    END IF;
    IF p_reviewer_id IS NULL OR p_reviewer_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف المراجع غير صالح.';
    END IF;

    SELECT m.booking_id, m.approval_status,
           m.new_from_city_id, m.new_to_city_id, m.new_departure_date,
           m.new_return_date, m.new_trip_type, m.new_service_type,
           m.new_bus_type, m.new_notes, m.price_difference
      INTO v_booking_id, v_current_status,
           v_new_from_city, v_new_to_city, v_new_departure_date,
           v_new_return_date, v_new_trip_type, v_new_service_type,
           v_new_bus_type, v_new_notes, v_price_diff
      FROM booking_modifications m
     WHERE m.id = p_modification_id LIMIT 1;

    SET p_booking_id = v_booking_id;

    IF v_booking_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'طلب التعديل غير موجود.';
    END IF;
    IF v_current_status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'تمت معالجة هذا الطلب مسبقاً.';
    END IF;

    SET v_before_json = JSON_OBJECT(
        'modification_id', CAST(p_modification_id AS CHAR),
        'booking_id',      CAST(v_booking_id AS CHAR),
        'approval_status_before', v_current_status
    );

    IF p_is_approved = 0 THEN
        -- (أ) الرفض
        UPDATE booking_modifications
           SET approval_status = 'rejected',
               reviewed_by     = p_reviewer_id,
               reviewed_at     = NOW(),
               review_notes    = COALESCE(p_review_notes, review_notes),
               updated_at      = NOW()
         WHERE id = p_modification_id;

        SET v_after_json = JSON_OBJECT(
            'modification_id', CAST(p_modification_id AS CHAR),
            'status_after',    'rejected',
            'review_notes',    LEFT(COALESCE(p_review_notes,''), 500)
        );
    ELSE
        -- (ب) الموافقة + تطبيق التغييرات على الحجز
        UPDATE booking_modifications
           SET approval_status = 'approved',
               reviewed_by     = p_reviewer_id,
               reviewed_at     = NOW(),
               review_notes    = COALESCE(p_review_notes, review_notes),
               updated_at      = NOW()
         WHERE id = p_modification_id;

        -- تطبيق القيم الجديدة غير الفارغة على الحجز (MERGE منطقي)
        UPDATE bus_flight_bookings b
           SET b.from_city_id    = COALESCE(v_new_from_city, b.from_city_id),
               b.to_city_id      = COALESCE(v_new_to_city,   b.to_city_id),
               b.departure_date  = COALESCE(v_new_departure_date, b.departure_date),
               b.return_date     = COALESCE(v_new_return_date,    b.return_date),
               b.trip_type       = COALESCE(v_new_trip_type,       b.trip_type),
               b.service_type    = COALESCE(v_new_service_type,    b.service_type),
               b.bus_type        = COALESCE(v_new_bus_type,        b.bus_type),
               b.mod_reason      = LEFT(COALESCE(CONCAT(IFNULL(b.mod_reason,''), ' | ',
                                        'تعديل: طلب #', p_modification_id,
                                        IFNULL(CONCAT(' — ', p_review_notes), '')), b.mod_reason), 1000),
               b.mod_datetime    = NOW(),
               b.booking_stage        = 'modified',
               b.last_stage_changed_at = NOW(),
               b.last_stage_changed_by = p_reviewer_id
         WHERE b.id = v_booking_id;

        -- سجل سير العمل
        SELECT booking_stage
          INTO v_old_stage
          FROM bus_flight_bookings WHERE id = v_booking_id;

        INSERT INTO booking_workflow (
            booking_id, from_stage, to_stage,
            transition_notes, extra_data, performed_by, performed_at
        ) VALUES (
            v_booking_id, v_old_stage, 'modified',
            CONCAT('موافقة على تعديل الحجز — طلب #', p_modification_id),
            JSON_OBJECT('modification_id', CAST(p_modification_id AS CHAR),
                        'price_difference', CAST(v_price_diff AS CHAR)),
            p_reviewer_id, NOW()
        );

        -- سجل الحالة القديم للتوافق
        INSERT INTO booking_status_logs (
            booking_id, old_status_id, new_status_id,
            changed_by, notes, created_at
        ) VALUES (
            v_booking_id, NULL, NULL, p_reviewer_id,
            CONCAT('تعديل الحجز — طلب #', p_modification_id), NOW()
        );

        -- إشعار "تعديل" للنظام
        SELECT traveler_name, booking_number
          INTO v_traveler_name, v_booking_number
          FROM bus_flight_bookings WHERE id = v_booking_id;

        INSERT INTO booking_notifications (
            booking_id, notification_type, delivery_channel,
            subject_text, body_text,
            status, scheduled_at, sent_at, sent_by, created_at
        ) VALUES (
            v_booking_id, 'modification', 'system',
            CONCAT('تم تعديل حجز رقم ', v_booking_number),
            CONCAT('عزيزي ', IFNULL(v_traveler_name,'المسافر'),
                   '،\nتم تعديل تفاصيل حجزك رقم ', v_booking_number,
                   ' بناءً على طلبك. يرجى مراجعة التفاصيل الجديدة.'),
            'pending', NOW(), NOW(), p_reviewer_id, NOW()
        );

        SET v_after_json = JSON_OBJECT(
            'modification_id', CAST(p_modification_id AS CHAR),
            'booking_id',      CAST(v_booking_id AS CHAR),
            'status_after',    'approved',
            'price_difference',CAST(v_price_diff AS CHAR),
            'new_stage',       'modified'
        );
    END IF;

    -- سجل تدقيق
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SET v_audit_ip = (
            SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_reviewer_id LIMIT 1
        );
    END;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        p_reviewer_id, 'update', 'booking_modifications', p_modification_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_audit_ip, NULL, NOW()
    );

    COMMIT;
END$$


-- =============================================================
-- (4) sp_cancel_booking — الإلغاء مع دعم الاسترداد المالي
-- =============================================================
DROP PROCEDURE IF EXISTS `sp_cancel_booking`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_cancel_booking`(
    IN `p_booking_id`              INT,
    IN `p_user_id`                 INT,
    IN `p_cancel_reason`           TEXT,

    -- بيانات الاسترداد المالي (يمكن إرسالها كـ NULL لعدم تسجيل طلب استرداد في نفس اللحظة)
    IN `p_refund_amount`           DECIMAL(18,4),
    IN `p_refund_currency_id`      INT,
    IN `p_customer_account_id`     INT,
    IN `p_cash_bank_account_id`    INT,
    IN `p_refund_method`           VARCHAR(30),
    IN `p_is_partial_refund`       TINYINT(1),
    IN `p_process_refund_now`      TINYINT(1),           -- هل تريد إنشاء سند صرف الآن أم يُعالج لاحقاً؟

    -- مخرجات
    OUT `p_refund_request_id`      INT,
    OUT `p_financial_tx_id`        INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الحجوزات] إلغاء الحجز + إنشاء طلب استرداد مالي + تحديث السير'
sp_cancel_booking_body:
BEGIN
    DECLARE v_exists              INT DEFAULT 0;
    DECLARE v_is_cancelled        TINYINT(1);
    DECLARE v_old_stage           VARCHAR(30);
    DECLARE v_before_json         JSON;
    DECLARE v_after_json          JSON;
    DECLARE v_ctx                 JSON;
    DECLARE v_audit_ip            VARCHAR(45) DEFAULT NULL;
    DECLARE v_refund_id           INT DEFAULT NULL;
    DECLARE v_tx_id               INT DEFAULT NULL;
    DECLARE v_traveler_name       VARCHAR(255);
    DECLARE v_booking_number      VARCHAR(50);
    DECLARE v_branch_id           INT;
    DECLARE v_created_by          INT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'booking_id',      CAST(p_booking_id AS CHAR),
            'user_id',         CAST(p_user_id AS CHAR),
            'process_refund',  CAST(p_process_refund_now AS CHAR),
            'mysql_errno',     CAST(@err_no AS CHAR),
            'sqlstate',        @err_sqlstate
        );
        CALL sp_log_error('sp_cancel_booking', @err_msg, p_user_id, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إلغاء الحجز. يرجى مراجعة البيانات والمحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_booking_id IS NULL OR p_booking_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف الحجز غير صالح.';
    END IF;
    IF p_user_id IS NULL OR p_user_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف المستخدم غير صالح.';
    END IF;
    IF CHAR_LENGTH(TRIM(COALESCE(p_cancel_reason,''))) < 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يجب ذكر سبب الإلغاء.';
    END IF;

    -- التحقق من الاسترداد المالي إن تم طلب المعالجة الفورية
    IF p_process_refund_now = 1 THEN
        IF p_refund_amount IS NULL OR p_refund_amount <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يجب تحديد مبلغ صالح للاسترداد.';
        END IF;
        IF p_refund_currency_id IS NULL OR p_refund_currency_id <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يجب تحديد عملة الاسترداد.';
        END IF;
        IF p_customer_account_id IS NULL OR p_customer_account_id <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يجب تحديد حساب العميل.';
        END IF;
        IF p_cash_bank_account_id IS NULL OR p_cash_bank_account_id <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يجب تحديد حساب الصندوق / البنك للاسترداد.';
        END IF;
    END IF;

    -- جلب الحجز
    SELECT 1, is_cancelled, booking_stage, traveler_name,
           booking_number, branch_id, created_by
      INTO v_exists, v_is_cancelled, v_old_stage, v_traveler_name,
           v_booking_number, v_branch_id, v_created_by
      FROM bus_flight_bookings
     WHERE id = p_booking_id LIMIT 1;

    IF v_exists <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز غير موجود.';
    END IF;
    IF v_is_cancelled = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز ملغى مسبقاً.';
    END IF;
    IF v_old_stage = 'departed' OR v_old_stage = 'completed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'لا يمكن إلغاء الحجز بعد انطلاقه أو إكماله.';
    END IF;

    -- صورة قبل
    SET v_before_json = JSON_OBJECT(
        'booking_id',     CAST(p_booking_id AS CHAR),
        'is_cancelled',   '0',
        'booking_stage',  COALESCE(v_old_stage,''),
        'refund_status',  'none'
    );

    -- إلغاء الحجز
    UPDATE bus_flight_bookings
       SET is_cancelled         = 1,
           cancel_reason        = p_cancel_reason,
           cancel_datetime      = NOW(),
           is_confirmed         = 0,
           booking_stage        = 'cancelled',
           last_stage_changed_at = NOW(),
           last_stage_changed_by = p_user_id,
           refund_status        = CASE
                                    WHEN p_refund_amount IS NULL OR p_refund_amount <= 0 THEN 'none'
                                    WHEN p_process_refund_now = 1 THEN 'processed'
                                    ELSE 'requested'
                                  END,
           refund_amount        = COALESCE(p_refund_amount, 0),
           refund_processed_at  = CASE
                                    WHEN p_process_refund_now = 1 THEN NOW()
                                    ELSE NULL
                                  END
     WHERE id = p_booking_id;

    -- سجل سير العمل
    INSERT INTO booking_workflow (
        booking_id, from_stage, to_stage, transition_notes,
        extra_data, performed_by, performed_at
    ) VALUES (
        p_booking_id, v_old_stage, 'cancelled',
        CONCAT('إلغاء الحجز — ', p_cancel_reason),
        JSON_OBJECT('cancel_reason',       LEFT(p_cancel_reason, 500),
                    'refund_amount',       CAST(COALESCE(p_refund_amount, 0) AS CHAR),
                    'process_refund_now',  CAST(p_process_refund_now AS CHAR)),
        p_user_id, NOW()
    );

    -- سجل الحالة القديم للتوافق
    INSERT INTO booking_status_logs (
        booking_id, old_status_id, new_status_id,
        changed_by, notes, created_at
    ) VALUES (
        p_booking_id, NULL, NULL, p_user_id,
        CONCAT('إلغاء الحجز — ', LEFT(p_cancel_reason, 250)), NOW()
    );

    -- إلغاء أي تذاكر رقمية صادرة عن هذا الحجز
    UPDATE booking_tickets
       SET is_void = 1, updated_at = NOW()
     WHERE booking_id = p_booking_id AND is_void = 0;

    -- إنشاء طلب استرداد (لو تم إعطاؤه)
    IF p_refund_amount IS NOT NULL AND p_refund_amount > 0 THEN

        INSERT INTO booking_refunds (
            booking_id, refund_amount, refund_currency_id,
            customer_account_id, cash_bank_account_id, refund_method,
            refund_reason, is_partial,
            requested_by, requested_at,
            status, approved_by, approved_at,
            processed_by, processed_at
        ) VALUES (
            p_booking_id, p_refund_amount, p_refund_currency_id,
            p_customer_account_id, p_cash_bank_account_id,
            COALESCE(NULLIF(p_refund_method,''), 'cash'),
            p_cancel_reason, COALESCE(p_is_partial_refund, 0),
            p_user_id, NOW(),
            CASE WHEN p_process_refund_now = 1 THEN 'processed' ELSE 'requested' END,
            CASE WHEN p_process_refund_now = 1 THEN p_user_id     ELSE NULL END,
            CASE WHEN p_process_refund_now = 1 THEN NOW()         ELSE NULL END,
            CASE WHEN p_process_refund_now = 1 THEN p_user_id     ELSE NULL END,
            CASE WHEN p_process_refund_now = 1 THEN NOW()         ELSE NULL END
        );
        SET v_refund_id = LAST_INSERT_ID();
    END IF;

    -- إشعار إلغاء
    INSERT INTO booking_notifications (
        booking_id, notification_type, delivery_channel,
        subject_text, body_text,
        status, scheduled_at, sent_at, sent_by, created_at
    ) VALUES (
        p_booking_id, 'cancellation', 'system',
        CONCAT('إشعار بإلغاء حجز رقم ', v_booking_number),
        CONCAT('عزيزي ', IFNULL(v_traveler_name,'المسافر'),
               '،\nنعتذر لإبلاغكم أنه تم إلغاء الحجز رقم ', v_booking_number,
               '.\nسبب الإلغاء: ', p_cancel_reason,
               IF(p_refund_amount IS NOT NULL AND p_refund_amount > 0,
                  CONCAT('\nسيتم استرداد مبلغ قدره: ', CAST(p_refund_amount AS CHAR)),
                  '')),
        'pending', NOW(), NOW(), p_user_id, NOW()
    );

    -- سجل تدقيق
    SET v_after_json = JSON_OBJECT(
        'booking_id',     CAST(p_booking_id AS CHAR),
        'is_cancelled',   '1',
        'cancel_at',      DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'),
        'booking_stage',  'cancelled',
        'refund_status',  CASE
                            WHEN p_refund_amount IS NULL OR p_refund_amount <= 0 THEN 'none'
                            WHEN p_process_refund_now = 1 THEN 'processed'
                            ELSE 'requested'
                          END,
        'refund_amount',  CAST(COALESCE(p_refund_amount, 0) AS CHAR),
        'refund_id',      CAST(COALESCE(v_refund_id, 0) AS CHAR)
    );

    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SET v_audit_ip = (
            SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_user_id LIMIT 1
        );
    END;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        p_user_id, 'cancel', 'bus_flight_bookings', p_booking_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_audit_ip, NULL, NOW()
    );

    -- ضبط المعاملات المخرجات
    SET p_refund_request_id = v_refund_id;
    SET p_financial_tx_id   = v_tx_id;

    COMMIT;
END$$


-- =============================================================
-- (5) sp_update_booking_stage — تحديث مرحلة سير عمل الحجز
-- =============================================================
DROP PROCEDURE IF EXISTS `sp_update_booking_stage`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_update_booking_stage`(
    IN `p_booking_id`   INT,
    IN `p_user_id`      INT,
    IN `p_new_stage`    VARCHAR(30),
    IN `p_notes`        TEXT,
    IN `p_extra_data`   JSON
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الحجوزات] تحديث مرحلة الحجز (مؤكد / تم إصدار التذكرة / انطلق / مكتمل / ملغى / معدّل)'
sp_update_stage_body:
BEGIN
    DECLARE v_exists          INT DEFAULT 0;
    DECLARE v_old_stage       VARCHAR(30);
    DECLARE v_is_cancelled    TINYINT(1);
    DECLARE v_ctx             JSON;
    DECLARE v_before_json     JSON;
    DECLARE v_after_json      JSON;
    DECLARE v_audit_ip        VARCHAR(45) DEFAULT NULL;
    DECLARE v_traveler_name   VARCHAR(255);
    DECLARE v_booking_number  VARCHAR(50);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'booking_id',  CAST(p_booking_id AS CHAR),
            'user_id',     CAST(p_user_id AS CHAR),
            'new_stage',   COALESCE(p_new_stage,''),
            'mysql_errno', CAST(@err_no AS CHAR),
            'sqlstate',    @err_sqlstate
        );
        CALL sp_log_error('sp_update_booking_stage', @err_msg, p_user_id, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر تحديث مرحلة الحجز. يرجى المحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    -- التحقق من صحة المرحلة
    IF p_new_stage NOT IN ('pending','confirmed','ticketed','departed','completed','cancelled','modified') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'المرحلة المطلوبة غير معروفة.';
    END IF;
    IF p_booking_id IS NULL OR p_booking_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف الحجز غير صالح.';
    END IF;
    IF p_user_id IS NULL OR p_user_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف المستخدم غير صالح.';
    END IF;

    SELECT 1, booking_stage, is_cancelled, traveler_name, booking_number
      INTO v_exists, v_old_stage, v_is_cancelled, v_traveler_name, v_booking_number
      FROM bus_flight_bookings WHERE id = p_booking_id LIMIT 1;

    IF v_exists <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز غير موجود.';
    END IF;

    -- لا يمكن العودة للوراء من مرحلتين نهائيتين (الحماية)
    IF v_old_stage = 'cancelled' OR v_old_stage = 'completed' THEN
        IF p_new_stage <> v_old_stage THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'لا يمكن تغيير المرحلة بعد أن انتهى الحجز (أو أُلغي).';
        END IF;
    END IF;

    SET v_before_json = JSON_OBJECT('old_stage', COALESCE(v_old_stage,''));

    -- تحديث المرحلة في الحجز الرئيسي
    UPDATE bus_flight_bookings
       SET booking_stage          = p_new_stage,
           last_stage_changed_at  = NOW(),
           last_stage_changed_by  = p_user_id,
           is_confirmed           = CASE WHEN p_new_stage IN ('confirmed','ticketed','departed','completed','modified') THEN 1 ELSE is_confirmed END
     WHERE id = p_booking_id;

    -- تسجيل سير العمل
    INSERT INTO booking_workflow (
        booking_id, from_stage, to_stage, transition_notes,
        extra_data, performed_by, performed_at
    ) VALUES (
        p_booking_id, v_old_stage, p_new_stage, p_notes,
        COALESCE(p_extra_data, JSON_OBJECT()), p_user_id, NOW()
    );

    -- سجل الحالة القديم للتوافق
    INSERT INTO booking_status_logs (
        booking_id, old_status_id, new_status_id,
        changed_by, notes, created_at
    ) VALUES (
        p_booking_id, NULL, NULL, p_user_id,
        CONCAT('مرحلة: ', v_old_stage, ' → ', p_new_stage,
               IF(p_notes IS NOT NULL, CONCAT(' — ', p_notes), '')), NOW()
    );

    -- إشعارات تلقائية لمراحل مهمة
    CASE p_new_stage
        WHEN 'ticketed' THEN
            INSERT INTO booking_notifications (
                booking_id, notification_type, delivery_channel,
                subject_text, body_text,
                status, scheduled_at, sent_at, sent_by, created_at
            ) VALUES (
                p_booking_id, 'confirmation', 'system',
                CONCAT('تم إصدار تذكرة الحجز ', v_booking_number),
                CONCAT('عزيزي ', IFNULL(v_traveler_name,'المسافر'),
                       '،\nتم إصدار التذكرة لحجزك رقم ', v_booking_number,
                       '. يمكنك تنزيل التذكرة الرقمية من حسابك.'),
                'pending', NOW(), NOW(), p_user_id, NOW()
            );

        WHEN 'departed' THEN
            INSERT INTO booking_notifications (
                booking_id, notification_type, delivery_channel,
                subject_text, body_text,
                status, scheduled_at, sent_at, sent_by, created_at
            ) VALUES (
                p_booking_id, 'departure', 'system',
                CONCAT('انطلقت رحلة الحجز ', v_booking_number),
                CONCAT('تم انطلاق رحلة الحجز رقم ', v_booking_number,
                       ' بنجاح. نتمنى رحلة آمنة وممتعة لـ ', IFNULL(v_traveler_name,'المسافر'), '.'),
                'pending', NOW(), NOW(), p_user_id, NOW()
            );

        WHEN 'completed' THEN
            INSERT INTO booking_notifications (
                booking_id, notification_type, delivery_channel,
                subject_text, body_text,
                status, scheduled_at, sent_at, sent_by, created_at
            ) VALUES (
                p_booking_id, 'completed', 'system',
                CONCAT('تم إكمال حجز رقم ', v_booking_number),
                CONCAT('تم إكمال رحلة الحجز رقم ', v_booking_number, ' بنجاح.\nشكراً لثقتكم بنا.'),
                'pending', NOW(), NOW(), p_user_id, NOW()
            );
    END CASE;

    SET v_after_json = JSON_OBJECT(
        'new_stage', p_new_stage,
        'notes',     LEFT(COALESCE(p_notes,''), 500)
    );

    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SET v_audit_ip = (
            SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_user_id LIMIT 1
        );
    END;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        p_user_id, 'stage_change', 'bus_flight_bookings', p_booking_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_audit_ip, NULL, NOW()
    );

    COMMIT;
END$$


-- =============================================================
-- (6) sp_generate_ticket — إنشاء تذكرة رقمية
-- =============================================================
DROP PROCEDURE IF EXISTS `sp_generate_ticket`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_generate_ticket`(
    IN `p_booking_id`       INT,
    IN `p_issued_by`        INT,
    IN `p_seat_number`      VARCHAR(30),
    IN `p_pnr`              VARCHAR(100),
    IN `p_supplier_ref`     VARCHAR(200),
    IN `p_bus_flight_number` VARCHAR(100),
    IN `p_public_base_url`  VARCHAR(500),        -- مثل https://alghazali.com/ticket/verify
    OUT `p_ticket_id`       INT,
    OUT `p_ticket_number`   VARCHAR(64)
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الحجوزات] إنشاء تذكرة رقمية جديدة مع QR ورمز تحقق + تحديث مرحلة Ticketed'
sp_generate_ticket_body:
BEGIN
    DECLARE v_exists            INT DEFAULT 0;
    DECLARE v_service_type      VARCHAR(20);
    DECLARE v_trip_type         VARCHAR(20);
    DECLARE v_traveler_name     VARCHAR(255);
    DECLARE v_mobile            VARCHAR(50);
    DECLARE v_id_type           VARCHAR(20);
    DECLARE v_id_number         VARCHAR(100);
    DECLARE v_from_id           INT;
    DECLARE v_to_id             INT;
    DECLARE v_from_name         VARCHAR(200);
    DECLARE v_to_name           VARCHAR(200);
    DECLARE v_departure_date    DATE;
    DECLARE v_return_date       DATE;
    DECLARE v_sales_inv_total   DECIMAL(18,4) DEFAULT 0;
    DECLARE v_sales_inv_tax     DECIMAL(18,4) DEFAULT 0;
    DECLARE v_sales_inv_curr    VARCHAR(10) DEFAULT 'SAR';
    DECLARE v_invoice_curr_id   INT;
    DECLARE v_ctx               JSON;
    DECLARE v_audit_ip          VARCHAR(45) DEFAULT NULL;
    DECLARE v_before_json       JSON;
    DECLARE v_after_json        JSON;
    DECLARE v_random_token      VARCHAR(64);
    DECLARE v_qr_payload        VARCHAR(500);
    DECLARE v_ticket_hash       VARCHAR(255);
    DECLARE v_date_prefix       VARCHAR(12);
    DECLARE v_counter           INT;
    DECLARE v_ticket_num        VARCHAR(64);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'booking_id',  CAST(p_booking_id AS CHAR),
            'issued_by',   CAST(p_issued_by AS CHAR),
            'mysql_errno', CAST(@err_no AS CHAR),
            'sqlstate',    @err_sqlstate
        );
        CALL sp_log_error('sp_generate_ticket', @err_msg, p_issued_by, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء التذكرة. يرجى المحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    IF p_booking_id IS NULL OR p_booking_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف الحجز غير صالح.';
    END IF;
    IF p_issued_by IS NULL OR p_issued_by <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف منشئ التذكرة غير صالح.';
    END IF;

    SELECT 1, service_type, trip_type, traveler_name, mobile_number,
           id_type, id_number, from_city_id, to_city_id,
           departure_date, return_date, sales_invoice_id
      INTO v_exists, v_service_type, v_trip_type, v_traveler_name, v_mobile,
           v_id_type, v_id_number, v_from_id, v_to_id,
           v_departure_date, v_return_date, v_invoice_curr_id
      FROM bus_flight_bookings bfb
     WHERE bfb.id = p_booking_id LIMIT 1;

    IF v_exists <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز غير موجود.';
    END IF;

    -- أسماء المدن (محفوظة نصياً كـ snapshot لضمان عدم التغيير لاحقاً)
    SELECT city_name INTO v_from_name FROM cities WHERE id = v_from_id LIMIT 1;
    SELECT city_name INTO v_to_name   FROM cities WHERE id = v_to_id   LIMIT 1;
    IF v_from_name IS NULL THEN SET v_from_name = '-'; END IF;
    IF v_to_name   IS NULL THEN SET v_to_name   = '-'; END IF;

    -- جلب قيمة الفاتورة
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SELECT COALESCE(net_amount, total_amount, 0),
               COALESCE(tax_amount, 0),
               COALESCE(currency_code, 'SAR')
          INTO v_sales_inv_total, v_sales_inv_tax, v_sales_inv_curr
          FROM invoices inv
          LEFT JOIN currencies cur ON cur.id = inv.currency_id
         WHERE inv.id = (SELECT sales_invoice_id FROM bus_flight_bookings WHERE id = p_booking_id)
         LIMIT 1;
    END;

    -- (1) توليد رقم تذكرة فريد بالتنسيق:
    --     TKT-BF-YYMM-00001
    SET v_date_prefix = DATE_FORMAT(NOW(), '%y%m');
    SELECT COALESCE(COUNT(*), 0) + 1 INTO v_counter
      FROM booking_tickets
     WHERE ticket_number LIKE CONCAT('TKT-BF-', v_date_prefix, '-%');
    SET v_ticket_num = CONCAT('TKT-BF-', v_date_prefix, '-', LPAD(v_counter, 5, '0'));

    -- (2) رمز تحقق عشوائي للتأكد من صحة التذكرة عبر الرابط العام
    SET v_random_token = LOWER(CONV(CAST(ROUND(RAND() * 1e16) AS UNSIGNED), 10, 36));
    SET v_random_token = CONCAT(v_random_token, LOWER(MD5(CONCAT(NOW(), UUID(), p_booking_id))));
    SET v_random_token = LEFT(v_random_token, 40);

    -- (3) بيانات رمز الـ QR (رابط تحقق عام + خصائص التذكرة)
    SET v_qr_payload = CONCAT(
        COALESCE(p_public_base_url, '#'), '/', v_random_token,
        '?t=', v_ticket_num,
        '&n=', TRIM(v_traveler_name)
    );

    -- (4) توقيع رقمي بسيط (hash لحرمة البيانات)
    SET v_ticket_hash = UPPER(SHA2(CONCAT(
        v_ticket_num, '|', v_traveler_name, '|', v_id_number, '|',
        IFNULL(v_departure_date,''), '|', v_from_name, '-', v_to_name
    ), 256));

    -- (5) إنشاء سجل التذكرة
    INSERT INTO booking_tickets (
        booking_id, ticket_number,
        service_type, trip_type,
        departure_city_name, arrival_city_name,
        departure_datetime, return_datetime,
        seat_number, pnr, supplier_reference, bus_flight_number,
        traveler_name, id_type, id_number,
        qr_code_data, verification_token, ticket_hash,
        currency_code, ticket_price, tax_amount, total_amount,
        issued_by, issued_at
    ) VALUES (
        p_booking_id, v_ticket_num,
        v_service_type, v_trip_type,
        v_from_name, v_to_name,
        v_departure_date, v_return_date,
        NULLIF(TRIM(p_seat_number), ''), NULLIF(TRIM(p_pnr), ''),
        NULLIF(TRIM(p_supplier_ref), ''), NULLIF(TRIM(p_bus_flight_number), ''),
        v_traveler_name, v_id_type, v_id_number,
        v_qr_payload, v_random_token, v_ticket_hash,
        v_sales_inv_curr,
        GREATEST(0, v_sales_inv_total - v_sales_inv_tax),
        v_sales_inv_tax,
        v_sales_inv_total,
        p_issued_by, NOW()
    );
    SET p_ticket_id      = LAST_INSERT_ID();
    SET p_ticket_number  = v_ticket_num;

    -- (6) تحديث حقل ticket_number في الحجز للتوافق مع البنية القديمة
    UPDATE bus_flight_bookings
       SET ticket_number = v_ticket_num
     WHERE id = p_booking_id;

    -- (7) نقل الحجز إلى مرحلة "تم إصدار التذكرة"
    CALL sp_update_booking_stage(
        p_booking_id,
        p_issued_by,
        'ticketed',
        CONCAT('إصدار التذكرة رقم ', v_ticket_num),
        JSON_OBJECT(
            'ticket_id',     CAST(p_ticket_id AS CHAR),
            'ticket_number', v_ticket_num,
            'seat',          COALESCE(p_seat_number,''),
            'pnr',           COALESCE(p_pnr,'')
        )
    );

    -- (8) سجل تدقيق
    SET v_before_json = JSON_OBJECT('action','generate_ticket');
    SET v_after_json  = JSON_OBJECT(
        'ticket_id',         CAST(p_ticket_id AS CHAR),
        'ticket_number',     v_ticket_num,
        'verification_token',v_random_token,
        'seat_number',       COALESCE(p_seat_number,''),
        'total_amount',      CAST(v_sales_inv_total AS CHAR)
    );

    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SET v_audit_ip = (
            SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = p_issued_by LIMIT 1
        );
    END;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        p_issued_by, 'insert', 'booking_tickets', p_ticket_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_audit_ip, NULL, NOW()
    );

    COMMIT;
END$$


-- =============================================================
-- (7) sp_create_booking_notification — إرسال إشعارات الحجز يدويًا
-- =============================================================
DROP PROCEDURE IF EXISTS `sp_create_booking_notification`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_booking_notification`(
    IN `p_booking_id`          INT,
    IN `p_user_id`             INT,
    IN `p_notification_type`   VARCHAR(30),
    IN `p_delivery_channel`    VARCHAR(30),
    IN `p_subject`             VARCHAR(500),
    IN `p_body`                TEXT,
    IN `p_scheduled_at`        DATETIME,
    IN `p_extra_data`          JSON,
    OUT `p_notification_id`    INT
)
    MODIFIES SQL DATA
    SQL SECURITY INVOKER
    COMMENT '[الحجوزات] إنشاء إشعار حجز جديد (يدوياً أو من جدولة)'
sp_create_notif_body:
BEGIN
    DECLARE v_exists          INT DEFAULT 0;
    DECLARE v_ctx             JSON;
    DECLARE v_before_json     JSON;
    DECLARE v_after_json      JSON;
    DECLARE v_audit_ip        VARCHAR(45) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            @err_sqlstate = RETURNED_SQLSTATE,
            @err_msg      = MESSAGE_TEXT,
            @err_no       = MYSQL_ERRNO;
        SET v_ctx = JSON_OBJECT(
            'booking_id',         CAST(p_booking_id AS CHAR),
            'user_id',            CAST(p_user_id AS CHAR),
            'notification_type',  COALESCE(p_notification_type,''),
            'delivery_channel',   COALESCE(p_delivery_channel,''),
            'mysql_errno',        CAST(@err_no AS CHAR),
            'sqlstate',           @err_sqlstate
        );
        CALL sp_log_error('sp_create_booking_notification', @err_msg, p_user_id, v_ctx);
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'تعذر إنشاء الإشعار. يرجى المحاولة مرة أخرى.';
    END;

    START TRANSACTION;

    -- التحقق
    IF p_booking_id IS NULL OR p_booking_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'معرف الحجز غير صالح.';
    END IF;
    IF CHAR_LENGTH(TRIM(COALESCE(p_body,''))) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'يجب إدخال نص الإشعار.';
    END IF;
    IF p_notification_type NOT IN
            ('confirmation','reminder','cancellation','modification','payment','departure','completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'نوع الإشعار غير صالح.';
    END IF;
    IF p_delivery_channel NOT IN ('email','whatsapp','sms','system') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'قناة الإرسال غير صالحة.';
    END IF;

    SELECT 1 INTO v_exists FROM bus_flight_bookings WHERE id = p_booking_id;
    IF v_exists <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الحجز غير موجود.';
    END IF;

    INSERT INTO booking_notifications (
        booking_id, user_id,
        notification_type, delivery_channel,
        subject_text, body_text, extra_data,
        status, scheduled_at, sent_by, created_at
    ) VALUES (
        p_booking_id, IF(p_user_id <= 0, NULL, p_user_id),
        p_notification_type, p_delivery_channel,
        p_subject, p_body, COALESCE(p_extra_data, JSON_OBJECT()),
        IF(p_scheduled_at IS NULL, 'pending', 'scheduled'),
        COALESCE(p_scheduled_at, NOW()),
        IF(p_user_id <= 0, NULL, p_user_id),
        NOW()
    );
    SET p_notification_id = LAST_INSERT_ID();

    SET v_before_json = JSON_OBJECT('action','create_notification');
    SET v_after_json  = JSON_OBJECT(
        'notification_id',   CAST(p_notification_id AS CHAR),
        'type',              p_notification_type,
        'channel',           p_delivery_channel,
        'scheduled_at',      COALESCE(DATE_FORMAT(p_scheduled_at, '%Y-%m-%d %H:%i:%s'),'')
    );

    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        SET v_audit_ip = (
            SELECT SUBSTRING_INDEX(
                GROUP_CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.ip_address')), '')
                             ORDER BY id DESC SEPARATOR '|'), '|', 1)
              FROM audit_logs WHERE user_id = IFNULL(p_user_id, 0) LIMIT 1
        );
    END;

    INSERT INTO audit_logs (
        user_id, action, table_name, record_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        IFNULL(p_user_id, 0), 'insert', 'booking_notifications', p_notification_id,
        CAST(v_before_json AS CHAR), CAST(v_after_json AS CHAR),
        v_audit_ip, NULL, NOW()
    );

    COMMIT;
END$$


DELIMITER ;

-- =============================================================
/*
-- [الجزء الخامس] أمثلة على الاستخدام (تنفيذ الاستعلامات)
-- =============================================================

-- (أ) تأكيد حجز يدوي
-- CALL sp_confirm_booking(
--     p_booking_id          => 25,
--     p_user_id             => 2,
--     p_confirmation_method => 'phone'
-- );

-- (ب) تقديم طلب تعديل الحجز
-- CALL sp_request_booking_modification(
--     p_booking_id            => 25,
--     p_requested_by          => 2,
--     p_modification_reason   => 'العميل طلب تغيير موعد الانطلاق',
--     p_new_from_city_id      => NULL,
--     p_new_to_city_id        => NULL,
--     p_new_departure_date    => '2026-08-20',
--     p_new_return_date       => NULL,
--     p_new_trip_type         => NULL,
--     p_new_service_type      => NULL,
--     p_new_bus_type          => NULL,
--     p_new_ticket_price      => 950.00,
--     p_new_notes             => 'تأجيل موعد المغادرة بعشرين يوماً',
--     p_old_ticket_price      => 900.00,
--     @mod_id
-- ); SELECT @mod_id;

-- (ج) الموافقة على طلب التعديل
-- CALL sp_approve_booking_modification(
--     p_modification_id   => 1,
--     p_reviewer_id       => 1,
--     p_review_notes      => 'تمت الموافقة بعد مطابقة التعديل مع المورد.',
--     p_is_approved       => 1,
--     @booking_id_output
-- ); SELECT @booking_id_output;

-- (د) إلغاء حجز مع استرداد كامل (توليد طلب استرداد فقط)
-- CALL sp_cancel_booking(
--     p_booking_id           => 25,
--     p_user_id              => 1,
--     p_cancel_reason        => 'إلغاء العميل للرحلة بسبب ظروفه الشخصية',
--     p_refund_amount        => 900.00,
--     p_refund_currency_id   => 1,
--     p_customer_account_id  => 164,
--     p_cash_bank_account_id => 5,
--     p_refund_method        => 'cash',
--     p_is_partial_refund    => 0,
--     p_process_refund_now   => 0,   -- فقط إنشاء الطلب، لا يُصدر سند صرف الآن
--     @refund_id, @financial_tx_id
-- ); SELECT @refund_id, @financial_tx_id;

-- (هـ) تحديث مرحلة الحجز إلى "انطلق"
-- CALL sp_update_booking_stage(
--     p_booking_id => 25,
--     p_user_id    => 2,
--     p_new_stage  => 'departed',
--     p_notes      => 'انطلق الباص من الموقف بتوقيت ٨ صباحاً',
--     p_extra_data => JSON_OBJECT('actual_departure_time', '2026-08-10 08:00:00')
-- );

-- (و) إنشاء تذكرة رقمية لحجز
-- CALL sp_generate_ticket(
--     p_booking_id          => 25,
--     p_issued_by           => 2,
--     p_seat_number         => 'A17',
--     p_pnr                 => 'SUP-8827-X',
--     p_supplier_ref        => 'SUP-ORDER-4491',
--     p_bus_flight_number   => 'BUS-09',
--     p_public_base_url     => 'https://alghazali.example.com/ticket/verify',
--     @ticket_id,
--     @ticket_number
-- ); SELECT @ticket_id, @ticket_number;

-- (ز) إرسال إشعار تذكير (جدول ليوم قبل المغادرة)
-- CALL sp_create_booking_notification(
--     p_booking_id         => 25,
--     p_user_id            => 1,
--     p_notification_type  => 'reminder',
--     p_delivery_channel   => 'whatsapp',
--     p_subject            => 'تذكير بالانطلاق غداً',
--     p_body               => 'تذكير عزيزي المسافر: موعد انطلاق رحلتك هو غداً الساعة 8 صباحاً من موقف صنعاء الرئيسي. يرجى الحضور قبل ساعة.',
--     p_scheduled_at       => '2026-08-09 20:00:00',
--     p_extra_data         => JSON_OBJECT('template_id','REMINDER_D1'),
--     @notif_id
-- ); SELECT @notif_id;


-- =============================================================
-- [الجزء السادس] استعلامات التقارير (SELECT queries)
-- =============================================================

-- (1) ملخص حالة الحجوزات حسب المرحلة
SELECT
    bs.label_ar                              AS `المرحلة`,
    COUNT(bfb.id)                            AS `عدد الحجوزات`,
    SUM(CASE WHEN bfb.service_type='flight' THEN 1 ELSE 0 END) AS `طيران`,
    SUM(CASE WHEN bfb.service_type='bus'    THEN 1 ELSE 0 END) AS `باص`,
    COALESCE(SUM(CASE WHEN inv.invoice_category='sales' THEN inv.net_amount ELSE 0 END), 0) AS `إجمالي المبيعات`,
    COALESCE(SUM(bfb.refund_amount), 0)     AS `إجمالي المستردات`
FROM bus_flight_bookings bfb
LEFT JOIN statuses bs ON bs.technical_name = bfb.booking_stage
LEFT JOIN invoices inv ON inv.id = bfb.sales_invoice_id
WHERE bfb.deleted_at IS NULL
GROUP BY bfb.booking_stage
ORDER BY COUNT(bfb.id) DESC;

-- (2) تقرير التذاكر الصادرة خلال فترة
SELECT
    bt.ticket_number                        AS `رقم التذكرة`,
    bfb.booking_number                      AS `رقم الحجز`,
    bt.traveler_name                        AS `المسافر`,
    CONCAT(bt.departure_city_name, ' → ', bt.arrival_city_name) AS `المسار`,
    DATE_FORMAT(bt.departure_datetime, '%Y-%m-%d') AS `تاريخ الانطلاق`,
    bt.currency_code                        AS `العملة`,
    bt.total_amount                         AS `الإجمالي`,
    bt.issued_at                            AS `تاريخ الإصدار`,
    u.full_name                             AS `صدر التذكرة من`,
    IF(bt.is_void=1, 'ملغية', 'صالحة')     AS `حالة التذكرة`,
    CONCAT_WS('',
        IFNULL(bt.seat_number, ''),
        IF(bt.seat_number IS NOT NULL, ' / مقعد #', '')
    )                                       AS `المرجع`
FROM booking_tickets bt
JOIN bus_flight_bookings bfb ON bfb.id = bt.booking_id
LEFT JOIN users u ON u.id = bt.issued_by
WHERE bt.issued_at BETWEEN '2026-01-01 00:00:00' AND NOW()
ORDER BY bt.issued_at DESC;

-- (3) تقرير طلبات الاسترداد
SELECT
    br.id                                   AS `رقم الطلب`,
    bfb.booking_number                      AS `الحجز`,
    br.refund_amount                        AS `المبلغ`,
    cur.currency_symbol                     AS `الرمز`,
    br.refund_method                        AS `طريقة الاسترداد`,
    CASE br.status
        WHEN 'requested' THEN 'مطلوب'
        WHEN 'approved'  THEN 'تمت الموافقة'
        WHEN 'processed' THEN 'تمت المعالجة'
        WHEN 'rejected'  THEN 'مرفوض'
    END                                     AS `الحالة`,
    IFNULL(u_req.full_name,'-')             AS `مقدم الطلب`,
    IFNULL(u_app.full_name,'-')             AS `الموافق`,
    br.requested_at                         AS `تاريخ الطلب`,
    br.processed_at                         AS `تاريخ التنفيذ`
FROM booking_refunds br
JOIN bus_flight_bookings bfb ON bfb.id = br.booking_id
LEFT JOIN currencies cur ON cur.id = br.refund_currency_id
LEFT JOIN users u_req ON u_req.id = br.requested_by
LEFT JOIN users u_app ON u_app.id = br.approved_by
ORDER BY br.id DESC;

-- (4) تقرير طلبات التعديل
SELECT
    bm.id                                       AS `رقم الطلب`,
    bfb.booking_number                          AS `الحجز`,
    bm.modification_reason                      AS `سبب التعديل`,
    bm.price_difference                         AS `الفرق المالي`,
    CASE bm.approval_status
        WHEN 'pending'   THEN 'قيد الانتظار'
        WHEN 'approved'  THEN 'تمت الموافقة'
        WHEN 'rejected'  THEN 'مرفوض'
        WHEN 'cancelled' THEN 'أُلغي الطلب'
    END                                         AS `حالة الطلب`,
    IFNULL(u_req.full_name, '-')                AS `المقدّم`,
    IFNULL(u_rev.full_name, '-')                AS `المراجع`,
    bm.created_at                               AS `تاريخ الطلب`,
    bm.reviewed_at                              AS `تاريخ المراجعة`
FROM booking_modifications bm
JOIN bus_flight_bookings bfb ON bfb.id = bm.booking_id
LEFT JOIN users u_req ON u_req.id = bm.requested_by
LEFT JOIN users u_rev ON u_rev.id = bm.reviewed_by
ORDER BY bm.id DESC;

-- (5) تقرير سير عمل الحجز (history) لحجز محدد
SELECT
    DATE_FORMAT(performed_at, '%Y-%m-%d %H:%i:%s') AS `الوقت`,
    CONCAT(COALESCE(from_stage,'-'), ' → ', to_stage) AS `الانتقال`,
    transition_notes                           AS `ملاحظات`,
    u.full_name                                AS `القيام بهذا التغيير`,
    extra_data                                 AS `بيانات إضافية`
FROM booking_workflow w
LEFT JOIN users u ON u.id = w.performed_by
WHERE w.booking_id = 25
ORDER BY w.id DESC;

-- (6) قائمة الإشعارات التي فشلت إعادة إرسالها (أكثر من 3 محاولات)
SELECT
    n.id                                          AS `الإشعار`,
    bfb.booking_number                            AS `الحجز`,
    n.notification_type                           AS `النوع`,
    n.delivery_channel                            AS `القناة`,
    LEFT(n.body_text, 100)                        AS `مقتطف النص`,
    n.retry_count                                 AS `عدد المحاولات`,
    n.last_error                                  AS `سبب الفشل`,
    n.scheduled_at                                AS `وقت الجدول`
FROM booking_notifications n
LEFT JOIN bus_flight_bookings bfb ON bfb.id = n.booking_id
WHERE n.status = 'failed' OR n.retry_count >= 3
ORDER BY n.updated_at DESC;

-- (7) البحث عن تذكرة برمز التحقق العام (public verify endpoint)
SELECT
    bt.ticket_number                              AS `ticket_number`,
    bt.traveler_name                              AS `traveler`,
    bt.id_type                                    AS `id_type`,
    bt.id_number                                  AS `id_number`,
    bt.departure_city_name,
    bt.arrival_city_name,
    bt.departure_datetime,
    bt.return_datetime,
    bt.service_type,
    bt.trip_type,
    bt.seat_number,
    bt.bus_flight_number,
    bt.total_amount,
    bt.currency_code,
    IF(bt.is_void=1, 'void', 'valid')             AS `ticket_status`,
    CASE
        WHEN bfb.booking_stage IN ('departed','completed') THEN 'completed_or_departed'
        WHEN bfb.booking_stage = 'cancelled'      THEN 'cancelled'
        ELSE 'ok'
    END                                           AS `booking_status`
FROM booking_tickets bt
JOIN bus_flight_bookings bfb ON bfb.id = bt.booking_id
WHERE bt.verification_token = 'TOKEN_PUBLIC_RECEIVED_FROM_URL'
LIMIT 1;


-- =============================================================
-- [الجزء السابع] شرح كيفية الدمج مع النظام الحالي (الفصول العملية)
-- =============================================================
--
-- ✅ الخطوة 1: تشغيل الملف على قاعدة البيانات
--    استخدم phpMyAdmin أو Adminer وقم بتشغيل الملف كله:
--    ➔ 014_booking_enhancement_system.sql
--    الملاحظة: نحن نستخدم ADD COLUMN IF NOT EXISTS لذا يمكن إعادة التشغيل بأمان.
--
-- ✅ الخطوة 2: التأكد من أن sp_log_error موجود
--    sp_log_error موجودة في dump الأصلي.
--    في حالة عدم وجودها قم بتشغيل أولاً:
--    tools/database/patches/patch_2026_07_17_error_logging_and_expense_refactors.sql
--
-- ✅ الخطوة 3: في كود PHP (bus_flight_bookings.php):
--
--    a) عند تأكيد الحجز عبر زر "تأكيد" في الصفحة:
--       <?php
--       $stmt = $pdo->prepare("CALL sp_confirm_booking(?,?,?)");
--       $stmt->execute([$booking_id, $_SESSION['admin_id'], $_POST['method'] ?? 'manual']);
--       $stmt->closeCursor();
--       header('Location: bus_flight_bookings.php?status=confirmed');
--
--    b) عند استدعاء "إلغاء الحجز" مع مبلغ استرداد:
--       $stmt = $pdo->prepare("CALL sp_cancel_booking(?,?,?,?,?,?,?,?,?, ?, @refund_id, @tx_id)");
--       $stmt->execute([ /* المعاملات التالية حسب التعريف: */
--           $_POST['booking_id'],
--           $_SESSION['admin_id'],
--           $_POST['cancel_reason'],
--           $_POST['refund_amount']        ?? null,
--           $_POST['currency_id']          ?? null,
--           $_POST['customer_account_id']  ?? null,
--           $_POST['cash_account_id']      ?? null,
--           $_POST['refund_method']        ?? 'cash',
--           $_POST['is_partial']           ?? 0,
--           0 /* لا تعالج المبلغ الآن، فقط أنشئ الطلب */
--       ]);
--       $stmt->closeCursor();
--       $out = $pdo->query("SELECT @refund_id AS refund_id, @tx_id AS tx_id")->fetch();
--
--    c) في صفحة إدارة التذاكر:
//       $stmt = $pdo->prepare("CALL sp_generate_ticket(?,?,?,?,?,?,?, @tid, @tnum)");
//       $stmt->execute([
//           $booking_id,
//           $_SESSION['admin_id'],
//           $_POST['seat']      ?? '',
//           $_POST['pnr']       ?? '',
//           $_POST['supp_ref']  ?? '',
//           $_POST['bf_num']    ?? '',
//           'https://your-domain.com/ticket/verify'
//       ]);
//       $stmt->closeCursor();
//       $ticket = $pdo->query("SELECT @tid AS id, @tnum AS number")->fetch();
//       // بعد ذلك احفظ id واستخدم مكتبة PHP QR لإنتاج صورة الـ QR
//       // من البيانات booking_tickets.qr_code_data
--
--    d) وحدة إشعارات الخلفية (cron job كل 5 دقائق):
//       SELECT id, booking_id, delivery_channel, subject_text, body_text
//       FROM   booking_notifications
//       WHERE  (status='pending' OR status='scheduled')
//         AND  (scheduled_at IS NULL OR scheduled_at <= NOW())
//       ORDER BY scheduled_at ASC, id ASC
//       LIMIT 50;
--
-- ✅ الخطوة 4: إضافة الأيقونات في admin/header.php إلى القائمة اليمنى:
//    ❯ الحجوزات → التذاكر الرقمية  → /admin/booking_tickets.php
//    ❯ الحجوزات → طلبات الاسترداد → /admin/booking_refunds.php
--    ❯ الحجوزات → طلبات التعديل → /admin/booking_modifications.php
--    ❯ المراسلات → إشعارات الحجوزات → /admin/booking_notifications.php
--
*/
-- =============================================================
-- ✅ الخطوة 5: إضافة صلاحيات الجداول الجديدة إلى unified_permissions
-- =============================================================
INSERT INTO `unified_permissions` (`code`, `name_ar`, `category`, `description`) VALUES
    ('booking_confirm',         'تأكيد الحجوزات',                'bookings', 'السماح بتأكيد الحجوزات المعلقة'),
    ('booking_modify_request',  'إنشاء طلب تعديل الحجز',         'bookings', 'إنشاء طلب تعديل بيانات حجز موجود'),
    ('booking_modify_approve',  'الموافقة على تعديل الحجز',      'bookings', 'الموافقة أو رفض طلبات تعديل الحجوزات'),
    ('booking_cancel',          'إلغاء الحجوزات',                'bookings', 'إلغاء حجز مع إمكانية طلب استرداد'),
    ('booking_refund_approve',  'الموافقة على طلبات الاسترداد',  'bookings', 'مراجعة وموافقة طلبات استرداد أموال الحجوزات'),
    ('booking_ticket_issue',    'إصدار التذاكر الرقمية',         'bookings', 'إصدار التذاكر الرقمية لحجوزات الطيران والباصات'),
    ('booking_notifications',   'إدارة إشعارات الحجوزات',       'reports',  'إرسال ومتابعة إشعارات الحجوزات عبر البريد والواتساب والرسائل')
ON DUPLICATE KEY UPDATE
    `name_ar` = VALUES(`name_ar`),
    `description` = VALUES(`description`);

-- =============================================================
-- نهاية ملف 014_booking_enhancement_system.sql
-- =============================================================

SET FOREIGN_KEY_CHECKS = 1;
