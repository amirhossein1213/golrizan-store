<?php
declare(strict_types=1);

function order_status_options(): array
{
    return [
        'new'=>'جدید',
        'pending_payment'=>'در انتظار پرداخت',
        'reviewing'=>'در حال بررسی',
        'sourcing'=>'در انتظار تأمین',
        'confirmed'=>'تأییدشده',
        'preparing'=>'در حال آماده‌سازی',
        'ready_to_ship'=>'آماده ارسال',
        'handed_to_carrier'=>'تحویل حمل‌کننده',
        'shipped'=>'ارسال‌شده',
        'delivered'=>'تحویل مشتری',
        'return_requested'=>'درخواست مرجوعی',
        'return_approved'=>'مرجوعی تأییدشده',
        'returned'=>'کالا مرجوع شد',
        'cancelled'=>'لغوشده',
        'refunded'=>'بازپرداخت‌شده',
    ];
}
function payment_status_options(): array
{
    return [
        'unpaid'=>'پرداخت‌نشده',
        'pending'=>'در انتظار تأیید درگاه',
        'paid'=>'پرداخت‌شده',
        'failed'=>'ناموفق',
        'partially_refunded'=>'بازپرداخت جزئی',
        'refunded'=>'بازپرداخت کامل',
    ];
}
function order_status_label(string $status): string { return order_status_options()[$status] ?? ($status==='installed'?'تحویل‌شده (وضعیت قدیمی)':$status); }
function payment_status_label(string $status): string { return payment_status_options()[$status] ?? $status; }
function order_payment_status_label(array $order): string
{
    $orderStatus=(string)($order['order_status']??'');
    $paymentStatus=(string)($order['payment_status']??'');
    if($orderStatus==='cancelled'&&$paymentStatus==='paid')return 'پرداخت انجام شده؛ بازپرداخت لازم است';
    if($orderStatus==='cancelled'&&in_array($paymentStatus,['unpaid','pending','failed'],true))return 'پرداخت لغوشده / ناموفق';
    return payment_status_label($paymentStatus);
}

/** @return array<int,array<string,mixed>> */
function order_item_options(array $item): array
{
    $raw = $item['variant_options_json'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_values(array_filter($decoded, 'is_array'));
    }
    return [];
}

function order_item_option_label(array $item): string
{
    $labels = [];
    foreach (order_item_options($item) as $option) {
        $attribute = trim((string)($option['attribute_name'] ?? ''));
        $value = trim((string)($option['value'] ?? ''));
        if ($value === '') continue;
        $label = $attribute !== '' ? $attribute.': '.$value : $value;
        $labels[$label] = $label;
    }
    if ($labels) return implode('، ', array_values($labels));
    return trim((string)($item['variant_title'] ?? ''));
}

function order_status_requires_tracking(string $status): bool { return $status==='shipped'; }

function order_status_transition_allowed(string $from, string $to): bool
{
    if($from===$to)return true;
    $map=[
        'new'=>['pending_payment','reviewing','sourcing','confirmed','cancelled'],
        'pending_payment'=>['reviewing','cancelled'],
        'reviewing'=>['sourcing','confirmed','cancelled'],
        'sourcing'=>['confirmed','cancelled'],
        'confirmed'=>['preparing','cancelled'],
        'preparing'=>['ready_to_ship','cancelled'],
        'ready_to_ship'=>['handed_to_carrier','shipped','cancelled'],
        'handed_to_carrier'=>['shipped','delivered'],
        'shipped'=>['delivered'],
        'delivered'=>['return_requested'],
        'installed'=>['return_requested'],
        'return_requested'=>['return_approved','delivered'],
        'return_approved'=>['returned'],
        'returned'=>['refunded'],
        'cancelled'=>['refunded'],
        'refunded'=>[],
    ];
    return in_array($to,$map[$from]??[],true);
}
function payment_status_transition_allowed(string $from,string $to): bool
{
    if($from===$to)return true;
    $map=[
        'unpaid'=>['pending','paid','failed'],
        'pending'=>['paid','failed'],
        'failed'=>['pending','paid'],
        'paid'=>['partially_refunded','refunded'],
        'partially_refunded'=>['refunded'],
        'refunded'=>[],
    ];
    return in_array($to,$map[$from]??[],true);
}

function restore_order_stock(PDO $pdo,int $orderId): void
{
    $lock=$pdo->prepare('SELECT stock_restored FROM orders WHERE id=? FOR UPDATE');$lock->execute([$orderId]);$restored=$lock->fetchColumn();
    if($restored===false)throw new RuntimeException('سفارش پیدا نشد.');
    if((int)$restored===1)return;
    $items=$pdo->prepare('SELECT product_id,variant_id,quantity FROM order_items WHERE order_id=?');$items->execute([$orderId]);$productIds=[];
    $productUpdate=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');
    $variantUpdate=$pdo->prepare('UPDATE product_variants SET stock=stock+? WHERE id=? AND product_id=?');
    $admin= function_exists('admin_user') ? admin_user() : null;$actorId=is_array($admin)&&!empty($admin['id'])?(int)$admin['id']:null;
    foreach($items->fetchAll() as $item){
        $productId=(int)$item['product_id'];$quantity=(int)$item['quantity'];$variantId=(int)($item['variant_id']??0);
        if($variantId>0){$variantUpdate->execute([$quantity,$variantId,$productId]);$productIds[$productId]=true;inventory_record_existing_change($pdo,$productId,$variantId,'sale_cancel',$quantity,0,'order',$orderId,'بازگشت موجودی سفارش لغوشده',$actorId);}
        else{$productUpdate->execute([$quantity,$productId]);inventory_record_existing_change($pdo,$productId,null,'sale_cancel',$quantity,0,'order',$orderId,'بازگشت موجودی سفارش لغوشده',$actorId);}
    }
    foreach(array_keys($productIds) as $productId)sync_product_variant_summary($pdo,(int)$productId);
    $pdo->prepare('UPDATE orders SET stock_restored=1 WHERE id=?')->execute([$orderId]);
}

function order_reservation_minutes(string $method): int
{
    return $method==='coordination'
        ? max(30,min(10080,(int)setting('manual_payment_reservation_minutes','1440')))
        : max(5,min(1440,(int)setting('payment_reservation_minutes','30')));
}

function order_reservation_expired(array $order): bool
{
    $method=(string)($order['payment_method']??'');if(!in_array($method,['gateway','torobpay','snappay','coordination'],true))return false;
    return strtotime((string)$order['created_at'])<=time()-(order_reservation_minutes($method)*60);
}

function release_expired_unpaid_orders(int $limit=25): int
{
    $gatewayMinutes=order_reservation_minutes('gateway');$manualMinutes=order_reservation_minutes('coordination');
    $limit=max(1,min(100,$limit));
    $stmt=db()->query("SELECT id FROM orders WHERE ((payment_method IN ('gateway','torobpay','snappay') AND created_at<DATE_SUB(NOW(),INTERVAL {$gatewayMinutes} MINUTE)) OR (payment_method='coordination' AND created_at<DATE_SUB(NOW(),INTERVAL {$manualMinutes} MINUTE))) AND payment_status IN ('unpaid','pending','failed') AND order_status IN ('new','pending_payment') AND stock_restored=0 ORDER BY id LIMIT {$limit}");
    $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));$released=0;
    foreach($ids as $orderId){
        $pdo=db();$pdo->beginTransaction();
        try{
            $lock=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$lock->execute([$orderId]);$order=$lock->fetch();
            if(!$order||!in_array((string)$order['payment_status'],['unpaid','pending','failed'],true)||!in_array((string)$order['order_status'],['new','pending_payment'],true)||(int)$order['stock_restored']===1||!order_reservation_expired($order)){$pdo->commit();continue;}
            $oldOrder=(string)$order['order_status'];$oldPayment=(string)$order['payment_status'];
            restore_order_stock($pdo,$orderId);
            accounting_order_cancelled($pdo,$orderId,(int)$order['subtotal'],(int)$order['shipping_amount'],false,(int)($order['discount_amount']??0));
            $pdo->prepare('DELETE FROM coupon_usages WHERE order_id=?')->execute([$orderId]);
            $pdo->prepare("UPDATE payment_transactions SET status='cancelled' WHERE order_id=? AND status IN ('created','requested')")->execute([$orderId]);
            $pdo->prepare("UPDATE orders SET order_status='cancelled',payment_status='failed',cancelled_at=NOW() WHERE id=?")->execute([$orderId]);
            $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')->execute([$orderId,$oldOrder,'cancelled',$oldPayment,'failed','انقضای مهلت پرداخت و آزادسازی خودکار موجودی']);
            $pdo->commit();$released++;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();log_error($e);}
    }
    return $released;
}

function release_expired_gateway_orders(int $limit=25): int
{
    return release_expired_unpaid_orders($limit);
}

function customer_cancel_unpaid_torobpay_order(string $publicToken): array
{
    $publicToken=trim($publicToken);
    if(!preg_match('/^[a-f0-9]{64}$/',$publicToken))throw new RuntimeException('سفارش معتبر نیست.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1 FOR UPDATE');$stmt->execute([$publicToken]);$order=$stmt->fetch();
        if(!$order)throw new RuntimeException('سفارش پیدا نشد.');
        if((string)$order['payment_method']!=='torobpay')throw new RuntimeException('روش پرداخت این سفارش ترب‌پی نیست.');
        if((string)$order['payment_status']==='paid')throw new RuntimeException('این سفارش پرداخت شده است و لغو آن باید از فرایند بازپرداخت انجام شود.');
        if((string)$order['order_status']==='cancelled'){
            $pdo->commit();
            return ['id'=>(int)$order['id'],'already'=>true];
        }
        if(!in_array((string)$order['order_status'],['new','pending_payment'],true)||!in_array((string)$order['payment_status'],['unpaid','pending','failed'],true)){
            throw new RuntimeException('این سفارش دیگر از صفحه پرداخت قابل لغو نیست.');
        }
        $orderId=(int)$order['id'];$oldOrder=(string)$order['order_status'];$oldPayment=(string)$order['payment_status'];
        restore_order_stock($pdo,$orderId);
        accounting_order_cancelled($pdo,$orderId,(int)$order['subtotal'],(int)$order['shipping_amount'],false,(int)($order['discount_amount']??0));
        $pdo->prepare('DELETE FROM coupon_usages WHERE order_id=?')->execute([$orderId]);
        $pdo->prepare("UPDATE payment_transactions SET status='cancelled' WHERE order_id=? AND status IN ('created','requested')")->execute([$orderId]);
        $pdo->prepare("UPDATE orders SET order_status='cancelled',payment_status='failed',cancelled_at=NOW() WHERE id=?")->execute([$orderId]);
        $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')
            ->execute([$orderId,$oldOrder,'cancelled',$oldPayment,'failed','انصراف مشتری پیش از ورود به درگاه ترب‌پی']);
        $pdo->commit();
        admin_notify_order_cancelled($orderId);
        return ['id'=>$orderId,'already'=>false];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function order_timestamp_updates(string $old,string $new): array
{
    $sets=[];
    if($old!==$new){
        if(in_array($new,['handed_to_carrier','shipped'],true))$sets['shipped_at']='NOW()';
        if($new==='delivered')$sets['delivered_at']='NOW()';
        if($new==='installed')$sets['installed_at']='NOW()';
        if($new==='cancelled')$sets['cancelled_at']='NOW()';
    }
    return $sets;
}

function admin_update_order_status(int $orderId,string $newOrderStatus,string $newPaymentStatus,string $note,bool $sendSms=true,?string $trackingCode=null): array
{
    if(!isset(order_status_options()[$newOrderStatus])||!isset(payment_status_options()[$newPaymentStatus]))throw new RuntimeException('وضعیت انتخاب‌شده معتبر نیست.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$stmt->execute([$orderId]);$old=$stmt->fetch();if(!$old)throw new RuntimeException('سفارش پیدا نشد.');
        $oldOrder=(string)$old['order_status'];$oldPayment=(string)$old['payment_status'];$oldTracking=(string)($old['tracking_code']??'');
        if($newOrderStatus==='cancelled'&&(string)$old['payment_method']==='snappay'&&in_array($oldPayment,['paid','partially_refunded'],true))throw new RuntimeException('برای سفارش پرداخت‌شده اسنپ‌پی، لغو فقط از دکمه «Cancel اسنپ‌پی» داخل صفحه سفارش مجاز است.');
        // لغو سفارش پرداخت‌نشده نباید وضعیت «در انتظار/پرداخت‌نشده» را باقی بگذارد.
        // سفارش واقعاً پرداخت‌شده تا ثبت بازپرداخت، Paid باقی می‌ماند تا سابقه مالی از بین نرود.
        if($newOrderStatus==='cancelled'&&in_array($oldPayment,['unpaid','pending','failed'],true))$newPaymentStatus='failed';
        if($trackingCode!==null)$trackingCode=text_substr(trim($trackingCode),0,120);
        $effectiveTracking=$trackingCode!==null?$trackingCode:$oldTracking;
        if(order_status_requires_tracking($newOrderStatus)&&$effectiveTracking==='')throw new RuntimeException('برای ثبت وضعیت ارسال‌شده، واردکردن کد رهگیری الزامی است.');
        if(!order_status_transition_allowed($oldOrder,$newOrderStatus))throw new RuntimeException('انتقال از «'.order_status_label($oldOrder).'» به «'.order_status_label($newOrderStatus).'» مجاز نیست.');
        if(!payment_status_transition_allowed($oldPayment,$newPaymentStatus))throw new RuntimeException('تغییر وضعیت پرداخت مجاز نیست.');
        if(in_array($newPaymentStatus,['partially_refunded','refunded'],true))throw new RuntimeException('بازپرداخت باید از بخش مرجوعی و بازپرداخت ثبت شود.');
        if($newOrderStatus==='cancelled'&&$oldPayment!=='paid'&&$newPaymentStatus==='paid')throw new RuntimeException('سفارش را هم‌زمان پرداخت‌شده و لغوشده ثبت نکنید. ابتدا وضعیت پرداخت را مشخص کنید.');
        if($oldOrder==='cancelled'&&$newPaymentStatus==='paid')throw new RuntimeException('سفارش لغوشده را نمی‌توان پرداخت‌شده کرد.');
        if($oldPayment!=='paid'&&$newPaymentStatus==='paid'&&in_array((string)$old['payment_method'],['gateway','torobpay','snappay'],true)){
            $expectedGateway=match((string)$old['payment_method']){'torobpay'=>'torobpay','snappay'=>'snappay',default=>'zibal'};
            $verified=$pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE order_id=? AND gateway=? AND status IN ('paid','partially_refunded','refunded')");$verified->execute([$orderId,$expectedGateway]);
            if((int)$verified->fetchColumn()<1)throw new RuntimeException('پرداخت آنلاین فقط پس از Verify موفق همان درگاه قابل ثبت است.');
        }
        if($newOrderStatus==='cancelled'){
            restore_order_stock($pdo,$orderId);
            accounting_order_cancelled($pdo,$orderId,(int)$old['subtotal'],(int)$old['shipping_amount'],$oldPayment==='paid'||$newPaymentStatus==='paid',(int)($old['discount_amount']??0));
            $pdo->prepare('DELETE FROM coupon_usages WHERE order_id=?')->execute([$orderId]);
            $pdo->prepare("UPDATE payment_transactions SET status='cancelled' WHERE order_id=? AND status IN ('created','requested')")->execute([$orderId]);
        }
        if($oldPayment!=='paid'&&$newPaymentStatus==='paid'){accounting_ensure_order_sale($pdo,$orderId);accounting_order_paid($pdo,$orderId,(int)$old['total']);}
        if($oldOrder!=='delivered'&&$newOrderStatus==='delivered'){accounting_ensure_order_sale($pdo,$orderId);accounting_order_delivered($pdo,$orderId);}
        $sets=['order_status=?','payment_status=?'];$params=[$newOrderStatus,$newPaymentStatus];
        if($trackingCode!==null){$sets[]='tracking_code=?';$params[]=$trackingCode?:null;}
        foreach(order_timestamp_updates($oldOrder,$newOrderStatus) as $column=>$expression)$sets[]="`$column`=$expression";
        if($newPaymentStatus==='paid'&&empty($old['paid_at']))$sets[]='paid_at=NOW()';
        $params[]=$orderId;$pdo->prepare('UPDATE orders SET '.implode(',',$sets).' WHERE id=?')->execute($params);
        if($oldOrder!==$newOrderStatus||$oldPayment!==$newPaymentStatus){
            $admin=admin_user();$actorAdminId=is_array($admin)&&!empty($admin['id'])?(int)$admin['id']:null;
            $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,?)')->execute([$orderId,$oldOrder,$newOrderStatus,$oldPayment,$newPaymentStatus,text_substr($note,0,500)?:null,$actorAdminId]);
        }
        $pdo->commit();

        $trackingChanged=$trackingCode!==null && trim($trackingCode)!==$oldTracking;
        $report=[
            'status_changed'=>$oldOrder!==$newOrderStatus,
            'payment_changed'=>$oldPayment!==$newPaymentStatus,
            'tracking_changed'=>$trackingChanged,
            'sms_requested'=>$sendSms,
            'order_sms'=>null,
            'payment_sms'=>null,
            'order_created_sms'=>null,
            'shipment_sms'=>null,
        ];
        // پیامک مشتری فقط پس از تکمیل پرداخت مجاز است؛ پیام‌های معوق همان لحظه پرداخت ارسال می‌شوند.
        $paymentJustCompleted=$oldPayment!==$newPaymentStatus&&$newPaymentStatus==='paid';
        if($sendSms&&$paymentJustCompleted){
            $deferred=sms_notify_after_payment_completed($orderId);
            $report['order_created_sms']=is_array($deferred['order_created']??null)?$deferred['order_created']:null;
            $report['order_sms']=is_array($deferred['order_status']??null)?$deferred['order_status']:null;
            $report['shipment_sms']=is_array($deferred['shipment_status']??null)?$deferred['shipment_status']:null;
        }
        if($sendSms&&!$paymentJustCompleted&&($oldOrder!==$newOrderStatus||$trackingChanged))$report['order_sms']=sms_notify_order_status($orderId,$newOrderStatus,true,true);
        if($sendSms&&$oldPayment!==$newPaymentStatus)$report['payment_sms']=sms_notify_payment_status($orderId,$newPaymentStatus,true,true);
        if($oldOrder!==$newOrderStatus&&$newOrderStatus==='cancelled')admin_notify_order_cancelled($orderId);
        if($oldPayment!==$newPaymentStatus&&$newPaymentStatus==='paid')admin_notify_payment_success($orderId);
        return $report;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function order_priority_options(): array
{
    return ['normal'=>'عادی','high'=>'مهم','urgent'=>'فوری'];
}
function order_priority_label(string $priority): string { return order_priority_options()[$priority]??'عادی'; }

function order_workflow_stage(string $status): string
{
    if(in_array($status,['new','pending_payment','reviewing','sourcing'],true))return 'new';
    if(in_array($status,['confirmed','preparing'],true))return 'processing';
    if($status==='ready_to_ship')return 'ready';
    if(in_array($status,['handed_to_carrier','shipped'],true))return 'shipping';
    if(in_array($status,['delivered','installed'],true))return 'completed';
    return 'closed';
}

function order_workflow_stages(): array
{
    return [
        'new'=>['label'=>'جدید و نیازمند بررسی','target'=>'reviewing'],
        'processing'=>['label'=>'در حال آماده‌سازی','target'=>'preparing'],
        'ready'=>['label'=>'آماده ارسال','target'=>'ready_to_ship'],
        'shipping'=>['label'=>'ارسال‌شده','target'=>'shipped'],
        'completed'=>['label'=>'تکمیل‌شده','target'=>'delivered'],
    ];
}

function order_next_statuses(string $status): array
{
    $result=[];foreach(order_status_options() as $key=>$label)if($key!==$status&&order_status_transition_allowed($status,$key))$result[$key]=$label;
    return $result;
}

function admin_order_assignable_admins(): array
{
    try{
        return db()->query("SELECT DISTINCT a.id,a.full_name,a.username FROM admins a LEFT JOIN admin_role_permissions rp ON rp.role_id=a.role_id AND rp.permission_key='manage_orders' LEFT JOIN admin_roles r ON r.id=a.role_id WHERE a.is_active=1 AND (a.role_id IS NULL OR (r.is_active=1 AND rp.permission_key='manage_orders')) ORDER BY a.full_name,a.id")->fetchAll();
    }catch(Throwable $e){log_error($e);return [];}
}

function admin_order_admin_is_assignable(?int $adminId): bool
{
    if($adminId===null)return true;if($adminId<1)return false;
    $stmt=db()->prepare("SELECT COUNT(*) FROM admins a LEFT JOIN admin_role_permissions rp ON rp.role_id=a.role_id AND rp.permission_key='manage_orders' LEFT JOIN admin_roles r ON r.id=a.role_id WHERE a.id=? AND a.is_active=1 AND (a.role_id IS NULL OR (r.is_active=1 AND rp.permission_key='manage_orders'))");$stmt->execute([$adminId]);return (int)$stmt->fetchColumn()>0;
}

function admin_update_order_meta(int $orderId,?int $assignedAdminId,string $priority,string $internalNote): array
{
    if($orderId<1)throw new RuntimeException('سفارش معتبر نیست.');
    if(!isset(order_priority_options()[$priority]))throw new RuntimeException('اولویت سفارش معتبر نیست.');
    $assignedAdminId=$assignedAdminId!==null&&$assignedAdminId>0?$assignedAdminId:null;
    if(!admin_order_admin_is_assignable($assignedAdminId))throw new RuntimeException('مدیر انتخاب‌شده اجازه مدیریت سفارش را ندارد.');
    $internalNote=text_substr(trim($internalNote),0,4000);
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT assigned_admin_id,priority,admin_internal_note FROM orders WHERE id=? FOR UPDATE');$stmt->execute([$orderId]);$old=$stmt->fetch();if(!$old)throw new RuntimeException('سفارش پیدا نشد.');
        $pdo->prepare('UPDATE orders SET assigned_admin_id=?,priority=?,admin_internal_note=? WHERE id=?')->execute([$assignedAdminId,$priority,$internalNote?:null,$orderId]);
        $assignmentChanged=(int)($old['assigned_admin_id']??0)!==(int)($assignedAdminId??0);
        $pdo->commit();
        admin_audit_event('order_meta_updated','order',$orderId,['assigned_admin_id'=>$assignedAdminId,'priority'=>$priority,'assignment_changed'=>$assignmentChanged]);
        if($assignmentChanged&&$assignedAdminId!==null)admin_notify_order_assigned($orderId,$assignedAdminId);
        return ['assignment_changed'=>$assignmentChanged];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function order_status_transition_path(string $from,string $to): array
{
    if($from===$to)return [];
    if(!isset(order_status_options()[$from])||!isset(order_status_options()[$to]))return [];
    $queue=[[$from,[]]];$visited=[$from=>true];
    while($queue){[$current,$path]=array_shift($queue);foreach(array_keys(order_next_statuses($current)) as $next){
        if(isset($visited[$next]))continue;$newPath=array_merge($path,[$next]);if($next===$to)return $newPath;$visited[$next]=true;$queue[]=[$next,$newPath];
    }}
    return [];
}

function admin_advance_order_to_status(int $orderId,string $targetStatus,string $note,bool $sendSms=true,?string $trackingCode=null): array
{
    $stmt=db()->prepare('SELECT order_status,payment_status,tracking_code FROM orders WHERE id=?');$stmt->execute([$orderId]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('سفارش پیدا نشد.');
    $current=(string)$order['order_status'];if($current===$targetStatus)return ['status_changed'=>false,'order_sms'=>null];
    $path=order_status_transition_path($current,$targetStatus);if(!$path)throw new RuntimeException('انتقال از «'.order_status_label($current).'» به «'.order_status_label($targetStatus).'» مجاز نیست.');
    $result=[];$lastIndex=count($path)-1;
    $finalTracking=$trackingCode!==null?$trackingCode:(string)($order['tracking_code']??'');
    foreach($path as $index=>$status){$isLast=$index===$lastIndex;$result=admin_update_order_status($orderId,$status,(string)$order['payment_status'],$isLast?$note:'گام میانی خودکار برای گردش سفارش',$isLast&&$sendSms,$isLast?$finalTracking:(string)($order['tracking_code']??''));}
    return $result;
}
