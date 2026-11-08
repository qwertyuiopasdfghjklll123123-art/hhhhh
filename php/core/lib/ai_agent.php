<?php
/**
 * المساعد الذكي للأدمن: يستخدم نفس مزوّد الذكاء الاصطناعي (call_ai_api) لكن بصلاحية تنفيذ أدوات إدارية
 * حقيقية (رد على تذاكر، فحص طلبات، تنظيف أسماء/أوصاف خدمات، فحص صحة النظام) — بتفويض كامل بلا موافقة لكل إجراء،
 * حسب طلب الأدمن صراحة. كل إجراء يُسجَّل في ai_agent_log للمراجعة لاحقاً حتى لو نُفِّذ تلقائياً.
 *
 * البروتوكول: بدل الاعتماد على "استدعاء أدوات" من مزوّد معيّن (غير مضمون الدعم لكل النماذج)، نطلب من النموذج
 * أن يكتب سطر JSON بصيغة {"tool":"اسم","args":{...}} عندما يريد تنفيذ إجراء، نُنفّذه، ونُعيد له النتيجة كرسالة
 * تالية، بحلقة محدودة التكرار (AI_AGENT_MAX_STEPS) تمنع أي دوران لا نهائي.
 */

const AI_AGENT_MAX_STEPS = 6;

function ai_agent_tools_prompt(): string {
    return <<<'TXT'

عندما تحتاج لتنفيذ إجراء فعلي استخدم فقط الأدوات التالية. للتنفيذ، اجعل ردّك بالكامل سطراً واحداً بصيغة JSON بالضبط بلا أي نص أو شرح قبله أو بعده:
{"tool":"اسم_الأداة","args":{...}}
بعدها ستصلك نتيجة التنفيذ في رسالة تالية فتكمل عملك بناءً عليها. يمكنك استخدام عدة أدوات بالتتابع حسب الحاجة (حد أقصى 6 خطوات بجلسة واحدة).
عندما تنتهي تماماً من كل ما يلزم، أو إن لم تحتج لأي أداة، اكتب رداً نصياً عادياً (بدون JSON) موجّهاً للمدير يلخّص ما فعلته أو يجيب سؤاله مباشرة.

الأدوات المتاحة:
- list_open_tickets {} : يرجع كل تذاكر الدعم التي تنتظر رداً من الإدارة (مفتوحة/بانتظار رد) مع آخر رسائلها.
- reply_ticket {"ticket_id": رقم, "message": "نص الرد"} : يرسل رداً فورياً لصاحب التذكرة ويعلّمها كمُجاب عليها.
- close_ticket {"ticket_id": رقم} : يغلق التذكرة نهائياً (استخدمها فقط بعد حل المشكلة فعلاً أو إن كانت التذكرة قديمة ولا رد من صاحبها).
- list_problem_orders {} : يرجع الطلبات العالقة (قيد التنفيذ منذ أكثر من 24 ساعة بلا اكتمال) لفحصها.
- get_order {"order_id": رقم} : تفاصيل طلب واحد بكل حقوله.
- list_services {"query": "كلمة بحث اختيارية بالاسم أو المنصة"} : يرجع حتى 40 خدمة من كتالوج الموقع الفعلي (معرّفها id/prov_id، اسمها الحالي، اسمها الأصلي من المزوّد، وصفها الحالي، المنصة، وهل تدعم تعويض/إلغاء). استخدمها دائماً أولاً قبل أي تعديل اسم أو وصف لمعرفة المعرّف الصحيح ومحتوى الخدمة الفعلي — لا تخترع معرّفات أو أسماء خدمات من عندك.
- update_service_name {"id": "معرّف من list_services", "prov_id": رقم, "name": "الاسم الجديد النظيف"} : يحدّث اسم الخدمة الظاهر للمستخدمين.
- update_service_desc {"id": "معرّف من list_services", "prov_id": رقم, "desc": "الوصف الجديد"} : يحدّث وصف الخدمة الظاهر للمستخدمين.
- check_system_health {} : يفحص الاتصال بقاعدة البيانات، الاتصال بمزوّد الخدمات، آخر أخطاء مسجّلة بالنظام، وعدد الطلبات العالقة — استخدمها عند أي فحص دوري أو شك بوجود عطل.

قواعد صارمة يجب اتّباعها دائماً:
1. المدير فوّضك كامل الصلاحية — نفّذ الإجراءات المناسبة مباشرة دون انتظار تأكيد منه على كل خطوة.
2. لا تخترع أبداً أرقام تذاكر أو طلبات أو معرّفات خدمات غير موجودة فعلاً فيما أرجعته الأدوات — استخدم القوائم أولاً دائماً.
3. عند تنظيف اسم خدمة: احذف فقط الرموز التعبيرية (إيموجي)، الرموز الزخرفية الغريبة، والأرقام/الشرطات الزائدة في نهاية الاسم بلا معنى. حافظ دوماً على المعلومات المهمة داخل الاسم (المنصة، الحد الأقصى للكمية، مستوى الجودة مثل Real/HQ، السرعة/فورية Instant، إمكانية Refill ومدتها) ولا تحذفها.
4. عند كتابة وصف خدمة: اكتب جملتين إلى ثلاث بالعربية الفصحى الواضحة تشرح ما تقدمه الخدمة فعلاً بناءً على اسمها الأصلي ونوعها (متابعين/لايكات/مشاهدات/تعليقات...) وهل تدعم تعويضاً أو إلغاءً — دون مبالغة أو اختراع مزايا غير مؤكدة من الاسم أو البيانات المتاحة.
5. عند الرد على تذكرة: اقرأ آخر رسالة المستخدم جيداً وأجب بالعربية بما يحل مشكلته تحديداً بنبرة ودودة ومهنية؛ لا تغلق التذكرة إلا إن كانت محلولة فعلاً.
TXT;
}

function ai_agent_system_prompt(): string {
    $site_name = get_setting('site_name', 'المتجر');
    return 'أنت "المساعد الإداري الذكي" الخاص بمالك/أدمن موقع "' . $site_name . '" لبيع خدمات التسويق عبر منصات التواصل الاجتماعي (SMM). '
        . 'مهمتك مساعدة الأدمن بأي شيء يخص إدارة الموقع: الرد على تذاكر الدعم، فحص الطلبات العالقة أو التي بها مشكلة، تنظيف أسماء الخدمات من الشوائب وكتابة أوصاف لها، وفحص صحة النظام دورياً. '
        . 'أجب الأدمن بالعربية دائماً، بوضوح واختصار عند التلخيص، وبالتفصيل الكافي عند الشرح.'
        . ai_agent_tools_prompt();
}

/** يفصل رد النموذج عن استدعاء أداة JSON إن وُجد (متسامح مع ```json ... ``` إن كتبها النموذج رغم التعليمات) */
function ai_agent_parse_tool_call(string $reply): ?array {
    $t = trim($reply);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $t, $m)) $t = trim($m[1]);
    if ($t === '' || $t[0] !== '{') return null;
    $j = json_decode($t, true);
    if (!is_array($j) || !isset($j['tool']) || !is_string($j['tool'])) return null;
    return $j;
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
            default: return [['error' => 'أداة غير معروفة: ' . $tool], false];
        }
    } catch (Throwable $e) {
        log_app_error('WARNING', 'ai_agent tool ' . $tool . ': ' . $e->getMessage());
        return [['error' => $e->getMessage()], false];
    }
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

    $hist = $conn->execute('SELECT role, content FROM ai_agent_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 40', [$conv_id])->fetchall();
    $messages = [['role' => 'system', 'content' => ai_agent_system_prompt()]];
    foreach (array_reverse($hist) as $h) $messages[] = ['role' => $h['role'], 'content' => $h['content']];

    $actions = [];
    $final_reply = null;
    for ($i = 0; $i < AI_AGENT_MAX_STEPS; $i++) {
        [$reply, $err] = call_ai_api($messages);
        if ($err) { $final_reply = $err; break; }
        $tool = ai_agent_parse_tool_call($reply);
        if ($tool === null) { $final_reply = $reply; break; }

        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $conn->execute('INSERT INTO ai_agent_messages(conversation_id,role,content,created_at) VALUES(?,?,?,?)', [$conv_id, 'assistant', $reply, microtime(true)]);

        [$result, $ok] = ai_agent_exec_tool($tool['tool'], (array)($tool['args'] ?? []));
        $actions[] = ['tool' => $tool['tool'], 'args' => $tool['args'] ?? [], 'ok' => $ok, 'result' => $result];
        $conn->execute('INSERT INTO ai_agent_log(created_at,source,tool,detail,success) VALUES(?,?,?,?,?)',
            [microtime(true), $source, $tool['tool'], json_encode(['args' => $tool['args'] ?? [], 'result' => $result], JSON_UNESCAPED_UNICODE), $ok ? 1 : 0]);

        $result_msg = '[نتيجة تنفيذ الأداة ' . $tool['tool'] . ']: ' . json_encode($result, JSON_UNESCAPED_UNICODE);
        $messages[] = ['role' => 'user', 'content' => $result_msg];
        $conn->execute('INSERT INTO ai_agent_messages(conversation_id,role,content,created_at) VALUES(?,?,?,?)', [$conv_id, 'user', $result_msg, microtime(true)]);
        $conn->commit();
    }
    if ($final_reply === null) $final_reply = 'وصلت للحد الأقصى من الإجراءات المتتالية بجولة واحدة (6)؛ إن احتجت أكثر أخبرني لأكمل.';

    $conn->execute('INSERT INTO ai_agent_messages(conversation_id,role,content,created_at) VALUES(?,?,?,?)', [$conv_id, 'assistant', $final_reply, microtime(true)]);
    $conn->execute('UPDATE ai_agent_conversations SET updated_at=? WHERE id=?', [microtime(true), $conv_id]);
    $conn->commit();

    return ['reply' => $final_reply, 'actions' => $actions, 'conversation_id' => $conv_id];
}
