<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
rate_limit('product-alert',20,300);
if(!is_post())json_response(['ok'=>false,'message'=>'روش درخواست معتبر نیست.'],405);
$data=input_json();verify_csrf(scalar_string($data['_csrf']??''));
try{
    $productId=max(0,scalar_int($data['product_id']??0));$variantId=max(0,scalar_int($data['variant_id']??0));$type=scalar_string($data['alert_type']??'stock');
    $customer=customer_user();$savedPhone=$customer?normalize_phone((string)($customer['phone']??'')):'';$phone=preg_match('/^09\d{9}$/',$savedPhone)?$savedPhone:scalar_string($data['phone']??'');$target=null;$raw=normalize_digits(trim(scalar_string($data['target_price']??'')));if($raw!==''){if(!ctype_digit($raw))throw new RuntimeException('قیمت هدف معتبر نیست.');$target=(int)$raw;}
    $result=product_alert_subscribe($productId,$variantId,$phone,$type,$target,$customer?(int)$customer['id']:null);
    json_response(['ok'=>true,'message'=>'هشدار ثبت شد؛ پس از تحقق شرط برای شما پیامک می‌فرستیم.','alert'=>$result]);
}catch(Throwable $e){json_response(['ok'=>false,'message'=>public_exception_message($e,'ثبت هشدار انجام نشد. دوباره تلاش کنید.')],$e instanceof RuntimeException&&!($e instanceof PDOException)?422:500);}
