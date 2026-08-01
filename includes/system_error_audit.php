<?php

/**
 * System error audit logger (DB) for admin panel.
 *
 * - Safe: silently fails if DB not ready.
 * - Minimal recursion: guards against re-entrancy.
 */

function ensure_system_error_audit_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $existsStmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'system_error_audit'
        ");
        $existsStmt->execute();
        if ((int)$existsStmt->fetchColumn() > 0) {
            return;
        }

        $pdo->exec("
            CREATE TABLE `system_error_audit` (
              `id` BIGINT NOT NULL AUTO_INCREMENT,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `level` VARCHAR(32) NOT NULL,
              `errno` INT NULL,
              `message` TEXT NOT NULL,
              `file` TEXT NULL,
              `line` INT NULL,
              `trace` MEDIUMTEXT NULL,
              `url` TEXT NULL,
              `method` VARCHAR(16) NULL,
              `ip_address` VARCHAR(64) NULL,
              `user_agent` TEXT NULL,
              `user_id` INT NULL,
              `context_json` MEDIUMTEXT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_created_at` (`created_at`),
              KEY `idx_level` (`level`),
              KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (Throwable $e) {
        // Silent fail.
    }
}

function system_error_level_from_errno(int $errno): string
{
    return match ($errno) {
        E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
        E_WARNING, E_USER_WARNING => 'warning',
        E_PARSE => 'parse',
        E_NOTICE, E_USER_NOTICE => 'notice',
        E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
        default => 'unknown',
    };
}

function log_system_error_audit(PDO $pdo, array $payload): void
{
    static $in = false;
    if ($in) return;
    $in = true;

    try {
        ensure_system_error_audit_table($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO system_error_audit
            (level, errno, message, file, line, trace, url, method, ip_address, user_agent, user_id, context_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            (string)($payload['level'] ?? 'error'),
            isset($payload['errno']) ? (int)$payload['errno'] : null,
            (string)($payload['message'] ?? ''),
            $payload['file'] ?? null,
            isset($payload['line']) ? (int)$payload['line'] : null,
            $payload['trace'] ?? null,
            $payload['url'] ?? null,
            $payload['method'] ?? null,
            $payload['ip_address'] ?? null,
            $payload['user_agent'] ?? null,
            isset($payload['user_id']) ? (int)$payload['user_id'] : null,
            $payload['context_json'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Silent fail.
    } finally {
        $in = false;
    }
}

function register_system_error_audit(PDO $pdo): void
{
    // Error handler: capture warnings/notices too (except those suppressed with @).
    set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($pdo) {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        // Avoid log spam for deprecations by default.
        if (in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return false;
        }

        $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
        $url = null;
        $method = null;
        if (PHP_SAPI !== 'cli') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $url = ($host !== '') ? ($scheme . '://' . $host . $uri) : $uri;
            $method = $_SERVER['REQUEST_METHOD'] ?? null;
        }

        log_system_error_audit($pdo, [
            'level' => system_error_level_from_errno((int)$errno),
            'errno' => (int)$errno,
            'message' => (string)$errstr,
            'file' => (string)$errfile,
            'line' => (int)$errline,
            'trace' => null,
            'url' => $url,
            'method' => $method,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'user_id' => $user_id ? (int)$user_id : null,
        ]);

        return false; // Let normal PHP handling continue (display/logging).
    });

    // Exception handler: log uncaught exceptions.
    set_exception_handler(function (Throwable $e) use ($pdo) {
        $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
        $url = null;
        $method = null;
        if (PHP_SAPI !== 'cli') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $url = ($host !== '') ? ($scheme . '://' . $host . $uri) : $uri;
            $method = $_SERVER['REQUEST_METHOD'] ?? null;
        }

        log_system_error_audit($pdo, [
            'level' => 'exception',
            'errno' => null,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'url' => $url,
            'method' => $method,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'user_id' => $user_id ? (int)$user_id : null,
        ]);

        throw $e;
    });
}

