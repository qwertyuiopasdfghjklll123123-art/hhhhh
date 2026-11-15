<?php
/**
 * خطافات ما قبل كل طلب - جديدة بالكامل (webapp.py لا تحتوي أي before_request)،
 * أضيفت كميزات حماية إضافية صريحة طلبها صاحب المنصة:
 *   - origin_check(): حماية CSRF على الطلبات المغيِّرة (POST/PUT/DELETE) من موقع آخر
 *   - admin_ip_allowed(): قائمة بيضاء اختيارية لعناوين لوحة الأدمن (معطّلة افتراضياً)
 * لا تُغيّر أي سلوك قائم: جلسة واصل دائمة التجديد من نفسها (session.php) فلا حاجة
 * لمحاكاة session.permanent = True هنا.
 */
/** مهام مؤجّلة بعد إرسال الاستجابة (لا شيء بعد بـ واصل - محجوزة لمرحلة لاحقة مثل cron.php) */
function run_deferred(): void {}

function run_before_request_hooks(): ?Response {
    $path = req_path();
    if (($csrf = origin_check()) !== null) return $csrf;
    if (admin_path($path) && !admin_ip_allowed()) {
        audit_log('admin_ip_denied', ['path' => $path]);
        return jsonify(['ok' => false, 'msg' => 'غير مسموح من هذا العنوان'], 403);
    }
    return null;
}
