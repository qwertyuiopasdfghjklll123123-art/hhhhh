<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_unset();
session_destroy();
// يمرّ عبر الموقع الرئيسي (force_logout=1) بدل الذهاب مباشرة لصفحة الدخول القديمة، حتى
// يُفرَّغ localStorage الخاص بالمتصفح هناك أيضاً (لا يمكن لهذه الصفحة PHP لمسه مباشرة)
header('Location: ../index.php?force_logout=1');
exit;
