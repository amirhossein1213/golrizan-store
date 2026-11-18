<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$pageKey = 'sales';
$storeName = (string)setting('store_name', 'لوازم خانگی گلریزان');
$pageTitle = 'فروش و مشاوره خرید | ' . $storeName;
$pageDescription = 'ارتباط با واحد فروش و مشاوره خرید برای انتخاب کالا، موجودی، مشخصات، قیمت و شرایط خرید از ' . $storeName . '.';
$canonical = app_url('sales.php');
$breadcrumbs = [['name'=>'خانه','url'=>app_url()],['name'=>'فروش و مشاوره خرید','url'=>$canonical]];
$generalPhone = trim((string)setting('phone',''));
$generalMobile = trim((string)setting('mobile',''));
$generalEmail = trim((string)setting('email',''));
$sales = [
    'phone'=>trim((string)setting('sales_phone','')) ?: $generalPhone,
    'mobile'=>trim((string)setting('sales_mobile','')) ?: $generalMobile,
    'email'=>trim((string)setting('sales_email','')) ?: $generalEmail,
    'hours'=>trim((string)setting('sales_hours','')),
    'whatsapp'=>trim((string)setting('sales_whatsapp_url','')),
    'bale'=>trim((string)setting('sales_bale_url','')),
    'description'=>trim((string)setting('sales_description','')) ?: 'برای مشاوره قبل از خرید، انتخاب محصول، اطلاع از موجودی و شرایط فروش با واحد فروش در ارتباط باشید.',
];
require BASE_PATH . '/app/views/header.php';
?>
<main class="service-page service-page--sales">
<section class="page-hero service-hero service-hero--sales"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>فروش و مشاوره خرید</span></div><span class="service-hero__eyebrow">مشاوره قبل از خرید</span><h1>فروش و مشاوره خرید گل‌ریزان</h1><p><?=e($sales['description'])?></p></div></section>
<section class="section service-page__body"><div class="container service-layout">
<article class="service-main">
<section class="service-card service-card--highlight service-card--sales"><span class="service-card__eyebrow">راهنمای انتخاب کالا</span><h2>قبل از خرید چه کمکی از واحد فروش می‌گیرم؟</h2><p>اگر بین چند مدل مردد هستید، درباره موجودی، قیمت، مشخصات، گارانتی، جشنواره یا شرایط خرید سؤال دارید، واحد فروش برای انتخاب مناسب‌تر راهنمایی‌تان می‌کند.</p><div class="service-topic-grid"><div><strong>مشاوره انتخاب محصول</strong><span>مقایسه مدل‌ها بر اساس نیاز شما</span></div><div><strong>قیمت و موجودی</strong><span>بررسی وضعیت فعلی کالا</span></div><div><strong>جشنواره و تخفیف</strong><span>شرایط فروش و ارسال رایگان منتخب</span></div><div><strong>گارانتی و مشخصات</strong><span>راهنمایی پیش از ثبت سفارش</span></div></div></section>
<section class="service-card"><h2>اطلاعات تماس واحد فروش</h2><div class="service-contact-list"><?php if($sales['mobile']!==''):?><a href="tel:<?=e(normalize_phone($sales['mobile']))?>"><span>موبایل فروش</span><bdi dir="ltr"><?=e($sales['mobile'])?></bdi></a><?php endif?><?php if($sales['phone']!==''):?><a href="tel:<?=e(normalize_phone($sales['phone']))?>"><span>تلفن فروش</span><bdi dir="ltr"><?=e($sales['phone'])?></bdi></a><?php endif?><?php if($sales['email']!==''&&filter_var($sales['email'],FILTER_VALIDATE_EMAIL)):?><a href="mailto:<?=e($sales['email'])?>"><span>ایمیل فروش</span><bdi dir="ltr"><?=e($sales['email'])?></bdi></a><?php endif?><?php if($sales['hours']!==''):?><div><span>ساعات پاسخگویی</span><strong><?=e($sales['hours'])?></strong></div><?php endif?></div><div class="service-actions"><?php if(safe_external_url($sales['whatsapp'])):?><a class="btn btn-secondary" href="<?=e($sales['whatsapp'])?>" target="_blank" rel="noopener noreferrer">واتساپ فروش</a><?php endif?><?php if(safe_external_url($sales['bale'])):?><a class="btn btn-secondary" href="<?=e($sales['bale'])?>" target="_blank" rel="noopener noreferrer">بله فروش</a><?php endif?><a class="btn btn-primary" href="<?=e(app_url('contact.php?department=sales'))?>">ارسال درخواست مشاوره</a></div></section>
<section class="service-card"><h2>برای مشاوره بهتر چه اطلاعاتی بگویم؟</h2><ul class="service-check-list"><li>نوع محصولی که قصد خرید آن را دارید</li><li>بودجه تقریبی یا بازه قیمتی مدنظر</li><li>ویژگی‌ها یا برندهایی که برایتان مهم هستند</li><li>اگر مدل مشخصی دیده‌اید، نام یا کد همان کالا</li></ul></section>
</article>
<aside class="service-aside"><div class="service-summary service-summary--sales"><span>دسترسی سریع</span><a href="<?=e(app_url('shop.php'))?>">مشاهده محصولات</a><?php if((string)setting('compare_enabled','1')==='1'):?><a href="<?=e(app_url('compare.php'))?>">مقایسه محصولات</a><?php endif?><a href="<?=e(app_url('payment-methods.php'))?>">روش‌های پرداخت</a><a href="<?=e(app_url('shipping-guide.php'))?>">نحوه ارسال سفارش</a></div><a class="btn btn-secondary" href="<?=e(app_url('support.php'))?>">پیگیری سفارش و پشتیبانی</a></aside>
</div></section></main>
<?php require BASE_PATH . '/app/views/footer.php';
