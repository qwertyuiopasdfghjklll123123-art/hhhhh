# hhhhh

منصة واصل (Wasel Business) — حملات واتساب، ملف واحد Flask (`webapp.py`).

## المتطلبات على السيرفر (VPS)

### 1) بايثون ومكتباته
```
sudo apt install -y python3 python3-pip
pip3 install -r requirements.txt
```
على Ubuntu 24.04 قد يرفض `pip3` التثبيت المباشر برسالة `externally-managed-environment`،
عندها استخدم:
```
pip3 install -r requirements.txt --break-system-packages
```

مكتبات `requirements.txt`:
- `flask` — السيرفر والواجهة
- `selenium` — التحكم بمتصفح واتساب ويب تلقائياً
- `openpyxl` — قراءة ملفات Excel لأرقام الحملات
- `requests` — استدعاء DeepSeek للرد الذكي

### 2) متصفح Chrome/Chromium + ChromeDriver (خارج بايثون)
Selenium يحتاج متصفح حقيقي مثبّت على السيرفر يشغّله بالخلفية (headless) عشان يفتح واتساب ويب:
```
sudo apt install -y chromium-browser chromium-chromedriver
```
أو نسخة Google Chrome الرسمية + ChromeDriver المطابق لإصدارها. الكود يدوّر تلقائياً عن
`chromedriver` بالنظام (`shutil.which`)، ولو ما لقاه يترك Selenium يحاول تدبيره تلقائياً.

### 3) قاعدة البيانات
لا يوجد شي إضافي للتثبيت — تُستخدم SQLite (ملف `app.db` يتولّد تلقائياً بمجلد المشروع)،
وهي مدمجة ببايثون.

## التشغيل
```
python3 webapp.py
```
يفتح تلقائياً على المنفذ المحفوظ بإعدادات الأدمن (أو `PORT` من متغيرات البيئة، وإلا 5000
افتراضياً). أول مستخدم يسجّل دخول عبر مسح رمز QR يصير أدمن المنصة تلقائياً.
