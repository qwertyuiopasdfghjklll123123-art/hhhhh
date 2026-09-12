        // ========== تعريف مسار مجلد الصور ==========
        const UPLOADS_PATH = 'uploads/';

        // ========== تحميل الصور مسبقاً ==========
        function preloadImages() {
            const preloadContainer = document.getElementById('preloadImages');
            if (!preloadContainer) return;
            
            const imagePaths = [];
            if (catalogData && catalogData.categories) {
                catalogData.categories.forEach(cat => {
                    if (cat.image) imagePaths.push(UPLOADS_PATH + cat.image);
                    if (cat.companies) {
                        cat.companies.forEach(comp => {
                            if (comp.logo) imagePaths.push(UPLOADS_PATH + comp.logo);
                            if (comp.products) {
                                comp.products.forEach(prod => {
                                    if (prod.img) imagePaths.push(UPLOADS_PATH + prod.img);
                                });
                            }
                        });
                    }
                    if (cat.services) {
                        cat.services.forEach(service => {
                            if (service.img) imagePaths.push(UPLOADS_PATH + service.img);
                        });
                    }
                });
            }
            
            if (favorites) {
                favorites.forEach(item => {
                    if (item.img && !item.img.startsWith('data:') && !item.img.startsWith('http')) {
                        imagePaths.push(item.img);
                    }
                });
            }
            
            if (cart) {
                cart.forEach(item => {
                    if (item.img && !item.img.startsWith('data:') && !item.img.startsWith('http')) {
                        imagePaths.push(item.img);
                    }
                });
            }
            
            const uniquePaths = [...new Set(imagePaths)];
            
            uniquePaths.forEach(path => {
                const img = new Image();
                img.src = path;
                const hiddenImg = document.createElement('img');
                hiddenImg.src = path;
                hiddenImg.style.display = 'none';
                hiddenImg.className = 'preload-img';
                preloadContainer.appendChild(hiddenImg);
            });
            
            console.log(`✅ تم تحميل ${uniquePaths.length} صورة مسبقاً`);
        }

        // ========== دالة مساعدة لإضافة مسار المجلد للصورة ==========
        function getImageUrl(imageName, defaultImage = 'https://iili.io/CKP5shF.jpg') {
            if (!imageName) return defaultImage;
            if (imageName.startsWith('http://') || imageName.startsWith('https://')) {
                return imageName;
            }
            if (imageName.startsWith('data:') || imageName.startsWith('http')) return imageName;
            return UPLOADS_PATH + imageName;
        }

        // إخفاء شاشة التحميل بعد تحميل الصفحة
        window.addEventListener('load', () => {
            setTimeout(() => {
                const splash = document.getElementById('splash-screen');
                if(splash) splash.classList.add('hide');
            }, 1500);
        });

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('?sw=1').catch(err => console.log('SW Error:', err));
        }
        
        // ========== دوال API ==========
        const CSRF_TOKEN = (typeof window !== 'undefined' && window.CSRF_TOKEN) ? window.CSRF_TOKEN : '';
        async function apiCall(action, method, data = null) {
            try {
                const options = { method: method, headers: { 'Content-Type': 'application/json' } };
                if (method === 'POST') {
                    options.body = JSON.stringify(Object.assign({}, data || {}, { csrf_token: CSRF_TOKEN }));
                } else if (data) {
                    options.body = JSON.stringify(data);
                }
                const url = window.location.pathname + '?action=' + action;
                const response = await fetch(url, options);
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch(e) {
                    console.error('JSON Parse Error:', text);
                    return { success: false, message: 'خطأ في استجابة السيرفر' };
                }
            } catch(e) {
                console.error('API Error:', e);
                return { success: false, message: 'خطأ في الاتصال: ' + e.message };
            }
        }

        // ========== المتغيرات العامة ==========
        let currentUser = null;
        let isGuestMode = false;
        let favorites = [];
        let cart = [];
        let catalogData = { categories: [] };
        let appSettings = { appName: 'Almulla', appLogo: 'https://iili.io/CKP5shF.jpg', whatsappNumber: '966555555555', hideMostRequested: false };
        let welcomeCardSettings = { enabled: true, title: 'مرحباً', message: 'أهلاً بك', buttonText: 'تصفح', buttonLink: '#', animationSpeed: 30 };
        let officialWebsite = '';
        let currentPage = 'home';
        let currentLevel = 'categories';
        let currentCategoryId = null;
        let currentCompanyId = null;
        
        let tempCatImage = null;
        let tempCompanyLogo = null;
        let tempProductImage = null;
        let tempServiceImage = null;
        
        let currentEditingService = null;
        let currentEditingCategoryId = null;
        let currentEditingServiceIndex = null;
        
        let currentEditingCompany = null;
        let currentEditingCompanyData = null;
        
        let editProductInfoData = {
            catId: null,
            compId: null,
            productId: null,
            newImage: null
        };
        
        let failedAttempts = 0;
        let currentCaptchaCode = "";
        let isBlocked = false;
        let blockUntil = 0;
        
        let pendingAction = null;
        let pendingActionData = null;
        
        let statusSearchQuery = '';
        
        const APP_URL = window.location.origin;
        const APP_NAME = 'Almulla';
        const APP_DESCRIPTION = 'اكتشف أفضل المواد والمنتجات مع عروضنا الحصرية';

        // ========== دوال مساعدة ==========
        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'toast-message';
            toast.innerHTML = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // ========== دوال نافذة الاتصال بنا ==========
        function showContactUs() {
            document.getElementById('contactUsModal').classList.add('show');
        }
        function closeContactUsModal() {
            document.getElementById('contactUsModal').classList.remove('show');
        }

        // ========== دوال نافذة عن التطبيق ==========
        function showAbout() {
            document.getElementById('aboutAppModal').classList.add('show');
        }
        function closeAboutAppModal() {
            document.getElementById('aboutAppModal').classList.remove('show');
        }

        // ========== دوال تثبيت التطبيق ==========
        function showInstallAppModal() {
            document.getElementById('installAppModal').classList.add('show');
        }
        function closeInstallAppModal() {
            document.getElementById('installAppModal').classList.remove('show');
        }

        // ========== دوال نافذة التأكيد ==========
        function showConfirmModal(title, message, onConfirm, data = null, isDelete = false) {
            document.getElementById('confirmModalTitle').innerHTML = title;
            document.getElementById('confirmModalMessage').innerHTML = message;
            pendingAction = onConfirm;
            pendingActionData = data;
            
            const okBtn = document.getElementById('confirmOkBtn');
            if (isDelete) {
                okBtn.classList.add('delete');
            } else {
                okBtn.classList.remove('delete');
            }
            
            document.getElementById('confirmModal').classList.add('show');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.remove('show');
        }

        function executeConfirmedAction() {
            const action = pendingAction;
            const actionData = pendingActionData;
            
            closeConfirmModal();
            
            if (action) {
                if (actionData) {
                    action(actionData);
                } else {
                    action();
                }
            }
            
            pendingAction = null;
            pendingActionData = null;
        }

        // ========== دوال الخروج ==========
        function showLogoutConfirm() {
            document.getElementById('logoutConfirmModal').classList.add('show');
        }

        function closeLogoutConfirm() {
            document.getElementById('logoutConfirmModal').classList.remove('show');
        }

        function confirmLogout() {
            closeLogoutConfirm();
            logout();
        }

        // ================================================================
        // ========== دوال تتبع المواد الأكثر طلباً ==========
        // ================================================================

        async function trackProductRequest(productName, categoryName) {
            await apiCall('track_product_request', 'POST', { 
                productName: productName, 
                categoryName: categoryName 
            });
            loadMostRequestedItems();
        }

        async function loadMostRequestedItems() {
            const result = await apiCall('get_most_requested', 'GET');
            const container = document.getElementById('topServicesContainer');
            const adminList = document.getElementById('mostRequestedList');
            
            if (result.settings && result.settings.hideMostRequested !== undefined) {
                appSettings.hideMostRequested = result.settings.hideMostRequested;
                if (document.getElementById('hideMostRequested')) {
                    document.getElementById('hideMostRequested').checked = result.settings.hideMostRequested;
                }
            }
            
            if (appSettings.hideMostRequested === true) {
                if (container) container.style.display = 'none';
            } else {
                if (container) container.style.display = 'block';
            }
            
            if (result.success && result.items && result.items.length > 0) {
                if (container && appSettings.hideMostRequested !== true) {
                    let html = `<div class="top-services-title">
                                    <i class="fas fa-fire"></i> الأكثر طلباً
                                    <i class="fas fa-chart-simple"></i>
                                </div>
                                <div class="top-services-grid">`;
                    result.items.slice(0, 6).forEach((item, index) => {
                        const rankIcon = index === 0 ? '🥇' : (index === 1 ? '🥈' : (index === 2 ? '🥉' : '📌'));
                        const icon = item.type === 'service' ? '🔔' : '📦';
                        html += `<div class="top-service-item" onclick="addItemToCartDirectly('${escapeHtml(item.name).replace(/'/g, "\\'")}', '${escapeHtml(item.category).replace(/'/g, "\\'")}', '${item.type}')">
                                    <div class="top-service-rank">${rankIcon}</div>
                                    <div class="top-service-info" style="flex:1; min-width:0;">
                                        <div class="top-service-name">${icon} ${escapeHtml(item.name)}</div>
                                        <div class="top-service-category" style="font-size:0.55rem; opacity:0.8;">${escapeHtml(item.category)}</div>
                                    </div>
                                </div>`;
                    });
                    html += `</div>`;
                    container.innerHTML = html;
                } else if (container && appSettings.hideMostRequested === true) {
                    container.innerHTML = '';
                }
                
                if (adminList) {
                    adminList.innerHTML = result.items.map((item, idx) => `
                        <div class="admin-item" style="padding:6px; border-bottom:1px solid var(--cardBd);">
                            <div style="font-size:0.7rem; flex:1;">
                                <span style="font-weight:700;">${idx + 1}.</span>
                                ${item.type === 'service' ? '🔔' : '📦'} 
                                <strong>${escapeHtml(item.name)}</strong>
                                <br>
                                <span style="font-size:0.55rem; color:var(--t3);">📁 ${escapeHtml(item.category)}</span>
                                <span style="font-size:0.55rem; color:var(--ac); margin-right:8px;">👆 ${item.count} طلب</span>
                            </div>
                            <button onclick="deleteMostRequestedItem('${escapeHtml(item.name).replace(/'/g, "\\'")}', '${escapeHtml(item.category).replace(/'/g, "\\'")}')" 
                                style="background:rgba(239,68,68,0.2); border:none; border-radius:6px; padding:4px 10px; cursor:pointer; color:var(--rd); font-size:0.6rem;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `).join('');
                }
            } else {
                if (container && appSettings.hideMostRequested !== true) {
                    container.innerHTML = '';
                }
                if (adminList) adminList.innerHTML = '<div style="padding:8px; text-align:center; font-size:0.7rem; color:var(--t4);">لا توجد مواد مطلوبة حتى الآن</div>';
            }
        }

        function addItemToCartDirectly(itemName, categoryName, type) {
            if (type === 'service') {
                addServiceToCartDirectly(itemName, categoryName);
            } else {
                addProductToCartDirectly(itemName, categoryName);
            }
        }

        function addProductToCartDirectly(productName, categoryName) {
            for (let cat of catalogData.categories) {
                if (cat.name === categoryName && cat.companies) {
                    for (let comp of cat.companies) {
                        const product = comp.products?.find(p => p.name === productName);
                        if (product && product.available !== false) {
                            addToCart({ 
                                name: product.name, 
                                code: product.code || '', 
                                color: product.color || 'غير محدد', 
                                img: product.img ? getImageUrl(product.img) : 'https://iili.io/CKP5shF.jpg',
                                category: cat.name,
                                available: true
                            });
                            return;
                        } else if (product && product.available === false) {
                            showToast('⚠️ هذا المنتج غير متوفر حالياً');
                            return;
                        }
                    }
                }
            }
            showToast('⚠️ لم يتم العثور على المنتج');
        }

        async function deleteMostRequestedItem(itemName, categoryName) {
            showConfirmModal(
                '🗑️ حذف من الأكثر طلباً',
                `هل أنت متأكد من حذف "${itemName}" من قائمة الأكثر طلباً؟`,
                async function() {
                    const result = await apiCall('delete_most_requested', 'POST', {
                        itemName: itemName,
                        categoryName: categoryName
                    });
                    if (result.success) {
                        showToast(`✅ تم حذف "${itemName}" من قائمة الأكثر طلباً`);
                        loadMostRequestedItems();
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        async function toggleHideMostRequested() {
            const checkbox = document.getElementById('hideMostRequested');
            const hide = checkbox.checked;
            const result = await apiCall('toggle_hide_most_requested', 'POST', { hide: hide });
            if (result.success) {
                appSettings.hideMostRequested = hide;
                showToast(hide ? '✅ تم إخفاء بطاقة الأكثر طلباً' : '✅ تم إظهار بطاقة الأكثر طلباً');
                loadMostRequestedItems();
            } else {
                showToast('❌ ' + (result.message || 'حدث خطأ'));
                checkbox.checked = !hide;
            }
        }

        // ================================================================
        // ========== دوال إدارة توفر الخدمات والمنتجات ==========
        // ================================================================

        function renderStatusServices(filter = '') {
            const container = document.getElementById('statusServiceList');
            if (!container) return;
            
            const searchTerm = filter.toLowerCase().trim();
            let allItems = [];
            
            if (catalogData.categories) {
                catalogData.categories.forEach(cat => {
                    if (cat.services && cat.services.length > 0) {
                        cat.services.forEach((service, idx) => {
                            allItems.push({
                                id: 'service_' + cat.id + '_' + idx,
                                name: service.name,
                                category: cat.name,
                                categoryId: cat.id,
                                type: 'خدمة مباشرة',
                                typeIcon: '🔔',
                                typeClass: 'service',
                                parentId: cat.id,
                                parentName: cat.name,
                                itemIdx: idx,
                                realId: service.id,
                                isService: true,
                                available: service.available !== false,
                                img: service.img || null,
                                notes: service.notes || '',
                                color: service.color || '',
                                code: '',
                                deletedCard: service.deletedCard || 'no'
                            });
                        });
                    }
                    
                    if (cat.companies && cat.companies.length > 0) {
                        cat.companies.forEach(comp => {
                            if (comp.products && comp.products.length > 0) {
                                comp.products.forEach((prod, pIdx) => {
                                    allItems.push({
                                        id: 'product_' + cat.id + '_' + comp.id + '_' + pIdx,
                                        name: prod.name,
                                        category: cat.name,
                                        categoryId: cat.id,
                                        company: comp.name,
                                        companyId: comp.id,
                                        type: 'منتج',
                                        typeIcon: '📦',
                                        typeClass: 'product',
                                        parentId: comp.id,
                                        parentName: comp.name,
                                        itemIdx: pIdx,
                                        realId: prod.id,
                                        isService: false,
                                        available: prod.available !== false,
                                        img: prod.img || null,
                                        code: prod.code || '',
                                        color: prod.color || '',
                                        deletedCard: prod.deletedCard || 'no'
                                    });
                                });
                            }
                        });
                    }
                });
            }
            
            let filtered = allItems;
            if (searchTerm) {
                filtered = allItems.filter(item => 
                    item.name.toLowerCase().includes(searchTerm) ||
                    item.category.toLowerCase().includes(searchTerm) ||
                    (item.company && item.company.toLowerCase().includes(searchTerm)) ||
                    (item.code && item.code.toLowerCase().includes(searchTerm))
                );
            }
            
            const total = allItems.length;
            const available = allItems.filter(item => item.available !== false).length;
            const unavailable = total - available;
            document.getElementById('statusTotal').textContent = total;
            document.getElementById('statusAvailable').textContent = available;
            document.getElementById('statusUnavailable').textContent = unavailable;
            
            document.getElementById('statusSearchClear').classList.toggle('visible', searchTerm.length > 0);
            
            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="no-results-status">
                        <i class="fas fa-search"></i>
                        لا توجد خدمات أو منتجات تطابق بحثك
                        <br>
                        <small style="color: var(--t4);">جرب كلمة بحث مختلفة</small>
                    </div>
                `;
                return;
            }
            
            let html = '';
            let currentCategory = '';
            let currentCompany = '';
            
            filtered.forEach((item) => {
                if (item.category !== currentCategory) {
                    currentCategory = item.category;
                    html += `
                        <div class="category-header">
                            <i class="fas fa-folder"></i> ${escapeHtml(item.category)}
                        </div>
                    `;
                    currentCompany = '';
                }
                
                if (item.type === 'منتج' && item.company !== currentCompany) {
                    currentCompany = item.company;
                    html += `
                        <div class="company-header">
                            <i class="fas fa-building" style="color:var(--ac2);"></i> ${escapeHtml(item.company)}
                        </div>
                    `;
                }
                
                const isAvailable = item.available !== false;
                const isHidden = item.deletedCard === 'ok';
                const statusText = isHidden ? '👁️ مخفي' : (isAvailable ? '✅ متوفر' : '❌ غير متوفر');
                const statusClass = isHidden ? 'unavailable' : (isAvailable ? 'available' : 'unavailable');
                const icon = item.typeIcon;
                const typeLabel = item.type;
                const typeClass = item.typeClass;
                
                let extraInfo = '';
                if (item.code) extraInfo += `📋 ${escapeHtml(item.code)} `;
                if (item.color) extraInfo += `🎨 ${escapeHtml(item.color)}`;
                if (isHidden) extraInfo += `👁️ مخفي`;
                
                html += `
                    <div class="status-service-item" data-item-id="${item.id}" style="${isHidden || !isAvailable ? 'border-right:3px solid var(--rd);' : ''}">
                        <div class="info">
                            <span class="icon">${icon}</span>
                            <span class="name">${escapeHtml(item.name)}</span>
                            <span class="type-badge ${typeClass}">${typeLabel}</span>
                            ${extraInfo ? `<span class="extra-info">${extraInfo}</span>` : ''}
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" ${isAvailable ? 'checked' : ''}
                                   onchange="toggleItemStatus('${item.id}', this.checked, '${item.isService ? 'service' : 'product'}', '${item.categoryId}', '${item.parentId || ''}', ${item.itemIdx}, '${item.realId}')">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        async function toggleItemStatus(itemId, newStatus, type, categoryId, parentId, itemIdx, itemRealId) {
            try {
                let result;

                if (type === 'service') {
                    result = await apiCall('toggle_service_status', 'POST', {
                        catId: categoryId,
                        serviceId: itemRealId
                    });
                } else if (type === 'product') {
                    result = await apiCall('toggle_product_status', 'POST', {
                        catId: categoryId,
                        compId: parentId,
                        productId: itemRealId
                    });
                } else {
                    showToast('❌ نوع غير معروف');
                    return;
                }
                
                if (result && result.success) {
                    if (type === 'service') {
                        const cat = catalogData.categories.find(c => c.id === categoryId);
                        if (cat && cat.services[itemIdx]) {
                            cat.services[itemIdx].available = newStatus;
                        }
                    } else if (type === 'product') {
                        const cat = catalogData.categories.find(c => c.id === categoryId);
                        if (cat) {
                            const comp = cat.companies.find(c => c.id === parentId);
                            if (comp && comp.products[itemIdx]) {
                                comp.products[itemIdx].available = newStatus;
                            }
                        }
                    }
                    
                    renderStatusServices(statusSearchQuery);
                    render();
                    updateAdminLists();
                    
                    const statusText = newStatus ? 'متوفرة ✅' : 'غير متوفرة ❌';
                    const icon = newStatus ? '✅' : '⚠️';
                    const itemName = type === 'service' ? 'الخدمة' : 'المنتج';
                    showToast(`${icon} تم تغيير حالة ${itemName} إلى "${statusText}"`);
                } else {
                    showToast('❌ ' + (result?.message || 'حدث خطأ'));
                    renderStatusServices(statusSearchQuery);
                }
            } catch(e) {
                console.error('Error toggling item status:', e);
                showToast('❌ حدث خطأ في تغيير الحالة');
                renderStatusServices(statusSearchQuery);
            }
        }

        function filterStatusServices() {
            const input = document.getElementById('serviceStatusSearch');
            statusSearchQuery = input.value;
            renderStatusServices(statusSearchQuery);
        }

        function clearStatusSearch() {
            document.getElementById('serviceStatusSearch').value = '';
            statusSearchQuery = '';
            renderStatusServices('');
            document.getElementById('serviceStatusSearch').focus();
        }

        // ================================================================
        // ========== دوال إدارة الخدمات ==========
        // ================================================================

        function openEditServiceModal(catId, serviceIdx) {
            const cat = catalogData.categories.find(c => c.id === catId);
            const service = cat?.services[serviceIdx];
            if (!service) {
                showToast('❌ الخدمة غير موجودة');
                return;
            }
            
            currentEditingCategoryId = catId;
            currentEditingServiceIndex = serviceIdx;
            currentEditingService = {...service};
            
            document.getElementById('editServiceName').value = service.name || '';
            document.getElementById('editServiceColor').value = service.color || '';
            document.getElementById('editServiceNotes').value = service.notes || '';
            document.getElementById('editServiceAvailable').checked = service.available !== false;
            
            const preview = document.getElementById('editServiceImagePreview');
            if (service.img) {
                preview.innerHTML = `<img src="${getImageUrl(service.img)}" style="width:80px; height:80px; border-radius:12px; object-fit:cover; border:2px solid var(--ac);">`;
            } else {
                preview.innerHTML = '<span style="color:var(--t4); font-size:0.7rem;">📷 لا توجد صورة</span>';
            }
            
            document.getElementById('editServiceModal').classList.add('show');
        }

        function closeEditServiceModal() {
            document.getElementById('editServiceModal').classList.remove('show');
            currentEditingService = null;
            currentEditingCategoryId = null;
            currentEditingServiceIndex = null;
        }

        function previewEditServiceImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('editServiceImagePreview').innerHTML = `<img src="${e.target.result}" style="width:80px; height:80px; border-radius:12px; object-fit:cover; border:2px solid var(--ac);">`;
                    currentEditingService.newImage = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function saveServiceChanges() {
            if (!currentEditingCategoryId || currentEditingServiceIndex === null) {
                showToast('❌ لم يتم تحديد الخدمة');
                return;
            }
            
            const cat = catalogData.categories.find(c => c.id === currentEditingCategoryId);
            if (!cat || !cat.services[currentEditingServiceIndex]) {
                showToast('❌ الخدمة غير موجودة');
                return;
            }
            
            const newName = document.getElementById('editServiceName').value.trim();
            if (!newName) {
                showToast('❌ اسم الخدمة مطلوب');
                return;
            }
            
            const serviceData = {
                name: newName,
                color: document.getElementById('editServiceColor').value.trim() || '',
                notes: document.getElementById('editServiceNotes').value.trim() || '',
                available: document.getElementById('editServiceAvailable').checked
            };
            
            if (currentEditingService && currentEditingService.newImage) {
                serviceData.img = currentEditingService.newImage;
            }
            
            try {
                const result = await apiCall('update_service', 'POST', {
                    catId: currentEditingCategoryId,
                    serviceId: currentEditingService.id,
                    service: serviceData
                });
                
                if (result.success) {
                    cat.services[currentEditingServiceIndex].name = serviceData.name;
                    cat.services[currentEditingServiceIndex].color = serviceData.color;
                    cat.services[currentEditingServiceIndex].notes = serviceData.notes;
                    cat.services[currentEditingServiceIndex].available = serviceData.available;
                    
                    if (serviceData.img) {
                        cat.services[currentEditingServiceIndex].img = serviceData.img;
                    }
                    
                    render();
                    updateAdminLists();
                    renderStatusServices(statusSearchQuery);
                    
                    closeEditServiceModal();
                    showToast(`✅ تم تحديث الخدمة "${newName}" بنجاح`);
                } else {
                    showToast('❌ ' + (result.message || 'حدث خطأ'));
                }
            } catch(e) {
                console.error('Error saving service:', e);
                showToast('❌ حدث خطأ في حفظ الخدمة');
            }
        }

        // ===== إخفاء الخدمة =====
        function hideService(catId, idx) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat || !cat.services[idx]) {
                showToast('❌ الخدمة غير موجودة');
                return;
            }
            
            const serviceName = cat.services[idx].name;
            
            showConfirmModal(
                '👁️ إخفاء الخدمة',
                `هل أنت متأكد من إخفاء الخدمة "${serviceName}"؟<br><span style="color:var(--or);">📌 ستختفي من العرض لكن البيانات محفوظة</span>`,
                async function() {
                    const result = await apiCall('hide_service', 'POST', {
                        catId: catId,
                        serviceId: cat.services[idx].id
                    });
                    
                    if (result.success) {
                        cat.services[idx].deletedCard = 'ok';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        showToast(`👁️ تم إخفاء الخدمة "${serviceName}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ===== استعادة الخدمة =====
        function restoreService(catId, idx) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat || !cat.services[idx]) {
                showToast('❌ الخدمة غير موجودة');
                return;
            }
            
            const serviceName = cat.services[idx].name;
            
            showConfirmModal(
                '👁️ استعادة الخدمة',
                `هل أنت متأكد من استعادة الخدمة "${serviceName}"؟`,
                async function() {
                    const result = await apiCall('restore_service', 'POST', {
                        catId: catId,
                        serviceId: cat.services[idx].id
                    });
                    
                    if (result.success) {
                        cat.services[idx].deletedCard = 'no';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        showToast(`👁️ تم استعادة الخدمة "${serviceName}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ================================================================
        // ========== دوال إدارة المنتجات ==========
        // ================================================================

        // ===== إخفاء المنتج =====
        function hideProduct(catId, compId, productIdx) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            const product = comp.products[productIdx];
            if (!product) { showToast('❌ المنتج غير موجود'); return; }
            
            const productName = product.name;
            
            showConfirmModal(
                '👁️ إخفاء المنتج',
                `هل أنت متأكد من إخفاء المنتج "${productName}"؟<br><span style="color:var(--or);">📌 سيختفي من العرض لكن البيانات محفوظة</span>`,
                async function() {
                    const result = await apiCall('hide_product', 'POST', {
                        catId: catId,
                        compId: compId,
                        productId: product.id
                    });
                    
                    if (result.success) {
                        product.deletedCard = 'ok';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        renderProductsTable();
                        showToast(`👁️ تم إخفاء المنتج "${productName}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ===== استعادة المنتج =====
        function restoreProduct(catId, compId, productIdx) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            const product = comp.products[productIdx];
            if (!product) { showToast('❌ المنتج غير موجود'); return; }
            
            const productName = product.name;
            
            showConfirmModal(
                '👁️ استعادة المنتج',
                `هل أنت متأكد من استعادة المنتج "${productName}"؟`,
                async function() {
                    const result = await apiCall('restore_product', 'POST', {
                        catId: catId,
                        compId: compId,
                        productId: product.id
                    });
                    
                    if (result.success) {
                        product.deletedCard = 'no';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        renderProductsTable();
                        showToast(`👁️ تم استعادة المنتج "${productName}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ===== حذف المنتج نهائياً =====
        function deleteProductPermanent(catId, compId, productId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            const productIdx = comp.products.findIndex(p => p.id === productId);
            if (productIdx === -1) { showToast('❌ المنتج غير موجود'); return; }
            
            const productName = comp.products[productIdx].name;
            
            showConfirmModal(
                '🗑️ حذف المنتج',
                `هل أنت متأكد من حذف المنتج "${productName}" بشكل دائم؟<br><span style="color:var(--rd); font-weight:bold;">⚠️ لا يمكن التراجع عن هذا الإجراء!</span>`,
                async function() {
                    const result = await apiCall('delete_product_permanent', 'POST', {
                        catId: catId,
                        compId: compId,
                        productId: productId
                    });
                    
                    if (result.success) {
                        comp.products.splice(productIdx, 1);
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        refreshStats();
                        renderProductsTable();
                        showToast(`🗑️ تم حذف المنتج "${productName}" بشكل دائم`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                },
                null,
                true
            );
        }

        // ===== تبديل حالة التوفر =====
        async function toggleProductAvailability(catId, compId, productId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            const productIdx = comp.products.findIndex(p => p.id === productId);
            if (productIdx === -1) { showToast('❌ المنتج غير موجود'); return; }
            
            const product = comp.products[productIdx];
            const newStatus = !product.available;
            
            const result = await apiCall('toggle_product_status', 'POST', {
                catId: catId,
                compId: compId,
                productId: productId
            });
            
            if (result.success) {
                product.available = newStatus;
                render();
                updateAdminLists();
                renderStatusServices(statusSearchQuery);
                renderProductsTable();
                showToast(`✅ تم ${newStatus ? 'تفعيل' : 'تعطيل'} المنتج "${product.name}"`);
            } else {
                showToast('❌ ' + (result.message || 'حدث خطأ'));
            }
        }

        // ===== عرض المنتجات في جدول =====
        function renderProductsTable() {
            const tbody = document.getElementById('productsTableBody');
            if (!tbody) return;
            
            let html = '';
            let counter = 1;
            
            catalogData.categories.forEach(cat => {
                if (cat.deletedCard === 'ok') return;
                
                cat.companies.forEach(comp => {
                    if (comp.deletedCard === 'ok') return;
                    
                    comp.products.forEach(prod => {
                        if (prod.deletedCard === 'ok') return;
                        
                        const isAvailable = prod.available !== false;
                        const statusColor = isAvailable ? 'var(--gn)' : 'var(--rd)';
                        const statusText = isAvailable ? '✅ متوفر' : '❌ غير متوفر';
                        
                        html += `
                            <tr>
                                <td style="padding: 8px;">${counter}</td>
                                <td style="padding: 8px; font-weight: 600;">${escapeHtml(prod.name)}</td>
                                <td style="padding: 8px;">${escapeHtml(prod.code || '-')}</td>
                                <td style="padding: 8px;">${escapeHtml(prod.color || '-')}</td>
                                <td style="padding: 8px;">
                                    <span style="color: ${statusColor}; font-weight: 600;">${statusText}</span>
                                </td>
                                <td style="padding: 8px;">
                                    <button onclick="openEditProductInfoModal('${cat.id}','${comp.id}','${prod.id}')" 
                                        style="background: var(--or); color: white; border: none; border-radius: 6px; padding: 4px 10px; cursor: pointer; font-size: 0.65rem;">
                                        <i class="fas fa-edit"></i> تعديل
                                    </button>
                                    <button onclick="toggleProductAvailability('${cat.id}','${comp.id}','${prod.id}')" 
                                        style="background: ${isAvailable ? 'var(--rd)' : 'var(--gn)'}; color: white; border: none; border-radius: 6px; padding: 4px 10px; cursor: pointer; font-size: 0.65rem;">
                                        ${isAvailable ? 'تعطيل' : 'تفعيل'}
                                    </button>
                                    <button onclick="deleteProductPermanent('${cat.id}','${comp.id}','${prod.id}')" 
                                        style="background: var(--rd); color: white; border: none; border-radius: 6px; padding: 4px 10px; cursor: pointer; font-size: 0.65rem;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                        counter++;
                    });
                });
            });
            
            if (counter === 1) {
                html = `
                    <tr>
                        <td colspan="6" style="padding: 30px; text-align: center; color: var(--t4);">
                            <i class="fas fa-box-open" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                            لا توجد منتجات
                        </td>
                    </tr>
                `;
            }
            
            tbody.innerHTML = html;
        }

        // ================================================================
        // ========== دوال تعديل معلومات المنتج ==========
        // ================================================================

        function openEditProductInfoModal(catId, compId, productId) {
            // العثور على المنتج
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            
            const product = comp.products.find(p => p.id === productId);
            if (!product) { showToast('❌ المنتج غير موجود'); return; }
            
            // حفظ البيانات
            editProductInfoData.catId = catId;
            editProductInfoData.compId = compId;
            editProductInfoData.productId = productId;
            editProductInfoData.newImage = null;
            
            // تعبئة الحقول
            document.getElementById('editProductInfoCatId').value = catId;
            document.getElementById('editProductInfoCompId').value = compId;
            document.getElementById('editProductInfoProductId').value = productId;
            document.getElementById('editProductInfoName').value = product.name || '';
            document.getElementById('editProductInfoCode').value = product.code || '';
            document.getElementById('editProductInfoColor').value = product.color || '';
            document.getElementById('editProductInfoImageUrl').value = product.image_url || '';
            
            // تعيين حالة التوفر
            document.getElementById('editProductInfoAvailable').checked = product.available !== false;
            
            // عرض الصورة الحالية
            const imagePreview = document.getElementById('editProductInfoImagePreview');
            if (product.img) {
                imagePreview.src = getImageUrl(product.img);
                imagePreview.style.display = 'block';
            } else if (product.image_url) {
                imagePreview.src = product.image_url;
                imagePreview.style.display = 'block';
            } else {
                imagePreview.src = '';
                imagePreview.style.display = 'none';
            }
            
            // إخفاء معاينة الصورة الجديدة
            document.getElementById('editProductInfoNewImagePreview').style.display = 'none';
            document.getElementById('editProductInfoImageInput').value = '';
            
            // فتح النافذة
            document.getElementById('editProductInfoModal').classList.add('show');
        }

        function closeEditProductInfoModal() {
            document.getElementById('editProductInfoModal').classList.remove('show');
            editProductInfoData = { catId: null, compId: null, productId: null, newImage: null };
        }

        function previewEditProductInfoImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewContainer = document.getElementById('editProductInfoNewImagePreview');
                    const previewImg = previewContainer.querySelector('img');
                    previewImg.src = e.target.result;
                    previewContainer.style.display = 'block';
                    editProductInfoData.newImage = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function saveEditProductInfo() {
            const catId = document.getElementById('editProductInfoCatId').value;
            const compId = document.getElementById('editProductInfoCompId').value;
            const productId = document.getElementById('editProductInfoProductId').value;
            const name = document.getElementById('editProductInfoName').value.trim();
            const code = document.getElementById('editProductInfoCode').value.trim();
            const color = document.getElementById('editProductInfoColor').value.trim();
            const imageUrl = document.getElementById('editProductInfoImageUrl').value.trim();
            const available = document.getElementById('editProductInfoAvailable').checked;
            
            // التحقق من صحة البيانات
            if (!name) {
                showToast('❌ اسم المنتج مطلوب');
                return;
            }
            
            // تجهيز البيانات
            const productData = {
                name: name,
                code: code || '',
                color: color || 'غير محدد',
                available: available,
                image_url: imageUrl || null
            };
            
            // إضافة الصورة الجديدة إذا وجدت
            if (editProductInfoData.newImage) {
                productData.img = editProductInfoData.newImage;
            }
            
            try {
                // عرض رسالة جاري التحميل
                showToast('⏳ جاري حفظ التغييرات...');
                
                const result = await apiCall('edit_product_info', 'POST', {
                    catId: catId,
                    compId: compId,
                    productId: productId,
                    product: productData
                });
                
                if (result && result.success) {
                    // تحديث البيانات المحلية
                    const cat = catalogData.categories.find(c => c.id === catId);
                    if (cat) {
                        const comp = cat.companies.find(c => c.id === compId);
                        if (comp) {
                            const product = comp.products.find(p => p.id === productId);
                            if (product) {
                                product.name = name;
                                product.code = code || '';
                                product.color = color || 'غير محدد';
                                product.available = available;
                                product.image_url = imageUrl || null;
                                if (editProductInfoData.newImage) {
                                    product.img = productData.img;
                                }
                            }
                        }
                    }
                    
                    // تحديث الواجهات
                    render();
                    updateAdminLists();
                    renderStatusServices(statusSearchQuery);
                    renderProductsTable();
                    
                    closeEditProductInfoModal();
                    showToast(`✅ تم تحديث معلومات المنتج "${name}" بنجاح`);
                } else {
                    showToast('❌ ' + (result?.message || 'حدث خطأ في حفظ البيانات'));
                }
            } catch(e) {
                console.error('Error saving product info:', e);
                showToast('❌ حدث خطأ في حفظ معلومات المنتج: ' + e.message);
            }
        }

        // ================================================================
        // ========== دوال إدارة الشركات ==========
        // ================================================================

        function openEditCompanyModal(catId, compId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            
            currentEditingCompanyData = {
                catId: catId,
                compId: compId
            };
            currentEditingCompany = {...comp};
            
            document.getElementById('editCompanyName').value = comp.name || '';
            
            const preview = document.getElementById('editCompanyLogoPreview');
            if (comp.logo) {
                preview.innerHTML = `<img src="${getImageUrl(comp.logo)}" style="width:80px; height:80px; border-radius:12px; object-fit:cover; border:2px solid var(--ac);">`;
            } else {
                preview.innerHTML = '<span style="color:var(--t4); font-size:0.7rem;">🏢 لا يوجد شعار</span>';
            }
            
            document.getElementById('editCompanyModal').classList.add('show');
        }

        function closeEditCompanyModal() {
            document.getElementById('editCompanyModal').classList.remove('show');
            currentEditingCompany = null;
            currentEditingCompanyData = null;
        }

        function previewEditCompanyLogo(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('editCompanyLogoPreview').innerHTML = `<img src="${e.target.result}" style="width:80px; height:80px; border-radius:12px; object-fit:cover; border:2px solid var(--ac);">`;
                    currentEditingCompany.newLogo = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function saveCompanyChanges() {
            if (!currentEditingCompanyData) {
                showToast('❌ لم يتم تحديد الشركة');
                return;
            }
            
            const { catId, compId } = currentEditingCompanyData;
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            
            const newName = document.getElementById('editCompanyName').value.trim();
            if (!newName) {
                showToast('❌ اسم الشركة مطلوب');
                return;
            }
            
            const companyData = {
                name: newName
            };
            
            if (currentEditingCompany && currentEditingCompany.newLogo) {
                companyData.logo = currentEditingCompany.newLogo;
            }
            
            try {
                const result = await apiCall('update_company', 'POST', {
                    catId: catId,
                    compId: compId,
                    company: companyData
                });
                
                if (result.success) {
                    comp.name = companyData.name;
                    
                    if (companyData.logo) {
                        comp.logo = companyData.logo;
                    }
                    
                    render();
                    updateAdminLists();
                    renderStatusServices(statusSearchQuery);
                    refreshStats();
                    
                    closeEditCompanyModal();
                    showToast(`✅ تم تحديث الشركة "${newName}" بنجاح`);
                } else {
                    showToast('❌ ' + (result.message || 'حدث خطأ'));
                }
            } catch(e) {
                console.error('Error saving company:', e);
                showToast('❌ حدث خطأ في حفظ الشركة');
            }
        }

        // ===== إخفاء الشركة =====
        function hideCompany(catId, compId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            
            const companyName = comp.name;
            
            showConfirmModal(
                '👁️ إخفاء الشركة',
                `هل أنت متأكد من إخفاء الشركة "${companyName}"؟<br><span style="color:var(--or);">📌 ستختفي من العرض لكن البيانات محفوظة</span>`,
                async function() {
                    const result = await apiCall('hide_company', 'POST', {
                        catId: catId,
                        compId: compId
                    });
                    
                    if (result.success) {
                        comp.deletedCard = 'ok';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        showToast(`👁️ تم إخفاء الشركة "${companyName}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ===== استعادة الشركة =====
        function restoreCompany(catId, compId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            const comp = cat.companies.find(c => c.id === compId);
            if (!comp) { showToast('❌ الشركة غير موجودة'); return; }
            
            const companyName = comp.name;
            
            showConfirmModal(
                '👁️ استعادة الشركة',
                `هل أنت متأكد من استعادة الشركة "${companyName}"؟`,
                async function() {
                    const result = await apiCall('restore_company', 'POST', {
                        catId: catId,
                        compId: compId
                    });
                    
                    if (result.success) {
                        comp.deletedCard = 'no';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        showToast(`👁️ تم استعادة الشركة "${companyName}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ================================================================
        // ========== دوال عرض قائمة الأقسام والشركات والمواد ==========
        // ================================================================

        function updateAdminLists() {
            let html = '';
            
            if (!catalogData.categories || catalogData.categories.length === 0) {
                document.getElementById('adminCategoriesList').innerHTML = 
                    '<div style="padding:20px; text-align:center; color:var(--t4);">📭 لا توجد أقسام أو خدمات بعد</div>';
                return;
            }
            
            catalogData.categories.forEach(cat => {
                const isCatHidden = cat.deletedCard === 'ok';
                
                html += `<div style="background:${isCatHidden ? 'rgba(239,68,68,0.1)' : 'var(--acSh)'}; border-radius:8px; margin-bottom:10px; padding:12px; border-right:3px solid ${isCatHidden ? 'var(--rd)' : 'var(--ac)'};">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
                                <div>
                                    <i class="fas fa-folder" style="color:${isCatHidden ? 'var(--rd)' : 'var(--ac)'};"></i>
                                    <strong style="font-size:1rem; ${isCatHidden ? 'text-decoration:line-through; color:var(--rd);' : ''}">${escapeHtml(cat.name)}</strong>
                                    ${isCatHidden ? '<span style="font-size:0.6rem; background:var(--rd); color:white; padding:2px 8px; border-radius:10px; margin-right:5px;">👁️ مخفي</span>' : ''}
                                    <span style="font-size:0.65rem; color:var(--t3); background:var(--bg); padding:2px 10px; border-radius:12px; margin-right:8px;">
                                        <i class="fas fa-concierge-bell"></i> ${cat.services ? cat.services.length : 0} خدمات
                                        <i class="fas fa-building" style="margin-right:8px;"></i> ${cat.companies ? cat.companies.length : 0} شركات
                                    </span>
                                </div>
                                <div>
                                    ${isCatHidden ? `
                                        <button onclick="restoreCategory('${cat.id}')" style="background:var(--gn); color:white; border:none; border-radius:6px; padding:4px 12px; cursor:pointer; margin-left:5px; font-size:0.7rem;">
                                            <i class="fas fa-eye"></i> استعادة
                                        </button>
                                    ` : `
                                        <button onclick="hideCategory('${cat.id}')" style="background:var(--or); color:white; border:none; border-radius:6px; padding:4px 12px; cursor:pointer; margin-left:5px; font-size:0.7rem;">
                                            <i class="fas fa-eye-slash"></i> إخفاء
                                        </button>
                                    `}
                                    <button onclick="deleteCategory('${cat.id}')" style="background:rgba(239,68,68,0.2); border:none; border-radius:6px; padding:4px 12px; cursor:pointer; color:var(--rd); font-size:0.7rem;">
                                        <i class="fas fa-trash"></i> حذف
                                    </button>
                                </div>
                            </div>
                        </div>`;
                
                // عرض الخدمات
                if (cat.services && cat.services.length > 0) {
                    html += `<div style="margin-right:15px; margin-bottom:8px; padding:5px 10px; background:var(--bg); border-radius:8px; border-right:2px solid var(--ac2);">
                                <div style="font-size:0.75rem; color:var(--ac); font-weight:700; margin-bottom:8px;">
                                    <i class="fas fa-concierge-bell"></i> خدمات ${escapeHtml(cat.name)}
                                </div>`;
                    
                    cat.services.forEach((service, idx) => {
                        const isAvailable = service.available !== false;
                        const isHidden = service.deletedCard === 'ok';
                        const statusText = isHidden ? '👁️ مخفي' : (isAvailable ? '✅ متوفر' : '❌ غير متوفر');
                        const statusColor = isHidden ? 'var(--rd)' : (isAvailable ? 'var(--gn)' : 'var(--rd)');
                        const statusBg = isHidden ? 'rgba(239,68,68,0.15)' : (isAvailable ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)');
                        
                        html += `<div style="background:${isHidden ? 'rgba(239,68,68,0.05)' : (isAvailable ? 'var(--bg2)' : 'rgba(239,68,68,0.05)')}; 
                                    border:1.5px solid ${isHidden ? 'var(--rd)' : (isAvailable ? 'var(--cardBd)' : 'var(--rd)')}; 
                                    border-radius:10px; padding:10px 14px; margin-bottom:8px; margin-right:10px;">
                                    
                                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                                        <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:150px;">
                                            <span style="font-size:1.2rem;">🔔</span>
                                            <span style="font-weight:700; font-size:0.9rem; ${isHidden ? 'text-decoration:line-through; color:var(--rd);' : 'color:var(--t1);'}">${escapeHtml(service.name)}</span>
                                            ${service.color ? `<span style="font-size:0.65rem; color:var(--t3);">🎨 ${escapeHtml(service.color)}</span>` : ''}
                                            ${isHidden ? '<span style="font-size:0.55rem; background:var(--rd); color:white; padding:2px 8px; border-radius:10px;">👁️ مخفي</span>' : ''}
                                        </div>
                                        <span style="font-size:0.7rem; padding:3px 12px; border-radius:20px; font-weight:600; 
                                            background:${statusBg}; color:${statusColor};">
                                            ${statusText}
                                        </span>
                                    </div>
                                    
                                    ${service.notes ? `<div style="font-size:0.65rem; color:var(--t3); padding:4px 10px; background:var(--bg); border-radius:6px; border-right:2px solid var(--ac); margin-top:6px;">
                                        <i class="fas fa-sticky-note"></i> ${escapeHtml(service.notes)}
                                    </div>` : ''}
                                    
                                    <div style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">
                                        <button onclick="openEditServiceModal('${cat.id}', ${idx})" 
                                            style="background:var(--ac); color:white; border:none; border-radius:6px; padding:5px 14px; cursor:pointer; font-size:0.7rem; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fas fa-edit"></i> تعديل
                                        </button>
                                        <button onclick="toggleItemStatus('service_${cat.id}_${idx}', !${isAvailable}, 'service', '${cat.id}', '', ${idx}, '${service.id}')"
                                            style="background:transparent; border:1.5px solid ${isAvailable ? 'var(--gn)' : 'var(--rd)'}; 
                                            border-radius:6px; padding:5px 14px; cursor:pointer; color:${isAvailable ? 'var(--gn)' : 'var(--rd)'}; 
                                            font-size:0.7rem; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fas ${isAvailable ? 'fa-toggle-on' : 'fa-toggle-off'}"></i> 
                                            ${isAvailable ? 'تعطيل' : 'تفعيل'}
                                        </button>
                                        ${isHidden ? `
                                            <button onclick="restoreService('${cat.id}',${idx})" 
                                                style="background:var(--gn); color:white; border:none; border-radius:6px; padding:5px 14px; cursor:pointer; font-size:0.7rem; display:inline-flex; align-items:center; gap:4px;">
                                                <i class="fas fa-eye"></i> استعادة
                                            </button>
                                        ` : `
                                            <button onclick="hideService('${cat.id}',${idx})" 
                                                style="background:var(--or); color:white; border:none; border-radius:6px; padding:5px 14px; cursor:pointer; font-size:0.7rem; display:inline-flex; align-items:center; gap:4px;">
                                                <i class="fas fa-eye-slash"></i> إخفاء
                                            </button>
                                        `}
                                    </div>
                                </div>`;
                    });
                    
                    html += `</div>`;
                } else {
                    html += `<div style="margin-right:15px; padding:8px 15px; font-size:0.75rem; color:var(--t4);">
                                <i class="fas fa-info-circle"></i> لا توجد خدمات في هذه الفئة
                            </div>`;
                }
                
                // عرض الشركات والمنتجات
                if (cat.companies && cat.companies.length > 0) {
                    html += `<div style="margin-right:15px; margin-top:5px; padding:5px 10px; background:var(--bg); border-radius:8px; border-right:2px solid var(--or);">
                                <div style="font-size:0.7rem; color:var(--or); font-weight:700; margin-bottom:5px;">
                                    <i class="fas fa-building"></i> شركات ${escapeHtml(cat.name)}
                                </div>`;
                    
                    cat.companies.forEach(comp => {
                        const isCompHidden = comp.deletedCard === 'ok';
                        
                        html += `<div style="margin-right:10px; padding:8px 10px; background:${isCompHidden ? 'rgba(239,68,68,0.05)' : 'var(--bg2)'}; border-radius:8px; margin-bottom:5px; border:1px solid ${isCompHidden ? 'var(--rd)' : 'var(--cardBd)'};">
                                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                                        <div style="display:flex; align-items:center; gap:6px; flex:1;">
                                            <i class="fas fa-building" style="color:${isCompHidden ? 'var(--rd)' : 'var(--ac2)'};"></i>
                                            <strong style="font-size:0.8rem; ${isCompHidden ? 'text-decoration:line-through; color:var(--rd);' : ''}">${escapeHtml(comp.name)}</strong>
                                            ${isCompHidden ? '<span style="font-size:0.5rem; background:var(--rd); color:white; padding:2px 8px; border-radius:10px;">👁️ مخفي</span>' : ''}
                                            <span style="font-size:0.6rem; color:var(--t3);">(${comp.products ? comp.products.length : 0} منتجات)</span>
                                        </div>
                                        <div style="display:flex; gap:4px;">
                                            <button onclick="openEditCompanyModal('${cat.id}','${comp.id}')" 
                                                style="background:var(--ac); color:white; border:none; border-radius:6px; padding:4px 12px; cursor:pointer; font-size:0.65rem; display:inline-flex; align-items:center; gap:3px;">
                                                <i class="fas fa-edit"></i> تعديل
                                            </button>
                                            ${isCompHidden ? `
                                                <button onclick="restoreCompany('${cat.id}','${comp.id}')" 
                                                    style="background:var(--gn); color:white; border:none; border-radius:6px; padding:4px 12px; cursor:pointer; font-size:0.65rem; display:inline-flex; align-items:center; gap:3px;">
                                                    <i class="fas fa-eye"></i> استعادة
                                                </button>
                                            ` : `
                                                <button onclick="hideCompany('${cat.id}','${comp.id}')" 
                                                    style="background:var(--or); color:white; border:none; border-radius:6px; padding:4px 12px; cursor:pointer; font-size:0.65rem; display:inline-flex; align-items:center; gap:3px;">
                                                    <i class="fas fa-eye-slash"></i> إخفاء
                                                </button>
                                            `}
                                        </div>
                                    </div>
                                    
                                    ${comp.products && comp.products.length > 0 ? `
                                        <div style="margin-right:20px; margin-top:5px;">
                                            ${comp.products.map((prod, pIdx) => {
                                                const prodAvailable = prod.available !== false;
                                                const prodHidden = prod.deletedCard === 'ok';
                                                return `
                                                    <div style="display:flex; justify-content:space-between; align-items:center; padding:4px 8px; border-bottom:1px dotted var(--cardBd); flex-wrap:wrap; gap:4px; ${prodHidden ? 'background:rgba(239,68,68,0.05);' : ''}">
                                                        <div style="display:flex; align-items:center; gap:6px; flex:1;">
                                                            <i class="fas fa-box" style="color:${prodHidden ? 'var(--rd)' : 'var(--or)'}; font-size:0.65rem;"></i>
                                                            <span style="font-size:0.7rem; ${prodHidden ? 'text-decoration:line-through; color:var(--rd);' : ''}">${escapeHtml(prod.name)}</span>
                                                            ${prod.code ? `<span style="font-size:0.5rem; color:var(--t3);">📋 ${escapeHtml(prod.code)}</span>` : ''}
                                                            ${prod.color ? `<span style="font-size:0.5rem; color:var(--t3);">🎨 ${escapeHtml(prod.color)}</span>` : ''}
                                                            ${prodHidden ? '<span style="font-size:0.5rem; background:var(--rd); color:white; padding:2px 8px; border-radius:10px;">👁️ مخفي</span>' : ''}
                                                            <span style="font-size:0.5rem; padding:2px 8px; border-radius:10px; ${prodAvailable ? 'background:#d4edda; color:#155724;' : 'background:#f8d7da; color:#721c24;'}">
                                                                ${prodAvailable ? '✅ متوفر' : '❌ غير متوفر'}
                                                            </span>
                                                        </div>
                                                        <div style="display:flex; gap:3px;">
                                                            <!-- زر تعديل معلومات المنتج -->
                                                            <button onclick="openEditProductInfoModal('${cat.id}','${comp.id}','${prod.id}')" 
                                                                style="background:var(--or); color:white; border:none; border-radius:4px; padding:3px 10px; cursor:pointer; font-size:0.55rem; display:inline-flex; align-items:center; gap:2px;">
                                                                <i class="fas fa-pen"></i>
                                                            </button>
                                                            <button onclick="toggleItemStatus('product_${cat.id}_${comp.id}_${pIdx}', !${prodAvailable}, 'product', '${cat.id}', '${comp.id}', ${pIdx}, '${prod.id}')"
                                                                style="background:transparent; border:1px solid ${prodAvailable ? 'var(--gn)' : 'var(--rd)'}; border-radius:4px; padding:3px 10px; cursor:pointer; color:${prodAvailable ? 'var(--gn)' : 'var(--rd)'}; font-size:0.55rem; display:inline-flex; align-items:center; gap:2px;">
                                                                <i class="fas ${prodAvailable ? 'fa-toggle-on' : 'fa-toggle-off'}"></i>
                                                            </button>
                                                            ${prodHidden ? `
                                                                <button onclick="restoreProduct('${cat.id}','${comp.id}',${pIdx})" 
                                                                    style="background:var(--gn); color:white; border:none; border-radius:4px; padding:3px 10px; cursor:pointer; font-size:0.55rem; display:inline-flex; align-items:center; gap:2px;">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                            ` : `
                                                                <button onclick="hideProduct('${cat.id}','${comp.id}',${pIdx})" 
                                                                    style="background:var(--or); color:white; border:none; border-radius:4px; padding:3px 10px; cursor:pointer; font-size:0.55rem; display:inline-flex; align-items:center; gap:2px;">
                                                                    <i class="fas fa-eye-slash"></i>
                                                                </button>
                                                            `}
                                                        </div>
                                                    </div>
                                                `;
                                            }).join('')}
                                        </div>
                                    ` : `<div style="margin-right:20px; font-size:0.6rem; color:var(--t4); padding:4px 8px;">لا توجد منتجات</div>`}
                                </div>`;
                    });
                    
                    html += `</div>`;
                }
            });
            
            document.getElementById('adminCategoriesList').innerHTML = html;
        }

        // ================================================================
        // ========== دوال إدارة الفئات (إخفاء/استعادة/حذف) ==========
        // ================================================================

        // ===== إخفاء الفئة =====
        function hideCategory(catId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            
            showConfirmModal(
                '👁️ إخفاء الفئة',
                `هل أنت متأكد من إخفاء الفئة "${cat.name}"؟<br><span style="color:var(--or);">📌 ستختفي من العرض لكن البيانات محفوظة</span>`,
                async function() {
                    const result = await apiCall('hide_category', 'POST', {
                        catId: catId
                    });
                    
                    if (result.success) {
                        cat.deletedCard = 'ok';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        renderProductsTable();
                        showToast(`👁️ تم إخفاء الفئة "${cat.name}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ===== استعادة الفئة =====
        function restoreCategory(catId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            
            showConfirmModal(
                '👁️ استعادة الفئة',
                `هل أنت متأكد من استعادة الفئة "${cat.name}"؟`,
                async function() {
                    const result = await apiCall('restore_category', 'POST', {
                        catId: catId
                    });
                    
                    if (result.success) {
                        cat.deletedCard = 'no';
                        render();
                        updateAdminLists();
                        renderStatusServices(statusSearchQuery);
                        renderProductsTable();
                        showToast(`👁️ تم استعادة الفئة "${cat.name}"`);
                    } else {
                        showToast('❌ ' + (result.message || 'حدث خطأ'));
                    }
                }
            );
        }

        // ===== حذف الفئة (نهائياً) =====
        function deleteCategory(catId) {
            const cat = catalogData.categories.find(c => c.id === catId);
            if (!cat) { showToast('❌ الفئة غير موجودة'); return; }
            
            showConfirmModal(
                '🗑️ حذف الفئة',
                `هل أنت متأكد من حذف الفئة "${cat.name}" بشكل دائم؟<br><span style="color:var(--rd); font-weight:bold;">⚠️ سيتم حذف جميع الخدمات والشركات والمنتجات التابعة لها!</span>`,
                async function() {
                    catalogData.categories = catalogData.categories.filter(c => c.id !== catId);
                    await saveCatalog();
                    render();
                    updateAdminLists();
                    updateAdminSelects();
                    refreshStats();
                    renderStatusServices(statusSearchQuery);
                    renderProductsTable();
                    showToast(`🗑️ تم حذف الفئة "${cat.name}" بشكل دائم`);
                },
                null,
                true
            );
        }

        // ================================================================
        // ========== دوال تسجيل الدخول والحساب ==========
        // ================================================================

        function generateCaptcha() {
            const canvas = document.getElementById('registerCaptchaCanvas');
            if(!canvas) return;
            const ctx = canvas.getContext('2d');
            const width = canvas.width;
            const height = canvas.height;
            
            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = '#f8fafc';
            ctx.fillRect(0, 0, width, height);
            
            let code = "";
            for(let i = 0; i < 4; i++) {
                code += Math.floor(Math.random() * 10);
            }
            currentCaptchaCode = code;
            
            for(let i = 0; i < 120; i++) {
                ctx.fillStyle = `rgba(100, 100, 100, ${Math.random() * 0.3})`;
                ctx.fillRect(Math.random() * width, Math.random() * height, 2, 2);
            }
            
            for(let i = 0; i < code.length; i++) {
                const char = code[i];
                const x = 25 + (i * 40) + (Math.random() * 8 - 4);
                const y = 30 + (Math.random() * 8 - 4);
                const rotation = (Math.random() - 0.5) * 0.4;
                const fontSize = 22 + Math.floor(Math.random() * 10);
                
                ctx.save();
                ctx.translate(x, y);
                ctx.rotate(rotation);
                ctx.font = `${fontSize}px 'Tajawal', monospace`;
                ctx.fillStyle = `rgb(${40 + Math.random() * 80}, ${40 + Math.random() * 80}, ${150 + Math.random() * 100})`;
                ctx.fillText(char, -10, 5);
                ctx.restore();
            }
        }

        function blockRegisterForm(seconds) {
            isBlocked = true;
            blockUntil = Date.now() + (seconds * 1000);
            const btn = document.getElementById('registerBtn');
            const timerDiv = document.getElementById('registerWaitingTimer');
            
            if(btn) btn.disabled = true;
            if(timerDiv) timerDiv.style.display = 'block';
            
            const updateTimer = setInterval(() => {
                const remaining = Math.ceil((blockUntil - Date.now()) / 1000);
                if(remaining <= 0) {
                    clearInterval(updateTimer);
                    if(timerDiv) timerDiv.style.display = 'none';
                    if(btn) btn.disabled = false;
                    isBlocked = false;
                    blockUntil = 0;
                    failedAttempts = 0;
                    showToast('✅ تم فتح النموذج، يمكنك المحاولة مرة أخرى.');
                } else {
                    if(timerDiv) timerDiv.innerHTML = `<i class="fas fa-hourglass-half"></i> يرجى الانتظار ${remaining} ثانية قبل المحاولة مرة أخرى.`;
                }
            }, 1000);
        }

        function handleRegister() {
            clearMessages();
            
            if(isBlocked || (blockUntil > Date.now())) {
                const remaining = Math.ceil((blockUntil - Date.now()) / 1000);
                showToast(`⏳ النموذج مقفل مؤقتاً، يرجى الانتظار ${remaining} ثانية.`);
                return;
            }
            
            const fullname = document.getElementById('regFullname').value.trim();
            const email = document.getElementById('regEmail').value.trim();
            const password = document.getElementById('regPassword').value;
            const confirmPass = document.getElementById('regConfirmPass').value;
            const captchaValue = document.getElementById('registerCaptchaInput').value.trim();
            
            if(!fullname) { showToast('يرجى إدخال الاسم الكامل', 'error'); return; }
            if(!email) { showToast('يرجى إدخال البريد الإلكتروني', 'error'); return; }
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if(!emailRegex.test(email)) { showToast('البريد الإلكتروني غير صالح', 'error'); return; }
            if(!password) { showToast('يرجى إدخال كلمة المرور', 'error'); return; }
            if(password.length < 4) { showToast('كلمة المرور 4 أحرف على الأقل', 'error'); return; }
            if(password !== confirmPass) { showToast('كلمة المرور غير متطابقة', 'error'); return; }
            if(!captchaValue) { showToast('يرجى إدخال رمز التحقق', 'error'); return; }
            
            if(captchaValue !== currentCaptchaCode) {
                failedAttempts++;
                generateCaptcha();
                document.getElementById('registerCaptchaInput').value = '';
                
                if(failedAttempts < 3) {
                    showToast(`❌ رمز التحقق غير صحيح! متبقي ${3 - failedAttempts} محاولات.`, 'error');
                }
                
                if(failedAttempts >= 3) {
                    showToast(`🔒 لقد تجاوزت الحد المسموح (3 محاولات خاطئة). سيتم قفل النموذج لمدة 60 ثانية.`, 'error');
                    blockRegisterForm(60);
                }
                return;
            }
            
            registerUser(fullname, email, password);
        }

        async function registerUser(fullname, email, password) {
            const result = await apiCall('register', 'POST', { fullname, email, password });
            
            if(result.success) {
                showToast('✅ تم إنشاء الحساب بنجاح!', 'success');
                document.getElementById('regFullname').value = '';
                document.getElementById('regEmail').value = '';
                document.getElementById('regPassword').value = '';
                document.getElementById('regConfirmPass').value = '';
                document.getElementById('registerCaptchaInput').value = '';
                generateCaptcha();
                failedAttempts = 0;
                setTimeout(() => switchTab('login'), 1500);
            } else {
                showToast(result.message || 'فشل إنشاء الحساب', 'error');
            }
        }

        function clearMessages() {
            const errorDiv = document.getElementById('authError');
            const successDiv = document.getElementById('authSuccess');
            if(errorDiv) errorDiv.classList.remove('sh');
            if(successDiv) successDiv.classList.remove('sh');
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            if (tab === 'login') {
                document.querySelector('.tab-btn:first-child').classList.add('active');
                document.getElementById('loginTab').classList.add('active');
            } else {
                document.querySelector('.tab-btn:last-child').classList.add('active');
                document.getElementById('registerTab').classList.add('active');
                generateCaptcha();
                failedAttempts = 0;
            }
            document.getElementById('authError').classList.remove('sh');
            document.getElementById('authSuccess').classList.remove('sh');
        }

        async function login() {
            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value;
            const errorDiv = document.getElementById('authError');
            const successDiv = document.getElementById('authSuccess');
            
            errorDiv.classList.remove('sh');
            successDiv.classList.remove('sh');
            
            if (!email || !password) {
                errorDiv.innerText = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
                errorDiv.classList.add('sh');
                return;
            }
            
            const result = await apiCall('login', 'POST', { email, password });
            
            if (result.success) {
                currentUser = result.user;
                isGuestMode = false;
                localStorage.setItem('user', JSON.stringify(currentUser));
                localStorage.removeItem('isGuest');
                successDiv.innerText = `مرحباً ${currentUser.fullname}! جاري تحويلك...`;
                successDiv.classList.add('sh');
                setTimeout(() => {
                    window.location.href = window.location.pathname + '?page=app';
                }, 1000);
            } else {
                errorDiv.innerText = result.message || 'فشل تسجيل الدخول';
                errorDiv.classList.add('sh');
            }
        }

        function browseAsGuest() {
            isGuestMode = true;
            currentUser = { id: 0, fullname: 'زائر', email: 'guest@temp.com', isAdmin: false };
            localStorage.setItem('isGuest', 'true');
            localStorage.removeItem('user');
            
            const loginScreen = document.getElementById('loginScreen');
            const appScreen = document.getElementById('appScreen');
            if(loginScreen) loginScreen.classList.add('hide');
            if(appScreen) appScreen.style.display = 'block';
            
            loadData();
            showToast('🌐 مرحباً بك في وضع التصفح كزائر');
        }

        function logout() {
            apiCall('logout', 'POST').catch(() => {});
            localStorage.removeItem('user');
            localStorage.removeItem('isGuest');
            localStorage.removeItem('favorites');
            localStorage.removeItem('cart');
            showToast('👋 تم تسجيل الخروج بنجاح');
            setTimeout(() => {
                window.location.href = window.location.pathname;
            }, 500);
        }

        function updateProfileDisplay() {
            if (currentUser && !isGuestMode) {
                document.getElementById('displayName').innerText = currentUser.fullname;
                document.getElementById('displayEmail').innerText = currentUser.email;
                document.getElementById('userNameDisplay').innerHTML = currentUser?.isAdmin ? `<i class="fas fa-user-shield"></i> ${escapeHtml(currentUser.fullname)}` : `<i class="fas fa-user"></i> ${escapeHtml(currentUser.fullname)}`;
                document.getElementById('adminBtn').style.display = currentUser?.isAdmin ? 'inline-block' : 'none';
            } else if (isGuestMode) {
                document.getElementById('displayName').innerText = 'زائر';
                document.getElementById('displayEmail').innerText = 'وضع التصفح كزائر';
                document.getElementById('userNameDisplay').innerHTML = '<i class="fas fa-user-friends"></i> زائر';
                document.getElementById('adminBtn').style.display = 'none';
            }
        }

        // ================================================================
        // ========== دوال الملف الشخصي ==========
        // ================================================================

        function hideAllForms() {
            const editForm = document.getElementById('editForm');
            const passwordForm = document.getElementById('passwordForm');
            const viewCard = document.getElementById('viewCard');
            if (editForm) editForm.classList.remove('show');
            if (passwordForm) passwordForm.classList.remove('show');
            if (viewCard) viewCard.style.display = 'block';
        }

        function showEditForm() {
            if (isGuestMode) {
                showToast('⚠️ يرجى تسجيل الدخول لتعديل الملف الشخصي');
                return;
            }
            document.getElementById('editFullname').value = currentUser?.fullname || '';
            document.getElementById('editEmail').value = currentUser?.email || '';
            document.getElementById('viewCard').style.display = 'none';
            document.getElementById('editForm').classList.add('show');
        }

        function hideEditForm() { hideAllForms(); }

        function showPasswordForm() {
            if (isGuestMode) {
                showToast('⚠️ يرجى تسجيل الدخول لتغيير كلمة المرور');
                return;
            }
            document.getElementById('currentPass').value = '';
            document.getElementById('newPass').value = '';
            document.getElementById('confirmPass').value = '';
            document.getElementById('viewCard').style.display = 'none';
            document.getElementById('passwordForm').classList.add('show');
        }

        function hidePasswordForm() { hideAllForms(); }

        async function saveProfileChanges() {
            const fullname = document.getElementById('editFullname').value.trim();
            const email = document.getElementById('editEmail').value.trim();

            if (!fullname) {
                showToast('❌ الاسم الكامل مطلوب');
                return;
            }
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email || !emailRegex.test(email)) {
                showToast('❌ البريد الإلكتروني غير صالح');
                return;
            }

            try {
                showToast('⏳ جاري حفظ التغييرات...');
                
                const result = await apiCall('update_profile', 'POST', {
                    userId: currentUser.id,
                    fullname: fullname,
                    email: email,
                    currentPassword: '',
                    newPassword: ''
                });

                console.log('Save profile result:', result);

                if (result && result.success) {
                    if (result.user) {
                        currentUser = result.user;
                        localStorage.setItem('user', JSON.stringify(currentUser));
                        updateProfileDisplay();
                    }
                    showToast('✅ تم تحديث الملف الشخصي بنجاح');
                    hideAllForms();
                } else {
                    showToast('❌ ' + (result?.message || 'فشل تحديث الملف الشخصي'));
                }
            } catch(e) {
                console.error('Error saving profile:', e);
                showToast('❌ حدث خطأ في حفظ التغييرات: ' + e.message);
            }
        }

        async function changePassword() {
            const currentPass = document.getElementById('currentPass').value;
            const newPass = document.getElementById('newPass').value;
            const confirmPass = document.getElementById('confirmPass').value;

            if (!currentPass) {
                showToast('❌ يرجى إدخال كلمة المرور الحالية');
                return;
            }
            if (!newPass) {
                showToast('❌ يرجى إدخال كلمة المرور الجديدة');
                return;
            }
            if (newPass.length < 4) {
                showToast('❌ كلمة المرور الجديدة 4 أحرف على الأقل');
                return;
            }
            if (newPass !== confirmPass) {
                showToast('❌ كلمة المرور غير متطابقة');
                return;
            }

            try {
                showToast('⏳ جاري تغيير كلمة المرور...');
                
                const result = await apiCall('update_profile', 'POST', {
                    userId: currentUser.id,
                    fullname: currentUser.fullname,
                    email: currentUser.email,
                    currentPassword: currentPass,
                    newPassword: newPass
                });

                console.log('Change password result:', result);

                if (result && result.success) {
                    if (result.user) {
                        currentUser = result.user;
                        localStorage.setItem('user', JSON.stringify(currentUser));
                        updateProfileDisplay();
                    }
                    showToast('✅ تم تغيير كلمة المرور بنجاح');
                    hideAllForms();
                    document.getElementById('currentPass').value = '';
                    document.getElementById('newPass').value = '';
                    document.getElementById('confirmPass').value = '';
                } else {
                    showToast('❌ ' + (result?.message || 'فشل تغيير كلمة المرور'));
                }
            } catch(e) {
                console.error('Error changing password:', e);
                showToast('❌ حدث خطأ في تغيير كلمة المرور: ' + e.message);
            }
        }

        function showDeleteAccountModal() {
            if (isGuestMode) { 
                showToast('⚠️ لا يمكن حذف حساب الزائر'); 
                return; 
            }
            document.getElementById('deletePasswordInput').value = '';
            document.getElementById('deletePasswordError').style.display = 'none';
            document.getElementById('deleteAccountModal').classList.add('show');
        }

        function closeDeleteAccountModal() {
            document.getElementById('deleteAccountModal').classList.remove('show');
            document.getElementById('deletePasswordInput').value = '';
            document.getElementById('deletePasswordError').style.display = 'none';
        }

        async function confirmDeleteAccount() {
            const password = document.getElementById('deletePasswordInput').value;
            const errorDiv = document.getElementById('deletePasswordError');
            const confirmBtn = document.getElementById('deleteAccountConfirmBtn');
            
            if (!password) {
                errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> يرجى إدخال كلمة المرور';
                errorDiv.style.display = 'block';
                return;
            }
            
            if (password.length < 4) {
                errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> كلمة المرور يجب أن تكون 4 أحرف على الأقل';
                errorDiv.style.display = 'block';
                return;
            }
            
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التحقق...';
            
            const result = await apiCall('verify_password', 'POST', { 
                userId: currentUser.id, 
                password: password 
            });
            
            if (result.success) {
                const deleteResult = await apiCall('delete_account', 'POST', { 
                    userId: currentUser.id, 
                    password: password 
                });
                
                if (deleteResult.success) {
                    showToast('✅ تم حذف الحساب بنجاح');
                    setTimeout(() => logout(), 1500);
                } else {
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + deleteResult.message;
                    errorDiv.style.display = 'block';
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fas fa-trash-alt"></i> تأكيد الحذف';
                }
            } else {
                errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> كلمة المرور غير صحيحة';
                errorDiv.style.display = 'block';
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-trash-alt"></i> تأكيد الحذف';
            }
        }

        function deleteAccountPreview() {
            showDeleteAccountModal();
        }

        // ================================================================
        // ========== دوال المشاركة ==========
        // ================================================================

        function shareViaWhatsApp() {
            const text = `📱 *${APP_NAME}*\n\n${APP_DESCRIPTION}\n\n📲 حمل التطبيق الآن:\n${APP_URL}`;
            window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
        }

        function shareViaFacebook() { 
            window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(APP_URL)}`, '_blank'); 
        }

        function copyShareLink() { 
            navigator.clipboard.writeText(APP_URL).then(() => showToast('✅ تم نسخ الرابط')).catch(() => showToast('❌ فشل النسخ')); 
        }

        function shareViaNative() { 
            if (navigator.share) { 
                navigator.share({ title: APP_NAME, text: APP_DESCRIPTION, url: APP_URL }).catch(() => {}); 
            } else { 
                copyShareLink(); 
            } 
        }

        function shareViaEmail() { 
            window.location.href = `mailto:?subject=${encodeURIComponent(`📱 حمل تطبيق ${APP_NAME}`)}&body=${encodeURIComponent(`مرحباً،\n\nأدعوك لتجربة تطبيق ${APP_NAME}\n\n${APP_DESCRIPTION}\n\nرابط التحميل: ${APP_URL}`)}`; 
        }

        function shareViaSMS() { 
            window.location.href = `sms:?body=${encodeURIComponent(`📱 حمل تطبيق ${APP_NAME}: ${APP_URL}`)}`; 
        }

        function showLanguageMenu() { showToast('🌐 العربية | English قريباً'); }

        function toggleTheme() {
            const body = document.body;
            const isDark = body.getAttribute('data-t') === 'dark';
            body.setAttribute('data-t', isDark ? 'light' : 'dark');
            localStorage.setItem('theme', !isDark ? 'dark' : 'light');
        }

        // ================================================================
        // ========== دوال السلة ==========
        // ================================================================

        function addToCart(product) { 
            if (product.available === false) {
                showToast('⚠️ هذا المنتج غير متوفر حالياً');
                return;
            }
            
            if (product.img && !product.img.startsWith('data:') && !product.img.startsWith('http')) {
                product.img = getImageUrl(product.img);
            }
            
            const idx = cart.findIndex(i => i.name === product.name && i.code === product.code);
            if (idx !== -1) { 
                cart[idx].quantity = (cart[idx].quantity || 1) + 1; 
            } else { 
                cart.push({ ...product, quantity: 1, img: product.img || 'https://iili.io/CKP5shF.jpg' }); 
            }
            saveCart(); 
            showToast(`✅ تم إضافة "${product.name}" إلى السلة`);
            
            if (product.isService) {
                trackServiceRequest(product.name, product.category);
            } else {
                trackProductRequest(product.name, product.category || 'منتج');
            }
        }

        function addToCartWithQuantity(product, quantity = 1) {
            if (product.available === false) {
                showToast('⚠️ هذا المنتج غير متوفر حالياً');
                return;
            }
            
            if (quantity < 1) quantity = 1;
            if (product.img && !product.img.startsWith('data:') && !product.img.startsWith('http')) {
                product.img = getImageUrl(product.img);
            }
            
            const idx = cart.findIndex(i => i.name === product.name && i.code === product.code);
            if (idx !== -1) { 
                cart[idx].quantity = (cart[idx].quantity || 1) + quantity; 
            } else { 
                cart.push({ ...product, quantity: quantity, img: product.img || 'https://iili.io/CKP5shF.jpg' }); 
            }
            saveCart(); 
            showToast(`✅ تم إضافة ${quantity} من "${product.name}" إلى السلة`);
            
            if (product.isService) {
                trackServiceRequest(product.name, product.category);
            } else {
                trackProductRequest(product.name, product.category || 'منتج');
            }
        }

        function increaseQuantity(index) {
            if (cart[index]) {
                cart[index].quantity = (cart[index].quantity || 1) + 1;
                saveCart();
                showToast(`🔺 تم زيادة كمية "${cart[index].name}" إلى ${cart[index].quantity}`);
            }
        }

        function decreaseQuantity(index) {
            if (cart[index]) {
                const currentQty = cart[index].quantity || 1;
                if (currentQty > 1) {
                    cart[index].quantity = currentQty - 1;
                    saveCart();
                    showToast(`🔻 تم تقليل كمية "${cart[index].name}" إلى ${cart[index].quantity}`);
                } else {
                    if (confirm(`هل تريد حذف "${cart[index].name}" من السلة؟`)) {
                        const removedName = cart[index].name;
                        cart.splice(index, 1);
                        saveCart();
                        showToast(`🗑️ تم حذف "${removedName}" من السلة`);
                    }
                }
            }
        }

        function removeFromCart(index) { 
            const removedName = cart[index].name;
            cart.splice(index,1); 
            saveCart(); 
            showToast(`🗑️ تم حذف "${removedName}" من السلة`); 
        }

        function updateCartUI() {
            const totalItems = cart.reduce((sum, item) => sum + (item.quantity || 1), 0);
            const cartNavBadge = document.getElementById('cartNavBadge');
            if (cartNavBadge) {
                if (totalItems > 0) {
                    cartNavBadge.innerText = totalItems > 99 ? '99+' : totalItems;
                    cartNavBadge.style.display = 'block';
                } else {
                    cartNavBadge.style.display = 'none';
                }
            }
            
            const container = document.getElementById('cartItems');
            const footer = document.getElementById('cartFooter');
            if (!container) return;
            
            if (cart.length === 0) { 
                container.innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-shopping-cart" style="font-size:2.5rem; color:var(--t4); margin-bottom:10px; display:block;"></i>🛒 السلة فارغة</div>'; 
                if(footer) footer.style.display = 'none'; 
                return; 
            }
            
            if(footer) footer.style.display = 'block';
            
            let html = '';
            cart.forEach((item, idx) => {
                const quantity = item.quantity || 1;
                const imgSrc = item.img || 'https://iili.io/CKP5shF.jpg';
                html += `<div style="display:flex; gap:8px; padding:8px; border-bottom:1px solid var(--cardBd); position:relative;">
                            <img src="${imgSrc}" style="width:55px; height:55px; border-radius:8px; object-fit:cover;">
                            <div style="flex:1;">
                                <div style="font-weight:700; font-size:0.8rem;">${escapeHtml(item.name)}</div>
                                <div style="font-size:0.6rem; color:var(--ac);">${item.code || (item.isService ? 'خدمة' : 'بدون كود')}</div>
                                <div style="font-size:0.6rem;">🎨 ${item.color || 'غير محدد'}</div>
                                ${item.notes ? `<div style="font-size:0.55rem; color:var(--gn); margin-top:2px;"><i class="fas fa-sticky-note"></i> ${escapeHtml(item.notes)}</div>` : ''}
                                <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
                                    <button class="quantity-btn" onclick="decreaseQuantity(${idx})">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <span class="quantity-value">${quantity}</span>
                                    <button class="quantity-btn" onclick="increaseQuantity(${idx})">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <button onclick="removeFromCart(${idx})" style="background:#ff444420; border:none; border-radius:6px; padding:4px 12px; cursor:pointer; color:#ff4444; font-size:0.65rem; margin-right: auto;">
                                        <i class="fas fa-trash"></i> حذف
                                    </button>
                                </div>
                            </div>
                        </div>`;
            });
            container.innerHTML = html;
        }

        function saveCart() { 
            localStorage.setItem('cart', JSON.stringify(cart)); 
            updateCartUI(); 
        }

        function loadCart() { 
            const saved = localStorage.getItem('cart'); 
            if(saved) {
                cart = JSON.parse(saved);
                cart.forEach(item => { if (!item.quantity) item.quantity = 1; });
            }
            updateCartUI(); 
        }

        function toggleCart() { 
            document.getElementById('cartSidebar').classList.toggle('open'); 
        }

        function clearCart() { 
            showConfirmModal(
                '🛒 تفريغ السلة',
                'هل أنت متأكد من تفريغ السلة بالكامل؟ سيتم حذف جميع العناصر المضافة.',
                function() {
                    cart = []; 
                    saveCart(); 
                    showToast('🗑️ تم تفريغ السلة');
                    closeCartSidebar();
                }
            );
        }

        function closeCartSidebar() {
            document.getElementById('cartSidebar').classList.remove('open');
        }

        function checkout() {
            if(cart.length === 0){ showToast('السلة فارغة'); return; }
            
            let msg = `🟥 *طلب حجز جديد*%0a👤 ${currentUser?.fullname || 'زائر'}%0a📧 ${currentUser?.email || 'guest@temp.com'}%0a%0a📋 *المواد والمنتجات المطلوبة:*%0a`;
            
            cart.forEach((item, i) => { 
                const quantity = item.quantity || 1;
                msg += `${i+1}. ${item.name}%0a`;
                msg += `   📦 ${item.code || (item.isService ? 'خدمة' : 'بدون كود')}%0a`;
                msg += `   🎨 ${item.color || 'غير محدد'}%0a`;
                msg += `   🔢 الكمية: ${quantity}%0a`;
                if(item.notes) msg += `   📝 ملاحظات: ${item.notes}%0a`;
                msg += `%0a`;
            });
            
            const totalItems = cart.reduce((sum, item) => sum + (item.quantity || 1), 0);
            msg += `📊 *إجمالي العناصر: ${totalItems}*%0a`;
            
            window.open(`https://wa.me/${appSettings.whatsappNumber}?text=${msg}`, '_blank');
            updateOrdersStats();
        }

        // ================================================================
        // ========== دوال المفضلات ==========
        // ================================================================

        function loadFavorites() {
            const saved = localStorage.getItem('favorites');
            if(saved) favorites = JSON.parse(saved);
            updateFavoritesUI();
            updateFavoritesStats(favorites.length);
        }

        function saveFavorites() {
            localStorage.setItem('favorites', JSON.stringify(favorites));
            updateFavoritesUI();
            updateFavoritesStats(favorites.length);
        }

        function updateFavoritesUI() {
            const favNavBadge = document.getElementById('favoritesNavBadge');
            if (favNavBadge) {
                if (favorites.length > 0) {
                    favNavBadge.innerText = favorites.length > 99 ? '99+' : favorites.length;
                    favNavBadge.style.display = 'block';
                } else {
                    favNavBadge.style.display = 'none';
                }
            }
            
            const container = document.getElementById('favoritesItems');
            if (!container) return;
            
            if (favorites.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-heart" style="font-size:2.5rem; color:var(--t4); margin-bottom:10px; display:block;"></i>❤️ لا توجد مواد أو منتجات في المفضلات</div>';
                return;
            }
            
            let html = '';
            favorites.forEach((item, idx) => {
                const imgSrc = item.img || 'https://iili.io/CKP5shF.jpg';
                html += `<div style="display:flex; gap:10px; padding:8px; border-bottom:1px solid var(--cardBd);">
                            <img src="${imgSrc}" style="width:50px; height:50px; border-radius:10px; object-fit:cover;">
                            <div style="flex:1;">
                                <div style="font-weight:700; font-size:0.8rem;">${escapeHtml(item.name)}</div>
                                <div style="font-size:0.6rem; color:var(--ac);">${item.code || (item.isService ? 'خدمة' : 'بدون كود')}</div>
                                <div style="font-size:0.6rem;">🎨 ${item.color || 'غير محدد'}</div>
                                ${item.notes ? `<div style="font-size:0.55rem; color:var(--gn); margin-top:3px;"><i class="fas fa-sticky-note"></i> ${escapeHtml(item.notes)}</div>` : ''}
                                <div style="display:flex; gap:6px; margin-top:6px;">
                                    <button onclick="addToCartFromFavorites(${idx})" style="background:var(--ac); border:none; border-radius:6px; padding:4px 10px; cursor:pointer; color:white; font-size:0.65rem;"><i class="fas fa-cart-plus"></i> أضف</button>
                                    <button onclick="removeFromFavorites(${idx})" style="background:#ff444420; border:none; border-radius:6px; padding:4px 10px; cursor:pointer; color:#ff4444; font-size:0.65rem;"><i class="fas fa-trash"></i> حذف</button>
                                </div>
                            </div>
                        </div>`;
            });
            container.innerHTML = html;
        }

        function addToFavorites(product) {
            const exists = favorites.some(fav => fav.code === product.code && fav.name === product.name);
            if (!exists) {
                favorites.push({...product, img: product.img || 'https://iili.io/CKP5shF.jpg'});
                saveFavorites();
                showToast(`❤️ تم إضافة "${product.name}" إلى المفضلات`);
                render();
            } else {
                showToast(`⚠️ "${product.name}" موجود بالفعل في المفضلات`);
            }
        }

        function removeFromFavorites(index) {
            const removed = favorites[index];
            favorites.splice(index, 1);
            saveFavorites();
            showToast(`🗑️ تم إزالة "${removed.name}" من المفضلات`);
            render();
        }

        function addToCartFromFavorites(index) {
            const item = favorites[index];
            addToCart(item);
            if (item.isService) trackServiceRequest(item.name, item.category);
        }

        function clearFavorites() { 
            showConfirmModal(
                '🗑️ مسح المفضلات',
                'هل أنت متأكد من مسح جميع المواد والمنتجات من قائمة المفضلات؟',
                function() {
                    favorites = []; 
                    saveFavorites(); 
                    showToast('🗑️ تم مسح جميع المفضلات');
                    render();
                    closeFavoritesSidebar();
                }
            );
        }

        function toggleFavorites() {
            document.getElementById('favoritesSidebar').classList.toggle('open');
        }

        function closeFavoritesSidebar() {
            document.getElementById('favoritesSidebar').classList.remove('open');
        }

        function isProductInFavorites(product) {
            return favorites.some(fav => fav.code === product.code && fav.name === product.name);
        }

        // ================================================================
        // ========== دوال الإحصائيات والخدمات الأكثر طلباً ==========
        // ================================================================

        async function updateFavoritesStats(count) {
            await apiCall('update_favorites_stats', 'POST', { count: count });
        }

        async function updateOrdersStats() {
            await apiCall('update_orders_stats', 'POST');
        }

        async function trackServiceRequest(serviceName, categoryName) {
            await apiCall('track_service_request', 'POST', { 
                serviceName: serviceName, 
                categoryName: categoryName 
            });
            loadMostRequestedItems();
        }

        function addServiceToCartDirectly(serviceName, categoryName) {
            for (let cat of catalogData.categories) {
                if (cat.name === categoryName && cat.services) {
                    const service = cat.services.find(s => s.name === serviceName);
                    if (service && service.available !== false) {
                        addToCart({ 
                            name: service.name, 
                            code: '', 
                            color: service.color || 'خدمة', 
                            img: service.img ? getImageUrl(service.img) : 'https://iili.io/CKP5shF.jpg',
                            notes: service.notes,
                            isService: true,
                            category: cat.name,
                            available: true
                        });
                        trackServiceRequest(serviceName, categoryName);
                        break;
                    } else if (service && service.available === false) {
                        showToast('⚠️ هذه الخدمة غير متوفرة حالياً');
                        break;
                    }
                }
            }
        }

        async function refreshStats() {
            const result = await apiCall('get_stats', 'GET');
            if (result.success) {
                document.getElementById('statUsers').innerText = result.stats.total_users;
                document.getElementById('statCategories').innerText = result.stats.total_categories;
                document.getElementById('statCompanies').innerText = result.stats.total_companies;
                document.getElementById('statProducts').innerText = result.stats.total_products;
                document.getElementById('statServices').innerText = result.stats.total_services;
                document.getElementById('statFavorites').innerText = result.stats.total_favorites;
                document.getElementById('statOrders').innerText = result.stats.total_orders;
                document.getElementById('statVisitors').innerText = result.stats.total_visitors;
            }
        }

        function clearMostRequested() {
            if(confirm('⚠️ هل أنت متأكد من مسح جميع إحصائيات المواد الأكثر طلباً؟')) {
                showToast('✅ تم مسح الإحصائيات');
                loadMostRequestedItems();
            }
        }

        // ================================================================
        // ========== دوال المستخدمين ==========
        // ================================================================

        async function loadUsersList() {
            const result = await apiCall('get_users', 'GET');
            const container = document.getElementById('usersList');
            if (container && result.success) {
                container.innerHTML = result.users.map(user => `
                    <div class="admin-item">
                        <div><strong>${escapeHtml(user.fullname)}</strong><br><span style="font-size:0.65rem;">${escapeHtml(user.email)}</span></div>
                        ${!user.isAdmin ? `<button onclick="deleteUser(${user.id})" style="background:rgba(239,68,68,0.2); border:none; border-radius:6px; padding:4px 8px; cursor:pointer; color:var(--rd);"><i class="fas fa-trash"></i></button>` : '<span style="color:var(--ac); font-size:0.7rem;">مدير</span>'}
                    </div>
                `).join('');
            }
        }

        async function deleteUser(userId) {
            if (confirm('هل أنت متأكد من حذف هذا المستخدم؟')) {
                await apiCall('delete_user', 'POST', { id: userId });
                loadUsersList();
                refreshStats();
                showToast('✅ تم حذف المستخدم');
            }
        }

        // ================================================================
        // ========== دوال الإشعارات ==========
        // ================================================================

        function requestNotification() {
            showConfirmModal(
                '🔔 تفعيل الإشعارات',
                'هل تريد تفعيل الإشعارات؟ ستصلك آخر أخبار التطبيق وأحدث العروض والخصومات.',
                function() {
                    enableNotificationPermission();
                }
            );
        }

        function enableNotificationPermission() {
            if (!("Notification" in window)) { 
                showToast("المتصفح لا يدعم الإشعارات"); 
                return; 
            }
            if (Notification.permission === "granted") { 
                showToast("✅ الإشعارات مفعلة مسبقاً");
                new Notification("🔔 اختبار", { body: "الإشعارات تعمل بشكل جيد", icon: "https://iili.io/CKP5shF.jpg" });
                return;
            }
            if (Notification.permission === "denied") {
                showToast("⚠️ الإشعارات معطلة من المتصفح. يرجى تفعيلها من إعدادات المتصفح");
                return;
            }
            Notification.requestPermission().then(p => { 
                if (p === "granted") {
                    showToast("✅ تم تفعيل الإشعارات بنجاح");
                    new Notification("🔔 مرحباً!", { body: "ستصلك الآن آخر أخبار التطبيق", icon: "https://iili.io/CKP5shF.jpg" });
                } else {
                    showToast("⚠️ لم يتم تفعيل الإشعارات");
                }
            });
        }

        async function sendNotificationToAll() {
            const title = document.getElementById('notifyTitle')?.value.trim();
            const body = document.getElementById('notifyBody')?.value.trim();
            if (!title || !body) { showToast('أدخل عنوان ومحتوى الإشعار'); return; }
            showToast(`📢 تم إرسال الإشعار: ${title}`);
            document.getElementById('notifyTitle').value = '';
            document.getElementById('notifyBody').value = '';
        }

        // ================================================================
        // ========== دوال عرض المحتوى ==========
        // ================================================================

        function render() {
            const content = document.getElementById('contentArea');
            if(!content || currentPage !== 'home') return;
            
            // فلترة الفئات المخفية
            const visibleCategories = catalogData.categories?.filter(cat => cat.deletedCard !== 'ok') || [];
            
            if(currentLevel === 'categories'){
                let html = `<div class="title"><i class="fas fa-folder-open"></i> جميع الاقسام</div><div class="grid">`;
                visibleCategories.forEach(cat => {
                    const imgSrc = getImageUrl(cat.image, 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'%3E%3Crect width=\'100\' height=\'100\' fill=\'%236366f1\'/%3E%3Ctext x=\'50\' y=\'55\' text-anchor=\'middle\' fill=\'white\' font-size=\'40\'%3E📁%3C/text%3E%3C/svg%3E');
                    html += `<div class="card" onclick="navigateTo('category_detail','${cat.id}')">
                                <img class="card-image" src="${imgSrc}" loading="lazy">
                                <div class="card-title">${escapeHtml(cat.name)}</div>
                            </div>`;
                });
                html += `</div>`;
                content.innerHTML = html;
            }
            else if(currentLevel === 'category_detail'){
                const cat = catalogData.categories?.find(c => c.id === currentCategoryId);
                if(!cat || cat.deletedCard === 'ok'){ navigateTo('categories'); return; }
                
                let html = `<div class="back-btn" onclick="navigateTo('categories')"><i class="fas fa-arrow-right"></i> العودة للاقسام</div>`;
                
                // فلترة الخدمات المخفية
                const visibleServices = cat.services?.filter(s => s.deletedCard !== 'ok') || [];
                const hasServices = visibleServices.length > 0;
                
                // فلترة الشركات المخفية ومنتجاتها المخفية
                const visibleCompanies = cat.companies?.filter(c => c.deletedCard !== 'ok') || [];
                const hasCompanies = visibleCompanies.length > 0;
                
                if(hasServices){
                    html += `<div class="title"><i class="fas fa-concierge-bell"></i> المواد ${escapeHtml(cat.name)}</div><div class="grid">`;
                    visibleServices.forEach(service => {
                        const isFav = isProductInFavorites({name: service.name, code: ''});
                        const escapedName = escapeHtml(service.name).replace(/'/g, "\\'");
                        const escapedColor = escapeHtml(service.color).replace(/'/g, "\\'");
                        const escapedNotes = (service.notes || '').replace(/'/g, "\\'");
                        const escapedCatName = escapeHtml(cat.name).replace(/'/g, "\\'");
                        const imageUrl = getImageUrl(service.img, 'https://iili.io/CKP5shF.jpg');
                        const isAvailable = service.available !== false;
                        
                        html += `<div class="service-card ${!isAvailable ? 'service-unavailable' : ''}" style="position:relative;">
                                    ${!isAvailable ? `<div class="unavailable-bar"><i class="fas fa-ban"></i> هذه الخدمة غير متوفرة حالياً</div>` : ''}
                                    <div class="service-image-wrapper">
                                        <img class="service-img" src="${imageUrl}" onclick="event.stopPropagation(); previewImage('${service.img ? service.img : ''}')" loading="lazy">
                                        ${!isAvailable ? `
                                        <div class="unavailable-overlay">
                                            <i class="fas fa-ban"></i>
                                            <span>غير متوفرة حالياً</span>
                                        </div>` : ''}
                                    </div>
                                    <button class="favorite-btn ${isFav ? 'active' : ''}" onclick="event.stopPropagation(); addToFavorites({name:'${escapedName}', code:'', color:'${escapedColor}', img:'${imageUrl}', notes:'${escapedNotes}', isService: true, category:'${escapedCatName}'})">
                                        <i class="fas fa-heart"></i>
                                    </button>
                                    <div class="service-info">
                                        <div class="service-badge" style="font-size:0.5rem; background:var(--or); color:#fff; display:inline-block; padding:2px 6px; border-radius:20px; margin-bottom:4px;">
                                            <i class="fas fa-star"></i> خدمة مباشرة
                                            ${!isAvailable ? '<span style="background:var(--rd); margin-right:5px; padding:0 5px; border-radius:10px;">● غير متوفر</span>' : ''}
                                        </div>
                                        <div class="service-name">${escapeHtml(service.name)}</div>
                                        <div class="service-category">${escapeHtml(cat.name)}</div>
                                        ${service.notes ? `<div class="service-notes"><i class="fas fa-info-circle"></i> ${escapeHtml(service.notes)}</div>` : ''}
                                        ${isAvailable ? 
                                            `<button class="add-btn" onclick="event.stopPropagation(); addToCart({name:'${escapedName}', code:'', color:'${escapedColor}', img:'${imageUrl}', notes:'${escapedNotes}', isService: true, category:'${escapedCatName}', available: true});"><i class="fas fa-cart-plus"></i> طلب</button>` : 
                                            `<button class="add-btn" style="background:#ff444420; border-color:var(--rd); color:var(--rd);" disabled><i class="fas fa-ban"></i> غير متوفر</button>`
                                        }
                                    </div>
                                </div>`;
                    });
                    html += `</div>`;
                }
                
                if(hasCompanies){
                    html += `<div class="title" style="${hasServices ? 'margin-top:25px;' : ''}"><i class="fas fa-building"></i> شركات ${escapeHtml(cat.name)}</div><div class="grid">`;
                    visibleCompanies.forEach(comp => {
                        const imgSrc = getImageUrl(comp.logo, 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'%3E%3Crect width=\'100\' height=\'100\' fill=\'%23818cf8\'/%3E%3Ctext x=\'50\' y=\'55\' text-anchor=\'middle\' fill=\'white\' font-size=\'40\'%3E🏢%3C/text%3E%3C/svg%3E');
                        html += `<div class="card" onclick="navigateTo('products','${currentCategoryId}','${comp.id}')">
                                    <img class="card-image" src="${imgSrc}" loading="lazy">
                                    <div class="card-title">${escapeHtml(comp.name)}</div>
                                    <div class="card-sub" style="font-size:0.6rem; color:var(--t4);">${comp.products?.filter(p => p.deletedCard !== 'ok').length||0} منتج</div>
                                </div>`;
                    });
                    html += `</div>`;
                }
                
                if(!hasServices && !hasCompanies){
                    html += `<div class="title" style="text-align:center; padding:60px 20px; color:var(--t4);">
                                <i class="fas fa-info-circle" style="font-size:3rem; margin-bottom:15px; display:block;"></i>
                                لا توجد مواد أو شركات في هذه الفئة بعد
                            </div>`;
                }
                
                content.innerHTML = html;
            }
            else if(currentLevel === 'products'){
                const cat = catalogData.categories?.find(c => c.id === currentCategoryId);
                const comp = cat?.companies?.find(co => co.id === currentCompanyId);
                if(!comp || comp.deletedCard === 'ok'){ navigateTo('category_detail', currentCategoryId); return; }
                
                // فلترة المنتجات المخفية
                const visibleProducts = comp.products?.filter(p => p.deletedCard !== 'ok') || [];
                
                let html = `<div class="back-btn" onclick="navigateTo('category_detail','${currentCategoryId}')"><i class="fas fa-arrow-right"></i> العودة للشركات</div>
                            <div class="title"><i class="fas fa-palette"></i> منتجات ${escapeHtml(comp.name)}</div>
                            <div class="grid">`;
                
                visibleProducts.forEach(prod => {
                    const isFav = isProductInFavorites(prod);
                    const escapedName = escapeHtml(prod.name).replace(/'/g, "\\'");
                    const escapedCode = escapeHtml(prod.code || 'بدون كود').replace(/'/g, "\\'");
                    const escapedColor = escapeHtml(prod.color || 'غير محدد').replace(/'/g, "\\'");
                    const imageUrl = getImageUrl(prod.img, 'https://iili.io/CKP5shF.jpg');
                    const isAvailable = prod.available !== false;
                    
                    html += `<div class="service-card ${!isAvailable ? 'service-unavailable' : ''}" style="position:relative;">
                                ${!isAvailable ? `<div class="unavailable-bar"><i class="fas fa-ban"></i> هذا المنتج غير متوفر حالياً</div>` : ''}
                                <div class="service-image-wrapper">
                                    <img class="service-img" src="${imageUrl}" onclick="event.stopPropagation(); previewImage('${prod.img ? prod.img : ''}')" loading="lazy">
                                    ${!isAvailable ? `
                                    <div class="unavailable-overlay">
                                        <i class="fas fa-ban"></i>
                                        <span>غير متوفر حالياً</span>
                                    </div>` : ''}
                                </div>
                                <button class="favorite-btn ${isFav ? 'active' : ''}" onclick="event.stopPropagation(); addToFavorites({name:'${escapedName}', code:'${escapedCode}', color:'${escapedColor}', img:'${imageUrl}'})">
                                    <i class="fas fa-heart"></i>
                                </button>
                                <div class="service-info">
                                    <div class="service-name">${escapeHtml(prod.name)}</div>
                                    <div class="service-category">${escapeHtml(prod.color || 'غير محدد')}</div>
                                    <div class="service-notes" style="font-size:0.6rem;"><i class="fas fa-barcode"></i> كود: ${escapeHtml(prod.code || 'بدون كود')}</div>
                                    ${isAvailable ? 
                                        `<button class="add-btn" onclick="event.stopPropagation(); addToCart({name:'${escapedName}', code:'${escapedCode}', color:'${escapedColor}', img:'${imageUrl}', available: true});"><i class="fas fa-cart-plus"></i> أضف للسلة</button>` : 
                                        `<button class="add-btn" style="background:#ff444420; border-color:var(--rd); color:var(--rd);" disabled><i class="fas fa-ban"></i> غير متوفر</button>`
                                    }
                                </div>
                            </div>`;
                });
                html += `</div>`;
                content.innerHTML = html;
            }
        }

        function navigateTo(level, catId=null, compId=null){ 
            currentLevel=level; 
            currentCategoryId=catId; 
            currentCompanyId=compId; 
            render(); 
            const searchResults = document.getElementById('searchResults');
            const searchInput = document.getElementById('searchInput');
            if(searchResults) searchResults.style.display = 'none'; 
            if(searchInput) searchInput.value=''; 
        }

        function switchPage(page) {
            currentPage = page;
            document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
            const activeItem = document.querySelector(`.nav-item[data-page="${page}"]`);
            if (activeItem) activeItem.classList.add('active');
            const profilePage = document.getElementById('profilePage');
            const contentArea = document.getElementById('contentArea');
            const searchBar = document.querySelector('.search-bar');
            const welcomeContainer = document.getElementById('welcomeCardContainer');
            const topServices = document.getElementById('topServicesContainer');
            if (profilePage) profilePage.style.display = page === 'profile' ? 'block' : 'none';
            if (contentArea) contentArea.style.display = page === 'home' ? 'block' : 'none';
            if (searchBar) searchBar.style.display = page === 'home' ? 'flex' : 'none';
            if (welcomeContainer) welcomeContainer.style.display = page === 'home' ? 'block' : 'none';
            if (topServices) topServices.style.display = page === 'home' ? 'block' : 'none';
            if (page === 'home') render();
        }

        function searchItems() {
            const query = document.getElementById('searchInput')?.value.trim().toLowerCase();
            const resultsDiv = document.getElementById('searchResults');
            if(!resultsDiv) return;
            if(!query){ resultsDiv.style.display = 'none'; return; }
            let results = [];
            catalogData.categories?.forEach(cat => {
                if (cat.deletedCard === 'ok') return;
                
                if(cat.name.toLowerCase().includes(query)) results.push({ type: 'فئة', name: cat.name, action: () => navigateTo('category_detail', cat.id) });
                
                cat.services?.forEach(service => { 
                    if(service.deletedCard === 'ok') return;
                    if(service.name.toLowerCase().includes(query) && service.available !== false) {
                        const imgUrl = getImageUrl(service.img, 'https://iili.io/CKP5shF.jpg');
                        results.push({ type: 'خدمة', name: service.name, color: service.color, img: imgUrl, notes: service.notes, category: cat.name, action: () => addToCart({ name: service.name, code: '', color: service.color || 'خدمة', img: imgUrl, notes: service.notes, isService: true, category: cat.name, available: true }) });
                    }
                });
                cat.companies?.forEach(comp => {
                    if (comp.deletedCard === 'ok') return;
                    
                    if(comp.name.toLowerCase().includes(query)) results.push({ type: 'شركة', name: comp.name, action: () => navigateTo('products', cat.id, comp.id) });
                    
                    comp.products?.forEach(prod => { 
                        if(prod.deletedCard === 'ok') return;
                        if(prod.name.toLowerCase().includes(query) || (prod.code && prod.code.toLowerCase().includes(query))) {
                            const imgUrl = getImageUrl(prod.img, 'https://iili.io/CKP5shF.jpg');
                            results.push({ type: 'منتج', name: prod.name, code: prod.code, color: prod.color, img: imgUrl, action: () => addToCart({ name: prod.name, code: prod.code, color: prod.color, img: imgUrl, available: true }) });
                        }
                    });
                });
            });
            if(results.length===0){ resultsDiv.innerHTML = '<div class="no-results" style="padding:10px;text-align:center;">لا توجد نتائج</div>'; resultsDiv.style.display = 'block'; return; }
            resultsDiv.innerHTML = results.map(r => `<div class="search-result-item" onclick="(${r.action.toString()})()" style="padding:8px;border-bottom:1px solid var(--cardBd);cursor:pointer;"><div style="font-weight:700; font-size:0.8rem;">${escapeHtml(r.name)}</div><div style="font-size:0.55rem;color:var(--ac);">${r.type}</div></div>`).join('');
            resultsDiv.style.display = 'block';
        }

        // ================================================================
        // ========== دوال لوحة التحكم ==========
        // ================================================================

        function openAdminPanel() { 
            if(currentUser?.isAdmin && !isGuestMode){ 
                updateAdminLists();
                renderStatusServices();
                loadUsersList(); 
                refreshStats(); 
                loadMostRequestedItems();
                updateAdminSelects();
                renderProductsTable();
                
                if (document.getElementById('hideMostRequested')) {
                    document.getElementById('hideMostRequested').checked = appSettings.hideMostRequested === true;
                }
                
                const panel = document.getElementById('adminPanel'); 
                if(panel) panel.classList.add('open'); 
            } else if(isGuestMode) {
                showToast('⚠️ يجب تسجيل الدخول كمدير للوصول إلى لوحة التحكم');
            }
        }

        function closeAdminPanel() { 
            const panel = document.getElementById('adminPanel'); 
            if(panel) panel.classList.remove('open'); 
        }

        function updateAdminSelects() {
            const companySelect = document.getElementById('companyCatSelect');
            const productCatSelect = document.getElementById('productCatSelect');
            const serviceCatSelect = document.getElementById('serviceCatSelect');
            const optionsHtml = '<option value="">اختر الفئة</option>' + (catalogData.categories?.filter(cat => cat.deletedCard !== 'ok').map(cat => `<option value="${cat.id}">${escapeHtml(cat.name)}</option>`).join('') || '');
            if (companySelect) companySelect.innerHTML = optionsHtml;
            if (productCatSelect) {
                productCatSelect.innerHTML = optionsHtml;
                productCatSelect.onchange = () => {
                    const catId = productCatSelect.value;
                    const category = catalogData.categories?.find(c => c.id === catId);
                    const compSelect = document.getElementById('productCompSelect');
                    if (compSelect) {
                        compSelect.innerHTML = '<option value="">اختر الشركة</option>';
                        if (category?.companies) category.companies.filter(c => c.deletedCard !== 'ok').forEach(comp => compSelect.innerHTML += `<option value="${comp.id}">${escapeHtml(comp.name)}</option>`);
                    }
                };
            }
            if (serviceCatSelect) serviceCatSelect.innerHTML = optionsHtml;
        }

        // ================================================================
        // ========== دوال إدارة الفئات ==========
        // ================================================================

        function addCategory() {
            const name = document.getElementById('newCatName')?.value.trim();
            if (!name) { showToast('أدخل اسم الفئة'); return; }
            catalogData.categories.push({ 
                id: 'cat_'+Date.now(), 
                name: name, 
                image: tempCatImage || null, 
                companies: [], 
                services: [],
                deletedCard: 'no'
            });
            saveCatalog(); render(); updateAdminLists(); updateAdminSelects(); refreshStats();
            document.getElementById('newCatName').value = '';
            tempCatImage = null;
            document.getElementById('catImagePreview').innerHTML = '';
            showToast('✅ تم إضافة الفئة');
        }

        function addCompany() {
            const catId = document.getElementById('companyCatSelect')?.value;
            const name = document.getElementById('newCompanyName')?.value.trim();
            if (!catId || !name) { showToast('اختر الفئة وأدخل اسم الشركة'); return; }
            const cat = catalogData.categories.find(c => c.id === catId);
            if(cat){ 
                cat.companies = cat.companies || []; 
                cat.companies.push({ 
                    id: 'comp_'+Date.now(), 
                    name: name, 
                    logo: tempCompanyLogo || null, 
                    products: [],
                    deletedCard: 'no'
                }); 
                saveCatalog(); render(); updateAdminLists(); updateAdminSelects(); refreshStats();
                document.getElementById('newCompanyName').value = '';
                tempCompanyLogo = null;
                document.getElementById('companyLogoPreview').innerHTML = '';
                showToast('✅ تم إضافة الشركة'); 
            }
        }

        function addProduct() {
            const catId = document.getElementById('productCatSelect')?.value;
            const compId = document.getElementById('productCompSelect')?.value;
            const name = document.getElementById('newProductName')?.value.trim();
            
            if(!catId || !compId){ showToast('اختر الفئة والشركة'); return; }
            if(!name){ showToast('الاسم مطلوب'); return; }
            
            const cat = catalogData.categories.find(c => c.id === catId);
            const comp = cat?.companies?.find(co => co.id === compId);
            if(comp){ 
                const code = document.getElementById('newProductCode')?.value.trim() || '';
                const color = document.getElementById('newProductColor')?.value.trim() || 'غير محدد';
                comp.products = comp.products || []; 
                comp.products.push({ 
                    id: 'prod_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                    name: name, 
                    code: code, 
                    color: color, 
                    img: tempProductImage || null,
                    available: true,
                    deletedCard: 'no',
                    image_url: null
                }); 
                saveCatalog(); updateAdminLists(); refreshStats();
                if(currentCategoryId===catId && currentCompanyId===compId) render(); 
                document.getElementById('newProductName').value = '';
                document.getElementById('newProductCode').value = '';
                document.getElementById('newProductColor').value = '';
                tempProductImage = null;
                document.getElementById('productImagePreview').innerHTML = '';
                showToast('✅ تم إضافة المنتج'); 
            }
        }

        function addService() {
            const catId = document.getElementById('serviceCatSelect')?.value;
            const name = document.getElementById('newServiceName')?.value.trim();
            
            if(!catId){ showToast('اختر الفئة'); return; }
            if(!name){ showToast('اسم الخدمة مطلوب'); return; }
            
            const cat = catalogData.categories.find(c => c.id === catId);
            if(cat){ 
                const color = document.getElementById('newServiceColor')?.value.trim() || 'خدمة مميزة';
                const notes = document.getElementById('newServiceNotes')?.value.trim() || '';
                cat.services = cat.services || []; 
                cat.services.push({ 
                    id: 'service_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                    name: name, 
                    color: color, 
                    img: tempServiceImage || null, 
                    notes: notes,
                    available: true,
                    deletedCard: 'no'
                }); 
                saveCatalog(); updateAdminLists(); render(); refreshStats();
                renderStatusServices(statusSearchQuery);
                document.getElementById('newServiceName').value = '';
                document.getElementById('newServiceColor').value = '';
                document.getElementById('newServiceNotes').value = '';
                tempServiceImage = null;
                document.getElementById('serviceImagePreview').innerHTML = '';
                showToast(notes ? `✅ تم إضافة الخدمة "${name}" مع وصف` : `✅ تم إضافة الخدمة "${name}"`); 
            }
        }

        async function saveCatalog() { 
            await apiCall('save_catalog', 'POST', { catalog: catalogData }); 
        }

        // ================================================================
        // ========== دوال البطاقة الترحيبية والإعدادات ==========
        // ================================================================

        function loadWelcomeCardSettings(settings) {
            if (settings && settings.welcomeCard) welcomeCardSettings = settings.welcomeCard;
            if (settings && settings.officialWebsite) officialWebsite = settings.officialWebsite;
            renderWelcomeCard();
        }

        function renderWelcomeCard() {
            const container = document.getElementById('welcomeCardContainer');
            if (!container) return;
            if (!welcomeCardSettings.enabled) { container.style.display = 'none'; return; }
            container.style.display = 'block';
            container.innerHTML = `
                <div class="welcome-card">
                    <div class="welcome-content">
                        <div class="welcome-title"><span style="display:inline-block; animation:pulse 1s ease infinite;">✨</span> ${escapeHtml(welcomeCardSettings.title)} <span style="display:inline-block; animation:pulse 1s ease infinite;">✨</span></div>
                        <div class="welcome-message">${escapeHtml(welcomeCardSettings.message)}</div>
                        <div class="welcome-btn" onclick="event.stopPropagation(); window.open('${welcomeCardSettings.buttonLink}', '_blank')">
                            <i class="fas fa-rocket"></i> ${escapeHtml(welcomeCardSettings.buttonText)}
                        </div>
                        ${officialWebsite ? `<a href="${officialWebsite}" target="_blank" class="official-link" onclick="event.stopPropagation();"><i class="fas fa-globe"></i> ${officialWebsite.replace('https://', '').replace('http://', '')}</a>` : ''}
                    </div>
                </div>
            `;
        }

        async function saveWelcomeCardSettings() {
            welcomeCardSettings = {
                enabled: document.getElementById('welcomeEnabled').checked,
                title: document.getElementById('welcomeTitle').value.trim() || 'مرحباً',
                message: document.getElementById('welcomeMessage').value.trim() || 'أهلاً بك',
                buttonText: document.getElementById('welcomeBtnText').value.trim() || 'تصفح',
                buttonLink: document.getElementById('welcomeBtnLink').value.trim() || '#',
                animationSpeed: 30
            };
            officialWebsite = document.getElementById('officialWebsite').value.trim();
            await apiCall('save_settings', 'POST', { welcomeCard: welcomeCardSettings, officialWebsite: officialWebsite });
            renderWelcomeCard();
            showToast('✅ تم حفظ إعدادات البطاقة الترحيبية');
        }

        function updateAppUI() {
            const loginAppName = document.getElementById('loginAppName');
            if (loginAppName) loginAppName.innerHTML = appSettings.appName;
            const loginLogo = document.getElementById('loginAppLogo');
            if (loginLogo && appSettings.appLogo) { loginLogo.src = appSettings.appLogo; loginLogo.classList.add('show'); if(loginAppName) loginAppName.style.display = 'none'; }
            updateProfileDisplay();
        }

        async function saveAppSettings() {
            const newName = document.getElementById('appNameInput').value.trim();
            const newWhatsapp = document.getElementById('whatsappNumberInput').value.trim();
            if (newName) appSettings.appName = newName;
            if (newWhatsapp) appSettings.whatsappNumber = newWhatsapp;
            await apiCall('save_settings', 'POST', appSettings);
            updateAppUI();
            showToast('✅ تم حفظ الإعدادات');
        }

        // ================================================================
        // ========== دوال معاينة الصور ==========
        // ================================================================

        function previewImage(imageSrc) {
            const modal = document.getElementById('imagePreviewModal');
            const modalImg = document.getElementById('previewImage');
            if (modal && modalImg) {
                modalImg.src = getImageUrl(imageSrc);
                modal.classList.add('show');
            }
        }

        function closeImagePreview() {
            document.getElementById('imagePreviewModal').classList.remove('show');
        }

        function previewCategoryImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    tempCatImage = e.target.result;
                    const preview = document.getElementById('catImagePreview');
                    if (preview) preview.innerHTML = `<img src="${tempCatImage}" class="image-preview">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function previewCompanyLogo(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    tempCompanyLogo = e.target.result;
                    const preview = document.getElementById('companyLogoPreview');
                    if (preview) preview.innerHTML = `<img src="${tempCompanyLogo}" class="image-preview">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function previewProductImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    tempProductImage = e.target.result;
                    const preview = document.getElementById('productImagePreview');
                    if (preview) preview.innerHTML = `<img src="${tempProductImage}" class="image-preview">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function previewServiceImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    tempServiceImage = e.target.result;
                    const preview = document.getElementById('serviceImagePreview');
                    if (preview) preview.innerHTML = `<img src="${tempServiceImage}" class="image-preview">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // ================================================================
        // ========== تحميل البيانات ==========
        // ================================================================

        async function trackVisit() {
            await apiCall('track_visit', 'POST');
        }

        async function loadData() {
            const settings = await apiCall('load_settings', 'GET');
            if (settings?.success) { 
                appSettings = settings.settings; 
                updateAppUI(); 
                loadWelcomeCardSettings(settings.settings);
                
                if (settings.settings && settings.settings.hideMostRequested !== undefined) {
                    appSettings.hideMostRequested = settings.settings.hideMostRequested;
                }
                
                if (document.getElementById('hideMostRequested')) {
                    document.getElementById('hideMostRequested').checked = appSettings.hideMostRequested === true;
                }
            }
            
            const catalog = await apiCall('load_catalog', 'GET');
            if (catalog?.success) { 
                catalogData = catalog.catalog; 
                if (!catalogData.categories) catalogData.categories = [];
                
                // التأكد من وجود deletedCard لكل العناصر
                catalogData.categories.forEach(cat => {
                    if (!cat.deletedCard) cat.deletedCard = 'no';
                    if (cat.companies) {
                        cat.companies.forEach(comp => {
                            if (!comp.deletedCard) comp.deletedCard = 'no';
                            if (comp.products) {
                                comp.products.forEach(prod => {
                                    if (!prod.deletedCard) prod.deletedCard = 'no';
                                    if (!prod.id) prod.id = 'prod_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                                    if (!prod.image_url) prod.image_url = null;
                                });
                            }
                        });
                    }
                    if (cat.services) {
                        cat.services.forEach(service => {
                            if (!service.deletedCard) service.deletedCard = 'no';
                            if (!service.id) service.id = 'service_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                        });
                    }
                });
                
                updateAdminSelects(); 
                render(); 
                setTimeout(preloadImages, 500);
            }
            
            const whatsapp = await apiCall('get_whatsapp', 'GET');
            if (whatsapp?.success) { appSettings.whatsappNumber = whatsapp.whatsappNumber; if(document.getElementById('whatsappNumberInput')) document.getElementById('whatsappNumberInput').value = appSettings.whatsappNumber; }
            
            loadCart();
            loadFavorites();
            loadMostRequestedItems();
            if (currentUser?.isAdmin && !isGuestMode) { loadUsersList(); refreshStats(); }
            trackVisit();
            
            if (document.getElementById('welcomeEnabled')) {
                document.getElementById('welcomeEnabled').checked = welcomeCardSettings.enabled;
                document.getElementById('welcomeTitle').value = welcomeCardSettings.title;
                document.getElementById('welcomeMessage').value = welcomeCardSettings.message;
                document.getElementById('welcomeBtnText').value = welcomeCardSettings.buttonText;
                document.getElementById('welcomeBtnLink').value = welcomeCardSettings.buttonLink;
                document.getElementById('officialWebsite').value = officialWebsite;
            }
            
            if (document.getElementById('appNameInput')) document.getElementById('appNameInput').value = appSettings.appName;
            if (document.getElementById('whatsappNumberInput')) document.getElementById('whatsappNumberInput').value = appSettings.whatsappNumber;
        }

        // ================================================================
        // ========== التشغيل الأولي ==========
        // ================================================================

        const savedUser = localStorage.getItem('user');
        const savedGuest = localStorage.getItem('isGuest');
        
        if((savedUser || savedGuest === 'true') && window.location.search.includes('page=app')){
            if(savedUser) { currentUser = JSON.parse(savedUser); isGuestMode = false; }
            else if(savedGuest === 'true') { isGuestMode = true; currentUser = { id: 0, fullname: 'زائر', email: 'guest@temp.com', isAdmin: false }; }
            const loginScreen = document.getElementById('loginScreen');
            const appScreen = document.getElementById('appScreen');
            if(loginScreen) loginScreen.classList.add('hide');
            if(appScreen) appScreen.style.display = 'block';
            loadData();
        } else if(window.location.search.includes('page=app') && !savedUser && savedGuest !== 'true'){
            window.location.href = window.location.pathname;
        }

        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') document.body.setAttribute('data-t', 'dark');
        
        if(document.getElementById('registerTab').classList.contains('active')) {
            generateCaptcha();
        }
        
        let deferredPrompt = null;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
        });
