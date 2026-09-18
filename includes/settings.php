<?php
declare(strict_types=1);

/**
 * إعدادات عامة على مستوى النظام (جدول app_settings بصيغة Key/Value).
 * القيم الحساسة (مثل GitHub OAuth Client Secret) تُشفَّر بواسطة الاستدعاء
 * (لا تفترض هذه الدوال تشفيراً تلقائياً) — انظر get_encrypted_setting/set_encrypted_setting أدناه.
 */

function get_app_setting(string $key): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function set_app_setting(string $key, ?string $value): void
{
    if ($value === null || $value === '') {
        db()->prepare('DELETE FROM app_settings WHERE setting_key = ?')->execute([$key]);
        return;
    }
    db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

function get_encrypted_setting(string $key): ?string
{
    $raw = get_app_setting($key);
    return $raw !== null ? Crypto::decrypt($raw) : null;
}

function set_encrypted_setting(string $key, ?string $plaintext): void
{
    set_app_setting($key, $plaintext !== null && $plaintext !== '' ? Crypto::encrypt($plaintext) : null);
}
