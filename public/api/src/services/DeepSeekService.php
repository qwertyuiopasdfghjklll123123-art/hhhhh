<?php

class DeepSeekService
{
    // نداء عام لواجهة chat/completions المتوافقة مع OpenAI والتي توفرها DeepSeek
    // راجع: https://api-docs.deepseek.com/
    public static function chatCompletion($messages, $temperature = 0.6, $jsonMode = false, $maxTokens = 2000)
    {
        $config = SettingsService::getActiveConfig('deepseek');
        if (!$config['apiKey']) {
            throw new ApiException(
                412,
                'لم يتم ضبط مفتاح DeepSeek API بعد. أضِفه من صفحة الإعدادات (Settings) في لوحة التحكم.'
            );
        }

        $payload = [
            'model' => $config['model'],
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $url = rtrim($config['baseUrl'], '/') . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['apiKey'],
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new ApiException(502, 'تعذّر الوصول إلى DeepSeek API: ' . $curlError);
        }

        $data = json_decode($responseBody, true);

        if ($statusCode >= 400) {
            $message = isset($data['error']['message']) ? $data['error']['message'] : 'خطأ غير معروف';
            throw new ApiException(502, "فشل الاتصال بـ DeepSeek ($statusCode): $message");
        }

        $content = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
        return ['content' => $content, 'usage' => $data['usage'] ?? null];
    }

    private static function parseJsonSafely($text)
    {
        $cleaned = trim(preg_replace('/```json|```/i', '', $text));
        $decoded = json_decode($cleaned, true);
        if ($decoded === null) {
            throw new ApiException(502, 'رد الذكاء الاصطناعي لم يكن بصيغة JSON صالحة');
        }
        return $decoded;
    }

    // === محرك المناهج المدعوم بالذكاء الاصطناعي (AI Content Pipeline) ===
    // ملاحظة: النموذج اللغوي لا يستطيع التحقق من روابط يوتيوب الحقيقية، لذا نطلب منه
    // اقتراح "عبارة بحث" فقط لكل محاضرة، ثم YoutubeService (اختيارية) تبحث فعلياً
    public static function generateCurriculum($countryName, $stageName, $subjectCount = 5, $unitsPerSubject = 4, $lecturesPerUnit = 3)
    {
        $system = 'أنت خبير مناهج تعليمية عربية. مهمتك اقتراح هيكل دراسي دقيق ومناسب '
            . 'لعمر ومستوى الطلاب. أعد النتيجة بصيغة JSON فقط بدون أي شرح إضافي.';

        $user = "اقترح هيكلاً دراسياً لدولة \"$countryName\" للمرحلة \"$stageName\".\n"
            . "أعد $subjectCount مواد دراسية رئيسية مناسبة لهذه المرحلة في هذه الدولة.\n"
            . "لكل مادة أعد $unitsPerSubject وحدات/فصول بالترتيب المنطقي للمنهج.\n"
            . "لكل وحدة أعد $lecturesPerUnit محاضرات بعناوين واضحة ووصف قصير، "
            . "مع اقتراح \"search_query\" (عبارة بحث يوتيوب بالعربية) تساعد لاحقاً بإيجاد فيديو تعليمي موثوق لهذه المحاضرة.\n\n"
            . 'أعد الناتج بالشكل التالي بالضبط (JSON فقط):
{
  "subjects": [
    {
      "name_ar": "اسم المادة",
      "icon": "اسم أيقونة Font Awesome مناسب مثل fa-square-root-variable",
      "units": [
        {
          "title_ar": "عنوان الوحدة",
          "description": "وصف قصير",
          "lectures": [
            { "title_ar": "عنوان المحاضرة", "description": "وصف قصير", "search_query": "عبارة بحث يوتيوب" }
          ]
        }
      ]
    }
  ]
}';

        $result = self::chatCompletion(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            0.5,
            true,
            4000
        );

        return self::parseJsonSafely($result['content']);
    }

    // === قسم الامتحانات التلقائية (AI Quiz System) ===
    public static function generateQuiz($lectureTitle, $lectureDescription, $transcript, $questionCount = 5)
    {
        $system = 'أنت مساعد تعليمي متخصص بإعداد اختبارات اختيار من متعدد بالعربية. '
            . 'أعد النتيجة بصيغة JSON فقط بدون أي نص خارج JSON.';

        $contextText = $transcript
            ? 'محتوى/ملخص المحاضرة:' . "\n" . mb_substr($transcript, 0, 6000)
            : 'وصف المحاضرة: ' . ($lectureDescription ?: 'غير متوفر');

        $user = "أنشئ اختباراً من $questionCount أسئلة اختيار من متعدد حول محاضرة بعنوان \"$lectureTitle\".\n"
            . "$contextText\n\n"
            . 'لكل سؤال أعد 4 خيارات، خيار واحد صحيح فقط، مع شرح مختصر للإجابة الصحيحة.
أعد الناتج بالشكل التالي بالضبط (JSON فقط):
{
  "questions": [
    {
      "question_text": "نص السؤال",
      "explanation": "شرح مختصر للإجابة الصحيحة",
      "options": [
        { "text": "خيار 1", "is_correct": false },
        { "text": "خيار 2", "is_correct": true },
        { "text": "خيار 3", "is_correct": false },
        { "text": "خيار 4", "is_correct": false }
      ]
    }
  ]
}';

        $result = self::chatCompletion(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            0.4,
            true,
            3000
        );

        return self::parseJsonSafely($result['content']);
    }

    // === المساعد الشخصي الذكي (AI Tutor) ===
    public static function tutorReply($lectureTitle, $lectureDescription, $transcript, $history, $userMessage)
    {
        $system = "أنت مدرّس خصوصي ذكي ومحفّز لطالب عربي. أجب فقط ضمن سياق المحاضرة الحالية، "
            . "بسّط الأفكار المعقدة بأمثلة قريبة من واقع الطالب، وكن مختصراً ومشجعاً.\n"
            . "إن سُئلت عن موضوع خارج سياق المحاضرة، وجّه الطالب بلطف للعودة لموضوع الدرس.\n\n"
            . "عنوان المحاضرة: $lectureTitle\n"
            . 'وصف المحاضرة: ' . ($lectureDescription ?: 'غير متوفر')
            . ($transcript ? "\nملخص محتوى المحاضرة: " . mb_substr($transcript, 0, 4000) : '');

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['message_text']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $result = self::chatCompletion($messages, 0.6, false, 800);
        return ['reply' => $result['content'], 'usage' => $result['usage']];
    }
}
