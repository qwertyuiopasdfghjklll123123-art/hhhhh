<?php

// محمّل تلقائي بسيط بدون Composer: يبحث عن اسم الصنف كملف داخل src/ أو src/controllers أو src/services
spl_autoload_register(function ($class) {
    $dirs = [__DIR__ . '/', __DIR__ . '/controllers/', __DIR__ . '/services/'];
    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
