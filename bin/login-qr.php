<?php

declare(strict_types=1);

namespace TelegramMirror\LoginQr;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use RuntimeException;
use TelegramMirror\App;
use TelegramMirror\StartupValidationException;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/src/App.php';

/** Replace a private artifact without exposing partial files to API readers. */
function writePrivateAtomically(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    try {
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
            || !chmod($temporary, 0600)
            || !rename($temporary, $path)) {
            throw new RuntimeException('Cannot save private QR login artifact.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/** State contains no phone number, account ID, API hash or login token. */
function writeState(string $path, string $status, int $expiresAt = 0, ?string $failureCode = null): void
{
    $state = [
        'status' => $status,
        'expires_at' => $expiresAt,
        'updated_at' => time(),
    ];
    if ($status === 'failed' && $failureCode !== null) {
        $state['failure_code'] = $failureCode;
    }
    writePrivateAtomically($path, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
}

/** Emit a finite repair hint, never arbitrary exception messages or values. */
function safeFailureCode(Throwable $error): string
{
    if ($error instanceof StartupValidationException) {
        $message = $error->getMessage();
        foreach ([
            'tham gia channel nguồn' => 'source_join_required',
            'Post Messages' => 'target_post_permission_required',
            'không dùng bot token' => 'wrong_account_type',
            'tài khoản/cặp channel khác' => 'storage_binding_mismatch',
            'channel phát tin' => 'invalid_channel',
            'phải khác nhau' => 'invalid_channel',
        ] as $fragment => $code) {
            if (str_contains($message, $fragment)) {
                return $code;
            }
        }
    }
    if ($error instanceof RPCErrorException && in_array($error->rpc, [
        'CHAT_ADMIN_REQUIRED', 'CHAT_WRITE_FORBIDDEN', 'CHAT_RESTRICTED',
        'USER_BANNED_IN_CHANNEL', 'CHANNEL_PRIVATE', 'CHANNEL_INVALID',
        'PEER_ID_INVALID', 'CHAT_ID_INVALID', 'USERNAME_INVALID',
        'USERNAME_NOT_OCCUPIED', 'API_ID_INVALID', 'AUTH_KEY_INVALID',
        'AUTH_KEY_UNREGISTERED', 'AUTH_KEY_DUPLICATED', 'SESSION_REVOKED',
        'SESSION_EXPIRED', 'SESSION_PASSWORD_NEEDED', 'USER_DEACTIVATED',
        'USER_DEACTIVATED_BAN', 'FROZEN_METHOD_INVALID', 'BOT_METHOD_INVALID',
        'PEER_FLOOD',
    ], true)) {
        return 'rpc_' . $error->rpc;
    }
    return 'login_failed';
}

function removeQrImages(): void
{
    foreach (['login-qr.svg', 'login-qr.png', 'login-qr.png.b64'] as $filename) {
        $path = App::path($filename);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

$deadline = microtime(true) + 300.0;
$statePath = null;
$ownsLock = false;
try {
    App::boot();
    if (!App::acquireLock()) {
        // Do not alter the state/images of an existing login or worker.
        fwrite(STDERR, 'Another login or worker owns the session; stop it first.' . PHP_EOL);
        exit(1);
    }
    $ownsLock = true;
    $statePath = App::path('login-qr-state.json');
    removeQrImages();
    writeState($statePath, 'starting');
    $settings = App::settings();
    // Always retain this lock owner's session in the foreground. The legacy
    // --full-instance option is still accepted, but is no longer required.
    $hadRestartFlag = array_key_exists('MadelineSelfRestart', $_GET);
    $oldRestartFlag = $_GET['MadelineSelfRestart'] ?? null;
    $_GET['MadelineSelfRestart'] = 1;
    try {
        $api = new API(App::path('telegram.session'), $settings);
    } finally {
        if ($hadRestartFlag) { $_GET['MadelineSelfRestart'] = $oldRestartFlag; }
        else { unset($_GET['MadelineSelfRestart']); }
    }
    // Existing IPC connections queue constructor settings asynchronously.
    // Apply them before requesting a QR so the selected transport is active.
    $api->updateSettings($settings);
    $nextQr = null;

    while (true) {
        $authorization = $api->getAuthorization();
        if ($authorization === API::LOGGED_IN) {
            // Refresh identity before permission checks and persistent binding.
            $api->fullGetSelf();
            $binding = App::validateAccount($api);
            App::initialize($api, $binding);
            removeQrImages();
            writeState($statePath, 'success');
            fwrite(STDOUT, 'QR login and channel validation succeeded. Worker is not started.' . PHP_EOL);
            exit(0);
        }
        if ($authorization === API::WAITING_PASSWORD) {
            removeQrImages();
            writeState($statePath, 'needs_2fa');
            fwrite(STDOUT, 'Two-step verification required. Complete it with bin/login.php in Terminal.' . PHP_EOL);
            exit(2);
        }
        if ($authorization !== API::NOT_LOGGED_IN) {
            throw new RuntimeException('Existing login state requires interactive Terminal login.');
        }
        if (microtime(true) >= $deadline) {
            removeQrImages();
            writeState($statePath, 'expired');
            fwrite(STDOUT, 'QR login window expired.' . PHP_EOL);
            exit(2);
        }

        // start() reads stdin; qrLogin() supports a headless cron process.
        $qr = $nextQr ?? $api->qrLogin();
        $nextQr = null;
        if ($qr === null) {
            continue; // Distinguish logged-in and pending 2FA on the next turn.
        }
        writePrivateAtomically(App::path('login-qr.svg'), $qr->getQRSvg(400, 4));
        if (extension_loaded('gd') && class_exists(GDLibRenderer::class)) {
            try {
                $png = (new Writer(new GDLibRenderer(480, 4)))->writeString($qr->link);
                writePrivateAtomically(App::path('login-qr.png'), $png);
                // Fileman get_file_content returns UTF-8 text. This private
                // companion lets the authenticated deployer fetch PNG bytes.
                writePrivateAtomically(App::path('login-qr.png.b64'), base64_encode($png));
            } catch (Throwable) {
                // SVG remains usable. Never print the link or render exception.
                foreach (['login-qr.png', 'login-qr.png.b64'] as $filename) {
                    $pngPath = App::path($filename);
                    if (is_file($pngPath)) {
                        unlink($pngPath);
                    }
                }
            }
        }
        writeState($statePath, 'waiting_qr', $qr->expiry);
        try {
            $nextQr = $qr->waitForLoginOrQrCodeExpiration(
                new TimeoutCancellation(max(0.001, $deadline - microtime(true))),
            );
        } catch (CancelledException) {
            removeQrImages();
            if (microtime(true) >= $deadline) {
                writeState($statePath, 'expired');
                fwrite(STDOUT, 'QR login window expired.' . PHP_EOL);
                exit(2);
            }
            writeState($statePath, 'failed', 0, 'rpc_timeout');
            fwrite(STDERR, 'Telegram connection timed out before the QR login window ended.' . PHP_EOL);
            exit(1);
        }
        // The helper refreshes an expired QR automatically; the next iteration
        // publishes that refreshed code or completes account validation.
    }
} catch (Throwable $error) {
    if ($ownsLock && $statePath !== null) {
        try {
            removeQrImages();
            writeState($statePath, 'failed', 0, safeFailureCode($error));
        } catch (Throwable) {
        }
    }
    // Full API exceptions may contain private values; do not print them here.
    fwrite(STDERR, 'QR login failed. Check the private MadelineProto log or use bin/login.php in Terminal.' . PHP_EOL);
    exit(1);
}
