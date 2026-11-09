<?php
declare(strict_types=1);
/**
 * Read-only private iCalendar subscription for Google Calendar / Apple Calendar.
 * The secret URL is a bearer credential: never display it publicly.
 * This feeds massage reservations to Google, but does not import Google busy times.
 */
ini_set('display_errors','0');
require_once __DIR__.'/lib.php';
header('X-Robots-Tag: noindex,nofollow');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$key=$_GET['key']??'';
if(!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key)){http_response_code(403);exit;}
try{
 if(!hash_equals(mm_calendar_secret(),$key)){http_response_code(403);exit;}
 $orders=mm_db()->query("SELECT id,focus,kind,buyer_name,recipient,duration,vs,status,confirmed_date,confirmed_time,created_at
 FROM orders WHERE appointment_status='confirmed' AND confirmed_date<>'' AND confirmed_time<>'' ORDER BY confirmed_date")->fetchAll();
 $blocks=mm_db()->query("SELECT id,block_date,start_time,end_time FROM massage_blocks ORDER BY block_date")->fetchAll();
}catch(Throwable $e){http_response_code(503);exit;}
function esc_ics(string $v): string{return str_replace(["\\",",",";","\r\n","\n","\r"],["\\\\","\\,","\\;","\\n","\\n","\\n"],$v);}
function fold_ics(string $line): string {
 if(strlen($line)<=70)return $line;
 $chunks=[];$current='';
 foreach(preg_split('//u',$line,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){
  if(strlen($current)+strlen($char)>70){$chunks[]=$current;$current=' '.$char;}else{$current.=$char;}
 }
 $chunks[]=$current;return implode("\r\n",$chunks);
}
$tz=new DateTimeZone('Europe/Prague');
$lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Jiří Janoušek//Moje masáže//CS','CALSCALE:GREGORIAN','METHOD:PUBLISH','X-WR-CALNAME:Moje masáže','X-WR-TIMEZONE:Europe/Prague','REFRESH-INTERVAL;VALUE=DURATION:PT1H'];
foreach($orders as $o){
 try{
  $start=new DateTimeImmutable($o['confirmed_date'].' '.$o['confirmed_time'],$tz);
  $end=$start->modify('+'.(int)$o['duration'].' minutes');
  $name=$o['kind']==='gift'?$o['recipient']:$o['buyer_name'];
  $title='Masáž '.($o['kind']==='gift'?'– poukaz ':'– ').($name?:'klient').' ('.$o['duration'].' min)';
  $desc='Moje masáže. '.(MM_FOCUS[$o['focus']]??'Masáž').'. VS '.$o['vs'].'. Úhrada: '.($o['status']==='paid'?'potvrzena':'čeká se').'.';
  $lines=array_merge($lines,[
    'BEGIN:VEVENT',
    'UID:mm-'.esc_ics($o['id']).'@moje-masaze.jirijanousek.cz',
    'DTSTAMP:'.gmdate('Ymd\THis\Z',(int)$o['created_at']),
    'DTSTART:'.$start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
    'DTEND:'.$end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
    'SUMMARY:'.esc_ics($title),
    'DESCRIPTION:'.esc_ics($desc),
    'STATUS:CONFIRMED',
    'END:VEVENT'
  ]);
 }catch(Throwable $e){continue;}
}
foreach($blocks as $b){
 try{
  $start=new DateTimeImmutable($b['block_date'].' '.$b['start_time'],$tz);
  $end=new DateTimeImmutable($b['block_date'].' '.$b['end_time'],$tz);
  $lines=array_merge($lines,[
   'BEGIN:VEVENT','UID:mm-block-'.(int)$b['id'].'@moje-masaze.jirijanousek.cz',
   'DTSTAMP:'.$start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
   'DTSTART:'.$start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
   'DTEND:'.$end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
   'SUMMARY:Soukromá blokace masáží','STATUS:CONFIRMED','END:VEVENT'
  ]);
 }catch(Throwable $e){continue;}
}
$lines[]='END:VCALENDAR';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="moje-masaze.ics"');
echo implode("\r\n",array_map('fold_ics',$lines))."\r\n";
