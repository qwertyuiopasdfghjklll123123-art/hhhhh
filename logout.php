<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('dashboard.php');
}

verify_csrf();
logout();
flash('success', 'تم تسجيل الخروج بنجاح.');
redirect('login.php');
