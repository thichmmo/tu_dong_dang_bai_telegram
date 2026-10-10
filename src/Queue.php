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

    /** Complete ACK and final destination IDs commit together under stable random IDs. */
    public function markReposted(array $batch, int $targetId, array $ack): void
    {
        if(count($ack)!==count($batch['ids'])||count(array_unique(array_values($ack)))!==count($batch['ids'])){throw new RuntimeException('Incomplete repost acknowledgement.');}
        $this->database->beginTransaction();
        try{
            $key=hash('sha256',json_encode([$batch['source_id'],$targetId,$batch['ids'],$batch['random_ids']],JSON_THROW_ON_ERROR));
            $this->validateFrozenBatch($batch,$targetId,$key);
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
