// صفحة المحاضرة: مشغّل الفيديو + تتبع الإكمال + المساعد الذكي (AI Tutor)
window.App = window.App || {};
App.views = App.views || {};

let ytApiPromise = null;
function loadYouTubeApi() {
  if (window.YT && window.YT.Player) return Promise.resolve();
  if (ytApiPromise) return ytApiPromise;
  ytApiPromise = new Promise((resolve) => {
    const prevCallback = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => {
      if (prevCallback) prevCallback();
      resolve();
    };
    const tag = document.createElement('script');
    tag.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(tag);
  });
  return ytApiPromise;
}

App.views.lecture = async function lecture({ id }) {
  const view = document.getElementById('view');
  const { lecture: lec, progress } = await App.api.getLecture(id);
  const loggedIn = App.state.isLoggedIn();
  const isCompleted = Boolean(progress && progress.is_completed);

  const videoInner = lec.youtube_video_id
    ? `<div id="ytPlayer"></div>`
    : `<div class="video-fallback">
         <i class="fas fa-video-slash"></i>
         <div>لم يُعتمد فيديو موثّق لهذه المحاضرة بعد</div>
         ${lec.youtube_url ? `<a class="btn btn-outline btn-sm" href="${lec.youtube_url}" target="_blank" rel="noopener">ابحث عنها في يوتيوب <i class="fas fa-arrow-up-left-from-square"></i></a>` : ''}
       </div>`;

  view.innerHTML = `
    <div class="an">
      <div class="breadcrumb">
        <a href="#/home">الرئيسية</a> <i class="fas fa-chevron-left"></i>
        <a href="#/subject/${lec.subject_id}">${App.ui.escapeHtml(lec.subject_name)}</a> <i class="fas fa-chevron-left"></i>
        <a href="#/unit/${lec.unit_id}">${App.ui.escapeHtml(lec.unit_title)}</a>
      </div>
      <div class="page-title">${App.ui.escapeHtml(lec.title_ar)}</div>
      <div class="page-sub">${App.ui.escapeHtml(lec.description || '')}</div>

      <div class="video-wrap">${videoInner}</div>

      ${
        loggedIn
          ? `<div class="chip ${isCompleted ? 'chip-done' : ''}" id="completionChip" style="margin-bottom:14px">
               <i class="fas ${isCompleted ? 'fa-circle-check' : 'fa-clock'}"></i> ${isCompleted ? 'تم إكمال المشاهدة' : 'لم تكتمل بعد'}
             </div>`
          : `<div class="card" style="margin-bottom:14px;font-size:.78rem;display:flex;justify-content:space-between;align-items:center;gap:10px">
               <span>سجّل دخولك لتتبع تقدمك وكسب النقاط</span>
               <button class="btn btn-primary btn-sm" data-nav="#/login">دخول</button>
             </div>`
      }

      <div style="display:flex;gap:10px;flex-wrap:wrap">
        ${
          loggedIn
            ? `<button class="btn btn-outline" id="markCompleteBtn" ${isCompleted ? 'disabled' : ''}>
                 <i class="fas fa-check"></i> ${isCompleted ? 'تمت المشاهدة' : 'أنهيت المشاهدة'}
               </button>`
            : ''
        }
        <button class="btn btn-primary" id="startQuizBtn"><i class="fas fa-pen-to-square"></i> ابدأ الاختبار</button>
      </div>
    </div>

    <button class="tutor-fab" id="tutorFab" title="المساعد الذكي"><i class="fas fa-robot"></i></button>
    <div class="tutor-panel" id="tutorPanel">
      <div class="tutor-head">
        <h4><i class="fas fa-robot"></i> المساعد الذكي</h4>
        <button class="icon-btn" id="tutorCloseBtn"><i class="fas fa-xmark"></i></button>
      </div>
      <div class="tutor-body" id="tutorBody">
        <div class="tutor-msg assistant">أهلاً بك! أنا مساعدك الذكي لهذه المحاضرة. اسألني عن أي نقطة غامضة 🤓</div>
      </div>
      <form class="tutor-input" id="tutorForm">
        <input type="text" id="tutorInput" placeholder="اكتب سؤالك هنا..." autocomplete="off" ${loggedIn ? '' : 'disabled'}>
        <button class="tutor-send" type="submit" ${loggedIn ? '' : 'disabled'}><i class="fas fa-paper-plane"></i></button>
      </form>
    </div>
  `;

  view.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));

  // ---- تشغيل الفيديو وتتبّع الإكمال ----
  async function markComplete(watchedSeconds) {
    if (!loggedIn || isCompletedNow) return;
    isCompletedNow = true;
    try {
      const res = await App.api.updateProgress(id, { watchedSeconds: watchedSeconds || 0, completed: true });
      const chip = document.getElementById('completionChip');
      if (chip) {
        chip.classList.add('chip-done');
        chip.innerHTML = '<i class="fas fa-circle-check"></i> تم إكمال المشاهدة';
      }
      const btn = document.getElementById('markCompleteBtn');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-check"></i> تمت المشاهدة';
      }
      if (res.pointsAwarded) {
        App.ui.toast(`أحسنت! حصلت على ${res.pointsAwarded} نقطة 🎉`, 'ok');
        App.state.updateUser({ pointsTotal: (App.state.getUser().pointsTotal || 0) + res.pointsAwarded });
        App.main.refreshHeader();
      }
    } catch (err) {
      isCompletedNow = false;
      if (!App.ui.handleAuthError(err)) App.ui.toast(err.message, 'err');
    }
  }
  let isCompletedNow = isCompleted;

  const markBtn = document.getElementById('markCompleteBtn');
  if (markBtn) markBtn.addEventListener('click', () => markComplete(lec.duration_seconds));

  if (lec.youtube_video_id) {
    loadYouTubeApi().then(() => {
      try {
        // eslint-disable-next-line no-new
        new YT.Player('ytPlayer', {
          videoId: lec.youtube_video_id,
          playerVars: { rel: 0 },
          events: {
            onStateChange: (e) => {
              if (e.data === YT.PlayerState.ENDED) markComplete(lec.duration_seconds);
            },
          },
        });
      } catch (err) {
        console.error('YouTube player init failed:', err);
      }
    });
  }

  document.getElementById('startQuizBtn').addEventListener('click', () => {
    if (!loggedIn) {
      App.ui.toast('يرجى تسجيل الدخول لبدء الاختبار', 'err');
      location.hash = '#/login';
      return;
    }
    location.hash = `#/quiz/${id}`;
  });

  // ---- المساعد الذكي (Tutor Chat) ----
  const fab = document.getElementById('tutorFab');
  const panel = document.getElementById('tutorPanel');
  const body = document.getElementById('tutorBody');
  const form = document.getElementById('tutorForm');
  const input = document.getElementById('tutorInput');
  let historyLoaded = false;

  function addMessage(role, text) {
    const div = document.createElement('div');
    div.className = `tutor-msg ${role}`;
    div.textContent = text;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
  }

  fab.addEventListener('click', async () => {
    panel.classList.toggle('open');
    if (panel.classList.contains('open') && !historyLoaded && loggedIn) {
      historyLoaded = true;
      try {
        const { messages } = await App.api.getTutorHistory(id);
        messages.forEach((m) => addMessage(m.role, m.message_text));
      } catch (err) {
        /* تجاهل فشل تحميل السجل، الشات يبقى صالحاً للاستخدام */
      }
    }
  });
  document.getElementById('tutorCloseBtn').addEventListener('click', () => panel.classList.remove('open'));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!loggedIn) {
      location.hash = '#/login';
      return;
    }
    const text = input.value.trim();
    if (!text) return;
    addMessage('user', text);
    input.value = '';
    input.disabled = true;
    const typingEl = document.createElement('div');
    typingEl.className = 'tutor-msg assistant';
    typingEl.innerHTML = '<span class="spinner"></span>';
    body.appendChild(typingEl);
    body.scrollTop = body.scrollHeight;
    try {
      const { reply } = await App.api.sendTutorMessage(id, text);
      typingEl.remove();
      addMessage('assistant', reply);
    } catch (err) {
      typingEl.remove();
      addMessage('assistant', `عذراً، حدث خطأ: ${err.message}`);
    } finally {
      input.disabled = false;
      input.focus();
    }
  });
};
