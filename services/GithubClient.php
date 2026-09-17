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

    /** يسرد محتويات مجلد (أو جذر المستودع إن ترك المسار فارغاً) */
    public function listDirectory(string $path = ''): array
    {
        $path = ltrim($path, '/');
        $endpoint = "/repos/{$this->owner}/{$this->repo}/contents/" . $this->encodePath($path) . '?ref=' . rawurlencode($this->branch);
        $res = $this->request('GET', $endpoint);
        if (!$res['success']) {
            return $res;
        }
        $raw = $res['data'];
        if (!is_array($raw) || (isset($raw['type']) && $raw['type'] === 'file')) {
            return ['success' => false, 'error' => 'المسار المحدد هو ملف وليس مجلداً.'];
        }
        $items = [];
        foreach ($raw as $item) {
            $items[] = [
                'name' => $item['name'] ?? '',
                'path' => $item['path'] ?? '',
                'type' => $item['type'] ?? 'file',
                'size' => $item['size'] ?? 0,
            ];
        }
        usort($items, static fn ($a, $b) => [$b['type'], $a['name']] <=> [$a['type'], $b['name']]);
        return ['success' => true, 'items' => $items];
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

    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        if (trim($this->token) === '' || $this->owner === '' || $this->repo === '') {
            return ['success' => false, 'error' => 'إعدادات GitHub غير مكتملة لهذا المشروع (Owner / Repo / Token).'];
        }

        $ch = curl_init(self::API_BASE . $endpoint);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: PHP-Projects-Dashboard',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => 'تعذّر الاتصال بـ GitHub API: ' . $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $message = $data['message'] ?? ('HTTP ' . $status);
            return ['success' => false, 'error' => $message, 'status' => $status];
        }

        return ['success' => true, 'data' => $data, 'status' => $status];
    }
}
