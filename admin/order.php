<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$id=max(0,scalar_int($_GET['id']??$_POST['id']??0));
if(is_post()){
    verify_csrf();
    try{
        if($id<1)throw new RuntimeException('سفارش معتبر نیست.');
        $action=scalar_string($_POST['action']??'update');
        if($action==='snappay_update'){
            $result=snappay_update_order($id,is_array($_POST['snappay_qty']??null)?$_POST['snappay_qty']:[]);
            flash('success','Update اسنپ‌پی با موفقیت انجام شد. مبلغ سفارش از '.fa_number(format_price((int)$result['old_total'])).' به '.fa_number(format_price((int)$result['new_total'])).' تومان تغییر کرد.');
            redirect(app_url('admin/order.php?id='.$id));
        }
        if($action==='snappay_cancel'){
            snappay_cancel_order($id);
            flash('success','Cancel کامل سفارش در اسنپ‌پی با موفقیت انجام شد و سفارش محلی نیز لغو/بازپرداخت شد.');
            redirect(app_url('admin/order.php?id='.$id));
        }
        if($action==='snappay_status'){
            $tx=snappay_latest_paid_transaction_for_order(db(),$id,false);if(!$tx)throw new RuntimeException('تراکنش اسنپ‌پی برای بررسی وضعیت پیدا نشد.');
            $status=snappay_status_details((string)$tx['authority']);
            flash(!empty($status['ok'])?'success':'error','وضعیت فعلی اسنپ‌پی: '.(string)($status['status']??'UNKNOWN'));
            redirect(app_url('admin/order.php?id='.$id));
        }
        if($action==='create_refund_case'){
            $returnId=create_admin_refund_case_for_order($id,'پرونده بازپرداخت سفارش لغوشده');
            flash('success','پرونده بازپرداخت آماده شد.');redirect(app_url('admin/return.php?id='.$returnId));
        }
        if($action==='admin_meta'){
            $assignedId=max(0,scalar_int($_POST['assigned_admin_id']??0));
            admin_update_order_meta($id,$assignedId>0?$assignedId:null,scalar_string($_POST['priority']??'normal'),scalar_string($_POST['admin_internal_note']??''));
            flash('success','مسئول، اولویت و یادداشت داخلی سفارش ذخیره شد.');
            redirect(app_url('admin/order.php?id='.$id));
        }
        if($action==='ship_with_tracking'){
            $tracking=text_substr(trim(scalar_string($_POST['tracking_code']??'')),0,150);
            $sendSms=isset($_POST['send_sms']);
            $shipResult=admin_mark_order_shipped($id,$tracking,null,'ثبت ارسال از صفحه سفارش توسط مدیر',$sendSms);
            flash('success','سفارش ارسال‌شده ثبت شد و کد رهگیری ذخیره گردید.');
            if($sendSms&&is_array($shipResult['sms']??null))sms_admin_flash_immediate_result($shipResult['sms'],'پیامک کد رهگیری');
            redirect(app_url('admin/order.php?id='.$id));
        }
        if($action==='quick_status'){
            $quickStatus=scalar_string($_POST['quick_status']??'');
            $current=db()->prepare('SELECT payment_status,tracking_code FROM orders WHERE id=?');$current->execute([$id]);$currentOrder=$current->fetch();if(!$currentOrder)throw new RuntimeException('سفارش پیدا نشد.');
            $sendSms=isset($_POST['send_sms']);
            if($quickStatus==='shipped'){
                $tracking=text_substr(trim(scalar_string($_POST['tracking_code']??'')),0,150);
                $shipResult=admin_mark_order_shipped($id,$tracking,null,'تغییر سریع وضعیت و ثبت ارسال توسط مدیر',$sendSms);
                flash('success','سفارش ارسال‌شده ثبت شد و کد رهگیری ذخیره گردید.');
                if($sendSms&&is_array($shipResult['sms']??null))sms_admin_flash_immediate_result($shipResult['sms'],'پیامک کد رهگیری');
            }else{
                $updateResult=admin_update_order_status($id,$quickStatus,(string)$currentOrder['payment_status'],'تغییر سریع وضعیت توسط مدیر',$sendSms,(string)($currentOrder['tracking_code']??''));
                flash('success','وضعیت سفارش به «'.order_status_label($quickStatus).'» تغییر کرد.');
                if($sendSms&&is_array($updateResult['order_sms']??null))sms_admin_flash_immediate_result($updateResult['order_sms'],'پیامک تغییر وضعیت سفارش');
            }
            redirect(app_url('admin/order.php?id='.$id));
        }
        $orderStatus=scalar_string($_POST['order_status']??'new');
        $paymentStatus=scalar_string($_POST['payment_status']??'unpaid');
        $note=text_substr(trim(scalar_string($_POST['status_note']??'')),0,500);
        $tracking=text_substr(trim(scalar_string($_POST['tracking_code']??'')),0,120);
        $sendSms=isset($_POST['send_sms']);
        $updateResult=admin_update_order_status($id,$orderStatus,$paymentStatus,$note,$sendSms,$tracking);
        flash('success','وضعیت سفارش ذخیره شد.');
        if($sendSms){
            if(is_array($updateResult['order_created_sms']??null))sms_admin_flash_immediate_result($updateResult['order_created_sms'],'پیامک تأیید ثبت سفارش پس از پرداخت');
            if(is_array($updateResult['order_sms']??null))sms_admin_flash_immediate_result($updateResult['order_sms'],'پیامک تغییر وضعیت سفارش');
            if(is_array($updateResult['shipment_sms']??null))sms_admin_flash_immediate_result($updateResult['shipment_sms'],'پیامک کد رهگیری معوق');
            if(is_array($updateResult['payment_sms']??null))sms_admin_flash_immediate_result($updateResult['payment_sms'],'پیامک تغییر وضعیت پرداخت');
            if(empty($updateResult['order_created_sms'])&&empty($updateResult['order_sms'])&&empty($updateResult['shipment_sms'])&&empty($updateResult['payment_sms']))flash('error','وضعیت یا اطلاعات رهگیری تغییری نکرد؛ پیامکی ارسال نشد.');
        }
    }catch(Throwable $e){log_error($e);flash('error',$e->getMessage());}
    redirect(app_url('admin/order.php?id='.$id));
}
if($id<1){http_response_code(404);exit('سفارش پیدا نشد.');}
$stmt=db()->prepare('SELECT o.*,c.full_name account_name,a.full_name assigned_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN admins a ON a.id=o.assigned_admin_id WHERE o.id=?');$stmt->execute([$id]);$order=$stmt->fetch();
if(!$order){http_response_code(404);exit('سفارش پیدا نشد.');}
$itemsStmt=db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$itemsStmt->execute([$id]);$items=$itemsStmt->fetchAll();
$historyStmt=db()->prepare('SELECT h.*,a.full_name admin_name FROM order_status_history h LEFT JOIN admins a ON a.id=h.admin_id WHERE h.order_id=? ORDER BY h.id DESC');$historyStmt->execute([$id]);$history=$historyStmt->fetchAll();
$paymentsStmt=db()->prepare('SELECT * FROM payment_transactions WHERE order_id=? ORDER BY id DESC');$paymentsStmt->execute([$id]);$payments=$paymentsStmt->fetchAll();
$returnsStmt=db()->prepare('SELECT * FROM return_requests WHERE order_id=? ORDER BY id DESC');$returnsStmt->execute([$id]);$returns=$returnsStmt->fetchAll();
$snappPayment=null;foreach($payments as $paymentRow){if((string)$paymentRow['gateway']==='snappay'&&in_array((string)$paymentRow['status'],['paid','partially_refunded','refunded'],true)){$snappPayment=$paymentRow;break;}}$assignableAdmins=admin_order_assignable_admins();$nextStatuses=order_next_statuses((string)$order['order_status']);$quickStatuses=$nextStatuses;if((string)$order['payment_method']==='snappay'&&in_array((string)$order['payment_status'],['paid','partially_refunded'],true)){unset($quickStatuses['cancelled']);unset($nextStatuses['cancelled']);}unset($quickStatuses['handed_to_carrier'],$quickStatuses['shipped']);$canQuickShip=in_array((string)$order['order_status'],['confirmed','preparing','ready_to_ship','handed_to_carrier','shipped'],true);$currentStage=order_workflow_stage((string)$order['order_status']);
$adminPage='orders';$adminTitle='سفارش '.$order['order_number'];require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1><?=e($order['order_number'])?></h1><p class="muted"><?=e(order_status_label($order['order_status']))?> · <?=e(order_payment_status_label($order))?></p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/orders.php'))?>">بازگشت</a><?php if(admin_can('manage_shipping')):$orderShipment=shipment_for_order($id);?><a class="btn btn-secondary" href="<?=e(app_url('admin/shipment.php'.($orderShipment?'?id='.(int)$orderShipment['id']:'?order_id='.$id)))?>"><?=$orderShipment?'پرونده ارسال':'ساخت پرونده ارسال'?></a><?php endif?><?php if($returns):?><a class="btn btn-secondary" href="<?=e(app_url('admin/return.php?id='.(int)$returns[0]['id']))?>">پرونده مرجوعی</a><?php endif?></div></div>
<div class="order-progress">
<?php $stageOrder=['new','processing','ready','shipping','completed'];$stageLabels=['new'=>'ثبت و بررسی','processing'=>'آماده‌سازی','ready'=>'آماده ارسال','shipping'=>'ارسال','completed'=>'تحویل'];$currentIndex=array_search($currentStage,$stageOrder,true);foreach($stageOrder as $index=>$stageKey):?>
<div class="order-progress__step <?=$currentStage===$stageKey?'is-current':(($currentIndex!==false&&$index<$currentIndex)?'is-complete':'')?>"><?=e($stageLabels[$stageKey])?></div>
<?php endforeach?>
</div>
<?php if($quickStatuses||$canQuickShip):?><div class="card" style="margin-bottom:18px"><h2>تغییر سریع وضعیت</h2><div class="order-quick-actions"><?php foreach($quickStatuses as $statusKey=>$statusLabel):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="quick_status"><input type="hidden" name="quick_status" value="<?=e($statusKey)?>"><input type="hidden" name="send_sms" value="1"><button class="btn btn-secondary btn-sm"><?=e($statusLabel)?></button></form><?php endforeach?><?php if($canQuickShip):?><form method="post" data-order-tracking-form data-current-tracking="<?=e($order['tracking_code']??'')?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="ship_with_tracking"><input type="hidden" name="tracking_code" value=""><input type="hidden" name="send_sms" value="1"><button class="btn btn-primary btn-sm"><?=$order['order_status']==='shipped'?'اصلاح کد رهگیری':'ارسال و ثبت کد رهگیری'?></button></form><?php endif?></div><small class="muted"><?=(string)$order['payment_status']==='paid'?'پس از ثبت هر وضعیت، پیامک مشتری همان لحظه ارسال می‌شود.':'تا زمانی که پرداخت کامل نشود، وضعیت ذخیره می‌شود اما هیچ پیامکی برای مشتری ارسال نخواهد شد.'?></small></div><?php endif?>
<div class="order-meta">
<div><strong>تاریخ ثبت</strong><br><?=e(jalali_format($order['created_at']))?></div>
<div><strong>مشتری</strong><br><?=e($order['customer_name'])?><br><a href="tel:<?=e($order['phone'])?>"><?=e($order['phone'])?></a></div>
<div><strong>نشانی</strong><br><?=e($order['province'].'، '.$order['city'])?><br><?=nl2br(e($order['address']))?><br>کدپستی <?=e($order['postal_code'])?></div>
<div><strong>پرداخت</strong><br><?=e(payment_method_label((string)$order['payment_method']))?><br><?=e(order_payment_status_label($order))?><?php if($order['gateway_reference']):?><br><small><?=((string)$order['payment_method']==='snappay')?'Transaction ID اسنپ‌پی: ':''?><span dir="ltr"><?=e($order['gateway_reference'])?></span></small><?php endif?><?php if((string)$order['payment_method']==='torobpay'&&!empty($order['payment_markup_accepted_at'])):?><br><small>پذیرش افزایش قیمت: <?=e(jalali_format($order['payment_markup_accepted_at']))?></small><?php endif?></div>
</div>
<div class="table-wrap" style="margin-top:20px"><table><thead><tr><th>کالا / مدل</th><th>SKU</th><th>قیمت واحد</th><th>جشنواره</th><th>تعداد</th><th>جمع</th></tr></thead><tbody>
<?php foreach($items as $item):$optionLabel=order_item_option_label($item);?><tr><td><strong><?=e($item['title'])?></strong><?php if($optionLabel!==''):?><br><small><?=e($optionLabel)?></small><?php endif?></td><td dir="ltr"><?=e($item['sku'])?></td><td><?php if((int)$item['base_unit_price']>(int)$item['unit_price']):?><small><s><?=fa_number(format_price($item['base_unit_price']))?></s></small><br><?php endif?><?=fa_number(format_price($item['unit_price']))?></td><td><?=e($item['festival_title']?:'—')?></td><td><?=fa_number($item['quantity'])?></td><td><?=fa_number(format_price($item['line_total']))?></td></tr><?php endforeach?>
<tr><td colspan="5">جمع کالاها</td><td><?=fa_number(format_price($order['subtotal']))?> تومان</td></tr><?php if((float)($order['payment_markup_percent']??0)>0):?><tr><td colspan="5">افزایش لحاظ‌شده ترب‌پی (<?=fa_number(rtrim(rtrim(number_format((float)$order['payment_markup_percent'],2,'.',''),'0'),'.'))?>٪)</td><td><?=fa_number(format_price((int)($order['payment_markup_amount']??0)))?> تومان</td></tr><?php endif?><?php if((int)($order['discount_amount']??0)>0):?><tr><td colspan="5">تخفیف <?=e($order['coupon_code']?'کد '.$order['coupon_code']:'')?></td><td>− <?=fa_number(format_price($order['discount_amount']))?> تومان</td></tr><?php endif?><tr><td colspan="5">ارسال</td><td><?=e(in_array((string)$order['shipping_method'],['festival_free','product_festival_free'],true)?'رایگان جشنواره':((string)$order['shipping_method']==='mixed_product_festival'?'ترکیبی':'پس‌کرایه'))?></td></tr><tr><td colspan="5"><strong>مبلغ نهایی</strong></td><td><strong><?=fa_number(format_price($order['total']))?> تومان</strong></td></tr>
</tbody></table></div>
<?php if((string)$order['payment_method']==='snappay'&&$snappPayment):?>
<div class="card" style="margin-top:20px"><h2>کنترل تراکنش اسنپ‌پی</h2>
<p><strong>Transaction ID:</strong> <span dir="ltr"><?=e((string)($snappPayment['reference_id']?:$order['gateway_reference']))?></span></p>
<p class="muted">Update و Cancel برگشت‌پذیر نیستند. قبل از ارسال درخواست، اطلاعات را دوباره کنترل کنید. Get Payment Status نیز از این بخش قابل بررسی است و تطبیق خودکار در Cron ادامه دارد.</p>
<form method="post" style="margin:12px 0"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="snappay_status"><button class="btn btn-secondary" type="submit">Get Payment Status</button></form>
<?php if(in_array((string)$order['payment_status'],['paid','partially_refunded'],true)&&!in_array((string)$order['order_status'],['cancelled','refunded','returned'],true)):?>
<form method="post" style="margin-top:18px" onsubmit="return confirm('هشدار: Update اسنپ‌پی برگشت‌پذیر نیست. تعدادهای جدید و مبلغ سفارش را کنترل کرده‌اید؟');">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="snappay_update">
<div class="table-wrap"><table><thead><tr><th>کالا</th><th>وضعیت تخفیف اولیه</th><th>تعداد فعلی</th><th>تعداد جدید</th></tr></thead><tbody>
<?php foreach($items as $item):?><tr><td><?=e($item['title'])?><?php $ol=order_item_option_label($item);if($ol!==''):?><br><small><?=e($ol)?></small><?php endif?></td><td><?=(int)$item['base_unit_price']>(int)$item['unit_price']?'تخفیف‌دار':'بدون تخفیف'?></td><td><?=fa_number($item['quantity'])?></td><td><input type="number" name="snappay_qty[<?=(int)$item['id']?>]" min="0" max="<?=(int)$item['quantity']?>" value="<?=(int)$item['quantity']?>" required style="max-width:100px"></td></tr><?php endforeach?>
</tbody></table></div><p class="muted">برای دمو: ابتدا کالای بدون تخفیف را از ۲ به ۱ کاهش دهید؛ سپس حداقل ۳۰ ثانیه بعد کالای تخفیف‌دار را با مقدار ۰ حذف کنید.</p><button class="btn btn-primary" type="submit">ارسال Update به اسنپ‌پی</button></form>
<form method="post" style="margin-top:18px" onsubmit="return confirm('هشدار جدی: Cancel کل سفارش اسنپ‌پی غیرقابل برگشت است و کل مانده سفارش مرجوع می‌شود. مطمئن هستید؟');"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="snappay_cancel"><button class="btn btn-danger" type="submit">Cancel کامل سفارش اسنپ‌پی</button></form>
<?php endif?></div>
<?php endif?>
<form class="card" method="post" style="margin-top:20px"><h2>مدیریت داخلی سفارش</h2><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="admin_meta"><div class="order-assignment-card"><div class="field"><label>مسئول سفارش</label><select name="assigned_admin_id"><option value="0">بدون مسئول مشخص</option><?php foreach($assignableAdmins as $adminOption):?><option value="<?=(int)$adminOption['id']?>" <?=(int)($order['assigned_admin_id']??0)===(int)$adminOption['id']?'selected':''?>><?=e($adminOption['full_name'].' ('.$adminOption['username'].')')?></option><?php endforeach?></select></div><div class="field"><label>اولویت</label><select name="priority"><?php foreach(order_priority_options() as $priorityKey=>$priorityLabel):?><option value="<?=e($priorityKey)?>" <?=($order['priority']??'normal')===$priorityKey?'selected':''?>><?=e($priorityLabel)?></option><?php endforeach?></select></div><div class="field full"><label>یادداشت داخلی مدیران</label><textarea name="admin_internal_note" maxlength="4000" rows="4" placeholder="این متن به مشتری نمایش داده نمی‌شود."><?=e($order['admin_internal_note']??'')?></textarea></div></div><button class="btn btn-primary">ذخیره اطلاعات داخلی</button></form>
<form class="card" method="post" style="margin-top:20px"><h2>گردش سفارش</h2><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="update"><div class="form-grid">
<div class="field"><label>وضعیت سفارش</label><select name="order_status"><?php foreach(order_status_options() as $key=>$label):if($key==='cancelled'&&(string)$order['payment_method']==='snappay'&&in_array((string)$order['payment_status'],['paid','partially_refunded'],true))continue;if(in_array($key,['handed_to_carrier','shipped'],true)&&$key!==$order['order_status'])continue;if($key!==$order['order_status']&&!order_status_transition_allowed((string)$order['order_status'],$key))continue;?><option value="<?=e($key)?>" <?=$order['order_status']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><small>ثبت «ارسال‌شده» فقط از دکمه «ارسال و ثبت کد رهگیری» انجام می‌شود.</small></div>
<div class="field"><label>وضعیت پرداخت</label><select name="payment_status"><?php foreach(payment_status_options() as $key=>$label):if(in_array($key,['partially_refunded','refunded'],true))continue;if($key!==$order['payment_status']&&!payment_status_transition_allowed((string)$order['payment_status'],$key))continue;?><option value="<?=e($key)?>" <?=$order['payment_status']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><small>بازپرداخت فقط از بخش مرجوعی ثبت می‌شود.</small></div>
<div class="field"><label>کد رهگیری / بارنامه</label><input name="tracking_code" maxlength="120" value="<?=e($order['tracking_code']??'')?>" readonly><small>برای ثبت یا اصلاح کد رهگیری از دکمه ارسال بالای صفحه استفاده کنید.</small></div>
<div class="field full"><label>یادداشت این تغییر</label><textarea name="status_note" maxlength="500" rows="3"></textarea></div>
<div class="field full"><label><input type="checkbox" name="send_sms" value="1" checked> ارسال فوری پیامک وضعیت از SMS.ir پس از تکمیل پرداخت</label><small>اگر پرداخت سفارش کامل نشده باشد، تغییرات ذخیره می‌شوند اما پیامکی برای مشتری ارسال نمی‌شود.</small></div>
</div><button class="btn btn-primary">ذخیره وضعیت</button></form>
<?php if($order['order_status']==='cancelled'&&$order['payment_status']==='paid'&&!$returns):?><form method="post" class="card" style="margin-top:20px"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="create_refund_case"><h2>بازپرداخت سفارش لغوشده</h2><p>برای ثبت استرداد وجه و شماره مرجع درگاه، ابتدا پرونده بازپرداخت بسازید.</p><button class="btn btn-primary">ایجاد پرونده بازپرداخت</button></form><?php endif?>
<?php if((string)$order['payment_method']==='snappay'&&(string)$order['payment_status']==='paid'):?><div class="form-message" style="margin-top:20px"><strong>کنترل ارسال اسنپ‌پی:</strong> پیش از تحویل کالا به حمل، وضعیت این سفارش را در پرتال پذیرندگان اسنپ‌پی بررسی کنید؛ طبق رویه اسنپ‌پی فقط وضعیت «موفق» مجوز ارسال کالا است و «تأیید شده» هنوز مرحله نهایی ارسال نیست.</div><?php endif?>
<?php if($payments):?><div class="card" style="margin-top:20px"><h2>تراکنش‌های پرداخت</h2><div class="table-wrap"><table><thead><tr><th>درگاه</th><th>مبلغ</th><th>Payment Token / Authority</th><th>Transaction ID / مرجع</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td><?=e($p['gateway']==='torobpay'?'ترب‌پی':($p['gateway']==='snappay'?'اسنپ‌پی':($p['gateway']==='zibal'?'زیبال':$p['gateway'])))?></td><td><?=fa_number(format_price($p['amount']))?></td><td dir="ltr"><?=e($p['authority']?:'—')?></td><td dir="ltr"><?=e($p['reference_id']?:'—')?></td><td><?=e($p['status'])?></td><td><?=e(jalali_format($p['created_at']))?></td></tr><?php endforeach?></tbody></table></div></div><?php endif?>
<div class="card" style="margin-top:20px"><h2>تاریخچه وضعیت‌ها</h2><div class="table-wrap"><table><thead><tr><th>تاریخ</th><th>سفارش</th><th>پرداخت</th><th>توضیح</th><th>ثبت‌کننده</th></tr></thead><tbody><?php foreach($history as $h):?><tr><td><?=e(jalali_format($h['created_at']))?></td><td><?=e(order_status_label($h['new_order_status']))?></td><td><?=e(payment_status_label($h['new_payment_status']))?></td><td><?=e($h['note']?:'—')?></td><td><?=e($h['admin_name']?:'سیستم/مشتری')?></td></tr><?php endforeach?></tbody></table></div></div>
<?php require __DIR__.'/partials/footer.php';
