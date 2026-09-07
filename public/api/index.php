<?php
/**
 * نقطة الدخول الوحيدة للـ API — كل الطلبات تمرّ من هنا (عبر .htaccess)
 */

require __DIR__ . '/src/autoload.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// يحسب المسار المطلوب من REQUEST_URI مباشرة (يعمل تحت Apache وتحت خادم PHP المدمج للتطوير معاً)
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($scriptDir !== '/' && strpos($requestPath, $scriptDir) === 0) {
    $requestPath = substr($requestPath, strlen($scriptDir));
}
$route = '/' . trim($requestPath, '/');
$method = $_SERVER['REQUEST_METHOD'];

$routes = require __DIR__ . '/src/routes.php';

foreach ($routes as $definition) {
    list($routeMethod, $pattern, $handler, $opts) = $definition;
    if ($routeMethod !== $method) {
        continue;
    }
    $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#u';
    if (!preg_match($regex, $route, $matches)) {
        continue;
    }
    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    foreach ($params as $key => $value) {
        $params[$key] = urldecode($value);
    }

    try {
        $user = null;
        if (!empty($opts['auth'])) {
            $user = Auth::requireAuth();
            if (!empty($opts['admin'])) {
                Auth::requireAdmin($user);
            }
        } elseif (!empty($opts['optionalAuth'])) {
            $user = Auth::optionalAuth();
        }

        $body = [];
        if (in_array($method, ['POST', 'PUT'], true)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        call_user_func($handler, $params, $body, $user);
    } catch (ApiException $e) {
        Response::error($e->getMessage(), $e->statusCode);
    } catch (Throwable $e) {
        error_log('[smart-elearning-api] ' . $e->getMessage());
        Response::error('حدث خطأ غير متوقع في الخادم: ' . $e->getMessage(), 500);
    }
    exit;
}

Response::error('المسار المطلوب غير موجود', 404);
