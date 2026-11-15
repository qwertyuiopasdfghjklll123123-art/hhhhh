<?php
/**
 * إرسال رسائل واتساب (أكواد الدخول + الحملات) على استضافة مشتركة بدون VPS.
 *
 * webapp.py (نسخة بايثون) يرسل عبر Selenium يشغّل متصفح Chrome حقيقي مسجّل دخول بحساب
 * واتساب ويب - هذا يحتاج عملية خلفية دائمة الشغل بمتصفح كامل، وهذا **غير متاح على أي
 * استضافة مشتركة (cPanel/DirectAdmin/Hostinger)**: لا صلاحية تشغيل Chrome/chromedriver،
 * ولا عمليات خلفية تدوم بعد انتهاء طلب PHP. هذا قيد من منصة الاستضافة نفسها، لا من الكود.
 *
 * البديل هنا: كل الإرسال يمر عبر "مزوّد" (WaProvider) خارجي يستلم رقم الهاتف والنص
 * ويرسله هو عبر اتصاله الخاص بواتساب - اختر واحداً وعبّي بياناته بـ core/config.php
 * (WA_PROVIDER_DRIVER + WA_PROVIDER_CONFIG):
 *
 *   'cloud_api'    - واتساب الرسمي (Meta WhatsApp Business Cloud API). يحتاج حساب
 *                     Meta for Developers + رقم أعمال مُفعّل + قالب رسالة (template) معتمد
 *                     من ميتا لإرسال رسائل حملات/تسويق لعدد كبير من الأرقام (رسائل الأكواد
 *                     العادية OTP لا تحتاج قالب خاص عادة). الأدق والأكثر استقراراً على المدى
 *                     الطويل، لكنه يحتاج توثيق حساب تجاري حقيقي عند ميتا.
 *                     WA_PROVIDER_CONFIG = ['phone_number_id' => '...', 'access_token' => '...']
 *
 *   'http_generic' - أي مزوّد واتساب جاهز تربطه بنفسك (أغلب مزوّدي "واتساب غير رسمي" يتيحون
 *                     ربط رقمك الشخصي بمسح رمز QR من لوحتهم - نفس تجربة المستخدم القديمة
 *                     بالضبط، بدون أي VPS أو متصفح من طرفك). تكتب رابط الإرسال وترويستك
 *                     وشكل الجسم وهذا المزوّد يرسله لك كما هو.
 *                     WA_PROVIDER_CONFIG = ['send_url' => 'https://...', 'method' => 'POST',
 *                       'headers' => ['Authorization' => 'Bearer ...'],
 *                       'body' => ['to' => '{phone}', 'message' => '{text}'], 'json' => true]
 *
 *   'none' (افتراضي) - الإرسال معطّل مؤقتاً؛ أي محاولة إرسال ترجع خطأ واضح بدل ما تتعطّل
 *                     بصمت. استعمله لحد ما تقرر/تجهّز مزوّداً.
 *
 * ملاحظة: لم تُختبر هذه الدوال بعد مقابل حساب حقيقي (ما فيه بيانات اعتماد فعلية وقت كتابتها) -
 * تحقّق من استجابة أول رسالة حقيقية بعد تعبية config.php، وأعلمني لو شكل رد مزوّدك يختلف.
 */

class WaProviderException extends RuntimeException {}
class WaProviderNotConfigured extends WaProviderException {}

interface WaProvider {
    public function isReady(): bool;
    /** يرسل نصاً لرقم هاتف دولي بدون + (مثال: 9647801234567). يرمي WaProviderException عند الفشل */
    public function sendText(string $phone, string $text): void;
}

final class NullWaProvider implements WaProvider {
    public function isReady(): bool { return false; }
    public function sendText(string $phone, string $text): void {
        throw new WaProviderNotConfigured('إرسال واتساب غير مفعّل حالياً - اختر مزوّداً وعبّي بياناته بـ core/config.php (WA_PROVIDER_DRIVER)');
    }
}

final class CloudApiWaProvider implements WaProvider {
    private string $phoneNumberId;
    private string $accessToken;
    public function __construct(array $cfg) {
        $this->phoneNumberId = (string)($cfg['phone_number_id'] ?? '');
        $this->accessToken = (string)($cfg['access_token'] ?? '');
    }
    public function isReady(): bool { return $this->phoneNumberId !== '' && $this->accessToken !== ''; }
    public function sendText(string $phone, string $text): void {
        if (!$this->isReady()) throw new WaProviderNotConfigured('WA_PROVIDER_CONFIG ناقص: phone_number_id أو access_token');
        $url = 'https://graph.facebook.com/v20.0/' . rawurlencode($this->phoneNumberId) . '/messages';
        try {
            $res = requests_post($url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => ['body' => $text],
                ],
                'timeout' => 20,
            ]);
        } catch (RequestsException $e) {
            throw new WaProviderException('تعذر الاتصال بواتساب (Meta Cloud API): ' . $e->getMessage(), 0, $e);
        }
        if (!$res->ok()) {
            throw new WaProviderException('رفض واتساب (Meta Cloud API) الرسالة [' . $res->status_code . ']: ' . substr($res->text, 0, 300));
        }
    }
}

final class HttpGenericWaProvider implements WaProvider {
    private array $cfg;
    public function __construct(array $cfg) { $this->cfg = $cfg; }
    public function isReady(): bool { return !empty($this->cfg['send_url']); }
    public function sendText(string $phone, string $text): void {
        if (!$this->isReady()) throw new WaProviderNotConfigured('WA_PROVIDER_CONFIG ناقص: send_url');
        $fill = function ($v) use ($phone, $text) {
            return is_string($v) ? str_replace(['{phone}', '{text}'], [$phone, $text], $v) : $v;
        };
        $body = array_map($fill, (array)($this->cfg['body'] ?? ['to' => '{phone}', 'message' => '{text}']));
        $headers = array_map($fill, (array)($this->cfg['headers'] ?? []));
        $opts = ['headers' => $headers, 'timeout' => 20];
        $opts[!empty($this->cfg['json']) ? 'json' : 'data'] = $body;
        try {
            $res = requests_post((string)$this->cfg['send_url'], $opts);
        } catch (RequestsException $e) {
            throw new WaProviderException('تعذر الاتصال بمزوّد واتساب: ' . $e->getMessage(), 0, $e);
        }
        if (!$res->ok()) {
            throw new WaProviderException('رفض مزوّد واتساب الرسالة [' . $res->status_code . ']: ' . substr($res->text, 0, 300));
        }
    }
}

function wa_provider(): WaProvider {
    $driver = defined('WA_PROVIDER_DRIVER') ? WA_PROVIDER_DRIVER : 'none';
    $cfg = defined('WA_PROVIDER_CONFIG') ? (array)WA_PROVIDER_CONFIG : [];
    switch ($driver) {
        case 'cloud_api': return new CloudApiWaProvider($cfg);
        case 'http_generic': return new HttpGenericWaProvider($cfg);
        default: return new NullWaProvider();
    }
}
