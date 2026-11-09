<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib.php';
ini_set('display_errors','0');
function mm_fio_value(array $row,int $number): mixed {return $row['column'.$number]['value']??$row['column_'.$number]['value']??null;}
function mm_fio_date(mixed $raw): ?string {
 if(is_int($raw)||is_float($raw)||is_string($raw)&&preg_match('/\A\d{10}(?:\d{3})?\z/D',$raw)){
  $n=(int)$raw;if($n>9999999999)$n=intdiv($n,1000);
  if($n<946684800||$n>4102444800)return null;
  return (new DateTimeImmutable('@'.$n))->setTimezone(new DateTimeZone('Europe/Prague'))->format('Y-m-d');
 }
 if(is_string($raw)&&preg_match('/\A(\d{4}-\d{2}-\d{2})/D',$raw,$m))return $m[1];
 return null;
}
function mm_fio_cents(mixed $v): ?int {
 if(!is_string($v)&&!is_float($v)&&!is_int($v))return null;
 $str=str_replace(',','.',trim((string)$v));if(!preg_match('/\A[0-9]{1,7}(?:\.[0-9]{1,4})?\z/D',$str))return null;
 return (int)round((float)$str*100);
}
function mm_token(): string {
 $v=getenv('MASSAGE_FIO_TOKEN');
 if(is_string($v)&&$v!=='')return $v;
 $file=mm_private_dir().'/fio-token.txt';
 if(!is_file($file)||is_link($file)||!is_readable($file))return '';
 $perm=@fileperms($file);
 if($perm===false||($perm&0077)!==0)return '';
 return trim((string)file_get_contents($file));
}
if(!function_exists('curl_init')){fwrite(STDERR,"PHP curl extension missing.\n");exit(1);}
$token=mm_token();
if(!preg_match('/\A[a-zA-Z0-9]{30,128}\z/D',$token)){fwrite(STDERR,"Missing private read-only Fio token.\n");exit(1);}
$lock=fopen(mm_private_dir().'/fio.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
try{
 $db=mm_db();
 $last=(int)$db->query("SELECT value FROM meta WHERE key='last_bank_sync'")->fetchColumn();
 if($last!==0&&time()-$last<60)exit(0);
 $tz=new DateTimeZone('Europe/Prague');$now=new DateTimeImmutable('now',$tz);
 $start=$now->modify('-14 days')->format('Y-m-d');$end=$now->format('Y-m-d');
 $url='https://fioapi.fio.cz/v1/rest/periods/'.rawurlencode($token).'/'.$start.'/'.$end.'/transactions.json';
 $curl=curl_init($url);
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
 $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 if(!is_string($body)||strlen($body)>5000000||$status!==200)throw new RuntimeException('Fio did not return valid result (HTTP '.$status.').');
 $json=json_decode($body,true,100,JSON_THROW_ON_ERROR);$statement=$json['accountStatement']??null;
 if(!is_array($statement))throw new RuntimeException('No bank statement.');
 $info=$statement['info']??[];$iban=strtoupper(preg_replace('/\s+/', '',(string)($info['iban']??'')));
 if($iban!==MM_IBAN)throw new RuntimeException('Fio account mismatch.');
 $transactions=$statement['transactionList']['transaction']??[];
 if(!is_array($transactions))throw new RuntimeException('Invalid Fio transactions list.');
 $found=0;
 foreach($transactions as $row){
  if(!is_array($row))continue;
  $id=(string)(mm_fio_value($row,22)??'');$vs=(string)(mm_fio_value($row,5)??'');
  $currency=strtoupper((string)(mm_fio_value($row,14)??$info['currency']??''));
  $cents=mm_fio_cents(mm_fio_value($row,1));$date=mm_fio_date(mm_fio_value($row,0));
  if(!preg_match('/\A[0-9]{1,20}\z/D',$id)||!preg_match('/\A57[0-9]{8}\z/D',$vs)||$currency!=='CZK'||$cents===null||$cents<1||$date===null)continue;
  $q=$db->prepare("UPDATE orders SET status='paid',paid_at=?,fio_id=? WHERE vs=? AND amount_cents=? AND status='pending' AND date(created_at,'unixepoch','-1 day')<=? AND NOT EXISTS(SELECT 1 FROM orders WHERE fio_id=?)");
  $q->execute([time(),$id,$vs,$cents,$date,$id]);
  if($q->rowCount()!==1)continue;
  $o=$db->prepare('SELECT id FROM orders WHERE fio_id=?');$o->execute([$id]);$orderId=(string)$o->fetchColumn();
  mm_queue($orderId,'paid_client');mm_queue($orderId,'paid_admin');$found++;
 }
 $db->prepare("INSERT INTO meta (key,value) VALUES ('last_bank_sync',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([(string)time()]);
 $mail=mm_dispatch_mail(50);
 printf("Massage Fio sync: matched=%d sent=%d failed=%d\n",$found,$mail['sent'],$mail['failed']);
}catch(Throwable $e){
 error_log('MM Fio sync: '.get_class($e).' '.$e->getMessage()); // no URL/token in thrown messages
 fwrite(STDERR,"Bank synchronization failed; inspect private server log.\n");exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}
