const { query, pool } = require('../config/db');

const POINTS = {
  LECTURE_COMPLETE: 10,
  QUIZ_CORRECT_ANSWER: 5,
  QUIZ_PERFECT_BONUS: 20,
};

// يمنح نقاطاً لمستخدم ويحدّث الكاش points_total ضمن معاملة واحدة (transaction)
// كي تبقى لوحة المتصدرين متسقة دائماً مع سجل النقاط
async function award(userId, points, reason, referenceType = null, referenceId = null) {
  if (!points || points <= 0) return;
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    await conn.execute(
      `INSERT INTO points_transactions (user_id, points, reason, reference_type, reference_id)
       VALUES (?, ?, ?, ?, ?)`,
      [userId, points, reason, referenceType, referenceId]
    );
    await conn.execute('UPDATE users SET points_total = points_total + ? WHERE id = ?', [
      points,
      userId,
    ]);
    await conn.commit();
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

async function getHistory(userId, limit = 50) {
  return query(
    `SELECT id, points, reason, reference_type, reference_id, created_at
     FROM points_transactions
     WHERE user_id = :userId
     ORDER BY created_at DESC
     LIMIT ${Number(limit) || 50}`,
    { userId }
  );
}

module.exports = { POINTS, award, getHistory };
