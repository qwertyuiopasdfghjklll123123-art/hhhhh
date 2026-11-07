<?php
/**
 * بطاقة المطوّر في «حسابي»: اسم + صورة + رقم واتساب يحدّدها الأدمن، وعند الضغط عليها يُفتح حساب واتساب المطوّر.
 * الإعدادات في site_settings (dev_name / dev_whatsapp / dev_note) والصورة في LOGO_DIR/dev_avatar.*
 */

function dev_avatar_file(): ?string { $f = glob(LOGO_DIR . '/dev_avatar.*') ?: []; return $f ? $f[0] : null; }

function dev_card_data(): array {
    $f = dev_avatar_file();
    return [
        'name' => trim((string)get_setting('dev_name', '')),
        'whatsapp' => preg_replace('/[^0-9]/', '', (string)get_setting('dev_whatsapp', '')),
        'note' => trim((string)get_setting('dev_note', '')),
        'image' => $f ? '/dev-avatar?v=' . (int)@filemtime($f) : '',
    ];
}

route('/api/developer-card', function () {
    $d = dev_card_data();
    $d['enabled'] = $d['whatsapp'] !== '';
    return jsonify(['ok' => true] + $d);
});

route('/dev-avatar', function () {
    $f = dev_avatar_file();
    if (!$f) return new Response('', 404);
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $r = send_file($f, ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream', false, null, 3600);
    $r->headers['X-Content-Type-Options'] = 'nosniff';
    return $r;
});

route('/api/admin/developer', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    return jsonify(['ok' => true] + dev_card_data());
});

route('/api/admin/developer/save', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $name = py_slice(py_strip(py_str(py_or(dget($data, 'name'), ''))), null, 60);
    $note = py_slice(py_strip(py_str(py_or(dget($data, 'note'), ''))), null, 80);
    $wa = preg_replace('/[^0-9]/', '', (string)py_or(dget($data, 'whatsapp'), ''));
    if ($wa !== '' && (strlen($wa) < 7 || strlen($wa) > 16)) return jsonify(['ok' => false, 'msg' => 'رقم واتساب غير صالح (بصيغة دولية بدون + أو أصفار، مثال 9647701234567)']);
    set_setting('dev_name', $name);
    set_setting('dev_note', $note);
    set_setting('dev_whatsapp', $wa);
    audit_log('developer_card_save', ['has_number' => $wa !== '']);
    return jsonify(['ok' => true, 'msg' => 'تم حفظ بطاقة المطوّر']);
}, ['POST']);

route('/api/admin/developer/image', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $f = _upload_file('image');
    if (!$f) return jsonify(['ok' => false, 'msg' => 'اختر صورة']);
    if ((int)($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || (int)($f['size'] ?? 0) > 2097152) return jsonify(['ok' => false, 'msg' => 'الحجم الأقصى 2MB']);
    $tmp = (string)($f['tmp_name'] ?? '');
    $ext = $tmp !== '' ? img_sniff_ext($tmp) : null;
    if (!$ext) return jsonify(['ok' => false, 'msg' => 'الصيغ المسموحة: png, jpg, webp, gif']);
    if (!is_dir(LOGO_DIR)) @mkdir(LOGO_DIR, 0775, true);
    foreach (glob(LOGO_DIR . '/dev_avatar.*') ?: [] as $old) @unlink($old);
    if (!_upload_save($f, LOGO_DIR . '/dev_avatar.' . $ext)) throw new RuntimeException('upload failed — تحقق من upload_max_filesize/post_max_size');
    audit_log('developer_image_upload');
    return jsonify(['ok' => true, 'image' => dev_card_data()['image']]);
}, ['POST']);

route('/api/admin/developer/image/delete', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    foreach (glob(LOGO_DIR . '/dev_avatar.*') ?: [] as $old) @unlink($old);
    return jsonify(['ok' => true]);
}, ['POST']);
