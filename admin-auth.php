<?php
declare(strict_types=1);
/** Secure owner-only login credentials and one-time email password reset. */
const MM_ADMIN_LOGIN='jgjanousek@gmail.com';
function mm_admin_hash(): string {
 // A local file takes precedence over an older server environment hash, allowing password reset.
 $file=mm_private_dir().'/admin-hash.txt';
 if(is_file($file)&&!is_link($file)&&is_readable($file)){
  $mode=@fileperms($file);
  if($mode!==false&&($mode&0077)===0){
   $value=trim((string)file_get_contents($file));
   if($value!==''&&strlen($value)<260&&password_get_info($value)['algo']!==null)return $value;
  }
 }
 $env=getenv('MASSAGE_ADMIN_PASSWORD_HASH');
 return is_string($env)&&$env!==''?$env:'';
}
function mm_admin_valid_new_password(mixed $p,mixed $confirm): string {
 if(!is_string($p)||!is_string($confirm)||strlen($p)<12||strlen($p)>128||!hash_equals($p,$confirm)){
  throw new InvalidArgumentException('Heslo musí mít alespoň 12 znaků, nejvýše 128 znaků a obě zadání se musí shodovat.');
 }
 return $p;
}
function mm_admin_save_password(string $newPassword): void {
 if(strlen($newPassword)<12||strlen($newPassword)>128)throw new InvalidArgumentException('Zvolte heslo o délce 12 až 128 znaků.');
 $base=mm_private_dir();$file=$base.'/admin-hash.txt';
 if(is_link($file))throw new RuntimeException('Bezpečnostní chyba úložiště hesla.');
 $hash=password_hash($newPassword,PASSWORD_DEFAULT);
 if(!is_string($hash)||$hash==='')throw new RuntimeException('Heslo nelze bezpečně uložit.');
 $temp=$base.'/admin-hash.'.bin2hex(random_bytes(8)).'.tmp';
 if(file_put_contents($temp,$hash."\n",LOCK_EX)===false)throw new RuntimeException('Úložiště hesla není zapisovatelné.');
 if(!@chmod($temp,0600)||!@rename($temp,$file)){@unlink($temp);throw new RuntimeException('Bezpečné uložení hesla selhalo.');}
 mm_admin_invalidate_sessions();
}
function mm_admin_reset_init(PDO $db): void {
 $db->exec("CREATE TABLE IF NOT EXISTS admin_password_resets(
  token_hash TEXT PRIMARY KEY,
  issued_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  used_at INTEGER
 );
 CREATE INDEX IF NOT EXISTS reset_expiry_idx ON admin_password_resets(expires_at)");
}
function mm_admin_invalidate_sessions(): void {
 $db=mm_db();
 $v=bin2hex(random_bytes(16));
 $q=$db->prepare("INSERT INTO meta(key,value) VALUES('admin_session_version',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
 $q->execute([$v]);
}
function mm_admin_session_version(): string {
 $db=mm_db();
 $q=$db->query("SELECT value FROM meta WHERE key='admin_session_version'");
 $v=$q->fetchColumn();
 if(!is_string($v)||strlen($v)<8){mm_admin_invalidate_sessions();return mm_admin_session_version();}
 return $v;
}
function mm_admin_is_logged_in(): bool {
 if(($_SESSION['mm_admin_auth']??false)!==true)return false;
 $saved=$_SESSION['mm_admin_version']??'';
 return is_string($saved)&&hash_equals(mm_admin_session_version(),$saved);
}
function mm_admin_generate_reset(): string {
 $db=mm_db();mm_admin_reset_init($db);
 $token=bin2hex(random_bytes(32));
 $hash=hash('sha256',$token);$now=time();
 $db->prepare('DELETE FROM admin_password_resets WHERE expires_at<? OR used_at IS NOT NULL')->execute([$now]);
 $db->prepare('INSERT INTO admin_password_resets(token_hash,issued_at,expires_at) VALUES(?,?,?)')->execute([$hash,$now,$now+1800]);
 return $token;
}
function mm_admin_reset_is_valid(mixed $token): bool {
 if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token))return false;
 $db=mm_db();mm_admin_reset_init($db);
 $q=$db->prepare("SELECT 1 FROM admin_password_resets WHERE token_hash=? AND expires_at>? AND used_at IS NULL");
 $q->execute([hash('sha256',$token),time()]);
 return (bool)$q->fetchColumn();
}
function mm_admin_request_reset(mixed $email,string $ip): void {
 mm_rate_limit('admin-reset:'.$ip);
 // Generic response for all e-mails; only the verified owner receives the reset link.
 if(!is_string($email)||!hash_equals(MM_ADMIN_LOGIN,strtolower(trim($email)))||mm_admin_hash()==='')return;
 $db=mm_db();mm_admin_reset_init($db);
 $q=$db->prepare('SELECT COUNT(*) FROM admin_password_resets WHERE issued_at>?');
 $q->execute([time()-3600]);
 if((int)$q->fetchColumn()>=3)return;
 $token=mm_admin_generate_reset();
 $url='https://moje-masaze.jirijanousek.cz/admin.php?reset='.rawurlencode($token);
 $subject='🔐 Obnovení hesla administrace Moje masáže';
 $html='<!doctype html><html lang="cs"><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f5efe5;padding:25px;color:#283e31"><table style="max-width:580px;margin:auto;width:100%;background:white;border-collapse:collapse"><tr><td style="background:#20392b;color:#edd1a4;padding:25px;font:27px Georgia,serif">❁ DOTYK &amp; KLID</td></tr><tr><td style="padding:28px"><h1 style="font:28px Georgia,serif">Obnovení administrátorského hesla</h1><p>Pro účet <b>jgjanousek@gmail.com</b> byl vyžádán odkaz na obnovu hesla. Odkaz lze použít pouze jednou a platí 30 minut.</p><p style="margin:27px 0"><a href="'.mm_escape($url).'" style="background:#294732;color:white;text-decoration:none;padding:14px 25px;border-radius:9px">Nastavit nové heslo</a></p><p style="font-size:13px;color:#6e756d">Pokud jste o obnovu nežádal, zprávu ignorujte. Vaše dosavadní heslo zůstává nezměněné.</p></td></tr></table></body></html>';
 $text="Obnovení hesla administrace Moje masáže.\n\nOdkaz pro jednorázové nastavení nového hesla (platí 30 minut):\n".$url."\n\nPokud jste o změnu nežádal, tento e-mail ignorujte.";
 if(!mm_send(MM_ADMIN_LOGIN,['subject'=>$subject,'html'=>$html,'text'=>$text]))error_log('MM admin reset email was not accepted by mail transport.');
}
function mm_admin_complete_reset(string $token,string $newPassword,string $repeat): void {
 mm_admin_valid_new_password($newPassword,$repeat);
 if(!mm_admin_reset_is_valid($token))throw new InvalidArgumentException('Odkaz pro obnovu je neplatný nebo již vypršel.');
 $db=mm_db();$db->exec('BEGIN IMMEDIATE');
 try{
  $q=$db->prepare("UPDATE admin_password_resets SET used_at=? WHERE token_hash=? AND expires_at>? AND used_at IS NULL");
  $q->execute([time(),hash('sha256',$token),time()]);
  if($q->rowCount()!==1)throw new InvalidArgumentException('Odkaz už byl použit nebo vypršel.');
  mm_admin_save_password($newPassword);
  $db->exec('UPDATE admin_password_resets SET used_at='.time().' WHERE used_at IS NULL');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
