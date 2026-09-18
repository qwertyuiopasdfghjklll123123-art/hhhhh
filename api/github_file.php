<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$projectId = (int) ($_GET['project_id'] ?? 0);
$action    = (string) ($_GET['action'] ?? 'tree');
$path      = (string) ($_GET['path'] ?? '');

if ($projectId <= 0) {
    json_response(['success' => false, 'error' => 'project_id مطلوب'], 422);
}

$stmt = db()->prepare('SELECT * FROM project_context WHERE project_id = ?');
$stmt->execute([$projectId]);
$context = $stmt->fetch();
if (!$context) {
    json_response(['success' => false, 'error' => 'سياق المشروع غير موجود'], 404);
}

$token = resolve_github_token($context, $user);
if (!$token || !$context['github_owner'] || !$context['github_repo']) {
    json_response(['success' => false, 'error' => 'إعدادات GitHub غير مكتملة لهذا المشروع. أضفها من تبويب الإعدادات.'], 422);
}

$gh = new GithubClient($token, $context['github_owner'], $context['github_repo'], $context['github_branch'] ?: 'main');

if ($action === 'get') {
    $res = $gh->getFile($path);
    if (!$res['success']) {
        json_response(['success' => false, 'error' => $res['error']], 502);
    }
    if (strlen($res['content']) > 200000) {
        json_response(['success' => false, 'error' => 'الملف كبير جداً لعرضه هنا (أكثر من 200KB).'], 422);
    }
    json_response(['success' => true, 'content' => $res['content'], 'path' => $res['path'], 'sha' => $res['sha']]);
}

$res = $gh->listDirectory($path);
if (!$res['success']) {
    json_response(['success' => false, 'error' => $res['error']], 502);
}
json_response(['success' => true, 'items' => $res['items'], 'path' => $path]);
