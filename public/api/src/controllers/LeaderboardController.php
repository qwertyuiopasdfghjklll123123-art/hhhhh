<?php

class LeaderboardController
{
    public static function index($params, $body, $user)
    {
        $scope = ($_GET['scope'] ?? '') === 'country' ? 'country' : 'global';
        $limit = min((int) ($_GET['limit'] ?? 50) ?: 50, 100);
        $countryId = !empty($_GET['countryId']) ? (int) $_GET['countryId'] : null;

        if ($scope === 'country' && !$countryId && $user) {
            $u = Database::one('SELECT country_id FROM users WHERE id = ?', [$user['id']]);
            $countryId = $u ? $u['country_id'] : null;
        }

        if ($scope === 'country' && $countryId) {
            $rows = Database::all(
                "SELECT user_id, name, avatar_url, points_total, country_name_ar, country_flag, rank_in_country AS `rank`
                 FROM leaderboard_view WHERE country_id = ?
                 ORDER BY points_total DESC LIMIT $limit",
                [$countryId]
            );
        } else {
            $rows = Database::all(
                "SELECT user_id, name, avatar_url, points_total, country_name_ar, country_flag, rank_global AS `rank`
                 FROM leaderboard_view ORDER BY points_total DESC LIMIT $limit"
            );
        }

        $me = null;
        if ($user) {
            $me = Database::one(
                'SELECT user_id, name, avatar_url, points_total, country_name_ar, country_flag,
                        rank_global, rank_in_country
                 FROM leaderboard_view WHERE user_id = ?',
                [$user['id']]
            );
        }

        Response::json(['scope' => $scope, 'countryId' => $countryId, 'leaderboard' => $rows, 'me' => $me]);
    }
}
