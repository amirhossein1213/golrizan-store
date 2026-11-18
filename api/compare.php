<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(!is_post())json_response(['ok'=>false,'message'=>'روش درخواست نامعتبر است.'],405);
$data=input_json();$raw=is_array($data['ids']??null)?$data['ids']:[];
$requestedIds=array_slice(array_values(array_unique(array_filter(array_map('intval',$raw),static fn(int $id):bool=>$id>0))),0,4);
$products=comparison_products($requestedIds);$items=[];
foreach($products as $product)$items[]=['id'=>(int)$product['id'],'category_id'=>(int)$product['category_id'],'category_slug'=>$product['category_slug'],'title'=>$product['title'],'brand'=>$product['brand_name'],'category'=>$product['category_name'],'price'=>(int)$product['price'],'stock'=>(int)$product['stock'],'warranty'=>trim((string)$product['warranty'])?:'بدون گارانتی','energy_label'=>$product['energy_label']?:'—','color'=>$product['color']?:'—','rating'=>(float)$product['rating'],'features'=>$product['features'],'image'=>public_image((string)$product['image_path']),'url'=>product_url($product)];
$acceptedIds=array_column($items,'id');$removedIds=array_values(array_diff($requestedIds,$acceptedIds));$first=$products[0]??null;
json_response(['ok'=>true,'items'=>$items,'accepted_ids'=>$acceptedIds,'removed_ids'=>$removedIds,'category'=>$first?['id'=>(int)$first['category_id'],'name'=>$first['category_name'],'slug'=>$first['category_slug']]:null,'message'=>$removedIds?'برای مقایسه دقیق، فقط محصولات یک دسته نگه داشته شدند.':'']);
