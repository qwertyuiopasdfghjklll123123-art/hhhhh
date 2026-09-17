<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $convId = (int) ($_GET['conversation_id'] ?? 0);
    $stmt = db()->prepare('SELECT id FROM ai_conversations WHERE id = ? AND user_id = ?');
    $stmt->execute([$convId, $user['id']]);
    if (!$stmt->fetch()) {
        json_response(['success' => false, 'error' => 'المحادثة غير موجودة'], 404);
    }
    $msgStmt = db()->prepare('SELECT id, role, content, meta, created_at FROM ai_messages WHERE conversation_id = ? ORDER BY id ASC');
    $msgStmt->execute([$convId]);
    json_response(['success' => true, 'messages' => $msgStmt->fetchAll()]);
}

if ($method !== 'POST') {
    json_response(['success' => false, 'error' => 'طريقة غير مدعومة'], 405);
}

verify_csrf();
$body = json_body();

$projectId   = (int) ($body['project_id'] ?? 0);
$convId      = isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0;
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

$pStmt = db()->prepare('SELECT id, name FROM projects WHERE id = ?');
$pStmt->execute([$projectId]);
$project = $pStmt->fetch();
if (!$project) {
    json_response(['success' => false, 'error' => 'المشروع غير موجود'], 404);
}

$cStmt = db()->prepare('SELECT * FROM project_context WHERE project_id = ?');
$cStmt->execute([$projectId]);
$context = $cStmt->fetch();
if (!$context) {
    json_response(['success' => false, 'error' => 'سياق المشروع غير مهيأ'], 422);
}

$nvidiaKey = Crypto::decrypt($context['nvidia_api_key']);
if (!$nvidiaKey) {
    json_response(['success' => false, 'error' => 'لم يتم ضبط مفتاح NVIDIA NIM API لهذا المشروع بعد. أضفه من تبويب الإعدادات.'], 422);
}

if ($convId > 0) {
    $convStmt = db()->prepare('SELECT id FROM ai_conversations WHERE id = ? AND user_id = ? AND project_id = ?');
    $convStmt->execute([$convId, $user['id'], $projectId]);
    if (!$convStmt->fetch()) {
        json_response(['success' => false, 'error' => 'المحادثة غير موجودة'], 404);
    }
} else {
    $title = truncate($content !== '' ? $content : 'محادثة بالصورة', 60);
    db()->prepare('INSERT INTO ai_conversations (project_id, user_id, title) VALUES (?, ?, ?)')
        ->execute([$projectId, $user['id'], $title !== '' ? $title : 'محادثة جديدة']);
    $convId = (int) db()->lastInsertId();
}

$extraContext = null;
$attachedMeta = null;
if ($attachPath !== '') {
    $githubToken = Crypto::decrypt($context['github_token']);
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

$systemPrompt = NvidiaClient::buildSystemPrompt($context['sql_schema'], $context['system_rules'], $extraContext);

$histStmt = db()->prepare('SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 16');
$histStmt->execute([$convId]);
$history = array_reverse($histStmt->fetchAll());
$priorMessages = array_map(static fn (array $m): array => ['role' => $m['role'], 'content' => $m['content']], $history);

$hasImage = $imageBase64 !== '' && $imageMime !== '';

$userMessageMeta = $attachedMeta;
if ($hasImage) {
    $userMessageMeta = array_merge($userMessageMeta ?? [], ['image' => $imageName ?: 'صورة مرفقة']);
}

$userContentToStore = $content !== '' ? $content : '(صورة بدون نص مرافق)';
db()->prepare('INSERT INTO ai_messages (conversation_id, role, content, meta) VALUES (?, ?, ?, ?)')
    ->execute([$convId, 'user', $userContentToStore, $userMessageMeta ? json_encode($userMessageMeta, JSON_UNESCAPED_UNICODE) : null]);

if ($hasImage) {
    if (!preg_match('/^image\/(png|jpe?g|webp|gif)$/i', $imageMime)) {
        json_response(['success' => false, 'error' => 'صيغة الصورة غير مدعومة.'], 422);
    }
    if (strlen($imageBase64) > 8000000) {
        json_response(['success' => false, 'error' => 'حجم الصورة كبير جداً.'], 422);
    }
    $client = new NvidiaClient($nvidiaKey, $context['nvidia_vision_model'] ?: 'meta/llama-3.2-90b-vision-instruct');
    $result = $client->chatWithImage(
        $systemPrompt,
        $content !== '' ? $content : 'صف هذه الصورة وحلّلها ضمن سياق المشروع.',
        $imageBase64,
        $imageMime,
        $priorMessages
    );
} else {
    $client = new NvidiaClient($nvidiaKey, $context['nvidia_text_model'] ?: 'meta/llama-3.1-70b-instruct');
    $messages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $priorMessages, [['role' => 'user', 'content' => $content]]);
    $result = $client->chat($messages);
}

if (!$result['success']) {
    db()->prepare('INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, ?, ?)')
        ->execute([$convId, 'assistant', 'تعذّر الحصول على رد من المساعد الذكي: ' . $result['error']]);
    json_response(['success' => false, 'error' => $result['error'], 'conversation_id' => $convId], 502);
}

db()->prepare('INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, ?, ?)')
    ->execute([$convId, 'assistant', $result['content']]);

db()->prepare('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);

$titleStmt = db()->prepare('SELECT title FROM ai_conversations WHERE id = ?');
$titleStmt->execute([$convId]);

log_activity((int) $user['id'], 'ai_chat', "استخدام المساعد الذكي في مشروع: {$project['name']}");

json_response([
    'success'         => true,
    'conversation_id' => $convId,
    'title'           => $titleStmt->fetchColumn(),
    'reply'           => $result['content'],
]);
