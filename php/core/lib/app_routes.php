<?php
/**
 * روابط أقسام لوحة /app: لكل قسم رابط خاص به  /app/<slug>
 *
 * لوحة /app صفحة واحدة (SPA): صفحات رئيسية تتبدّل (pageHome ...) وطبقات (overlay-page) تُفتح فوقها بإضافة الصنف show.
 * هذا الجدول هو المصدر الوحيد للحقيقة:
 *   • الخادم يتحقق منه (رابط غير معروف أو قسم إدارة لغير الأدمن ⇒ تحويل إلى /app)
 *   • المتصفح يقرأه (window.__FC_SECTIONS) فيفتح القسم عند تحميل الرابط، ويحدّث الرابط عند التنقل (dash_router.js)
 *
 * كل عنصر:  slug => [معرّف عنصر DOM، النوع (pg صفحة رئيسية | ov طبقة)، الصلاحية (all | admin | user)، كود فتحه]
 *   الصلاحية user = للمستخدمين العاديين فقط (عناصر nonadmin-only بالقالب).
 *   كود الفتح = نفس ما تفعله قوائم الواجهة عند النقر (منسوخ منها) بلا toggleMobDrawer.
 *   ملاحظة: الواجهة الأصلية تُبقي طبقة واحدة فقط مفتوحة (عند فتح طبقة تُغلق غيرها)، فكل رابط يفتح طبقته وحدها؛
 *   والبادئة settings/ في الرابط للتجميع فقط.
 */
function app_sections(): array {
    $add = fn(string $id) => "document.getElementById('$id').classList.add('show')";
    $adm = fn(string $id, string $loader) => "openAdmOV('$id');$loader";
    return [
        // ----- صفحات رئيسية -----
        ''                  => ['pageHome',     'pg', 'all', "switchPage('pageHome')"],
        'orders'            => ['pageOrders',   'pg', 'all', "switchPage('pageOrders')"],
        'recharge'          => ['pageRecharge', 'pg', 'user', "switchPage('pageRecharge')"],
        'recharge-requests' => ['pageAllOrders', 'pg', 'admin', "switchPage('pageAllOrders')"],
        'account'           => ['pageAccount',  'pg', 'all', "switchPage('pageAccount')"],

        // ----- طبقات المستخدم -----
        'notifications'     => ['notifPage',           'ov', 'all', 'openNotifPage()'],
        'my-tickets'        => ['customerTicketsPage', 'ov', 'user', 'loadCustomerTicketsList()'],
        'ai-assistant'      => ['aiChatPage',          'ov', 'user', $add('aiChatPage') . ';openAiConversation(0)'],
        'transactions'      => ['rchHistPage',         'ov', 'user', 'loadRchHistory()'],
        'account-settings'  => ['accSettingsPage',     'ov', 'user', $add('accSettingsPage')],
        'faq'               => ['faqPage',             'ov', 'user', $add('faqPage')],
        'my-custom-services' => ['customSvcsPage',     'ov', 'all', $add('customSvcsPage') . ';showCustomSvcsPage()'],
        'favorites'         => ['favSvcsPage',         'ov', 'all', 'openFavPage()'],
        'my-level'          => ['userLevelsPage',      'ov', 'all', 'openUserLevels()'],
        'api'               => ['apiPage',             'ov', 'all', $add('apiPage')
            . ";document.getElementById('apiUrlDisplay').textContent=location.origin+'/api/v2';"
            . "fetch('/api/get-key').then(function(r){return r.json()}).then(function(d){document.getElementById('apiKeyDisplay').textContent=d.key||'لم يتم توليد مفتاح بعد'})"],

        // ----- طبقات الإدارة -----
        'referral'          => ['adminRefPage',          'ov', 'admin', 'loadAdminReferral()'],
        'tickets'           => ['adminTicketsPage',      'ov', 'admin', 'loadAdminTickets()'],
        'smm-orders'        => ['adminSMMOrdersPage',    'ov', 'admin', $add('adminSMMOrdersPage') . ';loadAdminSMMOrders()'],
        'custom-services'   => ['adminCSPage',           'ov', 'admin', $add('adminCSPage') . ';loadAdminCS()'],
        'service-orders'    => ['adminCOPage',           'ov', 'admin', $add('adminCOPage') . ';loadAdminCO()'],
        'filters'           => ['adminFilterPage',       'ov', 'admin', $add('adminFilterPage') . ';loadAdminFilters()'],
        'services'          => ['adminSvcMgmtPage',      'ov', 'admin', $add('adminSvcMgmtPage') . ';loadSvcMgmt()'],
        'levels'            => ['adminLevelsPage',       'ov', 'admin', $add('adminLevelsPage') . ';loadAdminLevels()'],
        'auto-refill'       => ['adminRefillPage',       'ov', 'admin', $add('adminRefillPage') . ';loadAdminRefill()'],
        'order-lookup'      => ['adminOrderLookupPage',  'ov', 'admin', $add('adminOrderLookupPage')],
        'master-key'        => ['masterKeyPage',         'ov', 'admin', $add('masterKeyPage') . ';loadMasterKey()'],
        'import-export'     => ['transferPage',          'ov', 'admin', $adm('transferPage', 'loadTransferPage()')],
        'security'          => ['securityPage',          'ov', 'admin', $adm('securityPage', 'loadSecurityPage()')],

        // ----- لوحة الإعدادات وصفحاتها الفرعية -----
        'settings'                 => ['settingsPage',     'ov', 'admin', $add('settingsPage')],
        'settings/payment-methods' => ['pmFullPage',       'ov', 'admin', $add('pmFullPage') . ';loadPM()'],
        'settings/users'           => ['usersPage',        'ov', 'admin', $add('usersPage') . ';loadUsers()'],
        'settings/profits'         => ['profitsPage',      'ov', 'admin', $add('profitsPage') . ';loadProfits()'],
        'settings/announcements'   => ['annPage',          'ov', 'admin', $add('annPage') . ';loadAnnAdmin()'],
        'settings/logo'            => ['logoPage',         'ov', 'admin', $adm('logoPage', 'loadSiteSettings()')],
        'settings/pwa-icon'        => ['pwaIconPage',      'ov', 'admin', $adm('pwaIconPage', 'loadPwaIcon()')],
        'settings/smtp'            => ['smtpPage',         'ov', 'admin', $adm('smtpPage', 'loadSiteSettings()')],
        'settings/backup'          => ['backupPage',       'ov', 'admin', $adm('backupPage', 'loadSiteSettings();loadBackupList()')],
        'settings/providers'       => ['providersPage',    'ov', 'admin', $adm('providersPage', 'loadProviders()')],
        'settings/coupons'         => ['couponPage',       'ov', 'admin', $adm('couponPage', 'loadCoupons();loadDailyGiftAdmin();loadDailyBonusSettings()')],
        'settings/reviews'         => ['reviewsAdmPage',   'ov', 'admin', $adm('reviewsAdmPage', 'loadAdminReviews()')],
        'settings/currencies'      => ['currencyPage',     'ov', 'admin', $adm('currencyPage', 'loadCurrencySettings()')],
        'settings/ai'              => ['aiSettingsPage',   'ov', 'admin', $adm('aiSettingsPage', 'loadAiSettings()')],
        'settings/auto-import'     => ['autoImportPage',   'ov', 'admin', $adm('autoImportPage', 'loadAutoImportSettings()')],
        'settings/google'          => ['googlePage',       'ov', 'admin', $adm('googlePage', 'loadGoogleSettings()')],
        'settings/policies'        => ['sitePoliciesPage', 'ov', 'admin', $adm('sitePoliciesPage', 'loadSitePolicies()')],
    ];
}

/** تفاصيل قسم بحسب الـ slug أو null */
function app_section(string $slug): ?array {
    $all = app_sections();
    return array_key_exists($slug, $all) ? $all[$slug] : null;
}

/** هل يحق لهذا المستخدم فتح القسم؟ */
function app_section_allowed(array $sec, bool $isAdmin): bool {
    return $sec[2] === 'all' || ($sec[2] === 'admin' && $isAdmin) || ($sec[2] === 'user' && !$isAdmin);
}

/** سكربت الراوتر المضمَّن بصفحة /app: جدول الأقسام + dash_router.js */
function app_router_script(bool $isAdmin): string {
    $secs = [];
    foreach (app_sections() as $slug => $d) {
        $secs[] = ['s' => (string)$slug, 'el' => $d[0], 'k' => $d[1], 'a' => $d[2], 'o' => $d[3]];
    }
    $json = json_encode($secs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $js = (string)@file_get_contents(APP_DIR . '/templates/dash_router.js');
    $tools = $isAdmin ? (string)@file_get_contents(APP_DIR . '/templates/dash_admin_tools.js') : '';   // صفحتا الاستيراد/التصدير والأمان: للأدمن فقط
    return '<script>window.__FC_ADMIN=' . ($isAdmin ? 'true' : 'false') . ';window.__FC_SECTIONS=' . $json . ";\n" . $tools . "\n" . $js . "\n</script>";
}
