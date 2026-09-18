<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// جولات المساعد المتعددة (استكشاف الملفات ثم الرد) قد تستدعي الذكاء الاصطناعي
// أكثر من مرة تباعاً، وكل استدعاء قد يأخذ وقتاً طويلاً على بعض المزوّدين/الاستضافات
// (لوحظ سابقاً على هذا النوع من الاستضافة المشتركة). نمدّد مهلة تنفيذ PHP قدر
// الإمكان؛ قد تتجاوزها بعض الاستضافات عبر إعداد خادم منفصل لا يمكن التحكم به من هنا.
set_time_limit(280);

const CODE_MAX_ROUNDS = 3;
const CODE_MAX_FILES_PER_ROUND = 3;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['success' => false, 'error' => 'طريقة غير مدعومة'], 405);
}

verify_csrf();
$body = json_body();

$projectId  = (int) ($body['project_id'] ?? 0);
$convId     = isset($body['conversation_id']) ? (int) $body['conversation_id'] : 0;
$providerId = isset($body['provider_id']) ? (int) $body['provider_id'] : 0;
$content    = trim((string) ($body['content'] ?? ''));
$attachPath = trim((string) ($body['attach_path'] ?? ''));

if ($projectId <= 0) {
    json_response(['success' => false, 'error' => 'مشروع غير صالح'], 422);
}
if ($content === '') {
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

$githubToken = resolve_github_token($context, $user);
if (!$githubToken || !$context['github_owner'] || !$context['github_repo']) {
    json_response(['success' => false, 'error' => 'قسم الكود يحتاج حساب GitHub مرتبطاً ومستودعاً محدَّداً لهذا المشروع أولاً. اضبط ذلك من تبويب الإعدادات.'], 422);
}
$gh = new GithubClient($githubToken, $context['github_owner'], $context['github_repo'], $context['github_branch'] ?: 'main');

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
    json_response(['success' => false, 'error' => "تعذّر قراءة مفتاح المزوّد «{$provider['label']}». أعد إدخاله من صفحة مزوّدي الذكاء الاصطناعي."], 422);
}

/* ---------- التحقق من/إنشاء محادثة الكود ---------- */

if ($convId > 0) {
    $convStmt = db()->prepare("SELECT id FROM ai_conversations WHERE id = ? AND user_id = ? AND project_id = ? AND mode = 'code'");
    $convStmt->execute([$convId, $user['id'], $projectId]);
    if (!$convStmt->fetch()) {
        json_response(['success' => false, 'error' => 'المحادثة غير موجودة'], 404);
    }
} else {
    $title = truncate($content, 60);
    db()->prepare("INSERT INTO ai_conversations (project_id, user_id, mode, title, provider_id) VALUES (?, ?, 'code', ?, ?)")
        ->execute([$projectId, $user['id'], $title !== '' ? $title : 'محادثة كود جديدة', $provider['id']]);
    $convId = (int) db()->lastInsertId();
}

/* ---------- تجهيز نظرة عامة عن المستودع + الملف المرفَق يدوياً (إن وُجد) ---------- */

$filesRead = [];
$preReadBlock = '';

if ($attachPath !== '') {
    $attachRes = $gh->getFile($attachPath);
    if ($attachRes['success']) {
        $preReadBlock = "### {$attachPath} (مرفَق من المستخدم)\n```\n" . truncate_file_content($attachRes['content']) . "\n```";
        $filesRead[] = $attachPath;
    }
}

$treeRes = $gh->getTree();
$systemPrompt = code_chat_build_system_prompt(
    (string) $context['github_owner'],
    (string) $context['github_repo'],
    (string) ($context['github_branch'] ?: 'main'),
    $treeRes['success'] ? $treeRes['files'] : null,
    $treeRes['success'] ? $treeRes['truncated'] : false,
    $context['sql_schema'],
    $context['system_rules'],
    project_skills_combined($projectId),
    $preReadBlock
);

/* ---------- سجل المحادثة السابق (أزواج سؤال/جواب نهائية فقط — بلا تفاصيل جولات القراءة) ---------- */

$histStmt = db()->prepare('SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 16');
$histStmt->execute([$convId]);
$history = array_reverse($histStmt->fetchAll());
$priorMessages = array_map(static fn (array $m): array => ['role' => $m['role'], 'content' => $m['content']], $history);

$userMessageMeta = $attachPath !== '' ? ['path' => $attachPath] : null;
db()->prepare('INSERT INTO ai_messages (conversation_id, role, content, meta) VALUES (?, ?, ?, ?)')
    ->execute([$convId, 'user', $content, $userMessageMeta ? json_encode($userMessageMeta, JSON_UNESCAPED_UNICODE) : null]);

$client = new AiClient($providerApiKey, $provider['base_url'], $provider['text_model']);

$messages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    $priorMessages,
    [['role' => 'user', 'content' => $content]]
);

/* ---------- الحلقة المحدودة: رد ← طلب ملفات (اختياري) ← تزويد بالمحتوى ← رد تالٍ ---------- */

$finalReply = null;
$finalReasoning = null;
$roundsUsed = 0;
$totalTokensUsed = 0;

for ($round = 1; $round <= CODE_MAX_ROUNDS; $round++) {
    $result = $client->chat($messages);
    $roundsUsed = $round;
    $totalTokensUsed += (int) ($result['usage']['total_tokens'] ?? 0);

    if (!$result['success']) {
        if ($totalTokensUsed > 0) {
            db()->prepare('UPDATE ai_providers SET tokens_used = tokens_used + ? WHERE id = ?')->execute([$totalTokensUsed, $provider['id']]);
        }
        db()->prepare('INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, ?, ?)')
            ->execute([$convId, 'assistant', "تعذّر الحصول على رد من «{$provider['label']}»: " . $result['error']]);
        json_response(['success' => false, 'error' => $result['error'], 'conversation_id' => $convId], 502);
    }

    $assistantText = $result['content'];
    $requestedPaths = code_chat_parse_read_requests($assistantText, CODE_MAX_FILES_PER_ROUND);

    if (empty($requestedPaths) || $round >= CODE_MAX_ROUNDS) {
        $finalReply = $assistantText;
        $finalReasoning = $result['reasoning'] ?? null;
        break;
    }

    $messages[] = ['role' => 'assistant', 'content' => $assistantText];

    // بعض النماذج قد تطلب ملفاً سبق تزويده لها فعلاً (خصوصاً بعد تكرار الطلب).
    // هذا ليس "لا يوجد طلب" (فلا نعرضه كرد نهائي)، لكنه أيضاً لا يستحق جلباً
    // فعلياً — ننبّه النموذج بدل تكرار نفس الملف، ضمن نفس حد الجولات المتاح.
    $newPaths = array_values(array_diff($requestedPaths, $filesRead));
    if (empty($newPaths)) {
        $messages[] = ['role' => 'user', 'content' => 'جميع الملفات التي طلبتها سبق تزويدك بمحتواها أعلاه في هذه المحادثة. أجب الآن مباشرة بالحل النهائي بناءً على ما تملكه، دون تكرار أي طلب READ_FILE.'];
        continue;
    }

    $fetchedBlock = '';
    foreach ($newPaths as $path) {
        $fileRes = $gh->getFile($path);
        if ($fileRes['success']) {
            $fetchedBlock .= "### {$path}\n```\n" . truncate_file_content($fileRes['content']) . "\n```\n\n";
            $filesRead[] = $path;
        } else {
            $fetchedBlock .= "### {$path}\nتعذّر جلب هذا الملف: {$fileRes['error']}\n\n";
        }
    }

    $isLastRound = ($round + 1 >= CODE_MAX_ROUNDS);
    $instruction = $isLastRound
        ? 'هذه آخر فرصة للاطلاع على ملفات إضافية — لا تطلب أي ملف آخر، وأجب الآن مباشرة بالحل النهائي الكامل بناءً على كل ما اطلعت عليه.'
        : 'أكمل تحليلك بناءً على محتوى الملفات أعلاه. اطلب ملفات إضافية فقط إن كانت ضرورية فعلاً وبنفس صيغة READ_FILE، وإلا أجب مباشرة بالحل النهائي.';

    $messages[] = ['role' => 'user', 'content' => "محتوى الملفات المطلوبة:\n\n" . $fetchedBlock . $instruction];
}

/* ---------- تطبيق طلبات الكتابة التلقائية (WRITE_FILE) - رفع مباشر إلى GitHub ---------- */

$filesWritten = [];
$writeRequests = code_chat_parse_write_requests((string) $finalReply);
foreach ($writeRequests as $w) {
    $existing = $gh->getFile($w['path']);
    $sha = $existing['success'] ? $existing['sha'] : null;
    $commitMsg = 'تحديث تلقائي عبر مساعد الكود: ' . truncate($content, 80);
    $writeRes = $gh->createOrUpdateFile($w['path'], $w['content'], $commitMsg, $sha);

    $confirmLine = $writeRes['success']
        ? '✅ تم رفع **' . $w['path'] . '** إلى GitHub' . (!empty($writeRes['data']['commit']['html_url']) ? ' ([عرض الـ Commit](' . $writeRes['data']['commit']['html_url'] . '))' : '') . '.'
        : '❌ تعذّر رفع **' . $w['path'] . '** إلى GitHub: ' . $writeRes['error'];
    $finalReply = str_replace($w['raw'], $confirmLine, (string) $finalReply);

    $filesWritten[] = [
        'path'       => $w['path'],
        'success'    => $writeRes['success'],
        'commit_url' => $writeRes['data']['commit']['html_url'] ?? null,
        'error'      => $writeRes['success'] ? null : $writeRes['error'],
    ];
}

$assistantMeta = ['provider' => $provider['label'], 'rounds' => $roundsUsed];
if ($finalReasoning) {
    $assistantMeta['reasoning'] = $finalReasoning;
}
if (!empty($filesRead)) {
    $assistantMeta['files_read'] = array_values(array_unique($filesRead));
}
if (!empty($filesWritten)) {
    $assistantMeta['files_written'] = $filesWritten;
}

db()->prepare('INSERT INTO ai_messages (conversation_id, role, content, meta) VALUES (?, ?, ?, ?)')
    ->execute([$convId, 'assistant', $finalReply, json_encode($assistantMeta, JSON_UNESCAPED_UNICODE)]);

if ($totalTokensUsed > 0) {
    db()->prepare('UPDATE ai_providers SET tokens_used = tokens_used + ? WHERE id = ?')->execute([$totalTokensUsed, $provider['id']]);
}

db()->prepare('UPDATE ai_conversations SET updated_at = NOW(), provider_id = ? WHERE id = ?')->execute([$provider['id'], $convId]);

$titleStmt = db()->prepare('SELECT title FROM ai_conversations WHERE id = ?');
$titleStmt->execute([$convId]);

log_activity((int) $user['id'], 'ai_code_chat', "استخدام قسم الكود ({$provider['label']}) في مشروع: {$project['name']}");

json_response([
    'success'         => true,
    'conversation_id' => $convId,
    'title'           => $titleStmt->fetchColumn(),
    'reply'           => $finalReply,
    'reasoning'       => $finalReasoning,
    'provider'        => $provider['label'],
    'files_read'      => array_values(array_unique($filesRead)),
    'files_written'   => $filesWritten,
    'rounds_used'     => $roundsUsed,
]);

/* ---------- دوال مساعدة خاصة بقسم الكود ---------- */

function truncate_file_content(string $content, int $maxChars = 6000): string
{
    if (str_contains($content, "\0")) {
        return '(ملف ثنائي، تعذّر عرضه كنص)';
    }
    if (mb_strlen($content, 'UTF-8') > $maxChars) {
        return mb_substr($content, 0, $maxChars, 'UTF-8') . "\n… (تم اقتطاع الملف، حجمه الأصلي أكبر)";
    }
    return $content;
}

/** يبني قائمة مسارات فريدة (بلا تكرار داخل نفس الرد) من أسطر READ_FILE ضمن رد المساعد */
function code_chat_parse_read_requests(string $assistantText, int $maxPerRound): array
{
    if (!preg_match_all('/READ_FILE:\s*`?([^\s`]+)`?/i', $assistantText, $matches)) {
        return [];
    }
    $paths = [];
    foreach ($matches[1] as $raw) {
        $path = ltrim(trim($raw, " \t\n\r\0\x0B.,;"), '/');
        if ($path === '' || mb_strlen($path) > 400 || in_array($path, $paths, true)) {
            continue;
        }
        $paths[] = $path;
        if (count($paths) >= $maxPerRound) {
            break;
        }
    }
    return $paths;
}

/**
 * يبني قائمة طلبات كتابة ملفات (WRITE_FILE) ضمن الرد النهائي: كل عنصر
 * ['path'=>..,'content'=>..,'raw'=>النص الكامل المطابق] ليُستبدل لاحقاً بسطر
 * تأكيد مختصر بدل عرض صيغة الماركر الخام للمستخدم. حد أقصى 5 ملفات بالرد الواحد.
 */
function code_chat_parse_write_requests(string $assistantText): array
{
    if (!preg_match_all('/WRITE_FILE:\s*`?([^\s`\n]+)`?\s*\n<<<CONTENT\r?\n(.*?)\r?\nCONTENT>>>/s', $assistantText, $matches, PREG_SET_ORDER)) {
        return [];
    }
    $writes = [];
    foreach ($matches as $m) {
        $path = ltrim(trim($m[1], " \t\n\r\0\x0B.,;"), '/');
        if ($path === '' || mb_strlen($path) > 400) {
            continue;
        }
        $writes[] = ['path' => $path, 'content' => $m[2], 'raw' => $m[0]];
        if (count($writes) >= 5) {
            break;
        }
    }
    return $writes;
}

function code_chat_build_system_prompt(
    string $owner,
    string $repo,
    string $branch,
    ?array $treeFiles,
    bool $treeTruncated,
    ?string $sqlSchema,
    ?string $systemRules,
    ?string $skillContent,
    string $preReadBlock
): string {
    $parts = [
        "أنت مساعد برمجي يعمل مثل \"Claude Code\": تستكشف مستودع GitHub التالي وتحل المشاكل أو تنفّذ الطلبات بناءً على محتواه الفعلي بدل التخمين.\n"
        . "المستودع: {$owner}/{$repo} (الفرع: {$branch})\n\n"
        . "عندما تحتاج الاطلاع على محتوى ملف معيّن لفهم المشكلة أو التأكد من التفاصيل قبل الإجابة، اطلبه بكتابة سطر مستقل بالصيغة التالية بالضبط "
        . "(سطر واحد لكل ملف، حتى " . CODE_MAX_FILES_PER_ROUND . " ملفات كحد أقصى دفعة واحدة):\n"
        . "READ_FILE: المسار/الكامل/للملف.امتداد\n\n"
        . 'سيصلك محتوى هذه الملفات في رسالة تالية لتتابع تحليلك (حتى ' . CODE_MAX_ROUNDS . ' جولات استكشاف إجمالاً). '
        . 'لا تطلب ملفاً لا علاقة له بالطلب، ولا ملفاً عُرض عليك محتواه مسبقاً في هذه المحادثة. بمجرد أن تملك سياقاً كافياً، '
        . 'أجب بحل نهائي واضح وقابل للتطبيق مباشرة.'
        . "\n\nهذا المستودع مساحة عمل تعمل عليها مباشرة (تماماً مثل Claude Code): عندما يطلب المستخدم تنفيذ تعديل أو ميزة فعلية "
        . "(وليس مجرد شرح أو مثال توضيحي)، لا تكتفِ بعرض الكود على المستخدم لينسخه يدوياً — ارفعه مباشرة كـ Commit فعلي على "
        . "المستودع بكتابة، لكل ملف تريد إنشاءه أو تعديله، الصيغة التالية بالضبط (حتى 5 ملفات بالرد الواحد، والمحتوى كاملاً "
        . "وليس Diff أو مقتطفاً جزئياً):\n"
        . "WRITE_FILE: المسار/الكامل/للملف.امتداد\n<<<CONTENT\n(محتوى الملف الكامل هنا)\nCONTENT>>>\n\n"
        . 'استخدم WRITE_FILE فقط عندما يريد المستخدم تطبيق تغيير فعلي على المستودع، وليس لعرض كود توضيحي داخل الشرح '
        . '(الكود التوضيحي العادي داخل كتلة ```لغة البرمجة ... ``` كما هو معتاد لا يُرفع تلقائياً).',
    ];

    if ($treeFiles === null) {
        $parts[] = "### قائمة ملفات المستودع\nتعذّر جلب قائمة الملفات تلقائياً؛ اطلب أي ملف تحتاجه مباشرة بصيغة READ_FILE إن كنت تعرف مساره المحتمل.";
    } elseif (empty($treeFiles)) {
        $parts[] = "### قائمة ملفات المستودع\nالمستودع (أو هذا الفرع منه) لا يحتوي أي ملفات حالياً.";
    } else {
        $list = implode("\n", array_map(static fn (array $f): string => '- ' . $f['path'], $treeFiles));
        if ($treeTruncated) {
            $list .= "\n… (تم اقتطاع القائمة، المستودع يحتوي ملفات أكثر من المعروض هنا)";
        }
        $parts[] = "### قائمة ملفات المستودع (نظرة عامة)\n{$list}";
    }

    if ($preReadBlock !== '') {
        $parts[] = "### ملف مرفَق مسبقاً من المستخدم\n{$preReadBlock}";
    }

    if ($skillContent !== null && trim($skillContent) !== '') {
        $parts[] = "### سياق Skill دائم لهذا المشروع\n" . trim($skillContent);
    }

    if ($systemRules !== null && trim($systemRules) !== '') {
        $parts[] = "### قواعد المشروع والتوجيهات البرمجية\n" . trim($systemRules);
    }
    if ($sqlSchema !== null && trim($sqlSchema) !== '') {
        $parts[] = "### هيكل قاعدة البيانات (SQL Schema)\n```sql\n" . trim($sqlSchema) . "\n```";
    }

    return implode("\n\n", $parts);
}
