<?php
declare(strict_types=1);

// آدرس قدیمی برای GET دست‌نخورده می‌ماند؛ اگر ترب همین مسیر را به‌عنوان v3 با POST صدا بزند،
// درخواست به endpoint استاندارد و دارای JWT منتقل می‌شود.
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    require __DIR__ . '/torob-products-v3.php';
    exit;
}

require dirname(__DIR__).'/app/bootstrap.php';$key=scalar_string($_GET['key']??'');$expected=(string)setting('torob_feed_key','');if($expected===''||!hash_equals($expected,$key))json_response(['ok'=>false,'message'=>'کلید خروجی معتبر نیست.'],403);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, max-age=300');header('X-Robots-Tag: noindex, nofollow');
$rows=db()->query("SELECT p.*,b.name brand,b.slug brand_slug,c.name category,c.slug category_slug,b.name brand_name,c.name category_name FROM products p JOIN brands b ON b.id=p.brand_id JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND p.torob_enabled=1 AND p.price>0 AND b.is_active=1 AND c.is_active=1 ORDER BY p.id")->fetchAll();$items=[];
foreach(hydrate_products($rows) as $p){
    if(product_has_variants($p)){
        foreach(product_variant_rows((int)$p['id'],true) as $v){if(!(int)$v['torob_enabled'])continue;$v=variant_with_festival($p,$v);$label=$v['option_label']?:$v['title_suffix'];$items[]=['page_url'=>app_url('product/'.rawurlencode($p['slug']).'?variant='.(int)$v['id']),'product_id'=>$v['sku'],'parent_product_id'=>$p['sku'],'title'=>trim($p['title'].($label?' - '.$label:'')),'price'=>(int)$v['price']*10,'old_price'=>((int)$v['base_price']>(int)$v['price'])?(int)$v['base_price']*10:null,'availability'=>(int)$v['stock']>0,'stock'=>(int)$v['stock'],'image_url'=>public_image((string)($v['image_path']?:$p['image_path'])),'brand'=>$p['brand'],'category'=>$p['category'],'variant'=>$label,'festival'=>$v['festival_title'],'last_update'=>$v['updated_at']?:$p['updated_at']];}
        continue;
    }
    $items[]=['page_url'=>app_url('product/'.rawurlencode($p['slug'])),'product_id'=>$p['sku'],'title'=>$p['title'],'price'=>(int)$p['price']*10,'old_price'=>($p['base_price']>(int)$p['price'])?(int)$p['base_price']*10:null,'availability'=>(int)$p['stock']>0,'stock'=>(int)$p['stock'],'image_url'=>public_image((string)$p['image_path']),'brand'=>$p['brand'],'category'=>$p['category'],'festival'=>$p['festival_title'],'last_update'=>$p['updated_at']];
}
echo json_encode(['store'=>setting('store_name'),'currency'=>'IRR','items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
