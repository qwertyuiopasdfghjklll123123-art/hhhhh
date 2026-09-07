<?php

class QuizController
{
    // يُحمّل الاختبار مع أسئلته وخياراته. includeAnswers=false يُخفي is_correct
    // (يُستخدم هذا قبل التصحيح كي لا تُسرَّب الإجابة الصحيحة للمتصفح)
    private static function loadQuizWithQuestions($quizId, $includeAnswers = false)
    {
        $quiz = Database::one('SELECT * FROM quizzes WHERE id = ?', [$quizId]);
        if (!$quiz) {
            return null;
        }

        $questions = Database::all(
            'SELECT id, question_text, explanation, points_value, order_index
             FROM quiz_questions WHERE quiz_id = ? ORDER BY order_index',
            [$quizId]
        );

        $optionCols = $includeAnswers
            ? 'id, question_id, option_text, is_correct, order_index'
            : 'id, question_id, option_text, order_index';

        foreach ($questions as &$q) {
            $q['options'] = Database::all(
                "SELECT $optionCols FROM quiz_options WHERE question_id = ? ORDER BY order_index",
                [$q['id']]
            );
        }
        unset($q);

        return ['quiz' => $quiz, 'questions' => $questions];
    }

    // GET /lectures/{lectureId}/quiz — يُعيد اختباراً موجوداً، أو يولّد واحداً جديداً
    // بالذكاء الاصطناعي أول مرة ويخزّنه
    public static function getQuiz($params, $body, $user)
    {
        $lectureId = $params['lectureId'];
        $lecture = Database::one('SELECT * FROM lectures WHERE id = ?', [$lectureId]);
        if (!$lecture) {
            throw new ApiException(404, 'المحاضرة غير موجودة');
        }

        $existingQuiz = Database::one('SELECT id FROM quizzes WHERE lecture_id = ? LIMIT 1', [$lectureId]);

        if (!$existingQuiz) {
            $generated = DeepSeekService::generateQuiz(
                $lecture['title_ar'],
                $lecture['description'],
                $lecture['transcript_text']
            );

            $pdo = Database::get();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO quizzes (lecture_id, title, generated_by) VALUES (?, ?, 'ai')")
                    ->execute([$lectureId, 'اختبار: ' . $lecture['title_ar']]);
                $quizId = (int) $pdo->lastInsertId();

                foreach (($generated['questions'] ?? []) as $qIndex => $question) {
                    $pdo->prepare(
                        'INSERT INTO quiz_questions (quiz_id, question_text, explanation, order_index)
                         VALUES (?, ?, ?, ?)'
                    )->execute([$quizId, $question['question_text'], $question['explanation'] ?? null, $qIndex + 1]);
                    $questionId = (int) $pdo->lastInsertId();

                    foreach (($question['options'] ?? []) as $oIndex => $option) {
                        $pdo->prepare(
                            'INSERT INTO quiz_options (question_id, option_text, is_correct, order_index)
                             VALUES (?, ?, ?, ?)'
                        )->execute([$questionId, $option['text'], !empty($option['is_correct']) ? 1 : 0, $oIndex + 1]);
                    }
                }

                $pdo->commit();
                $existingQuiz = ['id' => $quizId];
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $data = self::loadQuizWithQuestions($existingQuiz['id'], false);
        Response::json($data);
    }

    // POST /quizzes/{id}/submit  { answers: [{ questionId, selectedOptionId }] }
    public static function submit($params, $body, $user)
    {
        $quizId = $params['id'];
        $answers = $body['answers'] ?? null;
        if (!is_array($answers) || count($answers) === 0) {
            throw new ApiException(400, 'يجب إرسال إجابات الأسئلة');
        }

        $data = self::loadQuizWithQuestions($quizId, true);
        if (!$data) {
            throw new ApiException(404, 'الاختبار غير موجود');
        }
        $questions = $data['questions'];
        $questionMap = [];
        foreach ($questions as $q) {
            $questionMap[$q['id']] = $q;
        }

        $correctCount = 0;
        $score = 0;
        $results = [];

        foreach ($answers as $answer) {
            $qid = (int) ($answer['questionId'] ?? 0);
            if (!isset($questionMap[$qid])) {
                continue;
            }
            $question = $questionMap[$qid];
            $correctOption = null;
            foreach ($question['options'] as $opt) {
                if ($opt['is_correct']) {
                    $correctOption = $opt;
                    break;
                }
            }
            $selectedOptionId = isset($answer['selectedOptionId']) ? (int) $answer['selectedOptionId'] : null;
            $isCorrect = $correctOption && $selectedOptionId === (int) $correctOption['id'];
            if ($isCorrect) {
                $correctCount++;
                $score += (int) $question['points_value'];
            }
            $results[] = [
                'questionId' => (int) $question['id'],
                'selectedOptionId' => $selectedOptionId,
                'correctOptionId' => $correctOption ? (int) $correctOption['id'] : null,
                'isCorrect' => (bool) $isCorrect,
                'explanation' => $question['explanation'],
            ];
        }

        $pdo = Database::get();
        $startedAt = !empty($body['startedAt']) ? date('Y-m-d H:i:s', strtotime($body['startedAt'])) : date('Y-m-d H:i:s');
        $durationSeconds = !empty($body['startedAt'])
            ? max(0, time() - strtotime($body['startedAt']))
            : null;

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO quiz_attempts
                   (user_id, quiz_id, score, total_questions, correct_count, started_at, submitted_at, duration_seconds)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)'
            )->execute([
                $user['id'], $quizId, $score, count($questions), $correctCount, $startedAt, $durationSeconds,
            ]);
            $attemptId = (int) $pdo->lastInsertId();

            foreach ($results as $r) {
                $pdo->prepare(
                    'INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_option_id, is_correct)
                     VALUES (?, ?, ?, ?)'
                )->execute([$attemptId, $r['questionId'], $r['selectedOptionId'], $r['isCorrect'] ? 1 : 0]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        $earnedPoints = $correctCount * PointsService::QUIZ_CORRECT_ANSWER;
        if ($earnedPoints > 0) {
            PointsService::award($user['id'], $earnedPoints, 'quiz_correct_answer', 'quiz_attempt', $attemptId);
        }
        $bonusAwarded = 0;
        if ($correctCount === count($questions)) {
            $bonusAwarded = PointsService::QUIZ_PERFECT_BONUS;
            PointsService::award($user['id'], $bonusAwarded, 'quiz_perfect_bonus', 'quiz_attempt', $attemptId);
        }

        Response::json([
            'attemptId' => $attemptId,
            'totalQuestions' => count($questions),
            'correctCount' => $correctCount,
            'score' => $score,
            'pointsEarned' => $earnedPoints + $bonusAwarded,
            'perfectBonus' => $bonusAwarded,
            'results' => $results,
        ]);
    }
}
