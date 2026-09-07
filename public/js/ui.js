// دوال مساعدة مشتركة لواجهة المستخدم
window.App = window.App || {};

App.ui = (function () {
  let toastTimer = null;

  function toast(message, type = '') {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = message;
    el.className = 'toast show' + (type ? ` ${type}` : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      el.classList.remove('show');
      setTimeout(() => {
        el.hidden = true;
      }, 250);
    }, 3000);
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function loadingHtml(label = 'جاري التحميل...') {
    return `<div class="loading-block"><span class="spinner"></span> ${escapeHtml(label)}</div>`;
  }

  function emptyStateHtml(icon, title, subtitle = '') {
    return `<div class="center-box">
      <i class="fas ${icon}"></i>
      <div style="font-weight:700;color:var(--text)">${escapeHtml(title)}</div>
      ${subtitle ? `<div style="font-size:.75rem">${escapeHtml(subtitle)}</div>` : ''}
    </div>`;
  }

  function formatDuration(seconds) {
    if (!seconds) return '';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
  }

  function formatDate(value) {
    if (!value) return '';
    try {
      return new Date(value).toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (err) {
      return '';
    }
  }

  function initials(name) {
    if (!name) return '؟';
    return name.trim().slice(0, 1).toUpperCase();
  }

  // يستخرج رابط تضمين يوتيوب صالح من video id أو رابط كامل، أو null إن لم يوجد فيديو معتمد
  function youtubeEmbedUrl(lecture) {
    if (lecture.youtube_video_id) {
      return `https://www.youtube.com/embed/${lecture.youtube_video_id}`;
    }
    return null;
  }

  function handleAuthError(err) {
    if (err && err.status === 401) {
      App.state.clearSession();
      App.main.refreshHeader();
      location.hash = '#/login';
      toast('يرجى تسجيل الدخول مجدداً', 'err');
      return true;
    }
    return false;
  }

  return { toast, escapeHtml, loadingHtml, emptyStateHtml, formatDuration, formatDate, initials, youtubeEmbedUrl, handleAuthError };
})();
