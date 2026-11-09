<?php
declare(strict_types=1);
/* Server-side Fio synchronization. Run from CLI every five minutes.
 * Each currency is matched only against its verified destination account,
 * incoming amount, bank transaction ID and unambiguous payment reference.
 * Never expose access tokens in URLs, errors or a public directory.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib.php';
ini_set('display_errors','0');

function mm_fio_value(array $row,int $n): mixed {
 return $row['column'.$n]['value']??$row['column_'.$n]['value']??null;
}
function mm_fio_date(mixed $raw): ?string {
 if(is_int($raw)||is_float($raw)||is_string($raw)&&preg_match('/\A[0-9]{10}(?:[0-9]{3})?\z/D',$raw)){
  $num=(int)$raw;if($num>9999999999)$num=intdiv($num,1000);
  if($num<946684800||$num>4102444800)return null;
  return (new DateTimeImmutable('@'.$num))->setTimezone(new DateTimeZone('Europe/Prague'))->format('Y-m-d');
 }
 if(is_string($raw)&&preg_match('/\A(\d{4}-\d{2}-\d{2})/D',$raw,$m))return $m[1];
 return null;
}
function mm_fio_cents(mixed $raw): ?int {
 if(!is_string($raw)&&!is_float($raw)&&!is_int($raw))return null;
 $v=str_replace(',','.',trim((string)$raw));
 if(!preg_match('/\A[0-9]{1,7}(?:\.[0-9]{1,4})?\z/D',$v))return null;
 $val=(float)$v;if(!is_finite($val)||$val<=0)return null;
 return (int)round($val*100);
}
function mm_fio_token(string $currency): string {
 $env=getenv($currency==='EUR'?'MASSAGE_EUR_FIO_TOKEN':'MASSAGE_FIO_TOKEN');
 if(is_string($env)&&$env!=='')return $env;
 $path=mm_private_dir().'/'.($currency==='EUR'?'fio-token-eur.txt':'fio-token.txt');
 if(!is_file($path)||is_link($path)||!is_readable($path))return '';
 $perm=@fileperms($path);
 if($perm===false||($perm&0077)!==0)return '';
 return trim((string)file_get_contents($path));
}
function mm_fio_reference(array $row,string $currency): ?string {
 $vs=trim((string)(mm_fio_value($row,5)??''));
 if(preg_match('/\A57[0-9]{8}\z/D',$vs))return $vs;
 if($currency!=='EUR')return null;
 // Euro SEPA payments may put the variable symbol into the payment reference
 // or recipient's message instead of Fio's variable-symbol field.
 foreach([16,27] as $n){
  $raw=(string)(mm_fio_value($row,$n)??'');
  if(preg_match('/(?:^|[^A-Z0-9])MMVS-(57[0-9]{8})(?:$|[^0-9])/i',$raw,$m))return $m[1];
 }
 return null;
}
function mm_sync_account(PDO $db,string $currency,string $iban,string $token): int {
 if(!preg_match('/\A[A-Za-z0-9]{30,128}\z/D',$token))throw new RuntimeException('Read-only Fio token is missing for '.$currency);
 $q=$db->prepare('SELECT value FROM meta WHERE key=?');
 $key='last_bank_sync_'.$currency;$q->execute([$key]);$last=(int)$q->fetchColumn();
 if($last&&time()-$last<60)return 0;
 $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Prague'));
 $start=$now->modify('-14 days')->format('Y-m-d');$end=$now->format('Y-m-d');
 // URL includes bank secret; NEVER log or print it.
 $url='https://fioapi.fio.cz/v1/rest/periods/'.rawurlencode($token).'/'.$start.'/'.$end.'/transactions.json';
 $curl=curl_init($url);
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
 $body=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 if(!is_string($body)||strlen($body)>5000000||$http!==200)throw new RuntimeException('Fio response unavailable for '.$currency.' (HTTP '.$http.')');
 $data=json_decode($body,true,100,JSON_THROW_ON_ERROR);
 $statement=$data['accountStatement']??null;
 if(!is_array($statement))throw new RuntimeException('Fio statement missing for '.$currency);
 $info=$statement['info']??[];
 $actual=strtoupper(preg_replace('/\s+/','',(string)($info['iban']??'')));
 if($actual!==$iban)throw new RuntimeException('Unexpected Fio IBAN for '.$currency);
 $txs=$statement['transactionList']['transaction']??[];
 if($txs===null)$txs=[];
 if(!is_array($txs))throw new RuntimeException('Invalid Fio transactions for '.$currency);
 $matched=0;
 foreach($txs as $tx){
  if(!is_array($tx))continue;
  $id=(string)(mm_fio_value($tx,22)??'');
  $money=mm_fio_cents(mm_fio_value($tx,1));
  $txCurrency=strtoupper(trim((string)(mm_fio_value($tx,14)??$info['currency']??'')));
  $when=mm_fio_date(mm_fio_value($tx,0));
  $vs=mm_fio_reference($tx,$currency);
  if(!preg_match('/\A[0-9]{1,20}\z/D',$id)||$money===null||$when===null||$vs===null||$txCurrency!==$currency)continue;
  $update=$db->prepare("UPDATE orders SET status='paid',paid_at=?,fio_id=?
    WHERE vs=? AND currency=? AND amount_cents=? AND status='pending'
    AND date(created_at,'unixepoch','-1 day')<=?
    AND NOT EXISTS (SELECT 1 FROM orders WHERE fio_id=?)");
  $update->execute([time(),$id,$vs,$currency,$money,$when,$id]);
  if($update->rowCount()!==1)continue;
  $order=$db->prepare('SELECT id FROM orders WHERE fio_id=?');$order->execute([$id]);
  $orderId=(string)$order->fetchColumn();
  mm_queue($orderId,'paid_client');mm_queue($orderId,'paid_admin');$matched++;
 }
 $db->prepare('INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key,(string)time()]);
 return $matched;
}

if(!function_exists('curl_init')){fwrite(STDERR,"cURL extension missing.\n");exit(1);}
$lock=fopen(mm_private_dir().'/fio.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
try{
 $db=mm_db();$total=0;
 $total+=mm_sync_account($db,'CZK',MM_IBAN,mm_fio_token('CZK'));
 if(mm_eur_enabled())$total+=mm_sync_account($db,'EUR',mm_eur_iban(),mm_fio_token('EUR'));
 $mail=mm_dispatch_mail(50);
 printf("Massage Fio sync: matched=%d sent=%d failed=%d\n",$total,$mail['sent'],$mail['failed']);
}catch(Throwable $e){
 error_log('MM Fio sync: '.$e->getMessage());
 fwrite(STDERR,"Bank synchronization failed; inspect server error log.\n");exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}
