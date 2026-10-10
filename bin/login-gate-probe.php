<?php
declare(strict_types=1);

use TelegramMirror\App;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/App.php';
umask(0077);
$nonce = $argv[1] ?? '';
if (!is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) { exit(1); }

function probeSnapshot(string $name): array
{
    $path = App::path($name);
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) { return ['exists' => false, 'raw' => null]; }
    if (is_link($path) || !is_file($path) || filesize($path) > 65536) {
        throw new RuntimeException('Unsafe login metadata.');
    }
    $raw = file_get_contents($path, false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) { throw new RuntimeException('Unsafe login metadata.'); }
    return ['exists' => true, 'raw' => $raw];
}

function probeInputSafe(array $request, array $state): bool
{
    if (!$request['exists'] && !$state['exists']) { return true; }
    if (!$request['exists'] || !$state['exists']) { return false; }
    $requestValue = json_decode($request['raw'], false, 8, JSON_THROW_ON_ERROR);
    $stateValue = json_decode($state['raw'], false, 8, JSON_THROW_ON_ERROR);
    if (!$requestValue instanceof stdClass || get_object_vars($requestValue) !== []) { return false; }
    if (!$stateValue instanceof stdClass) { return false; }
    $stateFields = get_object_vars($stateValue);
    return $stateFields === [] || $stateFields === ['status' => 'input'];
}

function probeReport(string $nonce, bool $lockFree, bool $inputSafe): void
{
    $path = App::path('login-gate-probe-' . $nonce . '.json');
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    $raw = json_encode(['request_id' => $nonce, 'lock_free' => $lockFree,
        'input_safe' => $inputSafe, 'checked_at' => time()], JSON_THROW_ON_ERROR);
    try {
        if (file_put_contents($temporary, $raw, LOCK_EX) !== strlen($raw)
            || !chmod($temporary, 0600) || !rename($temporary, $path)) {
            throw new RuntimeException('Cannot publish login gate proof.');
        }
    } finally { if (is_file($temporary)) { unlink($temporary); } }
}

$lockFree = false;
try {
    App::boot(false);
    if (!App::acquireLock()) { probeReport($nonce, false, false); exit(3); }
    $lockFree = true;
    $before = [probeSnapshot('login-2fa-request.json'), probeSnapshot('login-2fa-state.json')];
    $envelope = App::path('login-2fa-envelope.json');
    clearstatcache(true, $envelope);
    $safe = !file_exists($envelope) && !is_link($envelope) && probeInputSafe(...$before);
    $after = [probeSnapshot('login-2fa-request.json'), probeSnapshot('login-2fa-state.json')];
    clearstatcache(true, $envelope);
    $safe = $safe && $before === $after && !file_exists($envelope) && !is_link($envelope);
    probeReport($nonce, true, $safe);
    exit($safe ? 0 : 1);
} catch (Throwable) {
    try { probeReport($nonce, $lockFree, false); } catch (Throwable) {}
    fwrite(STDERR, "Login gate proof did not complete.\n");
    exit(1);
}
