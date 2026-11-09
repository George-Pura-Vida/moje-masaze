<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/mm_test_'.bin2hex(random_bytes(6));
if(!mkdir($dir,0700,true))throw new RuntimeException('Test temp directory unavailable');
putenv('MASSAGE_PRIVATE_DIR='.$dir);
putenv('MASSAGE_EUR_ENABLED=0');
require_once dirname(__DIR__).'/lib.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: ".$message."\n";}
function delete_tree(string $dir): void {
 foreach(scandir($dir)?:[] as $f){
  if($f==='.'||$f==='..')continue;
  $p=$dir.'/'.$f;if(is_dir($p))delete_tree($p);else @unlink($p);
 }
 @rmdir($dir);
}
register_shutdown_function(static function()use($dir){delete_tree($dir);});
check(mm_price(60,1)===200000,'60-minute price');
check(mm_price(60,2)===380000,'two 60-minute sessions discounted by 5%');
check(mm_price(60,3)===540000,'three 60-minute sessions discounted by 10%');
check(mm_price(180,1)===300000,'180-minute price');
check(mm_price(180,2)===570000,'two 180-minute sessions discounted by 5%');
check(mm_price(180,3)===810000,'three 180-minute sessions discounted by 10%');
try{mm_price(10,1);throw new RuntimeException('FAIL: accepted invalid duration');}catch(InvalidArgumentException $e){check(true,'invalid duration is rejected');}
try{mm_quote(60,1,'EUR');throw new RuntimeException('FAIL: unconfigured EUR payments accepted');}catch(InvalidArgumentException $e){check(true,'EUR can be disabled by configuration');}
check(mm_quote(60,1,'CZK')['amount_cents']===200000,'CZK quote');
$csrf=mm_csrf();
$input=['kind'=>'booking','focus'=>'holistic','duration'=>'60','count'=>'2','name'=>'Test Client','email'=>'demo@example.org','phone'=>'+420 700 111 222','date'=>'','time'=>'','note'=>'','recipient'=>'','website'=>'','lang'=>'en','currency'=>'CZK','terms'=>true,'csrf'=>$csrf];
$order=mm_create_order($input,'192.0.2.1');
check(strlen($order['token'])===48,'unpredictable private order token');
check(preg_match('/\A57[0-9]{8}\z/D',$order['vs'])===1,'unique ten-digit order variable symbol');
check((float)$order['amount']===3800.0,'server recomputes price');
check($order['currency']==='CZK','server returns payment currency');
check(mm_eur_iban()===MM_IBAN,'EUR transfer uses the same owner-verified Fio IBAN');

$q=mm_db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$order['id']]);$row=$q->fetch();
check($row!==false && $row['status']==='pending','new order is unpaid');
check($row['amount_cents']===380000,'stored amount cannot be controlled by client');
check((int)mm_db()->query('SELECT COUNT(*) FROM email_queue')->fetchColumn()===2,'two initial operational emails queued');
$withoutHoneypot=$input;unset($withoutHoneypot['website']);
$cleanOrder=mm_create_order($withoutHoneypot,'192.0.2.18');
check($cleanOrder['id']!==''&&$cleanOrder['currency']==='CZK','real CZK order without auto-filled anti-spam field is accepted');

$dayForTest=(new DateTimeImmutable('+15 days',new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$realistic=[...$input,'name'=>'Test Client CZ','email'=>'booking.test@example.org','phone'=>'+420700123456','count'=>'1','date'=>$dayForTest,'time'=>'13:00','note'=>'Thank you'];
$realOrder=mm_create_order($realistic,'192.0.2.121');
check($realOrder['currency']==='CZK' && (float)$realOrder['amount']===2000.0,'fully populated booking form saves valid date, time and phone');
$legacyAuto=mm_create_order([...$input,'website'=>'autofilled.example'],'192.0.2.122');
check(strlen($legacyAuto['token'])===48,'browser autofill of legacy anti-spam field does not block orders');

check(str_contains(mm_mail_template($row,'new_client')['html'],'DOTYK'),'branded HTML email');
try{mm_create_order([...$input,'csrf'=>'invalid'],'192.0.2.2');throw new RuntimeException('FAIL: invalid CSRF accepted');}catch(InvalidArgumentException $e){check(true,'invalid CSRF rejected');}
try{mm_create_order([...$input,'currency'=>'EUR'],'192.0.2.3');throw new RuntimeException('FAIL: unconfigured EUR order accepted');}catch(InvalidArgumentException $e){check(true,'unconfigured EUR order rejected');}
$date=(new DateTimeImmutable('+3 days',new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$confirmed=mm_admin_update($order['id'],'confirm',['confirmed_date'=>$date,'confirmed_time'=>'10:00']);
check($confirmed['appointment_status']==='confirmed'&&$confirmed['confirmed_date']===$date,'admin can confirm appointment without changing bank status');
check($confirmed['status']==='pending','admin confirmation does not falsely mark bank transfer paid');
$done=mm_admin_update($order['id'],'complete',[]);
check($done['appointment_status']==='completed','admin can complete a session');
$giftInput=[...$input,'kind'=>'gift','recipient'=>'Gift Person','count'=>'3'];
$gift=mm_create_order($giftInput,'192.0.2.4');
try{mm_admin_update($gift['id'],'redeem',[]);throw new RuntimeException('FAIL: unpaid gift redeemed');}catch(InvalidArgumentException $e){check(true,'unpaid voucher cannot be redeemed');}
mm_db()->prepare("UPDATE orders SET status='paid' WHERE id=?")->execute([$gift['id']]);
for($i=1;$i<=3;$i++){
 $redeemed=mm_admin_update($gift['id'],'redeem',[]);
 check((int)$redeemed['redeemed_count']===$i,'gift redemption count '.$i);
}
try{mm_admin_update($gift['id'],'redeem',[]);throw new RuntimeException('FAIL: overredeemed gift');}catch(InvalidArgumentException $e){check(true,'paid gift cannot be redeemed beyond purchased count');}

$slotDay=(new DateTimeImmutable('+5 days',new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$first=mm_create_order([...$input,'count'=>'1','date'=>$slotDay,'time'=>'11:00'],'192.0.2.5');
check((float)$first['amount']===2000.0,'specific 60-minute appointment saved');
$open=mm_slot_list($slotDay,60);
$at11=array_values(array_filter($open,static fn($x)=>$x['time']==='11:00'));
check(count($at11)===1&&!$at11[0]['available'],'selected slot is reserved');
try{
 mm_create_order([...$input,'count'=>'1','date'=>$slotDay,'time'=>'11:00'],'192.0.2.6');
 throw new RuntimeException('FAIL: double booking accepted');
}catch(InvalidArgumentException $e){check(true,'double booking is rejected');}
try{
 mm_create_order([...$input,'count'=>'1','date'=>$slotDay,'time'=>'12:00'],'192.0.2.7');
 throw new RuntimeException('FAIL: overlapping massage accepted');
}catch(InvalidArgumentException $e){check(true,'overlapping booking and transition buffer are rejected');}
$giftSchedule=(new DateTimeImmutable('+6 days',new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$giftConfirmed=mm_admin_update($gift['id'],'schedule_gift',['confirmed_date'=>$giftSchedule,'confirmed_time'=>'14:00']);
check($giftConfirmed['appointment_status']==='confirmed'&&$giftConfirmed['confirmed_time']==='14:00','admin can confirm a paid gift voucher date and hour');
require_once dirname(__DIR__).'/coupon.php';
$printed=mm_coupon_html($giftConfirmed,false);
check(str_contains($printed,'Gift Person')&&str_contains($printed,'14:00')&&str_contains($printed,'voucher-qr.php'),'printable coupon includes recipient, time and entry QR image');
$emailCoupon=mm_mail_template($giftConfirmed,'paid_client');
check(str_contains($emailCoupon['html'],'cid:mm-gift-qr@moje-masaze'),'paid gift email embeds the QR code');
check(str_contains($emailCoupon['html'],'Gift Person'),'paid gift email includes recipient name');
require_once dirname(__DIR__).'/vendor/php-qrcode.php';
$qr=new QRCode('https://moje-masaze.jirijanousek.cz/checkin.php?code='.str_repeat('a',48),['s'=>'qrm']);
$svg=$qr->render_svg();
check(str_contains($svg,'<svg')&&str_contains($svg,'<path'),'server generates entry QR SVG without GD');
$key=mm_calendar_secret();
check(preg_match('/\A[a-f0-9]{64}\z/D',$key)===1,'private calendar feed key created securely');


check((int)mm_db()->query('SELECT COUNT(*) FROM clients')->fetchColumn()===2,'CRM deduplicates repeat massage customers by email');
$clientID=mm_crm_save_client(['full_name'=>'Customer Demo','email'=>'customer-demo@example.org','phone'=>'+420 777 000 111','notes'=>'Repeat appointment']);
$client=mm_db()->prepare('SELECT full_name,email,phone,notes FROM clients WHERE id=?');$client->execute([$clientID]);$clientRow=$client->fetch();
check($clientRow['full_name']==='Customer Demo' && str_contains($clientRow['notes'],'Repeat'),'CRM owner can create and edit customer profiles');
mm_crm_save_client(['client_id'=>$clientID,'full_name'=>'Customer Updated','email'=>'customer-demo@example.org','phone'=>'+420 777 000 111','notes'=>'Preferred afternoon']);
$client->execute([$clientID]);check($client->fetch()['full_name']==='Customer Updated','CRM customer edit saved to private DB');
$cache=$dir.'/eur-rate.json';
file_put_contents($cache,json_encode(['rate'=>25.0,'date'=>'test']));
@chmod($cache,0600);
putenv('MASSAGE_EUR_ENABLED=1');
$eurQuote=mm_quote(60,1,'EUR');
check($eurQuote['iban']===MM_IBAN && $eurQuote['amount_cents']===8000,'EUR quotes match Fio IBAN and validated reference rate');
check(mm_money($eurQuote['amount_cents'],'EUR')==='80 €','whole EUR shows no decimal places');
file_put_contents($cache,json_encode(['rate'=>24.36,'date'=>'test']));
$roundedQuote=mm_quote(60,1,'EUR');
check($roundedQuote['amount_cents']===8200,'2000 CZK divided by 24.36 rounds to exactly 82 EUR');
check(mm_money($roundedQuote['amount_cents'],'EUR')==='82 €','rounded EUR is displayed without cents');
check(mm_quote(180,1,'EUR')['amount_cents']===12300,'3000 CZK rounds to whole EUR');
check(mm_quote(60,2,'EUR')['amount_cents']===15600,'discounted package rounds to whole EUR');
check(mm_quote(60,3,'EUR')['amount_cents']===22200,'three-visit package rounds to whole EUR');
try{mm_create_order([...$input,'currency'=>'EUR','count'=>'1','expected_amount_cents'=>8210],'192.0.2.190');throw new RuntimeException('FAIL: mismatched EUR quote accepted');}catch(InvalidArgumentException $e){check(true,'order rejects obsolete cents quote');}
file_put_contents($cache,json_encode(['rate'=>25.0,'date'=>'test']));
$eurOrder=mm_create_order([...$input,'currency'=>'EUR','count'=>'1','expected_amount_cents'=>8000],'192.0.2.19');
check($eurOrder['currency']==='EUR' && $eurOrder['iban']===MM_IBAN && (float)$eurOrder['amount']===80.0,'EUR order is saved with correct SEPA IBAN');
$q=mm_db()->prepare('SELECT status FROM orders WHERE id=?');$q->execute([$eurOrder['id']]);
check($q->fetchColumn()==='pending','EUR order stays pending until actual banking confirmation');
putenv('MASSAGE_EUR_ENABLED=0');

$inquiry=mm_crm_add_inquiry(['name'=>'Test Contact','email'=>'contact@example.org','phone'=>'+420 777 000 222','subject'=>'Gift voucher','message'=>'Please send me an appointment date for a gift voucher.','website'=>'','terms'=>true,'csrf'=>$csrf],'192.0.2.90');
$q=mm_db()->prepare('SELECT status FROM inquiries WHERE id=?');$q->execute([$inquiry]);
check($q->fetchColumn()==='new','contact message safely stored in inbox');


check((int)mm_db()->query("SELECT COUNT(*) FROM inquiry_email_queue WHERE status='pending'")->fetchColumn()===2,'client and administrator contact confirmation messages queued');
$contactAuto=mm_crm_add_inquiry(['name'=>'Test Client CZ','email'=>'booking.test@example.org','phone'=>'+420700123456','subject'=>'Gift voucher','message'=>'Please arrange a new massage appointment for next week.','website'=>'autofilled.example','terms'=>true,'csrf'=>$csrf],'192.0.2.123');
check(strlen($contactAuto)===24,'contact form saves with matching customer email and autofilled legacy field');

mm_crm_inquiry_status($inquiry,'resolved');$q->execute([$inquiry]);check($q->fetchColumn()==='resolved','inbox message can be marked resolved');
require_once dirname(__DIR__).'/admin-auth.php';
$ownerPassword='ExampleTestPassphrase_2026_only';
check(mm_admin_hash()==='','new server does not ship a predefined admin password');
$activationToken=mm_admin_generate_reset();
check(mm_admin_reset_is_valid($activationToken),'first-time owner password activation token is valid');
mm_admin_complete_reset($activationToken,$ownerPassword,$ownerPassword);
check(password_verify($ownerPassword,mm_admin_hash()),'owner can initialize private password via one-time email link');
mm_admin_save_password($ownerPassword);
check(password_verify($ownerPassword,mm_admin_hash()),'admin password stored only as a private password hash');
$reset=mm_admin_generate_reset();
check(mm_admin_reset_is_valid($reset),'single-use password reset token initially valid');
$updatedPassword='AnotherSafePassword_2026';
mm_admin_complete_reset($reset,$updatedPassword,$updatedPassword);
check(!mm_admin_reset_is_valid($reset),'password reset token invalid after use');
check(password_verify($updatedPassword,mm_admin_hash())&&!password_verify($ownerPassword,mm_admin_hash()),'password reset securely rotates the hash');
$denied=false;
try{mm_admin_complete_reset($reset,'ThirdSafePassword_2026','ThirdSafePassword_2026');}
catch(InvalidArgumentException $e){$denied=true;}
check($denied,'replay of used password reset denied');

require_once dirname(__DIR__).'/cron/fio-sync.php';
$bankDay=new DateTimeImmutable('today',new DateTimeZone('Europe/Prague'));
$periodStart=$bankDay->modify('-14 days');
function bank_row(string $id,mixed $amount,?string $currency,string $day,string $vs='',string $message=''): array {
 return ['column22'=>['value'=>$id],'column1'=>['value'=>$amount],'column14'=>['value'=>$currency],
  'column0'=>['value'=>$day],'column5'=>['value'=>$vs],'column16'=>['value'=>$message]];
}
function bank_statement(string $iban,?array $rows): array {
 return ['accountStatement'=>['info'=>['iban'=>$iban,'currency'=>'CZK'],'transactionList'=>['transaction'=>$rows]]];
}
$today=$bankDay->format('Y-m-d');
$ref='MMVS-'.$eurOrder['vs'];
$wrong=[
 bank_row('100',80,'CZK',$today,$eurOrder['vs'],$ref),
 bank_row('101',80,null,$today,'',$ref),
 bank_row('102','80.001','EUR',$today,'',$ref),
 bank_row('103',-80,'EUR',$today,'',$ref),
 bank_row('104',80,'EUR',$bankDay->modify('-1 day')->format('Y-m-d'),'',$ref),
 bank_row('105',80,'EUR',$today,$cleanOrder['vs'],$ref),
 bank_row('106',80,'EUR',$today,'',$ref.' MMVS-'.$cleanOrder['vs']),
 bank_row('107',81,'EUR',$today,'',$ref),
];
$db=mm_db();
check(mm_fio_reconcile($db,bank_statement(MM_IBAN,$wrong),$periodStart,$bankDay)===['CZK'=>0,'EUR'=>0],
 'Fio rejects converted, missing-currency, inexact, outgoing, predating and ambiguous transactions');
$valid=[bank_row('108','80.00','EUR',$today,'',$ref),bank_row('109',3800,'CZK',$today,$cleanOrder['vs'])];
try{
 mm_fio_reconcile($db,bank_statement('CZ0000000000000000000000',$valid),$periodStart,$bankDay);
 throw new LogicException('FAIL: wrong Fio destination accepted');
}catch(RuntimeException $e){check(true,'wrong Fio statement IBAN rejected');}
$db->exec("CREATE TRIGGER test_queue_failure BEFORE INSERT ON email_queue WHEN NEW.kind='paid_admin'
 BEGIN SELECT RAISE(ABORT,'simulated queue failure'); END");
try{
 mm_fio_reconcile($db,bank_statement(MM_IBAN,$valid),$periodStart,$bankDay);
 throw new LogicException('FAIL: queue failure ignored');
}catch(PDOException $e){
 $q=$db->prepare('SELECT status FROM orders WHERE id=?');$q->execute([$eurOrder['id']]);
 check($q->fetchColumn()==='pending','payment status rolls back if its notifications cannot be queued');
}
$db->exec('DROP TRIGGER test_queue_failure');
check(mm_fio_reconcile($db,bank_statement(MM_IBAN,$valid),$periodStart,$bankDay)===['CZK'=>1,'EUR'=>1],
 'same authenticated Fio statement reconciles CZK and EUR independently');
check(mm_fio_reconcile($db,bank_statement(MM_IBAN,$valid),$periodStart,$bankDay)===['CZK'=>0,'EUR'=>0],
 'Fio replay cannot confirm or enqueue again');
check((int)$db->query("SELECT COUNT(*) FROM email_queue WHERE kind IN ('paid_client','paid_admin')")->fetchColumn()===4,
 'only verified payments queue both notices');
$q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$gift['id']]);$paidGift=$q->fetch();
$template=mm_mail_template($paidGift,'paid_client');
check(str_contains($template['html'],'cid:mm-gift-qr@moje-masaze')&&$template['inline_qr_token']===$gift['token'],
 'paid gift HTML contains its private inline redemption QR');
check(mm_mail_template($paidGift,'new_client')['inline_qr_token']===null,
 'order acknowledgement contains no redemption QR');
if(function_exists('imagepng'))check(str_starts_with(mm_voucher_qr_png($gift['token'])??'',"\x89PNG"),'gift QR renders as PNG');

echo "All smoke tests passed.\n";