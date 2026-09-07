<?php

class Config
{
    private static $data = null;

    private static function load()
    {
        if (self::$data !== null) {
            return;
        }
        $path = __DIR__ . '/../config.php';
        if (!file_exists($path)) {
            Response::error(
                'ملف الإعدادات config.php غير موجود. انسخ config.example.php إلى config.php ثم عدّل القيم (بيانات قاعدة البيانات وغيرها).',
                500
            );
        }
        self::$data = require $path;
    }

    public static function get($key, $default = null)
    {
        self::load();
        return isset(self::$data[$key]) ? self::$data[$key] : $default;
    }
}
