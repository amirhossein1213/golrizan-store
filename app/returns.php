<?php
declare(strict_types=1);

function return_status_options(): array
{
    return [
        'requested'=>'ثبت‌شده',
        'reviewing'=>'در حال بررسی',
        'approved'=>'تأییدشده',
        'rejected'=>'ردشده',
        'received'=>'کالا دریافت شد',
        'refund_pending'=>'در انتظار بازپرداخت',
        'refunded'=>'بازپرداخت‌شده',
        'closed'=>'بسته‌شده',
    ];
}
function return_status_label(string $status): string { return return_status_options()[$status] ?? $status; }
function return_reason_options(): array
{
    return ['damaged'=>'آسیب‌دیدگی هنگام تحویل','defective'=>'ایراد فنی','wrong_item'=>'کالای اشتباه','not_as_described'=>'مغایرت با توضیحات','changed_mind'=>'انصراف از خرید','other'=>'سایر موارد'];
}
function return_condition_options(): array
{
    return ['unknown'=>'بررسی نشده','sealed'=>'پلمب و سالم','opened'=>'بازشده','damaged'=>'آسیب‌دیده','defective'=>'معیوب'];
}
function return_restock_options(): array
{
    return ['pending'=>'تعیین نشده','restock'=>'بازگشت به موجودی سالم','damaged'=>'انتقال به موجودی آسیب‌دیده','discard'=>'عدم بازگشت به موجودی'];
}
function refund_method_options(): array
{
    return ['gateway'=>'استرداد در پنل درگاه پرداخت','bank'=>'واریز بانکی','manual'=>'ثبت دستی'];
}
function return_number(): string { return 'RT-' . jalali_compact_date() . '-' . strtoupper(bin2hex(random_bytes(4))); }
function order_return_base_eligible(array $order): bool
{
    if((string)setting('return_enabled','1')!=='1') return false;
    if((string)setting('return_require_delivered','1')==='1' && !in_array((string)$order['order_status'],['delivered','installed'],true)) return false;
    if(in_array((string)$order['payment_status'],['refunded'],true)) return false;
    return true;
}
function return_reason_has_health_guarantee(?string $reason): bool
{
    return in_array((string)$reason,['damaged','defective'],true);
}
function order_return_eligible(array $order, ?string $reason=null): bool
{
    if(!order_return_base_eligible($order)) return false;
    // تعهد سلامت کالا محدود به بازه مرجوعی عادی نیست؛ کالای آسیب‌دیده یا معیوب همیشه قابل ثبت برای بررسی است.
    if(return_reason_has_health_guarantee($reason)) return true;
    $days=max(1,min(30,(int)setting('return_window_days','7')));
    $base=strtotime((string)($order['delivered_at']?:$order['updated_at']?:$order['created_at']));
    return $base>0 && $base >= time()-($days*86400);
}
function order_return_entry_available(array $order): bool
{
    // فرم باید برای سفارش تحویل‌شده باز باشد تا مشتری حتی پس از مهلت عادی بتواند «آسیب‌دیده/معیوب» را انتخاب کند.
    return order_return_base_eligible($order);
}
function active_return_for_order(int $orderId): ?array
{
    $stmt=db()->prepare("SELECT * FROM return_requests WHERE order_id=? AND status NOT IN ('rejected','closed','refunded') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);return $stmt->fetch()?:null;
}
function return_request_by_id(int $returnId, bool $forUpdate=false, ?PDO $pdo=null): ?array
{
    $pdo ??= db();
    $sql='SELECT r.*,o.order_number,o.order_status,o.payment_status,o.total order_total,o.customer_name,o.phone,o.paid_at,o.payment_method,o.gateway_reference,o.installed_at FROM return_requests r JOIN orders o ON o.id=r.order_id WHERE r.id=?';
    if($forUpdate)$sql.=' FOR UPDATE';
    $stmt=$pdo->prepare($sql);$stmt->execute([$returnId]);$row=$stmt->fetch();return $row?:null;
}
function return_items_for_request(int $returnId, ?PDO $pdo=null): array
{
    $pdo ??= db();
    $stmt=$pdo->prepare('SELECT ri.*,oi.product_id,oi.variant_id,oi.sku,oi.title,oi.variant_title,oi.variant_options_json,oi.unit_price,oi.unit_cost,oi.quantity order_quantity FROM return_items ri JOIN order_items oi ON oi.id=ri.order_item_id WHERE ri.return_id=? ORDER BY ri.id');
    $stmt->execute([$returnId]);return $stmt->fetchAll();
}
function create_return_request(int $orderId,int $customerId,string $reason,string $note,array $quantities): int
{
    $reasonOptions=return_reason_options();if(!isset($reasonOptions[$reason]))throw new RuntimeException('دلیل مرجوعی معتبر نیست.');
    if(text_length($note)>2000)throw new RuntimeException('توضیحات مرجوعی بیش از حد طولانی است.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM orders WHERE id=? AND customer_id=? FOR UPDATE');$stmt->execute([$orderId,$customerId]);$order=$stmt->fetch();
        if(!$order||!order_return_eligible($order,$reason))throw new RuntimeException(return_reason_has_health_guarantee($reason)?'این سفارش شرایط ثبت مرجوعی سلامت کالا را ندارد.':'مهلت مرجوعی عادی این سفارش به پایان رسیده است؛ برای کالای آسیب‌دیده یا معیوب همچنان می‌توانید درخواست ثبت کنید.');
        $existing=$pdo->prepare("SELECT id FROM return_requests WHERE order_id=? AND status NOT IN ('rejected','closed','refunded') LIMIT 1");$existing->execute([$orderId]);if($existing->fetchColumn())throw new RuntimeException('برای این سفارش یک درخواست فعال وجود دارد.');
        $itemsStmt=$pdo->prepare('SELECT * FROM order_items WHERE order_id=?');$itemsStmt->execute([$orderId]);$items=$itemsStmt->fetchAll();
        // تعدادهایی که قبلاً در یک پرونده پذیرفته/در حال بررسی ثبت شده‌اند دوباره قابل درخواست نیستند.
        // فقط پرونده ردشده از سهم مصرف‌شده خارج می‌شود؛ پرونده بسته یا بازپرداخت‌شده سابقه قطعی است.
        $usedStmt=$pdo->prepare("SELECT ri.order_item_id,COALESCE(SUM(ri.quantity),0) used_quantity FROM return_items ri JOIN return_requests rr ON rr.id=ri.return_id WHERE rr.order_id=? AND rr.status<>'rejected' GROUP BY ri.order_item_id");
        $usedStmt->execute([$orderId]);$used=[];foreach($usedStmt->fetchAll() as $usedRow)$used[(int)$usedRow['order_item_id']]=(int)$usedRow['used_quantity'];
        $selected=[];$requested=0;
        foreach($items as $item){
            $rawQty=max(0,(int)($quantities[(int)$item['id']]??0));
            $available=max(0,(int)$item['quantity']-(int)($used[(int)$item['id']]??0));
            if($rawQty>$available)throw new RuntimeException('تعداد درخواستی یکی از اقلام بیشتر از مانده قابل مرجوعی است.');
            $qty=min($available,$rawQty);if($qty<1)continue;
            $amount=(int)$item['unit_price']*$qty;if($requested>MAX_MONEY_AMOUNT-$amount)throw new RuntimeException('مبلغ درخواست مرجوعی بیش از سقف مجاز است.');$requested+=$amount;$selected[]=[$item,$qty,$amount];
        }
        if(!$selected)throw new RuntimeException('حداقل یک کالا و تعداد را برای مرجوعی انتخاب کنید.');
        // تخفیف سفارش به نسبت ارزش اقلام انتخاب‌شده از مبلغ قابل بازپرداخت کسر می‌شود.
        if((int)$order['subtotal']>0)$requested=min((int)$order['total'],(int)floor($requested*(int)$order['total']/(int)$order['subtotal']));
        $pdo->prepare('INSERT INTO return_requests(order_id,customer_id,return_number,public_token,status,reason,customer_note,requested_amount) VALUES(?,?,?,?,?,?,?,?)')->execute([$orderId,$customerId,return_number(),bin2hex(random_bytes(32)),'requested',$reason,$note?:null,$requested]);
        $returnId=(int)$pdo->lastInsertId();$insert=$pdo->prepare('INSERT INTO return_items(return_id,order_item_id,quantity,reason,approved_amount) VALUES(?,?,?,?,0)');
        foreach($selected as [$item,$qty])$insert->execute([$returnId,(int)$item['id'],$qty,$reasonOptions[$reason]]);
        $oldOrderStatus=(string)$order['order_status'];
        $pdo->prepare("UPDATE orders SET order_status='return_requested' WHERE id=?")->execute([$orderId]);
        $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')->execute([$orderId,$oldOrderStatus,'return_requested',$order['payment_status'],$order['payment_status'],'ثبت درخواست مرجوعی توسط مشتری']);
        $pdo->commit();
        admin_notify_return_requested($returnId);sms_notify_return_status($returnId,'requested');sms_notify_order_status($orderId,'return_requested');return $returnId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function return_transition_allowed(string $from,string $to): bool
{
    if($from===$to)return true;
    $map=[
        'requested'=>['reviewing','approved','rejected'],
        'reviewing'=>['approved','rejected'],
        'approved'=>['received','rejected'],
        'received'=>['refund_pending'],
        'refund_pending'=>['refunded'],
        'refunded'=>['closed'],
        'rejected'=>['closed'],
        'closed'=>[],
    ];
    return in_array($to,$map[$from]??[],true);
}
function accounting_return_inventory(PDO $pdo,int $returnItemId,int $amount): void
{
    if($amount<1)return;
    accounting_post($pdo,'بازگشت کالای سالم مرجوعی به انبار',[
        ['account_id'=>accounting_account_id($pdo,'inventory'),'debit'=>$amount,'credit'=>0],
        ['account_id'=>accounting_account_id($pdo,'cost_of_goods_sold'),'debit'=>0,'credit'=>$amount],
    ],'return-item:'.$returnItemId.':restock','return_item',$returnItemId);
}
function process_return_inventory(PDO $pdo,int $returnId): void
{
    $items=$pdo->prepare('SELECT ri.*,oi.product_id,oi.variant_id,oi.unit_cost FROM return_items ri JOIN order_items oi ON oi.id=ri.order_item_id WHERE ri.return_id=? FOR UPDATE');
    $items->execute([$returnId]);$productIds=[];
    $productUpdate=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');
    $variantUpdate=$pdo->prepare('UPDATE product_variants SET stock=stock+? WHERE id=? AND product_id=?');
    $damagedInsert=$pdo->prepare("INSERT INTO damaged_inventory(return_item_id,product_id,variant_id,quantity,unit_cost,condition_status) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),unit_cost=VALUES(unit_cost),condition_status=VALUES(condition_status)");
    $mark=$pdo->prepare('UPDATE return_items SET inventory_processed_at=NOW() WHERE id=? AND inventory_processed_at IS NULL');
    foreach($items->fetchAll() as $item){
        if(!empty($item['inventory_processed_at']))continue;
        $action=(string)$item['restock_action'];
        if($action==='pending')throw new RuntimeException('برای همه اقلام، وضعیت بازگشت به انبار را تعیین کنید.');
        if($action==='restock'){
            $qty=(int)$item['quantity'];$productId=(int)$item['product_id'];$variantId=(int)($item['variant_id']??0);
            if($variantId>0){$variantUpdate->execute([$qty,$variantId,$productId]);if($variantUpdate->rowCount()!==1)throw new RuntimeException('متغیر کالای مرجوعی پیدا نشد.');$productIds[$productId]=true;inventory_record_existing_change($pdo,$productId,$variantId,'return',$qty,(int)$item['unit_cost'],'return',$returnId,'مرجوعی سالم',(int)(admin_user()['id']??0));}
            else{$productUpdate->execute([$qty,$productId]);if($productUpdate->rowCount()!==1)throw new RuntimeException('کالای مرجوعی پیدا نشد.');inventory_record_existing_change($pdo,$productId,null,'return',$qty,(int)$item['unit_cost'],'return',$returnId,'مرجوعی سالم',(int)(admin_user()['id']??0));}
            accounting_return_inventory($pdo,(int)$item['id'],(int)$item['unit_cost']*$qty);
        }
        elseif($action==='damaged'){
            $damagedInsert->execute([(int)$item['id'],(int)$item['product_id'],!empty($item['variant_id'])?(int)$item['variant_id']:null,(int)$item['quantity'],(int)$item['unit_cost'],(string)$item['condition_status']]);
            inventory_record_movement($pdo,(int)$item['product_id'],!empty($item['variant_id'])?(int)$item['variant_id']:null,'damage',(int)$item['quantity'],0,(int)$item['unit_cost'],'return',$returnId,'انتقال به موجودی آسیب‌دیده',(int)(admin_user()['id']??0));
        }
        $mark->execute([(int)$item['id']]);
    }
    foreach(array_keys($productIds) as $productId)sync_product_variant_summary($pdo,(int)$productId);
}
function admin_update_return(int $returnId,string $newStatus,string $adminNote,array $itemInput=[],?string $refundMethod=null,bool $sendSms=true): void
{
    if(!isset(return_status_options()[$newStatus]))throw new RuntimeException('وضعیت مرجوعی معتبر نیست.');
    $adminNote=text_substr(trim($adminNote),0,2000);
    $pdo=db();$pdo->beginTransaction();
    try{
        $return=return_request_by_id($returnId,true,$pdo);if(!$return)throw new RuntimeException('درخواست مرجوعی پیدا نشد.');
        $oldStatus=(string)$return['status'];if(!return_transition_allowed($oldStatus,$newStatus))throw new RuntimeException('این تغییر وضعیت برای درخواست مرجوعی مجاز نیست.');
        $items=return_items_for_request($returnId,$pdo);$approvedTotal=0;
        $itemsEditable=in_array($oldStatus,['requested','reviewing','approved'],true);
        $updateItem=$pdo->prepare('UPDATE return_items SET condition_status=?,restock_action=?,approved_amount=? WHERE id=? AND return_id=?');
        foreach($items as $item){
            $input=is_array($itemInput[(int)$item['id']]??null)?$itemInput[(int)$item['id']]:[];
            $condition=$itemsEditable?scalar_string($input['condition_status']??$item['condition_status']):(string)$item['condition_status'];if(!isset(return_condition_options()[$condition]))$condition='unknown';
            $restock=$itemsEditable?scalar_string($input['restock_action']??$item['restock_action']):(string)$item['restock_action'];if(!isset(return_restock_options()[$restock]))$restock='pending';
            $maxAmount=(int)$item['unit_price']*(int)$item['quantity'];
            $approved=$itemsEditable?max(0,min($maxAmount,scalar_int($input['approved_amount']??$item['approved_amount']))):(int)$item['approved_amount'];
            if($newStatus==='rejected')$approved=0;
            if($approvedTotal>MAX_MONEY_AMOUNT-$approved)throw new RuntimeException('مبلغ تأییدشده بیش از سقف مجاز است.');$approvedTotal+=$approved;
            $updateItem->execute([$condition,$restock,$approved,(int)$item['id'],$returnId]);
        }
        if($approvedTotal>(int)$return['requested_amount']||$approvedTotal>(int)$return['order_total'])throw new RuntimeException('جمع مبلغ تأییدشده از مبلغ قابل بازپرداخت سفارش بیشتر است.');
        if(in_array($newStatus,['approved','received','refund_pending','refunded'],true)&&$approvedTotal<1)throw new RuntimeException('مبلغ تأییدشده مرجوعی معتبر نیست.');
        if($newStatus==='received')process_return_inventory($pdo,$returnId);
        if($newStatus==='refund_pending'){
            if(!isset(refund_method_options()[$refundMethod??'']))throw new RuntimeException('روش بازپرداخت را انتخاب کنید.');
        }
        $sets=['status=?','admin_note=?','approved_amount=?'];$params=[$newStatus,$adminNote?:null,$approvedTotal];
        if($refundMethod!==null&&isset(refund_method_options()[$refundMethod])){$sets[]='refund_method=?';$params[]=$refundMethod;}
        $params[]=$returnId;$pdo->prepare('UPDATE return_requests SET '.implode(',',$sets).' WHERE id=?')->execute($params);
        $orderStatus=(string)$return['order_status'];$targetOrderStatus=$orderStatus;
        if($newStatus==='approved')$targetOrderStatus='return_approved';
        elseif($newStatus==='rejected')$targetOrderStatus=in_array($orderStatus,['return_requested','return_approved'],true)?(!empty($return['installed_at'])?'installed':'delivered'):$orderStatus;
        elseif(in_array($newStatus,['received','refund_pending'],true))$targetOrderStatus='returned';
        if($targetOrderStatus!==$orderStatus){
            $pdo->prepare('UPDATE orders SET order_status=? WHERE id=?')->execute([$targetOrderStatus,(int)$return['order_id']]);
            $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,?)')->execute([(int)$return['order_id'],$orderStatus,$targetOrderStatus,$return['payment_status'],$return['payment_status'],'تغییر وضعیت از بخش مرجوعی',(int)(admin_user()['id']??0)]);
        }
        $pdo->commit();
        if($sendSms&&$oldStatus!==$newStatus)sms_notify_return_status($returnId,$newStatus);
        if($sendSms&&$targetOrderStatus!==$orderStatus)sms_notify_order_status((int)$return['order_id'],$targetOrderStatus);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function accounting_partial_refund(PDO $pdo,int $orderId,int $refundTransactionId,int $amount): void
{
    if($amount<1)return;
    accounting_post($pdo,'بازپرداخت مرجوعی سفارش '.$orderId,[
        ['account_id'=>accounting_account_id($pdo,'sales_returns'),'debit'=>$amount,'credit'=>0],
        ['account_id'=>accounting_account_id($pdo,'cash_bank'),'debit'=>0,'credit'=>$amount],
    ],'refund:'.$refundTransactionId.':completed','refund',$refundTransactionId);
}
function accounting_refund_liability_payment(PDO $pdo,int $orderId,int $refundTransactionId,int $amount): void
{
    if($amount<1)return;
    accounting_post($pdo,'پرداخت بدهی بازپرداخت سفارش '.$orderId,[
        ['account_id'=>accounting_account_id($pdo,'customer_refunds_payable'),'debit'=>$amount,'credit'=>0],
        ['account_id'=>accounting_account_id($pdo,'cash_bank'),'debit'=>0,'credit'=>$amount],
    ],'refund:'.$refundTransactionId.':liability','refund',$refundTransactionId);
}

function complete_return_refund(int $returnId,int $amount,string $method,string $reference,string $note,bool $sendSms=true): void
{
    if(!isset(refund_method_options()[$method]))throw new RuntimeException('روش بازپرداخت معتبر نیست.');
    $reference=text_substr(trim($reference),0,150);$note=text_substr(trim($note),0,500);
    if(in_array($method,['gateway','bank'],true)&&$reference==='')throw new RuntimeException('شماره مرجع بازپرداخت را وارد کنید.');
    if($amount<1||$amount>MAX_MONEY_AMOUNT)throw new RuntimeException('مبلغ بازپرداخت معتبر نیست.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $return=return_request_by_id($returnId,true,$pdo);if(!$return)throw new RuntimeException('درخواست مرجوعی پیدا نشد.');
        if(!in_array((string)$return['status'],['received','refund_pending'],true))throw new RuntimeException('درخواست هنوز آماده بازپرداخت نیست.');
        if(!in_array((string)$return['payment_status'],['paid','partially_refunded'],true))throw new RuntimeException('برای سفارش پرداخت‌نشده امکان ثبت بازپرداخت وجود ندارد.');
        $paidStmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM refund_transactions WHERE return_id=? AND status='completed'");$paidStmt->execute([$returnId]);$already=(int)$paidStmt->fetchColumn();
        $approved=(int)$return['approved_amount'];if($approved<1||$amount>$approved-$already)throw new RuntimeException('مبلغ بازپرداخت از مانده قابل بازپرداخت بیشتر است.');
        $paymentStmt=$pdo->prepare("SELECT id FROM payment_transactions WHERE order_id=? AND status IN ('paid','partially_refunded') ORDER BY id DESC LIMIT 1");$paymentStmt->execute([(int)$return['order_id']]);$paymentId=(int)$paymentStmt->fetchColumn()?:null;
        $pdo->prepare("INSERT INTO refund_transactions(return_id,order_id,payment_transaction_id,amount,method,status,reference_id,note,processed_by,processed_at) VALUES(?,?,?,?,?,'completed',?,?,?,NOW())")
            ->execute([$returnId,(int)$return['order_id'],$paymentId,$amount,$method,$reference?:null,$note?:null,(int)(admin_user()['id']??0)]);
        $refundTransactionId=(int)$pdo->lastInsertId();
        if((string)$return['order_status']==='cancelled')accounting_refund_liability_payment($pdo,(int)$return['order_id'],$refundTransactionId,$amount);
        else accounting_partial_refund($pdo,(int)$return['order_id'],$refundTransactionId,$amount);
        $returnPaid=$already+$amount;$returnComplete=$returnPaid>=$approved;
        $orderRefundStmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM refund_transactions WHERE order_id=? AND status='completed'");$orderRefundStmt->execute([(int)$return['order_id']]);$orderRefunded=(int)$orderRefundStmt->fetchColumn();
        $orderTotal=(int)$return['order_total'];$newPayment=$orderRefunded>=$orderTotal?'refunded':'partially_refunded';
        if((string)$return['order_status']==='cancelled')$newOrder='cancelled';
        elseif($newPayment==='refunded')$newOrder='refunded';
        elseif($returnComplete)$newOrder=!empty($return['installed_at'])?'installed':'delivered';
        else $newOrder='returned';
        $pdo->prepare('UPDATE return_requests SET status=?,refund_method=? WHERE id=?')->execute([$returnComplete?'refunded':'refund_pending',$method,$returnId]);
        $pdo->prepare('UPDATE orders SET payment_status=?,order_status=? WHERE id=?')->execute([$newPayment,$newOrder,(int)$return['order_id']]);
        if($paymentId){$pdo->prepare('UPDATE payment_transactions SET status=? WHERE id=?')->execute([$newPayment==='refunded'?'refunded':'partially_refunded',$paymentId]);}
        $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,?)')->execute([(int)$return['order_id'],$return['order_status'],$newOrder,$return['payment_status'],$newPayment,'ثبت بازپرداخت مرجوعی: '.($reference?:'بدون مرجع'),(int)(admin_user()['id']??0)]);
        $pdo->commit();
        if($sendSms){sms_notify_return_status($returnId,$returnComplete?'refunded':'refund_pending');sms_notify_payment_status((int)$return['order_id'],$newPayment);sms_notify_order_status((int)$return['order_id'],$newOrder);}
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function create_admin_refund_case_for_order(int $orderId,string $note=''): int
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');$stmt->execute([$orderId]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('سفارش پیدا نشد.');
        if((string)$order['payment_status']!=='paid')throw new RuntimeException('فقط سفارش پرداخت‌شده قابل ورود به فرایند بازپرداخت است.');
        if(!in_array((string)$order['order_status'],['cancelled','delivered','installed'],true))throw new RuntimeException('ابتدا سفارش را لغو یا تحویل‌شده ثبت کنید.');
        $existing=$pdo->prepare("SELECT id FROM return_requests WHERE order_id=? AND status NOT IN ('rejected','closed','refunded') LIMIT 1");$existing->execute([$orderId]);$found=(int)$existing->fetchColumn();if($found>0){$pdo->commit();return $found;}
        $itemsStmt=$pdo->prepare('SELECT * FROM order_items WHERE order_id=?');$itemsStmt->execute([$orderId]);$items=$itemsStmt->fetchAll();if(!$items)throw new RuntimeException('اقلام سفارش پیدا نشد.');
        $requested=(int)$order['total'];if($requested<1)throw new RuntimeException('این سفارش مبلغ قابل بازپرداخت ندارد.');
        $method=in_array((string)$order['payment_method'],['gateway','torobpay','snappay'],true)?'gateway':'manual';
        $pdo->prepare("INSERT INTO return_requests(order_id,customer_id,return_number,public_token,status,reason,customer_note,admin_note,requested_amount,approved_amount,refund_method) VALUES(?,?,?,?, 'refund_pending','other',NULL,?,?,?,?)")
            ->execute([$orderId,$order['customer_id']?:null,return_number(),bin2hex(random_bytes(32)),text_substr(trim($note),0,2000)?:'پرونده بازپرداخت ایجادشده توسط مدیر',$requested,$requested,$method]);
        $returnId=(int)$pdo->lastInsertId();$insert=$pdo->prepare("INSERT INTO return_items(return_id,order_item_id,quantity,reason,condition_status,restock_action,approved_amount,inventory_processed_at) VALUES(?,?,?,?, 'unknown',?, ?, ?)");
        $alreadyRestored=(int)$order['stock_restored']===1;
        foreach($items as $item)$insert->execute([$returnId,(int)$item['id'],(int)$item['quantity'],'بازپرداخت سفارش توسط مدیر',$alreadyRestored?'restock':'pending',(int)$item['line_total'],$alreadyRestored?date('Y-m-d H:i:s'):null]);
        $pdo->commit();return $returnId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
