<?php
/**
 * محفظة واصل: تسعير اختياري بالرسالة الواحدة يحدده الأدمن (site_settings.price_per_message_iqd،
 * صفر = معطّل/الإرسال مجاني كما كان). عند التفعيل يُخصَم سعر الرسالة من محفظة المالك
 * (users.balance_iqd) فقط بعد نجاح الإرسال الفعلي عبر البوابة - لا خصم على فشل الإرسال.
 * الأدمن مستثنى دائماً من الخصم (أدوات تشغيل المنصة نفسها لا تُحتسب استهلاكاً).
 *
 * تُطبَّق على: رسائل الحملات (cron.php) وتذكيرات المشتركين (cron.php) والإرسال عبر
 * API الخارجي (/api/v1/send). لا تُطبَّق على أكواد تحقق واتساب (OTP تسجيل الدخول) ولا
 * على ردود الرد الآلي - هذه بنية تحتية للمنصة نفسها لا "حملة" يدفع المستخدم ثمنها.
 */

/** رصيد غير كافٍ - فرع من GatewayException عمداً: أي كود قديم يصطاد GatewayException
 *  يستمر يعمل، وأي كود جديد (cron.php) يقدر يصطادها تحديداً ليوقف الحملة لا يفشلها رقماً برقم */
class InsufficientBalanceException extends GatewayException {}

/** سعر الرسالة الحالي بالدينار (0 = التسعير بالرسالة معطّل تماماً) */
function message_price_iqd(): int {
    $s = get_site_settings();
    return max(0, (int)py_or($s['price_per_message_iqd'], 0));
}

function wallet_balance_iqd(int $userId): int {
    $row = get_db()->execute('SELECT balance_iqd FROM users WHERE id=?', [$userId])->fetchone();
    return $row ? (int)$row['balance_iqd'] : 0;
}

/**
 * يرسل رسالة عبر البوابة، ويخصم سعرها من محفظة المالك عند التفعيل - بعد نجاح الإرسال
 * فقط. يفحص الرصيد قبل أي محاولة إرسال؛ غير كافٍ يرمي InsufficientBalanceException
 * فوراً بدون إرسال شيء. فشل الإرسال نفسه (GatewayException عادية) لا يخصم شيئاً.
 */
function wsl_wallet_charge_and_send(int $ownerId, string $accId, string $phone, string $text): void {
    $price = message_price_iqd();
    if ($price <= 0) { gateway_send($accId, $phone, $text); return; }

    $conn = get_db();
    $owner = $conn->execute('SELECT is_admin, balance_iqd FROM users WHERE id=?', [$ownerId])->fetchone();
    $isAdmin = $owner && py_truthy($owner['is_admin']);
    if (!$isAdmin && (!$owner || (int)$owner['balance_iqd'] < $price)) {
        throw new InsufficientBalanceException(
            'رصيد محفظتك غير كافٍ لإرسال رسالة جديدة (سعر الرسالة: ' . number_format($price) . ' د.ع). اشحن محفظتك من الإعدادات ← المحفظة.'
        );
    }
    gateway_send($accId, $phone, $text);
    if (!$isAdmin) {
        $conn->execute('UPDATE users SET balance_iqd=balance_iqd-? WHERE id=?', [$price, $ownerId]);
        $conn->commit();
    }
}
