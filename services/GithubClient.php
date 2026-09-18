<?php
declare(strict_types=1);

/**
 * عميل مبسّط لـ GitHub REST API — جلب ملفات المشروع وإنشاء/تحديث الملفات (Commits).
 * يعتمد Contents API: https://docs.github.com/en/rest/repos/contents
 *
 * ملاحظة أمنية: يُستخدم من طرف السيرفر فقط. الـ Personal Access Token يُخزَّن
 * مشفَّراً (Crypto) في قاعدة البيانات ولا يُرسل مطلقاً إلى متصفح المستخدم.
 */
final class GithubClient
{
    private const API_BASE = 'https://api.github.com';

    private string $token;
    private string $owner;
    private string $repo;
    private string $branch;

    public function __construct(string $token, string $owner, string $repo, string $branch = 'main')
    {
        $this->token  = $token;
        $this->owner  = $owner;
        $this->repo   = $repo;
        $this->branch = $branch !== '' ? $branch : 'main';
    }

    public function getRepo(): array
    {
        return $this->request('GET', "/repos/{$this->owner}/{$this->repo}");
    }

    /**
     * يسرد مستودعات المستخدم صاحب رمز الوصول (لا يحتاج owner/repo محدَّدين
     * مسبقاً — يُستخدم لبناء قائمة اختيار المستودع بدل كتابة الاسم يدوياً).
     */
    public function listUserRepos(int $perPage = 100): array
    {
        $perPage = max(1, min(100, $perPage));
        $res = $this->request('GET', "/user/repos?sort=updated&per_page={$perPage}&affiliation=owner,collaborator,organization_member", null, true);
        if (!$res['success']) {
            return $res;
        }
        $items = [];
        foreach ((array) $res['data'] as $repo) {
            if (!is_array($repo)) {
                continue;
            }
            $items[] = [
                'full_name'      => (string) ($repo['full_name'] ?? ''),
                'owner'          => (string) ($repo['owner']['login'] ?? ''),
                'name'           => (string) ($repo['name'] ?? ''),
                'private'        => (bool) ($repo['private'] ?? false),
                'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
            ];
        }
        return ['success' => true, 'repos' => $items];
    }

    /** يجلب محتوى ملف نصي واحد. يعيد ['success','content','sha','path'] */
    public function getFile(string $path): array
    {
        $path = ltrim($path, '/');
        $res = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/contents/" . $this->encodePath($path) . '?ref=' . rawurlencode($this->branch));
        if (!$res['success']) {
            return $res;
        }
        $data = $res['data'];
        if (($data['type'] ?? '') !== 'file' || !isset($data['content'])) {
            return ['success' => false, 'error' => 'المسار المحدد ليس ملفاً قابلاً للقراءة (قد يكون مجلداً أو غير موجود).'];
        }
        $content = base64_decode(str_replace("\n", '', $data['content']));
        return [
            'success' => true,
            'content' => $content === false ? '' : $content,
            'sha'     => $data['sha'],
            'path'    => $data['path'],
            'size'    => $data['size'] ?? strlen((string) $content),
        ];
    }

    /**
     * يجلب قائمة مسطّحة (بلا مجلدات) بكل ملفات المستودع دفعة واحدة عبر Git Trees API
     * (بدل التصفح التكراري مجلداً تلو الآخر)، لبناء لمحة عامة عن بنية المستودع تُحقن
     * ضمن موجّه النظام لقسم "الكود". محدودة بعدد أقصى من الملفات لتفادي تضخّم
     * الموجّه في المستودعات الضخمة.
     * يعيد ['success','files' => [['path'=>string,'size'=>int], ...],'truncated' => bool]
     */
    public function getTree(int $maxEntries = 500): array
    {
        $res = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/git/trees/" . rawurlencode($this->branch) . '?recursive=1');
        if (!$res['success']) {
            return $res;
        }

        $data = $res['data'];
        $rawTree = is_array($data['tree'] ?? null) ? $data['tree'] : [];
        $blobs = array_values(array_filter($rawTree, static fn ($entry): bool => ($entry['type'] ?? '') === 'blob'));

        $files = [];
        foreach ($blobs as $entry) {
            if (count($files) >= $maxEntries) {
                break;
            }
            $files[] = [
                'path' => (string) ($entry['path'] ?? ''),
                'size' => (int) ($entry['size'] ?? 0),
            ];
        }

        return [
            'success'   => true,
            'files'     => $files,
            'truncated' => !empty($data['truncated']) || count($blobs) > count($files),
        ];
    }

    /** ينشئ ملفاً جديداً أو يحدّث ملفاً موجوداً (Commit مباشر على الفرع المحدد) */
    public function createOrUpdateFile(string $path, string $content, string $commitMessage, ?string $sha = null): array
    {
        $path = ltrim($path, '/');
        $body = [
            'message' => $commitMessage !== '' ? $commitMessage : 'تحديث عبر لوحة إدارة المشاريع',
            'content' => base64_encode($content),
            'branch'  => $this->branch,
        ];
        if ($sha) {
            $body['sha'] = $sha;
        }
        return $this->request('PUT', "/repos/{$this->owner}/{$this->repo}/contents/" . $this->encodePath($path), $body);
    }

    private function encodePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function request(string $method, string $endpoint, ?array $body = null, bool $skipRepoCheck = false): array
    {
        if (trim($this->token) === '' || (!$skipRepoCheck && ($this->owner === '' || $this->repo === ''))) {
            return ['success' => false, 'error' => 'إعدادات GitHub غير مكتملة لهذا المشروع (Owner / Repo / Token).'];
        }

        // إعادة محاولة واحدة فقط عند فشل على مستوى النقل (مهلة/DNS/اتصال) دون أي
        // رد فعلي من GitHub؛ إن وصل رد HTTP فعلي (نجاحاً أو خطأً) لا نعيد المحاولة.
        $maxAttempts = 2;
        $transportError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init(self::API_BASE . $endpoint);
            $headers = [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: PHP-Projects-Dashboard',
                // تعطيل انتظار "100 Continue" - راجع الملاحظة في AiClient::send() لنفس السبب.
                'Expect:',
            ];
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);

            if ($response === false) {
                $transportError = curl_error($ch);
                curl_close($ch);
                if ($attempt < $maxAttempts) {
                    usleep(500000);
                    continue;
                }
                return [
                    'success' => false,
                    'error'   => 'تعذّر الاتصال بـ GitHub API بعد ' . $maxAttempts . ' محاولات: ' . $transportError,
                ];
            }

            $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            $data = json_decode($response, true);

            if ($status < 200 || $status >= 300) {
                $message = $data['message'] ?? null;
                if ($message === null) {
                    $bodySnippet = trim(mb_substr((string) $response, 0, 200));
                    $message = 'HTTP ' . $status . ' من ' . $effectiveUrl . ($bodySnippet !== '' ? ' — ' . $bodySnippet : ' (رد فارغ)');
                }
                // "Not Found" من GitHub وحدها لا تُفسَّر بسهولة؛ نضيف السبب الأرجح
                // حسب رمز الحالة بدل ترك المستخدم بلا أي دليل لحل المشكلة.
                if ($status === 404 && !$skipRepoCheck) {
                    $message = "لم يُعثر على المستودع {$this->owner}/{$this->repo} (أو المسار المطلوب فيه) على الفرع «{$this->branch}». "
                        . 'تحقق من صحة اسم المستودع/المالك والفرع، ومن أن حساب GitHub المرتبط يملك صلاحية الوصول إليه.';
                } elseif ($status === 401) {
                    $message = 'رمز الوصول إلى GitHub غير صالح أو منتهي. أعد ربط حساب GitHub من صفحة "حسابي".';
                } elseif ($status === 403) {
                    $message = 'GitHub رفض الطلب (403): إمّا صلاحيات حساب GitHub المرتبط لا تكفي للوصول لهذا المستودع، أو تم تجاوز حد الطلبات المسموح — حاول لاحقاً. التفاصيل: ' . $message;
                }
                return ['success' => false, 'error' => $message, 'status' => $status];
            }

            return ['success' => true, 'data' => $data, 'status' => $status];
        }

        return ['success' => false, 'error' => 'تعذّر الاتصال بـ GitHub API: ' . $transportError];
    }
}
