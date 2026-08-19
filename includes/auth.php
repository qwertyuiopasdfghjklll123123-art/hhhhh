<?php
/**
 * منطق تسجيل الدخول، الجلسات، وحماية الصفحات حسب الصلاحية (Role Guard).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_attempt(PDO $pdo, string $username, string $password): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    $employee = null;
    if ($user['employee_id']) {
        $empStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $empStmt->execute([$user['employee_id']]);
        $employee = $empStmt->fetch() ?: null;
    }

    $sessionUser = [
        'id' => (int) $user['id'],
        'role' => $user['role'],
        'username' => $user['username'],
        'employee_id' => $user['employee_id'] ? (int) $user['employee_id'] : null,
        'branch_id' => $user['branch_id'] ? (int) $user['branch_id'] : null,
        'full_name' => $employee['full_name'] ?? ($user['role'] === 'hr' ? 'مدير الموارد البشرية' : $user['username']),
    ];

    $_SESSION['user'] = $sessionUser;
    return $sessionUser;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }
    return $user;
}

/**
 * @param string[] $roles الأدوار المسموح لها بالدخول لهذه الصفحة
 */
function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('لا تملك صلاحية الوصول إلى هذه الصفحة.');
    }
    return $user;
}

function role_home(string $role): string
{
    return match ($role) {
        'hr' => '/hr.php',
        'branch_manager' => '/branch.php',
        'employee' => '/employee.php',
        default => '/login.php',
    };
}
