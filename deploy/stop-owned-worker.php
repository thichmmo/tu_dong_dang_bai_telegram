<?php declare(strict_types=1);
// Temporary maintenance action: target only this app's proved worker process.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/App.php';
use TelegramMirror\App;
umask(0077);
App::boot(false);
$report = App::path('deploy/maintenance-stop.json');
$script = dirname(__DIR__) . '/bin/worker.php';
$lock = App::path('process.lock');
$uid = fileowner('/proc/' . getmypid());
$owned = static function (int $pid) use ($script, $lock, $uid): bool {
    $proc = '/proc/' . $pid;
    if (@fileowner($proc) !== $uid) { return false; }
    $argv = @file_get_contents($proc . '/cmdline');
    if ($argv === false || !in_array($script, explode("\0", $argv), true)) { return false; }
    foreach (@scandir($proc . '/fd') ?: [] as $fd) {
        if (ctype_digit($fd) && @readlink($proc . '/fd/' . $fd) === $lock) { return true; }
    }
    return false;
};
$signal = static function (int $pid, string $name) use ($owned): void {
    if (!$owned($pid)) { return; }
    $process = proc_open(['/bin/kill', '-' . $name, (string) $pid],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($process)) { proc_close($process); }
};
$pids = [];
foreach (glob('/proc/[0-9]*') ?: [] as $proc) {
    $pid = (int) basename($proc);
    if ($owned($pid)) { $pids[] = $pid; $signal($pid, 'TERM'); }
}
$deadline = microtime(true) + 15;
do {
    $remaining = array_values(array_filter($pids, $owned));
    if (!$remaining) { break; }
    usleep(100000);
} while (microtime(true) < $deadline);
foreach ($remaining as $pid) { $signal($pid, 'KILL'); }
usleep(300000);
$free = App::acquireLock();
if ($free) { App::releaseLock(); }
$data = ['stopped_count' => count($pids), 'process_lock_free' => $free, 'checked_at' => time()];
file_put_contents($report, json_encode($data, JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
chmod($report, 0600);
exit($free ? 0 : 1);
