<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (!is_installed()) {
    redirect('install.php');
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

start_secure_session();

redirect(current_user() ? 'dashboard.php' : 'login.php');
