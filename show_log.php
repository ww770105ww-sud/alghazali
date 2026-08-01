<?php
$log_file = __DIR__ . '/service_accounts_save.log';
if (file_exists($log_file)) {
    echo '<pre>';
    echo file_get_contents($log_file);
    echo '</pre>';
} else {
    echo 'Log file not found yet!';
}
