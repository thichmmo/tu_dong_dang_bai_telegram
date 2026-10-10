<?php

declare(strict_types=1);

// Inspect installed classes and bundled schemas only. Never boot the app,
// create an API/SessionPaths instance, read config.php, or connect to Telegram.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

try {
    if (PHP_VERSION_ID < 80300 || PHP_INT_SIZE !== 8) {
        throw new RuntimeException('PHP CLI 8.3+ 64 bit is required.');
    }
    $root = dirname(__DIR__);
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php is missing; install dependencies first.');
    }
    require $autoload;

    $package = 'danog/madelineproto';
    $version = Composer\InstalledVersions::getVersion($package);
    if ($version !== '8.7.0.0') {
        throw new RuntimeException('Expected MadelineProto 8.7.0, found ' . ($version ?? 'unknown') . '.');
    }
    echo 'PASS MadelineProto ' . Composer\InstalledVersions::getPrettyVersion($package) . PHP_EOL;

    foreach ([
        TelegramMirror\App::class,
        TelegramMirror\Queue::class,
        TelegramMirror\StartupValidationException::class,
        TelegramMirror\MirrorHandler::class,
    ] as $class) {
        if (!class_exists($class)) {
            throw new RuntimeException('PSR-4 cannot load ' . $class . '.');
        }
        echo 'PASS autoload ' . $class . PHP_EOL;
    }
    $handler = TelegramMirror\MirrorHandler::class;
    if (!is_subclass_of($handler, danog\MadelineProto\SimpleEventHandler::class)) {
        throw new RuntimeException('The handler must extend SimpleEventHandler for Cron support.');
    }
    $cron = new ReflectionMethod($handler, 'drainQueue');
    $attributes = $cron->getAttributes(danog\MadelineProto\EventHandler\Attributes\Cron::class);
    if (!$cron->isPublic() || count($attributes) !== 1
        || $attributes[0]->newInstance()->period !== 3.0) {
        throw new RuntimeException('Expected public drainQueue with Cron(period: 3.0).');
    }
    echo 'PASS SimpleEventHandler and Cron(period: 3.0)' . PHP_EOL;

    // Match the raw-update naming rule used by EventHandler::internalStart
    // against the API schema shipped with this exact installed library.
    $schemaPath = (new danog\MadelineProto\Settings\TLSchema())->getAPISchema();
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException('Cannot read the installed Telegram TL schema.');
    }
    foreach (['onUpdateNewChannelMessage', 'onUpdateChannel'] as $name) {
        $method = new ReflectionMethod($handler, $name);
        $parameters = $method->getParameters();
        $predicate = lcfirst(substr($name, 2));
        if (!$method->isPublic() || count($parameters) !== 1
            || (string) $parameters[0]->getType() !== 'array'
            || (string) $method->getReturnType() !== 'void'
            || !preg_match('/^' . preg_quote($predicate, '/')
                . '#[a-fA-F0-9]+\b[^;\n]*=\s*Update;/m', $schema)) {
            throw new RuntimeException('Unsupported raw-update handler: ' . $name . '.');
        }
        echo 'PASS raw handler ' . $name . PHP_EOL;
    }

    $severe = 0;
    $warnings = 0;
    foreach (danog\MadelineProto\Tools::validateEventHandlerClass($handler) as $issue) {
        if ($issue->severe) {
            ++$severe;
        } else {
            ++$warnings;
        }
        // Reading issue fields avoids Logger initialization and side effects.
        echo ($issue->severe ? 'FAIL ' : 'WARN ') . $issue->file . ':'
            . $issue->line . ' ' . $issue->message . PHP_EOL;
    }
    if ($severe !== 0) {
        throw new RuntimeException('Event handler analyzer found ' . $severe . ' fatal issue(s).');
    }
    echo 'PASS event handler analyzer; ' . $warnings . ' warning(s).' . PHP_EOL;
    echo 'PASS library check completed without a Telegram API/session.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL library check: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
