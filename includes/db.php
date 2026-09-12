<?php
function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
        throw new RuntimeException('إعدادات قاعدة البيانات غير موجودة');
    }

    $port = defined('DB_PORT') && DB_PORT ? DB_PORT : 3306;
    $charset = defined('DB_CHARSET') && DB_CHARSET ? DB_CHARSET : 'utf8mb4';
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . $charset;

    $pdo = new PDO($dsn, DB_USER, defined('DB_PASS') ? DB_PASS : '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
