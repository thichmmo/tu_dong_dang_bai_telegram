<?php
declare(strict_types=1);

namespace TelegramMirror\Audit;

use PDO;
use RuntimeException;
use Throwable;

/** A failed audit is inconclusive; it must never try to repair the running app. */
final class AuditFailure extends RuntimeException {}

final class ReadClient
{
    private mixed $socket = null;
    private bool $attempted = false;
    private bool $closed = false;
    private int $index = 0;
    private int $attempts = 0;
    private string $identity;
    private float $deadline;

    public function __construct(private mixed $connect, private mixed $exchange,
        private mixed $guard, private mixed $clock = null)
    {
        $this->clock ??= static fn(): float => microtime(true);
        $this->deadline = ($this->clock)() + 105;
        $this->identity = ($this->guard)();
    }

    public function call(string $method, array $arguments): array
    {
        try {
            if (!in_array($method, ['channels.getMessages', 'messages.getHistory'], true)) {
                throw new AuditFailure('RPC_NOT_ALLOWED');
            }
            if ($this->closed || $this->attempts >= 30) { throw new AuditFailure('RPC_CLOSED_OR_LIMIT'); }
            $this->check();
            if (!$this->attempted) {
                $this->attempted = true;
                $this->socket = ($this->connect)();
            }
            $this->check();
            $remaining = min(30.0, $this->deadline - ($this->clock)());
            if ($remaining <= 0) { throw new AuditFailure('RPC_DEADLINE'); }
            ++$this->attempts;
            $reply = ($this->exchange)($this->socket,
                ['methodCallAsyncRead', [$method, $arguments, null]], $remaining);
            if (!is_array($reply) || !array_is_list($reply) || count($reply) !== 2
                || !is_int($reply[0]) || $reply[0] !== $this->index || !is_array($reply[1])) {
                throw new AuditFailure('IPC_RESPONSE_INVALID');
            }
            ++$this->index;
            $this->check();
            return $reply[1];
        } catch (Throwable $error) {
            $this->close();
            throw $error instanceof AuditFailure ? $error : new AuditFailure('IPC_READ_FAILED');
        }
    }

    private function check(): void
    {
        if (($this->clock)() >= $this->deadline) { throw new AuditFailure('RPC_DEADLINE'); }
        if (($this->guard)() !== $this->identity) { throw new AuditFailure('WORKER_IDENTITY_CHANGED'); }
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        if (is_object($this->socket)) {
            try { $this->socket->disconnect(); } catch (Throwable) {}
        }
        $this->socket = null;
    }

    public function requests(): int { return $this->attempts; }
    public function responses(): int { return $this->index; }
}

/** No App/Queue/bootstrap/session deserialization. Only passive native guards and PDO SELECTs. */
final class NativeState
{
    public function __construct(public readonly string $root) {}

    private function privatePath(string $path, int $type): array
    {
        clearstatcache(true, $path);
        $s = @lstat($path);
        $uid = @fileowner('/proc/' . getmypid());
        if ($s === false || $uid === false || $s['uid'] !== $uid
            || ($s['mode'] & 0170000) !== $type || ($s['mode'] & 0077) !== 0 || is_link($path)) {
            throw new AuditFailure('PRIVATE_PATH_REQUIRED');
        }
        return ['dev' => $s['dev'], 'ino' => $s['ino'], 'uid' => $s['uid'], 'mode' => $s['mode']];
    }

    public function prerequisites(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE !== 8
            || preg_match('~(?:^|/)(?:public_html|htdocs)(?:/|$)~i', $this->root)) {
            throw new AuditFailure('PRIVATE_NATIVE_LINUX_REQUIRED');
        }
        foreach ([$this->root, $this->root . '/storage', $this->root . '/storage/deploy'] as $p) {
            $this->privatePath($p, 0040000);
        }
    }

    public function guard(int $expectedPid): string
    {
        $this->prerequisites();
        $store = $this->root . '/storage';
        $this->privatePath($store . '/telegram.session', 0040000);
        $ipc = $store . '/telegram.session/ipc';
        $socketStat = $this->privatePath($ipc, 0140000);
        $path = $store . '/deploy/live-status.json';
        $this->privatePath($path, 0100000);
        $raw = @file_get_contents($path, false, null, 0, 1048577);
        if ($raw === false || strlen($raw) > 1048576) { throw new AuditFailure('LIVE_STATUS_UNAVAILABLE'); }
        $live = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $now = time();
        $meta = $live['metadata'] ?? [];
        if (($live['worker_alive'] ?? false) !== true || ($live['all_targets_ready'] ?? false) !== true
            || ($live['generated_at'] ?? 0) > $now || $now - ($live['generated_at'] ?? 0) >= 30
            || (string) ($meta['worker_pid'] ?? '') !== (string) $expectedPid
            || ($meta['worker_ready'] ?? '') !== '1'
            || (int) ($meta['worker_heartbeat_at'] ?? 0) > $now
            || $now - (int) ($meta['worker_heartbeat_at'] ?? 0) >= 30) {
            throw new AuditFailure('WORKER_NOT_READY');
        }
        $proc = '/proc/' . $expectedPid;
        $uid = @fileowner('/proc/' . getmypid());
        clearstatcache(true, $proc);
        if ($uid === false || @fileowner($proc) !== $uid) { throw new AuditFailure('WORKER_UID_MISMATCH'); }
        $argv = @file_get_contents($proc . '/cmdline');
        $stat = @file_get_contents($proc . '/stat');
        $fds = @scandir($proc . '/fd');
        $unix = @file_get_contents('/proc/net/unix');
        if ($argv === false || $stat === false || $fds === false || $unix === false
            || !in_array($this->root . '/bin/worker.php', explode("\0", $argv), true)) {
            throw new AuditFailure('WORKER_INSPECTION_FAILED');
        }
        $end = strrpos($stat, ')');
        $fields = $end === false ? [] : preg_split('/\s+/', trim(substr($stat, $end + 1)));
        $start = $fields[19] ?? null;
        $lock = false; $sockets = []; $inodes = [];
        foreach ($fds as $fd) {
            if (!ctype_digit($fd)) { continue; }
            $link = @readlink($proc . '/fd/' . $fd);
            if ($link === $store . '/process.lock') { $lock = true; }
            if (is_string($link) && preg_match('/^socket:\[([0-9]+)\]$/D', $link, $m)) { $sockets[$m[1]] = true; }
        }
        foreach (explode("\n", $unix) as $line) {
            $parts = preg_split('/\s+/', trim($line), 8);
            if (count($parts) === 8 && $parts[3] === '00010000' && $parts[4] === '0001'
                && $parts[7] === $ipc && isset($sockets[$parts[6]])) { $inodes[] = $parts[6]; }
        }
        sort($inodes, SORT_STRING);
        if (!$lock || $inodes === [] || !is_string($start) || !ctype_digit($start)) {
            throw new AuditFailure('WORKER_SOCKET_OR_LOCK_UNPROVED');
        }
        return hash('sha256', json_encode([$expectedPid, $uid, $start, $socketStat, $inodes], JSON_THROW_ON_ERROR));
    }

    public function snapshot(): array
    {
        $this->prerequisites();
        $file = $this->root . '/storage/queue.sqlite';
        foreach ([$file, $file . '-wal', $file . '-shm'] as $p) { $this->privatePath($p, 0100000); }
        if (!defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || !defined('PDO::SQLITE_OPEN_READONLY')) {
            throw new AuditFailure('READONLY_PDO_REQUIRED');
        }
        $uri = implode('/', array_map('rawurlencode', explode('/', $file)));
        $dsn = ini_get('open_basedir') ? 'sqlite:' . $file : 'sqlite:file:' . $uri . '?mode=ro';
        $db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 0,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        try {
            $db->exec('PRAGMA query_only=ON');
            $changesBefore = (int) $db->query('SELECT total_changes()')->fetchColumn();
            $db->beginTransaction();
            $select = static function (string $sql) use ($db): array {
                $s = $db->prepare($sql);
                if (!$s->getAttribute(PDO::SQLITE_ATTR_READONLY_STATEMENT)) { throw new AuditFailure('READONLY_STATEMENT_REQUIRED'); }
                $s->execute(); return $s->fetchAll();
            };
            $metadata = $select("SELECT key,value FROM queue_metadata WHERE key IN ('source_id','source_min_id','worker_pid','initialized_at')");
            $meta = array_column($metadata, 'value', 'key');
            foreach (['source_id','source_min_id','worker_pid','initialized_at'] as $k) {
                if (!isset($meta[$k]) || !preg_match('/^-?[0-9]{1,20}$/D', $meta[$k])
                    || filter_var($meta[$k], FILTER_VALIDATE_INT) === false) { throw new AuditFailure('METADATA_INVALID'); }
                $meta[$k] = (int) $meta[$k];
            }
            $targets = $select('SELECT source_id,target_id,cutoff,created_at FROM queue_targets ORDER BY target_id LIMIT 11');
            if (count($targets) !== 2) { throw new AuditFailure('EXPECTED_TWO_TARGETS'); }
            foreach ($targets as &$t) {
                foreach ($t as &$v) { $v = (int) $v; } unset($v);
                if ($t['source_id'] !== $meta['source_id'] || $t['target_id'] === $meta['source_id']) { throw new AuditFailure('QUEUE_BINDING_INVALID'); }
            } unset($t);
            $rows = $select('SELECT m.source_id,m.message_id,m.message_date,m.grouped_id,m.protected,m.first_seen,
                d.target_id,d.random_id,d.status,d.attempts,d.updated_at
                FROM queue_messages m LEFT JOIN queue_deliveries d ON d.source_id=m.source_id AND d.message_id=m.message_id
                WHERE m.message_id > ' . $meta['source_min_id'] . ' ORDER BY m.message_id,m.source_id,d.target_id LIMIT 1001');
            if (count($rows) > 1000) { throw new AuditFailure('LEDGER_LIMIT'); }
            foreach ($rows as &$r) {
                if ((int) $r['source_id'] !== $meta['source_id']) { throw new AuditFailure('SOURCE_BINDING_INVALID'); }
                $r['random_id_sha256'] = hash('sha256', 'TelegramMirror/random_id/v1:' . (string) $r['random_id']);
                unset($r['random_id']);
                foreach (['source_id','message_id','message_date','protected','first_seen','target_id','attempts','updated_at'] as $k) {
                    if ($r[$k] !== null) { $r[$k] = (int) $r[$k]; }
                }
                if ($r['grouped_id'] !== null && !preg_match('/^(?:0|-?[1-9][0-9]*)$/D', (string) $r['grouped_id'])) {
                    throw new AuditFailure('GROUPED_ID_INVALID');
                }
            } unset($r);
            $queryOnly = (int) $db->query('PRAGMA query_only')->fetchColumn();
            $changesAfter = (int) $db->query('SELECT total_changes()')->fetchColumn();
            if ($queryOnly !== 1 || $changesBefore !== 0 || $changesAfter !== 0) { throw new AuditFailure('AUDIT_DML_DETECTED'); }
            return ['metadata'=>$meta,'targets'=>$targets,'rows'=>$rows,'query_only'=>1,
                'own_changes_before'=>0,'own_changes_after'=>0,
                'ledger_sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
        } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    }
}

/** Compare in RAM; callers receive IDs, counts, HMACs and booleans only. */
final class Verifier
{
    public function __construct(private mixed $rpc, private string $key, private int $pages = 10) {}

    public static function seal(array $result, bool $stateUnchanged): array
    {
        if ($stateUnchanged) { return $result; }
        $result['reason']='AUDIT_STATE_CHANGED';
        $result['verified_source_ids']=[];
        $result['current_verified_source_ids']=[];
        foreach (['all_audited_messages_verified','live_natural_forward_verified','live_album_verified',
            'all_audited_messages_current_verified','live_current_snapshot_verified','live_current_album_verified'] as $flag) {
            $result[$flag]=false;
        }
        foreach (['messages','albums'] as $kind) {
            foreach ($result[$kind] as &$item) {
                $item['verified']=false;
                $item['current_verified']=false;
                foreach ($item['targets'] as &$target) { $target['verified']=false;$target['current_verified']=false; } unset($target);
            } unset($item);
        }
        return $result;
    }

    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as &$v) { $v = self::canonical($v); } unset($v);
        return $value;
    }

    private function hmac(mixed $value): string
    {
        return hash_hmac('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->key);
    }

    public static function peer(mixed $peer): ?int
    {
        // Pinned MadelineProto normalizes native peerChannel values to Bot API ints.
        if (is_int($peer)) { return $peer < -1000000000000 ? $peer : null; }
        if (!is_array($peer) || ($peer['_'] ?? '') !== 'peerChannel'
            || !is_int($peer['channel_id'] ?? null)) { return null; }
        $id = $peer['channel_id'];
        if ($id < -1000000000000) { return $id; }
        return $id > 0 && $id <= PHP_INT_MAX - 1000000000000 ? -1000000000000 - $id : null;
    }

    private static function group(mixed $group): ?string
    {
        if ($group===null || $group===0 || $group==='0' || $group==='') { return null; }
        if ((!is_int($group) && !is_string($group)) || !preg_match('/^-?[1-9][0-9]*$/D',(string)$group)) {
            throw new AuditFailure('GROUPED_ID_INVALID');
        }
        return (string)$group;
    }

    private static function clean(array $value): array
    {
        foreach ($value as $k => &$v) {
            if (in_array($k, ['access_hash','file_reference','thumbs','video_thumbs','stripped_thumb','bytes'], true)) { unset($value[$k]); }
            elseif (is_array($v)) { $v = self::clean($v); }
        } unset($v);
        return $value;
    }

    public static function mediaShape(mixed $media): array
    {
        // Finite protocol diagnostics only: no content, IDs, URLs or auth fields.
        $constructors=['messageMediaEmpty','messageMediaPhoto','messageMediaDocument','messageMediaWebPage',
            'messageMediaGeo','messageMediaGeoLive','messageMediaContact','messageMediaVenue','messageMediaPoll',
            'messageMediaDice','messageMediaGame','messageMediaStory','messageMediaPaidMedia','messageMediaUnsupported',
            'messageMediaInvoice','photo','photoEmpty','document','documentEmpty','webPage','webPageEmpty',
            'webPagePending','webPageNotModified'];
        $fields=['_','photo','document','webpage','spoiler','ttl_seconds','live_photo','nopremium','video','round',
            'voice','alt_documents','video_cover','video_timestamp','safe','flags','flags2','bytes','media','extended_media',
            'id','access_hash','file_reference','date','sizes','dc_id','thumbs','video_sizes','has_stickers','video_thumbs',
            'mime_type','size','attributes','url','display_url','hash','type','site_name','title','description',
            'embed_url','embed_type','embed_width','embed_height','duration','author','cached_page',
            'has_large_media','has_small_media','manual','pending_date'];
        $shape=static function(mixed $value)use($constructors,$fields):array {
            if(!is_array($value)){return ['array'=>false];}
            $known=array_values(array_intersect($fields,array_keys($value)));sort($known,SORT_STRING);
            $out=['array'=>true,'constructor'=>in_array($value['_']??null,$constructors,true)?$value['_']:'other',
                'fields'=>$known,'other_fields_present'=>count($known)!==count($value)];
            if(array_key_exists('id',$value)){
                $out['id_native_int']=is_int($value['id']);
                $out['id_sign']=is_int($value['id'])?($value['id']>0?'positive':($value['id']<0?'negative':'zero')):'invalid';
            }
            foreach(['spoiler','live_photo','nopremium','video','round','voice','safe','has_large_media','has_small_media','manual']as$key){
                if(isset($value[$key])&&is_bool($value[$key])){$out['boolean_flags'][$key]=$value[$key];}
            }
            return $out;
        };
        $out=$shape($media);
        if(is_array($media)){
            foreach(['photo','document','webpage','video_cover']as$key){if(isset($media[$key])){$out['nested'][$key]=$shape($media[$key]);}}
            if(array_key_exists('alt_documents',$media)){
                $out['alternate_documents_shape']=is_array($media['alt_documents'])?($media['alt_documents']===[]?'empty':'nonempty'):'invalid';
            }
        }
        return $out;
    }

    private static function stableDocument(mixed $document): array
    {
        if(!is_array($document)||($document['_']??'')!=='document'
            ||!is_int($document['id']??null)||$document['id']<=0
            ||!is_string($document['mime_type']??null)||!is_int($document['size']??null)||$document['size']<0
            ||!is_array($document['attributes']??null)||!array_is_list($document['attributes'])){
            throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');
        }
        foreach($document['attributes']as$attribute){
            if(!is_array($attribute)||!in_array($attribute['_']??null,['documentAttributeImageSize','documentAttributeAnimated',
                'documentAttributeSticker','documentAttributeVideo','documentAttributeAudio','documentAttributeFilename',
                'documentAttributeHasStickers','documentAttributeCustomEmoji'],true)){throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');}
        }
        return ['id'=>(string)$document['id'],'mime_type'=>$document['mime_type'],'size'=>$document['size'],
            'attributes'=>self::clean($document['attributes'])];
    }

    private function documentComponents(array $document): array
    {
        $components=['document'=>$this->hmac($document)];
        foreach(['id','mime_type','size','attributes']as$field){$components[$field]=$this->hmac($document[$field]);}
        $dimensions=[];$durations=[];
        foreach($document['attributes']as$attribute){
            if(in_array($attribute['_'],['documentAttributeImageSize','documentAttributeVideo'],true)){
                $dimensions[]=array_intersect_key($attribute,array_flip(['_','w','h']));
            }
            if(in_array($attribute['_'],['documentAttributeVideo','documentAttributeAudio'],true)){
                $durations[]=array_intersect_key($attribute,array_flip(['_','duration']));
            }
        }
        $components['dimensions']=$this->hmac($dimensions);$components['duration']=$this->hmac($durations);
        return $components;
    }

    public function fingerprints(array $m): array
    {
        $media = $m['media'] ?? ['_' => 'messageMediaEmpty'];
        if (!is_array($media)) { throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE'); }
        $type = $media['_'] ?? '';
        foreach(['spoiler','live_photo','nopremium','video','round','voice']as$flag){
            if(array_key_exists($flag,$media)&&!is_bool($media[$flag])){throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');}
        }
        if(array_key_exists('ttl_seconds',$media)&&(!is_int($media['ttl_seconds'])||$media['ttl_seconds']<0)){
            throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');
        }
        $stable = ['type'=>$type,'spoiler'=>(bool)($media['spoiler']??false),'ttl_seconds'=>$media['ttl_seconds']??null];
        if ($type === 'messageMediaPhoto') {
            if (array_diff(array_keys($media), ['_','photo','spoiler','ttl_seconds','live_photo']) !== []
                || (isset($media['live_photo']) && $media['live_photo']!==false)
                || !is_array($media['photo']??null) || ($media['photo']['_']??'')!=='photo'
                || !is_int($media['photo']['id']??null) || $media['photo']['id']<=0) {
                throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');
            }
            $stable['id'] = (string)$media['photo']['id'];
        } elseif ($type === 'messageMediaDocument') {
            if (array_diff(array_keys($media), ['_','document','spoiler','ttl_seconds','nopremium','video','round','voice','alt_documents']) !== []
                || !is_array($media['document']??null) || ($media['document']['_']??'')!=='document'
                || !is_int($media['document']['id']??null) || $media['document']['id']<=0) {
                throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');
            }
            $stable += self::stableDocument($media['document'])+[
                'video'=>(bool)($media['video']??false),'round'=>(bool)($media['round']??false),
                'voice'=>(bool)($media['voice']??false),'nopremium'=>(bool)($media['nopremium']??false)];
            // Layer225 alt_documents is an ordered vector of alternative video qualities.
            // Preserve every variant in the HMAC; only rotating authorization references are excluded.
            $alternates=array_key_exists('alt_documents',$media)?$media['alt_documents']:[];
            if((array_key_exists('alt_documents',$media)&&($media['video']??null)!==true)
                ||!is_array($alternates)||!array_is_list($alternates)||count($alternates)>16){throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');}
            $stable['alt_documents_present']=array_key_exists('alt_documents',$media);
            $stable['alt_documents']=[];$variantIds=[$media['document']['id']=>true];
            foreach($alternates as$alternate){
                $projected=self::stableDocument($alternate);
                if(isset($variantIds[$alternate['id']])){throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');}
                $variantIds[$alternate['id']]=true;
                $stable['alt_documents'][]=$projected;
            }
        } elseif ($type !== 'messageMediaEmpty' || array_diff(array_keys($media), ['_'])!==[]) {
            throw new AuditFailure('UNSUPPORTED_MEDIA_TYPE');
        }
        $components=[];$variants=[];
        if($type==='messageMediaDocument'){
            $components=$this->documentComponents(array_intersect_key($stable,array_flip(['id','mime_type','size','attributes'])));
            foreach(['alt_documents_present','alt_documents']as$field){$components[$field]=$this->hmac($stable[$field]);}
            $variantIds=array_column($stable['alt_documents'],'id');
            $components['alternate_count']=$this->hmac(count($variantIds));
            $components['alternate_ordered_ids']=$this->hmac($variantIds);
            sort($variantIds,SORT_STRING);$components['alternate_unordered_ids']=$this->hmac($variantIds);
            foreach($stable['alt_documents']as$alternate){$variants[]=$this->documentComponents($alternate);}
            $components['flags']=$this->hmac(array_intersect_key($stable,array_flip(['spoiler','ttl_seconds','video','round','voice','nopremium'])));
        }
        return ['caption'=>$this->hmac($m['message']??''),'entities'=>$this->hmac($m['entities']??[]),
            'media'=>$this->hmac($stable),'layout'=>$this->hmac((bool)($m['invert_media']??false)),
            'media_type'=>$type,'media_components'=>$components,'media_variants'=>$variants];
    }

    private function messages(array $result): array
    {
        if (!in_array($result['_']??'', ['messages.channelMessages','messages.messages','messages.messagesSlice'], true)
            || !is_array($result['messages']??null) || !array_is_list($result['messages'])) {
            throw new AuditFailure('MESSAGES_RESULT_INVALID');
        }
        return $result['messages'];
    }

    public function selected(int $source, array $ids): array
    {
        $messages = [];
        foreach (array_chunk($ids,100) as $chunk) {
            $result = ($this->rpc)('channels.getMessages', ['channel'=>$source,
                'id'=>array_map(static fn(int $id):array=>['_'=>'inputMessageID','id'=>$id],$chunk)]);
            foreach ($this->messages($result) as $m) {
                if (($m['_']??'') !== 'message' || !is_int($m['id']??null)
                    || !in_array($m['id'],$chunk,true) || self::peer($m['peer_id']??[]) !== $source
                    || isset($messages[$m['id']])) { throw new AuditFailure('SOURCE_MESSAGE_INVALID'); }
                $messages[$m['id']] = $m;
            }
        }
        if (count($messages) !== count($ids)) { throw new AuditFailure('SOURCE_MESSAGE_MISSING'); }
        return $messages;
    }

    /** Freeze the first head; every later cursor must progress through contiguous history. */
    public function history(int $peer, int $lowerDate, ?int $sourceCutoff = null): array
    {
        $offset=0; $head=null; $lastId=PHP_INT_MAX; $lastDate=PHP_INT_MAX; $all=[];
        $complete=false; $boundary='page_limit'; $count=0;
        for (; $count<$this->pages; ++$count) {
            $page = $this->messages(($this->rpc)('messages.getHistory', ['peer'=>$peer,'offset_id'=>$offset,
                'offset_date'=>0,'add_offset'=>0,'limit'=>100,'max_id'=>0,'min_id'=>0,'hash'=>0]));
            if ($page===[]) { $complete=true; $boundary='history_exhausted'; ++$count; break; }
            foreach ($page as $m) {
                $id=$m['id']??null; $date=$m['date']??null;
                if (!is_int($id)||$id<=0||!is_int($date)||$date<=0||$id>=$lastId||$date>$lastDate
                    ||self::peer($m['peer_id']??[])!==$peer||isset($all[$id])) { throw new AuditFailure('HISTORY_NOT_CONTIGUOUS'); }
                $head??=$id; $all[$id]=$m; $lastId=$id; $lastDate=$date;
            }
            if (($sourceCutoff!==null && $lastId<=$sourceCutoff && $lastDate<$lowerDate)
                || ($sourceCutoff===null && $lastDate<$lowerDate)) {
                $complete=true; $boundary=$sourceCutoff===null?'date_crossed':'source_cutoff_crossed'; ++$count; break;
            }
            $offset=$lastId;
        }
        return ['messages'=>$all,'interval'=>['head_id'=>$head,'oldest_id'=>$all===[]?null:$lastId,
            'oldest_date'=>$all===[]?null:$lastDate,'lower_date'=>$lowerDate,'source_cutoff'=>$sourceCutoff,
            'pages'=>$count,'complete'=>$complete,'boundary'=>$boundary]];
    }

    public static function candidates(array $snapshot): array
    {
        $source=$snapshot['metadata']['source_id']; $baseline=$snapshot['metadata']['source_min_id'];
        $targets=array_column($snapshot['targets'],null,'target_id'); $ledger=[];
        foreach ($snapshot['rows'] as $row) {
            if ($row['source_id']!==$source || isset($ledger[$row['message_id']][$row['target_id']])) {
                throw new AuditFailure('LEDGER_BINDING_INVALID');
            }
            $ledger[$row['message_id']][$row['target_id']]=$row;
        }
        $ids=[]; $deferred=0;
        foreach ($ledger as $id=>$deliveries) {
            $ready=true;
            foreach ($targets as $target=>$t) {
                if ($id<=max(26490,$baseline,$t['cutoff']) || ($deliveries[$target]['status']??'')!=='forwarded'
                    || !empty($deliveries[$target]['protected'])) { $ready=false; }
            }
            if ($ready) { $ids[]=(int)$id; } else { ++$deferred; }
        }
        if ($ids===[]) { throw new AuditFailure('AWAITING_NATURAL_POST'); }
        sort($ids,SORT_NUMERIC);
        return ['ids'=>$ids,'ledger'=>$ledger,'deferred_source_count'=>$deferred];
    }

    public function verify(array $snapshot): array
    {
        $source=$snapshot['metadata']['source_id'];
        $targets=array_column($snapshot['targets'],null,'target_id');
        $selection=self::candidates($snapshot);$ids=$selection['ids'];$ledger=$selection['ledger'];
        $original=$this->selected($source,$ids);
        $telegramLower=min(array_column($original,'date'))-300;
        $sourceHistory=$this->history($source,$telegramLower,min(array_column($snapshot['targets'],'cutoff')));
        $targetHistory=[];
        foreach ($targets as $id=>$t) {
            $first=array_column(array_column($ledger,$id),'first_seen');
            $times=array_filter([$t['created_at'],$snapshot['metadata']['initialized_at'],...$first],static fn($n)=>$n>0);
            // Telegram dates also bound the interval when the hosting clock is ahead.
            $targetHistory[$id]=$this->history($id,min(min($times)-300,$telegramLower));
        }
        $again=$this->selected($source,$ids);
        $sourceAgain=$this->history($source,$telegramLower,min(array_column($snapshot['targets'],'cutoff')));
        $results=[]; $verifiedIds=[];$currentIds=[]; $groups=[];
        foreach ($original as $id=>$m) {
            $r=['source_message_id'=>$id,'mapping_unambiguous'=>!isset($m['fwd_from']),'targets'=>[],
                'current_verified'=>false,
                'source_date'=>is_int($m['date']??null)?$m['date']:null,
                'source_edit_date'=>is_int($m['edit_date']??null)?$m['edit_date']:null];
            if (isset($m['fwd_from'])) { $r['reason']='unsupported_forward_chain'; $r['verified']=false; $results[]=$r; continue; }
            try { $fp=$this->fingerprints($m); $later=$this->fingerprints($again[$id]); }
            catch(AuditFailure) { $r['reason']='unsupported_media_type';
                $r['source_media_shape']=self::mediaShape($m['media']??null);
                $r['reread_media_shape']=self::mediaShape($again[$id]['media']??null);
                $r['verified']=false;$results[]=$r;continue; }
            $envelope=static fn(array $value):array=>array_map(static fn($k)=>$value[$k]??null,
                ['date','post_author','grouped_id','edit_date','noforwards']);
            $r['source_changed_during_audit']=$fp!==$later||$envelope($m)!==$envelope($again[$id]);
            $r['source_history_observations_equal']=true;
            foreach([$sourceHistory,$sourceAgain]as$history){
                $observed=$history['messages'][$id]??null;
                try{$equal=is_array($observed)&&$fp===$this->fingerprints($observed)&&$envelope($m)===$envelope($observed);}
                catch(AuditFailure){$equal=false;}
                if(!$equal){$r['source_history_observations_equal']=false;$r['source_changed_during_audit']=true;}
            }
            $r['source_edit_after_delivery']=false;
            $sourceGroup=self::group($m['grouped_id']??null);
            $r['source_group_matches_ledger']=true;
            foreach ($ledger[$id] as $delivery) {
                if (self::group($delivery['grouped_id']??null)!==$sourceGroup) { $r['source_group_matches_ledger']=false; }
            }
            $r['source_content_hmac_sha256']=$this->hmac($fp);
            $r['media_type']=$fp['media_type'];
            $ok=!$r['source_changed_during_audit']&&$r['source_group_matches_ledger']&&empty($m['noforwards']);
            $currentOk=$ok;
            foreach ($targets as $target=>$t) {
                $matches=[];
                foreach ($targetHistory[$target]['messages'] as $d) {
                    $h=$d['fwd_from']??[];
                    if (($d['_']??'')==='message' && self::peer($h['from_id']??[])===$source
                        && ($h['channel_post']??null)===$id) { $matches[]=$d; }
                }
                $v=['target_id'=>$target,'matched_count'=>count($matches),'interval_complete'=>$targetHistory[$target]['interval']['complete'],
                    'destination_message_id'=>count($matches)===1?$matches[0]['id']:null,
                    'caption_equal'=>false,'entities_equal'=>false,'media_equal'=>false,'layout_equal'=>false,
                    'signature_equal'=>count($matches)===1 && ($matches[0]['fwd_from']['post_author']??null)===($m['post_author']??null),
                    'source_date_equal'=>count($matches)===1 && ($matches[0]['fwd_from']['date']??null)===($m['date']??null),
                    'verified'=>false,'current_verified'=>false];
                if (count($matches)===1) {
                    $v['destination_date']=is_int($matches[0]['date']??null)?$matches[0]['date']:null;
                    $v['destination_edit_date']=is_int($matches[0]['edit_date']??null)?$matches[0]['edit_date']:null;
                    $edit=$m['edit_date']??null;$created=$matches[0]['date']??null;
                    $v['source_edit_relation']=$edit===null||$edit===0?'absent':
                        (!is_int($edit)||!is_int($created)?'invalid':($edit<$created?'before':($edit===$created?'same_second':'after')));
                    if (!empty($m['edit_date']) && $m['edit_date']>=($matches[0]['date']??0)) {
                        $r['source_edit_after_delivery']=true;$ok=false;
                    }
                    try { $df=$this->fingerprints($matches[0]);
                        foreach (['caption','entities','media','layout'] as $field) { $v[$field.'_equal']=$fp[$field]===$df[$field]; }
                        if($fp['media_components']!==[]&&$df['media_components']!==[]){
                            foreach($fp['media_components']as$field=>$hash){$v['media_component_equal'][$field]=$hash===($df['media_components'][$field]??null);}
                            $sourceCount=count($fp['media_variants']);$destinationCount=count($df['media_variants']);
                            $comparison=['compared_by_index'=>true,'source_count'=>$sourceCount,'destination_count'=>$destinationCount,
                                'count_equal'=>$sourceCount===$destinationCount,'variants'=>[]];
                            for($index=0;$index<max($sourceCount,$destinationCount);++$index){
                                $pair=['index'=>$index,'source_present'=>isset($fp['media_variants'][$index]),
                                    'destination_present'=>isset($df['media_variants'][$index])];
                                foreach(['document','id','mime_type','size','attributes','dimensions','duration']as$field){
                                    $pair[$field.'_equal']=$pair['source_present']&&$pair['destination_present']
                                        &&$fp['media_variants'][$index][$field]===$df['media_variants'][$index][$field];
                                }
                                $comparison['variants'][]=$pair;
                            }
                            $v['alternate_document_comparison']=$comparison;
                        }
                        if(!$v['media_equal']){
                            $v['source_media_shape']=self::mediaShape($m['media']??null);
                            $v['destination_media_shape']=self::mediaShape($matches[0]['media']??null);
                        }
                        $v['destination_content_hmac_sha256']=$this->hmac($df);
                        $v['verified']=$v['interval_complete'] && $v['caption_equal'] && $v['entities_equal'] && $v['media_equal'] && $v['layout_equal']
                            && $v['signature_equal'] && $v['source_date_equal'];
                    } catch(AuditFailure) {}
                }
                $v['current_verified']=$v['verified'];
                $currentOk=$currentOk&&$v['current_verified'];
                $ok=$ok&&$v['verified']; $r['targets'][]=$v;
            }
            if ($r['source_changed_during_audit'] || $r['source_edit_after_delivery']
                || !$r['source_group_matches_ledger'] || !empty($m['noforwards'])) {
                foreach ($r['targets'] as &$v) { $v['content_mapping_verified']=$v['verified'];$v['verified']=false; } unset($v);
            }
            if($r['source_changed_during_audit']||!$r['source_group_matches_ledger']||!empty($m['noforwards'])){
                $currentOk=false;foreach($r['targets']as&$v){$v['current_verified']=false;}unset($v);
            }
            $r['verified']=$ok;$r['current_verified']=$currentOk; $results[]=$r;
            if ($sourceGroup!==null) { $groups[$sourceGroup][]=$id; }
            if ($ok) { $verifiedIds[]=$id; }
            if ($currentOk) { $currentIds[]=$id; }
        }
        $albums=[];
        foreach ($groups as $group=>$members) {
            $group=(string)$group;
            $beforeMembers=[];$afterMembers=[];$ledgerMembers=[];$partial=false;
            foreach ($ledger as $ledgerId=>$deliveries) {
                foreach ($deliveries as $row) {
                    if ((string)($row['grouped_id']??'')===$group) { $ledgerMembers[]=(int)$ledgerId;break; }
                }
            }
            foreach ($sourceHistory['messages'] as $m) {
                if ((string)($m['grouped_id']??'')===$group) { $beforeMembers[]=$m['id']; if ($m['id']<=max(array_column($snapshot['targets'],'cutoff'))) {$partial=true;} }
            }
            foreach ($sourceAgain['messages'] as $m) { if ((string)($m['grouped_id']??'')===$group) {$afterMembers[]=$m['id'];} }
            sort($members,SORT_NUMERIC);sort($beforeMembers,SORT_NUMERIC);sort($afterMembers,SORT_NUMERIC);sort($ledgerMembers,SORT_NUMERIC);
            $a=['source_group_hmac_sha256'=>$this->hmac($group),'source_member_ids'=>$members,'size'=>count($members),
                'known_ledger_member_ids'=>$ledgerMembers,'known_ledger_members_complete'=>$members===$ledgerMembers,
                'source_interval_complete'=>$sourceHistory['interval']['complete']&&$sourceAgain['interval']['complete'],
                'membership_complete'=>$members===$beforeMembers,'late_parts_pending'=>$beforeMembers!==$afterMembers,
                'partial_at_activation'=>$partial,'targets'=>[],'verified'=>false];
            $a['multiple_members']=count($members)>=2;
            $sourceMembersVerified=0;$currentMembersVerified=0;
            foreach ($results as $r) {
                if (in_array($r['source_message_id'],$members,true) && $r['verified']) { ++$sourceMembersVerified; }
                if (in_array($r['source_message_id'],$members,true) && $r['current_verified']) { ++$currentMembersVerified; }
            }
            $a['source_members_verified']=$sourceMembersVerified===count($members);
            $a['source_members_current_verified']=$currentMembersVerified===count($members);
            $albumOk=$a['multiple_members']&&$a['source_members_verified']&&$a['known_ledger_members_complete']&&$a['source_interval_complete']
                &&$a['membership_complete']&&!$a['late_parts_pending']&&!$partial;
            $albumCurrentOk=$a['multiple_members']&&$a['source_members_current_verified']&&$a['known_ledger_members_complete']&&$a['source_interval_complete']
                &&$a['membership_complete']&&!$a['late_parts_pending']&&!$partial;
            foreach ($targets as $target=>$t) {
                $destIds=[];$destGroups=[];$mapped=[];
                $destinationGroup=static function(array $message):?string{
                    try{return self::group($message['grouped_id']??null);}catch(AuditFailure){return null;}
                };
                foreach ($results as $r) {
                    if (!in_array($r['source_message_id'],$members,true) || !$r['current_verified']) {continue;}
                    foreach ($r['targets'] as $v) {if ($v['target_id']===$target&&$v['current_verified']&&$v['destination_message_id']!==null) {
                        $d=$targetHistory[$target]['messages'][$v['destination_message_id']];
                        $destIds[$r['source_message_id']]=$d['id'];$destGroups[]=$destinationGroup($d);
                    }}
                }
                ksort($destIds,SORT_NUMERIC);$ordered=array_values($destIds);$sorted=$ordered;sort($sorted,SORT_NUMERIC);
                $oneGroup=count(array_unique($destGroups))===1&&($destGroups[0]??null)!==null;
                if ($oneGroup) {
                    foreach ($targetHistory[$target]['messages'] as $d) {if ($destinationGroup($d)===$destGroups[0]) {$mapped[]=$d['id'];}}
                }
                sort($mapped,SORT_NUMERIC);
                $v=['target_id'=>$target,'destination_message_ids'=>$ordered,'member_count_equal'=>count($ordered)===count($members),
                    'group_consistent'=>$oneGroup,'no_extra_group_members'=>$mapped===$sorted,'order_equal'=>$ordered===$sorted];
                $v['verified']=$v['member_count_equal']&&$v['group_consistent']&&$v['no_extra_group_members']&&$v['order_equal'];
                $v['current_verified']=$v['verified'];
                $albumOk=$albumOk&&$v['verified'];$albumCurrentOk=$albumCurrentOk&&$v['current_verified'];$a['targets'][]=$v;
            }
            $a['verified']=$albumOk;$a['current_verified']=$albumCurrentOk;
            foreach($a['targets']as&$v){$v['verified']=$v['verified']&&$albumOk;$v['current_verified']=$v['current_verified']&&$albumCurrentOk;}unset($v);
            $albums[]=$a;
            if(!$albumCurrentOk){
                $currentIds=array_values(array_diff($currentIds,$members));
                foreach($results as&$r){if(in_array($r['source_message_id'],$members,true)){
                    $r['current_verified']=false;foreach($r['targets']as&$v){$v['current_verified']=false;}unset($v);
                }}unset($r);
            }
            if (!$albumOk) {
                $verifiedIds=array_values(array_diff($verifiedIds,$members));
                foreach ($results as &$r) {
                    if (in_array($r['source_message_id'],$members,true)) {
                        $r['verified']=false;$r['album_incomplete']=true;
                        foreach ($r['targets'] as &$v) { $v['content_mapping_verified']=$v['verified'];$v['verified']=false; } unset($v);
                    }
                } unset($r);
            }
        }
        return ['messages'=>$results,'albums'=>$albums,'verified_source_ids'=>$verifiedIds,
            'current_verified_source_ids'=>$currentIds,'live_current_snapshot_verified'=>$currentIds!==[],
            'all_audited_messages_current_verified'=>count($currentIds)===count($ids),
            'live_current_album_verified'=>$albums!==[]&&!in_array(false,array_column($albums,'current_verified'),true),
            'live_natural_forward_verified'=>$verifiedIds!==[],
            'all_audited_messages_verified'=>count($verifiedIds)===count($ids),
            'live_album_verified'=>$albums!==[]&&!in_array(false,array_column($albums,'verified'),true),
            'source_intervals'=>[$sourceHistory['interval'],$sourceAgain['interval']],
            'target_intervals'=>array_map(static fn($h)=>$h['interval'],$targetHistory),
            'deferred_source_count'=>$selection['deferred_source_count'],
            'media_fingerprint_scope'=>'stable media metadata only; no file bytes downloaded or hashed',
            'observation_scope'=>'Each destination was observed at its bounded history read; later destination edits/deletes are outside this report.',
            'current_snapshot_scope'=>'Current caption/entities/media metadata/layout and origin mapping match exactly once per destination in the complete audited target interval; source was stable across both reads. This does not prove the initial payload at send time or edit synchronization.',
            'initial_delivery_payload_verified'=>false,'edit_synchronization_verified'=>false,
            'scope'=>'Direct source messages and complete source groups in the audited post-cutoff interval; forward chains are inconclusive.'];
    }
}
