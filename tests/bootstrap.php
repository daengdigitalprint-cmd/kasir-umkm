<?php

$configCache = __DIR__.'/../bootstrap/cache/config.php';

if (is_file($configCache)) {
    throw new RuntimeException(
        'Config cache terdeteksi. Jalankan "php artisan config:clear" sebelum menjalankan test agar database aplikasi tidak disentuh.'
    );
}

require __DIR__.'/../vendor/autoload.php';
