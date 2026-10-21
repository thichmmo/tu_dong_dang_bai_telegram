<?php

declare(strict_types=1);

// Real history reconciliation, handler and SQLite queues with an entirely
// synthetic API. No application config, session credentials or network access.
namespace danog\MadelineProto {
    final class API { public const LOGGED_IN = 4; }
    class RPCErrorException extends \RuntimeException
    {
        public function __construct(public string $rpc) { parent::__construct($rpc); }
    }
    class SimpleEventHandler
    {
        public \TelegramMirror\Tests\SourceHistory\Messages $messages;
        public \TelegramMirror\Tests\SourceHistory\Channels $channels;
        public array $infos = [];
        public array $infoErrors = [];
        public array $logs = [];
        public function __construct()
        {
            $this->messages = new \TelegramMirror\Tests\SourceHistory\Messages();
            $this->channels = new \TelegramMirror\Tests\SourceHistory\Channels();
        }
        public function getAuthorization(): int { return API::LOGGED_IN; }
        public function getSelf(): array { return ['id' => 7654321, 'bot' => false]; }
        public function getInfo(int|string $peer): array
        {
            if (isset($this->infoErrors[$peer])) { throw $this->infoErrors[$peer]; }
            return $this->infos[$peer] ?? throw new \RuntimeException('Unknown offline peer');
        }
        public function refreshPeerCache(int ...$peers): void {}
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
        public function dispatch(array $message): void
        {
            $this->channels->fixtures[$this->getId($message)][$message['id']] = $message;
            $this->onUpdateNewChannelMessage(['_' => 'updateNewChannelMessage', 'message' => $message]);
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

namespace TelegramMirror\Tests\SourceHistory {
    use danog\MadelineProto\RPCError\RateLimitError;
    use danog\MadelineProto\RPCErrorException;
    use PDO;
    use ReflectionClass;
    use RuntimeException;
    use TelegramMirror\App;
    use TelegramMirror\MirrorHandler;
    use TelegramMirror\Queue;
    use TelegramMirror\SourceHistory;
    use Throwable;

    require_once dirname(__DIR__) . '/src/Queue.php';
    require_once dirname(__DIR__) . '/src/App.php';
    require_once dirname(__DIR__) . '/src/Repost.php';
    require_once dirname(__DIR__) . '/src/SourceHistory.php';
    require_once dirname(__DIR__) . '/src/MirrorHandler.php';

    const A = -1001234567890;
    const B = -1002234567890;
    const X = -1003234567890;
    const Y = -1004234567890;

    final class Channels
    {
        public array $fixtures = [];
        public function getMessages(int $channel, array $id): array
        {
            return ['_' => 'messages.channelMessages', 'messages' => array_map(
                fn(array $input): array => $this->fixtures[$channel][$input['id']]
                    ?? throw new RuntimeException('Missing offline source message'),
                array_reverse($id),
            )];
        }
    }

    final class Messages
    {
        /** @var array<int,array<int,array>> */
        public array $history = [];
        public array $historyCalls = [];
        public array $historyErrors = [];
        /** Source => offset ID => response/Throwable, consumed exactly once. */
        public array $historyResponses = [];
        public ?\Closure $beforeHistoryReturn = null;
        public array $sendCalls = [];
        public array $sendErrors = [];
        private array $accepted = [];
        private int $nextId = 9000;
        public function getHistory(int $peer, int $limit, int $offset_id = 0, int $offset_date = 0,
            int $add_offset = 0, int $max_id = 0, int $min_id = 0, int $hash = 0,
            float $timeout = 20.0, int $floodWaitLimit = 0): array
        {
            $call = compact('peer', 'limit', 'offset_id', 'offset_date', 'add_offset', 'max_id', 'min_id', 'hash');
            $this->historyCalls[] = $call;
            if (isset($this->historyErrors[$peer])) { throw $this->historyErrors[$peer]; }
            if (array_key_exists($offset_id, $this->historyResponses[$peer] ?? [])) {
                $result = $this->historyResponses[$peer][$offset_id];
                unset($this->historyResponses[$peer][$offset_id]);
                if ($result instanceof Throwable) { throw $result; }
            } else {
                $items = array_filter($this->history[$peer] ?? [], static fn(array $message): bool =>
                    $message['id'] > $min_id && ($max_id === 0 || $message['id'] < $max_id)
                    && ($offset_id === 0 || $message['id'] < $offset_id));
                krsort($items, SORT_NUMERIC);
                $result = ['_' => 'messages.channelMessages', 'messages' => array_values(array_slice($items, 0, $limit, true))];
            }
            if ($this->beforeHistoryReturn !== null) { ($this->beforeHistoryReturn)($call, $result); }
            return $result;
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
            $this->sendCalls[] = compact('peer', 'randomIds', 'items', 'method');
            $updates = [];
            foreach ($randomIds as $randomId) {
                $key = $peer . ':' . $randomId;
                $this->accepted[$key] ??= ++$this->nextId;
                $updates[] = ['_' => 'updateMessageID', 'random_id' => $randomId, 'id' => $this->accepted[$key]];
            }
            if (isset($this->sendErrors[$peer])) {
                $error = $this->sendErrors[$peer]; unset($this->sendErrors[$peer]); throw $error;
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
        catch (Throwable) { check(true, $description); return; }
        check(false, $description);
    }
    function inject(array $config, ?Queue $queue): void
    {
        $reflection = new ReflectionClass(App::class);
        $reflection->getProperty('config')->setValue(null, $config);
        $reflection->getProperty('queue')->setValue(null, $queue);
    }
    /** @return array{Queue,MirrorHandler,string} */
    function fixture(bool $second = false, array $settings = []): array
    {
        global $fixtures;
        App::releaseLock();
        $directory = __DIR__ . '/source-history-fixture-' . bin2hex(random_bytes(6));
        $fixtures[] = $directory;
        if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create offline storage'); }
        file_put_contents($directory . '/telegram.session', 'Offline fixture, no credentials.');
        $queue = new Queue($directory . '/queue.sqlite');
        $config = $settings + ['source' => '@source_a', 'target' => '@target_x', 'targets' => ['@target_x'],
            'storage_dir' => $directory, 'album_settle_seconds' => 0, 'retry_base_seconds' => 10,
            'retry_max_seconds' => 60, 'history_poll_seconds' => 60, 'history_page_size' => 3, 'history_max_pages' => 20];
        if ($second) { $config['sources'] = ['@source_a', '@source_b']; }
        inject($config, $queue);
        if (!App::acquireLock()) { throw new RuntimeException('Cannot acquire offline process lock'); }
        App::enableWorkerEvents();
        $handler = new MirrorHandler();
        foreach (['@source_a' => A, '@source_b' => B, '@target_x' => X, '@target_y' => Y] as $reference => $id) {
            $info = ['type' => 'channel', 'bot_api_id' => $id,
                'Chat' => ['broadcast' => true, 'left' => false, 'admin_rights' => ['post_messages' => true]]];
            $handler->infos[$reference] = $handler->infos[$id] = $info;
        }
        history($handler, A, [post(A, 100, 'old A')]);
        history($handler, B, [post(B, 100, 'old B')]);
        App::initialize($handler, App::validateAccount($handler));
        $handler->onStart();
        $handler->messages->historyCalls = [];
        return [$queue, $handler, $directory];
    }
    function post(int $source, int $id, string $caption = '', ?int $group = null): array
    {
        $message = ['_' => 'message', 'id' => $id, 'date' => time(),
            'peer_id' => ['_' => 'peerChannel', 'channel_id' => -1000000000000 - $source],
            'message' => $caption !== '' ? $caption : (string) $id,
            'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 1]]];
        if ($group !== null) {
            $message['grouped_id'] = $group;
            $message['media'] = ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo',
                'id' => (-$source * 1000) + $id, 'access_hash' => -987654321, 'file_reference' => "\x00\xff"]];
        }
        return $message;
    }
    function history(MirrorHandler $handler, int $source, array $messages): void
    {
        $handler->messages->history[$source] = [];
        foreach ($messages as $message) {
            $handler->messages->history[$source][$message['id']] = $message;
            $handler->channels->fixtures[$source][$message['id']] = $message;
        }
    }
    function due(Queue ...$queues): void
    {
        foreach ($queues as $queue) {
            $queue->setMeta('last_history_checked_at', '0');
            $queue->setMeta('history_retry_at', '0');
        }
    }
    function drain(MirrorHandler $handler, int $times = 10): void
    {
        for ($index = 0; $index < $times; ++$index) { $handler->drainQueue(); }
    }
    function rows(Queue $queue, string $table = 'queue_deliveries'): array
    {
        if (!in_array($table, ['queue_messages', 'queue_deliveries', 'queue_repost_payloads'], true)) { throw new RuntimeException('Unknown offline table'); }
        $database = (new ReflectionClass(Queue::class))->getProperty('database')->getValue($queue);
        return $database->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    function ids(Queue $queue): array
    {
        $ids = array_column(rows($queue), 'message_id');
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
    function database(Queue $queue): PDO
    {
        return (new ReflectionClass(Queue::class))->getProperty('database')->getValue($queue);
    }
    function route(string $source, array $targets = ['@target_x'], bool $enabled = true): array
    {
        return compact('source', 'targets', 'enabled');
    }
    function routes(string $directory, int $revision, array $routes): void
    {
        $path = $directory . '/routes.json';
        file_put_contents($path . '.tmp', json_encode(['version' => 1, 'revision' => $revision, 'routes' => $routes], JSON_THROW_ON_ERROR));
        chmod($path . '.tmp', 0600);
        if (!rename($path . '.tmp', $path)) { throw new RuntimeException('Cannot replace offline routes'); }
    }
    function cleanup(): void
    {
        global $fixtures;
        App::releaseLock(); inject([], null);
        try { App::queues(); } catch (RuntimeException) {}
        foreach ($fixtures as $directory) {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') { unlink($directory . '/' . $entry); }
            }
            rmdir($directory);
        }
    }

    try {
        $api = new MirrorHandler();
        history($api, A, array_map(static fn(int $id): array => post(A, $id), range(99, 108)));
        $collected = SourceHistory::collect($api, A, 100, 3, 10);
        same(range(101, 108), array_column($collected['messages'], 'id'), 'Descending history pages become one complete ascending source sequence');
        same(108, $collected['head'], 'Collector head is the newest source history identifier');
        same(3, $collected['pages'], 'Collector proves lower boundary across multiple pages');
        same([0, 106, 103], array_column($api->messages->historyCalls, 'offset_id'), 'Pagination advances exclusively below each previous page minimum');
        same([A, A, A], array_column($api->messages->historyCalls, 'peer'), 'Every history page stays scoped to its source');
        same([100, 100, 100], array_column($api->messages->historyCalls, 'min_id'), 'Every page excludes the immutable lower scan cursor');
        $api->messages->historyCalls = [];
        $empty = SourceHistory::collect($api, A, 108, 3, 10);
        same([], $empty['messages'], 'Empty later history creates no synthetic post');
        same(108, $empty['head'], 'Empty later history keeps its current cursor');
        same(1, $empty['pages'], 'Empty history requires only one source RPC');

        foreach ([
            'missing messages' => [],
            'non-list messages' => ['messages' => ['bad' => post(A, 101)]],
            'oversized page' => ['messages' => [post(A, 103), post(A, 102), post(A, 101), post(A, 100)]],
            'ascending messages' => ['messages' => [post(A, 101), post(A, 102)]],
            'duplicate identifier' => ['messages' => [post(A, 102), post(A, 102)]],
            'wrong source' => ['messages' => [post(B, 101)]],
            'string identifier' => ['messages' => [array_replace(post(A, 101), ['id' => '101'])]],
            'missing date' => ['messages' => [array_diff_key(post(A, 101), ['date' => true])]],
            'old boundary identifier' => ['messages' => [post(A, 100)]],
            'unknown constructor' => ['messages' => [['_'=> 'unknownMessage', 'id' => 101]]],
        ] as $description => $response) {
            $api->messages->historyResponses[A][0] = $response;
            rejected(static fn(): array => SourceHistory::collect($api, A, 100, 3, 10), 'Collector rejects ' . $description . ' without returning a partial sequence');
        }
        $api->messages->historyResponses[A][105] = ['messages' => [post(A, 105), post(A, 104)]];
        rejected(static fn(): array => SourceHistory::collect($api, A, 100, 4, 10), 'Repeated identifier across pages is not mistaken for complete history');
        rejected(static fn(): array => SourceHistory::collect($api, A, 100, 3, 1), 'Page limit cannot advance a cursor over uncollected older messages');
        $service = ['_' => 'messageService', 'id' => 110,
            'peer_id' => ['_' => 'peerChannel', 'channel_id' => -1000000000000 - A]];
        $deleted = ['_' => 'messageEmpty', 'id' => 109];
        history($api, A, [$service, $deleted, post(A, 108)]);
        $collected = SourceHistory::collect($api, A, 107, 3, 10);
        same([108], array_column($collected['messages'], 'id'), 'Service and deleted placeholders do not become repost payloads');
        same(110, $collected['head'], 'Service and deleted identifiers still advance a completely proven scan head');
        foreach ([A, ['_' => 'peerChannel', 'channel_id' => A]] as $peerRepresentation) {
            $canonical = post(A, 101);
            $canonical['peer_id'] = $peerRepresentation;
            history($api, A, [$canonical]);
            same([101], array_column(SourceHistory::collect($api, A, 100, 3, 10)['messages'], 'id'),
                'Collector accepts exact canonical source peer representation');
        }
        $wrongCanonical = post(A, 101); $wrongCanonical['peer_id'] = B;
        history($api, A, [$wrongCanonical]);
        rejected(static fn(): array => SourceHistory::collect($api, A, 100, 3, 10), 'Collector rejects a different canonical source peer');

        [$queue, $handler, $directory] = fixture();
        history($handler, A, array_map(static fn(int $id): array => post(A, $id, 'history ' . $id), range(101, 105)));
        due($queue);
        $handler->reconcileSources();
        same('105', $queue->getMeta('history_cursor'), 'History alone advances the durable source cursor');
        same(5, $queue->stats(X)['pending'], 'Missing live notifications are recovered into the actual queue');
        same([], $handler->messages->sendCalls, 'History ingestion performs no send before queue drain');
        drain($handler);
        same(5, $queue->stats(X)['forwarded'], 'Recovered history posts reach their configured destination');
        same(['history 101', 'history 102', 'history 103', 'history 104', 'history 105'],
            array_map(static fn(array $call): string => $call['items'][0]['message'], $handler->messages->sendCalls),
            'History recovery delivers the oldest missing source message first');
        $settled = rows($queue);
        $payloads = rows($queue, 'queue_repost_payloads');
        due($queue);
        $handler->reconcileSources();
        drain($handler);
        same(5, count($handler->messages->sendCalls), 'A repeated history check sends no duplicate destination post');
        same($settled, rows($queue), 'A repeated history check leaves delivery IDs, random IDs and terminal rows unchanged');
        same($payloads, rows($queue, 'queue_repost_payloads'), 'A repeated history check leaves frozen payload bytes unchanged');
        $historyCount = count($handler->messages->historyCalls);
        $handler->reconcileSources();
        same($historyCount, count($handler->messages->historyCalls), 'Successful history check respects its next polling interval');
        history($handler, A, array_map(static fn(int $id): array => post(A, $id, 'history ' . $id), range(101, 107)));
        $reopened = new Queue($directory . '/queue.sqlite');
        inject(App::config(), $reopened);
        $recovered = new MirrorHandler();
        $recovered->infos = $handler->infos;
        $recovered->messages = $handler->messages;
        $recovered->channels = $handler->channels;
        $recovered->onStart();
        same('105', $reopened->getMeta('history_cursor'), 'Worker restart preserves history cursor rather than recapturing the current head');
        due($reopened);
        $recovered->reconcileSources();
        drain($recovered);
        same('107', $reopened->getMeta('history_cursor'), 'Restarted worker recovers posts after its persisted scan cursor');
        same(7, $reopened->stats(X)['forwarded'], 'Restart does not replay any previously delivered history identifier');

        [$queue, $handler] = fixture();
        $handler->dispatch(post(A, 109, 'higher live notification'));
        history($handler, A, array_map(static fn(int $id): array => post(A, $id, 'missing ' . $id), range(101, 109)));
        due($queue);
        $handler->reconcileSources();
        same(range(101, 109), ids($queue), 'A higher live identifier cannot hide lower history gaps');
        same('109', $queue->getMeta('history_cursor'), 'Independent scan cursor reaches only the fully collected head');
        drain($handler);
        same(9, $queue->stats(X)['forwarded'], 'Live/history overlap deduplicates while recovering every lower missing message');
        same(9, count($handler->messages->sendCalls), 'One live event plus matching history produces exactly one send per source identifier');

        [$queue, $handler] = fixture(settings: ['history_page_size' => 2]);
        history($handler, A, [post(A, 101, 'before album'), post(A, 102, 'album one', 777),
            post(A, 103, 'album two', 777), post(A, 104, 'album three', 777), post(A, 105, 'after album')]);
        due($queue);
        $handler->reconcileSources();
        same(5, $queue->stats(X)['pending'], 'All pages are ingested before any cross-page album is drained');
        drain($handler);
        same(['sendMessage', 'sendMultiMedia', 'sendMessage'], array_column($handler->messages->sendCalls, 'method'), 'Album split across history pages remains one destination album');
        same(['album one', 'album two', 'album three'], array_column($handler->messages->sendCalls[1]['items'], 'message'), 'Cross-page album preserves every part and source order');
        same(5, $queue->stats(X)['forwarded'], 'Cross-page album and surrounding posts all commit once');

        foreach (['malformed page', 'RPC timeout', 'page cap'] as $failure) {
            [$queue, $handler] = fixture(settings: ['history_page_size' => 2, 'history_max_pages' => $failure === 'page cap' ? 1 : 20]);
            history($handler, A, array_map(static fn(int $id): array => post(A, $id), range(101, 105)));
            if ($failure === 'malformed page') { $handler->messages->historyResponses[A][104] = ['messages' => 'invalid']; }
            if ($failure === 'RPC timeout') { $handler->messages->historyResponses[A][104] = new RuntimeException('Offline history RPC timed out'); }
            $beforeCursor = $queue->getMeta('history_cursor');
            due($queue);
            $handler->reconcileSources();
            same($beforeCursor, $queue->getMeta('history_cursor'), $failure . ' cannot advance scan cursor');
            same(0, $queue->stats(X)['total'], $failure . ' cannot publish the newest partial page');
            check(($queue->getMeta('last_history_error') ?? '') !== '', $failure . ' records an actionable history error');
            check((int) $queue->getMeta('history_retry_at') > time(), $failure . ' schedules a later history retry');
            same('0', $queue->getMeta('history_running'), $failure . ' clears in-flight marker');
            $historyCount = count($handler->messages->historyCalls);
            $handler->reconcileSources();
            same($historyCount, count($handler->messages->historyCalls), $failure . ' respects retry backoff');
            $config = App::config(); $config['history_max_pages'] = 20; inject($config, $queue);
            due($queue);
            $handler->reconcileSources();
            drain($handler);
            same('105', $queue->getMeta('history_cursor'), $failure . ' retries from the last complete cursor');
            same(5, $queue->stats(X)['forwarded'], $failure . ' recovery delivers every missing identifier once');
            same('', $queue->getMeta('last_history_error'), $failure . ' recovery clears its old error');
        }

        [$queue, $handler, $directory] = fixture();
        history($handler, A, array_map(static fn(int $id): array => post(A, $id, 'durable album ' . $id, 999), range(101, 105)));
        database($queue)->exec("CREATE TRIGGER offline_history_failure BEFORE INSERT ON queue_messages WHEN NEW.message_id = 102 BEGIN SELECT RAISE(ABORT, 'Offline history queue commit failed'); END");
        $beforeCursor = $queue->getMeta('history_cursor');
        due($queue);
        $handler->reconcileSources();
        same($beforeCursor, $queue->getMeta('history_cursor'), 'Failed queue commit does not advance the history cursor');
        same('1', $queue->getMeta('history_commit_pending'), 'Partial history ingestion persists a durable delivery containment flag');
        same([101], ids($queue), 'Earlier durable enqueue is retained for deduplicated ingestion retry');
        $partialRandom = rows($queue)[0]['random_id'];
        drain($handler);
        same([], $handler->messages->sendCalls, 'Partial history commit cannot send an incomplete album');
        $reopened = new Queue($directory . '/queue.sqlite');
        inject(App::config(), $reopened);
        $recovered = new MirrorHandler();
        $recovered->infos = $handler->infos;
        $recovered->messages = $handler->messages;
        $recovered->channels = $handler->channels;
        $recovered->onStart();
        drain($recovered);
        same('1', $reopened->getMeta('history_commit_pending'), 'Restart retains partial-history durable containment');
        same([], $recovered->messages->sendCalls, 'Restart cannot send a partially ingested history album');
        database($reopened)->exec('DROP TRIGGER offline_history_failure');
        due($reopened);
        $recovered->reconcileSources();
        same($partialRandom, rows($reopened)[0]['random_id'], 'Partial-ingestion retry preserves earlier delivery random identifier');
        same('0', $reopened->getMeta('history_commit_pending'), 'Complete ingestion and cursor commit release durable containment');
        drain($recovered);
        same(5, $reopened->stats(X)['forwarded'], 'Queue commit retry recovers all identifiers after storage becomes writable');
        same(1, count($recovered->messages->sendCalls), 'Storage recovery sends the whole album in a single API request');

        [$queue, $handler, $directory] = fixture();
        history($handler, A, array_map(static fn(int $id): array => post(A, $id), range(101, 105)));
        $beforeCursor = $queue->getMeta('history_cursor');
        $handler->messages->beforeHistoryReturn = function () use ($handler, $directory): void {
            $handler->messages->beforeHistoryReturn = null;
            routes($directory, 1, [route('@source_a', enabled: false)]);
        };
        due($queue);
        $handler->reconcileSources();
        same($beforeCursor, $queue->getMeta('history_cursor'), 'Disabling a source during awaited history read prevents cursor commit');
        same(0, $queue->stats(X)['total'], 'Disabling a source during awaited history read prevents old-route ingestion');
        drain($handler);
        same([], $handler->messages->sendCalls, 'Disabled source history cannot leak to its old destination');
        history($handler, A, [post(A, 101), post(A, 105), post(A, 200, 'head while disabled')]);
        routes($directory, 2, [route('@source_a')]);
        drain($handler, 1);
        same('100', $queue->getMeta('source_min_id'), 'History reconciliation does not rewrite original source activation cutoff');
        same('200', $queue->getMeta('source_runtime_min_id'), 'Reactivation captures current runtime cutoff before history recovery');
        due($queue);
        $handler->reconcileSources();
        same('200', $queue->getMeta('history_cursor'), 'Old scan cursor is raised to current reactivation cutoff');
        same(0, $queue->stats(X)['total'], 'Reactivated history does not backfill the disabled interval');
        history($handler, A, [post(A, 200), post(A, 201, 'new after reactivation')]);
        due($queue);
        $handler->reconcileSources();
        drain($handler);
        same(1, $queue->stats(X)['forwarded'], 'Reactivated source history recovers only newly eligible posts');
        same('new after reactivation', $handler->messages->sendCalls[0]['items'][0]['message'], 'Reactivation delivers no older source body');

        [$queue, $handler, $directory] = fixture();
        history($handler, A, [post(A, 101, 'captured before target edit')]);
        $beforeCursor = $queue->getMeta('history_cursor');
        $handler->messages->beforeHistoryReturn = function () use ($handler, $directory): void {
            $handler->messages->beforeHistoryReturn = null;
            routes($directory, 1, [route('@source_a', ['@target_y'])]);
        };
        due($queue);
        $handler->reconcileSources();
        same($beforeCursor, $queue->getMeta('history_cursor'), 'Target edit during awaited history read prevents old cursor commit');
        same(0, $queue->stats(X)['total'], 'Target edit during awaited read cannot enqueue stale destination deliveries');
        drain($handler, 1);
        due($queue);
        $handler->reconcileSources();
        drain($handler);
        same(0, $queue->stats(Y)['total'], 'New target cutoff excludes the post captured before its activation');
        history($handler, A, [post(A, 101), post(A, 102, 'after target activation')]);
        due($queue);
        $handler->reconcileSources();
        drain($handler);
        same([Y], array_column($handler->messages->sendCalls, 'peer'), 'Only the currently active destination receives subsequent history');

        [$queue, $handler, $directory] = fixture(second: true);
        $secondary = App::queue(B);
        history($handler, A, [post(A, 101, 'A interrupted by route edit')]);
        history($handler, B, [post(B, 101, 'B old target history'), post(B, 102, 'B head when target changes')]);
        $aCursor = $queue->getMeta('history_cursor'); $bCursor = $secondary->getMeta('history_cursor');
        $handler->messages->beforeHistoryReturn = function (array $call) use ($handler, $directory): void {
            if ($call['peer'] !== A) { return; }
            $handler->messages->beforeHistoryReturn = null;
            routes($directory, 1, [route('@source_a'), route('@source_b', ['@target_y'])]);
            // Simulate a separate cron resuming while A's history RPC awaits.
            // It installs B's new activation into the same running handler.
            $handler->drainQueue();
        };
        due($queue, $secondary);
        $handler->reconcileSources();
        same(1, App::routeRevision(), 'Concurrent drain installs the new two-source route revision');
        same($aCursor, $queue->getMeta('history_cursor'), 'Interrupted A scan cannot commit using the installed newer revision');
        same($bCursor, $secondary->getMeta('history_cursor'), 'Old reconciliation pass cannot advance B under its new target activation');
        $bScans = array_filter($handler->messages->historyCalls,
            static fn(array $call): bool => $call['peer'] === B && $call['limit'] !== 1);
        same([], array_values($bScans), 'Old pass stops before issuing B history RPC with its stale route snapshot');
        same(0, $secondary->stats(X)['total'], 'Changed B target receives no stale delivery for its former destination');
        same(0, $secondary->stats(Y)['total'], 'Interrupted pass does not reinterpret old B history for the new target');
        history($handler, B, [post(B, 101), post(B, 102), post(B, 103, 'B after target switch')]);
        due($queue, $secondary);
        $handler->reconcileSources();
        drain($handler);
        same('103', $secondary->getMeta('history_cursor'), 'Fresh pass advances B using its current target activation');
        same(0, $secondary->stats(X)['total'], 'Fresh pass never creates deliveries to B old target');
        same(1, $secondary->stats(Y)['forwarded'], 'Fresh pass sends only B post after the new target cutoff');
        same([X, Y], array_column($handler->messages->sendCalls, 'peer'), 'Fresh pass applies the current destination independently for A and B');
        same('B after target switch', $handler->messages->sendCalls[1]['items'][0]['message'], 'B new destination receives no body from before its activation');

        [$queue, $handler, $directory] = fixture(second: true);
        $secondary = App::queue(B);
        history($handler, A, [post(A, 101, 'A interrupted by B reactivation')]);
        history($handler, B, [post(B, 101, 'B before reactivation'), post(B, 500, 'B reactivation head')]);
        $bCursor = $secondary->getMeta('history_cursor');
        $handler->messages->beforeHistoryReturn = function (array $call) use ($handler, $directory): void {
            if ($call['peer'] !== A) { return; }
            $handler->messages->beforeHistoryReturn = null;
            routes($directory, 1, [route('@source_a'), route('@source_b', enabled: false)]);
            $handler->drainQueue();
            routes($directory, 2, [route('@source_a'), route('@source_b')]);
            $handler->drainQueue();
        };
        due($queue, $secondary);
        $handler->reconcileSources();
        same(2, App::routeRevision(), 'Concurrent drain installs disabled and re-enabled B revisions');
        same('100', $secondary->getMeta('source_min_id'), 'Concurrent B reactivation preserves its immutable original cutoff');
        same('500', $secondary->getMeta('source_runtime_min_id'), 'Concurrent B reactivation captures its new runtime cutoff');
        same($bCursor, $secondary->getMeta('history_cursor'), 'Old reconciliation pass cannot stamp the new revision onto B old baseline');
        $bScans = array_filter($handler->messages->historyCalls,
            static fn(array $call): bool => $call['peer'] === B && $call['limit'] !== 1);
        same([], array_values($bScans), 'Old pass stops before scanning B with a pre-reactivation baseline');
        same(0, $secondary->stats(X)['total'], 'Old pass cannot enqueue B history below its newly installed runtime cutoff');
        history($handler, B, [post(B, 101), post(B, 500), post(B, 501, 'B after new runtime cutoff')]);
        $handler->messages->historyCalls = [];
        due($queue, $secondary);
        $handler->reconcileSources();
        drain($handler);
        $bScans = array_values(array_filter($handler->messages->historyCalls,
            static fn(array $call): bool => $call['peer'] === B));
        same([500], array_column($bScans, 'min_id'), 'Fresh pass scans B exclusively after the current runtime cutoff');
        same('501', $secondary->getMeta('history_cursor'), 'Fresh pass advances reactivated B to the new eligible history head');
        same([501], ids($secondary), 'Reactivated B history excludes all rows from its disabled interval');
        same(1, $secondary->stats(X)['forwarded'], 'Reactivated B sends only a post newer than its current runtime cutoff');

        [$queue, $handler] = fixture();
        history($handler, A, [post(A, 101)]);
        $beforeCursor = $queue->getMeta('history_cursor');
        $handler->messages->beforeHistoryReturn = function () use ($handler, $queue): void {
            $handler->messages->beforeHistoryReturn = null;
            $queue->setMeta('paused_reason', 'USER_PAUSE');
        };
        due($queue);
        $handler->reconcileSources();
        same($beforeCursor, $queue->getMeta('history_cursor'), 'Manual pause during history read prevents cursor commit');
        same(0, $queue->stats(X)['total'], 'Manual pause during history read prevents new delivery ingestion');
        $queue->setMeta('paused_reason', '');
        due($queue);
        $handler->reconcileSources();
        drain($handler);
        same(1, $queue->stats(X)['forwarded'], 'Resuming manual pause retries the uncommitted history safely');

        [$queue, $handler] = fixture(settings: ['history_page_size' => 2]);
        history($handler, A, [post(A, 101, 'live album one', 888), post(A, 102, 'history album two', 888), post(A, 103, 'history album three', 888)]);
        $nestedHistoryCalls = null;
        $handler->messages->beforeHistoryReturn = function () use ($handler, &$nestedHistoryCalls): void {
            $handler->messages->beforeHistoryReturn = null;
            $handler->dispatch(post(A, 101, 'live album one', 888));
            $handler->drainQueue();
            $count = count($handler->messages->historyCalls);
            $handler->reconcileSources();
            $nestedHistoryCalls = count($handler->messages->historyCalls) - $count;
        };
        due($queue);
        $handler->reconcileSources();
        same(0, $nestedHistoryCalls, 'Overlapping history cron cannot start a second scan');
        same([], $handler->messages->sendCalls, 'Concurrent live album part cannot drain while its source history scan is incomplete');
        same(3, $queue->stats(X)['pending'], 'Live/history overlap deduplicates one part and commits all album members');
        drain($handler);
        same(1, count($handler->messages->sendCalls), 'Live/history overlap sends one complete source album');
        same(['live album one', 'history album two', 'history album three'], array_column($handler->messages->sendCalls[0]['items'], 'message'), 'Concurrent source album retains every caption exactly once');

        [$queue, $handler] = fixture(second: true);
        $secondary = App::queue(B);
        history($handler, A, [post(A, 101, 'A source failed')]);
        history($handler, B, [post(B, 101, 'B history healthy')]);
        $handler->messages->historyErrors[A] = new RPCErrorException('CHANNEL_PRIVATE');
        $beforeCursor = $queue->getMeta('history_cursor');
        due($queue, $secondary);
        $handler->reconcileSources();
        drain($handler);
        same($beforeCursor, $queue->getMeta('history_cursor'), 'Source access failure keeps that source history cursor');
        check(str_contains($queue->getMeta('paused_reason') ?? '', 'CHANNEL_PRIVATE'), 'Source access failure pauses only that source queue');
        same(0, $queue->stats(X)['total'], 'Inaccessible source history is never ingested');
        same('101', $secondary->getMeta('history_cursor'), 'Another source advances its independent history cursor');
        same(1, $secondary->stats(X)['forwarded'], 'One inaccessible source does not stall another source history feed');

        [$queue, $handler] = fixture(second: true);
        $secondary = App::queue(B);
        history($handler, A, [post(A, 101)]);
        history($handler, B, [post(B, 101)]);
        $handler->messages->historyErrors[A] = new RateLimitError('FLOOD_WAIT_120', 120);
        $aCursor = $queue->getMeta('history_cursor'); $bCursor = $secondary->getMeta('history_cursor');
        due($queue, $secondary);
        $handler->reconcileSources();
        same($aCursor, $queue->getMeta('history_cursor'), 'Flood wait does not advance failing source cursor');
        same($bCursor, $secondary->getMeta('history_cursor'), 'Account-wide flood wait prevents another source scan');
        check((int) $queue->getMeta('global_forward_retry_at') >= time() + 119, 'History flood wait respects Telegram delay beyond normal retry cap');
        same([A], array_column($handler->messages->historyCalls, 'peer'), 'Flood wait stops further history RPCs in the shared account');
        drain($handler);
        same([], $handler->messages->sendCalls, 'Flood wait also gates destination sends');

        [$queue, $handler] = fixture();
        due($queue);
        $handler->reconcileSources();
        $handler->dispatch(post(A, 101, 'frozen before history retry'));
        $handler->messages->sendErrors[X] = new RuntimeException('Offline accepted send response lost');
        drain($handler, 1);
        same(1, $queue->stats(X)['pending'], 'Synthetic accepted send remains pending after lost acknowledgement');
        $frozenPayload = rows($queue, 'queue_repost_payloads');
        $frozenRow = rows($queue)[0];
        history($handler, A, [post(A, 101, 'edited source body'), post(A, 102, 'new next post')]);
        due($queue);
        $handler->reconcileSources();
        same($frozenPayload, rows($queue, 'queue_repost_payloads'), 'History rediscovery cannot overwrite a frozen uncertain-send payload');
        same($frozenRow, rows($queue)[0], 'History rediscovery preserves uncertain delivery random ID, batch membership and attempts');
        $queue->retryBatch([101], A, 'Offline retry now due', time() - 1, X);
        drain($handler);
        same($handler->messages->sendCalls[0]['randomIds'], $handler->messages->sendCalls[1]['randomIds'], 'History recovery retry reconciles with the same durable random ID');
        same('frozen before history retry', $handler->messages->sendCalls[1]['items'][0]['message'], 'History recovery retry preserves original frozen text despite later source edit');
        same(2, $queue->stats(X)['forwarded'], 'Uncertain old send and new history post both settle exactly once');
    } catch (Throwable $error) {
        $marker = getenv('TELEGRAM_MIRROR_SOURCE_HISTORY_RESULT');
        if (is_string($marker) && $marker !== '') { file_put_contents($marker, 'FAIL: ' . $error->getMessage()); }
        throw $error;
    } finally {
        unset($queue, $secondary, $reopened);
        cleanup();
    }
    $result = 'Source history: ' . $assertions . " assertions passed.\n";
    $marker = getenv('TELEGRAM_MIRROR_SOURCE_HISTORY_RESULT');
    if (is_string($marker) && $marker !== '') { file_put_contents($marker, $result); }
    echo $result;
}
