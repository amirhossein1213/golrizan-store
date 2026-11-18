<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
$token=trim(scalar_string($_GET['token']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$token)){http_response_code(404);exit('سفارش پیدا نشد.');}
$stmt=db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');$stmt->execute([$token]);$order=$stmt->fetch();
if(!$order){http_response_code(404);exit('سفارش پیدا نشد.');}
if((string)$order['payment_method']!=='torobpay'){redirect(app_url('order-success.php?token='.$token));}
if((string)$order['payment_status']==='paid'){redirect(app_url('order-success.php?token='.$token));}

// هر بار شروع واقعی پرداختِ دارای افزایش قیمت، باید بلافاصله قبلش از صفحه پذیرش عبور کند.
$markupPercent=(float)($order['payment_markup_percent']??0);
if($markupPercent>0){
    $gates=is_array($_SESSION['_torobpay_start_consent']??null)?$_SESSION['_torobpay_start_consent']:[];
    $sessionAcceptedAt=(int)($gates[$token]??0);
    $databaseAcceptedAt=!empty($order['payment_markup_accepted_at'])?(int)strtotime((string)$order['payment_markup_accepted_at']):0;
    $acceptedAt=max($sessionAcceptedAt,$databaseAcceptedAt);
    unset($gates[$token]);
    foreach($gates as $key=>$time)if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/',$key)||(int)$time<time()-900)unset($gates[$key]);
    $_SESSION['_torobpay_start_consent']=array_slice($gates,-20,null,true);
    if($acceptedAt<time()-600){redirect(app_url('torobpay-consent.php?token='.$token));}
}

try{$url=torobpay_start_for_order($order);redirect($url);}catch(Throwable $e){log_error($e);$pageKey='payment';$pageTitle='خطا در شروع ترب‌پی | '.setting('store_name');$robots='noindex,nofollow';require BASE_PATH.'/app/views/header.php';?><main><div class="payment-result error"><h1>اتصال به ترب‌پی انجام نشد</h1><p><?=e($e->getMessage())?></p><a class="btn btn-primary" href="<?=e(app_url('torobpay-consent.php?token='.$token))?>">تلاش دوباره</a><a class="btn btn-secondary" href="<?=e(app_url('order-success.php?token='.$token))?>">مشاهده سفارش</a></div></main><?php require BASE_PATH.'/app/views/footer.php';}
