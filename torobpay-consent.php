<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
$token=trim(scalar_string($_GET['token']??$_POST['token']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$token)){http_response_code(404);exit('سفارش پیدا نشد.');}
$stmt=db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');$stmt->execute([$token]);$order=$stmt->fetch();
if(!$order){http_response_code(404);exit('سفارش پیدا نشد.');}
if((string)$order['payment_method']!=='torobpay'){redirect(app_url('order-success.php?token='.$token));}
if((string)$order['payment_status']==='paid'){redirect(app_url('order-success.php?token='.$token));}
if(!in_array((string)$order['order_status'],['new','pending_payment'],true)){redirect(app_url('order-success.php?token='.$token));}

$markupPercent=(float)($order['payment_markup_percent']??0);
$markupAmount=max(0,(int)($order['payment_markup_amount']??0));
$subtotal=max(0,(int)($order['subtotal']??0));
$normalProductsTotal=max(0,$subtotal-$markupAmount);
$discount=max(0,(int)($order['discount_amount']??0));
$total=max(0,(int)($order['total']??0));
$percentLabel=rtrim(rtrim(number_format($markupPercent,2,'.',''),'0'),'.');

// اگر روی این سفارش افزایش قیمتی ثبت نشده، صفحه پذیرش لازم نیست.
if($markupPercent<=0 || $markupAmount<=0){redirect(app_url('torobpay-start.php?token='.$token));}

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=trim(scalar_string($_POST['action']??'accept_markup'));
    if($action==='cancel_order'){
        try{
            customer_cancel_unpaid_torobpay_order($token);
            $gates=is_array($_SESSION['_torobpay_start_consent']??null)?$_SESSION['_torobpay_start_consent']:[];
            unset($gates[$token]);$_SESSION['_torobpay_start_consent']=$gates;
            flash('success','سفارش با درخواست شما لغو شد و هیچ پرداخت موفقی برای آن ثبت نشد.');
            redirect(app_url('order-success.php?token='.$token));
        }catch(Throwable $e){
            log_error($e);$error=$e instanceof RuntimeException?$e->getMessage():'لغو سفارش انجام نشد. دوباره تلاش کنید.';
        }
    }elseif($action!=='accept_markup'||(string)($_POST['accept_markup']??'')!=='1'){
        $error='برای ادامه، باید افزایش قیمت خرید اقساطی را تأیید کنید.';
    }else{
        try{
            $pdo=db();$pdo->beginTransaction();
            $lock=$pdo->prepare('SELECT id,payment_method,payment_status,order_status,payment_markup_percent,payment_markup_amount FROM orders WHERE public_token=? LIMIT 1 FOR UPDATE');
            $lock->execute([$token]);$current=$lock->fetch();
            if(!$current)throw new RuntimeException('سفارش پیدا نشد.');
            if((string)$current['payment_method']!=='torobpay')throw new RuntimeException('روش پرداخت این سفارش ترب‌پی نیست.');
            if((string)$current['payment_status']==='paid'){ $pdo->rollBack(); redirect(app_url('order-success.php?token='.$token)); }
            if(!in_array((string)$current['order_status'],['new','pending_payment'],true))throw new RuntimeException('این سفارش دیگر امکان شروع پرداخت ندارد.');
            if((float)$current['payment_markup_percent']<=0 || (int)$current['payment_markup_amount']<=0)throw new RuntimeException('افزایش قیمت این سفارش معتبر نیست.');
            $pdo->prepare('UPDATE orders SET payment_markup_accepted_at=NOW() WHERE id=?')->execute([(int)$current['id']]);
            $pdo->commit();
            $gates=is_array($_SESSION['_torobpay_start_consent']??null)?$_SESSION['_torobpay_start_consent']:[];
            $gates[$token]=time();
            foreach($gates as $key=>$time)if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/',$key)||(int)$time<time()-900)unset($gates[$key]);
            $_SESSION['_torobpay_start_consent']=array_slice($gates,-20,null,true);
            redirect(app_url('torobpay-start.php?token='.$token));
        }catch(Throwable $e){
            if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
            log_error($e);$error=$e instanceof RuntimeException?$e->getMessage():'ثبت تأیید انجام نشد. دوباره تلاش کنید.';
        }
    }
}

$pageKey='payment';
$pageTitle='تأیید شرایط خرید اقساطی | '.setting('store_name');
$pageDescription='تأیید افزایش قیمت خرید اقساطی ترب‌پی پیش از انتقال به درگاه.';
$robots='noindex,nofollow';
require BASE_PATH.'/app/views/header.php';
?>
<main class="torobpay-consent-page"><section class="container torobpay-consent-wrap">
    <div class="torobpay-consent-card">
        <div class="torobpay-consent-badge">پرداخت اقساطی ترب‌پی</div>
        <h1>قبل از ورود به درگاه، این مورد را تأیید کنید</h1>
        <p class="torobpay-consent-lead">در این سفارش، به‌دلیل انتخاب خرید اقساطی با ترب‌پی، قیمت محصولات <strong><?=fa_number($percentLabel)?>٪</strong> بیشتر از روش پرداخت عادی محاسبه شده است.</p>
        <div class="torobpay-consent-alert"><strong>توجه</strong><span>با ادامه پرداخت، شما این افزایش قیمت را دیده‌اید و می‌پذیرید. شرایط اعتبار و تعداد اقساط نیز در سرویس ترب‌پی نمایش داده می‌شود.</span></div>
        <div class="torobpay-consent-summary" aria-label="خلاصه مبلغ سفارش">
            <div><span>شماره سفارش</span><strong><?=e((string)$order['order_number'])?></strong></div>
            <div><span>جمع قیمت محصولات با روش عادی</span><strong><?=fa_number(format_price($normalProductsTotal))?> تومان</strong></div>
            <div class="torobpay-consent-markup"><span>افزایش خرید اقساطی (<?=fa_number($percentLabel)?>٪)</span><strong>+ <?=fa_number(format_price($markupAmount))?> تومان</strong></div>
            <div><span>جمع محصولات با ترب‌پی</span><strong><?=fa_number(format_price($subtotal))?> تومان</strong></div>
            <?php if($discount>0):?><div><span>تخفیف سفارش</span><strong>− <?=fa_number(format_price($discount))?> تومان</strong></div><?php endif?>
            <div class="torobpay-consent-total"><span>مبلغ نهایی سفارش</span><strong><?=fa_number(format_price($total))?> تومان</strong></div>
        </div>
        <?php if($error!==''):?><div class="form-message error"><?=e($error)?></div><?php endif?>
        <form method="post" class="torobpay-consent-form">
            <?=csrf_field()?>
            <input type="hidden" name="token" value="<?=e($token)?>">
            <input type="hidden" name="action" value="accept_markup">
            <label class="torobpay-consent-check"><input type="checkbox" name="accept_markup" value="1" required><span>متوجه شدم که با انتخاب ترب‌پی، قیمت محصولات این سفارش <?=fa_number($percentLabel)?>٪ افزایش یافته و مبلغ بالا را می‌پذیرم.</span></label>
            <button class="btn btn-primary btn-block" type="submit">می‌پذیرم و وارد ترب‌پی می‌شوم</button>
        </form>
        <div class="torobpay-consent-actions"><form method="post" class="torobpay-consent-cancel-form"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><input type="hidden" name="action" value="cancel_order"><button class="btn btn-secondary" type="submit">انصراف و لغو سفارش</button></form><a href="<?=e(app_url('payment-methods.php#torobpay'))?>">توضیحات روش‌های پرداخت</a></div>
    </div>
</section></main>
<?php require BASE_PATH.'/app/views/footer.php';
