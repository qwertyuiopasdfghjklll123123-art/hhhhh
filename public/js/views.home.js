// الصفحة الرئيسية: بطاقة الملف الشخصي + شبكة المواد الدراسية
window.App = window.App || {};
App.views = App.views || {};

App.views.home = async function home() {
  const selection = App.state.getSelection();
  if (!selection.countryId || !selection.stageId) {
    location.hash = '#/onboarding';
    return;
  }

  const view = document.getElementById('view');
  const user = App.state.getUser();

  let profileHtml = '';
  if (user) {
    profileHtml = `
      <div class="profile-card an">
        <div class="profile-row">
          <div class="profile-av">${App.ui.initials(user.name)}</div>
          <div class="profile-info">
            <h2>أهلاً، ${App.ui.escapeHtml(user.name)}</h2>
            <p>واصل التعلّم واجمع المزيد من النقاط اليوم</p>
          </div>
        </div>
        <div class="profile-stats">
          <div class="profile-stat"><b id="homePoints">${user.pointsTotal || 0}</b><span>نقطة</span></div>
          <div class="profile-stat"><b><i class="fas fa-arrow-left" style="font-size:.9rem"></i></b><span><a href="#/leaderboard" style="color:inherit">لوحة المتصدرين</a></span></div>
        </div>
      </div>`;
  } else {
    profileHtml = `
      <div class="card an" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:20px">
        <div>
          <div style="font-weight:800;font-size:.85rem">أنت تتصفح كزائر</div>
          <div style="font-size:.7rem;color:var(--muted);margin-top:2px">سجّل دخولك لحفظ تقدمك وجمع النقاط</div>
        </div>
        <button class="btn btn-primary btn-sm" data-nav="#/login">دخول</button>
      </div>`;
  }

  view.innerHTML = `
    <div class="an">
      ${profileHtml}
      <div class="section-head">
        <h3><i class="fas fa-graduation-cap"></i> المواد الدراسية</h3>
        <a href="#/onboarding" style="font-size:.68rem;color:var(--cyan);font-weight:700">تغيير المرحلة</a>
      </div>
      <div id="subjectsHolder">${App.ui.loadingHtml('جاري تحميل المواد...')}</div>
    </div>
  `;
  view.querySelectorAll('[data-nav]').forEach((btn) =>
    btn.addEventListener('click', () => (location.hash = btn.dataset.nav))
  );

  const holder = document.getElementById('subjectsHolder');
  try {
    const { subjects, needsGeneration } = await App.api.getSubjects(selection.stageId);

    if (!subjects.length && needsGeneration) {
      holder.innerHTML = App.state.isAdmin()
        ? `<div class="center-box">
             <i class="fas fa-wand-magic-sparkles"></i>
             <div style="font-weight:700;color:var(--text)">لا يوجد منهج بعد لهذه المرحلة</div>
             <div style="font-size:.75rem">يمكنك توليده تلقائياً بالذكاء الاصطناعي الآن</div>
             <button class="btn btn-primary" id="genCurriculumBtn" style="margin-top:10px">
               <i class="fas fa-sparkles"></i> توليد المنهج بالذكاء الاصطناعي
             </button>
           </div>`
        : App.ui.emptyStateHtml('fa-hourglass-half', 'المنهج قيد التحضير', 'يقوم فريقنا بإعداد محتوى هذه المرحلة، عد قريباً');

      const genBtn = document.getElementById('genCurriculumBtn');
      if (genBtn) {
        genBtn.addEventListener('click', async () => {
          genBtn.disabled = true;
          genBtn.innerHTML = '<span class="spinner"></span> جاري التوليد (قد يستغرق دقيقة)...';
          try {
            const result = await App.api.generateCurriculum(selection.stageId);
            App.ui.toast(`تم توليد ${result.subjectCount} مواد و ${result.lectureCount} محاضرة`, 'ok');
            App.views.home();
          } catch (err) {
            App.ui.toast(err.message, 'err');
            genBtn.disabled = false;
            genBtn.innerHTML = '<i class="fas fa-sparkles"></i> توليد المنهج بالذكاء الاصطناعي';
          }
        });
      }
      return;
    }

    holder.innerHTML = `<div class="grid-cards">${subjects
      .map(
        (s) => `
      <div class="subject-card an" data-nav="#/subject/${s.id}">
        <div class="subject-ico" style="${s.color_hex ? `background:${s.color_hex}` : ''}"><i class="fas ${s.icon || 'fa-book'}"></i></div>
        <h4>${App.ui.escapeHtml(s.name_ar)}</h4>
        <p>${App.ui.escapeHtml(s.name_en || '')}</p>
      </div>`
      )
      .join('')}</div>`;

    holder.querySelectorAll('[data-nav]').forEach((el) =>
      el.addEventListener('click', () => (location.hash = el.dataset.nav))
    );
  } catch (err) {
    holder.innerHTML = App.ui.emptyStateHtml('fa-triangle-exclamation', 'تعذّر تحميل المواد', err.message);
  }
};
