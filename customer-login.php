<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
if((string)setting('customer_accounts_enabled','1')!=='1'){http_response_code(404);exit('حساب مشتری غیرفعال است.');}
if(customer_logged_in())redirect(app_url('account.php'));
$error='';$notice='';$method=scalar_string($_GET['method']??'otp');if(!in_array($method,['otp','password'],true))$method='otp';
$prefillPhone='';$fromOrderToken=strtolower(trim(scalar_string($_GET['from_order']??'')));
if(preg_match('/^[a-f0-9]{64}$/',$fromOrderToken)){
    $guestLinks=is_array($_SESSION['_guest_order_account_links']??null)?$_SESSION['_guest_order_account_links']:[];
    $guestLink=is_array($guestLinks[$fromOrderToken]??null)?$guestLinks[$fromOrderToken]:null;
    if($guestLink&&(int)($guestLink['time']??0)>=time()-86400){
        $candidate=normalize_phone((string)($guestLink['phone']??''));
        if(preg_match('/^09\d{9}$/',$candidate)){
            $prefillPhone=$candidate;
            $pendingPhone=normalize_phone((string)($_SESSION[customer_otp_session_key('login')]??''));
            if($pendingPhone!==''&&!hash_equals($candidate,$pendingPhone))unset($_SESSION[customer_otp_session_key('login')]);
            $_SESSION['_customer_order_link_token']=$fromOrderToken;
            $_SESSION['_customer_return']=(string)(parse_url(app_url('account.php'),PHP_URL_PATH)?:'/account.php');
        }
    }
}
$phone=normalize_phone((string)($_SESSION[customer_otp_session_key('login')]??''));
if(is_post()){
    try{
        verify_csrf();$action=scalar_string($_POST['action']??'request');
        if($action==='password'){
            $method='password';customer_password_login(scalar_string($_POST['identifier']??$_POST['email']??''),scalar_string($_POST['password']??''));
            $return=safe_local_redirect((string)($_SESSION['_customer_return']??''))??app_url('account.php');unset($_SESSION['_customer_return']);redirect($return);
        }
        if($action==='request'||$action==='resend'){
            $method='otp';$phone=normalize_phone(scalar_string($_POST['phone']??$phone));customer_request_otp($phone,'login');
            $notice=$action==='resend'?'کد جدید ارسال شد.':'کد ورود ارسال شد.';
        }elseif($action==='verify'){
            $method='otp';$phone=normalize_phone(scalar_string($_POST['phone']??$phone));customer_verify_otp($phone,scalar_string($_POST['code']??''),'login');
            $linkedToken=scalar_string($_SESSION['_customer_order_link_token']??'');
            if(preg_match('/^[a-f0-9]{64}$/',$linkedToken)&&isset($_SESSION['_guest_order_account_links'][$linkedToken]))unset($_SESSION['_guest_order_account_links'][$linkedToken]);
            unset($_SESSION['_customer_order_link_token']);
            $return=safe_local_redirect((string)($_SESSION['_customer_return']??''))??app_url('account.php');unset($_SESSION['_customer_return']);redirect($return);
        }elseif($action==='change'){
            $method='otp';unset($_SESSION[customer_otp_session_key('login')]);$phone='';
        }
    }catch(Throwable $e){$error=public_exception_message($e,'ورود انجام نشد. دوباره تلاش کنید.');}
}
$otpDeliveryNotice=$phone!==''?sms_customer_mobile_event_notice($phone,'customer_otp',true):null;
$pageKey='account';$pageTitle='ورود مشتری | '.setting('store_name');$robots='noindex,nofollow';require BASE_PATH.'/app/views/header.php';
?>
<main class="auth-page"><div class="container auth-layout">
<aside class="auth-showcase">
    <div><a class="auth-brand" href="<?=e(app_url())?>"><span>م</span><strong><?=e(setting('store_name','فروشگاه گلریزان'))?></strong></a><div class="auth-kicker">حساب مشتریان</div><h2>سریع‌تر وارد شوید؛ راحت‌تر خرید کنید.</h2><p>سفارش‌ها، نشانی‌ها، علاقه‌مندی‌ها و تیکت‌های پشتیبانی همیشه در حساب شما در دسترس‌اند.</p></div>
    <ul class="auth-benefits"><li><i>✓</i><span>ورود امن با Google، رمزعبور یا کد پیامکی</span></li><li><i>✓</i><span>دسترسی مستقیم به سفارش‌ها و فاکتورها</span></li><li><i>✓</i><span>حفظ سبد و اطلاعات ارسال برای خرید بعدی</span></li></ul>
    <div class="auth-secure-note"><span>⌁</span><div><strong>ارتباط امن</strong><small>اطلاعات ورود شما رمزنگاری و محافظت می‌شود.</small></div></div>
</aside>
<section class="auth-panel">
    <header class="auth-panel__top"><a href="<?=e(app_url())?>">بازگشت به فروشگاه</a><span>ورود امن</span></header>
    <div class="auth-heading"><span class="auth-heading__icon">↪</span><div><h1>خوش آمدید</h1><p>یکی از روش‌های زیر را برای ورود انتخاب کنید.</p></div></div>
    <?php foreach(flashes() as $flash):?><div class="form-message <?=$flash['type']==='success'?'success':'error'?>"><?=e($flash['message'])?></div><?php endforeach?>
    <?php if($error):?><div class="form-error"><?=e($error)?></div><?php endif?><?php if($notice):?><div class="form-message success"><?=e($notice)?></div><?php endif?><?php if($otpDeliveryNotice):?><div class="form-message <?=$otpDeliveryNotice['level']==='success'?'success':($otpDeliveryNotice['level']==='error'?'error':'')?>"><strong><?=e('وضعیت کد ورود: '.$otpDeliveryNotice['title'])?></strong><br><?=e($otpDeliveryNotice['message'])?><?php if($otpDeliveryNotice['solution']!==''):?><br><small><b>راهکار:</b> <?=e($otpDeliveryNotice['solution'])?></small><?php endif?></div><?php endif?><?php if($otpDeliveryNotice&&sms_notice_is_blacklisted($otpDeliveryNotice)):?><?=sms_blacklist_release_guide_html(['compact'=>true])?><?php endif?>
    <?php if(customer_google_enabled()):?><a class="auth-google" href="<?=e(app_url('google-auth.php?from=login'))?>" rel="nofollow"><svg aria-hidden="true" viewBox="0 0 24 24"><path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.4-.18-2.07H12v3.92h5.38a4.6 4.6 0 0 1-2 3.02v2.54h3.24c1.9-1.75 2.98-4.33 2.98-7.41Z"/><path fill="#34A853" d="M12 22c2.7 0 4.97-.9 6.62-2.36l-3.24-2.54c-.9.6-2.05.96-3.38.96-2.61 0-4.82-1.76-5.61-4.13H3.04v2.62A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.39 13.93A6.02 6.02 0 0 1 6.08 12c0-.67.12-1.32.31-1.93V7.45H3.04A10 10 0 0 0 2 12c0 1.61.39 3.14 1.04 4.55l3.35-2.62Z"/><path fill="#EA4335" d="M12 5.94c1.47 0 2.79.51 3.83 1.5l2.87-2.88A9.65 9.65 0 0 0 12 2a10 10 0 0 0-8.96 5.45l3.35 2.62C7.18 7.7 9.39 5.94 12 5.94Z"/></svg><span><strong>ادامه با Google</strong><small>نمایش و انتخاب حساب‌های Google</small></span><b>←</b></a><div class="auth-divider"><span>یا با اطلاعات حساب وارد شوید</span></div><?php endif?>
    <div class="auth-methods"><a class="<?=$method==='otp'?'active':''?>" href="<?=e(app_url('customer-login.php?method=otp'))?>"><span>کد یک‌بارمصرف</span><small>ورود با شماره همراه</small></a><a class="<?=$method==='password'?'active':''?>" href="<?=e(app_url('customer-login.php?method=password'))?>"><span>شناسه و رمزعبور</span><small>موبایل یا ایمیل تأییدشده</small></a></div>
    <?php if($method==='password'):?>
    <form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="action" value="password"><div class="field"><label>شماره همراه یا ایمیل تأییدشده</label><input name="identifier" maxlength="190" autocomplete="username" value="<?=e(trim(scalar_string($_POST['identifier']??$_POST['email']??'')))?>" placeholder="09xxxxxxxxx یا name@example.com" dir="ltr" required></div><div class="field"><label>رمز عبور</label><div class="auth-password-field"><input type="password" name="password" maxlength="200" autocomplete="current-password" placeholder="رمز عبور شما" required><button type="button" data-password-toggle>نمایش</button></div></div><button class="btn auth-primary btn-block">ورود به حساب</button></form>
    <?php elseif(!$phone):?>
    <form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="action" value="request"><div class="field"><label>شماره همراه</label><input name="phone" inputmode="numeric" autocomplete="tel" pattern="09[0-9]{9}" maxlength="11" value="<?=e($prefillPhone)?>" placeholder="09xxxxxxxxx" dir="ltr" required><small><?=$prefillPhone!==''?'کد به همان شماره ثبت‌شده روی سفارش مهمان ارسال می‌شود.':'کد ورود از طریق پیامک برایتان ارسال می‌شود.'?></small></div><button class="btn auth-primary btn-block">دریافت کد ورود</button></form>
    <?php else:?>
    <div class="auth-otp-intro"><span>•••</span><p>کد ارسال‌شده به <strong dir="ltr"><?=e($phone)?></strong> را وارد کنید.</p></div><form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="action" value="verify"><input type="hidden" name="phone" value="<?=e($phone)?>"><div class="field"><label>کد یک‌بارمصرف</label><input class="auth-otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9۰-۹٠-٩]{4,8}" maxlength="8" placeholder="— — — — —" required autofocus></div><button class="btn auth-primary btn-block">تأیید و ورود</button></form><div class="auth-secondary-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="resend"><input type="hidden" name="phone" value="<?=e($phone)?>"><button class="btn auth-ghost btn-block">ارسال دوباره کد</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="change"><button class="btn auth-ghost btn-block">تغییر شماره</button></form></div><p class="muted" style="margin-top:10px">اگر کد نرسید، یک دقیقه بعد صفحه را تازه کنید تا علت تحویل‌نشدن نمایش داده شود.</p>
    <?php endif?>
    <p class="auth-switch">هنوز حساب ندارید؟ <a href="<?=e(app_url('customer-register.php'))?>">ساخت حساب جدید</a></p>
</section>
</div></main><?php require BASE_PATH.'/app/views/footer.php';
