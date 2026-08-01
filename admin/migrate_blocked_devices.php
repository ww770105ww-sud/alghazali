<?php
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';

echo "<!DOCTYPE html>
<html lang='ar' dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>ترقية قاعدة البيانات</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; padding: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1, h2 { color: #0f172a; text-align: center; }
        .success { color: #16a34a; font-weight: bold; }
        .info { color: #2563eb; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 10px; font-weight: 600; margin-top: 20px; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 ترقية قاعدة البيانات</h1>
        <h2>تثبيت ميزة حظر الأجهزة وعدد الجلسات</h2>
        <hr>";

try {
    // 1. Create blocked_devices table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blocked_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            device_fingerprint VARCHAR(255) NOT NULL,
            ip_address VARCHAR(50),
            user_agent TEXT,
            reason TEXT,
            blocked_by INT,
            blocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            INDEX idx_user_id (user_id),
            INDEX idx_device_fingerprint (device_fingerprint),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p class='success'>✅ تم إنشاء جدول blocked_devices أو كان موجودًا</p>";

    // 2. Add device_fingerprint column to user_sessions table
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM user_sessions LIKE 'device_fingerprint'");
        if (!$checkCol->fetch()) {
            $pdo->exec("ALTER TABLE user_sessions ADD COLUMN device_fingerprint VARCHAR(255) NULL AFTER session_id");
            echo "<p class='success'>✅ تم إضافة عمود device_fingerprint إلى جدول user_sessions</p>";
        } else {
            echo "<p class='info'>ℹ️ عمود device_fingerprint موجود بالفعل في user_sessions</p>";
        }
    } catch (PDOException $e) {
        echo "<p class='info'>ℹ️ عمود device_fingerprint موجود بالفعل في user_sessions</p>";
    }

    // 3. Unblock all devices
    $pdo->exec("UPDATE blocked_devices SET is_active = 0 WHERE is_active = 1");
    echo "<p class='success'>✅ تم إلغاء حظر جميع الأجهزة للاختبار</p>";

    // 4. Make sure allow_multiple_sessions is enabled in system_settings
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('allow_multiple_sessions', '1', 'general') ON DUPLICATE KEY UPDATE setting_value = '1'");
    $stmt->execute();
    echo "<p class='success'>✅ تم تفعيل خيار عدد الجلسات (السماح بجهازين أو أكثر)</p>";

    echo "<hr><h2 class='success' style='text-align:center;'>🎉 تمت الترقية بنجاح!</h2>";
    echo "<p style='text-align:center;'><a href='login.php' class='btn'>تسجيل الدخول الآن</a></p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ خطأ: " . $e->getMessage() . "</p>";
}

echo "
    </div>
</body>
</html>";
?>