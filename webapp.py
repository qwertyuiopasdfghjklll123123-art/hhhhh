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
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            plan_active INTEGER NOT NULL DEFAULT 0,
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
    # تسجيل الدخول عبر واتساب يحتاج email تقبل NULL (حساب برقم بدون بريد) - نعيد بناء
    # الجدول لو كان لسا يفرض NOT NULL من نسخة قديمة، حتى لا نفقد أي مستخدمين موجودين
    email_notnull = any(r["name"] == "email" and r["notnull"] for r in conn.execute("PRAGMA table_info(users)").fetchall())
    if email_notnull:
        conn.execute("ALTER TABLE users RENAME TO users_old")
        conn.execute("""
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE,
                phone TEXT UNIQUE,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                plan_active INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT ''
            )
        """)
        conn.execute(
            "INSERT INTO users (id, email, phone, password_hash, is_admin, plan_active, created_at) "
            "SELECT id, email, phone, password_hash, is_admin, plan_active, created_at FROM users_old"
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
PLAN_PRICE_IQD = 25000  # سعر مبدئي، عدّله لسعرك الفعلي


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


def db_create_user_by_phone(phone, password_hash, is_admin):
    conn = get_db()
    cur = conn.execute(
        "INSERT INTO users (phone, password_hash, is_admin, plan_active, created_at) VALUES (?, ?, ?, 0, ?)",
        (phone, password_hash, int(is_admin), datetime.now().strftime("%Y-%m-%d %H:%M")),
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


def db_create_user(email, password_hash, is_admin):
    conn = get_db()
    cur = conn.execute(
        "INSERT INTO users (email, password_hash, is_admin, plan_active, created_at) VALUES (?, ?, ?, 0, ?)",
        (email, password_hash, int(is_admin), datetime.now().strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    user_id = cur.lastrowid
    conn.close()
    return user_id


def db_list_users():
    conn = get_db()
    rows = conn.execute("SELECT id, email, phone, is_admin, plan_active, created_at FROM users ORDER BY id").fetchall()
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
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<style>
  html, body {{ margin:0; padding:0; background:oklch(0.145 0.014 245); color:oklch(0.97 0.006 245); font-family:'Tajawal',system-ui,sans-serif; min-height:100vh; }}
  .wrap {{ max-width: 360px; margin: 0 auto; padding: 60px 20px; }}
  .logo {{ display:flex; align-items:center; justify-content:center; gap:9px; margin-bottom:10px; }}
  .logo span {{ font-family:'Cairo',sans-serif; font-weight:800; font-size:18px; }}
  h1 {{ text-align:center; font-family:'Cairo',sans-serif; font-size:20px; font-weight:800; margin:14px 0 4px; }}
  .subtitle {{ text-align:center; font-size:12.5px; color:oklch(0.72 0.02 245); margin:0 0 20px; line-height:1.7; }}
  .tabs {{ display:flex; gap:8px; margin-bottom:14px; }}
  .tab {{ flex:1; padding:11px 6px; border-radius:12px; border:1.5px solid oklch(1 0 0 / 15%); background:oklch(0.195 0.017 245); color:oklch(0.72 0.02 245); font-size:12.5px; font-weight:700; text-align:center; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; font-family:inherit; }}
  .tab.active {{ border-color:oklch(0.78 0.17 152 / 45%); color:oklch(0.78 0.17 152); background:oklch(0.78 0.17 152 / 10%); }}
  .box {{ background:oklch(0.195 0.017 245); border:1px solid oklch(1 0 0 / 9%); border-radius:20px; padding:24px 22px; box-shadow:0 20px 50px rgba(0,0,0,.4); }}
  .shield {{ display:flex; justify-content:center; margin-bottom:14px; color:oklch(0.78 0.17 152); }}
  h2 {{ text-align:center; font-family:'Cairo',sans-serif; color:oklch(0.97 0.006 245); font-size:17px; font-weight:800; margin:0 0 4px; }}
  .step-sub {{ text-align:center; font-size:12px; color:oklch(0.72 0.02 245); margin:0 0 16px; }}
  input {{ width:100%; box-sizing:border-box; padding:12px 13px; font-size:14px; font-family:inherit; background:oklch(0.235 0.019 245); border:1px solid oklch(1 0 0 / 15%); border-radius:13px; margin-top:10px; color:oklch(0.97 0.006 245); }}
  input::placeholder {{ color:oklch(0.5 0.02 245); }}
  button {{ width:100%; padding:13px; margin-top:18px; border:none; border-radius:14px; background:linear-gradient(135deg, oklch(0.78 0.17 152), oklch(0.66 0.18 152)); color:oklch(0.2 0.05 152); font-weight:800; font-size:14px; font-family:inherit; cursor:pointer; box-shadow:0 8px 20px oklch(0.78 0.17 152 / 28%); }}
  button:disabled {{ opacity:.7; cursor:default; }}
  button.btn-ghost {{ background:transparent; border:1.5px solid oklch(1 0 0 / 18%); color:oklch(0.97 0.006 245); box-shadow:none; font-weight:700; }}
  p.switch {{ text-align:center; font-size:12px; margin-top:16px; color:oklch(0.72 0.02 245); }}
  p.switch a {{ color:oklch(0.78 0.17 152); font-weight:700; text-decoration:none; }}
  p.err {{ color:oklch(0.68 0.19 21); font-size:12px; text-align:center; margin:0 0 10px; }}
  p.wa-msg {{ color:oklch(0.68 0.19 21); font-size:12px; text-align:center; margin:10px 0 0; min-height:14px; }}
  .wa-hint {{ text-align:center; font-size:11px; color:oklch(0.72 0.02 245); margin-top:12px; }}
  .wa-badge {{ display:flex; justify-content:center; margin-bottom:16px; }}
</style>
</head>
<body>
  <div class="wrap">
    <div class="logo">
      <svg width="30" height="30" viewBox="0 0 32 32" aria-hidden="true">
        <defs><linearGradient id="lg" x1="0" y1="0" x2="32" y2="32">
          <stop offset="0" stop-color="oklch(0.78 0.17 152)"/><stop offset="1" stop-color="oklch(0.6 0.18 152)"/>
        </linearGradient></defs>
        <rect x="1" y="1" width="30" height="30" rx="9" fill="url(#lg)"/>
        <path d="M9 11a2 2 0 012-2h10a2 2 0 012 2v7a2 2 0 01-2 2h-7l-4 4v-4h-1a2 2 0 01-2-2v-7z" fill="#fff"/>
        <path d="M10.5 15h3l1.5-3 2.5 6 1.5-3h3" fill="none" stroke="url(#lg)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <span>واصل</span>
    </div>
    <h1>{title}</h1>
    <p class="subtitle">سجّل دخولك للوصول إلى حسابك وإدارة حملاتك التسويقية بسهولة</p>

    <div class="tabs">
      <div class="tab" id="tabEmail" onclick="showTab('email')">البريد الإلكتروني</div>
      <div class="tab active" id="tabWa" onclick="showTab('wa')">عبر واتساب</div>
    </div>

    <div class="box" id="panelEmail" style="display:none">
      <div class="shield">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 3l7 3v6c0 5-3 8-7 9-4-1-7-4-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/>
        </svg>
      </div>
      <h2>{title}</h2>
      {error_html}
      <form method="post" action="{action}">
        <input name="email" type="email" placeholder="البريد الإلكتروني" required>
        <input name="password" type="password" placeholder="كلمة المرور" required>
        <button type="submit">{title}</button>
      </form>
      {switch_html}
    </div>

    <div class="box" id="panelWa">
      <div class="wa-badge">
        <svg width="56" height="56" viewBox="0 0 32 32" aria-hidden="true">
          <rect x="1" y="1" width="30" height="30" rx="9" fill="url(#lg)"/>
          <path d="M9 11a2 2 0 012-2h10a2 2 0 012 2v7a2 2 0 01-2 2h-7l-4 4v-4h-1a2 2 0 01-2-2v-7z" fill="#fff"/>
          <path d="M10.5 15h3l1.5-3 2.5 6 1.5-3h3" fill="none" stroke="url(#lg)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>

      <div id="waStepPhone">
        <h2>تسجيل الدخول عبر واتساب</h2>
        <p class="step-sub">الطريقة الأسرع والأكثر أماناً</p>
        <input id="waPhone" type="tel" placeholder="رقم واتساب مع مفتاح الدولة، مثال 9647701234567">
        <button type="button" id="waSendBtn" data-label="متابعة عبر واتساب" onclick="waSendCode()">متابعة عبر واتساب</button>
        <p class="wa-hint">آمن وسريع — بدون كلمة مرور لو رقمك مسجّل من قبل</p>
      </div>

      <div id="waStepCode" style="display:none">
        <h2>أدخل رمز التحقق</h2>
        <p class="step-sub">أرسلناه لك عبر واتساب على الرقم المدخل</p>
        <input id="waCode" type="text" inputmode="numeric" placeholder="رمز التحقق (6 أرقام)">
        <button type="button" id="waVerifyBtn" data-label="تحقق" onclick="waVerify()">تحقق</button>
        <button type="button" class="btn-ghost" onclick="waStep('phone')">تعديل الرقم</button>
      </div>

      <div id="waStepPass" style="display:none">
        <h2>عيّن كلمة مرور</h2>
        <p class="step-sub">رقمك جديد — عيّن كلمة مرور لإنشاء حسابك</p>
        <input id="waPass" type="password" placeholder="كلمة المرور (6 أحرف على الأقل)">
        <button type="button" id="waCompleteBtn" data-label="إنشاء الحساب" onclick="waComplete()">إنشاء الحساب</button>
      </div>

      <p class="wa-msg" id="waMsg"></p>
      {switch_html}
    </div>
  </div>

<script>
function showTab(tab) {{
  document.getElementById('tabEmail').classList.toggle('active', tab === 'email');
  document.getElementById('tabWa').classList.toggle('active', tab === 'wa');
  document.getElementById('panelEmail').style.display = tab === 'email' ? 'block' : 'none';
  document.getElementById('panelWa').style.display = tab === 'wa' ? 'block' : 'none';
}}

function waStep(step) {{
  document.getElementById('waStepPhone').style.display = step === 'phone' ? 'block' : 'none';
  document.getElementById('waStepCode').style.display = step === 'code' ? 'block' : 'none';
  document.getElementById('waStepPass').style.display = step === 'pass' ? 'block' : 'none';
  waMsg('');
}}

function waMsg(t) {{ document.getElementById('waMsg').innerText = t || ''; }}

function waBtnLoading(id, loading) {{
  const btn = document.getElementById(id);
  btn.disabled = loading;
  btn.innerText = loading ? '...' : btn.dataset.label;
}}

let waPhone = '';

async function waSendCode() {{
  const phone = document.getElementById('waPhone').value.replace(/[^0-9]/g, '');
  if (phone.length < 8) {{ waMsg('أدخل رقم واتساب صحيح مع مفتاح الدولة'); return; }}
  waPhone = phone;
  waBtnLoading('waSendBtn', true);
  const r = await fetch('/auth/whatsapp/send_code', {{
    method: 'POST', headers: {{'Content-Type': 'application/json'}}, body: JSON.stringify({{phone: phone}})
  }}).then(res => res.json());
  waBtnLoading('waSendBtn', false);
  if (!r.ok) {{ waMsg(r.error); return; }}
  waStep('code');
}}

async function waVerify() {{
  const code = document.getElementById('waCode').value.trim();
  waBtnLoading('waVerifyBtn', true);
  const r = await fetch('/auth/whatsapp/verify', {{
    method: 'POST', headers: {{'Content-Type': 'application/json'}}, body: JSON.stringify({{phone: waPhone, code: code}})
  }}).then(res => res.json());
  waBtnLoading('waVerifyBtn', false);
  if (!r.ok) {{ waMsg(r.error); return; }}
  if (r.logged_in) {{ window.location.href = '/'; return; }}
  waStep('pass');
}}

async function waComplete() {{
  const password = document.getElementById('waPass').value;
  waBtnLoading('waCompleteBtn', true);
  const r = await fetch('/auth/whatsapp/complete', {{
    method: 'POST', headers: {{'Content-Type': 'application/json'}}, body: JSON.stringify({{phone: waPhone, password: password}})
  }}).then(res => res.json());
  waBtnLoading('waCompleteBtn', false);
  if (!r.ok) {{ waMsg(r.error); return; }}
  window.location.href = '/';
}}
</script>
</body>
</html>
"""


EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


@app.route("/login", methods=["GET", "POST"])
def login_page():
    if request.method == "GET":
        return render_auth_page("تسجيل الدخول", "/login", '<p class="switch">ما عندك حساب؟ <a href="/signup">أنشئ حساب</a></p>', "")
    email = request.form.get("email", "").strip().lower()
    password = request.form.get("password", "")
    user = db_get_user_by_email(email)
    if not user or not check_password_hash(user["password_hash"], password):
        return render_auth_page("تسجيل الدخول", "/login", '<p class="switch">ما عندك حساب؟ <a href="/signup">أنشئ حساب</a></p>', "البريد الإلكتروني أو كلمة المرور غير صحيحة")
    session["user_id"] = user["id"]
    session["email"] = user["email"]
    session["is_admin"] = bool(user["is_admin"])
    return redirect("/")


@app.route("/signup", methods=["GET", "POST"])
def signup_page():
    if request.method == "GET":
        return render_auth_page("إنشاء حساب", "/signup", '<p class="switch">عندك حساب؟ <a href="/login">سجّل الدخول</a></p>', "")
    email = request.form.get("email", "").strip().lower()
    password = request.form.get("password", "")
    switch = '<p class="switch">عندك حساب؟ <a href="/login">سجّل الدخول</a></p>'
    if not email or not password:
        return render_auth_page("إنشاء حساب", "/signup", switch, "عبّي كل الحقول")
    if not EMAIL_RE.match(email):
        return render_auth_page("إنشاء حساب", "/signup", switch, "أدخل بريد إلكتروني صحيح")
    if db_get_user_by_email(email):
        return render_auth_page("إنشاء حساب", "/signup", switch, "هذا البريد مسجّل من قبل")
    is_admin = db_count_users() == 0
    user_id = db_create_user(email, generate_password_hash(password), is_admin)
    session["user_id"] = user_id
    session["email"] = email
    session["is_admin"] = is_admin
    return redirect("/")


@app.route("/logout", methods=["POST"])
def logout_page():
    session.clear()
    return redirect("/login")


# ---------------------------------------------------------------- تسجيل الدخول برقم واتساب

@app.route("/auth/whatsapp/send_code", methods=["POST"])
def send_whatsapp_code():
    data = request.json or {}
    phone = re.sub(r"\D", "", data.get("phone") or "")
    if len(phone) < 8:
        return jsonify(ok=False, error="أدخل رقم واتساب صحيح مع مفتاح الدولة"), 400
    sender = find_otp_sender_account()
    if not sender:
        return jsonify(ok=False, error="تسجيل الدخول عبر واتساب غير مفعّل حالياً، جرّب البريد الإلكتروني"), 400
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


@app.route("/auth/whatsapp/verify", methods=["POST"])
def verify_whatsapp_code():
    data = request.json or {}
    phone = re.sub(r"\D", "", data.get("phone") or "")
    code = (data.get("code") or "").strip()
    with otp_lock:
        entry = otp_codes.get(phone)
        if not entry or entry["expires"] < time.time() or entry["code"] != code:
            return jsonify(ok=False, error="الرمز غير صحيح أو منتهي الصلاحية"), 400
        entry["verified"] = True
    # رقم مسجّل من قبل: التحقق من الرمز عبر واتساب يكفي لتسجيل الدخول مباشرة بدون كلمة مرور
    existing = db_get_user_by_phone(phone)
    if existing:
        with otp_lock:
            otp_codes.pop(phone, None)
        session["user_id"] = existing["id"]
        session["email"] = existing["email"] or existing["phone"]
        session["is_admin"] = bool(existing["is_admin"])
        return jsonify(ok=True, logged_in=True)
    return jsonify(ok=True, logged_in=False)


@app.route("/auth/whatsapp/complete", methods=["POST"])
def complete_whatsapp_signup():
    """يُستدعى فقط لإنشاء حساب جديد برقم لم يُسجَّل من قبل (طُلب تعيين كلمة مرور بعد التحقق)."""
    data = request.json or {}
    phone = re.sub(r"\D", "", data.get("phone") or "")
    password = data.get("password") or ""
    with otp_lock:
        entry = otp_codes.get(phone)
        if not entry or not entry.get("verified"):
            return jsonify(ok=False, error="تحقق من رقمك أولاً"), 400
    if len(password) < 6:
        return jsonify(ok=False, error="كلمة المرور قصيرة، لازم 6 أحرف على الأقل"), 400
    if db_get_user_by_phone(phone):
        return jsonify(ok=False, error="هذا الرقم مسجّل بالفعل"), 400
    is_admin = db_count_users() == 0
    user_id = db_create_user_by_phone(phone, generate_password_hash(password), is_admin)
    user = db_get_user_by_id(user_id)
    with otp_lock:
        otp_codes.pop(phone, None)
    session["user_id"] = user["id"]
    session["email"] = user["email"] or user["phone"]
    session["is_admin"] = bool(user["is_admin"])
    return jsonify(ok=True)


# ---------------------------------------------------------------- الصفحة الرئيسية وملفات PWA

@app.route("/")
def home():
    if not session.get("user_id"):
        return redirect("/login")
    page = PAGE.replace("__IS_ADMIN__", "true" if session.get("is_admin") else "false")
    page = page.replace("__USERNAME__", session.get("email", ""))
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
    user = db_get_user_by_email(session["email"])
    payments = db_list_payments_for_user(session["user_id"])
    return jsonify(
        plan_active=bool(user and user["plan_active"]),
        plan_name=PLAN_NAME,
        price_iqd=PLAN_PRICE_IQD,
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
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
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
  html, body { margin: 0; padding: 0; background: var(--bg); color: var(--ink); font-family: 'Tajawal', system-ui, sans-serif; }
  .app { display: flex; flex-direction: column; min-height: 100vh; }
  h1, h2, h3, .topbar-title, .stat-num, .logo-word { font-family: 'Cairo', 'Tajawal', sans-serif; }
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
  .nav { width: 220px; flex-shrink: 0; background: var(--card); border-left: 1px solid var(--border); padding: 16px 10px; }
  .nav-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; border-radius: 12px; font-size: 13px; font-weight: 700; color: var(--muted); cursor: pointer; margin-bottom: 4px; transition: .15s ease; }
  .nav-item:hover { background: var(--card-soft); color: var(--ink); }
  .nav-item.active { background: var(--gold-light); border: 1px solid var(--gold-border); color: var(--gold); }
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
      <div class="nav-item" data-s="accounts" onclick="showSection('accounts')"></div>
      <div class="nav-item" data-s="campaigns" onclick="showSection('campaigns')"></div>
      <div class="nav-item" data-s="autoreply" onclick="showSection('autoreply')"></div>
      <div class="nav-item" data-s="settings" onclick="showSection('settings')"></div>
    </nav>
    <main class="content"><div class="content-inner" id="content"></div></main>
  </div>

  <nav class="bottom-tabs" id="bottomTabs">
    <div class="bottom-tab" data-s="accounts" onclick="showSection('accounts')"></div>
    <div class="bottom-tab" data-s="campaigns" onclick="showSection('campaigns')"></div>
    <div class="fab" onclick="showSection('campaigns')" title="بدء حملة جديدة"></div>
    <div class="bottom-tab" data-s="autoreply" onclick="showSection('autoreply')"></div>
    <div class="bottom-tab" data-s="settings" onclick="showSection('settings')"></div>
  </nav>
</div>

<script>
const IS_ADMIN = __IS_ADMIN__;

/* ---------- أيقونات خطية (بدون إيموجي) ---------- */
const ICONS = {
  accounts: '<circle cx="12" cy="8" r="3.2"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/>',
  campaigns: '<line x1="21" y1="3" x2="10" y2="14"/><polygon points="21 3 14 21 10 14 3 10 21 3"/>',
  autoreply: '<path d="M4 5h16v11H8l-4 4V5z"/><circle cx="9" cy="10.3" r=".9" fill="currentColor" stroke="none"/><circle cx="12" cy="10.3" r=".9" fill="currentColor" stroke="none"/><circle cx="15" cy="10.3" r=".9" fill="currentColor" stroke="none"/>',
  settings: '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="15" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="9" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="17" cy="18" r="2"/>',
  bell: '<path d="M6 8a6 6 0 0112 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 21a2 2 0 004 0"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>',
  moon: '<path d="M20 14.5A8 8 0 119.5 4a6.5 6.5 0 1010.5 10.5z"/>',
  plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
};
function icon(name, size) {
  size = size || 20;
  return '<svg class="icon" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || '') + '</svg>';
}

const SECTION_LABELS = { accounts: 'حسابي', campaigns: 'الحملات', autoreply: 'الرد الآلي', settings: 'الإعدادات' };

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
}
initChrome();

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

  html +=
    '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">الاشتراك</h3>' +
    '<div class="dark-card rounded-2xl p-4" id="subBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>';

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
      '</div>' +
      '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">العملاء</h3>' +
      '<div class="dark-card rounded-2xl p-3" id="customersBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>' +
      '<h3 class="text-[12px] font-extrabold text-gold mt-5 mb-1">طلبات الدفع بانتظار المراجعة</h3>' +
      '<div class="dark-card rounded-2xl p-3" id="paymentsBox"><div class="text-muted text-[11px]">جارِ التحميل...</div></div>';
  }

  c.innerHTML = html;
  loadSubscription();

  if (IS_ADMIN) {
    fetch('/admin/ai_settings').then(r => r.json()).then(d => {
      document.getElementById('aiKey').placeholder = d.api_key_set ? 'محفوظ مسبقاً (اتركه فاضي للإبقاء عليه)' : 'sk-...';
      document.getElementById('aiKb').value = d.knowledge_base || '';
    });
    loadCustomers();
    loadPendingPayments();
  }
}

async function loadSubscription() {
  const d = await fetch('/subscription').then(r => r.json());
  const box = document.getElementById('subBox');
  if (!box) return;
  let html = '<p class="text-[13px] font-bold">' + d.plan_name + ' — ' + d.price_iqd.toLocaleString() + ' د.ع</p>';
  if (d.plan_active) {
    html += '<span class="pill pill-green mt-1">خطتك مفعّلة</span>';
  } else {
    html +=
      '<p class="text-[11px] text-muted mt-1">حوّل المبلغ عبر سوبر كي، وبعدين أدخل رقم إثبات التحويل هنا ليتم التفعيل من الإدارة.</p>' +
      '<input id="payRef" placeholder="رقم إثبات التحويل">' +
      '<button class="btn-gold" onclick="submitPayment()">إرسال</button>' +
      '<div id="payMsg" class="text-center text-[12px] font-bold mt-2"></div>';
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

async function submitPayment() {
  const reference = document.getElementById('payRef').value.trim();
  if (!reference) return;
  const r = await fetch('/subscription/pay', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({reference: reference})
  }).then(res => res.json());
  if (r.ok) { loadSubscription(); return; }
  document.getElementById('payMsg').innerText = 'فشل: ' + r.error;
}

async function loadCustomers() {
  const rows = await fetch('/admin/customers').then(r => r.json());
  const box = document.getElementById('customersBox');
  if (!box) return;
  box.innerHTML = rows.length
    ? rows.map(u => {
        const label = u.email || u.phone || '—';
        return '<div class="history-row"><span class="flex items-center gap-2">' + avatarHtml(label, String(u.id)) +
        '<span>' + label + '</span></span><span class="pill ' + (u.plan_active ? 'pill-green' : 'pill-gray') + '">' +
        (u.plan_active ? 'مفعّل' : 'غير مفعّل') + '</span><span class="text-muted">' + u.created_at + '</span></div>';
      }).join('')
    : '<div class="text-muted text-[11px]">ما فيه عملاء بعد</div>';
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
