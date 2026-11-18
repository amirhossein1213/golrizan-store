<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');

$urls = [];
$dateOnly = static function (mixed $value): ?string {
    if (!is_scalar($value) || trim((string)$value) === '') return null;
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
};
$latest = static function (string $sql) use ($dateOnly): ?string {
    try { return $dateOnly(db()->query($sql)->fetchColumn()); } catch (Throwable $exception) { log_error($exception); return null; }
};
$add = static function (string $url, ?string $modified = null, string $change = 'weekly', string $priority = '0.6') use (&$urls): void {
    $urls[] = ['url'=>$url,'modified'=>$modified,'change'=>$change,'priority'=>$priority];
};
$homeModified=$latest("SELECT MAX(changed_at) FROM (SELECT MAX(updated_at) changed_at FROM products WHERE status='active' UNION ALL SELECT MAX(updated_at) FROM home_banners WHERE is_active=1 UNION ALL SELECT MAX(updated_at) FROM festivals WHERE is_active=1 UNION ALL SELECT MAX(updated_at) FROM settings) changes");
$shopModified=$latest("SELECT MAX(changed_at) FROM (SELECT MAX(p.updated_at) changed_at FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.status='active' AND c.is_active=1 AND b.is_active=1 UNION ALL SELECT MAX(updated_at) FROM festivals WHERE is_active=1) changes");
$add(app_url(), $homeModified, 'daily', '1.0');
$add(app_url('shop.php'), $shopModified, 'daily', '0.9');
foreach (['about.php','contact.php','policies.php','trust.php','shipping-guide.php','return-policy.php','payment-methods.php','faq.php','support.php','sales.php'] as $path) {$modified=is_file(BASE_PATH.'/'.$path)?date('Y-m-d',(int)filemtime(BASE_PATH.'/'.$path)):null;$add(app_url($path),$modified,'monthly','0.5');}

$categories = db()->query("SELECT c.slug,GREATEST(c.created_at,COALESCE(MAX(p.updated_at),c.created_at)) updated_at FROM categories c LEFT JOIN products p ON p.category_id=c.id AND p.status='active' WHERE c.is_active=1 GROUP BY c.id,c.slug,c.created_at ORDER BY c.id")->fetchAll();
foreach ($categories as $category) $add(app_url('shop.php?category='.rawurlencode($category['slug'])), $dateOnly($category['updated_at']), 'weekly', '0.8');
$products = db()->query("SELECT p.slug,p.updated_at FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1 ORDER BY p.id")->fetchAll();
foreach ($products as $product) $add(app_url('product/'.rawurlencode($product['slug'])), $dateOnly($product['updated_at']), 'weekly', '0.9');

if ((string)setting('blog_enabled','1') === '1') {
    $blogModified=$latest("SELECT MAX(updated_at) FROM blog_posts WHERE status='published' AND published_at<=NOW()");
    $add(app_url('blog.php'), $blogModified, 'daily', '0.8');
    $blogCategories=db()->query("SELECT bc.slug,COUNT(bp.id) post_count,GREATEST(bc.updated_at,COALESCE(MAX(bp.updated_at),bc.updated_at)) updated_at FROM blog_categories bc LEFT JOIN blog_posts bp ON bp.category_id=bc.id AND bp.status='published' AND bp.published_at<=NOW() WHERE bc.is_active=1 GROUP BY bc.id,bc.slug,bc.updated_at ORDER BY bc.sort_order,bc.name")->fetchAll();
    foreach ($blogCategories as $category) if ((int)$category['post_count'] > 0) $add(app_url('blog.php?category='.rawurlencode($category['slug'])), $dateOnly($category['updated_at']), 'weekly', '0.7');
    $posts = db()->query("SELECT slug,updated_at FROM blog_posts WHERE status='published' AND published_at<=NOW() ORDER BY id")->fetchAll();
    foreach ($posts as $post) $add(app_url('blog/'.rawurlencode($post['slug'])), $dateOnly($post['updated_at']), 'monthly', '0.8');
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><?php foreach($urls as $item):?><url><loc><?=e($item['url'])?></loc><?php if($item['modified']):?><lastmod><?=e($item['modified'])?></lastmod><?php endif?><changefreq><?=e($item['change'])?></changefreq><priority><?=e($item['priority'])?></priority></url><?php endforeach?></urlset>
