<?php

declare(strict_types=1);

// Offline integration: the real App, handler, repost and queue run against
// private SQLite fixtures and a synthetic API. No config/session/network access.
namespace danog\MadelineProto {
    final class API { public const LOGGED_IN = 4; }
    class RPCErrorException extends \RuntimeException
    {
        public function __construct(public string $rpc) { parent::__construct($rpc); }
    }
    class SimpleEventHandler
    {
        public \TelegramMirror\Tests\MultiSource\Messages $messages;
        public \TelegramMirror\Tests\MultiSource\Channels $channels;
        public array $infos = [];
        public array $infoErrors = [];
        public array $refreshErrors = [];
        public array $refreshCalls = [];
        public array $logs = [];
        public function __construct()
        {
            $this->messages = new \TelegramMirror\Tests\MultiSource\Messages();
            $this->channels = new \TelegramMirror\Tests\MultiSource\Channels();
        }
        public function getAuthorization(): int { return API::LOGGED_IN; }
        public function getSelf(): array { return ['id' => 7654321, 'bot' => false]; }
        public function getInfo(int|string $peer): array
        {
            if (isset($this->infoErrors[$peer])) { throw $this->infoErrors[$peer]; }
            return $this->infos[$peer] ?? throw new \RuntimeException('Unknown offline peer');
        }
        public function refreshPeerCache(int ...$peers): void
        {
            $this->refreshCalls[] = $peers;
            foreach ($peers as $peer) {
                if (isset($this->refreshErrors[$peer])) { throw $this->refreshErrors[$peer]; }
            }
        }
        public function getId(array $message): int
        {
            $peer = $message['peer_id'] ?? $message;
            if (($peer['_'] ?? '') !== 'peerChannel') { throw new \RuntimeException('Unexpected offline peer'); }
            return -1000000000000 - (int) $peer['channel_id'];
        }
        public function logger(string $message): void { $this->logs[] = $message; }
        public function getFileInfo(array $media): array
        {
            $photo = $media['photo'] ?? (($media['_'] ?? '') === 'photo' ? $media : null);
            $file = $photo ?? $media['document'];
            $input = ['_' => $photo !== null ? 'inputPhoto' : 'inputDocument',
                'id' => $file['id'], 'access_hash' => $file['access_hash'], 'file_reference' => $file['file_reference']];
            return ['InputMedia' => ['_' => $photo !== null ? 'inputMediaPhoto' : 'inputMediaDocument', 'id' => $input],
                'InputPhoto' => $input];
        }
        public function dispatch(array $update): void
        {
            if (($update['_'] ?? '') === 'updateNewChannelMessage') {
                $message = $update['message'];
                $this->channels->fixtures[$this->getId($message)][$message['id']] = $message;
            }
            $method = 'on' . ucfirst($update['_']);
            if (method_exists($this, $method)) { $this->{$method}($update); }
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
        public function __construct(string $rpc, private int $wait) { parent::__construct($rpc); }
        public function getWaitTimeLeft(): int { return $this->wait; }
    }
}

namespace TelegramMirror\Tests\MultiSource {
    use danog\MadelineProto\RPCErrorException;
    use PDO;
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

    const A = -1001234567890;
    const B = -1002234567890;
    const X = -1003234567890;
    const Y = -1004234567890;
    const OTHER = -1005234567890;

    final class Channels
    {
        public array $fixtures = [];
        public array $calls = [];
        public function getMessages(int $channel, array $id): array
        {
            $this->calls[] = compact('channel', 'id');
            $messages = [];
            foreach ($id as $input) {
                if (($input['_'] ?? '') !== 'inputMessageID') { throw new RuntimeException('Missing explicit message input'); }
                $messages[] = $this->fixtures[$channel][$input['id']]
                    ?? throw new RuntimeException('Missing source-specific offline message');
            }
            return ['_' => 'messages.channelMessages', 'messages' => array_reverse($messages)];
        }
    }

    final class Messages
    {
        public array $heads = [A => 100, B => 100];
        public array $historyCalls = [];
        public array $historyErrors = [];
        public array $calls = [];
        public array $errors = [];
        private array $accepted = [];
        private int $nextId = 9000;
        public function getHistory(int $peer, int $limit): array
        {
            $this->historyCalls[] = compact('peer', 'limit');
            if (isset($this->historyErrors[$peer])) { throw $this->historyErrors[$peer]; }
            $head = $this->heads[$peer] ?? throw new RuntimeException('Unknown source history');
            return ['messages' => $head === 0 ? [] : [['id' => $head]]];
        }
        public function forwardMessages(...$arguments): never { throw new RuntimeException('FORWARD_RPC_FORBIDDEN'); }
        public function sendMessage(int $peer, string $message, array $entities, int $random_id, bool $no_webpage, bool $invert_media): array
        {
            return $this->respond($peer, [$random_id], [['message' => $message, 'entities' => $entities]], 'sendMessage');
        }
        public function sendMedia(int $peer, array $media, string $message, array $entities, int $random_id, bool $invert_media): array
        {
            return $this->respond($peer, [$random_id], [compact('media', 'message', 'entities')], 'sendMedia');
        }
        public function sendMultiMedia(int $peer, array $multi_media, bool $invert_media): array
        {
            return $this->respond($peer, array_column($multi_media, 'random_id'), $multi_media, 'sendMultiMedia');
        }
        private function respond(int $peer, array $randomIds, array $items, string $method): array
        {
            $this->calls[] = compact('peer', 'randomIds', 'items', 'method');
            $updates = [];
            foreach ($randomIds as $randomId) {
                $key = $peer . ':' . $randomId;
                $this->accepted[$key] ??= ++$this->nextId;
                $updates[] = ['_' => 'updateMessageID', 'random_id' => $randomId, 'id' => $this->accepted[$key]];
            }
            // A lost response follows a synthetic acceptance; retry must reuse
            // its persisted IDs and body to reconcile the same destination IDs.
            if (isset($this->errors[$peer])) {
                $error = $this->errors[$peer]; unset($this->errors[$peer]); throw $error;
            }
            return ['_' => 'updates', 'updates' => $updates];
        }
    }

    $assertions = 0;
    $fixtures = [];
    function check(bool $condition, string $description): void
    {
        global $assertions;
        ++$assertions;
        if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
    }
    function same(mixed $expected, mixed $actual, string $description): void
    {
        check($expected === $actual, $description . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
    function rejected(callable $operation, string $description): void
    {
        try { $operation(); }
        catch (RuntimeException) { check(true, $description); return; }
        check(false, $description);
    }
    function inject(array $config, ?Queue $queue): void
    {
        $reflection = new ReflectionClass(App::class);
        $reflection->getProperty('config')->setValue(null, $config);
        $reflection->getProperty('queue')->setValue(null, $queue);
    }
    /** @return array{Queue,MirrorHandler,string} */
    function fixture(?array $sources = null, bool $start = true): array
    {
        global $fixtures;
        App::releaseLock();
        $directory = __DIR__ . '/multi-source-fixture-' . bin2hex(random_bytes(6));
        $fixtures[] = $directory;
        if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create offline storage'); }
        file_put_contents($directory . '/telegram.session', 'Offline fixture, no credentials.');
        $queue = new Queue($directory . '/queue.sqlite');
        $config = ['source' => '@source_a', 'target' => '@target_x', 'targets' => ['@target_x'],
            'storage_dir' => $directory, 'album_settle_seconds' => 0,
            'retry_base_seconds' => 10, 'retry_max_seconds' => 60];
        if ($sources !== null) { $config['sources'] = $sources; }
        inject($config, $queue);
        if (!App::acquireLock()) { throw new RuntimeException('Cannot acquire offline process lock'); }
        App::enableWorkerEvents();
        $handler = new MirrorHandler();
        foreach (['@source_a' => A, '@source_b' => B, '@target_x' => X, '@target_y' => Y, '@other_peer' => OTHER] as $reference => $id) {
            $info = ['type' => 'channel', 'bot_api_id' => $id,
                'Chat' => ['broadcast' => true, 'left' => false, 'admin_rights' => ['post_messages' => true]]];
            $handler->infos[$reference] = $handler->infos[$id] = $info;
        }
        if ($start) {
            App::initialize($handler, App::validateAccount($handler));
            $handler->onStart();
        }
        return [$queue, $handler, $directory];
    }
    function post(int $source, int $id, string $caption, ?int $group = null): array
    {
        $message = ['_' => 'message', 'id' => $id, 'date' => time(),
            'peer_id' => ['_' => 'peerChannel', 'channel_id' => -1000000000000 - $source],
            'message' => $caption, 'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 1]]];
        if ($group !== null) {
            $message['grouped_id'] = $group;
            $message['media'] = ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo',
                'id' => (-$source * 1000) + $id, 'access_hash' => -987654321, 'file_reference' => "\x00\xff"]];
        }
        return ['_' => 'updateNewChannelMessage', 'message' => $message];
    }
    function route(string $source, array $targets = ['@target_x'], bool $enabled = true): array
    {
        return compact('source', 'targets', 'enabled');
    }
    function routes(string $directory, int $revision, array $routes): void
    {
        $path = $directory . '/routes.json';
        $temporary = $path . '.tmp';
        file_put_contents($temporary, json_encode(['version' => 1, 'revision' => $revision, 'routes' => $routes], JSON_THROW_ON_ERROR));
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) { throw new RuntimeException('Cannot replace offline route config'); }
    }
    function durableSnapshot(string $path): array
    {
        $database = new PDO('sqlite:' . $path);
        $result = [];
        foreach (['queue_messages', 'queue_deliveries', 'queue_targets', 'queue_repost_payloads'] as $table) {
            $result[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach (['source_id', 'target_id', 'account_id', 'source_min_id', 'initialized_at', 'delivery_mode'] as $key) {
            $statement = $database->prepare('SELECT value FROM queue_metadata WHERE key = ?');
            $statement->execute([$key]);
            $result['bindings'][$key] = $statement->fetchColumn();
        }
        return $result;
    }
    function cleanup(): void
    {
        global $fixtures;
        App::releaseLock(); inject([], null);
        // Drop App's retained source objects before removing their SQLite
        // files. Inactive sources intentionally stay open during worker life.
        try { App::queues(); } catch (RuntimeException) {}
        foreach ($fixtures as $directory) {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') { unlink($directory . '/' . $entry); }
            }
            rmdir($directory);
        }
    }

    try {
        [$primary, $handler] = fixture();
        same(1, count(App::sourceConfigs()), 'Legacy config still supplies exactly one source');
        same($primary, App::queue(A), 'Legacy source retains its original queue object');
        same([A], array_column($handler->messages->historyCalls, 'peer'), 'Legacy activation captures one source head');
        $handler->dispatch(post(A, 100, 'old A'));
        $handler->dispatch(post(A, 101, 'new A'));
        $handler->drainQueue();
        same(1, $primary->stats(X)['forwarded'], 'Legacy source still reposts only its new post');
        same('new A', $handler->messages->calls[0]['items'][0]['message'], 'Legacy repost preserves the actual text');

        [$primary, $handler, $directory] = fixture(['@source_a', '@source_b']);
        $secondary = App::queue(B);
        same(2, count(App::sourceConfigs()), 'Flat sources inherit configured destination list');
        same([A, B], array_column($handler->messages->historyCalls, 'peer'), 'Each source establishes its own activation head');
        same('100', $primary->getMeta('source_min_id'), 'Primary cutoff is source-specific');
        same('100', $secondary->getMeta('source_min_id'), 'Secondary cutoff is independently persisted');
        check($primary !== $secondary, 'Two sources never share a queue object');
        check(is_file($directory . '/queue.sqlite'), 'Primary SQLite filename remains compatible');
        check(is_file($directory . '/queue-source-' . substr((string) B, 1) . '.sqlite'), 'Additional source uses canonical-ID SQLite filename');
        $handler->dispatch(post(A, 101, 'A one', 777));
        $handler->dispatch(post(B, 101, 'B one', 777));
        $handler->dispatch(post(B, 102, 'B two', 777));
        $handler->dispatch(post(A, 102, 'A two', 777));
        $a = $primary->pendingBatches(time(), 0, X)[0];
        $b = $secondary->pendingBatches(time(), 0, X)[0];
        same([101, 102], $a['ids'], 'A album membership excludes matching B identifiers');
        same([101, 102], $b['ids'], 'B album membership excludes matching A identifiers');
        same(A, $a['source_id'], 'A batch keeps its canonical source');
        same(B, $b['source_id'], 'B batch keeps its canonical source');
        same([], array_intersect($a['random_ids'], $b['random_ids']), 'Same IDs across sources have distinct durable random IDs');
        $handler->drainQueue();
        same(2, count($handler->messages->calls), 'Equal album IDs in two sources produce separate API sends');
        same(['sendMultiMedia', 'sendMultiMedia'], array_column($handler->messages->calls, 'method'), 'Both complete source albums use album RPC');
        same(['A one', 'A two'], array_column($handler->messages->calls[0]['items'], 'message'), 'A album sends only A captions in message order');
        same(['B one', 'B two'], array_column($handler->messages->calls[1]['items'], 'message'), 'B album sends only B captions in message order');
        same([A, B], array_column($handler->channels->calls, 'channel'), 'Media reads target the matching source channel');
        same(2, $primary->stats(X)['forwarded'], 'A album acknowledgements commit only A deliveries');
        same(2, $secondary->stats(X)['forwarded'], 'B album acknowledgements commit only B deliveries');
        $handler->dispatch(post(OTHER, 103, 'unconfigured'));
        $handler->dispatch(post(X, 103, 'destination update'));
        $handler->dispatch(post(A, 100, 'old A'));
        $handler->dispatch(post(B, 100, 'old B'));
        same(2, $primary->stats(X)['total'], 'Unconfigured/destination/old updates cannot enter A queue');
        same(2, $secondary->stats(X)['total'], 'Unconfigured/destination/old updates cannot enter B queue');

        [$primary, $handler] = fixture([
            ['source' => '@source_a', 'targets' => ['@target_x', '@target_y']],
            ['source' => '@source_b', 'targets' => ['@target_y']],
        ]);
        $secondary = App::queue(B);
        $handler->dispatch(post(A, 101, 'A routed'));
        $handler->dispatch(post(B, 101, 'B routed'));
        same([Y, X], array_keys($primary->targetStats()), 'Per-source A mapping registers both its destinations');
        same([Y], array_keys($secondary->targetStats()), 'Per-source B mapping excludes unrelated destination');
        $ax = $primary->pendingBatches(time(), 0, X)[0]['random_ids'];
        $ay = $primary->pendingBatches(time(), 0, Y)[0]['random_ids'];
        check($ax !== $ay, 'One source retains independent IDs per target');
        $handler->drainQueue();
        same([X, Y, Y], array_column($handler->messages->calls, 'peer'), 'One handler applies each source target mapping');
        same(['A routed', 'A routed', 'B routed'], array_map(static fn(array $call): string => $call['items'][0]['message'], $handler->messages->calls), 'Shared destination keeps source-specific text');
        same(1, $primary->stats(X)['forwarded'], 'A destination X succeeds');
        same(1, $primary->stats(Y)['forwarded'], 'A destination Y succeeds');
        same(1, $secondary->stats(Y)['forwarded'], 'B destination Y succeeds independently');

        [$primary, $handler, $directory] = fixture(['@source_a', '@source_b']);
        $secondary = App::queue(B);
        $handler->dispatch(post(A, 101, 'A frozen one', 888));
        $handler->dispatch(post(A, 102, 'A frozen two', 888));
        $handler->dispatch(post(B, 101, 'B succeeds', 888));
        $handler->dispatch(post(B, 102, 'B second', 888));
        $aRandom = $primary->pendingBatches(time(), 0, X)[0]['random_ids'];
        $handler->messages->errors[X] = new RuntimeException('Offline accepted response lost');
        $handler->drainQueue();
        same(2, $primary->stats(X)['pending'], 'Lost A response retains its entire album');
        same(2, $secondary->stats(X)['forwarded'], 'A transport error does not block healthy B');
        $bSnapshot = durableSnapshot($directory . '/queue-source-' . substr((string) B, 1) . '.sqlite');
        $handler->channels->fixtures[A][101]['message'] = 'later source edit';
        $primary->retryBatch([101, 102], A, 'Offline retry is due', time() - 1, X);
        $handler->drainQueue();
        same($aRandom, $handler->messages->calls[0]['randomIds'], 'Initial A send uses stored random IDs');
        same($aRandom, $handler->messages->calls[2]['randomIds'], 'A reconciliation retains the same random IDs');
        same(['A frozen one', 'A frozen two'], array_column($handler->messages->calls[2]['items'], 'message'), 'A retry retains its frozen body after source edit');
        same(2, $primary->stats(X)['forwarded'], 'Retry reconciles both original A destination messages');
        same($bSnapshot, durableSnapshot($directory . '/queue-source-' . substr((string) B, 1) . '.sqlite'), 'A retry cannot mutate settled B rows or cutoff');

        [$primary, $handler, $directory] = fixture();
        $handler->dispatch(post(A, 101, 'A before upgrade'));
        $handler->drainQueue();
        $oldSnapshot = durableSnapshot($directory . '/queue.sqlite');
        $handler->messages->heads[B] = 500;
        routes($directory, 1, [route('@source_a'), route('@source_b')]);
        $handler->drainQueue();
        $secondary = App::queue(B);
        same(1, App::routeRevision(), 'Dashboard revision is loaded by the live handler');
        same([A, B], array_column($handler->messages->historyCalls, 'peer'), 'Adding source reads only its new activation head');
        same('500', $secondary->getMeta('source_min_id'), 'Added source starts from its own current head');
        same($oldSnapshot, durableSnapshot($directory . '/queue.sqlite'), 'Adding source preserves every old primary row, payload and cutoff');
        same(0, $secondary->stats(X)['total'], 'Source activation does not backfill its history');
        $handler->dispatch(post(B, 500, 'B old at cutoff'));
        $handler->dispatch(post(B, 501, 'B after activation'));
        $handler->drainQueue();
        same(1, $secondary->stats(X)['forwarded'], 'Added source reposts only a subsequent new update');
        same('B after activation', $handler->messages->calls[1]['items'][0]['message'], 'Added source delivers its own new body');
        $beforeRestart = durableSnapshot($directory . '/queue-source-' . substr((string) B, 1) . '.sqlite');
        $historyCount = count($handler->messages->historyCalls);
        $handler->messages->heads[B] = 900;
        $reopened = new Queue($directory . '/queue.sqlite');
        inject(App::config(), $reopened);
        $recovered = new MirrorHandler();
        $recovered->infos = $handler->infos;
        $recovered->messages = $handler->messages;
        $recovered->channels = $handler->channels;
        $recovered->onStart();
        same($historyCount, count($recovered->messages->historyCalls), 'Worker restart does not reset any established source head');
        same('500', App::queue(B)->getMeta('source_min_id'), 'Additional source cutoff survives reopening all queue objects');
        same($beforeRestart, durableSnapshot($directory . '/queue-source-' . substr((string) B, 1) . '.sqlite'), 'Restart preserves additional source ledger, random IDs and payloads');

        [$primary, $handler] = fixture(['@source_a', B], false);
        $handler->messages->heads[B] = 0;
        App::initialize($handler, App::validateAccount($handler));
        $handler->onStart();
        same('0', App::queue(B)->getMeta('source_min_id'), 'An empty new source gets an explicit zero cutoff');
        $handler->dispatch(post(B, 1, 'first message in empty source'));
        $handler->drainQueue();
        same(1, App::queue(B)->stats(X)['forwarded'], 'First subsequent message in empty source is delivered');
        same(0, $primary->stats(X)['total'], 'Empty source delivery cannot create unrelated primary rows');

        [$primary, $handler, $directory] = fixture(['@source_a', '@source_b']);
        $secondary = App::queue(B);
        $handler->dispatch(post(A, 101, 'A pending while disabled'));
        $handler->dispatch(post(B, 101, 'B active'));
        $aRandom = $primary->pendingBatches(time(), 0, X)[0]['random_ids'];
        $historyCount = count($handler->messages->historyCalls);
        routes($directory, 1, [route('@source_a', enabled: false), route('@source_b')]);
        $handler->drainQueue();
        same(1, $primary->stats(X)['pending'], 'Disabling source retains its old pending row');
        same(0, $primary->stats(X)['forwarded'], 'Disabled source cannot drain an old pending row');
        same(1, $secondary->stats(X)['forwarded'], 'Disabled source does not stop another source');
        same($aRandom, $primary->pendingBatches(time(), 0, X)[0]['random_ids'], 'Disable preserves original retry identifiers');
        $handler->dispatch(post(A, 102, 'A update while disabled'));
        same(1, $primary->stats(X)['total'], 'Disabled source updates are not newly queued');
        $handler->messages->heads[A] = 200;
        routes($directory, 2, [route('@source_a'), route('@source_b')]);
        $handler->drainQueue();
        same($historyCount + 1, count($handler->messages->historyCalls), 'Re-enabling source captures a separate current runtime head');
        same('100', $primary->getMeta('source_min_id'), 'Re-enabled source keeps its original activation cutoff');
        same('200', $primary->getMeta('source_runtime_min_id'), 'Re-enabled source records the current runtime cutoff separately');
        same(1, $primary->stats(X)['forwarded'], 'Re-enabling reconciles preserved old pending delivery');
        same($aRandom, $handler->messages->calls[1]['randomIds'], 'Re-enabled send uses pre-disable random IDs');
        $handler->dispatch(post(A, 150, 'missed while source disabled'));
        same(1, $primary->stats(X)['total'], 'Re-enabled source rejects delayed updates from the disabled interval');
        routes($directory, 3, [route('@source_a')]);
        $handler->drainQueue();
        $handler->dispatch(post(B, 102, 'B removed'));
        same(1, $secondary->stats(X)['total'], 'Removed source receives no new queued deliveries');
        same($secondary, App::queue(B), 'Removed source retains its durable queue for later reactivation');

        [$primary, $handler, $directory] = fixture();
        $handler->dispatch(post(A, 101, 'old pending X only'));
        $xRandom = $primary->pendingBatches(time(), 0, X)[0]['random_ids'];
        $handler->messages->heads[A] = 200;
        routes($directory, 1, [route('@source_a', ['@target_x', '@target_y'])]);
        $handler->drainQueue();
        same($xRandom, $handler->messages->calls[0]['randomIds'], 'Adding destination preserves old target pending identity');
        same(1, $primary->stats(X)['forwarded'], 'Existing destination sends its old pending post after addition');
        same(0, $primary->stats(Y)['total'], 'New destination does not receive already queued source history');
        same(200, $primary->targetStats()[Y]['cutoff'], 'New destination stores its own activation cutoff');
        $handler->dispatch(post(A, 150, 'late before Y activation'));
        same(1, $primary->stats(X)['pending'], 'Existing target still accepts valid delayed update after original cutoff');
        same(0, $primary->stats(Y)['total'], 'New target rejects delayed message before its activation head');
        $handler->dispatch(post(A, 201, 'new for X and Y'));
        $handler->drainQueue();
        $handler->drainQueue();
        same(3, $primary->stats(X)['forwarded'], 'Existing target keeps its original cutoff and delivery order');
        same(1, $primary->stats(Y)['forwarded'], 'New target sends only post newer than its activation head');

        [$primary, $handler, $directory] = fixture([
            ['source' => '@source_a', 'targets' => ['@target_x', '@target_y']],
        ]);
        $handler->dispatch(post(A, 101, 'pending for two targets'));
        $yRandom = $primary->pendingBatches(time(), 0, Y)[0]['random_ids'];
        routes($directory, 1, [route('@source_a', ['@target_x'])]);
        $handler->drainQueue();
        same(1, $primary->stats(X)['forwarded'], 'Remaining destination drains normally');
        same(1, $primary->stats(Y)['pending'], 'Removed destination preserves old pending delivery');
        same(0, $primary->stats(Y)['forwarded'], 'Removed destination is not drained');
        same($yRandom, $primary->pendingBatches(time(), 0, Y)[0]['random_ids'], 'Removed destination keeps stable random IDs');
        $handler->dispatch(post(A, 102, 'only current target'));
        same(1, $primary->stats(Y)['total'], 'Removed target receives no new deliveries while inactive');
        $handler->drainQueue();
        $historyCount = count($handler->messages->historyCalls);
        $handler->messages->heads[A] = 300;
        routes($directory, 2, [route('@source_a', ['@target_x', '@target_y'])]);
        $handler->drainQueue();
        same($historyCount + 1, count($handler->messages->historyCalls), 'Restoring old target captures a separate current runtime head');
        same('300', $primary->getMeta('target_runtime_min_id_' . Y), 'Restored target stores current runtime cutoff');
        same(1, $primary->stats(Y)['forwarded'], 'Restored target sends only its preserved pending post');
        same($yRandom, $handler->messages->calls[2]['randomIds'], 'Restored target reconciles the original delivery identity');
        $handler->dispatch(post(A, 200, 'delayed source update'));
        same(1, $primary->stats(Y)['total'], 'Restored target rejects delayed updates from its inactive interval');

        [$primary, $handler] = fixture(['@source_a', '@source_b'], false);
        $handler->infoErrors['@source_b'] = new RPCErrorException('CHANNEL_PRIVATE');
        $binding = App::validateAccount($handler);
        check(!empty($binding['unresolved_sources']), 'Unavailable new source has an explicit validation error');
        App::initialize($handler, $binding);
        $handler->onStart();
        $handler->dispatch(post(A, 101, 'A with broken optional source'));
        $handler->drainQueue();
        same(1, $primary->stats(X)['forwarded'], 'Unavailable optional source does not stop existing valid source');
        same('100', $primary->getMeta('source_min_id'), 'Unavailable optional source does not reset healthy source cutoff');

        [$primary, $handler] = fixture(['@source_a', '@source_b']);
        $secondary = App::queue(B);
        $handler->dispatch(post(A, 101, 'A inaccessible'));
        $handler->dispatch(post(B, 101, 'B remains accessible'));
        $handler->infoErrors[A] = new RPCErrorException('CHANNEL_PRIVATE');
        $handler->dispatch(['_' => 'updateChannel', 'channel_id' => -1000000000000 - A]);
        $handler->drainQueue();
        check(str_contains($primary->getMeta('paused_reason') ?? '', 'CHANNEL_PRIVATE'), 'Source access pause is recorded in that source queue');
        same(1, $primary->stats(X)['pending'], 'Inaccessible source retains its pending body');
        same(1, $secondary->stats(X)['forwarded'], 'Primary source access error does not globally stop secondary source');

        [$primary, $handler] = fixture(['@source_a', '@source_b']);
        $secondary = App::queue(B);
        $handler->dispatch(post(A, 101, 'A session failure'));
        $handler->dispatch(post(B, 101, 'B must wait for account'));
        $handler->messages->errors[X] = new RPCErrorException('AUTH_KEY_UNREGISTERED');
        $handler->drainQueue();
        same('AUTH_KEY_UNREGISTERED', $primary->getMeta('paused_reason'), 'Authorization failure pauses the shared account');
        same(1, count($handler->messages->calls), 'Authorization failure prevents secondary source send');
        same(1, $secondary->stats(X)['pending'], 'Account pause preserves secondary pending delivery');

        [$primary, $handler] = fixture(['@source_a', '@source_b'], false);
        $handler->infos[A]['Chat']['noforwards'] = true;
        $handler->infos['@source_a']['Chat']['noforwards'] = true;
        App::initialize($handler, App::validateAccount($handler));
        $handler->onStart();
        $handler->dispatch(post(A, 101, 'protected A'));
        $handler->dispatch(post(B, 101, 'ordinary B'));
        $handler->drainQueue();
        same(1, $primary->stats(X)['skipped'], 'Protected source cannot bypass Telegram protection');
        same(1, App::queue(B)->stats(X)['forwarded'], 'Protected source does not block unrelated ordinary source');
        same('ordinary B', $handler->messages->calls[0]['items'][0]['message'], 'Only accessible unprotected source sends a body');

        [$primary, $handler, $directory] = fixture();
        $handler->messages->heads[B] = 700;
        $handler->infoErrors['@source_b'] = new RPCErrorException('CHANNEL_PRIVATE');
        routes($directory, 1, [route('@source_a'), route('@source_b')]);
        $handler->dispatch(post(A, 101, 'old source still active'));
        $handler->drainQueue();
        same(1, $primary->stats(X)['forwarded'], 'Failed new dashboard source leaves active old source operating');
        same('100', $primary->getMeta('source_min_id'), 'Failed new source leaves old activation cutoff unchanged');
        unset($handler->infoErrors['@source_b']);
        routes($directory, 2, [route('@source_a'), route('@source_b')]);
        $handler->drainQueue();
        same('700', App::queue(B)->getMeta('source_min_id'), 'Recovered new source captures head only after valid access');
        same(0, App::queue(B)->stats(X)['total'], 'Recovered new source cannot backfill inaccessible history');

        [$primary, $handler] = fixture(['@source_a', '@source_b'], false);
        $handler->messages->historyErrors[B] = new RuntimeException('Offline B activation history failed');
        App::initialize($handler, App::validateAccount($handler));
        $handler->onStart();
        $secondary = App::queue(B);
        same(null, $secondary->getMeta('source_min_id'), 'Failed initial B head cannot invent its activation cutoff');
        same(null, $secondary->getMeta('initialized_at'), 'Failed initial B head cannot mark the source activated');
        check(str_contains($secondary->getMeta('paused_reason') ?? '', 'activation history failed'), 'Initial B head failure is recorded only in B state');
        $handler->dispatch(post(A, 101, 'A after initial B head error'));
        $handler->dispatch(post(B, 101, 'B not activated yet'));
        $handler->drainQueue();
        same(1, $primary->stats(X)['forwarded'], 'Initial B history failure leaves A operating');
        same(0, $secondary->stats()['total'], 'Failed initial B activation cannot accept updates');

        [$primary, $handler, $directory] = fixture();
        $handler->messages->historyErrors[B] = new RPCErrorException('CHANNEL_PRIVATE');
        routes($directory, 1, [route('@source_a'), route('@source_b')]);
        $handler->dispatch(post(A, 101, 'A while new B head fails'));
        $handler->drainQueue();
        $secondary = App::queue(B);
        same(1, $primary->stats(X)['forwarded'], 'Hot-added B history failure cannot block established A');
        same('100', $primary->getMeta('source_min_id'), 'Hot-added B history failure preserves A cutoff');
        same(null, $secondary->getMeta('source_min_id'), 'Hot-added B history failure leaves cutoff unset');
        same(null, $secondary->getMeta('initialized_at'), 'Hot-added B history failure leaves activation unset');
        $handler->dispatch(post(B, 501, 'B update before valid activation'));
        same(0, $secondary->stats()['total'], 'Failed hot-added B activation accepts no source update');
        unset($handler->messages->historyErrors[B]);
        $handler->messages->heads[B] = 600;
        routes($directory, 2, [route('@source_a'), route('@source_b')]);
        $handler->drainQueue();
        same('600', $secondary->getMeta('source_min_id'), 'Recovered B head establishes only its current cutoff');
        $handler->dispatch(post(B, 599, 'B delayed inaccessible history'));
        $handler->dispatch(post(B, 601, 'B after recovered activation'));
        $handler->drainQueue();
        same(1, $secondary->stats(X)['forwarded'], 'Recovered B delivers only a new post after trustworthy activation');
        same('B after recovered activation', $handler->messages->calls[1]['items'][0]['message'], 'Recovered B does not backfill older source bodies');

        [$primary, $handler] = fixture(['@source_a', '@source_b']);
        $secondary = App::queue(B);
        $handler->dispatch(post(A, 101, 'A access failure survives restart'));
        $handler->dispatch(post(B, 101, 'B continues after restart'));
        $handler->infoErrors[A] = new RPCErrorException('CHANNEL_PRIVATE');
        $handler->infoErrors['@source_a'] = new RPCErrorException('CHANNEL_PRIVATE');
        $handler->dispatch(['_' => 'updateChannel', 'channel_id' => -1000000000000 - A]);
        $historyCount = count($handler->messages->historyCalls);
        $recovered = new MirrorHandler();
        $recovered->infos = $handler->infos;
        $recovered->infoErrors = $handler->infoErrors;
        $recovered->messages = $handler->messages;
        $recovered->channels = $handler->channels;
        $recovered->onStart();
        $recovered->drainQueue();
        same($historyCount, count($recovered->messages->historyCalls), 'Restart with isolated primary access error does not reset B history');
        same(1, $primary->stats(X)['pending'], 'Restart preserves inaccessible primary pending delivery');
        same('100', $primary->getMeta('source_min_id'), 'Restart preserves inaccessible primary cutoff');
        same(1, $secondary->stats(X)['forwarded'], 'Restart with primary access pause still delivers valid secondary');
        same('B continues after restart', $recovered->messages->calls[0]['items'][0]['message'], 'Restart attempts only the accessible secondary body');

        [, $handler] = fixture([
            ['source' => '@source_a', 'targets' => ['@source_b']],
            ['source' => '@source_b', 'targets' => ['@target_x']],
        ], false);
        rejected(static fn(): array => App::validateAccount($handler), 'Direct source/destination cycle is rejected before initialization');
        [, $handler] = fixture(['@source_a', '@source_b'], false);
        $handler->infos['@target_x']['bot_api_id'] = B;
        $handler->infos[B]['Chat']['admin_rights'] = ['post_messages' => true];
        rejected(static fn(): array => App::validateAccount($handler), 'Canonical source/destination cycle is rejected even through an alias');

        [$primary, $handler, $directory] = fixture();
        routes($directory, 1, [route('@source_a')]);
        $handler->drainQueue();
        file_put_contents($directory . '/routes.json', '{ invalid configuration');
        $handler->dispatch(post(A, 101, 'retained valid route'));
        $handler->drainQueue();
        same(1, $primary->stats(X)['forwarded'], 'Malformed dashboard revision cannot disable previously valid route');
        same('100', $primary->getMeta('source_min_id'), 'Malformed route edit cannot invent a new cutoff');
    } catch (Throwable $error) {
        $marker = getenv('TELEGRAM_MIRROR_MULTI_SOURCE_RESULT');
        if (is_string($marker) && $marker !== '') { file_put_contents($marker, 'FAIL: ' . $error->getMessage()); }
        throw $error;
    } finally {
        unset($primary, $secondary, $reopened);
        cleanup();
    }
    $result = 'Multi-source: ' . $assertions . " assertions passed.\n";
    $marker = getenv('TELEGRAM_MIRROR_MULTI_SOURCE_RESULT');
    if (is_string($marker) && $marker !== '') { file_put_contents($marker, $result); }
    echo $result;
}
