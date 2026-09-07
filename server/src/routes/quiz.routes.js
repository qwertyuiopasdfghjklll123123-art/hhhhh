const express = require('express');
const { query, pool } = require('../config/db');
const asyncHandler = require('../utils/asyncHandler');
const ApiError = require('../utils/ApiError');
const { requireAuth } = require('../middleware/auth');
const deepseekService = require('../services/deepseekService');
const pointsService = require('../services/pointsService');

const router = express.Router();

async function loadQuizWithQuestions(quizId, { includeAnswers = false } = {}) {
  const [quiz] = await query('SELECT * FROM quizzes WHERE id = :id', { id: quizId });
  if (!quiz) return null;

  const questions = await query(
    'SELECT id, question_text, explanation, points_value, order_index FROM quiz_questions WHERE quiz_id = :quizId ORDER BY order_index',
    { quizId }
  );

  const optionCols = includeAnswers
    ? 'id, question_id, option_text, is_correct, order_index'
    : 'id, question_id, option_text, order_index';

  for (const q of questions) {
    q.options = await query(
      `SELECT ${optionCols} FROM quiz_options WHERE question_id = :questionId ORDER BY order_index`,
      { questionId: q.id }
    );
  }

  return { quiz, questions };
}

// GET /api/lectures/:lectureId/quiz
// يُعيد اختباراً موجوداً، أو يولّد واحداً جديداً بالذكاء الاصطناعي أول مرة ويخزّنه
router.get(
  '/lectures/:lectureId/quiz',
  requireAuth,
  asyncHandler(async (req, res) => {
    const { lectureId } = req.params;
    const [lecture] = await query('SELECT * FROM lectures WHERE id = :id', { id: lectureId });
    if (!lecture) throw new ApiError(404, 'المحاضرة غير موجودة');

    let [existingQuiz] = await query('SELECT id FROM quizzes WHERE lecture_id = :lectureId LIMIT 1', {
      lectureId,
    });

    if (!existingQuiz) {
      const generated = await deepseekService.generateQuiz({
        lectureTitle: lecture.title_ar,
        lectureDescription: lecture.description,
        transcript: lecture.transcript_text,
      });

      const conn = await pool.getConnection();
      try {
        await conn.beginTransaction();
        const [quizResult] = await conn.execute(
          `INSERT INTO quizzes (lecture_id, title, generated_by) VALUES (?, ?, 'ai')`,
          [lectureId, `اختبار: ${lecture.title_ar}`]
        );
        const quizId = quizResult.insertId;

        for (const [qIndex, question] of (generated.questions || []).entries()) {
          const [questionResult] = await conn.execute(
            `INSERT INTO quiz_questions (quiz_id, question_text, explanation, order_index)
             VALUES (?, ?, ?, ?)`,
            [quizId, question.question_text, question.explanation || null, qIndex + 1]
          );
          const questionId = questionResult.insertId;

          for (const [oIndex, option] of (question.options || []).entries()) {
            await conn.execute(
              `INSERT INTO quiz_options (question_id, option_text, is_correct, order_index)
               VALUES (?, ?, ?, ?)`,
              [questionId, option.text, option.is_correct ? 1 : 0, oIndex + 1]
            );
          }
        }

        await conn.commit();
        existingQuiz = { id: quizId };
      } catch (err) {
        await conn.rollback();
        throw err;
      } finally {
        conn.release();
      }
    }

    const data = await loadQuizWithQuestions(existingQuiz.id, { includeAnswers: false });
    res.json(data);
  })
);

// POST /api/quizzes/:id/submit  { answers: [{ questionId, selectedOptionId }] }
router.post(
  '/quizzes/:id/submit',
  requireAuth,
  asyncHandler(async (req, res) => {
    const quizId = req.params.id;
    const { answers, startedAt } = req.body;
    if (!Array.isArray(answers) || !answers.length) {
      throw new ApiError(400, 'يجب إرسال إجابات الأسئلة');
    }

    const data = await loadQuizWithQuestions(quizId, { includeAnswers: true });
    if (!data) throw new ApiError(404, 'الاختبار غير موجود');

    const { questions } = data;
    const questionMap = new Map(questions.map((q) => [q.id, q]));

    let correctCount = 0;
    let score = 0;
    const results = [];

    for (const answer of answers) {
      const question = questionMap.get(Number(answer.questionId));
      if (!question) continue;
      const correctOption = question.options.find((o) => o.is_correct);
      const isCorrect = correctOption && Number(answer.selectedOptionId) === correctOption.id;
      if (isCorrect) {
        correctCount += 1;
        score += question.points_value;
      }
      results.push({
        questionId: question.id,
        selectedOptionId: answer.selectedOptionId || null,
        correctOptionId: correctOption ? correctOption.id : null,
        isCorrect: Boolean(isCorrect),
        explanation: question.explanation,
      });
    }

    const conn = await pool.getConnection();
    let attemptId;
    try {
      await conn.beginTransaction();
      const [attemptResult] = await conn.execute(
        `INSERT INTO quiz_attempts
           (user_id, quiz_id, score, total_questions, correct_count, started_at, submitted_at, duration_seconds)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)`,
        [
          req.user.id,
          quizId,
          score,
          questions.length,
          correctCount,
          startedAt ? new Date(startedAt) : new Date(),
          startedAt ? Math.max(0, Math.round((Date.now() - new Date(startedAt).getTime()) / 1000)) : null,
        ]
      );
      attemptId = attemptResult.insertId;

      for (const r of results) {
        await conn.execute(
          `INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_option_id, is_correct)
           VALUES (?, ?, ?, ?)`,
          [attemptId, r.questionId, r.selectedOptionId, r.isCorrect ? 1 : 0]
        );
      }

      await conn.commit();
    } catch (err) {
      await conn.rollback();
      throw err;
    } finally {
      conn.release();
    }

    // نقاط الإجابات الصحيحة + مكافأة العلامة الكاملة
    const earnedPoints = correctCount * pointsService.POINTS.QUIZ_CORRECT_ANSWER;
    if (earnedPoints > 0) {
      await pointsService.award(
        req.user.id,
        earnedPoints,
        'quiz_correct_answer',
        'quiz_attempt',
        attemptId
      );
    }
    let bonusAwarded = 0;
    if (correctCount === questions.length) {
      bonusAwarded = pointsService.POINTS.QUIZ_PERFECT_BONUS;
      await pointsService.award(req.user.id, bonusAwarded, 'quiz_perfect_bonus', 'quiz_attempt', attemptId);
    }

    res.json({
      attemptId,
      totalQuestions: questions.length,
      correctCount,
      score,
      pointsEarned: earnedPoints + bonusAwarded,
      perfectBonus: bonusAwarded,
      results,
    });
  })
);

module.exports = router;
