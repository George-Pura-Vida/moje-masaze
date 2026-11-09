<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/lib.php';
require_once __DIR__.'/crm.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')mm_json(405,['error'=>'Použijte kontaktní formulář.']);
if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))mm_json(415,['error'=>'Neplatný formát.']);
if(!mm_form_origin_allowed())mm_json(403,['error'=>'Nepovolený požadavek.']);
$raw=file_get_contents('php://input',false,null,0,8193);
if(!is_string($raw)||strlen($raw)>8192)mm_json(413,['error'=>'Příliš dlouhá zpráva.']);
try{
 $payload=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
 if(!is_array($payload))throw new InvalidArgumentException('Neplatná zpráva.');
 $id=mm_crm_add_inquiry($payload,(string)($_SERVER['REMOTE_ADDR']??'unknown'));
 try{mm_crm_dispatch_inquiries(8);}catch(Throwable $e){error_log('MM contact mail queue: '.get_class($e));}
 mm_json(201,['ok'=>true,'message'=>'Zpráva je uložená. Potvrzení odesíláme e-mailem.']);
}catch(JsonException $e){mm_json(400,['error'=>'Neplatný JSON.']);}
catch(InvalidArgumentException $e){mm_json(422,['error'=>$e->getMessage()]);}
catch(PDOException $e){
 error_log('MM contact database: '.get_class($e));
 mm_json(503,['error'=>'Databáze zpráv není dostupná. Ověřte stav serveru v system-status.php.','code'=>'database_write']);
}catch(RuntimeException $e){
 $limited=str_contains($e->getMessage(),'Příliš mnoho požadavků');
 error_log('MM contact runtime: '.get_class($e));
 mm_json($limited?429:503,['error'=>$limited?'Příliš mnoho pokusů. Zkuste to později.':'Úložiště zpráv není dostupné. Zkontrolujte system-status.php.','code'=>$limited?'rate_limited':'storage_unavailable']);
}catch(Throwable $e){
 $rid=bin2hex(random_bytes(4));error_log('MM contact '.$rid.': '.get_class($e));
 mm_json(503,['error'=>'Zprávu nelze právě uložit. Provozovatel může ověřit diagnostiku serveru.','code'=>'contact_server_failure','reference'=>$rid]);
}
