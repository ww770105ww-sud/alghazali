<?php
/**
 * إعدادات الاتصال بقاعدة البيانات - وكالة الغزالي للسفريات والسياحة
 * محدث لاستخدام متغيرات البيئة
 */

// Include session configuration first to ensure consistent session handling
require_once __DIR__ . '/session_config.php';

// دالة بسيطة لتحميل متغيرات البيئة من ملف .env
/**
 * @param string $path
 * @return void
 */
function load_env(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) < 2) continue;
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if (empty($key)) continue;
        if (!getenv($key)) putenv("$key=$value");
        if (!isset($_ENV[$key])) $_ENV[$key] = $value;
        if (!isset($_SERVER[$key])) $_SERVER[$key] = $value;
    }
}

// تحميل ملف .env إذا كان موجوداً
load_env(__DIR__ . '/../.env');

// اكتشاف البيئة تلقائياً (محلي أم استضافة خارجية)
$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
$http_host = $_SERVER['HTTP_HOST'] ?? '';

$is_local = (php_sapi_name() === 'cli') ||
    in_array($remote_addr, ['127.0.0.1', '::1']) ||
    strpos($http_host, 'localhost') !== false ||
    strpos($http_host, '127.0.0.1') !== false ||
    strpos($http_host, '192.168.') !== false ||
    strpos($http_host, '10.') !== false ||
    strpos($http_host, 'manus.computer') !== false;

// الحصول على إعدادات الاتصال من متغيرات البيئة
$has_env = !empty(getenv('DB_HOST'));
if ($has_env) {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USER') ?: 'root';
    $db = getenv('DB_NAME') ?: 'alghazali';
    $pass = getenv('DB_PASS') ?: '';
} else {
    $host = '127.0.0.1';
    $user = 'root';
    $db = 'alghazali';
    $pass = '';
}

$charset = 'utf8mb4';
$collation = 'utf8mb4_unicode_ci';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];

try {
    ini_set('default_charset', 'UTF-8');
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }

    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE $collation");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    $pdo->exec("SET collation_connection = $collation");
    $pdo->exec("SET collation_database = $collation");
    $pdo->exec("SET collation_server = $collation");

    $pdo->exec("SET time_zone = '+03:00'");
    // تعطيل ONLY_FULL_GROUP_BY لضمان عمل الاستعلامات المعقدة
    $pdo->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

    // Initialize SafeDB class
    require_once __DIR__ . '/SafeDB.php';
    $safeDb = new SafeDB($pdo);

    // Register system error audit logger
    require_once __DIR__ . '/system_error_audit.php';
    register_system_error_audit($pdo);
} catch (\PDOException $e) {
    // إذا كان الطلب AJAX، أرجع JSON
    if (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    ) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => true, 'message' => 'خطأ في الاتصال بقاعدة البيانات. يرجى التحقق من إعدادات MySQL.']);
    } else {
        http_response_code(503);
        echo '<div style="direction:rtl;font-family:Arial;padding:20px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:8px;margin:20px;">';
        echo '<h3 style="color:#721c24">⚠️ خطأ في الاتصال بقاعدة البيانات</h3>';
        echo '<p>يرجى التأكد من تشغيل MySQL وصحة بيانات الاتصال.</p>';
        echo '<small style="color:#999">' . htmlspecialchars($e->getMessage()) . '</small>';
        echo '</div>';
    }
    exit();
}
