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

/** نسبة خصم مستوى المستخدم الحالي بناءً على إجمالي مشترياته المكتملة (مكافئ _get_user_level_discount) */
function get_user_level_discount($uid) {
    $stmt = db()->prepare("SELECT COALESCE(SUM(CAST(charge AS DECIMAL(14,4))),0) AS total FROM orders WHERE user_id=? AND status='Completed'");
    $stmt->execute([$uid]);
    $totalSpent = (float)$stmt->fetch()['total'];
    $levels = db()->query('SELECT discount, min_spent FROM levels WHERE active=1 ORDER BY min_spent ASC')->fetchAll();
    $discount = 0.0;
    foreach ($levels as $lv) {
        if ($totalSpent >= (float)$lv['min_spent']) {
            $discount = (float)$lv['discount'];
        }
    }
    return $discount;
}

/** المستوى الحالي للمستخدم (للعرض بالواجهة) */
function get_user_level($uid) {
    $stmt = db()->prepare("SELECT COALESCE(SUM(CAST(charge AS DECIMAL(14,4))),0) AS total FROM orders WHERE user_id=? AND status='Completed'");
    $stmt->execute([$uid]);
    $totalSpent = (float)$stmt->fetch()['total'];
    $levels = db()->query('SELECT * FROM levels WHERE active=1 ORDER BY min_spent ASC')->fetchAll();
    $current = null;
    foreach ($levels as $lv) {
        if ($totalSpent >= (float)$lv['min_spent']) {
            $current = $lv;
        }
    }
    return $current;
}

/**
 * ينشئ إشعاراً داخل التطبيق للمستخدم ويقصّ القديم (يحتفظ بآخر 50 فقط).
 * ملاحظة: إشعارات المتصفح (Web Push/VAPID) من نسخة Python غير مُنقولة بعد.
 */
function push_user_notif($userId, $type, $title, $text, $icon = 'bell', $goto = '') {
    $id = bin2hex(random_bytes(4));
    db()->prepare('INSERT INTO notifications (id, user_id, type, title, text, icon, goto, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $userId, $type, $title, $text, $icon, $goto, time()]);
    db()->prepare(
        'DELETE FROM notifications WHERE user_id = ? AND id NOT IN (
            SELECT id FROM (SELECT id FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50) t
        )'
    )->execute([$userId, $userId]);
}

/** يرجع صف referrals لمستخدم، ينشئه بكود جديد إذا غير موجود (مكافئ _get_or_create_referral) */
function get_or_create_referral($uid) {
    $stmt = db()->prepare('SELECT * FROM referrals WHERE user_id=?');
    $stmt->execute([$uid]);
    $ref = $stmt->fetch();
    if (!$ref) {
        $code = 'FC' . strtoupper(bin2hex(random_bytes(3)));
        db()->prepare('INSERT INTO referrals (user_id, code, created_at) VALUES (?,?,?)')->execute([$uid, $code, time()]);
        $stmt->execute([$uid]);
        $ref = $stmt->fetch();
    }
    return $ref;
}

define('REFERRAL_COMMISSION', 5);
define('REFERRAL_MIN_WITHDRAW', 1.0);

/** يضيف عمولة إحالة لمن أحال هذا المستخدم عند اكتمال طلب (مكافئ _add_referral_commission) */
function add_referral_commission($uid, $orderId, $cost) {
    try {
        $stmt = db()->prepare('SELECT * FROM referrals WHERE user_id=?');
        $stmt->execute([$uid]);
        $ref = $stmt->fetch();
        if ($ref && $ref['referred_by']) {
            $commission = round($cost * REFERRAL_COMMISSION / 100, 4);
            if ($commission > 0) {
                db()->prepare('UPDATE referrals SET pending = pending + ? WHERE user_id = ?')->execute([$commission, $ref['referred_by']]);
                db()->prepare('INSERT INTO referral_history (referrer_id, amount, from_order, created_at) VALUES (?,?,?,?)')
                    ->execute([$ref['referred_by'], $commission, $orderId, time()]);
            }
        }
    } catch (Exception $e) {
        // لا نكسر تدفق الطلب الأساسي بسبب فشل عمولة إحالة
    }
}

function next_display_id() {
    // نفس فكرة DISPLAY_ID_START بنسخة Python: رقم ظاهر للمستخدم يبدأ من 2700
    $max = db()->query('SELECT MAX(CAST(display_id AS UNSIGNED)) AS m FROM orders')->fetch();
    $start = 2700;
    $next = ($max && $max['m']) ? max($max['m'] + 1, $start) : $start;
    return (string) $next;
}
