<?php
declare(strict_types=1);

use TelegramMirror\App;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/App.php';
umask(0077);
$id = $argv[1] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { exit(1); }

function cleanupReport(string $id, string $status): void
{
    $path = App::path('login-2fa-cleanup-' . $id . '.json');
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    $data = json_encode(['request_id' => $id, 'status' => $status, 'updated_at' => time()], JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data)
        || !chmod($temporary, 0600) || !rename($temporary, $path)) {
        if (is_file($temporary)) { unlink($temporary); }
        throw new RuntimeException('Cannot publish cleanup state.');
    }
}

/** Read only bounded regular metadata; truthy strings are not cancellation. */
function cleanupRequest(string $id): ?array
{
    $path = App::path('login-2fa-request.json');
    if (is_link($path) || !is_file($path) || filesize($path) > 65536) { return null; }
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 65536) { return null; }
    $request = json_decode($raw, true);
    return is_array($request) && ($request['request_id'] ?? null) === $id
        && is_int($request['expires_at'] ?? null) && ($request['cancelled'] ?? null) === true
        ? $request : null;
}

try {
    App::boot(false);
    if (!App::acquireLock()) { cleanupReport($id, 'busy'); exit(3); }
    $request = cleanupRequest($id);
    if ($request === null) {
        cleanupReport($id, 'conflict'); exit(1);
    }
    $envelopePath = App::path('login-2fa-envelope.json');
    if (is_link($envelopePath)) { cleanupReport($id, 'conflict'); exit(1); }
    if (is_file($envelopePath)) {
        if (filesize($envelopePath) > 65536) { cleanupReport($id, 'conflict'); exit(1); }
        $envelope = json_decode((string) file_get_contents($envelopePath), true);
        if (!is_array($envelope) || ($envelope['request_id'] ?? null) !== $id) {
            cleanupReport($id, 'conflict'); exit(1);
        }
        $currentRequest = cleanupRequest($id);
        if ($currentRequest === null || $currentRequest['expires_at'] !== $request['expires_at']) {
            cleanupReport($id, 'conflict'); exit(1);
        }
        if (!unlink($envelopePath)) { throw new RuntimeException('Cannot consume retired artifact.'); }
    }
    $currentRequest = cleanupRequest($id);
    if ($currentRequest === null || $currentRequest['expires_at'] !== $request['expires_at']) {
        cleanupReport($id, 'conflict'); exit(1);
    }
    cleanupReport($id, ($request['retired_status'] ?? '') === 'expired' ? 'expired' : 'cancelled');
    fwrite(STDOUT, "Private request retired; session and queue preserved.\n");
} catch (Throwable) {
    // No secret values, exception arguments, paths or trace in logs.
    fwrite(STDERR, "Private request cleanup did not finish.\n");
    exit(1);
}
