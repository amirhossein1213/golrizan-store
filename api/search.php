<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
rate_limit('search',120,300);
$q=trim(scalar_string($_GET['q']??''));
if(text_length($q)<2)json_response(['ok'=>true,'items'=>[]]);
$q=text_substr($q,0,100);
	$stmt=db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug FROM products p JOIN brands b ON b.id=p.brand_id JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND p.price>0 AND b.is_active=1 AND c.is_active=1 AND (p.title LIKE ? OR p.short_title LIKE ? OR p.sku LIKE ? OR p.description LIKE ? OR b.name LIKE ? OR EXISTS(SELECT 1 FROM product_features pf WHERE pf.product_id=p.id AND pf.feature_text LIKE ?) OR EXISTS(SELECT 1 FROM product_variants pv WHERE pv.product_id=p.id AND (pv.sku LIKE ? OR pv.title_suffix LIKE ?)) OR EXISTS(SELECT 1 FROM product_variants pv JOIN product_variant_values pvv ON pvv.variant_id=pv.id JOIN product_attribute_values pav ON pav.id=pvv.attribute_value_id WHERE pv.product_id=p.id AND pav.value LIKE ?) OR EXISTS(SELECT 1 FROM product_attribute_selections pas JOIN product_attribute_values pav2 ON pav2.id=pas.attribute_value_id WHERE pas.product_id=p.id AND pav2.value LIKE ?)) ORDER BY p.featured DESC,p.id DESC LIMIT 8");
	$like='%'.$q.'%';$stmt->execute([$like,$like,$like,$like,$like,$like,$like,$like,$like,$like]);$items=[];
foreach(hydrate_products($stmt->fetchAll()) as $p)$items[]=['id'=>(int)$p['id'],'title'=>$p['title'],'price'=>(int)$p['price'],'url'=>app_url('product/'.rawurlencode($p['slug']))];
json_response(['ok'=>true,'items'=>$items]);
