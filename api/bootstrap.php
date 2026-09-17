<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * حراسة مصادقة مخصصة لنقاط AJAX: تعيد JSON 401 بدل إعادة التوجيه (redirect)
 * التي تكسر استجابات fetch() المتوقَّعة كـ JSON في واجهة الدردشة.
 */
$user = current_user();
if (!$user) {
    json_response(['success' => false, 'error' => 'يجب تسجيل الدخول أولاً.'], 401);
}
$_SESSION['last_activity'] = time();
