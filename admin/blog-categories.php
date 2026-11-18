<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'save');
    $id = max(0, scalar_int($_POST['id'] ?? 0));
    try {
        if ($action === 'delete') {
            $count = db()->prepare('SELECT COUNT(*) FROM blog_posts WHERE category_id=?');
            $count->execute([$id]);
            if ((int)$count->fetchColumn() > 0) throw new RuntimeException('این دسته دارای نوشته است و قابل حذف نیست. ابتدا نوشته‌ها را منتقل کنید.');
            db()->prepare('DELETE FROM blog_categories WHERE id=?')->execute([$id]);
            flash('success', 'دسته وبلاگ حذف شد.');
        } elseif ($action === 'toggle') {
            db()->prepare('UPDATE blog_categories SET is_active=1-is_active WHERE id=?')->execute([$id]);
            flash('success', 'وضعیت دسته تغییر کرد.');
        } else {
            $name = trim(scalar_string($_POST['name'] ?? ''));
            $slug = slugify(trim(scalar_string($_POST['slug'] ?? '')) ?: $name);
            $description = trim(scalar_string($_POST['description'] ?? ''));
            $metaTitle = trim(scalar_string($_POST['meta_title'] ?? ''));
            $metaDescription = trim(scalar_string($_POST['meta_description'] ?? ''));
            $sortOrder = max(-100000, min(100000, scalar_int($_POST['sort_order'] ?? 0)));
            if (text_length($name) < 2 || text_length($name) > 120) throw new RuntimeException('نام دسته باید بین ۲ تا ۱۲۰ کاراکتر باشد.');
            if (!preg_match('/^[a-z0-9-]{2,140}$/', $slug)) throw new RuntimeException('نشانی دسته معتبر نیست.');
            if (text_length($description) > 500 || text_length($metaTitle) > 255 || text_length($metaDescription) > 320) throw new RuntimeException('یکی از متن‌ها بیش از حد مجاز است.');
            if ($id > 0) {
                db()->prepare('UPDATE blog_categories SET name=?,slug=?,description=?,meta_title=?,meta_description=?,sort_order=? WHERE id=?')->execute([$name,$slug,$description?:null,$metaTitle?:null,$metaDescription?:null,$sortOrder,$id]);
            } else {
                db()->prepare('INSERT INTO blog_categories(name,slug,description,meta_title,meta_description,sort_order) VALUES(?,?,?,?,?,?)')->execute([$name,$slug,$description?:null,$metaTitle?:null,$metaDescription?:null,$sortOrder]);
            }
            flash('success', 'دسته وبلاگ ذخیره شد.');
        }
    } catch (Throwable $exception) {
        log_error($exception);
        flash('error', $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage() : 'ذخیره انجام نشد؛ نام یا نشانی دسته تکراری است.');
    }
    redirect(app_url('admin/blog-categories.php' . ($action === 'save' && $id > 0 ? '?edit=' . $id : '')));
}

$editId = max(0, scalar_int($_GET['edit'] ?? 0));
$edit = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM blog_categories WHERE id=?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}
$categories = blog_categories(false);
$adminPage = 'blog';
$adminTitle = 'دسته‌بندی‌های وبلاگ';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><div><h1>دسته‌بندی‌های وبلاگ</h1><p class="muted">ساختار موضوعی محتوا و اطلاعات سئوی هر دسته را مدیریت کنید.</p></div><a class="btn btn-secondary" href="<?=e(app_url('admin/blog-posts.php'))?>">نوشته‌ها</a></div>
<div class="two-col">
<div class="table-wrap"><table><thead><tr><th>دسته</th><th>نوشته منتشرشده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($categories as $category):?><tr><td><strong><?=e($category['name'])?></strong><br><small dir="ltr"><?=e($category['slug'])?></small></td><td><?=fa_number((int)$category['post_count'])?></td><td><?=$category['is_active']?'فعال':'غیرفعال'?></td><td><div class="admin-actions"><a class="btn btn-secondary btn-sm" href="?edit=<?=$category['id']?>">ویرایش</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$category['id']?>"><button class="btn btn-secondary btn-sm">تغییر وضعیت</button></form><?php if((int)$category['post_count']===0):?><form method="post" data-confirm="این دسته حذف شود؟"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$category['id']?>"><button class="btn btn-danger btn-sm">حذف</button></form><?php endif?></div></td></tr><?php endforeach?><?php if(!$categories):?><tr><td colspan="4">هنوز دسته‌ای ساخته نشده است.</td></tr><?php endif?></tbody></table></div>
<form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id']??0)?>"><h2><?=$edit?'ویرایش دسته':'دسته جدید'?></h2><div class="field"><label>نام دسته</label><input name="name" maxlength="120" value="<?=e($edit['name']??'')?>" required></div><div class="field"><label>نشانی انگلیسی</label><input name="slug" dir="ltr" maxlength="140" pattern="[a-z0-9-]{2,140}" value="<?=e($edit['slug']??'')?>"><small>در صورت خالی‌بودن، خودکار ساخته می‌شود.</small></div><div class="field"><label>توضیح کوتاه</label><textarea name="description" maxlength="500" rows="3"><?=e($edit['description']??'')?></textarea></div><div class="field"><label>عنوان سئو؛ اختیاری</label><input name="meta_title" maxlength="255" value="<?=e($edit['meta_title']??'')?>"></div><div class="field"><label>توضیحات سئو؛ اختیاری</label><textarea name="meta_description" maxlength="320" rows="3"><?=e($edit['meta_description']??'')?></textarea></div><div class="field"><label>ترتیب</label><input type="number" name="sort_order" min="-100000" max="100000" value="<?=e($edit['sort_order']??0)?>"></div><button class="btn btn-primary">ذخیره دسته</button></form>
</div>
<?php require __DIR__ . '/partials/footer.php';
