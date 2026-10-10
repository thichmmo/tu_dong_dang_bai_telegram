<?php

declare(strict_types=1);

// Run the real private CLI entry points with copied code, dummy encryption
// keys, SQLite/session sentinels and offline API doubles. Never read config.php,
// host.txt, vendor or a real Telegram session; never connect to the network.
namespace danog\MadelineProto {
    final class Logger { public const FILE_LOGGER = 1; }
    final class Settings
    {
        public static array $rpcTimeouts = [];
        public function __call(string $name, array $arguments): self
        {
            if ($name === 'setRpcDropTimeout') { self::$rpcTimeouts[] = $arguments[0]; }
            return $this;
        }
    }
    class RPCErrorException extends \RuntimeException
    {
        public function __construct(public string $rpc) { parent::__construct($rpc); }
    }
    final class API
    {
        public const LOGGED_IN = 3;
        public const WAITING_PASSWORD = 2;
        public static int $constructors = 0;
        public static int $passwordCalls = 0;
        public static int $identityCalls = 0;
        private int $authorization = self::WAITING_PASSWORD;
        public function __construct(string $session, Settings $settings) { ++self::$constructors; }
        public function updateSettings(Settings $settings): void {}
        public function getAuthorization(): int
        {
            if ($this->authorization === self::WAITING_PASSWORD) {
                $mode = \TelegramMirror\Tests\TwoFactor\mode();
                if ($mode === 'expiry-before-rpc') {
                    $GLOBALS['twofa_clock'] = $GLOBALS['twofa_expiry'] + 0.5;
                } elseif ($mode === 'cancel-before-rpc') {
                    \TelegramMirror\Tests\TwoFactor\replaceRequest(true);
                } elseif ($mode === 'newer-before-rpc') {
                    \TelegramMirror\Tests\TwoFactor\replaceRequest(false, true);
                    \TelegramMirror\Tests\TwoFactor\writeNewerState();
                }
            }
            return $this->authorization;
        }
        public function complete2faLogin(string $password): array
        {
            ++self::$passwordCalls;
            if ($password !== \TelegramMirror\Tests\TwoFactor\DUMMY_PASSWORD) {
                throw new \RuntimeException('The offline double received an unexpected fixture.');
            }
            $this->authorization = self::LOGGED_IN;
            $mode = \TelegramMirror\Tests\TwoFactor\mode();
            if ($mode === 'expiry-after-rpc') {
                $GLOBALS['twofa_clock'] = $GLOBALS['twofa_expiry'] + 0.5;
            } elseif ($mode === 'cancel-after-rpc') {
                \TelegramMirror\Tests\TwoFactor\replaceRequest(true);
            }
            return ['_' => 'auth.authorization'];
        }
        public function fullGetSelf(): array { ++self::$identityCalls; return $this->getSelf(); }
        public function getSelf(): array { return ['id' => 4242, 'bot' => false]; }
        public function refreshPeerCache(int ...$peers): void {}
        public function getInfo(int|string $peer): array
        {
            $target = $peer === '@test_target' || $peer === \TelegramMirror\Tests\TwoFactor\TARGET;
            return ['type' => 'channel',
                'bot_api_id' => $target ? \TelegramMirror\Tests\TwoFactor\TARGET : \TelegramMirror\Tests\TwoFactor\SOURCE,
                'Chat' => ['broadcast' => true, 'left' => false, 'admin_rights' => ['post_messages' => $target]]];
        }
    }
}

namespace TelegramMirror\LoginTwoFactor {
    function time(): int { return (int) floor($GLOBALS['twofa_clock']); }
    function microtime(bool $asFloat = false): float|string
    {
        return $asFloat ? $GLOBALS['twofa_clock'] : '0.00000000 ' . (int) $GLOBALS['twofa_clock'];
    }
    function chmod(string $path, int $mode): bool
    {
        $test = \TelegramMirror\Tests\TwoFactor\mode();
        if (empty($GLOBALS['twofa_publication_raced']) && str_contains($path, 'login-2fa-state.json.tmp.')) {
            if ($test === 'newer-during-publication') {
                $GLOBALS['twofa_publication_raced'] = true;
                \TelegramMirror\Tests\TwoFactor\replaceRequest(false, true);
                \TelegramMirror\Tests\TwoFactor\writeNewerState();
            } elseif ($test === 'cancel-during-publication') {
                $GLOBALS['twofa_publication_raced'] = true;
                \TelegramMirror\Tests\TwoFactor\replaceRequest(true);
            }
        }
        return \chmod($path, $mode);
    }
}

namespace TelegramMirror\Tests\TwoFactor {
    use danog\MadelineProto\API;
    use danog\MadelineProto\Settings;
    use RuntimeException;
    use TelegramMirror\App;
    use TelegramMirror\Queue;
    use Throwable;

    const SOURCE = -1001234567890;
    const TARGET = -1002234567890;
    const REQUEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const NEWER_REQUEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    const DUMMY_PASSWORD = 'Synthetic only: kiểm tra';
    const MODES = ['success', 'ciphertext-tampered', 'newer-envelope',
        'cancel-before-decrypt', 'expiry-before-rpc', 'cancel-before-rpc',
        'newer-before-rpc', 'expiry-after-rpc', 'cancel-after-rpc',
        'newer-during-publication', 'cancel-during-publication',
        'cleanup-busy', 'cleanup-cancelled', 'cleanup-expired',
        'cleanup-newer-request', 'cleanup-newer-envelope', 'cleanup-not-cancelled',
        'cleanup-invalid-cancel', 'cleanup-request-symlink', 'cleanup-request-oversized',
        'probe-empty', 'probe-missing', 'probe-busy', 'probe-envelope', 'probe-foreign',
        'probe-malformed', 'probe-symlink', 'probe-oversized'];

    function mode(): string { return getenv('TELEGRAM_MIRROR_TWOFA_TEST') ?: 'all'; }
    function expectedExit(string $mode): int
    {
        return match ($mode) {
            'success', 'cleanup-cancelled', 'cleanup-expired', 'probe-empty', 'probe-missing' => 0,
            'cleanup-busy', 'probe-busy' => 3,
            'cancel-before-decrypt', 'expiry-before-rpc', 'cancel-before-rpc',
            'expiry-after-rpc', 'cancel-after-rpc', 'cancel-during-publication' => 2,
            default => 1,
        };
    }
    /** Observe requested exits without changing their codes or control flow. */
    function finishHelper(int|string $code = 0): never
    {
        $GLOBALS['twofa_helper_completed'] = true;
        $GLOBALS['twofa_helper_exit'] = is_int($code) ? $code : 0;
        exit($code);
    }
    function copyObservedHelper(string $source, string $target): void
    {
        $tokens = token_get_all((string) file_get_contents($source), TOKEN_PARSE);
        $output = '';
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_EXIT) {
                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next])
                    && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { ++$next; }
                $following = $tokens[$next] ?? null;
                if ($following !== '(' && $following !== ';') {
                    throw new RuntimeException('Unsupported offline CLI exit form.');
                }
                $output .= '\\TelegramMirror\\Tests\\TwoFactor\\finishHelper' . ($following === ';' ? '(0)' : '');
            } else { $output .= is_array($token) ? $token[1] : $token; }
        }
        file_put_contents($target, $output);
    }
    function removeFixture(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') { removeFixture($path . '/' . $item); }
            }
            rmdir($path);
        } elseif (is_file($path) || is_link($path)) { unlink($path); }
    }
    function replaceRequest(bool $cancelled = false, bool $newer = false): void
    {
        file_put_contents($GLOBALS['twofa_storage'] . '/login-2fa-request.json', json_encode([
            'request_id' => $newer ? NEWER_REQUEST : REQUEST,
            'expires_at' => $GLOBALS['twofa_expiry'], 'cancelled' => $cancelled,
            'retired_status' => mode() === 'cleanup-expired' ? 'expired' : 'cancelled',
        ], JSON_THROW_ON_ERROR));
    }
    function writeNewerState(): void
    {
        file_put_contents($GLOBALS['twofa_storage'] . '/login-2fa-state.json', json_encode([
            'request_id' => NEWER_REQUEST, 'status' => 'processing', 'updated_at' => 123,
        ], JSON_THROW_ON_ERROR));
    }
    function envelope(string $requestId, int $expiry): array
    {
        $salt = random_bytes(16); $nonce = random_bytes(12);
        $key = hash_hkdf('sha256', str_repeat('a', 32), 32, 'telegram-mirror-2fa-v1', $salt);
        $payload = json_encode(['password' => DUMMY_PASSWORD, 'request_id' => $requestId,
            'expires_at' => $expiry], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ciphertext = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            $nonce, $tag, $requestId . '|' . $expiry, 16);
        if ($ciphertext === false) { throw new RuntimeException('Cannot encrypt offline fixture.'); }
        return ['version' => 1, 'request_id' => $requestId, 'expires_at' => $expiry,
            'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext)];
    }

    $mode = mode();
    if ($mode === 'all') {
        $total = 0;
        foreach (MODES as $childMode) {
            $marker = __DIR__ . '/twofa-result-' . bin2hex(random_bytes(8));
            $env = getenv();
            $env['TELEGRAM_MIRROR_TWOFA_TEST'] = $childMode;
            $env['TELEGRAM_MIRROR_TWOFA_RESULT'] = $marker;
            try {
                $process = proc_open([PHP_BINARY, __FILE__], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, null, $env);
                if (!is_resource($process)) { throw new RuntimeException('Cannot start offline two-step fixture.'); }
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $exit = proc_close($process);
                $result = is_file($marker) ? file_get_contents($marker) : '';
                if ($exit !== expectedExit($childMode)
                    || !preg_match('/^Two-factor ' . preg_quote($childMode, '/') . ': (\d+) assertions passed\.\n$/D', $result, $match)) {
                    throw new RuntimeException('Offline two-step ' . $childMode . ' failed: ' . $result . $stdout . $stderr);
                }
                $total += (int) $match[1];
            } finally { if (is_file($marker)) { unlink($marker); } }
        }
        echo 'Two-factor CLI: ' . $total . " assertions passed.\n";
        exit(0);
    }
    if (!in_array($mode, MODES, true)) { throw new RuntimeException('Unknown offline two-step fixture mode.'); }
    if (!function_exists('openssl_encrypt')) { throw new RuntimeException('Offline two-step tests need OpenSSL.'); }

    $assertions = 0;
    function check(bool $condition, string $description): void
    {
        global $assertions;
        ++$assertions;
        if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
    }
    function same(mixed $expected, mixed $actual, string $description): void { check($expected === $actual, $description); }

    $fixture = __DIR__ . '/twofa-fixture-' . bin2hex(random_bytes(8));
    foreach ([$fixture, $fixture . '/src', $fixture . '/bin', $fixture . '/vendor', $fixture . '/storage'] as $path) {
        mkdir($path, 0700);
    }
    foreach (['App.php', 'Queue.php', 'StartupValidationException.php'] as $file) {
        copy(dirname(__DIR__) . '/src/' . $file, $fixture . '/src/' . $file);
    }
    foreach (['login-2fa.php', 'login-2fa-cleanup.php', 'login-gate-probe.php'] as $file) {
        copyObservedHelper(dirname(__DIR__) . '/bin/' . $file, $fixture . '/bin/' . $file);
    }
    file_put_contents($fixture . '/vendor/autoload.php', "<?php // Offline API doubles are already loaded.\n");
    $storage = $fixture . '/storage';
    $GLOBALS['twofa_storage'] = $storage;
    $GLOBALS['twofa_clock'] = (float) time();
    $GLOBALS['twofa_expiry'] = time() + 100;
    file_put_contents($fixture . '/config.php', '<?php return ' . var_export([
        'source' => '@test_source', 'target' => '@test_target', 'storage_dir' => $storage,
        'api_id' => 123456, 'api_hash' => str_repeat('a', 32),
    ], true) . ';');
    $sessionSentinel = 'Synthetic session sentinel; contains no credentials.';
    file_put_contents($storage . '/telegram.session', $sessionSentinel);
    require_once $fixture . '/src/Queue.php';
    $queue = new Queue($storage . '/queue.sqlite');
    $queue->setMeta('source_min_id', '10');
    $queue->enqueue(SOURCE, ['id' => 11, 'date' => time()]);
    $randomIds = $queue->pendingBatches(time())[0]['random_ids'];
    replaceRequest($mode === 'cancel-before-decrypt' || str_starts_with($mode, 'cleanup-'),
        $mode === 'cleanup-newer-request');
    if ($mode === 'cleanup-not-cancelled') { replaceRequest(); }
    if ($mode === 'cleanup-invalid-cancel') {
        file_put_contents($storage . '/login-2fa-request.json', json_encode([
            'request_id' => REQUEST, 'expires_at' => $GLOBALS['twofa_expiry'], 'cancelled' => 'false',
        ], JSON_THROW_ON_ERROR));
    } elseif ($mode === 'cleanup-request-symlink') {
        rename($storage . '/login-2fa-request.json', $storage . '/request-sentinel.json');
        symlink($storage . '/request-sentinel.json', $storage . '/login-2fa-request.json');
    } elseif ($mode === 'cleanup-request-oversized') {
        file_put_contents($storage . '/login-2fa-request.json', str_repeat('x', 65537));
    }
    $envelope = envelope(in_array($mode, ['newer-envelope', 'cleanup-newer-envelope'], true)
        ? NEWER_REQUEST : REQUEST, $GLOBALS['twofa_expiry']);
    if ($mode === 'ciphertext-tampered') { $envelope['tag'] = base64_encode(str_repeat(chr(0), 16)); }
    $envelopeBytes = json_encode($envelope, JSON_THROW_ON_ERROR);
    file_put_contents($storage . '/login-2fa-envelope.json', $envelopeBytes);
    if (str_starts_with($mode, 'probe-')) {
        unlink($storage . '/login-2fa-envelope.json');
        file_put_contents($storage . '/login-2fa-request.json', '{}');
        file_put_contents($storage . '/login-2fa-state.json', '{"status":"input"}');
        if ($mode === 'probe-missing') {
            unlink($storage . '/login-2fa-request.json'); unlink($storage . '/login-2fa-state.json');
        } elseif ($mode === 'probe-envelope') {
            file_put_contents($storage . '/login-2fa-envelope.json', $envelopeBytes);
        } elseif ($mode === 'probe-foreign') {
            replaceRequest(false, true);
        } elseif ($mode === 'probe-malformed') {
            file_put_contents($storage . '/login-2fa-request.json', '{broken');
        } elseif ($mode === 'probe-symlink') {
            rename($storage . '/login-2fa-request.json', $storage . '/request-sentinel.json');
            symlink($storage . '/request-sentinel.json', $storage . '/login-2fa-request.json');
        } elseif ($mode === 'probe-oversized') {
            file_put_contents($storage . '/login-2fa-request.json', str_repeat('x', 65537));
        }
    }
    $originalRequest = is_file($storage . '/login-2fa-request.json')
        ? file_get_contents($storage . '/login-2fa-request.json') : null;
    $originalState = is_file($storage . '/login-2fa-state.json')
        ? file_get_contents($storage . '/login-2fa-state.json') : null;
    $foreignLock = null;
    if ($mode === 'cleanup-busy' || $mode === 'probe-busy') {
        $foreignLock = fopen($storage . '/process.lock', 'ce');
        flock($foreignLock, LOCK_EX | LOCK_NB);
    }
    $argv = ['offline-helper', REQUEST];
    $resultPath = getenv('TELEGRAM_MIRROR_TWOFA_RESULT');
    register_shutdown_function(function () use ($mode, $fixture, $storage, $queue,
        $sessionSentinel, $randomIds, $foreignLock, $envelopeBytes, $resultPath, $originalRequest, $originalState): void {
        global $assertions;
        try {
            same(true, $GLOBALS['twofa_helper_completed'] ?? false, 'CLI reaches an observed controlled exit or cleanup return');
            same(expectedExit($mode), $GLOBALS['twofa_helper_exit'] ?? null, 'CLI requests the expected exit status');
            same($sessionSentinel, file_get_contents($storage . '/telegram.session'), 'Session sentinel remains intact');
            same('10', $queue->getMeta('source_min_id'), 'Existing source cutoff remains intact');
            same(1, $queue->stats()['pending'], 'Pending post is preserved');
            same($randomIds, $queue->pendingBatches(time())[0]['random_ids'], 'Stable delivery IDs are preserved');
            $statePath = $storage . '/login-2fa-state.json';
            $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
            if (str_starts_with($mode, 'probe-')) {
                same(0, API::$constructors, 'Gate probe makes no Telegram constructor call');
                same(0, API::$passwordCalls, 'Gate probe sends no credential RPC');
                $report = json_decode((string) file_get_contents($storage . '/login-gate-probe-' . REQUEST . '.json'), true);
                same(REQUEST, $report['request_id'] ?? null, 'Gate proof is nonce-bound');
                same($mode !== 'probe-busy', $report['lock_free'] ?? null, 'Gate proof requires the session lock');
                same(in_array($mode, ['probe-empty', 'probe-missing'], true), $report['input_safe'] ?? null,
                    'Only genuinely empty unlocked state is input-safe');
                check(is_int($report['checked_at'] ?? null) && abs(time() - $report['checked_at']) < 30,
                    'Gate proof has a recent bounded timestamp');
                same($originalRequest, is_file($storage . '/login-2fa-request.json')
                    ? file_get_contents($storage . '/login-2fa-request.json') : null, 'Gate probe preserves request bytes');
                same($originalState, is_file($storage . '/login-2fa-state.json')
                    ? file_get_contents($storage . '/login-2fa-state.json') : null, 'Gate probe preserves state bytes');
                same($mode === 'probe-envelope', is_file($storage . '/login-2fa-envelope.json'),
                    'Gate probe never removes or creates a credential envelope');
            } elseif (str_starts_with($mode, 'cleanup-')) {
                same(0, API::$constructors, 'Cleanup makes no Telegram constructor call');
                $report = json_decode((string) file_get_contents($storage . '/login-2fa-cleanup-' . REQUEST . '.json'), true);
                same(REQUEST, $report['request_id'] ?? null, 'Cleanup report belongs to this request');
                $retired = in_array($mode, ['cleanup-cancelled', 'cleanup-expired'], true);
                same($retired ? ($mode === 'cleanup-expired' ? 'expired' : 'cancelled')
                    : ($mode === 'cleanup-busy' ? 'busy' : 'conflict'), $report['status'] ?? null, 'Cleanup outcome matches binding/lock');
                same(!$retired, is_file($storage . '/login-2fa-envelope.json'), 'Only matching retired envelope is removed');
                if (!$retired) {
                    same($envelopeBytes, file_get_contents($storage . '/login-2fa-envelope.json'), 'Busy/conflicting cleanup preserves envelope bytes');
                }
                same(null, $state, 'Cleanup does not overwrite authentication state');
            } else {
                $successful = $mode === 'success';
                $afterRpc = in_array($mode, ['expiry-after-rpc', 'cancel-after-rpc'], true);
                same($successful || $afterRpc ? 1 : 0, API::$passwordCalls, 'Credential RPC runs only for an active matching request');
                if ($successful) {
                    same('success', $state['status'] ?? null, 'Valid fixture completes authentication');
                    same('4242', $queue->getMeta('account_id'), 'Successful login initializes account binding');
                    same((string) SOURCE, $queue->getMeta('source_id'), 'Successful login binds source');
                } else {
                    same(null, $queue->getMeta('validated_at'), 'Rejected, expired or cancelled request cannot initialize');
                    if (str_starts_with($mode, 'newer-')) {
                        if ($mode === 'newer-envelope') {
                            same('failed', $state['status'] ?? null, 'Mismatched envelope is rejected');
                            same('invalid_envelope', $state['failure_code'] ?? null, 'Mismatch emits finite failure code');
                            same($envelopeBytes, file_get_contents($storage . '/login-2fa-envelope.json'), 'A newer envelope is never consumed');
                        } else {
                            same(NEWER_REQUEST, $state['request_id'] ?? null, 'Old helper cannot overwrite newer state');
                            same('processing', $state['status'] ?? null, 'Newer state remains untouched');
                        }
                    } else {
                        $expected = str_starts_with($mode, 'expiry-') ? 'expired'
                            : (str_starts_with($mode, 'cancel-') ? 'cancelled' : 'failed');
                        same($expected, $state['status'] ?? null, 'Finite state reports cancellation/expiry/rejection');
                        same(REQUEST, $state['request_id'] ?? null, 'Terminal state remains request-bound');
                        if ($mode === 'ciphertext-tampered') {
                            same('invalid_envelope', $state['failure_code'] ?? null, 'GCM tampering emits finite failure code');
                        }
                    }
                }
                if ($mode !== 'newer-envelope') {
                    check(!is_file($storage . '/login-2fa-envelope.json'), 'Consumed own envelope does not persist');
                }
                if (in_array($mode, ['ciphertext-tampered', 'newer-envelope',
                    'cancel-before-decrypt', 'newer-during-publication', 'cancel-during-publication'], true)) {
                    same(0, API::$constructors, 'Rejected pre-auth request opens no Telegram API');
                }
                if ($mode === 'expiry-before-rpc' || $mode === 'cancel-before-rpc') {
                    same(0, API::$identityCalls, 'Credential guard stops before identity validation');
                }
                foreach (Settings::$rpcTimeouts as $timeout) {
                    check(is_int($timeout) && $timeout >= 1 && $timeout <= 30, 'RPC timeout remains bounded');
                }
            }
            $result = 'Two-factor ' . $mode . ': ' . $assertions . " assertions passed.\n";
            if (is_string($resultPath) && $resultPath !== '') { file_put_contents($resultPath, $result); }
            echo $result;
        } catch (Throwable $error) {
            if (is_string($resultPath) && $resultPath !== '') { file_put_contents($resultPath, 'FAIL: ' . $error->getMessage()); }
            throw $error;
        } finally {
            if (class_exists(App::class, false)) { App::releaseLock(); }
            if (is_resource($foreignLock)) { flock($foreignLock, LOCK_UN); fclose($foreignLock); }
            removeFixture($fixture);
        }
    });
    $entry = str_starts_with($mode, 'probe-') ? 'login-gate-probe.php'
        : (str_starts_with($mode, 'cleanup-') ? 'login-2fa-cleanup.php' : 'login-2fa.php');
    require $fixture . '/bin/' . $entry;
    if (!str_starts_with($mode, 'cleanup-')) {
        throw new RuntimeException('Authentication CLI returned without its controlled terminal exit.');
    }
    finishHelper(0);
}
