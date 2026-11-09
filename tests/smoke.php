<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/mm_test_'.bin2hex(random_bytes(6));
if(!mkdir($dir,0700,true))throw new RuntimeException('Test temp directory unavailable');
putenv('MASSAGE_PRIVATE_DIR='.$dir);
putenv('MASSAGE_EUR_ENABLED=0');
require_once dirname(__DIR__).'/lib.php';
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
try{mm_quote(60,1,'EUR');throw new RuntimeException('FAIL: unconfigured EUR payments accepted');}catch(InvalidArgumentException $e){check(true,'EUR disabled without separate bank config');}
check(mm_quote(60,1,'CZK')['amount_cents']===200000,'CZK quote');
session_start();$csrf=mm_csrf();
$input=['kind'=>'booking','focus'=>'holistic','duration'=>'60','count'=>'2','name'=>'Test Client','email'=>'demo@example.org','phone'=>'+420 700 111 222','date'=>'','time'=>'','note'=>'','recipient'=>'','website'=>'','lang'=>'en','currency'=>'CZK','terms'=>true,'csrf'=>$csrf];
$order=mm_create_order($input,'192.0.2.1');
check(strlen($order['token'])===48,'unpredictable private order token');
check(preg_match('/\A57[0-9]{8}\z/D',$order['vs'])===1,'unique ten-digit order variable symbol');
check($order['amount']===3800.0,'server recomputes price');
check($order['currency']==='CZK','server returns payment currency');
$q=mm_db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$order['id']]);$row=$q->fetch();
check($row!==false && $row['status']==='pending','new order is unpaid');
check($row['amount_cents']===380000,'stored amount cannot be controlled by client');
check((int)mm_db()->query('SELECT COUNT(*) FROM email_queue')->fetchColumn()===2,'two initial operational emails queued');
check(str_contains(mm_mail_template($row,'new_client')['html'],'DOTYK'),'branded HTML email');
try{mm_create_order([...$input,'csrf'=>'invalid'],'192.0.2.2');throw new RuntimeException('FAIL: invalid CSRF accepted');}catch(InvalidArgumentException $e){check(true,'invalid CSRF rejected');}
try{mm_create_order([...$input,'currency'=>'EUR'],'192.0.2.3');throw new RuntimeException('FAIL: unconfigured EUR order accepted');}catch(InvalidArgumentException $e){check(true,'unconfigured EUR order rejected');}
echo "All smoke tests passed.\n";