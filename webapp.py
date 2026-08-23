"""
صفحة واحدة: تسجيل دخول واتساب عبر QR + حملة إرسال لعدة أرقام (كتابة أو ملف Excel) + فاصل زمني.

تشغيل:
    pip install -r requirements.txt
    python webapp.py
ثم افتح بالمتصفح: http://localhost:5000 (أو http://IP_السيرفر:5000 على VPS)
أول مرة امسح رمز QR من نفس الصفحة، والجلسة تُحفظ بمجلد wa_session لعدم تكرار المسح لاحقاً.
"""

import os
import re
import shutil
import threading
import time
import urllib.parse

import openpyxl
from flask import Flask, Response, jsonify, request
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

SESSION_DIR = "./wa_session"
DEFAULT_MESSAGE = "هلوو"
DEFAULT_DELAY = 15

app = Flask(__name__)
driver = None
lock = threading.Lock()
campaign_state = {"total": 0, "sent": 0, "failed": 0, "running": False, "failed_numbers": []}


def start_driver():
    global driver
    options = webdriver.ChromeOptions()
    options.add_argument(f"--user-data-dir={SESSION_DIR}")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1200,900")
    options.add_argument("--headless=new")  # يشتغل بدون شاشة، رمز QR يُعرض عبر /qr
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


def send_to(number, text):
    url = f"https://web.whatsapp.com/send?phone={number}&text={urllib.parse.quote(text)}"
    driver.get(url)
    box = WebDriverWait(driver, 30).until(
        EC.presence_of_element_located((By.XPATH, '//footer//div[@contenteditable="true"]'))
    )
    time.sleep(2)  # مهلة حتى يكتمل تعبئة نص الرسالة تلقائياً بالحقل قبل الإرسال
    box.send_keys(Keys.ENTER)


def run_campaign(numbers, text, delay):
    for i, number in enumerate(numbers):
        with lock:
            try:
                send_to(number, text)
                campaign_state["sent"] += 1
            except Exception:
                campaign_state["failed"] += 1
                campaign_state["failed_numbers"].append(number)
        if i < len(numbers) - 1:
            time.sleep(delay)
    campaign_state["running"] = False


@app.route("/")
def home():
    return PAGE


@app.route("/qr")
def qr():
    if driver is None:
        return "", 204
    try:
        canvas = driver.find_element(By.TAG_NAME, "canvas")
        return Response(canvas.screenshot_as_png, mimetype="image/png")
    except Exception:
        return "", 204


@app.route("/debug")
def debug():
    if driver is None:
        return "driver لسا ما بدأ", 503
    return Response(driver.get_screenshot_as_png(), mimetype="image/png")


@app.route("/status")
def status():
    logged_in = driver is not None and len(driver.find_elements(By.ID, "pane-side")) > 0
    return jsonify(logged_in=logged_in)


@app.route("/campaign", methods=["POST"])
def campaign():
    if driver is None:
        return jsonify(ok=False, error="تأكد من تسجيل الدخول أولاً"), 400
    if campaign_state["running"]:
        return jsonify(ok=False, error="فيه حملة شغالة حالياً، انتظر تخلص"), 400

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

    campaign_state.update(total=len(numbers), sent=0, failed=0, running=True, failed_numbers=[])
    threading.Thread(target=run_campaign, args=(numbers, text, delay), daemon=True).start()
    return jsonify(ok=True, total=len(numbers))


@app.route("/campaign_status")
def campaign_status():
    return jsonify(**campaign_state)


PAGE = """
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>منصة حملات واتساب</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  html, body { margin: 0; padding: 0; background: #f5f0e8; color: #1a1a1a; font-family: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif; }
  .phone-frame { width: 100%; max-width: 430px; min-height: 100vh; margin: 0 auto; display: flex; flex-direction: column; background: #f5f0e8; }

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
  .text-gold-soft { color: rgba(184,134,11,.6); }
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

  #qrImg { width: 240px; height: 240px; border-radius: 16px; border: 1px solid rgba(184,134,11,.2); background: #fff; display: block; margin: 0 auto; }

  .stat-row { display: flex; border-radius: 16px; overflow: hidden; margin-top: 14px; }
  .stat-cell { flex: 1; text-align: center; padding: 12px 4px; background: #ffffff; border: 1px solid rgba(184,134,11,.12); }
  .stat-cell + .stat-cell { border-right: none; }
  .stat-num { font-size: 16px; font-weight: 800; }
  .stat-label { font-size: 10px; color: #8a8a8a; margin-top: 2px; }
</style>
</head>
<body>
<div class="phone-frame">

  <header class="px-5 pt-6 pb-3 text-center">
    <div class="w-12 h-12 mx-auto mb-2 rounded-full bg-gold-light border border-gold flex items-center justify-center text-xl">💬</div>
    <h1 class="text-base font-extrabold">منصة حملات واتساب</h1>
    <p class="text-[11px] text-gold-soft font-light tracking-wide mt-0.5">أرسل حملتك التسويقية بأمان وسهولة</p>
  </header>

  <main class="flex-1 px-5 pb-8 space-y-3">

    <div id="login">
      <div class="glossy-card rounded-2xl p-4 border-gold">
        <div class="relative-z">
          <h2 class="text-sm font-extrabold text-gold text-center mb-1">مرحباً بك 👋</h2>
          <p class="text-[11px] text-[#4a4a4a]/70 text-center mb-3">اربط رقم واتساب بثلاث خطوات بسيطة</p>
          <div class="rule-item">
            <div class="rule-icon">📱</div>
            <div class="rule-text">افتح واتساب بجوالك<small>من التطبيق مباشرة</small></div>
          </div>
          <div class="rule-item">
            <div class="rule-icon">🔗</div>
            <div class="rule-text">الأجهزة المرتبطة<small>الإعدادات ← الأجهزة المرتبطة ← ربط جهاز</small></div>
          </div>
          <div class="rule-item">
            <div class="rule-icon">📷</div>
            <div class="rule-text">امسح الرمز أدناه<small>ينتظر المسح تلقائياً، ما تحتاج تضغط أي شيء</small></div>
          </div>
        </div>
      </div>

      <div class="dark-card rounded-2xl p-4 mt-3 text-center">
        <img id="qrImg" src="/qr" alt="QR">
        <p class="text-[10px] text-[#4a4a4a]/50 mt-2">يتحدّث الرمز تلقائياً كل بضع ثوانٍ</p>
      </div>
    </div>

    <div id="app" style="display:none">
      <div class="glossy-card rounded-2xl p-4 border-gold text-center">
        <div class="relative-z">
          <div class="w-9 h-9 mx-auto mb-1 rounded-full bg-gold-light border border-gold flex items-center justify-center text-base">✅</div>
          <h2 class="text-sm font-extrabold text-gold">تم تسجيل الدخول</h2>
          <p class="text-[11px] text-[#4a4a4a]/70 mt-0.5">جهّز حملتك وابدأ الإرسال</p>
        </div>
      </div>

      <div class="dark-card rounded-2xl p-4 mt-3">
        <label class="field-label">الأرقام (رقم كل سطر، أو مفصولة بفواصل)</label>
        <textarea id="numbersText" rows="4" placeholder="9647701234567&#10;9647709876543"></textarea>

        <label class="field-label">أو ارفع ملف Excel (.xlsx) فيه الأرقام بالعمود الأول</label>
        <input type="file" id="numbersFile" accept=".xlsx">

        <label class="field-label">نص الرسالة</label>
        <input id="text" value="هلوو">

        <label class="field-label">الفاصل الزمني بين كل رسالة وأخرى (بالثواني)</label>
        <input id="delay" type="number" min="1" value="15">

        <button class="btn-gold" onclick="startCampaign()">بدء الإرسال</button>
      </div>

      <div class="stat-row" id="statRow" style="display:none">
        <div class="stat-cell"><div class="stat-num text-emerald-600" id="statSent">0</div><div class="stat-label">تم الإرسال</div></div>
        <div class="stat-cell"><div class="stat-num text-red-500" id="statFailed">0</div><div class="stat-label">فشل</div></div>
        <div class="stat-cell"><div class="stat-num text-gold" id="statTotal">0</div><div class="stat-label">الإجمالي</div></div>
      </div>
      <div id="msg" class="text-center text-[12px] font-bold mt-2"></div>
    </div>

  </main>
</div>

<script>
async function poll() {
  const r = await fetch('/status').then(res => res.json());
  if (r.logged_in) {
    document.getElementById('login').style.display = 'none';
    document.getElementById('app').style.display = 'block';
  } else {
    document.getElementById('qrImg').src = '/qr?' + Date.now();
    setTimeout(poll, 3000);
  }
}
poll();

async function startCampaign() {
  const form = new FormData();
  form.append('numbers_text', document.getElementById('numbersText').value);
  form.append('text', document.getElementById('text').value.trim());
  form.append('delay', document.getElementById('delay').value || 15);
  const file = document.getElementById('numbersFile').files[0];
  if (file) form.append('numbers_file', file);

  document.getElementById('msg').innerText = 'جارِ البدء...';
  const r = await fetch('/campaign', { method: 'POST', body: form }).then(res => res.json());
  if (!r.ok) {
    document.getElementById('msg').innerText = 'فشل: ' + r.error;
    return;
  }
  document.getElementById('msg').innerText = '';
  document.getElementById('statRow').style.display = 'flex';
  trackProgress();
}

async function trackProgress() {
  const r = await fetch('/campaign_status').then(res => res.json());
  document.getElementById('statSent').innerText = r.sent;
  document.getElementById('statFailed').innerText = r.failed;
  document.getElementById('statTotal').innerText = r.total;
  if (r.running) {
    setTimeout(trackProgress, 2000);
  } else if (r.total > 0) {
    document.getElementById('msg').innerText = 'انتهت الحملة ✅';
  }
}
</script>
</body>
</html>
"""


if __name__ == "__main__":
    threading.Thread(target=start_driver, daemon=True).start()
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 5000)))
