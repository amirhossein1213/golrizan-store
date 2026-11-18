<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(admin_user())redirect(app_url('admin/'));
if(!admin_pending_two_factor())redirect(app_url('admin/login.php'));
if(!headers_sent()){header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');}
$error='';
if(is_post()){
    verify_csrf();$code=trim(scalar_string($_POST['code']??''));$pending=admin_pending_two_factor();$userKey='admin-2fa-user:'.(int)($pending['id']??0);$ipKey='admin-2fa-ip:'.client_ip();$retry=max(rate_limit_retry_after($userKey,8,900),rate_limit_retry_after($ipKey,16,900));
    if($retry>0)$error='تلاش‌های ناموفق زیاد است. حدود '.max(1,(int)ceil($retry/60)).' دقیقه دیگر دوباره تلاش کنید.';
    elseif(admin_complete_two_factor($code)){rate_limit_clear($userKey);rate_limit_clear($ipKey);$next=safe_local_redirect((string)($_SESSION['_admin_login_next']??''));unset($_SESSION['_admin_login_next']);redirect($next??app_url('admin/'));}
    else{rate_limit_record($userKey,900);rate_limit_record($ipKey,900);$error='کد تأیید یا کد بازیابی معتبر نیست.';}
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تأیید دومرحله‌ای</title><link rel="stylesheet" href="<?=e(asset('css/admin.css'))?>"></head><body><main class="login-page"><form class="login-box" method="post"><h1>تأیید دومرحله‌ای</h1><p class="muted">کد ۶ رقمی برنامه احراز هویت یا یکی از کدهای بازیابی را وارد کنید.</p><?php if($error):?><div class="flash error"><?=e($error)?></div><?php endif?><?=csrf_field()?><div class="field"><label>کد تأیید</label><input name="code" dir="ltr" autocomplete="one-time-code" maxlength="12" autofocus required></div><button class="btn btn-primary" style="width:100%;margin-top:18px">تأیید و ورود</button><a class="btn btn-secondary" style="width:100%;margin-top:8px" href="<?=e(app_url('admin/login.php'))?>">بازگشت</a></form></main></body></html>
