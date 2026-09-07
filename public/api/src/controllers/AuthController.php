<?php

class AuthController
{
    private static function publicUser($u)
    {
        return [
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'countryId' => $u['country_id'] !== null ? (int) $u['country_id'] : null,
            'stageId' => $u['stage_id'] !== null ? (int) $u['stage_id'] : null,
            'avatarUrl' => $u['avatar_url'],
            'pointsTotal' => (int) $u['points_total'],
        ];
    }

    public static function register($params, $body, $user)
    {
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if (!$name || !$email || strlen($password) < 6) {
            throw new ApiException(400, 'الاسم والبريد الإلكتروني مطلوبان، وكلمة المرور 6 محارف على الأقل');
        }

        $existing = Database::one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing) {
            throw new ApiException(409, 'هذا البريد الإلكتروني مسجّل مسبقاً');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        Database::run(
            "INSERT INTO users (name, email, password_hash, role, country_id, stage_id)
             VALUES (?, ?, ?, 'student', ?, ?)",
            [$name, $email, $passwordHash, $body['countryId'] ?? null, $body['stageId'] ?? null]
        );
        $newId = Database::lastInsertId();

        $newUser = Database::one('SELECT * FROM users WHERE id = ?', [$newId]);
        $token = Auth::sign($newUser['id'], $newUser['role'], $newUser['name']);
        Response::json(['token' => $token, 'user' => self::publicUser($newUser)], 201);
    }

    public static function login($params, $body, $user)
    {
        $email = trim($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if (!$email || !$password) {
            throw new ApiException(400, 'البريد الإلكتروني وكلمة المرور مطلوبان');
        }

        $found = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$found || !$found['is_active']) {
            throw new ApiException(401, 'بيانات الدخول غير صحيحة');
        }
        if (!password_verify($password, $found['password_hash'])) {
            throw new ApiException(401, 'بيانات الدخول غير صحيحة');
        }

        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$found['id']]);

        $token = Auth::sign($found['id'], $found['role'], $found['name']);
        Response::json(['token' => $token, 'user' => self::publicUser($found)]);
    }

    public static function me($params, $body, $user)
    {
        $found = Database::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        if (!$found) {
            throw new ApiException(404, 'المستخدم غير موجود');
        }
        Response::json(['user' => self::publicUser($found)]);
    }
}
