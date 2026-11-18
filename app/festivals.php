<?php
declare(strict_types=1);

/**
 * ماژول جشنواره‌ها
 * - هیچ جشنواره قیمتی با جشنواره قیمتی دیگر جمع نمی‌شود؛ بیشترین منفعت برای مشتری اعمال می‌شود.
 * - جشنواره ارسال رایگان مستقل است و می‌تواند هم‌زمان با جشنواره قیمت فعال باشد.
 */

function limited_discount_max_duration_seconds(): int
{
    return 72 * 60 * 60;
}

function torobpay_special_max_duration_seconds(): int
{
    return limited_discount_max_duration_seconds();
}

function limited_discount_percent(int $oldPrice, int $newPrice): float
{
    if ($oldPrice < 1 || $newPrice < 1 || $newPrice >= $oldPrice) return 0.0;
    return round((($oldPrice - $newPrice) * 100) / $oldPrice, 2);
}

function torobpay_special_discount_percent(int $oldPrice, int $newPrice): float
{
    return limited_discount_percent($oldPrice, $newPrice);
}

function limited_discount_channels(): array
{
    return [
        'site'=>'فروشگاه',
        'torobpay'=>'فروشگاه + ترب‌پی',
        'snappay'=>'فروشگاه + اسنپ‌پی',
        'all'=>'فروشگاه + ترب‌پی + اسنپ‌پی',
    ];
}

function limited_discount_normalize_channel(string $channel): string
{
    return array_key_exists($channel, limited_discount_channels()) ? $channel : 'site';
}

function limited_discount_channel_label(string $channel): string
{
    $channel = limited_discount_normalize_channel($channel);
    return limited_discount_channels()[$channel];
}

function limited_discount_requires_torobpay(string $channel): bool
{
    return in_array(limited_discount_normalize_channel($channel), ['torobpay','all'], true);
}

function limited_discount_requires_snappay(string $channel): bool
{
    return in_array(limited_discount_normalize_channel($channel), ['snappay','all'], true);
}

function torobpay_special_payment_markup_percent(): float
{
    return max(0.0, (float)setting('torobpay_markup_percent', '0'));
}

function limited_discount_sql_price_condition(): string
{
    if (torobpay_special_payment_markup_percent() <= 0.0001) return 'f.is_torobpay_special=1';
    return "f.is_torobpay_special=1 AND COALESCE(f.limited_discount_channel,'torobpay') NOT IN ('torobpay','all')";
}

function torobpay_special_product(int $productId, ?PDO $pdo = null, bool $forUpdate = false): ?array
{
    if ($productId < 1) return null;
    $pdo ??= db();
    $stmt = $pdo->prepare(
        'SELECT p.id,p.title,p.sku,p.price,p.stock,p.status,p.product_type,p.torob_enabled,p.snappay_enabled,p.festival_enabled,p.category_id,p.brand_id,' .
        'c.is_active category_active,b.is_active brand_active ' .
        'FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.id=? LIMIT 1' .
        ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$productId]);
    return $stmt->fetch() ?: null;
}

function torobpay_special_basic_block_reason(?array $product, string $channel = 'site'): ?string
{
    $channel = limited_discount_normalize_channel($channel);
    if (!$product) return 'محصول انتخاب‌شده پیدا نشد.';
    if ((string)($product['status'] ?? '') !== 'active') return 'محصول باید فعال باشد.';
    if (empty($product['category_active']) || empty($product['brand_active'])) return 'دسته‌بندی و برند محصول باید فعال باشند.';
    if ((string)($product['product_type'] ?? 'simple') !== 'simple') return 'در این نسخه، تخفیف محدود فقط برای محصول ساده قابل ثبت است.';
    if ((int)($product['price'] ?? 0) < 1) return 'محصول باید قیمت اصلی معتبر داشته باشد.';
    if ((int)($product['stock'] ?? 0) < 1) return 'محصول ناموجود را نمی‌توان وارد تخفیف محدود کرد.';
    if (limited_discount_requires_torobpay($channel) && empty($product['torob_enabled'])) return 'برای کانال ترب‌پی، نمایش محصول در خروجی ترب باید فعال باشد.';
    if (limited_discount_requires_snappay($channel) && empty($product['snappay_enabled'])) return 'برای کانال اسنپ‌پی، مجازبودن محصول برای اسنپ‌پی باید فعال باشد.';
    if (!empty($product['festival_enabled'])) return 'این محصول از قبل جشنواره مستقیم یا ارسال رایگان جشنواره‌ای دارد.';
    return null;
}

function torobpay_special_overlap_festival(int $productId, string $startsAt, string $endsAt, int $ignoreFestivalId = 0, ?PDO $pdo = null): ?array
{
    if ($productId < 1) return null;
    $pdo ??= db();
    $stmt = $pdo->prepare(
        "SELECT f.id,f.title,f.starts_at,f.ends_at FROM festivals f
         JOIN products p ON p.id=?
         WHERE f.id<>? AND f.is_active=1 AND f.festival_type='price'
           AND f.starts_at<=? AND f.ends_at>=?
           AND (
             f.scope='all'
             OR (f.scope='category' AND f.target_id=p.category_id)
             OR (f.scope='brand' AND f.target_id=p.brand_id)
             OR (f.scope='product' AND f.target_id=p.id)
             OR (f.scope='group' AND EXISTS(
                 SELECT 1 FROM festival_products fp WHERE fp.festival_id=f.id AND fp.product_id=p.id
             ))
           )
         ORDER BY f.is_torobpay_special DESC,f.priority DESC,f.id DESC LIMIT 1"
    );
    $stmt->execute([$productId, max(0, $ignoreFestivalId), $endsAt, $startsAt]);
    return $stmt->fetch() ?: null;
}

function torobpay_special_conflict_for_target(string $scope, int $targetId, array $groupProductIds, string $startsAt, string $endsAt, ?PDO $pdo = null): ?array
{
    $pdo ??= db();
    $where = '';
    $params = [$endsAt, $startsAt];
    if ($scope === 'product' && $targetId > 0) {
        $where = 'p.id=?';
        $params[] = $targetId;
    } elseif ($scope === 'category' && $targetId > 0) {
        $where = 'p.category_id=?';
        $params[] = $targetId;
    } elseif ($scope === 'brand' && $targetId > 0) {
        $where = 'p.brand_id=?';
        $params[] = $targetId;
    } elseif ($scope === 'group') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $groupProductIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) return null;
        $where = 'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    } else {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT f.id,f.title,p.id product_id,p.title product_title
         FROM festivals f JOIN products p ON p.id=f.target_id
         WHERE f.is_torobpay_special=1 AND f.is_active=1
           AND f.starts_at<=? AND f.ends_at>=? AND {$where}
         ORDER BY f.starts_at,f.id LIMIT 1"
    );
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function torobpay_special_product_block_reason(int $productId, string $startsAt, string $endsAt, int $ignoreFestivalId = 0, ?PDO $pdo = null, string $channel = 'site'): ?string
{
    $pdo ??= db();
    $product = torobpay_special_product($productId, $pdo);
    $reason = torobpay_special_basic_block_reason($product, $channel);
    if ($reason !== null) return $reason;
    $overlap = torobpay_special_overlap_festival($productId, $startsAt, $endsAt, $ignoreFestivalId, $pdo);
    if ($overlap) return 'این محصول در بازه انتخابی عضو جشنواره «' . (string)$overlap['title'] . '» است.';
    return null;
}

function torobpay_special_eligible_products(string $startsAt, string $endsAt, int $limit = 500, ?PDO $pdo = null, string $channel = 'site'): array
{
    $pdo ??= db();
    $limit = max(1, min(2000, $limit));
    $channel = limited_discount_normalize_channel($channel);
    $channelWhere = limited_discount_requires_torobpay($channel) ? ' AND p.torob_enabled=1' : '';
    if (limited_discount_requires_snappay($channel)) $channelWhere .= ' AND p.snappay_enabled=1';
    $stmt = $pdo->prepare(
        "SELECT p.id,p.title,p.sku,p.price,p.stock,p.torob_enabled,p.snappay_enabled
         FROM products p
         JOIN categories c ON c.id=p.category_id AND c.is_active=1
         JOIN brands b ON b.id=p.brand_id AND b.is_active=1
         WHERE p.status='active' AND p.product_type='simple' AND p.price>0 AND p.stock>0
           AND p.festival_enabled=0{$channelWhere}
           AND NOT EXISTS(
             SELECT 1 FROM festivals f
             WHERE f.is_active=1 AND f.festival_type='price'
               AND f.starts_at<=? AND f.ends_at>=?
               AND (
                 f.scope='all'
                 OR (f.scope='category' AND f.target_id=p.category_id)
                 OR (f.scope='brand' AND f.target_id=p.brand_id)
                 OR (f.scope='product' AND f.target_id=p.id)
                 OR (f.scope='group' AND EXISTS(
                   SELECT 1 FROM festival_products fp WHERE fp.festival_id=f.id AND fp.product_id=p.id
                 ))
               )
           )
         ORDER BY p.title,p.id LIMIT {$limit}"
    );
    $stmt->execute([$endsAt, $startsAt]);
    return $stmt->fetchAll() ?: [];
}

function torobpay_special_campaign(int $festivalId, ?PDO $pdo = null): ?array
{
    if ($festivalId < 1) return null;
    $pdo ??= db();
    $stmt = $pdo->prepare(
        "SELECT f.*,p.title product_title,p.sku product_sku,p.price current_price,p.stock current_stock,
                p.status product_status,p.product_type,p.torob_enabled,p.snappay_enabled,p.festival_enabled,
                c.is_active category_active,b.is_active brand_active
         FROM festivals f
         LEFT JOIN products p ON p.id=f.target_id
         LEFT JOIN categories c ON c.id=p.category_id
         LEFT JOIN brands b ON b.id=p.brand_id
         WHERE f.id=? AND f.is_torobpay_special=1 LIMIT 1"
    );
    $stmt->execute([$festivalId]);
    return $stmt->fetch() ?: null;
}

function torobpay_special_live_campaign_for_product(int $productId, ?PDO $pdo = null): ?array
{
    if ($productId < 1) return null;
    $pdo ??= db();
    $stmt = $pdo->prepare(
        "SELECT * FROM festivals
         WHERE target_id=? AND is_torobpay_special=1 AND is_active=1 AND festival_type='price'
           AND scope='product' AND ends_at>=NOW()
         ORDER BY starts_at,id LIMIT 1"
    );
    $stmt->execute([$productId]);
    return $stmt->fetch() ?: null;
}

function torobpay_special_campaign_integrity(array $campaign, ?PDO $pdo = null): ?string
{
    $pdo ??= db();
    $channel = limited_discount_normalize_channel((string)($campaign['limited_discount_channel'] ?? 'torobpay'));
    if (limited_discount_requires_torobpay($channel) && torobpay_special_payment_markup_percent() > 0.0001) return 'درصد افزایش قیمت ترب‌پی بیشتر از صفر است؛ برای کانال ترب‌پی آن را صفر کنید.';
    $festivalId = max(0, (int)($campaign['id'] ?? 0));
    $productId = max(0, (int)($campaign['target_id'] ?? 0));
    $startsAt = (string)($campaign['starts_at'] ?? '');
    $endsAt = (string)($campaign['ends_at'] ?? '');
    $start = strtotime($startsAt);
    $end = strtotime($endsAt);
    if ($start === false || $end === false || $end <= $start) return 'بازه زمانی کمپین معتبر نیست.';
    if (($end - $start) > limited_discount_max_duration_seconds()) return 'بازه کمپین از سقف سه روز بیشتر است.';
    $oldPrice = max(0, (int)($campaign['torobpay_old_price'] ?? 0));
    $newPrice = max(0, (int)($campaign['torobpay_new_price'] ?? 0));
    if ($oldPrice < 1 || $newPrice < 1 || $newPrice >= $oldPrice) return 'قیمت قبل و بعد کمپین معتبر نیست.';
    $reason = torobpay_special_product_block_reason($productId, $startsAt, $endsAt, $festivalId, $pdo, $channel);
    if ($reason !== null) return $reason;
    $product = torobpay_special_product($productId, $pdo);
    if (!$product || (int)$product['price'] !== $oldPrice) return 'قیمت اصلی محصول پس از ثبت تخفیف تغییر کرده است؛ ابتدا تخفیف محدود را لغو و کانال‌های انتخاب‌شده را هماهنگ کنید.';
    return null;
}

function torobpay_special_create_campaign(array $input, array $actor = [], ?PDO $pdo = null): array
{
    $pdo ??= db();
    $productId = max(0, (int)($input['product_id'] ?? 0));
    $newPrice = max(0, (int)($input['new_price'] ?? 0));
    $startsAt = date('Y-m-d H:i:s', strtotime((string)($input['starts_at'] ?? '')) ?: 0);
    $endsAt = date('Y-m-d H:i:s', strtotime((string)($input['ends_at'] ?? '')) ?: 0);
    $start = strtotime($startsAt) ?: 0;
    $end = strtotime($endsAt) ?: 0;
    $reason = text_substr(trim((string)($input['popularity_reason'] ?? '')), 0, 180);
    $isActive = !empty($input['is_active']) ? 1 : 0;
    $channel = limited_discount_normalize_channel((string)($input['channel'] ?? 'site'));

    if ($productId < 1) throw new RuntimeException('محصول تخفیف محدود را انتخاب کنید.');
    if (limited_discount_requires_torobpay($channel) && torobpay_special_payment_markup_percent() > 0.0001) throw new RuntimeException('برای انتخاب کانال ترب‌پی، درصد افزایش قیمت ترب‌پی باید صفر باشد تا قیمت سایت، پنل و پرداخت یکسان بماند.');
    if ($start < 1 || $end <= $start) throw new RuntimeException('زمان پایان باید بعد از زمان شروع باشد.');
    if (($end - $start) > limited_discount_max_duration_seconds()) throw new RuntimeException('بازه تخفیف محدود نمی‌تواند بیشتر از سه روز باشد.');
    if ($end <= time()) throw new RuntimeException('زمان پایان تخفیف باید در آینده باشد.');
    if (text_length($reason) < 3) throw new RuntimeException('دلیل پربازدیدشدن یا جذابیت تخفیف را وارد کنید.');

    $pdo->beginTransaction();
    try {
        $product = torobpay_special_product($productId, $pdo, true);
        $blockReason = torobpay_special_basic_block_reason($product, $channel);
        if ($blockReason !== null) throw new RuntimeException($blockReason);
        $overlap = torobpay_special_overlap_festival($productId, $startsAt, $endsAt, 0, $pdo);
        if ($overlap) throw new RuntimeException('این محصول در بازه انتخابی عضو جشنواره «' . (string)$overlap['title'] . '» است.');
        $oldPrice = (int)$product['price'];
        if ($newPrice < 1 || $newPrice >= $oldPrice) throw new RuntimeException('قیمت بعد از تخفیف باید بیشتر از صفر و کمتر از قیمت اصلی محصول باشد.');
        $saving = $oldPrice - $newPrice;
        $title = text_substr('تخفیف محدود — ' . (string)$product['title'], 0, 180);
        $slug = 'limited-discount-' . slugify((string)$product['title']) . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $stmt = $pdo->prepare(
            'INSERT INTO festivals(title,slug,festival_type,scope,discount_type,discount_value,target_id,starts_at,ends_at,priority,banner_text,show_on_home,is_active,is_torobpay_special,limited_discount_channel,torobpay_old_price,torobpay_new_price,torobpay_stock_snapshot,torobpay_popularity_reason) ' .
            "VALUES(?,?,'price','product','fixed',?,?,?,?,900000,?,0,?,1,?,?,?,?,?)"
        );
        $stmt->execute([
            $title,$slug,$saving,$productId,$startsAt,$endsAt,'تخفیف محدود',$isActive,$channel,
            $oldPrice,$newPrice,max(0, (int)$product['stock']),$reason,
        ]);
        $festivalId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    if (function_exists('admin_audit_event')) {
        admin_audit_event('limited_discount_created', 'festival', $festivalId, $actor + [
            'product_id'=>$productId,'channel'=>$channel,'old_price'=>$oldPrice,'new_price'=>$newPrice,'starts_at'=>$startsAt,'ends_at'=>$endsAt,
        ]);
    }
    return torobpay_special_campaign($festivalId, $pdo) ?? ['id'=>$festivalId];
}

function torobpay_special_set_active(int $festivalId, bool $active, array $actor = [], ?PDO $pdo = null): array
{
    $pdo ??= db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM festivals WHERE id=? AND is_torobpay_special=1 FOR UPDATE');
        $lock->execute([$festivalId]);
        $campaign = $lock->fetch();
        if (!$campaign) throw new RuntimeException('تخفیف محدود پیدا نشد.');
        if ($active) {
            if ((strtotime((string)$campaign['ends_at']) ?: 0) <= time()) throw new RuntimeException('تخفیف پایان‌یافته را نمی‌توان دوباره فعال کرد.');
            $integrity = torobpay_special_campaign_integrity($campaign, $pdo);
            if ($integrity !== null) throw new RuntimeException($integrity);
        }
        $pdo->prepare('UPDATE festivals SET is_active=? WHERE id=?')->execute([$active ? 1 : 0, $festivalId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if (function_exists('admin_audit_event')) admin_audit_event('limited_discount_toggled', 'festival', $festivalId, $actor + ['is_active'=>$active ? 1 : 0]);
    return torobpay_special_campaign($festivalId, $pdo) ?? ['id'=>$festivalId,'is_active'=>$active ? 1 : 0];
}

function torobpay_special_delete_campaign(int $festivalId, array $actor = [], ?PDO $pdo = null): void
{
    $pdo ??= db();
    $stmt = $pdo->prepare('DELETE FROM festivals WHERE id=? AND is_torobpay_special=1');
    $stmt->execute([$festivalId]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('تخفیف محدود پیدا نشد.');
    if (function_exists('admin_audit_event')) admin_audit_event('limited_discount_deleted', 'festival', $festivalId, $actor);
}

function limited_discount_create_campaign(array $input, array $actor = [], ?PDO $pdo = null): array
{
    return torobpay_special_create_campaign($input, $actor, $pdo);
}

function limited_discount_set_active(int $festivalId, bool $active, array $actor = [], ?PDO $pdo = null): array
{
    return torobpay_special_set_active($festivalId, $active, $actor, $pdo);
}

function limited_discount_delete_campaign(int $festivalId, array $actor = [], ?PDO $pdo = null): void
{
    torobpay_special_delete_campaign($festivalId, $actor, $pdo);
}

function active_festivals(?PDO $pdo = null, ?string $festivalType = null): array
{
    $pdo ??= db();
    $now = date('Y-m-d H:i:s');
    $where = ["is_active=1", "starts_at<=?", "ends_at>=?"];
    $params = [$now, $now];
    if ($festivalType !== null) {
        $where[] = 'festival_type=?';
        $params[] = $festivalType;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM festivals WHERE ' . implode(' AND ', $where) . ' ORDER BY priority DESC,id DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $exception) {
        // خرابی بخش اختیاری جشنواره نباید فهرست یا صفحه محصول را از دسترس خارج کند.
        log_error($exception);
        return [];
    }
}

function current_free_shipping_festival(?PDO $pdo = null): ?array
{
    foreach (active_festivals($pdo, 'free_shipping') as $festival) return $festival;
    return null;
}

function configured_homepage_festival(?PDO $pdo = null, bool $activeNow = false): ?array
{
    $pdo ??= db();
    $where = ["show_on_home=1", "festival_type='price'", "scope='group'"];
    $params = [];
    if ($activeNow) {
        $where[] = 'is_active=1';
        $where[] = 'starts_at<=?';
        $where[] = 'ends_at>=?';
        $now = date('Y-m-d H:i:s');
        $params = [$now, $now];
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM festivals WHERE ' . implode(' AND ', $where) . ' ORDER BY priority DESC,id DESC LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    } catch (PDOException $exception) {
        log_error($exception);
        return null;
    }
}

function festival_group_memberships(array $festivalIds, array $productIds, ?PDO $pdo = null): array
{
    if (!$festivalIds || !$productIds) return [];
    $pdo ??= db();
    $festivalIds = array_values(array_unique(array_map('intval', $festivalIds)));
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    $festivalMarks = implode(',', array_fill(0, count($festivalIds), '?'));
    $productMarks = implode(',', array_fill(0, count($productIds), '?'));
    try {
        $stmt = $pdo->prepare("SELECT festival_id,product_id FROM festival_products WHERE festival_id IN ($festivalMarks) AND product_id IN ($productMarks)");
        $stmt->execute(array_merge($festivalIds, $productIds));
        $memberships = [];
        foreach ($stmt->fetchAll() as $row) $memberships[(int)$row['festival_id']][(int)$row['product_id']] = true;
        return $memberships;
    } catch (PDOException $exception) {
        log_error($exception);
        return [];
    }
}

function festival_matches_product(array $festival, array $product, array $memberships): bool
{
    $scope = (string)$festival['scope'];
    $targetId = (int)($festival['target_id'] ?? 0);
    return match ($scope) {
        'all' => true,
        'category' => $targetId > 0 && $targetId === (int)($product['category_id'] ?? 0),
        'brand' => $targetId > 0 && $targetId === (int)($product['brand_id'] ?? 0),
        'product' => $targetId > 0 && $targetId === (int)($product['id'] ?? 0),
        'group' => !empty($memberships[(int)$festival['id']][(int)($product['id'] ?? 0)]),
        default => false,
    };
}

function festival_price_result(int $basePrice, array $festival): ?array
{
    if ($basePrice < 1) return null;
    if (!empty($festival['is_torobpay_special'])) {
        $channel = limited_discount_normalize_channel((string)($festival['limited_discount_channel'] ?? 'torobpay'));
        if (limited_discount_requires_torobpay($channel) && torobpay_special_payment_markup_percent() > 0.0001) return null;
        $oldPrice = max(0, (int)($festival['torobpay_old_price'] ?? 0));
        $newPrice = max(0, (int)($festival['torobpay_new_price'] ?? 0));
        if ($oldPrice !== $basePrice || $newPrice < 1 || $newPrice >= $oldPrice) return null;
        $saving = $oldPrice - $newPrice;
        $percent = torobpay_special_discount_percent($oldPrice, $newPrice);
        $percentLabel = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        return [
            'price' => $newPrice,
            'saving' => $saving,
            'label' => fa_number($percentLabel) . '٪ تخفیف محدود',
        ];
    }
    $value = max(0, (int)$festival['discount_value']);
    $discountType = (string)$festival['discount_type'];
    if ($discountType === 'percent') {
        if ($value < 1 || $value > 99) return null;
        $saving = intdiv($basePrice * $value, 100);
        $label = fa_number($value) . '٪ جشنواره';
    } elseif ($discountType === 'fixed') {
        if ($value < 1) return null;
        $saving = min($value, $basePrice - 1);
        $label = fa_number(format_price($saving)) . ' تومان جشنواره';
    } else {
        return null;
    }
    if ($saving < 1) return null;
    return [
        'price' => max(1, $basePrice - $saving),
        'saving' => $saving,
        'label' => $label,
    ];
}


/**
 * جشنواره مستقیم محصول که از فرم افزودن/ویرایش محصول ثبت می‌شود.
 * برای محصول متغیر، نسبت قیمت جشنواره به قیمت مرجع روی قیمت هر مدل اعمال می‌شود.
 */
function direct_product_festival_result(array $product, int $basePrice): ?array
{
    if (empty($product['festival_enabled']) || $basePrice < 1) return null;
    $referencePrice = max(0, (int)($product['festival_reference_price'] ?? $product['price'] ?? 0));
    $festivalPrice = max(0, (int)($product['festival_price'] ?? 0));
    if ($referencePrice < 1 || $festivalPrice < 1 || $festivalPrice >= $referencePrice) return null;

    $effectivePrice = $basePrice === $referencePrice
        ? $festivalPrice
        : max(1, min($basePrice - 1, (int)round(($basePrice * $festivalPrice) / $referencePrice)));
    $saving = $basePrice - $effectivePrice;
    if ($saving < 1) return null;
    $percent = max(1, min(99, (int)round(($saving / $basePrice) * 100)));

    return [
        'id' => null,
        'title' => 'جشنواره',
        'banner_text' => !empty($product['festival_free_shipping']) ? 'قیمت جشنواره‌ای همراه با ارسال رایگان' : 'قیمت ویژه جشنواره',
        'priority' => 1000000,
        'price' => $effectivePrice,
        'saving' => $saving,
        'label' => fa_number($percent) . '٪ جشنواره',
        'direct' => true,
    ];
}

function apply_active_festivals_to_products(array $products, ?PDO $pdo = null): array
{
    if (!$products) return [];
    $pdo ??= db();
    $festivals = active_festivals($pdo, 'price');
    $groupIds = [];
    foreach ($festivals as $festival) if ($festival['scope'] === 'group') $groupIds[] = (int)$festival['id'];
    $productIds = array_map(static fn(array $product): int => (int)$product['id'], $products);
    $memberships = festival_group_memberships($groupIds, $productIds, $pdo);

    foreach ($products as &$product) {
        $basePrice = max(0, (int)($product['base_price'] ?? $product['price'] ?? 0));
        $product['base_price'] = $basePrice;
        $product['price'] = $basePrice;
        $product['old_price'] = null;
        $product['festival_id'] = null;
        $product['festival_title'] = !empty($product['festival_enabled']) ? 'جشنواره' : null;
        $product['festival_label'] = !empty($product['festival_free_shipping']) ? 'ارسال رایگان جشنواره' : null;
        $product['festival_saving'] = 0;
        $product['festival_banner_text'] = !empty($product['festival_free_shipping']) ? 'ارسال رایگان ویژه این محصول' : null;
        $product['is_limited_discount'] = false;
        $product['limited_discount_ends_at'] = null;
        $product['limited_discount_channel'] = null;
        $best = direct_product_festival_result($product, $basePrice);

        foreach ($festivals as $festival) {
            if (!festival_matches_product($festival, $product, $memberships)) continue;
            $priceResult = festival_price_result($basePrice, $festival);
            if ($priceResult === null) continue;
            $candidate = $festival + $priceResult;
            if (
                $best === null
                || $candidate['saving'] > $best['saving']
                || ($candidate['saving'] === $best['saving'] && (int)$candidate['priority'] > (int)$best['priority'])
                || ($candidate['saving'] === $best['saving'] && (int)$candidate['priority'] === (int)$best['priority'] && (int)$candidate['id'] > (int)($best['id'] ?? 0))
            ) $best = $candidate;
        }
        if ($best !== null) {
            $product['price'] = (int)$best['price'];
            $product['old_price'] = $basePrice;
            $product['festival_id'] = isset($best['id']) && $best['id'] !== null ? (int)$best['id'] : null;
            $product['festival_title'] = (string)$best['title'];
            $product['festival_label'] = (string)$best['label'];
            $product['festival_saving'] = (int)$best['saving'];
            $product['festival_banner_text'] = trim((string)($best['banner_text'] ?? '')) ?: (string)$best['title'];
            $product['is_limited_discount'] = !empty($best['is_torobpay_special']);
            if ($product['is_limited_discount']) {
                $product['limited_discount_ends_at'] = (string)($best['ends_at'] ?? '');
                $product['limited_discount_channel'] = limited_discount_normalize_channel((string)($best['limited_discount_channel'] ?? 'torobpay'));
            }
        }
    }
    unset($product);
    return $products;
}

function festival_shipping_quote(?PDO $pdo = null, array $products = []): array
{
    $festival = current_free_shipping_festival($pdo);
    if ($festival) {
        return [
            'amount' => 0,
            'mode' => 'festival_free',
            'label' => 'ارسال رایگان جشنواره',
            'description' => trim((string)($festival['banner_text'] ?? '')) ?: (string)$festival['title'],
            'festival_id' => (int)$festival['id'],
            'festival_title' => (string)$festival['title'],
        ];
    }

    $eligibleLines = 0;
    $totalLines = 0;
    foreach ($products as $product) {
        if ((int)($product['quantity'] ?? $product['qty'] ?? 1) < 1) continue;
        $totalLines++;
        if (!empty($product['festival_enabled']) && !empty($product['festival_free_shipping'])) $eligibleLines++;
    }
    if ($totalLines > 0 && $eligibleLines === $totalLines) {
        return [
            'amount' => 0,
            'mode' => 'product_festival_free',
            'label' => 'ارسال رایگان جشنواره',
            'description' => 'تمام کالاهای این سفارش دارای ارسال رایگان جشنواره‌ای هستند.',
            'festival_id' => null,
            'festival_title' => 'جشنواره محصولات',
        ];
    }
    if ($eligibleLines > 0) {
        return [
            'amount' => 0,
            'mode' => 'mixed_product_festival',
            'label' => 'ارسال ترکیبی',
            'description' => 'ارسال کالاهای جشنواره‌ای رایگان است و هزینه حمل سایر کالاها هنگام تحویل دریافت می‌شود.',
            'festival_id' => null,
            'festival_title' => 'جشنواره محصولات',
        ];
    }
    return [
        'amount' => 0,
        'mode' => 'collect',
        'label' => 'پس‌کرایه',
        'description' => 'هزینه حمل هنگام تحویل و طبق تعرفه شرکت حمل پرداخت می‌شود.',
        'festival_id' => null,
        'festival_title' => null,
    ];
}

function public_active_festivals(?PDO $pdo = null): array
{
    $pdo ??= db();
    $rows = active_festivals($pdo);
    $items = [];
    foreach ($rows as $festival) {
        $items[] = [
            'id' => (int)$festival['id'],
            'title' => (string)$festival['title'],
            'type' => (string)$festival['festival_type'],
            'scope' => (string)$festival['scope'],
            'banner_text' => trim((string)($festival['banner_text'] ?? '')),
            'ends_at' => (string)$festival['ends_at'],
        ];
    }
    return $items;
}
