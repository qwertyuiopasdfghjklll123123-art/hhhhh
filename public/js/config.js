// إعدادات عامة للواجهة الأمامية
window.App = window.App || {};

App.config = {
  // عنوان خادم الـ API الخلفي (عدّله عند النشر الفعلي)
  API_BASE_URL: (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
    ? 'http://localhost:4000/api'
    : '/api',

  POINTS_LABELS: {
    lecture_complete: 'إكمال محاضرة',
    quiz_correct_answer: 'إجابة صحيحة',
    quiz_perfect_bonus: 'مكافأة علامة كاملة',
    daily_streak: 'مواظبة يومية',
    manual_adjustment: 'تعديل يدوي',
  },
};
