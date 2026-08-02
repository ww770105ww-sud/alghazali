<?php
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

// Provide a helper to skip DB tests if PDO MySQL not available
if (!function_exists('skip_if_no_db')) {
    function skip_if_no_db(\PHPUnit\Framework\TestCase $test)
    {
        if (!extension_loaded('pdo_mysql')) {
            $test->markTestSkipped('pdo_mysql extension not available.');
        }
    }
}
