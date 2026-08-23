"""
صفحة واحدة: تسجيل دخول واتساب عبر QR + خانة رقم + زر يرسل نص "هلوو".

تشغيل:
    pip install -r requirements.txt
    python webapp.py
ثم افتح بالمتصفح: http://localhost:5000 (أو http://IP_السيرفر:5000 على VPS)
أول مرة امسح رمز QR من نفس الصفحة، والجلسة تُحفظ بمجلد wa_session لعدم تكرار المسح لاحقاً.
"""

import threading
import time
import urllib.parse

from flask import Flask, Response, jsonify, request
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

SESSION_DIR = "./wa_session"
MESSAGE = "هلوو"

app = Flask(__name__)
driver = None
lock = threading.Lock()


def start_driver():
    global driver
    options = webdriver.ChromeOptions()
    options.add_argument(f"--user-data-dir={SESSION_DIR}")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1200,900")
    options.add_argument("--headless=new")  # يشتغل بدون شاشة، رمز QR يُعرض عبر /qr
    driver = webdriver.Chrome(options=options)
    driver.get("https://web.whatsapp.com")


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


@app.route("/status")
def status():
    logged_in = driver is not None and len(driver.find_elements(By.ID, "pane-side")) > 0
    return jsonify(logged_in=logged_in)


@app.route("/send", methods=["POST"])
def send():
    number = (request.json or {}).get("number", "").strip()
    if not number or driver is None:
        return jsonify(ok=False, error="أدخل رقماً، وتأكد من تسجيل الدخول أولاً"), 400
    with lock:
        try:
            url = f"https://web.whatsapp.com/send?phone={number}&text={urllib.parse.quote(MESSAGE)}"
            driver.get(url)
            box = WebDriverWait(driver, 30).until(
                EC.presence_of_element_located((By.XPATH, '//footer//div[@contenteditable="true"]'))
            )
            time.sleep(2)  # مهلة حتى يكتمل تعبئة نص الرسالة تلقائياً بالحقل قبل الإرسال
            box.send_keys(Keys.ENTER)
            return jsonify(ok=True)
        except Exception as e:
            return jsonify(ok=False, error=str(e)), 500


PAGE = """
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>إرسال واتساب</title>
<style>
  body { font-family: sans-serif; max-width: 420px; margin: 60px auto; text-align: center; }
  img { width: 260px; height: 260px; border: 1px solid #ccc; }
  input, button { padding: 10px; font-size: 16px; margin-top: 12px; width: 100%; box-sizing: border-box; }
  button { cursor: pointer; }
  #msg { margin-top: 12px; font-weight: bold; }
</style>
</head>
<body>
  <div id="login">
    <h3>امسح رمز QR لتسجيل الدخول</h3>
    <img id="qrImg" src="/qr">
  </div>
  <div id="app" style="display:none">
    <h3>تم تسجيل الدخول</h3>
    <input id="number" placeholder="الرقم مع مفتاح الدولة، مثال 9647701234567">
    <button onclick="sendMsg()">إرسال "هلوو"</button>
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

async function sendMsg() {
  const number = document.getElementById('number').value.trim();
  document.getElementById('msg').innerText = 'جارِ الإرسال...';
  const r = await fetch('/send', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({number})
  }).then(res => res.json());
  document.getElementById('msg').innerText = r.ok ? 'تم الإرسال بنجاح' : ('فشل: ' + r.error);
}
</script>
</body>
</html>
"""


if __name__ == "__main__":
    threading.Thread(target=start_driver, daemon=True).start()
    app.run(host="0.0.0.0", port=5000)
