<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

function quick_product_slug(string $title): string
{
    $base = text_substr(slugify($title), 0, 165);
    $candidate = $base;
    $check = db()->prepare('SELECT 1 FROM products WHERE slug=? LIMIT 1');
    for ($suffix = 2; $suffix <= 99; $suffix++) {
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
        $candidate = text_substr($base, 0, 172 - strlen((string) $suffix)) . '-' . $suffix;
    }
    return text_substr($base, 0, 160) . '-' . bin2hex(random_bytes(4));
}

function quick_product_sku(): string
{
    $check = db()->prepare('SELECT 1 FROM products WHERE sku=? LIMIT 1');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $sku = 'MS-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $check->execute([$sku]);
        if (!$check->fetchColumn()) {
            return $sku;
        }
    }
    throw new RuntimeException('ساخت کد یکتای محصول انجام نشد؛ دوباره تلاش کنید.');
}

/**
 * @return array<int,array{name:mixed,type:mixed,tmp_name:mixed,error:mixed,size:mixed}>
 */
function quick_product_uploaded_images(array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        return [];
    }
    $uploads = [];
    foreach ($names as $index => $name) {
        if (trim((string) $name) === '') {
            continue;
        }
        $uploads[] = [
            'name' => $files['name'][$index] ?? null,
            'type' => $files['type'][$index] ?? null,
            'tmp_name' => $files['tmp_name'][$index] ?? null,
            'error' => $files['error'][$index] ?? null,
            'size' => $files['size'][$index] ?? null,
        ];
    }
    return $uploads;
}

$categories = array_values(array_filter(all_categories(false), static fn(array $category): bool => (string) $category['slug'] !== 'festival'));
$brands = all_brands(false);
$attributeDefinitions = all_product_attributes(true);
$homepageFestival = configured_homepage_festival();
$errors = [];
$data = [
    'title' => '',
    'category_id' => (int) ($_SESSION['_quick_product_category'] ?? 0),
    'brand_id' => (int) ($_SESSION['_quick_product_brand'] ?? 0),
    'price' => '',
    'stock' => '0',
    'status' => 'active',
    'has_warranty' => 0,
    'warranty_months' => '',
    'authenticity_guarantee' => 0,
    'safe_shipping_guarantee' => 0,
    'festival_enabled' => 0,
    'festival_price' => null,
    'festival_free_shipping' => 0,
    'home_festival' => 0,
    'features' => [],
    'specifications' => [],
    'attribute_value_ids' => [],
];

if (is_post()) {
    verify_csrf();
    $title = trim(scalar_string($_POST['title'] ?? ''));
    $categoryId = max(0, scalar_int($_POST['category_id'] ?? 0));
    $brandId = max(0, scalar_int($_POST['brand_id'] ?? 0));
    $priceValid = false;
    $price = bounded_uint($_POST['price'] ?? '', MAX_MONEY_AMOUNT, $priceValid);
    $stockText = normalize_digits(trim(scalar_string($_POST['stock'] ?? '0')));
    $stockValid = $stockText !== '' && ctype_digit($stockText) && strlen($stockText) <= 7 && (int) $stockText <= 1000000;
    $stock = $stockValid ? (int) $stockText : 0;
    $status = scalar_string($_POST['status'] ?? 'active') === 'draft' ? 'draft' : 'active';
    $hasWarranty = isset($_POST['has_warranty']);
    $warrantyMonthsText = normalize_digits(trim(scalar_string($_POST['warranty_months'] ?? '')));
    $warrantyMonthsValid = !$hasWarranty || ($warrantyMonthsText !== '' && ctype_digit($warrantyMonthsText) && (int) $warrantyMonthsText >= 1 && (int) $warrantyMonthsText <= 120);
    $warrantyMonths = $warrantyMonthsValid && $hasWarranty ? (int) $warrantyMonthsText : 0;
    $warranty = $hasWarranty && $warrantyMonths > 0 ? $warrantyMonths . ' ماه گارانتی' : '';
    $authenticityGuarantee = isset($_POST['authenticity_guarantee']) ? 1 : 0;
    $safeShippingGuarantee = isset($_POST['safe_shipping_guarantee']) ? 1 : 0;
    $festivalEnabled = isset($_POST['festival_enabled']) ? 1 : 0;
    $festivalFreeShipping = $festivalEnabled && isset($_POST['festival_free_shipping']) ? 1 : 0;
    $festivalPriceInput = trim(scalar_string($_POST['festival_price'] ?? ''));
    $festivalPriceValid = true;
    $festivalPrice = null;
    if ($festivalEnabled && $festivalPriceInput !== '') {
        $festivalPrice = bounded_uint($festivalPriceInput, MAX_MONEY_AMOUNT, $festivalPriceValid);
    }
    if (!$festivalEnabled) {
        $festivalPrice = null;
        $festivalFreeShipping = 0;
    }
    $specifications = submitted_product_specifications($_POST['spec_name'] ?? [], $_POST['spec_value'] ?? []);
    $featureSpecificationMerge = merge_product_feature_lines_into_specifications($_POST['features'] ?? '', $specifications);
    $features = $featureSpecificationMerge['features'];
    $specifications = $featureSpecificationMerge['specifications'];
    $selectedAttributeValueIds = normalize_product_attribute_value_ids($_POST['attribute_value_ids'] ?? []);
    $addToHomeFestival = isset($_POST['home_festival']);
    $data = [
        'title' => $title,
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'price' => $price,
        'stock' => $stock,
        'status' => $status,
        'has_warranty' => $hasWarranty ? 1 : 0,
        'warranty_months' => $warrantyMonthsText,
        'authenticity_guarantee' => $authenticityGuarantee,
        'safe_shipping_guarantee' => $safeShippingGuarantee,
        'festival_enabled' => $festivalEnabled,
        'festival_price' => $festivalPrice,
        'festival_free_shipping' => $festivalFreeShipping,
        'home_festival' => $addToHomeFestival ? 1 : 0,
        'features' => $features,
        'specifications' => $specifications,
        'attribute_value_ids' => $selectedAttributeValueIds,
    ];

    if (text_length($title) < 3 || text_length($title) > 255) {
        $errors[] = 'نام محصول باید بین ۳ تا ۲۵۵ کاراکتر باشد.';
    }
    if (!$categoryId || !$brandId) {
        $errors[] = 'دسته‌بندی و برند را انتخاب کنید.';
    }
    if (!$priceValid || ($status === 'active' && $price < 1)) {
        $errors[] = 'قیمت فروش محصول فعال باید عددی بزرگ‌تر از صفر باشد.';
    }
    if (!$stockValid) {
        $errors[] = 'موجودی باید عددی بین صفر تا ۱٬۰۰۰٬۰۰۰ باشد.';
    }
    if (!$warrantyMonthsValid) {
        $errors[] = 'مدت گارانتی باید عددی بین ۱ تا ۱۲۰ ماه باشد.';
    }
    if (!$festivalPriceValid) {
        $errors[] = 'قیمت جشنواره باید عدد صحیح و در محدوده مجاز باشد.';
    }
    if ($festivalEnabled && $festivalPrice !== null && ($festivalPrice < 1 || $festivalPrice >= $price)) {
        $errors[] = 'قیمت جشنواره باید بزرگ‌تر از صفر و کمتر از قیمت اصلی باشد.';
    }
    if ($festivalEnabled && $festivalPrice === null && !$festivalFreeShipping) {
        $errors[] = 'برای محصول جشنواره‌ای، قیمت جشنواره یا ارسال رایگان را فعال کنید.';
    }
    foreach ($features as $feature) {
        if (text_length($feature) > 255) {
            $errors[] = 'هر ویژگی متنی حداکثر ۲۵۵ کاراکتر است.';
            break;
        }
    }
    foreach ($specifications as $specification) {
        if ($specification['spec_name'] === '' || $specification['spec_value'] === '') {
            $errors[] = 'برای هر ردیف مشخصات فنی، هم عنوان و هم مقدار را وارد کنید.';
            break;
        }
        if (text_length($specification['spec_name']) > 180 || text_length($specification['spec_value']) > 2000) {
            $errors[] = 'عنوان مشخصه حداکثر ۱۸۰ و مقدار آن حداکثر ۲۰۰۰ کاراکتر است.';
            break;
        }
    }
    if ($selectedAttributeValueIds) {
        $attributeRows = product_attribute_value_rows(db(), $selectedAttributeValueIds, true);
        if (count($attributeRows) !== count($selectedAttributeValueIds)) {
            $errors[] = 'یکی از مقادیر ویژگی انتخاب‌شده معتبر یا فعال نیست.';
        }
    }
    if ($addToHomeFestival && !$homepageFestival) {
        $errors[] = 'ابتدا از بخش جشنواره‌ها یک جشنواره قیمتِ گروهی را برای صفحهٔ اصلی انتخاب کنید.';
    }

    $uploadedFiles = quick_product_uploaded_images($_FILES['images'] ?? []);
    if (count($uploadedFiles) > 12) {
        $errors[] = 'در افزودن سریع محصول، حداکثر ۱۲ تصویر می‌توانید انتخاب کنید.';
    }

    if (!$errors) {
        $relation = db()->prepare('SELECT (SELECT is_active FROM categories WHERE id=?) category_active,(SELECT is_active FROM brands WHERE id=?) brand_active');
        $relation->execute([$categoryId, $brandId]);
        $selected = $relation->fetch();
        if (!$selected || $selected['category_active'] === null || $selected['brand_active'] === null) {
            $errors[] = 'دسته‌بندی یا برند انتخاب‌شده معتبر نیست.';
        } elseif ($status === 'active' && (!(int) $selected['category_active'] || !(int) $selected['brand_active'])) {
            $errors[] = 'برای انتشار محصول، دسته‌بندی و برند باید فعال باشند.';
        }
    }

    $imagePaths = [];
    if (!$errors && $uploadedFiles) {
        try {
            foreach ($uploadedFiles as $file) $imagePaths[] = save_product_upload($file);
        } catch (Throwable $exception) {
            foreach ($imagePaths as $uploadedPath) delete_managed_upload($uploadedPath);
            $imagePaths = [];
            $errors[] = $exception->getMessage();
        }
    }

    if (!$errors) {
        try {
            $result = quick_product_service_create([
                'title'=>$title,
                'category_id'=>$categoryId,
                'brand_id'=>$brandId,
                'price'=>$price,
                'stock'=>$stock,
                'status'=>$status,
                'warranty_months'=>$warrantyMonths,
                'authenticity_guarantee'=>$authenticityGuarantee,
                'safe_shipping_guarantee'=>$safeShippingGuarantee,
                'festival_enabled'=>$festivalEnabled,
                'festival_price'=>$festivalPrice,
                'festival_free_shipping'=>$festivalFreeShipping,
                'features'=>$features,
                'specifications'=>$specifications,
                'attribute_value_ids'=>$selectedAttributeValueIds,
                'home_festival_id'=>$addToHomeFestival && $homepageFestival ? (int)$homepageFestival['id'] : 0,
            ], $imagePaths);
            $productId = (int)$result['product_id'];
            $_SESSION['_quick_product_category'] = $categoryId;
            $_SESSION['_quick_product_brand'] = $brandId;
            $visibilityMessage = $status === 'active'
                ? ($stock > 0 ? 'محصول فعال و قابل نمایش ثبت شد.' : 'محصول فعال ثبت شد؛ موجودی صفر است و در ردیف‌های فقط موجود نمایش داده نمی‌شود.')
                : 'محصول به‌صورت پیش‌نویس ثبت شد و در فروشگاه نمایش داده نمی‌شود.';
            flash('success', $visibilityMessage . ' کد کالا: ' . $result['sku']);
            if (!$result['specifications_saved'] && $specifications) flash('error', 'محصول ثبت شد، اما ذخیره مشخصات فنی نیازمند تعمیر دیتابیس است.');
            if (!$result['gallery_saved'] && count($imagePaths) > 1) flash('error', 'محصول ثبت شد، اما ثبت کامل گالری تصاویر انجام نشد؛ فقط تصویر اصلی نگه داشته شد.');
            if (scalar_string($_POST['next'] ?? '') === 'edit') redirect(app_url('admin/product-edit.php?id=' . $productId));
            redirect(app_url('admin/product-quick-add.php'));
        } catch (Throwable $exception) {
            foreach ($imagePaths as $uploadedPath) delete_managed_upload($uploadedPath);
            log_error($exception);
            $errors[] = $exception->getMessage() !== '' ? $exception->getMessage() : 'ثبت محصول انجام نشد. دوباره تلاش کنید.';
        }
    }
}

$adminPage = 'quick-product';
$adminTitle = 'افزودن سریع محصول';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title">
    <div>
        <h1>افزودن سریع محصول</h1>
        <p class="muted">با یک فرم سبک و مرتب، محصول را همراه با چند تصویر، جشنواره، ضمانت و مشخصات فنی ثبت کنید.</p>
    </div>
    <div class="admin-actions">
        <a class="btn btn-secondary" href="<?=e(app_url('admin/product-edit.php'))?>">فرم کامل</a>
        <?php if (admin_can('manage_system')): ?><a class="btn btn-secondary" href="<?=e(app_url('admin/bale-product-bot.php'))?>">ربات بله</a><?php endif ?>
        <a class="btn btn-secondary" href="<?=e(app_url('admin/products.php'))?>">فهرست محصولات</a>
    </div>
</div>
<?php foreach ($errors as $error): ?>
    <div class="flash error"><?=e($error)?></div>
<?php endforeach ?>
<div class="quick-product-layout">
    <form method="post" enctype="multipart/form-data" class="card quick-product-form quick-product-form--beautified">
        <?=csrf_field()?>
        <section class="quick-product-hero">
            <div>
                <strong>ثبت سریع‌تر، ظاهر بهتر</strong>
                <p>SKU و نشانی محصول خودکار ساخته می‌شوند و اگر چند تصویر انتخاب کنید، تصویر اول به‌صورت خودکار تصویر اصلی خواهد شد.</p>
            </div>
            <div class="quick-product-hero__chips">
                <span>چند عکس</span>
                <span>گالری خودکار</span>
                <span>تصویر اول = اصلی</span>
                <span>آماده ترب؛ ۱۲۰۰×۱۲۰۰</span>
            </div>
        </section>

        <div class="form-grid quick-product-grid">
            <div class="field full quick-section-heading">
                <h2>اطلاعات اصلی محصول</h2>
                <p class="muted">نام، دسته، برند و قیمت را وارد کنید تا محصول سریع ثبت شود.</p>
            </div>

            <div class="field full"><label>نام محصول</label><input name="title" maxlength="255" value="<?=e($data['title'])?>" placeholder="مثلاً جاروبرقی فیلیپس مدل FC9170" required autofocus></div>
            <div class="field"><label>دسته‌بندی</label><select name="category_id" required><option value="">انتخاب کنید</option><?php foreach ($categories as $category): ?><option value="<?=$category['id']?>" <?=$data['category_id'] == $category['id'] ? 'selected' : ''?>><?=e($category['name'])?><?=$category['is_active'] ? '' : ' — غیرفعال'?></option><?php endforeach ?></select></div>
            <div class="field"><label>برند</label><select name="brand_id" required><option value="">انتخاب کنید</option><?php foreach ($brands as $brand): ?><option value="<?=$brand['id']?>" <?=$data['brand_id'] == $brand['id'] ? 'selected' : ''?>><?=e($brand['name'])?><?=$brand['is_active'] ? '' : ' — غیرفعال'?></option><?php endforeach ?></select></div>
            <div class="field"><label>قیمت اصلی (تومان)</label><input type="number" name="price" min="0" max="100000000000000" value="<?=e($data['price'])?>" required></div>
            <div class="field"><label>موجودی قابل فروش</label><input type="number" name="stock" min="0" max="1000000" value="<?=e($data['stock'])?>" required><small>موجودی مستقیماً روی محصول ذخیره می‌شود؛ انبار و تأمین‌کننده‌ای در کار نیست.</small></div>
            <div class="field"><label>وضعیت</label><select name="status"><option value="active" <?=$data['status'] === 'active' ? 'selected' : ''?>>فعال و قابل نمایش</option><option value="draft" <?=$data['status'] === 'draft' ? 'selected' : ''?>>پیش‌نویس</option></select></div>

            <div class="field full quick-media-card" data-multi-image-picker data-max-files="12" data-existing-files="0">
                <div class="quick-media-card__head">
                    <div>
                        <label>تصاویر محصول</label>
                        <small>چند تصویر انتخاب کنید؛ تصویر اول اصلی می‌شود و همه عکس‌ها بدون تغییر نسبت روی بوم سفید ۱۲۰۰×۱۲۰۰ قرار می‌گیرند.</small>
                    </div>
                    <span class="quick-media-limit" data-image-picker-count>۰ از ۱۲ تصویر ثبت شده</span>
                </div>
                <label class="quick-media-dropzone" for="quick-product-images">
                    <input id="quick-product-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple data-image-picker-input>
                    <span class="quick-media-dropzone__title">برای انتخاب چند عکس کلیک کنید</span>
                    <span class="quick-media-dropzone__meta">JPG، PNG یا WebP تا ۶ مگابایت؛ ضلع کوتاه فایل اولیه حداقل ۳۰۰ پیکسل — خروجی WebP آماده ترب.</span>
                </label>
                <div class="quick-media-preview" data-image-picker-preview hidden></div>
                <p class="quick-media-hint" data-image-picker-help>اگر چند عکس انتخاب کنید، همه آن‌ها در گالری محصول ثبت می‌شوند و عکس اول تصویر اصلی خواهد بود.</p>
            </div>

            <div class="field full quick-section-heading">
                <h2>جشنواره و مزایای خرید</h2>
                <p class="muted">امتیازهای خرید و وضعیت جشنواره را از همین‌جا فعال کنید.</p>
            </div>

            <div class="field full festival-product-box">
                <label class="quick-festival-check"><input type="checkbox" name="festival_enabled" value="1" data-product-festival-toggle <?=$data['festival_enabled'] ? 'checked' : ''?>><span><strong>محصول جشنواره‌ای</strong><small>محصول خودکار در دسته جشنواره نمایش داده می‌شود.</small></span></label>
                <div class="form-grid festival-product-fields" data-product-festival-fields <?=$data['festival_enabled'] ? '' : 'hidden'?>>
                    <div class="field"><label>قیمت جشنواره (تومان؛ اختیاری)</label><input type="number" name="festival_price" min="1" max="100000000000000" value="<?=e($data['festival_price'] ?? '')?>"><small>برای جشنواره فقط ارسال رایگان، خالی بگذارید.</small></div>
                    <div class="field"><label><input type="checkbox" name="festival_free_shipping" value="1" <?=$data['festival_free_shipping'] ? 'checked' : ''?>> ارسال رایگان جشنواره</label><small>می‌تواند بدون تخفیف قیمت فعال باشد.</small></div>
                </div>
            </div>

            <div class="field quick-guarantees-card"><label>ضمانت‌های محصول</label><label><input type="checkbox" name="authenticity_guarantee" value="1" <?=$data['authenticity_guarantee'] ? 'checked' : ''?>> تضمین اصالت کالا</label><label><input type="checkbox" name="safe_shipping_guarantee" value="1" <?=$data['safe_shipping_guarantee'] ? 'checked' : ''?>> گارانتی ارسال سلامت کالا</label><label class="quick-festival-check"><input type="checkbox" name="has_warranty" value="1" data-warranty-toggle <?=$data['has_warranty'] ? 'checked' : ''?>><span><strong>دارای گارانتی</strong><small>با فعال‌کردن این گزینه، مدت گارانتی را به ماه وارد کنید.</small></span></label><div data-warranty-fields <?=$data['has_warranty'] ? '' : 'hidden'?>><label>مدت گارانتی (ماه)</label><input type="number" name="warranty_months" min="1" max="120" inputmode="numeric" value="<?=e($data['warranty_months'])?>" placeholder="مثلاً ۱۸" <?=$data['has_warranty'] ? 'required' : ''?>><small>عددی بین ۱ تا ۱۲۰ ماه وارد کنید.</small></div><small>فقط گزینه‌های فعال در صفحه محصول و هنگام خرید نمایش داده می‌شوند.</small></div>

            <div class="field full quick-section-heading">
                <h2>جدول مشخصات فنی</h2>
                <p class="muted">ردیف‌های دلخواه مانند ظرفیت، توان مصرفی، جنس بدنه و امکانات را وارد کنید.</p>
            </div>

            <div class="field full product-attribute-selector"><div class="product-attribute-selector__head"><div><label>ویژگی‌های قابل انتخاب محصول</label><small>گزینه‌های موجود این کالا را تیک بزنید؛ مثل رنگ قرمز و مشکی. مشتری در مرحله تکمیل سفارش باید از هر ویژگی یک گزینه انتخاب کند؛ قیمت و موجودی از خود محصول محاسبه می‌شود.</small></div><a class="btn btn-secondary btn-sm" href="<?=e(app_url('admin/attributes.php'))?>">تعریف ویژگی</a></div><?php if($attributeDefinitions):?><div class="product-attribute-selector__groups"><?php foreach($attributeDefinitions as $attribute):?><fieldset class="product-attribute-selector__group"><legend><?=e($attribute['name'])?></legend><?php if($attribute['values']):?><div class="product-attribute-selector__values"><?php foreach($attribute['values'] as $attributeValue):?><label class="product-attribute-choice"><input type="checkbox" name="attribute_value_ids[]" value="<?=$attributeValue['id']?>" <?=in_array((int)$attributeValue['id'],array_map('intval',$data['attribute_value_ids']??[]),true)?'checked':''?>><?php if($attribute['display_type']==='color'&&$attributeValue['color_hex']):?><span class="product-attribute-choice__color" style="background:<?=e($attributeValue['color_hex'])?>"></span><?php endif?><span><?=e($attributeValue['value'])?></span></label><?php endforeach?></div><?php else:?><small class="muted">مقداری تعریف نشده است.</small><?php endif?></fieldset><?php endforeach?></div><?php else:?><div class="flash warning">ابتدا از بخش ویژگی‌های محصولات، ویژگی و مقدارهای آن را تعریف کنید.</div><?php endif?></div><div class="field full feature-specification-bridge" data-feature-specification-bridge><label>ویژگی‌های متنی؛ هر مورد در یک خط</label><textarea name="features" rows="6" maxlength="12800" data-feature-specification-source placeholder="موتور کم‌صدا&#10;تعداد المنت: دارای المنت بالا و المنت پایین"><?=e(implode("\n",$data['features']??[]))?></textarea><div class="feature-specification-bridge__tools"><small>خط‌های بدون «:» به‌عنوان ویژگی متنی ذخیره می‌شوند. خط‌های «عنوان: مقدار» خودکار وارد جدول مشخصات فنی می‌شوند.</small><button class="btn btn-secondary btn-sm" type="button" data-convert-feature-specifications>انتقال موارد دارای «:»</button></div><div class="feature-specification-bridge__message" data-feature-specification-message hidden aria-live="polite"></div></div><div class="field full technical-specifications-editor"><div class="technical-specifications-editor__head"><div><label>مشخصات فنی محصول</label><small>می‌توانید تا ۶۰ ردیف مشخصه ثبت کنید.</small></div><button class="btn btn-secondary" type="button" data-add-specification>+ افزودن مشخصه</button></div><div data-specification-list><?php $specificationRows = $data['specifications'] ?: [['spec_name' => '', 'spec_value' => '']]; foreach ($specificationRows as $specification): ?><div class="technical-specification-row" data-specification-row><input name="spec_name[]" maxlength="180" value="<?=e($specification['spec_name'])?>" placeholder="عنوان مشخصه"><textarea name="spec_value[]" maxlength="2000" rows="2" placeholder="مقدار مشخصه"><?=e($specification['spec_value'])?></textarea><button class="btn btn-danger technical-specification-remove" type="button" data-remove-specification aria-label="حذف مشخصه">حذف</button></div><?php endforeach ?></div><template data-specification-template><div class="technical-specification-row" data-specification-row><input name="spec_name[]" maxlength="180" placeholder="عنوان مشخصه"><textarea name="spec_value[]" maxlength="2000" rows="2" placeholder="مقدار مشخصه"></textarea><button class="btn btn-danger technical-specification-remove" type="button" data-remove-specification aria-label="حذف مشخصه">حذف</button></div></template></div>

            <div class="field full"><?php if ($homepageFestival): ?><label class="quick-festival-check"><input type="checkbox" name="home_festival" value="1" <?=$data['home_festival'] ? 'checked' : ''?>><span><strong>نمایش در ردیف جشنواره صفحه اول «<?=e($homepageFestival['title'])?>»</strong><small>پس از فعال‌شدن زمان جشنواره، محصول در ردیف جشنواره صفحهٔ اصلی نمایش داده می‌شود.</small></span></label><?php else: ?><div class="flash warning">برای نمایش محصول در ردیف جشنواره صفحه اول، ابتدا در <a href="<?=e(app_url('admin/festivals.php'))?>">مدیریت جشنواره‌ها</a> یک جشنواره قیمتِ گروهی را «جشنواره اصلی صفحهٔ اول» کنید.</div><?php endif ?></div>
        </div>
        <div class="quick-product-actions">
            <button class="btn btn-primary" name="next" value="another">ذخیره و افزودن محصول بعدی</button>
            <button class="btn btn-secondary" name="next" value="edit">ذخیره و تکمیل جزئیات</button>
        </div>
    </form>

    <aside class="card quick-product-help">
        <strong>کارهایی که خودکار انجام می‌شوند</strong>
        <ul>
            <li>ساخت SKU یکتا</li>
            <li>ساخت نشانی محصول</li>
            <li>یادآوری آخرین دسته و برند برای محصول بعدی</li>
            <li>ثبت چند تصویر در گالری محصول</li>
            <li>قرارگرفتن تصویر اول به‌عنوان تصویر اصلی</li>
            <li>ذخیره موجودی روی محصول</li>
            <li>افزودن خودکار محصول جشنواره‌ای به دسته جشنواره</li>
            <li>پشتیبانی از تخفیف قیمت یا فقط ارسال رایگان</li>
            <li>افزودن یک‌کلیکی به جشنواره صفحهٔ اول</li>
            <li>تبدیل خودکار «عنوان: مقدار» به جدول مشخصات فنی</li><li>ثبت جدول مشخصات فنی دلخواه</li>
            <li>ثبت ضمانت‌ها و مدت گارانتی به ماه</li>
            <li>فعال‌سازی خروجی ترب</li>
        </ul>
    </aside>
</div>
<script nonce="<?=e(CSP_NONCE)?>">(()=>{const toggle=document.querySelector('[data-product-festival-toggle]'),fields=document.querySelector('[data-product-festival-fields]');if(toggle&&fields){const sync=()=>{fields.hidden=!toggle.checked;};toggle.addEventListener('change',sync);sync();}const warrantyToggle=document.querySelector('[data-warranty-toggle]'),warrantyFields=document.querySelector('[data-warranty-fields]'),warrantyMonths=warrantyFields?.querySelector('input[name="warranty_months"]');if(warrantyToggle&&warrantyFields&&warrantyMonths){const syncWarranty=(focus=false)=>{const active=warrantyToggle.checked;warrantyFields.hidden=!active;warrantyMonths.disabled=!active;warrantyMonths.required=active;if(active&&focus)warrantyMonths.focus();};warrantyToggle.addEventListener('change',()=>syncWarranty(true));syncWarranty();}const list=document.querySelector('[data-specification-list]'),template=document.querySelector('[data-specification-template]'),addButton=document.querySelector('[data-add-specification]');if(list&&template&&addButton){const syncRemoveButtons=()=>{const rows=[...list.querySelectorAll('[data-specification-row]')];rows.forEach(row=>{const button=row.querySelector('[data-remove-specification]');if(button)button.disabled=rows.length===1;});};list.addEventListener('click',event=>{const target=event.target;if(!(target instanceof Element))return;const button=target.closest('[data-remove-specification]');if(!button)return;const row=button.closest('[data-specification-row]');if(row&&list.querySelectorAll('[data-specification-row]').length>1)row.remove();syncRemoveButtons();});addButton.addEventListener('click',()=>{if(list.querySelectorAll('[data-specification-row]').length>=60)return;list.append(template.content.cloneNode(true));const rows=list.querySelectorAll('[data-specification-row]');const input=rows[rows.length-1]?.querySelector('input');if(input)input.focus();syncRemoveButtons();});syncRemoveButtons();}})();</script>
<?php require __DIR__ . '/partials/footer.php';
