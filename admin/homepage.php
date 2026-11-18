<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

function homepage_admin_link_valid(string $value): bool
{
    return storefront_internal_link_is_valid($value);
}

$defaults = [
    'home_hero_eyebrow' => 'خرید مستقیم با قیمت و موجودی به‌روز',
    'home_hero_title' => 'خانه را هوشمندانه تجهیز کنید',
    'home_hero_highlight' => 'مطمئن، سریع و با قیمت شفاف',
    'home_hero_description' => 'مدل‌ها را مقایسه کنید، موجودی واقعی را ببینید و کالای مناسب خانه‌تان را با گارانتی مشخص سفارش دهید.',
    'home_hero_primary_label' => 'مشاهده محصولات',
    'home_hero_primary_url' => 'shop.php',
    'home_hero_secondary_label' => 'راهنمای خرید',
    'home_hero_secondary_url' => 'blog.php',
    'home_hero_note_title' => 'کالای اصل و دارای گارانتی',
    'home_hero_note_text' => 'جزئیات گارانتی در صفحه هر محصول',
    'home_categories_title' => 'دسته‌بندی‌های محبوب',
    'home_featured_title' => 'پیشنهادهای منتخب برای خانه شما',
    'home_newest_title' => 'تازه‌ترین کالاهای موجود',
    'home_bestseller_title' => 'پرفروش‌های هر دسته',
    'home_products_limit' => '4',
    'home_bestseller_category_limit' => '4',
    'home_bestseller_product_limit' => '3',
    'home_bestseller_category_ids' => '',
    'home_hero_image' => '',
    'instagram_title' => 'ما را در اینستاگرام دنبال کنید',
    'instagram_username' => 'golrizan1368',
    'instagram_url' => 'https://www.instagram.com/golrizan1368/',
    'instagram_profile_image' => 'assets/images/ui/instagram-golrizan1368.webp',
    'home_show_categories' => '1',
    'home_show_deals' => '1',
    'home_show_featured' => '1',
    'home_show_newest' => '1',
    'home_show_blog' => '1',
    'home_show_newsletter' => '1',
    'home_show_banners' => '1',
    'home_show_category_bestsellers' => '0',
    'home_show_instagram' => '1',
];
$textLimits = [
    'home_hero_eyebrow'=>120,'home_hero_title'=>180,'home_hero_highlight'=>140,'home_hero_description'=>500,
    'home_hero_primary_label'=>60,'home_hero_secondary_label'=>60,'home_hero_note_title'=>140,'home_hero_note_text'=>180,
    'home_categories_title'=>140,'home_featured_title'=>140,'home_newest_title'=>140,'home_bestseller_title'=>140,
    'instagram_title'=>140,'instagram_username'=>30,
];
$toggleKeys = ['home_show_categories','home_show_deals','home_show_featured','home_show_newest','home_show_blog','home_show_newsletter','home_show_banners','home_show_category_bestsellers','home_show_instagram'];
$errors = [];
$allHomepageCategories = all_categories();

if (is_post()) {
    verify_csrf();
    $input = [];
    foreach ($textLimits as $key => $limit) {
        $input[$key] = trim(scalar_string($_POST[$key] ?? ''));
        if (text_length($input[$key]) > $limit) $errors[] = 'یکی از متن‌های صفحه اصلی بیش از حد طولانی است.';
    }
    foreach (['home_hero_primary_url','home_hero_secondary_url'] as $key) {
        $input[$key] = trim(scalar_string($_POST[$key] ?? ''));
        if (!homepage_admin_link_valid($input[$key])) $errors[] = 'نشانی دکمه‌های بنر باید یک مسیر داخلی معتبر مانند shop.php باشد.';
    }
    foreach ($toggleKeys as $key) $input[$key] = isset($_POST[$key]) ? '1' : '0';
    $limit = scalar_int($_POST['home_products_limit'] ?? 4, 4);
    $input['home_products_limit'] = in_array($limit, [4,8,12], true) ? (string)$limit : '4';
    $categoryLimit = scalar_int($_POST['home_bestseller_category_limit'] ?? 4, 4);
    $productGroupLimit = scalar_int($_POST['home_bestseller_product_limit'] ?? 3, 3);
    $input['home_bestseller_category_limit'] = in_array($categoryLimit, [2,3,4,5,6], true) ? (string)$categoryLimit : '4';
    $input['home_bestseller_product_limit'] = in_array($productGroupLimit, [2,3,4], true) ? (string)$productGroupLimit : '3';
    $allowedCategoryIds = array_fill_keys(array_map(static fn(array $category): int => (int)$category['id'], $allHomepageCategories), true);
    $selectedCategoryIds = [];
    foreach ((array)($_POST['bestseller_category_ids'] ?? []) as $categoryId) {
        $categoryId = max(0, scalar_int($categoryId));
        if ($categoryId > 0 && isset($allowedCategoryIds[$categoryId])) $selectedCategoryIds[$categoryId] = $categoryId;
    }
    $input['home_bestseller_category_ids'] = implode(',', array_slice(array_values($selectedCategoryIds), 0, 6));
    $input['instagram_url'] = trim(scalar_string($_POST['instagram_url'] ?? ''));
    if (safe_external_url($input['instagram_url']) === null) $errors[] = 'نشانی اینستاگرام باید یک آدرس کامل و امن با https باشد.';
    if (!preg_match('/^[A-Za-z0-9._]{1,30}$/', $input['instagram_username'])) $errors[] = 'نام کاربری اینستاگرام معتبر نیست.';
    foreach (['home_hero_title','home_hero_primary_label','home_categories_title','home_featured_title','home_newest_title','home_bestseller_title','instagram_title'] as $requiredKey) {
        if ($input[$requiredKey] === '') $errors[] = 'عنوان‌ها و متن دکمه اصلی نباید خالی باشند.';
    }

    $oldImage = trim((string)setting('home_hero_image', ''));
    $newImage = $oldImage;
    $newUpload = '';
    if (isset($_POST['remove_hero_image'])) $newImage = '';
    if (!empty($_FILES['hero_image']['name'])) {
        try {
            $newUpload = save_managed_image_upload($_FILES['hero_image'], 'homepage', 6 * 1024 * 1024, 640, 360, 2400);
            $newImage = $newUpload;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
    $input['home_hero_image'] = $newImage;

    $oldInstagramImage = trim((string)setting('instagram_profile_image', 'assets/images/ui/instagram-golrizan1368.webp'));
    $newInstagramImage = $oldInstagramImage;
    $newInstagramUpload = '';
    if (!empty($_FILES['instagram_profile_image']['name'])) {
        try {
            $newInstagramUpload = save_managed_image_upload($_FILES['instagram_profile_image'], 'homepage', 6 * 1024 * 1024, 500, 350, 2400);
            $newInstagramImage = $newInstagramUpload;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
    $input['instagram_profile_image'] = $newInstagramImage;

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach ($input as $key => $value) $statement->execute([$key, $value]);
            $pdo->commit();
            if ($oldImage !== '' && $oldImage !== $newImage) delete_managed_upload($oldImage);
            if ($oldInstagramImage !== '' && $oldInstagramImage !== $newInstagramImage) delete_managed_upload($oldInstagramImage);
            flash('success', 'طراحی و محتوای صفحه اصلی ذخیره شد.');
            redirect(app_url('admin/homepage.php'));
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newUpload !== '') delete_managed_upload($newUpload);
            if ($newInstagramUpload !== '') delete_managed_upload($newInstagramUpload);
            log_error($exception);
            $errors[] = 'ذخیره تنظیمات صفحه اصلی انجام نشد.';
        }
    } else {
        if ($newUpload !== '') delete_managed_upload($newUpload);
        if ($newInstagramUpload !== '') delete_managed_upload($newInstagramUpload);
    }
}

$values = [];
foreach ($defaults as $key => $default) {
    if (is_post()) {
        if (in_array($key, $toggleKeys, true)) $values[$key] = isset($_POST[$key]) ? '1' : '0';
        elseif (in_array($key, ['home_hero_image','instagram_profile_image'], true)) $values[$key] = trim((string)setting($key, $default));
        elseif ($key === 'home_bestseller_category_ids') $values[$key] = $input[$key] ?? '';
        else $values[$key] = trim(scalar_string($_POST[$key] ?? $default));
    } else {
        $values[$key] = (string)setting($key, $default);
    }
}
$selectedBestsellerIds = array_values(array_filter(array_map('intval', explode(',', $values['home_bestseller_category_ids']))));

$adminPage = 'homepage';
$adminTitle = 'طراحی صفحه اصلی';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><div><h1>طراحی صفحه اصلی</h1><p class="muted">ترتیب محتوای فروش، بنرها، پرفروش‌ها و بخش اینستاگرام را مدیریت کنید.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/home-banners.php'))?>">مدیریت بنرها</a><a class="btn btn-secondary" href="<?=e(app_url())?>" target="_blank" rel="noopener noreferrer">مشاهده صفحه اصلی</a></div></div>
<?php foreach ($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<form method="post" enctype="multipart/form-data"><?=csrf_field()?>
<div class="card"><h2>بنر اصلی</h2><div class="form-grid">
<div class="field"><label>متن کوتاه بالای بنر</label><input name="home_hero_eyebrow" maxlength="120" value="<?=e($values['home_hero_eyebrow'])?>"></div>
<div class="field"><label>عنوان اصلی</label><input name="home_hero_title" maxlength="180" value="<?=e($values['home_hero_title'])?>" required></div>
<div class="field full"><label>عبارت برجسته</label><input name="home_hero_highlight" maxlength="140" value="<?=e($values['home_hero_highlight'])?>"><small>این عبارت با رنگ طلایی در خط دوم عنوان نمایش داده می‌شود.</small></div>
<div class="field full"><label>توضیح بنر</label><textarea name="home_hero_description" maxlength="500" rows="3"><?=e($values['home_hero_description'])?></textarea></div>
<div class="field"><label>متن دکمه اصلی</label><input name="home_hero_primary_label" maxlength="60" value="<?=e($values['home_hero_primary_label'])?>" required></div>
<div class="field"><label>مسیر دکمه اصلی</label><input name="home_hero_primary_url" dir="ltr" maxlength="500" value="<?=e($values['home_hero_primary_url'])?>" required><small>برای نمونه: shop.php</small></div>
<div class="field"><label>متن دکمه دوم</label><input name="home_hero_secondary_label" maxlength="60" value="<?=e($values['home_hero_secondary_label'])?>"></div>
<div class="field"><label>مسیر دکمه دوم</label><input name="home_hero_secondary_url" dir="ltr" maxlength="500" value="<?=e($values['home_hero_secondary_url'])?>" required><small>برای نمونه: blog.php</small></div>
<div class="field"><label>عنوان کارت اعتماد روی تصویر</label><input name="home_hero_note_title" maxlength="140" value="<?=e($values['home_hero_note_title'])?>"></div>
<div class="field"><label>توضیح کارت اعتماد</label><input name="home_hero_note_text" maxlength="180" value="<?=e($values['home_hero_note_text'])?>"></div>
<div class="field full"><label>تصویر بنر</label><input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp"><small>حداقل ۶۴۰×۳۶۰؛ JPG، PNG یا WebP. تصویر به‌صورت خودکار کوچک و کم‌حجم می‌شود.</small><?php if($values['home_hero_image']!==''):?><img class="homepage-admin-preview" src="<?=e(public_image($values['home_hero_image']))?>" alt=""><label class="checkbox-row"><input type="checkbox" name="remove_hero_image" value="1"> حذف تصویر فعلی و استفاده از تصویر پیش‌فرض</label><?php endif?></div>
</div></div>
<div class="card"><h2>بخش‌های فروشگاه</h2><div class="form-grid">
<div class="field"><label>عنوان دسته‌بندی‌ها</label><input name="home_categories_title" maxlength="140" value="<?=e($values['home_categories_title'])?>" required></div>
<div class="field"><label>عنوان محصولات منتخب</label><input name="home_featured_title" maxlength="140" value="<?=e($values['home_featured_title'])?>" required></div>
<div class="field"><label>عنوان محصولات تازه</label><input name="home_newest_title" maxlength="140" value="<?=e($values['home_newest_title'])?>" required></div>
<div class="field"><label>تعداد محصول در هر بخش</label><select name="home_products_limit"><?php foreach([4,8,12] as $limit):?><option value="<?=$limit?>" <?=(int)$values['home_products_limit']===$limit?'selected':''?>><?=fa_number($limit)?> محصول</option><?php endforeach?></select></div>
<div class="field full checkboxes"><label><input type="checkbox" name="home_show_categories" <?=$values['home_show_categories']==='1'?'checked':''?>> نمایش دسته‌بندی‌های محبوب</label><label><input type="checkbox" name="home_show_banners" <?=$values['home_show_banners']==='1'?'checked':''?>> نمایش بنرهای تصویری چندگانه</label><label><input type="checkbox" name="home_show_deals" <?=$values['home_show_deals']==='1'?'checked':''?>> نمایش جشنواره و کاهش قیمت واقعی</label><label><input type="checkbox" name="home_show_featured" <?=$values['home_show_featured']==='1'?'checked':''?>> نمایش محصولات منتخب</label><label><input type="checkbox" name="home_show_category_bestsellers" <?=$values['home_show_category_bestsellers']==='1'?'checked':''?>> نمایش پرفروش‌های هر دسته</label><label><input type="checkbox" name="home_show_newest" <?=$values['home_show_newest']==='1'?'checked':''?>> نمایش تازه‌ترین کالاهای موجود</label><label><input type="checkbox" name="home_show_blog" <?=$values['home_show_blog']==='1'?'checked':''?>> نمایش آخرین مطالب وبلاگ</label><label><input type="checkbox" name="home_show_newsletter" <?=$values['home_show_newsletter']==='1'?'checked':''?>> نمایش عضویت پیامکی</label><label><input type="checkbox" name="home_show_instagram" <?=$values['home_show_instagram']==='1'?'checked':''?>> نمایش بخش اینستاگرام</label></div>
</div></div>
<div class="card"><div class="admin-title"><div><h2>پرفروش‌های هر دسته</h2><p class="muted">محصولات بر پایه تعداد فروش سفارش‌های پرداخت‌شده مرتب می‌شوند؛ اگر سابقه فروش نباشد، کالاهای منتخب و پربازدیدتر جایگزین می‌شوند.</p></div></div><div class="form-grid">
<div class="field"><label>عنوان بخش</label><input name="home_bestseller_title" maxlength="140" value="<?=e($values['home_bestseller_title'])?>" required></div>
<div class="field"><label>تعداد دسته</label><select name="home_bestseller_category_limit"><?php foreach([2,3,4,5,6] as $limit):?><option value="<?=$limit?>" <?=(int)$values['home_bestseller_category_limit']===$limit?'selected':''?>><?=fa_number($limit)?> دسته</option><?php endforeach?></select></div>
<div class="field"><label>تعداد کالا در هر دسته</label><select name="home_bestseller_product_limit"><?php foreach([2,3,4] as $limit):?><option value="<?=$limit?>" <?=(int)$values['home_bestseller_product_limit']===$limit?'selected':''?>><?=fa_number($limit)?> کالا</option><?php endforeach?></select></div>
<div class="field full"><label>دسته‌های منتخب؛ حداکثر ۶ مورد</label><div class="checkboxes homepage-category-choices"><?php foreach($allHomepageCategories as $category):?><label><input type="checkbox" name="bestseller_category_ids[]" value="<?=$category['id']?>" <?=in_array((int)$category['id'],$selectedBestsellerIds,true)?'checked':''?>> <?=e($category['name'])?></label><?php endforeach?><?php if(!$allHomepageCategories):?><span class="muted">ابتدا یک دسته فعال بسازید.</span><?php endif?></div><small>اگر هیچ دسته‌ای انتخاب نشود، سیستم دسته‌های دارای بیشترین فروش را خودکار انتخاب می‌کند.</small></div>
</div></div>
<div class="card"><h2>اینستاگرام با قاب گوشی</h2><p class="muted">این بخش با همان چیدمان نمونه، شامل نوار گرادیانی در سمت راست و گوشی در سمت چپ صفحه نمایش داده می‌شود.</p><div class="form-grid">
<div class="field"><label>عنوان دعوت</label><input name="instagram_title" maxlength="140" value="<?=e($values['instagram_title'])?>" required></div>
<div class="field"><label>نام کاربری</label><input name="instagram_username" dir="ltr" maxlength="30" pattern="[A-Za-z0-9._]{1,30}" value="<?=e($values['instagram_username'])?>" required></div>
<div class="field full"><label>نشانی صفحه اینستاگرام</label><input type="url" name="instagram_url" dir="ltr" maxlength="500" value="<?=e($values['instagram_url'])?>" required></div>
<div class="field full"><label>تصویر داخل گوشی</label><input type="file" name="instagram_profile_image" accept="image/jpeg,image/png,image/webp"><small>حداقل ۵۰۰×۳۵۰؛ تصویر هنگام بارگذاری به‌صورت خودکار کم‌حجم می‌شود.</small><?php if($values['instagram_profile_image']!==''):?><img class="homepage-admin-preview homepage-instagram-preview" src="<?=e(public_image($values['instagram_profile_image']))?>" alt="پیش‌نمایش تصویر اینستاگرام"><?php endif?></div>
</div></div>
<div class="flash success">ردیف جشنواره فقط وقتی نمایش داده می‌شود که یک جشنواره قیمتِ گروهی به‌عنوان «جشنواره اصلی صفحهٔ اول» انتخاب شده و محصول فعال داشته باشد. <a href="<?=e(app_url('admin/festivals.php'))?>">مدیریت جشنواره‌ها</a> · تصاویر جدید نیز به‌صورت خودکار کوچک و کم‌حجم می‌شوند.</div>
<button class="btn btn-primary">ذخیره طراحی صفحه اصلی</button>
</form>
<?php require __DIR__ . '/partials/footer.php';
