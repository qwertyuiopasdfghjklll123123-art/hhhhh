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
<title>حملة واتساب</title>
<style>
  body { font-family: sans-serif; max-width: 420px; margin: 60px auto; text-align: center; padding: 0 16px; }
  img { width: 260px; height: 260px; border: 1px solid #ccc; }
  input, textarea, button { padding: 10px; font-size: 16px; margin-top: 12px; width: 100%; box-sizing: border-box; font-family: inherit; }
  textarea { resize: vertical; }
  button { cursor: pointer; }
  label { display: block; margin-top: 16px; font-size: 14px; color: #555; text-align: right; }
  #msg, #progress { margin-top: 12px; font-weight: bold; }
</style>
</head>
<body>
  <div id="login">
    <h3>امسح رمز QR لتسجيل الدخول</h3>
    <img id="qrImg" src="/qr">
  </div>
  <div id="app" style="display:none">
    <h3>تم تسجيل الدخول</h3>

    <label>الأرقام (رقم كل سطر، أو مفصولة بفواصل)</label>
    <textarea id="numbersText" rows="4" placeholder="9647701234567&#10;9647709876543"></textarea>

    <label>أو ارفع ملف Excel (.xlsx) فيه الأرقام بالعمود الأول</label>
    <input type="file" id="numbersFile" accept=".xlsx">

    <label>نص الرسالة</label>
    <input id="text" value="هلوو">

    <label>الفاصل الزمني بين كل رسالة وأخرى (بالثواني)</label>
    <input id="delay" type="number" min="1" value="15">

    <button onclick="startCampaign()">بدء الإرسال</button>
    <div id="progress"></div>
    <div id="msg"></div>
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
  document.getElementById('progress').innerText = '';
  const r = await fetch('/campaign', { method: 'POST', body: form }).then(res => res.json());
  if (!r.ok) {
    document.getElementById('msg').innerText = 'فشل: ' + r.error;
    return;
  }
  document.getElementById('msg').innerText = '';
  trackProgress();
}

async function trackProgress() {
  const r = await fetch('/campaign_status').then(res => res.json());
  document.getElementById('progress').innerText =
    'تم الإرسال: ' + r.sent + '  /  فشل: ' + r.failed + '  /  الإجمالي: ' + r.total;
  if (r.running) {
    setTimeout(trackProgress, 2000);
  } else if (r.total > 0) {
    document.getElementById('msg').innerText = 'انتهت الحملة';
  }
}
</script>
</body>
</html>
"""


if __name__ == "__main__":
    threading.Thread(target=start_driver, daemon=True).start()
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 5000)))
