<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
$token = scalar_string($_GET['token'] ?? '');
$order = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare('SELECT * FROM orders WHERE public_token=? LIMIT 1');
    $stmt->execute([$token]);
    $order = $stmt->fetch() ?: null;
}
if (!$order) http_response_code(404);
if ($order && (string)$order['order_status'] !== 'cancelled') {
    // مسیر جایگزین: اگر ارسال لحظه ثبت/پرداخت به هر دلیل شکست خورد، بازشدن صفحه نتیجه پس از وقفه کوتاه دوباره تلاش می‌کند.
    sms_notify_order_created((int)$order['id'],true);
    if ((string)$order['payment_status'] === 'paid' && (string)$order['order_status'] !== 'cancelled') sms_notify_payment_status((int)$order['id'],'paid',true);
}
$smsDeliveryNotices=$order?sms_customer_order_delivery_notices((int)$order['id'],true):[];
$pageKey='success';$pageTitle='نتیجه سفارش | '.setting('store_name');$robots='noindex,nofollow';
require BASE_PATH.'/app/views/header.php';
$orderIsCancelled=(bool)$order&&(string)$order['order_status']==='cancelled';
$currentPaymentLabel=$order?order_payment_status_label($order):'';
$sessionOrderToken = scalar_string($_SESSION['_last_order_public_token'] ?? '');
$clearCartOnLoad = (bool)$order && preg_match('/^[a-f0-9]{64}$/', $sessionOrderToken) && hash_equals($sessionOrderToken, $token);
if ($clearCartOnLoad) unset($_SESSION['_last_order_public_token']);
$accountNotice = is_array($_SESSION['_checkout_account_notice'][$token] ?? null) ? $_SESSION['_checkout_account_notice'][$token] : null;
if ($accountNotice) unset($_SESSION['_checkout_account_notice'][$token]);
if ($orderIsCancelled) $accountNotice = null;
$guestAccountLink=app_url('customer-login.php');
if($order&&!customer_logged_in()&&$accountNotice&&!empty($accountNotice['guest'])){
    $guestLinks=is_array($_SESSION['_guest_order_account_links']??null)?$_SESSION['_guest_order_account_links']:[];
    $guestLink=is_array($guestLinks[$token]??null)?$guestLinks[$token]:null;
    if($guestLink&&normalize_phone((string)($guestLink['phone']??''))===normalize_phone((string)$order['phone'])&&(int)($guestLink['time']??0)>=time()-86400){
        $guestAccountLink=app_url('customer-login.php?from_order='.rawurlencode($token));
    }
}
?><main><div class="container product-page"><div class="empty-state"><?php foreach(flashes() as $flash):?><div class="form-message <?=$flash['type']==='success'?'success':'error'?>" style="max-width:720px;margin:10px auto"><?=e($flash['message'])?></div><?php endforeach?><?php if($order):?><div class="success-icon"><?=$orderIsCancelled?'×':'✓'?></div><h1><?=$orderIsCancelled?'این سفارش لغو شده است':'سفارش شما ثبت شد'?></h1><p>شماره سفارش: <strong><?=e($order['order_number'])?></strong></p><p>تاریخ ثبت: <strong><?=e(jalali_format($order['created_at']))?></strong></p><p>مبلغ نهایی: <strong><?=fa_number(format_price($order['total']))?> تومان</strong></p><?php if((float)($order['payment_markup_percent']??0)>0):?><p>افزایش قیمت ترب‌پی: <strong><?=fa_number(rtrim(rtrim(number_format((float)$order['payment_markup_percent'],2,'.',''),'0'),'.'))?>٪</strong> (<?=fa_number(format_price((int)($order['payment_markup_amount']??0)))?> تومان روی قیمت محصولات)</p><?php endif?><p>وضعیت پرداخت: <?=e($currentPaymentLabel)?></p><?php if((string)$order['payment_method']==='snappay'&&!empty($order['gateway_reference'])&&in_array((string)$order['payment_status'],['paid','partially_refunded','refunded'],true)):?><p>شناسه تراکنش اسنپ‌پی: <strong dir="ltr"><?=e((string)$order['gateway_reference'])?></strong></p><?php endif?><p>ارسال: <strong><?=e(in_array((string)$order['shipping_method'],['festival_free','product_festival_free'],true)?'ارسال رایگان جشنواره':((string)$order['shipping_method']==='mixed_product_festival'?'ارسال ترکیبی؛ کالاهای جشنواره‌ای رایگان و سایر کالاها پس‌کرایه':'پس‌کرایه؛ پرداخت کرایه هنگام تحویل'))?></strong></p><?php if($smsDeliveryNotices):?><div class="checkout-card" style="max-width:720px;margin:20px auto;text-align:right"><h2 style="margin-top:0">وضعیت پیامک‌های سفارش</h2><p class="muted">ارسال‌شدن به SMS.ir با تحویل به گوشی متفاوت است؛ نتیجه نهایی در این بخش نمایش داده می‌شود.</p><?php foreach($smsDeliveryNotices as $smsNotice):?><div class="form-message <?=$smsNotice['level']==='success'?'success':($smsNotice['level']==='error'?'error':'')?>" style="margin:10px 0"><strong><?=e($smsNotice['event_label'].' — '.$smsNotice['title'])?></strong><br><span><?=e($smsNotice['message'])?></span><?php if($smsNotice['solution']!==''):?><br><small><b>راهکار:</b> <?=e($smsNotice['solution'])?></small><?php endif?></div><?php if(sms_notice_is_blacklisted($smsNotice)&&sms_order_event_can_be_resent((string)$smsNotice['event_key'],$order)):?><?=sms_blacklist_release_guide_html(['resend_fields'=>['token'=>$token,'event_key'=>$smsNotice['event_key'],'return_to'=>$_SERVER['REQUEST_URI']??app_url('order-success.php?token='.$token)],'change_url'=>app_url('contact.php'),'change_label'=>'اصلاح شماره با پشتیبانی'])?><?php endif?><?php endforeach?><small class="muted">اگر پیامک تازه ارسال شده، گزارش تحویل ممکن است با کمی تأخیر به‌روزرسانی شود.</small></div><?php endif?><?php if(in_array((string)$order['payment_method'],['gateway','torobpay','snappay'],true)&&in_array((string)$order['payment_status'],['unpaid','pending','failed'],true)&&in_array((string)$order['order_status'],['new','pending_payment'],true)):?><?php $retryTorob=(string)$order['payment_method']==='torobpay';$retrySnapp=(string)$order['payment_method']==='snappay';$retryPending=(string)$order['payment_status']==='pending';$retryScript=$retryTorob&&$retryPending?'torobpay-callback.php':($retryTorob?'torobpay-consent.php':($retrySnapp?'snappay-start.php':'payment-start.php'));$retryLabel=$retryTorob?($retryPending?'بررسی پرداخت ترب‌پی':'ادامه پرداخت با ترب‌پی'):($retrySnapp?'ادامه پرداخت با اسنپ‌پی':'ادامه پرداخت با زیبال');?><p><a class="btn btn-primary" href="<?=e(app_url($retryScript.'?token='.$token))?>"><?=$retryLabel?></a></p><?php endif?><?php if(!customer_logged_in()&&$accountNotice&&!empty($accountNotice['guest'])):?><div class="order-account-created"><span>👤</span><div><strong>سفارش مهمان با موفقیت ثبت شد</strong><p>برای دیدن این سفارش در حساب خود، همان شماره همراه سفارش را با کد یک‌بارمصرف تأیید کنید.</p></div><a class="btn btn-secondary" href="<?=e($guestAccountLink)?>">ورود و اتصال امن سفارش</a></div><?php endif?><a class="btn btn-primary" href="<?=e(app_url('shop.php'))?>">بازگشت به فروشگاه</a><?php else:?><h1>سفارش پیدا نشد</h1><a class="btn btn-primary" href="<?=e(app_url())?>">صفحه اصلی</a><?php endif?></div></div></main><?php require BASE_PATH.'/app/views/footer.php';
