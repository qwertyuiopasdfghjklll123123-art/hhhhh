const express = require('express');
const { query } = require('../config/db');
const asyncHandler = require('../utils/asyncHandler');
const { optionalAuth } = require('../middleware/auth');

const router = express.Router();

// GET /api/leaderboard?scope=global|country&countryId=&limit=50
router.get(
  '/',
  optionalAuth,
  asyncHandler(async (req, res) => {
    const scope = req.query.scope === 'country' ? 'country' : 'global';
    const limit = Math.min(Number(req.query.limit) || 50, 100);
    let countryId = req.query.countryId ? Number(req.query.countryId) : null;

    if (scope === 'country' && !countryId && req.user) {
      const [u] = await query('SELECT country_id FROM users WHERE id = :id', { id: req.user.id });
      countryId = u ? u.country_id : null;
    }

    const rows =
      scope === 'country' && countryId
        ? await query(
            `SELECT user_id, name, avatar_url, points_total, country_name_ar, country_flag, rank_in_country AS rank
             FROM leaderboard_view WHERE country_id = :countryId
             ORDER BY points_total DESC LIMIT ${limit}`,
            { countryId }
          )
        : await query(
            `SELECT user_id, name, avatar_url, points_total, country_name_ar, country_flag, rank_global AS rank
             FROM leaderboard_view ORDER BY points_total DESC LIMIT ${limit}`
          );

    let me = null;
    if (req.user) {
      const [mine] = await query(
        `SELECT user_id, name, avatar_url, points_total, country_name_ar, country_flag,
                rank_global, rank_in_country
         FROM leaderboard_view WHERE user_id = :userId`,
        { userId: req.user.id }
      );
      me = mine || null;
    }

    res.json({ scope, countryId, leaderboard: rows, me });
  })
);

module.exports = router;
