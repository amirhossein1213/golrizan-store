<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();


if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'save', 'save');
    $id = max(0, scalar_int($_POST['id'] ?? 0));

    if ($action === 'delete') {
        $result = taxonomy_delete_category($id, ['audit_event'=>'category_deleted']);
        if ($result['ok']) flash('success', $result['message']);
        else flash('error', $result['message']);
        redirect(app_url('admin/categories.php'));
    }


    if ($action === 'toggle') {
        if ($id < 1) {
            flash('error', 'دسته‌بندی معتبر نیست.');
        } else {
            $categoryCheck = db()->prepare('SELECT slug FROM categories WHERE id=?');
            $categoryCheck->execute([$id]);
            $categorySlug = (string)($categoryCheck->fetchColumn() ?: '');
            if ($categorySlug === 'festival') {
                flash('error', 'دسته جشنواره سیستمی است و باید فعال بماند.');
            } else {
                $statement = db()->prepare('UPDATE categories SET is_active=1-is_active WHERE id=?');
                $statement->execute([$id]);
                flash($statement->rowCount() === 1 ? 'success' : 'error', $statement->rowCount() === 1 ? 'وضعیت دسته‌بندی تغییر کرد.' : 'دسته‌بندی پیدا نشد.');
            }
        }
        redirect(app_url('admin/categories.php'));
    }

    $name = trim(scalar_string($_POST['name'] ?? ''));
    $slug = slugify(trim(scalar_string($_POST['slug'] ?? '')) ?: $name);
    if ($id > 0) {
        $systemCategory = db()->prepare('SELECT slug FROM categories WHERE id=?');
        $systemCategory->execute([$id]);
        if ((string)($systemCategory->fetchColumn() ?: '') === 'festival') $slug = 'festival';
    }
    $image = trim(scalar_string($_POST['image_path'] ?? ''));
    $metaTitle = trim(scalar_string($_POST['meta_title'] ?? ''));
    $metaDescription = trim(scalar_string($_POST['meta_description'] ?? ''));
    $sort = max(-1000000, min(1000000, scalar_int($_POST['sort_order'] ?? 0)));
    $errors = [];
    $newUpload = '';
    $oldImage = '';
    if ($id > 0) {
        $oldImageStatement = db()->prepare('SELECT image_path FROM categories WHERE id=?');
        $oldImageStatement->execute([$id]);
        $oldImage = (string)($oldImageStatement->fetchColumn() ?: '');
    }
    if (isset($_POST['remove_image'])) $image = '';
    if (!empty($_FILES['image']['name'])) {
        try {
            $newUpload = save_managed_image_upload($_FILES['image'], 'categories', 4 * 1024 * 1024, 120, 120, 1200);
            $image = $newUpload;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (text_length($name) < 2 || text_length($name) > 120) $errors[] = 'نام دسته‌بندی باید بین ۲ تا ۱۲۰ کاراکتر باشد.';
    if (!preg_match('/^[a-z0-9-]{2,140}$/', $slug)) $errors[] = 'نشانی دسته‌بندی معتبر نیست.';
    if ($image !== '' && !safe_external_url($image) && !safe_internal_image_path($image)) $errors[] = 'مسیر تصویر باید از پوشه تصاویر داخلی و بدون مسیرهای نسبی، یا یک آدرس HTTPS معتبر باشد.';
    if (text_length($metaTitle) > 255 || text_length($metaDescription) > 320) $errors[] = 'اطلاعات سئوی دسته‌بندی بیش از حد طولانی است.';

    if ($errors) {
        if ($newUpload !== '') delete_managed_upload($newUpload);
        foreach ($errors as $error) flash('error', $error);
    } else {
        try {
            if ($id) {
                $statement = db()->prepare('UPDATE categories SET name=?,slug=?,image_path=?,meta_title=?,meta_description=?,sort_order=? WHERE id=?');
                $statement->execute([$name, $slug, $image, $metaTitle?:null, $metaDescription?:null, $sort, $id]);
                if ($statement->rowCount() === 0) {
                    $check = db()->prepare('SELECT id FROM categories WHERE id=?');
                    $check->execute([$id]);
                    if (!$check->fetchColumn()) throw new RuntimeException('دسته‌بندی پیدا نشد.');
                }
            } else {
                db()->prepare('INSERT INTO categories(name,slug,image_path,meta_title,meta_description,sort_order) VALUES(?,?,?,?,?,?)')->execute([$name, $slug, $image, $metaTitle?:null, $metaDescription?:null, $sort]);
            }
            if ($oldImage !== '' && $oldImage !== $image) delete_managed_upload($oldImage);
            flash('success', 'دسته‌بندی ذخیره شد.');
        } catch (Throwable $exception) {
            if ($newUpload !== '') delete_managed_upload($newUpload);
            log_error($exception);
            flash('error', $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage() : 'نام یا نشانی دسته‌بندی تکراری است یا اطلاعات معتبر نیست.');
        }
    }
    redirect(app_url('admin/categories.php' . ($id ? '?edit=' . $id : '')));
}

$editId = max(0, scalar_int($_GET['edit'] ?? 0));
$edit = null;
if ($editId) {
    $statement = db()->prepare('SELECT * FROM categories WHERE id=?');
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: null;
    if (!$edit) flash('error', 'دسته‌بندی موردنظر پیدا نشد.');
}
$items = db()->query('SELECT c.*,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) product_count FROM categories c ORDER BY c.sort_order,c.name')->fetchAll();
$adminPage = 'categories';
$adminTitle = 'دسته‌بندی‌ها';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><h1>دسته‌بندی‌ها</h1></div>
<div class="alert alert-info category-delete-note"><strong>حذف امن دسته‌بندی:</strong> اگر دسته محصول داشته باشد، محصولات آن قبل از حذف به دسته «بدون دسته‌بندی» منتقل می‌شوند. دسته جشنواره و دسته‌ای که هدف مستقیم جشنواره باشد قابل حذف نیست.</div>
<div class="two-col">
<div class="table-wrap"><table><thead><tr><th>نام</th><th>نشانی</th><th>محصولات</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?=e($item['name'])?><?php if($item['slug']==='festival'):?><br><small class="muted">دسته سیستمی محصولات جشنواره‌ای</small><?php endif?></td><td dir="ltr"><?=e($item['slug'])?></td><td><?=fa_number((int)($item['product_count']??0))?></td><td><?=$item['is_active'] ? 'فعال' : 'غیرفعال'?></td><td><div class="actions"><a class="btn btn-secondary" href="?edit=<?=(int)$item['id']?>">ویرایش</a><form class="inline-form" method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="toggle"><button class="btn btn-danger">تغییر وضعیت</button></form><?php if($item['slug']!=='festival'):?><form class="inline-form" method="post" data-confirm="دسته‌بندی «<?=e($item['name'])?>» حذف شود؟<?php if((int)($item['product_count']??0)>0):?> محصولات آن به «بدون دسته‌بندی» منتقل می‌شوند.<?php endif?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="delete"><button class="btn btn-danger" type="submit">حذف</button></form><?php endif?></div></td></tr><?php endforeach; ?>
</tbody></table></div>
<form class="card" method="post" enctype="multipart/form-data"><h2><?=$edit ? 'ویرایش' : 'دسته جدید'?></h2><?=csrf_field()?><input type="hidden" name="id" value="<?=e($edit['id'] ?? 0)?>"><div class="field"><label>نام</label><input name="name" maxlength="120" value="<?=e($edit['name'] ?? '')?>" required></div><div class="field"><label>نشانی</label><input name="slug" dir="ltr" maxlength="140" pattern="[a-z0-9-]{2,140}" value="<?=e($edit['slug'] ?? '')?>"></div><div class="field"><label>بارگذاری تصویر دسته</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPG، PNG یا WebP؛ به‌صورت خودکار کوچک و کم‌حجم می‌شود.</small></div><div class="field"><label>یا مسیر تصویر</label><input name="image_path" dir="ltr" maxlength="255" value="<?=e($edit['image_path'] ?? '')?>"><?php if(!empty($edit['image_path'])):?><label class="checkbox-row"><input type="checkbox" name="remove_image" value="1"> حذف تصویر فعلی</label><?php endif?></div><div class="field"><label>عنوان سئو؛ اختیاری</label><input name="meta_title" maxlength="255" value="<?=e($edit['meta_title'] ?? '')?>"></div><div class="field"><label>توضیحات سئو؛ اختیاری</label><textarea name="meta_description" maxlength="320" rows="3"><?=e($edit['meta_description'] ?? '')?></textarea><small>اگر خالی باشد، عنوان و توضیح به‌صورت خودکار ساخته می‌شود.</small></div><div class="field"><label>ترتیب</label><input type="number" name="sort_order" min="-1000000" max="1000000" value="<?=e($edit['sort_order'] ?? 0)?>"></div><button class="btn btn-primary" style="margin-top:14px">ذخیره</button></form>
</div>
<?php require __DIR__ . '/partials/footer.php';
