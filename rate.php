<?php
/* ======================================================================
   صفحة عامة لتقييم الفرع وتقديم شكوى — لا تتطلب تسجيل دخول.
   يُشارك مسؤول الفرع رابطها (rate.php?b=<رقم الفرع>) مع المسافرين.
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Baghdad');

if (!file_exists(__DIR__ . '/config.php')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'النظام غير مثبت بعد.';
    exit;
}
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax'];
    try {
        $pdo = db();
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => 'تعذر الاتصال بقاعدة البيانات']);
        exit;
    }

    $branchId = (int) ($_POST['branchId'] ?? 0);
    $branchCheck = $pdo->prepare("SELECT id FROM branches WHERE id=? AND status='active'");
    $branchCheck->execute([$branchId]);
    if (!$branchCheck->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'الفرع غير موجود']);
        exit;
    }

    if ($action === 'submit_rating') {
        $rating = (int) ($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            echo json_encode(['ok' => false, 'error' => 'الرجاء اختيار تقييم صحيح']);
            exit;
        }
        $comment = trim(mb_substr($_POST['comment'] ?? '', 0, 500));
        $pdo->prepare("INSERT INTO branch_ratings (branch_id, rating, comment) VALUES (?, ?, ?)")
            ->execute([$branchId, $rating, $comment ?: null]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'submit_complaint') {
        $title = trim(mb_substr($_POST['title'] ?? '', 0, 150));
        $details = trim(mb_substr($_POST['details'] ?? '', 0, 1000));
        if ($title === '' || $details === '') {
            echo json_encode(['ok' => false, 'error' => 'الرجاء تعبئة عنوان الشكوى وتفاصيلها']);
            exit;
        }
        $pdo->prepare("INSERT INTO traveler_complaints (branch_id, title, details) VALUES (?, ?, ?)")
            ->execute([$branchId, $title, $details]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
}

$pdo = null;
$branch = null;
$company = ['name' => 'شركة الصوى للصرافة', 'logo' => null];
try {
    $pdo = db();
    $settingsRow = $pdo->query("SELECT company_name, company_logo FROM settings ORDER BY id DESC LIMIT 1")->fetch();
    if ($settingsRow) {
        $company['name'] = $settingsRow['company_name'] ?: $company['name'];
        $company['logo'] = $settingsRow['company_logo'] ?: null;
    }
    $branchId = (int) ($_GET['b'] ?? 0);
    if ($branchId > 0) {
        $branchStmt = $pdo->prepare("SELECT id, name FROM branches WHERE id=? AND status='active'");
        $branchStmt->execute([$branchId]);
        $branch = $branchStmt->fetch();
    }
} catch (Throwable $ex) {
    $branch = null;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تقييم <?= htmlspecialchars($company['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
        background: linear-gradient(135deg, #f0f4f8 0%, #d9e2eb 100%);
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }

    .rating-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 28px 30px;
        max-width: 480px;
        width: 100%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08), 0 8px 24px rgba(0, 0, 0, 0.04);
        position: relative;
        overflow: hidden;
        direction: rtl;
    }

    .rating-card::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        left: 0;
        height: 4px;
        background: linear-gradient(90deg, #006b73, #0A8A94, #c99a3d, #0A8A94, #006b73);
        background-size: 200% 100%;
        animation: gradientMove 4s linear infinite;
    }

    @keyframes gradientMove {
        0% { background-position: 0% 0%; }
        100% { background-position: 200% 0%; }
    }

    .rating-card .header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 4px;
    }

    .rating-card .header .icon {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, #006b73, #0A8A94);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 18px;
        flex-shrink: 0;
        overflow: hidden;
    }

    .rating-card .header .icon img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .rating-card .header .title {
        font-size: 18px;
        font-weight: 800;
        color: #1A2E35;
    }

    .rating-card .header .title span {
        background: linear-gradient(135deg, #006b73, #0A8A94);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .rating-card .subtitle {
        font-size: 13px;
        color: #4A6A78;
        margin-bottom: 16px;
        padding-right: 56px;
    }

    .rating-card .branch-name {
        background: rgba(0, 107, 115, 0.04);
        border-radius: 10px;
        padding: 10px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
        border: 1px solid rgba(0, 107, 115, 0.06);
    }

    .rating-card .branch-name i {
        color: #006b73;
        font-size: 16px;
    }

    .rating-card .branch-name .name {
        font-size: 15px;
        font-weight: 700;
        color: #1A2E35;
    }

    .rating-card .rating-buttons-section {
        text-align: center;
        padding: 6px 0 12px;
    }

    .rating-card .rating-buttons-section .rating-label {
        font-size: 13px;
        color: #4A6A78;
        font-weight: 500;
        display: block;
        margin-bottom: 12px;
    }

    .rating-card .rating-buttons {
        display: flex;
        gap: 10px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .rating-card .rating-buttons .rating-btn {
        padding: 10px 18px;
        border: 2px solid rgba(0, 107, 115, 0.08);
        border-radius: 12px;
        background: #F8FAFC;
        color: #4A6A78;
        font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        min-width: 70px;
        position: relative;
    }

    .rating-card .rating-buttons .rating-btn:hover {
        border-color: #c99a3d;
        background: rgba(201, 154, 61, 0.04);
        transform: translateY(-2px);
    }

    .rating-card .rating-buttons .rating-btn.active {
        border-color: #c99a3d;
        background: rgba(201, 154, 61, 0.08);
        color: #c99a3d;
        box-shadow: 0 4px 16px rgba(201, 154, 61, 0.15);
    }

    .rating-card .rating-buttons .rating-btn .emoji {
        display: block;
        font-size: 24px;
        margin-bottom: 2px;
    }

    .rating-card .rating-buttons .rating-btn .label-text {
        font-size: 12px;
    }

    .rating-card .rating-buttons .rating-btn[data-value="1"].active {
        border-color: #EF4444;
        background: rgba(239, 68, 68, 0.08);
        color: #EF4444;
    }

    .rating-card .rating-buttons .rating-btn[data-value="2"].active {
        border-color: #F59E0B;
        background: rgba(245, 158, 11, 0.08);
        color: #F59E0B;
    }

    .rating-card .rating-buttons .rating-btn[data-value="3"].active {
        border-color: #8B9DC3;
        background: rgba(139, 157, 195, 0.08);
        color: #4A6A78;
    }

    .rating-card .rating-buttons .rating-btn[data-value="4"].active {
        border-color: #0A8A94;
        background: rgba(10, 138, 148, 0.08);
        color: #0A8A94;
    }

    .rating-card .rating-buttons .rating-btn[data-value="5"].active {
        border-color: #10B981;
        background: rgba(16, 185, 129, 0.08);
        color: #10B981;
    }

    .rating-card .selected-rating-text {
        font-size: 13px;
        color: #8AA0B0;
        margin-top: 10px;
    }

    .rating-card .selected-rating-text strong {
        color: #1A2E35;
        font-weight: 700;
    }

    .rating-card .comment-section {
        margin: 12px 0 16px;
    }

    .rating-card .comment-section textarea {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid rgba(0, 107, 115, 0.06);
        border-radius: 10px;
        font-size: 13px;
        font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
        background: #F8FAFC;
        color: #1A2E35;
        outline: none;
        resize: vertical;
        min-height: 70px;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .rating-card .comment-section textarea:focus {
        border-color: #006b73;
        box-shadow: 0 0 0 4px rgba(0, 107, 115, 0.06);
    }

    .rating-card .comment-section textarea::placeholder {
        color: #8AA0B0;
    }

    .rating-card .actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .rating-card .actions .btn {
        flex: 1;
        min-width: 100px;
        padding: 12px 20px;
        border: none;
        border-radius: 10px;
        font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .rating-card .actions .btn-submit {
        background: linear-gradient(135deg, #006b73, #0A8A94);
        color: #fff;
        flex: 2;
    }

    .rating-card .actions .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 24px rgba(0, 107, 115, 0.30);
    }

    .rating-card .actions .btn-submit:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .rating-card .actions .btn-complaint {
        background: rgba(239, 68, 68, 0.06);
        color: #DC2626;
        border: 1.5px solid rgba(239, 68, 68, 0.12);
        flex: 1;
    }

    .rating-card .actions .btn-complaint:hover {
        background: rgba(239, 68, 68, 0.10);
        transform: translateY(-2px);
        box-shadow: 0 6px 24px rgba(239, 68, 68, 0.15);
    }

    .complaint-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.50);
        backdrop-filter: blur(6px);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .complaint-modal.show { display: flex; }

    .complaint-modal .modal-content {
        background: #ffffff;
        border-radius: 20px;
        padding: 28px 30px;
        max-width: 460px;
        width: 100%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.20);
        direction: rtl;
        animation: modalSlideUp 0.4s ease;
    }

    @keyframes modalSlideUp {
        from { opacity: 0; transform: translateY(30px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .complaint-modal .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid rgba(0, 107, 115, 0.06);
    }

    .complaint-modal .modal-header h3 {
        font-size: 18px;
        font-weight: 800;
        color: #1A2E35;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .complaint-modal .modal-header h3 i { color: #DC2626; }

    .complaint-modal .modal-header .close-btn {
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.04);
        color: #8AA0B0;
        font-size: 18px;
        cursor: pointer;
    }

    .complaint-modal .modal-body .form-group { margin-bottom: 14px; }

    .complaint-modal .modal-body .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: #4A6A78;
        margin-bottom: 4px;
    }

    .complaint-modal .modal-body .form-group input,
    .complaint-modal .modal-body .form-group textarea {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid rgba(0, 107, 115, 0.06);
        border-radius: 10px;
        font-size: 13px;
        font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
        background: #F8FAFC;
        color: #1A2E35;
        outline: none;
    }

    .complaint-modal .modal-body .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .complaint-modal .modal-footer { display: flex; gap: 10px; margin-top: 16px; }

    .complaint-modal .modal-footer .btn {
        flex: 1;
        padding: 12px 20px;
        border: none;
        border-radius: 10px;
        font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .complaint-modal .modal-footer .btn-send { background: #DC2626; color: #fff; }
    .complaint-modal .modal-footer .btn-cancel { background: rgba(0, 0, 0, 0.04); color: #4A6A78; }

    .toast-container {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 999;
        display: flex;
        flex-direction: column;
        gap: 10px;
        align-items: center;
        pointer-events: none;
        width: 100%;
        max-width: 420px;
        padding: 0 16px;
    }

    .toast {
        background: #1A2E35;
        border-radius: 14px;
        padding: 14px 20px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        pointer-events: auto;
        width: 100%;
        display: flex;
        align-items: center;
        gap: 12px;
        opacity: 0;
        transform: translateY(-80px) scale(0.9);
        transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        color: #fff;
        font-size: 13px;
        font-weight: 600;
    }

    .toast.show { opacity: 1; transform: translateY(0) scale(1); }
    .toast.success { background: #065F46; }
    .toast.error { background: #991B1B; }

    .toast .toast-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        flex-shrink: 0;
        background: rgba(255, 255, 255, 0.12);
    }

    .empty-link-card {
        background: #fff;
        border-radius: 20px;
        padding: 40px 30px;
        max-width: 420px;
        width: 100%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
    }

    .empty-link-card i { font-size: 40px; color: #8AA0B0; margin-bottom: 14px; display: block; }
    .empty-link-card p { color: #4A6A78; font-size: 14px; }

    @media (max-width: 480px) {
        .rating-card { padding: 20px 16px; }
        .rating-card .header .title { font-size: 16px; }
        .rating-card .subtitle { padding-right: 0; font-size: 12px; }
        .rating-card .rating-buttons .rating-btn { padding: 8px 12px; font-size: 12px; min-width: 55px; }
        .rating-card .rating-buttons .rating-btn .emoji { font-size: 20px; }
        .rating-card .rating-buttons .rating-btn .label-text { font-size: 10px; }
        .rating-card .actions .btn { font-size: 12px; padding: 10px 14px; min-width: 80px; }
        .complaint-modal .modal-content { padding: 20px 16px; }
        .complaint-modal .modal-header h3 { font-size: 16px; }
    }

    @media (max-width: 360px) {
        .rating-card .rating-buttons .rating-btn { padding: 6px 10px; font-size: 11px; min-width: 48px; }
        .rating-card .rating-buttons .rating-btn .emoji { font-size: 18px; }
        .rating-card .rating-buttons .rating-btn .label-text { font-size: 9px; }
        .rating-card .actions { flex-direction: column; }
        .rating-card .actions .btn { flex: 1; }
    }
</style>
</head>
<body>

<?php if (!$branch): ?>
    <div class="empty-link-card">
        <i class="fas fa-link-slash"></i>
        <p>هذا الرابط غير صالح أو أن الفرع غير موجود. الرجاء التأكد من الرابط مع مسؤول الفرع.</p>
    </div>
<?php else: ?>
    <div class="rating-card">
        <div class="header">
            <div class="icon">
                <?php if ($company['logo']): ?>
                    <img src="<?= htmlspecialchars($company['logo']) ?>" alt="logo">
                <?php else: ?>
                    <i class="fas fa-store"></i>
                <?php endif; ?>
            </div>
            <div class="title">تقييم تجربة <span><?= htmlspecialchars($company['name']) ?></span></div>
        </div>
        <div class="subtitle">شاركنا رأيك لتطوير الخدمة</div>

        <div class="branch-name">
            <i class="fas fa-location-dot"></i>
            <span class="name"><?= htmlspecialchars($branch['name']) ?></span>
        </div>

        <div class="rating-buttons-section">
            <span class="rating-label">اختر تقييمك للتجربة</span>
            <div class="rating-buttons" id="ratingButtons">
                <button class="rating-btn" data-value="1"><span class="emoji">😞</span><span class="label-text">سيء</span></button>
                <button class="rating-btn" data-value="2"><span class="emoji">😐</span><span class="label-text">مقبول</span></button>
                <button class="rating-btn" data-value="3"><span class="emoji">🙂</span><span class="label-text">جيد</span></button>
                <button class="rating-btn" data-value="4"><span class="emoji">😊</span><span class="label-text">جيد جداً</span></button>
                <button class="rating-btn" data-value="5"><span class="emoji">🌟</span><span class="label-text">ممتاز</span></button>
            </div>
            <div class="selected-rating-text" id="selectedRatingText">لم تختار تقييماً بعد</div>
        </div>

        <div class="comment-section">
            <textarea id="commentInput" placeholder="أضف تعليقاً عن تجربتك في الفرع (اختياري)..."></textarea>
        </div>

        <div class="actions">
            <button class="btn btn-submit" id="submitBtn" disabled><i class="fas fa-paper-plane"></i> إرسال التقييم</button>
            <button class="btn btn-complaint" id="complaintBtn"><i class="fas fa-flag"></i> شكوى</button>
        </div>
    </div>

    <div class="complaint-modal" id="complaintModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-flag"></i> تقديم شكوى</h3>
                <button class="close-btn" onclick="closeComplaintModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>الفرع</label>
                    <input type="text" value="<?= htmlspecialchars($branch['name']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>عنوان الشكوى <span style="color:#DC2626;">*</span></label>
                    <input type="text" id="complaintTitle" placeholder="مثال: تأخر في الخدمة">
                </div>
                <div class="form-group">
                    <label>تفاصيل الشكوى <span style="color:#DC2626;">*</span></label>
                    <textarea id="complaintDetails" placeholder="اذكر تفاصيل الشكوى بشكل واضح..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-send" onclick="submitComplaint()"><i class="fas fa-paper-plane"></i> إرسال الشكوى</button>
                <button class="btn btn-cancel" onclick="closeComplaintModal()">إلغاء</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        const BRANCH_ID = <?= (int) $branch['id'] ?>;
        let selectedRating = 0;
        const ratingButtons = document.querySelectorAll('.rating-btn');
        const ratingText = document.getElementById('selectedRatingText');
        const submitBtn = document.getElementById('submitBtn');

        const ratingNames = { 1: 'سيء', 2: 'مقبول', 3: 'جيد', 4: 'جيد جداً', 5: 'ممتاز' };
        const ratingEmojis = { 1: '😞', 2: '😐', 3: '🙂', 4: '😊', 5: '🌟' };

        ratingButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const value = parseInt(this.dataset.value);
                selectedRating = value;
                ratingButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                ratingText.innerHTML = `تقييمك: <strong>${ratingEmojis[value]} ${ratingNames[value]}</strong>`;
                submitBtn.disabled = false;
            });
        });

        submitBtn.addEventListener('click', function() {
            if (selectedRating === 0) { showToast('⚠️ تنبيه', 'الرجاء اختيار تقييم أولاً', 'error'); return; }
            const comment = document.getElementById('commentInput').value.trim();
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
            this.disabled = true;
            fetch('?ajax=submit_rating', { method: 'POST', body: new URLSearchParams({ branchId: BRANCH_ID, rating: selectedRating, comment }) })
                .then(r => r.json()).then(data => {
                    if (!data.ok) {
                        showToast('⚠️ خطأ', data.error || 'تعذر إرسال التقييم', 'error');
                        this.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال التقييم';
                        this.disabled = false;
                        return;
                    }
                    showToast('✅ تم الإرسال', `شكراً لك! تقييمك "${ratingNames[selectedRating]}" ساعدنا في تحسين الخدمة.`, 'success');
                    this.innerHTML = '<i class="fas fa-check"></i> تم الإرسال';
                    this.style.background = '#10B981';
                }).catch(() => {
                    showToast('❌ خطأ', 'تعذر الاتصال بالخادم', 'error');
                    this.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال التقييم';
                    this.disabled = false;
                });
        });

        document.getElementById('complaintBtn').addEventListener('click', function() {
            document.getElementById('complaintModal').classList.add('show');
            document.getElementById('complaintTitle').focus();
        });

        function closeComplaintModal() {
            document.getElementById('complaintModal').classList.remove('show');
            document.getElementById('complaintTitle').value = '';
            document.getElementById('complaintDetails').value = '';
        }

        function submitComplaint() {
            const title = document.getElementById('complaintTitle').value.trim();
            const details = document.getElementById('complaintDetails').value.trim();
            if (!title) { showToast('⚠️ تنبيه', 'الرجاء إدخال عنوان الشكوى', 'error'); document.getElementById('complaintTitle').focus(); return; }
            if (!details) { showToast('⚠️ تنبيه', 'الرجاء كتابة تفاصيل الشكوى', 'error'); document.getElementById('complaintDetails').focus(); return; }

            const btn = document.querySelector('.complaint-modal .btn-send');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
            btn.disabled = true;
            fetch('?ajax=submit_complaint', { method: 'POST', body: new URLSearchParams({ branchId: BRANCH_ID, title, details }) })
                .then(r => r.json()).then(data => {
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الشكوى';
                    btn.disabled = false;
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر إرسال الشكوى', 'error'); return; }
                    showToast('📩 تم إرسال الشكوى', `تم استلام شكواك: "${title}" — سيتم مراجعتها من قبل الإدارة.`, 'success');
                    closeComplaintModal();
                }).catch(() => {
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الشكوى';
                    btn.disabled = false;
                    showToast('❌ خطأ', 'تعذر الاتصال بالخادم', 'error');
                });
        }

        document.getElementById('complaintModal').addEventListener('click', function(e) { if (e.target === this) closeComplaintModal(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeComplaintModal(); });

        let toastId = 0;
        function showToast(title, message, type = 'info', duration = 4000) {
            const container = document.getElementById('toastContainer');
            const id = ++toastId;
            const icons = { success: 'fas fa-check-circle', error: 'fas fa-times-circle', info: 'fas fa-info-circle', warning: 'fas fa-exclamation-triangle' };
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.id = `toast-${id}`;
            toast.innerHTML = `<div class="toast-icon"><i class="${icons[type] || icons.info}"></i></div><div><div style="font-weight:700;font-size:14px;">${title}</div><div style="font-weight:400;font-size:12px;opacity:0.8;">${message}</div></div>`;
            container.appendChild(toast);
            requestAnimationFrame(() => { toast.classList.add('show'); });
            const timer = setTimeout(() => { closeToast(id); }, duration);
            toast._timer = timer;
            toast.addEventListener('click', () => { closeToast(id); });
            return id;
        }

        function closeToast(id) {
            const toast = document.getElementById(`toast-${id}`);
            if (!toast) return;
            if (toast._timer) clearTimeout(toast._timer);
            toast.classList.remove('show');
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-80px) scale(0.9)';
            setTimeout(() => { if (toast.parentElement) toast.remove(); }, 400);
        }
    </script>
<?php endif; ?>
</body>
</html>
