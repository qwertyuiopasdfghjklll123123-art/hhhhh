<?php
declare(strict_types=1);

if (!file_exists(__DIR__ . '/config/config.php')) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if ($user) {
    redirect(role_home($user['role']));
}

redirect('/login.php');
