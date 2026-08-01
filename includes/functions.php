<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/tafqeet.php';

/**
 * XSS Protection helper
 */
function h($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

if (!function_exists('normalize_datetime_db')) {
    function normalize_datetime_db($value = null, $default = 'now')
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null || $value === '') {
            if ($default === null) {
                return null;
            }

            if ($default === 'now') {
                return date('Y-m-d H:i:s');
            }

            return normalize_datetime_db($default, null);
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int)$value);
        }

        $value = trim((string)$value);
        if ($value === '') {
            return normalize_datetime_db(null, $default);
        }

        $timestamp = strtotime(str_replace('T', ' ', $value));
        if ($timestamp === false) {
            return normalize_datetime_db(null, $default);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('format_datetime_local_value')) {
    function format_datetime_local_value($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i');
        }

        $timestamp = is_numeric($value) ? (int)$value : strtotime(str_replace('T', ' ', (string)$value));
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d\TH:i', $timestamp);
    }
}

if (!function_exists('format_datetime_display')) {
    function format_datetime_display($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        $timestamp = is_numeric($value) ? (int)$value : strtotime(str_replace('T', ' ', (string)$value));
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d H:i', $timestamp);
    }
}

if (!function_exists('normalize_service_display_name')) {
    function normalize_service_display_name($source_type)
    {
        $display_names = [
            'flight' => 'الطيران',
            'bus' => 'النقل البري',
            'passport' => 'جوازات السفر',
            'umrah' => 'العمرة',
            'hajj' => 'الحج',
            'family_visit' => 'الزيارة العائلية',
            'work_visa' => 'فيز العمل',
            'postal_services' => 'الخدمات البريدية',
            'general' => 'عام'
        ];
        
        $source_type = trim((string)$source_type);
        
        // Check aliases
        $all_aliases = array_merge(
            get_umrah_service_aliases(),
            get_hajj_service_aliases(),
            get_postal_service_aliases()
        );
        
        if (in_array($source_type, $all_aliases)) {
            if (is_umrah_service($source_type)) {
                return 'العمرة';
            } elseif (is_hajj_service($source_type)) {
                return 'الحج';
            } elseif (is_postal_service($source_type)) {
                return 'الخدمات البريدية';
            }
        }
        
        // Check the mapping in getServiceInvoiceConfig
        $settings = [];
        $config_mapping = [
            'النقل البري' => 'النقل البري',
            'تذاكر طيران وبصات' => 'الطيران',
            'bus_flight_bookings' => 'الطيران',
            'تذكر طيران' => 'الطيران',
            'الطيران' => 'الطيران',
            'bus' => 'النقل البري',
            'flight' => 'الطيران',
            'جوازت السفر' => 'جوازات السفر',
            'حج وعمرة' => 'العمرة',
            'خدمات العمرة' => 'العمرة',
            'خدمات الحج والعمرة' => 'العمرة',
            'umrah' => 'العمرة',
            'خدمات الحج' => 'الحج',
            'hajj' => 'الحج',
            'الزيارة العائلية' => 'الزيارة العائلية',
            'family_visit' => 'الزيارة العائلية',
            'فيز العمل' => 'فيز العمل',
            'work_visa' => 'فيز العمل',
            'postal_services' => 'الخدمات البريدية'
        ];
        
        return $config_mapping[$source_type] ?? $display_names[$source_type] ?? $source_type;
    }
}

/**
 * CSRF Token Generation
 */
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF Token Verification
 */
function verify_csrf_token($token)
{
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF Hidden Input
 */
function csrf_input()
{
    return '<input type="hidden" name="csrf_token" value="' . generate_csrf_token() . '">';
}

/**
 * Direct File Access Protection
 */
function check_access()
{
    if (!defined('SYSTEM_ACCESS')) {
        header('HTTP/1.0 403 Forbidden');
        echo "Direct access denied.";
        exit;
    }
}

function getSettings($pdo)
{
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}

function getNews($pdo, $limit = 5)
{
    $stmt = $pdo->prepare("SELECT * FROM news WHERE is_active = 1 ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getServices($pdo)
{
    $stmt = $pdo->query("
        SELECT s.*, c.currency_name, c.currency_symbol
        FROM services s
        LEFT JOIN currencies c ON s.currency_id = c.id
        ORDER BY s.created_at DESC
    ");
    return $stmt->fetchAll();
}

function logVisit($pdo)
{
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $pdo->prepare("INSERT INTO statistics (type, ip_address) VALUES ('visit', ?)");
    $stmt->execute([$ip]);
}

function logQuery($pdo)
{
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $pdo->prepare("INSERT INTO statistics (type, ip_address) VALUES ('query', ?)");
    $stmt->execute([$ip]);
}

/**
 * SEO Functions
 */

// توليد وصف Meta ذكي بناءً على الصفحة
function getPageDescription($settings, $currentPage = 'index')
{
    $base_desc = $settings['meta_description'] ?? '';
    switch ($currentPage) {
        case 'about':
            return "تعرف على " . $settings['site_name'] . "، وكالتكم الموثوقة للسفر والسياحة والحج والعمرة في اليمن. خبرة طويلة وخدمات متميزة.";
        case 'services':
            return "استكشف خدمات " . $settings['site_name'] . ": تأشيرات، تذاكر طيران، رحلات سياحية، وبرامج حج وعمرة متكاملة بأفضل الأسعار.";
        case 'contact':
            return "تواصل مع " . $settings['site_name'] . " للاستفسار عن خدمات السفر والسياحة. نحن هنا لخدمتكم على مدار الساعة.";
        default:
            return $base_desc;
    }
}

// توليد كلمات مفتاحية ذكية بناءً على الصفحة
function getPageKeywords($settings, $currentPage = 'index')
{
    $base_keys = $settings['meta_keywords'] ?? '';
    $extra = "سفريات اليمن، وكالة سياحة، حجز تذاكر، فيزا، حج وعمرة";
    switch ($currentPage) {
        case 'about':
            return "عن الوكالة، من نحن، خبرة الغزالي، " . $base_keys;
        case 'services':
            return "خدمات سفر، حجز طيران، برامج عمرة، رحلات سياحية، " . $base_keys;
        case 'contact':
            return "اتصل بنا، تواصل، رقم مكتب الغزالي، عنوان الوكالة، " . $base_keys;
        default:
            return $base_keys . ", " . $extra;
    }
}

// توليد Schema Markup (JSON-LD) لوكالة السفر
function generateSchemaMarkup($settings)
{
    $schema = [
        "@context" => "https://schema.org",
        "@type" => "TravelAgency",
        "name" => $settings['site_name'],
        "image" => "https://" . $_SERVER['HTTP_HOST'] . "/assets/uploads/" . $settings['site_logo'],
        "@id" => "https://" . $_SERVER['HTTP_HOST'],
        "url" => "https://" . $_SERVER['HTTP_HOST'],
        "telephone" => $settings['phone'],
        "email" => $settings['email'],
        "address" => [
            "@type" => "PostalAddress",
            "streetAddress" => $settings['address'],
            "addressLocality" => "Sana'a",
            "addressCountry" => "YE"
        ],
        "geo" => [
            "@type" => "GeoCoordinates",
            "latitude" => $settings['latitude'],
            "longitude" => $settings['longitude']
        ],
        "sameAs" => [] // سيتم ملؤه من روابط التواصل
    ];
    return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
/**
 * Voucher Template Engine
 */

function getVoucherTemplate($pdo, $service_id = null, $type = 'receipt')
{
    // 1. البحث عن القالب الافتراضي لهذه الخدمة تحديداً
    if ($service_id) {
        $stmt = $pdo->prepare("SELECT * FROM voucher_templates WHERE service_id = ? AND is_default = 1 AND status = 1 LIMIT 1");
        $stmt->execute([$service_id]);
        $template = $stmt->fetch();
        if ($template) return $template;
    }

    // 2. البحث عن قالب افتراضي عام لنوع السند (قبض/صرف)
    $stmt = $pdo->prepare("SELECT * FROM voucher_templates WHERE template_type = ? AND is_default = 1 AND status = 1 LIMIT 1");
    $stmt->execute([$type]);
    $template = $stmt->fetch();
    if ($template) return $template;

    // 3. العودة لآخر قالب مضاف لنوع السند إذا لم يوجد افتراضي
    $stmt = $pdo->prepare("SELECT * FROM voucher_templates WHERE template_type = ? AND status = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$type]);
    return $stmt->fetch();
}

function parseVoucherContent($content, $data)
{
    // قائمة بكافة الحقول الديناميكية الممكنة
    $tags = [
        '{{receipt_no}}' => $data['receipt_no'] ?? '',
        '{{payment_no}}' => $data['payment_no'] ?? '',
        '{{transaction_no}}' => $data['transaction_no'] ?? '',
        '{{receipt_date}}' => $data['receipt_date'] ?? date('Y-m-d'),
        '{{created_at}}' => $data['created_at'] ?? date('Y-m-d H:i'),
        '{{customer_name}}' => $data['customer_name'] ?? '',
        '{{customer_phone}}' => $data['customer_phone'] ?? '',
        '{{passport_no}}' => $data['passport_no'] ?? '',
        '{{service_name}}' => $data['service_name'] ?? '',
        '{{amount}}' => number_format($data['amount'] ?? 0, 2),
        '{{currency}}' => $data['currency'] ?? '',
        '{{amount_in_words}}' => $data['amount_in_words'] ?? '',
        '{{remaining_amount}}' => number_format($data['remaining_amount'] ?? 0, 2),
        '{{description}}' => $data['description'] ?? '',
        '{{notes}}' => $data['notes'] ?? '',
        '{{travel_date}}' => $data['travel_date'] ?? '',
        '{{reference_no}}' => $data['reference_no'] ?? '',
        '{{company_name}}' => $data['company_name'] ?? '',
        '{{branch_name}}' => $data['branch_name'] ?? '',
        '{{branch_address}}' => $data['branch_address'] ?? '',
        '{{branch_phone}}' => $data['branch_phone'] ?? '',
        '{{printed_by}}' => $data['printed_by'] ?? '',
        '{{created_by}}' => $data['created_by'] ?? '',
        '{{logo}}' => isset($data['logo']) ? '<img src="../assets/uploads/' . $data['logo'] . '" style="max-height:80px;">' : '',
        '{{stamp}}' => isset($data['stamp']) ? '<img src="../assets/uploads/' . $data['stamp'] . '" style="max-height:100px;opacity:0.7;">' : '',
        '{{signature}}' => isset($data['signature']) ? '<img src="../assets/uploads/' . $data['signature'] . '" style="max-height:60px;">' : ''
    ];

    foreach ($tags as $tag => $val) {
        $content = str_replace($tag, $val, $content);
    }
    return $content;
}

/**
 * Workflow & Permissions Functions
 */

function getServiceInvoiceConfig($source_type, $settings)
{
    $mapping = [
        'النقل البري' => 'flight',
        'تذاكر طيران وبصات' => 'flight',
        'bus_flight_bookings' => 'flight',
        'تذكر طيران' => 'flight',
        'الطيران' => 'flight',
        'bus' => 'flight',
        'flight' => 'flight',
        'حجوزات الطيران' => 'flight',
        'حجوزات الباصات' => 'flight',
        'جوازت السفر' => 'passport',
        'معاملات جوازات' => 'passport',
        'حج وعمرة' => 'umrah',
        'قسم العمرة' => 'umrah',
        'خدمات العمرة' => 'umrah',
        'خدمات الحج والعمرة' => 'umrah',
        'umrah' => 'umrah',
        'خدمات الحج' => 'hajj',
        'hajj' => 'hajj',
        'الزيارة العائلية' => 'family_visit',
        'FamilyVisit' => 'family_visit',
        'family_visit' => 'family_visit',
        'فيز العمل' => 'work_visa',
        'work_visa' => 'work_visa',
        'postal_services' => 'postal_services',
        'الخدمات البريدية' => 'postal_services',
        'خدمات البريد' => 'postal_services',
        'postal' => 'postal_services',
        'general' => 'general'
    ];
    $key = $mapping[$source_type] ?? 'general';

    // إعدادات افتراضية
    $res = [
        'sales_prefix' => $settings['sales_invoice_prefix'] ?? 'SAL-',
        'purchase_prefix' => $settings['purchase_invoice_prefix'] ?? 'PUR-',
        'digits' => (int)($settings['invoice_number_digits'] ?? 6),
        'revenue_account_id' => $settings['default_sales_account_id'] ?? null,
        'cost_account_id' => $settings['default_cost_account_id'] ?? null,
        'profit_account_id' => $settings['default_profit_account_id'] ?? null
    ];

    if ($key !== 'general') {
        $res['sales_prefix'] = $settings["srv_{$key}_sales_prefix"] ?? $res['sales_prefix'];
        $res['purchase_prefix'] = $settings["srv_{$key}_purchase_prefix"] ?? $res['purchase_prefix'];
        $res['digits'] = (int)($settings["srv_{$key}_digits"] ?? $res['digits']);
        $res['revenue_account_id'] = $settings["revenue_{$key}_account_id"] ?? $res['revenue_account_id'];
        $res['cost_account_id'] = $settings["cost_{$key}_account_id"] ?? $res['cost_account_id'];
        $res['profit_account_id'] = $settings["profit_{$key}_account_id"] ?? $res['profit_account_id'];
    }

    return $res;
}

/**
 * Invoice Numbering Logic
 */

function generateInvoiceNumber($pdo, $source_type, $category, $settings, $fixed_number = null)
{
    $config = getServiceInvoiceConfig($source_type, $settings);
    $s_prefix = $config['sales_prefix'];
    $p_prefix = $config['purchase_prefix'];
    $prefix = ($category === 'sales') ? $s_prefix : $p_prefix;
    $digits = $config['digits'];

    if ($fixed_number === null) {
        // Find the next sequence number by checking BOTH prefixes to keep them synced
        $stmt = $pdo->prepare("
            SELECT MAX(num) FROM (
                SELECT CAST(SUBSTRING(invoice_number, LENGTH(?) + 1) AS UNSIGNED) as num FROM invoices WHERE invoice_number LIKE ?
                UNION
                SELECT CAST(SUBSTRING(invoice_number, LENGTH(?) + 1) AS UNSIGNED) as num FROM invoices WHERE invoice_number LIKE ?
            ) as combined
        ");
        $stmt->execute([$s_prefix, $s_prefix . '%', $p_prefix, $p_prefix . '%']);
        $last_number = (int)$stmt->fetchColumn();
        $fixed_number = $last_number + 1;
    }

    return [
        'number' => $prefix . str_pad($fixed_number, $digits, '0', STR_PAD_LEFT),
        'numeric_part' => $fixed_number
    ];
}

function has_permission($perm_name)
{
    global $pdo;
    static $request_permission_cache = [];
    static $request_super_user_cache = [];

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
    if (!$user_id) return false;

    if (!array_key_exists($user_id, $request_super_user_cache)) {
        $stmt = $pdo->prepare("SELECT r.name, u.user_type FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch();

        $is_super_user = false;
        if ($user_info) {
            $role = mb_strtolower((string)$user_info['name'], 'UTF-8');
            $user_type = mb_strtolower((string)$user_info['user_type'], 'UTF-8');
            $super_roles = ['admin', 'developer', 'super_admin', 'مدير', 'مبرمج', 'مطور'];
            $is_super_user = in_array($role, $super_roles, true) || in_array($user_type, $super_roles, true);
        }

        $request_super_user_cache[$user_id] = $is_super_user;
    }

    if ($request_super_user_cache[$user_id]) {
        return true;
    }

    if (!array_key_exists($user_id, $request_permission_cache)) {
        $stmt = $pdo->prepare("
            SELECT p.permission_code
            FROM role_permissions_unified rp
            JOIN unified_permissions p ON rp.permission_id = p.id
            JOIN users u ON u.role_id = rp.role_id
            WHERE u.id = ?
              AND (rp.target_type IS NULL OR rp.target_type = '')
        ");
        $stmt->execute([$user_id]);

        $request_permission_cache[$user_id] = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        $_SESSION['perms'] = $request_permission_cache[$user_id];
    }

    return isset($request_permission_cache[$user_id][$perm_name]);
}

function generateDeviceFingerprint()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown';

    return hash('sha256', $ip . '|' . $userAgent . '|' . $acceptLanguage);
}

function ensureUserSessionTables()
{
    global $pdo;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(255) NOT NULL,
            device_fingerprint VARCHAR(255) NULL,
            ip_address VARCHAR(50),
            user_agent TEXT,
            device_type VARCHAR(50),
            browser VARCHAR(100),
            operating_system VARCHAR(100),
            timezone VARCHAR(100),
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL,
            status ENUM('active', 'ended', 'terminated') DEFAULT 'active',
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id),
            INDEX idx_status (status),
            INDEX idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'device_fingerprint' => "ALTER TABLE user_sessions ADD COLUMN device_fingerprint VARCHAR(255) NULL AFTER session_id",
        'browser' => "ALTER TABLE user_sessions ADD COLUMN browser VARCHAR(100) NULL AFTER device_type",
        'operating_system' => "ALTER TABLE user_sessions ADD COLUMN operating_system VARCHAR(100) NULL AFTER browser",
        'timezone' => "ALTER TABLE user_sessions ADD COLUMN timezone VARCHAR(100) NULL AFTER operating_system",
        'last_activity' => "ALTER TABLE user_sessions ADD COLUMN last_activity DATETIME DEFAULT CURRENT_TIMESTAMP AFTER status"
    ];

    foreach ($columns as $column => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM user_sessions LIKE " . $pdo->quote($column));
        if (!$check->fetch()) {
            $pdo->exec($sql);
        }
    }

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
}

function ensureUserActivityLogTable()
{
    global $pdo;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(100) NOT NULL,
            full_name VARCHAR(255),
            activity_type VARCHAR(100) NOT NULL,
            activity_description TEXT,
            ip_address VARCHAR(50),
            user_agent TEXT,
            device_type VARCHAR(50),
            browser VARCHAR(100),
            operating_system VARCHAR(100),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_activity_type (activity_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'browser' => "ALTER TABLE user_activity_logs ADD COLUMN browser VARCHAR(100) NULL AFTER device_type",
        'operating_system' => "ALTER TABLE user_activity_logs ADD COLUMN operating_system VARCHAR(100) NULL AFTER browser"
    ];

    foreach ($columns as $column => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM user_activity_logs LIKE " . $pdo->quote($column));
        if (!$check->fetch()) {
            $pdo->exec($sql);
        }
    }
}

function isDeviceBlocked($user_id, $device_fingerprint)
{
    global $pdo;

    try {
        ensureUserSessionTables();
        $stmt = $pdo->prepare("
            SELECT id
            FROM blocked_devices
            WHERE user_id = ?
              AND device_fingerprint = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$user_id, $device_fingerprint]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        error_log("Device block check error: " . $e->getMessage());
        return false;
    }
}

function createUserSession($user_id)
{
    global $pdo;

    try {
        ensureUserSessionTables();

        $settings = getSettings($pdo);
        $allowMultiple = !empty($settings['allow_multiple_sessions']) && $settings['allow_multiple_sessions'] !== '0';
        $sessionBehavior = $settings['session_behavior'] ?? 'terminate_old';

        if (!$allowMultiple) {
            if ($sessionBehavior === 'reject_new') {
                $active = $pdo->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND status = 'active'");
                $active->execute([$user_id]);
                if ((int)$active->fetchColumn() > 0) {
                    return ['success' => false, 'message' => 'لديك جلسة نشطة بالفعل. يرجى تسجيل الخروج من الجهاز الآخر أولاً.'];
                }
            } else {
                $stmt = $pdo->prepare("UPDATE user_sessions SET status = 'terminated', ended_at = NOW() WHERE user_id = ? AND status = 'active'");
                $stmt->execute([$user_id]);
            }
        }

        $ua = parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO user_sessions
                (user_id, session_id, device_fingerprint, ip_address, user_agent, device_type, browser, operating_system, timezone, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $user_id,
            session_id(),
            generateDeviceFingerprint(),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $ua['device'] ?? null,
            $ua['browser'] ?? null,
            $ua['os'] ?? null,
            $settings['timezone'] ?? date_default_timezone_get()
        ]);

        return ['success' => true, 'session_id' => $pdo->lastInsertId()];
    } catch (Throwable $e) {
        error_log("Create user session error: " . $e->getMessage());
        return ['success' => false, 'message' => 'تعذر إنشاء جلسة المستخدم.'];
    }
}

function updateSessionActivity($session_id = null)
{
    global $pdo;

    try {
        ensureUserSessionTables();
        $session_id = $session_id ?: session_id();
        $stmt = $pdo->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ? AND status = 'active'");
        $stmt->execute([$session_id]);
        return true;
    } catch (Throwable $e) {
        error_log("Update session activity error: " . $e->getMessage());
        return false;
    }
}

function terminateUserSession($session_id)
{
    global $pdo;

    try {
        ensureUserSessionTables();
        $stmt = $pdo->prepare("SELECT us.*, u.username, u.full_name FROM user_sessions us LEFT JOIN users u ON u.id = us.user_id WHERE us.id = ?");
        $stmt->execute([(int)$session_id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE user_sessions SET status = 'terminated', ended_at = NOW() WHERE id = ? AND status = 'active'");
            $upd->execute([(int)$session_id]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                return true;
            }
            if (!empty($session['session_id'])) {
                try {
                    $saveHandler = ini_get('session.save_handler');
                    if ($saveHandler === 'files') {
                        $savePath = session_save_path() ?: sys_get_temp_dir();
                        $file = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $session['session_id'];
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }
                } catch (Throwable $e2) {
                    error_log("Session file cleanup warning: " . $e2->getMessage());
                }
            }
            $pdo->commit();
            if (!empty($session['user_id'])) {
                logUserActivity(
                    (int)$session['user_id'],
                    $session['username'] ?? 'unknown',
                    $session['full_name'] ?? null,
                    'session_terminated',
                    'إنهاء الجلسة من قبل الإدارة [' . ((int)$_SESSION['admin_id'] ?? 0) . '] | IP: ' . ($session['ip_address'] ?? '?')
                );
            }
            return true;
        } catch (Throwable $inner) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $inner;
        }
    } catch (Throwable $e) {
        error_log("Terminate session error: " . $e->getMessage());
        return false;
    }
}

function endAllUserSessions($user_id)
{
    global $pdo;

    try {
        ensureUserSessionTables();
        $user_id = (int)$user_id;
        if ($user_id <= 0) return false;

        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT session_id FROM user_sessions WHERE user_id = ? AND status = 'active'");
            $sel->execute([$user_id]);
            $sessIds = $sel->fetchAll(PDO::FETCH_COLUMN, 0);

            $upd = $pdo->prepare("UPDATE user_sessions SET status = 'terminated', ended_at = NOW() WHERE user_id = ? AND status = 'active'");
            $upd->execute([$user_id]);
            $count = $upd->rowCount();

            if ($count > 0) {
                foreach ($sessIds as $sid) {
                    if (!empty($sid)) {
                        $saveHandler = ini_get('session.save_handler');
                        if ($saveHandler === 'files') {
                            $savePath = session_save_path() ?: sys_get_temp_dir();
                            $file = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $sid;
                            if (is_file($file)) {
                                @unlink($file);
                            }
                        }
                    }
                }
                $pdo->commit();
                $usr = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
                $usr->execute([$user_id]);
                $u = $usr->fetch();
                logUserActivity(
                    $user_id,
                    $u['username'] ?? 'unknown',
                    $u['full_name'] ?? null,
                    'session_terminated',
                    'إنهاء جميع الجلسات من قبل الإدارة [' . ((int)$_SESSION['admin_id'] ?? 0) . '] - عدد: ' . $count
                );
                return true;
            }

            $pdo->commit();
            return true;
        } catch (Throwable $inner) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $inner;
        }
    } catch (Throwable $e) {
        error_log("End all sessions error: " . $e->getMessage());
        return false;
    }
}

function blockDevice($session_id, $reason = '')
{
    global $pdo;

    try {
        ensureUserSessionTables();
        $stmt = $pdo->prepare("SELECT us.*, u.username, u.full_name FROM user_sessions us LEFT JOIN users u ON u.id = us.user_id WHERE us.id = ?");
        $stmt->execute([(int)$session_id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) return false;

        $fingerprint = $session['device_fingerprint'] ?: generateDeviceFingerprint();
        $blocked_by = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;

        $pdo->beginTransaction();
        try {
            $exist = $pdo->prepare("SELECT id, is_active FROM blocked_devices WHERE user_id = ? AND device_fingerprint = ? LIMIT 1");
            $exist->execute([$session['user_id'], $fingerprint]);
            $row = $exist->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if ($row['is_active']) {
                    $pdo->commit();
                    return terminateUserSession($session_id);
                }
                $reactivate = $pdo->prepare("UPDATE blocked_devices SET is_active = 1, reason = ?, blocked_by = ?, blocked_at = NOW(), ip_address = ?, user_agent = ? WHERE id = ?");
                $reactivate->execute([
                    $reason,
                    $blocked_by,
                    $session['ip_address'] ?? null,
                    $session['user_agent'] ?? null,
                    (int)$row['id']
                ]);
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO blocked_devices
                        (user_id, device_fingerprint, ip_address, user_agent, reason, blocked_by, blocked_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)
                ");
                $insert->execute([
                    $session['user_id'],
                    $fingerprint,
                    $session['ip_address'] ?? null,
                    $session['user_agent'] ?? null,
                    $reason,
                    $blocked_by
                ]);
            }

            $pdo->commit();

            logUserActivity(
                (int)$session['user_id'],
                $session['username'] ?? 'unknown',
                $session['full_name'] ?? null,
                'device_blocked',
                'حظر الجهاز من قبل الإدارة [' . ((int)$blocked_by) . ']. السبب: ' . ($reason ?: 'غير محدد') . ' | IP: ' . ($session['ip_address'] ?? '?')
            );

            return terminateUserSession($session_id);
        } catch (Throwable $inner) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $inner;
        }
    } catch (Throwable $e) {
        error_log("Block device error: " . $e->getMessage());
        return false;
    }
}

function unblockDevice($blocked_device_id)
{
    global $pdo;

    try {
        ensureUserSessionTables();
        $blocked_device_id = (int)$blocked_device_id;
        if ($blocked_device_id <= 0) return false;

        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT bd.*, u.username, u.full_name FROM blocked_devices bd LEFT JOIN users u ON u.id = bd.user_id WHERE bd.id = ?");
            $sel->execute([$blocked_device_id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                return false;
            }

            $upd = $pdo->prepare("UPDATE blocked_devices SET is_active = 0 WHERE id = ? AND is_active = 1");
            $upd->execute([$blocked_device_id]);
            $pdo->commit();

            logUserActivity(
                (int)$row['user_id'],
                $row['username'] ?? 'unknown',
                $row['full_name'] ?? null,
                'device_unblocked',
                'إلغاء حظر الجهاز من قبل الإدارة [' . ((int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0)) . ']. IP محظور: ' . ($row['ip_address'] ?? '?')
            );
            return true;
        } catch (Throwable $inner) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $inner;
        }
    } catch (Throwable $e) {
        error_log("Unblock device error: " . $e->getMessage());
        return false;
    }
}

function getBlockedDevices($user_id = null)
{
    global $pdo;

    try {
        ensureUserSessionTables();
        $sql = "
            SELECT bd.*, u.username, u.full_name
            FROM blocked_devices bd
            LEFT JOIN users u ON bd.user_id = u.id
            WHERE 1=1
        ";
        $params = [];
        if ($user_id) {
            $sql .= " AND bd.user_id = ?";
            $params[] = $user_id;
        }
        $sql .= " ORDER BY bd.blocked_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("Get blocked devices error: " . $e->getMessage());
        return [];
    }
}

function logUserActivity($user_id, $username, $full_name, $activity_type, $activity_description = '')
{
    global $pdo;

    try {
        ensureUserActivityLogTable();
        $ua = parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO user_activity_logs
                (user_id, username, full_name, activity_type, activity_description, ip_address, user_agent, device_type, browser, operating_system)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $username ?: 'unknown',
            $full_name,
            $activity_type,
            $activity_description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $ua['device'] ?? null,
            $ua['browser'] ?? null,
            $ua['os'] ?? null
        ]);
        return true;
    } catch (Throwable $e) {
        error_log("User activity log error: " . $e->getMessage());
        return false;
    }
}

/**
 * دوال الترجمة المحاسبية والعامة
 */

/**
 * دالة معالجة رفع الملفات
 */
if (!function_exists('format_date_display')) {
    function format_date_display($date, $include_time = false)
    {
        if ($date === null || $date === '') {
            return '-';
        }

        $timestamp = is_numeric($date) ? (int)$date : strtotime((string)$date);
        if ($timestamp === false) {
            return htmlspecialchars((string)$date, ENT_QUOTES, 'UTF-8');
        }

        return date($include_time ? 'Y-m-d H:i' : 'Y-m-d', $timestamp);
    }
}

if (!function_exists('getUserStats')) {
    function getUserStats()
    {
        global $pdo;

        $stats = [
            'active_users' => 0,
            'inactive_users' => 0,
            'online_now' => 0,
            'active_sessions' => 0,
            'logins_today' => 0,
            'logouts_today' => 0,
        ];

        try {
            ensureUserSessionTables();
            ensureUserActivityLogTable();

            $stats['active_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
            $stats['inactive_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status <> 'active' OR status IS NULL")->fetchColumn();
            $stats['active_sessions'] = (int)$pdo->query("SELECT COUNT(*) FROM user_sessions WHERE status = 'active'")->fetchColumn();
            $stats['online_now'] = (int)$pdo->query("
                SELECT COUNT(DISTINCT user_id)
                FROM user_sessions
                WHERE status = 'active'
                  AND COALESCE(last_activity, started_at) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ")->fetchColumn();
            $stats['logins_today'] = (int)$pdo->query("
                SELECT COUNT(*)
                FROM user_activity_logs
                WHERE DATE(created_at) = CURDATE()
                  AND activity_type IN ('login', 'user_login', 'تسجيل دخول')
            ")->fetchColumn();
            $stats['logouts_today'] = (int)$pdo->query("
                SELECT COUNT(*)
                FROM user_activity_logs
                WHERE DATE(created_at) = CURDATE()
                  AND activity_type IN ('logout', 'user_logout', 'تسجيل خروج')
            ")->fetchColumn();
        } catch (Throwable $e) {
            error_log("Get user stats error: " . $e->getMessage());
        }

        return $stats;
    }
}

function handleFileUpload($file_key, $passport_number, $type, $traveler_name = '')
{
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $type_ar = [
        'personal' => 'صورة_شخصية',
        'passport' => 'صورة_الجواز',
        'exit' => 'صورة_الصادر',
        'auth' => 'صورة_التفويض',
        'deport' => 'صورة_الترحيل',
        'letter' => 'صورة_الخطاب',
        'print' => 'البرنت'
    ];

    $type_name = $type_ar[$type] ?? $type;
    // تنظيف الاسم من الرموز غير المسموحة في أسماء الملفات
    $clean_name = preg_replace('/[^\x{0600}-\x{06FF}A-Za-z0-9_\-]/u', '_', $traveler_name);

    $upload_dir = __DIR__ . '/../assets/uploads/passports/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file_extension = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));

    // التحقق من نوع الملف (أمان)
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    if (!in_array($file_extension, $allowed_extensions)) {
        return null;
    }

    $new_filename = $type_name . '_' . $clean_name . '_' . $passport_number . '_' . time() . '.' . $file_extension;
    $target_path = $upload_dir . $new_filename;

    if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $target_path)) {
        return 'passports/' . $new_filename;
    }

    return null;
}

/**
 * دالة لتوليد شرط تصفية البيانات بناءً على هوية المستخدم وارتباطاته
 * @return array ['clause' => string, 'params' => array]
 */
function get_umrah_settings($pdo)
{
    $settings = getSettings($pdo);

    // إرجاع إعدادات افتراضية إذا لم تكن موجودة في قاعدة البيانات
    if (empty($settings)) {
        return [
            'enable_umrah' => 1,
            'min_passport_validity_months' => 6,
            'visa_expiry_alert_days' => 5,
            'auto_post_to_financials' => 1
        ];
    }

    return [
        'enable_umrah' => $settings['enable_umrah'] ?? 1,
        'min_passport_validity_months' => $settings['min_passport_validity_months'] ?? 6,
        'visa_expiry_alert_days' => $settings['visa_expiry_alert_days'] ?? 5,
        'auto_post_to_financials' => $settings['auto_post_to_financials'] ?? 1
    ];
}

if (!function_exists('get_module_definitions')) {
    function get_module_definitions()
    {
        return [
            'enable_bus_bookings' => 'Bus bookings',
            'enable_flight_bookings' => 'Flight bookings',
            'enable_passport_transactions' => 'Passport transactions',
            'enable_work_visa' => 'Work visa',
            'enable_family_visit' => 'Family visit',
            'enable_postal_services' => 'Postal services',
            'enable_umrah' => 'Umrah',
            'enable_hajj' => 'Hajj',
            'enable_crm' => 'CRM',
        ];
    }
}

if (!function_exists('normalize_bool_setting')) {
    function normalize_bool_setting($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}

if (!function_exists('currentUserIsAdmin')) {
    function currentUserIsAdmin()
    {
        if (empty($_SESSION['admin_id']) && empty($_SESSION['user_id'])) {
            return false;
        }
        $role = $_SESSION['role'] ?? '';
        if (is_string($role)) {
            $role_l = strtolower($role);
            if (in_array($role_l, ['superadmin', 'admin', 'owner', 'مدير', 'مالك', 'مشرف'], true)) {
                return true;
            }
        }
        if (function_exists('hasPermission') && !empty($_SESSION['user_id'])) {
            if (hasPermission($_SESSION['user_id'], 'manage_users')
                || hasPermission($_SESSION['user_id'], 'manage_sessions')
                || hasPermission($_SESSION['user_id'], 'system_settings')) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('requireAdminAccess')) {
    function requireAdminAccess()
    {
        if (!currentUserIsAdmin()) {
            $_SESSION['flash_message'] = [
                'type'  => 'danger',
                'title' => 'صلاحية مرفوضة',
                'body'  => 'ليس لديك صلاحية للوصول إلى هذه الصفحة.'
            ];
            header('Location: index.php');
            exit();
        }
    }
}

if (!function_exists('getDeviceTypeLabel')) {
    function getDeviceTypeLabel($type_en)
    {
        $map = [
            'mobile'  => 'جوال',
            'tablet'  => 'تابلت',
            'desktop' => 'كمبيوتر',
            'جوال'   => 'جوال',
            'تابلت' => 'تابلت',
            'كمبيوتر' => 'كمبيوتر'
        ];
        return $map[$type_en] ?? ($type_en ?: 'غير معروف');
    }
}

if (!function_exists('getActivityTypeLabel')) {
    function getActivityTypeLabel($type)
    {
        $map = [
            'login'          => ['label' => 'تسجيل دخول',   'class' => 'success'],
            'user_login'     => ['label' => 'تسجيل دخول',   'class' => 'success'],
            'logout'         => ['label' => 'تسجيل خروج',   'class' => 'danger'],
            'user_logout'    => ['label' => 'تسجيل خروج',   'class' => 'danger'],
            'login_failed'   => ['label' => 'فشل تسجيل دخول', 'class' => 'warning'],
            'login_blocked'  => ['label' => 'جهاز محظور',   'class' => 'dark'],
            'session_terminated' => ['label' => 'إنهاء جلسة', 'class' => 'danger'],
            'device_blocked' => ['label' => 'حظر جهاز',     'class' => 'warning'],
            'device_unblocked' => ['label' => 'إلغاء حظر جهاز', 'class' => 'success'],
            'password_change' => ['label' => 'تغيير كلمة مرور', 'class' => 'info'],
            'create'         => ['label' => 'إنشاء',         'class' => 'primary'],
            'update'         => ['label' => 'تحديث',         'class' => 'primary'],
            'delete'         => ['label' => 'حذف',           'class' => 'danger'],
            'post'           => ['label' => 'ترحيل',         'class' => 'success'],
            'unpost'         => ['label' => 'إلغاء ترحيل',   'class' => 'warning'],
        ];
        if (isset($map[$type])) {
            return $map[$type];
        }
        return ['label' => $type, 'class' => 'secondary'];
    }
}

if (!function_exists('reload_module_settings_cache')) {
    function reload_module_settings_cache()
    {
        unset($_SESSION['settings_cache'], $_SESSION['module_settings_cache']);
    }
}

function get_module_status($pdo, $module_name)
{
    $settings = getSettings($pdo);
    return normalize_bool_setting($settings[$module_name] ?? false);
}

if (!function_exists('get_umrah_service_aliases')) {
    function get_umrah_service_aliases()
    {
        return ['خدمات العمرة', 'قسم العمرة', 'حج وعمرة', 'خدمات الحج والعمرة', 'umrah'];
    }
}

if (!function_exists('get_hajj_service_aliases')) {
    function get_hajj_service_aliases()
    {
        return ['خدمات الحج', 'hajj'];
    }
}

if (!function_exists('get_postal_service_aliases')) {
    function get_postal_service_aliases()
    {
        return ['خدمات البريد', 'postal_services', 'postal'];
    }
}

if (!function_exists('is_umrah_service')) {
    function is_umrah_service($service_type)
    {
        return in_array(trim((string) $service_type), get_umrah_service_aliases(), true);
    }
}

if (!function_exists('is_hajj_service')) {
    function is_hajj_service($service_type)
    {
        return in_array(trim((string) $service_type), get_hajj_service_aliases(), true);
    }
}

if (!function_exists('is_postal_service')) {
    function is_postal_service($service_type)
    {
        return in_array(trim((string) $service_type), get_postal_service_aliases(), true);
    }
}

function check_user_dependencies($pdo, $user_id)
{
    // Define tables and the column that links to the users table
    $dependency_tables = [
        'passports' => 'user_id', // Assuming passports are created by a user
        'umrah' => 'user_id',     // Assuming umrah records are created by a user
        'work_visa' => 'user_id', // Assuming work_visa records are created by a user
        'family_visit' => 'user_id', // Assuming family_visit records are created by a user
        'internal_messages' => 'sender_id', // Assuming messages have a sender
        'notifications' => 'user_id', // Assuming notifications are for a user
        'user_branches' => 'user_id', // Assuming user_branches links a user to a branch
        'bus_flight_bookings' => 'created_by', // Assuming the new booking module has a created_by field
        'passport_transactions' => 'created_by' // Assuming the new passport transactions module has a created_by field
        // Add other tables as identified in the future
    ];

    foreach ($dependency_tables as $table => $column) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetchColumn() > 0) {
                return true; // Found dependencies
            }
        } catch (PDOException $e) {
            // Log the error if the table or column does not exist, but don't stop the check
            // For now, we'll just ignore it, assuming some tables might not exist yet
            // or column names might differ, and we want to check existing ones.
            error_log("Error checking dependency in table $table: " . $e->getMessage());
        }
    }
    return false; // No dependencies found
}

function is_user_involved_in_any_transaction($pdo, $user_id)
{
    // This function can be expanded to check more complex relationships or specific transaction types.
    // For now, it directly calls check_user_dependencies.
    return check_user_dependencies($pdo, $user_id);
}

function get_entity_filter($table_alias = '', $branch_col = 'branch_id', $agent_col = 'agent_id', $employee_col = 'employee_id', $user_col = 'user_id')
{
    global $pdo;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
    if (!$user_id) return ['clause' => '1=0', 'params' => []]; // لا بيانات لغير المسجلين

    // جلب بيانات المستخدم الحالية
    $stmt = $pdo->prepare("SELECT u.user_type, u.branch_id, u.agent_id, u.employee_id, r.name as role
                           FROM users u
                           LEFT JOIN roles r ON u.role_id = r.id
                           WHERE u.id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) return ['clause' => '1=0', 'params' => []];

    // الأدوار العليا ترى كل شيء
    if (in_array($user['user_type'], ['admin', 'developer']) || in_array($user['role'], ['admin', 'developer']) || has_permission('view_all_transactions')) {
        return ['clause' => '1=1', 'params' => []];
    }

    $prefix = $table_alias ? $table_alias . '.' : '';
    $clauses = [];
    $params = [];

    // تصفية حسب نوع المستخدم وارتباطه
    if ($user['user_type'] == 'agent' && !empty($user['agent_id'])) {
        $clauses[] = "{$prefix}{$agent_col} = ?";
        $params[] = $user['agent_id'];
    } elseif ($user['user_type'] == 'branch' && !empty($user['branch_id'])) {
        $clauses[] = "{$prefix}{$branch_col} = ?";
        $params[] = $user['branch_id'];
    } elseif ($user['user_type'] == 'employee' && !empty($user['employee_id'])) {
        if (!empty($user['branch_id'])) {
            $clauses[] = "{$prefix}{$branch_col} = ?";
            $params[] = $user['branch_id'];
        }
    }

    // إذا لم يكن لديه صلاحية رؤية الكل، يرى فقط ما أضافه بنفسه كفلتر إضافي أو وحيد
    if (!has_permission('view_all_transactions') && $user_col !== null) {
        $clauses[] = "{$prefix}{$user_col} = ?";
        $params[] = $user_id;
    }

    if (empty($clauses)) {
        return ['clause' => '1=1', 'params' => []]; // إذا لم يكن هناك شرط محدد، اسمح بالوصول (يمكن تقييده لاحقاً)
    }

    return ['clause' => implode(' AND ', $clauses), 'params' => $params];
}

// توليد رقم عملية مالية فريد
function generateOperationNumber($type)
{
    $prefix = '';
    switch ($type) {
        case 'receipt':
            $prefix = 'REC';
            break;
        case 'payment':
            $prefix = 'PAY';
            break;
        case 'expense':
            $prefix = 'EXP';
            break;
        default:
            $prefix = 'FIN';
    }
    return $prefix . '-' . date('Ymd') . '-' . rand(1000, 9999);
}

// توليد رقم حجز فريد للباصات والطيران
function generateBookingNumber($service_type)
{
    global $pdo;

    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM bus_flight_bookings WHERE service_type = ?");
    $stmt_count->execute([$service_type]);
    $count = $stmt_count->fetchColumn();

    $next_num = $count + 1;
    $prefix = (strtolower($service_type) === 'bus') ? 'B' : 'F';

    return $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
}

function get_workflow_for_transaction($type, $branch_id = null)
{
    global $pdo;

    // Normalize work_visa type
    $types = [$type];
    if ($type === 'work_visa') $types[] = '6';
    if ($type === '6') $types[] = 'work_visa';

    $placeholders = implode(',', array_fill(0, count($types), '?'));

    // Try to find specific workflow for branch and type
    $sql = "SELECT * FROM workflows
            WHERE (transaction_type IN ($placeholders) OR transaction_type = 'all')
            AND (branch_id = ? OR branch_id IS NULL)
            AND is_active = 1
            ORDER BY branch_id DESC, (transaction_type = 'all') ASC LIMIT 1";

    $params = array_merge($types, [$branch_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function get_all_workflows_for_transaction($type, $branch_id = null)
{
    global $pdo;

    $types = [$type];
    if ($type === 'work_visa') $types[] = '6';
    if ($type === '6') $types[] = 'work_visa';

    $placeholders = implode(',', array_fill(0, count($types), '?'));

    $sql = "SELECT * FROM workflows
            WHERE (transaction_type IN ($placeholders) OR transaction_type = 'all')
            AND (branch_id = ? OR branch_id IS NULL)
            AND is_active = 1
            ORDER BY branch_id DESC, name ASC";

    $params = array_merge($types, [$branch_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_workflow_id_by_transaction_type($pdo, $transaction_type)
{
    $stmt = $pdo->prepare("SELECT id FROM workflows WHERE transaction_type = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$transaction_type]);
    return $stmt->fetchColumn();
}

function get_workflow_steps($pdo, $workflow_id)
{
    $stmt = $pdo->prepare("SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$workflow_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * جلب الحقول الديناميكية لخطوة معينة في سير العمل
 */
function get_step_dynamic_fields($pdo, $step_id)
{
    $stmt = $pdo->prepare("
        SELECT f.*, sf.is_editable, sf.is_visible, sf.is_required, sf.sort_order as step_sort
        FROM workflow_fields f
        JOIN workflow_step_fields sf ON f.id = sf.field_id
        WHERE sf.step_id = ? AND sf.is_visible = 1 AND f.is_active = 1
        ORDER BY sf.sort_order, f.sort_order
    ");
    $stmt->execute([$step_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * جلب قيم الحقول الديناميكية لمعاملة معينة
 */
function get_transaction_field_values($pdo, $transaction_id, $transaction_type)
{
    $stmt = $pdo->prepare("
        SELECT f.field_key, v.field_value
        FROM workflow_field_values v
        JOIN workflow_fields f ON v.field_id = f.id
        WHERE v.transaction_id = ? AND v.transaction_type = ?
    ");
    $stmt->execute([$transaction_id, $transaction_type]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * حفظ قيم الحقول الديناميكية لمعاملة
 */
function save_transaction_field_values($pdo, $transaction_id, $transaction_type, $values, $user_id = null)
{
    if (empty($values)) return true;

    foreach ($values as $field_key => $value) {
        // جلب معرف الحقل من مفتاحه
        $stmt_f = $pdo->prepare("SELECT id FROM workflow_fields WHERE field_key = ? LIMIT 1");
        $stmt_f->execute([$field_key]);
        $field_id = $stmt_f->fetchColumn();

        if ($field_id) {
            $stmt_v = $pdo->prepare("
                INSERT INTO workflow_field_values (transaction_id, transaction_type, field_id, field_value, updated_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_by = VALUES(updated_by)
            ");
            $stmt_v->execute([$transaction_id, $transaction_type, $field_id, $value, $user_id]);
        }
    }
    return true;
}

function get_allowed_transitions($workflow_id, $from_step_id, $role_id = null, $user_id = null)
{
    global $pdo;

    // Check if current user is admin/developer/programmer/manager (to bypass restrictions)
    $is_super = false;
    $current_user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
    $role_name = '';

    if ($current_user_id) {
        $stmt_r = $pdo->prepare("SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt_r->execute([$current_user_id]);
        $role_name = (string)$stmt_r->fetchColumn();
        if (in_array($role_name, ['admin', 'developer', 'مدير', 'مبرمج', 'مطور'])) {
            $is_super = true;
        }
    }

    $sql = "SELECT wt.id as transition_id, wt.to_step_id, ws.step_name as to_step_name, ws.color, ws.require_note, ws.require_reason, wt.require_approval
            FROM workflow_transitions wt
            JOIN workflow_steps ws ON wt.to_step_id = ws.id
            WHERE wt.workflow_id = ?";

    $params = [$workflow_id];

    // If user has 'request_document_confirmation', restrict transitions
    if (has_permission('request_document_confirmation') && !in_array($role_name, ['admin', 'developer', 'مدير', 'مبرمج', 'مطور'])) {
        $stmt_check = $pdo->prepare("SELECT step_name FROM workflow_steps WHERE id = ?");
        $stmt_check->execute([$from_step_id]);
        $current_step_name = $stmt_check->fetchColumn();

        if (strpos($current_step_name, 'تأكيد استلام') !== false) {
            // Already confirmed, allow other transitions
            $sql .= " AND wt.from_step_id = ? AND ws.step_name NOT LIKE '%تسليم للفرع الرئيسي%'";
            $params[] = $from_step_id;
            $is_super = true; // Allow all subsequent transitions in order
        } else {
            // Not yet confirmed, ONLY show "Deliver to Main Branch" (even if not a direct transition from current step)
            $sql .= " AND ws.step_name LIKE '%تسليم للفرع الرئيسي%'";
            $is_super = true; // Bypass role filter
        }
    } else {
        // Normal case: must match current step
        $sql .= " AND wt.from_step_id = ?";
        $params[] = $from_step_id;
    }

    // If not super user, filter by role/user
    if (!$is_super) {
        $sql .= " AND (wt.role_id IS NULL OR wt.role_id = '' OR FIND_IN_SET(?, wt.role_id)) AND (wt.allow_by_user_id IS NULL OR wt.allow_by_user_id = ?)";
        $params[] = $role_id;
        $params[] = $user_id;
    }

    $sql .= " GROUP BY wt.id, wt.to_step_id, ws.step_name, ws.color, ws.require_note, ws.require_reason, wt.require_approval ORDER BY wt.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // If user has 'request_document_confirmation', rename the transition button
    if (has_permission('request_document_confirmation') && !in_array($role_name, ['admin', 'developer', 'مدير', 'مبرمج', 'مطور'])) {
        foreach ($results as &$row) {
            if (strpos($row['to_step_name'], 'تسليم للفرع الرئيسي') !== false) {
                $row['to_step_name'] = 'طلب تأكيد استلام وثائق';
            }
        }
    }

    return $results;
}

/**
 * دالة تغيير حالة الحجز (خاصة بحجوزات الباصات والطيران)
 * @param int|array $booking_ids معرف أو مصفوفة معرفات الحجوزات
 * @param int $new_status_id معرف الحالة الجديدة من جدول statuses
 * @param int $user_id معرف المستخدم القائم بالتغيير
 * @param string $notes ملاحظات
 * @param array $extra_data بيانات إضافية (مثل رقم التذكرة، تاريخ السفر، إلخ)
 */
function change_booking_status($booking_ids, $new_status_id, $user_id, $notes = '', $extra_data = [])
{
    global $pdo;
    if (!is_array($booking_ids)) $booking_ids = [$booking_ids];

    $transaction_started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transaction_started = true;
        }

        // جلب تفاصيل الحالة الجديدة
        $stmt_status = $pdo->prepare("SELECT * FROM statuses WHERE id = ?");
        $stmt_status->execute([$new_status_id]);
        $new_status_info = $stmt_status->fetch();
        if (!$new_status_info) return false;

        foreach ($booking_ids as $id) {
            // جلب تفاصيل الحجز الحالي
            $stmt_booking = $pdo->prepare("SELECT status_id, branch_id, agent_id, sale_price, purchase_price, amount_received FROM bus_flight_bookings WHERE id = ?");
            $stmt_booking->execute([$id]);
            $booking = $stmt_booking->fetch();
            if (!$booking) continue;

            $old_status_id = $booking['status_id'];
            $current_sale_price = (float)$booking['sale_price'];
            $current_purchase_price = (float)$booking['purchase_price'];
            $current_received = (float)$booking['amount_received'];

            // بناء استعلام التحديث للحجز
            $update_fields = ["status_id = ?"];
            $params = [$new_status_id];

            // معالجة الخصم إذا وجد في البيانات المرسلة
            if (isset($extra_data['discount_amount']) && (float)$extra_data['discount_amount'] > 0) {
                $discount = (float)$extra_data['discount_amount'];
                $current_sale_price -= $discount;

                $update_fields[] = "sale_price = ?";
                $params[] = $current_sale_price;

                $update_fields[] = "discount_amount = ?";
                $params[] = $discount;
                // المتبقي والربح يتم تحديثهما تلقائياً بواسطة قاعدة البيانات
            }

            // إضافة الحقول الإضافية الأخرى
            if (!empty($extra_data)) {
                $allowed_extra_fields = [
                    'mod_reason',
                    'mod_datetime',
                    'requested_mod_date',
                    'cancel_reason',
                    'cancel_datetime',
                    'confirm_datetime',
                    'ticket_number',
                    'is_cancelled',
                    'batch_no',
                    'request_date',
                    'main_branch_delivery_date',
                    'received_date',
                    'sent_to_embassy_date',
                    'embassy_exit_date',
                    'arrival_office_date',
                    'visa_no',
                    'visa_issue_date',
                    'visa_expiry_date',
                    'transport_delivery_date',
                    'delivery_date',
                    'customer_receiver_name',
                    'cancellation_reason',
                    'reject_reason'
                ];
                foreach ($extra_data as $field => $value) {
                    if (in_array($field, $allowed_extra_fields)) {
                        $update_fields[] = "`$field` = ?";
                        $params[] = ($value === '') ? null : $value;
                    }
                }
            }

            $params[] = $id;

            $update_sql = "UPDATE `bus_flight_bookings` SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt_update = $pdo->prepare($update_sql);
            $stmt_update->execute($params);

            // تحضير ملاحظات السجل لتشمل كافة البيانات المدخلة في سير العمل
            $log_notes = $notes;
            if (!empty($extra_data)) {
                $field_labels = get_all_workflow_fields();
                $extra_info = [];
                foreach ($extra_data as $fkey => $fval) {
                    if (!empty($fval) && isset($field_labels[$fkey])) {
                        $extra_info[] = $field_labels[$fkey] . ": " . $fval;
                    }
                }
                if (!empty($extra_info)) {
                    $log_notes .= (!empty($log_notes) ? " | " : "") . implode(" - ", $extra_info);
                }
            }

            // تسجيل تغيير الحالة في سجل التغييرات
            $stmt_log = $pdo->prepare("
                INSERT INTO `booking_status_logs` (
                    booking_id, old_status_id, new_status_id, changed_by, notes
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt_log->execute([
                $id,
                $old_status_id,
                $new_status_id,
                $user_id,
                $log_notes
            ]);
        }

        if ($transaction_started) {
            $pdo->commit();
        }
        return true;
    } catch (PDOException $e) {
        if ($transaction_started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error changing booking status: " . $e->getMessage());
        return false;
    }
}

/**
 * دالة ترحيل الحجز مالياً (تطبيقاً للنظام الموحد الجديد)
 */
function post_booking_to_financials($booking_id, $user_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM bus_flight_bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        if (!$booking || !empty($booking['invoice_id'])) return true;

        $party_type = $booking['agent_id'] ? 'agent' : 'customer';
        $party_id = $booking['agent_id'] ?: $booking['customer_id'];

        $description = "فاتورة " . ($booking['service_type'] == 'bus' ? 'باص' : 'طيران') . " رقم: " . $booking['booking_number'] . " للمسافر: " . $booking['traveler_name'];

        $invoice_id = php_create_invoice_and_post(
            $pdo,
            'sales',
            $booking['branch_id'],
            'bus_flight',
            $booking_id,
            $party_id,
            $booking['currency_id'],
            $booking['sale_price'],
            0,
            $booking['purchase_price'],
            'draft',
            $description,
            $user_id,
            $booking['agent_id']
        );

        if ($invoice_id) {
            $pdo->prepare("UPDATE bus_flight_bookings SET invoice_id = ? WHERE id = ?")->execute([$invoice_id, $booking_id]);

            // إذا تم دفع مبلغ مقدم، نقوم بإنشاء سند قبض
            if ($booking['amount_received'] > 0) {
                // جلب حساب الطرف من النظام الموحد
                $table = ($party_type == 'agent') ? 'agents' : 'customers';
                $party_account_id = $pdo->query("SELECT account_id FROM $table WHERE id = $party_id")->fetchColumn();

                php_create_voucher_and_post(
                    $pdo,
                    'receipt',
                    $booking['branch_id'],
                    $party_type,
                    $party_id,
                    $booking['amount_received'],
                    $booking['currency_id'],
                    $booking['account_id'], // cash_bank_account_id
                    $party_account_id,
                    "دفعة مقدمة للحجز: " . $booking['booking_number'],
                    $booking['booking_number'],
                    json_encode([['invoice_id' => $invoice_id, 'amount' => $booking['amount_received']]]),
                    null,
                    null,
                    false
                );
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Financial Posting Error: " . $e->getMessage());
        return false;
    }
}

/**
 * ترحيل المعاملات (الجوازات والتأشيرات والعمرة) إلى النظام المالي الموحد
 */
function post_passport_to_financials($passport_id, $user_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM passports WHERE id = ?");
        $stmt->execute([$passport_id]);
        $trx = $stmt->fetch();
        if (!$trx || !empty($trx['invoice_id'])) return true;

        $party_type = !empty($trx['agent_id']) ? 'agent' : 'customer';
        $party_id = !empty($trx['agent_id']) ? $trx['agent_id'] : $trx['customer_id'];

        $description = "فاتورة " . ($trx['transaction_type'] == 'umrah' ? 'عمرة' : 'تأشيرة عمل') . " رقم: " . $trx['passport_number'] . " للمسافر: " . $trx['full_name'];

        $invoice_id = php_create_invoice_and_post(
            $pdo,
            'sales',
            $trx['branch_id'],
            $trx['transaction_type'],
            $passport_id,
            $party_id,
            $trx['currency_id'],
            $trx['sale_price'],
            0,
            $trx['purchase_price'],
            'draft',
            $description,
            $user_id,
            $trx['agent_id']
        );

        if ($invoice_id) {
            $pdo->prepare("UPDATE passports SET invoice_id = ? WHERE id = ?")->execute([$invoice_id, $passport_id]);

            if ($trx['amount_received'] > 0) {
                $table = ($party_type == 'agent') ? 'agents' : 'customers';
                $party_account_id = $pdo->query("SELECT account_id FROM $table WHERE id = $party_id")->fetchColumn();

                php_create_voucher_and_post(
                    $pdo,
                    'receipt',
                    $trx['branch_id'],
                    $party_type,
                    $party_id,
                    $trx['amount_received'],
                    $trx['currency_id'],
                    $trx['account_id'],
                    $party_account_id,
                    "دفعة مقدمة للمعاملة: " . $trx['passport_number'],
                    $trx['passport_number'],
                    json_encode([['invoice_id' => $invoice_id, 'amount' => $trx['amount_received']]]),
                    null,
                    null,
                    false
                );
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Financial Posting Error (Passport): " . $e->getMessage());
        return false;
    }
}

/**
 * ترحيل معاملات الجوازات إلى النظام المالي الموحد
 */
function post_passport_transaction_to_financials($transaction_id, $user_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM passport_transactions WHERE id = ?");
        $stmt->execute([$transaction_id]);
        $trx = $stmt->fetch();
        if (!$trx || !empty($trx['invoice_id'])) return false;

        $party_type = !empty($trx['agent_id']) ? 'agent' : 'customer';
        $party_id = !empty($trx['agent_id']) ? $trx['agent_id'] : ($trx['customer_id'] ?? null);

        $description = "فاتورة معاملة جوازات رقم: " . $trx['transaction_number'] . " للمسافر: " . $trx['full_name'];

        $invoice_id = php_create_invoice_and_post(
            $pdo,
            'sales',
            $trx['branch_id'],
            'passport_transaction',
            $transaction_id,
            $party_id,
            $trx['currency_id'],
            $trx['sale_price'],
            0,
            $trx['purchase_price'],
            'draft',
            $description,
            $user_id,
            $trx['agent_id']
        );

        if ($invoice_id) {
            $pdo->prepare("UPDATE passport_transactions SET invoice_id = ? WHERE id = ?")->execute([$invoice_id, $transaction_id]);

            if ($trx['amount_received'] > 0) {
                $table = ($party_type == 'agent') ? 'agents' : 'customers';
                $party_account_id = $pdo->query("SELECT account_id FROM $table WHERE id = $party_id")->fetchColumn();

                php_create_voucher_and_post(
                    $pdo,
                    'receipt',
                    $trx['branch_id'],
                    $party_type,
                    $party_id,
                    $trx['amount_received'],
                    $trx['currency_id'],
                    $trx['account_id'],
                    $party_account_id,
                    "دفعة مقدمة للمعاملة: " . $trx['transaction_number'],
                    $trx['transaction_number'],
                    json_encode([['invoice_id' => $invoice_id, 'amount' => $trx['amount_received']]]),
                    null,
                    null,
                    false
                );
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Financial Posting Error (Passport Transaction): " . $e->getMessage());
        return false;
    }
}

/**
 * ترحيل معاملات الزيارة العائلية إلى النظام المالي الموحد
 */
function post_family_visit_to_financials($request_id, $user_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM family_visit_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        if (!$req || !empty($req['invoice_id'])) return true;

        $party_type = !empty($req['agent_id']) ? 'agent' : 'customer';
        $party_id = !empty($req['agent_id']) ? $req['agent_id'] : $req['customer_id'];

        $total_sale = (float)$req['total_sale_price'];
        $total_cost = (float)($req['total_agent_price'] + $req['total_branch_price']);

        $description = "فاتورة زيارة عائلية رقم: " . $req['document_no'] . " للمسافر: " . $req['owner_name'];

        $invoice_id = php_create_invoice_and_post(
            $pdo,
            'sales',
            $req['branch_id'],
            'family_visit',
            $request_id,
            $party_id,
            $req['currency_id'] ?: 1,
            $total_sale,
            0,
            $total_cost,
            'draft',
            $description,
            $user_id,
            $req['agent_id']
        );

        if ($invoice_id) {
            $pdo->prepare("UPDATE family_visit_requests SET invoice_id = ? WHERE id = ?")->execute([$invoice_id, $request_id]);

            if ($req['amount_received'] > 0) {
                $table = ($party_type == 'agent') ? 'agents' : 'customers';
                $party_account_id = $pdo->query("SELECT account_id FROM $table WHERE id = $party_id")->fetchColumn();

                php_create_voucher_and_post(
                    $pdo,
                    'receipt',
                    $req['branch_id'],
                    $party_type,
                    $party_id,
                    $req['amount_received'],
                    $req['currency_id'] ?: 1,
                    $req['account_id'],
                    $party_account_id,
                    "دفعة مقدمة للزيارة العائلية: " . $req['document_no'],
                    $req['document_no'],
                    json_encode([['invoice_id' => $invoice_id, 'amount' => $req['amount_received']]]),
                    null,
                    null,
                    false
                );
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Financial Posting Error (Family Visit): " . $e->getMessage());
        return false;
    }
}

/**
 * دالة تغيير حالة المعاملة (موحدة لجميع الخدمات)
 * @param int|array $transaction_ids معرف أو مصفوفة معرفات المعاملات
 * @param int $new_step_id معرف المرحلة الجديدة من سير العمل
 * @param int $user_id معرف المستخدم القائم بالتغيير
 * @param string $notes ملاحظات
 * @param array $extra_data بيانات إضافية (مثل رقم التأشيرة، تاريخ السفارة، إلخ)
 */
function change_transaction_status($transaction_ids, $new_step_id, $user_id, $notes = '', $extra_data = [])
{
    global $pdo;
    if (!is_array($transaction_ids)) $transaction_ids = [$transaction_ids];

    $transaction_started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transaction_started = true;
        }

        // جلب تفاصيل المرحلة الجديدة
        $stmt_step = $pdo->prepare("SELECT * FROM workflow_steps WHERE id = ?");
        $stmt_step->execute([$new_step_id]);
        $new_step = $stmt_step->fetch();
        if (!$new_step) return false;

        // جلب دور المستخدم
        $stmt_role = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
        $stmt_role->execute([$user_id]);
        $user_role_id = $stmt_role->fetchColumn();

        foreach ($transaction_ids as $id) {
            // جلب تفاصيل المعاملة الحالية
            $stmt_trx = $pdo->prepare("SELECT status_id, branch_id, agent_id, transaction_type, parent_id FROM passports WHERE id = ?");
            $stmt_trx->execute([$id]);
            $trx = $stmt_trx->fetch();
            if (!$trx) continue;

            // بناء استعلام التحديث للمعاملة
            $update_fields = ["status_id = ?", "workflow_id = ?", "status_changed_at = CURRENT_TIMESTAMP", "status_changed_by = ?"];
            $params = [$new_step['status_id'], $new_step['workflow_id'], $user_id];

            // إضافة الحقول الإضافية إذا كانت موجودة في البيانات المرسلة
            $applied_extra = [];
            if (!empty($extra_data)) {
                // قائمة الحقول المسموح بتحديثها في جدول passports مباشرة
                $allowed_passport_fields = [
                    'batch_no',
                    'batch_id',
                    'request_date',
                    'main_branch_delivery_date',
                    'received_date',
                    'sent_to_embassy_date',
                    'embassy_exit_date',
                    'arrival_office_date',
                    'visa_no',
                    'visa_number',
                    'visa_issue_date',
                    'visa_expiry_date',
                    'transport_delivery_date',
                    'delivery_date',
                    'customer_receiver_name',
                    'cancellation_reason',
                    'reject_reason',
                    'notes',
                    'office_name'
                ];

                foreach ($extra_data as $field => $value) {
                    if (in_array($field, $allowed_passport_fields)) {
                        $update_fields[] = "`$field` = ?";
                        $params[] = ($value === '') ? null : $value;
                        $applied_extra[$field] = $value;
                    } else {
                        // نحتفظ بها في applied_extra لاستخدامها في جداول أخرى (مثل umrah_details)
                        $applied_extra[$field] = $value;
                    }
                }
            }

            if ($new_step['is_final']) {
                $update_fields[] = "closed_at = CURRENT_TIMESTAMP";
                $update_fields[] = "closed_by = ?";
                $params[] = $user_id;
            }

            $params[] = $id; // للمعالج WHERE
            $stmt_upd = $pdo->prepare("UPDATE passports SET " . implode(", ", $update_fields) . " WHERE id = ?");
            if (!$stmt_upd->execute($params)) {
                throw new PDOException("Failed to update passport ID $id");
            }

            $affected = $stmt_upd->rowCount();
            error_log("change_transaction_status: Updated ID $id, Affected Rows: $affected");

            // تحديث بيانات العمرة الموجودة في جدول passports إذا كانت المعاملة عمرة وهناك بيانات إضافية
            if ($trx['transaction_type'] === 'umrah' && !empty($applied_extra)) {
                $ud_fields = [];
                $ud_params = [];
                // الأعمدة الموجودة في passports والتي قد يتم تحديثها
                $allowed_ud_cols = ['visa_number', 'visa_issue_date', 'visa_expiry_date', 'is_outside_ksa', 'host_id', 'guarantor_id'];

                foreach ($applied_extra as $field => $value) {
                    if (in_array($field, $allowed_ud_cols)) {
                        $ud_fields[] = "`$field` = ?";
                        $ud_params[] = ($value === '') ? null : $value;
                    }
                }

                if (!empty($ud_fields)) {
                    $ud_params[] = $id;
                    $stmt_ud_upd = $pdo->prepare("UPDATE passports SET " . implode(", ", $ud_fields) . " WHERE id = ?");
                    $stmt_ud_upd->execute($ud_params);
                }
            }

            // تسجيل الحركة في السجل
            $stmt_log = $pdo->prepare("INSERT INTO transaction_status_logs (transaction_id, old_status_id, new_status_id, changed_by, changed_role_id, branch_id, agent_id, notes, updated_fields) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_log->execute([
                $id,
                $trx['status_id'],
                $new_step['status_id'],
                $user_id,
                $user_role_id,
                $trx['branch_id'],
                $trx['agent_id'],
                $notes,
                !empty($applied_extra) ? json_encode($applied_extra, JSON_UNESCAPED_UNICODE) : null
            ]);

            $stmt_names = $pdo->prepare("SELECT status_name FROM statuses WHERE id = ?");
            $stmt_names->execute([$trx['status_id']]);
            $old_status_name = $stmt_names->fetchColumn();
            $stmt_names->execute([$new_step['status_id']]);
            $new_status_name = $stmt_names->fetchColumn();

            $audit_old = ['status_id' => $trx['status_id'], 'status_name' => $old_status_name];
            $audit_new = ['status_id' => $new_step['status_id'], 'status_name' => $new_status_name, 'step_name' => $new_step['step_name']];
            if (!empty($applied_extra)) {
                foreach ($applied_extra as $field => $value) {
                    $audit_new[$field] = $value;
                }
            }
            log_audit($pdo, 'تحديث حالة سير العمل', 'passports', $id, $audit_old, $audit_new);

            // إرسال إشعار للوكيل أو الفرع
            if (!empty($trx['agent_id']) || !empty($trx['branch_id'])) {
                // جلب أسماء الحالات والمهنة للإشعار التفصيلي
                $stmt_names = $pdo->prepare("SELECT
                    (SELECT status_name FROM statuses WHERE id = ?) as old_name,
                    (SELECT status_name FROM statuses WHERE id = ?) as new_name,
                    p.full_name, p.passport_number, prof.name_ar as profession_name
                    FROM passports p
                    LEFT JOIN professions prof ON p.profession_id = prof.id
                    WHERE p.id = ?");
                $stmt_names->execute([$trx['status_id'], $new_step['status_id'], $id]);
                $names = $stmt_names->fetch();

                if ($names) {
                    $notif_title = "تحديث سير العمل: " . $new_step['step_name'];
                    $notif_msg = "تم نقل المعاملة [ " . $names['full_name'] . " ] \n";
                    $notif_msg .= "رقم الجواز: " . $names['passport_number'] . " \n";
                    $notif_msg .= "المهنة: " . ($names['profession_name'] ?: '---') . " \n";
                    $notif_msg .= "من: " . ($names['old_name'] ?: 'جديد') . " \n";
                    $notif_msg .= "إلى: " . $names['new_name'];

                    if (!empty($notes)) {
                        $notif_msg .= "\nملاحظات: " . $notes;
                    }

                    $notif_link = ($trx['transaction_type'] === 'umrah') ? "umrah.php?id=" . $id : "work_visa.php?id=" . $id;

                    $stmt_notif = $pdo->prepare("INSERT INTO notifications (agent_id, branch_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, ?, 'info', ?)");
                    $stmt_notif->execute([
                        $trx['agent_id'],
                        $trx['branch_id'],
                        $notif_title,
                        $notif_msg,
                        $notif_link,
                        $user_id
                    ]);
                }
            }

            // إذا كانت المعاملة "أب" (Parent)، نقوم بتحديث جميع "الأبناء" (Children) تلقائياً
            $stmt_children = $pdo->prepare("SELECT id FROM passports WHERE parent_id = ?");
            $stmt_children->execute([$id]);
            $children = $stmt_children->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($children)) {
                // استدعاء ذاتي لتحديث الأبناء (بدون تكرار منطق الأب لتجنب الحلقات اللانهائية)
                foreach ($children as $child_id) {
                    // تحديث بسيط للأبناء
                    $pdo->prepare("UPDATE passports SET status_id = ?, workflow_id = ?, status_changed_at = CURRENT_TIMESTAMP, status_changed_by = ? WHERE id = ?")
                        ->execute([$new_step['status_id'], $new_step['workflow_id'], $user_id, $child_id]);

                    // تحديث بيانات passports للأبناء أيضاً إذا لزم الأمر
                    if ($trx['transaction_type'] === 'umrah' && !empty($applied_extra)) {
                        $ud_fields = [];
                        $ud_params = [];
                        $allowed_ud_cols = ['visa_number', 'visa_no', 'visa_issue_date', 'visa_expiry_date', 'batch_id', 'embassy_exit_date'];
                        foreach ($applied_extra as $field => $value) {
                            if (in_array($field, $allowed_ud_cols)) {
                                $ud_fields[] = "`$field` = ?";
                                $ud_params[] = $value;
                            }
                        }
                        if (!empty($ud_fields)) {
                            $ud_params[] = $child_id;
                            $pdo->prepare("UPDATE passports SET " . implode(", ", $ud_fields) . " WHERE id = ?")->execute($ud_params);
                        }
                    }

                    $pdo->prepare("INSERT INTO transaction_status_logs (transaction_id, old_status_id, new_status_id, changed_by, changed_role_id, branch_id, agent_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$child_id, $trx['status_id'], $new_step['status_id'], $user_id, $user_role_id, $trx['branch_id'], $trx['agent_id'], "تحديث تلقائي (تابع للمجموعة)"]);
                }
            }
        }

        if ($transaction_started) {
            $pdo->commit();
        }
        return true;
    } catch (PDOException $e) {
        if ($transaction_started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in change_transaction_status: " . $e->getMessage());
        return false;
    }
}

function get_step_fields($step_id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT show_fields FROM workflow_steps WHERE id = ?");
    $stmt->execute([$step_id]);
    $fields_str = $stmt->fetchColumn();
    return $fields_str ? explode(',', $fields_str) : [];
}

function get_all_workflow_fields()
{
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT field_key, field_label FROM workflow_fields WHERE is_active = 1 ORDER BY sort_order, field_label");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        // Fallback to static list if table doesn't exist yet or error occurs
        return [
            'batch_no' => 'رقم الطلب',
            'visa_no' => 'رقم التأشيرة',
            'visa_issue_date' => 'تاريخ إصدار التأشيرة',
            'visa_expiry_date' => 'تاريخ انتهاء التأشيرة'
        ];
    }
}

/**
 * جلب الحقول بناءً على نوع المعاملة (للمودالات والواجهات)
 */
function get_workflow_fields_by_type($transaction_type)
{
    global $pdo;

    $mapped_types = [$transaction_type];
    if ($transaction_type === 'visa' || $transaction_type == '5') $mapped_types[] = 'family_visit';
    if ($transaction_type === 'passport_transactions' || $transaction_type == '2') $mapped_types[] = 'passport';
    if ($transaction_type === 'bus_flight_bookings' || $transaction_type === 'booking' || $transaction_type == '3') $mapped_types[] = 'booking';
    if ($transaction_type === 'umrah' || $transaction_type == '4') {
        $mapped_types[] = 'umrah';
        $mapped_types[] = 'hajj';
    }
    if ($transaction_type === 'work_visa' || $transaction_type == '6') $mapped_types[] = 'work_visa';

    $mapped_types[] = 'general';
    $mapped_types = array_unique($mapped_types);

    $placeholders = implode(',', array_fill(0, count($mapped_types), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT f.field_key, f.field_label
            FROM workflow_fields f
            JOIN workflow_field_group_mappings gm ON f.id = gm.field_id
            JOIN workflow_field_groups g ON gm.group_id = g.id
            WHERE f.is_active = 1 AND g.group_key IN ($placeholders)
            GROUP BY f.field_key
            ORDER BY f.sort_order, f.field_label
        ");
        $stmt->execute($mapped_types);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return get_all_workflow_fields();
    }
}
/**
 * Audit Log Function (Unified)
 */
function log_audit($pdo, $action_type, $table_name, $record_id = null, $old_data = null, $new_data = null, $reason = null)
{
    if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) return false;

    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 1;
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // التحقق من الحماية (Hard Closing)
    if (in_array(strtolower($action_type), ['update', 'delete', 'cancel', 'reverse'])) {
        $closing_date = getSettings($pdo)['closing_date'] ?? '2000-01-01';

        // محاولة جلب تاريخ العملية للتحقق
        $record_date = null;
        try {
            if ($table_name == 'invoices') {
                $stmt = $pdo->prepare("SELECT invoice_date FROM invoices WHERE id = ?");
                $stmt->execute([$record_id]);
                $record_date = $stmt->fetchColumn();
            } elseif ($table_name == 'financial_transactions') {
                $stmt = $pdo->prepare("SELECT transaction_date FROM financial_transactions WHERE id = ?");
                $stmt->execute([$record_id]);
                $record_date = $stmt->fetchColumn();
            }
        } catch (Exception $e) {
        }

        if ($record_date && $record_date <= $closing_date) {
            // تسجيل محاولة تعديل مرفوضة
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                'Unauthorized Access Attempt',
                $table_name,
                $record_id,
                $old_data ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null,
                json_encode(['attempted_action' => $action_type, 'reason' => 'Financial Period Closed'], JSON_UNESCAPED_UNICODE),
                $user_ip,
                $user_agent
            ]);
            return false;
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $user_id,
            $action_type,
            $table_name,
            $record_id,
            $old_data ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null,
            $new_data ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null,
            $user_ip,
            $user_agent
        ]);
    } catch (PDOException $e) {
        return false;
    }
}

function parseUserAgent($ua)
{
    if (empty($ua)) {
        return [
            'os'          => 'Unknown',
            'os_ar'       => 'غير معروف',
            'browser'     => 'Unknown',
            'browser_ar'  => 'غير معروف',
            'device'      => 'desktop',
            'device_ar'   => 'كمبيوتر',
            'icon'        => 'fas fa-globe',
            'os_icon'     => 'fas fa-laptop',
            'device_icon' => 'fas fa-desktop'
        ];
    }

    $os_en = 'Unknown';
    $os_ar = 'غير معروف';
    $browser_en = 'Unknown';
    $browser_ar = 'غير معروف';
    $device_en = 'desktop';
    $device_ar = 'كمبيوتر';

    if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $ua)) {
        $device_en = 'mobile';
        $device_ar = 'جوال';
    } elseif (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
        $device_en = 'tablet';
        $device_ar = 'تابلت';
    }

    if (preg_match('/windows|win32/i', $ua)) { $os_en = 'Windows'; $os_ar = 'ويندوز'; }
    elseif (preg_match('/android/i', $ua))   { $os_en = 'Android'; $os_ar = 'أندرويد'; }
    elseif (preg_match('/iphone|ipad|ipod/i', $ua)) { $os_en = 'iOS'; $os_ar = 'iOS'; }
    elseif (preg_match('/linux/i', $ua))     { $os_en = 'Linux'; $os_ar = 'لينكس'; }
    elseif (preg_match('/macintosh|mac os x/i', $ua)) { $os_en = 'macOS'; $os_ar = 'ماك أو إس'; }

    if (preg_match('/msie|trident/i', $ua)) { $browser_en = 'Internet Explorer'; $browser_ar = 'إنترنت إكسبلورر'; }
    elseif (preg_match('/edge|edg/i', $ua)) { $browser_en = 'Edge'; $browser_ar = 'إيدج'; }
    elseif (preg_match('/firefox/i', $ua))  { $browser_en = 'Firefox'; $browser_ar = 'فَيَرفُوكس'; }
    elseif (preg_match('/opr\/|opera/i', $ua)) { $browser_en = 'Opera'; $browser_ar = 'أوبرا'; }
    elseif (preg_match('/chrome/i', $ua))   { $browser_en = 'Chrome'; $browser_ar = 'كُرُوم'; }
    elseif (preg_match('/safari/i', $ua))   { $browser_en = 'Safari'; $browser_ar = 'سَفَارِي'; }

    $lower_browser = strtolower($browser_en);
    $lower_os = strtolower($os_en);
    $icon = 'fas fa-globe';
    if (strpos($lower_browser, 'chrome') !== false) $icon = 'fab fa-chrome';
    elseif (strpos($lower_browser, 'firefox') !== false) $icon = 'fab fa-firefox';
    elseif (strpos($lower_browser, 'safari') !== false) $icon = 'fab fa-safari';
    elseif (strpos($lower_browser, 'edge') !== false) $icon = 'fab fa-edge';
    elseif (strpos($lower_browser, 'opera') !== false) $icon = 'fab fa-opera';
    elseif (strpos($lower_browser, 'explorer') !== false) $icon = 'fab fa-internet-explorer';

    $os_icon = 'fas fa-laptop';
    if ($lower_os === 'windows') $os_icon = 'fab fa-windows';
    elseif ($lower_os === 'android') $os_icon = 'fab fa-android';
    elseif (in_array($lower_os, ['ios', 'macos'], true)) $os_icon = 'fab fa-apple';
    elseif ($lower_os === 'linux') $os_icon = 'fab fa-linux';

    $device_icon = 'fas fa-desktop';
    if ($device_en === 'mobile') $device_icon = 'fas fa-mobile-alt';
    elseif ($device_en === 'tablet') $device_icon = 'fas fa-tablet-alt';

    return [
        'os'          => $os_en,
        'os_ar'       => $os_ar,
        'browser'     => $browser_en,
        'browser_ar'  => $browser_ar,
        'device'      => $device_en,
        'device_ar'   => $device_ar,
        'icon'        => $icon,
        'os_icon'     => $os_icon,
        'device_icon' => $device_icon
    ];
}

function translateTableName($tableName)
{
    $mapping = [
        'invoices' => 'الفواتير',
        'financial_transactions' => 'المعاملات المالية',
        'journal_lines' => 'قيود اليومية',
        'customers' => 'العملاء',
        'suppliers' => 'الموردين',
        'agents' => 'الوكلاء',
        'unified_accounts' => 'شجرة الحسابات',
        'users' => 'المستخدمين',
        'settings' => 'الإعدادات',
        'cost_centers' => 'مراكز التكلفة',
        'currency_exchange_transactions' => 'عمليات تصريف العملات',
        'currency_exchange_rates_history' => 'تاريخ أسعار الصرف',
        'receivable' => 'حسابات القبض',
        'revenue' => 'الإيرادات',
        'bank' => 'البنوك',
        'box' => 'الصناديق'
    ];
    return $mapping[$tableName] ?? $tableName;
}

function translateColumnName($colName)
{
    $mapping = [
        'id' => 'المعرف',
        'invoice_number' => 'رقم الفاتورة',
        'transaction_number' => 'رقم السند',
        'unified_transaction_id' => 'المعرف الموحد للحركة',
        'invoice_date' => 'تاريخ الفاتورة',
        'transaction_date' => 'تاريخ الحركة',
        'branch_id' => 'الفرع',
        'invoice_category' => 'نوع الفاتورة',
        'source_type' => 'نوع المصدر',
        'source_id' => 'رقم المصدر',
        'transaction_type' => 'نوع الحركة',
        'customer_id' => 'العميل',
        'supplier_id' => 'المورد',
        'agent_id' => 'الوكيل',
        'entity_type' => 'نوع الجهة',
        'entity_id' => 'معرف الجهة',
        'party_account_id' => 'حساب الجهة',
        'cash_bank_account_id' => 'حساب الصندوق/البنك',
        'branch_entity_id' => 'الجهة الفرعية',
        'cost_center_id' => 'مركز التكلفة',
        'total_amount' => 'المبلغ الإجمالي',
        'discount' => 'الخصم',
        'net_amount' => 'الصافي',
        'cost_amount' => 'التكلفة',
        'payment_type' => 'نوع السداد',
        'delivery_type' => 'طريقة التسليم',
        'status' => 'الحالة',
        'created_at' => 'تاريخ الإنشاء',
        'created_by' => 'أنشئ بواسطة',
        'updated_at' => 'تاريخ التحديث',
        'updated_by' => 'تم التحديث بواسطة',
        'posted_at' => 'تاريخ الترحيل',
        'posted_by' => 'تم الترحيل بواسطة',
        'amount_received' => 'المبلغ المستلم',
        'payment_status' => 'حالة السداد',
        'invoice_status' => 'حالة الفاتورة',
        'exchange_rate' => 'سعر الصرف',
        'reference_number' => 'رقم المرجع',
        'reference_type' => 'نوع المرجع',
        'reference_id' => 'معرف المرجع',
        'description' => 'الوصف/البيان',
        'account_id' => 'رقم الحساب',
        'customer_account_id' => 'حساب العميل',
        'supplier_account_id' => 'حساب المورد',
        'debit' => 'مدين',
        'credit' => 'دائن',
        'currency_id' => 'العملة',
        'created_ip' => 'عنوان IP للإنشاء',
        'created_user_agent' => 'متصفح الإنشاء',
        'updated_ip' => 'عنوان IP للتحديث',
        'posted_ip' => 'عنوان IP للترحيل',
        'cancelled_at' => 'تاريخ الإلغاء',
        'canceled_at' => 'تاريخ الإلغاء',
        'cancelled_by' => 'تم الإلغاء بواسطة',
        'canceled_by' => 'تم الإلغاء بواسطة',
        'cancelled_ip' => 'عنوان IP للإلغاء',
        'canceled_ip' => 'عنوان IP للإلغاء',
        'cancellation_reason' => 'سبب الإلغاء',
        'full_name' => 'الاسم الكامل',
        'username' => 'اسم المستخدم',
        'email' => 'البريد الإلكتروني',
        'from_currency_id' => 'من عملة',
        'to_currency_id' => 'إلى عملة',
        'from_amount' => 'المبلغ المرسل',
        'to_amount' => 'المبلغ المستلم',
        'from_account_id' => 'من حساب',
        'to_account_id' => 'إلى حساب',
        'profit_loss' => 'الربح/الخسارة',
        'old_rate_sell' => 'سعر البيع القديم',
        'new_rate_sell' => 'سعر البيع الجديد',
        'old_rate_buy' => 'سعر الشراء القديم',
        'new_rate_buy' => 'سعر الشراء الجديد',
        'changed_at' => 'تاريخ التغيير',
        'changed_by' => 'تم التغيير بواسطة',
        'payer_type' => 'نوع الدافع',
        'payer_id' => 'معرف الدافع',
        'payee_type' => 'نوع المستفيد',
        'payee_id' => 'معرف المستفيد',
        'amount' => 'المبلغ',
        'allocations' => 'توزيع المبالغ (الفواتير)'
    ];
    if (isset($mapping[$colName])) {
        return $mapping[$colName];
    }

    // ترجمة ذكية تلقائية لأي حقول غير موجودة صراحة في القائمة.
    $tokenMap = [
        'id' => 'المعرف',
        'invoice' => 'فاتورة',
        'number' => 'رقم',
        'date' => 'تاريخ',
        'branch' => 'فرع',
        'category' => 'تصنيف',
        'source' => 'مصدر',
        'type' => 'نوع',
        'customer' => 'عميل',
        'supplier' => 'مورد',
        'agent' => 'وكيل',
        'entity' => 'جهة',
        'center' => 'مركز',
        'cost' => 'تكلفة',
        'total' => 'إجمالي',
        'discount' => 'خصم',
        'net' => 'صافي',
        'amount' => 'مبلغ',
        'payment' => 'سداد',
        'delivery' => 'تسليم',
        'status' => 'حالة',
        'description' => 'بيان',
        'account' => 'حساب',
        'currency' => 'عملة',
        'created' => 'إنشاء',
        'updated' => 'تحديث',
        'posted' => 'ترحيل',
        'by' => 'بواسطة',
        'name' => 'اسم',
        'full' => 'كامل',
        'user' => 'مستخدم',
        'ip' => 'عنوان IP',
        'old' => 'قديم',
        'new' => 'جديد',
        'values' => 'القيم',
        'payee' => 'المستفيد',
        'payer' => 'الدافع',
        'edit' => 'تعديل',
        'add' => 'إضافة',
        'receivable' => 'حسابات قبض',
        'revenue' => 'إيراد',
        'bank' => 'بنك',
        'box' => 'صندوق'
    ];

    $parts = preg_split('/[_\s]+/', strtolower((string)$colName));
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    if (empty($parts)) {
        return $colName;
    }

    $translatedParts = [];
    foreach ($parts as $part) {
        $translatedParts[] = $tokenMap[$part] ?? $part;
    }

    return implode(' ', $translatedParts);
}

function translateAction($action)
{
    $raw = trim((string)$action);
    if ($raw === '') {
        return '-';
    }

    $normalized = strtolower($raw);

    if (strpos($normalized, 'create') !== false || strpos($raw, 'إضافة') !== false) return 'إضافة';
    if (strpos($normalized, 'update') !== false || strpos($raw, 'تعديل') !== false) return 'تعديل';
    if (strpos($normalized, 'delete') !== false || strpos($raw, 'حذف') !== false) return 'حذف';
    if (strpos($normalized, 'post') !== false || strpos($raw, 'ترحيل') !== false) {
        if (strpos($normalized, 'unpost') !== false || strpos($raw, 'إلغاء') !== false) return 'إلغاء ترحيل';
        return 'ترحيل';
    }
    if (strpos($normalized, 'reverse') !== false || strpos($raw, 'عكس') !== false) return 'عكس قيد';
    if (strpos($normalized, 'cancel') !== false || strpos($raw, 'إلغاء') !== false) return 'إلغاء';
    if (strpos($normalized, 'reset') !== false || strpos($raw, 'إعادة تعيين') !== false) return 'إعادة تعيين';
    if (strpos($normalized, 'approve') !== false || strpos($raw, 'اعتماد') !== false) return 'اعتماد';
    if (strpos($normalized, 'reject') !== false || strpos($raw, 'رفض') !== false) return 'رفض';
    if (strpos($normalized, 'print') !== false || strpos($raw, 'طباعة') !== false) return 'طباعة';
    if (strpos($normalized, 'login') !== false || strpos($raw, 'دخول') !== false) return 'تسجيل دخول';
    if (strpos($normalized, 'logout') !== false || strpos($raw, 'خروج') !== false) return 'تسجيل خروج';

    return $raw;
}

function translateSourceTypeName($sourceType)
{
    $value = trim((string)$sourceType);
    if ($value === '') {
        return '';
    }

    $mapping = [
        'general' => 'عام',
        'bus' => 'تذاكر طيران وبصات',
        'flight' => 'تذاكر طيران وبصات',
        'busflight' => 'تذاكر طيران وبصات',
        'تذاكر طيران وبصات' => 'تذاكر طيران وبصات',
        'النقل البري' => 'تذاكر طيران وبصات',
        'الطيران' => 'تذاكر طيران وبصات',
        'passport' => 'الجوازات',
        'umrah' => 'العمرة',
        'hajj' => 'الحج',
        'familyvisit' => 'الزيارة العائلية',
        'family_visit' => 'الزيارة العائلية',
        'work_visa' => 'فيزا العمل',
        'visa' => 'فيزا العمل'
    ];

    $key = strtolower(str_replace(' ', '', $value));
    return $mapping[$key] ?? $value;
}

function getAuditFormName($tableName, $action, $newData = [], $oldData = [])
{
    $table = strtolower((string)$tableName);
    $actionNorm = strtolower((string)$action);
    $payload = !empty($newData) ? $newData : $oldData;

    if ($table === 'invoices') {
        $category = strtolower((string)($payload['invoice_category'] ?? ''));
        $sourceName = translateSourceTypeName($payload['source_type'] ?? '');
        $prefix = 'فاتورة';
        if ($category === 'sales') {
            $prefix = 'فاتورة بيع';
        } elseif ($category === 'purchase') {
            $prefix = 'فاتورة شراء';
        }
        return $sourceName !== '' ? ($prefix . ' (' . $sourceName . ')') : $prefix;
    }

    if ($table === 'financial_transactions') {
        $txType = strtolower((string)($payload['transaction_type'] ?? ''));
        if ($txType === '' && (strpos($actionNorm, 'receipt') !== false || strpos($actionNorm, 'قبض') !== false)) {
            $txType = 'receipt';
        }
        if ($txType === '' && (strpos($actionNorm, 'payment') !== false || strpos($actionNorm, 'صرف') !== false)) {
            $txType = 'payment';
        }

        if ($txType === 'receipt') {
            return 'سند قبض';
        }
        if ($txType === 'payment') {
            return 'سند صرف';
        }
        if ($txType !== '') {
            return 'مستند مالي';
        }
    }

    if ($table === 'passports') {
        $type = translateSourceTypeName($payload['transaction_type'] ?? '');
        return $type !== '' ? "معاملة ($type)" : 'معاملة جوازات';
    }

    if ($table === 'users') return 'مستخدم';
    if ($table === 'customers') return 'عميل';
    if ($table === 'suppliers') return 'مورد';
    if ($table === 'agents') return 'وكيل';
    if ($table === 'branches') return 'فرع';

    return translateTableName($tableName);
}

/**
 * Unified function to safely delete a financial record.
 * This function checks the status of the record, prevents deletion of 'posted' transactions,
 * and handles the deletion of associated financial entries (journal lines, financial transactions)
 * and other related records (e.g., payment allocations for invoices).
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $table_name The main table name (e.g., 'invoices', 'receipts', 'payments').
 * @param int $record_id The ID of the record to delete.
 * @param string $status_field The name of the status field in $table_name (e.g., 'invoice_status', 'status').
 * @param string $reference_type The reference type for financial_transactions and journal_lines (e.g., 'invoice', 'receipt', 'payment').
 * @param array $additional_deletions An associative array where keys are table names and values are the foreign key column names in those tables that link to the main record_id.
 *                                     Example: ['payment_allocations' => 'invoice_id']
 * @param array $allowed_statuses An array of statuses that permit deletion (default: ['draft', 'cancelled']).
 * @return array An associative array with 'success' (boolean) and 'message' (string).
 */
function safe_delete_record(
    PDO $pdo,
    string $table_name,
    int $record_id,
    string $status_field,
    string $reference_type,
    array $additional_deletions = [],
    array $allowed_statuses = ['draft', 'cancelled']
): array {
    // Start a transaction to ensure atomicity
    $pdo->beginTransaction();

    try {
        // 1. Get current status of the record
        $stmt = $pdo->prepare("SELECT {$status_field} FROM `{$table_name}` WHERE id = ?");
        $stmt->execute([$record_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $pdo->rollBack();
            return ['success' => false, 'message' => "السجل غير موجود في {$table_name}."];
        }

        $current_status = $record[$status_field];

        // 2. Check if deletion is allowed based on status
        if (!in_array($current_status, $allowed_statuses)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => "لا يمكن حذف سجل بحالة '{$current_status}'. يجب أن يكون بحالة " . implode(' أو ', $allowed_statuses) . " أولاً."];
        }

        // 3. Delete associated journal_lines and financial_transactions
        // First, get financial_transaction_ids related to this record
        $stmt_ft_ids = $pdo->prepare("SELECT id FROM financial_transactions WHERE reference_type = ? AND reference_id = ?");
        $stmt_ft_ids->execute([$reference_type, $record_id]);
        $financial_transaction_ids = $stmt_ft_ids->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($financial_transaction_ids)) {
            $placeholders = implode(',', array_fill(0, count($financial_transaction_ids), '?'));

            // Delete from journal_lines
            $stmt_jl = $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id IN ({$placeholders})");
            $stmt_jl->execute($financial_transaction_ids);

            // Delete from financial_transactions
            $stmt_ft = $pdo->prepare("DELETE FROM financial_transactions WHERE id IN ({$placeholders})");
            $stmt_ft->execute($financial_transaction_ids);
        }

        // 4. Handle additional deletions (e.g., payment_allocations for invoices)
        foreach ($additional_deletions as $extra_table => $fk_column) {
            $stmt_extra = $pdo->prepare("DELETE FROM `{$extra_table}` WHERE `{$fk_column}` = ?");
            $stmt_extra->execute([$record_id]);
        }

        // 5. Delete the main record
        $stmt_main = $pdo->prepare("DELETE FROM `{$table_name}` WHERE id = ?");
        $stmt_main->execute([$record_id]);

        // 6. Log the audit (assuming log_audit function is available)
        // You might want to fetch more details of the deleted record for audit_logs if needed
        $deleted_details = ['table' => $table_name, 'id' => $record_id, 'status' => $current_status];
        // Assuming $_SESSION['admin_id'] or $_SESSION['user_id'] is set
        log_audit($pdo, 'حذف سجل مالي', $table_name, $record_id, $deleted_details, null, 'Safe delete function');

        $pdo->commit();
        return ['success' => true, 'message' => "تم حذف السجل بنجاح من {$table_name}."];
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Log the actual error for debugging
        error_log("Safe delete failed for {$table_name} ID {$record_id}: " . $e->getMessage());
        return ['success' => false, 'message' => "خطأ أثناء محاولة حذف السجل: " . $e->getMessage()];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Safe delete failed for {$table_name} ID {$record_id}: " . $e->getMessage());
        return ['success' => false, 'message' => "حدث خطأ غير متوقع: " . $e->getMessage()];
    }
}


function translateFieldValue($field, $value)
{
    if ($value === null || $value === '') {
        return '-';
    }

    $field = (string)$field;
    $normalized = strtolower(trim((string)$value));

    $enumMaps = [
        'payment_type' => [
            'cash' => 'نقدي',
            'credit' => 'آجل',
            'bank_transfer' => 'تحويل بنكي',
            'agent' => 'وكيل',
            'branch' => 'فرع',
            'draft' => 'مسودة'
        ],
        'delivery_type' => [
            'cash' => 'نقدي',
            'credit' => 'آجل',
            'bank_transfer' => 'تحويل بنكي',
            'agent' => 'وكيل',
            'branch' => 'فرع',
            'draft' => 'مسودة'
        ],
        'transaction_type' => [
            'payment' => 'سند صرف',
            'receipt' => 'سند قبض',
            'invoice' => 'فاتورة',
            'journal' => 'قيد يومية'
        ],
        'status' => [
            'draft' => 'مسودة',
            'posted' => 'مرحلة',
            'cancelled' => 'ملغاة',
            'canceled' => 'ملغاة',
            'pending' => 'معلقة'
        ],
        'entity_type' => [
            'supplier' => 'مورد',
            'customer' => 'عميل',
            'agent' => 'وكيل',
            'branch' => 'فرع',
            'employee' => 'موظف',
            'other' => 'أخرى'
        ],
        'payee_type' => [
            'supplier' => 'مورد',
            'customer' => 'عميل',
            'agent' => 'وكيل',
            'branch' => 'فرع',
            'employee' => 'موظف',
            'other' => 'أخرى'
        ],
        'payer_type' => [
            'supplier' => 'مورد',
            'customer' => 'عميل',
            'agent' => 'وكيل',
            'branch' => 'فرع',
            'employee' => 'موظف',
            'other' => 'أخرى'
        ],
        'reference_type' => [
            'invoice' => 'فاتورة',
            'receipt' => 'سند قبض',
            'payment' => 'سند صرف'
        ]
    ];

    if (isset($enumMaps[$field][$normalized])) {
        return $enumMaps[$field][$normalized];
    }

    return (string)$value;
}

function translateInvoiceCategoryValue($value)
{
    $v = strtolower(trim((string)$value));
    $map = [
        'sales' => 'بيع',
        'purchase' => 'شراء'
    ];
    return $map[$v] ?? (string)$value;
}

function translatePaymentStatusValue($value)
{
    $v = strtolower(trim((string)$value));
    $map = [
        'unpaid' => 'غير مدفوعة',
        'partial' => 'مدفوعة جزئياً',
        'partially_paid' => 'مدفوعة جزئياً',
        'fully_paid' => 'مدفوعة بالكامل',
        'posted' => 'مرحلة',
        'awaiting_approval' => 'بانتظار الاعتماد'
    ];
    return $map[$v] ?? (string)$value;
}

function translateInvoiceStatusValue($value)
{
    $v = strtolower(trim((string)$value));
    $map = [
        'draft' => 'مسودة',
        'posted' => 'مرحلة',
        'cancelled' => 'ملغاة'
    ];
    return $map[$v] ?? (string)$value;
}

function normalizeAuditCompareValue($value)
{
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($value === null) {
        return '';
    }
    return trim((string)$value);
}

function renderAuditModalContent($old_val, $new_val, $modal_id = 'default', $action = '')
{
    global $pdo;

    // جلب البيانات المرجعية المطلوبة للترجمة (تحسين: التأكد من توفر $pdo)
    static $ref_data = null;
    if ($ref_data === null && isset($pdo)) {
        $ref_data = [
            'user_names' => [],
            'customer_names' => [],
            'supplier_names' => [],
            'agent_names' => [],
            'branch_names' => [],
            'currency_names' => [],
            'account_names' => []
        ];
        try {
            foreach ($pdo->query("SELECT id, username, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u)
                $ref_data['user_names'][$u['id']] = $u['full_name'] ?: $u['username'];
            foreach ($pdo->query("SELECT id, full_name FROM customers")->fetchAll(PDO::FETCH_ASSOC) as $c)
                $ref_data['customer_names'][$c['id']] = $c['full_name'];
            foreach ($pdo->query("SELECT id, supplier_name FROM suppliers")->fetchAll(PDO::FETCH_ASSOC) as $s)
                $ref_data['supplier_names'][$s['id']] = $s['supplier_name'];
            foreach ($pdo->query("SELECT id, agent_name FROM agents")->fetchAll(PDO::FETCH_ASSOC) as $a)
                $ref_data['agent_names'][$a['id']] = $a['agent_name'];
            foreach ($pdo->query("SELECT id, branch_name FROM branches")->fetchAll(PDO::FETCH_ASSOC) as $b)
                $ref_data['branch_names'][$b['id']] = $b['branch_name'];
            foreach ($pdo->query("SELECT id, currency_name, currency_symbol FROM currencies")->fetchAll(PDO::FETCH_ASSOC) as $cur)
                $ref_data['currency_names'][$cur['id']] = $cur['currency_name'] . ' (' . $cur['currency_symbol'] . ')';
            foreach ($pdo->query("SELECT id, account_name_ar, account_code FROM unified_accounts")->fetchAll(PDO::FETCH_ASSOC) as $acc)
                $ref_data['account_names'][$acc['id']] = $acc['account_name_ar'] . ' (' . $acc['account_code'] . ')';
        } catch (Exception $e) {
        }
    }

    $old_decoded = json_decode((string)$old_val, true) ?: [];
    $new_decoded = json_decode((string)$new_val, true) ?: [];

    // تحديد نوع العملية بدقة
    $action_type = strtolower((string)$action);
    $is_create = (stripos($action_type, 'create') !== false || stripos($action_type, 'إضافة') !== false || (empty($old_decoded) && !empty($new_decoded)));
    $is_delete = (stripos($action_type, 'delete') !== false || stripos($action_type, 'حذف') !== false);
    $is_update = (!$is_create && !$is_delete);

    // الحصول على كل المفاتيح الفريدة
    $all_keys = array_unique(array_merge(array_keys($old_decoded), array_keys($new_decoded)));

    // استبعاد بعض الحقول التقنية الصرفة
    $excluded_keys = ['updated_at', 'created_at', 'invoice_id', 'transaction_id', 'created_by', 'updated_by', 'posted_by', 'cancelled_by', 'canceled_by', 'created_ip', 'updated_ip', 'posted_ip', 'cancelled_ip', 'cancelled_ip'];
    $all_keys = array_filter($all_keys, fn($k) => !in_array($k, $excluded_keys));

    // ترتيب الحقول
    $priority_keys = ['id', 'transaction_number', 'invoice_number', 'transaction_date', 'invoice_date', 'transaction_type', 'invoice_category', 'amount', 'total_amount', 'net_amount', 'party_account_id', 'cash_bank_account_id', 'payer_type', 'payee_type', 'payer_id', 'payee_id', 'branch_id', 'currency_id', 'description', 'status', 'allocations'];

    usort($all_keys, function ($a, $b) use ($priority_keys) {
        $idxA = array_search($a, $priority_keys);
        $idxB = array_search($b, $priority_keys);
        if ($idxA === false && $idxB === false) return strcmp($a, $b);
        if ($idxA === false) return 1;
        if ($idxB === false) return -1;
        return $idxA - $idxB;
    });

    $rows = '';
    $changed_keys_count = 0;
    foreach ($all_keys as $k) {
        $v_old = $old_decoded[$k] ?? null;
        $v_new = $new_decoded[$k] ?? null;

        $is_changed = (normalizeAuditCompareValue($v_old) !== normalizeAuditCompareValue($v_new));
        if ($is_changed && $is_update) $changed_keys_count++;

        $label = translateColumnName($k);

        $formatValue = function ($val, $key, $data) use ($ref_data, $pdo) {
            if ($val === null || $val === '') return '<span class="text-muted italic small">فارغ</span>';
            if ($key === 'allocations') {
                $alloc_data = is_array($val) ? $val : json_decode((string)$val, true);
                if (is_array($alloc_data) && !empty($alloc_data)) {
                    $items = [];
                    foreach ($alloc_data as $inv_id => $amt) {
                        $num = "#$inv_id";
                        try {
                            if (isset($pdo)) {
                                $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
                                $stmt->execute([$inv_id]);
                                $num = $stmt->fetchColumn() ?: "#$inv_id";
                            }
                        } catch (Exception $e) {
                        }
                        $items[] = "الفاتورة <span class='fw-bold text-primary'>$num</span>: <span class='fw-bold'>$amt</span>";
                    }
                    return " <div class='p-2 bg-white border rounded shadow-sm small'>" . implode('<br>', $items) . "</div>";
                }
                if (is_array($val)) {
                    return htmlspecialchars(json_encode($val, JSON_UNESCAPED_UNICODE));
                }
                return htmlspecialchars((string)$val);
            }

            if (is_array($val)) return htmlspecialchars(json_encode($val, JSON_UNESCAPED_UNICODE));
            if ($key === 'invoice_category') return translateInvoiceCategoryValue($val);
            if ($key === 'payer_type' || $key === 'payee_type' || $key === 'entity_type') return translateFieldValue($key, $val);

            // معالجة ذكية لمعرفات الجهات
            if (in_array($key, ['customer_id', 'party_id', 'payer_id', 'payee_id']) && is_numeric($val)) {
                $type = $data['payer_type'] ?? $data['payee_type'] ?? $data['entity_type'] ?? '';
                if ($type === 'customer') return ($ref_data['customer_names'][(int)$val] ?? "#$val");
                if ($type === 'supplier') return ($ref_data['supplier_names'][(int)$val] ?? "#$val");
                if ($type === 'agent') return ($ref_data['agent_names'][(int)$val] ?? "#$val");
                if ($type === 'branch') return ($ref_data['branch_names'][(int)$val] ?? "#$val");
                return ($ref_data['customer_names'][(int)$val] ?? $ref_data['supplier_names'][(int)$val] ?? $ref_data['agent_names'][(int)$val] ?? $ref_data['branch_names'][(int)$val] ?? "#$val");
            }

            if ($key === 'supplier_id' && is_numeric($val)) return ($ref_data['supplier_names'][(int)$val] ?? "#$val");
            if ($key === 'branch_id' && is_numeric($val)) return ($ref_data['branch_names'][(int)$val] ?? "#$val");
            if (strpos($key, 'currency_id') !== false && is_numeric($val)) return ($ref_data['currency_names'][(int)$val] ?? "#$val");
            if ($key === 'payment_status') return translatePaymentStatusValue($val);
            if ($key === 'invoice_status' || $key === 'status') return translateInvoiceStatusValue($val);
            if (in_array($key, ['account_id', 'from_account_id', 'to_account_id', 'party_account_id', 'cash_bank_account_id'], true) && is_numeric($val)) return ($ref_data['account_names'][(int)$val] ?? "#$val");
            if (in_array($key, ['created_by', 'updated_by', 'posted_by', 'changed_by', 'user_id', 'canceled_by', 'cancelled_by'], true) && is_numeric($val)) return ($ref_data['user_names'][(int)$val] ?? "#$val");

            return translateFieldValue($key, $val);
        };

        $old_display = $formatValue($v_old, $k, $old_decoded);
        $new_display = $formatValue($v_new, $k, $new_decoded);

        if ($is_create) {
            $rows .= "<tr>
                <td class='fw-bold text-dark bg-light text-end' style='width:30%'>" . htmlspecialchars($label) . "</td>
                <td class='text-dark font-monospace text-start'>" . $new_display . "</td>
            </tr>";
        } elseif ($is_delete) {
            $rows .= "<tr>
                <td class='fw-bold text-dark bg-light text-end' style='width:30%'>" . htmlspecialchars($label) . "</td>
                <td class='text-danger font-monospace text-start'>" . $old_display . "</td>
            </tr>";
        } else {
            $row_class = $is_changed ? 'table-warning border-start border-4 border-warning' : '';
            $change_badge = $is_changed ? '<span class="badge bg-warning text-dark ms-2"><i class="fas fa-edit me-1"></i> تم التعديل</span>' : '';

            $rows .= "<tr class='{$row_class}'>
                <td class='fw-bold text-dark bg-light text-end' style='width:25%'>" . htmlspecialchars($label) . " {$change_badge}</td>
                <td class='text-muted small text-start' style='width:37.5%'>" . $old_display . "</td>
                <td class='text-dark fw-bold font-monospace text-start' style='width:37.5%'>" . $new_display . "</td>
            </tr>";
        }
    }

    if ($rows === '') return '<div class="alert alert-info border-0 mb-0"><i class="fas fa-info-circle me-2"></i>لا توجد تفاصيل متاحة.</div>';

    $acc_id = 'acc_' . $modal_id;
    $collapse_id = 'coll_' . $modal_id;

    if ($is_create) {
        $header_title = "تفاصيل البيانات الجديدة (إضافة)";
        $table_header = "<tr><th class='text-end'>الحقل</th><th class='text-start'>القيمة</th></tr>";
    } elseif ($is_delete) {
        $header_title = "تفاصيل البيانات المحذوفة";
        $table_header = "<tr><th class='text-end'>الحقل</th><th class='text-start'>القيمة السابقة</th></tr>";
    } else {
        $header_title = "مقارنة الحقول (تم تعديل {$changed_keys_count} حقول)";
        $table_header = "<tr><th class='text-end'>الحقل</th><th class='text-start'>قبل التعديل</th><th class='text-start'>بعد التعديل</th></tr>";
    }

    return "
        <div class='accordion shadow-sm rounded-4 overflow-hidden' id='{$acc_id}'>
            <div class='accordion-item border-0'>
                <h2 class='accordion-header' id='heading_{$modal_id}'>
                    <button class='accordion-button bg-white fw-bold text-primary py-3' type='button' data-bs-toggle='collapse' data-bs-target='#{$collapse_id}' aria-expanded='true' aria-controls='{$collapse_id}'>
                        <div class='d-flex align-items-center'>
                            <div class='bg-primary bg-opacity-10 p-2 rounded-circle me-3 text-primary' style='width:35px; height:35px; display:flex; align-items:center; justify-content:center;'>
                                <i class='fas fa-clipboard-list small'></i>
                            </div>
                            <span>{$header_title}</span>
                        </div>
                    </button>
                </h2>
                <div id='{$collapse_id}' class='accordion-collapse collapse show' aria-labelledby='heading_{$modal_id}' data-bs-parent='#{$acc_id}'>
                    <div class='accordion-body p-0'>
                        <div class='table-responsive'>
                            <table class='table table-bordered align-middle mb-0' dir='rtl'>
                                <thead class='table-dark text-center small'>
                                    {$table_header}
                                </thead>
                                <tbody>{$rows}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    ";
}

/**
 * Accounting & Balance Functions
 */
function format_date_ar($date_str)
{
    if (empty($date_str)) return '---';
    $timestamp = strtotime($date_str);
    if (!$timestamp) return $date_str;

    $months_ar = [
        "January" => "يناير",
        "February" => "فبراير",
        "March" => "مارس",
        "April" => "أبريل",
        "May" => "مايو",
        "June" => "يونيو",
        "July" => "يوليو",
        "August" => "أغسطس",
        "September" => "سبتمبر",
        "October" => "أكتوبر",
        "November" => "نوفمبر",
        "December" => "ديسمبر"
    ];

    $month_en = date("F", $timestamp);
    $month_ar = $months_ar[$month_en] ?? $month_en;

    return date("d", $timestamp) . " " . $month_ar . " " . date("Y", $timestamp);
}

function get_party_balance($pdo, $party_type, $party_id)
{
    $table = ($party_type === 'agent') ? 'agents' : 'branches';
    $stmt = $pdo->prepare("SELECT current_balance, credit_limit FROM $table WHERE id = ?");
    $stmt->execute([$party_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function update_party_balance($pdo, $party_type, $party_id, $amount, $operation = 'add')
{
    $table = ($party_type === 'agent') ? 'agents' : 'branches';
    $op = ($operation === 'add') ? "+" : "-";

    $stmt = $pdo->prepare("UPDATE $table SET current_balance = current_balance $op ? WHERE id = ?");
    return $stmt->execute([$amount, $party_id]);
}

function check_credit_limit($pdo, $party_type, $party_id, $new_amount)
{
    $data = get_party_balance($pdo, $party_type, $party_id);
    if (!$data) return true;

    $current_balance = floatval($data['current_balance']);
    $limit = floatval($data['credit_limit']);

    // إذا كان الرصيد بعد العملية سيتجاوز الحد الائتماني (بالسالب عادة في المحاسبة)
    // أو إذا كنا نستخدم الرصيد كمديونية
    if ($limit > 0 && ($current_balance - $new_amount) < -$limit) {
        return false;
    }
    return true;
}

function generate_transfer_no($pdo, $type)
{
    $prefix = ($type === 'incoming') ? 'IN' : (($type === 'outgoing') ? 'OUT' : 'INT');
    $date = date('Ymd');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM money_transfers WHERE transfer_no LIKE ?");
    $stmt->execute([$prefix . '-' . $date . '-%']);
    $count = $stmt->fetchColumn() + 1;

    return $prefix . '-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/**
 * تسجيل حركة مالية لخدمة (عمرة، تأشيرة عمل، زيارة عائلية) بنظام ERP الموحد
 */
function record_service_transaction($pdo, $owner_type, $owner_id, $amount, $currency_id, $reference_type, $reference_id, $description)
{
    if ($amount <= 0) return true;

    $table = ($owner_type === 'agent') ? 'agents' : 'branches';
    $stmt_acc = $pdo->prepare("SELECT account_id FROM $table WHERE id = ?");
    $stmt_acc->execute([$owner_id]);
    $debit_account_id = $stmt_acc->fetchColumn();

    // الحساب الدائن عادة ما يكون حساب الإيرادات الخاص بالخدمة
    $stmt_rev = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '4-4000' LIMIT 1");
    $credit_account_id = $stmt_rev->fetchColumn();

    if (!$debit_account_id || !$credit_account_id) return false;

    $agent_id = ($owner_type === 'agent') ? $owner_id : null;
    $branch_id = ($owner_type === 'branch') ? $owner_id : null;
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;

    // ترحيل القيد (استخدام دالة PHP بدلاً من الإجراء المخزن)
    $transaction_id = php_create_financial_entry(
        $pdo,
        date('Y-m-d'),
        'receipt',
        $owner_type,
        $owner_id,
        $debit_account_id,
        $credit_account_id,
        $amount,
        $currency_id,
        $description,
        $user_id,
        $branch_id,
        $agent_id,
        null,
        $reference_type,
        $reference_id
    );

    return !empty($transaction_id);
}

function get_current_user_pricing_context($pdo)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
    if (!$user_id) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $user_type = strtolower($user['user_type'] ?? '');
    $role_name = strtolower($user['role_name'] ?? $user['role'] ?? '');
    $is_agent = $user_type === 'agent' || $role_name === 'agent';
    $is_branch = $user_type === 'branch' || $role_name === 'branch';
    $is_accountant = in_array($user_type, ['accountant']) || in_array($role_name, ['accountant', 'محاسب']);
    $is_developer = in_array($user_type, ['developer']) || in_array($role_name, ['developer', 'مبرمج', 'مطور']);
    $is_admin = in_array($user_type, ['admin']) || in_array($role_name, ['admin', 'manager', 'مدير']);
    $is_relayer = in_array($user_type, ['relayer']) || in_array($role_name, ['relayer', 'مرحل']);
    $is_privileged = $is_admin || $is_developer || $is_accountant || $is_relayer || has_permission('view_all_agents_branches');

    return [
        'user' => $user,
        'user_id' => $user_id,
        'user_type' => $user_type,
        'role_name' => $role_name,
        'agent_id' => !empty($user['agent_id']) ? (int) $user['agent_id'] : null,
        'branch_id' => !empty($user['branch_id']) ? (int) $user['branch_id'] : null,
        'is_agent' => $is_agent,
        'is_branch' => $is_branch,
        'is_admin' => $is_admin,
        'is_developer' => $is_developer,
        'is_accountant' => $is_accountant,
        'is_relayer' => $is_relayer,
        'is_privileged' => $is_privileged
    ];
}

function normalize_service_target($pdo, $requested_agent_id = null, $requested_branch_id = null)
{
    $ctx = get_current_user_pricing_context($pdo);
    if (!$ctx) {
        throw new Exception('المستخدم الحالي غير معروف.');
    }

    $agent_id = !empty($requested_agent_id) ? (int) $requested_agent_id : null;
    $branch_id = !empty($requested_branch_id) ? (int) $requested_branch_id : null;

    if ($ctx['is_agent']) {
        $agent_id = $ctx['agent_id'];
        $branch_id = null;
    } elseif ($ctx['is_branch']) {
        $branch_id = $ctx['branch_id'];
        $agent_id = null;
    } else {
        if ($agent_id) {
            $branch_id = null;
        } elseif ($branch_id) {
            $agent_id = null;
        }
    }

    $owner_type = $agent_id ? 'agent' : ($branch_id ? 'branch' : null);
    $owner_id = $agent_id ?: $branch_id;

    return [
        'context' => $ctx,
        'agent_id' => $agent_id,
        'branch_id' => $branch_id,
        'owner_type' => $owner_type,
        'owner_id' => $owner_id
    ];
}

function resolve_service_id($pdo, $service_identifier)
{
    if (is_numeric($service_identifier)) {
        return (int) $service_identifier;
    }

    $identifier = trim((string) $service_identifier);
    if ($identifier === '') {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM services
        WHERE service_type = ? OR service_name = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

function get_service_price_config($pdo, $service_id, $agent_id = null, $branch_id = null, $customer_id = null, $supplier_id = null)
{
    $service_id = resolve_service_id($pdo, $service_id);
    $agent_id = !empty($agent_id) ? (int) $agent_id : null;
    $branch_id = !empty($branch_id) ? (int) $branch_id : null;
    $customer_id = !empty($customer_id) ? (int) $customer_id : null;
    $supplier_id = !empty($supplier_id) ? (int) $supplier_id : null;

    if ($service_id <= 0) {
        return null;
    }

    $queries = [];
    // Priority: Customer -> Agent -> Branch -> Supplier -> Global
    if ($customer_id) {
        $queries[] = [
            "SELECT sp.*, c.currency_name, c.currency_symbol FROM service_prices sp LEFT JOIN currencies c ON sp.currency_id = c.id WHERE sp.service_id = ? AND sp.status = 'active' AND sp.customer_id = ? ORDER BY sp.id DESC LIMIT 1",
            [$service_id, $customer_id]
        ];
    }
    if ($agent_id) {
        $queries[] = [
            "SELECT sp.*, c.currency_name, c.currency_symbol FROM service_prices sp LEFT JOIN currencies c ON sp.currency_id = c.id WHERE sp.service_id = ? AND sp.status = 'active' AND sp.agent_id = ? ORDER BY sp.id DESC LIMIT 1",
            [$service_id, $agent_id]
        ];
    }
    if ($branch_id) {
        $queries[] = [
            "SELECT sp.*, c.currency_name, c.currency_symbol FROM service_prices sp LEFT JOIN currencies c ON sp.currency_id = c.id WHERE sp.service_id = ? AND sp.status = 'active' AND sp.branch_id = ? AND sp.agent_id IS NULL AND sp.customer_id IS NULL ORDER BY sp.id DESC LIMIT 1",
            [$service_id, $branch_id]
        ];
    }
    if ($supplier_id) {
        $queries[] = [
            "SELECT sp.*, c.currency_name, c.currency_symbol FROM service_prices sp LEFT JOIN currencies c ON sp.currency_id = c.id WHERE sp.service_id = ? AND sp.status = 'active' AND sp.supplier_id = ? ORDER BY sp.id DESC LIMIT 1",
            [$service_id, $supplier_id]
        ];
    }
    $queries[] = [
        "SELECT sp.*, c.currency_name, c.currency_symbol FROM service_prices sp LEFT JOIN currencies c ON sp.currency_id = c.id WHERE sp.service_id = ? AND sp.status = 'active' AND sp.agent_id IS NULL AND sp.branch_id IS NULL AND sp.customer_id IS NULL AND sp.supplier_id IS NULL ORDER BY sp.id DESC LIMIT 1",
        [$service_id]
    ];

    foreach ($queries as [$sql, $params]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $purchase_price = (float) ($row['agent_price'] ?: $row['branch_price'] ?: 0);
            $row['purchase_price'] = $purchase_price;
            $row['sale_price'] = (float) ($row['default_sale_price'] ?? 0);
            $row['target_type'] = $row['customer_id'] ? 'customer' : ($row['agent_id'] ? 'agent' : ($row['branch_id'] ? 'branch' : ($row['supplier_id'] ? 'supplier' : 'global')));
            return $row;
        }
    }

    return null;
}

function can_edit_service_sale_price($ctx = null)
{
    global $pdo;
    if ($ctx === null) {
        $ctx = get_current_user_pricing_context($pdo);
    }
    if (!$ctx) {
        return false;
    }

    if ($ctx['is_agent']) {
        return true;
    }

    return has_permission('services_edit_sale_price');
}

function can_edit_service_purchase_price($ctx = null)
{
    global $pdo;
    if ($ctx === null) {
        $ctx = get_current_user_pricing_context($pdo);
    }
    if (!$ctx) {
        return false;
    }

    if (!($ctx['is_admin'] || $ctx['is_developer'] || $ctx['is_accountant'])) {
        return false;
    }

    return has_permission('services_edit_purchase_price');
}

function can_edit_service_currency($ctx = null)
{
    global $pdo;
    if ($ctx === null) {
        $ctx = get_current_user_pricing_context($pdo);
    }
    if (!$ctx) {
        return false;
    }

    if (!($ctx['is_admin'] || $ctx['is_developer'] || $ctx['is_accountant'])) {
        return false;
    }

    return has_permission('services_edit_currency');
}

function create_chart_account_for_entity($pdo, $entity_type, $entity_id, $entity_name)
{
    $parent_code = '';
    $account_type = 'asset';
    $normal_balance = 'debit';

    switch ($entity_type) {
        case 'customer':
            $parent_code = '1-1100'; // مدينون (عملاء)
            $account_type = 'asset';
            $normal_balance = 'debit';
            break;
        case 'agent':
            $parent_code = '1-1200'; // مدينون (وكلاء)
            $account_type = 'asset';
            $normal_balance = 'debit';
            break;
        case 'branch':
            $parent_code = '1-1300'; // مدينون (فروع)
            $account_type = 'asset';
            $normal_balance = 'debit';
            break;
        case 'employee':
            $parent_code = '2-2200'; // دائنون (موظفين)
            $account_type = 'liability';
            $normal_balance = 'credit';
            break;
        case 'supplier':
            $parent_code = '2-2100'; // دائنون (موردين)
            $account_type = 'liability';
            $normal_balance = 'credit';
            break;
        case 'expense':
            $parent_code = '5-5700'; // مصروفات تشغيلية
            $account_type = 'expense';
            $normal_balance = 'debit';
            break;
        case 'bank':
            $parent_code = '1-1000-02'; // البنك
            $account_type = 'asset';
            $normal_balance = 'debit';
            break;
        case 'box':
            $parent_code = '1-1000-01'; // الصندوق
            $account_type = 'asset';
            $normal_balance = 'debit';
            break;
    }

    if (!$parent_code) return null;

    // جلب الـ parent_id
    $stmt_p = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
    $stmt_p->execute([$parent_code]);
    $parent_id = $stmt_p->fetchColumn();

    if (!$parent_id) {
        return null;
    }

    // توليد الكود الجديد (مثال: الأب 1-1100، الابن 1-1100-01)
    // نبحث عن أكبر كود تحت هذا الأب
    $stmt_c = $pdo->prepare("SELECT account_code FROM chart_of_accounts WHERE parent_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_c->execute([$parent_id]);
    $last_code = $stmt_c->fetchColumn();

    if ($last_code) {
        $parts = explode('-', $last_code);
        $last_num = (int)end($parts);
        $new_num = str_pad($last_num + 1, 2, '0', STR_PAD_LEFT);
        $new_code = $parent_code . '-' . $new_num;
    } else {
        $new_code = $parent_code . '-01';
    }

    // إنشاء الحساب في شجرة الحسابات
    $stmt_ins = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name_ar, account_type, parent_id, normal_balance) VALUES (?, ?, ?, ?, ?)");
    $stmt_ins->execute([$new_code, $entity_name, $account_type, $parent_id, $normal_balance]);
    return $pdo->lastInsertId();
}

function resolve_transaction_pricing($pdo, $service_id, $requested_agent_id = null, $requested_branch_id = null, $request = [])
{
    $target = normalize_service_target($pdo, $requested_agent_id, $requested_branch_id);
    $ctx = $target['context'];
    $price_config = get_service_price_config($pdo, $service_id, $target['agent_id'], $target['branch_id']);

    if (!$price_config) {
        throw new Exception('لم يتم العثور على تسعير فعال لهذه الخدمة.');
    }

    $purchase_price = (float) $price_config['purchase_price'];
    $sale_price = (float) $price_config['sale_price'];
    $currency_id = !empty($price_config['currency_id']) ? (int) $price_config['currency_id'] : null;

    if (can_edit_service_purchase_price($ctx) && isset($request['purchase_price']) && $request['purchase_price'] !== '') {
        $purchase_price = (float) $request['purchase_price'];
    }

    if (can_edit_service_currency($ctx) && !empty($request['currency_id'])) {
        $currency_id = (int) $request['currency_id'];
    }

    if (can_edit_service_sale_price($ctx) && isset($request['sale_price']) && $request['sale_price'] !== '') {
        $sale_price = (float) $request['sale_price'];
    }

    $agent_price = 0.0;
    $branch_price = 0.0;
    if ($target['owner_type'] === 'agent') {
        $agent_price = $purchase_price;
    } elseif ($target['owner_type'] === 'branch') {
        $branch_price = $purchase_price;
    }

    return [
        'context' => $ctx,
        'target' => $target,
        'pricing' => $price_config,
        'purchase_price' => $purchase_price,
        'sale_price' => $sale_price,
        'currency_id' => $currency_id,
        'agent_price' => $agent_price,
        'branch_price' => $branch_price
    ];
}

/**
 * دالة لتعديل درجة سطوع لون Hex (لتوليد ألوان الـ Hover)
 */
function adjustBrightness($hex, $steps)
{
    $steps = max(-255, min(255, $steps));
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $hex = str_repeat(substr($hex, 0, 1), 2) . str_repeat(substr($hex, 1, 1), 2) . str_repeat(substr($hex, 2, 1), 2);
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = max(0, min(255, $r + $steps));
    $g = max(0, min(255, $g + $steps));
    $b = max(0, min(255, $b + $steps));

    $r_hex = str_pad(dechex($r), 2, '0', STR_PAD_LEFT);
    $g_hex = str_pad(dechex($g), 2, '0', STR_PAD_LEFT);
    $b_hex = str_pad(dechex($b), 2, '0', STR_PAD_LEFT);

    return '#' . $r_hex . $g_hex . $b_hex;
}

/**
 * دوال الترجمة المحاسبية والعامة المضافة حديثاً
 */

/**
 * دالة مساعدة لجلب اسم الطرف بناءً على النوع والمعرف
 */
function get_party_name($type, $id)
{
    global $pdo;
    if (!$id) return 'طرف غير محدد';

    switch ($type) {
        case 'customer':
            $stmt = $pdo->prepare("SELECT full_name FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() ?: 'عميل غير موجود';
        case 'agent':
            $stmt = $pdo->prepare("SELECT agent_name FROM agents WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() ?: 'وكيل غير موجود';
        case 'supplier':
            $stmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() ?: 'مورد غير موجود';
        case 'employee':
            $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() ?: 'موظف غير موجود';
        case 'branch':
            $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() ?: 'فرع غير موجود';
        default:
            return "طرف: $type (#$id)";
    }
}

/**
 * ترجمة نوع الخدمة (المصدر)
 */
function translate_service_type($type)
{
    $map = [
        'passports' => 'الجوازات',
        'umrah' => 'العمرة',
        'visa' => 'تأشيرات',
        'bus' => 'تذاكر طيران وبصات',
        'flight' => 'تذاكر طيران وبصات',
        'busflight' => 'تذاكر طيران وبصات',
        'family_visit' => 'زيارة عائلية',
        'work_visa' => 'تأشيرات عمل',
        'expense' => 'مصروفات',
        'migration' => 'ترحيل بيانات'
    ];
    return $map[strtolower($type)] ?? strtoupper($type);
}

/**
 * ترجمة حالة الفاتورة
 */
function translate_invoice_status($status)
{
    $map = [
        'confirmed' => 'مؤكد',
        'paid' => 'مدفوع',
        'cancelled' => 'ملغى',
        'pending' => 'قيد الانتظار',
        'draft' => 'مسودة'
    ];
    return $map[strtolower($status)] ?? $status;
}


/**
 * API Security Functions (JWT & Refresh Token)
 */
function generateRefreshToken($user_id)
{
    // ملاحظة: يتطلب هذا الكلاس وجود مكتبة PHP-JWT
    // في حال عدم وجودها، يتم التعامل مع التوكن كجزء من منطق النظام المستقبلي
    if (!class_exists('Firebase\JWT\JWT')) {
        return bin2hex(random_bytes(32)); // البديل في حال غياب المكتبة
    }

    $payload = [
        'user_id' => $user_id,
        'type' => 'refresh',
        'exp' => time() + 604800 // 7 أيام
    ];

    $secret_key = getenv('JWT_SECRET_KEY') ?: 'default_secret_key_for_ghazali_system';
    return Firebase\JWT\JWT::encode($payload, $secret_key, 'HS256');
}
