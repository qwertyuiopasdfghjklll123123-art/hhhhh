<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
mb_internal_encoding('UTF-8');

/* مانيفست PWA كملف مستقل (بدل توجيهه عبر ?asset= من index.php) — يبقى
   ديناميكياً لأن اسم/شعار الموقع قابلان للتعديل من لوحة الأدمن، فيُقرآن هنا
   من نفس طبقة تخزين MySQL التي يستخدمها index.php. */
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$logo = site_logo_url();
$icons = $logo
    ? [['src'=>$logo, 'sizes'=>'192x192', 'type'=>'image/png', 'purpose'=>'any'], ['src'=>$logo, 'sizes'=>'512x512', 'type'=>'image/png', 'purpose'=>'any']]
    : [['src'=>'assets/icon-192.png', 'sizes'=>'192x192', 'type'=>'image/png', 'purpose'=>'any'], ['src'=>'assets/icon-512.png', 'sizes'=>'512x512', 'type'=>'image/png', 'purpose'=>'any']];

echo json_encode([
    'name' => site_name(),
    'short_name' => site_name(),
    'start_url' => 'index.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#f2b100',
    'dir' => 'rtl',
    'lang' => 'ar',
    'icons' => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
