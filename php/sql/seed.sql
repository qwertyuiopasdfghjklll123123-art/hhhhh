-- بيانات افتراضية (مستويات، طرق دفع، مزوّد، حسابان) — مطابقة لما يزرعه init_db() بنسخة بايثون الأصلية.
-- استوردها بعد schema.sql عند التثبيت اليدوي عبر phpMyAdmin. بعد الاستيراد غيّر كلمة مرور admin@admin.com فوراً!
SET NAMES utf8mb4;

INSERT INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (1, 'برونزي', 'BRONZE', 0, 0, '#78716c', 'fa-solid fa-seedling', 'بداية الرحلة', 1, 0);
INSERT INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (2, 'فضي', 'SILVER', 50, 3, '#94a3b8', 'fa-solid fa-shield-halved', 'أولوية بالدعم الفني\nخصم 3%', 1, 1);
INSERT INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (3, 'ذهبي', 'GOLD', 200, 5, '#f59e0b', 'fa-solid fa-crown', 'خصم 5%\nعروض حصرية\nدعم سريع', 1, 2);
INSERT INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (4, 'VIP', 'VIP', 1000, 8, '#6366f1', 'fa-solid fa-gem', 'خصم 8%\nمدير حساب خاص\nأولوية قصوى\nإشعارات أولاً', 1, 3);
INSERT INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (5, 'ملكي', 'ROYAL', 5000, 12, '#ec4899', 'fa-solid fa-chess-king', 'خصم 12%\nدعم 24/7\nخدمات مجانية شهرية', 1, 4);
INSERT INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (6, 'أسطوري', 'LEGEND', 25000, 15, '#ef4444', 'fa-solid fa-dragon', 'خصم 15%\nكل المزايا\nتسعيرة خاصة\nAPI مخصص', 1, 5);

INSERT INTO `payment_methods` (`id`,`name`,`icon`,`number`,`exchange_rate`,`currency`,`note`,`bot_link`,`active`,`method_type`,`method_extras`,`sort_order`,`image`) VALUES (1, 'Binance Pay', 'fa-brands fa-bitcoin', '', '', 'USD', '', '', 0, 'binance', '{"api_key": "", "api_secret": ""}', 1, '');
INSERT INTO `payment_methods` (`id`,`name`,`icon`,`number`,`exchange_rate`,`currency`,`note`,`bot_link`,`active`,`method_type`,`method_extras`,`sort_order`,`image`) VALUES (2, 'آسياسيل', 'fa-solid fa-mobile-screen', '', '', 'IQD', 'تحويل رصيد آسياسيل مباشر وتلقائي', '', 0, 'asiacell', '{"max_transfer": 10000}', 2, '');
INSERT INTO `payment_methods` (`id`,`name`,`icon`,`number`,`exchange_rate`,`currency`,`note`,`bot_link`,`active`,`method_type`,`method_extras`,`sort_order`,`image`) VALUES (3, 'نجوم تليجرام', 'fa-brands fa-telegram', '', '', 'USD', 'دفع تلقائي عبر بوت تليجرام بنجوم Telegram Stars', '', 0, 'telegram_stars', '{"bot_token": "", "bot_username": "", "admin_chat_id": "", "rate_usd_per_100": 1.5}', 3, '');

INSERT INTO `providers` (`id`,`name`,`api_url`,`api_key`,`active`,`is_default`,`balance`,`balance_updated`,`show_in_store`) VALUES (1, 'المزود الرئيسي', 'https://smmparty.com/api/v2', '1c0218b705ad1d031562ab810b11552e', 1, 1, 0, 0, 1);

INSERT INTO `users` (`id`,`name`,`email`,`password`,`verified`,`verify_token`,`google_id`,`avatar`,`is_admin`,`balance`,`banned`,`username`,`last_daily_bonus`,`last_seen`,`last_ai_nudge`,`telegram_chat_id`,`api_key`,`public_id`) VALUES (1, 'حمزة', 'test@test.com', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 1, NULL, NULL, '', 0, 0, 0, NULL, 0, 0, 0, '', '', 'FC-1001');
INSERT INTO `users` (`id`,`name`,`email`,`password`,`verified`,`verify_token`,`google_id`,`avatar`,`is_admin`,`balance`,`banned`,`username`,`last_daily_bonus`,`last_seen`,`last_ai_nudge`,`telegram_chat_id`,`api_key`,`public_id`) VALUES (2, 'الأدمن', 'admin@admin.com', 'b5b7485daad03710944d2741bd0713e56a282c4d699a3cfb069e4e73113d7c44', 1, NULL, NULL, '', 1, 0, 0, NULL, 0, 0, 0, '', '', 'FC-1002');
