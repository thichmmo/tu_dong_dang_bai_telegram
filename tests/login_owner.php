<?php

declare(strict_types=1);

// Run the real login entry points with copied code and an offline API double.
// No production config, credentials, session, vendor files or network calls.
namespace danog\MadelineProto {
    final class Logger { public const FILE_LOGGER = 1; }
    final class Settings { public function __call(string $method, array $arguments): self { return $this; } }
    class RPCErrorException extends \RuntimeException
    {
        public function __construct(public string $rpc) { parent::__construct($rpc); }
    }
    final class API
    {
        public const LOGGED_IN = 3;
        public const WAITING_PASSWORD = 2;
        public const NOT_LOGGED_IN = 0;
        public static string $mode;
        public static array $fullFlags = [];
        public static int $startCalls = 0;
        public static int $identityCalls = 0;
        public static int $historyCalls = 0;
        public function __construct(string $session, Settings $settings)
        {
            self::$fullFlags[] = $_GET['MadelineSelfRestart'] ?? null;
            if (str_contains(self::$mode, 'failure')) { throw new RPCErrorException('AUTH_KEY_DUPLICATED'); }
        }
        public function start(): void { ++self::$startCalls; }
        public function updateSettings(Settings $settings): void {}
        public function getAuthorization(): int { return self::$mode === 'qr-2fa' ? self::WAITING_PASSWORD : self::LOGGED_IN; }
        public function fullGetSelf(): array { ++self::$identityCalls; return $this->getSelf(); }
        public function getSelf(): array { return ['id' => str_contains(self::$mode, 'mismatch') ? 9999 : 1234, 'bot' => false]; }
        public function refreshPeerCache(int ...$peers): void {}
        public function getInfo(int|string $peer): array
        {
            $target = $peer === '@test_target' || $peer === \TelegramMirror\Tests\LoginOwner\TARGET;
            $second = $peer === '@second_source' || $peer === \TelegramMirror\Tests\LoginOwner\SECOND_SOURCE;
            return ['type' => 'channel', 'bot_api_id' => $target
                ? \TelegramMirror\Tests\LoginOwner\TARGET : ($second ? \TelegramMirror\Tests\LoginOwner\SECOND_SOURCE : \TelegramMirror\Tests\LoginOwner\SOURCE),
                'Chat' => ['broadcast' => true, 'left' => false, 'admin_rights' => ['post_messages' => $target]]];
        }
    }
}

namespace TelegramMirror\Tests\LoginOwner {
    use danog\MadelineProto\API;
    use ReflectionClass;
    use RuntimeException;
    use TelegramMirror\App;
    use TelegramMirror\Queue;
    use TelegramMirror\WorkerSupervisor;
    use Throwable;

    const SOURCE = -1001234567890;
    const TARGET = -1002234567890;
    const SECOND_SOURCE = -1003234567890;
    const MODES = ['login', 'login-existing-flag', 'login-failure', 'login-mismatch',
        'qr', 'qr-legacy-flag', 'qr-failure', 'qr-mismatch', 'qr-2fa', 'qr-competing',
        'login-history-unknown', 'login-history-flood', 'login-history-session', 'login-disabled-primary',
        'login-disabled-primary-manual', 'login-disabled-primary-source-auth'];

    function removeFixture(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') { removeFixture($path . '/' . $item); }
            }
            rmdir($path);
        } elseif (is_file($path)) { unlink($path); }
    }
    function expectedExit(string $mode): int
    {
        if ($mode === 'qr-2fa') { return 2; }
        return str_contains($mode, 'failure') || str_contains($mode, 'mismatch') || $mode === 'qr-competing' ? 1 : 0;
    }
    $mode = getenv('TELEGRAM_MIRROR_LOGIN_OWNER_TEST') ?: 'all';
    if ($mode === 'all') {
        $total = 0;
        foreach (MODES as $childMode) {
            $marker = __DIR__ . '/login-owner-result-' . bin2hex(random_bytes(8));
            $env = getenv();
            $env['TELEGRAM_MIRROR_LOGIN_OWNER_TEST'] = $childMode;
            $env['TELEGRAM_MIRROR_LOGIN_OWNER_RESULT'] = $marker;
            try {
                $process = proc_open([PHP_BINARY, __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                if (!is_resource($process)) { throw new RuntimeException('Cannot run offline login owner fixture.'); }
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $exit = proc_close($process);
                $result = is_file($marker) ? file_get_contents($marker) : '';
                if ($exit !== expectedExit($childMode)) { throw new RuntimeException('Login owner ' . $childMode . ' exited ' . $exit . ': ' . $result . $stdout . $stderr); }
                ++$total;
                if (!preg_match('/^Login owner ' . preg_quote($childMode, '/') . ': (\d+) assertions passed\.\n$/D', $result, $match)
                    || !str_contains($stdout, $result)) { throw new RuntimeException('Login owner verification failed: ' . $result . $stdout . $stderr); }
                $total += 1 + (int) $match[1];
            } finally { if (is_file($marker)) { unlink($marker); } }
        }
        echo 'Login ownership: ' . $total . " assertions passed.\n";
        exit(0);
    }
    if (!in_array($mode, MODES, true)) { throw new RuntimeException('Unknown login ownership fixture.'); }
    $assertions = 0;
    function same(mixed $expected, mixed $actual, string $reason): void
    {
        global $assertions;
        ++$assertions;
        if ($expected !== $actual) { throw new RuntimeException('FAIL: ' . $reason); }
    }
    $fixture = __DIR__ . '/login-owner-fixture-' . bin2hex(random_bytes(8));
    foreach ([$fixture, $fixture . '/src', $fixture . '/bin', $fixture . '/vendor', $fixture . '/storage'] as $directory) { mkdir($directory, 0700); }
    foreach (['App.php', 'Queue.php', 'StartupValidationException.php', 'WorkerSupervisor.php'] as $file) { copy(dirname(__DIR__) . '/src/' . $file, $fixture . '/src/' . $file); }
    foreach (['login.php', 'login-qr.php'] as $file) { copy(dirname(__DIR__) . '/bin/' . $file, $fixture . '/bin/' . $file); }
    file_put_contents($fixture . '/vendor/autoload.php', '<?php // Offline fixture.');
    $config = ['source' => '@test_source', 'target' => '@test_target', 'storage_dir' => $fixture . '/storage',
        'api_id' => 123456, 'api_hash' => str_repeat('a', 32)];
    if (str_starts_with($mode, 'login-disabled-primary')) {
        $config['sources'] = [['source' => '@test_source', 'targets' => ['@test_target'], 'enabled' => false],
            ['source' => '@second_source', 'targets' => ['@test_target']]];
    }
    file_put_contents($fixture . '/config.php', '<?php return ' . var_export($config, true) . ';');
    file_put_contents($fixture . '/storage/telegram.session', 'Offline session sentinel; no credentials.');
    file_put_contents($fixture . '/storage/login-qr-state.json', '{"status":"fixture-existing"}');
    file_put_contents($fixture . '/storage/login-qr.svg', 'Offline image sentinel.');
    require_once $fixture . '/src/Queue.php';
    require_once $fixture . '/src/WorkerSupervisor.php';
    $queue = new Queue($fixture . '/storage/queue.sqlite');
    foreach (['source_id' => SOURCE, 'target_id' => TARGET, 'account_id' => 1234,
        'validated_at' => 100, 'initialized_at' => 99, 'source_min_id' => 10,
        'source_runtime_min_id' => 50, 'paused_reason' => $mode === 'login-disabled-primary-manual' ? 'SOURCE_ACCESS: CHANNEL_PRIVATE'
            : ($mode === 'login-disabled-primary-source-auth' ? 'SOURCE_ACCESS: AUTH_KEY_DUPLICATED' : 'AUTH_KEY_DUPLICATED'),
        'history_cursor' => 80, 'history_commit_pending' => 1, 'history_commit_activation' => 'fixture-activation',
        'history_required' => 1, 'source_enabled' => str_starts_with($mode, 'login-disabled-primary') ? 0 : 1,
        'last_history_error' => $mode === 'login-history-unknown' ? 'HISTORY_COMMIT_ACTIVATION_CHANGED'
            : ($mode === 'login-history-flood' ? 'FLOOD_WAIT_120' : ($mode === 'login-history-session' ? 'SESSION_EXPIRED' : 'AUTH_KEY_DUPLICATED')),
        'history_retry_at' => time() + 3600, 'history_failures' => 8,
        'last_history_checked_at' => 123, 'history_running' => 1, 'history_last_attempt_at' => 456,
        'global_forward_retry_at' => $mode === 'login-history-flood' ? time() + 7200 : 0,
        'forward_retry_at' => $mode === 'login-history-flood' ? time() + 1800 : 0,
    ] as $key => $value) { $queue->setMeta($key, (string)$value); }
    $historyMeta = [];
    foreach (['history_cursor', 'history_commit_pending', 'history_commit_activation', 'history_required',
        'source_enabled', 'last_history_error', 'history_retry_at', 'history_failures', 'last_history_checked_at',
        'history_running', 'history_last_attempt_at', 'global_forward_retry_at', 'forward_retry_at'] as $key) {
        $historyMeta[$key] = $queue->getMeta($key);
    }
    $queue->registerTargets(SOURCE, [TARGET], 10);
    $queue->activateRepost(SOURCE, [TARGET]);
    $queue->enqueue(SOURCE, ['id' => 11, 'date' => 100]);
    $queue->markSkipped([11], SOURCE, 'offline terminal row', TARGET);
    $queue->enqueue(SOURCE, ['id' => 12, 'date' => 100]);
    $database = (new ReflectionClass(Queue::class))->getProperty('database')->getValue($queue);
    $tables = ['queue_messages', 'queue_targets', 'queue_deliveries'];
    $ledger = [];
    foreach ($tables as $table) { $ledger[$table] = $database->query('SELECT * FROM ' . $table)->fetchAll(); }
    $database = null;
    $originalGet = ['fixture' => 'keep'];
    if (in_array($mode, ['login-existing-flag', 'login-failure', 'qr-legacy-flag', 'qr-failure'], true)) { $originalGet['MadelineSelfRestart'] = 'fixture-original'; }
    $_GET = $originalGet;
    API::$mode = $mode;
    $argv = [$fixture . '/bin/login-qr.php'];
    if ($mode === 'qr-legacy-flag') { $argv[] = '--full-instance'; }
    $foreignLock = null;
    if ($mode === 'qr-competing') { $foreignLock = fopen($fixture . '/storage/process.lock', 'c'); flock($foreignLock, LOCK_EX | LOCK_NB); }
    $resultPath = getenv('TELEGRAM_MIRROR_LOGIN_OWNER_RESULT');
    $returned = false;
    register_shutdown_function(static function () use ($mode, $fixture, &$queue, $tables, $ledger, $historyMeta, $originalGet, $foreignLock, $resultPath, &$returned): void {
        global $assertions;
        try {
            same(null, error_get_last(), 'Login exits without an uncaught PHP error');
            same($mode === 'qr-competing' ? [] : [1], API::$fullFlags, 'Each lock owner opens one full foreground API by default');
            same($originalGet, $_GET, 'Login restores prior full-instance scope on success and constructor failure');
            same('10', $queue->getMeta('source_min_id'), 'Login preserves the historical cutoff');
            same('50', $queue->getMeta('source_runtime_min_id'), 'Login preserves the activation cutoff');
            same('99', $queue->getMeta('initialized_at'), 'Login preserves initial activation time');
            same((string)SOURCE, $queue->getMeta('source_id'), 'Login preserves source binding');
            same((string)TARGET, $queue->getMeta('target_id'), 'Login preserves destination binding');
            same('1234', $queue->getMeta('account_id'), 'Login preserves the existing account binding');
            $database = (new ReflectionClass(Queue::class))->getProperty('database')->getValue($queue);
            foreach ($tables as $table) { same($ledger[$table], $database->query('SELECT * FROM ' . $table)->fetchAll(), 'Login preserves terminal rows, pending deliveries and stable random IDs in ' . $table); }
            $database = null;
            $successful = expectedExit($mode) === 0;
            same($mode === 'login-disabled-primary-manual' ? 'SOURCE_ACCESS: CHANNEL_PRIVATE' : ($successful ? '' : 'AUTH_KEY_DUPLICATED'),
                $queue->getMeta('paused_reason'), 'Only successful same-account validation clears the invalid-session pause and preserves unrelated inactive-source pauses');
            $repairedHistory = $successful && !in_array($mode, ['login-history-unknown', 'login-history-flood'], true);
            foreach ($historyMeta as $key => $value) {
                $expected = $value;
                if ($repairedHistory && $key === 'last_history_error') { $expected = ''; }
                elseif ($repairedHistory && in_array($key, ['history_retry_at', 'history_failures', 'last_history_checked_at', 'history_running', 'history_last_attempt_at'], true)) { $expected = '0'; }
                same($expected, $queue->getMeta($key), 'Login repairs only finite auth history state and preserves unrelated state: ' . $key);
            }
            $database = (new ReflectionClass(Queue::class))->getProperty('database')->getValue($queue);
            $metadata = $database->query('SELECT key, value FROM queue_metadata')->fetchAll(\PDO::FETCH_KEY_PAIR);
            $database = null;
            $decision = WorkerSupervisor::decision(['metadata' => $metadata, 'sources' => [$metadata]], null, [], time());
            same($successful ? (in_array($mode, ['login-history-unknown', 'login-disabled-primary-manual'], true) ? 'blocked'
                : ($mode === 'login-history-flood' ? 'deferred' : 'start')) : 'blocked', $decision['action'],
                'Successful same-account login permits a start after auth repair while unrelated rate gates stay effective');
            same(str_starts_with($mode, 'login') && !str_contains($mode, 'failure') ? 1 : 0, API::$startCalls, 'Only interactive login invokes start');
            same(str_starts_with($mode, 'login') && $successful, $returned, 'QR helpers terminate; a successful terminal login returns normally');
            if (str_starts_with($mode, 'qr')) {
                $state = json_decode(file_get_contents($fixture . '/storage/login-qr-state.json'), true, 512, JSON_THROW_ON_ERROR);
                $expectedState = $mode === 'qr-competing' ? 'fixture-existing' : ($mode === 'qr-2fa' ? 'needs_2fa' : ($successful ? 'success' : 'failed'));
                same($expectedState, $state['status'], 'QR state reports a finite status');
                same($mode === 'qr-competing', is_file($fixture . '/storage/login-qr.svg'), 'Only the lock owner removes its private QR image');
                if ($mode === 'qr-failure') { same('rpc_AUTH_KEY_DUPLICATED', $state['failure_code'], 'QR constructor failures expose only an allowed code'); }
                if ($mode === 'qr-mismatch') { same('storage_binding_mismatch', $state['failure_code'], 'Different-account login retains a finite binding error'); }
                if ($mode !== 'qr-competing') { same(0600, fileperms($fixture . '/storage/login-qr-state.json') & 0777, 'QR login state stays private'); }
            }
            $result = 'Login owner ' . $mode . ': ' . $assertions . " assertions passed.\n";
            if ($resultPath !== false && $resultPath !== '') { file_put_contents($resultPath, $result); }
            echo $result;
        } catch (Throwable $error) {
            if ($resultPath !== false && $resultPath !== '') { file_put_contents($resultPath, 'FAIL: ' . $error->getMessage()); }
            throw $error;
        } finally {
            if (class_exists(App::class, false)) {
                App::releaseLock();
                $reflection = new ReflectionClass(App::class);
                $reflection->getProperty('queue')->setValue(null, null);
                $reflection->getProperty('sourceQueues')->setValue(null, []);
            }
            $queue = null;
            if (is_resource($foreignLock)) { flock($foreignLock, LOCK_UN); fclose($foreignLock); }
            removeFixture($fixture);
        }
    });
    require $fixture . '/bin/' . (str_starts_with($mode, 'qr') ? 'login-qr.php' : 'login.php');
    $returned = true;
}
