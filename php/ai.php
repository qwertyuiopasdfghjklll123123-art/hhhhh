<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'المساعد الذكي — fastcrand'; $assetBase = ''; include __DIR__ . '/includes/head.php'; ?>
<style>
  .chat-wrap { display: flex; gap: 12px; height: 70vh; }
  .chat-list { width: 220px; overflow-y: auto; }
  .chat-list .item { padding: 8px; border-radius: 8px; cursor: pointer; margin-bottom: 4px; font-size: 13px; }
  .chat-list .item:hover { background: var(--primary-bg); }
  .chat-main { flex: 1; display: flex; flex-direction: column; }
  .chat-msgs { flex: 1; overflow-y: auto; padding: 10px; background: var(--bg2); border-radius: 10px; }
  .msg { max-width: 80%; margin: 6px 0; padding: 8px 12px; border-radius: 10px; font-size: 14px; white-space: pre-wrap; }
  .msg.user { background: var(--primary); color: #fff; margin-right: auto; margin-left: 0; text-align: right; }
  .msg.assistant { background: var(--card); border: 1px solid var(--card-border); margin-left: auto; margin-right: 0; }
  .chat-input { display: flex; gap: 8px; margin-top: 10px; }
  .chat-input textarea { flex: 1; min-height: 44px; resize: none; }
  @media (max-width: 700px) { .chat-list { display: none; } }
</style>
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <h2>🤖 المساعد الذكي</h2>
    <a class="btn secondary" href="dashboard">رجوع</a>
  </div>
  <div class="card">
    <div class="chat-wrap">
      <div class="chat-list card" id="convList"></div>
      <div class="chat-main">
        <div class="chat-msgs" id="chatMsgs"></div>
        <div class="chat-input">
          <textarea id="chatInput" placeholder="اكتب رسالتك..."></textarea>
          <button onclick="sendMsg()">إرسال</button>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
let currentConvId = null;

function addMsg(role, content) {
  const box = document.getElementById('chatMsgs');
  const d = document.createElement('div');
  d.className = 'msg ' + role;
  d.textContent = content;
  box.appendChild(d);
  box.scrollTop = box.scrollHeight;
}

async function loadConversations() {
  const r = await fetch('api/ai_conversations');
  const d = await r.json();
  const list = document.getElementById('convList');
  list.innerHTML = '<div class="item" onclick="newChat()" style="font-weight:bold">+ محادثة جديدة</div>';
  (d.conversations || []).forEach(c => {
    const item = document.createElement('div');
    item.className = 'item';
    item.textContent = c.title;
    item.onclick = () => loadConversation(c.id);
    list.appendChild(item);
  });
}

async function loadConversation(id) {
  currentConvId = id;
  const r = await fetch('api/ai_messages?conversation_id=' + id);
  const d = await r.json();
  document.getElementById('chatMsgs').innerHTML = '';
  (d.messages || []).forEach(m => addMsg(m.role, m.content));
}

function newChat() {
  currentConvId = null;
  document.getElementById('chatMsgs').innerHTML = '';
}

async function sendMsg() {
  const input = document.getElementById('chatInput');
  const text = input.value.trim();
  if (!text) return;
  input.value = '';
  addMsg('user', text);
  const r = await fetch('api/ai_chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: text, conversation_id: currentConvId })
  });
  const d = await r.json();
  if (d.ok) {
    currentConvId = d.conversation_id;
    addMsg('assistant', d.reply);
    loadConversations();
  } else {
    addMsg('assistant', '⚠️ ' + (d.msg || 'حدث خطأ'));
  }
}

document.getElementById('chatInput').addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
});

loadConversations();
</script>
</body>
</html>
