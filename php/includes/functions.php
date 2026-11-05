<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function hash_pw($pw) {
    return hash('sha256', $pw);
}

function get_setting($key, $default = '') {
    $stmt = db()->prepare('SELECT value FROM site_settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function set_setting($key, $value) {
    $stmt = db()->prepare(
        'INSERT INTO site_settings (`key`, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = ?'
    );
    $stmt->execute([$key, $value, $value]);
}

function current_user() {
    static $user = null;
    static $loaded = false;
    if ($loaded) return $user;
    $loaded = true;
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function require_login() {
    if (!current_user()) {
        header('Location: /php/login.php');
        exit;
    }
}

function require_admin() {
    $u = current_user();
    if (!$u || !$u['is_admin']) {
        http_response_code(403);
        echo 'ممنوع — صلاحيات أدمن مطلوبة';
        exit;
    }
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function next_display_id() {
    // نفس فكرة DISPLAY_ID_START بنسخة Python: رقم ظاهر للمستخدم يبدأ من 2700
    $max = db()->query('SELECT MAX(CAST(display_id AS UNSIGNED)) AS m FROM orders')->fetch();
    $start = 2700;
    $next = ($max && $max['m']) ? max($max['m'] + 1, $start) : $start;
    return (string) $next;
}
