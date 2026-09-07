<?php

// توليد والتحقق من جلسات الدخول (JWT مبسّط، HS256) بدون أي مكتبات خارجية
class Auth
{
    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data)
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function sign($userId, $role, $name)
    {
        $secret = Config::get('jwt_secret');
        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $expiresDays = (int) Config::get('jwt_expires_in_days', 7);
        $payload = [
            'sub' => $userId,
            'role' => $role,
            'name' => $name,
            'iat' => time(),
            'exp' => time() + $expiresDays * 86400,
        ];
        $payloadEncoded = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $signature = hash_hmac('sha256', "$header.$payloadEncoded", $secret, true);
        $signatureEncoded = self::base64UrlEncode($signature);
        return "$header.$payloadEncoded.$signatureEncoded";
    }

    private static function verify($token)
    {
        $secret = Config::get('jwt_secret');
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ApiException(401, 'جلسة الدخول غير صالحة أو منتهية');
        }
        list($header, $payload, $signature) = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new ApiException(401, 'جلسة الدخول غير صالحة أو منتهية');
        }
        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!$data || (isset($data['exp']) && $data['exp'] < time())) {
            throw new ApiException(401, 'جلسة الدخول غير صالحة أو منتهية');
        }
        return $data;
    }

    private static function bearerToken()
    {
        $header = null;
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        return trim(substr($header, 7));
    }

    // يفشل بخطأ 401 إن لم يوجد توكن صالح
    public static function requireAuth()
    {
        $token = self::bearerToken();
        if (!$token) {
            throw new ApiException(401, 'يجب تسجيل الدخول أولاً');
        }
        $data = self::verify($token);
        return ['id' => (int) $data['sub'], 'role' => $data['role'], 'name' => $data['name']];
    }

    // لا يفشل إن لم يوجد توكن؛ يُعيد null فقط
    public static function optionalAuth()
    {
        $token = self::bearerToken();
        if (!$token) {
            return null;
        }
        try {
            $data = self::verify($token);
            return ['id' => (int) $data['sub'], 'role' => $data['role'], 'name' => $data['name']];
        } catch (Exception $e) {
            return null;
        }
    }

    public static function requireAdmin($user)
    {
        if (!$user || $user['role'] !== 'admin') {
            throw new ApiException(403, 'هذا الإجراء متاح للمشرفين فقط');
        }
    }
}
