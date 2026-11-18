<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$pageKey = 'about';
$storeName = (string)setting('store_name', 'لوازم خانگی گلریزان');
$pageTitle = 'درباره ما | ' . $storeName;
$pageDescription = 'آشنایی با ' . $storeName . ' و تعهدهای فروشگاه در خرید لوازم خانگی.';
require BASE_PATH . '/app/views/header.php';
?>
<main>
<section class="page-hero"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>درباره ما</span></div><h1>درباره <?=e($storeName)?></h1><p>فروشگاهی با تمرکز بر قیمت شفاف، موجودی واقعی و پشتیبانی مسئولانه خرید.</p></div></section>
<section class="section"><div class="container content-card"><h2>تعهد ما</h2><p>مشخصات، قیمت، موجودی و شرایط گارانتی هر کالا پیش از سفارش به‌صورت روشن نمایش داده می‌شود تا بتوانید مدل مناسب را با آگاهی انتخاب کنید.</p><h2>خدمات فروشگاه</h2><p>مشاوره پیش از خرید، هماهنگی ارسال کالاهای سنگین، اعلام شرایط گارانتی و پیگیری مرحله‌به‌مرحله سفارش از خدمات اصلی فروشگاه است.</p><h2>خرید مطمئن</h2><p>راه‌های تماس، قوانین ارسال و مرجوعی و وضعیت سفارش در دسترس مشتری قرار دارد و اطلاعات ثبت‌شده هر سفارش از حساب مشتری قابل پیگیری است.</p></div></section>
</main>
<?php require BASE_PATH . '/app/views/footer.php';
