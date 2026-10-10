<?php

declare(strict_types=1);

namespace TelegramMirror;

use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\SimpleEventHandler;
use Throwable;

/** One Telegram session, with an isolated durable queue for each source. */
final class MirrorHandler extends SimpleEventHandler
{
    private bool $ready = false;
    private bool $sending = false;
    private int $sourceId = 0;
    private int $targetId = 0;
    /** @var list<int> */
    private array $targetIds = [];
    private int $baseline = 0;
    private bool $sourceProtected = false;
    /** @var array<int,array{target_id:int,target_ids:list<int>,baseline:int,protected:bool}> */
    private array $sources = [];
    private int $routesRevision = -1;

    public function onStart(): void
    {
        $this->ready = false;
        if (!App::workerEventsAllowed()) { return; }
        try {
            App::queue()->setMeta('worker_ready', '0');
            App::queue()->setMeta('worker_heartbeat_at', '0');
            App::requireInitialized();
            $binding = App::validateAccount($this);
            // These callbacks are not registered yet. Capture every newly
            // activated source's baseline before accepting its updates.
            $binding = App::initializeFirstRun($this, $binding);
            App::queue()->setMeta('worker_started_at', (string)time());
            $this->applyBinding($binding, true);
            App::clearStartupRetry();
            App::queue()->setMeta('worker_ready', '1');
            $this->ready = true;
            $this->logger('Mirror ready for ' . count($this->sources) . ' source(s).');
        } catch (Throwable $error) {
            // MadelineProto catches exceptions raised by onStart and can still
            // register callbacks. Exit through App so an unready handler never
            // lives indefinitely and silently discards source updates.
            App::startupFailed($error);
        }
    }

    /** @param array<string, mixed> $update */
    public function onUpdateNewChannelMessage(array $update): void
    {
        if (!$this->ready || !App::workerEventsAllowed()) {
            return;
        }
        $message = $update['message'] ?? [];
        if (($message['_'] ?? '') !== 'message') { return; }
        $sourceId = $this->getId($message);
        $source = $this->sources[$sourceId] ?? null;
        if ($source === null || ($message['id'] ?? 0) <= $source['baseline']) { return; }
        $queue = App::queue($sourceId);
        if ($source['protected'] && $queue->getMeta('source_revalidation_needed') !== '1') { $message['noforwards'] = true; }
        $queue->enqueue($sourceId, $message, $source['target_ids']);
        $queue->setMeta('last_received_at', (string)time());
    }

    /** @param array<string, mixed> $update */
    public function onUpdateChannel(array $update): void
    {
        $channelId = $update['channel_id'] ?? 0;
        // MadelineProto normally supplies canonical IDs. Also accept the bare
        // positive MTProto channel ID when an update is supplied externally.
        if (is_int($channelId) && $channelId > 0) {
            $channelId = -1000000000000 - $channelId;
        }
        if (!$this->ready || !App::workerEventsAllowed() || !isset($this->sources[$channelId])) {
            return;
        }
        App::queue($channelId)->setMeta('source_revalidation_needed', '1');
        $this->refreshSourceState($channelId);
    }

    /** Do not deliver or permanently skip using protection/access flags known to be stale. */
    private function refreshSourceState(?int $sourceId = null): bool
    {
        $sourceId ??= $this->sourceId;
        $queue = App::queue($sourceId);
        try {
            $info = App::validateSource($this, $sourceId);
            $protected = !empty($info['Chat']['noforwards']);
            $this->sources[$sourceId]['protected'] = $protected;
            if ($sourceId === $this->sourceId) { $this->sourceProtected = $protected; }
            $queue->setMeta('source_protected', $protected ? '1' : '0');
            $queue->setMeta('source_revalidation_needed', '0');
            return true;
        } catch (Throwable $error) {
            $queue->setMeta('source_revalidation_needed', '1');
            if (App::isPermanentSourceFailure($error)) {
                $queue->setMeta('paused_reason', 'SOURCE_ACCESS: ' . $error->getMessage());
                if (App::isAccountFailure($error)) { App::queue()->setMeta('paused_reason', $error->getMessage()); }
            } else {
                $delay = $error instanceof RateLimitError ? max(1, $error->getWaitTimeLeft()) + 1 : App::config()['retry_base_seconds'];
                $queue->setMeta('forward_retry_at', (string)(time() + $delay));
                if ($error instanceof RateLimitError) { App::queue()->setMeta('global_forward_retry_at', (string)(time() + $delay)); }
            }
            $this->logger('Source refresh failed: ' . $error->getMessage());
            return false;
        }
    }

    /** Install only validated active routes; retained source queues stay untouched. */
    private function applyBinding(array $binding, bool $startup = false): void
    {
        $sources = [];
        foreach ($binding['sources'] ?? [$binding['source_id'] => $binding] as $sourceId => $route) {
            $queue = App::queue((int)$sourceId);
            $sources[(int)$sourceId] = ['target_id' => $route['target_id'],
                'target_ids' => array_map('intval', array_keys($route['targets'])),
                'baseline' => max((int)$queue->getMeta('source_min_id'), (int)$queue->getMeta('source_runtime_min_id')),
                'protected' => $route['source_protected']];
            $queue->setMeta('source_protected', $route['source_protected'] ? '1' : '0');
            $queue->setMeta('source_revalidation_needed', '0');
            if ($startup || $queue->getMeta('worker_started_at') === null) { $queue->setMeta('worker_started_at', (string)time()); }
            $queue->setMeta('worker_ready', '1');
        }
        $this->sources = $sources;
        $this->sourceId = $binding['source_id'];
        $this->targetId = $binding['target_id'];
        $primary = $sources[$this->sourceId] ?? null;
        $this->targetIds = $primary['target_ids'] ?? [];
        $this->baseline = $primary['baseline'] ?? (int)App::queue()->getMeta('source_min_id');
        $this->sourceProtected = $primary['protected'] ?? false;
        $this->routesRevision = App::routeRevision();
        App::queue()->setMeta('route_revision_applied', (string)$this->routesRevision);
        App::queue()->setMeta('routes_revision', (string)$this->routesRevision);
        App::queue()->setMeta('route_config_error', '');
    }

    /** Apply dashboard edits inside the existing owned session. */
    private function synchronizeRoutes(): bool
    {
        try {
            App::reloadRoutes();
            if (App::routeRevision() === $this->routesRevision) { return true; }
            $binding = App::validateAccount($this);
            $binding = App::initializeFirstRun($this, $binding);
            $this->applyBinding($binding);
            return true;
        } catch (Throwable $error) {
            App::queue()->setMeta('route_config_error', $error->getMessage());
            if (App::isAccountFailure($error)) { App::queue()->setMeta('paused_reason', $error->getMessage()); return false; }
            if ($error instanceof RateLimitError) {
                App::queue()->setMeta('global_forward_retry_at', (string)(time() + max(1, $error->getWaitTimeLeft()) + 1));
                return false;
            }
            $this->logger('Route update failed: ' . $error->getMessage());
            // Rejecting an edit retains the last successfully installed map.
            return true;
        }
    }

    private function globallyPaused(): bool
    {
        $reason = App::queue()->getMeta('paused_reason') ?? '';
        return ($reason !== '' && !str_starts_with($reason, 'SOURCE_ACCESS:'))
            || (int)App::queue()->getMeta('global_forward_retry_at') > time();
    }

    #[Cron(period: 3.0)]
    public function drainQueue(): void
    {
        if (!$this->ready || !App::workerEventsAllowed() || $this->sending) { return; }
        App::queue()->setMeta('worker_heartbeat_at', (string)time());
        if ($this->globallyPaused() || !$this->synchronizeRoutes()) { return; }
        $this->sending = true;
        try {
            foreach ($this->sources as $sourceId => $source) {
                $queue = App::queue($sourceId);
                $queue->setMeta('worker_heartbeat_at', (string)time());
                if ($queue->getMeta('paused_reason') || (int)$queue->getMeta('forward_retry_at') > time()) { continue; }
                if ($queue->getMeta('source_revalidation_needed') === '1' && !$this->refreshSourceState($sourceId)) {
                    if ($this->globallyPaused()) { return; }
                    continue;
                }
                foreach ($source['target_ids'] as $targetId) {
                    if ($queue->getMeta('target_pause_' . $targetId)) { continue; }
                    $batch = $queue->pendingBatches(time(), App::config()['album_settle_seconds'], $targetId)[0] ?? null;
                    if ($batch === null) { continue; }
                    if ($batch['source_id'] !== $sourceId) { $queue->setMeta('paused_reason', 'QUEUE_BINDING_MISMATCH'); break; }
                    if ($batch['protected'] || $this->sources[$sourceId]['protected']) {
                        if (!empty($batch['batch_key'])) { $queue->setMeta('target_pause_' . $targetId, 'REPOST_PROTECTION_RECONCILIATION_REQUIRED'); continue; }
                        $queue->markSkipped($batch['ids'], $sourceId, 'CHAT_FORWARDS_RESTRICTED', $targetId);
                        continue;
                    }
                    if (!$this->forwardBatch($batch, $targetId)) {
                        if ($this->globallyPaused()) { return; }
                        break;
                    }
                }
            }
        } finally { $this->sending = false; }
    }

    /** Return false when an account/source failure must stop all destinations. */
    private function forwardBatch(array $batch, int $targetId): bool
    {
        $sourceId = (int)$batch['source_id'];
        $queue = App::queue($sourceId);
        $frozenLoaded = false;
        try {
            if (($batch['delivery_mode'] ?? '') !== 'repost' || $queue->getMeta('delivery_mode') !== 'repost') {
                $queue->setMeta('paused_reason', 'REPOST_MODE_NOT_ACTIVATED');
                return false;
            }
            // Fresh channel/message protection gates precede reference reuse.
            if (!$this->refreshSourceState($sourceId)) { return false; }
            if ($this->sources[$sourceId]['protected']) {
                if (!empty($batch['batch_key'])) { $queue->setMeta('target_pause_' . $targetId, 'REPOST_PROTECTION_RECONCILIATION_REQUIRED'); return true; }
                $queue->markSkipped($batch['ids'], $sourceId, 'CHAT_FORWARDS_RESTRICTED', $targetId);
                return true;
            }
            $payloads = $queue->frozenPayloads($sourceId, $batch['ids']);
            $frozenLoaded = $payloads !== [];
            $raw = Repost::read($this, $batch);
            $missing = array_values(array_diff($batch['ids'], array_keys($payloads)));
            if ($missing !== []) {
                $captureBatch = $batch; $captureBatch['ids'] = $missing;
                $fresh = Repost::capture($this, $captureBatch, $raw);
                $queue->freezePayloads($sourceId, $fresh);
                $payloads = $queue->frozenPayloads($sourceId, $batch['ids']);
            }
            $queue->freezeRepostBatch($batch, $targetId);
            $payloads = Repost::authorize($this, $payloads, $raw);
            // Containment can be committed while source reads are in progress.
            if ($queue->getMeta('paused_reason') || $queue->getMeta('target_pause_' . $targetId) || $this->globallyPaused()) { return false; }
            // A route disabled during an awaited source read must not send.
            try {
                if (App::reloadRoutes() || App::routeRevision() !== $this->routesRevision) { return false; }
            } catch (Throwable $routeError) {
                App::queue()->setMeta('route_config_error', $routeError->getMessage());
                if (App::routeRevision() !== $this->routesRevision) { return false; }
            }
            $result = Repost::send($this, $targetId, $batch, $payloads);
            if (Repost::processingPending($result)) {
                $this->retry($batch, 'REPOST_VIDEO_PROCESSING_RECONCILIATION_REQUIRED', null, $targetId);
                $queue->setMeta('target_pause_' . $targetId, 'REPOST_VIDEO_PROCESSING_RECONCILIATION_REQUIRED');
                return true;
            }
            $confirmed = Repost::acknowledgements($result, $batch['random_ids'], $targetId);
            if ($confirmed === null) {
                $this->retry($batch, 'UNCONFIRMED_REPOST_RESPONSE', null, $targetId);
                return true;
            }
            $queue->markReposted($batch, $targetId, $confirmed);
            $queue->setMeta('last_forwarded_at', (string) time());
            $queue->setMeta('last_forwarded_id_' . $targetId, (string) max($batch['ids']));
            if ($targetId === (int)$queue->getMeta('target_id')) { $queue->setMeta('last_forwarded_id', (string) max($batch['ids'])); }
            $this->logger('Reposted to ' . $targetId . ': ' . implode(',', $batch['ids']));
        } catch (RepostFailure $error) {
            if ($frozenLoaded || !empty($batch['batch_key'])) {
                $queue->setMeta('target_pause_' . $targetId, 'REPOST_RECONCILIATION_REQUIRED: ' . $error->getMessage());
                return true;
            }
            $queue->markSkipped($batch['ids'], $sourceId, $error->getMessage(), $targetId);
            $this->logger('Repost blocked: ' . $error->getMessage());
        } catch (RateLimitError $error) {
            $delay = max(1, $error->getWaitTimeLeft()) + 1;
            $this->retry($batch, $error->rpc, $delay, $targetId);
            $queue->setMeta('forward_retry_at', (string)(time() + $delay));
            App::queue()->setMeta('global_forward_retry_at', (string)(time() + $delay));
            return false;
        } catch (RPCErrorException $error) {
            $rpc = $error->rpc;
            if (in_array($rpc, [
                'CHAT_FORWARDS_RESTRICTED', 'MESSAGE_ID_INVALID', 'MSG_ID_INVALID',
                'MESSAGE_IDS_EMPTY', 'BROADCAST_PUBLIC_VOTERS_FORBIDDEN',
                'QUIZ_ANSWER_MISSING', 'MEDIA_EMPTY', 'MEDIA_FILE_INVALID',
            ], true)) {
                if (!empty($batch['batch_key'])) {
                    $queue->setMeta('target_pause_' . $targetId, 'REPOST_RECONCILIATION_REQUIRED: ' . $rpc);
                    return true;
                }
                $queue->markSkipped($batch['ids'], $sourceId, $rpc, $targetId);
                $this->logger('Skipped unforwardable posts: ' . $rpc);
            } elseif (App::isAccountFailure($error)) {
                $queue->setMeta('paused_reason', $rpc);
                App::queue()->setMeta('paused_reason', $rpc);
                return false;
            } elseif (in_array($rpc, ['CHANNEL_PRIVATE', 'CHANNEL_INVALID', 'PEER_ID_INVALID'], true)) {
                if (!$this->refreshSourceState($sourceId)) { return false; }
                $queue->setMeta('target_pause_' . $targetId, $rpc);
            } elseif (in_array($rpc, [
                'CHAT_ADMIN_REQUIRED', 'CHAT_WRITE_FORBIDDEN', 'CHAT_RESTRICTED',
                'USER_BANNED_IN_CHANNEL',
            ], true) || str_starts_with($rpc, 'CHAT_SEND_')) {
                $queue->setMeta('target_pause_' . $targetId, $rpc);
                $this->logger('Paused destination ' . $targetId . ': ' . $rpc);
            } else {
                // Includes RANDOM_ID_DUPLICATE: it can mean the original call
                // is still in flight. A later identical call reconciles it.
                $this->retry($batch, $rpc, null, $targetId);
            }
        } catch (Throwable $error) {
            $this->retry($batch, $error->getMessage(), null, $targetId);
        }
        return true;
    }

    /** @param array<string, mixed> $batch */
    private function retry(array $batch, string $reason, ?int $delay, int $targetId): void
    {
        if ($delay === null) {
            $config = App::config();
            $delay = (int) min($config['retry_max_seconds'],
                $config['retry_base_seconds'] * (2 ** min(12, $batch['attempts'])));
        }
        $retryAt = time() + $delay;
        App::queue((int)$batch['source_id'])->retryBatch($batch['ids'], (int)$batch['source_id'], $reason, $retryAt, $targetId);
        $this->logger('Retry source posts in ' . $delay . ' seconds: ' . $reason);
    }
}
