            let detailReturnSection = 'home';
            
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
                if (!pwaCard || !notifCard) return;

                const canShowPwa = !!deferredInstallPrompt && !isStandalonePwa() && localStorage.getItem('pwaInstallDismissed') !== '1';
                const canShowNotif = 'Notification' in window && Notification.permission === 'default' && localStorage.getItem('notifPermDismissed') !== '1';

                pwaCard.classList.toggle('hidden', !canShowPwa);
                notifCard.classList.toggle('hidden', !canShowNotif);
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
                if (section === 'admin') {
                    const frame = document.getElementById('adminEmbedFrame');
                    if (frame && !frame.src) frame.src = frame.dataset.src;
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
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
                if (!confirm('هل تريد حذف جميع الإشعارات نهائياً؟')) return;
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
            function renderUsageTab(hosting) {
                // أرقام استخدام تجريبية ثابتة لكل سيرفر (مبنية على رقم السيرفر)
                const cpuPct = 15 + (hosting.id * 17) % 70;
                const ramPct = 20 + (hosting.id * 29) % 65;
                const bandwidthUsed = (0.2 + ((hosting.id * 0.37) % 0.7)).toFixed(2);
                const storageUsedPct = 25 + (hosting.id * 19) % 60;
                const storageTotal = parseInt(hosting.plan === 'أساسي' ? 50 : hosting.plan === 'متقدم' ? 100 : hosting.plan === 'احترافي' ? 200 : 40);
                const storageUsed = Math.round(storageTotal * storageUsedPct / 100);

                document.getElementById('usageGaugesContent').innerHTML = `
                    <div class="gauge-card">
                        <div class="gauge" style="--pct:${cpuPct}"><span class="gauge-value">${cpuPct}%</span></div>
                        <div class="gauge-label">CPU</div>
                    </div>
                    <div class="gauge-card">
                        <div class="gauge" style="--pct:${ramPct}"><span class="gauge-value">${ramPct}%</span></div>
                        <div class="gauge-label">RAM</div>
                    </div>
                `;
                document.getElementById('usageBandwidthLabel').textContent = bandwidthUsed + ' TB / 1 TB';
                document.getElementById('usageBandwidthFill').style.width = (bandwidthUsed * 100) + '%';
                document.getElementById('usageStorageLabel').textContent = storageUsed + ' GB / ' + storageTotal + ' GB';
                document.getElementById('usageStorageFill').style.width = storageUsedPct + '%';
            }

            function switchDetailTab(tab) {
                document.getElementById('tabBtnUsage').classList.toggle('active', tab === 'usage');
                document.getElementById('tabBtnInfo').classList.toggle('active', tab === 'info');
                document.getElementById('tabPanelUsage').classList.toggle('hidden', tab !== 'usage');
                document.getElementById('tabPanelInfo').classList.toggle('hidden', tab !== 'info');
            }

            function showHostingDetail(id) {
                const hosting = HOSTING.find(h => h.id === id);
                if (!hosting) return;

                detailReturnSection = document.getElementById('section-servers').classList.contains('hidden') ? 'home' : 'servers';

                const statusText = (hosting.status === 'active' ? t('active') : t('expired')) + (hosting.status === 'active' ? ' ✅' : ' ❌');
                const statusClass = hosting.status === 'active' ? 'pill-green' : 'pill-red';
                const isExpired = hosting.status === 'expired';

                renderUsageTab(hosting);
                switchDetailTab('usage');

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
                    ${isExpired ? `
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

            function renewHosting(id) {
                if (confirm('هل تريد تجديد هذه الاستضافة؟')) {
                    alert('✅ تم تجديد الاستضافة بنجاح!\nتم إضافة شهر جديد إلى تاريخ الانتهاء.');
                    // في تطبيق حقيقي، يتم إرسال طلب تجديد
                    setTimeout(function() {
                        hideHostingDetail();
                        // تحديث الصفحة لإظهار التغييرات
                        location.reload();
                    }, 1000);
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
                    <div class="plan-select-item ${wizardState.planId === plan.id ? 'selected' : ''}" onclick="wizardSelectPlan(${plan.id})">
                        <div class="radio-circle ${wizardState.planId === plan.id ? 'checked' : ''}"><i class="fas fa-check"></i></div>
                        <div class="info">
                            <div class="top-row">
                                <span class="plan-title">${plan.icon} ${plan.name}</span>
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
                return VPS_PLANS.find(p => p.id === wizardState.planId);
            }

            function renderPlanDetails() {
                const plan = currentPlan();
                if (!plan) return;
                document.getElementById('planDetailsIcon').textContent = plan.icon;
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
                    <div class="detail-row"><span class="label">${t('plan_label')}</span><span class="value">${plan.icon} ${plan.name}</span></div>
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
                const options = PAYMENT_METHODS.map(pm => ({
                    id: String(pm.id),
                    icon: pm.icon,
                    color: pm.color,
                    logo: pm.logo_path,
                    title: pm.name,
                    sub: 'تحويل يدوي',
                    manual: true,
                    account_number: pm.account_number,
                    instructions: pm.instructions,
                }));
                options.push({
                    id: 'balance', icon: 'fa-wallet', color: 'green', logo: null, title: 'رصيد الحساب',
                    sub: formatUsd(USER_BALANCE) + ' متاح', manual: false,
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
                if (selected && selected.manual) {
                    uploadWrap.classList.remove('hidden');
                    proofInput.required = true;
                    document.getElementById('payInstructionsBox').innerHTML = `
                        <div class="detail-row"><span class="label">حوّل إلى</span><span class="value">${selected.account_number || '-'}</span></div>
                        ${selected.instructions ? `<div class="detail-row"><span class="label">ملاحظة</span><span class="value" style="direction:rtl;text-align:right;font-weight:400;font-size:12px">${selected.instructions}</span></div>` : ''}
                    `;
                } else {
                    uploadWrap.classList.add('hidden');
                    proofInput.required = false;
                }
            }

            function wizardSelectPayment(id) {
                wizardState.paymentMethod = id;
                renderPayOptions();
                checkBalanceSufficiency();
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
                document.querySelectorAll('#section-vps .wizard-step').forEach(el => el.classList.add('hidden'));
                document.getElementById('vpsStep' + step.charAt(0).toUpperCase() + step.slice(1)).classList.remove('hidden');

                if (step === 'plan') {
                    wizardState = { planId: null, paymentMethod: null, billingCycle: 'monthly' };
                    document.getElementById('billingTabMonthly')?.classList.add('active');
                    document.getElementById('billingTabYearly')?.classList.remove('active');
                    renderPlanList();
                    document.getElementById('planContinueBtn').disabled = true;
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
            
            function showPaymentPage(methodId, methodName, accountNumber, instructions) {
                document.getElementById('paymentMethodName').textContent = 'شحن عبر ' + methodName;
                document.getElementById('topUpPaymentMethodId').value = methodId;
                document.getElementById('topUpInstructions').innerHTML = `
                    <div class="detail-row"><span class="label">حوّل إلى</span><span class="value">${accountNumber || '-'}</span></div>
                ` + (instructions ? `<div class="detail-row"><span class="label">ملاحظة</span><span class="value" style="direction:rtl;text-align:right;font-weight:400;font-size:12px">${instructions}</span></div>` : '');
                document.getElementById('paymentPage').classList.remove('hidden');
                document.getElementById('addBalanceSection').classList.add('hidden');
            }

            function hidePaymentPage() {
                document.getElementById('paymentPage').classList.add('hidden');
                document.getElementById('addBalanceSection').classList.remove('hidden');
            }

            function showInvoiceDetail(id) {
                const invoice = INVOICES.find(inv => inv.id === id);
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

            async function sendToAi(section, logId, userText) {
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
                }
            }

            function sendAiMessage() {
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
            }
