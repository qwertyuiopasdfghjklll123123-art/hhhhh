<?php
/**
 * واصل — قوالب HTML: شاشة تسجيل الدخول وهيكل التطبيق (الشريط الجانبي).
 * التصميم نفس تصميم نظام الموارد البشرية: تركوازي/ذهبي (#006b73 / #c99a3d)
 * وخط IBM Plex Sans Arabic (مستضاف محلياً بمجلد assets).
 */

require_once __DIR__ . '/core.php';

function asset($path)
{
    return h($path) . '?v=' . WASEL_VERSION;
}

/** شعار واصل الافتراضي: فقاعة محادثة بيضاء فيها ثلاث نقاط (نفس أيقونة التطبيق). */
function logo_svg($size = 38)
{
    return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 100 100" fill="none" aria-hidden="true">'
        . '<ellipse cx="50" cy="46" rx="32" ry="26" fill="#fff"/><path d="M29 62 23 84 46 69Z" fill="#fff"/>'
        . '<circle cx="37" cy="46" r="5" fill="#006b73"/><circle cx="50" cy="46" r="5" fill="#006b73"/><circle cx="63" cy="46" r="5" fill="#c99a3d"/></svg>';
}

function send_security_headers()
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function render_login_page($error = '', $email = '')
{
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    $site = h(site_name());
    $logoUrl = site_logo_url();
    $logo = $logoUrl
        ? '<img src="' . h($logoUrl) . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">'
        : logo_svg(38);
    $errorHtml = $error !== '' ? '<p class="status-msg error">' . h($error) . '</p>' : '';
    $csrf = h(csrf_token());
    $emailVal = h($email);
    $fonts = asset('assets/vendor/fonts/plex.css');
    echo <<<HTML
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#006b73">
<meta name="robots" content="noindex">
<title>{$site} - تسجيل الدخول</title>
<link rel="manifest" href="index.php?pwa=manifest">
<link rel="stylesheet" href="{$fonts}">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
html, body { width:100%; min-height:100%; }
body { font-family:'IBM Plex Sans Arabic','Cairo','Tajawal',system-ui,sans-serif; color:#1A2E35; background:#004b52; }
button, a { font:inherit; border:0; cursor:pointer; background:none; }
a { text-decoration:none; color:inherit; }
input { font:inherit; outline:none; }
body.leaving { animation: pageFadeOut .13s ease forwards; }
@keyframes pageFadeOut { to { opacity:0; transform:scale(.98); } }
.login-page { width:100%; min-height:100vh; background:linear-gradient(135deg, #004b52 0%, #006b73 100%);
  display:flex; align-items:center; justify-content:center; padding:24px; animation:fadeIn .3s ease; }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.login-card { background:#fff; border-radius:20px; padding:40px 32px; max-width:420px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.login-logo { text-align:center; margin-bottom:26px; }
.login-logo .logo-icon { width:64px; height:64px; margin:0 auto 12px; border-radius:20px;
  background:linear-gradient(135deg, #006b73, #0A8A94); display:inline-flex; align-items:center; justify-content:center;
  box-shadow:0 8px 32px rgba(0,107,115,.35); overflow:hidden; }
.login-logo h2 { font-size:21px; font-weight:700; color:#1A2E35; }
.login-logo p { margin-top:4px; color:#8AA0B0; font-size:13px; font-weight:400; }
.input-group { margin-top:16px; text-align:right; }
.input-group:first-of-type { margin-top:0; }
.input-group label { display:block; font-size:13px; font-weight:700; color:#4A6A78; margin-bottom:6px; }
.input-group input { width:100%; height:48px; padding:0 16px; border:2px solid rgba(0,107,115,.08); border-radius:10px;
  background:#F0F4F8; color:#1A2E35; font-size:14px; text-align:right; }
.input-group input[dir="ltr"] { text-align:left; }
.input-group input:focus { border-color:#006b73; box-shadow:0 0 0 4px rgba(0,107,115,.06); }
.input-group input::placeholder { color:#8AA0B0; }
.btn-primary { width:100%; height:48px; margin-top:20px; border-radius:12px; background:linear-gradient(135deg, #006b73, #0A8A94);
  color:#fff; font-size:15px; font-weight:700; display:flex; align-items:center; justify-content:center; gap:8px;
  box-shadow:0 4px 16px rgba(0,107,115,.3); transition:.2s ease; }
.btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(0,107,115,.4); }
.btn-primary svg { width:16px; height:16px; }
.form-row-between { display:flex; align-items:center; justify-content:space-between; margin-top:14px; font-size:12px; }
.remember-check { display:flex; align-items:center; gap:5px; color:#4A6A78; }
.remember-check input { width:auto; accent-color:#006b73; }
.forgot-link { color:#006b73; font-weight:600; }
.status-msg { font-size:12px; color:#4A6A78; margin-top:12px; text-align:center; font-weight:500; min-height:16px; }
.status-msg.error { color:#DC2626; margin:0 0 14px; }
.status-msg.success { color:#059669; }
@media (max-width:480px) {
  .login-card { padding:28px 22px; border-radius:16px; }
  .login-logo .logo-icon { width:56px; height:56px; }
  .login-logo h2 { font-size:19px; }
}
</style>
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-icon">{$logo}</div>
      <h2>{$site}</h2>
      <p>تسجيل الدخول — سجل دخولك للوصول إلى حسابك وإدارة حملاتك</p>
    </div>
    {$errorHtml}
    <form method="post" action="index.php">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf" value="{$csrf}">
      <div class="input-group">
        <label>البريد الإلكتروني</label>
        <input type="email" name="email" value="{$emailVal}" placeholder="example@domain.com" dir="ltr" autocomplete="username" required>
      </div>
      <div class="input-group">
        <label>كلمة المرور</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
      </div>
      <div class="form-row-between">
        <label class="remember-check"><input type="checkbox" name="remember" value="1"> تذكرني</label>
        <a href="#" class="forgot-link" onclick="showForgot(event)">نسيت كلمة المرور؟</a>
      </div>
      <button class="btn-primary" type="submit">تسجيل الدخول <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 3h6v6"/><path d="M10 14L21 3"/><path d="M12 11v7H5V5h7"/></svg></button>
    </form>
    <div class="status-msg" id="emailStatus"></div>
  </div>
</div>
<script>
(function () {
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (document.body.classList.contains('leaving')) return;
      e.preventDefault();
      document.body.classList.add('leaving');
      setTimeout(function () { form.submit(); }, 130);
    });
  });
})();
function showForgot(e) {
  e.preventDefault();
  var el = document.getElementById('emailStatus');
  el.textContent = 'لإعادة تعيين كلمة المرور، تواصل مع إدارة المنصة.';
  el.className = 'status-msg success';
}
</script>
</body>
</html>
HTML;
}

function render_app_page(array $user)
{
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $site = h(site_name());
    $boot = [
        'isAdmin' => (bool) $user['is_admin'],
        'user' => ['id' => $user['id'], 'name' => $user['name'] ?: $user['email'], 'email' => $user['email']],
        'siteName' => site_name(),
        'logoUrl' => site_logo_url(),
        'csrf' => csrf_token(),
        'defaultCountryCode' => DEFAULT_COUNTRY_CODE,
        'defaultMessage' => DEFAULT_MESSAGE,
        'defaultDelay' => DEFAULT_DELAY,
        'minDelay' => MIN_DELAY,
        'defaultSplitTime' => DEFAULT_SPLIT_TIME,
        'maxNumbers' => MAX_NUMBERS_PER_CAMPAIGN,
        'today' => date('Y-m-d'),
        'version' => WASEL_VERSION,
    ];
    $bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $csrf = h(csrf_token());
    $fa = asset('assets/vendor/fa/css/all.min.css');
    $fonts = asset('assets/vendor/fonts/plex.css');
    $css = asset('assets/app.css');
    $js = asset('assets/app.js');
    $icon = h(site_logo_url() ?: 'assets/icon-192.png');
    echo <<<HTML
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>{$site}</title>
<link rel="manifest" href="index.php?pwa=manifest">
<meta name="theme-color" content="#006b73">
<link rel="icon" href="{$icon}">
<link rel="apple-touch-icon" href="{$icon}">
<link rel="stylesheet" href="{$fonts}">
<link rel="stylesheet" href="{$fa}">
<link rel="stylesheet" href="{$css}">
</head>
<body>

<div id="pwaInstallBanner" class="pwa-banner">
  <img id="pwaBannerIcon" src="{$icon}" alt="">
  <span id="pwaBannerText">اختياري: أضف أيقونة الموقع للشاشة الرئيسية حتى تفتحه بسرعة</span>
  <button class="pwa-banner-install" onclick="installPwa()">إضافة الأيقونة</button>
  <button class="pwa-banner-dismiss" onclick="dismissPwaBanner()" aria-label="إغلاق">&times;</button>
</div>

<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="logo" id="brandLogo">و</div>
    <div class="name" id="brandName">{$site}</div>
  </div>
  <nav class="nav-menu" id="navMenu"></nav>
  <div class="user-info">
    <div class="avatar" id="sidebarAvatar">؟</div>
    <div class="info">
      <div class="name" id="sidebarUserName"></div>
      <div class="role" id="sidebarUserRole"></div>
    </div>
    <button class="logout-btn" onclick="logoutPlatform()" title="تسجيل الخروج"><i class="fas fa-right-from-bracket"></i></button>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="confirm-overlay" id="confirmOverlay" onclick="closeConfirmSheet()"></div>
<div class="confirm-sheet" id="confirmSheet">
  <div class="sheet-handle"></div>
  <div class="sheet-title"><i class="fas fa-right-from-bracket"></i> تسجيل الخروج</div>
  <div class="sheet-message">هل أنت متأكد من رغبتك في تسجيل الخروج؟</div>
  <div class="sheet-actions">
    <button class="btn-cancel" onclick="closeConfirmSheet()"><i class="fas fa-times"></i> إلغاء</button>
    <button class="btn-confirm" onclick="confirmLogout()"><i class="fas fa-right-from-bracket"></i> تأكيد</button>
  </div>
</div>

<div class="confirm-overlay" id="addAccountOverlay" onclick="closeAddAccountSheet()"></div>
<div class="confirm-sheet" id="addAccountSheet">
  <div class="sheet-handle"></div>
  <div class="sheet-title"><i class="fas fa-plus"></i> إضافة حساب واتساب</div>
  <div class="sheet-message">اسم يميّز الرقم اللي ترسل منه الحملات، ورقمه (اختياري — للتذكير وقت الإرسال)</div>
  <input type="text" id="newAccountName" maxlength="60" placeholder="مثال: واتساب المبيعات" style="margin-bottom:10px" onkeydown="if(event.key==='Enter')submitAddAccount()">
  <input type="tel" id="newAccountPhone" maxlength="20" dir="ltr" placeholder="رقم الواتساب: 07701234567 (اختياري)" style="margin-bottom:16px;text-align:right" onkeydown="if(event.key==='Enter')submitAddAccount()">
  <div class="sheet-actions">
    <button class="btn-cancel" onclick="closeAddAccountSheet()"><i class="fas fa-times"></i> إلغاء</button>
    <button class="btn-confirm-gold" onclick="submitAddAccount()"><i class="fas fa-plus"></i> إضافة</button>
  </div>
</div>

<div class="confirm-overlay" id="infoOverlay" onclick="closeInfoSheet()"></div>
<div class="confirm-sheet" id="infoSheet">
  <div class="sheet-handle"></div>
  <div id="infoSheetBody"></div>
</div>

<main class="main-content">
  <header class="top-header">
    <button class="mobile-menu-toggle" onclick="toggleSidebar()" aria-label="القائمة"><i class="fas fa-bars"></i></button>
    <div class="header-title" id="headerTitle"></div>
    <div class="header-actions">
      <button class="icon-btn" id="bellBtn" onclick="showSection('notifications')" title="الإشعارات">
        <i class="fas fa-bell"></i>
        <span class="badge" id="bellBadge" style="display:none"></span>
      </button>
    </div>
  </header>
  <div id="pageContent"><div class="content-inner" id="content"></div></div>
</main>

<form id="logoutForm" method="post" action="index.php" style="display:none">
  <input type="hidden" name="action" value="logout">
  <input type="hidden" name="csrf" value="{$csrf}">
</form>
<div class="toast" id="toast"></div>

<script>window.WASEL = {$bootJson};</script>
<script src="{$js}"></script>
</body>
</html>
HTML;
}
