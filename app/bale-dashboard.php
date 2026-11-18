<?php
declare(strict_types=1);

/** @param array<int,array<int,string>> $rows @return array<string,mixed> */
function bale_dashboard_reply_keyboard(array $rows): array
{
    $keyboard = [];
    $seen = [];
    foreach ($rows as $row) {
        $buttons = [];
        foreach ($row as $label) {
            $label = trim((string)$label);
            if ($label === '' || isset($seen[$label])) continue;
            $seen[$label] = true;
            $buttons[] = ['text'=>text_substr($label,0,60)];
        }
        if ($buttons) $keyboard[] = $buttons;
    }
    return ['keyboard'=>$keyboard,'resize_keyboard'=>true,'one_time_keyboard'=>false,'selective'=>true];
}

function bale_dashboard_can(string $userId, string $capability): bool
{
    return $userId === '' || bale_product_bot_can($userId,$capability);
}

/** @return array<string,mixed> */
function bale_dashboard_main_keyboard(string $userId): array
{
    $rows = [];
    $first = [];
    if (bale_dashboard_can($userId,'products')) $first[] = '📦 محصولات';
    if (bale_dashboard_can($userId,'orders') && bale_product_bot_order_management_enabled()) $first[] = '🧾 سفارش‌ها';
    if ($first) $rows[] = $first;

    $second = [];
    if (bale_dashboard_can($userId,'products')) $second[] = '💰 قیمت و تخفیف';
    if (bale_dashboard_can($userId,'reports')) $second[] = '📊 گزارش‌ها';
    if ($second) $rows[] = $second;

    $third = [];
    if (bale_dashboard_can($userId,'tickets') || bale_dashboard_can($userId,'returns')) $third[] = '💬 پشتیبانی';
    $third[] = '⚙️ تنظیمات و دسترسی';
    $rows[] = $third;
    $rows[] = ['🏠 داشبورد'];
    return bale_dashboard_reply_keyboard($rows);
}

function bale_dashboard_safe_count(string $sql): int
{
    try { return max(0,(int)db()->query($sql)->fetchColumn()); }
    catch (Throwable $error) { log_error($error); return 0; }
}

/** @return array<string,int> */
function bale_dashboard_stats(): array
{
    $lowStock = 0;
    try { if (function_exists('bale_adv_low_stock_count')) $lowStock = bale_adv_low_stock_count(); }
    catch (Throwable $error) { log_error($error); }
    return [
        'new_orders'=>bale_dashboard_safe_count("SELECT COUNT(*) FROM orders WHERE order_status='new'"),
        'low_stock'=>$lowStock,
        'limited_discounts'=>bale_dashboard_safe_count("SELECT COUNT(*) FROM festivals WHERE is_torobpay_special=1 AND is_active=1 AND starts_at<=NOW() AND ends_at>=NOW()"),
        'tickets'=>bale_dashboard_safe_count("SELECT COUNT(*) FROM support_tickets WHERE status='pending_admin'"),
        'returns'=>bale_dashboard_safe_count("SELECT COUNT(*) FROM return_requests WHERE status IN ('requested','reviewing')"),
    ];
}

function bale_dashboard_text(string $userId): string
{
    $stats = bale_dashboard_stats();
    $lines = [
        '🟦 فروشگاه گل‌ریزان | پنل مدیریت',
        '━━━━━━━━━━━━━━━━━━',
        '🔔 وضعیت فعلی فروشگاه',
    ];
    if (bale_dashboard_can($userId,'orders')) $lines[] = '🧾 سفارش جدید: ' . fa_number($stats['new_orders']);
    if (bale_dashboard_can($userId,'products')) {
        $lines[] = '⚠️ کالا یا مدل کم‌موجود: ' . fa_number($stats['low_stock']);
        $lines[] = '⏳ تخفیف محدود فعال: ' . fa_number($stats['limited_discounts']);
    }
    if (bale_dashboard_can($userId,'tickets')) $lines[] = '🎫 تیکت منتظر پاسخ: ' . fa_number($stats['tickets']);
    if (bale_dashboard_can($userId,'returns')) $lines[] = '↩️ مرجوعی در انتظار بررسی: ' . fa_number($stats['returns']);
    $lines[] = '━━━━━━━━━━━━━━━━━━';
    $lines[] = 'یکی از بخش‌های پایین را انتخاب کنید.';
    $lines[] = 'برای شروع دوباره همیشه /menu در دسترس است.';
    return implode("\n",$lines);
}

function bale_dashboard_send(string $chatId, string $userId, string $notice = ''): void
{
    $text = $notice !== '' ? trim($notice) . "\n\n" : '';
    bale_product_bot_send($chatId,$text . bale_dashboard_text($userId),bale_dashboard_main_keyboard($userId));
}

function bale_dashboard_is_command(string $text): bool
{
    return in_array($text,['📦 محصولات','🧾 سفارش‌ها','💰 قیمت و تخفیف','📊 گزارش‌ها','💬 پشتیبانی','⚙️ تنظیمات و دسترسی','🏠 داشبورد'],true);
}

function bale_dashboard_command_allowed(string $userId, string $text): bool
{
    return match ($text) {
        '📦 محصولات','💰 قیمت و تخفیف'=>bale_dashboard_can($userId,'products'),
        '🧾 سفارش‌ها'=>bale_dashboard_can($userId,'orders') && bale_product_bot_order_management_enabled(),
        '📊 گزارش‌ها'=>bale_dashboard_can($userId,'reports'),
        '💬 پشتیبانی'=>bale_dashboard_can($userId,'tickets') || bale_dashboard_can($userId,'returns'),
        '⚙️ تنظیمات و دسترسی','🏠 داشبورد'=>true,
        default=>false,
    };
}

/** @return array<string,mixed> */
function bale_dashboard_section_keyboard(string $section, string $userId): array
{
    $rows = match ($section) {
        'products' => [
            ['➕ افزودن سریع محصول','✏️ ویرایش محصول'],
            ['⚡ قیمت/موجودی سریع','🖼 مدیریت تصاویر'],
            ['🔗 لینک محصولات','🪄 اصلاح عکس‌های قبلی'],
            ['📂 مدیریت دسته‌ها','🏷 مدیریت برندها'],
            ['🗑 حذف محصول'],
        ],
        'pricing' => [
            ['📈 افزایش/کاهش درصدی','⏳ تخفیف محدود'],
            ['⚡ قیمت/موجودی سریع'],
        ],
        'orders' => [['🧾 مدیریت سفارش‌ها']],
        'reports' => [['📊 گزارش فروش امروز','⚠️ موجودی کم']],
        'support' => array_values(array_filter([
            bale_dashboard_can($userId,'tickets') ? ['🎫 تیکت‌های مشتریان'] : [],
            bale_dashboard_can($userId,'returns') ? ['↩️ درخواست‌های مرجوعی'] : [],
        ])),
        'settings' => array_values(array_filter([
            bale_dashboard_can($userId,'payments') ? ['💳 درصد افزایش ترب‌پی'] : [],
            ['🆔 شناسه من'],
        ])),
        default => [],
    };
    $rows[] = ['🏠 داشبورد'];
    return bale_dashboard_reply_keyboard($rows);
}

function bale_dashboard_open_section(string $chatId, string $userId, string $section): void
{
    [$title,$description] = match ($section) {
        'products' => ['📦 مدیریت محصولات','افزودن، ویرایش، تصاویر، دسته‌ها، برندها و لینک محصولات.'],
        'pricing' => ['💰 قیمت و تخفیف','تغییر تکی یا درصدی قیمت فروش و مدیریت تخفیف‌های محدود. قیمت خرید از این بخش تغییر نمی‌کند.'],
        'orders' => ['🧾 مدیریت سفارش‌ها','مشاهده سفارش، اطلاعات مشتری، تغییر مرحله و ثبت کد رهگیری.'],
        'reports' => ['📊 گزارش‌ها و هشدارها','فروش امروز و کالاها یا مدل‌هایی که موجودی آن‌ها به حد هشدار رسیده است.'],
        'support' => ['💬 پشتیبانی مشتریان','پاسخ به تیکت‌ها و بررسی درخواست‌های مرجوعی.'],
        'settings' => ['⚙️ تنظیمات و دسترسی','تنظیم درصد ترب‌پی و مشاهده شناسه مدیر این حساب بله.'],
        default => ['🏠 داشبورد','بخش موردنظر را انتخاب کنید.'],
    };
    bale_product_bot_send($chatId,$title."\n━━━━━━━━━━━━━━━━━━\n".$description."\n\nعملیات موردنظر را از دکمه‌های پایین انتخاب کنید.",bale_dashboard_section_keyboard($section,$userId));
}

function bale_dashboard_handle_command(string $chatId, string $userId, string $text): bool
{
    if (!bale_dashboard_is_command($text)) return false;
    if ($text === '🏠 داشبورد') { bale_dashboard_send($chatId,$userId); return true; }
    $section = match ($text) {
        '📦 محصولات'=>'products','🧾 سفارش‌ها'=>'orders','💰 قیمت و تخفیف'=>'pricing',
        '📊 گزارش‌ها'=>'reports','💬 پشتیبانی'=>'support','⚙️ تنظیمات و دسترسی'=>'settings',
        default=>'',
    };
    $allowed=bale_dashboard_command_allowed($userId,$text);
    if (!$allowed) {
        bale_product_bot_send($chatId,'⛔ دسترسی این بخش برای حساب شما فعال نیست.',bale_dashboard_main_keyboard($userId));
        return true;
    }
    bale_dashboard_open_section($chatId,$userId,$section);
    return true;
}
