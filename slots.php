<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
ini_set('display_errors','0');
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')mm_json(405,['error'=>'Nepovoleno.']);
$date=$_GET['date']??'';
$duration=filter_var($_GET['duration']??60,FILTER_VALIDATE_INT);
try{
 if(!is_string($date)||!is_int($duration))throw new InvalidArgumentException('Vyberte datum a délku masáže.');
 $slots=mm_slot_list($date,$duration);
 mm_json(200,['date'=>$date,'duration'=>$duration,'slots'=>$slots,'source'=>'Rezervační kalendář Moje masáže','notice'=>'Termíny jsou orientační do schválení provozovatelem. Externí Google Kalendář zatím není propojen.']);
}catch(InvalidArgumentException $e){mm_json(422,['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('MM slots: '.get_class($e));mm_json(503,['error'=>'Termíny nelze právě načíst.']);}
