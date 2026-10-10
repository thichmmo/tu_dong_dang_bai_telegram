<?php

declare(strict_types=1);

use TelegramMirror\Queue;

require dirname(__DIR__) . '/src/Queue.php';

$assertions = 0;
function check(bool $condition, string $description): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
}

function same(mixed $expected, mixed $actual, string $description): void
{
    check($expected === $actual, $description . '; expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true));
}

function rejects(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        check(true, $description);
        return;
    }
    check(false, $description);
}

$source = -1001234567890;
$queue = new Queue(':memory:');
same(null, $queue->getMeta('cutoff'), 'Missing metadata is null');
$queue->setMeta('cutoff', '123');
$queue->setMeta('cutoff', '456');
same('456', $queue->getMeta('cutoff'), 'Metadata updates persist');
$queue->setMeta('empty', '');
same('', $queue->getMeta('empty'), 'Empty metadata value is distinct from missing');

$queue->enqueue($source, ['id' => 2, 'date' => 100]);
$queue->enqueue($source, ['id' => 1, 'date' => 90]);
$batch = $queue->pendingBatches(time())[0];
same([1], $batch['ids'], 'Oldest message ID is sent first despite arrival order');
same($source, $batch['source_id'], '64-bit source ID is preserved');
check(is_int($batch['random_ids'][0]) && $batch['random_ids'][0] > 0, 'Random ID is a positive PHP integer');
$randomIds = $batch['random_ids'];
$queue->enqueue($source, ['id' => 1, 'date' => 999, 'noforwards' => true]);
same(2, $queue->stats()['pending'], 'Duplicate updates are deduplicated');
same($randomIds, $queue->pendingBatches(time())[0]['random_ids'], 'Duplicate retains its original random ID');
same(90, $queue->pendingBatches(time())[0]['messages'][0]['date'], 'Duplicate does not replace original metadata');

$future = time() + 60;
$queue->retryBatch([1], $source, 'Temporary Telegram error', $future);
same([], $queue->pendingBatches($future - 1), 'A retry delay blocks later posts for that source');
$queue->enqueue($source - 1, ['id' => 50, 'date' => 110]);
$ready = $queue->pendingBatches($future - 1);
same(1, count($ready), 'A blocked source does not block another source');
same($source - 1, $ready[0]['source_id'], 'Independent source can proceed');
$ready = $queue->pendingBatches($future);
$retried = array_values(array_filter($ready, fn (array $item): bool => $item['source_id'] === $source))[0];
same($randomIds, $retried['random_ids'], 'Retry retains stable random IDs');
same(1, $retried['attempts'], 'Retry count is returned');
same(1, $queue->stats()['retries'], 'Retry statistics count waiting records');
$queue->markForwarded([1], $source);
same([2], array_values(array_filter($queue->pendingBatches($future),
    fn (array $item): bool => $item['source_id'] === $source))[0]['ids'], 'Completing oldest unlocks the next post');
$queue->retryBatch([1], $source, 'Stale retry', $future + 1);
$queue->markSkipped([1], $source, 'Stale skip');
same(1, $queue->stats()['forwarded'], 'Stale updates cannot resurrect or change terminal records');
$queue->enqueue($source, ['id' => 1, 'date' => 90]);
same(3, $queue->stats()['total'], 'Terminal records continue deduplicating repeats');

$albumQueue = new Queue(':memory:');
$albumQueue->enqueue($source, ['id' => 12, 'date' => 200]);
$albumQueue->enqueue($source, ['id' => 11, 'date' => 199, 'grouped_id' => '9223372036854775800']);
$albumQueue->enqueue($source, ['id' => 10, 'date' => 199, 'grouped_id' => '9223372036854775800']);
same([], $albumQueue->pendingBatches(time()), 'Unsettled oldest album blocks newer single messages');
$album = $albumQueue->pendingBatches(time() + 10)[0];
same([10, 11], $album['ids'], 'All album parts are returned together in ascending order');
same('9223372036854775800', $album['messages'][0]['grouped_id'], 'Album IDs preserve full precision');
same(2, count($album['random_ids']), 'Album has one persisted random ID per item');
$albumQueue->retryBatch($album['ids'], $source, 'Album retry', $future);
same([], $albumQueue->pendingBatches($future - 1), 'Album retry blocks newer posts');
same($album['random_ids'], $albumQueue->pendingBatches($future)[0]['random_ids'], 'Album random IDs remain stable');
$albumQueue->markForwarded($album['ids'], $source);
same(2, $albumQueue->stats()['forwarded'], 'Album completion updates all members');
same([12], $albumQueue->pendingBatches($future)[0]['ids'], 'The single following the album becomes eligible');
$albumQueue->markSkipped([12], $source, 'Protected content');
same([], $albumQueue->pendingBatches($future), 'Skipped messages leave the pending queue');
same('Protected content', $albumQueue->stats()['last_reason'], 'Skip reason is available in stats');
same(1, $albumQueue->stats()['skipped'], 'Skip statistics count skipped records');

$protectedQueue = new Queue(':memory:');
$protectedQueue->enqueue($source, ['id' => 1, 'date' => 100, 'grouped_id' => 123, 'noforwards' => true]);
$protectedQueue->enqueue($source, ['id' => 2, 'date' => 100, 'grouped_id' => 123]);
$protectedBatch = $protectedQueue->pendingBatches(time() + 10)[0];
same(true, $protectedBatch['protected'], 'One protected album part flags the whole batch');
same(true, $protectedBatch['messages'][0]['noforwards'], 'Original protection state is preserved');

$independentQueue = new Queue(':memory:');
for ($i = 0; $i < 25; ++$i) {
    $independentQueue->enqueue($source - $i, ['id' => 1, 'date' => 100]);
    if ($i < 22) {
        $independentQueue->retryBatch([1], $source - $i, 'Wait', $future);
    }
}
same(3, count($independentQueue->pendingBatches($future - 1)), 'Blocked sources do not consume the eligible batch limit');
same(20, count($independentQueue->pendingBatches($future)), 'Eligible batches have a bounded limit');

$isolatedQueue = new Queue(':memory:');
$isolatedQueue->enqueue($source, ['id' => 1, 'date' => 100]);
$isolatedQueue->enqueue($source - 1, ['id' => 1, 'date' => 100]);
$isolatedQueue->markSkipped([1], $source, 'Only this source');
same(1, $isolatedQueue->stats()['pending'], 'Identical message IDs from different sources stay independent');
same($source - 1, $isolatedQueue->pendingBatches(time())[0]['source_id'], 'Batch status changes are limited to their source');

$largeQueue = new Queue(':memory:');
$largeIds = range(1, 1100);
foreach ($largeIds as $id) {
    $largeQueue->enqueue($source, ['id' => $id, 'date' => 100]);
}
$largeQueue->markForwarded($largeIds, $source);
same(1100, $largeQueue->stats()['forwarded'], 'Large atomic batch updates respect SQLite parameter limits');
same([], $largeQueue->pendingBatches(time()), 'Large completed batch has no remaining pending messages');

$path = tempnam(sys_get_temp_dir(), 'telegram-queue-');
if ($path === false) {
    throw new RuntimeException('Cannot create temporary queue database.');
}
try {
    $diskQueue = new Queue($path);
    $diskQueue->enqueue($source, ['id' => 7, 'date' => 100]);
    $diskRandomIds = $diskQueue->pendingBatches(time())[0]['random_ids'];
    $diskQueue->retryBatch([7], $source, 'Restart retry', $future);
    $diskQueue->setMeta('cutoff', '123456');
    unset($diskQueue);
    $diskQueue = new Queue($path);
    same($diskRandomIds, $diskQueue->pendingBatches($future)[0]['random_ids'], 'Random IDs survive process restart');
    same(1, $diskQueue->pendingBatches($future)[0]['attempts'], 'Attempts survive process restart');
    same([], $diskQueue->pendingBatches($future - 1), 'Retry schedule survives process restart');
    same('123456', $diskQueue->getMeta('cutoff'), 'Metadata survives process restart');
    $diskQueue->markForwarded([7], $source);
    unset($diskQueue);
    $diskQueue = new Queue($path);
    $diskQueue->enqueue($source, ['id' => 7, 'date' => 100]);
    same(1, $diskQueue->stats()['forwarded'], 'Terminal dedupe survives process restart');
    same([], $diskQueue->pendingBatches($future), 'Forwarded messages remain terminal after restart');
    unset($diskQueue);
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

$targetA = -1002234567890;
$targetB = -1003234567890;
$targetC = -1004234567890;
$multi = new Queue(':memory:');
$multi->setMeta('source_id', (string) $source);
$multi->setMeta('target_id', (string) $targetA);
$multi->setMeta('source_min_id', '10');
foreach ([11, 12, 13, 14] as $id) { $multi->enqueue($source, ['id' => $id, 'date' => 100]); }
$legacyRandom = $multi->pendingBatches(time())[0]['random_ids'];
$multi->markForwarded([12], $source);
$multi->markSkipped([13], $source, 'Legacy protected message');
$multi->retryBatch([14], $source, 'Legacy network retry', $future);
$legacyStats = $multi->stats();
$multi->registerTargets($source, [$targetA, (string) $targetB], 14);
same(true, $multi->hasTarget($targetA), 'Original target is registered during migration');
same(true, $multi->hasTarget($targetB), 'An additional normalized target is registered');
same(false, $multi->hasTarget($targetC), 'Unknown target lookup is false');
same($legacyStats, $multi->stats($targetA), 'Migration preserves every original delivery status and retry');
same($legacyRandom, $multi->pendingBatches(time(), 5, $targetA)[0]['random_ids'], 'Migration preserves the original pending random ID');
same(0, $multi->stats($targetB)['total'], 'A newly activated target receives no existing backlog');
same(10, $multi->targetStats()[$targetA]['cutoff'], 'Original target retains its historical baseline');
same(14, $multi->targetStats()[$targetB]['cutoff'], 'Added target starts at activation cutoff');
$multi->registerTargets($source, [$targetA, $targetB], 999);
same(10, $multi->targetStats()[$targetA]['cutoff'], 'Repeated activation cannot rebase the original target');
same(14, $multi->targetStats()[$targetB]['cutoff'], 'Repeated activation cannot rebase an added target');
$multi->enqueue($source, ['id' => 11, 'date' => 100]);
$multi->enqueue($source, ['id' => 14, 'date' => 100]);
same(0, $multi->stats($targetB)['total'], 'Duplicate legacy events cannot backfill the added target');
same($legacyStats, $multi->stats($targetA), 'Repeated registration and duplicate events cannot resurrect terminal rows');
$multi->markForwarded([11], $source, $targetA);
same([], $multi->pendingBatches($future - 1, 5, $targetA), 'The migrated retry still blocks newer original-target posts');
$retried = $multi->pendingBatches($future, 5, $targetA)[0];
same([14], $retried['ids'], 'Migrated retry becomes eligible at its persisted deadline');
same(1, $retried['attempts'], 'Migration preserves original retry attempts');
$multi->markForwarded([14], $source, $targetA);

$multi->enqueue($source, ['id' => 15, 'date' => 200, 'grouped_id' => 777]);
$multi->enqueue($source, ['id' => 16, 'date' => 200, 'grouped_id' => 777]);
$multi->enqueue($source, ['id' => 17, 'date' => 201]);
$albumA = $multi->pendingBatches(time() + 10, 5, $targetA)[0];
$albumB = $multi->pendingBatches(time() + 10, 5, $targetB)[0];
same($targetA, $albumA['target_id'], 'Batches identify their delivery destination');
same([15, 16], $albumA['ids'], 'Original destination receives the complete new album');
same([15, 16], $albumB['ids'], 'Added destination receives the complete new album');
check($albumA['random_ids'][0] !== $albumB['random_ids'][0]
    && $albumA['random_ids'][1] !== $albumB['random_ids'][1], 'Each destination has distinct persisted random IDs per album member');
$multi->retryBatch($albumB['ids'], $source, 'Target B network retry', $future, $targetB);
same([], $multi->pendingBatches($future - 1, 5, $targetB), 'B retry blocks its following post');
same($albumA['random_ids'], $multi->pendingBatches(time() + 10, 5, $targetA)[0]['random_ids'], 'B retry does not block or alter A');
same($albumB['random_ids'], $multi->pendingBatches($future, 5, $targetB)[0]['random_ids'], 'B retry reuses its own stable random IDs');
same(0, $multi->stats($targetA)['retries'], 'B retry does not increment A attempts');
same(2, $multi->stats($targetB)['retries'], 'B statistics count both retried album members');
$multi->markForwarded($albumA['ids'], $source, $targetA);
same([17], $multi->pendingBatches(time() + 10, 5, $targetA)[0]['ids'], 'A independently proceeds past its acknowledged album');
$multi->markSkipped($albumB['ids'], $source, 'B protected rejection', $targetB);
same([17], $multi->pendingBatches(time() + 10, 5, $targetB)[0]['ids'], 'B skip independently releases its next post');
$multi->retryBatch($albumA['ids'], $source, 'Stale A retry', $future, $targetA);
$multi->markSkipped($albumA['ids'], $source, 'Stale A skip', $targetA);
same(5, $multi->stats($targetA)['forwarded'], 'Targeted stale callbacks cannot resurrect or overwrite A terminal rows');
same(2, $multi->stats($targetB)['skipped'], 'A completion cannot change B skipped state');
same(10, $multi->stats()['total'], 'Aggregate statistics count independent deliveries');
same(2, $multi->stats()['pending'], 'Aggregate pending count includes each waiting destination');
same($source, $multi->targetStats()[$targetB]['source_id'], 'Target statistics retain source binding');
rejects(fn () => $multi->markForwarded([17], $source), 'Ambiguous multi-target writes require an explicit destination');
same(2, $multi->stats()['pending'], 'An ambiguous write changes neither target');
rejects(fn () => $multi->registerTargets($source - 1, [$targetA, $targetB], 100), 'The registered source cannot be remapped');
rejects(fn () => $multi->registerTargets($source, [$targetB], 100), 'The original destination cannot be removed');
rejects(fn () => $multi->registerTargets($source, [$targetA], 100), 'An added destination cannot be silently removed');
rejects(fn () => $multi->registerTargets($source, [$targetA, (string) $targetA, $targetB], 100), 'Duplicate normalized target IDs are rejected');
rejects(fn () => $multi->registerTargets($source, [$targetA, $targetB, $source], 100), 'A destination cannot equal its source');
rejects(fn () => $multi->registerTargets($source, [], 100), 'An empty target registry is rejected');
rejects(fn () => $multi->registerTargets($source, [$targetA, $targetB, 123], 100), 'Noncanonical target IDs are rejected');
rejects(fn () => $multi->enqueue($source - 1, ['id' => 100, 'date' => 100]), 'A different source cannot enter an activated registry');
rejects(fn () => $multi->pendingBatches(time(), 5, $targetC), 'Unknown destination reads are rejected');
rejects(fn () => $multi->markSkipped([17], $source, 'Unknown', $targetC), 'Unknown destination writes are rejected');
rejects(fn () => $multi->stats($targetC), 'Unknown destination statistics are rejected');
$multi->setMeta('target_id', (string) $targetC);
rejects(fn () => $multi->registerTargets($source, [$targetA, $targetB, $targetC], 100),
    'Changing original destination metadata cannot remap its durable binding');
same(false, $multi->hasTarget($targetC), 'Rejected legacy remapping does not partially activate another target');
$multi->setMeta('target_id', (string) $targetA);

$badMigration = new Queue(':memory:');
$badMigration->setMeta('source_id', (string) $source);
$badMigration->setMeta('target_id', (string) $targetA);
$badMigration->setMeta('source_min_id', 'invalid');
$badMigration->enqueue($source, ['id' => 1, 'date' => 100]);
rejects(fn () => $badMigration->registerTargets($source, [$targetB, $targetA], 10), 'Invalid legacy cutoff aborts migration');
same(false, $badMigration->hasTarget($targetB), 'Failed migration rolls back a target inserted earlier in its transaction');
same(1, $badMigration->stats()['pending'], 'Failed migration retains the complete legacy queue');
$foreignLegacy = new Queue(':memory:');
$foreignLegacy->enqueue($source - 1, ['id' => 1, 'date' => 100]);
rejects(fn () => $foreignLegacy->registerTargets($source, [$targetA], 10), 'Mixed legacy sources cannot be accidentally rebound');
same(false, $foreignLegacy->hasTarget($targetA), 'Rejected source migration leaves the registry empty');

$preexisting = new Queue(':memory:');
$preexisting->setMeta('source_id', (string) $source);
$preexisting->setMeta('target_id', (string) $targetA);
$preexisting->setMeta('source_min_id', '0');
$preexisting->enqueue($source, ['id' => 101, 'date' => 100]);
$preexisting->registerTargets($source, [$targetA, $targetB], 100);
same(0, $preexisting->stats($targetB)['total'], 'Existing backlog above a conservative activation cutoff is still not backfilled');
$preexisting->enqueue($source, ['id' => 101, 'date' => 100]);
same(0, $preexisting->stats($targetB)['total'], 'A duplicate existing ID above the added target cutoff cannot create a delivery');
$preexisting->enqueue($source, ['id' => 102, 'date' => 101]);
same([102], $preexisting->pendingBatches(time(), 0, $targetB)[0]['ids'], 'Only a fresh insertion above activation cutoff enters the added target');

$path = tempnam(sys_get_temp_dir(), 'telegram-delivery-');
if ($path === false) { throw new RuntimeException('Cannot create durable delivery fixture.'); }
try {
    $durable = new Queue($path);
    $durable->registerTargets($source, [$targetA, $targetB], 20);
    $durable->enqueue($source, ['id' => 20, 'date' => 100]);
    same(0, $durable->stats()['total'], 'Activation cutoff excludes earlier updates for every new target');
    $durable->enqueue($source, ['id' => 21, 'date' => 100, 'grouped_id' => 888]);
    $durable->enqueue($source, ['id' => 22, 'date' => 100, 'grouped_id' => 888]);
    $snapshotA = $durable->pendingBatches(time() + 10, 5, $targetA)[0];
    $snapshotB = $durable->pendingBatches(time() + 10, 5, $targetB)[0];
    // This part arrives while Telegram is processing the already held batch.
    $durable->enqueue($source, ['id' => 23, 'date' => 100, 'grouped_id' => 888]);
    $durable->markForwarded($snapshotA['ids'], $source, $targetA);
    $lateA = $durable->pendingBatches(time() + 10, 5, $targetA)[0];
    same([23], $lateA['ids'], 'Snapshot acknowledgement leaves the late album member pending for A');
    same(2, $durable->stats($targetA)['forwarded'], 'Snapshot commit only marks the members that were actually sent');
    $wholeB = $durable->pendingBatches(time() + 10, 5, $targetB)[0];
    same([21, 22, 23], $wholeB['ids'], 'Unsent B album includes the late member together');
    same($snapshotB['random_ids'], array_slice($wholeB['random_ids'], 0, 2), 'Late arrival preserves existing B random IDs');
    check($lateA['random_ids'][0] !== $wholeB['random_ids'][2], 'The late member has a distinct random ID per target');
    $durable->retryBatch([23], $source, 'A late-member retry', $future, $targetA);
    $durable->markSkipped($snapshotB['ids'], $source, 'B snapshot skipped', $targetB);
    $lateB = $durable->pendingBatches(time() + 10, 5, $targetB)[0];
    same([23], $lateB['ids'], 'Snapshot skip cannot discard a later B album member');
    unset($durable);
    $durable = new Queue($path);
    same([], $durable->pendingBatches($future - 1, 5, $targetA), 'A late-member backoff survives a database reopen');
    same($lateA['random_ids'], $durable->pendingBatches($future, 5, $targetA)[0]['random_ids'], 'A late random ID survives restart');
    same($lateB['random_ids'], $durable->pendingBatches(time() + 10, 5, $targetB)[0]['random_ids'], 'B independent late random ID survives restart');
    same(1, $durable->pendingBatches($future, 5, $targetA)[0]['attempts'], 'Target-specific retry attempts survive restart');
    same(2, $durable->stats($targetA)['forwarded'], 'A terminal snapshot rows survive restart');
    same(2, $durable->stats($targetB)['skipped'], 'B terminal snapshot rows survive restart');
    $durable->enqueue($source, ['id' => 21, 'date' => 100, 'grouped_id' => 888]);
    same(2, $durable->stats($targetA)['forwarded'], 'Duplicate album events cannot resurrect A after restart');
    same(2, $durable->stats($targetB)['skipped'], 'Duplicate album events cannot resurrect B after restart');
    $durable->registerTargets($source, [$targetA, $targetB, $targetC], 23);
    same(20, $durable->targetStats()[$targetA]['cutoff'], 'Adding C preserves A immutable activation cutoff');
    same(20, $durable->targetStats()[$targetB]['cutoff'], 'Adding C preserves B immutable activation cutoff');
    same(23, $durable->targetStats()[$targetC]['cutoff'], 'C starts from its own later activation cutoff');
    same(0, $durable->stats($targetC)['total'], 'C receives no forwarded, skipped or pending earlier backlog');
    $durable->enqueue($source, ['id' => 23, 'date' => 100, 'grouped_id' => 888]);
    same(0, $durable->stats($targetC)['total'], 'A repeated pending update cannot backfill C');
    $durable->enqueue($source, ['id' => 24, 'date' => 101]);
    same([24], $durable->pendingBatches(time(), 0, $targetC)[0]['ids'], 'C receives the first genuinely new post after activation');
    same([], $durable->pendingBatches($future - 1, 5, $targetA), 'A backoff does not prevent C from receiving new posts');
    unset($durable);
    $durable = new Queue($path);
    same([24], $durable->pendingBatches(time(), 0, $targetC)[0]['ids'], 'Added target delivery survives another restart');
    same(23, $durable->targetStats()[$targetC]['cutoff'], 'Added target cutoff is durable');
    unset($durable);
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) { unlink($file); }
    }
}

$path = tempnam(sys_get_temp_dir(), 'telegram-migration-');
if ($path === false) { throw new RuntimeException('Cannot create legacy migration fixture.'); }
try {
    $migration = new Queue($path);
    $migration->setMeta('source_id', (string) $source);
    $migration->setMeta('target_id', (string) $targetA);
    $migration->setMeta('source_min_id', '5');
    $migration->enqueue($source, ['id' => 9, 'date' => 100]);
    $oldRetryRandom = $migration->pendingBatches(time())[0]['random_ids'];
    $migration->retryBatch([9], $source, 'Legacy durable retry', $future);
    foreach ([6, 7, 8] as $id) { $migration->enqueue($source, ['id' => $id, 'date' => 100]); }
    $oldPendingRandom = $migration->pendingBatches(time())[0]['random_ids'];
    $migration->markForwarded([7], $source);
    $migration->markSkipped([8], $source, 'Legacy durable skip');
    $beforeMigration = $migration->stats();
    unset($migration);
    $migration = new Queue($path);
    $migration->registerTargets($source, [$targetA, $targetB], 9);
    unset($migration);
    $migration = new Queue($path);
    same($beforeMigration, $migration->stats($targetA), 'Every legacy delivery state survives migration and restart');
    same($oldPendingRandom, $migration->pendingBatches(time(), 5, $targetA)[0]['random_ids'],
        'Legacy pending random IDs survive the migration transaction and restart');
    same('5', $migration->getMeta('source_min_id'), 'Migration leaves the original global cutoff metadata unchanged');
    same((string) $targetA, $migration->getMeta('target_id'), 'Migration leaves the original target metadata unchanged');
    same(0, $migration->stats($targetB)['total'], 'No legacy backlog appears at B after restart');
    $migration->markForwarded([6], $source, $targetA);
    same([], $migration->pendingBatches($future - 1, 5, $targetA), 'Migrated durable retry deadline remains enforced');
    $retry = $migration->pendingBatches($future, 5, $targetA)[0];
    same($oldRetryRandom, $retry['random_ids'], 'Legacy retried random IDs survive migration and restart');
    same(1, $retry['attempts'], 'Legacy retried attempt count survives migration and restart');
    $migration->registerTargets($source, [$targetA, $targetB], 999);
    same(5, $migration->targetStats()[$targetA]['cutoff'], 'Restart registration preserves original legacy activation cutoff');
    same(9, $migration->targetStats()[$targetB]['cutoff'], 'Restart registration preserves additional target activation cutoff');
    $migration->enqueue($source, ['id' => 7, 'date' => 100]);
    same(2, $migration->stats($targetA)['forwarded'], 'Legacy terminal rows still deduplicate after migration restart');
    same(0, $migration->stats($targetB)['total'], 'A duplicate legacy terminal row cannot become a new B delivery');
    unset($migration);
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) { unlink($file); }
    }
}

echo 'Queue: ' . $assertions . " assertions passed.\n";
