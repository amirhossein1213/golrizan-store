<?php
require dirname(__DIR__).'/app/bootstrap.php';
if (admin_user()) redirect(app_url('admin/'));
if (!headers_sent()) { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); }
$error='';
$now=time();
$lockedUntil=(int)($_SESSION['login_locked_until']??0);
if ($lockedUntil > 0 && $lockedUntil <= $now) { unset($_SESSION['login_locked_until'], $_SESSION['login_attempts']); $lockedUntil=0; }
if(is_post()){
    verify_csrf();
    if($lockedUntil>$now){
        $minutes=max(1,(int)ceil(($lockedUntil-$now)/60));
        $error='ورود موقتاً قفل شده است. حدود '.$minutes.' دقیقه دیگر دوباره تلاش کنید.';
    }else{
        $username=trim(scalar_string($_POST['username']??''));
        $password=scalar_string($_POST['password']??'');
        if(!preg_match('/^[A-Za-z0-9._-]{3,80}$/',$username)||$password===''||strlen($password)>200){$error='نام کاربری یا رمز واردشده معتبر نیست.';}
        $maxAttempts=max(3,min(20,(int)setting('admin_login_max_attempts','5')));$lockMinutes=max(5,min(120,(int)setting('admin_login_lock_minutes','15')));
        $ipKey='admin-login-ip:'.client_ip();
        $userKey='admin-login-user:'.hash('sha256',strtolower($username));
        $retryAfter=max(rate_limit_retry_after($ipKey,$maxAttempts*2,$lockMinutes*60),rate_limit_retry_after($userKey,$maxAttempts,$lockMinutes*60));
        if($error!==''){
            rate_limit_record($ipKey,900);rate_limit_record($userKey,900);
        }elseif($retryAfter>0){
            $error='ورود موقتاً قفل شده است. حدود '.max(1,(int)ceil($retryAfter/60)).' دقیقه دیگر دوباره تلاش کنید.';
        }else{
            $loginResult=admin_login($username,$password);
            if($loginResult==='two_factor'){
                rate_limit_clear($ipKey);rate_limit_clear($userKey);$_SESSION['_admin_login_next']=safe_local_redirect(scalar_string($_GET['next']??''));redirect(app_url('admin/two-factor.php'));
            }
            if($loginResult===true){
            rate_limit_clear($ipKey);rate_limit_clear($userKey);
            $next=safe_local_redirect(scalar_string($_GET['next']??''));
            redirect($next??app_url('admin/'));
            }
            rate_limit_record($ipKey,$lockMinutes*60);rate_limit_record($userKey,$lockMinutes*60);
            $attempts=(int)($_SESSION['login_attempts']??0)+1;
            $_SESSION['login_attempts']=$attempts;
            if($attempts>=$maxAttempts){
                $_SESSION['login_locked_until']=$now+($lockMinutes*60);
                $error='به‌دلیل تلاش‌های ناموفق، ورود برای '.$lockMinutes.' دقیقه قفل شد.';
            }else{
                $error='نام کاربری یا رمز عبور نادرست است.';
            }
        }
    }
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود مدیر</title><link rel="stylesheet" href="<?=e(asset('css/admin.css'))?>"></head><body><main class="login-page"><form class="login-box" method="post"><h1>ورود به مدیریت</h1><p class="muted"><?=e(setting('store_name'))?></p><?php if(isset($_GET['installed'])):?><div class="flash success">نصب با موفقیت انجام شد. وارد پنل شوید.</div><?php endif?><?php if($error):?><div class="flash error"><?=e($error)?></div><?php endif?><?=csrf_field()?><div class="field"><label>نام کاربری</label><input name="username" autocomplete="username" maxlength="80" required></div><div class="field" style="margin-top:14px"><label>رمز عبور</label><input type="password" name="password" autocomplete="current-password" maxlength="200" required></div><button class="btn btn-primary" style="width:100%;margin-top:18px">ورود</button><a class="btn btn-secondary" style="width:100%;margin-top:8px" href="<?=e(app_url())?>">بازگشت به سایت</a></form></main></body></html>
