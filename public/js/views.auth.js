// صفحتا تسجيل الدخول وإنشاء حساب جديد
window.App = window.App || {};
App.views = App.views || {};

App.views.login = async function login() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="auth-card an">
      <div class="page-title">مرحباً بعودتك 👋</div>
      <div class="page-sub">سجّل الدخول لمتابعة رحلتك التعليمية</div>
      <div class="card">
        <form id="loginForm">
          <div class="form-group">
            <label>البريد الإلكتروني</label>
            <input class="form-control" type="email" id="loginEmail" required placeholder="example@mail.com">
          </div>
          <div class="form-group">
            <label>كلمة المرور</label>
            <input class="form-control" type="password" id="loginPassword" required placeholder="••••••••">
          </div>
          <div class="form-error" id="loginError"></div>
          <button class="btn btn-primary btn-block" type="submit" id="loginSubmit">دخول</button>
        </form>
      </div>
      <div class="auth-switch">ليس لديك حساب؟ <a href="#/register">أنشئ حساباً جديداً</a></div>
    </div>
  `;

  document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('loginError');
    const submitBtn = document.getElementById('loginSubmit');
    errorEl.classList.remove('show');
    submitBtn.disabled = true;
    submitBtn.textContent = 'جاري الدخول...';
    try {
      const { token, user } = await App.api.login({
        email: document.getElementById('loginEmail').value.trim(),
        password: document.getElementById('loginPassword').value,
      });
      App.state.setSession(token, user);
      App.main.refreshHeader();
      App.ui.toast(`أهلاً بك ${user.name}!`, 'ok');
      location.hash = '#/home';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.classList.add('show');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'دخول';
    }
  });
};

App.views.register = async function register() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="auth-card an">
      <div class="page-title">إنشاء حساب جديد ✨</div>
      <div class="page-sub">انضم وابدأ اجمع النقاط من أول محاضرة</div>
      <div class="card">
        <form id="registerForm">
          <div class="form-group">
            <label>الاسم الكامل</label>
            <input class="form-control" type="text" id="regName" required placeholder="اسمك">
          </div>
          <div class="form-group">
            <label>البريد الإلكتروني</label>
            <input class="form-control" type="email" id="regEmail" required placeholder="example@mail.com">
          </div>
          <div class="form-group">
            <label>كلمة المرور</label>
            <input class="form-control" type="password" id="regPassword" required minlength="6" placeholder="6 محارف على الأقل">
          </div>
          <div class="select-grid">
            <div class="form-group">
              <label>الدولة</label>
              <select class="form-control" id="regCountry"><option value="">اختر...</option></select>
            </div>
            <div class="form-group">
              <label>المرحلة الدراسية</label>
              <select class="form-control" id="regStage" disabled><option value="">اختر الدولة أولاً</option></select>
            </div>
          </div>
          <div class="form-error" id="regError"></div>
          <button class="btn btn-primary btn-block" type="submit" id="regSubmit">إنشاء الحساب</button>
        </form>
      </div>
      <div class="auth-switch">لديك حساب بالفعل؟ <a href="#/login">سجّل الدخول</a></div>
    </div>
  `;

  const countrySelect = document.getElementById('regCountry');
  const stageSelect = document.getElementById('regStage');

  try {
    const { countries } = await App.api.getCountries();
    countrySelect.innerHTML =
      '<option value="">اختر...</option>' +
      countries.map((c) => `<option value="${c.id}">${c.flag_emoji || ''} ${App.ui.escapeHtml(c.name_ar)}</option>`).join('');
  } catch (err) {
    App.ui.toast('تعذّر تحميل قائمة الدول', 'err');
  }

  countrySelect.addEventListener('change', async () => {
    if (!countrySelect.value) {
      stageSelect.disabled = true;
      stageSelect.innerHTML = '<option value="">اختر الدولة أولاً</option>';
      return;
    }
    stageSelect.disabled = false;
    stageSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    const { stages } = await App.api.getStages(countrySelect.value);
    stageSelect.innerHTML =
      '<option value="">اختر المرحلة...</option>' +
      stages.map((s) => `<option value="${s.id}">${App.ui.escapeHtml(s.name_ar)}</option>`).join('');
  });

  document.getElementById('registerForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('regError');
    const submitBtn = document.getElementById('regSubmit');
    errorEl.classList.remove('show');
    submitBtn.disabled = true;
    submitBtn.textContent = 'جاري الإنشاء...';
    try {
      const { token, user } = await App.api.register({
        name: document.getElementById('regName').value.trim(),
        email: document.getElementById('regEmail').value.trim(),
        password: document.getElementById('regPassword').value,
        countryId: countrySelect.value || null,
        stageId: stageSelect.value || null,
      });
      App.state.setSession(token, user);
      if (countrySelect.value && stageSelect.value) {
        App.state.setSelection(countrySelect.value, stageSelect.value);
      }
      App.main.refreshHeader();
      App.ui.toast(`تم إنشاء حسابك بنجاح، أهلاً بك ${user.name}!`, 'ok');
      location.hash = '#/home';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.classList.add('show');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'إنشاء الحساب';
    }
  });
};
