<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
require_once __DIR__.'/lib.php';
require_once __DIR__.'/admin-auth.php';
require_once __DIR__.'/google-calendar-lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private,no-store,max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex,nofollow,noarchive');
header('X-Frame-Options: DENY');
if(!mm_admin_is_logged_in()){header('Location: admin.php',true,303);exit;}
$notice='';$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 $csrf=(string)($_POST['csrf']??'');
 if(!hash_equals(mm_csrf(),$csrf)){$error='Bezpečnostní token vypršel. Obnovte stránku.';http_response_code(403);}
 else{
  $action=(string)($_POST['action']??'');
  if($action==='connect'){
   if(!mm_google_configured()){$error='Nejdříve uložte Client ID a Client Secret pouze do soukromého nastavení na WebKitty.';}
   else{
    $state=bin2hex(random_bytes(24));
    $_SESSION['mm_google_oauth_state']=$state;
    $_SESSION['mm_google_oauth_until']=time()+600;
    $url='https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
     'client_id'=>mm_google_client_id(),
     'redirect_uri'=>MM_GOOGLE_CALLBACK,
     'response_type'=>'code',
     'scope'=>'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
     'access_type'=>'offline',
     'prompt'=>'consent',
     'include_granted_scopes'=>'true',
     'state'=>$state,
    ],'', '&',PHP_QUERY_RFC3986);
    header('Location: '.$url,true,302);exit;
   }
  }elseif($action==='disconnect'){
   try{
    $token=mm_google_read_token();
    if($token!==null&&isset($token['access_token'])){
     // Best effort revoke; local deletion always takes priority.
     try{mm_google_http('POST','https://oauth2.googleapis.com/revoke',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['token'=>(string)($token['refresh_token']??$token['access_token'])]));}catch(Throwable $e){}
    }
    $f=mm_google_token_path();if(is_file($f)&&!is_link($f)&&!@unlink($f))throw new RuntimeException('Token file could not be deleted.');
    $notice='Google Kalendář byl odpojen z webu. Stávající Google události zůstávají zachované.';
   }catch(Throwable $e){$error='Google propojení se nepodařilo bezpečně odebrat.';}
  }
 }
}
if(isset($_GET['error'])){
 $error='Google oprávnění nebylo uděleno. Zkuste autorizaci znovu.';
 unset($_SESSION['mm_google_oauth_state'],$_SESSION['mm_google_oauth_until']);
}elseif(isset($_GET['code'])||isset($_GET['state'])){
 $state=$_GET['state']??'';
 $saved=$_SESSION['mm_google_oauth_state']??null;
 $until=$_SESSION['mm_google_oauth_until']??0;
 unset($_SESSION['mm_google_oauth_state'],$_SESSION['mm_google_oauth_until']);
 if(!is_string($state)||!is_string($saved)||!hash_equals($saved,$state)||time()>(int)$until){
  $error='Neplatný nebo expirovaný OAuth požadavek. Zkuste připojení znovu.';
  http_response_code(403);
 }elseif(!isset($_GET['code'])||!is_string($_GET['code'])||strlen($_GET['code'])>4096){
  $error='Google neposkytl platný autorizační kód.';
 }elseif(!mm_google_configured()){$error='Konfigurace Client ID / Client Secret chybí na serveru.';}
 else{
  try{
   $oauth=mm_google_token_exchange([
    'code'=>$_GET['code'],
    'client_id'=>mm_google_client_id(),
    'client_secret'=>mm_google_client_secret(),
    'redirect_uri'=>MM_GOOGLE_CALLBACK,
    'grant_type'=>'authorization_code',
   ]);
   if(!isset($oauth['refresh_token']))throw new RuntimeException('Google nevydal obnovovací token. Zopakujte připojení a potvrďte přístup.');
   $info=mm_google_http('GET','https://openidconnect.googleapis.com/v1/userinfo',[
    'Authorization: Bearer '.(string)$oauth['access_token'],'Accept: application/json'
   ]);
   if($info['status']!==200||($info['data']['email_verified']??false)!==true||
      !hash_equals(MM_GOOGLE_OWNER,strtolower((string)($info['data']['email']??'')))){
    throw new RuntimeException('Propojovaný Google účet není '.MM_GOOGLE_OWNER.'. Přihlaste se správným Google účtem.');
   }
   mm_google_save_token($oauth);
   header('Location: admin.php?notice='.rawurlencode('Google Kalendář byl bezpečně propojen s účtem '.MM_GOOGLE_OWNER). '#calendar',true,303);exit;
  }catch(Throwable $e){
   error_log('MM Google OAuth: '.get_class($e));
   $error=$e instanceof RuntimeException?$e->getMessage():'Google připojení se nezdařilo.';
  }
 }
}
$connected=mm_google_connected();$configured=mm_google_configured();
function e(mixed $x): string{return mm_escape($x);}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google Calendar API · Moje masáže</title>
<style>body{margin:0;background:#f5efe5;color:#233c2d;font:16px/1.6 system-ui,Arial,sans-serif}main{max-width:840px;margin:28px auto;padding:25px;background:white;border-radius:18px;box-shadow:0 18px 50px #22382917}h1,h2{font-family:Georgia,serif;font-weight:normal}h1{font-size:36px;margin-bottom:8px}.tag{display:inline-block;border-radius:40px;padding:6px 12px;font-size:.82rem;font-weight:700;background:#e9e7dd}.tag.yes{background:#dcf0df;color:#27643b}.tag.no{background:#fff0d9;color:#725528}.notice{padding:14px;background:#e8f4e7;border-radius:10px}.error{padding:14px;background:#ffe9e4;border-radius:10px;color:#923c33}.panel{background:#faf7f1;border:1px solid #e7dcca;padding:20px;border-radius:12px;margin:20px 0}.button,button{background:#264331;color:white;padding:13px 18px;border:0;border-radius:9px;font-weight:700;text-decoration:none;display:inline-block;cursor:pointer}button.danger{background:#82463c}a{color:#74512c}code{background:#eee8e0;padding:4px;overflow-wrap:anywhere}li{margin:10px 0}</style></head>
<body><main><p><a href="admin.php#calendar">← Zpět do administrace</a></p><h1>📅 Google Calendar API</h1>
<p>Propojení webu Moje masáže s osobním kalendářem účtu <strong><?=e(MM_GOOGLE_OWNER)?></strong>, projekt <strong>moje-masaze-514213</strong>.</p>
<p><span class="tag <?=$configured?'yes':'no'?>"><?=$configured?'OAuth klient nastaven':'OAuth klient není na serveru nastaven'?></span>
<span class="tag <?=$connected?'yes':'no'?>"><?=$connected?'Google účet připojen':'Google účet není připojen'?></span></p>
<?php if($notice):?><p class="notice"><?=e($notice)?></p><?php endif;?>
<?php if($error):?><p class="error"><?=e($error)?></p><?php endif;?>
<div class="panel"><h2>1. Nastavení v Google Cloud</h2>
<ol>
<li>V projektu <strong>moje-masaze-514213</strong> povolte <strong>Google Calendar API</strong>.</li>
<li>Google Auth Platform → Branding: <strong>Moje masáže</strong>. Audience: <strong>External / Testing</strong> a testovací uživatel <strong>jgjanousek@gmail.com</strong>.</li>
<li>Clients → Create Client → <strong>Web application</strong>. Povolte přesně tuto OAuth redirect URL:</li>
</ol>
<p><code><?=e(MM_GOOGLE_CALLBACK)?></code></p>
<p>Client ID a Client Secret uložte výhradně do soukromého nastavení WebKitty jako <code>MASSAGE_GOOGLE_CLIENT_ID</code> a <code>MASSAGE_GOOGLE_CLIENT_SECRET</code>. Nezadávejte hesla ani secret do GitHubu. Pro osobní kalendář nechte <code>MASSAGE_GOOGLE_CALENDAR_ID=primary</code>.</p>
</div>
<div class="panel"><h2>2. Autorizace</h2>
<?php if(!$connected):?>
<p>Po konfiguraci klikněte na propojení a schvalte přístup ke Google Kalendáři účtu <?=e(MM_GOOGLE_OWNER)?>.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(mm_csrf())?>"><input type="hidden" name="action" value="connect"><button type="submit" <?=$configured?'':'disabled'?>>Propojit Google Kalendář přes OAuth 2.0</button></form>
<?php else:?>
<p>Propojeno. Web může ověřovat obsazenost v Google Kalendáři a zapisovat potvrzené masáže pod účet <?=e(MM_GOOGLE_OWNER)?>.</p>
<form method="post" onsubmit="return confirm('Opravdu odebrat propojení s Google Kalendářem?')"><input type="hidden" name="csrf" value="<?=e(mm_csrf())?>"><input type="hidden" name="action" value="disconnect"><button type="submit" class="danger">Odpojit Google Kalendář</button></form>
<?php endif;?></div>
<div class="panel"><h2>3. Jak funguje synchronizace</h2>
<p>Web zobrazuje pouze volné termíny podle interních rezervací a externího Google Free/Busy. Pokud při zapnutém propojení Google API neodpovídá, nové konkrétní hodiny se pro bezpečnost nenabízejí. Potvrzená masáž se zapíše do Google Kalendáře pod jedinečným ID; opakování zápisu nevytvoří duplicitu.</p>
<p>Ověřte funkčnost na testovací rezervaci v administraci. Nepřipojujte veřejný kalendář a nepoužívejte Client Secret ve webovém JavaScriptu.</p></div>
</main></body></html>
