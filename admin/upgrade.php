<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$results = [];
$errors = [];
$repairNotice = $_SESSION['_migration_error'] ?? '';
$repairRef = $_SESSION['_migration_error_ref'] ?? '';
unset($_SESSION['_migration_error'], $_SESSION['_migration_error_ref']);

$pdo = db();
$beforeVersion = mousavi_schema_version($pdo);
$missingBefore = mousavi_schema_missing($pdo);

$forceRepair = scalar_string($_GET['repair'] ?? '') === '1';

if (is_post()) {
    verify_csrf();
    try {
        $results = $forceRepair ? run_mousavi_migrations($pdo) : ensure_mousavi_schema($pdo);
        // اگر ensure در همین درخواست قبلاً اجرا نشده باشد، مهاجرت می‌کند؛ برای اطمینان ساختار دوباره بررسی می‌شود.
        if (!mousavi_schema_is_current($pdo)) {
            $results = array_merge($results, run_mousavi_migrations($pdo));
        }
        flash('success', 'ارتقای دیتابیس با موفقیت انجام شد. ورود کاربران، مدیریت محصولات، ارسال و بکاپ امن به‌روز هستند.');
        redirect(app_url('admin/index.php'));
    } catch (Throwable $exception) {
        log_error($exception);
        $errors[] = 'ارتقا انجام نشد. ابتدا مطمئن شوید کاربر MySQL دارای مجوزهای CREATE، ALTER، INDEX و INSERT است.';
        $errors[] = 'جزئیات فنی در فایل storage/logs/php-error.log ثبت شد.';
        $repairRef = date('Ymd-His');
    }
}

$currentVersion = mousavi_schema_version($pdo);
$missing = mousavi_schema_missing($pdo);
$adminPage = 'upgrade';
$adminTitle = 'تعمیر و ارتقای دیتابیس';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><h1>تعمیر و ارتقای پایگاه داده</h1></div>

<?php if ($repairNotice !== ''): ?>
    <div class="flash error">
        <?=e($repairNotice)?>
        <?php if ($repairRef !== ''): ?><br><small>شناسه گزارش: <?=e($repairRef)?></small><?php endif ?>
    </div>
<?php endif ?>
<?php foreach ($errors as $error): ?><div class="flash error"><?=e($error)?></div><?php endforeach ?>
<?php foreach ($results as $result): ?><div class="flash success"><?=e($result)?></div><?php endforeach ?>

<div class="card">
    <p>نسخه برنامه: <strong><?=e(app_version())?></strong></p>
    <p>نسخه ساختار موردنیاز دیتابیس: <strong><?=e(MOUSAVI_SCHEMA_VERSION)?></strong></p>
    <p>نسخه فعلی دیتابیس: <strong><?=e($currentVersion)?></strong></p>
    <?php if (!$forceRepair && $missing === [] && version_compare($currentVersion, MOUSAVI_SCHEMA_VERSION, '>=')): ?>
        <div class="flash success">ساختار دیتابیس کامل و به‌روز است.</div>
        <p><a class="btn btn-primary" href="<?=e(app_url('admin/index.php'))?>">ورود به داشبورد</a></p>
    <?php else: ?>
        <div class="flash error"><?= $forceRepair ? 'حالت تعمیر اجباری فعال است؛ ساختار محصولات و جشنواره‌ها دوباره بررسی و ترمیم می‌شود.' : 'ساختار دیتابیس ناقص یا قدیمی است و باید تعمیر شود.' ?></div>
        <?php if ($missing !== []): ?>
            <details><summary>اجزای ایجادنشده</summary><pre><?=e(implode("\n", $missing))?></pre></details>
        <?php endif ?>
        <p>پیش از اجرا از دیتابیس نسخه پشتیبان تهیه کنید. این عملیات اطلاعات سفارش‌ها و محصولات قبلی را حذف نمی‌کند.</p>
        <form method="post">
            <?=csrf_field()?>
            <button class="btn btn-primary" type="submit">تعمیر و ارتقای خودکار دیتابیس</button>
        </form>
    <?php endif ?>
</div>
<div class="card">
    <h2>در صورت شکست ارتقا</h2>
    <ol>
        <li>در cPanel بخش MySQL Databases، کاربر دیتابیس را دوباره با گزینه ALL PRIVILEGES به دیتابیس متصل کنید.</li>
        <li>پوشه‌های <code>storage</code>، <code>storage/logs</code> و <code>storage/backups</code> باید قابل نوشتن باشند.</li>
        <li>فایل <code>storage/logs/php-error.log</code> را برای متن خطای MySQL بررسی کنید.</li>
    </ol>
</div>
<?php require __DIR__ . '/partials/footer.php';
