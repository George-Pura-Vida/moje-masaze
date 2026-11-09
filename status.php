<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
ini_set('display_errors','0');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')mm_json(405,['error'=>'Nepovoleno.']);
$token=$_GET['token']??'';
if(!is_string($token)||!preg_match('/\A[a-f0-9]{48}\z/D',$token))mm_json(404,['error'=>'Objednávka nenalezena.']);
try{
 $q=mm_db()->prepare('SELECT kind,status FROM orders WHERE token=? LIMIT 1');$q->execute([$token]);$o=$q->fetch();
 if(!$o)mm_json(404,['error'=>'Objednávka nenalezena.']);
 $data=['status'=>$o['status']];
 if($o['status']==='paid'&&$o['kind']==='gift')$data['voucher_url']='voucher.php?token='.rawurlencode($token);
 mm_json(200,$data);
}catch(Throwable $e){error_log('MM status: '.get_class($e));mm_json(503,['error'=>'Zkuste to později.']);}
