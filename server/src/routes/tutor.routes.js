const express = require('express');
const { query } = require('../config/db');
const asyncHandler = require('../utils/asyncHandler');
const ApiError = require('../utils/ApiError');
const { requireAuth } = require('../middleware/auth');
const deepseekService = require('../services/deepseekService');

const router = express.Router();

async function getOrCreateSession(userId, lectureId) {
  const [existing] = await query(
    `SELECT * FROM ai_chat_sessions WHERE user_id = :userId AND lecture_id = :lectureId
     ORDER BY created_at DESC LIMIT 1`,
    { userId, lectureId }
  );
  if (existing) return existing;

  const result = await query(
    `INSERT INTO ai_chat_sessions (user_id, lecture_id) VALUES (:userId, :lectureId)`,
    { userId, lectureId }
  );
  return { id: result.insertId, user_id: userId, lecture_id: lectureId };
}

// GET /api/tutor/sessions/:lectureId  -- سجل المحادثة السابق مع المساعد لهذه المحاضرة
router.get(
  '/sessions/:lectureId',
  requireAuth,
  asyncHandler(async (req, res) => {
    const [session] = await query(
      `SELECT id FROM ai_chat_sessions WHERE user_id = :userId AND lecture_id = :lectureId
       ORDER BY created_at DESC LIMIT 1`,
      { userId: req.user.id, lectureId: req.params.lectureId }
    );
    if (!session) return res.json({ sessionId: null, messages: [] });

    const messages = await query(
      `SELECT role, message_text, created_at FROM ai_chat_messages
       WHERE session_id = :sessionId ORDER BY created_at ASC`,
      { sessionId: session.id }
    );
    res.json({ sessionId: session.id, messages });
  })
);

// POST /api/tutor/chat  { lectureId, message }
router.post(
  '/chat',
  requireAuth,
  asyncHandler(async (req, res) => {
    const { lectureId, message } = req.body;
    if (!lectureId || !message || !message.trim()) {
      throw new ApiError(400, 'يجب تحديد المحاضرة ونص السؤال');
    }

    const [lecture] = await query('SELECT * FROM lectures WHERE id = :id', { id: lectureId });
    if (!lecture) throw new ApiError(404, 'المحاضرة غير موجودة');

    const session = await getOrCreateSession(req.user.id, lectureId);

    const history = await query(
      `SELECT role, message_text FROM ai_chat_messages WHERE session_id = :sessionId
       ORDER BY created_at ASC LIMIT 20`,
      { sessionId: session.id }
    );

    await query(
      `INSERT INTO ai_chat_messages (session_id, role, message_text) VALUES (:sessionId, 'user', :message)`,
      { sessionId: session.id, message: message.trim() }
    );

    const { reply, usage } = await deepseekService.tutorReply({
      lectureTitle: lecture.title_ar,
      lectureDescription: lecture.description,
      transcript: lecture.transcript_text,
      history,
      userMessage: message.trim(),
    });

    await query(
      `INSERT INTO ai_chat_messages (session_id, role, message_text, tokens_used)
       VALUES (:sessionId, 'assistant', :reply, :tokens)`,
      { sessionId: session.id, reply, tokens: usage ? usage.total_tokens : null }
    );

    res.json({ sessionId: session.id, reply });
  })
);

module.exports = router;
