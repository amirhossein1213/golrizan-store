<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';$customer=customer_required();if(!is_post())redirect(app_url());verify_csrf();
$productId=max(0,scalar_int($_POST['product_id']??0));$product=find_product_by_id($productId);
try{submit_product_question($productId,(int)$customer['id'],scalar_string($_POST['question']??''));flash('success','پرسش شما ثبت شد و پس از بررسی نمایش داده می‌شود.');}
catch(Throwable $e){flash('error',public_exception_message($e,'ثبت پرسش انجام نشد. دوباره تلاش کنید.'));}
redirect($product?product_url($product).'#questions':app_url('shop.php'));
