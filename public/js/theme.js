// تبديل المظهر الفاتح/الداكن وحفظ التفضيل
window.App = window.App || {};

App.theme = {
  init() {
    try {
      if (localStorage.getItem('zaki_theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    } catch (err) {
      /* تجاهل */
    }
  },
  toggle() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) {
      document.documentElement.removeAttribute('data-theme');
    } else {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
    try {
      localStorage.setItem('zaki_theme', isDark ? 'light' : 'dark');
    } catch (err) {
      /* تجاهل */
    }
  },
};

App.theme.init();
