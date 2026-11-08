<?php
/**
 * المساعد الإداري الذكي: صفحة حوار بين الأدمن والمساعد (بصلاحيات تنفيذ كاملة عبر lib/ai_agent.php)،
 * سجل الإجراءات المنفَّذة، وتوكن ثابت لربط فحص دوري حقيقي من cron الاستضافة بلا جلسة أدمن.
 */

route('/api/admin/ai-agent/chat', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 403);
    @set_time_limit(420);   // حتى 6 نداءات متتالية للذكاء الاصطناعي (65s للواحدة كحد أقصى) داخل جولة واحدة
    $data = req_data();
    $msg = py_strip(py_or(dget($data, 'message'), ''));
    if (!py_truthy($msg)) return jsonify(['ok' => false, 'msg' => 'الرسالة فارغة']);
    $r = ai_agent_run($msg, 'chat');
    return jsonify(['ok' => true, 'reply' => $r['reply'], 'actions' => $r['actions'], 'conversation_id' => $r['conversation_id']]);
}, ['POST']);

route('/api/admin/ai-agent/history', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $conv = $conn->execute('SELECT id FROM ai_agent_conversations ORDER BY id LIMIT 1')->fetchone();
    if (!$conv) return jsonify(['ok' => true, 'messages' => []]);
    $rows = $conn->execute('SELECT role, content, created_at FROM ai_agent_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 60', [$conv['id']])->fetchall();
    $out = [];
    foreach (array_reverse($rows) as $m) {
        // رسائل "نتيجة تنفيذ الأداة" الداخلية لا تُعرض كفقاعة محادثة عادية — فقط رسائل الأدمن والردود النصية الفعلية
        if ($m['role'] === 'user' && str_starts_with((string)$m['content'], '[نتيجة تنفيذ الأداة')) continue;
        $out[] = ['role' => $m['role'], 'content' => $m['content'], 'time' => fmt_time('%d/%m %H:%M', $m['created_at'])];
    }
    return jsonify(['ok' => true, 'messages' => $out]);
});

route('/api/admin/ai-agent/log', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM ai_agent_log ORDER BY id DESC LIMIT 100')->fetchall();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'time' => fmt_time('%d/%m %H:%M', $r['created_at']), 'source' => $r['source'],
                  'tool' => $r['tool'], 'success' => py_truthy($r['success']), 'detail' => $r['detail']];
    }
    return jsonify(['ok' => true, 'log' => $out]);
});

function ai_agent_cron_token(): string {
    $t = get_setting('ai_agent_cron_token', '');
    if (!py_truthy($t)) {
        $t = bin2hex(random_bytes(20));
        set_setting('ai_agent_cron_token', $t);
    }
    return $t;
}

route('/api/admin/ai-agent/cron-info', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $token = ai_agent_cron_token();
    $url = (defined('DOMAIN') && DOMAIN ? ('https://' . DOMAIN) : '') . '/api/admin/ai-agent/cron-run?token=' . $token;
    return jsonify(['ok' => true, 'token' => $token, 'url' => $url]);
});

route('/api/admin/ai-agent/cron-info/regenerate', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $t = bin2hex(random_bytes(20));
    set_setting('ai_agent_cron_token', $t);
    $url = (defined('DOMAIN') && DOMAIN ? ('https://' . DOMAIN) : '') . '/api/admin/ai-agent/cron-run?token=' . $t;
    return jsonify(['ok' => true, 'token' => $t, 'url' => $url]);
}, ['POST']);

/* يستدعيها cron حقيقي من الاستضافة (لا جلسة أدمن هنا — التوكن وحده هو التفويض) */
route('/api/admin/ai-agent/cron-run', function () {
    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $real = get_setting('ai_agent_cron_token', '');
    if (!py_truthy($real) || !py_truthy($token) || !hash_equals($real, $token)) {
        return jsonify(['ok' => false, 'msg' => 'توكن غير صحيح'], 403);
    }
    @set_time_limit(420);
    $instruction = 'هذا فحص دوري تلقائي (لا أدمن حاضر الآن) — افحص النظام بالكامل بنفسك ونفّذ ما تراه مناسباً فوراً دون انتظار أي رد: '
        . 'تحقق من صحة النظام والاتصال بالمزوّد، أجب على أي تذاكر دعم مفتوحة تنتظر رداً بما يحل مشكلة صاحبها، راجع الطلبات العالقة منذ أكثر من 24 ساعة، '
        . 'وإن وجدت خدمات بأسماء فيها شوائب (إيموجي/رموز/أرقام زائدة بلا معنى) أو بلا وصف، نظّفها واكتب لها وصفاً مناسباً. لخّص في النهاية بإيجاز ما فعلته وما وجدته.';
    $r = ai_agent_run($instruction, 'cron');
    return jsonify(['ok' => true, 'reply' => $r['reply'], 'actions_count' => count($r['actions'])]);
}, ['GET', 'POST']);
