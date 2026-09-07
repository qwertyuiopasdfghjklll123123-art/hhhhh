// نقطة انطلاق التطبيق: تسجيل المسارات، ربط عناصر الهيدر الثابتة، بدء التشغيل
window.App = window.App || {};

App.main = (function () {
  function refreshHeader() {
    const user = App.state.getUser();
    const pointsChip = document.getElementById('pointsChip');
    const loginBtn = document.getElementById('loginNavBtn');
    const userMenu = document.getElementById('userMenu');
    const udName = document.getElementById('udName');
    const udSettingsItem = document.getElementById('udSettingsItem');
    const bnSettings = document.getElementById('bnSettings');
    const bottomNav = document.getElementById('bottomNav');

    bottomNav.hidden = false;

    if (user) {
      pointsChip.hidden = false;
      document.getElementById('pointsChipValue').textContent = user.pointsTotal || 0;
      loginBtn.hidden = true;
      userMenu.hidden = false;
      udName.textContent = user.name;
      const isAdmin = App.state.isAdmin();
      udSettingsItem.hidden = !isAdmin;
      bnSettings.hidden = !isAdmin;
    } else {
      pointsChip.hidden = true;
      loginBtn.hidden = false;
      userMenu.hidden = true;
      bnSettings.hidden = true;
    }
  }

  function highlightNav(path) {
    const homeLike =
      path === '/home' ||
      path === '/onboarding' ||
      path.startsWith('/subject/') ||
      path.startsWith('/unit/') ||
      path.startsWith('/lecture/') ||
      path.startsWith('/quiz/');

    document.querySelectorAll('.bn-item[data-nav]').forEach((item) => {
      const target = item.dataset.nav.replace('#', '');
      const active = target === '/home' ? homeLike : target === path;
      item.classList.toggle('active', active);
    });
  }

  function wireStaticHeader() {
    document.getElementById('themeBtn').addEventListener('click', () => App.theme.toggle());

    document.querySelectorAll('#bottomNav [data-nav], #userDropdown [data-nav], #loginNavBtn[data-nav]').forEach((el) => {
      el.addEventListener('click', () => {
        location.hash = el.dataset.nav;
        document.getElementById('userDropdown').classList.remove('open');
      });
    });

    document.getElementById('avatarBtn').addEventListener('click', (e) => {
      e.stopPropagation();
      document.getElementById('userDropdown').classList.toggle('open');
    });
    document.addEventListener('click', (e) => {
      const menu = document.getElementById('userMenu');
      if (menu && !menu.contains(e.target)) document.getElementById('userDropdown').classList.remove('open');
    });

    document.getElementById('logoutBtn').addEventListener('click', () => {
      App.state.clearSession();
      refreshHeader();
      App.ui.toast('تم تسجيل الخروج');
      location.hash = '#/home';
      App.router.resolve();
    });
  }

  function registerRoutes() {
    App.router.register('/login', App.views.login);
    App.router.register('/register', App.views.register);
    App.router.register('/onboarding', App.views.onboarding);
    App.router.register('/home', App.views.home);
    App.router.register('/subject/:id', App.views.subjectUnits);
    App.router.register('/unit/:id', App.views.unitLectures);
    App.router.register('/lecture/:id', App.views.lecture);
    App.router.register('/quiz/:lectureId', App.views.quiz, { requiresAuth: true });
    App.router.register('/leaderboard', App.views.leaderboard);
    App.router.register('/profile', App.views.profile, { requiresAuth: true });
    App.router.register('/settings', App.views.settings, { requiresAuth: true, requiresAdmin: true });
  }

  async function init() {
    wireStaticHeader();
    refreshHeader();
    registerRoutes();
    App.router.start();

    // تحديث بيانات المستخدم (النقاط، الدور) من الخادم إن كان مسجّلاً دخوله
    if (App.state.isLoggedIn()) {
      try {
        const { user } = await App.api.me();
        App.state.updateUser(user);
        refreshHeader();
      } catch (err) {
        if (err.status === 401) {
          App.state.clearSession();
          refreshHeader();
        }
      }
    }
  }

  return { init, refreshHeader, highlightNav };
})();

App.main.init();
