<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib.php';
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
  'CZK Fio token'=>(function(){
    $token=getenv('MASSAGE_FIO_TOKEN');if(is_string($token)&&$token!=='')return 'configured in CLI environment';
    $path=mm_private_dir().'/fio-token.txt';$mode=is_file($path)?@fileperms($path):false;
    return $mode!==false&&($mode&0077)===0?'private token file present':'missing';
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
