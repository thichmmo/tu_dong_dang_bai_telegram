<?php

declare(strict_types=1);

use TelegramMirror\Control;

require dirname(__DIR__) . '/src/Control.php';

$assertions = 0;
function check(bool $condition, string $description): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
}
function same(mixed $expected, mixed $actual, string $description): void
{
    check($expected === $actual, $description);
}
function rejects(callable $operation, string $description): void
{
    try { $operation(); }
    catch (InvalidArgumentException | RuntimeException) { check(true, $description); return; }
    check(false, $description);
}
function wipe(string $directory): void
{
    if (!is_dir($directory)) { return; }
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $path = $directory . '/' . $name;
        if (!is_link($path) && is_dir($path)) { wipe($path); }
        else { unlink($path); }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/telegram-control-' . bin2hex(random_bytes(8));
try {
    rejects(fn() => new Control('relative-storage'), 'Relative storage rejected');
    rejects(fn() => new Control($directory . '/public_html/private'), 'Webroot storage rejected');
    rejects(fn() => new Control($directory . '/../other'), 'Traversal storage rejected');
    rejects(fn() => new Control($directory . "\0bad"), 'NUL storage rejected');
    $control = new Control($directory);
    same(null, $control->identity(), 'Fresh installation has no admin identity');
    same(['version' => 1, 'revision' => 0, 'routes' => []], $control->routes(), 'Empty route document starts at revision zero');
    $sessionDirectory = $control->sessionDirectory();
    same($directory . '/admin-sessions', $sessionDirectory, 'Session files use isolated private directory');

    $token = $control->createSetupToken();
    check(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 'Setup token has 256 random bits');
    $setupFile = $directory . '/admin-setup.json';
    $setup = json_decode((string) file_get_contents($setupFile), true);
    same(hash('sha256', $token), $setup['token_hash'], 'Only setup token hash is persisted');
    check(!str_contains((string) file_get_contents($setupFile), $token), 'Plaintext token is absent from disk');
    check($control->setupAvailable($token), 'Fresh setup token is available');
    $grant = $control->setupGrant($token);
    same(['token_hash', 'expires_at'], array_keys($grant), 'Private session grant contains hash and deadline only');
    check(!str_contains(json_encode($grant), $token), 'Private session grant does not persist raw invitation');
    check($control->grantAvailable($grant), 'Verified setup grant is usable');
    $invalidGrant = $grant; $invalidGrant['token_hash'] = str_repeat('0', 64);
    check(!$control->grantAvailable($invalidGrant), 'Invented setup grant rejected');
    check(!$control->setupAvailable(str_repeat('0', 64)), 'Wrong token rejected without consuming real token');
    rejects(fn() => $control->setup($token, 'ab', 'Synthetic admin password 42'), 'Short username rejected');
    rejects(fn() => $control->setup($token, 'AdminUser', 'short'), 'Short password rejected');
    rejects(fn() => $control->setup($token, 'AdminUser', str_repeat('A', 9)), 'Nine-character password rejected at minimum boundary');
    rejects(fn() => $control->setup($token, 'AdminUser', str_repeat('ệ', 9)), 'Nine UTF-8 characters rejected even when byte length exceeds ten');
    rejects(fn() => $control->setup($token, 'AdminUser', str_repeat('a', 73)), 'Bcrypt byte truncation prevented');
    rejects(fn() => $control->setup($token, 'AdminUser', str_repeat('ệ', 25)), 'UTF-8 byte limit prevents bcrypt truncation');
    check($control->setupAvailable($token), 'Invalid account fields do not consume invitation');
    $rotated = $control->createSetupToken();
    check($rotated !== $token, 'Rotating invitation creates a new token');
    check(!$control->setupAvailable($token), 'Rotated old token is invalid');
    check(!$control->grantAvailable($grant), 'Rotation revokes previously verified private session grant');
    $setup = json_decode((string) file_get_contents($setupFile), true);
    $setup['expires_at'] = time() - 1;
    file_put_contents($setupFile, json_encode($setup));
    check(!$control->setupAvailable($rotated), 'Expired invitation fails');
    rejects(fn() => $control->setup($rotated, 'AdminUser', 'Synthetic admin password 42'), 'Expired invitation cannot create account');
    $token = $control->createSetupToken();
    $grant = $control->setupGrant($token);
    $password = str_repeat('A', 10);
    $identity = $control->setupFromGrant($grant, 'AdminUser', $password);
    same(['username', 'auth_epoch'], array_keys($identity), 'Public identity has no password hash');
    same('AdminUser', $identity['username'], 'Admin username is retained');
    same($identity, $control->identity(), 'Admin identity survives new reads');
    check(!file_exists($setupFile), 'Invitation hash file removed after setup');
    check(!$control->setupAvailable($token), 'Consumed invitation is unavailable');
    check(!$control->grantAvailable($grant), 'Consumed private session grant is unavailable');
    rejects(fn() => $control->setupFromGrant($grant, 'AnotherUser', 'Another synthetic password 42'), 'Private session grant cannot replace account');
    rejects(fn() => $control->setup($token, 'AnotherUser', 'Another synthetic password 42'), 'Invitation cannot replace account');
    rejects(fn() => $control->createSetupToken(), 'Invitation minting refused after account exists');
    $record = json_decode((string) file_get_contents($directory . '/admin.json'), true);
    check(!str_contains((string) file_get_contents($directory . '/admin.json'), $password), 'Admin password plaintext is absent from persisted record');
    check(password_verify($password, $record['password_hash']), 'Exactly ten-character setup password is accepted and stored with verifiable password_hash');
    same($identity, $control->authenticate('adminuser', $password, 'synthetic-client-a'), 'Valid case-insensitive login returns only identity');
    same(null, $control->authenticate('AdminUser', 'wrong-password-value', 'synthetic-client-b'), 'Wrong password denied');
    same(null, $control->authenticate('MissingUser', $password, 'synthetic-client-c'), 'Wrong username denied with a valid password');
    same(null, $control->authenticate('AdminUser', $password . str_repeat('x', 80), 'synthetic-client-d'), 'Oversized login password is never truncated');
    for ($i = 0; $i < 5; ++$i) {
        same(null, $control->authenticate('AdminUser', 'wrong-password-value', 'synthetic-client-throttle'), 'Failed login recorded ' . $i);
    }
    $second = new Control($directory);
    same(null, $second->authenticate('AdminUser', $password, 'synthetic-client-throttle'), 'Rate limit survives Control instance and blocks even correct password');
    same($identity, $second->authenticate('AdminUser', $password, 'synthetic-client-unblocked'), 'Independent client remains usable');
    $attempts = json_decode((string) file_get_contents($directory . '/admin-attempts.json'), true);
    check(!str_contains((string) file_get_contents($directory . '/admin-attempts.json'), 'synthetic-client-throttle'), 'Client identifiers are hashed before persisting');
    check(!str_contains((string) file_get_contents($directory . '/admin-attempts.json'), 'wrong-password-value'), 'Attempts never contain submitted password');
    $attempts['global'] = array_fill(0, 100, time());
    $attempts['clients'] = [];
    file_put_contents($directory . '/admin-attempts.json', json_encode($attempts));
    same(null, $second->authenticate('AdminUser', $password, 'new-client'), 'Global limit caps clients that rotate identifiers');
    $attempts['global'] = array_fill(0, 100, time() - 901);
    file_put_contents($directory . '/admin-attempts.json', json_encode($attempts));
    same($identity, $second->authenticate('AdminUser', $password, 'new-client'), 'Expired failed attempts do not lock out forever');
    $attemptsBackup = file_get_contents($directory . '/admin-attempts.json');
    file_put_contents($directory . '/admin-attempts.json', '{}');
    rejects(fn() => $second->authenticate('AdminUser', $password, 'new-client'), 'Malformed attempts fail closed instead of resetting throttle');
    file_put_contents($directory . '/admin-attempts.json', $attemptsBackup);

    $session = [];
    $csrf = Control::csrfToken($session);
    same($csrf, Control::csrfToken($session), 'Same live session retains CSRF token');
    check(Control::validCsrf($session, $csrf), 'Live CSRF token accepted');
    check(!Control::validCsrf($session, str_repeat('0', 64)), 'Wrong CSRF rejected');
    check(!Control::validCsrf($session, [$csrf]), 'Array CSRF rejected');
    check(!Control::validCsrf([], $csrf), 'Missing session CSRF rejected');
    $renewedSession = [];
    check(Control::csrfToken($renewedSession) !== $csrf, 'New session gets distinct token');

    same('@first_source', Control::normalizePeer(' @FIRST_source '), 'Username case canonicalized');
    same('@first_source', Control::normalizePeer('https://t.me/FIRST_source/'), 'Public channel link canonicalized');
    same('-1001234567890', Control::normalizePeer(-1001234567890), 'Channel numeric IDs retain full decimal string');
    foreach (['http://t.me/first_source', 'https://t.me/+privateinvite', 'https://t.me/first_source/42', '@tiny', '@user<script>', '-1000000000000', '-1009999999999999999999999', [], 'https://attacker.example/first_source'] as $invalid) {
        rejects(fn() => Control::normalizePeer($invalid), 'Invalid channel reference rejected');
    }
    $routes = [['source' => '@FIRST_source', 'targets' => ['@FIRST_target', '-1002234567890'], 'enabled' => true]];
    $saved = $control->saveRoutes(0, $routes);
    same(1, $saved['revision'], 'Saving increments revision');
    same('@first_source', $saved['routes'][0]['source'], 'Saved source normalized');
    same(['@first_target', '-1002234567890'], $saved['routes'][0]['targets'], 'Saved target ordering preserved');
    same($saved, $second->routes(), 'Atomic route file is readable by independent instance');
    rejects(fn() => $second->saveRoutes(0, []), 'Stale revision cannot overwrite newer configuration');
    same($saved, $control->routes(), 'Rejected stale edit leaves configuration unchanged');
    $paused = $saved['routes'];
    $paused[0]['enabled'] = false;
    $saved = $second->saveRoutes(1, $paused);
    same(false, $saved['routes'][0]['enabled'], 'Disable persists without deleting source or targets');
    same(2, $saved['revision'], 'Disable increments revision');
    $deleted = $control->saveRoutes(2, []);
    same([], $deleted['routes'], 'Removing all sources supported');
    same(3, $deleted['revision'], 'Delete increments revision');
    rejects(fn() => $control->saveRoutes(-1, []), 'Negative revision rejected');
    rejects(fn() => Control::validateRoutes([['source' => '@first_source', 'targets' => ['@first_target'], 'enabled' => 'true']]), 'Non-boolean enabled rejected');
    rejects(fn() => Control::validateRoutes([['source' => '@first_source', 'targets' => [], 'enabled' => true]]), 'Empty destination list rejected');
    rejects(fn() => Control::validateRoutes([['source' => '@first_source', 'targets' => ['@FIRST_target', 'https://t.me/first_target'], 'enabled' => true]]), 'Target aliases in same route deduplicated');
    rejects(fn() => Control::validateRoutes([['source' => '@first_source', 'targets' => ['@FIRST_source'], 'enabled' => false]]), 'Source cannot target itself even if disabled');
    rejects(fn() => Control::validateRoutes([
        ['source' => '@first_source', 'targets' => ['@first_target'], 'enabled' => true],
        ['source' => 'https://t.me/FIRST_source', 'targets' => ['@second_target'], 'enabled' => false],
    ]), 'Duplicate normalized sources cannot hide in disabled route');
    $cross = [
        ['source' => '@first_source', 'targets' => ['@second_source'], 'enabled' => true],
        ['source' => '@second_source', 'targets' => ['@first_target'], 'enabled' => true],
    ];
    rejects(fn() => Control::validateRoutes($cross), 'Active source cannot be an active destination');
    $cross[1]['enabled'] = false;
    same(false, Control::validateRoutes($cross)[1]['enabled'], 'Disabled source may be target of another active route');
    $many = [];
    for ($i = 0; $i < 21; ++$i) { $many[] = ['source' => '@source_' . $i, 'targets' => ['@first_target'], 'enabled' => true]; }
    rejects(fn() => Control::validateRoutes($many), 'Twenty-source bound enforced');
    $targets = [];
    for ($i = 0; $i < 11; ++$i) { $targets[] = '@target_' . $i; }
    rejects(fn() => Control::validateRoutes([['source' => '@first_source', 'targets' => $targets, 'enabled' => true]]), 'Ten-target bound enforced');
    rejects(fn() => Control::validateRoutes(['not-a-list' => $routes[0]]), 'Route document requires an ordered list');

    mkdir($directory . '/deploy', 0700);
    $live = ['generated_at' => time(), 'ready' => true, 'worker_alive' => true, 'paused' => false,
        'metadata' => ['worker_heartbeat_at' => (string) time(), 'routes_revision' => '3', 'api_hash' => 'synthetic-secret'],
        'error' => 'sensitive-error', 'sources' => [-1001234567890 => ['reference' => '@first_source', 'ready' => true]]];
    file_put_contents($directory . '/deploy/live-status.json', json_encode($live));
    chmod($directory . '/deploy/live-status.json', 0600);
    $safeStatus = $control->status();
    same(['fresh', 'running', 'needs_login', 'revision', 'config_rejected', 'routes'], array_keys($safeStatus), 'Status exports only finite keys');
    check($safeStatus['running'], 'Fresh live worker recognized');
    same(3, $safeStatus['revision'], 'Applied revision parsed from finite numeric field');
    same(['@first_source' => true], $safeStatus['routes'], 'Readiness contains only normalized configured refs');
    check(!str_contains(json_encode($safeStatus), 'synthetic-secret') && !str_contains(json_encode($safeStatus), 'sensitive-error'), 'Raw errors and metadata never exported');
    $live['metadata']['route_config_error'] = 'sensitive-loop-error';
    $live['unresolved_sources'] = ['@unknown_source' => 'sensitive-access-error'];
    file_put_contents($directory . '/deploy/live-status.json', json_encode($live));
    $safeStatus = $control->status();
    check($safeStatus['config_rejected'], 'Rejected configuration exports finite flag');
    same(false, $safeStatus['routes']['@unknown_source'], 'Unresolved channel exports only normalized reference and readiness');
    check(!str_contains(json_encode($safeStatus), 'sensitive-loop-error') && !str_contains(json_encode($safeStatus), 'sensitive-access-error'), 'Rejection flags and unresolved refs never expose Telegram errors');
    $live['generated_at'] = time() - 61;
    file_put_contents($directory . '/deploy/live-status.json', json_encode($live));
    check(!$control->status()['running'], 'Stale live report is not running');
    $live['generated_at'] = time(); $live['paused'] = true;
    file_put_contents($directory . '/deploy/live-status.json', json_encode($live));
    check(!$control->status()['running'], 'Durable pause takes precedence over ready');

    foreach (['AUTH_KEY_DUPLICATED', 'AUTH_KEY_UNREGISTERED', 'SESSION_REVOKED', 'SESSION_EXPIRED', 'AUTH_KEY_INVALID'] as $code) {
        $live['metadata']['paused_reason'] = 'STARTUP: Telegram returned ' . $code . ' sensitive-auth-detail';
        file_put_contents($directory . '/deploy/live-status.json', json_encode($live));
        $safeStatus = $control->status();
        check($safeStatus['needs_login'] && !$safeStatus['running'], 'Revoked authorization requires private login: ' . $code);
        same(false, $safeStatus['routes']['@first_source'], 'Authorization failure overrides contradictory route readiness');
        check(!str_contains(json_encode($safeStatus), $code) && !str_contains(json_encode($safeStatus), 'sensitive-auth-detail'),
            'Authorization status exposes only a finite flag');
    }
    foreach (['CHAT_WRITE_FORBIDDEN', 'SOURCE_ACCESS: CHANNEL_PRIVATE', 'AUTH_KEY_INVALID_SUFFIX', ['AUTH_KEY_INVALID']] as $reason) {
        check(!Control::needsTelegramLogin(['metadata' => ['paused_reason' => $reason]]), 'Non-auth pauses and malformed reasons do not request login');
    }
    check(Control::needsTelegramLogin(['metadata' => ['startup_error' => 'RPC error (SESSION_REVOKED)']]), 'Startup authorization failure is recognized');
    check(!Control::needsTelegramLogin(['metadata' => ['api_hash' => 'AUTH_KEY_INVALID']]), 'Unrelated private fields cannot trigger auth diagnosis');
    check(Control::needsTelegramLogin(['needs_login' => true]), 'Explicit finite status flag is accepted');
    check(!Control::needsTelegramLogin(['needs_login' => 'true', 'metadata' => 'malformed']), 'Malformed metadata and non-boolean status flag are ignored');
    check(!Control::needsTelegramLogin(['metadata' => ['paused_reason' => str_repeat('x', 1024) . ' AUTH_KEY_INVALID']]),
        'Exception inspection has a fixed byte limit');
    file_put_contents($directory . '/deploy/live-status.json', json_encode(['generated_at' => time(), 'metadata' => 'malformed']));
    $malformedStatus = $control->status();
    check(!$malformedStatus['needs_login'] && !$malformedStatus['running'], 'Malformed report metadata remains unknown without an exception');
    $live['generated_at'] = time() - 61;
    file_put_contents($directory . '/deploy/live-status.json', json_encode($live));
    $safeStatus = $control->status();
    check($safeStatus['needs_login'] && !$safeStatus['fresh'] && !$safeStatus['running'], 'Last known auth failure remains distinct from a fresh health claim');

    $publicRoot = $directory . '/public-fixture';
    mkdir($publicRoot . '/storage/deploy', 0700, true);
    $previousRoot = getenv('TELEGRAM_MIRROR_ROOT');
    putenv('TELEGRAM_MIRROR_ROOT=' . $publicRoot);
    $renderIndex = static function (): string {
        ob_start();
        try { require dirname(__DIR__) . '/public/index.php'; return (string) ob_get_clean(); }
        catch (Throwable $error) { ob_end_clean(); throw $error; }
    };
    try {
        file_put_contents($publicRoot . '/storage/deploy/live-status.json', json_encode($live));
        $html = $renderIndex();
        check(str_contains($html, 'Phiên Telegram đã mất hiệu lực, cần đăng nhập lại'), 'Public page gives the finite recovery hint');
        check(str_contains($html, 'Lần cập nhật gần nhất ghi nhận:'), 'Stale auth diagnosis is explicitly last known');
        check(!str_contains($html, 'sensitive-auth-detail') && !str_contains($html, 'synthetic-secret')
            && !str_contains($html, 'AUTH_KEY_INVALID'), 'Public hint never renders private exception data');
        check(!str_contains($html, 'class="status active"') && !str_contains($html, 'name="password"'), 'Auth failure shows no healthy badge or Telegram credential form');
        $live['generated_at'] = time(); $live['paused'] = false;
        $live['metadata']['paused_reason'] = ''; $live['all_targets_ready'] = true;
        file_put_contents($publicRoot . '/storage/deploy/live-status.json', json_encode($live));
        $html = $renderIndex();
        check(str_contains($html, 'class="status active"') && !str_contains($html, 'Phiên Telegram đã mất hiệu lực'),
            'A healthy replacement report clears the recovery hint');
        file_put_contents($publicRoot . '/storage/deploy/live-status.json', '{broken');
        $html = $renderIndex();
        check(!str_contains($html, 'class="status active"') && !str_contains($html, 'Phiên Telegram đã mất hiệu lực'),
            'Invalid status JSON remains unknown without inventing an auth cause');
    } finally { putenv($previousRoot === false ? 'TELEGRAM_MIRROR_ROOT' : 'TELEGRAM_MIRROR_ROOT=' . $previousRoot); }

    file_put_contents($directory . '/routes.json', '{broken');
    rejects(fn() => $control->routes(), 'Corrupt route configuration fails closed');
    rejects(fn() => $control->saveRoutes(3, []), 'Corrupt existing configuration is not silently replaced');
    file_put_contents($directory . '/routes.json', json_encode($deleted));
    if (PHP_OS_FAMILY !== 'Unknown') {
        chmod($directory . '/routes.json', 0644);
        rejects(fn() => $control->routes(), 'World-readable routes rejected');
        chmod($directory . '/routes.json', 0600);
    }
    if (function_exists('symlink') && @symlink($directory . '/admin.json', $directory . '/symlink-state')) {
        rejects(fn() => new Control($directory . '/symlink-state'), 'Symlink storage rejected');
        unlink($directory . '/routes.json');
        symlink($directory . '/admin.json', $directory . '/routes.json');
        rejects(fn() => $control->routes(), 'Symlink state file rejected before reading');
        rejects(fn() => $control->saveRoutes(3, []), 'Symlink state file rejected before writing');
    }
    clearstatcache();
    same(0700, fileperms($directory) & 0777, 'Private storage mode is 0700');
    same(0700, fileperms($sessionDirectory) & 0777, 'Private session directory mode is 0700');
    same(0600, fileperms($directory . '/admin.json') & 0777, 'Admin identity/password record mode is 0600');
    same(0600, fileperms($directory . '/admin-attempts.json') & 0777, 'Durable failed attempts mode is 0600');
    same(0600, fileperms($directory . '/admin.lock') & 0777, 'Auth lock file mode is 0600');
    same(0600, fileperms($directory . '/routes.lock') & 0777, 'Route lock file mode is 0600');
    echo "Control: $assertions assertions passed.\n";
} finally { wipe($directory); }
