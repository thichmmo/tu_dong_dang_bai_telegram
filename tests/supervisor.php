<?php

declare(strict_types=1);

use TelegramMirror\Queue;
use TelegramMirror\WorkerSupervisor;

require_once dirname(__DIR__) . '/src/WorkerSupervisor.php';
$assertions = 0;
function supervisorCheck(bool $condition, string $description): void
{
    global $assertions; ++$assertions;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
}
function supervisorSame(mixed $expected, mixed $actual, string $description): void
{
    supervisorCheck($expected === $actual, $description . '; expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true));
}
function supervisorReject(callable $operation, string $description): void
{
    try { $operation(); } catch (Throwable) { supervisorCheck(true, $description); return; }
    supervisorCheck(false, $description);
}
function supervisorRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { supervisorRemove($path . '/' . $entry); } }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) { unlink($path); }
}
$now = 1800000000;
$metadata = ['initialized_at' => '1700000000', 'validated_at' => '1700000001',
    'source_id' => '-1001234567890', 'target_id' => '-1002234567890', 'account_id' => '123456',
    'worker_pid' => '1234', 'worker_heartbeat_at' => (string)($now - 1), 'paused_reason' => '',
    'startup_error' => '', 'last_history_error' => '', 'route_config_error' => ''];
$source = ['source_enabled' => '1', 'history_required' => '1', 'last_history_checked_at' => (string)($now - 10),
    'last_history_error' => '', 'paused_reason' => ''];
$snapshot = ['metadata' => $metadata, 'sources' => [$source]];
$owner = ['pid' => 1234];
$oldState = ['first_seen_at' => $now - 1000];
$policy = static fn(array $s, ?array $o = null, array $state = []): array => WorkerSupervisor::decision($s, $o, $state, $now);
supervisorSame('healthy', $policy($snapshot, $owner, $oldState)['action'], 'Recent heartbeat and history need no recovery');
supervisorSame('start', $policy($snapshot)['action'], 'Absent initialized worker can start');
supervisorSame('startup_grace', $policy($snapshot, $owner)['reason'], 'Newly observed owner has a bounded startup grace');
supervisorSame('recovery_cooldown', $policy($snapshot, $owner, $oldState + ['last_recovery_at' => $now - 10])['reason'], 'Recent recovery prevents restart loops');
$stalled = $snapshot; $stalled['metadata']['worker_heartbeat_at'] = (string)($now - 1000);
supervisorSame('heartbeat_stalled', $policy($stalled, $owner, $oldState)['reason'], 'Stalled event loop triggers recovery after grace');
$staleHistory = $snapshot; $staleHistory['sources'][0]['last_history_checked_at'] = (string)($now - 1000);
supervisorSame('history_stalled', $policy($staleHistory, $owner, $oldState)['reason'], 'Fresh event loop cannot mask a frozen history reader');
$staleHistory['sources'][0]['last_history_error'] = 'HISTORY_SCAN_TIMEOUT';
supervisorSame('restart', $policy($staleHistory, $owner, $oldState)['action'], 'A bounded transient history timeout is recoverable');
$capitalizedTimeout = $staleHistory; $capitalizedTimeout['sources'][0]['last_history_error'] = 'Timeout while waiting for messages.getHistory';
supervisorSame('restart', $policy($capitalizedTimeout, $owner, $oldState)['action'], 'Native Amp capitalized Timeout message remains in the transient allowlist');
$localBackoff = $staleHistory; $localBackoff['sources'][0]['history_retry_at'] = (string)($now + 300);
supervisorSame('restart', $policy($localBackoff, $owner, $oldState)['action'], 'Repeated stale transient failures can recover despite a renewed source-local history backoff');
$localBackoff['metadata']['global_forward_retry_at'] = (string)($now + 300);
supervisorSame('retry_wait', $policy($localBackoff, $owner, $oldState)['reason'], 'Real account-wide FloodWait still prevents stale-history recovery');
$disabled = $staleHistory; $disabled['sources'][0]['source_enabled'] = '0';
supervisorSame('healthy', $policy($disabled, $owner, $oldState)['action'], 'Inactive sources do not force restart');
$future = $snapshot; $future['metadata']['worker_heartbeat_at'] = (string)($now + 1);
supervisorSame('clock_invalid', $policy($future, $owner, $oldState)['reason'], 'Future heartbeat cannot grant a restart');
$future = $snapshot; $future['sources'][0]['last_history_checked_at'] = (string)($now + 1);
supervisorSame('clock_invalid', $policy($future, $owner, $oldState)['reason'], 'Future history timestamp cannot grant a restart');
foreach (['AUTH_KEY_DUPLICATED', 'AUTH_KEY_UNREGISTERED', 'SESSION_REVOKED', 'SESSION_EXPIRED', 'SESSION_PASSWORD_NEEDED', 'USER_DEACTIVATED'] as $fatal) {
    $s = $stalled; $s['metadata']['paused_reason'] = $fatal;
    supervisorSame('needs_login', $policy($s, $owner, $oldState)['reason'], $fatal . ' cannot be repaired by restart');
    supervisorSame('blocked', $policy($s)['action'], $fatal . ' also prevents starting an absent worker');
}
foreach (['USER_PAUSE', 'SOURCE_ACCESS: CHANNEL_PRIVATE', 'REPOST_RECONCILIATION_REQUIRED', 'CHAT_WRITE_FORBIDDEN'] as $pause) {
    $s = $stalled; $s['sources'][0]['paused_reason'] = $pause;
    supervisorSame('persistent_pause', $policy($s, $owner, $oldState)['reason'], 'Supervisor preserves source/manual pause ' . $pause);
}
$s = $stalled; $s['sources'][0]['target_pause_-1002234567890'] = 'REPOST_CAPTION_RECONCILIATION_REQUIRED';
supervisorSame('persistent_pause', $policy($s, $owner, $oldState)['reason'], 'Target reconciliation pause is never bypassed');
foreach (['startup_retry_at', 'global_forward_retry_at', 'forward_retry_at', 'history_retry_at'] as $key) {
    $s = $stalled; $s['metadata'][$key] = (string)($now + 300);
    supervisorSame('retry_wait', $policy($s, $owner, $oldState)['reason'], $key . ' backoff prevents recovery');
}
foreach (['HISTORY_SCAN_LIMIT_REACHED', 'HISTORY_COMMIT_ACTIVATION_CHANGED', 'MEDIA_CAPTION_TOO_LONG'] as $reason) {
    $s = $stalled; $s['sources'][0]['last_history_error'] = $reason;
    supervisorSame('history_repair_required', $policy($s, $owner, $oldState)['reason'], 'Deterministic error ' . $reason . ' is not a transient restart');
}
foreach (['initialized_at', 'validated_at', 'account_id', 'source_id', 'target_id'] as $key) {
    $s = $snapshot; unset($s['metadata'][$key]);
    supervisorSame('not_initialized', $policy($s)['reason'], 'Missing ' . $key . ' prevents an unattended start');
}

// Native fixture exercises the actual /proc generation, flock FD, Unix listener,
// read-only metadata snapshot, orphan discovery and stop-before-spawn ordering.
// Its stopper uses a private local control file; it never sends Telegram traffic.
$fixture = null; $process = null; $pipes = []; $queue = null;
try {
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/self/stat') && function_exists('proc_open') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        require_once dirname(__DIR__) . '/src/Queue.php';
        // Linux Unix sockets have a short sockaddr_un path limit. Deployment
        // candidates are deeply nested, so use a canonical private temp root.
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($temporaryRoot === false) { throw new RuntimeException('Native fixture temporary root unavailable'); }
        $fixture = rtrim($temporaryRoot, '/') . '/tg-sup-' . bin2hex(random_bytes(6));
        if (strlen($fixture . '/storage/telegram.session/ipc') > 100) {
            throw new RuntimeException('Native fixture Unix socket path too long');
        }
        foreach ([$fixture, $fixture . '/bin', $fixture . '/storage', $fixture . '/storage/deploy', $fixture . '/storage/telegram.session'] as $path) { mkdir($path, 0700); }
        $workerSource = <<<'PHP'
<?php
umask(0077);
$store = dirname(__DIR__) . '/storage';
$lock = fopen($store . '/process.lock', 'ce');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(2); }
$socket = stream_socket_server('unix://' . $store . '/telegram.session/ipc', $error, $message);
if (!$socket) { exit(3); }
chmod($store . '/telegram.session/ipc', 0700);
file_put_contents($store . '/fixture-worker-pid', (string)getmypid());
fwrite(STDOUT, "READY\n");
while (!is_file($store . '/fixture-stop')) { usleep(20000); }
fclose($socket); flock($lock, LOCK_UN); fclose($lock);
PHP;
        file_put_contents($fixture . '/bin/worker.php', $workerSource); chmod($fixture . '/bin/worker.php', 0600);
        $queue = new Queue($fixture . '/storage/queue.sqlite');
        foreach ($metadata + ['history_required' => '1', 'source_enabled' => '1', 'last_history_checked_at' => (string)time()] as $key => $value) {
            $queue->setMeta($key, $value);
        }
        $process = proc_open([PHP_BINARY, $fixture . '/bin/worker.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        supervisorCheck(is_resource($process), 'Synthetic native worker launches');
        stream_set_timeout($pipes[1], 5);
        supervisorSame('READY', trim((string)fgets($pipes[1])), 'Synthetic worker owns its lock and listener');
        $pid = proc_get_status($process)['pid'];
        $queue->setMeta('worker_pid', (string)$pid);
        $queue->setMeta('worker_heartbeat_at', (string)time());
        $stops = 0; $spawns = 0;
        $supervisor = new WorkerSupervisor($fixture, $fixture . '/storage', PHP_BINARY, [],
            function (array $pin) use (&$stops, $pid, $fixture): void {
                supervisorSame($pid, $pin['pid'], 'Stop callback receives only the pinned fixture generation');
                ++$stops; file_put_contents($fixture . '/storage/fixture-stop', 'stop');
            }, function () use (&$spawns, $fixture): int {
                $h = fopen($fixture . '/storage/process.lock', 'ce');
                supervisorCheck($h !== false && flock($h, LOCK_EX | LOCK_NB), 'Spawner runs only after old process released its lock');
                flock($h, LOCK_UN); fclose($h); ++$spawns; return 999999;
            });
        $pin = $supervisor->inspect($pid);
        supervisorSame($pid, $pin['pid'], 'Native inspection finds exact PID');
        supervisorCheck(ctype_digit($pin['start_ticks']), 'Native inspection pins Linux start ticks');
        supervisorCheck($pin['lock_ino'] > 0 && $pin['socket_ino'] > 0 && $pin['listeners'] !== [], 'Native inspection pins file lock inode and owned listening socket');
        supervisorReject(fn() => $supervisor->inspect(getmypid()), 'Supervisor rejects its own different argv');
        $before = $queue->getMeta('worker_heartbeat_at');
        $first = $supervisor->tick();
        supervisorSame('startup_grace', $first['reason'], 'Existing worker is discovered even though supervisor did not spawn it');
        supervisorSame($before, $queue->getMeta('worker_heartbeat_at'), 'Supervisor snapshot does not mutate worker heartbeat');
        supervisorSame(0, $stops, 'Graceful existing worker is not stopped');
        $queue->setMeta('worker_heartbeat_at', (string)(time() - 1000));
        $statePath = $fixture . '/storage/deploy/supervisor-state.json';
        $state = json_decode(file_get_contents($statePath), true, 16, JSON_THROW_ON_ERROR);
        $state['first_seen_at'] = time() - 1000;
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        $queue->setMeta('paused_reason', 'AUTH_KEY_DUPLICATED');
        supervisorSame('needs_login', $supervisor->tick()['reason'], 'Native fatal pause blocks recovery before IPC');
        supervisorSame(0, $stops, 'Invalid authorization causes no lifecycle call');
        $queue->setMeta('paused_reason', '');
        $queue->setMeta('target_pause_-1002234567890', 'REPOST_RECONCILIATION_REQUIRED');
        supervisorSame('persistent_pause', $supervisor->tick()['reason'], 'Native target containment blocks recovery');
        supervisorSame(0, $spawns, 'Containment creates no second worker');
        $queue->setMeta('target_pause_-1002234567890', '');
        $guard = fopen($fixture . '/storage/supervisor.lock', 'ce'); flock($guard, LOCK_EX | LOCK_NB);
        supervisorCheck(($supervisor->tick()['busy'] ?? false) === true, 'Competing supervisor cannot run while own lock is held');
        flock($guard, LOCK_UN); fclose($guard);
        $restart = $supervisor->tick();
        supervisorSame('restart', $restart['action'], 'Stale actual owner is recovered without trusting parent-child relation');
        supervisorCheck(($restart['old_generation_exit_proved'] ?? false) && ($restart['process_lock_free'] ?? false), 'Old generation exit and lock release are both proved before spawn');
        supervisorSame(1, $stops, 'Exactly one pinned stop is attempted');
        supervisorSame(1, $spawns, 'Exactly one replacement is spawned');
        supervisorSame('recovery_cooldown', $supervisor->tick()['reason'], 'Immediate next cron tick does not repeat recovery');
        supervisorSame(1, $spawns, 'Cooldown retains one spawn even after old owner disappeared');
        $report = json_decode(file_get_contents($fixture . '/storage/deploy/supervisor.json'), true, 16, JSON_THROW_ON_ERROR);
        supervisorCheck(!isset($report['metadata']) && !isset($report['session']), 'Durable report contains no queue/account/session contents');
        supervisorSame(0600, fileperms($statePath) & 0777, 'Supervisor private state remains owner-only');
        supervisorCheck($before !== '', 'Fixture has a concrete heartbeat baseline');

        // Production spawning must detach from timeout's process group. Kill
        // an isolated fixture supervisor group and prove its worker survives.
        if (is_executable('/usr/bin/setsid') && is_executable('/usr/bin/timeout')) {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $pipes = []; proc_close($process); $process = null;
            foreach (['fixture-stop', 'fixture-worker-pid', 'telegram.session/ipc', 'deploy/supervisor-state.json'] as $relative) {
                $path = $fixture . '/storage/' . $relative;
                if (file_exists($path) || is_link($path)) { unlink($path); }
            }
            $wrapper = '<?php require ' . var_export(dirname(__DIR__) . '/src/WorkerSupervisor.php', true) . ';'
                . '$s=new \\TelegramMirror\\WorkerSupervisor(' . var_export($fixture, true) . ','
                . var_export($fixture . '/storage', true) . ',PHP_BINARY);'
                . 'file_put_contents(' . var_export($fixture . '/storage/fixture-wrapper-result', true)
                . ',json_encode($s->tick()));sleep(10);';
            file_put_contents($fixture . '/spawn-wrapper.php', $wrapper);
            $wrapperProcess = proc_open(['/usr/bin/setsid', '/usr/bin/timeout', '--signal=KILL', '2s',
                PHP_BINARY, $fixture . '/spawn-wrapper.php'], [0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $wrapperPipes);
            supervisorCheck(is_resource($wrapperProcess), 'Isolated timeout supervisor fixture launches');
            $deadline = microtime(true) + 5;
            do {
                $wrapperState = proc_get_status($wrapperProcess);
                if (!$wrapperState['running']) { break; }
                usleep(50000);
            } while (microtime(true) < $deadline);
            supervisorCheck(!$wrapperState['running'], 'Outer timeout terminates only its isolated supervisor group');
            proc_close($wrapperProcess);
            $workerPid = (int)file_get_contents($fixture . '/storage/fixture-worker-pid');
            $detached = $supervisor->inspect($workerPid);
            supervisorSame($workerPid, $detached['pid'], 'Detached worker survives termination of supervisor timeout group');
            $raw = file_get_contents('/proc/' . $workerPid . '/stat');
            $fields = preg_split('/\s+/', trim(substr($raw, strrpos($raw, ')') + 1)));
            supervisorSame((string)$workerPid, $fields[3], 'Worker owns a separate Linux session');
            supervisorSame((string)$workerPid, $fields[2], 'Worker owns a separate process group');
            $spawnReport = json_decode(file_get_contents($fixture . '/storage/fixture-wrapper-result'), true, 16, JSON_THROW_ON_ERROR);
            supervisorSame('start', $spawnReport['action'], 'Real production spawn path starts only an absent initialized fixture');
            file_put_contents($fixture . '/storage/fixture-stop', 'stop');
            $deadline = microtime(true) + 3;
            do {
                clearstatcache(); $raw = @file_get_contents('/proc/' . $workerPid . '/stat');
                if (!is_string($raw)) { break; }
                $fields = preg_split('/\s+/', trim(substr($raw, strrpos($raw, ')') + 1)));
                if (in_array($fields[0] ?? '', ['Z', 'X'], true)) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            supervisorCheck(!is_string($raw) || in_array($fields[0] ?? '', ['Z', 'X'], true), 'Detached synthetic worker stops cooperatively after the survival test');
        }
    }
} finally {
    if ($fixture !== null) { file_put_contents($fixture . '/storage/fixture-stop', 'stop'); }
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    if (is_resource($process)) { proc_close($process); }
    unset($queue);
    if ($fixture !== null) { supervisorRemove($fixture); }
}
$result = 'Supervisor: ' . $assertions . " assertions passed.\n";
if (($marker = getenv('TELEGRAM_MIRROR_SUPERVISOR_RESULT')) !== false && $marker !== '') { file_put_contents($marker, $result); }
echo $result;
