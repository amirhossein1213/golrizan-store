<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$pageKey = 'return-policy';
$storeName = (string)setting('store_name', 'لوازم خانگی گلریزان');
$pageTitle = 'شرایط مرجوعی کالا | ' . $storeName;
$pageDescription = 'شرایط مرجوعی کالای ناسالم، آسیب‌دیده، شکسته یا دارای ایراد فیزیکی و نحوه ثبت درخواست در فروشگاه گل‌ریزان.';
$canonical = app_url('return-policy.php');
$breadcrumbs = [['name'=>'خانه','url'=>app_url()],['name'=>'مرکز اعتماد','url'=>app_url('trust.php')],['name'=>'شرایط مرجوعی','url'=>$canonical]];
require BASE_PATH . '/app/views/header.php';
?>
<main class="guide-page guide-page--return">
<section class="page-hero guide-hero">
  <div class="container">
    <div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><a href="<?=e(app_url('trust.php'))?>">مرکز اعتماد</a><span>/</span><span>شرایط مرجوعی</span></div>
    <span class="guide-hero__eyebrow">ضمانت سلامت کالا</span>
    <h1>شرایط مرجوعی کالا</h1>
    <p>سلامت فیزیکی کالا تعهد فروشگاه است؛ اگر کالا سالم به دست شما نرسد، درخواست مرجوعی بررسی و پیگیری می‌شود.</p>
  </div>
</section>
<section class="section guide-page__content">
  <div class="container guide-layout">
    <article class="guide-main">
      <section class="guide-intro-card guide-intro-card--return"><span>تعهد گل‌ریزان</span><h2>اگر کالا سالم نباشد، در هر صورت امکان مرجوعی دارد</h2><p>اگر محصول هنگام تحویل آسیب‌دیده، شکسته، فرورفته یا دارای هرگونه مشکل مرتبط با سلامت فیزیکی باشد، امکان ثبت درخواست مرجوعی وجود دارد و تیم پشتیبانی فروشگاه مراحل رسیدگی را با شما هماهنگ می‌کند.</p></section>
      <section class="guide-section"><h2>چه مواردی شامل مرجوعی می‌شوند؟</h2><ul class="guide-check-list"><li>شکستگی یا ترک‌خوردگی کالا</li><li>فرورفتگی، ضربه یا آسیب ظاهری ناشی از حمل</li><li>ناقص‌بودن قطعات اصلی داخل بسته</li><li>خیس‌شدگی یا آسیب جدی بسته‌بندی که به کالا صدمه زده باشد</li><li>هر وضعیتی که نشان دهد کالا سالم به مشتری تحویل نشده است</li></ul></section>
      <section class="guide-section"><h2>پس از دریافت کالا چه کاری انجام دهید؟</h2><ol class="guide-steps"><li><strong>سلامت کالا را بررسی کنید</strong><p>در اولین فرصت وضعیت بسته‌بندی، بدنه و اجزای اصلی کالا را بررسی کنید.</p></li><li><strong>از مشکل عکس یا ویدئو بگیرید</strong><p>ثبت تصویر واضح از آسیب، بسته‌بندی و برچسب مرسوله به سریع‌ترشدن بررسی کمک می‌کند.</p></li><li><strong>به پشتیبانی اطلاع دهید</strong><p>از طریق صفحه تماس، حساب مشتری یا شماره پشتیبانی، شماره سفارش و توضیح مشکل را اعلام کنید.</p></li><li><strong>هماهنگی مرجوعی</strong><p>پس از بررسی اولیه، پشتیبانی روش بازگرداندن یا ادامه رسیدگی را به شما اعلام می‌کند.</p></li></ol></section>
      <section class="guide-section"><h2>نکات مهم مرجوعی</h2><div class="guide-note guide-note--return"><strong>کالا و متعلقات را نگه دارید</strong><p>تا پایان بررسی، کالا، کارتن، متعلقات، دفترچه‌ها و برچسب‌های بسته‌بندی را نگه دارید و بدون هماهنگی فروشگاه کالا را ارسال نکنید.</p></div><ul class="guide-check-list"><li>شماره سفارش را هنگام تماس با پشتیبانی اعلام کنید.</li><li>از استفاده بیشتر از کالای آسیب‌دیده خودداری کنید.</li><li>مرجوعی پس از هماهنگی با پشتیبانی انجام می‌شود.</li><li>نتیجه نهایی پس از بررسی وضعیت کالا اعلام خواهد شد.</li></ul></section>
      <section class="guide-section"><h2>روش ثبت درخواست</h2><p>مشتریان دارای حساب می‌توانند درخواست خود را از بخش سفارش‌های حساب کاربری ثبت کنند. همچنین امکان تماس مستقیم با پشتیبانی فروشگاه برای راهنمایی وجود دارد.</p><div class="guide-actions"><a class="btn btn-primary" href="<?=e(app_url('account.php'))?>">ورود به حساب مشتری</a><a class="btn btn-secondary" href="<?=e(app_url('contact.php'))?>">تماس با پشتیبانی</a></div></section>
    </article>
    <aside class="guide-aside"><div class="guide-summary guide-summary--return"><span>خلاصه مرجوعی</span><ul><li><strong>شرط اصلی:</strong> سالم نبودن کالا</li><li><strong>اقدام مشتری:</strong> اطلاع سریع به پشتیبانی</li><li><strong>مدارک مفید:</strong> عکس یا ویدئوی آسیب</li><li><strong>هماهنگی:</strong> پیش از بازگرداندن کالا</li></ul></div><a class="btn btn-primary" href="<?=e(app_url('contact.php'))?>">ارتباط با پشتیبانی</a><a class="btn btn-secondary" href="<?=e(app_url('trust.php'))?>">بازگشت به مرکز اعتماد</a></aside>
  </div>
</section>
</main>
<?php require BASE_PATH . '/app/views/footer.php';
