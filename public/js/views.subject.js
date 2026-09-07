// عرض وحدات مادة معيّنة، وعرض محاضرات وحدة معيّنة
window.App = window.App || {};
App.views = App.views || {};

App.views.subjectUnits = async function subjectUnits({ id }) {
  const view = document.getElementById('view');
  const [subjectRes, unitsRes] = await Promise.all([App.api.getSubject(id), App.api.getUnits(id)]);
  const subject = subjectRes.subject;
  const units = unitsRes.units;

  view.innerHTML = `
    <div class="an">
      <div class="breadcrumb">
        <a href="#/home">الرئيسية</a> <i class="fas fa-chevron-left"></i> <span>${App.ui.escapeHtml(subject.name_ar)}</span>
      </div>
      <div class="page-title"><i class="fas ${subject.icon || 'fa-book'}" style="color:var(--cyan);margin-inline-end:6px"></i>${App.ui.escapeHtml(subject.name_ar)}</div>
      <div class="page-sub">${units.length} وحدة دراسية</div>
      <div id="unitsHolder"></div>
    </div>
  `;

  const holder = document.getElementById('unitsHolder');
  if (!units.length) {
    holder.innerHTML = App.ui.emptyStateHtml('fa-layer-group', 'لا توجد وحدات بعد', 'سيتم إضافتها قريباً');
    return;
  }

  holder.innerHTML = units
    .map(
      (u, i) => `
    <div class="list-row an" data-nav="#/unit/${u.id}" style="animation-delay:${i * 0.05}s">
      <div class="list-ico"><i class="fas fa-layer-group"></i></div>
      <div class="list-body">
        <h4>${App.ui.escapeHtml(u.title_ar)}</h4>
        <p>${App.ui.escapeHtml(u.description || '')}</p>
      </div>
      <span class="chip">${u.lecture_count} محاضرة</span>
    </div>`
    )
    .join('');

  holder.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));
};

App.views.unitLectures = async function unitLectures({ id }) {
  const view = document.getElementById('view');
  const [unitRes, lecturesRes] = await Promise.all([App.api.getUnit(id), App.api.getLectures(id)]);
  const unit = unitRes.unit;
  const lectures = lecturesRes.lectures;

  view.innerHTML = `
    <div class="an">
      <div class="breadcrumb">
        <a href="#/home">الرئيسية</a> <i class="fas fa-chevron-left"></i>
        <a href="#/subject/${unit.subject_id}">${App.ui.escapeHtml(unit.subject_name)}</a> <i class="fas fa-chevron-left"></i>
        <span>${App.ui.escapeHtml(unit.title_ar)}</span>
      </div>
      <div class="page-title"><i class="fas fa-layer-group" style="color:var(--cyan);margin-inline-end:6px"></i>${App.ui.escapeHtml(unit.title_ar)}</div>
      <div class="page-sub">${App.ui.escapeHtml(unit.description || '')}</div>
      <div id="lecturesHolder"></div>
    </div>
  `;

  const holder = document.getElementById('lecturesHolder');
  if (!lectures.length) {
    holder.innerHTML = App.ui.emptyStateHtml('fa-video', 'لا توجد محاضرات بعد', 'سيتم إضافتها قريباً');
    return;
  }

  holder.innerHTML = lectures
    .map(
      (l, i) => `
    <div class="list-row an" data-nav="#/lecture/${l.id}" style="animation-delay:${i * 0.05}s">
      <div class="list-ico"><i class="fas fa-play"></i></div>
      <div class="list-body">
        <h4>${i + 1}. ${App.ui.escapeHtml(l.title_ar)}</h4>
        <p>${App.ui.escapeHtml(l.description || '')}</p>
      </div>
      <span class="list-meta">${App.ui.formatDuration(l.duration_seconds)}</span>
    </div>`
    )
    .join('');

  holder.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));
};
