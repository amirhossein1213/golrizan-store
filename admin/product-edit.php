<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

function product_edit_normalize_uploads(array $files): array
{
    $names = $files['name'] ?? [];
    if (!is_array($names)) return [];
    $uploads = [];
    foreach ($names as $index => $name) {
        if (trim((string)$name) === '') continue;
        $uploads[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? null,
            'tmp_name' => $files['tmp_name'][$index] ?? null,
            'error' => $files['error'][$index] ?? null,
            'size' => $files['size'][$index] ?? null,
        ];
    }
    return $uploads;
}

$id = max(0, scalar_int($_GET['id'] ?? 0));
$product = $id ? find_product_by_id($id, true) : null;
if ($id && !$product && !is_post()) {
    http_response_code(404);
    exit('محصول پیدا نشد.');
}

$defaults = [
    'id'=>0,'category_id'=>'','brand_id'=>'','slug'=>'','sku'=>'','title'=>'','short_title'=>'',
    'description'=>'','price'=>'','cost_price'=>'','old_price'=>'','stock'=>0,'weight_grams'=>0,'shipping_extra'=>0,'rating'=>0,'reviews_count'=>0,
    'image_path'=>'','badge'=>'','energy_label'=>'','color'=>'','warranty'=>'','authenticity_guarantee'=>0,'safe_shipping_guarantee'=>0,'festival_enabled'=>0,'festival_price'=>null,'festival_free_shipping'=>0,'status'=>'active',
    'featured'=>0,'torob_enabled'=>1,'snappay_enabled'=>0,'sort_order'=>0,'meta_title'=>'',
    'meta_description'=>'','product_type'=>'simple','features'=>[],'specifications'=>[],
];
$data = $product ?: $defaults;
$liveTorobpayCampaign = $id ? torobpay_special_live_campaign_for_product($id) : null;
$data['specifications'] = $id ? product_specifications($id) : [];
$attributeDefinitions = all_product_attributes(true);
$selectedAttributeValueIds = $id ? product_selected_attribute_value_ids($id) : [];
if ($id && !$selectedAttributeValueIds) $selectedAttributeValueIds = product_used_attribute_value_ids($id);
$homepageFestival = configured_homepage_festival();
$homeFestivalMember = false;
if ($id && $homepageFestival) {
    $membership = db()->prepare('SELECT 1 FROM festival_products WHERE festival_id=? AND product_id=?');
    $membership->execute([(int)$homepageFestival['id'],$id]);
    $homeFestivalMember = (bool)$membership->fetchColumn();
}
$data['home_festival'] = $homeFestivalMember ? 1 : 0;
$errors = [];
$newUploads = [];

if (is_post()) {
    verify_csrf();
    $id = max(0, scalar_int($_POST['id'] ?? 0));
    $currentProduct = $id ? find_product_by_id($id, true) : null;
    $liveTorobpayCampaign = $id ? torobpay_special_live_campaign_for_product($id) : null;
    if ($id && !$currentProduct) $errors[] = 'محصول پیدا نشد.';

    $title = trim(scalar_string($_POST['title'] ?? ''));
    $shortTitle = trim(scalar_string($_POST['short_title'] ?? '')) ?: $title;
    $slug = slugify(trim(scalar_string($_POST['slug'] ?? '')) ?: $title);
    $sku = trim(scalar_string($_POST['sku'] ?? ''));
    $categoryId = max(0, scalar_int($_POST['category_id'] ?? 0));
    $brandId = max(0, scalar_int($_POST['brand_id'] ?? 0));
    $description = trim(scalar_string($_POST['description'] ?? ''));
    $productType = in_array(scalar_string($_POST['product_type'] ?? 'simple'), ['simple','variable'], true) ? scalar_string($_POST['product_type']) : 'simple';
    $priceValid = false;
    $price = bounded_uint($_POST['price'] ?? '', MAX_MONEY_AMOUNT, $priceValid);
    $costPrice=(int)($currentProduct['cost_price']??0);
    $costPriceValid=true;
    $oldPriceInput = trim(scalar_string($_POST['old_price'] ?? ''));
    $oldPriceValid = true;
    $oldPrice = null;
    if ($oldPriceInput !== '') {
        $oldPrice = bounded_uint($oldPriceInput, MAX_MONEY_AMOUNT, $oldPriceValid);
    }
    if ($oldPrice !== null && $oldPrice <= $price) $oldPrice = null;
    $stock = max(0, min(1000000, scalar_int($_POST['stock'] ?? 0)));
    $weightGrams=max(0,min(10000000,scalar_int($_POST['weight_grams']??0)));
    $shippingExtraValid=false;$shippingExtra=bounded_uint($_POST['shipping_extra']??'0',MAX_MONEY_AMOUNT,$shippingExtraValid);
    $rating = (float)($currentProduct['rating']??0);
    $reviewsCount = (int)($currentProduct['reviews_count']??0);
    $badge = trim(scalar_string($_POST['badge'] ?? ''));
    $energyLabel = trim(scalar_string($_POST['energy_label'] ?? ''));
    $color = trim(scalar_string($_POST['color'] ?? ''));
    $hasWarranty=isset($_POST['has_warranty']);$warrantyMonths=max(0,min(120,scalar_int($_POST['warranty_months']??0)));$warranty=$hasWarranty?$warrantyMonths.' ماه گارانتی':'';$authenticityGuarantee=isset($_POST['authenticity_guarantee'])?1:0;$safeShippingGuarantee=isset($_POST['safe_shipping_guarantee'])?1:0;
    $festivalEnabled=isset($_POST['festival_enabled'])?1:0;$festivalFreeShipping=$festivalEnabled&&isset($_POST['festival_free_shipping'])?1:0;$festivalPriceInput=trim(scalar_string($_POST['festival_price']??''));$festivalPriceValid=true;$festivalPrice=null;if($festivalEnabled&&$festivalPriceInput!=='')$festivalPrice=bounded_uint($festivalPriceInput,MAX_MONEY_AMOUNT,$festivalPriceValid);if(!$festivalEnabled){$festivalPrice=null;$festivalFreeShipping=0;}
    $statusInput = scalar_string($_POST['status'] ?? ($id ? (string)($currentProduct['status'] ?? 'draft') : 'active'), 'active');
    $status = in_array($statusInput, ['active','draft'], true) ? $statusInput : 'draft';
    $sortOrder = max(-1000000, min(1000000, scalar_int($_POST['sort_order'] ?? 0)));
    $metaTitle = trim(scalar_string($_POST['meta_title'] ?? ''));
    $metaDescription = trim(scalar_string($_POST['meta_description'] ?? ''));
    $specifications = submitted_product_specifications($_POST['spec_name'] ?? [], $_POST['spec_value'] ?? []);
    $featureSpecificationMerge = merge_product_feature_lines_into_specifications($_POST['features'] ?? '', $specifications);
    $features = $featureSpecificationMerge['features'];
    $specifications = $featureSpecificationMerge['specifications'];
    $selectedAttributeValueIds = normalize_product_attribute_value_ids($_POST['attribute_value_ids'] ?? []);
    $addToHomeFestival = isset($_POST['home_festival']);

    $data = [
        'id'=>$id,'category_id'=>$categoryId,'brand_id'=>$brandId,'slug'=>$slug,'sku'=>$sku,
        'title'=>$title,'short_title'=>$shortTitle,'description'=>$description,'price'=>$price,'cost_price'=>$costPrice,
        'old_price'=>$oldPrice,'stock'=>$stock,'weight_grams'=>$weightGrams,'shipping_extra'=>$shippingExtra,'rating'=>$rating,'reviews_count'=>$reviewsCount,
        'image_path'=>(string)($currentProduct['image_path'] ?? ''),'badge'=>$badge,
        'energy_label'=>$energyLabel,'color'=>$color,'warranty'=>$warranty,'authenticity_guarantee'=>$authenticityGuarantee,'safe_shipping_guarantee'=>$safeShippingGuarantee,'festival_enabled'=>$festivalEnabled,'festival_price'=>$festivalPrice,'festival_free_shipping'=>$festivalFreeShipping,'status'=>$status,
        'featured'=>isset($_POST['featured'])?1:0,'torob_enabled'=>isset($_POST['torob_enabled'])?1:0,
        'snappay_enabled'=>isset($_POST['snappay_enabled'])?1:0,'sort_order'=>$sortOrder,
        'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,'product_type'=>$productType,'features'=>$features,'specifications'=>$specifications,'home_festival'=>$addToHomeFestival?1:0,
    ];

    if (text_length($title) < 3 || text_length($title) > 255) $errors[] = 'عنوان محصول باید بین ۳ تا ۲۵۵ کاراکتر باشد.';
    if (text_length($shortTitle) > 180) $errors[] = 'عنوان کوتاه حداکثر ۱۸۰ کاراکتر است.';
    if (!preg_match('/^[a-z0-9-]{2,180}$/', $slug)) $errors[] = 'نشانی محصول معتبر نیست.';
    if (!preg_match('/^[A-Za-z0-9._-]{2,100}$/', $sku)) $errors[] = 'کد کالا باید ۲ تا ۱۰۰ کاراکتر انگلیسی معتبر باشد.';
    if (!$categoryId || !$brandId) $errors[] = 'دسته‌بندی و برند الزامی هستند.';
    if (!$priceValid) $errors[] = 'قیمت فروش باید عدد صحیح، نامنفی و در محدوده مجاز باشد.';
    if (!$shippingExtraValid) $errors[]='هزینه حمل ویژه کالا معتبر نیست.';
    if (!$oldPriceValid) $errors[] = 'قیمت قبلی باید عدد صحیح، نامنفی و در محدوده مجاز باشد.';
    if (!$festivalPriceValid) $errors[] = 'قیمت جشنواره باید عدد صحیح و در محدوده مجاز باشد.';
    if ($festivalEnabled && $festivalPrice !== null && ($festivalPrice < 1 || $festivalPrice >= $price)) $errors[] = 'قیمت جشنواره باید بزرگ‌تر از صفر و کمتر از قیمت اصلی باشد.';
    if ($festivalEnabled && $festivalPrice === null && !$festivalFreeShipping) $errors[] = 'برای محصول جشنواره‌ای، قیمت جشنواره یا ارسال رایگان را فعال کنید.';
    if ($liveTorobpayCampaign) {
        $campaignChannel = limited_discount_normalize_channel((string)($liveTorobpayCampaign['limited_discount_channel'] ?? 'torobpay'));
        $campaignOldPrice = (int)($liveTorobpayCampaign['torobpay_old_price'] ?? 0);
        if ($price !== $campaignOldPrice) $errors[] = 'تا زمانی که تخفیف محدود فعال یا زمان‌بندی‌شده است، قیمت اصلی محصول قابل تغییر نیست؛ ابتدا تخفیف را لغو کنید.';
        if ($festivalEnabled) $errors[] = 'این محصول تخفیف محدود فعال یا زمان‌بندی‌شده دارد و نمی‌تواند هم‌زمان جشنواره مستقیم داشته باشد.';
        if ($status !== 'active' || $productType !== 'simple') $errors[] = 'محصول دارای تخفیف محدود باید ساده و فعال بماند؛ برای تغییر این موارد ابتدا تخفیف را لغو کنید.';
        if (limited_discount_requires_torobpay($campaignChannel) && empty($data['torob_enabled'])) $errors[] = 'کانال این تخفیف شامل ترب‌پی است؛ خروجی ترب محصول باید تا زمان لغو تخفیف فعال بماند.';
        if (limited_discount_requires_snappay($campaignChannel) && empty($data['snappay_enabled'])) $errors[] = 'کانال این تخفیف شامل اسنپ‌پی است؛ مجازبودن محصول برای اسنپ‌پی باید تا زمان لغو تخفیف فعال بماند.';
    }
    if($hasWarranty&&$warrantyMonths<1)$errors[]='مدت گارانتی باید بین ۱ تا ۱۲۰ ماه باشد.';
    if ($status === 'active' && $productType === 'simple' && $price <= 0) $errors[] = 'محصول ساده فعال باید قیمت فروش بزرگ‌تر از صفر داشته باشد.';
    if ($id && $currentProduct && (string)$currentProduct['product_type']==='variable' && $productType==='simple') {
        $variantCount=db()->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id=?');$variantCount->execute([$id]);
        if((int)$variantCount->fetchColumn()>0)$errors[]='برای تبدیل محصول متغیر به ساده، ابتدا همه متغیرهای بدون سابقه سفارش را حذف و متغیرهای دارای سابقه را غیرفعال کنید.';
    }
    if ($status === 'active' && $productType === 'variable' && $id) {
        $activeVariantCount=db()->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id=? AND status='active' AND price>0");$activeVariantCount->execute([$id]);
        if((int)$activeVariantCount->fetchColumn()<1)$errors[]='محصول متغیر فعال باید حداقل یک متغیر فعال و قیمت‌دار داشته باشد.';
    }
    if ($status === 'active' && $productType === 'variable' && !$id) $errors[]='ابتدا محصول متغیر را به‌صورت پیش‌نویس ذخیره کنید، متغیرها را بسازید و سپس آن را فعال کنید.';
    if (text_length($description) > 30000) $errors[] = 'توضیحات محصول بیش از حد طولانی است.';
    if (text_length($badge) > 100 || text_length($energyLabel) > 50 || text_length($color) > 80 || text_length($warranty) > 180) $errors[] = 'یکی از مشخصات کوتاه محصول بیش از حد طولانی است.';
    if (text_length($metaTitle) > 255 || text_length($metaDescription) > 320) $errors[] = 'اطلاعات سئو بیش از حد طولانی است.';
    foreach ($features as $feature) if (text_length($feature) > 255) { $errors[] = 'هر ویژگی محصول حداکثر ۲۵۵ کاراکتر است.'; break; }
    foreach ($specifications as $specification) {
        if ($specification['spec_name'] === '' || $specification['spec_value'] === '') { $errors[] = 'برای هر ردیف مشخصات فنی، هم عنوان و هم مقدار را وارد کنید.'; break; }
        if (text_length($specification['spec_name']) > 180 || text_length($specification['spec_value']) > 2000) { $errors[] = 'عنوان مشخصه حداکثر ۱۸۰ و مقدار آن حداکثر ۲۰۰۰ کاراکتر است.'; break; }
    }
    if ($selectedAttributeValueIds) {
        $attributeRows = product_attribute_value_rows(db(), $selectedAttributeValueIds, true);
        if (count($attributeRows) !== count($selectedAttributeValueIds)) $errors[] = 'یکی از مقادیر ویژگی انتخاب‌شده معتبر یا فعال نیست.';
    }
    if ($id) {
        $usedAttributeValueIds = product_used_attribute_value_ids($id);
        $removedUsedValues = array_values(array_diff($usedAttributeValueIds, $selectedAttributeValueIds));
        if ($removedUsedValues) $errors[] = 'یکی از ویژگی‌های تیک‌خورده در متغیرهای موجود استفاده می‌شود؛ ابتدا متغیر مربوط را حذف یا ویرایش کنید.';
    }
    if ($addToHomeFestival && !$homepageFestival) $errors[] = 'ابتدا از بخش جشنواره‌ها یک جشنواره قیمتِ گروهی را برای صفحهٔ اصلی انتخاب کنید.';

    if (!$errors) {
        $duplicateSku = db()->prepare('SELECT id,title FROM products WHERE sku=? AND id<>? LIMIT 1');
        $duplicateSku->execute([$sku, $id]);
        $skuOwner = $duplicateSku->fetch();
        if ($skuOwner) {
            $errors[] = 'کد کالا «' . $sku . '» قبلاً برای محصول «' . (string)$skuOwner['title'] . '» ثبت شده است.';
        }

        $duplicateSlug = db()->prepare('SELECT id,title FROM products WHERE slug=? AND id<>? LIMIT 1');
        $duplicateSlug->execute([$slug, $id]);
        $slugOwner = $duplicateSlug->fetch();
        if ($slugOwner) {
            $errors[] = 'نشانی محصول «' . $slug . '» قبلاً برای محصول «' . (string)$slugOwner['title'] . '» ثبت شده است.';
        }
    }

    if (!$errors) {
        $relationCheck = db()->prepare('SELECT (SELECT is_active FROM categories WHERE id=?) category_active, (SELECT is_active FROM brands WHERE id=?) brand_active');
        $relationCheck->execute([$categoryId, $brandId]);
        $relations = $relationCheck->fetch();
        if (!array_key_exists('category_active', (array)$relations) || $relations['category_active'] === null || !array_key_exists('brand_active', (array)$relations) || $relations['brand_active'] === null) {
            $errors[] = 'دسته‌بندی یا برند انتخاب‌شده معتبر نیست.';
        } elseif ($status === 'active' && (!(int)$relations['category_active'] || !(int)$relations['brand_active'])) {
            $errors[] = 'محصول فعال باید دسته‌بندی و برند فعال داشته باشد.';
        }
    }

    $selectedUploadFiles = product_edit_normalize_uploads($_FILES['images'] ?? []);
    // سازگاری با فرم‌های قدیمی که فقط یک فیلد image ارسال می‌کردند.
    if (!$selectedUploadFiles && !empty($_FILES['image']['name'])) {
        $selectedUploadFiles[] = $_FILES['image'];
    }
    $existingImageCount = ($id && $currentProduct)
        ? count(product_gallery_images($id, (string)($currentProduct['image_path'] ?? ''), (string)($currentProduct['title'] ?? ''), false))
        : 0;
    $remainingImageSlots = max(0, 12 - $existingImageCount);
    if (count($selectedUploadFiles) > $remainingImageSlots) {
        $errors[] = 'با توجه به تصاویر فعلی، فقط ' . fa_number($remainingImageSlots) . ' تصویر دیگر قابل افزودن است.';
    }

    if (!$errors && $selectedUploadFiles) {
        try {
            foreach ($selectedUploadFiles as $file) {
                $newUploads[] = save_product_upload($file);
            }
        } catch (Throwable $exception) {
            foreach ($newUploads as $uploadedPath) delete_managed_upload($uploadedPath);
            $newUploads = [];
            $errors[] = $exception->getMessage();
        }
    }

    if (!$errors) {
        $values = [
            $categoryId,$brandId,$slug,$sku,$title,$shortTitle,$description,$price,$costPrice,$oldPrice,$stock,$weightGrams,$shippingExtra,
            $rating,$reviewsCount,$data['image_path'],$badge,$energyLabel,$color,$warranty,$authenticityGuarantee,$safeShippingGuarantee,$festivalEnabled,$festivalPrice,$festivalFreeShipping,$status,
            $data['featured'],$data['torob_enabled'],$data['snappay_enabled'],$sortOrder,$metaTitle,$metaDescription,$productType,
        ];
        $editingExistingProduct = $id > 0;
        $makeFirstImagePrimary = !$editingExistingProduct || isset($_POST['make_first_primary']);
        $pdo = null;
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $oldInventoryValue = 0;
            $oldStockValue = 0;
            if ($id) {
                $lockProduct = $pdo->prepare('SELECT cost_price,stock FROM products WHERE id=? FOR UPDATE');
                $lockProduct->execute([$id]);
                $locked = $lockProduct->fetch();
                if (!$locked) throw new RuntimeException('محصول پیدا نشد.');
                $oldInventoryValue = ((string)($currentProduct['product_type']??'simple')==='simple') ? (int)$locked['cost_price'] * (int)$locked['stock'] : 0;
                $oldStockValue = (int)$locked['stock'];
            }
            $newInventoryValue=$productType==='simple' ? $costPrice*$stock : 0;
            if ($id) {
                $sql = 'UPDATE products SET category_id=?,brand_id=?,slug=?,sku=?,title=?,short_title=?,description=?,price=?,cost_price=?,old_price=?,stock=?,weight_grams=?,shipping_extra=?,rating=?,reviews_count=?,image_path=?,badge=?,energy_label=?,color=?,warranty=?,authenticity_guarantee=?,safe_shipping_guarantee=?,festival_enabled=?,festival_price=?,festival_free_shipping=?,status=?,featured=?,torob_enabled=?,snappay_enabled=?,sort_order=?,meta_title=?,meta_description=?,product_type=? WHERE id=?';
                $values[] = $id;
                $pdo->prepare($sql)->execute($values);
            } else {
                $sql = 'INSERT INTO products(category_id,brand_id,slug,sku,title,short_title,description,price,cost_price,old_price,stock,weight_grams,shipping_extra,rating,reviews_count,image_path,badge,energy_label,color,warranty,authenticity_guarantee,safe_shipping_guarantee,festival_enabled,festival_price,festival_free_shipping,status,featured,torob_enabled,snappay_enabled,sort_order,meta_title,meta_description,product_type) VALUES(' . implode(',', array_fill(0, 33, '?')) . ')';
                $pdo->prepare($sql)->execute($values);
                $id = (int)$pdo->lastInsertId();
            }
            if($productType==='simple'&&$stock!==$oldStockValue)inventory_record_movement($pdo,$id,null,$oldStockValue===0&&$stock>0?'opening':'adjustment',$stock-$oldStockValue,$stock,$costPrice,'product',$id,$oldStockValue===0?'موجودی اولیه محصول':'اصلاح از صفحه محصول',(int)admin_user()['id']);
            accounting_inventory_adjustment($pdo,$id,$oldInventoryValue,$newInventoryValue,(int)admin_user()['id']);
            try {
                $pdo->prepare('DELETE FROM product_features WHERE product_id=?')->execute([$id]);
                $featureInsert = $pdo->prepare('INSERT INTO product_features(product_id,feature_text,sort_order) VALUES(?,?,?)');
                foreach ($features as $index => $feature) $featureInsert->execute([$id, $feature, $index]);
            } catch (PDOException $optionalError) { log_error($optionalError); }
            replace_product_attribute_selections($pdo, $id, $selectedAttributeValueIds);
            $specificationsSaved = replace_product_specifications($pdo, $id, $specifications);
            if ($homepageFestival) {
                try {
                    $pdo->prepare('DELETE FROM festival_products WHERE festival_id=? AND product_id=?')->execute([(int)$homepageFestival['id'],$id]);
                    if ($addToHomeFestival) $pdo->prepare('INSERT INTO festival_products(festival_id,product_id) VALUES(?,?)')->execute([(int)$homepageFestival['id'],$id]);
                } catch (PDOException $optionalError) { log_error($optionalError); }
            }
            if ($newUploads) {
                product_gallery_ensure_primary_row($pdo, $id, (string)($currentProduct['image_path'] ?? ''), $title);
                $maxOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM product_gallery WHERE product_id=? FOR UPDATE');
                $maxOrderStmt->execute([$id]);
                $nextSortOrder = (int)$maxOrderStmt->fetchColumn() + 1;
                $insertGallery = $pdo->prepare('INSERT INTO product_gallery(product_id,image_path,alt_text,sort_order,is_primary) VALUES(?,?,?,?,0)');
                $firstUploadedImageId = 0;
                foreach ($newUploads as $offset => $uploadedPath) {
                    $insertGallery->execute([$id, $uploadedPath, $title, $nextSortOrder + $offset]);
                    if ($offset === 0) $firstUploadedImageId = (int)$pdo->lastInsertId();
                }
                if ($makeFirstImagePrimary && $firstUploadedImageId > 0) {
                    $pdo->prepare('UPDATE product_gallery SET is_primary=(id=?) WHERE product_id=?')->execute([$firstUploadedImageId, $id]);
                }
                product_gallery_sync_primary($pdo, $id);
            }
            product_alert_wake_for_product($pdo,$id);
            $pdo->commit();
            $visibilityMessage = $status === 'active'
                ? ($stock > 0 ? 'محصول فعال و قابل نمایش ذخیره شد.' : 'محصول فعال ذخیره شد؛ به‌دلیل موجودی صفر در ردیف‌های «فقط موجود» نمایش داده نمی‌شود.')
                : 'محصول به‌صورت پیش‌نویس ذخیره شد و تا زمان فعال‌سازی در فروشگاه نمایش داده نمی‌شود.';
            if ($newUploads) $visibilityMessage .= ' ' . fa_number(count($newUploads)) . ' تصویر به گالری اضافه شد.';
            flash('success', $visibilityMessage);
            if (!$specificationsSaved && $specifications) flash('error', 'محصول ذخیره شد، اما جدول مشخصات فنی نیاز به تعمیر دیتابیس دارد.');
            redirect(app_url('admin/product-edit.php?id=' . $id));
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            foreach ($newUploads as $uploadedPath) delete_managed_upload($uploadedPath);
            $newUploads = [];
            $databaseMessage = $exception->getMessage();
            if ($exception instanceof PDOException && (string)$exception->getCode() === '23000' && stripos($databaseMessage, 'sku') !== false) {
                $errors[] = 'این کد کالا قبلاً برای محصول دیگری ثبت شده است.';
            } elseif ($exception instanceof PDOException && (string)$exception->getCode() === '23000' && stripos($databaseMessage, 'slug') !== false) {
                $errors[] = 'این نشانی محصول قبلاً استفاده شده است.';
            } else {
                $errors[] = 'ذخیره محصول انجام نشد. جزئیات خطا در گزارش فنی ثبت شد؛ دوباره تلاش کنید.';
            }
            log_error($exception);
        }
    }
}

$categories = array_values(array_filter(all_categories(false), static fn(array $category): bool => (string)$category['slug'] !== 'festival'));
$brands = all_brands(false);
$adminPage = 'products';
$adminTitle = $id ? 'ویرایش محصول' : 'محصول جدید';
$hasWarranty=trim((string)($data['warranty']??''))!=='';
$warrantyMonths=0;if($hasWarranty&&preg_match('/(\d+)/u',normalize_digits((string)$data['warranty']),$warrantyMatch))$warrantyMonths=max(1,min(120,(int)$warrantyMatch[1]));
$currentGalleryImages = $id ? product_gallery_images($id, (string)($data['image_path'] ?? ''), (string)($data['title'] ?? ''), false) : [];
$currentGalleryCount = count($currentGalleryImages);
$remainingImageSlots = max(0, 12 - $currentGalleryCount);
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><h1><?=$id?'ویرایش محصول':'افزودن محصول'?></h1><div class="admin-actions"><?php if($id):?><a class="btn btn-primary" href="<?=e(app_url('admin/product-gallery.php?product_id='.$id))?>">گالری تصاویر</a><?php endif?><?php if($id && ($data['product_type']??'simple')==='variable'):?><a class="btn btn-primary" href="<?=e(app_url('admin/product-variants.php?product_id='.$id))?>">مدیریت متغیرها</a><?php endif?><a class="btn btn-secondary" href="<?=e(app_url('admin/products.php'))?>">بازگشت</a></div></div>
<?php if($liveTorobpayCampaign):?><div class="flash warning">این محصول یک تخفیف محدود فعال یا زمان‌بندی‌شده در کانال «<?=e(limited_discount_channel_label((string)($liveTorobpayCampaign['limited_discount_channel']??'torobpay')))?>» دارد. تغییر قیمت اصلی، جشنواره مستقیم و وضعیت محصول تا زمان <a href="<?=e(app_url('admin/limited-discounts.php#campaign-'.(int)$liveTorobpayCampaign['id']))?>">لغو تخفیف و بازگشت قیمت</a> قفل است.</div><?php endif?>
<?php foreach($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<form method="post" enctype="multipart/form-data"><div class="card"><div class="form-grid"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><div class="field full"><label>عنوان کامل</label><input name="title" maxlength="255" value="<?=e($data['title'])?>" required></div><div class="field"><label>عنوان کوتاه</label><input name="short_title" maxlength="180" value="<?=e($data['short_title'])?>"></div><div class="field"><label>نشانی انگلیسی (Slug)</label><input name="slug" dir="ltr" maxlength="180" pattern="[a-z0-9-]{2,180}" value="<?=e($data['slug'])?>"></div><div class="field"><label>کد کالا (SKU)</label><input name="sku" dir="ltr" maxlength="100" pattern="[A-Za-z0-9._-]{2,100}" value="<?=e($data['sku'])?>" required></div><div class="field"><label>دسته‌بندی</label><select name="category_id" required><option value="">انتخاب کنید</option><?php foreach($categories as $category):?><option value="<?=$category['id']?>" <?=$data['category_id']==$category['id']?'selected':''?>><?=e($category['name'])?></option><?php endforeach?></select></div><div class="field"><label>برند</label><select name="brand_id" required><option value="">انتخاب کنید</option><?php foreach($brands as $brand):?><option value="<?=$brand['id']?>" <?=$data['brand_id']===$brand['id']?'selected':''?>><?=e($brand['name'])?></option><?php endforeach?></select></div><div class="field"><label>نوع محصول</label><select name="product_type"><option value="simple" <?=($data['product_type']??'simple')==='simple'?'selected':''?>>ساده</option><option value="variable" <?=($data['product_type']??'simple')==='variable'?'selected':''?>>متغیر؛ رنگ/ظرفیت/...</option></select><small>ویژگی‌های محصول ساده هنگام تسویه انتخاب می‌شوند؛ در محصول متغیر، قیمت و موجودی از ترکیب‌ها محاسبه می‌شود.</small></div><div class="field"><label>قیمت اصلی (تومان)</label><input type="number" name="price" value="<?=e($data['price'])?>" min="0" max="100000000000000" required></div><div class="field full festival-product-box"><label class="quick-festival-check"><input type="checkbox" name="festival_enabled" value="1" data-product-festival-toggle <?=$data['festival_enabled']?'checked':''?>><span><strong>محصول جشنواره‌ای</strong><small>می‌تواند تخفیف قیمت، ارسال رایگان یا هر دو را داشته باشد و خودکار در دسته جشنواره نمایش داده می‌شود.</small></span></label><div class="form-grid festival-product-fields" data-product-festival-fields <?=$data['festival_enabled']?'':'hidden'?>><div class="field"><label>قیمت جشنواره (تومان؛ اختیاری)</label><input type="number" name="festival_price" min="1" max="100000000000000" value="<?=e($data['festival_price']??'')?>"><small>برای جشنواره فقط ارسال رایگان، این فیلد را خالی بگذارید.</small></div><div class="field"><label><input type="checkbox" name="festival_free_shipping" value="1" <?=$data['festival_free_shipping']?'checked':''?> > ارسال رایگان جشنواره</label><small>این گزینه مستقل از تخفیف قیمت است.</small></div></div></div><div class="field"><label>موجودی مستقیم محصول</label><input type="number" name="stock" value="<?=e($data['stock'])?>" min="0" max="1000000"><small>همین عدد موجودی قابل فروش محصول است؛ نیازی به ثبت انبار یا تأمین‌کننده نیست.</small></div><div class="field full quick-media-card" data-multi-image-picker data-max-files="<?=$remainingImageSlots?>" data-existing-files="<?=$currentGalleryCount?>">
    <div class="quick-media-card__head">
        <div><label for="product-edit-images">تصاویر محصول</label><small>می‌توانید چند تصویر را یک‌جا انتخاب کنید؛ هر عکس بدون برش یا کشیدگی روی بوم سفید ۱۲۰۰×۱۲۰۰ آماده ترب می‌شود.</small></div>
        <span class="quick-media-limit" data-image-picker-count><?=fa_number($currentGalleryCount)?> از ۱۲ تصویر ثبت شده</span>
    </div>
    <label class="quick-media-dropzone" for="product-edit-images">
        <input id="product-edit-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple data-image-picker-input <?=$remainingImageSlots < 1 ? 'disabled' : ''?>>
        <span class="quick-media-dropzone__title"><?=$remainingImageSlots > 0 ? 'برای انتخاب یا رهاکردن چند عکس کلیک کنید' : 'ظرفیت ۱۲ تصویر تکمیل شده است'?></span>
        <span class="quick-media-dropzone__meta">JPG، PNG یا WebP تا ۶ مگابایت؛ ضلع کوتاه فایل اولیه حداقل ۳۰۰ پیکسل — ظرفیت باقی‌مانده: <?=fa_number($remainingImageSlots)?> تصویر</span>
    </label>
    <div class="quick-media-preview" data-image-picker-preview hidden></div>
    <p class="quick-media-hint" data-image-picker-help>تصاویر انتخاب‌شده قبل از ذخیره اینجا نمایش داده می‌شوند؛ با فلش‌ها ترتیب را عوض و با حذف، فایل اشتباه را کنار بگذارید.</p>
    <?php if($remainingImageSlots > 0):?><label class="checkbox-row quick-media-primary-option"><input type="checkbox" name="make_first_primary" value="1" checked> اولین تصویر انتخاب‌شده، تصویر اصلی محصول شود</label><?php endif?>
    <?php if($currentGalleryImages):?><div class="quick-media-existing-grid" aria-label="تصاویر فعلی محصول"><?php foreach($currentGalleryImages as $currentImage):?><figure><img src="<?=e($currentImage['url'])?>" alt="<?=e($currentImage['alt_text'])?>"><figcaption><?=$currentImage['is_primary']?'<strong>تصویر اصلی</strong>':'تصویر گالری'?></figcaption></figure><?php endforeach?></div><?php endif?>
    <?php if($id):?><div class="quick-media-current"><span>تصاویر فعلی: <?=fa_number($currentGalleryCount)?></span><a class="btn btn-secondary btn-sm" href="<?=e(app_url('admin/product-gallery.php?product_id='.$id))?>">مدیریت، مرتب‌سازی و حذف تصاویر فعلی</a></div><?php endif?>
</div><div class="field"><label>ضمانت‌های محصول</label><label><input type="checkbox" name="authenticity_guarantee" <?=$data['authenticity_guarantee']?'checked':''?>> تضمین اصالت کالا</label><label><input type="checkbox" name="safe_shipping_guarantee" <?=$data['safe_shipping_guarantee']?'checked':''?>> گارانتی ارسال سلامت کالا</label><label class="quick-festival-check"><input type="checkbox" name="has_warranty" value="1" data-warranty-toggle <?=$hasWarranty?'checked':''?>><span><strong>دارای گارانتی</strong><small>با فعال‌کردن این گزینه، مدت گارانتی را به ماه وارد کنید.</small></span></label><div data-warranty-fields <?=$hasWarranty?'':'hidden'?>><label>مدت گارانتی (ماه)</label><input type="number" name="warranty_months" min="1" max="120" inputmode="numeric" value="<?=e($warrantyMonths?:'')?>" placeholder="مثلاً ۱۸" <?=$hasWarranty?'required':''?>><small>عددی بین ۱ تا ۱۲۰ ماه وارد کنید.</small></div></div><input type="hidden" name="color" value="<?=e($data['color'])?>"><div class="field"><label>رده انرژی</label><input name="energy_label" maxlength="50" value="<?=e($data['energy_label'])?>"></div><div class="field"><label>برچسب روی کارت</label><input name="badge" maxlength="100" value="<?=e($data['badge'])?>"></div><div class="field"><label>وضعیت</label><select name="status"><option value="draft" <?=$data['status']==='draft'?'selected':''?>>پیش‌نویس</option><option value="active" <?=$data['status']==='active'?'selected':''?>>فعال</option></select></div><div class="field"><label>ترتیب نمایش</label><input type="number" name="sort_order" value="<?=e($data['sort_order'])?>"></div><div class="field full"><?php if($homepageFestival):?><label class="quick-festival-check"><input type="checkbox" name="home_festival" value="1" <?=$data['home_festival']?'checked':''?>><span><strong>نمایش در ردیف جشنواره صفحه اول «<?=e($homepageFestival['title'])?>»</strong><small>عضویت محصول در ردیف جشنواره صفحهٔ اصلی با همین ذخیره به‌روز می‌شود.</small></span></label><?php else:?><div class="flash warning">برای نمایش محصول در ردیف جشنواره صفحه اول، در <a href="<?=e(app_url('admin/festivals.php'))?>">مدیریت جشنواره‌ها</a> یک جشنواره قیمتِ گروهی را برای صفحهٔ اصلی انتخاب کنید.</div><?php endif?></div><div class="field full"><small>امتیاز و تعداد دیدگاه به‌صورت خودکار از دیدگاه‌های تأییدشده محاسبه می‌شود.</small></div><div class="field full product-attribute-selector"><div class="product-attribute-selector__head"><div><label>ویژگی‌های قابل انتخاب محصول</label><small>مقدارهای موجود این کالا را تیک بزنید؛ مثلاً برای رنگ، «قرمز» و «مشکی». در محصول ساده مشتری هنگام تکمیل سفارش از هر گروه یک گزینه انتخاب می‌کند و قیمت/موجودی مشترک می‌ماند؛ در محصول متغیر ویژگی‌های تعیین‌شده توسط مدل دوباره پرسیده نمی‌شوند. برای تعریف گزینه جدید به بخش <a href="<?=e(app_url('admin/attributes.php'))?>">ویژگی‌های محصولات</a> بروید.</small></div></div><?php if($attributeDefinitions):?><div class="product-attribute-selector__groups"><?php foreach($attributeDefinitions as $attribute):?><fieldset class="product-attribute-selector__group"><legend><?=e($attribute['name'])?></legend><?php if($attribute['values']):?><div class="product-attribute-selector__values"><?php foreach($attribute['values'] as $attributeValue):?><label class="product-attribute-choice"><input type="checkbox" name="attribute_value_ids[]" value="<?=$attributeValue['id']?>" <?=in_array((int)$attributeValue['id'],$selectedAttributeValueIds,true)?'checked':''?>><?php if($attribute['display_type']==='color'&&$attributeValue['color_hex']):?><span class="product-attribute-choice__color" style="background:<?=e($attributeValue['color_hex'])?>"></span><?php endif?><span><?=e($attributeValue['value'])?></span></label><?php endforeach?></div><?php else:?><small class="muted">برای این ویژگی هنوز مقداری تعریف نشده است.</small><?php endif?></fieldset><?php endforeach?></div><?php else:?><div class="flash warning">هنوز ویژگی فعالی تعریف نشده است. از بخش «ویژگی‌های محصولات» ابتدا ویژگی و مقدارهای آن را بسازید.</div><?php endif?></div><div class="field full feature-specification-bridge" data-feature-specification-bridge><label>ویژگی‌های متنی؛ هر مورد در یک خط</label><textarea name="features" rows="6" maxlength="12800" data-feature-specification-source placeholder="موتور کم‌صدا&#10;تعداد المنت: دارای المنت بالا و المنت پایین"><?=e(implode("\n",$data['features']))?></textarea><div class="feature-specification-bridge__tools"><small>خط‌های معمولی به‌عنوان ویژگی متنی باقی می‌مانند. هر خط با الگوی «عنوان: مقدار» به‌طور خودکار به جدول مشخصات فنی منتقل می‌شود.</small><button class="btn btn-secondary btn-sm" type="button" data-convert-feature-specifications>انتقال موارد دارای «:»</button></div><div class="feature-specification-bridge__message" data-feature-specification-message hidden aria-live="polite"></div></div><div class="field full technical-specifications-editor"><div class="technical-specifications-editor__head"><div><label>جدول مشخصات فنی</label><small>برای نمونه: «ظرفیت به لیتر» و «۶ لیتر». ترتیب ردیف‌ها در صفحه محصول حفظ می‌شود.</small></div><button class="btn btn-secondary" type="button" data-add-specification>+ افزودن مشخصه</button></div><div data-specification-list><?php $specificationRows=$data['specifications']?:[['spec_name'=>'','spec_value'=>'']];foreach($specificationRows as $specification):?><div class="technical-specification-row" data-specification-row><input name="spec_name[]" maxlength="180" value="<?=e($specification['spec_name'])?>" placeholder="عنوان مشخصه؛ مثلاً توان مصرفی"><textarea name="spec_value[]" maxlength="2000" rows="2" placeholder="مقدار مشخصه؛ مثلاً ۱۲۰۰ وات"><?=e($specification['spec_value'])?></textarea><button class="btn btn-danger technical-specification-remove" type="button" data-remove-specification aria-label="حذف مشخصه">حذف</button></div><?php endforeach?></div><template data-specification-template><div class="technical-specification-row" data-specification-row><input name="spec_name[]" maxlength="180" placeholder="عنوان مشخصه؛ مثلاً توان مصرفی"><textarea name="spec_value[]" maxlength="2000" rows="2" placeholder="مقدار مشخصه؛ مثلاً ۱۲۰۰ وات"></textarea><button class="btn btn-danger technical-specification-remove" type="button" data-remove-specification aria-label="حذف مشخصه">حذف</button></div></template></div><div class="field full"><label>توضیحات</label><textarea name="description" rows="7" maxlength="30000"><?=e($data['description'])?></textarea></div><div class="field"><label>عنوان سئو</label><input name="meta_title" maxlength="255" value="<?=e($data['meta_title'])?>"></div><div class="field"><label>توضیحات سئو</label><input name="meta_description" maxlength="320" value="<?=e($data['meta_description'])?>"></div><div class="field full checkboxes"><label><input type="checkbox" name="featured" <?=$data['featured']?'checked':''?>> محصول منتخب</label><label><input type="checkbox" name="torob_enabled" <?=$data['torob_enabled']?'checked':''?>> نمایش در خروجی ترب</label><label><input type="checkbox" name="snappay_enabled" <?=$data['snappay_enabled']?'checked':''?>> مجاز برای اسنپ‌پی</label></div></div></div><button class="btn btn-primary">ذخیره محصول</button></form>
<script nonce="<?=e(CSP_NONCE)?>">(()=>{const toggle=document.querySelector('[data-product-festival-toggle]'),fields=document.querySelector('[data-product-festival-fields]');if(toggle&&fields){const sync=()=>{fields.hidden=!toggle.checked;};toggle.addEventListener('change',sync);sync();}const warrantyToggle=document.querySelector('[data-warranty-toggle]'),warrantyFields=document.querySelector('[data-warranty-fields]'),warrantyMonths=warrantyFields?.querySelector('input[name="warranty_months"]');if(warrantyToggle&&warrantyFields&&warrantyMonths){const syncWarranty=(focus=false)=>{const active=warrantyToggle.checked;warrantyFields.hidden=!active;warrantyMonths.disabled=!active;warrantyMonths.required=active;if(active&&focus)warrantyMonths.focus();};warrantyToggle.addEventListener('change',()=>syncWarranty(true));syncWarranty();}const list=document.querySelector('[data-specification-list]'),template=document.querySelector('[data-specification-template]'),addButton=document.querySelector('[data-add-specification]');if(!list||!template||!addButton)return;const syncRemoveButtons=()=>{const rows=[...list.querySelectorAll('[data-specification-row]')];rows.forEach(row=>{const button=row.querySelector('[data-remove-specification]');if(button)button.disabled=rows.length===1;});};list.addEventListener('click',event=>{const target=event.target;if(!(target instanceof Element))return;const button=target.closest('[data-remove-specification]');if(!button)return;const row=button.closest('[data-specification-row]');if(row&&list.querySelectorAll('[data-specification-row]').length>1)row.remove();syncRemoveButtons();});addButton.addEventListener('click',()=>{if(list.querySelectorAll('[data-specification-row]').length>=60)return;list.append(template.content.cloneNode(true));const rows=list.querySelectorAll('[data-specification-row]');const input=rows[rows.length-1]?.querySelector('input');if(input)input.focus();syncRemoveButtons();});syncRemoveButtons();})();</script>
<?php require __DIR__ . '/partials/footer.php';
