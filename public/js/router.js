// موجّه بسيط قائم على الـ hash (#/path/:param) بدون أي مكتبات خارجية
window.App = window.App || {};

App.router = (function () {
  const routes = [];

  function register(pattern, handler, opts = {}) {
    const segments = pattern.split('/').filter(Boolean);
    routes.push({ pattern, segments, handler, requiresAuth: Boolean(opts.requiresAuth), requiresAdmin: Boolean(opts.requiresAdmin) });
  }

  function match(hashPath) {
    const pathSegments = hashPath.split('/').filter(Boolean);
    for (const route of routes) {
      if (route.segments.length !== pathSegments.length) continue;
      const params = {};
      let ok = true;
      for (let i = 0; i < route.segments.length; i++) {
        const rs = route.segments[i];
        const ps = pathSegments[i];
        if (rs.startsWith(':')) params[rs.slice(1)] = decodeURIComponent(ps);
        else if (rs !== ps) {
          ok = false;
          break;
        }
      }
      if (ok) return { route, params };
    }
    return null;
  }

  async function resolve() {
    const hash = location.hash || '#/home';
    const path = hash.replace(/^#/, '') || '/home';
    const found = match(path);
    const view = document.getElementById('view');

    if (!found) {
      view.innerHTML = App.ui.emptyStateHtml('fa-compass', 'الصفحة غير موجودة', 'تحقق من الرابط أو عد للرئيسية');
      return;
    }

    if (found.route.requiresAuth && !App.state.isLoggedIn()) {
      App.ui.toast('يرجى تسجيل الدخول أولاً', 'err');
      location.hash = '#/login';
      return;
    }
    if (found.route.requiresAdmin && !App.state.isAdmin()) {
      App.ui.toast('هذه الصفحة للمشرفين فقط', 'err');
      location.hash = '#/home';
      return;
    }

    App.main.highlightNav(path);
    view.innerHTML = App.ui.loadingHtml();
    try {
      await found.route.handler(found.params);
    } catch (err) {
      if (!App.ui.handleAuthError(err)) {
        view.innerHTML = App.ui.emptyStateHtml('fa-triangle-exclamation', 'حدث خطأ', err.message);
      }
    }
    window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
  }

  function start() {
    window.addEventListener('hashchange', resolve);
    resolve();
  }

  return { register, start, resolve };
})();
