<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
if((string)setting('customer_accounts_enabled','1')!=='1'){http_response_code(404);exit('حساب مشتری غیرفعال است.');}
$from=scalar_string($_GET['from']??'login');$from=in_array($from,['login','register','link'],true)?$from:'login';
$current=customer_user();if($current&&$from!=='link')redirect(app_url('account.php'));if(!$current&&$from==='link'){$_SESSION['_customer_return']=app_url('account.php');redirect(app_url('customer-login.php'));}
$fallback=$from==='register'?'customer-register.php':($from==='link'?'account.php':'customer-login.php');
try{$url=customer_google_authorization_url();if(isset($_SESSION['_google_oauth'])&&is_array($_SESSION['_google_oauth'])){$_SESSION['_google_oauth']['from']=$from;if($from==='link'){$_SESSION['_google_oauth']['link_customer_id']=(int)$current['id'];$_SESSION['_google_oauth']['return']=app_url('account.php');}}redirect($url);}catch(Throwable $e){flash('error',public_exception_message($e,'شروع ورود با Google انجام نشد. دوباره تلاش کنید.'));redirect(app_url($fallback));}
