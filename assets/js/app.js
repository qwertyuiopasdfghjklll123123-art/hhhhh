/* =====================================================================
   لوحة إدارة المشاريع الذكية — تفاعلات الواجهة (Vanilla JS بدون اعتماديات)
   ===================================================================== */
(function () {
  'use strict';

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  /** غلاف موحّد لاستدعاءات AJAX نحو نقاط api/*.php مع رأس CSRF تلقائي */
  async function apiFetch(url, options) {
    options = options || {};
    var headers = Object.assign({
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': csrfToken(),
    }, options.headers || {});

    var body = options.body;
    if (body && typeof body === 'object' && !(body instanceof FormData)) {
      body = JSON.stringify(body);
      headers['Content-Type'] = 'application/json';
    }

    var res;
    try {
      res = await fetch(url, Object.assign({}, options, { headers: headers, body: body }));
    } catch (e) {
      return { success: false, error: 'تعذّر الاتصال بالسيرفر. تحقق من اتصال الشبكة.' };
    }

    var data;
    try {
      data = await res.json();
    } catch (e) {
      data = { success: false, error: 'استجابة غير صالحة من السيرفر (HTTP ' + res.status + ').' };
    }
    if (typeof data.success === 'undefined') {
      data.success = res.ok;
    }
    return data;
  }

  /* ------------------------------------------------------------------ */
  /* القائمة الجانبية على الشاشات الصغيرة                                 */
  /* ------------------------------------------------------------------ */
  var appShell = document.getElementById('appShell');
  var mobileToggle = document.getElementById('mobileToggle');
  var appOverlay = document.getElementById('appOverlay');

  if (mobileToggle && appShell) {
    mobileToggle.addEventListener('click', function () {
      appShell.classList.toggle('sidebar-open');
    });
  }
  if (appOverlay && appShell) {
    appOverlay.addEventListener('click', function () {
      appShell.classList.remove('sidebar-open');
    });
  }

  /* ------------------------------------------------------------------ */
  /* نوافذ منبثقة عامة                                                    */
  /* ------------------------------------------------------------------ */
  function openModal(id) {
    var el = document.getElementById(id);
    if (el) { el.classList.add('open'); }
  }
  function closeModal(fromEl) {
    var backdrop = fromEl.closest('.modal-backdrop');
    if (backdrop) { backdrop.classList.remove('open'); }
  }

  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-action="open-modal"]');
    if (openBtn) { openModal(openBtn.getAttribute('data-modal')); return; }

    var closeBtn = e.target.closest('[data-action="close-modal"]');
    if (closeBtn) { closeModal(closeBtn); return; }

    if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
      e.target.classList.remove('open');
      return;
    }

    var toggleBtn = e.target.closest('[data-action="toggle-visibility"]');
    if (toggleBtn) {
      var input = document.getElementById(toggleBtn.getAttribute('data-target'));
      if (input) {
        var icon = toggleBtn.querySelector('i');
        if (input.type === 'password') {
          input.type = 'text';
          if (icon) { icon.className = 'fa-solid fa-eye-slash'; }
        } else {
          input.type = 'password';
          if (icon) { icon.className = 'fa-solid fa-eye'; }
        }
      }
      return;
    }

    var editUserBtn = e.target.closest('[data-action="edit-user"]');
    if (editUserBtn) {
      setValue('editUserId', editUserBtn.getAttribute('data-id'));
      setValue('editUserName', editUserBtn.getAttribute('data-name'));
      setValue('editUserEmail', editUserBtn.getAttribute('data-email'));
      setValue('editUserRole', editUserBtn.getAttribute('data-role') || 'user');
      openModal('modalEditUser');
      return;
    }

    var resetBtn = e.target.closest('[data-action="reset-password"]');
    if (resetBtn) {
      setValue('resetPasswordId', resetBtn.getAttribute('data-id'));
      var nameEl = document.getElementById('resetPasswordName');
      if (nameEl) { nameEl.textContent = resetBtn.getAttribute('data-name') || ''; }
      openModal('modalResetPassword');
      return;
    }

    var addProviderBtn = e.target.closest('[data-action="open-provider-modal"]');
    if (addProviderBtn) {
      resetProviderModal();
      openModal('modalProvider');
      return;
    }

    var copyTextBtn = e.target.closest('[data-action="copy-text"]');
    if (copyTextBtn) {
      var copySource = document.getElementById(copyTextBtn.getAttribute('data-copy-target'));
      if (copySource && navigator.clipboard && navigator.clipboard.writeText) {
        var copyIcon = copyTextBtn.querySelector('i');
        var prevIconClass = copyIcon ? copyIcon.className : '';
        navigator.clipboard.writeText(copySource.textContent || '').then(function () {
          if (copyIcon) { copyIcon.className = 'fa-solid fa-check'; }
          setTimeout(function () { if (copyIcon) { copyIcon.className = prevIconClass; } }, 1500);
        }).catch(function () {});
      }
      return;
    }

    var editProviderBtn = e.target.closest('[data-action="edit-provider"]');
    if (editProviderBtn) {
      resetProviderModal();
      setValue('providerFormAction', 'update_provider');
      setValue('providerId', editProviderBtn.getAttribute('data-id'));
      setValue('providerLabel', editProviderBtn.getAttribute('data-label'));
      setValue('providerBaseUrl', editProviderBtn.getAttribute('data-base-url'));
      setValue('providerTextModel', editProviderBtn.getAttribute('data-text-model'));
      setValue('providerVisionModel', editProviderBtn.getAttribute('data-vision-model'));
      setValue('providerTokenBudget', editProviderBtn.getAttribute('data-token-budget'));
      var titleEl = document.getElementById('providerModalTitle');
      if (titleEl) { titleEl.innerHTML = '<i class="fa-solid fa-microchip"></i> تعديل مزوّد ذكاء اصطناعي'; }
      var keyHint = document.getElementById('providerKeyHint');
      if (keyHint) { keyHint.textContent = 'اترك الحقل فارغاً للإبقاء على المفتاح الحالي كما هو.'; }
      var submitBtn = document.getElementById('providerSubmitBtn');
      if (submitBtn) { submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> حفظ التعديلات'; }
      openModal('modalProvider');
      return;
    }
  });

  /** يعيد نموذج مزوّد الذكاء الاصطناعي المنبثق إلى وضع "إضافة" الافتراضي */
  function resetProviderModal() {
    var form = document.getElementById('providerForm');
    if (!form) { return; }
    form.reset();
    setValue('providerFormAction', 'add_provider');
    setValue('providerId', '');
    var titleEl = document.getElementById('providerModalTitle');
    if (titleEl) { titleEl.innerHTML = '<i class="fa-solid fa-microchip"></i> إضافة مزوّد ذكاء اصطناعي'; }
    var keyHint = document.getElementById('providerKeyHint');
    if (keyHint) { keyHint.textContent = 'يُشفَّر قبل التخزين ولا يظهر لأي مستخدم بعد حفظه.'; }
    var submitBtn = document.getElementById('providerSubmitBtn');
    if (submitBtn) { submitBtn.innerHTML = '<i class="fa-solid fa-check"></i> إضافة'; }
  }

  function setValue(id, value) {
    var el = document.getElementById(id);
    if (el) { el.value = value || ''; }
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form && form.hasAttribute && form.hasAttribute('data-confirm')) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(function (m) {
        m.classList.remove('open');
      });
    }
  });

  if (window.location.hash === '#new') {
    openModal('modalNewProject');
  }

  document.querySelectorAll('.alert-success').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .4s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    }, 5000);
  });

  /* ------------------------------------------------------------------ */
  /* إرفاق ملف نصي عند إضافة سياق Skill جديد (تبويب Skill بصفحة project_context.php) */
  /* ------------------------------------------------------------------ */
  var skillFileInput = document.getElementById('skillFileInput');
  if (skillFileInput) {
    var skillContent = document.getElementById('skillContent');
    var skillTitleInput = document.getElementById('skillTitleInput');

    skillFileInput.addEventListener('change', function () {
      var file = skillFileInput.files && skillFileInput.files[0];
      if (!file) { return; }
      if (file.size > 2 * 1024 * 1024) {
        window.alert('حجم الملف كبير جداً (الحد الأقصى 2MB).');
        skillFileInput.value = '';
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        skillContent.value = String(reader.result || '');
        if (skillTitleInput && !skillTitleInput.value.trim()) {
          skillTitleInput.value = file.name;
        }
      };
      reader.readAsText(file);
      skillFileInput.value = '';
    });
  }

  /* ------------------------------------------------------------------ */
  /* اختيار مستودع GitHub من قائمة مستودعات المستخدم الفعلية بدل كتابة   */
  /* Owner/Repo يدوياً (تبويب الإعدادات بصفحة project_context.php)        */
  /* ------------------------------------------------------------------ */
  var btnPickRepo = document.getElementById('btnPickRepo');
  if (btnPickRepo) {
    var repoPickerList = document.getElementById('repoPickerList');
    var repoPickerSearch = document.getElementById('repoPickerSearch');
    var githubOwnerInput = document.getElementById('githubOwnerInput');
    var githubRepoInput = document.getElementById('githubRepoInput');
    var githubBranchInput = document.getElementById('githubBranchInput');
    var repoPickerLoaded = null;

    var renderRepoList = function (repos, filterText) {
      repoPickerList.innerHTML = '';
      var filtered = repos;
      if (filterText) {
        var q = filterText.toLowerCase();
        filtered = repos.filter(function (r) { return r.full_name.toLowerCase().indexOf(q) !== -1; });
      }
      if (!filtered.length) {
        repoPickerList.innerHTML = '<div class="empty-state"><i class="fa-brands fa-github"></i><p>لا توجد نتائج مطابقة.</p></div>';
        return;
      }
      filtered.forEach(function (r) {
        var row = document.createElement('div');
        row.className = 'repo-item';
        var icon = document.createElement('i');
        icon.className = r.private ? 'fa-solid fa-lock' : 'fa-brands fa-github';
        row.appendChild(icon);
        var name = document.createElement('span');
        name.className = 'repo-item-name';
        name.textContent = r.full_name;
        row.appendChild(name);
        if (r.private) {
          var badge = document.createElement('span');
          badge.className = 'badge badge-disabled';
          badge.textContent = 'خاص';
          row.appendChild(badge);
        }
        row.addEventListener('click', function () {
          if (githubOwnerInput) { githubOwnerInput.value = r.owner; }
          if (githubRepoInput) { githubRepoInput.value = r.name; }
          if (githubBranchInput) { githubBranchInput.value = r.default_branch || 'main'; }
          var backdrop = repoPickerList.closest('.modal-backdrop');
          if (backdrop) { backdrop.classList.remove('open'); }
        });
        repoPickerList.appendChild(row);
      });
    };

    btnPickRepo.addEventListener('click', async function () {
      openModal('modalRepoPicker');
      if (repoPickerLoaded) { renderRepoList(repoPickerLoaded, repoPickerSearch ? repoPickerSearch.value.trim() : ''); return; }
      repoPickerList.innerHTML = '<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>يتم التحميل...</p></div>';
      var data = await apiFetch('api/github_repos.php');
      if (!data.success) {
        repoPickerList.innerHTML = '';
        var err = document.createElement('div');
        err.className = 'empty-state';
        err.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><p></p>';
        err.querySelector('p').textContent = data.error || 'تعذّر جلب المستودعات.';
        repoPickerList.appendChild(err);
        return;
      }
      repoPickerLoaded = data.repos || [];
      renderRepoList(repoPickerLoaded, '');
    });

    if (repoPickerSearch) {
      repoPickerSearch.addEventListener('input', function () {
        if (repoPickerLoaded) { renderRepoList(repoPickerLoaded, repoPickerSearch.value.trim()); }
      });
    }
  }

  /* ------------------------------------------------------------------ */
  /* وحدة المساعد الذكي (تعمل فقط داخل صفحة project_context.php)          */
  /* ------------------------------------------------------------------ */
  var chatLayout = document.getElementById('chatLayout');
  if (chatLayout) {
    initChat(chatLayout);
  }

  function initChat(root) {
    var projectId = root.getAttribute('data-project-id');
    var hasGithub = root.getAttribute('data-has-github') === '1';
    var mode = root.getAttribute('data-mode') === 'code' ? 'code' : 'chat';
    var sendEndpoint = mode === 'code' ? 'api/code_chat.php' : 'api/messages.php';
    var codeConvId = parseInt(root.getAttribute('data-code-conv-id'), 10) || 0;
    var providersData = [];
    try { providersData = JSON.parse(root.getAttribute('data-providers') || '[]'); } catch (e) { providersData = []; }
    var providerStorageKey = 'pmdash_provider_' + projectId + '_' + mode;

    var chatMessages = document.getElementById('chatMessages');
    var chatWelcome = document.getElementById('chatWelcome');
    var chatForm = document.getElementById('chatForm');
    var chatInput = document.getElementById('chatInput');
    var chatAttachments = document.getElementById('chatAttachments');
    var chatRailList = document.getElementById('chatRailList');
    var btnNewChat = document.getElementById('btnNewChat');
    var btnSendChat = document.getElementById('btnSendChat');
    var chatImageInput = document.getElementById('chatImageInput');
    var btnAttachGithub = document.getElementById('btnAttachGithub');
    var providerSelect = document.getElementById('providerSelect');
    var providerInfoCard = document.getElementById('providerInfoCard');

    var modalGithubBrowse = document.getElementById('modalGithubBrowse');
    var githubPathBar = document.getElementById('githubPathBar');
    var githubFileList = document.getElementById('githubFileList');

    var commitPath = document.getElementById('commitPath');
    var commitContent = document.getElementById('commitContent');
    var commitMessage = document.getElementById('commitMessage');
    var commitStatus = document.getElementById('commitStatus');
    var btnConfirmCommit = document.getElementById('btnConfirmCommit');

    var btnPickRepo = document.getElementById('btnPickRepo');
    var repoPickerList = document.getElementById('repoPickerList');
    var repoPickerSearch = document.getElementById('repoPickerSearch');
    var repoPickerLoaded = null;

    var currentConversationId = null;
    var pendingAttachment = null;
    var pendingImage = null;
    var sending = false;

    /* -------------------------------------------------------------- */
    /* تذكّر آخر مزوّد ذكاء اصطناعي مُختار لهذا المشروع/الوضع، حتى قبل    */
    /* إنشاء أي محادثة فعلية - يبقى المزوّد نفسه عند إرسال رسالة تالية   */
    /* أو حتى بعد إغلاق المشروع وإعادة فتحه.                             */
    /* -------------------------------------------------------------- */
    function findProvider(id) {
      var idStr = String(id);
      for (var i = 0; i < providersData.length; i++) {
        if (String(providersData[i].id) === idStr) { return providersData[i]; }
      }
      return null;
    }

    function updateProviderInfoCard() {
      if (!providerInfoCard || !providerSelect) { return; }
      var p = findProvider(providerSelect.value);
      if (!p) { providerInfoCard.style.display = 'none'; return; }
      providerInfoCard.innerHTML = '';
      var icon = document.createElement('i');
      icon.className = 'fa-solid fa-microchip';
      providerInfoCard.appendChild(icon);
      var text = document.createElement('span');
      var strong = document.createElement('strong');
      strong.textContent = p.label;
      text.appendChild(strong);
      text.appendChild(document.createTextNode(' · '));
      var modelSpan = document.createElement('span');
      modelSpan.className = 'provider-info-model';
      modelSpan.textContent = p.text_model || '';
      text.appendChild(modelSpan);
      if (p.vision_model) {
        text.appendChild(document.createTextNode(' '));
        var visionTag = document.createElement('i');
        visionTag.className = 'fa-regular fa-image';
        visionTag.title = 'يدعم الصور مباشرة';
        text.appendChild(visionTag);
      }
      providerInfoCard.appendChild(text);
      providerInfoCard.style.display = 'flex';
    }

    function rememberProviderChoice() {
      if (!providerSelect || !providerSelect.value) { return; }
      try { window.localStorage.setItem(providerStorageKey, providerSelect.value); } catch (e) { /* تجاهل (وضع تصفح خاص مثلاً) */ }
    }

    function setProviderSelectValue(id) {
      if (!providerSelect || !id) { return; }
      if (findProvider(id)) {
        providerSelect.value = String(id);
        updateProviderInfoCard();
      }
    }

    if (providerSelect) {
      var storedProvider = null;
      try { storedProvider = window.localStorage.getItem(providerStorageKey); } catch (e) { storedProvider = null; }
      if (storedProvider) { setProviderSelectValue(storedProvider); }
      updateProviderInfoCard();
      providerSelect.addEventListener('change', function () {
        updateProviderInfoCard();
        rememberProviderChoice();
      });
    }

    function scrollToBottom() { chatMessages.scrollTop = chatMessages.scrollHeight; }
    function clearMessages() { chatMessages.innerHTML = ''; }
    function showWelcome() {
      clearMessages();
      if (chatWelcome) { chatMessages.appendChild(chatWelcome); }
    }

    function buildCodeBlock(lang, code) {
      var wrap = document.createElement('div');
      wrap.className = 'code-block';

      var toolbar = document.createElement('div');
      toolbar.className = 'code-block-toolbar';
      var langSpan = document.createElement('span');
      langSpan.className = 'code-lang';
      langSpan.textContent = lang || 'text';
      toolbar.appendChild(langSpan);

      var actions = document.createElement('div');
      actions.className = 'code-block-actions';

      var copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i> نسخ';
      copyBtn.addEventListener('click', function () {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(code).then(function () {
            copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> تم النسخ';
            setTimeout(function () { copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i> نسخ'; }, 1500);
          }).catch(function () {});
        }
      });
      actions.appendChild(copyBtn);

      if (hasGithub && mode !== 'code') {
        var commitBtn = document.createElement('button');
        commitBtn.type = 'button';
        commitBtn.innerHTML = '<i class="fa-solid fa-code-commit"></i> رفع إلى GitHub';
        commitBtn.addEventListener('click', function () {
          commitContent.value = code;
          commitPath.value = pendingAttachment ? pendingAttachment.path : '';
          commitStatus.style.display = 'none';
          openModal('modalCommit');
        });
        actions.appendChild(commitBtn);
      }

      toolbar.appendChild(actions);
      wrap.appendChild(toolbar);

      var pre = document.createElement('pre');
      var codeEl = document.createElement('code');
      codeEl.textContent = code;
      pre.appendChild(codeEl);
      wrap.appendChild(pre);

      return wrap;
    }

    /**
     * ينسّق النص الداخلي (عريض/مائل/كود مضمّن) عبر عقد DOM فقط — بلا أي
     * innerHTML لنص خارجي، لضمان الحماية من XSS بغض النظر عن مصدر النص
     * (رد نموذج، أو حتى محتوى ملف من GitHub في قسم الكود).
     */
    function renderInline(container, text) {
      var re = /\*\*([^*]+)\*\*|`([^`]+)`|\*([^*]+)\*/g;
      var lastIndex = 0;
      var match;
      while ((match = re.exec(text)) !== null) {
        if (match.index > lastIndex) {
          container.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
        }
        var el;
        if (match[1] !== undefined) { el = document.createElement('strong'); el.textContent = match[1]; }
        else if (match[2] !== undefined) { el = document.createElement('code'); el.textContent = match[2]; }
        else { el = document.createElement('em'); el.textContent = match[3]; }
        container.appendChild(el);
        lastIndex = re.lastIndex;
      }
      if (lastIndex < text.length) {
        container.appendChild(document.createTextNode(text.slice(lastIndex)));
      }
    }

    function buildMarkdownTable(lines) {
      var wrap = document.createElement('div');
      wrap.className = 'msg-table-wrap';
      var table = document.createElement('table');
      table.className = 'msg-table';
      var splitRow = function (line) {
        return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) { return c.trim(); });
      };
      var thead = document.createElement('thead');
      var headRow = document.createElement('tr');
      splitRow(lines[0]).forEach(function (cell) {
        var th = document.createElement('th');
        renderInline(th, cell);
        headRow.appendChild(th);
      });
      thead.appendChild(headRow);
      table.appendChild(thead);
      var tbody = document.createElement('tbody');
      for (var r = 2; r < lines.length; r++) {
        var tr = document.createElement('tr');
        splitRow(lines[r]).forEach(function (cell) {
          var td = document.createElement('td');
          renderInline(td, cell);
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      wrap.appendChild(table);
      return wrap;
    }

    /** ينسّق فقرة نصية (بلا كتل كود ```) بأسلوب Markdown خفيف: عناوين، قوائم، جداول، فقرات */
    function renderMarkdownBlock(container, text) {
      var lines = String(text).replace(/\r\n/g, '\n').split('\n');
      var paraBuf = [];
      var i = 0;

      function flushPara() {
        if (!paraBuf.length) { return; }
        var p = document.createElement('p');
        renderInline(p, paraBuf.join(' '));
        container.appendChild(p);
        paraBuf = [];
      }

      while (i < lines.length) {
        var line = lines[i];

        if (!line.trim()) { flushPara(); i++; continue; }

        var headerMatch = line.match(/^(#{1,4})\s+(.*)$/);
        if (headerMatch) {
          flushPara();
          var h = document.createElement('div');
          h.className = 'msg-heading msg-heading-' + headerMatch[1].length;
          renderInline(h, headerMatch[2]);
          container.appendChild(h);
          i++; continue;
        }

        if (/^\s*\|.*\|\s*$/.test(line) && lines[i + 1] && /^\s*\|?[\s:|-]+\|?\s*$/.test(lines[i + 1])) {
          flushPara();
          var tableLines = [line, lines[i + 1]];
          var k = i + 2;
          while (k < lines.length && /^\s*\|.*\|\s*$/.test(lines[k])) { tableLines.push(lines[k]); k++; }
          container.appendChild(buildMarkdownTable(tableLines));
          i = k; continue;
        }

        if (/^\s*([-*]|\d+\.)\s+/.test(line)) {
          flushPara();
          var ordered = /^\s*\d+\./.test(line);
          var list = document.createElement(ordered ? 'ol' : 'ul');
          while (i < lines.length) {
            var m = lines[i].match(/^\s*(?:[-*]|\d+\.)\s+(.*)$/);
            if (!m) { break; }
            var li = document.createElement('li');
            renderInline(li, m[1]);
            list.appendChild(li);
            i++;
          }
          container.appendChild(list);
          continue;
        }

        paraBuf.push(line.trim());
        i++;
      }
      flushPara();
    }

    function renderContent(container, text) {
      var parts = String(text).split(/```(\w*)\n([\s\S]*?)```/g);
      for (var i = 0; i < parts.length; i += 3) {
        var plain = parts[i];
        if (plain) { renderMarkdownBlock(container, plain); }
        var lang = parts[i + 1];
        var code = parts[i + 2];
        if (typeof code === 'string') {
          container.appendChild(buildCodeBlock(lang, code));
        }
      }
    }

    function appendAttachmentNote(bubble, iconClass, text) {
      var note = document.createElement('span');
      note.className = 'msg-attachment-note';
      var icon = document.createElement('i');
      icon.className = iconClass;
      note.appendChild(icon);
      note.appendChild(document.createTextNode(' ' + text));
      bubble.appendChild(note);
      bubble.appendChild(document.createElement('br'));
    }

    function appendMessage(role, content, meta) {
      if (chatWelcome && chatWelcome.parentNode === chatMessages) {
        chatMessages.innerHTML = '';
      }
      var msg = document.createElement('div');
      msg.className = 'msg ' + (role === 'user' ? 'msg-user' : 'msg-assistant');

      var avatar = document.createElement('div');
      avatar.className = 'msg-avatar';
      avatar.innerHTML = role === 'user' ? '<i class="fa-solid fa-user"></i>' : '<i class="fa-solid fa-wand-magic-sparkles"></i>';

      var bubble = document.createElement('div');
      bubble.className = 'msg-bubble';

      var providerLabel = null;
      var reasoningText = null;
      if (meta) {
        try {
          var m = typeof meta === 'string' ? JSON.parse(meta) : meta;
          if (m && m.path) { appendAttachmentNote(bubble, 'fa-brands fa-github', m.path); }
          if (m && m.image) { appendAttachmentNote(bubble, 'fa-regular fa-image', m.image); }
          if (m && m.vision_relay) { appendAttachmentNote(bubble, 'fa-solid fa-eye', 'حُلِّلت الصورة تلقائياً بواسطة ' + m.vision_relay); }
          if (m && m.files_read && m.files_read.length) {
            appendAttachmentNote(bubble, 'fa-solid fa-folder-open', 'اطّلع على: ' + m.files_read.join('، '));
          }
          if (m && m.files_written && m.files_written.length) {
            var writtenNames = m.files_written.map(function (w) { return w.path; }).join('، ');
            appendAttachmentNote(bubble, 'fa-solid fa-code-commit', 'رُفع تلقائياً إلى GitHub: ' + writtenNames);
          }
          if (m && m.provider) { providerLabel = m.provider; }
          if (m && m.reasoning) { reasoningText = m.reasoning; }
        } catch (e) { /* تجاهل بيانات meta غير صالحة */ }
      }

      if (reasoningText) {
        var details = document.createElement('details');
        details.className = 'msg-reasoning';
        var summary = document.createElement('summary');
        summary.innerHTML = '<i class="fa-solid fa-brain"></i> تفكير النموذج';
        details.appendChild(summary);
        var reasoningBody = document.createElement('div');
        reasoningBody.className = 'msg-reasoning-body';
        renderContent(reasoningBody, reasoningText);
        details.appendChild(reasoningBody);
        bubble.appendChild(details);
      }

      renderContent(bubble, content);

      if (providerLabel) {
        var tag = document.createElement('div');
        tag.className = 'msg-provider-tag';
        tag.innerHTML = '<i class="fa-solid fa-microchip"></i>';
        tag.appendChild(document.createTextNode(' ' + providerLabel));
        bubble.appendChild(tag);
      }

      msg.appendChild(avatar);
      msg.appendChild(bubble);
      chatMessages.appendChild(msg);
      scrollToBottom();
      return msg;
    }

    function appendTyping() {
      var msg = document.createElement('div');
      msg.className = 'msg msg-assistant';
      msg.id = 'typingIndicator';
      msg.innerHTML = '<div class="msg-avatar"><i class="fa-solid fa-wand-magic-sparkles"></i></div>' +
        '<div class="msg-bubble"><div class="msg-typing"><span></span><span></span><span></span></div></div>';
      chatMessages.appendChild(msg);
      scrollToBottom();
    }
    function removeTyping() {
      var el = document.getElementById('typingIndicator');
      if (el) { el.remove(); }
    }

    function setActiveConv(id) {
      currentConversationId = id;
      document.querySelectorAll('.conv-item').forEach(function (el) {
        el.classList.toggle('active', String(el.getAttribute('data-conv-id')) === String(id));
      });
    }

    function addConvToRail(id, title) {
      if (!chatRailList) { return; }
      var empty = chatRailList.querySelector('.chat-rail-empty');
      if (empty) { empty.remove(); }
      var item = document.createElement('div');
      item.className = 'conv-item active';
      item.setAttribute('data-conv-id', String(id));

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'conv-item-btn';
      btn.innerHTML = '<i class="fa-regular fa-message"></i>';
      var textSpan = document.createElement('span');
      textSpan.className = 'conv-item-text';
      textSpan.textContent = title;
      btn.appendChild(textSpan);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'conv-item-del';
      delBtn.setAttribute('data-action', 'delete-conv');
      delBtn.setAttribute('data-id', String(id));
      delBtn.title = 'حذف المحادثة';
      delBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';

      item.appendChild(btn);
      item.appendChild(delBtn);
      chatRailList.insertBefore(item, chatRailList.firstChild);

      document.querySelectorAll('.conv-item').forEach(function (el) {
        if (el !== item) { el.classList.remove('active'); }
      });
    }

    async function loadConversation(id) {
      setActiveConv(id);
      clearMessages();
      var loading = document.createElement('div');
      loading.className = 'empty-state';
      loading.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><p>جاري التحميل...</p>';
      chatMessages.appendChild(loading);

      var data = await apiFetch('api/messages.php?conversation_id=' + encodeURIComponent(id));
      clearMessages();
      if (!data.success) {
        appendMessage('assistant', 'تعذّر تحميل المحادثة: ' + (data.error || ''));
        return;
      }
      if (data.conversation && data.conversation.provider_id) {
        setProviderSelectValue(data.conversation.provider_id);
      }
      if (!data.messages || !data.messages.length) { showWelcome(); return; }
      data.messages.forEach(function (m) { appendMessage(m.role, m.content, m.meta); });
    }

    if (chatRailList) {
      chatRailList.addEventListener('click', function (e) {
        var delBtn = e.target.closest('[data-action="delete-conv"]');
        if (delBtn) {
          e.stopPropagation();
          var id = delBtn.getAttribute('data-id');
          if (!window.confirm('حذف هذه المحادثة نهائياً؟')) { return; }
          apiFetch('api/conversations.php', { method: 'POST', body: { action: 'delete', conversation_id: id } }).then(function (res) {
            if (res.success) {
              var item = delBtn.closest('.conv-item');
              if (item) { item.remove(); }
              if (String(currentConversationId) === String(id)) {
                currentConversationId = null;
                showWelcome();
              }
              if (!chatRailList.querySelector('.conv-item')) {
                chatRailList.innerHTML = '<p class="chat-rail-empty">لا توجد محادثات بعد.</p>';
              }
            }
          });
          return;
        }
        var item = e.target.closest('.conv-item');
        if (item) {
          var convId = item.getAttribute('data-conv-id');
          if (String(convId) !== String(currentConversationId)) { loadConversation(convId); }
        }
      });
    }

    if (btnNewChat) {
      btnNewChat.addEventListener('click', function () {
        currentConversationId = null;
        document.querySelectorAll('.conv-item').forEach(function (el) { el.classList.remove('active'); });
        showWelcome();
        clearAttachments();
        chatInput.focus();
      });
    }

    function renderAttachments() {
      chatAttachments.innerHTML = '';
      if (pendingAttachment) {
        var chip = document.createElement('span');
        chip.className = 'attachment-chip';
        chip.innerHTML = '<i class="fa-brands fa-github"></i>';
        var span = document.createElement('span');
        span.textContent = pendingAttachment.path;
        chip.appendChild(span);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        removeBtn.addEventListener('click', function () { pendingAttachment = null; renderAttachments(); });
        chip.appendChild(removeBtn);
        chatAttachments.appendChild(chip);
      }
      if (pendingImage) {
        var imgChip = document.createElement('span');
        imgChip.className = 'attachment-chip';
        var img = document.createElement('img');
        img.src = pendingImage.dataUrl;
        img.alt = pendingImage.name;
        imgChip.appendChild(img);
        var label = document.createElement('span');
        label.textContent = pendingImage.name;
        imgChip.appendChild(label);
        var removeImgBtn = document.createElement('button');
        removeImgBtn.type = 'button';
        removeImgBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        removeImgBtn.addEventListener('click', function () { pendingImage = null; chatImageInput.value = ''; renderAttachments(); });
        imgChip.appendChild(removeImgBtn);
        chatAttachments.appendChild(imgChip);
      }
    }
    function clearAttachments() { pendingAttachment = null; pendingImage = null; renderAttachments(); }

    if (chatImageInput) {
      chatImageInput.addEventListener('change', function () {
        var file = chatImageInput.files && chatImageInput.files[0];
        if (!file) { return; }
        if (file.size > 6 * 1024 * 1024) {
          window.alert('حجم الصورة كبير جداً (الحد الأقصى 6MB).');
          chatImageInput.value = '';
          return;
        }
        var reader = new FileReader();
        reader.onload = function () {
          var dataUrl = String(reader.result);
          pendingImage = { base64: dataUrl.split(',')[1] || '', mime: file.type, name: file.name, dataUrl: dataUrl };
          renderAttachments();
        };
        reader.readAsDataURL(file);
      });
    }

    function renderBreadcrumb(path) {
      githubPathBar.innerHTML = '';
      var rootBtn = document.createElement('button');
      rootBtn.type = 'button';
      rootBtn.innerHTML = '<i class="fa-solid fa-house"></i> الجذر';
      rootBtn.addEventListener('click', function () { loadGithubDir(''); });
      githubPathBar.appendChild(rootBtn);
      if (!path) { return; }
      var acc = '';
      path.split('/').forEach(function (seg) {
        acc = acc ? acc + '/' + seg : seg;
        githubPathBar.appendChild(document.createTextNode(' / '));
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = seg;
        var target = acc;
        btn.addEventListener('click', function () { loadGithubDir(target); });
        githubPathBar.appendChild(btn);
      });
    }

    function formatSize(bytes) {
      bytes = Number(bytes) || 0;
      if (bytes < 1024) { return bytes + ' B'; }
      if (bytes < 1024 * 1024) { return Math.round(bytes / 1024) + ' KB'; }
      return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    async function loadGithubDir(path) {
      renderBreadcrumb(path);
      githubFileList.innerHTML = '<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>يتم التحميل...</p></div>';
      var data = await apiFetch('api/github_file.php?action=tree&project_id=' + encodeURIComponent(projectId) + '&path=' + encodeURIComponent(path));
      githubFileList.innerHTML = '';
      if (!data.success) {
        var err = document.createElement('div');
        err.className = 'empty-state';
        err.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><p></p>';
        err.querySelector('p').textContent = data.error || 'تعذّر جلب الملفات.';
        githubFileList.appendChild(err);
        return;
      }
      if (!data.items || !data.items.length) {
        githubFileList.innerHTML = '<div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>مجلد فارغ.</p></div>';
        return;
      }
      data.items.forEach(function (item) {
        var row = document.createElement('div');
        row.className = 'gh-item ' + (item.type === 'dir' ? 'gh-item-folder' : 'gh-item-file');
        var icon = document.createElement('i');
        icon.className = item.type === 'dir' ? 'fa-solid fa-folder' : 'fa-regular fa-file-lines';
        var name = document.createElement('span');
        name.textContent = item.name;
        row.appendChild(icon);
        row.appendChild(name);
        if (item.type !== 'dir') {
          var size = document.createElement('span');
          size.className = 'gh-item-size';
          size.textContent = formatSize(item.size);
          row.appendChild(size);
        }
        row.addEventListener('click', function () {
          if (item.type === 'dir') { loadGithubDir(item.path); } else { selectGithubFile(item.path); }
        });
        githubFileList.appendChild(row);
      });
    }

    async function selectGithubFile(path) {
      var data = await apiFetch('api/github_file.php?action=get&project_id=' + encodeURIComponent(projectId) + '&path=' + encodeURIComponent(path));
      if (!data.success) {
        window.alert(data.error || 'تعذّر جلب الملف.');
        return;
      }
      pendingAttachment = { path: data.path };
      renderAttachments();
      closeModal(modalGithubBrowse);
    }

    if (btnAttachGithub) {
      btnAttachGithub.addEventListener('click', function () {
        openModal('modalGithubBrowse');
        loadGithubDir('');
      });
    }

    function autoGrow() {
      chatInput.style.height = 'auto';
      chatInput.style.height = Math.min(chatInput.scrollHeight, 160) + 'px';
    }
    if (chatInput) {
      chatInput.addEventListener('input', autoGrow);
      chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          if (chatForm.requestSubmit) { chatForm.requestSubmit(); } else { sendMessage(); }
        }
      });
    }

    /** وضع الكود: يبقى على نمط طلب/رد واحد (بلا بث) لأن الرد النهائي قد يمرّ بجولات جلب ملفات داخلية أولاً */
    async function sendMessageNonStreaming(payload) {
      appendTyping();
      var data = await apiFetch(sendEndpoint, { method: 'POST', body: payload });
      removeTyping();

      if (!data.success) {
        appendMessage('assistant', 'تعذّر الحصول على رد: ' + (data.error || 'خطأ غير معروف.'));
        return;
      }
      if (!currentConversationId) {
        addConvToRail(data.conversation_id, data.title || 'محادثة جديدة');
        currentConversationId = data.conversation_id;
      }
      var replyMeta = null;
      if (data.provider || data.reasoning || (data.files_read && data.files_read.length)) {
        replyMeta = {};
        if (data.provider) { replyMeta.provider = data.provider; }
        if (data.reasoning) { replyMeta.reasoning = data.reasoning; }
        if (data.files_read && data.files_read.length) { replyMeta.files_read = data.files_read; }
      }
      appendMessage('assistant', data.reply, replyMeta);
    }

    /** وضع الدردشة العادية: يقرأ استجابة SSE من api/messages.php ويعرض النص تدريجياً أولاً بأول */
    async function sendMessageStreaming(payload) {
      appendTyping();

      var bubble = null;
      var msgEl = null;
      var contentSoFar = '';
      var reasoningSoFar = '';
      var gotAnyDelta = false;

      function ensureBubble() {
        if (bubble) { return; }
        removeTyping();
        if (chatWelcome && chatWelcome.parentNode === chatMessages) { chatMessages.innerHTML = ''; }
        msgEl = document.createElement('div');
        msgEl.className = 'msg msg-assistant';
        var avatar = document.createElement('div');
        avatar.className = 'msg-avatar';
        avatar.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i>';
        bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        msgEl.appendChild(avatar);
        msgEl.appendChild(bubble);
        chatMessages.appendChild(msgEl);
        scrollToBottom();
      }

      function redraw() {
        bubble.innerHTML = '';
        if (reasoningSoFar) {
          var details = document.createElement('details');
          details.className = 'msg-reasoning';
          details.open = true;
          var summary = document.createElement('summary');
          summary.innerHTML = '<i class="fa-solid fa-brain"></i> تفكير النموذج';
          details.appendChild(summary);
          var body = document.createElement('div');
          body.className = 'msg-reasoning-body';
          renderContent(body, reasoningSoFar);
          details.appendChild(body);
          bubble.appendChild(details);
        }
        if (contentSoFar) { renderContent(bubble, contentSoFar); }
        scrollToBottom();
      }

      var finalEvent = null;
      try {
        var res = await fetch(sendEndpoint, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken(),
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(payload),
        });

        if (!res.ok || !res.body) {
          var errText = 'HTTP ' + res.status;
          try { var errData = await res.json(); if (errData && errData.error) { errText = errData.error; } } catch (e) { /* تجاهل */ }
          removeTyping();
          appendMessage('assistant', 'تعذّر الحصول على رد: ' + errText);
          return;
        }

        var reader = res.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buf = '';

        while (true) {
          var chunk = await reader.read();
          if (chunk.done) { break; }
          buf += decoder.decode(chunk.value, { stream: true });
          var frames = buf.split('\n\n');
          buf = frames.pop();
          for (var f = 0; f < frames.length; f++) {
            var lines = frames[f].split('\n');
            for (var l = 0; l < lines.length; l++) {
              if (lines[l].indexOf('data:') !== 0) { continue; }
              var jsonText = lines[l].slice(5).trim();
              if (!jsonText) { continue; }
              var evt;
              try { evt = JSON.parse(jsonText); } catch (e) { continue; }

              if (evt.type === 'delta') {
                ensureBubble();
                gotAnyDelta = true;
                if (evt.kind === 'reasoning') { reasoningSoFar += evt.text; } else { contentSoFar += evt.text; }
                redraw();
              } else if (evt.type === 'done' || evt.type === 'error') {
                finalEvent = evt;
              }
            }
          }
        }
      } catch (e) {
        removeTyping();
        if (!gotAnyDelta) {
          appendMessage('assistant', 'تعذّر الحصول على رد: تعذّر الاتصال بالسيرفر أو انقطع أثناء الاستقبال.');
        }
        return;
      }

      removeTyping();

      if (!finalEvent || finalEvent.type === 'error' || !finalEvent.success) {
        var errMsg = 'تعذّر الحصول على رد: ' + ((finalEvent && finalEvent.error) || 'خطأ غير معروف.');
        if (bubble) { contentSoFar = errMsg; reasoningSoFar = ''; redraw(); } else { appendMessage('assistant', errMsg); }
        return;
      }

      if (!currentConversationId) {
        addConvToRail(finalEvent.conversation_id, finalEvent.title || 'محادثة جديدة');
        currentConversationId = finalEvent.conversation_id;
      }

      // إعادة رسم نهائية بالنص الكامل من السيرفر (احتياطاً لأي جزء ناقص أثناء البث) + وسم المزوّد
      contentSoFar = finalEvent.reply || contentSoFar;
      reasoningSoFar = finalEvent.reasoning || reasoningSoFar;
      ensureBubble();
      redraw();
      if (finalEvent.provider) {
        var tag = document.createElement('div');
        tag.className = 'msg-provider-tag';
        tag.innerHTML = '<i class="fa-solid fa-microchip"></i>';
        tag.appendChild(document.createTextNode(' ' + finalEvent.provider));
        bubble.appendChild(tag);
      }
    }

    async function sendMessage() {
      if (sending) { return; }
      var text = chatInput.value.trim();
      if (!text && !pendingImage) { return; }

      sending = true;
      btnSendChat.disabled = true;

      var meta = null;
      if (pendingAttachment || pendingImage) {
        meta = {};
        if (pendingAttachment) { meta.path = pendingAttachment.path; }
        if (pendingImage) { meta.image = pendingImage.name; }
      }
      appendMessage('user', text || '(صورة بدون نص)', meta);

      chatInput.value = '';
      autoGrow();

      var payload = { project_id: projectId, conversation_id: currentConversationId, content: text };
      if (providerSelect && providerSelect.value) {
        payload.provider_id = providerSelect.value;
        rememberProviderChoice();
      }
      if (pendingAttachment) { payload.attach_path = pendingAttachment.path; }
      if (pendingImage) {
        payload.image_base64 = pendingImage.base64;
        payload.image_mime = pendingImage.mime;
        payload.image_name = pendingImage.name;
      }
      clearAttachments();

      if (mode === 'code') {
        await sendMessageNonStreaming(payload);
      } else {
        await sendMessageStreaming(payload);
      }

      sending = false;
      btnSendChat.disabled = false;
    }

    if (chatForm) {
      chatForm.addEventListener('submit', function (e) {
        e.preventDefault();
        sendMessage();
      });
    }

    if (btnConfirmCommit) {
      btnConfirmCommit.addEventListener('click', async function () {
        var path = commitPath.value.trim();
        var content = commitContent.value;
        var message = commitMessage.value.trim();
        if (!path) { window.alert('مسار الملف مطلوب.'); return; }

        btnConfirmCommit.disabled = true;
        commitStatus.style.display = 'block';
        commitStatus.className = 'alert alert-info';
        commitStatus.textContent = 'جاري تنفيذ الـ Commit...';

        var data = await apiFetch('api/github_commit.php', {
          method: 'POST',
          body: { project_id: projectId, path: path, content: content, message: message },
        });

        btnConfirmCommit.disabled = false;
        if (!data.success) {
          commitStatus.className = 'alert alert-error';
          commitStatus.textContent = data.error || 'فشل تنفيذ الـ Commit.';
          return;
        }
        commitStatus.className = 'alert alert-success';
        commitStatus.textContent = 'تم رفع التعديل بنجاح إلى GitHub.';
        setTimeout(function () { closeModal(btnConfirmCommit); }, 1200);
      });
    }

    // وضع الكود: محادثة واحدة مستمرة فقط - إن كانت موجودة مسبقاً لهذا المستخدم
    // بهذا المشروع، تُحمَّل تلقائياً عند فتح الصفحة (بلا رواق/زر محادثة جديدة).
    if (mode === 'code' && codeConvId > 0) {
      loadConversation(codeConvId);
    }
  }
})();
