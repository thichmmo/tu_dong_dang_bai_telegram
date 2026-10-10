<?php declare(strict_types=1);
// A bounded, one-time check run by a temporary private cPanel cron job.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$root = dirname(__DIR__);
$outputDirectory = $root . '/storage/deploy';
$reportId = '';
for ($index = 1; $index < count($argv); ++$index) {
    if ($argv[$index] === '--report-id' && isset($argv[$index + 1])) {
        $reportId = $argv[++$index];
    } elseif (str_starts_with($argv[$index], '--report-id=')) {
        $reportId = substr($argv[$index], strlen('--report-id='));
    } else {
        fwrite(STDERR, "Use --report-id followed by 1–64 letters or digits.\n");
        exit(2);
    }
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/D', $reportId)) {
        fwrite(STDERR, "Use --report-id followed by 1–64 letters or digits.\n");
        exit(2);
    }
}
$suffix = $reportId === '' ? '' : '-' . $reportId;
$resultFile = $outputDirectory . '/verification' . $suffix . '.json';
$lock = fopen($outputDirectory . '/verification' . $suffix . '.lock', 'ce');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB) || file_exists($resultFile) || is_link($resultFile)) { exit; }
$deadline = microtime(true) + 90;
$startedAt = gmdate('c');
$cases = [];
$fingerprint = static function () use ($root): array {
    $files = [];
    foreach (['src', 'bin', 'tests'] as $folder) {
        foreach (glob($root . '/' . $folder . '/*.php') ?: [] as $file) {
            $files[$folder . '/' . basename($file)] = $file;
        }
    }
    foreach (glob($root . '/deploy/*.sh') ?: [] as $file) {
        $files['deploy/' . basename($file)] = $file;
    }
    foreach (['composer.json', 'composer.lock', 'config.example.php', 'public/index.php', 'public/admin.php', 'public/.htaccess',
        'deploy/watch-worker.sh', 'deploy/verify.php', 'deploy/library-check.php', 'deploy/twofa-crypto-check.php',
        'deploy/audit-dependencies.php', 'deploy/stop-owned-worker.php'] as $relative) {
        $files[$relative] = $root . '/' . $relative;
    }
    ksort($files, SORT_STRING);
    $hashes = [];
    foreach ($files as $relative => $file) {
        if (!is_file($file) || is_link($file) || ($hash = hash_file('sha256', $file)) === false) {
            throw new RuntimeException('Cannot fingerprint source: ' . $relative);
        }
        $hashes[$relative] = $hash;
    }
    return ['sha256' => hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'files' => $hashes];
};
$before = $after = null;
$madelineVersion = null;
$run = static function (string $name, array $arguments, int $expectedExit, string $successMarker, array $environment = []) use (&$cases, $root, $outputDirectory, $deadline, $suffix): void {
    $outputFile = $outputDirectory . '/verify' . $suffix . '-' . $name . '.log';
    $process = proc_open([PHP_BINARY, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'w'], 2 => ['redirect', 1]], $pipes, $root, ['PATH' => '/usr/bin:/bin', ...$environment]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run verification: ' . $name); }
    while (($status = proc_get_status($process))['running']) {
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            proc_close($process);
            throw new RuntimeException('Verification timed out: ' . $name);
        }
        usleep(50000);
    }
    $exit = $status['exitcode'];
    $closed = proc_close($process);
    if ($exit < 0) { $exit = $closed; }
    $output = (string) file_get_contents($outputFile);
    $passed = $exit === $expectedExit && str_contains($output, $successMarker);
    $assertions = preg_match('/(?:^|\n)[^\n]*: ([0-9]+) assertions passed\./', $successMarker, $match) === 1 ? (int) $match[1] : 0;
    $cases[] = ['name' => $name, 'exit' => $exit, 'passed' => $passed,
        'assertions' => $passed ? $assertions : 0, 'output' => $output];
};
try {
    $before = $fingerprint();
    $composerLock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
    foreach ($composerLock['packages'] ?? [] as $package) {
        if (($package['name'] ?? null) === 'danog/madelineproto') {
            $madelineVersion = ltrim((string) ($package['version'] ?? ''), 'v');
            break;
        }
    }
    if ($madelineVersion !== '8.7.0') {
        throw new RuntimeException('Verification requires MadelineProto 8.7.0.');
    }
    foreach ($before['files'] as $relative => $_hash) {
        if (str_ends_with($relative, '.php')) {
            $run('lint-' . str_replace(['/', '.php'], ['-', ''], $relative), ['-l', $root . '/' . $relative], 0, 'No syntax errors detected');
        }
    }
    $run('queue', [$root . '/tests/queue.php'], 0, 'Queue: 133 assertions passed.');
    $run('mirror', [$root . '/tests/mirror.php'], 0, 'Mirror: 202 assertions passed.');
    $run('repost', [$root . '/tests/repost.php'], 0, 'Repost: 52 assertions passed.');
    $run('multi-source', [$root . '/tests/multi_source.php'], 0, 'Multi-source: 123 assertions passed.');
    $run('control', [$root . '/tests/control.php'], 0, 'Control: 114 assertions passed.');
    foreach (['transient' => 16, 'target-transient' => 18, 'permanent' => 9, 'paused' => 7, 'backoff' => 6, 'missing-baseline' => 5, 'state-unavailable' => 5, 'rpc-permanent' => 6, 'flood-wait' => 7] as $mode => $assertions) {
        $run('startup-' . $mode, [$root . '/tests/mirror.php'], 1, 'Startup ' . $mode . ': ' . $assertions . ' assertions passed.', ['TELEGRAM_MIRROR_STARTUP_TEST' => $mode]);
    }
    $run('delivery-crash', [$root . '/tests/mirror.php'], 1, 'Delivery crash: 14 assertions passed.', ['TELEGRAM_MIRROR_DELIVERY_TEST' => 'accepted-before-commit']);
    $run('lock', [$root . '/tests/lock.php'], 0, 'Lock: 9 assertions passed.');
    $run('lock-inheritance', [$root . '/tests/lock.php'], 0, 'Lock inheritance: 4 assertions passed.', ['TELEGRAM_MIRROR_LOCK_INHERITANCE_TEST' => '1']);
    $run('worker', [$root . '/tests/worker.php'], 0, 'Worker bootstrap: 198 assertions passed.');
    $run('status', [$root . '/tests/status.php'], 0, 'Status liveness: 48 assertions passed.');
    $run('twofa', [$root . '/tests/twofa.php'], 0, 'Two-factor CLI: 366 assertions passed.');
    $run('twofa-crypto', [$root . '/deploy/twofa-crypto-check.php'], 0, 'PASS Node/PHP HKDF AES-GCM interop and metadata tamper rejection.');
    $run('library', [$root . '/deploy/library-check.php'], 0, 'PASS library check completed without a Telegram API/session.');
    $run('doctor', [$root . '/bin/check.php'], 0, '[OK] Composer dependencies');
    $after = $fingerprint();
    $passed = array_reduce($cases, static fn(bool $ok, array $case): bool => $ok && $case['passed'], true);
    $report = ['passed' => $passed && $before === $after];
} catch (Throwable $error) {
    $report = ['passed' => false, 'error' => $error->getMessage(), 'cases' => $cases];
    try { $after = $fingerprint(); } catch (Throwable) {}
}
$report += ['report_id' => $reportId === '' ? null : $reportId, 'php' => PHP_VERSION, 'binary' => PHP_BINARY,
    'madelineproto' => $madelineVersion, 'expected_madelineproto' => '8.7.0', 'started_at' => $startedAt,
    'finished_at' => gmdate('c'), 'sources_unchanged' => $before !== null && $before === $after,
    'source_before' => $before, 'source_after' => $after, 'cases' => $cases,
    'assertions_passed' => array_sum(array_column($cases, 'assertions'))];
$temporary = $resultFile . '.tmp.' . bin2hex(random_bytes(6));
try {
    if (file_put_contents($temporary, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false
        || !chmod($temporary, 0600) || !rename($temporary, $resultFile)) {
        throw new RuntimeException('Cannot save verification report.');
    }
} finally {
    if (is_file($temporary)) { unlink($temporary); }
    flock($lock, LOCK_UN);
    fclose($lock);
}
