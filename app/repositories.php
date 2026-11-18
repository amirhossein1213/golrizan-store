<?php
declare(strict_types=1);

function all_categories(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM categories' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order, name';
    $rows=db()->query($sql)->fetchAll();foreach($rows as &$row){$row['id']=(int)$row['id'];$row['is_active']=(int)$row['is_active'];}unset($row);return $rows;
}

function all_brands(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM brands' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order, name';
    $rows=db()->query($sql)->fetchAll();foreach($rows as &$row){$row['id']=(int)$row['id'];$row['is_active']=(int)$row['is_active'];}unset($row);return $rows;
}

function normalize_product_row(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['category_id'] = (int)$row['category_id'];
    $row['brand_id'] = (int)$row['brand_id'];
    $row['price'] = (int)$row['price'];
    $row['base_price'] = (int)$row['price'];
    $row['legacy_old_price'] = isset($row['old_price']) && $row['old_price'] !== null ? (int)$row['old_price'] : null;
    $row['old_price'] = null;
    $row['cost_price'] = (int)($row['cost_price'] ?? 0);
    $row['stock'] = (int)$row['stock'];
    $row['weight_grams'] = (int)($row['weight_grams'] ?? 0);
    $row['shipping_extra'] = (int)($row['shipping_extra'] ?? 0);
    $row['featured'] = (int)$row['featured'];
    $row['torob_enabled'] = (int)$row['torob_enabled'];
    $row['snappay_enabled'] = (int)$row['snappay_enabled'];
    $row['authenticity_guarantee'] = (int)($row['authenticity_guarantee'] ?? 0);
    $row['safe_shipping_guarantee'] = (int)($row['safe_shipping_guarantee'] ?? 0);
    $row['festival_enabled'] = (int)($row['festival_enabled'] ?? 0);
    $row['festival_price'] = isset($row['festival_price']) && $row['festival_price'] !== null ? (int)$row['festival_price'] : null;
    $row['festival_free_shipping'] = (int)($row['festival_free_shipping'] ?? 0);
    $row['rating'] = (float)$row['rating'];
    $row['reviews_count'] = (int)$row['reviews_count'];
    $row['features'] = [];
    $row['festival_id'] = null;
    $row['festival_title'] = null;
    $row['festival_label'] = null;
    $row['festival_saving'] = 0;
    return $row;
}

function hydrate_products(array $rows, bool $applyFestivals = true, ?PDO $pdo = null): array
{
    if (!$rows) return [];
    $products = [];
    $ids = [];
    foreach ($rows as $row) {
        $product = normalize_product_row($row);
        $products[] = $product;
        $ids[] = $product['id'];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $connection = $pdo ?? db();
    $features = [];
    try {
        $statement = $connection->prepare("SELECT product_id,feature_text FROM product_features WHERE product_id IN ($marks) ORDER BY product_id,sort_order,id");
        $statement->execute($ids);
        foreach ($statement->fetchAll() as $feature) $features[(int)$feature['product_id']][] = $feature['feature_text'];
    } catch (PDOException $exception) {
        // ویژگی‌ها جانبی هستند؛ محصول اصلی باید همچنان قابل نمایش باشد.
        log_error($exception);
    }
    foreach ($products as &$product) $product['features'] = $features[$product['id']] ?? [];
    unset($product);
    return $applyFestivals ? apply_active_festivals_to_products($products, $connection) : $products;
}

function hydrate_product(array $row, bool $applyFestivals = true, ?PDO $pdo = null): array
{
    return hydrate_products([$row], $applyFestivals, $pdo)[0];
}

function product_features(int $productId): array
{
    try {
        $stmt = db()->prepare('SELECT feature_text FROM product_features WHERE product_id=? ORDER BY sort_order,id');
        $stmt->execute([$productId]);
        return array_column($stmt->fetchAll(), 'feature_text');
    } catch (PDOException $exception) {
        log_error($exception);
        return [];
    }
}


function product_specifications(int $productId, ?PDO $pdo = null): array
{
    if ($productId < 1) return [];
    $connection = $pdo ?? db();
    try {
        $stmt = $connection->prepare('SELECT id,product_id,spec_name,spec_value,sort_order FROM product_specifications WHERE product_id=? ORDER BY sort_order,id');
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $exception) {
        // جدول مشخصات فنی یک بخش جانبی است؛ نبود موقت آن نباید کل صفحه محصول را ۵۰۰ کند.
        log_error($exception);
        return [];
    }
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['product_id'] = (int)$row['product_id'];
        $row['sort_order'] = (int)$row['sort_order'];
    }
    unset($row);
    return $rows;
}

function submitted_product_specifications(mixed $names, mixed $values): array
{
    if (!is_array($names) || !is_array($values)) return [];
    $names = array_values($names);
    $values = array_values($values);
    $rows = [];
    $count = min(60, max(count($names), count($values)));
    for ($index = 0; $index < $count; $index++) {
        $name = trim(scalar_string($names[$index] ?? ''));
        $value = trim(scalar_string($values[$index] ?? ''));
        if ($name === '' && $value === '') continue;
        $rows[] = ['spec_name'=>$name, 'spec_value'=>$value, 'sort_order'=>count($rows)];
    }
    return $rows;
}


function product_feature_lines(mixed $raw): array
{
    $lines = is_array($raw)
        ? array_values($raw)
        : (preg_split('/\R/u', scalar_string($raw)) ?: []);
    $result = [];
    foreach ($lines as $line) {
        $line = trim(scalar_string($line));
        if ($line !== '') $result[] = $line;
    }
    // ۵۰ ویژگی متنی + حداکثر ۶۰ خطی که ممکن است به مشخصات فنی منتقل شوند.
    return array_slice($result, 0, 110);
}

function product_specification_title_key(string $title): string
{
    $title = trim(normalize_digits($title));
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
    return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
}

function product_feature_specification_pair(string $line): ?array
{
    $line = trim($line);
    if ($line === '') return null;
    // فقط اولین «:» جداکننده است؛ بنابراین خود مقدار می‌تواند دوباره شامل «:» باشد.
    if (!preg_match('/^(.{1,180}?)[\x{003A}\x{FF1A}]\s*(.+)$/u', $line, $match)) return null;
    $name = trim((string)$match[1]);
    $value = trim((string)$match[2]);
    if ($name === '' || $value === '') return null;
    if (!preg_match('/[\p{L}]/u', $name)) return null;
    // آدرس‌هایی مانند https://example.com نباید به مشخصه تبدیل شوند.
    if (preg_match('/^[a-z][a-z0-9+.-]*$/i', $name) && str_starts_with($value, '//')) return null;
    if (text_length($name) > 180 || text_length($value) > 2000) return null;
    return ['spec_name'=>$name, 'spec_value'=>$value];
}

function merge_product_feature_lines_into_specifications(mixed $rawFeatures, array $specifications, int $maximumSpecifications = 60, int $maximumFeatures = 50): array
{
    $maximumSpecifications = max(1, min(60, $maximumSpecifications));
    $maximumFeatures = max(1, min(50, $maximumFeatures));
    $rows = array_slice(array_values($specifications), 0, $maximumSpecifications);
    $titleIndexes = [];
    foreach ($rows as $index => &$row) {
        $row['spec_name'] = trim(scalar_string($row['spec_name'] ?? ''));
        $row['spec_value'] = trim(scalar_string($row['spec_value'] ?? ''));
        $row['sort_order'] = $index;
        if ($row['spec_name'] !== '') {
            $key = product_specification_title_key($row['spec_name']);
            if ($key !== '' && !array_key_exists($key, $titleIndexes)) $titleIndexes[$key] = $index;
        }
    }
    unset($row);

    $features = [];
    $converted = 0;
    $duplicates = 0;
    $conflicts = 0;
    $overflow = 0;

    foreach (product_feature_lines($rawFeatures) as $line) {
        $pair = product_feature_specification_pair($line);
        if (!$pair) {
            $features[] = $line;
            continue;
        }
        $key = product_specification_title_key($pair['spec_name']);
        if ($key !== '' && array_key_exists($key, $titleIndexes)) {
            $rowIndex = $titleIndexes[$key];
            $currentValue = trim(scalar_string($rows[$rowIndex]['spec_value'] ?? ''));
            if ($currentValue === '') {
                $rows[$rowIndex]['spec_value'] = $pair['spec_value'];
                $converted++;
                continue;
            }
            $normalizedCurrent = preg_replace('/\s+/u', ' ', $currentValue) ?? $currentValue;
            $normalizedIncoming = preg_replace('/\s+/u', ' ', $pair['spec_value']) ?? $pair['spec_value'];
            if ($normalizedCurrent === $normalizedIncoming) {
                $duplicates++;
                continue;
            }
            // مقدار دستی موجود را بازنویسی نمی‌کنیم؛ خط متعارض به‌عنوان ویژگی متنی باقی می‌ماند.
            $features[] = $line;
            $conflicts++;
            continue;
        }
        if (count($rows) >= $maximumSpecifications) {
            $features[] = $line;
            $overflow++;
            continue;
        }
        $rows[] = [
            'spec_name'=>$pair['spec_name'],
            'spec_value'=>$pair['spec_value'],
            'sort_order'=>count($rows),
        ];
        $titleIndexes[$key] = count($rows) - 1;
        $converted++;
    }

    $features = array_slice($features, 0, $maximumFeatures);
    foreach ($rows as $index => &$row) $row['sort_order'] = $index;
    unset($row);
    return [
        'features'=>$features,
        'specifications'=>$rows,
        'converted'=>$converted,
        'duplicates'=>$duplicates,
        'conflicts'=>$conflicts,
        'overflow'=>$overflow,
    ];
}

function replace_product_specifications(PDO $pdo, int $productId, array $rows): bool
{
    try {
        $pdo->prepare('DELETE FROM product_specifications WHERE product_id=?')->execute([$productId]);
        if (!$rows) return true;
        $insert = $pdo->prepare('INSERT INTO product_specifications(product_id,spec_name,spec_value,sort_order) VALUES(?,?,?,?)');
        foreach ($rows as $index => $row) {
            $insert->execute([
                $productId,
                (string)$row['spec_name'],
                (string)$row['spec_value'],
                (int)($row['sort_order'] ?? $index),
            ]);
        }
        return true;
    } catch (PDOException $exception) {
        log_error($exception);
        return false;
    }
}

function find_product_by_slug(string $slug, bool $includeDraft = false): ?array
{
    $sql = 'SELECT p.*, c.name category_name, c.slug category_slug, b.name brand_name, b.slug brand_slug
            FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id
            WHERE p.slug=?' . ($includeDraft ? '' : " AND p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1") . ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? hydrate_product($row, !$includeDraft) : null;
}

function find_product_by_id(int $id, bool $includeDraft = false): ?array
{
    $sql = 'SELECT p.*, c.name category_name, c.slug category_slug, b.name brand_name, b.slug brand_slug
            FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id
            WHERE p.id=?' . ($includeDraft ? '' : " AND p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1") . ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? hydrate_product($row, !$includeDraft) : null;
}

function featured_products(int $limit = 8): array
{
    $limit = max(1, min(24, $limit));
    $stmt = db()->prepare("SELECT p.*, c.name category_name, c.slug category_slug, b.name brand_name, b.slug brand_slug
        FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id
        WHERE p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1 ORDER BY p.featured DESC, p.sort_order, p.id DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return hydrate_products($stmt->fetchAll());
}

function homepage_available_products(string $mode = 'featured', int $limit = 8): array
{
    $limit = max(1, min(24, $limit));
    $order = $mode === 'newest' ? 'p.id DESC' : 'p.featured DESC,p.rating DESC,p.sort_order,p.id DESC';
    $stmt = db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug
        FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id
        WHERE p.status='active' AND p.price>0 AND p.stock>0 AND c.is_active=1 AND b.is_active=1
        ORDER BY {$order} LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return hydrate_products($stmt->fetchAll());
}

function homepage_deal_products(int $limit = 4): array
{
    $limit = max(1, min(8, $limit));
    $stmt = db()->query("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug
        FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id
        WHERE p.status='active' AND p.price>0 AND p.stock>0 AND c.is_active=1 AND b.is_active=1
        ORDER BY p.featured DESC,p.id DESC LIMIT 100");
    $products = array_values(array_filter(hydrate_products($stmt->fetchAll()), static fn(array $product): bool => (int)$product['festival_saving'] > 0));
    usort($products, static fn(array $a, array $b): int => [(int)$b['festival_saving'],(int)$b['id']] <=> [(int)$a['festival_saving'],(int)$a['id']]);
    return array_slice($products, 0, $limit);
}

function homepage_festival_products(int $festivalId, int $limit = 12): array
{
    $limit = max(1, min(24, $limit));
    if ($festivalId < 1) return [];
    $stmt = db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug
        FROM festival_products fp
        JOIN products p ON p.id=fp.product_id
        JOIN categories c ON c.id=p.category_id
        JOIN brands b ON b.id=p.brand_id
        WHERE fp.festival_id=? AND p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1
        ORDER BY p.featured DESC,p.sort_order,p.id DESC LIMIT ?");
    $stmt->bindValue(1, $festivalId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_values(array_filter(hydrate_products($stmt->fetchAll()), static fn(array $product): bool => (int)$product['festival_saving'] > 0));
}


function homepage_direct_festival_products(int $limit = 12): array
{
    $limit = max(1, min(24, $limit));
    try {
        $stmt = db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug
            FROM products p
            JOIN categories c ON c.id=p.category_id
            JOIN brands b ON b.id=p.brand_id
            WHERE p.status='active' AND p.price>0 AND p.festival_enabled=1 AND c.is_active=1 AND b.is_active=1
            ORDER BY p.featured DESC,p.sort_order,p.id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_values(array_filter(
            hydrate_products($stmt->fetchAll()),
            static fn(array $product): bool => (int)$product['festival_saving'] > 0 || !empty($product['festival_free_shipping'])
        ));
    } catch (PDOException $exception) {
        log_error($exception);
        return [];
    }
}

function homepage_limited_discount_products(int $limit = 12): array
{
    $limit = max(1, min(24, $limit));
    try {
        $stmt = db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug
            FROM festivals f
            JOIN products p ON p.id=f.target_id
            JOIN categories c ON c.id=p.category_id
            JOIN brands b ON b.id=p.brand_id
            WHERE f.is_torobpay_special=1 AND f.is_active=1 AND f.festival_type='price' AND f.scope='product'
              AND f.starts_at<=NOW() AND f.ends_at>=NOW()
              AND p.status='active' AND p.price>0 AND p.stock>0 AND c.is_active=1 AND b.is_active=1
            ORDER BY f.ends_at ASC,f.priority DESC,f.id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_values(array_filter(
            hydrate_products($stmt->fetchAll()),
            static fn(array $product): bool => !empty($product['is_limited_discount']) && (int)$product['festival_saving'] > 0
        ));
    } catch (PDOException $exception) {
        log_error($exception);
        return [];
    }
}

function search_products(array $filters, int $page = 1, int $perPage = 24): array
{
    $page = max(1, min(100000, $page));
    $requestedPage = $page;
    $perPage = max(1, min(60, $perPage));
    $where = ["p.status='active'", 'p.price>0', 'c.is_active=1', 'b.is_active=1'];
    $params = [];
    $query = text_substr(trim(scalar_string($filters['q'] ?? '')), 0, 100);
    if ($query !== '') {
        $where[] = '(p.title LIKE ? OR p.short_title LIKE ? OR p.sku LIKE ? OR p.description LIKE ? OR b.name LIKE ? OR EXISTS(SELECT 1 FROM product_features pf WHERE pf.product_id=p.id AND pf.feature_text LIKE ?) OR EXISTS(SELECT 1 FROM product_variants pv WHERE pv.product_id=p.id AND (pv.sku LIKE ? OR pv.title_suffix LIKE ?)) OR EXISTS(SELECT 1 FROM product_variants pv JOIN product_variant_values pvv ON pvv.variant_id=pv.id JOIN product_attribute_values pav ON pav.id=pvv.attribute_value_id JOIN product_attributes pa ON pa.id=pav.attribute_id WHERE pv.product_id=p.id AND (pav.value LIKE ? OR pa.name LIKE ?)) OR EXISTS(SELECT 1 FROM product_attribute_selections pas JOIN product_attribute_values pav2 ON pav2.id=pas.attribute_value_id JOIN product_attributes pa2 ON pa2.id=pav2.attribute_id WHERE pas.product_id=p.id AND (pav2.value LIKE ? OR pa2.name LIKE ?)))';
        $q = '%' . $query . '%';
        array_push($params, $q, $q, $q, $q, $q, $q, $q, $q, $q, $q, $q, $q);
    }
    $category = scalar_string($filters['category'] ?? '');
    $festivalCategory = $category === 'festival';
    if (!$festivalCategory && $category !== '') {
        $where[] = 'c.slug=?';
        $params[] = $category;
    }
    $brand = scalar_string($filters['brand'] ?? '');
    if ($brand !== '') { $where[] = 'b.slug=?'; $params[] = $brand; }
    if (!empty($filters['stock'])) $where[] = 'p.stock>0';
    $warranty=scalar_string($filters['warranty']??'');
    if($warranty==='with')$where[]="(COALESCE(p.warranty,'')<>'' OR EXISTS(SELECT 1 FROM product_variants pv WHERE pv.product_id=p.id AND pv.status='active' AND COALESCE(pv.warranty,'')<>''))";
    elseif($warranty==='without')$where[]="(COALESCE(p.warranty,'')='' AND NOT EXISTS(SELECT 1 FROM product_variants pv WHERE pv.product_id=p.id AND pv.status='active' AND COALESCE(pv.warranty,'')<>''))";
    $color=text_substr(trim(scalar_string($filters['color']??'')),0,80);
    if($color!==''){$where[]="(p.color=? OR EXISTS(SELECT 1 FROM product_variants pv JOIN product_variant_values pvv ON pvv.variant_id=pv.id JOIN product_attribute_values pav ON pav.id=pvv.attribute_value_id JOIN product_attributes pa ON pa.id=pav.attribute_id WHERE pv.product_id=p.id AND pv.status='active' AND pa.display_type='color' AND pav.value=?) OR EXISTS(SELECT 1 FROM product_attribute_selections pas JOIN product_attribute_values pav2 ON pav2.id=pas.attribute_value_id JOIN product_attributes pa2 ON pa2.id=pav2.attribute_id WHERE pas.product_id=p.id AND pa2.display_type='color' AND pav2.value=?))";$params[]=$color;$params[]=$color;$params[]=$color;}
    $energy=text_substr(trim(scalar_string($filters['energy']??'')),0,50);if($energy!==''){$where[]='p.energy_label=?';$params[]=$energy;}
    $type=scalar_string($filters['type']??'');if(in_array($type,['simple','variable'],true)){$where[]='p.product_type=?';$params[]=$type;}
    $rating=max(0,min(5,scalar_int($filters['rating']??0)));if($rating>0){$where[]='p.rating>=?';$params[]=$rating;}

    $minValid = false;
    $min = bounded_uint($filters['min'] ?? '', MAX_MONEY_AMOUNT, $minValid);
    $maxValid = false;
    $max = bounded_uint($filters['max'] ?? '', MAX_MONEY_AMOUNT, $maxValid);
    $sort = scalar_string($filters['sort'] ?? 'popular', 'popular');
    if (!in_array($sort, ['popular','cheap','expensive','newest'], true)) $sort = 'popular';

    // قیمت مؤثر جشنواره در SQL محاسبه می‌شود تا فیلتر/مرتب‌سازی مجبور به بارگذاری کل کاتالوگ در حافظه PHP نباشد.
    $limitedDiscountPriceCondition = limited_discount_sql_price_condition();
    $effectivePriceSql = "LEAST(CASE WHEN p.festival_enabled=1 AND p.festival_price IS NOT NULL AND p.festival_price>0 AND p.festival_price<p.price THEN p.festival_price ELSE p.price END,COALESCE((SELECT MIN(CASE WHEN {$limitedDiscountPriceCondition} AND f.torobpay_old_price=p.price AND f.torobpay_new_price>0 AND f.torobpay_new_price<f.torobpay_old_price THEN f.torobpay_new_price WHEN f.is_torobpay_special=0 AND f.discount_type='percent' AND f.discount_value BETWEEN 1 AND 99 THEN GREATEST(1,p.price-FLOOR(p.price*f.discount_value/100)) WHEN f.is_torobpay_special=0 AND f.discount_type='fixed' AND f.discount_value>0 THEN GREATEST(1,p.price-LEAST(f.discount_value,p.price-1)) ELSE NULL END) FROM festivals f WHERE f.is_active=1 AND f.festival_type='price' AND f.starts_at<=NOW() AND f.ends_at>=NOW() AND (f.scope='all' OR (f.scope='category' AND f.target_id=p.category_id) OR (f.scope='brand' AND f.target_id=p.brand_id) OR (f.scope='product' AND f.target_id=p.id) OR (f.scope='group' AND EXISTS(SELECT 1 FROM festival_products fp WHERE fp.festival_id=f.id AND fp.product_id=p.id)))),p.price))";
    if ($festivalCategory) $where[] = '(p.festival_enabled=1 OR '.$effectivePriceSql.'<p.price)';
    if ($minValid) {$where[]=$effectivePriceSql.'>=?';$params[]=$min;}
    if ($maxValid) {$where[]=$effectivePriceSql.'<=?';$params[]=$max;}
    $whereSql = implode(' AND ', $where);
    $order = match($sort){'newest'=>'p.id DESC','cheap'=>$effectivePriceSql.' ASC,p.id DESC','expensive'=>$effectivePriceSql.' DESC,p.id DESC',default=>'p.featured DESC,p.rating DESC,p.id DESC'};
    $count = db()->prepare("SELECT COUNT(*) FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $outOfRange = $requestedPage > $pages;
    if ($outOfRange) return ['items'=>[],'total'=>$total,'pages'=>$pages,'page'=>$requestedPage,'out_of_range'=>true];
    $offset = max(0, ($requestedPage - 1) * $perPage);
    $stmt = db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    return ['items'=>hydrate_products($stmt->fetchAll()),'total'=>$total,'pages'=>$pages,'page'=>$requestedPage,'out_of_range'=>false];
}

function cart_products(array $items): array
{
    $clean = [];
    foreach (array_slice($items, 0, 100) as $item) {
        if (!is_array($item)) continue;
        $id = scalar_int($item['id'] ?? 0);
        $variantId = max(0, scalar_int($item['variant_id'] ?? 0));
        $qty = max(1, min(20, scalar_int($item['quantity'] ?? 1, 1)));
        if ($id < 1) continue;
        $key = $id . ':' . $variantId;
        $clean[$key] = ['id'=>$id,'variant_id'=>$variantId,'quantity'=>min(20,(int)($clean[$key]['quantity']??0)+$qty)];
    }
    if (!$clean) return [];
    $productIds = array_values(array_unique(array_map(static fn(array $row): int => $row['id'], $clean)));
    sort($productIds,SORT_NUMERIC);
    $marks = implode(',', array_fill(0,count($productIds),'?'));
    $stmt=db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.id IN ($marks) AND p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1");
    $stmt->execute($productIds);
    $productMap=[];
    foreach(hydrate_products($stmt->fetchAll()) as $product)$productMap[(int)$product['id']]=$product;
    $variantMaps=[];
    foreach($productMap as $productId=>$product){
        if(!product_has_variants($product))continue;
        foreach(product_variant_rows($productId,true) as $variant)$variantMaps[$productId][(int)$variant['id']]=$variant;
    }
    $rows=[];
    foreach($clean as $entry){
        $product=$productMap[$entry['id']]??null;if(!$product)continue;
        $quantity=$entry['quantity'];$variantId=$entry['variant_id'];
        if(product_has_variants($product)){
            $variant=$variantMaps[$entry['id']][$variantId]??null;
            // هرگز مدل نامعتبر یا حذف‌شده را بی‌صدا با مدل دیگری جایگزین نکن.
            // این کار ممکن بود مشتری رنگ/ظرفیتی متفاوت از انتخاب خودش دریافت کند.
            if(!$variant)continue;
            $variant=variant_with_festival($product,$variant);
            $product['variant_id']=$variantId;
            $product['variant_title']=$variant['option_label']?:$variant['title_suffix'];
            $product['variant_options']=$variant['options'];
            $product['sku']=$variant['sku'];
            $product['price']=$variant['price'];
            $product['base_price']=$variant['base_price'];
            $product['cost_price']=$variant['cost_price'];
            $product['stock']=$variant['stock'];
            $product['weight_grams']=$variant['weight_grams'];
            $product['warranty']=$variant['warranty']?:$product['warranty'];
            $product['image_path']=$variant['image_path']?:$product['image_path'];
            $product['festival_id']=$variant['festival_id'];
            $product['festival_title']=$variant['festival_title'];
            $product['festival_label']=$variant['festival_label'];
            $product['festival_saving']=$variant['festival_saving'];
            $product['_snappay_enabled']=(int)($variant['snappay_enabled']??0);
        } else {
            $product['variant_id']=null;$product['variant_title']=null;$product['variant_options']=[];$product['_snappay_enabled']=(int)($product['snappay_enabled']??0);
        }
        $product['quantity']=min($quantity,max(0,(int)$product['stock']));
        $product['cart_key']=$product['id'].':'.((int)($product['variant_id']??0));
        $rows[]=$product;
    }
    return $rows;
}

function shipping_amount(int $subtotal = 0): int
{
    return 0;
}

function cart_totals(array $products): array
{
    $subtotal = 0;
    $baseSubtotal = 0;
    $festivalSaving = 0;
    foreach ($products as $product) {
        $qty = (int)($product['quantity'] ?? 0);
        if ($qty < 1) continue;
        $price = (int)$product['price'];
        $basePrice = (int)($product['base_price'] ?? $price);
        if ($price <= 0 || $price > MAX_MONEY_AMOUNT || $price > intdiv(MAX_MONEY_AMOUNT, $qty)) throw new RuntimeException('قیمت یا تعداد یکی از کالاها نامعتبر است.');
        $line = $price * $qty;
        $baseLine = $basePrice * $qty;
        if ($subtotal > MAX_MONEY_AMOUNT - $line || $baseSubtotal > MAX_MONEY_AMOUNT - $baseLine) throw new RuntimeException('مبلغ سبد خرید از سقف مجاز بیشتر است.');
        $subtotal += $line;
        $baseSubtotal += $baseLine;
        $festivalSaving += max(0, $baseLine - $line);
    }
    $shipping = festival_shipping_quote(null, $products);
    return [
        'subtotal'=>$subtotal,
        'base_subtotal'=>$baseSubtotal,
        'festival_saving'=>$festivalSaving,
        'shipping'=>$shipping,
        'total'=>$subtotal,
    ];
}

function order_number(): string
{
    return 'MSV-' . jalali_compact_date() . '-' . strtoupper(bin2hex(random_bytes(8)));
}
