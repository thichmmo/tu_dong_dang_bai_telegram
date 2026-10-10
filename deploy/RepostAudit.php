<?php
declare(strict_types=1);
namespace TelegramMirror\Audit;

/** Corrected-mode evidence: explicit destination IDs, no forwarding headers, primary files. */
final class RepostAudit
{
    public function __construct(private mixed $rpc,private string $key){}
    private static function clean(array $v):array{
        foreach($v as$k=>&$x){if(in_array($k,['access_hash','file_reference','thumbs','video_thumbs','bytes'],true)){unset($v[$k]);}elseif(is_array($x)){$x=self::clean($x);}}unset($x);return $v;
    }
    public static function semantic(array $m):array{
        $media=$m['media']??['_'=>'messageMediaEmpty'];$type=$media['_']??'';
        $s=['type'=>$type,'spoiler'=>(bool)($media['spoiler']??false),'ttl_seconds'=>$media['ttl_seconds']??null];
        if($type==='messageMediaPhoto'){
            if(!is_int($media['photo']['id']??null)||$media['photo']['id']<1||!empty($media['live_photo'])){throw new AuditFailure('REPOST_PHOTO_INVALID');}
            $s['id']=$media['photo']['id'];
        }elseif($type==='messageMediaDocument'){
            $d=$media['document']??[];
            if(!is_int($d['id']??null)||$d['id']<1||!is_string($d['mime_type']??null)||!is_int($d['size']??null)||!is_array($d['attributes']??null)){throw new AuditFailure('REPOST_DOCUMENT_INVALID');}
            $s+=['id'=>$d['id'],'mime_type'=>$d['mime_type'],'size'=>$d['size'],'attributes'=>self::clean($d['attributes']),
                'video_cover_id'=>$media['video_cover']['id']??null,'video_timestamp'=>$media['video_timestamp']??null];
        }elseif($type==='messageMediaWebPage'){
            // Link preview rendering is explicitly outside exact primary-file scope.
            $s=['type'=>'text'];
        }elseif($type==='messageMediaEmpty'){$s=['type'=>'text'];}
        else{throw new AuditFailure('REPOST_MEDIA_UNSUPPORTED');}
        return ['message'=>$m['message']??'','entities'=>$m['entities']??[],'invert_media'=>(bool)($m['invert_media']??false),'primary_media'=>$s];
    }
    private function fp(array $m):string{return hash_hmac('sha256',json_encode(Verifier::canonical(self::semantic($m)),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$this->key);}
    private static function group(array $m):?string{$g=$m['grouped_id']??null;return $g===null||$g===0||$g==='0'?null:(string)$g;}
    public function verify(array $rows,array $frozen,int $activated):array{
        if($rows===[]){throw new AuditFailure('AWAITING_NEW_REPOST_DELIVERIES');}
        $ids=[];$targets=[];$destinations=[];
        foreach($rows as$r){
            if($r['source_id']!==-1002295574614||$r['delivery_mode']!=='repost'||$r['status']!=='forwarded'||!is_int($r['destination_message_id'])||$r['destination_message_id']<1){throw new AuditFailure('NEW_REPOST_LEDGER_NOT_SETTLED');}
            $ids[$r['message_id']]=true;$targets[$r['target_id']]=true;$destinations[$r['target_id']][$r['message_id']]=$r['destination_message_id'];
        }
        $ids=array_keys($ids);sort($ids);$targetIds=array_keys($targets);sort($targetIds);
        if($targetIds!==[-1004374103992,-1003718740756]){throw new AuditFailure('EXPECTED_TWO_REPOST_TARGETS');}
        foreach($targetIds as$t){if(array_keys($destinations[$t])!==$ids||count(array_unique($destinations[$t]))!==count($ids)){throw new AuditFailure('REPOST_ACK_MAP_INCOMPLETE');}}
        $v=new Verifier($this->rpc,$this->key,10);$source=-1002295574614;
        $a=$v->selected($source,$ids);$lower=min(array_column($a,'date'));
        $h1=$v->history($source,$lower,26490);if(!$h1['interval']['complete']){throw new AuditFailure('SOURCE_HISTORY_INCOMPLETE');}
        $historyLower=max($activated,min(array_map(static fn($r):int=>$r['first_seen']??$activated,$rows)));
        $histories=[];foreach($targetIds as$t){$histories[$t]=$v->history($t,$historyLower);if(!$histories[$t]['interval']['complete']){throw new AuditFailure('DESTINATION_HISTORY_INCOMPLETE');}}
        $b=$v->selected($source,$ids);$h2=$v->history($source,$lower,26490);if(!$h2['interval']['complete']){throw new AuditFailure('SOURCE_FINAL_HISTORY_INCOMPLETE');}
        $fps=[];$groups=[];
        foreach($ids as$id){
            $captured=$frozen[$id]??[];$original=$captured['source']??[];
            if(($original['_']??null)!=='message'||($original['id']??null)!==$id||Verifier::peer($original['peer_id']??null)!==$source
                ||($captured['message']??null)!==($original['message']??'')||($captured['entities']??null)!==($original['entities']??[])
                ||($captured['invert_media']??null)!==(bool)($original['invert_media']??false)||self::group($original)!==self::group($a[$id])){throw new AuditFailure('FROZEN_SOURCE_ENVELOPE_INVALID');}
            $sourceMedia=$original['media']??[];$input=$captured['media']??null;$sourceType=$sourceMedia['_']??null;
            if(in_array($sourceType,['messageMediaPhoto','messageMediaDocument'],true)){
                $photo=$sourceType==='messageMediaPhoto';$field=$photo?'photo':'document';
                if(!is_array($input)||($input['_']??null)!==($photo?'inputMediaPhoto':'inputMediaDocument')
                    ||($input['id']['_']??null)!==($photo?'inputPhoto':'inputDocument')||($input['id']['id']??null)!==($sourceMedia[$field]['id']??null)
                    ||(bool)($input['spoiler']??false)!==(bool)($sourceMedia['spoiler']??false)||($input['ttl_seconds']??null)!==($sourceMedia['ttl_seconds']??null)
                    ||($input['video_cover']['id']??null)!==($sourceMedia['video_cover']['id']??null)||($input['video_timestamp']??null)!==($sourceMedia['video_timestamp']??null)){throw new AuditFailure('FROZEN_MEDIA_SEND_FIELDS_INVALID');}
            }elseif($input!==null){throw new AuditFailure('FROZEN_TEXT_SEND_FIELDS_INVALID');}
            foreach($rows as$row){if($row['message_id']===$id&&self::group(['grouped_id'=>$row['grouped_id']])!==self::group($original)){throw new AuditFailure('LEDGER_SOURCE_GROUP_CHANGED');}}
            $fp=$this->fp($original);$fps[$id]=$fp;
            foreach([$a[$id],$b[$id],$h1['messages'][$id]??[],$h2['messages'][$id]??[]]as$m){
                if(($m['_']??null)!=='message'||$this->fp($m)!==$fp||self::group($m)!==self::group($a[$id])||!empty($m['noforwards'])){throw new AuditFailure('SOURCE_SNAPSHOT_UNSTABLE');}
            }
            $g=self::group($a[$id]);if($g!==null){$groups[$g][]=$id;}
        }
        foreach($groups as$g=>$members){
            foreach([$h1,$h2]as$h){$seen=[];foreach($h['messages']as$m){if(self::group($m)===(string)$g){$seen[]=$m['id'];}}sort($seen);if($seen!==$members||count($members)<2){throw new AuditFailure('SOURCE_ALBUM_INCOMPLETE');}}
        }
        $proof=[];$albumProof=[];
        foreach($targetIds as$t){
            $history=$histories[$t]['messages'];$expectedCounts=array_count_values(array_values($fps));$actualCounts=[];
            foreach($history as$m){if(($m['_']??'')!=='message'||$m['date']<$historyLower){continue;}
                try{$fp=$this->fp($m);}catch(AuditFailure){continue;}
                if(isset($expectedCounts[$fp])){$actualCounts[$fp]=($actualCounts[$fp]??0)+1;}
            }
            foreach($expectedCounts as$fp=>$count){if(($actualCounts[$fp]??0)!==$count){throw new AuditFailure('REPOST_DUPLICATE_OR_MISSING_PAYLOAD');}}
            foreach($ids as$id){$dest=$destinations[$t][$id];$m=$history[$dest]??[];
                if(($m['_']??null)!=='message'||!is_int($m['date']??null)||$m['date']<$historyLower||Verifier::peer($m['peer_id']??null)!==$t||isset($m['fwd_from'])||!empty($m['video_processing_pending'])||$this->fp($m)!==$fps[$id]){throw new AuditFailure('DESTINATION_REPOST_MISMATCH');}
                if(self::group($a[$id])===null&&self::group($m)!==null){throw new AuditFailure('UNEXPECTED_DESTINATION_ALBUM');}
                $proof[]=['source_message_id'=>$id,'target_id'=>$t,'destination_message_id'=>$dest,'forward_header_absent'=>true,'caption_entities_layout_primary_media_equal'=>true,'matching_copy_count'=>1,'primary_media_type'=>self::semantic($frozen[$id]['source'])['primary_media']['type']];
            }
            foreach($groups as$g=>$members){$destIds=array_map(fn($id)=>$destinations[$t][$id],$members);$actualGroup=self::group($history[$destIds[0]]);$seen=[];
                if($actualGroup===null){throw new AuditFailure('DESTINATION_ALBUM_GROUP_MISSING');}
                foreach($history as$m){if(self::group($m)===$actualGroup){$seen[]=$m['id'];}}sort($seen);
                $sorted=$destIds;sort($sorted);if($seen!==$destIds||$sorted!==$destIds){throw new AuditFailure('DESTINATION_ALBUM_ORDER_OR_MEMBERSHIP');}
                $albumProof[]=['target_id'=>$t,'source_message_ids'=>$members,'destination_message_ids'=>$destIds,'complete_ordered_unique'=>true];
            }
        }
        return ['passed'=>true,'native_repost_verified'=>true,'live_repost_album_verified'=>$groups!==[],
            'source_message_ids'=>$ids,'messages'=>$proof,'albums'=>$albumProof,'history_intervals'=>array_map(fn($h)=>$h['interval'],$histories),
            'proof_scope'=>'Stable natural new source snapshots equal new destination posts with no fwd_from, preserved text/entities/layout and primary photo/document metadata. Full bounded cohort history contains the expected number of matching copies; albums complete and ordered. Alternative video renditions and edited/deleted source synchronization are outside scope.'];
    }
}
