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
            email TEXT UNIQUE,
            phone TEXT UNIQUE,
            name TEXT,
            password_hash TEXT,
            is_admin INTEGER NOT NULL DEFAULT 0,
            plan_active INTEGER NOT NULL DEFAULT 0,
            trial_ends_at TEXT,
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
                created_at TEXT NOT NULL DEFAULT ''
            )
        """)
        conn.execute(
            "INSERT INTO users (id, email, phone, name, password_hash, is_admin, plan_active, trial_ends_at, created_at) "
            "SELECT id, email, phone, name, password_hash, is_admin, plan_active, trial_ends_at, created_at FROM users_old"
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
    return True


def db_set_plan_active(user_id, active):
    conn = get_db()
    conn.execute("UPDATE users SET plan_active = ? WHERE id = ?", (int(active), user_id))
    conn.commit()
    conn.close()


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
    print(f"[رد آلي] بدأت المراقبة لحساب {accounts.get(acc_id, {}).get('name', acc_id)}")
    while True:
        acc = accounts.get(acc_id)
        if not acc or not acc.get("watching") or acc["driver"] is None:
            print(f"[رد آلي] توقفت المراقبة لحساب {acc_id}")
            return
        try:
            with acc["lock"]:
                driver = acc["driver"]
                chat_items = driver.find_elements(By.CSS_SELECTOR, '#pane-side div[role="listitem"]')[:8]
                print(f"[رد آلي] {acc['name']}: فحص {len(chat_items)} محادثة")
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
                        add_event(acc["name"], "رد تلقائي", f"{chat_name}: {reply_text[:60]}")
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
    --bg: oklch(0.145 0.014 245); --card: oklch(0.195 0.017 245); --card-soft: oklch(0.235 0.019 245);
    --ink: oklch(0.97 0.006 245); --muted: oklch(0.72 0.02 245); --faint: oklch(0.5 0.02 245);
    --gold: oklch(0.78 0.17 152); --gold-strong: oklch(0.66 0.18 152); --green-ink: oklch(0.2 0.05 152);
    --border: oklch(1 0 0 / 9%); --border-2: oklch(1 0 0 / 15%);
  }}
  * {{ box-sizing:border-box; margin:0; padding:0; -webkit-tap-highlight-color:transparent; user-select:none; -webkit-user-select:none; }}
  html, body {{ width:100%; height:100%; background:var(--bg); font-family:{FONT_STACK}; overflow:hidden; position:fixed; inset:0; }}
  body {{ color:var(--ink); }}
  img, svg, .card, .feature {{ -webkit-touch-callout:none; pointer-events:none; }}
  .app {{ position:relative; width:100%; max-width:430px; height:100vh; height:100dvh; margin:auto; overflow:hidden;
    background: radial-gradient(circle at 50% 25%, oklch(0.78 0.17 152 / 8%), transparent 30%),
                radial-gradient(circle at 18% 60%, oklch(0.78 0.17 152 / 4%), transparent 20%),
                linear-gradient(180deg, oklch(0.16 0.015 245) 0%, var(--bg) 55%, oklch(0.135 0.013 245) 100%);
    display:flex; flex-direction:column; animation:fadeIn .3s ease; }}
  @keyframes fadeIn {{ from {{ opacity:0; }} to {{ opacity:1; }} }}
  .app::before {{ content:""; position:absolute; inset:0; pointer-events:none; opacity:.3;
    background-image: radial-gradient(circle at 12% 25%, oklch(0.78 0.17 152 / 70%) 0 3px, transparent 4px),
                       radial-gradient(circle at 88% 40%, oklch(0.78 0.17 152 / 60%) 0 3px, transparent 4px),
                       radial-gradient(circle at 23% 10%, oklch(0.78 0.17 152 / 70%) 0 4px, transparent 5px),
                       radial-gradient(circle at 84% 20%, oklch(0.78 0.17 152 / 30%) 0 2px, transparent 3px); }}
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
  .card {{ position:absolute; z-index:3; width:165px; min-height:65px; border:1px solid oklch(0.78 0.17 152 / 12%);
    background:linear-gradient(135deg, oklch(0.22 0.02 245 / 90%), oklch(0.16 0.015 245 / 90%)); box-shadow:0 10px 25px rgba(0,0,0,.35), inset 0 1px 0 oklch(1 0 0 / 3%);
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
  .primary small {{ font-size:12px; color:oklch(0.92 0.04 152); margin-top:2px; }}
  .whatsapp {{ height:50px; margin-top:10px; border:2px solid var(--border-2); background:oklch(0.12 0.013 245 / 45%); color:var(--gold); font-size:16px; font-weight:700; flex-direction:row; gap:8px; }}
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
  @media (min-width:431px) {{ .app {{ border-left:1px solid rgba(255,255,255,.03); border-right:1px solid rgba(255,255,255,.03); }} }}
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
  --bg: oklch(0.145 0.014 245); --card: oklch(0.195 0.017 245); --card-soft: oklch(0.235 0.019 245);
  --ink: oklch(0.97 0.006 245); --muted: oklch(0.72 0.02 245); --faint: oklch(0.5 0.02 245);
  --gold: oklch(0.78 0.17 152); --gold-strong: oklch(0.66 0.18 152); --green-ink: oklch(0.2 0.05 152);
  --border: oklch(1 0 0 / 9%); --border-2: oklch(1 0 0 / 15%);
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
  background: radial-gradient(circle at 50% 25%, oklch(0.78 0.17 152 / 9%), transparent 30%),
              radial-gradient(circle at 52% 60%, oklch(0.78 0.17 152 / 3.5%), transparent 25%),
              linear-gradient(180deg, oklch(0.16 0.015 245), var(--bg) 68%, oklch(0.19 0.018 245));
  animation:fadeIn .3s ease; }}
@keyframes fadeIn {{ from {{ opacity:0; }} to {{ opacity:1; }} }}
{PAGE_TRANSITION_CSS}
.page::before {{ content:""; position:absolute; inset:0; pointer-events:none; opacity:.7;
  background: radial-gradient(circle at 70% 7%, oklch(0.78 0.17 152 / 90%) 0 2px, transparent 3px),
              radial-gradient(circle at 86% 12%, oklch(0.78 0.17 152 / 80%) 0 3px, transparent 4px),
              radial-gradient(circle at 38% 6%, oklch(0.78 0.17 152 / 70%) 0 3px, transparent 4px),
              radial-gradient(circle at 13% 17%, oklch(0.78 0.17 152 / 90%) 0 2px, transparent 3px),
              radial-gradient(circle at 75% 23%, oklch(0.78 0.17 152 / 80%) 0 2px, transparent 3px),
              radial-gradient(circle at 29% 45%, oklch(0.78 0.17 152 / 90%) 0 3px, transparent 4px),
              radial-gradient(circle at 78% 50%, oklch(0.78 0.17 152 / 80%) 0 3px, transparent 4px); }}
.bubble {{ position:absolute; border:1px solid oklch(0.78 0.17 152 / 5%); background:oklch(0.78 0.17 152 / 1.5%); border-radius:18px; opacity:.5; pointer-events:none; }}
.bubble::before, .bubble::after {{ content:""; position:absolute; width:20px; height:3px; right:8px; background:oklch(0.78 0.17 152 / 5%); border-radius:6px; }}
.bubble::before {{ top:10px; }}
.bubble::after {{ top:17px; width:14px; }}
.bubble.one {{ width:60px; height:44px; top:130px; right:-10px; }}
.bubble.two {{ width:52px; height:38px; top:330px; left:-10px; }}
.bubble.three {{ width:48px; height:34px; top:500px; right:-12px; }}

.back {{ position:absolute; top:14px; right:16px; z-index:5; width:44px; height:44px; border-radius:50%; background:oklch(0.22 0.02 245 / 60%);
  border:1px solid var(--border-2); display:flex; align-items:center; justify-content:center; color:var(--ink); box-shadow:inset 0 1px oklch(1 0 0 / 3%); }}
.back svg {{ width:20px; height:20px; }}

.main-content {{ flex:1; display:flex; flex-direction:column; justify-content:center; padding:50px 22px 6px; position:relative; z-index:2; min-height:0; }}
.header {{ text-align:center; flex-shrink:0; }}
.logo {{ width:80px; height:80px; margin:0 auto 10px; border-radius:24px; background:linear-gradient(145deg, var(--gold), var(--gold-strong)); box-shadow:0 12px 35px oklch(0.78 0.17 152 / 14%); display:flex; align-items:center; justify-content:center; }}
.logo svg {{ width:54px; height:54px; }}
.title {{ font-family:{FONT_STACK}; font-size:26px; line-height:1.2; font-weight:800;
  background:linear-gradient(135deg, var(--gold), var(--gold-strong)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }}
.subtitle {{ margin-top:3px; color:var(--muted); font-size:12.5px; line-height:1.6; font-weight:400; }}

.login-card {{ position:relative; z-index:3; margin:22px 25px 0; border:1px solid var(--border); border-radius:16px;
  background:linear-gradient(145deg, oklch(0.19 0.018 245 / 88%), oklch(0.13 0.014 245 / 92%)); padding:20px 20px 18px; text-align:center; flex-shrink:0;
  box-shadow:0 14px 40px rgba(0,0,0,.2), inset 0 1px oklch(1 0 0 / 2%); min-height:320px; }}

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
.input-group input, .input-group select {{ width:100%; padding:12px 14px; border-radius:12px; border:1.5px solid var(--border-2); background:oklch(1 0 0 / 4%); color:var(--ink); font-size:14px; font-weight:400; }}
.input-group input:focus, .input-group select:focus {{ border-color:var(--gold); background:oklch(1 0 0 / 7%); }}
.input-group input::placeholder {{ color:var(--faint); }}

.phone-input-row {{ display:flex; gap:8px; margin-top:4px; direction:ltr; }}
.phone-input-row .country-code {{ flex:0 0 100px; }}
.phone-input-row .country-code select {{ padding:12px 10px; border-radius:12px; border:1.5px solid var(--border-2); background:oklch(1 0 0 / 4%); color:var(--ink); font-size:14px; font-weight:500; width:100%; height:100%; direction:ltr; }}
.phone-input-row .country-code select:focus {{ border-color:var(--gold); background:oklch(1 0 0 / 7%); }}
.phone-input-row .phone-number {{ flex:1; }}
.phone-input-row .phone-number input {{ width:100%; padding:12px 14px; border-radius:12px; border:1.5px solid var(--border-2); background:oklch(1 0 0 / 4%); color:var(--ink); font-size:14px; height:100%; direction:ltr; text-align:left; }}
.phone-input-row .phone-number input:focus {{ border-color:var(--gold); background:oklch(1 0 0 / 7%); }}
.phone-input-row .phone-number input::placeholder {{ color:var(--faint); text-align:left; }}

.btn-primary {{ width:100%; height:48px; margin-top:14px; border-radius:14px; background:linear-gradient(180deg, var(--gold), var(--gold-strong)); color:var(--green-ink); font-size:15px; font-weight:600;
  display:flex; align-items:center; justify-content:center; gap:8px; }}
.btn-primary svg {{ width:18px; height:18px; }}
.btn-primary:disabled {{ opacity:.7; }}
.btn-outline {{ width:100%; height:44px; margin-top:8px; border-radius:14px; border:1.5px solid var(--border-2); background:oklch(1 0 0 / 3%); color:var(--muted); font-size:13.5px; font-weight:500;
  display:flex; align-items:center; justify-content:center; gap:8px; }}

.step-note {{ font-size:12px; color:var(--muted); line-height:1.7; margin:0 0 14px; }}
.qr-box {{ width:172px; height:172px; margin:0 auto; border-radius:16px; background:var(--ink); padding:10px; display:flex; align-items:center; justify-content:center; box-shadow:0 10px 30px rgba(0,0,0,.3); }}
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
  window.location.href = '/';
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
    if not user:
        is_admin = db_count_users() == 0
        user_id = db_create_user_by_phone(phone, None, is_admin)
        user = db_get_user_by_id(user_id)
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
    sent = sum(h["sent"] for a in my_accounts for h in a["history"])
    failed = sum(h["failed"] for a in my_accounts for h in a["history"])
    total_msgs = sent + failed
    return jsonify(
        accounts_total=len(my_accounts),
        accounts_connected=sum(1 for a in my_accounts if account_logged_in(a)),
        messages_sent=sent,
        success_rate=round((sent / total_msgs) * 100) if total_msgs else 0,
        campaigns_total=sum(len(a["history"]) for a in my_accounts),
    )


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
    was_enabled = acc["auto_reply"]["enabled"]
    enabled = bool(data.get("enabled"))
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
<meta name="theme-color" content="#123320">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root {
    --bg: oklch(0.145 0.014 245); --card: oklch(0.195 0.017 245); --card-soft: oklch(0.235 0.019 245); --card-3: oklch(0.29 0.02 245);
    --border: oklch(1 0 0 / 9%); --border-2: oklch(1 0 0 / 15%);
    --ink: oklch(0.97 0.006 245); --muted: oklch(0.72 0.02 245); --faint: oklch(0.5 0.02 245);
    --gold: oklch(0.78 0.17 152); --gold-strong: oklch(0.66 0.18 152);
    --gold-light: oklch(0.78 0.17 152 / 16%); --gold-border: oklch(0.78 0.17 152 / 30%); --gold-shadow: oklch(0.78 0.17 152 / 28%);
    --green-ink: oklch(0.2 0.05 152); --red: oklch(0.68 0.19 21); --blue: oklch(0.72 0.13 238); --amber: oklch(0.8 0.14 78);
    --shadow: rgba(0,0,0,.35);
  }
  :root[data-theme="light"] {
    --bg: oklch(0.97 0.006 245); --card: oklch(1 0 0); --card-soft: oklch(0.955 0.006 245); --card-3: oklch(0.91 0.008 245);
    --border: oklch(0 0 0 / 8%); --border-2: oklch(0 0 0 / 14%);
    --ink: oklch(0.22 0.02 245); --muted: oklch(0.42 0.02 245); --faint: oklch(0.58 0.02 245);
    --gold: oklch(0.6 0.17 152); --gold-strong: oklch(0.5 0.18 152);
    --gold-light: oklch(0.6 0.17 152 / 12%); --gold-border: oklch(0.6 0.17 152 / 25%); --gold-shadow: oklch(0.6 0.17 152 / 20%);
    --green-ink: oklch(0.99 0.01 152); --red: oklch(0.55 0.19 21); --blue: oklch(0.55 0.13 238); --amber: oklch(0.62 0.14 78);
    --shadow: rgba(0,0,0,.08);
  }
  html, body { margin: 0; padding: 0; background: var(--bg); color: var(--ink); font-family: 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal', system-ui, sans-serif; }
  .app { display: flex; flex-direction: column; min-height: 100vh; }
  h1, h2, h3, .topbar-title, .stat-num, .logo-word { font-family: 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal', sans-serif; }
  svg.icon { display: inline-block; vertical-align: middle; flex-shrink: 0; }

  .topbar { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: var(--card); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 30; }
  .topbar-title { font-size: 15px; font-weight: 800; }
  .topbar-actions { display: flex; gap: 6px; }
  .icon-btn { position: relative; width: 36px; height: 36px; border-radius: 11px; border: 1px solid var(--border-2); background: var(--card-soft); color: var(--ink); display: flex; align-items: center; justify-content: center; cursor: pointer; }
  .badge { position: absolute; top: -3px; left: -3px; width: 9px; height: 9px; border-radius: 50%; background: var(--red); }

  .notif-panel { position: absolute; top: 52px; left: 12px; width: 280px; max-height: 320px; overflow-y: auto; background: var(--card); border: 1px solid var(--border-2); border-radius: 16px; box-shadow: 0 12px 30px var(--shadow); z-index: 40; padding: 8px; }
  .notif-item { padding: 8px 10px; border-radius: 10px; font-size: 12px; }
  .notif-item + .notif-item { margin-top: 4px; }
  .notif-item b { display: block; font-size: 12px; color: var(--gold); }
  .notif-item span { color: var(--muted); font-size: 11px; }

  .body { display: flex; flex: 1; }
  .nav { width: 220px; flex-shrink: 0; background: var(--card); border-left: 1px solid var(--border); padding: 16px 10px; display: flex; flex-direction: column; }
  .nav-items { flex: 1; }
  .nav-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; border-radius: 12px; font-size: 13px; font-weight: 700; color: var(--muted); cursor: pointer; margin-bottom: 4px; transition: .15s ease; }
  .nav-item:hover { background: var(--card-soft); color: var(--ink); }
  .nav-item.active { background: var(--gold-light); border: 1px solid var(--gold-border); color: var(--gold); }
  .profile-card { display: flex; align-items: center; gap: 10px; padding: 12px 10px; border-top: 1px solid var(--border); margin-top: 8px; }
  .profile-name { font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .content { flex: 1; padding: 28px; display: flex; justify-content: center; }
  .content-inner { width: 100%; max-width: 620px; }
  .bottom-tabs { display: none; }

  @media (max-width: 780px) {
    .nav { display: none; }
    .content { padding: 16px 16px 90px; }
    .bottom-tabs {
      display: flex; align-items: center; position: fixed; bottom: 0; left: 0; right: 0; height: 66px;
      background: color-mix(in oklch, var(--card) 82%, transparent); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
      border-top: 1px solid var(--border); z-index: 30;
    }
    .bottom-tab { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; font-size: 10px; font-weight: 700; color: var(--faint); cursor: pointer; }
    .bottom-tab.active { color: var(--gold); }
    .fab {
      width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; margin: 0 6px; cursor: pointer;
      background: linear-gradient(135deg, var(--gold), var(--gold-strong)); color: var(--green-ink);
      display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 18px var(--gold-shadow);
    }
  }

  .dark-card { background: var(--card); border: 1px solid var(--border); border-radius: 18px; box-shadow: 0 4px 20px var(--shadow); }
  .glossy-card {
    background: linear-gradient(145deg, var(--card), var(--card-soft));
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

  .text-gold { color: var(--gold); }
  .text-red { color: var(--red); }
  .border-gold { border-color: var(--gold-border); }
  .bg-gold-light { background: var(--gold-light); }
  .text-muted { color: var(--muted); }

  .step-item { display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); }
  .step-item:last-child { border-bottom: 0; }
  .step-num { width: 26px; height: 26px; border-radius: 50%; background: var(--gold-light); border: 1px solid var(--gold-border); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; color: var(--gold); }
  .step-text { font-size: 13px; color: var(--ink); font-weight: 500; }
  .step-text small { display: block; font-weight: 400; font-size: 11px; color: var(--muted); margin-top: 2px; }

  .field-label { display: block; margin-top: 16px; margin-bottom: 6px; font-size: 12px; color: var(--muted); font-weight: 500; }
  input, textarea, select {
    width: 100%; box-sizing: border-box; padding: 11px 12px; font-size: 14px; font-family: inherit;
    background: var(--card-soft); border: 1px solid var(--border-2); border-radius: 12px; color: var(--ink);
  }
  input:focus, textarea:focus, select:focus { outline: none; border-color: var(--gold); }
  textarea { resize: vertical; }
  input[type="file"] { padding: 9px 12px; }
  input[type="checkbox"] { width: auto; }

  .btn-gold {
    display: block; width: 100%; padding: 13px; margin-top: 18px; border: none; border-radius: 15px;
    background: linear-gradient(135deg, var(--gold), var(--gold-strong)); color: var(--green-ink);
    font-weight: 800; font-size: 15px; font-family: inherit; cursor: pointer; transition: .2s ease;
    box-shadow: 0 8px 20px var(--gold-shadow);
  }
  .btn-gold:hover { filter: brightness(1.05); }
  .btn-outline {
    display: block; width: 100%; padding: 11px; margin-top: 10px; border-radius: 14px;
    background: var(--card-soft); border: 1.5px solid var(--border-2); color: var(--ink);
    font-weight: 700; font-size: 13px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-outline:hover { border-color: var(--gold-border); color: var(--gold); }
  .btn-danger {
    display: block; width: 100%; padding: 10px; margin-top: 12px; border-radius: 14px;
    background: transparent; border: 1.5px solid color-mix(in oklch, var(--red) 35%, transparent); color: var(--red);
    font-weight: 700; font-size: 12px; font-family: inherit; cursor: pointer; transition: .2s ease;
  }
  .btn-danger:hover { background: color-mix(in oklch, var(--red) 8%, transparent); }
  .btn-small { width: auto; padding: 8px 14px; margin-top: 0; display: inline-block; }

  .confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(4px); z-index: 90; display: none; }
  .confirm-overlay.show { display: block; }
  .confirm-sheet { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 480px;
    background: var(--card); border-radius: 20px 20px 0 0; box-shadow: 0 -8px 40px var(--shadow); z-index: 91; display: none;
    padding: 22px 20px calc(20px + env(safe-area-inset-bottom)); }
  .confirm-sheet.show { display: block; animation: sheetUp .3s cubic-bezier(.34,1.56,.64,1); }
  @keyframes sheetUp { from { transform: translate(-50%, 100%); } to { transform: translate(-50%, 0); } }
  .confirm-sheet .sheet-handle { width: 40px; height: 4px; background: var(--border-2); border-radius: 4px; margin: 0 auto 16px; }
  .confirm-sheet .sheet-title { font-size: 16px; font-weight: 800; color: var(--ink); text-align: center; margin-bottom: 6px; font-family: 'Cairo','Tajawal',sans-serif; }
  .confirm-sheet .sheet-message { font-size: 13px; color: var(--muted); text-align: center; margin-bottom: 20px; }
  .confirm-sheet .sheet-actions { display: flex; gap: 10px; }
  .confirm-sheet .sheet-actions button { flex: 1; height: 46px; border: none; border-radius: 14px; font-size: 14px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; }
  .confirm-sheet .btn-cancel { background: var(--card-soft); color: var(--muted); }
  .confirm-sheet .btn-confirm { background: var(--red); color: #fff; }

  #qrImg { width: 220px; height: 220px; border-radius: 16px; border: 1px solid var(--border-2); background: #fff; display: block; margin: 0 auto; }

  .stat-row { display: flex; border-radius: 16px; overflow: hidden; margin-top: 14px; }
  .stat-cell { flex: 1; text-align: center; padding: 12px 4px; background: var(--card); border: 1px solid var(--border); }
  .stat-cell + .stat-cell { border-right: none; }
  .stat-num { font-size: 18px; font-weight: 800; }
  .stat-label { font-size: 10px; color: var(--muted); margin-top: 2px; }

  .account-name { font-size: 13px; font-weight: 700; flex: 1; }
  .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .dot-on { background: var(--gold); box-shadow: 0 0 6px var(--gold-shadow); }
  .dot-off { background: var(--amber); }
  .empty-state { text-align: center; color: var(--muted); font-size: 13px; margin-top: 60px; }

  .avatar { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 14px; }
  .avatar-0 { background: linear-gradient(135deg, oklch(0.78 0.17 152), oklch(0.6 0.17 152)); }
  .avatar-1 { background: linear-gradient(135deg, oklch(0.72 0.13 238), oklch(0.55 0.15 238)); }
  .avatar-2 { background: linear-gradient(135deg, oklch(0.72 0.15 300), oklch(0.55 0.16 300)); }
  .avatar-3 { background: linear-gradient(135deg, oklch(0.8 0.14 78), oklch(0.65 0.16 60)); }
  .avatar-4 { background: linear-gradient(135deg, oklch(0.72 0.17 30), oklch(0.6 0.19 21)); }

  .pill { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 800; }
  .pill-green { background: var(--gold-light); color: var(--gold); }
  .pill-blue { background: color-mix(in oklch, var(--blue) 16%, transparent); color: var(--blue); }
  .pill-red { background: color-mix(in oklch, var(--red) 14%, transparent); color: var(--red); }
  .pill-amber { background: color-mix(in oklch, var(--amber) 18%, transparent); color: var(--amber); }
  .pill-gray { background: var(--card-3); color: var(--muted); }

  .history-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 4px; border-bottom: 1px solid var(--border); font-size: 12px; }
  .history-row:last-child { border-bottom: none; }

  .rule-row { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
  .rule-row input { margin-top: 0; }
  .rule-remove { flex-shrink: 0; width: 32px; height: 32px; border-radius: 10px; border: 1px solid color-mix(in oklch, var(--red) 35%, transparent); background: transparent; color: var(--red); cursor: pointer; }
</style>
</head>
<body>
<div class="confirm-overlay" id="confirmOverlay" onclick="closeConfirmSheet()"></div>
<div class="confirm-sheet" id="confirmSheet">
  <div class="sheet-handle"></div>
  <div class="sheet-title">تسجيل الخروج</div>
  <div class="sheet-message">هل أنت متأكد من رغبتك في تسجيل الخروج؟</div>
  <div class="sheet-actions">
    <button class="btn-cancel" onclick="closeConfirmSheet()">إلغاء</button>
    <button class="btn-confirm" onclick="confirmLogout()">تأكيد</button>
  </div>
</div>
<div class="app">
  <header class="topbar">
    <span class="text-[11px] text-muted">__USERNAME__</span>
    <div class="topbar-title" style="display:flex;align-items:center;gap:7px">
      <svg width="26" height="26" viewBox="0 0 32 32" aria-hidden="true">
        <defs><linearGradient id="logoGrad" x1="0" y1="0" x2="32" y2="32">
          <stop offset="0" stop-color="oklch(0.78 0.17 152)"/><stop offset="1" stop-color="oklch(0.6 0.18 152)"/>
        </linearGradient></defs>
        <rect x="1" y="1" width="30" height="30" rx="9" fill="url(#logoGrad)"/>
        <path d="M9 11a2 2 0 012-2h10a2 2 0 012 2v7a2 2 0 01-2 2h-7l-4 4v-4h-1a2 2 0 01-2-2v-7z" fill="#fff"/>
        <path d="M10.5 15h3l1.5-3 2.5 6 1.5-3h3" fill="none" stroke="url(#logoGrad)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <span class="logo-word">واصل لحملات واتساب</span>
    </div>
    <div class="topbar-actions">
      <button class="icon-btn" id="themeBtn" onclick="toggleTheme()"></button>
      <button class="icon-btn" id="bellBtn" onclick="toggleNotifPanel()"><span class="badge" id="bellBadge" style="display:none"></span></button>
    </div>
  </header>

  <div class="notif-panel" id="notifPanel" style="display:none"></div>

  <div class="body">
    <nav class="nav" id="nav">
      <div class="nav-items">
        <div class="nav-item" data-s="home" onclick="showSection('home')"></div>
        <div class="nav-item" data-s="accounts" onclick="showSection('accounts')"></div>
        <div class="nav-item" data-s="campaigns" onclick="showSection('campaigns')"></div>
        <div class="nav-item" data-s="autoreply" onclick="showSection('autoreply')"></div>
        <div class="nav-item" data-s="admin" id="navAdmin" onclick="showSection('admin')"></div>
        <div class="nav-item" data-s="settings" onclick="showSection('settings')"></div>
      </div>
      <div class="profile-card" id="profileCard"></div>
    </nav>
    <main class="content"><div class="content-inner" id="content"></div></main>
  </div>

  <nav class="bottom-tabs" id="bottomTabs">
    <div class="bottom-tab" data-s="home" onclick="showSection('home')"></div>
    <div class="bottom-tab" data-s="accounts" onclick="showSection('accounts')"></div>
    <div class="fab" onclick="showSection('campaigns')" title="بدء حملة جديدة"></div>
    <div class="bottom-tab" data-s="campaigns" onclick="showSection('campaigns')"></div>
    <div class="bottom-tab" data-s="autoreply" onclick="showSection('autoreply')"></div>
    <div class="bottom-tab" data-s="settings" onclick="showSection('settings')"></div>
  </nav>
</div>

<script>
const IS_ADMIN = __IS_ADMIN__;

/* ---------- أيقونات خطية (بدون إيموجي) ---------- */
const ICONS = {
  home: '<path d="M4 11l8-7 8 7"/><path d="M6 10v9a1 1 0 001 1h10a1 1 0 001-1v-9"/><path d="M10 20v-6h4v6"/>',
  accounts: '<circle cx="12" cy="8" r="3.2"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/>',
  campaigns: '<line x1="21" y1="3" x2="10" y2="14"/><polygon points="21 3 14 21 10 14 3 10 21 3"/>',
  autoreply: '<path d="M4 5h16v11H8l-4 4V5z"/><circle cx="9" cy="10.3" r=".9" fill="currentColor" stroke="none"/><circle cx="12" cy="10.3" r=".9" fill="currentColor" stroke="none"/><circle cx="15" cy="10.3" r=".9" fill="currentColor" stroke="none"/>',
  admin: '<path d="M12 3l7 3v6c0 5-3 8-7 9-4-1-7-4-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
  settings: '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="15" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="9" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="17" cy="18" r="2"/>',
  bell: '<path d="M6 8a6 6 0 0112 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 21a2 2 0 004 0"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>',
  moon: '<path d="M20 14.5A8 8 0 119.5 4a6.5 6.5 0 1010.5 10.5z"/>',
  plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
  whatsapp: '<path d="M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3.5 20l1.2-4.2A8.5 8.5 0 1 1 20.5 11.5Z"/><path d="M8.5 8.2c.3-.4.6-.4.9-.2l1.1 1.7c.2.3.1.6-.1.9l-.6.6c.7 1.4 1.8 2.4 3.2 3.1l.6-.7c.2-.2.6-.3.9-.1l1.7 1c.3.2.3.6.1.9-.4.7-1 1.1-1.8 1-3.7-.5-7.2-3.8-7.9-7.6-.1-.2.1-.5.2-.6l1.7-.9Z"/>',
};
function icon(name, size) {
  size = size || 20;
  return '<svg class="icon" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || '') + '</svg>';
}

const CURRENT_USER = "__USERNAME__";
const SECTION_LABELS = { home: 'الرئيسية', accounts: 'حسابي', campaigns: 'الحملات', autoreply: 'الرد الآلي', admin: 'التحكم', settings: 'الإعدادات' };

function initChrome() {
  document.getElementById('bellBtn').insertAdjacentHTML('afterbegin', icon('bell'));
  document.querySelectorAll('.nav-item').forEach(function (el) {
    el.innerHTML = icon(el.dataset.s) + '<span>' + SECTION_LABELS[el.dataset.s] + '</span>';
  });
  document.querySelectorAll('.bottom-tab').forEach(function (el) {
    el.innerHTML = icon(el.dataset.s, 20) + SECTION_LABELS[el.dataset.s];
  });
  const fab = document.querySelector('.fab');
  if (fab) fab.innerHTML = icon('plus', 22);
  if (!IS_ADMIN) document.getElementById('navAdmin').style.display = 'none';
  const initial = (CURRENT_USER || '؟').trim().charAt(0).toUpperCase();
  document.getElementById('profileCard').innerHTML =
    '<div class="avatar avatar-0">' + initial + '</div>' +
    '<div style="overflow:hidden"><div class="profile-name">' + CURRENT_USER + '</div>' +
    '<div class="pill pill-green" style="margin-top:3px">' + (IS_ADMIN ? 'أدمن المنصة' : 'مستخدم') + '</div></div>';
}
initChrome();

let accounts = [];
let section = 'home';
let activeId = null;
let gen = 0;
let lastSeenEventId = -1;

/* ---------- تصميم داكن/نهاري ---------- */
function applyTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  localStorage.setItem('theme', t);
  const btn = document.getElementById('themeBtn');
  if (btn) btn.innerHTML = icon(t === 'dark' ? 'sun' : 'moon');
}
function toggleTheme() {
  applyTheme((document.documentElement.getAttribute('data-theme') || 'dark') === 'dark' ? 'light' : 'dark');
}
applyTheme(localStorage.getItem('theme') || 'dark');

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

function handleSubscriptionError(r, msgElId) {
  if (!r.needs_subscription) return false;
  document.getElementById(msgElId).innerHTML = r.error + ' — <span style="text-decoration:underline;cursor:pointer" onclick="showSection(\\'settings\\')">فتح الاشتراك</span>';
  return true;
}

/* ---------- التنقل بين الأقسام ---------- */
function showSection(s) {
  section = s;
  document.querySelectorAll('.nav-item, .bottom-tab').forEach(n => n.classList.toggle('active', n.dataset.s === s));
  render();
}
showSection('home');

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
  if (section === 'home') renderHome();
  else if (section === 'accounts') renderAccounts();
  else if (section === 'campaigns') renderCampaigns(myGen);
  else if (section === 'autoreply') renderAutoReply();
  else if (section === 'admin') renderAdmin();
  else renderSettings();
}

/* ---------- قسم الرئيسية ---------- */
function statCardHtml(iconName, label, value) {
  return '<div class="dark-card rounded-2xl p-4">' +
    '<div style="width:34px;height:34px;border-radius:10px;background:var(--gold-light);display:flex;align-items:center;justify-content:center;color:var(--gold);margin-bottom:10px">' + icon(iconName, 18) + '</div>' +
    '<div class="stat-num text-gold" style="font-size:22px">' + value + '</div>' +
    '<div class="text-[11px] text-muted mt-1">' + label + '</div></div>';
}

function renderHome() {
  const c = document.getElementById('content');
  c.innerHTML =
    '<h2 class="text-sm font-extrabold text-gold mb-1">مرحباً، ' + (CURRENT_USER.split('@')[0] || CURRENT_USER) + '</h2>' +
    '<p class="text-[12px] text-muted mb-3">نظرة عامة على نشاطك</p>' +
    '<div class="grid grid-cols-2 gap-3" id="homeStats"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>';
  fetch('/dashboard/stats').then(r => r.json()).then(d => {
    document.getElementById('homeStats').innerHTML =
      statCardHtml('campaigns', 'رسائل مرسلة', d.messages_sent.toLocaleString()) +
      statCardHtml('settings', 'معدل النجاح', d.success_rate + '%') +
      statCardHtml('accounts', 'حسابات متصلة', d.accounts_connected + ' / ' + d.accounts_total) +
      statCardHtml('autoreply', 'إجمالي الحملات', d.campaigns_total);
  });
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
  let html = '<h2 class="text-sm font-extrabold text-gold mb-3">حسابي</h2>';
  if (!accounts.length) {
    html += '<div class="empty-state">ما عندك حسابات بعد، ضيف واحد للبدء</div>';
  }
  accounts.forEach(acc => {
    html += '<div class="dark-card rounded-2xl p-3 mb-3">' +
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
    '<div class="stat-cell"><div class="stat-num text-gold" id="statSent">0</div><div class="stat-label">تم الإرسال</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-red" id="statFailed">0</div><div class="stat-label">فشل</div></div>' +
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
    ? rows.map(h => '<div class="history-row"><span>' + h.time + '</span><span class="pill pill-green">نجح ' + h.sent + '</span><span class="pill pill-red">فشل ' + h.failed + '</span><span class="text-muted">من ' + h.total + '</span></div>').join('')
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
    '<p class="text-[11px] text-muted mt-1">يفحص كل رسالة جديدة تجيك من أي شخص (مو رقم واحد بس)، ويرد أول شي إذا طابقت إحدى الكلمات أدناه.</p>' +
    '<label class="field-label">كلمات مفتاحية وردودها (لها الأولوية)</label>' +
    '<div id="rulesBox" class="mt-1"></div>' +
    '<button class="btn-outline" onclick="addRule()">+ إضافة كلمة مفتاحية</button>' +
    '<label class="flex items-center gap-2 text-[13px] font-bold mt-4"><input type="checkbox" id="arAiEnabled"> رد بالذكاء الاصطناعي (DeepSeek) لو ما طابقت أي كلمة مفتاحية</label>' +
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
  let html =
    '<h2 class="text-sm font-extrabold text-gold mb-3">الإعدادات</h2>' +
    '<div class="dark-card rounded-2xl p-4">' +
    '<div class="flex items-center justify-between mb-3"><span class="text-[13px] font-bold">المظهر</span>' +
    '<button class="btn-outline btn-small" onclick="toggleTheme()">تبديل داكن/نهاري</button></div>' +
    '<div class="flex items-center justify-between"><span class="text-[13px] font-bold">الإشعارات (' + notifState + ')</span>' +
    '<button class="btn-outline btn-small" onclick="ensureNotifPermission()">تفعيل</button></div>' +
    '<p class="text-[11px] text-muted mt-3">هذا التطبيق PWA — تقدر تضيفه لشاشتك الرئيسية من قائمة المتصفح "إضافة إلى الشاشة الرئيسية".</p>' +
    '</div>';

  if (IS_ADMIN) {
    html += '<button class="btn-outline" onclick="showSection(\\'admin\\')">فتح لوحة تحكم الأدمن</button>';
  }

  html +=
    '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">الاشتراك</h3>' +
    '<div class="dark-card rounded-2xl p-4" id="subBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '<button class="btn-danger" onclick="logoutPlatform()">تسجيل الخروج من المنصة</button>';

  c.innerHTML = html;
  loadSubscription();
}

/* ---------- قسم التحكم (أدمن فقط) ---------- */
function renderAdmin() {
  const c = document.getElementById('content');
  if (!IS_ADMIN) { c.innerHTML = '<div class="empty-state">هذا القسم لأدمن المنصة فقط</div>'; return; }
  c.innerHTML =
    '<h2 class="text-sm font-extrabold text-gold mb-3">لوحة التحكم</h2>' +
    '<h3 class="text-[12px] font-extrabold text-gold mb-1">الرد الذكي (DeepSeek)</h3>' +
    '<div class="dark-card rounded-2xl p-4">' +
    '<label class="field-label">مفتاح API (يُترك فاضي لعدم التغيير)</label>' +
    '<input id="aiKey" type="password" placeholder="sk-...">' +
    '<label class="field-label">قائمة المنتجات والأسعار (يعتمد عليها الرد الذكي)</label>' +
    '<textarea id="aiKb" rows="6" placeholder="مثال:&#10;منتج أ - 10 دولار&#10;منتج ب - 15 دولار"></textarea>' +
    '<button class="btn-gold" onclick="saveAiSettings()">حفظ إعدادات الأدمن</button>' +
    '<div id="aiMsg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '</div>' +
    '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">العملاء</h3>' +
    '<div class="dark-card rounded-2xl p-3" id="customersBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
    '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">طلبات الدفع بانتظار المراجعة</h3>' +
    '<div class="dark-card rounded-2xl p-3" id="paymentsBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>';

  fetch('/admin/ai_settings').then(r => r.json()).then(d => {
    document.getElementById('aiKey').placeholder = d.api_key_set ? 'محفوظ مسبقاً (اتركه فاضي للإبقاء عليه)' : 'sk-...';
    document.getElementById('aiKb').value = d.knowledge_base || '';
  });
  loadCustomers();
  loadPendingPayments();
}

async function loadSubscription() {
  const d = await fetch('/subscription').then(r => r.json());
  const box = document.getElementById('subBox');
  if (!box) return;
  let html = '<p class="text-[13px] font-bold">' + d.plan_name + ' — ' + d.price_iqd.toLocaleString() + ' د.ع / شهرياً</p>';
  if (d.plan_active) {
    html += '<span class="pill pill-green mt-1">خطتك مفعّلة</span>';
  } else if (d.trial_days_left > 0) {
    html +=
      '<span class="pill pill-amber mt-1">تجربة مجانية — تبقى ' + d.trial_days_left + ' يوم</span>' +
      '<p class="text-[11px] text-muted mt-2">فعّل اشتراكك قبل انتهاء التجربة حتى تستمر الحملات والرد الآلي بالعمل بدون توقف.</p>';
  } else {
    html += '<span class="pill pill-red mt-1">انتهت الفترة التجريبية</span>' +
      '<p class="text-[11px] text-muted mt-2">لازم تفعّل الاشتراك حتى تكدر ترسل حملات أو تستخدم الرد الآلي.</p>';
  }
  if (!d.plan_active) {
    html += '<a class="btn-gold" style="display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none" href="' + d.wa_pay_link + '" target="_blank">' + icon('whatsapp', 16) + ' اشتراك الآن عبر واتساب</a>';
  }
  if (d.payments && d.payments.length) {
    html += '<div class="mt-3">' + d.payments.map(p =>
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
