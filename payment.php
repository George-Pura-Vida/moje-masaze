<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
ini_set('display_errors','0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex,nofollow');
header('Referrer-Policy: no-referrer');
$token=$_GET['token']??'';
if(!is_string($token)||!preg_match('/\A[a-f0-9]{48}\z/D',$token)){http_response_code(404);exit('Platební odkaz nenalezen.');}
try{
 $q=mm_db()->prepare('SELECT id,kind,focus,duration,quantity,amount_cents,currency,vs,status,bank_iban,bank_bic FROM orders WHERE token=? LIMIT 1');
 $q->execute([$token]);$o=$q->fetch();
}catch(Throwable $e){http_response_code(503);exit('Platební údaje nejsou právě dostupné.');}
if(!$o){http_response_code(404);exit('Platební odkaz nenalezen.');}
$ccy=$o['currency']??'CZK';
$iban=$o['bank_iban']?:($ccy==='EUR'?mm_eur_iban():MM_IBAN);
if($iban===''){http_response_code(503);exit('Bankovní údaje je potřeba ověřit. Kontaktujte provozovatele.');}
$bank=['iban'=>$iban,'bic'=>$o['bank_bic']?:'FIOBCZPPXXX','vs'=>$o['vs'],'currency'=>$ccy,'amount'=>number_format((int)$o['amount_cents']/100,2,'.','')];
$paid=$o['status']==='paid';$gift=$o['kind']==='gift';
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>QR platba • Moje masáže</title>
<style>
body{margin:0;background:#f5eee3;color:#213025;font:16px/1.6 system-ui,sans-serif}*{box-sizing:border-box}header{background:#1d2a21;color:#f3e6d5;padding:20px 5vw;font-family:Georgia,serif;font-size:23px}header a{color:inherit;text-decoration:none}main{max-width:950px;margin:38px auto;padding:0 18px}.grid{display:grid;grid-template-columns:1.3fr .8fr;gap:24px}.card{background:#fff;border:1px solid #e3d7c7;border-radius:17px;padding:28px;box-shadow:0 12px 30px #45372513}h1{font:40px/1.2 Georgia,serif;margin:0 0 16px}dt{color:#747369;font-size:13px;margin-top:10px}dd{margin:2px 0 10px;font-weight:700;overflow-wrap:anywhere}dl{border-block:1px solid #e9dfd0;padding:10px 0}button,.button{display:inline-block;background:#25392c;color:#fff;border:0;border-radius:8px;padding:12px 18px;font:600 14px system-ui,sans-serif;text-decoration:none;cursor:pointer;margin:6px 7px 5px 0}button:hover{background:#3a5942}.qr{max-width:280px;margin:0 auto}.qr svg{width:100%;height:auto}.hint{background:#faf3e5;border-radius:8px;padding:14px;color:#665d52;font-size:14px}.notice{background:#e9f1e6;color:#2b5c35;padding:14px;border-radius:8px;font-weight:700}.small{font-size:13px;color:#76736b}footer{font-size:13px;color:#777;padding:23px 20px;text-align:center}@media(max-width:720px){.grid{grid-template-columns:1fr}.card{padding:21px}h1{font-size:33px}}
</style></head><body><header><a href="/">❁ DOTYK &amp; KLID · Moje masáže</a></header>
<main><div class="grid"><section class="card"><h1><?=mm_escape($gift?'Dárkový poukaz – úhrada':'Platba za masáž')?></h1>
<p><?=mm_escape(MM_FOCUS[$o['focus']]??'Masáž')?> • <?=mm_escape($o['duration'])?> minut × <?=mm_escape($o['quantity'])?></p>
<?php if($paid):?><p class="notice">✅ Platba byla ověřena bankou.</p><?php else:?><p>Pro platbu naskenujte QR kód nebo zadejte údaje ručně. Dokončení platby potvrzuje banka, nikoli zobrazení QR.</p><?php endif;?>
<dl><dt>Částka</dt><dd><?=mm_escape(mm_money((int)$o['amount_cents'],$ccy))?></dd><dt>IBAN příjemce</dt><dd id="iban"><?=mm_escape($iban)?></dd><dt>BIC</dt><dd><?=mm_escape($bank['bic'])?></dd><dt>Variabilní symbol</dt><dd><?=mm_escape($o['vs'])?></dd><dt>Zpráva pro příjemce</dt><dd>MMVS-<?=mm_escape($o['vs'])?></dd><dt>Stav</dt><dd id="status"><?=mm_escape($paid?'Zaplaceno':'Čeká na platbu')?></dd></dl>
<?php if($ccy==='EUR'):?><p class="hint">U EUR převodu (SEPA) nemusí každá bankovní aplikace podporovat platební QR. Pokud QR nepřijme, použijte ruční SEPA platbu na uvedený IBAN, přesnou částku v EUR a zprávu MMVS-<?=mm_escape($o['vs'])?>.</p><?php endif;?>
<button id="copy">📋 Kopírovat údaje</button><button id="check">↻ Ověřit stav</button>
<?php if($gift&&$paid):?><a class="button" href="voucher.php?token=<?=mm_escape(rawurlencode($token))?>" target="_blank" rel="noopener">🎁 Zobrazit poukaz</a><?php endif;?>
<p id="feedback" role="status" aria-live="polite" class="small"></p>
</section><aside class="card" style="text-align:center"><h2 style="font:26px Georgia,serif">Platební QR</h2><div class="qr" id="qr"></div><p class="small">Údaje před potvrzením platby vždy zkontrolujte v bankovní aplikaci.</p></aside></div></main>
<footer>© <?=date('Y')?> Jiří Janoušek · <a href="/" style="color:#805933">Moje masáže</a></footer>
<script type="module">
import {qrcodegen} from './vendor/qrcodegen.js';
const bank=<?=json_encode($bank,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const orderToken=<?=json_encode($token)?>;
const payload='SPD*1.0*ACC:'+bank.iban.replace(/\s/g,'')+'*AM:'+bank.amount+'*CC:'+bank.currency+'*X-VS:'+bank.vs+'*MSG:MMVS-'+bank.vs;
const qr=qrcodegen.QrCode.encodeText(payload,qrcodegen.QrCode.Ecc.MEDIUM),ns='http://www.w3.org/2000/svg',border=4;
const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 '+(qr.size+border*2)+' '+(qr.size+border*2));svg.setAttribute('role','img');svg.setAttribute('aria-label','Platební QR kód');svg.setAttribute('shape-rendering','crispEdges');
const bg=document.createElementNS(ns,'rect');bg.setAttribute('width','100%');bg.setAttribute('height','100%');bg.setAttribute('fill','#fff');svg.append(bg);
const path=document.createElementNS(ns,'path');let d='';
for(let y=0;y<qr.size;y++)for(let x=0;x<qr.size;x++)if(qr.getModule(x,y))d+='M'+(x+border)+' '+(y+border)+'h1v1h-1z';
path.setAttribute('d',d);path.setAttribute('fill','#000');svg.append(path);document.getElementById('qr').append(svg);
document.getElementById('copy').addEventListener('click',async()=>{
 const text='Jiří Janoušek\nIBAN: '+bank.iban+'\nBIC: '+bank.bic+'\nČástka: '+bank.amount+' '+bank.currency+'\nVS: '+bank.vs+'\nZpráva: MMVS-'+bank.vs;
 try{await navigator.clipboard.writeText(text);document.getElementById('feedback').textContent='Údaje zkopírovány.';}
 catch{document.getElementById('feedback').textContent='Zkopírujte údaje ručně.';}
});
document.getElementById('check').addEventListener('click',async()=>{
 try{
  const r=await fetch('status.php?token='+encodeURIComponent(orderToken),{cache:'no-store'});if(!r.ok)throw Error();
  const x=await r.json();document.getElementById('status').textContent=x.status==='paid'?'Zaplaceno':'Čeká na platbu';
  const msg=document.getElementById('feedback');msg.textContent=x.status==='paid'?'✅ Platba je potvrzena.':'Platba zatím není potvrzena.';
  if(x.status==='paid'&&x.voucher_url){const a=document.createElement('a');a.href=x.voucher_url;a.textContent=' Zobrazit poukaz ↗';msg.append(a);}
 }catch{document.getElementById('feedback').textContent='Stav nelze právě ověřit.';}
});
</script></body></html>