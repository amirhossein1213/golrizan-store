<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET' && current_relative_path() === 'index.php') { header('Location: '.app_url(), true, 301); exit; }

function homepage_public_link(string $value, string $fallback): string
{
    $value = trim($value);
    $decoded = rawurldecode($value);
    if ($value === '' || strlen($value) > 500 || str_starts_with($value, '/') || str_starts_with($value, '//') || str_starts_with($decoded, '/') || str_contains($value, '\\') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $value) || preg_match('/[\x00-\x1F\x7F]/', $decoded) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._~/?&=%-]{0,499}$#', $value)) $value = $fallback;
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) $value = $fallback;
    foreach (explode('/', rawurldecode((string)(parse_url($value, PHP_URL_PATH) ?? ''))) as $segment) if ($segment === '..') $value = $fallback;
    return app_url($value);
}

$pageKey = 'home';
$pageTitle = trim((string)setting('seo_default_title', '')) ?: setting('store_name', 'لوازم خانگی گلریزان') . ' | خرید مطمئن لوازم خانگی';
$pageDescription = trim((string)setting('seo_default_description', '')) ?: (string)setting('store_name', 'لوازم خانگی گلریزان') . '؛ مشاهده شفاف قیمت، موجودی، گارانتی و خرید آنلاین.';
$canonical = app_url();
$categories = all_categories();
$homeCategories = array_values(array_filter($categories, static fn(array $category): bool => (string)$category['slug'] !== 'festival'));
$productLimit = (int)setting('home_products_limit', '4');
if (!in_array($productLimit, [4,8,12], true)) $productLimit = 4;
$featuredProducts = (string)setting('home_show_featured', '1') === '1' ? homepage_available_products('featured', min(24, $productLimit * 2)) : [];
$newestProducts = (string)setting('home_show_newest', '1') === '1' ? homepage_available_products('newest', min(24, $productLimit * 3)) : [];
$limitedDiscountProducts = homepage_limited_discount_products(12);
$limitedDiscountIds = array_fill_keys(array_map(static fn(array $product): int => (int)$product['id'], $limitedDiscountProducts), true);
$mainFestival = null;
$dealProducts = [];
if ((string)setting('home_show_deals', '1') === '1') {
    $configuredDeals = [];
    $directDeals = [];
    try {
        $mainFestival = configured_homepage_festival(null, true);
        $configuredDeals = $mainFestival ? homepage_festival_products((int)$mainFestival['id'], 12) : [];
    } catch (Throwable $festivalError) {
        log_error($festivalError);
        $mainFestival = null;
    }
    try {
        // جشنواره مستقیم محصول مستقل از جشنواره گروهی است و خرابی یکی نباید دیگری را پنهان کند.
        $directDeals = homepage_direct_festival_products(12);
    } catch (Throwable $festivalError) {
        log_error($festivalError);
    }
    $indexedDeals = [];
    foreach (array_merge($configuredDeals, $directDeals) as $deal) {
        $indexedDeals[(int)$deal['id']] = $deal;
        if (count($indexedDeals) >= 12) break;
    }
    $dealProducts = array_values(array_filter($indexedDeals, static fn(array $product): bool => !isset($limitedDiscountIds[(int)$product['id']])));
}
$dealIds = array_fill_keys(array_map(static fn(array $product): int => (int)$product['id'], $dealProducts), true);
$featuredProducts = array_slice(array_values(array_filter($featuredProducts, static fn(array $product): bool => !isset($limitedDiscountIds[(int)$product['id']]) && !isset($dealIds[(int)$product['id']]))), 0, $productLimit);
$featuredIds = array_fill_keys(array_map(static fn(array $product): int => (int)$product['id'], $featuredProducts), true);
$newestProducts = array_slice(array_values(array_filter($newestProducts, static fn(array $product): bool => !isset($limitedDiscountIds[(int)$product['id']]) && !isset($dealIds[(int)$product['id']]) && !isset($featuredIds[(int)$product['id']]))), 0, $productLimit);
$latestBlogPosts = (string)setting('blog_enabled', '1') === '1' && (string)setting('home_show_blog', '1') === '1' ? blog_latest_posts(3) : [];
$promoBanners = (string)setting('home_show_banners', '1') === '1' ? homepage_promo_banners(6) : [];
$bestsellerCategoryLimit = max(2, min(6, (int)setting('home_bestseller_category_limit','4')));
$bestsellerProductLimit = max(2, min(4, (int)setting('home_bestseller_product_limit','3')));
$categoryBestsellerGroups = (string)setting('home_show_category_bestsellers','0') === '1' ? homepage_category_bestseller_groups($bestsellerCategoryLimit, $bestsellerProductLimit) : [];
$instagramUrl = safe_external_url((string)setting('instagram_url','https://www.instagram.com/golrizan1368/')) ?? 'https://www.instagram.com/golrizan1368/';
$instagramUsername = preg_match('/^[A-Za-z0-9._]{1,30}$/', (string)setting('instagram_username','golrizan1368')) ? (string)setting('instagram_username','golrizan1368') : 'golrizan1368';
$instagramImageSetting = trim((string)setting('instagram_profile_image','assets/images/ui/instagram-golrizan1368.webp'));
$instagramImageInternal = safe_internal_image_path($instagramImageSetting);
$instagramImage = $instagramImageInternal !== null && is_file(BASE_PATH . '/' . $instagramImageInternal) ? $instagramImageSetting : 'assets/images/ui/instagram-golrizan1368.webp';
$instagramPhoneMockup = 'assets/images/ui/instagram-iphone-mockup.webp';
$heroImageSetting = trim((string)setting('home_hero_image', ''));
$heroImageInternal = safe_internal_image_path($heroImageSetting);
$heroImage = $heroImageSetting !== '' && (safe_external_url($heroImageSetting) !== null || ($heroImageInternal !== null && is_file(BASE_PATH . '/' . $heroImageInternal))) ? $heroImageSetting : 'assets/images/ui/hero-appliances.svg';
$heroPrimaryUrl = homepage_public_link((string)setting('home_hero_primary_url', 'shop.php'), 'shop.php');
$heroSecondaryUrl = homepage_public_link((string)setting('home_hero_secondary_url', 'blog.php'), 'blog.php');
require BASE_PATH . '/app/views/header.php';
?>
<main class="homepage-v25 homepage-v26">
<section class="home-hero"><div class="container"><div class="home-hero__card">
<div class="home-hero__content"><span class="home-eyebrow"><b></b><?=e((string)setting('home_hero_eyebrow','خرید مستقیم با قیمت و موجودی به‌روز'))?></span><h1><?=e((string)setting('home_hero_title','خانه را هوشمندانه تجهیز کنید'))?><strong><?=e((string)setting('home_hero_highlight','مطمئن، سریع و با قیمت شفاف'))?></strong></h1><p><?=e((string)setting('home_hero_description','مدل‌ها را مقایسه کنید، موجودی واقعی را ببینید و کالای مناسب خانه‌تان را با گارانتی مشخص سفارش دهید.'))?></p><div class="home-hero__actions"><a class="btn btn-primary" href="<?=e($heroPrimaryUrl)?>"><?=e((string)setting('home_hero_primary_label','مشاهده محصولات'))?> <span>←</span></a><?php if(trim((string)setting('home_hero_secondary_label','راهنمای خرید'))!==''):?><a class="btn btn-outline-light" href="<?=e($heroSecondaryUrl)?>"><?=e((string)setting('home_hero_secondary_label','راهنمای خرید'))?></a><?php endif?></div><div class="home-hero__facts"><span>✓ موجودی واقعی</span><span>✓ مقایسه هم‌گروه</span><span>✓ گارانتی مشخص</span></div></div>
<div class="home-hero__visual"><div class="home-hero__glow"></div><img src="<?=e(public_image($heroImage))?>" alt="خرید لوازم خانگی از <?=e((string)setting('store_name','لوازم خانگی گلریزان'))?>" width="640" height="520" decoding="async" fetchpriority="high"><div class="home-hero__note"><span>✓</span><div><strong><?=e((string)setting('home_hero_note_title','کالای اصل و دارای گارانتی'))?></strong><small><?=e((string)setting('home_hero_note_text','جزئیات گارانتی در صفحه هر محصول'))?></small></div></div></div>
</div></div></section>

<section class="home-trust"><div class="container home-trust__grid">
<article><span><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4zM8 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm8 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM4 11h16"/></svg></span><div><strong>ارسال پس‌کرایه</strong><small>پرداخت هزینه حمل هنگام تحویل</small></div></article>
<article><span><svg viewBox="0 0 24 24"><path d="m12 3 8 3v6c0 4.5-3 7.7-8 9-5-1.3-8-4.5-8-9V6l8-3Zm-3 9 2 2 4-5"/></svg></span><div><strong>ضمانت اصالت</strong><small>گارانتی هر کالا کاملاً مشخص است</small></div></article>
<article><span><svg viewBox="0 0 24 24"><path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a2 2 0 0 1-2-2v-2a2 2 0 0 1 1-2Zm16 0h-3v6h2a2 2 0 0 0 2-2v-2a2 2 0 0 0-1-2Z"/></svg></span><div><strong>مشاوره قبل از خرید</strong><small>انتخاب مدل متناسب با نیاز شما</small></div></article>
<article><span><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg></span><div><strong>خرید شفاف</strong><small>قیمت و موجودی به‌روز در صفحه کالا</small></div></article>
</div></section>

<?php if((string)setting('home_show_categories','1')==='1' && $homeCategories):?><section class="section home-categories"><div class="container"><div class="section-head"><div class="section-title"><span>دسترسی سریع</span><h2><?=e((string)setting('home_categories_title','دسته‌بندی‌های محبوب'))?></h2></div><a href="<?=e(app_url('shop.php'))?>">همه دسته‌بندی‌ها ←</a></div><div class="home-category-grid"><?php foreach(array_slice($homeCategories,0,8) as $cat):?><a class="home-category-card" href="<?=e(app_url('shop.php?category='.rawurlencode((string)$cat['slug'])))?>"><span><img src="<?=e(public_image_thumbnail((string)$cat['image_path']))?>" alt="<?=e($cat['name'])?>" loading="lazy" decoding="async" width="96" height="96"></span><strong><?=e($cat['name'])?></strong><small>مشاهده کالاها</small></a><?php endforeach?></div></div></section><?php endif?>

<?php if($promoBanners):?><section class="section home-promo-section" aria-labelledby="home-promo-title"><div class="container"><div class="section-head"><div class="section-title"><span>دسته‌های پرفروش گلریزان</span><h2 id="home-promo-title">پیشنهادهای تصویری فروشگاه</h2></div></div><div class="home-promo-grid"><?php foreach($promoBanners as $banner):?><a class="home-promo-card home-promo-card--<?=e($banner['layout_size'])?>" href="<?=e(storefront_internal_url((string)$banner['link_url']))?>"><img src="<?=e(public_image((string)$banner['image_path']))?>" alt="<?=e($banner['title'])?>" loading="lazy" decoding="async" width="800" height="480"><span class="home-promo-card__shade"></span><span class="home-promo-card__content"><?php if(trim((string)$banner['eyebrow'])!==''):?><small><?=e($banner['eyebrow'])?></small><?php endif?><strong><?=e($banner['title'])?></strong><?php if(trim((string)$banner['subtitle'])!==''):?><em><?=e($banner['subtitle'])?></em><?php endif?><b><?=e($banner['button_label'])?> ←</b></span></a><?php endforeach?></div></div></section><?php endif?>

<?php if($limitedDiscountProducts):?><section class="section home-limited-discounts" aria-labelledby="home-limited-discounts-title"><div class="container"><div class="home-limited-discounts__head"><div class="section-title"><span>فرصت حداکثر سه‌روزه</span><h2 id="home-limited-discounts-title">تخفیف‌های محدود فعال</h2><p>این قیمت‌ها فقط تا پایان زمان هر محصول معتبرند؛ پس از پایان یا لغو، قیمت اصلی خودکار برمی‌گردد.</p></div><a href="<?=e(app_url('shop.php?category=festival'))?>">مشاهده همه تخفیف‌ها ←</a></div><div class="product-grid home-limited-discounts__grid"><?php foreach($limitedDiscountProducts as $item) require BASE_PATH . '/app/views/product-card.php';?></div></div></section><?php endif?>

<?php if($dealProducts):
$festivalTitle = trim((string)($mainFestival['title'] ?? '')) ?: 'جشنواره محصولات';
$festivalText = trim((string)($mainFestival['banner_text'] ?? '')) ?: 'کالاهای منتخب با قیمت جشنواره‌ای یا ارسال رایگان';
$festivalEndTimestamp = $mainFestival ? (strtotime((string)$mainFestival['ends_at']) ?: 0) : 0;
?><section class="section home-festival-section" aria-labelledby="home-festival-title"><div class="container"><div class="home-festival"><aside class="home-festival__intro"><span>جشنواره ویژه</span><h2 id="home-festival-title"><?=e($festivalTitle)?></h2><p><?=e($festivalText)?></p><?php if($festivalEndTimestamp>0):?><div class="home-festival__countdown" data-festival-countdown data-ends-at="<?=e((string)($festivalEndTimestamp*1000))?>"><b data-festival-time>در حال محاسبه…</b><small>تا پایان جشنواره</small></div><?php else:?><div class="home-festival__countdown"><b>پیشنهادهای فعال</b><small>تا زمان پایان موجودی</small></div><?php endif?><a href="<?=e(app_url('shop.php?category=festival'))?>">مشاهده همه جشنواره‌ها ←</a></aside><div class="home-festival__rail" aria-label="محصولات جشنواره"><?php foreach($dealProducts as $deal):
$hasFestivalDiscount=(int)$deal['festival_saving']>0 && (int)$deal['base_price']>(int)$deal['price'];
$dealPercent=$hasFestivalDiscount?max(1,min(99,(int)round(((int)$deal['festival_saving']/max(1,(int)$deal['base_price']))*100))):0;
$isFestivalFreeShipping=!empty($deal['festival_free_shipping']);
?><a class="home-festival-card" href="<?=e(product_url($deal))?>"><span class="home-festival-card__image"><img src="<?=e(public_image_thumbnail((string)$deal['image_path']))?>" alt="<?=e($deal['title'])?>" loading="lazy" decoding="async" width="180" height="180"><?php if($dealPercent>0):?><em><?=fa_number($dealPercent)?>٪</em><?php elseif($isFestivalFreeShipping):?><em>ارسال رایگان</em><?php endif?></span><small><?=e($deal['brand_name'])?></small><strong><?=e($deal['short_title']?:$deal['title'])?></strong><?php if($hasFestivalDiscount):?><span class="home-festival-card__old"><?=fa_number(format_price($deal['base_price']))?></span><?php elseif($isFestivalFreeShipping):?><span class="home-festival-card__shipping">ارسال رایگان جشنواره</span><?php endif?><b><?=fa_number(format_price($deal['price']))?> <small>تومان</small></b></a><?php endforeach?></div></div></div></section><?php endif?>

<?php if($featuredProducts):?><section class="section home-products"><div class="container"><div class="section-head"><div class="section-title"><span>انتخاب فروشگاه</span><h2><?=e((string)setting('home_featured_title','پیشنهادهای منتخب برای خانه شما'))?></h2></div><a href="<?=e(app_url('shop.php?stock=1'))?>">ورود به فروشگاه ←</a></div><div class="product-grid"><?php foreach($featuredProducts as $item) require BASE_PATH . '/app/views/product-card.php';?></div></div></section><?php endif?>

<?php if((string)setting('compare_enabled','1')==='1' || (string)setting('product_alerts_enabled','1')==='1'):?><section class="home-smart"><div class="container home-smart__inner"><div><span>خرید دقیق‌تر</span><h2>قبل از انتخاب، مدل‌های مشابه را کنار هم ببینید</h2><p>فقط محصولات یک دسته با هم مقایسه می‌شوند؛ مثلاً جاروبرقی با جاروبرقی. برای کالای ناموجود نیز هشدار موجودشدن یا کاهش قیمت ثبت کنید.</p></div><div><?php if((string)setting('compare_enabled','1')==='1'):?><a class="btn btn-primary" href="<?=e(app_url('compare.php'))?>">مقایسه محصولات</a><?php endif?><a class="btn btn-outline-light" href="<?=e(app_url('shop.php?stock=1'))?>">مشاهده کالاهای موجود</a></div></div></section><?php endif?>

<?php if($newestProducts):?><section class="section section-soft home-products"><div class="container"><div class="section-head"><div class="section-title"><span>تازه در فروشگاه</span><h2><?=e((string)setting('home_newest_title','تازه‌ترین کالاهای موجود'))?></h2></div><a href="<?=e(app_url('shop.php?sort=newest&stock=1'))?>">همه تازه‌ها ←</a></div><div class="product-grid"><?php foreach($newestProducts as $item) require BASE_PATH . '/app/views/product-card.php';?></div></div></section><?php endif?>

<?php if($categoryBestsellerGroups):?><section class="section home-category-bestsellers" aria-labelledby="category-bestseller-title"><div class="container"><div class="section-head"><div class="section-title"><span>محبوب میان خریداران</span><h2 id="category-bestseller-title"><?=e((string)setting('home_bestseller_title','پرفروش‌های هر دسته'))?></h2></div><a href="<?=e(app_url('shop.php?stock=1'))?>">همه محصولات ←</a></div><div class="home-bestseller-groups"><?php foreach($categoryBestsellerGroups as $group):?><section class="home-bestseller-group"><header><div><span>پرفروش‌های</span><h3><?=e($group['category']['name'])?></h3></div><a href="<?=e(app_url('shop.php?category='.rawurlencode((string)$group['category']['slug']).'&stock=1'))?>">مشاهده همه ←</a></header><div class="home-bestseller-products"><?php foreach($group['products'] as $rank=>$product):?><a class="home-bestseller-card" href="<?=e(product_url($product))?>"><span class="home-bestseller-card__rank"><?=fa_number($rank+1)?></span><span class="home-bestseller-card__image"><img src="<?=e(public_image_thumbnail((string)$product['image_path']))?>" alt="<?=e($product['title'])?>" loading="lazy" decoding="async" width="150" height="150"></span><span class="home-bestseller-card__content"><small><?=e($product['brand_name'])?></small><strong><?=e($product['short_title'] ?: $product['title'])?></strong><?php if(!empty($product['features'][0])):?><em><?=e($product['features'][0])?></em><?php endif?><b><?=fa_number(format_price((int)$product['price']))?> <small>تومان</small></b></span></a><?php endforeach?></div></section><?php endforeach?></div></div></section><?php endif?>

<?php if($latestBlogPosts):?><section class="section home-blog"><div class="container"><div class="section-head"><div class="section-title"><span>مجله فروشگاه</span><h2>راهنمای انتخاب و نگهداری</h2></div><a href="<?=e(app_url('blog.php'))?>">مشاهده همه مقاله‌ها ←</a></div><div class="blog-grid"><?php foreach($latestBlogPosts as $post) require BASE_PATH . '/app/views/blog-card.php';?></div></div></section><?php endif?>

<?php if((string)setting('home_show_newsletter','1')==='1'):?><section class="section home-newsletter-section"><div class="container newsletter home-newsletter"><div><span>اطلاع‌رسانی فروشگاه</span><h2>از جشنواره‌ها و موجودشدن کالاها باخبر شوید</h2><p>شماره شما فقط برای اطلاع‌رسانی فروشگاه استفاده می‌شود.</p></div><form class="newsletter-form" data-newsletter><input type="tel" name="phone" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" placeholder="شماره موبایل" aria-label="شماره موبایل" required><button type="submit">عضویت</button></form></div></section><?php endif?>

<?php if((string)setting('home_show_instagram','1')==='1'):?><section class="section home-instagram-section" aria-labelledby="home-instagram-title"><div class="container"><div class="home-instagram-stage"><a class="home-instagram-banner" href="<?=e($instagramUrl)?>" target="_blank" rel="noopener noreferrer"><span class="home-instagram-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><circle cx="17.4" cy="6.7" r="1"></circle></svg></span><span class="home-instagram-copy"><strong id="home-instagram-title"><?=e((string)setting('instagram_title','ما را در اینستاگرام دنبال کنید'))?></strong><b dir="ltr">@<?=e($instagramUsername)?></b></span><span class="home-instagram-action">مشاهده صفحه <i aria-hidden="true">←</i></span></a><a class="home-instagram-phone home-instagram-phone--iphone" href="<?=e($instagramUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="مشاهده صفحه اینستاگرام <?=e($instagramUsername)?>"><img class="home-instagram-phone__device" src="<?=e(public_image($instagramPhoneMockup))?>" alt="نمای صفحه اینستاگرام <?=e($instagramUsername)?> داخل گوشی آیفون" loading="lazy" decoding="async" width="500" height="760"></a></div></div></section><?php endif?>
</main>
<?php require BASE_PATH . '/app/views/footer.php';
