<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';admin_required();
$raw=scalar_string($_GET['ids']??'');$ids=array_values(array_unique(array_filter(array_map('intval',explode(',',$raw)),static fn(int $id):bool=>$id>0)));$ids=array_slice($ids,0,50);
$mode=scalar_string($_GET['mode']??'invoice');if(!in_array($mode,['invoice','label'],true))$mode='invoice';
if(!$ids){http_response_code(422);exit('هیچ سفارشی انتخاب نشده است.');}
$marks=implode(',',array_fill(0,count($ids),'?'));$stmt=db()->prepare("SELECT o.*,a.full_name assigned_name FROM orders o LEFT JOIN admins a ON a.id=o.assigned_admin_id WHERE o.id IN ($marks) ORDER BY o.id");$stmt->execute($ids);$orders=$stmt->fetchAll();
$itemsStmt=db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
$adminPage='orders';$adminTitle=$mode==='label'?'برچسب ارسال':'چاپ سفارش‌ها';require __DIR__.'/partials/header.php';
?>
<div class="admin-title no-print"><h1><?=$mode==='label'?'برچسب‌های ارسال':'چاپ فاکتور سفارش‌ها'?></h1><div class="admin-actions"><button class="btn btn-primary" onclick="window.print()">چاپ</button><a class="btn btn-secondary" href="<?=e(app_url('admin/orders.php'))?>">بازگشت</a></div></div>
<?php foreach($orders as $index=>$order):$itemsStmt->execute([(int)$order['id']]);$items=$itemsStmt->fetchAll();?>
<section class="card print-order <?=$index<count($orders)-1?'print-page-break':''?>">
<?php if($mode==='label'):?>
<h1>سفارش <?=e($order['order_number'])?></h1><div class="order-meta"><div><strong>گیرنده</strong><br><?=e($order['customer_name'])?><br><span dir="ltr"><?=e($order['phone'])?></span></div><div><strong>نشانی</strong><br><?=e($order['province'].'، '.$order['city'])?><br><?=nl2br(e($order['address']))?><br>کدپستی: <?=e($order['postal_code'])?></div><div><strong>ارسال</strong><br><?=e(order_status_label((string)$order['order_status']))?><br>کد رهگیری: <?=e($order['tracking_code']?:'—')?></div></div>
<?php else:?>
<div class="admin-title"><div><h1><?=e(setting('store_name','فروشگاه گلریزان'))?></h1><p>سفارش <?=e($order['order_number'])?> · <?=e(jalali_format($order['created_at']))?></p></div><div><strong><?=e($order['customer_name'])?></strong><br><span dir="ltr"><?=e($order['phone'])?></span></div></div>
<div class="table-wrap"><table><thead><tr><th>کالا</th><th>مدل / ویژگی انتخابی</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody><?php foreach($items as $item):$optionLabel=order_item_option_label($item);?><tr><td><?=e($item['title'])?></td><td><?=e($optionLabel?:'—')?></td><td><?=fa_number($item['quantity'])?></td><td><?=fa_number(format_price((int)$item['unit_price']))?></td><td><?=fa_number(format_price((int)$item['line_total']))?></td></tr><?php endforeach?><tr><td colspan="4"><strong>مبلغ نهایی</strong></td><td><strong><?=fa_number(format_price((int)$order['total']))?> تومان</strong></td></tr></tbody></table></div>
<p><strong>نشانی:</strong> <?=e($order['province'].'، '.$order['city'].'، '.$order['address'])?> · کدپستی <?=e($order['postal_code'])?></p>
<?php endif?>
</section>
<?php endforeach?>
<?php require __DIR__.'/partials/footer.php';
