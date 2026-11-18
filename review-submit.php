<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';$customer=customer_required();if(!is_post())redirect(app_url());verify_csrf();
$productId=max(0,scalar_int($_POST['product_id']??0));$product=find_product_by_id($productId);
try{submit_product_review($productId,(int)$customer['id'],scalar_int($_POST['rating']??0),scalar_string($_POST['title']??''),scalar_string($_POST['body']??''));flash('success','دیدگاه شما ثبت شد و پس از بررسی نمایش داده می‌شود.');}
catch(Throwable $e){flash('error',public_exception_message($e,'ثبت دیدگاه انجام نشد. دوباره تلاش کنید.'));}
redirect($product?product_url($product).'#reviews':app_url('shop.php'));
