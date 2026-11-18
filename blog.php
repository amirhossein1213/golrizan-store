<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if ((string)setting('blog_enabled','1') !== '1') { http_response_code(404); exit('وبلاگ در دسترس نیست.'); }

$pageInput=scalar_int($_GET['page']??1,1);$invalidPage=$pageInput>10000;$page=max(1,min(10000,$pageInput));
$perPage=9;
$query=text_substr(trim(scalar_string($_GET['q']??'')),0,100);
$categorySlug=scalar_string($_GET['category']??'');
$categories=blog_categories();
$category=null;
foreach($categories as $candidate)if($candidate['slug']===$categorySlug){$category=$candidate;break;}
$invalidCategory=$categorySlug!==''&&!$category;if($invalidCategory){http_response_code(404);$categorySlug='__not_found__';}
$where=["bp.status='published'","bp.published_at<=NOW()",'bc.is_active=1'];$params=[];
if($categorySlug!==''){$where[]='bc.slug=?';$params[]=$categorySlug;}
if($query!==''){$where[]='(bp.title LIKE ? OR bp.excerpt LIKE ? OR bp.content LIKE ?)';$needle='%'.$query.'%';array_push($params,$needle,$needle,$needle);}
$whereSql=implode(' AND ',$where);
$count=db()->prepare("SELECT COUNT(*) FROM blog_posts bp JOIN blog_categories bc ON bc.id=bp.category_id WHERE {$whereSql}");$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$outOfRange=$page>$pages;$offset=($page-1)*$perPage;
$posts=[];if(!$outOfRange){$stmt=db()->prepare("SELECT bp.*,bc.name category_name,bc.slug category_slug,a.full_name author_name FROM blog_posts bp JOIN blog_categories bc ON bc.id=bp.category_id LEFT JOIN admins a ON a.id=bp.author_id WHERE {$whereSql} ORDER BY bp.is_featured DESC,bp.published_at DESC,bp.id DESC LIMIT {$perPage} OFFSET {$offset}");$stmt->execute($params);$posts=$stmt->fetchAll();}
$notFound=$invalidCategory||$invalidPage||$outOfRange;if($notFound)http_response_code(404);
$pageKey='blog';$baseTitle=$category?($category['meta_title']?:$category['name']):'مجله و راهنمای خرید';$pageTitle=$baseTitle.($page>1?' — صفحه '.fa_number($page):'').' | '.setting('store_name');$pageDescription=($category?($category['meta_description']?:($category['description']?:'مقاله‌ها و راهنماهای تخصصی درباره '.$category['name'])):'راهنمای خرید، مقایسه و نگهداری لوازم خانگی از '.setting('store_name')).($page>1?'؛ صفحه '.fa_number($page):'');
$canonicalParams=[];if($category)$canonicalParams['category']=$category['slug'];if($query!=='')$canonicalParams['q']=$query;if($page>1)$canonicalParams['page']=$page;$canonical=app_url('blog.php'.($canonicalParams?'?'.http_build_query($canonicalParams,'','&',PHP_QUERY_RFC3986):''));$robots=($query!==''||$notFound)?'noindex,follow':'index,follow';
$breadcrumbs=[['name'=>'خانه','url'=>app_url()],['name'=>'وبلاگ','url'=>app_url('blog.php')]];if($category)$breadcrumbs[]=['name'=>$category['name'],'url'=>app_url('blog.php?category='.rawurlencode((string)$category['slug']))];
require BASE_PATH.'/app/views/header.php';
?>
<main><section class="page-hero blog-hero"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>وبلاگ</span><?php if($category):?><span>/</span><span><?=e($category['name'])?></span><?php endif?></div><h1><?=e($category?$category['name']:'مجله و راهنمای خرید')?></h1><p><?=e($pageDescription)?></p></div></section>
<section class="section"><div class="container blog-layout"><div><form class="blog-search" method="get"><?php if($category):?><input type="hidden" name="category" value="<?=e($category['slug'])?>"><?php endif?><input type="search" name="q" value="<?=e($query)?>" placeholder="جست‌وجو در مقاله‌ها…"><button class="btn btn-primary">جست‌وجو</button></form><div class="blog-grid"><?php foreach($posts as $post)require BASE_PATH.'/app/views/blog-card.php';?></div><?php if(!$posts):?><div class="empty-state"><h2>مطلبی پیدا نشد</h2><p>عبارت جست‌وجو یا دسته‌بندی دیگری را امتحان کنید.</p><a class="btn btn-secondary" href="<?=e(app_url('blog.php'))?>">همه مقاله‌ها</a></div><?php endif?><?php if($pages>1&&!$outOfRange):$pageStart=max(1,$page-2);$pageEnd=min($pages,$page+2);?><nav class="pagination" aria-label="صفحه‌های وبلاگ"><?php for($i=$pageStart;$i<=$pageEnd;$i++):$qs=[];if($category)$qs['category']=$category['slug'];if($query!=='')$qs['q']=$query;if($i>1)$qs['page']=$i;?><a class="<?=$i===$page?'active':''?>" <?=$i===$page?'aria-current="page"':''?> href="<?=e(app_url('blog.php'.($qs?'?'.http_build_query($qs,'','&',PHP_QUERY_RFC3986):'')))?>"><?=fa_number($i)?></a><?php endfor?></nav><?php endif?></div><aside class="blog-sidebar"><div class="blog-sidebar__card"><h2>موضوع‌ها</h2><a class="<?=$category?'':'active'?>" href="<?=e(app_url('blog.php'))?>"><span>همه مقاله‌ها</span><small><?=fa_number(array_sum(array_map(static fn(array $item):int=>(int)$item['post_count'],$categories)))?></small></a><?php foreach($categories as $item):?><a class="<?=$category&&(int)$category['id']===(int)$item['id']?'active':''?>" href="<?=e(app_url('blog.php?category='.rawurlencode($item['slug'])))?>"><span><?=e($item['name'])?></span><small><?=fa_number((int)$item['post_count'])?></small></a><?php endforeach?></div><div class="blog-sidebar__cta"><strong>برای انتخاب محصول نیاز به راهنمایی دارید؟</strong><p>پشتیبانی فروشگاه قبل از خرید در کنار شماست.</p><a class="btn btn-light btn-block" href="<?=e(app_url('contact.php'))?>">تماس با پشتیبانی</a></div></aside></div></section></main>
<?php require BASE_PATH.'/app/views/footer.php';
