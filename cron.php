<?php
/**
 * معالجة الحملات بالخلفية - بديل threads بايثون (webapp.py يرسل من ثريد يبقى شغال طول
 * الحملة؛ PHP بالاستضافة المشتركة ينتهي كل طلب فوراً، فهذا السكربت يحتاج يتكرر عبر Cron).
 *
 * شغّله كل دقيقة من لوحة الاستضافة (DirectAdmin ← Advanced Features ← Cron Jobs، كل حقول
 * الوقت *). يبقى شغال قريب من دقيقة كاملة بكل تشغيلة (قفل يمنع تشغيلتين بنفس الوقت)، يفحص
 * كل حملة شغالة كل ثانيتين ويرسل أي رقم حان وقته (نفس الفاصل الزمني المحدد بالحملة) - هذا
 * يعطي فاصلاً زمنياً حقيقياً بين كل رقم ورقم رغم إن المشغّل نفسه (cron) يتكرر كل دقيقة بس.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/core/bootstrap.php';

$lockFile = DATA_DIR . '/cache/cron.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0); // تشغيلة سابقة لسا شغالة (تداخل) - لا تبدأ تشغيلة ثانية بنفس الوقت
}

function cron_send_next_due(): bool {
    $conn = get_db();
    $due = $conn->execute("SELECT * FROM campaigns WHERE status='running' AND next_send_at<=? ORDER BY id ASC", [time()])->fetchall();
    $didWork = false;
    foreach ($due as $c) {
        $next = $conn->execute("SELECT id, phone FROM campaign_numbers WHERE campaign_id=? AND status='pending' ORDER BY id ASC LIMIT 1", [$c['id']])->fetchone();
        if (!$next) {
            $conn->execute("UPDATE campaigns SET status='completed' WHERE id=?", [$c['id']]);
            $conn->commit();
            $accName = $conn->execute('SELECT name FROM wa_accounts WHERE id=?', [$c['account_id']])->fetchone()['name'] ?? $c['account_id'];
            $kind = (int)$c['failed'] === 0 ? 'success' : 'warning';
            add_event((int)$c['owner'], $accName, 'اكتملت الحملة', "نجح {$c['sent']} من {$c['total']}، فشل {$c['failed']}", $kind);
            continue;
        }
        $didWork = true;
        try {
            gateway_send($c['account_id'], $next['phone'], $c['text']);
            $conn->execute("UPDATE campaign_numbers SET status='sent' WHERE id=?", [$next['id']]);
            $conn->execute('UPDATE campaigns SET sent=sent+1, next_send_at=? WHERE id=?', [time() + (int)$c['delay_seconds'], $c['id']]);
        } catch (GatewayException $e) {
            $conn->execute("UPDATE campaign_numbers SET status='failed' WHERE id=?", [$next['id']]);
            $conn->execute('UPDATE campaigns SET failed=failed+1, next_send_at=? WHERE id=?', [time() + (int)$c['delay_seconds'], $c['id']]);
            log_app_error('WARNING', "campaign {$c['id']} send to {$next['phone']} failed: " . $e->getMessage());
        }
        $conn->commit();
    }
    return $didWork;
}

function cron_cleanup_otp_codes(): void {
    $conn = get_db();
    $conn->execute('DELETE FROM otp_codes WHERE expires_at < ?', [time() - 3600]);
    $conn->commit();
}

/** تذكير/انتهاء اشتراك مشتركي كل مستخدم (CRM) - مرة وحدة بس لكل حالة (reminder_sent/expired_sent) */
function cron_subscriber_reminders(): void {
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM subscribers WHERE reminder_sent=0 OR expired_sent=0')->fetchall();
    $today = new DateTime('today');
    foreach ($rows as $r) {
        try { $expiry = (new DateTime($r['subscribed_at']))->modify('+' . (int)$r['duration_days'] . ' days'); }
        catch (Exception $e) { continue; }
        $daysLeft = (int)$today->diff($expiry)->format('%r%a');
        $settings = $conn->execute('SELECT * FROM subscription_settings WHERE owner=?', [$r['owner']])->fetchone();
        $reminderBefore = $settings ? (int)$settings['reminder_days_before'] : 3;
        $sender = null;
        if (!py_truthy($r['expired_sent']) && $daysLeft <= 0) {
            $sender = wsl_find_sending_account((int)$r['owner']);
            if (!$sender) continue;
            $msg = str_replace(['{name}', '{days}'], [$r['name'], '0'],
                ($settings['expired_message'] ?? '') ?: 'مرحباً {name}، اشتراكك انتهى اليوم. تواصل معنا للتجديد.');
            try {
                gateway_send($sender, $r['phone'], $msg);
                $conn->execute('UPDATE subscribers SET expired_sent=1 WHERE id=?', [$r['id']]);
                $conn->commit();
            } catch (GatewayException $e) { log_app_error('WARNING', 'subscriber expiry message failed: ' . $e->getMessage()); }
        } elseif (!py_truthy($r['reminder_sent']) && $daysLeft > 0 && $daysLeft <= $reminderBefore) {
            $sender = wsl_find_sending_account((int)$r['owner']);
            if (!$sender) continue;
            $msg = str_replace(['{name}', '{days}'], [$r['name'], (string)$daysLeft],
                ($settings['reminder_message'] ?? '') ?: 'مرحباً {name}، اشتراكك سينتهي خلال {days} أيام. تواصل معنا للتجديد.');
            try {
                gateway_send($sender, $r['phone'], $msg);
                $conn->execute('UPDATE subscribers SET reminder_sent=1 WHERE id=?', [$r['id']]);
                $conn->commit();
            } catch (GatewayException $e) { log_app_error('WARNING', 'subscriber reminder message failed: ' . $e->getMessage()); }
        }
    }
}

try { cron_subscriber_reminders(); } catch (Throwable $e) { log_app_error('WARNING', 'cron_subscriber_reminders: ' . $e->getMessage()); }
Conn::shutdown();

$deadline = time() + 50; // يترك مسافة قبل أي تشغيلة cron تالية (تتكرر كل دقيقة)
$lastCleanup = 0;
while (time() < $deadline) {
    $didWork = cron_send_next_due();
    if (time() - $lastCleanup > 300) { cron_cleanup_otp_codes(); $lastCleanup = time(); }
    Conn::shutdown();
    if (!$didWork) sleep(2);
}

flock($fp, LOCK_UN);
fclose($fp);
