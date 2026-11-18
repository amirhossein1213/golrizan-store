<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
admin_required();

function sms_recipient_status_label(?string $status): string
{
    return match($status){
        'sent'=>'ارسال‌شده',
        'failed'=>'ناموفق',
        'skipped'=>'ارسال واقعی نشده',
        'pending'=>'در انتظار',
        null,''=>'هیچ گزارشی ثبت نشده',
        default=>(string)$status,
    };
}

function sms_recipient_status_class(?string $status): string
{
    return match($status){
        'sent'=>'green',
        'failed'=>'red',
        'skipped'=>'orange',
        'pending'=>'',
        default=>'orange',
    };
}

function sms_recipient_event_label(?string $event): string
{
    $event=(string)$event;
    return match(true){
        $event==='order_created'=>'ثبت سفارش',
        $event==='admin_new_order'=>'سفارش جدید مدیر',
        str_starts_with($event,'payment_status_')=>'وضعیت پرداخت',
        str_starts_with($event,'order_status_')=>'وضعیت سفارش',
        str_starts_with($event,'shipment_status_')=>'ارسال و رهگیری',
        str_starts_with($event,'return_status_')=>'مرجوعی',
        str_starts_with($event,'product_alert_price_drop')=>'کاهش قیمت',
        str_starts_with($event,'product_alert_stock')=>'موجودشدن محصول',
        $event==='customer_otp'=>'کد ورود/ثبت‌نام',
        $event==='ticket_reply'=>'پاسخ تیکت',
        $event==='marketing_campaign'=>'کمپین پیامکی',
        $event==='' =>'—',
        default=>$event,
    };
}

$adminPage='sms-recipients';
$adminTitle='کنترل پیامک شماره‌ها';
$view=scalar_string($_GET['view']??'numbers');
if(!in_array($view,['numbers','orders'],true))$view='numbers';
$period=scalar_string($_GET['period']??'30');
if(!in_array($period,['today','7','30','all'],true))$period='30';
$searchRaw=trim(scalar_string($_GET['q']??''));
$searchDigits=preg_replace('/\D+/u','',normalize_digits($searchRaw))??'';
$page=max(1,scalar_int($_GET['page']??1,1));
$per=50;

$dateSql=match($period){
    'today'=>"created_at>=CURDATE()",
    '7'=>"created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",
    '30'=>"created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)",
    default=>'1=1',
};
$periodLabel=match($period){'today'=>'امروز','7'=>'۷ روز اخیر','30'=>'۳۰ روز اخیر',default=>'همه زمان‌ها'};

$summary=['numbers_count'=>0,'total_count'=>0,'sent_count'=>0,'failed_count'=>0,'skipped_count'=>0,'pending_count'=>0];
$rows=[];
$total=0;
$pages=1;

if($view==='numbers'){
    $where=[$dateSql,"mobile<>''"];
    $params=[];
    if($searchDigits!==''){$where[]='mobile LIKE ?';$params[]='%'.$searchDigits.'%';}
    $whereSql=' WHERE '.implode(' AND ',$where);

    try{
        $summaryStmt=db()->prepare("SELECT COUNT(DISTINCT mobile) numbers_count,COUNT(*) total_count,
            SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) sent_count,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed_count,
            SUM(CASE WHEN status='skipped' THEN 1 ELSE 0 END) skipped_count,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending_count
            FROM sms_logs".$whereSql);
        $summaryStmt->execute($params);
        $summary=array_merge($summary,$summaryStmt->fetch()?:[]);

        $countStmt=db()->prepare('SELECT COUNT(*) FROM (SELECT mobile FROM sms_logs'.$whereSql.' GROUP BY mobile) grouped_numbers');
        $countStmt->execute($params);
        $total=(int)$countStmt->fetchColumn();
        $pages=max(1,(int)ceil($total/$per));
        $page=min($page,$pages);
        $offset=($page-1)*$per;

        $query="SELECT grouped.*,latest.status last_status,latest.event_key last_event,latest.response_message last_response,
                       latest.delivery_state last_delivery_state,latest.delivery_status last_delivery_status,latest.delivery_reason last_delivery_reason,latest.delivery_checked_at last_delivery_checked_at,
                       latest.reference_type last_reference_type,latest.reference_id last_reference_id
                FROM (
                    SELECT mobile,COUNT(*) total_count,
                           SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) sent_count,
                           SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed_count,
                           SUM(CASE WHEN status='skipped' THEN 1 ELSE 0 END) skipped_count,
                           SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending_count,
                           MAX(created_at) last_attempt_at,MAX(sent_at) last_sent_at,MAX(id) last_id
                    FROM sms_logs".$whereSql."
                    GROUP BY mobile
                ) grouped
                JOIN sms_logs latest ON latest.id=grouped.last_id
                ORDER BY grouped.last_attempt_at DESC,grouped.mobile DESC
                LIMIT ".$per.' OFFSET '.$offset;
        $stmt=db()->prepare($query);
        $stmt->execute($params);
        $rows=$stmt->fetchAll();
    }catch(Throwable $exception){
        log_error($exception);
        flash('error','خواندن گزارش شماره‌های پیامک ناموفق بود. ساختار جدول sms_logs را از بخش ارتقا بررسی کنید.');
    }
}else{
    $where=[$dateSql,"TRIM(COALESCE(o.phone,''))<>''"];
    $params=[];
    if($searchRaw!==''){
        $where[]='(o.phone LIKE ? OR o.order_number LIKE ? OR o.customer_name LIKE ?)';
        $needle='%'.($searchDigits!==''?$searchDigits:$searchRaw).'%';
        $params[]=$needle;$params[]=$needle;$params[]='%'.$searchRaw.'%';
    }
    $whereSql=' WHERE '.implode(' AND ',array_map(static fn(string $part):string=>str_replace('created_at','o.created_at',$part),$where));
    try{
        $countStmt=db()->prepare('SELECT COUNT(*) FROM orders o'.$whereSql);
        $countStmt->execute($params);
        $total=(int)$countStmt->fetchColumn();
        $pages=max(1,(int)ceil($total/$per));
        $page=min($page,$pages);
        $offset=($page-1)*$per;

        $query="SELECT o.id,o.order_number,o.customer_name,o.phone,o.payment_status,o.order_status,o.created_at,
                  (SELECT s.status FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='order_created' ORDER BY s.id DESC LIMIT 1) order_sms_status,
                  (SELECT s.response_message FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='order_created' ORDER BY s.id DESC LIMIT 1) order_sms_message,
                  (SELECT s.delivery_status FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='order_created' ORDER BY s.id DESC LIMIT 1) order_delivery_status,
                  (SELECT s.delivery_reason FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='order_created' ORDER BY s.id DESC LIMIT 1) order_delivery_reason,
                  (SELECT s.status FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='payment_status_paid' ORDER BY s.id DESC LIMIT 1) payment_sms_status,
                  (SELECT s.response_message FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='payment_status_paid' ORDER BY s.id DESC LIMIT 1) payment_sms_message,
                  (SELECT s.delivery_status FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='payment_status_paid' ORDER BY s.id DESC LIMIT 1) payment_delivery_status,
                  (SELECT s.delivery_reason FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='payment_status_paid' ORDER BY s.id DESC LIMIT 1) payment_delivery_reason
                FROM orders o".$whereSql.' ORDER BY o.id DESC LIMIT '.$per.' OFFSET '.$offset;
        $stmt=db()->prepare($query);
        $stmt->execute($params);
        $rows=$stmt->fetchAll();

        $orderStatsStmt=db()->prepare("SELECT COUNT(*) numbers_count,
            SUM(CASE WHEN EXISTS(SELECT 1 FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='order_created' AND s.status='sent') THEN 1 ELSE 0 END) sent_count,
            SUM(CASE WHEN NOT EXISTS(SELECT 1 FROM sms_logs s WHERE s.reference_type='order' AND s.reference_id=o.id AND s.event_key='order_created' AND s.status='sent') THEN 1 ELSE 0 END) failed_count
            FROM orders o".$whereSql);
        $orderStatsStmt->execute($params);
        $orderStats=$orderStatsStmt->fetch()?:[];
        $summary['numbers_count']=(int)($orderStats['numbers_count']??0);
        $summary['sent_count']=(int)($orderStats['sent_count']??0);
        $summary['failed_count']=(int)($orderStats['failed_count']??0);
    }catch(Throwable $exception){
        log_error($exception);
        flash('error','خواندن کنترل پیامک سفارش‌ها ناموفق بود.');
    }
}

$queryBase=['view'=>$view,'period'=>$period,'q'=>$searchRaw];
require __DIR__.'/partials/header.php';
?>
<div class="admin-title">
    <div><h1>کنترل پیامک شماره‌ها</h1><p class="muted">برای هر شماره علاوه بر پذیرش اولیه، نتیجه تحویل اپراتور، علت نرسیدن و راهکار پیشنهادی نمایش داده می‌شود.</p></div>
    <div class="actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-logs.php'))?>">جزئیات همه ارسال‌ها</a><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-settings.php'))?>">تنظیمات پیامک</a></div>
</div>

<div class="actions" style="margin-bottom:14px">
    <a class="btn <?=$view==='numbers'?'btn-primary':'btn-secondary'?>" href="?view=numbers&period=<?=e($period)?>&q=<?=e(rawurlencode($searchRaw))?>">گزارش بر اساس شماره</a>
    <a class="btn <?=$view==='orders'?'btn-primary':'btn-secondary'?>" href="?view=orders&period=<?=e($period)?>&q=<?=e(rawurlencode($searchRaw))?>">کنترل پیامک سفارش‌ها</a>
</div>

<div class="stats">
    <div class="card stat"><strong><?=fa_number((int)$summary['numbers_count'])?></strong><span><?=$view==='numbers'?'شماره یکتا':'سفارش دارای شماره'?></span></div>
    <div class="card stat"><strong><?=fa_number((int)$summary['sent_count'])?></strong><span><?=$view==='numbers'?'ارسال موفق':'ثبت سفارش پیامک‌شده'?></span></div>
    <div class="card stat"><strong><?=fa_number((int)$summary['failed_count'])?></strong><span><?=$view==='numbers'?'ناموفق':'بدون ارسال موفق'?></span></div>
    <?php if($view==='numbers'):?><div class="card stat"><strong><?=fa_number((int)$summary['skipped_count'])?></strong><span>Sandbox / غیرفعال</span></div><div class="card stat"><strong><?=fa_number((int)$summary['pending_count'])?></strong><span>در انتظار</span></div><?php endif?>
</div>

<form class="filters-admin" method="get">
    <input type="hidden" name="view" value="<?=e($view)?>">
    <input name="q" value="<?=e($searchRaw)?>" placeholder="جست‌وجوی شماره<?= $view==='orders'?'، سفارش یا مشتری':'' ?>" inputmode="search">
    <select name="period"><option value="today" <?=$period==='today'?'selected':''?>>امروز</option><option value="7" <?=$period==='7'?'selected':''?>>۷ روز اخیر</option><option value="30" <?=$period==='30'?'selected':''?>>۳۰ روز اخیر</option><option value="all" <?=$period==='all'?'selected':''?>>همه زمان‌ها</option></select>
    <button class="btn btn-secondary">اعمال فیلتر</button>
    <?php if($searchRaw!==''||$period!=='30'):?><a class="btn btn-secondary" href="?view=<?=e($view)?>">پاک‌کردن</a><?php endif?>
</form>
<p class="muted">بازه گزارش: <?=e($periodLabel)?></p>

<?php if($view==='numbers'):?>
<div class="table-wrap"><table><thead><tr><th>شماره موبایل</th><th>کل تلاش‌ها</th><th>ارسال‌شده</th><th>ارسال‌نشده</th><th>ناموفق</th><th>Sandbox/خاموش</th><th>در انتظار</th><th>آخرین تلاش</th><th>آخرین نتیجه</th><th>جزئیات</th></tr></thead><tbody>
<?php foreach($rows as $row):$notSent=(int)$row['failed_count']+(int)$row['skipped_count']+(int)$row['pending_count'];?><tr>
<td dir="ltr"><strong><?=e((string)$row['mobile'])?></strong></td>
<td><?=fa_number((int)$row['total_count'])?></td>
<td><span class="badge green"><?=fa_number((int)$row['sent_count'])?></span></td>
<td><span class="badge <?=$notSent>0?'red':'green'?>"><?=fa_number($notSent)?></span></td>
<td><?=fa_number((int)$row['failed_count'])?></td><td><?=fa_number((int)$row['skipped_count'])?></td><td><?=fa_number((int)$row['pending_count'])?></td>
<td><?=e(jalali_format((string)$row['last_attempt_at']))?></td>
<td><span class="badge <?=e(sms_recipient_status_class((string)$row['last_status']))?>"><?=e(sms_recipient_status_label((string)$row['last_status']))?></span><?php if((string)$row['last_status']==='sent'):$deliveryInfo=sms_delivery_state_info($row['last_delivery_state']!==null?(int)$row['last_delivery_state']:null);?><br><span class="badge <?=e(sms_delivery_badge_class((string)($row['last_delivery_status']??$deliveryInfo['status'])))?>"><?=e($deliveryInfo['label'])?></span><br><small><?=e((string)($row['last_delivery_reason']?:$deliveryInfo['reason']))?></small><?php else:?><br><small><?=e(sms_recipient_event_label((string)$row['last_event']))?><?php if(!empty($row['last_response'])):?> · <?=e(text_substr((string)$row['last_response'],0,80))?><?php endif?></small><?php endif?></td>
<td><a class="btn btn-secondary btn-sm" href="<?=e(app_url('admin/sms-logs.php?mobile='.rawurlencode((string)$row['mobile'])))?>">نمایش همه</a></td>
</tr><?php endforeach?>
<?php if(!$rows):?><tr><td colspan="10">برای این بازه یا شماره، هیچ گزارش پیامکی ثبت نشده است.</td></tr><?php endif?></tbody></table></div>
<?php else:?>
<div class="table-wrap"><table><thead><tr><th>سفارش</th><th>شماره موبایل</th><th>مشتری</th><th>زمان خرید</th><th>وضعیت پرداخت</th><th>پیامک ثبت سفارش</th><th>پیامک پرداخت موفق</th><th>جزئیات</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr>
<td><a href="<?=e(app_url('admin/order.php?id='.(int)$row['id']))?>"><strong><?=e((string)$row['order_number'])?></strong></a></td>
<td dir="ltr"><strong><?=e((string)$row['phone'])?></strong></td><td><?=e((string)$row['customer_name'])?></td><td><?=e(jalali_format((string)$row['created_at']))?></td>
<td><?=e(function_exists('payment_status_label')?payment_status_label((string)$row['payment_status']):(string)$row['payment_status'])?></td>
<td><span class="badge <?=e(sms_recipient_status_class($row['order_sms_status']!==null?(string)$row['order_sms_status']:null))?>"><?=e(sms_recipient_status_label($row['order_sms_status']!==null?(string)$row['order_sms_status']:null))?></span><?php if((string)($row['order_sms_status']??'')==='sent'):$deliveryStatus=(string)($row['order_delivery_status']??'');$deliveryInfo=sms_delivery_status_info($deliveryStatus);?><br><span class="badge <?=e(sms_delivery_badge_class($deliveryStatus!==''?$deliveryStatus:$deliveryInfo['status']))?>"><?=e($deliveryInfo['label'])?></span><?php if(!empty($row['order_delivery_reason'])):?><br><small><?=e($row['order_delivery_reason'])?></small><?php endif?><?php elseif(!empty($row['order_sms_message'])):?><br><small><?=e(text_substr((string)$row['order_sms_message'],0,100))?></small><?php endif?></td>
<td><?php if((string)$row['payment_status']!=='paid'):?><span class="badge orange">هنوز پرداخت موفق نیست</span><?php else:?><span class="badge <?=e(sms_recipient_status_class($row['payment_sms_status']!==null?(string)$row['payment_sms_status']:null))?>"><?=e(sms_recipient_status_label($row['payment_sms_status']!==null?(string)$row['payment_sms_status']:null))?></span><?php if((string)($row['payment_sms_status']??'')==='sent'):$deliveryStatus=(string)($row['payment_delivery_status']??'');$deliveryInfo=sms_delivery_status_info($deliveryStatus);?><br><span class="badge <?=e(sms_delivery_badge_class($deliveryStatus!==''?$deliveryStatus:'pending'))?>"><?=e($deliveryInfo['label'])?></span><?php if(!empty($row['payment_delivery_reason'])):?><br><small><?=e($row['payment_delivery_reason'])?></small><?php endif?><?php elseif(!empty($row['payment_sms_message'])):?><br><small><?=e(text_substr((string)$row['payment_sms_message'],0,100))?></small><?php endif?><?php endif?></td>
<td><a class="btn btn-secondary btn-sm" href="<?=e(app_url('admin/sms-logs.php?mobile='.rawurlencode((string)$row['phone'])))?>">گزارش شماره</a></td>
</tr><?php endforeach?>
<?php if(!$rows):?><tr><td colspan="8">سفارش دارای شماره موبایل در این بازه پیدا نشد.</td></tr><?php endif?></tbody></table></div>
<?php endif?>

<?php if($pages>1):?><div class="pagination"><?php for($i=1;$i<=$pages;$i++):$link=$queryBase;$link['page']=$i;?><a class="<?=$i===$page?'active':''?>" href="?<?=e(http_build_query($link))?>"><?=fa_number($i)?></a><?php endfor?></div><?php endif?>
<?php require __DIR__.'/partials/footer.php';
