<?php
declare(strict_types=1);
if(!defined('MM_AUTHENTICATED')||MM_AUTHENTICATED!==true){http_response_code(404);exit;}
/** Owner-only CRM dashboard. Included exclusively inside the authenticated admin view. */
$db=mm_db();
$search=trim((string)($_GET['q']??''));
if(strlen($search)>100)$search=substr($search,0,100);
$selected=(string)($_GET['client']??'');
if(!preg_match('/\A[a-f0-9]{24}\z/D',$selected))$selected='';
$month=(string)($_GET['month']??date('Y-m'));
if(!preg_match('/\A20[2-4][0-9]-(?:0[1-9]|1[0-2])\z/D',$month))$month=date('Y-m');
$start=new DateTimeImmutable($month.'-01',new DateTimeZone('Europe/Prague'));
$next=$start->modify('first day of next month');
$monthPrev=$start->modify('-1 month')->format('Y-m');
$monthNext=$next->format('Y-m');
$dates=$db->prepare("SELECT o.id,o.client_id,o.buyer_name,o.kind,o.status,o.appointment_status,o.focus,o.duration,
 COALESCE(NULLIF(o.confirmed_date,''),o.preferred_date) AS visit_date,
 COALESCE(NULLIF(o.confirmed_time,''),o.preferred_time) AS visit_time
 FROM orders o WHERE
 ((o.confirmed_date>=? AND o.confirmed_date<?) OR (o.confirmed_date='' AND o.preferred_date>=? AND o.preferred_date<?))
 AND o.appointment_status NOT IN ('cancelled','completed')
 ORDER BY visit_time");
$dates->execute([$start->format('Y-m-d'),$next->format('Y-m-d'),$start->format('Y-m-d'),$next->format('Y-m-d')]);
$byDay=[];
foreach($dates->fetchAll() as $event){if($event['visit_date']!=='')$byDay[$event['visit_date']][]=$event;}
$clientSql="SELECT c.*,COUNT(o.id) AS order_count,
 COALESCE(SUM(CASE WHEN o.status='paid' AND o.currency='CZK' THEN o.amount_cents ELSE 0 END),0) AS paid_czk,
 COALESCE(SUM(CASE WHEN o.status='paid' AND o.currency='EUR' THEN o.amount_cents ELSE 0 END),0) AS paid_eur,
 MAX(o.created_at) AS last_order
 FROM clients c LEFT JOIN orders o ON o.client_id=c.id";
$params=[];
if($search!==''){$clientSql.=" WHERE c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?";$params=["%$search%","%$search%","%$search%"];}
$clientSql.=" GROUP BY c.id ORDER BY c.archived ASC,last_order DESC,c.full_name ASC LIMIT 250";
$find=$db->prepare($clientSql);$find->execute($params);$clients=$find->fetchAll();
$profile=null;$history=[];
if($selected!==''){
 $q=$db->prepare("SELECT * FROM clients WHERE id=?");$q->execute([$selected]);$profile=$q->fetch()?:null;
 if($profile){$q=$db->prepare("SELECT * FROM orders WHERE client_id=? ORDER BY created_at DESC LIMIT 150");$q->execute([$selected]);$history=$q->fetchAll();}
}
$messages=$db->query("SELECT * FROM inquiries ORDER BY status='new' DESC,created_at DESC LIMIT 120")->fetchAll();
$inboxNew=(int)$db->query("SELECT COUNT(*) FROM inquiries WHERE status='new'")->fetchColumn();
$paidTotals=$db->query("SELECT currency,SUM(amount_cents) AS cents,COUNT(*) AS n FROM orders WHERE status='paid' GROUP BY currency")->fetchAll();
$totals=[];foreach($paidTotals as $row)$totals[$row['currency']]=$row;
$upcoming=$db->query("SELECT id,client_id,buyer_name,focus,duration,kind,confirmed_date,confirmed_time,status
 FROM orders WHERE appointment_status='confirmed' AND confirmed_date>=date('now') ORDER BY confirmed_date,confirmed_time LIMIT 12")->fetchAll();
?>
<style>
.crmnav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 24px;position:sticky;top:0;background:#f8f3eaf5;padding:9px 0;z-index:10}
.crmnav a{display:inline-flex;gap:7px;align-items:center;text-decoration:none;border:1px solid #ddcfb9;border-radius:9px;padding:10px 15px;background:white;color:#2b4432;font-weight:700;font-size:.88rem}
.crmnav a:hover{background:#23442f;color:white}
.crmtitle{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:25px 0 13px}
.crmpanel{padding:22px;background:white;border:1px solid #e8dcc9;border-radius:14px;margin-bottom:21px;box-shadow:0 8px 30px #253d2908}
.crmdashboard{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.crmkpi{padding:17px;border:1px solid #e7dac7;background:#fcf9f3;border-radius:10px}
.crmkpi strong{display:block;font-size:1.8rem;color:#314b35;font-variant-numeric:tabular-nums}
.crmkpi span{font-size:.81rem;color:#746d5f}
.crmcols{display:grid;grid-template-columns:1.4fr .8fr;gap:17px}
.crmform{display:flex;gap:9px;align-items:end;flex-wrap:wrap}
.crmform label{min-width:150px;flex:1}
.crmform textarea{font:inherit;border:1px solid #ded3bf;border-radius:8px;padding:9px;width:100%;resize:vertical}
.crmsearch{display:flex;gap:9px;margin-bottom:16px}.crmsearch input{flex:1}
.crmlist{display:grid;gap:8px;max-height:450px;overflow:auto;padding-right:5px}
.crmclient{display:flex;justify-content:space-between;gap:10px;border:1px solid #e8e0d2;border-radius:9px;padding:11px;background:#fefcf9}
.crmclient a{font-weight:700;color:#29452f;text-decoration:none}
.crmclient small,.crmclient span{display:block;font-size:.78rem;color:#706f65;margin-top:4px;overflow-wrap:anywhere}
.crmclient--archived{opacity:.58}
.crmstatus{padding:4px 9px;border-radius:20px;background:#dfece0;color:#28532f;font-size:.76rem;font-weight:700}
.crmstatus--pending{background:#f8e8ca;color:#825d2f}
.crmcalhead{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:12px 0}
.crmcalhead a{text-decoration:none;background:#eee3d4;border-radius:8px;padding:7px 12px}
.crmcalendar{display:grid;grid-template-columns:repeat(7,minmax(90px,1fr));gap:4px}
.crmweekday{background:#2c4735;color:#fff;padding:8px;text-align:center;font-size:.76rem;font-weight:700}
.crmday{min-height:104px;border:1px solid #e6dccc;background:#fffcf8;border-radius:7px;padding:7px;font-size:.77rem}
.crmday--today{border:2px solid #b78c54;background:#fff4e5}
.crmday--empty{background:transparent;border:0}
.crmday strong{display:block;margin-bottom:5px;color:#293c2e}
.crmevent{display:block;background:#e4f0e5;border-left:3px solid #648c6c;border-radius:4px;padding:4px 5px;margin-top:4px;font-size:.73rem;color:#22402b;text-decoration:none;overflow-wrap:anywhere}
.crmevent--pending{background:#f7e9d4;border-left-color:#c69a59;color:#77582e}
.crminquiry{padding:13px;border:1px solid #e5d9c9;border-radius:9px;margin-bottom:10px;background:#fdfbf7}
.crminquiry p{white-space:pre-wrap;margin:8px 0;overflow-wrap:anywhere}.crminquiry small{color:#667164}
.crmhint{font-size:.8rem;color:#71695f;line-height:1.5}
.crmcompact{font-size:.77rem}
.crmcompact td,.crmcompact th{padding:9px}
@media(max-width:1050px){.crmdashboard{grid-template-columns:repeat(2,1fr)}.crmcols{grid-template-columns:1fr}}
@media(max-width:720px){.crmpanel{padding:14px}.crmcalendar{overflow-x:auto;min-width:680px}.crmcalwrap{overflow:auto}.crmdashboard{grid-template-columns:repeat(2,1fr)}.crmnav a{padding:8px 10px}.crmkpi strong{font-size:1.3rem}}
</style>
<nav class="crmnav" aria-label="Správa systému">
 <a href="#dashboard">📊 Přehled</a><a href="#clients">👥 Klienti</a>
 <a href="#calendar">📆 Kalendář</a><a href="#inquiries">✉️ Zprávy <?php if($inboxNew):?>(<?=$inboxNew?>)<?php endif;?></a>
 <a href="#orders">💳 Objednávky a platby</a><a href="#security">🔐 Heslo a zabezpečení</a>
</nav>
<section id="dashboard" class="crmpanel"><h2>Manažerský přehled</h2>
 <p class="crmhint">Příjmy zahrnují pouze objednávky ověřeně uhrazené bankou. EUR a CZK se nesčítají dohromady.</p>
 <div class="crmdashboard">
 <div class="crmkpi"><strong><?=number_format((int)$db->query('SELECT COUNT(*) FROM clients WHERE archived=0')->fetchColumn(),0,',',' ')?></strong><span>Aktivní klienti</span></div>
 <div class="crmkpi"><strong><?=number_format((int)($totals['CZK']['cents']??0)/100,0,',',' ')?> Kč</strong><span>Uhrazeno CZK</span></div>
 <div class="crmkpi"><strong><?=number_format((int)($totals['EUR']['cents']??0)/100,2,',',' ')?> €</strong><span>Uhrazeno EUR</span></div>
 <div class="crmkpi"><strong><?=$inboxNew?></strong><span>Nové zprávy</span></div>
 </div>
 <div class="crmtitle"><h3>Nejbližší potvrzené masáže</h3><span class="crmstatus">Zarezervováno</span></div>
 <?php if(!$upcoming):?><p class="crmhint">Zatím nejsou potvrzené budoucí termíny.</p><?php endif;?>
 <?php foreach($upcoming as $o):?><div class="crmclient">
 <div><strong><?=h($o['confirmed_date'].' '.$o['confirmed_time'])?> · <?=h($o['buyer_name'])?></strong>
 <small><?=h(MM_FOCUS[$o['focus']]??$o['focus'])?> · <?=h($o['duration'])?> minut</small></div>
 <a href="#orders">Objednávka</a></div><?php endforeach;?>
</section>
<section id="clients" class="crmpanel">
 <div class="crmtitle"><h2>👥 Databáze klientů</h2><a href="admin.php?export=clients" style="font-weight:700">↓ Export CSV (Excel)</a></div>
 <p class="crmhint">Každá objednávka a zpráva propojuje klientský profil podle e-mailu. Historie masáží a bankovních úhrad zůstává u objednávek.</p>
 <div class="crmcols"><div>
  <form method="get" class="crmsearch"><input type="search" name="q" value="<?=h($search)?>" maxlength="100" placeholder="Hledat jméno, telefon nebo e-mail"><button type="submit">Hledat</button></form>
  <div class="crmlist">
  <?php if(!$clients):?><p>Žádní klienti neodpovídají hledání.</p><?php endif;?>
  <?php foreach($clients as $cl):?><div class="crmclient <?=$cl['archived']?'crmclient--archived':''?>">
   <div><a href="admin.php?client=<?=h(rawurlencode($cl['id']))?>#clients"><?=h($cl['full_name'])?></a>
   <small><?=h($cl['email'])?> · <?=h($cl['phone'])?></small>
   <small><?=h($cl['order_count'])?> objednávek · <?=h(mm_money((int)$cl['paid_czk'],'CZK'))?><?php if((int)$cl['paid_eur']):?> · <?=h(mm_money((int)$cl['paid_eur'],'EUR'))?><?php endif;?></small>
   </div><div><a href="admin.php?client=<?=h(rawurlencode($cl['id']))?>#clients">Otevřít ↗</a></div></div>
  <?php endforeach;?></div>
 </div><div>
  <h3><?= $profile?'Karta klienta':'Nový klient' ?></h3>
  <form method="post" class="crmform">
   <input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="action" value="save_client">
   <input type="hidden" name="client_id" value="<?=h($profile['id']??'')?>">
   <label>Jméno a příjmení<input name="full_name" required maxlength="120" value="<?=h($profile['full_name']??'')?>"></label>
   <label>E-mail<input name="email" type="email" required maxlength="254" value="<?=h($profile['email']??'')?>"></label>
   <label>Telefon<input name="phone" type="tel" maxlength="25" value="<?=h($profile['phone']??'')?>"></label>
   <label style="flex-basis:100%">Poznámky k péči (ne zdravotní dokumentace)<textarea name="notes" rows="4" maxlength="2500"><?=h($profile['notes']??'')?></textarea></label>
   <button name="action" value="save_client" type="submit"><?= $profile?'Uložit změny':'Vytvořit klienta' ?></button>
  </form>
  <?php if($profile):?>
   <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="client_id" value="<?=h($profile['id'])?>"><button name="action" value="<?=$profile['archived']?'restore_client':'archive_client'?>" class="logout"><?=$profile['archived']?'Obnovit klienta':'Archivovat klienta'?></button></form>
   <h3 style="margin-top:24px">Historie masáží a úhrad</h3>
   <?php if(!$history):?><p class="crmhint">Klient zatím nemá objednávky.</p><?php endif;?>
   <?php foreach($history as $o):?><div class="crmclient"><div>
    <b><?=h(date('d.m.Y',(int)$o['created_at']))?> · <?=h(MM_FOCUS[$o['focus']]??$o['focus'])?></b>
    <small><?=h($o['duration'])?> min × <?=h($o['quantity'])?> · <?=h($o['confirmed_date']?:$o['preferred_date'])?> <?=h($o['confirmed_time']?:$o['preferred_time'])?></small>
    <small><?=h(mm_money((int)$o['amount_cents'],$o['currency']))?> · <?=h($o['status']==='paid'?'Zaplaceno':'Nezaplaceno')?> · VS <?=h($o['vs'])?></small>
   </div></div><?php endforeach;?>
  <?php endif;?>
 </div></div>
</section>
<section id="calendar" class="crmpanel">
 <div class="crmtitle"><h2>📆 Kalendář masáží a schůzek</h2><span class="crmhint">Evropský čas · Praha</span></div>
 <div class="crmcalhead"><a href="admin.php?month=<?=h($monthPrev)?>#calendar">← Předchozí</a>
 <strong><?=h($start->format('m / Y'))?></strong><a href="admin.php?month=<?=h($monthNext)?>#calendar">Další →</a></div>
 <div class="crmcalwrap"><div class="crmcalendar">
 <?php foreach(['Po','Út','St','Čt','Pá','So','Ne'] as $day):?><div class="crmweekday"><?=$day?></div><?php endforeach;?>
 <?php for($pad=1;$pad<(int)$start->format('N');$pad++):?><div class="crmday crmday--empty"></div><?php endfor;?>
 <?php for($n=1;$n<=(int)$start->format('t');$n++):$date=$start->setDate((int)$start->format('Y'),(int)$start->format('m'),$n)->format('Y-m-d'); ?>
  <div class="crmday <?=$date===date('Y-m-d')?'crmday--today':''?>"><strong><?=$n?></strong>
   <?php foreach($byDay[$date]??[] as $o):?><a class="crmevent <?=$o['appointment_status']==='confirmed'?'':'crmevent--pending'?>" href="#orders" title="<?=h($o['appointment_status']==='confirmed'?'Potvrzeno':'Předběžně')?>">
    <?=h($o['visit_time']?:'dle domluvy')?> · <?=h($o['buyer_name'])?> · <?=h($o['duration'])?> min
   </a><?php endforeach;?>
  </div><?php endfor;?></div></div>
 <p class="crmhint">Zeleně jsou potvrzené termíny, béžově předběžné žádosti. Nové termíny potvrzuje správce; volné časy rezervací se ověřují proti interní databázi.</p>
</section>
<section id="inquiries" class="crmpanel"><div class="crmtitle"><h2>✉️ Kontaktní formulář a zprávy</h2><span class="crmstatus"><?=$inboxNew?> nových</span></div>
 <p class="crmhint">Zprávy přicházejí z formuláře na webu; klient i správce dostávají samostatné grafické potvrzení e-mailem.</p>
 <?php if(!$messages):?><p>Žádná zpráva zatím nepřišla.</p><?php endif;?>
 <?php foreach($messages as $m):?><div class="crminquiry">
  <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
   <b><?=h($m['subject'])?></b><span class="crmstatus <?=$m['status']==='new'?'crmstatus--pending':''?>"><?=h($m['status']==='new'?'Nová':($m['status']==='resolved'?'Vyřízená':'Přečtená'))?></span>
  </div>
  <small><?=h(date('d.m.Y H:i',(int)$m['created_at']))?> · <?=h($m['sender_name'])?> · <a href="mailto:<?=h($m['email'])?>"><?=h($m['email'])?></a><?php if($m['phone']):?> · <a href="tel:<?=h($m['phone'])?>"><?=h($m['phone'])?></a><?php endif;?></small>
  <p><?=h($m['message'])?></p>
  <form method="post" class="crmform">
   <input type="hidden" name="csrf" value="<?=h(mm_csrf())?>"><input type="hidden" name="action" value="inquiry_status">
   <input type="hidden" name="inquiry_id" value="<?=h($m['id'])?>">
   <label>Stav <select name="status"><option value="new" <?=$m['status']==='new'?'selected':''?>>Nová</option><option value="read" <?=$m['status']==='read'?'selected':''?>>Přečtená</option><option value="resolved" <?=$m['status']==='resolved'?'selected':''?>>Vyřízená</option></select></label>
   <button type="submit">Uložit stav</button>
  </form>
 </div><?php endforeach;?>
</section>

<section class="crmpanel" id="security">
 <div class="crmtitle"><h2>🔐 Administrátorský účet a zabezpečení</h2><span class="crmstatus">Pouze správce</span></div>
 <p>Účet: <strong>jgjanousek@gmail.com</strong>. Nové heslo zůstává výhradně ve formě bezpečného hashe v soukromé části serveru, mimo zdrojový kód a veřejný GitHub.</p>
 <div class="crmcols">
  <div><h3>Změnit administrátorské heslo</h3>
  <form method="post" class="crmform" autocomplete="off">
   <input type="hidden" name="csrf" value="<?=h(mm_csrf())?>">
   <input type="hidden" name="action" value="change_password">
   <label style="flex-basis:100%">Současné heslo<input name="current_password" type="password" required autocomplete="current-password"></label>
   <label style="flex-basis:100%">Nové heslo (min. 12 znaků)<input name="new_password" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></label>
   <label style="flex-basis:100%">Nové heslo znovu<input name="new_password_again" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></label>
   <button type="submit">Uložit nové heslo</button>
  </form></div>
  <div><h3>Zapomenuté heslo</h3><p>Po odhlášení klikněte na <strong>Zapomenuté heslo?</strong>. Systém pošle jednorázový odkaz pouze na schválený administrátorský e-mail, který lze použít nejpozději do 30 minut.</p>
  <p class="crmhint">Odkaz nikdy neobsahuje současné heslo. Obnova vyžaduje funkční odesílání e-mailů na WebKitty. Po změně hesla se ostatní administrátorské relace odhlásí.</p>
  <p class="crmhint">Administrace je přístupná jen přes přihlášení, bez veřejné registrace.</p></div>
 </div>
</section>
