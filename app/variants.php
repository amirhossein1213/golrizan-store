<?php
declare(strict_types=1);

function product_has_variants(array $product): bool
{
    return (string)($product['product_type'] ?? 'simple') === 'variable';
}

function all_product_attributes(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM product_attributes' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order,name,id';
    $attributes = db()->query($sql)->fetchAll();
    if (!$attributes) return [];
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $attributes);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM product_attribute_values WHERE attribute_id IN ($marks) ORDER BY attribute_id,sort_order,value,id");
    $stmt->execute($ids);
    $values = [];
    foreach ($stmt->fetchAll() as $row) $values[(int)$row['attribute_id']][] = $row;
    foreach ($attributes as &$attribute) $attribute['values'] = $values[(int)$attribute['id']] ?? [];
    unset($attribute);
    return $attributes;
}

function normalize_product_attribute_value_ids(mixed $input): array
{
    $result = [];
    foreach ((array)$input as $value) {
        $id = max(0, scalar_int($value));
        if ($id > 0) $result[$id] = $id;
    }
    return array_values($result);
}

function product_attribute_value_rows(PDO $pdo, array $valueIds, bool $activeOnly = true): array
{
    $valueIds = normalize_product_attribute_value_ids($valueIds);
    if (!$valueIds) return [];
    $marks = implode(',', array_fill(0, count($valueIds), '?'));
    $sql = "SELECT pav.id value_id,pav.attribute_id,pav.value,pav.slug value_slug,pav.color_hex,pav.sort_order value_sort,pa.name attribute_name,pa.slug attribute_slug,pa.display_type,pa.sort_order attribute_sort,pa.is_active FROM product_attribute_values pav JOIN product_attributes pa ON pa.id=pav.attribute_id WHERE pav.id IN ($marks)";
    if ($activeOnly) $sql .= ' AND pa.is_active=1';
    $sql .= ' ORDER BY pa.sort_order,pa.name,pa.id,pav.sort_order,pav.value,pav.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($valueIds);
    return $stmt->fetchAll();
}

function product_selected_attribute_value_ids(int $productId, ?PDO $pdo = null): array
{
    if ($productId < 1) return [];
    $pdo ??= db();
    try {
        $stmt = $pdo->prepare('SELECT attribute_value_id FROM product_attribute_selections WHERE product_id=? ORDER BY sort_order,attribute_value_id');
        $stmt->execute([$productId]);
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    } catch (PDOException $exception) {
        log_error($exception);
        return [];
    }
}

function product_used_attribute_value_ids(int $productId, ?PDO $pdo = null): array
{
    if ($productId < 1) return [];
    $pdo ??= db();
    $stmt = $pdo->prepare('SELECT DISTINCT pvv.attribute_value_id FROM product_variants pv JOIN product_variant_values pvv ON pvv.variant_id=pv.id WHERE pv.product_id=? ORDER BY pvv.attribute_value_id');
    $stmt->execute([$productId]);
    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function replace_product_attribute_selections(PDO $pdo, int $productId, array $valueIds): void
{
    $valueIds = normalize_product_attribute_value_ids($valueIds);
    $pdo->prepare('DELETE FROM product_attribute_selections WHERE product_id=?')->execute([$productId]);
    if (!$valueIds) return;
    $insert = $pdo->prepare('INSERT INTO product_attribute_selections(product_id,attribute_value_id,sort_order) VALUES(?,?,?)');
    foreach ($valueIds as $index => $valueId) $insert->execute([$productId, $valueId, $index]);
}

function product_selected_attributes(int $productId, bool $activeOnly = true, ?PDO $pdo = null): array
{
    $pdo ??= db();
    $valueIds = product_selected_attribute_value_ids($productId, $pdo);
    if (!$valueIds) return [];
    $rows = product_attribute_value_rows($pdo, $valueIds, $activeOnly);
    $attributes = [];
    foreach ($rows as $row) {
        $attributeId = (int)$row['attribute_id'];
        if (!isset($attributes[$attributeId])) {
            $attributes[$attributeId] = [
                'id'=>$attributeId,
                'name'=>$row['attribute_name'],
                'slug'=>$row['attribute_slug'],
                'display_type'=>$row['display_type'],
                'values'=>[],
            ];
        }
        $attributes[$attributeId]['values'][] = [
            'id'=>(int)$row['value_id'],
            'value'=>$row['value'],
            'slug'=>$row['value_slug'],
            'color_hex'=>$row['color_hex'],
        ];
    }
    return array_values($attributes);
}

/**
 * ویژگی‌هایی که باید در تسویه حساب از مشتری پرسیده شوند.
 *
 * ویژگی‌های ثبت‌شده در خود مدل قبلاً روی صفحه محصول انتخاب شده‌اند و نباید
 * دوباره با یک مقدار متناقض در Checkout نمایش داده شوند.
 */
function product_checkout_attributes(int $productId, array $variantOptions = [], ?PDO $pdo = null): array
{
    if ($productId < 1) return [];
    $fixedAttributeIds = [];
    foreach ($variantOptions as $option) {
        if (!is_array($option)) continue;
        $attributeId = max(0, scalar_int($option['attribute_id'] ?? 0));
        if ($attributeId > 0) $fixedAttributeIds[$attributeId] = true;
    }
    $groups = product_selected_attributes($productId, true, $pdo);
    return array_values(array_filter($groups, static function (array $group) use ($fixedAttributeIds): bool {
        $attributeId = (int)($group['id'] ?? 0);
        return $attributeId > 0 && !isset($fixedAttributeIds[$attributeId]) && !empty($group['values']);
    }));
}

/**
 * @return array{options:array<int,array<string,mixed>>,label:string,value_ids:array<int,int>}
 */
function validate_product_checkout_attribute_selections(int $productId, array $valueIds, array $variantOptions = [], ?PDO $pdo = null): array
{
    $pdo ??= db();
    $groups = product_checkout_attributes($productId, $variantOptions, $pdo);
    $selectedIds = normalize_product_attribute_value_ids($valueIds);
    if (!$groups) {
        if ($selectedIds) throw new RuntimeException('برای این کالا ویژگی قابل انتخابی در تسویه حساب وجود ندارد.');
        return ['options'=>[],'label'=>'','value_ids'=>[]];
    }

    $allowedValues = [];
    $requiredAttributes = [];
    foreach ($groups as $group) {
        $attributeId = (int)$group['id'];
        $requiredAttributes[$attributeId] = (string)$group['name'];
        foreach ((array)$group['values'] as $value) {
            if (!is_array($value)) continue;
            $valueId = (int)($value['id'] ?? 0);
            if ($valueId < 1) continue;
            $allowedValues[$valueId] = [
                'value_id'=>$valueId,
                'attribute_id'=>$attributeId,
                'value'=>(string)($value['value'] ?? ''),
                'value_slug'=>(string)($value['slug'] ?? ''),
                'color_hex'=>$value['color_hex'] ?? null,
                'attribute_name'=>(string)$group['name'],
                'attribute_slug'=>(string)($group['slug'] ?? ''),
                'display_type'=>(string)($group['display_type'] ?? 'button'),
            ];
        }
    }

    $selectedByAttribute = [];
    foreach ($selectedIds as $valueId) {
        $option = $allowedValues[$valueId] ?? null;
        if (!$option) throw new RuntimeException('یکی از ویژگی‌های انتخاب‌شده این کالا معتبر یا در دسترس نیست.');
        $attributeId = (int)$option['attribute_id'];
        if (isset($selectedByAttribute[$attributeId])) throw new RuntimeException('از هر ویژگی کالا فقط یک مقدار قابل انتخاب است.');
        $selectedByAttribute[$attributeId] = $option;
    }

    $missing = array_diff_key($requiredAttributes, $selectedByAttribute);
    if ($missing) throw new RuntimeException('ویژگی «'.reset($missing).'» را برای این کالا انتخاب کنید.');
    if (count($selectedByAttribute) !== count($requiredAttributes)) throw new RuntimeException('ویژگی‌های انتخاب‌شده کالا با گزینه‌های موجود هماهنگ نیست.');

    $options = [];
    foreach ($groups as $group) $options[] = $selectedByAttribute[(int)$group['id']];
    $label = implode('، ', array_map(static fn(array $option): string => $option['attribute_name'].': '.$option['value'], $options));
    return ['options'=>$options,'label'=>$label,'value_ids'=>array_map(static fn(array $option): int => (int)$option['value_id'], $options)];
}

function product_available_attributes(int $productId, bool $activeOnly = true, ?PDO $pdo = null): array
{
    $pdo ??= db();
    $selectedIds = product_selected_attribute_value_ids($productId, $pdo);
    if (!$selectedIds) return all_product_attributes($activeOnly);
    $rows = product_attribute_value_rows($pdo, $selectedIds, $activeOnly);
    $attributes = [];
    foreach ($rows as $row) {
        $attributeId = (int)$row['attribute_id'];
        if (!isset($attributes[$attributeId])) {
            $attributes[$attributeId] = [
                'id'=>$attributeId,
                'name'=>$row['attribute_name'],
                'slug'=>$row['attribute_slug'],
                'display_type'=>$row['display_type'],
                'values'=>[],
            ];
        }
        $attributes[$attributeId]['values'][] = [
            'id'=>(int)$row['value_id'],
            'attribute_id'=>$attributeId,
            'value'=>$row['value'],
            'slug'=>$row['value_slug'],
            'color_hex'=>$row['color_hex'],
        ];
    }
    return array_values($attributes);
}

function product_variant_rows(int $productId, bool $activeOnly = true, ?PDO $pdo = null): array
{
    $pdo ??= db();
    $sql = 'SELECT * FROM product_variants WHERE product_id=?' . ($activeOnly ? " AND status='active' AND price>0" : '') . ' ORDER BY is_default DESC,sort_order,id';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$productId]);
        $variants = $stmt->fetchAll();
    } catch (PDOException $exception) {
        log_error($exception);
        return [];
    }
    if (!$variants) return [];
    $variantIds = array_map(static fn(array $row): int => (int)$row['id'], $variants);
    $marks = implode(',', array_fill(0, count($variantIds), '?'));
    $valuesStmt = $pdo->prepare("SELECT pvv.variant_id,pav.id value_id,pav.value,pav.slug value_slug,pav.color_hex,pa.id attribute_id,pa.name attribute_name,pa.slug attribute_slug,pa.display_type FROM product_variant_values pvv JOIN product_attribute_values pav ON pav.id=pvv.attribute_value_id JOIN product_attributes pa ON pa.id=pav.attribute_id WHERE pvv.variant_id IN ($marks) ORDER BY pa.sort_order,pa.id,pav.sort_order,pav.id");
    $valuesStmt->execute($variantIds);
    $options = [];
    foreach ($valuesStmt->fetchAll() as $row) $options[(int)$row['variant_id']][] = $row;
    foreach ($variants as &$variant) {
        foreach (['id','product_id','price','cost_price','stock','weight_grams','is_default','torob_enabled','snappay_enabled','sort_order'] as $key) $variant[$key] = (int)$variant[$key];
        $variant['old_price'] = $variant['old_price'] !== null ? (int)$variant['old_price'] : null;
        $variant['options'] = $options[$variant['id']] ?? [];
        $variant['option_value_ids'] = array_map(static fn(array $row): int => (int)$row['value_id'], $variant['options']);
        $variant['option_label'] = implode('، ', array_map(static fn(array $row): string => $row['attribute_name'] . ': ' . $row['value'], $variant['options']));
    }
    unset($variant);
    return $variants;
}

function product_variant_by_id(int $variantId, bool $activeOnly = true, ?PDO $pdo = null, bool $forUpdate = false): ?array
{
    if ($variantId < 1) return null;
    $pdo ??= db();
    $sql = 'SELECT pv.*,p.category_id,p.brand_id,p.title product_title,p.short_title product_short_title,p.slug product_slug,p.status product_status,p.product_type,c.is_active category_active,b.is_active brand_active FROM product_variants pv JOIN products p ON p.id=pv.product_id JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE pv.id=?';
    if ($activeOnly) $sql .= " AND pv.status='active' AND pv.price>0 AND p.status='active' AND c.is_active=1 AND b.is_active=1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$variantId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $variants = product_variant_rows((int)$row['product_id'], false, $pdo);
    foreach ($variants as $variant) if ((int)$variant['id'] === $variantId) return array_merge($row, $variant);
    return null;
}

function product_default_variant(int $productId, bool $activeOnly = true, ?PDO $pdo = null): ?array
{
    $variants = product_variant_rows($productId, $activeOnly, $pdo);
    return $variants[0] ?? null;
}

function variant_with_festival(array $product, array $variant, ?PDO $pdo = null): array
{
    $basePrice = max(0, (int)$variant['price']);
    $festivalProduct = $product;
    $festivalProduct['festival_reference_price'] = max(0, (int)($product['price'] ?? 0));
    $festivalProduct['price'] = $basePrice;
    $festivalProduct['base_price'] = $basePrice;
    $applied = apply_active_festivals_to_products([$festivalProduct], $pdo)[0];
    $variant['base_price'] = $basePrice;
    $variant['price'] = (int)$applied['price'];
    $variant['festival_id'] = $applied['festival_id'];
    $variant['festival_title'] = $applied['festival_title'];
    $variant['festival_label'] = $applied['festival_label'];
    $variant['festival_saving'] = (int)$applied['festival_saving'];
    $suffix = trim((string)($variant['option_label'] ?? ''));
    if ($suffix === '') $suffix = trim((string)($variant['title_suffix'] ?? ''));
    $variant['display_title'] = trim((string)$product['title'] . ($suffix !== '' ? ' - ' . $suffix : ''));
    return $variant;
}

function sync_product_variant_summary(PDO $pdo, int $productId): void
{
    $stmt = $pdo->prepare("SELECT MIN(price) min_price,SUM(stock) total_stock,MIN(cost_price) min_cost,MIN(weight_grams) min_weight FROM product_variants WHERE product_id=? AND status='active' AND price>0");
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row || $row['min_price'] === null) {
        $pdo->prepare("UPDATE products SET price=0,stock=0 WHERE id=? AND product_type='variable'")->execute([$productId]);
        return;
    }
    $pdo->prepare("UPDATE products SET price=?,cost_price=?,stock=?,weight_grams=? WHERE id=? AND product_type='variable'")->execute([
        (int)$row['min_price'],(int)$row['min_cost'],(int)$row['total_stock'],(int)$row['min_weight'],$productId
    ]);
}

function variant_selection_matrix(array $variants): array
{
    $attributes = [];
    foreach ($variants as $variant) {
        foreach ($variant['options'] as $option) {
            $attributeId = (int)$option['attribute_id'];
            $valueId = (int)$option['value_id'];
            if (!isset($attributes[$attributeId])) $attributes[$attributeId] = [
                'id'=>$attributeId,'name'=>$option['attribute_name'],'slug'=>$option['attribute_slug'],'display_type'=>$option['display_type'],'values'=>[]
            ];
            $attributes[$attributeId]['values'][$valueId] = [
                'id'=>$valueId,'value'=>$option['value'],'slug'=>$option['value_slug'],'color_hex'=>$option['color_hex']
            ];
        }
    }
    foreach ($attributes as &$attribute) $attribute['values'] = array_values($attribute['values']);
    unset($attribute);
    return array_values($attributes);
}
