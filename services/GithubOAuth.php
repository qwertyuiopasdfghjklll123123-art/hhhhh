<?php
declare(strict_types=1);

/**
 * تدفّق GitHub OAuth (Authorization Code Flow) لربط حساب GitHub بمستخدم
 * لوحة التحكم، بدل إنشاء ولصق Personal Access Token يدوياً.
 *
 * يتطلب تسجيل "OAuth App" من صاحب الموقع على:
 * https://github.com/settings/developers → New OAuth App
 * ثم حفظ Client ID و Client Secret من صفحة "إعدادات النظام" (admin_settings.php).
 *
 * رمز الوصول الناتج (Access Token) يعمل كـ Bearer Token عادي مع GitHub REST
 * API، بنفس آلية Personal Access Token تماماً — GithubClient لا يحتاج أي
 * تعديل ليتعامل معه.
 */
final class GithubOAuth
{
    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL     = 'https://github.com/login/oauth/access_token';
    private const USER_URL      = 'https://api.github.com/user';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(string $clientId, string $clientSecret, string $redirectUri)
    {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri  = $redirectUri;
    }

    public function buildAuthorizeUrl(string $state): string
    {
        $params = [
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope'        => 'repo',
            'state'        => $state,
            'allow_signup' => 'false',
        ];
        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /** يستبدل رمز التفويض (code) القادم من GitHub برمز وصول (access_token) */
    public function exchangeCodeForToken(string $code): array
    {
        $payload = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
        ];

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: PHP-Projects-Dashboard',
                'Expect:',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => 'تعذّر الاتصال بـ GitHub لتبادل رمز الدخول: ' . $error];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return ['success' => false, 'error' => 'رد غير متوقَّع من GitHub (HTTP ' . $status . ').'];
        }
        if (!empty($data['error'])) {
            return ['success' => false, 'error' => $data['error_description'] ?? $data['error']];
        }
        $accessToken = $data['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            return ['success' => false, 'error' => 'لم يُرجع GitHub رمز وصول صالحاً.'];
        }

        return ['success' => true, 'access_token' => $accessToken];
    }

    /** يجلب بيانات المستخدم المرتبط برمز الوصول (للتحقق ولعرض اسم المستخدم) */
    public function getAuthenticatedUser(string $accessToken): array
    {
        $ch = curl_init(self::USER_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: PHP-Projects-Dashboard',
                'Expect:',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => 'تعذّر جلب بيانات حساب GitHub: ' . $error];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['login'])) {
            return ['success' => false, 'error' => 'تعذّر التحقق من حساب GitHub (HTTP ' . $status . ').'];
        }

        return ['success' => true, 'username' => $data['login']];
    }
}
