<?php
declare(strict_types=1);

const PRICE_ADJUSTMENT_MAX_BASIS_POINTS = 10_000;

/** @return array<string,string> */
function price_adjustment_direction_labels(): array
{
    return ['increase'=>'افزایش','decrease'=>'کاهش'];
}

function price_adjustment_normalize_direction(string $direction): string
{
    if (!array_key_exists($direction, price_adjustment_direction_labels())) {
        throw new RuntimeException('نوع تغییر قیمت معتبر نیست.');
    }
    return $direction;
}

/** @return array{basis_points:int,percent:string} */
function price_adjustment_parse_percent(string $raw, string $direction): array
{
    $direction = price_adjustment_normalize_direction($direction);
    $raw = trim(normalize_digits($raw));
    $raw = str_replace(['٪','%',' '], '', $raw);
    $raw = str_replace(['٫',','], '.', $raw);
    if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $raw)) {
        throw new RuntimeException('درصد باید عددی با حداکثر دو رقم اعشار باشد؛ مانند ۱۰ یا ۷٫۵.');
    }

    [$whole,$fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $basisPoints = ((int)$whole * 100) + (int)str_pad(substr($fraction, 0, 2), 2, '0');
    if ($basisPoints < 1 || $basisPoints > PRICE_ADJUSTMENT_MAX_BASIS_POINTS) {
        throw new RuntimeException('درصد تغییر باید بین ۰٫۰۱ تا ۱۰۰ باشد.');
    }
    if ($direction === 'decrease' && $basisPoints >= PRICE_ADJUSTMENT_MAX_BASIS_POINTS) {
        throw new RuntimeException('درصد کاهش باید کمتر از ۱۰۰ باشد تا قیمت محصول صفر نشود.');
    }

    return ['basis_points'=>$basisPoints,'percent'=>price_adjustment_percent_value($basisPoints)];
}

function price_adjustment_percent_value(int $basisPoints): string
{
    $basisPoints = max(0, min(PRICE_ADJUSTMENT_MAX_BASIS_POINTS, $basisPoints));
    $value = number_format($basisPoints / 100, 2, '.', '');
    return rtrim(rtrim($value, '0'), '.') ?: '0';
}

function price_adjustment_percent_label(int $basisPoints): string
{
    return fa_number(str_replace('.', '٫', price_adjustment_percent_value($basisPoints))) . '٪';
}

function price_adjustment_calculate(int $amount, string $direction, int $basisPoints): int
{
    $direction = price_adjustment_normalize_direction($direction);
    if ($amount < 0 || $amount > MAX_MONEY_AMOUNT) throw new RuntimeException('قیمت فعلی خارج از محدوده مجاز است.');
    if ($amount === 0) return 0;
    if ($basisPoints < 1 || $basisPoints > PRICE_ADJUSTMENT_MAX_BASIS_POINTS) throw new RuntimeException('درصد تغییر معتبر نیست.');
    if ($direction === 'decrease' && $basisPoints >= PRICE_ADJUSTMENT_MAX_BASIS_POINTS) throw new RuntimeException('کاهش صددرصدی مجاز نیست.');

    $multiplier = $direction === 'increase'
        ? PRICE_ADJUSTMENT_MAX_BASIS_POINTS + $basisPoints
        : PRICE_ADJUSTMENT_MAX_BASIS_POINTS - $basisPoints;
    $result = intdiv(($amount * $multiplier) + 5_000, PRICE_ADJUSTMENT_MAX_BASIS_POINTS);
    $result = max(1, $result);
    if ($result > MAX_MONEY_AMOUNT) throw new RuntimeException('حداقل یکی از قیمت‌ها پس از افزایش از سقف مجاز بیشتر می‌شود.');
    return $result;
}

function price_adjustment_compare_price(?int $value, int $newBasePrice, string $direction, int $basisPoints): ?int
{
    if ($value === null || $value < 1) return null;
    $next = price_adjustment_calculate($value, $direction, $basisPoints);
    return $next > $newBasePrice ? $next : null;
}

function price_adjustment_festival_price(?int $value, int $newBasePrice, string $direction, int $basisPoints): ?int
{
    if ($value === null || $value < 1 || $newBasePrice < 2) return null;
    $next = price_adjustment_calculate($value, $direction, $basisPoints);
    return max(1, min($newBasePrice - 1, $next));
}

function price_adjustment_nullable_int(mixed $value): ?int
{
    return $value === null ? null : (int)$value;
}

function price_adjustment_nullable_int_equal(mixed $left, mixed $right): bool
{
    return price_adjustment_nullable_int($left) === price_adjustment_nullable_int($right);
}

/** @return array{products:array<int,array<string,mixed>>,variants:array<int,array<string,mixed>>,locked:array<int,int>,signature:string} */
function price_adjustment_catalog_state(PDO $pdo, bool $forUpdate = false): array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $products = $pdo->query(
        'SELECT id,title,sku,product_type,status,price,old_price,festival_enabled,festival_price '
        . 'FROM products ORDER BY id' . $lock
    )->fetchAll() ?: [];
    $variants = $pdo->query(
        "SELECT v.id,v.product_id,v.sku,v.status,v.price,v.old_price FROM product_variants v "
        . "JOIN products p ON p.id=v.product_id AND p.product_type='variable' ORDER BY v.id" . $lock
    )->fetchAll() ?: [];
    $lockedRows = $pdo->query(
        "SELECT id,target_id FROM festivals WHERE is_torobpay_special=1 AND is_active=1 "
        . "AND festival_type='price' AND scope='product' AND ends_at>=NOW() ORDER BY id" . $lock
    )->fetchAll() ?: [];
    $locked = [];
    foreach ($lockedRows as $row) {
        $productId = max(0, (int)($row['target_id'] ?? 0));
        if ($productId > 0) $locked[$productId] = (int)$row['id'];
    }

    $signatureRows = ['products'=>[],'variants'=>[],'locked'=>$locked];
    foreach ($products as $row) {
        $signatureRows['products'][] = [
            (int)$row['id'],(string)$row['product_type'],(int)$row['price'],
            price_adjustment_nullable_int($row['old_price']),
            (int)$row['festival_enabled'],price_adjustment_nullable_int($row['festival_price']),
        ];
    }
    foreach ($variants as $row) {
        $signatureRows['variants'][] = [
            (int)$row['id'],(int)$row['product_id'],(string)$row['status'],(int)$row['price'],
            price_adjustment_nullable_int($row['old_price']),
        ];
    }
    $encoded = json_encode($signatureRows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);

    return [
        'products'=>$products,
        'variants'=>$variants,
        'locked'=>$locked,
        'signature'=>hash('sha256', is_string($encoded) ? $encoded : ''),
    ];
}

/**
 * @param array{products:array<int,array<string,mixed>>,variants:array<int,array<string,mixed>>,locked:array<int,int>,signature:string} $state
 * @return array<string,mixed>
 */
function price_adjustment_build_plan(array $state, string $direction, int $basisPoints): array
{
    $direction = price_adjustment_normalize_direction($direction);
    $variantsByProduct = [];
    foreach ($state['variants'] as $variant) $variantsByProduct[(int)$variant['product_id']][] = $variant;

    $productPlans = [];
    $variantPlans = [];
    $samples = [];
    $lockedCount = 0;
    $zeroPriceCount = 0;
    $unchangedCount = 0;
    $simpleCount = 0;
    $variableCount = 0;

    foreach ($state['products'] as $product) {
        $productId = (int)$product['id'];
        if (isset($state['locked'][$productId])) {
            $lockedCount++;
            continue;
        }
        $type = (string)($product['product_type'] ?? 'simple');
        $oldProductPrice = max(0, (int)$product['price']);
        $oldComparePrice = price_adjustment_nullable_int($product['old_price']);
        $oldFestivalPrice = price_adjustment_nullable_int($product['festival_price']);

        if ($type !== 'variable') {
            if ($oldProductPrice < 1) {
                $zeroPriceCount++;
                continue;
            }
            $newProductPrice = price_adjustment_calculate($oldProductPrice, $direction, $basisPoints);
            $newComparePrice = price_adjustment_compare_price($oldComparePrice, $newProductPrice, $direction, $basisPoints);
            $newFestivalPrice = price_adjustment_festival_price($oldFestivalPrice, $newProductPrice, $direction, $basisPoints);
            if ($newProductPrice === $oldProductPrice && $newComparePrice === $oldComparePrice && $newFestivalPrice === $oldFestivalPrice) {
                $unchangedCount++;
                continue;
            }
            $productPlans[] = [
                'id'=>$productId,'title'=>(string)$product['title'],'sku'=>(string)$product['sku'],'type'=>'simple',
                'price_before'=>$oldProductPrice,'price_after'=>$newProductPrice,
                'compare_before'=>$oldComparePrice,'compare_after'=>$newComparePrice,
                'festival_before'=>$oldFestivalPrice,'festival_after'=>$newFestivalPrice,
            ];
            $simpleCount++;
            if (count($samples) < 6) $samples[] = ['title'=>(string)$product['title'],'before'=>$oldProductPrice,'after'=>$newProductPrice,'kind'=>'محصول ساده'];
            continue;
        }

        $productVariantPlans = [];
        $activePricesAfter = [];
        $positiveVariantCount = 0;
        foreach ($variantsByProduct[$productId] ?? [] as $variant) {
            $oldVariantPrice = max(0, (int)$variant['price']);
            if ($oldVariantPrice < 1) continue;
            $positiveVariantCount++;
            $newVariantPrice = price_adjustment_calculate($oldVariantPrice, $direction, $basisPoints);
            $oldVariantCompare = price_adjustment_nullable_int($variant['old_price']);
            $newVariantCompare = price_adjustment_compare_price($oldVariantCompare, $newVariantPrice, $direction, $basisPoints);
            if ((string)$variant['status'] === 'active') $activePricesAfter[] = $newVariantPrice;
            if ($newVariantPrice === $oldVariantPrice && $newVariantCompare === $oldVariantCompare) continue;
            $productVariantPlans[] = [
                'id'=>(int)$variant['id'],'product_id'=>$productId,'sku'=>(string)$variant['sku'],
                'price_before'=>$oldVariantPrice,'price_after'=>$newVariantPrice,
                'compare_before'=>$oldVariantCompare,'compare_after'=>$newVariantCompare,
            ];
        }
        if (!$productVariantPlans) {
            if ($positiveVariantCount > 0) $unchangedCount++;
            else $zeroPriceCount++;
            continue;
        }

        $newProductPrice = $activePricesAfter ? min($activePricesAfter) : $oldProductPrice;
        $newComparePrice = price_adjustment_compare_price($oldComparePrice, $newProductPrice, $direction, $basisPoints);
        $newFestivalPrice = price_adjustment_festival_price($oldFestivalPrice, $newProductPrice, $direction, $basisPoints);
        $productPlans[] = [
            'id'=>$productId,'title'=>(string)$product['title'],'sku'=>(string)$product['sku'],'type'=>'variable',
            'price_before'=>$oldProductPrice,'price_after'=>$newProductPrice,
            'compare_before'=>$oldComparePrice,'compare_after'=>$newComparePrice,
            'festival_before'=>$oldFestivalPrice,'festival_after'=>$newFestivalPrice,
        ];
        foreach ($productVariantPlans as $variantPlan) $variantPlans[] = $variantPlan;
        $variableCount++;
        if (count($samples) < 6) $samples[] = ['title'=>(string)$product['title'],'before'=>$oldProductPrice,'after'=>$newProductPrice,'kind'=>'محصول متغیر'];
    }

    return [
        'direction'=>$direction,
        'basis_points'=>$basisPoints,
        'percent'=>price_adjustment_percent_value($basisPoints),
        'signature'=>(string)$state['signature'],
        'products'=>$productPlans,
        'variants'=>$variantPlans,
        'product_count'=>count($productPlans),
        'variant_count'=>count($variantPlans),
        'simple_count'=>$simpleCount,
        'variable_count'=>$variableCount,
        'locked_count'=>$lockedCount,
        'zero_price_count'=>$zeroPriceCount,
        'unchanged_count'=>$unchangedCount,
        'samples'=>$samples,
    ];
}

/** @return array<string,mixed> */
function price_adjustment_preview(string $direction, int $basisPoints, ?PDO $pdo = null): array
{
    $pdo ??= db();
    return price_adjustment_build_plan(price_adjustment_catalog_state($pdo, false), $direction, $basisPoints);
}

/** @param array<string,mixed> $actor @return array<string,mixed> */
function price_adjustment_apply(string $direction, int $basisPoints, string $expectedSignature, array $actor = [], ?PDO $pdo = null): array
{
    $pdo ??= db();
    $direction = price_adjustment_normalize_direction($direction);
    if (!preg_match('/^[a-f0-9]{64}$/', $expectedSignature)) throw new RuntimeException('پیش‌نمایش تغییر قیمت معتبر نیست.');
    $source = (string)($actor['source'] ?? 'admin') === 'bale' ? 'bale' : 'admin';
    $actorLabel = text_substr(trim((string)($actor['label'] ?? $actor['username'] ?? '')), 0, 190);
    $adminId = max(0, (int)($actor['admin_id'] ?? 0));

    $pdo->beginTransaction();
    try {
        $state = price_adjustment_catalog_state($pdo, true);
        if (!hash_equals($expectedSignature, (string)$state['signature'])) {
            throw new RuntimeException('قیمت یا وضعیت بعضی محصولات بعد از پیش‌نمایش تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.');
        }
        $plan = price_adjustment_build_plan($state, $direction, $basisPoints);
        if ((int)$plan['product_count'] < 1) throw new RuntimeException('با این درصد، هیچ قیمت واجد شرایطی تغییر نمی‌کند.');

        $insertBatch = $pdo->prepare(
            'INSERT INTO price_adjustment_batches(direction,basis_points,status,source,actor_label,product_count,variant_count,skipped_count,created_by,created_at) '
            . "VALUES(?,?,'applied',?,?,?,?,?,?,NOW())"
        );
        $insertBatch->execute([
            $direction,$basisPoints,$source,$actorLabel !== '' ? $actorLabel : null,
            (int)$plan['product_count'],(int)$plan['variant_count'],(int)$plan['locked_count'],$adminId > 0 ? $adminId : null,
        ]);
        $batchId = (int)$pdo->lastInsertId();

        $insertItem = $pdo->prepare(
            'INSERT INTO price_adjustment_items(batch_id,item_type,product_id,variant_id,price_before,price_after,compare_price_before,compare_price_after,festival_price_before,festival_price_after) '
            . 'VALUES(?,?,?,?,?,?,?,?,?,?)'
        );
        $updateVariant = $pdo->prepare('UPDATE product_variants SET price=?,old_price=? WHERE id=? AND product_id=?');
        foreach ($plan['variants'] as $variant) {
            $insertItem->execute([
                $batchId,'variant',(int)$variant['product_id'],(int)$variant['id'],
                (int)$variant['price_before'],(int)$variant['price_after'],$variant['compare_before'],$variant['compare_after'],null,null,
            ]);
            $updateVariant->execute([(int)$variant['price_after'],$variant['compare_after'],(int)$variant['id'],(int)$variant['product_id']]);
        }

        $updateProduct = $pdo->prepare('UPDATE products SET price=?,old_price=?,festival_price=? WHERE id=?');
        foreach ($plan['products'] as $product) {
            $insertItem->execute([
                $batchId,'product',(int)$product['id'],null,
                (int)$product['price_before'],(int)$product['price_after'],$product['compare_before'],$product['compare_after'],$product['festival_before'],$product['festival_after'],
            ]);
            $updateProduct->execute([(int)$product['price_after'],$product['compare_after'],$product['festival_after'],(int)$product['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    foreach ($plan['products'] as $product) {
        if (function_exists('product_alert_wake_for_product')) product_alert_wake_for_product($pdo, (int)$product['id'], null);
    }
    if (function_exists('admin_audit_event')) {
        admin_audit_event('bulk_price_adjustment_applied', 'price_adjustment', $batchId, $actor + [
            'direction'=>$direction,'basis_points'=>$basisPoints,'product_count'=>(int)$plan['product_count'],
            'variant_count'=>(int)$plan['variant_count'],'skipped_count'=>(int)$plan['locked_count'],
        ]);
    }
    return price_adjustment_batch($batchId, $pdo) ?? ['id'=>$batchId] + $plan;
}

/** @return array<string,mixed>|null */
function price_adjustment_batch(int $batchId, ?PDO $pdo = null): ?array
{
    if ($batchId < 1) return null;
    $pdo ??= db();
    $stmt = $pdo->prepare('SELECT * FROM price_adjustment_batches WHERE id=? LIMIT 1');
    $stmt->execute([$batchId]);
    return $stmt->fetch() ?: null;
}

/** @return array<string,mixed>|null */
function price_adjustment_latest_applied_batch(?PDO $pdo = null): ?array
{
    $pdo ??= db();
    $row = $pdo->query("SELECT * FROM price_adjustment_batches WHERE status='applied' ORDER BY id DESC LIMIT 1")->fetch();
    return $row ?: null;
}

/** @return array<int,array<string,mixed>> */
function price_adjustment_history(int $limit = 30, ?PDO $pdo = null): array
{
    $pdo ??= db();
    $limit = max(1, min(100, $limit));
    return $pdo->query('SELECT * FROM price_adjustment_batches ORDER BY id DESC LIMIT ' . $limit)->fetchAll() ?: [];
}

/** @param array<string,mixed> $actor @return array<string,mixed> */
function price_adjustment_revert(int $batchId, array $actor = [], ?PDO $pdo = null): array
{
    if ($batchId < 1) throw new RuntimeException('سابقه تغییر قیمت معتبر نیست.');
    $pdo ??= db();
    $adminId = max(0, (int)($actor['admin_id'] ?? 0));
    $actorLabel = text_substr(trim((string)($actor['label'] ?? $actor['username'] ?? '')), 0, 190);

    $pdo->beginTransaction();
    try {
        $latest = $pdo->query("SELECT id FROM price_adjustment_batches WHERE status='applied' ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
        if ((int)$latest !== $batchId) throw new RuntimeException('فقط آخرین تغییر گروهیِ فعال قابل بازگردانی است.');
        $batchStmt = $pdo->prepare("SELECT * FROM price_adjustment_batches WHERE id=? AND status='applied' LIMIT 1 FOR UPDATE");
        $batchStmt->execute([$batchId]);
        $batch = $batchStmt->fetch();
        if (!$batch) throw new RuntimeException('این تغییر قبلاً بازگردانی شده یا وجود ندارد.');

        $itemsStmt = $pdo->prepare('SELECT * FROM price_adjustment_items WHERE batch_id=? ORDER BY item_type DESC,id');
        $itemsStmt->execute([$batchId]);
        $items = $itemsStmt->fetchAll() ?: [];
        if (!$items) throw new RuntimeException('جزئیات لازم برای بازگردانی این تغییر وجود ندارد.');

        $productItems = [];
        $variantItems = [];
        foreach ($items as $item) {
            if ((string)$item['item_type'] === 'variant') $variantItems[] = $item;
            else $productItems[] = $item;
        }

        $lockedProducts = [];
        $selectProduct = $pdo->prepare('SELECT price,old_price,festival_price FROM products WHERE id=? LIMIT 1 FOR UPDATE');
        foreach ($productItems as $item) {
            $productId = (int)$item['product_id'];
            $selectProduct->execute([$productId]);
            $current = $selectProduct->fetch();
            if (!$current) throw new RuntimeException('یکی از محصولات این سابقه حذف شده و بازگردانی امن ممکن نیست.');
            if (
                (int)$current['price'] !== (int)$item['price_after']
                || !price_adjustment_nullable_int_equal($current['old_price'], $item['compare_price_after'])
                || !price_adjustment_nullable_int_equal($current['festival_price'], $item['festival_price_after'])
            ) throw new RuntimeException('قیمت یکی از محصولات بعداً به‌صورت دستی تغییر کرده است؛ برای جلوگیری از حذف تغییر جدید، بازگردانی متوقف شد.');
            $lockedProducts[$productId] = true;
        }

        $selectVariant = $pdo->prepare('SELECT price,old_price FROM product_variants WHERE id=? AND product_id=? LIMIT 1 FOR UPDATE');
        foreach ($variantItems as $item) {
            $selectVariant->execute([(int)$item['variant_id'],(int)$item['product_id']]);
            $current = $selectVariant->fetch();
            if (!$current) throw new RuntimeException('یکی از مدل‌های محصول حذف شده و بازگردانی امن ممکن نیست.');
            if (
                (int)$current['price'] !== (int)$item['price_after']
                || !price_adjustment_nullable_int_equal($current['old_price'], $item['compare_price_after'])
            ) throw new RuntimeException('قیمت یکی از مدل‌ها بعداً تغییر کرده است؛ برای جلوگیری از حذف تغییر جدید، بازگردانی متوقف شد.');
        }

        $updateVariant = $pdo->prepare('UPDATE product_variants SET price=?,old_price=? WHERE id=? AND product_id=?');
        foreach ($variantItems as $item) {
            $updateVariant->execute([(int)$item['price_before'],$item['compare_price_before'],(int)$item['variant_id'],(int)$item['product_id']]);
        }
        $updateProduct = $pdo->prepare('UPDATE products SET price=?,old_price=?,festival_price=? WHERE id=?');
        foreach ($productItems as $item) {
            $updateProduct->execute([(int)$item['price_before'],$item['compare_price_before'],$item['festival_price_before'],(int)$item['product_id']]);
        }
        $updateBatch = $pdo->prepare("UPDATE price_adjustment_batches SET status='reverted',reverted_by=?,reverted_actor_label=?,reverted_at=NOW() WHERE id=? AND status='applied'");
        $updateBatch->execute([$adminId > 0 ? $adminId : null,$actorLabel !== '' ? $actorLabel : null,$batchId]);
        if ($updateBatch->rowCount() !== 1) throw new RuntimeException('ثبت بازگردانی انجام نشد.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    foreach (array_keys($lockedProducts) as $productId) {
        if (function_exists('product_alert_wake_for_product')) product_alert_wake_for_product($pdo, (int)$productId, null);
    }
    if (function_exists('admin_audit_event')) {
        admin_audit_event('bulk_price_adjustment_reverted', 'price_adjustment', $batchId, $actor + [
            'direction'=>(string)$batch['direction'],'basis_points'=>(int)$batch['basis_points'],
            'product_count'=>(int)$batch['product_count'],'variant_count'=>(int)$batch['variant_count'],
        ]);
    }
    return price_adjustment_batch($batchId, $pdo) ?? ['id'=>$batchId,'status'=>'reverted'];
}
