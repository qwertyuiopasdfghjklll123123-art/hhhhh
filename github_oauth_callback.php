<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/services/GithubOAuth.php';

$user = require_login();

$returnTo = $_SESSION['github_oauth_return'] ?? 'profile.php';
unset($_SESSION['github_oauth_return']);

$expectedState = $_SESSION['github_oauth_state'] ?? null;
unset($_SESSION['github_oauth_state']);

if (!empty($_GET['error'])) {
    flash('error', 'تم إلغاء ربط GitHub.');
    redirect($returnTo);
}

$state = (string) ($_GET['state'] ?? '');
$code  = (string) ($_GET['code'] ?? '');

if ($state === '' || $expectedState === null || !hash_equals($expectedState, $state)) {
    flash('error', 'انتهت صلاحية طلب الربط أو أنه غير صالح. حاول الربط مجدداً.');
    redirect($returnTo);
}
if ($code === '') {
    flash('error', 'لم يصل رمز تفويض من GitHub.');
    redirect($returnTo);
}

$clientId     = get_app_setting('github_oauth_client_id') ?? '';
$clientSecret = get_encrypted_setting('github_oauth_client_secret') ?? '';
if ($clientId === '' || $clientSecret === '') {
    flash('error', 'إعدادات GitHub OAuth App غير مكتملة.');
    redirect($returnTo);
}

$redirectUri = rtrim(app_config()['app']['url'] ?? '', '/') . '/github_oauth_callback.php';
$oauth = new GithubOAuth($clientId, $clientSecret, $redirectUri);

$tokenResult = $oauth->exchangeCodeForToken($code);
if (!$tokenResult['success']) {
    flash('error', 'تعذّر ربط GitHub: ' . $tokenResult['error']);
    redirect($returnTo);
}

$userResult = $oauth->getAuthenticatedUser($tokenResult['access_token']);
if (!$userResult['success']) {
    flash('error', 'تعذّر ربط GitHub: ' . $userResult['error']);
    redirect($returnTo);
}

db()->prepare('UPDATE users SET github_oauth_token = ?, github_oauth_username = ? WHERE id = ?')
    ->execute([Crypto::encrypt($tokenResult['access_token']), $userResult['username'], $user['id']]);

log_activity((int) $user['id'], 'github_connect', 'ربط حساب GitHub: @' . $userResult['username']);
flash('success', 'تم ربط حساب GitHub (@' . $userResult['username'] . ') بنجاح.');
redirect($returnTo);
