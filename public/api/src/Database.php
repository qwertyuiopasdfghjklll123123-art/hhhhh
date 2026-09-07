<?php

class Database
{
    private static $pdo = null;

    public static function get()
    {
        if (self::$pdo === null) {
            $config = Config::get('db');
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['name'],
                $config['charset'] ?? 'utf8mb4'
            );
            try {
                self::$pdo = new PDO($dsn, $config['user'], $config['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                Response::error('تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage(), 500);
            }
        }
        return self::$pdo;
    }

    // تنفيذ استعلام SELECT وإرجاع كل الصفوف
    public static function all($sql, $params = [])
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // تنفيذ استعلام SELECT وإرجاع أول صف فقط (أو null)
    public static function one($sql, $params = [])
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // تنفيذ INSERT/UPDATE/DELETE، يُعيد كائن الـ statement (لاستخدام rowCount عند الحاجة)
    public static function run($sql, $params = [])
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function lastInsertId()
    {
        return (int) self::get()->lastInsertId();
    }
}
