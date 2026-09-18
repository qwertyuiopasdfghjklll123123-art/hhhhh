<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/services/GithubOAuth.php';

$user = require_login();

/** يسمح فقط بمسار محلي بسيط (ملف .php واختيارياً استعلام) لمنع Open Redirect */
function safe_local_return(?string $path, string $default): string
{
    if ($path !== null && preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_=&%.\-]*)?$/', $path)) {
        return $path;
    }
    return $default;
}

$returnTo = safe_local_return($_GET['return'] ?? null, 'profile.php');

$clientId     = get_app_setting('github_oauth_client_id') ?? '';
$clientSecret = get_encrypted_setting('github_oauth_client_secret') ?? '';

if ($clientId === '' || $clientSecret === '') {
    flash('error', 'لم يتم إعداد GitHub OAuth App بعد. اطلب من مسؤول النظام ضبطه من "إعدادات النظام" أولاً.');
    redirect($returnTo);
}

$redirectUri = rtrim(app_config()['app']['url'] ?? '', '/') . '/github_oauth_callback.php';

$state = bin2hex(random_bytes(24));
$_SESSION['github_oauth_state']  = $state;
$_SESSION['github_oauth_return'] = $returnTo;

$oauth = new GithubOAuth($clientId, $clientSecret, $redirectUri);
redirect($oauth->buildAuthorizeUrl($state));
