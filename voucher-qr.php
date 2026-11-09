<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$token=$_GET['token']??'';
if(!is_string($token)||!preg_match('/\A[a-f0-9]{48}\z/D',$token)){http_response_code(404);exit;}
try{
 $stmt=mm_db()->prepare("SELECT id FROM orders WHERE token=? AND kind='gift' AND status='paid' LIMIT 1");
 $stmt->execute([$token]);if(!$stmt->fetchColumn()){http_response_code(404);exit;}
}catch(Throwable $e){http_response_code(503);exit;}
if(!function_exists('imagepng')){http_response_code(503);exit;}
require_once __DIR__.'/vendor/php-qrcode.php';
$url='https://moje-masaze.jirijanousek.cz/checkin.php?code='.rawurlencode($token);
try{
 $generator=new QRCode($url,['s'=>'qrm','sf'=>7,'p'=>15,'bc'=>'ffffff','fc'=>'21352b']);
 header('Content-Type: image/png');
 $generator->output_image();
}catch(Throwable $e){error_log('Voucher QR: '.get_class($e));http_response_code(503);}
