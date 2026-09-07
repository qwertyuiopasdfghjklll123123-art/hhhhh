// شاشة اختيار الدولة والمرحلة الدراسية (نقطة البداية)
window.App = window.App || {};
App.views = App.views || {};

App.views.onboarding = async function onboarding() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="an" style="padding-top:26px">
      <div class="page-title" style="text-align:center;font-size:1.35rem">اختر بلدك ومرحلتك الدراسية</div>
      <div class="page-sub" style="text-align:center">سنعرض لك المواد والمحاضرات المناسبة لك تلقائياً</div>
      <div class="card" style="max-width:420px;margin:0 auto">
        <div class="form-group">
          <label>الدولة</label>
          <select class="form-control" id="obCountry"><option value="">جاري التحميل...</option></select>
        </div>
        <div class="form-group">
          <label>المرحلة الدراسية</label>
          <select class="form-control" id="obStage" disabled><option value="">اختر الدولة أولاً</option></select>
        </div>
        <button class="btn btn-primary btn-block" id="obSubmit" disabled>ابدأ التعلّم <i class="fas fa-arrow-left"></i></button>
      </div>
    </div>
  `;

  const countrySelect = document.getElementById('obCountry');
  const stageSelect = document.getElementById('obStage');
  const submitBtn = document.getElementById('obSubmit');

  try {
    const { countries } = await App.api.getCountries();
    countrySelect.innerHTML =
      '<option value="">اختر الدولة...</option>' +
      countries.map((c) => `<option value="${c.id}">${c.flag_emoji || ''} ${App.ui.escapeHtml(c.name_ar)}</option>`).join('');
  } catch (err) {
    countrySelect.innerHTML = '<option value="">تعذّر تحميل الدول</option>';
    App.ui.toast(err.message, 'err');
  }

  countrySelect.addEventListener('change', async () => {
    submitBtn.disabled = true;
    if (!countrySelect.value) {
      stageSelect.disabled = true;
      stageSelect.innerHTML = '<option value="">اختر الدولة أولاً</option>';
      return;
    }
    stageSelect.disabled = false;
    stageSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    try {
      const { stages } = await App.api.getStages(countrySelect.value);
      stageSelect.innerHTML =
        '<option value="">اختر المرحلة...</option>' +
        stages.map((s) => `<option value="${s.id}">${App.ui.escapeHtml(s.name_ar)}</option>`).join('');
    } catch (err) {
      App.ui.toast(err.message, 'err');
    }
  });

  stageSelect.addEventListener('change', () => {
    submitBtn.disabled = !stageSelect.value;
  });

  submitBtn.addEventListener('click', () => {
    App.state.setSelection(countrySelect.value, stageSelect.value);
    location.hash = '#/home';
  });
};
