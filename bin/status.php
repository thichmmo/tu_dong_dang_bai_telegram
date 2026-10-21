<?php

declare(strict_types=1);

use TelegramMirror\App;

require_once dirname(__DIR__) . '/src/App.php';

try {
    // Reads only local SQLite state; no Composer, MTProto session or network.
    App::boot(false);
    $queue = App::queue();
    $meta = [];
    foreach ([
        'initialized_at', 'account_id', 'source_id', 'target_id', 'source_min_id',
        'source_protected', 'validated_at', 'worker_started_at', 'worker_heartbeat_at',
        'worker_pid', 'worker_ready', 'startup_error', 'startup_retry_at', 'startup_attempts', 'startup_failed_at',
        'last_received_at', 'last_forwarded_at', 'last_forwarded_id', 'paused_reason',
        'forward_retry_at', 'target_ids', 'unresolved_targets',
        'routes_revision', 'route_config_error', 'unresolved_sources',
        'history_required', 'history_cursor', 'last_history_checked_at', 'last_history_error',
    ] as $key) {
        $meta[$key] = $queue->getMeta($key);
    }
    // A recent heartbeat survives an abrupt exit. Prove that the recorded
    // Linux process is our worker and still owns its session lock descriptor.
    $liveness = static function (?string $storedPid, string $script, string $lock): array {
        $dead = ['worker_alive' => false, 'liveness_unavailable' => false];
        $unavailable = ['worker_alive' => false, 'liveness_unavailable' => true];
        if (PHP_OS_FAMILY !== 'Linux') {
            return $unavailable;
        }
        $ownProc = '/proc/' . getmypid();
        $ownUid = @fileowner($ownProc);
        if ($ownUid === false || !is_readable($ownProc . '/cmdline') || !is_dir($ownProc . '/fd')) {
            return $unavailable;
        }
        if ($storedPid === null || !preg_match('/^[1-9][0-9]*$/D', $storedPid)
            || filter_var($storedPid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return $dead;
        }
        $proc = '/proc/' . $storedPid;
        clearstatcache(true, $proc);
        if (!is_dir($proc)) {
            return $dead;
        }
        $uid = @fileowner($proc);
        if ($uid === false) {
            return $unavailable;
        }
        if ($uid !== $ownUid) {
            return $dead;
        }
        $command = @file_get_contents($proc . '/cmdline');
        if ($command === false) {
            return $unavailable;
        }
        if (!in_array(realpath($script) ?: $script, explode("\0", $command), true)) {
            return $dead;
        }
        $descriptors = @scandir($proc . '/fd');
        if ($descriptors === false) {
            return $unavailable;
        }
        $readable = false;
        foreach ($descriptors as $descriptor) {
            if (!ctype_digit($descriptor)) {
                continue;
            }
            $target = @readlink($proc . '/fd/' . $descriptor);
            if ($target !== false) {
                $readable = true;
                if ($target === $lock) {
                    return ['worker_alive' => true, 'liveness_unavailable' => false];
                }
            }
        }
        return $readable ? $dead : $unavailable;
    };
    $alive = $liveness($meta['worker_pid'], __DIR__ . '/worker.php', App::path('process.lock'));
    $now = time();
    $heartbeat = (int) ($meta['worker_heartbeat_at'] ?? 0);
    $age = $now - $heartbeat;
    $paused = !empty($meta['paused_reason'])
        && !str_starts_with($meta['paused_reason'], 'SOURCE_ACCESS:');
    $receivingReady = $alive['worker_alive'] && !$paused && ($meta['worker_ready'] ?? '0') === '1'
        && $heartbeat > 0 && $age >= 0 && $age < 30;
    $targets = [];
    $postable = 0;
    $references = json_decode($queue->getMeta('target_refs') ?? '{}', true);
    $references = is_array($references) ? $references : [];
    foreach ($queue->targetStats() as $id => $statistics) {
        $reason = $queue->getMeta('target_pause_' . $id);
        $targets[$id] = $statistics + ['paused' => !empty($reason), 'pause_reason' => $reason,
            'reference' => $references[$id] ?? $id,
            'ready' => $receivingReady && empty($reason),
            'last_forwarded_id' => $queue->getMeta('last_forwarded_id_' . $id)];
        if (empty($reason)) { ++$postable; }
    }
    $unresolved = json_decode($meta['unresolved_targets'] ?? '{}', true);
    $unresolved = is_array($unresolved) ? $unresolved : [];
    // Receiving updates and delivering to every configured destination are
    // separate facts. A destination pause must not mask a healthy sibling.
    $deliveryReady = $receivingReady && ($targets === [] || $postable > 0);
    $status = [
        'generated_at' => $now,
        'initialized' => $meta['initialized_at'] !== null,
        'paused' => $paused,
        'worker_alive' => $alive['worker_alive'],
        'liveness_unavailable' => $alive['liveness_unavailable'],
        'receiving_ready' => $receivingReady,
        'ready' => $deliveryReady,
        'all_targets_ready' => $deliveryReady && $postable === count($targets) && $unresolved === [],
        'targets' => $targets,
        'unresolved_targets' => $unresolved,
        'heartbeat_age_seconds' => $heartbeat > 0 ? max(0, $age) : null,
        'queue' => $queue->stats(),
        'metadata' => $meta,
    ];
    $sources = [];
    $activeCount = $readyCount = $receivingSourceCount = 0;
    $requiresHistory = false;
    foreach (App::queues() as $sourceId => $sourceQueue) {
        $enabled = $sourceQueue->getMeta('source_enabled') !== '0';
        $sourcePause = $sourceQueue->getMeta('paused_reason');
        $historyRequired = $sourceQueue->getMeta('history_required') === '1';
        $requiresHistory = $requiresHistory || ($enabled && $historyRequired);
        $historyChecked = (int)$sourceQueue->getMeta('last_history_checked_at');
        $historyError = $sourceQueue->getMeta('last_history_error') ?? '';
        $historyPoll = max(15, min(900, (int)(App::config()['history_poll_seconds'] ?? 60)));
        $historyFresh = !$historyRequired || ($historyChecked > 0 && $historyChecked <= $now
            && $now - $historyChecked < max(180, $historyPoll * 3) && $historyError === '');
        $sourceReceiving = $receivingReady && $enabled && empty($sourcePause) && $historyFresh;
        $activeIds = json_decode($sourceQueue->getMeta('active_target_ids') ?? 'null', true);
        $sourceRefs = json_decode($sourceQueue->getMeta('target_refs') ?? '{}', true);
        $sourceRefs = is_array($sourceRefs) ? $sourceRefs : [];
        $sourceTargets = [];
        $activeTargets = $readyTargets = 0;
        foreach ($sourceQueue->targetStats() as $targetId => $statistics) {
            $active = $enabled && (!is_array($activeIds) || in_array($targetId, $activeIds, true));
            $reason = $sourceQueue->getMeta('target_pause_' . $targetId);
            $targetReady = $sourceReceiving && $active && empty($reason);
            $sourceTargets[$targetId] = $statistics + ['enabled' => $active,
                'reference' => $sourceRefs[$targetId] ?? $targetId, 'ready' => $targetReady,
                'paused' => !empty($reason), 'pause_reason' => $reason];
            if ($active) { ++$activeTargets; }
            if ($targetReady) { ++$readyTargets; }
        }
        $sourceReady = $sourceReceiving && $readyTargets > 0;
        $sources[$sourceId] = ['reference' => $sourceQueue->getMeta('source_reference')
                ?? App::config()['source'], 'enabled' => $enabled, 'ready' => $sourceReady,
            'all_targets_ready' => $sourceReady && $activeTargets === $readyTargets
                && (json_decode($sourceQueue->getMeta('unresolved_targets') ?? '{}', true) ?: []) === [],
            'paused' => !empty($sourcePause), 'cutoff' => (int)$sourceQueue->getMeta('source_min_id'),
            'targets' => $sourceTargets, 'queue' => $sourceQueue->stats(),
            'history' => ['required' => $historyRequired, 'fresh' => $historyFresh,
                'checked_at' => $historyChecked > 0 ? $historyChecked : null,
                'failed' => $historyError !== '', 'cursor' => (int)$sourceQueue->getMeta('history_cursor')]];
        if ($enabled) { ++$activeCount; }
        if ($sourceReceiving) { ++$receivingSourceCount; }
        if ($sources[$sourceId]['all_targets_ready']) { ++$readyCount; }
        if ($sourceId === (int)$meta['source_id'] && !$historyFresh) {
            foreach ($status['targets'] as &$target) { $target['ready'] = false; }
            unset($target);
        }
    }
    $status['sources'] = $sources;
    $status['routes_revision'] = (int)($meta['routes_revision'] ?? 0);
    $status['unresolved_sources'] = json_decode($meta['unresolved_sources'] ?? '{}', true) ?: [];
    // Keep the legacy primary destination view for existing tools. Aggregate
    // readiness across sources once a route registry has actually been applied.
    if ($meta['routes_revision'] !== null || $requiresHistory) {
        $status['receiving_ready'] = $receivingReady && $receivingSourceCount > 0;
        $status['ready'] = $receivingReady && array_filter($sources, static fn(array $s): bool => $s['ready']) !== [];
        $status['all_targets_ready'] = $status['ready'] && $activeCount === $readyCount
            && $status['unresolved_sources'] === [] && empty($meta['route_config_error']);
    }
    fwrite(STDOUT, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    App::fail($error);
}
