<?php
declare(strict_types=1);
/** Moje masáže — private booking and bank-payment services.
 * IMPORTANT: FIO token is used only by CLI cron; never loaded by the public site.
 */
const MM_IBAN='CZ8220100000000223366333';
const MM_ACCOUNT='223366333/2010';
const MM_OWNER='Jiří Janoušek';
const MM_ADMIN_DEFAULT='jgjanousek@gmail.com';
const MM_FOCUS=[
 'holistic'=>'Celostní relaxační masáž',
 'sensual'=>'Smyslová a tantrická relaxační masáž',
 'back'=>'Záda, šíje a svaly',
 'sport'=>'Sportovní masáž nohou a lýtek',
 'head'=>'Masáž hlavy a obličeje',
 'stones'=>'Lávové kameny',
 'towels'=>'Horké ručníky',
 'touch'=>'Terapeutický dotek'
];
function mm_private_dir(): string {
  static $ready=null;
  if(is_string($ready))return $ready;
  $env=getenv('MASSAGE_PRIVATE_DIR');
  $explicit=is_string($env)&&$env!=='';
  $primary=$explicit?$env:dirname(__DIR__).'/moje-masaze-private';
  if(!str_starts_with($primary,'/'))throw new RuntimeException('MASSAGE_PRIVATE_DIR must be an absolute path.');
  // Never silently switch away from existing client records if their original directory is unwritable.
  $candidates=[$primary];
  if(!$explicit&&!file_exists($primary)){
    $home=getenv('HOME');
    if(is_string($home)&&$home!==''&&str_starts_with($home,'/'))
      $candidates[]=rtrim($home,'/').'/moje-masaze-private';
  }
  foreach(array_unique($candidates) as $dir){
    if(is_link($dir)||(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)))continue;
    $resolved=realpath($dir);$public=realpath(__DIR__);
    if($resolved===false||$public===false||$resolved===$public||str_starts_with($resolved,$public.'/'))continue;
    @chmod($dir,0700);
    if(!is_writable($dir))continue;
    // PHP-FPM may report a writable directory but still reject actual file creation.
    $probe=$dir.'/.probe-'.bin2hex(random_bytes(5));
    if(@file_put_contents($probe,'ok',LOCK_EX)===false)continue;
    @unlink($probe);
    return $ready=$resolved;
  }
  error_log('MM storage: no writable private directory OUTSIDE webroot; set MASSAGE_PRIVATE_DIR in WebKitty.');
  throw new RuntimeException('Private database directory cannot be created or written. Ask hosting to configure MASSAGE_PRIVATE_DIR outside public webroot.');
}
function mm_config(string $key,string $default=''): string {
  $env=getenv($key);
  if(is_string($env)&&$env!=='')return $env;
  static $settings=null;
  if($settings===null){
    $settings=[];
    $file=mm_private_dir().'/settings.json';
    if(is_file($file)&&!is_link($file)&&is_readable($file)){
      $mode=@fileperms($file);
      if($mode!==false&&($mode&0077)===0){
        $raw=@file_get_contents($file,false,null,0,4097);
        if(is_string($raw)&&strlen($raw)<=4096){
          $decoded=json_decode($raw,true);
          if(is_array($decoded))$settings=$decoded;
        }
      }
    }
  }
  $v=$settings[$key]??null;
  return is_string($v)?$v:$default;
}

function mm_db(): PDO {
  static $db;
  if($db instanceof PDO)return $db;
  if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Missing PHP pdo_sqlite extension. Enable it in WebKitty PHP settings.');
  $file=mm_private_dir().'/orders.sqlite';
  $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $db->exec('PRAGMA busy_timeout=5000');
  $db->exec('PRAGMA foreign_keys=ON');
  try{
    $journal=$db->query('PRAGMA journal_mode=WAL')->fetchColumn();
    if(!is_string($journal)||strtolower($journal)!=='wal')$db->exec('PRAGMA journal_mode=DELETE');
  }catch(PDOException $e){
    // Network/shared hosting may disallow WAL or the necessary -shm shared-memory file.
    error_log('MM database: WAL unavailable, using DELETE journal mode.');
    $db->exec('PRAGMA journal_mode=DELETE');
  }
  $db->exec("CREATE TABLE IF NOT EXISTS orders (
 id TEXT PRIMARY KEY,
 token TEXT NOT NULL UNIQUE,
 vs TEXT NOT NULL UNIQUE,
 kind TEXT NOT NULL CHECK(kind IN ('booking','gift')),
 focus TEXT NOT NULL,
 duration INTEGER NOT NULL CHECK(duration IN (60,180)),
 quantity INTEGER NOT NULL CHECK(quantity IN (1,2,3)),
 amount_cents INTEGER NOT NULL,
 currency TEXT NOT NULL DEFAULT 'CZK',
 fx_rate REAL NOT NULL DEFAULT 1,
 bank_iban TEXT NOT NULL DEFAULT '',
 bank_bic TEXT NOT NULL DEFAULT '',
 buyer_name TEXT NOT NULL,
 email TEXT NOT NULL,
 phone TEXT NOT NULL,
 recipient TEXT NOT NULL DEFAULT '',
 preferred_date TEXT NOT NULL DEFAULT '',
 preferred_time TEXT NOT NULL DEFAULT '',
 note TEXT NOT NULL DEFAULT '',
 lang TEXT NOT NULL DEFAULT 'cs',
 created_at INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending',
 paid_at INTEGER,
 fio_id TEXT UNIQUE,
 appointment_status TEXT NOT NULL DEFAULT 'requested',
 confirmed_date TEXT NOT NULL DEFAULT '',
 confirmed_time TEXT NOT NULL DEFAULT '',
 redeemed_count INTEGER NOT NULL DEFAULT 0,
 redeemed_at INTEGER
);
CREATE TABLE IF NOT EXISTS email_queue (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_id TEXT NOT NULL,
 kind TEXT NOT NULL CHECK(kind IN ('new_client','new_admin','paid_client','paid_admin')),
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sending','sent','failed')),
 created_at INTEGER NOT NULL,
 UNIQUE(order_id,kind),
 FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE TABLE IF NOT EXISTS rate (
 ip_hash TEXT NOT NULL,
 hour TEXT NOT NULL,
 hits INTEGER NOT NULL DEFAULT 0,
 PRIMARY KEY(ip_hash,hour)
);
CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS massage_blocks (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 block_date TEXT NOT NULL,
 start_time TEXT NOT NULL,
 end_time TEXT NOT NULL,
 note TEXT NOT NULL DEFAULT '',
 created_at INTEGER NOT NULL
);
");
  $columns=$db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC);
  $names=array_column($columns,'name');
  if(!in_array('currency',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN currency TEXT NOT NULL DEFAULT 'CZK'");
  if(!in_array('fx_rate',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN fx_rate REAL NOT NULL DEFAULT 1");
  if(!in_array('bank_iban',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN bank_iban TEXT NOT NULL DEFAULT ''");
  if(!in_array('bank_bic',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN bank_bic TEXT NOT NULL DEFAULT ''");
  if(!in_array('appointment_status',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN appointment_status TEXT NOT NULL DEFAULT 'requested'");
  if(!in_array('confirmed_date',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN confirmed_date TEXT NOT NULL DEFAULT ''");
  if(!in_array('confirmed_time',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN confirmed_time TEXT NOT NULL DEFAULT ''");
  if(!in_array('redeemed_count',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN redeemed_count INTEGER NOT NULL DEFAULT 0");
  if(!in_array('redeemed_at',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN redeemed_at INTEGER");
  if(!in_array('client_id',$names,true))$db->exec("ALTER TABLE orders ADD COLUMN client_id TEXT NOT NULL DEFAULT ''");
  require_once __DIR__.'/crm.php';
  mm_crm_migrate($db);
  @chmod($file,0600);
  return $db;
}
function mm_price(int $duration,int $quantity): int {
  if(!in_array($duration,[60,180],true)||!in_array($quantity,[1,2,3],true))throw new InvalidArgumentException('Neplatný balíček.');
  $unit=$duration===60?200000:300000;
  $percent=$quantity===1?100:($quantity===2?95:90);
  return intdiv($unit*$quantity*$percent,100);
}
function mm_eur_iban(): string {
 $iban=strtoupper(preg_replace('/\s+/','',mm_config('MASSAGE_EUR_IBAN')));
 if(!preg_match('/\A[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\z/D',$iban) || $iban===MM_IBAN)return '';
 return $iban;
}
function mm_eur_enabled(): bool {
 return mm_config('MASSAGE_EUR_ENABLED')==='1' && mm_eur_iban()!=='' && mm_config('MASSAGE_EUR_FIO_CONFIGURED')==='1';
}
function mm_eur_bic(): string {
 $bic=strtoupper(trim(mm_config('MASSAGE_EUR_BIC','FIOBCZPPXXX')));
 return preg_match('/\A[A-Z0-9]{8}(?:[A-Z0-9]{3})?\z/D',$bic)?$bic:'FIOBCZPPXXX';
}
function mm_eur_rate(): float {
 // EUR quote uses the last officially published CNB reference rate.
 $private=mm_private_dir().'/eur-rate.json';
 if(is_file($private) && (time()-(int)filemtime($private)<86400)){
   $row=json_decode((string)file_get_contents($private),true);
   if(is_array($row)&&isset($row['rate'])&&$row['rate']>=15&&$row['rate']<=45)return (float)$row['rate'];
 }
 if(!function_exists('curl_init'))throw new RuntimeException('Kurz EUR není nyní dostupný.');
 $url='https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';
 $curl=curl_init($url);
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>9,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 if($status===200&&is_string($raw)&&strlen($raw)<100000){
  foreach(explode("\n",$raw) as $line){
   $parts=explode('|',trim($line));
   if(count($parts)===5&&$parts[3]==='EUR'&&ctype_digit($parts[2])&&(int)$parts[2]>0){
    $rate=(float)str_replace(',','.',$parts[4])/(int)$parts[2];
    if($rate>=15&&$rate<=45){
      $tmp=$private.'.'.bin2hex(random_bytes(5)).'.tmp';
      file_put_contents($tmp,json_encode(['rate'=>$rate,'date'=>trim(explode("\n",$raw)[0])]),LOCK_EX);
      @chmod($tmp,0600);@rename($tmp,$private);
      return $rate;
    }
   }
  }
 }
 // A weekend / holiday may use a recent cached rate, but never an old one.
 if(is_file($private)&&time()-(int)filemtime($private)<5*86400){
   $row=json_decode((string)file_get_contents($private),true);
   if(is_array($row)&&isset($row['rate'])&&$row['rate']>=15&&$row['rate']<=45)return (float)$row['rate'];
 }
 throw new RuntimeException('Aktuální kurz ČNB není dostupný. Zvolte prosím CZK.');
}
function mm_quote(int $duration,int $quantity,string $currency): array {
 $czk=mm_price($duration,$quantity);
 if($currency==='CZK')return ['amount_cents'=>$czk,'currency'=>'CZK','fx_rate'=>1.0,'iban'=>MM_IBAN,'bic'=>'FIOBCZPPXXX','account'=>MM_ACCOUNT];
 if($currency!=='EUR')throw new InvalidArgumentException('Nepodporovaná měna.');
 if(!mm_eur_enabled())throw new InvalidArgumentException('EUR platby zatím nejsou aktivní. Zvolte prosím CZK.');
 $rate=mm_eur_rate();
 return ['amount_cents'=>(int)round($czk/$rate),'currency'=>'EUR','fx_rate'=>$rate,'iban'=>mm_eur_iban(),'bic'=>mm_eur_bic(),'account'=>mm_eur_iban()];
}


/**
 * Public schedule is the local booking ledger, not a Google Calendar live-busy feed.
 * Times are Europe/Prague; buffers between sessions prevent consecutive overlap.
 */
function mm_slot_interval(string $date,string $time,int $duration): array {
 $zone=new DateTimeZone('Europe/Prague');
 $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
 if(!$day||$day->format('Y-m-d')!==$date)throw new InvalidArgumentException('Neplatné datum rezervace.');
 if(!preg_match('/\A(?:0[9]|1[0-9]):(?:00|15|30|45)\z/D',$time))throw new InvalidArgumentException('Neplatná hodina.');
 if(!in_array($duration,[60,180],true))throw new InvalidArgumentException('Neplatná délka masáže.');
 $start=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,$zone);
 if(!$start||$start->format('Y-m-d H:i')!==$date.' '.$time)throw new InvalidArgumentException('Neplatný čas.');
 $end=$start->modify('+'.($duration+30).' minutes');
 $min=$day->setTime(9,0);
 $max=$day->setTime(20,0);
 if($start<$min||$end>$max)throw new InvalidArgumentException('Zvolte čas v rozmezí 9:00–20:00 s rezervou mezi masážemi.');
 if($day>new DateTimeImmutable('+365 days',$zone))throw new InvalidArgumentException('Termín je příliš vzdálený.');
 return [$start->getTimestamp(),$end->getTimestamp()];
}
function mm_slot_is_free(string $date,string $time,int $duration,string $ignoreId=''): bool {
 try{[$begin,$end]=mm_slot_interval($date,$time,$duration);}
 catch(InvalidArgumentException $e){return false;}
 $db=mm_db();
 $q=$db->prepare("SELECT id,status,created_at,duration,preferred_date,preferred_time,confirmed_date,confirmed_time,appointment_status
  FROM orders WHERE (preferred_date=? OR confirmed_date=?) AND appointment_status NOT IN ('cancelled','completed')");
 $q->execute([$date,$date]);
 $now=time();
 foreach($q->fetchAll() as $o){
  if($o['id']===$ignoreId)continue;
  if($o['confirmed_date']!==''&&$o['confirmed_time']!==''){
   $otherDate=$o['confirmed_date'];$otherTime=$o['confirmed_time'];
  }else{
   // Pending payment holds a new slot only for 24 hours.
   // Paid orders and approved visits continue blocking until completed/cancelled.
   if($o['status']!=='paid'&&$o['appointment_status']!=='confirmed'&&$now-(int)$o['created_at']>86400)continue;
   $otherDate=$o['preferred_date'];$otherTime=$o['preferred_time'];
  }
  if($otherDate!==$date||$otherTime==='')continue;
  try{[$otherBegin,$otherEnd]=mm_slot_interval($otherDate,$otherTime,(int)$o['duration']);}
  catch(InvalidArgumentException $e){continue;}
  if($begin<$otherEnd && $end>$otherBegin)return false;
 }
 $blocked=$db->prepare('SELECT start_time,end_time FROM massage_blocks WHERE block_date=?');$blocked->execute([$date]);
 foreach($blocked->fetchAll() as $row){
  $s=strtotime($date.' '.$row['start_time'].' Europe/Prague');
  $e=strtotime($date.' '.$row['end_time'].' Europe/Prague');
  if($s!==false&&$e!==false&&$begin<$e&&$end>$s)return false;
 }
 return true;
}
function mm_slot_list(string $date,int $duration): array {
 $zone=new DateTimeZone('Europe/Prague');
 $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
 if(!$day||$day->format('Y-m-d')!==$date||$day<new DateTimeImmutable('today',$zone)||$day>new DateTimeImmutable('+365 days',$zone))throw new InvalidArgumentException('Vyberte datum během příštích 12 měsíců.');
 if(!in_array($duration,[60,180],true))throw new InvalidArgumentException('Neplatná délka masáže.');
 $out=[];$now=new DateTimeImmutable('now',$zone);
 require_once __DIR__.'/google-calendar-lib.php';
 // Fail closed: if Google is linked but unavailable, never falsely advertise a free slot.
 $externalBusy=mm_google_connected()?mm_google_busy($date):[];
 foreach(range(9,19) as $hour){
  $time=sprintf('%02d:00',$hour);
  [$begin,$end]=[null,null];
  try{[$begin,$end]=mm_slot_interval($date,$time,$duration);}catch(InvalidArgumentException $e){continue;}
  $out[]=['time'=>$time,'available'=>($begin>$now->getTimestamp()+3600)&&mm_slot_is_free($date,$time,$duration)&&!mm_google_overlaps($externalBusy,$begin,$end)];
 }
 return $out;
}
function mm_block_create(string $date,string $start,string $end,string $note=''): void {
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Europe/Prague'));
 if(!$d||$d->format('Y-m-d')!==$date||!preg_match('/\A(?:0[0-9]|1[0-9]|2[0-3]):(?:00|30)\z/D',$start)||!preg_match('/\A(?:0[0-9]|1[0-9]|2[0-3]):(?:00|30)\z/D',$end)||$end<=$start)throw new InvalidArgumentException('Neplatná časová blokace.');
 $q=mm_db()->prepare('INSERT INTO massage_blocks(block_date,start_time,end_time,note,created_at) VALUES(?,?,?,?,?)');
 $q->execute([$date,$start,$end,mm_text($note,100),time()]);
}

function mm_calendar_secret(): string {
 $file=mm_private_dir().'/calendar-key.txt';
 if(!is_file($file)){
  $key=bin2hex(random_bytes(32));
  $tmp=$file.'.'.bin2hex(random_bytes(5)).'.tmp';
  if(file_put_contents($tmp,$key,LOCK_EX)===false)throw new RuntimeException('Nelze vytvořit kalendářový klíč.');
  @chmod($tmp,0600);
  if(!@rename($tmp,$file)){$key=is_file($file)?trim((string)file_get_contents($file)):'';@unlink($tmp);}
 }
 $perms=@fileperms($file);
 if($perms===false||($perms&0077)!==0||is_link($file))throw new RuntimeException('Kalendářový klíč není uložen bezpečně.');
 $key=trim((string)file_get_contents($file));
 if(!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new RuntimeException('Neplatný kalendářový klíč.');
 return $key;
}

function mm_csrf(): string {
  if(session_status()!==PHP_SESSION_ACTIVE)session_start();
  if(empty($_SESSION['mm_csrf']))$_SESSION['mm_csrf']=bin2hex(random_bytes(24));
  return $_SESSION['mm_csrf'];
}
function mm_json(int $status,array $value): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  exit;
}
function mm_rate_limit(string $ip): void {
  $db=mm_db();$h=hash('sha256',$ip.'|mm-salt');$hour=gmdate('YmdH');
  $db->exec("DELETE FROM rate WHERE hour < '".gmdate('YmdH',time()-7200)."'");
  $query=$db->prepare('INSERT INTO rate (ip_hash,hour,hits) VALUES (?,?,1) ON CONFLICT(ip_hash,hour) DO UPDATE SET hits=hits+1');
  $query->execute([$h,$hour]);
  $q=$db->prepare('SELECT hits FROM rate WHERE ip_hash=? AND hour=?');$q->execute([$h,$hour]);
  if((int)$q->fetchColumn()>8)throw new RuntimeException('Příliš mnoho požadavků. Zkuste to prosím později.');
}
function mm_text(mixed $raw,int $max,bool $required=false): string {
  if(!is_string($raw))throw new InvalidArgumentException('Neplatné údaje formuláře.');
  $value=trim(preg_replace('/\s+/u',' ',$raw)??'');
  if(strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)||($required&&$value===''))throw new InvalidArgumentException('Vyplňte prosím platné údaje.');
  return $value;
}
function mm_create_order(array $in,string $ip): array {
  mm_rate_limit($ip);
  if(($in['website']??'')!=='')throw new InvalidArgumentException('Požadavek nebyl přijat.');
  if(($in['terms']??null)!==true)throw new InvalidArgumentException('Potvrďte podmínky objednávky.');
  if(!isset($in['csrf'])||!is_string($in['csrf'])||!hash_equals(mm_csrf(),$in['csrf']))throw new InvalidArgumentException('Obnovte stránku a zkuste to znovu.');
  $kind=$in['kind']??null;$focus=$in['focus']??null;$duration=filter_var($in['duration']??null,FILTER_VALIDATE_INT);$quantity=filter_var($in['count']??null,FILTER_VALIDATE_INT);
  if(!in_array($kind,['gift','booking'],true)||!is_string($focus)||!array_key_exists($focus,MM_FOCUS))throw new InvalidArgumentException('Vyberte platnou službu.');
  if(!is_int($duration)||!is_int($quantity))throw new InvalidArgumentException('Vyberte platný balíček.');
  $currency=$in['currency']??'CZK';
  if(!is_string($currency))throw new InvalidArgumentException('Neplatná měna.');
  $quote=mm_quote($duration,$quantity,$currency);
  $amount=$quote['amount_cents'];
  if(isset($in['expected_amount_cents'])){
    $expected=filter_var($in['expected_amount_cents'],FILTER_VALIDATE_INT);
    if($expected===false||$expected!==$amount)throw new InvalidArgumentException('Cena se od zobrazení změnila. Aktualizujte objednávku a potvrďte novou cenu.');
  }
  $name=mm_text($in['name']??null,120,true);
  $email=mm_text($in['email']??null,254,true);
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Zadejte platný e-mail.');
  $phone=mm_text($in['phone']??null,25,true);
  if(!preg_match('/\A[0-9+()\s\-]{7,25}\z/D',$phone))throw new InvalidArgumentException('Zadejte platné telefonní číslo.');
  $recipient=mm_text($in['recipient']??'',120);
  $note=mm_text($in['note']??'',650);
  $prefDate=mm_text($in['date']??'',10);
  $prefTime=mm_text($in['time']??'',5);
  if($prefDate!==''){
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$prefDate,new DateTimeZone('Europe/Prague'));
    if(!$date||$date->format('Y-m-d')!==$prefDate||$date<new DateTimeImmutable('today',new DateTimeZone('Europe/Prague'))||$date>new DateTimeImmutable('+1 year',new DateTimeZone('Europe/Prague')))throw new InvalidArgumentException('Neplatný termín.');
  }
  if($prefTime!==''&&!preg_match('/\A(?:09|10|11|12|13|14|15|16|17|18|19):00\z/D',$prefTime))throw new InvalidArgumentException('Neplatná hodina.');
  if($prefTime!==''&&$prefDate==='')throw new InvalidArgumentException('Pro hodinu zvolte také datum.');
  $lang=$in['lang']??'cs';if(!in_array($lang,['cs','sk','en','es','zh'],true))$lang='cs';
  $db=mm_db();$id=bin2hex(random_bytes(12));$token=bin2hex(random_bytes(24));
  if($prefDate!==''&&$prefTime!==''){
    if(!mm_slot_is_free($prefDate,$prefTime,$duration))throw new InvalidArgumentException('Vybraný čas již není volný. Zvolte prosím jiný.');
    require_once __DIR__.'/google-calendar-lib.php';
    if(!mm_google_slot_free($prefDate,$prefTime,$duration))throw new InvalidArgumentException('Vybraný čas je obsazený ve vašem Google Kalendáři.');
  }
  $db->exec('BEGIN IMMEDIATE');
  try {
  $clientId=mm_crm_upsert_client($db,$name,$email,$phone,time());
  if($prefDate!==''&&$prefTime!==''&&!mm_slot_is_free($prefDate,$prefTime,$duration))throw new InvalidArgumentException('Vybraný čas právě obsadil jiný klient. Vyberte další termín.');
  for($i=0;$i<20;$i++){
    $vs='57'.(string)random_int(10000000,99999999);
    try{
      $q=$db->prepare("INSERT INTO orders (id,token,vs,kind,focus,duration,quantity,amount_cents,currency,fx_rate,bank_iban,bank_bic,buyer_name,email,phone,recipient,preferred_date,preferred_time,note,lang,created_at,client_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $q->execute([$id,$token,$vs,$kind,$focus,$duration,$quantity,$amount,$currency,$quote['fx_rate'],$quote['iban'],$quote['bic'],$name,$email,$phone,$recipient,$prefDate,$prefTime,$note,$lang,time(),$clientId]);
      break;
    }catch(PDOException $e){
      if(str_contains($e->getMessage(),'UNIQUE constraint failed: orders.vs')&&$i<19)continue;
      throw $e;
    }
  }
  mm_queue($id,'new_client');mm_queue($id,'new_admin');
  $db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return ['id'=>$id,'token'=>$token,'vs'=>$vs,'amount'=>$amount/100,'kind'=>$kind,'currency'=>$currency,'iban'=>$quote['iban'],'bic'=>$quote['bic'],'account'=>$quote['account']];
}
function mm_queue(string $id,string $kind): void {
  $q=mm_db()->prepare("INSERT OR IGNORE INTO email_queue(order_id,kind,created_at) VALUES (?,?,?)");$q->execute([$id,$kind,time()]);
}
function mm_money(int $cents,string $currency='CZK'): string {return number_format($cents/100,$currency==='EUR'?2:($cents%100?2:0),',',' ').($currency==='EUR'?' €':' Kč');}
function mm_escape(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function mm_admin(): string {$raw=mm_config('MASSAGE_ADMIN_EMAIL');return filter_var($raw,FILTER_VALIDATE_EMAIL)?$raw:MM_ADMIN_DEFAULT;}
function mm_header_encoded(string $s): string {return '=?UTF-8?B?'.base64_encode($s).'?=';}
function mm_salutation(string $name): string {
  // Unknown surnames are not automatically declined: neutral address is safer.
  $name=trim($name);
  $known=['Jiří Janoušek'=>'Dobrý den, pane Janoušku,','Pavel Sova'=>'Dobrý den, pane Sovo,','Petr Novák'=>'Dobrý den, pane Nováku,','Jana Nováková'=>'Dobrý den, paní Nováková,','Marie Svobodová'=>'Dobrý den, paní Svobodová,'];
  return $known[$name]??'Dobrý den,';
}
function mm_mail_template(array $o,string $kind): array {
  $paid=str_starts_with($kind,'paid_');$admin=str_ends_with($kind,'_admin');
  $gift=$o['kind']==='gift';
  $subject=$admin
   ?($paid?'✅ Zaplaceno • Moje masáže • VS '.$o['vs']:'📩 Nová objednávka • Moje masáže • VS '.$o['vs'])
   :($paid?($gift?'🎁 Váš dárkový poukaz • Moje masáže':'✅ Potvrzení úhrady • Moje masáže'):'🌿 Objednávka přijata • Moje masáže');
  $head=$paid?($gift?'Dárkový poukaz je připraven':'Platba byla potvrzena'):($gift?'Poukaz čeká na úhradu':'Vaše objednávka byla přijata');
  if($admin)$head=$paid?'Nová uhrazená objednávka':'Nový požadavek na masáž';
  $intro=$admin?'Zpráva pro administrátora.':
    ($paid?($gift?'Platba byla ověřena podle bankovního výpisu. Děkujeme za objednávku.':'Vaše úhrada byla ověřena bankou. Termín ještě osobně potvrdíme.'):'Děkujeme za objednávku. Proveďte platbu pomocí QR kódu a variabilního symbolu. Rezervovaný termín zatím není potvrzen.');
  $lang=$admin?'cs':(in_array(($o['lang']??'cs'),['cs','sk','en','es','zh'],true)?$o['lang']:'cs');
  $salutation=mm_salutation((string)$o['buyer_name']);
  $localizations=[
    'sk'=>[
      'salutation'=>'Dobrý deň,',
      'subjectNew'=>'🌿 Objednávka prijatá • Moje masáže','subjectPaid'=>'✅ Potvrdenie úhrady • Moje masáže','subjectGift'=>'🎁 Váš darčekový poukaz • Moje masáže',
      'headNew'=>'Vaša objednávka bola prijatá','headGift'=>'Poukaz čaká na úhradu','headPaid'=>'Platba bola potvrdená','headPaidGift'=>'Darčekový poukaz je pripravený',
      'introNew'=>'Ďakujeme za objednávku. Pre platbu použite QR kód a variabilný symbol. Termín zatiaľ nie je potvrdený.',
      'introPaid'=>'Úhradu potvrdila banka. Termín masáže ešte osobne potvrdíme.',
      'introGiftPaid'=>'Platba bola overená bankou. Ďakujeme. Váš poukaz je pripravený.',
      'link'=>'Otvoriť platobný QR kód','voucher'=>'Zobraziť darčekový poukaz','pay'=>'Skontrolujte bankový účet, sumu a platobnú referenciu.',
      'fields'=>['Objednávka'=>'Objednávka','Masáž'=>'Masáž','Délka'=>'Trvanie','Počet masáží'=>'Počet masáží','Cena'=>'Cena','Variabilní symbol'=>'Variabilný symbol','Pro koho'=>'Pre koho','Preferovaný termín'=>'Preferovaný termín']
    ],
    'en'=>[
      'salutation'=>'Hello,',
      'subjectNew'=>'🌿 Order received • My Massages','subjectPaid'=>'✅ Payment confirmed • My Massages','subjectGift'=>'🎁 Your gift voucher • My Massages',
      'headNew'=>'Your order has been received','headGift'=>'Gift voucher awaiting payment','headPaid'=>'Payment confirmed','headPaidGift'=>'Your gift voucher is ready',
      'introNew'=>'Thank you for your order. Please pay using the QR code and payment reference. Your preferred appointment has not been confirmed yet.',
      'introPaid'=>'The bank has confirmed your payment. We will confirm your massage appointment separately.',
      'introGiftPaid'=>'Your payment has been verified by the bank. Thank you for your order.',
      'link'=>'Open payment QR code','voucher'=>'View gift voucher','pay'=>'Check the bank account, exact amount and payment reference before paying.',
      'fields'=>['Objednávka'=>'Order','Masáž'=>'Massage','Délka'=>'Duration','Počet masáží'=>'Sessions','Cena'=>'Price','Variabilní symbol'=>'Payment reference','Pro koho'=>'Recipient','Preferovaný termín'=>'Preferred appointment']
    ],
    'es'=>[
      'salutation'=>'Hola,',
      'subjectNew'=>'🌿 Pedido recibido • Mis masajes','subjectPaid'=>'✅ Pago confirmado • Mis masajes','subjectGift'=>'🎁 Tu tarjeta regalo • Mis masajes',
      'headNew'=>'Hemos recibido tu pedido','headGift'=>'Tarjeta regalo pendiente de pago','headPaid'=>'Pago confirmado','headPaidGift'=>'Tu tarjeta regalo está lista',
      'introNew'=>'Gracias por tu pedido. Paga con el código QR y la referencia indicada. La cita aún no está confirmada.',
      'introPaid'=>'El banco ha confirmado el pago. Confirmaremos la cita por separado.',
      'introGiftPaid'=>'El banco ha verificado el pago. Muchas gracias por tu pedido.',
      'link'=>'Abrir QR de pago','voucher'=>'Ver tarjeta regalo','pay'=>'Comprueba la cuenta, el importe exacto y la referencia antes de pagar.',
      'fields'=>['Objednávka'=>'Pedido','Masáž'=>'Masaje','Délka'=>'Duración','Počet masáží'=>'Sesiones','Cena'=>'Precio','Variabilní symbol'=>'Referencia de pago','Pro koho'=>'Destinatario','Preferovaný termín'=>'Fecha preferida']
    ],
    'zh'=>[
      'salutation'=>'您好，',
      'subjectNew'=>'🌿 订单已收到 • 我的按摩','subjectPaid'=>'✅ 付款已确认 • 我的按摩','subjectGift'=>'🎁 您的礼品券 • 我的按摩',
      'headNew'=>'您的订单已收到','headGift'=>'礼品券等待付款','headPaid'=>'付款已确认','headPaidGift'=>'您的礼品券已准备好',
      'introNew'=>'感谢您的订单。请使用付款二维码及付款识别码支付。预约时间仍需另行确认。',
      'introPaid'=>'银行已确认收款。按摩预约时间将另行确认。',
      'introGiftPaid'=>'银行已核实收款。感谢您的订单。',
      'link'=>'打开付款二维码','voucher'=>'查看礼品券','pay'=>'付款前请核对银行账户、金额和付款识别码。',
      'fields'=>['Objednávka'=>'订单','Masáž'=>'按摩','Délka'=>'时长','Počet masáží'=>'次数','Cena'=>'价格','Variabilní symbol'=>'付款识别码','Pro koho'=>'赠予对象','Preferovaný termín'=>'期望时间']
    ]
  ];
  $L=$localizations[$lang]??null;
  if($L!==null && !$admin){
    $salutation=$L['salutation'];
    $head=$paid?($gift?$L['headPaidGift']:$L['headPaid']):($gift?$L['headGift']:$L['headNew']);
    $intro=$paid?($gift?$L['introGiftPaid']:$L['introPaid']):$L['introNew'];
    $subject=$paid?($gift?$L['subjectGift']:$L['subjectPaid']):$L['subjectNew'];
  }
  $fields=[
   'Objednávka'=>$gift?'Dárkový poukaz':'Rezervace masáže',
   'Masáž'=>MM_FOCUS[$o['focus']]??$o['focus'],
   'Délka'=>(string)$o['duration'].' minut',
   'Počet masáží'=>(string)$o['quantity'],
   'Cena'=>mm_money((int)$o['amount_cents'],$o['currency']??'CZK'),
   'Variabilní symbol'=>$o['vs'],
  ];
  if($admin){$fields['Klient']=$o['buyer_name'];$fields['E-mail']=$o['email'];$fields['Telefon']=$o['phone'];}
  if($gift&&$o['recipient']!=='')$fields['Pro koho']=$o['recipient'];
  if(!$gift&&$o['preferred_date']!=='')$fields['Preferovaný termín']=$o['preferred_date'].' '.$o['preferred_time'];
  if($admin&&$o['note']!=='')$fields['Poznámka']=$o['note'];
  if($L!==null && !$admin){
    $translated=[];foreach($fields as $label=>$value)$translated[$L['fields'][$label]??$label]=$value;
    $fields=$translated;
  }
  $url='https://moje-masaze.jirijanousek.cz/voucher.php?token='.rawurlencode($o['token']);
  $payUrl='https://moje-masaze.jirijanousek.cz/payment.php?token='.rawurlencode($o['token']);
  if($paid&&$gift&&!$admin)$intro.=' '.($L['voucher']??'Váš poukaz si můžete zobrazit zde:').' '.$url;
  if(!$paid&&!$admin)$intro.=' '.($L['link']??'QR platbu si otevřete zde:').' '.$payUrl;
  $plain=($admin?'Dobrý den, administrátore,':$salutation)."\n\n".$head."\n\n".$intro."\n";
  foreach($fields as $key=>$value)$plain.=$key.': '.$value."\n";
  if(!$paid)$plain.="Účet/IBAN: ".(($o['currency']??'CZK')==='EUR'?($o['bank_iban']?:mm_eur_iban()):MM_ACCOUNT)."\nIBAN: ".(($o['currency']??'CZK')==='EUR'?($o['bank_iban']?:mm_eur_iban()):MM_IBAN)."\n";
  $plain.="\nJiří Janoušek | Moje masáže\ninfo@jirijanousek.cz\n";
  $rows='';foreach($fields as $key=>$value){$rows.='<tr><td style="padding:10px;border-bottom:1px solid #eee4d6;color:#6c6a61">'.mm_escape($key).'</td><td style="padding:10px;border-bottom:1px solid #eee4d6;color:#2e382f;font-weight:700">'.mm_escape($value).'</td></tr>';}
  $extra='';
  if(!$paid && !$admin)$extra='<p style="margin:22px 0"><a href="'.mm_escape($payUrl).'" style="display:inline-block;background:#b08351;color:#fff;padding:13px 22px;text-decoration:none;border-radius:8px;font-weight:bold">'.mm_escape($L['link']??'📱 Otevřít platební QR kód').'</a></p>';
  if(!$paid)$extra.='<p style="background:#f9f2e5;padding:15px;border-radius:9px">Před potvrzením úhrady zkontrolujte měnu, částku, účet <strong>'.mm_escape(($o['currency']??'CZK')==='EUR'?($o['bank_iban']?:mm_eur_iban()):MM_ACCOUNT).'</strong> a VS <strong>'.mm_escape($o['vs']).'</strong>. U EUR platby uveďte také referenci MMVS-'.mm_escape($o['vs']).' ve zprávě pro příjemce. Samotný QR kód není dokladem platby.</p>';
  if($paid&&$gift&&!$admin)$extra='<p style="margin:26px 0"><a href="'.mm_escape($url).'" style="background:#aa7d47;color:#fff;padding:13px 24px;text-decoration:none;border-radius:8px;font-weight:700">🎁 '.mm_escape($L['voucher']??'Zobrazit dárkový poukaz').'</a></p>';
  if($paid && $gift && !$admin){
    require_once __DIR__.'/coupon.php';
    $extra=mm_coupon_html($o,true);
  }
  $html='<!doctype html><html lang="cs"><head><meta charset="utf-8"></head><body style="background:#f6f1e8;margin:0;padding:24px 10px;font-family:Arial,Helvetica,sans-serif;color:#273227"><table role="presentation" style="max-width:640px;width:100%;border-collapse:collapse;margin:auto;background:#fff;border:1px solid #e4d8c7"><tr><td style="padding:29px;background:#1d2a21;color:#fff;border-bottom:5px solid #c7a06d"><div style="font-family:Georgia,serif;font-size:27px;color:#e8c99d">❁ DOTYK &amp; KLID</div><div style="font-size:12px;letter-spacing:2px;color:#e5e6da">MOJE MASÁŽE • JIŘÍ JANOUŠEK</div></td></tr><tr><td style="padding:28px"><p>'.mm_escape($admin?'Dobrý den, administrátore,':$salutation).'</p><h1 style="font-family:Georgia,serif;font-size:27px;font-weight:400">'.mm_escape($head).'</h1><p>'.mm_escape($intro).'</p><table role="presentation" style="width:100%;background:#fbf8f2;border-collapse:collapse">'.$rows.'</table>'.$extra.'<p style="margin-top:30px;font-size:13px;color:#696b63">S přáním krásného dne,<br><strong>Jiří Janoušek</strong><br>info@jirijanousek.cz<br>+420 777 796 148</p></td></tr><tr><td style="background:#e9e6dd;padding:15px 28px;font-size:11px;color:#737267">Tato zpráva se týká výhradně vaší objednávky. Nejde o reklamní sdělení.</td></tr></table></body></html>';
  return ['subject'=>$subject,'text'=>$plain,'html'=>$html,'inline_qr_token'=>($paid&&$gift&&!$admin)?$o['token']:null];
}
function mm_voucher_qr_png(string $token): ?string {
 if(!preg_match('/\A[a-f0-9]{48}\z/D',$token)||!function_exists('imagepng'))return null;
 require_once __DIR__.'/vendor/php-qrcode.php';
 try{
  $generator=new QRCode('https://moje-masaze.jirijanousek.cz/checkin.php?code='.$token,['s'=>'qrm','sf'=>6,'p'=>15]);
  $img=$generator->render_image();
  ob_start();imagepng($img);$bytes=ob_get_clean();imagedestroy($img);
  return is_string($bytes)&&strlen($bytes)>500&&strlen($bytes)<1000000?$bytes:null;
 }catch(Throwable $e){error_log('MM voucher QR generation: '.get_class($e));return null;}
}
function mm_send(string $to,array $template): bool {
 if(!filter_var($to,FILTER_VALIDATE_EMAIL))return false;
 $boundary='mm_alt_'.bin2hex(random_bytes(9));
 $outer='mm_rel_'.bin2hex(random_bytes(9));
 $html=(string)$template['html'];$plain=(string)$template['text'];
 $png=null;
 $token=$template['inline_qr_token']??null;
 if(is_string($token)&&preg_match('/\A[a-f0-9]{48}\z/D',$token))$png=mm_voucher_qr_png($token);
 if($png===null){
  $html=str_replace('cid:mm-gift-qr@moje-masaze','https://moje-masaze.jirijanousek.cz/voucher-qr.php?token='.rawurlencode((string)$token),$html);
 }
 $headers='From: '.mm_header_encoded('Jiří Janoušek • Moje masáže').' <info@jirijanousek.cz>'."\r\n".
    'Reply-To: info@jirijanousek.cz'."\r\n".
    'MIME-Version: 1.0'."\r\n".
    'Content-Type: '.($png!==null?'multipart/related; boundary="'.$outer.'"':'multipart/alternative; boundary="'.$boundary.'"');
 $body='';
 if($png!==null){
  $body.='--'.$outer."\r\n".'Content-Type: multipart/alternative; boundary="'.$boundary.'"'."\r\n\r\n";
 }
 $body.='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".
   chunk_split(base64_encode($plain)).
   '--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".
   chunk_split(base64_encode($html)).
   '--'.$boundary."--\r\n";
 if($png!==null){
  $body.='--'.$outer."\r\n".
   "Content-Type: image/png; name=\"vstupni-qr.png\"\r\n".
   "Content-ID: <mm-gift-qr@moje-masaze>\r\n".
   "Content-Disposition: inline; filename=\"vstupni-qr.png\"\r\n".
   "Content-Transfer-Encoding: base64\r\n\r\n".
   chunk_split(base64_encode($png)).
   '--'.$outer."--\r\n";
 }
 return mail($to,mm_header_encoded((string)$template['subject']),$body,$headers);
}
function mm_dispatch_mail(int $max=20): array {
  $db=mm_db();$rows=$db->query("SELECT q.id AS queue_id,q.kind AS mail_kind,o.* FROM email_queue q JOIN orders o ON o.id=q.order_id WHERE q.status IN ('pending','failed') ORDER BY q.id LIMIT ".max(1,min(50,$max)))->fetchAll();
  $count=['sent'=>0,'failed'=>0];
  foreach($rows as $o){
    $q=$db->prepare("UPDATE email_queue SET status='sending' WHERE id=? AND status IN ('pending','failed')");$q->execute([$o['queue_id']]);if($q->rowCount()!==1)continue;
    $template=mm_mail_template($o,$o['mail_kind']);
    try{$ok=mm_send(str_ends_with($o['mail_kind'],'_admin')?mm_admin():$o['email'],$template);}catch(Throwable $e){$ok=false;error_log('MM mail transport: '.get_class($e));}
    $db->prepare('UPDATE email_queue SET status=? WHERE id=? AND status=?')->execute([$ok?'sent':'failed',$o['queue_id'],'sending']);
    $count[$ok?'sent':'failed']++;
  }
  return $count;
}

/** Administrator-only status updates. Never alter bank-confirmed payment state manually. */
function mm_admin_update(string $id,string $action,array $data): array {
 $db=mm_db();
 if(!preg_match('/\A[a-f0-9]{24}\z/D',$id))throw new InvalidArgumentException('Neplatné ID.');
 $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();
 if(!$o)throw new InvalidArgumentException('Objednávka nenalezena.');
 $transaction=in_array($action,['confirm','schedule_gift'],true);
 if($transaction){
  $newDate=(string)($data['confirmed_date']??'');$newTime=(string)($data['confirmed_time']??'');
  if($newDate!==''&&$newTime!==''&&($newDate!==$o['confirmed_date']||$newTime!==$o['confirmed_time'])){
    require_once __DIR__.'/google-calendar-lib.php';
    if(!mm_google_slot_free($newDate,$newTime,(int)$o['duration']))throw new InvalidArgumentException('Termín je obsazený v Google Kalendáři.');
  }
  $db->exec('BEGIN IMMEDIATE');
 }
 try {
 if($action==='redeem'){
  if($o['kind']!=='gift'||$o['status']!=='paid')throw new InvalidArgumentException('Poukaz musí být uhrazený.');
  $sql="UPDATE orders SET redeemed_count=redeemed_count+1,redeemed_at=? WHERE id=? AND kind='gift' AND status='paid' AND redeemed_count<quantity";
  $update=$db->prepare($sql);$update->execute([time(),$id]);
  if($update->rowCount()!==1)throw new InvalidArgumentException('Poukaz je již plně využit.');
 }elseif($action==='confirm'||$action==='schedule_gift'){
  $expectedKind=$action==='schedule_gift'?'gift':'booking';
  if($o['kind']!==$expectedKind)throw new InvalidArgumentException('Nevhodný typ rezervace.');
  if($expectedKind==='gift'&&$o['status']!=='paid')throw new InvalidArgumentException('Termín poukazu lze potvrdit až po uhrazení.');
  $date=mm_text($data['confirmed_date']??'',10,true);
  $time=mm_text($data['confirmed_time']??'',5,true);
  $dateObj=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Europe/Prague'));
  if(!$dateObj||$dateObj->format('Y-m-d')!==$date||$dateObj<(new DateTimeImmutable('today',new DateTimeZone('Europe/Prague'))))throw new InvalidArgumentException('Datum musí být dnes nebo později.');
  if(!preg_match('/\A(?:0[7-9]|1[0-9]|2[01]):(?:00|15|30|45)\z/D',$time))throw new InvalidArgumentException('Neplatný čas.');
  if(!mm_slot_is_free($date,$time,(int)$o['duration'],$id))throw new InvalidArgumentException('Termín koliduje s jinou masáží nebo blokací kalendáře.');
  $update=$db->prepare("UPDATE orders SET appointment_status='confirmed',confirmed_date=?,confirmed_time=? WHERE id=? AND kind=?");
  $update->execute([$date,$time,$id,$expectedKind]);
 }elseif($action==='cancel'||$action==='complete'){
  if($o['kind']!=='booking')throw new InvalidArgumentException('Nevhodná akce.');
  $update=$db->prepare('UPDATE orders SET appointment_status=? WHERE id=?');
  $update->execute([$action==='cancel'?'cancelled':'completed',$id]);
 }else{throw new InvalidArgumentException('Neplatná akce.');}
 if($transaction)$db->commit();
 }catch(Throwable $e){if($transaction&&$db->inTransaction())$db->rollBack();throw $e;}
 $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function mm_appointment_notice(array $o,string $action): bool {
 if(!in_array($action,['confirm','schedule_gift','cancel'],true))return true;
 if($action==='schedule_gift'&&$o['kind']==='gift'&&$o['status']==='paid'){
  require_once __DIR__.'/coupon.php';
  return mm_send($o['email'],mm_mail_template($o,'paid_client'));
 }
 $confirmed=$action==='confirm';
 $heading=$confirmed?'Potvrzení termínu masáže':'Zrušení termínu masáže';
 $details=$confirmed
   ?'Potvrzujeme termín '.($o['confirmed_date']??'').' v '.($o['confirmed_time']??'').'.'
   :'Domluvený termín byl zrušen. Pro nový termín nás prosím kontaktujte.';
 $plain=mm_salutation((string)$o['buyer_name'])."\n\n".$heading."\n".$details."\n".MM_FOCUS[$o['focus']]."\n\nJiří Janoušek | Moje masáže\n";
 $html='<!doctype html><html lang="cs"><meta charset="utf-8"><body style="background:#f7f1e7;padding:18px;font-family:Arial,sans-serif"><table style="max-width:560px;margin:auto;background:white;border-collapse:collapse"><tr><td style="padding:25px;background:#1d2a21;color:#efdab5;font-size:24px;font-family:Georgia,serif">❁ DOTYK &amp; KLID</td></tr><tr><td style="padding:28px"><h1 style="font-family:Georgia,serif;font-weight:400">'.mm_escape($heading).'</h1><p>'.mm_escape(mm_salutation((string)$o['buyer_name'])).'</p><p>'.mm_escape($details).'</p><p><strong>'.mm_escape(MM_FOCUS[$o['focus']]).'</strong> • '.(int)$o['duration'].' minut</p><p>Jiří Janoušek<br>info@jirijanousek.cz</p></td></tr></table></body></html>';
 return mm_send($o['email'],['subject'=>($confirmed?'📅':'ℹ️').' '.$heading.' | Moje masáže','text'=>$plain,'html'=>$html]);
}
