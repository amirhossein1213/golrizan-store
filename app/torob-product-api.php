<?php
declare(strict_types=1);

/**
 * Torob Product API v3
 *
 * This module is intentionally independent from TorobPay CPG credentials.
 * Product-sync requests are authenticated only with Torob's Ed25519-signed JWT.
 */

const TOROB_API_V3_NAME = 'torob_api_v3';
const TOROB_API_V3_PAGE_SIZE = 100;
const TOROB_API_V3_TOKEN_VERSION = '1';
const TOROB_API_V3_PUBLIC_KEY_RAW_BASE64 = 't6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=';

final class TorobApiAuthenticationException extends RuntimeException
{
}

final class TorobApiConfigurationException extends RuntimeException
{
}

final class TorobApiRequestException extends InvalidArgumentException
{
}

function torob_api_v3_endpoint_url(): string
{
    return app_url('api/torob-products-v3.php');
}

function torob_api_v3_is_supported(): bool
{
    return extension_loaded('sodium') && function_exists('sodium_crypto_sign_verify_detached');
}

function torob_api_v3_base64url_decode(string $value): string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        throw new TorobApiAuthenticationException('Malformed JWT segment.');
    }
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    if (!is_string($decoded)) {
        throw new TorobApiAuthenticationException('Malformed JWT encoding.');
    }
    return $decoded;
}

function torob_api_v3_integer_claim(mixed $value, string $name): int
{
    if (is_int($value)) return $value;
    if (is_string($value) && preg_match('/^\d{1,20}$/D', $value) === 1) return (int)$value;
    throw new TorobApiAuthenticationException('Invalid JWT claim: ' . $name);
}

/** @return array<string,mixed> */
function torob_api_v3_verify_token(string $jwt, string $tokenVersion, ?string $expectedAudience = null): array
{
    if (!torob_api_v3_is_supported()) {
        throw new TorobApiConfigurationException('PHP Sodium is required for Torob JWT validation.');
    }
    if (!hash_equals(TOROB_API_V3_TOKEN_VERSION, trim($tokenVersion))) {
        throw new TorobApiAuthenticationException('Unsupported Torob token version.');
    }
    if ($jwt === '' || strlen($jwt) > 16384) {
        throw new TorobApiAuthenticationException('Missing or oversized Torob token.');
    }
    $segments = explode('.', $jwt);
    if (count($segments) !== 3) {
        throw new TorobApiAuthenticationException('Malformed JWT.');
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
    try {
        $header = json_decode(torob_api_v3_base64url_decode($encodedHeader), true, 16, JSON_THROW_ON_ERROR);
        $payload = json_decode(torob_api_v3_base64url_decode($encodedPayload), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new TorobApiAuthenticationException('Malformed JWT JSON.', 0, $exception);
    }
    if (!is_array($header) || !is_array($payload)) {
        throw new TorobApiAuthenticationException('Malformed JWT data.');
    }
    if (($header['alg'] ?? null) !== 'EdDSA') {
        throw new TorobApiAuthenticationException('Unexpected JWT algorithm.');
    }
    if (isset($header['typ']) && strtoupper((string)$header['typ']) !== 'JWT') {
        throw new TorobApiAuthenticationException('Unexpected JWT type.');
    }
    if (isset($header['v']) && (string)$header['v'] !== TOROB_API_V3_TOKEN_VERSION) {
        throw new TorobApiAuthenticationException('Unexpected JWT header version.');
    }

    $signature = torob_api_v3_base64url_decode($encodedSignature);
    $publicKey = base64_decode(TOROB_API_V3_PUBLIC_KEY_RAW_BASE64, true);
    if (!is_string($publicKey) || strlen($publicKey) !== 32 || strlen($signature) !== 64) {
        throw new TorobApiConfigurationException('Torob public-key configuration is invalid.');
    }
    if (!sodium_crypto_sign_verify_detached($signature, $encodedHeader . '.' . $encodedPayload, $publicKey)) {
        throw new TorobApiAuthenticationException('JWT signature validation failed.');
    }

    $now = time();
    $expiresAt = torob_api_v3_integer_claim($payload['exp'] ?? null, 'exp');
    $notBefore = torob_api_v3_integer_claim($payload['nbf'] ?? null, 'nbf');
    if ($now > $expiresAt) {
        throw new TorobApiAuthenticationException('JWT has expired.');
    }
    if ($now < $notBefore) {
        throw new TorobApiAuthenticationException('JWT is not active yet.');
    }

    $expectedAudience = strtolower(trim($expectedAudience ?? request_host()));
    $audience = $payload['aud'] ?? null;
    if (!is_string($audience) || $expectedAudience === '' || !hash_equals($expectedAudience, strtolower(trim($audience)))) {
        throw new TorobApiAuthenticationException('JWT audience does not match this shop.');
    }
    return $payload;
}

/** @return array{mode:string,page?:int,sort?:string,values?:array<int,string>} */
function torob_api_v3_validate_request(array $input): array
{
    $hasUrls = array_key_exists('page_urls', $input);
    $hasUniques = array_key_exists('page_uniques', $input);
    $hasPageMode = array_key_exists('page', $input) || array_key_exists('sort', $input);
    if (((int)$hasUrls + (int)$hasUniques + (int)$hasPageMode) !== 1) {
        throw new TorobApiRequestException('request body must contain exactly one supported lookup mode');
    }

    if ($hasUrls || $hasUniques) {
        $key = $hasUrls ? 'page_urls' : 'page_uniques';
        $maximumLength = $hasUrls ? 1500 : 200;
        $values = $input[$key];
        if (!is_array($values) || !array_is_list($values) || count($values) < 1) {
            throw new TorobApiRequestException($key . ' must be a non-empty list');
        }
        if (count($values) > 1000) {
            throw new TorobApiRequestException($key . ' contains too many items');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new TorobApiRequestException($key . ' items must be strings');
            }
            $value = trim($value);
            if ($value === '' || text_length($value) > $maximumLength) {
                throw new TorobApiRequestException($key . ' contains an invalid item');
            }
            $normalized[$value] = $value;
        }
        return ['mode'=>$hasUrls ? 'urls' : 'uniques', 'values'=>array_values($normalized)];
    }

    if (!array_key_exists('page', $input)) {
        throw new TorobApiRequestException('page parameter is not provided');
    }
    if (!array_key_exists('sort', $input)) {
        throw new TorobApiRequestException('sort parameter is not provided');
    }
    $pageValue = $input['page'];
    $page = is_int($pageValue) ? $pageValue : (is_string($pageValue) && ctype_digit($pageValue) ? (int)$pageValue : 0);
    if ($page < 1 || $page > 1000000) {
        throw new TorobApiRequestException('page parameter must be a positive integer');
    }
    $sort = is_string($input['sort']) ? trim($input['sort']) : '';
    if (!in_array($sort, ['date_added_desc', 'date_updated_desc'], true)) {
        throw new TorobApiRequestException('sort parameter is invalid');
    }
    return ['mode'=>'page', 'page'=>$page, 'sort'=>$sort];
}

function torob_api_v3_product_base_url(): string
{
    return app_url('product/');
}

function torob_api_v3_index_sql(): string
{
    return "
        SELECT CONCAT('product-',p.id) page_unique,
               CONCAT(?,p.slug) page_url,
               p.id product_id,
               NULL variant_id,
               'simple' item_type,
               p.created_at date_added,
               p.updated_at date_updated
          FROM products p
          JOIN categories c ON c.id=p.category_id
          JOIN brands b ON b.id=p.brand_id
         WHERE p.product_type='simple'
           AND p.status='active' AND p.torob_enabled=1 AND p.price>0
           AND c.is_active=1 AND b.is_active=1
        UNION ALL
        SELECT CONCAT('variant-',pv.id) page_unique,
               CONCAT(?,p.slug,'?variant=',pv.id) page_url,
               p.id product_id,
               pv.id variant_id,
               'variant' item_type,
               pv.created_at date_added,
               GREATEST(p.updated_at,pv.updated_at) date_updated
          FROM product_variants pv
          JOIN products p ON p.id=pv.product_id
          JOIN categories c ON c.id=p.category_id
          JOIN brands b ON b.id=p.brand_id
         WHERE p.product_type='variable'
           AND p.status='active' AND p.torob_enabled=1 AND p.price>0
           AND pv.status='active' AND pv.torob_enabled=1 AND pv.price>0
           AND c.is_active=1 AND b.is_active=1
    ";
}

/** @return array{rows:array<int,array<string,mixed>>,current_page:int,total:int,max_pages:int} */
function torob_api_v3_fetch_index(PDO $pdo, array $request): array
{
    $validated = isset($request['mode']) ? $request : torob_api_v3_validate_request($request);
    $baseSql = torob_api_v3_index_sql();
    $params = [torob_api_v3_product_base_url(), torob_api_v3_product_base_url()];
    $where = '';
    $currentPage = 1;
    $total = 0;
    $maxPages = 1;
    $limitSql = '';
    $orderSql = ' ORDER BY torob_index.date_added DESC,torob_index.product_id DESC,torob_index.variant_id DESC';

    if ($validated['mode'] === 'page') {
        $currentPage = (int)$validated['page'];
        $count = $pdo->prepare("SELECT COUNT(*) FROM ({$baseSql}) torob_index");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $maxPages = max(1, (int)ceil($total / TOROB_API_V3_PAGE_SIZE));
        if ((string)$validated['sort'] === 'date_updated_desc') {
            $orderSql = ' ORDER BY torob_index.date_updated DESC,torob_index.product_id DESC,torob_index.variant_id DESC';
        }
        $offset = ($currentPage - 1) * TOROB_API_V3_PAGE_SIZE;
        $limitSql = ' LIMIT ' . TOROB_API_V3_PAGE_SIZE . ' OFFSET ' . $offset;
    } else {
        $field = $validated['mode'] === 'urls' ? 'page_url' : 'page_unique';
        $values = array_values((array)($validated['values'] ?? []));
        $where = ' WHERE torob_index.' . $field . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        $params = array_merge($params, $values);
    }

    $statement = $pdo->prepare("SELECT * FROM ({$baseSql}) torob_index{$where}{$orderSql}{$limitSql}");
    $statement->execute($params);
    $rows = $statement->fetchAll() ?: [];
    if ($validated['mode'] !== 'page') {
        $total = count($rows);
        $maxPages = 1;
    }
    return ['rows'=>$rows, 'current_page'=>$currentPage, 'total'=>$total, 'max_pages'=>$maxPages];
}

function torob_api_v3_clean_text(mixed $value, int $maximum): string
{
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return text_substr(trim($text), 0, max(1, $maximum));
}

function torob_api_v3_timestamp(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format(DateTimeInterface::ATOM);
    try {
        return (new DateTimeImmutable($text, new DateTimeZone('Asia/Tehran')))->format(DateTimeInterface::ATOM);
    } catch (Throwable) {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format(DateTimeInterface::ATOM);
    }
}

function torob_api_v3_image_url(string $path): ?string
{
    $external = safe_external_url($path);
    if ($external !== null) return text_substr($external, 0, 1000);
    $internal = safe_internal_image_path($path);
    if ($internal === null || !is_file(BASE_PATH . '/' . $internal)) return null;
    $url = str_starts_with($internal, 'assets/') ? asset(substr($internal, 7)) : app_url($internal);
    return text_substr($url, 0, 1000);
}

/** @return array<int,string> */
function torob_api_v3_images(array $product, ?array $variant = null): array
{
    static $galleryPaths = [];
    $productId = (int)$product['id'];
    $paths = [];
    $variantPath = trim((string)($variant['image_path'] ?? ''));
    $productPath = trim((string)($product['image_path'] ?? ''));
    if ($variantPath !== '') $paths[] = $variantPath;
    if ($productPath !== '') $paths[] = $productPath;
    if (!array_key_exists($productId, $galleryPaths)) {
        $galleryPaths[$productId] = [];
        foreach (product_gallery_images($productId, '', (string)$product['title'], false) as $image) {
            $path = trim((string)($image['image_path'] ?? ''));
            if ($path !== '') $galleryPaths[$productId][] = $path;
        }
    }
    foreach ($galleryPaths[$productId] as $path) $paths[] = $path;

    $urls = [];
    foreach ($paths as $path) {
        $url = torob_api_v3_image_url($path);
        if ($url !== null) $urls[$url] = $url;
        if (count($urls) >= 12) break;
    }
    return array_values($urls);
}

/** @return array<string,string|int> */
function torob_api_v3_specifications(array $product, ?array $variant = null, ?PDO $pdo = null): array
{
    static $baseSpecifications = [];
    $pdo ??= db();
    $productId = (int)$product['id'];
    if (!array_key_exists($productId, $baseSpecifications)) {
        $baseSpecifications[$productId] = [];
        foreach (product_specifications($productId, $pdo) as $row) {
            $name = torob_api_v3_clean_text($row['spec_name'] ?? '', 180);
            $value = torob_api_v3_clean_text($row['spec_value'] ?? '', 1000);
            if ($name === '' || $value === '') continue;
            if (isset($baseSpecifications[$productId][$name]) && (string)$baseSpecifications[$productId][$name] !== $value) {
                $value = torob_api_v3_clean_text((string)$baseSpecifications[$productId][$name] . '، ' . $value, 1000);
            }
            $baseSpecifications[$productId][$name] = $value;
        }
        $energy = torob_api_v3_clean_text($product['energy_label'] ?? '', 50);
        if ($energy !== '' && !isset($baseSpecifications[$productId]['رده مصرف انرژی'])) {
            $baseSpecifications[$productId]['رده مصرف انرژی'] = $energy;
        }
    }
    $spec = $baseSpecifications[$productId];
    if ($variant !== null) {
        foreach ((array)($variant['options'] ?? []) as $option) {
            $name = torob_api_v3_clean_text($option['attribute_name'] ?? '', 180);
            $value = torob_api_v3_clean_text($option['value'] ?? '', 1000);
            if ($name !== '' && $value !== '') $spec[$name] = $value;
        }
    } else {
        foreach (product_selected_attributes($productId, true, $pdo) as $attribute) {
            $name = torob_api_v3_clean_text($attribute['name'] ?? '', 180);
            $values = [];
            foreach ((array)($attribute['values'] ?? []) as $option) {
                $value = torob_api_v3_clean_text($option['value'] ?? '', 200);
                if ($value !== '') $values[$value] = $value;
            }
            if ($name !== '' && $values !== []) $spec[$name] = torob_api_v3_clean_text(implode('، ', $values), 1000);
        }
    }
    $color = torob_api_v3_clean_text($product['color'] ?? '', 80);
    if ($variant === null && $color !== '' && !isset($spec['رنگ'])) $spec['رنگ'] = $color;
    return $spec;
}

/** @return array<string,mixed> */
function torob_api_v3_serialize_product(array $index, array $product, ?array $variant, ?PDO $pdo = null): array
{
    $pdo ??= db();
    $effective = $product;
    $label = '';
    if ($variant !== null) {
        $effective = $variant;
        $label = trim((string)($variant['option_label'] ?: $variant['title_suffix']));
    }
    $title = torob_api_v3_clean_text((string)$product['title'] . ($label !== '' ? ' - ' . $label : ''), 500);
    $availability = (int)($effective['stock'] ?? 0) > 0;
    $effectivePrice = max(0, (int)($effective['price'] ?? 0));
    $currentPrice = $availability ? $effectivePrice : 0;
    $basePrice = max(0, (int)($effective['base_price'] ?? $effectivePrice));
    $specifications = torob_api_v3_specifications($product, $variant, $pdo);

    $item = [
        'page_unique'=>text_substr((string)$index['page_unique'], 0, 200),
        'page_url'=>text_substr((string)$index['page_url'], 0, 1500),
        'title'=>$title,
        'current_price'=>$currentPrice,
        'availability'=>$availability,
        'category_name'=>torob_api_v3_clean_text($product['category_name'] ?? '', 200),
        'image_links'=>torob_api_v3_images($product, $variant),
        'spec'=>(object)$specifications,
        'date_added'=>torob_api_v3_timestamp($index['date_added'] ?? $product['created_at'] ?? ''),
        'date_updated'=>torob_api_v3_timestamp($index['date_updated'] ?? $product['updated_at'] ?? ''),
    ];
    if ($variant !== null) $item['product_group_id'] = 'product-' . (int)$product['id'];

    $subtitle = torob_api_v3_clean_text($product['short_title'] ?? '', 500);
    if ($subtitle !== '' && $subtitle !== torob_api_v3_clean_text($product['title'] ?? '', 500)) {
        $item['subtitle'] = $subtitle . ($label !== '' ? ' - ' . $label : '');
        $item['subtitle'] = text_substr($item['subtitle'], 0, 500);
    }
    if ($availability && $basePrice > $currentPrice) $item['old_price'] = $basePrice;
    $guaranteeValue = $variant !== null && trim((string)($variant['warranty'] ?? '')) !== ''
        ? (string)$variant['warranty']
        : (string)($product['warranty'] ?? '');
    $guarantee = torob_api_v3_clean_text($guaranteeValue, 200);
    if ($guarantee !== '') $item['guarantee'] = $guarantee;
    $description = torob_api_v3_clean_text($product['description'] ?? '', 500);
    if ($description !== '') $item['short_desc'] = $description;
    return $item;
}

/** @return array<int,array<string,mixed>> */
function torob_api_v3_load_products(PDO $pdo, array $indexRows): array
{
    if ($indexRows === []) return [];
    $productIds = [];
    foreach ($indexRows as $row) {
        $productId = max(0, (int)($row['product_id'] ?? 0));
        if ($productId > 0) $productIds[$productId] = $productId;
    }
    if ($productIds === []) return [];

    $ids = array_values($productIds);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug
        FROM products p
        JOIN categories c ON c.id=p.category_id
        JOIN brands b ON b.id=p.brand_id
        WHERE p.id IN ({$marks}) AND p.status='active' AND p.torob_enabled=1 AND p.price>0
          AND c.is_active=1 AND b.is_active=1");
    $statement->execute($ids);
    $productMap = [];
    foreach (hydrate_products($statement->fetchAll() ?: [], true, $pdo) as $product) {
        $productMap[(int)$product['id']] = $product;
    }

    $variantMaps = [];
    foreach ($indexRows as $index) {
        $productId = (int)($index['product_id'] ?? 0);
        $variantId = (int)($index['variant_id'] ?? 0);
        if ($variantId < 1 || isset($variantMaps[$productId])) continue;
        $variantMaps[$productId] = [];
        foreach (product_variant_rows($productId, true, $pdo) as $variant) {
            if ((int)($variant['torob_enabled'] ?? 0) !== 1) continue;
            $variantMaps[$productId][(int)$variant['id']] = $variant;
        }
    }

    $products = [];
    foreach ($indexRows as $index) {
        $productId = (int)($index['product_id'] ?? 0);
        $variantId = (int)($index['variant_id'] ?? 0);
        $product = $productMap[$productId] ?? null;
        if (!is_array($product)) continue;
        $variant = null;
        if ($variantId > 0) {
            $variant = $variantMaps[$productId][$variantId] ?? null;
            if (!is_array($variant)) continue;
            $variant = variant_with_festival($product, $variant, $pdo);
        }
        $products[] = torob_api_v3_serialize_product($index, $product, $variant, $pdo);
    }
    return $products;
}

/** @return array{api_version:string,current_page:int,total:int,max_pages:int,products:array<int,array<string,mixed>>} */
function torob_api_v3_response_data(PDO $pdo, array $request): array
{
    $validated = isset($request['mode']) ? $request : torob_api_v3_validate_request($request);
    $index = torob_api_v3_fetch_index($pdo, $validated);
    return [
        'api_version'=>TOROB_API_V3_NAME,
        'current_page'=>$index['current_page'],
        'total'=>$index['total'],
        'max_pages'=>$index['max_pages'],
        'products'=>torob_api_v3_load_products($pdo, $index['rows']),
    ];
}

/** @return array<string,mixed> */
function torob_api_v3_diagnostics(?PDO $pdo = null): array
{
    $pdo ??= db();
    $index = torob_api_v3_fetch_index($pdo, ['mode'=>'page', 'page'=>1, 'sort'=>'date_added_desc']);
    $latestRows = array_slice($index['rows'], 0, 10);
    $latest = torob_api_v3_load_products($pdo, $latestRows);
    $simple = 0;
    $variants = 0;
    foreach ($index['rows'] as $row) {
        if ((string)($row['item_type'] ?? '') === 'variant') $variants++;
        else $simple++;
    }
    if ($index['total'] > TOROB_API_V3_PAGE_SIZE) {
        $baseSql = torob_api_v3_index_sql();
        $counts = $pdo->prepare("SELECT item_type,COUNT(*) item_count FROM ({$baseSql}) torob_index GROUP BY item_type");
        $counts->execute([torob_api_v3_product_base_url(), torob_api_v3_product_base_url()]);
        $simple = 0;
        $variants = 0;
        foreach ($counts->fetchAll() ?: [] as $row) {
            if ((string)$row['item_type'] === 'variant') $variants = (int)$row['item_count'];
            else $simple = (int)$row['item_count'];
        }
    }

    $catalog = $pdo->query("SELECT
        COUNT(*) total_products,
        COALESCE(SUM(status='draft'),0) draft_products,
        COALESCE(SUM(status='active' AND torob_enabled=0),0) torob_disabled,
        COALESCE(SUM(status='active' AND price=0),0) zero_price
        FROM products")->fetch() ?: [];
    $inactiveTaxonomy = (int)$pdo->query("SELECT COUNT(*) FROM products p
        JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id
        WHERE p.status='active' AND p.torob_enabled=1 AND p.price>0 AND (c.is_active=0 OR b.is_active=0)")->fetchColumn();
    $variableWithoutRows = (int)$pdo->query("SELECT COUNT(*) FROM products p
        JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id
        WHERE p.product_type='variable' AND p.status='active' AND p.torob_enabled=1 AND p.price>0
          AND c.is_active=1 AND b.is_active=1
          AND NOT EXISTS(SELECT 1 FROM product_variants pv WHERE pv.product_id=p.id AND pv.status='active' AND pv.torob_enabled=1 AND pv.price>0)")->fetchColumn();

    $siteUrl = rtrim((string)setting('site_url', ''), '/');
    $siteParts = $siteUrl !== '' ? parse_url($siteUrl) : false;
    $httpsReady = is_array($siteParts) && strtolower((string)($siteParts['scheme'] ?? '')) === 'https' && !empty($siteParts['host']);
    $warnings = [];
    if (!torob_api_v3_is_supported()) $warnings[] = 'افزونه Sodium برای اعتبارسنجی JWT ترب فعال نیست.';
    if (!$httpsReady) $warnings[] = 'آدرس اصلی سایت باید با HTTPS صحیح تنظیم شود.';
    if ($index['total'] < 1) $warnings[] = 'هیچ محصول واجد شرایطی برای خروجی ترب پیدا نشد.';
    $missingImages = 0;
    foreach ($latest as $product) if (empty($product['image_links'])) $missingImages++;
    if ($missingImages > 0) $warnings[] = fa_number($missingImages) . ' محصول از ده محصول جدید خروجی، تصویر معتبر ندارد.';

    return [
        'ready'=>$warnings === [],
        'endpoint'=>torob_api_v3_endpoint_url(),
        'sodium'=>torob_api_v3_is_supported(),
        'https'=>$httpsReady,
        'total'=>$index['total'],
        'simple'=>$simple,
        'variants'=>$variants,
        'draft_products'=>(int)($catalog['draft_products'] ?? 0),
        'torob_disabled'=>(int)($catalog['torob_disabled'] ?? 0),
        'zero_price'=>(int)($catalog['zero_price'] ?? 0),
        'inactive_taxonomy'=>$inactiveTaxonomy,
        'variable_without_rows'=>$variableWithoutRows,
        'warnings'=>$warnings,
        'latest'=>$latest,
    ];
}
