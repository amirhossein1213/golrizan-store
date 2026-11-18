<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$pageKey = 'contact';
$storeName = (string)setting('store_name', 'لوازم خانگی گلریزان');
$pageTitle = 'تماس با ما | ' . $storeName;
$pageDescription = 'راه‌های ارتباط با پشتیبانی مشتریان و واحد فروش و مشاوره خرید ' . $storeName . '.';

$generalPhone = trim((string)setting('phone', ''));
$generalMobile = trim((string)setting('mobile', ''));
$generalEmail = trim((string)setting('email', ''));
$storeAddress = trim((string)setting('address', ''));

$support = [
    'phone'=>trim((string)setting('support_phone','')) ?: $generalPhone,
    'mobile'=>trim((string)setting('support_mobile','')) ?: $generalMobile,
    'email'=>trim((string)setting('support_email','')) ?: $generalEmail,
    'hours'=>trim((string)setting('support_hours','')),
    'whatsapp'=>trim((string)setting('support_whatsapp_url','')),
    'bale'=>trim((string)setting('support_bale_url','')),
    'description'=>trim((string)setting('support_description','')) ?: 'برای پیگیری سفارش، وضعیت ارسال، مرجوعی و خدمات پس از فروش با پشتیبانی مشتریان در ارتباط باشید.',
];
$sales = [
    'phone'=>trim((string)setting('sales_phone','')) ?: $generalPhone,
    'mobile'=>trim((string)setting('sales_mobile','')) ?: $generalMobile,
    'email'=>trim((string)setting('sales_email','')) ?: $generalEmail,
    'hours'=>trim((string)setting('sales_hours','')),
    'whatsapp'=>trim((string)setting('sales_whatsapp_url','')),
    'bale'=>trim((string)setting('sales_bale_url','')),
    'description'=>trim((string)setting('sales_description','')) ?: 'برای مشاوره قبل از خرید، انتخاب محصول، اطلاع از موجودی و شرایط فروش با واحد فروش در ارتباط باشید.',
];
$requestedDepartment = scalar_string($_GET['department'] ?? 'support');
if (!in_array($requestedDepartment, ['support','sales'], true)) $requestedDepartment = 'support';

require BASE_PATH . '/app/views/header.php';
?>
<main class="contact-page">
<section class="page-hero"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>تماس با ما</span></div><h1>ارتباط با گل‌ریزان</h1><p>برای پیگیری سفارش و خدمات پس از فروش با پشتیبانی مشتریان، و برای مشاوره قبل از خرید با واحد فروش تماس بگیرید.</p></div></section>
<section class="section"><div class="container">
<div class="contact-support-grid">
<article class="contact-support-card contact-support-card--customer"><span class="contact-support-card__label">پشتیبانی مشتریان</span><h2>پیگیری سفارش و خدمات پس از فروش</h2><p><?=e($support['description'])?></p><div class="contact-support-list">
<?php if($support['mobile']!==''):?><a href="tel:<?=e(normalize_phone($support['mobile']))?>"><span>موبایل</span><bdi dir="ltr"><?=e($support['mobile'])?></bdi></a><?php endif?>
<?php if($support['phone']!==''):?><a href="tel:<?=e(normalize_phone($support['phone']))?>"><span>تلفن</span><bdi dir="ltr"><?=e($support['phone'])?></bdi></a><?php endif?>
<?php if($support['email']!==''&&filter_var($support['email'],FILTER_VALIDATE_EMAIL)):?><a href="mailto:<?=e($support['email'])?>"><span>ایمیل</span><bdi dir="ltr"><?=e($support['email'])?></bdi></a><?php endif?>
<?php if($support['hours']!==''):?><div><span>ساعات پاسخگویی</span><strong><?=e($support['hours'])?></strong></div><?php endif?>
</div><div class="contact-channel-actions"><?php if(safe_external_url($support['whatsapp'])):?><a class="btn btn-secondary" href="<?=e($support['whatsapp'])?>" target="_blank" rel="noopener noreferrer">واتساپ پشتیبانی</a><?php endif?><?php if(safe_external_url($support['bale'])):?><a class="btn btn-secondary" href="<?=e($support['bale'])?>" target="_blank" rel="noopener noreferrer">بله پشتیبانی</a><?php endif?></div></article>

<article class="contact-support-card contact-support-card--sales"><span class="contact-support-card__label">فروش و مشاوره خرید</span><h2>انتخاب کالا و اطلاع از موجودی</h2><p><?=e($sales['description'])?></p><div class="contact-support-list">
<?php if($sales['mobile']!==''):?><a href="tel:<?=e(normalize_phone($sales['mobile']))?>"><span>موبایل فروش</span><bdi dir="ltr"><?=e($sales['mobile'])?></bdi></a><?php endif?>
<?php if($sales['phone']!==''):?><a href="tel:<?=e(normalize_phone($sales['phone']))?>"><span>تلفن فروش</span><bdi dir="ltr"><?=e($sales['phone'])?></bdi></a><?php endif?>
<?php if($sales['email']!==''&&filter_var($sales['email'],FILTER_VALIDATE_EMAIL)):?><a href="mailto:<?=e($sales['email'])?>"><span>ایمیل فروش</span><bdi dir="ltr"><?=e($sales['email'])?></bdi></a><?php endif?>
<?php if($sales['hours']!==''):?><div><span>ساعات پاسخگویی</span><strong><?=e($sales['hours'])?></strong></div><?php endif?>
</div><div class="contact-channel-actions"><?php if(safe_external_url($sales['whatsapp'])):?><a class="btn btn-secondary" href="<?=e($sales['whatsapp'])?>" target="_blank" rel="noopener noreferrer">واتساپ فروش</a><?php endif?><?php if(safe_external_url($sales['bale'])):?><a class="btn btn-secondary" href="<?=e($sales['bale'])?>" target="_blank" rel="noopener noreferrer">بله فروش</a><?php endif?></div></article>
</div>

<div class="contact-grid contact-grid--split">
<div class="content-card"><h2>اطلاعات عمومی فروشگاه</h2><?php if($storeAddress!==''):?><p><strong>نشانی:</strong> <?=e($storeAddress)?></p><?php endif?><?php if($generalEmail!==''&&filter_var($generalEmail,FILTER_VALIDATE_EMAIL)):?><p><strong>ایمیل عمومی:</strong> <a href="mailto:<?=e($generalEmail)?>"><?=e($generalEmail)?></a></p><?php endif?><p class="muted">برای رسیدگی سریع‌تر، هنگام پیگیری سفارش شماره سفارش خود را آماده داشته باشید.</p></div>
<form class="checkout-card" data-contact-form><h2>ارسال پیام</h2><div class="hp-field" aria-hidden="true"><label>وب‌سایت</label><input name="website" tabindex="-1" autocomplete="off"></div><div class="field"><label>پیام برای کدام واحد است؟</label><select name="department" required><option value="support" <?=$requestedDepartment==='support'?'selected':''?>>پشتیبانی مشتریان — پیگیری سفارش، ارسال و مرجوعی</option><option value="sales" <?=$requestedDepartment==='sales'?'selected':''?>>فروش و مشاوره خرید — انتخاب کالا و موجودی</option></select></div><div class="field"><label>نام و نام خانوادگی</label><input name="name" minlength="2" maxlength="160" required></div><div class="field"><label>شماره موبایل</label><input name="phone" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" required></div><div class="field"><label>موضوع</label><input name="subject" maxlength="200" placeholder="مثلاً پیگیری سفارش یا مشاوره خرید"></div><div class="field"><label>متن پیام</label><textarea name="message" rows="5" minlength="10" maxlength="5000" required></textarea></div><div class="form-message" data-form-message></div><button class="btn btn-primary">ارسال پیام</button></form>
</div></div></section></main>
<?php require BASE_PATH . '/app/views/footer.php';
