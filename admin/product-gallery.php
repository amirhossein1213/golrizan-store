<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
admin_required();

$productId=max(0,scalar_int($_GET['product_id']??$_POST['product_id']??0));
$product=$productId?find_product_by_id($productId,true):null;
if($productId&&!$product){http_response_code(404);exit('محصول پیدا نشد.');}

if(is_post()&&$product){
    verify_csrf();$action=scalar_string($_POST['action']??'upload');
    try{
        if($action==='upload'){
            $currentImages=product_gallery_images($productId,(string)($product['image_path']??''),(string)$product['title'],false);
            $count=count($currentImages);
            $remaining=max(0,12-$count);if($remaining<1)throw new RuntimeException('حداکثر ۱۲ تصویر برای هر محصول مجاز است.');
            $names=$_FILES['images']['name']??[];if(!is_array($names))throw new RuntimeException('ساختار فایل‌ها معتبر نیست.');
            $indexes=[];foreach($names as $index=>$name)if(trim((string)$name)!=='')$indexes[]=$index;
            if(!$indexes)throw new RuntimeException('حداقل یک تصویر انتخاب کنید.');
            if(count($indexes)>$remaining)throw new RuntimeException('با توجه به تصاویر فعلی، فقط '.fa_number($remaining).' تصویر دیگر قابل افزودن است.');
            $uploaded=[];
            foreach($indexes as $index){
                $file=[];foreach(['name','type','tmp_name','error','size'] as $key)$file[$key]=$_FILES['images'][$key][$index]??null;
                $uploaded[]=save_product_upload($file);
            }
            $pdo=db();$pdo->beginTransaction();
            product_gallery_ensure_primary_row($pdo,$productId,(string)($product['image_path']??''),(string)$product['title']);
            $maxOrderStmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM product_gallery WHERE product_id=? FOR UPDATE');$maxOrderStmt->execute([$productId]);$sort=(int)$maxOrderStmt->fetchColumn()+1;
            $insert=$pdo->prepare('INSERT INTO product_gallery(product_id,image_path,alt_text,sort_order,is_primary) VALUES(?,?,?,?,0)');
            $firstUploadedId=0;
            foreach($uploaded as $offset=>$path){$insert->execute([$productId,$path,$product['title'],$sort+$offset]);if($offset===0)$firstUploadedId=(int)$pdo->lastInsertId();}
            if(($count===0||isset($_POST['make_first_primary']))&&$firstUploadedId>0)$pdo->prepare('UPDATE product_gallery SET is_primary=(id=?) WHERE product_id=?')->execute([$firstUploadedId,$productId]);
            product_gallery_sync_primary($pdo,$productId);$pdo->commit();
            flash('success',fa_number(count($uploaded)).' تصویر به گالری افزوده شد.');
        }elseif($action==='primary'){
            $imageId=max(0,scalar_int($_POST['image_id']??0));$pdo=db();$pdo->beginTransaction();
            $check=$pdo->prepare('SELECT image_path FROM product_gallery WHERE id=? AND product_id=? FOR UPDATE');$check->execute([$imageId,$productId]);$path=$check->fetchColumn();if($path===false)throw new RuntimeException('تصویر پیدا نشد.');
            $pdo->prepare('UPDATE product_gallery SET is_primary=(id=?) WHERE product_id=?')->execute([$imageId,$productId]);$pdo->prepare('UPDATE products SET image_path=? WHERE id=?')->execute([(string)$path,$productId]);$pdo->commit();flash('success','تصویر اصلی تغییر کرد.');
        }elseif($action==='move'){
            $imageId=max(0,scalar_int($_POST['image_id']??0));$direction=scalar_string($_POST['direction']??'up');
            $pdo=db();$pdo->beginTransaction();$stmt=$pdo->prepare('SELECT id FROM product_gallery WHERE product_id=? ORDER BY is_primary DESC,sort_order,id FOR UPDATE');$stmt->execute([$productId]);$ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));$position=array_search($imageId,$ids,true);if($position===false)throw new RuntimeException('تصویر پیدا نشد.');$other=$direction==='down'?$position+1:$position-1;if(isset($ids[$other])){$first=$ids[$position];$second=$ids[$other];$pdo->prepare('UPDATE product_gallery SET sort_order=? WHERE id=?')->execute([$other,$first]);$pdo->prepare('UPDATE product_gallery SET sort_order=? WHERE id=?')->execute([$position,$second]);}$pdo->commit();flash('success','ترتیب گالری ذخیره شد.');
        }elseif($action==='alt'){
            $imageId=max(0,scalar_int($_POST['image_id']??0));$alt=text_substr(trim(scalar_string($_POST['alt_text']??'')),0,255);db()->prepare('UPDATE product_gallery SET alt_text=? WHERE id=? AND product_id=?')->execute([$alt?:null,$imageId,$productId]);flash('success','متن جایگزین تصویر ذخیره شد.');
        }elseif($action==='delete'){
            $imageId=max(0,scalar_int($_POST['image_id']??0));$pdo=db();$pdo->beginTransaction();$stmt=$pdo->prepare('SELECT image_path FROM product_gallery WHERE id=? AND product_id=? FOR UPDATE');$stmt->execute([$imageId,$productId]);$path=$stmt->fetchColumn();if($path===false)throw new RuntimeException('تصویر پیدا نشد.');$pdo->prepare('DELETE FROM product_gallery WHERE id=? AND product_id=?')->execute([$imageId,$productId]);product_gallery_sync_primary($pdo,$productId);$pdo->commit();product_gallery_delete_file_if_unused((string)$path);flash('success','تصویر حذف شد.');
        }
    }catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(isset($uploaded))foreach($uploaded as $path)delete_managed_upload($path);log_error($e);flash('error',$e->getMessage());}
    redirect(app_url('admin/product-gallery.php?product_id='.$productId));
}

$adminPage='gallery';$adminTitle='گالری محصولات';
if(!$product){$products=db()->query('SELECT id,title,sku,image_path FROM products ORDER BY id DESC LIMIT 500')->fetchAll();require __DIR__.'/partials/header.php';?>
<div class="admin-title"><div><h1>گالری محصولات</h1><p class="muted">برای مدیریت حداکثر ۱۲ تصویر، یک محصول را انتخاب کنید.</p></div></div><div class="table-wrap"><table><thead><tr><th>محصول</th><th>کد</th><th>تصاویر</th><th></th></tr></thead><tbody><?php foreach($products as $row):$c=db()->prepare('SELECT COUNT(*) FROM product_gallery WHERE product_id=?');$c->execute([$row['id']]);?><tr><td><?=e($row['title'])?></td><td dir="ltr"><?=e($row['sku'])?></td><td><?=fa_number((int)$c->fetchColumn())?> از ۱۲</td><td><a class="btn btn-primary btn-sm" href="<?=e(app_url('admin/product-gallery.php?product_id='.(int)$row['id']))?>">مدیریت</a></td></tr><?php endforeach?></tbody></table></div><?php require __DIR__.'/partials/footer.php';exit;}

$images=product_gallery_images($productId,(string)$product['image_path'],(string)$product['title'],false);
$currentImageCount=count($images);$remainingImageSlots=max(0,12-$currentImageCount);
require __DIR__.'/partials/header.php';?>
<div class="admin-title"><div><h1>گالری <?=e($product['title'])?></h1><p class="muted">تصویر اصلی در کارت محصول و ابتدای گالری نمایش داده می‌شود؛ هر آپلود جدید خودکار به WebP سفید ۱۲۰۰×۱۲۰۰ آماده ترب تبدیل می‌شود.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/product-edit.php?id='.$productId))?>">ویرایش محصول</a><a class="btn btn-secondary" href="<?=e(app_url('admin/product-gallery.php'))?>">همه محصولات</a></div></div>
<form method="post" enctype="multipart/form-data" class="card gallery-upload-card" data-multi-image-picker data-max-files="<?=$remainingImageSlots?>" data-existing-files="<?=$currentImageCount?>"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="action" value="upload">
<div class="quick-media-card__head"><div><h2>افزودن چند تصویر</h2><small>عکس‌ها را یک‌جا انتخاب کنید؛ قبل از بارگذاری می‌توانید ترتیبشان را عوض یا فایل اشتباه را حذف کنید.</small></div><span class="quick-media-limit" data-image-picker-count><?=fa_number($currentImageCount)?> از ۱۲ تصویر ثبت شده</span></div>
<label class="quick-media-dropzone" for="gallery-product-images"><input id="gallery-product-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required data-image-picker-input <?=$remainingImageSlots<1?'disabled':''?>><span class="quick-media-dropzone__title"><?=$remainingImageSlots>0?'برای انتخاب یا رهاکردن چند عکس کلیک کنید':'ظرفیت گالری تکمیل شده است'?></span><span class="quick-media-dropzone__meta">JPG، PNG یا WebP تا ۶ مگابایت؛ ضلع کوتاه فایل اولیه حداقل ۳۰۰ پیکسل — ظرفیت باقی‌مانده: <?=fa_number($remainingImageSlots)?> تصویر</span></label>
<div class="quick-media-preview" data-image-picker-preview hidden></div><p class="quick-media-hint" data-image-picker-help>ترتیب پیش‌نمایش همان ترتیب ذخیره در گالری است.</p>
<?php if($remainingImageSlots>0):?><label class="checkbox-row quick-media-primary-option"><input type="checkbox" name="make_first_primary" value="1"> اولین تصویر جدید، تصویر اصلی محصول شود</label><button class="btn btn-primary">بارگذاری تصاویر انتخاب‌شده</button><?php endif?></form>
<div class="gallery-admin-grid"><?php foreach($images as $image):?><article class="card gallery-admin-item"><img src="<?=e($image['url'])?>" alt=""><div class="admin-actions"><?php if(!$image['is_primary']&&$image['id']):?><form method="post"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="action" value="primary"><input type="hidden" name="image_id" value="<?=$image['id']?>"><button class="btn btn-secondary btn-sm">تصویر اصلی</button></form><?php else:?><span class="badge green">اصلی</span><?php endif?><?php if($image['id']):?><form method="post"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="action" value="move"><input type="hidden" name="image_id" value="<?=$image['id']?>"><input type="hidden" name="direction" value="up"><button class="btn btn-secondary btn-sm">↑</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="action" value="move"><input type="hidden" name="image_id" value="<?=$image['id']?>"><input type="hidden" name="direction" value="down"><button class="btn btn-secondary btn-sm">↓</button></form><?php endif?></div><?php if($image['id']):?><form method="post" class="field"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="action" value="alt"><input type="hidden" name="image_id" value="<?=$image['id']?>"><label>متن جایگزین</label><input name="alt_text" maxlength="255" value="<?=e($image['alt_text'])?>"><button class="btn btn-secondary btn-sm">ذخیره</button></form><form method="post" data-confirm="این تصویر حذف شود؟"><?=csrf_field()?><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="image_id" value="<?=$image['id']?>"><button class="btn btn-danger btn-sm">حذف</button></form><?php endif?></article><?php endforeach?></div>
<?php require __DIR__.'/partials/footer.php';
