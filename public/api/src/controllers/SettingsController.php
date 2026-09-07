<?php

// كل مسارات الإعدادات محصورة بالمشرف (admin) عبر opts['admin']=true في routes.php
// لأنها تتحكم بمفتاح API الحساس (قسم "إضافة مفتاح DeepSeek")
class SettingsController
{
    public static function get($params, $body, $user)
    {
        Response::json(SettingsService::getMaskedSettings('deepseek'));
    }

    public static function save($params, $body, $user)
    {
        $result = SettingsService::saveSettings(
            'deepseek',
            $body['apiKey'] ?? null,
            $body['baseUrl'] ?? null,
            $body['model'] ?? null,
            $user['id']
        );
        Response::json(array_merge(['message' => 'تم حفظ إعدادات DeepSeek بنجاح'], $result));
    }

    public static function test($params, $body, $user)
    {
        $result = DeepSeekService::chatCompletion(
            [
                ['role' => 'system', 'content' => 'أجب بكلمة واحدة فقط: "متصل".'],
                ['role' => 'user', 'content' => 'اختبار اتصال'],
            ],
            0.6,
            false,
            10
        );
        Response::json(['ok' => true, 'sample' => $result['content']]);
    }
}
