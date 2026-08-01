<?php
/**
 * PHP Built-in Server Router for alghazali ERP
 * Handles Pretty URLs (mimics .htaccess mod_rewrite) for /admin/ area.
 * Serves static files directly; falls back to .php resolution.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docRoot = __DIR__;

// Normalize: strip leading slash for file resolution
$path = ltrim($uri, '/');

// 1) Serve real existing file as-is (static assets: css, js, images, uploads)
if ($path !== '' && is_file($docRoot . '/' . $path)) {
    return false; // let PHP built-in server serve the static file
}

// 2) Real existing directory with index.php
if ($path !== '' && is_dir($docRoot . '/' . $path)) {
    $idx = rtrim($docRoot . '/' . $path, '/') . '/index.php';
    if (is_file($idx)) {
        require $idx;
        return true;
    }
}

// 3) Pretty URL -> append .php if the .php file exists (mimics RewriteRule ^([^.]+)$ $1.php)
if ($path !== '') {
    $candidate = $docRoot . '/' . $path;
    if (!preg_match('/\.[a-zA-Z0-9]+$/', $path) && is_file($candidate . '.php')) {
        require $candidate . '.php';
        return true;
    }
}

// 4) Root -> index.php
if ($path === '' || $path === '/' || $uri === '/' ) {
    require $docRoot . '/index.php';
    return true;
}

// 5) 404 fallback
http_response_code(404);
echo "<h1>404 - Not Found</h1>";
echo "<p>The requested URL " . htmlspecialchars($uri) . " was not found.</p>";
return true;
