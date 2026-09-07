<?php

class SettingsService
{
    // يقرأ إعداد مزوّد الذكاء الاصطناعي من قاعدة البيانات أولاً، ويسقط احتياطياً على config.php
    public static function getActiveConfig($provider = 'deepseek')
    {
        $row = Database::one('SELECT * FROM api_settings WHERE provider = ? LIMIT 1', [$provider]);

        $fallback = Config::get('deepseek', []);
        $fallbackConfig = [
            'apiKey' => $fallback['api_key'] ?? null,
            'baseUrl' => $fallback['base_url'] ?? 'https://api.deepseek.com',
            'model' => $fallback['model'] ?? 'deepseek-chat',
            'source' => 'config',
        ];

        if (!$row) {
            return $fallbackConfig;
        }

        $dbKey = $row['api_key_encrypted'] ? Crypto::decrypt($row['api_key_encrypted']) : null;

        return [
            'apiKey' => $dbKey ?: $fallbackConfig['apiKey'],
            'baseUrl' => $row['api_base_url'] ?: $fallbackConfig['baseUrl'],
            'model' => $row['model_name'] ?: $fallbackConfig['model'],
            'source' => $dbKey ? 'database' : 'config',
        ];
    }

    public static function getMaskedSettings($provider = 'deepseek')
    {
        $config = self::getActiveConfig($provider);
        return [
            'provider' => $provider,
            'apiBaseUrl' => $config['baseUrl'],
            'model' => $config['model'],
            'hasApiKey' => (bool) $config['apiKey'],
            'maskedApiKey' => $config['apiKey'] ? Crypto::maskKey($config['apiKey']) : null,
            'source' => $config['source'],
        ];
    }

    public static function saveSettings($provider, $apiKey, $baseUrl, $model, $userId)
    {
        if (!$apiKey || strlen(trim($apiKey)) < 10) {
            throw new ApiException(400, 'مفتاح API غير صالح');
        }
        $encrypted = Crypto::encrypt(trim($apiKey));

        Database::run(
            'INSERT INTO api_settings (provider, api_key_encrypted, api_base_url, model_name, is_active, updated_by)
             VALUES (?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
               api_key_encrypted = VALUES(api_key_encrypted),
               api_base_url = VALUES(api_base_url),
               model_name = VALUES(model_name),
               updated_by = VALUES(updated_by)',
            [
                $provider,
                $encrypted,
                $baseUrl ?: 'https://api.deepseek.com',
                $model ?: 'deepseek-chat',
                $userId,
            ]
        );

        return self::getMaskedSettings($provider);
    }
}
