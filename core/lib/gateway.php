<?php
/**
 * عميل بسيط لـ wasel-gateway (تطبيق Node.js/Baileys المنفصل اللي يشغّل حسابات واتساب
 * فعلياً - راجع wasel-gateway/README.md). هذا الملف بس HTTP calls، ما يلمس قاعدة البيانات.
 */

class GatewayException extends RuntimeException {}
class GatewayNotConfigured extends GatewayException {}

function gateway_configured(): bool {
    return GATEWAY_BASE_URL !== '' && GATEWAY_TOKEN !== '';
}

function gateway_request(string $method, string $path, array $opts = []): array {
    if (!gateway_configured()) {
        throw new GatewayNotConfigured('بوابة واتساب (wasel-gateway) غير مضبوطة - اكتب GATEWAY_BASE_URL و GATEWAY_TOKEN بـ core/config.php');
    }
    $opts['headers'] = array_merge(['Authorization' => 'Bearer ' . GATEWAY_TOKEN], $opts['headers'] ?? []);
    $opts['timeout'] = $opts['timeout'] ?? 15;
    try {
        $res = http_request($method, rtrim(GATEWAY_BASE_URL, '/') . $path, $opts);
    } catch (RequestsException $e) {
        throw new GatewayException('تعذر الاتصال ببوابة واتساب: ' . $e->getMessage(), 0, $e);
    }
    $json = json_decode($res->text, true);
    if (!is_array($json)) $json = ['ok' => $res->ok()];
    $json['_status'] = $res->status_code;
    return $json;
}

/** يبدأ/يستأنف جلسة حساب (آمن الاستدعاء المتكرر). يرمي GatewayException لو تعذّر الاتصال بالبوابة نفسها */
function gateway_start_session(string $accId): array {
    return gateway_request('POST', "/sessions/$accId/start");
}

/** {status: connecting|qr|connected|disconnected, phone} - لا يرمي أبداً لو البوابة وصلت (ترجع disconnected بأمان) */
function gateway_status(string $accId): array {
    try {
        return gateway_request('GET', "/sessions/$accId/status");
    } catch (GatewayException $e) {
        return ['ok' => false, 'status' => 'disconnected', 'phone' => null, 'error' => $e->getMessage()];
    }
}

/** صورة PNG للباركود الحالي، أو null لو ما فيه باركود جاهز (قبل البدء/متصل أصلاً) */
function gateway_qr_png(string $accId): ?string {
    if (!gateway_configured()) return null;
    try {
        $res = http_request('GET', rtrim(GATEWAY_BASE_URL, '/') . "/sessions/$accId/qr.png", [
            'headers' => ['Authorization' => 'Bearer ' . GATEWAY_TOKEN], 'timeout' => 10,
        ]);
    } catch (RequestsException $e) {
        return null;
    }
    return $res->status_code === 200 ? $res->text : null;
}

/** يرسل رسالة نصية. يرمي GatewayException برسالة مفهومة عند الفشل (حساب غير متصل، تعذر الاتصال...) */
function gateway_send(string $accId, string $phone, string $text): void {
    $r = gateway_request('POST', "/sessions/$accId/send", ['json' => ['phone' => $phone, 'text' => $text]]);
    if (empty($r['ok'])) {
        throw new GatewayException((string)($r['error'] ?? 'فشل إرسال غير معروف من البوابة'));
    }
}

function gateway_logout(string $accId): void {
    try { gateway_request('POST', "/sessions/$accId/logout"); } catch (GatewayException $e) {}
}

function gateway_delete_session(string $accId): void {
    try { gateway_request('DELETE', "/sessions/$accId"); } catch (GatewayException $e) {}
}
