<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

$conversationId = (int)($_GET['conversation_id'] ?? 0);
$chk = db()->prepare('SELECT id FROM ai_conversations WHERE id=? AND user_id=?');
$chk->execute([$conversationId, $user['id']]);
if (!$chk->fetch()) {
    json_response(['ok' => false, 'msg' => 'محادثة غير موجودة']);
}

$stmt = db()->prepare('SELECT role, content, created_at FROM ai_messages WHERE conversation_id=? ORDER BY id ASC');
$stmt->execute([$conversationId]);
json_response(['ok' => true, 'messages' => $stmt->fetchAll()]);
