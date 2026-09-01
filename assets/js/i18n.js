// ============================================================
// نظام تعدد اللغات (عربي/إنجليزي) - العربية هي اللغة الافتراضية
// ============================================================
const I18N = {
    ar: {
        start_now: 'ابدأ الآن', browse_plans: 'تصفح الخطط والأسعار', no_hidden_fees: 'بدون رسوم خفية',
        refund_guarantee: 'ضمان استرداد 30 يوم', instant_activation: 'تفعيل فوري', welcome_to: 'مرحباً بك في',
        reliable_hosting: 'استضافة', trusted: 'موثوقة', for_uninterrupted: 'لأداء لا ينقطع',
        hero_sub: 'نوفر لك أفضل خدمات الاستضافة بأعلى سرعة وأمان، لموقعك وتطبيقاتك لتنمو بدون حدود.',
        free_domain: 'نطاق مجاني', with_every_plan: 'مع كل خطة', daily_backup: 'نسخ احتياطي يومي',
        to_protect_data: 'لحفظ بياناتك', easy_dashboard: 'لوحة تحكم سهلة', competitive_prices: 'أسعار تنافسية',
        quality_best_price: 'جودة بأفضل سعر', super_speed: 'سرعة فائقة', advanced_security: 'أمان متطور',
        advanced_protection: 'حماية متقدمة', support_247: 'دعم فني 24/7', pro_team: 'فريق محترف',
        uptime_badge: 'جاهزية 99.99%', uptime_label: 'وقت تشغيل', all_rights_reserved: 'جميع الحقوق محفوظة.',
        back: 'رجوع', plans_and_pricing: 'الخطط والأسعار', choose_your_plan: 'اختر الباقة التي تناسب احتياجاتك، بدون أي رسوم خفية.',
        subscribe_now: 'اشتراك الآن', no_plans_available: 'لا توجد باقات متاحة حالياً.',
        login: 'تسجيل الدخول', login_welcome: 'مرحباً بعودتك! سجّل الدخول لمتابعة إدارة استضافتك.',
        continue_with_google: 'متابعة عبر Google', or_via_email: 'أو عبر البريد الإلكتروني', email: 'البريد الإلكتروني',
        password: 'كلمة المرور', sign_in: 'دخول', no_account: 'ليس لديك حساب؟', create_new_account: 'إنشاء حساب جديد',
        back_home: '« العودة للرئيسية', create_account: 'إنشاء حساب جديد', join_us_prefix: 'انضم إلى', join_us: 'وابدأ باستضافة مشاريعك اليوم.',
        signup_with_google: 'إنشاء حساب عبر Google', full_name: 'الاسم الكامل', min_6_chars: '6 أحرف على الأقل',
        verification_code: 'كود التحقق', type_code_shown: 'اكتب الكود الظاهر في الصورة', agree_to: 'أوافق على',
        terms_and_conditions: 'الشروط والأحكام', and_word: 'و', privacy_policy: 'سياسة الخصوصية',
        have_account: 'لديك حساب مسبقاً؟', home: 'الرئيسية', my_servers: 'سيرفراتي', new_order: 'طلب جديد',
        invoices: 'فواتير', account: 'الحساب', settings: 'إعدادات', my_orders: 'طلباتي', notifications: 'الإشعارات',
        currency_display: 'عملة عرض الأسعار', currency_display_sub: 'تُطبَّق على كل الأسعار المعروضة لك في التطبيق والموقع',
        language: 'اللغة', language_sub: 'لغة عرض التطبيق', choose_display_currency: 'اختر عملة العرض',
        search_currency: 'ابحث عن عملة أو رمز...', choose_language: 'اختر اللغة', logout: 'تسجيل الخروج',
        logout_confirm_title: 'تسجيل الخروج', logout_confirm_body: 'هل أنت متأكد من رغبتك في تسجيل الخروج من حسابك؟',
        cancel: 'إلغاء', confirm_logout: 'تأكيد الخروج', server_id: 'معرّف السيرفر', hosting_name: 'اسم الاستضافة',
        plan_label: 'الخطة', ip_address: 'عنوان IP', username: 'اسم المستخدم', status: 'الحالة', expiry_date: 'تاريخ الانتهاء',
        active: 'مفعل', expired: 'منتهي', renew_hosting: 'تجديد الاستضافة', cpu: 'المعالج', ram: 'الذاكرة (RAM)',
        storage: 'التخزين', bandwidth: 'الباندويث', os: 'نظام التشغيل', server_location: 'موقع السيرفر',
        subscription_duration: 'مدة الاشتراك', monthly: 'شهري', yearly: 'سنوي', price: 'السعر', total: 'الإجمالي',
        continue_btn: 'متابعة', pay_now: 'إرسال الطلب', share_and_earn: 'شارك واحصل أصدقاؤك على خصم',
        share_link: 'مشاركة الرابط', view_receipt: 'عرض وصل الدفع', administration: 'الإدارة',
        admin_panel: 'لوحة التحكم', admin_panel_sub: 'إدارة الموقع والطلبات والإعدادات',
        general_settings: 'الإعدادات العامة', dark_mode: 'المظهر الداكن', dark_mode_sub: 'الوضع الليلي للتطبيق',
    },
    en: {
        start_now: 'Start Now', browse_plans: 'Browse Plans & Pricing', no_hidden_fees: 'No hidden fees',
        refund_guarantee: '30-day money back', instant_activation: 'Instant activation', welcome_to: 'Welcome to',
        reliable_hosting: 'Reliable', trusted: 'hosting', for_uninterrupted: 'for uninterrupted performance',
        hero_sub: 'We provide the best hosting services with top speed and security, so your site and apps can grow without limits.',
        free_domain: 'Free domain', with_every_plan: 'With every plan', daily_backup: 'Daily backup',
        to_protect_data: 'To protect your data', easy_dashboard: 'Easy dashboard', competitive_prices: 'Competitive prices',
        quality_best_price: 'Quality at the best price', super_speed: 'Blazing speed', advanced_security: 'Advanced security',
        advanced_protection: 'Advanced protection', support_247: '24/7 support', pro_team: 'Professional team',
        uptime_badge: '99.99% uptime', uptime_label: 'Uptime', all_rights_reserved: 'All rights reserved.',
        back: 'Back', plans_and_pricing: 'Plans & Pricing', choose_your_plan: 'Choose the plan that fits your needs, with no hidden fees.',
        subscribe_now: 'Subscribe Now', no_plans_available: 'No plans available right now.',
        login: 'Log In', login_welcome: 'Welcome back! Log in to keep managing your hosting.',
        continue_with_google: 'Continue with Google', or_via_email: 'or via email', email: 'Email',
        password: 'Password', sign_in: 'Sign In', no_account: "Don't have an account?", create_new_account: 'Create new account',
        back_home: '« Back to home', create_account: 'Create Account', join_us_prefix: 'Join', join_us: 'and start hosting your projects today.',
        signup_with_google: 'Sign up with Google', full_name: 'Full Name', min_6_chars: 'At least 6 characters',
        verification_code: 'Verification Code', type_code_shown: 'Type the code shown in the image', agree_to: 'I agree to the',
        terms_and_conditions: 'Terms & Conditions', and_word: 'and', privacy_policy: 'Privacy Policy',
        have_account: 'Already have an account?', home: 'Home', my_servers: 'My Servers', new_order: 'New Order',
        invoices: 'Invoices', account: 'Account', settings: 'Settings', my_orders: 'My Orders', notifications: 'Notifications',
        currency_display: 'Display Currency', currency_display_sub: 'Applied to all prices shown to you across the app and site',
        language: 'Language', language_sub: 'App display language', choose_display_currency: 'Choose display currency',
        search_currency: 'Search a currency or code...', choose_language: 'Choose language', logout: 'Log Out',
        logout_confirm_title: 'Log Out', logout_confirm_body: 'Are you sure you want to log out of your account?',
        cancel: 'Cancel', confirm_logout: 'Confirm Logout', server_id: 'Server ID', hosting_name: 'Hosting Name',
        plan_label: 'Plan', ip_address: 'IP Address', username: 'Username', status: 'Status', expiry_date: 'Expiry Date',
        active: 'Active', expired: 'Expired', renew_hosting: 'Renew Hosting', cpu: 'CPU', ram: 'RAM',
        storage: 'Storage', bandwidth: 'Bandwidth', os: 'Operating System', server_location: 'Server Location',
        subscription_duration: 'Billing Cycle', monthly: 'Monthly', yearly: 'Yearly', price: 'Price', total: 'Total',
        continue_btn: 'Continue', pay_now: 'Submit Order', share_and_earn: 'Share and get your friends a discount',
        share_link: 'Share Link', view_receipt: 'View Receipt', administration: 'Administration',
        admin_panel: 'Admin Panel', admin_panel_sub: 'Manage the site, orders, and settings',
        general_settings: 'General Settings', dark_mode: 'Dark Mode', dark_mode_sub: 'Night mode for the app',
    },
};

function getLang() {
    try { return localStorage.getItem('siteLang') === 'en' ? 'en' : 'ar'; } catch (e) { return 'ar'; }
}

function t(key) {
    const dict = I18N[getLang()] || I18N.ar;
    return dict[key] !== undefined ? dict[key] : (I18N.ar[key] || key);
}

function applyLanguage(lang) {
    lang = lang === 'en' ? 'en' : 'ar';
    try { localStorage.setItem('siteLang', lang); } catch (e) {}
    document.documentElement.setAttribute('lang', lang);
    document.documentElement.setAttribute('dir', lang === 'en' ? 'ltr' : 'rtl');
    document.documentElement.classList.toggle('lang-en', lang === 'en');
    const dict = I18N[lang] || I18N.ar;
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        if (dict[key] !== undefined) el.textContent = dict[key];
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.getAttribute('data-i18n-placeholder');
        if (dict[key] !== undefined) el.placeholder = dict[key];
    });
    document.querySelectorAll('.lang-toggle-label').forEach(el => {
        el.textContent = lang === 'en' ? 'AR' : 'EN';
    });
    document.dispatchEvent(new CustomEvent('languagechange', { detail: { lang } }));
}

function toggleLanguage() {
    applyLanguage(getLang() === 'en' ? 'ar' : 'en');
}

document.addEventListener('DOMContentLoaded', () => applyLanguage(getLang()));
