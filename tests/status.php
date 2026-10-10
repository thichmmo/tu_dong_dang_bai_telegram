<?php

declare(strict_types=1);

// Run the actual status entry point against private, synthetic local state.
// Native Linux also keeps a real lock-owning child alive for /proc checks.
use TelegramMirror\App;
use TelegramMirror\Queue;

$assertions = 0;
if (($resultPath = getenv('TELEGRAM_MIRROR_STATUS_RESULT')) !== false && $resultPath !== '') {
    register_shutdown_function(static function () use ($resultPath): void {
        global $assertions;
        file_put_contents($resultPath, json_encode(['assertions' => $assertions, 'error' => error_get_last()]));
    });
}
function statusCheck(bool $condition, string $description): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
}

function statusRemove(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                statusRemove($path . '/' . $item);
            }
        }
        rmdir($path);
    } elseif (is_file($path)) {
        unlink($path);
    }
}

$fixture = __DIR__ . '/status-fixture-' . bin2hex(random_bytes(8));
foreach ([$fixture, $fixture . '/src', $fixture . '/bin', $fixture . '/storage'] as $path) {
    mkdir($path, 0700);
}
foreach (['App.php', 'Queue.php', 'StartupValidationException.php'] as $file) {
    copy(dirname(__DIR__) . '/src/' . $file, $fixture . '/src/' . $file);
}
$reportPath = $fixture . '/status-report.json';
// fwrite(STDOUT) bypasses PHP output buffering. Redirect only that output
// descriptor in the copied entry point, preserving its complete status logic.
$source = file_get_contents(dirname(__DIR__) . '/bin/status.php');
$source = str_replace('fwrite(STDOUT,', 'fwrite($statusOutput,', $source, $replacements);
if ($replacements !== 1) { throw new RuntimeException('Status output capture needs review.'); }
file_put_contents($fixture . '/bin/status.php', $source);
$config = ['source' => '@test_source', 'target' => '@test_target', 'storage_dir' => $fixture . '/storage'];
file_put_contents($fixture . '/config.php', '<?php return ' . var_export($config, true) . ';');

// A test-only worker exercises the real App file lock. No Telegram libraries,
// credentials or network are used. Its absolute argv matches the app path.
file_put_contents($fixture . '/bin/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
use TelegramMirror\App;
require dirname(__DIR__) . '/src/App.php';
App::boot(false);
if (!App::acquireLock()) { exit(2); }
$queue = App::queue();
$queue->setMeta('worker_pid', (string) getmypid());
$queue->setMeta('worker_ready', '1');
$queue->setMeta('worker_heartbeat_at', (string) time());
$queue->setMeta('paused_reason', '');
fwrite(STDOUT, "READY\n");
$other = null;
while (($line = fgets(STDIN)) !== false) {
    switch (trim($line)) {
        case 'pause': $queue->setMeta('paused_reason', 'CHAT_WRITE_FORBIDDEN'); break;
        case 'old': $queue->setMeta('worker_heartbeat_at', (string) (time() - 60)); break;
        case 'future': $queue->setMeta('worker_heartbeat_at', (string) (time() + 60)); break;
        case 'not-ready': $queue->setMeta('worker_ready', '0'); break;
        case 'ready':
            $queue->setMeta('worker_ready', '1');
            $queue->setMeta('worker_heartbeat_at', (string) time());
            $queue->setMeta('paused_reason', '');
            break;
        case 'unlock': App::releaseLock(); break;
        case 'other-lock':
            App::releaseLock();
            $other = fopen(App::path('other.lock'), 'ce');
            if ($other === false || !flock($other, LOCK_EX | LOCK_NB)) { exit(3); }
            break;
        case 'relock':
            if (is_resource($other)) { fclose($other); $other = null; }
            if (!App::acquireLock()) { exit(4); }
            break;
        case 'exit': exit(0);
        default: exit(5);
    }
    fwrite(STDOUT, "OK\n");
}
PHP);
require $fixture . '/src/App.php';
App::boot(false);
$queue = App::queue();
foreach (['initialized_at' => time(), 'worker_pid' => getmypid(), 'worker_ready' => 1,
    'worker_heartbeat_at' => time(), 'paused_reason' => ''] as $key => $value) {
    $queue->setMeta($key, (string) $value);
}
$queueSource = -1001234567890;
$targetA = -1002234567890;
$targetB = -1003234567890;
$queue->setMeta('source_id', (string) $queueSource);
$queue->setMeta('target_id', (string) $targetA);
$queue->setMeta('source_min_id', '0');
$queue->registerTargets($queueSource, [$targetA, $targetB], 0);
$queue->enqueue($queueSource, ['id' => 1, 'date' => time()]);
$queue->markForwarded([1], $queueSource, $targetA);
$queue->setMeta('last_forwarded_id_' . $targetA, '1');
$statusPath = $fixture . '/bin/status.php';
$readStatus = static function () use ($statusPath, $reportPath): array {
    $statusOutput = fopen($reportPath, 'w');
    try {
        require $statusPath;
    } finally {
        fclose($statusOutput);
    }
    return json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
};
$process = null;
$pipes = [];
$verifyTargetStates = static function (bool $receiving) use ($queue, $readStatus, $targetA, $targetB): void {
    $queue->setMeta('target_pause_' . $targetA, '');
    $queue->setMeta('target_pause_' . $targetB, '');
    $queue->setMeta('unresolved_targets', '{}');
    $report = $readStatus();
    statusCheck($report['receiving_ready'] === $receiving && $report['ready'] === $receiving
        && $report['all_targets_ready'] === $receiving
        && $report['targets'][$targetA]['ready'] === $receiving
        && $report['targets'][$targetB]['ready'] === $receiving,
        'Healthy destinations are ready only when the proven receiving worker is ready');
    statusCheck(!$report['targets'][$targetA]['paused'] && !$report['targets'][$targetB]['paused'],
        'Both healthy destination permissions are represented');

    $queue->setMeta('target_pause_' . $targetB, 'CHAT_ADMIN_REQUIRED');
    $report = $readStatus();
    statusCheck($report['receiving_ready'] === $receiving && $report['ready'] === $receiving
        && !$report['all_targets_ready'] && $report['targets'][$targetA]['ready'] === $receiving
        && !$report['targets'][$targetB]['ready'], 'A stays ready while B is independently paused');
    statusCheck($report['targets'][$targetB]['paused']
        && $report['targets'][$targetB]['pause_reason'] === 'CHAT_ADMIN_REQUIRED', 'B exposes its own permission pause');
    statusCheck(!$report['paused'], 'A destination pause does not create a global source pause');

    $queue->setMeta('target_pause_' . $targetA, 'CHAT_WRITE_FORBIDDEN');
    $report = $readStatus();
    statusCheck($report['receiving_ready'] === $receiving && !$report['ready'] && !$report['all_targets_ready'],
        'When both destinations pause, receiving can remain healthy while delivery is unready');
    statusCheck($report['targets'][$targetA]['paused'] && $report['targets'][$targetB]['paused'],
        'Both destination pauses remain visible');

    $queue->setMeta('target_pause_' . $targetA, '');
    $queue->setMeta('target_pause_' . $targetB, '');
    $unresolved = ['@missing_target' => 'CHANNEL_INVALID'];
    $queue->setMeta('unresolved_targets', json_encode($unresolved, JSON_THROW_ON_ERROR));
    $report = $readStatus();
    statusCheck($report['receiving_ready'] === $receiving && $report['ready'] === $receiving && !$report['all_targets_ready'],
        'An unresolved configured destination prevents all-target readiness');
    statusCheck($report['unresolved_targets'] === $unresolved, 'The unresolved destination and reason are reported');
    statusCheck($report['targets'][$targetA]['ready'] === $receiving && $report['targets'][$targetB]['ready'] === $receiving,
        'Unresolved destination does not mask resolved destination readiness');
    $queue->setMeta('unresolved_targets', '{}');
    $report = $readStatus();
    statusCheck($report['all_targets_ready'] === $receiving, 'Resolving the configuration restores complete readiness');
    statusCheck($report['targets'][$targetA]['forwarded'] === 1 && $report['targets'][$targetB]['pending'] === 1,
        'Permission and resolution transitions preserve independent delivery statistics');
};
try {
    $report = $readStatus();
    statusCheck(is_int($report['generated_at']) && abs(time() - $report['generated_at']) < 5,
        'Status records when this report was generated');
    statusCheck(is_bool($report['worker_alive']) && is_bool($report['liveness_unavailable']),
        'Liveness fields are safe booleans');
    statusCheck(!$report['worker_alive'] && !$report['ready'],
        'Fresh metadata cannot make the non-worker test process ready');
    statusCheck($report['metadata']['worker_pid'] === (string) getmypid(), 'Private status includes the recorded PID');
    statusCheck($report['heartbeat_age_seconds'] < 30, 'False readiness is tested with a fresh heartbeat');
    statusCheck(is_bool($report['receiving_ready']) && is_bool($report['all_targets_ready']), 'Receiving and complete delivery readiness are explicit booleans');
    statusCheck($report['targets'][$targetA]['forwarded'] === 1 && $report['targets'][$targetB]['pending'] === 1,
        'Status exposes independent per-destination delivery statistics');
    statusCheck($report['targets'][$targetA]['last_forwarded_id'] === '1', 'Status exposes the destination-specific latest forwarded ID');

    $native = PHP_OS_FAMILY === 'Linux' && is_readable('/proc/self/cmdline') && is_dir('/proc/self/fd');
    if (!$native) {
        statusCheck($report['liveness_unavailable'], 'Unavailable Linux process inspection is reported');
        $verifyTargetStates(false);
        $queue->setMeta('paused_reason', 'CHAT_WRITE_FORBIDDEN');
        $report = $readStatus();
        statusCheck($report['paused'] && !$report['ready'], 'An existing permission pause always blocks readiness');
        $queue->setMeta('paused_reason', '');
        $queue->setMeta('worker_pid', '99999999');
        $report = $readStatus();
        statusCheck(!$report['worker_alive'] && !$report['ready'], 'Missing process is never assumed alive');
        statusCheck($report['liveness_unavailable'], 'Missing /proc remains explicitly unavailable');
    } else {
        statusCheck(!$report['liveness_unavailable'], 'A readable Linux process with the wrong argv is conclusively rejected');
        $process = proc_open([PHP_BINARY, $fixture . '/bin/worker.php'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start offline liveness child.'); }
        foreach ($pipes as $pipe) { stream_set_timeout($pipe, 5); }
        statusCheck(trim((string) fgets($pipes[1])) === 'READY', 'A native lock-owning worker fixture is running');
        $childPid = proc_get_status($process)['pid'];
        $command = static function (string $value) use ($pipes): void {
            fwrite($pipes[0], $value . "\n");
            statusCheck(trim((string) fgets($pipes[1])) === 'OK', 'Fixture accepts ' . $value);
        };
        $report = $readStatus();
        statusCheck($report['metadata']['worker_pid'] === (string) $childPid, 'Readiness is bound to the actual owner PID');
        statusCheck($report['worker_alive'] && !$report['liveness_unavailable'] && $report['ready'],
            'Exact worker argv, owned UID and held lock FD prove a live ready worker');
        $verifyTargetStates(true);

        $command('pause');
        $report = $readStatus();
        statusCheck($report['worker_alive'] && $report['paused'] && !$report['ready'],
            'A live worker with a permission pause is not ready');
        $command('ready');
        $command('old');
        $report = $readStatus();
        statusCheck($report['worker_alive'] && !$report['ready'] && $report['heartbeat_age_seconds'] >= 30,
            'A stale heartbeat blocks readiness despite a live owner');
        $command('future');
        $report = $readStatus();
        statusCheck($report['worker_alive'] && !$report['ready'], 'A future heartbeat cannot prove readiness');
        $command('ready');
        $command('not-ready');
        $report = $readStatus();
        statusCheck($report['worker_alive'] && !$report['ready'], 'An unready live worker remains unready');
        $command('ready');
        $command('unlock');
        $report = $readStatus();
        statusCheck(!$report['worker_alive'] && !$report['liveness_unavailable'] && !$report['ready'],
            'A live process without the session lock descriptor cannot claim readiness');
        $command('other-lock');
        $report = $readStatus();
        statusCheck(!$report['worker_alive'] && !$report['ready'], 'Holding a different file lock cannot prove ownership');

        // The correct FD alone is insufficient: this test process has another
        // argv, so it must be rejected even while it owns the session lock.
        statusCheck(App::acquireLock(), 'Non-worker process acquires the released session lock');
        $queue->setMeta('worker_pid', (string) getmypid());
        $report = $readStatus();
        statusCheck(!$report['worker_alive'] && !$report['ready'], 'Matching session FD with unrelated argv is rejected');
        App::releaseLock();
        $queue->setMeta('worker_pid', (string) $childPid);
        $command('relock');
        $command('ready');
        $report = $readStatus();
        statusCheck($report['worker_alive'] && $report['ready'], 'Recovered owner is ready again');
        fwrite($pipes[0], "exit\n");
        fclose($pipes[0]);
        $pipes[0] = null;
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $pipes = [];
        $exitCode = proc_close($process);
        $process = null;
        statusCheck($exitCode === 0 && $stderr === '', 'Native fixture exits cleanly');
        $report = $readStatus();
        statusCheck($report['metadata']['worker_ready'] === '1' && $report['heartbeat_age_seconds'] < 30,
            'Exit test retains the last ready flag and fresh heartbeat');
        statusCheck(!$report['worker_alive'] && !$report['ready'] && !$report['liveness_unavailable'],
            'A freshly exited worker cannot produce a stale ready report');
    }
    $queue->setMeta('worker_pid', 'not-a-pid');
    $report = $readStatus();
    statusCheck(!$report['worker_alive'] && !$report['ready'], 'Invalid PID metadata is safely rejected');
    echo 'Status liveness: ' . $assertions . " assertions passed.\n";
} finally {
    App::releaseLock();
    if (is_resource($process)) {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fwrite($pipes[0], "exit\n");
        }
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        proc_terminate($process);
        proc_close($process);
    }
    // Close every SQLite handle before removing mapped fixture files.
    (new ReflectionClass(App::class))->getProperty('queue')->setValue(null, null);
    unset($queue, $verifyTargetStates);
    statusRemove($fixture);
}
