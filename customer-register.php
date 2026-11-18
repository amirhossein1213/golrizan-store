<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
if((string)setting('customer_accounts_enabled','1')!=='1'){http_response_code(404);exit('حساب مشتری غیرفعال است.');}
if(customer_logged_in())redirect(app_url('account.php'));

$error='';$notice='';
$method=scalar_string($_GET['method']??'phone');
if(!in_array($method,['phone','full'],true))$method='phone';
$pending=customer_pending_registration();
$quickPhone=normalize_phone((string)($_SESSION[customer_otp_session_key('register_phone')]??''));
if($pending)$method='full';
elseif($quickPhone!=='')$method='phone';

if(is_post()){
    try{
        verify_csrf();
        $action=scalar_string($_POST['action']??'phone_request');
        if($action==='phone_request'||$action==='phone_resend'){
            $method='phone';
            $quickPhone=normalize_phone(scalar_string($_POST['phone']??$quickPhone));
            customer_request_otp($quickPhone,'register_phone');
            $notice=$action==='phone_resend'?'کد جدید ارسال شد.':'کد ثبت‌نام به شماره همراه شما ارسال شد.';
        }elseif($action==='phone_verify'){
            $method='phone';
            $quickPhone=normalize_phone(scalar_string($_POST['phone']??$quickPhone));
            customer_verify_otp($quickPhone,scalar_string($_POST['code']??''),'register_phone');
            $return=safe_local_redirect((string)($_SESSION['_customer_return']??''))??app_url('account.php');
            unset($_SESSION['_customer_return']);
            redirect($return);
        }elseif($action==='phone_change'){
            $method='phone';
            unset($_SESSION[customer_otp_session_key('register_phone')]);
            $quickPhone='';
        }elseif($action==='request'){
            $method='full';
            customer_begin_registration(
                scalar_string($_POST['first_name']??''),
                scalar_string($_POST['last_name']??''),
                scalar_string($_POST['phone']??''),
                scalar_string($_POST['email']??''),
                scalar_string($_POST['password']??''),
                scalar_string($_POST['password_confirm']??'')
            );
            $pending=customer_pending_registration();
            $notice='کد تأیید شماره همراه ارسال شد.';
        }elseif($action==='verify'){
            $method='full';
            customer_complete_registration(scalar_string($_POST['code']??''));
            $return=safe_local_redirect((string)($_SESSION['_customer_return']??''))??app_url('account.php');
            unset($_SESSION['_customer_return']);
            redirect($return);
        }elseif($action==='resend'){
            $method='full';
            $pending=customer_pending_registration();
            if(!$pending)throw new RuntimeException('اطلاعات ثبت‌نام منقضی شده است.');
            customer_request_otp((string)$pending['phone'],'register');
            $notice='کد جدید ارسال شد.';
        }elseif($action==='change'){
            $method='full';
            unset($_SESSION['_customer_register_pending'],$_SESSION[customer_otp_session_key('register')]);
            $pending=null;
        }else{
            throw new RuntimeException('درخواست ثبت‌نام معتبر نیست.');
        }
    }catch(Throwable $e){
        $error=public_exception_message($e,'ثبت‌نام انجام نشد. دوباره تلاش کنید.');
        $pending=customer_pending_registration();
        $quickPhone=normalize_phone((string)($_SESSION[customer_otp_session_key('register_phone')]??$quickPhone));
    }
}

$otpNoticePhone=$method==='phone'?$quickPhone:(string)($pending['phone']??'');
$otpDeliveryNotice=$otpNoticePhone!==''?sms_customer_mobile_event_notice($otpNoticePhone,'customer_otp',true):null;
$pageKey='account';
$pageTitle='ثبت‌نام با تلفن همراه | '.setting('store_name');
$robots='noindex,nofollow';
require BASE_PATH.'/app/views/header.php';
?>
<main class="auth-page"><div class="container auth-layout auth-layout--register">
<aside class="auth-showcase">
    <div><a class="auth-brand" href="<?=e(app_url())?>"><span>م</span><strong><?=e(setting('store_name','فروشگاه گلریزان'))?></strong></a><div class="auth-kicker">عضویت در فروشگاه</div><h2>ثبت‌نام سریع با شماره همراه.</h2><p>فقط شماره موبایل خود را تأیید کنید؛ سفارش‌های قبلی ثبت‌شده با همین شماره نیز به حساب شما متصل می‌شوند.</p></div>
    <ul class="auth-benefits"><li><i>✓</i><span>ثبت‌نام امن با کد یک‌بارمصرف</span></li><li><i>✓</i><span>اتصال خودکار خریدهای مهمان همان شماره</span></li><li><i>✓</i><span>مشاهده سفارش‌ها، فاکتورها و وضعیت ارسال</span></li></ul>
    <div class="auth-secure-note"><span>⌁</span><div><strong>حریم خصوصی شما</strong><small>شماره همراه فقط برای احراز هویت و اطلاع‌رسانی سفارش استفاده می‌شود.</small></div></div>
</aside>
<section class="auth-panel auth-panel--register">
    <header class="auth-panel__top"><a href="<?=e(app_url())?>">بازگشت به فروشگاه</a><span><?=$method==='phone'?'ثبت‌نام موبایلی':'عضویت کامل'?></span></header>
    <div class="auth-heading"><span class="auth-heading__icon">＋</span><div><h1><?php if($method==='phone'):?><?=$quickPhone!==''?'تأیید شماره همراه':'ثبت‌نام با تلفن همراه'?><?php else:?><?=$pending?'تأیید شماره همراه':'ساخت حساب کامل'?><?php endif?></h1><p><?php if($method==='phone'):?><?=$quickPhone!==''?'کد پیامکی را وارد کنید تا حساب شما ساخته یا بازیابی شود.':'بدون نیاز به ایمیل و رمز عبور، با کد پیامکی ثبت‌نام کنید.'?><?php else:?><?=$pending?'برای تکمیل عضویت، کد پیامکی را وارد کنید.':'نام، ایمیل و رمز عبور را نیز برای حساب کامل ثبت کنید.'?><?php endif?></p></div></div>
    <?php foreach(flashes() as $flash):?><div class="form-message <?=$flash['type']==='success'?'success':'error'?>"><?=e($flash['message'])?></div><?php endforeach?>
    <?php if($error):?><div class="form-error"><?=e($error)?></div><?php endif?><?php if($notice):?><div class="form-message success"><?=e($notice)?></div><?php endif?><?php if($otpDeliveryNotice):?><div class="form-message <?=$otpDeliveryNotice['level']==='success'?'success':($otpDeliveryNotice['level']==='error'?'error':'')?>"><strong><?=e('وضعیت کد تأیید: '.$otpDeliveryNotice['title'])?></strong><br><?=e($otpDeliveryNotice['message'])?><?php if($otpDeliveryNotice['solution']!==''):?><br><small><b>راهکار:</b> <?=e($otpDeliveryNotice['solution'])?></small><?php endif?></div><?php endif?><?php if($otpDeliveryNotice&&sms_notice_is_blacklisted($otpDeliveryNotice)):?><?=sms_blacklist_release_guide_html(['compact'=>true])?><?php endif?>

    <div class="auth-methods auth-methods--register"><a class="<?=$method==='phone'?'active':''?>" href="<?=e(app_url('customer-register.php?method=phone'))?>"><span>شماره همراه</span><small>ثبت‌نام سریع با OTP</small></a><a class="<?=$method==='full'?'active':''?>" href="<?=e(app_url('customer-register.php?method=full'))?>"><span>حساب کامل</span><small>نام، ایمیل و رمز عبور</small></a></div>

    <?php if($method==='phone'):?>
        <?php if($quickPhone===''):?>
        <form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="action" value="phone_request"><div class="field"><label>شماره همراه</label><input name="phone" inputmode="numeric" autocomplete="tel" pattern="09[0-9]{9}" maxlength="11" placeholder="09xxxxxxxxx" dir="ltr" value="<?=e(normalize_phone(scalar_string($_POST['phone']??'')))?>" required><small>اگر قبلاً با این شماره خرید کرده‌اید، سفارش‌ها بعد از تأیید به حساب شما متصل می‌شوند.</small></div><button class="btn auth-primary btn-block">دریافت کد ثبت‌نام</button></form>
        <?php else:?>
        <div class="auth-otp-intro"><span>•••</span><p>کد ارسال‌شده به <strong dir="ltr"><?=e($quickPhone)?></strong> را وارد کنید.</p></div>
        <form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="action" value="phone_verify"><input type="hidden" name="phone" value="<?=e($quickPhone)?>"><div class="field"><label>کد تأیید</label><input class="auth-otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9۰-۹٠-٩]{4,8}" maxlength="8" placeholder="— — — — —" required autofocus></div><button class="btn auth-primary btn-block">تأیید و ورود به حساب</button></form>
        <div class="auth-secondary-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="phone_resend"><input type="hidden" name="phone" value="<?=e($quickPhone)?>"><button class="btn auth-ghost btn-block">ارسال دوباره کد</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="phone_change"><button class="btn auth-ghost btn-block">تغییر شماره</button></form></div><p class="muted" style="margin-top:10px">اگر کد نرسید، یک دقیقه بعد صفحه را تازه کنید تا علت تحویل‌نشدن نمایش داده شود.</p>
        <?php endif?>
    <?php else:?>
        <?php if(!$pending):?>
        <?php if(customer_google_enabled()):?><a class="auth-google" href="<?=e(app_url('google-auth.php?from=register'))?>" rel="nofollow"><svg aria-hidden="true" viewBox="0 0 24 24"><path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.4-.18-2.07H12v3.92h5.38a4.6 4.6 0 0 1-2 3.02v2.54h3.24c1.9-1.75 2.98-4.33 2.98-7.41Z"/><path fill="#34A853" d="M12 22c2.7 0 4.97-.9 6.62-2.36l-3.24-2.54c-.9.6-2.05.96-3.38.96-2.61 0-4.82-1.76-5.61-4.13H3.04v2.62A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.39 13.93A6.02 6.02 0 0 1 6.08 12c0-.67.12-1.32.31-1.93V7.45H3.04A10 10 0 0 0 2 12c0 1.61.39 3.14 1.04 4.55l3.35-2.62Z"/><path fill="#EA4335" d="M12 5.94c1.47 0 2.79.51 3.83 1.5l2.87-2.88A9.65 9.65 0 0 0 12 2a10 10 0 0 0-8.96 5.45l3.35 2.62C7.18 7.7 9.39 5.94 12 5.94Z"/></svg><span><strong>ثبت‌نام با Google</strong><small>نمایش و انتخاب حساب‌های Google</small></span><b>←</b></a><div class="auth-divider"><span>یا فرم ثبت‌نام کامل را تکمیل کنید</span></div><?php endif?>
        <form method="post" class="auth-form auth-register-form"><?=csrf_field()?><input type="hidden" name="action" value="request"><div class="form-grid">
        <div class="field"><label>نام</label><input name="first_name" maxlength="80" autocomplete="given-name" value="<?=e(trim(scalar_string($_POST['first_name']??'')))?>" placeholder="نام شما" required></div><div class="field"><label>نام خانوادگی</label><input name="last_name" maxlength="100" autocomplete="family-name" value="<?=e(trim(scalar_string($_POST['last_name']??'')))?>" placeholder="نام خانوادگی" required></div>
        <div class="field"><label>شماره همراه</label><input name="phone" inputmode="numeric" autocomplete="tel" pattern="09[0-9]{9}" maxlength="11" placeholder="09xxxxxxxxx" dir="ltr" value="<?=e(normalize_phone(scalar_string($_POST['phone']??'')))?>" required></div><div class="field"><label>ایمیل</label><input type="email" name="email" maxlength="190" autocomplete="email" placeholder="name@example.com" dir="ltr" value="<?=e(trim(scalar_string($_POST['email']??'')))?>" required></div>
        <div class="field"><label>رمز عبور</label><div class="auth-password-field"><input type="password" name="password" minlength="8" maxlength="200" autocomplete="new-password" placeholder="حداقل ۸ کاراکتر" required><button type="button" data-password-toggle>نمایش</button></div><small>رمز باید شامل حرف و عدد باشد.</small></div><div class="field"><label>تکرار رمز عبور</label><div class="auth-password-field"><input type="password" name="password_confirm" minlength="8" maxlength="200" autocomplete="new-password" placeholder="تکرار رمز عبور" required><button type="button" data-password-toggle>نمایش</button></div></div>
        </div><button class="btn auth-primary btn-block">ساخت حساب و تأیید شماره</button></form>
        <?php else:?>
        <div class="auth-otp-intro"><span>•••</span><p>کد ارسال‌شده به <strong dir="ltr"><?=e($pending['phone'])?></strong> را وارد کنید.</p></div><form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="action" value="verify"><div class="field"><label>کد تأیید</label><input class="auth-otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9۰-۹٠-٩]{4,8}" maxlength="8" placeholder="— — — — —" required autofocus></div><button class="btn auth-primary btn-block">تأیید و ساخت حساب</button></form><div class="auth-secondary-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="resend"><button class="btn auth-ghost btn-block">ارسال دوباره کد</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="change"><button class="btn auth-ghost btn-block">اصلاح اطلاعات</button></form></div>
        <?php endif?>
    <?php endif?>
    <p class="auth-switch">قبلاً ثبت‌نام کرده‌اید؟ <a href="<?=e(app_url('customer-login.php'))?>">ورود به حساب</a></p>
</section>
</div></main><?php require BASE_PATH.'/app/views/footer.php';
