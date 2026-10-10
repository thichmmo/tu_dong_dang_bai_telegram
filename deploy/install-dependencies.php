<?php declare(strict_types=1);

// Private, one-time CLI installer. Never place deploy/ in a web document root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$applicationDirectory = dirname(__DIR__);
$privateDirectory = $applicationDirectory . '/storage/deploy';
$deadline = microtime(true) + 360;
$phpExecutable = PHP_BINARY;
$retryFailedInstallation = in_array('--retry', $argv, true);
umask(0077);
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

if (preg_match('~/(public_html|htdocs)(/|$)~i', str_replace('\\', '/', $applicationDirectory))) {
    fwrite(STDERR, "Installer must be outside the web document root.\n");
    exit(1);
}
if (!is_dir($privateDirectory) && !@mkdir($privateDirectory, 0700, true)) {
    fwrite(STDERR, "Cannot create private installer directory.\n");
    exit(1);
}
if (!function_exists('flock')) {
    fwrite(STDERR, "PHP CLI must enable flock.\n");
    exit(1);
}
$installerLock = @fopen($privateDirectory . '/install.lock', 'c');
if (!is_resource($installerLock)) {
    fwrite(STDERR, "Cannot open installer lock.\n");
    exit(1);
}
if (!flock($installerLock, LOCK_EX | LOCK_NB)) {
    fclose($installerLock);
    exit(0);
}

$statePath = $privateDirectory . '/state.json';
$logPath = $privateDirectory . '/install.log';
$installerPath = $privateDirectory . '/composer-setup.php';
$composerPath = $privateDirectory . '/composer.phar';
$state = [
    'status' => 'running',
    'phase' => 'requirements',
    'started_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'php_version' => PHP_VERSION,
    'pid' => getmypid(),
];
$writeState = static function (array $current) use ($statePath): void {
    $current['updated_at'] = gmdate('c');
    $json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($statePath . '.tmp', $json, LOCK_EX) === false || !rename($statePath . '.tmp', $statePath)) {
        throw new RuntimeException('Cannot write private installer state.');
    }
};
$log = static function (string $message) use ($logPath): void {
    if (file_put_contents($logPath, '[' . gmdate('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot write private installer log.');
    }
};

// A terminal marker deliberately prevents every-minute retry storms.
if (is_file($statePath)) {
    $previous = json_decode((string) file_get_contents($statePath), true);
    if (is_array($previous) && ($previous['status'] ?? null) === 'success') {
        exit(0);
    }
    if (is_array($previous) && ($previous['status'] ?? null) === 'failed') {
        if (!$retryFailedInstallation) {
            exit(0);
        }
        $archiveName = 'state-' . gmdate('Ymd-His') . '-' . getmypid() . '.json';
        if (is_file($privateDirectory . '/' . $archiveName) || !@rename($statePath, $privateDirectory . '/' . $archiveName)) {
            fwrite(STDERR, "Cannot archive the failed installer state. No retry was started.\n");
            exit(1);
        }
        $state['previous_failed_state'] = $archiveName;
        $log('Explicit retry requested; previous failed state was archived.');
    } else {
        $state['status'] = 'failed';
        $state['phase'] = 'interrupted';
        $state['message'] = 'A previous installation was interrupted. Inspect the private log before retrying.';
        $writeState($state);
        $log($state['message']);
        exit(1);
    }
}

$finished = false;
register_shutdown_function(static function () use (&$finished, &$state, $writeState, $installerPath): void {
    if (is_file($installerPath)) {
        @unlink($installerPath);
    }
    if (!$finished && error_get_last() !== null) {
        $state['status'] = 'failed';
        $state['message'] = 'Installer terminated unexpectedly; inspect the private log.';
        try {
            $writeState($state);
        } catch (Throwable) {
            // Never expose state or a backtrace through cron output.
        }
    }
});

$download = static function (string $url, int $maximumBytes) use ($deadline): string {
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
        throw new RuntimeException('Installation exceeded its six-minute deadline.');
    }
    if (function_exists('curl_init')) {
        $body = '';
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize verified HTTPS downloads.');
        }
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => max(1, (int) ceil(min(20.0, $remaining))),
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ceil(min(10.0, $remaining))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'TelegramMirror-DependencyInstaller/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maximumBytes): int {
                if (strlen($body) + strlen($chunk) > $maximumBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if (!$ok || $statusCode !== 200 || $body === '') {
            throw new RuntimeException('Cannot download or validate the official Composer installation files.');
        }
        return $body;
    }
    $context = stream_context_create([
        'http' => [
            'timeout' => min(20.0, $remaining),
            'follow_location' => 0,
            'user_agent' => 'TelegramMirror-DependencyInstaller/1.0',
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $stream = @fopen($url, 'rb', false, $context);
    if (!is_resource($stream)) {
        throw new RuntimeException('Cannot download or validate the official Composer installation files.');
    }
    $body = '';
    try {
        while (!feof($stream)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Installation exceeded its six-minute deadline.');
            }
            stream_set_timeout($stream, max(1, (int) ceil(min(5.0, $remaining))));
            $chunk = @fread($stream, 16384);
            if ($chunk === false || stream_get_meta_data($stream)['timed_out']) {
                throw new RuntimeException('Official Composer HTTPS download timed out.');
            }
            $body .= $chunk;
            if (strlen($body) > $maximumBytes) {
                throw new RuntimeException('Official Composer installation file exceeded its size limit.');
            }
        }
    } finally {
        fclose($stream);
    }
    if ($body === '') {
        throw new RuntimeException('Official Composer installation download was empty.');
    }
    return $body;
};

$run = static function (array $arguments) use ($applicationDirectory, $logPath, $privateDirectory, $phpExecutable, $deadline): void {
    // Enable HTTPS stream access only for this Composer child process.
    // The account/server PHP configuration remains unchanged.
    array_splice($arguments, 1, 0, ['-d', 'allow_url_fopen=1']);
    $remaining = max(1, (int) floor($deadline - microtime(true)));
    $childEnvironment = [
        'PATH' => dirname($phpExecutable) . ':/usr/local/bin:/usr/bin:/bin',
        'LANG' => 'C.UTF-8',
        'COMPOSER_HOME' => $privateDirectory . '/composer-home',
        'COMPOSER_CACHE_DIR' => $privateDirectory . '/composer-cache',
        'COMPOSER_NO_INTERACTION' => '1',
        'COMPOSER_PROCESS_TIMEOUT' => (string) $remaining,
    ];
    $process = @proc_open(
        $arguments,
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $unusedPipes,
        $applicationDirectory,
        $childEnvironment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch Composer with the current PHP CLI.');
    }
    $exitCode = null;
    while (true) {
        $processStatus = proc_get_status($process);
        if (!$processStatus['running']) {
            $exitCode = $processStatus['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            $terminateDeadline = microtime(true) + 2;
            do {
                usleep(100000);
                $processStatus = proc_get_status($process);
            } while ($processStatus['running'] && microtime(true) < $terminateDeadline);
            if ($processStatus['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
            throw new RuntimeException('Installation exceeded its six-minute deadline.');
        }
        usleep(200000);
    }
    $closedCode = proc_close($process);
    if (($exitCode >= 0 ? $exitCode : $closedCode) !== 0) {
        throw new RuntimeException('Composer exited with an error. Inspect the private installation log.');
    }
};

try {
    $writeState($state);
    $log('Starting dependency installation with PHP ' . PHP_VERSION . '.');
    if (PHP_VERSION_ID < 80300 || PHP_INT_SIZE !== 8) {
        throw new RuntimeException('The installer requires PHP CLI 8.3 or later, 64-bit.');
    }
    foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
        if (!function_exists($function)) {
            throw new RuntimeException('The PHP CLI process functions must be enabled.');
        }
    }
    foreach (['openssl', 'phar', 'hash', 'json'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Missing installer PHP extension: ' . $extension . '.');
        }
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL) && !function_exists('curl_init')) {
        throw new RuntimeException('The installer requires the curl extension or allow_url_fopen for verified HTTPS downloads.');
    }
    if (!is_file($applicationDirectory . '/composer.json')) {
        throw new RuntimeException('composer.json is missing from the private application directory.');
    }
    $state['phase'] = 'download';
    $writeState($state);
    $signature = strtolower(trim($download('https://composer.github.io/installer.sig', 1024)));
    $installer = $download('https://getcomposer.org/installer', 1048576);
    if (!preg_match('/\A[a-f0-9]{96}\z/', $signature) || !hash_equals($signature, hash('sha384', $installer))) {
        throw new RuntimeException('Official Composer installer SHA384 verification failed.');
    }
    if (file_put_contents($installerPath, $installer, LOCK_EX) === false) {
        throw new RuntimeException('Cannot save the verified Composer installer.');
    }
    $log('Official Composer installer SHA384 verified.');
    $state['phase'] = 'composer';
    $writeState($state);
    $run([$phpExecutable, $installerPath, '--quiet', '--install-dir=' . $privateDirectory, '--filename=composer.phar']);
    @unlink($installerPath);
    $state['phase'] = 'dependencies';
    $writeState($state);
    $log('Installing application dependencies; script and plugin hooks are disabled.');
    $run([$phpExecutable, $composerPath, 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--no-ansi', '--optimize-autoloader', '--no-scripts', '--no-plugins']);
    if (!is_file($applicationDirectory . '/vendor/autoload.php')) {
        throw new RuntimeException('Composer completed without vendor/autoload.php.');
    }
    $state['phase'] = 'verification';
    $writeState($state);
    $log('Checking installed dependency requirements against the native PHP CLI.');
    $run([$phpExecutable, $composerPath, 'check-platform-reqs', '--no-dev', '--no-ansi']);
    $state['status'] = 'success';
    $state['phase'] = 'complete';
    $state['finished_at'] = gmdate('c');
    $state['message'] = 'Dependencies installed. Telegram still requires private configuration and account login.';
    $writeState($state);
    $log($state['message']);
    $finished = true;
    echo "Dependencies installed. Remove the temporary installer cron job.\n";
} catch (Throwable $error) {
    $state['status'] = 'failed';
    $state['finished_at'] = gmdate('c');
    $state['message'] = $error->getMessage();
    try {
        $writeState($state);
        $log('FAILED: ' . $error->getMessage());
    } catch (Throwable) {
        // Keep cron output free of paths, credentials and backtraces.
    }
    $finished = true;
    fwrite(STDERR, "Dependency installation failed. Inspect storage/deploy/state.json and install.log privately.\n");
    exit(1);
}
