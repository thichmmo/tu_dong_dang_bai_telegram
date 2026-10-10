<?php

declare(strict_types=1);

// Execute the real worker entry point against copied source, synthetic config,
// an offline SQLite fixture and MadelineProto doubles. Never read real config.
namespace danog\MadelineProto {
    final class Logger { public const FILE_LOGGER = 1; }
    final class Settings
    {
        public function __call(string $name, array $arguments): self { return $this; }
    }
    class RPCErrorException extends \RuntimeException
    {
        public function __construct(public string $rpc) { parent::__construct($rpc); }
    }
    class API
    {
        public const LOGGED_IN = 3;
        public const WAITING_PASSWORD = 2;
        public static string $mode = '';
        public static int $constructors = 0;
        public static int $loopCalls = 0;
        public static int $handlerStarts = 0;
        public static int $historyCalls = 0;
        public static int $forwardCalls = 0;
        public static array $constructorFullFlags = [];
        public static array $loopFullFlags = [];
        public \TelegramMirror\Tests\Worker\Messages $messages;
        public \TelegramMirror\Tests\Worker\Channels $channels;

        public function __construct(string $session = '', ?Settings $settings = null)
        {
            $this->messages = new \TelegramMirror\Tests\Worker\Messages();
            $this->channels = new \TelegramMirror\Tests\Worker\Channels();
            if ($session !== '') {
                ++self::$constructors;
                self::$constructorFullFlags[] = $_GET['MadelineSelfRestart'] ?? null;
                if (self::$mode === 'transient') {
                    throw new \RuntimeException('Offline bootstrap connection failed');
                }
                if (self::$mode === 'permanent') {
                    throw new RPCErrorException('SESSION_REVOKED');
                }
            }
        }

        public function getAuthorization(): int
        {
            return self::$mode === 'unauthenticated' ? self::WAITING_PASSWORD : self::LOGGED_IN;
        }
        public function getSelf(): array { return ['id' => 1234, 'bot' => false]; }
        public function refreshPeerCache(int ...$peers): void {}
        public function getInfo(int|string $peer): array
        {
            if (self::$mode === 'handler-transient') {
                throw new \RuntimeException('Offline onStart connection failed');
            }
            $target = $peer === '@test_target' || $peer === \TelegramMirror\Tests\Worker\TARGET;
            return ['type' => 'channel',
                'bot_api_id' => $target ? \TelegramMirror\Tests\Worker\TARGET : \TelegramMirror\Tests\Worker\SOURCE,
                'Chat' => ['broadcast' => true, 'left' => false,
                    'admin_rights' => ['post_messages' => $target]]];
        }
        public function getId(array $message): int { return \TelegramMirror\Tests\Worker\SOURCE; }
        public function logger(string $message): void {}
    }
    class SimpleEventHandler extends API
    {
        public static function startAndLoop(string $session, Settings $settings): void
        {
            ++API::$loopCalls;
            API::$loopFullFlags[] = $_GET['MadelineSelfRestart'] ?? null;
            if (API::$mode === 'loop-transient') {
                throw new \RuntimeException('Offline startAndLoop bootstrap failed before onStart');
            }
            ++API::$handlerStarts;
            $handler = new \TelegramMirror\MirrorHandler();
            $handler->onStart();
            $handler->drainQueue();
        }
    }
}

namespace danog\MadelineProto\EventHandler\Attributes {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Cron { public function __construct(public float $period) {} }
}

namespace danog\MadelineProto\RPCError {
    class RateLimitError extends \danog\MadelineProto\RPCErrorException
    {
        public function getWaitTimeLeft(): int { return 10; }
    }
}

namespace TelegramMirror\Tests\Worker {
    use danog\MadelineProto\API;
    use RuntimeException;
    use TelegramMirror\App;
    use TelegramMirror\Queue;
    use Throwable;

    const SOURCE = -1001234567890;
    const TARGET = -1002234567890;
    const MODES = ['transient', 'permanent', 'missing-session', 'unauthenticated',
        'competing', 'boot-failure', 'handler-transient', 'loop-transient', 'backoff', 'paused'];

    final class Channels
    {
        public function getMessages(int $channel,array $id):array
        {
            return ['messages'=>array_map(fn($i)=>['_'=>'message','id'=>$i,'date'=>123,'peer_id'=>['_'=>'peerChannel','channel_id'=>1234567890],'message'=>'offline post'], array_column($id,'id'))];
        }
    }
    final class Messages
    {
        public function getHistory(int $peer, int $limit): array
        {
            ++API::$historyCalls;
            return ['messages' => [['id' => 500]]];
        }
        public function forwardMessages(...$args):never { throw new RuntimeException('Forward RPC forbidden'); }
        public function sendMessage(int $peer,string $message,array $entities,int $random_id,bool $no_webpage,bool $invert_media):array
        {
            ++API::$forwardCalls;
            return ['_'=>'updateShortSentMessage','out'=>true,'id'=>1000];
        }
    }

    function removeFixture(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    removeFixture($path . '/' . $item);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    $mode = getenv('TELEGRAM_MIRROR_WORKER_TEST') ?: 'all';
    if ($mode === 'all') {
        $total = 0;
        foreach (MODES as $childMode) {
            $marker = __DIR__ . '/worker-result-' . bin2hex(random_bytes(8));
            $env = getenv();
            $env['TELEGRAM_MIRROR_WORKER_TEST'] = $childMode;
            $env['TELEGRAM_MIRROR_WORKER_RESULT'] = $marker;
            try {
                $process = proc_open([PHP_BINARY, __FILE__], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, null, $env);
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot start offline worker fixture.');
                }
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                $expectedExit = $childMode === 'competing' ? 0 : 1;
                $result = is_file($marker) ? file_get_contents($marker) : '';
                if ($exitCode !== $expectedExit) {
                    throw new RuntimeException('Offline worker ' . $childMode . ' exited '
                        . $exitCode . ', expected ' . $expectedExit . ': ' . $result . $stdout . $stderr);
                }
                ++$total;
                if (!preg_match('/^Worker ' . preg_quote($childMode, '/') . ': (\d+) assertions passed\.\n$/D', $result, $match)) {
                    throw new RuntimeException('Offline worker ' . $childMode . ' failed: ' . $result . $stdout . $stderr);
                }
                if (!str_contains($stdout, $result)) {
                    throw new RuntimeException('Offline worker ' . $childMode . ' did not complete its verification callback.');
                }
                ++$total;
                $total += (int) $match[1];
            } finally {
                if (is_file($marker)) { unlink($marker); }
            }
        }
        echo 'Worker bootstrap: ' . $total . " assertions passed.\n";
        exit(0);
    }
    if (!in_array($mode, MODES, true)) {
        throw new RuntimeException('Unknown offline worker fixture mode.');
    }

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

    $fixture = __DIR__ . '/worker-fixture-' . bin2hex(random_bytes(8));
    foreach ([$fixture, $fixture . '/src', $fixture . '/bin', $fixture . '/vendor', $fixture . '/storage'] as $path) {
        mkdir($path, 0700);
    }
    foreach (['App.php', 'Queue.php', 'StartupValidationException.php', 'MirrorHandler.php', 'Repost.php'] as $file) {
        copy(dirname(__DIR__) . '/src/' . $file, $fixture . '/src/' . $file);
    }
    copy(dirname(__DIR__) . '/bin/worker.php', $fixture . '/bin/worker.php');
    file_put_contents($fixture . '/vendor/autoload.php', "<?php\nrequire_once dirname(__DIR__) . '/src/Repost.php';\nrequire_once dirname(__DIR__) . '/src/MirrorHandler.php';\n");
    if ($mode !== 'boot-failure') {
        $config = [
            'source' => '@test_source', 'target' => '@test_target',
            'storage_dir' => $fixture . '/storage', 'api_id' => 123456,
            'api_hash' => str_repeat('a', 32),
            'album_settle_seconds' => 1, 'retry_base_seconds' => 10, 'retry_max_seconds' => 60,
        ];
        file_put_contents($fixture . '/config.php', '<?php return ' . var_export($config, true) . ';');
    }
    if ($mode !== 'missing-session') {
        file_put_contents($fixture . '/storage/telegram.session', 'Offline fixture; no credentials.');
    }
    require_once $fixture . '/src/Queue.php';
    $queue = new Queue($fixture . '/storage/queue.sqlite');
    foreach (['validated_at' => time(), 'initialized_at' => time() - 1000,
        'source_min_id' => 10, 'source_id' => SOURCE, 'target_id' => TARGET,
        'account_id' => 1234, 'worker_pid' => 99999999, 'worker_ready' => 1, 'worker_heartbeat_at' => time(),
        'startup_attempts' => 0, 'startup_retry_at' => 0, 'paused_reason' => ''] as $key => $value) {
        $queue->setMeta($key, (string) $value);
    }
    $queue->registerTargets(SOURCE, [TARGET], 10);
    $queue->activateRepost(SOURCE, [TARGET]);
    $queue->enqueue(SOURCE, ['id' => 11, 'date' => time()]);
    $originalRandomIds = $queue->pendingBatches(time())[0]['random_ids'];
    if ($mode === 'backoff') {
        $queue->setMeta('startup_attempts', '2');
        $queue->setMeta('startup_retry_at', (string) (time() + 60));
    } elseif ($mode === 'paused') {
        $queue->setMeta('paused_reason', 'CHAT_WRITE_FORBIDDEN');
    }
    $foreignLock = null;
    if ($mode === 'competing') {
        $foreignLock = fopen($fixture . '/storage/process.lock', 'c');
        flock($foreignLock, LOCK_EX | LOCK_NB);
    }
    API::$mode = $mode;
    if (in_array($mode, ['transient', 'loop-transient'], true)) {
        $_GET['MadelineSelfRestart'] = 'fixture-original';
    }
    $originalGet = $_GET;
    $worker = $fixture . '/bin/worker.php';
    $resultPath = getenv('TELEGRAM_MIRROR_WORKER_RESULT');
    $primaryReturned = false;
    register_shutdown_function(function () use ($mode, $fixture, $queue, $worker, $foreignLock, $resultPath, $originalRandomIds, $originalGet, &$primaryReturned): void {
        global $assertions;
        try {
            same(null, error_get_last(), 'Worker exits intentionally without an uncaught PHP error');
            same(false, $primaryReturned, 'Primary failing or competing entry point terminates rather than returns');
            same(API::$constructors === 0 ? [] : [1], API::$constructorFullFlags,
                'API authorization preflight runs with full-instance mode enabled');
            same(API::$loopCalls === 0 ? [] : [1], API::$loopFullFlags,
                'The forwarding loop runs with full-instance mode enabled');
            if ($mode !== 'handler-transient') {
                same($originalGet, $_GET, 'Thrown bootstrap failures restore the original full-instance flag scope');
            }
            same('10', $queue->getMeta('source_min_id'), 'Bootstrap failures cannot rebase the cutoff');
            same(1, $queue->stats()['pending'], 'Bootstrap failure preserves the pending post');
            same($originalRandomIds, $queue->pendingBatches(time())[0]['random_ids'], 'Bootstrap preserves delivery random IDs');
            same(0, API::$historyCalls, 'Bootstrap failure does not read source history');
            same(0, API::$forwardCalls, 'Bootstrap failure cannot forward any queued post');
            same(in_array($mode, ['competing', 'boot-failure'], true) ? '99999999' : (string) getmypid(),
                $queue->getMeta('worker_pid'), 'Only an owner replaces the worker PID before bootstrap');
            if (in_array($mode, ['transient', 'handler-transient', 'loop-transient'], true)) {
                same('1', $queue->getMeta('startup_attempts'), 'Bootstrap/onStart failure is recorded exactly once');
                check((int) $queue->getMeta('startup_retry_at') > time(), 'Transient bootstrap failure schedules backoff');
                same('', $queue->getMeta('paused_reason'), 'Transient bootstrap failure remains automatically recoverable');
                same('0', $queue->getMeta('worker_ready'), 'Failed bootstrap clears stale readiness');
                same('0', $queue->getMeta('worker_heartbeat_at'), 'Failed bootstrap clears stale heartbeat');
                if ($mode === 'transient') {
                    same(0, API::$loopCalls, 'Library constructor failure occurs before onStart');
                } elseif ($mode === 'loop-transient') {
                    same(1, API::$loopCalls, 'startAndLoop is reached once before its bootstrap failure');
                    same(0, API::$handlerStarts, 'Library bootstrap fails before the handler starts');
                    same(1, API::$constructors, 'Library bootstrap fails after authorization preflight');
                    check(str_contains($queue->getMeta('startup_error') ?? '', 'before onStart'),
                        'The library bootstrap error is recorded durably');
                } else {
                    same(1, API::$loopCalls, 'onStart exit is not caught and recorded again by worker');
                }
                App::releaseLock();
                $queue->setMeta('startup_retry_at', '0');
                API::$mode = 'recovered';
                // This recovery simulates the next process. PHP exit inside
                // onStart does not unwind finally blocks in the dying process.
                $_GET = $originalGet;
                require $worker;
                same($originalGet, $_GET, 'Healthy bootstrap completion restores the original full-instance flag scope');
                same('1', $queue->getMeta('worker_ready'), 'Healthy next bootstrap activates the worker');
                same((string) getmypid(), $queue->getMeta('worker_pid'), 'Recovered owner records its PID');
                same('0', $queue->getMeta('startup_attempts'), 'Recovered bootstrap clears failed attempts');
                same('10', $queue->getMeta('source_min_id'), 'Recovered bootstrap preserves the cutoff');
                same(0, API::$historyCalls, 'Recovered bootstrap does not read history again');
                same(1, $queue->stats()['forwarded'], 'Recovered worker drains the retained post');
            } elseif (in_array($mode, ['permanent', 'missing-session', 'unauthenticated'], true)) {
                check(str_starts_with($queue->getMeta('paused_reason') ?? '', 'STARTUP:'), 'Permanent bootstrap failure requires manual recovery');
                same('0', $queue->getMeta('startup_retry_at'), 'Permanent bootstrap failure does not automatically retry');
                same('0', $queue->getMeta('worker_ready'), 'Permanent bootstrap failure clears readiness');
                same(0, API::$loopCalls, 'Missing or unauthorized sessions cannot enter interactive startAndLoop');
            } else {
                same($mode === 'backoff' ? '2' : '0', $queue->getMeta('startup_attempts'), 'Early exit does not invent a bootstrap attempt');
                same(0, API::$constructors, 'Preboot, contention, pause and backoff exits make no Telegram constructor call');
                same(0, API::$loopCalls, 'Early exit does not enter the event loop');
                if ($mode === 'paused') {
                    same('CHAT_WRITE_FORBIDDEN', $queue->getMeta('paused_reason'), 'Worker bootstrap preserves existing permission pause');
                }
                if (in_array($mode, ['backoff', 'paused'], true)) {
                    same('0', $queue->getMeta('worker_ready'), 'Gated restart clears readiness left by an older process');
                }
            }
            $result = 'Worker ' . $mode . ': ' . $assertions . " assertions passed.\n";
            if ($resultPath !== false && $resultPath !== '') { file_put_contents($resultPath, $result); }
            echo $result;
        } catch (Throwable $error) {
            if ($resultPath !== false && $resultPath !== '') { file_put_contents($resultPath, 'FAIL: ' . $error->getMessage()); }
            throw $error;
        } finally {
            if (class_exists(App::class, false)) { App::releaseLock(); }
            if (is_resource($foreignLock)) { flock($foreignLock, LOCK_UN); fclose($foreignLock); }
            removeFixture($fixture);
        }
    });
    require $worker;
    $primaryReturned = true;
    throw new RuntimeException('Offline failing worker unexpectedly returned.');
}
