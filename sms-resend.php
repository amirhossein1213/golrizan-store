<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
if(!is_post()){http_response_code(405);header('Allow: POST');exit('Method Not Allowed');}
$returnTo=safe_local_redirect(scalar_string($_POST['return_to']??''))??app_url();
try{
    verify_csrf();
    $eventKey=scalar_string($_POST['event_key']??'');
    if(!preg_match('/^(order_created|payment_status_paid|order_status_[a-z0-9_-]{1,50})$/',$eventKey))throw new RuntimeException('نوع پیامک برای ارسال مجدد معتبر نیست.');

    $order=null;
    $token=strtolower(trim(scalar_string($_POST['token']??'')));
    $customerOrderId=max(0,scalar_int($_POST['customer_order_id']??0));
    $trackOrder=trim(scalar_string($_POST['track_order']??''));
    $trackPhone=normalize_phone(scalar_string($_POST['track_phone']??''));
    if(preg_match('/^[a-f0-9]{64}$/',$token)){
        $stmt=db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');$stmt->execute([$token]);$order=$stmt->fetch()?:null;
    }elseif($customerOrderId>0&&customer_logged_in()){
        $customer=customer_user();$stmt=db()->prepare('SELECT * FROM orders WHERE id=? AND customer_id=? LIMIT 1');$stmt->execute([$customerOrderId,(int)$customer['id']]);$order=$stmt->fetch()?:null;
    }elseif(preg_match('/^[A-Za-z0-9-]{5,50}$/',$trackOrder)&&preg_match('/^09\d{9}$/',$trackPhone)){
        $stmt=db()->prepare('SELECT * FROM orders WHERE order_number=? AND phone=? LIMIT 1');$stmt->execute([$trackOrder,$trackPhone]);$order=$stmt->fetch()?:null;
    }
    if(!$order)throw new RuntimeException('سفارش برای ارسال مجدد پیدا نشد.');
    if(!sms_order_event_can_be_resent($eventKey,$order))throw new RuntimeException('این پیامک مربوط به وضعیت فعلی سفارش نیست.');

    $logStmt=db()->prepare("SELECT delivery_state,delivery_status,status FROM sms_logs WHERE reference_type='order' AND reference_id=? AND event_key=? ORDER BY id DESC LIMIT 1");
    $logStmt->execute([(int)$order['id'],$eventKey]);$lastLog=$logStmt->fetch();
    if(!$lastLog||((int)($lastLog['delivery_state']??0)!==7&&(string)($lastLog['delivery_status']??'')!=='blacklisted')){
        throw new RuntimeException('ارسال مجدد از این دکمه فقط برای پیامکی است که اپراتور آن را به‌دلیل محدودیت دریافت رد کرده است.');
    }

    $ipLimitKey='sms-resend-ip:'.client_ip();
    $orderLimitKey='sms-resend-order:'.(int)$order['id'].':'.$eventKey;
    $retryAfter=max(rate_limit_retry_after($ipLimitKey,8,600),rate_limit_retry_after($orderLimitKey,2,600));
    if($retryAfter>0)throw new RuntimeException('ارسال مجدد تا '.fa_number($retryAfter).' ثانیه دیگر امکان‌پذیر است.');
    rate_limit_record($ipLimitKey,600);rate_limit_record($orderLimitKey,600);
    $result=sms_resend_order_event((int)$order['id'],$eventKey);
    if(!empty($result['ok'])&&empty($result['skipped']))flash('success','پیامک دوباره ارسال شد. نتیجه نهایی تحویل ممکن است با کمی تأخیر ثبت شود.');
    else throw new RuntimeException(trim((string)($result['message']??''))?:'ارسال مجدد پیامک انجام نشد.');
}catch(Throwable $e){flash('error',public_exception_message($e,'ارسال مجدد پیامک انجام نشد. دوباره تلاش کنید.'));}
redirect($returnTo);
