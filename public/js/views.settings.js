// صفحة إعدادات الذكاء الاصطناعي (للمشرف فقط): إضافة/تحديث مفتاح DeepSeek API
window.App = window.App || {};
App.views = App.views || {};

App.views.settings = async function settings() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="an" style="max-width:480px;margin:0 auto">
      <div class="page-title"><i class="fas fa-key" style="color:var(--cyan)"></i> إعدادات الذكاء الاصطناعي</div>
      <div class="page-sub">أضف مفتاح DeepSeek API لتفعيل توليد المناهج، الاختبارات، والمساعد الذكي</div>

      <div id="keyStatus" class="key-status">${App.ui.loadingHtml('جاري التحقق من الحالة...')}</div>

      <div class="card">
        <form id="settingsForm">
          <div class="form-group">
            <label>مفتاح DeepSeek API</label>
            <input class="form-control" type="password" id="apiKeyInput" placeholder="sk-xxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
            <div class="form-hint">
              يُخزَّن مشفّراً في قاعدة البيانات ولا يُعرض كاملاً بعد الحفظ.
              احصل على مفتاحك من <a href="https://platform.deepseek.com" target="_blank" rel="noopener" style="color:var(--cyan);font-weight:700">platform.deepseek.com</a>
            </div>
          </div>
          <div class="form-group">
            <label>رابط الـ API الأساسي</label>
            <input class="form-control" type="text" id="baseUrlInput" placeholder="https://api.deepseek.com">
          </div>
          <div class="form-group">
            <label>اسم النموذج</label>
            <input class="form-control" type="text" id="modelInput" placeholder="deepseek-chat">
          </div>
          <div class="form-error" id="settingsError"></div>
          <div style="display:flex;gap:10px">
            <button class="btn btn-primary btn-block" type="submit" id="saveSettingsBtn">
              <i class="fas fa-floppy-disk"></i> حفظ الإعدادات
            </button>
            <button class="btn btn-outline" type="button" id="testConnBtn"><i class="fas fa-plug"></i> اختبار الاتصال</button>
          </div>
        </form>
      </div>
    </div>
  `;

  const statusEl = document.getElementById('keyStatus');

  async function loadStatus() {
    try {
      const s = await App.api.getDeepseekSettings();
      statusEl.className = `key-status ${s.hasApiKey ? 'ok' : 'missing'}`;
      statusEl.innerHTML = s.hasApiKey
        ? `<i class="fas fa-circle-check"></i> مفتاح مضبوط حالياً: ${App.ui.escapeHtml(s.maskedApiKey)} (المصدر: ${s.source === 'database' ? 'لوحة التحكم' : 'ملف env.'})`
        : `<i class="fas fa-circle-exclamation"></i> لم يتم ضبط أي مفتاح بعد — الميزات الذكية معطّلة حالياً`;
      document.getElementById('baseUrlInput').value = s.apiBaseUrl || 'https://api.deepseek.com';
      document.getElementById('modelInput').value = s.model || 'deepseek-chat';
    } catch (err) {
      statusEl.className = 'key-status missing';
      statusEl.textContent = 'تعذّر جلب حالة الإعدادات: ' + err.message;
    }
  }
  loadStatus();

  document.getElementById('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('settingsError');
    const saveBtn = document.getElementById('saveSettingsBtn');
    errorEl.classList.remove('show');
    const apiKey = document.getElementById('apiKeyInput').value.trim();
    if (!apiKey) {
      errorEl.textContent = 'يرجى إدخال مفتاح API لحفظه';
      errorEl.classList.add('show');
      return;
    }
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner"></span> جاري الحفظ...';
    try {
      await App.api.saveDeepseekSettings({
        apiKey,
        baseUrl: document.getElementById('baseUrlInput').value.trim(),
        model: document.getElementById('modelInput').value.trim(),
      });
      document.getElementById('apiKeyInput').value = '';
      App.ui.toast('تم حفظ إعدادات DeepSeek بنجاح', 'ok');
      loadStatus();
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.classList.add('show');
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = '<i class="fas fa-floppy-disk"></i> حفظ الإعدادات';
    }
  });

  document.getElementById('testConnBtn').addEventListener('click', async () => {
    const btn = document.getElementById('testConnBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> جاري الاختبار...';
    try {
      await App.api.testDeepseekConnection();
      App.ui.toast('الاتصال ناجح! المفتاح يعمل بشكل صحيح ✅', 'ok');
    } catch (err) {
      App.ui.toast(err.message, 'err');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-plug"></i> اختبار الاتصال';
    }
  });
};
