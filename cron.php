<?php
// معالجة التجديد التلقائي للاستضافات المنتهية. شغّله دورياً عبر Cron Job:
// php /path/to/cron.php  —  أو عبر رابط: cron.php?key=... (المفتاح يُنشأ تلقائياً في جدول settings)

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

$today = date('Y-m-d');
$expiredStmt = $pdo->prepare("SELECT * FROM hosting WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date <= ?");
$expiredStmt->execute([$today]);
$expiredHostings = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

$renewed = 0;
$skipped = 0;

foreach ($expiredHostings as $hosting) {
    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $userStmt->execute([$hosting['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || empty($user['auto_renew'])) {
        $skipped++;
        continue;
    }

    [$ok, $msg] = createRenewalRequest($pdo, $hosting);
    if (!$ok) {
        if (str_contains($msg, 'رصيدك')) {
            notifyUser($pdo, (int)$user['id'], '⚠️ تعذر التجديد التلقائي', 'انتهت صلاحية استضافتك (' . $hosting['name'] . '). ' . $msg, 'system');
        }
        $skipped++;
        continue;
    }

    notifyUser($pdo, (int)$user['id'], '🔄 طلب تجديد تلقائي', 'انتهت صلاحية استضافتك (' . $hosting['name'] . '). ' . $msg, 'system');
    $renewed++;
}

echo "checked: " . count($expiredHostings) . ", renewal_requests_created: $renewed, skipped: $skipped\n";
