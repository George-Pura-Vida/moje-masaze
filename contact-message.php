<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/lib.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')mm_json(405,['error'=>'Použijte kontaktní formulář.']);
if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))mm_json(415,['error'=>'Neplatný formát.']);
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');$host=(string)($_SERVER['HTTP_HOST']??'');
if($origin!==''&&!hash_equals('https://'.$host,$origin)&&!hash_equals('http://localhost',$origin))mm_json(403,['error'=>'Nepovolený požadavek.']);
$raw=file_get_contents('php://input',false,null,0,8193);
if(!is_string($raw)||strlen($raw)>8192)mm_json(413,['error'=>'Příliš dlouhá zpráva.']);
try{
 $payload=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
 if(!is_array($payload))throw new InvalidArgumentException('Neplatná zpráva.');
 $id=mm_crm_add_inquiry($payload,(string)($_SERVER['REMOTE_ADDR']??'unknown'));
 try{mm_crm_dispatch_inquiries(8);}catch(Throwable $e){error_log('MM contact mail queue: '.get_class($e));}
 mm_json(201,['ok'=>true,'message'=>'Zpráva je uložená. Potvrzení odesíláme e-mailem.']);
}catch(InvalidArgumentException $e){mm_json(422,['error'=>$e->getMessage()]);}
catch(RuntimeException $e){mm_json(429,['error'=>'Příliš mnoho zpráv. Zkuste to později.']);}
catch(Throwable $e){error_log('MM inquiry: '.get_class($e));mm_json(503,['error'=>'Zprávu nelze právě uložit. Zkuste to později.']);}
