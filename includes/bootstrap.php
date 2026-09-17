<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!is_installed()) {
    header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') ? '../install.php' : 'install.php'));
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';

start_secure_session();
send_security_headers();

date_default_timezone_set(app_config()['app']['timezone'] ?? 'UTC');
