<?php
declare(strict_types=1);

function storefront_internal_link_is_valid(string $value): bool
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 500) return false;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) return false;

    $decoded = $value;
    for ($round = 0; $round < 3; $round++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) break;
        $decoded = $next;
    }

    if (
        str_starts_with($value, '/') || str_starts_with($value, '//') ||
        str_starts_with($decoded, '/') || str_starts_with($decoded, '//') ||
        str_contains($value, '\\') || str_contains($decoded, '\\') ||
        preg_match('/[\x00-\x1F\x7F]/', $value) || preg_match('/[\x00-\x1F\x7F]/', $decoded)
    ) return false;

    $parts = parse_url($value);
    if (
        $parts === false || isset($parts['scheme']) || isset($parts['host']) ||
        isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
    ) return false;

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._~/-]*$#D', $path) || str_contains($path, '//')) return false;
    foreach (explode('/', rawurldecode($path)) as $segment) {
        if ($segment === '.' || $segment === '..') return false;
    }

    foreach (['query', 'fragment'] as $component) {
        if (!isset($parts[$component])) continue;
        $part = (string)$parts[$component];
        if (
            strlen($part) > 450 || str_contains($part, '\\') ||
            preg_match('/[\x00-\x1F\x7F]/', $part) ||
            strpbrk($part, '<>"') !== false
        ) return false;
    }

    return true;
}

function storefront_internal_url(string $value, string $fallback = 'shop.php'): string
{
    return app_url(storefront_internal_link_is_valid($value) ? trim($value) : $fallback);
}

function homepage_promo_banners(int $limit = 6): array
{
    $limit = max(1, min(8, $limit));
    $stmt = db()->prepare('SELECT * FROM home_banners WHERE is_active=1 ORDER BY sort_order,id LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function trust_icon_catalog(): array
{
    return [
        'shield'=>'ضمانت اصالت','truck'=>'ارسال','receipt'=>'خرید شفاف','support'=>'پشتیبانی',
        'tracking'=>'پیگیری سفارش','return'=>'مرجوعی','payment'=>'پرداخت امن','store'=>'فروشگاه حضوری',
    ];
}

function trust_center_items(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM trust_items' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order,id';
    return db()->query($sql)->fetchAll();
}

function faq_category_catalog(): array
{
    return [
        'purchase'=>'خرید و ثبت سفارش','payment'=>'پرداخت','shipping'=>'ارسال و رهگیری','warranty'=>'گارانتی و اصالت',
        'returns'=>'مرجوعی','account'=>'حساب کاربری','service'=>'خدمات فروشگاه','other'=>'سایر پرسش‌ها',
    ];
}

function faq_public_groups(): array
{
    $catalog = faq_category_catalog();
    $rows = db()->query('SELECT * FROM faqs WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
    $groups = [];
    foreach ($rows as $row) {
        $key = isset($catalog[$row['category_key']]) ? (string)$row['category_key'] : 'other';
        if (!isset($groups[$key])) $groups[$key] = ['key'=>$key,'title'=>$catalog[$key],'items'=>[]];
        $groups[$key]['items'][] = $row;
    }
    return array_values($groups);
}

function homepage_category_bestseller_groups(int $categoryLimit = 4, int $productLimit = 3): array
{
    $categoryLimit = max(1, min(6, $categoryLimit));
    $productLimit = max(2, min(4, $productLimit));
    $selected = array_values(array_unique(array_filter(array_map(
        static fn(string $value): int => max(0, (int)$value),
        explode(',', (string)setting('home_bestseller_category_ids', ''))
    ))));
    $selected = array_slice($selected, 0, 6);

    if ($selected) {
        $marks = implode(',', array_fill(0, count($selected), '?'));
        $stmt = db()->prepare("SELECT c.* FROM categories c WHERE c.is_active=1 AND c.id IN ($marks) AND EXISTS(SELECT 1 FROM products p WHERE p.category_id=c.id AND p.status='active' AND p.price>0 AND p.stock>0)");
        $stmt->execute($selected);
        $byId = [];
        foreach ($stmt->fetchAll() as $category) $byId[(int)$category['id']] = $category;
        $categories = [];
        foreach ($selected as $id) if (isset($byId[$id])) $categories[] = $byId[$id];
    } else {
        $stmt = db()->prepare("SELECT c.*,COALESCE(cs.sold_quantity,0) sold_quantity
            FROM categories c
            LEFT JOIN (
                SELECT p.category_id,SUM(oi.quantity) sold_quantity
                FROM order_items oi
                JOIN orders o ON o.id=oi.order_id AND o.payment_status IN ('paid','partially_refunded') AND o.order_status NOT IN ('cancelled','refunded')
                JOIN products p ON p.id=oi.product_id
                GROUP BY p.category_id
            ) cs ON cs.category_id=c.id
            WHERE c.is_active=1 AND EXISTS(SELECT 1 FROM products p WHERE p.category_id=c.id AND p.status='active' AND p.price>0 AND p.stock>0)
            ORDER BY sold_quantity DESC,c.sort_order,c.id DESC LIMIT ?");
        $stmt->bindValue(1, $categoryLimit, PDO::PARAM_INT);
        $stmt->execute();
        $categories = $stmt->fetchAll();
    }

    $groups = [];
    foreach (array_slice($categories, 0, $categoryLimit) as $category) {
        $stmt = db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug,COALESCE(ps.sold_quantity,0) sold_quantity
            FROM products p
            JOIN categories c ON c.id=p.category_id
            JOIN brands b ON b.id=p.brand_id
            LEFT JOIN (
                SELECT oi.product_id,SUM(oi.quantity) sold_quantity
                FROM order_items oi
                JOIN orders o ON o.id=oi.order_id AND o.payment_status IN ('paid','partially_refunded') AND o.order_status NOT IN ('cancelled','refunded')
                GROUP BY oi.product_id
            ) ps ON ps.product_id=p.id
            WHERE p.category_id=? AND p.status='active' AND p.price>0 AND p.stock>0 AND b.is_active=1
            ORDER BY sold_quantity DESC,p.featured DESC,p.rating DESC,p.sort_order,p.id DESC LIMIT ?");
        $stmt->bindValue(1, (int)$category['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $productLimit, PDO::PARAM_INT);
        $stmt->execute();
        $products = hydrate_products($stmt->fetchAll());
        foreach ($products as &$product) $product['sold_quantity'] = (int)($product['sold_quantity'] ?? 0);
        unset($product);
        if ($products) $groups[] = ['category'=>$category,'products'=>$products];
    }
    return $groups;
}
