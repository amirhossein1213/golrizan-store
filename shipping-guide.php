<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$pageKey = 'shipping-guide';
$storeName = (string)setting('store_name', 'لوازم خانگی گلریزان');
$pageTitle = 'نحوه ارسال سفارش‌ها | ' . $storeName;
$pageDescription = 'توضیح کامل روش پس‌کرایه، پرداخت هزینه حمل درب منزل، زمان ۴ تا ۵ روز کاری و ارسال رایگان کالاهای منتخب جشنواره.';
$canonical = app_url('shipping-guide.php');
$breadcrumbs = [['name'=>'خانه','url'=>app_url()],['name'=>'مرکز اعتماد','url'=>app_url('trust.php')],['name'=>'نحوه ارسال سفارش','url'=>$canonical]];
require BASE_PATH . '/app/views/header.php';
?>
<main class="guide-page guide-page--shipping">
<section class="page-hero guide-hero">
  <div class="container">
    <div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><a href="<?=e(app_url('trust.php'))?>">مرکز اعتماد</a><span>/</span><span>نحوه ارسال سفارش</span></div>
    <span class="guide-hero__eyebrow">راهنمای ارسال گل‌ریزان</span>
    <h1>نحوه ارسال سفارش‌ها</h1>
    <p>در این صفحه شرایط ارسال، زمان آماده‌سازی و شیوه پرداخت هزینه حمل را شفاف توضیح داده‌ایم.</p>
  </div>
</section>
<section class="section guide-page__content">
  <div class="container guide-layout">
    <article class="guide-main">
      <section class="guide-intro-card"><span>نکته اصلی</span><h2>ارسال سفارش‌های عادی به‌صورت پس‌کرایه است</h2><p>در روش پس‌کرایه، هزینه حمل هنگام ثبت سفارش اینترنتی از شما دریافت نمی‌شود. مرسوله به آدرس ثبت‌شده ارسال می‌شود و هزینه کرایه را هنگام تحویل، درب منزل به مأمور یا شرکت حمل پرداخت می‌کنید.</p></section>
      <section class="guide-section"><h2>فرآیند ارسال سفارش</h2><ol class="guide-steps"><li><strong>ثبت و پرداخت سفارش</strong><p>پس از ثبت نهایی و تکمیل پرداخت کالا، سفارش وارد مرحله بررسی و آماده‌سازی می‌شود.</p></li><li><strong>بررسی و بسته‌بندی</strong><p>کالا از نظر اطلاعات سفارش بررسی و برای ارسال ایمن بسته‌بندی می‌شود.</p></li><li><strong>تحویل به شرکت حمل</strong><p>مرسوله به شرکت حمل تحویل داده می‌شود و در صورت ثبت کد رهگیری، اطلاعات آن برای مشتری ارسال خواهد شد.</p></li><li><strong>تحویل درب منزل</strong><p>مشتری کالا را در آدرس ثبت‌شده تحویل می‌گیرد و هزینه کرایه را در مقصد پرداخت می‌کند.</p></li></ol></section>
      <section class="guide-section"><h2>هزینه ارسال چگونه محاسبه می‌شود؟</h2><p>مبلغ کرایه بر اساس شهر مقصد، وزن، حجم بسته، نوع کالا و تعرفه شرکت حمل تعیین می‌شود. به همین دلیل ممکن است مبلغ حمل سفارش‌های مختلف یکسان نباشد.</p><div class="guide-note"><strong>پرداخت هزینه حمل</strong><p>در سفارش‌های عادی، هزینه کرایه درب منزل و هنگام تحویل مرسوله پرداخت می‌شود.</p></div></section>
      <section class="guide-section"><h2>زمان ارسال و تحویل</h2><p>از زمان نهایی‌شدن سفارش، آماده‌سازی، بسته‌بندی و ارسال کالا معمولاً <strong>۴ تا ۵ روز کاری</strong> زمان می‌برد. تعطیلات رسمی، مقصدهای دورتر، شرایط جوی یا محدودیت شرکت حمل ممکن است باعث تغییر زمان تحویل شوند.</p></section>
      <section class="guide-section"><h2>ارسال رایگان جشنواره</h2><p>بعضی کالاهای منتخب در جشنواره‌ها ممکن است شامل ارسال رایگان باشند. در این حالت، وضعیت ارسال رایگان در صفحه همان محصول یا نشان جشنواره مشخص می‌شود. ارسال رایگان فقط برای کالاها و بازه‌هایی معتبر است که فروشگاه صریحاً اعلام کرده باشد.</p><ul class="guide-check-list"><li>ارسال رایگان برای همه محصولات دائمی نیست.</li><li>فقط کالاهای دارای برچسب یا شرایط ارسال رایگان مشمول هستند.</li><li>شرایط هر جشنواره ممکن است با جشنواره دیگر متفاوت باشد.</li></ul></section>
      <section class="guide-section"><h2>کالاهای بزرگ و سنگین</h2><p>برای برخی لوازم خانگی بزرگ یا سنگین، ممکن است پیش از ارسال هماهنگی تلفنی انجام شود. هزینه حمل چنین کالاهایی بر اساس شرایط شرکت حمل و مقصد مشخص می‌شود.</p></section>
    </article>
    <aside class="guide-aside"><div class="guide-summary"><span>خلاصه ارسال</span><ul><li><strong>روش ارسال:</strong> پس‌کرایه</li><li><strong>پرداخت کرایه:</strong> درب منزل</li><li><strong>زمان معمول:</strong> ۴ تا ۵ روز کاری</li><li><strong>جشنواره:</strong> ارسال رایگان کالاهای منتخب</li></ul></div><a class="btn btn-primary" href="<?=e(app_url('shop.php'))?>">مشاهده محصولات</a><a class="btn btn-secondary" href="<?=e(app_url('contact.php'))?>">پرسش از پشتیبانی</a></aside>
  </div>
</section>
</main>
<?php require BASE_PATH . '/app/views/footer.php';
