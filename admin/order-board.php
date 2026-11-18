<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';admin_required();
$stages=order_workflow_stages();$stageKeys=array_keys($stages);$ordersByStage=array_fill_keys($stageKeys,[]);
$stmt=db()->query("SELECT o.id,o.order_number,o.customer_name,o.phone,o.total,o.order_status,o.payment_status,o.priority,o.created_at,o.assigned_admin_id,o.tracking_code,a.full_name assigned_name,s.status shipment_status FROM orders o LEFT JOIN admins a ON a.id=o.assigned_admin_id LEFT JOIN shipments s ON s.order_id=o.id WHERE o.order_status NOT IN ('cancelled','refunded','returned','return_requested','return_approved') ORDER BY FIELD(o.priority,'urgent','high','normal'),o.id DESC LIMIT 300");
foreach($stmt->fetchAll() as $order){$stage=order_workflow_stage((string)$order['order_status']);if(isset($ordersByStage[$stage]))$ordersByStage[$stage][]=$order;}
$adminPage='order-board';$adminTitle='تابلوی سفارش‌ها';require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1>تابلوی سفارش‌ها</h1><p class="muted">کارت را میان ستون‌ها جابه‌جا کن. تغییر فقط زمانی ثبت می‌شود که انتقال وضعیت از نظر سیستم مجاز باشد.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/orders.php'))?>">نمای جدولی</a><label class="order-board-sms"><input type="checkbox" data-order-board-send-sms checked> ارسال فوری پیامک مشتری</label></div></div>
<div class="order-board" data-order-board data-order-action-endpoint="<?=e(app_url('admin/order-action.php'))?>" data-order-csrf="<?=e(csrf_token())?>">
<?php foreach($stages as $stageKey=>$stage):?>
<section class="order-board__column" data-order-stage="<?=e($stageKey)?>" data-order-target="<?=e($stage['target'])?>">
<div class="order-board__column-head"><h2><?=e($stage['label'])?></h2><span data-order-stage-count><?=fa_number(count($ordersByStage[$stageKey]))?></span></div>
<div class="order-board__dropzone" data-order-dropzone>
<?php foreach($ordersByStage[$stageKey] as $order):?>
<article class="order-board-card" draggable="true" data-order-card data-order-id="<?=(int)$order['id']?>" data-order-status="<?=e($order['order_status'])?>" data-order-tracking="<?=e($order['tracking_code']??'')?>">
<div class="order-board-card__head"><h3><a href="<?=e(app_url('admin/order.php?id='.(int)$order['id']))?>"><?=e($order['order_number'])?></a></h3><span class="order-priority order-priority--<?=e($order['priority'])?>"><?=e(order_priority_label((string)$order['priority']))?></span></div>
<p><?=e($order['customer_name'])?><br><span dir="ltr"><?=e($order['phone'])?></span></p>
<div class="order-board-card__meta"><span><?=fa_number(format_price((int)$order['total']))?> تومان</span><span><?=e(payment_status_label((string)$order['payment_status']))?></span><span><?=e(order_status_label((string)$order['order_status']))?></span><?php if($order['assigned_name']):?><span><?=e($order['assigned_name'])?></span><?php endif?></div>
</article>
<?php endforeach?>
</div></section>
<?php endforeach?>
</div>
<div class="flash error" data-order-board-message hidden></div>
<?php require __DIR__.'/partials/footer.php';
