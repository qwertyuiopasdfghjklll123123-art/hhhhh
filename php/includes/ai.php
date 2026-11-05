<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/smm.php';

define('AI_API_BASE', 'https://integrate.api.nvidia.com/v1/chat/completions');

/** @return array{0:string|null,1:string|null} [reply, error] — مكافئ call_ai_api() */
function call_ai_api($messages) {
    $apiKey = get_setting('nvidia_api_key', '');
    $model = get_setting('nvidia_model', 'openai/gpt-oss-20b') ?: 'openai/gpt-oss-20b';
    if (!$apiKey) {
        return [null, 'لم يتم إعداد مفتاح المساعد الذكي بعد. الرجاء التواصل مع الإدارة.'];
    }

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'temperature' => 1,
        'top_p' => 1,
        'max_tokens' => 4096,
        'stream' => false,
    ]);

    $body = null; $httpCode = 0; $curlErr = '';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init(AI_API_BASE);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $apiKey"],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body !== false) break;
        if ($curlErrNo === CURLE_OPERATION_TIMEOUTED) {
            return [null, 'استغرق رد المساعد الذكي وقتاً أطول من المعتاد، حاول مرة أخرى.'];
        }
        if ($attempt === 0) {
            usleep(1500000);
            continue;
        }
        return [null, 'تعذر الاتصال بخدمة الذكاء الاصطناعي، تحقق من اتصالك وحاول مرة أخرى.'];
    }

    if ($httpCode !== 200) {
        return [null, 'تعذر الحصول على رد من المساعد الذكي حالياً، حاول مرة أخرى.'];
    }
    $json = json_decode($body, true);
    $reply = $json['choices'][0]['message']['content'] ?? null;
    if (!$reply) {
        return [null, 'لم يصل رد من خدمة الذكاء الاصطناعي.'];
    }
    return [$reply, null];
}

/** سياق حالة حساب المستخدم (رصيد، عدد الطلبات، آخر حالة) يُضاف للـ system prompt */
function ai_account_context($uid) {
    $stmt = db()->prepare('SELECT balance FROM users WHERE id=?');
    $stmt->execute([$uid]);
    $balance = (float)($stmt->fetch()['balance'] ?? 0);

    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM orders WHERE user_id=?');
    $stmt->execute([$uid]);
    $orderCount = (int)$stmt->fetch()['c'];

    $stmt = db()->prepare('SELECT display_id, service_name, status FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 3');
    $stmt->execute([$uid]);
    $recent = $stmt->fetchAll();

    $ctx = "\n\nمعلومات حساب المستخدم الحالي (حقيقية، استخدمها عند الحاجة): الرصيد $" . number_format($balance, 2)
        . "، عدد الطلبات الكلي: $orderCount.";
    if ($recent) {
        $ctx .= ' آخر طلباته: ';
        foreach ($recent as $r) {
            $ctx .= "#" . ($r['display_id'] ?: '') . " ({$r['service_name']} - {$r['status']}); ";
        }
    }
    return $ctx;
}

/** يبحث عن خدمات مطابقة لرسالة المستخدم داخل الكتالوج الحالي */
function ai_find_services($query) {
    $query = mb_strtolower(trim($query));
    if ($query === '') return [];
    $catalog = build_services_catalog();
    $matches = [];
    foreach ($catalog as $category => $items) {
        foreach ($items as $it) {
            $name = mb_strtolower($it['name']);
            foreach (preg_split('/\s+/u', $query) as $word) {
                if (mb_strlen($word) >= 3 && mb_strpos($name, $word) !== false) {
                    $matches[] = $it;
                    break;
                }
            }
            if (count($matches) >= 5) break 2;
        }
    }
    return $matches;
}

function ai_services_context($matches) {
    if (empty($matches)) {
        return "\n\nملاحظة: لم يتم إيجاد تطابق حرفي بالكتالوج لما ذكره المستخدم — هذا لا يعني أن الخدمة غير متوفرة أبداً،"
            . ' فقط اطلب منه تفصيل اسم الخدمة أو المنصة أكثر، ولا تخبره أبداً أن الخدمة غير متوفرة بناءً على هذا فقط.';
    }
    $ctx = "\n\nخدمات مطابقة فعلياً وموجودة بالكتالوج الآن (أخبر المستخدم أنها متوفرة، لا تقل أبداً أنها غير متاحة):";
    foreach ($matches as $m) {
        $ctx .= "\n- {$m['name']}: \${$m['rate']} لكل 1000، الكمية من {$m['min']} إلى {$m['max']}.";
    }
    return $ctx;
}

function ai_payment_methods_context() {
    $methods = db()->query('SELECT name FROM payment_methods WHERE active=1')->fetchAll();
    if (!$methods) return '';
    $names = implode('، ', array_map(fn($m) => $m['name'], $methods));
    return "\n\nطرق الدفع المتاحة حالياً لشحن الرصيد: $names.";
}
