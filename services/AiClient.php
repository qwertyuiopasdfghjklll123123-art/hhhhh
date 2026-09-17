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

    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_ENDPOINT, string $model = 'meta/llama-3.1-70b-instruct', int $timeout = 90)
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

        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => 'تعذّر الاتصال بمزوّد الذكاء الاصطناعي: ' . $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? $data['message'] ?? ('HTTP ' . $status);
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
