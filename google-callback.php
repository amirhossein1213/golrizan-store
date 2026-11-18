<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
try{
    $pending=$_SESSION['_google_oauth']??null;$return=is_array($pending)?safe_local_redirect((string)($pending['return']??'')):null;$from=is_array($pending)?(string)($pending['from']??'login'):'login';$fallback=$from==='register'?'customer-register.php':($from==='link'?'account.php':'customer-login.php');
    if(isset($_GET['error'])){unset($_SESSION['_google_oauth']);throw new RuntimeException('ورود با Google لغو شد یا مجوز لازم صادر نشد.');}
    customer_google_complete(scalar_string($_GET['code']??''),scalar_string($_GET['state']??''));unset($_SESSION['_customer_return']);redirect($return??app_url('account.php'));
}catch(Throwable $e){flash('error',public_exception_message($e,'تأیید حساب Google انجام نشد. دوباره تلاش کنید.'));redirect(app_url($fallback??'customer-login.php'));}
