<?php
declare(strict_types=1);
// Offline request and persistence contracts; no Composer/session/network.
require_once dirname(__DIR__).'/src/Queue.php';
require_once dirname(__DIR__).'/src/Repost.php';
use TelegramMirror\Queue;
use TelegramMirror\Repost;
use TelegramMirror\RepostFailure;
const RS=-1001234567890;
const RT=-1002234567890;
const RB=-1003234567890;
$assertions=0;
function eq(mixed $a,mixed $b,string $why):void{global $assertions;++$assertions;if($a!==$b){throw new RuntimeException('FAIL '.$why);}}
function rejects(callable $f,string $why):void{try{$f();}catch(RuntimeException){eq(true,true,$why);return;}eq(true,false,$why);}
function raw(int $id,array $extra=[]):array{return $extra+['_'=>'message','id'=>$id,'date'=>123,'peer_id'=>['_'=>'peerChannel','channel_id'=>1234567890],'message'=>'😀 <b>literal</b>','entities'=>[['_'=>'messageEntityBold','offset'=>3,'length'=>7]]];}
function photo(int $id=888):array{return ['_'=>'messageMediaPhoto','photo'=>['_'=>'photo','id'=>$id,'access_hash'=>-12345678901234,'file_reference'=>"\x00\xff\x80"],'spoiler'=>true];}
final class RpcDouble {
    public array $source=[];public array $calls=[];public ?array $override=null;public int $next=500;
    public function __call(string $method,array $args):array{
        if($method==='getMessages'){return ['messages'=>$this->override??array_reverse(array_values(array_intersect_key($this->source,array_flip(array_column($args['id'],'id')))))];}
        if($method==='forwardMessages'){throw new RuntimeException('Forward forbidden');}
        $this->calls[]=['method'=>$method,'args'=>$args];
        if($method==='sendMultiMedia'){$u=[];foreach($args['multi_media']as$p){$u[]=['_'=>'updateMessageID','random_id'=>$p['random_id'],'id'=>++$this->next];}return ['_'=>'updates','updates'=>$u];}
        return ['_'=>'updateShortSentMessage','out'=>true,'id'=>++$this->next];
    }
}
final class ApiDouble {
    public RpcDouble $channels;public RpcDouble $messages;
    public function __construct(){$this->channels=new RpcDouble;$this->messages=new RpcDouble;}
    public function getId(array $m):int{return -1000000000000-$m['peer_id']['channel_id'];}
    public function getFileInfo(array $m):array{
        $f=$m['photo']??($m['document']??$m);$p=($f['_']??'')==='photo';
        $i=['_'=>$p?'inputPhoto':'inputDocument','id'=>$f['id'],'access_hash'=>$f['access_hash'],'file_reference'=>$f['file_reference']];
        return ['InputMedia'=>['_'=>$p?'inputMediaPhoto':'inputMediaDocument','id'=>$i],'InputPhoto'=>$i];
    }
}
function batch(array $ids,array $randoms=[1234]):array{return ['source_id'=>RS,'ids'=>$ids,'random_ids'=>$randoms,'delivery_mode'=>'repost'];}
$api=new ApiDouble;
$api->channels->source=[1=>raw(1)];$b=batch([1]);$p=Repost::capture($api,$b);
$r=Repost::send($api,RT,$b,$p);$c=$api->messages->calls[0];
eq('sendMessage',$c['method'],'text uses fresh sendMessage');
eq(raw(1)['message'],$c['args']['message'],'literal text unchanged');eq(raw(1)['entities'],$c['args']['entities'],'UTF16 entities unchanged');
eq(false,array_key_exists('parse_mode',$c['args']),'no markup parsing');eq(false,array_key_exists('from_peer',$c['args']),'no forward source');eq(1234,$c['args']['random_id'],'explicit random');
eq(['1234'=>501],Repost::acknowledgements($r,[1234],RT),'single short ACK');
eq(null,Repost::acknowledgements(['_'=>'updateShortSentMessage','out'=>1,'id'=>3],[1234]),'strict short out');
eq(null,Repost::acknowledgements(['_'=>'updateShortSentMessage','out'=>true,'id'=>0],[1234]),'invalid short ID');
$api->channels->source=[2=>raw(2,['media'=>photo(),'invert_media'=>true])];$b=batch([2]);$p=Repost::capture($api,$b);Repost::send($api,RT,$b,$p);$c=end($api->messages->calls);
eq('sendMedia',$c['method'],'photo fresh media send');eq('inputMediaPhoto',$c['args']['media']['_'],'reuse existing photo');
eq("\x00\xff\x80",$c['args']['media']['id']['file_reference'],'binary reference');eq(true,$c['args']['media']['spoiler'],'photo spoiler');eq(true,$c['args']['invert_media'],'caption position');
$doc=['_'=>'messageMediaDocument','document'=>['_'=>'document','id'=>7123456789012345678,'access_hash'=>-8123456789012345678,'file_reference'=>"\xff\x00",'attributes'=>[['_'=>'documentAttributeVideo','duration'=>7.1,'w'=>720,'h'=>1280]]],'spoiler'=>true,'video_timestamp'=>3,'video_cover'=>photo(777)['photo']];
$api->channels->source=[3=>raw(3,['media'=>$doc])];$b=batch([3]);$p=Repost::capture($api,$b);Repost::send($api,RT,$b,$p);$c=end($api->messages->calls);
eq('inputMediaDocument',$c['args']['media']['_'],'reuse primary video');eq(7123456789012345678,$c['args']['media']['id']['id'],'64bit media');eq(true,$c['args']['media']['spoiler'],'document spoiler');eq(3,$c['args']['media']['video_timestamp'],'video timestamp');eq(777,$c['args']['media']['video_cover']['id'],'video cover');
$api->channels->source=[4=>raw(4,['media'=>photo(4),'message'=>'first']),5=>raw(5,['media'=>$doc,'message'=>'second'])];$b=batch([4,5],[101,102]);$p=Repost::capture($api,$b);$r=Repost::send($api,RT,$b,$p);$c=end($api->messages->calls);
eq('sendMultiMedia',$c['method'],'album fresh multi media');eq(['first','second'],array_column($c['args']['multi_media'],'message'),'shuffled read preserves album order');eq([101,102],array_column($c['args']['multi_media'],'random_id'),'per-part stable randoms');
$ack=Repost::acknowledgements($r,[101,102],RT);eq(2,count($ack),'complete album ack');eq(2,count(array_unique($ack)),'distinct destination IDs');
$map=fn($a,$id)=>['_'=>'updateMessageID','random_id'=>$a,'id'=>$id];
eq(null,Repost::acknowledgements(['_'=>'updates','updates'=>[$map(101,1)]],[101,102]),'partial ACK');
eq(null,Repost::acknowledgements(['_'=>'updates','updates'=>[$map(101,1),$map(102,1)]],[101,102]),'duplicate destination ACK');
eq(null,Repost::acknowledgements(['_'=>'updates','updates'=>[$map(101,1),$map(101,2)]],[101]),'contradictory ACK');
eq(null,Repost::acknowledgements(['_'=>'updates','updates'=>[$map(101,1),['_'=>'updateNewScheduledMessage','message'=>['id'=>1]]]],[101]),'scheduled ID not final');
foreach([['fwd_from'=>['from_id'=>1]],['video_processing_pending'=>true],['peer_id'=>['_'=>'peerChannel','channel_id'=>3234567890]]]as$bad){
 $m=$bad+['id'=>1,'peer_id'=>['_'=>'peerChannel','channel_id'=>2234567890]];
 eq(null,Repost::acknowledgements(['_'=>'updates','updates'=>[$map(101,1),['_'=>'updateNewChannelMessage','message'=>$m]]],[101],RT),'invalid inline message rejected');
}
foreach([[raw(4,['noforwards'=>true]),raw(5,['media'=>$doc])],[raw(4),raw(4)],[raw(4)],[raw(4,['peer_id'=>['_'=>'peerChannel','channel_id'=>99]]),raw(5)]]as$bad){$api->channels->override=$bad;rejects(fn()=>Repost::capture($api,batch([4,5],[101,102])),'protected/missing/duplicate/wrong-peer blocked');}
$api->channels->override=null;
$queue=new Queue(':memory:');$queue->setMeta('source_id',(string)RS);$queue->setMeta('target_id',(string)RT);$queue->setMeta('source_min_id','0');$queue->registerTargets(RS,[RT,RB],0);
$queue->enqueue(RS,raw(1));$old=$queue->pendingBatches(time(),0,RT)[0];
$queue->markForwarded([1],RS,RT);$queue->enqueue(RS,raw(2));$pre=$queue->pendingBatches(time(),0,RT)[0];
eq(3,$queue->activateRepost(RS,[RT,RB]),'all legacy pending quarantined including attempt zero');eq(1,$queue->stats(RT)['forwarded'],'old terminal retained');
eq(2,$queue->stats(RB)['skipped'],'old unsent destination quarantined');eq(0,$queue->activateRepost(RS,[RT,RB]),'mode seal idempotent');
$queue->enqueue(RS,raw(2));eq(0,$queue->stats(RT)['pending'],'old duplicate not resurrected');
$queue->enqueue(RS,raw(10,['grouped_id'=>777]));$queue->enqueue(RS,raw(11,['grouped_id'=>777]));
$a=$queue->pendingBatches(time(),0,RT)[0];$b=$queue->pendingBatches(time(),0,RB)[0];eq('repost',$a['delivery_mode'],'new rows explicit mode');eq(false,$a['random_ids']===$b['random_ids'],'target random IDs independent');
$payloads=[10=>['version'=>1,'message'=>'original','entities'=>[],'media'=>['_'=>'inputMediaPhoto','id'=>['_'=>'inputPhoto','id'=>10,'access_hash'=>1,'file_reference'=>"\x00\xff"]]],11=>['version'=>1,'message'=>'caption','entities'=>[],'media'=>null]];
$f=$queue->freezePayloads(RS,$payloads);eq($payloads,$queue->frozenPayloads(RS,[10,11]),'binary private payload roundtrip');
$changed=$payloads;$changed[10]['message']='edited';eq($payloads,$queue->freezePayloads(RS,$changed),'source edit cannot change frozen semantics');
$queue->freezeRepostBatch($a,RT);$queue->enqueue(RS,raw(12,['grouped_id'=>777]));eq([10,11],$queue->pendingBatches(time(),0,RT)[0]['ids'],'late part cannot alter attempted album');
$queue->retryBatch([10,11],RS,'timeout',time()-1,RT);eq($a['random_ids'],$queue->pendingBatches(time(),0,RT)[0]['random_ids'],'retry randoms unchanged');
rejects(fn()=>$queue->markForwarded([10,11],RS,RT),'generic success cannot bypass repost ACK');
rejects(fn()=>$queue->markReposted($a,RT,[(string)$a['random_ids'][0]=>600]),'partial completion cannot commit');
eq(3,$queue->stats(RT)['pending'],'failed completion rolls back');
$queue->markReposted($a,RT,[(string)$a['random_ids'][0]=>600,(string)$a['random_ids'][1]=>601]);eq(3,$queue->stats(RT)['forwarded'],'complete ACK commits both parts');eq([12],$queue->pendingBatches(time(),0,RT)[0]['ids'],'late part retained separately');
eq('0',$queue->getMeta('source_min_id'),'cutoff unchanged');
echo 'Repost: '.$assertions." assertions passed.\n";
