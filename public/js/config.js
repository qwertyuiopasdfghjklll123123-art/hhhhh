// إعدادات عامة للواجهة الأمامية
window.App = window.App || {};

App.config = {
  // عنوان خادم الـ API الخلفي (عدّله عند النشر الفعلي)
  // ملاحظة: عند فتح index.html مباشرة بنقرتين (file://) يكون hostname فارغاً،
  // لذا نتعامل معه كحالة محلية أيضاً بدل تركه ينكسر على مسار نسبي غير صالح
  API_BASE_URL: (function () {
    const host = window.location.hostname;
    const isLocal = window.location.protocol === 'file:' || host === '' || host === 'localhost' || host === '127.0.0.1';
    return isLocal ? 'http://localhost:4000/api' : '/api';
  })(),

  POINTS_LABELS: {
    lecture_complete: 'إكمال محاضرة',
    quiz_correct_answer: 'إجابة صحيحة',
    quiz_perfect_bonus: 'مكافأة علامة كاملة',
    daily_streak: 'مواظبة يومية',
    manual_adjustment: 'تعديل يدوي',
  },
};
