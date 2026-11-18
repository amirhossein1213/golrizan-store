<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';if((string)setting('compare_enabled','1')!=='1')redirect(app_url('shop.php'));$pageKey='compare';$pageTitle='مقایسه محصولات | '.setting('store_name');$robots='noindex,follow';require BASE_PATH.'/app/views/header.php';?>
<main><section class="page-hero"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>مقایسه محصولات</span></div><h1>مقایسه محصولات هم‌نوع</h1><p>حداکثر چهار محصول از یک دسته را کنار هم مقایسه کنید؛ مثلاً جاروبرقی فقط با جاروبرقی.</p></div></section><div class="container checkout-card" data-compare-page><div class="loading-box">در حال آماده‌سازی مقایسه…</div></div></main>
<?php require BASE_PATH.'/app/views/footer.php';
