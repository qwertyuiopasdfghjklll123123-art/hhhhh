<?php

class LectureController
{
    public static function detail($params, $body, $user)
    {
        $lecture = Database::one(
            'SELECT l.*, u.title_ar AS unit_title, u.subject_id, sub.name_ar AS subject_name
             FROM lectures l
             JOIN units u ON u.id = l.unit_id
             JOIN subjects sub ON sub.id = u.subject_id
             WHERE l.id = ?',
            [$params['id']]
        );
        if (!$lecture) {
            throw new ApiException(404, 'المحاضرة غير موجودة');
        }

        $progress = null;
        if ($user) {
            $row = Database::one(
                'SELECT watched_seconds, is_completed, completed_at
                 FROM lecture_progress WHERE user_id = ? AND lecture_id = ?',
                [$user['id'], $lecture['id']]
            );
            $progress = $row ?: ['watched_seconds' => 0, 'is_completed' => 0, 'completed_at' => null];
        }

        Database::run('UPDATE lectures SET view_count = view_count + 1 WHERE id = ?', [$lecture['id']]);

        Response::json(['lecture' => $lecture, 'progress' => $progress]);
    }

    public static function updateProgress($params, $body, $user)
    {
        $lectureId = $params['id'];
        $watchedSeconds = (int) ($body['watchedSeconds'] ?? 0);
        $completed = !empty($body['completed']);

        $lecture = Database::one('SELECT id FROM lectures WHERE id = ?', [$lectureId]);
        if (!$lecture) {
            throw new ApiException(404, 'المحاضرة غير موجودة');
        }

        Database::run(
            'INSERT INTO lecture_progress (user_id, lecture_id, watched_seconds, is_completed, completed_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               watched_seconds = GREATEST(watched_seconds, VALUES(watched_seconds)),
               is_completed = is_completed OR VALUES(is_completed),
               completed_at = COALESCE(completed_at, VALUES(completed_at))',
            [
                $user['id'],
                $lectureId,
                $watchedSeconds,
                $completed ? 1 : 0,
                $completed ? date('Y-m-d H:i:s') : null,
            ]
        );

        $pointsAwarded = 0;
        if ($completed) {
            $row = Database::one(
                'SELECT points_awarded FROM lecture_progress WHERE user_id = ? AND lecture_id = ?',
                [$user['id'], $lectureId]
            );
            if ($row && !$row['points_awarded']) {
                PointsService::award($user['id'], PointsService::LECTURE_COMPLETE, 'lecture_complete', 'lecture', $lectureId);
                Database::run(
                    'UPDATE lecture_progress SET points_awarded = 1 WHERE user_id = ? AND lecture_id = ?',
                    [$user['id'], $lectureId]
                );
                $pointsAwarded = PointsService::LECTURE_COMPLETE;
            }
        }

        Response::json(['ok' => true, 'pointsAwarded' => $pointsAwarded]);
    }
}
