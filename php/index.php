<?php
require_once __DIR__ . '/includes/functions.php';
if (current_user()) {
    header('Location: dashboard');
} else {
    header('Location: login');
}
exit;
