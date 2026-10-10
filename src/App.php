<?php

declare(strict_types=1);

namespace TelegramMirror;

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\Settings;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/StartupValidationException.php';

/** Process configuration and local state; never expose this application via HTTP. */
final class App
{
    /** @var array<string, mixed> */
    private static array $config = [];
    private static ?Queue $queue = null;
    /** @var array<int, Queue> */
    private static array $sourceQueues = [];
    private static ?\WeakReference $sourceQueueOwner = null;
    private static int $primarySourceId = 0;
    private static ?string $routesFingerprint = null;
    private static int $routesRevision = 0;
    /** @var resource|null */
    private static mixed $lock = null;
    private static ?int $lockOwnerPid = null;
    private static bool $lockShutdownRegistered = false;
    private static bool $workerEvents = false;
    private static ?int $workerEventPid = null;

    public static function boot(bool $autoload = true): void
    {
        self::disableWorkerEvents();
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Ứng dụng chỉ chạy bằng PHP CLI trong Terminal/SSH.');
        }
        if (PHP_VERSION_ID < 80300 || PHP_INT_SIZE !== 8) {
            throw new RuntimeException('Cần PHP CLI 8.3 trở lên, 64 bit.');
        }
        umask(0077);
        $root = dirname(__DIR__);
        self::assertPrivatePath($root);
        $file = $root . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('Chưa có config.php. Sao chép config.example.php và điền cấu hình.');
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException('config.php phải trả về một mảng cấu hình.');
        }
        foreach (['source', 'storage_dir'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
                throw new RuntimeException("Cấu hình $key phải là chuỗi không rỗng.");
            }
        }
        $config['source'] = self::normalizePeer($config['source'], 'source');
        $targets = $config['targets'] ?? (isset($config['target']) ? [$config['target']] : []);
        if (!is_array($targets) || !array_is_list($targets) || $targets === []) {
            throw new RuntimeException('targets phải là danh sách channel đích không rỗng.');
        }
        $config['targets'] = array_map(static fn(mixed $peer): int|string => self::normalizePeer($peer, 'targets'), $targets);
        if (count(array_unique($config['targets'], SORT_REGULAR)) !== count($config['targets'])) {
            throw new RuntimeException('targets không được chứa channel trùng nhau.');
        }
        $config['target'] = self::normalizePeer($config['target'] ?? $config['targets'][0], 'target');
        if (!in_array($config['target'], $config['targets'], true)) {
            throw new RuntimeException('Channel target cũ phải được giữ trong targets.');
        }
        $storage = rtrim($config['storage_dir'], '/');
        if ($storage === '' || $storage[0] !== '/') {
            throw new RuntimeException('storage_dir phải là đường dẫn tuyệt đối.');
        }
        self::assertPrivatePath($storage);
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            throw new RuntimeException('Không tạo được storage_dir.');
        }
        $storage = realpath($storage);
        if ($storage === false || !is_writable($storage)) {
            throw new RuntimeException('storage_dir không ghi được.');
        }
        self::assertPrivatePath($storage);
        @chmod($storage, 0700);
        $config['storage_dir'] = $storage;
        $config += [
            'album_settle_seconds' => 5,
            'retry_base_seconds' => 15,
            'retry_max_seconds' => 3600,
            'telegram_transport' => 'tcp',
        ];
        if (!in_array($config['telegram_transport'], ['tcp', 'https', 'wss'], true)) {
            throw new RuntimeException('telegram_transport phải là tcp, https hoặc wss.');
        }
        foreach (['album_settle_seconds', 'retry_base_seconds', 'retry_max_seconds'] as $key) {
            if (!is_int($config[$key]) || $config[$key] < 1) {
                throw new RuntimeException("$key phải là số nguyên dương.");
            }
        }
        if ($config['retry_max_seconds'] < $config['retry_base_seconds']) {
            throw new RuntimeException('retry_max_seconds phải lớn hơn hoặc bằng retry_base_seconds.');
        }
        self::$config = $config;
        require_once __DIR__ . '/Queue.php';
        self::$queue = new Queue(self::path('queue.sqlite'));
        self::syncSourceQueues();
        self::reloadRoutes();
        self::sourceConfigs();
        if ($autoload) {
            if (!is_int($config['api_id'] ?? null) || $config['api_id'] < 1
                || !is_string($config['api_hash'] ?? null)
                || !preg_match('/^[a-fA-F0-9]{32}$/D', $config['api_hash'])) {
                throw new RuntimeException('Điền api_id và api_hash hợp lệ vào config.php.');
            }
            $vendor = $root . '/vendor/autoload.php';
            if (!is_file($vendor)) {
                throw new RuntimeException('Chưa cài dependency. Chạy composer install --no-dev.');
            }
            require_once $vendor;
        }
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return self::$config;
    }

    public static function queue(?int $sourceId = null): Queue
    {
        $primary = self::$queue ?? throw new RuntimeException('App chưa được khởi tạo.');
        if ($sourceId === null) { return $primary; }
        self::syncSourceQueues();
        if ((int) $primary->getMeta('source_id') === $sourceId || self::$primarySourceId === $sourceId) { return $primary; }
        return self::$sourceQueues[$sourceId] ?? throw new RuntimeException('Nguồn chưa được xác định trong storage.');
    }

    /** @return array<int, Queue> Canonical source IDs, including retained inactive queues. */
    public static function queues(): array
    {
        self::syncSourceQueues();
        $primaryId = (int) self::queue()->getMeta('source_id');
        $primaryId = $primaryId < -1000000000000 ? $primaryId : self::$primarySourceId;
        return ($primaryId < -1000000000000 ? [$primaryId => self::queue()] : []) + self::$sourceQueues;
    }

    /** @return list<array{source:int|string,target:int|string,targets:list<int|string>,enabled:bool}> */
    public static function sourceConfigs(): array
    {
        $routes = self::$config['_managed_routes'] ?? null;
        if ($routes !== null) { return $routes; }
        $primary = self::$config['source'];
        $targets = self::$config['targets'] ?? [self::$config['target']];
        $routes = [['source' => $primary, 'target' => self::$config['target'], 'targets' => $targets, 'enabled' => true]];
        if (!is_array(self::$config['sources'] ?? []) || !array_is_list(self::$config['sources'] ?? [])) {
            throw new RuntimeException('sources phải là danh sách nguồn.');
        }
        $configured = [];
        foreach (self::$config['sources'] ?? [] as $route) {
            $entry = is_array($route) ? $route : ['source' => $route];
            $entry['source'] = self::normalizePeer($entry['source'] ?? null, 'source');
            if (isset($configured[(string)$entry['source']])) { throw new RuntimeException('sources không được chứa channel trùng nhau.'); }
            $configured[(string)$entry['source']] = true;
            if ($entry['source'] === $primary) {
                $routes[0] = $entry + ['target' => self::$config['target'], 'targets' => $targets, 'enabled' => true];
            } else { $routes[] = $entry + ['targets' => $targets, 'enabled' => true]; }
        }
        return self::normalizeRoutes($routes, count($routes) > 1);
    }

    public static function routeRevision(): int
    {
        self::syncSourceQueues();
        return self::$routesRevision;
    }

    /** Read an atomic local dashboard revision; never reconnect the Telegram session. */
    public static function reloadRoutes(): bool
    {
        self::syncSourceQueues();
        $file = self::path('routes.json');
        if (!is_file($file)) { return false; }
        if (is_link($file) || filesize($file) > 262144) { throw new RuntimeException('Tệp cấu hình nguồn không hợp lệ.'); }
        $raw = file_get_contents($file);
        if ($raw === false) { throw new RuntimeException('Không đọc được cấu hình nguồn.'); }
        $fingerprint = hash('sha256', $raw);
        if ($fingerprint === self::$routesFingerprint) { return false; }
        $document = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document) || ($document['version'] ?? null) !== 1
            || !is_int($document['revision'] ?? null) || $document['revision'] < 0
            || !is_array($document['routes'] ?? null) || !array_is_list($document['routes'])) {
            throw new RuntimeException('Cấu hình nguồn phải có version 1, revision và routes hợp lệ.');
        }
        if (self::$routesFingerprint !== null && $document['revision'] <= self::$routesRevision) {
            throw new RuntimeException('Bản cập nhật nguồn phải có revision lớn hơn bản đang chạy.');
        }
        $routes = self::normalizeRoutes($document['routes']);
        self::$config['_managed_routes'] = $routes;
        self::$routesRevision = $document['revision'];
        self::$routesFingerprint = $fingerprint;
        return true;
    }

    /** @param list<mixed> $routes @return list<array{source:int|string,target:int|string,targets:list<int|string>,enabled:bool}> */
    private static function normalizeRoutes(array $routes, bool $rejectLoops = true): array
    {
        if (count($routes) > 100) { throw new RuntimeException('Chỉ hỗ trợ tối đa 100 nguồn.'); }
        $normalized = []; $sources = [];
        foreach ($routes as $route) {
            if (!is_array($route)) { throw new RuntimeException('Mỗi nguồn phải có source và targets.'); }
            $source = self::normalizePeer($route['source'] ?? null, 'source');
            if (isset($sources[(string)$source])) { throw new RuntimeException('sources không được chứa channel trùng nhau.'); }
            $targets = $route['targets'] ?? null;
            if (!is_array($targets) || !array_is_list($targets) || $targets === [] || count($targets) > 100) {
                throw new RuntimeException('Mỗi nguồn cần danh sách targets không rỗng, tối đa 100 đích.');
            }
            $targets = array_map(static fn(mixed $value): int|string => self::normalizePeer($value, 'targets'), $targets);
            if (count(array_unique($targets, SORT_REGULAR)) !== count($targets)) { throw new RuntimeException('targets không được chứa channel trùng nhau.'); }
            $enabled = $route['enabled'] ?? true;
            if (!is_bool($enabled)) { throw new RuntimeException('enabled phải là true hoặc false.'); }
            $sources[(string)$source] = $enabled;
            $target = self::normalizePeer($route['target'] ?? $targets[0], 'target');
            $normalized[] = compact('source', 'target', 'targets', 'enabled');
        }
        foreach ($normalized as $route) {
            if (!$route['enabled']) { continue; }
            foreach ($route['targets'] as $target) {
                if ($rejectLoops && !empty($sources[(string)$target])) { throw new StartupValidationException('Không xác định được channel đích: SOURCE_TARGET_LOOP.'); }
            }
        }
        return $normalized;
    }

    private static function syncSourceQueues(): void
    {
        if (self::$sourceQueueOwner !== null && self::$sourceQueueOwner->get() === self::$queue && self::$queue !== null) { return; }
        self::$sourceQueueOwner = self::$queue === null ? null : \WeakReference::create(self::$queue);
        self::$sourceQueues = [];
        self::$primarySourceId = 0;
        self::$routesFingerprint = null;
        self::$routesRevision = 0;
        if (self::$queue === null) { return; }
        $primaryId = (int) self::$queue->getMeta('source_id');
        if ($primaryId < -1000000000000) { self::$primarySourceId = $primaryId; }
        $refs = json_decode(self::$queue->getMeta('source_refs') ?? '{}', true);
        foreach (is_array($refs) ? $refs : [] as $id => $reference) {
            $id = (int)$id;
            if ($id >= -1000000000000 || $id === $primaryId) { continue; }
            $path = self::sourceQueuePath($id);
            if (is_file($path)) { self::$sourceQueues[$id] = new Queue($path); }
        }
    }

    private static function sourceQueuePath(int $sourceId): string
    {
        if ($sourceId >= -1000000000000) { throw new RuntimeException('ID nguồn không hợp lệ.'); }
        return self::path('queue-source-' . substr((string)$sourceId, 1) . '.sqlite');
    }

    private static function bindSourceQueue(int $sourceId, bool $primary): Queue
    {
        self::syncSourceQueues();
        if ($primary || self::$primarySourceId === $sourceId) { self::$primarySourceId = $sourceId; return self::queue(); }
        return self::$sourceQueues[$sourceId] ??= new Queue(self::sourceQueuePath($sourceId));
    }

    public static function path(string $filename): string
    {
        return self::$config['storage_dir'] . '/' . $filename;
    }

    public static function acquireLock(): bool
    {
        // POSIX close-on-exec keeps MadelineProto IPC subprocesses from
        // retaining this owner's lock after termination.
        $lock = fopen(self::path('process.lock'), 'ce');
        if ($lock === false) {
            throw new RuntimeException('Không mở được khóa tiến trình trong storage.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }
        self::$lock = $lock;
        self::$lockOwnerPid = getmypid();
        if (!self::$lockShutdownRegistered) {
            register_shutdown_function([self::class, 'releaseLock']);
            self::$lockShutdownRegistered = true;
        }
        return true;
    }

    /** Release the shared file lock before inherited descriptors can retain it. */
    public static function releaseLock(): void
    {
        self::disableWorkerEvents();
        $lock = self::$lock;
        $ownerPid = self::$lockOwnerPid;
        self::$lock = null;
        self::$lockOwnerPid = null;
        if (is_resource($lock)) {
            // flock ownership follows the open file description. Closing only
            // this descriptor leaves the lock held by an inherited IPC child.
            if ($ownerPid === getmypid()) { flock($lock, LOCK_UN); }
            fclose($lock);
        }
    }

    /** Only the process that acquired this application's lock may deliver updates. */
    public static function enableWorkerEvents(): void
    {
        if (!is_resource(self::$lock) || self::$lockOwnerPid !== getmypid()) { throw new RuntimeException('Worker event mode requires the owned process lock.'); }
        self::$workerEventPid = getmypid();
        self::$workerEvents = true;
    }

    public static function workerEventsAllowed(): bool
    {
        return self::$workerEvents && is_resource(self::$lock)
            && self::$lockOwnerPid === getmypid() && self::$workerEventPid === getmypid();
    }

    public static function disableWorkerEvents(): void
    {
        self::$workerEvents = false;
        self::$workerEventPid = null;
    }

    public static function settings(): Settings
    {
        $settings = new Settings();
        $settings->getAppInfo()
            ->setApiId(self::$config['api_id'])
            ->setApiHash(self::$config['api_hash']);
        $settings->getLogger()->setType(Logger::FILE_LOGGER)
            ->setExtra(self::path('madeline.log'))
            ->setMaxSize(25 * 1024 * 1024);
        $settings->getRpc()->setFloodTimeout(5);
        $transport = self::$config['telegram_transport'] ?? 'tcp';
        if ($transport === 'https') {
            $settings->getConnection()->setProtocol(\danog\MadelineProto\Stream\MTProtoTransport\HttpsStream::class);
        } elseif ($transport === 'wss') {
            $settings->getConnection()
                ->setProtocol(\danog\MadelineProto\Stream\MTProtoTransport\AbridgedStream::class)
                ->setTransport(\danog\MadelineProto\Stream\Transport\WssStream::class)
                ->setObfuscated(true)
                ->setRetry(false);
        }
        return $settings;
    }

    public static function requireInitialized(): void
    {
        foreach (['validated_at', 'source_id', 'target_id', 'account_id'] as $key) {
            if (self::queue()->getMeta($key) === null) {
                throw new StartupValidationException('Chưa khởi tạo thành công. Chạy php bin/login.php trước.');
            }
        }
        if (!file_exists(self::path('telegram.session'))) {
            throw new StartupValidationException('Không tìm thấy session. Chạy php bin/login.php trong Terminal.');
        }
        // This also runs in bin/worker.php before MadelineProto connects. A
        // watchdog must not repeatedly call Telegram for a persistent pause.
        $reason = self::queue()->getMeta('paused_reason');
        $isolatedSourcePause = str_starts_with($reason ?? '', 'SOURCE_ACCESS:')
            && (isset(self::$config['_managed_routes']) || count(self::sourceConfigs()) > 1);
        if ($reason && !$isolatedSourcePause) {
            self::fail(new RuntimeException('Worker đang tạm dừng: ' . $reason
                . '. Sửa quyền/cấu hình và chạy php bin/login.php trước.'));
        }
        $retryAt = (int) self::queue()->getMeta('startup_retry_at');
        if ($retryAt > time()) {
            self::fail(new RuntimeException('Chưa đến lượt thử khởi động lại; còn '
                . ($retryAt - time()) . ' giây.'));
        }
    }

    /**
     * Validate the account and refreshed channel permissions without sending.
     * Accepts an API instance or EventHandler because both export these methods.
     *
     * Target permission failures are returned independently of source/account failures.
     * @return array<string, mixed>
     */
    public static function validateAccount(object $api): array
    {
        if ($api->getAuthorization() !== API::LOGGED_IN) {
            throw new StartupValidationException('Session chưa đăng nhập. Chạy php bin/login.php trong Terminal.');
        }
        $self = $api->getSelf();
        if ($self === false || ($self['bot'] ?? false)) {
            throw new StartupValidationException('Tool cần tài khoản Telegram người dùng, không dùng bot token.');
        }
        $accountId = (int)$self['id'];
        $previousAccount = self::queue()->getMeta('account_id');
        if ($previousAccount !== null && $previousAccount !== (string)$accountId) {
            throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác.');
        }
        $routes = self::sourceConfigs();
        $managed = isset(self::$config['_managed_routes']);
        $isolateSources = $managed || count($routes) > 1;
        $storedRefs = json_decode(self::queue()->getMeta('source_refs') ?? '{}', true);
        $storedRefs = is_array($storedRefs) ? $storedRefs : [];
        $primaryId = self::queue()->getMeta('source_id');
        if ($primaryId !== null) { $storedRefs[(int)$primaryId] ??= self::$config['source']; }
        $resolved = []; $errors = []; $unresolved = []; $sourceIds = []; $seenSourceIds = [];
        foreach ($routes as $route) {
            $reference = $route['source'];
            $knownId = is_int($reference) ? $reference : null;
            foreach ($storedRefs as $id => $storedReference) {
                if ($storedReference === $reference) { $knownId = (int)$id; break; }
            }
            $primary = $reference === self::$config['source'] || $primaryId !== null && $knownId === (int)$primaryId;
            if (!$route['enabled']) {
                if ($knownId !== null) {
                    if (isset($seenSourceIds[$knownId])) { throw new StartupValidationException('sources chứa ID channel trùng nhau.'); }
                    $seenSourceIds[$knownId] = true;
                }
                continue;
            }
            try {
                $source = self::validateSource($api, null, $reference);
                $id = (int)$source['bot_api_id'];
                if ($id >= -1000000000000) { throw new StartupValidationException('Nguồn phải có ID channel hợp lệ.'); }
                if ($knownId !== null && $knownId !== $id || $primary && $primaryId !== null && (int)$primaryId !== $id) {
                    throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác: SOURCE_IDENTITY_CHANGED.');
                }
                if (isset($seenSourceIds[$id])) { throw new StartupValidationException('sources chứa ID channel trùng nhau.'); }
                $seenSourceIds[$id] = true;
                $sourceIds[$id] = true;
                $queue = self::bindSourceQueue($id, $primary);
                foreach (['account_id' => $accountId, 'source_id' => $id] as $key => $value) {
                    if ($queue->getMeta($key) !== null && $queue->getMeta($key) !== (string)$value) {
                        throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác.');
                    }
                }
                $resolved[$id] = ['route' => $route, 'source' => $source, 'queue' => $queue];
            } catch (Throwable $error) {
                if (self::isAccountFailure($error) || $error instanceof RateLimitError
                    || !$isolateSources && $primary || str_contains($error->getMessage(), 'ID channel trùng nhau')
                    || str_contains($error->getMessage(), 'tài khoản/cặp channel khác')) { throw $error; }
                if ($knownId !== null) {
                    if (isset($seenSourceIds[$knownId])) { throw new StartupValidationException('sources chứa ID channel trùng nhau.'); }
                    $seenSourceIds[$knownId] = true;
                    $sourceIds[$knownId] = true;
                    self::bindSourceQueue($knownId, $primary);
                    $errors[$knownId] = 'SOURCE_ACCESS: ' . $error->getMessage();
                }
                $unresolved[(string)$reference] = 'SOURCE_ACCESS: ' . $error->getMessage();
            }
        }
        // Resolve all sources first: a destination of any route must not feed
        // another source, even if two usernames name the same canonical peer.
        $bindings = [];
        foreach ($resolved as $id => $entry) {
            try {
                $bindings[$id] = self::validateRouteTargets($api, $entry['queue'], $entry['route'],
                    $accountId, $id, $entry['source'], array_keys($sourceIds), $managed);
            } catch (Throwable $error) {
                if (!$managed || self::isAccountFailure($error) || $error instanceof RateLimitError
                    || str_contains($error->getMessage(), 'SOURCE_TARGET_LOOP')
                    || str_contains($error->getMessage(), 'ID channel trùng nhau')) { throw $error; }
                $errors[$id] = 'SOURCE_ACCESS: ' . $error->getMessage();
                $unresolved[(string)$entry['route']['source']] = $errors[$id];
            }
        }
        $legacy = $primaryId === null ? null : ($bindings[(int)$primaryId] ?? null);
        if ($legacy === null) {
            foreach ($bindings as $id => $binding) {
                if (self::queue($id) === self::queue()) { $legacy = $binding; break; }
            }
        }
        if ($legacy === null) {
            if ($primaryId === null) { throw new StartupValidationException('Chưa khởi tạo nguồn chính; giữ nguồn source ban đầu khi đăng nhập lần đầu.'); }
            $legacy = ['account_id' => $accountId, 'source_id' => (int)$primaryId,
                'target_id' => (int)self::queue()->getMeta('target_id'), 'source_protected' => self::queue()->getMeta('source_protected') === '1',
                'targets' => [], 'unresolved_targets' => [], 'source_reference' => self::$config['source']];
        }
        return $legacy + ['sources' => $bindings, 'source_errors' => $errors, 'unresolved_sources' => $unresolved];
    }

    /** @param array<string,mixed> $route @param list<int> $sourceIds @return array<string,mixed> */
    private static function validateRouteTargets(object $api, Queue $queue, array $route,
        int $accountId, int $sourceId, array $source, array $sourceIds, bool $managed): array
    {
        $storedRefs = json_decode($queue->getMeta('target_refs') ?? '{}', true);
        $storedRefs = is_array($storedRefs) ? $storedRefs : [];
        $legacyPrimary = $queue->getMeta('target_id');
        if ($legacyPrimary !== null && (int)$legacyPrimary >= -1000000000000) {
            throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác hoặc có ID đích không hợp lệ.');
        }
        $targets = []; $unresolved = [];
        foreach ($route['targets'] as $reference) {
            $id = is_int($reference) ? $reference : null;
            foreach ($storedRefs as $storedId => $storedReference) {
                if ($storedReference === $reference) { $id = (int)$storedId; break; }
            }
            if ($id === null && !$managed && $reference === $route['target'] && $legacyPrimary !== null) { $id = (int)$legacyPrimary; }
            $knownId = $id;
            try {
                $info = $api->getInfo($reference);
                $resolvedId = (int)$info['bot_api_id'];
                if ($knownId !== null && $resolvedId !== $knownId) {
                    $targets[$knownId] = ['id' => $knownId, 'reference' => $reference, 'valid' => false, 'error' => 'TARGET_IDENTITY_CHANGED'];
                    continue;
                }
                if (in_array($resolvedId, $sourceIds, true)) {
                    if (!$managed && count($sourceIds) === 1) { $unresolved[(string)$reference] = 'SOURCE_TARGET_LOOP'; continue; }
                    throw new StartupValidationException('Không xác định được channel đích: SOURCE_TARGET_LOOP.');
                }
                $id = $resolvedId;
                $api->refreshPeerCache($id);
                $info = $api->getInfo($id);
                $error = self::targetError($info, $sourceId);
                if ($id >= -1000000000000) { $id = null; }
            } catch (Throwable $failure) {
                if (!$failure instanceof RPCErrorException || $failure instanceof RateLimitError || !self::isTargetFailure($failure)) { throw $failure; }
                $error = $failure->rpc;
            }
            if ($id === null) { $unresolved[(string)$reference] = $error; continue; }
            if (isset($targets[$id])) { throw new StartupValidationException('targets chứa ID channel trùng nhau.'); }
            $targets[$id] = ['id' => $id, 'reference' => $reference, 'valid' => $error === null, 'error' => $error];
        }
        if (!$managed && $legacyPrimary !== null && !isset($targets[(int)$legacyPrimary])) {
            throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác. Channel đích cũ không được đổi hoặc xóa.');
        }
        if ($targets === []) { throw new StartupValidationException('Không xác định được channel đích; kiểm tra username/quyền truy cập.'); }
        if (!$managed) {
            $previousTargets = json_decode($queue->getMeta('target_ids') ?? '[]', true);
            foreach (is_array($previousTargets) ? $previousTargets : [] as $previousTarget) {
                if (!isset($targets[(int)$previousTarget])) { throw new StartupValidationException('Không được xóa channel đích đã gắn với storage.'); }
            }
        }
        $primary = $legacyPrimary;
        if ($primary === null) {
            foreach ($targets as $id => $target) {
                if ($target['reference'] === $route['target'] || is_int($route['target']) && $id === $route['target']) { $primary = $id; break; }
            }
            if ($primary === null) { throw new StartupValidationException('Không xác định được channel target chính; kiểm tra cấu hình và quyền truy cập.'); }
        }
        return ['account_id' => $accountId, 'source_id' => $sourceId, 'target_id' => (int)$primary,
            'source_reference' => $route['source'], 'source_protected' => !empty($source['Chat']['noforwards']),
            'targets' => $targets, 'unresolved_targets' => $unresolved];
    }

    /** Refresh the source independently when a forward error could name either peer. */
    public static function validateSource(object $api, ?int $sourceId = null, int|string|null $reference = null): array
    {
        $source = $api->getInfo($sourceId ?? $reference ?? self::$config['source']);
        $api->refreshPeerCache((int) $source['bot_api_id']);
        $source = $api->getInfo((int) $source['bot_api_id']);
        if (($source['type'] ?? '') !== 'channel' || empty($source['Chat']['broadcast'])) {
            throw new StartupValidationException('Nguồn phải là channel phát tin, không phải group/supergroup.');
        }
        if (!empty($source['Chat']['left'])) { throw new StartupValidationException('Tài khoản phải tham gia channel nguồn trước khi chạy tool.'); }
        return $source;
    }

    public static function isAccountFailure(Throwable $error): bool
    {
        return $error instanceof RPCErrorException && in_array($error->rpc, [
            'AUTH_KEY_INVALID', 'AUTH_KEY_UNREGISTERED', 'AUTH_KEY_DUPLICATED',
            'SESSION_REVOKED', 'SESSION_EXPIRED', 'SESSION_PASSWORD_NEEDED',
            'USER_DEACTIVATED', 'USER_DEACTIVATED_BAN', 'API_ID_INVALID',
            'FROZEN_METHOD_INVALID', 'BOT_METHOD_INVALID', 'PEER_FLOOD',
        ], true);
    }

    public static function isTargetFailure(RPCErrorException $error): bool
    {
        return in_array($error->rpc, ['CHAT_ADMIN_REQUIRED', 'CHAT_WRITE_FORBIDDEN', 'CHAT_RESTRICTED',
            'USER_BANNED_IN_CHANNEL', 'CHANNEL_PRIVATE', 'CHANNEL_INVALID', 'PEER_ID_INVALID',
            'CHAT_ID_INVALID', 'USERNAME_INVALID', 'USERNAME_NOT_OCCUPIED'], true)
            || str_starts_with($error->rpc, 'CHAT_SEND_');
    }

    public static function isPermanentSourceFailure(Throwable $error): bool
    {
        return $error instanceof StartupValidationException || self::isAccountFailure($error)
            || $error instanceof RPCErrorException && in_array($error->rpc,
                ['CHANNEL_PRIVATE', 'CHANNEL_INVALID', 'PEER_ID_INVALID', 'CHAT_ID_INVALID',
                    'USERNAME_INVALID', 'USERNAME_NOT_OCCUPIED', 'USER_BANNED_IN_CHANNEL'], true);
    }

    private static function targetError(array $info, int $sourceId): ?string
    {
        if (($info['type'] ?? '') !== 'channel' || empty($info['Chat']['broadcast'])) { return 'Đích phải là channel phát tin, không phải group/supergroup.'; }
        if ((int) $info['bot_api_id'] === $sourceId) { return 'Channel nguồn và đích phải khác nhau.'; }
        if (!empty($info['Chat']['left']) || empty($info['Chat']['creator']) && empty($info['Chat']['admin_rights']['post_messages'])) { return 'Tài khoản chưa có quyền Post Messages ở channel đích.'; }
        return null;
    }

    /** @param array<string, mixed> $binding */
    public static function initialize(object $api, array $binding): void
    {
        foreach (self::bindingSources($binding) as $source) {
            $queue = self::queue($source['source_id']);
            self::persistBinding($queue, $source);
            self::initializeTargetStates($source);
            $queue->setMeta('paused_reason', '');
            $queue->setMeta('validated_at', (string)time());
        }
        self::persistSourceRegistry($binding);
        self::clearStartupRetry();
    }

    /** @return array<string,mixed> Only successfully activated routes are returned. */
    public static function initializeFirstRun(object $api, array $binding): array
    {
        $activeSources = [];
        $sources = self::bindingSources($binding);
        $isolate = isset(self::$config['_managed_routes']) || count(self::sourceConfigs()) > 1;
        foreach ($sources as $sourceId => $source) {
            try {
                self::initializeSourceFirstRun($api, $source);
                $activeSources[$sourceId] = true;
            } catch (Throwable $error) {
                if (!$isolate || self::isAccountFailure($error) || $error instanceof RateLimitError) { throw $error; }
                unset($sources[$sourceId]);
                $reason = 'SOURCE_ACCESS: ' . $error->getMessage();
                $binding['source_errors'][$sourceId] = $reason;
                $binding['source_error_refs'][$sourceId] = $source['source_reference'];
                $binding['unresolved_sources'][(string)$source['source_reference']] = $reason;
            }
        }
        if (isset($binding['sources'])) { $binding['sources'] = $sources; }
        foreach (self::queues() as $id => $queue) {
            if (!isset($activeSources[$id]) && !isset($binding['source_errors'][$id])) {
                $queue->setMeta('source_enabled', '0');
                $queue->setMeta('worker_ready', '0');
            }
        }
        self::persistSourceRegistry($binding);
        return $binding;
    }

    /** Complete source reads before committing any activation metadata. */
    private static function initializeSourceFirstRun(object $api, array $source): void
    {
        $queue = self::queue($source['source_id']);
        $firstRun = $queue->getMeta('initialized_at') === null;
        $ids = array_map('intval', array_keys($source['targets']));
        $historical = array_map('intval', array_keys($queue->targetStats()));
        if ($queue->getMeta('target_id') !== null) { $historical[] = (int)$queue->getMeta('target_id'); }
        $previousActiveRaw = $queue->getMeta('active_target_ids') ?? $queue->getMeta('target_ids');
        // An older worker has no runtime activation metadata. Its registered
        // destinations were already active; do not invent a new cutoff for them.
        $previousActive = $previousActiveRaw === null ? $historical : json_decode($previousActiveRaw, true);
        $previousActive = is_array($previousActive) ? array_map('intval', $previousActive) : [];
        $newTargets = array_filter($ids, static fn(int $id): bool => !$queue->hasTarget($id)
            && $id !== (int)$queue->getMeta('target_id'));
        $reactivatedTargets = array_diff($ids, $previousActive);
        $sourceReactivated = $queue->getMeta('source_enabled') === '0';
        $baseline = (int)$queue->getMeta('source_min_id');
        if ($firstRun && $queue->stats()['total'] !== 0) {
            throw new StartupValidationException('Hàng đợi có bài nhưng thiếu mốc khởi tạo; giữ dữ liệu và kiểm tra storage.');
        }
        if ($firstRun || $newTargets !== [] || $sourceReactivated || $reactivatedTargets !== []) {
            $history = $api->messages->getHistory(peer: $source['source_id'], limit: 1);
            if (!is_array($history['messages'] ?? null) || !array_is_list($history['messages'])) {
                throw new RuntimeException('Invalid source history response; activation cutoff not recorded.');
            }
            if ($history['messages'] === []) { $cutoff = 0; }
            else {
                $cutoff = $history['messages'][0]['id'] ?? null;
                if (!is_int($cutoff) || $cutoff < 1) { throw new RuntimeException('Invalid source history message ID; activation cutoff not recorded.'); }
            }
        } else { $cutoff = $baseline; }
        if ($firstRun) {
            self::persistBinding($queue, $source);
            $queue->setMeta('source_min_id', (string)$cutoff);
        }
        // Queue's immutable registry retains disabled destinations. Only
        // active IDs are used by the event receiver and drain loop.
        $allIds = array_values(array_unique(array_merge($historical, $ids)));
        $queue->registerTargets($source['source_id'], $allIds, $cutoff);
        if ($firstRun) { $queue->activateRepost($source['source_id'], $allIds); }
        if ($sourceReactivated) { $queue->setMeta('source_runtime_min_id', (string)$cutoff); }
        foreach ($reactivatedTargets as $id) { $queue->setMeta('target_runtime_min_id_' . $id, (string)$cutoff); }
        self::initializeTargetStates($source);
        $queue->setMeta('active_target_ids', json_encode($ids, JSON_THROW_ON_ERROR));
        $queue->setMeta('source_enabled', '1');
        $queue->setMeta('validated_at', (string)time());
        // Local source failures may be resolved by editing its route. A
        // manual/account maintenance pause remains effective globally.
        if (str_starts_with($queue->getMeta('paused_reason') ?? '', 'SOURCE_ACCESS:')) { $queue->setMeta('paused_reason', ''); }
        if ($firstRun) { $queue->setMeta('initialized_at', (string)time()); }
    }

    /** @return array<int,array<string,mixed>> */
    private static function bindingSources(array $binding): array
    {
        return $binding['sources'] ?? [$binding['source_id'] => $binding];
    }

    private static function persistBinding(Queue $queue, array $binding): void
    {
        foreach (['account_id', 'source_id', 'target_id'] as $key) { $queue->setMeta($key, (string)$binding[$key]); }
        $queue->setMeta('source_reference', (string)($binding['source_reference'] ?? self::$config['source']));
        $queue->setMeta('source_protected', $binding['source_protected'] ? '1' : '0');
    }

    private static function persistSourceRegistry(array $binding): void
    {
        $refs = json_decode(self::queue()->getMeta('source_refs') ?? '{}', true);
        $refs = is_array($refs) ? $refs : [];
        foreach (self::bindingSources($binding) as $id => $source) { $refs[$id] = $source['source_reference'] ?? self::$config['source']; }
        foreach ($binding['source_error_refs'] ?? [] as $id => $reference) { $refs[$id] = $reference; }
        self::queue()->setMeta('source_refs', json_encode($refs, JSON_THROW_ON_ERROR));
        self::queue()->setMeta('unresolved_sources', json_encode($binding['unresolved_sources'] ?? [], JSON_THROW_ON_ERROR));
        foreach ($binding['source_errors'] ?? [] as $id => $reason) {
            $queue = self::queue((int)$id);
            if (isset($refs[$id])) { $queue->setMeta('source_reference', (string)$refs[$id]); }
            $queue->setMeta('paused_reason', $reason);
            $queue->setMeta('worker_ready', '0');
        }
    }

    /** Persist historical identities; only configured destinations are active. */
    private static function initializeTargetStates(array $binding): void
    {
        $queue = self::queue($binding['source_id']);
        $refs = json_decode($queue->getMeta('target_refs') ?? '{}', true);
        $refs = is_array($refs) ? $refs : [];
        foreach ($binding['targets'] as $id => $target) {
            $refs[$id] = $target['reference'];
            $previous = $queue->getMeta('target_pause_' . $id) ?? '';
            // Permission checks must not erase an uncertain send requiring
            // reconciliation merely because a dashboard revision changed.
            if (!$target['valid'] || !str_contains($previous, 'RECONCILIATION_REQUIRED')) {
                $queue->setMeta('target_pause_' . $id, $target['valid'] ? '' : (string)$target['error']);
            }
        }
        $queue->setMeta('target_refs', json_encode($refs, JSON_THROW_ON_ERROR));
        $queue->setMeta('target_ids', json_encode(array_map('intval', array_keys($refs)), JSON_THROW_ON_ERROR));
        $queue->setMeta('unresolved_targets', json_encode($binding['unresolved_targets'], JSON_THROW_ON_ERROR));
    }

    /** Clear a resolved startup failure after validation or a successful start. */
    public static function clearStartupRetry(): void
    {
        $queue = self::queue();
        $queue->setMeta('startup_error', '');
        $queue->setMeta('startup_retry_at', '0');
        $queue->setMeta('startup_attempts', '0');
    }

    /** Persist the failure, then exit so the library cannot swallow it. */
    public static function startupFailed(Throwable $error): never
    {
        try {
            $queue = self::queue();
            $queue->setMeta('worker_ready', '0');
            $queue->setMeta('worker_heartbeat_at', '0');
            $queue->setMeta('startup_error', $error->getMessage());
            $queue->setMeta('startup_failed_at', (string) time());
            $attempts = (int) $queue->getMeta('startup_attempts') + 1;
            $queue->setMeta('startup_attempts', (string) $attempts);
            $permanent = $error instanceof StartupValidationException;
            if ($error instanceof RPCErrorException) {
                $permanent = in_array($error->rpc, [
                    'CHAT_ADMIN_REQUIRED', 'CHAT_WRITE_FORBIDDEN', 'CHAT_RESTRICTED',
                    'USER_BANNED_IN_CHANNEL', 'CHANNEL_PRIVATE', 'CHANNEL_INVALID',
                    'PEER_ID_INVALID', 'CHAT_ID_INVALID', 'USERNAME_INVALID',
                    'USERNAME_NOT_OCCUPIED', 'API_ID_INVALID', 'AUTH_KEY_INVALID',
                    'AUTH_KEY_UNREGISTERED', 'AUTH_KEY_DUPLICATED', 'SESSION_REVOKED',
                    'SESSION_EXPIRED', 'SESSION_PASSWORD_NEEDED', 'USER_DEACTIVATED',
                    'USER_DEACTIVATED_BAN', 'FROZEN_METHOD_INVALID', 'BOT_METHOD_INVALID',
                    'PEER_FLOOD',
                ], true) || str_starts_with($error->rpc, 'CHAT_SEND_');
            }
            if ($permanent) {
                $queue->setMeta('paused_reason', 'STARTUP: ' . $error->getMessage());
                $queue->setMeta('startup_retry_at', '0');
            } else {
                $delay = $error instanceof RateLimitError
                    ? max(1, $error->getWaitTimeLeft()) + 1
                    : (int) min(self::$config['retry_max_seconds'],
                        self::$config['retry_base_seconds'] * (2 ** min(12, $attempts - 1)));
                $queue->setMeta('startup_retry_at', (string) (time() + $delay));
            }
        } catch (Throwable $stateError) {
            // Even a broken or unwritable database must not turn a failed
            // startup into a running handler with every callback disabled.
            $error = new RuntimeException($error->getMessage()
                . '; không ghi được trạng thái khởi động: ' . $stateError->getMessage(), 0, $error);
        }
        self::fail($error);
    }

    public static function fail(Throwable $error): never
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(403);
            exit('CLI only.');
        }
        fwrite(STDERR, 'Lỗi: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }

    private static function assertPrivatePath(string $path): void
    {
        $resolved = realpath($path);
        foreach ([$path, $resolved ?: $path] as $candidate) {
            if (preg_match('~(?:^|/)(?:public_html|htdocs)(?:/|$)~i', $candidate)) {
                throw new RuntimeException('Mã nguồn, cấu hình và storage phải nằm ngoài public_html/htdocs.');
            }
        }
    }

    private static function normalizePeer(mixed $value, string $label): int|string
    {
        if (is_int($value) && $value < -1000000000000) { return $value; }
        if (is_string($value)) {
            $peer = trim($value);
            if (preg_match('/^-100[0-9]+$/D', $peer) && filter_var($peer, FILTER_VALIDATE_INT) !== false
                && (int) $peer < -1000000000000) { return (int) $peer; }
            if (preg_match('/^@[A-Za-z0-9_]{5,32}$/D', $peer)) { return strtolower($peer); }
        }
        throw new RuntimeException("$label phải là @username channel hoặc ID dạng -100...");
    }
}
