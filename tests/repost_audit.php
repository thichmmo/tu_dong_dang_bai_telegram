<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/deploy/NaturalAudit.php';require_once dirname(__DIR__).'/deploy/RepostAudit.php';
use TelegramMirror\Audit\RepostAudit;
use TelegramMirror\Audit\AuditFailure;
$assertions=0;$cases=0;
function raCheck(bool $v):void{global $assertions;++$assertions;if(!$v){throw new RuntimeException('Repost audit fixture failed.');}}
function raFixture():array{
 $source=-1002295574614;$targets=[-1004374103992,-1003718740756];$activation=1000;$sourceMessages=[];$rows=[];$frozen=[];$history=[];
 foreach([26497,26498]as$i=>$id){
  $m=['_'=>'message','id'=>$id,'date'=>1010,'peer_id'=>$source,'message'=>'caption '.$i,'entities'=>[],'grouped_id'=>888,
   'media'=>['_'=>'messageMediaPhoto','photo'=>['_'=>'photo','id'=>777+$i]]];
  $sourceMessages[$id]=$m;$frozen[$id]=['version'=>1,'source'=>$m,'message'=>$m['message'],'entities'=>[],'invert_media'=>false,
   'media'=>['_'=>'inputMediaPhoto','id'=>['_'=>'inputPhoto','id'=>777+$i]]];
  foreach($targets as$j=>$t){$dest=11+$i+$j*10;$rows[]=['source_id'=>$source,'message_id'=>$id,'target_id'=>$t,'destination_message_id'=>$dest,'delivery_mode'=>'repost','status'=>'forwarded','grouped_id'=>'888'];
   $post=$m;$post['id']=$dest;$post['date']=1011;$post['peer_id']=$t;$post['grouped_id']=999+$j;$history[$t][$dest]=$post;}
 }
 $history[$source]=$sourceMessages;
 foreach([$source,...$targets]as$peer){$old=['_'=>'message','id'=>$peer===$source?26490:1,'date'=>900,'peer_id'=>$peer,'message'=>'old'];$history[$peer][$old['id']]=$old;krsort($history[$peer]);}
 return compact('source','targets','activation','sourceMessages','rows','frozen','history');
}
function raRun(array $f):array{
 $rpc=static function(string $method,array $args)use($f):array{
  if($method==='channels.getMessages'){$ids=array_column($args['id'],'id');$m=array_values(array_intersect_key($f['sourceMessages'],array_flip($ids)));}
  else{$m=array_values($f['history'][$args['peer']]);if(($args['offset_id']??0)>0){$m=array_values(array_filter($m,fn($v)=>$v['id']<$args['offset_id']));}}
  return ['_'=>'messages.channelMessages','messages'=>$m];
 };
 return (new RepostAudit($rpc,str_repeat('k',32)))->verify($f['rows'],$f['frozen'],$f['activation']);
}
function raReject(callable $change):void{global $cases;++$cases;$f=raFixture();$change($f);try{raRun($f);}catch(AuditFailure){raCheck(true);return;}raCheck(false);}
$f=raFixture();$r=raRun($f);++$cases;raCheck($r['native_repost_verified']);raCheck($r['live_repost_album_verified']);raCheck(count($r['messages'])===4);raCheck(count($r['albums'])===2);
raReject(function(&$f){$f['history'][$f['targets'][0]][11]['fwd_from']=['from_id'=>$f['source']];});
raReject(function(&$f){$f['history'][$f['targets'][0]][11]['media']['photo']['id']=99999;});
raReject(function(&$f){$copy=$f['history'][$f['targets'][0]][11];$copy['id']=13;$f['history'][$f['targets'][0]][13]=$copy;krsort($f['history'][$f['targets'][0]]);});
raReject(function(&$f){foreach($f['sourceMessages'] as&$m){unset($m['grouped_id']);}unset($m);foreach($f['frozen']as&$p){unset($p['source']['grouped_id']);}unset($p);foreach($f['rows']as&$row){$row['grouped_id']=null;}unset($row);foreach($f['history']as&$h){foreach($h as&$m){unset($m['grouped_id']);}unset($m);}unset($h);$t=$f['targets'][0];$copy=$f['history'][$t][11];$copy['id']=13;$f['history'][$t][13]=$copy;$f['history'][$t][11]['date']=900;$f['history'][$t][11]['grouped_id']=null;uasort($f['history'][$t],fn($a,$b)=>$b['date']<=>$a['date']?:$b['id']<=>$a['id']);});
raReject(function(&$f){$copy=$f['sourceMessages'][26498];$copy['id']=26499;$f['history'][$f['source']][26499]=$copy;krsort($f['history'][$f['source']]);});
raReject(function(&$f){[$f['rows'][0]['destination_message_id'],$f['rows'][2]['destination_message_id']]=[12,11];});
raReject(function(&$f){$copy=$f['history'][$f['targets'][0]][12];$copy['id']=13;$copy['message']='unrelated extra album part';$f['history'][$f['targets'][0]][13]=$copy;krsort($f['history'][$f['targets'][0]]);});
raReject(function(&$f){$f['sourceMessages'][26497]['message']='edited';});
raReject(function(&$f){$f['frozen'][26497]['message']='different send body';});
raReject(function(&$f){$f['frozen'][26497]['media']['id']['id']=123456;});
raReject(function(&$f){$f['frozen'][26497]['media']['spoiler']=true;});
raReject(function(&$f){$f['frozen'][26497]['source']['grouped_id']=null;});
raReject(function(&$f){$f['rows'][0]['grouped_id']=null;});
raReject(function(&$f){$f['rows'][0]['destination_message_id']=null;});
raReject(function(&$f){$f['rows'][0]['source_id']=-1009999999999;});
raReject(function(&$f){$f['history'][$f['targets'][0]][11]['video_processing_pending']=true;});
echo 'Repost audit: '.$assertions.' assertions passed; '.$cases." cases.\n";
