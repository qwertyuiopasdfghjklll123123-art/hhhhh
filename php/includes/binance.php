<?php
/** تحقق من عملية Binance Pay عبر API الحساب التجاري (مكافئ verify_binance_order) */
function currency_equivalent($a, $b) {
    $a = strtoupper(trim($a));
    $b = strtoupper(trim($b));
    if ($a === $b) return true;
    $usdGroup = ['USD', 'USDT'];
    return in_array($a, $usdGroup) && in_array($b, $usdGroup);
}

/** @return array{0:bool,1:string|array} [verified, message-or-transaction-row] */
function verify_binance_order($pm, $orderId, $expectedAmountUsd) {
    $extras = json_decode($pm['method_extras'] ?? '{}', true) ?: [];
    $apiKey = trim($extras['api_key'] ?? '');
    $apiSecret = trim($extras['api_secret'] ?? '');
    if (!$apiKey || !$apiSecret) {
        return [false, 'لم يتم إعداد مفاتيح Binance من الإدارة بعد.'];
    }
    $orderId = trim($orderId);
    if (!$orderId) {
        return [false, 'الرجاء إدخال رقم عملية Binance (Order ID).'];
    }

    $nowMs = (int)(microtime(true) * 1000);
    $params = [
        'timestamp' => $nowMs,
        'status' => '1',
        'startTime' => $nowMs - 7 * 24 * 60 * 60 * 1000,
        'endTime' => $nowMs,
    ];
    $queryString = http_build_query($params, '', '&');
    $signature = hash_hmac('sha256', $queryString, $apiSecret);
    $url = "https://api3.binance.com/sapi/v1/pay/transactions?$queryString&signature=$signature";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["X-MBX-APIKEY: $apiKey"],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return [false, 'تعذر الاتصال بـ Binance.'];
    }
    $data = json_decode($resp, true);
    $rows = is_array($data) ? ($data['data'] ?? null) : null;
    if (!is_array($rows)) {
        return [false, 'استجابة غير متوقعة من Binance. تأكد من صحة مفاتيح API.'];
    }

    $found = null;
    foreach ($rows as $row) {
        if ((string)($row['orderId'] ?? '') === $orderId) { $found = $row; break; }
    }
    if (!$found) {
        return [false, 'لم يتم العثور على عملية دفع بهذا الرقم خلال آخر 7 أيام.'];
    }

    $ccy = (string)($found['currency'] ?? '');
    if (!currency_equivalent('USD', $ccy)) {
        return [false, 'عملة العملية غير مطابقة (' . strtoupper($ccy) . ').'];
    }

    $amount = (float)($found['amount'] ?? 0);
    if (abs($amount - $expectedAmountUsd) >= 0.0001) {
        return [false, sprintf('المبلغ غير مطابق. المتوقع $%.2f والمستلم $%.2f.', $expectedAmountUsd, $amount)];
    }

    return [true, $found];
}
