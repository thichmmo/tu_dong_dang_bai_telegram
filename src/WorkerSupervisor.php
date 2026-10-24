<?php

declare(strict_types=1);

namespace TelegramMirror;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Independent, local health supervisor. It never opens a Telegram session. */
final class WorkerSupervisor
{
    private int $uid;
    private ?Closure $stopper;
    private ?Closure $spawner;

    public function __construct(private readonly string $root, private readonly string $storage,
        private readonly string $php, private readonly array $options = [], ?callable $stopper = null,
        ?callable $spawner = null)
    {
        if (PHP_OS_FAMILY !== 'Linux' || realpath($root) !== $root || realpath($storage) !== $storage
            || preg_match('~(?:^|/)(?:public_html|htdocs)(?:/|$)~i', $root . '/' . $storage)
            || !is_executable($php)) { throw new RuntimeException('SUPERVISOR_PRIVATE_LINUX_REQUIRED'); }
        $uid = @fileowner('/proc/' . getmypid());
        if (!is_int($uid)) { throw new RuntimeException('SUPERVISOR_UID_UNAVAILABLE'); }
        $this->uid = $uid;
        $this->privatePath($root, 0040000);
        $this->privatePath($storage, 0040000);
        $this->privatePath($storage . '/deploy', 0040000);
        $this->stopper = $stopper === null ? null : Closure::fromCallable($stopper);
        $this->spawner = $spawner === null ? null : Closure::fromCallable($spawner);
    }

    /** Policy is separately testable; an unhealthy status alone never grants a restart. */
    public static function decision(array $snapshot, ?array $owner, array $state, int $now, array $options = []): array
    {
        $meta = $snapshot['metadata'] ?? [];
        $sources = $snapshot['sources'] ?? [];
        foreach ([$meta, ...$sources] as $entry) {
            foreach (['paused_reason', 'startup_error', 'last_history_error', 'route_config_error'] as $key) {
                $reason = (string)($entry[$key] ?? '');
                if (preg_match('/AUTH_KEY_|SESSION_(?:REVOKED|EXPIRED|PASSWORD)|USER_DEACTIVATED|API_ID_INVALID|PEER_FLOOD|FROZEN_METHOD/', $reason)) {
                    return ['action' => 'blocked', 'reason' => 'needs_login'];
                }
            }
            foreach ($entry as $key => $value) {
                if (($key === 'paused_reason' || str_starts_with((string)$key, 'target_pause_')) && $value !== '') {
                    return ['action' => 'blocked', 'reason' => 'persistent_pause'];
                }
            }
            foreach (['startup_retry_at', 'global_forward_retry_at', 'forward_retry_at'] as $key) {
                if ((int)($entry[$key] ?? 0) > $now) { return ['action' => 'deferred', 'reason' => 'retry_wait']; }
            }
            $error = (string)($entry['last_history_error'] ?? '');
            $transient = $error !== '' && preg_match('/TIMEOUT|timed out|cancel|connect|RPC_(?:CALL|MCGET)_FAIL|RECEIVE_TIMEOUT|HISTORY_SOURCE_REVALIDATION_FAILED/i', $error);
            if ($error !== '' && !$transient) {
                return ['action' => 'blocked', 'reason' => 'history_repair_required'];
            }
            $lastSuccess = (int)($entry['last_history_checked_at'] ?? 0);
            $historyStale = $lastSuccess <= $now && $now - $lastSuccess > max(600, 5 * (int)($options['history_poll_seconds'] ?? 60));
            // Repeated bounded transport failures must eventually reset the
            // connection even when their local backoff is renewed each time.
            // Real FloodWait also sets the global gate, which remains mandatory.
            if ((int)($entry['history_retry_at'] ?? 0) > $now && !($transient && $historyStale)) {
                return ['action' => 'deferred', 'reason' => 'retry_wait'];
            }
        }
        foreach (['initialized_at', 'validated_at', 'source_id', 'target_id', 'account_id'] as $key) {
            if (!isset($meta[$key]) || !preg_match('/^-?[0-9]+$/D', (string)$meta[$key])) {
                return ['action' => 'blocked', 'reason' => 'not_initialized'];
            }
        }
        $cooldown = max(120, min(3600, (int)($options['cooldown_seconds'] ?? 300)));
        if ((int)($state['last_recovery_at'] ?? 0) > $now - $cooldown) {
            return ['action' => 'deferred', 'reason' => 'recovery_cooldown'];
        }
        if ($owner === null) { return ['action' => 'start', 'reason' => 'worker_absent']; }
        $grace = max(60, min(600, (int)($options['startup_grace_seconds'] ?? 180)));
        $firstSeen = (int)($state['first_seen_at'] ?? $now);
        if ($firstSeen > $now || $now - $firstSeen < $grace) {
            return ['action' => 'deferred', 'reason' => 'startup_grace'];
        }
        $heartbeat = (int)($meta['worker_heartbeat_at'] ?? 0);
        if ($heartbeat > $now) { return ['action' => 'blocked', 'reason' => 'clock_invalid']; }
        $heartbeatLimit = max(90, min(600, (int)($options['heartbeat_stale_seconds'] ?? 180)));
        if ($heartbeat === 0 || $now - $heartbeat > $heartbeatLimit) {
            return ['action' => 'restart', 'reason' => 'heartbeat_stalled'];
        }
        $historyLimit = max(300, min(3600, (int)($options['history_stale_seconds'] ?? 600)));
        $poll = max(30, (int)($options['history_poll_seconds'] ?? 60));
        foreach ($sources as $entry) {
            if (($entry['source_enabled'] ?? '1') === '0' || ($entry['history_required'] ?? '0') !== '1') { continue; }
            $checked = (int)($entry['last_history_checked_at'] ?? 0);
            if ($checked > $now) { return ['action' => 'blocked', 'reason' => 'clock_invalid']; }
            if ($checked === 0 || $now - $checked > max($historyLimit, $poll * 5)) {
                return ['action' => 'restart', 'reason' => 'history_stalled'];
            }
        }
        return ['action' => 'healthy', 'reason' => 'progress_recent'];
    }

    /** Inspect the actual owner, including orphaned workers; never trust a PID alone. */
    public function inspect(int $pid, bool $requireIpc = true): array
    {
        if ($pid < 1) { throw new RuntimeException('OWNER_PID_INVALID'); }
        $proc = '/proc/' . $pid;
        clearstatcache();
        if (@fileowner($proc) !== $this->uid) { throw new RuntimeException('OWNER_UID_MISMATCH'); }
        $argv = @file_get_contents($proc . '/cmdline');
        $stat = @file_get_contents($proc . '/stat');
        $fds = @scandir($proc . '/fd');
        if ($argv === false || $stat === false || $fds === false
            || explode("\0", rtrim($argv, "\0")) !== [$this->php, $this->root . '/bin/worker.php']) {
            throw new RuntimeException('OWNER_ARGV_UNPROVED');
        }
        $fields = self::procFields($stat);
        if (!ctype_digit($fields[19] ?? '') || in_array($fields[0] ?? '', ['Z', 'X'], true)) {
            throw new RuntimeException('OWNER_GENERATION_UNPROVED');
        }
        $lockPath = $this->storage . '/process.lock';
        $lock = $this->privatePath($lockPath, 0100000);
        $holdsLock = false; $sockets = [];
        foreach ($fds as $fd) {
            if (!ctype_digit($fd)) { continue; }
            $link = @readlink($proc . '/fd/' . $fd);
            if ($link === $lockPath) {
                $held = @stat($proc . '/fd/' . $fd);
                $info = @file_get_contents($proc . '/fdinfo/' . $fd);
                if (is_array($held) && $held['dev'] === $lock['dev'] && $held['ino'] === $lock['ino']
                    && is_string($info) && preg_match('/^lock:\s+\d+:\s+FLOCK\s+ADVISORY\s+WRITE\s+' . $pid
                        . '\s+\S+:' . $lock['ino'] . '\s+0\s+EOF\s*$/m', $info)) { $holdsLock = true; }
            }
            if (is_string($link) && preg_match('/^socket:\[([0-9]+)\]$/D', $link, $m)) { $sockets[$m[1]] = true; }
        }
        if (!$holdsLock) { throw new RuntimeException('OWNER_LOCK_UNPROVED'); }
        $identity = ['pid' => $pid, 'uid' => $this->uid, 'start_ticks' => $fields[19],
            'lock_dev' => $lock['dev'], 'lock_ino' => $lock['ino']];
        if ($requireIpc) {
            $this->privatePath($this->storage . '/telegram.session', 0040000);
            $path = $this->storage . '/telegram.session/ipc';
            $socket = $this->privatePath($path, 0140000);
            $unix = @file_get_contents('/proc/net/unix');
            if (!is_string($unix)) { throw new RuntimeException('OWNER_SOCKET_UNPROVED'); }
            $listeners = [];
            foreach (explode("\n", $unix) as $line) {
                $parts = preg_split('/\s+/', trim($line), 8);
                if (count($parts) === 8 && $parts[3] === '00010000' && $parts[4] === '0001'
                    && $parts[7] === $path && isset($sockets[$parts[6]])) { $listeners[] = $parts[6]; }
            }
            sort($listeners, SORT_STRING);
            if ($listeners === []) { throw new RuntimeException('OWNER_SOCKET_UNPROVED'); }
            $identity += ['socket_dev' => $socket['dev'], 'socket_ino' => $socket['ino'], 'listeners' => $listeners];
        }
        $again = @file_get_contents($proc . '/stat');
        if (!is_string($again) || (self::procFields($again)[19] ?? null) !== $identity['start_ticks']) {
            throw new RuntimeException('OWNER_GENERATION_CHANGED');
        }
        return $identity;
    }

    public function tick(): array
    {
        $report = ['version' => 1, 'checked_at' => time(), 'action' => 'blocked', 'reason' => 'inspection_unavailable'];
        $lock = $this->openLock('supervisor.lock');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $report + ['busy' => true]; }
        try {
            $state = $this->readJson($this->storage . '/deploy/supervisor-state.json') ?? [];
            $snapshot = $this->snapshot();
            $owners = $this->owners();
            if (count($owners) > 1) { throw new RuntimeException('MULTIPLE_OWNERS'); }
            $owner = $owners[0] ?? null;
            if ($owner !== null && (string)$owner['pid'] !== (string)($snapshot['metadata']['worker_pid'] ?? '')) {
                throw new RuntimeException('METADATA_OWNER_MISMATCH');
            }
            $generation = $owner === null ? null : hash('sha256', json_encode($owner, JSON_THROW_ON_ERROR));
            if (($state['generation'] ?? null) !== $generation) {
                $state['generation'] = $generation;
                $state['first_seen_at'] = time();
            }
            $decision = self::decision($snapshot, $owner, $state, time(), $this->options);
            $report = array_replace($report, $decision);
            if ($owner !== null) { $report['worker_pid'] = $owner['pid']; }
            if ($decision['action'] === 'restart') {
                $pin = $this->inspect($owner['pid']);
                foreach ($owner as $key => $value) {
                    if (($pin[$key] ?? null) !== $value) { throw new RuntimeException('OWNER_GENERATION_CHANGED'); }
                }
                $state['last_recovery_at'] = time();
                $this->writeJson($this->storage . '/deploy/supervisor-state.json', $state);
                if ($this->inspect($pin['pid']) !== $pin) { throw new RuntimeException('OWNER_GENERATION_CHANGED'); }
                $freshPolicy = self::decision($this->snapshot(), $owner,
                    ['first_seen_at' => 0], time(), $this->options);
                if ($freshPolicy['action'] !== 'restart') { throw new RuntimeException('RECOVERY_CANCELLED'); }
                $report['owner_generation_sha256'] = hash('sha256', json_encode($pin, JSON_THROW_ON_ERROR));
                if ($this->stopper !== null) { ($this->stopper)($pin); }
                else { $this->stop($pin); }
                $deadline = microtime(true) + 12;
                while (!$this->exited($pin) && microtime(true) < $deadline) { usleep(100000); }
                if (!$this->exited($pin)) { throw new RuntimeException('OWNER_EXIT_UNPROVED'); }
                $report['old_generation_exit_proved'] = true;
                $owner = null;
            }
            if ($decision['action'] === 'start' || $decision['action'] === 'restart') {
                if ($this->owners() !== []) { throw new RuntimeException('OWNER_APPEARED'); }
                if (self::decision($this->snapshot(), null, [], time(), $this->options)['action'] !== 'start') {
                    throw new RuntimeException('RECOVERY_CANCELLED');
                }
                $this->privatePath($this->storage . '/telegram.session', 0040000);
                $probe = $this->openLock('process.lock');
                if (!flock($probe, LOCK_EX | LOCK_NB)) { fclose($probe); throw new RuntimeException('PROCESS_LOCK_BUSY'); }
                $report['process_lock_free'] = true;
                // The worker takes this same lock; release only our descriptor before spawning.
                flock($probe, LOCK_UN); fclose($probe);
                $state['last_recovery_at'] = time();
                $this->writeJson($this->storage . '/deploy/supervisor-state.json', $state);
                $pid = $this->spawner === null ? $this->spawn() : ($this->spawner)();
                if (!is_int($pid) || $pid < 1) { throw new RuntimeException('SPAWN_UNPROVED'); }
                $report['spawned_pid'] = $pid;
            }
            $this->writeJson($this->storage . '/deploy/supervisor-state.json', $state);
        } catch (Throwable $error) {
            $known = ['MULTIPLE_OWNERS', 'METADATA_OWNER_MISMATCH', 'OWNER_GENERATION_CHANGED', 'OWNER_EXIT_UNPROVED',
                'OWNER_SOCKET_UNPROVED', 'OWNER_LOCK_UNPROVED', 'OWNER_APPEARED', 'PROCESS_LOCK_BUSY', 'SPAWN_UNPROVED', 'RECOVERY_CANCELLED'];
            $report['action'] = 'blocked';
            $report['reason'] = in_array($error->getMessage(), $known, true) ? strtolower($error->getMessage()) : 'inspection_unavailable';
        } finally {
            try { $this->writeJson($this->storage . '/deploy/supervisor.json', $report); } catch (Throwable) {}
            flock($lock, LOCK_UN); fclose($lock);
        }
        return $report;
    }

    private function snapshot(): array
    {
        $additional = glob($this->storage . '/queue-source-*.sqlite') ?: [];
        $paths = [$this->storage . '/queue.sqlite', ...$additional];
        if (count($paths) > 101) { throw new RuntimeException('QUEUE_LIMIT'); }
        $sources = [];
        foreach ($paths as $path) {
            $this->privatePath($path, 0100000);
            if (!defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || !defined('PDO::SQLITE_OPEN_READONLY')) {
                throw new RuntimeException('READONLY_PDO_REQUIRED');
            }
            $uri = implode('/', array_map('rawurlencode', explode('/', $path)));
            $db = new PDO('sqlite:file:' . $uri . '?mode=ro', null, null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0,
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
            $db->exec('PRAGMA query_only=ON');
            $rows = $db->query('SELECT key,value FROM queue_metadata LIMIT 1001')->fetchAll(PDO::FETCH_KEY_PAIR);
            if (count($rows) > 1000) { throw new RuntimeException('METADATA_LIMIT'); }
            $sources[] = $rows;
            unset($db);
        }
        return ['metadata' => $sources[0], 'sources' => $sources];
    }

    private function owners(): array
    {
        $owners = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $proc) {
            if (@fileowner($proc) !== $this->uid) { continue; }
            $argv = @file_get_contents($proc . '/cmdline');
            if (!is_string($argv) || explode("\0", rtrim($argv, "\0")) !== [$this->php, $this->root . '/bin/worker.php']) { continue; }
            $stat = @file_get_contents($proc . '/stat');
            if (is_string($stat) && in_array(self::procFields($stat)[0] ?? '', ['Z', 'X'], true)) { continue; }
            // A matching live command with unprovable ownership is not an absent worker.
            $owners[] = $this->inspect((int)basename($proc), false);
        }
        return $owners;
    }

    private function stop(array $pin): void
    {
        require_once $this->root . '/vendor/autoload.php';
        if ($this->inspect($pin['pid']) !== $pin) { throw new RuntimeException('OWNER_GENERATION_CHANGED'); }
        $future = \Amp\async(fn() => \Amp\Ipc\connect($this->storage . '/telegram.session/ipc'));
        try { $socket = $future->await(new \Amp\TimeoutCancellation(3)); }
        catch (Throwable $error) { $future->ignore(); throw $error; }
        try {
            if ($this->inspect($pin['pid']) !== $pin) { throw new RuntimeException('OWNER_GENERATION_CHANGED'); }
            // MP8.7 dispatches this lifecycle method locally. No Telegram RPC is sent.
            $future = \Amp\async(static function () use ($socket): mixed {
                $socket->send(['stop', []]);
                return $socket->receive();
            });
            try { $future->await(new \Amp\TimeoutCancellation(8)); }
            catch (Throwable) { $future->ignore(); }
        } finally { $socket->disconnect(); }
    }

    private function spawn(): int
    {
        if (!is_executable('/usr/bin/setsid') || !is_executable('/usr/bin/nohup')) {
            throw new RuntimeException('SPAWN_UNPROVED');
        }
        $log = $this->storage . '/worker.log';
        if (!file_exists($log)) { $h = fopen($log, 'x'); if ($h === false) { throw new RuntimeException('LOG_UNAVAILABLE'); } fclose($h); }
        $this->privatePath($log, 0100000);
        // A separate session also separates the worker from timeout's process
        // group. nohup alone would not protect it from the observer's SIGKILL.
        $script = 'set +m; umask 077; /usr/bin/nohup /usr/bin/setsid "$1" "$2" </dev/null >>"$3" 2>&1 & printf "%s\n" "$!"';
        $process = proc_open(['/bin/bash', '-c', $script, 'telegram-supervisor', $this->php,
            $this->root . '/bin/worker.php', $log], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w']], $pipes, $this->root);
        if (!is_resource($process)) { throw new RuntimeException('SPAWN_UNPROVED'); }
        $pid = trim((string)stream_get_contents($pipes[1], 64)); fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || !ctype_digit($pid)) { throw new RuntimeException('SPAWN_UNPROVED'); }
        return (int)$pid;
    }

    private function exited(array $pin): bool
    {
        clearstatcache();
        $path = '/proc/' . $pin['pid'];
        if (!is_dir($path)) { return true; }
        $raw = @file_get_contents($path . '/stat');
        if (!is_string($raw)) { return false; }
        $fields = self::procFields($raw);
        return ($fields[19] ?? null) !== $pin['start_ticks'] || in_array($fields[0] ?? '', ['Z', 'X'], true);
    }

    private static function procFields(string $raw): array
    {
        $end = strrpos($raw, ')');
        return $end === false ? [] : preg_split('/\s+/', trim(substr($raw, $end + 1)));
    }

    private function privatePath(string $path, int $type): array
    {
        clearstatcache(true, $path); $s = @lstat($path);
        if (!is_array($s) || $s['uid'] !== $this->uid || ($s['mode'] & 0170000) !== $type
            || ($s['mode'] & 0077) !== 0 || is_link($path)
            || $type === 0100000 && $s['nlink'] !== 1) { throw new RuntimeException('PRIVATE_PATH_REQUIRED'); }
        return $s;
    }

    private function openLock(string $name): mixed
    {
        $path = $this->storage . '/' . $name;
        if (file_exists($path) || is_link($path)) { $this->privatePath($path, 0100000); }
        $h = @fopen($path, 'ce');
        if (!is_resource($h)) { throw new RuntimeException('LOCK_UNAVAILABLE'); }
        chmod($path, 0600);
        $s = $this->privatePath($path, 0100000); $held = fstat($h);
        if (!is_array($held) || $s['dev'] !== $held['dev'] || $s['ino'] !== $held['ino']) {
            fclose($h); throw new RuntimeException('LOCK_CHANGED');
        }
        return $h;
    }

    private function readJson(string $path): ?array
    {
        if (!file_exists($path) && !is_link($path)) { return null; }
        $s = $this->privatePath($path, 0100000);
        if ($s['size'] > 16384) { throw new RuntimeException('STATE_INVALID'); }
        $data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('STATE_INVALID'); }
        return $data;
    }

    private function writeJson(string $path, array $value): void
    {
        if (file_exists($path) || is_link($path)) { $this->privatePath($path, 0100000); }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        $h = @fopen($temporary, 'x');
        if (!is_resource($h)) { throw new RuntimeException('STATE_WRITE_FAILED'); }
        try {
            chmod($temporary, 0600);
            $raw = json_encode($value, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($h, $raw) !== strlen($raw) || !fflush($h) || !fsync($h)) { throw new RuntimeException('STATE_WRITE_FAILED'); }
            fclose($h); $h = null;
            if (!rename($temporary, $path)) { throw new RuntimeException('STATE_WRITE_FAILED'); }
        } finally { if (is_resource($h)) { fclose($h); } if (is_file($temporary)) { unlink($temporary); } }
    }
}
