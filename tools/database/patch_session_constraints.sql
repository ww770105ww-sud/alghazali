DELIMITER $$

-- 1. إضافة قيود فريدة لجدول الأجهزة المحظورة لمنع التكرار
DROP PROCEDURE IF EXISTS `_patch_add_blocked_unique`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `_patch_add_blocked_unique`()
MODIFIES SQL DATA
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;

    -- Clean duplicate blocked devices first: keep only latest per (user_id, fingerprint)
    DELETE bd1 FROM blocked_devices bd1
    INNER JOIN blocked_devices bd2
        ON  bd1.user_id = bd2.user_id
        AND bd1.device_fingerprint = bd2.device_fingerprint
        AND bd1.id < bd2.id;

    -- Drop legacy duplicates, then add unique index
    SET @drop_idx_exists = (
        SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name   = 'blocked_devices'
           AND index_name   = 'uniq_user_device');
    SET @sql_drop = IF(@drop_idx_exists > 0,
        'ALTER TABLE blocked_devices DROP INDEX uniq_user_device',
        'SELECT 1');
    PREPARE stmt_drop FROM @sql_drop; EXECUTE stmt_drop; DEALLOCATE PREPARE stmt_drop;

    ALTER TABLE blocked_devices
        ADD CONSTRAINT uniq_user_device UNIQUE (user_id, device_fingerprint);

    -- Add composite indexes for common lookups
    SET @idx1 = (SELECT COUNT(*) FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name='blocked_devices' AND index_name='idx_active_user');
    SET @sql1 = IF(@idx1 = 0,
        'ALTER TABLE blocked_devices ADD INDEX idx_active_user (is_active, user_id)',
        'SELECT 1');
    PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;

    SET @idx2 = (SELECT COUNT(*) FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name='user_sessions' AND index_name='idx_user_status');
    SET @sql2 = IF(@idx2 = 0,
        'ALTER TABLE user_sessions ADD INDEX idx_user_status (user_id, status)',
        'SELECT 1');
    PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

    SET @idx3 = (SELECT COUNT(*) FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name='user_sessions' AND index_name='idx_sid_status');
    SET @sql3 = IF(@idx3 = 0,
        'ALTER TABLE user_sessions ADD INDEX idx_sid_status (session_id, status)',
        'SELECT 1');
    PREPARE s3 FROM @sql3; EXECUTE s3; DEALLOCATE PREPARE s3;

    SET @idx4 = (SELECT COUNT(*) FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name='user_activity_logs' AND index_name='idx_user_created');
    SET @sql4 = IF(@idx4 = 0,
        'ALTER TABLE user_activity_logs ADD INDEX idx_user_created (user_id, created_at)',
        'SELECT 1');
    PREPARE s4 FROM @sql4; EXECUTE s4; DEALLOCATE PREPARE s4;
END$$
DELIMITER ;

CALL `_patch_add_blocked_unique`();
DROP PROCEDURE IF EXISTS `_patch_add_blocked_unique`;
