const express = require('express');
const cors = require('cors');

const authRoutes = require('./routes/auth.routes');
const curriculumRoutes = require('./routes/curriculum.routes');
const lectureRoutes = require('./routes/lecture.routes');
const quizRoutes = require('./routes/quiz.routes');
const pointsRoutes = require('./routes/points.routes');
const leaderboardRoutes = require('./routes/leaderboard.routes');
const tutorRoutes = require('./routes/tutor.routes');
const settingsRoutes = require('./routes/settings.routes');
const { notFoundHandler, errorHandler } = require('./middleware/errorHandler');

function createApp() {
  const app = express();

  app.use(
    cors({
      origin: process.env.CLIENT_ORIGIN || '*',
    })
  );
  app.use(express.json({ limit: '1mb' }));

  app.get('/api/health', (req, res) => res.json({ ok: true, service: 'smart-elearning-api' }));

  app.use('/api/auth', authRoutes);
  app.use('/api', curriculumRoutes); // /countries, /stages/:id/subjects, /subjects/:id/units, /units/:id/lectures
  app.use('/api/lectures', lectureRoutes);
  app.use('/api', quizRoutes); // /lectures/:id/quiz, /quizzes/:id/submit
  app.use('/api/points', pointsRoutes);
  app.use('/api/leaderboard', leaderboardRoutes);
  app.use('/api/tutor', tutorRoutes);
  app.use('/api/settings', settingsRoutes);

  app.use(notFoundHandler);
  app.use(errorHandler);

  return app;
}

module.exports = createApp;
