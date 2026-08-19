<?php
/* ======================================================================
   يولّد ملف manifest.json ديناميكياً لكل قسم (?app=employee|hr|branch|general)
   بحيث يثبَّت كل قسم كتطبيق PWA منفصل باسمه وأيقونته الخاصة — الأيقونة تعكس
   شعار الشركة إن وُجد (من إعدادات HR) وإلا تُستخدم الأيقونة الافتراضية.
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (!file_exists(__DIR__ . '/config.php')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['name' => 'شركة الصوى للصرافة'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/config.php';

$apps = [
    'employee' => ['name' => 'نافذة الموظف', 'short' => 'الموظف', 'start' => '/'],
    'hr' => ['name' => 'الموارد البشرية', 'short' => 'HR', 'start' => '/hr'],
    'branch' => ['name' => 'مدير الفرع', 'short' => 'مدير الفرع', 'start' => '/branch'],
    'general' => ['name' => 'المسؤول العام', 'short' => 'المسؤول العام', 'start' => '/general'],
];

$app = $_GET['app'] ?? 'employee';
if (!isset($apps[$app])) {
    $app = 'employee';
}
$conf = $apps[$app];

$companyName = 'شركة الصوى للصرافة';
$logoUrl = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $row = $pdo->query("SELECT company_name, company_logo FROM settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $companyName = $row['company_name'] ?: $companyName;
        $logoUrl = $row['company_logo'] ?: null;
    }
} catch (Throwable $e) {
    // تجاهل، استخدم الأيقونة الافتراضية
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($logoUrl) {
    $icons = [
        ['src' => $origin . '/' . ltrim($logoUrl, '/'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ];
} else {
    $icons = [
        ['src' => $origin . '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $origin . '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ];
}

header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name' => $companyName . ' — ' . $conf['name'],
    'short_name' => $conf['short'],
    'start_url' => $conf['start'],
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#006b73',
    'theme_color' => '#006b73',
    'dir' => 'rtl',
    'lang' => 'ar',
    'icons' => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
