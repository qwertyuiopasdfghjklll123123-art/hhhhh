<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

$stmt = db()->prepare(
    "SELECT c.id, c.title, c.updated_at,
     (SELECT content FROM ai_messages WHERE conversation_id=c.id ORDER BY id DESC LIMIT 1) AS last_message
     FROM ai_conversations c WHERE c.user_id=? ORDER BY c.updated_at DESC LIMIT 50"
);
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id' => (int)$r['id'],
        'title' => $r['title'] ?: 'محادثة بدون عنوان',
        'preview' => mb_substr($r['last_message'] ?? '', 0, 80),
        'time' => date('Y-m-d H:i', (int)$r['updated_at']),
    ];
}
json_response(['ok' => true, 'conversations' => $out]);
