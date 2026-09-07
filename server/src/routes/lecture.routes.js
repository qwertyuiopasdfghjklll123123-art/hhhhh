const express = require('express');
const { query } = require('../config/db');
const asyncHandler = require('../utils/asyncHandler');
const ApiError = require('../utils/ApiError');
const { requireAuth, optionalAuth } = require('../middleware/auth');
const pointsService = require('../services/pointsService');

const router = express.Router();

// GET /api/lectures/:id
router.get(
  '/:id',
  optionalAuth,
  asyncHandler(async (req, res) => {
    const [lecture] = await query(
      `SELECT l.*, u.title_ar AS unit_title, u.subject_id,
              sub.name_ar AS subject_name
       FROM lectures l
       JOIN units u ON u.id = l.unit_id
       JOIN subjects sub ON sub.id = u.subject_id
       WHERE l.id = :id`,
      { id: req.params.id }
    );
    if (!lecture) throw new ApiError(404, 'المحاضرة غير موجودة');

    let progress = null;
    if (req.user) {
      const rows = await query(
        `SELECT watched_seconds, is_completed, completed_at
         FROM lecture_progress WHERE user_id = :userId AND lecture_id = :lectureId`,
        { userId: req.user.id, lectureId: lecture.id }
      );
      progress = rows[0] || { watched_seconds: 0, is_completed: 0, completed_at: null };
    }

    await query('UPDATE lectures SET view_count = view_count + 1 WHERE id = :id', {
      id: lecture.id,
    });

    res.json({ lecture, progress });
  })
);

// POST /api/lectures/:id/progress  { watchedSeconds, completed }
router.post(
  '/:id/progress',
  requireAuth,
  asyncHandler(async (req, res) => {
    const lectureId = req.params.id;
    const { watchedSeconds = 0, completed = false } = req.body;

    const [lecture] = await query('SELECT id FROM lectures WHERE id = :id', { id: lectureId });
    if (!lecture) throw new ApiError(404, 'المحاضرة غير موجودة');

    await query(
      `INSERT INTO lecture_progress (user_id, lecture_id, watched_seconds, is_completed, completed_at)
       VALUES (:userId, :lectureId, :watchedSeconds, :completed, :completedAt)
       ON DUPLICATE KEY UPDATE
         watched_seconds = GREATEST(watched_seconds, VALUES(watched_seconds)),
         is_completed = is_completed OR VALUES(is_completed),
         completed_at = COALESCE(completed_at, VALUES(completed_at))`,
      {
        userId: req.user.id,
        lectureId,
        watchedSeconds,
        completed: completed ? 1 : 0,
        completedAt: completed ? new Date() : null,
      }
    );

    let pointsAwarded = 0;
    if (completed) {
      const [row] = await query(
        `SELECT points_awarded FROM lecture_progress WHERE user_id = :userId AND lecture_id = :lectureId`,
        { userId: req.user.id, lectureId }
      );
      if (row && !row.points_awarded) {
        await pointsService.award(
          req.user.id,
          pointsService.POINTS.LECTURE_COMPLETE,
          'lecture_complete',
          'lecture',
          lectureId
        );
        await query(
          `UPDATE lecture_progress SET points_awarded = 1 WHERE user_id = :userId AND lecture_id = :lectureId`,
          { userId: req.user.id, lectureId }
        );
        pointsAwarded = pointsService.POINTS.LECTURE_COMPLETE;
      }
    }

    res.json({ ok: true, pointsAwarded });
  })
);

module.exports = router;
