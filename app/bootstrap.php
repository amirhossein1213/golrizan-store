<?php
declare(strict_types=1);

const BASE_PATH = __DIR__ . '/..';

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('expose_php', '0');
@ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
if (ob_get_level() === 0) ob_start();

function mousavi_app_base_path(): string
{
    $scriptFileInput = trim((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $documentRootInput = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $scriptFile = $scriptFileInput !== '' ? realpath($scriptFileInput) : false;
    $documentRoot = $documentRootInput !== '' ? realpath($documentRootInput) : false;
    if (is_string($scriptFile) && $scriptFile !== '' && is_string($documentRoot) && $documentRoot !== '') {
        $file = str_replace('\\', '/', $scriptFile);
        $root = rtrim(str_replace('\\', '/', $documentRoot), '/');
        if ($file === $root || str_starts_with($file, $root . '/')) {
            $relative = substr($file, strlen($root));
            $base = rtrim(str_replace('\\', '/', dirname($relative)), '/.');
            while (preg_match('#/(?:admin|api|install)$#', $base)) {
                $base = rtrim(str_replace('\\', '/', dirname($base)), '/.');
            }
            return $base === '/' ? '' : $base;
        }
    }

    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    if (preg_match('#^(.*?)/(?:product|blog)/[A-Za-z0-9-]+/?$#', $scriptPath, $match)) {
        return rtrim((string)$match[1], '/');
    }
    $base = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.');
    while (preg_match('#/(?:admin|api|install)$#', $base)) {
        $base = rtrim(str_replace('\\', '/', dirname($base)), '/.');
    }
    return $base === '/' ? '' : $base;
}

$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$requestIsSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieBase = mousavi_app_base_path();
    $cookiePath = $cookieBase === '' ? '/' : $cookieBase . '/';
    @session_name('MOUSAVISESSID');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $requestIsSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!defined('CSP_NONCE')) {
    define('CSP_NONCE', rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='));
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if ($requestIsSecure) header('Strict-Transport-Security: max-age=15552000');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self' 'nonce-" . CSP_NONCE . "'; script-src-attr 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'" . ($requestIsSecure ? '; upgrade-insecure-requests' : ''));
}

date_default_timezone_set('Asia/Tehran');

$configFile = BASE_PATH . '/config/database.php';
$lockFile = BASE_PATH . '/storage/install.lock';
if (!is_file($configFile) || !is_file($lockFile)) {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!str_contains($script, '/install/')) {
        header('Location: ' . mousavi_app_base_path() . '/install/');
        exit;
    }
}

require_once BASE_PATH . '/app/helpers.php';
require_once BASE_PATH . '/app/jalali.php';
require_once BASE_PATH . '/app/database.php';
require_once BASE_PATH . '/app/migrations.php';

/*
 * دیتابیس در نخستین درخواست پس از جایگزینی فایل‌های نسخه جدید، به‌صورت قفل‌شده و خودکار ارتقا می‌یابد.
 * صفحه upgrade از اجرای خودکار مستثنا است تا در صورت شکست، مسیر تعمیر دستی همیشه باز بماند.
 */
$scriptNameForMigration = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$isMigrationBypassPage = str_ends_with($scriptNameForMigration, '/admin/upgrade.php')
    || str_ends_with($scriptNameForMigration, '/admin/logout.php');
$isInstalled = is_file($configFile) && is_file($lockFile);
if ($isInstalled && !$isMigrationBypassPage) {
    try {
        $migrationResults = ensure_mousavi_schema(db());
        if ($migrationResults !== []) {
            $_SESSION['_flash'][] = [
                'type' => 'success',
                'message' => 'پایگاه داده به‌صورت خودکار به نسخه جدید ارتقا یافت.',
            ];
        }
    } catch (Throwable $migrationError) {
        log_error($migrationError);
        $_SESSION['_migration_error'] = 'ارتقای خودکار دیتابیس انجام نشد. دسترسی CREATE و ALTER کاربر دیتابیس و دسترسی نوشتن پوشه storage را بررسی کنید.';
        $_SESSION['_migration_error_ref'] = date('Ymd-His');

        $isAdminRequest = str_contains($scriptNameForMigration, '/admin/');
        $isApiRequest = str_contains($scriptNameForMigration, '/api/')
            || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        if ($isAdminRequest) {
            header('Location: ' . mousavi_app_base_path() . '/admin/upgrade.php?repair=1');
            exit;
        }
        if ($isApiRequest) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: 60');
            echo json_encode([
                'ok' => false,
                'message' => 'فروشگاه در حال ارتقای پایگاه داده است. کمی بعد دوباره تلاش کنید.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 60');
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>به‌روزرسانی فروشگاه</title><body style="font-family:Tahoma,sans-serif;max-width:680px;margin:80px auto;padding:24px;line-height:2"><h1>فروشگاه در حال به‌روزرسانی است</h1><p>ارتقای پایگاه داده کامل نشده است. مدیر فروشگاه باید وارد بخش ارتقای سیستم شود.</p></body></html>';
        exit;
    }
}

if ($isInstalled && PHP_SAPI !== 'cli' && !headers_sent()) {
    $configuredSiteUrl = rtrim(trim((string)setting('site_url', '')), '/');
    $siteParts = $configuredSiteUrl !== '' ? parse_url($configuredSiteUrl) : false;
    if (is_array($siteParts) && !empty($siteParts['scheme']) && !empty($siteParts['host'])) {
        $configuredScheme = strtolower((string)$siteParts['scheme']);
        $requestScheme = $requestIsSecure ? 'https' : 'http';
        $requestParts = parse_url($requestScheme.'://'.request_host());
        $configuredPort = (int)($siteParts['port'] ?? ($configuredScheme === 'https' ? 443 : 80));
        $requestPort = is_array($requestParts) ? (int)($requestParts['port'] ?? ($requestScheme === 'https' ? 443 : 80)) : 0;
        $sameHost = is_array($requestParts) && hash_equals(strtolower((string)$siteParts['host']), strtolower((string)($requestParts['host'] ?? '')));
        if (!$sameHost || $configuredPort !== $requestPort || $configuredScheme !== $requestScheme) {
            $relativePath = current_relative_path();
            $location = $configuredSiteUrl.($relativePath !== '' ? '/'.ltrim($relativePath, '/') : '');
            if ($_GET !== []) $location .= '?'.http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
            header('Location: '.$location, true, in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','HEAD'], true) ? 301 : 308);
            exit;
        }
    }
}

require_once BASE_PATH . '/app/festivals.php';
require_once BASE_PATH . '/app/price-adjustments.php';
require_once BASE_PATH . '/app/shipping.php';
require_once BASE_PATH . '/app/accounting.php';
require_once BASE_PATH . '/app/orders.php';
require_once BASE_PATH . '/app/admin-notifications.php';
require_once BASE_PATH . '/app/sms.php';
require_once BASE_PATH . '/app/variants.php';
require_once BASE_PATH . '/app/inventory.php';
require_once BASE_PATH . '/app/payments.php';
require_once BASE_PATH . '/app/torobpay.php';
require_once BASE_PATH . '/app/snappay.php';
require_once BASE_PATH . '/app/returns.php';
require_once BASE_PATH . '/app/shipments.php';
require_once BASE_PATH . '/app/backups.php';
require_once BASE_PATH . '/app/customer-auth.php';
require_once BASE_PATH . '/app/repositories.php';
require_once BASE_PATH . '/app/storefront-content.php';
require_once BASE_PATH . '/app/blog.php';
require_once BASE_PATH . '/app/seo.php';
require_once BASE_PATH . '/app/product-gallery.php';
require_once BASE_PATH . '/app/torob-product-api.php';
require_once BASE_PATH . '/app/quick-product-service.php';
require_once BASE_PATH . '/app/taxonomy-management.php';
require_once BASE_PATH . '/app/bale-product-bot.php';
require_once BASE_PATH . '/app/product-alerts.php';
require_once BASE_PATH . '/app/tickets.php';
require_once BASE_PATH . '/app/engagement.php';
require_once BASE_PATH . '/app/marketing.php';
require_once BASE_PATH . '/app/reports.php';
require_once BASE_PATH . '/app/system-health.php';
require_once BASE_PATH . '/app/admin-security.php';
require_once BASE_PATH . '/app/auth.php';

set_exception_handler(static function (Throwable $exception): void {
    log_error($exception);
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    if (!headers_sent()) header('Cache-Control: no-store');
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || str_contains($scriptName, '/api/');
    if ($wantsJson) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'خطای داخلی سرور رخ داد. دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } else {
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
        echo 'خطای داخلی سرور رخ داد. لطفاً دوباره تلاش کنید.';
    }
    exit;
});
