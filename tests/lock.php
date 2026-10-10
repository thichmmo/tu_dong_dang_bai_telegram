<?php

declare(strict_types=1);

use TelegramMirror\App;

require_once dirname(__DIR__) . '/src/App.php';

$assertions = 0;
function lockCheck(bool $condition, string $description): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
}

$directory = __DIR__ . '/lock-fixture-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create offline lock fixture.');
}
$reflection = new ReflectionClass(App::class);
$reflection->getProperty('config')->setValue(null, ['storage_dir' => $directory]);
$path = $directory . '/process.lock';

function lockCleanup(string $directory): void
{
    App::releaseLock();
    if (is_file($directory . '/process.lock')) {
        unlink($directory . '/process.lock');
    }
    rmdir($directory);
}

// On native PHP, exercise an actual inherited descriptor during parent
// shutdown. The child remains alive until App's registered release has run.
if (getenv('TELEGRAM_MIRROR_LOCK_INHERITANCE_TEST') === '1') {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
        lockCleanup($directory);
        echo "Lock inheritance: SKIP (native pcntl extension required).\n";
        exit(0);
    }
    lockCheck(App::acquireLock(), 'Parent acquires the process lock');
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        lockCleanup($directory);
        throw new RuntimeException('Cannot create lock test control sockets.');
    }
    foreach ($sockets as $socket) {
        stream_set_timeout($socket, 5);
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        lockCleanup($directory);
        throw new RuntimeException('Cannot fork lock test child.');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        fwrite($sockets[1], "READY\n");
        $command = trim((string) fgets($sockets[1]));
        $probe = fopen($path, 'c');
        $acquired = $command === 'CHECK' && $probe !== false && flock($probe, LOCK_EX | LOCK_NB);
        fwrite($sockets[1], $acquired ? "PASS\n" : "FAIL\n");
        if ($probe !== false) {
            if ($acquired) {
                flock($probe, LOCK_UN);
            }
            fclose($probe);
        }
        fclose($sockets[1]);
        exit($acquired ? 0 : 1);
    }
    fclose($sockets[1]);
    lockCheck(trim((string) fgets($sockets[0])) === 'READY', 'Child is alive with the inherited lock descriptor');
    // App::acquireLock registered its shutdown callback before this one.
    register_shutdown_function(function () use ($sockets, $pid, $directory): void {
        global $assertions;
        try {
            fwrite($sockets[0], "CHECK\n");
            lockCheck(trim((string) fgets($sockets[0])) === 'PASS',
                'Parent shutdown unlocks despite a surviving inherited descriptor');
            pcntl_waitpid($pid, $status);
            lockCheck(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Inheritance test child exits successfully');
            echo 'Lock inheritance: ' . $assertions . " assertions passed.\n";
        } finally {
            fclose($sockets[0]);
            lockCleanup($directory);
        }
    });
    exit(0);
}

$foreign = null;
$probe = null;
try {
    lockCheck(App::acquireLock(), 'First session helper acquires its lock');
    $foreign = fopen($path, 'c');
    $probe = fopen($path, 'c');
    lockCheck($foreign !== false && $probe !== false, 'Independent helper descriptors open');
    lockCheck(!flock($foreign, LOCK_EX | LOCK_NB), 'Different helper cannot acquire an active lock');
    App::releaseLock();
    lockCheck(flock($foreign, LOCK_EX | LOCK_NB), 'Explicit release lets the next helper acquire the lock');
    App::releaseLock();
    lockCheck(!flock($probe, LOCK_EX | LOCK_NB), 'Repeated release does not unlock a different owner');
    lockCheck(!App::acquireLock(), 'A competing owner prevents acquiring the lock');
    App::releaseLock();
    lockCheck(!flock($probe, LOCK_EX | LOCK_NB), 'Failed acquisition does not gain ownership or unlock the competing owner');
    flock($foreign, LOCK_UN);
    fclose($foreign);
    $foreign = null;
    lockCheck(App::acquireLock(), 'A later helper can acquire after the competing owner releases');
    App::releaseLock();
    lockCheck(flock($probe, LOCK_EX | LOCK_NB), 'Reacquired lock can be released again');
    flock($probe, LOCK_UN);
    echo 'Lock: ' . $assertions . " assertions passed.\n";
} finally {
    foreach ([$foreign, $probe] as $stream) {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    lockCleanup($directory);
}
