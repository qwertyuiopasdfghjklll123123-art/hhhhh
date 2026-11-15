<?php
/** ينشئ الجداول من sql/database.sql وحساب الأدمن من config.php (آمن عند التكرار). */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../core/bootstrap.php';
init_db();
Conn::shutdown();
echo "تمام.\n";
