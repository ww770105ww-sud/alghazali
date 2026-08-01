<?php
ob_start();
define('SYSTEM_ACCESS', true);
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
require_once 'db.php';
require_once 'functions.php';
$settings = getSettings($pdo);
try {
    $social_links = $pdo->query("SELECT * FROM social_links")->fetchAll();
} catch (PDOException $e) {
    $social_links = [];
}
$news_list = $pdo->query("SELECT * FROM news WHERE is_active = 1 ORDER BY created_at DESC LIMIT 10")->fetchAll();

// تحديد الصفحة الحالية
$current_file = basename($_SERVER['PHP_SELF'], ".php");

// إعدادات شريط الأخبار
$show_ticker = $settings['show_ticker'] ?? 1;
$ticker_speed = $settings['ticker_speed'] ?? 25;
$ticker_bg = $settings['ticker_bg_color'] ?? '#82c91e';

// SEO المتقدم
$page_title = ($current_file == 'index' ? '' : ' - ') . $settings['site_name'];
if ($current_file == 'about') $page_title = "من نحن" . $page_title;
elseif ($current_file == 'services') $page_title = "خدماتنا" . $page_title;
elseif ($current_file == 'contact') $page_title = "اتصل بنا" . $page_title;
else $page_title = $settings['site_name'];

$meta_desc = getPageDescription($settings, $current_file);
$meta_keys = getPageKeywords($settings, $current_file);
$canonical_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>

    <!-- SEO Meta Tags المتقدمة -->
    <meta name="description" content="<?php echo $meta_desc; ?>">
    <meta name="keywords" content="<?php echo $meta_keys; ?>">
    <meta name="author" content="<?php echo $settings['site_name']; ?>">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <link rel="canonical" href="<?php echo $canonical_url; ?>">

    <!-- Schema.org Markup (JSON-LD) لتحسين ظهور جوجل -->
    <script type="application/ld+json">
        <?php echo generateSchemaMarkup($settings); ?>
    </script>

    <!-- Google Search Console Verification -->
    <?php if (!empty($settings['google_verification'])): ?>
        <meta name="google-site-verification" content="<?php echo $settings['google_verification']; ?>" />
    <?php endif; ?>

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $canonical_url; ?>">
    <meta property="og:title" content="<?php echo $page_title; ?>">
    <meta property="og:description" content="<?php echo $meta_desc; ?>">
    <meta property="og:site_name" content="<?php echo $settings['site_name']; ?>">
    <meta property="og:locale" content="ar_AR">
    <?php if ($settings['site_logo']): ?>
        <meta property="og:image" content="assets/uploads/<?php echo $settings['site_logo']; ?>">
        <meta property="og:image:alt" content="<?php echo $settings['site_name']; ?>">
    <?php endif; ?>

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $page_title; ?>">
    <meta name="twitter:description" content="<?php echo $meta_desc; ?>">

    <link rel="icon" href="assets/uploads/<?php echo $settings['site_favicon']; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/icon-fallback.css?v=20260617">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <link rel="stylesheet" href="assets/css/custom_style.css">
    <link rel="stylesheet" href="assets/css/unified-design.css?v=20260430">
    <script>
        (function() {
            function enableIconFallback() {
                var cssReady = false;
                var fontReady = false;
                try {
                    var probe = document.createElement('i');
                    probe.className = 'fas fa-check';
                    probe.style.position = 'absolute';
                    probe.style.opacity = '0';
                    probe.style.pointerEvents = 'none';
                    document.body.appendChild(probe);
                    var content = window.getComputedStyle(probe, '::before').getPropertyValue('content');
                    cssReady = !!content && content !== 'none' && content !== 'normal' && content !== '""';
                    probe.remove();
                } catch (e) {}
                try {
                    fontReady = document.fonts && (
                        document.fonts.check('900 1em "Font Awesome 6 Free"') ||
                        document.fonts.check('900 1em "Font Awesome 5 Free"') ||
                        document.fonts.check('400 1em "Font Awesome 6 Brands"') ||
                        document.fonts.check('400 1em "Font Awesome 5 Brands"')
                    );
                } catch (e) {}
                document.documentElement.classList.toggle('fa-fallback', !cssReady || !fontReady);
            }
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(enableIconFallback);
            }
            document.addEventListener('DOMContentLoaded', enableIconFallback);
            window.addEventListener('load', enableIconFallback);
            setTimeout(enableIconFallback, 350);
            setTimeout(enableIconFallback, 1200);
        })();
    </script>

    <style>
        :root {
            --primary-color: <?php echo $settings['primary_color'] ?? '#82c91e'; ?>;
            --secondary-color: <?php echo $settings['secondary_color'] ?? '#6c757d'; ?>;
            --header-bg: <?php echo $settings['header_color'] ?? '#ffffff'; ?>;
            --footer-bg: <?php echo $settings['footer_color'] ?? '#343a40'; ?>;
            --qawafel-green: #82c91e;
            --ticker-bg: <?php echo $ticker_bg; ?>;
            --menu-btn-color: <?php echo $settings['menu_btn_color'] ?? '#82c91e'; ?>;
        }

        html,
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #ffffff;
            padding-top: 0;
            overflow-x: hidden !important;
            width: 100% !important;
            position: relative;
            margin: 0;
        }

        /* ===== NAVBAR ===== */
        .navbar {
            background-color: var(--header-bg) !important;
            box-shadow: 0 1px 0 rgba(0, 0, 0, 0.07), 0 4px 20px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
            padding: 10px 0;
        }

        .navbar-brand {
            color: #1e293b !important;
            font-weight: 900;
            font-family: 'Cairo', sans-serif;
            font-size: 1.2rem;
        }

        .nav-link {
            color: #475569 !important;
            font-weight: 600;
            font-family: 'Cairo', sans-serif;
            font-size: 0.95rem;
            padding: 8px 14px !important;
            position: relative;
            transition: color 0.2s ease !important;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 14px;
            left: 14px;
            height: 2.5px;
            background: var(--primary-color);
            border-radius: 2px;
            transform: scaleX(0);
            transition: transform 0.25s ease;
            transform-origin: center;
        }

        .nav-link:hover {
            color: var(--primary-color) !important;
        }

        .nav-link:hover::after {
            transform: scaleX(1);
        }

        .nav-link.active-page {
            color: var(--primary-color) !important;
            font-weight: 700;
        }

        .nav-link.active-page::after {
            transform: scaleX(1);
        }

        .navbar-toggler {
            background-color: var(--menu-btn-color) !important;
            border-color: var(--menu-btn-color) !important;
            border-radius: 8px;
        }

        /* زر تبديل الثيم */
        .theme-toggle-btn {
            border: 1.5px solid #e2e8f0;
            color: #475569;
            background: #f8fafc;
            border-radius: 999px;
            padding: 6px 15px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.25s ease;
            font-size: 0.88rem;
            font-family: 'Cairo', sans-serif;
            font-weight: 600;
            cursor: pointer;
        }

        .theme-toggle-btn:hover {
            background: var(--primary-color);
            color: #fff;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(130, 201, 30, 0.3);
        }

        .theme-toggle-btn i {
            transition: transform 0.4s ease;
        }

        .theme-toggle-btn:hover i {
            transform: rotate(30deg);
        }

        /* زر تسجيل الدخول */
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), #a3e635);
            border: none;
            color: #1a2e00 !important;
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: 0.88rem;
            padding: 7px 18px;
            border-radius: 999px;
            transition: all 0.25s ease;
            box-shadow: 0 3px 10px rgba(130, 201, 30, 0.3);
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(130, 201, 30, 0.4);
            color: #fff !important;
        }

        /* ===== شريط الأخبار ===== */
        .news-ticker-container {
            background-color: var(--ticker-bg);
            color: white;
            height: 40px;
            display: flex;
            align-items: center;
            overflow: hidden;
            position: relative;
            z-index: 1000;
        }

        .ticker-label {
            background-color: rgba(0, 0, 0, 0.2);
            padding: 0 20px;
            height: 100%;
            display: flex;
            align-items: center;
            font-weight: 800;
            font-size: 0.85rem;
            z-index: 10;
            white-space: nowrap;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
        }

        .ticker-content-box {
            flex: 1;
            overflow: hidden;
            position: relative;
            height: 100%;
        }

        .ticker-scroll {
            display: flex;
            white-space: nowrap;
            height: 100%;
            align-items: center;
            position: absolute;
            right: 0;
            padding-right: 100%;
            /* Start from outside */
            animation: ticker-move-rtl <?php echo $ticker_speed; ?>s linear infinite;
        }

        .ticker-news-item {
            padding: 0 40px;
            font-size: 0.9rem;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .ticker-news-item strong {
            color: rgba(255, 255, 255, 0.9);
        }

        .ticker-news-item span {
            color: #fff;
        }

        @keyframes ticker-move-rtl {
            0% {
                transform: translateX(0);
            }

            100% {
                transform: translateX(100%);
            }
        }

        .news-ticker-container:hover .ticker-scroll {
            animation-play-state: paused;
        }

        /* ===== زر التواصل العائم ===== */
        .floating-social {
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 9999;
        }

        .social-btn-main {
            width: 58px;
            height: 58px;
            background: linear-gradient(135deg, var(--qawafel-green), #a3e635);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 6px 20px rgba(130, 201, 30, 0.45);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .social-btn-main:hover {
            transform: scale(1.12) rotate(10deg);
            box-shadow: 0 8px 25px rgba(130, 201, 30, 0.55);
        }

        .social-menu {
            position: absolute;
            bottom: 70px;
            left: 0;
            display: none;
            flex-direction: column;
            gap: 10px;
        }

        .social-menu.show {
            display: flex;
        }

        .social-item {
            width: 48px;
            height: 48px;
            background-color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.12);
            color: #333;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1.5px solid rgba(0, 0, 0, 0.05);
        }

        .social-item:hover {
            transform: translateX(5px) scale(1.1);
            box-shadow: 0 5px 18px rgba(0, 0, 0, 0.18);
            color: var(--primary-color);
        }
    </style>
</head>

<body>
    <script>
        (function() {
            function getCookie(name) {
                const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
                return match ? decodeURIComponent(match[2]) : null;
            }

            function resolveTheme(theme) {
                if (theme === 'system') {
                    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                return theme;
            }

            const defaultTheme = '<?php echo $settings['default_theme'] ?? 'light'; ?>';
            const savedTheme = localStorage.getItem('theme') || getCookie('theme') || defaultTheme;
            const theme = resolveTheme(savedTheme);

            if (theme === 'dark') {
                document.body.classList.add('dark-mode');
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>
    <nav class="navbar navbar-expand-lg sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <?php if ($settings['site_logo']): ?>
                    <img src="assets/uploads/<?php echo $settings['site_logo']; ?>" alt="<?php echo $settings['site_name']; ?> - وكالة سفريات وسياحة" style="height: 45px; width: auto; object-fit: contain;">
                <?php else: ?>
                    <span class="fw-bold text-primary"><?php echo $settings['site_name']; ?></span>
                <?php endif; ?>
            </a>
            <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="تبديل القائمة">
                <i class="fas fa-bars fs-3 text-primary"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item"><a class="nav-link <?php echo $current_file == 'index' ? 'active-page' : ''; ?>" href="index.php">الرئيسية</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $current_file == 'about' ? 'active-page' : ''; ?>" href="about.php">من نحن</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $current_file == 'services' ? 'active-page' : ''; ?>" href="services.php">خدماتنا</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $current_file == 'contact' ? 'active-page' : ''; ?>" href="contact.php">اتصل بنا</a></li>
                </ul>
                <ul class="navbar-nav align-items-lg-center gap-lg-2">
                    <?php if (!empty($settings['show_theme_toggle_button'])): ?>
                        <li class="nav-item">
                            <button class="theme-toggle-btn mt-2 mt-lg-0" id="themeToggle" onclick="toggleTheme()" title="تبديل الليل/النهار">
                                <i class="fas fa-moon" id="themeIcon"></i>
                                <span class="d-none d-lg-inline">الوضع</span>
                            </button>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($settings['show_login_button'])): ?>
                        <li class="nav-item">
                            <a class="btn-login mt-2 mt-lg-0" href="admin/login.php">
                                <i class="fas fa-sign-in-alt"></i> دخول
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <?php if ($show_ticker && !empty($news_list)): ?>
        <div class="news-ticker-container">
            <div class="ticker-label">آخر الأخبار</div>
            <div class="ticker-content-box" style="flex:1; overflow:hidden; position:relative; height:100%;">
                <div class="ticker-scroll">
                    <?php foreach ($news_list as $news): ?>
                        <div class="ticker-news-item" style="padding:0 50px;">
                            <strong><?php echo htmlspecialchars($news['title']); ?>:</strong>
                            <span><?php echo htmlspecialchars($news['content'] ?? ''); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="floating-social">
        <div class="social-menu" id="socialMenu">
            <?php foreach ($social_links as $link): ?>
                <a href="<?php echo $link['link_url']; ?>" target="_blank" class="social-item" title="<?php echo $link['platform_name']; ?>">
                    <i class="<?php echo $link['platform_icon']; ?>"></i>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="social-btn-main" onclick="document.getElementById('socialMenu').classList.toggle('show')">
            <i class="fas fa-comments"></i>
        </div>
    </div>
    <div class="content-wrapper" style="margin-top: 0 !important; padding-top: 0 !important; border-top: none !important;">
