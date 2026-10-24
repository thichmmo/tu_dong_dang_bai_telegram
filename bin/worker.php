<?php

declare(strict_types=1);

use danog\MadelineProto\API;
use Revolt\EventLoop;
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
    $sessionGuard = null;
    try {
        $api = new API(App::path('telegram.session'), $settings);
        if ($api->getAuthorization() !== API::LOGGED_IN) {
            throw new StartupValidationException('Session chưa đăng nhập. Chạy php bin/login.php trong Terminal.');
        }
        // A paused handler can still leave the library loop alive. Invalidated
        // keys require a new login; terminate without another Telegram call.
        $sessionGuard = EventLoop::repeat(3.0, static function () use (&$sessionGuard): void {
            $reason = App::queue()->getMeta('paused_reason') ?? '';
            if (!preg_match('/(?:^|[^A-Z0-9_])(AUTH_KEY_INVALID|AUTH_KEY_UNREGISTERED|AUTH_KEY_DUPLICATED|SESSION_REVOKED|SESSION_EXPIRED|SESSION_PASSWORD_NEEDED)(?:$|[^A-Z0-9_])/', $reason, $match)) { return; }
            App::queue()->setMeta('worker_ready', '0');
            App::queue()->setMeta('worker_heartbeat_at', '0');
            App::queue()->setMeta('startup_retry_at', '0');
            App::disableWorkerEvents();
            if ($sessionGuard !== null) { EventLoop::cancel($sessionGuard); $sessionGuard = null; }
            // PHP shutdown closes this owner's session. The persistent pause
            // prevents unattended Cron retries from opening the invalid key.
            App::fail(new RuntimeException('Session Telegram không còn hiệu lực (' . $match[1]
                . '). Cần đăng nhập lại để tiếp tục.'));
        });
        EventLoop::unreference($sessionGuard);
        fwrite(STDOUT, 'Worker bắt đầu. Log: ' . App::path('madeline.log') . PHP_EOL);
        // Reuse the authorized full instance instead of opening the same
        // session a second time through EventHandler::startAndLoop().
        API::startAndLoopMulti([$api], MirrorHandler::class);
    } finally {
        if ($sessionGuard !== null) { EventLoop::cancel($sessionGuard); }
        unset($api);
        API::finalize();
        if ($hadFullFlag) { $_GET['MadelineSelfRestart'] = $oldFullFlag; }
        else { unset($_GET['MadelineSelfRestart']); }
    }
} catch (Throwable $error) {
    if ($booted && $ownsLock) {
        App::startupFailed($error);
    }
    App::fail($error);
}
