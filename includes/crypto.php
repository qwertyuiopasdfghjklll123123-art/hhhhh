<?php
declare(strict_types=1);

/**
 * تشفير/فك تشفير القيم الحساسة (GitHub Token، NVIDIA API Key) قبل تخزينها
 * في قاعدة البيانات، باستخدام AES-256-GCM (تشفير موثّق Authenticated Encryption).
 * المفتاح يُقرأ من config/config.php (app.key) ولا يُخزَّن أبداً في قاعدة البيانات.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $config = app_config();
        $raw = $config['app']['key'] ?? '';
        if (str_starts_with($raw, 'base64:')) {
            $raw = base64_decode(substr($raw, 7), true) ?: '';
        }
        if (strlen($raw) !== 32) {
            throw new RuntimeException('مفتاح التطبيق (app.key) غير صالح داخل config/config.php.');
        }
        return $raw;
    }

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('فشل تشفير البيانات الحساسة.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain  = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}
