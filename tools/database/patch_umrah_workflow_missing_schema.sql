-- ================================================================
-- باتش إنشاء الأعمدة والجداول المفقودة لنظام سير العمل للعمرة
-- التوافق: MariaDB 10.4.28+
-- تطبق على قاعدة البيانات: ghazali
-- ================================================================

-- 1) إضافة عمود workflow_step_id إلى جدول passports (إن لم يوجد)
--    هذا العمود يربط المعاملة مباشرة بالمرحلة الحالية في workflow_steps
SET @dbname = DATABASE();
SET @tablename = 'passports';
SET @columnname = 'workflow_step_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname
     AND TABLE_NAME = @tablename
     AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE `passports` ADD COLUMN `workflow_step_id` INT(11) NULL DEFAULT NULL AFTER `workflow_id`, ADD INDEX `idx_passports_workflow_step` (`workflow_step_id`)'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ================================================================
-- 2) إنشاء جدول workflow_checklist (قوائم التحقق لكل معاملة لكل شرط)
-- ================================================================
CREATE TABLE IF NOT EXISTS `workflow_checklist` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `passport_id` INT(11) NOT NULL COMMENT 'معرف المعاملة / الجواز',
    `booking_id` INT(11) NULL DEFAULT NULL COMMENT 'معرف الحجز (إن وجد)',
    `requirement_id` INT(11) NOT NULL COMMENT 'معرف شرط الوثيقة/المستند',
    `requirement_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'اسم الشرط عند لحظة التسجيل',
    `verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = لم يتم التحقق, 1 = تم التحقق',
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `verified_by` INT(11) NULL DEFAULT NULL COMMENT 'من قام بالتحقق',
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_passport_requirement` (`passport_id`, `requirement_id`),
    INDEX `idx_workflow_checklist_passport` (`passport_id`),
    INDEX `idx_workflow_checklist_booking` (`booking_id`),
    INDEX `idx_workflow_checklist_verified` (`verified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوائم التحقق من الوثائق والمتطلبات لكل معاملة';

-- ================================================================
-- 3) إنشاء جدول workflow_logs (سجل تفصيلي لتغييرات المراحل)
-- ================================================================
CREATE TABLE IF NOT EXISTS `workflow_logs` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `passport_id` INT(11) NULL DEFAULT NULL,
    `booking_id` INT(11) NULL DEFAULT NULL,
    `from_step_id` INT(11) NULL DEFAULT NULL,
    `to_step_id` INT(11) NOT NULL,
    `from_status_id` INT(11) NULL DEFAULT NULL,
    `to_status_id` INT(11) NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `extra_data` JSON NULL DEFAULT NULL COMMENT 'حقول إضافية تم تحديثها مع الانتقال',
    `created_by` INT(11) NOT NULL COMMENT 'المستخدم الذي نفذ الانتقال',
    `created_by_role` INT(11) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(512) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_wf_logs_passport` (`passport_id`),
    INDEX `idx_wf_logs_booking` (`booking_id`),
    INDEX `idx_wf_logs_to_step` (`to_step_id`),
    INDEX `idx_wf_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل عمليات الانتقال بين مراحل سير العمل';

-- ================================================================
-- 4) إنشاء جدول document_requirements (متطلبات الوثائق حسب المهنة/النوع)
-- ================================================================
CREATE TABLE IF NOT EXISTS `document_requirements` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `requirement_name` VARCHAR(255) NOT NULL COMMENT 'اسم الوثيقة أو الشرط (مثل: صورة الجواز)',
    `requirement_type` ENUM('document','check','payment','approval') NOT NULL DEFAULT 'document',
    `transaction_type` VARCHAR(50) NULL DEFAULT NULL COMMENT 'umrah, work_visa, visa, booking, all',
    `profession_id` INT(11) NULL DEFAULT NULL COMMENT 'إذا كان الشرط خاصاً بمحنة محددة',
    `nationality_id` INT(11) NULL DEFAULT NULL COMMENT 'إذا كان خاصاً بجنسية محددة',
    `gender` ENUM('Male','Female','Any') NOT NULL DEFAULT 'Any',
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `description` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_docreq_trx_type` (`transaction_type`),
    INDEX `idx_docreq_profession` (`profession_id`),
    INDEX `idx_docreq_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قائمة متطلبات الوثائق للمعاملات المختلفة';

-- ================================================================
-- 5) بيانات أولية (متطلبات افتراضية للعمرة)
-- ================================================================
INSERT IGNORE INTO `document_requirements`
    (`requirement_name`, `requirement_type`, `transaction_type`, `gender`, `sort_order`, `is_required`, `is_active`, `description`)
VALUES
    ('صورة الجواز (صفحة المعلومات)', 'document', 'umrah', 'Any', 1, 1, 1, 'صفحة المعلومات الشخصية في الجواز واضحة'),
    ('الصورة الشخصية',            'document', 'umrah', 'Any', 2, 1, 1, 'صورة شخصية حديثة 4x6 على الأقل'),
    ('تصريح الإحرام (المهرب)',    'document', 'umrah', 'Any', 3, 0, 1, 'إن وجدت حسب متطلبات الجهة'),
    ('بطاقة السفر / العقد',       'document', 'umrah', 'Any', 4, 0, 1, 'عقد الحجز مع الوكالة'),
    ('تأشيرة الخروج النهائية',    'document', 'umrah', 'Any', 5, 0, 1, 'طبعة تأشيرة الخروج من السعودية'),
    ('تأكيد استلام جواز العميل',  'check',    'umrah', 'Any', 6, 1, 1, 'توقيع العميل أو الوكيل باستلام الجواز بعد انتهاء الخدمة'),
    ('تأكيد الحجز في المظلة',     'check',    'umrah', 'Any', 7, 0, 1, 'إشعار تأكيد حجز المظلة والمرافق');

-- ================================================================
-- نهاية الباتش
-- ================================================================
