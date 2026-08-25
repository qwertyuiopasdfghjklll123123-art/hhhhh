"""
منصة حملات واتساب: تسجيل دخول/إنشاء حساب لكل مستخدم (بيانات معزولة لكل واحد)،
تطبيق PWA بشريط تبويب أسفل الشاشة على الجوال وقائمة جانبية على اللابتوب، حسابات
واتساب متعددة لكل مستخدم، حملات فورية أو مجدولة (نص/صورة/فيديو/ملف)، رد آلي
بكلمات مفتاحية أو بالذكاء الاصطناعي (DeepSeek، يفعّله الأدمن)، وضع داكن/نهاري.

تشغيل:
    pip install -r requirements.txt
    python webapp.py
ثم افتح: http://localhost:5000 (أو http://IP_السيرفر:5000 على VPS)
أول مستخدم يسوي "إنشاء حساب" يصير أدمن المنصة تلقائياً (يقدر يضيف مفتاح DeepSeek
من قسم الإعدادات). بيانات المستخدمين تُحفظ بملف app.db (SQLite) وتبقى بعد إعادة
تشغيل السيرفر، لكن حسابات واتساب المتصلة نفسها (المتصفح/الجلسة) تحتاج إعادة ربط
لو انطفى السيرفر بالكامل، بنفس ما كان الوضع قبل هذي الإضافة.

ملاحظة صدق: "الرد الآلي" (كلمات مفتاحية أو AI) يعتمد على مراقبة أول محادثة بقائمة
واتساب بشكل دوري لعدم وجود حدث رسمي "رسالة جديدة"، وهذا أضعف جزء بكل الكود لأنه
مبني على تخمين لمحددات DOM قابلة للتغيّر — توقّع حاجتها لجولة تصحيح لو ما اشتغلت
أول مرة، بنفس طريقة تصحيح إرفاق الصور سابقاً.
"""

import os
import re
import shutil
import sqlite3
import threading
import time
import urllib.parse
import uuid
from datetime import datetime
from functools import wraps

import openpyxl
import requests
from flask import Flask, Response, jsonify, redirect, request, session
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from werkzeug.security import check_password_hash, generate_password_hash

SESSIONS_ROOT = "./wa_sessions"
UPLOADS_DIR = "./uploads"
DB_PATH = "./app.db"
DEFAULT_MESSAGE = "هلوو"
DEFAULT_DELAY = 15
EVENTS_MAX = 50

app = Flask(__name__)
app.secret_key = os.environ.get("SECRET_KEY", uuid.uuid4().hex)

accounts = {}  # id -> {id, owner, name, driver, lock, campaign, history, auto_reply, watching}
events = []
events_lock = threading.Lock()


# ---------------------------------------------------------------- قاعدة البيانات

def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    conn = get_db()
    conn.execute("""
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS ai_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            api_key TEXT DEFAULT '',
            knowledge_base TEXT DEFAULT ''
        )
    """)
    conn.commit()
    conn.close()


init_db()


def db_get_user_by_username(username):
    conn = get_db()
    row = conn.execute("SELECT * FROM users WHERE username = ?", (username,)).fetchone()
    conn.close()
    return row


def db_count_users():
    conn = get_db()
    n = conn.execute("SELECT COUNT(*) c FROM users").fetchone()["c"]
    conn.close()
    return n


def db_create_user(username, password_hash, is_admin):
    conn = get_db()
    cur = conn.execute(
        "INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, ?)",
        (username, password_hash, int(is_admin)),
    )
    conn.commit()
    user_id = cur.lastrowid
    conn.close()
    return user_id


def db_get_ai_settings():
    conn = get_db()
    row = conn.execute("SELECT * FROM ai_settings WHERE id = 1").fetchone()
    conn.close()
    return row


def db_set_ai_settings(api_key, knowledge_base):
    conn = get_db()
    conn.execute(
        "INSERT INTO ai_settings (id, api_key, knowledge_base) VALUES (1, ?, ?) "
        "ON CONFLICT(id) DO UPDATE SET api_key = excluded.api_key, knowledge_base = excluded.knowledge_base",
        (api_key, knowledge_base),
    )
    conn.commit()
    conn.close()


# ---------------------------------------------------------------- المصادقة

def login_required(f):
    @wraps(f)
    def wrapper(*args, **kwargs):
        if not session.get("user_id"):
            return jsonify(ok=False, error="سجّل الدخول أولاً"), 401
        return f(*args, **kwargs)
    return wrapper


def admin_required(f):
    @wraps(f)
    def wrapper(*args, **kwargs):
        if not session.get("is_admin"):
            return jsonify(ok=False, error="هذا الإجراء لأدمن المنصة فقط"), 403
        return f(*args, **kwargs)
    return wrapper


def get_owned_account(acc_id):
    acc = accounts.get(acc_id)
    if not acc or acc["owner"] != session.get("user_id"):
        return None
    return acc


# ---------------------------------------------------------------- حسابات واتساب

def add_event(account_name, title, body):
    with events_lock:
        events.append({
            "id": (events[-1]["id"] + 1) if events else 1,
            "account": account_name,
            "title": title,
            "body": body,
            "time": datetime.now().strftime("%H:%M"),
        })
        del events[:-EVENTS_MAX]


def new_account_entry(acc_id, owner, name):
    return {
        "id": acc_id,
        "owner": owner,
        "name": name or f"حساب {len(accounts) + 1}",
        "driver": None,
        "lock": threading.Lock(),
        "campaign": {"total": 0, "sent": 0, "failed": 0, "running": False, "failed_numbers": [], "scheduled_for": None},
        "history": [],
        "auto_reply": {"enabled": False, "mode": "keywords", "rules": []},
        "watching": False,
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
    driver.execute_cdp_cmd(
        "Page.addScriptToEvaluateOnNewDocument",
        {"source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"},
    )
    driver.get("https://web.whatsapp.com")
    accounts[acc_id]["driver"] = driver


def add_account(owner, name):
    acc_id = uuid.uuid4().hex[:8]
    accounts[acc_id] = new_account_entry(acc_id, owner, name)
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
        caption_box = WebDriverWait(driver, 25).until(
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
    started = datetime.now().strftime("%Y-%m-%d %H:%M")
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
    state["scheduled_for"] = None
    acc["history"].insert(0, {"time": started, "total": state["total"], "sent": state["sent"], "failed": state["failed"]})
    del acc["history"][20:]
    add_event(acc["name"], "انتهت الحملة", f'نجح {state["sent"]} من {state["total"]}، فشل {state["failed"]}')


def ai_reply(customer_message):
    settings = db_get_ai_settings()
    if not settings or not settings["api_key"]:
        return None
    try:
        resp = requests.post(
            "https://api.deepseek.com/chat/completions",
            headers={"Authorization": f"Bearer {settings['api_key']}", "Content-Type": "application/json"},
            json={
                "model": "deepseek-chat",
                "messages": [
                    {
                        "role": "system",
                        "content": (
                            "أنت مساعد خدمة عملاء لمتجر عبر واتساب. هذه قائمة المنتجات والأسعار "
                            "التي تعتمد عليها فقط بالرد:\n" + settings["knowledge_base"] +
                            "\nرد بإيجاز ووضوح باللهجة العربية، ولا تخترع معلومات غير موجودة أعلاه."
                        ),
                    },
                    {"role": "user", "content": customer_message},
                ],
            },
            timeout=25,
        )
        resp.raise_for_status()
        return resp.json()["choices"][0]["message"]["content"].strip()
    except Exception:
        return None


def watch_account(acc_id):
    """مراقبة أفضل-جهد لأول محادثة بالقائمة، ورد آلي بكلمات مفتاحية أو بالذكاء الاصطناعي."""
    last_seen = {}
    while True:
        acc = accounts.get(acc_id)
        if not acc or not acc.get("watching") or acc["driver"] is None:
            return
        try:
            with acc["lock"]:
                driver = acc["driver"]
                first_chat = driver.find_element(By.CSS_SELECTOR, '#pane-side div[role="listitem"]')
                name_el = first_chat.find_element(By.CSS_SELECTOR, "span[title]")
                chat_name = name_el.get_attribute("title") or "غير معروف"
                first_chat.click()
                time.sleep(1.5)
                incoming = driver.find_elements(By.CSS_SELECTOR, "div.message-in .selectable-text span")
                last_text = incoming[-1].text.strip() if incoming else ""
                if last_text and last_seen.get(chat_name) != last_text:
                    last_seen[chat_name] = last_text
                    reply_text = None
                    if acc["auto_reply"]["mode"] == "ai":
                        reply_text = ai_reply(last_text)
                    else:
                        for rule in acc["auto_reply"]["rules"]:
                            if rule["keyword"] and rule["keyword"] in last_text:
                                reply_text = rule["reply"]
                                break
                    if reply_text:
                        box = driver.find_element(By.XPATH, '//footer//div[@contenteditable="true"]')
                        box.send_keys(reply_text)
                        box.send_keys(Keys.ENTER)
                        add_event(acc["name"], "رد تلقائي", f"{chat_name}: {reply_text[:60]}")
        except Exception:
            pass
        time.sleep(6)


# ---------------------------------------------------------------- صفحات المصادقة

def render_auth_page(title, action, switch_html, error):
    error_html = f'<p class="err">{error}</p>' if error else ""
    return f"""
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  html, body {{ margin:0; padding:0; background:#f5f0e8; color:#1a1a1a; font-family:'IBM Plex Sans Arabic','Tajawal',system-ui,sans-serif; }}
  .box {{ max-width: 360px; margin: 90px auto; background:#fff; border:1px solid rgba(184,134,11,.2); border-radius:20px; padding:28px 24px; box-shadow:0 10px 30px rgba(0,0,0,.05); }}
  h2 {{ text-align:center; color:#b8860b; font-size:18px; margin:0 0 18px; }}
  input {{ width:100%; box-sizing:border-box; padding:11px 12px; font-size:14px; font-family:inherit; border:1px solid rgba(184,134,11,.25); border-radius:12px; margin-top:10px; }}
  button {{ width:100%; padding:12px; margin-top:16px; border:none; border-radius:12px; background:#b8860b; color:#fff; font-weight:700; font-size:14px; font-family:inherit; cursor:pointer; }}
  p.switch {{ text-align:center; font-size:12px; margin-top:14px; }}
  p.switch a {{ color:#b8860b; font-weight:700; text-decoration:none; }}
  p.err {{ color:#dc2626; font-size:12px; text-align:center; margin:0 0 10px; }}
</style>
</head>
<body>
  <div class="box">
    <h2>{title}</h2>
    {error_html}
    <form method="post" action="{action}">
      <input name="username" placeholder="اسم المستخدم" required>
      <input name="password" type="password" placeholder="كلمة المرور" required>
      <button type="submit">{title}</button>
    </form>
    {switch_html}
  </div>
</body>
</html>
"""


@app.route("/login", methods=["GET", "POST"])
def login_page():
    if request.method == "GET":
        return render_auth_page("تسجيل الدخول", "/login", '<p class="switch">ما عندك حساب؟ <a href="/signup">أنشئ حساب</a></p>', "")
    username = request.form.get("username", "").strip()
    password = request.form.get("password", "")
    user = db_get_user_by_username(username)
    if not user or not check_password_hash(user["password_hash"], password):
        return render_auth_page("تسجيل الدخول", "/login", '<p class="switch">ما عندك حساب؟ <a href="/signup">أنشئ حساب</a></p>', "اسم المستخدم أو كلمة المرور غير صحيحة")
    session["user_id"] = user["id"]
    session["username"] = user["username"]
    session["is_admin"] = bool(user["is_admin"])
    return redirect("/")


@app.route("/signup", methods=["GET", "POST"])
def signup_page():
    if request.method == "GET":
        return render_auth_page("إنشاء حساب", "/signup", '<p class="switch">عندك حساب؟ <a href="/login">سجّل الدخول</a></p>', "")
    username = request.form.get("username", "").strip()
    password = request.form.get("password", "")
    switch = '<p class="switch">عندك حساب؟ <a href="/login">سجّل الدخول</a></p>'
    if not username or not password:
        return render_auth_page("إنشاء حساب", "/signup", switch, "عبّي كل الحقول")
    if db_get_user_by_username(username):
        return render_auth_page("إنشاء حساب", "/signup", switch, "اسم المستخدم مستخدم من قبل")
    is_admin = db_count_users() == 0
    user_id = db_create_user(username, generate_password_hash(password), is_admin)
    session["user_id"] = user_id
    session["username"] = username
    session["is_admin"] = is_admin
    return redirect("/")


@app.route("/logout", methods=["POST"])
def logout_page():
    session.clear()
    return redirect("/login")


# ---------------------------------------------------------------- الصفحة الرئيسية وملفات PWA

@app.route("/")
def home():
    if not session.get("user_id"):
        return redirect("/login")
    page = PAGE.replace("__IS_ADMIN__", "true" if session.get("is_admin") else "false")
    page = page.replace("__USERNAME__", session.get("username", ""))
    return page


@app.route("/manifest.json")
def manifest():
    return jsonify({
        "name": "منصة حملات واتساب",
        "short_name": "حملات واتساب",
        "start_url": "/",
        "display": "standalone",
        "background_color": "#f5f0e8",
        "theme_color": "#b8860b",
        "dir": "rtl",
        "lang": "ar",
        "icons": [{"src": "/icon.svg", "sizes": "any", "type": "image/svg+xml", "purpose": "any maskable"}],
    })


@app.route("/icon.svg")
def icon():
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">'
        '<rect width="128" height="128" rx="28" fill="#b8860b"/>'
        '<circle cx="64" cy="64" r="30" fill="#fff"/>'
        "</svg>"
    )
    return Response(svg, mimetype="image/svg+xml")


@app.route("/sw.js")
def service_worker():
    js = (
        "self.addEventListener('install', e => self.skipWaiting());\n"
        "self.addEventListener('activate', e => self.clients.claim());\n"
        "self.addEventListener('notificationclick', e => {\n"
        "  e.notification.close();\n"
        "  e.waitUntil(clients.openWindow('/'));\n"
        "});\n"
    )
    return Response(js, mimetype="application/javascript")


@app.route("/events")
@login_required
def get_events():
    since = int(request.args.get("since", 0) or 0)
    uid = session["user_id"]
    my_account_names = {a["name"] for a in accounts.values() if a["owner"] == uid}
    with events_lock:
        return jsonify([e for e in events if e["id"] > since and e["account"] in my_account_names])


# ---------------------------------------------------------------- إعدادات الأدمن (AI)

@app.route("/admin/ai_settings", methods=["GET"])
@login_required
@admin_required
def get_ai_settings():
    row = db_get_ai_settings()
    return jsonify(api_key_set=bool(row and row["api_key"]), knowledge_base=(row["knowledge_base"] if row else ""))


@app.route("/admin/ai_settings", methods=["POST"])
@login_required
@admin_required
def set_ai_settings():
    data = request.json or {}
    existing = db_get_ai_settings()
    api_key = (data.get("api_key") or "").strip()
    if not api_key and existing:
        api_key = existing["api_key"]
    knowledge_base = data.get("knowledge_base") or ""
    db_set_ai_settings(api_key, knowledge_base)
    return jsonify(ok=True)


# ---------------------------------------------------------------- حسابات واتساب (لكل مستخدم)

@app.route("/accounts", methods=["GET"])
@login_required
def list_accounts():
    uid = session["user_id"]
    return jsonify([
        {"id": aid, "name": a["name"], "logged_in": account_logged_in(a)}
        for aid, a in accounts.items() if a["owner"] == uid
    ])


@app.route("/accounts", methods=["POST"])
@login_required
def create_account():
    data = request.json or {}
    acc_id = add_account(session["user_id"], (data.get("name") or "").strip())
    return jsonify(id=acc_id, name=accounts[acc_id]["name"])


@app.route("/accounts/<acc_id>/logout", methods=["POST"])
@login_required
def account_logout(acc_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(ok=False, error="حساب غير موجود"), 404
    acc["watching"] = False
    if acc["driver"] is not None:
        try:
            acc["driver"].quit()
        except Exception:
            pass
    shutil.rmtree(f"{SESSIONS_ROOT}/{acc_id}", ignore_errors=True)
    del accounts[acc_id]
    return jsonify(ok=True)


@app.route("/accounts/<acc_id>/qr")
@login_required
def qr(acc_id):
    acc = get_owned_account(acc_id)
    if not acc or acc["driver"] is None:
        return "", 204
    try:
        canvas = acc["driver"].find_element(By.TAG_NAME, "canvas")
        return Response(canvas.screenshot_as_png, mimetype="image/png")
    except Exception:
        return "", 204


@app.route("/accounts/<acc_id>/debug")
@login_required
def debug(acc_id):
    acc = get_owned_account(acc_id)
    if not acc or acc["driver"] is None:
        return "driver لسا ما بدأ", 503
    return Response(acc["driver"].get_screenshot_as_png(), mimetype="image/png")


@app.route("/accounts/<acc_id>/status")
@login_required
def status(acc_id):
    acc = get_owned_account(acc_id)
    return jsonify(logged_in=bool(acc) and account_logged_in(acc))


@app.route("/accounts/<acc_id>/campaign", methods=["POST"])
@login_required
def campaign(acc_id):
    acc = get_owned_account(acc_id)
    if not acc or acc["driver"] is None:
        return jsonify(ok=False, error="تأكد من تسجيل الدخول أولاً"), 400
    if acc["campaign"]["running"]:
        return jsonify(ok=False, error="فيه حملة شغالة أو مجدولة حالياً على هذا الحساب"), 400

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

    run_at = None
    send_at = request.form.get("send_at", "").strip()
    if send_at:
        try:
            run_at = datetime.fromisoformat(send_at)
        except ValueError:
            run_at = None

    acc["campaign"].update(total=len(numbers), sent=0, failed=0, running=True, failed_numbers=[], scheduled_for=None)

    if run_at and run_at > datetime.now():
        acc["campaign"]["scheduled_for"] = run_at.strftime("%Y-%m-%d %H:%M")
        wait_seconds = (run_at - datetime.now()).total_seconds()

        def delayed():
            time.sleep(wait_seconds)
            run_campaign(acc, numbers, text, delay, media_path)

        threading.Thread(target=delayed, daemon=True).start()
    else:
        threading.Thread(target=run_campaign, args=(acc, numbers, text, delay, media_path), daemon=True).start()

    return jsonify(ok=True, total=len(numbers), scheduled_for=acc["campaign"]["scheduled_for"])


@app.route("/accounts/<acc_id>/campaign_status")
@login_required
def campaign_status(acc_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(total=0, sent=0, failed=0, running=False, failed_numbers=[], scheduled_for=None)
    return jsonify(**acc["campaign"])


@app.route("/accounts/<acc_id>/campaigns")
@login_required
def campaign_history(acc_id):
    acc = get_owned_account(acc_id)
    return jsonify(acc["history"] if acc else [])


@app.route("/accounts/<acc_id>/auto_reply", methods=["GET"])
@login_required
def get_auto_reply(acc_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(enabled=False, mode="keywords", rules=[])
    return jsonify(**acc["auto_reply"])


@app.route("/accounts/<acc_id>/auto_reply", methods=["POST"])
@login_required
def set_auto_reply(acc_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(ok=False, error="حساب غير موجود"), 404
    data = request.json or {}
    acc["auto_reply"]["rules"] = [
        {"keyword": (r.get("keyword") or "").strip(), "reply": (r.get("reply") or "").strip()}
        for r in data.get("rules", [])
        if (r.get("keyword") or "").strip()
    ]
    acc["auto_reply"]["mode"] = "ai" if data.get("mode") == "ai" else "keywords"
    was_enabled = acc["auto_reply"]["enabled"]
    enabled = bool(data.get("enabled"))
    acc["auto_reply"]["enabled"] = enabled
    if enabled and not was_enabled:
        acc["watching"] = True
        threading.Thread(target=watch_account, args=(acc_id,), daemon=True).start()
    elif not enabled:
        acc["watching"] = False
    return jsonify(ok=True)


PAGE = """
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>منصة حملات واتساب</title>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#b8860b">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root {
    --bg: #f5f0e8; --card: #ffffff; --card-soft: #fcf8f0; --ink: #1a1a1a; --muted: #8a8a8a;
    --gold: #b8860b; --gold-border: rgba(184,134,11,.25); --gold-light: rgba(184,134,11,.07);
    --shadow: rgba(0,0,0,.04);
  }
  html[data-theme="dark"] {
    --bg: #17140f; --card: #241f18; --card-soft: #2b2419; --ink: #f2ede0; --muted: #a89a80;
    --gold: #e6b73e; --gold-border: rgba(230,183,62,.3); --gold-light: rgba(230,183,62,.1);
    --shadow: rgba(0,0,0,.3);
  }
  html, body { margin: 0; padding: 0; background: var(--bg); color: var(--ink); font-family: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif; }
  .app { display: flex; flex-direction: column; min-height: 100vh; }

  .topbar { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: var(--card); border-bottom: 1px solid var(--gold-border); position: sticky; top: 0; z-index: 30; }
  .topbar-title { font-size: 14px; font-weight: 800; }
  .topbar-actions { display: flex; gap: 6px; }
  .icon-btn { position: relative; height: 34px; padding: 0 12px; border-radius: 10px; border: 1px solid var(--gold-border); background: var(--card); color: var(--gold); font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; }
  .badge { position: absolute; top: -3px; left: -3px; width: 9px; height: 9px; border-radius: 50%; background: #dc2626; }

  .notif-panel { position: absolute; top: 52px; left: 12px; width: 280px; max-height: 320px; overflow-y: auto; background: var(--card); border: 1px solid var(--gold-border); border-radius: 14px; box-shadow: 0 12px 30px var(--shadow); z-index: 40; padding: 8px; }
  .notif-item { padding: 8px 10px; border-radius: 10px; font-size: 12px; }
  .notif-item + .notif-item { margin-top: 4px; }
  .notif-item b { display: block; font-size: 12px; color: var(--gold); }
  .notif-item span { color: var(--muted); font-size: 11px; }

  .body { display: flex; flex: 1; }
  .nav { width: 220px; flex-shrink: 0; background: var(--card); border-left: 1px solid var(--gold-border); padding: 16px 10px; }
  .nav-item { padding: 11px 12px; border-radius: 12px; font-size: 13px; font-weight: 700; cursor: pointer; margin-bottom: 4px; transition: .15s ease; }
  .nav-item:hover { background: var(--gold-light); }
  .nav-item.active { background: var(--gold-light); border: 1px solid var(--gold-border); color: var(--gold); }
  .content { flex: 1; padding: 28px; display: flex; justify-content: center; }
  .content-inner { width: 100%; max-width: 620px; }
  .bottom-tabs { display: none; }

  @media (max-width: 780px) {
    .nav { display: none; }
    .content { padding: 16px 16px 84px; }
    .bottom-tabs {
      display: flex; position: fixed; bottom: 0; left: 0; right: 0; height: 62px;
      background: var(--card); border-top: 1px solid var(--gold-border); z-index: 30;
    }
    .bottom-tab { flex: 1; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: var(--muted); cursor: pointer; }
    .bottom-tab.active { color: var(--gold); }
  }

  .dark-card { background: var(--card); border: 1px solid var(--gold-border); box-shadow: 0 4px 20px var(--shadow); }
  .glossy-card {
    background: linear-gradient(145deg, var(--card), var(--card-soft));
    border: 1.5px solid var(--gold-border);
    box-shadow: 0 0 30px var(--shadow), inset 0 1px 0 var(--gold-light);
    position: relative; overflow: hidden;
  }
  .glossy-card::before {
    content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
    background: conic-gradient(from 0deg at 50% 50%, transparent 0%, var(--gold-light) 25%, transparent 50%, var(--gold-light) 75%, transparent 100%);
    animation: shimmerRotate 10s linear infinite; pointer-events: none;
  }
  @keyframes shimmerRotate { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
  .glossy-card .relative-z { position: relative; z-index: 1; }

  .text-gold { color: var(--gold); }
  .border-gold { border-color: var(--gold-border); }
  .bg-gold-light { background: var(--gold-light); }
  .text-muted { color: var(--muted); }

  .step-item { display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--gold-light); }
  .step-item:last-child { border-bottom: 0; }
  .step-num { width: 26px; height: 26px; border-radius: 50%; background: var(--gold-light); border: 1px solid var(--gold-border); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; color: var(--gold); }
  .step-text { font-size: 13px; color: var(--ink); font-weight: 500; }
  .step-text small { display: block; font-weight: 400; font-size: 11px; color: var(--muted); margin-top: 2px; }

  .field-label { display: block; margin-top: 16px; margin-bottom: 6px; font-size: 12px; color: var(--muted); font-weight: 500; }
  input, textarea, select {
    width: 100%; box-sizing: border-box; padding: 11px 12px; font-size: 14px; font-family: inherit;
    background: var(--card); border: 1px solid var(--gold-border); border-radius: 12px; color: var(--ink);
  }
  input:focus, textarea:focus, select:focus { outline: none; border-color: var(--gold); }
  textarea { resize: vertical; }
  input[type="file"] { padding: 9px 12px; }
  input[type="checkbox"] { width: auto; }

  .btn-gold {
    display: block; width: 100%; padding: 13px; margin-top: 18px; border: none; border-radius: 14px;
    background: var(--gold); color: #fff; font-weight: 700; font-size: 15px; font-family: inherit;
    cursor: pointer; transition: .2s ease;
  }
  .btn-gold:hover { filter: brightness(.92); }
  .btn-outline {
    display: block; width: 100%; padding: 11px; margin-top: 10px; border-radius: 14px;
    background: transparent; border: 1.5px solid var(--gold-border); color: var(--gold);
    font-weight: 700; font-size: 13px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-outline:hover { background: var(--gold-light); }
  .btn-danger {
    display: block; width: 100%; padding: 10px; margin-top: 12px; border-radius: 14px;
    background: transparent; border: 1.5px solid rgba(220,38,38,.3); color: #dc2626;
    font-weight: 700; font-size: 12px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-danger:hover { background: rgba(220,38,38,.06); }
  .btn-small { width: auto; padding: 8px 14px; margin-top: 0; display: inline-block; }

  #qrImg { width: 220px; height: 220px; border-radius: 16px; border: 1px solid var(--gold-border); background: #fff; display: block; margin: 0 auto; }

  .stat-row { display: flex; border-radius: 16px; overflow: hidden; margin-top: 14px; }
  .stat-cell { flex: 1; text-align: center; padding: 12px 4px; background: var(--card); border: 1px solid var(--gold-light); }
  .stat-cell + .stat-cell { border-right: none; }
  .stat-num { font-size: 16px; font-weight: 800; }
  .stat-label { font-size: 10px; color: var(--muted); margin-top: 2px; }

  .account-name { font-size: 13px; font-weight: 700; flex: 1; }
  .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .dot-on { background: #16a34a; box-shadow: 0 0 6px rgba(22,163,74,.6); }
  .dot-off { background: #d1a83a; }
  .empty-state { text-align: center; color: var(--muted); font-size: 13px; margin-top: 60px; }

  .history-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 4px; border-bottom: 1px solid var(--gold-light); font-size: 12px; }
  .history-row:last-child { border-bottom: none; }

  .rule-row { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
  .rule-row input { margin-top: 0; }
  .rule-remove { flex-shrink: 0; width: 32px; height: 32px; border-radius: 10px; border: 1px solid rgba(220,38,38,.3); background: transparent; color: #dc2626; cursor: pointer; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <span class="text-[11px] text-muted">__USERNAME__</span>
    <div class="topbar-title">منصة حملات واتساب</div>
    <div class="topbar-actions">
      <button class="icon-btn" id="themeBtn" onclick="toggleTheme()">داكن</button>
      <button class="icon-btn" id="bellBtn" onclick="toggleNotifPanel()">الإشعارات<span class="badge" id="bellBadge" style="display:none"></span></button>
    </div>
  </header>

  <div class="notif-panel" id="notifPanel" style="display:none"></div>

  <div class="body">
    <nav class="nav" id="nav">
      <div class="nav-item" data-s="accounts" onclick="showSection('accounts')">حسابي</div>
      <div class="nav-item" data-s="campaigns" onclick="showSection('campaigns')">الحملات</div>
      <div class="nav-item" data-s="autoreply" onclick="showSection('autoreply')">الرد الآلي</div>
      <div class="nav-item" data-s="settings" onclick="showSection('settings')">الإعدادات</div>
    </nav>
    <main class="content"><div class="content-inner" id="content"></div></main>
  </div>

  <nav class="bottom-tabs" id="bottomTabs">
    <div class="bottom-tab" data-s="accounts" onclick="showSection('accounts')">حسابي</div>
    <div class="bottom-tab" data-s="campaigns" onclick="showSection('campaigns')">الحملات</div>
    <div class="bottom-tab" data-s="autoreply" onclick="showSection('autoreply')">الرد الآلي</div>
    <div class="bottom-tab" data-s="settings" onclick="showSection('settings')">الإعدادات</div>
  </nav>
</div>

<script>
const IS_ADMIN = __IS_ADMIN__;
let accounts = [];
let section = 'accounts';
let activeId = null;
let gen = 0;
let lastSeenEventId = -1;

/* ---------- تصميم داكن/نهاري ---------- */
function applyTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  localStorage.setItem('theme', t);
  const btn = document.getElementById('themeBtn');
  if (btn) btn.innerText = t === 'dark' ? 'نهاري' : 'داكن';
}
function toggleTheme() {
  applyTheme((document.documentElement.getAttribute('data-theme') || 'light') === 'dark' ? 'light' : 'dark');
}
applyTheme(localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));

/* ---------- PWA + إشعارات ---------- */
if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js').catch(() => {}); }

function ensureNotifPermission() {
  if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
}
function showLocalNotification(title, body) {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  if (navigator.serviceWorker && navigator.serviceWorker.ready) {
    navigator.serviceWorker.ready.then(reg => reg.showNotification(title, { body: body })).catch(() => new Notification(title, { body: body }));
  } else {
    new Notification(title, { body: body });
  }
}

async function pollEvents() {
  try {
    const evs = await fetch('/events?since=0').then(r => r.json());
    if (lastSeenEventId === -1) {
      lastSeenEventId = evs.length ? Math.max(...evs.map(e => e.id)) : 0;
    } else {
      const fresh = evs.filter(e => e.id > lastSeenEventId);
      fresh.forEach(e => showLocalNotification(e.title, e.account + ': ' + e.body));
      if (fresh.length) lastSeenEventId = Math.max(...evs.map(e => e.id));
    }
    document.getElementById('bellBadge').style.display = evs.length ? 'block' : 'none';
    window._events = evs;
  } catch (e) {}
  setTimeout(pollEvents, 5000);
}
pollEvents();

function toggleNotifPanel() {
  const panel = document.getElementById('notifPanel');
  if (panel.style.display === 'block') { panel.style.display = 'none'; return; }
  const evs = (window._events || []).slice().reverse();
  panel.innerHTML = evs.length
    ? evs.map(e => '<div class="notif-item"><b>' + e.title + '</b>' + e.account + ' — ' + e.body + '<br><span>' + e.time + '</span></div>').join('')
    : '<div class="notif-item text-muted">ما فيه إشعارات بعد</div>';
  panel.style.display = 'block';
}

/* ---------- التنقل بين الأقسام ---------- */
function showSection(s) {
  section = s;
  document.querySelectorAll('.nav-item, .bottom-tab').forEach(n => n.classList.toggle('active', n.dataset.s === s));
  render();
}
showSection('accounts');

async function loadAccounts(preferId) {
  accounts = await fetch('/accounts').then(r => r.json());
  if (preferId) activeId = preferId;
  if (!accounts.find(a => a.id === activeId)) activeId = accounts.length ? accounts[0].id : null;
}

async function render() {
  gen++;
  const myGen = gen;
  await loadAccounts();
  if (myGen !== gen) return;
  if (section === 'accounts') renderAccounts();
  else if (section === 'campaigns') renderCampaigns(myGen);
  else if (section === 'autoreply') renderAutoReply();
  else renderSettings();
}

/* ---------- قسم حسابي ---------- */
function renderAccounts() {
  const c = document.getElementById('content');
  let html = '<h2 class="text-sm font-extrabold text-gold mb-3">حسابي</h2>';
  if (!accounts.length) {
    html += '<div class="empty-state">ما عندك حسابات بعد، ضيف واحد للبدء</div>';
  }
  accounts.forEach(acc => {
    html += '<div class="dark-card rounded-2xl p-3 mb-3">' +
      '<div class="flex items-center gap-2.5 mb-2">' +
      '<span class="dot ' + (acc.logged_in ? 'dot-on' : 'dot-off') + '"></span>' +
      '<span class="account-name">' + acc.name + '</span>' +
      '<span class="text-[10px] text-muted">' + (acc.logged_in ? 'متصل' : 'غير متصل') + '</span>' +
      '</div>';
    if (!acc.logged_in) {
      html += '<div class="text-center"><img id="qrImg-' + acc.id + '" src="/accounts/' + acc.id + '/qr" style="width:170px;height:170px;border-radius:12px;border:1px solid var(--gold-border);background:#fff"><p class="text-[10px] text-muted mt-1">امسح الرمز من واتساب بجوالك</p></div>';
    } else {
      html += '<button class="btn-danger" onclick="logoutAccount(\\'' + acc.id + '\\')">تسجيل الخروج</button>';
    }
    html += '</div>';
  });
  html += '<button class="btn-outline" onclick="addAccount()">+ إضافة حساب</button>';
  c.innerHTML = html;

  accounts.filter(a => !a.logged_in).forEach(acc => pollLogin(acc.id, gen));
}

async function pollLogin(accId, myGen) {
  if (myGen !== gen) return;
  const r = await fetch('/accounts/' + accId + '/status').then(res => res.json());
  if (myGen !== gen) return;
  if (r.logged_in) { render(); return; }
  const img = document.getElementById('qrImg-' + accId);
  if (img) img.src = '/accounts/' + accId + '/qr?' + Date.now();
  setTimeout(() => pollLogin(accId, myGen), 3000);
}

async function addAccount() {
  const name = prompt('اسم الحساب (اختياري):', '');
  if (name === null) return;
  const r = await fetch('/accounts', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({name: name.trim()}) }).then(res => res.json());
  await loadAccounts(r.id);
  render();
}

async function logoutAccount(id) {
  if (!confirm('تسجيل الخروج من هذا الحساب؟ بيحتاج مسح رمز QR من جديد لاحقاً.')) return;
  await fetch('/accounts/' + id + '/logout', { method: 'POST' });
  activeId = null;
  render();
}

/* ---------- منتقي الحساب المشترك ---------- */
function accountSelectHtml(onchangeFn) {
  if (!accounts.length) return '<div class="empty-state">ضيف حساباً من قسم "حسابي" أول</div>';
  let html = '<select id="accPicker" onchange="' + onchangeFn + '(this.value)">';
  accounts.forEach(a => {
    html += '<option value="' + a.id + '"' + (a.id === activeId ? ' selected' : '') + '>' + a.name + (a.logged_in ? '' : ' (غير متصل)') + '</option>';
  });
  html += '</select>';
  return html;
}

/* ---------- قسم الحملات ---------- */
function renderCampaigns(myGen) {
  const c = document.getElementById('content');
  if (!accounts.length) { c.innerHTML = '<div class="empty-state">ضيف حساباً من قسم "حسابي" أول</div>'; return; }
  const acc = accounts.find(a => a.id === activeId);

  let html = '<h2 class="text-sm font-extrabold text-gold mb-3">الحملات</h2>';
  html += '<label class="field-label">الحساب</label>' + accountSelectHtml('switchCampaignAccount');

  if (!acc || !acc.logged_in) {
    html += '<div class="dark-card rounded-2xl p-4 mt-3 text-center text-muted text-[12px]">هذا الحساب غير متصل بعد — أكمل تسجيل الدخول من قسم "حسابي"</div>';
    c.innerHTML = html;
    return;
  }

  html +=
    '<div class="dark-card rounded-2xl p-4 mt-3">' +
    '<label class="field-label">الأرقام (رقم كل سطر، أو مفصولة بفواصل)</label>' +
    '<textarea id="numbersText" rows="4" placeholder="9647701234567&#10;9647709876543"></textarea>' +
    '<button class="btn-outline" id="contactPickerBtn" style="display:none" onclick="pickContacts()">استيراد من جهات الاتصال</button>' +
    '<label class="field-label">أو ارفع ملف Excel (.xlsx) فيه الأرقام بالعمود الأول</label>' +
    '<input type="file" id="numbersFile" accept=".xlsx">' +
    '<label class="field-label">نص الرسالة / التعليق</label>' +
    '<input id="text" value="هلوو">' +
    '<label class="field-label">صورة، فيديو، أو ملف (اختياري)</label>' +
    '<input type="file" id="mediaFile" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.zip">' +
    '<label class="field-label">الفاصل الزمني بين كل رسالة وأخرى (بالثواني)</label>' +
    '<input id="delay" type="number" min="1" value="15">' +
    '<label class="field-label">جدولة الإرسال (اختياري — اتركه فاضي للإرسال الفوري)</label>' +
    '<input id="sendAt" type="datetime-local">' +
    '<button class="btn-gold" onclick="startCampaign()">بدء الإرسال</button>' +
    '</div>' +
    '<div class="stat-row" id="statRow" style="display:none">' +
    '<div class="stat-cell"><div class="stat-num text-emerald-600" id="statSent">0</div><div class="stat-label">تم الإرسال</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-red-500" id="statFailed">0</div><div class="stat-label">فشل</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-gold" id="statTotal">0</div><div class="stat-label">الإجمالي</div></div>' +
    '</div>' +
    '<div id="msg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">نتائج الحملات السابقة</h3>' +
    '<div class="dark-card rounded-2xl p-3" id="historyBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>';

  c.innerHTML = html;

  if ('contacts' in navigator && 'ContactsManager' in window) {
    document.getElementById('contactPickerBtn').style.display = 'block';
  }

  loadHistory(acc.id);
  refreshCampaignState(acc.id, myGen);
}

function switchCampaignAccount(id) { activeId = id; render(); }

async function loadHistory(accId) {
  const rows = await fetch('/accounts/' + accId + '/campaigns').then(r => r.json());
  const box = document.getElementById('historyBox');
  if (!box) return;
  box.innerHTML = rows.length
    ? rows.map(h => '<div class="history-row"><span>' + h.time + '</span><span class="text-emerald-600">نجح ' + h.sent + '</span><span class="text-red-500">فشل ' + h.failed + '</span><span class="text-muted">من ' + h.total + '</span></div>').join('')
    : '<div class="text-muted text-[11px]">ما فيه حملات سابقة</div>';
}

async function pickContacts() {
  try {
    const contacts = await navigator.contacts.select(['tel'], { multiple: true });
    const nums = [];
    contacts.forEach(c => (c.tel || []).forEach(t => nums.push(t)));
    const ta = document.getElementById('numbersText');
    ta.value += (ta.value ? '\\n' : '') + nums.join('\\n');
  } catch (e) {}
}

async function startCampaign() {
  const accId = activeId;
  const form = new FormData();
  form.append('numbers_text', document.getElementById('numbersText').value);
  form.append('text', document.getElementById('text').value.trim());
  form.append('delay', document.getElementById('delay').value || 15);
  form.append('send_at', document.getElementById('sendAt').value || '');
  const numbersFile = document.getElementById('numbersFile').files[0];
  if (numbersFile) form.append('numbers_file', numbersFile);
  const mediaFile = document.getElementById('mediaFile').files[0];
  if (mediaFile) form.append('media_file', mediaFile);

  ensureNotifPermission();
  document.getElementById('msg').innerText = 'جارِ البدء...';
  const r = await fetch('/accounts/' + accId + '/campaign', { method: 'POST', body: form }).then(res => res.json());
  if (!r.ok) { document.getElementById('msg').innerText = 'فشل: ' + r.error; return; }
  document.getElementById('msg').innerText = r.scheduled_for ? ('مجدولة لـ ' + r.scheduled_for) : '';
  document.getElementById('statRow').style.display = 'flex';
  refreshCampaignState(accId, gen);
}

async function refreshCampaignState(accId, myGen) {
  if (myGen !== gen) return;
  const r = await fetch('/accounts/' + accId + '/campaign_status').then(res => res.json());
  if (myGen !== gen) return;
  const sentEl = document.getElementById('statSent');
  if (!sentEl) return;
  if (r.total > 0) document.getElementById('statRow').style.display = 'flex';
  sentEl.innerText = r.sent;
  document.getElementById('statFailed').innerText = r.failed;
  document.getElementById('statTotal').innerText = r.total;
  const msg = document.getElementById('msg');
  if (r.scheduled_for && !r.running) msg.innerText = '';
  else if (r.scheduled_for) msg.innerText = 'مجدولة لـ ' + r.scheduled_for;
  if (r.running || r.scheduled_for) {
    setTimeout(() => refreshCampaignState(accId, myGen), 2000);
  } else if (r.total > 0 && msg.innerText === '') {
    msg.innerText = 'انتهت الحملة';
    loadHistory(accId);
  }
}

/* ---------- قسم الرد الآلي ---------- */
let autoReplyRules = [];

function renderAutoReply() {
  const c = document.getElementById('content');
  if (!accounts.length) { c.innerHTML = '<div class="empty-state">ضيف حساباً من قسم "حسابي" أول</div>'; return; }
  const acc = accounts.find(a => a.id === activeId);

  let html = '<h2 class="text-sm font-extrabold text-gold mb-3">الرد الآلي</h2>';
  html += '<label class="field-label">الحساب</label>' + accountSelectHtml('switchAutoReplyAccount');

  if (!acc || !acc.logged_in) {
    html += '<div class="dark-card rounded-2xl p-4 mt-3 text-center text-muted text-[12px]">هذا الحساب غير متصل بعد</div>';
    c.innerHTML = html;
    return;
  }

  html +=
    '<div class="dark-card rounded-2xl p-4 mt-3">' +
    '<label class="flex items-center gap-2 text-[13px] font-bold"><input type="checkbox" id="arEnabled"> تفعيل الرد الآلي لهذا الحساب</label>' +
    '<label class="field-label">طريقة الرد</label>' +
    '<select id="arMode"><option value="keywords">كلمات مفتاحية</option><option value="ai">ذكاء اصطناعي (DeepSeek)</option></select>' +
    '<div id="rulesBox" class="mt-3"></div>' +
    '<button class="btn-outline" id="addRuleBtn" onclick="addRule()">+ إضافة كلمة مفتاحية</button>' +
    '<p id="aiNote" class="text-[11px] text-muted mt-2" style="display:none">الرد يعتمد على مفتاح DeepSeek وقائمة المنتجات/الأسعار اللي يضيفها الأدمن من قسم الإعدادات.</p>' +
    '<button class="btn-gold" onclick="saveAutoReply()">حفظ</button>' +
    '<div id="arMsg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '</div>';
  c.innerHTML = html;

  document.getElementById('arMode').onchange = updateAutoReplyModeUI;

  fetch('/accounts/' + acc.id + '/auto_reply').then(r => r.json()).then(d => {
    document.getElementById('arEnabled').checked = !!d.enabled;
    document.getElementById('arMode').value = d.mode || 'keywords';
    autoReplyRules = d.rules && d.rules.length ? d.rules : [{keyword: 'سلام', reply: 'سلام عليكم'}];
    renderRules();
    updateAutoReplyModeUI();
  });
}

function updateAutoReplyModeUI() {
  const ai = document.getElementById('arMode').value === 'ai';
  document.getElementById('rulesBox').style.display = ai ? 'none' : 'block';
  document.getElementById('addRuleBtn').style.display = ai ? 'none' : 'block';
  document.getElementById('aiNote').style.display = ai ? 'block' : 'none';
}

function switchAutoReplyAccount(id) { activeId = id; render(); }

function renderRules() {
  const box = document.getElementById('rulesBox');
  if (!box) return;
  box.innerHTML = autoReplyRules.map((r, i) =>
    '<div class="rule-row">' +
    '<input placeholder="كلمة مفتاحية" value="' + (r.keyword || '').replace(/"/g, '&quot;') + '" onchange="autoReplyRules[' + i + '].keyword=this.value">' +
    '<input placeholder="الرد" value="' + (r.reply || '').replace(/"/g, '&quot;') + '" onchange="autoReplyRules[' + i + '].reply=this.value">' +
    '<button class="rule-remove" onclick="removeRule(' + i + ')">✕</button>' +
    '</div>'
  ).join('');
}
function addRule() { autoReplyRules.push({keyword: '', reply: ''}); renderRules(); }
function removeRule(i) { autoReplyRules.splice(i, 1); renderRules(); }

async function saveAutoReply() {
  const acc = accounts.find(a => a.id === activeId);
  if (!acc) return;
  ensureNotifPermission();
  const enabled = document.getElementById('arEnabled').checked;
  const mode = document.getElementById('arMode').value;
  const r = await fetch('/accounts/' + acc.id + '/auto_reply', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({enabled: enabled, mode: mode, rules: autoReplyRules})
  }).then(res => res.json());
  document.getElementById('arMsg').innerText = r.ok ? 'تم الحفظ' : ('فشل: ' + r.error);
}

/* ---------- قسم الإعدادات ---------- */
function renderSettings() {
  const c = document.getElementById('content');
  const notifState = ('Notification' in window) ? Notification.permission : 'غير مدعوم';
  let html =
    '<h2 class="text-sm font-extrabold text-gold mb-3">الإعدادات</h2>' +
    '<div class="dark-card rounded-2xl p-4">' +
    '<div class="flex items-center justify-between mb-3"><span class="text-[13px] font-bold">المظهر</span>' +
    '<button class="btn-outline btn-small" onclick="toggleTheme()">تبديل داكن/نهاري</button></div>' +
    '<div class="flex items-center justify-between"><span class="text-[13px] font-bold">الإشعارات (' + notifState + ')</span>' +
    '<button class="btn-outline btn-small" onclick="ensureNotifPermission()">تفعيل</button></div>' +
    '<p class="text-[11px] text-muted mt-3">هذا التطبيق PWA — تقدر تضيفه لشاشتك الرئيسية من قائمة المتصفح "إضافة إلى الشاشة الرئيسية".</p>' +
    '</div>' +
    '<button class="btn-danger" onclick="logoutPlatform()">تسجيل الخروج من المنصة</button>';

  if (IS_ADMIN) {
    html +=
      '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">إعدادات الأدمن — الرد الذكي (DeepSeek)</h3>' +
      '<div class="dark-card rounded-2xl p-4">' +
      '<label class="field-label">مفتاح API (يُترك فاضي لعدم التغيير)</label>' +
      '<input id="aiKey" type="password" placeholder="sk-...">' +
      '<label class="field-label">قائمة المنتجات والأسعار (يعتمد عليها الرد الذكي)</label>' +
      '<textarea id="aiKb" rows="6" placeholder="مثال:&#10;منتج أ - 10 دولار&#10;منتج ب - 15 دولار"></textarea>' +
      '<button class="btn-gold" onclick="saveAiSettings()">حفظ إعدادات الأدمن</button>' +
      '<div id="aiMsg" class="text-center text-[12px] font-bold mt-2"></div>' +
      '</div>';
  }

  c.innerHTML = html;

  if (IS_ADMIN) {
    fetch('/admin/ai_settings').then(r => r.json()).then(d => {
      document.getElementById('aiKey').placeholder = d.api_key_set ? 'محفوظ مسبقاً (اتركه فاضي للإبقاء عليه)' : 'sk-...';
      document.getElementById('aiKb').value = d.knowledge_base || '';
    });
  }
}

async function saveAiSettings() {
  const r = await fetch('/admin/ai_settings', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({api_key: document.getElementById('aiKey').value.trim(), knowledge_base: document.getElementById('aiKb').value})
  }).then(res => res.json());
  document.getElementById('aiMsg').innerText = r.ok ? 'تم الحفظ' : ('فشل: ' + r.error);
  document.getElementById('aiKey').value = '';
}

async function logoutPlatform() {
  if (!confirm('تسجيل الخروج من المنصة؟')) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '/logout';
  document.body.appendChild(form);
  form.submit();
}
</script>
</body>
</html>
"""


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 5000)), threaded=True)
