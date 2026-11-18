<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
admin_required();

function sms_log_event_label(string $event): string
{
    return match(true){
        $event==='order_created'=>'ثبت سفارش مشتری',
        $event==='admin_new_order'=>'سفارش جدید برای مدیر',
        str_starts_with($event,'payment_status_')=>'وضعیت پرداخت',
        str_starts_with($event,'order_status_')=>'وضعیت سفارش',
        str_starts_with($event,'product_alert_price_drop')=>'کاهش قیمت محصول',
        str_starts_with($event,'product_alert_stock')=>'موجودشدن محصول',
        str_starts_with($event,'shipment_status_')=>'ارسال و رهگیری',
        str_starts_with($event,'return_status_')=>'مرجوعی و بازپرداخت',
        $event==='ticket_reply'=>'پاسخ تیکت',
        $event==='customer_otp'=>'OTP مشتری',
        $event==='admin_test'=>'تست OTP',
        str_starts_with($event,'admin_route_test_')=>'تست مسیر پیامک',
        $event==='marketing_campaign'=>'کمپین پیامکی',
        default=>$event,
    };
}

function sms_log_retry_event(array $log): void
{
    $event=(string)$log['event_key'];
    $referenceType=(string)($log['reference_type']??'');
    $referenceId=(int)($log['reference_id']??0);
    if($referenceId<1)throw new RuntimeException('این گزارش مرجع قابل تلاش مجدد ندارد.');

    if($event==='order_created'||$event==='admin_new_order'){
        $result=sms_notify_order_created($referenceId,true);
        $outcome=$event==='admin_new_order'?($result['admin']??[]):($result['customer']??[]);
        if(empty($outcome['ok'])||!empty($outcome['skipped']))throw new RuntimeException((string)($outcome['message']??'ارسال دوباره پیامک انجام نشد.'));
        return;
    }
    if(str_starts_with($event,'payment_status_')){
        $outcome=sms_notify_payment_status($referenceId,substr($event,strlen('payment_status_')),true);
        if(empty($outcome['ok'])||!empty($outcome['skipped']))throw new RuntimeException((string)($outcome['message']??'ارسال دوباره پیامک انجام نشد.'));
        return;
    }
    if(str_starts_with($event,'order_status_')){
        $outcome=sms_notify_order_status($referenceId,substr($event,strlen('order_status_')),true);
        if(empty($outcome['ok'])||!empty($outcome['skipped']))throw new RuntimeException((string)($outcome['message']??'ارسال دوباره پیامک انجام نشد.'));
        return;
    }
    if(str_starts_with($event,'return_status_')){
        sms_notify_return_status($referenceId,substr($event,strlen('return_status_')),true);
        return;
    }
    if(str_starts_with($event,'shipment_status_')){
        $outcome=sms_notify_shipment_status($referenceId,substr($event,strlen('shipment_status_')),true,true);
        if(empty($outcome['ok'])||!empty($outcome['skipped']))throw new RuntimeException((string)($outcome['message']??'ارسال دوباره پیامک انجام نشد.'));
        return;
    }
    if($event==='ticket_reply'){
        sms_notify_ticket_reply($referenceId);
        return;
    }
    if(str_starts_with($event,'product_alert_')){
        $stmt=db()->prepare('SELECT * FROM product_alerts WHERE id=? LIMIT 1');
        $stmt->execute([$referenceId]);
        $alert=$stmt->fetch();
        if(!$alert)throw new RuntimeException('هشدار محصول پیدا نشد.');
        $current=product_alert_current((int)$alert['product_id'],(int)($alert['variant_id']??0));
        if(!$current)throw new RuntimeException('محصول یا مدل هشدار دیگر فعال نیست.');
        $trigger=(string)$alert['alert_type']==='stock'
            ? (int)$current['stock']>0
            : ($alert['target_price']!==null?(int)$current['price']<=(int)$alert['target_price']:(int)$current['price']<(int)$alert['baseline_price']);
        if(!$trigger)throw new RuntimeException('شرط این هشدار در حال حاضر برقرار نیست.');
        sms_send_product_alert($alert,$current);
        db()->prepare("UPDATE product_alerts SET status='sent',sent_at=NOW(),last_checked_at=NOW(),attempts=0,last_error=NULL,lock_token=NULL,locked_at=NULL WHERE id=?")->execute([$referenceId]);
        return;
    }
    throw new RuntimeException('تلاش مجدد خودکار برای این نوع پیامک تعریف نشده است.');
}

if(is_post()){
    verify_csrf();
    $action=scalar_string($_POST['action']??'');
    if($action==='retry'){
        $id=max(0,scalar_int($_POST['id']??0));
        try{
            $stmt=db()->prepare('SELECT * FROM sms_logs WHERE id=? LIMIT 1');$stmt->execute([$id]);$log=$stmt->fetch();
            if(!$log)throw new RuntimeException('گزارش پیامک پیدا نشد.');
            sms_log_retry_event($log);
            flash('success','مسیر پیامک دوباره اجرا شد. نتیجه جدید را در بالای گزارش بررسی کنید.');
        }catch(Throwable $exception){log_error($exception);flash('error',$exception->getMessage());}
        redirect(app_url('admin/sms-logs.php'));
    }
    if($action==='sync_delivery'){
        $id=max(0,scalar_int($_POST['id']??0));
        $result=sms_refresh_log_delivery($id,true);
        flash(!empty($result['ok'])?'success':'error',(string)($result['message']??'بررسی گزارش تحویل انجام نشد.'));
        redirect(app_url('admin/sms-logs.php'));
    }
    if($action==='sync_delivery_batch'){
        try{
            $result=sms_sync_delivery_reports(50);
            $message=(string)($result['message']??'بررسی انجام شد.').' به‌روزرسانی: '.fa_number((int)($result['updated']??0)).'، خطا: '.fa_number((int)($result['failed']??0));
            flash((int)($result['failed']??0)>0?'error':'success',$message);
        }catch(Throwable $exception){log_error($exception);flash('error',$exception->getMessage());}
        redirect(app_url('admin/sms-logs.php'));
    }
}

$adminPage='sms';$adminTitle='گزارش پیامک‌ها';
$page=max(1,scalar_int($_GET['page']??1,1));$per=50;
$status=scalar_string($_GET['status']??'');$event=trim(scalar_string($_GET['event']??''));$mobileRaw=trim(scalar_string($_GET['mobile']??''));
$mobile=preg_replace('/\D+/u','',normalize_digits($mobileRaw))??'';
$where=[];$params=[];
if(in_array($status,['pending','sent','failed','skipped'],true)){$where[]='status=?';$params[]=$status;}
if($event!==''){$where[]='event_key LIKE ?';$params[]=$event.'%';}
if($mobile!==''){$where[]='mobile LIKE ?';$params[]='%'.$mobile.'%';}
$sql=$where?' WHERE '.implode(' AND ',$where):'';
$c=db()->prepare('SELECT COUNT(*) FROM sms_logs'.$sql);$c->execute($params);$total=(int)$c->fetchColumn();
$pages=max(1,(int)ceil($total/$per));$page=min($page,$pages);
$q=db()->prepare('SELECT * FROM sms_logs'.$sql.' ORDER BY id DESC LIMIT '.$per.' OFFSET '.(($page-1)*$per));$q->execute($params);$rows=$q->fetchAll();
try{$stats=db()->query("SELECT status,COUNT(*) count FROM sms_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);}catch(Throwable $exception){log_error($exception);$stats=[];}
try{$deliveryStats=db()->query("SELECT delivery_status,COUNT(*) count FROM sms_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND delivery_status IS NOT NULL GROUP BY delivery_status")->fetchAll(PDO::FETCH_KEY_PAIR);}catch(Throwable $exception){log_error($exception);$deliveryStats=[];}
require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1>گزارش پیامک‌ها</h1><p class="muted">«ارسال‌شده» یعنی SMS.ir درخواست را پذیرفته است؛ ستون تحویل نهایی مشخص می‌کند پیام واقعاً به گوشی رسیده یا به علت‌هایی مانند محدودیت دریافت رد شده است.</p></div><div class="actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="sync_delivery_batch"><button class="btn btn-primary">به‌روزرسانی گزارش تحویل</button></form><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-recipients.php'))?>">وضعیت بر اساس شماره</a><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-settings.php'))?>">تنظیمات و تست مسیرها</a></div></div>
<div class="stats"><div class="card stat"><strong><?=fa_number((int)($stats['sent']??0))?></strong><span>پذیرفته‌شده ۲۴ ساعت</span></div><div class="card stat"><strong><?=fa_number((int)($deliveryStats['delivered']??0))?></strong><span>تحویل‌شده قطعی</span></div><div class="card stat"><strong><?=fa_number((int)($deliveryStats['blacklisted']??0))?></strong><span>محدودیت دریافت</span></div><div class="card stat"><strong><?=fa_number((int)($stats['failed']??0))?></strong><span>خطای ارسال اولیه</span></div></div>
<form class="filters-admin" method="get"><input name="mobile" value="<?=e($mobileRaw)?>" placeholder="جست‌وجوی شماره موبایل" inputmode="tel"><select name="status"><option value="">همه وضعیت‌ها</option><?php foreach(['sent'=>'پذیرفته‌شده','failed'=>'خطای ارسال','skipped'=>'ارسال واقعی نشده','pending'=>'در انتظار'] as $key=>$label):?><option value="<?=$key?>" <?=$status===$key?'selected':''?>><?=$label?></option><?php endforeach?></select><select name="event"><option value="">همه مسیرها</option><option value="order_created" <?=$event==='order_created'?'selected':''?>>ثبت سفارش مشتری</option><option value="admin_new_order" <?=$event==='admin_new_order'?'selected':''?>>سفارش مدیر</option><option value="payment_status_" <?=$event==='payment_status_'?'selected':''?>>وضعیت پرداخت</option><option value="order_status_" <?=$event==='order_status_'?'selected':''?>>وضعیت سفارش</option><option value="product_alert_price_drop" <?=$event==='product_alert_price_drop'?'selected':''?>>کاهش قیمت</option><option value="product_alert_stock" <?=$event==='product_alert_stock'?'selected':''?>>موجودشدن محصول</option><option value="shipment_status_" <?=$event==='shipment_status_'?'selected':''?>>ارسال و رهگیری</option><option value="return_status_" <?=$event==='return_status_'?'selected':''?>>مرجوعی</option><option value="ticket_reply" <?=$event==='ticket_reply'?'selected':''?>>تیکت</option><option value="customer_otp" <?=$event==='customer_otp'?'selected':''?>>OTP</option></select><button class="btn btn-secondary">فیلتر</button></form>
<div class="table-wrap"><table><thead><tr><th>تاریخ</th><th>موبایل</th><th>مسیر</th><th>قالب / متغیرها</th><th>پذیرش اولیه</th><th>تحویل نهایی و علت</th><th>شناسه پیام</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($rows as $row):$payload=json_decode((string)($row['payload_json']??''),true);$state=$row['delivery_state']!==null?(int)$row['delivery_state']:null;$deliveryInfo=sms_delivery_state_info($state);?>
<tr><td><?=e(jalali_format($row['created_at']))?></td><td dir="ltr"><a href="<?=e(app_url('admin/sms-logs.php?mobile='.rawurlencode((string)$row['mobile'])))?>"><strong><?=e($row['mobile'])?></strong></a></td><td><strong><?=e(sms_log_event_label((string)$row['event_key']))?></strong><br><small dir="ltr"><?=e($row['event_key'])?></small></td>
<td><strong><?=fa_number($row['template_id']?:'—')?></strong><?php if(is_array($payload)&&$payload):?><details><summary><?=fa_number(count($payload))?> متغیر</summary><?php foreach($payload as $parameter):?><small><b dir="ltr"><?=e((string)($parameter['name']??''))?></b>: <?=e((string)($parameter['value']??''))?></small><br><?php endforeach?></details><?php endif?></td>
<td><span class="badge <?=$row['status']==='sent'?'green':($row['status']==='failed'?'red':($row['status']==='skipped'?'orange':''))?>"><?=e(['sent'=>'پذیرفته شد','failed'=>'ناموفق','skipped'=>'ارسال نشد','pending'=>'در انتظار'][$row['status']]??$row['status'])?></span><br><small><?=e($row['response_message']?:'—')?></small></td>
<td><?php if($row['status']!=='sent'):?><span class="badge red">گزارش تحویل ندارد</span><?php else:?><span class="badge <?=e(sms_delivery_badge_class((string)($row['delivery_status']??$deliveryInfo['status'])))?>"><?=e($deliveryInfo['label'])?></span><br><small><b>علت:</b> <?=e((string)($row['delivery_reason']?:$deliveryInfo['reason']))?></small><br><small><b>راهکار:</b> <?=e($deliveryInfo['solution'])?></small><?php if(!empty($row['delivery_checked_at'])):?><br><small>آخرین بررسی: <?=e(jalali_format($row['delivery_checked_at']))?></small><?php endif?><?php if(!empty($row['delivery_last_error'])):?><br><small class="text-danger">خطای دریافت گزارش: <?=e($row['delivery_last_error'])?></small><?php endif?><?php endif?></td>
<td><?=fa_number($row['message_id']?:'—')?><?php if($state!==null):?><br><small>کد تحویل: <?=fa_number($state)?></small><?php endif?></td>
<td><div class="actions"><?php if($row['status']==='sent'&&(int)($row['message_id']??0)>0):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="sync_delivery"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-secondary btn-sm">بررسی تحویل</button></form><?php endif?><?php if(in_array($row['status'],['failed','skipped'],true)&&!str_starts_with((string)$row['event_key'],'admin_route_test_')&&!in_array((string)$row['event_key'],['customer_otp','admin_test','marketing_campaign'],true)):?><form method="post" data-confirm="این مسیر پیامک دوباره اجرا شود؟"><?=csrf_field()?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-secondary btn-sm">تلاش مجدد</button></form><?php endif?></div></td></tr>
<?php endforeach?><?php if(!$rows):?><tr><td colspan="8">گزارشی وجود ندارد.</td></tr><?php endif?></tbody></table></div>
<?php if($pages>1):?><div class="pagination"><?php for($i=1;$i<=$pages;$i++):$pageQuery=http_build_query(['page'=>$i,'status'=>$status,'event'=>$event,'mobile'=>$mobileRaw]);?><a class="<?=$i===$page?'active':''?>" href="?<?=e($pageQuery)?>"><?=fa_number($i)?></a><?php endfor?></div><?php endif?>
<?php require __DIR__.'/partials/footer.php';
