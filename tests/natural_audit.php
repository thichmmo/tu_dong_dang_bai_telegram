<?php
declare(strict_types=1);

// Pure synthetic fixtures: no vendor, Telegram connection, session, App or Queue.
require_once dirname(__DIR__).'/deploy/NaturalAudit.php';

use TelegramMirror\Audit\AuditFailure;
use TelegramMirror\Audit\ReadClient;
use TelegramMirror\Audit\Verifier;

const SOURCE=-1002295574614;
const TARGET_A=-1003718740756;
const TARGET_B=-1004374103992;
$checks=0;$cases=0;
function check(bool $ok,string $label):void {
    global $checks;++$checks;if(!$ok){throw new RuntimeException('FAIL: '.$label);}
}
function failure(callable $run,string $reason):void {
    try{$run();}catch(AuditFailure $e){check($e->getMessage()===$reason,$reason);return;}
    throw new RuntimeException('Expected failure: '.$reason);
}
function test(string $name,callable $run):void {global $cases;$run();++$cases;}

final class FakeSocket {public int $closes=0;public function disconnect():void{++$this->closes;}}
function readHarness(?callable $exchange=null,?callable $guard=null,?callable $clock=null):array {
    $state=(object)['connects'=>0,'packets'=>[],'socket'=>new FakeSocket(),'reply'=>0];
    $connect=static function()use($state){++$state->connects;return $state->socket;};
    $send=static function($socket,$packet,$timeout)use($state,$exchange){
        $state->packets[]=$packet;
        if($exchange!==null){return $exchange($state,$socket,$packet,$timeout);}
        return [$state->reply++,['_'=>'messages.messages','messages'=>[]]];
    };
    return [new ReadClient($connect,$send,$guard??static fn()=>'same',$clock),$state];
}

test('connect once and allowlisted protocol',function(){
    [$c,$s]=readHarness();$c->call('channels.getMessages',['channel'=>SOURCE,'id'=>[]]);
    $c->call('messages.getHistory',['peer'=>SOURCE]);
    check($s->connects===1&&count($s->packets)===2,'one connection');
    check($s->packets[0]===['methodCallAsyncRead',['channels.getMessages',['channel'=>SOURCE,'id'=>[]],null]],'native packet');
    check($c->requests()===2&&$c->responses()===2,'attempts and replies');
    $c->close();$c->close();check($s->socket->closes===1,'disconnect once');
});
test('invalid replies permanently close',function(){
    foreach([[1,[]],['0',[]],[0.0,[]],[0,[],null],['index'=>0,'result'=>[]],new stdClass(),null,[0,new stdClass()]] as $reply){
        [$c,$s]=readHarness(static fn()=>$reply);
        failure(static fn()=>$c->call('messages.getHistory',[]),'IPC_RESPONSE_INVALID');
        failure(static fn()=>$c->call('messages.getHistory',[]),'RPC_CLOSED_OR_LIMIT');
        check($s->connects===1&&count($s->packets)===1&&$s->socket->closes===1,'no reconnect invalid reply');
        check($c->requests()===1&&$c->responses()===0,'failed attempt counted');
    }
});
test('exceptions and denied methods close',function(){
    [$c,$s]=readHarness(static function(){throw new RuntimeException('secret timeout');});
    failure(static fn()=>$c->call('messages.getHistory',[]),'IPC_READ_FAILED');
    failure(static fn()=>$c->call('messages.getHistory',[]),'RPC_CLOSED_OR_LIMIT');
    check($s->connects===1&&count($s->packets)===1,'timeout no reconnect');
    [$c,$s]=readHarness();$c->call('messages.getHistory',[]);
    failure(static fn()=>$c->call('messages.forwardMessages',[]),'RPC_NOT_ALLOWED');
    failure(static fn()=>$c->call('messages.getHistory',[]),'RPC_CLOSED_OR_LIMIT');
    check(count($s->packets)===1&&$s->socket->closes===1,'denied RPC terminates connection');
});
test('identity changes and deadline fail before next read',function(){
    $identity='original';[$c,$s]=readHarness(null,static function()use(&$identity){return $identity;});
    $identity='changed-pid-starttime-socket';
    failure(static fn()=>$c->call('messages.getHistory',[]),'WORKER_IDENTITY_CHANGED');
    check($s->connects===0&&$s->packets===[],'identity gate before connect');
    $identity='same';[$c,$s]=readHarness(static function($s)use(&$identity){$identity='different';return[0,[]];},static function()use(&$identity){return$identity;});
    failure(static fn()=>$c->call('messages.getHistory',[]),'WORKER_IDENTITY_CHANGED');
    check($s->socket->closes===1&&$c->responses()===1,'identity change after receive closes');
    $now=0.0;[$c,$s]=readHarness(null,null,static function()use(&$now){return$now;});$now=105.0;
    failure(static fn()=>$c->call('messages.getHistory',[]),'RPC_DEADLINE');
    check($s->connects===0,'total deadline before connect');
});
test('request limit',function(){
    [$c,$s]=readHarness();for($i=0;$i<30;++$i){$c->call('messages.getHistory',[]);}
    failure(static fn()=>$c->call('messages.getHistory',[]),'RPC_CLOSED_OR_LIMIT');
    check($c->requests()===30&&$s->connects===1&&$s->socket->closes===1,'finite request budget');
});
test('native and raw peer conversion',function(){
    check(Verifier::peer(SOURCE)===SOURCE,'native int peer');
    check(Verifier::peer(['_'=>'peerChannel','channel_id'=>2295574614])===SOURCE,'raw positive channel');
    check(Verifier::peer(['_'=>'peerChannel','channel_id'=>SOURCE])===SOURCE,'canonical array channel');
    foreach([null,'-1002295574614',0,-1000000000000,['_' => 'peerUser','user_id'=>1],['_'=>'peerChannel','channel_id'=>-1]]as$p){check(Verifier::peer($p)===null,'reject non-channel');}
});

function sourceMessage(int $id,?string $group=null):array {
    $m=['_'=>'message','id'=>$id,'peer_id'=>SOURCE,'date'=>1000,'message'=>'SECRET-CAPTION-'.$id,
        'entities'=>[['_'=>'messageEntityBold','offset'=>0,'length'=>3]]];
    if($group!==null){$m['grouped_id']=$group;$m['media']=['_'=>'messageMediaPhoto','photo'=>['_'=>'photo','id'=>50000+$id,'access_hash'=>'SECRET-ACCESS','file_reference'=>'SECRET-REF']];}
    return$m;
}
test('finite media diagnostics never expose content or credentials',function(){
    $shape=Verifier::mediaShape(['_'=>'messageMediaDocument','video'=>false,'video_cover'=>['_'=>'photo','id'=>987],
        'SECRET-UNKNOWN-KEY'=>'SECRET-CAPTION','document'=>['_'=>'document','id'=>123,'access_hash'=>'SECRET-ACCESS',
            'file_reference'=>'SECRET-REF','mime_type'=>'SECRET-MIME','attributes'=>['SECRET-ATTR']]]);
    $json=json_encode($shape,JSON_THROW_ON_ERROR);
    check($shape['constructor']==='messageMediaDocument'&&$shape['other_fields_present'],'finite root diagnostics');
    check(in_array('video_cover',$shape['fields'],true)&&$shape['boolean_flags']['video']===false,'shape fields and boolean');
    check($shape['nested']['document']['constructor']==='document','nested constructor');
    check($shape['nested']['document']['id_native_int']&&$shape['nested']['document']['id_sign']==='positive','finite ID type and sign');
    check(!str_contains($json,'SECRET')&&!str_contains($json,'987')&&!str_contains($json,'123'),'no raw values or unknown field names');
    check(Verifier::mediaShape(['_'=>'SECRET-TYPE'])['constructor']==='other','unknown constructor hidden');
    check(Verifier::mediaShape('SECRET-MEDIA')===['array'=>false],'scalar value hidden');
});
function destination(array $m,int $target,int $id,?string $group=null):array {
    $d=$m;unset($d['grouped_id'],$d['post_author']);$d['id']=$id;$d['peer_id']=$target;$d['date']=1100;
    $d['fwd_from']=['_'=>'messageFwdHeader','from_id'=>SOURCE,'channel_post'=>$m['id'],'date'=>$m['date']];
    if(isset($m['post_author'])){$d['fwd_from']['post_author']=$m['post_author'];}
    if($group!==null){$d['grouped_id']=$group;}return$d;
}
function fixture(int $count=1,bool $album=false):array {
    $source=[];$histories=[SOURCE=>[],TARGET_A=>[],TARGET_B=>[]];$rows=[];
    for($i=0;$i<$count;++$i){$id=26491+$i;$m=sourceMessage($id,$album?'999888777666555444':null);$source[$id]=$m;
        $histories[SOURCE][]=$m;
        foreach([TARGET_A,TARGET_B]as$j=>$target){$histories[$target][]=destination($m,$target,100+$i,$album?(string)(8000000000000+$j):null);
            $rows[]=['source_id'=>SOURCE,'message_id'=>$id,'message_date'=>1000,'grouped_id'=>$m['grouped_id']??null,
                'protected'=>0,'first_seen'=>1000,'target_id'=>$target,'random_id_sha256'=>hash('sha256','synthetic'.$id.$target),
                'status'=>'forwarded','attempts'=>1,'updated_at'=>1100];}
    }
    $histories[SOURCE][]=['_'=>'message','id'=>26490,'peer_id'=>SOURCE,'date'=>900,'message'=>'old'];
    foreach([TARGET_A,TARGET_B]as$t){$histories[$t][]=['_'=>'message','id'=>1,'peer_id'=>$t,'date'=>500,'message'=>'old'];}
    foreach($histories as&$h){usort($h,static fn($a,$b)=>$b['id']<=>$a['id']);}unset($h);
    $snapshot=['metadata'=>['source_id'=>SOURCE,'source_min_id'=>26490,'initialized_at'=>1000,'worker_pid'=>123],
        'targets'=>array_map(static fn($t)=>['source_id'=>SOURCE,'target_id'=>$t,'cutoff'=>26490,'created_at'=>1000],[TARGET_A,TARGET_B]),'rows'=>$rows];
    return ['snapshot'=>$snapshot,'source'=>$source,'histories'=>$histories,'calls'=>[],'selected_reads'=>0,'source_history_reads'=>0,'hook'=>null];
}
function runFixture(array &$f,int $pages=10):array {
    $rpc=static function(string $method,array $args)use(&$f):array{
        $f['calls'][]=[$method,$args];
        if(is_callable($f['hook'])){($f['hook'])($f,$method,$args);}
        if($method==='channels.getMessages'){
            ++$f['selected_reads'];$messages=[];
            foreach($args['id']as$id){if(isset($f['source'][$id['id']])){$messages[]=$f['source'][$id['id']];}}
        }elseif($method==='messages.getHistory'){
            if($args['peer']===SOURCE){++$f['source_history_reads'];}
            $messages=array_values(array_filter($f['histories'][$args['peer']],static fn($m)=>$args['offset_id']===0||$m['id']<$args['offset_id']));
            $messages=array_slice($messages,0,100);
        }else{throw new RuntimeException('Forbidden mock RPC');}
        return ['_'=>'messages.messages','messages'=>$messages];
    };
    return(new Verifier($rpc,str_repeat('K',32),$pages))->verify($f['snapshot']);
}
function failedFixture(array &$f,string $label):array {
    $r=runFixture($f);check(!$r['live_natural_forward_verified'],$label);return$r;
}
test('direct native-shaped post to both targets and redaction',function(){
    $f=fixture();$r=runFixture($f);check($r['live_natural_forward_verified']&&$r['all_audited_messages_verified'],'direct both');
    check($r['verified_source_ids']===[26491]&&!$r['live_album_verified'],'IDs only');
    foreach($r['messages'][0]['targets']as$v){check($v['matched_count']===1&&$v['interval_complete']&&$v['verified'],'complete exact one');}
    $json=json_encode($r);foreach(['SECRET-CAPTION','SECRET-ACCESS','SECRET-REF','synthetic']as$secret){check(!str_contains($json,$secret),'no content or random ID');}
    check((bool)preg_match('/^[a-f0-9]{64}$/D',$r['messages'][0]['source_content_hmac_sha256']),'ephemeral HMAC shape');
});
test('missing and duplicate destination',function(){
    $f=fixture();array_shift($f['histories'][TARGET_B]);$r=failedFixture($f,'missing B');
    check($r['messages'][0]['targets'][1]['matched_count']===0,'absence complete interval');
    $f=fixture();$duplicate=$f['histories'][TARGET_A][0];$duplicate['id']=101;$duplicate['fwd_from']['date']=999;
    array_unshift($f['histories'][TARGET_A],$duplicate);$r=failedFixture($f,'duplicate A even differing date');
    check($r['messages'][0]['targets'][0]['matched_count']===2,'duplicate count not fingerprint filtered');
});
test('header date author origin ID and payload mismatches',function(){
    foreach(['date','author','origin','id','caption','entities','layout','media']as$change){
        $f=fixture();$d=&$f['histories'][TARGET_B][0];
        switch($change){case'date':$d['fwd_from']['date']=999;break;case'author':$d['fwd_from']['post_author']='other';break;
            case'origin':$d['fwd_from']['from_id']=TARGET_A;break;case'id':$d['fwd_from']['channel_post']=26492;break;
            case'caption':$d['message']='changed';break;case'entities':$d['entities']=[];break;
            case'layout':$d['invert_media']=true;break;case'media':$d['media']=['_'=>'messageMediaPhoto','photo'=>['_'=>'photo','id'=>1]];break;}
        unset($d);failedFixture($f,$change.' unequal');
    }
});
test('partial page cap and cursor replay fail closed',function(){
    $f=fixture();$f['histories'][TARGET_A]=[$f['histories'][TARGET_A][0]];
    $r=runFixture($f,1);check(!$r['live_natural_forward_verified']&&!$r['messages'][0]['targets'][0]['interval_complete'],'short page not completeness');
    $f=fixture();$f['histories'][SOURCE]=[$f['histories'][SOURCE][0]];
    $f['hook']=static function(&$f,$method,$args){if($method==='messages.getHistory'&&$args['peer']===SOURCE&&$args['offset_id']!==0){$f['histories'][SOURCE][0]['id']=$args['offset_id']-1;$f['histories'][SOURCE][0]['date']=1001;}};
    failure(static fn()=>runFixture($f),'HISTORY_NOT_CONTIGUOUS');
});
test('deleted numeric gaps accepted; wrong peer rejected',function(){
    $f=fixture(2);$f['histories'][TARGET_A][0]['id']=110;$r=runFixture($f);check($r['live_natural_forward_verified'],'ID gaps not missing pages');
    $f=fixture();$f['histories'][TARGET_B][0]['peer_id']=TARGET_A;failure(static fn()=>runFixture($f),'HISTORY_NOT_CONTIGUOUS');
});
test('document stable metadata excludes authorization references',function(){
    $f=fixture();$media=['_'=>'messageMediaDocument','document'=>['_'=>'document','id'=>7654321,'size'=>123,'mime_type'=>'video/mp4',
        'access_hash'=>'SECRET-ACCESS','file_reference'=>'SECRET-REF','attributes'=>[['_'=>'documentAttributeFilename','file_name'=>'SECRET-FILENAME']]]];
    $f['source'][26491]['media']=$media;
    $f['histories'][SOURCE][0]['media']=$media;
    foreach([TARGET_A,TARGET_B]as$t){$f['histories'][$t][0]['media']=$media;$f['histories'][$t][0]['media']['document']['access_hash']='other';$f['histories'][$t][0]['media']['document']['file_reference']='different';}
    $r=runFixture($f);check($r['live_natural_forward_verified'],'access refs excluded');check(!str_contains(json_encode($r),'SECRET-'),'media report redacted');
    $f['histories'][TARGET_B][0]['media']['document']['size']=124;failedFixture($f,'document size mismatch');
});
test('native alternate video qualities are fingerprinted in full vector order',function(){
    $doc=static fn(int$id):array=>['_'=>'document','id'=>$id,'size'=>123,'mime_type'=>'video/mp4',
        'access_hash'=>'SECRET-ACCESS','file_reference'=>'SECRET-REF',
        'attributes'=>[['_'=>'documentAttributeVideo','duration'=>3.5,'w'=>720,'h'=>1280]]];
    $media=['_'=>'messageMediaDocument','video'=>true,'document'=>$doc(10),'alt_documents'=>[$doc(11),$doc(12)]];
    $f=fixture();$f['source'][26491]['media']=$media;
    $f['histories'][SOURCE][0]['media']=$media;
    foreach([TARGET_A,TARGET_B]as$t){$f['histories'][$t][0]['media']=$media;$f['histories'][$t][0]['media']['alt_documents'][0]['access_hash']='different';}
    $r=runFixture($f);check($r['live_natural_forward_verified']&&$r['all_audited_messages_current_verified'],'native qualities match');
    check(!str_contains(json_encode($r),'SECRET-'),'alternate authorization values hidden');
    foreach(['id','order','size','mime','attributes','missing']as$change){
        $copy=$f;$a=&$copy['histories'][TARGET_B][0]['media']['alt_documents'];
        if($change==='id'){$a[0]['id']=13;}elseif($change==='order'){$a=array_reverse($a);}
        elseif($change==='size'){$a[0]['size']=124;}elseif($change==='mime'){$a[0]['mime_type']='video/webm';}
        elseif($change==='attributes'){$a[0]['attributes'][0]['w']=480;}else{unset($copy['histories'][TARGET_B][0]['media']['alt_documents']);}unset($a);
        $r=failedFixture($copy,'alternate '.$change);check(!$r['live_current_snapshot_verified'],'alternate snapshot mismatch '.$change);
        $v=$r['messages'][0]['targets'][1];$parts=$v['media_component_equal'];$variants=$v['alternate_document_comparison'];
        check($parts['document']&&$parts['dimensions']&&$parts['duration'],'primary document unchanged '.$change);
        check(!$parts['alt_documents']&&$variants['compared_by_index'],'alternate diagnostics do not relax strict gate '.$change);
        check($variants['source_count']===2&&$variants['destination_count']===($change==='missing'?0:2),'bounded alternate counts '.$change);
        if($change==='order'){check(!$parts['alternate_ordered_ids']&&$parts['alternate_unordered_ids'],'same variants reordered');}
        if($change==='attributes'){check(!$variants['variants'][0]['dimensions_equal']&&$variants['variants'][0]['duration_equal'],'dimension mismatch localized');}
        if($change==='id'){check(!$parts['alternate_unordered_ids']&&!$variants['variants'][0]['id_equal'],'different variant ID localized');}
        if($change==='missing'){check(!$variants['variants'][0]['destination_present']&&!$variants['variants'][0]['document_equal'],'missing variant localized');}
        foreach($variants['variants']as$pair){foreach($pair as$key=>$value){check($key==='index'?is_int($value):is_bool($value),'only finite index and equality booleans');}}
        check(!str_contains(json_encode($r),'SECRET-'),'diagnostics hide content and auth '.$change);
    }
    foreach(['nonlist','overcap','emptyconstructor','duplicate','wrongid','wrongsize','wrongmime','wrongattrs','nonvideo','null','wrongflag','wrongttl','wrongattrtag']as$change){
        $copy=$f;$a=&$copy['source'][26491]['media'];
        if($change==='nonlist'){$a['alt_documents']=['x'=>$doc(11)];}
        elseif($change==='overcap'){$a['alt_documents']=array_map($doc,range(20,36));}
        elseif($change==='emptyconstructor'){$a['alt_documents'][0]['_']='documentEmpty';}
        elseif($change==='duplicate'){$a['alt_documents'][1]['id']=11;}
        elseif($change==='wrongid'){$a['alt_documents'][0]['id']='11';}
        elseif($change==='wrongsize'){$a['alt_documents'][0]['size']=-1;}
        elseif($change==='wrongmime'){$a['alt_documents'][0]['mime_type']=12;}
        elseif($change==='wrongattrs'){$a['alt_documents'][0]['attributes']=['x'=>[]];}
        elseif($change==='nonvideo'){$a['video']=false;}elseif($change==='null'){$a['alt_documents']=null;}
        elseif($change==='wrongflag'){$a['video']='true';}elseif($change==='wrongttl'){$a['ttl_seconds']='60';}
        else{$a['alt_documents'][0]['attributes']=['SECRET-INVALID-ATTR'];}unset($a);
        $r=failedFixture($copy,'malformed alternate '.$change);check(!$r['live_current_snapshot_verified'],'invalid alternate snapshot '.$change);
    }
});
test('current snapshots preserve historical edit uncertainty and all non-temporal gates',function(){
    foreach([1100,1150]as$edit){
        $f=fixture();$f['source'][26491]['edit_date']=$edit;$f['histories'][SOURCE][0]['edit_date']=$edit;
        $r=failedFixture($f,'edited initial version unknown');
        check($r['all_audited_messages_current_verified']&&$r['current_verified_source_ids']===[26491],'edited current snapshot complete');
        check(!$r['initial_delivery_payload_verified']&&!$r['edit_synchronization_verified'],'initial version and edit sync unproved');
        foreach($r['messages'][0]['targets']as$v){check(!$v['verified']&&$v['current_verified'],'separate target scopes');
            check($v['source_edit_relation']===($edit===1100?'same_second':'after'),'precise edit relation');}
        $f['histories'][TARGET_B][0]['message']='changed';$r=failedFixture($f,'edited snapshot mismatch');
        check(!$r['live_current_snapshot_verified'],'changed current content blocked');
    }
    $f=fixture();$f['hook']=static function(&$f,$method){if($method==='channels.getMessages'&&$f['selected_reads']===1){$f['source'][26491]['edit_date']=1150;}};
    $r=failedFixture($f,'source changes within observation');check(!$r['live_current_snapshot_verified'],'source envelope mutation blocks current');
    $f=fixture(3,true);$f['source'][26491]['edit_date']=1150;
    foreach($f['histories'][SOURCE]as&$m){if($m['id']===26491){$m['edit_date']=1150;}}unset($m);
    $r=failedFixture($f,'edited album historical uncertainty');
    check($r['live_current_album_verified']&&$r['all_audited_messages_current_verified']&&!$r['live_album_verified'],'complete edited album has only current proof');
    $f['histories'][TARGET_B][0]['grouped_id']='split';$r=failedFixture($f,'edited split album');
    check(!$r['live_current_snapshot_verified']&&!$r['live_current_album_verified'],'album shape blocks both current gates');
    foreach($r['messages']as$m){check(!$m['current_verified'],'every invalid album member cleared');}
    $finalFixture=fixture(3,true);$sealed=Verifier::seal(runFixture($finalFixture),false);
    check(!$sealed['live_current_snapshot_verified']&&!$sealed['live_current_album_verified']&&$sealed['current_verified_source_ids']===[],'state seal clears new scope');
    foreach([...$sealed['messages'],...$sealed['albums']]as$m){check(!$m['current_verified'],'sealed current item');foreach($m['targets']as$t){check(!$t['current_verified'],'sealed current target');}}
});
test('known source history contradictions invalidate every current and historical scope',function(){
    foreach(['caption','edit_date','media','missing']as$change){
        $f=fixture();$f['hook']=static function(&$f,$method,$args)use($change){
            if($method==='messages.getHistory'&&$args['peer']===SOURCE&&$f['selected_reads']===2){
                if($change==='missing'){$f['histories'][SOURCE]=array_values(array_filter($f['histories'][SOURCE],static fn($m)=>$m['id']!==26491));}
                else{foreach($f['histories'][SOURCE]as&$m){if($m['id']===26491){
                    if($change==='caption'){$m['message']='changed';}elseif($change==='edit_date'){$m['edit_date']=1150;}
                    else{$m['media']=['_'=>'messageMediaPhoto','photo'=>['_'=>'photo','id'=>987]];}
                }}unset($m);}
            }
        };
        $r=failedFixture($f,'final source history '.$change);
        check(!$r['live_current_snapshot_verified']&&!$r['all_audited_messages_current_verified'],'known contradictory observation blocks current '.$change);
        check(!$r['messages'][0]['source_history_observations_equal'],'explicit history conflict');
    }
});
test('unsupported variants and forward chains inconclusive',function(){
    foreach(['live_photo','video','alt_documents','video_cover','video_timestamp','empty']as$variant){
        $f=fixture(1,true);$m=&$f['source'][26491]['media'];
        if($variant==='empty'){$m['photo']['_']='photoEmpty';}elseif(in_array($variant,['live_photo','video'],true)){$m[$variant]=true;}
        else{$m=['_'=>'messageMediaDocument','document'=>['_'=>'document','id'=>1],$variant=>[]];}unset($m);
        failedFixture($f,'unsupported '.$variant);
    }
    $f=fixture();$f['source'][26491]['fwd_from']=['from_id'=>TARGET_A,'channel_post'=>1];$r=failedFixture($f,'forward chain');
    check($r['messages'][0]['reason']==='unsupported_forward_chain','explicit forward chain scope');
});
test('source edit and change during audit',function(){
    $f=fixture();$f['source'][26491]['edit_date']=1100;failedFixture($f,'edit after ACK');
    $f=fixture();$f['hook']=static function(&$f,$method){if($method==='channels.getMessages'&&$f['selected_reads']===1){$f['source'][26491]['message']='edited';}};
    $r=failedFixture($f,'source changed between reads');check($r['messages'][0]['source_changed_during_audit'],'change flag');
});
test('absent and invalid destination groups cannot prove an album',function(){
    foreach([null,0,'0','',true,[], 'invalid','00']as$invalid){
        $f=fixture(3,true);
        foreach($f['histories'][TARGET_B]as&$m){if(isset($m['fwd_from'])){$m['grouped_id']=$invalid;}}unset($m);
        $r=failedFixture($f,'destination absent or invalid group');
        check(!$r['live_current_snapshot_verified']&&!$r['live_current_album_verified'],'invalid destination group clears current album');
        check(!$r['albums'][0]['targets'][1]['group_consistent'],'requires canonical nonempty group');
        foreach($r['messages']as$m){check(!$m['current_verified']&&!$m['verified'],'all members invalidated by absent group');}
    }
});
test('three-part album independent destination groups',function(){
    $f=fixture(3,true);$r=runFixture($f);check($r['live_album_verified']&&$r['live_natural_forward_verified'],'complete album');
    check($r['albums'][0]['source_member_ids']===[26491,26492,26493],'all three source IDs');
    foreach($r['albums'][0]['targets']as$v){check($v['verified']&&$v['member_count_equal']&&$v['order_equal'],'album shape/order');}
    check(!str_contains(json_encode($r),'999888777666555444'),'source group HMAC only');
});
test('missing source member late member split reverse extra groups',function(){
    foreach(['missing','late','split','reverse','extra']as$kind){
        $f=fixture(3,true);
        if($kind==='missing'){$f['snapshot']['rows']=array_values(array_filter($f['snapshot']['rows'],static fn($r)=>$r['message_id']!==26493));}
        elseif($kind==='late'){$f['hook']=static function(&$f,$method,$args){if($method==='messages.getHistory'&&$args['peer']===SOURCE&&$f['source_history_reads']===1){array_unshift($f['histories'][SOURCE],sourceMessage(26494,'999888777666555444'));}};}
        elseif($kind==='split'){$f['histories'][TARGET_A][0]['grouped_id']='split';}
        elseif($kind==='reverse'){$f['histories'][TARGET_A][0]['id']=100;$f['histories'][TARGET_A][2]['id']=102;usort($f['histories'][TARGET_A],static fn($a,$b)=>$b['id']<=>$a['id']);}
        else{$extra=$f['histories'][TARGET_A][0];$extra['id']=103;unset($extra['fwd_from']);array_unshift($f['histories'][TARGET_A],$extra);}
        $r=failedFixture($f,'album '.$kind);check(!$r['live_album_verified']&&$r['albums'][0]['verified']===false,'album gate '.$kind);
        foreach($r['messages']as$m){check(!$m['verified']&&($m['album_incomplete']??false),'member invalidated '.$kind);}
    }
});
test('activation-crossing album never passes',function(){
    $f=fixture(2,true);$old=sourceMessage(26490,'999888777666555444');$old['date']=900;$f['histories'][SOURCE][2]=$old;
    $r=failedFixture($f,'partial at activation');check($r['albums'][0]['partial_at_activation'],'activation partial flag');
});
test('common target cutoff selection and pending rows do not block later direct proof',function(){
    $f=fixture(3);$f['snapshot']['targets'][1]['cutoff']=26491;
    foreach($f['snapshot']['rows']as&$row){if($row['message_id']===26492){$row['status']='skipped';}}unset($row);
    $r=runFixture($f);check($r['verified_source_ids']===[26493]&&$r['deferred_source_count']===2,'eligible subset');
    foreach($f['calls']as[$method,$args]){if($method==='channels.getMessages'){check(array_column($args['id'],'id')===[26493],'no selected RPC before common cutoff');}}
    $f=fixture();$f['snapshot']['rows'][0]['status']='pending';failure(static fn()=>runFixture($f),'AWAITING_NATURAL_POST');check($f['calls']===[],'no read before complete eligibility');
});
test('missing selected source and invalid history constructors',function(){
    $f=fixture();unset($f['source'][26491]);failure(static fn()=>runFixture($f),'SOURCE_MESSAGE_MISSING');
    $v=new Verifier(static fn()=>['_'=>'messages.messagesNotModified','messages'=>[]],str_repeat('K',32));
    failure(static fn()=>$v->history(SOURCE,0,26490),'MESSAGES_RESULT_INVALID');
});

test('late ledger changes invalidate every report gate',function(){
    $f=fixture(3,true);$r=runFixture($f);check($r['live_album_verified'],'initial complete mock');
    check(Verifier::seal($r,true)===$r,'stable report retained');
    $sealed=Verifier::seal($r,false);
    check(!$sealed['live_natural_forward_verified']&&!$sealed['live_album_verified']&&!$sealed['all_audited_messages_verified'],'late ledger global flags');
    check($sealed['verified_source_ids']===[]&&$sealed['reason']==='AUDIT_STATE_CHANGED','late state inconclusive');
    foreach([...$sealed['messages'],...$sealed['albums']]as$item){check(!$item['verified'],'late nested gate');foreach($item['targets']as$t){check(!$t['verified'],'late target gate');}}
});
test('hosting clock skew still scans duplicate on later page',function(){
    $f=fixture();$f['snapshot']['metadata']['initialized_at']=1600;
    foreach($f['snapshot']['targets']as&$t){$t['created_at']=1600;}unset($t);
    foreach($f['snapshot']['rows']as&$row){$row['first_seen']=1600;$row['updated_at']=1700;}unset($row);
    $history=[];for($i=0;$i<100;++$i){$history[]=['_'=>'message','id'=>300-$i,'peer_id'=>TARGET_A,'date'=>1200,'message'=>'irrelevant'];}
    $copy=destination($f['source'][26491],TARGET_A,350);$copy['date']=1200;array_unshift($history,$copy);
    $history[]=destination($f['source'][26491],TARGET_A,150);$history[]=['_'=>'message','id'=>1,'peer_id'=>TARGET_A,'date'=>500,'message'=>'old'];
    $f['histories'][TARGET_A]=$history;$r=failedFixture($f,'clock skew duplicate');
    check($r['messages'][0]['targets'][0]['matched_count']===2&&$r['target_intervals'][TARGET_A]['pages']===2,'clock-safe full interval');
    $f=fixture();foreach($f['snapshot']['rows']as&$row){$row['updated_at']=1700;}unset($row);
    $f['source'][26491]['edit_date']=1150;$r=failedFixture($f,'Telegram edit clock');check($r['messages'][0]['source_edit_after_delivery'],'edit compared to Telegram destination date');
});
test('single grouped item and mixed evidence retain honest scope',function(){
    $f=fixture(1,true);$r=failedFixture($f,'one member is not album proof');check(!$r['live_album_verified']&&!$r['albums'][0]['multiple_members'],'requires multiple parts');
    $f=fixture(2);$f['source'][26492]['fwd_from']=['from_id'=>TARGET_A,'channel_post'=>1];$r=runFixture($f);
    check($r['live_natural_forward_verified']&&!$r['all_audited_messages_verified']&&$r['verified_source_ids']===[26491],'partial direct proof explicitly scoped');
});
test('source envelope changes invalidate equal-content proof',function(){
    foreach(['post_author','grouped_id','edit_date','noforwards','date']as$field){
        $f=fixture();$f['hook']=static function(&$f,$method)use($field){if($method==='channels.getMessages'&&$f['selected_reads']===1){$f['source'][26491][$field]=in_array($field,['date','edit_date'],true)?1101:'changed';}};
        $r=failedFixture($f,'envelope '.$field);check($r['messages'][0]['source_changed_during_audit'],'envelope flag '.$field);
    }
});
test('native layer225 ordinary photo false flag is supported',function(){
    $f=fixture(2,true);
    foreach($f['source']as&$m){$m['media']['live_photo']=false;$m['media']['spoiler']=false;}unset($m);
    foreach([TARGET_A,TARGET_B]as$t){foreach($f['histories'][$t]as&$m){if(isset($m['media'])){$m['media']['live_photo']=false;$m['media']['spoiler']=false;}}unset($m);}
    $r=runFixture($f);check($r['live_album_verified'],'native false live_photo supported');
});
test('changed album with stable standalone never claims album verification',function(){
    foreach(['changed','edited']as$kind){
        $f=fixture(3,true);$id=26494;$m=sourceMessage($id);$f['source'][$id]=$m;array_unshift($f['histories'][SOURCE],$m);
        foreach([TARGET_A,TARGET_B]as$t){
            array_unshift($f['histories'][$t],destination($m,$t,103));
            $row=array_values(array_filter($f['snapshot']['rows'],static fn($row)=>$row['target_id']===$t))[0];
            $row['message_id']=$id;$row['grouped_id']=null;$row['random_id_sha256']=hash('sha256','standalone'.$t);$f['snapshot']['rows'][]=$row;
        }
        if($kind==='changed'){$f['hook']=static function(&$f,$method){if($method==='channels.getMessages'&&$f['selected_reads']===1){$f['source'][26491]['message']='changed album caption';}};}
        else{$f['source'][26491]['edit_date']=1150;}
        $r=runFixture($f);check($r['live_natural_forward_verified']&&!$r['live_album_verified'],'standalone proof does not promote '.$kind.' album');
        check($r['verified_source_ids']===[26494]&&!$r['albums'][0]['source_members_verified'],'only stable standalone retained');
    }
});
test('hidden deleted pending middle member remains incomplete from ledger',function(){
    $f=fixture(3,true);unset($f['source'][26492]);
    $f['histories'][SOURCE]=array_values(array_filter($f['histories'][SOURCE],static fn($m)=>$m['id']!==26492));
    foreach([TARGET_A,TARGET_B]as$t){$f['histories'][$t]=array_values(array_filter($f['histories'][$t],static fn($m)=>$m['id']!==101));}
    foreach($f['snapshot']['rows']as&$row){if($row['message_id']===26492){$row['status']='pending';}}unset($row);
    $r=failedFixture($f,'known pending middle deleted from source');
    check(!$r['live_album_verified']&&!$r['albums'][0]['known_ledger_members_complete'],'ledger prevents incomplete album proof');
    check($r['albums'][0]['known_ledger_member_ids']===[26491,26492,26493]&&$r['albums'][0]['source_member_ids']===[26491,26493],'known source gap explicit');
});
test('ledger grouping cannot be silently reclassified as standalone',function(){
    $f=fixture(3,true);unset($f['source'][26492]);
    foreach($f['source']as&$m){unset($m['grouped_id']);}unset($m);
    $f['histories'][SOURCE]=array_values(array_filter($f['histories'][SOURCE],static fn($m)=>$m['id']!==26492));
    foreach($f['histories'][SOURCE]as&$m){unset($m['grouped_id']);}unset($m);
    foreach([TARGET_A,TARGET_B]as$t){$f['histories'][$t]=array_values(array_filter($f['histories'][$t],static fn($m)=>$m['id']!==101));foreach($f['histories'][$t]as&$m){unset($m['grouped_id']);}unset($m);}
    foreach($f['snapshot']['rows']as&$row){if($row['message_id']===26492){$row['status']='pending';}}unset($row);
    $r=failedFixture($f,'ledger group disappeared from stable source');
    foreach($r['messages']as$m){check(!$m['source_group_matches_ledger']&&!$m['verified'],'group contradiction invalidates direct proof');}
    $f=fixture();foreach($f['snapshot']['rows']as&$row){$row['grouped_id']='77';}unset($row);
    failedFixture($f,'source null vs ledger group');
});

fwrite(STDOUT,json_encode(['passed'=>true,'synthetic'=>true,'cases'=>$cases,'assertions'=>$checks,
    'php_version'=>PHP_VERSION,'telegram_rpc_executed'=>false,'session_loaded'=>false],JSON_THROW_ON_ERROR)."\n");
