<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==2||!preg_match('/^[a-f0-9]{32}$/D',$argv[1])){exit(2);}
umask(0077);
require_once __DIR__.'/NaturalAudit.php';require_once __DIR__.'/RepostAudit.php';
use TelegramMirror\Audit\NativeState;
use TelegramMirror\Audit\ReadClient;
use TelegramMirror\Audit\RepostAudit;
use TelegramMirror\Audit\AuditFailure;
function repostSnapshot(string $root):array{
    if(!defined('PDO::SQLITE_OPEN_READONLY')){throw new AuditFailure('READONLY_SQLITE_REQUIRED');}
    $file=$root.'/storage/queue.sqlite';$uid=fileowner('/proc/'.getmypid());
    foreach([$file,$file.'-wal',$file.'-shm']as$path){clearstatcache(true,$path);$s=@lstat($path);if($s===false||$s['uid']!==$uid||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||is_link($path)){throw new AuditFailure('PRIVATE_DATABASE_REQUIRED');}}
    $uri=implode('/',array_map('rawurlencode',explode('/',$file)));
    $db=new PDO(ini_get('open_basedir')?'sqlite:'.$file:'sqlite:file:'.$uri.'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    $db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    try{
        $meta=$db->query('SELECT key,value FROM queue_metadata ORDER BY key')->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows=$db->query("SELECT m.grouped_id,d.* FROM queue_deliveries d JOIN queue_messages m ON m.source_id=d.source_id AND m.message_id=d.message_id WHERE d.delivery_mode='repost' ORDER BY d.message_id,d.target_id LIMIT 1001")->fetchAll();
        if(count($rows)>1000){throw new AuditFailure('REPOST_LEDGER_LIMIT');}
        $payloads=$db->query('SELECT source_id,message_id,payload FROM queue_repost_payloads ORDER BY source_id,message_id LIMIT 1001')->fetchAll();
        $targets=$db->query('SELECT * FROM queue_targets ORDER BY target_id')->fetchAll();
        if((int)($meta['source_id']??0)!==-1002295574614||($meta['delivery_mode']??null)!=='repost'||(int)($meta['source_min_id']??0)!==26490
            ||($meta['paused_reason']??'')!==''||($meta['source_protected']??'0')!=='0'
            ||array_column($targets,'target_id')!==[-1004374103992,-1003718740756]||array_column($targets,'cutoff')!==[26490,26490]
            ||(int)($meta['repost_activated_at']??0)<1){throw new AuditFailure('REPOST_BINDINGS_NOT_READY');}
        $frozen=[];$digests=[];
        foreach($payloads as$p){if($p['source_id']!==-1002295574614){throw new AuditFailure('FROZEN_SOURCE_CHANGED');}
            $raw=base64_decode($p['payload'],true);$value=$raw===false?false:unserialize($raw,['allowed_classes'=>false]);
            if(!is_array($value)||($value['version']??null)!==1){throw new AuditFailure('FROZEN_PAYLOAD_INVALID');}
            $frozen[$p['message_id']]=$value;$digests[$p['message_id']]=hash('sha256',$p['payload']);
        }
        foreach($rows as&$row){$row['random_id_sha256']=hash('sha256','TelegramMirror/random_id/v1:'.(string)$row['random_id']);unset($row['random_id']);}unset($row);
        $binding=array_intersect_key($meta,array_flip(['account_id','source_id','target_id','target_ids','target_refs','source_min_id','initialized_at','delivery_mode','repost_activated_at','worker_pid']));
        if((int)$db->query('SELECT total_changes()')->fetchColumn()!==0||(int)$db->query('PRAGMA query_only')->fetchColumn()!==1){throw new AuditFailure('AUDIT_CHANGED_DATABASE');}
        $allRows=$rows;$byMessage=[];
        foreach($allRows as$row){$byMessage[$row['message_id']][]=$row;}
        $eligible=[];$groups=[];
        foreach($byMessage as$id=>$deliveries){
            $ids=array_column($deliveries,'target_id');sort($ids);
            if(count($deliveries)!==2||$ids!==[-1004374103992,-1003718740756]||array_unique(array_column($deliveries,'status'))!==['forwarded']){continue;}
            $eligible[$id]=$deliveries;$group=$deliveries[0]['grouped_id'];
            if($group!==null&&$group!==0&&$group!=='0'){$groups[(string)$group][]=$id;}
        }
        $cohort=[];$latest=0;
        foreach($groups as$members){if(count($members)>=2&&max($members)>$latest){$cohort=$members;$latest=max($members);}}
        if($cohort===[]&&$eligible!==[]){$cohort=[max(array_keys($eligible))];}
        $rows=[];sort($cohort);
        foreach($cohort as$id){array_push($rows,...$eligible[$id]);}
        return ['rows'=>$rows,'frozen'=>$frozen,'activated'=>(int)$meta['repost_activated_at'],'pid'=>(int)$meta['worker_pid'],
            'seal'=>hash('sha256',json_encode([$allRows,$digests,$binding,$targets],JSON_THROW_ON_ERROR)),
            'readonly'=>true,'own_changes'=>0];
    }finally{$db->rollBack();}
}
$root=realpath(dirname(__DIR__));$nonce=$argv[1];$dir=$root.'/storage/deploy';$client=null;
$report=['nonce'=>$nonce,'started_at'=>time(),'passed'=>false,'native_repost_verified'=>false,'live_repost_album_verified'=>false,'synthetic'=>false];
try{
    $state=new NativeState($root);$state->prerequisites();$before=repostSnapshot($root);
    if($before['rows']===[]){throw new AuditFailure('AWAITING_NEW_REPOST_DELIVERIES');}
    $guard=static fn():string=>$state->guard($before['pid']);$identity=$guard();
    require_once $root.'/vendor/autoload.php';
    if(ltrim(\Composer\InstalledVersions::getPrettyVersion('danog/madelineproto')??'','v')!=='8.7.0'){throw new AuditFailure('PINNED_LIBRARY_REQUIRED');}
    $client=new ReadClient(static fn()=>\Amp\Ipc\connect($root.'/storage/telegram.session/ipc'),static function($socket,array $packet,float $seconds):mixed{
        $f=\Amp\async(static function()use($socket,$packet):mixed{$socket->send($packet);return $socket->receive();});
        try{return $f->await(new \Amp\TimeoutCancellation($seconds));}catch(Throwable $e){$f->ignore();throw $e;}
    },$guard);
    $result=(new RepostAudit($client->call(...),random_bytes(32)))->verify($before['rows'],$before['frozen'],$before['activated']);
    $after=repostSnapshot($root);
    if($before['seal']!==$after['seal']||$guard()!==$identity){throw new AuditFailure('REPOST_AUDIT_STATE_CHANGED');}
    $report=array_replace($report,$result);$report['readonly_snapshots']=true;$report['database_own_changes']=0;
    $report['ledger_bindings_payloads_random_ids_unchanged']=true;$report['worker_identity_unchanged']=true;
}catch(Throwable $e){$report['reason']=$e instanceof AuditFailure?$e->getMessage():'REPOST_AUDIT_INCONCLUSIVE';}
finally{if($client!==null){$report['rpc_requests']=$client->requests();$report['validated_responses']=$client->responses();$client->close();}}
$report['finished_at']=time();$h=fopen($dir.'/repost-audit-'.$nonce.'.json','x');fwrite($h,json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($h);exit($report['passed']?0:1);
