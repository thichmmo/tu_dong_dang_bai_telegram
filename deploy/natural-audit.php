<?php
declare(strict_types=1);

// Private CLI reader only. Never bootstrap App/Queue or reconstruct a Telegram session.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !preg_match('/^[a-f0-9]{32}$/D', $argv[1])) {
    fwrite(STDERR, "One lowercase 32-hex audit nonce required.\n"); exit(2);
}
umask(0077);
require_once __DIR__ . '/NaturalAudit.php';

use TelegramMirror\Audit\AuditFailure;
use TelegramMirror\Audit\NativeState;
use TelegramMirror\Audit\ReadClient;
use TelegramMirror\Audit\Verifier;

$root = realpath(dirname(__DIR__));
$report = ['version'=>2,'nonce'=>$argv[1],'started_at'=>time(),'passed'=>false,'pass_scope'=>'inconclusive',
    'live_natural_forward_verified'=>false,'live_album_verified'=>false,
    'live_current_snapshot_verified'=>false,'live_current_album_verified'=>false,
    'all_audited_messages_current_verified'=>false,'current_scope_passed'=>false,
    'initial_delivery_payload_verified'=>false,'edit_synchronization_verified'=>false,
    'rpc_requests'=>0,'synthetic'=>false];
$client=null; $path=null;
try {
    if ($root===false) { throw new AuditFailure('PRIVATE_ROOT_REQUIRED'); }
    $state=new NativeState($root); $state->prerequisites();
    $path=$root.'/storage/deploy/natural-audit-'.$argv[1].'.json';
    if (file_exists($path)||is_link($path)) { throw new AuditFailure('REPORT_EXISTS'); }
    $before=$state->snapshot();
    $targetIds=array_column($before['targets'],'target_id');sort($targetIds,SORT_NUMERIC);
    if ($before['metadata']['source_id']!==-1002295574614
        ||$targetIds!==[-1004374103992,-1003718740756]
        ||$before['metadata']['source_min_id']<26490
        ||min(array_column($before['targets'],'cutoff'))<26490
        ||$before['metadata']['worker_pid']<1) { throw new AuditFailure('DEPLOYED_BINDINGS_CHANGED'); }
    $report['snapshot_before']=['ledger_sha256'=>$before['ledger_sha256'],
        'query_only'=>$before['query_only'],'own_changes_before'=>$before['own_changes_before'],
        'own_changes_after'=>$before['own_changes_after'],'source_cutoff'=>$before['metadata']['source_min_id'],
        'targets'=>$before['targets']];
    // This hard gate precedes Composer, socket connection and every Telegram RPC.
    Verifier::candidates($before);
    $pid=$before['metadata']['worker_pid'];
    $guard=static fn():string=>$state->guard($pid);
    $identity=$guard();
    $report['worker_identity_digest_before']=$identity;
    $report['guard_proofs']=['same_uid'=>true,'exact_worker_argv'=>true,'worker_holds_process_lock'=>true,
        'private_socket_path'=>true,'listening_socket_inode_held_by_worker'=>true,'fresh_all_targets_ready'=>true];
    require_once $root.'/vendor/autoload.php';
    if (ltrim(\Composer\InstalledVersions::getPrettyVersion('danog/madelineproto')??'','v')!=='8.7.0') {
        throw new AuditFailure('PINNED_LIBRARY_REQUIRED');
    }
    $client=new ReadClient(
        // Pinned danog/ipc 1.0.1 uses a synchronous 5s native Unix socket connect.
        // The guard only accepts this socket type; FIFO/file fallback is forbidden.
        static fn()=>\Amp\Ipc\connect($root.'/storage/telegram.session/ipc'),
        static function ($socket,array $packet,float $seconds):mixed {
            // Timeout covers send and receive. Closing does not retry a sent read.
            $future=\Amp\async(static function () use ($socket,$packet):mixed {
                $socket->send($packet); return $socket->receive();
            });
            try { return $future->await(new \Amp\TimeoutCancellation($seconds)); }
            catch (Throwable $error) {
                // ReadClient closes the owned socket; consume a later coroutine failure.
                $future->ignore();throw $error;
            }
        },$guard);
    $verifier=new Verifier($client->call(...),random_bytes(32));
    $result=$verifier->verify($before);
    $after=$state->snapshot();
    $report['snapshot_after']=['ledger_sha256'=>$after['ledger_sha256'],
        'query_only'=>$after['query_only'],'own_changes_before'=>$after['own_changes_before'],
        'own_changes_after'=>$after['own_changes_after']];
    $bindingsStable=$before['metadata']===$after['metadata']&&$before['targets']===$after['targets'];
    $randoms=static function(array $rows):array {
        $r=[];foreach($rows as $row){$r[$row['source_id'].':'.$row['message_id'].':'.$row['target_id']]=$row['random_id_sha256'];}
        ksort($r,SORT_STRING);return $r;
    };
    $prior=$randoms($before['rows']);$later=$randoms($after['rows']);$randomStable=true;
    foreach($prior as $key=>$hash){if(($later[$key]??null)!==$hash){$randomStable=false;}}
    $report['random_ids_stable_during_audit']=$randomStable;
    $report['selected_random_digest_before']=hash('sha256',json_encode($prior,JSON_THROW_ON_ERROR));
    $sameSubset=array_intersect_key($later,$prior);
    $report['selected_random_digest_after']=hash('sha256',json_encode($sameSubset,JSON_THROW_ON_ERROR));
    $finalIdentity=$guard();
    $report['worker_identity_digest_after']=$finalIdentity;
    $report['worker_identity_unchanged']=$identity===$finalIdentity;
    $report['bindings_unchanged']=$bindingsStable;
    $ledgerStable=$before['ledger_sha256']===$after['ledger_sha256'];
    $report['ledger_unchanged_during_audit']=$ledgerStable;
    $report['actual_delivery_attempts']=array_map(static fn($r)=>[
        'source_message_id'=>$r['message_id'],'target_id'=>$r['target_id'],'attempts'=>$r['attempts']],$before['rows']);
    $report['real_retry_replay_verified']=false;
    $report['real_delivery_restart_verified']=false;
    $result=Verifier::seal($result,$ledgerStable&&$bindingsStable&&$randomStable&&$report['worker_identity_unchanged']);
    $report+=$result;
    // Replace the initial false gates only after independently proved matches.
    $report['live_natural_forward_verified']=$result['live_natural_forward_verified']&&$ledgerStable&&$bindingsStable&&$randomStable&&$report['worker_identity_unchanged'];
    $report['live_album_verified']=$result['live_album_verified']&&$report['live_natural_forward_verified'];
    $report['live_current_snapshot_verified']=$result['live_current_snapshot_verified'];
    $report['live_current_album_verified']=$result['live_current_album_verified'];
    $report['all_audited_messages_current_verified']=$result['all_audited_messages_current_verified'];
    $report['passed']=$report['live_natural_forward_verified'];
    $report['current_scope_passed']=$report['all_audited_messages_current_verified']&&$report['live_current_snapshot_verified'];
    $report['pass_scope']=$report['current_scope_passed']?'current_observed_snapshot':
        ($report['live_natural_forward_verified']?'strict_temporal_subset':'inconclusive');
} catch(Throwable $error) {
    $report['reason']=$error instanceof AuditFailure?$error->getMessage():'AUDIT_INCONCLUSIVE';
} finally {
    if($client instanceof ReadClient){$report['rpc_requests']=$client->requests();$report['rpc_validated_responses']=$client->responses();$client->close();}
    $report['finished_at']=time();
}
if($path===null){fwrite(STDERR,"Private audit prerequisites unavailable.\n");exit(2);}
$handle=@fopen($path,'x');
if($handle===false){fwrite(STDERR,"Private report unavailable.\n");exit(2);}
try {
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if(fwrite($handle,$json)!==strlen($json)){throw new RuntimeException('REPORT_WRITE_FAILED');}
} finally {fclose($handle);}
fwrite(STDOUT,'natural-audit: '.($report['current_scope_passed']?'CURRENT_SNAPSHOT_VERIFIED':($report['passed']?'VERIFIED':'INCONCLUSIVE'))."\n");
exit($report['passed']||$report['current_scope_passed']?0:1);
