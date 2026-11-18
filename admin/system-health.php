<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'run_now');
    try {
        if ($action === 'run_now') {
            $result = central_cron_run_all('admin');
            if (!empty($result['locked'])) {
                flash('error', 'یک اجرای دیگر Cron مرکزی هنوز در حال انجام است.');
            } elseif (!empty($result['ok'])) {
                flash(($result['status'] ?? '') === 'warning' ? 'error' : 'success', (string) ($result['message'] ?? 'اجرای مرکزی انجام شد.'));
            } else {
                flash('error', (string) ($result['message'] ?? 'اجرای مرکزی کامل نشد.'));
            }
            admin_audit_event('central_cron_manual_run', 'system', null, ['status' => $result['status'] ?? 'unknown']);
        } elseif ($action === 'rotate_key') {
            $newKey = bin2hex(random_bytes(32));
            system_setting_write('central_cron_key', $newKey);
            admin_audit_event('central_cron_key_rotated', 'system');
            flash('success', 'کلید Cron مرکزی تغییر کرد. اگر از اجرای URL استفاده می‌کنید، نشانی Cron را در هاست به‌روزرسانی کنید.');
        }
    } catch (Throwable $exception) {
        log_error($exception);
        flash('error', $exception->getMessage());
    }
    redirect(app_url('admin/system-health.php'));
}

try {
    $checks = central_system_health_checks();
} catch (Throwable $exception) {
    log_error($exception);
    $checks = [central_health_check('health_page', 'خودآزمایی صفحه سلامت', 'error', 'یکی از بررسی‌های سلامت توسط محدودیت‌های هاست متوقف شد؛ جزئیات در گزارش خطای PHP ثبت شده است.')];
}
$summary = central_system_health_summary($checks);
try {
    $lastResult = central_cron_last_result();
} catch (Throwable $exception) {
    log_error($exception);
    $lastResult = [];
}
$lastRun = system_setting_fresh('central_cron_last_run_at', '');
$lastStatus = system_setting_fresh('central_cron_last_status', '');
$lastDuration = (int) system_setting_fresh('central_cron_last_duration_ms', '0');
try {
    $centralUrl = central_cron_url();
} catch (Throwable $exception) {
    log_error($exception);
    $centralUrl = app_url('cron.php');
    $checks[] = central_health_check('cron_key', 'کلید Cron', 'warning', 'کلید اجرای URL ساخته نشد؛ اجرای CLI همچنان قابل استفاده است.');
    $summary = central_system_health_summary($checks);
}
try {
    $cliCommand = central_cron_cli_command('/usr/local/bin/php');
    $eaPhpCommand = central_cron_cli_command('/opt/cpanel/ea-php83/root/usr/bin/php');
} catch (Throwable $exception) {
    log_error($exception);
    $cliCommand = '/usr/local/bin/php -q ' . BASE_PATH . '/cron.php >/dev/null 2>&1';
    $eaPhpCommand = '/opt/cpanel/ea-php83/root/usr/bin/php -q ' . BASE_PATH . '/cron.php >/dev/null 2>&1';
}
$statusLabels = ['ok' => 'سالم', 'warning' => 'نیازمند بررسی', 'error' => 'خطا'];
$taskLabels = ['success' => 'موفق', 'skipped' => 'ردشده', 'warning' => 'هشدار', 'failed' => 'ناموفق'];
$adminPage = 'system-health';
$adminTitle = 'سلامت سایت و Cron مرکزی';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title">
    <div>
        <h1>سلامت سایت و Cron مرکزی</h1>
        <p class="muted">تمام وظایف زمان‌بندی‌شده فروشگاه از یک مسیر اجرا می‌شوند: هشدار محصول، کمپین پیامکی، بکاپ و پاک‌سازی داده‌های موقت.</p>
    </div>
    <div class="admin-actions">
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="run_now"><button class="btn btn-primary">اجرای کامل همین حالا</button></form>
        <a class="btn btn-secondary" href="<?=e(app_url('admin/upgrade.php'))?>">تعمیر دیتابیس</a>
    </div>
</div>

<section class="health-overview health-overview--<?=e($summary['status'])?>">
    <div class="health-overview__main">
        <span class="health-overview__signal"></span>
        <div>
            <strong><?=e($summary['status'] === 'ok' ? 'سامانه سالم است' : ($summary['status'] === 'warning' ? 'سامانه فعال است؛ چند مورد را بررسی کنید' : 'سامانه نیازمند اصلاح است'))?></strong>
            <small><?=fa_number($summary['ok'])?> سالم · <?=fa_number($summary['warning'])?> هشدار · <?=fa_number($summary['error'])?> خطا</small>
        </div>
    </div>
    <div class="health-overview__meta">
        <span>آخرین Cron: <strong><?=e($lastRun !== '' ? (function_exists('jalali_format') ? jalali_format($lastRun) : $lastRun) : 'هنوز اجرا نشده')?></strong></span>
        <span>مدت اجرا: <strong><?=$lastDuration > 0 ? fa_number($lastDuration) . ' میلی‌ثانیه' : '—'?></strong></span>
        <span>نتیجه: <strong><?=e(['success' => 'موفق', 'warning' => 'هشدار', 'error' => 'خطا'][$lastStatus] ?? '—')?></strong></span>
    </div>
</section>

<div class="health-grid">
    <?php foreach ($checks as $check): ?>
        <article class="card health-check health-check--<?=e($check['status'])?>">
            <div class="health-check__head"><span></span><strong><?=e($check['title'])?></strong><em><?=e($statusLabels[$check['status']] ?? 'بررسی')?></em></div>
            <p><?=e($check['message'])?></p>
            <?php if (($check['key'] ?? '') === 'central_cron' && !empty($check['meta']['last_run_at'])): ?><small>آخرین اجرا: <?=e(function_exists('jalali_format') ? jalali_format((string)$check['meta']['last_run_at']) : (string)$check['meta']['last_run_at'])?></small><?php endif ?>
        </article>
    <?php endforeach ?>
</div>

<section class="card central-cron-card">
    <div class="central-cron-card__head">
        <div><h2>تنظیم تنها Cron موردنیاز</h2><p class="muted">در cPanel فقط همین یک Cron را هر ۱۰ دقیقه اجرا کن. بکاپ داخل همین اجراست و فقط در زمان تعیین‌شده ساخته می‌شود.</p></div>
        <span class="badge green">پیشنهاد: هر ۱۰ دقیقه</span>
    </div>
    <div class="central-cron-schedule"><code>*/10 * * * *</code><span>Every 10 minutes</span></div>
    <div class="field full"><label>دستور اصلی برای cPanel</label><div class="codebox" dir="ltr"><?=e($cliCommand)?></div><small>این دستور برای یوزر هاست و مسیر واقعی پروژه به‌صورت خودکار از محل نصب ساخته شده است.</small></div>
    <details class="central-cron-alternative"><summary>اگر مسیر PHP بالا کار نکرد</summary><div class="field"><label>مسیر PHP 8.3 در بسیاری از هاست‌های cPanel</label><div class="codebox" dir="ltr"><?=e($eaPhpCommand)?></div></div></details>
    <details class="central-cron-alternative"><summary>اجرای URL به‌جای PHP CLI</summary><div class="field"><label>نشانی امن Cron مرکزی</label><div class="codebox" dir="ltr"><?=e($centralUrl)?></div><small>اجرای CLI امن‌تر و پیشنهادی است. نشانی URL شامل کلید محرمانه است.</small></div></details>
    <div class="central-cron-actions">
        <form method="post" data-confirm="کلید Cron مرکزی تغییر کند؟ Cron مبتنی بر URL قبلی دیگر کار نخواهد کرد."><?=csrf_field()?><input type="hidden" name="action" value="rotate_key"><button class="btn btn-secondary">تغییر کلید Cron</button></form>
        <a class="btn btn-secondary" href="<?=e(app_url('admin/product-alerts.php'))?>">هشدار محصولات</a>
        <a class="btn btn-secondary" href="<?=e(app_url('admin/sms-campaigns.php'))?>">کمپین پیامکی</a>
        <a class="btn btn-secondary" href="<?=e(app_url('admin/backups.php'))?>">بکاپ‌ها</a>
    </div>
</section>

<section class="card">
    <h2>آخرین نتیجه وظایف مرکزی</h2>
    <div class="central-task-grid">
        <?php foreach (($lastResult['tasks'] ?? []) as $task): $taskStatus = (string)($task['status'] ?? 'warning'); ?>
            <article class="central-task central-task--<?=e($taskStatus)?>">
                <div><strong><?=e((string)($task['title'] ?? 'وظیفه'))?></strong><span><?=e($taskLabels[$taskStatus] ?? $taskStatus)?></span></div>
                <p><?=e((string)($task['message'] ?? ''))?></p>
                <?php if (!empty($task['data']) && is_array($task['data'])): ?><code dir="ltr"><?=e(json_encode($task['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?></code><?php endif ?>
            </article>
        <?php endforeach ?>
        <?php if (empty($lastResult['tasks'])): ?><div class="notice-box">هنوز Cron مرکزی اجرا نشده است. دکمه «اجرای کامل همین حالا» را بزن یا Cron را در cPanel ثبت کن.</div><?php endif ?>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php';
