<?php

class PointsService
{
    const LECTURE_COMPLETE = 10;
    const QUIZ_CORRECT_ANSWER = 5;
    const QUIZ_PERFECT_BONUS = 20;

    // يمنح نقاطاً لمستخدم ويحدّث الكاش points_total ضمن معاملة واحدة (transaction)
    // كي تبقى لوحة المتصدرين متسقة دائماً مع سجل النقاط
    public static function award($userId, $points, $reason, $referenceType = null, $referenceId = null)
    {
        if (!$points || $points <= 0) {
            return;
        }
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO points_transactions (user_id, points, reason, reference_type, reference_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$userId, $points, $reason, $referenceType, $referenceId]);

            $pdo->prepare('UPDATE users SET points_total = points_total + ? WHERE id = ?')
                ->execute([$points, $userId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function getHistory($userId, $limit = 50)
    {
        $limit = (int) $limit ?: 50;
        return Database::all(
            "SELECT id, points, reason, reference_type, reference_id, created_at
             FROM points_transactions
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT $limit",
            [$userId]
        );
    }
}
