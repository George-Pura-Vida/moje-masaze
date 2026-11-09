<?php
declare(strict_types=1);
/* Read-only Fio synchronization for the one account used by CZK and EUR. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require_once dirname(__DIR__).'/lib.php';

function mm_fio_value(array $row,int $n): mixed {
 return $row['column'.$n]['value']??$row['column_'.$n]['value']??null;
}

function mm_fio_date(mixed $raw): ?DateTimeImmutable {
 $tz=new DateTimeZone('Europe/Prague');
 if((is_int($raw)||is_string($raw))&&preg_match('/\A[0-9]{10}(?:[0-9]{3})?\z/D',(string)$raw)){
  $num=(int)$raw;
  if(strlen((string)$raw)===13)$num=intdiv($num,1000);
  if($num<946684800||$num>4102444800)return null;
  return (new DateTimeImmutable('@'.$num))->setTimezone($tz)->setTime(0,0);
 }
 if(!is_string($raw)||!preg_match('/\A(\d{4}-\d{2}-\d{2})(?:[+-]\d{2}:\d{2})?\z/D',$raw,$match))return null;
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$match[1],$tz);
 return $date&&$date->format('Y-m-d')===$match[1]?$date:null;
}

function mm_fio_cents(mixed $raw): ?int {
 if(!is_string($raw)&&!is_float($raw)&&!is_int($raw))return null;
 $value=str_replace(',','.',trim((string)$raw));
 if(!preg_match('/\A([0-9]{1,7})(?:\.([0-9]{1,2})0*)?\z/D',$value,$parts))return null;
 $cents=(int)$parts[1]*100+(int)str_pad($parts[2]??'',2,'0');
 return $cents>0?$cents:null;
}

function mm_fio_token(): string {
 $token=getenv('MASSAGE_FIO_TOKEN');
 if(!is_string($token)||trim($token)===''){
  $path=mm_private_dir().'/fio-token.txt';
  $mode=is_file($path)&&!is_link($path)?@fileperms($path):false;
  $token=$mode!==false&&($mode&0077)===0?@file_get_contents($path,false,null,0,256):false;
 }
 $token=is_string($token)?trim($token):'';
 return preg_match('/\A[A-Za-z0-9]{30,128}\z/D',$token)?$token:'';
}

function mm_fio_reference(array $row,string $currency): ?string {
 $raw=mm_fio_value($row,5);
 $vs=is_string($raw)||is_int($raw)?trim((string)$raw):'';
 if($vs!==''&&!preg_match('/\A57[0-9]{8}\z/D',$vs))return null;
 $references=$vs===''?[]:[$vs];
 // SEPA remittance information may carry MMVS in place of the bank's VS.
 foreach([16,27] as $column){
  $message=mm_fio_value($row,$column);
  if(!is_string($message))continue;
  preg_match_all('/(?<![A-Z0-9])MMVS-(57[0-9]{8})(?![A-Z0-9])/i',$message,$matches);
  $references=array_merge($references,$matches[1]);
 }
 $references=array_unique($references);
 return count($references)===1&&($currency==='EUR'||$vs!=='')?(string)reset($references):null;
}

function mm_fio_reconcile(PDO $db,array $data,DateTimeImmutable $start,DateTimeImmutable $end): array {
 $statement=$data['accountStatement']??null;
 $info=$statement['info']??null;
 $rawIban=$info['iban']??null;
 if(!is_string($rawIban)||strtoupper(preg_replace('/\s+/','',$rawIban))!==MM_IBAN){
  throw new RuntimeException('Fio returned a statement for an unexpected account.');
 }
 $list=$statement['transactionList']??null;
 if(!is_array($list)||!array_key_exists('transaction',$list))throw new RuntimeException('Fio transaction list is missing.');
 $transactions=$list['transaction']??[];
 if(!is_array($transactions)||!array_is_list($transactions))throw new RuntimeException('Invalid Fio transaction list.');

 $matched=['CZK'=>0,'EUR'=>0];
 $update=$db->prepare("UPDATE orders SET status='paid',paid_at=?,fio_id=?
   WHERE vs=? AND currency=? AND amount_cents=? AND status='pending'
   AND (bank_iban=? OR (bank_iban='' AND currency='CZK'))
   AND created_at<?
   AND NOT EXISTS (SELECT 1 FROM orders WHERE fio_id=?)");
 $order=$db->prepare('SELECT id FROM orders WHERE fio_id=?');
 $db->exec('BEGIN IMMEDIATE');
 try{
  foreach($transactions as $row){
   if(!is_array($row))continue;
   $rawId=mm_fio_value($row,22);
   $id=is_string($rawId)||is_int($rawId)?(string)$rawId:'';
   $amount=mm_fio_cents(mm_fio_value($row,1));
   $currency=mm_fio_value($row,14);
   $date=mm_fio_date(mm_fio_value($row,0));
   if(!isset($matched[is_string($currency)?$currency:''])||$amount===null||$date===null||$date<$start||$date>$end||
      !preg_match('/\A[1-9][0-9]{0,19}\z/D',$id))continue;
   $reference=mm_fio_reference($row,$currency);
   if($reference===null)continue;
   $update->execute([time(),$id,$reference,$currency,$amount,MM_IBAN,$date->modify('+1 day')->getTimestamp(),$id]);
   if($update->rowCount()!==1)continue;
   $order->execute([$id]);
   $orderId=$order->fetchColumn();
   $order->closeCursor();
   if(!is_string($orderId))throw new RuntimeException('Confirmed order is missing.');
   mm_queue($orderId,'paid_client');
   mm_queue($orderId,'paid_admin');
   $matched[$currency]++;
  }
  $db->prepare("INSERT INTO meta(key,value) VALUES('last_bank_sync',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
     ->execute([(string)time()]);
  $db->exec('COMMIT');
 }catch(Throwable $e){
  $db->exec('ROLLBACK');
  throw $e;
 }
 return $matched;
}

function mm_fio_run(): int {
 try{
  if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension missing.');
  $lock=fopen(mm_private_dir().'/fio.lock','c');
  if($lock===false)throw new RuntimeException('Fio sync lock unavailable.');
  try{
   if(!flock($lock,LOCK_EX|LOCK_NB)){echo "Fio sync already running.\n";return 0;}
   $db=mm_db();
   $last=(int)$db->query("SELECT value FROM meta WHERE key='last_bank_sync'")->fetchColumn();
   if($last&&time()-$last<60){echo "Fio sync already completed within the last minute.\n";return 0;}
   $token=mm_fio_token();
   if($token==='')throw new RuntimeException('Read-only Fio token is missing or invalid.');
   $end=new DateTimeImmutable('today',new DateTimeZone('Europe/Prague'));
   $start=$end->modify('-14 days');
   // The Fio API uses a token in the request URL. Keep it out of output and logs.
   $url='https://fioapi.fio.cz/v1/rest/periods/'.rawurlencode($token).'/'.$start->format('Y-m-d').'/'.$end->format('Y-m-d').'/transactions.json';
   $curl=curl_init($url);
   curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
   $body=curl_exec($curl);
   $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
   curl_close($curl);
   if(!is_string($body)||strlen($body)>5000000||$http!==200)throw new RuntimeException('Fio statement unavailable (HTTP '.$http.').');
   $data=json_decode($body,true,100,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
   if(!is_array($data))throw new RuntimeException('Invalid Fio statement.');
   $matched=mm_fio_reconcile($db,$data,$start,$end);
   printf("Fio sync complete: CZK=%d EUR=%d\n",$matched['CZK'],$matched['EUR']);
  }finally{fclose($lock);}
 }catch(Throwable $e){
  $reason=get_class($e)===RuntimeException::class?$e->getMessage():get_class($e);
  error_log('MM Fio sync: '.$reason);
  fwrite(STDERR,"Fio sync failed: ".$reason."\n");
  return 1;
 }
 return 0;
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(mm_fio_run());
