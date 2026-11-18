<?php
declare(strict_types=1);

function shipment_status_labels(): array
{
    return [
        'pending'=>'در انتظار آماده‌سازی',
        'ready'=>'آماده تحویل',
        'handed_over'=>'تحویل شرکت حمل',
        'in_transit'=>'در مسیر',
        'delivered'=>'تحویل مشتری',
        'returned'=>'برگشت‌خورده',
        'cancelled'=>'لغوشده',
    ];
}

function shipment_transition_allowed(string $from,string $to): bool
{
    if($from===$to)return true;
    $map=[
        'pending'=>['ready','cancelled'],
        'ready'=>['handed_over','cancelled'],
        'handed_over'=>['in_transit','delivered','returned'],
        'in_transit'=>['delivered','returned'],
        'delivered'=>[],
        'returned'=>[],
        'cancelled'=>[],
    ];
    return in_array($to,$map[$from]??[],true);
}

function shipment_allowed_order_statuses(string $shipmentStatus): array
{
    return match($shipmentStatus){
        'pending'=>['confirmed','preparing','ready_to_ship'],
        'ready'=>['preparing','ready_to_ship'],
        'handed_over'=>['ready_to_ship','handed_to_carrier'],
        'in_transit'=>['ready_to_ship','handed_to_carrier','shipped'],
        'delivered'=>['handed_to_carrier','shipped','delivered'],
        'returned'=>['shipped','delivered','return_requested','return_approved','returned'],
        'cancelled'=>order_status_options() ? array_keys(order_status_options()) : [],
        default=>[],
    };
}

function shipment_target_order_status(string $shipmentStatus): ?string
{
    return match($shipmentStatus){
        'ready'=>'ready_to_ship',
        'handed_over'=>'handed_to_carrier',
        'in_transit'=>'shipped',
        'delivered'=>'delivered',
        default=>null,
    };
}

function shipment_number(): string
{
    return 'SHP-'.jalali_compact_date().'-'.strtoupper(bin2hex(random_bytes(4)));
}

function shipment_tracking_url(?string $template,string $code): string
{
    $template=trim((string)$template);
    $code=trim($code);
    if($template===''||$code===''||!str_contains($template,'{code}'))return '';
    $url=str_replace('{code}',rawurlencode($code),$template);
    return filter_var($url,FILTER_VALIDATE_URL)?$url:'';
}

function shipment_for_order(int $orderId): ?array
{
    $stmt=db()->prepare('SELECT s.*,c.name carrier_name,c.tracking_url_template FROM shipments s LEFT JOIN shipping_carriers c ON c.id=s.carrier_id WHERE s.order_id=?');
    $stmt->execute([$orderId]);
    return $stmt->fetch()?:null;
}

function admin_mark_order_shipped(int $orderId,string $trackingCode,?int $carrierId=null,string $note='ثبت ارسال سفارش',bool $sendSms=true): array
{
    $trackingCode=text_substr(trim($trackingCode),0,150);
    if($trackingCode==='')throw new RuntimeException('برای ثبت ارسال، کد رهگیری الزامی است.');
    $stmt=db()->prepare('SELECT id,order_status,payment_status FROM orders WHERE id=? LIMIT 1');
    $stmt->execute([$orderId]);$order=$stmt->fetch();
    if(!$order)throw new RuntimeException('سفارش پیدا نشد.');
    if(!in_array((string)$order['payment_status'],['paid','partially_refunded'],true))throw new RuntimeException('تا تکمیل پرداخت، ثبت ارسال سفارش مجاز نیست.');
    $allowed=['confirmed','preparing','ready_to_ship','handed_to_carrier','shipped'];
    if(!in_array((string)$order['order_status'],$allowed,true))throw new RuntimeException('ابتدا سفارش را تأیید کنید تا امکان ثبت ارسال فعال شود.');

    if(in_array((string)$order['order_status'],['confirmed','preparing'],true)){
        admin_advance_order_to_status($orderId,'ready_to_ship',$note.'؛ آماده‌سازی خودکار پیش از ارسال',false);
    }

    $shipment=shipment_for_order($orderId);
    if($carrierId===null&&!empty($shipment['carrier_id'])){
        $carrierCheck=db()->prepare('SELECT id FROM shipping_carriers WHERE id=? AND is_active=1 LIMIT 1');
        $carrierCheck->execute([(int)$shipment['carrier_id']]);
        if($carrierCheck->fetchColumn())$carrierId=(int)$shipment['carrier_id'];
    }
    $packages=max(1,(int)($shipment['package_count']??1));
    $weight=max(0,(int)($shipment['weight_grams']??0));
    $cost=max(0,(int)($shipment['shipping_cost']??0));
    $status=(string)($shipment['status']??'pending');
    if($status==='pending'){
        save_shipment($orderId,$carrierId,$trackingCode,'ready',$packages,$weight,$cost,$note,'آماده تحویل برای ارسال','',false);
        $shipment=shipment_for_order($orderId);$status=(string)($shipment['status']??'ready');
    }
    if($status==='ready'){
        save_shipment($orderId,$carrierId,$trackingCode,'handed_over',$packages,$weight,$cost,$note,'مرسوله تحویل بخش حمل شد','',false);
        $shipment=shipment_for_order($orderId);$status=(string)($shipment['status']??'handed_over');
    }
    if($status==='handed_over'){
        return save_shipment($orderId,$carrierId,$trackingCode,'in_transit',$packages,$weight,$cost,$note,'سفارش ارسال شد و کد رهگیری ثبت گردید','',$sendSms);
    }
    if($status==='in_transit'){
        return save_shipment($orderId,$carrierId,$trackingCode,'in_transit',$packages,$weight,$cost,$note,'کد رهگیری ارسال اصلاح شد','',$sendSms);
    }
    throw new RuntimeException('پرونده ارسال در وضعیت بسته است و امکان ثبت یا اصلاح ارسال وجود ندارد.');
}

function save_shipment(int $orderId,?int $carrierId,string $trackingCode,string $status,int $packages,int $weight,int $cost,string $note,string $eventText,string $location,bool $sendSms=true): array
{
    if(!isset(shipment_status_labels()[$status]))throw new RuntimeException('وضعیت ارسال معتبر نیست.');
    $trackingCode=text_substr(trim($trackingCode),0,150);
    if(in_array($status,['handed_over','in_transit','delivered','returned'],true)&&!$carrierId&&$trackingCode==='')throw new RuntimeException('برای این وضعیت باید شرکت حمل یا کد رهگیری را مشخص کنید.');
    $note=text_substr(trim($note),0,3000);
    $eventText=text_substr(trim($eventText),0,500);
    $location=text_substr(trim($location),0,180);
    $packages=max(1,min(100,$packages));
    $weight=max(0,min(100000000,$weight));
    $cost=max(0,min(MAX_MONEY_AMOUNT,$cost));

    $pdo=db();
    $pdo->beginTransaction();
    try{
        $o=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $o->execute([$orderId]);
        $order=$o->fetch();
        if(!$order)throw new RuntimeException('سفارش پیدا نشد.');
        if($carrierId){
            $c=$pdo->prepare('SELECT id FROM shipping_carriers WHERE id=? AND is_active=1');
            $c->execute([$carrierId]);
            if(!$c->fetchColumn())throw new RuntimeException('شرکت حمل معتبر نیست.');
        }

        $s=$pdo->prepare('SELECT * FROM shipments WHERE order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $shipment=$s->fetch();
        $oldStatus=$shipment?(string)$shipment['status']:'pending';
        $oldTracking=$shipment?(string)($shipment['tracking_code']??''):'';
        $oldCarrier=$shipment?(int)($shipment['carrier_id']??0):0;
        if(!shipment_transition_allowed($oldStatus,$status))throw new RuntimeException('تغییر وضعیت انتخاب‌شده برای این ارسال مجاز نیست.');
        if($status==='cancelled'&&!$shipment)throw new RuntimeException('پرونده ارسال ثبت‌نشده را نمی‌توان لغو کرد.');
        if($status!=='cancelled'&&!in_array((string)$order['payment_status'],['paid','partially_refunded'],true))throw new RuntimeException('تا تکمیل پرداخت، ایجاد یا تغییر پرونده ارسال مجاز نیست.');
        $allowedOrderStatuses=shipment_allowed_order_statuses($status);
        if(!in_array((string)$order['order_status'],$allowedOrderStatuses,true))throw new RuntimeException('وضعیت فعلی سفارش با وضعیت انتخاب‌شده برای ارسال سازگار نیست. ابتدا وضعیت سفارش را اصلاح کنید.');
        $targetOrderStatus=shipment_target_order_status($status);
        if($targetOrderStatus!==null&&$targetOrderStatus!==(string)$order['order_status']&&!order_status_transition_allowed((string)$order['order_status'],$targetOrderStatus))throw new RuntimeException('همگام‌سازی وضعیت سفارش با پرونده ارسال مجاز نیست. ابتدا مراحل قبلی سفارش را تکمیل کنید.');

        $destination=trim((string)$order['province'].'، '.(string)$order['city'].'، '.(string)$order['address'].'، کدپستی '.(string)$order['postal_code']);
        $admin=admin_user();$adminId=is_array($admin)&&!empty($admin['id'])?(int)$admin['id']:null;
        if($shipment){
            $pdo->prepare("UPDATE shipments SET carrier_id=?,tracking_code=?,status=?,package_count=?,weight_grams=?,shipping_cost=?,note=?,dispatched_at=IF(? IN ('handed_over','in_transit'),COALESCE(dispatched_at,NOW()),dispatched_at),delivered_at=IF(?='delivered',COALESCE(delivered_at,NOW()),delivered_at),updated_by=? WHERE id=?")
                ->execute([$carrierId,$trackingCode?:null,$status,$packages,$weight,$cost,$note?:null,$status,$status,$adminId,(int)$shipment['id']]);
            $shipmentId=(int)$shipment['id'];
        }else{
            $pdo->prepare("INSERT INTO shipments(order_id,carrier_id,shipment_number,tracking_code,status,package_count,weight_grams,shipping_cost,recipient_name,recipient_phone,destination,note,dispatched_at,delivered_at,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,IF(? IN ('handed_over','in_transit'),NOW(),NULL),IF(?='delivered',NOW(),NULL),?,?)")
                ->execute([$orderId,$carrierId,shipment_number(),$trackingCode?:null,$status,$packages,$weight,$cost,$order['customer_name'],$order['phone'],$destination,$note?:null,$status,$status,$adminId,$adminId]);
            $shipmentId=(int)$pdo->lastInsertId();
        }
        $event=$eventText!==''?$eventText:(shipment_status_labels()[$status]??$status);
        $pdo->prepare('INSERT INTO shipment_events(shipment_id,status,event_text,location,admin_id) VALUES(?,?,?,?,?)')->execute([$shipmentId,$status,$event,$location?:null,$adminId]);
        $oldOrderStatus=(string)$order['order_status'];
        if($targetOrderStatus!==null&&$targetOrderStatus!==$oldOrderStatus){
            if($targetOrderStatus==='delivered'){
                accounting_ensure_order_sale($pdo,$orderId);
                accounting_order_delivered($pdo,$orderId);
            }
            $sets=['order_status=?','tracking_code=?'];$params=[$targetOrderStatus,$trackingCode?:null];
            foreach(order_timestamp_updates($oldOrderStatus,$targetOrderStatus) as $column=>$expression)$sets[]="`$column`=$expression";
            $params[]=$orderId;
            $pdo->prepare('UPDATE orders SET '.implode(',',$sets).' WHERE id=?')->execute($params);
            $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,?)')->execute([$orderId,$oldOrderStatus,$targetOrderStatus,$order['payment_status'],$order['payment_status'],'همگام‌سازی خودکار و اتمیک با پرونده ارسال',$adminId]);
        }else{
            $pdo->prepare('UPDATE orders SET tracking_code=? WHERE id=?')->execute([$trackingCode?:null,$orderId]);
        }
        $pdo->commit();

        $statusChanged=!$shipment||$oldStatus!==$status;
        $trackingChanged=$oldTracking!==$trackingCode;
        $carrierChanged=$oldCarrier!==(int)($carrierId??0);
        $smsResult=null;
        // پیامک تیک‌خورده در همین درخواست و بدون وابستگی به Cron ارسال می‌شود.
        if($sendSms&&($statusChanged||$trackingChanged||$carrierChanged))$smsResult=sms_notify_shipment_status($shipmentId,$status,true,true);
        admin_audit_event('shipment_saved','shipment',$shipmentId,['status'=>$status,'tracking_code'=>$trackingCode]);
        return [
            'shipment_id'=>$shipmentId,
            'sms_requested'=>$sendSms,
            'sms'=>$smsResult,
            'changed'=>$statusChanged||$trackingChanged||$carrierChanged,
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function sms_notify_shipment_status(int $shipmentId,string $status,bool $forceAttempt=true,bool $allowResendSent=false): array
{
    if((string)setting('sms_shipment_status_enabled','0')!=='1'){
        return sms_immediate_outcome(false,false,true,'ارسال پیامک وضعیت مرسوله در تنظیمات غیرفعال است.');
    }
    $eventKey='shipment_status_'.$status;
    if(!$allowResendSent&&!sms_event_should_dispatch($eventKey,'shipment',$shipmentId,$forceAttempt)){
        return sms_immediate_outcome(false,false,true,'این وضعیت مرسوله قبلاً پیامک شده است.');
    }
    try{
        $stmt=db()->prepare('SELECT s.id,s.tracking_code,s.status,o.order_number,o.phone,o.payment_status,o.paid_at,cu.phone customer_phone,c.name carrier_name FROM shipments s JOIN orders o ON o.id=s.order_id LEFT JOIN customers cu ON cu.id=o.customer_id LEFT JOIN shipping_carriers c ON c.id=s.carrier_id WHERE s.id=?');
        $stmt->execute([$shipmentId]);
        $row=$stmt->fetch();
        if(!$row)return sms_immediate_outcome(false,false,true,'پرونده ارسال پیدا نشد.');
        if(!sms_order_payment_completed($row))return sms_immediate_outcome(false,false,true,'اطلاعات ارسال ذخیره شد؛ پیامک کد رهگیری تا تکمیل پرداخت مشتری ارسال نمی‌شود.');
        $result=smsir_send_verify(sms_order_recipient_phone($row),sms_template_id('sms_shipment_status_template_id'),[
            sms_param_name('sms_shipment_param_order','Order')=>$row['order_number'],
            sms_param_name('sms_shipment_param_status','Status')=>shipment_status_labels()[$status]??$status,
            sms_param_name('sms_shipment_param_code','Code')=>$row['tracking_code']?:'—',
            sms_param_name('sms_shipment_param_carrier','Carrier')=>$row['carrier_name']?:'فروشگاه',
        ],$eventKey,'shipment',(int)$row['id']);
        return sms_immediate_outcome(true,!empty($result['ok']),!empty($result['skipped']),(string)($result['message']??''),$result);
    }catch(Throwable $e){
        log_error($e);
        return sms_immediate_outcome(true,false,false,$e->getMessage());
    }
}
