<?php
declare(strict_types=1);
/** Google Calendar API adapter. Credentials/tokens stored only outside public document root. */
const MM_GOOGLE_CALLBACK='https://moje-masaze.jirijanousek.cz/google-calendar.php';
const MM_GOOGLE_OWNER='jgjanousek@gmail.com';
function mm_google_client_id(): string {return trim(mm_config('MASSAGE_GOOGLE_CLIENT_ID'));}
function mm_google_client_secret(): string {return trim(mm_config('MASSAGE_GOOGLE_CLIENT_SECRET'));}
function mm_google_calendar_id(): string {return trim(mm_config('MASSAGE_GOOGLE_CALENDAR_ID','primary'))?:'primary';}
function mm_google_configured(): bool {
 $id=mm_google_client_id();$secret=mm_google_client_secret();
 return $id!==''&&$secret!==''&&str_ends_with($id,'.apps.googleusercontent.com');
}
function mm_google_token_path(): string {return mm_private_dir().'/google-calendar-token.json';}
function mm_google_read_token(): ?array {
 $f=mm_google_token_path();
 if(!is_file($f)||is_link($f))return null;
 $perm=@fileperms($f);
 if($perm===false||($perm&0077)!==0)throw new RuntimeException('Google token must have mode 0600.');
 $data=@file_get_contents($f,false,null,0,8193);
 if(!is_string($data)||strlen($data)>8192)return null;
 $v=json_decode($data,true);
 return is_array($v)&&isset($v['refresh_token'])&&is_string($v['refresh_token'])?$v:null;
}
function mm_google_save_token(array $new): void {
 $old=mm_google_read_token()??[];
 $refresh=$new['refresh_token']??$old['refresh_token']??'';
 $access=$new['access_token']??$old['access_token']??'';
 if(!is_string($refresh)||strlen($refresh)<20||!is_string($access)||strlen($access)<20)throw new RuntimeException('Google did not issue usable OAuth tokens. Reconnect Google account.');
 $lifetime=filter_var($new['expires_in']??3600,FILTER_VALIDATE_INT);
 $data=['refresh_token'=>$refresh,'access_token'=>$access,'expires_at'=>time()+max(60,is_int($lifetime)?$lifetime:3600)-45,'owner'=>MM_GOOGLE_OWNER];
 $f=mm_google_token_path();$tmp=mm_private_dir().'/google-'.bin2hex(random_bytes(10)).'.tmp';
 $bytes=json_encode($data,JSON_THROW_ON_ERROR);
 if(file_put_contents($tmp,$bytes,LOCK_EX)===false)throw new RuntimeException('Google token storage unavailable.');
 if(!@chmod($tmp,0600)||!@rename($tmp,$f)){@unlink($tmp);throw new RuntimeException('Cannot save private Google token.');}
}
function mm_google_connected(): bool {
 if(!mm_google_configured())return false;
 try{return mm_google_read_token()!==null;}catch(Throwable $e){return false;}
}
function mm_google_http(string $method,string $url,array $headers=[],?string $body=null): array {
 if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is needed for Google Calendar API.');
 $okHosts=['www.googleapis.com','oauth2.googleapis.com','openidconnect.googleapis.com'];
 $u=parse_url($url);
 if(!is_array($u)||($u['scheme']??'')!=='https'||!in_array($u['host']??'',$okHosts,true))throw new RuntimeException('Disallowed Google API endpoint.');
 $ch=curl_init($url);
 $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method];
 if($body!==null){$opts[CURLOPT_POSTFIELDS]=$body;}
 curl_setopt_array($ch,$opts);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
 $error=curl_error($ch);curl_close($ch);
 if(!is_string($raw)||strlen($raw)>1000000||$status===0)throw new RuntimeException('Google API network error: '.substr($error,0,100));
 $v=json_decode($raw,true);
 return ['status'=>$status,'data'=>is_array($v)?$v:[]];
}
function mm_google_token_exchange(array $post): array {
 $r=mm_google_http('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query($post));
 if($r['status']!==200||!isset($r['data']['access_token']))throw new RuntimeException('Google OAuth did not authorize access (HTTP '.$r['status'].').');
 return $r['data'];
}
function mm_google_access_token(): string {
 if(!mm_google_connected())throw new RuntimeException('Google Calendar not connected.');
 $t=mm_google_read_token();
 if(($t['expires_at']??0)>time()+90&&isset($t['access_token']))return $t['access_token'];
 $new=mm_google_token_exchange([
  'client_id'=>mm_google_client_id(),'client_secret'=>mm_google_client_secret(),
  'refresh_token'=>$t['refresh_token'],'grant_type'=>'refresh_token'
 ]);
 mm_google_save_token($new);
 return (string)$new['access_token'];
}
function mm_google_api(string $method,string $path,?array $body=null): array {
 if(!str_starts_with($path,'/calendar/v3/')||str_contains($path,'..'))throw new RuntimeException('Invalid Google Calendar path.');
 $token=mm_google_access_token();
 $headers=['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json'];
 return mm_google_http($method,'https://www.googleapis.com'.$path,$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
}
function mm_google_busy_range(DateTimeImmutable $start,DateTimeImmutable $end): array {
 if(!mm_google_connected())return [];
 if($start>=$end)throw new InvalidArgumentException('Invalid Google date range.');
 $calendar=mm_google_calendar_id();
 $r=mm_google_api('POST','/calendar/v3/freeBusy',[
  'timeMin'=>$start->format(DateTimeInterface::RFC3339),
  'timeMax'=>$end->format(DateTimeInterface::RFC3339),
  'timeZone'=>'Europe/Prague',
  'items'=>[['id'=>$calendar]],
 ]);
 if($r['status']!==200)throw new RuntimeException('Google Calendar unavailable (HTTP '.$r['status'].').');
 $record=$r['data']['calendars'][$calendar]??null;
 if(!is_array($record)||(isset($record['errors'])&&!empty($record['errors'])))throw new RuntimeException('Google Calendar free/busy permission or calendar error.');
 $busy=[];
 foreach($record['busy']??[] as $b){
  if(!isset($b['start'],$b['end']))continue;
  try{$s=(new DateTimeImmutable($b['start']))->getTimestamp();$e=(new DateTimeImmutable($b['end']))->getTimestamp();}
  catch(Throwable $x){continue;}
  if($e>$s)$busy[]=[$s,$e];
 }
 return $busy;
}
function mm_google_busy(string $date): array {
 if(!mm_google_connected())return [];
 $tz=new DateTimeZone('Europe/Prague');
 $start=new DateTimeImmutable($date.' 00:00',$tz);
 if($start->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid Google date.');
 return mm_google_busy_range($start,$start->modify('+1 day'));
}
function mm_google_overlaps(array $busy,int $start,int $end): bool {
 foreach($busy as $b){if($start<(int)$b[1]&&$end>(int)$b[0])return true;}
 return false;
}
function mm_google_slot_free(string $date,string $time,int $duration): bool {
 if(!mm_google_connected())return true;
 [$start,$end]=mm_slot_interval($date,$time,$duration);
 return !mm_google_overlaps(mm_google_busy($date),$start,$end);
}
function mm_google_event_id(array $o): string {
 // Google event IDs are deterministic: retrying never creates a second event.
 return 'mm'.strtolower((string)$o['id']);
}
function mm_google_sync_order(array $o): string {
 if(!mm_google_connected())return 'Google not connected';
 $calendar=rawurlencode(mm_google_calendar_id());
 $id=mm_google_event_id($o);
 $path='/calendar/v3/calendars/'.$calendar.'/events/'.$id;
 if(($o['appointment_status']??'')!=='confirmed'||empty($o['confirmed_date'])||empty($o['confirmed_time'])){
  $result=mm_google_api('DELETE',$path);
  if(!in_array($result['status'],[200,204,404,410],true))throw new RuntimeException('Google event removal failed.');
  return 'Google event removed';
 }
 $start=new DateTimeImmutable($o['confirmed_date'].' '.$o['confirmed_time'],new DateTimeZone('Europe/Prague'));
 $end=$start->modify('+'.(int)$o['duration'].' minutes');
 $summary='Masáž – '.(string)($o['recipient']?:$o['buyer_name']);
 $description='Moje masáže · '.(MM_FOCUS[$o['focus']]??$o['focus'])."\n".
   'Klient: '.$o['buyer_name']."\nEmail: ".$o['email']."\nTelefon: ".$o['phone']."\nVS: ".$o['vs']."\nPlatba: ".($o['status']==='paid'?'uhrazeno':'čeká na úhradu');
 $event=[
  'summary'=>function_exists('mb_substr')?mb_substr($summary,0,160):substr($summary,0,160),
  'description'=>$description,
  'start'=>['dateTime'=>$start->format(DateTimeInterface::RFC3339),'timeZone'=>'Europe/Prague'],
  'end'=>['dateTime'=>$end->format(DateTimeInterface::RFC3339),'timeZone'=>'Europe/Prague'],
  'transparency'=>'opaque',
 ];
 $get=mm_google_api('GET',$path);
 if($get['status']===200){$put=mm_google_api('PATCH',$path,$event);if($put['status']!==200)throw new RuntimeException('Google event update failed.');return 'Google event updated';}
 if($get['status']!==404)throw new RuntimeException('Google event lookup failed: '.$get['status']);
 $event['id']=$id;
 $create=mm_google_api('POST','/calendar/v3/calendars/'.$calendar.'/events',$event);
 if(!in_array($create['status'],[200,201],true)){
  if($create['status']===409){$retry=mm_google_api('PATCH',$path,$event);if($retry['status']===200)return 'Google event updated';}
  throw new RuntimeException('Google event creation failed (HTTP '.$create['status'].').');
 }
 return 'Google event added';
}
