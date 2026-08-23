"""
مرسل رسائل واتساب تلقائي - سكربت واحد باستخدام Selenium + WhatsApp Web.

الاستخدام:
1) ضع الأرقام (بالصيغة الدولية بدون + أو مسافات، مثال: 9647701234567) كل رقم بسطر داخل numbers.txt
2) عدّل MESSAGE و DELAY_SECONDS أدناه
3) شغّل السكربت أول مرة و HEADLESS = False لمسح رمز QR (على جهازك الشخصي وليس VPS بدون شاشة)
4) بعد نجاح الدخول ينشئ مجلد wa_session بالجلسة المحفوظة، انسخ مجلد المشروع كامل إلى VPS
5) على VPS غيّر HEADLESS = True وشغّل السكربت مباشرة بدون الحاجة لمسح QR مرة ثانية
"""

import shutil
import time
import urllib.parse

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

MESSAGE = "مرحباً، هذه رسالة تجريبية من الحملة."
DELAY_SECONDS = 15
NUMBERS_FILE = "numbers.txt"
SESSION_DIR = "./wa_session"
HEADLESS = False


def build_driver():
    options = webdriver.ChromeOptions()
    options.add_argument(f"--user-data-dir={SESSION_DIR}")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1200,900")
    if HEADLESS:
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
    return driver


def wait_for_login(driver):
    print("افتح واتساب على جوالك وامسح رمز QR إذا ظهر (أول مرة فقط)...")
    WebDriverWait(driver, 120).until(
        EC.presence_of_element_located((By.ID, "pane-side"))
    )
    print("تم تسجيل الدخول بنجاح.")


def send_message(driver, number, message):
    url = f"https://web.whatsapp.com/send?phone={number}&text={urllib.parse.quote(message)}"
    driver.get(url)
    box = WebDriverWait(driver, 30).until(
        EC.presence_of_element_located((By.XPATH, '//footer//div[@contenteditable="true"]'))
    )
    time.sleep(2)  # مهلة حتى يكتمل تعبئة نص الرسالة تلقائياً بالحقل قبل الإرسال
    box.send_keys(Keys.ENTER)


def main():
    with open(NUMBERS_FILE, encoding="utf-8") as f:
        numbers = [line.strip() for line in f if line.strip()]

    driver = build_driver()
    driver.get("https://web.whatsapp.com")
    wait_for_login(driver)

    sent, failed = [], []
    for number in numbers:
        try:
            send_message(driver, number, MESSAGE)
            sent.append(number)
            print(f"نجح الإرسال إلى {number}")
        except Exception as e:
            failed.append(number)
            print(f"فشل الإرسال إلى {number}: {e}")
        time.sleep(DELAY_SECONDS)

    print(f"\nالنتيجة النهائية: نجح {len(sent)} - فشل {len(failed)}")
    if failed:
        print("الأرقام الفاشلة:", ", ".join(failed))

    driver.quit()


if __name__ == "__main__":
    main()
