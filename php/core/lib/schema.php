<?php
/**
 * إنشاء الجداول + البيانات الافتراضية (يقابل init_db() في app.py:881-1287 وما بعده 1292-1438).
 * يُستدعى من install.php ومن tools/init_db.php؛ آمن عند التكرار (IF NOT EXISTS + فحص العدّ).
 */

function schema_statements(): array {
    $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/sql/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);          // أسطر التعليق
    $stmts = [];
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $s) {
        $s = trim($s);
        if ($s !== '') $stmts[] = $s;
    }
    return $stmts;
}

/** ينشئ الجداول الناقصة فقط */
function create_schema(PDO $pdo): int {
    $n = 0;
    foreach (schema_statements() as $st) { $pdo->exec($st); $n++; }
    return $n;
}

function init_db(): void {
    $conn = get_db();
    create_schema($conn->pdo);

    // المستويات الافتراضية
    $lv = $conn->execute('SELECT COUNT(*) as c FROM levels')->fetchone()['c'];
    if ($lv == 0) {
        $levels = [
            ['برونزي', 'BRONZE', 0, 0, '#78716c', 'fa-solid fa-seedling', 'بداية الرحلة', 1, 0],
            ['فضي', 'SILVER', 50, 3, '#94a3b8', 'fa-solid fa-shield-halved', "أولوية بالدعم الفني\nخصم 3%", 1, 1],
            ['ذهبي', 'GOLD', 200, 5, '#f59e0b', 'fa-solid fa-crown', "خصم 5%\nعروض حصرية\nدعم سريع", 1, 2],
            ['VIP', 'VIP', 1000, 8, '#6366f1', 'fa-solid fa-gem', "خصم 8%\nمدير حساب خاص\nأولوية قصوى\nإشعارات أولاً", 1, 3],
            ['ملكي', 'ROYAL', 5000, 12, '#ec4899', 'fa-solid fa-chess-king', "خصم 12%\nدعم 24/7\nخدمات مجانية شهرية", 1, 4],
            ['أسطوري', 'LEGEND', 25000, 15, '#ef4444', 'fa-solid fa-dragon', "خصم 15%\nكل المزايا\nتسعيرة خاصة\nAPI مخصص", 1, 5],
        ];
        foreach ($levels as $l) {
            $conn->execute('INSERT INTO levels(name,name_en,min_spent,discount,color,icon,benefits,active,sort_order) VALUES(?,?,?,?,?,?,?,?,?)', $l);
        }
        $conn->commit();
    }

    // طرق الدفع الثابتة: Binance وآسياسيل ونجوم تليجرام (معطّلة افتراضياً)
    if ($conn->execute("SELECT COUNT(*) FROM payment_methods WHERE method_type='binance'")->fetchone()[0] == 0) {
        $conn->execute('INSERT INTO payment_methods(name,icon,currency,active,sort_order,method_type,method_extras) VALUES(?,?,?,?,?,?,?)',
            ['Binance Pay', 'fa-brands fa-bitcoin', 'USD', 0, 1, 'binance', py_json_dumps(['api_key' => '', 'api_secret' => ''])]);
    }
    if ($conn->execute("SELECT COUNT(*) FROM payment_methods WHERE method_type='asiacell'")->fetchone()[0] == 0) {
        $conn->execute('INSERT INTO payment_methods(name,icon,currency,note,active,sort_order,method_type,method_extras) VALUES(?,?,?,?,?,?,?,?)',
            ['آسياسيل', 'fa-solid fa-mobile-screen', 'IQD', 'تحويل رصيد آسياسيل مباشر وتلقائي', 0, 2, 'asiacell', py_json_dumps(['max_transfer' => 10000])]);
    }
    if ($conn->execute("SELECT COUNT(*) FROM payment_methods WHERE method_type='telegram_stars'")->fetchone()[0] == 0) {
        $conn->execute('INSERT INTO payment_methods(name,icon,currency,note,active,sort_order,method_type,method_extras) VALUES(?,?,?,?,?,?,?,?)',
            ['نجوم تليجرام', 'fa-brands fa-telegram', 'USD', 'دفع تلقائي عبر بوت تليجرام بنجوم Telegram Stars', 0, 3, 'telegram_stars',
             py_json_dumps(['bot_token' => '', 'bot_username' => '', 'admin_chat_id' => '', 'rate_usd_per_100' => 1.5])]);
    }
    $conn->commit();

    // المزوّد الافتراضي
    $pc = $conn->execute('SELECT COUNT(*) as c FROM providers')->fetchone()['c'];
    if ($pc == 0) {
        $oldUrl = ''; $oldKey = '';
        try {
            $r1 = $conn->execute("SELECT value FROM site_settings WHERE key='provider_api_url'")->fetchone();
            $r2 = $conn->execute("SELECT value FROM site_settings WHERE key='provider_api_key'")->fetchone();
            if ($r1) $oldUrl = $r1['value'];
            if ($r2) $oldKey = $r2['value'];
        } catch (Throwable $e) {}
        if (!py_truthy($oldUrl)) $oldUrl = SMM_API_URL;
        if (!py_truthy($oldKey)) $oldKey = SMM_API_KEY;
        $conn->execute('INSERT INTO providers(name,api_url,api_key,active,is_default) VALUES(?,?,?,1,1)', ['المزود الرئيسي', $oldUrl, $oldKey]);
        $conn->commit();
    }
    // المزود الرئيسي يعرض خدماته تلقائياً
    try { $conn->execute('UPDATE providers SET show_in_store=1 WHERE is_default=1 AND show_in_store=0'); } catch (Throwable $e) {}
    $conn->commit();

    // المستخدمان الافتراضيان (يُتجاهل الخطأ إن وُجدا)
    try {
        $conn->execute('INSERT INTO users(name,email,password,verified) VALUES(?,?,?,1)', ['حمزة', 'test@test.com', hash_pw('123456')]);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); }
    try {
        $conn->execute('INSERT INTO users(name,email,password,verified,is_admin) VALUES(?,?,?,1,1)', ['الأدمن', 'admin@admin.com', hash_pw('2sebvWFr6BYwVBrV')]);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); }

    _backfill_display_ids();
    _backfill_public_ids();
}
