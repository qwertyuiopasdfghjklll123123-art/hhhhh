const express = require('express');
const { query } = require('../config/db');
const asyncHandler = require('../utils/asyncHandler');
const { requireAuth } = require('../middleware/auth');
const pointsService = require('../services/pointsService');

const router = express.Router();

// GET /api/points/me
router.get(
  '/me',
  requireAuth,
  asyncHandler(async (req, res) => {
    const [user] = await query('SELECT points_total FROM users WHERE id = :id', { id: req.user.id });
    const history = await pointsService.getHistory(req.user.id, 50);
    const badges = await query(
      `SELECT b.code, b.name_ar, b.description_ar, b.icon, ub.earned_at
       FROM user_badges ub JOIN badges b ON b.id = ub.badge_id
       WHERE ub.user_id = :userId ORDER BY ub.earned_at DESC`,
      { userId: req.user.id }
    );
    res.json({ pointsTotal: user ? user.points_total : 0, history, badges });
  })
);

module.exports = router;
