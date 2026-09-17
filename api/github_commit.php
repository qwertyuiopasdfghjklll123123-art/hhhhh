<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['success' => false, 'error' => 'طريقة غير مدعومة'], 405);
}

verify_csrf();
$body = json_body();

$projectId = (int) ($body['project_id'] ?? 0);
$path      = trim((string) ($body['path'] ?? ''));
$content   = (string) ($body['content'] ?? '');
$message   = trim((string) ($body['message'] ?? ''));
$message   = $message !== '' ? $message : 'تحديث عبر لوحة إدارة المشاريع';

if ($projectId <= 0 || $path === '') {
    json_response(['success' => false, 'error' => 'المسار والمشروع مطلوبان.'], 422);
}
if (strlen($content) > 2000000) {
    json_response(['success' => false, 'error' => 'حجم المحتوى كبير جداً.'], 422);
}

$stmt = db()->prepare('SELECT * FROM project_context WHERE project_id = ?');
$stmt->execute([$projectId]);
$context = $stmt->fetch();
if (!$context) {
    json_response(['success' => false, 'error' => 'سياق المشروع غير موجود'], 404);
}

$token = Crypto::decrypt($context['github_token']);
if (!$token || !$context['github_owner'] || !$context['github_repo']) {
    json_response(['success' => false, 'error' => 'إعدادات GitHub غير مكتملة لهذا المشروع.'], 422);
}

$gh = new GithubClient($token, $context['github_owner'], $context['github_repo'], $context['github_branch'] ?: 'main');

// نحدّد sha تلقائياً إن كان الملف موجوداً مسبقاً (تحديث)، وإلا يُنشأ الملف كملف جديد.
$existing = $gh->getFile($path);
$sha = $existing['success'] ? $existing['sha'] : null;

$res = $gh->createOrUpdateFile($path, $content, $message, $sha);
if (!$res['success']) {
    json_response(['success' => false, 'error' => $res['error']], 502);
}

$pStmt = db()->prepare('SELECT name FROM projects WHERE id = ?');
$pStmt->execute([$projectId]);
$projectName = $pStmt->fetchColumn();
log_activity((int) $user['id'], 'github_commit', "Commit على {$path} في مشروع: " . ($projectName ?: $projectId));

json_response([
    'success'     => true,
    'commit_url'  => $res['data']['commit']['html_url'] ?? null,
    'content_url' => $res['data']['content']['html_url'] ?? null,
]);
