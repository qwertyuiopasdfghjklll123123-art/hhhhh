<?php
declare(strict_types=1);

/** بيانات المستخدم الحالي من قاعدة البيانات (وليس من الجلسة فقط) حتى ينعكس تعطيل الحساب فوراً */
function current_user(): ?array
{
    static $cached = null;
    static $resolved = false;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        logout(false);
        return null;
    }

    $cached = $user;
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }

    $timeout = 4 * 60 * 60; // 4 ساعات خمول
    if (!empty($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $timeout) {
        logout(false);
        flash('error', 'انتهت الجلسة بسبب عدم النشاط، الرجاء تسجيل الدخول مجدداً.');
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();

    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('غير مصرح لك بالوصول لهذه الصفحة. هذه الصلاحية مخصصة للمسؤولين فقط.');
    }
    return $user;
}

/* ------------------------------------------------------------------ */
/* الحماية من هجمات تخمين كلمة المرور (Brute-force)                    */
/* ------------------------------------------------------------------ */

function login_identifier(string $email): string
{
    return ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower(trim($email));
}

function check_login_throttle(string $identifier): ?string
{
    $stmt = db()->prepare('SELECT locked_until FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$identifier]);
    $row = $stmt->fetch();
    if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
        $remaining = (int) ceil((strtotime($row['locked_until']) - time()) / 60);
        return "تم إيقاف محاولات الدخول مؤقتاً بسبب تكرار الفشل. حاول مجدداً بعد {$remaining} دقيقة.";
    }
    return null;
}

function register_failed_login(string $identifier): void
{
    $stmt = db()->prepare('SELECT id, attempts FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$identifier]);
    $row = $stmt->fetch();

    if ($row) {
        $attempts = (int) $row['attempts'] + 1;
        $lockedUntil = $attempts >= 5 ? (new DateTime('+15 minutes'))->format('Y-m-d H:i:s') : null;
        db()->prepare('UPDATE login_attempts SET attempts = ?, locked_until = ? WHERE id = ?')
            ->execute([$attempts, $lockedUntil, $row['id']]);
    } else {
        db()->prepare('INSERT INTO login_attempts (identifier, attempts) VALUES (?, 1)')->execute([$identifier]);
    }
}

function clear_login_throttle(string $identifier): void
{
    db()->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$identifier]);
}

/**
 * يحاول تسجيل الدخول. يعيد ['success'=>bool,'message'=>?string]
 */
function attempt_login(string $email, string $password): array
{
    $identifier = login_identifier($email);

    $lockMessage = check_login_throttle($identifier);
    if ($lockMessage !== null) {
        return ['success' => false, 'message' => $lockMessage];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        register_failed_login($identifier);
        usleep(300000);
        log_activity($user['id'] ?? null, 'login_failed', 'محاولة دخول فاشلة: ' . $email);
        return ['success' => false, 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'هذا الحساب معطَّل. الرجاء التواصل مع مسؤول النظام.'];
    }

    clear_login_throttle($identifier);
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['last_activity'] = time();

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    log_activity((int) $user['id'], 'login', 'تسجيل دخول ناجح');

    return ['success' => true, 'message' => null];
}

function logout(bool $logAction = true): void
{
    $uid = $_SESSION['user_id'] ?? null;
    if ($logAction && $uid) {
        log_activity((int) $uid, 'logout', 'تسجيل خروج');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
