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
  $env=getenv('MASSAGE_PRIVATE_DIR');
  $dir=is_string($env)&&$env!==''?$env:dirname(__DIR__).'/moje-masaze-private';
  if(!str_starts_with($dir,'/'))throw new RuntimeException('Private path must be absolute.');
  if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private directory cannot be created.');
  @chmod($dir,0700);
  if(!is_writable($dir))throw new RuntimeException('Private directory not writable.');
  return $dir;
}
function mm_db(): PDO {
  static $db;
  if($db instanceof PDO)return $db;
  $file=mm_private_dir().'/orders.sqlite';
  $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $db->exec("PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS orders (
 id TEXT PRIMARY KEY,
 token TEXT NOT NULL UNIQUE,
 vs TEXT NOT NULL UNIQUE,
 kind TEXT NOT NULL CHECK(kind IN ('booking','gift')),
 focus TEXT NOT NULL,
 duration INTEGER NOT NULL CHECK(duration IN (60,180)),
 quantity INTEGER NOT NULL CHECK(quantity IN (1,2,3)),
 amount_cents INTEGER NOT NULL,
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
 fio_id TEXT UNIQUE
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
");
  @chmod($file,0600);
  return $db;
}
function mm_price(int $duration,int $quantity): int {
  if(!in_array($duration,[60,180],true)||!in_array($quantity,[1,2,3],true))throw new InvalidArgumentException('Neplatný balíček.');
  $unit=$duration===60?200000:300000;
  $percent=$quantity===1?100:($quantity===2?95:90);
  return intdiv($unit*$quantity*$percent,100);
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
  $amount=mm_price($duration,$quantity);
  $name=mm_text($in['name']??null,120,true);
  $email=mm_text($in['email']??null,254,true);
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Zadejte platný e-mail.');
  $phone=mm_text($in['phone']??null,25,true);
  if(!preg_match('/\A[0-9+()\s\-]{7,25}\z/D',$phone))throw new InvalidArgumentException('Zadejte platné telefonní číslo.');
  $recipient=mm_text($in['recipient']??'',120);
  $note=mm_text($in['note']??'',650);
  $prefDate=mm_text($in['date']??'',10);
  $prefTime=mm_text($in['time']??'',5);
  if($kind==='gift'){$prefDate='';$prefTime='';}
  if($prefDate!==''){
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$prefDate,new DateTimeZone('Europe/Prague'));
    if(!$date||$date->format('Y-m-d')!==$prefDate||$date<new DateTimeImmutable('today',new DateTimeZone('Europe/Prague'))||$date>new DateTimeImmutable('+1 year',new DateTimeZone('Europe/Prague')))throw new InvalidArgumentException('Neplatný termín.');
  }
  if($prefTime!==''&&!preg_match('/\A(?:09|10|11|12|13|14|15|16|17|18|19):00\z/D',$prefTime))throw new InvalidArgumentException('Neplatná hodina.');
  $lang=$in['lang']??'cs';if(!in_array($lang,['cs','sk','en','es','zh'],true))$lang='cs';
  $db=mm_db();$id=bin2hex(random_bytes(12));$token=bin2hex(random_bytes(24));
  for($i=0;$i<20;$i++){
    $vs='57'.(string)random_int(10000000,99999999);
    try{
      $q=$db->prepare("INSERT INTO orders (id,token,vs,kind,focus,duration,quantity,amount_cents,buyer_name,email,phone,recipient,preferred_date,preferred_time,note,lang,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $q->execute([$id,$token,$vs,$kind,$focus,$duration,$quantity,$amount,$name,$email,$phone,$recipient,$prefDate,$prefTime,$note,$lang,time()]);
      break;
    }catch(PDOException $e){
      if(str_contains($e->getMessage(),'UNIQUE constraint failed: orders.vs')&&$i<19)continue;
      throw $e;
    }
  }
  mm_queue($id,'new_client');mm_queue($id,'new_admin');
  return ['id'=>$id,'token'=>$token,'vs'=>$vs,'amount'=>$amount/100,'kind'=>$kind];
}
function mm_queue(string $id,string $kind): void {
  $q=mm_db()->prepare("INSERT OR IGNORE INTO email_queue(order_id,kind,created_at) VALUES (?,?,?)");$q->execute([$id,$kind,time()]);
}
function mm_money(int $cents): string {return number_format($cents/100,$cents%100?2:0,',',' ').' Kč';}
function mm_escape(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function mm_admin(): string {$raw=getenv('MASSAGE_ADMIN_EMAIL');return is_string($raw)&&filter_var($raw,FILTER_VALIDATE_EMAIL)?$raw:MM_ADMIN_DEFAULT;}
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
  $fields=[
   'Objednávka'=>$gift?'Dárkový poukaz':'Rezervace masáže',
   'Masáž'=>MM_FOCUS[$o['focus']]??$o['focus'],
   'Délka'=>(string)$o['duration'].' minut',
   'Počet masáží'=>(string)$o['quantity'],
   'Cena'=>mm_money((int)$o['amount_cents']),
   'Variabilní symbol'=>$o['vs'],
  ];
  if($admin){$fields['Klient']=$o['buyer_name'];$fields['E-mail']=$o['email'];$fields['Telefon']=$o['phone'];}
  if($gift&&$o['recipient']!=='')$fields['Pro koho']=$o['recipient'];
  if(!$gift&&$o['preferred_date']!=='')$fields['Preferovaný termín']=$o['preferred_date'].' '.$o['preferred_time'];
  if($admin&&$o['note']!=='')$fields['Poznámka']=$o['note'];
  $url='https://moje-masaze.jirijanousek.cz/voucher.php?token='.rawurlencode($o['token']);
  if($paid&&$gift&&!$admin)$intro.=' Váš poukaz můžete otevřít a vytisknout zde: '.$url;
  $plain=($admin?'Dobrý den, administrátore,':mm_salutation($o['buyer_name']))."\n\n".$head."\n\n".$intro."\n";
  foreach($fields as $key=>$value)$plain.=$key.': '.$value."\n";
  if(!$paid)$plain.="Účet: ".MM_ACCOUNT."\nIBAN: ".MM_IBAN."\n";
  $plain.="\nJiří Janoušek | Moje masáže\ninfo@jirijanousek.cz\n";
  $rows='';foreach($fields as $key=>$value){$rows.='<tr><td style="padding:10px;border-bottom:1px solid #eee4d6;color:#6c6a61">'.mm_escape($key).'</td><td style="padding:10px;border-bottom:1px solid #eee4d6;color:#2e382f;font-weight:700">'.mm_escape($value).'</td></tr>';}
  $extra='';
  if(!$paid)$extra='<p style="background:#f9f2e5;padding:15px;border-radius:9px">Před potvrzením úhrady zkontrolujte částku, účet <strong>'.MM_ACCOUNT.'</strong> a VS <strong>'.mm_escape($o['vs']).'</strong>. Potvrzení banky není totéž co vytvoření QR kódu.</p>';
  if($paid&&$gift&&!$admin)$extra='<p style="margin:26px 0"><a href="'.mm_escape($url).'" style="background:#aa7d47;color:#fff;padding:13px 24px;text-decoration:none;border-radius:8px;font-weight:700">🎁 Zobrazit dárkový poukaz</a></p>';
  $html='<!doctype html><html lang="cs"><head><meta charset="utf-8"></head><body style="background:#f6f1e8;margin:0;padding:24px 10px;font-family:Arial,Helvetica,sans-serif;color:#273227"><table role="presentation" style="max-width:640px;width:100%;border-collapse:collapse;margin:auto;background:#fff;border:1px solid #e4d8c7"><tr><td style="padding:29px;background:#1d2a21;color:#fff;border-bottom:5px solid #c7a06d"><div style="font-family:Georgia,serif;font-size:27px;color:#e8c99d">❁ DOTYK &amp; KLID</div><div style="font-size:12px;letter-spacing:2px;color:#e5e6da">MOJE MASÁŽE • JIŘÍ JANOUŠEK</div></td></tr><tr><td style="padding:28px"><p>'.mm_escape($admin?'Dobrý den, administrátore,':mm_salutation($o['buyer_name'])).'</p><h1 style="font-family:Georgia,serif;font-size:27px;font-weight:400">'.mm_escape($head).'</h1><p>'.mm_escape($intro).'</p><table role="presentation" style="width:100%;background:#fbf8f2;border-collapse:collapse">'.$rows.'</table>'.$extra.'<p style="margin-top:30px;font-size:13px;color:#696b63">S přáním krásného dne,<br><strong>Jiří Janoušek</strong><br>info@jirijanousek.cz<br>+420 777 796 148</p></td></tr><tr><td style="background:#e9e6dd;padding:15px 28px;font-size:11px;color:#737267">Tato zpráva se týká výhradně vaší objednávky. Nejde o reklamní sdělení.</td></tr></table></body></html>';
  return ['subject'=>$subject,'text'=>$plain,'html'=>$html];
}
function mm_send(string $to,array $template): bool {
  if(!filter_var($to,FILTER_VALIDATE_EMAIL))return false;
  $boundary='mm_'.bin2hex(random_bytes(12));
  $headers='From: '.mm_header_encoded('Jiří Janoušek • Moje masáže').' <info@jirijanousek.cz>'."\r\n".'Reply-To: info@jirijanousek.cz'."\r\n".'MIME-Version: 1.0'."\r\n".'Content-Type: multipart/alternative; boundary="'.$boundary.'"';
  $body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($template['text'])).'--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($template['html'])).'--'.$boundary."--\r\n";
  return mail($to,mm_header_encoded($template['subject']),$body,$headers);
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
