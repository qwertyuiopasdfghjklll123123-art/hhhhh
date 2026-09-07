const express = require('express');
const { query, pool } = require('../config/db');
const asyncHandler = require('../utils/asyncHandler');
const ApiError = require('../utils/ApiError');
const { requireAuth, requireAdmin } = require('../middleware/auth');
const deepseekService = require('../services/deepseekService');
const youtubeService = require('../services/youtubeService');

const router = express.Router();

// GET /api/countries
router.get(
  '/countries',
  asyncHandler(async (req, res) => {
    const countries = await query(
      'SELECT id, code, name_ar, name_en, flag_emoji FROM countries WHERE is_active = 1 ORDER BY name_ar'
    );
    res.json({ countries });
  })
);

// GET /api/countries/:countryId/stages
router.get(
  '/countries/:countryId/stages',
  asyncHandler(async (req, res) => {
    const stages = await query(
      `SELECT id, name_ar, name_en, education_level, level_order
       FROM stages WHERE country_id = :countryId AND is_active = 1
       ORDER BY level_order`,
      { countryId: req.params.countryId }
    );
    res.json({ stages });
  })
);

// GET /api/stages/:stageId/subjects
router.get(
  '/stages/:stageId/subjects',
  asyncHandler(async (req, res) => {
    const subjects = await query(
      `SELECT id, name_ar, name_en, icon, color_hex, order_index
       FROM subjects WHERE stage_id = :stageId ORDER BY order_index, name_ar`,
      { stageId: req.params.stageId }
    );
    res.json({ subjects, needsGeneration: subjects.length === 0 });
  })
);

// GET /api/subjects/:subjectId
router.get(
  '/subjects/:subjectId',
  asyncHandler(async (req, res) => {
    const [subject] = await query(
      `SELECT id, stage_id, name_ar, name_en, icon, color_hex FROM subjects WHERE id = :id`,
      { id: req.params.subjectId }
    );
    if (!subject) throw new ApiError(404, 'المادة غير موجودة');
    res.json({ subject });
  })
);

// GET /api/units/:unitId
router.get(
  '/units/:unitId',
  asyncHandler(async (req, res) => {
    const [unit] = await query(
      `SELECT u.id, u.title_ar, u.description, u.subject_id, s.name_ar AS subject_name
       FROM units u JOIN subjects s ON s.id = u.subject_id WHERE u.id = :id`,
      { id: req.params.unitId }
    );
    if (!unit) throw new ApiError(404, 'الوحدة غير موجودة');
    res.json({ unit });
  })
);

// GET /api/subjects/:subjectId/units
router.get(
  '/subjects/:subjectId/units',
  asyncHandler(async (req, res) => {
    const units = await query(
      `SELECT u.id, u.title_ar, u.description, u.order_index,
              COUNT(l.id) AS lecture_count
       FROM units u
       LEFT JOIN lectures l ON l.unit_id = u.id
       WHERE u.subject_id = :subjectId
       GROUP BY u.id
       ORDER BY u.order_index`,
      { subjectId: req.params.subjectId }
    );
    res.json({ units });
  })
);

// GET /api/units/:unitId/lectures
router.get(
  '/units/:unitId/lectures',
  asyncHandler(async (req, res) => {
    const lectures = await query(
      `SELECT id, title_ar, description, youtube_video_id, youtube_url, is_link_verified,
              duration_seconds, thumbnail_url, order_index
       FROM lectures WHERE unit_id = :unitId ORDER BY order_index`,
      { unitId: req.params.unitId }
    );
    res.json({ lectures });
  })
);

// POST /api/stages/:stageId/generate
// محرك المناهج بالذكاء الاصطناعي: يولّد المواد + الوحدات + المحاضرات لمرحلة
// لا تملك محتوى بعد، ثم يخزّنها في قاعدة البيانات (يُستدعى مرة واحدة فقط
// لكل مرحلة، وبعدها تُقرأ البيانات من القاعدة مباشرة لتقليل تكلفة الذكاء الاصطناعي)
router.post(
  '/stages/:stageId/generate',
  requireAuth,
  requireAdmin,
  asyncHandler(async (req, res) => {
    const { stageId } = req.params;
    const [stage] = await query(
      `SELECT s.*, c.name_ar AS country_name_ar, c.name_en AS country_name_en
       FROM stages s JOIN countries c ON c.id = s.country_id
       WHERE s.id = :stageId`,
      { stageId }
    );
    if (!stage) throw new ApiError(404, 'المرحلة الدراسية غير موجودة');

    const existing = await query('SELECT id FROM subjects WHERE stage_id = :stageId', { stageId });
    if (existing.length) {
      throw new ApiError(409, 'تم توليد المنهج لهذه المرحلة مسبقاً');
    }

    const jobResult = await query(
      `INSERT INTO ai_generation_jobs (job_type, country_id, stage_id, status, request_payload)
       VALUES ('curriculum', :countryId, :stageId, 'processing', :payload)`,
      {
        countryId: stage.country_id,
        stageId,
        payload: JSON.stringify({ stageName: stage.name_ar, countryName: stage.country_name_ar }),
      }
    );
    const jobId = jobResult.insertId;

    let curriculum;
    try {
      curriculum = await deepseekService.generateCurriculum({
        countryName: stage.country_name_ar,
        stageName: stage.name_ar,
      });
    } catch (err) {
      await query(
        `UPDATE ai_generation_jobs SET status='failed', error_message=:msg, completed_at=NOW() WHERE id=:id`,
        { msg: err.message.slice(0, 500), id: jobId }
      );
      throw err;
    }

    const conn = await pool.getConnection();
    let subjectCount = 0;
    let unitCount = 0;
    let lectureCount = 0;
    try {
      await conn.beginTransaction();

      for (const [sIndex, subject] of (curriculum.subjects || []).entries()) {
        const [subjectResult] = await conn.execute(
          `INSERT INTO subjects (stage_id, name_ar, icon, order_index, is_ai_generated)
           VALUES (?, ?, ?, ?, 1)`,
          [stageId, subject.name_ar, subject.icon || 'fa-book', sIndex + 1]
        );
        subjectCount += 1;
        const subjectId = subjectResult.insertId;

        for (const [uIndex, unit] of (subject.units || []).entries()) {
          const [unitResult] = await conn.execute(
            `INSERT INTO units (subject_id, title_ar, description, order_index, is_ai_generated)
             VALUES (?, ?, ?, ?, 1)`,
            [subjectId, unit.title_ar, unit.description || null, uIndex + 1]
          );
          unitCount += 1;
          const unitId = unitResult.insertId;

          for (const [lIndex, lecture] of (unit.lectures || []).entries()) {
            // محاولة إيجاد فيديو حقيقي موثوق عبر YouTube Data API (إن كان مفعّلاً)
            const found = await youtubeService.searchLectureVideo(lecture.search_query || lecture.title_ar);
            const youtubeUrl = found
              ? `https://www.youtube.com/watch?v=${found.videoId}`
              : youtubeService.fallbackSearchUrl(lecture.search_query || lecture.title_ar);

            await conn.execute(
              `INSERT INTO lectures
                 (unit_id, title_ar, description, youtube_video_id, youtube_url,
                  is_link_verified, thumbnail_url, source, order_index)
               VALUES (?, ?, ?, ?, ?, ?, ?, 'ai_curated', ?)`,
              [
                unitId,
                lecture.title_ar,
                lecture.description || null,
                found ? found.videoId : null,
                youtubeUrl,
                found ? 1 : 0,
                found ? found.thumbnailUrl : null,
                lIndex + 1,
              ]
            );
            lectureCount += 1;
          }
        }
      }

      await conn.execute(
        `UPDATE ai_generation_jobs
         SET status='completed', completed_at=NOW(),
             response_summary=?
         WHERE id=?`,
        [JSON.stringify({ subjectCount, unitCount, lectureCount }), jobId]
      );

      await conn.commit();
    } catch (err) {
      await conn.rollback();
      await query(
        `UPDATE ai_generation_jobs SET status='failed', error_message=:msg, completed_at=NOW() WHERE id=:id`,
        { msg: err.message.slice(0, 500), id: jobId }
      );
      throw err;
    } finally {
      conn.release();
    }

    res.status(201).json({ message: 'تم توليد المنهج بنجاح', subjectCount, unitCount, lectureCount });
  })
);

module.exports = router;
