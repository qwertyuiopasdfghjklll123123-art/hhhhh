<?php
/**
 * نقطة تحميل موحّدة: يجب تضمينها في بداية كل صفحة محمية بالنظام.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

start_session();
