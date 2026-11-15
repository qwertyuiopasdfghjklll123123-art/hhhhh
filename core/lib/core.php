<?php
/**
 * دوال مساعدة عامة يحتاجها أكثر من ملف مسارات (مقابل دوال متفرقة بأعلى app.py).
 */

/** المستخدم الحالي (صف كامل من users) أو null لو ما فيه جلسة صالحة */
function current_user(): ?Row {
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) return null;
    return get_db()->execute('SELECT * FROM users WHERE id=?', [$uid])->fetchone();
}

/** 401 JSON لو ما فيه جلسة؛ وإلا null (استعمال: $r = require_login(); if ($r) return $r;) */
function require_login(): ?Response {
    if (empty($_SESSION['user_id'])) return jsonify(['ok' => false, 'error' => 'سجّل الدخول أولاً'], 401);
    return null;
}

/** 403 JSON لو المستخدم الحالي مو أدمن فعلياً بقاعدة البيانات (لا يكفي الاعتماد على الجلسة فقط) */
function require_admin(): ?Response {
    if (($r = require_login()) !== null) return $r;
    $u = current_user();
    if (!$u || !py_truthy($u['is_admin'])) return jsonify(['ok' => false, 'error' => 'هذا القسم للأدمن فقط'], 403);
    return null;
}

function now_str(): string { return gmdate('Y-m-d H:i'); }
function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

/** نفس قائمة رموز الدول بـ webapp.py (مصدر واحد لمنتقي الأدمن) */
const COUNTRY_CODES = [
    ['20', '🇪🇬 +20'], ['212', '🇲🇦 +212'], ['213', '🇩🇿 +213'], ['216', '🇹🇳 +216'],
    ['218', '🇱🇾 +218'], ['90', '🇹🇷 +90'], ['91', '🇮🇳 +91'], ['92', '🇵🇰 +92'],
    ['93', '🇦🇫 +93'], ['94', '🇱🇰 +94'], ['95', '🇲🇲 +95'], ['960', '🇲🇻 +960'],
    ['961', '🇱🇧 +961'], ['962', '🇯🇴 +962'], ['963', '🇸🇾 +963'], ['964', '🇮🇶 +964'],
    ['965', '🇰🇼 +965'], ['966', '🇸🇦 +966'], ['967', '🇾🇪 +967'], ['968', '🇴🇲 +968'],
    ['970', '🇵🇸 +970'], ['971', '🇦🇪 +971'], ['972', '🇮🇱 +972'], ['973', '🇧🇭 +973'],
    ['974', '🇶🇦 +974'], ['975', '🇧🇹 +975'], ['976', '🇲🇳 +976'], ['977', '🇳🇵 +977'],
    ['98', '🇮🇷 +98'], ['262', '🇾🇹 +262'], ['992', '🇹🇯 +992'], ['993', '🇹🇲 +993'],
    ['994', '🇦🇿 +994'], ['995', '🇬🇪 +995'], ['996', '🇰🇬 +996'], ['998', '🇺🇿 +998'],
    ['1', '🇺🇸 +1'], ['44', '🇬🇧 +44'], ['49', '🇩🇪 +49'], ['33', '🇫🇷 +33'],
    ['39', '🇮🇹 +39'], ['34', '🇪🇸 +34'], ['7', '🇷🇺 +7'], ['86', '🇨🇳 +86'],
    ['81', '🇯🇵 +81'], ['82', '🇰🇷 +82'], ['60', '🇲🇾 +60'], ['62', '🇮🇩 +62'],
    ['63', '🇵🇭 +63'], ['64', '🇳🇿 +64'], ['61', '🇦🇺 +61'],
];

/** الاشتراك فعّال فعلياً: plan_active=1 وما انتهت مدته (لو الأدمن حدد مدة عند التفعيل) */
function effective_plan_active(Row $user): bool {
    if (!py_truthy($user['plan_active'])) return false;
    $ends = $user['plan_ends_at'];
    if (!$ends) return true;
    $t = strtotime($ends);
    return $t === false ? true : time() < $t;
}

/** مفعّل باشتراك حقيقي، أو لسا داخل فترة التجربة المجانية */
function user_has_access(Row $user): bool {
    if (effective_plan_active($user)) return true;
    $trial = $user['trial_ends_at'];
    if (!$trial) return false;
    $t = strtotime($trial);
    return $t !== false && time() < $t;
}

/** يستخرج أرقاماً من نص (فاصلة/سطر جديد) - ٨ أرقام فأكثر بعد تنظيف أي رمز غير رقمي */
function numbers_from_text(string $text): array {
    $out = [];
    foreach (preg_split('/[,\n\r]+/', $text) as $part) {
        $digits = preg_replace('/\D/', '', $part);
        if (strlen($digits) >= 8) $out[] = $digits;
    }
    return $out;
}

/** يضيف رمز الدولة لرقم محلي ما يبدأ فيه أصلاً (رقم دولي كامل يترجع بدون تغيير) */
function apply_country_code(string $number, string $countryCode): string {
    if ($countryCode === '' || str_starts_with($number, $countryCode)) return $number;
    if (str_starts_with($number, '0')) $number = substr($number, 1);
    return $countryCode . $number;
}

/** إشعار للمستخدم (يظهر بقسم الإشعارات) - EVENTS_MAX=50 بالأصل، هنا نحذف الزائد كل إضافة بدل الاحتفاظ بكل شي للأبد */
function add_event(int $owner, ?string $account, string $title, string $body, string $kind = 'info'): void {
    $conn = get_db();
    $conn->execute('INSERT INTO events (owner, account, title, body, kind, created_at) VALUES (?,?,?,?,?,?)',
        [$owner, $account, $title, $body, $kind, gmdate('H:i')]);
    $conn->commit();
    $conn->execute(
        'DELETE FROM events WHERE owner=? AND id NOT IN (SELECT id FROM (SELECT id FROM events WHERE owner=? ORDER BY id DESC LIMIT 50) t)',
        [$owner, $owner]
    );
    $conn->commit();
}

function get_site_settings(): Row {
    $row = get_db()->execute('SELECT * FROM site_settings WHERE id=1')->fetchone();
    if ($row) return $row;
    get_db()->execute(
        'INSERT INTO site_settings (id, domain, site_name, default_country_code) VALUES (1, ?, ?, ?)',
        [defined('DOMAIN') ? DOMAIN : '', '', defined('DEFAULT_COUNTRY_CODE_FALLBACK') ? DEFAULT_COUNTRY_CODE_FALLBACK : '964']
    );
    get_db()->commit();
    return get_db()->execute('SELECT * FROM site_settings WHERE id=1')->fetchone();
}
