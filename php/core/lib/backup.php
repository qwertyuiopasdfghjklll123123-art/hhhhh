<?php
/**
 * النسخ الاحتياطي (app.py:94-270).
 *
 * بايثون كان ينسخ ملف SQLite إلى مجلد backup_<label>_<timestamp>؛ هنا نكتب ملف SQL كاملاً
 * (CREATE TABLE + INSERT لكل الجداول، بلا حاجة لـ mysqldump) داخل المجلد نفسه بنفس التسمية،
 * مع الاحتفاظ بآخر 48 نسخة، ونسخة تلغرام (zip) تحوي ملف SQL + ملفات VAPID/الاشتراكات/كاش الخدمات.
 */

const BACKUP_INTERVAL = 3600;
const MAX_BACKUPS = 48;
const TELEGRAM_BACKUP_INTERVAL = 6 * 3600;
const BACKUP_SQL_NAME = 'fastcrand.sql';

function ensure_backup_dir(): void {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0775, true);
}

function _backup_log(string $line): void {
    @file_put_contents(DATA_DIR . '/logs/backup.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function _rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) _rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

/**
 * يكتب نسخة كاملة من قاعدة البيانات كملف SQL قابل للاستيراد (phpMyAdmin أو restore_backup).
 * الأعمدة المولَّدة (GENERATED) تُستثنى لأن MySQL يعيد حسابها؛ ويُكتب كل جدول بجملة DROP + CREATE.
 * @return int عدد الصفوف المكتوبة
 */
function sql_dump_to_file(string $path): int {
    $db = get_db_raw();           // اتصال مستقل غير مخزَّن مؤقتاً
    $pdo = $db->pdo;
    $fp = fopen($path, 'wb');
    if (!$fp) throw new RuntimeException("cannot write $path");
    $rows_total = 0;
    try {
        fwrite($fp, '-- fastcrand SQL backup — ' . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';\n\n");
        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch(PDO::FETCH_NUM);
            fwrite($fp, 'DROP TABLE IF EXISTS `' . $t . "`;\n" . $row[1] . ";\n\n");

            $cols = [];
            $cst = $pdo->query('SELECT column_name, extra FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ' . $pdo->quote($t) . ' ORDER BY ordinal_position');
            foreach ($cst->fetchAll(PDO::FETCH_NUM) as $c) {
                if (preg_match('/\b(VIRTUAL|STORED)\s+GENERATED\b|\bPERSISTENT\b/i', (string)$c[1])) continue;
                $cols[] = $c[0];
            }
            if (!$cols) continue;
            $colList = '`' . implode('`,`', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . '`';

            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $st = $pdo->query('SELECT ' . $colList . ' FROM `' . str_replace('`', '``', $t) . '`');
                $numeric = [];
                foreach ($cols as $i => $_) {
                    $m = $st->getColumnMeta($i);
                    $numeric[$i] = in_array($m['native_type'] ?? '', ['LONG', 'LONGLONG', 'SHORT', 'TINY', 'INT24', 'DOUBLE', 'FLOAT', 'NEWDECIMAL', 'DECIMAL', 'YEAR'], true);
                }
                $head = 'INSERT INTO `' . $t . '` (' . $colList . ') VALUES ';
                $buf = []; $bufLen = 0;
                $flush = function () use (&$buf, &$bufLen, $fp, $head) {
                    if ($buf) fwrite($fp, $head . implode(',', $buf) . ";\n");
                    $buf = []; $bufLen = 0;
                };
                while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    $vals = [];
                    foreach ($r as $i => $v) {
                        if ($v === null) $vals[] = 'NULL';
                        elseif (is_int($v)) $vals[] = (string)$v;
                        elseif (is_float($v)) $vals[] = is_finite($v) ? var_export($v, true) : 'NULL';
                        elseif ($numeric[$i] && is_numeric($v)) $vals[] = (string)$v;
                        else $vals[] = $pdo->quote((string)$v);
                    }
                    $line = '(' . implode(',', $vals) . ')';
                    $buf[] = $line; $bufLen += strlen($line); $rows_total++;
                    if ($bufLen > 524288) $flush();
                }
                $flush();
                $st->closeCursor();
            } finally {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
            fwrite($fp, "\n");
        }
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($fp);
    }
    return $rows_total;
}

/** create_backup  (app.py:101) — يرجع اسم النسخة أو null عند الفشل */
function create_backup(string $label = 'auto'): ?string {
    ensure_backup_dir();
    $ts = date('Ymd_His');
    $backup_name = "backup_{$label}_{$ts}";
    $backup_folder = BACKUP_DIR . '/' . $backup_name;
    @mkdir($backup_folder, 0775, true);
    $copied = 0;
    try {
        sql_dump_to_file($backup_folder . '/' . BACKUP_SQL_NAME);
        $copied++;
        $all = [];
        foreach (scandir(BACKUP_DIR) ?: [] as $d) {
            if (strncmp($d, 'backup_', 7) === 0 && is_dir(BACKUP_DIR . '/' . $d)) $all[] = $d;
        }
        sort($all, SORT_STRING);
        while (count($all) > MAX_BACKUPS) {
            $old = array_shift($all);
            _rrmdir(BACKUP_DIR . '/' . $old);
        }
        return $copied > 0 ? $backup_name : null;
    } catch (Throwable $e) {
        _backup_log('create_backup failed: ' . get_class($e) . ': ' . $e->getMessage());
        _rrmdir($backup_folder);
    }
    return null;
}

/** list_backups  (app.py:123) */
function list_backups(): array {
    ensure_backup_dir();
    $names = scandir(BACKUP_DIR) ?: [];
    rsort($names, SORT_STRING);
    $backups = [];
    foreach ($names as $d) {
        $dpath = BACKUP_DIR . '/' . $d;
        if (strncmp($d, 'backup_', 7) === 0 && is_dir($dpath)) {
            $files = array_values(array_diff(scandir($dpath) ?: [], ['.', '..']));
            $size = 0;
            foreach ($files as $f) $size += (int)@filesize($dpath . '/' . $f);
            clearstatcache(true, $dpath);
            $backups[] = ['name' => $d, 'files' => count($files), 'size' => $size, 'timestamp' => (float)filemtime($dpath)];
        }
    }
    return $backups;
}

/**
 * restore_backup  (app.py:134): ينشئ نسخة pre_restore ثم يعيد تنفيذ ملف SQL المحفوظ.
 * (لا يوجد مسار يستدعيها بالأصل أيضاً؛ تبقى للاستعمال اليدوي من سطر الأوامر.)
 */
function restore_backup($backup_name): bool {
    $backup_name = (string)$backup_name;
    if ($backup_name === '' || strpos($backup_name, '/') !== false || strpos($backup_name, '\\') !== false || $backup_name === '..') return false;
    $backup_path = BACKUP_DIR . '/' . $backup_name;
    try {
        if (!is_dir($backup_path)) return false;
        $file = $backup_path . '/' . BACKUP_SQL_NAME;
        if (!is_file($file)) return false;
        create_backup('pre_restore');
        $pdo = get_db_raw()->pdo;
        $fp = fopen($file, 'rb');
        if (!$fp) return false;
        $buf = '';
        while (($line = fgets($fp)) !== false) {
            if ($buf === '' && (trim($line) === '' || strncmp($line, '--', 2) === 0)) continue;
            $buf .= $line;
            if (substr(rtrim($line, "\r\n"), -1) === ';') {
                $pdo->exec($buf);
                $buf = '';
            }
        }
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        _backup_log('restore_backup failed: ' . get_class($e) . ': ' . $e->getMessage());
    }
    return false;
}

/* ------------------------------ نسخة تلغرام (app.py:178-249) ------------------------------ */

function telegram_backup_files(): array {
    return [vapid_file(), push_subs_file(), svc_cache_file()];
}

/** _create_telegram_backup — يرجع true حتى لو ردّ تلغرام بخطأ HTTP (مثل الأصل: الاستثناءات فقط تُفشِلها) */
function _create_telegram_backup(): bool {
    $bot_token = get_setting('tg_bot_token', '');
    $chat_id = get_setting('tg_chat_id', '');
    if (!py_truthy($bot_token) || !py_truthy($chat_id)) return false;
    ensure_backup_dir();
    $ts = date('Ymd_His');
    $zip_name = "fastcrand_cloud_backup_{$ts}.zip";
    $zip_path = BACKUP_DIR . '/' . $zip_name;
    $sql_tmp = BACKUP_DIR . '/fastcrand_tmp_' . bin2hex(random_bytes(4)) . '.sql';
    $report_lines = []; $total_size = 0; $files_added = 0;
    try {
        $zf = new ZipArchive();
        if ($zf->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("cannot create $zip_path");
        $sources = [];
        sql_dump_to_file($sql_tmp);
        $sources[BACKUP_SQL_NAME] = $sql_tmp;
        foreach (telegram_backup_files() as $fpath) {
            if (is_file($fpath)) $sources[basename($fpath)] = $fpath;
        }
        foreach ($sources as $arcname => $fpath) {
            $fsize = (int)filesize($fpath);
            $total_size += $fsize;
            $zf->addFile($fpath, $arcname);
            $zf->setCompressionName($arcname, ZipArchive::CM_DEFLATE);
            $files_added++;
            $report_lines[] = '  📄 ' . $arcname . ' — ' . py_str(py_round($fsize / 1024, 1)) . ' KB';
        }
        $zf->close();
        @unlink($sql_tmp);
        if ($files_added == 0) { @unlink($zip_path); return false; }
        $zip_size = (int)filesize($zip_path);
        $user_count = 0; $order_count = 0; $balance_total = 0.0;
        try {
            $conn = get_db_raw();
            $user_count = $conn->execute('SELECT COUNT(*) FROM users')->fetchone()[0];
            $order_count = $conn->execute('SELECT COUNT(*) FROM orders')->fetchone()[0];
            $bal = $conn->execute('SELECT COALESCE(SUM(balance),0) FROM users')->fetchone()[0];
            $balance_total = py_round(py_float($bal), 2);
        } catch (Throwable $e) {}
        $_sname = get_setting('site_name', 'fastcrand');
        $report = "☁️ <b>نسخة احتياطية سحابية — {$_sname}</b>\n━━━━━━━━━━━━━━━━━━━━\n"
            . '🕐 ' . date('Y-m-d H:i:s') . "\n\n"
            . "📊 <b>إحصائيات:</b>\n  👥 المستخدمين: <b>{$user_count}</b>\n"
            . "  📦 الطلبات: <b>{$order_count}</b>\n  💰 مجموع الأرصدة: <b>$" . py_str($balance_total) . "</b>\n\n"
            . "📁 <b>الملفات ({$files_added}):</b>\n" . implode("\n", $report_lines) . "\n\n"
            . '📦 حجم الـ ZIP: <b>' . py_str(py_round($zip_size / 1024, 1)) . " KB</b>\n━━━━━━━━━━━━━━━━━━━━\n"
            . '✅ لاسترجاع: نزّل الملف → فك الضغط → ارفع الملفات للسيرفر';
        $url = "https://api.telegram.org/bot{$bot_token}/sendDocument";
        requests_post($url, ['data' => ['chat_id' => $chat_id, 'caption' => $report, 'parse_mode' => 'HTML'],
                             'files' => ['document' => [$zip_name, $zip_path]], 'timeout' => 15]);
        _backup_log('Telegram Backup: sent ' . $zip_name . ' (' . py_str(py_round($zip_size / 1024, 1)) . ' KB)');
        @unlink($zip_path);
        return true;
    } catch (Throwable $e) {
        _backup_log('Telegram Backup error: ' . $e->getMessage());
        try {
            $err_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            requests_post($err_url, ['data' => ['chat_id' => $chat_id, 'text' => "❌ فشل النسخ الاحتياطي السحابي!\n\n⚠️ " . py_slice($e->getMessage(), null, 200), 'parse_mode' => 'HTML'], 'timeout' => 10]);
        } catch (Throwable $e2) {}
        @unlink($sql_tmp);
        @unlink($zip_path);
        return false;
    }
}

/** send_backup_telegram: يعمل بعد إرسال الاستجابة (بديل الـ thread بالأصل) */
function send_backup_telegram($backup_name = null): bool {
    defer_after_response(function () {
        try { _create_telegram_backup(); } catch (Throwable $e) {}
    });
    return true;
}

/** send_backup_telegram_blocking: ينتظر النتيجة (مهلة الإرسال 15 ثانية كالأصل) */
function send_backup_telegram_blocking($backup_name = null): bool {
    try { return _create_telegram_backup(); }
    catch (Throwable $e) { return false; }
}
