<?php
// يُضمَّن في بداية كل صفحة تحت admin/ - يجهز الجلسة، الاتصال بقاعدة البيانات، CSRF ورسائل التنبيه
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../includes/functions.php';

if (!file_exists(__DIR__ . '/../../config.php')) {
    header('Location: ../install.php');
    exit;
}
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/data.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 315360000);
ini_set('session.cookie_lifetime', 315360000);
session_name('Almulla_SECURE');
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getDb();

function admin_require_login() {
    if (!isAdmin()) {
        header('Location: login.php');
        exit;
    }
}

function admin_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
}

function admin_verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        admin_flash('error', 'انتهت صلاحية الجلسة، حاول مرة أخرى.');
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
}

function admin_flash($type, $message) {
    $_SESSION['admin_flash'][] = ['type' => $type, 'message' => $message];
}

function admin_get_flashes() {
    $flashes = $_SESSION['admin_flash'] ?? [];
    unset($_SESSION['admin_flash']);
    return $flashes;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
