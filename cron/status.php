<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/fio-sync.php';
ini_set('display_errors','0');
try{
 $private=mm_private_dir();
 $db=mm_db();
 $stats=[
  'PHP'=>PHP_VERSION,
  'PDO SQLite'=>extension_loaded('pdo_sqlite')?'ok':'missing',
  'cURL'=>extension_loaded('curl')?'ok':'missing',
  'Private data directory'=>is_writable($private)?'writable':'not writable',
  'Admin password hash'=>(function(){
    $hash=getenv('MASSAGE_ADMIN_PASSWORD_HASH');
    if(is_string($hash)&&$hash!=='')return 'configured';
    $path=mm_private_dir().'/admin-hash.txt';
    $mode=is_file($path)?@fileperms($path):false;
    return $mode!==false&&($mode&0077)===0?'configured':'missing';
  })(),
  'Fio read-only token'=>mm_fio_token()!==''?'configured':'missing or invalid',
  'Last successful Fio sync (UTC)'=>(function()use($db){
    $last=(int)$db->query("SELECT value FROM meta WHERE key='last_bank_sync'")->fetchColumn();
    return $last?gmdate('c',$last):'not recorded';
  })(),
  'EUR bank payments'=>mm_eur_enabled()?'enabled':'inactive',
  'Total orders'=>(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
  'Unpaid orders'=>(int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
  'Paid orders'=>(int)$db->query("SELECT COUNT(*) FROM orders WHERE status='paid'")->fetchColumn(),
  'Pending/failed e-mails'=>(int)$db->query("SELECT COUNT(*) FROM email_queue WHERE status IN ('pending','failed')")->fetchColumn(),
 ];
 foreach($stats as $key=>$value)echo $key.': '.$value.PHP_EOL;
 echo "This diagnostic does not call Fio or send emails.\n";
}catch(Throwable $e){
 fwrite(STDERR,"Diagnostic failed: ".get_class($e)."; see server logs.\n");exit(1);
}
