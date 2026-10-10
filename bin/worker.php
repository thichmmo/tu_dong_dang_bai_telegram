<?php

declare(strict_types=1);

use danog\MadelineProto\API;
use TelegramMirror\App;
use TelegramMirror\MirrorHandler;
use TelegramMirror\StartupValidationException;

require_once dirname(__DIR__) . '/src/App.php';

$booted = false;
$ownsLock = false;
try {
    App::boot();
    $booted = true;
    if (!App::acquireLock()) {
        // A healthy worker already owns this session. Cron duplicates exit quietly.
        exit(0);
    }
    $ownsLock = true;
    // Only the session lock owner may replace the PID used by status.php.
    App::queue()->setMeta('worker_pid', (string) getmypid());
    App::queue()->setMeta('worker_ready', '0');
    App::queue()->setMeta('worker_heartbeat_at', '0');
    App::requireInitialized();
    // Restored session handlers may wake up during the API constructor.
    // Only this initialized session lock owner may process those events.
    App::enableWorkerEvents();
    $settings = App::settings();
    // startAndLoop calls the library's interactive start() before onStart.
    // An unattended worker must use an already authenticated session.
    // MadelineProto 8.7 reads this hook when opening the session. Keep the
    // worker in this process so an IPC helper cannot consume channel updates.
    $hadFullFlag = array_key_exists('MadelineSelfRestart', $_GET);
    $oldFullFlag = $_GET['MadelineSelfRestart'] ?? null;
    $_GET['MadelineSelfRestart'] = 1;
    try {
        $api = new API(App::path('telegram.session'), $settings);
        if ($api->getAuthorization() !== API::LOGGED_IN) {
            throw new StartupValidationException('Session chưa đăng nhập. Chạy php bin/login.php trong Terminal.');
        }
        unset($api);
        fwrite(STDOUT, 'Worker bắt đầu. Log: ' . App::path('madeline.log') . PHP_EOL);
        MirrorHandler::startAndLoop(App::path('telegram.session'), $settings);
    } finally {
        if ($hadFullFlag) { $_GET['MadelineSelfRestart'] = $oldFullFlag; }
        else { unset($_GET['MadelineSelfRestart']); }
    }
} catch (Throwable $error) {
    if ($booted && $ownsLock) {
        App::startupFailed($error);
    }
    App::fail($error);
}
