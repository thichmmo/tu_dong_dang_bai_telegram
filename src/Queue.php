<?php

declare(strict_types=1);

namespace TelegramMirror;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Durable forwarding queue. Terminal rows are retained to deduplicate updates.
 *
 * Only message identifiers and delivery state are stored; Telegram media and
 * message bodies remain on Telegram. Callers must serialize forwarding workers.
 */
final class Queue
{
    private readonly PDO $database;

    public function __construct(string $path)
    {
        if ($path === '') {
            throw new InvalidArgumentException('Queue database path cannot be empty.');
        }
        if (PHP_INT_SIZE < 8) {
            throw new RuntimeException('Telegram message random IDs require 64-bit PHP.');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The pdo_sqlite PHP extension is required.');
        }

        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create the queue database directory.');
            }
        }

        $this->database = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec('PRAGMA busy_timeout = 100');
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA synchronous = FULL');
        $this->database->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS queue_messages (
                source_id INTEGER NOT NULL,
                message_id INTEGER NOT NULL,
                message_date INTEGER NOT NULL,
                grouped_id TEXT,
                protected INTEGER NOT NULL DEFAULT 0,
                random_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'forwarded', 'skipped')),
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt INTEGER NOT NULL DEFAULT 0,
                reason TEXT,
                first_seen INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (source_id, message_id)
            );
            CREATE INDEX IF NOT EXISTS queue_pending_order
                ON queue_messages (status, source_id, message_id);
            CREATE INDEX IF NOT EXISTS queue_album
                ON queue_messages (source_id, grouped_id, status, message_id);
            CREATE TABLE IF NOT EXISTS queue_metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS queue_targets (
                target_id INTEGER PRIMARY KEY,
                source_id INTEGER NOT NULL,
                cutoff INTEGER NOT NULL CHECK (cutoff >= 0),
                created_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS queue_deliveries (
                source_id INTEGER NOT NULL,
                message_id INTEGER NOT NULL,
                target_id INTEGER NOT NULL,
                random_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'forwarded', 'skipped')),
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt INTEGER NOT NULL DEFAULT 0,
                reason TEXT,
                first_seen INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (source_id, message_id, target_id)
            );
            CREATE INDEX IF NOT EXISTS queue_delivery_pending
                ON queue_deliveries (target_id, status, source_id, message_id);
            SQL);

        // Additive migration: old rows remain explicitly legacy forward deliveries.
        $this->database->exec('BEGIN IMMEDIATE');
        try {
        $columns = array_column($this->database->query('PRAGMA table_info(queue_deliveries)')->fetchAll(), 'name');
        foreach ([
            'delivery_mode' => "TEXT NOT NULL DEFAULT 'forward' CHECK(delivery_mode IN ('forward','repost'))",
            'destination_message_id' => 'INTEGER CHECK(destination_message_id IS NULL OR destination_message_id > 0)',
            'batch_key' => 'TEXT',
        ] as $name => $definition) {
            if (!in_array($name, $columns, true)) { $this->database->exec('ALTER TABLE queue_deliveries ADD COLUMN ' . $name . ' ' . $definition); }
        }
        $this->database->exec('CREATE TABLE IF NOT EXISTS queue_repost_payloads (source_id INTEGER NOT NULL, message_id INTEGER NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(source_id,message_id))');
        $this->database->exec('CREATE TABLE IF NOT EXISTS queue_repost_overflow (batch_key TEXT PRIMARY KEY, plan TEXT NOT NULL, progress TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
        $this->database->exec('CREATE UNIQUE INDEX IF NOT EXISTS queue_destination_message ON queue_deliveries(target_id,destination_message_id) WHERE destination_message_id IS NOT NULL');
        $this->database->exec('COMMIT');
        } catch (Throwable $e) { $this->database->exec('ROLLBACK'); throw $e; }

        if ($path !== ':memory:') {
            @chmod($path, 0600);
        }
    }

    /**
     * Activate the full, append-only destination list atomically.
     *
     * The original destination inherits every legacy delivery, including
     * terminal rows and random IDs. Added destinations start at currentCutoff
     * and receive no existing backlog. Cutoffs never change on later calls.
     *
     * @param list<int|string> $targetIds
     */
    public function registerTargets(int $sourceId, array $targetIds, int $currentCutoff): void
    {
        self::channelId($sourceId);
        if ($currentCutoff < 0 || $targetIds === []) {
            throw new InvalidArgumentException('Target registration requires destinations and a non-negative cutoff.');
        }
        $targets = [];
        foreach ($targetIds as $value) {
            $id = self::channelId($value);
            if ($id === $sourceId || isset($targets[$id])) {
                throw new InvalidArgumentException('Destinations must be unique and different from the source.');
            }
            $targets[$id] = true;
        }

        $this->database->beginTransaction();
        try {
            $storedSource = $this->getMeta('source_id');
            if ($storedSource !== null && self::channelId($storedSource) !== $sourceId) {
                throw new InvalidArgumentException('The queue is bound to a different source.');
            }
            $storedTarget = $this->getMeta('target_id');
            $legacyTarget = $storedTarget === null ? null : self::channelId($storedTarget);
            $sealedTarget = $this->getMeta('queue_legacy_target_id');
            if ($sealedTarget !== null && $legacyTarget !== self::channelId($sealedTarget)) {
                throw new InvalidArgumentException('The original destination binding cannot be changed.');
            }
            if ($legacyTarget !== null && !isset($targets[$legacyTarget])) {
                throw new InvalidArgumentException('The original destination cannot be remapped or removed.');
            }
            $existing = $this->database->query('SELECT * FROM queue_targets ORDER BY created_at, target_id')->fetchAll();
            foreach ($existing as $row) {
                if ((int) $row['source_id'] !== $sourceId || !isset($targets[(int) $row['target_id']])) {
                    throw new InvalidArgumentException('Registered source and destinations cannot be remapped or removed.');
                }
            }
            $foreign = $this->database->prepare('SELECT 1 FROM queue_messages WHERE source_id != ? LIMIT 1');
            $foreign->execute([$sourceId]);
            if ($foreign->fetchColumn() !== false) {
                throw new InvalidArgumentException('Legacy messages belong to a different source.');
            }

            $insert = $this->database->prepare(
                'INSERT OR IGNORE INTO queue_targets (target_id, source_id, cutoff, created_at) VALUES (?, ?, ?, ?)'
            );
            $now = time();
            foreach (array_keys($targets) as $targetId) {
                $cutoff = $currentCutoff;
                if ($existing === [] && $targetId === $legacyTarget) {
                    $oldCutoff = $this->getMeta('source_min_id');
                    if ($oldCutoff !== null) {
                        $valid = filter_var($oldCutoff, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                        if ($valid === false) {
                            throw new InvalidArgumentException('The legacy source cutoff is invalid.');
                        }
                        $cutoff = $valid;
                    }
                }
                $insert->execute([$targetId, $sourceId, $cutoff, $now]);
            }
            if ($existing === [] && $legacyTarget !== null) {
                $migrate = $this->database->prepare(<<<'SQL'
                    INSERT INTO queue_deliveries
                        (source_id, message_id, target_id, random_id, status,
                         attempts, next_attempt, reason, first_seen, updated_at)
                    SELECT source_id, message_id, ?, random_id, status,
                           attempts, next_attempt, reason, first_seen, updated_at
                    FROM queue_messages WHERE source_id = ?
                    SQL);
                $migrate->execute([$legacyTarget, $sourceId]);
                $this->setMeta('queue_legacy_target_id', (string) $legacyTarget);
            }
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) { $this->database->rollBack(); }
            throw $exception;
        }
    }

    public function hasTarget(int $targetId): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM queue_targets WHERE target_id = ?');
        $statement->execute([$targetId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $message A raw MTProto message. */
    public function enqueue(int $sourceId, array $message, ?array $activeTargetIds = null): void
    {
        $activeTargets = null;
        if ($activeTargetIds !== null) {
            $activeTargets = [];
            foreach ($activeTargetIds as $targetId) {
                $activeTargets[self::channelId($targetId)] = true;
            }
        }
        $messageId = self::messageId($message['id'] ?? null);
        $date = $message['date'] ?? time();
        if (!is_int($date) || $date < 0) {
            throw new InvalidArgumentException('Message date must be a non-negative integer.');
        }

        $groupedId = $message['grouped_id'] ?? null;
        if ($groupedId !== null && !is_int($groupedId) && !is_string($groupedId)) {
            throw new InvalidArgumentException('Message grouped_id must be an integer or string.');
        }
        if ($groupedId !== null) {
            $groupedId = (string) $groupedId;
            if ($groupedId === '') {
                $groupedId = null;
            }
        }

        $now = time();
        $targets = $this->database->query('SELECT * FROM queue_targets ORDER BY created_at, target_id')->fetchAll();
        if ($targets !== [] && (int) $targets[0]['source_id'] !== $sourceId) {
            throw new InvalidArgumentException('The queue is bound to a different source.');
        }
        $randomId = random_int(1, PHP_INT_MAX);
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(<<<'SQL'
                INSERT OR IGNORE INTO queue_messages
                    (source_id, message_id, message_date, grouped_id, protected,
                     random_id, first_seen, updated_at)
                VALUES
                    (:source_id, :message_id, :message_date, :grouped_id, :protected,
                     :random_id, :first_seen, :updated_at)
                SQL);
            $statement->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
            $statement->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $statement->bindValue(':message_date', $date, PDO::PARAM_INT);
            $statement->bindValue(':grouped_id', $groupedId, $groupedId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->bindValue(':protected', !empty($message['noforwards']) ? 1 : 0, PDO::PARAM_INT);
            $statement->bindValue(':random_id', $randomId, PDO::PARAM_INT);
            $statement->bindValue(':first_seen', $now, PDO::PARAM_INT);
            $statement->bindValue(':updated_at', $now, PDO::PARAM_INT);
            $statement->execute();
            if ($statement->rowCount() > 0 && $targets !== []) {
                $insert = $this->database->prepare(<<<'SQL'
                    INSERT INTO queue_deliveries
                        (source_id, message_id, target_id, random_id, first_seen, updated_at, delivery_mode)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    SQL);
                $used = [$randomId];
                $legacyTarget = $this->getMeta('queue_legacy_target_id') ?? $this->getMeta('target_id');
                foreach ($targets as $target) {
                    $targetId = (int) $target['target_id'];
                    if (($activeTargets !== null && !isset($activeTargets[$targetId]))
                        || $messageId <= max((int) $target['cutoff'], (int) $this->getMeta('target_runtime_min_id_' . $targetId))) {
                        continue;
                    }
                    if ($legacyTarget !== null && $targetId === (int) $legacyTarget) {
                        $deliveryRandom = $randomId;
                    } else {
                        do { $deliveryRandom = random_int(1, PHP_INT_MAX); }
                        while (in_array($deliveryRandom, $used, true));
                        $used[] = $deliveryRandom;
                    }
                    $insert->execute([$sourceId, $messageId, $targetId, $deliveryRandom, $now, $now, $this->getMeta('delivery_mode') === 'repost' ? 'repost' : 'forward']);
                }
            }
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) { $this->database->rollBack(); }
            throw $exception;
        }
    }

    /**
     * Return at most twenty batches, one oldest eligible batch per source and
     * destination. Without a destination registry, retain the legacy behavior.
     *
     * Re-read after forwarding a batch to drain its source. Exposing only one
     * batch per destination prevents later posts passing its failed send. Album parts
     * settle together, measured from receipt of the most recent queued part.
     *
     * @return list<array{source_id: int, target_id?: int, ids: list<int>, random_ids: list<int>,
     *     attempts: int, protected: bool, messages: list<array{id: int, date: int,
     *     grouped_id: ?string, noforwards: bool}>}>
     */
    public function pendingBatches(int $now, int $settleSeconds = 5, ?int $targetId = null): array
    {
        if ($settleSeconds < 0) {
            throw new InvalidArgumentException('Album settle seconds cannot be negative.');
        }

        $deliveryMode = $this->hasRegistry();
        if ($targetId !== null && !$this->hasTarget($targetId)) {
            throw new InvalidArgumentException('Unknown destination.');
        }
        if ($deliveryMode) {
            $modeFilter = $this->getMeta('delivery_mode') === 'repost' ? " AND delivery_mode = 'repost'" : '';
            $filter = ($targetId === null ? '' : ' AND target_id = :target_id') . $modeFilter;
            $oldest = $this->database->prepare(
                'SELECT m.message_date, m.grouped_id, m.protected, d.* '
                . 'FROM queue_deliveries AS d JOIN queue_messages AS m '
                . 'ON m.source_id = d.source_id AND m.message_id = d.message_id '
                . 'JOIN (SELECT source_id, target_id, MIN(message_id) AS first_id '
                . "FROM queue_deliveries WHERE status = 'pending'" . $filter
                . ' GROUP BY source_id, target_id) AS oldest '
                . 'ON oldest.source_id = d.source_id AND oldest.target_id = d.target_id '
                . "AND oldest.first_id = d.message_id WHERE d.status = 'pending' "
                . 'ORDER BY d.first_seen, d.source_id, d.target_id, d.message_id'
            );
            if ($targetId !== null) { $oldest->bindValue(':target_id', $targetId, PDO::PARAM_INT); }
            $oldest->execute();
        } else {
            $oldest = $this->database->query(<<<'SQL'
                SELECT m.* FROM queue_messages AS m
                JOIN (
                    SELECT source_id, MIN(message_id) AS first_id
                    FROM queue_messages WHERE status = 'pending'
                    GROUP BY source_id
                ) AS oldest ON oldest.source_id = m.source_id
                    AND oldest.first_id = m.message_id
                WHERE m.status = 'pending'
                ORDER BY m.first_seen, m.source_id, m.message_id
                SQL);
        }

        $batches = [];
        while (($first = $oldest->fetch()) !== false) {
            $rows = [$first];
            if ($first['grouped_id'] !== null && (!$deliveryMode || $first['batch_key'] === null)) {
                if ($deliveryMode) {
                    $parts = $this->database->prepare(<<<'SQL'
                        SELECT m.message_date, m.grouped_id, m.protected, d.*
                        FROM queue_deliveries AS d JOIN queue_messages AS m
                            ON m.source_id = d.source_id AND m.message_id = d.message_id
                        WHERE d.source_id = :source_id AND d.target_id = :target_id
                          AND m.grouped_id = :grouped_id AND d.status = 'pending' AND d.batch_key IS NULL AND d.delivery_mode = :delivery_mode
                        ORDER BY d.message_id
                        SQL);
                    $parts->bindValue(':target_id', (int) $first['target_id'], PDO::PARAM_INT);
                    $parts->bindValue(':delivery_mode', $first['delivery_mode'], PDO::PARAM_STR);
                } else {
                    $parts = $this->database->prepare(<<<'SQL'
                        SELECT * FROM queue_messages
                        WHERE source_id = :source_id AND grouped_id = :grouped_id
                          AND status = 'pending'
                        ORDER BY message_id
                        SQL);
                }
                $parts->bindValue(':source_id', (int) $first['source_id'], PDO::PARAM_INT);
                $parts->bindValue(':grouped_id', $first['grouped_id'], PDO::PARAM_STR);
                $parts->execute();
                $rows = $parts->fetchAll();
            }

            if ($deliveryMode && $first['batch_key'] !== null) {
                $parts = $this->database->prepare("SELECT m.message_date,m.grouped_id,m.protected,d.* FROM queue_deliveries d JOIN queue_messages m ON m.source_id=d.source_id AND m.message_id=d.message_id WHERE d.source_id=? AND d.target_id=? AND d.batch_key=? AND d.status='pending' AND d.delivery_mode='repost' ORDER BY d.message_id");
                $parts->execute([$first['source_id'],$first['target_id'],$first['batch_key']]);
                $rows = $parts->fetchAll();
            }
            if ($rows === []) {
                continue;
            }

            $lastSeen = 0;
            $retryAt = 0;
            $batch = [
                'source_id' => (int) $first['source_id'],
                'ids' => [],
                'random_ids' => [],
                'attempts' => 0,
                'protected' => false,
                'messages' => [],
            ];
            if ($deliveryMode) { $batch['target_id'] = (int) $first['target_id']; $batch['delivery_mode'] = $first['delivery_mode']; $batch['batch_key'] = $first['batch_key']; }
            foreach ($rows as $row) {
                if ($deliveryMode && $row['delivery_mode'] !== $first['delivery_mode']) { throw new RuntimeException('Mixed delivery modes.'); }
                $lastSeen = max($lastSeen, (int) $row['first_seen']);
                $retryAt = max($retryAt, (int) $row['next_attempt']);
                $batch['attempts'] = max($batch['attempts'], (int) $row['attempts']);
                $batch['protected'] = $batch['protected'] || (bool) $row['protected'];
                $batch['ids'][] = (int) $row['message_id'];
                $batch['random_ids'][] = (int) $row['random_id'];
                $batch['messages'][] = [
                    'id' => (int) $row['message_id'],
                    'date' => (int) $row['message_date'],
                    'grouped_id' => $row['grouped_id'],
                    'noforwards' => (bool) $row['protected'],
                ];
            }

            if ($retryAt > $now) {
                continue;
            }
            if ($first['grouped_id'] !== null && $lastSeen > $now - $settleSeconds) {
                continue;
            }

            $batches[] = $batch;
            if (count($batches) === 20) {
                break;
            }
        }

        return $batches;
    }

    /** Explicit cutover under the caller's exclusive worker lock, never a constructor side effect. */
    public function activateRepost(int $sourceId, array $targetIds): int
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $targets = $this->database->query('SELECT source_id,target_id FROM queue_targets')->fetchAll();
            $actual = array_map('intval', array_column($targets, 'target_id'));
            sort($actual); sort($targetIds);
            if ($actual !== $targetIds || $targets === []
                || array_unique(array_map('intval', array_column($targets, 'source_id'))) !== [$sourceId]
                || (int)$this->getMeta('source_id') !== $sourceId) {
                throw new RuntimeException('Repost activation bindings differ.');
            }
            if ($this->getMeta('delivery_mode') === 'repost') {
                if ((int)$this->database->query("SELECT COUNT(*) FROM queue_deliveries WHERE delivery_mode='forward' AND status='pending'")->fetchColumn() !== 0) { throw new RuntimeException('Legacy pending deliveries remain after repost activation.'); }
                $this->database->exec('COMMIT'); return 0;
            }
            $count = $this->database->exec("UPDATE queue_deliveries SET status='skipped', reason='LEGACY_FORWARD_PENDING_QUARANTINED' WHERE delivery_mode='forward' AND status='pending'");
            $this->setMeta('delivery_mode', 'repost');
            $this->setMeta('repost_activated_at', (string)time());
            $this->database->exec('COMMIT');
            return $count;
        } catch (Throwable $e) { $this->database->exec('ROLLBACK'); throw $e; }
    }

    /** Clear only this maintenance pause atomically after the explicit mode seal. */
    public function resumeRepostMaintenance(array $targetIds): void
    {
        $this->database->beginTransaction();
        try {
            if($this->getMeta('delivery_mode')!=='repost'||(int)$this->getMeta('repost_activated_at')<1){throw new RuntimeException('Repost activation missing.');}
            $keys=['paused_reason'];foreach($targetIds as$id){$keys[]='target_pause_'.$id;}
            foreach($keys as$key){if($this->getMeta($key)!=='USER_MODE_CHANGE_REPOST'){throw new RuntimeException('Maintenance pause ownership changed.');}}
            foreach($keys as$key){$this->setMeta($key,'');}
            $this->database->commit();
        }catch(Throwable $e){if($this->database->inTransaction()){$this->database->rollBack();}throw $e;}
    }

    /** Freeze source payload once for every destination, preserving binary Telegram references. */
    public function frozenPayloads(int $sourceId, array $ids): array
    {
        $get=$this->database->prepare('SELECT payload FROM queue_repost_payloads WHERE source_id=? AND message_id=?');
        $result=[];
        foreach($ids as$id){
            $get->execute([$sourceId,$id]);$raw=$get->fetchColumn();if($raw===false){continue;}
            $encoded=base64_decode($raw,true);$p=$encoded===false?false:unserialize($encoded,['allowed_classes'=>false]);
            if(!is_array($p)||($p['version']??null)!==1){throw new RuntimeException('Frozen repost payload invalid.');}
            $result[$id]=$p;
        }
        return $result;
    }

    public function freezePayloads(int $sourceId, array $fresh): array
    {
        $this->database->beginTransaction();
        try {
            $put = $this->database->prepare('INSERT OR IGNORE INTO queue_repost_payloads(source_id,message_id,payload) VALUES(?,?,?)');
            $get = $this->database->prepare('SELECT payload FROM queue_repost_payloads WHERE source_id=? AND message_id=?');
            $result = [];
            foreach ($fresh as $id => $payload) {
                $put->execute([$sourceId,$id,base64_encode(serialize($payload))]);
                $get->execute([$sourceId,$id]);
                $encoded = base64_decode($get->fetchColumn(), true);
                $p = $encoded === false ? false : unserialize($encoded, ['allowed_classes'=>false]);
                if (!is_array($p) || ($p['version']??null)!==1) { throw new RuntimeException('Frozen repost payload invalid.'); }
                $result[$id] = $p;
            }
            $this->database->commit(); return $result;
        } catch(Throwable $e) { if($this->database->inTransaction()){$this->database->rollBack();} throw $e; }
    }

    /** Persist membership before the network call so a late album part cannot change a retry. */
    public function freezeRepostBatch(array $batch, int $targetId): void
    {
        if (($batch['delivery_mode']??null)!=='repost' || $this->getMeta('delivery_mode')!=='repost') {
            throw new RuntimeException('Legacy forwarding deliveries cannot be reposted.');
        }
        $key = hash('sha256', json_encode([$batch['source_id'],$targetId,$batch['ids'],$batch['random_ids']], JSON_THROW_ON_ERROR));
        $this->database->beginTransaction();
        try {
            $put=$this->database->prepare("UPDATE queue_deliveries SET batch_key=? WHERE source_id=? AND message_id=? AND target_id=? AND random_id=? AND status='pending' AND delivery_mode='repost' AND (batch_key IS NULL OR batch_key=?)");
            foreach($batch['ids']as$i=>$id){
                $put->execute([$key,$batch['source_id'],$id,$targetId,$batch['random_ids'][$i],$key]);
                if($put->rowCount()!==1){throw new RuntimeException('Repost batch changed.');}
            }
            $this->validateFrozenBatch($batch,$targetId,$key);
            $this->database->commit();
        }catch(Throwable $e){if($this->database->inTransaction()){$this->database->rollBack();}throw $e;}
    }

    private function validateFrozenBatch(array $batch,int $targetId,string $key):void
    {
        $get=$this->database->prepare('SELECT message_id,random_id,status FROM queue_deliveries WHERE source_id=? AND target_id=? AND batch_key=? ORDER BY message_id');
        $get->execute([$batch['source_id'],$targetId,$key]);$rows=$get->fetchAll();
        if(array_map('intval',array_column($rows,'message_id'))!==$batch['ids']||array_map('intval',array_column($rows,'random_id'))!==$batch['random_ids']||array_unique(array_column($rows,'status'))!==['pending']){throw new RuntimeException('Frozen batch membership changed.');}
    }

    /**
     * Persist a caption-overflow request before sending it. Existing delivery
     * random IDs and frozen payload bytes are never changed by this protocol.
     * freshAttempt is reserved for a known new request or an audited migration.
     */
    public function prepareOverflow(array $batch, int $targetId, array $payloads, bool $freshAttempt = false): ?array
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $context = $this->overflowContext($batch, $targetId, $payloads);
            $plan = $this->overflowPlan($context);
            if ($plan === null && $context['overflow_ids'] !== []) {
                if ($context['status'] !== 'pending' || ($context['frozen'] && !$freshAttempt)) {
                    throw new RuntimeException('REPOST_CAPTION_RECONCILIATION_REQUIRED');
                }
                // prepareOverflow can also be called before freezeRepostBatch.
                if (!$context['frozen']) {
                    $put = $this->database->prepare("UPDATE queue_deliveries SET batch_key=? WHERE source_id=? AND target_id=? AND message_id=? AND random_id=? AND status='pending' AND delivery_mode='repost' AND batch_key IS NULL");
                    foreach ($context['ids'] as $index => $id) {
                        $put->execute([$context['batch_key'], $context['source_id'], $targetId, $id, $context['random_ids'][$index]]);
                        if ($put->rowCount() !== 1) { throw new RuntimeException('REPOST_CAPTION_BATCH_CHANGED'); }
                    }
                }
                $used = array_fill_keys($context['random_ids'], true);
                // Exclude every ID already reserved by another overflow plan.
                foreach ($this->database->query('SELECT plan FROM queue_repost_overflow') as $row) {
                    $other = json_decode($row['plan'], true, 64, JSON_THROW_ON_ERROR);
                    foreach ([...($other['media_random_ids'] ?? []), ...array_values($other['caption_random_ids'] ?? [])] as $id) {
                        $used[$id] = true;
                    }
                }
                $exists = $this->database->prepare('SELECT 1 FROM queue_deliveries WHERE random_id=? LIMIT 1');
                $newRandom = static function () use (&$used, $exists): int {
                    do {
                        $id = random_int(1, PHP_INT_MAX);
                        if (isset($used[$id])) { continue; }
                        $exists->execute([$id]);
                        if ($exists->fetchColumn() !== false) { continue; }
                        $used[$id] = true;
                        return $id;
                    } while (true);
                };
                $media = [];
                foreach ($context['ids'] as $_) { $media[] = $newRandom(); }
                $captions = [];
                foreach ($context['overflow_ids'] as $id) { $captions[$id] = $newRandom(); }
                $immutable = [
                    'version' => 1, 'batch_key' => $context['batch_key'],
                    'source_id' => $context['source_id'], 'target_id' => $targetId,
                    'ids' => $context['ids'], 'random_ids' => $context['random_ids'],
                    'payload_sha256' => $context['payload_sha256'],
                    'media_random_ids' => $media, 'overflow_ids' => $context['overflow_ids'],
                    'caption_random_ids' => $captions,
                ];
                $progress = ['media_ack' => null, 'caption_acks' => []];
                $put = $this->database->prepare('INSERT INTO queue_repost_overflow(batch_key,plan,progress,created_at,updated_at) VALUES(?,?,?,?,?)');
                $now = time();
                $put->execute([$context['batch_key'], json_encode($immutable, JSON_THROW_ON_ERROR),
                    json_encode($progress, JSON_THROW_ON_ERROR), $now, $now]);
                $plan = $immutable + $progress;
            }
            $this->database->exec('COMMIT');
            return $plan;
        } catch (Throwable $error) {
            $this->database->exec('ROLLBACK');
            throw $error;
        }
    }

    /** Translate network media random IDs back to immutable delivery random IDs. */
    public function rememberOverflowMediaAck(array $batch, int $targetId, array $networkAck): array
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $context = $this->overflowContext($batch, $targetId);
            $plan = $this->overflowPlan($context) ?? throw new RuntimeException('REPOST_CAPTION_PLAN_MISSING');
            $media = self::overflowAck($networkAck, $plan['media_random_ids']);
            $translated = [];
            foreach ($plan['random_ids'] as $index => $random) {
                $translated[$random] = $media[$plan['media_random_ids'][$index]];
            }
            if ($plan['media_ack'] !== null && $plan['media_ack'] !== $translated) {
                throw new RuntimeException('REPOST_CAPTION_ACK_CHANGED');
            }
            if ($plan['media_ack'] !== null) {
                $this->database->exec('COMMIT');
                return $plan;
            }
            if (array_intersect(array_values($translated), array_values($plan['caption_acks'])) !== []) {
                throw new RuntimeException('REPOST_CAPTION_ACK_INVALID');
            }
            $plan['media_ack'] = $translated;
            $this->storeOverflowProgress($plan);
            $this->database->exec('COMMIT');
            return $plan;
        } catch (Throwable $error) {
            $this->database->exec('ROLLBACK');
            throw $error;
        }
    }

    /** Remember each sidecar acknowledgement before the next network operation. */
    public function rememberOverflowCaptionAck(array $batch, int $targetId, int $sourceMessageId, int $destinationMessageId): array
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $context = $this->overflowContext($batch, $targetId);
            $plan = $this->overflowPlan($context) ?? throw new RuntimeException('REPOST_CAPTION_PLAN_MISSING');
            if ($plan['media_ack'] === null || !in_array($sourceMessageId, $plan['overflow_ids'], true) || $destinationMessageId < 1) {
                throw new RuntimeException('REPOST_CAPTION_ACK_INVALID');
            }
            $old = $plan['caption_acks'][$sourceMessageId] ?? null;
            if ($old !== null && $old !== $destinationMessageId) { throw new RuntimeException('REPOST_CAPTION_ACK_CHANGED'); }
            if ($old !== null) {
                $this->database->exec('COMMIT');
                return $plan;
            }
            $other = $plan['caption_acks'];
            unset($other[$sourceMessageId]);
            if (in_array($destinationMessageId, [...array_values($other), ...array_values($plan['media_ack'])], true)) {
                throw new RuntimeException('REPOST_CAPTION_ACK_INVALID');
            }
            $plan['caption_acks'][$sourceMessageId] = $destinationMessageId;
            // Canonical insertion order makes repeated calls and restart reads identical.
            $ordered = [];
            foreach ($plan['overflow_ids'] as $id) {
                if (isset($plan['caption_acks'][$id])) { $ordered[$id] = $plan['caption_acks'][$id]; }
            }
            $plan['caption_acks'] = $ordered;
            $this->storeOverflowProgress($plan);
            $this->database->exec('COMMIT');
            return $plan;
        } catch (Throwable $error) {
            $this->database->exec('ROLLBACK');
            throw $error;
        }
    }

    /** Caller holds the write transaction; bind plan identity to stored bytes. */
    private function overflowContext(array $batch, int $targetId, ?array $supplied = null): array
    {
        $source = $batch['source_id'] ?? null;
        $ids = $batch['ids'] ?? null;
        $randoms = $batch['random_ids'] ?? null;
        if (!is_int($source) || !is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 10
            || !is_array($randoms) || !array_is_list($randoms) || count($randoms) !== count($ids)
            || ($batch['delivery_mode'] ?? null) !== 'repost'
            || (isset($batch['target_id']) && $batch['target_id'] !== $targetId)
            || $this->getMeta('delivery_mode') !== 'repost'
            || (int) $this->getMeta('source_id') !== $source
            || (int) $this->getMeta('repost_activated_at') < 1) {
            throw new RuntimeException('REPOST_CAPTION_BATCH_INVALID');
        }
        self::channelId($source); self::channelId($targetId);
        $target = $this->database->prepare('SELECT source_id FROM queue_targets WHERE target_id=?');
        $target->execute([$targetId]);
        if ($target->fetchColumn() !== $source) { throw new RuntimeException('REPOST_CAPTION_BATCH_CHANGED'); }
        foreach ([...$ids, ...$randoms] as $id) {
            if (!is_int($id) || $id < 1) { throw new RuntimeException('REPOST_CAPTION_BATCH_INVALID'); }
        }
        $sorted = $ids; sort($sorted, SORT_NUMERIC);
        if ($sorted !== $ids || count(array_unique($ids)) !== count($ids) || count(array_unique($randoms)) !== count($ids)) {
            throw new RuntimeException('REPOST_CAPTION_BATCH_INVALID');
        }
        $key = hash('sha256', json_encode([$source, $targetId, $ids, $randoms], JSON_THROW_ON_ERROR));
        if (isset($batch['batch_key']) && $batch['batch_key'] !== $key) { throw new RuntimeException('REPOST_CAPTION_BATCH_CHANGED'); }
        $get = $this->database->prepare('SELECT message_id,random_id,batch_key,status,delivery_mode,destination_message_id FROM queue_deliveries WHERE source_id=? AND target_id=? AND message_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY message_id');
        $get->execute([$source, $targetId, ...$ids]);
        $rows = $get->fetchAll();
        if (array_column($rows, 'message_id') !== $ids || array_column($rows, 'random_id') !== $randoms
            || array_values(array_unique(array_column($rows, 'delivery_mode'))) !== ['repost']) {
            throw new RuntimeException('REPOST_CAPTION_BATCH_CHANGED');
        }
        $statuses = array_values(array_unique(array_column($rows, 'status')));
        $keys = array_values(array_unique(array_column($rows, 'batch_key'), SORT_REGULAR));
        if (count($statuses) !== 1 || !in_array($statuses[0], ['pending', 'forwarded'], true)
            || count($keys) !== 1 || ($keys[0] !== null && $keys[0] !== $key)) {
            throw new RuntimeException('REPOST_CAPTION_BATCH_CHANGED');
        }
        if ($keys[0] !== null) {
            $get = $this->database->prepare('SELECT message_id,random_id FROM queue_deliveries WHERE source_id=? AND target_id=? AND batch_key=? ORDER BY message_id');
            $get->execute([$source, $targetId, $key]); $members = $get->fetchAll();
            if (array_column($members, 'message_id') !== $ids || array_column($members, 'random_id') !== $randoms) {
                throw new RuntimeException('REPOST_CAPTION_BATCH_CHANGED');
            }
        }
        $get = $this->database->prepare('SELECT payload FROM queue_repost_payloads WHERE source_id=? AND message_id=?');
        $bytes = []; $overflow = [];
        if ($supplied !== null && count($supplied) !== count($ids)) { throw new RuntimeException('REPOST_CAPTION_PAYLOAD_CHANGED'); }
        foreach ($ids as $id) {
            $get->execute([$source, $id]); $encoded = $get->fetchColumn();
            $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
            $payload = $raw === false ? false : unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($payload) || ($payload['version'] ?? null) !== 1 || serialize($payload) !== $raw
                || !is_string($payload['message'] ?? null) || !array_key_exists('media', $payload)
                || ($payload['media'] !== null && !is_array($payload['media']))) {
                throw new RuntimeException('REPOST_CAPTION_PAYLOAD_INVALID');
            }
            if ($supplied !== null && (!isset($supplied[$id]) || !is_array($supplied[$id])
                || serialize(self::overflowSemantics($supplied[$id])) !== serialize(self::overflowSemantics($payload)))) {
                throw new RuntimeException('REPOST_CAPTION_PAYLOAD_CHANGED');
            }
            $bytes[$id] = $raw;
            if ($payload['media'] !== null) {
                $length = self::overflowTextLength($payload['message']);
                if ($length > 1024) {
                    if ($length > 4096) { throw new RuntimeException('REPOST_CAPTION_TEXT_TOO_LONG'); }
                    $overflow[] = $id;
                }
            }
        }
        return ['batch_key' => $key, 'source_id' => $source, 'target_id' => $targetId,
            'ids' => $ids, 'random_ids' => $randoms, 'payload_sha256' => hash('sha256', serialize($bytes)),
            'overflow_ids' => $overflow, 'frozen' => $keys[0] !== null, 'status' => $statuses[0], 'rows' => $rows];
    }

    private static function overflowSemantics(array $payload): array
    {
        // Only the two primary-file authorization fields may rotate on retry.
        if (in_array($payload['media']['_'] ?? null, ['inputMediaPhoto', 'inputMediaDocument'], true)
            && is_array($payload['media']['id'] ?? null)) {
            unset($payload['media']['id']['access_hash'], $payload['media']['id']['file_reference']);
        }
        return $payload;
    }

    private static function overflowTextLength(string $text): int
    {
        $points = preg_match_all('/./us', $text);
        $pairs = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
        if ($points === false || $pairs === false) { throw new RuntimeException('REPOST_CAPTION_PAYLOAD_INVALID'); }
        return $points + $pairs;
    }

    /** Read and validate immutable request plus durable ACK progress. */
    private function overflowPlan(array $context): ?array
    {
        $get = $this->database->prepare('SELECT plan,progress FROM queue_repost_overflow WHERE batch_key=?');
        $get->execute([$context['batch_key']]); $row = $get->fetch();
        if ($row === false) { return null; }
        $plan = json_decode($row['plan'], true, 64, JSON_THROW_ON_ERROR);
        $progress = json_decode($row['progress'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($plan) || !is_array($progress) || ($plan['version'] ?? null) !== 1) {
            throw new RuntimeException('REPOST_CAPTION_PLAN_INVALID');
        }
        foreach (['batch_key', 'source_id', 'target_id', 'ids', 'random_ids', 'payload_sha256', 'overflow_ids'] as $field) {
            if (!array_key_exists($field, $plan) || $plan[$field] !== $context[$field]) { throw new RuntimeException('REPOST_CAPTION_PLAN_CHANGED'); }
        }
        $media = $plan['media_random_ids'] ?? null; $captions = $plan['caption_random_ids'] ?? null;
        if (!is_array($media) || !array_is_list($media) || count($media) !== count($plan['ids']) || !is_array($captions)
            || array_keys($captions) !== $plan['overflow_ids']) { throw new RuntimeException('REPOST_CAPTION_PLAN_INVALID'); }
        $new = [...$media, ...array_values($captions)];
        foreach ($new as $random) {
            if (!is_int($random) || $random < 1) { throw new RuntimeException('REPOST_CAPTION_PLAN_INVALID'); }
        }
        if (count(array_unique($new)) !== count($new) || array_intersect($new, $plan['random_ids']) !== []
            || !array_key_exists('media_ack', $progress) || !is_array($progress['caption_acks'] ?? null)) {
            throw new RuntimeException('REPOST_CAPTION_PLAN_INVALID');
        }
        if ($progress['media_ack'] !== null) { $progress['media_ack'] = self::overflowAck($progress['media_ack'], $plan['random_ids']); }
        $seen = $progress['media_ack'] === null ? [] : array_values($progress['media_ack']);
        foreach ($progress['caption_acks'] as $id => $destination) {
            if (!in_array($id, $plan['overflow_ids'], true) || !is_int($destination) || $destination < 1
                || in_array($destination, $seen, true) || $progress['media_ack'] === null) { throw new RuntimeException('REPOST_CAPTION_ACK_INVALID'); }
            $seen[] = $destination;
        }
        if ($context['status'] === 'forwarded') {
            if ($progress['media_ack'] === null || count($progress['caption_acks']) !== count($plan['overflow_ids'])) {
                throw new RuntimeException('REPOST_CAPTION_COMPLETION_INVALID');
            }
            foreach ($context['rows'] as $index => $delivery) {
                if ($delivery['destination_message_id'] !== $progress['media_ack'][$plan['random_ids'][$index]]) {
                    throw new RuntimeException('REPOST_CAPTION_COMPLETION_INVALID');
                }
            }
        }
        return $plan + $progress;
    }

    private static function overflowAck(array $ack, array $randoms): array
    {
        if (count($ack) !== count($randoms)) { throw new RuntimeException('REPOST_CAPTION_ACK_INVALID'); }
        $ordered = [];
        foreach ($randoms as $random) {
            $destination = $ack[$random] ?? null;
            if (!is_int($destination) || $destination < 1 || in_array($destination, $ordered, true)) {
                throw new RuntimeException('REPOST_CAPTION_ACK_INVALID');
            }
            $ordered[$random] = $destination;
        }
        return $ordered;
    }

    private function storeOverflowProgress(array $plan): void
    {
        $put = $this->database->prepare('UPDATE queue_repost_overflow SET progress=?,updated_at=? WHERE batch_key=?');
        $put->execute([json_encode(['media_ack' => $plan['media_ack'], 'caption_acks' => $plan['caption_acks']], JSON_THROW_ON_ERROR),
            time(), $plan['batch_key']]);
        if ($put->rowCount() !== 1) { throw new RuntimeException('REPOST_CAPTION_PLAN_CHANGED'); }
    }

    /** Complete ACK and final destination IDs commit together under stable random IDs. */
    public function markReposted(array $batch, int $targetId, array $ack): void
    {
        if(count($ack)!==count($batch['ids'])||count(array_unique(array_values($ack)))!==count($batch['ids'])){throw new RuntimeException('Incomplete repost acknowledgement.');}
        $this->database->beginTransaction();
        try{
            $key=hash('sha256',json_encode([$batch['source_id'],$targetId,$batch['ids'],$batch['random_ids']],JSON_THROW_ON_ERROR));
            $this->validateFrozenBatch($batch,$targetId,$key);
            $overflow = $this->database->prepare('SELECT 1 FROM queue_repost_overflow WHERE batch_key=?');
            $overflow->execute([$key]);
            if ($overflow->fetchColumn() !== false) {
                $plan = $this->overflowPlan($this->overflowContext($batch, $targetId));
                if ($plan['media_ack'] === null || count($plan['caption_acks']) !== count($plan['overflow_ids'])
                    || self::overflowAck($ack, $batch['random_ids']) !== $plan['media_ack']) {
                    throw new RuntimeException('REPOST_CAPTION_COMPLETION_INCOMPLETE');
                }
            }
            $put=$this->database->prepare("UPDATE queue_deliveries SET status='forwarded',destination_message_id=?,reason=NULL,next_attempt=0,updated_at=? WHERE source_id=? AND message_id=? AND target_id=? AND random_id=? AND status='pending' AND delivery_mode='repost'");
            foreach($batch['ids']as$i=>$id){
                $destination=$ack[(string)$batch['random_ids'][$i]]??null;
                if(!is_int($destination)||$destination<1){throw new RuntimeException('Invalid repost destination ID.');}
                $put->execute([$destination,time(),$batch['source_id'],$id,$targetId,$batch['random_ids'][$i]]);
                if($put->rowCount()!==1){throw new RuntimeException('Repost delivery changed before commit.');}
            }
            $this->database->commit();
        }catch(Throwable $e){if($this->database->inTransaction()){$this->database->rollBack();}throw $e;}
    }

    /** @param list<int> $ids */
    public function markForwarded(array $ids, int $sourceId, ?int $targetId = null): void
    {
        if($this->hasRegistry() && $this->getMeta('delivery_mode')==='repost'){throw new RuntimeException('Repost completion requires destination acknowledgements.');}
        $this->updateBatch($ids, $sourceId, 'forwarded', null, 0, false, $targetId);
    }

    /** @param list<int> $ids */
    public function markSkipped(array $ids, int $sourceId, string $reason, ?int $targetId = null): void
    {
        $this->updateBatch($ids, $sourceId, 'skipped', $reason, 0, false, $targetId);
    }

    /** @param list<int> $ids */
    public function retryBatch(array $ids, int $sourceId, string $reason, int $retryAt, ?int $targetId = null): void
    {
        if ($retryAt < 0) {
            throw new InvalidArgumentException('Retry timestamp cannot be negative.');
        }
        $this->updateBatch($ids, $sourceId, 'pending', $reason, $retryAt, true, $targetId);
    }

    public function getMeta(string $key): ?string
    {
        $statement = $this->database->prepare('SELECT value FROM queue_metadata WHERE key = :key');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function setMeta(string $key, string $value): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Metadata key cannot be empty.');
        }
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO queue_metadata (key, value) VALUES (:key, :value)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
            SQL);
        $statement->execute([':key' => $key, ':value' => $value]);
    }

    /** @return array{pending: int, forwarded: int, skipped: int, total: int, retries: int, last_reason: ?string} */
    public function stats(?int $targetId = null): array
    {
        if ($targetId !== null && !$this->hasTarget($targetId)) {
            throw new InvalidArgumentException('Unknown destination.');
        }
        $table = $this->hasRegistry() ? 'queue_deliveries' : 'queue_messages';
        $where = $targetId === null ? '' : ' WHERE target_id = ' . $targetId;
        $stats = [
            'pending' => 0,
            'forwarded' => 0,
            'skipped' => 0,
            'total' => 0,
            'retries' => 0,
            'last_reason' => null,
        ];
        $counts = $this->database->query('SELECT status, COUNT(*) AS count FROM ' . $table . $where . ' GROUP BY status');
        foreach ($counts as $row) {
            $stats[$row['status']] = (int) $row['count'];
            $stats['total'] += (int) $row['count'];
        }
        $stats['retries'] = (int) $this->database->query(
            'SELECT COUNT(*) FROM ' . $table . " WHERE status = 'pending' AND attempts > 0"
                . ($targetId === null ? '' : ' AND target_id = ' . $targetId)
        )->fetchColumn();
        $reason = $this->database->query('SELECT reason FROM ' . $table . ' WHERE reason IS NOT NULL'
            . ($targetId === null ? '' : ' AND target_id = ' . $targetId)
            . ' ORDER BY updated_at DESC, first_seen DESC, source_id, message_id DESC LIMIT 1')->fetchColumn();
        $stats['last_reason'] = $reason === false ? null : (string) $reason;

        return $stats;
    }

    /** @return array<int, array<string, int|string|null>> */
    public function targetStats(): array
    {
        $result = [];
        foreach ($this->database->query('SELECT * FROM queue_targets ORDER BY created_at, target_id') as $row) {
            $id = (int) $row['target_id'];
            $result[$id] = $this->stats($id) + ['cutoff' => (int) $row['cutoff'], 'source_id' => (int) $row['source_id']];
        }
        return $result;
    }

    /** @param list<int> $ids */
    private function updateBatch(
        array $ids,
        int $sourceId,
        string $status,
        ?string $reason,
        int $retryAt,
        bool $incrementAttempts = false,
        ?int $targetId = null,
    ): void {
        $ids = array_values(array_unique(array_map(self::messageId(...), $ids)));
        if ($ids === []) {
            return;
        }
        $deliveryMode = $this->hasRegistry();
        if ($deliveryMode && $targetId === null) {
            $targets = $this->database->query('SELECT target_id FROM queue_targets')->fetchAll(PDO::FETCH_COLUMN);
            if (count($targets) !== 1) {
                throw new InvalidArgumentException('A destination is required when multiple targets are registered.');
            }
            $targetId = (int) $targets[0];
        }
        if ($targetId !== null && !$this->hasTarget($targetId)) {
            throw new InvalidArgumentException('Unknown destination.');
        }

        $now = time();
        $this->database->beginTransaction();
        try {
            // Chunk placeholders for SQLite installations with a 999-variable limit.
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $attempts = $incrementAttempts ? 'attempts + 1' : 'attempts';
                $statement = $this->database->prepare(
                    'UPDATE ' . ($deliveryMode ? 'queue_deliveries' : 'queue_messages')
                    . ' SET status = ?, reason = ?, next_attempt = ?, '
                    . 'updated_at = ?, attempts = ' . $attempts
                    . " WHERE source_id = ? AND status = 'pending'"
                    . ($deliveryMode ? ' AND target_id = ?' . ($this->getMeta('delivery_mode') === 'repost' ? " AND delivery_mode='repost'" : '') : '') . ' AND message_id IN (' . $placeholders . ')'
                );
                $statement->bindValue(1, $status, PDO::PARAM_STR);
                $statement->bindValue(2, $reason, $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $statement->bindValue(3, $retryAt, PDO::PARAM_INT);
                $statement->bindValue(4, $now, PDO::PARAM_INT);
                $statement->bindValue(5, $sourceId, PDO::PARAM_INT);
                if ($deliveryMode) { $statement->bindValue(6, $targetId, PDO::PARAM_INT); }
                foreach ($chunk as $index => $id) {
                    $statement->bindValue($index + ($deliveryMode ? 7 : 6), $id, PDO::PARAM_INT);
                }
                $statement->execute();
            }
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    private function hasRegistry(): bool
    {
        return $this->database->query('SELECT 1 FROM queue_targets LIMIT 1')->fetchColumn() !== false;
    }

    private static function channelId(mixed $value): int
    {
        if (is_string($value)) { $value = trim($value); }
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-100[0-9]+$/D', (string) $value)) {
            throw new InvalidArgumentException('Channel IDs must be canonical negative Telegram IDs.');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id >= 0) {
            throw new InvalidArgumentException('Channel ID is outside the supported integer range.');
        }
        return $id;
    }

    private static function messageId(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Message ID must be a positive integer.');
        }

        return $value;
    }
}
