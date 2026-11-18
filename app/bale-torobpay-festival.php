<?php
declare(strict_types=1);

/**
 * نام فایل و پیشوند callback برای سازگاری با دکمه‌های نسخه‌های قبلی حفظ شده‌اند؛
 * قابلیت از این نسخه در رابط کاربری «تخفیف محدود» و مستقل از درگاه است.
 */

function bale_torobpay_festival_channel(array $data): string
{
    return limited_discount_normalize_channel((string)($data['channel'] ?? 'site'));
}

function bale_torobpay_festival_channel_note(string $channel): string
{
    $notes = ['قیمت تخفیفی روی سایت، سبد خرید و تسویه یکسان اعمال می‌شود.'];
    if (limited_discount_requires_torobpay($channel)) $notes[] = 'مشخصات قیمت قبل و بعد را عیناً در پنل ترب‌پی نیز ثبت کنید.';
    if (limited_discount_requires_snappay($channel)) $notes[] = 'اگر کمپین جداگانه‌ای در پنل اسنپ‌پی دارید، همان قیمت و بازه را در آن پنل هماهنگ کنید.';
    return implode("\n", $notes);
}

function bale_torobpay_festival_menu(string $chatId, string $userId, int $messageId = 0): void
{
    bale_product_bot_session_delete($chatId);
    $markup = bale_product_bot_inline_keyboard([
        [[ 'text'=>'➕ ساخت تخفیف محدود','callback_data'=>'tpf:create' ]],
        [[ 'text'=>'📋 مشاهده و لغو تخفیف‌ها','callback_data'=>'tpf:manage:1' ]],
        [[ 'text'=>'❌ بستن','callback_data'=>bale_product_bot_cancel_callback() ]],
    ]);
    $text = "⏳ تخفیف محدود\n\nتخفیف واقعی را برای حداکثر سه روز فعال کنید. محصولات فعال در ردیف ویژه صفحه اصلی نمایش داده می‌شوند و با لغو کمپین، قیمت اصلی همان لحظه برمی‌گردد.";
    if ($messageId > 0) bale_product_bot_edit($chatId, $messageId, $text, $markup);
    else bale_product_bot_send($chatId, $text, $markup);
}

function bale_torobpay_festival_channel_menu(string $chatId, int $messageId = 0): void
{
    $markup = bale_product_bot_inline_keyboard([
        [[ 'text'=>'🌐 فقط فروشگاه','callback_data'=>'tpf:channel:site' ]],
        [[ 'text'=>'🟣 فروشگاه + ترب‌پی','callback_data'=>'tpf:channel:torobpay' ]],
        [[ 'text'=>'🟢 فروشگاه + اسنپ‌پی','callback_data'=>'tpf:channel:snappay' ]],
        [[ 'text'=>'🔵 فروشگاه + هر دو درگاه','callback_data'=>'tpf:channel:all' ]],
        [[ 'text'=>'↩️ بازگشت','callback_data'=>'tpf:menu' ]],
    ]);
    $text = "این تخفیف قرار است با کدام کانال هماهنگ باشد؟\n\nدر همه حالت‌ها قیمت روی خود فروشگاه اعمال می‌شود. انتخاب درگاه فقط کنترل‌های لازم برای همان درگاه را هم فعال می‌کند.";
    if ($messageId > 0) bale_product_bot_edit($chatId, $messageId, $text, $markup);
    else bale_product_bot_send($chatId, $text, $markup);
}

function bale_torobpay_festival_products(int $page = 1, string $channel = 'site'): array
{
    $perPage = 8;
    $startsAt = date('Y-m-d H:i:s');
    $endsAt = date('Y-m-d H:i:s', time() + limited_discount_max_duration_seconds());
    $channel = limited_discount_normalize_channel($channel);
    $products = torobpay_special_eligible_products($startsAt, $endsAt, 2000, null, $channel);
    $total = count($products);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return [
        'rows'=>array_slice($products, ($page - 1) * $perPage, $perPage),
        'page'=>$page,
        'pages'=>$pages,
        'total'=>$total,
        'channel'=>$channel,
    ];
}

function bale_torobpay_festival_list_markup(array $list): array
{
    $buttons = [];
    foreach ($list['rows'] as $product) {
        $label = '⏳ ' . text_substr((string)$product['title'], 0, 31) . ' | ' . fa_number(format_price((int)$product['price']));
        $buttons[] = [[ 'text'=>$label, 'callback_data'=>'tpf:pick:' . (int)$product['id'] ]];
    }
    $nav = [];
    if ((int)$list['page'] > 1) $nav[] = ['text'=>'⬅️ قبلی','callback_data'=>'tpf:page:' . ((int)$list['page'] - 1)];
    $nav[] = ['text'=>fa_number((int)$list['page']) . ' / ' . fa_number((int)$list['pages']),'callback_data'=>'page:noop:0'];
    if ((int)$list['page'] < (int)$list['pages']) $nav[] = ['text'=>'بعدی ➡️','callback_data'=>'tpf:page:' . ((int)$list['page'] + 1)];
    $buttons[] = $nav;
    $buttons[] = [[ 'text'=>'↩️ انتخاب کانال','callback_data'=>'tpf:create' ]];
    $buttons[] = [[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]];
    return bale_product_bot_inline_keyboard($buttons);
}

function bale_torobpay_festival_show_products(string $chatId, string $userId, int $page = 1, int $messageId = 0, string $channel = 'site'): void
{
    $list = bale_torobpay_festival_products($page, $channel);
    if (!$list['rows']) {
        bale_product_bot_session_delete($chatId);
        $text = "فعلاً محصول واجد شرایطی برای تخفیف محدود در کانال «" . limited_discount_channel_label($channel) . "» وجود ندارد.\n\nمحصول باید ساده، فعال، موجود و بدون جشنواره هم‌زمان در سه روز آینده باشد.";
        if (limited_discount_requires_torobpay($channel)) $text .= "\nبرای ترب‌پی، خروجی ترب محصول و درصد افزایش صفر نیز لازم است.";
        if (limited_discount_requires_snappay($channel)) $text .= "\nبرای اسنپ‌پی، مجازبودن محصول برای اسنپ‌پی نیز باید فعال باشد.";
        $markup = bale_product_bot_inline_keyboard([
            [[ 'text'=>'↩️ انتخاب کانال دیگر','callback_data'=>'tpf:create' ]],
            [[ 'text'=>'❌ بستن','callback_data'=>bale_product_bot_cancel_callback() ]],
        ]);
        if ($messageId > 0) bale_product_bot_edit($chatId, $messageId, $text, $markup);
        else bale_product_bot_send($chatId, $text, $markup);
        return;
    }
    bale_product_bot_session_save($chatId, $userId, 'torobpay_festival_select', ['page'=>(int)$list['page'],'channel'=>$list['channel']]);
    $text = "⏳ ساخت تخفیف محدود\nکانال: " . limited_discount_channel_label($list['channel']) . "\n\nمحصولی را انتخاب کنید. فهرست فقط کالاهای بدون جشنواره هم‌زمان را نشان می‌دهد.\n\nصفحه " . fa_number((int)$list['page']) . ' از ' . fa_number((int)$list['pages']) . ' — ' . fa_number((int)$list['total']) . ' محصول';
    $markup = bale_torobpay_festival_list_markup($list);
    if ($messageId > 0) bale_product_bot_edit($chatId, $messageId, $text, $markup);
    else bale_product_bot_send($chatId, $text, $markup);
}

function bale_torobpay_festival_start(string $chatId, string $userId): void
{
    bale_torobpay_festival_menu($chatId, $userId);
}

function bale_torobpay_festival_confirmation_text(array $data): string
{
    $oldPrice = max(0, (int)($data['old_price'] ?? 0));
    $newPrice = max(0, (int)($data['new_price'] ?? 0));
    $percent = limited_discount_percent($oldPrice, $newPrice);
    $percentLabel = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    $channel = bale_torobpay_festival_channel($data);
    return implode("\n", [
        '⏳ تأیید تخفیف محدود',
        '',
        'محصول: ' . (string)($data['product_title'] ?? ''),
        'کد کالا: ' . (string)($data['product_sku'] ?? ''),
        'کانال: ' . limited_discount_channel_label($channel),
        'قیمت قبل: ' . fa_number(format_price($oldPrice)) . ' تومان',
        'قیمت بعد: ' . fa_number(format_price($newPrice)) . ' تومان',
        'تخفیف: ' . fa_number($percentLabel) . '٪',
        'موجودی: ' . fa_number((int)($data['stock'] ?? 0)),
        'شروع: ' . jalali_format((string)($data['starts_at'] ?? '')),
        'پایان: ' . jalali_format((string)($data['ends_at'] ?? '')),
        'دلیل: ' . (string)($data['popularity_reason'] ?? ''),
        '',
        bale_torobpay_festival_channel_note($channel),
    ]);
}

function bale_torobpay_festival_confirm(string $chatId, string $userId, array $data, int $messageId = 0): void
{
    bale_product_bot_session_save($chatId, $userId, 'torobpay_festival_confirm', $data);
    $markup = bale_product_bot_inline_keyboard([
        [[ 'text'=>'✅ ساخت و فعال‌سازی','callback_data'=>'tpf:save' ]],
        [[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]],
    ]);
    $text = bale_torobpay_festival_confirmation_text($data);
    if ($messageId > 0) bale_product_bot_edit($chatId, $messageId, $text, $markup);
    else bale_product_bot_send($chatId, $text, $markup);
}

function bale_torobpay_festival_result_text(array $campaign): string
{
    $oldPrice = max(0, (int)($campaign['torobpay_old_price'] ?? 0));
    $newPrice = max(0, (int)($campaign['torobpay_new_price'] ?? 0));
    $percent = limited_discount_percent($oldPrice, $newPrice);
    $percentLabel = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    $channel = limited_discount_normalize_channel((string)($campaign['limited_discount_channel'] ?? 'site'));
    return implode("\n", [
        '✅ تخفیف محدود روی سایت ساخته شد',
        '',
        'محصول: ' . (string)($campaign['product_title'] ?? ''),
        'کد کالا: ' . (string)($campaign['product_sku'] ?? ''),
        'کانال: ' . limited_discount_channel_label($channel),
        'شروع: ' . jalali_format((string)($campaign['starts_at'] ?? '')),
        'پایان: ' . jalali_format((string)($campaign['ends_at'] ?? '')),
        'قیمت قبل: ' . fa_number(format_price($oldPrice)) . ' تومان',
        'قیمت بعد: ' . fa_number(format_price($newPrice)) . ' تومان',
        'درصد تخفیف: ' . fa_number($percentLabel) . '٪',
        'موجودی: ' . fa_number((int)($campaign['current_stock'] ?? $campaign['torobpay_stock_snapshot'] ?? 0)),
        '',
        bale_torobpay_festival_channel_note($channel),
    ]);
}

function bale_torobpay_festival_campaigns(int $page = 1): array
{
    $perPage = 6;
    $total = (int)db()->query("SELECT COUNT(*) FROM festivals WHERE is_torobpay_special=1 AND is_active=1 AND ends_at>=NOW()")->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $rows = db()->query(
        "SELECT f.*,p.title product_title,p.sku product_sku,p.price current_price
         FROM festivals f LEFT JOIN products p ON p.id=f.target_id
         WHERE f.is_torobpay_special=1 AND f.is_active=1 AND f.ends_at>=NOW()
         ORDER BY f.starts_at ASC,f.id DESC LIMIT {$perPage} OFFSET {$offset}"
    )->fetchAll();
    return ['rows'=>$rows ?: [],'page'=>$page,'pages'=>$pages,'total'=>$total];
}

function bale_torobpay_festival_management_markup(array $list): array
{
    $buttons = [];
    foreach ($list['rows'] as $campaign) {
        $title = text_substr((string)($campaign['product_title'] ?: 'محصول حذف‌شده'), 0, 28);
        $price = fa_number(format_price((int)($campaign['torobpay_new_price'] ?? 0)));
        $buttons[] = [[ 'text'=>'🛑 ' . $title . ' | ' . $price, 'callback_data'=>'tpf:cancel:' . (int)$campaign['id'] ]];
    }
    if ((int)$list['pages'] > 1) {
        $nav = [];
        if ((int)$list['page'] > 1) $nav[] = ['text'=>'⬅️ قبلی','callback_data'=>'tpf:manage:' . ((int)$list['page'] - 1)];
        $nav[] = ['text'=>fa_number((int)$list['page']) . ' / ' . fa_number((int)$list['pages']),'callback_data'=>'page:noop:0'];
        if ((int)$list['page'] < (int)$list['pages']) $nav[] = ['text'=>'بعدی ➡️','callback_data'=>'tpf:manage:' . ((int)$list['page'] + 1)];
        $buttons[] = $nav;
    }
    $buttons[] = [[ 'text'=>'↩️ بازگشت','callback_data'=>'tpf:menu' ]];
    return bale_product_bot_inline_keyboard($buttons);
}

function bale_torobpay_festival_show_management(string $chatId, string $userId, int $page = 1, int $messageId = 0): void
{
    $list = bale_torobpay_festival_campaigns($page);
    bale_product_bot_session_save($chatId, $userId, 'torobpay_festival_manage', ['page'=>(int)$list['page']]);
    $text = $list['rows']
        ? "📋 تخفیف‌های محدود فعال یا زمان‌بندی‌شده\n\nبرای لغو، محصول را انتخاب کنید. با لغو، قیمت تخفیفی خاموش و قیمت اصلی محصول فوراً برگردانده می‌شود."
        : "در حال حاضر تخفیف محدود فعال یا زمان‌بندی‌شده‌ای وجود ندارد.";
    $markup = bale_torobpay_festival_management_markup($list);
    if ($messageId > 0) bale_product_bot_edit($chatId, $messageId, $text, $markup);
    else bale_product_bot_send($chatId, $text, $markup);
}

function bale_torobpay_festival_callback(string $chatId, string $userId, int $messageId, string $dataValue, string $callbackId, ?array $session): bool
{
    if (!str_starts_with($dataValue, 'tpf:')) return false;

    if ($dataValue === 'tpf:menu') {
        bale_torobpay_festival_menu($chatId, $userId, $messageId);
        bale_product_bot_answer_callback($callbackId);
        return true;
    }
    if ($dataValue === 'tpf:create') {
        bale_product_bot_session_delete($chatId);
        bale_torobpay_festival_channel_menu($chatId, $messageId);
        bale_product_bot_answer_callback($callbackId);
        return true;
    }
    if (preg_match('/^tpf:channel:(site|torobpay|snappay|all)$/', $dataValue, $match)) {
        $channel = limited_discount_normalize_channel($match[1]);
        if (limited_discount_requires_torobpay($channel) && torobpay_special_payment_markup_percent() > 0.0001) {
            bale_product_bot_answer_callback($callbackId, 'درصد افزایش ترب‌پی باید صفر باشد.');
            bale_product_bot_send($chatId, '⛔ برای کانال ترب‌پی ابتدا «درصد ترب‌پی» را روی صفر تنظیم کنید.', bale_product_bot_main_keyboard($userId));
            return true;
        }
        bale_torobpay_festival_show_products($chatId, $userId, 1, $messageId, $channel);
        bale_product_bot_answer_callback($callbackId);
        return true;
    }
    if (preg_match('/^tpf:manage:(\d+)$/', $dataValue, $match)) {
        bale_torobpay_festival_show_management($chatId, $userId, max(1, (int)$match[1]), $messageId);
        bale_product_bot_answer_callback($callbackId);
        return true;
    }
    if (preg_match('/^tpf:cancel:(\d+)$/', $dataValue, $match)) {
        if (!$session || $session['step'] !== 'torobpay_festival_manage') {
            bale_product_bot_answer_callback($callbackId, 'این فهرست منقضی شده است؛ تخفیف‌ها را دوباره باز کنید.');
            return true;
        }
        $campaign = torobpay_special_campaign((int)$match[1]);
        if (!$campaign || empty($campaign['is_active']) || (strtotime((string)$campaign['ends_at']) ?: 0) < time()) {
            bale_product_bot_answer_callback($callbackId, 'این تخفیف دیگر فعال نیست.');
            bale_torobpay_festival_show_management($chatId, $userId, 1, $messageId);
            return true;
        }
        $text = implode("\n", [
            '⚠️ لغو تخفیف محدود؟',
            '',
            'محصول: ' . (string)($campaign['product_title'] ?? ''),
            'قیمت تخفیفی: ' . fa_number(format_price((int)$campaign['torobpay_new_price'])) . ' تومان',
            'قیمت اصلی پس از لغو: ' . fa_number(format_price((int)$campaign['torobpay_old_price'])) . ' تومان',
            '',
            'پس از تأیید، قیمت اصلی همان لحظه روی سایت و سبد خرید نمایش داده می‌شود.',
        ]);
        $nonce = bin2hex(random_bytes(8));
        bale_product_bot_session_save($chatId, $userId, 'torobpay_festival_cancel_confirm', [
            'campaign_id'=>(int)$campaign['id'],
            'nonce'=>$nonce,
        ]);
        $markup = bale_product_bot_inline_keyboard([
            [[ 'text'=>'✅ بله، لغو و بازگشت قیمت','callback_data'=>'tpf:cancelok:' . (int)$campaign['id'] . ':' . $nonce ]],
            [[ 'text'=>'↩️ بازگشت به فهرست','callback_data'=>'tpf:manage:1' ]],
        ]);
        bale_product_bot_edit($chatId, $messageId, $text, $markup);
        bale_product_bot_answer_callback($callbackId);
        return true;
    }
    if (preg_match('/^tpf:cancelok:(\d+):([a-f0-9]{16})$/D', $dataValue, $match)) {
        $campaignId = (int)$match[1];
        $expectedNonce = $session && is_array($session['data'] ?? null)
            ? trim((string)($session['data']['nonce'] ?? '')) : '';
        if (!$session || $session['step'] !== 'torobpay_festival_cancel_confirm'
            || (int)($session['data']['campaign_id'] ?? 0) !== $campaignId
            || !preg_match('/^[a-f0-9]{16}$/D',$expectedNonce)
            || !hash_equals($expectedNonce,(string)$match[2])) {
            bale_product_bot_answer_callback($callbackId, 'این تأیید لغو منقضی شده است؛ هیچ قیمتی تغییر نکرد.');
            return true;
        }
        $campaign = torobpay_special_campaign($campaignId);
        if (!$campaign || empty($campaign['is_active'])) {
            bale_product_bot_answer_callback($callbackId, 'این تخفیف قبلاً لغو شده است.');
            return true;
        }
        try {
            limited_discount_set_active((int)$campaign['id'], false, ['username'=>'bale:' . $userId,'bale_user_id'=>$userId]);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_answer_callback($callbackId, 'تخفیف لغو شد و قیمت برگشت.');
            $text = "✅ تخفیف محدود لغو شد\n\nمحصول: " . (string)($campaign['product_title'] ?? '') . "\nقیمت اصلی فعال: " . fa_number(format_price((int)$campaign['torobpay_old_price'])) . " تومان\n\nقیمت محصول در دیتابیس تغییر نکرده بود؛ بنابراین بازگشت قیمت فوری و خودکار انجام شد.";
            bale_product_bot_edit($chatId, $messageId, $text, bale_product_bot_inline_keyboard([
                [[ 'text'=>'📋 تخفیف‌های دیگر','callback_data'=>'tpf:manage:1' ]],
                [[ 'text'=>'↩️ منوی تخفیف محدود','callback_data'=>'tpf:menu' ]],
            ]));
        } catch (Throwable $error) {
            log_error($error);
            bale_product_bot_answer_callback($callbackId, 'لغو تخفیف انجام نشد.');
            bale_product_bot_send($chatId, '❌ ' . text_substr($error->getMessage(), 0, 500), bale_product_bot_main_keyboard($userId));
        }
        return true;
    }

    if (preg_match('/^tpf:page:(\d+)$/', $dataValue, $match)) {
        if (!$session || $session['step'] !== 'torobpay_festival_select') {
            bale_product_bot_answer_callback($callbackId, 'این فهرست منقضی شده است.');
            return true;
        }
        $channel = bale_torobpay_festival_channel($session['data']);
        bale_torobpay_festival_show_products($chatId, $userId, max(1, (int)$match[1]), $messageId, $channel);
        bale_product_bot_answer_callback($callbackId);
        return true;
    }

    if (preg_match('/^tpf:pick:(\d+)$/', $dataValue, $match)) {
        if (!$session || $session['step'] !== 'torobpay_festival_select') {
            bale_product_bot_answer_callback($callbackId, 'این فهرست منقضی شده است.');
            return true;
        }
        $channel = bale_torobpay_festival_channel($session['data']);
        $productId = (int)$match[1];
        $startsAt = date('Y-m-d H:i:s');
        $endsAt = date('Y-m-d H:i:s', time() + limited_discount_max_duration_seconds());
        $blockReason = torobpay_special_product_block_reason($productId, $startsAt, $endsAt, 0, null, $channel);
        $product = torobpay_special_product($productId);
        if ($blockReason !== null || !$product) {
            bale_product_bot_answer_callback($callbackId, text_substr($blockReason ?? 'محصول پیدا نشد.', 0, 170));
            return true;
        }
        $state = [
            'channel'=>$channel,
            'product_id'=>$productId,
            'product_title'=>(string)$product['title'],
            'product_sku'=>(string)$product['sku'],
            'old_price'=>(int)$product['price'],
            'stock'=>(int)$product['stock'],
        ];
        bale_product_bot_session_save($chatId, $userId, 'torobpay_festival_price', $state);
        try { bale_product_bot_edit($chatId, $messageId, '✅ محصول انتخاب شد: ' . (string)$product['title']); } catch (Throwable $error) { log_error($error); }
        bale_product_bot_answer_callback($callbackId, 'قیمت جدید را بفرستید.');
        bale_product_bot_send($chatId, "قیمت بعد از تخفیف را به تومان و فقط به‌صورت عدد بفرستید.\n\nقیمت اصلی: " . fa_number(format_price((int)$product['price'])) . " تومان\nقیمت جدید باید کمتر از این مقدار باشد.", bale_product_bot_cancel_keyboard());
        return true;
    }

    if (preg_match('/^tpf:dur:(24|48|72)$/', $dataValue, $match)) {
        if (!$session || $session['step'] !== 'torobpay_festival_duration') {
            bale_product_bot_answer_callback($callbackId, 'مرحله انتخاب مدت منقضی شده است.');
            return true;
        }
        $hours = (int)$match[1];
        $state = $session['data'];
        $state['starts_at'] = date('Y-m-d H:i:s');
        $state['ends_at'] = date('Y-m-d H:i:s', time() + ($hours * 3600));
        $channel = bale_torobpay_festival_channel($state);
        $blockReason = torobpay_special_product_block_reason((int)($state['product_id'] ?? 0), $state['starts_at'], $state['ends_at'], 0, null, $channel);
        if ($blockReason !== null) {
            bale_product_bot_answer_callback($callbackId, text_substr($blockReason, 0, 170));
            return true;
        }
        bale_product_bot_session_save($chatId, $userId, 'torobpay_festival_reason', $state);
        try { bale_product_bot_edit($chatId, $messageId, '✅ مدت تخفیف: ' . fa_number($hours) . " ساعت\nپایان: " . jalali_format($state['ends_at'])); } catch (Throwable $error) { log_error($error); }
        bale_product_bot_answer_callback($callbackId, 'دلیل تخفیف را بفرستید.');
        bale_product_bot_send(
            $chatId,
            "دلیل واقعی جذابیت تخفیف را بنویسید.\nمثال: قیمت رقابتی، موجودی محدود و تخفیف واقعی محصول پربازدید",
            bale_product_bot_inline_keyboard([
                [[ 'text'=>'⭐ استفاده از متن پیشنهادی','callback_data'=>'tpf:reason:default' ]],
                [[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]],
            ])
        );
        return true;
    }

    if ($dataValue === 'tpf:reason:default') {
        if (!$session || $session['step'] !== 'torobpay_festival_reason') {
            bale_product_bot_answer_callback($callbackId, 'این مرحله منقضی شده است.');
            return true;
        }
        $state = $session['data'];
        $state['popularity_reason'] = 'قیمت رقابتی، موجودی محدود و تخفیف واقعی محصول پربازدید';
        bale_product_bot_answer_callback($callbackId, 'متن پیشنهادی ثبت شد.');
        bale_torobpay_festival_confirm($chatId, $userId, $state, $messageId);
        return true;
    }

    if ($dataValue === 'tpf:save') {
        if (!$session || $session['step'] !== 'torobpay_festival_confirm') {
            bale_product_bot_answer_callback($callbackId, 'تأیید ساخت منقضی شده است.');
            return true;
        }
        $state = $session['data'];
        try {
            $campaign = limited_discount_create_campaign([
                'channel'=>bale_torobpay_festival_channel($state),
                'product_id'=>(int)($state['product_id'] ?? 0),
                'new_price'=>(int)($state['new_price'] ?? 0),
                'starts_at'=>(string)($state['starts_at'] ?? ''),
                'ends_at'=>(string)($state['ends_at'] ?? ''),
                'popularity_reason'=>(string)($state['popularity_reason'] ?? ''),
                'is_active'=>1,
            ], ['username'=>'bale:' . $userId,'bale_user_id'=>$userId]);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_answer_callback($callbackId, 'تخفیف محدود ساخته شد.');
            bale_product_bot_edit($chatId, $messageId, bale_torobpay_festival_result_text($campaign));
            bale_product_bot_send($chatId, 'منوی مدیریت:', bale_product_bot_main_keyboard($userId));
        } catch (Throwable $error) {
            log_error($error);
            bale_product_bot_answer_callback($callbackId, 'ساخت تخفیف انجام نشد.');
            bale_product_bot_send($chatId, '❌ ' . text_substr($error->getMessage(), 0, 500), bale_product_bot_cancel_keyboard());
        }
        return true;
    }

    bale_product_bot_answer_callback($callbackId, 'دکمه تخفیف محدود معتبر نیست یا منقضی شده است.');
    return true;
}

function bale_torobpay_festival_message(string $chatId, string $userId, string $step, array $data, array $message, string $text): bool
{
    if ($step === 'torobpay_festival_price') {
        $number = preg_replace('/[^0-9]/', '', normalize_digits($text)) ?? '';
        $oldPrice = max(0, (int)($data['old_price'] ?? 0));
        if ($number === '' || strlen($number) > 15) {
            bale_product_bot_send($chatId, 'قیمت معتبر را فقط به‌صورت عدد بفرستید.', bale_product_bot_cancel_keyboard());
            return true;
        }
        $newPrice = (int)$number;
        if ($newPrice < 1 || $newPrice >= $oldPrice || $newPrice > MAX_MONEY_AMOUNT) {
            bale_product_bot_send($chatId, 'قیمت بعد از تخفیف باید بیشتر از صفر و کمتر از ' . fa_number(format_price($oldPrice)) . ' تومان باشد.', bale_product_bot_cancel_keyboard());
            return true;
        }
        $data['new_price'] = $newPrice;
        bale_product_bot_session_save($chatId, $userId, 'torobpay_festival_duration', $data);
        $percent = limited_discount_percent($oldPrice, $newPrice);
        $percentLabel = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        bale_product_bot_send(
            $chatId,
            "✅ قیمت بعد: " . fa_number(format_price($newPrice)) . " تومان\nتخفیف واقعی: " . fa_number($percentLabel) . "٪\n\nمدت تخفیف را انتخاب کنید؛ سقف مجاز سه روز است.",
            bale_product_bot_inline_keyboard([
                [[ 'text'=>'۱ روز','callback_data'=>'tpf:dur:24' ],[ 'text'=>'۲ روز','callback_data'=>'tpf:dur:48' ],[ 'text'=>'۳ روز','callback_data'=>'tpf:dur:72' ]],
                [[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]],
            ])
        );
        return true;
    }

    if ($step === 'torobpay_festival_reason') {
        $reason = text_substr(trim($text), 0, 180);
        if (text_length($reason) < 3) {
            bale_product_bot_send($chatId, 'دلیل باید حداقل سه نویسه باشد؛ یک توضیح واقعی درباره جذابیت تخفیف بفرستید.', bale_product_bot_cancel_keyboard());
            return true;
        }
        $data['popularity_reason'] = $reason;
        bale_torobpay_festival_confirm($chatId, $userId, $data);
        return true;
    }

    return false;
}
