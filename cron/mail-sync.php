<?php
declare(strict_types=1);
/** Deliver pending contact acknowledgements and operational order emails.
 * Separate from Fio reconciliation so ordinary contacts still work without bank API.
 * Call only through PHP CLI, every 5 minutes, with a private data directory.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require_once dirname(__DIR__).'/lib.php';
$path=mm_private_dir().'/mail-sync.lock';
$lock=fopen($path,'c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){exit(0);}
try{
 $db=mm_db();
 $orders=mm_dispatch_mail(50);
 $contacts=mm_crm_dispatch_inquiries(30);
 printf("Massage mail sync: orders_sent=%d orders_failed=%d contacts_sent=%d contacts_failed=%d\n",
 (int)$orders['sent'],(int)$orders['failed'],(int)$contacts['sent'],(int)$contacts['failed']);
}catch(Throwable $e){
 error_log('MM mail sync: '.get_class($e));
 fwrite(STDERR,"Mail delivery job failed; inspect server error log.\n");
 exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}
