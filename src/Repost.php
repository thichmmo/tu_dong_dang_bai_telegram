<?php
declare(strict_types=1);
namespace TelegramMirror;
use RuntimeException;

final class RepostFailure extends RuntimeException {}

/** Raw Telegram copy payloads. No download, forward attribution or text parsing. */
final class Repost
{
    public static function read(object $api,array $batch):array
    {
        $response=$api->channels->getMessages(channel:$batch['source_id'],id:array_map(static fn(int $id):array=>['_'=>'inputMessageID','id'=>$id],$batch['ids']));
        $indexed=[];
        foreach($response['messages']??[]as$message){
            if(($message['_']??'')!=='message'||!is_int($message['id']??null)
                ||!in_array($message['id'],$batch['ids'],true)||isset($indexed[$message['id']])
                ||$api->getId($message)!==$batch['source_id']){throw new RepostFailure('SOURCE_MESSAGE_SET_INVALID');}
            if(!empty($message['noforwards'])){throw new RepostFailure('CHAT_FORWARDS_RESTRICTED');}
            $indexed[$message['id']]=$message;
        }
        if(count($indexed)!==count($batch['ids'])){throw new RepostFailure('SOURCE_MESSAGE_SET_INCOMPLETE');}
        return $indexed;
    }

    public static function capture(object $api,array $batch,?array $indexed=null):array
    {
        $indexed ??= self::read($api,$batch);
        $result=[];
        foreach($batch['ids']as$id){
            $m=$indexed[$id];$text=$m['message']??'';$entities=$m['entities']??[];
            if(!is_string($text)||!is_array($entities)||!array_is_list($entities)){throw new RepostFailure('SOURCE_TEXT_INVALID');}
            $media=$m['media']??null;$type=$media['_']??null;$input=null;$family='text';
            if($type==='messageMediaPhoto'||$type==='messageMediaDocument'){
                if($type==='messageMediaPhoto'&&(!empty($media['live_photo'])||isset($media['video']))){throw new RepostFailure('UNSUPPORTED_LIVE_PHOTO');}
                $info=$api->getFileInfo($media);$input=$info['InputMedia']??null;
                $wanted=$type==='messageMediaPhoto'?'inputMediaPhoto':'inputMediaDocument';
                if(!is_array($input)||($input['_']??'')!==$wanted){throw new RepostFailure('SOURCE_MEDIA_REFERENCE_INVALID');}
                foreach(['spoiler','ttl_seconds','video_timestamp']as$key){if(array_key_exists($key,$media)){$input[$key]=$media[$key];}}
                if(isset($media['video_cover'])){
                    $cover=$api->getFileInfo($media['video_cover'])['InputPhoto']??null;
                    if(!is_array($cover)||($cover['_']??'')!=='inputPhoto'){throw new RepostFailure('SOURCE_VIDEO_COVER_INVALID');}
                    $input['video_cover']=$cover;
                }
                $family=$type==='messageMediaPhoto'?'visual':'document';
                foreach($media['document']['attributes']??[]as$a){
                    if(($a['_']??'')==='documentAttributeVideo'){$family='visual';break;}
                    if(($a['_']??'')==='documentAttributeAudio'){$family='audio';}
                }
            }elseif($type!==null&&$type!=='messageMediaEmpty'&&$type!=='messageMediaWebPage'){
                throw new RepostFailure('UNSUPPORTED_REPOST_MEDIA');
            }
            if($input===null&&$text===''){throw new RepostFailure('EMPTY_REPOST_MESSAGE');}
            $result[$id]=['version'=>1,'source'=>$m,'message'=>$text,'entities'=>$entities,
                'media'=>$input,'family'=>$family,'invert_media'=>!empty($m['invert_media']),
                'no_webpage'=>$type!=='messageMediaWebPage'];
        }
        return $result;
    }

    public static function send(object $api,int $target,array $batch,array $payloads):array
    {
        $parts=[];$family=null;$invert=null;
        foreach($batch['ids']as$i=>$id){
            $p=$payloads[$id]??throw new RepostFailure('FROZEN_PAYLOAD_MISSING');
            if(count($batch['ids'])>1){
                if($p['media']===null||($family!==null&&$family!==$p['family'])||($invert!==null&&$invert!==$p['invert_media'])){
                    throw new RepostFailure('UNSUPPORTED_REPOST_ALBUM');
                }
                $family=$p['family'];$invert=$p['invert_media'];
                $parts[]=['_'=>'inputSingleMedia','media'=>$p['media'],'random_id'=>$batch['random_ids'][$i],
                    'message'=>$p['message'],'entities'=>$p['entities']];
            }
        }
        if(count($batch['ids'])>10){throw new RepostFailure('REPOST_ALBUM_TOO_LARGE');}
        if(count($batch['ids'])>1){return $api->messages->sendMultiMedia(peer:$target,multi_media:$parts,invert_media:$invert);}
        $p=$payloads[$batch['ids'][0]];$random=$batch['random_ids'][0];
        if($p['media']===null){return $api->messages->sendMessage(peer:$target,message:$p['message'],entities:$p['entities'],
            random_id:$random,no_webpage:$p['no_webpage'],invert_media:$p['invert_media']);}
        return $api->messages->sendMedia(peer:$target,media:$p['media'],message:$p['message'],entities:$p['entities'],
            random_id:$random,invert_media:$p['invert_media']);
    }

    /** UTF-16 units match Telegram's entity offsets, including surrogate pairs. */
    public static function utf16Length(string $text):int
    {
        $points=preg_match_all('/./us',$text);
        $surrogates=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$text);
        if($points===false||$surrogates===false){throw new RepostFailure('SOURCE_TEXT_INVALID');}
        return $points+$surrogates;
    }

    /** Render a separate immutable transport plan; the original snapshot is retained. */
    public static function overflowMedia(array $batch,array $payloads,array $plan):array
    {
        $randoms=$plan['media_random_ids']??null;$overflow=$plan['overflow_ids']??null;
        if(($plan['version']??null)!==1||!is_array($randoms)||!array_is_list($randoms)||count($randoms)!==count($batch['ids'])
            ||!is_array($overflow)||!array_is_list($overflow)||$overflow===[]
            ||count(array_unique($overflow,SORT_REGULAR))!==count($overflow)){throw new RepostFailure('REPOST_CAPTION_PLAN_INVALID');}
        $rendered=$payloads;
        foreach($batch['ids']as$i=>$id){
            $p=$payloads[$id]??throw new RepostFailure('FROZEN_PAYLOAD_MISSING');
            if(!is_int($randoms[$i])||$randoms[$i]<1){throw new RepostFailure('REPOST_CAPTION_PLAN_INVALID');}
            $long=$p['media']!==null&&self::utf16Length($p['message'])>1024;
            if($long!==in_array($id,$overflow,true)){throw new RepostFailure('REPOST_CAPTION_PLAN_INVALID');}
            if($long){
                if(self::utf16Length($p['message'])>4096){throw new RepostFailure('REPOST_CAPTION_TEXT_TOO_LONG');}
                $rendered[$id]['message']='';$rendered[$id]['entities']=[];
            }
        }
        if(count(array_unique($randoms))!==count($randoms)||array_diff($overflow,$batch['ids'])!==[]){throw new RepostFailure('REPOST_CAPTION_PLAN_INVALID');}
        $transport=$batch;$transport['random_ids']=$randoms;
        return ['batch'=>$transport,'payloads'=>$rendered];
    }

    /** Send the complete caption as one text message, retaining raw entities. */
    public static function sendOverflowCaption(object $api,int $target,array $payload,int $randomId):array
    {
        if(!is_string($payload['message']??null)||self::utf16Length($payload['message'])>4096
            ||self::utf16Length($payload['message'])<=1024||$randomId<1
            ||!is_array($payload['entities']??null)||!array_is_list($payload['entities'])){
            throw new RepostFailure('REPOST_CAPTION_TEXT_TOO_LONG');
        }
        return $api->messages->sendMessage(peer:$target,message:$payload['message'],entities:$payload['entities'],
            random_id:$randomId,no_webpage:true,invert_media:false);
    }

    /** Refresh only rotating authorization bytes for the same primary file; semantics stay frozen. */
    public static function authorize(object $api,array $payloads,array $raw):array
    {
        foreach($payloads as$id=>&$p){
            $input=$p['media'];$current=$raw[$id]['media']??[];
            if($input===null){continue;}
            $key=($input['_']??'')==='inputMediaPhoto'?'photo':'document';
            if(($current[$key]['id']??null)!==($input['id']['id']??null)){continue;}
            $fresh=$api->getFileInfo($current)['InputMedia']??null;
            if(!is_array($fresh)||($fresh['_']??null)!==($input['_']??null)||($fresh['id']['id']??null)!==$input['id']['id']){throw new RepostFailure('MEDIA_AUTHORIZATION_REFRESH_INVALID');}
            foreach(['access_hash','file_reference']as$k){$p['media']['id'][$k]=$fresh['id'][$k];}
        }unset($p);
        return $payloads;
    }

    public static function processingPending(array $result):bool
    {
        foreach($result['updates']??[]as$u){
            if(($u['_']??'')==='updateNewScheduledMessage'||!empty($u['message']['video_processing_pending'])){return true;}
        }
        return false;
    }

    /** Complete positive, unique final destination IDs; scheduled IDs are never final. */
    public static function acknowledgements(array $result,array $randomIds,?int $target=null):?array
    {
        if(($result['_']??'')==='updateShortSentMessage'){
            $id=$result['id']??null;
            return count($randomIds)===1&&is_int($id)&&$id>0&&($result['out']??null)===true
                ?[(string)$randomIds[0]=>$id]:null;
        }
        if(!in_array($result['_']??'', ['updates','updatesCombined'],true)){return null;}
        $mapped=[];
        foreach($result['updates']??[]as$u){
            if(($u['_']??'')==='updateNewScheduledMessage'){return null;}
            if(($u['_']??'')!=='updateMessageID'){continue;}
            $random=$u['random_id']??null;$id=$u['id']??null;
            if(!is_int($random)||!in_array($random,$randomIds,true)||!is_int($id)||$id<1){return null;}
            $key=(string)$random;
            if(isset($mapped[$key])&&$mapped[$key]!==$id){return null;}
            $mapped[$key]=$id;
        }
        foreach($result['updates']??[]as$u){
            if(!in_array($u['_']??'', ['updateNewMessage','updateNewChannelMessage'],true)){continue;}
            $m=$u['message']??[];
            if(!in_array($m['id']??null,array_values($mapped),true)){continue;}
            $peer=$m['peer_id']??null;$peerId=null;
            if(is_int($peer)&&$peer<-1000000000000){$peerId=$peer;}
            elseif(is_array($peer)&&($peer['_']??'')==='peerChannel'&&is_int($peer['channel_id']??null)){
                $channel=$peer['channel_id'];
                if($channel<-1000000000000){$peerId=$channel;}
                elseif($channel>0&&$channel<=PHP_INT_MAX-1000000000000){$peerId=-1000000000000-$channel;}
            }
            if(isset($m['fwd_from'])||!empty($m['video_processing_pending'])||($target!==null&&$peerId!==$target)){return null;}
        }
        return count($mapped)===count($randomIds)&&count(array_unique(array_values($mapped)))===count($randomIds)?$mapped:null;
    }
}
