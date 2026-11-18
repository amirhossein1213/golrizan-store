<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$errors = [];
$editId = max(0, scalar_int($_GET['id'] ?? 0));
$categories = array_values(array_filter(all_categories(false), static fn(array $category): bool => (string)($category['slug'] ?? '') !== 'festival'));
$brands = all_brands(false);
$products = db()->query("SELECT id,title,sku,status FROM products ORDER BY title")->fetchAll();

function festival_datetime_input(?string $value): string
{
    return $value ? jalali_datetime_input($value) : '';
}

function festival_state(array $festival): array
{
    $now = time();
    $start = strtotime((string)$festival['starts_at']) ?: 0;
    $end = strtotime((string)$festival['ends_at']) ?: 0;
    if (!(int)$festival['is_active']) return ['غیرفعال','red'];
    if ($start > $now) return ['در انتظار شروع','orange'];
    if ($end < $now) return ['پایان‌یافته','red'];
    return ['فعال','green'];
}

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'save', 'save');
    $id = max(0, scalar_int($_POST['id'] ?? 0));
    if ($action === 'delete') {
        if ($id < 1) flash('error', 'جشنواره انتخاب‌شده معتبر نیست.');
        else {
            try {
                $stmt = db()->prepare('DELETE FROM festivals WHERE id=? AND is_torobpay_special=0');
                $stmt->execute([$id]);
                flash($stmt->rowCount() === 1 ? 'success' : 'error', $stmt->rowCount() === 1 ? 'جشنواره حذف شد.' : 'جشنواره پیدا نشد.');
            } catch (Throwable $e) { log_error($e); flash('error', 'حذف جشنواره انجام نشد.'); }
        }
        redirect(app_url('admin/festivals.php'));
    }

    $title = trim(scalar_string($_POST['title'] ?? ''));
    $festivalType = scalar_string($_POST['festival_type'] ?? 'price', 'price');
    $scope = scalar_string($_POST['scope'] ?? 'product', 'product');
    $discountType = scalar_string($_POST['discount_type'] ?? 'percent', 'percent');
    $discountValid = false;
    $discountValue = bounded_uint($_POST['discount_value'] ?? '0', MAX_MONEY_AMOUNT, $discountValid);
    $targetId = match ($scope) {
        'product' => max(0, scalar_int($_POST['target_product_id'] ?? 0)),
        'category' => max(0, scalar_int($_POST['target_category_id'] ?? 0)),
        'brand' => max(0, scalar_int($_POST['target_brand_id'] ?? 0)),
        default => 0,
    };
    $priority = max(-100000, min(100000, scalar_int($_POST['priority'] ?? 0)));
    $bannerText = text_substr(trim(scalar_string($_POST['banner_text'] ?? '')), 0, 255);
    $showOnHome = isset($_POST['show_on_home']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $startsRaw = scalar_string($_POST['starts_at'] ?? '');
    $endsRaw = scalar_string($_POST['ends_at'] ?? '');
    $starts = jalali_parse_datetime($startsRaw);
    $ends = jalali_parse_datetime($endsRaw);
    $selectedProducts = [];
    foreach ((array)($_POST['product_ids'] ?? []) as $productId) {
        $pid = max(0, scalar_int($productId));
        if ($pid > 0) $selectedProducts[$pid] = $pid;
    }
    $selectedProducts = array_slice(array_values($selectedProducts), 0, 500);

    if (text_length($title) < 3 || text_length($title) > 180) $errors[] = 'عنوان جشنواره باید بین ۳ تا ۱۸۰ نویسه باشد.';
    if ($starts === null || $ends === null || strtotime($ends) <= strtotime($starts)) $errors[] = 'تاریخ پایان شمسی باید بعد از تاریخ شروع باشد؛ نمونه: ۱۴۰۵/۰۵/۰۹، ۱۶:۳۰.';
    if (!in_array($festivalType, ['price','free_shipping'], true)) $errors[] = 'نوع جشنواره معتبر نیست.';

    if ($festivalType === 'free_shipping') {
        $scope = 'all'; $discountType = null; $discountValue = 0; $targetId = 0; $selectedProducts = []; $showOnHome = 0;
    } else {
        if (!in_array($scope, ['category','brand','product','group'], true)) $errors[] = 'محدوده جشنواره معتبر نیست.';
        if (!in_array($discountType, ['percent','fixed'], true) || !$discountValid) $errors[] = 'روش کاهش قیمت معتبر نیست.';
        if ($discountType === 'percent' && ($discountValue < 1 || $discountValue > 99)) $errors[] = 'درصد جشنواره باید بین ۱ تا ۹۹ باشد.';
        if ($discountType === 'fixed' && $discountValue < 1) $errors[] = 'مبلغ جشنواره باید بزرگ‌تر از صفر باشد.';
        if ($scope === 'group' && !$selectedProducts) $errors[] = 'برای جشنواره گروهی حداقل یک کالا انتخاب کنید.';
        if ($scope !== 'group') {
            $table = ['category'=>'categories','brand'=>'brands','product'=>'products'][$scope] ?? null;
            if (!$table || $targetId < 1) $errors[] = 'هدف جشنواره را انتخاب کنید.';
            elseif (!(int)db()->query("SELECT COUNT(*) FROM {$table} WHERE id=" . (int)$targetId)->fetchColumn()) $errors[] = 'هدف انتخاب‌شده در دیتابیس وجود ندارد.';
        } else $targetId = 0;
        if ($showOnHome && $scope !== 'group') $errors[] = 'جشنواره اصلی صفحهٔ اول باید از نوع قیمت و با محدوده گروهی باشد.';
        if ($starts !== null && $ends !== null && strtotime($ends) > strtotime($starts)) {
            $torobpayConflict = torobpay_special_conflict_for_target($scope, $targetId, $selectedProducts, $starts, $ends);
            if ($torobpayConflict) $errors[] = 'محصول «' . (string)$torobpayConflict['product_title'] . '» در همین بازه تخفیف محدود دارد؛ ابتدا زمان‌ها را جدا یا تخفیف را لغو کنید.';
        }
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $lock = $pdo->prepare('SELECT slug FROM festivals WHERE id=? AND is_torobpay_special=0 FOR UPDATE');
                $lock->execute([$id]);
                $existing = $lock->fetch();
                if (!$existing) throw new RuntimeException('جشنواره پیدا نشد.');
                $slug = (string)$existing['slug'];
                $stmt = $pdo->prepare('UPDATE festivals SET title=?,festival_type=?,scope=?,discount_type=?,discount_value=?,target_id=?,starts_at=?,ends_at=?,priority=?,banner_text=?,show_on_home=?,is_active=? WHERE id=?');
                $stmt->execute([$title,$festivalType,$scope,$discountType,$discountValue,$targetId ?: null,$starts,$ends,$priority,$bannerText ?: null,$showOnHome,$isActive,$id]);
            } else {
                $slug = slugify($title) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
                $stmt = $pdo->prepare('INSERT INTO festivals(title,slug,festival_type,scope,discount_type,discount_value,target_id,starts_at,ends_at,priority,banner_text,show_on_home,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$title,$slug,$festivalType,$scope,$discountType,$discountValue,$targetId ?: null,$starts,$ends,$priority,$bannerText ?: null,$showOnHome,$isActive]);
                $id = (int)$pdo->lastInsertId();
            }
            if ($showOnHome) $pdo->prepare('UPDATE festivals SET show_on_home=0 WHERE id<>?')->execute([$id]);
            $pdo->prepare('DELETE FROM festival_products WHERE festival_id=?')->execute([$id]);
            if ($festivalType === 'price' && $scope === 'group') {
                $insert = $pdo->prepare('INSERT INTO festival_products(festival_id,product_id) VALUES(?,?)');
                foreach ($selectedProducts as $productId) $insert->execute([$id,$productId]);
            }
            $pdo->commit();
            flash('success', 'جشنواره ذخیره شد. قیمت‌ها در سایت و سبد خرید به‌صورت خودکار محاسبه می‌شوند.');
            redirect(app_url('admin/festivals.php'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error($e);
            $errors[] = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'ذخیره جشنواره انجام نشد.';
        }
    }
}

$festivals = db()->query("SELECT f.*,c.name category_name,b.name brand_name,p.title product_name,(SELECT COUNT(*) FROM festival_products fp WHERE fp.festival_id=f.id) group_count FROM festivals f LEFT JOIN categories c ON f.scope='category' AND c.id=f.target_id LEFT JOIN brands b ON f.scope='brand' AND b.id=f.target_id LEFT JOIN products p ON f.scope='product' AND p.id=f.target_id WHERE f.is_torobpay_special=0 ORDER BY f.is_active DESC,f.starts_at DESC,f.id DESC")->fetchAll();
$editing = null;
$selectedProductIds = [];
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM festivals WHERE id=? AND is_torobpay_special=0');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
    if ($editing) {
        $memberStmt = db()->prepare('SELECT product_id FROM festival_products WHERE festival_id=?');
        $memberStmt->execute([$editId]);
        $selectedProductIds = array_map('intval', array_column($memberStmt->fetchAll(), 'product_id'));
    } else $errors[] = 'جشنواره انتخاب‌شده پیدا نشد.';
}
$editing = $editing ?: [
    'id'=>0,'title'=>'','festival_type'=>'price','scope'=>'product','discount_type'=>'percent','discount_value'=>10,'target_id'=>null,
    'starts_at'=>date('Y-m-d H:i:s'),'ends_at'=>date('Y-m-d H:i:s', strtotime('+7 days')),'priority'=>0,'banner_text'=>'','show_on_home'=>0,'is_active'=>1,
];
$adminPage='festivals';$adminTitle='جشنواره‌ها';require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1>مدیریت جشنواره‌ها</h1><p class="muted">در صورت هم‌زمانی چند جشنواره قیمت، بهترین قیمت برای مشتری اعمال می‌شود.</p></div><div class="admin-actions"><a class="btn btn-primary" href="<?=e(app_url('admin/limited-discounts.php'))?>">تخفیف محدود</a><a class="btn btn-secondary" href="<?=e(app_url('admin/festivals.php'))?>">جشنواره جدید</a></div></div>
<?php foreach($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<div class="card festival-help"><strong>قواعد:</strong> جشنواره قیمت می‌تواند برای یک کالا، یک برند، یک دسته یا گروهی از کالاهای انتخابی باشد. جشنواره ارسال رایگان روی تمام سفارش‌ها فعال می‌شود و ارسال عادی در سایر روزها پس‌کرایه است.</div>
<div class="two-col festival-layout">
<div class="table-wrap"><table><thead><tr><th>جشنواره</th><th>نوع و محدوده</th><th>مقدار</th><th>زمان</th><th>وضعیت</th><th></th></tr></thead><tbody>
<?php foreach($festivals as $festival):[$state,$color]=festival_state($festival);$target=match($festival['scope']){'all'=>'تمام سفارش‌ها','category'=>$festival['category_name']?:'دسته حذف‌شده','brand'=>$festival['brand_name']?:'برند حذف‌شده','product'=>$festival['product_name']?:'کالای حذف‌شده','group'=>fa_number($festival['group_count']).' کالای انتخابی',default=>'—'};?>
<tr><td><strong><?=e($festival['title'])?></strong><?php if($festival['show_on_home']):?><span class="badge green">صفحهٔ اصلی</span><?php endif?><?php if($festival['banner_text']):?><small class="muted festival-table-note"><?=e($festival['banner_text'])?></small><?php endif?></td><td><?=e($festival['festival_type']==='free_shipping'?'ارسال رایگان':'جشنواره قیمت')?> <small class="muted"><?=e($target)?></small></td><td><?php if($festival['festival_type']==='free_shipping'):?>رایگان<?php elseif($festival['discount_type']==='percent'):?><?=fa_number($festival['discount_value'])?>٪<?php else:?><?=fa_number(format_price($festival['discount_value']))?> تومان<?php endif?></td><td><small><?=e(jalali_format($festival['starts_at']))?><br>تا <?=e(jalali_format($festival['ends_at']))?></small></td><td><span class="badge <?=$color?>"><?=$state?></span></td><td><a class="btn btn-secondary" href="?id=<?=$festival['id']?>">ویرایش</a><form class="inline-form" method="post" data-confirm="این جشنواره حذف شود؟"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$festival['id']?>"><button class="btn btn-danger">حذف</button></form></td></tr>
<?php endforeach?>
<?php if(!$festivals):?><tr><td colspan="6">هنوز جشنواره‌ای ثبت نشده است.</td></tr><?php endif?>
</tbody></table></div>
<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$editing['id']?>"><h2><?=$editing['id']?'ویرایش جشنواره':'جشنواره جدید'?></h2>
<div class="field"><label>عنوان جشنواره</label><input name="title" maxlength="180" value="<?=e($editing['title'])?>" required placeholder="مثلاً جشنواره تابستانه موسوی"></div>
<div class="field"><label>نوع جشنواره</label><select name="festival_type" data-festival-type><option value="price" <?=$editing['festival_type']==='price'?'selected':''?>>جشنواره قیمت</option><option value="free_shipping" <?=$editing['festival_type']==='free_shipping'?'selected':''?>>ارسال رایگان</option></select></div>
<div data-price-festival-fields>
<div class="field"><label>محدوده</label><select name="scope" data-festival-scope><option value="product" <?=$editing['scope']==='product'?'selected':''?>>یک کالا</option><option value="category" <?=$editing['scope']==='category'?'selected':''?>>یک دسته‌بندی</option><option value="brand" <?=$editing['scope']==='brand'?'selected':''?>>یک برند</option><option value="group" <?=$editing['scope']==='group'?'selected':''?>>گروهی از کالاها</option></select></div>
<div class="field" data-target-product><label>کالا</label><select name="target_product_id"><option value="">انتخاب کالا</option><?php foreach($products as $product):?><option value="<?=$product['id']?>" <?=$editing['scope']==='product'&&(int)$editing['target_id']===(int)$product['id']?'selected':''?>><?=e($product['title'].' — '.$product['sku'])?></option><?php endforeach?></select></div>
<div class="field" data-target-category><label>دسته‌بندی</label><select name="target_category_id"><option value="">انتخاب دسته</option><?php foreach($categories as $category):?><option value="<?=$category['id']?>" <?=$editing['scope']==='category'&&(int)$editing['target_id']===(int)$category['id']?'selected':''?>><?=e($category['name'])?></option><?php endforeach?></select></div>
<div class="field" data-target-brand><label>برند</label><select name="target_brand_id"><option value="">انتخاب برند</option><?php foreach($brands as $brand):?><option value="<?=$brand['id']?>" <?=$editing['scope']==='brand'&&(int)$editing['target_id']===(int)$brand['id']?'selected':''?>><?=e($brand['name'])?></option><?php endforeach?></select></div>
<div class="field" data-target-group><label>کالاهای گروه</label><select name="product_ids[]" multiple size="9"><?php foreach($products as $product):?><option value="<?=$product['id']?>" <?=in_array((int)$product['id'],$selectedProductIds,true)?'selected':''?>><?=e($product['title'].' — '.$product['sku'])?></option><?php endforeach?></select><small>برای انتخاب چند کالا از Ctrl یا Command استفاده کنید.</small></div>
<div class="field"><label>روش کاهش قیمت جشنواره</label><select name="discount_type"><option value="percent" <?=$editing['discount_type']==='percent'?'selected':''?>>درصدی</option><option value="fixed" <?=$editing['discount_type']==='fixed'?'selected':''?>>مبلغ ثابت</option></select></div>
<div class="field"><label>مقدار</label><input type="number" name="discount_value" min="1" max="100000000000000" value="<?=e($editing['discount_value'])?>" required><small>برای حالت درصدی عددی بین ۱ تا ۹۹ وارد کنید.</small></div>
</div>
<div class="field"><label>شروع</label><input type="text" name="starts_at" value="<?=e(festival_datetime_input($editing['starts_at']))?>" data-jalali-datetime placeholder="۱۴۰۵/۰۵/۰۹، ۱۶:۳۰" inputmode="numeric" autocomplete="off" required></div>
<div class="field"><label>پایان</label><input type="text" name="ends_at" value="<?=e(festival_datetime_input($editing['ends_at']))?>" data-jalali-datetime placeholder="۱۴۰۵/۰۵/۱۶، ۱۶:۳۰" inputmode="numeric" autocomplete="off" required></div>
<div class="field"><label>اولویت</label><input type="number" name="priority" min="-100000" max="100000" value="<?=e($editing['priority'])?>"><small>در منفعت برابر، عدد بزرگ‌تر مقدم است.</small></div>
<div class="field"><label>متن نمایشی</label><input name="banner_text" maxlength="255" value="<?=e($editing['banner_text'])?>" placeholder="مثلاً فقط تا پایان جمعه"></div>
<div class="checkboxes"><label><input type="checkbox" name="show_on_home" data-home-festival <?=$editing['show_on_home']?'checked':''?>> نمایش به‌عنوان جشنواره اصلی صفحهٔ اول</label><small>این گزینه فقط برای جشنواره قیمتِ گروهی فعال است و جایگزین جشنواره اصلی قبلی می‌شود.</small><label><input type="checkbox" name="is_active" <?=$editing['is_active']?'checked':''?>> فعال</label></div>
<button class="btn btn-primary">ذخیره جشنواره</button></form>
</div>
<script nonce="<?=e(CSP_NONCE)?>">
(()=>{const type=document.querySelector('[data-festival-type]'),scope=document.querySelector('[data-festival-scope]'),priceFields=document.querySelector('[data-price-festival-fields]'),home=document.querySelector('[data-home-festival]');const sync=()=>{const isPrice=type.value==='price';priceFields.hidden=!isPrice;document.querySelectorAll('[data-target-product],[data-target-category],[data-target-brand],[data-target-group]').forEach(el=>el.hidden=true);if(isPrice){const block=document.querySelector('[data-target-'+scope.value+']');if(block)block.hidden=false;}if(home){home.disabled=!(isPrice&&scope.value==='group');if(home.disabled)home.checked=false;}};document.addEventListener('change',e=>{if(e.target===type||e.target===scope)sync();});sync();})();
</script>
<?php require __DIR__.'/partials/footer.php';
