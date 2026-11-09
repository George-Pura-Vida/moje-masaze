<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']);
session_start();
require_once __DIR__.'/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex,nofollow,noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$token=$_GET['code']??$_POST['code']??'';
if(!is_string($token)||!preg_match('/\A[a-f0-9]{48}\z/D',$token)){http_response_code(404);exit('Neplatný vstupní QR kód.');}
$admin=($_SESSION['mm_admin_auth']??false)===true;
if(!$admin){http_response_code(403);}
$error='';$ok='';
if($admin&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $csrf=(string)($_POST['csrf']??'');
 if(!hash_equals(mm_csrf(),$csrf)){$error='Neplatný bezpečnostní token.';}
 else{
  try{
   $stmt=mm_db()->prepare('SELECT id FROM orders WHERE token=? AND kind=?');
   $stmt->execute([$token,'gift']);$id=$stmt->fetchColumn();
   if(!$id)throw new InvalidArgumentException('Poukaz nebyl nalezen.');
   $o=mm_admin_update((string)$id,'redeem',[]);
   $ok='Jedna masáž byla uplatněna. Zbývá '.((int)$o['quantity']-(int)$o['redeemed_count']).' masáží.';
  }catch(InvalidArgumentException $e){$error=$e->getMessage();}
  catch(Throwable $e){$error='Uplatnění se nezdařilo.';}
 }
}
$o=null;
if($admin){
 try{$q=mm_db()->prepare("SELECT buyer_name,recipient,kind,focus,duration,quantity,redeemed_count,status,preferred_date,preferred_time,confirmed_date,confirmed_time,appointment_status,vs FROM orders WHERE token=? AND kind='gift' LIMIT 1");
 $q->execute([$token]);$o=$q->fetch();}catch(Throwable $e){$error='Databáze dočasně nedostupná.';}
 if(!$o){http_response_code(404);}
}
function esc(mixed $v): string{return mm_escape($v);}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ověření dárkového poukazu · Moje masáže</title>
<style>body{background:#f2ebe1;color:#27382d;font:16px/1.5 system-ui,Arial,sans-serif;padding:22px}main{max-width:610px;margin:30px auto;background:white;padding:30px;border-radius:18px;border:1px solid #decbb0;box-shadow:0 16px 40px #24332519}h1{font:38px Georgia,serif}a{color:#815c33}button{border:0;border-radius:9px;background:#263d2c;color:white;padding:15px 24px;font-weight:700;cursor:pointer}p{line-height:1.75}.tag{background:#e4eedc;padding:6px 12px;border-radius:8px}.error{background:#ffe1d9;padding:10px}.ok{background:#e2f3e2;padding:10px}</style>
</head><body><main><h1>❁ Kontrola poukazu</h1>
<?php if(!$admin):?><p>Vstupní QR kód je určen pro ověření provozovatelem. Bez přihlášení jej nelze uplatnit.</p><p><a href="admin.php">Přihlásit se do administrace</a> a poté QR kód znovu naskenovat.</p>
<?php elseif(!$o):?><p class="error">Poukaz nebyl nalezen.</p>
<?php else:?>
<?php if($ok!==''):?><p class="ok"><?=esc($ok)?></p><?php endif;?>
<?php if($error!==''):?><p class="error"><?=esc($error)?></p><?php endif;?>
<p><strong><?=esc($o['recipient']?:$o['buyer_name'])?></strong></p>
<p><?=esc(MM_FOCUS[$o['focus']]??$o['focus'])?> · <?=esc($o['duration'])?> minut</p>
<p><span class="tag"><?=esc($o['status']==='paid'?'BANKOU UHRAZENO':'NEUHRAZENO')?></span></p>
<p>Kód: MM-<?=esc($o['vs'])?></p>
<p>Využito: <b><?=esc($o['redeemed_count'])?> z <?=esc($o['quantity'])?></b></p>
<?php if($o['confirmed_date']!==''):?><p>Potvrzený termín: <?=esc($o['confirmed_date'])?> <?=esc($o['confirmed_time'])?></p>
<?php elseif($o['preferred_date']!==''):?><p>Požadovaný termín: <?=esc($o['preferred_date'])?> <?=esc($o['preferred_time']?:'dle domluvy')?> – zatím nepotvrzený</p><?php endif;?>
<?php if($o['status']==='paid'&&$o['redeemed_count']<$o['quantity']):?>
<form method="post"><input type="hidden" name="code" value="<?=esc($token)?>"><input type="hidden" name="csrf" value="<?=esc(mm_csrf())?>">
<button type="submit" onclick="return confirm('Opravdu uplatnit jednu masáž z poukazu?')">✓ Ověřit a uplatnit 1 vstup</button></form>
<?php else:?><p class="error">Poukaz není možné dále uplatnit.</p><?php endif;?>
<?php endif;?>
<p><a href="admin.php">Zpět do administrace</a></p></main></body></html>