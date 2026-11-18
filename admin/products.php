<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

function admin_product_selection(mixed $values): array
{
    if (!is_array($values)) return [];
    $ids = [];
    foreach ($values as $value) {
        $id = max(0, scalar_int($value));
        if ($id > 0) $ids[$id] = $id;
        if (count($ids) >= 200) break;
    }
    return array_values($ids);
}

function admin_product_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function admin_products_redirect_url(): string
{
    $query = [];
    $q = text_substr(trim(scalar_string($_POST['return_q'] ?? '')), 0, 100);
    $status = scalar_string($_POST['return_status'] ?? '');
    $page = max(1, min(100000, scalar_int($_POST['return_page'] ?? 1, 1)));
    if ($q !== '') $query['q'] = $q;
    if (in_array($status, ['active', 'draft'], true)) $query['status'] = $status;
    if ($page > 1) $query['page'] = $page;
    return app_url('admin/products.php') . ($query ? '?' . http_build_query($query) : '');
}


function admin_product_protected_ids(PDO $pdo, array $ids): array
{
    if (!$ids) return [];
    $marks = admin_product_placeholders($ids);
    $protected = [];
    foreach (['order_items','purchase_order_items','inventory_movements','damaged_inventory'] as $table) {
        $statement = $pdo->prepare("SELECT DISTINCT product_id FROM `$table` WHERE product_id IN ($marks)");
        $statement->execute($ids);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $productId) $protected[(int)$productId] = true;
    }
    return $protected;
}

function admin_product_image_paths(PDO $pdo, array $ids): array
{
    if (!$ids) return [];
    $marks = admin_product_placeholders($ids);
    $paths = [];
    foreach ([
        "SELECT image_path FROM products WHERE id IN ($marks)",
        "SELECT image_path FROM product_gallery WHERE product_id IN ($marks)",
        "SELECT image_path FROM product_variants WHERE product_id IN ($marks)",
    ] as $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute($ids);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $path = trim((string)$path);
            if ($path !== '') $paths[$path] = $path;
        }
    }
    return array_values($paths);
}

function admin_products_limited_discount_lock(PDO $pdo, array $ids): ?array
{
    if (!$ids) return null;
    $marks = admin_product_placeholders($ids);
    $statement = $pdo->prepare(
        "SELECT p.id,p.title,f.id festival_id FROM products p JOIN festivals f ON f.target_id=p.id
         WHERE p.id IN ($marks) AND f.is_torobpay_special=1 AND f.is_active=1
           AND f.festival_type='price' AND f.scope='product' AND f.ends_at>=NOW()
         ORDER BY f.starts_at,f.id LIMIT 1"
    );
    $statement->execute($ids);
    return $statement->fetch() ?: null;
}

function admin_product_cleanup_images(array $paths): void
{
    foreach (array_values(array_unique($paths)) as $path) product_gallery_delete_file_if_unused((string)$path);
}

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? '');
    $redirectUrl = admin_products_redirect_url();

    if ($action === 'toggle') {
        $id = max(0, scalar_int($_POST['id'] ?? 0));
        if ($id < 1) {
            flash('error', 'درخواست تغییر وضعیت معتبر نیست.');
        } else {
            $statement = db()->prepare('SELECT p.status,p.price,c.is_active category_active,b.is_active brand_active FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.id=?');
            $statement->execute([$id]);
            $product = $statement->fetch();
            if (!$product) {
                flash('error', 'محصول پیدا نشد.');
            } elseif ($product['status'] === 'active') {
                $torobpayLock = admin_products_limited_discount_lock(db(), [$id]);
                if ($torobpayLock) flash('error', 'این محصول تخفیف محدود فعال یا زمان‌بندی‌شده دارد؛ ابتدا تخفیف را لغو کنید.');
                else {
                    db()->prepare("UPDATE products SET status='draft' WHERE id=?")->execute([$id]);
                    admin_audit_event('product_disabled', 'product', $id);
                    flash('success', 'محصول غیرفعال شد.');
                }
            } elseif ((int)$product['price'] <= 0) {
                flash('error', 'برای فعال‌کردن محصول ابتدا قیمت معتبر ثبت کنید.');
            } elseif (!(int)$product['category_active'] || !(int)$product['brand_active']) {
                flash('error', 'برای فعال‌کردن محصول، دسته‌بندی و برند آن نیز باید فعال باشند.');
            } else {
                db()->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$id]);
                admin_audit_event('product_enabled', 'product', $id);
                flash('success', 'محصول فعال شد.');
            }
        }
        redirect($redirectUrl);
    }

    if ($action === 'delete') {
        $id = max(0, scalar_int($_POST['id'] ?? 0));
        if ($id < 1) {
            flash('error', 'محصول معتبر نیست.');
            redirect($redirectUrl);
        }

        $pdo = db();
        $imagePaths = [];
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT id,title,status FROM products WHERE id=? FOR UPDATE');
            $statement->execute([$id]);
            $product = $statement->fetch();
            if (!$product) throw new RuntimeException('محصول پیدا نشد.');
            if (admin_products_limited_discount_lock($pdo, [$id])) throw new RuntimeException('این محصول تخفیف محدود فعال یا زمان‌بندی‌شده دارد؛ ابتدا تخفیف را لغو کنید.');

            $protected = admin_product_protected_ids($pdo, [$id]);
            if (isset($protected[$id])) {
                $pdo->prepare("UPDATE products SET status='draft' WHERE id=?")->execute([$id]);
                $pdo->commit();
                admin_audit_event('product_archived_instead_of_delete', 'product', $id, ['title'=>(string)$product['title']]);
                flash('success', 'این محصول سابقه سفارش، خرید یا گردش انبار دارد؛ برای حفظ سوابق مالی حذف نشد و از سایت آرشیو شد.');
                redirect($redirectUrl);
            }

            $imagePaths = admin_product_image_paths($pdo, [$id]);
            $delete = $pdo->prepare('DELETE FROM products WHERE id=?');
            $delete->execute([$id]);
            if ($delete->rowCount() !== 1) throw new RuntimeException('حذف محصول انجام نشد.');
            $pdo->commit();

            admin_product_cleanup_images($imagePaths);
            admin_audit_event('product_deleted', 'product', $id, ['title'=>(string)$product['title'],'images_removed'=>count($imagePaths)]);
            flash('success', 'محصول و اطلاعات وابسته آن با موفقیت حذف شد.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error($exception);
            flash('error', $exception instanceof RuntimeException && !($exception instanceof PDOException)
                ? $exception->getMessage()
                : 'حذف محصول انجام نشد؛ محصول به اطلاعات مالی یا عملیاتی فروشگاه متصل است.');
        }
        redirect($redirectUrl);
    }

    if ($action === 'bulk') {
        $bulkAction = scalar_string($_POST['bulk_action'] ?? '');
        $ids = admin_product_selection($_POST['product_ids'] ?? []);
        if (!$ids) {
            flash('error', 'حداقل یک محصول را انتخاب کنید.');
            redirect($redirectUrl);
        }
        if (!in_array($bulkAction, ['disable', 'delete'], true)) {
            flash('error', 'عملیات گروهی انتخاب‌شده معتبر نیست.');
            redirect($redirectUrl);
        }

        $pdo = db();
        $marks = admin_product_placeholders($ids);
        $pdo->beginTransaction();
        try {
            $selectedStatement = $pdo->prepare("SELECT id,title,status FROM products WHERE id IN ($marks) FOR UPDATE");
            $selectedStatement->execute($ids);
            $selectedProducts = $selectedStatement->fetchAll();
            $existingIds = array_map(static fn(array $row): int => (int)$row['id'], $selectedProducts);
            if (!$existingIds) throw new RuntimeException('محصولات انتخاب‌شده پیدا نشدند.');
            $torobpayLock = admin_products_limited_discount_lock($pdo, $existingIds);
            if ($torobpayLock) throw new RuntimeException('محصول «' . (string)$torobpayLock['title'] . '» تخفیف محدود فعال یا زمان‌بندی‌شده دارد؛ ابتدا تخفیف آن را لغو کنید.');

            if ($bulkAction === 'disable') {
                $existingMarks = admin_product_placeholders($existingIds);
                $update = $pdo->prepare("UPDATE products SET status='draft' WHERE id IN ($existingMarks) AND status='active'");
                $update->execute($existingIds);
                $changed = $update->rowCount();
                $pdo->commit();
                admin_audit_event('products_bulk_disabled', 'product', null, ['product_ids'=>$existingIds,'changed'=>$changed]);
                flash('success', $changed > 0
                    ? fa_number($changed) . ' محصول غیرفعال شد.'
                    : 'محصولات انتخاب‌شده از قبل غیرفعال بودند.');
                redirect($redirectUrl);
            }

            $protectedIds = admin_product_protected_ids($pdo, $existingIds);
            $deletableIds = array_values(array_filter(
                $existingIds,
                static fn(int $productId): bool => !isset($protectedIds[$productId])
            ));
            $imagePaths = admin_product_image_paths($pdo, $deletableIds);
            if ($deletableIds) {
                $deleteMarks = admin_product_placeholders($deletableIds);
                $deleteStatement = $pdo->prepare("DELETE FROM products WHERE id IN ($deleteMarks)");
                $deleteStatement->execute($deletableIds);
            }
            $pdo->commit();
            admin_product_cleanup_images($imagePaths);

            $deletedCount = count($deletableIds);
            $protectedCount = count($protectedIds);
            admin_audit_event('products_bulk_deleted', 'product', null, [
                'deleted_product_ids'=>$deletableIds,
                'protected_product_ids'=>array_map('intval', array_keys($protectedIds)),
            ]);
            if ($deletedCount > 0) flash('success', fa_number($deletedCount) . ' محصول حذف شد.');
            if ($protectedCount > 0) {
                flash('error', fa_number($protectedCount) . ' محصول به‌دلیل داشتن سابقه سفارش، خرید یا گردش انبار حذف نشد. برای پنهان‌کردن آن‌ها از گزینه غیرفعال‌سازی استفاده کنید.');
            }
            if ($deletedCount === 0 && $protectedCount === 0) flash('error', 'محصول قابل حذفی در میان انتخاب‌ها پیدا نشد.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $exception instanceof PDOException
                ? 'حذف گروهی انجام نشد؛ یکی از محصولات به اطلاعات مالی یا عملیاتی فروشگاه متصل است.'
                : $exception->getMessage());
        }
        redirect($redirectUrl);
    }

    flash('error', 'عملیات درخواستی معتبر نیست.');
    redirect($redirectUrl);
}

$adminPage = 'products';
$adminTitle = 'مدیریت محصولات';
$q = text_substr(trim(scalar_string($_GET['q'] ?? '')), 0, 100);
$status = scalar_string($_GET['status'] ?? '');
$page = max(1, min(100000, scalar_int($_GET['page'] ?? 1, 1)));
$perPage = 50;
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.title LIKE ? OR p.sku LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($status, ['active','draft'], true)) {
    $where[] = 'p.status=?';
    $params[] = $status;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStatement = db()->prepare('SELECT COUNT(*) FROM products p' . $whereSql);
$countStatement->execute($params);
$total = (int)$countStatement->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$sql = 'SELECT p.*,c.name category_name,b.name brand_name FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id' . $whereSql . ' ORDER BY p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$statement = db()->prepare($sql);
$statement->execute($params);
$products = $statement->fetchAll();
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><h1>محصولات</h1><div class="admin-actions"><a class="btn btn-primary" href="<?=e(app_url('admin/product-quick-add.php'))?>">+ افزودن سریع</a><a class="btn btn-secondary" href="<?=e(app_url('admin/product-edit.php'))?>">فرم کامل محصول</a><a class="btn btn-secondary" href="<?=e(app_url('admin/price-adjustments.php'))?>">📊 تغییر گروهی قیمت‌ها</a></div></div>
<form class="filters-admin"><input name="q" maxlength="100" value="<?=e($q)?>" placeholder="جست‌وجوی نام یا کد"><select name="status"><option value="">همه وضعیت‌ها</option><option value="active" <?=$status === 'active' ? 'selected' : ''?>>فعال</option><option value="draft" <?=$status === 'draft' ? 'selected' : ''?>>پیش‌نویس</option></select><button class="btn btn-secondary">جست‌وجو</button></form>

<?php if ($products): ?>
<form id="bulk-products-form" class="card bulk-products-toolbar" method="post" data-bulk-products-form>
    <?=csrf_field()?>
    <input type="hidden" name="action" value="bulk">
    <input type="hidden" name="return_q" value="<?=e($q)?>">
    <input type="hidden" name="return_status" value="<?=e($status)?>">
    <input type="hidden" name="return_page" value="<?=$page?>">
    <div class="bulk-products-toolbar__summary"><strong>عملیات گروهی محصولات</strong><span class="muted" data-product-selected-count>هیچ محصولی انتخاب نشده است.</span></div>
    <div class="admin-actions">
        <select name="bulk_action" data-product-bulk-action aria-label="انتخاب عملیات گروهی">
            <option value="disable">غیرفعال‌کردن انتخاب‌شده‌ها</option>
            <option value="delete">حذف انتخاب‌شده‌ها</option>
        </select>
        <button class="btn btn-danger" type="submit" data-product-bulk-submit disabled>اجرای عملیات</button>
    </div>
</form>
<?php endif; ?>

<div class="table-wrap"><table><thead><tr><th class="product-select-cell"><input type="checkbox" data-product-select-all aria-label="انتخاب همه محصولات این صفحه" title="انتخاب همه محصولات این صفحه"></th><th>تصویر</th><th>محصول</th><th>دسته/برند</th><th>قیمت</th><th>موجودی</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach ($products as $product): ?><tr data-product-row><td class="product-select-cell"><input type="checkbox" name="product_ids[]" value="<?=(int)$product['id']?>" form="bulk-products-form" data-product-select aria-label="انتخاب <?=e($product['title'])?>"></td><td><img class="thumb" src="<?=e(public_image((string)$product['image_path']))?>" alt=""></td><td><strong><?=e($product['title'])?></strong><?php if(!empty($product['festival_enabled'])):?><span class="badge orange">جشنواره<?php if(!empty($product['festival_free_shipping'])):?> + ارسال رایگان<?php endif?></span><?php endif?><br><small><?=e($product['sku'])?> · <?=($product['product_type']??'simple')==='variable'?'متغیر':'ساده'?></small></td><td><?=e($product['category_name'])?><br><small><?=e($product['brand_name'])?></small></td><td><?php if(!empty($product['festival_enabled'])&&!empty($product['festival_price'])&&(int)$product['festival_price']<(int)$product['price']):?><small class="muted"><s><?=fa_number(format_price($product['price']))?></s></small><br><strong><?=fa_number(format_price($product['festival_price']))?></strong><?php else:?><?=fa_number(format_price($product['price']))?><?php endif?></td><td><span class="badge <?=(int)$product['stock'] <= 3 ? 'orange' : 'green'?>"><?=fa_number($product['stock'])?></span></td><td><span class="badge <?=$product['status'] === 'active' ? 'green' : 'red'?>"><?=$product['status'] === 'active' ? 'فعال' : 'پیش‌نویس'?></span></td><td><div class="actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/product-edit.php?id=' . (int)$product['id']))?>">ویرایش</a><?php if(($product['product_type']??'simple')==='variable'):?><a class="btn btn-secondary" href="<?=e(app_url('admin/product-variants.php?product_id='.(int)$product['id']))?>">متغیرها</a><?php endif?><form class="inline-form" method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$product['id']?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="return_q" value="<?=e($q)?>"><input type="hidden" name="return_status" value="<?=e($status)?>"><input type="hidden" name="return_page" value="<?=$page?>"><button class="btn btn-danger"><?=$product['status'] === 'active' ? 'غیرفعال' : 'فعال'?></button></form><form class="inline-form" method="post" data-confirm="محصول «<?=e($product['title'])?>» حذف شود؟ اگر سابقه مالی داشته باشد، به‌جای حذف از سایت آرشیو می‌شود."><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$product['id']?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_q" value="<?=e($q)?>"><input type="hidden" name="return_status" value="<?=e($status)?>"><input type="hidden" name="return_page" value="<?=$page?>"><button class="btn btn-danger" type="submit">حذف</button></form></div></td></tr><?php endforeach; ?>
<?php if (!$products): ?><tr><td colspan="8">محصولی پیدا نشد.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($pages > 1): $start=max(1,$page-2);$end=min($pages,$page+2); ?><nav class="pagination"><?php if($start>1):?><a href="?<?=e(http_build_query(['q'=>$q,'status'=>$status,'page'=>1]))?>">۱</a><?php endif; ?><?php for($i=$start;$i<=$end;$i++):?><a class="<?=$i===$page?'active':''?>" href="?<?=e(http_build_query(['q'=>$q,'status'=>$status,'page'=>$i]))?>"><?=fa_number($i)?></a><?php endfor; ?><?php if($end<$pages):?><a href="?<?=e(http_build_query(['q'=>$q,'status'=>$status,'page'=>$pages]))?>"><?=fa_number($pages)?></a><?php endif; ?></nav><?php endif; ?>
<?php require __DIR__ . '/partials/footer.php';
