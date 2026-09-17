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

    var editProviderBtn = e.target.closest('[data-action="edit-provider"]');
    if (editProviderBtn) {
      resetProviderModal();
      setValue('providerFormAction', 'update_provider');
      setValue('providerId', editProviderBtn.getAttribute('data-id'));
      setValue('providerLabel', editProviderBtn.getAttribute('data-label'));
      setValue('providerBaseUrl', editProviderBtn.getAttribute('data-base-url'));
      setValue('providerTextModel', editProviderBtn.getAttribute('data-text-model'));
      setValue('providerVisionModel', editProviderBtn.getAttribute('data-vision-model'));
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
  /* وحدة المساعد الذكي (تعمل فقط داخل صفحة project_context.php)          */
  /* ------------------------------------------------------------------ */
  var chatLayout = document.getElementById('chatLayout');
  if (chatLayout) {
    initChat(chatLayout);
  }

  function initChat(root) {
    var projectId = root.getAttribute('data-project-id');
    var hasGithub = root.getAttribute('data-has-github') === '1';

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

    var modalGithubBrowse = document.getElementById('modalGithubBrowse');
    var githubPathBar = document.getElementById('githubPathBar');
    var githubFileList = document.getElementById('githubFileList');

    var commitPath = document.getElementById('commitPath');
    var commitContent = document.getElementById('commitContent');
    var commitMessage = document.getElementById('commitMessage');
    var commitStatus = document.getElementById('commitStatus');
    var btnConfirmCommit = document.getElementById('btnConfirmCommit');

    var currentConversationId = null;
    var pendingAttachment = null;
    var pendingImage = null;
    var sending = false;

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

      if (hasGithub) {
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

    function renderContent(container, text) {
      var parts = String(text).split(/```(\w*)\n([\s\S]*?)```/g);
      for (var i = 0; i < parts.length; i += 3) {
        var plain = parts[i];
        if (plain) {
          plain.split(/\n{2,}/).forEach(function (para) {
            if (!para.trim()) { return; }
            var p = document.createElement('p');
            p.textContent = para;
            container.appendChild(p);
          });
        }
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
      appendTyping();

      var payload = { project_id: projectId, conversation_id: currentConversationId, content: text };
      if (providerSelect && providerSelect.value) { payload.provider_id = providerSelect.value; }
      if (pendingAttachment) { payload.attach_path = pendingAttachment.path; }
      if (pendingImage) {
        payload.image_base64 = pendingImage.base64;
        payload.image_mime = pendingImage.mime;
        payload.image_name = pendingImage.name;
      }
      clearAttachments();

      var data = await apiFetch('api/messages.php', { method: 'POST', body: payload });
      removeTyping();
      sending = false;
      btnSendChat.disabled = false;

      if (!data.success) {
        appendMessage('assistant', 'تعذّر الحصول على رد: ' + (data.error || 'خطأ غير معروف.'));
        return;
      }
      if (!currentConversationId) {
        addConvToRail(data.conversation_id, data.title || 'محادثة جديدة');
        currentConversationId = data.conversation_id;
      }
      var replyMeta = null;
      if (data.provider || data.reasoning) {
        replyMeta = {};
        if (data.provider) { replyMeta.provider = data.provider; }
        if (data.reasoning) { replyMeta.reasoning = data.reasoning; }
      }
      appendMessage('assistant', data.reply, replyMeta);
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
  }
})();
