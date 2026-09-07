<?php

class PointsController
{
    public static function me($params, $body, $user)
    {
        $row = Database::one('SELECT points_total FROM users WHERE id = ?', [$user['id']]);
        $history = PointsService::getHistory($user['id'], 50);
        $badges = Database::all(
            'SELECT b.code, b.name_ar, b.description_ar, b.icon, ub.earned_at
             FROM user_badges ub JOIN badges b ON b.id = ub.badge_id
             WHERE ub.user_id = ? ORDER BY ub.earned_at DESC',
            [$user['id']]
        );
        Response::json([
            'pointsTotal' => $row ? (int) $row['points_total'] : 0,
            'history' => $history,
            'badges' => $badges,
        ]);
    }
}
