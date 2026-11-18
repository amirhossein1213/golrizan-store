<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
$token=trim(scalar_string($_GET['token']??$_POST['token']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$token)){http_response_code(404);exit('سفارش پیدا نشد.');}
$stmt=db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');$stmt->execute([$token]);$order=$stmt->fetch();
if(!$order){http_response_code(404);exit('سفارش پیدا نشد.');}
if((string)$order['payment_method']!=='torobpay'){redirect(app_url('order-success.php?token='.$token));}
$txId=max(0,scalar_int($_GET['tx']??$_POST['tx']??0));
$transaction=torobpay_callback_transaction((int)$order['id'],$txId);
if(!$transaction){http_response_code(404);exit('تراکنش ترب‌پی پیدا نشد.');}
$paymentToken=trim((string)($transaction['authority']??''));
$clientState=strtoupper(trim(scalar_string($_GET['state']??$_POST['state']??'')));
$callbackTransactionId=trim(scalar_string($_POST['transactionId']??$_GET['transactionId']??''));
$callbackAmount=trim(normalize_digits(scalar_string($_POST['amount']??$_GET['amount']??'')));
$callbackContractError='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $requestPayload=json_decode((string)($transaction['request_json']??''),true);
    $expectedTransactionId=is_array($requestPayload)?trim((string)($requestPayload['transactionId']??'')):'';
    if(!in_array($clientState,['OK','FAILED'],true))$callbackContractError='وضعیت Callback ترب‌پی معتبر نیست.';
    elseif($expectedTransactionId!==''&&($callbackTransactionId===''||!hash_equals($expectedTransactionId,$callbackTransactionId)))$callbackContractError='شناسه تراکنش Callback ترب‌پی با سفارش تطابق ندارد.';
    elseif($callbackAmount===''||!ctype_digit($callbackAmount)||(int)$callbackAmount!==payment_toman_to_rial((int)$order['total']))$callbackContractError='مبلغ Callback ترب‌پی با سفارش تطابق ندارد.';
}
$success=false;$pending=false;$cancelled=false;$message='نتیجه پرداخت ترب‌پی قطعی نشد.';$reference='';
try{
    if($callbackContractError!=='')throw new RuntimeException($callbackContractError);
    $result=torobpay_verify_order($order,$paymentToken);
    $success=true;$reference=(string)$result['reference'];
    $message=!empty($result['already'])?'این پرداخت قبلاً تأیید شده بود.':'پرداخت ترب‌پی با موفقیت تأیید شد.';
}catch(Throwable $e){
    log_error($e);
    $status=torobpay_status($paymentToken);
    $cancelled=torobpay_status_is_cancelled($status)||in_array($clientState,['REVERT','CANCELLED','CANCELED'],true);
    $terminal=torobpay_status_is_terminal_failure($status)||$clientState==='FAILED'||$cancelled;
    if($terminal){
        $transactionStatus=$cancelled?'cancelled':'failed';
        db()->prepare("UPDATE payment_transactions SET status=?,response_json=? WHERE id=? AND status NOT IN ('paid','partially_refunded','refunded')")->execute([$transactionStatus,json_encode(['error'=>$e->getMessage(),'status'=>$status,'state'=>$clientState],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$transaction['id']]);
        db()->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND gateway_reference=? AND payment_status IN ('unpaid','pending','failed')")->execute([(int)$order['id'],$paymentToken]);
        $message=$cancelled?'پرداخت ترب‌پی توسط شما لغو شد و هیچ پرداخت موفقی برای این تراکنش ثبت نشد.':'پرداخت ترب‌پی ناموفق بود و پرداخت‌شده ثبت نشد.';
    }else{
        torobpay_mark_verification_pending((int)$order['id'],$paymentToken,$e->getMessage());
        $pending=true;$message='نتیجه پرداخت از ترب‌پی هنوز قطعی نشده است. پرداخت را تکرار نکنید و کمی بعد دوباره بررسی کنید.';
    }
}
if($success){$_SESSION['_last_order_public_token']=$token;redirect(app_url('order-success.php?token='.$token.'&payment=success'));}
$pageKey='payment';$pageTitle='نتیجه پرداخت ترب‌پی | '.setting('store_name');$robots='noindex,nofollow';require BASE_PATH.'/app/views/header.php';
?><main><div class="payment-result <?=$pending?'':'error'?>"><h1><?=$pending?'پرداخت در حال بررسی است':($cancelled?'پرداخت لغو شد':'پرداخت کامل نشد')?></h1><p><?=e($message)?></p><?php if($pending):?><p>تا مشخص‌شدن نتیجه، پرداخت جدیدی انجام ندهید.</p><a class="btn btn-primary" href="<?=e(app_url('torobpay-callback.php?token='.$token.($transaction?'&tx='.(int)$transaction['id']:'')))?>">بررسی دوباره پرداخت</a><?php else:?><p>سفارش شما حذف نشده و در صورت فعال‌بودن مهلت پرداخت می‌توانید دوباره تلاش کنید.</p><a class="btn btn-primary" href="<?=e(app_url('torobpay-consent.php?token='.$token))?>">پرداخت دوباره با ترب‌پی</a><?php endif?><a class="btn btn-secondary" href="<?=e(app_url('order-success.php?token='.$token))?>">مشاهده سفارش</a></div></main><?php require BASE_PATH.'/app/views/footer.php';
