<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']);
session_start();
require_once __DIR__.'/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
function get_hash(): string {
 $env=getenv('MASSAGE_ADMIN_PASSWORD_HASH');if(is_string($env)&&$env!=='')return $env;
 try{
  $file=mm_private_dir().'/admin-hash.txt';
  if(!is_file($file)||is_link($file)||!is_readable($file))return '';
  $perm=@fileperms($file);if($perm===false||($perm&0077)!==0)return '';
  return trim((string)file_get_contents($file));
 }catch(Throwable $e){return '';}
}
function redirect_admin(string $msg=''): never {
 header('Location: admin.php'.($msg?'?notice='.rawurlencode($msg):''),true,303);exit;
}
$alert='';$authenticated=($_SESSION['mm_admin_auth']??false)===true;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 $csrf=(string)($_POST['csrf']??'');
 if(!hash_equals(mm_csrf(),$csrf)){http_response_code(403);$alert='Neplatný bezpečnostní token. Obnovte stránku.';}
 elseif(($_POST['action']??'')==='logout'){
  unset($_SESSION['mm_admin_auth']);session_regenerate_id(true);redirect_admin('Odhlášení proběhlo.');
 }elseif(($_POST['action']??'')==='login'){
  try{
   mm_rate_limit('admin:'.($_SERVER['REMOTE_ADDR']??'unknown'));
   $hash=get_hash();
   if($hash!==''&&password_verify((string)($_POST['password']??''),$hash)){
    session_regenerate_id(true);$_SESSION['mm_admin_auth']=true;redirect_admin('Přihlášení úspěšné.');
   }
   $alert='Neplatné heslo nebo není nastavené přihlášení.';http_response_code(401);
  }catch(Throwable $e){$alert='Příliš mnoho pokusů, zkuste to později.';http_response_code(429);}
 }elseif($authenticated){
  try{
   $action=(string)($_POST['action']??'');
   if($action==='add_block'){mm_block_create((string)($_POST['block_date']??''),(string)($_POST['block_start']??''),(string)($_POST['block_end']??''),(string)($_POST['block_note']??''));redirect_admin('Časová blokace byla vytvořena.');}
   if($action==='delete_block'){$blockId=filter_var($_POST['block_id']??null,FILTER_VALIDATE_INT);if(!$blockId||$blockId<1)throw new InvalidArgumentException('Neplatná blokace.');$delete=mm_db()->prepare('DELETE FROM massage_blocks WHERE id=?');$delete->execute([$blockId]);redirect_admin('Blokace byla zrušena.');}
   $order=mm_admin_update((string)($_POST['order_id']??''),$action,$_POST);
   $notice='Změna byla uložena.';
   if(in_array($action,['confirm','schedule_gift','cancel'],true)&&!mm_appointment_notice($order,$action))$notice='Změna je uložená, ale odeslání e-mailu se nezdařilo. Kontaktujte klienta osobně.';
   redirect_admin($notice);
  }catch(Throwable $e){$alert=$e instanceof InvalidArgumentException?$e->getMessage():'Změnu se nepodařilo uložit.';http_response_code(422);}
 }
}
$authenticated=($_SESSION['mm_admin_auth']??false)===true;
$notice=(string)($_GET['notice']??'');
if(strlen($notice)>180)$notice='';
$orders=[];$blocks=[];$calendarUrl='';$stats=['all'=>0,'paid'=>0,'pending'=>0,'gifts'=>0];
if($authenticated){
 try{
  $db=mm_db();
  $orders=$db->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 200')->fetchAll();
  $blocks=$db->query('SELECT * FROM massage_blocks WHERE block_date>=date(\'now\') ORDER BY block_date,start_time LIMIT 100')->fetchAll();
  try{$calendarUrl='https://moje-masaze.jirijanousek.cz/calendar-feed.php?key='.rawurlencode(mm_calendar_secret());}catch(Throwable $e){$calendarUrl='';error_log('Calendar feed key: '.get_class($e));}
  $stats['all']=(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
  $stats['paid']=(int)$db->query("SELECT COUNT(*) FROM orders WHERE status='paid'")->fetchColumn();
  $stats['pending']=(int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
  $stats['gifts']=(int)$db->query("SELECT COUNT(*) FROM orders WHERE kind='gift'")->fetchColumn();
 }catch(Throwable $e){$alert='Databáze momentálně není dostupná.';}
}
function h(mixed $s): string {return mm_escape($s);}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Správa objednávek • Moje masáže</title>
<style>
:root{font-family:system-ui,Arial,sans-serif;color:#27372d;background:#f8f3ea}*{box-sizing:border-box}body{margin:0}header{background:#1c2a21;color:#fff;padding:19px 4vw;display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap}h1,h2{font-family:Georgia,serif;font-weight:400;margin:0}h1{font-size:1.6rem}header a{color:#e4c28b;text-decoration:none}main{max-width:1300px;margin:36px auto;padding:0 20px}button,.button{border:0;border-radius:8px;padding:10px 16px;background:#2a422f;color:#fff;font-weight:700;cursor:pointer}button:hover{background:#36583d}.logout{background:#ffffff22}input,select{padding:10px;border-radius:7px;border:1px solid #d9cebb;font:inherit;min-width:0}label{font-size:.83rem;font-weight:600;display:grid;gap:5px}.login{max-width:410px;margin:35px auto;padding:25px;background:#fff;border:1px solid #e2d7c6;border-radius:15px}.login input{width:100%}.login button{width:100%;margin-top:19px}p{line-height:1.6}.notice{padding:12px 17px;margin:15px 0;background:#fff4d7;border-left:4px solid #b98c51;border-radius:6px}.error{background:#fff0ec;border-left-color:#b84d40}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:24px}.stats>div{background:white;border:1px solid #eee1d1;padding:20px;border-radius:12px}.stats strong{display:block;font-size:1.8rem}.stats small{color:#636b62}table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;font-size:.83rem}thead th{text-align:left;background:#2b3c2e;color:#fff;padding:14px 12px}tbody td{padding:15px 12px;vertical-align:top;border-bottom:1px solid #e9e5df}tbody tr:nth-child(2n){background:#fcfaf6}td p{margin:3px 0;font-size:.78rem;color:#697265;overflow-wrap:anywhere}td strong{display:block}a{color:#845529}form.inline{display:flex;gap:6px;align-items:end;flex-wrap:wrap;margin:7px 0}form.inline button{font-size:.72rem;padding:9px 11px}form.inline input{max-width:130px;padding:7px}form.inline select{padding:7px} .tag{display:inline-block;background:#e5eee4;padding:3px 8px;border-radius:8px;font-size:.76rem}.pending{background:#f5e9ce}.muted{color:#6b736d}.scroll{overflow:auto;border-radius:14px;box-shadow:0 10px 32px #332b1a0b}footer{padding:35px 0;text-align:center;font-size:.78rem;color:#6f776e}@media(max-width:950px){.stats{grid-template-columns:repeat(2,1fr)}table{min-width:1080px}}@media(max-width:480px){.stats{grid-template-columns:1fr 1fr}} 
</style></head>
<body><header><div><h1>❁ Moje masáže · Administrace</h1><small>Jiří Janoušek | Objednávky a dárkové poukazy</small></div><div><a href="/">← Web</a> <?php if($authenticated):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><button name="action" value="logout" class="logout">Odhlásit se</button></form><?php endif;?></div></header>
<main>
<?php if($notice!==''):?><div class="notice"><?=h($notice)?></div><?php endif;?>
<?php if($alert!==''):?><div class="notice error"><?=h($alert)?></div><?php endif;?>
<?php if(!$authenticated):?>
<section class="login"><h2>Přihlášení správce</h2><p>Vstup pouze pro provozovatele. Heslo se porovnává bezpečně na serveru; ukládá se pouze jeho hash.</p>
<?php if(get_hash()===''):?><p class="notice error">Přihlášení není aktivované. Nastavte na serveru <code>MASSAGE_ADMIN_PASSWORD_HASH</code> nebo soukromý soubor <code>admin-hash.txt</code>.</p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="action" value="login"><label>Heslo administrátora<input type="password" name="password" required autocomplete="current-password"></label><button type="submit">Přihlásit se</button></form></section>
<?php else:?>
<div class="stats"><div><strong><?=$stats['all']?></strong><small>Všechny objednávky</small></div><div><strong><?=$stats['paid']?></strong><small>Ověřeně uhrazené</small></div><div><strong><?=$stats['pending']?></strong><small>Čekají na platbu</small></div><div><strong><?=$stats['gifts']?></strong><small>Dárkové poukazy</small></div></div>
<section style="background:white;border:1px solid #e5d8c8;padding:20px;border-radius:14px;margin:12px 0 25px">
<h2 style="margin:0 0 12px">📆 Rezervační kalendář</h2>
<p>Volné termíny na webu se kontrolují proti těmto rezervacím a časovým blokacím. Propojení s Google Kalendářem funguje formou soukromého odběru potvrzených termínů; obsazenost vašeho osobního Google Kalendáře se zpět na web automaticky nepřenáší.</p>
<?php if($calendarUrl!==''):?><p><strong>Soukromý odkaz pro Google Kalendář:</strong> <a href="<?=h($calendarUrl)?>" rel="nofollow">Otevřít kalendářový soubor .ics</a></p>
<details><summary>Zobrazit odkaz pro odběr v Google Kalendáři</summary><input type="text" readonly onclick="this.select()" style="width:100%" value="<?=h($calendarUrl)?>"><small>Odkaz je tajný. V Google Kalendáři zvolte Jiné kalendáře → Z adresy URL a vložte jej. Synchronizace Google může být opožděná.</small></details><?php endif;?>
<h3 style="margin:20px 0 10px">Blokovat obsazený čas</h3><form method="post" class="inline" style="background:#f8f3eb;padding:13px;border-radius:9px">
<input type="hidden" name="csrf" value="<?=h(mm_csrf())?>">
<label>Datum<input type="date" name="block_date" required></label>
<label>Od<input type="time" name="block_start" value="09:00" step="1800" required></label>
<label>Do<input type="time" name="block_end" value="10:00" step="1800" required></label>
<label>Důvod (volitelně)<input type="text" name="block_note" maxlength="100"></label>
<button name="action" value="add_block">+ Přidat blokaci</button></form>
<?php if($blocks):?><div style="display:flex;flex-wrap:wrap;gap:9px;margin:12px 0"><?php foreach($blocks as $b):?><form method="post" style="background:#f1e8d8;padding:8px;border-radius:8px">
<input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="block_id" value="<?=h($b['id'])?>">
<span><?=h($b['block_date'])?> · <?=h($b['start_time'])?>–<?=h($b['end_time'])?> · <?=h($b['note'])?></span>
<button name="action" value="delete_block" onclick="return confirm('Odstranit blokaci?')" style="padding:5px 9px">×</button></form><?php endforeach;?></div><?php endif;?>
</section>
<h2>Posledních 200 objednávek</h2><p class="muted">Bankovní platbu potvrzuje výhradně Fio API. Správce zde mění pouze termín a čerpání poukazů.</p>
<div class="scroll"><table><thead><tr><th>Objednávka</th><th>Klient</th><th>Služba</th><th>Úhrada</th><th>Správa</th></tr></thead><tbody>
<?php foreach($orders as $o):?>
<tr><td><strong><?=date('d.m.Y H:i',(int)$o['created_at'])?></strong><p>VS: <?=h($o['vs'])?></p><p><?=h($o['kind']==='gift'?'🎁 Poukaz':'📅 Termín')?></p></td>
<td><strong><?=h($o['buyer_name'])?></strong><p><a href="mailto:<?=h($o['email'])?>"><?=h($o['email'])?></a></p><p><?=h($o['phone'])?></p><?php if($o['recipient']!==''):?><p>Pro: <?=h($o['recipient'])?></p><?php endif;?></td>
<td><strong><?=h(MM_FOCUS[$o['focus']]??$o['focus'])?></strong><p><?=h($o['duration'])?> minut × <?=h($o['quantity'])?></p><p><?=h($o['note'])?></p></td>
<td><strong><?=h(mm_money((int)$o['amount_cents'],$o['currency']))?></strong><span class="tag <?=$o['status']==='pending'?'pending':''?>"><?=h($o['status']==='paid'?'Zaplaceno':'Čeká na platbu')?></span><p><?=h($o['currency'])?></p></td>
<td>
<?php if($o['kind']==='booking'):?>
<span class="tag"><?=h(match($o['appointment_status']){'requested'=>'Žádost','confirmed'=>'Potvrzený termín','cancelled'=>'Zrušeno','completed'=>'Dokončeno',default=>'Žádost'})?></span>
<?php if($o['confirmed_date']!==''):?><p><?=h($o['confirmed_date'])?> <?=h($o['confirmed_time'])?></p><?php endif;?>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="order_id" value="<?=h($o['id'])?>">
<label>Datum<input type="date" name="confirmed_date" value="<?=h($o['confirmed_date']?:$o['preferred_date'])?>" required></label><label>Čas<input type="time" name="confirmed_time" step="900" value="<?=h($o['confirmed_time']?:($o['preferred_time']?:'10:00'))?>" required></label><button name="action" value="confirm">Potvrdit</button></form>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="order_id" value="<?=h($o['id'])?>"><button name="action" value="complete">Dokončit</button><button name="action" value="cancel" onclick="return confirm('Opravdu zrušit termín?')">Zrušit</button></form>
<?php else:?>
<p>Vyčerpáno: <?=h($o['redeemed_count'])?> / <?=h($o['quantity'])?></p>
<?php if($o['status']==='paid'&&$o['redeemed_count']<$o['quantity']):?>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="order_id" value="<?=h($o['id'])?>"><button name="action" value="redeem" onclick="return confirm('Potvrdit využití jedné masáže z poukazu?')">Uplatnit 1 masáž</button></form>
<?php endif;?>
<?php if($o['status']==='paid'):?>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="order_id" value="<?=h($o['id'])?>">
<label>Potvrzený datum<input type="date" name="confirmed_date" required value="<?=h($o['confirmed_date']?:$o['preferred_date'])?>"></label>
<label>Hodina<input type="time" name="confirmed_time" step="900" required value="<?=h($o['confirmed_time']?:($o['preferred_time']?:'10:00'))?>"></label>
<button name="action" value="schedule_gift">📅 Potvrdit termín + aktualizovat poukaz</button></form>
<a href="voucher.php?token=<?=h(rawurlencode($o['token']))?>" target="_blank" rel="noopener noreferrer">🎁 Zobrazit / vytisknout poukaz s QR ↗</a>
<?php endif;?>
<?php endif;?>
</td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</main><footer>Moje masáže · Soukromá správa objednávek • <?=date('Y')?></footer></body></html>