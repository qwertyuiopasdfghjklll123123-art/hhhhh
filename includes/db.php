<?php
declare(strict_types=1);

/**
 * يعيد اتصال PDO وحيد (Singleton) لكامل الطلب.
 * يستخدم Prepared Statements حصراً في كل أنحاء التطبيق لمنع SQL Injection.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $db = $config['db'] ?? [];

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'] ?? '127.0.0.1',
        $db['port'] ?? '3306',
        $db['name'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        if (function_exists('is_ajax_request') && is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'تعذّر الاتصال بقاعدة البيانات.']);
            exit;
        }
        die('تعذّر الاتصال بقاعدة البيانات. الرجاء مراجعة إعدادات config/config.php أو التواصل مع مسؤول النظام.');
    }

    return $pdo;
}
