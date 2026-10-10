<?php
declare(strict_types=1);

// Run in a private terminal. This link permits the first administrator setup.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require_once dirname(__DIR__) . '/src/Control.php';

try {
    $configuration = require dirname(__DIR__) . '/config.php';
    $storage = $configuration['storage_dir'] ?? dirname(__DIR__) . '/storage';
    $control = new \TelegramMirror\Control($storage);
    $token = $control->createSetupToken(900);
    $origin = $argv[1] ?? 'https://thucunghaiza.site';
    if (!preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]+)?$~D', $origin)) {
        throw new RuntimeException('Dùng HTTPS origin, ví dụ https://example.com.');
    }
    fwrite(STDOUT, 'Link thiết lập quản trị, dùng một lần trong 15 phút:' . PHP_EOL
        . $origin . '/admin.php?setup=' . rawurlencode($token) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Không tạo được link thiết lập: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
