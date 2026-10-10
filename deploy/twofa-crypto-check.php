<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$e=json_decode(base64_decode('eyJ2ZXJzaW9uIjogMSwgInJlcXVlc3RfaWQiOiAiODZjZGNiYzhhNjQ2N2JlYjNhNTg1YjEyMGRiYjc2YjEiLCAiZXhwaXJlc19hdCI6IDE3OTE1NjY2NTUsICJzYWx0IjogIjIveGFBN0RiWnlFUDhwRjFDVWtFa3c9PSIsICJub25jZSI6ICJnUHRPaDV2eVBQUlk2TVhXIiwgImNpcGhlcnRleHQiOiAic2FxRVViRGFiRmZNVVg3bXZGS296R01SZkVxbG12VkhwVDVBQzNob0I0OXdyVXJrRDlVb2dpbThTTStMc2wyWWU3cEVSRHFabGYwWDZwcFN6MXBTWE1kZVVwcFY5cFMzS2JtNHFvYjZkVTZ1eVFScmw2Q3RXc1hlS3NWbjB3djAzUnZHNG45Y3dtQTkxSS9kZlZQcFVxSUYiLCAidGFnIjogIk1RaDNKeit3eHY3UVU4VjlkdW9aVnc9PSJ9'),true,8,JSON_THROW_ON_ERROR);
$key=hash_hkdf('sha256','00000000000000000000000000000000',32,'telegram-mirror-2fa-v1',base64_decode($e['salt'],true));
$clear=openssl_decrypt(base64_decode($e['ciphertext'],true),'aes-256-gcm',$key,OPENSSL_RAW_DATA,base64_decode($e['nonce'],true),base64_decode($e['tag'],true),$e['request_id'].'|'.$e['expires_at']);
$p=json_decode($clear,true,8,JSON_THROW_ON_ERROR);
if(($p['password']??'')!=='synthetic-integration-check'||$p['request_id']!==$e['request_id']||$p['expires_at']!==$e['expires_at'])throw new RuntimeException('Interop failure');
$bad=openssl_decrypt(base64_decode($e['ciphertext'],true),'aes-256-gcm',$key,OPENSSL_RAW_DATA,base64_decode($e['nonce'],true),base64_decode($e['tag'],true),$e['request_id'].'|'.($e['expires_at']+1));
if($bad!==false)throw new RuntimeException('Tampered AAD accepted');
echo "PASS Node/PHP HKDF AES-GCM interop and metadata tamper rejection.\n";
