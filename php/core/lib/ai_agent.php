<?php
/**
 * المساعد الذكي للأدمن: يستخدم دعم DeepSeek الأصلي لاستدعاء الدوال (function calling متوافق مع OpenAI
 * عبر call_ai_api_raw/tools في routes/ai.php) لتنفيذ أدوات إدارية حقيقية (رد على تذاكر، فحص طلبات،
 * تنظيف أسماء/أوصاف خدمات، فحص صحة النظام) — بتفويض كامل بلا موافقة لكل إجراء، حسب طلب الأدمن صراحة.
 * كل إجراء يُسجَّل في ai_agent_log للمراجعة لاحقاً حتى لو نُفِّذ تلقائياً.
 *
 * ملاحظة تصميم: جُرِّب أولاً بروتوكول نصي (مطالبة النموذج بكتابة JSON داخل الرد) فتبيّن أنه غير موثوق —
 * بعض النماذج (ومنها DeepSeek أحياناً) تخرج بصيغة استدعاء خاصة بها بدل النص المطلوب حرفياً، فيظهر للأدمن
 * نص مشوّه بدل تنفيذ فعلي. الحل الصحيح: تمرير الأدوات كمخطط JSON Schema رسمي عبر حقل tools في الطلب،
 * وقراءة tool_calls من رد المزوّد مباشرة بدل تحليل النص — وهذا ما يطبَّق هنا.
 */

const AI_AGENT_MAX_STEPS = 6;

/** مخطط الأدوات بصيغة OpenAI/DeepSeek function-calling (JSON Schema) */
function ai_agent_tool_schemas(): array {
    $noArgs = ['type' => 'object', 'properties' => (object)[], 'required' => []];
    return [
        ['type' => 'function', 'function' => ['name' => 'list_open_tickets',
            'description' => 'يرجع كل تذاكر الدعم التي تنتظر رداً من الإدارة (مفتوحة/بانتظار رد) مع آخر رسائلها.',
            'parameters' => $noArgs]],
        ['type' => 'function', 'function' => ['name' => 'reply_ticket',
            'description' => 'يرسل رداً فورياً لصاحب تذكرة دعم ويعلّمها كمُجاب عليها.',
            'parameters' => ['type' => 'object', 'properties' => [
                'ticket_id' => ['type' => 'integer', 'description' => 'رقم التذكرة من list_open_tickets'],
                'message' => ['type' => 'string', 'description' => 'نص الرد بالعربية'],
            ], 'required' => ['ticket_id', 'message']]]],
        ['type' => 'function', 'function' => ['name' => 'close_ticket',
            'description' => 'يغلق تذكرة دعم نهائياً — فقط بعد حل مشكلتها فعلاً أو إن كانت قديمة بلا رد من صاحبها.',
            'parameters' => ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer']], 'required' => ['ticket_id']]]],
        ['type' => 'function', 'function' => ['name' => 'list_problem_orders',
            'description' => 'يرجع الطلبات العالقة (قيد التنفيذ منذ أكثر من 24 ساعة بلا اكتمال) لفحصها.',
            'parameters' => $noArgs]],
        ['type' => 'function', 'function' => ['name' => 'get_order',
            'description' => 'تفاصيل طلب واحد بكل حقوله.',
            'parameters' => ['type' => 'object', 'properties' => ['order_id' => ['type' => 'integer']], 'required' => ['order_id']]]],
        ['type' => 'function', 'function' => ['name' => 'list_services',
            'description' => 'يرجع حتى 40 خدمة من كتالوج الموقع الفعلي (المعرّف id/prov_id، الاسم الحالي، الاسم الأصلي من المزوّد، الوصف الحالي، المنصة، وهل تدعم تعويض/إلغاء). استخدمها دائماً أولاً قبل أي تعديل اسم أو وصف لمعرفة المعرّف الصحيح — لا تخترع معرّفات أو أسماء خدمات من عندك.',
            'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'كلمة بحث اختيارية بالاسم أو المنصة']], 'required' => []]]],
        ['type' => 'function', 'function' => ['name' => 'update_service_name',
            'description' => 'يحدّث اسم خدمة ظاهر للمستخدمين (نظّف الرموز التعبيرية/الزخرفية/الأرقام الزائدة بلا معنى فقط، وحافظ على معلومات مهمة مثل المنصة والجودة والسرعة وإمكانية Refill).',
            'parameters' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string', 'description' => 'معرّف الخدمة من list_services'],
                'prov_id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'الاسم الجديد النظيف'],
            ], 'required' => ['id', 'prov_id', 'name']]]],
        ['type' => 'function', 'function' => ['name' => 'update_service_desc',
            'description' => 'يحدّث وصف خدمة ظاهر للمستخدمين (جملتان إلى ثلاث بالعربية الفصحى تشرح ما تقدمه الخدمة فعلاً بناءً على اسمها ونوعها، دون مبالغة أو اختراع مزايا).',
            'parameters' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string', 'description' => 'معرّف الخدمة من list_services'],
                'prov_id' => ['type' => 'integer'],
                'desc' => ['type' => 'string'],
            ], 'required' => ['id', 'prov_id', 'desc']]]],
        ['type' => 'function', 'function' => ['name' => 'check_system_health',
            'description' => 'يفحص الاتصال بقاعدة البيانات، الاتصال بمزوّد الخدمات، آخر أخطاء مسجّلة بالنظام، وعدد الطلبات العالقة — استخدمها عند أي فحص دوري أو شك بوجود عطل.',
            'parameters' => $noArgs]],
        ['type' => 'function', 'function' => ['name' => 'create_gift_coupon',
            'description' => 'ينشئ كوبون هدية رصيد مجاني فعلياً بالموقع. إن لم يُطلب كود محدد، يُولَّد كود عشوائي تلقائياً.',
            'parameters' => ['type' => 'object', 'properties' => [
                'code' => ['type' => 'string', 'description' => 'اختياري — كود محدد بالأحرف اللاتينية/أرقام، وإلا يُولَّد تلقائياً'],
                'amount' => ['type' => 'number', 'description' => 'قيمة الرصيد بالدولار لكل استخدام للكوبون'],
                'max_uses' => ['type' => 'integer', 'description' => 'الحد الأقصى لعدد مرات الاستخدام (افتراضي 1)'],
                'notify_all' => ['type' => 'boolean', 'description' => 'إرسال إشعار بالكوبون لكل المستخدمين فوراً (افتراضي true)'],
            ], 'required' => ['amount']]]],
        // بتفويض صريح من مالك الموقع: وصول SQL حر وكامل بلا أي قيد إضافي على قاعدة البيانات — يشمل
        // DELETE/UPDATE/DROP وأي استعلام آخر. لا تراجع عن أي حذف يُنفَّذ بهذه الأداة. كل استدعاء يُسجَّل
        // بالكامل (نص الاستعلام + النتيجة) في ai_agent_log مثل أي أداة أخرى — للمراجعة فقط، لا كقيد مانع.
        ['type' => 'function', 'function' => ['name' => 'run_sql',
            'description' => 'ينفّذ أي استعلام SQL مباشرة على قاعدة بيانات الموقع الفعلية (MySQL) — قراءة أو تعديل أو حذف، بلا أي قيد. استخدمها لأي إجراء لا تغطيه أداة مخصصة أعلاه (مثل حذف مستخدم أو طلب نهائياً، أو أي تعديل جماعي مباشر على الجداول). اكتب أسماء الجداول/الأعمدة الصحيحة فقط — تحقق منها بـ SELECT قبل أي DELETE/UPDATE إن لم تكن متأكداً من تطابق الشرط. لا يوجد تراجع بعد أي حذف.',
            'parameters' => ['type' => 'object', 'properties' => [
                'sql' => ['type' => 'string', 'description' => 'نص استعلام SQL كامل وصالح لتنفيذه مباشرة'],
            ], 'required' => ['sql']]]],
    ];
}

function ai_agent_system_prompt(): string {
    $site_name = get_setting('site_name', 'المتجر');
    return 'أنت "المساعد الإداري الذكي" الخاص بمالك/أدمن موقع "' . $site_name . '" لبيع خدمات التسويق عبر منصات التواصل الاجتماعي (SMM). '
        . 'مهمتك مساعدة الأدمن بأي شيء يخص إدارة الموقع: الرد على تذاكر الدعم، فحص الطلبات العالقة أو التي بها مشكلة، تنظيف أسماء الخدمات من الشوائب وكتابة أوصاف لها، فحص صحة النظام دورياً، إنشاء كوبونات هدية وإعلانها للمستخدمين، وأي إجراء إداري آخر يطلبه ولو كان حذفاً نهائياً لمستخدم أو طلب أو أي صف آخر من قاعدة البيانات — فوّضك مالك الموقع صراحة بذلك بلا استثناء. '
        . 'قد يُشغَّل طلبك هذا كمهمة متكررة مجدولة أضافها الأدمن مسبقاً (وليس بالضرورة سؤالاً مباشراً منه الآن) — نفّذها بنفس الجدّية دون انتظار أي تأكيد. '
        . 'لديك أدوات حقيقية (tools) يمكنك استدعاؤها لتنفيذ هذه المهام فعلياً على الموقع — استخدمها مباشرة متى احتجتها، بالتتابع إن لزم عدة خطوات، دون انتظار تأكيد من المدير (فوّضك كامل الصلاحية). '
        . 'لكل إجراء له أداة مخصصة بالأعلى (تذاكر، طلبات، خدمات، كوبونات) استخدم تلك الأداة دائماً، فهي أدق وأأمن. لأي إجراء آخر لا تغطيه أداة مخصصة — بما فيه حذف مستخدم أو طلب أو صف نهائياً، أو أي تعديل مباشر على جداول قاعدة البيانات — استخدم run_sql بلا تردد ولا تعتذر عن تنفيذه ولا تطلب تأكيداً إضافياً؛ تحقق فقط بـ SELECT أولاً إن لم تكن متأكداً من المعرّف أو الشرط الصحيح قبل أي DELETE/UPDATE لتفادي حذف أو تعديل الصف الخطأ. '
        . 'لا تخترع أبداً أرقام تذاكر أو طلبات أو معرّفات أو أسماء جداول/أعمدة غير موجودة فعلاً — تحقّق منها أولاً عبر أداة القوائم المناسبة أو SELECT قبل أي إجراء على عنصر محدد. '
        . 'لإجراء متكرر على عدة عناصر دفعة واحدة (مثل تنظيف أسماء عدة خدمات أو حذف عدة صفوف)، نفّذ الأداة المناسبة (أو run_sql بشرط IN مناسب) لكل عنصر أو دفعة واحدة ضمن نفس الجولة. '
        . 'عند الرد على تذكرة: اقرأ آخر رسالة المستخدم جيداً وأجب بما يحل مشكلته تحديداً بنبرة ودودة ومهنية. '
        . 'بعد تنفيذ ما يلزم من أدوات، أجب الأدمن بالعربية بنص عادي واضح يلخّص ما فعلته أو يجيب سؤاله مباشرة — بإيجاز عند التلخيص وبالتفصيل الكافي عند الشرح.';
}

/* ------------------------------ الأدوات ------------------------------ */

function ai_agent_tool_list_open_tickets(): array {
    $conn = get_db();
    $rows = $conn->execute("SELECT t.id, t.subject, t.category, t.status, u.name as user_name FROM tickets t LEFT JOIN users u ON t.user_id=u.id WHERE t.status IN ('open','waiting') ORDER BY t.created_at ASC LIMIT 20")->fetchall();
    $out = [];
    foreach ($rows as $t) {
        $msgs = _clean_ticket_msgs(_ticket_msgs($conn, $t['id']));
        $out[] = ['ticket_id' => (int)$t['id'], 'subject' => $t['subject'], 'category' => $t['category'], 'status' => $t['status'],
                  'user_name' => py_or($t['user_name'], ''), 'last_messages' => array_slice($msgs, -4)];
    }
    return ['count' => count($out), 'tickets' => $out];
}

function ai_agent_tool_reply_ticket(array $args): array {
    $tid = (int)($args['ticket_id'] ?? 0);
    $msg = trim((string)($args['message'] ?? ''));
    if (!$tid || $msg === '') return ['error' => 'ticket_id ونص الرسالة مطلوبان'];
    $conn = get_db();
    $t = $conn->execute('SELECT * FROM tickets WHERE id=?', [$tid])->fetchone();
    if (!$t) return ['error' => 'لا توجد تذكرة بهذا الرقم'];
    $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)', [$tid, 'admin', $msg]);
    $conn->execute('UPDATE tickets SET status=? WHERE id=?', ['replied', $tid]);
    $conn->commit();
    push_user_notif($t['user_id'], 'system', 'رد على تذكرتك #TK-' . $tid, $msg, 'message', 'ticket:' . $tid);
    return ['ok' => true, 'ticket_id' => $tid];
}

function ai_agent_tool_close_ticket(array $args): array {
    $tid = (int)($args['ticket_id'] ?? 0);
    if (!$tid) return ['error' => 'ticket_id مطلوب'];
    $conn = get_db();
    $t = $conn->execute('SELECT * FROM tickets WHERE id=?', [$tid])->fetchone();
    if (!$t) return ['error' => 'لا توجد تذكرة بهذا الرقم'];
    $conn->execute('UPDATE tickets SET status=? WHERE id=?', ['closed', $tid]);
    $conn->commit();
    push_user_notif($t['user_id'], 'system', 'تم إغلاق تذكرتك #TK-' . $tid, 'تم إغلاق التذكرة من قبل الإدارة', 'lock', 'ticket:' . $tid);
    return ['ok' => true, 'ticket_id' => $tid];
}

function ai_agent_tool_list_problem_orders(): array {
    $conn = get_db();
    $cut = microtime(true) - 86400;
    $rows = $conn->execute("SELECT id, display_id, service_name, quantity, charge, status, created_at FROM orders WHERE status IN ('Processing','Pending','In progress') AND created_at < ? ORDER BY created_at ASC LIMIT 25", [$cut])->fetchall();
    $out = [];
    foreach ($rows as $o) {
        $out[] = ['order_id' => (int)$o['id'], 'display_id' => $o['display_id'], 'service_name' => $o['service_name'],
                  'quantity' => $o['quantity'], 'charge' => $o['charge'], 'status' => $o['status'],
                  'hours_stuck' => round((microtime(true) - (float)$o['created_at']) / 3600, 1)];
    }
    return ['count' => count($out), 'orders' => $out];
}

function ai_agent_tool_get_order(array $args): array {
    $oid = (int)($args['order_id'] ?? 0);
    if (!$oid) return ['error' => 'order_id مطلوب'];
    $conn = get_db();
    $o = $conn->execute('SELECT * FROM orders WHERE id=?', [$oid])->fetchone();
    if (!$o) return ['error' => 'لا يوجد طلب بهذا الرقم'];
    unset($o['comments']);
    return $o;
}

function ai_agent_tool_list_services(string $query, int $limit = 40): array {
    $q = mb_strtolower(trim($query), 'UTF-8');
    $conn = get_db();
    $rows = $conn->execute("SELECT * FROM service_filters WHERE filter_type='service' AND hidden=0")->fetchall();
    $base = [];
    foreach (get_all_smm_services() as $s) { if (is_array($s)) $base[py_str(dget($s, 'service', '')) . '|' . py_str(dget($s, '_prov_id', 0))] = $s; }
    [$plats] = _build_services_catalog();
    $eff = [];
    foreach ($plats as $pn => $pl) foreach ($pl['categories'] as $cn => $items) foreach ($items as $it) $eff[$it['id'] . '|' . $pn] = $it + ['_cat' => $cn];
    $out = [];
    foreach ($rows as $r) {
        $sid = py_str($r['filter_key']); $pid = (int)$r['prov_id']; $plat = py_str($r['platform']);
        $b = $base[$sid . '|' . $pid] ?? null;
        $e = $eff[$sid . '|' . $plat] ?? null;
        if (!$b && !$e) continue;
        $name = $e['name'] ?? ($b ? py_str(dget($b, 'name', '')) : ('#' . $sid));
        $orig_name = $b ? py_str(dget($b, 'name', '')) : $name;
        $cname = py_str($r['custom_name'] ?? '');
        $cdesc = $r->has('custom_desc') ? py_str($r['custom_desc'] ?? '') : '';
        $cat = $e['_cat'] ?? py_str(py_or($r['custom_category'], $b ? dget($b, 'category', '') : ''));
        if ($q !== '' && mb_stripos($name . ' ' . $orig_name . ' ' . $plat . ' ' . $cat, $q, 0, 'UTF-8') === false) continue;
        $out[] = ['id' => $sid, 'prov_id' => $pid, 'platform' => $plat, 'category' => $cat,
                  'current_name' => $name, 'original_provider_name' => $orig_name, 'custom_name_set' => $cname !== '',
                  'current_desc' => $e['desc'] ?? $cdesc, 'custom_desc_set' => $cdesc !== '',
                  'refill' => $b ? svc_flag(dget($b, 'refill', false)) : false, 'cancel' => $b ? svc_flag(dget($b, 'cancel', false)) : false];
        if (count($out) >= $limit) break;
    }
    return ['count' => count($out), 'services' => $out];
}

function ai_agent_tool_update_service(array $args, string $field): array {
    $sid = py_str($args['id'] ?? ''); $pid = (int)($args['prov_id'] ?? 0);
    if ($sid === '') return ['error' => 'id مطلوب (من list_services)'];
    $row = svcctl_find_row($sid, $pid);
    if (!$row) return ['error' => 'الخدمة غير موجودة بالمتجر — استخدم list_services أولاً للتأكد من المعرّف'];
    $conn = get_db();
    if ($field === 'name') {
        $v = svcctl_clip(py_str($args['name'] ?? ''), 300);
        if ($v === '') return ['error' => 'الاسم الجديد فارغ'];
        $conn->execute('UPDATE service_filters SET custom_name=? WHERE id=?', [$v, $row['id']]);
    } else {
        $v = mb_substr(trim(py_str($args['desc'] ?? '')), 0, 4000, 'UTF-8');
        if ($v === '') return ['error' => 'الوصف الجديد فارغ'];
        $conn->execute('UPDATE service_filters SET custom_desc=? WHERE id=?', [$v, $row['id']]);
    }
    $conn->commit();
    _bump_svc_version();
    return ['ok' => true, 'id' => $sid, 'prov_id' => $pid, 'field' => $field, 'value' => $v];
}

function ai_agent_tool_check_health(): array {
    $out = [];
    try { get_db()->execute('SELECT 1'); $out['db'] = 'ok'; } catch (Throwable $e) { $out['db'] = 'فشل: ' . $e->getMessage(); }
    [$ai_ok, $ai_msg] = _check_ai_connectivity();
    $out['ai_provider'] = $ai_ok ? 'ok' : $ai_msg;
    try {
        $provs = get_all_providers();
        $pstat = [];
        foreach ($provs as $p) {
            if (!py_truthy($p['active']) || !py_truthy($p['api_url']) || !py_truthy($p['api_key'])) { $pstat[] = $p['name'] . ': غير مفعّل'; continue; }
            try {
                $r = requests_post($p['api_url'], ['data' => ['key' => $p['api_key'], 'action' => 'balance'], 'timeout' => 10]);
                $pstat[] = $p['name'] . ': ' . ($r->status_code == 200 ? 'متصل' : 'HTTP ' . $r->status_code);
            } catch (Throwable $e) { $pstat[] = $p['name'] . ': تعذّر الاتصال (' . py_slice($e->getMessage(), null, 80) . ')'; }
        }
        $out['providers'] = $pstat;
    } catch (Throwable $e) { $out['providers'] = ['خطأ: ' . $e->getMessage()]; }
    try {
        $conn = get_db();
        $out['stuck_orders_count'] = (int)$conn->execute("SELECT COUNT(*) FROM orders WHERE status IN ('Processing','Pending','In progress') AND created_at < ?", [microtime(true) - 86400])->fetchone()[0];
        $out['open_tickets_count'] = (int)$conn->execute("SELECT COUNT(*) FROM tickets WHERE status IN ('open','waiting')")->fetchone()[0];
    } catch (Throwable $e) {}
    $log_file = DATA_DIR . '/logs/app_errors.log';
    $tail = [];
    if (is_file($log_file)) {
        $lines = @file($log_file, FILE_IGNORE_NEW_LINES);
        if ($lines) foreach (array_slice($lines, -10) as $l) { $j = json_decode($l, true); if ($j) $tail[] = $j['level'] . ': ' . py_slice($j['message'] ?? '', null, 200); }
    }
    $out['recent_errors'] = $tail ?: ['لا توجد أخطاء مسجّلة مؤخراً'];
    return $out;
}

function ai_agent_tool_create_coupon(array $args): array {
    $amount = py_float($args['amount'] ?? 0);
    if ($amount <= 0) return ['error' => 'amount يجب أن يكون أكبر من صفر'];
    $code = py_upper_s(py_strip_s($args['code'] ?? ''));
    if ($code === '') $code = 'GIFT' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $max_uses = max(1, (int)($args['max_uses'] ?? 1));
    $notify_all = !array_key_exists('notify_all', $args) || py_truthy($args['notify_all']);
    $cid = uuid8();
    $conn = get_db();
    try {
        $conn->execute('INSERT INTO coupons(id,code,amount,max_uses,expires_at) VALUES(?,?,?,?,?)', [$cid, $code, $amount, $max_uses, '']);
        $conn->commit();
    } catch (Throwable $e) {
        return ['error' => 'الكود "' . $code . '" موجود مسبقاً، جرّب كوداً آخر'];
    }
    if ($notify_all) {
        $users = $conn->execute('SELECT id FROM users WHERE is_admin=0 ORDER BY id ASC')->fetchall();
        foreach ($users as $u) {
            $nid = uuid8();
            $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
                [$nid, $u['id'], 'system', 'كوبون هدية جديد', "استخدم الكود $code واحصل على $" . py_fmt($amount, 2) . ' رصيد مجاني!', 'gift']);
        }
        $conn->commit();
    }
    return ['ok' => true, 'code' => $code, 'amount' => $amount, 'max_uses' => $max_uses, 'notified_users' => $notify_all];
}

/**
 * تنفيذ SQL حر بلا أي قيد على قاعدة بيانات الموقع — بتفويض صريح من مالك الموقع (يشمل DELETE نهائي بلا تراجع).
 * يُسجَّل نص الاستعلام والنتيجة بالكامل في ai_agent_log من المستدعي (ai_agent_run) تماماً كأي أداة أخرى، للمراجعة فقط.
 */
function ai_agent_tool_run_sql(array $args): array {
    $sql = trim((string)($args['sql'] ?? ''));
    if ($sql === '') return ['error' => 'sql مطلوب'];
    $conn = get_db();
    try {
        $cur = $conn->execute($sql);
        $conn->commit();
        $out = ['row_count' => $cur->rowcount];
        $rows = $cur->fetchall();
        if ($rows) {
            $out['rows'] = array_slice(rows_dicts($rows), 0, 200);
            if (count($rows) > 200) $out['truncated'] = true;
        }
        if ($cur->lastrowid) $out['last_insert_id'] = $cur->lastrowid;
        return $out;
    } catch (Throwable $e) {
        $conn->rollback();
        return ['error' => $e->getMessage()];
    }
}

function ai_agent_exec_tool(string $tool, array $args): array {
    try {
        switch ($tool) {
            case 'list_open_tickets': return [ai_agent_tool_list_open_tickets(), true];
            case 'reply_ticket': $r = ai_agent_tool_reply_ticket($args); return [$r, !isset($r['error'])];
            case 'close_ticket': $r = ai_agent_tool_close_ticket($args); return [$r, !isset($r['error'])];
            case 'list_problem_orders': return [ai_agent_tool_list_problem_orders(), true];
            case 'get_order': $r = ai_agent_tool_get_order($args); return [$r, !isset($r['error'])];
            case 'list_services': return [ai_agent_tool_list_services((string)($args['query'] ?? '')), true];
            case 'update_service_name': $r = ai_agent_tool_update_service($args, 'name'); return [$r, !isset($r['error'])];
            case 'update_service_desc': $r = ai_agent_tool_update_service($args, 'desc'); return [$r, !isset($r['error'])];
            case 'check_system_health': return [ai_agent_tool_check_health(), true];
            case 'create_gift_coupon': $r = ai_agent_tool_create_coupon($args); return [$r, !isset($r['error'])];
            case 'run_sql': $r = ai_agent_tool_run_sql($args); return [$r, !isset($r['error'])];
            default: return [['error' => 'أداة غير معروفة: ' . $tool], false];
        }
    } catch (Throwable $e) {
        log_app_error('WARNING', 'ai_agent tool ' . $tool . ': ' . $e->getMessage());
        return [['error' => $e->getMessage()], false];
    }
}

/**
 * يكشف إن كان نص رد (بلا tool_calls رسمية) يحاول فعلياً وصف استدعاء أداة بصيغة تحكّم غير رسمية بدل نص طبيعي —
 * بعض النماذج (أو بوابات توجيه الطلبات) قد تُسرّب رموز تحكّمها الداخلية الخاصة باستدعاء الأدوات كنص عادي
 * بدل تعبئة tool_calls الرسمية، فيظهر للأدمن كلام مبهم مثل "<DSML| |calls| |>" بدل تنفيذ فعلي أو رد مفهوم.
 */
function ai_agent_looks_garbled(string $text): bool {
    return (bool)preg_match('/dsml|invoke\s+name\s*=|function_call|tool[_\x{2581}\x{ff5c}]*calls?\s*[_\x{2581}\x{ff5c}]*(begin|end)?/iu', $text)
        || str_contains($text, '｜') || str_contains($text, '▁');
}

/**
 * يحاول استخراج اسم أداة ومعاملاتها فعلياً من نص "مشوّه" يحوي صيغة شبه-XML (مثل <｜｜DSML｜｜ invoke name="x">...)
 * يسرّبها بعض المزوّدين أحياناً كنص عادي بدل تعبئة tool_calls الرسمية — بدل الاستسلام مباشرة نحاول تنفيذ
 * الاستدعاء المقصود فعلياً. نرجع ['name','args'] فقط إن كان اسم الأداة معروفاً وكل معاملاتها المطلوبة (required)
 * متوفرة بوضوح بالنص — وإلا null، فلا نخمّن تنفيذ إجراء (خصوصاً مع وجود أداة run_sql الحرة) بمعاملات ناقصة أو غامضة.
 */
function ai_agent_parse_dsml_tool_call(string $text, array $tools): ?array {
    if (!preg_match('/invoke\s+name\s*=\s*"([a-zA-Z0-9_]+)"/u', $text, $m)) return null;
    $name = $m[1];
    $schema = null;
    foreach ($tools as $t) { if (($t['function']['name'] ?? '') === $name) { $schema = $t['function']; break; } }
    if ($schema === null) return null;
    $required = $schema['parameters']['required'] ?? [];
    $body = '';
    if (preg_match('/invoke\s+name\s*=\s*"' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/[^>]*invoke\s*>/su', $text, $bm)) $body = trim($bm[1]);
    $args = [];
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $args = $decoded;
        } elseif (preg_match_all('/<[^>]*parameter\s+name\s*=\s*"([a-zA-Z0-9_]+)"[^>]*>(.*?)<\/[^>]*parameter\s*>/su', $body, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $p) $args[$p[1]] = trim($p[2]);
        }
    }
    foreach ($required as $r) {
        if (!array_key_exists($r, $args) || $args[$r] === '') return null;
    }
    return ['name' => $name, 'args' => $args];
}

/**
 * يشغّل جولة محادثة/تنفيذ كاملة: يحفظ رسالة الأدمن (إن وُجدت)، يستدعي النموذج، ينفّذ أي أدوات يطلبها تباعاً
 * (حتى AI_AGENT_MAX_STEPS)، ويعيد الرد النهائي النصي + قائمة الإجراءات المنفَّذة فعلياً بهذه الجولة.
 * $source: 'chat' (محادثة حيّة مع الأدمن) أو 'cron' (فحص دوري تلقائي بلا أدمن حاضر).
 */
function ai_agent_run(string $user_message, string $source = 'chat'): array {
    $conn = get_db();
    $conv = $conn->execute('SELECT id FROM ai_agent_conversations ORDER BY id LIMIT 1')->fetchone();
    if ($conv) {
        $conv_id = (int)$conv['id'];
    } else {
        $cur = $conn->execute('INSERT INTO ai_agent_conversations(title,created_at,updated_at) VALUES(?,?,?)', ['المساعد الإداري الذكي', microtime(true), microtime(true)]);
        $conv_id = $cur->lastrowid;
        $conn->commit();
    }

    if (trim($user_message) !== '') {
        $conn->execute('INSERT INTO ai_agent_messages(conversation_id,role,content,created_at) VALUES(?,?,?,?)', [$conv_id, 'user', $user_message, microtime(true)]);
        $conn->commit();
    }

    // ملاحظة: فقط رسائل (user, assistant) النهائية المقروءة تُحفَظ طويل الأمد — سلسلة tool_calls/tool الخام
    // تبقى داخل هذه الجولة فقط (أسفل) ولا تُخزَّن، لتبسيط المخطط وتفادي حفظ معرّفات tool_call_id عبر الزمن.
    $hist = $conn->execute('SELECT role, content FROM ai_agent_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 40', [$conv_id])->fetchall();
    $messages = [['role' => 'system', 'content' => ai_agent_system_prompt()]];
    foreach (array_reverse($hist) as $h) $messages[] = ['role' => $h['role'], 'content' => $h['content']];

    $tools = ai_agent_tool_schemas();
    $actions = [];
    $final_reply = null;
    $garbled_retry_used = false;
    for ($i = 0; $i < AI_AGENT_MAX_STEPS; $i++) {
        // توكنات أعلى من المحادثة العادية: إجراء إداري واحد قد يستدعي عدة أدوات متوازية بنفس الرد
        // (مثلاً تحديث أسماء عشرات الخدمات دفعة واحدة) — القطع المبكر يفسد تجميع tool_calls الرسمية
        [$msg, $err] = call_ai_api_raw($messages, $tools, 4000);
        if ($err) { $final_reply = $err; break; }
        $toolCalls = $msg['tool_calls'] ?? null;
        if (!$toolCalls) {
            $reply = py_truthy($msg['content'] ?? null) ? $msg['content'] : 'تم.';
            // بعض المزوّدين/النماذج قد "يهلوس" أحياناً بصيغة استدعاء أداة غير رسمية كنص عادي بدل تعبئة tool_calls
            // الرسمية (رموز تحكّم مشوّهة مثل <DSML|...>) — لا نعرض هذا النص كأنه رد نهائي سليم أبداً.
            if (ai_agent_looks_garbled($reply)) {
                log_app_error('WARNING', 'ai_agent: garbled non-tool_calls content from provider: ' . mb_substr($reply, 0, 300, 'UTF-8'));
                // أولاً: نحاول استخراج استدعاء الأداة المقصود فعلياً من هذه الصيغة المشوّهة وتنفيذه مباشرة
                // (حل جذري لمشكلة متكررة لدى بعض الحسابات حيث يرسل المزوّد كل استدعاء أداة بهذه الصيغة دائماً
                // مهما أعدنا المحاولة أو ذكّرناه) بدل الاستسلام مباشرة برسالة خطأ.
                $extracted = ai_agent_parse_dsml_tool_call($reply, $tools);
                if ($extracted !== null) {
                    $toolCalls = [['id' => 'dsml_1', 'type' => 'function', 'function' => [
                        'name' => $extracted['name'], 'arguments' => json_encode($extracted['args'], JSON_UNESCAPED_UNICODE),
                    ]]];
                    $msg['content'] = null;
                    $msg['tool_calls'] = $toolCalls;
                } elseif (!$garbled_retry_used) {
                    // تعذّر الاستخراج (أداة غير معروفة أو معاملات مطلوبة ناقصة) — نعيد المحاولة مرة واحدة بتذكير صريح
                    $garbled_retry_used = true;
                    $messages[] = ['role' => 'system', 'content' => 'ردّك السابق لم يصل بصيغة سليمة. استخدم آلية استدعاء الأدوات الرسمية (tool_calls) فقط لتنفيذ أي إجراء، ولا تكتب استدعاء الأداة كنص عادي إطلاقاً.'];
                    continue;
                } else {
                    $final_reply = 'تعذّر الحصول على رد منظّم من مزوّد الذكاء الاصطناعي لهذا الطلب (مشكلة تنسيق من النموذج نفسه، وليست عطلاً بالموقع). جرّب تبسيط الطلب أو إعادة المحاولة بعد قليل. إن تكرر الأمر باستمرار، تأكد من أن النموذج المحدد بإعدادات المساعد الذكي (deepseek_model) يدعم فعلياً استدعاء الأدوات (Tool/Function Calling).';
                    break;
                }
            } else {
                $final_reply = $reply;
                break;
            }
        }

        $messages[] = $msg;
        foreach ($toolCalls as $tc) {
            $fn = $tc['function']['name'] ?? '';
            $args = json_decode($tc['function']['arguments'] ?? '{}', true);
            if (!is_array($args)) $args = [];
            [$result, $ok] = ai_agent_exec_tool($fn, $args);
            $actions[] = ['tool' => $fn, 'args' => $args, 'ok' => $ok, 'result' => $result];
            $conn->execute('INSERT INTO ai_agent_log(created_at,source,tool,detail,success) VALUES(?,?,?,?,?)',
                [microtime(true), $source, $fn, json_encode(['args' => $args, 'result' => $result], JSON_UNESCAPED_UNICODE), $ok ? 1 : 0]);
            $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
        }
        $conn->commit();
    }
    if ($final_reply === null) $final_reply = 'وصلت للحد الأقصى من الإجراءات المتتالية بجولة واحدة (6)؛ إن احتجت أكثر أخبرني لأكمل.';

    $conn->execute('INSERT INTO ai_agent_messages(conversation_id,role,content,created_at) VALUES(?,?,?,?)', [$conv_id, 'assistant', $final_reply, microtime(true)]);
    $conn->execute('UPDATE ai_agent_conversations SET updated_at=? WHERE id=?', [microtime(true), $conv_id]);
    $conn->commit();

    return ['reply' => $final_reply, 'actions' => $actions, 'conversation_id' => $conv_id];
}
