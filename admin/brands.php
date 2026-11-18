<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'save', 'save');
    $id = max(0, scalar_int($_POST['id'] ?? 0));

    if ($action === 'delete') {
        $result = taxonomy_delete_brand($id, ['audit_event'=>'brand_deleted']);
        if ($result['ok']) flash('success', $result['message']);
        else flash('error', $result['message']);
        redirect(app_url('admin/brands.php'));
    }


    if ($action === 'toggle') {
        if ($id < 1) {
            flash('error', 'برند معتبر نیست.');
        } else {
            $statement = db()->prepare('UPDATE brands SET is_active=1-is_active WHERE id=?');
            $statement->execute([$id]);
            flash($statement->rowCount() === 1 ? 'success' : 'error', $statement->rowCount() === 1 ? 'وضعیت برند تغییر کرد.' : 'برند پیدا نشد.');
        }
        redirect(app_url('admin/brands.php'));
    }

    $name = trim(scalar_string($_POST['name'] ?? ''));
    $slug = slugify(trim(scalar_string($_POST['slug'] ?? '')) ?: $name);
    $sort = max(-1000000, min(1000000, scalar_int($_POST['sort_order'] ?? 0)));
    $errors = [];
    if (text_length($name) < 2 || text_length($name) > 120) $errors[] = 'نام برند باید بین ۲ تا ۱۲۰ کاراکتر باشد.';
    if (!preg_match('/^[a-z0-9-]{2,140}$/', $slug)) $errors[] = 'نشانی برند معتبر نیست.';

    if ($errors) {
        foreach ($errors as $error) flash('error', $error);
    } else {
        try {
            if ($id) {
                $statement = db()->prepare('UPDATE brands SET name=?,slug=?,sort_order=? WHERE id=?');
                $statement->execute([$name, $slug, $sort, $id]);
                if ($statement->rowCount() === 0) {
                    $check = db()->prepare('SELECT id FROM brands WHERE id=?');
                    $check->execute([$id]);
                    if (!$check->fetchColumn()) throw new RuntimeException('برند پیدا نشد.');
                }
            } else {
                db()->prepare('INSERT INTO brands(name,slug,sort_order) VALUES(?,?,?)')->execute([$name, $slug, $sort]);
            }
            flash('success', 'برند ذخیره شد.');
        } catch (Throwable $exception) {
            log_error($exception);
            flash('error', $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage() : 'نام یا نشانی برند تکراری است یا اطلاعات معتبر نیست.');
        }
    }
    redirect(app_url('admin/brands.php' . ($id ? '?edit=' . $id : '')));
}

$editId = max(0, scalar_int($_GET['edit'] ?? 0));
$edit = null;
if ($editId) {
    $statement = db()->prepare('SELECT * FROM brands WHERE id=?');
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: null;
    if (!$edit) flash('error', 'برند موردنظر پیدا نشد.');
}
$items = db()->query('SELECT b.*,(SELECT COUNT(*) FROM products p WHERE p.brand_id=b.id) product_count FROM brands b ORDER BY b.sort_order,b.name')->fetchAll();
$adminPage = 'brands';
$adminTitle = 'برندها';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><h1>برندها</h1></div>
<div class="two-col">
<div class="table-wrap"><table><thead><tr><th>نام</th><th>نشانی</th><th>محصولات</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?=e($item['name'])?></td><td dir="ltr"><?=e($item['slug'])?></td><td><?=fa_number((int)($item['product_count']??0))?></td><td><?=$item['is_active'] ? 'فعال' : 'غیرفعال'?></td><td><div class="actions"><a class="btn btn-secondary" href="?edit=<?=(int)$item['id']?>">ویرایش</a><form class="inline-form" method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="toggle"><button class="btn btn-danger">تغییر وضعیت</button></form><form class="inline-form" method="post" data-confirm="برند «<?=e($item['name'])?>» برای همیشه حذف شود؟"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$item['id']?>"><input type="hidden" name="action" value="delete"><button class="btn btn-danger" type="submit">حذف</button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div>
<form class="card" method="post"><h2><?=$edit ? 'ویرایش' : 'برند جدید'?></h2><?=csrf_field()?><input type="hidden" name="id" value="<?=e($edit['id'] ?? 0)?>"><div class="field"><label>نام</label><input name="name" maxlength="120" value="<?=e($edit['name'] ?? '')?>" required></div><div class="field"><label>نشانی</label><input name="slug" dir="ltr" maxlength="140" pattern="[a-z0-9-]{2,140}" value="<?=e($edit['slug'] ?? '')?>"></div><div class="field"><label>ترتیب</label><input type="number" name="sort_order" min="-1000000" max="1000000" value="<?=e($edit['sort_order'] ?? 0)?>"></div><button class="btn btn-primary" style="margin-top:14px">ذخیره</button></form>
</div>
<?php require __DIR__ . '/partials/footer.php';
