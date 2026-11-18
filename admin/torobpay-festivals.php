<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$errors = [];
$defaultStartsAt = date('Y-m-d H:i:s');
$defaultEndsAt = date('Y-m-d H:i:s', time() + limited_discount_max_duration_seconds());
$form = [
    'channel'=>'site',
    'product_id'=>0,
    'new_price'=>'',
    'starts_at'=>jalali_datetime_input($defaultStartsAt),
    'ends_at'=>jalali_datetime_input($defaultEndsAt),
    'popularity_reason'=>'قیمت رقابتی و تخفیف واقعی برای محصول پربازدید',
    'is_active'=>1,
];
$actor = ['admin_id'=>(int)(admin_user()['id'] ?? 0)];

if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'create', 'create');
    $festivalId = max(0, scalar_int($_POST['id'] ?? 0));
    try {
        if ($action === 'delete') {
            limited_discount_delete_campaign($festivalId, $actor);
            flash('success', 'رکورد تخفیف محدود حذف شد. اگر آن را در پنل بیرونی ثبت کرده‌اید، همان‌جا نیز حذف کنید.');
            redirect(app_url('admin/limited-discounts.php'));
        }
        if ($action === 'toggle') {
            $active = scalar_int($_POST['active'] ?? 0) === 1;
            limited_discount_set_active($festivalId, $active, $actor);
            flash('success', $active ? 'تخفیف محدود فعال شد.' : 'تخفیف محدود لغو شد و قیمت اصلی محصول فوراً برگشت.');
            redirect(app_url('admin/limited-discounts.php'));
        }
        if ($action !== 'create') throw new RuntimeException('عملیات انتخاب‌شده معتبر نیست.');

        $newPriceValid = false;
        $newPrice = bounded_uint($_POST['new_price'] ?? '', MAX_MONEY_AMOUNT, $newPriceValid);
        $form = [
            'channel'=>limited_discount_normalize_channel(scalar_string($_POST['channel'] ?? 'site')),
            'product_id'=>max(0, scalar_int($_POST['product_id'] ?? 0)),
            'new_price'=>$newPriceValid ? $newPrice : trim(scalar_string($_POST['new_price'] ?? '')),
            'starts_at'=>trim(scalar_string($_POST['starts_at'] ?? '')),
            'ends_at'=>trim(scalar_string($_POST['ends_at'] ?? '')),
            'popularity_reason'=>text_substr(trim(scalar_string($_POST['popularity_reason'] ?? '')), 0, 180),
            'is_active'=>isset($_POST['is_active']) ? 1 : 0,
        ];
        $startsAt = jalali_parse_datetime((string)$form['starts_at']);
        $endsAt = jalali_parse_datetime((string)$form['ends_at']);
        if (!$newPriceValid) $errors[] = 'قیمت بعد از تخفیف باید عدد صحیح و در محدوده مجاز باشد.';
        if ($startsAt === null || $endsAt === null) $errors[] = 'تاریخ شروع و پایان شمسی را کامل وارد کنید؛ نمونه: ۱۴۰۵/۰۶/۰۱، ۱۰:۰۰.';
        if (!$errors) {
            $campaign = limited_discount_create_campaign([
                'channel'=>$form['channel'],
                'product_id'=>$form['product_id'],
                'new_price'=>$newPrice,
                'starts_at'=>$startsAt,
                'ends_at'=>$endsAt,
                'popularity_reason'=>$form['popularity_reason'],
                'is_active'=>$form['is_active'],
            ], $actor);
            $externalNote = limited_discount_requires_torobpay($form['channel']) || limited_discount_requires_snappay($form['channel']) ? ' مشخصات نمایش‌داده‌شده را با پنل‌های انتخاب‌شده نیز هماهنگ کنید.' : '';
            flash('success', 'تخفیف محدود ساخته شد و در زمان تعیین‌شده روی سایت اعمال می‌شود.' . $externalNote);
            redirect(app_url('admin/limited-discounts.php#campaign-' . (int)$campaign['id']));
        }
    } catch (Throwable $error) {
        log_error($error);
        $errors[] = $error instanceof PDOException ? 'ذخیره در پایگاه داده انجام نشد؛ ارتقای نسخه ۲.۱۰.۴۵ را از بخش ارتقای سیستم بررسی کنید.' : $error->getMessage();
    }
}

$eligibilityStartsAt = jalali_parse_datetime((string)$form['starts_at']) ?? $defaultStartsAt;
$eligibilityEndsAt = jalali_parse_datetime((string)$form['ends_at']) ?? $defaultEndsAt;
$eligibleProducts = torobpay_special_eligible_products($eligibilityStartsAt, $eligibilityEndsAt, 1000, null, 'site');
$torobpayMarkupPercent = torobpay_special_payment_markup_percent();
$canCreateCampaign = (bool)$eligibleProducts;
$campaigns = db()->query(
    "SELECT f.*,p.title product_title,p.sku product_sku,p.price current_price,p.stock current_stock
     FROM festivals f LEFT JOIN products p ON p.id=f.target_id
     WHERE f.is_torobpay_special=1 ORDER BY f.is_active DESC,f.starts_at DESC,f.id DESC"
)->fetchAll();

$activeCount = 0;
$attentionCount = 0;
$now = time();
foreach ($campaigns as &$campaign) {
    $campaign['limited_discount_channel'] = limited_discount_normalize_channel((string)($campaign['limited_discount_channel'] ?? 'torobpay'));
    $start = strtotime((string)$campaign['starts_at']) ?: 0;
    $end = strtotime((string)$campaign['ends_at']) ?: 0;
    $campaign['_integrity'] = null;
    if (empty($campaign['is_active'])) {
        $campaign['_state'] = ['غیرفعال','red'];
    } elseif ($end < $now) {
        $campaign['_state'] = ['پایان‌یافته','red'];
    } else {
        $campaign['_integrity'] = torobpay_special_campaign_integrity($campaign);
        if ($campaign['_integrity'] !== null) {
            $campaign['_state'] = ['نیازمند هماهنگی','red'];
            $attentionCount++;
        } elseif ($start > $now) {
            $campaign['_state'] = ['در انتظار شروع','orange'];
        } else {
            $campaign['_state'] = ['فعال','green'];
            $activeCount++;
        }
    }
}
unset($campaign);

$adminPage = 'limited-discounts';
$adminTitle = 'تخفیف محدود';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title">
    <div><h1>تخفیف محدود</h1><p class="muted">تخفیف واقعی و زمان‌دارِ حداکثر سه‌روزه برای فروشگاه، ترب‌پی، اسنپ‌پی یا هر دو درگاه.</p></div>
    <div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/festivals.php'))?>">جشنواره‌های عمومی</a></div>
</div>
<?php foreach ($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>

<div class="stats torobpay-festival-stats">
    <div class="stat-card"><span>کل کمپین‌ها</span><strong><?=fa_number(count($campaigns))?></strong></div>
    <div class="stat-card"><span>فعال روی سایت</span><strong><?=fa_number($activeCount)?></strong></div>
    <div class="stat-card"><span>نیازمند هماهنگی</span><strong><?=fa_number($attentionCount)?></strong></div>
</div>

<div class="flash warning torobpay-festival-notice"><strong>قیمت اصلی محصول دست‌نخورده می‌ماند.</strong> با پایان زمان یا زدن «لغو و بازگشت قیمت»، تخفیف خاموش و قیمت اصلی همان لحظه دوباره نمایش داده می‌شود. ثبت کمپین در پنل ترب‌پی یا اسنپ‌پی خودکار نیست.</div>
<?php if($torobpayMarkupPercent>0.0001):?><div class="flash warning"><strong>فقط کانال ترب‌پی محدود است.</strong> درصد افزایش ترب‌پی روی <?=fa_number(rtrim(rtrim(number_format($torobpayMarkupPercent,2,'.',''),'0'),'.'))?>٪ است؛ برای انتخاب کانال ترب‌پی آن را در <a href="<?=e(app_url('admin/payment-settings.php'))?>">تنظیمات درگاه‌ها</a> صفر کنید. تخفیف فروشگاه یا اسنپ‌پی همچنان قابل ساخت است.</div><?php endif?>

<div class="two-col torobpay-festival-layout">
    <form class="card" method="post" data-torobpay-festival-form data-torobpay-markup="<?=e((string)$torobpayMarkupPercent)?>">
        <?=csrf_field()?><input type="hidden" name="action" value="create">
        <h2>ساخت تخفیف محدود</h2>
        <div class="field"><label>کانال هماهنگی</label><select name="channel" data-limited-channel><?php foreach(limited_discount_channels() as $channelValue=>$channelLabel):?><option value="<?=e($channelValue)?>" <?=$form['channel']===$channelValue?'selected':''?>><?=e($channelLabel)?></option><?php endforeach?></select><small>در همه حالت‌ها تخفیف روی سایت اعمال می‌شود؛ کانال انتخابی کنترل‌های همان درگاه را اضافه می‌کند.</small></div>
        <div class="field"><label>محصول خارج از جشنواره</label><select name="product_id" data-torobpay-product required><option value="">انتخاب محصول واجد شرایط</option><?php foreach ($eligibleProducts as $product):?><option value="<?=$product['id']?>" data-price="<?=$product['price']?>" data-stock="<?=$product['stock']?>" data-torob="<?=empty($product['torob_enabled'])?'0':'1'?>" data-snappay="<?=empty($product['snappay_enabled'])?'0':'1'?>" <?=((int)$form['product_id']===(int)$product['id'])?'selected':''?>><?=e($product['title'].' — '.$product['sku'])?></option><?php endforeach?></select><small>محصول باید ساده، فعال، موجود و بدون تخفیف هم‌زمان باشد. انتخاب ترب‌پی/اسنپ‌پی فقط محصولات مجاز همان درگاه را نگه می‌دارد.</small></div>
        <?php if (!$eligibleProducts):?><div class="flash warning">برای این بازه محصول واجد شرایطی وجود ندارد؛ تاریخ را تغییر دهید یا جشنواره قبلی محصول را پایان دهید.</div><?php endif?>
        <div class="form-grid">
            <div class="field"><label>قیمت قبل</label><input type="text" data-torobpay-old-price readonly value="پس از انتخاب محصول"><small>قیمت اصلی فعلی سایت؛ قابل ویرایش نیست.</small></div>
            <div class="field"><label>قیمت بعد از تخفیف (تومان)</label><input type="number" name="new_price" min="1" max="100000000000000" inputmode="numeric" value="<?=e($form['new_price'])?>" data-torobpay-new-price required></div>
            <div class="field"><label>موجودی قابل اعلام</label><input type="text" data-torobpay-stock readonly value="—"><small>موجودی فعلی سایت هنگام ثبت کمپین ذخیره می‌شود.</small></div>
            <div class="field"><label>درصد تخفیف</label><input type="text" data-torobpay-percent readonly value="—"></div>
            <div class="field"><label>شروع</label><input type="text" name="starts_at" value="<?=e($form['starts_at'])?>" data-jalali-datetime placeholder="۱۴۰۵/۰۶/۰۱، ۱۰:۰۰" inputmode="numeric" autocomplete="off" required></div>
            <div class="field"><label>پایان؛ حداکثر ۳ روز</label><input type="text" name="ends_at" value="<?=e($form['ends_at'])?>" data-jalali-datetime placeholder="۱۴۰۵/۰۶/۰۴، ۱۰:۰۰" inputmode="numeric" autocomplete="off" required></div>
        </div>
        <div class="field"><label>دلیل جذابیت تخفیف</label><textarea name="popularity_reason" maxlength="180" rows="3" required><?=e($form['popularity_reason'])?></textarea><small>واقعی و قابل دفاع بنویسید؛ مثلاً «قیمت رقابتی، موجودی محدود و تخفیف واقعی محصول پربازدید».</small></div>
        <div class="checkboxes"><label><input type="checkbox" name="is_active" value="1" <?=$form['is_active']?'checked':''?>> هم‌زمان با رسیدن زمان شروع، روی سایت فعال باشد</label></div>
        <button class="btn btn-primary" data-limited-submit <?=$canCreateCampaign?'':'disabled'?>>ساخت تخفیف محدود</button>
    </form>
    <div class="card torobpay-festival-rules">
        <h2>کنترل‌های خودکار</h2>
        <ul>
            <li>محصولی که جشنواره مستقیم یا جشنواره زمان‌دار هم‌پوشان دارد قابل انتخاب نیست.</li>
            <li>زمان پایان بیشتر از ۷۲ ساعت پس از شروع پذیرفته نمی‌شود.</li>
            <li>قیمت بعد باید از قیمت اصلی فعلی کمتر باشد.</li>
            <li>قیمت تخفیفی در صفحه محصول، ردیف صفحه اصلی، سبد و تسویه یکسان اعمال می‌شود.</li>
            <li>برای کانال ترب‌پی یا اسنپ‌پی، مجازبودن محصول در همان درگاه کنترل می‌شود.</li>
            <li>اگر قیمت اصلی بعداً تغییر کند، تخفیف ناهماهنگ نمایش داده نمی‌شود و کمپین هشدار می‌گیرد.</li>
        </ul>
        <p class="muted">نسخه اول برای محصولات ساده فعال است؛ محصولات متغیر باید برای هر مدل قیمت و موجودی جداگانه داشته باشند و عمداً در این فهرست نشان داده نمی‌شوند.</p>
    </div>
</div>

<div class="admin-title torobpay-festival-list-title"><div><h2>تخفیف‌های محدود ثبت‌شده</h2><p class="muted">برای کانال‌های بیرونی، مشخصات همین ردیف را بدون تغییر در پنل مربوطه وارد کنید.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>محصول</th><th>کانال</th><th>بازه</th><th>قیمت قبل / بعد</th><th>تخفیف</th><th>موجودی</th><th>دلیل</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach ($campaigns as $campaign):
    [$stateLabel,$stateColor] = $campaign['_state'];
    $oldPrice = (int)$campaign['torobpay_old_price'];
    $newPrice = (int)$campaign['torobpay_new_price'];
    $percent = limited_discount_percent($oldPrice, $newPrice);
    $percentLabel = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    $copyText = implode("\n", [
        'محصول: ' . (string)($campaign['product_title'] ?: 'محصول حذف‌شده'),
        'کد کالا: ' . (string)($campaign['product_sku'] ?? ''),
        'کانال: ' . limited_discount_channel_label((string)$campaign['limited_discount_channel']),
        'شروع: ' . jalali_format((string)$campaign['starts_at']),
        'پایان: ' . jalali_format((string)$campaign['ends_at']),
        'قیمت قبل: ' . format_price($oldPrice) . ' تومان',
        'قیمت بعد: ' . format_price($newPrice) . ' تومان',
        'درصد تخفیف: ' . $percentLabel . '٪',
        'موجودی: ' . (int)$campaign['current_stock'],
        'فعال: ' . (!empty($campaign['is_active']) ? 'بله' : 'خیر'),
        'دلیل: ' . (string)$campaign['torobpay_popularity_reason'],
    ]);
?>
<tr id="campaign-<?=(int)$campaign['id']?>">
    <td><strong><?=e($campaign['product_title'] ?: 'محصول حذف‌شده')?></strong><small class="muted"><?=e((string)$campaign['product_sku'])?></small></td>
    <td><span class="badge blue"><?=e(limited_discount_channel_label((string)$campaign['limited_discount_channel']))?></span></td>
    <td><small><?=e(jalali_format($campaign['starts_at']))?><br>تا <?=e(jalali_format($campaign['ends_at']))?></small></td>
    <td><small><s><?=fa_number(format_price($oldPrice))?></s><br><strong><?=fa_number(format_price($newPrice))?> تومان</strong></small></td>
    <td><?=fa_number($percentLabel)?>٪</td>
    <td><?=fa_number((int)$campaign['current_stock'])?><?php if ((int)$campaign['current_stock'] !== (int)$campaign['torobpay_stock_snapshot']):?><small class="muted">هنگام ثبت: <?=fa_number((int)$campaign['torobpay_stock_snapshot'])?></small><?php endif?></td>
    <td><small><?=e((string)$campaign['torobpay_popularity_reason'])?></small></td>
    <td><span class="badge <?=$stateColor?>"><?=$stateLabel?></span><?php if ($campaign['_integrity'] !== null):?><small class="torobpay-festival-error"><?=e($campaign['_integrity'])?></small><?php endif?></td>
    <td><button class="btn btn-secondary btn-sm" type="button" data-copy-torobpay="<?=e($copyText)?>">کپی مشخصات</button><form class="inline-form" method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$campaign['id']?>"><input type="hidden" name="active" value="<?=$campaign['is_active']?'0':'1'?>"><button class="btn btn-secondary btn-sm"><?=$campaign['is_active']?'لغو و بازگشت قیمت':'فعال‌سازی'?></button></form><form class="inline-form" method="post" data-confirm="رکورد این تخفیف محدود کاملاً حذف شود؟"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$campaign['id']?>"><button class="btn btn-danger btn-sm">حذف رکورد</button></form></td>
</tr>
<?php endforeach?>
<?php if (!$campaigns):?><tr><td colspan="9">هنوز تخفیف محدودی ثبت نشده است.</td></tr><?php endif?>
</tbody></table></div>

<script nonce="<?=e(CSP_NONCE)?>">
(()=>{
    const form=document.querySelector('[data-torobpay-festival-form]'),channel=document.querySelector('[data-limited-channel]'),product=document.querySelector('[data-torobpay-product]'),newPrice=document.querySelector('[data-torobpay-new-price]'),oldOutput=document.querySelector('[data-torobpay-old-price]'),stockOutput=document.querySelector('[data-torobpay-stock]'),percentOutput=document.querySelector('[data-torobpay-percent]'),submit=document.querySelector('[data-limited-submit]');
    const number=value=>new Intl.NumberFormat('fa-IR').format(value);
    const sync=()=>{const option=product?.selectedOptions?.[0],old=Number(option?.dataset?.price||0),stock=Number(option?.dataset?.stock||0),next=Number(newPrice?.value||0);if(oldOutput)oldOutput.value=old>0?number(old)+' تومان':'پس از انتخاب محصول';if(stockOutput)stockOutput.value=old>0?number(stock):'—';if(percentOutput)percentOutput.value=old>0&&next>0&&next<old?number(Math.round(((old-next)*100/old)*100)/100)+'٪':'—';};
    const filterProducts=()=>{const value=channel?.value||'site',needsTorob=value==='torobpay'||value==='all',needsSnappay=value==='snappay'||value==='all';product?.querySelectorAll('option[data-price]').forEach(option=>{const allowed=(!needsTorob||option.dataset.torob==='1')&&(!needsSnappay||option.dataset.snappay==='1');option.hidden=!allowed;option.disabled=!allowed;});if(product?.selectedOptions?.[0]?.disabled)product.value='';const markup=Number(form?.dataset?.torobpayMarkup||0);if(submit)submit.disabled=!<?=json_encode($canCreateCampaign)?>||(needsTorob&&markup>0);sync();};
    channel?.addEventListener('change',filterProducts);product?.addEventListener('change',sync);newPrice?.addEventListener('input',sync);filterProducts();
    document.querySelectorAll('[data-copy-torobpay]').forEach(button=>button.addEventListener('click',async()=>{const text=button.dataset.copyTorobpay||'';try{await navigator.clipboard.writeText(text);button.textContent='کپی شد ✓';setTimeout(()=>button.textContent='کپی مشخصات',1800);}catch(error){const area=document.createElement('textarea');area.value=text;area.setAttribute('readonly','');area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();button.textContent='کپی شد ✓';setTimeout(()=>button.textContent='کپی مشخصات',1800);}}));
})();
</script>
<?php require __DIR__ . '/partials/footer.php';
