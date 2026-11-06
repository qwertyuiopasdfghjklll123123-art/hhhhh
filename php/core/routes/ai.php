<?php
/**
 * المساعد الذكي (NVIDIA API): نداء الخدمة + سياق الحساب/الخدمات/طرق الدفع + المحادثات
 * منقول من app.py:4183-4462.
 */

const AI_API_BASE = 'https://integrate.api.nvidia.com/v1/chat/completions';

/** @return array [reply|null, error|null] */
function call_ai_api(array $messages): array {
    $api_key = get_setting('nvidia_api_key', '');
    $model = py_or(get_setting('nvidia_model', 'openai/gpt-oss-20b'), 'openai/gpt-oss-20b');
    if (!py_truthy($api_key)) {
        return [null, 'لم يتم إعداد مفتاح المساعد الذكي بعد. الرجاء التواصل مع الإدارة.'];
    }
    $resp = null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $resp = requests_post(AI_API_BASE, [
                'headers' => ['Content-Type' => 'application/json', 'Authorization' => "Bearer $api_key"],
                'json' => ['model' => $model, 'messages' => $messages, 'temperature' => 1, 'top_p' => 1, 'max_tokens' => 4096, 'stream' => false],
                'timeout' => 30,
            ]);
            break;
        } catch (RequestsTimeoutT $e) {
            return [null, 'استغرق رد المساعد الذكي وقتاً أطول من المعتاد، حاول مرة أخرى.'];
        } catch (Throwable $e) {
            // فشل على مستوى الاتصال غالباً عابر — أعد المحاولة مرة واحدة بعد توقف قصير
            if ($attempt == 0) {
                usleep(1500000);
                continue;
            }
            return [null, 'تعذر الاتصال بخدمة الذكاء الاصطناعي، تحقق من اتصالك وحاول مرة أخرى.'];
        }
    }
    if ($resp->status_code != 200) {
        return [null, 'تعذر الحصول على رد من المساعد الذكي حالياً، حاول مرة أخرى.'];
    }
    try {
        $j = $resp->json();
        $reply = $j['choices'][0]['message']['content'];
    } catch (Throwable $e) {
        $reply = null;
    }
    if (!py_truthy($reply)) {
        return [null, 'لم يصل رد من خدمة الذكاء الاصطناعي.'];
    }
    return [$reply, null];
}

/** @return array [bool, string] */
function _check_ai_connectivity(): array {
    $api_key = get_setting('nvidia_api_key', '');
    $model = py_or(get_setting('nvidia_model', 'openai/gpt-oss-20b'), 'openai/gpt-oss-20b');
    if (!py_truthy($api_key)) {
        return [false, 'لم يتم إعداد مفتاح API الخاص بالمساعد الذكي بعد من لوحة الإدارة.'];
    }
    try {
        $resp = requests_post(AI_API_BASE, [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => "Bearer $api_key"],
            'json' => ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'hi']], 'max_tokens' => 5],
            'timeout' => 15,
        ]);
    } catch (RequestsSSLError $e) {
        return [false, 'فشل الاتصال بسبب مشكلة شهادة SSL: ' . py_slice($e->getMessage(), null, 150)];
    } catch (RequestsConnectionErrorT $e) {
        return [false, 'تعذّر الوصول لسيرفر المساعد الذكي من هذا السيرفر (مشكلة شبكة/DNS/جدار حماية): ' . py_slice($e->getMessage(), null, 150)];
    } catch (RequestsTimeoutT $e) {
        return [false, 'انتهت مهلة الاتصال (أكثر من 15 ثانية) — الشبكة بطيئة جداً أو محجوبة.'];
    } catch (Throwable $e) {
        return [false, 'خطأ غير متوقع أثناء الاتصال: ' . py_slice($e->getMessage(), null, 150)];
    }
    if ($resp->status_code == 200) return [true, 'الاتصال يعمل بشكل طبيعي.'];
    if ($resp->status_code == 401) return [false, 'مفتاح API غير صحيح أو منتهي الصلاحية (خطأ 401) — أنشئ مفتاحاً جديداً من build.nvidia.com.'];
    if ($resp->status_code == 402) return [false, 'رصيد الحساب غير كافٍ (خطأ 402).'];
    if ($resp->status_code == 429) return [false, 'تجاوزت الحد المسموح من الطلبات حالياً (خطأ 429) — عادة مؤقت، أعد المحاولة بعد قليل.'];
    return [false, 'الخدمة رجّعت رمز غير متوقع: ' . $resp->status_code . ' — ' . py_slice($resp->text, null, 150)];
}

route('/api/admin/ai/test-connection', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 403);
    [$ok, $msg] = _check_ai_connectivity();
    return jsonify(['ok' => $ok, 'msg' => $msg]);
}, ['POST']);

function ai_account_status_context($user_id): string {
    // أمان: كل استعلام مقيّد بمعرّف المستخدم نفسه (لا نقبل معرّفات من نص الدردشة)
    $conn = get_db();
    $lines = [];
    $orders = $conn->execute('SELECT id, display_id, service_name, quantity, charge, status, created_at FROM orders WHERE user_id=? ORDER BY created_at DESC, id ASC LIMIT 8', [$user_id])->fetchall();
    foreach ($orders as $o) {
        $when = fmt_time('%Y-%m-%d %H:%M', $o['created_at']);
        $oref = py_or($o['display_id'], $o['id']);
        $lines[] = '- طلب #' . py_str($oref) . ': خدمة "' . py_str($o['service_name']) . '" — الكمية ' . py_str($o['quantity']) . ' — السعر $' . py_str($o['charge']) . ' — الحالة: ' . py_str($o['status']) . " — بتاريخ $when.";
    }
    $custom_orders = $conn->execute('SELECT id, service_name, quantity, cost, status, created_at FROM custom_orders WHERE user_id=? ORDER BY created_at DESC, __fc_seq ASC LIMIT 5', [$user_id])->fetchall();
    foreach ($custom_orders as $c) {
        $when = fmt_time('%Y-%m-%d %H:%M', $c['created_at']);
        $lines[] = '- طلب خدمة مخصصة #' . py_str($c['id']) . ': "' . py_str($c['service_name']) . '" — الكمية ' . py_str($c['quantity']) . ' — السعر $' . py_str($c['cost']) . ' — الحالة: ' . py_str($c['status']) . " — بتاريخ $when.";
    }
    $recharges = $conn->execute('SELECT id, amount, method, status, created_at FROM recharge_requests WHERE user_id=? ORDER BY created_at DESC, id ASC LIMIT 5', [$user_id])->fetchall();
    foreach ($recharges as $r) {
        $when = fmt_time('%Y-%m-%d %H:%M', $r['created_at']);
        $lines[] = '- طلب شحن رصيد #' . py_str($r['id']) . ' بمبلغ $' . py_fmt($r['amount']) . ' عبر ' . py_str($r['method']) . ' — الحالة: ' . py_str($r['status']) . " — بتاريخ $when.";
    }
    if (!$lines) {
        return "\n\nملاحظة: لا توجد لدى هذا المستخدم حالياً أي طلبات أو عمليات شحن رصيد.";
    }
    return "\n\nهذه هي طلبات وعمليات شحن الرصيد الفعلية لهذا المستخدم فقط (لا تخبره أبداً عن أي طلب أو مستخدم آخر، ولا تفترض بيانات غير موجودة هنا):\n"
        . implode("\n", $lines)
        . "\nإذا سأل عن حالة طلب أو سبب تأخيره: استخدم رقمه وحالته أعلاه فقط، وطمئنه دون الوعد بوقت محدد.";
}

function ai_find_services($query, int $limit = 8): array {
    $words = [];
    if (preg_match_all('/(*UCP)[\w\x{0600}-\x{06FF}]+/u', mb_strtolower((string)py_or($query, ''), 'UTF-8'), $m)) {
        foreach ($m[0] as $w) if (py_len($w) > 1) $words[] = $w;
    }
    if (!$words) return [];
    $matches = [];
    try {
        [$platforms, $_t] = _build_services_catalog();
        foreach ($platforms as $plat => $pdata) {
            $plat = (string)$plat;
            foreach ($pdata['categories'] as $cat => $svcs) {
                $cat = (string)$cat;
                foreach ($svcs as $s) {
                    $hay = mb_strtolower(dget($s, 'name', '') . ' ' . $plat . ' ' . $cat, 'UTF-8');
                    $hit = false;
                    foreach ($words as $w) { if (strpos($hay, $w) !== false) { $hit = true; break; } }
                    if ($hit) {
                        $matches[] = ['id' => $s['id'], 'name' => $s['name'], 'platform' => $plat, 'category' => $cat, 'rate' => dget($s, 'rate', '0')];
                        if (count($matches) >= $limit * 3) break;
                    }
                }
            }
        }
    } catch (Throwable $e) {}
    try {
        $conn = get_db();
        $crows = $conn->execute('SELECT id, name, type, rate FROM custom_services WHERE active=1 ORDER BY __fc_seq ASC')->fetchall();
        foreach ($crows as $c) {
            $hay = mb_strtolower($c['name'] . ' ' . py_or($c['type'], ''), 'UTF-8');
            $hit = false;
            foreach ($words as $w) { if (strpos($hay, $w) !== false) { $hit = true; break; } }
            if ($hit) {
                $matches[] = ['id' => $c['id'], 'name' => $c['name'], 'platform' => py_or($c['type'], 'مخصصة'), 'category' => 'خدمة مخصصة', 'rate' => py_str($c['rate']), 'custom' => true];
            }
        }
    } catch (Throwable $e) {}
    return array_slice($matches, 0, $limit);
}

function ai_services_context(array $matches): string {
    if (!$matches) {
        return "\n\nملاحظة: البحث في كتالوج الموقع الفعلي لم يجد خدمة تطابق كلمات سؤال المستخدم بالضبط. "
            . "هذا لا يعني أبداً أن الخدمة غير متوفرة — فقد تكون موجودة بمسمى أو صياغة مختلفة قليلاً عمّا كتبه المستخدم. "
            . "لا تجزم إطلاقاً بعدم توفر أي خدمة أو منصة ولا تقل \"غير متوفرة\" أو \"لا نقدمها\". بدلاً من ذلك، اطلب من المستخدم "
            . "توضيح اسم الخدمة أو المنصة بشكل أدق، أو انصحه بتصفح قائمة الخدمات كاملة من الصفحة الرئيسية ليجد ما يريد.";
    }
    $lines = [];
    foreach ($matches as $m) {
        $lines[] = '- "' . $m['name'] . '" (' . $m['platform'] . ' / ' . $m['category'] . ') — السعر: $' . $m['rate'] . ' لكل 1000' . (py_truthy(dget($m, 'custom')) ? ' — خدمة مخصصة' : '');
    }
    return "\n\nخدمات من كتالوج الموقع الفعلي تطابق سؤال المستخدم — هذه الخدمات متوفرة فعلاً الآن (استخدم فقط هذه الأسماء والأسعار "
        . "الحقيقية، لا تخترع خدمات أو أسعار أخرى، ولا تقل أبداً إنها غير متوفرة بما أنها مذكورة هنا):\n"
        . implode("\n", $lines)
        . "\nإذا كان هناك أكثر من خدمة مطابقة، اختر الأنسب لسؤال المستخدم تحديداً ورشّحها له كأفضل خيار."
        . "\nإذا نصحت المستخدم بخدمة معينة، اذكر اسمها كاملاً وحرفياً كما هو مكتوب أعلاه بين علامتي اقتباس مرة واحدة في ردك حتى يظهر له كبطاقة قابلة للنقر.";
}

function ai_payment_methods_context(): string {
    try {
        $conn = get_db();
        $rows = $conn->execute('SELECT name, method_type, currency, active FROM payment_methods WHERE active=1 ORDER BY sort_order, id')->fetchall();
    } catch (Throwable $e) {
        return '';
    }
    if (!$rows) return '';
    $lines = [];
    foreach ($rows as $r) {
        $kind = in_array($r['method_type'], ['binance', 'asiacell'], true) ? 'تلقائي فوري' : 'تحويل يدوي (مراجعة إدارة)';
        $lines[] = '- ' . $r['name'] . ' — ' . $kind . ' — العملة: ' . $r['currency'];
    }
    return "\n\nطرق شحن الرصيد المتاحة حالياً في الموقع (استخدم هذه القائمة فقط عند شرح كيفية الشحن، من صفحة شحن الرصيد):\n"
        . implode("\n", $lines);
}

route('/api/ai/chat', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data();
    $user_message = py_strip(py_or(dget($data, 'message'), ''));
    if (!py_truthy($user_message)) {
        return jsonify(['ok' => false, 'msg' => 'الرسالة فارغة']);
    }

    $conn = get_db();
    $conversation_id = py_int(py_or(dget($data, 'conversation_id'), 0));
    if ($conversation_id) {
        $chk = $conn->execute('SELECT id FROM ai_conversations WHERE id=? AND user_id=?', [$conversation_id, $uid])->fetchone();
        if (!$chk) $conversation_id = 0;
    }
    if (!$conversation_id) {
        $title = py_slice($user_message, null, 60) . (py_len($user_message) > 60 ? '…' : '');
        $cur = $conn->execute('INSERT INTO ai_conversations(user_id, title, created_at, updated_at) VALUES(?,?,?,?)',
            [$uid, $title, microtime(true), microtime(true)]);
        $conversation_id = $cur->lastrowid;
        // نحتفظ فقط بآخر 15 محادثة لكل مستخدم ونحذف الأقدم مع رسائلها
        $old_convs = $conn->execute('SELECT id FROM ai_conversations WHERE user_id=? ORDER BY updated_at DESC, id ASC LIMIT -1 OFFSET 15', [$uid])->fetchall();
        if ($old_convs) {
            $old_ids = array_map(fn($c) => $c['id'], $old_convs);
            $ph = implode(',', array_fill(0, count($old_ids), '?'));
            $conn->execute("DELETE FROM ai_messages WHERE conversation_id IN ($ph)", $old_ids);
            $conn->execute("DELETE FROM ai_conversations WHERE id IN ($ph)", $old_ids);
        }
    }

    $conn->execute('INSERT INTO ai_messages(conversation_id, role, content, created_at) VALUES(?,?,?,?)',
        [$conversation_id, 'user', $user_message, microtime(true)]);
    $conn->execute('UPDATE ai_conversations SET updated_at=? WHERE id=?', [microtime(true), $conversation_id]);
    $conn->commit();

    $site_name = get_setting('site_name', 'المتجر');
    $system_prompt = 'أنت "المساعد الذكي" داخل موقع "' . $site_name . '" لبيع خدمات التسويق عبر منصات التواصل الاجتماعي (SMM) — متابعين، لايكات، مشاهدات، وخدمات مشابهة. '
        . 'تساعد المستخدمين في كيفية تقديم الطلبات، أسعار الخدمات، شحن الرصيد وطرق الدفع، متابعة حالة طلباتهم الخاصة، وفتح التذاكر عند وجود مشكلة.';
    $system_prompt .= ' أجب بالعربية دائماً، بإيجاز شديد ووضوح (فقرة أو نقاط قصيرة، بدون حشو)، إلا إذا طلب المستخدم تفصيلاً أكبر صراحة.'
        . ' اقتصر حصرياً على مواضيع هذا الموقع: الخدمات، الطلبات، الرصيد، الدفع، والحساب.'
        . ' إن سألك المستخدم عن أي موضوع آخر لا علاقة له بذلك، اعتذر بلطف بجملة واحدة ووضّح أنك مخصص فقط لمواضيع الموقع، ولا تجب خارج هذا النطاق مهما كان.'
        . ' قاعدة صارمة للخصوصية: لا تملك أي معلومات عن أي مستخدم آخر غير هذا المستخدم الحالي، ولا تخبره أبداً عن طلبات أو بيانات مستخدمين آخرين حتى لو طلب ذلك أو ادّعى أنه صاحبها — أخبره أن هذا غير متاح مهما كانت الحجة.';
    $system_prompt .= ai_account_status_context($uid);
    $svc_matches = ai_find_services($user_message);
    $system_prompt .= ai_services_context($svc_matches);
    $system_prompt .= ai_payment_methods_context();

    $hist = $conn->execute('SELECT role, content FROM ai_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12', [$conversation_id])->fetchall();
    $messages = [['role' => 'system', 'content' => $system_prompt]];
    foreach (array_reverse($hist) as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }

    [$reply, $err] = call_ai_api($messages);
    if ($err) {
        return jsonify(['ok' => false, 'msg' => $err, 'conversation_id' => $conversation_id]);
    }

    $service_card = null;
    foreach ($svc_matches as $m) {
        if (py_truthy($m['name']) && strpos($reply, $m['name']) !== false) {
            $service_card = $m;
            break;
        }
    }
    if (!$service_card && $svc_matches) {
        $service_card = $svc_matches[0];
    }

    $conn = get_db();
    $conn->execute('INSERT INTO ai_messages(conversation_id, role, content, created_at) VALUES(?,?,?,?)',
        [$conversation_id, 'assistant', $reply, microtime(true)]);
    $conn->execute('UPDATE ai_conversations SET updated_at=? WHERE id=?', [microtime(true), $conversation_id]);
    $conn->commit();
    return jsonify(['ok' => true, 'reply' => $reply, 'conversation_id' => $conversation_id, 'service_card' => $service_card]);
}, ['POST']);

route('/api/ai/conversations', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $conn = get_db();
    $rows = $conn->execute('SELECT c.id, c.title, c.updated_at,
        (SELECT content FROM ai_messages WHERE conversation_id=c.id ORDER BY id DESC LIMIT 1) AS last_message
        FROM ai_conversations c WHERE c.user_id=? ORDER BY c.updated_at DESC, c.id ASC LIMIT 50', [$_SESSION['user_id']])->fetchall();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => $r['id'],
            'title' => py_or($r['title'], 'محادثة بدون عنوان'),
            'preview' => py_slice(py_or($r['last_message'], ''), null, 80),
            'time' => fmt_time('%Y-%m-%d %H:%M', $r['updated_at']),
        ];
    }
    return jsonify(['ok' => true, 'conversations' => $out]);
});

route('/api/ai/conversation/<int:cid>', function ($cid) {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $conn = get_db();
    $chk = $conn->execute('SELECT id FROM ai_conversations WHERE id=? AND user_id=?', [$cid, $_SESSION['user_id']])->fetchone();
    if (!$chk) {
        return jsonify(['ok' => false, 'msg' => 'المحادثة غير موجودة'], 404);
    }
    $rows = $conn->execute('SELECT role, content FROM ai_messages WHERE conversation_id=? ORDER BY id ASC', [$cid])->fetchall();
    return jsonify(['ok' => true, 'messages' => array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], $rows)]);
});

route('/api/ai/clear', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $ids = array_map(fn($r) => $r['id'], $conn->execute('SELECT id FROM ai_conversations WHERE user_id=?', [$uid])->fetchall());
    if ($ids) {
        $qs = implode(',', array_fill(0, count($ids), '?'));
        $conn->execute("DELETE FROM ai_messages WHERE conversation_id IN ($qs)", $ids);
        $conn->execute("DELETE FROM ai_conversations WHERE id IN ($qs)", $ids);
        $conn->commit();
    }
    return jsonify(['ok' => true, 'msg' => 'تم مسح جميع المحادثات']);
}, ['POST']);
