-- نفّذ هذا الملف مرة واحدة في phpMyAdmin إن كان موقعك مُنصَّباً من نسخة سابقة بدون
-- حقلي telegram_bot_token و telegram_chat_id (إرسال نسخة احتياطية عبر بوت تيليجرام)
ALTER TABLE settings
    ADD COLUMN telegram_bot_token VARCHAR(255) NULL DEFAULT NULL AFTER welcome_card,
    ADD COLUMN telegram_chat_id VARCHAR(100) NULL DEFAULT NULL AFTER telegram_bot_token;
