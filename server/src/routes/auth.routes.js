const express = require('express');
const bcrypt = require('bcryptjs');
const { query } = require('../config/db');
const { signToken } = require('../utils/jwt');
const { requireAuth } = require('../middleware/auth');
const asyncHandler = require('../utils/asyncHandler');
const ApiError = require('../utils/ApiError');

const router = express.Router();

function publicUser(u) {
  return {
    id: u.id,
    name: u.name,
    email: u.email,
    role: u.role,
    countryId: u.country_id,
    stageId: u.stage_id,
    avatarUrl: u.avatar_url,
    pointsTotal: u.points_total,
  };
}

// POST /api/auth/register
router.post(
  '/register',
  asyncHandler(async (req, res) => {
    const { name, email, password, countryId, stageId } = req.body;
    if (!name || !email || !password || password.length < 6) {
      throw new ApiError(400, 'الاسم والبريد الإلكتروني مطلوبان، وكلمة المرور 6 محارف على الأقل');
    }

    const existing = await query('SELECT id FROM users WHERE email = :email', { email });
    if (existing.length) throw new ApiError(409, 'هذا البريد الإلكتروني مسجّل مسبقاً');

    const passwordHash = await bcrypt.hash(password, 10);
    const result = await query(
      `INSERT INTO users (name, email, password_hash, role, country_id, stage_id)
       VALUES (:name, :email, :passwordHash, 'student', :countryId, :stageId)`,
      {
        name,
        email,
        passwordHash,
        countryId: countryId || null,
        stageId: stageId || null,
      }
    );

    const [user] = await query('SELECT * FROM users WHERE id = :id', { id: result.insertId });
    const token = signToken(user);
    res.status(201).json({ token, user: publicUser(user) });
  })
);

// POST /api/auth/login
router.post(
  '/login',
  asyncHandler(async (req, res) => {
    const { email, password } = req.body;
    if (!email || !password) throw new ApiError(400, 'البريد الإلكتروني وكلمة المرور مطلوبان');

    const [user] = await query('SELECT * FROM users WHERE email = :email', { email });
    if (!user || !user.is_active) throw new ApiError(401, 'بيانات الدخول غير صحيحة');

    const ok = await bcrypt.compare(password, user.password_hash);
    if (!ok) throw new ApiError(401, 'بيانات الدخول غير صحيحة');

    await query('UPDATE users SET last_login_at = NOW() WHERE id = :id', { id: user.id });

    const token = signToken(user);
    res.json({ token, user: publicUser(user) });
  })
);

// GET /api/auth/me
router.get(
  '/me',
  requireAuth,
  asyncHandler(async (req, res) => {
    const [user] = await query('SELECT * FROM users WHERE id = :id', { id: req.user.id });
    if (!user) throw new ApiError(404, 'المستخدم غير موجود');
    res.json({ user: publicUser(user) });
  })
);

module.exports = router;
