<?php
/**
 * Centralized session configuration to avoid permission issues and conflicts
 */

// Set custom session save path first (only if no session is active yet)
if (session_status() === PHP_SESSION_NONE) {
    $session_save_path = __DIR__ . '/../sessions';
    if (!is_dir($session_save_path)) {
        mkdir($session_save_path, 0777, true);
    }
    session_save_path($session_save_path);

    // Determine secure cookie settings
    $cookieSecure = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );

    // Set cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    // Start session
    session_start();
}
