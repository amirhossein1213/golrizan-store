<?php
declare(strict_types=1);

const MAX_MONEY_AMOUNT = 100_000_000_000_000;

function scalar_string(mixed $value, string $default = ''): string
{
    return is_string($value) || is_numeric($value) ? (string)$value : $default;
}

function scalar_int(mixed $value, int $default = 0): int
{
    return is_int($value) || is_float($value) || is_string($value) && preg_match('/^-?\d+$/', $value) ? (int)$value : $default;
}

function bounded_uint(mixed $value, int $max = MAX_MONEY_AMOUNT, ?bool &$valid = null): int
{
    $text = trim(scalar_string($value));
    $maxText = (string)$max;
    $valid = $text !== '' && ctype_digit($text)
        && (strlen($text) < strlen($maxText) || strlen($text) === strlen($maxText) && strcmp($text, $maxText) <= 0);
    return $valid ? (int)$text : 0;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): void
{
    $token ??= $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['_csrf'] ?? null;
    $valid = is_string($token) && is_string($expected)
        && strlen($token) === 64 && strlen($expected) === 64
        && ctype_xdigit($token) && ctype_xdigit($expected)
        && hash_equals($expected, $token);
    if (!$valid) {
        http_response_code(419);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_response(['ok' => false, 'message' => 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید.'], 419);
        }
        exit('درخواست نامعتبر است. صفحه را تازه‌سازی کنید.');
    }
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false) return [];
    if (strlen($raw) > 1024 * 1024) json_response(['ok'=>false,'message'=>'حجم درخواست بیش از حد مجاز است.'], 413);
    $data = json_decode($raw !== '' ? $raw : '{}', true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_response(['ok'=>false,'message'=>'ساختار JSON درخواست معتبر نیست.'], 400);
    }
    return $data;
}

function setting(string $key, mixed $default = ''): mixed
{
    static $settings = null;
    if ($settings === null) {
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            $settings = [];
            foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];
        } catch (Throwable) {
            $settings = [];
        }
    }
    return $settings[$key] ?? $default;
}

function request_host(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::\d{1,5})?$/', $host)) return 'localhost';
    return $host;
}

function app_url(string $path = ''): string
{
    $base = rtrim((string)setting('site_url', ''), '/');
    if ($base === '') {
        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = request_host();
        $dir = function_exists('mousavi_app_base_path') ? mousavi_app_base_path() : '';
        $base = $scheme . '://' . $host . $dir;
    }
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function app_version(): string
{
    static $version = null;
    if ($version !== null) return $version;
    $raw = trim((string)@file_get_contents(BASE_PATH . '/VERSION.txt'));
    $version = preg_match('/^\d+\.\d+\.\d+$/', $raw) ? $raw : '2.9.5';
    return $version;
}

function asset(string $path): string
{
    $clean = ltrim($path, '/');
    $url = app_url('assets/' . $clean);
    return preg_match('/\.(?:css|js|avif|webp|jpe?g|png|gif|svg|ico|woff2?)$/i', $clean)
        ? $url . '?v=' . rawurlencode(app_version())
        : $url;
}
function upload_url(string $path): string { return app_url(ltrim($path, '/')); }
function product_url(array $product): string { return app_url('product/' . rawurlencode($product['slug'])); }

function format_price(int|float|string $amount): string
{
    return number_format((int)$amount, 0, '.', ',');
}

function fa_number(int|float|string $value): string
{
    return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹','.'=>'٫',','=>'٬']);
}

function valid_iso_date(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) return false;
    return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
}

function text_length(string $text): int
{
    if (function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
    if (function_exists('iconv_strlen')) {
        $length = @iconv_strlen($text, 'UTF-8');
        if ($length !== false) return $length;
    }
    if (preg_match_all('/./us', $text, $matches) !== false) return count($matches[0]);
    return strlen($text);
}

function text_substr(string $text, int $start, int $length): string
{
    if (function_exists('mb_substr')) return mb_substr($text, $start, $length, 'UTF-8');
    if (function_exists('iconv_substr')) {
        $slice = @iconv_substr($text, $start, $length, 'UTF-8');
        if ($slice !== false) return $slice;
    }
    if (preg_match_all('/./us', $text, $matches) !== false) return implode('', array_slice($matches[0], $start, $length));
    return substr($text, $start, $length);
}

function slugify(string $text): string
{
    $text = trim($text);
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: $text;
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') $text = strtolower($converted);
    } else {
        $text = strtolower($text);
    }
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?: '';
    $text = trim(strtolower($text), '-');
    return $text !== '' ? $text : 'item-' . substr(hash('sha256', $text . microtime(true)), 0, 10);
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = compact('type', 'message');
}

function flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

function current_page(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
}

function current_relative_path(): string
{
    $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $basePath = (string)(parse_url((string)setting('site_url', ''), PHP_URL_PATH) ?? '');
    $basePath = rtrim($basePath, '/');
    if ($basePath !== '' && ($uriPath === $basePath || str_starts_with($uriPath, $basePath . '/'))) {
        $uriPath = substr($uriPath, strlen($basePath)) ?: '/';
    }
    return ltrim($uriPath, '/');
}

function current_canonical_url(): string
{
    return app_url(current_relative_path());
}

function valid_site_url(string $url): bool
{
    $decoded = rawurldecode($url);
    if (str_contains($url, '\\') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url) || preg_match('/[\x00-\x1F\x7F]/', $decoded)) return false;
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !empty($parts['query']) || !empty($parts['fragment']) || !empty($parts['user']) || !empty($parts['pass'])) return false;
    $path = rawurldecode((string)($parts['path'] ?? ''));
    $segments = explode('/', $path);
    return !in_array('.', $segments, true) && !in_array('..', $segments, true);
}

function safe_local_redirect(string $path): ?string
{
    if ($path === '' || !str_starts_with($path, '/')) return null;
    $decoded = rawurldecode($path);
    if (str_starts_with($path, '//') || str_starts_with($decoded, '//') || str_contains($path, '\\') || str_contains($decoded, '\\')) return null;
    if (preg_match('/[\x00-\x1F\x7F]/', $path) || preg_match('/[\x00-\x1F\x7F]/', $decoded)) return null;
    $parts = parse_url($path);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $basePath = (string)(parse_url((string)setting('site_url', ''), PHP_URL_PATH) ?? '');
    $basePath = rtrim($basePath, '/');
    if ($basePath !== '' && $path !== $basePath && !str_starts_with($path, $basePath . '/')) return null;
    return $path;
}

function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function rate_limit_storage(string $key, int $windowSeconds, bool $addAttempt): array
{
    $now = time();
    $directory = BASE_PATH . '/storage/rate-limits';
    if (!is_dir($directory)) @mkdir($directory, 0750, true);
    if (is_dir($directory) && random_int(1, 100) === 1) {
        foreach (glob($directory . '/*.json') ?: [] as $oldFile) if (@filemtime($oldFile) < $now - 172800) @unlink($oldFile);
    }
    $file = $directory . '/' . hash('sha256', $key) . '.json';
    $handle = @fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        $sessionKey = '_rate_' . hash('sha256', $key);
        $attempts = array_values(array_filter((array)($_SESSION[$sessionKey] ?? []), static fn($time): bool => is_int($time) && $time > $now - $windowSeconds));
        if ($addAttempt) $attempts[] = $now;
        $_SESSION[$sessionKey] = $attempts;
        return $attempts;
    }
    $contents = stream_get_contents($handle);
    $decoded = json_decode($contents ?: '[]', true);
    $attempts = array_values(array_filter(is_array($decoded) ? $decoded : [], static fn($time): bool => is_int($time) && $time > $now - $windowSeconds));
    if ($addAttempt) $attempts[] = $now;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($attempts));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($file, 0640);
    return $attempts;
}

function rate_limit_retry_after(string $key, int $maxAttempts, int $windowSeconds): int
{
    $attempts = rate_limit_storage($key, $windowSeconds, false);
    if (count($attempts) < $maxAttempts) return 0;
    return max(1, $windowSeconds - (time() - (int)$attempts[0]));
}

function rate_limit_record(string $key, int $windowSeconds): void
{
    rate_limit_storage($key, $windowSeconds, true);
}

function rate_limit_clear(string $key): void
{
    $file = BASE_PATH . '/storage/rate-limits/' . hash('sha256', $key) . '.json';
    @unlink($file);
    unset($_SESSION['_rate_' . hash('sha256', $key)]);
}

function rate_limit(string $key, int $maxAttempts, int $windowSeconds): void
{
    $bucket = $key . ':' . client_ip();
    $retryAfter = rate_limit_retry_after($bucket, $maxAttempts, $windowSeconds);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        json_response(['ok' => false, 'message' => 'تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.'], 429);
    }
    rate_limit_record($bucket, $windowSeconds);
}

function payment_method_available(string $method): bool
{
    return match ($method) {
        'coordination' => (string)setting('payment_manual_enabled','1') === '1',
        'gateway' => function_exists('payment_gateway_enabled') && payment_gateway_enabled(),
        'torobpay' => function_exists('torobpay_enabled') && torobpay_enabled(),
        'snappay' => function_exists('snappay_enabled') && snappay_enabled(),
        default => false,
    };
}

function safe_internal_image_path(string $path): ?string
{
    $path = trim($path);
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) return null;
    if (!preg_match('#^(?:assets/images|uploads/(?:products|blog|homepage|categories))/[A-Za-z0-9._/-]{1,220}\.(?:svg|png|jpe?g|webp)$#i', $path)) return null;
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) return null;
    }
    return $path;
}

function public_image(string $path): string
{
    $fallback = asset('images/ui/category-all.svg');
    if ($path === '') return $fallback;
    $external = safe_external_url($path);
    if ($external !== null) return $external;
    $internal = safe_internal_image_path($path);
    if ($internal === null || !is_file(BASE_PATH.'/'.$internal)) return $fallback;
    return str_starts_with($internal, 'assets/') ? asset(substr($internal, 7)) : app_url($internal);
}

function managed_image_thumbnail_path(string $path): ?string
{
    $internal = safe_internal_image_path($path);
    if ($internal === null || !str_starts_with($internal, 'uploads/')) return null;
    $info = pathinfo($internal);
    $thumbnail = ($info['dirname'] ?? '').'/'.($info['filename'] ?? '').'-thumb.webp';
    return is_file(BASE_PATH.'/'.$thumbnail) ? $thumbnail : null;
}

function public_image_thumbnail(string $path): string
{
    $thumbnail = managed_image_thumbnail_path($path);
    return $thumbnail !== null ? app_url($thumbnail) : public_image($path);
}

function public_image_srcset(string $path): string
{
    $internal = safe_internal_image_path($path);
    if ($internal === null || !is_file(BASE_PATH.'/'.$internal)) return '';
    $sources = [];
    $thumbnail = managed_image_thumbnail_path($internal);
    if ($thumbnail !== null) {
        $size = @getimagesize(BASE_PATH.'/'.$thumbnail);
        if (is_array($size) && (int)$size[0] > 0) $sources[] = app_url($thumbnail).' '.(int)$size[0].'w';
    }
    $size = @getimagesize(BASE_PATH.'/'.$internal);
    if (is_array($size) && (int)$size[0] > 0) $sources[] = (str_starts_with($internal,'assets/')?asset(substr($internal,7)):app_url($internal)).' '.(int)$size[0].'w';
    return implode(', ', array_values(array_unique($sources)));
}

function safe_external_url(string $url): ?string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    return $url;
}

function license_badge_html(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';
    $href = null; $src = null; $alt = ''; $code = '';
    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="license-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        $images = $dom->getElementsByTagName('img');
        if ($images->length > 0) {
            $img = $images->item(0);
            $src = safe_external_url((string)$img?->getAttribute('src'));
            $alt = text_substr((string)$img?->getAttribute('alt'), 0, 160);
            $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$img?->getAttribute('code')) ?? '';
        }
        $anchors = $dom->getElementsByTagName('a');
        if ($anchors->length > 0) $href = safe_external_url((string)$anchors->item(0)?->getAttribute('href'));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    } else {
        if (preg_match('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $m)) $src = safe_external_url($m[2]);
        if (preg_match('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $m)) $href = safe_external_url($m[2]);
        if (preg_match('/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1/is', $html, $m)) $alt = text_substr(strip_tags($m[2]), 0, 160);
        if (preg_match('/<img\b[^>]*\bcode\s*=\s*(["\'])(.*?)\1/is', $html, $m)) $code = preg_replace('/[^A-Za-z0-9_-]/', '', $m[2]) ?? '';
    }
    if (!$src) return '';
    $img = '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" referrerpolicy="origin"' . ($code !== '' ? ' code="' . e($code) . '"' : '') . '>';
    return $href ? '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="origin">' . $img . '</a>' : $img;
}

function normalize_phone(string $phone): string
{
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    return preg_replace('/\D+/', '', strtr($phone, $map)) ?? '';
}

function log_error(Throwable $e): void
{
    $line = sprintf("[%s] %s in %s:%d\n", date('c'), $e->getMessage(), $e->getFile(), $e->getLine());
    @file_put_contents(BASE_PATH . '/storage/logs/app.log', $line, FILE_APPEND | LOCK_EX);
}

function public_exception_message(Throwable $exception,string $fallback='انجام عملیات ممکن نشد. دوباره تلاش کنید.'): string
{
    if($exception instanceof RuntimeException&&!($exception instanceof PDOException)){
        $message=trim($exception->getMessage());
        return $message!==''?text_substr($message,0,500):$fallback;
    }
    log_error($exception);
    return $fallback;
}

const PRODUCT_IMAGE_CANVAS_SIZE = 1200;
const PRODUCT_IMAGE_MIN_SOURCE_SIDE = 300;

/**
 * تصویر محصول را بدون برش یا کشیدگی روی بوم سفید استاندارد ترب قرار می‌دهد.
 * خروجی اصلی همیشه 1200×1200 و بندانگشتی همیشه 480×480 است.
 */
function write_torob_ready_product_image(
    mixed $source,
    int $sourceWidth,
    int $sourceHeight,
    string $directory,
    string $baseName
): ?string {
    if ($sourceWidth < 1 || $sourceHeight < 1
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled')
        || !function_exists('imagewebp')) return null;

    $canvasSize = PRODUCT_IMAGE_CANVAS_SIZE;
    $scale = min($canvasSize / $sourceWidth, $canvasSize / $sourceHeight);
    $contentWidth = max(1, min($canvasSize, (int)round($sourceWidth * $scale)));
    $contentHeight = max(1, min($canvasSize, (int)round($sourceHeight * $scale)));
    $offsetX = intdiv($canvasSize - $contentWidth, 2);
    $offsetY = intdiv($canvasSize - $contentHeight, 2);
    $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
    if ($canvas === false) return null;

    imagealphablending($canvas, true);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $canvasSize, $canvasSize, $white);
    $copied = imagecopyresampled(
        $canvas,
        $source,
        $offsetX,
        $offsetY,
        0,
        0,
        $contentWidth,
        $contentHeight,
        $sourceWidth,
        $sourceHeight
    );

    $webpPath = $directory . '/' . $baseName . '.webp';
    $written = false;
    if ($copied) {
        foreach ([88,84,80,76,72] as $quality) {
            $written = @imagewebp($canvas, $webpPath, $quality);
            clearstatcache(true, $webpPath);
            if (!$written || !is_file($webpPath)) break;
            if ((int)filesize($webpPath) <= 750 * 1024) break;
        }
    }

    $thumbnailPath = $directory . '/' . $baseName . '-thumb.webp';
    if ($written && is_file($webpPath) && (int)filesize($webpPath) > 0) {
        $thumbnail = imagecreatetruecolor(480, 480);
        if ($thumbnail !== false) {
            imagealphablending($thumbnail, true);
            $thumbnailWhite = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefilledrectangle($thumbnail, 0, 0, 480, 480, $thumbnailWhite);
            $thumbnailCopied = imagecopyresampled($thumbnail, $canvas, 0, 0, 0, 0, 480, 480, $canvasSize, $canvasSize);
            $thumbnailWritten = $thumbnailCopied && @imagewebp($thumbnail, $thumbnailPath, 78);
            imagedestroy($thumbnail);
            if ($thumbnailWritten && is_file($thumbnailPath) && (int)filesize($thumbnailPath) > 0) @chmod($thumbnailPath, 0644);
            elseif (is_file($thumbnailPath)) @unlink($thumbnailPath);
        }
    }
    imagedestroy($canvas);

    if (!$written || !is_file($webpPath) || (int)filesize($webpPath) < 1) {
        if (is_file($webpPath)) @unlink($webpPath);
        if (is_file($thumbnailPath)) @unlink($thumbnailPath);
        return null;
    }
    $outputSize = @getimagesize($webpPath);
    if (!is_array($outputSize) || (int)$outputSize[0] !== $canvasSize || (int)$outputSize[1] !== $canvasSize) {
        @unlink($webpPath);
        if (is_file($thumbnailPath)) @unlink($thumbnailPath);
        return null;
    }
    @chmod($webpPath, 0644);
    return 'uploads/products/' . $baseName . '.webp';
}


function save_managed_image_upload(
    array $file,
    string $folder,
    int $maxBytes = 5242880,
    int $minWidth = 1,
    int $minHeight = 1,
    int $maxDimension = 2400
): string
{
    $allowedFolders = ['products','blog','homepage','categories'];
    if (!in_array($folder, $allowedFolders, true)) throw new RuntimeException('پوشه تصویر معتبر نیست.');
    foreach (['error','size','tmp_name','name'] as $key) {
        if (isset($file[$key]) && is_array($file[$key])) throw new RuntimeException('ساختار فایل بارگذاری‌شده معتبر نیست.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('بارگذاری تصویر ناموفق بود.');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > $maxBytes) throw new RuntimeException('حجم تصویر از محدوده مجاز بیشتر است.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('فایل بارگذاری‌شده معتبر نیست.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) throw new RuntimeException('فرمت تصویر مجاز نیست.');

    $dimensions = @getimagesize($tmp);
    if (!is_array($dimensions) || ($dimensions[0] ?? 0) < $minWidth || ($dimensions[1] ?? 0) < $minHeight) {
        throw new RuntimeException('ابعاد تصویر از حداقل مجاز کوچک‌تر است.');
    }
    if ($folder === 'products' && min((int)$dimensions[0], (int)$dimensions[1]) < PRODUCT_IMAGE_MIN_SOURCE_SIDE) {
        throw new RuntimeException('برای حفظ کیفیت، ضلع کوتاه تصویر محصول باید حداقل ۳۰۰ پیکسل باشد.');
    }
    if ((int)$dimensions[0] > 8000 || (int)$dimensions[1] > 8000 || ((int)$dimensions[0] * (int)$dimensions[1]) > 30000000) {
        throw new RuntimeException('ابعاد تصویر بیش از حد بزرگ است.');
    }

    $baseName = date('Ymd') . '-' . bin2hex(random_bytes(8));
    $dir = BASE_PATH . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('پوشه بارگذاری قابل ساخت نیست.');

    $decoder = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };
    $canProcess = $decoder !== '' && function_exists($decoder)
        && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');

    if ($canProcess) {
        $source = @$decoder($tmp);
        if ($source !== false) {
            $width = (int)$dimensions[0];
            $height = (int)$dimensions[1];

            // تصاویر موبایل JPEG ممکن است Orientation داشته باشند؛ پیش از کوچک‌سازی اصلاح می‌شود.
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($tmp);
                $orientation = (int)($exif['Orientation'] ?? 1);
                if (in_array($orientation, [3,6,8], true) && function_exists('imagerotate')) {
                    $angle = $orientation === 3 ? 180 : ($orientation === 6 ? -90 : 90);
                    $rotated = @imagerotate($source, $angle, 0);
                    if ($rotated !== false) {
                        imagedestroy($source);
                        $source = $rotated;
                        $width = imagesx($source);
                        $height = imagesy($source);
                    }
                }
            }

            if ($folder === 'products') {
                $productPath = write_torob_ready_product_image($source, $width, $height, $dir, $baseName);
                imagedestroy($source);
                if ($productPath !== null) return $productPath;
                throw new RuntimeException('آماده‌سازی خودکار تصویر محصول انجام نشد؛ افزونه GD و WebP هاست را بررسی کنید.');
            }

            $scale = min(1, $maxDimension / max($width, $height));
            $targetWidth = max(1, (int)round($width * $scale));
            $targetHeight = max(1, (int)round($height * $scale));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($canvas !== false) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
                $copied = imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                // سقف هدف برای هر نوع تصویر؛ کیفیت تا حد امن به‌صورت تطبیقی کم می‌شود.
                $targetBytes = match ($folder) {
                    'products' => 500 * 1024,
                    'categories' => 350 * 1024,
                    'blog' => 700 * 1024,
                    default => 850 * 1024,
                };
                $webpName = $baseName . '.webp';
                $webpPath = $dir . '/' . $webpName;
                $written = false;

                if ($copied && function_exists('imagewebp')) {
                    for ($quality = 82; $quality >= 62; $quality -= 5) {
                        $written = @imagewebp($canvas, $webpPath, $quality);
                        clearstatcache(true, $webpPath);
                        if (!$written || !is_file($webpPath)) break;
                        if ((int)filesize($webpPath) <= $targetBytes) break;
                    }
                }

                if ($copied && function_exists('imagewebp') && max($targetWidth, $targetHeight) > 480) {
                    $thumbScale = 480 / max($targetWidth, $targetHeight);
                    $thumbWidth = max(1, (int)round($targetWidth * $thumbScale));
                    $thumbHeight = max(1, (int)round($targetHeight * $thumbScale));
                    $thumbCanvas = imagecreatetruecolor($thumbWidth, $thumbHeight);
                    if ($thumbCanvas !== false) {
                        imagealphablending($thumbCanvas, false);
                        imagesavealpha($thumbCanvas, true);
                        $thumbTransparent = imagecolorallocatealpha($thumbCanvas, 0, 0, 0, 127);
                        imagefilledrectangle($thumbCanvas, 0, 0, $thumbWidth, $thumbHeight, $thumbTransparent);
                        $thumbCopied = imagecopyresampled($thumbCanvas, $canvas, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $targetWidth, $targetHeight);
                        $thumbPath = $dir.'/'.$baseName.'-thumb.webp';
                        $thumbWritten = $thumbCopied && @imagewebp($thumbCanvas, $thumbPath, 76);
                        imagedestroy($thumbCanvas);
                        if ($thumbWritten && is_file($thumbPath) && (int)filesize($thumbPath) > 0) @chmod($thumbPath, 0644);
                        elseif (is_file($thumbPath)) @unlink($thumbPath);
                    }
                }

                imagedestroy($canvas);
                imagedestroy($source);

                if ($written && is_file($webpPath) && (int)filesize($webpPath) > 0) {
                    $optimizedSize = (int)filesize($webpPath);
                    // فایل از قبل بسیار کم‌حجم باشد، نسخه بزرگ‌تر جای آن ذخیره نمی‌شود.
                    if ($optimizedSize < $size || $scale < 1 || $size > $targetBytes) {
                        @chmod($webpPath, 0644);
                        return 'uploads/' . $folder . '/' . $webpName;
                    }
                    @unlink($webpPath);
                } elseif (is_file($webpPath)) {
                    @unlink($webpPath);
                }
            } else {
                imagedestroy($source);
            }
        }
    }

    if ($folder === 'products') {
        throw new RuntimeException('برای آماده‌سازی خودکار تصویر محصول، افزونه GD با پشتیبانی WebP باید فعال باشد.');
    }

    // در هاست فاقد GD/WebP یا وقتی فایل اصلی از خروجی سبک‌تر است، همان فایل معتبر ذخیره می‌شود.
    $fallbackName = $baseName . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $fallbackName)) {
        $orphanThumbnail=$dir.'/'.$baseName.'-thumb.webp';if(is_file($orphanThumbnail))@unlink($orphanThumbnail);
        throw new RuntimeException('ذخیره تصویر انجام نشد.');
    }
    @chmod($dir . '/' . $fallbackName, 0644);
    return 'uploads/' . $folder . '/' . $fallbackName;
}

function save_product_upload(array $file): string
{
    return save_managed_image_upload($file, 'products', 6 * 1024 * 1024, PRODUCT_IMAGE_MIN_SOURCE_SIDE, PRODUCT_IMAGE_MIN_SOURCE_SIDE, PRODUCT_IMAGE_CANVAS_SIZE);
}

function delete_managed_upload(string $path): void
{
    if (!preg_match('#^uploads/(?:products|blog|homepage|categories)/\d{8}-[a-f0-9]{16}\.(?:jpe?g|png|webp)$#i', $path)) return;
    $file = BASE_PATH . '/' . $path;
    if (is_file($file)) @unlink($file);
    $info = pathinfo($path);
    $thumbnail = BASE_PATH.'/'.($info['dirname'] ?? '').'/'.($info['filename'] ?? '').'-thumb.webp';
    if (is_file($thumbnail)) @unlink($thumbnail);
}
