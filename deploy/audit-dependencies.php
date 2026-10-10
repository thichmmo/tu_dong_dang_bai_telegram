<?php declare(strict_types=1);
// Read-only Composer advisory lookup; never install or update dependencies.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$root = dirname(__DIR__);
$folder = $root . '/storage/deploy';
$output = $folder . '/dependency-audit-output.json';
$report = $folder . '/dependency-audit.json';
$lockHash = hash_file('sha256', $root . '/composer.lock');
$process = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=1', $folder . '/composer.phar',
    '--no-interaction', '--no-plugins', '--no-scripts', 'audit', '--locked', '--no-dev', '--format=json'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $folder . '/dependency-audit-errors.log', 'w']],
    $pipes, $root, ['PATH' => '/usr/bin:/bin', 'COMPOSER_HOME' => $folder . '/composer-home']);
$deadline = microtime(true) + 60;
if (!is_resource($process)) { exit(1); }
while (($state = proc_get_status($process))['running']) {
    if (microtime(true) >= $deadline) { proc_terminate($process, 9); break; }
    usleep(100000);
}
$exit = $state['exitcode'];
$closed = proc_close($process);
if ($exit < 0) { $exit = $closed; }
$data = is_file($output) && filesize($output) < 2097152 ? json_decode((string) file_get_contents($output), true) : null;
$result = ['checked_at' => gmdate('c'), 'composer_lock_sha256' => $lockHash,
    'sources_unchanged' => $lockHash === hash_file('sha256', $root . '/composer.lock'),
    'exit' => $exit, 'valid_response' => is_array($data) && isset($data['advisories']),
    'advisories' => is_array($data) ? ($data['advisories'] ?? null) : null,
    'abandoned' => is_array($data) ? ($data['abandoned'] ?? null) : null];
file_put_contents($report, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
chmod($report, 0600);
