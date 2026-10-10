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

    public static function queue(): Queue
    {
        return self::$queue ?? throw new RuntimeException('App chưa được khởi tạo.');
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
        if ($reason) {
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
        $source = self::validateSource($api);
        $binding = [
            'account_id' => (int) $self['id'],
            'source_id' => (int) $source['bot_api_id'],
        ];
        foreach ($binding as $key => $value) {
            $previous = self::queue()->getMeta($key);
            if ($previous !== null && $previous !== (string) $value) {
                throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác. '
                    . 'Giữ nguyên dữ liệu cũ và dùng storage_dir mới để đổi cặp channel.');
            }
        }
        $storedRefs = json_decode(self::queue()->getMeta('target_refs') ?? '{}', true);
        $storedRefs = is_array($storedRefs) ? $storedRefs : [];
        $legacyPrimary = self::queue()->getMeta('target_id');
        if ($legacyPrimary !== null && (int) $legacyPrimary >= -1000000000000) {
            throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác hoặc có ID đích không hợp lệ.');
        }
        $targets = [];
        $unresolved = [];
        foreach (self::$config['targets'] ?? [self::$config['target']] as $reference) {
            $id = is_int($reference) ? $reference : null;
            foreach ($storedRefs as $storedId => $storedReference) {
                if ($storedReference === $reference) { $id = (int) $storedId; break; }
            }
            if ($id === null && $reference === self::$config['target'] && self::queue()->getMeta('target_id') !== null) {
                $id = (int) self::queue()->getMeta('target_id');
            }
            $knownId = $id;
            try {
                $info = $api->getInfo($reference);
                $resolvedId = (int) $info['bot_api_id'];
                if ($knownId !== null && $resolvedId !== $knownId) {
                    $targets[$knownId] = ['id' => $knownId, 'reference' => $reference,
                        'valid' => false, 'error' => 'TARGET_IDENTITY_CHANGED'];
                    continue;
                }
                if ($resolvedId === $binding['source_id']) {
                    $unresolved[(string) $reference] = 'SOURCE_TARGET_LOOP';
                    continue;
                }
                $id = $resolvedId;
                $api->refreshPeerCache($id);
                $info = $api->getInfo($id);
                $error = self::targetError($info, $binding['source_id']);
                if ($id >= -1000000000000) { $id = null; }
            } catch (Throwable $failure) {
                if (!$failure instanceof RPCErrorException || $failure instanceof RateLimitError
                    || !self::isTargetFailure($failure)) { throw $failure; }
                $error = $failure->rpc;
            }
            if ($id === null) {
                $unresolved[(string) $reference] = $error;
                continue;
            }
            if (isset($targets[$id])) {
                if ($targets[$id]['error'] === 'TARGET_IDENTITY_CHANGED') { continue; }
                throw new StartupValidationException('targets chứa ID channel trùng nhau.');
            }
            $targets[$id] = ['id' => $id, 'reference' => $reference, 'valid' => $error === null, 'error' => $error];
        }
        $primary = self::queue()->getMeta('target_id');
        if ($primary !== null && !isset($targets[(int) $primary])) {
            throw new StartupValidationException('Storage này đã gắn với tài khoản/cặp channel khác. Channel đích cũ không được đổi hoặc xóa.');
        }
        if ($targets === []) { throw new StartupValidationException('Không xác định được channel đích; kiểm tra username/quyền truy cập.'); }
        $previousTargets = json_decode(self::queue()->getMeta('target_ids') ?? '[]', true);
        foreach (is_array($previousTargets) ? $previousTargets : [] as $previousTarget) {
            if (!isset($targets[(int) $previousTarget])) { throw new StartupValidationException('Không được xóa channel đích đã gắn với storage.'); }
        }
        if ($primary === null) {
            foreach ($targets as $id => $target) {
                if ($target['reference'] === self::$config['target']
                    || is_int(self::$config['target']) && $id === self::$config['target']) {
                    $primary = $id;
                    break;
                }
            }
            if ($primary === null) { throw new StartupValidationException('Không xác định được channel target chính; kiểm tra cấu hình và quyền truy cập.'); }
        }
        $binding['target_id'] = (int) $primary;
        return $binding + ['source_protected' => !empty($source['Chat']['noforwards']),
            'targets' => $targets, 'unresolved_targets' => $unresolved];
    }

    /** Refresh the source independently when a forward error could name either peer. */
    public static function validateSource(object $api, ?int $sourceId = null): array
    {
        $source = $api->getInfo($sourceId ?? self::$config['source']);
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
        $queue = self::queue();
        foreach (['account_id', 'source_id', 'target_id'] as $key) {
            $queue->setMeta($key, (string) $binding[$key]);
        }
        $queue->setMeta('source_protected', $binding['source_protected'] ? '1' : '0');
        self::initializeTargetStates($binding);
        $queue->setMeta('paused_reason', '');
        self::clearStartupRetry();
        $queue->setMeta('validated_at', (string) time());
    }

    /** Capture the baseline when the event handler is first activated. */
    public static function initializeFirstRun(object $api, array $binding): void
    {
        $queue = self::queue();
        $firstRun = $queue->getMeta('initialized_at') === null;
        $ids = array_map('intval', array_keys($binding['targets']));
        $newTargets = array_filter($ids, static fn(int $id): bool => !$queue->hasTarget($id)
            && $id !== (int) $queue->getMeta('target_id'));
        $baseline = (int) $queue->getMeta('source_min_id');
        if ($firstRun) {
            if ($queue->stats()['total'] !== 0) {
                throw new StartupValidationException('Hàng đợi có bài nhưng thiếu mốc khởi tạo; giữ dữ liệu và kiểm tra storage.');
            }
        }
        if ($firstRun || $newTargets !== []) {
            // One request establishes only new destinations' activation cutoff.
            $history = $api->messages->getHistory(peer: $binding['source_id'], limit: 1);
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
            $baseline = $cutoff;
            foreach (['account_id', 'source_id', 'target_id'] as $key) {
                $queue->setMeta($key, (string) $binding[$key]);
            }
            $queue->setMeta('source_min_id', (string) $baseline);
        }
        $queue->registerTargets($binding['source_id'], $ids, $cutoff);
        if ($firstRun) { $queue->activateRepost($binding['source_id'], $ids); }
        self::initializeTargetStates($binding);
        if ($firstRun) { $queue->setMeta('initialized_at', (string) time()); }
    }

    /** Persist permission pauses per destination, including inactive new targets. */
    private static function initializeTargetStates(array $binding): void
    {
        $queue = self::queue();
        $refs = [];
        foreach ($binding['targets'] as $id => $target) {
            $refs[$id] = $target['reference'];
            $queue->setMeta('target_pause_' . $id, $target['valid'] ? '' : (string) $target['error']);
        }
        $queue->setMeta('target_refs', json_encode($refs, JSON_THROW_ON_ERROR));
        $queue->setMeta('target_ids', json_encode(array_map('intval', array_keys($binding['targets'])), JSON_THROW_ON_ERROR));
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
