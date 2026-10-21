<?php

declare(strict_types=1);

namespace TelegramMirror;

use RuntimeException;

/** Bounded read-only history scan. Its caller commits only a complete result. */
final class SourceHistory
{
    /** @return array{messages:list<array<string,mixed>>,head:int,pages:int} */
    public static function collect(object $api, int $sourceId, int $cursor, int $pageSize = 100, int $maxPages = 10): array
    {
        if ($sourceId >= -1000000000000 || $cursor < 0 || $pageSize < 1 || $pageSize > 100
            || $maxPages < 1 || $maxPages > 50) {
            throw new RuntimeException('HISTORY_SCAN_OPTIONS_INVALID');
        }
        $offset = 0;
        $head = $cursor;
        $messages = [];
        $deadline = microtime(true) + 55;
        for ($page = 1; $page <= $maxPages; ++$page) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) { throw new RuntimeException('HISTORY_SCAN_TIMEOUT'); }
            $result = $api->messages->getHistory(peer: $sourceId, offset_id: $offset,
                offset_date: 0, add_offset: 0, limit: $pageSize, max_id: 0, min_id: $cursor, hash: 0,
                timeout: min(20.0, $remaining), floodWaitLimit: 0);
            if (microtime(true) > $deadline) { throw new RuntimeException('HISTORY_SCAN_TIMEOUT'); }
            if (!is_array($result) || !is_array($result['messages'] ?? null)
                || !array_is_list($result['messages']) || count($result['messages']) > $pageSize) {
                throw new RuntimeException('HISTORY_RESPONSE_INVALID');
            }
            $rows = $result['messages'];
            $previous = $offset === 0 ? PHP_INT_MAX : $offset;
            foreach ($rows as $message) {
                if (!is_array($message) || !is_int($message['id'] ?? null)
                    || $message['id'] <= $cursor || $message['id'] >= $previous) {
                    throw new RuntimeException('HISTORY_PAGINATION_INVALID');
                }
                $id = $message['id'];
                $previous = $id;
                $head = max($head, $id);
                $kind = $message['_'] ?? '';
                if (!in_array($kind, ['message', 'messageService', 'messageEmpty'], true)) {
                    throw new RuntimeException('HISTORY_MESSAGE_INVALID');
                }
                $peer = $message['peer_id'] ?? null;
                if ($kind !== 'messageEmpty' || $peer !== null) {
                    $peerId = null;
                    if (is_int($peer) && $peer < -1000000000000) { $peerId = $peer; }
                    elseif (is_array($peer) && ($peer['_'] ?? '') === 'peerChannel' && is_int($peer['channel_id'] ?? null)) {
                        $channel = $peer['channel_id'];
                        if ($channel < -1000000000000) { $peerId = $channel; }
                        elseif ($channel > 0 && $channel <= PHP_INT_MAX - 1000000000000) { $peerId = -1000000000000 - $channel; }
                    }
                    if ($peerId !== $sourceId) {
                        throw new RuntimeException('HISTORY_SOURCE_MISMATCH');
                    }
                }
                if ($kind === 'message') {
                    if (!is_int($message['date'] ?? null) || $message['date'] < 0
                        || isset($message['grouped_id']) && !is_int($message['grouped_id']) && !is_string($message['grouped_id'])) {
                        throw new RuntimeException('HISTORY_MESSAGE_INVALID');
                    }
                    $messages[$id] = $message;
                }
            }
            if (count($rows) < $pageSize) {
                ksort($messages, SORT_NUMERIC);
                return ['messages' => array_values($messages), 'head' => $head, 'pages' => $page];
            }
            $offset = $previous;
        }
        // A capped scan has no proven lower boundary. Publishing its newest
        // page could reverse post order or split an album at the page edge.
        throw new RuntimeException('HISTORY_SCAN_LIMIT_REACHED');
    }
}
