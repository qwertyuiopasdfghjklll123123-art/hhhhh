<?php

// تشفير/فك تشفير مفتاح DeepSeek قبل تخزينه في قاعدة البيانات (AES-256-GCM)
class Crypto
{
    // يشتق مفتاحاً بطول 32 بايت من أي نص يضعه المستخدم في settings_encryption_key
    // (بخلاف نسخة Node التي تطلب hex بطول محدد بالضبط، هنا نقبل أي نص لتسهيل الإعداد)
    private static function key()
    {
        $passphrase = Config::get('settings_encryption_key');
        if (!$passphrase) {
            throw new Exception('settings_encryption_key غير مضبوط في config.php');
        }
        return hash('sha256', $passphrase, true);
    }

    public static function encrypt($plainText)
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt((string) $plainText, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new Exception('فشل تشفير القيمة');
        }
        return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipherText);
    }

    public static function decrypt($payload)
    {
        if (!$payload) {
            return null;
        }
        $parts = explode(':', $payload);
        if (count($parts) !== 3) {
            return null;
        }
        list($ivB64, $tagB64, $dataB64) = $parts;
        $iv = base64_decode($ivB64);
        $tag = base64_decode($tagB64);
        $data = base64_decode($dataB64);
        $result = openssl_decrypt($data, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $result === false ? null : $result;
    }

    // يعرض آخر 4 محارف فقط من المفتاح لعرضه في واجهة الإعدادات
    public static function maskKey($plainKey)
    {
        if (!$plainKey) {
            return null;
        }
        $tail = substr($plainKey, -4);
        return str_repeat('*', max(strlen($plainKey) - 4, 4)) . $tail;
    }
}
