<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'save', 'save');
    $id = max(0, scalar_int($_POST['id'] ?? 0));
    $newUpload = '';
    try {
        if ($action === 'delete') {
            $stmt = db()->prepare('SELECT image_path FROM home_banners WHERE id=?');
            $stmt->execute([$id]);
            $image = (string)$stmt->fetchColumn();
            if ($image === '') throw new RuntimeException('بنر پیدا نشد.');
            db()->prepare('DELETE FROM home_banners WHERE id=?')->execute([$id]);
            delete_managed_upload($image);
            admin_audit_event('home_banner_deleted', 'home_banner', $id);
            flash('success', 'بنر حذف شد.');
            redirect(app_url('admin/home-banners.php'));
        }
        if ($action === 'toggle') {
            db()->prepare('UPDATE home_banners SET is_active=1-is_active WHERE id=?')->execute([$id]);
            admin_audit_event('home_banner_toggled', 'home_banner', $id);
            flash('success', 'وضعیت نمایش بنر تغییر کرد.');
            redirect(app_url('admin/home-banners.php'));
        }

        $title = trim(scalar_string($_POST['title'] ?? ''));
        $subtitle = trim(scalar_string($_POST['subtitle'] ?? ''));
        $eyebrow = trim(scalar_string($_POST['eyebrow'] ?? ''));
        $buttonLabel = trim(scalar_string($_POST['button_label'] ?? 'مشاهده محصولات'));
        $linkUrl = trim(scalar_string($_POST['link_url'] ?? 'shop.php'));
        $layoutSize = scalar_string($_POST['layout_size'] ?? 'medium', 'medium');
        $sortOrder = max(-10000, min(10000, scalar_int($_POST['sort_order'] ?? 0)));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if (text_length($title) < 2 || text_length($title) > 180) throw new RuntimeException('عنوان بنر باید بین ۲ تا ۱۸۰ نویسه باشد.');
        if (text_length($subtitle) > 320 || text_length($eyebrow) > 100 || text_length($buttonLabel) < 2 || text_length($buttonLabel) > 60) throw new RuntimeException('یکی از متن‌های بنر از محدوده مجاز خارج است.');
        if (!storefront_internal_link_is_valid($linkUrl)) throw new RuntimeException('نشانی بنر باید یک مسیر داخلی امن باشد؛ مانند shop.php یا shop.php?q=جاروبرقی.');
        if (!in_array($layoutSize, ['large','medium','small'], true)) throw new RuntimeException('اندازه بنر معتبر نیست.');

        $oldImage = '';
        if ($id > 0) {
            $stmt = db()->prepare('SELECT image_path FROM home_banners WHERE id=?');
            $stmt->execute([$id]);
            $oldImage = (string)$stmt->fetchColumn();
            if ($oldImage === '') throw new RuntimeException('بنر پیدا نشد.');
        }
        $imagePath = $oldImage;
        if (!empty($_FILES['image']['name'])) {
            $newUpload = save_managed_image_upload($_FILES['image'], 'homepage', 6 * 1024 * 1024, 600, 300, 2400);
            $imagePath = $newUpload;
        }
        if ($imagePath === '') throw new RuntimeException('برای بنر جدید یک تصویر انتخاب کنید.');

        if ($id > 0) {
            db()->prepare('UPDATE home_banners SET title=?,subtitle=?,eyebrow=?,button_label=?,link_url=?,image_path=?,layout_size=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([$title,$subtitle ?: null,$eyebrow ?: null,$buttonLabel,$linkUrl,$imagePath,$layoutSize,$sortOrder,$isActive,$id]);
        } else {
            db()->prepare('INSERT INTO home_banners(title,subtitle,eyebrow,button_label,link_url,image_path,layout_size,sort_order,is_active) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$title,$subtitle ?: null,$eyebrow ?: null,$buttonLabel,$linkUrl,$imagePath,$layoutSize,$sortOrder,$isActive]);
            $id = (int)db()->lastInsertId();
        }
        if ($oldImage !== '' && $oldImage !== $imagePath) delete_managed_upload($oldImage);
        admin_audit_event('home_banner_saved', 'home_banner', $id, ['active'=>$isActive,'layout_size'=>$layoutSize]);
        flash('success', 'بنر تصویری ذخیره شد.');
        redirect(app_url('admin/home-banners.php?edit=' . $id));
    } catch (Throwable $exception) {
        if ($newUpload !== '') delete_managed_upload($newUpload);
        log_error($exception);
        flash('error', $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage() : 'ذخیره بنر انجام نشد.');
        redirect(app_url('admin/home-banners.php' . ($id > 0 ? '?edit=' . $id : '')));
    }
}

$editId = max(0, scalar_int($_GET['edit'] ?? 0));
$editing = null;
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM home_banners WHERE id=?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}
$editing = $editing ?: ['id'=>0,'title'=>'','subtitle'=>'','eyebrow'=>'','button_label'=>'مشاهده محصولات','link_url'=>'shop.php','image_path'=>'','layout_size'=>'medium','sort_order'=>0,'is_active'=>1];
$banners = db()->query('SELECT * FROM home_banners ORDER BY sort_order,id')->fetchAll();
$sizeLabels = ['large'=>'عریض','medium'=>'متوسط','small'=>'کوچک'];
$adminPage = 'home-banners';
$adminTitle = 'بنرهای تصویری صفحه اصلی';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><div><h1>بنرهای تصویری</h1><p class="muted">چند بنر با تصویر، متن، مقصد، اندازه و ترتیب مستقل در صفحه اصلی نمایش دهید.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/homepage.php'))?>">تنظیمات صفحه اصلی</a><a class="btn btn-primary" href="<?=e(app_url('admin/home-banners.php'))?>">بنر جدید</a></div></div>
<div class="two-col home-banner-admin">
<div class="table-wrap"><table><thead><tr><th>پیش‌نمایش</th><th>بنر</th><th>چیدمان</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($banners as $banner):?><tr><td><img class="admin-banner-thumb" src="<?=e(public_image((string)$banner['image_path']))?>" alt=""></td><td><strong><?=e($banner['title'])?></strong><small class="muted"><?=e($banner['eyebrow'] ?: $banner['subtitle'])?></small><small dir="ltr"><?=e($banner['link_url'])?></small></td><td><?=e($sizeLabels[$banner['layout_size']] ?? 'متوسط')?> · <?=fa_number((int)$banner['sort_order'])?></td><td><span class="badge <?=$banner['is_active']?'green':'red'?>"><?=$banner['is_active']?'فعال':'غیرفعال'?></span></td><td><div class="admin-actions"><a class="btn btn-secondary btn-sm" href="?edit=<?=$banner['id']?>">ویرایش</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$banner['id']?>"><button class="btn btn-secondary btn-sm">تغییر وضعیت</button></form><form method="post" data-confirm="این بنر حذف شود؟"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$banner['id']?>"><button class="btn btn-danger btn-sm">حذف</button></form></div></td></tr><?php endforeach?>
<?php if(!$banners):?><tr><td colspan="5">هنوز بنری ثبت نشده است.</td></tr><?php endif?></tbody></table></div>
<form class="card" method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=$editing['id']?>"><h2><?=$editing['id']?'ویرایش بنر':'بنر جدید'?></h2>
<div class="field"><label>عنوان</label><input name="title" maxlength="180" value="<?=e($editing['title'])?>" required></div>
<div class="field"><label>متن کوتاه بالای عنوان</label><input name="eyebrow" maxlength="100" value="<?=e($editing['eyebrow'])?>"></div>
<div class="field"><label>توضیح</label><textarea name="subtitle" maxlength="320" rows="3"><?=e($editing['subtitle'])?></textarea></div>
<div class="field"><label>متن دکمه</label><input name="button_label" maxlength="60" value="<?=e($editing['button_label'])?>" required></div>
<div class="field"><label>مسیر داخلی</label><input name="link_url" dir="ltr" maxlength="500" value="<?=e($editing['link_url'])?>" required><small>نمونه: shop.php، shop.php?q=جاروبرقی یا shop.php?category=vacuum</small></div>
<div class="field"><label>اندازه در شبکه</label><select name="layout_size"><?php foreach($sizeLabels as $key=>$label):?><option value="<?=e($key)?>" <?=$editing['layout_size']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></div>
<div class="field"><label>ترتیب نمایش</label><input type="number" name="sort_order" min="-10000" max="10000" value="<?=e($editing['sort_order'])?>"></div>
<div class="field"><label>تصویر <?=$editing['id']?'جایگزین':''?></label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" <?=$editing['id']?'':'required'?>><small>حداقل ۶۰۰×۳۰۰؛ تصویر به‌صورت خودکار کوچک و کم‌حجم می‌شود.</small><?php if($editing['image_path']!==''):?><img class="homepage-admin-preview" src="<?=e(public_image((string)$editing['image_path']))?>" alt=""><?php endif?></div>
<label class="checkbox-row"><input type="checkbox" name="is_active" <?=$editing['is_active']?'checked':''?>> نمایش در صفحه اصلی</label>
<button class="btn btn-primary">ذخیره بنر</button></form></div>
<?php require __DIR__ . '/partials/footer.php';
