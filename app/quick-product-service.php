<?php
declare(strict_types=1);

/**
 * سرویس مشترک ثبت محصول سریع؛ فرم پنل و ربات بله هر دو از همین مسیر استفاده می‌کنند.
 */
function quick_product_service_slug(string $title): string
{
    $base = text_substr(slugify($title), 0, 165);
    if ($base === '') $base = 'product-' . date('Ymd-His');
    $candidate = $base;
    $check = db()->prepare('SELECT 1 FROM products WHERE slug=? LIMIT 1');
    for ($suffix = 2; $suffix <= 99; $suffix++) {
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) return $candidate;
        $candidate = text_substr($base, 0, max(20, 172 - strlen((string)$suffix))) . '-' . $suffix;
    }
    return text_substr($base, 0, 160) . '-' . bin2hex(random_bytes(4));
}

function quick_product_service_sku(): string
{
    $check = db()->prepare('SELECT 1 FROM products WHERE sku=? LIMIT 1');
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $sku = 'MS-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $check->execute([$sku]);
        if (!$check->fetchColumn()) return $sku;
    }
    throw new RuntimeException('ساخت کد یکتای محصول انجام نشد؛ دوباره تلاش کنید.');
}

/** @return array<int,array{spec_name:string,spec_value:string,sort_order:int}> */
function quick_product_service_specifications(mixed $value): array
{
    if (!is_array($value)) return [];
    $rows = [];
    foreach (array_slice(array_values($value), 0, 60) as $row) {
        if (!is_array($row)) continue;
        $name = trim(scalar_string($row['spec_name'] ?? ''));
        $specValue = trim(scalar_string($row['spec_value'] ?? ''));
        if ($name === '' && $specValue === '') continue;
        if ($name === '' || $specValue === '') throw new RuntimeException('برای هر مشخصه فنی، عنوان و مقدار را کامل وارد کنید.');
        if (text_length($name) > 180 || text_length($specValue) > 2000) throw new RuntimeException('طول عنوان یا مقدار مشخصه فنی بیش از حد مجاز است.');
        $rows[] = ['spec_name'=>$name, 'spec_value'=>$specValue, 'sort_order'=>count($rows)];
    }
    return $rows;
}

/**
 * @param array<string,mixed> $input
 * @param array<int,string> $imagePaths مسیرهای مدیریت‌شده‌ای که قبلاً ذخیره شده‌اند.
 * @return array{product_id:int,sku:string,slug:string,status:string,stock:int,specifications_saved:bool,gallery_saved:bool}
 */
function quick_product_service_create(array $input, array $imagePaths = []): array
{
    $title = trim(scalar_string($input['title'] ?? ''));
    $description = trim(scalar_string($input['description'] ?? ''));
    $categoryId = max(0, scalar_int($input['category_id'] ?? 0));
    $brandId = max(0, scalar_int($input['brand_id'] ?? 0));
    $price = max(0, (int)($input['price'] ?? 0));
    $stock = max(0, min(1000000, (int)($input['stock'] ?? 0)));
    $status = scalar_string($input['status'] ?? 'draft') === 'active' ? 'active' : 'draft';
    $warrantyMonths = max(0, min(120, (int)($input['warranty_months'] ?? 0)));
    $warranty = $warrantyMonths > 0 ? $warrantyMonths . ' ماه گارانتی' : trim(scalar_string($input['warranty'] ?? ''));
    $authenticityGuarantee = !empty($input['authenticity_guarantee']) ? 1 : 0;
    $safeShippingGuarantee = !empty($input['safe_shipping_guarantee']) ? 1 : 0;
    $festivalEnabled = !empty($input['festival_enabled']) ? 1 : 0;
    $festivalFreeShipping = $festivalEnabled && !empty($input['festival_free_shipping']) ? 1 : 0;
    $festivalPrice = isset($input['festival_price']) && $input['festival_price'] !== '' && $input['festival_price'] !== null ? max(0, (int)$input['festival_price']) : null;
    if (!$festivalEnabled) { $festivalPrice = null; $festivalFreeShipping = 0; }
    $features = array_slice(product_feature_lines($input['features'] ?? []), 0, 50);
    $specifications = quick_product_service_specifications($input['specifications'] ?? []);
    $selectedAttributeValueIds = normalize_product_attribute_value_ids($input['attribute_value_ids'] ?? []);
    $homeFestivalId = max(0, (int)($input['home_festival_id'] ?? 0));

    if (text_length($title) < 3 || text_length($title) > 255) throw new RuntimeException('نام محصول باید بین ۳ تا ۲۵۵ کاراکتر باشد.');
    if (text_length($description) > 30000) throw new RuntimeException('توضیحات محصول بیش از حد طولانی است.');
    if ($categoryId < 1 || $brandId < 1) throw new RuntimeException('دسته‌بندی و برند محصول مشخص نشده است.');
    if ($price > MAX_MONEY_AMOUNT || ($status === 'active' && $price < 1)) throw new RuntimeException('قیمت فروش محصول فعال باید بزرگ‌تر از صفر باشد.');
    foreach ($features as $feature) if (text_length($feature) > 255) throw new RuntimeException('هر ویژگی متنی حداکثر ۲۵۵ کاراکتر است.');
    if ($festivalEnabled && $festivalPrice !== null && ($festivalPrice < 1 || $festivalPrice >= $price)) throw new RuntimeException('قیمت جشنواره باید بزرگ‌تر از صفر و کمتر از قیمت اصلی باشد.');
    if ($festivalEnabled && $festivalPrice === null && !$festivalFreeShipping) throw new RuntimeException('برای محصول جشنواره‌ای، قیمت جشنواره یا ارسال رایگان را فعال کنید.');
    $imagePaths = array_values(array_unique(array_filter(array_map('strval', array_slice($imagePaths, 0, 12)), static fn(string $path): bool => preg_match('#^uploads/products/\d{8}-[a-f0-9]{16}\.(?:jpe?g|png|webp)$#i', $path) === 1)));

    $pdo = db();
    $relation = $pdo->prepare('SELECT (SELECT is_active FROM categories WHERE id=?) category_active,(SELECT is_active FROM brands WHERE id=?) brand_active');
    $relation->execute([$categoryId, $brandId]);
    $selected = $relation->fetch();
    if (!$selected || $selected['category_active'] === null || $selected['brand_active'] === null) throw new RuntimeException('دسته‌بندی یا برند انتخاب‌شده معتبر نیست.');
    if ($status === 'active' && (!(int)$selected['category_active'] || !(int)$selected['brand_active'])) throw new RuntimeException('برای انتشار محصول، دسته‌بندی و برند باید فعال باشند.');
    if ($selectedAttributeValueIds) {
        $attributeRows = product_attribute_value_rows($pdo, $selectedAttributeValueIds, true);
        if (count($attributeRows) !== count($selectedAttributeValueIds)) throw new RuntimeException('یکی از مقادیر ویژگی انتخاب‌شده معتبر یا فعال نیست.');
    }

    $slug = quick_product_service_slug($title);
    $sku = quick_product_service_sku();
    $productId = 0;
    $specificationsSaved = true;
    $gallerySaved = true;
    try {
        $pdo->beginTransaction();
        $primaryImagePath = $imagePaths[0] ?? '';
        $stmt = $pdo->prepare("INSERT INTO products(category_id,brand_id,slug,sku,title,short_title,description,price,stock,image_path,product_type,status,torob_enabled,warranty,authenticity_guarantee,safe_shipping_guarantee,festival_enabled,festival_price,festival_free_shipping) VALUES(?,?,?,?,?,?,?,?,?,?,'simple',?,1,?,?,?,?,?,?)");
        $stmt->execute([$categoryId,$brandId,$slug,$sku,$title,text_substr($title,0,180),$description !== '' ? $description : null,$price,$stock,$primaryImagePath ?: null,$status,$warranty ?: null,$authenticityGuarantee,$safeShippingGuarantee,$festivalEnabled,$festivalPrice,$festivalFreeShipping]);
        $productId = (int)$pdo->lastInsertId();
        if ($features) {
            $featureInsert = $pdo->prepare('INSERT INTO product_features(product_id,feature_text,sort_order) VALUES(?,?,?)');
            foreach ($features as $index => $feature) $featureInsert->execute([$productId,$feature,$index]);
        }
        replace_product_attribute_selections($pdo, $productId, $selectedAttributeValueIds);
        $specificationsSaved = replace_product_specifications($pdo, $productId, $specifications);
        if ($homeFestivalId > 0) {
            try { $pdo->prepare('INSERT INTO festival_products(festival_id,product_id) VALUES(?,?)')->execute([$homeFestivalId,$productId]); }
            catch (PDOException $optionalError) { log_error($optionalError); }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    if ($imagePaths) {
        try {
            $pdo->beginTransaction();
            $insertGallery = $pdo->prepare('INSERT INTO product_gallery(product_id,image_path,alt_text,sort_order,is_primary) VALUES(?,?,?,?,?)');
            foreach ($imagePaths as $index => $path) $insertGallery->execute([$productId,$path,$title,$index,$index === 0 ? 1 : 0]);
            product_gallery_sync_primary($pdo, $productId);
            $pdo->commit();
        } catch (Throwable $optionalError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $gallerySaved = false;
            log_error($optionalError);
            foreach (array_slice($imagePaths, 1) as $extraPath) delete_managed_upload($extraPath);
        }
    }

    return [
        'product_id'=>$productId,
        'sku'=>$sku,
        'slug'=>$slug,
        'status'=>$status,
        'stock'=>$stock,
        'specifications_saved'=>$specificationsSaved,
        'gallery_saved'=>$gallerySaved,
    ];
}
