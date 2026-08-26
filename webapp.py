"""
منصة حملات واتساب متعددة الفروع: حساب عام واحد يسجَّل بالبريد الإلكتروني وكلمة
المرور، ومنه يُنشئ حسابات فروع (بريد/كلمة مرور مستقلة لكل فرع) تسجّل دخولها
بشكل منفصل وتدير حملاتها الخاصة (بيانات معزولة لكل فرع). تطبيق PWA بشريط تبويب
أسفل الشاشة على الجوال وقائمة جانبية على اللابتوب، حسابات واتساب متعددة لكل
حساب (عام أو فرع)، حملات فورية أو مجدولة بموعد واحد أو مقسّمة على عدة أيام
(مثلاً: قسّم 80 رقم على 4 أيام بمعدل 20 يومياً)، رسالة الحملة فيها حقل نص وحقل
رابط منفصلين يُدمجان بالرسالة المرسلة، رد آلي بكلمات مفتاحية أو بالذكاء
الاصطناعي (DeepSeek، يفعّله الأدمن)، وضع داكن/نهاري. ما فيه اشتراكات أو فترة
تجريبية - كل حساب مسجّل يقدر يستخدم المنصة كاملة مباشرة.

تشغيل:
    pip install -r requirements.txt
    python webapp.py
ثم افتح: http://localhost:5000 (أو http://IP_السيرفر:5000 على VPS)

أدمن المنصة حساب ثابت (بريد وكلمة مرور من ADMIN_EMAIL/ADMIN_PASSWORD بالأسفل، أو
من متغيرات البيئة بنفس الاسم) يُنشأ أو يُحدَّث تلقائياً عند كل إقلاع للسيرفر -
غيّر القيمتين قبل أي تشغيل فعلي على الإنترنت. أي شخص آخر يسوي "إنشاء حساب" يصير
حساباً عاماً عادياً (مو أدمن) يقدر يضيف حسابات واتساب وينشئ فروعاً تحته مباشرة.

بيانات المستخدمين تُحفظ بملف app.db (SQLite) وتبقى بعد إعادة تشغيل السيرفر،
وكذلك حسابات واتساب المضافة (اسمها وإعدادات ردها الآلي) تُحفظ وتُستعاد تلقائياً
عند إقلاع السيرفر من جديد - يعيد ربط كل حساب بجلسة متصفحه المحفوظة بمجلد
wa_sessions بدون حاجة لمسح رمز QR من جديد، طالما واتساب نفسه ما ألغى ربط الجلسة
من جهته.

ملاحظة صدق: "الرد الآلي" (كلمات مفتاحية أو AI) يعتمد على مراقبة أول محادثة بقائمة
واتساب بشكل دوري لعدم وجود حدث رسمي "رسالة جديدة"، وهذا أضعف جزء بكل الكود لأنه
مبني على تخمين لمحددات DOM قابلة للتغيّر — توقّع حاجتها لجولة تصحيح لو ما اشتغلت
أول مرة، بنفس طريقة تصحيح إرفاق الصور سابقاً. نفس الملاحظة تنطبق على استخدام
واجهة واتساب ويب بالأتمتة (Selenium) بشكل عام: يخالف شروط استخدام واتساب الرسمية
للإرسال الآلي/الجماعي، فاستخدمه على مسؤوليتك ولأرقام وافقت فعلاً على استقبال
رسائلك (تسويق لعملائك الحاليين مثلاً) لا لإرسال رسائل غير مرغوبة.
"""

import json
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

# بيانات دخول أدمن المنصة - ثابتة عمداً (لا تتغيّر بالتسجيل). غيّرها لبياناتك قبل أي
# تشغيل فعلي، إما هنا مباشرة أو عبر متغيرات البيئة ADMIN_EMAIL / ADMIN_PASSWORD حتى
# لا تُحفظ كلمة المرور الحقيقية بالكود لو رفعت المشروع لمكان عام
ADMIN_EMAIL = os.environ.get("ADMIN_EMAIL", "admin@wasel.local")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "ChangeMe@12345")

DEFAULT_SPLIT_TIME = "10:00"  # وقت الإرسال اليومي الافتراضي للحملات المقسّمة على عدة أيام
SCHEDULER_POLL_SECONDS = 30  # كل كم ثانية يتحقق خيط الجدولة من دفعات الحملات المستحقة


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

accounts = {}  # id -> {id, owner, name, driver, lock, campaign, history, auto_reply, watching}
events = []
events_lock = threading.Lock()


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
            email TEXT UNIQUE NOT NULL,
            name TEXT,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            parent_id INTEGER,
            created_at TEXT NOT NULL DEFAULT ''
        )
    """)
    # ترقية لقاعدة بيانات أُنشئت بنسخة سابقة ما فيها عمود parent_id (حسابات الفروع)
    cols = [r["name"] for r in conn.execute("PRAGMA table_info(users)").fetchall()]
    if "parent_id" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN parent_id INTEGER")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS ai_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            api_key TEXT DEFAULT '',
            knowledge_base TEXT DEFAULT ''
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS wa_accounts (
            id TEXT PRIMARY KEY,
            owner INTEGER NOT NULL,
            name TEXT,
            auto_reply_enabled INTEGER NOT NULL DEFAULT 0,
            auto_reply_ai_enabled INTEGER NOT NULL DEFAULT 0,
            auto_reply_rules TEXT NOT NULL DEFAULT '[]',
            created_at TEXT NOT NULL DEFAULT ''
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS site_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            port INTEGER,
            domain TEXT DEFAULT ''
        )
    """)
    # قيم افتراضية أول مرة بس (لو ما فيه صف بعد) - بعدها الإعدادات من لوحة الأدمن هي المصدر
    if conn.execute("SELECT COUNT(*) FROM site_settings").fetchone()[0] == 0:
        conn.execute("INSERT INTO site_settings (id, port, domain) VALUES (1, NULL, '')")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS campaign_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id TEXT NOT NULL,
            owner INTEGER NOT NULL,
            day_index INTEGER NOT NULL,
            total_days INTEGER NOT NULL,
            numbers TEXT NOT NULL,
            total INTEGER NOT NULL,
            text TEXT NOT NULL,
            media_path TEXT,
            delay INTEGER NOT NULL,
            run_date TEXT NOT NULL,
            run_time TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            sent INTEGER NOT NULL DEFAULT 0,
            failed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )
    """)
    conn.commit()
    conn.close()


init_db()


def ensure_admin_user():
    """يضمن وجود حساب أدمن واحد ثابت ببريد وكلمة مرور ADMIN_EMAIL/ADMIN_PASSWORD، ويعيد
    ضبط كلمة مروره لتطابقهما بكل إقلاع - حتى تبقى بيانات دخول الأدمن معروفة وثابتة دائماً
    بدل الاعتماد على "أول مستخدم يسجّل يصير أدمن"."""
    conn = get_db()
    row = conn.execute("SELECT id FROM users WHERE email = ?", (ADMIN_EMAIL,)).fetchone()
    password_hash = generate_password_hash(ADMIN_PASSWORD)
    if row:
        conn.execute(
            "UPDATE users SET password_hash = ?, is_admin = 1, parent_id = NULL WHERE id = ?",
            (password_hash, row["id"]),
        )
    else:
        conn.execute(
            "INSERT INTO users (email, name, password_hash, is_admin, parent_id, created_at) "
            "VALUES (?, 'أدمن المنصة', ?, 1, NULL, ?)",
            (ADMIN_EMAIL, password_hash, datetime.now().strftime("%Y-%m-%d %H:%M")),
        )
    conn.commit()
    conn.close()


ensure_admin_user()


def db_get_user_by_email(email):
    conn = get_db()
    row = conn.execute("SELECT * FROM users WHERE email = ?", (email,)).fetchone()
    conn.close()
    return row


def db_get_user_by_id(user_id):
    conn = get_db()
    row = conn.execute("SELECT * FROM users WHERE id = ?", (user_id,)).fetchone()
    conn.close()
    return row


def db_create_user(email, password_hash, name=None, parent_id=None, is_admin=False):
    conn = get_db()
    cur = conn.execute(
        "INSERT INTO users (email, name, password_hash, is_admin, parent_id, created_at) VALUES (?, ?, ?, ?, ?, ?)",
        (email, name, password_hash, int(is_admin), parent_id, datetime.now().strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    user_id = cur.lastrowid
    conn.close()
    return user_id


def db_list_users():
    conn = get_db()
    rows = conn.execute("SELECT id, email, name, is_admin, parent_id, created_at FROM users ORDER BY id").fetchall()
    conn.close()
    return rows


def db_set_user_name(user_id, name):
    conn = get_db()
    conn.execute("UPDATE users SET name = ? WHERE id = ?", (name, user_id))
    conn.commit()
    conn.close()


def db_create_branch(email, password_hash, name, parent_id):
    return db_create_user(email, password_hash, name, parent_id=parent_id, is_admin=False)


def db_list_branches(parent_id):
    conn = get_db()
    rows = conn.execute(
        "SELECT id, email, name, created_at FROM users WHERE parent_id = ? ORDER BY id", (parent_id,)
    ).fetchall()
    conn.close()
    return rows


def db_get_branch(branch_id, parent_id):
    conn = get_db()
    row = conn.execute(
        "SELECT * FROM users WHERE id = ? AND parent_id = ?", (branch_id, parent_id)
    ).fetchone()
    conn.close()
    return row


def db_delete_branch(branch_id, parent_id):
    conn = get_db()
    conn.execute("DELETE FROM users WHERE id = ? AND parent_id = ?", (branch_id, parent_id))
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


def db_get_site_settings():
    conn = get_db()
    row = conn.execute("SELECT * FROM site_settings WHERE id = 1").fetchone()
    conn.close()
    return row


def db_set_site_settings(port, domain):
    conn = get_db()
    conn.execute(
        "INSERT INTO site_settings (id, port, domain) VALUES (1, ?, ?) "
        "ON CONFLICT(id) DO UPDATE SET port = excluded.port, domain = excluded.domain",
        (port, domain),
    )
    conn.commit()
    conn.close()


def db_save_wa_account(acc_id, owner, name):
    conn = get_db()
    conn.execute(
        "INSERT OR IGNORE INTO wa_accounts (id, owner, name, created_at) VALUES (?, ?, ?, ?)",
        (acc_id, owner, name, datetime.now().strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    conn.close()


def db_update_wa_account_auto_reply(acc_id, enabled, ai_enabled, rules):
    conn = get_db()
    conn.execute(
        "UPDATE wa_accounts SET auto_reply_enabled = ?, auto_reply_ai_enabled = ?, auto_reply_rules = ? WHERE id = ?",
        (int(enabled), int(ai_enabled), json.dumps(rules, ensure_ascii=False), acc_id),
    )
    conn.commit()
    conn.close()


def db_delete_wa_account(acc_id):
    conn = get_db()
    conn.execute("DELETE FROM wa_accounts WHERE id = ?", (acc_id,))
    conn.commit()
    conn.close()


def db_list_wa_accounts():
    conn = get_db()
    rows = conn.execute("SELECT * FROM wa_accounts").fetchall()
    conn.close()
    return rows


# ---------------------------------------------------------------- دفعات الحملات المقسّمة على أيام

def db_create_batch(account_id, owner, day_index, total_days, numbers, text, media_path, delay, run_date, run_time):
    conn = get_db()
    conn.execute(
        "INSERT INTO campaign_batches (account_id, owner, day_index, total_days, numbers, total, text, "
        "media_path, delay, run_date, run_time, status, created_at) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
        (account_id, owner, day_index, total_days, json.dumps(numbers), len(numbers), text,
         media_path, delay, run_date, run_time, datetime.now().strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    conn.close()


def db_list_batches_for_account(account_id):
    conn = get_db()
    rows = conn.execute(
        "SELECT id, day_index, total_days, total, run_date, run_time, status, sent, failed "
        "FROM campaign_batches WHERE account_id = ? ORDER BY run_date, run_time",
        (account_id,),
    ).fetchall()
    conn.close()
    return rows


def db_get_due_batches(today, now_time):
    conn = get_db()
    rows = conn.execute(
        "SELECT * FROM campaign_batches WHERE status = 'pending' "
        "AND (run_date < ? OR (run_date = ? AND run_time <= ?)) ORDER BY run_date, run_time",
        (today, today, now_time),
    ).fetchall()
    conn.close()
    return rows


def db_mark_batch_running(batch_id):
    conn = get_db()
    conn.execute("UPDATE campaign_batches SET status = 'running' WHERE id = ?", (batch_id,))
    conn.commit()
    conn.close()


def db_finish_batch(batch_id, sent, failed):
    conn = get_db()
    conn.execute(
        "UPDATE campaign_batches SET status = 'done', sent = ?, failed = ? WHERE id = ?",
        (sent, failed, batch_id),
    )
    conn.commit()
    conn.close()


def db_cancel_batch(batch_id, owner):
    conn = get_db()
    cur = conn.execute(
        "UPDATE campaign_batches SET status = 'cancelled' WHERE id = ? AND owner = ? AND status = 'pending'",
        (batch_id, owner),
    )
    conn.commit()
    ok = cur.rowcount > 0
    conn.close()
    return ok


def db_delete_batches_for_account(account_id):
    conn = get_db()
    conn.execute("DELETE FROM campaign_batches WHERE account_id = ?", (account_id,))
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


def general_required(f):
    """يمنع حسابات الفروع من إنشاء فروع تحتها - فرع مستوى واحد بس تحت كل حساب عام."""
    @wraps(f)
    def wrapper(*args, **kwargs):
        if session.get("parent_id"):
            return jsonify(ok=False, error="هذا الإجراء متاح فقط للحساب العام، مو لحسابات الفروع"), 403
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
    db_save_wa_account(acc_id, owner, accounts[acc_id]["name"])
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


def run_campaign_batch(acc, batch_id, day_index, total_days, numbers, text, delay, media_path):
    """يرسل دفعة يوم واحد من حملة مقسّمة على عدة أيام، ويحدّث سجل campaign_batches بدل
    الاعتماد على acc['campaign'] وحده لأن هذا يتكرر عدة مرات (يوم بعد يوم) لنفس الحساب."""
    state = acc["campaign"]
    started = datetime.now().strftime("%Y-%m-%d %H:%M")
    add_event(
        acc["owner"], acc["name"], "بدأ يوم من حملة مجدولة",
        f"اليوم {day_index}/{total_days} — جارِ إرسال {len(numbers)} رسالة", kind="info",
    )
    sent = failed = 0
    failed_numbers = []
    for i, number in enumerate(numbers):
        with acc["lock"]:
            try:
                send_to(acc["driver"], number, text, media_path)
                sent += 1
            except Exception:
                failed += 1
                failed_numbers.append(number)
        state["sent"], state["failed"], state["failed_numbers"] = sent, failed, failed_numbers
        if i < len(numbers) - 1:
            time.sleep(delay)
    state["running"] = False
    db_finish_batch(batch_id, sent, failed)
    acc["history"].insert(0, {
        "time": started, "total": len(numbers), "sent": sent, "failed": failed,
        "text": f"{text} (يوم {day_index}/{total_days})",
    })
    del acc["history"][20:]
    finish_kind = "success" if failed == 0 else "warning"
    add_event(
        acc["owner"], acc["name"], "اكتمل يوم من حملة مجدولة",
        f"اليوم {day_index}/{total_days} — نجح {sent} من {len(numbers)}، فشل {failed}", kind=finish_kind,
    )


def scheduler_loop():
    """خيط خلفي يفحص دوري دفعات campaign_batches المستحقة (تاريخ/وقت وصل أو فات) ويشغّلها،
    حساب واحد كحد أقصى بنفس اللحظة (نفس شرط acc['campaign']['running'] المستخدم بالإرسال
    الفوري) حتى ما نشغّل دفعتين بالتوازي على نفس متصفح واتساب."""
    while True:
        try:
            now = datetime.now()
            due = db_get_due_batches(now.strftime("%Y-%m-%d"), now.strftime("%H:%M"))
            for row in due:
                acc = accounts.get(row["account_id"])
                if not acc or acc["driver"] is None or not account_logged_in(acc) or acc["campaign"]["running"]:
                    continue  # نحاول بالدورة الجاية بدل ما نفشل الدفعة نهائياً (الحساب مشغول أو غير متصل)
                try:
                    numbers = json.loads(row["numbers"])
                except (TypeError, ValueError):
                    numbers = []
                acc["campaign"].update(total=len(numbers), sent=0, failed=0, running=True, failed_numbers=[], scheduled_for=None)
                db_mark_batch_running(row["id"])
                threading.Thread(
                    target=run_campaign_batch,
                    args=(acc, row["id"], row["day_index"], row["total_days"], numbers, row["text"], row["delay"], row["media_path"]),
                    daemon=True,
                ).start()
        except Exception as e:
            print(f"[جدولة] خطأ بدورة جدولة الحملات: {e}")
        time.sleep(SCHEDULER_POLL_SECONDS)


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
    diag2_dumped = False
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
                    try:
                        driver.find_element(By.TAG_NAME, "body").send_keys(Keys.ESCAPE)
                    except Exception:
                        pass
                    chat_items = driver.find_elements(By.CSS_SELECTOR, '#pane-side [data-testid="cell-frame-container"]')[:8]
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
                    names_ok = names_fail = text_found = 0
                    for item in chat_items:
                        try:
                            chat_name = item.find_element(By.CSS_SELECTOR, "span[title]").get_attribute("title") or "غير معروف"
                            names_ok += 1
                        except Exception:
                            names_fail += 1
                            continue
                        driver.execute_script("arguments[0].click();", item)
                        time.sleep(1.2)
                        incoming = driver.find_elements(By.CSS_SELECTOR, "div.message-in .selectable-text span")
                        last_text = incoming[-1].text.strip() if incoming else ""
                        if last_text:
                            text_found += 1
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
                    if chat_items and not diag2_dumped:
                        diag2_dumped = True
                        print(f"[رد آلي] {acc['name']}: تشخيص٢ - استخراج اسم نجح لـ {names_ok}/{len(chat_items)}, رسائل واردة موجودة لـ {text_found}/{len(chat_items)}")
                        if names_fail > 0:
                            try:
                                print(f"[رد آلي] {acc['name']}: عينة HTML لأول محادثة:\n{chat_items[0].get_attribute('outerHTML')[:1200]}")
                            except Exception as diag2_e:
                                print(f"[رد آلي] {acc['name']}: تعذر أخذ عينة HTML: {diag2_e}")
                        if names_ok > 0 and text_found == 0:
                            try:
                                main_total = len(driver.find_elements(By.CSS_SELECTOR, "#main *"))
                                probe3 = {}
                                for sel in ["div.message-in", "div.message-out", ".selectable-text",
                                            '[data-testid="conversation-panel-messages"]',
                                            '[data-testid="msg-container"]', "div.copyable-text"]:
                                    try:
                                        probe3[sel] = len(driver.find_elements(By.CSS_SELECTOR, f"#main {sel}"))
                                    except Exception:
                                        probe3[sel] = "خطأ"
                                print(f"[رد آلي] {acc['name']}: تشخيص٣ - إجمالي عناصر داخل #main: {main_total}, محددات مرشحة: {probe3}")
                            except Exception as diag3_e:
                                print(f"[رد آلي] {acc['name']}: تعذر أخذ تشخيص٣: {diag3_e}")
        except Exception as e:
            print(f"[رد آلي] خطأ بدورة المراقبة: {e}")
        time.sleep(6)


def restore_one_account(acc_id):
    """يشغّل متصفح الحساب ويعيد ربطه بنفس جلسة واتساب المحفوظة بمجلد wa_sessions (بدون
    QR جديد طالما واتساب ما ألغى الجلسة)، ثم يبدأ مراقبة الرد الآلي بعد اكتمال تشغيل
    المتصفح فعلياً - ليس بالتوازي معه - حتى لا تنتهي watch_account فوراً لأن driver لسا None."""
    acc = accounts.get(acc_id)
    if not acc:
        return
    try:
        start_account_driver(acc_id)
    except Exception as e:
        print(f"[استعادة حسابات] فشل تشغيل متصفح الحساب {acc.get('name', acc_id)}: {e}")
        return
    if acc["auto_reply"]["enabled"]:
        acc["watching"] = True
        watch_account(acc_id)


def restore_wa_accounts():
    """يستعيد كل حسابات واتساب المحفوظة بقاعدة البيانات عند إقلاع السيرفر، حتى لا يحتاج
    المستخدم يضيف حسابه ويمسح QR من جديد بعد كل إعادة تشغيل أو تحديث كود."""
    rows = db_list_wa_accounts()
    for row in rows:
        acc_id = row["id"]
        accounts[acc_id] = new_account_entry(acc_id, row["owner"], row["name"])
        try:
            rules = json.loads(row["auto_reply_rules"] or "[]")
        except (ValueError, TypeError):
            rules = []
        accounts[acc_id]["auto_reply"] = {
            "enabled": bool(row["auto_reply_enabled"]),
            "ai_enabled": bool(row["auto_reply_ai_enabled"]),
            "rules": rules,
        }
        threading.Thread(target=restore_one_account, args=(acc_id,), daemon=True).start()
    if rows:
        print(f"[استعادة حسابات] استعادة {len(rows)} حساب واتساب من قاعدة البيانات...")


# ---------------------------------------------------------------- صفحات المصادقة

ICON_LOGIN_ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 3h6v6"/><path d="M10 14L21 3"/><path d="M12 11v7H5V5h7"/></svg>'
ICON_PERSON_ADD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>'


def logo_svg(size=32):
    return f"""<svg width="{size}" height="{size}" viewBox="0 0 100 100" fill="none" aria-label="Wasel">
      <path d="M50 9C27 9 9 26 9 47.5c0 8 2.6 15.2 7.2 21.2L10 88l19-5.8c6.2 3.5 13.3 5.5 21 5.5 23 0 41-17 41-40.2S73 9 50 9Z" stroke="#fff" stroke-width="6"/>
      <path d="M35 25c-3 0-5 2-5 5 0 16 12 28 28 33 4 1 7-2 8-5l1-4-10-5-4 4c-5-2-9-6-11-11l4-4-5-10c-1-2-3-3-6-3Z" fill="#fff"/>
    </svg>"""


def render_auth_page(title, action, switch_html, error):
    error_html = f'<p class="status-msg error">{error}</p>' if error else ""
    is_login = action == "/login"
    if is_login:
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
        email_secondary = '<a href="/signup" class="btn-secondary">إنشاء حساب جديد</a>'
    else:
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
        email_secondary = '<a href="/login" class="btn-outline">عودة لتسجيل الدخول</a>'
    return f"""
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#006b73">
<title>واصل - {title}</title>
{FONT_LINKS}
<style>
* {{ box-sizing:border-box; margin:0; padding:0; }}
html, body {{ width:100%; min-height:100%; }}
body {{ font-family:{FONT_STACK}; color:#1A2E35; }}
button, a {{ font:inherit; border:0; cursor:pointer; background:none; }}
a {{ text-decoration:none; color:inherit; }}
input {{ font:inherit; outline:none; }}
{PAGE_TRANSITION_CSS}

.login-page {{ width:100%; min-height:100vh; background:linear-gradient(135deg, #004b52 0%, #006b73 100%);
  display:flex; align-items:center; justify-content:center; padding:24px; animation:fadeIn .3s ease; }}
@keyframes fadeIn {{ from {{ opacity:0; }} to {{ opacity:1; }} }}

.login-card {{ background:#fff; border-radius:20px; padding:40px 32px; max-width:420px; width:100%;
  box-shadow:0 20px 60px rgba(0,0,0,.25); }}
.login-logo {{ text-align:center; margin-bottom:26px; }}
.login-logo .logo-icon {{ width:64px; height:64px; margin:0 auto 12px; border-radius:20px;
  background:linear-gradient(135deg, #006b73, #0A8A94); display:inline-flex; align-items:center; justify-content:center;
  box-shadow:0 8px 32px rgba(0,107,115,.35); }}
.login-logo .logo-icon svg {{ width:38px; height:38px; }}
.login-logo h2 {{ font-size:21px; font-weight:900; color:#1A2E35; }}
.login-logo h2 span {{ color:#006b73; }}
.login-logo p {{ margin-top:4px; color:#8AA0B0; font-size:13px; font-weight:400; }}

.input-group {{ margin-top:16px; text-align:right; }}
.input-group:first-of-type {{ margin-top:0; }}
.input-group label {{ display:block; font-size:13px; font-weight:700; color:#4A6A78; margin-bottom:6px; }}
.input-group input {{ width:100%; height:48px; padding:0 16px; border:2px solid rgba(0,107,115,.08); border-radius:10px;
  background:#F0F4F8; color:#1A2E35; font-size:14px; text-align:right; }}
.input-group input:focus {{ border-color:#006b73; box-shadow:0 0 0 4px rgba(0,107,115,.06); }}
.input-group input::placeholder {{ color:#8AA0B0; }}

.btn-primary {{ width:100%; height:48px; margin-top:20px; border-radius:12px; background:linear-gradient(135deg, #006b73, #0A8A94);
  color:#fff; font-size:15px; font-weight:700; display:flex; align-items:center; justify-content:center; gap:8px;
  box-shadow:0 4px 16px rgba(0,107,115,.3); transition:.2s ease; }}
.btn-primary:hover {{ transform:translateY(-2px); box-shadow:0 8px 28px rgba(0,107,115,.4); }}
.btn-primary svg {{ width:16px; height:16px; }}
.btn-secondary, .btn-outline {{ width:100%; height:44px; margin-top:10px; border-radius:12px; border:1.5px solid rgba(0,107,115,.15);
  background:transparent; color:#4A6A78; font-size:13.5px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; transition:.2s ease; }}
.btn-secondary:hover, .btn-outline:hover {{ border-color:#006b73; color:#006b73; }}

.form-row-between {{ display:flex; align-items:center; justify-content:space-between; margin-top:14px; font-size:12px; }}
.remember-check {{ display:flex; align-items:center; gap:5px; color:#4A6A78; }}
.remember-check input {{ width:auto; accent-color:#006b73; }}
.forgot-link {{ color:#006b73; font-weight:600; }}

.status-msg {{ font-size:12px; color:#4A6A78; margin-top:12px; text-align:center; font-weight:500; min-height:16px; }}
.status-msg.error {{ color:#DC2626; }}
.status-msg.success {{ color:#059669; }}

p.switch {{ text-align:center; font-size:13px; color:#4A6A78; margin-top:18px; font-weight:400; }}
p.switch a {{ color:#006b73; font-weight:700; }}
.terms {{ margin-top:14px; text-align:center; color:#8AA0B0; font-size:11px; line-height:1.7; font-weight:400; }}
.terms-link {{ color:#006b73; font-weight:600; }}

@media (max-width:480px) {{
  .login-card {{ padding:28px 22px; border-radius:16px; }}
  .login-logo .logo-icon {{ width:56px; height:56px; }}
  .login-logo h2 {{ font-size:19px; }}
}}
</style>
</head>
<body>

<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-icon">{logo_svg(38)}</div>
      <h2>واصل <span>Business</span></h2>
      <p>{title} — سجل دخولك للوصول إلى حسابك وإدارة حملاتك</p>
    </div>

    {error_html}
    <form method="post" action="{action}">{email_fields}
    </form>
    {email_secondary}
    <div class="status-msg" id="emailStatus"></div>

    {switch_html}
    <div class="terms">بالتسجيل، فإنك توافق على <span class="terms-link">الشروط والأحكام</span> و<span class="terms-link">سياسة الخصوصية</span></div>
  </div>
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

PAGE_TRANSITION_CSS = """body.leaving { animation: pageFadeOut .13s ease forwards; }
@keyframes pageFadeOut { to { opacity:0; transform:scale(.98); } }"""

PAGE_TRANSITION_JS = """(function() {
  function leaveAndGo(action) {
    if (document.body.classList.contains('leaving')) return;
    document.body.classList.add('leaving');
    setTimeout(action, 130);
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


EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


@app.route("/login", methods=["GET", "POST"])
def login_page():
    switch = '<p class="switch">ما عندك حساب؟ <a href="/signup">أنشئ حساب</a></p>'
    if request.method == "GET":
        if session.get("user_id"):
            return redirect("/app")
        return render_auth_page("تسجيل الدخول", "/login", switch, "")
    email = request.form.get("email", "").strip().lower()
    password = request.form.get("password", "")
    user = db_get_user_by_email(email)
    if not user or not check_password_hash(user["password_hash"], password):
        return render_auth_page("تسجيل الدخول", "/login", switch, "البريد الإلكتروني أو كلمة المرور غير صحيحة")
    session["user_id"] = user["id"]
    session["email"] = user["email"]
    session["name"] = user["name"]
    session["is_admin"] = bool(user["is_admin"])
    session["parent_id"] = user["parent_id"]
    session.permanent = bool(request.form.get("remember"))
    return redirect("/")


@app.route("/signup", methods=["GET", "POST"])
def signup_page():
    switch = '<p class="switch">عندك حساب؟ <a href="/login">سجّل الدخول</a></p>'
    if request.method == "GET":
        if session.get("user_id"):
            return redirect("/app")
        return render_auth_page("إنشاء حساب", "/signup", switch, "")
    name = request.form.get("name", "").strip()
    email = request.form.get("email", "").strip().lower()
    password = request.form.get("password", "")
    if not name or not email or not password:
        return render_auth_page("إنشاء حساب", "/signup", switch, "عبّي كل الحقول")
    if not EMAIL_RE.match(email):
        return render_auth_page("إنشاء حساب", "/signup", switch, "أدخل بريد إلكتروني صحيح")
    if len(password) < 6:
        return render_auth_page("إنشاء حساب", "/signup", switch, "كلمة المرور لازم تكون 6 أحرف على الأقل")
    if db_get_user_by_email(email):
        return render_auth_page("إنشاء حساب", "/signup", switch, "هذا البريد مسجّل من قبل")
    # كل من يسجّل بنفسه يصير حساباً عاماً (مو فرعاً ومو أدمن) - يقدر يضيف حسابات واتساب
    # وينشئ فروعاً تحته مباشرة من قسم الإعدادات؛ أدمن المنصة حساب ثابت واحد فقط (ensure_admin_user)
    user_id = db_create_user(email, generate_password_hash(password), name, parent_id=None, is_admin=False)
    add_event(
        user_id, None, "مرحباً بك في واصل",
        "تم إنشاء حسابك العام بنجاح - أضف حساب واتساب وابدأ حملتك الأولى، أو أنشئ فروعاً من قسم الإعدادات",
        kind="success",
    )
    session["user_id"] = user_id
    session["email"] = email
    session["name"] = name
    session["is_admin"] = False
    session["parent_id"] = None
    return redirect("/")


@app.route("/logout", methods=["POST"])
def logout_page():
    session.clear()
    return redirect("/login")


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
    if session.get("user_id"):
        return redirect("/app")
    return redirect("/login")


@app.route("/app")
def app_home():
    if not session.get("user_id"):
        return redirect("/")
    page = PAGE.replace("__IS_ADMIN__", "true" if session.get("is_admin") else "false")
    page = page.replace("__IS_GENERAL__", "false" if session.get("parent_id") else "true")
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
        "  e.waitUntil(clients.openWindow('/app'));\n"
        "});\n"
    )
    return Response(js, mimetype="application/javascript")


@app.route("/events")
@login_required
def get_events():
    since = int(request.args.get("since", 0) or 0)
    uid = session["user_id"]
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


@app.route("/admin/site_settings", methods=["GET"])
@login_required
@admin_required
def get_site_settings():
    row = db_get_site_settings()
    return jsonify(port=(row["port"] if row else None), domain=(row["domain"] if row else ""))


@app.route("/admin/site_settings", methods=["POST"])
@login_required
@admin_required
def set_site_settings():
    data = request.json or {}
    port_raw = data.get("port")
    port = None
    if port_raw not in (None, ""):
        try:
            port = int(port_raw)
        except (TypeError, ValueError):
            return jsonify(ok=False, error="رقم المنفذ (Port) لازم يكون رقم صحيح"), 400
        if not (1 <= port <= 65535):
            return jsonify(ok=False, error="رقم المنفذ لازم يكون بين 1 و65535"), 400
    domain = (data.get("domain") or "").strip()
    db_set_site_settings(port, domain)
    return jsonify(ok=True)


# ---------------------------------------------------------------- الفروع (حساب عام -> فروعه)

def generate_branch_password():
    return secrets.token_urlsafe(9)


@app.route("/branches", methods=["GET"])
@login_required
@general_required
def list_branches():
    rows = db_list_branches(session["user_id"])
    return jsonify([{"id": r["id"], "email": r["email"], "name": r["name"], "created_at": r["created_at"]} for r in rows])


@app.route("/branches", methods=["POST"])
@login_required
@general_required
def create_branch():
    data = request.json or {}
    name = (data.get("name") or "").strip()
    email = (data.get("email") or "").strip().lower()
    password = data.get("password") or ""
    if not name or not email:
        return jsonify(ok=False, error="عبّي اسم الفرع والبريد الإلكتروني"), 400
    if not EMAIL_RE.match(email):
        return jsonify(ok=False, error="أدخل بريد إلكتروني صحيح"), 400
    if db_get_user_by_email(email):
        return jsonify(ok=False, error="هذا البريد مسجّل من قبل"), 400
    if not password:
        password = generate_branch_password()
    elif len(password) < 6:
        return jsonify(ok=False, error="كلمة المرور لازم تكون 6 أحرف على الأقل"), 400
    branch_id = db_create_branch(email, generate_password_hash(password), name, session["user_id"])
    add_event(session["user_id"], None, "تم إنشاء فرع جديد", f"الفرع \"{name}\" جاهز لتسجيل الدخول بحسابه المستقل", kind="success")
    return jsonify(ok=True, id=branch_id, email=email, password=password, name=name)


@app.route("/branches/<int:branch_id>", methods=["DELETE"])
@login_required
@general_required
def delete_branch(branch_id):
    row = db_get_branch(branch_id, session["user_id"])
    if not row:
        return jsonify(ok=False, error="فرع غير موجود"), 404
    # ننظّف كل حسابات واتساب التابعة لهذا الفرع (يقفل متصفحاتها ويحذف جلساتها المحفوظة) قبل حذف حساب الفرع نفسه
    for wa in db_list_wa_accounts():
        if wa["owner"] != branch_id:
            continue
        acc = accounts.pop(wa["id"], None)
        if acc:
            acc["watching"] = False
            if acc["driver"] is not None:
                try:
                    acc["driver"].quit()
                except Exception:
                    pass
        shutil.rmtree(f"{SESSIONS_ROOT}/{wa['id']}", ignore_errors=True)
        db_delete_wa_account(wa["id"])
        db_delete_batches_for_account(wa["id"])
    db_delete_branch(branch_id, session["user_id"])
    return jsonify(ok=True)


@app.route("/admin/accounts")
@login_required
@admin_required
def admin_accounts():
    return jsonify([dict(u) for u in db_list_users()])


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
    db_delete_wa_account(acc_id)
    db_delete_batches_for_account(acc_id)
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
    link = request.form.get("link", "").strip()
    if link:
        text = f"{text}\n{link}"

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

    split_mode = request.form.get("split_mode", "none")
    if split_mode in ("count", "days"):
        try:
            split_value = int(request.form.get("split_value", 0))
            if split_value < 1:
                raise ValueError
        except (TypeError, ValueError):
            return jsonify(ok=False, error="أدخل رقم صحيح أكبر من صفر للتقسيم"), 400

        start_date_raw = request.form.get("start_date", "").strip()
        try:
            start_date = datetime.strptime(start_date_raw, "%Y-%m-%d").date() if start_date_raw else datetime.now().date()
        except ValueError:
            return jsonify(ok=False, error="تاريخ البداية غير صحيح"), 400

        send_time = request.form.get("send_time", "").strip() or DEFAULT_SPLIT_TIME
        if not re.match(r"^([01]\d|2[0-3]):[0-5]\d$", send_time):
            send_time = DEFAULT_SPLIT_TIME

        # "عدد لكل يوم": المستخدم يحدد كم رقم باليوم مباشرة. "عدد الأيام": نوزّع الأرقام
        # بالتساوي على هالعدد من الأيام (تقريب لأعلى، فيتجمّع الفرق باليوم الأخير)
        per_day = split_value if split_mode == "count" else -(-len(numbers) // split_value)
        chunks = [numbers[i:i + per_day] for i in range(0, len(numbers), per_day)]
        total_days = len(chunks)
        for i, chunk in enumerate(chunks):
            run_date = (start_date + timedelta(days=i)).strftime("%Y-%m-%d")
            db_create_batch(acc_id, acc["owner"], i + 1, total_days, chunk, text, media_path, delay, run_date, send_time)
        add_event(
            acc["owner"], acc["name"], "تمت جدولة حملة مقسّمة على أيام",
            f"{len(numbers)} رقم على {total_days} يوم (~{per_day} يومياً)، ابتداءً من {start_date.strftime('%Y-%m-%d')} الساعة {send_time}",
            kind="info",
        )
        return jsonify(ok=True, total=len(numbers), split=True, days=total_days, per_day=per_day, start_date=start_date.strftime("%Y-%m-%d"))

    # إرسال فوري، أو بموعد واحد (بدون تقسيم على أيام)
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

    return jsonify(ok=True, total=len(numbers), scheduled_for=acc["campaign"]["scheduled_for"], split=False)


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


@app.route("/accounts/<acc_id>/campaign_batches")
@login_required
def campaign_batches(acc_id):
    if not get_owned_account(acc_id):
        return jsonify([])
    rows = db_list_batches_for_account(acc_id)
    return jsonify([dict(r) for r in rows])


@app.route("/accounts/<acc_id>/campaign_batches/<int:batch_id>/cancel", methods=["POST"])
@login_required
def cancel_campaign_batch(acc_id, batch_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(ok=False, error="حساب غير موجود"), 404
    ok = db_cancel_batch(batch_id, acc["owner"])
    return jsonify(ok=ok, error=None if ok else "ما قدرنا نلغي هذه الدفعة (يمكن صارت شغالة أو خلصت)")


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
        acc["auto_reply"]["enabled"] = enabled
        if enabled and not was_enabled:
            acc["watching"] = True
            threading.Thread(target=watch_account, args=(acc_id,), daemon=True).start()
        elif not enabled:
            acc["watching"] = False
    db_update_wa_account_auto_reply(acc_id, acc["auto_reply"]["enabled"], acc["auto_reply"]["ai_enabled"], acc["auto_reply"]["rules"])
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
<link rel="preconnect" href="https://cdnjs.cloudflare.com">
<link rel="preconnect" href="https://cdn.tailwindcss.com">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root {
    --primary: #006b73; --primary-light: #0A8A94; --primary-dark: #004b52;
    --primary-gradient: linear-gradient(135deg, #006b73 0%, #0A8A94 100%);
    --accent: #c99a3d;
    --bg: #F0F4F8; --bg-card: #FFFFFF; --card-soft: #EEF2F5; --card-3: #E4E9EE;
    --border: rgba(0,107,115,.08); --border-2: rgba(0,107,115,.16);
    --text-primary: #1A2E35; --text-secondary: #4A6A78; --text-muted: #8AA0B0;
    --gold-light: rgba(0,107,115,.08); --gold-border: rgba(0,107,115,.20); --gold-shadow: rgba(0,107,115,.25);
    --green-ink: #ffffff; --red: #EF4444; --blue: #3B82F6; --amber: #D97706;
    --shadow: rgba(0,107,115,.08);
    --shadow-sm: 0 2px 8px rgba(0,107,115,0.04);
    --shadow-md: 0 4px 20px rgba(0,107,115,0.06);
    --shadow-lg: 0 8px 40px rgba(0,107,115,0.08);
    --shadow-xl: 0 12px 56px rgba(0,107,115,0.10);
    --radius-sm: 8px; --radius-md: 14px; --radius-lg: 20px; --radius-full: 9999px;
    --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --sidebar-width: 260px;
  }
  :root[data-theme="dark"] {
    --primary: #0A8A94; --primary-light: #0A8A94; --primary-dark: #006b73;
    --primary-gradient: linear-gradient(135deg, #006b73 0%, #0A8A94 100%);
    --bg: #0D1117; --bg-card: #161B22; --card-soft: #1C232C; --card-3: #262E38;
    --border: rgba(255,255,255,.06); --border-2: rgba(255,255,255,.12);
    --text-primary: #F0F6FC; --text-secondary: #8B949E; --text-muted: #6A8098;
    --gold-light: rgba(10,138,148,.12); --gold-border: rgba(10,138,148,.28); --gold-shadow: rgba(10,138,148,.3);
    --green-ink: #ffffff; --red: #EF4444; --blue: #3B82F6; --amber: #D97706;
    --shadow: rgba(0,0,0,.4);
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-xl: 0 12px 56px rgba(0,0,0,0.6);
  }
  html, body { margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text-primary); font-family: 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal', system-ui, sans-serif;
    min-height: 100vh; display: flex; transition: background var(--transition-base), color var(--transition-base); animation: pageFadeIn .18s ease; }
  @keyframes pageFadeIn { from { opacity: 0; } to { opacity: 1; } }
  h1, h2, h3, .stat-num { font-family: 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal', sans-serif; }
  .icon { display: inline-block; vertical-align: middle; flex-shrink: 0; }

  /* ---------- الشريط الجانبي (سطح المكتب) ---------- */
  .sidebar {
    width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, #006b73 0%, #004b52 100%);
    box-shadow: 2px 0 20px rgba(0,0,0,0.08); position: fixed; right: 0; top: 0; z-index: 100;
    display: flex; flex-direction: column; padding: 20px 16px; overflow-y: auto; transition: var(--transition-base);
  }
  .sidebar .brand { display: flex; align-items: center; gap: 12px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 16px; }
  .sidebar .brand .logo {
    width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(255,255,255,0.14);
    display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; font-weight: 900; flex-shrink: 0;
  }
  .sidebar .brand .name { font-size: 18px; font-weight: 900; color: #fff; }
  .sidebar .brand .name span { color: #c9d18f; }

  .sidebar .nav-menu { flex: 1; display: flex; flex-direction: column; gap: 2px; }
  .sidebar .nav-item {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: var(--radius-md);
    cursor: pointer; transition: var(--transition-base); color: rgba(255,255,255,0.75); font-weight: 600; font-size: 13px;
    border: none; background: transparent; width: 100%; font-family: inherit; text-align: right;
  }
  .sidebar .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
  .sidebar .nav-item.active { background: rgba(255,255,255,0.16); color: #fff; }
  .sidebar .nav-item i { width: 20px; font-size: 16px; text-align: center; flex-shrink: 0; }

  .sidebar .nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 8px 0; }

  .sidebar .user-info { padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
  .sidebar .user-info .avatar {
    width: 44px; height: 44px; border-radius: var(--radius-full); background: rgba(255,255,255,0.14);
    display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; font-weight: 900; flex-shrink: 0;
  }
  .sidebar .user-info .info { min-width: 0; }
  .sidebar .user-info .info .name { font-size: 14px; font-weight: 800; color: #fff; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .sidebar .user-info .info .role { font-size: 11px; color: rgba(255,255,255,0.6); font-weight: 400; }
  .sidebar .user-info .logout-btn {
    margin-right: auto; background: none; border: none; color: rgba(255,255,255,0.6); cursor: pointer;
    font-size: 18px; transition: var(--transition-base); padding: 4px 8px; border-radius: var(--radius-full); flex-shrink: 0;
  }
  .sidebar .user-info .logout-btn:hover { color: #EF4444; background: rgba(239,68,68,0.08); }

  .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 99; }
  .sidebar-overlay.show { display: block; }
  .mobile-menu-toggle { display: none; background: none; border: none; font-size: 22px; color: var(--text-primary); cursor: pointer; padding: 4px 8px; }

  /* ---------- المحتوى الرئيسي ---------- */
  .main-content { flex: 1; margin-right: var(--sidebar-width); min-height: 100vh; display: flex; flex-direction: column; }
  .top-header {
    display: flex; align-items: center; justify-content: space-between; padding: 14px 24px;
    position: sticky; top: 0; z-index: 90; background: rgba(240,244,248,0.85); backdrop-filter: blur(20px) saturate(180%); -webkit-backdrop-filter: blur(20px) saturate(180%);
    border-bottom: 1px solid rgba(0,107,115,0.04); box-shadow: 0 2px 20px rgba(0,0,0,0.03); transition: var(--transition-base);
  }
  :root[data-theme="dark"] .top-header { background: rgba(13,17,23,0.85); border-bottom: 1px solid rgba(255,255,255,0.06); }
  .top-header .header-actions { display: flex; align-items: center; gap: 6px; margin-right: auto; }
  .icon-btn {
    width: 42px; height: 42px; border-radius: var(--radius-full); border: 1px solid rgba(0,0,0,0.04);
    background: rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: center;
    color: var(--text-secondary); cursor: pointer; font-size: 18px; position: relative; transition: var(--transition-base);
  }
  :root[data-theme="dark"] .icon-btn { background: rgba(255,255,255,0.05); color: #8B949E; border-color: rgba(255,255,255,0.06); }
  .icon-btn:hover { background: rgba(0,0,0,0.06); color: var(--primary); }
  .icon-btn .badge {
    position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
    background: var(--red); border-radius: var(--radius-full); border: 2px solid rgba(255,255,255,0.8);
    box-shadow: 0 0 12px rgba(239,68,68,0.4); animation: pulse-dot 2s ease-in-out infinite;
  }
  @keyframes pulse-dot { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.4); opacity: 0.6; } }

  .notif-item-full {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 12px; margin-bottom: 8px;
    background: var(--bg-card); border: 1px solid rgba(0,107,115,0.04); transition: var(--transition-base);
  }
  .notif-item-full:hover { border-color: rgba(0,107,115,0.12); box-shadow: var(--shadow-sm); }
  .notif-item-full .notif-icon {
    width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0; background: rgba(0,107,115,0.08); color: var(--primary);
  }
  .notif-item-full .notif-icon.success { background: rgba(16,185,129,0.12); color: #059669; }
  .notif-item-full .notif-icon.warning { background: rgba(251,191,36,0.12); color: #D97706; }
  .notif-item-full .notif-content { flex: 1; }
  .notif-item-full .notif-content .notif-title { font-size: 13px; font-weight: 700; color: var(--text-primary); }
  .notif-item-full .notif-content .notif-message { font-size: 11px; color: var(--text-muted); line-height: 1.5; }
  .notif-item-full .notif-time { font-size: 10px; color: var(--text-muted); flex-shrink: 0; background: rgba(0,107,115,0.04); padding: 2px 10px; border-radius: var(--radius-full); }
  .notif-item-full .notif-actions { display: flex; gap: 6px; flex-shrink: 0; }
  .notif-item-full .notif-actions button { width: 32px; height: 32px; border: none; border-radius: 50%; background: transparent; color: var(--text-muted); cursor: pointer; transition: var(--transition-base); display: flex; align-items: center; justify-content: center; font-size: 13px; }
  .notif-item-full .notif-actions .btn-read:hover { background: rgba(0,107,115,0.06); color: var(--primary); }
  .notif-item-full .notif-actions .btn-delete:hover { background: rgba(239,68,68,0.06); color: #EF4444; }

  #pageContent { flex: 1; padding: 24px 32px 40px; display: flex; justify-content: center; }
  .content-inner { width: 100%; max-width: 880px; }
  .content-inner.fade-in { animation: contentFadeIn .15s ease; }
  @keyframes contentFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

  @media (max-width: 768px) {
    :root { --sidebar-width: 0px; }
    .sidebar { transform: translateX(100%); width: 280px; }
    .sidebar.open { transform: translateX(0); }
    .main-content { margin-right: 0; }
    .mobile-menu-toggle { display: flex !important; }
    #pageContent { padding: 16px; }
  }

  .page-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
  .page-title h2 { font-size: 20px; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
  @media (max-width: 480px) { .page-title h2 { font-size: 18px; } }

  .card {
    background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid rgba(0,107,115,0.04);
    box-shadow: var(--shadow-sm); padding: 20px; margin-bottom: 14px; transition: var(--transition-base);
  }
  .card:hover { box-shadow: var(--shadow-md); border-color: rgba(0,107,115,0.06); }
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
    width: 100%; box-sizing: border-box; padding: 14px 16px; font-size: 15.5px; font-family: inherit;
    background: var(--bg); border: 2px solid rgba(0,107,115,0.08); border-radius: var(--radius-sm); color: var(--text-primary);
    transition: var(--transition-base); outline: none;
  }
  input:focus, textarea:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(0,107,115,0.06); }
  textarea { resize: vertical; min-height: 80px; }
  input[type="file"] { padding: 9px 12px; }
  input[type="checkbox"] { width: auto; }

  .btn-submit {
    width: 100%; height: 48px; border: none; border-radius: var(--radius-md);
    background: var(--primary-gradient); color: #fff; font-size: 15px; font-weight: 700;
    cursor: pointer; transition: var(--transition-base); font-family: inherit;
    box-shadow: 0 4px 16px rgba(0,107,115,0.25); margin-top: 8px;
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
  }
  .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,107,115,0.35); }
  .btn-submit:active { transform: scale(0.97); }
  .btn-outline {
    display: block; width: 100%; padding: 10px; margin-top: 6px; border-radius: 11px;
    background: var(--bg-card); border: 1.5px solid rgba(0,107,115,0.15); color: var(--text-secondary);
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
  .confirm-sheet .btn-cancel { background: rgba(0,107,115,0.06); color: var(--text-secondary); }
  .confirm-sheet .btn-cancel:hover { background: rgba(0,107,115,0.1); transform: translateY(-2px); }
  .confirm-sheet .btn-confirm { background: linear-gradient(135deg, #EF4444, #DC2626); color: #fff; box-shadow: 0 4px 16px rgba(239,68,68,0.25); }
  .confirm-sheet .btn-confirm-gold { background: var(--primary-gradient); color: #fff; box-shadow: 0 4px 16px rgba(0,107,115,0.25); }
  .confirm-sheet .btn-confirm-gold:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,107,115,0.35); }
  .confirm-sheet .btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(239,68,68,0.35); }

  #qrImg { width: 220px; height: 220px; border-radius: 16px; border: 1px solid var(--border-2); background: #fff; display: block; margin: 0 auto; }

  .stat-row { display: flex; border-radius: 12px; overflow: hidden; margin-top: 10px; }
  .stat-cell { flex: 1; text-align: center; padding: 8px 4px; background: var(--bg-card); border: 1px solid rgba(0,107,115,0.04); }
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
  .pill-gold { background: rgba(201,154,61,0.14); color: #c99a3d; border: 1px solid rgba(201,154,61,0.25); }

  .history-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 4px; border-bottom: 1px solid rgba(0,107,115,0.04); font-size: 11px; }
  .history-row:last-child { border-bottom: none; }
  .history-row.clickable { cursor: pointer; }
  .history-row .campaign-name { font-weight: 700; color: var(--text-primary); display: block; }
  .history-row .campaign-detail { font-size: 10px; color: var(--text-muted); }
  .history-row .see-detail { color: var(--primary); font-weight: 700; margin-right: 8px; }

  .campaign-detail-card { background: var(--bg-card); border: 1px solid rgba(0,107,115,0.04); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); padding: 20px; margin-bottom: 14px; transition: var(--transition-base); }
  .campaign-detail-card:hover { box-shadow: var(--shadow-md); border-color: rgba(0,107,115,0.06); }
  .campaign-detail-card .detail-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(0,107,115,0.04); font-size: 13px; gap: 10px; }
  .campaign-detail-card .detail-row:last-child { border-bottom: none; }
  .campaign-detail-card .detail-row .label { color: var(--text-muted); font-weight: 500; flex-shrink: 0; }
  .campaign-detail-card .detail-row .value { font-weight: 700; color: var(--text-primary); text-align: left; }

  .hero-main {
    background: linear-gradient(145deg, var(--bg-card), var(--bg-card)); border: 1.5px solid rgba(0,107,115,0.15);
    border-radius: 20px; padding: 24px 20px; position: relative; overflow: hidden; text-align: center; margin-bottom: 20px;
    box-shadow: 0 0 40px rgba(0,107,115,0.04), inset 0 1px 0 rgba(0,107,115,0.05);
  }
  .hero-main::before {
    content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
    background: conic-gradient(from 0deg at 50% 50%, transparent 0%, rgba(0,107,115,0.04) 20%, transparent 40%, rgba(0,107,115,0.04) 60%, transparent 80%, rgba(0,107,115,0.04) 100%);
    animation: shimmerRotate 12s linear infinite; pointer-events: none;
  }
  .hero-main::after { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at 30% 20%, rgba(0,107,115,0.03), transparent 60%); pointer-events: none; opacity: 0.5; }
  .hero-main .relative-z { position: relative; z-index: 1; }
  .hero-main .hero-icon-main { width: 64px; height: 64px; margin: 0 auto 10px; border-radius: 50%; background: rgba(0,107,115,0.06); border: 1.5px solid rgba(0,107,115,0.15); display: flex; align-items: center; justify-content: center; font-size: 28px; color: var(--primary); }
  .hero-main h1 { font-size: 22px; font-weight: 900; color: var(--primary); margin-bottom: 4px; }
  .hero-main p { font-size: 12px; color: var(--text-muted); line-height: 1.7; margin: 0 auto; max-width: 400px; }
  .hero-main .hero-badges { display: flex; justify-content: center; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
  .hero-main .hero-badges span { font-size: 11px; font-weight: 700; color: var(--primary); background: rgba(0,107,115,0.06); padding: 4px 14px; border-radius: 20px; border: 1px solid rgba(0,107,115,0.08); }

  .stats-mini-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
  .stat-mini-box { background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid rgba(0,107,115,0.04); box-shadow: var(--shadow-sm); padding: 10px 4px; text-align: center; transition: var(--transition-base); }
  .stat-mini-box:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
  .stat-mini-box .num { font-size: 18px; font-weight: 900; color: var(--text-primary); line-height: 1.2; }
  .stat-mini-box .num.green { color: #059669; }
  .stat-mini-box .num.orange { color: #D97706; }
  .stat-mini-box .num.primary { color: var(--primary); }
  .stat-mini-box .label { font-size: 9px; color: var(--text-muted); margin-top: 2px; font-weight: 500; }

  .quick-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
  .quick-action-btn { padding: 14px 8px; border-radius: var(--radius-md); border: 2px solid rgba(0,107,115,0.08); background: var(--bg-card); text-align: center; cursor: pointer; transition: var(--transition-base); font-weight: 700; font-size: 12px; color: var(--text-primary); font-family: inherit; }
  .quick-action-btn:hover { border-color: var(--primary); background: rgba(0,107,115,0.02); transform: translateY(-2px); box-shadow: var(--shadow-md); }
  .quick-action-btn:active { transform: scale(0.95); }
  .quick-action-btn i { font-size: 24px; display: block; margin-bottom: 4px; color: var(--primary); }

  .account-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid rgba(0,107,115,0.04); border-radius: 12px; margin-bottom: 8px; transition: var(--transition-base); }
  .account-item:hover { box-shadow: var(--shadow-sm); border-color: rgba(0,107,115,0.08); }
  .account-item .info { flex: 1; min-width: 0; }
  .account-item .info .name { font-size: 13px; font-weight: 700; color: var(--text-primary); }
  .account-item .info .status { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

  .profile-card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid rgba(0,107,115,0.04); box-shadow: var(--shadow-sm); padding: 20px; margin-bottom: 14px; display: flex; align-items: center; gap: 16px; transition: var(--transition-base); }
  .profile-card .avatar { width: 64px; height: 64px; font-size: 28px; box-shadow: 0 4px 16px rgba(0,107,115,0.2); }
  .profile-card .info { flex: 1; }
  .profile-card .info .name { font-size: 18px; font-weight: 800; color: var(--text-primary); }
  .profile-card .info .role { font-size: 12px; font-weight: 600; color: var(--primary); background: rgba(0,107,115,0.06); padding: 2px 12px; border-radius: var(--radius-full); display: inline-block; margin-top: 2px; }

  .settings-list { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid rgba(0,107,115,0.04); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 14px; }
  .settings-head { padding: 14px 16px; background: rgba(0,107,115,0.04); border-bottom: 1px solid rgba(0,107,115,0.04); font-size: 13px; font-weight: 700; color: var(--text-secondary); display: flex; align-items: center; gap: 10px; }
  .settings-item { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid rgba(0,107,115,0.04); cursor: pointer; transition: var(--transition-base); }
  .settings-item:last-child { border-bottom: none; }
  .settings-item:hover { background: rgba(0,107,115,0.02); }
  .settings-item .left { display: flex; align-items: center; gap: 14px; }
  .settings-item .icon-wrap { width: 36px; height: 36px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
  .settings-item .icon-wrap.primary { background: rgba(0,107,115,0.08); color: var(--primary); }
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
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="logo">و</div>
    <div class="name">منصة <span>واصل</span></div>
  </div>
  <nav class="nav-menu" id="navMenu"></nav>
  <div class="user-info">
    <div class="avatar" id="sidebarAvatar">؟</div>
    <div class="info">
      <div class="name" id="sidebarUserName"></div>
      <div class="role" id="sidebarUserRole"></div>
    </div>
    <button class="logout-btn" onclick="logoutPlatform()" title="تسجيل الخروج"><i class="fas fa-right-from-bracket"></i></button>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

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

<main class="main-content">
  <header class="top-header">
    <button class="mobile-menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div class="header-actions">
      <button class="icon-btn" id="themeBtn" onclick="toggleTheme()" title="المظهر"></button>
      <button class="icon-btn" id="bellBtn" onclick="openNotifications()" title="الإشعارات">
        <i class="fas fa-bell"></i>
        <span class="badge" id="bellBadge" style="display:none"></span>
      </button>
    </div>
  </header>
  <div id="pageContent"><div class="content-inner" id="content"></div></div>
</main>

<script>
const IS_ADMIN = __IS_ADMIN__;
const IS_GENERAL = __IS_GENERAL__;

/* ---------- أيقونات خطية (بدون إيموجي) ---------- */
const ICONS = {
  home: 'fas fa-house', accounts: 'fas fa-phone', campaigns: 'fas fa-bullhorn', autoreply: 'fas fa-comment-dots',
  analytics: 'fas fa-chart-column', admin: 'fas fa-shield-halved', settings: 'fas fa-gear',
  bell: 'fas fa-bell', sun: 'fas fa-sun', moon: 'fas fa-moon', plus: 'fas fa-plus', whatsapp: 'fab fa-whatsapp',
  logout: 'fas fa-right-from-bracket', ai: 'fas fa-robot', users: 'fas fa-users', branches: 'fas fa-code-branch',
  rocket: 'fas fa-rocket', trophy: 'fas fa-trophy', list: 'fas fa-list', info: 'fas fa-circle-info', clock: 'fas fa-clock',
  check: 'fas fa-check', checkCircle: 'fas fa-circle-check', warn: 'fas fa-triangle-exclamation', trash: 'fas fa-trash',
  link: 'fas fa-link', calendar: 'fas fa-calendar-days', ban: 'fas fa-ban', copy: 'fas fa-copy',
};
function icon(name, size) {
  size = size || 20;
  return '<i class="icon ' + (ICONS[name] || 'fas fa-circle') + '" style="font-size:' + size + 'px; width:' + size + 'px; display:inline-flex; align-items:center; justify-content:center;"></i>';
}

const CURRENT_USER = "__USERNAME__";
const SECTION_LABELS = { home: 'الرئيسية', accounts: 'حسابي', campaigns: 'الحملات', autoreply: 'الرد الآلي', analytics: 'إحصائيات', admin: 'لوحة التحكم', branches: 'الفروع', settings: 'الإعدادات' };

function initChrome() {
  const items = ['home', 'accounts', 'campaigns', 'autoreply', 'analytics'];
  if (IS_GENERAL) items.push('branches');
  if (IS_ADMIN) items.push('admin');
  let navHtml = items.map(function (s) {
    return '<button class="nav-item" data-s="' + s + '" onclick="showSection(\\'' + s + '\\')">' + icon(s, 16) + '<span>' + SECTION_LABELS[s] + '</span></button>';
  }).join('');
  navHtml += '<div class="nav-divider"></div>';
  navHtml += '<button class="nav-item" data-s="settings" onclick="showSection(\\'settings\\')">' + icon('settings', 16) + '<span>' + SECTION_LABELS.settings + '</span></button>';
  document.getElementById('navMenu').innerHTML = navHtml;

  const displayName = CURRENT_USER.split('@')[0] || CURRENT_USER;
  document.getElementById('sidebarAvatar').textContent = (CURRENT_USER || '؟').trim().charAt(0).toUpperCase();
  document.getElementById('sidebarUserName').textContent = displayName;
  document.getElementById('sidebarUserRole').textContent = IS_ADMIN ? 'أدمن المنصة' : (IS_GENERAL ? 'حساب عام' : 'حساب فرع');
}
initChrome();

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

let accounts = [];
let section = 'home';
let activeId = null;
let gen = 0;
let lastSeenEventId = -1;
let campaignHistory = [];
let campaignHistoryAccName = '';
let dataCache = {};

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
  const cached = window._events;
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('bell', 18) + ' الإشعارات</h2>' +
      '<div style="display:flex;gap:6px">' +
        '<button class="btn-outline" style="width:auto;padding:6px 12px;font-size:11px;margin:0" onclick="markAllEventsRead()">' + icon('check', 10) + ' قراءة الكل</button>' +
        '<button class="btn-outline" style="width:auto;padding:6px 12px;font-size:11px;margin:0" onclick="closeNotifications()">رجوع</button>' +
      '</div>' +
    '</div>' +
    '<div id="notifList">' + (cached ? '' : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>';
  fadeContent();
  if (cached) renderNotifList(cached);
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

/* ---------- التنقل بين الأقسام ---------- */
function showSection(s) {
  window._notifOpen = false;
  section = s;
  document.querySelectorAll('.sidebar .nav-menu .nav-item').forEach(n => n.classList.toggle('active', n.dataset.s === s));
  closeSidebar();
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

function renderActiveSection(myGen) {
  if (section === 'home') renderHome();
  else if (section === 'accounts') renderAccounts();
  else if (section === 'campaigns') renderCampaigns(myGen);
  else if (section === 'analytics') renderAnalytics();
  else if (section === 'autoreply') renderAutoReply();
  else if (section === 'admin') renderAdmin();
  else if (section === 'branches') renderBranches();
  else renderSettings();
}

async function render() {
  gen++;
  const myGen = gen;
  const hasCache = accounts.length > 0;
  const loadPromise = loadAccounts();
  if (!hasCache) {
    await loadPromise;
    if (myGen !== gen) return;
  }
  renderActiveSection(myGen);
  fadeContent();
  if (hasCache) {
    loadPromise.then(function() {
      if (myGen !== gen) return;
      renderActiveSection(myGen);
    });
  }
}

/* ---------- قسم الرئيسية ---------- */
function statMiniHtml(value, label, colorClass) {
  return '<div class="stat-mini-box"><div class="num' + (colorClass ? ' ' + colorClass : '') + '">' + value + '</div><div class="label">' + label + '</div></div>';
}

function homeStatsHtml(d) {
  return statMiniHtml(d.messages_sent.toLocaleString(), 'رسائل مرسلة', 'primary') +
    statMiniHtml(d.accounts_connected + '/' + d.accounts_total, 'حسابات متصلة', 'green') +
    statMiniHtml(d.campaigns_total, 'إجمالي الحملات', 'orange') +
    statMiniHtml(d.success_rate + '%', 'معدل النجاح', 'primary');
}

function homeHistoryHtml(rows) {
  return rows.length ? historyRowsHtml(rows.slice(0, 3)) : '<div class="text-muted text-[11px]">ما فيه حملات سابقة</div>';
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
    '<div class="stats-mini-row" id="homeStats">' + (dataCache.dashboardStats ? homeStatsHtml(dataCache.dashboardStats) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>' +
    '<div class="quick-actions">' +
      '<button class="quick-action-btn" onclick="showSection(\\'campaigns\\')">' + icon('campaigns', 20) + 'حملات</button>' +
      '<button class="quick-action-btn" onclick="showSection(\\'analytics\\')">' + icon('analytics', 20) + 'إحصائيات</button>' +
      '<button class="quick-action-btn" onclick="showSection(\\'accounts\\')">' + icon('accounts', 20) + 'حساباتي</button>' +
      '<button class="quick-action-btn" onclick="showSection(\\'' + (IS_GENERAL ? 'branches' : 'settings') + '\\')">' + icon(IS_GENERAL ? 'branches' : 'settings', 20) + (IS_GENERAL ? 'الفروع' : 'الإعدادات') + '</button>' +
    '</div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('accounts', 14) + ' حساباتي</h4><button class="btn-outline" style="width:auto;padding:4px 12px;font-size:10px;margin:0" onclick="openAddAccountSheet()">' + icon('plus', 10) + ' إضافة</button></div>' +
      '<div id="homeAccounts"></div>' +
    '</div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('clock', 14) + ' آخر الحملات</h4><span style="font-size:11px;color:var(--text-muted);cursor:pointer" onclick="showSection(\\'campaigns\\')">عرض الكل</span></div>' +
      '<div id="homeHistory">' + (dataCache.dashboardCampaigns ? homeHistoryHtml(dataCache.dashboardCampaigns) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>' +
    '</div>';
  fetch('/dashboard/stats').then(r => r.json()).then(d => {
    dataCache.dashboardStats = d;
    const el = document.getElementById('homeStats');
    if (el) el.innerHTML = homeStatsHtml(d);
  });
  fetch('/dashboard/campaigns').then(r => r.json()).then(rows => {
    campaignHistory = rows;
    campaignHistoryAccName = '';
    dataCache.dashboardCampaigns = rows;
    const el = document.getElementById('homeHistory');
    if (el) el.innerHTML = homeHistoryHtml(rows);
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
    '<label class="field-label">نص الرسالة</label>' +
    '<textarea id="text" rows="2" placeholder="اكتب نص رسالتك هنا">هلوو</textarea>' +
    '<label class="field-label">' + icon('link', 11) + ' رابط (اختياري، يُضاف بآخر الرسالة)</label>' +
    '<input id="link" type="url" placeholder="https://example.com" dir="ltr">' +
    '<label class="field-label">صورة، فيديو، أو ملف (اختياري)</label>' +
    '<input type="file" id="mediaFile" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.zip">' +
    '<label class="field-label">الفاصل الزمني بين كل رسالة وأخرى (بالثواني)</label>' +
    '<input id="delay" type="number" min="1" value="15">' +
    '<label class="field-label">' + icon('calendar', 11) + ' طريقة الإرسال</label>' +
    '<select id="splitMode" onchange="onSplitModeChange()">' +
      '<option value="none">فوري، أو بموعد واحد</option>' +
      '<option value="count">تقسيم على أيام — عدد ثابت من الأرقام كل يوم</option>' +
      '<option value="days">تقسيم على أيام — وزّع الأرقام على عدد أيام محدد</option>' +
    '</select>' +
    '<div id="singleScheduleBox">' +
      '<label class="field-label">جدولة الإرسال (اختياري — اتركه فاضي للإرسال الفوري)</label>' +
      '<input id="sendAt" type="datetime-local">' +
    '</div>' +
    '<div id="splitBox" style="display:none">' +
      '<label class="field-label" id="splitValueLabel">عدد الأرقام باليوم الواحد</label>' +
      '<input id="splitValue" type="number" min="1" placeholder="مثال: 20">' +
      '<label class="field-label">تاريخ بداية الإرسال</label>' +
      '<input id="startDate" type="date">' +
      '<label class="field-label">وقت الإرسال اليومي</label>' +
      '<input id="sendTime" type="time" value="10:00">' +
      '<p class="text-[11px] text-muted mt-1">مثال: 80 رقم بوضع "عدد ثابت كل يوم" = 20، بترسل 20 يومياً على مدى 4 أيام تلقائياً.</p>' +
    '</div>' +
    '<button class="btn-submit" onclick="startCampaign()">' + icon('rocket', 14) + ' بدء الإرسال</button>' +
    '</div>' +
    '<div class="stat-row" id="statRow" style="display:none">' +
    '<div class="stat-cell"><div class="stat-num text-gold" id="statSent">0</div><div class="stat-label">تم الإرسال</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-red" id="statFailed">0</div><div class="stat-label">فشل</div></div>' +
    '<div class="stat-cell"><div class="stat-num text-gold" id="statTotal">0</div><div class="stat-label">الإجمالي</div></div>' +
    '</div>' +
    '<div id="msg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '<div class="card" id="batchesCard" style="display:none">' +
    '<div class="card-header"><h4>' + icon('calendar', 14) + ' جدول الحملة المقسّمة على أيام</h4></div>' +
    '<div id="batchesBox"></div>' +
    '</div>' +
    '<h3 style="font-size:12px;font-weight:800;color:var(--primary);margin:18px 0 8px">جميع الحملات</h3>';
  const cachedHistory = dataCache['campaigns_' + acc.id];
  html += '<div id="historyBox">' + (cachedHistory ? historyRowsHtml(cachedHistory, true) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>';

  c.innerHTML = html;
  if (cachedHistory) { campaignHistory = cachedHistory; campaignHistoryAccName = acc.name; }

  if ('contacts' in navigator && 'ContactsManager' in window) {
    document.getElementById('contactPickerBtn').style.display = 'block';
  }

  document.getElementById('startDate').value = new Date().toISOString().slice(0, 10);
  loadHistory(acc.id);
  loadBatches(acc.id);
  refreshCampaignState(acc.id, myGen);
}

function onSplitModeChange() {
  const mode = document.getElementById('splitMode').value;
  document.getElementById('singleScheduleBox').style.display = mode === 'none' ? 'block' : 'none';
  document.getElementById('splitBox').style.display = mode === 'none' ? 'none' : 'block';
  document.getElementById('splitValueLabel').textContent = mode === 'days' ? 'عدد الأيام المطلوب التوزيع عليها' : 'عدد الأرقام باليوم الواحد';
}

function switchCampaignAccount(id) { activeId = id; render(); }

const BATCH_STATUS_LABEL = { pending: 'بانتظار موعده', running: 'شغّال الآن', done: 'اكتمل', cancelled: 'ملغى' };
const BATCH_STATUS_PILL = { pending: 'pill-amber', running: 'pill-blue', done: 'pill-green', cancelled: 'pill-gray' };

function batchesHtml(rows, accId) {
  if (!rows.length) return '';
  return rows.map(b =>
    '<div class="history-row"><div><span class="campaign-name">اليوم ' + b.day_index + '/' + b.total_days + ' — ' + b.total + ' رقم</span>' +
    '<div class="campaign-detail">' + b.run_date + ' الساعة ' + b.run_time +
    (b.status === 'done' ? ' — نجح ' + b.sent + '، فشل ' + b.failed : '') + '</div></div>' +
    '<div style="text-align:left;display:flex;align-items:center;gap:6px">' +
    '<span class="pill ' + (BATCH_STATUS_PILL[b.status] || 'pill-gray') + '">' + (BATCH_STATUS_LABEL[b.status] || b.status) + '</span>' +
    (b.status === 'pending' ? '<button class="btn-outline btn-small" style="color:#dc2626;border-color:rgba(220,38,38,.3)" onclick="cancelBatch(' + b.id + ', \\'' + accId + '\\')">' + icon('ban', 10) + ' إلغاء</button>' : '') +
    '</div></div>'
  ).join('');
}

async function loadBatches(accId) {
  const rows = await fetch('/accounts/' + accId + '/campaign_batches').then(r => r.json());
  const card = document.getElementById('batchesCard');
  const box = document.getElementById('batchesBox');
  if (!card || !box) return;
  if (!rows.length) { card.style.display = 'none'; return; }
  card.style.display = 'block';
  box.innerHTML = batchesHtml(rows, accId);
}

async function cancelBatch(batchId, accId) {
  if (!confirm('إلغاء دفعة هذا اليوم من الحملة المجدولة؟')) return;
  await fetch('/accounts/' + accId + '/campaign_batches/' + batchId + '/cancel', { method: 'POST' });
  loadBatches(accId);
}

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
  dataCache['campaigns_' + accId] = rows;
  campaignHistory = rows;
  const acc = accounts.find(a => a.id === accId);
  campaignHistoryAccName = acc ? acc.name : '';
  const box = document.getElementById('historyBox');
  if (box) box.innerHTML = historyRowsHtml(rows, true);
}

function showCampaignDetail(i) {
  const h = campaignHistory[i];
  if (!h) return;
  const c = document.getElementById('content');
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('info', 18) + ' تفاصيل الحملة</h2>' +
      '<button onclick="render()" style="height:36px;padding:0 14px;border:2px solid rgba(0,107,115,0.08);border-radius:var(--radius-sm);background:transparent;color:var(--text-secondary);font-size:12px;font-weight:700;cursor:pointer;font-family:inherit">رجوع</button>' +
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
  form.append('link', document.getElementById('link').value.trim());
  form.append('delay', document.getElementById('delay').value || 15);
  const splitMode = document.getElementById('splitMode').value;
  form.append('split_mode', splitMode);
  if (splitMode === 'none') {
    form.append('send_at', document.getElementById('sendAt').value || '');
  } else {
    form.append('split_value', document.getElementById('splitValue').value || '');
    form.append('start_date', document.getElementById('startDate').value || '');
    form.append('send_time', document.getElementById('sendTime').value || '');
  }
  const numbersFile = document.getElementById('numbersFile').files[0];
  if (numbersFile) form.append('numbers_file', numbersFile);
  const mediaFile = document.getElementById('mediaFile').files[0];
  if (mediaFile) form.append('media_file', mediaFile);

  ensureNotifPermission();
  document.getElementById('msg').innerText = 'جارِ البدء...';
  const r = await fetch('/accounts/' + accId + '/campaign', { method: 'POST', body: form }).then(res => res.json());
  if (!r.ok) { document.getElementById('msg').innerText = 'فشل: ' + r.error; return; }
  if (r.split) {
    document.getElementById('msg').innerText = 'تمت الجدولة: ' + r.total + ' رقم على ' + r.days + ' يوم، ابتداءً من ' + r.start_date;
    loadBatches(accId);
    return;
  }
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
    loadBatches(accId);
  }
}

/* ---------- قسم الإحصائيات ---------- */
function analyticsStatsHtml(stats) {
  return statMiniHtml(stats.messages_sent.toLocaleString(), 'إجمالي الرسائل', 'primary') +
    statMiniHtml(stats.success_rate + '%', 'معدل النجاح', 'green') +
    statMiniHtml(stats.accounts_total, 'الحسابات', 'orange');
}

function analyticsPerfHtml(stats) {
  const avgRate = stats.campaigns_total ? Math.round((stats.campaigns_success / stats.campaigns_total) * 100) : 0;
  return '<div class="history-row"><span>إجمالي الحملات</span><span style="font-weight:700">' + stats.campaigns_total + '</span></div>' +
    '<div class="history-row"><span>الحملات الناجحة (بدون أي فشل)</span><span class="pill pill-green">' + stats.campaigns_success + '</span></div>' +
    '<div class="history-row"><span>حملات فيها إخفاقات</span><span class="pill pill-red">' + stats.campaigns_with_failures + '</span></div>' +
    '<div class="history-row"><span>معدل نجاح الحملات</span><span class="pill pill-green">' + avgRate + '%</span></div>';
}

async function renderAnalytics() {
  const c = document.getElementById('content');
  const cs = dataCache.dashboardStats, cr = dataCache.dashboardCampaigns;
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('analytics', 18) + ' الإحصائيات</h2></div>' +
    '<div class="stats-mini-row" id="analyticsStats" style="grid-template-columns:repeat(3,1fr)">' + (cs ? analyticsStatsHtml(cs) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('trophy', 14) + ' أداء الحملات</h4></div>' +
      '<div id="analyticsPerf">' + (cs ? analyticsPerfHtml(cs) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>' +
    '</div>' +
    '<div class="card">' +
      '<div class="card-header"><h4>' + icon('list', 14) + ' توزيع الرسائل حسب الحملة</h4></div>' +
      '<div id="analyticsHistory">' + (cr ? historyRowsHtml(cr) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>' +
    '</div>';
  if (cr) { campaignHistory = cr; campaignHistoryAccName = ''; }

  const [stats, rows] = await Promise.all([
    fetch('/dashboard/stats').then(r => r.json()),
    fetch('/dashboard/campaigns').then(r => r.json()),
  ]);
  dataCache.dashboardStats = stats;
  dataCache.dashboardCampaigns = rows;

  const statsEl = document.getElementById('analyticsStats');
  if (statsEl) statsEl.innerHTML = analyticsStatsHtml(stats);
  const perfEl = document.getElementById('analyticsPerf');
  if (perfEl) perfEl.innerHTML = analyticsPerfHtml(stats);

  campaignHistory = rows;
  campaignHistoryAccName = '';
  const historyEl = document.getElementById('analyticsHistory');
  if (historyEl) historyEl.innerHTML = historyRowsHtml(rows);
}

/* ---------- قسم الرد الآلي ---------- */
function renderAutoReply() {
  const c = document.getElementById('content');
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('autoreply', 18) + ' الرد الآلي</h2></div>' +
    '<div class="card">' +
    '<div class="empty-state" style="margin-top:14px">' + icon('autoreply', 28) +
    '<div class="mt-2 font-bold text-[14px]" style="color:var(--text-primary)">هذا القسم قيد التطوير</div>' +
    '<p class="text-[12px] mt-1">نشتغل حالياً على تحسين دقة الرد الآلي، وبيتوفر قريباً.</p>' +
    '</div>' +
    '</div>';
}

/* ---------- قسم الإعدادات ---------- */
function renderSettings() {
  const c = document.getElementById('content');
  const notifState = ('Notification' in window) ? Notification.permission : 'غير مدعوم';
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const initial = (CURRENT_USER || '؟').trim().charAt(0).toUpperCase();
  const roleLabel = IS_ADMIN ? 'أدمن المنصة' : (IS_GENERAL ? 'حساب عام' : 'حساب فرع');

  let html =
    '<div class="page-title"><h2>' + icon('settings', 18) + ' الإعدادات</h2></div>' +
    '<div class="profile-card">' +
      '<div class="avatar avatar-0">' + initial + '</div>' +
      '<div class="info"><div class="name">' + CURRENT_USER + '</div><span class="role">' + roleLabel + '</span></div>' +
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
        '<div class="left"><div class="icon-wrap primary">' + icon('autoreply', 15) + '</div><div><span class="text">الرد الآلي</span><span class="sub-text">قيد التطوير - يتوفر قريباً</span></div></div>' +
        '<i class="fas fa-chevron-left chevron"></i>' +
      '</div>' +
      (IS_GENERAL ? (
        '<div class="settings-item" onclick="showSection(\\'branches\\')">' +
          '<div class="left"><div class="icon-wrap primary">' + icon('branches', 15) + '</div><div><span class="text">الفروع</span><span class="sub-text">أنشئ وأدر حسابات الفروع التابعة لك</span></div></div>' +
          '<i class="fas fa-chevron-left chevron"></i>' +
        '</div>'
      ) : '') +
      (IS_ADMIN ? (
        '<div class="settings-item" onclick="showSection(\\'admin\\')">' +
          '<div class="left"><div class="icon-wrap primary">' + icon('admin', 15) + '</div><div><span class="text">لوحة تحكم الأدمن</span><span class="sub-text">الرد الذكي وكل حسابات المنصة</span></div></div>' +
          '<i class="fas fa-chevron-left chevron"></i>' +
        '</div>'
      ) : '') +
    '</div>' +

    '<button class="btn-logout" onclick="logoutPlatform()">' + icon('logout', 14) + ' تسجيل الخروج من المنصة</button>';

  c.innerHTML = html;
}

/* ---------- قسم الفروع (للحساب العام فقط) ---------- */
function renderBranches() {
  const c = document.getElementById('content');
  if (!IS_GENERAL) { c.innerHTML = '<div class="empty-state">هذا القسم متاح فقط للحساب العام، مو لحسابات الفروع</div>'; return; }
  const cb = dataCache.branches;
  c.innerHTML =
    '<div class="page-title"><h2>' + icon('branches', 18) + ' الفروع</h2></div>' +
    '<div class="card">' +
    '<p class="text-[12px] text-muted mb-2">أنشئ حساب دخول مستقل لكل فرع (بريد وكلمة مرور خاصين فيه) يدير حسابات واتساب وحملات معزولة تماماً عن باقي الفروع وعن حسابك العام.</p>' +
    '<label class="field-label">اسم الفرع</label>' +
    '<input id="branchName" placeholder="مثال: فرع الكرادة">' +
    '<label class="field-label">البريد الإلكتروني</label>' +
    '<input id="branchEmail" type="email" dir="ltr" placeholder="branch1@example.com">' +
    '<label class="field-label">كلمة المرور (اتركها فاضية لتوليد كلمة مرور عشوائية)</label>' +
    '<input id="branchPassword" type="text" dir="ltr" placeholder="6 أحرف على الأقل">' +
    '<button class="btn-submit" onclick="createBranch()">' + icon('plus', 14) + ' إنشاء فرع</button>' +
    '<div id="branchMsg" class="text-center text-[12px] font-bold mt-2"></div>' +
    '</div>' +
    '<div class="card">' +
    '<div class="card-header"><h4>' + icon('branches', 14) + ' الفروع الحالية</h4></div>' +
    '<div id="branchesBox">' + (cb ? branchesListHtml(cb) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>' +
    '</div>';
  loadBranchesList();
}

function branchesListHtml(rows) {
  return rows.length
    ? rows.map(b =>
        '<div class="history-row"><span class="flex items-center gap-2">' + avatarHtml(b.name || b.email, String(b.id)) +
        '<span>' + (b.name ? b.name + ' · ' : '') + b.email + '</span></span>' +
        '<button class="btn-outline btn-small" style="color:#dc2626;border-color:rgba(220,38,38,.3)" onclick="deleteBranch(' + b.id + ')">' + icon('trash', 10) + ' حذف</button></div>'
      ).join('')
    : '<div class="text-muted text-[11px]">ما فيه فروع بعد</div>';
}

async function loadBranchesList() {
  const rows = await fetch('/branches').then(r => r.json());
  dataCache.branches = rows;
  const box = document.getElementById('branchesBox');
  if (box) box.innerHTML = branchesListHtml(rows);
}

async function createBranch() {
  const name = document.getElementById('branchName').value.trim();
  const email = document.getElementById('branchEmail').value.trim();
  const password = document.getElementById('branchPassword').value;
  const msg = document.getElementById('branchMsg');
  msg.innerText = 'جارِ الإنشاء...';
  const r = await fetch('/branches', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({name: name, email: email, password: password}),
  }).then(res => res.json());
  if (!r.ok) { msg.innerText = 'فشل: ' + r.error; return; }
  msg.innerHTML = 'تم إنشاء الفرع. بيانات الدخول (تظهر مرة وحدة، احفظها الآن):<br>' +
    '<b dir="ltr">' + r.email + '</b> / <b dir="ltr">' + r.password + '</b>';
  document.getElementById('branchName').value = '';
  document.getElementById('branchEmail').value = '';
  document.getElementById('branchPassword').value = '';
  loadBranchesList();
}

async function deleteBranch(id) {
  if (!confirm('حذف هذا الفرع؟ بيحذف كل حسابات واتساب وحملات هذا الفرع نهائياً.')) return;
  await fetch('/branches/' + id, { method: 'DELETE' });
  loadBranchesList();
}

/* ---------- قسم التحكم (أدمن فقط) ---------- */
function renderAdmin() {
  const c = document.getElementById('content');
  if (!IS_ADMIN) { c.innerHTML = '<div class="empty-state">هذا القسم لأدمن المنصة فقط</div>'; return; }
  const ca = dataCache.adminAccounts;
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
    '<div class="card-header"><h4>' + icon('users', 14) + ' كل حسابات المنصة</h4></div>' +
    '<div id="adminAccountsBox">' + (ca ? adminAccountsHtml(ca) : '<div class="text-muted text-[11px]">جارِ التحميل...</div>') + '</div>' +
    '</div>';

  fetch('/admin/ai_settings').then(r => r.json()).then(d => {
    document.getElementById('aiKey').placeholder = d.api_key_set ? 'محفوظ مسبقاً (اتركه فاضي للإبقاء عليه)' : 'sk-...';
    document.getElementById('aiKb').value = d.knowledge_base || '';
  });
  loadAdminAccounts();
}

function adminAccountsHtml(rows) {
  return rows.length
    ? rows.map(u => {
        const label = (u.name ? u.name + ' · ' : '') + u.email;
        const kind = u.is_admin ? '<span class="pill pill-gold">أدمن</span>' : (u.parent_id ? '<span class="pill pill-blue">فرع</span>' : '<span class="pill pill-green">حساب عام</span>');
        return '<div class="history-row"><span class="flex items-center gap-2">' + avatarHtml(label, String(u.id)) + '<span>' + label + '</span></span>' + kind + '</div>';
      }).join('')
    : '<div class="text-muted text-[11px]">ما فيه حسابات بعد</div>';
}

async function loadAdminAccounts() {
  const rows = await fetch('/admin/accounts').then(r => r.json());
  dataCache.adminAccounts = rows;
  const box = document.getElementById('adminAccountsBox');
  if (box) box.innerHTML = adminAccountsHtml(rows);
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
    restore_wa_accounts()
    _site_settings = db_get_site_settings()
    _configured_port = _site_settings["port"] if _site_settings and _site_settings["port"] else None
    app.run(host="0.0.0.0", port=_configured_port or int(os.environ.get("PORT", 5000)), threaded=True)
