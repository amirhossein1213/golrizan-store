<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
$token=trim(scalar_string($_GET['token']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$token)){http_response_code(404);exit('سفارش پیدا نشد.');}
$stmt=db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');$stmt->execute([$token]);$order=$stmt->fetch();
if(!$order){http_response_code(404);exit('سفارش پیدا نشد.');}
if((string)$order['payment_method']!=='snappay'){redirect(app_url('order-success.php?token='.$token));}
if((string)$order['payment_status']==='paid'){redirect(app_url('order-success.php?token='.$token));}
try{
    $url=snappay_start_for_order($order);
    redirect($url);
}catch(Throwable $e){
    log_error($e);
    $pageKey='payment';$pageTitle='خطا در شروع اسنپ‌پی | '.setting('store_name');$robots='noindex,nofollow';
    require BASE_PATH.'/app/views/header.php';
    ?><main><div class="payment-result error"><h1>اتصال به اسنپ‌پی انجام نشد</h1><p><?=e($e->getMessage())?></p><a class="btn btn-primary" href="<?=e(app_url('snappay-start.php?token='.$token))?>">تلاش دوباره</a><a class="btn btn-secondary" href="<?=e(app_url('order-success.php?token='.$token))?>">مشاهده سفارش</a></div></main><?php
    require BASE_PATH.'/app/views/footer.php';
}
