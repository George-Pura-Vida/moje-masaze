<?php
declare(strict_types=1);
/** Shared email and printable gift voucher; no template data comes from user HTML. */
function mm_coupon_date(array $o): array {
 $date=(string)($o['confirmed_date']??'');$time=(string)($o['confirmed_time']??'');
 if($date!=='' && $time!==''){
   $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
   return ['label'=>'POTVRZENÝ TERMÍN','value'=>($d?$d->format('d. m. Y'):$date).' · '.$time,'confirmed'=>true];
 }
 $date=(string)($o['preferred_date']??'');$time=(string)($o['preferred_time']??'');
 if($date!==''){
   $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
   return ['label'=>'PREFEROVANÝ TERMÍN (ČEKÁ NA POTVRZENÍ)','value'=>($d?$d->format('d. m. Y'):$date).($time!==''?' · '.$time:' · čas dle domluvy'),'confirmed'=>false];
 }
 return ['label'=>'TERMÍN MASÁŽE','value'=>'Dle individuální domluvy','confirmed'=>false];
}
function mm_coupon_html(array $o,bool $mail=false): string {
 $name=mm_escape(trim((string)($o['recipient']?:$o['buyer_name'])));
 $buyer=mm_escape($o['buyer_name']);
 $focus=mm_escape(MM_FOCUS[$o['focus']]??'Relaxační masáž');
 $vs=mm_escape($o['vs']);
 $duration=(int)$o['duration'];$count=(int)$o['quantity'];
 $remaining=max(0,$count-(int)($o['redeemed_count']??0));
 $date=mm_coupon_date($o);
 $url='https://moje-masaze.jirijanousek.cz/voucher.php?token='.rawurlencode($o['token']);
 $qrUrl='https://moje-masaze.jirijanousek.cz/voucher-qr.php?token='.rawurlencode($o['token']);
 $qrSrc=$mail?'cid:mm-gift-qr@moje-masaze':$qrUrl;
 $image='https://images.unsplash.com/photo-1540555700478-4be289fbecef?w=1200&q=85&fit=crop';
 $status=$remaining===0?'VYUŽITÝ':($remaining<$count?'ČÁSTEČNĚ VYUŽITÝ':'PLATNÝ A UHRAZENÝ');
 $html='
 <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:720px;margin:0 auto;border-collapse:collapse;background:#fffaf2;border:1px solid #d7be94;font-family:Arial,Helvetica,sans-serif;color:#24362a">
   <tr><td style="background:#1e3228;padding:23px 32px;color:#f1dec0;text-align:center"><div style="font-family:Georgia,serif;font-size:27px;letter-spacing:1px">❁ DOTYK &amp; KLID</div><div style="font-size:11px;letter-spacing:3px;color:#d3b88e;margin-top:8px">MOJE MASÁŽE · JIŘÍ JANOUŠEK</div></td></tr>
   <tr><td><img src="'.mm_escape($image).'" width="720" height="210" alt="Klidné relaxační prostředí pro masáže" style="display:block;width:100%;height:210px;object-fit:cover;border:0"></td></tr>
   <tr><td style="padding:26px 36px 10px;text-align:center"><div style="font-size:11px;letter-spacing:3px;color:#9f733d;font-weight:bold">DARUJTE ČAS PRO SEBE</div><h1 style="font-family:Georgia,serif;font-size:43px;font-weight:normal;line-height:1.1;margin:14px 0 8px;color:#273d30">Dárkový poukaz</h1><div style="color:#ab8152;font-family:Georgia,serif;font-size:26px">'.$focus.'</div>
     <p style="font-size:13px;letter-spacing:2px;color:#5f705f;margin:25px 0 5px">PRO</p><div style="font-family:Georgia,serif;font-size:33px;color:#243e2e;overflow-wrap:anywhere">'.$name.'</div></td></tr>
   <tr><td style="padding:12px 30px 28px"><table role="presentation" width="100%" cellpadding="12" cellspacing="0" style="border-collapse:collapse;background:#f2e9d9;border:1px solid #e0d1b8">
     <tr><td style="width:50%;border-bottom:1px solid #ddccb4"><span style="font-size:10px;color:#836c4e;letter-spacing:1.4px">MASÁŽ</span><br><strong style="font-size:17px">'.$duration.' minut</strong></td><td style="border-bottom:1px solid #ddccb4"><span style="font-size:10px;color:#836c4e;letter-spacing:1.4px">POČET VSTUPŮ</span><br><strong style="font-size:17px">'.$count.'× masáž</strong></td></tr>
     <tr><td colspan="2"><span style="font-size:10px;color:#836c4e;letter-spacing:1.4px">'.mm_escape($date['label']).'</span><br><strong style="font-size:17px">'.mm_escape($date['value']).'</strong></td></tr>
     </table></td></tr>
   <tr><td style="padding:0 30px 20px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
     <td style="vertical-align:middle;width:63%;padding:16px 9px 16px 0"><div style="font-size:10px;color:#8a6a44;letter-spacing:2px;font-weight:bold">VSTUPNÍ QR KÓD</div><p style="font-size:14px;line-height:1.65;color:#465a4a">Předložte při návštěvě. Provozovatel ověří jeho platnost a bezpečně zaeviduje využití masáže.</p><p style="font-size:13px;color:#526653"><strong>Číslo poukazu:</strong> MM-'.$vs.'<br><strong>Zbývá:</strong> '.$remaining.' z '.$count.' vstupů<br><strong>Stav:</strong> '.$status.'</p></td>
     <td align="center" style="vertical-align:middle;padding:8px;background:#fff;border:1px solid #e3d6bd"><img src="'.mm_escape($qrSrc).'" alt="QR kód pro vstup a ověření poukazu" width="156" height="156" style="display:block;width:156px;height:156px"></td>
   </tr></table></td></tr>
   <tr><td style="padding:16px 35px;text-align:center;background:#23392c;color:#f5e7cb;font-size:12px;line-height:1.8">Vystavil Jiří Janoušek · IČO 13798804<br>info@jirijanousek.cz · moje-masaze.jirijanousek.cz</td></tr>
 </table>';
 if($mail)$html.='<p style="max-width:720px;margin:20px auto;text-align:center;font:15px Arial,sans-serif"><a href="'.mm_escape($url).'" style="display:inline-block;background:#a67a42;color:#fff;padding:15px 25px;border-radius:8px;text-decoration:none;font-weight:bold">🎁 Otevřít poukaz v plné kvalitě a vytisknout A4</a></p><p style="color:#64685f;text-align:center;font:13px Arial,sans-serif">QR kód je platný pouze po bankovním potvrzení úhrady; neumožňuje veřejné uplatnění bez ověření správcem.</p>';
 return $html;
}
