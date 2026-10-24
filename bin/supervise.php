<?php

declare(strict_types=1);

use TelegramMirror\WorkerSupervisor;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80300) { exit(2); }
umask(0077);
require_once dirname(__DIR__) . '/src/WorkerSupervisor.php';
try {
    $root = realpath(dirname(__DIR__));
    $config = require $root . '/config.php';
    if (!is_array($config) || !is_string($config['storage_dir'] ?? null)) { throw new RuntimeException('CONFIG_INVALID'); }
    $supervisor = new WorkerSupervisor($root, rtrim($config['storage_dir'], '/'), PHP_BINARY,
        ['history_poll_seconds' => (int)($config['history_poll_seconds'] ?? 60)]);
    $result = $supervisor->tick();
    // Only finite, non-secret facts. The private report is the durable output.
    if (in_array('--json', $argv, true)) { fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n"); }
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Supervisor could not inspect private local state. No recovery was attempted.\n");
    exit(1);
}
