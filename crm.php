<?php
declare(strict_types=1);
/** Private CRM data only; included from lib.php after database initialization. */
function mm_crm_migrate(PDO $db): void {
 $db->exec("CREATE TABLE IF NOT EXISTS clients (
  id TEXT PRIMARY KEY,
  full_name TEXT NOT NULL,
  email TEXT NOT NULL COLLATE NOCASE UNIQUE,
  phone TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  archived INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
 );
 CREATE TABLE IF NOT EXISTS inquiries (
  id TEXT PRIMARY KEY,
  client_id TEXT NOT NULL DEFAULT '',
  sender_name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','read','resolved')),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
 );
 CREATE TABLE IF NOT EXISTS inquiry_email_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  inquiry_id TEXT NOT NULL,
  recipient_kind TEXT NOT NULL CHECK(recipient_kind IN ('client','admin')),
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','sending','sent','failed')),
  created_at INTEGER NOT NULL,
  UNIQUE(inquiry_id,recipient_kind)
 );
 CREATE INDEX IF NOT EXISTS clients_created_idx ON clients(created_at DESC);
 CREATE INDEX IF NOT EXISTS inquiries_created_idx ON inquiries(created_at DESC);
 CREATE INDEX IF NOT EXISTS orders_client_idx ON orders(client_id);
 CREATE INDEX IF NOT EXISTS orders_date_idx ON orders(confirmed_date,preferred_date);
 ");
 $q=$db->query("SELECT id,buyer_name,email,phone,created_at FROM orders WHERE client_id='' ORDER BY created_at ASC LIMIT 10000");
 $assign=$db->prepare("UPDATE orders SET client_id=? WHERE id=? AND client_id=''");
 foreach($q->fetchAll() as $r){
  if(!filter_var($r['email'],FILTER_VALIDATE_EMAIL))continue;
  $cid=mm_crm_upsert_client($db,(string)$r['buyer_name'],(string)$r['email'],(string)$r['phone'],(int)$r['created_at']);
  $assign->execute([$cid,$r['id']]);
 }
}
function mm_crm_upsert_client(PDO $db,string $name,string $email,string $phone,int $timestamp): string {
 $email=strtolower(trim($email));
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Neplatný e-mail.');
 $q=$db->prepare('SELECT id,full_name,phone FROM clients WHERE email=? COLLATE NOCASE');
 $q->execute([$email]);$row=$q->fetch();
 if($row){
  if($row['phone']===''&&$phone!==''){
   $db->prepare('UPDATE clients SET phone=?,updated_at=? WHERE id=?')->execute([$phone,time(),$row['id']]);
  }
  return (string)$row['id'];
 }
 $id=bin2hex(random_bytes(12));
 $db->prepare('INSERT INTO clients(id,full_name,email,phone,created_at,updated_at) VALUES(?,?,?,?,?,?)')
  ->execute([$id,$name,$email,$phone,$timestamp,time()]);
 return $id;
}
function mm_crm_phone(string $phone): string {
 if($phone!==''&&!preg_match('/\A[+0-9() \-]{7,25}\z/D',$phone))throw new InvalidArgumentException('Neplatný telefon.');
 return $phone;
}
function mm_crm_save_client(array $in): string {
 $name=mm_text($in['full_name']??null,120,true);
 $email=strtolower(mm_text($in['email']??null,254,true,'E-mail'));
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Neplatný e-mail.');
 $phone=mm_crm_phone(mm_text($in['phone']??'',25,false,'Telefon'));
 $notes=mm_text($in['notes']??'',2500);
 $id=(string)($in['client_id']??'');
 $db=mm_db();
 try{
  if($id===''){
   $id=bin2hex(random_bytes(12));
   $q=$db->prepare('INSERT INTO clients(id,full_name,email,phone,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
   $q->execute([$id,$name,$email,$phone,$notes,time(),time()]);
  }else{
   if(!preg_match('/\A[a-f0-9]{24}\z/D',$id))throw new InvalidArgumentException('Neplatné ID klienta.');
   $q=$db->prepare('UPDATE clients SET full_name=?,email=?,phone=?,notes=?,updated_at=? WHERE id=?');
   $q->execute([$name,$email,$phone,$notes,time(),$id]);
   if($q->rowCount()===0){$check=$db->prepare('SELECT id FROM clients WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())throw new InvalidArgumentException('Klient nenalezen.');}
  }
 }catch(PDOException $e){
  if(str_contains($e->getMessage(),'UNIQUE'))throw new InvalidArgumentException('Tento e-mail už má jiný klient.');
  throw $e;
 }
 return $id;
}
function mm_crm_archive_client(string $id,bool $archived): void {
 if(!preg_match('/\A[a-f0-9]{24}\z/D',$id))throw new InvalidArgumentException('Neplatné ID.');
 $q=mm_db()->prepare('UPDATE clients SET archived=?,updated_at=? WHERE id=?');
 $q->execute([$archived?1:0,time(),$id]);
}
function mm_crm_add_inquiry(array $in,string $ip): string {
 // Legacy hidden spam field is ignored: some password managers mistakenly fill it.
 // CSRF, minimum message length and rate limiting remain active.
 if(($in['terms']??null)!==true)throw new InvalidArgumentException('Souhlas se zpracováním je nutný.');
 if(!isset($in['csrf'])||!is_string($in['csrf'])||!hash_equals(mm_csrf(),$in['csrf']))throw new InvalidArgumentException('Obnovte stránku.');
 $name=mm_text($in['name']??null,120,true,'Jméno a příjmení');
 $email=strtolower(mm_text($in['email']??null,254,true));
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Neplatný e-mail.');
 $phone=mm_crm_phone(mm_text($in['phone']??'',25));
 $subject=mm_text($in['subject']??'Dotaz k masáži',130,true,'Předmět zprávy');
 $msg=mm_text($in['message']??null,2500,true,'Vaše zpráva');
 if(strlen($msg)<10)throw new InvalidArgumentException('Zpráva je příliš krátká.');
 mm_rate_limit('inquiry:'.$ip);
 $db=mm_db();$id=bin2hex(random_bytes(12));
 $db->exec('BEGIN IMMEDIATE');
 try{
  $cid=mm_crm_upsert_client($db,$name,$email,$phone,time());
  $q=$db->prepare('INSERT INTO inquiries(id,client_id,sender_name,email,phone,subject,message,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
  $q->execute([$id,$cid,$name,$email,$phone,$subject,$msg,'new',time(),time()]);
  $q=$db->prepare('INSERT INTO inquiry_email_queue(inquiry_id,recipient_kind,created_at) VALUES(?,?,?)');
  foreach(['admin','client'] as $role)$q->execute([$id,$role,time()]);
  $db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
 return $id;
}
function mm_crm_inquiry_template(array $r,bool $admin): array {
 $title=$admin?'Nová zpráva z kontaktního formuláře':'Děkujeme za vaši zprávu';
 $text="Dobrý den,\n\n".$title."\n\n".
  ($admin?'Klient: '.$r['sender_name']."\nE-mail: ".$r['email']."\nTelefon: ".$r['phone']."\n\n":'').
  "Předmět: ".$r['subject']."\n\n".$r['message']."\n\nJiří Janoušek | Moje masáže";
 $html='<html><body style="font-family:Arial,sans-serif;background:#f5f0e8;padding:22px;color:#29402f"><table role="presentation" style="max-width:610px;width:100%;margin:auto;background:white;border-collapse:collapse"><tr><td style="background:#20382a;padding:28px;color:#e9ce9e;font:26px Georgia,serif">❁ DOTYK &amp; KLID</td></tr><tr><td style="padding:26px"><h1 style="font:26px Georgia,serif">'.mm_escape($title).'</h1><p>Dobrý den,</p><p>'.($admin?'Přišla nová zpráva od klienta.':'Vaši zprávu jsme obdrželi a osobně odpovíme.').'</p><div style="background:#faf5ed;padding:18px;border:1px solid #e1d1b9"><b>'.mm_escape($r['subject']).'</b><p style="white-space:pre-wrap">'.nl2br(mm_escape($r['message'])).'</p></div>';
 if($admin)$html.='<p><b>'.mm_escape($r['sender_name']).'</b><br>'.mm_escape($r['email']).'<br>'.mm_escape($r['phone']).'</p>';
 $html.='<p style="margin-top:25px;color:#8e754c">Jiří Janoušek · Moje masáže</p></td></tr></table></body></html>';
 return ['subject'=>$admin?'📨 Nový dotaz · Moje masáže':'🌿 Zprávu jsme přijali · Moje masáže','text'=>$text,'html'=>$html];
}
function mm_crm_dispatch_inquiries(int $max=15): array {
 $results=['sent'=>0,'failed'=>0];
 $queueLock=mm_mail_queue_lock('inquiry-mail');
 if($queueLock===null)return $results;
 try{
 $db=mm_db();
 $db->exec("UPDATE inquiry_email_queue SET status='failed' WHERE status='sending'");
 $q=$db->query("SELECT q.id AS qid,q.recipient_kind,i.* FROM inquiry_email_queue q JOIN inquiries i ON i.id=q.inquiry_id
   WHERE q.status IN ('pending','failed') ORDER BY q.status='failed',q.id LIMIT ".max(1,min(50,$max)));
 foreach($q->fetchAll() as $i){
  $lock=$db->prepare("UPDATE inquiry_email_queue SET status='sending' WHERE id=? AND status IN ('pending','failed')");
  $lock->execute([$i['qid']]);if($lock->rowCount()!==1)continue;
  $admin=$i['recipient_kind']==='admin';
  try{$ok=mm_send($admin?mm_admin():$i['email'],mm_crm_inquiry_template($i,$admin));}
  catch(Throwable $e){$ok=false;error_log('MM inquiry mail: '.get_class($e));}
  $db->prepare('UPDATE inquiry_email_queue SET status=? WHERE id=?')->execute([$ok?'sent':'failed',$i['qid']]);
  $results[$ok?'sent':'failed']++;
 }
 return $results;
 }finally{fclose($queueLock);}
}
function mm_crm_inquiry_status(string $id,string $status): void {
 if(!preg_match('/\A[a-f0-9]{24}\z/D',$id)||!in_array($status,['new','read','resolved'],true))throw new InvalidArgumentException('Neplatný požadavek.');
 $db=mm_db();$q=$db->prepare('UPDATE inquiries SET status=?,updated_at=? WHERE id=?');
 $q->execute([$status,time(),$id]);
}
