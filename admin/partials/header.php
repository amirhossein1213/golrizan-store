<?php
admin_required();

$adminPage = $adminPage ?? '';
$adminTitle = $adminTitle ?? 'پنل مدیریت';
$user = admin_user();
$adminName = trim((string)($user['full_name'] ?? 'مدیر فروشگاه')) ?: 'مدیر فروشگاه';
$adminInitial = text_substr($adminName, 0, 1);
$adminId = (int)($user['id'] ?? 0);
$adminNotificationPrefs = admin_notification_preferences($adminId);
$adminNotificationAllRecent = admin_notifications_for_admin($adminId, 5, false);
$adminNotificationPanelEnabled = !empty($adminNotificationPrefs['in_panel']);
$adminNotificationUnread = $adminNotificationPanelEnabled ? admin_notification_unread_count($adminId) : 0;
$adminNotificationRecent = $adminNotificationPanelEnabled ? $adminNotificationAllRecent : [];
$adminNotificationLastId = $adminNotificationAllRecent ? max(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $adminNotificationAllRecent)) : 0;

$adminIcons = [
    'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>',
    'box' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.2v9.6L12 21l-8-4.2V7.2L12 3Zm0 2.3L7 7.9l5 2.6 5-2.6-5-2.6Zm-6 4.2v6.1l5 2.6v-6.1L6 9.5Zm7 8.7 5-2.6V9.5l-5 2.6v6.1Z"/></svg>',
    'gallery' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v9.2l3.8-3.8 2.7 2.7 2.8-3.3L19 16V6H5Zm2.5 4A1.5 1.5 0 1 0 7.5 7a1.5 1.5 0 0 0 0 3Z"/></svg>',
    'layers' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Zm-6.9 9L12 15.8l6.9-3.8 2.1 1.2-9 5-9-5L5.1 12Zm0 5L12 20.8l6.9-3.8 2.1 1.2-9 5-9-5L5.1 17Z"/></svg>',
    'tag' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-9-9Zm5-3a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>',
    'cart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2 10.2a2 2 0 0 0 2 1.6h8a2 2 0 0 0 2-1.6L21 7H6M9 21a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm9 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/></svg>',
    'return' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h10a4 4 0 0 1 4 4v6h-2v-6a2 2 0 0 0-2-2H7v4L2 8l5-5v4Zm10 10H7a4 4 0 0 1-4-4v-2h2v2a2 2 0 0 0 2 2h10v-4l5 5-5 5v-4Z"/></svg>',
    'warehouse' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 9 9-6 9 6v12h-3v-9H6v9H3V9Zm5 5h8v2H8v-2Zm0 4h8v3H8v-3Z"/></svg>',
    'truck' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h11v11H3V5Zm11 4h4l3 4v3h-2a3 3 0 0 1-6 0H9a3 3 0 0 1-6 0H2V7h2v7h10V9Zm-8 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm10 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm0-7v3h3l-2-3h-1Z"/></svg>',
    'wallet' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h15a2 2 0 0 1 2 2v3h-6a3 3 0 0 0 0 6h6v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm11 7h7v2h-7a1 1 0 0 1 0-2Z"/></svg>',
    'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-1a3 3 0 1 0 0-6v6ZM2 20a7 7 0 0 1 14 0H2Zm14.5-7a5.5 5.5 0 0 1 5.5 5.5V20h-4a9 9 0 0 0-2.2-6.8l.7-.2Z"/></svg>',
    'message' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm3 5v2h10V9H7Zm0 4v2h7v-2H7Z"/></svg>',
    'megaphone' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h4l10-5v14L7 14H3v-4Zm6 5.2v4.3A2.5 2.5 0 0 1 6.5 22H5v-8h2l2 1.2ZM19 9l2-2 1 1-2 2-1-1Zm0 6 1-1 2 2-1 1-2-2Z"/></svg>',
    'chart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10h4v10H4Zm6 0V4h4v16h-4Zm6 0v-7h4v7h-4Z"/></svg>',
    'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.8 2h2.4l.5 2.3 1.8.8 2-1.2 1.7 1.7-1.2 2 .8 1.8 2.2.5v2.4l-2.2.5-.8 1.8 1.2 2-1.7 1.7-2-1.2-1.8.8-.5 2.1h-2.4l-.5-2.1-1.8-.8-2 1.2-1.7-1.7 1.2-2-.8-1.8-2.2-.5V9.9l2.2-.5.8-1.8-1.2-2 1.7-1.7 2 1.2 1.8-.8.5-2.3ZM12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/></svg>',
    'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 20 5v6c0 5.2-3.4 9.3-8 11-4.6-1.7-8-5.8-8-11V5l8-3Zm0 3.1L7 7v4c0 3.5 2 6.4 5 7.9 3-1.5 5-4.4 5-7.9V7l-5-1.9Z"/></svg>',
    'database' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c5 0 9 1.6 9 3.5S17 10 12 10 3 8.4 3 6.5 7 3 12 3Zm-9 7c2 1.4 5.3 2 9 2s7-.6 9-2v4.5c0 1.9-4 3.5-9 3.5s-9-1.6-9-3.5V10Zm0 8c2 1.4 5.3 2 9 2s7-.6 9-2v1.5c0 1.9-4 3.5-9 3.5s-9-1.6-9-3.5V18Z"/></svg>',
    'bell' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.4-2h-4.8A2.5 2.5 0 0 0 12 22Zm8-5-2-2v-4a6 6 0 0 0-5-5.9V3h-2v2.1A6 6 0 0 0 6 11v4l-2 2v1h16v-1Z"/></svg>',
    'external' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7h-2V6.4l-8.3 8.3-1.4-1.4L17.6 5H14V3ZM5 5h6v2H5v12h12v-6h2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg>',
];

$navGroups = [
    ['label' => 'نمای کلی', 'items' => [
        ['dashboard', 'index.php', 'داشبورد', 'view_dashboard', 'dashboard'],
    ]],
    ['label' => 'کاتالوگ و فروش', 'items' => [
        ['homepage', 'homepage.php', 'طراحی صفحه اصلی', 'manage_content', 'gallery'],
        ['home-banners', 'home-banners.php', 'بنرهای تصویری', 'manage_content', 'gallery'],
        ['products', 'products.php', 'محصولات', 'manage_products', 'box'],
        ['price-adjustments', 'price-adjustments.php', 'تغییر گروهی قیمت‌ها', 'manage_products', 'chart'],
        ['quick-product', 'product-quick-add.php', 'افزودن سریع محصول', 'manage_products', 'box'],
        ['torob-sync', 'torob-sync.php', 'همگام‌سازی ترب', 'manage_products', 'external'],
        ['bale-product-bot', 'bale-product-bot.php', 'ربات بله افزودن محصول', 'manage_system', 'message'],
        ['gallery', 'product-gallery.php', 'گالری محصولات', 'manage_products', 'gallery'],
        ['attributes', 'attributes.php', 'ویژگی‌ها و مدل‌ها', 'manage_products', 'layers'],
        ['categories', 'categories.php', 'دسته‌بندی‌ها', 'manage_products', 'tag'],
        ['brands', 'brands.php', 'برندها', 'manage_products', 'tag'],
        ['festivals', 'festivals.php', 'جشنواره‌ها', 'manage_products', 'megaphone'],
        ['limited-discounts', 'limited-discounts.php', 'تخفیف محدود', 'manage_products', 'megaphone'],
        ['orders', 'orders.php', 'سفارش‌ها', 'manage_orders', 'cart'],
        ['order-board', 'order-board.php', 'تابلوی سفارش‌ها', 'manage_orders', 'layers'],
        ['notifications', 'notifications.php', 'مرکز اعلان‌ها', '', 'message'],
        ['returns', 'returns.php', 'مرجوعی‌ها', 'manage_returns', 'return'],
    ]],
    ['label' => 'عملیات', 'items' => [
        ['shipments', 'shipments.php', 'ارسال و رهگیری', 'manage_shipping', 'truck'],
        ['payments', 'payment-settings.php', 'زیبال + ترب‌پی + اسنپ‌پی', 'manage_payments', 'wallet'],
        ['accounting', 'accounting.php', 'حسابداری', 'manage_accounting', 'chart'],
    ]],
    ['label' => 'مشتریان و بازاریابی', 'items' => [
        ['customers', 'customers.php', 'مشتریان', 'manage_customers', 'users'],
        ['tickets', 'tickets.php', 'تیکت‌های پشتیبانی', 'manage_tickets', 'message'],
        ['reviews', 'reviews.php', 'نظر و پرسش‌وپاسخ', 'manage_reviews', 'message'],
        ['blog', 'blog-posts.php', 'وبلاگ و محتوا', 'manage_blog', 'message'],
        ['trust-center', 'trust-center.php', 'مرکز اعتماد', 'manage_content', 'shield'],
        ['faqs', 'faqs.php', 'پرسش‌های متداول', 'manage_content', 'message'],
        ['messages', 'messages.php', 'پیام‌ها', 'manage_content', 'message'],
        ['sms', 'sms-settings.php', 'تنظیمات پیامک', 'manage_sms', 'message'],
        ['sms-recipients', 'sms-recipients.php', 'وضعیت پیامک شماره‌ها', 'manage_sms', 'chart'],
        ['campaigns', 'sms-campaigns.php', 'پیامک گروهی', 'manage_marketing', 'megaphone'],
        ['alerts', 'product-alerts.php', 'هشدار موجودی و قیمت', 'manage_marketing', 'megaphone'],
        ['coupons', 'coupons.php', 'کدهای تخفیف', 'manage_marketing', 'tag'],
        ['abandoned', 'abandoned-carts.php', 'سبدهای رهاشده', 'manage_marketing', 'cart'],
    ]],
    ['label' => 'گزارش و تنظیمات', 'items' => [
        ['reports', 'reports.php', 'گزارش‌های مدیریتی', 'view_reports', 'chart'],
        ['settings', 'settings.php', 'تنظیمات فروشگاه', 'manage_settings', 'settings'],
        ['security', 'security.php', 'امنیت حساب من', '', 'shield'],
        ['admins', 'admin-users.php', 'مدیران و نقش‌ها', 'manage_admins', 'users'],
        ['audit', 'activity-logs.php', 'گزارش فعالیت', 'view_audit', 'shield'],
        ['backups', 'backups.php', 'بکاپ خودکار', 'manage_backups', 'database'],
        ['system-health', 'system-health.php', 'سلامت سایت و Cron', 'manage_system', 'shield'],
        ['upgrade', 'upgrade.php', 'ارتقای سیستم', 'manage_system', 'database'],
    ]],
];
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#0f172a">
    <title><?=e($adminTitle)?> | مدیریت گلریزان</title>
    <link rel="stylesheet" href="<?=e(asset('css/admin-v200.css'))?>">
    <link rel="stylesheet" href="<?=e(asset('css/admin.css'))?>">
</head>
<body class="admin-body" data-admin-page="<?=e($adminPage)?>" data-notification-endpoint="<?=e(app_url('admin/api-notifications.php'))?>" data-notification-csrf="<?=e(csrf_token())?>" data-notification-browser="<?=$adminNotificationPrefs['browser_enabled']?'1':'0'?>" data-notification-sound="<?=$adminNotificationPrefs['sound_enabled']?'1':'0'?>" data-notification-panel="<?=$adminNotificationPanelEnabled?'1':'0'?>" data-notification-last-id="<?=$adminNotificationLastId?>">
<div class="admin-shell">
    <aside class="admin-sidebar" data-admin-sidebar aria-label="منوی مدیریت">
        <div class="admin-sidebar__head">
            <a class="admin-brand" href="<?=e(app_url('admin/'))?>">
                <span class="admin-brand__mark">م</span>
                <span class="admin-brand__text"><strong><?=e(setting('store_name','لوازم خانگی گلریزان'))?></strong><small>مرکز مدیریت فروشگاه</small></span>
            </a>
            <button class="admin-icon-button admin-sidebar__close" type="button" data-admin-menu-close aria-label="بستن منو">×</button>
        </div>
        <?php $cronHealthy=central_cron_is_fresh(25); ?><?php if(admin_can('manage_system')):?><a class="admin-sidebar__status <?=$cronHealthy?'is-healthy':'is-warning'?>" href="<?=e(app_url('admin/system-health.php'))?>"><span></span> <?=$cronHealthy?'Cron مرکزی فعال است':'سلامت Cron را بررسی کنید'?></a><?php else:?><div class="admin-sidebar__status <?=$cronHealthy?'is-healthy':'is-warning'?>"><span></span> <?=$cronHealthy?'سامانه زمان‌بندی فعال است':'زمان‌بندی نیازمند بررسی است'?></div><?php endif?>
        <nav class="admin-nav">
            <?php foreach($navGroups as $group):
                $visibleItems = array_values(array_filter($group['items'], static fn(array $item): bool => $item[3] === '' || admin_can($item[3])));
                if (!$visibleItems) continue;
            ?>
                <section class="admin-nav-group">
                    <h2><?=e($group['label'])?></h2>
                    <?php foreach($visibleItems as [$key,$href,$label,$permission,$icon]):?>
                        <a class="admin-nav__item <?=$adminPage===$key?'active':''?>" href="<?=e(app_url('admin/'.$href))?>" <?=$adminPage===$key?'aria-current="page"':''?>>
                            <span class="admin-nav__icon"><?=$adminIcons[$icon]??$adminIcons['dashboard']?></span>
                            <span><?=e($label)?></span>
                        </a>
                    <?php endforeach?>
                </section>
            <?php endforeach?>
        </nav>
        <div class="admin-sidebar__footer">
            <div class="admin-user-card">
                <span class="admin-avatar"><?=e($adminInitial)?></span>
                <span><strong><?=e($adminName)?></strong><small><?=admin_is_super($user)?'مدیر کل':'مدیر پنل'?></small></span>
            </div>
            <form class="admin-nav__logout" method="post" action="<?=e(app_url('admin/logout.php'))?>"><?=csrf_field()?><button type="submit">خروج امن از حساب</button></form>
        </div>
    </aside>
    <button class="admin-backdrop" type="button" data-admin-menu-close aria-label="بستن منو" tabindex="-1"></button>
    <main class="admin-main">
        <header class="admin-top">
            <div class="admin-top__context">
                <button class="admin-icon-button mobile-admin-menu" type="button" data-admin-menu aria-label="بازکردن منو" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z"/></svg>
                </button>
                <div><small>پنل مدیریت</small><strong><?=e($adminTitle)?></strong></div>
            </div>
            <div class="admin-top__actions">
                <div class="admin-notification-bell" data-admin-notification-root>
                    <button class="admin-icon-button admin-notification-bell__button" type="button" data-admin-notification-toggle aria-label="اعلان‌های مدیریت" aria-expanded="false">
                        <?=$adminIcons['bell']?>
                        <span class="admin-notification-bell__count <?=$adminNotificationUnread<1?'is-empty':''?>" data-admin-notification-count><?=fa_number($adminNotificationUnread)?></span>
                    </button>
                    <div class="admin-notification-popover" data-admin-notification-popover hidden>
                        <div class="admin-notification-popover__head"><strong>اعلان‌ها</strong><a href="<?=e(app_url('admin/notifications.php'))?>">مشاهده همه</a></div>
                        <div class="admin-notification-popover__list" data-admin-notification-list>
                            <?php foreach($adminNotificationRecent as $notification):$notificationPath=admin_notification_safe_path((string)($notification['action_path']??''));?>
                                <a class="admin-notification-mini admin-notification-mini--<?=e($notification['level'])?> <?=$notification['read_at']?'is-read':'is-unread'?>" href="<?=e($notificationPath!==''?app_url($notificationPath):app_url('admin/notifications.php'))?>" data-notification-id="<?=(int)$notification['id']?>">
                                    <strong><?=e($notification['title'])?></strong><span><?=e($notification['message'])?></span><small><?=e(jalali_format($notification['created_at']))?></small>
                                </a>
                            <?php endforeach?>
                            <?php if(!$adminNotificationPanelEnabled):?><div class="admin-notification-empty">اعلان داخل پنل خاموش است؛ از تنظیمات اعلان فعالش کنید.</div><?php elseif(!$adminNotificationRecent):?><div class="admin-notification-empty">اعلان جدیدی وجود ندارد.</div><?php endif?>
                        </div>
                        <div class="admin-notification-popover__footer"><button type="button" class="btn btn-secondary btn-sm" data-admin-notification-read-all>خواندن همه</button><a class="btn btn-primary btn-sm" href="<?=e(app_url('admin/notifications.php'))?>">تنظیمات</a></div>
                    </div>
                </div>
                <span class="admin-date"><?=e(jalali_format(time()))?></span>
                <a class="admin-view-store" href="<?=e(app_url())?>" target="_blank" rel="noopener noreferrer"><span>مشاهده فروشگاه</span><?=$adminIcons['external']?></a>
                <span class="admin-top__avatar" title="<?=e($adminName)?>"><?=e($adminInitial)?></span>
            </div>
        </header>
        <section class="admin-content">
            <?php foreach(flashes() as $flash):?><div class="flash <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endforeach?>
