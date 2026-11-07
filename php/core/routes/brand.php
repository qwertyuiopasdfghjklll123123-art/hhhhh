<?php
/**
 * الشعار المتحرك: فيديو أو صورة متحركة شفافة تحلّ محل اسم الموقع في الشريط العلوي لواجهة المستخدم.
 * يرفعه الأدمن من «اللوغو واسم الموقع». نسختان اختياريتان:
 *   A: webm (VP9 بقناة ألفا) أو gif / webp / png متحرك / svg / mp4
 *   B: mov أو mp4 (HEVC بقناة ألفا) ليعمل الشفاف على Safari/iOS
 */

const BRAND_MAX_BYTES = 8388608;   // 8MB
const BRAND_EXT_A = ['webm', 'mp4', 'gif', 'webp', 'png', 'svg'];
const BRAND_EXT_B = ['mov', 'mp4'];

/** وضع شعار الشريط العلوي: default (الشعار المتحرك الافتراضي أو المرفوع) | name (اسم الموقع فقط) */
function brand_mode(): string { return get_setting('brand_mode', 'default') === 'name' ? 'name' : 'default'; }
function brand_files(string $slot): array { return glob(LOGO_DIR . '/brand_' . $slot . '.*') ?: []; }
function brand_file(string $slot): ?string { $f = brand_files($slot); return $f ? $f[0] : null; }

/** فحص محتوى الملف الفعلي (لا نثق بالامتداد وحده). يرجع [ext, mime] أو null */
function brand_sniff(string $tmp, string $ext): ?array {
    $h = (string)@file_get_contents($tmp, false, null, 0, 4096);
    if ($h === '') return null;
    $isMp4 = strlen($h) >= 12 && substr($h, 4, 4) === 'ftyp';
    switch ($ext) {
        case 'webm': return strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0 ? ['webm', 'video/webm'] : null;
        case 'mp4':  return $isMp4 ? ['mp4', 'video/mp4'] : null;
        case 'mov':  return $isMp4 ? ['mov', 'video/quicktime'] : null;
        case 'gif':  return strncmp($h, 'GIF8', 4) === 0 ? ['gif', 'image/gif'] : null;
        case 'png':  return strncmp($h, "\x89PNG", 4) === 0 ? ['png', 'image/png'] : null;
        case 'webp': return (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') ? ['webp', 'image/webp'] : null;
        case 'svg':
            $all = (string)@file_get_contents($tmp, false, null, 0, 1048576);
            if (stripos($all, '<svg') === false || preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject/i', $all)) return null;
            return ['svg', 'image/svg+xml'];
    }
    return null;
}

/** وسوم الشعار المتحرك لتُوضع مكان اسم الموقع (أو null إن لم يُرفع شيء) */
function brand_media_html(string $site_name): ?string {
    if (brand_mode() === 'name') return null;
    $a = brand_file('a'); $b = brand_file('b');
    if (!$a && !$b) {   // لا شعار مرفوع: الشعار المتحرك الافتراضي (FC) يتشكّل بـ 5 ثوانٍ
        $alt0 = htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8');
        return '<div class="brand-name" id="brandNameDisplay" style="display:none">' . $alt0 . '</div><img class="brand-media brand-default" src="/brand-default.svg?v=3" alt="' . $alt0 . '" width="38" height="38">';
    }
    $v = max($a ? (int)@filemtime($a) : 0, $b ? (int)@filemtime($b) : 0);
    $alt = htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8');
    $extA = $a ? strtolower(pathinfo($a, PATHINFO_EXTENSION)) : '';
    $isVideo = in_array($extA, ['webm', 'mp4'], true) || ($b && !$a);
    $name = '<div class="brand-name" id="brandNameDisplay" style="display:none">' . $alt . '</div>';
    if ($isVideo) {
        $src = '';
        if ($b) $src .= '<source src="/brand-media/b?v=' . $v . '" type="' . (strtolower(pathinfo($b, PATHINFO_EXTENSION)) === 'mov' ? 'video/quicktime; codecs=hvc1' : 'video/mp4') . '">';
        if ($a) $src .= '<source src="/brand-media/a?v=' . $v . '" type="' . ($extA === 'mp4' ? 'video/mp4' : 'video/webm') . '">';
        return $name . '<video class="brand-media" autoplay muted loop playsinline webkit-playsinline disablepictureinpicture preload="auto" aria-label="' . $alt . '">' . $src . '</video>';
    }
    return $name . '<img class="brand-media" src="/brand-media/a?v=' . $v . '" alt="' . $alt . '">';
}

route('/brand-media/<slot>', function ($slot) {
    $f = in_array($slot, ['a', 'b'], true) ? brand_file($slot) : null;
    if (!$f) return new Response('', 404);
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $mime = ['webm' => 'video/webm', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'gif' => 'image/gif', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'][$ext] ?? 'application/octet-stream';
    $r = send_file($f, $mime, false, null, 86400);
    $r->headers['X-Content-Type-Options'] = 'nosniff';
    $r->headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";   // ملف SVG لا ينفّذ سكربتاً حتى لو فُتح مباشرة
    return $r;
});

/* الشعار المتحرك الافتراضي: SVG شفاف (يتشكّل بـ 5 ثوانٍ) + نسخة فيديو webm بقناة ألفا قابلة للرفع من الأدمن */
foreach (['svg' => 'image/svg+xml', 'webm' => 'video/webm'] as $_bx => $_bm) {
    route('/brand-default.' . $_bx, function () use ($_bx, $_bm) {
        $f = APP_DIR . '/templates/brand_default.' . $_bx;
        if (!is_file($f)) return new Response('', 404);
        $r = send_file($f, $_bm, false, null, 604800);
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        $r->headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        if ($_bx === 'webm' && isset($_GET['dl'])) $r->headers['Content-Disposition'] = 'attachment; filename="fc_logo_animation.webm"';
        return $r;
    });
}

route('/api/admin/brand-mode', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $m = (string)py_or(dget(req_data(), 'mode'), '');
    if (!in_array($m, ['default', 'name'], true)) return jsonify(['ok' => false, 'msg' => 'وضع غير صالح']);
    set_setting('brand_mode', $m);
    audit_log('brand_mode', ['mode' => $m]);
    return jsonify(['ok' => true, 'mode' => $m]);
}, ['POST']);

route('/api/admin/brand-media', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $out = [];
    foreach (['a', 'b'] as $slot) { $f = brand_file($slot); $out[$slot] = $f ? ['ext' => strtolower(pathinfo($f, PATHINFO_EXTENSION)), 'size' => (int)@filesize($f)] : null; }
    return jsonify(['ok' => true, 'media' => $out, 'v' => time(), 'mode' => brand_mode()]);
});

route('/api/admin/upload-brand-media', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $slot = (($_POST['slot'] ?? 'a') === 'b') ? 'b' : 'a';
    $f = _upload_file('media');
    if (!$f) return jsonify(['ok' => false, 'error' => 'no file', 'msg' => 'اختر ملفاً']);
    if ((int)($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || (int)($f['size'] ?? 0) > BRAND_MAX_BYTES) return jsonify(['ok' => false, 'msg' => 'الحجم الأقصى 8MB']);
    $ext = _upload_ext((string)$f['name'], '');
    if ($ext === 'jpeg') $ext = 'png';
    if (!in_array($ext, $slot === 'a' ? BRAND_EXT_A : BRAND_EXT_B, true)) {
        return jsonify(['ok' => false, 'msg' => $slot === 'a' ? 'الصيغ المسموحة: webm, mp4, gif, webp, png, svg' : 'الصيغ المسموحة لنسخة Safari: mov, mp4']);
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $sn = $tmp !== '' ? brand_sniff($tmp, $ext) : null;
    if (!$sn) return jsonify(['ok' => false, 'msg' => 'محتوى الملف لا يطابق صيغته أو غير آمن']);
    foreach (brand_files($slot) as $old) @unlink($old);
    if (!is_dir(LOGO_DIR)) @mkdir(LOGO_DIR, 0775, true);
    if (!_upload_save($f, LOGO_DIR . '/brand_' . $slot . '.' . $sn[0])) throw new RuntimeException('upload failed — تحقق من upload_max_filesize/post_max_size');
    audit_log('brand_media_upload', ['slot' => $slot, 'ext' => $sn[0]]);
    return jsonify(['ok' => true, 'msg' => 'تم رفع الشعار المتحرك']);
}, ['POST']);

route('/api/admin/delete-brand-media', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    foreach (['a', 'b'] as $slot) foreach (brand_files($slot) as $old) @unlink($old);
    audit_log('brand_media_delete');
    return jsonify(['ok' => true]);
}, ['POST']);
