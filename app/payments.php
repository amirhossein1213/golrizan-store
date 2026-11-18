<?php
declare(strict_types=1);

function payment_gateway_name(): string { return 'zibal'; }
function payment_gateway_mode(): string { return (string)setting('payment_gateway_mode','sandbox') === 'live' ? 'live' : 'sandbox'; }
function payment_gateway_merchant_id(): string
{
    if (payment_gateway_mode() === 'sandbox') return 'zibal';
    $merchant = trim(reveal_secret((string)setting('payment_merchant_id_enc','')));
    return $merchant;
}
function payment_manual_enabled(): bool { return (string)setting('payment_manual_enabled','1') === '1'; }
function payment_gateway_enabled(): bool
{
    return (string)setting('payment_gateway_enabled','0') === '1'
        && payment_gateway_merchant_id() !== '';
}
function payment_method_label(string $method): string
{
    return [
        'coordination'=>'ثبت سفارش و هماهنگی پرداخت',
        'gateway'=>(string)setting('payment_gateway_title','پرداخت آنلاین زیبال'),
        'torobpay'=>function_exists('torobpay_title') ? torobpay_title() : 'پرداخت اعتباری ترب‌پی',
        'snappay'=>function_exists('snappay_title') ? snappay_title() : 'پرداخت اقساطی اسنپ‌پی',
    ][$method] ?? $method;
}

function payment_toman_to_rial(int $amount): int
{
    if ($amount < 1 || $amount > intdiv(PHP_INT_MAX, 10)) throw new RuntimeException('مبلغ سفارش برای ارسال به درگاه معتبر نیست.');
    return $amount * 10;
}

function payment_http_json(string $url, array $payload): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($body)) throw new RuntimeException('ساخت درخواست درگاه انجام نشد.');
    $curl = curl_init($url);
    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>25,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $http = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($raw)) throw new RuntimeException('ارتباط با زیبال برقرار نشد: '.($error?:'خطای شبکه'));
    $json=json_decode($raw,true);
    if (!is_array($json)) throw new RuntimeException('پاسخ زیبال قابل‌خواندن نیست. کد HTTP '.$http);
    $json['_http']=$http;
    return $json;
}

function zibal_api_url(string $action): string { return 'https://gateway.zibal.ir/v1/'.rawurlencode($action); }
function zibal_start_url(string|int $trackId): string { return 'https://gateway.zibal.ir/start/'.rawurlencode((string)$trackId); }

function zibal_result_message(int $result): string
{
    return [
        100=>'عملیات با موفقیت انجام شد.',
        102=>'مرچنت پیدا نشد.',
        103=>'مرچنت غیرفعال است.',
        104=>'مرچنت نامعتبر است.',
        105=>'مبلغ باید بزرگ‌تر از ۱٬۰۰۰ ریال باشد.',
        106=>'آدرس بازگشت نامعتبر است.',
        113=>'مبلغ تراکنش از سقف مجاز بیشتر است.',
        201=>'تراکنش قبلاً تأیید شده است.',
        202=>'تراکنش پرداخت‌نشده یا ناموفق است.',
        203=>'شناسه تراکنش نامعتبر است.',
    ][$result] ?? ('خطای زیبال با کد '.$result);
}

function payment_latest_transaction(int $orderId, ?string $status=null): ?array
{
    $sql='SELECT * FROM payment_transactions WHERE order_id=? AND gateway=\'zibal\'';$params=[$orderId];
    if ($status!==null){$sql.=' AND status=?';$params[]=$status;}$sql.=' ORDER BY id DESC LIMIT 1';
    $stmt=db()->prepare($sql);$stmt->execute($params);return $stmt->fetch()?:null;
}

function payment_find_order_by_track_id(string $trackId): ?array
{
    if (!preg_match('/^\d{5,30}$/', $trackId)) return null;
    $stmt=db()->prepare("SELECT o.* FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.gateway='zibal' AND pt.authority=? ORDER BY pt.id DESC LIMIT 1");
    $stmt->execute([$trackId]);
    return $stmt->fetch() ?: null;
}

function payment_start_for_order(array $order): string
{
    if((int)($order['total']??0)>0)release_expired_unpaid_orders(25);
    $pdo=db();$pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$lock->execute([(int)$order['id']]);$locked=$lock->fetch();
        if(!$locked)throw new RuntimeException('سفارش پیدا نشد.');
        if ((string)$locked['payment_status']==='paid'){$pdo->commit();return app_url('order-success.php?token='.$locked['public_token']);}
        if ((int)$locked['total']<1){
            $oldOrderStatus=(string)$locked['order_status'];$oldPaymentStatus=(string)$locked['payment_status'];
            $zeroOrderStatus=in_array($oldOrderStatus,['new','pending_payment'],true)?'reviewing':$oldOrderStatus;
            accounting_ensure_order_sale($pdo,(int)$locked['id']);
            $pdo->prepare("UPDATE payment_transactions SET status='cancelled' WHERE order_id=? AND gateway='zibal' AND status IN ('created','requested')")->execute([(int)$locked['id']]);
            $pdo->prepare("UPDATE orders SET payment_method='coordination',payment_status='paid',order_status=?,paid_at=COALESCE(paid_at,NOW()),gateway_reference=NULL WHERE id=?")->execute([$zeroOrderStatus,(int)$locked['id']]);
            if($oldOrderStatus!==$zeroOrderStatus||$oldPaymentStatus!=='paid')$pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')->execute([(int)$locked['id'],$oldOrderStatus,$zeroOrderStatus,$oldPaymentStatus,'paid','سفارش صفر تومان؛ بدون نیاز به درگاه پرداخت']);
            $pdo->commit();
            sms_notify_after_payment_completed((int)$locked['id']);sms_notify_payment_status((int)$locked['id'],'paid');admin_notify_payment_success((int)$locked['id']);
            return app_url('order-success.php?token='.$locked['public_token']);
        }
        if (!payment_gateway_enabled()) throw new RuntimeException('درگاه زیبال از پنل مدیریت فعال نشده است.');
        if (!in_array((string)$locked['order_status'],['new','pending_payment'],true)) throw new RuntimeException('این سفارش دیگر قابل پرداخت نیست. وضعیت سفارش را بررسی کنید.');
        if (!in_array((string)$locked['payment_status'],['unpaid','failed','pending'],true)) throw new RuntimeException('وضعیت پرداخت این سفارش اجازه شروع تراکنش جدید نمی‌دهد.');
        $recent=$pdo->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='zibal' AND status IN ('created','requested') ORDER BY id DESC LIMIT 1 FOR UPDATE");$recent->execute([(int)$locked['id']]);$existing=$recent->fetch();
        if($existing){
            $createdTs=strtotime((string)$existing['created_at']);$age=$createdTs>0?max(0,time()-$createdTs):PHP_INT_MAX;
            $reuseMinutes=max(5,min(120,(int)setting('payment_reservation_minutes','30')));$reuseSeconds=$reuseMinutes*60;
            if((string)$existing['status']==='requested'&&$age<$reuseSeconds&&preg_match('/^\d{5,30}$/',(string)$existing['authority'])){$pdo->commit();return zibal_start_url((string)$existing['authority']);}
            if((string)$existing['status']==='created'&&$age<90)throw new RuntimeException('درخواست پرداخت دیگری در حال ایجاد است؛ چند لحظه بعد دوباره تلاش کنید.');
            $pdo->prepare("UPDATE payment_transactions SET status='cancelled',response_json=COALESCE(response_json,?) WHERE id=? AND status IN ('created','requested')")->execute([json_encode(['reason'=>'expired_before_retry','expired_at'=>date('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$existing['id']]);
        }
        $order=$locked;
        $callback=app_url('payment-callback.php?token='.rawurlencode((string)$order['public_token']));
        $amountRial=payment_toman_to_rial((int)$order['total']);
        $payload=[
            'merchant'=>payment_gateway_merchant_id(),
            'amount'=>$amountRial,
            'callbackUrl'=>$callback,
            'orderId'=>(string)$order['order_number'],
            'mobile'=>(string)$order['phone'],
            'description'=>'پرداخت سفارش '.(string)$order['order_number'].' فروشگاه '.(string)setting('store_name','لوازم خانگی گلریزان'),
        ];
        $pdo->prepare("INSERT INTO payment_transactions(order_id,gateway,amount,status,request_json) VALUES(?, 'zibal', ?, 'created', ?)")
            ->execute([(int)$order['id'],(int)$order['total'],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $transactionId=(int)$pdo->lastInsertId();
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    try {
        $response=payment_http_json(zibal_api_url('request'),$payload);
        $result=(int)($response['result']??0);
        $trackId=trim((string)($response['trackId']??''));
        if ($result!==100 || !preg_match('/^\d{5,30}$/',$trackId)) throw new RuntimeException(zibal_result_message($result));
        $pdo->prepare("UPDATE payment_transactions SET authority=?,status='requested',response_json=? WHERE id=?")
            ->execute([$trackId,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$transactionId]);
        $pdo->prepare("UPDATE orders SET payment_status='pending',gateway_reference=?,order_status=IF(order_status IN ('new','pending_payment'),'pending_payment',order_status) WHERE id=? AND payment_status<>'paid'")
            ->execute([$trackId,(int)$order['id']]);
        return zibal_start_url($trackId);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE payment_transactions SET status='failed',response_json=? WHERE id=?")
            ->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),$transactionId]);
        $pdo->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND payment_status<>'paid'")->execute([(int)$order['id']]);
        admin_notify_gateway_failure((int)$order['id'],$e->getMessage(),'request-'.$transactionId);
        throw $e;
    }
}

function payment_verify_order(array $order, string $trackId): array
{
    if (!preg_match('/^\d{5,30}$/',$trackId)) throw new RuntimeException('شناسه تراکنش زیبال معتبر نیست.');
    $pre=db()->prepare("SELECT pt.amount,pt.status,o.total,o.payment_status,o.gateway_reference FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.order_id=? AND pt.gateway='zibal' AND pt.authority=? ORDER BY pt.id DESC LIMIT 1");
    $pre->execute([(int)$order['id'],$trackId]);$preRow=$pre->fetch();
    if(!$preRow)throw new RuntimeException('تراکنش مربوط به سفارش پیدا نشد.');
    if((int)$preRow['amount']!==(int)$preRow['total'])throw new RuntimeException('مبلغ تراکنش با سفارش تطابق ندارد.');
    if((string)$preRow['payment_status']==='paid'){
        // Callback تکراری فرصت بازیابی پیامک‌های ناموفق قبلی است؛ ثبت رویداد مانع ارسال تکراری موفق می‌شود.
        sms_notify_after_payment_completed((int)$order['id']);
        sms_notify_payment_status((int)$order['id'],'paid');
        return ['ok'=>true,'already'=>true,'reference'=>(string)$preRow['gateway_reference']];
    }

    // تماس شبکه بیرون از تراکنش دیتابیس انجام می‌شود تا سفارش در زمان انتظار API قفل نماند.
    $response=payment_http_json(zibal_api_url('verify'),['merchant'=>payment_gateway_merchant_id(),'trackId'=>(int)$trackId]);
    $result=(int)($response['result']??0);
    if(!in_array($result,[100,201],true))throw new RuntimeException(zibal_result_message($result));
    if(isset($response['amount']) && is_numeric($response['amount']) && (int)$response['amount']!==payment_toman_to_rial((int)$preRow['total']))throw new RuntimeException('مبلغ تأییدشده زیبال با مبلغ سفارش یکسان نیست.');

    $pdo=db();$pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$lock->execute([(int)$order['id']]);$locked=$lock->fetch();if(!$locked)throw new RuntimeException('سفارش پیدا نشد.');
        $tx=$pdo->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='zibal' AND authority=? ORDER BY id DESC LIMIT 1 FOR UPDATE");$tx->execute([(int)$order['id'],$trackId]);$transaction=$tx->fetch();if(!$transaction)throw new RuntimeException('تراکنش مربوط به سفارش پیدا نشد.');
        if((int)$transaction['amount']!==(int)$locked['total'])throw new RuntimeException('مبلغ تراکنش با سفارش تطابق ندارد.');
        if((string)$locked['payment_status']==='paid'||(string)$transaction['status']==='paid'){
            $pdo->commit();
            sms_notify_after_payment_completed((int)$locked['id']);
            sms_notify_payment_status((int)$locked['id'],'paid');
            return ['ok'=>true,'already'=>true,'reference'=>(string)($transaction['reference_id']?:$locked['gateway_reference'])];
        }
        $ref=trim((string)($response['refNumber']??$response['refnumber']??''));if($ref==='')$ref=$trackId;
        $card=trim((string)($response['cardNumber']??$response['cardPan']??''));$fee=isset($response['wage'])&&is_numeric($response['wage'])?(int)$response['wage']:null;
        $pdo->prepare("UPDATE payment_transactions SET status='paid',reference_id=?,card_pan=?,fee=?,response_json=?,verified_at=NOW() WHERE id=?")->execute([$ref,$card?:null,$fee,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$transaction['id']]);
        $oldStatus=(string)$locked['order_status'];$newStatus=in_array($oldStatus,['new','pending_payment'],true)?'reviewing':$oldStatus;
        if($oldStatus==='cancelled')accounting_cancelled_order_late_payment($pdo,(int)$locked['id'],(int)$locked['total']);
        else{accounting_ensure_order_sale($pdo,(int)$locked['id']);accounting_order_paid($pdo,(int)$locked['id'],(int)$locked['total']);}
        $pdo->prepare("UPDATE orders SET payment_status='paid',order_status=?,gateway_reference=?,paid_at=COALESCE(paid_at,NOW()) WHERE id=?")->execute([$newStatus,$ref,(int)$locked['id']]);
        $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')->execute([(int)$locked['id'],$oldStatus,$newStatus,$locked['payment_status'],'paid','تأیید خودکار پرداخت زیبال']);
        $pdo->commit();
        admin_notify_payment_success((int)$locked['id']);
        if($oldStatus==='cancelled'){
            try{create_admin_refund_case_for_order((int)$locked['id'],'پرداخت زیبال پس از لغو سفارش؛ نیازمند بازپرداخت');}catch(Throwable $refundError){log_error($refundError);}
        }
        sms_notify_after_payment_completed((int)$locked['id']);
        sms_notify_payment_status((int)$locked['id'],'paid');
        return ['ok'=>true,'already'=>$result===201,'reference'=>$ref];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function payment_mark_verification_pending(int $orderId, string $trackId, string $message): void
{
    if(!preg_match('/^\d{5,30}$/',$trackId))return;
    $payload=json_encode(['verification_pending'=>true,'error'=>$message,'checked_at'=>date('c')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    db()->prepare("UPDATE payment_transactions SET response_json=? WHERE order_id=? AND gateway='zibal' AND authority=? AND status='requested'")
        ->execute([$payload,$orderId,$trackId]);
    db()->prepare("UPDATE orders SET payment_status='pending' WHERE id=? AND gateway_reference=? AND payment_status IN ('unpaid','pending','failed')")
        ->execute([$orderId,$trackId]);
    admin_notify_gateway_failure($orderId,$message,'verify-pending-'.$trackId);
}

function payment_mark_failed(int $orderId, string $trackId, string $message): void
{
    $pdo=db();
    if(!preg_match('/^\d{5,30}$/',$trackId))return;
    $pdo->prepare("UPDATE payment_transactions SET status='failed',response_json=? WHERE order_id=? AND authority=? AND status NOT IN ('paid','partially_refunded','refunded')")
        ->execute([json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE),$orderId,$trackId]);
    // شکست یک تلاش قدیمی نباید پرداخت جدید همان سفارش را ناموفق کند.
    $stmt=$pdo->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND gateway_reference=? AND payment_status IN ('unpaid','pending','failed')");
    $stmt->execute([$orderId,$trackId]);
    if($stmt->rowCount()>0){sms_notify_payment_status($orderId,'failed');admin_notify_gateway_failure($orderId,$message,'track-'.$trackId);}
}

function zibal_test_connection(string $merchant): array
{
    $merchant=trim($merchant);
    if($merchant==='')throw new RuntimeException('مرچنت زیبال وارد نشده است.');
    $payload=[
        'merchant'=>$merchant,
        'amount'=>10000,
        'callbackUrl'=>app_url('payment-callback.php?test=1'),
        'orderId'=>'TEST-'.date('YmdHis'),
        'mobile'=>'09120000000',
        'description'=>'آزمایش اتصال فروشگاه گلریزان',
    ];
    $response=payment_http_json(zibal_api_url('request'),$payload);
    $result=(int)($response['result']??0);
    if($result!==100)throw new RuntimeException(zibal_result_message($result));
    return ['track_id'=>(string)($response['trackId']??''),'message'=>(string)($response['message']??'ارتباط موفق')];
}
