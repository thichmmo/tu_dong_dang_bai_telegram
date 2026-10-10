<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$failed = false;
$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failed): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    $failed = $failed || !$ok;
};

$check('PHP CLI', PHP_VERSION_ID >= 80300, PHP_VERSION . ' — cần PHP 8.3 trở lên');
$check('Số nguyên 64-bit', PHP_INT_SIZE === 8, (PHP_INT_SIZE * 8) . '-bit');
foreach (['dom', 'fileinfo', 'filter', 'hash', 'json', 'xml', 'zlib', 'mbstring', 'openssl', 'gmp', 'iconv', 'gd', 'tokenizer', 'pdo', 'pdo_sqlite'] as $extension) {
    $check('Extension ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'Có' : 'Thiếu');
}
$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
foreach (['stream_socket_client', 'stream_socket_server', 'proc_open', 'flock'] as $function) {
    $ok = function_exists($function) && !in_array($function, $disabled, true);
    $check('Hàm ' . $function, $ok, $ok ? 'Có thể dùng' : 'Bị chặn hoặc không có');
}
$check('Composer dependencies', is_file($root . '/vendor/autoload.php'), 'Chạy composer install --no-dev --prefer-dist nếu chưa cài');
$check('Cấu hình riêng', is_file($root . '/config.php'), 'Sao chép config.example.php thành config.php và điền API ID/hash');

$storage = $root . '/storage';
$check('Quyền ghi thư mục', is_dir($storage) ? is_writable($storage) : is_writable($root), 'Ứng dụng cần ghi session, hàng đợi và log');
$path = str_replace('\\', '/', realpath($root) ?: $root);
$check('Thư mục ứng dụng riêng', !preg_match('~/(public_html|htdocs)(/|$)~i', $path), 'Đặt code/session ngoài public_html; chỉ public/ làm document root');

if (in_array('--network', $argv, true)) {
    $socket = function_exists('stream_socket_client')
        ? @stream_socket_client('tcp://149.154.167.50:443', $code, $reason, 5)
        : false;
    $check('Kết nối Telegram TCP', is_resource($socket), is_resource($socket) ? 'Kết nối được' : 'Không kết nối được; hỏi hosting về kết nối outbound');
    if (is_resource($socket)) {
        fclose($socket);
    }
}

foreach ($checks as $item) {
    echo ($item['ok'] ? '[OK] ' : '[THIẾU] ') . $item['name'] . ': ' . $item['detail'] . PHP_EOL;
}
echo PHP_EOL . 'Cron chỉ khởi động lại worker. Hosting phải cho phép tiến trình PHP chạy liên tục.' . PHP_EOL;
echo 'Các kiểm tra này chưa xác nhận chính sách giới hạn tiến trình/RAM của nhà cung cấp.' . PHP_EOL;
exit($failed ? 1 : 0);
