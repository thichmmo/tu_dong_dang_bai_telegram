<?php

declare(strict_types=1);

namespace TelegramMirror\LoginTwoFactor;

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

final class InvalidEnvelope extends RuntimeException {}
final class ExpiredEnvelope extends RuntimeException {}
final class CancelledEnvelope extends RuntimeException {}

/** A stale or cancelled request cannot send credentials or publish new state. */
function assertCurrentRequest(string $requestId, ?int $expiresAt = null, bool $allowCancelled = false): void
{
    $request = json_decode(readPrivateJson(App::path('login-2fa-request.json')), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request) || ($request['request_id'] ?? null) !== $requestId
        || !is_int($request['expires_at'] ?? null)
        || ($expiresAt !== null && $request['expires_at'] !== $expiresAt)
        || (array_key_exists('cancelled', $request) && !is_bool($request['cancelled']))) {
        throw new InvalidEnvelope('Private login request is no longer current.');
    }
    if (($request['cancelled'] ?? false) && !$allowCancelled) {
        throw new CancelledEnvelope('Private login request was cancelled.');
    }
}

/** Bound each RPC by the remaining request lifetime and the outer deadline. */
function remainingRpcTimeout(int $expiresAt, float $deadline): int
{
    $remaining = min((float) $expiresAt, $deadline) - microtime(true);
    if ($remaining <= 0.0) {
        throw new ExpiredEnvelope('Private login request expired.');
    }
    // MadelineProto 8.7.0 accepts integer RPC drop timeouts, not a
    // cancellation argument on complete2faLogin(). Recheck after that call.
    return max(1, min(30, (int) floor($remaining)));
}

/** JSON artifacts are private and never contain a password or Telegram key. */
function writeState(string $requestId, string $status, ?string $failureCode = null): void
{
    assertCurrentRequest($requestId, null, $status === 'cancelled');
    $state = ['request_id' => $requestId, 'status' => $status, 'updated_at' => time()];
    if ($failureCode !== null) {
        $state['failure_code'] = $failureCode;
    }
    $path = App::path('login-2fa-state.json');
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    $content = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    try {
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
            || !chmod($temporary, 0600)) {
            throw new RuntimeException('Cannot write private login state.');
        }
        // Recheck after preparing the artifact so an older request cannot
        // overwrite a newer request's state at publication time.
        assertCurrentRequest($requestId, null, $status === 'cancelled');
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Cannot write private login state.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function readPrivateJson(string $path): string
{
    if (!is_file($path) || is_link($path) || filesize($path) > 65536) {
        throw new InvalidEnvelope('Invalid private login artifact.');
    }
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 65536) {
        throw new InvalidEnvelope('Invalid private login artifact.');
    }
    return $raw;
}

/** Decode only the fixed-length or bounded binary fields in this protocol. */
function decodeBinary(mixed $value, int $minimum, int $maximum): string
{
    if (!is_string($value) || strlen($value) > (int) ceil($maximum / 3) * 4) {
        throw new InvalidEnvelope('Invalid encrypted envelope.');
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < $minimum || strlen($decoded) > $maximum) {
        throw new InvalidEnvelope('Invalid encrypted envelope.');
    }
    return $decoded;
}

/** Consume only a matching artifact, leaving a newer request untouched. */
function consumeOwnEnvelope(string $path, string $raw): void
{
    if (is_link($path) || !is_file($path)) {
        throw new InvalidEnvelope('Login envelope changed before consumption.');
    }
    $current = file_get_contents($path);
    if ($current === false || !hash_equals($raw, $current) || !unlink($path)) {
        throw new InvalidEnvelope('Login envelope changed before consumption.');
    }
}

/** Exact authenticated metadata is request_id + "|" + decimal expires_at. */
function decryptPassword(array $envelope, string $apiHash, string $requestId, int $expiresAt): string
{
    $salt = decodeBinary($envelope['salt'] ?? null, 16, 16);
    $nonce = decodeBinary($envelope['nonce'] ?? null, 12, 12);
    $tag = decodeBinary($envelope['tag'] ?? null, 16, 16);
    $ciphertext = decodeBinary($envelope['ciphertext'] ?? null, 1, 32768);
    $key = hash_hkdf('sha256', $apiHash, 32, 'telegram-mirror-2fa-v1', $salt);
    $plaintext = @openssl_decrypt(
        $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        $nonce, $tag, $requestId . '|' . $expiresAt,
    );
    unset($key);
    if ($plaintext === false) {
        throw new InvalidEnvelope('Encrypted login envelope could not be authenticated.');
    }
    try {
        $payload = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['request_id'] ?? null) !== $requestId
            || ($payload['expires_at'] ?? null) !== $expiresAt
            || !is_string($payload['password'] ?? null)
            || $payload['password'] === '' || strlen($payload['password']) > 4096) {
            throw new InvalidEnvelope('Invalid encrypted login payload.');
        }
        return $payload['password'];
    } finally {
        unset($plaintext, $payload);
    }
}

/** Emit a finite repair hint rather than an exception message or argument. */
function safeFailureCode(Throwable $error): string
{
    if ($error instanceof CancelledEnvelope) {
        return 'cancelled';
    }
    if ($error instanceof InvalidEnvelope) {
        return 'invalid_envelope';
    }
    if ($error instanceof StartupValidationException) {
        foreach ([
            'tham gia channel nguồn' => 'source_join_required',
            'Post Messages' => 'target_post_permission_required',
            'không dùng bot token' => 'wrong_account_type',
            'tài khoản/cặp channel khác' => 'storage_binding_mismatch',
            'channel phát tin' => 'invalid_channel',
            'phải khác nhau' => 'invalid_channel',
        ] as $fragment => $code) {
            if (str_contains($error->getMessage(), $fragment)) {
                return $code;
            }
        }
    }
    if ($error instanceof RPCErrorException) {
        if ($error->rpc === 'PASSWORD_HASH_INVALID') {
            return 'wrong_password';
        }
        if (in_array($error->rpc, [
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
    }
    return 'login_failed';
}

$requestId = $argv[1] ?? '';
if (!is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
    fwrite(STDERR, 'A valid private login request ID is required.' . PHP_EOL);
    exit(1);
}

$ownsLock = false;
$password = null;
$deadline = microtime(true) + 300.0;
try {
    App::boot();
    if (!App::acquireLock()) {
        fwrite(STDERR, 'Another login or worker owns the session.' . PHP_EOL);
        exit(1);
    }
    $ownsLock = true;
    $request = json_decode(readPrivateJson(App::path('login-2fa-request.json')), true, 8, JSON_THROW_ON_ERROR);
    $envelopePath = App::path('login-2fa-envelope.json');
    $raw = readPrivateJson($envelopePath);
    $envelope = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_array($envelope)
        || ($request['request_id'] ?? null) !== $requestId
        || ($envelope['version'] ?? null) !== 1
        || ($envelope['request_id'] ?? null) !== $requestId
        || !is_int($envelope['expires_at'] ?? null)
        || ($request['expires_at'] ?? null) !== $envelope['expires_at']) {
        throw new InvalidEnvelope('Private login request does not match.');
    }
    $expiresAt = $envelope['expires_at'];
    consumeOwnEnvelope($envelopePath, $raw);
    unset($raw, $request);
    if ($expiresAt <= time()) {
        throw new ExpiredEnvelope('Private login request expired.');
    }
    if ($expiresAt > time() + 180) {
        throw new InvalidEnvelope('Private login request lifetime exceeds the bound.');
    }
    assertCurrentRequest($requestId, $expiresAt);
    $password = decryptPassword($envelope, App::config()['api_hash'], $requestId, $expiresAt);
    unset($envelope);
    writeState($requestId, 'processing');
    if (time() >= $expiresAt || microtime(true) >= $deadline) {
        throw new ExpiredEnvelope('Private login request expired.');
    }
    $settings = App::settings();
    $settings->getRpc()->setRpcDropTimeout(remainingRpcTimeout($expiresAt, $deadline));
    // Full-instance mode is the pinned 8.7.0 CLI self-restart hook, scoped only
    // to initialization. It avoids detached IPC during this one-time login.
    $hadRestartFlag = array_key_exists('MadelineSelfRestart', $_GET);
    $oldRestartFlag = $_GET['MadelineSelfRestart'] ?? null;
    $_GET['MadelineSelfRestart'] = 1;
    try {
        $api = new API(App::path('telegram.session'), $settings);
    } finally {
        if ($hadRestartFlag) { $_GET['MadelineSelfRestart'] = $oldRestartFlag; }
        else { unset($_GET['MadelineSelfRestart']); }
    }
    $api->updateSettings($settings);
    if (time() >= $expiresAt || microtime(true) >= $deadline) {
        throw new ExpiredEnvelope('Private login request expired.');
    }
    $authorization = $api->getAuthorization();
    if ($authorization === API::WAITING_PASSWORD) {
        // Looking up authorization may use up the envelope's remaining TTL.
        // Apply the smaller timeout, then check again directly before sending.
        $settings->getRpc()->setRpcDropTimeout(remainingRpcTimeout($expiresAt, $deadline));
        $api->updateSettings($settings);
        try {
            assertCurrentRequest($requestId, $expiresAt);
            remainingRpcTimeout($expiresAt, $deadline);
            $api->complete2faLogin($password);
        } finally {
            unset($password);
        }
    } elseif ($authorization === API::LOGGED_IN) {
        unset($password);
    } else {
        throw new RuntimeException('This session is not waiting for two-step verification.');
    }
    // A late authorization is retained in the session, but an expired
    // request cannot initialize the app or report success. Revalidate later.
    assertCurrentRequest($requestId, $expiresAt);
    remainingRpcTimeout($expiresAt, $deadline);
    $api->fullGetSelf();
    remainingRpcTimeout($expiresAt, $deadline);
    $binding = App::validateAccount($api);
    assertCurrentRequest($requestId, $expiresAt);
    remainingRpcTimeout($expiresAt, $deadline);
    App::initialize($api, $binding);
    remainingRpcTimeout($expiresAt, $deadline);
    writeState($requestId, 'success');
    fwrite(STDOUT, 'Two-step login and channel validation succeeded. Worker is not started.' . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    unset($password, $envelope, $raw);
    if ($ownsLock) {
        try {
            $status = $error instanceof CancelledEnvelope ? 'cancelled'
                : ($error instanceof ExpiredEnvelope ? 'expired' : 'failed');
            writeState($requestId, $status, $status === 'failed' ? safeFailureCode($error) : null);
        } catch (CancelledEnvelope) {
            // Cancellation may race an error's first state publication.
            try { writeState($requestId, 'cancelled'); } catch (Throwable) {}
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'Two-step login did not finish; inspect the private status artifact.' . PHP_EOL);
    exit($error instanceof ExpiredEnvelope || $error instanceof CancelledEnvelope ? 2 : 1);
}
