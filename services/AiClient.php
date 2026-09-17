<?php
declare(strict_types=1);

/**
 * عميل ذكاء اصطناعي عام — متوافق مع بنية استدعاءات OpenAI Chat Completions.
 * يعمل مع أي مزوّد يطابق هذه البنية عبر تحديد نقطة الاتصال (base URL) الخاصة
 * به: NVIDIA NIM (الافتراضي)، OpenAI، Groq، DeepSeek، Together AI، OpenRouter،
 * Mistral، أو أي نموذج مستضاف ذاتياً (vLLM/Ollama بواجهة متوافقة مع OpenAI).
 *
 * يدعم:
 *   - محادثات نصية عادية (chat)
 *   - محادثات برؤية/صور (chatWithImage) لنماذج Vision المتوافقة
 *   - بناء موجّه نظام (System Prompt) يحقن تلقائياً هيكل قاعدة البيانات
 *     وقواعد المشروع المحفوظة لكل مشروع (buildSystemPrompt)
 *
 * ملاحظة أمنية: هذا الصف يُستخدم من طرف السيرفر فقط. مفتاح الـ API لا يصل
 * إطلاقاً إلى متصفح المستخدم.
 */
final class AiClient
{
    public const DEFAULT_ENDPOINT = 'https://integrate.api.nvidia.com/v1/chat/completions';

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_ENDPOINT, string $model = 'openai/gpt-oss-20b', int $timeout = 90)
    {
        $this->apiKey  = $apiKey;
        $this->baseUrl = $baseUrl !== '' ? $baseUrl : self::DEFAULT_ENDPOINT;
        $this->model   = $model;
        $this->timeout = $timeout;
    }

    /**
     * يبني موجّه النظام (System Prompt) بحقن SQL Schema وقواعد المشروع تلقائياً،
     * ويُستدعى قبل أي طلب مراجعة أو تعديل كود كما هو مطلوب في مواصفات النظام.
     */
    public static function buildSystemPrompt(?string $sqlSchema, ?string $systemRules, ?string $extraContext = null): string
    {
        $parts = [
            'أنت مساعد برمجي خبير مدمج داخل لوحة إدارة مشاريع. مهمتك مراجعة الكود، ' .
            'اقتراح تعديلات دقيقة وقابلة للتطبيق مباشرة، والإجابة عن أسئلة تخص هذا المشروع تحديداً. ' .
            'التزم دائماً بهيكل قاعدة البيانات وقواعد المشروع أدناه كمصدر الحقيقة الوحيد، ' .
            'وعند اقتراح كود أعد كتلة كود كاملة وواضحة داخل ```لغة البرمجة ... ``` بحيث يمكن نسخها وتطبيقها مباشرة.',
        ];

        if ($systemRules !== null && trim($systemRules) !== '') {
            $parts[] = "### قواعد المشروع والتوجيهات البرمجية\n" . trim($systemRules);
        }

        if ($sqlSchema !== null && trim($sqlSchema) !== '') {
            $parts[] = "### هيكل قاعدة البيانات (SQL Schema)\n```sql\n" . trim($sqlSchema) . "\n```";
        }

        if ($extraContext !== null && trim($extraContext) !== '') {
            $parts[] = "### سياق إضافي (ملف من المستودع)\n" . trim($extraContext);
        }

        return implode("\n\n", $parts);
    }

    /**
     * محادثة نصية. $messages مصفوفة عناصر ['role' => 'system|user|assistant', 'content' => string]
     */
    public function chat(array $messages, array $options = []): array
    {
        $payload = array_merge([
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.4,
            'top_p'       => 0.9,
            'max_tokens'  => 4096,
            'stream'      => false,
        ], $options);

        return $this->send($payload);
    }

    /**
     * محادثة برؤية (Vision): نص المستخدم + صورة واحدة (base64) لنموذج يدعم الرؤية.
     */
    public function chatWithImage(string $systemPrompt, string $userText, string $imageBase64, string $mimeType, array $priorMessages = [], array $options = []): array
    {
        $messages   = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        foreach ($priorMessages as $m) {
            $messages[] = $m;
        }
        $messages[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $userText],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$imageBase64}"]],
            ],
        ];

        return $this->chat($messages, $options);
    }

    private function send(array $payload): array
    {
        if (trim($this->apiKey) === '') {
            return ['success' => false, 'error' => 'لا يوجد مفتاح API صالح لهذا المزوّد.'];
        }

        // إعادة محاولة واحدة فقط عند فشل على مستوى النقل (مهلة/DNS/اتصال) دون أي
        // رد فعلي من الخادم؛ هذا النوع تحديداً متقطّع أحياناً (نفس المزوّد قد ينجح
        // مرة ويتجمّد أخرى)، بخلاف رد HTTP فعلي (حتى لو خطأ) الذي لا فائدة من إعادته.
        $maxAttempts = 2;
        $transportError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($this->baseUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    // تعطيل انتظار "100 Continue": أجسام الطلبات هنا (موجّه النظام + المحادثة)
                    // غالباً أكبر من 1KB فيفعّلها cURL تلقائياً، وبعض الخوادم/الوسطاء خلف
                    // موازنات التحميل لا يردّون عليها إطلاقاً فيتجمّد الطلب حتى انتهاء المهلة
                    // رغم أن الاتصال بنفس المضيف يعمل بسرعة لأي طلب بلا جسم (مثل HEAD/GET).
                    'Expect:',
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                // بعض الوسطاء (Load Balancers/CDN) أمام واجهات API تتعثّر مع تفاوض HTTP/2
                // عبر ALPN فيتجمّد الطلب صامتاً؛ تثبيت HTTP/1.1 صريحاً أكثر توافقاً وأماناً هنا.
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                $transportError = curl_error($ch);
                curl_close($ch);
                if ($attempt < $maxAttempts) {
                    usleep(500000);
                    continue;
                }
                return [
                    'success' => false,
                    'error'   => 'تعذّر الاتصال بمزوّد الذكاء الاصطناعي (' . $this->baseUrl . ') بعد ' . $maxAttempts . ' محاولات: ' . $transportError,
                ];
            }

            $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            return $this->parseResponse($response, $status, $effectiveUrl);
        }

        return ['success' => false, 'error' => 'تعذّر الاتصال بمزوّد الذكاء الاصطناعي: ' . $transportError];
    }

    private function parseResponse(string $response, int $status, string $effectiveUrl): array
    {
        $data = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? $data['message'] ?? null;
            if ($message === null) {
                // لا رسالة خطأ منظَّمة من المزوّد؛ نُرفق الرابط الفعلي وعينة من الرد الخام
                // بدل "HTTP 404" وحدها، حتى يكون سبب الخطأ واضحاً من أول مرة (مسار خاطئ،
                // اسم نموذج غير موجود، توجيه غير متوقَّع...).
                $bodySnippet = trim(mb_substr((string) $response, 0, 200));
                $message = 'HTTP ' . $status . ' من ' . $effectiveUrl . ($bodySnippet !== '' ? ' — ' . $bodySnippet : ' (رد فارغ)');
            }
            return ['success' => false, 'error' => $message, 'status' => $status];
        }

        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            return ['success' => false, 'error' => 'استجابة غير متوقعة من مزوّد الذكاء الاصطناعي.', 'raw' => $data];
        }

        $content   = is_string($message['content'] ?? null) ? $message['content'] : '';
        // بعض نماذج الاستدلال (reasoning) مثل gpt-oss تعيد سلسلة تفكيرها في
        // reasoning_content منفصلة عن الإجابة النهائية في content.
        $reasoning = is_string($message['reasoning_content'] ?? null) && trim($message['reasoning_content']) !== ''
            ? $message['reasoning_content']
            : null;

        if ($content === '' && $reasoning === null) {
            return ['success' => false, 'error' => 'استجابة فارغة من مزوّد الذكاء الاصطناعي.', 'raw' => $data];
        }

        return [
            'success'   => true,
            'content'   => $content,
            'reasoning' => $reasoning,
            'usage'     => $data['usage'] ?? null,
            'model'     => $data['model'] ?? $this->model,
        ];
    }
}
