<?php
// نسخ احتياطي تلقائي لبيانات الموقع وإرساله عبر تيليجرام. شغّله دورياً كل 6 ساعات عبر Cron Job:
// php /path/to/backup_cron.php  —  أو عبر رابط: backup_cron.php?key=... (نفس مفتاح cron.php)

require_once __DIR__ . '/includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $cronSecret = getOrCreateCronSecret($pdo);
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals($cronSecret, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
}

[$ok, $msg] = runSiteBackupAndSend($pdo);
echo ($ok ? "backup sent: " : "backup failed: ") . $msg . "\n";
