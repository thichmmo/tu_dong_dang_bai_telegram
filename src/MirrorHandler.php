<?php

declare(strict_types=1);

namespace TelegramMirror;

use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\SimpleEventHandler;
use Throwable;

/** One source, one destination, with a durable local identifier queue. */
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

    public function onStart(): void
    {
        $this->ready = false;
        if (!App::workerEventsAllowed()) { return; }
        try {
            App::queue()->setMeta('worker_ready', '0');
            App::queue()->setMeta('worker_heartbeat_at', '0');
            App::requireInitialized();
            $binding = App::validateAccount($this);
            // onStart runs before these raw update callbacks are registered.
            // Starting the baseline here avoids losing posts while the login
            // process has the session but no active message handler.
            App::initializeFirstRun($this, $binding);
            $queue = App::queue();
            $this->sourceId = $binding['source_id'];
            $this->targetId = $binding['target_id'];
            $this->targetIds = array_map('intval', array_keys($binding['targets']));
            $this->sourceProtected = $binding['source_protected'];
            $this->baseline = (int) $queue->getMeta('source_min_id');
            $queue->setMeta('source_protected', $this->sourceProtected ? '1' : '0');
            $queue->setMeta('source_revalidation_needed', '0');
            $queue->setMeta('worker_started_at', (string) time());
            App::clearStartupRetry();
            $queue->setMeta('worker_ready', '1');
            $this->ready = true;
            $this->logger('Mirror ready: ' . $this->sourceId . ' → ' . implode(',', $this->targetIds));
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
        if (($message['_'] ?? '') !== 'message'
            || $this->getId($message) !== $this->sourceId
            || ($message['id'] ?? 0) <= $this->baseline) {
            return;
        }
        if ($this->sourceProtected && App::queue()->getMeta('source_revalidation_needed') !== '1') {
            $message['noforwards'] = true;
        }
        App::queue()->enqueue($this->sourceId, $message);
        App::queue()->setMeta('last_received_at', (string) time());
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
        if (!$this->ready || !App::workerEventsAllowed() || $channelId !== $this->sourceId) {
            return;
        }
        App::queue()->setMeta('source_revalidation_needed', '1');
        $this->refreshSourceState();
    }

    /** Do not deliver or permanently skip using protection/access flags known to be stale. */
    private function refreshSourceState(): bool
    {
        try {
            $info = App::validateSource($this, $this->sourceId);
            $this->sourceProtected = !empty($info['Chat']['noforwards']);
            App::queue()->setMeta('source_protected', $this->sourceProtected ? '1' : '0');
            App::queue()->setMeta('source_revalidation_needed', '0');
            return true;
        } catch (Throwable $error) {
            App::queue()->setMeta('source_revalidation_needed', '1');
            if (App::isPermanentSourceFailure($error)) {
                App::queue()->setMeta('paused_reason', 'SOURCE_ACCESS: ' . $error->getMessage());
            } else {
                $delay = $error instanceof RateLimitError ? max(1, $error->getWaitTimeLeft()) + 1 : App::config()['retry_base_seconds'];
                App::queue()->setMeta('forward_retry_at', (string) (time() + $delay));
            }
            $this->logger('Source refresh failed: ' . $error->getMessage());
            return false;
        }
    }

    #[Cron(period: 3.0)]
    public function drainQueue(): void
    {
        if (!$this->ready || !App::workerEventsAllowed() || $this->sending) {
            return;
        }
        $queue = App::queue();
        $queue->setMeta('worker_heartbeat_at', (string) time());
        if ($queue->getMeta('paused_reason') || (int) $queue->getMeta('forward_retry_at') > time()) {
            return;
        }
        if ($queue->getMeta('source_revalidation_needed') === '1' && !$this->refreshSourceState()) { return; }
        $this->sending = true;
        try {
            foreach ($this->targetIds as $targetId) {
                if ($queue->getMeta('target_pause_' . $targetId)) { continue; }
                $batch = $queue->pendingBatches(time(), App::config()['album_settle_seconds'], $targetId)[0] ?? null;
                if ($batch === null) { continue; }
                if ($batch['source_id'] !== $this->sourceId) {
                    $queue->setMeta('paused_reason', 'QUEUE_BINDING_MISMATCH');
                    return;
                }
                if ($batch['protected'] || $this->sourceProtected) {
                    if (!empty($batch['batch_key'])) { $queue->setMeta('target_pause_' . $targetId, 'REPOST_PROTECTION_RECONCILIATION_REQUIRED'); continue; }
                    $queue->markSkipped($batch['ids'], $this->sourceId, 'CHAT_FORWARDS_RESTRICTED', $targetId);
                    continue;
                }
                if (!$this->forwardBatch($batch, $targetId)) { return; }
            }
        } finally { $this->sending = false; }
    }

    /** Return false when an account/source failure must stop all destinations. */
    private function forwardBatch(array $batch, int $targetId): bool
    {
        $queue = App::queue();
        $frozenLoaded = false;
        try {
            if (($batch['delivery_mode'] ?? '') !== 'repost' || $queue->getMeta('delivery_mode') !== 'repost') {
                $queue->setMeta('paused_reason', 'REPOST_MODE_NOT_ACTIVATED');
                return false;
            }
            // Fresh channel/message protection gates precede reference reuse.
            if (!$this->refreshSourceState()) { return false; }
            if ($this->sourceProtected) {
                if (!empty($batch['batch_key'])) { $queue->setMeta('target_pause_' . $targetId, 'REPOST_PROTECTION_RECONCILIATION_REQUIRED'); return true; }
                $queue->markSkipped($batch['ids'], $this->sourceId, 'CHAT_FORWARDS_RESTRICTED', $targetId);
                return true;
            }
            $payloads = $queue->frozenPayloads($this->sourceId, $batch['ids']);
            $frozenLoaded = $payloads !== [];
            $raw = Repost::read($this, $batch);
            $missing = array_values(array_diff($batch['ids'], array_keys($payloads)));
            if ($missing !== []) {
                $captureBatch = $batch; $captureBatch['ids'] = $missing;
                $fresh = Repost::capture($this, $captureBatch, $raw);
                $queue->freezePayloads($this->sourceId, $fresh);
                $payloads = $queue->frozenPayloads($this->sourceId, $batch['ids']);
            }
            $queue->freezeRepostBatch($batch, $targetId);
            $payloads = Repost::authorize($this, $payloads, $raw);
            // Containment can be committed while source reads are in progress.
            if ($queue->getMeta('paused_reason') || $queue->getMeta('target_pause_' . $targetId)) { return false; }
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
            if ($targetId === $this->targetId) { $queue->setMeta('last_forwarded_id', (string) max($batch['ids'])); }
            $this->logger('Reposted to ' . $targetId . ': ' . implode(',', $batch['ids']));
        } catch (RepostFailure $error) {
            if ($frozenLoaded || !empty($batch['batch_key'])) {
                $queue->setMeta('target_pause_' . $targetId, 'REPOST_RECONCILIATION_REQUIRED: ' . $error->getMessage());
                return true;
            }
            $queue->markSkipped($batch['ids'], $this->sourceId, $error->getMessage(), $targetId);
            $this->logger('Repost blocked: ' . $error->getMessage());
        } catch (RateLimitError $error) {
            $delay = max(1, $error->getWaitTimeLeft()) + 1;
            $this->retry($batch, $error->rpc, $delay, $targetId);
            $queue->setMeta('forward_retry_at', (string) (time() + $delay));
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
                $queue->markSkipped($batch['ids'], $this->sourceId, $rpc, $targetId);
                $this->logger('Skipped unforwardable posts: ' . $rpc);
            } elseif (App::isAccountFailure($error)) {
                $queue->setMeta('paused_reason', $rpc);
                return false;
            } elseif (in_array($rpc, ['CHANNEL_PRIVATE', 'CHANNEL_INVALID', 'PEER_ID_INVALID'], true)) {
                if (!$this->refreshSourceState()) { return false; }
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
        App::queue()->retryBatch($batch['ids'], $this->sourceId, $reason, $retryAt, $targetId);
        $this->logger('Retry source posts in ' . $delay . ' seconds: ' . $reason);
    }
}
