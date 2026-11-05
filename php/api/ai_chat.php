<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai.php';
require_login();

$user = current_user();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$userMessage = trim($data['message'] ?? '');
if ($userMessage === '') {
    json_response(['ok' => false, 'msg' => 'الرسالة فارغة']);
}

$conversationId = (int)($data['conversation_id'] ?? 0);
if ($conversationId) {
    $chk = db()->prepare('SELECT id FROM ai_conversations WHERE id=? AND user_id=?');
    $chk->execute([$conversationId, $user['id']]);
    if (!$chk->fetch()) $conversationId = 0;
}

if (!$conversationId) {
    $title = mb_substr($userMessage, 0, 60);
    db()->prepare('INSERT INTO ai_conversations (user_id, title, created_at, updated_at) VALUES (?,?,?,?)')
        ->execute([$user['id'], $title, time(), time()]);
    $conversationId = (int)db()->lastInsertId();

    // يحتفظ فقط بآخر 15 محادثة لكل مستخدم لتخفيف حجم القاعدة
    $oldStmt = db()->prepare(
        'SELECT id FROM ai_conversations WHERE user_id=? ORDER BY updated_at DESC LIMIT 1000 OFFSET 15'
    );
    $oldStmt->execute([$user['id']]);
    $oldIds = array_column($oldStmt->fetchAll(), 'id');
    if ($oldIds) {
        $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
        db()->prepare("DELETE FROM ai_messages WHERE conversation_id IN ($placeholders)")->execute($oldIds);
        db()->prepare("DELETE FROM ai_conversations WHERE id IN ($placeholders)")->execute($oldIds);
    }
}

db()->prepare('INSERT INTO ai_messages (conversation_id, role, content, created_at) VALUES (?,?,?,?)')
    ->execute([$conversationId, 'user', $userMessage, time()]);
db()->prepare('UPDATE ai_conversations SET updated_at=? WHERE id=?')->execute([time(), $conversationId]);

$siteName = get_setting('site_name', 'المتجر');
$systemPrompt = "أنت \"المساعد الذكي\" داخل موقع \"$siteName\" لبيع خدمات التسويق عبر منصات التواصل الاجتماعي (SMM) — "
    . 'متابعين، لايكات، مشاهدات، وخدمات مشابهة. تساعد المستخدمين في كيفية تقديم الطلبات، أسعار الخدمات، '
    . 'شحن الرصيد وطرق الدفع، متابعة حالة طلباتهم الخاصة، وفتح التذاكر عند وجود مشكلة. '
    . 'أجب بالعربية دائماً، بإيجاز شديد ووضوح، إلا إذا طلب المستخدم تفصيلاً أكبر صراحة. '
    . 'اقتصر حصرياً على مواضيع هذا الموقع. إن سألك عن أي موضوع آخر، اعتذر بلطف بجملة واحدة ووضّح أنك مخصص فقط لمواضيع الموقع. '
    . 'لا تملك أي معلومات عن أي مستخدم آخر غير هذا المستخدم الحالي، ولا تخبره أبداً عن بيانات مستخدمين آخرين.';
$systemPrompt .= ai_account_context($user['id']);
$svcMatches = ai_find_services($userMessage);
$systemPrompt .= ai_services_context($svcMatches);
$systemPrompt .= ai_payment_methods_context();

$histStmt = db()->prepare('SELECT role, content FROM ai_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');
$histStmt->execute([$conversationId]);
$hist = array_reverse($histStmt->fetchAll());

$messages = [['role' => 'system', 'content' => $systemPrompt]];
foreach ($hist as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
}

[$reply, $err] = call_ai_api($messages);
if ($err) {
    json_response(['ok' => false, 'msg' => $err, 'conversation_id' => $conversationId]);
}

$serviceCard = null;
foreach ($svcMatches as $m) {
    if ($m['name'] && mb_strpos($reply, $m['name']) !== false) { $serviceCard = $m; break; }
}
if (!$serviceCard && $svcMatches) $serviceCard = $svcMatches[0];

db()->prepare('INSERT INTO ai_messages (conversation_id, role, content, created_at) VALUES (?,?,?,?)')
    ->execute([$conversationId, 'assistant', $reply, time()]);
db()->prepare('UPDATE ai_conversations SET updated_at=? WHERE id=?')->execute([time(), $conversationId]);

json_response(['ok' => true, 'reply' => $reply, 'conversation_id' => $conversationId, 'service_card' => $serviceCard]);
