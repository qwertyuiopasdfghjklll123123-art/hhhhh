            let detailReturnSection = 'home';

            function planIconHtml(icon, iconImage, size) {
                size = size || 28;
                if (iconImage) {
                    return `<img src="${iconImage}" alt="" style="width:${size}px;height:${size}px;object-fit:cover;border-radius:8px;vertical-align:middle">`;
                }
                return icon || '';
            }

            // ============================================================
            // تبديل المظهر
            // ============================================================
            function toggleTheme() {
                const html = document.documentElement;
                const currentTheme = html.getAttribute('data-theme') || 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                
                html.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);

                const toggle = document.getElementById('darkModeToggle');
                if (toggle) {
                    toggle.checked = newTheme === 'dark';
                }
            }

            // استعادة المظهر
            (function() {
                const savedTheme = localStorage.getItem('theme') || 'light';
                document.documentElement.setAttribute('data-theme', savedTheme);

                const toggle = document.getElementById('darkModeToggle');
                if (toggle) {
                    toggle.checked = savedTheme === 'dark';
                }
            })();

            // ============================================================
            // تثبيت التطبيق (PWA) + إشعارات المتصفح
            // ============================================================
            let deferredInstallPrompt = null;

            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('sw.js').catch(() => {});
                });
            }

            function isStandalonePwa() {
                return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            }

            function maybeShowOnboardCards() {
                const pwaCard = document.getElementById('pwaInstallCard');
                const notifCard = document.getElementById('notifPermCard');
                const currencyCard = document.getElementById('onboardCurrencyCard');
                const autoRenewCard = document.getElementById('onboardAutoRenewCard');

                if (currencyCard && autoRenewCard) {
                    currencyCard.classList.toggle('hidden', !NEEDS_ONBOARDING);
                    autoRenewCard.classList.toggle('hidden', !NEEDS_ONBOARDING);
                    if (NEEDS_ONBOARDING) updateOnboardCurrencyValue();
                }

                if (!pwaCard || !notifCard) return;

                const canShowPwa = !NEEDS_ONBOARDING && !!deferredInstallPrompt && !isStandalonePwa() && localStorage.getItem('pwaInstallDismissed') !== '1';
                const canShowNotif = !NEEDS_ONBOARDING && 'Notification' in window && Notification.permission === 'default' && localStorage.getItem('notifPermDismissed') !== '1';

                pwaCard.classList.toggle('hidden', !canShowPwa);
                notifCard.classList.toggle('hidden', !canShowNotif);
            }

            function updateOnboardCurrencyValue() {
                const el = document.getElementById('onboardCurrencyValue');
                if (!el) return;
                const code = detectCurrencyCode();
                const cur = CURRENCIES[code];
                el.textContent = cur ? (cur.symbol + ' ' + cur.name + ' (' + code + ')') : code;
            }

            async function toggleAutoRenewSetting() {
                const toggle = document.getElementById('autoRenewToggle');
                const enabled = toggle.checked ? 1 : 0;
                try {
                    const res = await fetch('index.php?ajax=update_auto_renew', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN, enabled }),
                    });
                    if (!res.ok) throw new Error('failed');
                } catch (err) {
                    toggle.checked = !toggle.checked;
                }
            }

            async function completeOnboarding() {
                const enabled = document.getElementById('onboardAutoRenewToggle')?.checked ? 1 : 0;
                document.getElementById('onboardCurrencyCard')?.classList.add('hidden');
                document.getElementById('onboardAutoRenewCard')?.classList.add('hidden');
                NEEDS_ONBOARDING = false;
                const autoRenewSettingsToggle = document.getElementById('autoRenewToggle');
                if (autoRenewSettingsToggle) autoRenewSettingsToggle.checked = !!enabled;
                maybeShowOnboardCards();
                try {
                    await fetch('index.php?ajax=complete_onboarding', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN, auto_renew: enabled }),
                    });
                } catch (err) {
                    // تجاهل أخطاء الشبكة، الإعداد محفوظ محلياً وسيُعاد تحميله لاحقاً
                }
            }

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredInstallPrompt = e;
                maybeShowOnboardCards();
            });

            window.addEventListener('appinstalled', () => {
                deferredInstallPrompt = null;
                localStorage.setItem('pwaInstallDismissed', '1');
                document.getElementById('pwaInstallCard')?.classList.add('hidden');
            });

            async function triggerPwaInstall() {
                if (!deferredInstallPrompt) return;
                deferredInstallPrompt.prompt();
                await deferredInstallPrompt.userChoice;
                deferredInstallPrompt = null;
                localStorage.setItem('pwaInstallDismissed', '1');
                document.getElementById('pwaInstallCard')?.classList.add('hidden');
            }

            function requestNotifPermission() {
                if (!('Notification' in window)) return;
                Notification.requestPermission().then(() => {
                    localStorage.setItem('notifPermDismissed', '1');
                    document.getElementById('notifPermCard')?.classList.add('hidden');
                });
            }

            function dismissOnboardCard(which) {
                localStorage.setItem(which === 'pwa' ? 'pwaInstallDismissed' : 'notifPermDismissed', '1');
                document.getElementById(which === 'pwa' ? 'pwaInstallCard' : 'notifPermCard')?.classList.add('hidden');
            }

            maybeShowOnboardCards();

            // ============================================================
            // بانر عملية آسياسيل غير مكتملة (تم تحويل جزء من المبلغ فعلياً)
            // ============================================================
            function showAsiacellPendingBanner() {
                const banner = document.getElementById('asiacellPendingBanner');
                if (!banner || !ASIACELL_PENDING) return;
                document.getElementById('asiacellPendingText').textContent =
                    'تم تحويل ' + Number(ASIACELL_PENDING.paid).toLocaleString() + ' د.ع من إجمالي ' + Number(ASIACELL_PENDING.total).toLocaleString() + ' د.ع (المتبقي ' + Number(ASIACELL_PENDING.remaining).toLocaleString() + ' د.ع).';
                banner.classList.remove('hidden');
            }
            showAsiacellPendingBanner();

            function resumeAsiacellPending() {
                if (!ASIACELL_PENDING) return;
                document.getElementById('asiacellPendingBanner').classList.add('hidden');
                if (ASIACELL_PENDING.context === 'order') {
                    showSection('vps');
                    wizardState.planId = ASIACELL_PENDING.plan_id;
                    wizardState.paymentMethod = String(ASIACELL_PENDING.payment_method_id);
                    wizardGoTo('payment');
                    binanceShowStep('order', 'info');
                    asiacellShowStep('order', 'sms2');
                    document.getElementById('asiacellPayInfo').innerHTML = `<div class="hosting-detail"><div class="detail-row"><span class="label">تم تحويل</span><span class="value" style="direction:ltr">${Number(ASIACELL_PENDING.paid).toLocaleString()} د.ع</span></div><div class="detail-row"><span class="label">المتبقي</span><span class="value" style="direction:ltr">${Number(ASIACELL_PENDING.remaining).toLocaleString()} د.ع</span></div></div>`;
                } else {
                    showSection('invoices');
                    showAddBalance();
                    const pm = PAYMENT_METHODS.find(p => String(p.id) === String(ASIACELL_PENDING.payment_method_id));
                    if (pm) showPaymentPage(pm.id, pm.name, pm.account_number, pm.instructions, pm.method_type, pm.binance_id, pm.qr_code, pm.exchange_rate);
                    asiacellShowStep('topup', 'sms2');
                    document.getElementById('topUpAsiacellPayInfo').innerHTML = `<div class="hosting-detail"><div class="detail-row"><span class="label">تم تحويل</span><span class="value" style="direction:ltr">${Number(ASIACELL_PENDING.paid).toLocaleString()} د.ع</span></div><div class="detail-row"><span class="label">المتبقي</span><span class="value" style="direction:ltr">${Number(ASIACELL_PENDING.remaining).toLocaleString()} د.ع</span></div></div>`;
                }
            }

            async function cancelAsiacellPending() {
                try {
                    const res = await fetch('index.php?ajax=asiacell_cancel', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN }),
                    });
                    const data = await res.json();
                    document.getElementById('asiacellPendingBanner').classList.add('hidden');
                    if (data.credited_usd > 0) {
                        location.href = 'index.php?app=1&topup=1';
                    }
                } catch (err) {
                    document.getElementById('asiacellPendingBanner').classList.add('hidden');
                }
            }

            // ============================================================
            // اختيار العملة (بطاقة منبثقة قابلة للبحث)
            // ============================================================
            function updateCurrencyCardValue() {
                const el = document.getElementById('currencyCardValue');
                if (!el) return;
                const code = detectCurrencyCode();
                const cur = CURRENCIES[code];
                el.textContent = cur ? (cur.symbol + ' ' + cur.name + ' (' + code + ')') : code;
            }
            updateCurrencyCardValue();

            function renderCurrencyOptions(filterText) {
                const term = (filterText || '').trim().toLowerCase();
                const current = detectCurrencyCode();
                const list = document.getElementById('currencyOptionsList');
                const codes = Object.keys(CURRENCIES).filter(code => {
                    if (!term) return true;
                    const c = CURRENCIES[code];
                    return code.toLowerCase().includes(term) || c.name.toLowerCase().includes(term);
                });
                if (!codes.length) {
                    list.innerHTML = '<div class="text-muted text-center" style="padding:24px 0;font-size:12px">لا توجد نتائج</div>';
                    return;
                }
                list.innerHTML = codes.map(code => {
                    const c = CURRENCIES[code];
                    const isSelected = code === current;
                    return `
                    <div class="picker-option ${isSelected ? 'selected' : ''}" onclick="chooseCurrency('${code}')">
                        <div class="picker-option-symbol">${c.symbol}</div>
                        <div class="picker-option-main"><strong>${c.name}</strong><span>${code}</span></div>
                        ${isSelected ? '<i class="fas fa-check picker-option-check"></i>' : ''}
                    </div>
                `;
                }).join('');
            }

            function filterCurrencyOptions() {
                renderCurrencyOptions(document.getElementById('currencySearchInput').value);
            }

            function chooseCurrency(code) {
                setDisplayCurrency(code);
                updateCurrencyCardValue();
                updateOnboardCurrencyValue();
                closeCurrencyPicker();
            }

            function openCurrencyPicker() {
                document.getElementById('currencySearchInput').value = '';
                renderCurrencyOptions();
                document.getElementById('currencyPickerOverlay').classList.add('show');
            }

            function closeCurrencyPicker() {
                document.getElementById('currencyPickerOverlay').classList.remove('show');
            }

            // ============================================================
            // اختيار اللغة
            // ============================================================
            function updateLanguageCardValue() {
                const el = document.getElementById('languageCardValue');
                if (!el) return;
                el.textContent = getLang() === 'en' ? 'English' : 'العربية';
                const checkAr = document.getElementById('langCheckAr');
                const checkEn = document.getElementById('langCheckEn');
                if (checkAr) checkAr.style.visibility = getLang() === 'ar' ? 'visible' : 'hidden';
                if (checkEn) checkEn.style.visibility = getLang() === 'en' ? 'visible' : 'hidden';
            }
            document.addEventListener('languagechange', updateLanguageCardValue);
            updateLanguageCardValue();

            function chooseLanguage(lang) {
                applyLanguage(lang);
                updateLanguageCardValue();
                closeLanguagePicker();
            }

            function openLanguagePicker() {
                updateLanguageCardValue();
                document.getElementById('languagePickerOverlay').classList.add('show');
            }

            function closeLanguagePicker() {
                document.getElementById('languagePickerOverlay').classList.remove('show');
            }

            // ============================================================
            // التنقل بين الأقسام
            // ============================================================
            function showSection(section) {
                document.querySelectorAll('.section-content').forEach(el => {
                    el.classList.add('hidden');
                });

                const target = document.getElementById('section-' + section);
                if (target) {
                    target.classList.remove('hidden');
                }

                document.querySelectorAll('#mainBottomNav .nav-item').forEach(el => {
                    el.classList.remove('active');
                    if (el.dataset.section === section) {
                        el.classList.add('active');
                    }
                });

                if (section === 'vps') {
                    wizardGoTo('plan');
                }
                if (section === 'servers') {
                    const searchInput = document.getElementById('serverSearchInput');
                    if (searchInput) searchInput.value = '';
                    filterServers();
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            // ============================================================
            // لوحة التحكم (الإدارة) - قسم مضمّن بالكامل داخل صفحة /app
            // التبديل بين تبويباتها يتم بالكامل في المتصفح، دون أي طلب شبكة
            // ============================================================
            function showAdminTab(key) {
                document.querySelectorAll('#adminTabsNav .admin-tab').forEach(el => {
                    el.classList.toggle('active', el.dataset.adminTab === key);
                });
                document.querySelectorAll('#section-admin .admin-tab-panel').forEach(el => {
                    el.classList.toggle('hidden', el.dataset.adminPanel !== key);
                });
                const hero = document.getElementById('adminHeroCard');
                if (hero) hero.classList.toggle('hidden', key !== 'orders');
                const stats = document.getElementById('adminStatsRow');
                if (stats) stats.classList.toggle('hidden', key !== 'orders' && key !== 'topups');
            }

            function showAdminFlash(msg, err) {
                const msgEl = document.getElementById('adminFlashMsg');
                if (msgEl) {
                    if (msg) { msgEl.querySelector('span').textContent = msg; msgEl.classList.remove('hidden'); }
                    else { msgEl.classList.add('hidden'); }
                }
                const errEl = document.getElementById('adminFlashErr');
                if (errEl) {
                    if (err) { errEl.querySelector('span').textContent = err; errEl.classList.remove('hidden'); }
                    else { errEl.classList.add('hidden'); }
                }
            }

            function confirmAndSubmit(form, message) {
                if (confirm(message)) form.submit();
                return false;
            }

            function toggleFulfillForm(orderId) {
                const el = document.getElementById('fulfill-' + orderId);
                el.classList.toggle('hidden');
            }

            function showSettingsPanel(btn, key) {
                document.querySelectorAll('.settings-subtabs .subtab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== key));
            }

            async function markNotificationsRead() {
                document.querySelector('#headerNotifBtn .notif-badge')?.remove();
                document.querySelectorAll('#section-notifications .notif-item.unread').forEach(el => {
                    el.classList.remove('unread');
                });
                const notifSettingsSub = document.querySelector('.settings-item[onclick*="notifications"] .sub');
                if (notifSettingsSub) notifSettingsSub.textContent = 'لا توجد إشعارات جديدة';
                try {
                    await fetch('index.php?ajax=mark_notifications_read', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN }),
                    });
                } catch (err) {
                    // تجاهل أخطاء الشبكة هنا، ستظهر الإشعارات كمقروءة بعد إعادة تحميل لاحقة
                }
            }

            async function deleteNotification(id) {
                const item = document.querySelector(`#section-notifications .notif-item[data-notif-id="${id}"]`);
                item?.remove();
                updateNotifEmptyState();
                try {
                    await fetch('index.php?ajax=delete_notification', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN, id }),
                    });
                } catch (err) {
                    // تجاهل أخطاء الشبكة، سيُعاد تحميلها لاحقاً
                }
            }

            async function deleteAllNotifications() {
                document.querySelectorAll('#section-notifications .notif-item').forEach(el => el.remove());
                document.querySelector('.notif-actions-row')?.remove();
                document.querySelector('#headerNotifBtn .notif-badge')?.remove();
                updateNotifEmptyState();
                try {
                    await fetch('index.php?ajax=delete_all_notifications', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN }),
                    });
                } catch (err) {
                    // تجاهل أخطاء الشبكة، سيُعاد تحميلها لاحقاً
                }
            }

            function updateNotifEmptyState() {
                const list = document.getElementById('notificationsListCard');
                if (list && !list.querySelector('.notif-item')) {
                    list.outerHTML = '<div class="text-muted text-center" style="padding:40px 0" id="noNotificationsMsg">📭 لا توجد إشعارات حالياً</div>';
                    document.querySelector('.notif-actions-row')?.remove();
                }
            }
            
            // ============================================================
            // تفاصيل الاستضافة
            // ============================================================
            function showHostingDetail(id) {
                const hosting = HOSTING.find(h => Number(h.id) === Number(id));
                if (!hosting) return;

                detailReturnSection = document.getElementById('section-servers').classList.contains('hidden') ? 'home' : 'servers';

                const statusText = (hosting.status === 'active' ? t('active') : t('expired')) + (hosting.status === 'active' ? ' ✅' : ' ❌');
                const statusClass = hosting.status === 'active' ? 'pill-green' : 'pill-red';
                const isExpired = hosting.status === 'expired';

                document.getElementById('hostingDetailContent').innerHTML = `
                    <div class="detail-row">
                        <span class="label">${t('server_id')}</span>
                        <span class="value" style="direction:ltr">#${hosting.vps_id || hosting.id}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">${t('hosting_name')}</span>
                        <span class="value">${hosting.name}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">${t('plan_label')}</span>
                        <span class="value">${hosting.plan}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">${t('ip_address')}</span>
                        <span class="value">
                            ${hosting.ip}
                            <button class="copy-btn" onclick="copyText('${hosting.ip}')" title="نسخ"><i class="fas fa-copy"></i></button>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="label">${t('username')}</span>
                        <span class="value">
                            ${hosting.username}
                            <button class="copy-btn" onclick="copyText('${hosting.username}')" title="نسخ"><i class="fas fa-copy"></i></button>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="label">${t('password')}</span>
                        <span class="value password">
                            ${hosting.password}
                            <button class="copy-btn" onclick="copyText('${hosting.password}')" title="نسخ"><i class="fas fa-copy"></i></button>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="label">${t('status')}</span>
                        <span class="value"><span class="pill ${statusClass}">${statusText}</span></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">${t('expiry_date')}</span>
                        <span class="value">${hosting.expiry_date}</span>
                    </div>
                    ${isExpired && hosting.pending_renewal ? `
                    <div class="form-alert-inline" style="text-align:center"><i class="fas fa-clock"></i> ${t('renewal_pending_msg')}</div>
                    ` : ''}
                    ${isExpired && !hosting.pending_renewal ? `
                    <button class="btn-renew" onclick="renewHosting(${hosting.id})">
                        <i class="fas fa-sync"></i> ${t('renew_hosting')}
                    </button>
                    ` : ''}
                `;
                
                // إخفاء القسم الحالي وإظهار التفاصيل
                document.getElementById('section-' + detailReturnSection).classList.add('hidden');
                document.getElementById('section-hosting-detail').classList.remove('hidden');

                // تحديث التنقل
                document.querySelectorAll('#mainBottomNav .nav-item').forEach(el => {
                    el.classList.remove('active');
                });
            }

            function hideHostingDetail() {
                document.getElementById('section-hosting-detail').classList.add('hidden');
                document.getElementById('section-' + detailReturnSection).classList.remove('hidden');

                document.querySelectorAll('#mainBottomNav .nav-item').forEach(el => {
                    el.classList.remove('active');
                    if (el.dataset.section === detailReturnSection) {
                        el.classList.add('active');
                    }
                });
            }
            
            function copyText(text) {
                navigator.clipboard.writeText(text).then(function() {
                    // إظهار رسالة短暂ة
                    const btn = event.target.closest('.copy-btn');
                    if (btn) {
                        const original = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-check" style="color:#34d399"></i>';
                        setTimeout(function() {
                            btn.innerHTML = original;
                        }, 1500);
                    }
                }).catch(function() {
                    // طريقة بديلة للنسخ
                    const input = document.createElement('input');
                    input.value = text;
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    document.body.removeChild(input);
                    alert('✅ تم نسخ النص!');
                });
            }

            function copyReferralLink() {
                const input = document.getElementById('referralLinkInput');
                const btn = document.getElementById('referralCopyBtn');
                const finish = () => {
                    const original = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check" style="color:#34d399"></i>';
                    setTimeout(function() { btn.innerHTML = original; }, 1500);
                };
                navigator.clipboard.writeText(input.value).then(finish).catch(function() {
                    input.select();
                    document.execCommand('copy');
                    finish();
                });
            }

            function shareReferralLink() {
                const link = document.getElementById('referralLinkInput').value;
                const text = 'سجّل عبر رابطي في استضافتي واحصل على خصم على أول طلب VPS!';
                if (navigator.share) {
                    navigator.share({ title: 'استضافتي', text: text, url: link }).catch(function() {});
                } else {
                    copyReferralLink();
                }
            }

            async function renewHosting(id) {
                if (!confirm('سيتم خصم قيمة الباقة من رصيدك وإرسال طلب تجديد بانتظار موافقة الإدارة. هل تريد المتابعة؟')) return;
                try {
                    const res = await fetch('index.php?ajax=request_renewal', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN, hosting_id: id }),
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) {
                        alert('❌ ' + (data.error || 'تعذر إرسال طلب التجديد.'));
                        return;
                    }
                    alert('✅ ' + data.message);
                    location.reload();
                } catch (err) {
                    alert('❌ تعذر الاتصال بالسيرفر. حاول مرة أخرى.');
                }
            }
            
            // ============================================================
            // البحث في السيرفرات
            // ============================================================
            function filterServers() {
                const query = (document.getElementById('serverSearchInput').value || '').trim().toLowerCase();
                const items = document.querySelectorAll('#serversListContent .server-list-item');
                let visibleCount = 0;
                items.forEach(item => {
                    const matches = item.dataset.name.includes(query);
                    item.classList.toggle('hidden', !matches);
                    if (matches) visibleCount++;
                });
                document.getElementById('noServerResults').classList.toggle('hidden', visibleCount > 0);
            }

            // ============================================================
            // معالج طلب VPS
            // ============================================================
            let wizardState = { planId: null, billingCycle: 'monthly' };

            function planDiscountPct(plan) {
                const original = Number(plan.original_price) || 0;
                const price = Number(plan.price) || 0;
                if (original <= price) return null;
                return Math.round(((original - price) / original) * 100);
            }

            function planPriceForCycle(plan) {
                const result = {
                    price: Number(plan.price),
                    suffix: plan.billing_cycle === 'yearly' ? '/سنة' : '/شهر',
                    discountPct: planDiscountPct(plan),
                    original: Number(plan.original_price) || null,
                };
                result.referralApplied = false;
                if (REFERRAL_ELIGIBLE && REFERRAL_DISCOUNT_PCT > 0) {
                    result.price = Math.round(result.price * (1 - REFERRAL_DISCOUNT_PCT / 100) * 100) / 100;
                    result.referralApplied = true;
                }
                return result;
            }

            function wizardSetBillingCycle(cycle) {
                wizardState.billingCycle = cycle;
                document.getElementById('orderBillingCycle').value = cycle;
                document.getElementById('billingTabMonthly').classList.toggle('active', cycle === 'monthly');
                document.getElementById('billingTabYearly').classList.toggle('active', cycle === 'yearly');
                renderPlanList();
            }

            function renderPlanList() {
                const filtered = VPS_PLANS.filter(plan => (plan.billing_cycle || 'monthly') === wizardState.billingCycle);
                if (!filtered.length) {
                    document.getElementById('planListContent').innerHTML = `<div class="text-muted text-center" style="padding:24px 0;font-size:12px">📭 لا توجد باقات ${wizardState.billingCycle === 'yearly' ? 'سنوية' : 'شهرية'} متاحة حالياً</div>`;
                    return;
                }
                document.getElementById('planListContent').innerHTML = filtered.map(plan => {
                    const p = planPriceForCycle(plan);
                    return `
                    <div class="plan-select-item ${wizardState.planId === Number(plan.id) ? 'selected' : ''}" onclick="wizardSelectPlan(${plan.id})">
                        <div class="radio-circle ${wizardState.planId === Number(plan.id) ? 'checked' : ''}"><i class="fas fa-check"></i></div>
                        <div class="info">
                            <div class="top-row">
                                <span class="plan-title">${planIconHtml(plan.icon, plan.icon_image)} ${plan.name}</span>
                                <span class="plan-price">${p.discountPct ? `<s style="font-size:11px;font-weight:600;color:var(--text-muted)" data-usd="${p.original}">${p.original}$</s> ` : ''}<span data-usd="${p.price}">${p.price}$</span><small style="font-size:10px;font-weight:600;color:var(--text-muted)">${p.suffix}</small></span>
                            </div>
                            <div class="plan-meta">${plan.cpu} · ${plan.ram} RAM · ${plan.storage}</div>
                            ${plan.badge ? `<span class="pill pill-gold" style="margin-top:6px">${plan.badge}</span>` : ''}
                            ${p.discountPct ? `<span class="pill" style="margin-top:6px;margin-right:4px;background:rgba(239,68,68,.12);color:#ef4444">خصم ${p.discountPct}%</span>` : ''}
                        </div>
                    </div>
                `;
                }).join('');
                applyCurrencyDisplay(document.getElementById('planListContent'));
            }

            function wizardSelectPlan(planId) {
                wizardState.planId = Number(planId);
                const plan = currentPlan();
                if (plan) {
                    wizardState.billingCycle = plan.billing_cycle === 'yearly' ? 'yearly' : 'monthly';
                    document.getElementById('billingTabMonthly')?.classList.toggle('active', wizardState.billingCycle === 'monthly');
                    document.getElementById('billingTabYearly')?.classList.toggle('active', wizardState.billingCycle === 'yearly');
                }
                renderPlanList();
                document.getElementById('planContinueBtn').disabled = false;
            }

            function currentPlan() {
                return VPS_PLANS.find(p => Number(p.id) === wizardState.planId);
            }

            function renderPlanDetails() {
                const plan = currentPlan();
                if (!plan) return;
                document.getElementById('planDetailsIcon').innerHTML = planIconHtml(plan.icon, plan.icon_image, 42);
                document.getElementById('planDetailsName').textContent = plan.name;
                const p = planPriceForCycle(plan);
                document.getElementById('planDetailsPrice').innerHTML = `${p.discountPct ? `<s style="font-size:16px;color:var(--text-muted);margin-left:6px" data-usd="${p.original}">${p.original}$</s>` : ''}<span data-usd="${p.price}">${p.price}$</span> <small style="font-size:13px;color:var(--text-muted)">${p.suffix}</small>${p.referralApplied ? `<div style="font-size:11px;font-weight:700;color:#059669;margin-top:4px"><i class="fas fa-gift"></i> يشمل خصم دعوة ${REFERRAL_DISCOUNT_PCT}%</div>` : ''}`;
                document.getElementById('planDetailsSpecs').innerHTML = `
                    <div class="detail-row"><span class="label">${t('cpu')}</span><span class="value">${plan.cpu}</span></div>
                    <div class="detail-row"><span class="label">${t('ram')}</span><span class="value">${plan.ram}</span></div>
                    <div class="detail-row"><span class="label">${t('storage')}</span><span class="value">${plan.storage}</span></div>
                    <div class="detail-row"><span class="label">${t('bandwidth')}</span><span class="value">${plan.bandwidth}</span></div>
                    <div class="detail-row"><span class="label">${t('os')}</span><span class="value">Ubuntu 22.04</span></div>
                    <div class="detail-row"><span class="label">${t('server_location')}</span><span class="value">Frankfurt, Germany</span></div>
                `;
                applyCurrencyDisplay(document.getElementById('planDetailsPrice'));
            }

            function renderOrderSummary() {
                const plan = currentPlan();
                if (!plan) return;
                const p = planPriceForCycle(plan);
                const cycleLabel = wizardState.billingCycle === 'yearly' ? t('yearly') : t('monthly');
                document.getElementById('orderSummaryContent').innerHTML = `
                    <div class="detail-row"><span class="label">${t('plan_label')}</span><span class="value">${planIconHtml(plan.icon, plan.icon_image)} ${plan.name}</span></div>
                    <div class="detail-row"><span class="label">${t('server_location')}</span><span class="value">Frankfurt, Germany</span></div>
                    <div class="detail-row"><span class="label">${t('os')}</span><span class="value">Ubuntu 22.04</span></div>
                    <div class="detail-row"><span class="label">${t('subscription_duration')}</span><span class="value">${cycleLabel}</span></div>
                    ${p.referralApplied ? `<div class="detail-row"><span class="label"><i class="fas fa-gift"></i> خصم دعوة صديق</span><span class="value" style="color:#059669">${REFERRAL_DISCOUNT_PCT}%</span></div>` : ''}
                    <div class="detail-row"><span class="label">${t('price')}</span><span class="value" data-usd="${p.price}">${p.price}$${p.suffix}</span></div>
                `;
                document.getElementById('paymentTotalAmount').setAttribute('data-usd', p.price);
                document.getElementById('paymentTotalAmount').textContent = p.price + '$';
                applyCurrencyDisplay(document.getElementById('orderSummaryContent'));
                applyCurrencyDisplay(document.getElementById('vpsStepPayment'));
            }

            function renderPayOptions() {
                // طرق الدفع اليدوي تظهر فقط لشحن الرصيد من قسم الفواتير، وليست لدفع طلب مباشرة
                const options = PAYMENT_METHODS.filter(pm => pm.method_type !== 'manual').map(pm => ({
                    id: String(pm.id),
                    icon: pm.icon,
                    color: pm.color,
                    logo: pm.logo_path,
                    title: pm.name,
                    sub: 'تحقق تلقائي فوري',
                    type: pm.method_type,
                    manual: false,
                    binance: pm.method_type === 'binance',
                    asiacell: pm.method_type === 'asiacell',
                    account_number: pm.account_number,
                    instructions: pm.instructions,
                    binanceId: pm.binance_id,
                    qrCode: pm.qr_code,
                    exchangeRate: pm.exchange_rate,
                }));
                options.push({
                    id: 'balance', icon: 'fa-wallet', color: 'green', logo: null, title: 'رصيد الحساب',
                    sub: formatUsd(USER_BALANCE) + ' متاح', manual: false, binance: false,
                });

                if (!wizardState.paymentMethod) wizardState.paymentMethod = options[0].id;

                document.getElementById('payOptionsContent').innerHTML = options.map(opt => `
                    <div class="pay-option ${wizardState.paymentMethod === opt.id ? 'selected' : ''}" onclick="wizardSelectPayment('${opt.id}')">
                        ${opt.logo ? `<div class="pm-logo-wrap"><img src="${opt.logo}" alt=""></div>` : `<div class="icon-wrap ${opt.color}"><i class="fas ${opt.icon}"></i></div>`}
                        <div style="flex:1">
                            <div class="title">${opt.title}</div>
                            <div class="sub">${opt.sub}</div>
                        </div>
                        <div class="radio-circle ${wizardState.paymentMethod === opt.id ? 'checked' : ''}"><i class="fas fa-check"></i></div>
                    </div>
                `).join('');

                const plan = currentPlan();
                document.getElementById('orderPlanId').value = plan ? plan.id : '';
                document.getElementById('orderPaymentMethodId').value = wizardState.paymentMethod;

                const selected = options.find(o => o.id === wizardState.paymentMethod);
                const uploadWrap = document.getElementById('proofUploadWrap');
                const proofInput = document.getElementById('proofImageInput');
                const binanceWrap = document.getElementById('binanceOrderIdWrap');
                const asiacellWrap = document.getElementById('asiacellWrap');
                const submitBtn = document.getElementById('orderSubmitBtn');
                const submitBtnLabel = document.querySelector('#orderSubmitBtn span');

                uploadWrap.classList.add('hidden');
                proofInput.required = false;
                binanceWrap.classList.add('hidden');
                asiacellWrap.classList.add('hidden');
                submitBtn.classList.remove('hidden');

                if (selected && selected.manual) {
                    uploadWrap.classList.remove('hidden');
                    proofInput.required = true;
                    document.getElementById('payInstructionsBox').innerHTML = `
                        <div class="detail-row"><span class="label">حوّل إلى</span><span class="value">${selected.account_number || '-'}</span></div>
                        ${selected.instructions ? `<div class="detail-row"><span class="label">ملاحظة</span><span class="value" style="direction:rtl;text-align:right;font-weight:400;font-size:12px">${selected.instructions}</span></div>` : ''}
                    `;
                    if (submitBtnLabel) submitBtnLabel.textContent = t('pay_now');
                } else if (selected && selected.binance) {
                    const wasHidden = binanceWrap.classList.contains('hidden');
                    binanceWrap.classList.remove('hidden');
                    submitBtn.classList.add('hidden');
                    if (wasHidden) binanceShowStep('order', 'info');
                    document.getElementById('binanceOrderError').classList.add('hidden');
                    const plan = currentPlan();
                    const amountUsd = plan ? planPriceForCycle(plan).price : 0;
                    document.getElementById('binancePayInfo').innerHTML = `
                        <div class="hosting-detail">
                            <div class="detail-row"><span class="label">المبلغ المطلوب</span><span class="value">${formatUsd(amountUsd)}</span></div>
                            <div class="detail-row"><span class="label">الدفع عبر</span><span class="value">Binance Pay</span></div>
                            ${selected.binanceId ? `<div class="detail-row"><span class="label">Binance Pay ID</span><span class="value" style="direction:ltr">${selected.binanceId}</span></div>` : ''}
                        </div>
                        ${selected.qrCode ? `<div style="text-align:center;margin-top:10px"><img src="${selected.qrCode}" alt="QR" style="width:150px;height:150px;object-fit:contain;border-radius:var(--radius-sm);border:1px solid var(--border-color)"></div>` : ''}
                    `;
                } else if (selected && selected.asiacell) {
                    const wasHidden = asiacellWrap.classList.contains('hidden');
                    asiacellWrap.classList.remove('hidden');
                    submitBtn.classList.add('hidden');
                    if (wasHidden) asiacellShowStep('order', 'phone');
                    const plan = currentPlan();
                    const amountUsd = plan ? planPriceForCycle(plan).price : 0;
                    const rate = parseFloat(selected.exchangeRate) || 0;
                    document.getElementById('asiacellPayInfo').innerHTML = `
                        <div class="hosting-detail">
                            <div class="detail-row"><span class="label">الدفع عبر</span><span class="value">آسياسيل</span></div>
                            ${rate ? `<div class="detail-row"><span class="label">المبلغ بالدينار العراقي</span><span class="value" style="direction:ltr">${Math.round(amountUsd * rate).toLocaleString()} د.ع</span></div>` : ''}
                        </div>
                    `;
                } else if (submitBtnLabel) {
                    submitBtnLabel.textContent = t('pay_now');
                }
            }

            function wizardSelectPayment(id) {
                wizardState.paymentMethod = id;
                renderPayOptions();
                checkBalanceSufficiency();
            }

            function isBinanceMethodSelected() {
                const pm = PAYMENT_METHODS.find(p => String(p.id) === wizardState.paymentMethod);
                return !!(pm && pm.method_type === 'binance');
            }

            function isAsiacellMethodSelected() {
                const pm = PAYMENT_METHODS.find(p => String(p.id) === wizardState.paymentMethod);
                return !!(pm && pm.method_type === 'asiacell');
            }

            function handleOrderFormSubmit(event) {
                if (isAsiacellMethodSelected()) {
                    event.preventDefault();
                    return false;
                }
                if (!isBinanceMethodSelected()) return true;
                event.preventDefault();
                submitBinanceOrder();
                return false;
            }

            async function submitBinanceOrder() {
                binanceShowStep('order', 'confirm');
                const errEl = document.getElementById('binanceOrderError');
                const submitBtn = document.getElementById('binanceOrderSubmitBtn');
                const binanceOrderId = document.getElementById('binanceOrderIdInput').value.trim();
                if (!binanceOrderId) {
                    errEl.textContent = 'الرجاء إدخال رقم عملية Binance.';
                    errEl.classList.remove('hidden');
                    return;
                }

                errEl.classList.add('hidden');
                submitBtn.disabled = true;
                try {
                    const res = await fetch('index.php?ajax=verify_binance_order', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            csrf_token: CSRF_TOKEN,
                            plan_id: document.getElementById('orderPlanId').value,
                            payment_method_id: document.getElementById('orderPaymentMethodId').value,
                            binance_order_id: binanceOrderId,
                        }),
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) {
                        errEl.textContent = data.error || 'تعذر التحقق من عملية الدفع.';
                        errEl.classList.remove('hidden');
                        submitBtn.disabled = false;
                        return;
                    }
                    location.href = 'index.php?app=1&ordered=1&order_id=' + data.order_id;
                } catch (err) {
                    errEl.textContent = 'تعذر الاتصال بالسيرفر. حاول مرة أخرى.';
                    errEl.classList.remove('hidden');
                    submitBtn.disabled = false;
                }
            }

            function checkBalanceSufficiency() {
                const warningEl = document.getElementById('balanceInsufficientWarning');
                const submitBtn = document.getElementById('orderSubmitBtn');
                const plan = currentPlan();
                if (!warningEl || !submitBtn || !plan) return;

                if (wizardState.paymentMethod !== 'balance') {
                    warningEl.classList.add('hidden');
                    submitBtn.disabled = false;
                    return;
                }
                const insufficient = USER_BALANCE < planPriceForCycle(plan).price;
                warningEl.classList.toggle('hidden', !insufficient);
                submitBtn.disabled = insufficient;
            }

            function wizardGoTo(step) {
                let bounced = false;
                if ((step === 'details' || step === 'summary' || step === 'payment') && !currentPlan()) {
                    step = 'plan';
                    bounced = true;
                }

                document.querySelectorAll('#section-vps .wizard-step').forEach(el => el.classList.add('hidden'));
                document.getElementById('vpsStep' + step.charAt(0).toUpperCase() + step.slice(1)).classList.remove('hidden');

                if (step === 'plan') {
                    wizardState = { planId: null, paymentMethod: null, billingCycle: 'monthly' };
                    document.getElementById('billingTabMonthly')?.classList.add('active');
                    document.getElementById('billingTabYearly')?.classList.remove('active');
                    renderPlanList();
                    document.getElementById('planContinueBtn').disabled = true;
                    document.getElementById('planUnavailableAlert')?.classList.toggle('hidden', !bounced);
                } else if (step === 'details') {
                    renderPlanDetails();
                } else if (step === 'summary') {
                    renderOrderSummary();
                } else if (step === 'payment') {
                    renderPayOptions();
                    renderOrderSummary();
                    checkBalanceSufficiency();
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            // ============================================================
            // الفواتير
            // ============================================================
            function showAddBalance() {
                document.getElementById('addBalanceSection').classList.remove('hidden');
                document.getElementById('invoicesList').classList.add('hidden');
                document.getElementById('invoiceDetail').classList.add('hidden');
                document.getElementById('paymentPage').classList.add('hidden');
            }
            
            function hideAddBalance() {
                document.getElementById('addBalanceSection').classList.add('hidden');
                document.getElementById('invoicesList').classList.remove('hidden');
            }
            
            let topUpManualExchangeRate = 0;

            function showPaymentPage(methodId, methodName, accountNumber, instructions, methodType, binanceId, qrCode, exchangeRate) {
                document.getElementById('paymentMethodName').textContent = 'شحن عبر ' + methodName;
                document.getElementById('topUpPaymentMethodId').value = methodId;
                document.getElementById('topUpInstructions').innerHTML = `
                    <div class="detail-row"><span class="label">حوّل إلى</span><span class="value">${accountNumber || '-'}</span></div>
                ` + (instructions ? `<div class="detail-row"><span class="label">ملاحظة</span><span class="value" style="direction:rtl;text-align:right;font-weight:400;font-size:12px">${instructions}</span></div>` : '');
                document.getElementById('paymentPage').classList.remove('hidden');
                document.getElementById('addBalanceSection').classList.add('hidden');

                const code = detectCurrencyCode();
                const cur = CURRENCIES[code];
                document.getElementById('topUpCurrencyLabel').textContent = cur ? (code + ' ' + cur.symbol) : '$';
                document.getElementById('topUpAmountInput').value = '';
                document.getElementById('topUpAmountUsd').value = '';
                document.getElementById('topUpUsdHint').textContent = '';
                topUpManualExchangeRate = methodType === 'manual' ? (parseFloat(exchangeRate) || 0) : 0;
                document.getElementById('topUpManualIqdHint').textContent = '';

                const isBinance = methodType === 'binance';
                const isAsiacell = methodType === 'asiacell';
                const proofWrap = document.getElementById('topUpProofWrap');
                const proofInput = document.getElementById('topUpProofInput');
                const binanceWrap = document.getElementById('topUpBinanceWrap');
                const asiacellWrap = document.getElementById('topUpAsiacellWrap');
                const submitBtn = document.querySelector('#paymentPage button[type="submit"]');
                proofWrap.classList.toggle('hidden', isBinance || isAsiacell);
                proofInput.required = !isBinance && !isAsiacell;
                binanceWrap.classList.toggle('hidden', !isBinance);
                asiacellWrap.classList.toggle('hidden', !isAsiacell);
                if (submitBtn) submitBtn.classList.toggle('hidden', isAsiacell || isBinance);
                document.getElementById('topUpInstructions').classList.toggle('hidden', isBinance || isAsiacell);
                document.getElementById('topUpBinanceOrderIdInput').value = '';
                document.getElementById('topUpBinanceError').classList.add('hidden');
                document.getElementById('topUpBinanceAmountError').classList.add('hidden');
                if (isBinance) {
                    topUpBinanceState = { binanceId: binanceId, qrCode: qrCode };
                    binanceShowStep('topup', 'info');
                    updateTopUpBinancePayInfo();
                }
                if (isAsiacell) {
                    asiacellShowStep('topup', 'phone');
                    document.getElementById('topUpAsiacellPhoneInput').value = '';
                    document.getElementById('topUpAsiacellSms1Input').value = '';
                    document.getElementById('topUpAsiacellSms2Input').value = '';
                    ['topUpAsiacellPhoneError', 'topUpAsiacellSms1Error', 'topUpAsiacellSms2Error'].forEach(id => document.getElementById(id).classList.add('hidden'));
                    document.getElementById('topUpAsiacellPayInfo').innerHTML = `<div class="hosting-detail"><div class="detail-row"><span class="label">الدفع عبر</span><span class="value">آسياسيل</span></div></div>`;
                }
                document.getElementById('topUpFooterNote').textContent = (isBinance || isAsiacell)
                    ? 'سيُضاف الرصيد فوراً بعد التحقق التلقائي من عملية الدفع.'
                    : 'سيتم إضافة الرصيد بعد مراجعة الإيصال من الإدارة.';
            }

            function hidePaymentPage() {
                document.getElementById('paymentPage').classList.add('hidden');
                document.getElementById('addBalanceSection').classList.remove('hidden');
            }

            function handleTopUpFormSubmit(event) {
                syncTopUpAmountUsd();
                const pm = PAYMENT_METHODS.find(p => String(p.id) === document.getElementById('topUpPaymentMethodId').value);
                if (pm && pm.method_type === 'asiacell') {
                    event.preventDefault();
                    return false;
                }
                if (!pm || pm.method_type !== 'binance') return true;
                event.preventDefault();
                submitBinanceTopup();
                return false;
            }

            async function submitBinanceTopup() {
                if (!(parseFloat(document.getElementById('topUpAmountUsd').value) > 0)) {
                    binanceGoToConfirm('topup');
                    return;
                }
                binanceShowStep('topup', 'confirm');
                const errEl = document.getElementById('topUpBinanceError');
                const submitBtn = document.getElementById('topUpBinanceSubmitBtn');
                const amount = document.getElementById('topUpAmountUsd').value;
                const binanceOrderId = document.getElementById('topUpBinanceOrderIdInput').value.trim();

                if (!(parseFloat(amount) > 0)) {
                    errEl.textContent = 'الرجاء إدخال مبلغ صحيح.';
                    errEl.classList.remove('hidden');
                    return;
                }
                if (!binanceOrderId) {
                    errEl.textContent = 'الرجاء إدخال رقم عملية Binance.';
                    errEl.classList.remove('hidden');
                    return;
                }

                errEl.classList.add('hidden');
                if (submitBtn) submitBtn.disabled = true;
                try {
                    const res = await fetch('index.php?ajax=verify_binance_topup', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            csrf_token: CSRF_TOKEN,
                            payment_method_id: document.getElementById('topUpPaymentMethodId').value,
                            amount: amount,
                            binance_order_id: binanceOrderId,
                        }),
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) {
                        errEl.textContent = data.error || 'تعذر التحقق من عملية الدفع.';
                        errEl.classList.remove('hidden');
                        if (submitBtn) submitBtn.disabled = false;
                        return;
                    }
                    location.href = 'index.php?app=1&topup=1';
                } catch (err) {
                    errEl.textContent = 'تعذر الاتصال بالسيرفر. حاول مرة أخرى.';
                    errEl.classList.remove('hidden');
                    if (submitBtn) submitBtn.disabled = false;
                }
            }

            // ============================================================
            // Binance Pay - تدفق خطوتين: معلومات الدفع (المبلغ+الباركود+الآيدي) -> إدخال معرف العملية للتأكيد
            // ============================================================
            let topUpBinanceState = { binanceId: '', qrCode: '' };

            const BINANCE_IDS = {
                order: { stepInfo: 'binanceStepInfo', stepConfirm: 'binanceStepConfirm' },
                topup: { stepInfo: 'topUpBinanceStepInfo', stepConfirm: 'topUpBinanceStepConfirm' },
            };

            function binanceShowStep(ctx, step) {
                const ids = BINANCE_IDS[ctx];
                document.getElementById(ids.stepInfo).classList.toggle('hidden', step !== 'info');
                document.getElementById(ids.stepConfirm).classList.toggle('hidden', step !== 'confirm');
            }

            function updateTopUpBinancePayInfo() {
                const amountUsd = parseFloat(document.getElementById('topUpAmountUsd').value) || 0;
                const { binanceId, qrCode } = topUpBinanceState;
                document.getElementById('topUpBinancePayInfo').innerHTML = `
                    <div class="hosting-detail">
                        <div class="detail-row"><span class="label">المبلغ المطلوب</span><span class="value">${formatUsd(amountUsd)}</span></div>
                        <div class="detail-row"><span class="label">الدفع عبر</span><span class="value">Binance Pay</span></div>
                        ${binanceId ? `<div class="detail-row"><span class="label">Binance Pay ID</span><span class="value" style="direction:ltr">${binanceId}</span></div>` : ''}
                    </div>
                    ${qrCode ? `<div style="text-align:center;margin-top:10px"><img src="${qrCode}" alt="QR" style="width:150px;height:150px;object-fit:contain;border-radius:var(--radius-sm);border:1px solid var(--border-color)"></div>` : ''}
                `;
            }

            function binanceGoToConfirm(ctx) {
                if (ctx === 'topup') {
                    const amount = parseFloat(document.getElementById('topUpAmountUsd').value) || 0;
                    const errEl = document.getElementById('topUpBinanceAmountError');
                    if (!(amount > 0)) {
                        errEl.textContent = 'الرجاء إدخال مبلغ صحيح قبل المتابعة.';
                        errEl.classList.remove('hidden');
                        return;
                    }
                    errEl.classList.add('hidden');
                }
                binanceShowStep(ctx, 'confirm');
            }

            // ============================================================
            // آسياسيل - تحويل رصيد تلقائي (تدفق ثلاث خطوات: هاتف -> رمز SMS -> رمز تأكيد التحويل)
            // ============================================================
            const ASIACELL_IDS = {
                order: {
                    wrap: 'asiacellWrap', payInfo: 'asiacellPayInfo',
                    stepPhone: 'asiacellStepPhone', phoneInput: 'asiacellPhoneInput', phoneError: 'asiacellPhoneError', sendBtn: 'asiacellSendCodeBtn',
                    stepSms1: 'asiacellStepSms1', sms1Input: 'asiacellSms1Input', sms1Error: 'asiacellSms1Error', verifyBtn: 'asiacellVerifySmsBtn',
                    stepSms2: 'asiacellStepSms2', sms2Input: 'asiacellSms2Input', sms2Error: 'asiacellSms2Error', confirmBtn: 'asiacellConfirmBtn',
                },
                topup: {
                    wrap: 'topUpAsiacellWrap', payInfo: 'topUpAsiacellPayInfo',
                    stepPhone: 'topUpAsiacellStepPhone', phoneInput: 'topUpAsiacellPhoneInput', phoneError: 'topUpAsiacellPhoneError', sendBtn: 'topUpAsiacellSendCodeBtn',
                    stepSms1: 'topUpAsiacellStepSms1', sms1Input: 'topUpAsiacellSms1Input', sms1Error: 'topUpAsiacellSms1Error', verifyBtn: 'topUpAsiacellVerifySmsBtn',
                    stepSms2: 'topUpAsiacellStepSms2', sms2Input: 'topUpAsiacellSms2Input', sms2Error: 'topUpAsiacellSms2Error', confirmBtn: 'topUpAsiacellConfirmBtn',
                },
            };

            function asiacellShowStep(ctx, step) {
                const ids = ASIACELL_IDS[ctx];
                document.getElementById(ids.stepPhone).classList.toggle('hidden', step !== 'phone');
                document.getElementById(ids.stepSms1).classList.toggle('hidden', step !== 'sms1');
                document.getElementById(ids.stepSms2).classList.toggle('hidden', step !== 'sms2');
            }

            function asiacellReset(ctx) {
                fetch('index.php?ajax=asiacell_cancel', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: CSRF_TOKEN }),
                }).catch(() => {});
                const ids = ASIACELL_IDS[ctx];
                document.getElementById(ids.phoneInput).value = '';
                document.getElementById(ids.sms1Input).value = '';
                document.getElementById(ids.sms2Input).value = '';
                [ids.phoneError, ids.sms1Error, ids.sms2Error].forEach(id => document.getElementById(id).classList.add('hidden'));
                asiacellShowStep(ctx, 'phone');
            }

            async function asiacellSendCode(ctx) {
                const ids = ASIACELL_IDS[ctx];
                const errEl = document.getElementById(ids.phoneError);
                const phone = document.getElementById(ids.phoneInput).value.trim();
                if (!/^(077|078|079)\d{8}$/.test(phone)) {
                    errEl.textContent = 'رقم هاتف غير صحيح، يجب أن يكون بصيغة 07xxxxxxxxx.';
                    errEl.classList.remove('hidden');
                    return;
                }
                errEl.classList.add('hidden');
                const btn = document.getElementById(ids.sendBtn);
                btn.disabled = true;
                try {
                    const payload = { csrf_token: CSRF_TOKEN, phone: phone, context: ctx };
                    if (ctx === 'order') {
                        payload.payment_method_id = document.getElementById('orderPaymentMethodId').value;
                        payload.plan_id = document.getElementById('orderPlanId').value;
                    } else {
                        syncTopUpAmountUsd();
                        payload.payment_method_id = document.getElementById('topUpPaymentMethodId').value;
                        payload.amount = document.getElementById('topUpAmountUsd').value;
                    }
                    const res = await fetch('index.php?ajax=asiacell_start', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) {
                        errEl.textContent = data.error || 'تعذر إرسال رمز التحقق.';
                        errEl.classList.remove('hidden');
                        btn.disabled = false;
                        return;
                    }
                    asiacellShowStep(ctx, 'sms1');
                } catch (err) {
                    errEl.textContent = 'تعذر الاتصال بالسيرفر. حاول مرة أخرى.';
                    errEl.classList.remove('hidden');
                }
                btn.disabled = false;
            }

            async function asiacellVerifySms(ctx) {
                const ids = ASIACELL_IDS[ctx];
                const errEl = document.getElementById(ids.sms1Error);
                const code = document.getElementById(ids.sms1Input).value.trim();
                if (!/^\d{4,6}$/.test(code)) {
                    errEl.textContent = 'رمز التحقق يجب أن يكون من 4 إلى 6 أرقام.';
                    errEl.classList.remove('hidden');
                    return;
                }
                errEl.classList.add('hidden');
                const btn = document.getElementById(ids.verifyBtn);
                btn.disabled = true;
                try {
                    const res = await fetch('index.php?ajax=asiacell_verify_sms', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN, code: code }),
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) {
                        errEl.textContent = data.error || 'رمز التحقق غير صحيح.';
                        errEl.classList.remove('hidden');
                        btn.disabled = false;
                        return;
                    }
                    const payInfoEl = document.getElementById(ids.payInfo);
                    if (payInfoEl && data.total) {
                        payInfoEl.innerHTML += `<div class="hosting-detail" style="margin-top:8px"><div class="detail-row"><span class="label">سيتم تحويل الآن</span><span class="value" style="direction:ltr">${Number(data.chunk_amount).toLocaleString()} د.ع</span></div>${data.total > data.chunk_amount ? `<div class="detail-row"><span class="label">من إجمالي</span><span class="value" style="direction:ltr">${Number(data.total).toLocaleString()} د.ع</span></div>` : ''}</div>`;
                    }
                    asiacellShowStep(ctx, 'sms2');
                } catch (err) {
                    errEl.textContent = 'تعذر الاتصال بالسيرفر. حاول مرة أخرى.';
                    errEl.classList.remove('hidden');
                }
                btn.disabled = false;
            }

            async function asiacellConfirmTransfer(ctx) {
                const ids = ASIACELL_IDS[ctx];
                const errEl = document.getElementById(ids.sms2Error);
                const code = document.getElementById(ids.sms2Input).value.trim();
                if (!/^\d{4,6}$/.test(code)) {
                    errEl.textContent = 'رمز التأكيد يجب أن يكون من 4 إلى 6 أرقام.';
                    errEl.classList.remove('hidden');
                    return;
                }
                errEl.classList.add('hidden');
                const btn = document.getElementById(ids.confirmBtn);
                btn.disabled = true;
                try {
                    const res = await fetch('index.php?ajax=asiacell_confirm_transfer', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN, code: code }),
                    });
                    const data = await res.json();
                    if (!res.ok || data.error) {
                        if (data.partial_stopped) {
                            errEl.textContent = data.error;
                            errEl.classList.remove('hidden');
                            document.getElementById(ids.sms2Input).value = '';
                            asiacellShowStep(ctx, 'phone');
                            btn.disabled = false;
                            return;
                        }
                        errEl.textContent = data.error || 'تعذر تأكيد التحويل.';
                        errEl.classList.remove('hidden');
                        btn.disabled = false;
                        return;
                    }
                    if (!data.done) {
                        document.getElementById(ids.sms2Input).value = '';
                        const payInfoEl = document.getElementById(ids.payInfo);
                        if (payInfoEl) {
                            payInfoEl.innerHTML = `<div class="hosting-detail"><div class="detail-row"><span class="label">تم تحويل</span><span class="value" style="direction:ltr">${Number(data.paid).toLocaleString()} د.ع</span></div><div class="detail-row"><span class="label">من إجمالي</span><span class="value" style="direction:ltr">${Number(data.total).toLocaleString()} د.ع</span></div><div class="detail-row"><span class="label">سيصلك رمز جديد للجزء التالي</span><span class="value" style="direction:ltr">${Number(data.next_chunk).toLocaleString()} د.ع</span></div></div>`;
                        }
                        btn.disabled = false;
                        return;
                    }
                    if (ctx === 'order') {
                        location.href = 'index.php?app=1&ordered=1&order_id=' + data.order_id;
                    } else {
                        location.href = 'index.php?app=1&topup=1';
                    }
                } catch (err) {
                    errEl.textContent = 'تعذر الاتصال بالسيرفر. حاول مرة أخرى.';
                    errEl.classList.remove('hidden');
                    btn.disabled = false;
                }
            }

            function syncTopUpAmountUsd() {
                const input = document.getElementById('topUpAmountInput');
                const hidden = document.getElementById('topUpAmountUsd');
                const hint = document.getElementById('topUpUsdHint');
                const code = detectCurrencyCode();
                const cur = CURRENCIES[code] || { rate: 1 };
                const entered = parseFloat(input.value) || 0;
                const usd = cur.rate ? (entered / cur.rate) : entered;
                hidden.value = usd.toFixed(2);
                hint.textContent = (entered > 0 && code !== 'USD') ? ('≈ ' + usd.toFixed(2) + ' $') : '';

                const manualHint = document.getElementById('topUpManualIqdHint');
                if (manualHint) {
                    manualHint.textContent = (usd > 0 && topUpManualExchangeRate > 0)
                        ? ('المبلغ بالدينار العراقي: ' + Math.round(usd * topUpManualExchangeRate).toLocaleString() + ' د.ع')
                        : '';
                }

                const pm = PAYMENT_METHODS.find(p => String(p.id) === document.getElementById('topUpPaymentMethodId').value);
                if (pm && pm.method_type === 'binance' && !document.getElementById('topUpBinanceWrap').classList.contains('hidden')) {
                    updateTopUpBinancePayInfo();
                }
            }

            function showInvoiceDetail(id) {
                const invoice = INVOICES.find(inv => Number(inv.id) === Number(id));
                if (!invoice) return;

                const statusMap = {
                    paid: ['مدفوع ✅', 'pill-green'],
                    pending: ['قيد المراجعة ⏳', 'pill-amber'],
                    rejected: ['مرفوض ❌', 'pill-red'],
                };
                const [statusText, statusClass] = statusMap[invoice.status] || [invoice.status, 'pill-amber'];

                document.getElementById('invoiceDetailContent').innerHTML = `
                    <div class="detail-row">
                        <span class="label">رقم الفاتورة</span>
                        <span class="value">${invoice.number}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">المبلغ</span>
                        <span class="value amount" data-usd="${invoice.amount}">${Number(invoice.amount).toFixed(2)}$</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">الحالة</span>
                        <span class="value"><span class="pill ${statusClass}">${statusText}</span></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">الوصف</span>
                        <span class="value">${invoice.description || 'لا يوجد وصف'}</span>
                    </div>
                `;
                applyCurrencyDisplay(document.getElementById('invoiceDetailContent'));

                document.getElementById('invoicesList').classList.add('hidden');
                document.getElementById('invoiceDetail').classList.remove('hidden');
                document.getElementById('addBalanceSection').classList.add('hidden');
                document.getElementById('paymentPage').classList.add('hidden');
            }
            
            function hideInvoiceDetail() {
                document.getElementById('invoiceDetail').classList.add('hidden');
                document.getElementById('invoicesList').classList.remove('hidden');
            }

            function showOrderDetail(id) {
                const order = ORDERS.find(o => Number(o.id) === Number(id));
                if (!order) return;

                const statusMap = {
                    approved: [t('order_status_completed'), 'pill-green'],
                    pending: [t('order_status_in_progress'), 'pill-amber'],
                    rejected: [t('order_status_cancelled'), 'pill-red'],
                };
                const [statusText, statusClass] = statusMap[order.status] || [order.status, 'pill-amber'];
                const cycleLabel = order.billing_cycle === 'yearly' ? t('yearly') : t('monthly');

                document.getElementById('orderDetailContent').innerHTML = `
                    <div class="detail-row"><span class="label">${t('plan_label')}</span><span class="value">${planIconHtml(order.plan_icon, order.plan_icon_image)} ${order.plan_name}</span></div>
                    <div class="detail-row"><span class="label">${t('price')}</span><span class="value" data-usd="${order.amount}">$${Number(order.amount).toFixed(2)}</span></div>
                    <div class="detail-row"><span class="label">${t('subscription_duration')}</span><span class="value">${cycleLabel}</span></div>
                    <div class="detail-row"><span class="label">${t('status')}</span><span class="value"><span class="pill ${statusClass}">${statusText}</span></span></div>
                    <div class="detail-row"><span class="label">${t('order_date')}</span><span class="value">${(order.created_at || '').substring(0, 10)}</span></div>
                    ${order.renewal_hosting_id ? `<div class="detail-row"><span class="label">${t('order_type')}</span><span class="value">${t('renewal_order')}</span></div>` : ''}
                    ${order.status === 'rejected' && order.admin_note ? `<div class="detail-row"><span class="label">${t('admin_note')}</span><span class="value" style="direction:rtl;text-align:right;font-weight:400;font-size:12px">${order.admin_note}</span></div>` : ''}
                `;
                applyCurrencyDisplay(document.getElementById('orderDetailContent'));

                document.getElementById('ordersListCard').classList.add('hidden');
                document.getElementById('orderDetail').classList.remove('hidden');
            }

            function hideOrderDetail() {
                document.getElementById('orderDetail').classList.add('hidden');
                document.getElementById('ordersListCard').classList.remove('hidden');
            }
            
            // ============================================================
            // بطاقة تأكيد تسجيل الخروج
            // ============================================================
            function showLogoutSheet() {
                document.getElementById('logoutOverlay').classList.add('show');
                document.body.style.overflow = 'hidden';
            }
            
            function closeLogoutSheet() {
                document.getElementById('logoutOverlay').classList.remove('show');
                document.body.style.overflow = '';
            }
            
            function confirmLogout() {
                window.location.href = '?logout=1';
            }

            // ============================================================
            // المساعد الذكي
            // ============================================================
            function enterAI() {
                document.querySelector('.header').classList.add('hidden');
                document.getElementById('appContent').classList.add('hidden');
                document.getElementById('mainBottomNav').classList.add('hidden');
                document.getElementById('section-ai').classList.remove('hidden');
                showAiView('home');
            }

            function exitAI() {
                document.getElementById('section-ai').classList.add('hidden');
                document.querySelector('.header').classList.remove('hidden');
                document.getElementById('appContent').classList.remove('hidden');
                document.getElementById('mainBottomNav').classList.remove('hidden');
                showSection('home');
            }

            const AI_VIEW_TITLES = {
                home: 'المساعد الذكي',
                explain: 'شرح أمر',
                solve: 'حل مشكلة',
                tips: 'نصائح التحسين',
                suggestions: 'اقتراحات ذكية',
                tools: 'الأدوات الذكية',
                conversations: 'المحادثات',
                settings: 'إعدادات المساعد'
            };
            const AI_CHAT_VIEWS = ['home', 'explain', 'solve', 'tips', 'suggestions'];
            const AI_WELCOME_HINTS = {
                explain: 'اكتب أي أمر لينكس (مثل: sudo apt update) وسأشرحه لك خطوة بخطوة 👇',
                solve: 'صف المشكلة التي تواجهها مع سيرفرك (اتصال، أداء، خدمة معينة...) وسأساعدك بتشخيصها وحلها 🔧',
                tips: 'اسألني عن أي جانب من سيرفرك (الأداء، الأمان، إدارة الموارد) وسأقترح تحسينات عملية 🚀',
                suggestions: 'اكتب ما تعمل عليه بسيرفرك وسأقترح عليك أفكاراً وخطوات ذكية 💡',
            };
            const aiHistories = { home: [], explain: [], solve: [], tips: [], suggestions: [] };

            function showAiView(view) {
                document.querySelectorAll('.ai-view').forEach(el => el.classList.add('hidden'));
                document.getElementById('aiView' + view.charAt(0).toUpperCase() + view.slice(1)).classList.remove('hidden');
                document.getElementById('aiHeaderTitle').textContent = AI_VIEW_TITLES[view] || 'المساعد الذكي';

                const isChatView = AI_CHAT_VIEWS.includes(view);
                document.getElementById('aiInputBar').classList.toggle('hidden', !isChatView);
                document.getElementById('aiBottomNav').classList.toggle('hidden', isChatView);

                document.querySelectorAll('#aiBottomNav .nav-item').forEach(el => {
                    el.classList.toggle('active', el.dataset.aiView === view);
                });

                const logId = 'ai' + view.charAt(0).toUpperCase() + view.slice(1) + 'ChatLog';
                const log = document.getElementById(logId);
                if (AI_WELCOME_HINTS[view] && log && !log.children.length) {
                    appendChatBubble(logId, 'bot', escapeHtml(AI_WELCOME_HINTS[view]));
                }

                document.getElementById('aiBody').scrollTop = 0;
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function formatAiReply(text) {
                let safe = escapeHtml(text);
                safe = safe.replace(/```([\s\S]*?)```/g, (m, code) => '<code style="display:block;white-space:pre-wrap;margin:8px 0">' + code.trim() + '</code>');
                safe = safe.replace(/`([^`]+)`/g, '<code>$1</code>');
                safe = safe.replace(/\n/g, '<br>');
                return safe;
            }

            function appendChatBubble(logId, sender, html) {
                const log = document.getElementById(logId);
                const bubble = document.createElement('div');
                bubble.className = 'chat-bubble ' + sender;
                bubble.innerHTML = html;
                log.appendChild(bubble);
                document.getElementById('aiBody').scrollTop = document.getElementById('aiBody').scrollHeight;
                return bubble;
            }

            let aiSending = false;

            async function sendToAi(section, logId, userText) {
                aiSending = true;
                const input = document.getElementById('aiInputField');
                const btn = document.getElementById('aiSendBtn');
                if (input) input.disabled = true;
                if (btn) btn.disabled = true;

                appendChatBubble(logId, 'user', escapeHtml(userText));
                const history = (aiHistories[section] || []).slice();
                aiHistories[section] = history.concat([{ role: 'user', content: userText }]);

                const typing = appendChatBubble(logId, 'bot', '<i class="fas fa-ellipsis"></i> جاري الكتابة...');

                try {
                    const res = await fetch('index.php?ajax=ai_chat', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: CSRF_TOKEN, section: section, message: userText, history: history }),
                    });
                    const data = await res.json();
                    typing.remove();
                    if (data.error) {
                        appendChatBubble(logId, 'bot', '⚠️ ' + escapeHtml(data.error));
                    } else {
                        appendChatBubble(logId, 'bot', formatAiReply(data.reply));
                        aiHistories[section].push({ role: 'assistant', content: data.reply });
                    }
                } catch (err) {
                    typing.remove();
                    appendChatBubble(logId, 'bot', '⚠️ تعذر الاتصال بالخادم، حاول مجدداً.');
                } finally {
                    aiSending = false;
                    if (input) { input.disabled = false; input.focus(); }
                    if (btn) btn.disabled = false;
                }
            }

            function sendAiMessage() {
                if (aiSending) return;
                const input = document.getElementById('aiInputField');
                const text = input.value.trim();
                if (!text) return;
                input.value = '';

                const activeView = AI_CHAT_VIEWS.find(v => !document.getElementById('aiView' + v.charAt(0).toUpperCase() + v.slice(1)).classList.contains('hidden')) || 'home';
                const logId = 'ai' + activeView.charAt(0).toUpperCase() + activeView.slice(1) + 'ChatLog';
                sendToAi(activeView, logId, text);
            }

            function openConversation(title) {
                showAiView('home');
                appendChatBubble('aiHomeChatLog', 'bot', '📂 فتح محادثة سابقة: <strong>' + escapeHtml(title) + '</strong> (السجل الكامل غير متاح في هذه النسخة التجريبية)');
            }

            function clearAiConversations() {
                if (!confirm('هل تريد مسح جميع المحادثات؟ لا يمكن التراجع عن هذا الإجراء.')) return;
                document.getElementById('aiConversationsList').innerHTML = '<div class="text-muted text-center" style="padding:24px 0">لا توجد محادثات محفوظة</div>';
            }

            // ============================================================
            // عرض القسم الافتراضي
            // ============================================================
            showSection('home');

            if (ROUTE_HINT.ordered) {
                showSection('vps');
                wizardGoTo('success');
                const orderSuccessIdEl = document.getElementById('orderSuccessId');
                if (orderSuccessIdEl && ROUTE_HINT.orderedId) orderSuccessIdEl.textContent = '#' + ROUTE_HINT.orderedId;
            } else if (ROUTE_HINT.buyPlanId) {
                showSection('vps');
                wizardSelectPlan(ROUTE_HINT.buyPlanId);
                wizardGoTo(ROUTE_HINT.hasOrderError ? 'payment' : 'details');
            } else if (ROUTE_HINT.adminSection) {
                showSection('admin');
                showAdminTab(ROUTE_HINT.adminSection);
                showAdminFlash(ROUTE_HINT.adminMsg, ROUTE_HINT.adminErr);
            }
