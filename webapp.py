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
import secrets
import shutil
import sqlite3
import threading
import time
import urllib.parse
import uuid
from datetime import datetime, timedelta
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
SECRET_KEY_PATH = "./secret.key"
DEFAULT_MESSAGE = "هلوو"
DEFAULT_DELAY = 15
EVENTS_MAX = 50


def get_or_create_secret_key():
    """مفتاح ثابت يُقرأ من ملف بدل ما يتولد عشوائياً كل تشغيل، حتى ما يطلع كل المستخدمين
    من جلساتهم لمجرد إعادة تشغيل السيرفر."""
    env_key = os.environ.get("SECRET_KEY")
    if env_key:
        return env_key
    if os.path.exists(SECRET_KEY_PATH):
        return open(SECRET_KEY_PATH, encoding="utf-8").read().strip()
    key = uuid.uuid4().hex + uuid.uuid4().hex
    with open(SECRET_KEY_PATH, "w", encoding="utf-8") as f:
        f.write(key)
    return key


app = Flask(__name__)
app.secret_key = get_or_create_secret_key()
app.config["PERMANENT_SESSION_LIFETIME"] = timedelta(days=30)

accounts = {}  # id -> {id, owner, name, driver, lock, campaign, history, auto_reply, watching, otp_sender}
events = []
events_lock = threading.Lock()
otp_codes = {}  # phone -> {code, expires, verified}
otp_lock = threading.Lock()
trial_check_cache = {}  # user_id -> آخر وقت (monotonic) تحقق فيه من انتهاء التجربة
trial_check_lock = threading.Lock()
TRIAL_CHECK_INTERVAL = 60  # ثواني - يمنع ضرب قاعدة البيانات بكل استطلاع إشعارات (كل 5 ثواني)


# ---------------------------------------------------------------- قاعدة البيانات

def get_db():
    conn = sqlite3.connect(DB_PATH, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=10000")
    return conn


def init_db():
    conn = get_db()
    conn.execute("""
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE,
            phone TEXT UNIQUE,
            name TEXT,
            password_hash TEXT,
            is_admin INTEGER NOT NULL DEFAULT 0,
            plan_active INTEGER NOT NULL DEFAULT 0,
            trial_ends_at TEXT,
            trial_ended_notified INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT ''
        )
    """)
    # ترقية قاعدة بيانات قديمة كانت تستخدم اسم مستخدم بدل بريد إلكتروني
    cols = [r["name"] for r in conn.execute("PRAGMA table_info(users)").fetchall()]
    if "username" in cols and "email" not in cols:
        conn.execute("ALTER TABLE users RENAME COLUMN username TO email")
        cols = [r["name"] for r in conn.execute("PRAGMA table_info(users)").fetchall()]
    if "plan_active" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN plan_active INTEGER NOT NULL DEFAULT 0")
    if "created_at" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN created_at TEXT NOT NULL DEFAULT ''")
    if "phone" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN phone TEXT")
    if "name" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN name TEXT")
    if "trial_ends_at" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN trial_ends_at TEXT")
        # كل حساب كان موجود قبل إضافة نظام التجربة/الاشتراك يبقى مفعّل تلقائياً، حتى لا
        # نقفل الوصول عن حسابات حقيقية (وبينها حساب الأدمن نفسه) لمجرد إضافة هذا العمود
        conn.execute("UPDATE users SET plan_active = 1 WHERE trial_ends_at IS NULL")
    if "trial_ended_notified" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN trial_ended_notified INTEGER NOT NULL DEFAULT 0")
    # الدخول عبر واتساب فقط (بدون كلمة مرور) يحتاج email و password_hash تقبل NULL - نعيد
    # بناء الجدول لو كان لسا يفرض NOT NULL من نسخة قديمة، حتى لا نفقد أي مستخدمين موجودين
    cols_info = conn.execute("PRAGMA table_info(users)").fetchall()
    needs_recreate = any(r["name"] in ("email", "password_hash") and r["notnull"] for r in cols_info)
    if needs_recreate:
        conn.execute("ALTER TABLE users RENAME TO users_old")
        conn.execute("""
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE,
                phone TEXT UNIQUE,
                name TEXT,
                password_hash TEXT,
                is_admin INTEGER NOT NULL DEFAULT 0,
                plan_active INTEGER NOT NULL DEFAULT 0,
                trial_ends_at TEXT,
                trial_ended_notified INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT ''
            )
        """)
        conn.execute(
            "INSERT INTO users (id, email, phone, name, password_hash, is_admin, plan_active, trial_ends_at, trial_ended_notified, created_at) "
            "SELECT id, email, phone, name, password_hash, is_admin, plan_active, trial_ends_at, trial_ended_notified, created_at FROM users_old"
        )
        conn.execute("DROP TABLE users_old")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS ai_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            api_key TEXT DEFAULT '',
            knowledge_base TEXT DEFAULT ''
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            plan_name TEXT NOT NULL,
            amount_iqd INTEGER NOT NULL,
            reference TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL
        )
    """)
    conn.commit()
    conn.close()


init_db()

PLAN_NAME = "الخطة الاحترافية"
PLAN_PRICE_IQD = 60000
TRIAL_DAYS = 3
WHATSAPP_PAY_NUMBER = "9647763835403"  # رقم واتساب لتفعيل الاشتراك، عدّله لرقمك الفعلي لو تغيّر


def user_has_access(user):
    """مفعّل باشتراك حقيقي، أو لسا داخل فترة التجربة المجانية (3 أيام من إنشاء الحساب)."""
    if user["plan_active"]:
        return True
    trial_ends_at = user["trial_ends_at"]
    if not trial_ends_at:
        return False
    try:
        return datetime.now() < datetime.strptime(trial_ends_at, "%Y-%m-%d %H:%M")
    except ValueError:
        return False


def db_get_user_by_email(email):
    conn = get_db()
    row = conn.execute("SELECT * FROM users WHERE email = ?", (email,)).fetchone()
    conn.close()
    return row


def db_get_user_by_phone(phone):
    conn = get_db()
    row = conn.execute("SELECT * FROM users WHERE phone = ?", (phone,)).fetchone()
    conn.close()
    return row


def db_get_user_by_id(user_id):
    conn = get_db()
    row = conn.execute("SELECT * FROM users WHERE id = ?", (user_id,)).fetchone()
    conn.close()
    return row


def db_create_user_by_phone(phone, password_hash, is_admin, name=None):
    conn = get_db()
    now = datetime.now()
    cur = conn.execute(
        "INSERT INTO users (phone, name, password_hash, is_admin, plan_active, trial_ends_at, created_at) VALUES (?, ?, ?, ?, 0, ?, ?)",
        (phone, name, password_hash, int(is_admin),
         (now + timedelta(days=TRIAL_DAYS)).strftime("%Y-%m-%d %H:%M"), now.strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    user_id = cur.lastrowid
    conn.close()
    return user_id


def db_count_users():
    conn = get_db()
    n = conn.execute("SELECT COUNT(*) c FROM users").fetchone()["c"]
    conn.close()
    return n


def db_create_user(email, password_hash, is_admin, name=None):
    conn = get_db()
    now = datetime.now()
    cur = conn.execute(
        "INSERT INTO users (email, name, password_hash, is_admin, plan_active, trial_ends_at, created_at) VALUES (?, ?, ?, ?, 0, ?, ?)",
        (email, name, password_hash, int(is_admin),
         (now + timedelta(days=TRIAL_DAYS)).strftime("%Y-%m-%d %H:%M"), now.strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    user_id = cur.lastrowid
    conn.close()
    return user_id


def db_list_users():
    conn = get_db()
    rows = conn.execute("SELECT id, email, phone, name, is_admin, plan_active, trial_ends_at, created_at FROM users ORDER BY id").fetchall()
    conn.close()
    return rows


def db_create_payment_request(user_id, plan_name, amount_iqd, reference):
    conn = get_db()
    conn.execute(
        "INSERT INTO payments (user_id, plan_name, amount_iqd, reference, status, created_at) "
        "VALUES (?, ?, ?, ?, 'pending', ?)",
        (user_id, plan_name, amount_iqd, reference, datetime.now().strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    conn.close()


def db_list_payments_for_user(user_id):
    conn = get_db()
    rows = conn.execute("SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC", (user_id,)).fetchall()
    conn.close()
    return rows


def db_list_pending_payments():
    conn = get_db()
    rows = conn.execute(
        "SELECT payments.*, users.email FROM payments "
        "JOIN users ON users.id = payments.user_id "
        "WHERE payments.status = 'pending' ORDER BY payments.id"
    ).fetchall()
    conn.close()
    return rows


def db_set_payment_status(payment_id, status):
    conn = get_db()
    row = conn.execute("SELECT * FROM payments WHERE id = ?", (payment_id,)).fetchone()
    if not row:
        conn.close()
        return False
    conn.execute("UPDATE payments SET status = ? WHERE id = ?", (status, payment_id))
    if status == "approved":
        conn.execute("UPDATE users SET plan_active = 1 WHERE id = ?", (row["user_id"],))
    conn.commit()
    conn.close()
    if status == "approved":
        add_event(row["user_id"], None, "تم تفعيل الاشتراك", "تم قبول دفعتك وتفعيل خطتك بنجاح", kind="success")
    return True


def db_set_plan_active(user_id, active):
    conn = get_db()
    conn.execute("UPDATE users SET plan_active = ? WHERE id = ?", (int(active), user_id))
    conn.commit()
    conn.close()
    if active:
        add_event(user_id, None, "تم تفعيل الاشتراك", "فعّل أدمن المنصة اشتراكك بنجاح", kind="success")


def db_mark_trial_ended_notified(user_id):
    conn = get_db()
    conn.execute("UPDATE users SET trial_ended_notified = 1 WHERE id = ?", (user_id,))
    conn.commit()
    conn.close()


def db_set_user_name(user_id, name):
    conn = get_db()
    conn.execute("UPDATE users SET name = ? WHERE id = ?", (name, user_id))
    conn.commit()
    conn.close()


def check_trial_ended_event(user):
    """يولّد إشعار 'انتهت الفترة التجريبية' مرة وحدة بس أول ما تنتهي، لأنه ما فيه حدث فعلي
    (مثل ضغطة زر) يوصلنا فيه الانتهاء - نتحقق منه تفاعلياً عند كل استطلاع للإشعارات."""
    if user["plan_active"] or user["trial_ended_notified"] or not user["trial_ends_at"]:
        return
    try:
        ended = datetime.now() >= datetime.strptime(user["trial_ends_at"], "%Y-%m-%d %H:%M")
    except ValueError:
        return
    if ended:
        add_event(user["id"], None, "انتهت الفترة التجريبية", "فعّل اشتراكك حتى تقدر تكمل إرسال الحملات والرد الآلي", kind="warning")
        db_mark_trial_ended_notified(user["id"])


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

def add_event(owner, account_name, title, body, kind="info"):
    with events_lock:
        events.append({
            "id": (events[-1]["id"] + 1) if events else 1,
            "owner": owner,
            "account": account_name,
            "title": title,
            "body": body,
            "kind": kind,
            "read": False,
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
        "ar_lock": threading.Lock(),
        "campaign": {"total": 0, "sent": 0, "failed": 0, "running": False, "failed_numbers": [], "scheduled_for": None},
        "history": [],
        "auto_reply": {"enabled": False, "ai_enabled": False, "rules": []},
        "watching": False,
        "otp_sender": False,
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


def find_otp_sender_account():
    """يبحث عن حساب واتساب واحد حدده الأدمن لإرسال رموز التحقق لتسجيل الدخول/الحسابات الجديدة."""
    for acc in accounts.values():
        if acc.get("otp_sender") and acc["driver"] is not None and account_logged_in(acc):
            return acc
    return None


def run_campaign(acc, numbers, text, delay, media_path):
    state = acc["campaign"]
    started = datetime.now().strftime("%Y-%m-%d %H:%M")
    add_event(acc["owner"], acc["name"], "بدأت حملة جديدة", f'جارِ إرسال {state["total"]} رسالة', kind="info")
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
    acc["history"].insert(0, {"time": started, "total": state["total"], "sent": state["sent"], "failed": state["failed"], "text": text})
    del acc["history"][20:]
    finish_kind = "success" if state["failed"] == 0 else "warning"
    add_event(acc["owner"], acc["name"], "اكتملت الحملة", f'نجح {state["sent"]} من {state["total"]}، فشل {state["failed"]}', kind=finish_kind)


def ai_reply(customer_message):
    settings = db_get_ai_settings()
    if not settings or not settings["api_key"]:
        print("[رد آلي] لا يوجد مفتاح DeepSeek محفوظ بقسم إعدادات الأدمن")
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
    except Exception as e:
        print(f"[رد آلي] فشل استدعاء DeepSeek: {e}")
        return None


def watch_account(acc_id):
    """مراقبة أفضل-جهد لعدة محادثات بالقائمة (مو الأولى بس)، ورد بكلمة مفتاحية إن وجدت
    وإلا بالذكاء الاصطناعي إن كان مفعّل. تطبع خطوات التشخيص بالتيرمنل للمساعدة بالتصحيح."""
    last_seen = {}
    diag_dumped = False
    print(f"[رد آلي] بدأت المراقبة لحساب {accounts.get(acc_id, {}).get('name', acc_id)}")
    while True:
        acc = accounts.get(acc_id)
        if not acc or not acc.get("watching") or acc["driver"] is None:
            print(f"[رد آلي] توقفت المراقبة لحساب {acc_id}")
            return
        try:
            with acc["lock"]:
                driver = acc["driver"]
                if not account_logged_in(acc):
                    print(f"[رد آلي] {acc['name']}: الحساب غير مسجل دخول بعد (لا يوجد pane-side)، تخطي هذه الدورة")
                else:
                    chat_items = driver.find_elements(By.CSS_SELECTOR, '#pane-side div[role="listitem"]')[:8]
                    print(f"[رد آلي] {acc['name']}: فحص {len(chat_items)} محادثة")
                    if len(chat_items) == 0 and not diag_dumped:
                        diag_dumped = True
                        try:
                            total_inside = len(driver.find_elements(By.CSS_SELECTOR, "#pane-side *"))
                            probe = {}
                            for sel in ['div[role="listitem"]', 'div[role="row"]', '[data-testid="cell-frame-container"]', "div[tabindex]"]:
                                try:
                                    probe[sel] = len(driver.find_elements(By.CSS_SELECTOR, f"#pane-side {sel}"))
                                except Exception:
                                    probe[sel] = "خطأ"
                            print(f"[رد آلي] {acc['name']}: تشخيص - إجمالي عناصر داخل pane-side: {total_inside}, محددات مرشحة: {probe}")
                        except Exception as diag_e:
                            print(f"[رد آلي] {acc['name']}: تعذر أخذ لقطة تشخيصية: {diag_e}")
                    for item in chat_items:
                        try:
                            chat_name = item.find_element(By.CSS_SELECTOR, "span[title]").get_attribute("title") or "غير معروف"
                        except Exception:
                            continue
                        item.click()
                        time.sleep(1.2)
                        incoming = driver.find_elements(By.CSS_SELECTOR, "div.message-in .selectable-text span")
                        last_text = incoming[-1].text.strip() if incoming else ""
                        if not last_text or last_seen.get(chat_name) == last_text:
                            continue
                        last_seen[chat_name] = last_text
                        print(f"[رد آلي] رسالة جديدة من {chat_name}: {last_text[:50]}")

                        reply_text = None
                        for rule in acc["auto_reply"]["rules"]:
                            if rule["keyword"] and rule["keyword"] in last_text:
                                reply_text = rule["reply"]
                                print(f"[رد آلي] طابقت كلمة مفتاحية: {rule['keyword']}")
                                break
                        if not reply_text and acc["auto_reply"].get("ai_enabled"):
                            reply_text = ai_reply(last_text)

                        if reply_text:
                            box = driver.find_element(By.XPATH, '//footer//div[@contenteditable="true"]')
                            box.send_keys(reply_text)
                            box.send_keys(Keys.ENTER)
                            add_event(acc["owner"], acc["name"], "رد تلقائي", f"{chat_name}: {reply_text[:60]}", kind="info")
                            print(f"[رد آلي] رديت على {chat_name}: {reply_text[:50]}")
                        else:
                            print(f"[رد آلي] ما فيه رد مطابق لرسالة {chat_name} (لا كلمة مفتاحية ولا AI مفعّل/شغّال)")
        except Exception as e:
            print(f"[رد آلي] خطأ بدورة المراقبة: {e}")
        time.sleep(6)


# ---------------------------------------------------------------- صفحات المصادقة

ICON_SEND_ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>'
ICON_LOGIN_ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 3h6v6"/><path d="M10 14L21 3"/><path d="M12 11v7H5V5h7"/></svg>'
ICON_PERSON_ADD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>'
ICON_LOCK_SM = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>'
ICON_BACK = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5L8 12l7 7"/><path d="M8 12h12"/></svg>'
ICON_CHECK = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>'
ICON_SEND = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12l16-8-6 16-3-6-7-2Z"/><path d="M11 14l5-5"/></svg>'
ICON_USERS = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.5-3.1 2.4-5 5.5-5s5 1.9 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15 15c2.6.2 4.3 1.5 5 4"/></svg>'
ICON_TREND = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 20V11"/><path d="M10 20V6"/><path d="M15 20v-9"/><path d="M20 20V3"/></svg>'
ICON_BOLT = '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M25 3L9 27h12l-2 18 20-27H27z"/></svg>'
ICON_CHART = '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M8 39V26"/><path d="M20 39V16"/><path d="M32 39V21"/><path d="M44 39V9"/><path d="M6 44h38"/><path d="M7 18l10-7 10 4 13-9"/></svg>'
ICON_TARGET = '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="24" cy="24" r="17"/><circle cx="24" cy="24" r="8"/><path d="M24 24l14-14"/><path d="M30 10h8v8"/></svg>'
ICON_WHATSAPP = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3.5 20l1.2-4.2A8.5 8.5 0 1 1 20.5 11.5Z"/><path d="M8.5 8.2c.3-.4.6-.4.9-.2l1.1 1.7c.2.3.1.6-.1.9l-.6.6c.7 1.4 1.8 2.4 3.2 3.1l.6-.7c.2-.2.6-.3.9-.1l1.7 1c.3.2.3.6.1.9-.4.7-1 1.1-1.8 1-3.7-.5-7.2-3.8-7.9-7.6-.1-.2.1-.5.2-.6l1.7-.9Z"/></svg>'


def logo_svg(size=32):
    return f"""<svg width="{size}" height="{size}" viewBox="0 0 100 100" fill="none" aria-label="Wasel">
      <path d="M50 9C27 9 9 26 9 47.5c0 8 2.6 15.2 7.2 21.2L10 88l19-5.8c6.2 3.5 13.3 5.5 21 5.5 23 0 41-17 41-40.2S73 9 50 9Z" stroke="#fff" stroke-width="6"/>
      <path d="M35 25c-3 0-5 2-5 5 0 16 12 28 28 33 4 1 7-2 8-5l1-4-10-5-4 4c-5-2-9-6-11-11l4-4-5-10c-1-2-3-3-6-3Z" fill="#fff"/>
    </svg>"""


def render_auth_page(title, action, switch_html, error):
    error_html = f'<p class="status-msg error">{error}</p>' if error else ""
    is_login = action == "/login/email"
    if is_login:
        email_step_title = "تسجيل الدخول بالبريد"
        email_fields = f"""
        <div class="input-group">
          <label>البريد الإلكتروني</label>
          <input type="email" name="email" placeholder="example@domain.com" dir="ltr" required>
        </div>
        <div class="input-group">
          <label>كلمة المرور</label>
          <input type="password" name="password" placeholder="••••••••" required>
        </div>
        <div class="form-row-between">
          <label class="remember-check"><input type="checkbox" name="remember" value="1"> تذكرني</label>
          <a href="#" class="forgot-link" onclick="showForgot(event)">نسيت كلمة المرور؟</a>
        </div>
        <button class="btn-primary" type="submit">تسجيل الدخول {ICON_LOGIN_ARROW}</button>"""
        email_secondary = '<a href="/signup/email" class="btn-secondary">إنشاء حساب جديد</a>'
    else:
        email_step_title = "إنشاء حساب"
        email_fields = f"""
        <div class="input-group">
          <label>الاسم الكامل</label>
          <input type="text" name="name" placeholder="أحمد محمد" required>
        </div>
        <div class="input-group">
          <label>البريد الإلكتروني</label>
          <input type="email" name="email" placeholder="example@domain.com" dir="ltr" required>
        </div>
        <div class="input-group">
          <label>كلمة المرور</label>
          <input type="password" name="password" placeholder="•••••••• (6 أحرف على الأقل)" required>
        </div>
        <button class="btn-primary" type="submit">إنشاء حساب {ICON_PERSON_ADD}</button>"""
        email_secondary = '<a href="/login/email" class="btn-outline">عودة لتسجيل الدخول</a>'
    return f"""
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#020b11">
<title>واصل - {title}</title>
{FONT_LINKS}
<style>
* {{ box-sizing:border-box; margin:0; padding:0; -webkit-tap-highlight-color:transparent; }}
html, body {{ width:100%; height:100%; background:#02090f; overflow:hidden; position:fixed; inset:0; }}
body {{ font-family:{FONT_STACK}; color:#fff; }}
button, a {{ font:inherit; border:0; cursor:pointer; background:none; }}
a {{ text-decoration:none; color:inherit; }}
input, select {{ font:inherit; border:0; outline:none; background:none; color:#fff; }}
select {{ appearance:none; -webkit-appearance:none; -moz-appearance:none; cursor:pointer; }}
select option {{ background:#0a1a20; color:#fff; }}

.page {{ width:100%; max-width:430px; height:100vh; height:100dvh; margin:auto; position:relative; overflow:hidden; display:flex; flex-direction:column;
  background: radial-gradient(circle at 50% 25%, rgba(0,255,120,.09), transparent 30%),
              radial-gradient(circle at 52% 60%, rgba(0,255,120,.035), transparent 25%),
              linear-gradient(180deg, #020a10, #030d13 68%, #07141c);
  animation:fadeIn .3s ease; }}
@keyframes fadeIn {{ from {{ opacity:0; }} to {{ opacity:1; }} }}
{PAGE_TRANSITION_CSS}
.page::before {{ content:""; position:absolute; inset:0; pointer-events:none; opacity:.7;
  background: radial-gradient(circle at 70% 7%, #00bd68 0 2px, transparent 3px),
              radial-gradient(circle at 86% 12%, #00c96c 0 3px, transparent 4px),
              radial-gradient(circle at 38% 6%, #00a85d 0 3px, transparent 4px),
              radial-gradient(circle at 13% 17%, #00d878 0 2px, transparent 3px),
              radial-gradient(circle at 75% 23%, #00b964 0 2px, transparent 3px),
              radial-gradient(circle at 29% 45%, #00db72 0 3px, transparent 4px),
              radial-gradient(circle at 78% 50%, #00b960 0 3px, transparent 4px); }}
.bubble {{ position:absolute; border:1px solid rgba(0,255,133,.05); background:rgba(0,255,133,.015); border-radius:18px; opacity:.5; pointer-events:none; }}
.bubble::before, .bubble::after {{ content:""; position:absolute; width:20px; height:3px; right:8px; background:rgba(0,255,133,.05); border-radius:6px; }}
.bubble::before {{ top:10px; }}
.bubble::after {{ top:17px; width:14px; }}
.bubble.one {{ width:60px; height:44px; top:130px; right:-10px; }}
.bubble.two {{ width:52px; height:38px; top:330px; left:-10px; }}
.bubble.three {{ width:48px; height:34px; top:500px; right:-12px; }}

.back {{ position:absolute; top:14px; right:16px; z-index:5; width:44px; height:44px; border-radius:50%; background:rgba(17,31,39,.6);
  border:1px solid #1c3039; display:flex; align-items:center; justify-content:center; color:#fff; box-shadow:inset 0 1px rgba(255,255,255,.03); }}
.back svg {{ width:20px; height:20px; }}

.main-content {{ flex:1; display:flex; flex-direction:column; justify-content:center; padding:50px 22px 6px; position:relative; z-index:2; min-height:0; }}
.header {{ text-align:center; flex-shrink:0; }}
.logo {{ width:80px; height:80px; margin:0 auto 10px; border-radius:24px; background:linear-gradient(145deg,#24d276,#0aa95c); box-shadow:0 12px 35px rgba(0,230,115,.14); display:flex; align-items:center; justify-content:center; }}
.logo svg {{ width:54px; height:54px; }}
.title {{ font-family:{FONT_STACK}; font-size:26px; line-height:1.2; font-weight:800;
  background:linear-gradient(135deg,#24d276,#0aa95c); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }}
.subtitle {{ margin-top:3px; color:#aeb8bc; font-size:12.5px; line-height:1.6; font-weight:400; }}

.login-card {{ position:relative; z-index:3; margin:22px 25px 0; border:1px solid #1c2d35; border-radius:16px;
  background:linear-gradient(145deg, rgba(9,23,30,.88), rgba(4,14,20,.92)); padding:20px 20px 18px; text-align:center; flex-shrink:0;
  box-shadow:0 14px 40px rgba(0,0,0,.2), inset 0 1px rgba(255,255,255,.02); }}

.input-group {{ position:relative; margin-top:12px; text-align:right; }}
.input-group:first-of-type {{ margin-top:4px; }}
.input-group label {{ display:block; font-size:11.5px; color:#aeb7ba; font-weight:500; margin-bottom:4px; }}
.input-group input {{ width:100%; padding:12px 14px; border-radius:12px; border:1.5px solid #1c2d35; background:rgba(255,255,255,.04); color:#fff; font-size:14px; font-weight:400; }}
.input-group input:focus {{ border-color:#0dd56d; background:rgba(255,255,255,.07); }}
.input-group input::placeholder {{ color:#6a7a80; }}

.btn-primary {{ width:100%; height:48px; margin-top:14px; border-radius:14px; background:linear-gradient(180deg,#1bc96c,#0eb85c); color:#fff; font-size:15px; font-weight:600;
  display:flex; align-items:center; justify-content:center; gap:8px; }}
.btn-primary svg {{ width:18px; height:18px; }}
.btn-secondary, .btn-outline {{ width:100%; height:44px; margin-top:8px; border-radius:14px; border:1.5px solid #1c2d35; background:rgba(255,255,255,.03); color:#aeb7ba; font-size:13.5px; font-weight:500;
  display:flex; align-items:center; justify-content:center; gap:8px; }}

.form-row-between {{ display:flex; align-items:center; justify-content:space-between; margin-top:12px; font-size:11.5px; }}
.remember-check {{ display:flex; align-items:center; gap:5px; color:#aeb7ba; }}
.remember-check input {{ width:auto; accent-color:#0dd56d; }}
.forgot-link {{ color:#10d86b; font-weight:500; }}

.status-msg {{ font-size:11.5px; color:#aeb7ba; margin-top:10px; font-weight:400; min-height:16px; }}
.status-msg.error {{ color:#f87171; }}
.status-msg.success {{ color:#34d399; }}

.bottom {{ position:relative; z-index:3; margin-top:auto; padding:14px 20px calc(10px + env(safe-area-inset-bottom)); text-align:center; flex-shrink:0; }}
p.switch {{ text-align:center; font-size:12.5px; color:#9fa9ad; font-weight:400; }}
p.switch a {{ color:#10d86b; text-decoration:none; font-weight:500; }}
.terms {{ margin-top:6px; color:#9fa9ad; font-size:10px; line-height:1.7; font-weight:300; }}
.terms-link {{ color:#10d86b; font-weight:400; }}
.home {{ width:90px; height:4px; background:#fff; border-radius:8px; margin:10px auto 0; opacity:.10; flex-shrink:0; }}

::-webkit-scrollbar {{ display:none; }}
* {{ scrollbar-width:none; }}

@media (max-height:700px) {{
  .logo {{ width:64px; height:64px; border-radius:18px; margin-bottom:6px; }}
  .logo svg {{ width:42px; height:42px; }}
  .title {{ font-size:22px; }}
  .subtitle {{ font-size:11px; }}
  .login-card {{ margin:16px 20px 0; padding:14px 16px 14px; border-radius:14px; }}
  .input-group {{ margin-top:8px; }}
  .input-group input {{ padding:10px 12px; font-size:13px; border-radius:10px; }}
  .input-group label {{ font-size:10.5px; }}
  .btn-primary {{ height:42px; font-size:13.5px; margin-top:10px; border-radius:12px; }}
  .btn-secondary, .btn-outline {{ height:38px; font-size:12px; margin-top:6px; border-radius:12px; }}
  .terms {{ font-size:9px; margin-top:4px; }}
  .home {{ width:70px; height:3px; margin:8px auto 0; }}
  .back {{ width:34px; height:34px; top:10px; right:12px; }}
  .back svg {{ width:16px; height:16px; }}
  .main-content {{ padding:40px 16px 4px; }}
  .status-msg {{ font-size:10.5px; margin-top:6px; min-height:14px; }}
}}
@media (max-height:580px) {{
  .logo {{ width:50px; height:50px; border-radius:14px; margin-bottom:4px; }}
  .logo svg {{ width:34px; height:34px; }}
  .title {{ font-size:18px; }}
  .subtitle {{ font-size:10px; }}
  .login-card {{ margin:10px 14px 0; padding:8px 12px 10px; border-radius:12px; }}
  .input-group {{ margin-top:4px; }}
  .input-group input {{ padding:8px 10px; font-size:11px; border-radius:8px; }}
  .input-group label {{ font-size:9px; margin-bottom:2px; }}
  .btn-primary {{ height:34px; font-size:11px; margin-top:6px; border-radius:10px; }}
  .btn-secondary, .btn-outline {{ height:30px; font-size:10px; margin-top:4px; border-radius:10px; }}
  .terms {{ font-size:8px; margin-top:2px; line-height:1.5; }}
  .home {{ width:50px; height:2px; margin:5px auto 0; }}
  .back {{ width:28px; height:28px; top:6px; right:8px; }}
  .back svg {{ width:14px; height:14px; }}
  .main-content {{ padding:30px 12px 2px; }}
  .status-msg {{ font-size:9px; margin-top:4px; min-height:12px; }}
}}
@media (max-width:360px) {{
  .login-card {{ margin-left:14px; margin-right:14px; }}
  .main-content {{ padding:40px 14px 4px; }}
}}
@media (min-width:431px) {{ .page {{ border-left:1px solid rgba(255,255,255,.03); border-right:1px solid rgba(255,255,255,.03); }} }}
</style>
</head>
<body>

<div class="page">
  <div class="bubble one"></div>
  <div class="bubble two"></div>
  <div class="bubble three"></div>

  <a href="/welcome" class="back" aria-label="رجوع">{ICON_BACK}</a>

  <div class="main-content">
    <header class="header">
      <div class="logo">{logo_svg(54)}</div>
      <h1 class="title">{title}</h1>
      <p class="subtitle">سجل دخولك للوصول إلى حسابك وإدارة حملاتك</p>
    </header>

    <section class="login-card">
      <h2 class="title" style="font-size:17px; margin-bottom:6px;">{email_step_title}</h2>
      {error_html}
      <form method="post" action="{action}">{email_fields}
      </form>
      {email_secondary}
      <div class="status-msg" id="emailStatus"></div>
    </section>
  </div>

  <footer class="bottom">
    {switch_html}
    <div class="terms">بالتسجيل، فإنك توافق على <span class="terms-link">الشروط والأحكام</span> و<span class="terms-link">سياسة الخصوصية</span></div>
    <div class="home"></div>
  </footer>
</div>

<script>
{PAGE_TRANSITION_JS}

function setStatus(id, msg, isError) {{
  const el = document.getElementById(id);
  el.textContent = msg || '';
  el.className = 'status-msg' + (msg ? (isError ? ' error' : ' success') : '');
}}

function showForgot(e) {{
  e.preventDefault();
  setStatus('emailStatus', 'لإعادة تعيين كلمة المرور، تواصل مع إدارة المنصة.', false);
}}
</script>
</body>
</html>
"""


FONT_LINKS = """<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">"""

FONT_STACK = "'IBM Plex Sans Arabic','Cairo','Tajawal',system-ui,sans-serif"

PAGE_TRANSITION_CSS = """body.leaving { animation: pageFadeOut .2s ease forwards; }
@keyframes pageFadeOut { to { opacity:0; transform:scale(.98); } }"""

PAGE_TRANSITION_JS = """(function() {
  function leaveAndGo(action) {
    if (document.body.classList.contains('leaving')) return;
    document.body.classList.add('leaving');
    setTimeout(action, 200);
  }
  window.leaveAndGo = leaveAndGo;
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a[href^="/"]');
    if (!a || a.target === '_blank' || e.metaKey || e.ctrlKey || e.button !== 0) return;
    var href = a.getAttribute('href');
    if (!href || href === '#') return;
    e.preventDefault();
    leaveAndGo(function() { window.location.href = href; });
  });
  document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      leaveAndGo(function() { form.submit(); });
    });
  });
})();"""


def render_welcome_page():
    return f"""
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#02090e">
<title>واصل — Wasel Business</title>
{FONT_LINKS}
<style>
  :root {{
    --bg: #F5F7FA; --card: #FFFFFF; --card-soft: #EEF2F5;
    --ink: #1A2E35; --muted: #4A6A78; --faint: #8AA0B0;
    --gold: #058693; --gold-strong: #046C76; --green-ink: #ffffff;
    --border: rgba(5,134,147,.08); --border-2: rgba(5,134,147,.16);
  }}
  * {{ box-sizing:border-box; margin:0; padding:0; -webkit-tap-highlight-color:transparent; user-select:none; -webkit-user-select:none; }}
  html, body {{ width:100%; height:100%; background:var(--bg); font-family:{FONT_STACK}; overflow:hidden; position:fixed; inset:0; }}
  body {{ color:var(--ink); }}
  img, svg, .card, .feature {{ -webkit-touch-callout:none; pointer-events:none; }}
  .app {{ position:relative; width:100%; max-width:430px; height:100vh; height:100dvh; margin:auto; overflow:hidden;
    background: radial-gradient(circle at 50% 25%, oklch(0.78 0.17 152 / 7%), transparent 30%),
                radial-gradient(circle at 18% 60%, oklch(0.78 0.17 152 / 4%), transparent 20%),
                var(--bg);
    display:flex; flex-direction:column; animation:fadeIn .3s ease; }}
  @keyframes fadeIn {{ from {{ opacity:0; }} to {{ opacity:1; }} }}
  .app::before {{ content:""; position:absolute; inset:0; pointer-events:none; opacity:.5;
    background-image: radial-gradient(circle at 12% 25%, oklch(0.78 0.17 152 / 35%) 0 3px, transparent 4px),
                       radial-gradient(circle at 88% 40%, oklch(0.78 0.17 152 / 30%) 0 3px, transparent 4px),
                       radial-gradient(circle at 23% 10%, oklch(0.78 0.17 152 / 35%) 0 4px, transparent 5px),
                       radial-gradient(circle at 84% 20%, oklch(0.78 0.17 152 / 18%) 0 2px, transparent 3px); }}
  .bubble {{ position:absolute; border:1px solid oklch(0.78 0.17 152 / 6%); background:oklch(0.78 0.17 152 / 2%); border-radius:22px; opacity:.6; pointer-events:none; }}
  .bubble::before, .bubble::after {{ content:""; position:absolute; width:30px; height:5px; right:12px; background:oklch(0.78 0.17 152 / 6%); border-radius:10px; }}
  .bubble::before {{ top:14px; }}
  .bubble::after {{ top:25px; width:20px; }}
  .bubble.one {{ width:90px; height:68px; top:160px; right:-15px; }}
  .bubble.two {{ width:80px; height:60px; top:350px; left:-15px; }}
  .bubble.three {{ width:75px; height:55px; top:540px; right:-20px; }}
  .main-content {{ flex:1; display:flex; flex-direction:column; justify-content:center; padding:10px 20px; position:relative; z-index:2; min-height:0; }}
  .hero {{ text-align:center; pointer-events:none; flex-shrink:0; }}
  .logo {{ width:120px; height:120px; margin:0 auto 10px; border-radius:32px; background:linear-gradient(145deg, var(--gold) 0%, var(--gold-strong) 100%);
    box-shadow:0 10px 35px oklch(0.78 0.17 152 / 20%), inset 0 1px 0 oklch(1 0 0 / 18%); display:flex; align-items:center; justify-content:center; }}
  .logo svg {{ width:78px; height:78px; filter:drop-shadow(0 2px 1px rgba(0,0,0,.1)); }}
  .brand {{ font-family:{FONT_STACK}; font-size:34px; font-weight:800; line-height:1.1; letter-spacing:-1px;
    background:linear-gradient(135deg, var(--gold), var(--gold-strong)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }}
  .en {{ margin-top:4px; color:var(--gold); font-size:14px; letter-spacing:2.5px; font-weight:700; direction:ltr; font-family:{FONT_STACK}; }}
  .description {{ margin:10px auto 0; max-width:300px; color:var(--muted); font-size:15px; line-height:1.7; font-weight:500; }}
  .carousel {{ position:relative; height:195px; margin-top:4px; flex-shrink:0; }}
  .slide {{ position:absolute; inset:0; display:flex; flex-direction:column; justify-content:center; opacity:0; pointer-events:none; transition:opacity .35s ease; }}
  .slide.active {{ opacity:1; }}
  .visual {{ height:195px; position:relative; pointer-events:none; flex-shrink:0; }}
  .phone {{ position:absolute; width:145px; height:190px; left:50%; top:4px; transform:translateX(-50%); border:2.5px solid var(--card-soft); border-radius:26px;
    background:linear-gradient(145deg, oklch(0.78 0.17 152 / 15%), oklch(0.1 0.012 245 / 95%) 55%), oklch(0.1 0.012 245);
    box-shadow:0 0 0 1px oklch(1 0 0 / 2.5%), 0 15px 40px rgba(0,0,0,.5), inset 0 0 30px oklch(0.78 0.17 152 / 3%); }}
  .phone::before {{ content:""; position:absolute; width:58px; height:13px; top:3px; left:50%; transform:translateX(-50%); background:oklch(0.11 0.012 245); border-radius:0 0 10px 10px; }}
  .phone-screen {{ position:absolute; inset:17px 7px 7px; border-radius:20px; overflow:hidden;
    background: radial-gradient(circle at 50% 55%, oklch(0.78 0.17 152 / 10%), transparent 35%), linear-gradient(180deg, oklch(0.1 0.013 245), var(--bg)); }}
  .card {{ position:absolute; z-index:3; width:165px; min-height:65px; border:1px solid oklch(0.78 0.17 152 / 14%);
    background:linear-gradient(135deg, rgba(255,255,255,.97), rgba(255,255,255,.9)); box-shadow:0 10px 25px rgba(5,134,147,.12), inset 0 1px 0 rgba(255,255,255,.6);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-radius:14px; padding:10px 12px;
    display:flex; align-items:center; gap:8px; text-align:right; pointer-events:none; }}
  .card-icon {{ flex:0 0 35px; width:35px; height:35px; border-radius:11px; background:oklch(0.78 0.17 152 / 8%); color:var(--gold); display:flex; align-items:center; justify-content:center; }}
  .card-content {{ flex:1; }}
  .card-title {{ color:var(--ink); font-size:10px; margin-bottom:2px; }}
  .card-number {{ direction:ltr; text-align:right; color:var(--gold); font-size:15px; font-weight:800; font-family:{FONT_STACK}; }}
  .progress {{ height:4px; border-radius:8px; background:var(--card-soft); margin-top:5px; overflow:hidden; }}
  .progress span {{ display:block; height:100%; border-radius:8px; background:var(--gold); box-shadow:0 0 7px oklch(0.78 0.17 152 / 30%); }}
  .card.target {{ right:2px; top:12px; }}
  .card.target .progress span {{ width:78%; }}
  .card.audience {{ left:2px; top:58px; }}
  .card.audience .progress span {{ width:64%; }}
  .card.engagement {{ left:50%; transform:translateX(-50%); bottom:8px; width:175px; }}
  .card.engagement .progress span {{ width:67%; }}
  .features {{ display:grid; grid-template-columns:repeat(3,1fr); gap:6px; padding:0 2px; margin-top:4px; pointer-events:none; flex-shrink:0; }}
  .feature {{ text-align:center; pointer-events:none; }}
  .feature-icon {{ width:54px; height:54px; margin:0 auto 6px; border-radius:50%; border:1px solid oklch(0.78 0.17 152 / 10%);
    background:radial-gradient(circle, oklch(0.78 0.17 152 / 10%), oklch(0.78 0.17 152 / 3%)); display:flex; align-items:center; justify-content:center; color:var(--gold); }}
  .feature h3 {{ font-size:13px; line-height:1.3; font-weight:700; font-family:{FONT_STACK}; }}
  .feature p {{ color:var(--faint); font-size:10px; line-height:1.5; margin-top:1px; }}
  .howto {{ width:100%; display:flex; flex-direction:column; gap:9px; padding:0 8px; }}
  .howto-step {{ display:flex; align-items:center; gap:10px; text-align:right; }}
  .howto-num {{ flex:0 0 28px; width:28px; height:28px; border-radius:50%; background:oklch(0.78 0.17 152 / 12%); border:1px solid oklch(0.78 0.17 152 / 18%);
    color:var(--gold); display:flex; align-items:center; justify-content:center; font-family:{FONT_STACK}; font-weight:700; font-size:13px; }}
  .howto-text h3 {{ font-size:12.5px; line-height:1.3; font-weight:700; font-family:{FONT_STACK}; }}
  .howto-text p {{ color:var(--faint); font-size:10px; line-height:1.4; margin-top:1px; }}
  .dots {{ display:flex; justify-content:center; gap:8px; direction:ltr; margin:12px 0 10px; flex-shrink:0; }}
  .dot {{ width:10px; height:10px; border-radius:50%; background:var(--card-soft); transition:.3s; cursor:pointer; }}
  .dot.active {{ background:var(--gold); box-shadow:0 0 10px oklch(0.78 0.17 152 / 25%); width:26px; border-radius:6px; }}
  {PAGE_TRANSITION_CSS}
  .actions {{ position:relative; z-index:4; padding:0 2px 6px; flex-shrink:0; }}
  .btn {{ width:100%; height:60px; border-radius:18px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-decoration:none; font-family:{FONT_STACK}; }}
  .primary {{ background:linear-gradient(180deg, var(--gold), var(--gold-strong)); box-shadow:0 8px 22px oklch(0.78 0.17 152 / 12%); color:var(--green-ink); }}
  .primary strong {{ font-size:19px; font-weight:800; font-family:{FONT_STACK}; }}
  .primary small {{ font-size:12px; color:rgba(255,255,255,.85); margin-top:2px; }}
  .whatsapp {{ height:50px; margin-top:10px; border:2px solid var(--border-2); background:var(--card); color:var(--gold); font-size:16px; font-weight:700; flex-direction:row; gap:8px; }}
  .whatsapp svg {{ width:22px; height:22px; flex-shrink:0; }}
  .login {{ margin-top:12px; text-align:center; color:var(--muted); font-size:14px; }}
  .login a {{ color:var(--gold); font-weight:700; margin-right:4px; text-decoration:none; }}
  .home-indicator {{ width:100px; height:4px; background:var(--ink); border-radius:20px; margin:8px auto 4px; opacity:.15; flex-shrink:0; }}
  @media (max-height:700px) {{
    .logo {{ width:85px; height:85px; border-radius:24px; }}
    .logo svg {{ width:56px; height:56px; }}
    .brand {{ font-size:26px; }}
    .en {{ font-size:11px; }}
    .description {{ font-size:12px; max-width:260px; margin-top:6px; }}
    .carousel {{ height:155px; }}
    .visual {{ height:155px; }}
    .phone {{ width:115px; height:152px; }}
    .card {{ width:135px; min-height:52px; padding:7px 9px; }}
    .card-icon {{ width:28px; height:28px; }}
    .card-title {{ font-size:9px; }}
    .card-number {{ font-size:12px; }}
    .card.target {{ top:8px; }}
    .card.audience {{ top:46px; }}
    .card.engagement {{ width:145px; bottom:4px; }}
    .feature-icon {{ width:42px; height:42px; }}
    .feature h3 {{ font-size:11px; }}
    .feature p {{ font-size:9px; }}
    .btn {{ height:50px; }}
    .primary strong {{ font-size:16px; }}
    .primary small {{ font-size:10px; }}
    .whatsapp {{ height:42px; font-size:13px; margin-top:6px; }}
    .whatsapp svg {{ width:18px; height:18px; }}
    .dots {{ margin:8px 0 6px; }}
    .dot {{ width:8px; height:8px; }}
    .dot.active {{ width:18px; }}
    .login {{ font-size:12px; margin-top:8px; }}
    .home-indicator {{ width:80px; height:3px; margin:4px auto 2px; }}
    .main-content {{ padding:6px 14px; }}
  }}
  @media (max-height:600px) {{
    .logo {{ width:68px; height:68px; border-radius:20px; }}
    .logo svg {{ width:44px; height:44px; }}
    .brand {{ font-size:22px; }}
    .description {{ font-size:11px; margin-top:4px; }}
    .carousel {{ height:125px; }}
    .visual {{ height:125px; }}
    .phone {{ width:95px; height:125px; border-radius:20px; }}
    .card {{ width:115px; min-height:42px; padding:5px 7px; border-radius:10px; }}
    .card-icon {{ width:24px; height:24px; border-radius:8px; }}
    .card-title {{ font-size:8px; }}
    .card-number {{ font-size:11px; }}
    .card.target {{ top:4px; }}
    .card.audience {{ top:36px; }}
    .card.engagement {{ width:125px; bottom:2px; }}
    .feature-icon {{ width:34px; height:34px; }}
    .feature h3 {{ font-size:10px; }}
    .feature p {{ font-size:8px; }}
    .btn {{ height:40px; border-radius:14px; }}
    .primary strong {{ font-size:14px; }}
    .primary small {{ font-size:9px; }}
    .whatsapp {{ height:36px; font-size:11px; margin-top:4px; border-radius:14px; }}
    .whatsapp svg {{ width:16px; height:16px; }}
    .dots {{ margin:4px 0; gap:5px; }}
    .dot {{ width:6px; height:6px; }}
    .dot.active {{ width:14px; }}
    .login {{ font-size:10px; margin-top:4px; }}
    .home-indicator {{ width:60px; height:3px; margin:2px auto; }}
    .main-content {{ padding:4px 10px; }}
  }}
  @media (min-width:431px) {{ .app {{ border-left:1px solid var(--border); border-right:1px solid var(--border); }} }}
</style>
</head>
<body>
  <div class="app">
    <div class="bubble one"></div>
    <div class="bubble two"></div>
    <div class="bubble three"></div>

    <div class="main-content">
      <section class="hero">
        <div class="logo">{logo_svg(78)}</div>
        <h1 class="brand">واصل</h1>
        <div class="en">WASEL BUSINESS</div>
        <p class="description">منصة احترافية لإدارة حملاتك<br>التسويقية عبر واتساب بسهولة</p>
      </section>

      <div class="carousel" id="welcomeCarousel">
        <div class="slide active">
          <section class="visual">
            <div class="phone"><div class="phone-screen"></div></div>

            <div class="card target">
              <div class="card-icon">{ICON_SEND}</div>
              <div class="card-content">
                <div class="card-title">رسائل مرسلة</div>
                <div class="card-number">12,680</div>
                <div class="progress"><span></span></div>
              </div>
            </div>

            <div class="card audience">
              <div class="card-icon">{ICON_USERS}</div>
              <div class="card-content">
                <div class="card-title">جمهورك المستهدف</div>
                <div class="card-number">2,450</div>
                <div class="progress"><span></span></div>
              </div>
            </div>

            <div class="card engagement">
              <div class="card-icon">{ICON_TREND}</div>
              <div class="card-content">
                <div class="card-title">معدل التفاعل</div>
                <div class="card-number">68%</div>
                <div class="progress"><span></span></div>
              </div>
            </div>
          </section>
        </div>

        <div class="slide">
          <section class="features">
            <div class="feature">
              <div class="feature-icon">{ICON_BOLT}</div>
              <h3>أتمتة كاملة</h3>
              <p>وفر الوقت والجهد</p>
            </div>
            <div class="feature">
              <div class="feature-icon">{ICON_CHART}</div>
              <h3>تحليلات دقيقة</h3>
              <p>تقارير مفصلة</p>
            </div>
            <div class="feature">
              <div class="feature-icon">{ICON_TARGET}</div>
              <h3>أفضل نتائج</h3>
              <p>زيادة المبيعات</p>
            </div>
          </section>
        </div>

        <div class="slide">
          <section class="howto">
            <div class="howto-step">
              <div class="howto-num">١</div>
              <div class="howto-text"><h3>اربط حساب واتساب</h3><p>سجّل دخولك برقمك خلال ثوانٍ</p></div>
            </div>
            <div class="howto-step">
              <div class="howto-num">٢</div>
              <div class="howto-text"><h3>استورد جهات الاتصال</h3><p>من ملف إكسل أو الصق الأرقام مباشرة</p></div>
            </div>
            <div class="howto-step">
              <div class="howto-num">٣</div>
              <div class="howto-text"><h3>أرسل حملتك</h3><p>بضغطة واحدة لكل جمهورك</p></div>
            </div>
          </section>
        </div>
      </div>

      <div class="dots">
        <span class="dot active"></span><span class="dot"></span><span class="dot"></span>
      </div>

      <section class="actions">
        <a href="/signup" class="btn primary">
          <strong>ابدأ الآن</strong>
          <small>إنشاء حساب جديد</small>
        </a>
        <a href="/login" class="btn whatsapp">
          {ICON_WHATSAPP}
          <span>تسجيل الدخول عبر واتساب</span>
        </a>
        <div class="login">لديك حساب بالفعل؟ <a href="/login">تسجيل الدخول</a></div>
        <div class="home-indicator"></div>
      </section>
    </div>
  </div>

<script>
{PAGE_TRANSITION_JS}

(function() {{
  var slides = document.querySelectorAll('.slide');
  var dots = document.querySelectorAll('.dot');
  var idx = 0;
  var timer;
  function show(i) {{
    idx = (i + slides.length) % slides.length;
    slides.forEach(function(s, n) {{ s.classList.toggle('active', n === idx); }});
    dots.forEach(function(d, n) {{ d.classList.toggle('active', n === idx); }});
  }}
  function restart() {{
    clearInterval(timer);
    timer = setInterval(function() {{ show(idx + 1); }}, 4000);
  }}
  dots.forEach(function(d, n) {{
    d.addEventListener('click', function() {{ show(n); restart(); }});
  }});
  var carousel = document.getElementById('welcomeCarousel');
  var touchX = null;
  carousel.addEventListener('touchstart', function(e) {{ touchX = e.touches[0].clientX; }}, {{passive:true}});
  carousel.addEventListener('touchend', function(e) {{
    if (touchX === null) return;
    var dx = e.changedTouches[0].clientX - touchX;
    touchX = null;
    if (Math.abs(dx) < 30) return;
    show(idx + (dx < 0 ? 1 : -1));
    restart();
  }}, {{passive:true}});
  restart();
}})();
</script>
</body>
</html>
"""


def render_phone_login_page():
    return f"""
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#02090f">
<title>واصل - تسجيل الدخول</title>
{FONT_LINKS}
<style>
:root {{
  --bg: #F5F7FA; --card: #FFFFFF; --card-soft: #EEF2F5;
  --ink: #1A2E35; --muted: #4A6A78; --faint: #8AA0B0;
  --gold: #058693; --gold-strong: #046C76; --green-ink: #ffffff;
  --border: rgba(5,134,147,.08); --border-2: rgba(5,134,147,.16);
}}
* {{ box-sizing:border-box; margin:0; padding:0; -webkit-tap-highlight-color:transparent; }}
html, body {{ width:100%; height:100%; background:var(--bg); overflow:hidden; position:fixed; inset:0; }}
body {{ font-family:{FONT_STACK}; color:var(--ink); }}
button, a {{ font:inherit; border:0; cursor:pointer; background:none; }}
a {{ text-decoration:none; color:inherit; }}
input, select {{ font:inherit; border:0; outline:none; background:none; color:var(--ink); }}
select {{ appearance:none; -webkit-appearance:none; -moz-appearance:none; cursor:pointer; }}
select option {{ background:var(--card-soft); color:var(--ink); }}

.page {{ width:100%; max-width:430px; height:100vh; height:100dvh; margin:auto; position:relative; overflow:hidden; display:flex; flex-direction:column;
  background: radial-gradient(circle at 50% 25%, oklch(0.78 0.17 152 / 8%), transparent 30%),
              radial-gradient(circle at 52% 60%, oklch(0.78 0.17 152 / 3%), transparent 25%),
              var(--bg);
  animation:fadeIn .3s ease; }}
@keyframes fadeIn {{ from {{ opacity:0; }} to {{ opacity:1; }} }}
{PAGE_TRANSITION_CSS}
.page::before {{ content:""; position:absolute; inset:0; pointer-events:none; opacity:.5;
  background: radial-gradient(circle at 70% 7%, oklch(0.78 0.17 152 / 45%) 0 2px, transparent 3px),
              radial-gradient(circle at 86% 12%, oklch(0.78 0.17 152 / 40%) 0 3px, transparent 4px),
              radial-gradient(circle at 38% 6%, oklch(0.78 0.17 152 / 35%) 0 3px, transparent 4px),
              radial-gradient(circle at 13% 17%, oklch(0.78 0.17 152 / 45%) 0 2px, transparent 3px),
              radial-gradient(circle at 75% 23%, oklch(0.78 0.17 152 / 40%) 0 2px, transparent 3px),
              radial-gradient(circle at 29% 45%, oklch(0.78 0.17 152 / 45%) 0 3px, transparent 4px),
              radial-gradient(circle at 78% 50%, oklch(0.78 0.17 152 / 40%) 0 3px, transparent 4px); }}
.bubble {{ position:absolute; border:1px solid oklch(0.78 0.17 152 / 5%); background:oklch(0.78 0.17 152 / 1.5%); border-radius:18px; opacity:.5; pointer-events:none; }}
.bubble::before, .bubble::after {{ content:""; position:absolute; width:20px; height:3px; right:8px; background:oklch(0.78 0.17 152 / 5%); border-radius:6px; }}
.bubble::before {{ top:10px; }}
.bubble::after {{ top:17px; width:14px; }}
.bubble.one {{ width:60px; height:44px; top:130px; right:-10px; }}
.bubble.two {{ width:52px; height:38px; top:330px; left:-10px; }}
.bubble.three {{ width:48px; height:34px; top:500px; right:-12px; }}

.back {{ position:absolute; top:14px; right:16px; z-index:5; width:44px; height:44px; border-radius:50%; background:var(--card);
  border:1px solid var(--border-2); display:flex; align-items:center; justify-content:center; color:var(--ink); box-shadow:0 2px 10px rgba(5,134,147,.1); }}
.back svg {{ width:20px; height:20px; }}

.main-content {{ flex:1; display:flex; flex-direction:column; justify-content:center; padding:50px 22px 6px; position:relative; z-index:2; min-height:0; }}
.header {{ text-align:center; flex-shrink:0; }}
.logo {{ width:80px; height:80px; margin:0 auto 10px; border-radius:24px; background:linear-gradient(145deg, var(--gold), var(--gold-strong)); box-shadow:0 12px 35px oklch(0.78 0.17 152 / 14%); display:flex; align-items:center; justify-content:center; }}
.logo svg {{ width:54px; height:54px; }}
.title {{ font-family:{FONT_STACK}; font-size:26px; line-height:1.2; font-weight:800;
  background:linear-gradient(135deg, var(--gold), var(--gold-strong)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }}
.subtitle {{ margin-top:3px; color:var(--muted); font-size:12.5px; line-height:1.6; font-weight:400; }}

.login-card {{ position:relative; z-index:3; margin:22px 25px 0; border:1px solid var(--border); border-radius:16px;
  background:var(--card); padding:20px 20px 18px; text-align:center; flex-shrink:0;
  box-shadow:0 14px 40px rgba(5,134,147,.1); min-height:320px; }}

.orbit {{ width:110px; height:110px; margin:0 auto 4px; position:relative; display:flex; align-items:center; justify-content:center; }}
.orbit::before, .orbit::after {{ content:""; position:absolute; border:2px solid oklch(0.78 0.17 152 / 45%); border-radius:50%; transform:rotate(-20deg); }}
.orbit::before {{ width:86px; height:86px; }}
.orbit::after {{ width:106px; height:106px; border-color:oklch(0.78 0.17 152 / 20%); }}
.orbit-dot {{ position:absolute; width:5px; height:5px; background:var(--gold); border-radius:50%; top:10px; right:16px; box-shadow:0 0 10px oklch(0.78 0.17 152 / 60%); }}
.phone {{ width:54px; height:78px; border:3px solid var(--card-soft); border-radius:10px; background:linear-gradient(150deg, oklch(0.4 0.1 152), oklch(0.13 0.02 152) 75%); position:relative; box-shadow:0 0 20px oklch(0.78 0.17 152 / 12%); display:flex; align-items:center; justify-content:center; color:var(--gold); }}
.phone::before {{ content:""; position:absolute; top:3px; left:50%; transform:translateX(-50%); width:22px; height:4px; background:oklch(0.15 0.015 245); border-radius:0 0 4px 4px; }}
.phone svg {{ width:26px; height:26px; }}
.check {{ position:absolute; right:18px; bottom:8px; width:32px; height:32px; border-radius:50%; background:var(--card); border:2px solid var(--card-soft); color:var(--gold);
  display:flex; align-items:center; justify-content:center; box-shadow:0 0 14px oklch(0.78 0.17 152 / 10%); }}
.check svg {{ width:17px; height:17px; }}

.input-group {{ position:relative; margin-top:12px; text-align:right; }}
.input-group:first-of-type {{ margin-top:4px; }}
.input-group label {{ display:block; font-size:11.5px; color:var(--muted); font-weight:500; margin-bottom:4px; }}
.input-group input, .input-group select {{ width:100%; padding:12px 14px; border-radius:12px; border:1.5px solid var(--border-2); background:var(--card-soft); color:var(--ink); font-size:14px; font-weight:400; }}
.input-group input:focus, .input-group select:focus {{ border-color:var(--gold); background:var(--card); }}
.input-group input::placeholder {{ color:var(--faint); }}

.phone-input-row {{ display:flex; gap:8px; margin-top:4px; direction:ltr; }}
.phone-input-row .country-code {{ flex:0 0 100px; }}
.phone-input-row .country-code select {{ padding:12px 10px; border-radius:12px; border:1.5px solid var(--border-2); background:var(--card-soft); color:var(--ink); font-size:14px; font-weight:500; width:100%; height:100%; direction:ltr; }}
.phone-input-row .country-code select:focus {{ border-color:var(--gold); background:var(--card); }}
.phone-input-row .phone-number {{ flex:1; }}
.phone-input-row .phone-number input {{ width:100%; padding:12px 14px; border-radius:12px; border:1.5px solid var(--border-2); background:var(--card-soft); color:var(--ink); font-size:14px; height:100%; direction:ltr; text-align:left; }}
.phone-input-row .phone-number input:focus {{ border-color:var(--gold); background:var(--card); }}
.phone-input-row .phone-number input::placeholder {{ color:var(--faint); text-align:left; }}

.btn-primary {{ width:100%; height:48px; margin-top:14px; border-radius:14px; background:linear-gradient(180deg, var(--gold), var(--gold-strong)); color:var(--green-ink); font-size:15px; font-weight:600;
  display:flex; align-items:center; justify-content:center; gap:8px; }}
.btn-primary svg {{ width:18px; height:18px; }}
.btn-primary:disabled {{ opacity:.7; }}
.btn-outline {{ width:100%; height:44px; margin-top:8px; border-radius:14px; border:1.5px solid var(--border-2); background:var(--card-soft); color:var(--muted); font-size:13.5px; font-weight:500;
  display:flex; align-items:center; justify-content:center; gap:8px; }}

.step-note {{ font-size:12px; color:var(--muted); line-height:1.7; margin:0 0 14px; }}
.qr-box {{ width:172px; height:172px; margin:0 auto; border-radius:16px; background:#fff; padding:10px; display:flex; align-items:center; justify-content:center; box-shadow:0 10px 30px rgba(5,134,147,.15); border:1px solid var(--border); }}
.qr-box img {{ width:100%; height:100%; object-fit:contain; }}

.status-msg {{ font-size:11.5px; color:var(--muted); margin-top:10px; font-weight:400; min-height:16px; }}
.status-msg.error {{ color:#f87171; }}
.status-msg.success {{ color:#34d399; }}

.secure {{ color:var(--muted); font-size:11px; margin-top:12px; display:flex; align-items:center; justify-content:center; gap:5px; font-weight:400; }}
.secure svg {{ width:16px; height:16px; }}

.bottom {{ position:relative; z-index:3; margin-top:auto; padding:14px 20px calc(10px + env(safe-area-inset-bottom)); text-align:center; flex-shrink:0; }}
.terms {{ color:var(--faint); font-size:10px; line-height:1.7; font-weight:300; }}
.terms-link {{ color:var(--gold); font-weight:400; }}
.home {{ width:90px; height:4px; background:var(--ink); border-radius:8px; margin:10px auto 0; opacity:.10; flex-shrink:0; }}

::-webkit-scrollbar {{ display:none; }}
* {{ scrollbar-width:none; }}

@media (max-height:700px) {{
  .logo {{ width:64px; height:64px; border-radius:18px; margin-bottom:6px; }}
  .logo svg {{ width:42px; height:42px; }}
  .title {{ font-size:22px; }}
  .subtitle {{ font-size:11px; }}
  .login-card {{ margin:16px 20px 0; padding:14px 16px 14px; border-radius:14px; min-height:270px; }}
  .orbit {{ width:90px; height:90px; }}
  .orbit::before {{ width:70px; height:70px; }}
  .orbit::after {{ width:86px; height:86px; }}
  .phone {{ width:44px; height:64px; border-radius:8px; border-width:2.5px; }}
  .qr-box {{ width:140px; height:140px; }}
  .check {{ width:26px; height:26px; right:14px; bottom:4px; border-width:1.5px; }}
  .input-group {{ margin-top:8px; }}
  .input-group input, .input-group select {{ padding:10px 12px; font-size:13px; border-radius:10px; }}
  .phone-input-row .country-code {{ flex:0 0 80px; }}
  .phone-input-row .country-code select, .phone-input-row .phone-number input {{ padding:10px; font-size:13px; border-radius:10px; }}
  .btn-primary {{ height:42px; font-size:13.5px; margin-top:10px; border-radius:12px; }}
  .btn-outline {{ height:38px; font-size:12px; margin-top:6px; border-radius:12px; }}
  .back {{ width:34px; height:34px; top:10px; right:12px; }}
  .back svg {{ width:16px; height:16px; }}
  .main-content {{ padding:40px 16px 4px; }}
}}
@media (max-height:580px) {{
  .logo {{ width:50px; height:50px; border-radius:14px; margin-bottom:4px; }}
  .logo svg {{ width:34px; height:34px; }}
  .title {{ font-size:18px; }}
  .login-card {{ margin:10px 14px 0; padding:10px 12px 10px; border-radius:12px; min-height:220px; }}
  .orbit {{ width:70px; height:70px; }}
  .orbit::before {{ width:54px; height:54px; }}
  .orbit::after {{ width:66px; height:66px; }}
  .phone {{ width:34px; height:48px; border-radius:6px; border-width:2px; }}
  .qr-box {{ width:110px; height:110px; }}
  .check {{ width:20px; height:20px; right:10px; bottom:2px; border-width:1.5px; }}
  .input-group {{ margin-top:4px; }}
  .btn-primary {{ height:34px; font-size:11px; margin-top:6px; border-radius:10px; }}
  .btn-outline {{ height:30px; font-size:10px; margin-top:4px; border-radius:10px; }}
  .back {{ width:28px; height:28px; top:6px; right:8px; }}
  .main-content {{ padding:30px 12px 2px; }}
}}
@media (max-width:360px) {{
  .login-card {{ margin-left:14px; margin-right:14px; }}
  .main-content {{ padding:40px 14px 4px; }}
  .phone-input-row .country-code {{ flex:0 0 75px; }}
}}
@media (min-width:431px) {{ .page {{ border-left:1px solid var(--border); border-right:1px solid var(--border); }} }}
</style>
</head>
<body>

<div class="page">
  <div class="bubble one"></div>
  <div class="bubble two"></div>
  <div class="bubble three"></div>

  <a href="/welcome" class="back" aria-label="رجوع">{ICON_BACK}</a>

  <div class="main-content">
    <header class="header">
      <div class="logo">{logo_svg(54)}</div>
      <h1 class="title">تسجيل الدخول</h1>
      <p class="subtitle">أدخل رقم واتساب للمتابعة - بدون بريد إلكتروني أو كلمة مرور</p>
    </header>

    <section class="login-card">
      <div class="orbit">
        <span class="orbit-dot"></span>
        <div class="phone">{ICON_WHATSAPP}</div>
        <div class="check">{ICON_CHECK}</div>
      </div>

      <div id="phoneStep">
        <div class="input-group">
          <label>رقم الهاتف</label>
          <div class="phone-input-row">
            <div class="country-code">
              <select id="countryCode">
                <option value="20">🇪🇬 +20</option>
                <option value="212">🇲🇦 +212</option>
                <option value="213">🇩🇿 +213</option>
                <option value="216">🇹🇳 +216</option>
                <option value="218">🇱🇾 +218</option>
                <option value="90">🇹🇷 +90</option>
                <option value="91">🇮🇳 +91</option>
                <option value="92">🇵🇰 +92</option>
                <option value="93">🇦🇫 +93</option>
                <option value="94">🇱🇰 +94</option>
                <option value="95">🇲🇲 +95</option>
                <option value="960">🇲🇻 +960</option>
                <option value="961">🇱🇧 +961</option>
                <option value="962">🇯🇴 +962</option>
                <option value="963">🇸🇾 +963</option>
                <option value="964" selected>🇮🇶 +964</option>
                <option value="965">🇰🇼 +965</option>
                <option value="966">🇸🇦 +966</option>
                <option value="967">🇾🇪 +967</option>
                <option value="968">🇴🇲 +968</option>
                <option value="970">🇵🇸 +970</option>
                <option value="971">🇦🇪 +971</option>
                <option value="972">🇮🇱 +972</option>
                <option value="973">🇧🇭 +973</option>
                <option value="974">🇶🇦 +974</option>
                <option value="975">🇧🇹 +975</option>
                <option value="976">🇲🇳 +976</option>
                <option value="977">🇳🇵 +977</option>
                <option value="98">🇮🇷 +98</option>
                <option value="262">🇾🇹 +262</option>
                <option value="992">🇹🇯 +992</option>
                <option value="993">🇹🇲 +993</option>
                <option value="994">🇦🇿 +994</option>
                <option value="995">🇬🇪 +995</option>
                <option value="996">🇰🇬 +996</option>
                <option value="998">🇺🇿 +998</option>
                <option value="1">🇺🇸 +1</option>
                <option value="44">🇬🇧 +44</option>
                <option value="49">🇩🇪 +49</option>
                <option value="33">🇫🇷 +33</option>
                <option value="39">🇮🇹 +39</option>
                <option value="34">🇪🇸 +34</option>
                <option value="7">🇷🇺 +7</option>
                <option value="86">🇨🇳 +86</option>
                <option value="81">🇯🇵 +81</option>
                <option value="82">🇰🇷 +82</option>
                <option value="60">🇲🇾 +60</option>
                <option value="62">🇮🇩 +62</option>
                <option value="63">🇵🇭 +63</option>
                <option value="64">🇳🇿 +64</option>
                <option value="61">🇦🇺 +61</option>
              </select>
            </div>
            <div class="phone-number">
              <input type="tel" id="phoneInput" placeholder="7701234567" dir="ltr">
            </div>
          </div>
        </div>
        <button class="btn-primary" id="sendBtn" data-label="متابعة" onclick="sendCode()"><span class="btn-label">متابعة</span> {ICON_SEND_ARROW}</button>
        <div class="secure">آمن وسريع بدون كلمة مرور {ICON_LOCK_SM}</div>
      </div>

      <div id="qrStep" style="display:none">
        <p class="step-note">هذا أول استخدام للموقع. امسح رمز QR من واتساب هاتفك لتفعيل حسابك كمدير المنصة، وسنرسل لك رمز التحقق تلقائياً بعد الاتصال</p>
        <div class="qr-box"><img id="qrImg" alt="QR"></div>
      </div>

      <div id="codeStep" style="display:none">
        <p class="step-note">أرسلنا رمز التحقق عبر واتساب على الرقم المدخل</p>
        <div class="input-group">
          <label>رمز التحقق</label>
          <input type="text" id="codeInput" placeholder="أدخل الرمز المكون من 6 أرقام" inputmode="numeric" dir="ltr">
        </div>
        <button class="btn-primary" id="verifyBtn" data-label="تحقق" onclick="verifyCode()"><span class="btn-label">تحقق</span> {ICON_CHECK}</button>
        <button class="btn-outline" onclick="showStep('phone')">تعديل الرقم</button>
      </div>

      <div id="nameStep" style="display:none">
        <p class="step-note">مرحباً بك أول مرة! شنو اسمك؟</p>
        <div class="input-group">
          <label>الاسم</label>
          <input type="text" id="nameInput" placeholder="اسمك الكامل">
        </div>
        <button class="btn-primary" id="nameBtn" data-label="متابعة" onclick="submitName()"><span class="btn-label">متابعة</span> {ICON_SEND_ARROW}</button>
      </div>

      <div class="status-msg" id="authStatus"></div>
    </section>
  </div>

  <footer class="bottom">
    <div class="terms">بتسجيل الدخول فإنك توافق على <span class="terms-link">الشروط والأحكام</span> و<span class="terms-link">سياسة الخصوصية</span></div>
    <div class="home"></div>
  </footer>
</div>

<script>
{PAGE_TRANSITION_JS}

let waPhone = '';
let bootstrapAccId = null;
let bootstrapPoll = null;

function showStep(step) {{
  document.getElementById('phoneStep').style.display = step === 'phone' ? 'block' : 'none';
  document.getElementById('qrStep').style.display = step === 'qr' ? 'block' : 'none';
  document.getElementById('codeStep').style.display = step === 'code' ? 'block' : 'none';
  document.getElementById('nameStep').style.display = step === 'name' ? 'block' : 'none';
  setStatus('authStatus', '', false);
  if (step !== 'qr') clearInterval(bootstrapPoll);
}}

function setStatus(id, msg, isError) {{
  const el = document.getElementById(id);
  el.textContent = msg || '';
  el.className = 'status-msg' + (msg ? (isError ? ' error' : ' success') : '');
}}

function btnLoading(id, loading) {{
  const btn = document.getElementById(id);
  btn.disabled = loading;
  btn.querySelector('.btn-label').textContent = loading ? '...' : btn.dataset.label;
}}

async function sendCode() {{
  const code = document.getElementById('countryCode').value;
  const phone = document.getElementById('phoneInput').value.replace(/[^0-9]/g, '');
  if (phone.length < 6) {{ setStatus('authStatus', 'أدخل رقم هاتف صحيح (بعد رمز الدولة)', true); return; }}
  waPhone = code + phone;
  btnLoading('sendBtn', true);
  const r = await fetch('/auth/whatsapp/send_code', {{
    method: 'POST', headers: {{'Content-Type': 'application/json'}}, body: JSON.stringify({{phone: waPhone}})
  }}).then(res => res.json());
  btnLoading('sendBtn', false);
  if (!r.ok) {{ setStatus('authStatus', r.error, true); return; }}
  if (r.bootstrap) {{
    bootstrapAccId = r.acc_id;
    showStep('qr');
    startBootstrapPoll();
    return;
  }}
  setStatus('authStatus', 'تم إرسال الرمز، تحقق من واتساب', false);
  showStep('code');
}}

function refreshQr() {{
  document.getElementById('qrImg').src = '/auth/bootstrap/qr/' + bootstrapAccId + '?t=' + Date.now();
}}

function startBootstrapPoll() {{
  refreshQr();
  clearInterval(bootstrapPoll);
  bootstrapPoll = setInterval(async function() {{
    refreshQr();
    const r = await fetch('/auth/bootstrap/status/' + bootstrapAccId).then(res => res.json());
    if (r.connected && r.code_sent) {{
      clearInterval(bootstrapPoll);
      setStatus('authStatus', 'تم الاتصال وإرسال رمز التحقق', false);
      showStep('code');
    }}
  }}, 2500);
}}

async function verifyCode() {{
  const c = document.getElementById('codeInput').value.trim();
  if (!c) {{ setStatus('authStatus', 'أدخل رمز التحقق', true); return; }}
  btnLoading('verifyBtn', true);
  const r = await fetch('/auth/whatsapp/verify', {{
    method: 'POST', headers: {{'Content-Type': 'application/json'}}, body: JSON.stringify({{phone: waPhone, code: c}})
  }}).then(res => res.json());
  btnLoading('verifyBtn', false);
  if (!r.ok) {{ setStatus('authStatus', r.error, true); return; }}
  if (r.is_new) {{ showStep('name'); return; }}
  window.leaveAndGo(function() {{ window.location.href = '/'; }});
}}

async function submitName() {{
  const name = document.getElementById('nameInput').value.trim();
  if (!name) {{ setStatus('authStatus', 'أدخل اسمك', true); return; }}
  btnLoading('nameBtn', true);
  const r = await fetch('/auth/set_name', {{
    method: 'POST', headers: {{'Content-Type': 'application/json'}}, body: JSON.stringify({{name: name}})
  }}).then(res => res.json());
  btnLoading('nameBtn', false);
  if (!r.ok) {{ setStatus('authStatus', r.error, true); return; }}
  window.leaveAndGo(function() {{ window.location.href = '/'; }});
}}
</script>
</body>
</html>
"""


EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


@app.route("/welcome")
def welcome_page():
    if session.get("user_id"):
        return redirect("/")
    return render_welcome_page()


@app.route("/login", methods=["GET"])
def login_page():
    return render_phone_login_page()


@app.route("/signup")
def signup_page():
    # ما عاد فيه خطوة "إنشاء حساب" منفصلة - رقم واتساب الجديد ينشئ حسابه تلقائياً
    # بمجرد التحقق من الرمز، فنفس صفحة الدخول تكفي لكل الحالتين
    return redirect("/login")


# صفحة الدخول القديمة بالبريد وكلمة المرور: أبقيناها شغالة على رابط غير معلن (لا يوجد أي
# زر بالواجهة يوصلها) كمنفذ احتياطي لأي حساب بريد/كلمة مرور حقيقي أُنشئ بجولات سابقة قبل
# التحويل لتسجيل الدخول برقم واتساب فقط، حتى لا يُقفل أحد عن حسابه الحقيقي على الموقع الفعلي
@app.route("/login/email", methods=["GET", "POST"])
def legacy_email_login_page():
    if request.method == "GET":
        return render_auth_page("تسجيل الدخول", "/login/email", '<p class="switch">ما عندك حساب؟ <a href="/signup/email">أنشئ حساب</a></p>', "")
    email = request.form.get("email", "").strip().lower()
    password = request.form.get("password", "")
    user = db_get_user_by_email(email)
    if not user or not user["password_hash"] or not check_password_hash(user["password_hash"], password):
        return render_auth_page("تسجيل الدخول", "/login/email", '<p class="switch">ما عندك حساب؟ <a href="/signup/email">أنشئ حساب</a></p>', "البريد الإلكتروني أو كلمة المرور غير صحيحة")
    session["user_id"] = user["id"]
    session["email"] = user["email"]
    session["name"] = user["name"]
    session["is_admin"] = bool(user["is_admin"])
    session.permanent = bool(request.form.get("remember"))
    return redirect("/")


@app.route("/signup/email", methods=["GET", "POST"])
def legacy_email_signup_page():
    if request.method == "GET":
        return render_auth_page("إنشاء حساب", "/signup/email", '<p class="switch">عندك حساب؟ <a href="/login/email">سجّل الدخول</a></p>', "")
    name = request.form.get("name", "").strip()
    email = request.form.get("email", "").strip().lower()
    password = request.form.get("password", "")
    switch = '<p class="switch">عندك حساب؟ <a href="/login/email">سجّل الدخول</a></p>'
    if not name or not email or not password:
        return render_auth_page("إنشاء حساب", "/signup/email", switch, "عبّي كل الحقول")
    if not EMAIL_RE.match(email):
        return render_auth_page("إنشاء حساب", "/signup/email", switch, "أدخل بريد إلكتروني صحيح")
    if db_get_user_by_email(email):
        return render_auth_page("إنشاء حساب", "/signup/email", switch, "هذا البريد مسجّل من قبل")
    is_admin = db_count_users() == 0
    user_id = db_create_user(email, generate_password_hash(password), is_admin, name)
    add_event(user_id, None, "بدأت الفترة التجريبية", f"لديك {TRIAL_DAYS} أيام مجانية لتجربة المنصة", kind="info")
    session["user_id"] = user_id
    session["email"] = email
    session["name"] = name
    session["is_admin"] = is_admin
    return redirect("/")


@app.route("/logout", methods=["POST"])
def logout_page():
    session.clear()
    return redirect("/login")


# ---------------------------------------------------------------- تسجيل الدخول برقم واتساب

bootstrap_lock = threading.Lock()


def find_pending_bootstrap_account():
    for acc in accounts.values():
        if acc.get("bootstrap") and acc["owner"] is None:
            return acc
    return None


@app.route("/auth/whatsapp/send_code", methods=["POST"])
def send_whatsapp_code():
    data = request.json or {}
    phone = re.sub(r"\D", "", data.get("phone") or "")
    if len(phone) < 8:
        return jsonify(ok=False, error="أدخل رقم واتساب صحيح مع مفتاح الدولة"), 400
    sender = find_otp_sender_account()
    if not sender:
        # ما فيه حساب واتساب مفعّل لإرسال رموز التحقق بعد. لو هذا أول استخدام للموقع (ولا
        # يوجد أي مستخدم بعد) نبدأ إعداد أولي: نفتح جلسة واتساب جديدة، والمستخدم يمسح رمز QR
        # منها بنفسه، وبمجرد اتصالها نرسل له رمز التحقق من نفس الحساب - فيصير هو المدير
        # وحساب واتساب هذا يصير حسابه الأول تلقائياً، بدون أي حاجة لحساب سابق يرسل له الرمز
        if db_count_users() == 0:
            with bootstrap_lock:
                acc = find_pending_bootstrap_account()
                if not acc:
                    acc_id = uuid.uuid4().hex[:8]
                    accounts[acc_id] = new_account_entry(acc_id, None, "حساب الإعداد الأولي")
                    accounts[acc_id]["bootstrap"] = True
                    accounts[acc_id]["bootstrap_phone"] = phone
                    accounts[acc_id]["bootstrap_code_sent"] = False
                    threading.Thread(target=start_account_driver, args=(acc_id,), daemon=True).start()
                    acc = accounts[acc_id]
                elif acc["bootstrap_phone"] != phone:
                    # المستخدم غيّر الرقم قبل إكمال الإعداد - نعيد تصفير علم "أُرسل الرمز"
                    # حتى يرسل status الرمز للرقم الجديد أول ما تتصل نفس جلسة واتساب المفتوحة
                    acc["bootstrap_phone"] = phone
                    acc["bootstrap_code_sent"] = False
            return jsonify(ok=True, bootstrap=True, acc_id=acc["id"])
        return jsonify(ok=False, error="تسجيل الدخول عبر واتساب غير مفعّل حالياً، تواصل مع إدارة المنصة"), 400
    code = str(secrets.randbelow(900000) + 100000)
    with otp_lock:
        otp_codes[phone] = {"code": code, "expires": time.time() + 600, "verified": False}
    try:
        with sender["lock"]:
            send_to(sender["driver"], phone, f"رمز التحقق الخاص بك في واصل: {code}\nصالح لمدة 10 دقائق.")
    except Exception:
        with otp_lock:
            otp_codes.pop(phone, None)
        return jsonify(ok=False, error="تعذر إرسال الرمز، تأكد إن الرقم يستخدم واتساب وحاول مرة أخرى"), 500
    return jsonify(ok=True)


@app.route("/auth/bootstrap/qr/<acc_id>")
def bootstrap_qr(acc_id):
    if db_count_users() > 0:
        return "", 204
    acc = accounts.get(acc_id)
    if not acc or not acc.get("bootstrap") or acc["driver"] is None:
        return "", 204
    try:
        canvas = acc["driver"].find_element(By.TAG_NAME, "canvas")
        return Response(canvas.screenshot_as_png, mimetype="image/png")
    except Exception:
        return "", 204


@app.route("/auth/bootstrap/status/<acc_id>")
def bootstrap_status(acc_id):
    if db_count_users() > 0:
        return jsonify(connected=False)
    acc = accounts.get(acc_id)
    if not acc or not acc.get("bootstrap") or not account_logged_in(acc):
        return jsonify(connected=False)
    if not acc.get("bootstrap_code_sent"):
        phone = acc.get("bootstrap_phone", "")
        code = str(secrets.randbelow(900000) + 100000)
        with otp_lock:
            otp_codes[phone] = {"code": code, "expires": time.time() + 600, "verified": False}
        try:
            with acc["lock"]:
                send_to(acc["driver"], phone, f"رمز التحقق الخاص بك في واصل: {code}\nصالح لمدة 10 دقائق.")
            acc["bootstrap_code_sent"] = True
        except Exception:
            return jsonify(connected=True, code_sent=False)
    return jsonify(connected=True, code_sent=True)


@app.route("/auth/whatsapp/verify", methods=["POST"])
def verify_whatsapp_code():
    data = request.json or {}
    phone = re.sub(r"\D", "", data.get("phone") or "")
    code = (data.get("code") or "").strip()
    with otp_lock:
        entry = otp_codes.get(phone)
        if not entry or entry["expires"] < time.time() or entry["code"] != code:
            return jsonify(ok=False, error="الرمز غير صحيح أو منتهي الصلاحية"), 400
        otp_codes.pop(phone, None)
    # الدخول برقم واتساب فقط بدون كلمة مرور: رقم مسجّل من قبل يسجّل دخوله مباشرة، ورقم جديد
    # ينشئ حسابه تلقائياً بمجرد التحقق من الرمز - لا خطوة كلمة مرور بعدها بأي الحالتين
    user = db_get_user_by_phone(phone)
    is_new = not user
    if not user:
        is_admin = db_count_users() == 0
        user_id = db_create_user_by_phone(phone, None, is_admin)
        user = db_get_user_by_id(user_id)
        add_event(user_id, None, "بدأت الفترة التجريبية", f"لديك {TRIAL_DAYS} أيام مجانية لتجربة المنصة", kind="info")
        if is_admin:
            boot_acc = find_pending_bootstrap_account()
            if boot_acc and boot_acc.get("bootstrap_phone") == phone:
                boot_acc["owner"] = user_id
                boot_acc["bootstrap"] = False
                boot_acc["otp_sender"] = True
                boot_acc["name"] = "حسابي الأول"
    session["user_id"] = user["id"]
    session["email"] = user["email"] or user["phone"]
    session["name"] = user["name"]
    session["is_admin"] = bool(user["is_admin"])
    return jsonify(ok=True, is_new=is_new)


@app.route("/auth/set_name", methods=["POST"])
@login_required
def set_user_name():
    name = (request.json or {}).get("name", "").strip()
    if not name:
        return jsonify(ok=False, error="أدخل اسمك"), 400
    db_set_user_name(session["user_id"], name)
    session["name"] = name
    return jsonify(ok=True)


# ---------------------------------------------------------------- الصفحة الرئيسية وملفات PWA

@app.route("/")
def home():
    if not session.get("user_id"):
        return redirect("/welcome")
    page = PAGE.replace("__IS_ADMIN__", "true" if session.get("is_admin") else "false")
    page = page.replace("__USERNAME__", session.get("name") or session.get("email", ""))
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
    now = time.monotonic()
    with trial_check_lock:
        should_check = now - trial_check_cache.get(uid, 0) >= TRIAL_CHECK_INTERVAL
        if should_check:
            trial_check_cache[uid] = now
    if should_check:
        user = db_get_user_by_id(uid)
        if user:
            check_trial_ended_event(user)
    with events_lock:
        return jsonify([e for e in events if e["id"] > since and e["owner"] == uid])


@app.route("/events/<int:event_id>/read", methods=["POST"])
@login_required
def mark_event_read(event_id):
    uid = session["user_id"]
    with events_lock:
        for e in events:
            if e["id"] == event_id and e["owner"] == uid:
                e["read"] = True
                return jsonify(ok=True)
    return jsonify(ok=False, error="غير موجود"), 404


@app.route("/events/read_all", methods=["POST"])
@login_required
def mark_all_events_read():
    uid = session["user_id"]
    with events_lock:
        for e in events:
            if e["owner"] == uid:
                e["read"] = True
    return jsonify(ok=True)


@app.route("/events/<int:event_id>", methods=["DELETE"])
@login_required
def delete_event(event_id):
    uid = session["user_id"]
    with events_lock:
        for i, e in enumerate(events):
            if e["id"] == event_id and e["owner"] == uid:
                del events[i]
                return jsonify(ok=True)
    return jsonify(ok=False, error="غير موجود"), 404


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


# ---------------------------------------------------------------- الاشتراك والدفع (تحقق يدوي)

@app.route("/subscription")
@login_required
def get_subscription():
    user = db_get_user_by_id(session["user_id"])
    payments = db_list_payments_for_user(session["user_id"])
    trial_days_left = 0
    if user and not user["plan_active"] and user["trial_ends_at"]:
        try:
            trial_days_left = max(0, (datetime.strptime(user["trial_ends_at"], "%Y-%m-%d %H:%M") - datetime.now()).days + 1)
        except ValueError:
            trial_days_left = 0
    pay_text = urllib.parse.quote(f"أرغب بتفعيل اشتراكي في منصة واصل ({PLAN_PRICE_IQD:,} د.ع)")
    return jsonify(
        plan_active=bool(user and user["plan_active"]),
        has_access=user_has_access(user) if user else False,
        trial_days_left=trial_days_left,
        plan_name=PLAN_NAME,
        price_iqd=PLAN_PRICE_IQD,
        wa_pay_link=f"https://wa.me/{WHATSAPP_PAY_NUMBER}?text={pay_text}",
        payments=[dict(p) for p in payments],
    )


@app.route("/subscription/pay", methods=["POST"])
@login_required
def submit_payment():
    data = request.json or {}
    reference = (data.get("reference") or "").strip()
    if not reference:
        return jsonify(ok=False, error="أدخل رقم إثبات الدفع/التحويل"), 400
    db_create_payment_request(session["user_id"], PLAN_NAME, PLAN_PRICE_IQD, reference)
    return jsonify(ok=True)


@app.route("/admin/customers")
@login_required
@admin_required
def admin_customers():
    return jsonify([dict(u) for u in db_list_users()])


@app.route("/admin/customers/<int:user_id>/plan", methods=["POST"])
@login_required
@admin_required
def admin_set_plan(user_id):
    data = request.json or {}
    db_set_plan_active(user_id, bool(data.get("active")))
    return jsonify(ok=True)


@app.route("/admin/payments")
@login_required
@admin_required
def admin_payments():
    return jsonify([dict(p) for p in db_list_pending_payments()])


@app.route("/admin/payments/<int:payment_id>/approve", methods=["POST"])
@login_required
@admin_required
def admin_approve_payment(payment_id):
    return jsonify(ok=db_set_payment_status(payment_id, "approved"))


@app.route("/admin/payments/<int:payment_id>/reject", methods=["POST"])
@login_required
@admin_required
def admin_reject_payment(payment_id):
    return jsonify(ok=db_set_payment_status(payment_id, "rejected"))


# ---------------------------------------------------------------- حسابات واتساب (لكل مستخدم)

@app.route("/dashboard/stats")
@login_required
def dashboard_stats():
    uid = session["user_id"]
    my_accounts = [a for a in accounts.values() if a["owner"] == uid]
    all_history = [h for a in my_accounts for h in a["history"]]
    sent = sum(h["sent"] for h in all_history)
    failed = sum(h["failed"] for h in all_history)
    total_msgs = sent + failed
    return jsonify(
        accounts_total=len(my_accounts),
        accounts_connected=sum(1 for a in my_accounts if account_logged_in(a)),
        messages_sent=sent,
        success_rate=round((sent / total_msgs) * 100) if total_msgs else 0,
        campaigns_total=len(all_history),
        campaigns_success=sum(1 for h in all_history if h["failed"] == 0),
        campaigns_with_failures=sum(1 for h in all_history if h["failed"] > 0),
    )


@app.route("/dashboard/campaigns")
@login_required
def dashboard_campaigns():
    uid = session["user_id"]
    my_accounts = [a for a in accounts.values() if a["owner"] == uid]
    rows = []
    for a in my_accounts:
        for h in a["history"]:
            rows.append({**h, "account_name": a["name"]})
    rows.sort(key=lambda r: r["time"], reverse=True)
    return jsonify(rows[:20])


@app.route("/accounts", methods=["GET"])
@login_required
def list_accounts():
    uid = session["user_id"]
    return jsonify([
        {"id": aid, "name": a["name"], "logged_in": account_logged_in(a), "otp_sender": a.get("otp_sender", False)}
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


@app.route("/accounts/<acc_id>/otp_sender", methods=["POST"])
@login_required
@admin_required
def set_otp_sender(acc_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(ok=False, error="حساب غير موجود"), 404
    data = request.json or {}
    if bool(data.get("enabled")):
        for a in accounts.values():
            a["otp_sender"] = False
        acc["otp_sender"] = True
    else:
        acc["otp_sender"] = False
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
    user = db_get_user_by_id(session["user_id"])
    if not user_has_access(user):
        return jsonify(ok=False, error="انتهت فترتك التجريبية، فعّل الاشتراك من قسم الإعدادات للمتابعة", needs_subscription=True), 402
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
        return jsonify(enabled=False, ai_enabled=False, rules=[])
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
    acc["auto_reply"]["ai_enabled"] = bool(data.get("ai_enabled"))
    enabled = bool(data.get("enabled"))
    with acc["ar_lock"]:
        was_enabled = acc["auto_reply"]["enabled"]
        if enabled and not was_enabled:
            user = db_get_user_by_id(session["user_id"])
            if not user_has_access(user):
                return jsonify(ok=False, error="انتهت فترتك التجريبية، فعّل الاشتراك من قسم الإعدادات للمتابعة", needs_subscription=True), 402
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
<meta name="theme-color" content="#F5F7FA">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root {
    --primary: #058693; --primary-light: #0AA6B5; --primary-dark: #046C76;
    --primary-gradient: linear-gradient(135deg, #058693 0%, #0AA6B5 100%);
    --accent: #D8B07A;
    --bg: #F5F7FA; --bg-card: #FFFFFF; --card-soft: #EEF2F5; --card-3: #E4E9EE;
    --border: rgba(5,134,147,.08); --border-2: rgba(5,134,147,.16);
    --text-primary: #1A2E35; --text-secondary: #4A6A78; --text-muted: #8AA0B0;
    --gold-light: rgba(5,134,147,.08); --gold-border: rgba(5,134,147,.20); --gold-shadow: rgba(5,134,147,.25);
    --green-ink: #ffffff; --red: #EF4444; --blue: #3B82F6; --amber: #D97706;
    --shadow: rgba(5,134,147,.08);
    --shadow-sm: 0 2px 8px rgba(5,134,147,0.04);
    --shadow-md: 0 4px 20px rgba(5,134,147,0.06);
    --shadow-lg: 0 8px 40px rgba(5,134,147,0.08);
    --shadow-xl: 0 12px 56px rgba(5,134,147,0.10);
    --radius-sm: 8px; --radius-md: 14px; --radius-lg: 20px; --radius-full: 9999px;
    --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --header-height: 64px; --nav-height: 72px;
  }
  :root[data-theme="dark"] {
    --primary: #0AA6B5; --primary-light: #0AA6B5; --primary-dark: #058693;
    --primary-gradient: linear-gradient(135deg, #058693 0%, #0AA6B5 100%);
    --bg: #0D1117; --bg-card: #161B22; --card-soft: #1C232C; --card-3: #262E38;
    --border: rgba(255,255,255,.06); --border-2: rgba(255,255,255,.12);
    --text-primary: #F0F6FC; --text-secondary: #8B949E; --text-muted: #6A8098;
    --gold-light: rgba(10,166,181,.12); --gold-border: rgba(10,166,181,.28); --gold-shadow: rgba(10,166,181,.3);
    --green-ink: #ffffff; --red: #EF4444; --blue: #3B82F6; --amber: #D97706;
    --shadow: rgba(0,0,0,.4);
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-xl: 0 12px 56px rgba(0,0,0,0.6);
  }
  html, body { margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text-primary); font-family: 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal', system-ui, sans-serif;
    min-height: 100vh; transition: background var(--transition-base), color var(--transition-base); animation: pageFadeIn .25s ease; }
  @keyframes pageFadeIn { from { opacity: 0; } to { opacity: 1; } }
  h1, h2, h3, .stat-num { font-family: 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal', sans-serif; }
  .icon { display: inline-block; vertical-align: middle; flex-shrink: 0; }

  .header-glass {
    background: rgba(255,255,255,0.92); backdrop-filter: blur(24px) saturate(180%); -webkit-backdrop-filter: blur(24px) saturate(180%);
    padding: 12px 24px; height: var(--header-height);
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 100;
    border-bottom: 1px solid rgba(0,0,0,0.04); box-shadow: 0 2px 20px rgba(0,0,0,0.04);
    transition: var(--transition-base);
  }
  :root[data-theme="dark"] .header-glass { background: #0D1117; border-bottom: 1px solid rgba(255,255,255,0.06); box-shadow: 0 2px 20px rgba(0,0,0,0.5); }
  .header-glass .brand { display: flex; align-items: center; gap: 12px; }
  .header-glass .brand .logo {
    width: 40px; height: 40px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px; font-weight: 900; box-shadow: 0 4px 16px rgba(5,134,147,0.25); background: var(--primary-gradient);
  }
  .header-glass .brand .name { font-size: 18px; font-weight: 900; color: var(--text-primary); letter-spacing: -0.5px; }
  .header-glass .brand .name span { background: var(--primary-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
  .header-glass .actions { display: flex; align-items: center; gap: 6px; }
  .header-glass .actions .icon-btn {
    width: 42px; height: 42px; border-radius: var(--radius-full); border: 1px solid rgba(0,0,0,0.04);
    background: rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: center;
    color: var(--text-secondary); cursor: pointer; font-size: 18px; position: relative; transition: var(--transition-base);
  }
  :root[data-theme="dark"] .header-glass .actions .icon-btn { background: rgba(255,255,255,0.05); color: #8B949E; border-color: rgba(255,255,255,0.06); }
  .header-glass .actions .icon-btn:hover { background: rgba(0,0,0,0.06); color: var(--primary); }
  .header-glass .actions .icon-btn .badge {
    position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
    background: var(--red); border-radius: var(--radius-full); border: 2px solid rgba(255,255,255,0.8);
    box-shadow: 0 0 12px rgba(239,68,68,0.4); animation: pulse-dot 2s ease-in-out infinite;
  }
  @keyframes pulse-dot { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.4); opacity: 0.6; } }

  .notif-item-full {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 12px; margin-bottom: 8px;
    background: var(--bg-card); border: 1px solid rgba(5,134,147,0.04); transition: var(--transition-base);
  }
  .notif-item-full:hover { border-color: rgba(5,134,147,0.12); box-shadow: var(--shadow-sm); }
  .notif-item-full .notif-icon {
    width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0; background: rgba(5,134,147,0.08); color: var(--primary);
  }
  .notif-item-full .notif-icon.success { background: rgba(16,185,129,0.12); color: #059669; }
  .notif-item-full .notif-icon.warning { background: rgba(251,191,36,0.12); color: #D97706; }
  .notif-item-full .notif-content { flex: 1; }
  .notif-item-full .notif-content .notif-title { font-size: 13px; font-weight: 700; color: var(--text-primary); }
  .notif-item-full .notif-content .notif-message { font-size: 11px; color: var(--text-muted); line-height: 1.5; }
  .notif-item-full .notif-time { font-size: 10px; color: var(--text-muted); flex-shrink: 0; background: rgba(5,134,147,0.04); padding: 2px 10px; border-radius: var(--radius-full); }
  .notif-item-full .notif-actions { display: flex; gap: 6px; flex-shrink: 0; }
  .notif-item-full .notif-actions button { width: 32px; height: 32px; border: none; border-radius: 50%; background: transparent; color: var(--text-muted); cursor: pointer; transition: var(--transition-base); display: flex; align-items: center; justify-content: center; font-size: 13px; }
  .notif-item-full .notif-actions .btn-read:hover { background: rgba(5,134,147,0.06); color: var(--primary); }
  .notif-item-full .notif-actions .btn-delete:hover { background: rgba(239,68,68,0.06); color: #EF4444; }

  .body { display: flex; flex: 1; justify-content: center; }
  .content { flex: 1; padding: 16px 20px calc(var(--nav-height) + 20px); display: flex; justify-content: center; max-width: 640px; margin: 0 auto; }
  .content-inner { width: 100%; }
  .content-inner.fade-in { animation: contentFadeIn .22s ease; }
  @keyframes contentFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

  .bottom-nav-minimal {
    position: fixed; bottom: 0; left: 0; right: 0; height: var(--nav-height); min-height: var(--nav-height);
    padding: 6px 8px 12px; background: var(--bg-card); border-top: 2px solid rgba(5,134,147,0.04);
    box-shadow: 0 -4px 30px rgba(0,0,0,0.02);
    display: flex; justify-content: space-around; align-items: center; transition: var(--transition-base); z-index: 200; gap: 2px;
  }
  :root[data-theme="dark"] .bottom-nav-minimal { background: #0D1117; border-top: 1px solid rgba(255,255,255,0.06); }
  .bottom-nav-minimal .nav-item {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
    padding: 4px 2px; min-height: 48px; border-radius: var(--radius-sm); transition: var(--transition-base);
    background: transparent; border: none; cursor: pointer; font-size: 10px; font-weight: 700; color: var(--text-muted);
    position: relative; font-family: inherit;
  }
  :root[data-theme="dark"] .bottom-nav-minimal .nav-item { color: #8B949E; }
  .bottom-nav-minimal .nav-item i { font-size: 20px; transition: var(--transition-base); }
  .bottom-nav-minimal .nav-item.active { color: var(--primary); background: rgba(5,134,147,0.04); }
  :root[data-theme="dark"] .bottom-nav-minimal .nav-item.active { color: #0AA6B5; background: rgba(10,166,181,0.08); }
  .bottom-nav-minimal .nav-item.active i { transform: translateY(-2px); }
  .bottom-nav-minimal .nav-item:active { transform: scale(0.92); }

  @media (max-width: 480px) {
    :root { --header-height: 58px; --nav-height: 66px; }
    .header-glass { padding: 10px 16px; }
    .header-glass .brand .name { font-size: 16px; }
    .header-glass .brand .logo { width: 34px; height: 34px; font-size: 16px; }
    .header-glass .actions .icon-btn { width: 36px; height: 36px; font-size: 16px; }
    .bottom-nav-minimal .nav-item { font-size: 9px; min-height: 42px; }
    .bottom-nav-minimal .nav-item i { font-size: 18px; }
  }
  @media (max-width: 380px) {
    .bottom-nav-minimal .nav-item span { display: none; }
    .bottom-nav-minimal .nav-item i { font-size: 20px; }
  }

  .page-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
  .page-title h2 { font-size: 20px; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
  @media (max-width: 480px) { .page-title h2 { font-size: 18px; } }

  .card {
    background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid rgba(5,134,147,0.04);
    box-shadow: var(--shadow-sm); padding: 20px; margin-bottom: 14px; transition: var(--transition-base);
  }
  .card:hover { box-shadow: var(--shadow-md); border-color: rgba(5,134,147,0.06); }
  .card .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
  .card .card-header h4 { font-size: 16px; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
  .card .card-header h4 i { color: var(--primary); }
  .glossy-card {
    background: linear-gradient(145deg, var(--bg-card), var(--card-soft));
    border: 1.5px solid var(--gold-border); border-radius: 18px;
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

  .text-gold { color: var(--primary); }
  .text-red { color: var(--red); }
  .border-gold { border-color: var(--gold-border); }
  .bg-gold-light { background: var(--gold-light); }
  .text-muted { color: var(--text-secondary); }

  .step-item { display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); }
  .step-item:last-child { border-bottom: 0; }
  .step-num { width: 26px; height: 26px; border-radius: 50%; background: var(--gold-light); border: 1px solid var(--gold-border); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; color: var(--primary); }
  .step-text { font-size: 13px; color: var(--text-primary); font-weight: 500; }
  .step-text small { display: block; font-weight: 400; font-size: 11px; color: var(--text-secondary); margin-top: 2px; }

  .field-label { display: block; margin-top: 16px; margin-bottom: 6px; font-size: 13px; color: var(--text-secondary); font-weight: 700; }
  input, textarea, select {
    width: 100%; box-sizing: border-box; padding: 12px 16px; font-size: 14px; font-family: inherit;
    background: var(--bg); border: 2px solid rgba(5,134,147,0.08); border-radius: var(--radius-sm); color: var(--text-primary);
    transition: var(--transition-base); outline: none;
  }
  input:focus, textarea:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(5,134,147,0.06); }
  textarea { resize: vertical; min-height: 80px; }
  input[type="file"] { padding: 9px 12px; }
  input[type="checkbox"] { width: auto; }

  .btn-gold {
    display: block; width: 100%; padding: 13px; margin-top: 18px; border: none; border-radius: 15px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: var(--green-ink);
    font-weight: 800; font-size: 15px; font-family: inherit; cursor: pointer; transition: .2s ease;
    box-shadow: 0 8px 20px var(--gold-shadow);
  }
  .btn-gold:hover { filter: brightness(1.05); }
  .btn-submit {
    width: 100%; height: 48px; border: none; border-radius: var(--radius-md);
    background: var(--primary-gradient); color: #fff; font-size: 15px; font-weight: 700;
    cursor: pointer; transition: var(--transition-base); font-family: inherit;
    box-shadow: 0 4px 16px rgba(5,134,147,0.25); margin-top: 8px;
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
  }
  .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(5,134,147,0.35); }
  .btn-submit:active { transform: scale(0.97); }
  .btn-outline {
    display: block; width: 100%; padding: 10px; margin-top: 6px; border-radius: 11px;
    background: var(--bg-card); border: 1.5px solid rgba(5,134,147,0.15); color: var(--text-secondary);
    font-weight: 700; font-size: 12px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
  .btn-danger {
    display: block; width: 100%; padding: 10px; margin-top: 10px; border-radius: 12px;
    background: rgba(239,68,68,0.12); border: 1.5px solid rgba(239,68,68,0.4); color: var(--red);
    font-weight: 800; font-size: 12px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-danger:hover { background: rgba(239,68,68,0.22); }
  .btn-logout {
    width: 100%; height: 46px; border: none; border-radius: var(--radius-md);
    background: linear-gradient(135deg,#EF4444,#DC2626); color: #fff; font-size: 14px; font-weight: 700;
    cursor: pointer; transition: var(--transition-base); font-family: inherit;
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    box-shadow: 0 4px 16px rgba(239,68,68,0.25); margin-top: 8px;
  }
  .btn-logout:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(239,68,68,0.35); }
  .btn-small { width: auto; padding: 8px 14px; margin-top: 0; display: inline-block; }

  .confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); backdrop-filter: blur(4px); z-index: 599; display: none; }
  .confirm-overlay.show { display: block; }
  .confirm-sheet { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 480px;
    background: var(--bg-card); border-radius: var(--radius-lg) var(--radius-lg) 0 0; box-shadow: var(--shadow-xl); z-index: 600; display: none;
    padding: 24px 20px calc(24px + env(safe-area-inset-bottom)); }
  .confirm-sheet.show { display: block; animation: sheetUp .35s cubic-bezier(.34,1.56,.64,1); }
  @keyframes sheetUp { from { transform: translate(-50%, 100%); } to { transform: translate(-50%, 0); } }
  .confirm-sheet .sheet-handle { width: 40px; height: 4px; background: rgba(0,0,0,0.12); border-radius: 4px; margin: 0 auto 16px; }
  .confirm-sheet .sheet-title { font-size: 16px; font-weight: 800; color: var(--text-primary); text-align: center; margin-bottom: 6px; }
  .confirm-sheet .sheet-message { font-size: 13px; color: var(--text-muted); text-align: center; margin-bottom: 20px; }
  .confirm-sheet .sheet-actions { display: flex; gap: 10px; }
  .confirm-sheet .sheet-actions button { flex: 1; height: 46px; border: none; border-radius: var(--radius-md); font-size: 14px; font-weight: 700;
    cursor: pointer; transition: var(--transition-base); display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; }
  .confirm-sheet .btn-cancel { background: rgba(5,134,147,0.06); color: var(--text-secondary); }
  .confirm-sheet .btn-cancel:hover { background: rgba(5,134,147,0.1); transform: translateY(-2px); }
  .confirm-sheet .btn-confirm { background: linear-gradient(135deg, #EF4444, #DC2626); color: #fff; box-shadow: 0 4px 16px rgba(239,68,68,0.25); }
  .confirm-sheet .btn-confirm-gold { background: var(--primary-gradient); color: #fff; box-shadow: 0 4px 16px rgba(5,134,147,0.25); }
  .confirm-sheet .btn-confirm-gold:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(5,134,147,0.35); }
  .confirm-sheet .btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(239,68,68,0.35); }

  #qrImg { width: 220px; height: 220px; border-radius: 16px; border: 1px solid var(--border-2); background: #fff; display: block; margin: 0 auto; }

  .stat-row { display: flex; border-radius: 12px; overflow: hidden; margin-top: 10px; }
  .stat-cell { flex: 1; text-align: center; padding: 8px 4px; background: var(--bg-card); border: 1px solid rgba(5,134,147,0.04); }
  .stat-cell + .stat-cell { border-right: none; }
  .stat-num { font-size: 16px; font-weight: 800; }
  .stat-label { font-size: 9px; color: var(--text-muted); margin-top: 1px; }

  .account-name { font-size: 13px; font-weight: 700; flex: 1; }
  .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .dot-on { background: var(--primary); box-shadow: 0 0 6px var(--gold-shadow); }
  .dot-off { background: var(--amber); }
  .empty-state { text-align: center; color: var(--text-secondary); font-size: 13px; margin-top: 60px; }

  .avatar { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 14px; }
  .avatar-0 { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }
  .avatar-1 { background: linear-gradient(135deg, oklch(0.72 0.13 238), oklch(0.55 0.15 238)); }
  .avatar-2 { background: linear-gradient(135deg, oklch(0.72 0.15 300), oklch(0.55 0.16 300)); }
  .avatar-3 { background: linear-gradient(135deg, oklch(0.8 0.14 78), oklch(0.65 0.16 60)); }
  .avatar-4 { background: linear-gradient(135deg, oklch(0.72 0.17 30), oklch(0.6 0.19 21)); }

  .pill { display: inline-flex; align-items: center; padding: 1px 8px; border-radius: 999px; font-size: 9px; font-weight: 800; }
  .pill-green { background: rgba(16,185,129,0.12); color: #059669; }
  .pill-blue { background: rgba(59,130,246,0.12); color: #3B82F6; }
  .pill-red { background: rgba(239,68,68,0.12); color: #DC2626; }
  .pill-amber { background: rgba(251,191,36,0.12); color: #D97706; }
  .pill-gray { background: rgba(107,114,128,0.12); color: #6B7280; }
  .pill-gold { background: rgba(216,176,122,0.12); color: #D8B07A; border: 1px solid rgba(216,176,122,0.2); }

  .history-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 4px; border-bottom: 1px solid rgba(5,134,147,0.04); font-size: 11px; }
  .history-row:last-child { border-bottom: none; }
  .history-row.clickable { cursor: pointer; }
  .history-row .campaign-name { font-weight: 700; color: var(--text-primary); display: block; }
  .history-row .campaign-detail { font-size: 10px; color: var(--text-muted); }
  .history-row .see-detail { color: var(--primary); font-weight: 700; margin-right: 8px; }

  .campaign-detail-card { background: var(--bg-card); border: 1px solid rgba(5,134,147,0.04); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); padding: 20px; margin-bottom: 14px; transition: var(--transition-base); }
  .campaign-detail-card:hover { box-shadow: var(--shadow-md); border-color: rgba(5,134,147,0.06); }
  .campaign-detail-card .detail-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(5,134,147,0.04); font-size: 13px; gap: 10px; }
  .campaign-detail-card .detail-row:last-child { border-bottom: none; }
  .campaign-detail-card .detail-row .label { color: var(--text-muted); font-weight: 500; flex-shrink: 0; }
  .campaign-detail-card .detail-row .value { font-weight: 700; color: var(--text-primary); text-align: left; }

  .rule-row { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
  .rule-row input { margin-top: 0; }
  .rule-remove { flex-shrink: 0; width: 32px; height: 32px; border-radius: 10px; border: 1px solid color-mix(in oklch, var(--red) 35%, transparent); background: transparent; color: var(--red); cursor: pointer; }

  .hero-main {
    background: linear-gradient(145deg, var(--bg-card), var(--bg-card)); border: 1.5px solid rgba(5,134,147,0.15);
    border-radius: 20px; padding: 24px 20px; position: relative; overflow: hidden; text-align: center; margin-bottom: 20px;
    box-shadow: 0 0 40px rgba(5,134,147,0.04), inset 0 1px 0 rgba(5,134,147,0.05);
  }
  .hero-main::before {
    content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
    background: conic-gradient(from 0deg at 50% 50%, transparent 0%, rgba(5,134,147,0.04) 20%, transparent 40%, rgba(5,134,147,0.04) 60%, transparent 80%, rgba(5,134,147,0.04) 100%);
    animation: shimmerRotate 12s linear infinite; pointer-events: none;
  }
  .hero-main::after { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at 30% 20%, rgba(5,134,147,0.03), transparent 60%); pointer-events: none; opacity: 0.5; }
  .hero-main .relative-z { position: relative; z-index: 1; }
  .hero-main .hero-icon-main { width: 64px; height: 64px; margin: 0 auto 10px; border-radius: 50%; background: rgba(5,134,147,0.06); border: 1.5px solid rgba(5,134,147,0.15); display: flex; align-items: center; justify-content: center; font-size: 28px; color: var(--primary); }
  .hero-main h1 { font-size: 22px; font-weight: 900; color: var(--primary); margin-bottom: 4px; }
  .hero-main p { font-size: 12px; color: var(--text-muted); line-height: 1.7; margin: 0 auto; max-width: 400px; }
  .hero-main .hero-badges { display: flex; justify-content: center; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
  .hero-main .hero-badges span { font-size: 11px; font-weight: 700; color: var(--primary); background: rgba(5,134,147,0.06); padding: 4px 14px; border-radius: 20px; border: 1px solid rgba(5,134,147,0.08); }

  .stats-mini-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
  .stat-mini-box { background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid rgba(5,134,147,0.04); box-shadow: var(--shadow-sm); padding: 10px 4px; text-align: center; transition: var(--transition-base); }
  .stat-mini-box:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
  .stat-mini-box .num { font-size: 18px; font-weight: 900; color: var(--text-primary); line-height: 1.2; }
  .stat-mini-box .num.green { color: #059669; }
  .stat-mini-box .num.orange { color: #D97706; }
  .stat-mini-box .num.primary { color: var(--primary); }
  .stat-mini-box .label { font-size: 9px; color: var(--text-muted); margin-top: 2px; font-weight: 500; }

  .quick-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
  .quick-action-btn { padding: 14px 8px; border-radius: var(--radius-md); border: 2px solid rgba(5,134,147,0.08); background: var(--bg-card); text-align: center; cursor: pointer; transition: var(--transition-base); font-weight: 700; font-size: 12px; color: var(--text-primary); font-family: inherit; }
  .quick-action-btn:hover { border-color: var(--primary); background: rgba(5,134,147,0.02); transform: translateY(-2px); box-shadow: var(--shadow-md); }
  .quick-action-btn:active { transform: scale(0.95); }
  .quick-action-btn i { font-size: 24px; display: block; margin-bottom: 4px; color: var(--primary); }

  .account-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid rgba(5,134,147,0.04); border-radius: 12px; margin-bottom: 8px; transition: var(--transition-base); }
  .account-item:hover { box-shadow: var(--shadow-sm); border-color: rgba(5,134,147,0.08); }
  .account-item .info { flex: 1; min-width: 0; }
  .account-item .info .name { font-size: 13px; font-weight: 700; color: var(--text-primary); }
  .account-item .info .status { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

  .profile-card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid rgba(5,134,147,0.04); box-shadow: var(--shadow-sm); padding: 20px; margin-bottom: 14px; display: flex; align-items: center; gap: 16px; transition: var(--transition-base); }
  .profile-card .avatar { width: 64px; height: 64px; font-size: 28px; box-shadow: 0 4px 16px rgba(5,134,147,0.2); }
  .profile-card .info { flex: 1; }
  .profile-card .info .name { font-size: 18px; font-weight: 800; color: var(--text-primary); }
  .profile-card .info .role { font-size: 12px; font-weight: 600; color: var(--primary); background: rgba(5,134,147,0.06); padding: 2px 12px; border-radius: var(--radius-full); display: inline-block; margin-top: 2px; }

  .settings-list { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid rgba(5,134,147,0.04); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 14px; }
  .settings-head { padding: 14px 16px; background: rgba(5,134,147,0.04); border-bottom: 1px solid rgba(5,134,147,0.04); font-size: 13px; font-weight: 700; color: var(--text-secondary); display: flex; align-items: center; gap: 10px; }
  .settings-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid rgba(5,134,147,0.04); cursor: pointer; transition: var(--transition-base); }
  .settings-item:last-child { border-bottom: none; }
  .settings-item:hover { background: rgba(5,134,147,0.02); }
  .settings-item .left { display: flex; align-items: center; gap: 14px; }
  .settings-item .icon-wrap { width: 36px; height: 36px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
  .settings-item .icon-wrap.primary { background: rgba(5,134,147,0.08); color: var(--primary); }
  .settings-item .icon-wrap.blue { background: rgba(59,130,246,0.08); color: #3B82F6; }
  .settings-item .icon-wrap.red { background: rgba(239,68,68,0.08); color: #EF4444; }
  .settings-item .text { font-size: 14px; font-weight: 600; color: var(--text-primary); }
  .settings-item .sub-text { font-size: 11px; color: var(--text-muted); display: block; }
  .settings-item .chevron { color: var(--text-muted); font-size: 14px; transition: var(--transition-base); }
  .settings-item:hover .chevron { transform: translateX(-4px); }

  .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
  .toggle-switch input { opacity: 0; width: 0; height: 0; }
  .toggle-switch .slider { position: absolute; cursor: pointer; inset: 0; background: #D1D5DB; border-radius: var(--radius-full); transition: var(--transition-base); box-shadow: inset 0 2px 4px rgba(0,0,0,0.06); }
  .toggle-switch .slider:before { position: absolute; content: ''; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: var(--radius-full); transition: var(--transition-base); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
  .toggle-switch input:checked + .slider { background: var(--primary); }
  .toggle-switch input:checked + .slider:before { transform: translateX(20px); }

  .subscription-card { background: var(--bg-card); border: 2px solid rgba(5,134,147,0.15); border-radius: 14px; padding: 18px 16px; text-align: center; transition: var(--transition-base); margin-bottom: 14px; }
  .subscription-card:hover { border-color: var(--primary); box-shadow: var(--shadow-md); }
  .subscription-card .plan-name { font-size: 16px; font-weight: 800; color: var(--text-primary); }
  .subscription-card .plan-price { font-size: 28px; font-weight: 900; color: var(--primary); margin: 6px 0; }
</style>
</head>
<body>
<header class="header-glass">
  <div class="brand">
    <div class="logo">و</div>
    <div class="name">منصة <span>واصل</span></div>
  </div>
  <div class="actions">
    <button class="icon-btn" id="themeBtn" onclick="toggleTheme()" title="المظهر"></button>
    <button class="icon-btn" id="bellBtn" onclick="openNotifications()" title="الإشعارات">
      <i class="fas fa-bell"></i>
      <span class="badge" id="bellBadge" style="display:none"></span>
    </button>
  </div>
</header>

<div class="confirm-overlay" id="confirmOverlay" onclick="closeConfirmSheet()"></div>
<div class="confirm-sheet" id="confirmSheet">
  <div class="sheet-handle"></div>
  <div class="sheet-title">🚪 تسجيل الخروج</div>
  <div class="sheet-message">هل أنت متأكد من رغبتك في تسجيل الخروج؟</div>
  <div class="sheet-actions">
    <button class="btn-cancel" onclick="closeConfirmSheet()"><i class="fas fa-times"></i> إلغاء</button>
    <button class="btn-confirm" onclick="confirmLogout()"><i class="fas fa-right-from-bracket"></i> تأكيد</button>
  </div>
</div>

<div class="confirm-overlay" id="addAccountOverlay" onclick="closeAddAccountSheet()"></div>
<div class="confirm-sheet" id="addAccountSheet">
  <div class="sheet-handle"></div>
  <div class="sheet-title">➕ إضافة حساب واتساب</div>
  <div class="sheet-message">أدخل اسماً لتمييز هذا الحساب (اختياري)</div>
  <input type="text" id="newAccountName" placeholder="مثال: حساب المبيعات" style="margin-bottom:16px" onkeydown="if(event.key==='Enter')submitAddAccount()">
  <div class="sheet-actions">
    <button class="btn-cancel" onclick="closeAddAccountSheet()"><i class="fas fa-times"></i> إلغاء</button>
    <button class="btn-confirm-gold" onclick="submitAddAccount()"><i class="fas fa-plus"></i> إضافة</button>
  </div>
</div>

<div class="body">
  <main class="content"><div class="content-inner" id="content"></div></main>
</div>

<nav class="bottom-nav-minimal" id="bottomNav">
  <button class="nav-item" data-s="home" onclick="showSection('home')"></button>
  <button class="nav-item" data-s="accounts" onclick="showSection('accounts')"></button>
  <button class="nav-item" data-s="campaigns" onclick="showSection('campaigns')"></button>
  <button class="nav-item" data-s="analytics" onclick="showSection('analytics')"></button>
  <button class="nav-item" data-s="settings" onclick="showSection('settings')"></button>
</nav>

<script>
const IS_ADMIN = __IS_ADMIN__;

/* ---------- أيقونات خطية (بدون إيموجي) ---------- */
const ICONS = {
  home: 'fas fa-house', accounts: 'fas fa-phone', campaigns: 'fas fa-bullhorn', autoreply: 'fas fa-comment-dots',
  analytics: 'fas fa-chart-column', admin: 'fas fa-shield-halved', settings: 'fas fa-gear',
  bell: 'fas fa-bell', sun: 'fas fa-sun', moon: 'fas fa-moon', plus: 'fas fa-plus', whatsapp: 'fab fa-whatsapp',
  logout: 'fas fa-right-from-bracket', ai: 'fas fa-robot', users: 'fas fa-users', payments: 'fas fa-receipt',
  rocket: 'fas fa-rocket', trophy: 'fas fa-trophy', list: 'fas fa-list', info: 'fas fa-circle-info', clock: 'fas fa-clock',
  check: 'fas fa-check', checkCircle: 'fas fa-circle-check', warn: 'fas fa-triangle-exclamation', trash: 'fas fa-trash',
};
function icon(name, size) {
  size = size || 20;
  return '<i class="icon ' + (ICONS[name] || 'fas fa-circle') + '" style="font-size:' + size + 'px; width:' + size + 'px; display:inline-flex; align-items:center; justify-content:center;"></i>';
}

const CURRENT_USER = "__USERNAME__";
const SECTION_LABELS = { home: 'الرئيسية', accounts: 'حسابي', campaigns: 'الحملات', autoreply: 'الرد الآلي', analytics: 'إحصائيات', admin: 'التحكم', settings: 'الإعدادات' };

function initChrome() {
  document.querySelectorAll('.bottom-nav-minimal .nav-item').forEach(function (el) {
    el.innerHTML = icon(el.dataset.s) + '<span>' + SECTION_LABELS[el.dataset.s] + '</span>';
  });
}
initChrome();

let accounts = [];
let section = 'home';
let activeId = null;
let gen = 0;
let lastSeenEventId = -1;
let campaignHistory = [];
let campaignHistoryAccName = '';

/* ---------- تصميم داكن/نهاري ---------- */
function applyTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  localStorage.setItem('theme', t);
  const btn = document.getElementById('themeBtn');
  if (btn) btn.innerHTML = icon(t === 'dark' ? 'sun' : 'moon');
  const cb = document.getElementById('darkModeToggle');
  if (cb) cb.checked = (t === 'dark');
}
function toggleTheme() {
  applyTheme((document.documentElement.getAttribute('data-theme') || 'light') === 'light' ? 'dark' : 'light');
}
applyTheme(localStorage.getItem('theme') || 'light');

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
      fresh.forEach(e => showLocalNotification(e.title, (e.account ? e.account + ': ' : '') + e.body));
      if (fresh.length) lastSeenEventId = Math.max(...evs.map(e => e.id));
    }
    document.getElementById('bellBadge').style.display = evs.some(e => !e.read) ? 'block' : 'none';
    window._events = evs;
    if (window._notifOpen) renderNotifList(evs);
  } catch (e) {}
  setTimeout(pollEvents, 5000);
}
pollEvents();

async function openNotifications() {
  window._notifOpen = true;
  const c = document.getElementById('content');
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('bell', 18) + ' الإشعارات</h2>' +
      '<div style="display:flex;gap:6px">' +
        '<button class="btn-outline" style="width:auto;padding:6px 12px;font-size:11px;margin:0" onclick="markAllEventsRead()">' + icon('check', 10) + ' قراءة الكل</button>' +
        '<button class="btn-outline" style="width:auto;padding:6px 12px;font-size:11px;margin:0" onclick="closeNotifications()">رجوع</button>' +
      '</div>' +
    '</div>' +
    '<div id="notifList"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>';
  fadeContent();
  const evs = await fetch('/events?since=0').then(r => r.json());
  window._events = evs;
  document.getElementById('bellBadge').style.display = evs.some(e => !e.read) ? 'block' : 'none';
  renderNotifList(evs);
}

function closeNotifications() {
  window._notifOpen = false;
  render();
}

function renderNotifList(evs) {
  const box = document.getElementById('notifList');
  if (!box) return;
  const sorted = evs.slice().reverse();
  box.innerHTML = sorted.length
    ? sorted.map(e => {
        const stateClass = e.kind === 'success' ? 'success' : e.kind === 'warning' ? 'warning' : '';
        const iconName = e.kind === 'success' ? 'checkCircle' : e.kind === 'warning' ? 'warn' : 'bell';
        return '<div class="notif-item-full">' +
          '<div class="notif-icon ' + stateClass + '">' + icon(iconName, 16) + '</div>' +
          '<div class="notif-content"><div class="notif-title">' + e.title + '</div>' +
          '<div class="notif-message">' + (e.account ? e.account + ' — ' : '') + e.body + '</div></div>' +
          '<span class="notif-time">' + e.time + '</span>' +
          '<div class="notif-actions">' +
            (e.read ? '' : '<button class="btn-read" onclick="markEventRead(' + e.id + ')" title="قراءة">' + icon('check', 12) + '</button>') +
            '<button class="btn-delete" onclick="deleteEvent(' + e.id + ')" title="حذف">' + icon('trash', 12) + '</button>' +
          '</div>' +
        '</div>';
      }).join('')
    : '<div class="text-muted text-[11px] text-center" style="padding:40px 0">ما فيه إشعارات بعد</div>';
}

async function markEventRead(id) {
  await fetch('/events/' + id + '/read', { method: 'POST' });
  window._events = (window._events || []).map(e => e.id === id ? Object.assign({}, e, {read: true}) : e);
  renderNotifList(window._events);
  document.getElementById('bellBadge').style.display = window._events.some(e => !e.read) ? 'block' : 'none';
}

async function markAllEventsRead() {
  await fetch('/events/read_all', { method: 'POST' });
  window._events = (window._events || []).map(e => Object.assign({}, e, {read: true}));
  renderNotifList(window._events);
  document.getElementById('bellBadge').style.display = 'none';
}

async function deleteEvent(id) {
  await fetch('/events/' + id, { method: 'DELETE' });
  window._events = (window._events || []).filter(e => e.id !== id);
  renderNotifList(window._events);
  document.getElementById('bellBadge').style.display = window._events.some(e => !e.read) ? 'block' : 'none';
}

function handleSubscriptionError(r, msgElId) {
  if (!r.needs_subscription) return false;
  document.getElementById(msgElId).innerHTML = r.error + ' — <span style="text-decoration:underline;cursor:pointer" onclick="showSection(\\'settings\\')">فتح الاشتراك</span>';
  return true;
}

/* ---------- التنقل بين الأقسام ---------- */
function showSection(s) {
  window._notifOpen = false;
  section = s;
  document.querySelectorAll('.bottom-nav-minimal .nav-item').forEach(n => n.classList.toggle('active', n.dataset.s === s));
  render();
}
showSection('home');

async function loadAccounts(preferId) {
  accounts = await fetch('/accounts').then(r => r.json());
  if (preferId) activeId = preferId;
  if (!accounts.find(a => a.id === activeId)) activeId = accounts.length ? accounts[0].id : null;
}

function fadeContent() {
  const c = document.getElementById('content');
  c.classList.remove('fade-in');
  void c.offsetWidth;
  c.classList.add('fade-in');
}

async function render() {
  gen++;
  const myGen = gen;
  await loadAccounts();
  if (myGen !== gen) return;
  if (section === 'home') renderHome();
  else if (section === 'accounts') renderAccounts();
  else if (section === 'campaigns') renderCampaigns(myGen);
  else if (section === 'analytics') renderAnalytics();
  else if (section === 'autoreply') renderAutoReply();
  else if (section === 'admin') renderAdmin();
  else renderSettings();
  fadeContent();
}

/* ---------- قسم الرئيسية ---------- */
function statMiniHtml(value, label, colorClass) {
  return '<div class="stat-mini-box"><div class="num' + (colorClass ? ' ' + colorClass : '') + '">' + value + '</div><div class="label">' + label + '</div></div>';
}

function renderHome() {
  const c = document.getElementById('content');
  const displayName = CURRENT_USER.split('@')[0] || CURRENT_USER;
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('home', 18) + ' الرئيسية</h2></div>' +
    '<div class="hero-main"><div class="relative-z">' +
      '<div class="hero-icon-main">' + icon('rocket', 28) + '</div>' +
      '<h1>منصة واصل</h1>' +
      '<p>أتمتة حملاتك التسويقية عبر واتساب — أرسل رسائل، صور، وفيديوهات لعملائك بضغطة واحدة.</p>' +
      '<div class="hero-badges"><span>' + icon('campaigns', 11) + ' إرسال جماعي</span><span>' + icon('analytics', 11) + ' تحليلات</span><span>' + icon('autoreply', 11) + ' رد آلي</span></div>' +
    '</div></div>' +
    '<h2 class="text-sm font-extrabold text-gold mb-1">مرحباً، ' + displayName + '</h2>' +
    '<p class="text-[12px] text-muted mb-3">نظرة عامة على نشاطك</p>' +
    '<div class="stats-mini-row" id="homeStats"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '<div class="quick-actions">' +
      '<button class="quick-action-btn" onclick="showSection(\\'campaigns\\')">' + icon('campaigns', 20) + 'حملات</button>' +
      '<button class="quick-action-btn" onclick="showSection(\\'analytics\\')">' + icon('analytics', 20) + 'إحصائيات</button>' +
      '<button class="quick-action-btn" onclick="showSection(\\'accounts\\')">' + icon('accounts', 20) + 'حساباتي</button>' +
      '<button class="quick-action-btn" onclick="showSection(\\'settings\\')">' + icon('settings', 20) + 'اشتراك</button>' +
    '</div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('accounts', 14) + ' حساباتي</h4><button class="btn-outline" style="width:auto;padding:4px 12px;font-size:10px;margin:0" onclick="openAddAccountSheet()">' + icon('plus', 10) + ' إضافة</button></div>' +
      '<div id="homeAccounts"></div>' +
    '</div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('clock', 14) + ' آخر الحملات</h4><span style="font-size:11px;color:var(--text-muted);cursor:pointer" onclick="showSection(\\'campaigns\\')">عرض الكل</span></div>' +
      '<div id="homeHistory"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '</div>';
  fetch('/dashboard/stats').then(r => r.json()).then(d => {
    document.getElementById('homeStats').innerHTML =
      statMiniHtml(d.messages_sent.toLocaleString(), 'رسائل مرسلة', 'primary') +
      statMiniHtml(d.accounts_connected + '/' + d.accounts_total, 'حسابات متصلة', 'green') +
      statMiniHtml(d.campaigns_total, 'إجمالي الحملات', 'orange') +
      statMiniHtml(d.success_rate + '%', 'معدل النجاح', 'primary');
  });
  fetch('/dashboard/campaigns').then(r => r.json()).then(rows => {
    campaignHistory = rows;
    campaignHistoryAccName = '';
    document.getElementById('homeHistory').innerHTML = rows.length
      ? historyRowsHtml(rows.slice(0, 3))
      : '<div class="text-muted text-[11px]">ما فيه حملات سابقة</div>';
  });
  const accBox = document.getElementById('homeAccounts');
  accBox.innerHTML = accounts.length
    ? accounts.slice(0, 3).map(a =>
        '<div class="account-item">' + avatarHtml(a.name, a.id) +
        '<div class="info"><div class="name">' + a.name + '</div><div class="status"><span class="pill ' + (a.logged_in ? 'pill-green' : 'pill-amber') + '">' + (a.logged_in ? 'متصل' : 'غير متصل') + '</span></div></div></div>'
      ).join('') + (accounts.length > 3 ? '<button class="btn-outline" onclick="showSection(\\'accounts\\')">عرض كل الحسابات</button>' : '')
    : '<div class="text-muted text-[11px] text-center" style="padding:20px 0">ما عندك حسابات واتساب بعد. <span style="color:var(--primary);cursor:pointer" onclick="showSection(\\'accounts\\')">أضف حساب</span></div>';
}

/* ---------- قسم حسابي ---------- */
function avatarHtml(name, seed) {
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
  const letter = (name || '؟').trim().charAt(0).toUpperCase();
  return '<div class="avatar avatar-' + (h % 5) + '">' + letter + '</div>';
}

function renderAccounts() {
  const c = document.getElementById('content');
  let html = '<div class="page-title"><h2>' + icon('accounts', 18) + ' حسابي</h2></div>';
  if (!accounts.length) {
    html += '<div class="empty-state">ما عندك حسابات بعد، ضيف واحد للبدء</div>';
  }
  accounts.forEach(acc => {
    html += '<div class="card">' +
      '<div class="flex items-center gap-2.5 mb-2">' +
      avatarHtml(acc.name, acc.id) +
      '<span class="account-name">' + acc.name + '</span>' +
      '<span class="pill ' + (acc.logged_in ? 'pill-green' : 'pill-amber') + '">' + (acc.logged_in ? 'متصل' : 'غير متصل') + '</span>' +
      '</div>';
    if (!acc.logged_in) {
      html += '<div class="text-center"><img id="qrImg-' + acc.id + '" src="/accounts/' + acc.id + '/qr" style="width:170px;height:170px;border-radius:12px;border:1px solid var(--gold-border);background:#fff"><p class="text-[10px] text-muted mt-1">امسح الرمز من واتساب بجوالك</p></div>';
    } else {
      if (IS_ADMIN) {
        html += '<label class="flex items-center gap-2 text-[12px] font-bold mb-2"><input type="checkbox" ' +
          (acc.otp_sender ? 'checked' : '') + ' onchange="setOtpSender(\\'' + acc.id + '\\', this.checked)"> ' +
          'استخدامه لإرسال رموز تسجيل الدخول عبر واتساب</label>';
      }
      html += '<button class="btn-danger" onclick="logoutAccount(\\'' + acc.id + '\\')">تسجيل الخروج</button>';
    }
    html += '</div>';
  });
  html += '<button class="btn-outline" onclick="openAddAccountSheet()">' + icon('plus', 12) + ' إضافة حساب</button>';
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

function openAddAccountSheet() {
  document.getElementById('newAccountName').value = '';
  document.getElementById('addAccountOverlay').classList.add('show');
  document.getElementById('addAccountSheet').classList.add('show');
}
function closeAddAccountSheet() {
  document.getElementById('addAccountOverlay').classList.remove('show');
  document.getElementById('addAccountSheet').classList.remove('show');
}
async function submitAddAccount() {
  const name = document.getElementById('newAccountName').value.trim();
  closeAddAccountSheet();
  const r = await fetch('/accounts', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({name: name}) }).then(res => res.json());
  await loadAccounts(r.id);
  render();
}

async function logoutAccount(id) {
  if (!confirm('تسجيل الخروج من هذا الحساب؟ بيحتاج مسح رمز QR من جديد لاحقاً.')) return;
  await fetch('/accounts/' + id + '/logout', { method: 'POST' });
  activeId = null;
  render();
}

async function setOtpSender(id, enabled) {
  await fetch('/accounts/' + id + '/otp_sender', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({enabled: enabled})
  });
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

  let html = '<div class="page-title"><h2>' + icon('campaigns', 18) + ' الحملات</h2></div>';
  html += '<label class="field-label">الحساب</label>' + accountSelectHtml('switchCampaignAccount');

  if (!acc || !acc.logged_in) {
    html += '<div class="card text-center text-muted text-[12px]">هذا الحساب غير متصل بعد — أكمل تسجيل الدخول من قسم "حسابي"</div>';
    c.innerHTML = html;
    return;
  }

  html +=
    '<div class="card">' +
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
    '<button class="btn-submit" onclick="startCampaign()">' + icon('rocket', 14) + ' بدء الإرسال</button>' +
    '</div>' +
    '<div class="stat-row" id="statRow" style="display:none">' +
    '<div class="stat-cell"><div class="stat-num text-gold" id="statSent">0</div><div class="stat-label">تم الإرسال</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-red" id="statFailed">0</div><div class="stat-label">فشل</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-gold" id="statTotal">0</div><div class="stat-label">الإجمالي</div></div>' +
    '</div>' +
    '<div id="msg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '<h3 style="font-size:12px;font-weight:800;color:var(--primary);margin:18px 0 8px">جميع الحملات</h3>' +
    '<div id="historyBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>';

  c.innerHTML = html;

  if ('contacts' in navigator && 'ContactsManager' in window) {
    document.getElementById('contactPickerBtn').style.display = 'block';
  }

  loadHistory(acc.id);
  refreshCampaignState(acc.id, myGen);
}

function switchCampaignAccount(id) { activeId = id; render(); }

function historyRowsHtml(rows, asCards) {
  return rows.length
    ? rows.map((h, i) => {
        const inner =
          '<div><span class="campaign-name">' + (h.text ? h.text.slice(0, 24) : '(بدون نص)') + '</span>' +
          '<div class="campaign-detail">' + (h.account_name ? h.account_name + ' · ' : '') + h.time + '</div></div>' +
          '<div style="text-align:left">' +
            (asCards ? '<span style="font-weight:700">' + h.total + ' رسالة</span> ' : '') +
            '<span class="pill pill-green">نجح ' + h.sent + '</span> ' +
            '<span class="pill pill-red">فشل ' + h.failed + '</span>' +
            (asCards ? '<span class="see-detail">تفاصيل</span>' : '') +
          '</div>';
        return asCards
          ? '<div class="card" style="padding:14px;cursor:pointer;margin-bottom:10px" onclick="showCampaignDetail(' + i + ')"><div class="history-row">' + inner + '</div></div>'
          : '<div class="history-row clickable" onclick="showCampaignDetail(' + i + ')">' + inner + '</div>';
      }).join('')
    : '<div class="text-muted text-[11px]">ما فيه حملات سابقة</div>';
}

async function loadHistory(accId) {
  const rows = await fetch('/accounts/' + accId + '/campaigns').then(r => r.json());
  campaignHistory = rows;
  const acc = accounts.find(a => a.id === accId);
  campaignHistoryAccName = acc ? acc.name : '';
  const box = document.getElementById('historyBox');
  if (!box) return;
  box.innerHTML = historyRowsHtml(rows, true);
}

function showCampaignDetail(i) {
  const h = campaignHistory[i];
  if (!h) return;
  const c = document.getElementById('content');
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('info', 18) + ' تفاصيل الحملة</h2>' +
      '<button onclick="render()" style="height:36px;padding:0 14px;border:2px solid rgba(5,134,147,0.08);border-radius:var(--radius-sm);background:transparent;color:var(--text-secondary);font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">رجوع</button>' +
    '</div>' +
    '<div class="campaign-detail-card">' +
      '<div class="detail-row"><span class="label">📱 الحساب</span><span class="value">' + (h.account_name || campaignHistoryAccName) + '</span></div>' +
      '<div class="detail-row"><span class="label">📅 تاريخ الإرسال</span><span class="value">' + h.time + '</span></div>' +
      '<div class="detail-row"><span class="label">✅ الناجح</span><span class="value" style="color:#059669">' + h.sent + '</span></div>' +
      '<div class="detail-row"><span class="label">❌ الفاشل</span><span class="value" style="color:#DC2626">' + h.failed + '</span></div>' +
      '<div class="detail-row"><span class="label">📊 الإجمالي</span><span class="value">' + h.total + '</span></div>' +
      '<div class="detail-row"><span class="label">📝 نص الرسالة</span><span class="value" style="color:var(--text-muted);font-weight:400">' + (h.text || '(بدون نص)') + '</span></div>' +
      '<div class="detail-row"><span class="label">📌 الحالة</span><span class="value"><span class="pill pill-green">مكتملة</span></span></div>' +
    '</div>' +
    '<button class="btn-outline" onclick="render()">رجوع إلى قائمة الحملات</button>';
  fadeContent();
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
  if (!r.ok) { if (!handleSubscriptionError(r, 'msg')) document.getElementById('msg').innerText = 'فشل: ' + r.error; return; }
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

/* ---------- قسم الإحصائيات ---------- */
async function renderAnalytics() {
  const c = document.getElementById('content');
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('analytics', 18) + ' الإحصائيات</h2></div>' +
    '<div class="stats-mini-row" id="analyticsStats" style="grid-template-columns:repeat(3,1fr)"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('trophy', 14) + ' أداء الحملات</h4></div>' +
      '<div id="analyticsPerf"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '</div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('list', 14) + ' توزيع الرسائل حسب الحملة</h4></div>' +
      '<div id="analyticsHistory"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '</div>';

  const [stats, rows] = await Promise.all([
    fetch('/dashboard/stats').then(r => r.json()),
    fetch('/dashboard/campaigns').then(r => r.json()),
  ]);

  document.getElementById('analyticsStats').innerHTML =
    statMiniHtml(stats.messages_sent.toLocaleString(), 'إجمالي الرسائل', 'primary') +
    statMiniHtml(stats.success_rate + '%', 'معدل النجاح', 'green') +
    statMiniHtml(stats.accounts_total, 'الحسابات', 'orange');

  const avgRate = stats.campaigns_total ? Math.round((stats.campaigns_success / stats.campaigns_total) * 100) : 0;
  document.getElementById('analyticsPerf').innerHTML =
    '<div class="history-row"><span>إجمالي الحملات</span><span style="font-weight:700">' + stats.campaigns_total + '</span></div>' +
    '<div class="history-row"><span>الحملات الناجحة (بدون أي فشل)</span><span class="pill pill-green">' + stats.campaigns_success + '</span></div>' +
    '<div class="history-row"><span>حملات فيها إخفاقات</span><span class="pill pill-red">' + stats.campaigns_with_failures + '</span></div>' +
    '<div class="history-row"><span>معدل نجاح الحملات</span><span class="pill pill-green">' + avgRate + '%</span></div>';

  campaignHistory = rows;
  campaignHistoryAccName = '';
  document.getElementById('analyticsHistory').innerHTML = historyRowsHtml(rows);
}

/* ---------- قسم الرد الآلي ---------- */
let autoReplyRules = [];

function renderAutoReply() {
  const c = document.getElementById('content');
  if (!accounts.length) { c.innerHTML = '<div class="empty-state">ضيف حساباً من قسم "حسابي" أول</div>'; return; }
  const acc = accounts.find(a => a.id === activeId);

  let html = '<div class="page-title"><h2>' + icon('autoreply', 18) + ' الرد الآلي</h2></div>';
  html += '<label class="field-label">الحساب</label>' + accountSelectHtml('switchAutoReplyAccount');

  if (!acc || !acc.logged_in) {
    html += '<div class="card text-center text-muted text-[12px]">هذا الحساب غير متصل بعد</div>';
    c.innerHTML = html;
    return;
  }

  html +=
    '<div class="card">' +
    '<div class="flex items-center justify-between"><span class="text-[13px] font-bold">تفعيل الرد الآلي لهذا الحساب</span>' +
    '<label class="toggle-switch"><input type="checkbox" id="arEnabled"><span class="slider"></span></label></div>' +
    '<p class="text-[11px] text-muted mt-1">يفحص كل رسالة جديدة تجيك من أي شخص (مو رقم واحد بس)، ويرد أول شي إذا طابقت إحدى الكلمات أدناه.</p>' +
    '<label class="field-label">كلمات مفتاحية وردودها (لها الأولوية)</label>' +
    '<div id="rulesBox" class="mt-1"></div>' +
    '<button class="btn-outline" onclick="addRule()">' + icon('plus', 12) + ' إضافة كلمة مفتاحية</button>' +
    '<div class="flex items-center justify-between mt-4"><span class="text-[13px] font-bold">رد بالذكاء الاصطناعي (DeepSeek) لو ما طابقت أي كلمة مفتاحية</span>' +
    '<label class="toggle-switch"><input type="checkbox" id="arAiEnabled"><span class="slider"></span></label></div>' +
    '<p class="text-[11px] text-muted mt-1">يحتاج مفتاح DeepSeek محفوظ من قسم الإعدادات (يضيفه أدمن المنصة).</p>' +
    '<button class="btn-gold" onclick="saveAutoReply()">حفظ</button>' +
    '<div id="arMsg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '</div>';
  c.innerHTML = html;

  fetch('/accounts/' + acc.id + '/auto_reply').then(r => r.json()).then(d => {
    document.getElementById('arEnabled').checked = !!d.enabled;
    document.getElementById('arAiEnabled').checked = !!d.ai_enabled;
    autoReplyRules = d.rules && d.rules.length ? d.rules : [{keyword: 'سلام', reply: 'سلام عليكم'}];
    renderRules();
  });
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
  const aiEnabled = document.getElementById('arAiEnabled').checked;
  const r = await fetch('/accounts/' + acc.id + '/auto_reply', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({enabled: enabled, ai_enabled: aiEnabled, rules: autoReplyRules})
  }).then(res => res.json());
  if (r.ok) document.getElementById('arMsg').innerText = 'تم الحفظ';
  else if (!handleSubscriptionError(r, 'arMsg')) document.getElementById('arMsg').innerText = 'فشل: ' + r.error;
}

/* ---------- قسم الإعدادات ---------- */
function renderSettings() {
  const c = document.getElementById('content');
  const notifState = ('Notification' in window) ? Notification.permission : 'غير مدعوم';
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const initial = (CURRENT_USER || '؟').trim().charAt(0).toUpperCase();

  let html =
    '<div class="page-title"><h2>' + icon('settings', 18) + ' الإعدادات</h2></div>' +
    '<div class="profile-card">' +
      '<div class="avatar avatar-0">' + initial + '</div>' +
      '<div class="info"><div class="name">' + CURRENT_USER + '</div><span class="role">' + (IS_ADMIN ? 'أدمن المنصة' : 'مستخدم') + '</span></div>' +
    '</div>' +

    '<div class="settings-list">' +
      '<div class="settings-head">' + icon('settings', 12) + ' الإعدادات العامة</div>' +
      '<div class="settings-item">' +
        '<div class="left"><div class="icon-wrap primary">' + icon('moon', 15) + '</div><div><span class="text">الوضع الداكن</span><span class="sub-text">تفعيل المظهر الداكن للتطبيق</span></div></div>' +
        '<label class="toggle-switch"><input type="checkbox" id="darkModeToggle"' + (isDark ? ' checked' : '') + ' onchange="toggleTheme()"><span class="slider"></span></label>' +
      '</div>' +
      '<div class="settings-item" onclick="ensureNotifPermission()">' +
        '<div class="left"><div class="icon-wrap blue">' + icon('bell', 15) + '</div><div><span class="text">الإشعارات</span><span class="sub-text">الحالة الحالية: ' + notifState + '</span></div></div>' +
        '<i class="fas fa-chevron-left chevron"></i>' +
      '</div>' +
      '<div class="settings-item" onclick="showSection(\\'autoreply\\')">' +
        '<div class="left"><div class="icon-wrap primary">' + icon('autoreply', 15) + '</div><div><span class="text">الرد الآلي</span><span class="sub-text">ردود تلقائية على رسائل عملائك</span></div></div>' +
        '<i class="fas fa-chevron-left chevron"></i>' +
      '</div>' +
      (IS_ADMIN ? (
        '<div class="settings-item" onclick="showSection(\\'admin\\')">' +
          '<div class="left"><div class="icon-wrap primary">' + icon('admin', 15) + '</div><div><span class="text">لوحة تحكم الأدمن</span><span class="sub-text">إدارة الاشتراكات والذكاء الاصطناعي</span></div></div>' +
          '<i class="fas fa-chevron-left chevron"></i>' +
        '</div>'
      ) : '') +
    '</div>' +

    '<div class="subscription-card" id="subBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +

    '<div class="settings-list">' +
      '<div class="settings-head">' + icon('whatsapp', 12) + ' حول التطبيق</div>' +
      '<a class="settings-item" id="supportLink" href="#" target="_blank" style="text-decoration:none;color:inherit">' +
        '<div class="left"><div class="icon-wrap primary">' + icon('whatsapp', 15) + '</div><div><span class="text">الدعم الفني عبر واتساب</span><span class="sub-text">تواصل مع فريق الدعم</span></div></div>' +
        '<i class="fas fa-chevron-left chevron"></i>' +
      '</a>' +
    '</div>' +

    '<button class="btn-logout" onclick="logoutPlatform()">' + icon('logout', 14) + ' تسجيل الخروج من المنصة</button>';

  c.innerHTML = html;
  loadSubscription();
}

/* ---------- قسم التحكم (أدمن فقط) ---------- */
function renderAdmin() {
  const c = document.getElementById('content');
  if (!IS_ADMIN) { c.innerHTML = '<div class="empty-state">هذا القسم لأدمن المنصة فقط</div>'; return; }
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('admin', 18) + ' لوحة التحكم</h2></div>' +
    '<div class="card">' +
    '<div class="card-header"><h4>' + icon('ai', 14) + ' الرد الذكي (DeepSeek)</h4></div>' +
    '<label class="field-label">مفتاح API (يُترك فاضي لعدم التغيير)</label>' +
    '<input id="aiKey" type="password" placeholder="sk-...">' +
    '<label class="field-label">قائمة المنتجات والأسعار (يعتمد عليها الرد الذكي)</label>' +
    '<textarea id="aiKb" rows="6" placeholder="مثال:&#10;منتج أ - 10 دولار&#10;منتج ب - 15 دولار"></textarea>' +
    '<button class="btn-submit" onclick="saveAiSettings()">حفظ إعدادات الأدمن</button>' +
    '<div id="aiMsg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '</div>' +
    '<div class="card">' +
    '<div class="card-header"><h4>' + icon('users', 14) + ' العملاء</h4></div>' +
    '<div id="customersBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '</div>' +
    '<div class="card">' +
    '<div class="card-header"><h4>' + icon('payments', 14) + ' طلبات الدفع بانتظار المراجعة</h4></div>' +
    '<div id="paymentsBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '</div>';

  fetch('/admin/ai_settings').then(r => r.json()).then(d => {
    document.getElementById('aiKey').placeholder = d.api_key_set ? 'محفوظ مسبقاً (اتركه فاضي للإبقاء عليه)' : 'sk-...';
    document.getElementById('aiKb').value = d.knowledge_base || '';
  });
  loadCustomers();
  loadPendingPayments();
}

async function loadSubscription() {
  const d = await fetch('/subscription').then(r => r.json());
  const supportLink = document.getElementById('supportLink');
  if (supportLink) supportLink.href = d.wa_pay_link;

  const box = document.getElementById('subBox');
  if (!box) return;
  let html =
    '<div class="plan-name">' + d.plan_name + '</div>' +
    '<div class="plan-price">' + d.price_iqd.toLocaleString() + ' <span style="font-size:12px;font-weight:600;color:var(--text-secondary)">د.ع / شهرياً</span></div>';
  if (d.plan_active) {
    html += '<span class="pill pill-green">خطتك مفعّلة</span>';
  } else if (d.trial_days_left > 0) {
    html +=
      '<span class="pill pill-amber">تجربة مجانية — تبقى ' + d.trial_days_left + ' يوم</span>' +
      '<p class="text-[11px] text-muted mt-2">فعّل اشتراكك قبل انتهاء التجربة حتى تستمر الحملات والرد الآلي بالعمل بدون توقف.</p>';
  } else {
    html += '<span class="pill pill-red">انتهت الفترة التجريبية</span>' +
      '<p class="text-[11px] text-muted mt-2">لازم تفعّل الاشتراك حتى تكدر ترسل حملات أو تستخدم الرد الآلي.</p>';
  }
  if (!d.plan_active) {
    html += '<a class="btn-gold" style="display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;margin-top:12px" href="' + d.wa_pay_link + '" target="_blank">' + icon('whatsapp', 16) + ' اشتراك الآن عبر واتساب</a>';
  }
  if (d.payments && d.payments.length) {
    html += '<div class="mt-3" style="text-align:right">' + d.payments.map(p =>
      '<div class="history-row"><span>' + p.created_at + '</span><span>' + p.reference + '</span><span class="pill ' +
      (p.status === 'approved' ? 'pill-green' : p.status === 'rejected' ? 'pill-red' : 'pill-gray') + '">' +
      (p.status === 'approved' ? 'مقبول' : p.status === 'rejected' ? 'مرفوض' : 'قيد المراجعة') + '</span></div>'
    ).join('') + '</div>';
  }
  box.innerHTML = html;
}

function customerStatusPill(u) {
  if (u.plan_active) return '<span class="pill pill-green">مفعّل</span>';
  if (u.trial_ends_at) {
    const days = Math.ceil((new Date(u.trial_ends_at.replace(' ', 'T')) - new Date()) / 86400000);
    if (days > 0) return '<span class="pill pill-amber">تجربة ' + days + ' يوم</span>';
  }
  return '<span class="pill pill-red">منتهي</span>';
}

async function loadCustomers() {
  const rows = await fetch('/admin/customers').then(r => r.json());
  const box = document.getElementById('customersBox');
  if (!box) return;
  box.innerHTML = rows.length
    ? rows.map(u => {
        const contact = u.email || u.phone || '—';
        const label = u.name ? (u.name + ' · ' + contact) : contact;
        return '<div class="history-row"><span class="flex items-center gap-2">' + avatarHtml(label, String(u.id)) +
        '<span>' + label + '</span></span>' + customerStatusPill(u) +
        '<button class="btn-outline btn-small" onclick="toggleCustomerPlan(' + u.id + ', ' + (u.plan_active ? 'false' : 'true') + ')">' +
        (u.plan_active ? 'إلغاء' : 'تفعيل') + '</button></div>';
      }).join('')
    : '<div class="text-muted text-[11px]">ما فيه عملاء بعد</div>';
}

async function toggleCustomerPlan(userId, active) {
  await fetch('/admin/customers/' + userId + '/plan', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({active: active})
  });
  loadCustomers();
}

async function loadPendingPayments() {
  const rows = await fetch('/admin/payments').then(r => r.json());
  const box = document.getElementById('paymentsBox');
  if (!box) return;
  box.innerHTML = rows.length
    ? rows.map(p =>
        '<div class="history-row"><span>' + p.email + '</span><span>' + p.reference + '</span><span>' +
        '<button class="btn-outline btn-small" onclick="approvePayment(' + p.id + ')">قبول</button> ' +
        '<button class="btn-outline btn-small" style="color:#dc2626;border-color:rgba(220,38,38,.3)" onclick="rejectPayment(' + p.id + ')">رفض</button>' +
        '</span></div>'
      ).join('')
    : '<div class="text-muted text-[11px]">ما فيه طلبات دفع بانتظار المراجعة</div>';
}

async function approvePayment(id) {
  await fetch('/admin/payments/' + id + '/approve', { method: 'POST' });
  loadPendingPayments();
  loadCustomers();
}

async function rejectPayment(id) {
  await fetch('/admin/payments/' + id + '/reject', { method: 'POST' });
  loadPendingPayments();
}

async function saveAiSettings() {
  const r = await fetch('/admin/ai_settings', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({api_key: document.getElementById('aiKey').value.trim(), knowledge_base: document.getElementById('aiKb').value})
  }).then(res => res.json());
  document.getElementById('aiMsg').innerText = r.ok ? 'تم الحفظ' : ('فشل: ' + r.error);
  document.getElementById('aiKey').value = '';
}

function logoutPlatform() {
  document.getElementById('confirmOverlay').classList.add('show');
  document.getElementById('confirmSheet').classList.add('show');
}

function closeConfirmSheet() {
  document.getElementById('confirmOverlay').classList.remove('show');
  document.getElementById('confirmSheet').classList.remove('show');
}

function confirmLogout() {
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
