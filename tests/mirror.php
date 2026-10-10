<?php

declare(strict_types=1);

// Offline integration doubles: this script never loads vendor/autoload.php,
// reads config.php, opens a Telegram session, or makes a network request.
namespace danog\MadelineProto {
    class API
    {
        public const LOGGED_IN = 4;
    }

    class RPCErrorException extends \RuntimeException
    {
        public function __construct(public string $rpc)
        {
            parent::__construct($rpc);
        }
    }

    class SimpleEventHandler
    {
        public \TelegramMirror\Tests\Mirror\FakeMessages $messages;
        public \TelegramMirror\Tests\Mirror\FakeChannels $channels;
        public int $authorization = API::LOGGED_IN;
        public array|false $self = ['id' => 7654321, 'bot' => false];
        public array $infos = [];
        public array $refreshCalls = [];
        public array $logs = [];
        public ?\Throwable $refreshError = null;
        public array $refreshErrors = [];

        public function __construct()
        {
            $this->messages = new \TelegramMirror\Tests\Mirror\FakeMessages();
            $this->channels = new \TelegramMirror\Tests\Mirror\FakeChannels();
        }

        public function getAuthorization(): int
        {
            return $this->authorization;
        }

        public function getSelf(): array|false
        {
            return $this->self;
        }

        public function getInfo(int|string $peer): array
        {
            return $this->infos[$peer] ?? throw new \RuntimeException('Unknown test peer');
        }

        public function refreshPeerCache(int ...$peers): void
        {
            $this->refreshCalls[] = $peers;
            foreach ($peers as $peer) { if (isset($this->refreshErrors[$peer])) { throw $this->refreshErrors[$peer]; } }
            if ($this->refreshError !== null) {
                throw $this->refreshError;
            }
        }

        public function getId(array $message): int
        {
            $peer = $message['peer_id'] ?? $message;
            return match ($peer['_'] ?? '') {
                'peerChannel' => -1000000000000 - (int) $peer['channel_id'],
                'peerChat' => -(int) $peer['chat_id'],
                'peerUser' => (int) $peer['user_id'],
                default => throw new \RuntimeException('Unexpected test peer shape'),
            };
        }

        public function logger(string $message): void
        {
            $this->logs[] = $message;
        }

        public function getFileInfo(array $media): array
        {
            $photo = $media['photo'] ?? (($media['_'] ?? '') === 'photo' ? $media : null);
            $file = $photo ?? $media['document'];
            $input = ['_' => $photo !== null ? 'inputPhoto' : 'inputDocument',
                'id'=>$file['id'], 'access_hash'=>$file['access_hash'], 'file_reference'=>$file['file_reference']];
            return ['InputMedia'=>['_'=>$photo !== null ? 'inputMediaPhoto' : 'inputMediaDocument','id'=>$input], 'InputPhoto'=>$input];
        }

        public function dispatch(array $update): bool
        {
            if (($update['_']??'')==='updateNewChannelMessage') { $this->channels->fixtures[$update['message']['id']]=$update['message']; }
            $method = 'on' . ucfirst($update['_']);
            if (!method_exists($this, $method)) {
                return false;
            }
            $this->{$method}($update);
            return true;
        }
    }
}

namespace danog\MadelineProto\EventHandler\Attributes {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Cron
    {
        public function __construct(public float $period)
        {
        }
    }
}

namespace danog\MadelineProto\RPCError {
    class RateLimitError extends \danog\MadelineProto\RPCErrorException
    {
        public function __construct(string $rpc, private int $wait)
        {
            parent::__construct($rpc);
        }

        public function getWaitTimeLeft(): int
        {
            return $this->wait;
        }
    }
}

namespace TelegramMirror\Tests\Mirror {
    use danog\MadelineProto\RPCError\RateLimitError;
    use danog\MadelineProto\RPCErrorException;
    use ReflectionClass;
    use RuntimeException;
    use TelegramMirror\App;
    use TelegramMirror\MirrorHandler;
    use TelegramMirror\Queue;
    use Throwable;

    require_once dirname(__DIR__) . '/src/Queue.php';
    require_once dirname(__DIR__) . '/src/App.php';
    require_once dirname(__DIR__) . '/src/Repost.php';
    require_once dirname(__DIR__) . '/src/MirrorHandler.php';

    const SOURCE = -1001234567890;
    const TARGET = -1002234567890;
    const SOURCE_RAW = 1234567890;
    const TARGET_RAW = 2234567890;
    const SECOND_TARGET = -1003234567890;
    const SECOND_TARGET_RAW = 3234567890;

    final class FakeChannels
    {
        public array $fixtures = [];
        public array $calls = [];
        public function getMessages(int $channel, array $id): array
        {
            $this->calls[] = compact('channel','id');
            $id = array_column($id, 'id');
            $messages = [];
            foreach ($id as $value) {
                $messages[] = $this->fixtures[$value] ?? post($value)['message'];
            }
            return ['_'=>'messages.channelMessages','messages'=>array_reverse($messages)];
        }
    }

    final class FakeMessages
    {
        public array $historyCalls = [];
        public array $repostCalls = [];
        public array $history = ['messages' => [['id' => 100]]];
        public ?Throwable $historyError = null;
        public ?Throwable $nextError = null;
        public ?int $acknowledgeCount = null;
        public ?array $ackResponse = null;
        public array $targetErrors = [];
        public array $targetAcknowledgements = [];
        public ?\Closure $beforeResponse = null;

        public function getHistory(int $peer, int $limit): array
        {
            $this->historyCalls[] = compact('peer', 'limit');
            if ($this->historyError !== null) {
                throw $this->historyError;
            }
            return $this->history;
        }

        public function forwardMessages(...$arguments): never { throw new RuntimeException('FORWARD_RPC_FORBIDDEN'); }
        public function sendMessage(int $peer,string $message,array $entities,int $random_id,bool $no_webpage,bool $invert_media):array
        {
            return $this->respond([$message],[$random_id],$peer, ['method'=>'sendMessage','message'=>$message,'entities'=>$entities,'no_webpage'=>$no_webpage,'invert_media'=>$invert_media]);
        }
        public function sendMedia(int $peer,array $media,string $message,array $entities,int $random_id,bool $invert_media):array
        {
            return $this->respond([$message],[$random_id],$peer, ['method'=>'sendMedia','media'=>$media,'message'=>$message,'entities'=>$entities,'invert_media'=>$invert_media]);
        }
        public function sendMultiMedia(int $peer,array $multi_media,bool $invert_media):array
        {
            return $this->respond(array_column($multi_media,'message'),array_column($multi_media,'random_id'),$peer,['method'=>'sendMultiMedia','multi_media'=>$multi_media,'invert_media'=>$invert_media]);
        }
        private function respond(array $captions,array $random_id,int $to_peer,array $raw):array
        {
            $id = array_map(static fn(string $caption):int => (int)$caption, $captions);
            $from_peer = SOURCE;
            $this->repostCalls[] = $raw + compact('from_peer','id','random_id','to_peer');
            if (isset($this->targetErrors[$to_peer])) {
                $error = $this->targetErrors[$to_peer];
                unset($this->targetErrors[$to_peer]);
                throw $error;
            }
            if ($this->nextError !== null) {
                $error = $this->nextError;
                $this->nextError = null;
                throw $error;
            }
            if ($this->ackResponse !== null) { return $this->ackResponse; }
            $count = $this->targetAcknowledgements[$to_peer] ?? $this->acknowledgeCount;
            $confirmedIds = $count === null ? $random_id : array_slice($random_id, 0, $count);
            if ($this->beforeResponse !== null) { ($this->beforeResponse)(compact('from_peer', 'id', 'random_id', 'to_peer')); }
            return ['_' => 'updates', 'updates' => array_map(
                fn (int $randomId): array => ['_' => 'updateMessageID', 'random_id' => $randomId, 'id' => 9000 + $id[array_search($randomId,$random_id,true)]],
                $confirmedIds,
            )];
        }
    }

    $assertions = 0;
    $fixtures = [];

    function check(bool $condition, string $description): void
    {
        global $assertions;
        ++$assertions;
        if (!$condition) {
            throw new RuntimeException('FAIL: ' . $description);
        }
    }

    function same(mixed $expected, mixed $actual, string $description): void
    {
        check($expected === $actual, $description . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }

    function fails(callable $operation, string $messageFragment, string $description): void
    {
        try {
            $operation();
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), $messageFragment), $description . ': ' . $error->getMessage());
            return;
        }
        check(false, $description . ': no exception was thrown');
    }

    function injectApp(array $config, ?Queue $queue): void
    {
        $reflection = new ReflectionClass(App::class);
        $reflection->getProperty('config')->setValue(null, $config);
        $reflection->getProperty('queue')->setValue(null, $queue);
    }

    /** @return array{Queue, MirrorHandler, string} */
    function fixture(bool $login = false, bool $start = false, bool $protected = false): array
    {
        global $fixtures;
        App::releaseLock();
        $directory = __DIR__ . '/mirror-fixture-' . bin2hex(random_bytes(6));
        $fixtures[] = $directory;
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create offline test storage.');
        }
        file_put_contents($directory . '/telegram.session', 'Offline fixture; no Telegram credentials.');
        $queue = new Queue($directory . '/queue.sqlite');
        injectApp([
            'source' => '@test_source',
            'target' => '@test_target',
            'storage_dir' => $directory,
            'album_settle_seconds' => 1,
            'retry_base_seconds' => 10,
            'retry_max_seconds' => 60,
        ], $queue);
        if (!App::acquireLock()) { throw new RuntimeException('Offline fixture cannot acquire its private process lock.'); }
        App::enableWorkerEvents();
        $source = [
            'type' => 'channel', 'bot_api_id' => SOURCE,
            'Chat' => ['broadcast' => true, 'left' => false, 'noforwards' => $protected],
        ];
        $target = [
            'type' => 'channel', 'bot_api_id' => TARGET,
            'Chat' => ['broadcast' => true, 'left' => false, 'admin_rights' => ['post_messages' => true]],
        ];
        $handler = new MirrorHandler();
        $handler->infos = ['@test_source' => $source, SOURCE => $source,
            '@test_target' => $target, TARGET => $target];
        if ($login) {
            App::initialize($handler, App::validateAccount($handler));
        }
        if ($start) {
            $handler->onStart();
        }
        return [$queue, $handler, $directory];
    }

    function post(int $id, int $channel = SOURCE_RAW, array $fields = []): array
    {
        return ['_' => 'updateNewChannelMessage', 'message' => $fields + [
            '_' => 'message', 'id' => $id, 'date' => time(),
            'peer_id' => ['_' => 'peerChannel', 'channel_id' => $channel],
            'message' => (string)$id,
            'media' => ['_'=>'messageMediaPhoto','photo'=>['_'=>'photo','id'=>$id,'access_hash'=>-987654321,'file_reference'=>"\x00\xff"]],
        ]];
    }

    function makeRetryDue(Queue $queue, array $ids, int $targetId = TARGET): void
    {
        // Advance a scheduled delivery through the public queue API without
        // sleeping. Stable random IDs must survive this schedule adjustment.
        $queue->retryBatch($ids, SOURCE, 'Offline test: scheduled retry became due', time() - 1, $targetId);
    }

    function cleanupFixtures(): void
    {
        global $fixtures;
        App::releaseLock();
        injectApp([], null);
        foreach ($fixtures as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($directory . '/' . $entry);
                }
            }
            rmdir($directory);
        }
        $fixtures = [];
    }

    function recoveredWorker(MirrorHandler $previous): MirrorHandler
    {
        if (!App::workerEventsAllowed()) {
            if (!App::acquireLock()) { throw new RuntimeException('Offline recovered worker cannot acquire its private lock.'); }
            App::enableWorkerEvents();
        }
        $worker = new MirrorHandler();
        $worker->infos = $previous->infos;
        $worker->self = $previous->self;
        return $worker;
    }

    function configureSecondTarget(Queue $queue, MirrorHandler $handler, bool $allowed = true): void
    {
        $config = App::config();
        $config['targets'] = ['@test_target', '@test_target_b'];
        injectApp($config, $queue);
        $info = ['type' => 'channel', 'bot_api_id' => SECOND_TARGET,
            'Chat' => ['broadcast' => true, 'left' => false, 'admin_rights' => ['post_messages' => $allowed]]];
        $handler->infos['@test_target_b'] = $handler->infos[SECOND_TARGET] = $info;
    }

    function disableAlbumDelay(Queue $queue): void
    {
        $config = App::config();
        $config['album_settle_seconds'] = 0;
        injectApp($config, $queue);
    }

    /** Terminate after a synthetic server accepts A, before the local ACK commit. */
    function deliveryCrashMode(): never
    {
        [$queue, $handler, $directory] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        disableAlbumDelay($queue);
        $handler->dispatch(post(101, SOURCE_RAW, ['grouped_id' => 777]));
        $handler->dispatch(post(102, SOURCE_RAW, ['grouped_id' => 777]));
        $config = App::config();
        $a = $queue->pendingBatches(time(), 0, TARGET)[0];
        $b = $queue->pendingBatches(time(), 0, SECOND_TARGET)[0];
        $returned = false;
        $resultPath = getenv('TELEGRAM_MIRROR_DELIVERY_RESULT');
        register_shutdown_function(function () use ($handler, $directory, $config, $a, $b, $resultPath, &$returned): void {
            global $assertions;
            try {
                check(!$returned, 'Accepted forward genuinely terminates before returning to the handler');
                $accepted = json_decode((string) file_get_contents($directory . '/accepted.json'), true, 8, JSON_THROW_ON_ERROR);
                same(TARGET, $accepted['to_peer'], 'Synthetic server accepted only destination A before the crash');
                same($a['random_ids'], $accepted['random_id'], 'Accepted request contains A persisted random IDs');
                $reopened = new Queue($directory . '/queue.sqlite');
                injectApp($config, $reopened);
                same(0, $reopened->stats(TARGET)['forwarded'], 'Crash cannot commit a local success for A');
                same(2, $reopened->stats(TARGET)['pending'], 'Accepted but uncommitted A album survives reopening');
                same(2, $reopened->stats(SECOND_TARGET)['pending'], 'Unsent B album survives reopening independently');
                same(0, $reopened->pendingBatches(time(), 0, TARGET)[0]['attempts'], 'Crash cannot invent a completed retry attempt');
                $recovered = recoveredWorker($handler);
                $recovered->onStart();
                same(0, count($recovered->messages->historyCalls), 'Recovery does not reset activation cutoffs');
                $recovered->drainQueue();
                same([TARGET, SECOND_TARGET], array_column($recovered->messages->repostCalls, 'to_peer'), 'Recovery attempts both pending destinations');
                same($a['random_ids'], $recovered->messages->repostCalls[0]['random_id'], 'A reconciliation uses exactly the previously accepted random IDs');
                same($b['random_ids'], $recovered->messages->repostCalls[1]['random_id'], 'B retains its independent IDs across the A crash');
                check($a['random_ids'] !== $b['random_ids'], 'Different destinations never share delivery random IDs');
                same(2, $reopened->stats(TARGET)['forwarded'], 'Synthetic acknowledgement finally commits A');
                same(2, $reopened->stats(SECOND_TARGET)['forwarded'], 'Synthetic acknowledgement finally commits B');
                $result = 'Delivery crash: ' . $assertions . " assertions passed.\n";
                if ($resultPath !== false && $resultPath !== '') { file_put_contents($resultPath, $result); }
                echo $result;
            } catch (Throwable $error) {
                if ($resultPath !== false && $resultPath !== '') { file_put_contents($resultPath, 'FAIL: ' . $error->getMessage()); }
                throw $error;
            } finally { cleanupFixtures(); }
        });
        $handler->messages->beforeResponse = function (array $accepted) use ($directory): never {
            file_put_contents($directory . '/accepted.json', json_encode($accepted, JSON_THROW_ON_ERROR));
            exit(1);
        };
        $handler->drainQueue();
        $returned = true;
        throw new RuntimeException('Synthetic accepted-forward crash did not terminate.');
    }

    /**
     * Run genuine process-exit paths in isolated invocations. A shutdown check
     * inspects durable state, so production code needs no exit mocking hook.
     * Native PHP intentionally exits 1; the explicit PASS marker is required.
     */
    function startupMode(string $mode): never
    {
        if (!in_array($mode, ['transient', 'target-transient', 'permanent', 'paused', 'backoff', 'missing-baseline',
            'state-unavailable', 'rpc-permanent', 'flood-wait'], true)) {
            throw new RuntimeException('Unknown offline startup test mode.');
        }
        [$queue, $handler] = fixture(true, !in_array($mode, ['backoff', 'missing-baseline'], true));
        if ($mode !== 'backoff') {
            if ($mode === 'missing-baseline') {
                $queue->enqueue(SOURCE, post(101)['message']);
            } else {
                $handler->dispatch(post(101));
            }
        }
        if ($mode === 'transient') {
            $handler->refreshError = new RuntimeException('Offline transient connection timeout');
        } elseif ($mode === 'target-transient') {
            configureSecondTarget($queue, $handler);
            $handler->refreshErrors[SECOND_TARGET] = new RuntimeException('Offline target connection timeout');
        } elseif ($mode === 'permanent') {
            $handler->infos[SOURCE]['Chat']['broadcast'] = false;
        } elseif ($mode === 'paused') {
            $handler->messages->nextError = new RPCErrorException('AUTH_KEY_UNREGISTERED');
            $handler->drainQueue();
        } elseif ($mode === 'backoff') {
            $queue->setMeta('startup_retry_at', (string) (time() + 60));
            $queue->setMeta('startup_error', 'Offline earlier connection timeout');
            $queue->setMeta('startup_attempts', '1');
        } elseif ($mode === 'state-unavailable') {
            injectApp(App::config(), null);
        } elseif ($mode === 'rpc-permanent') {
            $handler->refreshError = new RPCErrorException('CHANNEL_PRIVATE');
        } elseif ($mode === 'flood-wait') {
            $handler->refreshError = new RateLimitError('FLOOD_WAIT_100', 100);
        }
        $refreshCount = count($handler->refreshCalls);
        $historyCount = count($handler->messages->historyCalls);
        $returned = false;
        $resultPath = getenv('TELEGRAM_MIRROR_STARTUP_RESULT');
        register_shutdown_function(function () use ($mode, $queue, $handler, $refreshCount, $historyCount, $resultPath, &$returned): void {
            global $assertions;
            try {
                check(!$returned, 'Failed or gated startup terminates the worker process');
                if ($mode === 'transient' || $mode === 'target-transient') {
                    same('', $queue->getMeta('paused_reason'), 'Transient startup failure does not require manual login');
                    check(str_contains($queue->getMeta('startup_error') ?? '', 'connection timeout'), 'Transient startup reason is durable');
                    same('1', $queue->getMeta('startup_attempts'), 'Transient startup records its attempt');
                    check((int) $queue->getMeta('startup_retry_at') > time(), 'Transient startup schedules a future retry');
                    same('0', $queue->getMeta('worker_ready'), 'Failed startup cannot report a ready worker');
                    same('0', $queue->getMeta('worker_heartbeat_at'), 'Failed startup cannot report a fresh delivery heartbeat');
                    same(1, $queue->stats()['pending'], 'Transient startup preserves pending posts');
                    same('100', $queue->getMeta('source_min_id'), 'Transient startup preserves the original baseline');
                    same($historyCount, count($handler->messages->historyCalls), 'Failed restart does not re-read source history');
                    $queue->setMeta('startup_retry_at', '0');
                    $recovered = recoveredWorker($handler);
                    $recovered->onStart();
                    same('1', $queue->getMeta('worker_ready'), 'Next healthy startup becomes ready');
                    same('', $queue->getMeta('startup_error'), 'Healthy startup clears transient error');
                    same('0', $queue->getMeta('startup_attempts'), 'Healthy startup resets startup attempts');
                    same('0', $queue->getMeta('startup_retry_at'), 'Healthy startup clears retry deadline');
                    same($mode === 'target-transient' ? 1 : 0, count($recovered->messages->historyCalls), 'Recovered startup captures only an unactivated target cutoff');
                    $recovered->drainQueue();
                    same(1, $queue->stats()['forwarded'], 'Recovered worker drains the retained post');
                    if ($mode === 'target-transient') {
                        check($queue->hasTarget(SECOND_TARGET), 'Recovered startup registers B only after a trustworthy activation');
                        same(0, $queue->stats(SECOND_TARGET)['total'], 'Recovery does not backfill B with already queued A history');
                    }
                } elseif ($mode === 'permanent') {
                    check(str_starts_with($queue->getMeta('paused_reason') ?? '', 'STARTUP:'), 'Permanent startup failure requires manual recovery');
                    check(str_contains($queue->getMeta('startup_error') ?? '', 'channel phát tin'), 'Permanent startup reason describes invalid source');
                    same('0', $queue->getMeta('startup_retry_at'), 'Permanent startup failure does not retry automatically');
                    same('0', $queue->getMeta('worker_ready'), 'Permanent startup failure clears readiness');
                    same(1, $queue->stats()['pending'], 'Permanent startup failure retains pending posts');
                    same('100', $queue->getMeta('source_min_id'), 'Permanent startup failure retains baseline');
                    $handler->infos[SOURCE]['Chat']['broadcast'] = true;
                    App::initialize($handler, App::validateAccount($handler));
                    same('', $queue->getMeta('paused_reason'), 'Successful login clears startup permission pause');
                    $recovered = recoveredWorker($handler);
                    $recovered->onStart();
                    $recovered->drainQueue();
                    same(1, $queue->stats()['forwarded'], 'Login recovery drains retained posts');
                } elseif ($mode === 'paused') {
                    same('AUTH_KEY_UNREGISTERED', $queue->getMeta('paused_reason'), 'Startup preserves an account authorization pause');
                    same($refreshCount, count($handler->refreshCalls), 'Paused startup makes no permission RPC calls');
                    same($historyCount, count($handler->messages->historyCalls), 'Paused startup makes no history request');
                    same(1, count($handler->messages->repostCalls), 'Paused startup makes no new forward request');
                    same(1, $queue->stats()['pending'], 'Paused startup retains queued posts');
                    same('100', $queue->getMeta('source_min_id'), 'Paused startup retains its baseline');
                } elseif ($mode === 'backoff') {
                    same($refreshCount, count($handler->refreshCalls), 'Startup before retry deadline makes no permission RPC calls');
                    same(0, count($handler->messages->historyCalls), 'Startup backoff makes no history request');
                    same('', $queue->getMeta('paused_reason'), 'Startup backoff does not become a manual pause');
                    same('1', $queue->getMeta('startup_attempts'), 'Startup backoff does not count another failed network attempt');
                    same('Offline earlier connection timeout', $queue->getMeta('startup_error'), 'Startup backoff preserves its original error');
                } elseif ($mode === 'rpc-permanent') {
                    same('STARTUP: CHANNEL_PRIVATE', $queue->getMeta('paused_reason'), 'Permanent startup RPC error requires manual recovery');
                    same('CHANNEL_PRIVATE', $queue->getMeta('startup_error'), 'Permanent startup RPC reason is retained');
                    same('0', $queue->getMeta('startup_retry_at'), 'Permanent startup RPC error does not retry automatically');
                    same(1, $queue->stats()['pending'], 'Permanent startup RPC error retains pending posts');
                    same('100', $queue->getMeta('source_min_id'), 'Permanent startup RPC error retains baseline');
                } elseif ($mode === 'flood-wait') {
                    same('', $queue->getMeta('paused_reason'), 'Startup flood wait does not require manual login');
                    same('FLOOD_WAIT_100', $queue->getMeta('startup_error'), 'Startup flood wait reason is retained');
                    check((int) $queue->getMeta('startup_retry_at') >= time() + 99,
                        'Startup respects Telegram flood wait beyond the configured sixty-second retry cap');
                    same('0', $queue->getMeta('worker_ready'), 'Startup flood wait clears worker readiness');
                    same(1, $queue->stats()['pending'], 'Startup flood wait retains pending posts');
                    same('100', $queue->getMeta('source_min_id'), 'Startup flood wait retains baseline');
                } elseif ($mode === 'missing-baseline') {
                    check(str_contains($queue->getMeta('paused_reason') ?? '', 'thiếu mốc khởi tạo'),
                        'Queue without a baseline cannot silently discard existing posts');
                    same(1, $queue->stats()['pending'], 'Missing-baseline failure preserves queued posts');
                    same(null, $queue->getMeta('source_min_id'), 'Missing-baseline failure cannot invent a baseline');
                    same(0, count($handler->messages->historyCalls), 'Missing-baseline failure makes no history request');
                } else {
                    same($refreshCount, count($handler->refreshCalls), 'Unavailable startup state makes no permission RPC calls');
                    same($historyCount, count($handler->messages->historyCalls), 'Unavailable startup state makes no history request');
                    same(1, $queue->stats()['pending'], 'Unavailable startup state does not delete pending disk posts');
                    same('100', $queue->getMeta('source_min_id'), 'Unavailable startup state preserves disk baseline');
                }
                $result = 'Startup ' . $mode . ': ' . $assertions . " assertions passed.\n";
                // The WASM CLI can discard captured stdout when exit(1) runs.
                // Its external runner supplies, reads and removes this marker.
                if ($resultPath !== false && $resultPath !== '') {
                    file_put_contents($resultPath, $result);
                }
                echo $result;
            } catch (Throwable $error) {
                if ($resultPath !== false && $resultPath !== '') {
                    file_put_contents($resultPath, 'FAIL: ' . $error->getMessage() . "\n");
                }
                throw $error;
            } finally {
                cleanupFixtures();
            }
        });
        $handler->onStart();
        $returned = true;
        throw new RuntimeException('Expected startup to terminate the worker process.');
    }

    $startupTest = getenv('TELEGRAM_MIRROR_STARTUP_TEST');
    if ($startupTest !== false && $startupTest !== '') {
        startupMode($startupTest);
    }
    if (getenv('TELEGRAM_MIRROR_DELIVERY_TEST') === 'accepted-before-commit') { deliveryCrashMode(); }

    try {
        [$queue, $handler] = fixture();
        $binding = App::validateAccount($handler);
        same([7654321, SOURCE, TARGET, false], [$binding['account_id'], $binding['source_id'], $binding['target_id'],
            $binding['source_protected']], 'Account validation resolves canonical peer identities');
        same([[SOURCE], [TARGET]], $handler->refreshCalls, 'Validation refreshes source and destination independently');
        $handler->authorization = 0;
        fails(fn () => App::validateAccount($handler), 'chưa đăng nhập', 'Logged-out session is rejected');
        $handler->authorization = \danog\MadelineProto\API::LOGGED_IN;
        $handler->self = ['id' => 7654321, 'bot' => true];
        fails(fn () => App::validateAccount($handler), 'không dùng bot', 'Bot accounts are rejected');

        [$queue, $handler] = fixture();
        $handler->infos[SOURCE]['Chat']['left'] = true;
        fails(fn () => App::validateAccount($handler), 'tham gia channel nguồn', 'Source membership is required after peer refresh');
        [$queue, $handler] = fixture();
        $handler->infos[SOURCE]['Chat']['broadcast'] = false;
        fails(fn () => App::validateAccount($handler), 'channel phát tin', 'Source groups are rejected');
        [$queue, $handler] = fixture();
        $handler->infos[TARGET]['type'] = 'supergroup';
        check(str_contains(App::validateAccount($handler)['targets'][TARGET]['error'], 'channel phát tin'), 'Destination groups are individually paused');
        [$queue, $handler] = fixture();
        $handler->infos[TARGET]['Chat']['admin_rights']['post_messages'] = false;
        check(str_contains(App::validateAccount($handler)['targets'][TARGET]['error'], 'Post Messages'), 'Stale cached admin rights cannot bypass refreshed rights');
        $handler->infos[TARGET]['Chat']['creator'] = true;
        same(TARGET, App::validateAccount($handler)['target_id'], 'Destination owner can post without explicit admin rights');

        [$queue, $handler] = fixture();
        $handler->infos[SOURCE]['Chat']['creator'] = true;
        $handler->infos['@test_target'] = $handler->infos[SOURCE];
        fails(fn() => App::validateAccount($handler), 'Không xác định được channel đích', 'A source-only destination configuration cannot register a forwarding loop');

        [$queue, $handler] = fixture(true, true);
        $config = App::config();
        $config['targets'] = ['@test_target', '@test_source'];
        injectApp($config, $queue);
        $binding = App::validateAccount($handler);
        same([TARGET], array_keys($binding['targets']), 'A source alias configured as B is excluded from registered destinations');
        same(['@test_source' => 'SOURCE_TARGET_LOOP'], $binding['unresolved_targets'], 'A source destination has a finite unresolved failure code');
        $handler->onStart();
        check(!$queue->hasTarget(SOURCE), 'Invalid B cannot register the source itself');
        same(['@test_source' => 'SOURCE_TARGET_LOOP'], json_decode($queue->getMeta('unresolved_targets'), true), 'Invalid B state remains visible after worker startup');
        same(1, count($handler->messages->historyCalls), 'Invalid B does not fetch an activation cutoff');
        same('100', $queue->getMeta('source_min_id'), 'Invalid B preserves the original source baseline');
        $handler->dispatch(post(101));
        $handler->drainQueue();
        same(1, $queue->stats(TARGET)['forwarded'], 'Valid A continues forwarding when configured B resolves to the source');
        same([TARGET], array_column($handler->messages->repostCalls, 'to_peer'), 'Source-as-destination makes no forwarding call');
        same('', $queue->getMeta('paused_reason'), 'Invalid new B does not pause the account');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $handler->infos['@test_target_b'] = $handler->infos[SOURCE];
        $handler->onStart();
        same('TARGET_IDENTITY_CHANGED', $queue->getMeta('target_pause_' . SECOND_TARGET), 'Bound B alias resolving to the source remains anchored and paused');
        check($queue->hasTarget(SECOND_TARGET) && !$queue->hasTarget(SOURCE), 'Bound destination cannot be replaced by the source ID');
        $handler->drainQueue();
        same(1, $queue->stats(TARGET)['forwarded'], 'A continues after a bound B alias changes to the source');
        same(1, $queue->stats(SECOND_TARGET)['pending'], 'Bound B retains its pending delivery after alias change');

        [$queue, $handler] = fixture();
        configureSecondTarget($queue, $handler);
        $config = App::config();
        $config['targets'] = ['@test_target_b', '@test_target'];
        injectApp($config, $queue);
        $binding = App::validateAccount($handler);
        same(TARGET, $binding['target_id'], 'New storage uses configured primary A even when B appears first');
        App::initialize($handler, $binding);
        $handler->onStart();
        $handler->onStart();
        same((string) TARGET, $queue->getMeta('target_id'), 'Configured primary remains A after initialization and restart');
        same('', $queue->getMeta('target_pause_' . TARGET), 'Reordered targets cannot create a false primary identity mismatch');
        $handler->dispatch(post(101));
        $handler->drainQueue();
        same([1, 1], [$queue->stats(TARGET)['forwarded'], $queue->stats(SECOND_TARGET)['forwarded']], 'Both ordered destinations remain eligible after restart');
        foreach (['account_id', 'source_id', 'target_id'] as $key) {
            [$queue, $handler] = fixture();
            $queue->setMeta($key, '999999');
            fails(fn () => App::validateAccount($handler), 'tài khoản/cặp channel khác', 'Stored ' . $key . ' binding cannot be changed');
        }

        [$queue, $handler, $directory] = fixture();
        fails(fn () => App::requireInitialized(), 'Chưa khởi tạo', 'Unvalidated storage cannot start a worker');
        App::initialize($handler, App::validateAccount($handler));
        same(0, count($handler->messages->historyCalls), 'Login does not capture a message baseline');
        same(null, $queue->getMeta('source_min_id'), 'Login leaves first active worker baseline unset');
        App::requireInitialized();
        unlink($directory . '/telegram.session');
        fails(fn () => App::requireInitialized(), 'Không tìm thấy session', 'Validated metadata still requires a session file');
        file_put_contents($directory . '/telegram.session', 'Offline fixture.');
        $handler->onStart();
        same([['peer' => SOURCE, 'limit' => 1]], $handler->messages->historyCalls,
            'First active worker requests exactly the latest source post');
        same('100', $queue->getMeta('source_min_id'), 'First worker persists the new-only baseline');
        check($queue->getMeta('initialized_at') !== null, 'First worker records completed baseline initialization');
        $handler->messages->history = ['messages' => [['id' => 200]]];
        $handler->onStart();
        same(1, count($handler->messages->historyCalls), 'Worker restart does not request history again');
        same('100', $queue->getMeta('source_min_id'), 'Worker restart preserves original baseline');

        $handler->dispatch(post(99));
        $handler->dispatch(post(100));
        $handler->dispatch(post(101, TARGET_RAW));
        $handler->dispatch(post(101, SOURCE_RAW, ['_' => 'messageService']));
        $handler->dispatch(post(101, SOURCE_RAW, ['peer_id' => ['_' => 'peerChat', 'chat_id' => SOURCE_RAW]]));
        same(0, $queue->stats()['total'], 'History, destination posts, service messages and non-channel messages are ignored');
        same(false, $handler->dispatch(['_' => 'updateEditChannelMessage', 'message' => post(103)['message']]),
            'Edited-message updates have no forwarding callback');
        same(false, $handler->dispatch(['_' => 'updateNewMessage', 'message' => post(103)['message']]),
            'Non-channel new-message updates have no forwarding callback');
        $handler->dispatch(post(101, SOURCE_RAW, ['date' => 1]));
        $handler->dispatch(post(101));
        $handler->dispatch(post(102));
        same(2, $queue->stats()['pending'], 'Only new source IDs enqueue, independent of clock skew and duplicate delivery');
        $handler->drainQueue();
        $handler->drainQueue();
        same(2, $queue->stats()['forwarded'], 'Acknowledged source posts become forwarded');
        same([[101], [102]], array_column($handler->messages->repostCalls, 'id'), 'Worker sends source posts in order');
        same(SOURCE, $handler->messages->repostCalls[0]['from_peer'], 'Forward request reads the validated source');
        same(TARGET, $handler->messages->repostCalls[0]['to_peer'], 'Forward request posts to the validated destination');
        same('102', $queue->getMeta('last_forwarded_id'), 'Successful forwarding updates delivery metadata');
        check($queue->getMeta('worker_heartbeat_at') !== null, 'Worker records its delivery heartbeat');

        [$queue, $handler] = fixture(true, true, true);
        same('1', $queue->getMeta('source_protected'), 'Protected source status is persisted');
        $handler->dispatch(post(101));
        same(true, $queue->pendingBatches(time())[0]['protected'], 'Protected source marks newly received messages');
        $handler->drainQueue();
        same(1, $queue->stats()['skipped'], 'Protected source messages are skipped');
        same(0, count($handler->messages->repostCalls), 'Protected source is never submitted for forwarding');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101, SOURCE_RAW, ['noforwards' => true]));
        $handler->drainQueue();
        same(1, $queue->stats()['skipped'], 'Individual protected messages are skipped');
        same(0, count($handler->messages->repostCalls), 'Individual protected messages make no forward request');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->infos[SOURCE]['Chat']['noforwards'] = true;
        $refreshCount = count($handler->refreshCalls);
        $handler->onUpdateChannel(['channel_id' => TARGET]);
        same($refreshCount, count($handler->refreshCalls), 'Destination channel updates do not refresh the source');
        $handler->onUpdateChannel(['channel_id' => SOURCE_RAW]);
        same('1', $queue->getMeta('source_protected'), 'Raw source channel updates refresh content protection');
        $handler->drainQueue();
        same(1, $queue->stats()['skipped'], 'Protection enabled after receipt also prevents forwarding');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->refreshError = new RPCErrorException('CHANNEL_PRIVATE');
        $handler->onUpdateChannel(['channel_id' => SOURCE]);
        check(str_starts_with($queue->getMeta('paused_reason') ?? '', 'SOURCE_ACCESS:'), 'Failed source refresh pauses forwarding');
        $handler->drainQueue();
        same(0, count($handler->messages->repostCalls), 'Source access pause keeps queued messages unsent');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->dispatch(post(102));
        $handler->messages->nextError = new RPCErrorException('INTERNAL_SERVER_ERROR');
        $handler->drainQueue();
        same(2, $queue->stats()['pending'], 'Transient RPC errors keep posts pending');
        same(1, $queue->stats()['retries'], 'Transient RPC errors schedule only the oldest post for retry');
        same([], $queue->pendingBatches(time()), 'Retry delay prevents later source posts passing the failure');
        $retryBatch = $queue->pendingBatches(time() + 100)[0];
        same($handler->messages->repostCalls[0]['random_id'], $retryBatch['random_ids'], 'RPC retry preserves persisted random IDs');
        same(1, $retryBatch['attempts'], 'Worker records the failed attempt');
        $handler->drainQueue();
        same(1, count($handler->messages->repostCalls), 'Retry delay makes no immediate duplicate network call');
        makeRetryDue($queue, [101]);
        $handler->drainQueue();
        same($handler->messages->repostCalls[0]['random_id'], $handler->messages->repostCalls[1]['random_id'],
            'A later retry sends the same random IDs');
        $handler->drainQueue();
        same([[101], [101], [102]], array_column($handler->messages->repostCalls, 'id'), 'Later posts follow successful retry');
        same(2, $queue->stats()['forwarded'], 'Successful retry drains both queued source posts');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->messages->nextError = new RPCErrorException('CHAT_WRITE_FORBIDDEN');
        $handler->drainQueue();
        same('CHAT_WRITE_FORBIDDEN', $queue->getMeta('target_pause_' . TARGET), 'Write permission failures persist a destination pause');
        same('', $queue->getMeta('paused_reason'), 'Destination permission failure does not pause the account');
        same(1, $queue->stats()['pending'], 'Permission pause retains the post');
        same(0, $queue->stats()['retries'], 'Permission pause does not schedule repeated RPC attempts');
        $handler->drainQueue();
        $handler->drainQueue();
        same(1, count($handler->messages->repostCalls), 'Permission pause prevents repeated sends');
        App::initialize($handler, App::validateAccount($handler));
        $handler->onStart();
        $handler->drainQueue();
        same(1, $queue->stats()['forwarded'], 'Successful account revalidation clears pause and resumes retained post');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->messages->acknowledgeCount = 0;
        $handler->drainQueue();
        same(0, $queue->stats()['forwarded'], 'Unacknowledged RPC response cannot mark success');
        same(1, $queue->stats()['retries'], 'Missing acknowledgement schedules reconciliation');
        same('UNCONFIRMED_REPOST_RESPONSE', $queue->stats()['last_reason'], 'Missing acknowledgement records its reason');
        same($handler->messages->repostCalls[0]['random_id'], $queue->pendingBatches(time() + 100)[0]['random_ids'],
            'Missing acknowledgement retains random IDs');
        makeRetryDue($queue, [101]);
        $handler->messages->acknowledgeCount = null;
        $handler->drainQueue();
        same(1, $queue->stats()['forwarded'], 'Later acknowledgement reconciles the pending forward');

        [$queue, $handler] = fixture(true, true);
        // Album settling is covered by queue.php. Disable that delay here to
        // isolate the all-members acknowledgement requirement without sleeping.
        $config = App::config();
        $config['album_settle_seconds'] = 0;
        injectApp($config, $queue);
        $handler->dispatch(post(101, SOURCE_RAW, ['grouped_id' => 777]));
        $handler->dispatch(post(102, SOURCE_RAW, ['grouped_id' => 777]));
        $handler->messages->acknowledgeCount = 1;
        $handler->drainQueue();
        same(0, $queue->stats()['forwarded'], 'Partial album acknowledgement cannot mark any member forwarded');
        same(2, $queue->stats()['retries'], 'Partial album response retries the entire batch');
        same([101, 102], $handler->messages->repostCalls[0]['id'], 'Album forward keeps all source IDs together');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->messages->nextError = new RateLimitError('FLOOD_WAIT_10', 10);
        $handler->drainQueue();
        same([], $queue->pendingBatches(time() + 5), 'Flood wait prevents premature retries');
        same(1, count($queue->pendingBatches(time() + 20)), 'Flood wait eventually becomes eligible');
        same('FLOOD_WAIT_10', $queue->stats()['last_reason'], 'Flood wait reason is preserved');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->messages->nextError = new RPCErrorException('CHAT_FORWARDS_RESTRICTED');
        $handler->drainQueue();
        same(1, $queue->stats()['skipped'], 'Telegram protection rejection skips the source post');
        same(0, $queue->stats()['retries'], 'Protection rejection is not repeatedly retried');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101, TARGET_RAW));
        $handler->drainQueue();
        same(0, $queue->stats()['total'], 'Unexpected channel updates cannot enter the registered source queue');
        same(0, count($handler->messages->repostCalls), 'Unexpected queued source cannot post to the destination');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->messages->history = ['messages' => [['id' => 200]]];
        configureSecondTarget($queue, $handler, false);
        $handler->onStart();
        same('100', $queue->getMeta('source_min_id'), 'Adding B preserves the source baseline');
        same(100, $queue->targetStats()[TARGET]['cutoff'], 'Existing A retains its activation cutoff');
        same(200, $queue->targetStats()[SECOND_TARGET]['cutoff'], 'New B captures the latest source ID even while permission-paused');
        check(str_contains($queue->getMeta('target_pause_' . SECOND_TARGET), 'Post Messages'), 'Missing B permission pauses only B');
        same('', $queue->getMeta('paused_reason'), 'Missing B permission leaves source/account ready');
        $handler->dispatch(post(199));
        $handler->dispatch(post(201));
        $handler->drainQueue();
        $handler->drainQueue();
        same([[101], [199]], array_column($handler->messages->repostCalls, 'id'), 'A preserves and drains its existing backlog while B is paused');
        same([201], $queue->pendingBatches(time(), 0, SECOND_TARGET)[0]['ids'], 'B excludes all posts at or before its activation');
        $historyCount = count($handler->messages->historyCalls);
        $handler->infos[SECOND_TARGET]['Chat']['admin_rights']['post_messages'] = true;
        $handler->onStart();
        same($historyCount, count($handler->messages->historyCalls), 'Granting B rights does not move its cutoff');
        same('', $queue->getMeta('target_pause_' . SECOND_TARGET), 'Revalidation resumes B independently');
        $handler->drainQueue();
        same(3, $queue->stats(TARGET)['forwarded'], 'A receives all eligible posts');
        same(1, $queue->stats(SECOND_TARGET)['forwarded'], 'B receives only its post-activation post');
        $handler->dispatch(post(199));
        same(1, $queue->stats(SECOND_TARGET)['total'], 'Replayed older update cannot backfill B');

        [$queue, $handler, $directory] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $handler->dispatch(post(102));
        $handler->messages->targetAcknowledgements[SECOND_TARGET] = 0;
        $handler->drainQueue();
        $handler->drainQueue();
        same(2, $queue->stats(TARGET)['forwarded'], 'Unconfirmed B response cannot stall A');
        same(0, $queue->stats(SECOND_TARGET)['forwarded'], 'Unconfirmed B cannot claim delivery');
        same(1, $queue->stats(SECOND_TARGET)['retries'], 'Only B oldest post receives retry state');
        $bRandom = $handler->messages->repostCalls[1]['random_id'];
        check($handler->messages->repostCalls[0]['random_id'] !== $bRandom, 'Same source message has separate destination random IDs');
        $config = App::config();
        $queue = new Queue($directory . '/queue.sqlite');
        injectApp($config, $queue);
        makeRetryDue($queue, [101], SECOND_TARGET);
        $recovered = recoveredWorker($handler);
        $recovered->onStart();
        $recovered->drainQueue();
        $recovered->drainQueue();
        same([SECOND_TARGET, SECOND_TARGET], array_column($recovered->messages->repostCalls, 'to_peer'), 'Restart retries B without resending terminal A deliveries');
        same($bRandom, $recovered->messages->repostCalls[0]['random_id'], 'B response reconciliation retains IDs through restart');
        same([[101], [102]], array_column($recovered->messages->repostCalls, 'id'), 'B reconciliation preserves source order');
        same(2, $queue->stats(SECOND_TARGET)['forwarded'], 'B eventually drains its own backlog');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $handler->dispatch(post(102));
        $handler->messages->targetErrors[SECOND_TARGET] = new RPCErrorException('CHAT_WRITE_FORBIDDEN');
        $handler->drainQueue();
        $handler->drainQueue();
        same(2, $queue->stats(TARGET)['forwarded'], 'B write rejection does not block A later posts');
        same(2, $queue->stats(SECOND_TARGET)['pending'], 'B write rejection retains both B posts');
        same('CHAT_WRITE_FORBIDDEN', $queue->getMeta('target_pause_' . SECOND_TARGET), 'B RPC permission rejection is target-specific');
        same('', $queue->getMeta('paused_reason'), 'B RPC permission rejection does not pause the account');
        same(1, count(array_filter($handler->messages->repostCalls, fn(array $call): bool => $call['to_peer'] === SECOND_TARGET)), 'Paused B does not make repeated RPC calls');

        [$queue, $handler, $directory] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        disableAlbumDelay($queue);
        $handler->dispatch(post(101, SOURCE_RAW, ['grouped_id' => 888]));
        $handler->dispatch(post(102, SOURCE_RAW, ['grouped_id' => 888]));
        $handler->messages->beforeResponse = function () use ($handler): void {
            $handler->messages->beforeResponse = null;
            $handler->dispatch(post(103, SOURCE_RAW, ['grouped_id' => 888]));
            $handler->drainQueue();
        };
        $handler->drainQueue();
        same([[101, 102], [101, 102, 103]], array_column($handler->messages->repostCalls, 'id'), 'Late RPC-time album part cannot be inserted into A already-selected request');
        same(2, count($handler->messages->repostCalls), 'Reentrant cron does not overlap an in-flight send');
        same(2, $queue->stats(TARGET)['forwarded'], 'A commits only the original album snapshot');
        same(1, $queue->stats(TARGET)['pending'], 'Late A part remains pending after snapshot commit');
        same(3, $queue->stats(SECOND_TARGET)['forwarded'], 'B selected after late arrival can include all known parts');
        $lateRandom = $queue->pendingBatches(time(), 0, TARGET)[0]['random_ids'];
        $config = App::config();
        $queue = new Queue($directory . '/queue.sqlite');
        injectApp($config, $queue);
        $recovered = recoveredWorker($handler);
        $recovered->onStart();
        $recovered->drainQueue();
        same([[103]], array_column($recovered->messages->repostCalls, 'id'), 'Restart forwards the late part as a separate request');
        same($lateRandom, $recovered->messages->repostCalls[0]['random_id'], 'Late part random ID survives restart');
        same(3, $queue->stats(TARGET)['forwarded'], 'No late album part is lost');
        $recovered->dispatch(post(104, SOURCE_RAW, ['grouped_id' => 888]));
        $recovered->drainQueue();
        same([103, 104], array_merge(...array_column(array_filter($recovered->messages->repostCalls,
            fn(array $call): bool => $call['to_peer'] === TARGET), 'id')), 'A part first arriving after restart also stays a separate delivery');
        same(4, $queue->stats(SECOND_TARGET)['forwarded'], 'Post-restart late part is eligible for B independently');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $handler->messages->targetErrors[SECOND_TARGET] = new RPCErrorException('CHANNEL_PRIVATE');
        $handler->drainQueue();
        same(1, $queue->stats(TARGET)['forwarded'], 'Private B response preserves completed A delivery');
        same('CHANNEL_PRIVATE', $queue->getMeta('target_pause_' . SECOND_TARGET), 'Healthy revalidated source makes ambiguous error B-specific');
        same('', $queue->getMeta('paused_reason'), 'Healthy source avoids global pause after ambiguous B error');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $handler->infos[SOURCE]['Chat']['left'] = true;
        $handler->messages->targetErrors[TARGET] = new RPCErrorException('CHANNEL_PRIVATE');
        $handler->drainQueue();
        check(str_starts_with($queue->getMeta('paused_reason'), 'SOURCE_ACCESS:'), 'Ambiguous error with lost source membership pauses globally');
        same(0, count($handler->messages->repostCalls), 'Fresh source membership failure blocks both destinations before sending');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $handler->messages->targetErrors[TARGET] = new RateLimitError('FLOOD_WAIT_10', 10);
        $handler->drainQueue();
        $handler->drainQueue();
        same(1, count($handler->messages->repostCalls), 'Account flood wait prevents immediate RPCs to every target');
        check((int) $queue->getMeta('forward_retry_at') > time(), 'Account flood wait has a global retry deadline');
        same(1, $queue->stats(SECOND_TARGET)['pending'], 'Flood wait retains B without skipping or retry storms');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->refreshErrors[SECOND_TARGET] = new RuntimeException('Offline target TCP timeout');
        fails(fn() => App::validateAccount($handler), 'TCP timeout', 'Target network errors propagate to global startup recovery');
        same(null, $queue->getMeta('target_pause_' . SECOND_TARGET), 'Network failure cannot become a durable B permission pause');
        $handler->refreshErrors[SECOND_TARGET] = new RPCErrorException('INTERNAL_SERVER_ERROR');
        fails(fn() => App::validateAccount($handler), 'INTERNAL_SERVER_ERROR', 'Target internal RPC failure stays recoverable');
        same(null, $queue->getMeta('target_pause_' . SECOND_TARGET), 'Internal RPC failure cannot become a permission pause');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        configureSecondTarget($queue, $handler);
        $binding = App::validateAccount($handler);
        foreach ([[], ['messages' => 'not-a-list'], ['messages' => [['id' => '200']]], ['messages' => [['id' => -1]]]] as $malformed) {
            $handler->messages->history = $malformed;
            fails(fn() => App::initializeFirstRun($handler, $binding), 'Invalid source history', 'Malformed history cannot invent a new target cutoff');
            check(!$queue->hasTarget(SECOND_TARGET), 'Malformed activation leaves B unregistered');
        }
        same('100', $queue->getMeta('source_min_id'), 'Malformed activation preserves old source baseline');
        same(1, $queue->stats(TARGET)['pending'], 'Malformed activation preserves A pending delivery');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $newTarget = -1004234567890;
        $handler->infos['@test_target_b']['bot_api_id'] = $newTarget;
        $handler->infos[$newTarget] = $handler->infos['@test_target_b'];
        $config = App::config();
        $config['targets'] = ['@test_target', SECOND_TARGET, '@test_target_b'];
        injectApp($config, $queue);
        $handler->onStart();
        same('TARGET_IDENTITY_CHANGED', $queue->getMeta('target_pause_' . SECOND_TARGET), 'Reassigned B username pauses the known destination even if its numeric ID is retained');
        check(!$queue->hasTarget($newTarget), 'Reassigned username never creates an unintended destination');
        $handler->drainQueue();
        same(1, $queue->stats(TARGET)['forwarded'], 'B identity mismatch leaves A sending');
        same(1, $queue->stats(SECOND_TARGET)['pending'], 'B identity mismatch preserves its original pending delivery');
        same(100, $queue->targetStats()[SECOND_TARGET]['cutoff'], 'B identity mismatch preserves its cutoff');

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->infos['@test_target']['bot_api_id'] = $newTarget;
        $handler->infos[$newTarget] = $handler->infos['@test_target'];
        $handler->dispatch(post(101));
        $handler->onStart();
        same((string) TARGET, $queue->getMeta('target_id'), 'Reassigned primary username cannot remap the legacy binding');
        same('TARGET_IDENTITY_CHANGED', $queue->getMeta('target_pause_' . TARGET), 'Reassigned A pauses A independently');
        $handler->drainQueue();
        same(1, $queue->stats(SECOND_TARGET)['forwarded'], 'Primary identity mismatch does not block valid B');
        check(!$queue->hasTarget($newTarget), 'Primary alias reassignment cannot add the new account/channel');

        foreach ([true, false] as $oldProtection) {
            [$queue, $handler] = fixture(true, true, $oldProtection);
            $handler->infos[SOURCE]['Chat']['noforwards'] = !$oldProtection;
            $handler->refreshError = new RuntimeException('Offline source refresh timeout');
            $handler->onUpdateChannel(['channel_id' => SOURCE]);
            $handler->dispatch(post(101));
            $handler->drainQueue();
            same('1', $queue->getMeta('source_revalidation_needed'), 'Transient source refresh preserves a required revalidation');
            same('', $queue->getMeta('paused_reason'), 'Transient source refresh does not hard-pause');
            same(1, $queue->stats()['pending'], 'Uncertain protection leaves new posts pending');
            same(0, $queue->stats()['skipped'], 'Uncertain protection never permanently skips a new post');
            $handler->refreshError = null;
            $queue->setMeta('forward_retry_at', '0');
            $handler->drainQueue();
            same('0', $queue->getMeta('source_revalidation_needed'), 'Drain refreshes stale source state before acting');
            same($oldProtection ? 1 : 0, $queue->stats()['forwarded'], 'Fresh protection-OFF permits forwarding');
            same($oldProtection ? 0 : 1, $queue->stats()['skipped'], 'Fresh protection-ON skips without a forward attempt');
        }

        [$queue, $handler] = fixture(true, true);
        configureSecondTarget($queue, $handler);
        $handler->onStart();
        $handler->dispatch(post(101));
        $handler->refreshError = new RPCErrorException('INTERNAL_SERVER_ERROR');
        $handler->messages->targetErrors[TARGET] = new RPCErrorException('CHANNEL_PRIVATE');
        $handler->drainQueue();
        same('', $queue->getMeta('paused_reason'), 'Internal source revalidation RPC failure does not become a permanent source-access pause');
        check((int) $queue->getMeta('forward_retry_at') > time(), 'Internal source revalidation schedules global backoff');
        same('1', $queue->getMeta('source_revalidation_needed'), 'Internal source revalidation must complete before any later drain');
        same(2, $queue->stats()['pending'], 'Uncertain source access preserves both destination deliveries');

        $normalize = (new ReflectionClass(App::class))->getMethod('normalizePeer');
        same('@test_target', $normalize->invoke(null, '@Test_Target', 'target'), 'Peer normalization removes username case aliases');
        fails(fn() => $normalize->invoke(null, '-1005', 'target'), 'ID dạng -100', 'Short noncanonical string IDs are rejected');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $beforeStats = $queue->stats();
        $beforeMeta = [];
        foreach (['source_min_id', 'initialized_at', 'target_id', 'target_ids', 'worker_ready',
            'worker_heartbeat_at', 'worker_started_at', 'last_received_at', 'source_protected', 'paused_reason'] as $key) {
            $beforeMeta[$key] = $queue->getMeta($key);
        }
        $restored = unserialize(serialize($handler));
        App::releaseLock();
        same(false, App::workerEventsAllowed(), 'Login/helper role cannot retain worker event permission after lock release');
        $restored->onStart();
        same(0, count($restored->messages->historyCalls) - count($handler->messages->historyCalls), 'Restored helper handler does not initialize source history');
        (new ReflectionClass(MirrorHandler::class))->getProperty('ready')->setValue($restored, true);
        $restored->dispatch(post(102));
        $restored->onUpdateChannel(['channel_id' => SOURCE]);
        $restored->drainQueue();
        same($beforeStats, $queue->stats(), 'Serialized ready state cannot enqueue, skip or forward in a helper process');
        $afterMeta = [];
        foreach (array_keys($beforeMeta) as $key) { $afterMeta[$key] = $queue->getMeta($key); }
        same($beforeMeta, $afterMeta, 'Helper-restored callbacks cannot overwrite worker metadata/cutoffs');
        same([], $restored->messages->repostCalls, 'Helper-restored ready state makes no forward call');
        fails(fn() => App::enableWorkerEvents(), 'owned process lock', 'Worker event role cannot be enabled without the process lock');
        check(App::acquireLock(), 'Authentication helper owns the process lock in this fixture');
        same(false, App::workerEventsAllowed(), 'Owning the helper lock alone does not allow worker events');
        $database = new \PDO('sqlite:' . App::path('queue.sqlite'));
        $allMetadata = $database->query('SELECT key, value FROM queue_metadata ORDER BY key')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $historyCalls = count($restored->messages->historyCalls);
        $restored->onStart();
        (new ReflectionClass(MirrorHandler::class))->getProperty('ready')->setValue($restored, true);
        $restored->dispatch(post(103));
        $restored->onUpdateChannel(['channel_id' => SOURCE]);
        $restored->drainQueue();
        same($beforeStats, $queue->stats(), 'A lock-owning helper with serialized ready state cannot change any delivery');
        same($allMetadata, $database->query('SELECT key, value FROM queue_metadata ORDER BY key')->fetchAll(\PDO::FETCH_KEY_PAIR),
            'Lock-owning helper callbacks leave all metadata and heartbeat unchanged');
        same($historyCalls, count($restored->messages->historyCalls), 'Lock-owning helper does not initialize or refresh source history');
        same([], $restored->messages->repostCalls, 'Lock-owning helper makes no forward call');
        $contender = fopen(App::path('process.lock'), 'ce');
        check(is_resource($contender) && !flock($contender, LOCK_EX | LOCK_NB), 'Helper still owns its lock after every guarded callback');
        if (is_resource($contender)) { fclose($contender); }
        unset($database);
        App::releaseLock();
        check(App::acquireLock(), 'Real worker can reacquire its lock after helper isolation');
        App::enableWorkerEvents();
        $restored->onStart();
        $restored->drainQueue();
        same(1, $queue->stats()['forwarded'], 'Owned worker alone resumes the preserved pending delivery');

        [$queue, $handler] = fixture(true);
        $handler->messages->history = ['messages' => []];
        $handler->onStart();
        same('0', $queue->getMeta('source_min_id'), 'Empty source initializes baseline at zero');
        $handler->dispatch(post(1));
        same(1, $queue->stats()['pending'], 'Empty source accepts its first subsequent post');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));
        $handler->messages->nextError = new RuntimeException('lost reply after possible acceptance');
        $handler->drainQueue(); makeRetryDue($queue,[101]);
        $handler->infos[SOURCE]['Chat']['noforwards'] = true;
        $handler->onUpdateChannel(['channel_id'=>SOURCE]);$handler->drainQueue();
        same(1,$queue->stats(TARGET)['pending'],'Ambiguous sent delivery remains pending if source becomes protected');
        same(0,$queue->stats(TARGET)['skipped'],'Protection after lost ACK cannot claim no delivery');
        same('REPOST_PROTECTION_RECONCILIATION_REQUIRED',$queue->getMeta('target_pause_'.TARGET),'Protection pauses reconciliation');
        same(1,count($handler->messages->repostCalls),'No protected resend after ambiguous acceptance');

        [$queue, $handler] = fixture(true, true);
        $handler->dispatch(post(101));$handler->messages->nextError = new RuntimeException('lost reply');
        $handler->drainQueue();makeRetryDue($queue,[101]);
        $handler->channels->fixtures[101] = ['_'=>'messageEmpty','id'=>101];$handler->drainQueue();
        same(1,$queue->stats(TARGET)['pending'],'Deleted source after lost ACK stays unresolved');
        check(str_starts_with($queue->getMeta('target_pause_'.TARGET),'REPOST_RECONCILIATION_REQUIRED:'),'Deleted source pauses instead of skipping sent payload');

        [$queue, $handler] = fixture(true, true);$handler->dispatch(post(101));
        $handler->messages->ackResponse = ['_'=>'updates','updates'=>[['_'=>'updateNewScheduledMessage','message'=>['id'=>999]]]];
        $handler->drainQueue();
        same(1,$queue->stats(TARGET)['pending'],'Scheduled processing ID is not committed as a post');
        same(0,$queue->stats(TARGET)['forwarded'],'Processing ACK cannot claim delivered');
        same('REPOST_VIDEO_PROCESSING_RECONCILIATION_REQUIRED',$queue->getMeta('target_pause_'.TARGET),'Processing waits for explicit final-ID reconciliation');

        echo 'Mirror: ' . $assertions . " assertions passed.\n";
    } finally {
        unset($queue, $handler);
        cleanupFixtures();
    }
}
