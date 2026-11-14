<?php
/**
 * واصل — تجهيز إضافة كروم للتحميل.
 *
 * كل تحميل يولّد مفتاح ربط جديد للحساب ويضعه داخل config.json بالإضافة،
 * فالفرع يثبّتها ويشتغل الإرسال مباشرة بدون أي إعداد يدوي. المفتاح الجديد
 * يوقف أي نسخة محمّلة قبلها لنفس الحساب (جهاز إرسال واحد لكل حساب واتساب).
 */

require_once __DIR__ . '/core.php';

/** أنماط host_permissions لدومين الموقع (http و https، ومع/بدون www). */
function extension_site_patterns()
{
    $host = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
    if ($host === '') {
        return [];
    }
    if (filter_var($host, FILTER_VALIDATE_IP) || strpos($host, '.') === false) {
        return ['*://' . $host . '/*'];
    }
    $bare = preg_replace('/^www\./', '', $host);
    return ['*://' . $bare . '/*', '*://*.' . $bare . '/*'];
}

function extension_install_notes(array $acc)
{
    $site = site_name();
    $lines = [
        'إضافة الإرسال لمنصة ' . $site . ' — حساب: ' . $acc['name'],
        '',
        'خطوات التثبيت (مرة وحدة فقط):',
        '1) فك ضغط هذا الملف — بيطلع مجلد اسمه wasel-sender. خلّه بمكان ثابت (مثلاً المستندات) ولا تحذفه.',
        '2) افتح متصفح Google Chrome واكتب بشريط العنوان: chrome://extensions',
        '3) فعّل "وضع المطوّر" (Developer mode) من الزاوية العلوية.',
        '4) اضغط "تحميل إضافة غير مضغوطة" (Load unpacked) واختر مجلد wasel-sender.',
        '5) بينفتح تبويب واتساب ويب تلقائياً — امسح رمز QR من جوالك:',
        '   واتساب بالجوال ← الأجهزة المرتبطة ← ربط جهاز.',
        '',
        'بعدها خلّي كروم مفتوح على هذا الجهاز، والحملات بتنرسل تلقائياً.',
        'حالة الحساب بالموقع بتصير "متصل" خلال دقيقة.',
        '',
        'ملاحظات:',
        '- كل حساب واتساب يحتاج ملف تحميل خاص فيه. لحسابين على نفس الكمبيوتر استخدم ملفي تعريف (Profiles) بكروم.',
        '- تحميل الإضافة من جديد من الموقع يوقف هذه النسخة (رابط جديد لكل تحميل).',
        '- يفضّل تبقى نافذة واتساب ويب مفتوحة (مو مصغّرة) حتى يكون الإرسال أسرع.',
    ];
    return implode("\r\n", $lines) . "\r\n";
}

function extension_download(array $user, array $acc)
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><div dir="rtl" style="font-family:sans-serif;padding:30px">'
            . '<h3>إضافة zip غير مفعّلة على الاستضافة</h3>'
            . '<p>فعّلها من cPanel ← Select PHP Version ← Extensions ← zip، أو استخدم "رمز الربط اليدوي" من صفحة حسابي '
            . 'مع مجلد extension الموجود بملفات الموقع.</p></div>';
        exit;
    }
    $src = WASEL_ROOT . '/extension';
    if (!is_dir($src)) {
        http_response_code(500);
        exit('extension folder missing');
    }

    $key = new_agent_key();
    q('UPDATE wa_accounts SET agent_key_hash = ? WHERE id = ?', [agent_key_hash($key), $acc['id']]);
    $config = [
        'siteUrl' => base_url(),
        'agentKey' => $key,
        'accountId' => (int) $acc['id'],
        'accountName' => $acc['name'],
        'siteName' => site_name(),
        'issuedAt' => date('c'),
    ];
    $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    $tmp = tempnam(temp_dir(), 'wsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('zip error');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
        if ($rel === 'config.json' || $rel === '.htaccess' || $rel[0] === '.') {
            continue;
        }
        if ($rel === 'manifest.json') {
            $manifest = json_decode((string) file_get_contents($file->getPathname()), true);
            $manifest['host_permissions'] = array_values(array_unique(array_merge(['https://web.whatsapp.com/*'], extension_site_patterns())));
            $zip->addFromString('wasel-sender/manifest.json', json_encode($manifest, $json));
            continue;
        }
        $zip->addFile($file->getPathname(), 'wasel-sender/' . $rel);
    }
    $zip->addFromString('wasel-sender/config.json', json_encode($config, $json));
    $zip->addFromString('INSTALL.txt', extension_install_notes($acc));
    $zip->close();

    $fileName = 'wasel-sender-' . (int) $acc['id'] . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
