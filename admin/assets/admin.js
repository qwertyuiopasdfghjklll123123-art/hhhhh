document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.menu-toggle');
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.querySelector('.sidebar-overlay');

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
    }

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('open');
        });
    }
    if (overlay) overlay.addEventListener('click', closeSidebar);

    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('submit', function (e) {
            if (!window.confirm(el.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // شريط تقدّم حي لاستيراد نسخة احتياطية JSON: يستطلع admin/import_progress.php كل ثانية
    // تقريباً بينما يُرسَل النموذج بشكل طبيعي (بلا AJAX) - الصفحة ستُعاد تحميلها فور الانتهاء،
    // فيتوقف الاستطلاع تلقائياً؛ لا حاجة لإيقافه يدوياً في الحالة العادية
    var importForm = document.getElementById('importJsonForm');
    if (importForm) {
        importForm.addEventListener('submit', function () {
            var fileInput = importForm.querySelector('input[type="file"]');
            if (!fileInput || !fileInput.files || !fileInput.files.length) return;

            var progressUrl = importForm.getAttribute('data-progress-url');
            var box = document.getElementById('importProgressBox');
            var bar = document.getElementById('importProgressBar');
            var label = document.getElementById('importProgressLabel');
            var submitBtn = importForm.querySelector('button[type="submit"]');
            if (!progressUrl || !box || !bar || !label) return;

            box.style.display = 'block';
            if (submitBtn) submitBtn.disabled = true;
            var startedAt = Date.now();

            var poll = setInterval(function () {
                var elapsed = Math.round((Date.now() - startedAt) / 1000);
                fetch(progressUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var total = data.total_steps || 0;
                        var current = data.current_step || 0;
                        var pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;
                        bar.style.width = pct + '%';
                        var text = (data.label || 'جاري الاستيراد...') + ' (' + elapsed + ' ثانية)';
                        label.textContent = text;
                        if (data.status === 'done' || data.status === 'error') {
                            clearInterval(poll);
                        }
                    })
                    .catch(function () { /* تجاهل أخطاء الاستطلاع المؤقتة، الصفحة ستُعاد تحميلها على أي حال */ });
            }, 800);
        });
    }
});
