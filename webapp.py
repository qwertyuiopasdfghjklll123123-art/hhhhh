"""
منصة حملات واتساب: حسابات متعددة، كل حساب بجلسة Chrome مستقلة، تسجيل دخول عبر QR،
حملة إرسال (أرقام كتابة أو Excel + فاصل زمني + نص/صورة/صوت اختياري)، وتسجيل خروج.

تشغيل:
    pip install -r requirements.txt
    python webapp.py
ثم افتح بالمتصفح: http://localhost:5000 (أو http://IP_السيرفر:5000 على VPS)
كل حساب تضيفه يحتاج مسح QR مرة وحدة، وتُحفظ جلسته بمجلد wa_sessions/<id> لعدم تكرار المسح.
"""

import os
import re
import shutil
import threading
import time
import urllib.parse
import uuid

import openpyxl
from flask import Flask, Response, jsonify, request
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

SESSIONS_ROOT = "./wa_sessions"
UPLOADS_DIR = "./uploads"
DEFAULT_MESSAGE = "هلوو"
DEFAULT_DELAY = 15

app = Flask(__name__)
accounts = {}  # id -> {name, driver, lock, campaign}


def new_account_entry(name):
    return {
        "name": name or f"حساب {len(accounts) + 1}",
        "driver": None,
        "lock": threading.Lock(),
        "campaign": {"total": 0, "sent": 0, "failed": 0, "running": False, "failed_numbers": []},
    }


def start_account_driver(acc_id):
    options = webdriver.ChromeOptions()
    options.add_argument(f"--user-data-dir={SESSIONS_ROOT}/{acc_id}")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1200,900")
    options.add_argument("--headless=new")
    options.add_argument("--disable-blink-features=AutomationControlled")
    options.add_argument(
        "--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36"
    )
    options.add_experimental_option("excludeSwitches", ["enable-automation"])
    options.add_experimental_option("useAutomationExtension", False)
    driver_path = shutil.which("chromedriver")
    service = Service(executable_path=driver_path) if driver_path else Service()
    driver = webdriver.Chrome(service=service, options=options)
    # واتساب يعرض صفحة "حدّث المتصفح" لو لقى علامات أتمتة Selenium حتى مع Chrome حديث
    driver.execute_cdp_cmd(
        "Page.addScriptToEvaluateOnNewDocument",
        {"source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"},
    )
    driver.get("https://web.whatsapp.com")
    accounts[acc_id]["driver"] = driver


def add_account(name):
    acc_id = uuid.uuid4().hex[:8]
    accounts[acc_id] = new_account_entry(name)
    threading.Thread(target=start_account_driver, args=(acc_id,), daemon=True).start()
    return acc_id


def account_logged_in(acc):
    d = acc["driver"]
    return d is not None and len(d.find_elements(By.ID, "pane-side")) > 0


def numbers_from_text(text):
    numbers = []
    for part in re.split(r"[,\n\r]+", text):
        digits = re.sub(r"\D", "", part)
        if len(digits) >= 8:
            numbers.append(digits)
    return numbers


def numbers_from_excel(file_storage):
    wb = openpyxl.load_workbook(file_storage.stream, read_only=True)
    numbers = []
    for row in wb.active.iter_rows(values_only=True):
        if not row or row[0] is None:
            continue
        value = row[0]
        if isinstance(value, float) and value.is_integer():
            value = int(value)
        digits = re.sub(r"\D", "", str(value))
        if len(digits) >= 8:
            numbers.append(digits)
    return numbers


def send_to(driver, number, text, media_path=None):
    if media_path:
        driver.get(f"https://web.whatsapp.com/send?phone={number}")
    else:
        driver.get(f"https://web.whatsapp.com/send?phone={number}&text={urllib.parse.quote(text)}")
    WebDriverWait(driver, 30).until(
        EC.presence_of_element_located((By.XPATH, '//footer//div[@contenteditable="true"]'))
    )
    if media_path:
        # نتفادى الضغط على أيقونة "إرفاق" (تتغير بتحديثات واتساب) ونستخدم حقل رفع الملف مباشرة
        file_inputs = driver.find_elements(By.CSS_SELECTOR, 'input[type="file"]')
        if not file_inputs:
            raise RuntimeError("ما لقيت عنصر رفع الملفات بواجهة واتساب")
        file_inputs[0].send_keys(media_path)
        caption_box = WebDriverWait(driver, 20).until(
            EC.presence_of_element_located((By.XPATH, '//div[@contenteditable="true"][@data-tab]'))
        )
        if text:
            caption_box.send_keys(text)
        time.sleep(2)
        caption_box.send_keys(Keys.ENTER)
        time.sleep(3)  # مهلة أطول حتى يكتمل رفع الملف قبل الانتقال للرقم التالي
    else:
        time.sleep(2)  # مهلة حتى يكتمل تعبئة نص الرسالة تلقائياً بالحقل قبل الإرسال
        driver.switch_to.active_element.send_keys(Keys.ENTER)


def run_campaign(acc, numbers, text, delay, media_path):
    state = acc["campaign"]
    for i, number in enumerate(numbers):
        with acc["lock"]:
            try:
                send_to(acc["driver"], number, text, media_path)
                state["sent"] += 1
            except Exception:
                state["failed"] += 1
                state["failed_numbers"].append(number)
        if i < len(numbers) - 1:
            time.sleep(delay)
    state["running"] = False


@app.route("/")
def home():
    return PAGE


@app.route("/accounts", methods=["GET"])
def list_accounts():
    return jsonify([
        {"id": aid, "name": a["name"], "logged_in": account_logged_in(a)}
        for aid, a in accounts.items()
    ])


@app.route("/accounts", methods=["POST"])
def create_account():
    data = request.json or {}
    acc_id = add_account((data.get("name") or "").strip())
    return jsonify(id=acc_id, name=accounts[acc_id]["name"])


@app.route("/accounts/<acc_id>/logout", methods=["POST"])
def logout(acc_id):
    acc = accounts.get(acc_id)
    if not acc:
        return jsonify(ok=False, error="حساب غير موجود"), 404
    if acc["driver"] is not None:
        try:
            acc["driver"].quit()
        except Exception:
            pass
    shutil.rmtree(f"{SESSIONS_ROOT}/{acc_id}", ignore_errors=True)
    del accounts[acc_id]
    return jsonify(ok=True)


@app.route("/accounts/<acc_id>/qr")
def qr(acc_id):
    acc = accounts.get(acc_id)
    if not acc or acc["driver"] is None:
        return "", 204
    try:
        canvas = acc["driver"].find_element(By.TAG_NAME, "canvas")
        return Response(canvas.screenshot_as_png, mimetype="image/png")
    except Exception:
        return "", 204


@app.route("/accounts/<acc_id>/debug")
def debug(acc_id):
    acc = accounts.get(acc_id)
    if not acc or acc["driver"] is None:
        return "driver لسا ما بدأ", 503
    return Response(acc["driver"].get_screenshot_as_png(), mimetype="image/png")


@app.route("/accounts/<acc_id>/status")
def status(acc_id):
    acc = accounts.get(acc_id)
    return jsonify(logged_in=bool(acc) and account_logged_in(acc))


@app.route("/accounts/<acc_id>/campaign", methods=["POST"])
def campaign(acc_id):
    acc = accounts.get(acc_id)
    if not acc or acc["driver"] is None:
        return jsonify(ok=False, error="تأكد من تسجيل الدخول أولاً"), 400
    if acc["campaign"]["running"]:
        return jsonify(ok=False, error="فيه حملة شغالة حالياً على هذا الحساب"), 400

    numbers = list(numbers_from_text(request.form.get("numbers_text", "")))
    file = request.files.get("numbers_file")
    if file and file.filename:
        numbers += numbers_from_excel(file)
    numbers = list(dict.fromkeys(numbers))  # إزالة التكرار مع حفظ الترتيب

    if not numbers:
        return jsonify(ok=False, error="ما لقيت أي رقم صالح (اكتب أرقام أو ارفع ملف Excel)"), 400

    text = request.form.get("text", "").strip() or DEFAULT_MESSAGE
    try:
        delay = max(1, int(request.form.get("delay", DEFAULT_DELAY)))
    except (TypeError, ValueError):
        delay = DEFAULT_DELAY

    media_path = None
    media = request.files.get("media_file")
    if media and media.filename:
        os.makedirs(UPLOADS_DIR, exist_ok=True)
        media_path = os.path.abspath(os.path.join(UPLOADS_DIR, f"{uuid.uuid4().hex}_{media.filename}"))
        media.save(media_path)

    acc["campaign"].update(total=len(numbers), sent=0, failed=0, running=True, failed_numbers=[])
    threading.Thread(target=run_campaign, args=(acc, numbers, text, delay, media_path), daemon=True).start()
    return jsonify(ok=True, total=len(numbers))


@app.route("/accounts/<acc_id>/campaign_status")
def campaign_status(acc_id):
    acc = accounts.get(acc_id)
    if not acc:
        return jsonify(total=0, sent=0, failed=0, running=False, failed_numbers=[])
    return jsonify(**acc["campaign"])


PAGE = """
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>منصة حملات واتساب</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  html, body { margin: 0; padding: 0; background: #f5f0e8; color: #1a1a1a; font-family: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif; }
  .shell { display: flex; min-height: 100vh; }
  .sidebar { width: 280px; flex-shrink: 0; background: #ffffff; border-left: 1px solid rgba(184,134,11,.15); padding: 24px 18px; display: flex; flex-direction: column; }
  .main-panel { flex: 1; padding: 40px; display: flex; justify-content: center; }
  .main-inner { width: 100%; max-width: 560px; }

  @media (max-width: 760px) {
    .shell { flex-direction: column; }
    .sidebar { width: 100%; border-left: none; border-bottom: 1px solid rgba(184,134,11,.15); }
    .main-panel { padding: 24px 16px; }
  }

  .dark-card { background: #ffffff; border: 1px solid rgba(184,134,11,.15); box-shadow: 0 4px 20px rgba(0,0,0,.04); }
  .glossy-card {
    background: linear-gradient(145deg, #ffffff, #fcf8f0);
    border: 1.5px solid rgba(184,134,11,.2);
    box-shadow: 0 0 30px rgba(184,134,11,.05), inset 0 1px 0 rgba(184,134,11,.08);
    position: relative; overflow: hidden;
  }
  .glossy-card::before {
    content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
    background: conic-gradient(from 0deg at 50% 50%, transparent 0%, rgba(184,134,11,.04) 25%, transparent 50%, rgba(184,134,11,.04) 75%, transparent 100%);
    animation: shimmerRotate 10s linear infinite; pointer-events: none;
  }
  @keyframes shimmerRotate { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
  .glossy-card .relative-z { position: relative; z-index: 1; }

  .text-gold { color: #b8860b; }
  .border-gold { border-color: rgba(184,134,11,.25); }
  .bg-gold-light { background: rgba(184,134,11,.07); }

  .rule-item { display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; border-bottom: 1px solid rgba(184,134,11,.08); }
  .rule-item:last-child { border-bottom: 0; }
  .rule-icon { width: 32px; height: 32px; border-radius: 10px; background: rgba(184,134,11,.08); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; color: #b8860b; }
  .rule-text { font-size: 13px; color: #1a1a1a; font-weight: 500; }
  .rule-text small { display: block; font-weight: 400; font-size: 11px; color: #8a8a8a; margin-top: 2px; }

  .field-label { display: block; margin-top: 16px; margin-bottom: 6px; font-size: 12px; color: #8a8a8a; font-weight: 500; }
  input, textarea {
    width: 100%; box-sizing: border-box; padding: 11px 12px; font-size: 14px; font-family: inherit;
    background: #ffffff; border: 1px solid rgba(184,134,11,.2); border-radius: 12px; color: #1a1a1a;
  }
  input:focus, textarea:focus { outline: none; border-color: #b8860b; }
  textarea { resize: vertical; }
  input[type="file"] { padding: 9px 12px; }

  .btn-gold {
    display: block; width: 100%; padding: 13px; margin-top: 18px; border: none; border-radius: 14px;
    background: #b8860b; color: #fff; font-weight: 700; font-size: 15px; font-family: inherit;
    cursor: pointer; transition: .2s ease;
  }
  .btn-gold:hover { background: #9a7209; }
  .btn-outline {
    display: block; width: 100%; padding: 11px; margin-top: 10px; border-radius: 14px;
    background: transparent; border: 1.5px solid rgba(184,134,11,.35); color: #b8860b;
    font-weight: 700; font-size: 13px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-outline:hover { background: rgba(184,134,11,.07); }
  .btn-danger {
    display: block; width: 100%; padding: 10px; margin-top: 12px; border-radius: 14px;
    background: transparent; border: 1.5px solid rgba(220,38,38,.3); color: #dc2626;
    font-weight: 700; font-size: 12px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-danger:hover { background: rgba(220,38,38,.06); }

  #qrImg { width: 220px; height: 220px; border-radius: 16px; border: 1px solid rgba(184,134,11,.2); background: #fff; display: block; margin: 0 auto; }

  .stat-row { display: flex; border-radius: 16px; overflow: hidden; margin-top: 14px; }
  .stat-cell { flex: 1; text-align: center; padding: 12px 4px; background: #ffffff; border: 1px solid rgba(184,134,11,.12); }
  .stat-cell + .stat-cell { border-right: none; }
  .stat-num { font-size: 16px; font-weight: 800; }
  .stat-label { font-size: 10px; color: #8a8a8a; margin-top: 2px; }

  .account-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; cursor: pointer; margin-bottom: 4px; transition: .15s ease; }
  .account-item:hover { background: rgba(184,134,11,.06); }
  .account-item.active { background: rgba(184,134,11,.1); border: 1px solid rgba(184,134,11,.25); }
  .account-name { font-size: 13px; font-weight: 600; }
  .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .dot-on { background: #16a34a; box-shadow: 0 0 6px rgba(22,163,74,.6); }
  .dot-off { background: #d1a83a; }
  .empty-state { text-align: center; color: #8a8a8a; font-size: 13px; margin-top: 60px; }
</style>
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="text-center mb-4">
      <div class="w-11 h-11 mx-auto mb-1 rounded-full bg-gold-light border border-gold flex items-center justify-center text-lg">💬</div>
      <h1 class="text-sm font-extrabold">منصة حملات واتساب</h1>
    </div>
    <div id="accountsList" class="flex-1"></div>
    <button class="btn-outline" onclick="addAccount()">+ إضافة حساب</button>
  </aside>
  <main class="main-panel">
    <div class="main-inner" id="mainPanel"></div>
  </main>
</div>

<script>
let accounts = [];
let activeId = null;
let gen = 0;

function el(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstChild;
}

async function loadAccounts(preferId) {
  accounts = await fetch('/accounts').then(r => r.json());
  if (preferId) activeId = preferId;
  if (!accounts.find(a => a.id === activeId)) activeId = accounts.length ? accounts[0].id : null;
  renderSidebar();
  renderPanel();
}

function renderSidebar() {
  const list = document.getElementById('accountsList');
  list.innerHTML = '';
  accounts.forEach(acc => {
    const item = el('<div class="account-item ' + (acc.id === activeId ? 'active' : '') + '">' +
      '<span class="dot ' + (acc.logged_in ? 'dot-on' : 'dot-off') + '"></span>' +
      '<span class="account-name">' + acc.name + '</span></div>');
    item.onclick = () => { activeId = acc.id; renderSidebar(); renderPanel(); };
    list.appendChild(item);
  });
  if (!accounts.length) {
    list.appendChild(el('<p class="text-[11px] text-[#8a8a8a] text-center mt-2">ما فيه حسابات بعد</p>'));
  }
}

async function addAccount() {
  const name = prompt('اسم الحساب (اختياري):', '');
  if (name === null) return;
  const r = await fetch('/accounts', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({name: name.trim()})
  }).then(res => res.json());
  await loadAccounts(r.id);
}

async function logoutAccount(id) {
  if (!confirm('تسجيل الخروج من هذا الحساب؟ بيحتاج مسح رمز QR من جديد لاحقاً.')) return;
  await fetch('/accounts/' + id + '/logout', {method: 'POST'});
  await loadAccounts();
}

function renderPanel() {
  gen++;
  const myGen = gen;
  const panel = document.getElementById('mainPanel');
  const acc = accounts.find(a => a.id === activeId);

  if (!acc) {
    panel.innerHTML = '<div class="empty-state">أضف حساباً من الشريط الجانبي للبدء 👈</div>';
    return;
  }

  if (!acc.logged_in) {
    panel.innerHTML =
      '<div class="glossy-card rounded-2xl p-4 border-gold">' +
      '<div class="relative-z">' +
      '<h2 class="text-sm font-extrabold text-gold text-center mb-1">مرحباً بك 👋</h2>' +
      '<p class="text-[11px] text-[#4a4a4a]/70 text-center mb-3">اربط "' + acc.name + '" بثلاث خطوات بسيطة</p>' +
      '<div class="rule-item"><div class="rule-icon">📱</div><div class="rule-text">افتح واتساب بجوالك<small>من التطبيق مباشرة</small></div></div>' +
      '<div class="rule-item"><div class="rule-icon">🔗</div><div class="rule-text">الأجهزة المرتبطة<small>الإعدادات ← الأجهزة المرتبطة ← ربط جهاز</small></div></div>' +
      '<div class="rule-item"><div class="rule-icon">📷</div><div class="rule-text">امسح الرمز أدناه<small>ينتظر المسح تلقائياً</small></div></div>' +
      '</div></div>' +
      '<div class="dark-card rounded-2xl p-4 mt-3 text-center">' +
      '<img id="qrImg" src="/accounts/' + acc.id + '/qr">' +
      '<p class="text-[10px] text-[#4a4a4a]/50 mt-2">يتحدّث الرمز تلقائياً كل بضع ثوانٍ</p>' +
      '</div>';
    pollLogin(acc.id, myGen);
    return;
  }

  panel.innerHTML =
    '<div class="glossy-card rounded-2xl p-4 border-gold text-center">' +
    '<div class="relative-z">' +
    '<div class="w-9 h-9 mx-auto mb-1 rounded-full bg-gold-light border border-gold flex items-center justify-center text-base">✅</div>' +
    '<h2 class="text-sm font-extrabold text-gold">' + acc.name + '</h2>' +
    '<p class="text-[11px] text-[#4a4a4a]/70 mt-0.5">جهّز حملتك وابدأ الإرسال</p>' +
    '</div></div>' +
    '<div class="dark-card rounded-2xl p-4 mt-3">' +
    '<label class="field-label">الأرقام (رقم كل سطر، أو مفصولة بفواصل)</label>' +
    '<textarea id="numbersText" rows="4" placeholder="9647701234567&#10;9647709876543"></textarea>' +
    '<label class="field-label">أو ارفع ملف Excel (.xlsx) فيه الأرقام بالعمود الأول</label>' +
    '<input type="file" id="numbersFile" accept=".xlsx">' +
    '<label class="field-label">نص الرسالة / التعليق</label>' +
    '<input id="text" value="هلوو">' +
    '<label class="field-label">صورة أو ملف صوتي (اختياري)</label>' +
    '<input type="file" id="mediaFile" accept="image/*,audio/*">' +
    '<label class="field-label">الفاصل الزمني بين كل رسالة وأخرى (بالثواني)</label>' +
    '<input id="delay" type="number" min="1" value="15">' +
    '<button class="btn-gold" onclick="startCampaign()">بدء الإرسال</button>' +
    '</div>' +
    '<div class="stat-row" id="statRow" style="display:none">' +
    '<div class="stat-cell"><div class="stat-num text-emerald-600" id="statSent">0</div><div class="stat-label">تم الإرسال</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-red-500" id="statFailed">0</div><div class="stat-label">فشل</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-gold" id="statTotal">0</div><div class="stat-label">الإجمالي</div></div>' +
    '</div>' +
    '<div id="msg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '<button class="btn-danger" onclick="logoutAccount(\\'' + acc.id + '\\')">تسجيل الخروج من هذا الحساب</button>';
}

async function pollLogin(accId, myGen) {
  if (myGen !== gen) return;
  const r = await fetch('/accounts/' + accId + '/status').then(res => res.json());
  if (myGen !== gen) return;
  if (r.logged_in) {
    await loadAccounts(accId);
  } else {
    const img = document.getElementById('qrImg');
    if (img) img.src = '/accounts/' + accId + '/qr?' + Date.now();
    setTimeout(() => pollLogin(accId, myGen), 3000);
  }
}

async function startCampaign() {
  const accId = activeId;
  const form = new FormData();
  form.append('numbers_text', document.getElementById('numbersText').value);
  form.append('text', document.getElementById('text').value.trim());
  form.append('delay', document.getElementById('delay').value || 15);
  const numbersFile = document.getElementById('numbersFile').files[0];
  if (numbersFile) form.append('numbers_file', numbersFile);
  const mediaFile = document.getElementById('mediaFile').files[0];
  if (mediaFile) form.append('media_file', mediaFile);

  document.getElementById('msg').innerText = 'جارِ البدء...';
  const r = await fetch('/accounts/' + accId + '/campaign', { method: 'POST', body: form }).then(res => res.json());
  if (!r.ok) {
    document.getElementById('msg').innerText = 'فشل: ' + r.error;
    return;
  }
  document.getElementById('msg').innerText = '';
  document.getElementById('statRow').style.display = 'flex';
  trackProgress(accId, gen);
}

async function trackProgress(accId, myGen) {
  if (myGen !== gen) return;
  const r = await fetch('/accounts/' + accId + '/campaign_status').then(res => res.json());
  if (myGen !== gen) return;
  const sentEl = document.getElementById('statSent');
  if (!sentEl) return;
  sentEl.innerText = r.sent;
  document.getElementById('statFailed').innerText = r.failed;
  document.getElementById('statTotal').innerText = r.total;
  if (r.running) {
    setTimeout(() => trackProgress(accId, myGen), 2000);
  } else if (r.total > 0) {
    document.getElementById('msg').innerText = 'انتهت الحملة ✅';
  }
}

loadAccounts();
</script>
</body>
</html>
"""


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 5000)), threaded=True)
