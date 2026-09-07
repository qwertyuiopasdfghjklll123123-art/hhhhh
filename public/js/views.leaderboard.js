// لوحة المتصدرين: عام أو على مستوى الدولة
window.App = window.App || {};
App.views = App.views || {};

App.views.leaderboard = async function leaderboard() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="an">
      <div class="page-title"><i class="fas fa-ranking-star" style="color:var(--gold)"></i> لوحة المتصدرين</div>
      <div class="page-sub">تنافس مع زملائك واجمع أكبر عدد من النقاط</div>
      <div class="lb-tabs">
        <button class="lb-tab active" data-scope="global">🌍 عالمياً</button>
        <button class="lb-tab" data-scope="country">🏳️ داخل بلدي</button>
      </div>
      <div id="lbHolder">${App.ui.loadingHtml()}</div>
    </div>
  `;

  const holder = document.getElementById('lbHolder');
  const tabs = view.querySelectorAll('.lb-tab');

  async function load(scope) {
    holder.innerHTML = App.ui.loadingHtml();
    try {
      const selection = App.state.getSelection();
      const { leaderboard: rows, me } = await App.api.getLeaderboard(scope, selection.countryId);

      if (!rows.length) {
        holder.innerHTML = App.ui.emptyStateHtml('fa-users', 'لا يوجد طلاب بعد', 'كن أول المتصدرين!');
        return;
      }

      const medal = (rank) => (rank === 1 ? 'top1' : rank === 2 ? 'top2' : rank === 3 ? 'top3' : '');
      const currentUserId = App.state.getUser() ? App.state.getUser().id : null;

      holder.innerHTML = rows
        .map(
          (r) => `
        <div class="lb-row an ${r.user_id === currentUserId ? 'me' : ''}">
          <div class="lb-rank ${medal(r.rank)}">${r.rank <= 3 ? '🏅' : r.rank}</div>
          <div class="lb-av">${App.ui.initials(r.name)}</div>
          <div class="lb-info">
            <h4>${App.ui.escapeHtml(r.name)}</h4>
            <p>${r.country_flag || ''} ${App.ui.escapeHtml(r.country_name_ar || '')}</p>
          </div>
          <div class="lb-points">${r.points_total}</div>
        </div>`
        )
        .join('');

      if (me && !rows.find((r) => r.user_id === me.user_id)) {
        const myRank = scope === 'country' ? me.rank_in_country : me.rank_global;
        holder.innerHTML += `<div class="lb-row me an">
          <div class="lb-rank">${myRank}</div>
          <div class="lb-av">${App.ui.initials(me.name)}</div>
          <div class="lb-info"><h4>${App.ui.escapeHtml(me.name)} (أنت)</h4></div>
          <div class="lb-points">${me.points_total}</div>
        </div>`;
      }
    } catch (err) {
      holder.innerHTML = App.ui.emptyStateHtml('fa-triangle-exclamation', 'تعذّر تحميل لوحة المتصدرين', err.message);
    }
  }

  tabs.forEach((tab) =>
    tab.addEventListener('click', () => {
      tabs.forEach((t) => t.classList.remove('active'));
      tab.classList.add('active');
      load(tab.dataset.scope);
    })
  );

  load('global');
};
