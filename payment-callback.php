<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');

$token=trim(scalar_string($_GET['token']??''));
$trackId=normalize_digits(trim(scalar_string($_GET['trackId']??$_GET['trackid']??'')));
$successFlag=normalize_digits(trim(scalar_string($_GET['success']??'')));
$status=normalize_digits(trim(scalar_string($_GET['status']??'')));

if(!preg_match('/^[a-f0-9]{64}$/',$token)){http_response_code(404);exit('سفارش پیدا نشد.');}
$stmt=db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');$stmt->execute([$token]);$order=$stmt->fetch();
if(!$order){http_response_code(404);exit('سفارش پیدا نشد.');}

$success=false;$pending=false;$message='پرداخت توسط کاربر لغو یا ناموفق شد.';$reference='';
$callbackSuccessful = $successFlag === '1' || $status === '2';
if($callbackSuccessful && preg_match('/^\d{5,30}$/',$trackId)){
    try{
        $result=payment_verify_order($order,$trackId);
        $success=true;
        $reference=(string)$result['reference'];
        $message=$result['already']?'این پرداخت قبلاً تأیید شده بود.':'پرداخت با موفقیت تأیید شد.';
    }catch(Throwable $e){
        log_error($e);
        payment_mark_verification_pending((int)$order['id'],$trackId,$e->getMessage());
        $pending=true;
        $message='نتیجه پرداخت از زیبال هنوز قطعی نشده است. پرداخت را تکرار نکنید و چند لحظه بعد دوباره بررسی کنید.';
    }
}else{
    payment_mark_failed((int)$order['id'],$trackId,$message);
}
if($success){
    $_SESSION['_last_order_public_token']=$token;
    redirect(app_url('order-success.php?token='.$token.'&payment=success'));
}
$pageKey='payment';$pageTitle='نتیجه پرداخت | '.setting('store_name');$robots='noindex,nofollow';
require BASE_PATH.'/app/views/header.php';
?>
<main><div class="payment-result <?=$pending?'':'error'?>"><h1><?=$pending?'پرداخت در حال بررسی است':'پرداخت کامل نشد'?></h1><p><?=e($message)?></p><?php if($pending):?><p>تا مشخص‌شدن نتیجه، پرداخت جدیدی انجام ندهید.</p><a class="btn btn-primary" href="<?=e(app_url('payment-callback.php?token='.$token.'&trackId='.$trackId.'&success=1'))?>">بررسی دوباره پرداخت</a><?php else:?><p>سفارش شما حذف نشده و می‌توانید دوباره پرداخت را انجام دهید.</p><a class="btn btn-primary" href="<?=e(app_url('payment-start.php?token='.$token))?>">پرداخت دوباره</a><?php endif?><a class="btn btn-secondary" href="<?=e(app_url('order-success.php?token='.$token))?>">مشاهده سفارش</a></div></main>
<?php require BASE_PATH.'/app/views/footer.php';
