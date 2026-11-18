<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$id = max(0, scalar_int($_GET['id'] ?? $_POST['id'] ?? 0));
$post = null;
if ($id) { $stmt=db()->prepare('SELECT * FROM blog_posts WHERE id=?'); $stmt->execute([$id]); $post=$stmt->fetch()?:null; if(!$post&&!is_post()){http_response_code(404);exit('نوشته پیدا نشد.');} }
$defaults=['category_id'=>'','slug'=>'','title'=>'','excerpt'=>'','content'=>'','cover_image'=>'','cover_alt'=>'','status'=>'draft','is_featured'=>0,'meta_title'=>'','meta_description'=>'','published_at'=>''];
$data=$post?:$defaults; $errors=[]; $newUpload='';

if (is_post()) {
    verify_csrf();
    $id=max(0,scalar_int($_POST['id']??0));
    $current=null;if($id){$stmt=db()->prepare('SELECT * FROM blog_posts WHERE id=?');$stmt->execute([$id]);$current=$stmt->fetch()?:null;if(!$current)$errors[]='نوشته پیدا نشد.';}
    $title=trim(scalar_string($_POST['title']??''));
    $slug=slugify(trim(scalar_string($_POST['slug']??''))?:$title);
    $categoryId=max(0,scalar_int($_POST['category_id']??0));
    $excerpt=trim(scalar_string($_POST['excerpt']??''));
    $content=trim(scalar_string($_POST['content']??''));
    $coverAlt=trim(scalar_string($_POST['cover_alt']??''));
    $status=in_array(scalar_string($_POST['status']??'draft'),array_keys(blog_status_labels()),true)?scalar_string($_POST['status']):'draft';
    $publishInput=trim(scalar_string($_POST['published_at']??''));
    $publishedAt=$publishInput!==''?jalali_parse_datetime($publishInput):null;
    $metaTitle=trim(scalar_string($_POST['meta_title']??''));
    $metaDescription=trim(scalar_string($_POST['meta_description']??''));
    if(text_length($title)<5||text_length($title)>255)$errors[]='عنوان نوشته باید بین ۵ تا ۲۵۵ کاراکتر باشد.';
    if(!preg_match('/^[a-z0-9-]{2,190}$/',$slug))$errors[]='نشانی نوشته معتبر نیست.';
    if($categoryId<1)$errors[]='دسته‌بندی نوشته را انتخاب کنید.';
    if(text_length($excerpt)>600)$errors[]='خلاصه نوشته حداکثر ۶۰۰ کاراکتر است.';
    if(text_length($content)<20||text_length($content)>100000)$errors[]='متن نوشته باید بین ۲۰ تا ۱۰۰٬۰۰۰ کاراکتر باشد.';
    if(text_length($coverAlt)>255||text_length($metaTitle)>255||text_length($metaDescription)>320)$errors[]='یکی از اطلاعات تصویر یا سئو بیش از حد مجاز است.';
    if($publishInput!==''&&$publishedAt===null)$errors[]='زمان انتشار شمسی معتبر نیست.';
    $categoryCheck=db()->prepare('SELECT id FROM blog_categories WHERE id=?');$categoryCheck->execute([$categoryId]);if(!$categoryCheck->fetchColumn())$errors[]='دسته‌بندی انتخاب‌شده معتبر نیست.';
    $coverImage=(string)($current['cover_image']??'');
    if(!$errors&&!empty($_FILES['cover_image']['name']))try{$newUpload=save_blog_upload($_FILES['cover_image']);$coverImage=$newUpload;}catch(Throwable $exception){$errors[]=$exception->getMessage();}
    if(isset($_POST['remove_cover'])&&$newUpload==='')$coverImage='';
    if($status==='published'&&$publishedAt===null)$publishedAt=(string)($current['published_at']??'')?:date('Y-m-d H:i:s');
    $data=['category_id'=>$categoryId,'slug'=>$slug,'title'=>$title,'excerpt'=>$excerpt,'content'=>$content,'cover_image'=>$coverImage,'cover_alt'=>$coverAlt,'status'=>$status,'is_featured'=>isset($_POST['is_featured'])?1:0,'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,'published_at'=>$publishedAt];
    if(!$errors){
        try{
            $pdo=db();$pdo->beginTransaction();
            $values=[$categoryId,(int)admin_user()['id'],$slug,$title,$excerpt?:null,$content,$coverImage?:null,$coverAlt?:null,$status,$data['is_featured'],$metaTitle?:null,$metaDescription?:null,$publishedAt];
            if($id){$values[]=$id;$pdo->prepare('UPDATE blog_posts SET category_id=?,author_id=?,slug=?,title=?,excerpt=?,content=?,cover_image=?,cover_alt=?,status=?,is_featured=?,meta_title=?,meta_description=?,published_at=? WHERE id=?')->execute($values);}else{$pdo->prepare('INSERT INTO blog_posts(category_id,author_id,slug,title,excerpt,content,cover_image,cover_alt,status,is_featured,meta_title,meta_description,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);$id=(int)$pdo->lastInsertId();}
            $pdo->commit();
            $oldImage=(string)($current['cover_image']??'');if($oldImage!==''&&$oldImage!==$coverImage)delete_blog_upload($oldImage);
            flash('success',$status==='published'?'نوشته ذخیره و منتشر شد.':'پیش‌نویس نوشته ذخیره شد.');
            redirect(app_url('admin/blog-edit.php?id='.$id));
        }catch(Throwable $exception){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if($newUpload!=='')delete_blog_upload($newUpload);log_error($exception);$errors[]='ذخیره انجام نشد؛ احتمالاً نشانی نوشته تکراری است.';}
    }
}
$categories=blog_categories(false);$adminPage='blog';$adminTitle=$id?'ویرایش نوشته':'نوشته جدید';
require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1><?=$id?'ویرایش نوشته':'نوشتن مطلب جدید'?></h1><p class="muted">عنوان و توضیحات سئو در صورت خالی‌بودن، از خود نوشته ساخته می‌شوند.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/blog-posts.php'))?>">همه نوشته‌ها</a><?php if($id&&($data['status']??'')==='published'):?><a class="btn btn-secondary" target="_blank" rel="noopener" href="<?=e(blog_post_url($data))?>">مشاهده نوشته</a><?php endif?></div></div>
<?php foreach($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<?php if(!$categories):?><div class="flash error">برای نوشتن مطلب، ابتدا <a href="<?=e(app_url('admin/blog-categories.php'))?>"><strong>یک دسته‌بندی بسازید</strong></a>.</div><?php endif?>
<form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><div class="blog-editor-layout"><div><div class="card"><h2>محتوای نوشته</h2><div class="field"><label>عنوان</label><input name="title" maxlength="255" value="<?=e($data['title'])?>" required></div><div class="field"><label>خلاصه؛ اختیاری</label><textarea name="excerpt" maxlength="600" rows="3" placeholder="اگر خالی باشد، از ابتدای متن ساخته می‌شود."><?=e($data['excerpt'])?></textarea></div><div class="field"><label>متن نوشته</label><textarea class="blog-content-editor" name="content" minlength="20" maxlength="100000" rows="24" required><?=e($data['content'])?></textarea><small>برای تیتر از <code>## عنوان</code> و برای فهرست از <code>- مورد</code> در ابتدای خط استفاده کنید.</small></div></div><div class="card"><h2>سئوی نوشته</h2><div class="field"><label>عنوان سئو؛ اختیاری</label><input name="meta_title" maxlength="255" value="<?=e($data['meta_title'])?>" placeholder="پیشنهاد: حداکثر ۶۰ کاراکتر"></div><div class="field"><label>توضیحات سئو؛ اختیاری</label><textarea name="meta_description" maxlength="320" rows="3" placeholder="در صورت خالی‌بودن، از خلاصه مطلب ساخته می‌شود."><?=e($data['meta_description'])?></textarea></div></div></div><aside><div class="card blog-publish-card"><h2>انتشار</h2><div class="field"><label>دسته‌بندی</label><select name="category_id" required><option value="">انتخاب کنید</option><?php foreach($categories as $category):?><option value="<?=$category['id']?>" <?=(int)$data['category_id']===(int)$category['id']?'selected':''?>><?=e($category['name'])?><?=$category['is_active']?'':' — غیرفعال'?></option><?php endforeach?></select></div><div class="field"><label>وضعیت</label><select name="status"><?php foreach(blog_status_labels() as $key=>$label):?><option value="<?=$key?>" <?=$data['status']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></div><div class="field"><label>زمان انتشار شمسی</label><input name="published_at" data-jalali-datetime inputmode="numeric" autocomplete="off" placeholder="<?=e(jalali_datetime_input())?>" value="<?=e($data['published_at']?jalali_datetime_input($data['published_at']):'')?>"><small>برای انتشار فوری خالی بگذارید.</small></div><label class="checkbox-row"><input type="checkbox" name="is_featured" value="1" <?=$data['is_featured']?'checked':''?>> نمایش به‌عنوان نوشته ویژه</label><button class="btn btn-primary btn-block">ذخیره نوشته</button></div><div class="card"><h2>نشانی و تصویر</h2><div class="field"><label>نشانی انگلیسی</label><input name="slug" dir="ltr" maxlength="190" pattern="[a-z0-9-]{2,190}" value="<?=e($data['slug'])?>"><small>در صورت خالی‌بودن، خودکار ساخته می‌شود.</small></div><div class="field"><label>تصویر شاخص</label><input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp"><small>حداکثر ۵ مگابایت؛ به‌صورت خودکار کوچک و کم‌حجم می‌شود.</small></div><?php if($data['cover_image']):?><img class="blog-cover-preview" src="<?=e(public_image((string)$data['cover_image']))?>" alt=""><label class="checkbox-row"><input type="checkbox" name="remove_cover" value="1"> حذف تصویر فعلی</label><?php endif?><div class="field"><label>متن جایگزین تصویر</label><input name="cover_alt" maxlength="255" value="<?=e($data['cover_alt'])?>"></div></div></aside></div></form>
<?php require __DIR__.'/partials/footer.php';
