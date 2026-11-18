<?php
declare(strict_types=1);

function system_setting_fresh(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : $default;
    } catch (Throwable $exception) {
        log_error($exception);
        return $default;
    }
}

function system_setting_write(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function central_cron_key(): string
{
    $key = trim(system_setting_fresh('central_cron_key'));
    if (preg_match('/^[a-f0-9]{48,128}$/i', $key)) {
        return strtolower($key);
    }

    $fallbackPath = BASE_PATH . '/storage/central-cron.key';
    try {
        if (is_file($fallbackPath)) {
            $fallback = trim((string) @file_get_contents($fallbackPath));
            if (preg_match('/^[a-f0-9]{48,128}$/i', $fallback)) {
                try {
                    system_setting_write('central_cron_key', strtolower($fallback));
                } catch (Throwable $exception) {
                    log_error($exception);
                }
                return strtolower($fallback);
            }
        }
    } catch (Throwable $exception) {
        log_error($exception);
    }

    $key = bin2hex(random_bytes(32));
    try {
        system_setting_write('central_cron_key', $key);
    } catch (Throwable $exception) {
        log_error($exception);
        try {
            if (is_dir(dirname($fallbackPath)) || @mkdir(dirname($fallbackPath), 0755, true)) {
                @file_put_contents($fallbackPath, $key, LOCK_EX);
                @chmod($fallbackPath, 0600);
            }
        } catch (Throwable $fileException) {
            log_error($fileException);
        }
    }
    return $key;
}

function central_cron_url(): string
{
    return app_url('cron.php?key=' . rawurlencode(central_cron_key()));
}

function central_shell_quote(string $value): string
{
    if (function_exists('escapeshellarg')) {
        try {
            return escapeshellarg($value);
        } catch (Throwable $exception) {
            log_error($exception);
        }
    }
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function central_cron_cli_command(?string $phpBinary = null): string
{
    $phpBinary = trim((string) $phpBinary);
    if ($phpBinary === '') {
        $phpBinary = '/usr/local/bin/php';
    }
    return central_shell_quote($phpBinary) . ' -q ' . central_shell_quote(BASE_PATH . '/cron.php') . ' >/dev/null 2>&1';
}

function central_cron_last_result(): array
{
    $raw = system_setting_fresh('central_cron_last_result', '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function central_cron_is_fresh(int $maxAgeMinutes = 25): bool
{
    $last = trim(system_setting_fresh('central_cron_last_run_at', ''));
    $timestamp = $last !== '' ? strtotime($last) : false;
    return $timestamp !== false && $timestamp >= time() - max(5, $maxAgeMinutes) * 60;
}

function central_health_check(string $key, string $title, string $status, string $message, array $meta = []): array
{
    if (!in_array($status, ['ok', 'warning', 'error'], true)) {
        $status = 'warning';
    }
    return [
        'key' => $key,
        'title' => $title,
        'status' => $status,
        'message' => $message,
        'meta' => $meta,
    ];
}

function system_health_sms_enabled(): bool
{
    try {
        if (function_exists('sms_enabled')) {
            return (bool) sms_enabled();
        }
        return system_setting_fresh('sms_enabled', '0') === '1';
    } catch (Throwable $exception) {
        log_error($exception);
        return false;
    }
}

function system_health_sms_is_sandbox(): bool
{
    try {
        if (function_exists('sms_is_sandbox')) {
            return (bool) sms_is_sandbox();
        }
        return system_setting_fresh('sms_mode', 'sandbox') === 'sandbox';
    } catch (Throwable $exception) {
        log_error($exception);
        return true;
    }
}

function system_health_sms_has_api_key(): bool
{
    try {
        if (function_exists('sms_api_key')) {
            return trim((string) sms_api_key()) !== '';
        }

        $stored = trim(system_setting_fresh('sms_api_key_enc', ''));
        if ($stored === '') {
            return false;
        }
        if (function_exists('reveal_secret')) {
            return trim((string) reveal_secret($stored)) !== '';
        }

        // در نسخه‌های قدیمی ممکن است مقدار کلید بدون تابع رمزگشایی ذخیره شده باشد.
        return true;
    } catch (Throwable $exception) {
        log_error($exception);
        return false;
    }
}

function system_health_sms_live_ready(): bool
{
    try {
        if (function_exists('sms_live_ready')) {
            return (bool) sms_live_ready();
        }
        return system_health_sms_enabled()
            && !system_health_sms_is_sandbox()
            && system_health_sms_has_api_key();
    } catch (Throwable $exception) {
        log_error($exception);
        return false;
    }
}

function central_system_health_checks(): array
{
    $checks = [];

    try {
        $databaseOk = (int) db()->query('SELECT 1')->fetchColumn() === 1;
        $checks[] = central_health_check(
            'database',
            'اتصال پایگاه داده',
            $databaseOk ? 'ok' : 'error',
            $databaseOk ? 'اتصال MySQL برقرار است.' : 'پایگاه داده پاسخ معتبر نداد.'
        );
    } catch (Throwable $exception) {
        $checks[] = central_health_check('database', 'اتصال پایگاه داده', 'error', 'اتصال یا اجرای کوئری آزمایشی ناموفق است.');
        log_error($exception);
    }

    try {
        $currentSchema = mousavi_schema_version(db());
        $schemaStatus = version_compare($currentSchema, MOUSAVI_SCHEMA_VERSION, '>=') ? 'ok' : 'error';
        $checks[] = central_health_check(
            'schema',
            'ساختار دیتابیس',
            $schemaStatus,
            $schemaStatus === 'ok'
                ? 'ساختار دیتابیس با نسخه برنامه هماهنگ است.'
                : 'ارتقای دیتابیس کامل نشده است؛ بخش ارتقای سیستم را اجرا کنید.',
            ['current' => $currentSchema, 'expected' => MOUSAVI_SCHEMA_VERSION]
        );
    } catch (Throwable $exception) {
        $checks[] = central_health_check('schema', 'ساختار دیتابیس', 'error', 'نسخه ساختار دیتابیس قابل تشخیص نیست.');
        log_error($exception);
    }

    $requiredExtensions = ['pdo_mysql', 'curl', 'openssl', 'sodium', 'gd', 'json', 'mbstring', 'fileinfo'];
    $missingExtensions = array_values(array_filter($requiredExtensions, static fn(string $extension): bool => !extension_loaded($extension)));
    $checks[] = central_health_check(
        'php_extensions',
        'افزونه‌های PHP',
        $missingExtensions ? 'error' : 'ok',
        $missingExtensions ? 'افزونه‌های ضروری فعال نیستند: ' . implode('، ', $missingExtensions) : 'افزونه‌های ضروری PHP فعال هستند.',
        ['missing' => $missingExtensions, 'gd' => extension_loaded('gd')]
    );
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        $checks[] = central_health_check('image_optimizer', 'آماده‌سازی تصویر محصول', 'error', 'افزونه GD با پشتیبانی WebP فعال نیست؛ آپلود تصویر محصول تا فعال‌شدن آن متوقف می‌شود.');
    } else {
        $checks[] = central_health_check('image_optimizer', 'آماده‌سازی تصویر محصول', 'ok', 'هر تصویر محصول خودکار بدون برش یا کشیدگی به WebP سفید ۱۲۰۰×۱۲۰۰ آماده ترب تبدیل می‌شود.');
    }

    $writablePaths = [
        'storage' => BASE_PATH . '/storage',
        'گزارش خطا' => BASE_PATH . '/storage/logs',
        'تصاویر محصولات' => BASE_PATH . '/uploads/products',
    ];
    $notWritable = [];
    foreach ($writablePaths as $label => $path) {
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            $notWritable[] = $label;
            continue;
        }
        if (!is_writable($path)) {
            $notWritable[] = $label;
        }
    }
    $checks[] = central_health_check(
        'writable_paths',
        'دسترسی پوشه‌ها',
        $notWritable ? 'error' : 'ok',
        $notWritable ? 'دسترسی نوشتن این مسیرها مشکل دارد: ' . implode('، ', $notWritable) : 'پوشه‌های ذخیره‌سازی و تصاویر قابل نوشتن هستند.',
        ['not_writable' => $notWritable]
    );

    if (function_exists('application_secret_file_state')) {
        $secretState=application_secret_file_state();
        if (!empty($secretState['exists']) && !empty($secretState['valid']) && !empty($secretState['readable'])) {
            $checks[]=central_health_check('app_key','کلید محرمانه برنامه','ok','فایل storage/app.key سالم و قابل‌خواندن است.');
        } elseif (!empty($secretState['exists'])) {
            $checks[]=central_health_check('app_key','کلید محرمانه برنامه','error','فایل storage/app.key خراب یا ناخوانا است؛ آن را بازنویسی نکنید. نسخه پشتیبان را برگردانید یا API Key سرویس‌ها را دوباره ذخیره کنید.');
        } else {
            $checks[]=central_health_check('app_key','کلید محرمانه برنامه','warning','فایل storage/app.key وجود ندارد. در سایت ارتقایافته، نسخه پشتیبان این فایل را برگردانید تا کلیدهای رمز‌شده قبلی قابل‌خواندن بمانند.');
        }
    }

    $siteUrl = rtrim(trim(system_setting_fresh('site_url', (string) setting('site_url', ''))), '/');
    $siteParts = $siteUrl !== '' ? parse_url($siteUrl) : false;
    $siteSecure = is_array($siteParts) && strtolower((string) ($siteParts['scheme'] ?? '')) === 'https' && !empty($siteParts['host']);
    $checks[] = central_health_check(
        'site_url',
        'دامنه و SSL',
        $siteSecure ? 'ok' : 'error',
        $siteSecure ? 'آدرس اصلی سایت با HTTPS تنظیم شده است: ' . $siteUrl : 'آدرس کامل سایت باید با https:// و دامنه صحیح ذخیره شود.',
        ['site_url' => $siteUrl]
    );

    if (function_exists('torob_api_v3_diagnostics')) {
        try {
            $torob = torob_api_v3_diagnostics(db());
            $torobCritical = empty($torob['sodium']) || empty($torob['https']);
            $torobEmpty = (int)($torob['total'] ?? 0) < 1;
            $torobStatus = $torobCritical ? 'error' : ($torobEmpty || !empty($torob['warnings']) ? 'warning' : 'ok');
            $torobMessage = $torobCritical
                ? 'پیش‌نیاز امنیتی JWT یا HTTPS برای Product API v3 کامل نیست.'
                : ($torobEmpty
                    ? 'API پاسخ می‌دهد اما محصول واجد شرایطی برای ترب ندارد.'
                    : fa_number((int)$torob['total']) . ' ردیف محصول/مدل در خروجی استاندارد ترب آماده است.');
            $checks[] = central_health_check('torob_product_api', 'Product API v3 ترب', $torobStatus, $torobMessage, [
                'endpoint'=>$torob['endpoint'] ?? '',
                'total'=>(int)($torob['total'] ?? 0),
                'warnings'=>$torob['warnings'] ?? [],
            ]);
        } catch (Throwable $exception) {
            $checks[] = central_health_check('torob_product_api', 'Product API v3 ترب', 'error', 'ساخت یا بررسی خروجی محصولات ترب ناموفق است.');
            log_error($exception);
        }
    }

    $smsEnabled = system_health_sms_enabled();
    $smsSandbox = system_health_sms_is_sandbox();
    $smsReady = system_health_sms_live_ready();
    if (!$smsEnabled) {
        $checks[] = central_health_check('sms', 'سامانه پیامک', 'warning', 'سامانه پیامک غیرفعال است؛ پیام‌های خرید و هشدار محصول ارسال نمی‌شوند.');
    } elseif ($smsSandbox) {
        $checks[] = central_health_check('sms', 'سامانه پیامک', 'warning', 'سامانه پیامک روی Sandbox است و ارسال واقعی انجام نمی‌شود.');
    } elseif (!$smsReady) {
        $smsState=function_exists('sms_api_key_state')?sms_api_key_state():[];
        $checks[] = central_health_check('sms', 'سامانه پیامک', 'error', (string)($smsState['message']??'ارسال واقعی فعال است اما API Key معتبر در دسترس نیست.'));
    } else {
        $checks[] = central_health_check('sms', 'سامانه پیامک', 'ok', 'SMS.ir در حالت واقعی و با API Key آماده است.');
    }

    try {
        $failedSms = (int) db()->query("SELECT COUNT(*) FROM sms_logs WHERE status='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
        $checks[] = central_health_check(
            'sms_failures',
            'خطاهای پیامک ۲۴ ساعت اخیر',
            $failedSms > 0 ? 'warning' : 'ok',
            $failedSms > 0 ? fa_number($failedSms) . ' ارسال ناموفق ثبت شده است؛ گزارش پیامک‌ها را بررسی کنید.' : 'ارسال ناموفقی در ۲۴ ساعت گذشته ثبت نشده است.',
            ['count' => $failedSms]
        );
    } catch (Throwable $exception) {
        $checks[] = central_health_check('sms_failures', 'خطاهای پیامک ۲۴ ساعت اخیر', 'warning', 'گزارش خطاهای پیامک قابل خواندن نیست.');
        log_error($exception);
    }

    $lastCron = trim(system_setting_fresh('central_cron_last_run_at', ''));
    $cronFresh = central_cron_is_fresh(25);
    $checks[] = central_health_check(
        'central_cron',
        'Cron مرکزی',
        $cronFresh ? 'ok' : 'error',
        $cronFresh
            ? 'Cron مرکزی اخیراً اجرا شده است.'
            : ($lastCron === '' ? 'Cron مرکزی هنوز اجرا نشده است.' : 'از آخرین اجرای Cron مرکزی بیش از ۲۵ دقیقه گذشته است.'),
        ['last_run_at' => $lastCron]
    );

    $backupEnabled = system_setting_fresh('backup_enabled', '1') === '1';
    $lastBackup = trim(system_setting_fresh('backup_last_success_at', ''));
    $backupIntervalHours = max(1, min(168, (int) system_setting_fresh('backup_interval_hours', '24')));
    $lastBackupTime = $lastBackup !== '' ? strtotime($lastBackup) : false;
    if (!$backupEnabled) {
        $backupStatus = 'warning';
        $backupMessage = 'بکاپ خودکار غیرفعال است.';
    } elseif ($lastBackupTime === false) {
        $backupStatus = 'warning';
        $backupMessage = 'هنوز بکاپ خودکار موفقی ثبت نشده است.';
    } elseif ($lastBackupTime < time() - ($backupIntervalHours * 2 * 3600)) {
        $backupStatus = 'warning';
        $backupMessage = 'آخرین بکاپ موفق قدیمی‌تر از بازه انتظار است.';
    } else {
        $backupStatus = 'ok';
        $backupMessage = 'آخرین بکاپ موفق در بازه قابل قبول قرار دارد.';
    }
    $checks[] = central_health_check('backup', 'بکاپ خودکار', $backupStatus, $backupMessage, ['last_success_at' => $lastBackup, 'interval_hours' => $backupIntervalHours]);

    if (!function_exists('disk_free_space')) {
        $checks[] = central_health_check('disk', 'فضای آزاد هاست', 'warning', 'تابع بررسی فضای دیسک در تنظیمات PHP هاست غیرفعال است؛ این مورد مانع فعالیت فروشگاه نیست.');
    } else {
        try {
            $freeBytes = @disk_free_space(BASE_PATH);
            if (is_float($freeBytes) || is_int($freeBytes)) {
                $freeBytes = (float) $freeBytes;
                $diskStatus = $freeBytes < 500 * 1024 * 1024 ? 'error' : ($freeBytes < 1024 * 1024 * 1024 ? 'warning' : 'ok');
                $checks[] = central_health_check(
                    'disk',
                    'فضای آزاد هاست',
                    $diskStatus,
                    'فضای آزاد تقریبی: ' . number_format($freeBytes / 1073741824, 2) . ' گیگابایت.',
                    ['free_bytes' => $freeBytes]
                );
            } else {
                $checks[] = central_health_check('disk', 'فضای آزاد هاست', 'warning', 'هاست مقدار فضای آزاد را در اختیار PHP قرار نداد.');
            }
        } catch (Throwable $exception) {
            $checks[] = central_health_check('disk', 'فضای آزاد هاست', 'warning', 'بررسی فضای آزاد توسط محدودیت‌های هاست انجام نشد.');
            log_error($exception);
        }
    }

    return $checks;
}

function central_system_health_summary(array $checks): array
{
    $summary = ['ok' => 0, 'warning' => 0, 'error' => 0, 'status' => 'ok'];
    foreach ($checks as $check) {
        $status = (string) ($check['status'] ?? 'warning');
        if (!isset($summary[$status])) {
            $status = 'warning';
        }
        $summary[$status]++;
    }
    $summary['status'] = $summary['error'] > 0 ? 'error' : ($summary['warning'] > 0 ? 'warning' : 'ok');
    return $summary;
}

function central_cron_backup_due(): bool
{
    if (system_setting_fresh('backup_enabled', '1') !== '1') {
        return false;
    }
    $hours = max(1, min(168, (int) system_setting_fresh('backup_interval_hours', '24')));
    $last = trim(system_setting_fresh('backup_last_success_at', ''));
    $lastTimestamp = $last !== '' ? strtotime($last) : false;
    return $lastTimestamp === false || $lastTimestamp <= time() - $hours * 3600;
}

function central_cron_task(string $title, string $status, string $message, array $data = []): array
{
    if (!in_array($status, ['success', 'skipped', 'warning', 'failed'], true)) {
        $status = 'warning';
    }
    return ['title' => $title, 'status' => $status, 'message' => $message, 'data' => $data];
}

function central_cron_run_all(string $trigger = 'cron'): array
{
    $startedAt = date('Y-m-d H:i:s');
    $started = microtime(true);
    $lockPath = BASE_PATH . '/storage/central-cron.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return [
            'ok' => false,
            'locked' => true,
            'status' => 'warning',
            'message' => 'یک اجرای دیگر Cron مرکزی هنوز در حال پردازش است.',
            'started_at' => $startedAt,
            'tasks' => [],
        ];
    }

    $tasks = [];
    $hasFailure = false;
    $hasWarning = false;

    try {
        system_setting_write('central_cron_last_run_at', $startedAt);
        system_setting_write('central_cron_last_trigger', text_substr($trigger, 0, 40));

        try {
            if (system_setting_fresh('product_alerts_enabled', '1') !== '1') {
                $tasks['product_alerts'] = central_cron_task('هشدار موجودی و قیمت', 'skipped', 'ثبت هشدار محصولات غیرفعال است.');
            } else {
                $alertResult = process_product_alerts(max(1, min(100, (int) system_setting_fresh('product_alert_batch_size', '30'))));
                $paused = !empty($alertResult['paused']);
                $failed = (int) ($alertResult['failed'] ?? 0);
                $status = $paused || $failed > 0 ? 'warning' : 'success';
                $tasks['product_alerts'] = central_cron_task('هشدار موجودی و قیمت', $status, (string) ($alertResult['message'] ?? 'پردازش انجام شد.'), $alertResult);
                $hasWarning = $hasWarning || $status === 'warning';
            }
        } catch (Throwable $exception) {
            $hasFailure = true;
            $tasks['product_alerts'] = central_cron_task('هشدار موجودی و قیمت', 'failed', $exception->getMessage());
            log_error($exception);
        }

        try {
            $retryResult=sms_retry_failed_order_messages(max(1,min(30,(int)system_setting_fresh('sms_transactional_retry_batch','10'))));
            $retryStatus=!empty($retryResult['paused'])?'skipped':((int)($retryResult['failed']??0)>0?'warning':'success');
            $tasks['transactional_sms_retry']=central_cron_task('تلاش مجدد پیامک خرید',$retryStatus,(string)($retryResult['message']??'پردازش انجام شد.'),$retryResult);
            $hasWarning=$hasWarning||$retryStatus==='warning';
        } catch (Throwable $exception) {
            $hasWarning=true;
            $tasks['transactional_sms_retry']=central_cron_task('تلاش مجدد پیامک خرید','warning','بررسی پیامک‌های ناموفق کامل نشد.');
            log_error($exception);
        }


        try {
            $deliveryResult=sms_sync_delivery_reports(max(1,min(50,(int)system_setting_fresh('sms_delivery_sync_batch','20'))));
            $deliveryStatus=!empty($deliveryResult['paused'])?'skipped':((int)($deliveryResult['failed']??0)>0?'warning':'success');
            $tasks['sms_delivery_reports']=central_cron_task('گزارش تحویل پیامک',$deliveryStatus,(string)($deliveryResult['message']??'بررسی انجام شد.'),$deliveryResult);
            $hasWarning=$hasWarning||$deliveryStatus==='warning';
        } catch (Throwable $exception) {
            $hasWarning=true;
            $tasks['sms_delivery_reports']=central_cron_task('گزارش تحویل پیامک','warning','دریافت نتیجه تحویل بعضی پیامک‌ها کامل نشد.');
            log_error($exception);
        }

        try {
            if (!snappay_enabled()) {
                $tasks['snappay_reconciliation'] = central_cron_task('تطبیق تراکنش‌های اسنپ‌پی', 'skipped', 'درگاه اسنپ‌پی فعال نیست.');
            } else {
                $snappayResult = snappay_reconcile_pending_transactions(5);
                $snappayStatus = (int)($snappayResult['errors'] ?? 0) > 0 ? 'warning' : 'success';
                $tasks['snappay_reconciliation'] = central_cron_task('تطبیق تراکنش‌های اسنپ‌پی', $snappayStatus, 'تراکنش‌های معلق اسنپ‌پی بررسی شدند.', $snappayResult);
                $hasWarning = $hasWarning || $snappayStatus === 'warning';
            }
        } catch (Throwable $exception) {
            $hasWarning = true;
            $tasks['snappay_reconciliation'] = central_cron_task('تطبیق تراکنش‌های اسنپ‌پی', 'warning', 'تطبیق تراکنش‌های اسنپ‌پی کامل نشد.');
            log_error($exception);
        }

        try {
            if (system_setting_fresh('sms_marketing_enabled', '1') !== '1') {
                $tasks['sms_campaigns'] = central_cron_task('کمپین‌های پیامکی', 'skipped', 'پیامک گروهی غیرفعال است.');
            } else {
                $campaignIds = db()->query("SELECT id FROM sms_campaigns WHERE status='running' ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
                if (!$campaignIds) {
                    $tasks['sms_campaigns'] = central_cron_task('کمپین‌های پیامکی', 'success', 'کمپین فعالی برای پردازش وجود ندارد.', ['campaigns' => 0, 'processed' => 0, 'sent' => 0, 'failed' => 0]);
                } else {
                    $campaignSummary = ['campaigns' => count($campaignIds), 'processed' => 0, 'sent' => 0, 'failed' => 0];
                    foreach ($campaignIds as $campaignId) {
                        $campaignResult = process_sms_campaign((int) $campaignId, 50);
                        $campaignSummary['processed'] += (int) ($campaignResult['processed'] ?? 0);
                        $campaignSummary['sent'] += (int) ($campaignResult['sent'] ?? 0);
                        $campaignSummary['failed'] += (int) ($campaignResult['failed'] ?? 0);
                    }
                    $status = $campaignSummary['failed'] > 0 ? 'warning' : 'success';
                    $tasks['sms_campaigns'] = central_cron_task('کمپین‌های پیامکی', $status, 'کمپین‌های فعال پردازش شدند.', $campaignSummary);
                    $hasWarning = $hasWarning || $status === 'warning';
                }
            }
        } catch (Throwable $exception) {
            $hasFailure = true;
            $tasks['sms_campaigns'] = central_cron_task('کمپین‌های پیامکی', 'failed', $exception->getMessage());
            log_error($exception);
        }

        try {
            if (system_setting_fresh('backup_enabled', '1') !== '1') {
                $tasks['backup'] = central_cron_task('بکاپ خودکار', 'skipped', 'بکاپ خودکار غیرفعال است.');
            } elseif (!central_cron_backup_due()) {
                $tasks['backup'] = central_cron_task('بکاپ خودکار', 'success', 'هنوز زمان بکاپ بعدی نرسیده است.');
            } else {
                $created = backup_maybe_run('central_cron');
                if ($created) {
                    $tasks['backup'] = central_cron_task('بکاپ خودکار', 'success', 'بکاپ زمان‌بندی‌شده با موفقیت ساخته شد.');
                } else {
                    $hasWarning = true;
                    $tasks['backup'] = central_cron_task('بکاپ خودکار', 'warning', 'زمان بکاپ رسیده بود اما فایل جدید ساخته نشد؛ قفل هم‌زمانی یا گزارش بکاپ را بررسی کنید.');
                }
            }
        } catch (Throwable $exception) {
            $hasFailure = true;
            $tasks['backup'] = central_cron_task('بکاپ خودکار', 'failed', $exception->getMessage());
            log_error($exception);
        }

        try {
            $deletedOtp = db()->exec("DELETE FROM customer_otp_codes WHERE expires_at<DATE_SUB(NOW(),INTERVAL 2 DAY)");
            $notificationDays=max(7,min(365,(int)system_setting_fresh('admin_notification_retention_days','90')));
            $deletedNotifications=db()->exec('DELETE FROM admin_notifications WHERE created_at<DATE_SUB(NOW(),INTERVAL '.$notificationDays.' DAY)');
            $deletedSnappayLocks=0;foreach(glob(BASE_PATH.'/storage/snappay-verify-*.lock')?:[] as $lockFile)if(is_file($lockFile)&&(int)@filemtime($lockFile)<time()-604800&&@unlink($lockFile))$deletedSnappayLocks++;
            $tasks['housekeeping'] = central_cron_task('نگهداری سبک', 'success', 'داده‌های موقت و اعلان‌های قدیمی پاک‌سازی شدند.', ['expired_otp_deleted' => (int) $deletedOtp,'old_notifications_deleted'=>(int)$deletedNotifications,'snappay_locks_deleted'=>$deletedSnappayLocks]);
        } catch (Throwable $exception) {
            $hasWarning = true;
            $tasks['housekeeping'] = central_cron_task('نگهداری سبک', 'warning', 'پاک‌سازی داده‌های موقت کامل نشد.');
            log_error($exception);
        }

        $checks = central_system_health_checks();
        $healthSummary = central_system_health_summary($checks);
        if ($healthSummary['status'] === 'error') {
            $hasWarning = true;
        } elseif ($healthSummary['status'] === 'warning') {
            $hasWarning = true;
        }

        $status = $hasFailure ? 'error' : ($hasWarning ? 'warning' : 'success');
        $finishedAt = date('Y-m-d H:i:s');
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $result = [
            'ok' => !$hasFailure,
            'locked' => false,
            'status' => $status,
            'message' => $status === 'success' ? 'Cron مرکزی با موفقیت اجرا شد.' : ($status === 'warning' ? 'Cron مرکزی اجرا شد و چند مورد نیازمند بررسی است.' : 'بخشی از Cron مرکزی با خطا روبه‌رو شد.'),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'trigger' => $trigger,
            'tasks' => $tasks,
            'health' => $healthSummary,
        ];
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        system_setting_write('central_cron_last_status', $status);
        system_setting_write('central_cron_last_success_at', !$hasFailure ? $finishedAt : system_setting_fresh('central_cron_last_success_at', ''));
        system_setting_write('central_cron_last_duration_ms', (string) $durationMs);
        system_setting_write('central_cron_last_result', is_string($encoded) ? $encoded : '{}');
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
