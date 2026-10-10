<?php

declare(strict_types=1);

use danog\MadelineProto\API;
use TelegramMirror\App;

require_once dirname(__DIR__) . '/src/App.php';

try {
    App::boot();
    if (!App::acquireLock()) {
        throw new RuntimeException('Worker hoặc login đang chạy. Dừng worker và Cron Job trước khi đăng nhập.');
    }
    $api = new API(App::path('telegram.session'), App::settings());
    $api->start();
    $binding = App::validateAccount($api);
    App::initialize($api, $binding);
    fwrite(STDOUT, 'Đăng nhập và kiểm tra thành công.' . PHP_EOL);
    fwrite(STDOUT, 'Nguồn: ' . $binding['source_id'] . ' → Đích: ' . $binding['target_id'] . PHP_EOL);
    if (App::queue()->getMeta('source_min_id') !== null) {
        fwrite(STDOUT, 'Mốc bài cũ đã lưu: ' . App::queue()->getMeta('source_min_id') . PHP_EOL);
    } else {
        fwrite(STDOUT, 'Mốc bài mới sẽ được lưu khi worker hoạt động lần đầu.' . PHP_EOL);
    }
    if ($binding['source_protected']) {
        fwrite(STDOUT, 'Channel nguồn đang bảo vệ nội dung; worker sẽ bỏ qua bài bị chặn chuyển tiếp.' . PHP_EOL);
    }
    fwrite(STDOUT, 'Chạy php bin/worker.php để nhận và chuyển tiếp bài mới.' . PHP_EOL);
} catch (Throwable $error) {
    App::fail($error);
}
