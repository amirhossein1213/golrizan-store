<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

$pageInput=scalar_int($_GET['page']??1,1);$invalidPage=$pageInput>100000;$page=max(1,min(100000,$pageInput));
$filters=[
    'q'=>text_substr(trim(scalar_string($_GET['q']??'')),0,100),
    'category'=>scalar_string($_GET['category']??''),'brand'=>scalar_string($_GET['brand']??''),
    'min'=>trim(scalar_string($_GET['min']??'')),'max'=>trim(scalar_string($_GET['max']??'')),
    'stock'=>scalar_string($_GET['stock']??''),'warranty'=>scalar_string($_GET['warranty']??''),
    'color'=>text_substr(trim(scalar_string($_GET['color']??'')),0,80),'energy'=>text_substr(trim(scalar_string($_GET['energy']??'')),0,50),
    'type'=>scalar_string($_GET['type']??''),'rating'=>max(0,min(5,scalar_int($_GET['rating']??0))),
    'sort'=>scalar_string($_GET['sort']??'popular','popular'),
];
$allowedSorts=['popular','cheap','expensive','newest'];$invalidSort=!in_array($filters['sort'],$allowedSorts,true);if($invalidSort)$filters['sort']='popular';
$categories=all_categories();$brands=all_brands();$categorySlugs=array_column($categories,'slug');$brandSlugs=array_column($brands,'slug');
$invalidCategory=$filters['category']!==''&&!in_array($filters['category'],$categorySlugs,true);$invalidBrand=$filters['brand']!==''&&!in_array($filters['brand'],$brandSlugs,true);
$minValid=$filters['min']==='';if(!$minValid)bounded_uint($filters['min'],MAX_MONEY_AMOUNT,$minValid);$maxValid=$filters['max']==='';if(!$maxValid)bounded_uint($filters['max'],MAX_MONEY_AMOUNT,$maxValid);
if($invalidCategory)$filters['category']='__invalid__';if($invalidBrand)$filters['brand']='__invalid__';if(!$minValid)$filters['min']='';elseif((int)$filters['min']===0)$filters['min']='';if(!$maxValid)$filters['max']='';
$invalidWarranty=!in_array($filters['warranty'],['','with','without'],true);$invalidType=!in_array($filters['type'],['','simple','variable'],true);$invalidStock=!in_array($filters['stock'],['','1'],true);
if($invalidWarranty)$filters['warranty']='';if($invalidType)$filters['type']='';if($invalidStock)$filters['stock']='';
$invalidRange=$filters['min']!==''&&$filters['max']!==''&&(int)$filters['min']>(int)$filters['max'];
$colors=db()->query("SELECT filter_value FROM (SELECT color filter_value FROM products WHERE color IS NOT NULL AND color<>'' UNION SELECT pav.value filter_value FROM product_attribute_values pav JOIN product_attributes pa ON pa.id=pav.attribute_id WHERE pa.display_type='color') x ORDER BY filter_value")->fetchAll(PDO::FETCH_COLUMN);
$energies=db()->query("SELECT DISTINCT energy_label FROM products WHERE energy_label IS NOT NULL AND energy_label<>'' ORDER BY energy_label")->fetchAll(PDO::FETCH_COLUMN);
$result=search_products($filters,$page,24);
$activeFilterKeys=['q','category','brand','min','max','stock','warranty','color','energy','type','rating'];$hasActive=false;foreach($activeFilterKeys as $key)if((string)$filters[$key]!==''&&!($key==='rating'&&(string)$filters[$key]==='0')){$hasActive=true;break;}
$hasNonCategoryFilters=false;foreach($activeFilterKeys as $key)if($key!=='category'&&(string)$filters[$key]!==''&&!($key==='rating'&&(string)$filters[$key]==='0')){$hasNonCategoryFilters=true;break;}
$categoryListing=!$invalidCategory&&!$invalidBrand&&$filters['category']!==''&&!$hasNonCategoryFilters&&$filters['sort']==='popular';
$plainShop=!$hasActive&&$filters['sort']==='popular';
$outOfRange=!empty($result['out_of_range']);
$notFound=$invalidPage||$invalidCategory||$invalidBrand||$invalidSort||$invalidWarranty||$invalidType||$invalidStock||$invalidRange||!$minValid||!$maxValid||$outOfRange;
if($notFound)http_response_code(404);
$robots=(!$notFound&&($plainShop||$categoryListing))?'index,follow':'noindex,follow';
$canonicalParams=[];
foreach(['q','category','brand','min','max','stock','warranty','color','energy','type'] as $key)if((string)$filters[$key]!==''&&(string)$filters[$key]!=='__invalid__')$canonicalParams[$key]=$filters[$key];
if((int)$filters['rating']>0)$canonicalParams['rating']=(int)$filters['rating'];
if($filters['sort']!=='popular')$canonicalParams['sort']=$filters['sort'];
if($result['page']>1)$canonicalParams['page']=$result['page'];
$canonical=app_url('shop.php'.($canonicalParams?'?'.http_build_query($canonicalParams,'','&',PHP_QUERY_RFC3986):''));
$selectedCategory=null;if(!$invalidCategory&&$filters['category']!=='')foreach($categories as $candidate)if($candidate['slug']===$filters['category']){$selectedCategory=$candidate;break;}
$categoryBaseCanonical=$selectedCategory?app_url('shop.php?category='.rawurlencode((string)$selectedCategory['slug'])):app_url('shop.php');
$pageKey='shop';
if($selectedCategory){$pageTitle=($selectedCategory['meta_title']?:'خرید '.$selectedCategory['name']).($result['page']>1?' — صفحه '.fa_number($result['page']):'').' | '.setting('store_name');$pageDescription=($selectedCategory['meta_description']?:'مشاهده قیمت، موجودی و مقایسه مدل‌های '.$selectedCategory['name'].' در '.setting('store_name')).($result['page']>1?'؛ صفحه '.fa_number($result['page']):'');$breadcrumbs=[['name'=>'خانه','url'=>app_url()],['name'=>'فروشگاه','url'=>app_url('shop.php')],['name'=>$selectedCategory['name'],'url'=>$categoryBaseCanonical]];}
else{$pageTitle='فروشگاه'.($result['page']>1?' — صفحه '.fa_number($result['page']):'').' | '.setting('store_name');$pageDescription='مشاهده محصولات، قیمت روز، موجودی و مشخصات کامل کالاها در '.setting('store_name').($result['page']>1?'؛ صفحه '.fa_number($result['page']):'');}
require BASE_PATH.'/app/views/header.php';
?>
<main><section class="page-hero"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>فروشگاه</span></div><h1><?=e($selectedCategory?$selectedCategory['name']:'فروشگاه لوازم خانگی')?></h1><p><?=e($selectedCategory?($selectedCategory['meta_description']?:'مشاهده محصولات این دسته با قیمت و موجودی به‌روز'):'جست‌وجوی کامل در محصول، ویژگی و مدل‌های متغیر؛ همراه با فیلترهای دقیق.')?></p></div></section><div class="container shop-layout"><aside class="filters"><form method="get"><div class="filter-title"><strong>فیلتر محصولات</strong><button type="button" class="mobile-only icon-btn" data-filter-close>×</button></div>
<div class="filter-group"><h3>جست‌وجو</h3><input type="search" name="q" value="<?=e($filters['q'])?>" placeholder="نام، توضیحات، SKU، ویژگی یا مدل"></div>
<div class="filter-group"><h3>دسته‌بندی</h3><select name="category"><option value="">همه دسته‌ها</option><?php foreach($categories as $cat):?><option value="<?=e($cat['slug'])?>" <?=$filters['category']===$cat['slug']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach?></select></div>
<div class="filter-group"><h3>برند</h3><select name="brand"><option value="">همه برندها</option><?php foreach($brands as $brand):?><option value="<?=e($brand['slug'])?>" <?=$filters['brand']===$brand['slug']?'selected':''?>><?=e($brand['name'])?></option><?php endforeach?></select></div>
<div class="filter-group"><h3>محدوده قیمت (تومان)</h3><div class="price-inputs"><input type="number" name="min" min="0" max="100000000000000" value="<?=e($filters['min'])?>" placeholder="از قیمت"><input type="number" name="max" min="0" max="100000000000000" value="<?=e($filters['max'])?>" placeholder="تا قیمت"></div></div>
<div class="filter-group"><label class="check-row"><input type="checkbox" name="stock" value="1" <?=$filters['stock']?'checked':''?>> فقط کالاهای موجود</label><h3>گارانتی</h3><select name="warranty"><option value="">همه</option><option value="with" <?=$filters['warranty']==='with'?'selected':''?>>دارای گارانتی</option><option value="without" <?=$filters['warranty']==='without'?'selected':''?>>بدون گارانتی</option></select></div>
<div class="filter-group"><h3>رنگ</h3><select name="color"><option value="">همه رنگ‌ها</option><?php foreach($colors as $color):?><option value="<?=e($color)?>" <?=$filters['color']===$color?'selected':''?>><?=e($color)?></option><?php endforeach?></select><h3>رده انرژی</h3><select name="energy"><option value="">همه رده‌ها</option><?php foreach($energies as $energy):?><option value="<?=e($energy)?>" <?=$filters['energy']===$energy?'selected':''?>><?=e($energy)?></option><?php endforeach?></select></div>
<div class="filter-group"><h3>نوع محصول</h3><select name="type"><option value="">همه</option><option value="simple" <?=$filters['type']==='simple'?'selected':''?>>ساده</option><option value="variable" <?=$filters['type']==='variable'?'selected':''?>>متغیر</option></select><h3>حداقل امتیاز</h3><select name="rating"><option value="0">همه امتیازها</option><?php for($r=4;$r>=1;$r--):?><option value="<?=$r?>" <?=$filters['rating']===$r?'selected':''?>><?=fa_number($r)?> ستاره و بیشتر</option><?php endfor?></select></div>
<button class="btn btn-primary btn-block">اعمال فیلتر</button><a class="btn btn-outline btn-block" href="<?=e(app_url('shop.php'))?>">پاک‌کردن فیلترها</a></form></aside><section><div class="shop-toolbar"><div class="shop-toolbar__filter"><button class="btn btn-outline filter-toggle" type="button" data-filter-toggle><span aria-hidden="true">☷</span> فیلترها</button><small><strong><?=fa_number($result['total'])?></strong> کالا</small></div><form method="get" class="shop-toolbar__sort"><label for="shop-sort">مرتب‌سازی</label><?php foreach($filters as $key=>$value):if($key==='sort'||(string)$value===''||($key==='rating'&&(string)$value==='0'))continue;?><input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>"><?php endforeach?><select class="sort-select" id="shop-sort" name="sort" data-auto-submit><option value="popular" <?=$filters['sort']==='popular'?'selected':''?>>محبوب‌ترین</option><option value="cheap" <?=$filters['sort']==='cheap'?'selected':''?>>ارزان‌ترین</option><option value="expensive" <?=$filters['sort']==='expensive'?'selected':''?>>گران‌ترین</option><option value="newest" <?=$filters['sort']==='newest'?'selected':''?>>جدیدترین</option></select></form></div>
<?php if($result['items']):?><div class="product-grid"><?php foreach($result['items'] as $item)require BASE_PATH.'/app/views/product-card.php';?></div><?php else:?><div class="empty-state"><h2>محصولی پیدا نشد</h2><a class="btn btn-primary" href="<?=e(app_url('shop.php'))?>">نمایش همه محصولات</a></div><?php endif?>
<?php if($result['pages']>1&&!$outOfRange):$pageParams=$filters;if(($pageParams['sort']??'popular')==='popular')unset($pageParams['sort']);$pageParams=array_filter($pageParams,static fn($value,$key):bool=>(string)$value!==''&&!($key==='rating'&&(string)$value==='0'),ARRAY_FILTER_USE_BOTH);$start=max(1,$result['page']-2);$end=min($result['pages'],$result['page']+2);?><nav class="pagination" aria-label="صفحه‌های محصولات"><?php for($i=$start;$i<=$end;$i++):$params=$pageParams;if($i>1)$params['page']=$i;$href=app_url('shop.php'.($params?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):''));?><a class="<?=$i===$result['page']?'active':''?>" <?=$i===$result['page']?'aria-current="page"':''?> href="<?=e($href)?>"><?=fa_number($i)?></a><?php endfor?></nav><?php endif?></section></div></main>
<?php require BASE_PATH.'/app/views/footer.php';
