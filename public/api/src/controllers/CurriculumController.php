<?php

class CurriculumController
{
    public static function countries($params, $body, $user)
    {
        $rows = Database::all(
            'SELECT id, code, name_ar, name_en, flag_emoji FROM countries WHERE is_active = 1 ORDER BY name_ar'
        );
        Response::json(['countries' => $rows]);
    }

    public static function stages($params, $body, $user)
    {
        $rows = Database::all(
            'SELECT id, name_ar, name_en, education_level, level_order
             FROM stages WHERE country_id = ? AND is_active = 1
             ORDER BY level_order',
            [$params['countryId']]
        );
        Response::json(['stages' => $rows]);
    }

    public static function subjects($params, $body, $user)
    {
        $rows = Database::all(
            'SELECT id, name_ar, name_en, icon, color_hex, order_index
             FROM subjects WHERE stage_id = ? ORDER BY order_index, name_ar',
            [$params['stageId']]
        );
        Response::json(['subjects' => $rows, 'needsGeneration' => count($rows) === 0]);
    }

    public static function subjectDetail($params, $body, $user)
    {
        $subject = Database::one(
            'SELECT id, stage_id, name_ar, name_en, icon, color_hex FROM subjects WHERE id = ?',
            [$params['subjectId']]
        );
        if (!$subject) {
            throw new ApiException(404, 'المادة غير موجودة');
        }
        Response::json(['subject' => $subject]);
    }

    public static function units($params, $body, $user)
    {
        $rows = Database::all(
            'SELECT u.id, u.title_ar, u.description, u.order_index,
                    COUNT(l.id) AS lecture_count
             FROM units u
             LEFT JOIN lectures l ON l.unit_id = u.id
             WHERE u.subject_id = ?
             GROUP BY u.id
             ORDER BY u.order_index',
            [$params['subjectId']]
        );
        foreach ($rows as &$r) {
            $r['lecture_count'] = (int) $r['lecture_count'];
        }
        Response::json(['units' => $rows]);
    }

    public static function unitDetail($params, $body, $user)
    {
        $unit = Database::one(
            'SELECT u.id, u.title_ar, u.description, u.subject_id, s.name_ar AS subject_name
             FROM units u JOIN subjects s ON s.id = u.subject_id WHERE u.id = ?',
            [$params['unitId']]
        );
        if (!$unit) {
            throw new ApiException(404, 'الوحدة غير موجودة');
        }
        Response::json(['unit' => $unit]);
    }

    public static function lectures($params, $body, $user)
    {
        $rows = Database::all(
            'SELECT id, title_ar, description, youtube_video_id, youtube_url, is_link_verified,
                    duration_seconds, thumbnail_url, order_index
             FROM lectures WHERE unit_id = ? ORDER BY order_index',
            [$params['unitId']]
        );
        Response::json(['lectures' => $rows]);
    }

    // محرك المناهج بالذكاء الاصطناعي: يولّد المواد + الوحدات + المحاضرات لمرحلة
    // لا تملك محتوى بعد، ثم يخزّنها في قاعدة البيانات (يُستدعى مرة واحدة فقط
    // لكل مرحلة، وبعدها تُقرأ البيانات من القاعدة مباشرة لتقليل تكلفة الذكاء الاصطناعي)
    public static function generate($params, $body, $user)
    {
        $stageId = $params['stageId'];
        $stage = Database::one(
            'SELECT s.*, c.name_ar AS country_name_ar
             FROM stages s JOIN countries c ON c.id = s.country_id
             WHERE s.id = ?',
            [$stageId]
        );
        if (!$stage) {
            throw new ApiException(404, 'المرحلة الدراسية غير موجودة');
        }

        $existing = Database::all('SELECT id FROM subjects WHERE stage_id = ?', [$stageId]);
        if (count($existing)) {
            throw new ApiException(409, 'تم توليد المنهج لهذه المرحلة مسبقاً');
        }

        Database::run(
            "INSERT INTO ai_generation_jobs (job_type, country_id, stage_id, status, request_payload)
             VALUES ('curriculum', ?, ?, 'processing', ?)",
            [
                $stage['country_id'],
                $stageId,
                json_encode(['stageName' => $stage['name_ar'], 'countryName' => $stage['country_name_ar']], JSON_UNESCAPED_UNICODE),
            ]
        );
        $jobId = Database::lastInsertId();

        try {
            $curriculum = DeepSeekService::generateCurriculum($stage['country_name_ar'], $stage['name_ar']);
        } catch (Exception $e) {
            Database::run(
                "UPDATE ai_generation_jobs SET status='failed', error_message=?, completed_at=NOW() WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $jobId]
            );
            throw $e;
        }

        $pdo = Database::get();
        $subjectCount = 0;
        $unitCount = 0;
        $lectureCount = 0;

        $pdo->beginTransaction();
        try {
            foreach (($curriculum['subjects'] ?? []) as $sIndex => $subject) {
                $pdo->prepare(
                    'INSERT INTO subjects (stage_id, name_ar, icon, order_index, is_ai_generated)
                     VALUES (?, ?, ?, ?, 1)'
                )->execute([$stageId, $subject['name_ar'], $subject['icon'] ?? 'fa-book', $sIndex + 1]);
                $subjectCount++;
                $subjectId = (int) $pdo->lastInsertId();

                foreach (($subject['units'] ?? []) as $uIndex => $unitData) {
                    $pdo->prepare(
                        'INSERT INTO units (subject_id, title_ar, description, order_index, is_ai_generated)
                         VALUES (?, ?, ?, ?, 1)'
                    )->execute([$subjectId, $unitData['title_ar'], $unitData['description'] ?? null, $uIndex + 1]);
                    $unitCount++;
                    $unitId = (int) $pdo->lastInsertId();

                    foreach (($unitData['lectures'] ?? []) as $lIndex => $lecture) {
                        $searchQuery = $lecture['search_query'] ?? $lecture['title_ar'];
                        $found = YoutubeService::searchLectureVideo($searchQuery);
                        $youtubeUrl = $found
                            ? 'https://www.youtube.com/watch?v=' . $found['videoId']
                            : YoutubeService::fallbackSearchUrl($searchQuery);

                        $pdo->prepare(
                            "INSERT INTO lectures
                               (unit_id, title_ar, description, youtube_video_id, youtube_url,
                                is_link_verified, thumbnail_url, source, order_index)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'ai_curated', ?)"
                        )->execute([
                            $unitId,
                            $lecture['title_ar'],
                            $lecture['description'] ?? null,
                            $found ? $found['videoId'] : null,
                            $youtubeUrl,
                            $found ? 1 : 0,
                            $found ? $found['thumbnailUrl'] : null,
                            $lIndex + 1,
                        ]);
                        $lectureCount++;
                    }
                }
            }

            $pdo->prepare(
                "UPDATE ai_generation_jobs SET status='completed', completed_at=NOW(), response_summary=? WHERE id=?"
            )->execute([
                json_encode(compact('subjectCount', 'unitCount', 'lectureCount')),
                $jobId,
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            Database::run(
                "UPDATE ai_generation_jobs SET status='failed', error_message=?, completed_at=NOW() WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $jobId]
            );
            throw $e;
        }

        Response::json([
            'message' => 'تم توليد المنهج بنجاح',
            'subjectCount' => $subjectCount,
            'unitCount' => $unitCount,
            'lectureCount' => $lectureCount,
        ], 201);
    }
}
