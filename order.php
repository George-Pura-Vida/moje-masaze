<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
ini_set('display_errors','0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')mm_json(405,['error'=>'Použijte objednávkový formulář.']);
if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))mm_json(415,['error'=>'Požadavek musí být JSON.']);
$origin=$_SERVER['HTTP_ORIGIN']??'';
$host=$_SERVER['HTTP_HOST']??'';
if($origin!==''&&!hash_equals('https://'.$host,$origin)&&!hash_equals('http://localhost',$origin))mm_json(403,['error'=>'Nepovolený požadavek.']);
$raw=file_get_contents('php://input',false,null,0,8193);
if(!is_string($raw)||strlen($raw)>8192)mm_json(413,['error'=>'Příliš mnoho údajů.']);
try{
 $input=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
 if(!is_array($input))throw new InvalidArgumentException('Neplatný formulář.');
 $order=mm_create_order($input,(string)($_SERVER['REMOTE_ADDR']??'unknown'));
 try{mm_dispatch_mail(8);}catch(Throwable $e){error_log('MM order mail dispatcher: '.get_class($e));}
 mm_json(201,$order);
}catch(InvalidArgumentException $e){mm_json(422,['error'=>$e->getMessage()]);}
catch(PDOException $e){
 error_log('MM order database: '.get_class($e).' '.substr($e->getMessage(),0,200));
 mm_json(503,['error'=>'Databáze objednávek není dostupná. Ověřte stav serveru v system-status.php.','code'=>'database_write']);
}catch(RuntimeException $e){
 $limited=str_contains($e->getMessage(),'Příliš mnoho požadavků');
 error_log('MM order runtime: '.get_class($e).' '.substr($e->getMessage(),0,150));
 mm_json($limited?429:503,['error'=>$limited?'Příliš mnoho pokusů. Zkuste to později.':'Úložiště objednávek není dostupné. Zkontrolujte system-status.php.','code'=>$limited?'rate_limited':'storage_unavailable']);
}catch(Throwable $e){
 $rid=bin2hex(random_bytes(4));error_log('MM order '.$rid.': '.get_class($e).' '.substr($e->getMessage(),0,180));
 mm_json(503,['error'=>'Objednávku nelze právě uložit. Provozovatel může ověřit diagnostiku serveru.','code'=>'order_server_failure','reference'=>$rid]);
}
