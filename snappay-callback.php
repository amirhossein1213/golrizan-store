<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');

function snappay_callback_payment_token(): string
{
    foreach (['paymentToken','payment_token','PaymentToken'] as $key) {
        $value=trim(scalar_string($_GET[$key]??$_POST[$key]??''));
        if($value!==''&&text_length($value)<=150&&!preg_match('/[\x00-\x1F\x7F]/',$value))return $value;
    }
    $fallback=$_SESSION['_snappay_last_payment']??null;
    if(is_array($fallback)&&(int)($fallback['time']??0)>=time()-7200){
        $value=trim((string)($fallback['payment_token']??''));
        if($value!==''&&text_length($value)<=150)return $value;
    }
    return '';
}

$paymentToken=snappay_callback_payment_token();
$order=null;$transaction=null;
$callbackTransactionId='';
foreach(['transactionId','transaction_id','TransactionId'] as $transactionKey){
    $candidate=trim(scalar_string($_GET[$transactionKey]??$_POST[$transactionKey]??''));
    if($candidate!==''&&text_length($candidate)<=180&&!preg_match('/[\x00-\x1F\x7F]/',$candidate)){$callbackTransactionId=$candidate;break;}
}
if($paymentToken===''&&$callbackTransactionId!==''&&preg_match('/^[A-Za-z0-9_-]{5,180}$/',$callbackTransactionId)){
    $needle='%"transactionId":"'.$callbackTransactionId.'"%';
    $ts=db()->prepare("SELECT pt.*,o.public_token,o.payment_method,o.payment_status,o.order_status,o.total,o.id AS order_row_id FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.gateway='snappay' AND pt.request_json LIKE ? ORDER BY pt.id DESC LIMIT 1");
    $ts->execute([$needle]);$candidateTx=$ts->fetch();
    if($candidateTx){
        $request=json_decode((string)($candidateTx['request_json']??''),true);
        $expected=is_array($request)?trim((string)($request['transactionId']??'')):'';
        if($expected!==''&&hash_equals($expected,$callbackTransactionId)){
            $transaction=$candidateTx;$paymentToken=trim((string)($candidateTx['authority']??''));
            $oid=(int)$candidateTx['order_id'];$os=db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');$os->execute([$oid]);$order=$os->fetch()?:null;
        }
    }
}
if(!$order&&$paymentToken!==''){
    $stmt=db()->prepare("SELECT pt.*,o.public_token,o.payment_method,o.payment_status,o.order_status,o.total,o.id AS order_row_id FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.gateway='snappay' AND pt.authority=? ORDER BY pt.id DESC LIMIT 1");
    $stmt->execute([$paymentToken]);$transaction=$stmt->fetch();
    if($transaction){$oid=(int)$transaction['order_id'];$os=db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');$os->execute([$oid]);$order=$os->fetch()?:null;}
}
if(!$order){
    $fallback=$_SESSION['_snappay_last_payment']??null;
    $publicToken=is_array($fallback)?trim((string)($fallback['order_token']??'')):'';
    if(preg_match('/^[a-f0-9]{64}$/',$publicToken)){
        $os=db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');$os->execute([$publicToken]);$order=$os->fetch()?:null;
        if($order&&$paymentToken===''){$tx=snappay_latest_requested_transaction((int)$order['id']);if($tx){$transaction=$tx;$paymentToken=trim((string)($tx['authority']??''));}}
    }
}
if(!$order||!$transaction||$paymentToken===''){http_response_code(404);exit('تراکنش اسنپ‌پی پیدا نشد.');}
$token=(string)$order['public_token'];
if((string)$order['payment_method']!=='snappay'){redirect(app_url('order-success.php?token='.$token));}

$requestPayload=json_decode((string)($transaction['request_json']??''),true);$expectedCallbackTransactionId=is_array($requestPayload)?trim((string)($requestPayload['transactionId']??'')):'';
if($callbackTransactionId!==''&&$expectedCallbackTransactionId!==''&&!hash_equals($expectedCallbackTransactionId,$callbackTransactionId)){http_response_code(400);exit('شناسه تراکنش Callback اسنپ‌پی معتبر نیست.');}
$callbackAmount=trim(scalar_string($_POST['amount']??$_GET['amount']??''));
if($callbackAmount!==''&&(!preg_match('/^\d+$/',$callbackAmount)||(int)$callbackAmount!==payment_toman_to_rial((int)$order['total']))){http_response_code(400);exit('مبلغ Callback اسنپ‌پی با سفارش تطابق ندارد.');}
$callbackState=strtoupper(trim(scalar_string($_POST['state']??$_GET['state']??'')));
if($callbackState!==''&&!in_array($callbackState,['OK','FAILED'],true)){http_response_code(400);exit('وضعیت Callback اسنپ‌پی معتبر نیست.');}
if($callbackState==='FAILED'){
    db()->prepare("UPDATE payment_transactions SET status='failed',response_json=? WHERE id=? AND status NOT IN ('paid','partially_refunded','refunded')")
        ->execute([json_encode(['callback_state'=>'FAILED','remote_status'=>'FAILED','at'=>date('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$transaction['id']]);
    db()->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND payment_status IN ('unpaid','pending','failed')")->execute([(int)$order['id']]);
    $pageKey='payment';$pageTitle='نتیجه پرداخت اسنپ‌پی | '.setting('store_name');$robots='noindex,nofollow';require BASE_PATH.'/app/views/header.php';
    ?><main><div class="payment-result error"><h1>پرداخت اسنپ‌پی ناموفق بود</h1><p>تراکنش از سمت اسنپ‌پی ناموفق اعلام شد.</p><a class="btn btn-secondary" href="<?=e(app_url('order-success.php?token='.$token))?>">مشاهده سفارش</a></div></main><?php require BASE_PATH.'/app/views/footer.php';exit;
}

$success=false;$pending=false;$message='نتیجه پرداخت اسنپ‌پی قطعی نشد.';
try{
    $result=snappay_verify_order($order,$paymentToken);
    $success=true;
    $message=!empty($result['already'])?'این پرداخت قبلاً تأیید شده بود.':'پرداخت اسنپ‌پی با موفقیت تأیید و نهایی شد.';
}catch(Throwable $e){
    log_error($e);
    $status=snappay_status($paymentToken);
    if(in_array($status,['FAILED','CANCELLED','CANCELED','CANCEL','EXPIRED','REJECTED','REVERTED','REVERT'],true)){
        db()->prepare("UPDATE payment_transactions SET status='failed',response_json=? WHERE id=? AND status NOT IN ('paid','partially_refunded','refunded')")->execute([json_encode(['error'=>$e->getMessage(),'status'=>$status],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$transaction['id']]);
        db()->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND gateway_reference=? AND payment_status IN ('unpaid','pending','failed')")->execute([(int)$order['id'],$paymentToken]);
        $message='پرداخت اسنپ‌پی کامل نشد یا توسط کاربر لغو شد.';
    }else{
        snappay_mark_verification_pending((int)$order['id'],$paymentToken,$e->getMessage());
        $pending=true;$message='نتیجه پرداخت از اسنپ‌پی هنوز قطعی نشده است. پرداخت را تکرار نکنید و دوباره وضعیت را بررسی کنید.';
    }
}
if($success){unset($_SESSION['_snappay_last_payment']);$_SESSION['_last_order_public_token']=$token;redirect(app_url('order-success.php?token='.$token.'&payment=success'));}
$pageKey='payment';$pageTitle='نتیجه پرداخت اسنپ‌پی | '.setting('store_name');$robots='noindex,nofollow';require BASE_PATH.'/app/views/header.php';
?><main><div class="payment-result <?=$pending?'':'error'?>"><h1><?=$pending?'پرداخت در حال بررسی است':'پرداخت کامل نشد'?></h1><p><?=e($message)?></p><?php if($pending):?><p>تا مشخص‌شدن نتیجه، پرداخت جدیدی انجام ندهید.</p><a class="btn btn-primary" href="<?=e(app_url('snappay-callback.php?paymentToken='.rawurlencode($paymentToken)))?>">بررسی دوباره پرداخت</a><?php else:?><p>سفارش شما حذف نشده و در صورت فعال‌بودن مهلت پرداخت می‌توانید دوباره تلاش کنید.</p><a class="btn btn-primary" href="<?=e(app_url('snappay-start.php?token='.$token))?>">پرداخت دوباره با اسنپ‌پی</a><?php endif?><a class="btn btn-secondary" href="<?=e(app_url('order-success.php?token='.$token))?>">مشاهده سفارش</a></div></main><?php require BASE_PATH.'/app/views/footer.php';
