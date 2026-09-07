// صفحة نقاطي وإنجازاتي: سجل النقاط + الأوسمة
window.App = window.App || {};
App.views = App.views || {};

App.views.profile = async function profile() {
  const view = document.getElementById('view');
  const user = App.state.getUser();
  view.innerHTML = `
    <div class="an">
      <div class="page-title"><i class="fas fa-chart-simple" style="color:var(--cyan)"></i> نقاطي وإنجازاتي</div>
      <div class="profile-card" style="margin-top:10px">
        <div class="profile-row">
          <div class="profile-av">${App.ui.initials(user.name)}</div>
          <div class="profile-info">
            <h2>${App.ui.escapeHtml(user.name)}</h2>
            <p>${App.ui.escapeHtml(user.email)}</p>
          </div>
        </div>
        <div class="profile-stats">
          <div class="profile-stat"><b id="profilePoints">${user.pointsTotal || 0}</b><span>مجموع النقاط</span></div>
        </div>
      </div>

      <div class="section">
        <div class="section-head"><h3><i class="fas fa-medal"></i> الأوسمة</h3></div>
        <div id="badgesHolder" class="grid-cards">${App.ui.loadingHtml()}</div>
      </div>

      <div class="section">
        <div class="section-head"><h3><i class="fas fa-clock-rotate-left"></i> سجل النقاط</h3></div>
        <div id="historyHolder">${App.ui.loadingHtml()}</div>
      </div>
    </div>
  `;

  try {
    const { pointsTotal, history, badges } = await App.api.getMyPoints();
    document.getElementById('profilePoints').textContent = pointsTotal;

    const badgesHolder = document.getElementById('badgesHolder');
    badgesHolder.innerHTML = badges.length
      ? badges
          .map(
            (b) => `<div class="subject-card">
              <div class="subject-ico" style="background:var(--gradient-gold);color:#3a2900"><i class="fas ${b.icon || 'fa-medal'}"></i></div>
              <h4>${App.ui.escapeHtml(b.name_ar)}</h4>
              <p>${App.ui.formatDate(b.earned_at)}</p>
            </div>`
          )
          .join('')
      : App.ui.emptyStateHtml('fa-medal', 'لا توجد أوسمة بعد', 'أكمل محاضرات واختبارات لكسب أوسمتك الأولى');

    const historyHolder = document.getElementById('historyHolder');
    historyHolder.innerHTML = history.length
      ? history
          .map(
            (h) => `<div class="list-row" style="cursor:default">
              <div class="list-ico ${h.points > 0 ? '' : 'done'}"><i class="fas ${h.points > 0 ? 'fa-plus' : 'fa-minus'}"></i></div>
              <div class="list-body">
                <h4>${App.ui.escapeHtml(App.config.POINTS_LABELS[h.reason] || h.reason)}</h4>
                <p>${App.ui.formatDate(h.created_at)}</p>
              </div>
              <span class="chip ${h.points > 0 ? 'chip-done' : ''}">${h.points > 0 ? '+' : ''}${h.points}</span>
            </div>`
          )
          .join('')
      : App.ui.emptyStateHtml('fa-inbox', 'لا يوجد سجل نقاط بعد');
  } catch (err) {
    App.ui.toast(err.message, 'err');
  }
};
