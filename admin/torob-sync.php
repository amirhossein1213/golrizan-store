<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$diagnosticError = '';
try {
    $diagnostics = torob_api_v3_diagnostics();
} catch (Throwable $exception) {
    log_error($exception);
    $diagnosticError = 'بررسی خروجی ترب کامل نشد. ابتدا ارتقای دیتابیس را اجرا کنید و گزارش خطای PHP را ببینید.';
    $diagnostics = [
        'ready'=>false,
        'endpoint'=>torob_api_v3_endpoint_url(),
        'sodium'=>torob_api_v3_is_supported(),
        'https'=>str_starts_with((string)setting('site_url', ''), 'https://'),
        'total'=>0,
        'simple'=>0,
        'variants'=>0,
        'draft_products'=>0,
        'torob_disabled'=>0,
        'zero_price'=>0,
        'inactive_taxonomy'=>0,
        'variable_without_rows'=>0,
        'warnings'=>[],
        'latest'=>[],
    ];
}

$preview = [
    'api_version'=>TOROB_API_V3_NAME,
    'current_page'=>1,
    'total'=>(int)$diagnostics['total'],
    'max_pages'=>max(1, (int)ceil((int)$diagnostics['total'] / TOROB_API_V3_PAGE_SIZE)),
    'products'=>$diagnostics['latest'],
];
$previewJson = json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
$critical = empty($diagnostics['sodium']) || empty($diagnostics['https']) || $diagnosticError !== '';
$adminPage = 'torob-sync';
$adminTitle = 'همگام‌سازی محصولات ترب';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title">
    <div>
        <h1>همگام‌سازی محصولات ترب</h1>
        <p class="muted">خروجی رسمی Product API v3؛ جدا از درگاه پرداخت ترب‌پی و بدون استفاده از رمز یا کلید پذیرندگی.</p>
    </div>
    <div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/products.php'))?>">مدیریت محصولات</a><a class="btn btn-secondary" href="<?=e(app_url('admin/system-health.php'))?>">سلامت سایت</a></div>
</div>

<?php if ($diagnosticError !== ''): ?><div class="flash error"><?=e($diagnosticError)?></div>
<?php elseif ($critical): ?><div class="flash error">خروجی ساخته شده اما پیش‌نیاز امنیتی یا HTTPS کامل نیست؛ موارد زیر را اصلاح کنید.</div>
<?php elseif (!empty($diagnostics['warnings'])): ?><div class="flash error">API آماده پاسخ است؛ هشدارهای کیفیت محصول را نیز برطرف کنید.</div>
<?php else: ?><div class="flash success">API نسخه ۳ ترب آماده است و محصولات جدید از ابتدا در خروجی قرار می‌گیرند.</div><?php endif ?>

<section class="card">
    <h2>آدرس قابل ثبت در پنل فروشندگان ترب</h2>
    <div class="field full"><label>Product API v3 Endpoint</label><div class="codebox" dir="ltr"><?=e((string)$diagnostics['endpoint'])?></div></div>
    <p>در پنل ترب، نوع منبع را <strong>Product API v3</strong> و همین نشانی را ثبت کنید. درخواست‌های واقعی با روش POST و JWT امضاشده از سمت ترب ارسال می‌شوند.</p>
    <div class="notice-box"><strong>توجه:</strong> کد پذیرنده، Client ID، Client Secret، نام کاربری و رمز ترب‌پی مربوط به پرداخت CPG هستند و در این صفحه وارد نمی‌شوند.</div>
    <p class="muted">برای نمایش بهتر در ترب، تصویر اصلی مربع و حداقل ۹۰۰×۹۰۰ پیکسل پیشنهاد می‌شود؛ تصویر کوچک‌تر از ۶۰۰×۶۰۰ ممکن است نمایش داده نشود.</p>
</section>

<div class="stats">
    <div class="card stat"><strong><?=fa_number((int)$diagnostics['total'])?></strong><span>کل ردیف‌های قابل ارسال</span></div>
    <div class="card stat"><strong><?=fa_number((int)$diagnostics['simple'])?></strong><span>محصول ساده</span></div>
    <div class="card stat"><strong><?=fa_number((int)$diagnostics['variants'])?></strong><span>مدل و تنوع محصول</span></div>
    <div class="card stat"><strong><?=!empty($diagnostics['sodium'])?'فعال':'غیرفعال'?></strong><span>اعتبارسنجی امن JWT</span></div>
</div>

<?php if (!empty($diagnostics['warnings'])): ?>
<section class="card"><h2>موارد نیازمند بررسی</h2><ul><?php foreach ((array)$diagnostics['warnings'] as $warning): ?><li><?=e((string)$warning)?></li><?php endforeach ?></ul></section>
<?php endif ?>

<section class="card">
    <h2>چرا یک محصول وارد خروجی نمی‌شود؟</h2>
    <div class="stats">
        <div class="stat"><strong><?=fa_number((int)$diagnostics['draft_products'])?></strong><span>پیش‌نویس</span></div>
        <div class="stat"><strong><?=fa_number((int)$diagnostics['torob_disabled'])?></strong><span>نمایش در ترب خاموش</span></div>
        <div class="stat"><strong><?=fa_number((int)$diagnostics['zero_price'])?></strong><span>بدون قیمت معتبر</span></div>
        <div class="stat"><strong><?=fa_number((int)$diagnostics['inactive_taxonomy'])?></strong><span>برند یا دسته غیرفعال</span></div>
        <div class="stat"><strong><?=fa_number((int)$diagnostics['variable_without_rows'])?></strong><span>محصول متغیر بدون مدل مجاز</span></div>
    </div>
    <p class="muted">برای محصول متغیر، هم گزینه «نمایش در ترب» محصول اصلی و هم همان گزینه در مدل/تنوع باید روشن باشد. محصول ناموجود ارسال می‌شود اما با قیمت صفر و availability=false تا وضعیت آن در ترب اصلاح شود.</p>
</section>

<section class="card">
    <h2>جدیدترین ردیف‌های خروجی</h2>
    <div class="table-wrap"><table><thead><tr><th>شناسه پایدار</th><th>محصول</th><th>قیمت فعلی</th><th>موجودی</th><th>آخرین تغییر</th><th>صفحه</th></tr></thead><tbody>
    <?php foreach ((array)$diagnostics['latest'] as $item): ?>
        <tr>
            <td dir="ltr"><code><?=e((string)$item['page_unique'])?></code></td>
            <td><strong><?=e((string)$item['title'])?></strong></td>
            <td><?=fa_number(format_price((int)$item['current_price']))?> تومان<?php if (isset($item['old_price'])): ?><br><small>قبل: <?=fa_number(format_price((int)$item['old_price']))?></small><?php endif ?></td>
            <td><?=!empty($item['availability'])?'موجود':'ناموجود'?></td>
            <td dir="ltr"><?=e((string)$item['date_updated'])?></td>
            <td><a class="btn btn-secondary btn-sm" href="<?=e((string)$item['page_url'])?>" target="_blank" rel="noopener noreferrer">مشاهده</a></td>
        </tr>
    <?php endforeach ?>
    <?php if (empty($diagnostics['latest'])): ?><tr><td colspan="6">محصول واجد شرایطی در خروجی نیست.</td></tr><?php endif ?>
    </tbody></table></div>
</section>

<section class="card">
    <details><summary><strong>نمونه JSON تولیدشده برای بررسی مدیر</strong></summary><pre class="codebox" dir="ltr" style="white-space:pre-wrap;max-height:520px;overflow:auto;text-align:left"><?=e($previewJson)?></pre></details>
    <p class="muted">این پیش‌نمایش داخل پنل و بدون JWT ساخته می‌شود. خود endpoint عمومی فقط درخواست امضاشده ترب را می‌پذیرد.</p>
</section>
<?php require __DIR__ . '/partials/footer.php';
