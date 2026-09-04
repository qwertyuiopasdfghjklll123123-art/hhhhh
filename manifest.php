<?php
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$siteName = getSetting($pdo, 'site_name', 'استضافتي');
$siteLogo = getSetting($pdo, 'site_logo', '');

$icons = [];
if ($siteLogo !== '') {
    // نفس sizes/purpose الخاصة بالأيقونة الافتراضية أدناه، وإلا يتجاهل Chrome هذه
    // الأيقونة المرفوعة عند اختيار أيقونة التثبيت لأنها بلا حجم محدد/غير قابلة للقص (maskable)
    $icons[] = ['src' => $siteLogo, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
}
$icons[] = ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
$icons[] = ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];

echo json_encode([
    'name' => $siteName,
    'short_name' => $siteName,
    'description' => getSetting($pdo, 'site_tagline', 'استضافة VPS سريعة وآمنة'),
    'start_url' => 'index.php?app=1',
    'scope' => '.',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'dir' => 'rtl',
    'lang' => 'ar',
    'background_color' => '#f7f4f0',
    'theme_color' => '#ff7a1a',
    'icons' => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
