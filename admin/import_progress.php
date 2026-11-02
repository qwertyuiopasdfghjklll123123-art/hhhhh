<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

header('Content-Type: application/json; charset=utf-8');

$progress = db_import_progress_get($pdo);
if (!$progress) {
    echo json_encode(['status' => 'idle', 'current_step' => 0, 'total_steps' => 0, 'label' => null, 'message' => null]);
    exit;
}
echo json_encode($progress, JSON_UNESCAPED_UNICODE);
