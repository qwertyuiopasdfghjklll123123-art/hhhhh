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
