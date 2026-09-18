<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if ($projectId <= 0) {
        json_response(['success' => false, 'error' => 'project_id مطلوب'], 422);
    }
    $mode = ($_GET['mode'] ?? 'chat') === 'code' ? 'code' : 'chat';
    $stmt = db()->prepare('SELECT id, title, updated_at FROM ai_conversations WHERE project_id = ? AND user_id = ? AND mode = ? ORDER BY updated_at DESC');
    $stmt->execute([$projectId, $user['id'], $mode]);
    json_response(['success' => true, 'conversations' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    verify_csrf();
    $body   = json_body();
    $action = (string) ($body['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($body['conversation_id'] ?? 0);
        db()->prepare('DELETE FROM ai_conversations WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
        json_response(['success' => true]);
    }

    if ($action === 'rename') {
        $id    = (int) ($body['conversation_id'] ?? 0);
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            json_response(['success' => false, 'error' => 'العنوان مطلوب'], 422);
        }
        db()->prepare('UPDATE ai_conversations SET title = ? WHERE id = ? AND user_id = ?')
            ->execute([truncate($title, 100), $id, $user['id']]);
        json_response(['success' => true]);
    }

    json_response(['success' => false, 'error' => 'إجراء غير معروف'], 422);
}

json_response(['success' => false, 'error' => 'طريقة غير مدعومة'], 405);
