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
تشغيل السيرفر، وكذلك حسابات واتساب المضافة (اسمها وإعدادات ردها الآلي) تُحفظ
وتُستعاد تلقائياً عند إقلاع السيرفر من جديد - يعيد ربط كل حساب بجلسة متصفحه
المحفوظة بمجلد wa_sessions بدون حاجة لمسح رمز QR من جديد، طالما واتساب نفسه ما
ألغى ربط الجلسة من جهته.

ملاحظة صدق: "الرد الآلي" (كلمات مفتاحية أو AI) يعتمد على مراقبة أول محادثة بقائمة
واتساب بشكل دوري لعدم وجود حدث رسمي "رسالة جديدة"، وهذا أضعف جزء بكل الكود لأنه
مبني على تخمين لمحددات DOM قابلة للتغيّر — توقّع حاجتها لجولة تصحيح لو ما اشتغلت
أول مرة، بنفس طريقة تصحيح إرفاق الصور سابقاً.
"""

import base64
import concurrent.futures
import html
import json
import os
import re
import secrets
import shutil
import signal
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
DEFAULT_MEDIA_DELAY = 2
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
# سنة كاملة تقريباً - الهدف عملياً "ما تنتهي الجلسة إلا بتسجيل خروج صريح"، وليس Flask/Werkzeug
# يدعم انتهاء "أبدي" حقيقي، فنستخدم أطول مدة معقولة بدل هذا
app.config["PERMANENT_SESSION_LIFETIME"] = timedelta(days=365)

accounts = {}  # id -> {id, owner, name, driver, lock, campaign, history, auto_reply, watching, otp_sender}
# مسبح ثريدات خلفي مشترك لأي فحص متصفح ساخن ومتكرر (تسجيل الدخول عبر account_logged_in_fast،
# وصورة رمز QR عبر account_qr_png) - يفصل "قد ياخذ فحص المتصفح حتى 30 ثانية لو كان عالقاً"
# عن "كم ينتظر طلب HTTP الطالب فعلياً قبل أخذ جواب"
login_check_executor = concurrent.futures.ThreadPoolExecutor(max_workers=16, thread_name_prefix="login-check")
events = []
events_lock = threading.Lock()
otp_codes = {}  # phone -> {code, expires, verified}
otp_lock = threading.Lock()
otp_send_status = {}  # phone -> {status: sending/sent/failed, error}
otp_send_lock = threading.Lock()
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
    if "plan_ends_at" not in cols:
        conn.execute("ALTER TABLE users ADD COLUMN plan_ends_at TEXT")
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
    conn.execute("""
        CREATE TABLE IF NOT EXISTS wa_accounts (
            id TEXT PRIMARY KEY,
            owner INTEGER NOT NULL,
            name TEXT,
            auto_reply_enabled INTEGER NOT NULL DEFAULT 0,
            auto_reply_ai_enabled INTEGER NOT NULL DEFAULT 0,
            auto_reply_rules TEXT NOT NULL DEFAULT '[]',
            otp_sender INTEGER NOT NULL DEFAULT 0,
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
        conn.execute("INSERT INTO site_settings (id, port, domain) VALUES (1, 7000, 'botiye.site')")
    site_cols = [r["name"] for r in conn.execute("PRAGMA table_info(site_settings)").fetchall()]
    if "logo" not in site_cols:
        conn.execute("ALTER TABLE site_settings ADD COLUMN logo TEXT")
    if "site_name" not in site_cols:
        conn.execute("ALTER TABLE site_settings ADD COLUMN site_name TEXT DEFAULT ''")
    if "default_country_code" not in site_cols:
        conn.execute("ALTER TABLE site_settings ADD COLUMN default_country_code TEXT DEFAULT '964'")
    if "whatsapp_subscribe_number" not in site_cols:
        conn.execute("ALTER TABLE site_settings ADD COLUMN whatsapp_subscribe_number TEXT DEFAULT ''")
    if "whatsapp_support_number" not in site_cols:
        conn.execute("ALTER TABLE site_settings ADD COLUMN whatsapp_support_number TEXT DEFAULT ''")
    if "plan_price_iqd" not in site_cols:
        conn.execute("ALTER TABLE site_settings ADD COLUMN plan_price_iqd INTEGER")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner INTEGER NOT NULL,
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            subscribed_at TEXT NOT NULL,
            duration_days INTEGER NOT NULL,
            reminder_sent INTEGER NOT NULL DEFAULT 0,
            expired_sent INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT ''
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS subscription_settings (
            owner INTEGER PRIMARY KEY,
            default_duration_days INTEGER NOT NULL DEFAULT 30,
            reminder_days_before INTEGER NOT NULL DEFAULT 3,
            reminder_message TEXT NOT NULL DEFAULT '',
            expired_message TEXT NOT NULL DEFAULT '',
            start_message TEXT NOT NULL DEFAULT ''
        )
    """)
    sub_settings_cols = [r["name"] for r in conn.execute("PRAGMA table_info(subscription_settings)").fetchall()]
    if "start_message" not in sub_settings_cols:
        conn.execute("ALTER TABLE subscription_settings ADD COLUMN start_message TEXT NOT NULL DEFAULT ''")
    conn.commit()
    conn.close()


init_db()

PLAN_NAME = "الخطة الاحترافية"
PLAN_PRICE_IQD = 60000
TRIAL_DAYS = 3
PLAN_ACTIVATION_DAYS = 30  # مدة الاشتراك اللي يفعّله الأدمن يدوياً من لوحة التحكم - يتوقف تلقائياً بعدها
WHATSAPP_PAY_NUMBER = "9647763835403"  # رقم واتساب لتفعيل الاشتراك، عدّله لرقمك الفعلي لو تغيّر

# مصدر واحد لقائمة رموز الدول: تُستخدم بمنتقي الأدمن لرمز الدولة الافتراضي، وسابقاً كانت
# نفس القائمة مكررة يدوياً بقائمة اختيار بصفحة الدخول (أُزيلت - كل مستخدم صار يشوف رمز
# الدولة الافتراضي بس كنص ثابت، الأدمن هو اللي يحدده لمرة وحدة من لوحة التحكم)
COUNTRY_CODES = [
    ("20", "🇪🇬 +20"), ("212", "🇲🇦 +212"), ("213", "🇩🇿 +213"), ("216", "🇹🇳 +216"),
    ("218", "🇱🇾 +218"), ("90", "🇹🇷 +90"), ("91", "🇮🇳 +91"), ("92", "🇵🇰 +92"),
    ("93", "🇦🇫 +93"), ("94", "🇱🇰 +94"), ("95", "🇲🇲 +95"), ("960", "🇲🇻 +960"),
    ("961", "🇱🇧 +961"), ("962", "🇯🇴 +962"), ("963", "🇸🇾 +963"), ("964", "🇮🇶 +964"),
    ("965", "🇰🇼 +965"), ("966", "🇸🇦 +966"), ("967", "🇾🇪 +967"), ("968", "🇴🇲 +968"),
    ("970", "🇵🇸 +970"), ("971", "🇦🇪 +971"), ("972", "🇮🇱 +972"), ("973", "🇧🇭 +973"),
    ("974", "🇶🇦 +974"), ("975", "🇧🇹 +975"), ("976", "🇲🇳 +976"), ("977", "🇳🇵 +977"),
    ("98", "🇮🇷 +98"), ("262", "🇾🇹 +262"), ("992", "🇹🇯 +992"), ("993", "🇹🇲 +993"),
    ("994", "🇦🇿 +994"), ("995", "🇬🇪 +995"), ("996", "🇰🇬 +996"), ("998", "🇺🇿 +998"),
    ("1", "🇺🇸 +1"), ("44", "🇬🇧 +44"), ("49", "🇩🇪 +49"), ("33", "🇫🇷 +33"),
    ("39", "🇮🇹 +39"), ("34", "🇪🇸 +34"), ("7", "🇷🇺 +7"), ("86", "🇨🇳 +86"),
    ("81", "🇯🇵 +81"), ("82", "🇰🇷 +82"), ("60", "🇲🇾 +60"), ("62", "🇮🇩 +62"),
    ("63", "🇵🇭 +63"), ("64", "🇳🇿 +64"), ("61", "🇦🇺 +61"),
]
DEFAULT_COUNTRY_CODE_FALLBACK = "964"

# حساب أدمن ثابت يدخل برمز تحقق ثابت من دون ما يحتاج ربط QR بحساب واتساب شغّال - يفيد
# لدخول صاحب المنصة نفسه حتى لو ما فيه حساب واتساب متصل حالياً (يبقى تسجيل الدخول عبر QR
# متاح عادي لأي مستخدم ثاني). تنبيه أمني: هذا كود ثابت بالكود المصدري، أي أحد يشوف الكود
# يكدر يدخل بهذا الحساب - غيّر الرقم/الكود لو الكود المصدري صار متاح لغيرك
MASTER_ADMIN_PHONE = "9647819044981"
MASTER_ADMIN_CODE = "078190"

DEFAULT_START_MESSAGE = "مرحباً {name}، تم تفعيل اشتراكك بنجاح لمدة {days} يوم."
DEFAULT_REMINDER_MESSAGE = "مرحباً {name}، اشتراكك سينتهي خلال {days} أيام. تواصل معنا للتجديد."
DEFAULT_EXPIRED_MESSAGE = "مرحباً {name}، اشتراكك انتهى اليوم. تواصل معنا للتجديد."


def fill_subscription_message(template, name, days_left):
    return template.replace("{name}", name).replace("{days}", str(days_left))


def effective_plan_active(user):
    """الاشتراك يعتبر فعّال بس لو plan_active=1 وما انتهت مدته (لو الأدمن حدد مدة عند
    التفعيل) - يخلي الاشتراك "يتوقف تلقائياً" بمجرد ما تنتهي مدته بدون حاجة لمهمة خلفية
    تراقب وتطفي العلم بقاعدة البيانات، بنفس أسلوب فحص انتهاء التجربة المجانية أدناه."""
    if not user["plan_active"]:
        return False
    plan_ends_at = user["plan_ends_at"]
    if not plan_ends_at:
        return True
    try:
        return datetime.now() < datetime.strptime(plan_ends_at, "%Y-%m-%d %H:%M")
    except ValueError:
        return True


def user_has_access(user):
    """مفعّل باشتراك حقيقي (وما انتهت مدته)، أو لسا داخل فترة التجربة المجانية (3 أيام من
    إنشاء الحساب)."""
    if effective_plan_active(user):
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


def db_promote_to_admin(user_id):
    conn = get_db()
    conn.execute("UPDATE users SET is_admin = 1 WHERE id = ?", (user_id,))
    conn.commit()
    conn.close()


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
    if active:
        conn.execute("UPDATE users SET plan_active = 1 WHERE id = ?", (user_id,))
    else:
        conn.execute("UPDATE users SET plan_active = 0, plan_ends_at = NULL WHERE id = ?", (user_id,))
    conn.commit()
    conn.close()
    if active:
        add_event(user_id, None, "تم تفعيل الاشتراك", "فعّل أدمن المنصة اشتراكك بنجاح", kind="success")


def db_activate_plan_for_days(user_id, days):
    """يفعّل اشتراك المستخدم لمدة محددة (افتراضياً 30 يوم) - يتوقف تلقائياً بعدها بدون أي
    إجراء إضافي من الأدمن (effective_plan_active تتحقق من الانتهاء بنفسها عند كل استخدام)."""
    conn = get_db()
    ends_at = (datetime.now() + timedelta(days=days)).strftime("%Y-%m-%d %H:%M")
    conn.execute("UPDATE users SET plan_active = 1, plan_ends_at = ? WHERE id = ?", (ends_at, user_id))
    conn.commit()
    conn.close()
    add_event(user_id, None, "تم تفعيل الاشتراك", f"فعّل أدمن المنصة اشتراكك لمدة {days} يوم", kind="success")


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


def db_list_subscribers(owner):
    conn = get_db()
    rows = conn.execute("SELECT * FROM subscribers WHERE owner = ? ORDER BY subscribed_at DESC, id DESC", (owner,)).fetchall()
    conn.close()
    return rows


def db_add_subscriber(owner, name, phone, subscribed_at, duration_days):
    conn = get_db()
    cur = conn.execute(
        "INSERT INTO subscribers (owner, name, phone, subscribed_at, duration_days, created_at) VALUES (?, ?, ?, ?, ?, ?)",
        (owner, name, phone, subscribed_at, duration_days, datetime.now().strftime("%Y-%m-%d %H:%M")),
    )
    conn.commit()
    sub_id = cur.lastrowid
    conn.close()
    return sub_id


def db_get_subscriber(sub_id):
    conn = get_db()
    row = conn.execute("SELECT * FROM subscribers WHERE id = ?", (sub_id,)).fetchone()
    conn.close()
    return row


def db_delete_subscriber(sub_id, owner):
    conn = get_db()
    conn.execute("DELETE FROM subscribers WHERE id = ? AND owner = ?", (sub_id, owner))
    conn.commit()
    conn.close()


def db_renew_subscriber(sub_id, owner, subscribed_at, duration_days):
    conn = get_db()
    conn.execute(
        "UPDATE subscribers SET subscribed_at = ?, duration_days = ?, reminder_sent = 0, expired_sent = 0 "
        "WHERE id = ? AND owner = ?",
        (subscribed_at, duration_days, sub_id, owner),
    )
    conn.commit()
    conn.close()


def db_mark_reminder_sent(sub_id):
    conn = get_db()
    conn.execute("UPDATE subscribers SET reminder_sent = 1 WHERE id = ?", (sub_id,))
    conn.commit()
    conn.close()


def db_mark_expired_sent(sub_id):
    conn = get_db()
    conn.execute("UPDATE subscribers SET expired_sent = 1 WHERE id = ?", (sub_id,))
    conn.commit()
    conn.close()


def db_list_due_subscribers():
    """كل المشتركين اللي لسا ما وصلتهم رسالة تذكير أو رسالة انتهاء - تستخدمها دورة الجدولة
    الدورية بدل ما تفحص كل المشتركين كل مرة."""
    conn = get_db()
    rows = conn.execute("SELECT * FROM subscribers WHERE reminder_sent = 0 OR expired_sent = 0").fetchall()
    conn.close()
    return rows


def db_get_subscription_settings(owner):
    conn = get_db()
    row = conn.execute("SELECT * FROM subscription_settings WHERE owner = ?", (owner,)).fetchone()
    conn.close()
    return row


def db_set_subscription_settings(owner, default_duration_days, reminder_days_before, start_message, reminder_message, expired_message):
    conn = get_db()
    conn.execute(
        "INSERT INTO subscription_settings (owner, default_duration_days, reminder_days_before, start_message, reminder_message, expired_message) "
        "VALUES (?, ?, ?, ?, ?, ?) "
        "ON CONFLICT(owner) DO UPDATE SET default_duration_days = excluded.default_duration_days, "
        "reminder_days_before = excluded.reminder_days_before, start_message = excluded.start_message, "
        "reminder_message = excluded.reminder_message, expired_message = excluded.expired_message",
        (owner, default_duration_days, reminder_days_before, start_message, reminder_message, expired_message),
    )
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


def db_get_branding():
    conn = get_db()
    row = conn.execute(
        "SELECT logo, site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd "
        "FROM site_settings WHERE id = 1"
    ).fetchone()
    conn.close()
    return row


def db_set_branding(site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd, logo=None, update_logo=False):
    conn = get_db()
    if update_logo:
        conn.execute(
            "INSERT INTO site_settings (id, site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd, logo) "
            "VALUES (1, ?, ?, ?, ?, ?, ?) "
            "ON CONFLICT(id) DO UPDATE SET site_name = excluded.site_name, "
            "default_country_code = excluded.default_country_code, "
            "whatsapp_subscribe_number = excluded.whatsapp_subscribe_number, "
            "whatsapp_support_number = excluded.whatsapp_support_number, "
            "plan_price_iqd = excluded.plan_price_iqd, logo = excluded.logo",
            (site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd, logo),
        )
    else:
        conn.execute(
            "INSERT INTO site_settings (id, site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd) "
            "VALUES (1, ?, ?, ?, ?, ?) "
            "ON CONFLICT(id) DO UPDATE SET site_name = excluded.site_name, "
            "default_country_code = excluded.default_country_code, "
            "whatsapp_subscribe_number = excluded.whatsapp_subscribe_number, "
            "whatsapp_support_number = excluded.whatsapp_support_number, "
            "plan_price_iqd = excluded.plan_price_iqd",
            (site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd),
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


def db_set_wa_account_otp_sender(acc_id, enabled):
    conn = get_db()
    if enabled:
        conn.execute("UPDATE wa_accounts SET otp_sender = 0")
        conn.execute("UPDATE wa_accounts SET otp_sender = 1 WHERE id = ?", (acc_id,))
    else:
        conn.execute("UPDATE wa_accounts SET otp_sender = 0 WHERE id = ?", (acc_id,))
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
        "_login_cache": False,
        "_login_future": None,
        "_login_future_lock": threading.Lock(),
        "_qr_future": None,
        "_qr_future_lock": threading.Lock(),
    }


def start_account_driver(acc_id):
    options = webdriver.ChromeOptions()
    options.add_argument(f"--user-data-dir={SESSIONS_ROOT}/{acc_id}")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1200,900")
    options.add_argument("--headless=new")
    options.add_argument("--disable-blink-features=AutomationControlled")
    # تقليل استهلاك الذاكرة/المعالج لخدمات كروم الخلفية غير المتعلقة بواتساب ويب إطلاقاً
    # (تحديثات، تزامن، تحليلات...) - مهم بسيرفر ذاكرته تحت ضغط دائم مع عدة حسابات شغالة
    # بنفس الوقت. ما تلمس أي شي متعلق بعرض/تشغيل الصفحة نفسها، فقط خدمات محيطية بكروم
    options.add_argument("--disable-gpu")
    options.add_argument("--disable-extensions")
    options.add_argument("--disable-background-networking")
    options.add_argument("--disable-default-apps")
    options.add_argument("--disable-sync")
    options.add_argument("--disable-translate")
    options.add_argument("--disable-component-update")
    options.add_argument("--disable-domain-reliability")
    options.add_argument("--metrics-recording-only")
    options.add_argument("--mute-audio")
    options.add_argument("--no-first-run")
    options.add_argument("--safebrowsing-disable-auto-update")
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
    # نحدد سقف 30 ثانية لأي أمر سيلينيوم بعد هذا (بدل المهلة الافتراضية 120 ثانية) - لو صار
    # المتصفح عالق (ضغط ذاكرة بالسيرفر مثلاً) نفشل بسرعة بدل ما نعلّق الطلب اللي يستخدمه لدقيقتين
    driver.command_executor.set_timeout(30)
    accounts[acc_id]["driver"] = driver


def add_account(owner, name):
    acc_id = uuid.uuid4().hex[:8]
    accounts[acc_id] = new_account_entry(acc_id, owner, name)
    db_save_wa_account(acc_id, owner, accounts[acc_id]["name"])
    threading.Thread(target=start_account_driver, args=(acc_id,), daemon=True).start()
    return acc_id


def account_logged_in(acc):
    d = acc["driver"]
    if d is None:
        return False
    try:
        result = len(d.find_elements(By.ID, "pane-side")) > 0
    except Exception:
        # المتصفح تعذّر فحصه هالمرة (عالق مؤقتاً - متل وقت ضغط الذاكرة بالسيرفر) وهذا لا
        # يعني فعلاً انفصال الحساب، فقط تعذّر التأكد الآن. نرجع آخر حالة معروفة بدل ما
        # نفترض "غير متصل" بثقة زائفة ونمسح حالة "متصل" الصحيحة بمجرد تأخير مؤقت بالمتصفح -
        # هذا بالضبط اللي كان يخلي الموقع ما يعرض تسجيل الدخول بعد مسح الباركود لو صار
        # المتصفح عالق لثواني بنفس لحظة المسح
        return acc.get("_login_cache", False)
    acc["_login_cache"] = result
    return result


def account_logged_in_fast(acc):
    """متل account_logged_in لكن ما تعلّق طلب HTTP فترة طويلة لو كان الفحص بطيء - لا فرق
    إذا كان السبب ازدحام القفل acc["lock"] (watch_account شغالة) أو المتصفح نفسه عالق (لحد
    30 ثانية، سقف set_timeout المضروب بـ start_account_driver). تسلّم الفحص الحقيقي لمسبح
    ثريدات خلفي (login_check_executor) وتنتظره بمهلة قصيرة بس، ولو ما خلص بالوقت ترجع آخر
    حالة معروفة فوراً بدون ما توقف الفحص الخلفي نفسه - لو خلص لاحقاً (حتى لو بعد نص دقيقة)
    بيحدّث _login_cache تلقائياً لأجل الطلبات الجاية. مهم لمسارات ساخنة متل إرسال رمز OTP
    وقائمة الحسابات اللي تنفّذ عند كل تنقل بالتطبيق."""
    if acc["driver"] is None:
        return False
    with acc["_login_future_lock"]:
        future = acc.get("_login_future")
        if future is None or future.done():
            def _check():
                with acc["lock"]:
                    return account_logged_in(acc)
            future = login_check_executor.submit(_check)
            acc["_login_future"] = future
    try:
        return future.result(timeout=1.5)
    except Exception:
        return acc.get("_login_cache", False)


def account_qr_png(acc):
    """نفس فكرة account_logged_in_fast بالضبط لكن لجلب صورة رمز QR: تسلّم محاولة القراءة
    الحقيقية من المتصفح لنفس المسبح الخلفي (login_check_executor) وتنتظرها بمهلة قصيرة بس،
    بدل ما تعلّق ثريد HTTP لحد 30 ثانية لو كان المتصفح عالق. هالمسار بالذات ينفحص بشكل متكرر
    جداً من الواجهة أثناء انتظار مسح الباركود (كل ثانية أو أقل) - لو تركناه يعلّق مباشرة على
    متصفح عالق أصلاً، رح تتكدّس عشرات الثريدات العالقة بنفس الوقت على نفس الحساب وتزيد
    الضغط اللي سبب العلق من الأساس. ترجع None لو ما زالت الصورة جاهزة أو تعذّر الفحص."""
    d = acc["driver"]
    if d is None:
        return None
    with acc["_qr_future_lock"]:
        future = acc.get("_qr_future")
        if future is None or future.done():
            def _fetch():
                try:
                    with acc["lock"]:
                        canvas = d.find_element(By.TAG_NAME, "canvas")
                        return canvas.screenshot_as_png
                except Exception as e:
                    print(f"[QR] تعذر إيجاد رمز QR لحساب {acc.get('name', acc.get('id'))}: {e}")
                    return None
            future = login_check_executor.submit(_fetch)
            acc["_qr_future"] = future
    try:
        return future.result(timeout=1.5)
    except Exception:
        return None


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


def apply_country_code(number, country_code):
    """تضيف رمز الدولة لرقم محلي ما يبدأ فيه أصلاً (نفس منطق تطبيع رقم تسجيل الدخول: تشيل
    صفر بادئ إن وجد وتضيف الرمز) - رقم يبدأ فيه أصلاً (دولي كامل) يترجع بدون أي تغيير."""
    if not country_code or number.startswith(country_code):
        return number
    if number.startswith("0"):
        number = number[1:]
    return country_code + number


def _type_and_send_text(driver, text):
    """يكتب نص برسالة عادية بصندوق الدردشة المفتوح حالياً ويرسلها بـ Enter - مستخدم لإرسال
    النص كرسالة مستقلة بعد الصورة (Enter مؤكد يشتغل صح بهذا الصندوق تحديداً، خلاف صندوق تعليق
    الصورة اللي طلع يسبب مشكلة "ترسل كملصق")."""
    box = WebDriverWait(driver, 15).until(
        EC.presence_of_element_located((By.XPATH, '//footer//div[@contenteditable="true"]'))
    )
    box.click()
    box.send_keys(text)
    time.sleep(0.5)
    box.send_keys(Keys.ENTER)


def send_to(driver, number, text, media_path=None, media_delay=DEFAULT_MEDIA_DELAY):
    if media_path:
        driver.get(f"https://web.whatsapp.com/send?phone={number}")
    else:
        driver.get(f"https://web.whatsapp.com/send?phone={number}&text={urllib.parse.quote(text)}")
    WebDriverWait(driver, 30).until(
        EC.presence_of_element_located((By.XPATH, '//footer//div[@contenteditable="true"]'))
    )
    if media_path:
        # الصورة تُرسل هلق لحالها (بدون كتابة أي نص بصندوق تعليقها) - النص (لو موجود) يترسل
        # كرسالة مستقلة بعدها، حتى نتجنب صندوق تعليق الصورة اللي ثبت إنه يسبب مشكلة "ترسل
        # الصورة كملصق" مهما جرّبنا معه (جولات تشخيص سابقة وثّقت المحاولات والفشل بالتفصيل)
        try:
            plus_btn = WebDriverWait(driver, 10).until(
                EC.element_to_be_clickable((By.CSS_SELECTOR, 'span[data-icon="plus-rounded"]'))
            )
            plus_btn.click()
            time.sleep(0.5)
        except Exception as e:
            print(f"[حملة] تعذر الضغط على زر الإرفاق (+): {e}")
        file_inputs = driver.find_elements(By.CSS_SELECTOR, 'input[type="file"]')
        if not file_inputs:
            raise RuntimeError("ما لقيت عنصر رفع الملفات بواجهة واتساب")
        media_input = next((fi for fi in file_inputs if "image" in (fi.get_attribute("accept") or "")), file_inputs[0])
        media_input.send_keys(media_path)
        time.sleep(1.5)  # مهلة قصيرة حتى تنعكس معالجة الملف بواتساب
        try:
            os.makedirs(UPLOADS_DIR, exist_ok=True)
            driver.save_screenshot(os.path.join(UPLOADS_DIR, "_debug_after_file_select.png"))
        except Exception:
            pass
        caption_box = WebDriverWait(driver, 25).until(
            EC.presence_of_element_located((By.XPATH, '//div[@contenteditable="true"][@data-tab]'))
        )
        time.sleep(1)
        sent_via_click = False
        try:
            send_candidates = driver.execute_script("""
                return Array.from(document.querySelectorAll('[data-testid], [data-icon]'))
                    .filter(el => {
                        const t = (el.getAttribute('data-testid') || '') + ' ' + (el.getAttribute('data-icon') || '');
                        return /send/i.test(t);
                    });
            """)
            for el in send_candidates:
                try:
                    visible = el.is_displayed()
                except Exception:
                    continue
                if visible and not sent_via_click:
                    try:
                        el.click()
                        sent_via_click = True
                    except Exception:
                        pass
        except Exception as e:
            print(f"[حملة] تعذر البحث عن زر الإرسال: {e}")
        if not sent_via_click:
            caption_box.send_keys(Keys.ENTER)
        time.sleep(3)  # مهلة أطول حتى يكتمل رفع الملف قبل الانتقال للرقم التالي
        try:
            driver.save_screenshot(os.path.join(UPLOADS_DIR, "_debug_after_send.png"))
        except Exception:
            pass
        print(f"[حملة] أُرسلت الصورة لـ {number} ({'ضغط زر الإرسال' if sent_via_click else 'Enter احتياطي'})")
        if text:
            time.sleep(media_delay)
            _type_and_send_text(driver, text)
            print(f"[حملة] أُرسل النص لـ {number} كرسالة مستقلة بعد الصورة")
    else:
        time.sleep(2)  # مهلة حتى يكتمل تعبئة نص الرسالة تلقائياً بالحقل قبل الإرسال
        driver.switch_to.active_element.send_keys(Keys.ENTER)


def find_otp_sender_account():
    """يبحث عن حساب واتساب واحد حدده الأدمن لإرسال رموز التحقق لتسجيل الدخول/الحسابات الجديدة."""
    for acc in accounts.values():
        if acc.get("otp_sender") and acc["driver"] is not None and account_logged_in_fast(acc):
            return acc
    return None


def run_campaign(acc, numbers, text, delay, media_path, media_delay=DEFAULT_MEDIA_DELAY):
    state = acc["campaign"]
    started = datetime.now().strftime("%Y-%m-%d %H:%M")
    add_event(acc["owner"], acc["name"], "بدأت حملة جديدة", f'جارِ إرسال {state["total"]} رسالة', kind="info")
    for i, number in enumerate(numbers):
        with acc["lock"]:
            try:
                send_to(acc["driver"], number, text, media_path, media_delay)
                state["sent"] += 1
            except Exception as e:
                print(f"[حملة] فشل إرسال لـ {number} (حساب {acc['name']}): {e}")
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


def find_account_for_subscription_send(owner_id):
    """يلاقي حساب واتساب متصل يعود لنفس المستخدم لإرسال رسائل تذكير/انتهاء الاشتراك منه -
    يفضّل الحساب المحدد كمرسل رموز التحقق إن وجد ومتصل، وإلا أي حساب ثاني متصل يعود له."""
    candidates = [a for a in accounts.values() if a["owner"] == owner_id and a["driver"] is not None]
    for a in candidates:
        if a.get("otp_sender") and account_logged_in_fast(a):
            return a
    for a in candidates:
        if account_logged_in_fast(a):
            return a
    return None


def do_send_subscription_start_message(sub_id):
    """ترسل رسالة "تم تفعيل اشتراكك" بخيط منفصل فور إضافة مشترك جديد - بخيط منفصل حتى لا
    تعلّق طلب /subscribers (POST) بانتظار send_to() الكاملة، بنفس نمط do_send_otp."""
    sub = db_get_subscriber(sub_id)
    if not sub:
        return
    settings = db_get_subscription_settings(sub["owner"])
    start_message = (settings["start_message"] if settings else "") or DEFAULT_START_MESSAGE
    acc = find_account_for_subscription_send(sub["owner"])
    if not acc:
        return
    try:
        with acc["lock"]:
            send_to(acc["driver"], sub["phone"], fill_subscription_message(start_message, sub["name"], sub["duration_days"]))
        add_event(sub["owner"], acc["name"], "تم إشعار مشترك جديد", f'{sub["name"]}: تم إرسال رسالة بداية الاشتراك', kind="info")
    except Exception as e:
        print(f"[اشتراكات] فشل إرسال رسالة بداية الاشتراك لـ {sub['name']}: {e}")


def subscription_scheduler_loop():
    """تفحص دورياً كل مشتركي كل المستخدمين، وترسل رسالة تذكير قبل انتهاء الاشتراك بعدد الأيام
    اللي حدده كل مستخدم لحاله، ورسالة "انتهى اشتراكك" بيوم الانتهاء - مرة وحدة بس لكل حالة
    (reminder_sent/expired_sent تمنع التكرار، وتتصفر تلقائياً عند تجديد الاشتراك)."""
    while True:
        try:
            today = datetime.now().date()
            for r in db_list_due_subscribers():
                try:
                    expiry = datetime.strptime(r["subscribed_at"], "%Y-%m-%d").date() + timedelta(days=r["duration_days"])
                except ValueError:
                    continue
                days_left = (expiry - today).days
                settings = db_get_subscription_settings(r["owner"])
                reminder_days_before = settings["reminder_days_before"] if settings else 3
                reminder_message = (settings["reminder_message"] if settings else "") or DEFAULT_REMINDER_MESSAGE
                expired_message = (settings["expired_message"] if settings else "") or DEFAULT_EXPIRED_MESSAGE
                if not r["expired_sent"] and days_left <= 0:
                    acc = find_account_for_subscription_send(r["owner"])
                    if not acc:
                        continue
                    try:
                        with acc["lock"]:
                            send_to(acc["driver"], r["phone"], fill_subscription_message(expired_message, r["name"], 0))
                        db_mark_expired_sent(r["id"])
                        add_event(r["owner"], acc["name"], "انتهى اشتراك مستخدم", f'{r["name"]}: تم إرسال رسالة انتهاء الاشتراك', kind="warning")
                    except Exception as e:
                        print(f"[اشتراكات] فشل إرسال رسالة انتهاء لـ {r['name']}: {e}")
                elif not r["reminder_sent"] and 0 < days_left <= reminder_days_before:
                    acc = find_account_for_subscription_send(r["owner"])
                    if not acc:
                        continue
                    try:
                        with acc["lock"]:
                            send_to(acc["driver"], r["phone"], fill_subscription_message(reminder_message, r["name"], days_left))
                        db_mark_reminder_sent(r["id"])
                        add_event(r["owner"], acc["name"], "تذكير انتهاء اشتراك", f'{r["name"]}: يبقى {days_left} يوم', kind="info")
                    except Exception as e:
                        print(f"[اشتراكات] فشل إرسال تذكير لـ {r['name']}: {e}")
        except Exception as e:
            print(f"[اشتراكات] خطأ بدورة الجدولة: {e}")
        time.sleep(3600)


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
        chat_items = []
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
        except Exception as e:
            print(f"[رد آلي] خطأ بجلب قائمة المحادثات: {e}")
            time.sleep(6)
            continue

        # نمسك القفل لكل محادثة لحالها - مو للدورة كاملة - حتى عمليات ثانية بنفس الحساب
        # (إرسال حملة أو رمز OTP) ما تنتظر خلف دورة مراقبة كاملة قد تاخذ عشر ثواني وأكثر
        # لو فيها عدة محادثات؛ أسوأ حالة انتظار الآن هي وقت معالجة محادثة وحدة بس
        names_ok = names_fail = text_found = 0
        for item in chat_items:
            acc = accounts.get(acc_id)
            if not acc or not acc.get("watching") or acc["driver"] is None:
                print(f"[رد آلي] توقفت المراقبة لحساب {acc_id}")
                return
            try:
                with acc["lock"]:
                    driver = acc["driver"]
                    try:
                        chat_name = item.find_element(By.CSS_SELECTOR, "span[title]").get_attribute("title") or "غير معروف"
                    except Exception:
                        names_fail += 1
                        continue
                    names_ok += 1
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
            except Exception as e:
                print(f"[رد آلي] خطأ أثناء معالجة محادثة (تخطّيها والمتابعة للي بعدها): {e}")
                continue

        if chat_items and not diag2_dumped:
            diag2_dumped = True
            print(f"[رد آلي] {acc['name']}: تشخيص٢ - استخراج اسم نجح لـ {names_ok}/{len(chat_items)}, رسائل واردة موجودة لـ {text_found}/{len(chat_items)}")
            if names_fail > 0:
                try:
                    with acc["lock"]:
                        sample_html = chat_items[0].get_attribute('outerHTML')[:1200]
                    print(f"[رد آلي] {acc['name']}: عينة HTML لأول محادثة:\n{sample_html}")
                except Exception as diag2_e:
                    print(f"[رد آلي] {acc['name']}: تعذر أخذ عينة HTML: {diag2_e}")
            if names_ok > 0 and text_found == 0:
                try:
                    with acc["lock"]:
                        driver = acc["driver"]
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


def kill_orphaned_chrome_processes(known_ids):
    """يقتل عمليات كروم/chromedriver يتيمة من حسابات واتساب قديمة ما عادت موجودة بقاعدة
    البيانات. تصير هيك لو السيرفر انوقف بأمر قاسي (kill -9 أو fuser -k، وكلاهما يرسل
    SIGKILL اللي ما ينلتقط أبداً بأي كود تنظيف بايثون) بدل إيقاف نظيف - تضل عمليات كروم
    شغالة للأبد بذاكرة السيرفر بدون ما يعرف عنها أي بروسس بايثون جديد. تأكدنا من هذا فعلياً
    بمخرجات ps حقيقية من VPS المستخدم: 6 عمليات كروم قديمة (لحسابات محذوفة/قديمة) كانت تاكل
    وحدها نحو 3 غيغابايت من أصل 3.8 غيغابايت رام الجهاز، وهذا السبب الحقيقي وراء Swap
    الممتلئة 100% وفشل رمز QR بالظهور. تفحص فقط عمليات كروم تشير لمجلد SESSIONS_ROOT الخاص
    بهذا التطبيق تحديداً (عبر cmdline)، حتى ما تلمس أي عملية ثانية على نفس السيرفر."""
    if not os.path.isdir("/proc"):
        return
    pattern = re.compile(r"wa_sessions/([^/\s]+)")
    killed_ids = set()
    for entry in os.listdir("/proc"):
        if not entry.isdigit():
            continue
        try:
            with open(f"/proc/{entry}/comm", encoding="utf-8", errors="replace") as f:
                if "chrom" not in f.read().lower():
                    continue
            with open(f"/proc/{entry}/cmdline", "rb") as f:
                cmdline = f.read().decode("utf-8", "replace").replace("\x00", " ")
        except (FileNotFoundError, ProcessLookupError, PermissionError):
            continue
        m = pattern.search(cmdline)
        if not m or m.group(1) in known_ids:
            continue
        try:
            os.kill(int(entry), signal.SIGKILL)
            killed_ids.add(m.group(1))
        except (ProcessLookupError, PermissionError):
            pass
    if killed_ids:
        print(f"[تنظيف] قتلت عمليات كروم يتيمة من {len(killed_ids)} حساب قديم ما عاد موجود: {', '.join(sorted(killed_ids))}")


def restore_wa_accounts():
    """يستعيد كل حسابات واتساب المحفوظة بقاعدة البيانات عند إقلاع السيرفر، حتى لا يحتاج
    المستخدم يضيف حسابه ويمسح QR من جديد بعد كل إعادة تشغيل أو تحديث كود."""
    rows = db_list_wa_accounts()
    kill_orphaned_chrome_processes({row["id"] for row in rows})
    for row in rows:
        acc_id = row["id"]
        accounts[acc_id] = new_account_entry(acc_id, row["owner"], row["name"])
        accounts[acc_id]["otp_sender"] = bool(row["otp_sender"])
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
  .input-group input {{ padding:11px 13px; font-size:14px; border-radius:10px; }}
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

  <a href="/" class="back" aria-label="رجوع">{ICON_BACK}</a>

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


def get_branding_context():
    """يرجع (site_name المهروب HTML، site_logo data-URL أو None، has_custom_name) بعد التحقق
    والتنظيف - مصدر واحد يستخدمه كل من صفحة الترحيب/الدخول وشريط التطبيق العلوي بعد تسجيل
    الدخول، حتى ما يتكرر نفس منطق التحقق (data:image/ فقط، هروب HTML) بمكانين."""
    branding = db_get_branding()
    raw_name = ((branding["site_name"] if branding else "") or "").strip()
    has_custom_name = bool(raw_name)
    site_name = html.escape(raw_name) if has_custom_name else "واصل"
    site_logo = (branding["logo"] if branding else None) or None
    if site_logo and not (isinstance(site_logo, str) and site_logo.startswith("data:image/")):
        site_logo = None  # دفاع إضافي وقت العرض حتى لو صار بقاعدة البيانات شي غير متوقع
    return site_name, site_logo, has_custom_name, branding


def render_welcome_page():
    site_name, site_logo, has_custom_name, _branding = get_branding_context()
    whatsapp_icon_html = f'<img src="/branding/logo" alt="{site_name}" class="custom-logo-icon">' if site_logo else ICON_WHATSAPP
    hero_logo_html = f'<img src="/branding/logo" alt="{site_name}" style="width:78px;height:78px;object-fit:contain;border-radius:20px;">' if site_logo else logo_svg(78)
    login_logo_html = f'<img src="/branding/logo" alt="{site_name}" style="width:54px;height:54px;object-fit:contain;border-radius:14px;">' if site_logo else logo_svg(54)
    hero_logo_class = "w-logo has-custom-logo" if site_logo else "w-logo"
    login_logo_class = "logo has-custom-logo" if site_logo else "logo"
    page_title = site_name if has_custom_name else f"{site_name} — Wasel Business"
    brand_sub_html = "" if has_custom_name else '<div class="en">WASEL BUSINESS</div>'
    default_country_code = ((_branding["default_country_code"] if _branding else "") or "").strip()
    if default_country_code not in {c for c, _ in COUNTRY_CODES}:
        default_country_code = DEFAULT_COUNTRY_CODE_FALLBACK
    default_country_label = dict(COUNTRY_CODES).get(default_country_code, f"+{default_country_code}")
    html_out = WELCOME_TEMPLATE
    html_out = html_out.replace("__WELCOME_PAGE_TITLE__", page_title)
    html_out = html_out.replace("__WELCOME_FONT_LINKS__", FONT_LINKS)
    html_out = html_out.replace("__WELCOME_FONT_STACK__", FONT_STACK)
    html_out = html_out.replace("__WELCOME_PAGE_TRANSITION_CSS__", PAGE_TRANSITION_CSS)
    html_out = html_out.replace("__WELCOME_HERO_LOGO_CLASS__", hero_logo_class)
    html_out = html_out.replace("__WELCOME_HERO_LOGO_HTML__", hero_logo_html)
    html_out = html_out.replace("__WELCOME_SITE_NAME__", site_name)
    html_out = html_out.replace("__WELCOME_BRAND_SUB_HTML__", brand_sub_html)
    html_out = html_out.replace("__WELCOME_ICON_SEND__", ICON_SEND)
    html_out = html_out.replace("__WELCOME_ICON_USERS__", ICON_USERS)
    html_out = html_out.replace("__WELCOME_ICON_TREND__", ICON_TREND)
    html_out = html_out.replace("__WELCOME_ICON_BOLT__", ICON_BOLT)
    html_out = html_out.replace("__WELCOME_ICON_CHART__", ICON_CHART)
    html_out = html_out.replace("__WELCOME_ICON_TARGET__", ICON_TARGET)
    html_out = html_out.replace("__WELCOME_WHATSAPP_ICON_HTML__", whatsapp_icon_html)
    html_out = html_out.replace("__WELCOME_ICON_BACK__", ICON_BACK)
    html_out = html_out.replace("__WELCOME_LOGIN_LOGO_CLASS__", login_logo_class)
    html_out = html_out.replace("__WELCOME_LOGIN_LOGO_HTML__", login_logo_html)
    html_out = html_out.replace("__WELCOME_ICON_CHECK__", ICON_CHECK)
    html_out = html_out.replace("__WELCOME_DEFAULT_COUNTRY_LABEL__", default_country_label)
    html_out = html_out.replace("__WELCOME_ICON_SEND_ARROW__", ICON_SEND_ARROW)
    html_out = html_out.replace("__WELCOME_ICON_LOCK_SM__", ICON_LOCK_SM)
    html_out = html_out.replace("__WELCOME_PAGE_TRANSITION_JS__", PAGE_TRANSITION_JS)
    html_out = html_out.replace("__WELCOME_DEFAULT_COUNTRY_CODE__", default_country_code)
    return html_out


EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


@app.route("/welcome")
def welcome_page():
    if session.get("user_id"):
        return redirect("/app")
    return render_welcome_page()


@app.route("/login", methods=["GET"])
def login_page():
    return redirect("/")


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
    session.permanent = True  # ما تنتهي الجلسة إلا بتسجيل خروج صريح (/logout)
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
    session.permanent = True  # ما تنتهي الجلسة إلا بتسجيل خروج صريح (/logout)
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


def do_send_otp(sender, phone, code):
    """يرسل رمز التحقق بخيط منفصل (نفس نمط بوتستراب) حتى لا يعلّق طلب HTTP بانتظار
    تصفح Selenium الكامل لواتساب ويب - الواجهة تستطلع /auth/whatsapp/send_status بدلها."""
    try:
        with sender["lock"]:
            send_to(sender["driver"], phone, f"رمز التحقق الخاص بك في واصل: {code}\nصالح لمدة 10 دقائق.")
        with otp_send_lock:
            otp_send_status[phone] = {"status": "sent", "error": None}
    except Exception:
        with otp_lock:
            otp_codes.pop(phone, None)
        with otp_send_lock:
            otp_send_status[phone] = {"status": "failed", "error": "تعذر إرسال الرمز، تأكد إن الرقم يستخدم واتساب وحاول مرة أخرى"}


@app.route("/auth/whatsapp/send_code", methods=["POST"])
def send_whatsapp_code():
    data = request.json or {}
    phone = re.sub(r"\D", "", data.get("phone") or "")
    if len(phone) < 8:
        return jsonify(ok=False, error="أدخل رقم واتساب صحيح مع مفتاح الدولة"), 400
    if phone == MASTER_ADMIN_PHONE:
        # حساب أدمن ثابت يسجّل دخوله مباشرة بدون أي رمز تحقق - ما يحتاج حساب واتساب متصل
        # أصلاً ولا يمر بخطوة إدخال الرمز إطلاقاً
        login_as_phone(phone, is_master=True)
        return jsonify(ok=True, master=True)
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
                    accounts[acc_id]["bootstrap_sending"] = False
                    threading.Thread(target=start_account_driver, args=(acc_id,), daemon=True).start()
                    acc = accounts[acc_id]
                elif acc["bootstrap_phone"] != phone:
                    # المستخدم غيّر الرقم قبل إكمال الإعداد - نعيد تصفير علم "أُرسل الرمز"
                    # حتى يرسل status الرمز للرقم الجديد أول ما تتصل نفس جلسة واتساب المفتوحة
                    acc["bootstrap_phone"] = phone
                    acc["bootstrap_code_sent"] = False
                    acc["bootstrap_sending"] = False
            return jsonify(ok=True, bootstrap=True, acc_id=acc["id"])
        return jsonify(ok=False, error="تسجيل الدخول عبر واتساب غير مفعّل حالياً، تواصل مع إدارة المنصة"), 400
    code = str(secrets.randbelow(900000) + 100000)
    with otp_lock:
        otp_codes[phone] = {"code": code, "expires": time.time() + 600, "verified": False}
    with otp_send_lock:
        otp_send_status[phone] = {"status": "sending", "error": None}
    threading.Thread(target=do_send_otp, args=(sender, phone, code), daemon=True).start()
    return jsonify(ok=True, sending=True)


@app.route("/auth/whatsapp/send_status")
def whatsapp_send_status():
    phone = re.sub(r"\D", "", request.args.get("phone") or "")
    with otp_send_lock:
        st = otp_send_status.get(phone)
    if not st:
        return jsonify(status="unknown")
    return jsonify(status=st["status"], error=st["error"])


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


def do_send_bootstrap_code(acc_id):
    """يرسل رمز التحقق الأول (بعد مسح QR مباشرة) بخيط منفصل - نفس سبب do_send_otp: لا نعلّق
    طلب /auth/bootstrap/status المستطلَع كل 2.5 ثانية بانتظار send_to() الكاملة (تصفح فعلي
    لواتساب ويب قد ياخذ عدة ثواني)، ولا نكرر الإرسال لو صارت استطلاعات متراكبة قبل ما يخلص
    محاولة سابقة - acc["bootstrap_sending"] يمنع هذا."""
    acc = accounts.get(acc_id)
    if not acc:
        return
    phone = acc.get("bootstrap_phone", "")
    code = str(secrets.randbelow(900000) + 100000)
    with otp_lock:
        otp_codes[phone] = {"code": code, "expires": time.time() + 600, "verified": False}
    try:
        with acc["lock"]:
            send_to(acc["driver"], phone, f"رمز التحقق الخاص بك في واصل: {code}\nصالح لمدة 10 دقائق.")
        acc["bootstrap_code_sent"] = True
    except Exception:
        pass
    finally:
        acc["bootstrap_sending"] = False


@app.route("/auth/bootstrap/status/<acc_id>")
def bootstrap_status(acc_id):
    if db_count_users() > 0:
        return jsonify(connected=False)
    acc = accounts.get(acc_id)
    if not acc or not acc.get("bootstrap"):
        return jsonify(connected=False)
    if acc.get("bootstrap_code_sent"):
        return jsonify(connected=True, code_sent=True)
    if acc.get("bootstrap_sending"):
        return jsonify(connected=True, code_sent=False)
    if not account_logged_in_fast(acc):
        return jsonify(connected=False)
    acc["bootstrap_sending"] = True
    threading.Thread(target=do_send_bootstrap_code, args=(acc_id,), daemon=True).start()
    return jsonify(connected=True, code_sent=False)


def login_as_phone(phone, is_master=False):
    """يسجّل دخول المستخدم صاحب هذا الرقم بالجلسة الحالية - ينشئ حسابه لو ما كان موجود
    (ويرقّيه لأدمن لو is_master ولسا مو أدمن). تُستخدم بعد التحقق من رمز عادي، وبمسار
    الأدمن الثابت اللي يسجّل دخوله مباشرة بدون أي رمز أصلاً."""
    user = db_get_user_by_phone(phone)
    is_new = not user
    if not user:
        is_admin = is_master or db_count_users() == 0
        user_id = db_create_user_by_phone(phone, None, is_admin)
        user = db_get_user_by_id(user_id)
        add_event(user_id, None, "بدأت الفترة التجريبية", f"لديك {TRIAL_DAYS} أيام مجانية لتجربة المنصة", kind="info")
        if is_admin and not is_master:
            boot_acc = find_pending_bootstrap_account()
            if boot_acc and boot_acc.get("bootstrap_phone") == phone:
                boot_acc["owner"] = user_id
                boot_acc["bootstrap"] = False
                boot_acc["otp_sender"] = True
                boot_acc["name"] = "حسابي الأول"
                db_save_wa_account(boot_acc["id"], user_id, boot_acc["name"])
                db_set_wa_account_otp_sender(boot_acc["id"], True)
    elif is_master and not user["is_admin"]:
        db_promote_to_admin(user["id"])
        user = db_get_user_by_id(user["id"])
    session["user_id"] = user["id"]
    session["email"] = user["email"] or user["phone"]
    session["name"] = user["name"]
    session["is_admin"] = bool(user["is_admin"])
    session.permanent = True  # ما تنتهي الجلسة إلا بتسجيل خروج صريح (/logout)
    return is_new


@app.route("/auth/whatsapp/verify", methods=["POST"])
def verify_whatsapp_code():
    data = request.json or {}
    phone = re.sub(r"\D", "", data.get("phone") or "")
    code = (data.get("code") or "").strip()
    is_master = phone == MASTER_ADMIN_PHONE and code == MASTER_ADMIN_CODE
    if not is_master:
        with otp_lock:
            entry = otp_codes.get(phone)
            if not entry or entry["expires"] < time.time() or entry["code"] != code:
                return jsonify(ok=False, error="الرمز غير صحيح أو منتهي الصلاحية"), 400
            otp_codes.pop(phone, None)
    # الدخول برقم واتساب فقط بدون كلمة مرور: رقم مسجّل من قبل يسجّل دخوله مباشرة، ورقم جديد
    # ينشئ حسابه تلقائياً بمجرد التحقق من الرمز - لا خطوة كلمة مرور بعدها بأي الحالتين
    is_new = login_as_phone(phone, is_master=is_master)
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
    if session.get("user_id"):
        return redirect("/app")
    return render_welcome_page()


@app.route("/app")
def app_home():
    if not session.get("user_id"):
        return redirect("/")
    site_name, site_logo, has_custom_name, branding = get_branding_context()
    topbar_logo_html = f'<img src="/branding/logo" alt="{site_name}">' if site_logo else "و"
    topbar_name_html = site_name if has_custom_name else 'منصة <span>واصل</span>'
    default_country_code = ((branding["default_country_code"] if branding else "") or "").strip()
    if default_country_code not in {c for c, _ in COUNTRY_CODES}:
        default_country_code = DEFAULT_COUNTRY_CODE_FALLBACK
    page = PAGE.replace("__IS_ADMIN__", "true" if session.get("is_admin") else "false")
    page = page.replace("__USERNAME__", session.get("name") or session.get("email", ""))
    page = page.replace("__COUNTRY_CODES_JSON__", json.dumps(COUNTRY_CODES, ensure_ascii=False))
    page = page.replace("__DEFAULT_COUNTRY_CODE__", default_country_code)
    page = page.replace("__TOPBAR_LOGO_CLASS__", "has-custom-img" if site_logo else "")
    page = page.replace("__TOPBAR_LOGO_HTML__", topbar_logo_html)
    page = page.replace("__TOPBAR_NAME_HTML__", topbar_name_html)
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


MAX_LOGO_B64_CHARS = 2 * 1024 * 1024 * 4 // 3 + 100  # ~2 ميغابايت بعد فك ترميز base64


@app.route("/branding/logo")
def branding_logo():
    """تعرض الشعار كصورة حقيقية (بدل تضمينه base64 مباشرة داخل HTML بكل مكان يظهر فيه - كان
    يتكرر حرفياً حتى 5 مرات بنفس الصفحة، وقد يوصل حجمه لعدة ميغابايتات لو الشعار كبير).
    endpoint عام (بدون تسجيل دخول) لأن صفحة الترحيب قبل تسجيل الدخول تحتاجه أيضاً."""
    _, site_logo, _, _ = get_branding_context()
    if not site_logo:
        return "", 404
    try:
        header, b64data = site_logo.split(",", 1)
        mime = header.split(";")[0].replace("data:", "") or "image/png"
        raw = base64.b64decode(b64data)
    except Exception:
        return "", 404
    resp = Response(raw, mimetype=mime)
    resp.headers["Cache-Control"] = "public, max-age=300"
    return resp


@app.route("/admin/branding", methods=["GET"])
@login_required
@admin_required
def get_branding():
    row = db_get_branding()
    return jsonify(
        has_logo=bool(row and row["logo"]),
        site_name=(row["site_name"] if row else "") or "",
        default_country_code=(row["default_country_code"] if row else "") or DEFAULT_COUNTRY_CODE_FALLBACK,
        whatsapp_subscribe_number=(row["whatsapp_subscribe_number"] if row else "") or WHATSAPP_PAY_NUMBER,
        whatsapp_support_number=(row["whatsapp_support_number"] if row else "") or WHATSAPP_PAY_NUMBER,
        plan_price_iqd=(row["plan_price_iqd"] if row else None) or PLAN_PRICE_IQD,
    )


@app.route("/admin/branding", methods=["POST"])
@login_required
@admin_required
def set_branding():
    data = request.json or {}
    site_name = (data.get("site_name") or "").strip()[:60]
    valid_codes = {c for c, _ in COUNTRY_CODES}
    default_country_code = (data.get("default_country_code") or "").strip()
    if default_country_code not in valid_codes:
        default_country_code = DEFAULT_COUNTRY_CODE_FALLBACK
    whatsapp_subscribe_number = re.sub(r"\D", "", data.get("whatsapp_subscribe_number") or "") or WHATSAPP_PAY_NUMBER
    whatsapp_support_number = re.sub(r"\D", "", data.get("whatsapp_support_number") or "") or WHATSAPP_PAY_NUMBER
    try:
        plan_price_iqd = int(data.get("plan_price_iqd") or PLAN_PRICE_IQD)
    except (TypeError, ValueError):
        plan_price_iqd = PLAN_PRICE_IQD
    plan_price_iqd = max(0, plan_price_iqd)
    if "logo" not in data:
        db_set_branding(site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd)
        return jsonify(ok=True)
    logo = data.get("logo")
    if logo:
        if not isinstance(logo, str) or not logo.startswith("data:image/"):
            return jsonify(ok=False, error="صيغة الشعار غير صحيحة"), 400
        if len(logo) > MAX_LOGO_B64_CHARS:
            return jsonify(ok=False, error="حجم الصورة كبير جداً (الحد الأقصى 2 ميغابايت)"), 400
    else:
        logo = None
    db_set_branding(site_name, default_country_code, whatsapp_subscribe_number, whatsapp_support_number, plan_price_iqd, logo, update_logo=True)
    return jsonify(ok=True)


# ---------------------------------------------------------------- الاشتراك والدفع (تحقق يدوي)

@app.route("/subscription")
@login_required
def get_subscription():
    user = db_get_user_by_id(session["user_id"])
    payments = db_list_payments_for_user(session["user_id"])
    plan_active = effective_plan_active(user) if user else False
    trial_days_left = 0
    if user and not plan_active and user["trial_ends_at"]:
        try:
            trial_days_left = max(0, (datetime.strptime(user["trial_ends_at"], "%Y-%m-%d %H:%M") - datetime.now()).days + 1)
        except ValueError:
            trial_days_left = 0
    branding = db_get_branding()
    subscribe_number = (branding["whatsapp_subscribe_number"] if branding else "") or WHATSAPP_PAY_NUMBER
    support_number = (branding["whatsapp_support_number"] if branding else "") or WHATSAPP_PAY_NUMBER
    price_iqd = (branding["plan_price_iqd"] if branding else None) or PLAN_PRICE_IQD
    subscriber_id = (user["phone"] or user["email"] or "غير محدد") if user else "غير محدد"
    pay_text = urllib.parse.quote(f"أرغب بتفعيل اشتراكي في منصة واصل ({price_iqd:,} د.ع) - رقمي: {subscriber_id}")
    support_text = urllib.parse.quote("مرحباً، احتاج مساعدة")
    return jsonify(
        plan_active=plan_active,
        plan_ends_at=user["plan_ends_at"] if (user and plan_active) else None,
        has_access=user_has_access(user) if user else False,
        trial_days_left=trial_days_left,
        plan_name=PLAN_NAME,
        price_iqd=price_iqd,
        wa_pay_link=f"https://wa.me/{subscribe_number}?text={pay_text}",
        wa_support_link=f"https://wa.me/{support_number}?text={support_text}",
        payments=[dict(p) for p in payments],
    )


@app.route("/subscription/pay", methods=["POST"])
@login_required
def submit_payment():
    data = request.json or {}
    reference = (data.get("reference") or "").strip()
    if not reference:
        return jsonify(ok=False, error="أدخل رقم إثبات الدفع/التحويل"), 400
    branding = db_get_branding()
    price_iqd = (branding["plan_price_iqd"] if branding else None) or PLAN_PRICE_IQD
    db_create_payment_request(session["user_id"], PLAN_NAME, price_iqd, reference)
    return jsonify(ok=True)


# ---------------------------------------------------------------- اشتراكات عملاء المستخدم
# (كل مستخدم بالمنصة يدير هون قائمة عملائه هو - مثلاً صاحب إنترنت يسجّل مشتركينه ورقم كل
# واحد، والمنصة ترسلهم تلقائياً رسالة تذكير قبل انتهاء اشتراكهم ورسالة عند الانتهاء)

@app.route("/subscribers", methods=["GET"])
@login_required
def list_subscribers():
    today = datetime.now().date()
    out = []
    for r in db_list_subscribers(session["user_id"]):
        try:
            expiry = datetime.strptime(r["subscribed_at"], "%Y-%m-%d").date() + timedelta(days=r["duration_days"])
            days_left = (expiry - today).days
            expiry_str = expiry.strftime("%Y-%m-%d")
        except ValueError:
            days_left = None
            expiry_str = None
        out.append({
            "id": r["id"], "name": r["name"], "phone": r["phone"],
            "subscribed_at": r["subscribed_at"], "duration_days": r["duration_days"],
            "expiry": expiry_str, "days_left": days_left,
        })
    return jsonify(out)


@app.route("/subscribers", methods=["POST"])
@login_required
def create_subscriber():
    data = request.json or {}
    name = (data.get("name") or "").strip()[:60]
    phone = re.sub(r"\D", "", data.get("phone") or "")
    if not name or len(phone) < 8:
        return jsonify(ok=False, error="أدخل اسم ورقم واتساب صحيحين"), 400
    subscribed_at = (data.get("subscribed_at") or "").strip() or datetime.now().strftime("%Y-%m-%d")
    try:
        datetime.strptime(subscribed_at, "%Y-%m-%d")
    except ValueError:
        return jsonify(ok=False, error="تاريخ الاشتراك غير صحيح"), 400
    settings = db_get_subscription_settings(session["user_id"])
    default_duration = settings["default_duration_days"] if settings else 30
    duration_raw = data.get("duration_days")
    try:
        duration_days = int(duration_raw) if duration_raw not in (None, "") else default_duration
    except (TypeError, ValueError):
        return jsonify(ok=False, error="عدد أيام الاشتراك لازم يكون رقم صحيح"), 400
    if duration_days < 1:
        return jsonify(ok=False, error="عدد أيام الاشتراك لازم يكون رقم أكبر من صفر"), 400
    sub_id = db_add_subscriber(session["user_id"], name, phone, subscribed_at, duration_days)
    threading.Thread(target=do_send_subscription_start_message, args=(sub_id,), daemon=True).start()
    return jsonify(ok=True, id=sub_id)


@app.route("/subscribers/<int:sub_id>/delete", methods=["POST"])
@login_required
def delete_subscriber(sub_id):
    db_delete_subscriber(sub_id, session["user_id"])
    return jsonify(ok=True)


@app.route("/subscribers/<int:sub_id>/renew", methods=["POST"])
@login_required
def renew_subscriber(sub_id):
    sub = db_get_subscriber(sub_id)
    if not sub or sub["owner"] != session["user_id"]:
        return jsonify(ok=False, error="الاشتراك غير موجود"), 404
    data = request.json or {}
    subscribed_at = (data.get("subscribed_at") or "").strip() or datetime.now().strftime("%Y-%m-%d")
    try:
        datetime.strptime(subscribed_at, "%Y-%m-%d")
    except ValueError:
        return jsonify(ok=False, error="تاريخ الاشتراك غير صحيح"), 400
    duration_raw = data.get("duration_days")
    try:
        duration_days = int(duration_raw) if duration_raw not in (None, "") else sub["duration_days"]
    except (TypeError, ValueError):
        return jsonify(ok=False, error="عدد أيام الاشتراك لازم يكون رقم صحيح"), 400
    if duration_days < 1:
        return jsonify(ok=False, error="عدد أيام الاشتراك لازم يكون رقم أكبر من صفر"), 400
    db_renew_subscriber(sub_id, session["user_id"], subscribed_at, duration_days)
    return jsonify(ok=True)


@app.route("/subscription_settings", methods=["GET"])
@login_required
def get_subscription_settings():
    row = db_get_subscription_settings(session["user_id"])
    return jsonify(
        default_duration_days=(row["default_duration_days"] if row else 30),
        reminder_days_before=(row["reminder_days_before"] if row else 3),
        start_message=(row["start_message"] if row else "") or DEFAULT_START_MESSAGE,
        reminder_message=(row["reminder_message"] if row else "") or DEFAULT_REMINDER_MESSAGE,
        expired_message=(row["expired_message"] if row else "") or DEFAULT_EXPIRED_MESSAGE,
    )


@app.route("/subscription_settings", methods=["POST"])
@login_required
def set_subscription_settings():
    data = request.json or {}
    try:
        default_duration_days = int(data.get("default_duration_days") or 30)
        reminder_days_before = int(data.get("reminder_days_before") or 3)
    except (TypeError, ValueError):
        return jsonify(ok=False, error="القيم لازم تكون أرقام صحيحة"), 400
    if default_duration_days < 1 or reminder_days_before < 0:
        return jsonify(ok=False, error="القيم غير صحيحة"), 400
    start_message = (data.get("start_message") or "").strip()[:500] or DEFAULT_START_MESSAGE
    reminder_message = (data.get("reminder_message") or "").strip()[:500] or DEFAULT_REMINDER_MESSAGE
    expired_message = (data.get("expired_message") or "").strip()[:500] or DEFAULT_EXPIRED_MESSAGE
    db_set_subscription_settings(session["user_id"], default_duration_days, reminder_days_before, start_message, reminder_message, expired_message)
    return jsonify(ok=True)


@app.route("/admin/customers")
@login_required
@admin_required
def admin_customers():
    rows = []
    for u in db_list_users():
        d = dict(u)
        d["plan_active"] = effective_plan_active(u)  # يعكس الانتهاء التلقائي فوراً، مو الرقم الخام المخزون
        rows.append(d)
    return jsonify(rows)


@app.route("/admin/customers/<int:user_id>/plan", methods=["POST"])
@login_required
@admin_required
def admin_set_plan(user_id):
    data = request.json or {}
    if bool(data.get("active")):
        try:
            days = int(data.get("days") or PLAN_ACTIVATION_DAYS)
        except (TypeError, ValueError):
            days = PLAN_ACTIVATION_DAYS
        days = max(1, days)
        db_activate_plan_for_days(user_id, days)
    else:
        db_set_plan_active(user_id, False)
    return jsonify(ok=True)


@app.route("/admin/announcement/<acc_id>", methods=["POST"])
@login_required
@admin_required
def send_announcement(acc_id):
    """يرسل إعلان جماعي من أحد حسابات الأدمن نفسه لكل أرقام مستخدمي المنصة المسجلين -
    يعيد استخدام نفس بنية الحملات (run_campaign/send_to) بالضبط، وبس مصدر الأرقام يختلف."""
    acc = get_owned_account(acc_id)
    if not acc or acc["driver"] is None:
        return jsonify(ok=False, error="تأكد من تسجيل الدخول لهذا الحساب أولاً"), 400
    if acc["campaign"]["running"]:
        return jsonify(ok=False, error="فيه حملة أو إعلان شغال حالياً على هذا الحساب"), 400

    numbers = list(dict.fromkeys(u["phone"] for u in db_list_users() if u["phone"]))
    if not numbers:
        return jsonify(ok=False, error="ما فيه مستخدمين عندهم رقم واتساب مسجل"), 400

    text = request.form.get("text", "").strip()
    if not text:
        return jsonify(ok=False, error="اكتب نص الإعلان"), 400
    try:
        delay = max(1, int(request.form.get("delay", DEFAULT_DELAY)))
    except (TypeError, ValueError):
        delay = DEFAULT_DELAY
    try:
        media_delay = max(1, int(request.form.get("media_delay", DEFAULT_MEDIA_DELAY)))
    except (TypeError, ValueError):
        media_delay = DEFAULT_MEDIA_DELAY

    media_path = None
    media = request.files.get("media_file")
    if media and media.filename:
        os.makedirs(UPLOADS_DIR, exist_ok=True)
        media_path = os.path.abspath(os.path.join(UPLOADS_DIR, f"{uuid.uuid4().hex}_{media.filename}"))
        media.save(media_path)

    acc["campaign"].update(total=len(numbers), sent=0, failed=0, running=True, failed_numbers=[], scheduled_for=None)
    threading.Thread(target=run_campaign, args=(acc, numbers, text, delay, media_path, media_delay), daemon=True).start()
    return jsonify(ok=True, total=len(numbers))


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
        accounts_connected=sum(1 for a in my_accounts if account_logged_in_fast(a)),
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
        {"id": aid, "name": a["name"], "logged_in": account_logged_in_fast(a), "otp_sender": a.get("otp_sender", False)}
        for aid, a in accounts.items() if a["owner"] == uid
    ])


@app.route("/accounts", methods=["POST"])
@login_required
def create_account():
    data = request.json or {}
    acc_id = add_account(session["user_id"], (data.get("name") or "").strip())
    return jsonify(id=acc_id, name=accounts[acc_id]["name"])


def delete_wa_account(acc_id, acc):
    """يحذف حساب واتساب نهائياً: يوقف المتصفح، يمسح مجلد الجلسة، ويحذف السجل من الذاكرة
    وقاعدة البيانات (وبالتالي كل سجل حملاته/تاريخه المرتبط، لأنه مخزون بنفس سجل الحساب)."""
    acc["watching"] = False
    if acc["driver"] is not None:
        try:
            acc["driver"].quit()
        except Exception:
            pass
    shutil.rmtree(f"{SESSIONS_ROOT}/{acc_id}", ignore_errors=True)
    del accounts[acc_id]
    db_delete_wa_account(acc_id)


@app.route("/accounts/<acc_id>/logout", methods=["POST"])
@login_required
def account_logout(acc_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(ok=False, error="حساب غير موجود"), 404
    delete_wa_account(acc_id, acc)
    return jsonify(ok=True)


@app.route("/settings/delete_my_data", methods=["POST"])
@login_required
def delete_my_data():
    """يحذف كل بيانات المستخدم الحالي: كل حساباته على واتساب (جلسات تسجيل الدخول) وكل
    حملاته وتاريخها (مخزون بنفس سجل كل حساب، فينحذف تلقائياً معه)."""
    uid = session["user_id"]
    owned_ids = [aid for aid, a in accounts.items() if a["owner"] == uid]
    for acc_id in owned_ids:
        delete_wa_account(acc_id, accounts[acc_id])
    return jsonify(ok=True, deleted=len(owned_ids))


@app.route("/accounts/<acc_id>/otp_sender", methods=["POST"])
@login_required
@admin_required
def set_otp_sender(acc_id):
    acc = get_owned_account(acc_id)
    if not acc:
        return jsonify(ok=False, error="حساب غير موجود"), 404
    data = request.json or {}
    enabled = bool(data.get("enabled"))
    if enabled:
        for a in accounts.values():
            a["otp_sender"] = False
        acc["otp_sender"] = True
    else:
        acc["otp_sender"] = False
    db_set_wa_account_otp_sender(acc_id, enabled)
    return jsonify(ok=True)


@app.route("/accounts/<acc_id>/qr")
@login_required
def qr(acc_id):
    acc = get_owned_account(acc_id)
    if not acc or acc["driver"] is None:
        return "", 204
    png = account_qr_png(acc)
    if png is None:
        return "", 204
    return Response(png, mimetype="image/png")


@app.route("/accounts/<acc_id>/debug")
@login_required
def debug(acc_id):
    acc = get_owned_account(acc_id)
    if not acc or acc["driver"] is None:
        return "driver لسا ما بدأ", 503
    return Response(acc["driver"].get_screenshot_as_png(), mimetype="image/png")


@app.route("/debug/media_attach_snapshot")
@login_required
@admin_required
def debug_media_attach_snapshot():
    """لقطة شاشة تُلتقط تلقائياً لحظة محاولة إرفاق صورة/ملف بحملة (send_to) - تشخيص مؤقت
    لمشكلة "الصور ما ترسل". محصورة بالأدمن لأنها ممكن تكشف محتوى حملة مستخدم ثاني."""
    path = os.path.join(UPLOADS_DIR, "_debug_after_file_select.png")
    if not os.path.exists(path):
        return "", 404
    return Response(open(path, "rb").read(), mimetype="image/png")


@app.route("/debug/media_after_send_snapshot")
@login_required
@admin_required
def debug_media_after_send_snapshot():
    """لقطة شاشة تُلتقط مباشرة بعد محاولة إرسال الوسائط (بعد الضغط/Enter) من متصفح
    الإرسال نفسه - تبيّن شكل الفقاعة اللي فعلاً انرسلت (صورة عادية أو ملصق) بدون انتظار
    لقطة من هاتف المستلم. محصورة بالأدمن لنفس سبب اللقطة السابقة."""
    path = os.path.join(UPLOADS_DIR, "_debug_after_send.png")
    if not os.path.exists(path):
        return "", 404
    return Response(open(path, "rb").read(), mimetype="image/png")


@app.route("/accounts/<acc_id>/status")
@login_required
def status(acc_id):
    acc = get_owned_account(acc_id)
    return jsonify(logged_in=bool(acc) and account_logged_in_fast(acc))


@app.route("/campaign/preview_numbers", methods=["POST"])
@login_required
def preview_numbers():
    """يستخرج الأرقام من ملف Excel فوراً (بدون بدء أي حملة) - يستخدمه حقل الأرقام بواجهة
    الحملات ليعرض الأرقام المستخرجة للمستخدم قبل الإرسال، بنفس منطق الاستخراج بالضبط."""
    file = request.files.get("numbers_file")
    if not file or not file.filename:
        return jsonify(ok=False, error="ما فيه ملف"), 400
    try:
        numbers = numbers_from_excel(file)
    except Exception:
        return jsonify(ok=False, error="تعذرت قراءة الملف - تأكد إنه ملف Excel صالح"), 400
    return jsonify(ok=True, numbers=numbers)


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

    country_code = (request.form.get("country_code") or "").strip()
    if country_code not in {c for c, _ in COUNTRY_CODES}:
        country_code = DEFAULT_COUNTRY_CODE_FALLBACK
    numbers = [apply_country_code(n, country_code) for n in numbers]
    numbers = list(dict.fromkeys(numbers))  # إزالة التكرار مع حفظ الترتيب

    if not numbers:
        return jsonify(ok=False, error="ما لقيت أي رقم صالح (اكتب أرقام أو ارفع ملف Excel)"), 400

    text = request.form.get("text", "").strip() or DEFAULT_MESSAGE
    try:
        delay = max(1, int(request.form.get("delay", DEFAULT_DELAY)))
    except (TypeError, ValueError):
        delay = DEFAULT_DELAY
    try:
        media_delay = max(1, int(request.form.get("media_delay", DEFAULT_MEDIA_DELAY)))
    except (TypeError, ValueError):
        media_delay = DEFAULT_MEDIA_DELAY

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
            run_campaign(acc, numbers, text, delay, media_path, media_delay)

        threading.Thread(target=delayed, daemon=True).start()
    else:
        threading.Thread(target=run_campaign, args=(acc, numbers, text, delay, media_path, media_delay), daemon=True).start()

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
    db_update_wa_account_auto_reply(acc_id, acc["auto_reply"]["enabled"], acc["auto_reply"]["ai_enabled"], acc["auto_reply"]["rules"])
    return jsonify(ok=True)


FRONTEND_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "frontend.html")
FRONTEND_WELCOME_START = "<!-- ===== WASEL:WELCOME_PAGE START (لا تحذف هذا السطر - يستخدمه webapp.py لتحديد بداية القسم) ===== -->"
FRONTEND_WELCOME_END = "<!-- ===== WASEL:WELCOME_PAGE END ===== -->"
FRONTEND_APP_START = "<!-- ===== WASEL:APP_PAGE START (لا تحذف هذا السطر - يستخدمه webapp.py لتحديد بداية القسم) ===== -->"
FRONTEND_APP_END = "<!-- ===== WASEL:APP_PAGE END ===== -->"


def load_frontend_sections():
    """يقرأ frontend.html (الواجهة: HTML/CSS/JS) ويفصل قسميها بالاعتماد على علامات صريحة
    بالملف نفسه - شاشة الترحيب/الدخول قبل تسجيل الدخول (WELCOME_TEMPLATE، فيها توكنات
    __WELCOME_...__ تُستبدل ديناميكياً بـ render_welcome_page())، وتطبيق ما بعد تسجيل
    الدخول (PAGE، فيها توكنات __IS_ADMIN__ ونحوها تُستبدل بـ app_home()). نفس آلية الفصل
    المستخدمة وقت إنشاء الملف، حتى تبقى القراءة والكتابة متطابقتين تماماً."""
    content = open(FRONTEND_PATH, encoding="utf-8").read()
    welcome = content.split(FRONTEND_WELCOME_START + "\n", 1)[1].split("\n" + FRONTEND_WELCOME_END, 1)[0]
    app_page = content.split(FRONTEND_APP_START + "\n", 1)[1].split("\n" + FRONTEND_APP_END, 1)[0]
    return welcome, app_page


WELCOME_TEMPLATE, PAGE = load_frontend_sections()


if __name__ == "__main__":
    restore_wa_accounts()
    threading.Thread(target=subscription_scheduler_loop, daemon=True).start()
    _site_settings = db_get_site_settings()
    _configured_port = _site_settings["port"] if _site_settings and _site_settings["port"] else None
    app.run(host="0.0.0.0", port=_configured_port or int(os.environ.get("PORT", 5000)), threaded=True)
