<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';admin_required();

function admin_orders_selected_ids(mixed $input): array
{
    if(!is_array($input))return [];
    return array_slice(array_values(array_unique(array_filter(array_map('intval',$input),static fn(int $id):bool=>$id>0))),0,100);
}
function admin_orders_return_url(): string
{
    $query=scalar_string($_POST['return_query']??'');
    if($query!==''&&strlen($query)<=1000&&preg_match('/^[A-Za-z0-9_=&%+.-]*$/',$query))return app_url('admin/orders.php?'.$query);
    return app_url('admin/orders.php');
}

if(is_post()){
    verify_csrf();$action=scalar_string($_POST['action']??'');
    try{
        if($action==='quick_status'){
            $id=max(0,scalar_int($_POST['id']??0));$status=scalar_string($_POST['status']??'');
            $stmt=db()->prepare('SELECT payment_status,tracking_code FROM orders WHERE id=?');$stmt->execute([$id]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('سفارش پیدا نشد.');
            $sendSms=isset($_POST['send_sms']);
            if($status==='shipped'){
                $tracking=text_substr(trim(scalar_string($_POST['tracking_code']??'')),0,150);
                $result=admin_mark_order_shipped($id,$tracking,null,'ثبت ارسال از فهرست سفارش‌ها',$sendSms);
                flash('success','سفارش ارسال‌شده و کد رهگیری ثبت شد.');
                if($sendSms&&is_array($result['sms']??null))sms_admin_flash_immediate_result($result['sms'],'پیامک کد رهگیری');
            }else{
                $result=admin_update_order_status($id,$status,(string)$order['payment_status'],'تغییر سریع از فهرست سفارش‌ها',$sendSms,(string)($order['tracking_code']??''));
                flash('success','وضعیت سفارش به «'.order_status_label($status).'» تغییر کرد.');
                if($sendSms&&is_array($result['order_sms']??null))sms_admin_flash_immediate_result($result['order_sms'],'پیامک تغییر وضعیت سفارش');
            }
        }elseif($action==='bulk_status'){
            $ids=admin_orders_selected_ids($_POST['selected']??[]);$status=scalar_string($_POST['bulk_status']??'');if(!$ids)throw new RuntimeException('حداقل یک سفارش را انتخاب کنید.');if(!isset(order_status_options()[$status]))throw new RuntimeException('وضعیت گروهی معتبر نیست.');
            $success=0;$failed=0;$messages=[];foreach($ids as $id){try{admin_advance_order_to_status($id,$status,'تغییر گروهی وضعیت سفارش',isset($_POST['send_sms']));$success++;}catch(Throwable $e){$failed++;if(count($messages)<3)$messages[]='#'.$id.': '.$e->getMessage();}}
            flash($success>0?'success':'error',fa_number($success).' سفارش تغییر وضعیت یافت'.($failed?' و '.fa_number($failed).' سفارش انجام نشد.':'.'));
            if($messages)flash('error',implode(' | ',$messages));
        }elseif($action==='bulk_assign'){
            $ids=admin_orders_selected_ids($_POST['selected']??[]);if(!$ids)throw new RuntimeException('حداقل یک سفارش را انتخاب کنید.');$assigned=max(0,scalar_int($_POST['assigned_admin_id']??0));$assigned=$assigned>0?$assigned:null;
            if(!admin_order_admin_is_assignable($assigned))throw new RuntimeException('مدیر انتخاب‌شده معتبر نیست.');
            $priority=scalar_string($_POST['priority']??'normal');if(!isset(order_priority_options()[$priority]))throw new RuntimeException('اولویت انتخاب‌شده معتبر نیست.');
            $success=0;foreach($ids as $id){try{$stmt=db()->prepare('SELECT admin_internal_note FROM orders WHERE id=?');$stmt->execute([$id]);$note=(string)($stmt->fetchColumn()?:'');admin_update_order_meta($id,$assigned,$priority,$note);$success++;}catch(Throwable $e){log_error($e);}}
            flash('success',fa_number($success).' سفارش به‌روزرسانی شد.');
        }
    }catch(Throwable $e){log_error($e);flash('error',$e->getMessage());}
    redirect(admin_orders_return_url());
}

$adminPage='orders';$adminTitle='سفارش‌ها';
$status=scalar_string($_GET['status']??'');$payment=scalar_string($_GET['payment']??'');$priority=scalar_string($_GET['priority']??'');$assigned=max(0,scalar_int($_GET['assigned']??0));$city=text_substr(trim(scalar_string($_GET['city']??'')),0,100);$fromInput=trim(scalar_string($_GET['from']??''));$toInput=trim(scalar_string($_GET['to']??''));$from=$fromInput!==''?jalali_parse_date($fromInput):null;$to=$toInput!==''?jalali_parse_date($toInput):null;$minTotal=max(0,scalar_int(normalize_digits(scalar_string($_GET['min_total']??'0'))));$maxTotal=max(0,scalar_int(normalize_digits(scalar_string($_GET['max_total']??'0'))));$q=text_substr(trim(scalar_string($_GET['q']??'')),0,100);$page=max(1,min(100000,scalar_int($_GET['page']??1,1)));$perPage=50;$where=[];$params=[];
if(isset(order_status_options()[$status])){$where[]='o.order_status=?';$params[]=$status;}else$status='';
if(isset(payment_status_options()[$payment])){$where[]='o.payment_status=?';$params[]=$payment;}else$payment='';
if(isset(order_priority_options()[$priority])){$where[]='o.priority=?';$params[]=$priority;}else$priority='';
if($assigned>0){$where[]='o.assigned_admin_id=?';$params[]=$assigned;}
if($city!==''){$where[]='o.city LIKE ?';$params[]='%'.$city.'%';}
if($from!==null){$where[]='o.created_at>=?';$params[]=$from.' 00:00:00';$fromInput=jalali_date_format($from);}
if($to!==null){$where[]='o.created_at<=?';$params[]=$to.' 23:59:59';$toInput=jalali_date_format($to);}
if($minTotal>0){$where[]='o.total>=?';$params[]=$minTotal;}
if($maxTotal>0){$where[]='o.total<=?';$params[]=$maxTotal;}
if($q!==''){$where[]='(o.order_number LIKE ? OR o.phone LIKE ? OR o.customer_name LIKE ? OR o.tracking_code LIKE ? OR EXISTS (SELECT 1 FROM payment_transactions spt WHERE spt.order_id=o.id AND spt.gateway=\'snappay\' AND (spt.reference_id LIKE ? OR spt.request_json LIKE ?)))';$like='%'.$q.'%';array_push($params,$like,$like,$like,$like,$like,$like);}
$whereSql=$where?' WHERE '.implode(' AND ',$where):'';
$count=db()->prepare('SELECT COUNT(*) FROM orders o'.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
$stmt=db()->prepare('SELECT o.*,a.full_name assigned_name,s.status shipment_status,(SELECT spt.reference_id FROM payment_transactions spt WHERE spt.order_id=o.id AND spt.gateway=\'snappay\' AND spt.reference_id IS NOT NULL AND spt.reference_id<>\'\' ORDER BY spt.id DESC LIMIT 1) snappay_transaction_id FROM orders o LEFT JOIN admins a ON a.id=o.assigned_admin_id LEFT JOIN shipments s ON s.order_id=o.id'.$whereSql.' ORDER BY FIELD(o.priority,\'urgent\',\'high\',\'normal\'),o.id DESC LIMIT '.$perPage.' OFFSET '.$offset);$stmt->execute($params);$orders=$stmt->fetchAll();$assignableAdmins=admin_order_assignable_admins();$returnQuery=http_build_query(['q'=>$q,'status'=>$status,'payment'=>$payment,'priority'=>$priority,'assigned'=>$assigned,'city'=>$city,'from'=>$fromInput,'to'=>$toInput,'min_total'=>$minTotal?:'','max_total'=>$maxTotal?:'','page'=>$page]);
$counts=['unassigned'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE assigned_admin_id IS NULL AND order_status NOT IN ('delivered','installed','cancelled','refunded','returned')")->fetchColumn(),'stale'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('new','pending_payment','reviewing','sourcing','confirmed','preparing') AND created_at<DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn(),'ready'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE order_status='ready_to_ship'")->fetchColumn()];
require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1>مدیریت سفارش‌ها</h1><p class="muted"><?=fa_number($total)?> سفارش · <?=fa_number($counts['unassigned'])?> بدون مسئول · <?=fa_number($counts['stale'])?> معطل بیش از ۲۴ ساعت</p></div><div class="admin-actions"><a class="btn btn-primary" href="<?=e(app_url('admin/order-board.php'))?>">نمای ستونی</a></div></div>
<form class="filters-admin"><input name="q" maxlength="100" value="<?=e($q)?>" placeholder="شماره سفارش، نام، موبایل، رهگیری یا Transaction ID اسنپ‌پی"><input name="city" maxlength="100" value="<?=e($city)?>" placeholder="شهر"><input type="text" name="from" value="<?=e($fromInput)?>" data-jalali-date placeholder="از تاریخ"><input type="text" name="to" value="<?=e($toInput)?>" data-jalali-date placeholder="تا تاریخ"><input name="min_total" inputmode="numeric" value="<?=$minTotal>0?e((string)$minTotal):''?>" placeholder="حداقل مبلغ"><input name="max_total" inputmode="numeric" value="<?=$maxTotal>0?e((string)$maxTotal):''?>" placeholder="حداکثر مبلغ"><select name="status"><option value="">همه وضعیت‌های سفارش</option><?php foreach(order_status_options() as $key=>$label):?><option value="<?=e($key)?>" <?=$status===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><select name="payment"><option value="">همه وضعیت‌های پرداخت</option><?php foreach(payment_status_options() as $key=>$label):?><option value="<?=e($key)?>" <?=$payment===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><select name="priority"><option value="">همه اولویت‌ها</option><?php foreach(order_priority_options() as $key=>$label):?><option value="<?=e($key)?>" <?=$priority===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><select name="assigned"><option value="0">همه مدیران</option><?php foreach($assignableAdmins as $adminOption):?><option value="<?=(int)$adminOption['id']?>" <?=$assigned===(int)$adminOption['id']?'selected':''?>><?=e($adminOption['full_name'])?></option><?php endforeach?></select><button class="btn btn-secondary">فیلتر</button></form>
<form id="bulk-orders-form" method="post" data-order-bulk-form>
<?=csrf_field()?><input type="hidden" name="return_query" value="<?=e($returnQuery)?>">
<div class="card order-toolbar">
<strong data-order-selected-count>هیچ سفارشی انتخاب نشده است.</strong>
<select name="bulk_status"><option value="">تغییر وضعیت به…</option><?php foreach(order_status_options() as $key=>$label):if(in_array($key,['handed_to_carrier','shipped'],true))continue;?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach?></select>
<label><input type="checkbox" name="send_sms" value="1" checked> پیامک پس از تکمیل پرداخت</label>
<button class="btn btn-secondary" name="action" value="bulk_status" data-order-bulk-status disabled>اعمال وضعیت</button>
<select name="assigned_admin_id"><option value="0">بدون مسئول</option><?php foreach($assignableAdmins as $adminOption):?><option value="<?=(int)$adminOption['id']?>"><?=e($adminOption['full_name'])?></option><?php endforeach?></select>
<select name="priority"><?php foreach(order_priority_options() as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach?></select>
<button class="btn btn-secondary" name="action" value="bulk_assign" data-order-bulk-assign disabled>واگذاری/اولویت</button>
<button class="btn btn-secondary" type="button" data-order-print="invoice" disabled>چاپ فاکتور</button><button class="btn btn-secondary" type="button" data-order-print="label" disabled>چاپ برچسب</button>
</div>
</form>
<div class="table-wrap"><table><thead><tr><th><input class="order-checkbox" type="checkbox" data-order-select-all aria-label="انتخاب همه"></th><th>شماره و مشتری</th><th>مبلغ و پرداخت</th><th>گردش سفارش</th><th>مسئول و اولویت</th><th>زمان</th><th>اقدام سریع</th></tr></thead><tbody>
<?php foreach($orders as $o):$isStale=in_array((string)$o['order_status'],['new','pending_payment','reviewing','sourcing','confirmed','preparing'],true)&&strtotime((string)$o['created_at'])<time()-86400;$next=order_next_statuses((string)$o['order_status']);if((string)$o['payment_method']==='snappay'&&in_array((string)$o['payment_status'],['paid','partially_refunded'],true))unset($next['cancelled']);if(in_array((string)$o['order_status'],['confirmed','preparing','ready_to_ship','handed_to_carrier'],true))$next['shipped']='ارسال و ثبت کد رهگیری';?>
<tr data-order-row><td><input class="order-checkbox" type="checkbox" name="selected[]" value="<?=(int)$o['id']?>" data-order-select form="bulk-orders-form"></td><td><a href="<?=e(app_url('admin/order.php?id='.(int)$o['id']))?>"><strong><?=e($o['order_number'])?></strong></a><br><?=e($o['customer_name'])?> · <small dir="ltr"><?=e($o['phone'])?></small><?php if($o['tracking_code']):?><br><small>رهگیری: <?=e($o['tracking_code'])?></small><?php endif?></td><td><strong><?=fa_number(format_price((int)$o['total']))?></strong><br><span class="badge <?=$o['payment_status']==='paid'&&$o['order_status']!=='cancelled'?'green':''?>"><?=e(order_payment_status_label($o))?></span><?php if(!empty($o['snappay_transaction_id'])):?><br><small>SnappPay TX: <span dir="ltr"><?=e($o['snappay_transaction_id'])?></span></small><?php endif?></td><td><span class="badge"><?=e(order_status_label((string)$o['order_status']))?></span><?php if($o['shipment_status']):?><br><small>ارسال: <?=e(shipment_status_labels()[$o['shipment_status']]??$o['shipment_status'])?></small><?php endif?></td><td><?=e($o['assigned_name']?:'بدون مسئول')?><br><span class="order-priority order-priority--<?=e($o['priority'])?>"><?=e(order_priority_label((string)$o['priority']))?></span></td><td><?=e(jalali_format($o['created_at']))?><?php if($isStale):?><br><span class="order-stale">نیازمند پیگیری</span><?php endif?></td><td><?php if($next):?><form method="post" class="order-inline-action" data-order-quick-status-form data-current-tracking="<?=e($o['tracking_code']??'')?>"><?=csrf_field()?><input type="hidden" name="return_query" value="<?=e($returnQuery)?>"><input type="hidden" name="action" value="quick_status"><input type="hidden" name="id" value="<?=(int)$o['id']?>"><input type="hidden" name="send_sms" value="1"><input type="hidden" name="tracking_code" value=""><select name="status"><?php foreach($next as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach?></select><button class="btn btn-primary btn-sm">ثبت</button></form><?php else:?>—<?php endif?></td></tr>
<?php endforeach?><?php if(!$orders):?><tr><td colspan="7">سفارشی پیدا نشد.</td></tr><?php endif?></tbody></table></div>
<?php if($pages>1):?><nav class="pagination"><?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++):?><a class="<?=$i===$page?'active':''?>" href="?<?=e(http_build_query(['q'=>$q,'status'=>$status,'payment'=>$payment,'priority'=>$priority,'assigned'=>$assigned,'city'=>$city,'from'=>$fromInput,'to'=>$toInput,'min_total'=>$minTotal?:'','max_total'=>$maxTotal?:'','page'=>$i]))?>"><?=fa_number($i)?></a><?php endfor?></nav><?php endif?>
<?php require __DIR__.'/partials/footer.php';
