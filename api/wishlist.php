<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$customer=customer_user();if(!$customer)json_response(['ok'=>false,'message'=>'برای استفاده از علاقه‌مندی‌ها وارد حساب خود شوید.','login'=>app_url('customer-login.php')],401);
if(!is_post())json_response(['ok'=>true,'ids'=>customer_wishlist_ids((int)$customer['id']),'count'=>count(customer_wishlist_ids((int)$customer['id']))]);
verify_csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));rate_limit('wishlist',60,300);
$data=input_json();$productId=max(0,scalar_int($data['product_id']??0));
try{$active=customer_toggle_wishlist((int)$customer['id'],$productId);json_response(['ok'=>true,'active'=>$active,'count'=>count(customer_wishlist_ids((int)$customer['id']))]);}
catch(Throwable $e){json_response(['ok'=>false,'message'=>public_exception_message($e,'تغییر علاقه‌مندی انجام نشد. دوباره تلاش کنید.')],$e instanceof RuntimeException&&!($e instanceof PDOException)?422:500);}
