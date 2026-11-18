<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$pageKey = 'trust';
$storeName = (string)setting('store_name', 'لوازم خانگی گلریزان');
$trustTitle = trim((string)setting('trust_page_title', '')) ?: 'مرکز اعتماد ' . $storeName;
$pageTitle = $trustTitle . ' | ' . $storeName;
$pageDescription = seo_clean_text((string)setting('trust_page_intro','پیش از خرید، تعهدات فروشگاه و مسیرهای پشتیبانی را شفاف ببینید.'), 170);
$canonical = app_url('trust.php');
$breadcrumbs = [['name'=>'خانه','url'=>app_url()],['name'=>'مرکز اعتماد','url'=>$canonical]];
$trustItems = trust_center_items();
$trustIcons = [
    'shield'=>'<path d="M12 2 20 5v6c0 5.2-3.4 9.3-8 11-4.6-1.7-8-5.8-8-11V5l8-3Zm-3 10 2 2 4-5"/>',
    'truck'=>'<path d="M3 5h11v11H3V5Zm11 4h4l3 4v3h-3m-4 0H9m-6 0H2M7 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm13 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>',
    'receipt'=>'<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6M9 12h6M9 16h4"/>',
    'support'=>'<path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a2 2 0 0 1-2-2v-2a2 2 0 0 1 1-2Zm16 0h-3v6h2a2 2 0 0 0 2-2v-2a2 2 0 0 0-1-2Z"/>',
    'tracking'=>'<path d="M4 4h16v16H4V4Zm4 4h8M8 12h5M8 16h7"/>',
    'return'=>'<path d="M7 7h10a4 4 0 0 1 4 4v6h-2v-6a2 2 0 0 0-2-2H7v4L2 8l5-5v4Z"/>',
    'payment'=>'<path d="M3 6h18v13H3V6Zm0 4h18M7 15h4"/>',
    'store'=>'<path d="M4 10v10h16V10M3 10l2-6h14l2 6M8 20v-6h8v6"/>',
];
require BASE_PATH . '/app/views/header.php';
?>
<main class="trust-page trust-page--clean">
<section class="page-hero trust-hero trust-hero--clean">
  <div class="container">
    <div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>مرکز اعتماد</span></div>
    <span class="trust-hero__eyebrow">خرید آگاهانه و شفاف</span>
    <h1><?=e($trustTitle)?></h1>
    <p><?=e((string)setting('trust_page_intro','پیش از خرید، تعهدات فروشگاه و مسیرهای پشتیبانی را شفاف ببینید.'))?></p>
    <div class="trust-page__hero-points">
      <span>ارسال عادی به‌صورت پس‌کرایه</span>
      <span>پرداخت کرایه درب منزل</span>
      <span>زمان معمول ارسال ۴ تا ۵ روز کاری</span>
      <span>مرجوعی کالا در صورت سالم نبودن</span>
    </div>
  </div>
</section>

<section class="section trust-page__intro-wrap">
  <div class="container">
    <div class="trust-page__intro-card">
      <div>
        <span>مرکز راهنمای مشتریان</span>
        <h2>اطلاعات مهم خرید، پرداخت، ارسال و مرجوعی در یک نگاه</h2>
        <p>در این بخش تلاش کرده‌ایم مهم‌ترین تعهدات فروشگاه را به‌صورت واضح و خلاصه نمایش دهیم تا پیش از خرید، مسیر پرداخت و ارسال سفارش، شرایط مرجوعی و اصول خدمات فروشگاه برای شما روشن باشد.</p>
      </div>
      <div class="trust-page__intro-bullets">
        <strong>چه چیزهایی را اینجا می‌بینید؟</strong>
        <ul>
          <li>روش ارسال سفارش و زمان تقریبی تحویل</li>
          <li>روش پرداخت آنلاین و نحوه تأیید تراکنش</li><li>نحوه پرداخت کرایه در سفارش‌های عادی</li>
          <li>توضیح کالاهای جشنواره‌ای با ارسال رایگان</li>
          <li>شرایط مرجوعی در صورت سالم نبودن کالا</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="section trust-page__links-wrap">
  <div class="container">
    <div class="trust-page__links-grid">
      <article class="trust-page__link-card">
        <span class="trust-page__link-chip">راهنمای ارسال</span>
        <h2>نحوه ارسال سفارش‌ها</h2>
        <p>سفارش‌های عادی به‌صورت پس‌کرایه ارسال می‌شوند، هزینه حمل درب منزل پرداخت می‌شود و زمان معمول آماده‌سازی و ارسال حدود ۴ تا ۵ روز کاری است.</p>
        <a class="btn btn-primary" href="<?=e(app_url('shipping-guide.php'))?>">مشاهده صفحه نحوه ارسال سفارش</a>
      </article>
      <article class="trust-page__link-card trust-page__link-card--return">
        <span class="trust-page__link-chip trust-page__link-chip--green">شرایط مرجوعی</span>
        <h2>راهنمای مرجوعی کالا</h2>
        <p>اگر کالا سالم به دست شما نرسد، آسیب‌دیده باشد یا مشکل فیزیکی داشته باشد، امکان مرجوعی وجود دارد و پشتیبانی فروشگاه موضوع را پیگیری می‌کند.</p>
        <a class="btn btn-primary" href="<?=e(app_url('return-policy.php'))?>">مشاهده صفحه شرایط مرجوعی</a>
      </article>
      <article class="trust-page__link-card trust-page__link-card--payment">
        <span class="trust-page__link-chip trust-page__link-chip--blue">روش‌های پرداخت</span>
        <h2>راهنمای پرداخت سفارش</h2>
        <p>مراحل پرداخت آنلاین با زیبال، تأیید سروری تراکنش، هماهنگی پرداخت و راهکار پرداخت‌های ناموفق را به‌صورت کامل مشاهده کنید.</p>
        <a class="btn btn-primary" href="<?=e(app_url('payment-methods.php'))?>">مشاهده صفحه روش‌های پرداخت</a>
      </article>
    </div>
  </div>
</section>

<section class="section trust-page__commitments">
  <div class="container">
    <div class="trust-page__section-head"><span>تعهدات فروشگاه</span><h2>خدمات و ضمانت‌های گل‌ریزان</h2><p>این بخش شامل توضیحاتی است که مدیریت فروشگاه برای آشنایی بیشتر مشتریان ثبت کرده است.</p></div>
    <div class="trust-page__grid"><?php foreach($trustItems as $item): ?><article class="trust-page__card"><span class="trust-page__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><?=($trustIcons[$item['icon_key']] ?? $trustIcons['shield'])?></svg></span><div><h2><?=e($item['title'])?></h2><strong><?=e($item['summary'])?></strong><?php if(trim((string)$item['details'])!==''): ?><p><?=e($item['details'])?></p><?php endif; ?></div></article><?php endforeach; ?><?php if(!$trustItems): ?><div class="content-card"><p>اطلاعات مرکز اعتماد به‌زودی تکمیل می‌شود.</p></div><?php endif; ?></div>
  </div>
</section>

<section class="section trust-page__cta">
  <div class="container">
    <div><span>هنوز پرسشی دارید؟</span><h2>برای راهنمایی بیشتر با پشتیبانی فروشگاه در ارتباط باشید</h2></div>
    <div><a class="btn btn-primary" href="<?=e(app_url('faq.php'))?>">پرسش‌های متداول</a><a class="btn btn-secondary" href="<?=e(app_url('contact.php'))?>">تماس با فروشگاه</a></div>
  </div>
</section>
</main>
<?php require BASE_PATH . '/app/views/footer.php';
