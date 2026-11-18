<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$pageKey = 'support';
$storeName = (string)setting('store_name', 'لوازم خانگی گلریزان');
$pageTitle = 'پشتیبانی مشتریان | ' . $storeName;
$pageDescription = 'راه‌های ارتباط با پشتیبانی مشتریان، پیگیری سفارش، ارسال، مرجوعی و خدمات پس از فروش ' . $storeName . '.';
$canonical = app_url('support.php');
$breadcrumbs = [['name'=>'خانه','url'=>app_url()],['name'=>'پشتیبانی مشتریان','url'=>$canonical]];
$generalPhone = trim((string)setting('phone',''));
$generalMobile = trim((string)setting('mobile',''));
$generalEmail = trim((string)setting('email',''));
$support = [
    'phone'=>trim((string)setting('support_phone','')) ?: $generalPhone,
    'mobile'=>trim((string)setting('support_mobile','')) ?: $generalMobile,
    'email'=>trim((string)setting('support_email','')) ?: $generalEmail,
    'hours'=>trim((string)setting('support_hours','')),
    'whatsapp'=>trim((string)setting('support_whatsapp_url','')),
    'bale'=>trim((string)setting('support_bale_url','')),
    'description'=>trim((string)setting('support_description','')) ?: 'برای پیگیری سفارش، وضعیت ارسال، مرجوعی و خدمات پس از فروش با پشتیبانی مشتریان در ارتباط باشید.',
];
$ticketsEnabled = (string)setting('tickets_enabled','1') === '1';
$accountsEnabled = (string)setting('customer_accounts_enabled','1') === '1';
$isLoggedIn = $accountsEnabled && customer_logged_in();
require BASE_PATH . '/app/views/header.php';
?>
<main class="service-page service-page--support">
<section class="page-hero service-hero service-hero--support"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>پشتیبانی مشتریان</span></div><span class="service-hero__eyebrow">همراه شما بعد از خرید</span><h1>پشتیبانی مشتریان گل‌ریزان</h1><p><?=e($support['description'])?></p></div></section>
<section class="section service-page__body"><div class="container service-layout">
<article class="service-main">
<section class="service-card service-card--highlight"><span class="service-card__eyebrow">راه‌های ارتباطی</span><h2>برای چه موضوعاتی با پشتیبانی تماس بگیرم؟</h2><p>اگر سفارش ثبت کرده‌اید و درباره پرداخت، وضعیت آماده‌سازی، ارسال، کد رهگیری، سلامت کالا، مرجوعی یا خدمات پس از فروش سؤال دارید، این واحد پاسخ‌گوی شماست.</p><div class="service-topic-grid"><div><strong>پیگیری سفارش</strong><span>وضعیت ثبت، پرداخت و آماده‌سازی</span></div><div><strong>ارسال و رهگیری</strong><span>زمان ارسال و کد رهگیری مرسوله</span></div><div><strong>مرجوعی</strong><span>کالای ناسالم یا آسیب‌دیده</span></div><div><strong>خدمات پس از فروش</strong><span>راهنمایی درباره گارانتی و پشتیبانی</span></div></div></section>
<section class="service-card"><h2>اطلاعات تماس پشتیبانی</h2><div class="service-contact-list"><?php if($support['mobile']!==''):?><a href="tel:<?=e(normalize_phone($support['mobile']))?>"><span>موبایل پشتیبانی</span><bdi dir="ltr"><?=e($support['mobile'])?></bdi></a><?php endif?><?php if($support['phone']!==''):?><a href="tel:<?=e(normalize_phone($support['phone']))?>"><span>تلفن پشتیبانی</span><bdi dir="ltr"><?=e($support['phone'])?></bdi></a><?php endif?><?php if($support['email']!==''&&filter_var($support['email'],FILTER_VALIDATE_EMAIL)):?><a href="mailto:<?=e($support['email'])?>"><span>ایمیل پشتیبانی</span><bdi dir="ltr"><?=e($support['email'])?></bdi></a><?php endif?><?php if($support['hours']!==''):?><div><span>ساعات پاسخگویی</span><strong><?=e($support['hours'])?></strong></div><?php endif?></div><div class="service-actions"><?php if(safe_external_url($support['whatsapp'])):?><a class="btn btn-secondary" href="<?=e($support['whatsapp'])?>" target="_blank" rel="noopener noreferrer">واتساپ پشتیبانی</a><?php endif?><?php if(safe_external_url($support['bale'])):?><a class="btn btn-secondary" href="<?=e($support['bale'])?>" target="_blank" rel="noopener noreferrer">بله پشتیبانی</a><?php endif?><a class="btn btn-primary" href="<?=e(app_url('contact.php?department=support'))?>">ارسال پیام به پشتیبانی</a></div></section>
<section class="service-card"><h2>قبل از تماس این اطلاعات را آماده کنید</h2><ul class="service-check-list"><li>شماره سفارش یا شماره موبایلی که با آن خرید کرده‌اید</li><li>در موضوع مرجوعی، عکس یا ویدئوی واضح از آسیب کالا و بسته‌بندی</li><li>در موضوع پرداخت، زمان تقریبی تراکنش و نتیجه‌ای که در سایت دیده‌اید</li><li>در موضوع ارسال، کد رهگیری در صورت دریافت آن</li></ul></section>
</article>
<aside class="service-aside"><div class="service-summary service-summary--support"><span>دسترسی سریع</span><a href="<?=e(app_url('track-order.php'))?>">رهگیری سفارش</a><?php if($ticketsEnabled&&$accountsEnabled):?><a href="<?=e(app_url($isLoggedIn?'tickets.php':'customer-login.php'))?>"><?=$isLoggedIn?'ثبت و مشاهده تیکت':'ورود برای ثبت تیکت'?></a><?php endif?><a href="<?=e(app_url('return-policy.php'))?>">شرایط مرجوعی</a><a href="<?=e(app_url('shipping-guide.php'))?>">نحوه ارسال سفارش</a></div><a class="btn btn-secondary" href="<?=e(app_url('sales.php'))?>">نیاز به مشاوره خرید دارم</a></aside>
</div></section></main>
<?php require BASE_PATH . '/app/views/footer.php';
