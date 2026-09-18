<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// يسرد مستودعات حساب GitHub المرتبط بالمستخدم الحالي (بلا حاجة لسياق مشروع)
// لتعبئة قائمة اختيار المستودع بدل كتابة Owner/Repo يدوياً.

$token = !empty($user['github_oauth_token']) ? Crypto::decrypt($user['github_oauth_token']) : null;
if (!$token) {
    json_response(['success' => false, 'error' => 'اربط حساب GitHub أولاً من صفحة "حسابي" لعرض مستودعاتك.'], 422);
}

$gh = new GithubClient($token, '', '');
$res = $gh->listUserRepos();
if (!$res['success']) {
    json_response(['success' => false, 'error' => $res['error']], 502);
}

json_response(['success' => true, 'repos' => $res['repos']]);
