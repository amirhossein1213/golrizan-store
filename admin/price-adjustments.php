<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$errors = [];
$preview = null;
$form = ['direction'=>'increase','percent'=>''];
$admin = admin_user();
$actor = [
    'source'=>'admin',
    'admin_id'=>(int)($admin['id'] ?? 0),
    'label'=>trim((string)($admin['full_name'] ?? $admin['username'] ?? 'مدیر پنل')),
];

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'preview', 'preview');
    try {
        if ($action === 'revert') {
            $batchId = max(0, scalar_int($_POST['id'] ?? 0));
            $batch = price_adjustment_revert($batchId, $actor);
            flash('success', 'آخرین تغییر گروهی بازگردانی شد و قیمت‌های قبلی دوباره فعال شدند.');
            redirect(app_url('admin/price-adjustments.php#batch-' . (int)$batch['id']));
        }

        if ($action === 'apply') {
            $confirmation = is_array($_SESSION['_bulk_price_confirmation'] ?? null) ? $_SESSION['_bulk_price_confirmation'] : [];
            $nonce = trim(scalar_string($_POST['nonce'] ?? ''));
            $createdAt = (int)($confirmation['created_at'] ?? 0);
            if (
                $nonce === '' || !hash_equals((string)($confirmation['nonce'] ?? ''), $nonce)
                || $createdAt < time() - 600
            ) throw new RuntimeException('پیش‌نمایش منقضی شده است؛ دوباره درصد را بررسی کنید.');
            $batch = price_adjustment_apply(
                (string)$confirmation['direction'],
                (int)$confirmation['basis_points'],
                (string)$confirmation['signature'],
                $actor
            );
            unset($_SESSION['_bulk_price_confirmation']);
            flash('success', 'قیمت‌ها با موفقیت تغییر کردند: ' . fa_number((int)$batch['product_count']) . ' محصول و ' . fa_number((int)$batch['variant_count']) . ' مدل.');
            redirect(app_url('admin/price-adjustments.php#batch-' . (int)$batch['id']));
        }

        if ($action !== 'preview') throw new RuntimeException('عملیات انتخاب‌شده معتبر نیست.');
        unset($_SESSION['_bulk_price_confirmation']);
        $form['direction'] = price_adjustment_normalize_direction(scalar_string($_POST['direction'] ?? 'increase'));
        $form['percent'] = trim(scalar_string($_POST['percent'] ?? ''));
        $parsed = price_adjustment_parse_percent($form['percent'], $form['direction']);
        $preview = price_adjustment_preview($form['direction'], (int)$parsed['basis_points']);
        if ((int)$preview['product_count'] < 1) throw new RuntimeException('با این درصد، هیچ قیمت واجد شرایطی تغییر نمی‌کند.');
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['_bulk_price_confirmation'] = [
            'nonce'=>$nonce,
            'direction'=>$form['direction'],
            'basis_points'=>(int)$parsed['basis_points'],
            'signature'=>(string)$preview['signature'],
            'created_at'=>time(),
        ];
    } catch (Throwable $error) {
        if (($action ?? '') === 'apply') unset($_SESSION['_bulk_price_confirmation']);
        log_error($error);
        $errors[] = $error instanceof PDOException
            ? 'عملیات پایگاه داده انجام نشد؛ یک‌بار بخش ارتقای سیستم را باز کنید و دوباره تلاش کنید.'
            : $error->getMessage();
    }
}

$latestApplied = price_adjustment_latest_applied_batch();
$history = price_adjustment_history(40);
$adminPage = 'price-adjustments';
$adminTitle = 'تغییر گروهی قیمت‌ها';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title">
    <div><h1>تغییر گروهی قیمت‌ها</h1><p class="muted">افزایش یا کاهش درصدی قیمت پایه همه محصولات، همراه با پیش‌نمایش، سابقه و بازگردانی امن.</p></div>
    <div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/products.php'))?>">فهرست محصولات</a></div>
</div>
<?php foreach ($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>

<div class="flash warning">
    <strong>این عملیات روی قیمت فروش است.</strong>
    قیمت خرید، موجودی و درصد اختصاصی ترب‌پی/اسنپ‌پی تغییر نمی‌کند. قیمت جشنواره مستقیم و «قیمت قبلی» هر کالا نیز با همان نسبت هماهنگ می‌شوند. محصول دارای تخفیف محدود فعال یا زمان‌بندی‌شده تا زمان لغو کمپین رد می‌شود.
</div>

<div class="two-col">
    <form class="card" method="post">
        <?=csrf_field()?><input type="hidden" name="action" value="preview">
        <h2>۱. پیش‌نمایش تغییر</h2>
        <div class="field"><label>نوع تغییر</label><select name="direction" required><?php foreach(price_adjustment_direction_labels() as $value=>$label):?><option value="<?=e($value)?>" <?=$form['direction']===$value?'selected':''?>><?=e($label)?> قیمت همه محصولات</option><?php endforeach?></select></div>
        <div class="field"><label>درصد</label><input name="percent" inputmode="decimal" maxlength="6" placeholder="مثلاً 10 یا 7.5" value="<?=e($form['percent'])?>" required><small>افزایش: ۰٫۰۱ تا ۱۰۰٪ — کاهش: ۰٫۰۱ تا ۹۹٫۹۹٪. نتیجه به نزدیک‌ترین تومان گرد می‌شود.</small></div>
        <button class="btn btn-primary" type="submit">محاسبه و نمایش پیش‌نمایش</button>
    </form>
    <div class="card">
        <h2>قواعد ایمنی</h2>
        <ul>
            <li>محصول ساده و همه مدل‌های قیمت‌دار محصول متغیر تغییر می‌کنند؛ فعال یا پیش‌نویس بودن مانع نیست.</li>
            <li>پیش از اجرا، قیمت‌ها و وضعیت کمپین‌ها دوباره کنترل می‌شوند.</li>
            <li>اگر پس از اجرا قیمتی دستی عوض شود، بازگردانی خودکار متوقف می‌شود تا تغییر جدید حذف نشود.</li>
            <li>فقط آخرین عملیات گروهیِ فعال قابل بازگردانی است.</li>
        </ul>
    </div>
</div>

<?php if ($preview):?>
<div class="card" id="price-preview">
    <div class="admin-title"><div><h2>۲. تأیید نهایی</h2><p class="muted"><?=e(price_adjustment_direction_labels()[$preview['direction']])?> <?=price_adjustment_percent_label((int)$preview['basis_points'])?> روی قیمت‌های فعلی</p></div></div>
    <div class="stats">
        <div class="stat-card"><span>محصول ساده</span><strong><?=fa_number((int)$preview['simple_count'])?></strong></div>
        <div class="stat-card"><span>محصول متغیر</span><strong><?=fa_number((int)$preview['variable_count'])?></strong></div>
        <div class="stat-card"><span>مدل قیمت‌دار</span><strong><?=fa_number((int)$preview['variant_count'])?></strong></div>
        <div class="stat-card"><span>ردشده با تخفیف محدود</span><strong><?=fa_number((int)$preview['locked_count'])?></strong></div>
        <div class="stat-card"><span>بدون قیمت مثبت</span><strong><?=fa_number((int)$preview['zero_price_count'])?></strong></div>
        <div class="stat-card"><span>بدون تغییر پس از گردکردن</span><strong><?=fa_number((int)$preview['unchanged_count'])?></strong></div>
    </div>
    <?php if ((int)$preview['locked_count'] > 0):?><div class="flash warning"><?=fa_number((int)$preview['locked_count'])?> محصول به‌علت تخفیف محدود فعال یا زمان‌بندی‌شده تغییر نمی‌کند. برای اعمال روی آن‌ها ابتدا کمپین را لغو کنید.</div><?php endif?>
    <?php if ($preview['samples']):?><div class="table-wrap"><table><thead><tr><th>نمونه محصول</th><th>نوع</th><th>قیمت فعلی</th><th>قیمت جدید</th></tr></thead><tbody><?php foreach($preview['samples'] as $sample):?><tr><td><strong><?=e($sample['title'])?></strong></td><td><?=e($sample['kind'])?></td><td><?=fa_number(format_price((int)$sample['before']))?> تومان</td><td><strong><?=fa_number(format_price((int)$sample['after']))?> تومان</strong></td></tr><?php endforeach?></tbody></table></div><?php endif?>
    <form method="post" data-confirm="این درصد روی همه قیمت‌های واجد شرایط اعمال شود؟">
        <?=csrf_field()?><input type="hidden" name="action" value="apply"><input type="hidden" name="nonce" value="<?=e((string)($_SESSION['_bulk_price_confirmation']['nonce'] ?? ''))?>">
        <button class="btn btn-danger" type="submit">تأیید و اعمال روی همه قیمت‌ها</button>
    </form>
</div>
<?php endif?>

<div class="admin-title"><div><h2>سابقه تغییرهای گروهی</h2><p class="muted">جزئیات قبل و بعد هر قیمت برای بازگردانی امن نگه‌داری می‌شود.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>عملیات</th><th>محصول / مدل</th><th>ردشده</th><th>اجراکننده</th><th>زمان</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($history as $batch):
    $isApplied = (string)$batch['status'] === 'applied';
    $isLatest = $latestApplied && (int)$latestApplied['id'] === (int)$batch['id'];
?>
<tr id="batch-<?=(int)$batch['id']?>">
    <td><strong><?=e(price_adjustment_direction_labels()[(string)$batch['direction']] ?? 'تغییر')?> <?=price_adjustment_percent_label((int)$batch['basis_points'])?></strong><small class="muted">شناسه <?=fa_number((int)$batch['id'])?></small></td>
    <td><?=fa_number((int)$batch['product_count'])?> محصول<br><small><?=fa_number((int)$batch['variant_count'])?> مدل</small></td>
    <td><?=fa_number((int)$batch['skipped_count'])?></td>
    <td><?=e(trim((string)$batch['actor_label']) ?: ((string)$batch['source']==='bale'?'ربات بله':'پنل مدیریت'))?></td>
    <td><small><?=e(jalali_format((string)$batch['created_at']))?></small><?php if(!empty($batch['reverted_at'])):?><small class="muted">بازگردانی: <?=e(jalali_format((string)$batch['reverted_at']))?></small><?php endif?></td>
    <td><span class="badge <?=$isApplied?'green':'red'?>"><?=$isApplied?'اعمال‌شده':'بازگردانی‌شده'?></span></td>
    <td><?php if($isApplied && $isLatest):?><form method="post" data-confirm="آخرین تغییر گروهی بازگردانی و همه قیمت‌های قبلی بازیابی شوند؟"><?=csrf_field()?><input type="hidden" name="action" value="revert"><input type="hidden" name="id" value="<?=(int)$batch['id']?>"><button class="btn btn-secondary btn-sm" type="submit">↩️ بازگردانی قیمت‌ها</button></form><?php elseif($isApplied):?><small class="muted">فقط آخرین عملیات قابل بازگردانی است.</small><?php else:?><small class="muted">انجام شد</small><?php endif?></td>
</tr>
<?php endforeach?>
<?php if(!$history):?><tr><td colspan="7">هنوز تغییر گروهی قیمتی ثبت نشده است.</td></tr><?php endif?>
</tbody></table></div>
<?php require __DIR__ . '/partials/footer.php';
