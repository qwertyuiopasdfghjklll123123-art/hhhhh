// إدارة حالة التطبيق (المستخدم، التوكن، الدولة/المرحلة المختارة) عبر localStorage
window.App = window.App || {};

App.state = (function () {
  const KEYS = {
    token: 'zaki_token',
    user: 'zaki_user',
    country: 'zaki_country',
    stage: 'zaki_stage',
  };

  function safeGet(key) {
    try {
      return localStorage.getItem(key);
    } catch (err) {
      return null;
    }
  }
  function safeSet(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (err) {
      /* تجاهل بيئات بدون localStorage */
    }
  }
  function safeRemove(key) {
    try {
      localStorage.removeItem(key);
    } catch (err) {
      /* تجاهل */
    }
  }

  return {
    getToken() {
      return safeGet(KEYS.token);
    },
    getUser() {
      const raw = safeGet(KEYS.user);
      return raw ? JSON.parse(raw) : null;
    },
    setSession(token, user) {
      safeSet(KEYS.token, token);
      safeSet(KEYS.user, JSON.stringify(user));
    },
    updateUser(patch) {
      const user = this.getUser();
      const next = Object.assign({}, user, patch);
      safeSet(KEYS.user, JSON.stringify(next));
      return next;
    },
    clearSession() {
      safeRemove(KEYS.token);
      safeRemove(KEYS.user);
    },
    isLoggedIn() {
      return Boolean(this.getToken());
    },
    isAdmin() {
      const user = this.getUser();
      return Boolean(user && user.role === 'admin');
    },

    getSelection() {
      return {
        countryId: safeGet(KEYS.country),
        stageId: safeGet(KEYS.stage),
      };
    },
    setSelection(countryId, stageId) {
      safeSet(KEYS.country, String(countryId));
      safeSet(KEYS.stage, String(stageId));
    },
    clearSelection() {
      safeRemove(KEYS.country);
      safeRemove(KEYS.stage);
    },
  };
})();
