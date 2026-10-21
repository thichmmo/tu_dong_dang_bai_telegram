<?php

declare(strict_types=1);

// Real caption overflow, handler and SQLite queues with an entirely
// synthetic API. No application config, session credentials or network access.
namespace danog\MadelineProto {
    final class API { public const LOGGED_IN = 4; }
    class RPCErrorException extends \RuntimeException
    {
        public function __construct(public string $rpc) { parent::__construct($rpc); }
    }
    class SimpleEventHandler
    {
        public \TelegramMirror\Tests\CaptionOverflow\Messages $messages;
        public \TelegramMirror\Tests\CaptionOverflow\Channels $channels;
        public array $infos = [];
        public array $infoErrors = [];
        public array $logs = [];
        public function __construct()
        {
            $this->messages = new \TelegramMirror\Tests\CaptionOverflow\Messages();
            $this->channels = new \TelegramMirror\Tests\CaptionOverflow\Channels();
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

namespace TelegramMirror\Tests\CaptionOverflow {
    use danog\MadelineProto\RPCError\RateLimitError;
    use danog\MadelineProto\RPCErrorException;
    use PDO;
    use ReflectionClass;
    use RuntimeException;
    use TelegramMirror\App;
    use TelegramMirror\MirrorHandler;
    use TelegramMirror\Queue;
    use TelegramMirror\Repost;
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
        public array $scripted = [];
        public ?\Closure $beforeSend = null;
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
            return $this->respond($peer, [$random_id], [compact('message', 'entities', 'no_webpage', 'invert_media')], 'sendMessage');
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
            if ($this->beforeSend !== null) { ($this->beforeSend)($this->sendCalls[array_key_last($this->sendCalls)]); }
            $updates = [];
            foreach ($randomIds as $randomId) {
                $key = $peer . ':' . $randomId;
                $this->accepted[$key] ??= ++$this->nextId;
                $updates[] = ['_' => 'updateMessageID', 'random_id' => $randomId, 'id' => $this->accepted[$key]];
            }
            if (isset($this->sendErrors[$peer])) {
                $error = $this->sendErrors[$peer]; unset($this->sendErrors[$peer]); throw $error;
            }
            if (($this->scripted[$method][$peer] ?? []) !== []) {
                $response = array_shift($this->scripted[$method][$peer]);
                if ($response instanceof Throwable) { throw $response; }
                if ($response !== null) { return $response; }
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
        $directory = __DIR__ . '/caption-overflow-fixture-' . bin2hex(random_bytes(6));
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
    function photo(int $id, string $caption, array $entities = [], ?int $group = null): array
    {
        $message = post(A, $id, $caption, $group);
        $message['message'] = $caption;
        $message['entities'] = $entities;
        $message['media'] = ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo', 'id' => 900000 + $id,
            'access_hash' => -987654321, 'file_reference' => "\x00\xff"]];
        return $message;
    }
    function batch(Queue $queue, int $target = X): array
    {
        return $queue->pendingBatches(time(), 0, $target)[0] ?? throw new RuntimeException('Missing offline pending batch');
    }
    function freeze(Queue $queue, MirrorHandler $handler, int $target = X): array
    {
        $batch = batch($queue, $target);
        $queue->freezePayloads(A, Repost::capture($handler, $batch));
        $queue->freezeRepostBatch($batch, $target);
        return [$batch, $queue->frozenPayloads(A, $batch['ids'])];
    }
    function retryDue(Queue $queue, array $batch, int $target = X): void
    {
        $queue->retryBatch($batch['ids'], A, 'Offline retry now due', time() - 1, $target);
    }
    function deliveries(Queue $queue, int $target): array
    {
        return array_values(array_filter(rows($queue), static fn(array $row): bool => $row['target_id'] === $target));
    }
    function reopen(Queue $queue, MirrorHandler $handler, string $directory): array
    {
        $reopened = new Queue($directory . '/queue.sqlite');
        inject(App::config(), $reopened);
        $recovered = new MirrorHandler();
        $recovered->infos = $handler->infos;
        $recovered->messages = $handler->messages;
        $recovered->channels = $handler->channels;
        $recovered->onStart();
        return [$reopened, $recovered];
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
        if (!in_array($table, ['queue_messages', 'queue_deliveries', 'queue_repost_payloads', 'queue_repost_overflow'], true)) { throw new RuntimeException('Unknown offline table'); }
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
        foreach ([str_repeat('a', 1024), str_repeat('é', 1024), str_repeat('😀', 512)] as $caption) {
            [$queue, $handler] = fixture();
            $entities = [['_'=>'messageEntityBold', 'offset'=>0, 'length'=>1024]];
            $handler->dispatch(photo(101, $caption, $entities));
            $original = batch($queue);
            drain($handler, 1);
            same(1024, Repost::utf16Length($caption), 'Caption limit is measured in UTF-16 code units');
            same(['sendMedia'], array_column($handler->messages->sendCalls, 'method'), 'Caption at exact 1024 limit uses the ordinary media send');
            same($caption, $handler->messages->sendCalls[0]['items'][0]['message'], 'Normal caption text stays byte-for-byte unchanged');
            same($entities, $handler->messages->sendCalls[0]['items'][0]['entities'], 'Normal caption entities stay unchanged');
            same($original['random_ids'], $handler->messages->sendCalls[0]['randomIds'], 'Normal media retains its original delivery random ID');
            same([], rows($queue, 'queue_repost_overflow'), 'Normal caption creates no overflow progress record');
            same(1, $queue->stats(X)['forwarded'], 'Normal caption commits ordinary delivery');
        }

        foreach ([str_repeat('a', 1025), str_repeat('😀', 512) . 'a', str_repeat('a', 1023) . '😀'] as $caption) {
            [$queue, $handler] = fixture();
            $entities = [['_'=>'messageEntityBold', 'offset'=>0, 'length'=>1025]];
            $handler->dispatch(photo(101, $caption, $entities));
            $original = batch($queue);
            $captured = Repost::capture($handler, $original);
            drain($handler, 1);
            same(1025, Repost::utf16Length($caption), 'Caption one UTF-16 unit above the media limit is classified as overflow');
            same(['sendMedia', 'sendMessage'], array_column($handler->messages->sendCalls, 'method'), 'Overflow sends media followed by complete text');
            same('', $handler->messages->sendCalls[0]['items'][0]['message'], 'Overflow media caption is empty');
            same([], $handler->messages->sendCalls[0]['items'][0]['entities'], 'Overflow media has no dangling caption entities');
            same($caption, $handler->messages->sendCalls[1]['items'][0]['message'], 'Overflow text retains every source byte including astral Unicode');
            same($entities, $handler->messages->sendCalls[1]['items'][0]['entities'], 'Overflow text retains original UTF-16 entity offsets and lengths');
            check(!in_array($handler->messages->sendCalls[0]['randomIds'][0], $original['random_ids'], true), 'Changed media request uses a new persisted network random ID');
            check($handler->messages->sendCalls[1]['randomIds'][0] !== $handler->messages->sendCalls[0]['randomIds'][0], 'Auxiliary text and changed media have distinct network random IDs');
            same($captured, $queue->frozenPayloads(A, [101]), 'Overflow adaptation does not mutate the frozen original payload');
            same($original['random_ids'], array_column(deliveries($queue, X), 'random_id'), 'Overflow never rewrites original queue delivery random ID');
            same(1, $queue->stats(X)['forwarded'], 'Overflow primary delivery commits after media and text ACKs');
        }

        [$queue, $handler] = fixture();
        $caption = str_repeat('L', 1699) . '😀';
        $entities = [['_'=>'messageEntityBold', 'offset'=>0, 'length'=>1701],
            ['_'=>'messageEntityTextUrl', 'offset'=>1699, 'length'=>2, 'url'=>'https://example.test/full-caption']];
        for ($id = 101; $id <= 105; ++$id) {
            $handler->dispatch(photo($id, $id === 101 ? $caption : 'caption ' . $id,
                $id === 101 ? $entities : [['_'=>'messageEntityItalic', 'offset'=>0, 'length'=>7]], 777));
        }
        $original = batch($queue);
        $captured = Repost::capture($handler, $original);
        $mediaAckAtTextSend = null;
        $handler->messages->beforeSend = function (array $call) use ($queue, $original, $captured, &$mediaAckAtTextSend): void {
            if ($call['method'] === 'sendMessage') {
                $mediaAckAtTextSend = $queue->prepareOverflow($original, X, $captured)['media_ack'];
            }
        };
        drain($handler, 1);
        same(1701, Repost::utf16Length($caption), 'Regression fixture reproduces a 1701-unit caption on a five-photo album');
        same(['sendMultiMedia', 'sendMessage'], array_column($handler->messages->sendCalls, 'method'), 'Five-photo caption overflow preserves a single album and one complete text message');
        $parts = $handler->messages->sendCalls[0]['items'];
        same(5, count($parts), 'Overflow adaptation retains all five album members');
        same([900101, 900102, 900103, 900104, 900105], array_map(static fn(array $part): int => $part['media']['id']['id'], $parts), 'Overflow album preserves original primary media IDs and source order');
        same('', $parts[0]['message'], 'Only the overlong album caption is removed from media');
        same([], $parts[0]['entities'], 'Overlong album caption has no entities left on its empty media caption');
        same(['caption 102', 'caption 103', 'caption 104', 'caption 105'], array_column(array_slice($parts, 1), 'message'), 'Other album captions remain unchanged');
        same($caption, $handler->messages->sendCalls[1]['items'][0]['message'], 'Five-photo overflow posts the complete original caption separately');
        same($entities, $handler->messages->sendCalls[1]['items'][0]['entities'], 'Full caption keeps bold and URL entities unchanged');
        same(true, $handler->messages->sendCalls[1]['items'][0]['no_webpage'], 'Auxiliary caption does not introduce a separate link preview');
        check(is_array($mediaAckAtTextSend) && count($mediaAckAtTextSend) === 5, 'All media ACKs are durable before the auxiliary caption network call');
        same($original['random_ids'], array_map('intval', array_keys($mediaAckAtTextSend)), 'Durable media ACKs translate back to the original delivery random IDs');
        same($captured, $queue->frozenPayloads(A, $original['ids']), 'Five-photo overflow retains exact frozen source payloads');
        same($original['random_ids'], array_column(deliveries($queue, X), 'random_id'), 'Five-photo overflow preserves every original delivery random ID');
        same(5, $queue->stats(X)['forwarded'], 'Five-photo album completes only after full caption ACK');
        same(array_values($mediaAckAtTextSend), array_column(deliveries($queue, X), 'destination_message_id'), 'Primary destination IDs refer to gallery media rather than auxiliary text');

        [$queue, $handler, $directory] = fixture(settings: ['targets'=>['@target_x','@target_y']]);
        $caption = str_repeat('R', 1200);
        $entities = [['_'=>'messageEntityBold', 'offset'=>0, 'length'=>1200]];
        $handler->dispatch(photo(101, $caption, $entities));
        $xBatch = batch($queue, X); $yBatch = batch($queue, Y);
        $captured = Repost::capture($handler, $xBatch);
        $handler->messages->scripted['sendMessage'][X] = [new RuntimeException('Offline caption accepted but acknowledgement timed out')];
        drain($handler, 1);
        same(1, $queue->stats(X)['pending'], 'Auxiliary caption timeout leaves original target delivery pending');
        same(1, $queue->stats(Y)['forwarded'], 'Another target completes independently of caption timeout');
        $xPlan = $queue->prepareOverflow($xBatch, X, $captured);
        same(1, count($xPlan['media_ack']), 'Media ACK remains durable after auxiliary caption timeout');
        same([], $xPlan['caption_acks'], 'Caption timeout cannot fabricate a text ACK');
        same($xBatch['random_ids'], array_column(deliveries($queue, X), 'random_id'), 'Caption timeout preserves original target random ID');
        $ySettled = deliveries($queue, Y);
        $frozen = rows($queue, 'queue_repost_payloads');
        $callsBefore = count($handler->messages->sendCalls);
        [$reopened, $recovered] = reopen($queue, $handler, $directory);
        retryDue($reopened, $xBatch, X);
        drain($recovered, 1);
        $after = array_slice($recovered->messages->sendCalls, $callsBefore);
        same(['sendMessage'], array_column($after, 'method'), 'Restart retries only unacknowledged caption and never resends acknowledged media');
        same([$xPlan['caption_random_ids'][101]], $after[0]['randomIds'], 'Restart reuses persisted auxiliary caption random ID');
        same($caption, $after[0]['items'][0]['message'], 'Restart retains the full frozen caption body');
        same($entities, $after[0]['items'][0]['entities'], 'Restart retains caption entity offsets');
        same($frozen, rows($reopened, 'queue_repost_payloads'), 'Restart reconciliation does not modify frozen payload bytes');
        same($ySettled, deliveries($reopened, Y), 'Restart reconciliation cannot modify another target terminal rows');
        same(1, $reopened->stats(X)['forwarded'], 'Original target completes after auxiliary text acknowledgement is recovered');

        [$queue, $handler, $directory] = fixture();
        $handler->dispatch(photo(101, str_repeat('A', 1200), [], 888));
        $handler->dispatch(photo(102, str_repeat('B', 1300), [], 888));
        $handler->dispatch(photo(103, 'ordinary caption', [], 888));
        $original = batch($queue);
        $captured = Repost::capture($handler, $original);
        $handler->messages->scripted['sendMessage'][X] = [null, new RuntimeException('Offline second caption response lost')];
        drain($handler, 1);
        $plan = $queue->prepareOverflow($original, X, $captured);
        same(3, count($plan['media_ack']), 'Multiple-overflow album persists complete media ACKs');
        same([101], array_keys($plan['caption_acks']), 'First auxiliary caption ACK is durable before later caption failure');
        same(3, $queue->stats(X)['pending'], 'Partial auxiliary caption completion cannot mark any primary gallery delivery complete');
        $callsBefore = count($handler->messages->sendCalls);
        [$reopened, $recovered] = reopen($queue, $handler, $directory);
        retryDue($reopened, $original);
        drain($recovered, 1);
        $after = array_slice($recovered->messages->sendCalls, $callsBefore);
        same(['sendMessage'], array_column($after, 'method'), 'Restart skips acknowledged gallery and first auxiliary caption');
        same([$plan['caption_random_ids'][102]], $after[0]['randomIds'], 'Restart sends only second caption under its original auxiliary random ID');
        same(str_repeat('B', 1300), $after[0]['items'][0]['message'], 'Second auxiliary retry preserves complete second caption');
        same(3, $reopened->stats(X)['forwarded'], 'All primary album rows complete together after every auxiliary caption ACK');

        foreach (['media', 'caption'] as $missingStage) {
            [$queue, $handler] = fixture();
            $handler->dispatch(photo(101, str_repeat('M', 1200)));
            $original = batch($queue);
            $captured = Repost::capture($handler, $original);
            $method = $missingStage === 'media' ? 'sendMedia' : 'sendMessage';
            $handler->messages->scripted[$method][X] = [['_'=>'updates','updates'=>[]]];
            drain($handler, 1);
            $plan = $queue->prepareOverflow($original, X, $captured);
            same(1, $queue->stats(X)['pending'], 'Missing ' . $missingStage . ' ACK cannot finalize original delivery');
            same(null, deliveries($queue, X)[0]['destination_message_id'], 'Missing ' . $missingStage . ' ACK cannot persist primary success ID');
            same([], $plan['caption_acks'], 'Missing ' . $missingStage . ' ACK cannot fabricate auxiliary completion');
            if ($missingStage === 'media') {
                same(null, $plan['media_ack'], 'Missing media ACK cannot be marked durable');
                same(['sendMedia'], array_column($handler->messages->sendCalls, 'method'), 'Missing media ACK gates auxiliary caption send');
            } else { same(1, count($plan['media_ack']), 'Missing caption ACK retains independently confirmed media'); }
            $first = $handler->messages->sendCalls[0]['randomIds'];
            retryDue($queue, $original);
            $callsBefore = count($handler->messages->sendCalls);
            drain($handler, 1);
            $after = array_slice($handler->messages->sendCalls, $callsBefore);
            same($missingStage === 'media' ? ['sendMedia', 'sendMessage'] : ['sendMessage'], array_column($after, 'method'), 'Missing ACK retry resumes at the unconfirmed phase only');
            if ($missingStage === 'media') { same($first, $after[0]['randomIds'], 'Missing media ACK retry reuses the exact persisted changed-media IDs'); }
            same(1, $queue->stats(X)['forwarded'], 'Missing ' . $missingStage . ' ACK reconciles successfully on same-request retry');
        }

        foreach (['media', 'caption'] as $scheduledStage) {
            [$queue, $handler] = fixture();
            $handler->dispatch(photo(101, str_repeat('S', 1200)));
            $original = batch($queue);
            $captured = Repost::capture($handler, $original);
            $method = $scheduledStage === 'media' ? 'sendMedia' : 'sendMessage';
            $handler->messages->scripted[$method][X] = [['_'=>'updates','updates'=>[
                ['_'=>'updateNewScheduledMessage','message'=>['_'=>'message','id'=>9500]],
            ]]];
            drain($handler, 1);
            $plan = $queue->prepareOverflow($original, X, $captured);
            same(1, $queue->stats(X)['pending'], 'Scheduled ' . $scheduledStage . ' ACK cannot finalize primary delivery');
            same(null, deliveries($queue, X)[0]['destination_message_id'], 'Scheduled ' . $scheduledStage . ' ID is never a primary final destination ID');
            same([], $plan['caption_acks'], 'Scheduled ' . $scheduledStage . ' ACK cannot satisfy auxiliary caption completion');
            check(($queue->getMeta('target_pause_' . X) ?? '') !== '', 'Scheduled ' . $scheduledStage . ' ACK requires explicit reconciliation pause');
            $callsBefore = count($handler->messages->sendCalls);
            drain($handler, 1);
            same($callsBefore, count($handler->messages->sendCalls), 'Scheduled ACK reconciliation pause prevents automatic resend');
        }

        [$queue, $handler] = fixture();
        $caption = str_repeat('😀', 2048);
        $entities = [['_'=>'messageEntityBold', 'offset'=>0, 'length'=>4096]];
        $handler->dispatch(photo(101, $caption, $entities));
        drain($handler, 1);
        same(4096, Repost::utf16Length($caption), 'Auxiliary text boundary uses UTF-16 units for astral characters');
        same($caption, $handler->messages->sendCalls[1]['items'][0]['message'], 'Auxiliary text at exact 4096-unit limit remains complete');
        same($entities, $handler->messages->sendCalls[1]['items'][0]['entities'], 'Boundary-length auxiliary text preserves formatting');
        same(1, $queue->stats(X)['forwarded'], 'Exact 4096-unit auxiliary caption completes normally');

        [$queue, $handler] = fixture();
        $caption = str_repeat('😀', 2048) . 'a';
        $handler->dispatch(photo(101, $caption, [['_'=>'messageEntityBold', 'offset'=>0, 'length'=>4097]]));
        $original = batch($queue);
        drain($handler, 1);
        same(4097, Repost::utf16Length($caption), 'Unsupported oversized auxiliary caption fixture exceeds text boundary by one UTF-16 unit');
        same([], $handler->messages->sendCalls, 'Caption beyond auxiliary text limit is never clipped or partly sent');
        same(1, $queue->stats(X)['pending'], 'Unsupported full caption remains pending for reconciliation');
        check(str_contains($queue->getMeta('target_pause_' . X) ?? '', 'REPOST_CAPTION_TEXT_TOO_LONG'), 'Unsupported full caption produces a precise reconciliation pause');
        same($caption, $queue->frozenPayloads(A, [101])[101]['message'], 'Oversized-caption pause preserves full original frozen text');
        same($original['random_ids'], array_column(deliveries($queue, X), 'random_id'), 'Oversized-caption pause preserves original delivery identity');

        [$queue, $handler] = fixture();
        $caption = str_repeat('F', 1200);
        $handler->dispatch(photo(101, $caption));
        [$original, $captured] = freeze($queue, $handler);
        $payloadRows = rows($queue, 'queue_repost_payloads');
        drain($handler, 1);
        same([], $handler->messages->sendCalls, 'Previously frozen overflow without a persisted plan is never silently changed');
        check(str_contains($queue->getMeta('target_pause_' . X) ?? '', 'REPOST_CAPTION_RECONCILIATION_REQUIRED'), 'Old frozen request without overflow plan requires explicit reconciliation');
        same(1, $queue->stats(X)['pending'], 'Old uncertain frozen request remains pending');
        $plan = $queue->prepareOverflow($original, X, $captured, true);
        same(1, $plan['version'], 'Explicit trusted migration creates versioned overflow plan for the same frozen batch');
        same($original['ids'], $plan['ids'], 'Trusted migration retains frozen batch membership');
        same($original['random_ids'], $plan['random_ids'], 'Trusted migration retains original queue random IDs');
        $queue->setMeta('target_pause_' . X, '');
        retryDue($queue, $original);
        drain($handler, 1);
        same(['sendMedia', 'sendMessage'], array_column($handler->messages->sendCalls, 'method'), 'Explicit trusted migration enables the supported media-plus-full-caption flow');
        same($payloadRows, rows($queue, 'queue_repost_payloads'), 'Trusted migration does not alter original frozen payload bytes');
        same(1, $queue->stats(X)['forwarded'], 'Explicitly migrated frozen batch completes after all acknowledgements');

        [$queue, $handler, $directory] = fixture(settings: ['targets'=>['@target_x','@target_y']]);
        $handler->dispatch(photo(101, str_repeat('P', 1200)));
        [$xBatch, $captured] = freeze($queue, $handler, X);
        [$yBatch] = freeze($queue, $handler, Y);
        $xPlan = $queue->prepareOverflow($xBatch, X, $captured, true);
        $yPlan = $queue->prepareOverflow($yBatch, Y, $captured, true);
        same($xPlan, $queue->prepareOverflow($xBatch, X, $captured), 'Overflow plan preparation is idempotent and returns persisted identifiers');
        same([], array_intersect($xPlan['media_random_ids'], $xBatch['random_ids']), 'Overflow media network IDs do not reuse original row IDs');
        same([], array_intersect($xPlan['media_random_ids'], $yPlan['media_random_ids']), 'Destinations use independent persisted media network IDs');
        same([], array_intersect(array_values($xPlan['caption_random_ids']), array_values($yPlan['caption_random_ids'])), 'Destinations use independent auxiliary caption IDs');
        $beforeSchemaRows = rows($queue);
        $beforeSchemaPayloads = rows($queue, 'queue_repost_payloads');
        $beforePlans = rows($queue, 'queue_repost_overflow');
        $reopened = new Queue($directory . '/queue.sqlite');
        same($beforeSchemaRows, rows($reopened), 'Reopening additive overflow schema leaves all original delivery rows intact');
        same($beforeSchemaPayloads, rows($reopened, 'queue_repost_payloads'), 'Additive overflow schema preserves original frozen payload storage');
        same($beforePlans, rows($reopened, 'queue_repost_overflow'), 'Reopening preserves exact overflow network IDs and progress');

        [$queue, $handler, $directory] = fixture();
        $handler->dispatch(photo(101, 'ordinary pre-upgrade caption'));
        drain($handler, 1);
        $beforeSchemaRows = rows($queue);
        $beforeSchemaPayloads = rows($queue, 'queue_repost_payloads');
        // A database from the previous release has every old queue table but
        // none of this feature's auxiliary progress table.
        database($queue)->exec('DROP TABLE queue_repost_overflow');
        $reopened = new Queue($directory . '/queue.sqlite');
        same($beforeSchemaRows, rows($reopened), 'Adding overflow schema to an older database preserves terminal delivery IDs and random IDs');
        same($beforeSchemaPayloads, rows($reopened, 'queue_repost_payloads'), 'Adding overflow schema to an older database preserves frozen payload bytes');
        same([], rows($reopened, 'queue_repost_overflow'), 'Old database migration creates an empty auxiliary overflow table');
    } catch (Throwable $error) {
        $marker = getenv('TELEGRAM_MIRROR_CAPTION_OVERFLOW_RESULT');
        if (is_string($marker) && $marker !== '') { file_put_contents($marker, 'FAIL: ' . $error->getMessage()); }
        throw $error;
    } finally {
        unset($queue, $secondary, $reopened);
        cleanup();
    }
    $result = 'Caption overflow: ' . $assertions . " assertions passed.\n";
    $marker = getenv('TELEGRAM_MIRROR_CAPTION_OVERFLOW_RESULT');
    if (is_string($marker) && $marker !== '') { file_put_contents($marker, $result); }
    echo $result;
}
