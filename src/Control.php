<?php

declare(strict_types=1);

namespace TelegramMirror;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Small HTTP control plane. Never loads Telegram sessions or performs Telegram RPCs. */
final class Control
{
    private string $storage;
    private const WINDOW = 900;
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';

    public function __construct(string $storage)
    {
        umask(0077);
        $storage = rtrim($storage, '/');
        if ($storage === '' || $storage[0] !== '/' || str_contains($storage, "\0")) {
            throw new RuntimeException('Storage phải là đường dẫn riêng tuyệt đối.');
        }
        if (preg_match('~(?:^|/)(?:public_html|htdocs)(?:/|$)~i', $storage)) {
            throw new RuntimeException('Storage phải nằm ngoài thư mục public_html/htdocs.');
        }
        $cursor = '';
        foreach (explode('/', $storage) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { throw new RuntimeException('Đường dẫn storage không hợp lệ.'); }
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new RuntimeException('Storage không được dùng symbolic link.'); }
        }
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            throw new RuntimeException('Không tạo được storage riêng.');
        }
        $real = realpath($storage);
        if ($real === false || !is_writable($real)) {
            throw new RuntimeException('Không ghi được storage riêng.');
        }
        $this->storage = $real;
        $this->secureDirectory($real);
    }

    public function sessionDirectory(): string
    {
        $directory = $this->storage . '/admin-sessions';
        if (is_link($directory)) { throw new RuntimeException('Thư mục phiên không hợp lệ.'); }
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('Không tạo được thư mục phiên.');
        }
        $this->secureDirectory($directory);
        return $directory;
    }

    /** Return the plaintext only to the trusted CLI caller; never write it to disk. */
    public function createSetupToken(int $ttl = 900): string
    {
        if (PHP_SAPI !== 'cli') { throw new RuntimeException('Chỉ CLI được tạo liên kết thiết lập.'); }
        if ($ttl < 60 || $ttl > 3600) { throw new InvalidArgumentException('Thời hạn thiết lập không hợp lệ.'); }
        return $this->locked('admin', function () use ($ttl): string {
            if ($this->read('admin.json') !== null) { throw new RuntimeException('Đã có tài khoản quản trị.'); }
            $token = bin2hex(random_bytes(32));
            $this->write('admin-setup.json', ['token_hash' => hash('sha256', $token), 'expires_at' => time() + $ttl]);
            return $token;
        });
    }

    public function setupAvailable(string $token): bool
    {
        return $this->locked('admin', fn(): bool => $this->validSetup($token));
    }

    /** A verified grant may live in a private PHP session without persisting the raw URL token. */
    public function setupGrant(string $token): ?array
    {
        return $this->locked('admin', function () use ($token): ?array {
            if (!$this->validSetup($token)) { return null; }
            $setup = $this->read('admin-setup.json');
            return ['token_hash' => hash('sha256', $token), 'expires_at' => $setup['expires_at']];
        });
    }

    public function grantAvailable(array $grant): bool
    {
        return $this->locked('admin', fn(): bool => $this->validGrant($grant));
    }

    public function setupFromGrant(array $grant, string $username, string $password): array
    {
        return $this->locked('admin', function () use ($grant, $username, $password): array {
            if (!$this->validGrant($grant)) { throw new RuntimeException('Liên kết thiết lập đã hết hạn hoặc được sử dụng.'); }
            return $this->finishSetup($username, $password);
        });
    }

    /** @return array{username:string,auth_epoch:string} */
    public function setup(string $token, string $username, string $password): array
    {
        return $this->locked('admin', function () use ($token, $username, $password): array {
            if (!$this->validSetup($token)) { throw new RuntimeException('Liên kết thiết lập đã hết hạn hoặc được sử dụng.'); }
            return $this->finishSetup($username, $password);
        });
    }

    /** @return array{username:string,auth_epoch:string}|null */
    public function identity(): ?array
    {
        return $this->locked('admin', function (): ?array {
            $record = $this->read('admin.json');
            if ($record === null) { return null; }
            return $this->identityFrom($record);
        });
    }

    /** Failed attempts survive PHP processes. A blocked login is never verified. */
    public function authenticate(string $username, string $password, string $clientKey): ?array
    {
        return $this->locked('admin', function () use ($username, $password, $clientKey): ?array {
            $now = time();
            $key = hash('sha256', substr($clientKey, 0, 256));
            $attempts = $this->read('admin-attempts.json') ?? ['global' => [], 'clients' => []];
            if (!is_array($attempts['global'] ?? null) || !is_array($attempts['clients'] ?? null)
                || count($attempts['global']) > 100 || count($attempts['clients']) > 100) {
                throw new RuntimeException('Trạng thái đăng nhập không hợp lệ.');
            }
            $global = $this->recent($attempts['global'], $now);
            $clients = [];
            foreach ($attempts['clients'] as $client => $timestamps) {
                if (!is_string($client) || !preg_match('/^[a-f0-9]{64}$/D', $client)
                    || !is_array($timestamps) || count($timestamps) > 5) { throw new RuntimeException('Trạng thái đăng nhập không hợp lệ.'); }
                $recent = $this->recent($timestamps, $now);
                if ($recent !== []) { $clients[$client] = $recent; }
            }
            if (count($global) >= 100 || count($clients[$key] ?? []) >= 5) {
                return null;
            }
            $record = $this->read('admin.json');
            if ($record !== null) { $this->identityFrom($record); }
            $hash = $record['password_hash'] ?? self::DUMMY_HASH;
            if (!is_string($hash)) { throw new RuntimeException('Trạng thái quản trị không hợp lệ.'); }
            $verified = strlen($password) <= 72 && password_verify($password, $hash);
            if ($verified && $record !== null && hash_equals(strtolower($record['username']), strtolower(trim($username)))) {
                unset($clients[$key]);
                $this->write('admin-attempts.json', ['global' => $global, 'clients' => $clients]);
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $record['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    $this->write('admin.json', $record);
                }
                return $this->identityFrom($record);
            }
            $global[] = $now;
            $clients[$key][] = $now;
            $this->write('admin-attempts.json', ['global' => $global, 'clients' => $clients]);
            return null;
        });
    }

    /** @return array{version:int,revision:int,routes:array} */
    public function routes(): array
    {
        return $this->locked('routes', fn(): array => $this->routeDocument());
    }

    /** All writers must compare the revision of the form they edited. */
    public function saveRoutes(int $expectedRevision, array $routes): array
    {
        $routes = self::validateRoutes($routes);
        return $this->locked('routes', function () use ($expectedRevision, $routes): array {
            $current = $this->routeDocument();
            if ($expectedRevision < 0 || $current['revision'] !== $expectedRevision) {
                throw new RuntimeException('Cấu hình đã thay đổi ở cửa sổ khác. Tải lại trang rồi thử lại.');
            }
            if ($current['revision'] >= PHP_INT_MAX - 1) { throw new RuntimeException('Revision không hợp lệ.'); }
            $document = ['version' => 1, 'revision' => $current['revision'] + 1, 'routes' => $routes];
            $this->write('routes.json', $document);
            return $document;
        });
    }

    public static function normalizePeer(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('Channel phải là @username hoặc ID -100...'); }
        $peer = trim((string) $value);
        if (preg_match('~^https://t\.me/([A-Za-z0-9_]{5,32})/?$~iD', $peer, $match)) { $peer = '@' . $match[1]; }
        if (preg_match('/^@[A-Za-z0-9_]{5,32}$/D', $peer)) { return strtolower($peer); }
        if (preg_match('/^-100[0-9]+$/D', $peer) && filter_var($peer, FILTER_VALIDATE_INT) !== false
            && (int) $peer < -1000000000000) { return (string) (int) $peer; }
        throw new InvalidArgumentException('Channel phải là @username, link https://t.me/username hoặc ID -100...');
    }

    /** @return list<array{source:string,targets:list<string>,enabled:bool}> */
    public static function validateRoutes(array $routes): array
    {
        if (!array_is_list($routes) || count($routes) > 20) { throw new InvalidArgumentException('Tối đa 20 nguồn lấy bài.'); }
        $normalized = [];
        $sources = [];
        $activeSources = [];
        foreach ($routes as $route) {
            if (!is_array($route) || !array_key_exists('source', $route) || !is_bool($route['enabled'] ?? null)
                || !is_array($route['targets'] ?? null) || !array_is_list($route['targets'])
                || count($route['targets']) < 1 || count($route['targets']) > 10) {
                throw new InvalidArgumentException('Mỗi nguồn cần 1–10 channel đích và trạng thái hợp lệ.');
            }
            $source = self::normalizePeer($route['source']);
            if (isset($sources[$source])) { throw new InvalidArgumentException('Nguồn lấy bài bị trùng.'); }
            $sources[$source] = true;
            if ($route['enabled']) { $activeSources[$source] = true; }
            $targets = array_map(self::normalizePeer(...), $route['targets']);
            if (count(array_unique($targets)) !== count($targets)) { throw new InvalidArgumentException('Channel đích bị trùng trong cùng nguồn.'); }
            if (in_array($source, $targets, true)) { throw new InvalidArgumentException('Nguồn và đích không được là cùng một channel.'); }
            $normalized[] = ['source' => $source, 'targets' => $targets, 'enabled' => $route['enabled']];
        }
        foreach ($normalized as $route) {
            if (!$route['enabled']) { continue; }
            foreach ($route['targets'] as $target) {
                if (isset($activeSources[$target])) {
                    throw new InvalidArgumentException('Channel đang làm nguồn không được đồng thời làm đích; hãy tạm dừng nguồn đó trước.');
                }
            }
        }
        return $normalized;
    }

    public static function csrfToken(array &$session): string
    {
        if (!is_string($session['csrf'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $session['csrf'])) {
            $session['csrf'] = bin2hex(random_bytes(32));
        }
        return $session['csrf'];
    }

    public static function validCsrf(array $session, mixed $value): bool
    {
        return is_string($value) && is_string($session['csrf'] ?? null) && hash_equals($session['csrf'], $value);
    }

    /** Return only finite worker facts, never raw metadata, errors, or Telegram account data. */
    public function status(): array
    {
        $result = ['fresh' => false, 'running' => false, 'revision' => null, 'config_rejected' => false, 'routes' => []];
        $directory = $this->storage . '/deploy';
        if (is_link($directory) || !is_dir($directory)) { return $result; }
        $file = $directory . '/live-status.json';
        if (is_link($file) || !is_file($file) || filesize($file) > 262144) { return $result; }
        try { $report = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return $result; }
        if (!is_array($report)) { return $result; }
        $generated = $report['generated_at'] ?? 0;
        $heartbeat = $report['metadata']['worker_heartbeat_at'] ?? 0;
        if (!is_numeric($generated) || !is_numeric($heartbeat)) { return $result; }
        $result['fresh'] = time() - (int) $generated >= 0 && time() - (int) $generated < 60;
        $result['running'] = $result['fresh'] && !empty($report['ready']) && !empty($report['worker_alive'])
            && empty($report['paused']) && time() - (int) $heartbeat >= 0 && time() - (int) $heartbeat < 60;
        $revision = $report['metadata']['routes_revision'] ?? $report['routes_revision'] ?? null;
        if (is_int($revision) || (is_string($revision) && ctype_digit($revision))) { $result['revision'] = (int) $revision; }
        $result['config_rejected'] = !empty($report['metadata']['route_config_error']);
        $sourceReports = $report['sources'] ?? $report['routes'] ?? [];
        foreach (is_array($sourceReports) ? $sourceReports : [] as $route) {
            if (!is_array($route)) { continue; }
            try { $source = self::normalizePeer($route['reference'] ?? $route['source'] ?? null); } catch (Throwable) { continue; }
            $result['routes'][$source] = !empty($route['ready']);
        }
        foreach (is_array($report['unresolved_sources'] ?? null) ? $report['unresolved_sources'] : [] as $reference => $_reason) {
            try { $source = self::normalizePeer($reference); } catch (Throwable) { continue; }
            $result['routes'][$source] = false;
        }
        return $result;
    }

    private function routeDocument(): array
    {
        $document = $this->read('routes.json');
        if ($document === null) { return ['version' => 1, 'revision' => 0, 'routes' => []]; }
        if (($document['version'] ?? null) !== 1 || !is_int($document['revision'] ?? null) || $document['revision'] < 0
            || !is_array($document['routes'] ?? null)) { throw new RuntimeException('Cấu hình nguồn không hợp lệ.'); }
        return ['version' => 1, 'revision' => $document['revision'], 'routes' => self::validateRoutes($document['routes'])];
    }

    private function validSetup(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || $this->read('admin.json') !== null) { return false; }
        $setup = $this->read('admin-setup.json');
        return $setup !== null && is_string($setup['token_hash'] ?? null) && is_int($setup['expires_at'] ?? null)
            && $setup['expires_at'] > time() && hash_equals($setup['token_hash'], hash('sha256', $token));
    }

    private function validGrant(array $grant): bool
    {
        if ($this->read('admin.json') !== null || !is_string($grant['token_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $grant['token_hash']) || !is_int($grant['expires_at'] ?? null)) { return false; }
        $setup = $this->read('admin-setup.json');
        return $setup !== null && is_string($setup['token_hash'] ?? null) && is_int($setup['expires_at'] ?? null)
            && $setup['expires_at'] > time() && $setup['expires_at'] === $grant['expires_at']
            && hash_equals($setup['token_hash'], $grant['token_hash']);
    }

    private function finishSetup(string $username, string $password): array
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{2,31}$/D', $username)) {
            throw new InvalidArgumentException('Tên đăng nhập gồm 3–32 ký tự, bắt đầu bằng chữ, dùng chữ, số, dấu chấm, gạch ngang hoặc gạch dưới.');
        }
        if (strlen($password) > 72 || !preg_match('/^.{10,72}$/usD', $password)) {
            throw new InvalidArgumentException('Mật khẩu cần ít nhất 10 ký tự và không quá 72 byte.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $identity = ['username' => $username, 'auth_epoch' => bin2hex(random_bytes(24))];
        $this->write('admin.json', $identity + ['password_hash' => $hash, 'created_at' => time()]);
        // admin.json is the definitive one-use seal even if unlink is interrupted.
        $this->remove('admin-setup.json');
        return $identity;
    }

    private function identityFrom(array $record): array
    {
        if (!is_string($record['username'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{2,31}$/D', $record['username'])
            || !is_string($record['auth_epoch'] ?? null) || !preg_match('/^[a-f0-9]{48}$/D', $record['auth_epoch'])
            || !is_string($record['password_hash'] ?? null) || password_get_info($record['password_hash'])['algo'] === null) {
            throw new RuntimeException('Trạng thái tài khoản quản trị không hợp lệ.');
        }
        return ['username' => $record['username'], 'auth_epoch' => $record['auth_epoch']];
    }

    private function recent(mixed $timestamps, int $now): array
    {
        if (!is_array($timestamps) || !array_is_list($timestamps)) { throw new RuntimeException('Trạng thái đăng nhập không hợp lệ.'); }
        foreach ($timestamps as $timestamp) {
            if (!is_int($timestamp) || $timestamp < 1) { throw new RuntimeException('Trạng thái đăng nhập không hợp lệ.'); }
        }
        return array_values(array_filter($timestamps, static fn(mixed $value): bool => is_int($value) && $value > $now - self::WINDOW && $value <= $now));
    }

    private function secureDirectory(string $directory): void
    {
        $stat = lstat($directory);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0040000)
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) || !chmod($directory, 0700)) {
            throw new RuntimeException('Quyền thư mục riêng không hợp lệ.');
        }
    }

    private function checkFile(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) { throw new RuntimeException('File trạng thái không được dùng symbolic link.'); }
        if (!file_exists($path)) { return; }
        $stat = lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new RuntimeException('Quyền file trạng thái không hợp lệ.');
        }
    }

    private function locked(string $name, callable $operation): mixed
    {
        $path = $this->storage . '/' . $name . '.lock';
        $this->checkFile($path);
        $handle = fopen($path, 'c+b');
        if ($handle === false) { throw new RuntimeException('Không mở được khóa trạng thái.'); }
        try {
            if (!chmod($path, 0600) || !flock($handle, LOCK_EX)) { throw new RuntimeException('Không khóa được trạng thái.'); }
            $this->checkFile($path);
            $opened = fstat($handle);
            $actual = lstat($path);
            if ($opened === false || $actual === false || $opened['ino'] !== $actual['ino'] || $opened['dev'] !== $actual['dev']) {
                throw new RuntimeException('File khóa đã thay đổi.');
            }
            return $operation();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function read(string $name): ?array
    {
        $path = $this->storage . '/' . $name;
        $this->checkFile($path);
        if (!is_file($path)) { return null; }
        if (filesize($path) > 1048576) { throw new RuntimeException('File trạng thái quá lớn.'); }
        $contents = file_get_contents($path);
        if ($contents === false) { throw new RuntimeException('Không đọc được trạng thái.'); }
        try { $document = json_decode($contents, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('File trạng thái không hợp lệ.'); }
        if (!is_array($document)) { throw new RuntimeException('File trạng thái không hợp lệ.'); }
        return $document;
    }

    private function write(string $name, array $document): void
    {
        $path = $this->storage . '/' . $name;
        $this->checkFile($path);
        $temporary = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) { throw new RuntimeException('Không tạo được trạng thái mới.'); }
        try {
            if (!chmod($temporary, 0600)) { throw new RuntimeException('Không đặt được quyền file trạng thái.'); }
            $contents = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) { throw new RuntimeException('Không ghi được trạng thái.'); }
                $offset += $written;
            }
            if (!fflush($handle)) { throw new RuntimeException('Không hoàn tất ghi trạng thái.'); }
            if (function_exists('fsync') && !fsync($handle)) { throw new RuntimeException('Không đồng bộ được trạng thái.'); }
            $this->checkFile($path);
            if (!rename($temporary, $path)) { throw new RuntimeException('Không thay được trạng thái.'); }
        } finally { fclose($handle); if (is_file($temporary)) { unlink($temporary); } }
    }

    private function remove(string $name): void
    {
        $path = $this->storage . '/' . $name;
        $this->checkFile($path);
        if (is_file($path) && !unlink($path)) { throw new RuntimeException('Không thu hồi được liên kết thiết lập.'); }
    }
}
