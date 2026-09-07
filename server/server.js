require('dotenv').config();

const createApp = require('./src/app');
const { pool } = require('./src/config/db');

const PORT = process.env.PORT || 4000;

async function start() {
  try {
    await pool.query('SELECT 1');
    console.log('✅ تم الاتصال بقاعدة البيانات MySQL بنجاح');
  } catch (err) {
    console.error('❌ تعذّر الاتصال بقاعدة البيانات:', err.message);
    console.error('تأكد من ضبط server/.env وتشغيل database/schema.sql أولاً.');
  }

  const app = createApp();
  app.listen(PORT, () => {
    console.log(`🚀 Smart E-Learning API يعمل على المنفذ ${PORT}`);
  });
}

start();
