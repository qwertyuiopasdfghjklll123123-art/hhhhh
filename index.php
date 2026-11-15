<?php
/**
 * واصل — منصة حملات واتساب متعددة الفروع (نسخة PHP لاستضافة cPanel).
 *
 * هذا الملف هو مدخل الموقع: شاشة الدخول، لوحة التحكم، وواجهة JSON
 * (index.php?api=...). الإرسال تلقائي من السيرفر عبر خدمة واتساب API
 * (Green-API / UltraMsg — inc/autosend.php و cron.php)، أو يدوي بالضغط من
 * جوال أو كمبيوتر الفرع. ما يحتاج VPS ولا تثبيت أي شي على أجهزة الفروع.
 *
 * أول مرة: افتح install.php لإنشاء قاعدة البيانات.
 */

require __DIR__ . '/inc/core.php';

if (!wasel_installed()) {
    header('Location: install.php');
    exit;
}

try {
    wasel_boot(!isset($_GET['pwa']));
} catch (PDOException $e) {
    error_log('[wasel] DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><div dir="rtl" style="font-family:sans-serif;padding:40px;text-align:center">'
        . '<h2>تعذّر الاتصال بقاعدة البيانات</h2><p>تأكد من بيانات قاعدة البيانات بملف config.php أو أعد التثبيت (احذف config.php وافتح install.php).</p></div>';
    exit;
}

// ----------------------------------------------------------- ملفات PWA (بدون جلسة)
if (isset($_GET['pwa'])) {
    if ($_GET['pwa'] === 'manifest') {
        $s = site_settings();
        $icons = [];
        if (!empty($s['logo_file']) && (int) $s['logo_icons'] === 1) {
            $base = pathinfo($s['logo_file'], PATHINFO_FILENAME);
            $icons[] = ['src' => 'uploads/' . $base . '-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => 'uploads/' . $base . '-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
        } else {
            $icons[] = ['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
        }
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name' => $s['site_name'],
            'short_name' => $s['site_name'],
            'start_url' => './',
            'scope' => './',
            'display' => 'standalone',
            'background_color' => '#F0F4F8',
            'theme_color' => '#006b73',
            'dir' => 'rtl',
            'lang' => 'ar',
            'icons' => $icons,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(404);
    exit;
}

require __DIR__ . '/inc/api.php';
require __DIR__ . '/inc/views.php';

// ----------------------------------------------------------- واجهة JSON
if (isset($_GET['api'])) {
    api_dispatch((string) $_GET['api']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');

// ----------------------------------------------------------- تسجيل الدخول/الخروج
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!csrf_valid((string) ($_POST['csrf'] ?? ''))) {
        render_login_page('انتهت صلاحية الصفحة — حاول مرة ثانية', $email);
        exit;
    }
    if (login_throttled($email)) {
        render_login_page('محاولات دخول كثيرة — انتظر 15 دقيقة وحاول مرة ثانية', $email);
        exit;
    }
    $user = authenticate($email, $password);
    if (!$user) {
        login_failed($email);
        render_login_page('البريد الإلكتروني أو كلمة المرور غير صحيحة', $email);
        exit;
    }
    q('DELETE FROM login_attempts WHERE ip = ? AND email = ?', [client_ip(), $email]);
    login_user($user, !empty($_POST['remember']));
    header('Location: ' . base_path());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    if (csrf_valid((string) ($_POST['csrf'] ?? ''))) {
        logout_user();
    }
    header('Location: ' . base_path());
    exit;
}

$user = current_user();

if (!$user) {
    render_login_page();
    exit;
}

render_app_page($user);
