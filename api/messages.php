<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $convId = (int) ($_GET['conversation_id'] ?? 0);
    $stmt = db()->prepare('SELECT id, provider_id, mode FROM ai_conversations WHERE id = ? AND user_id = ?');
    $stmt->execute([$convId, $user['id']]);
    $conv = $stmt->fetch();
    if (!$conv) {
        json_response(['success' => false, 'error' => 'المحادثة غير موجودة'], 404);
    }
    $msgStmt = db()->prepare('SELECT id, role, content, meta, created_at FROM ai_messages WHERE conversation_id = ? ORDER BY id ASC');
    $msgStmt->execute([$convId]);
    json_response(['success' => true, 'messages' => $msgStmt->fetchAll(), 'conversation' => $conv]);
}

if ($method !== 'POST') {
    json_response(['success' => false, 'error' => 'طريقة غير مدعومة'], 405);
}

verify_csrf();
$body = json_body();

$projectId   = (int) ($body['project_id'] ?? 0);
$convId      = isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0;
$providerId  = isset($body['provider_id']) ? (int) $body['provider_id'] : 0;
$content     = trim((string) ($body['content'] ?? ''));
$attachPath  = trim((string) ($body['attach_path'] ?? ''));
$imageBase64 = (string) ($body['image_base64'] ?? '');
$imageMime   = (string) ($body['image_mime'] ?? '');
$imageName   = trim((string) ($body['image_name'] ?? ''));

if ($projectId <= 0) {
    json_response(['success' => false, 'error' => 'مشروع غير صالح'], 422);
}
if ($content === '' && $imageBase64 === '') {
    json_response(['success' => false, 'error' => 'الرسالة فارغة'], 422);
}
if (mb_strlen($content) > 12000) {
    json_response(['success' => false, 'error' => 'الرسالة طويلة جداً (الحد الأقصى 12000 حرف).'], 422);
}

$project = require_project_access($projectId, $user);

$cStmt = db()->prepare('SELECT * FROM project_context WHERE project_id = ?');
$cStmt->execute([$projectId]);
$context = $cStmt->fetch();
if (!$context) {
    json_response(['success' => false, 'error' => 'سياق المشروع غير مهيأ'], 422);
}

/* ---------- اختيار مزوّد الذكاء الاصطناعي (المحدَّد يدوياً أو الافتراضي) ---------- */

$provider = null;
if ($providerId > 0) {
    $provStmt = db()->prepare('SELECT * FROM ai_providers WHERE id = ?');
    $provStmt->execute([$providerId]);
    $provider = $provStmt->fetch();
}
if (!$provider) {
    $provider = db()->query('SELECT * FROM ai_providers ORDER BY is_default DESC, created_at ASC LIMIT 1')->fetch();
}
if (!$provider) {
    json_response(['success' => false, 'error' => 'لم يتم إضافة أي مزوّد ذكاء اصطناعي بعد. يجب على مسؤول النظام إضافة واحد من صفحة «مزوّدو الذكاء الاصطناعي».'], 422);
}

$providerApiKey = Crypto::decrypt($provider['api_key']);
if (!$providerApiKey) {
    json_response(['success' => false, 'error' => "تعذّر قراءة مفتاح المزوّد «{$provider['label']}». أعد إدخاله من تبويب الإعدادات."], 422);
}

$hasImage = $imageBase64 !== '' && $imageMime !== '';
$visionCaption = null;
$visionProviderLabel = null;

if ($hasImage) {
    if (!preg_match('/^image\/(png|jpe?g|webp|gif)$/i', $imageMime)) {
        json_response(['success' => false, 'error' => 'صيغة الصورة غير مدعومة.'], 422);
    }
    if (strlen($imageBase64) > 8000000) {
        json_response(['success' => false, 'error' => 'حجم الصورة كبير جداً.'], 422);
    }

    if (!$provider['vision_model']) {
        // المزوّد المختار لا يفهم الصور مباشرة: نستخدم أي مزوّد آخر متاح يملك نموذج
        // رؤية (نُفضّل واحداً على NVIDIA NIM بما أنه المزوّد الافتراضي للنظام) ليصفها
        // نصياً أولاً، ثم نمرّر الوصف كنص عادي للمزوّد المختار فعلياً — تعمل الصور
        // بهذا حتى مع مزوّدين نصيين بحتين لا يدعمون الرؤية إطلاقاً.
        $visionProvider = db()->query(
            "SELECT * FROM ai_providers WHERE vision_model IS NOT NULL AND TRIM(vision_model) <> ''
             ORDER BY (base_url LIKE '%nvidia%') DESC, is_default DESC, created_at ASC LIMIT 1"
        )->fetch();

        if ($visionProvider) {
            $visionKey = Crypto::decrypt($visionProvider['api_key']);
            if ($visionKey) {
                $visionClient = new AiClient($visionKey, $visionProvider['base_url'], $visionProvider['vision_model']);
                $capRes = $visionClient->chatWithImage(
                    'أنت نظام وصف صور دقيق. صف الصورة التالية بتفصيل موضوعي (العناصر، أي نص ظاهر فيها، الألوان، السياق العام) باللغة العربية مباشرة دون أي مقدمات.',
                    'صف هذه الصورة بدقة.',
                    $imageBase64,
                    $imageMime
                );
                if ($capRes['success'] && trim((string) $capRes['content']) !== '') {
                    $visionCaption = trim($capRes['content']);
                    $visionProviderLabel = $visionProvider['label'];
                    $capTokens = (int) ($capRes['usage']['total_tokens'] ?? 0);
                    if ($capTokens > 0) {
                        db()->prepare('UPDATE ai_providers SET tokens_used = tokens_used + ? WHERE id = ?')
                            ->execute([$capTokens, $visionProvider['id']]);
                    }
                }
            }
        }

        if ($visionCaption === null) {
            json_response(['success' => false, 'error' => "المزوّد «{$provider['label']}» لا يملك نموذج رؤية، ولا يوجد مزوّد آخر بنموذج رؤية مُعرَّف لوصف الصورة تلقائياً. أضف نموذج رؤية لأي مزوّد من صفحة مزوّدي الذكاء الاصطناعي."], 422);
        }
    }
}

/* ---------- التحقق من/إنشاء المحادثة ---------- */

if ($convId > 0) {
    $convStmt = db()->prepare("SELECT id FROM ai_conversations WHERE id = ? AND user_id = ? AND project_id = ? AND mode = 'chat'");
    $convStmt->execute([$convId, $user['id'], $projectId]);
    if (!$convStmt->fetch()) {
        json_response(['success' => false, 'error' => 'المحادثة غير موجودة'], 404);
    }
} else {
    $title = truncate($content !== '' ? $content : 'محادثة بالصورة', 60);
    db()->prepare("INSERT INTO ai_conversations (project_id, user_id, mode, title, provider_id) VALUES (?, ?, 'chat', ?, ?)")
        ->execute([$projectId, $user['id'], $title !== '' ? $title : 'محادثة جديدة', $provider['id']]);
    $convId = (int) db()->lastInsertId();
}

/* ---------- ملف GitHub مرفق (اختياري) - يُجلب من السيرفر مباشرة لضمان صحته ---------- */

$extraContext = null;
$attachedMeta = null;
if ($attachPath !== '') {
    $githubToken = resolve_github_token($context, $user);
    if ($githubToken && $context['github_owner'] && $context['github_repo']) {
        $gh = new GithubClient($githubToken, $context['github_owner'], $context['github_repo'], $context['github_branch'] ?: 'main');
        $fileRes = $gh->getFile($attachPath);
        if ($fileRes['success']) {
            $extraContext = "ملف مرفق: {$attachPath}\n```\n" . $fileRes['content'] . "\n```";
            $attachedMeta = ['path' => $attachPath];
        } else {
            $extraContext = "تعذّر جلب الملف المرفق ({$attachPath}): " . $fileRes['error'];
        }
    }
}

$systemPrompt = AiClient::buildSystemPrompt($context['sql_schema'], $context['system_rules'], project_skills_combined($projectId), $extraContext);

$histStmt = db()->prepare('SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 16');
$histStmt->execute([$convId]);
$history = array_reverse($histStmt->fetchAll());
$priorMessages = array_map(static fn (array $m): array => ['role' => $m['role'], 'content' => $m['content']], $history);

$userMessageMeta = $attachedMeta;
if ($hasImage) {
    $userMessageMeta = array_merge($userMessageMeta ?? [], ['image' => $imageName ?: 'صورة مرفقة']);
    if ($visionProviderLabel !== null) {
        $userMessageMeta['vision_relay'] = $visionProviderLabel;
    }
}

$userContentToStore = $content !== '' ? $content : '(صورة بدون نص مرافق)';
db()->prepare('INSERT INTO ai_messages (conversation_id, role, content, meta) VALUES (?, ?, ?, ?)')
    ->execute([$convId, 'user', $userContentToStore, $userMessageMeta ? json_encode($userMessageMeta, JSON_UNESCAPED_UNICODE) : null]);

/* ---------- استدعاء مزوّد الذكاء الاصطناعي المحدَّد، مع بثّ الرد تدريجياً (SSE) ---------- */

if ($hasImage && $provider['vision_model']) {
    // الصورة تذهب مباشرة لنموذج رؤية تابع للمزوّد المختار فعلياً (لا حاجة لوسيط)
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($priorMessages as $m) {
        $messages[] = $m;
    }
    $messages[] = [
        'role'    => 'user',
        'content' => [
            ['type' => 'text', 'text' => $content !== '' ? $content : 'صف هذه الصورة وحلّلها ضمن سياق المشروع.'],
            ['type' => 'image_url', 'image_url' => ['url' => "data:{$imageMime};base64,{$imageBase64}"]],
        ],
    ];
    $client = new AiClient($providerApiKey, $provider['base_url'], $provider['vision_model']);
} else {
    // إمّا بلا صورة، أو صورة وُصفت مسبقاً عبر مزوّد رؤية وسيط ($visionCaption) لأن
    // المزوّد المختار فعلياً نصّي بحت لا يدعم الرؤية.
    $finalContent = $content;
    if ($visionCaption !== null) {
        $imageNote = "[وصف صورة مرفقة، حُلِّلت تلقائياً بواسطة {$visionProviderLabel}]:\n" . $visionCaption;
        $finalContent = $content !== '' ? ($content . "\n\n" . $imageNote) : $imageNote;
    }
    $messages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $priorMessages, [['role' => 'user', 'content' => $finalContent]]);
    $client = new AiClient($providerApiKey, $provider['base_url'], $provider['text_model']);
}

// قد يستغرق البث وقتاً على بعض المزوّدين/الاستضافات؛ نمدّد مهلة تنفيذ PHP قدر
// الإمكان (قد تتجاوزها بعض الاستضافات عبر إعداد خادم منفصل لا يمكن التحكم به من هنا).
set_time_limit(150);

while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$sendEvent = static function (array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
};

$onDelta = static function (string $kind, string $text) use ($sendEvent): void {
    $sendEvent(['type' => 'delta', 'kind' => $kind, 'text' => $text]);
};

$result = $client->chatStream($messages, $onDelta);

if (!$result['success']) {
    db()->prepare('INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, ?, ?)')
        ->execute([$convId, 'assistant', "تعذّر الحصول على رد من «{$provider['label']}»: " . $result['error']]);
    $sendEvent(['type' => 'error', 'error' => $result['error'], 'conversation_id' => $convId]);
    exit;
}

$assistantMeta = ['provider' => $provider['label']];
if (!empty($result['reasoning'])) {
    $assistantMeta['reasoning'] = $result['reasoning'];
}

db()->prepare('INSERT INTO ai_messages (conversation_id, role, content, meta) VALUES (?, ?, ?, ?)')
    ->execute([$convId, 'assistant', $result['content'], json_encode($assistantMeta, JSON_UNESCAPED_UNICODE)]);

$tokensUsed = (int) ($result['usage']['total_tokens'] ?? 0);
if ($tokensUsed > 0) {
    db()->prepare('UPDATE ai_providers SET tokens_used = tokens_used + ? WHERE id = ?')->execute([$tokensUsed, $provider['id']]);
}

db()->prepare('UPDATE ai_conversations SET updated_at = NOW(), provider_id = ? WHERE id = ?')->execute([$provider['id'], $convId]);

$titleStmt = db()->prepare('SELECT title FROM ai_conversations WHERE id = ?');
$titleStmt->execute([$convId]);

log_activity((int) $user['id'], 'ai_chat', "استخدام المساعد الذكي ({$provider['label']}) في مشروع: {$project['name']}");

$sendEvent([
    'type'            => 'done',
    'success'         => true,
    'conversation_id' => $convId,
    'title'           => $titleStmt->fetchColumn(),
    'reply'           => $result['content'],
    'reasoning'       => $result['reasoning'] ?? null,
    'provider'        => $provider['label'],
]);
