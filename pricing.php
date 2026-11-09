<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')mm_json(405,['error'=>'Nepovoleno.']);
$duration=filter_var($_GET['duration']??60,FILTER_VALIDATE_INT);
$qty=filter_var($_GET['count']??1,FILTER_VALIDATE_INT);
try{
 if(!is_int($duration)||!is_int($qty))throw new InvalidArgumentException('Neplatný ceník.');
 $czk=mm_quote($duration,$qty,'CZK');
 $data=['czk_cents'=>$czk['amount_cents'],'eur_enabled'=>mm_eur_enabled(),'eur_cents'=>null,'rate'=>null,'source'=>'Česká národní banka'];
 if(mm_eur_enabled()){
   try{$eur=mm_quote($duration,$qty,'EUR');$data['eur_cents']=$eur['amount_cents'];$data['rate']=$eur['fx_rate'];}
   catch(RuntimeException $e){error_log('MM pricing: CNB rate unavailable.');}
 }
 mm_json(200,$data);
}catch(InvalidArgumentException $e){mm_json(422,['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('MM pricing: '.get_class($e));mm_json(503,['error'=>'Ceník dočasně nedostupný.']);}
