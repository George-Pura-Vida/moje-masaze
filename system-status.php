<?php
declare(strict_types=1);
/**
 * Privacy-safe readiness check for the public booking API.
 * Never returns paths, client data, tokens, emails, or PHP errors.
 */
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex,nofollow,noarchive');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit('{"ok":false}');}
$checks=['php'=>true,'sqlite'=>extension_loaded('pdo_sqlite'),'storage'=>false,'database'=>false,'database_write'=>false,'contact'=>false];
try{
 require_once __DIR__.'/lib.php';
 $dir=mm_private_dir();
 $checks['storage']=is_dir($dir)&&is_writable($dir);
 if($checks['sqlite']&&$checks['storage']){
  $db=mm_db();
  $checks['database']=(bool)$db->query('SELECT 1')->fetchColumn();
  $checks['contact']=(bool)$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='inquiries'")->fetchColumn();
  // Test the exact SQLite WRITE permission needed by booking and contact forms.
  try {
   $db->exec('BEGIN IMMEDIATE');
   try {
    $q=$db->prepare("INSERT INTO meta(key,value) VALUES('healthcheck_write',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    $q->execute([bin2hex(random_bytes(6))]);
   } finally {$db->exec('ROLLBACK');}
   $checks['database_write']=true;
  } catch(Throwable $writeError) {
   error_log('MM readiness: SQLite write check failed: '.get_class($writeError).' '.substr($writeError->getMessage(),0,180));
  }
 }
}catch(Throwable $e){error_log('MM readiness: '.get_class($e).' '.$e->getMessage());}
$ok=!in_array(false,$checks,true);
http_response_code($ok?200:503);
echo json_encode(['ok'=>$ok,'checks'=>$checks,'hint'=>$ok?'Objednávkový server a kontaktní databáze jsou dostupné.':'Serverová databáze není připravena. Zkontrolujte pdo_sqlite a MASSAGE_PRIVATE_DIR mimo webroot na WebKitty.'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
