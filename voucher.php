<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/lib.php';
require_once __DIR__.'/coupon.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex,nofollow,noarchive');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$token=$_GET['token']??'';
if(!is_string($token)||!preg_match('/\A[a-f0-9]{48}\z/D',$token)){http_response_code(404);exit('Poukaz nenalezen.');}
try{
 $q=mm_db()->prepare("SELECT * FROM orders WHERE token=? AND kind='gift' AND status='paid' LIMIT 1");
 $q->execute([$token]);$o=$q->fetch();
}catch(Throwable $e){http_response_code(503);exit('Poukaz je dočasně nedostupný.');}
if(!$o){http_response_code(404);exit('Poukaz bude dostupný po ověření bankovní platby.');}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Dárkový poukaz • Moje masáže · Jiří Janoušek</title>
<style>
*{box-sizing:border-box}html{background:#efe9df}body{margin:0;color:#27382d;font-family:Arial,sans-serif}.page{max-width:790px;margin:22px auto;padding:16px;background:white;box-shadow:0 22px 56px #233b2b20}.page table{page-break-inside:avoid}.actions{text-align:center;margin:22px auto 10px;padding:12px}.actions button,.actions a{display:inline-block;border:0;border-radius:9px;background:#263b2d;color:#fff;padding:14px 24px;text-decoration:none;font-weight:bold;cursor:pointer;margin:4px}.actions a{background:#a67a42}.printhelp{text-align:center;font-size:13px;color:#6e7167;margin:10px auto 24px;padding:0 15px}.page img{max-width:100%}
@media screen and (max-width:610px){.page{margin:0;padding:5px}.page table td{padding-left:12px!important;padding-right:12px!important}.page h1{font-size:30px!important}.page img[height="210"]{height:135px!important}}
@page{size:A4 portrait;margin:12mm}
@media print{html,body{background:white!important;margin:0;padding:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}.page{box-shadow:none!important;margin:0 auto;padding:0;max-width:100%}.actions,.printhelp{display:none!important}.page img{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head><body>
<div class="actions"><button type="button" onclick="window.print()">🖨 Vytisknout na A4 / uložit jako PDF</button> <a href="/">← Zpět na web</a></div>
<main class="page"><?=mm_coupon_html($o,false)?></main>
<p class="printhelp">Při tisku zapněte v prohlížeči tisk barev a obrázků na pozadí. QR kód slouží k ověření poukazu u provozovatele.</p>
</body></html>