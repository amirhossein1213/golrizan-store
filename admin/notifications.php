<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';admin_required();
$admin=admin_user();$adminId=(int)($admin['id']??0);
if(is_post()){
    verify_csrf();$action=scalar_string($_POST['action']??'');
    try{
        if($action==='save_preferences'){
            admin_notification_save_preferences($adminId,$_POST);if(admin_can('manage_settings')){
                $threshold=max(1,min(100,scalar_int($_POST['admin_low_stock_threshold']??3,3)));
                $retention=max(7,min(365,scalar_int($_POST['admin_notification_retention_days']??90,90)));
                $settingStmt=db()->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
                $settingStmt->execute(['admin_low_stock_threshold',(string)$threshold]);$settingStmt->execute(['admin_notification_retention_days',(string)$retention]);
            }flash('success','تنظیمات اعلان‌های شما ذخیره شد.');
        }elseif($action==='read_all'){
            admin_notification_mark_all_read($adminId);flash('success','همه اعلان‌ها خوانده‌شده شدند.');
        }elseif($action==='read'){
            admin_notification_mark_read($adminId,[scalar_int($_POST['id']??0)]);
        }elseif($action==='delete'){
            admin_notification_delete_for_admin($adminId,scalar_int($_POST['id']??0));flash('success','اعلان از فهرست شما حذف شد.');
        }
    }catch(Throwable $e){log_error($e);flash('error','ذخیره تغییرات اعلان انجام نشد.');}
    redirect(app_url('admin/notifications.php'));
}
$prefs=admin_notification_preferences($adminId);$catalog=admin_notification_event_catalog();$rows=admin_notifications_for_admin($adminId,100,false);
$adminPage='notifications';$adminTitle='مرکز اعلان‌ها';require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1>مرکز اعلان‌های مدیریت</h1><p class="muted">اعلان سفارش، پرداخت، مرجوعی، موجودی، پیامک و درگاه بدون نیاز به Cron ثبت می‌شود.</p></div><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="read_all"><button class="btn btn-secondary">خواندن همه</button></form></div>
<div class="notification-layout">
<section class="card notification-preferences">
<h2>روش دریافت اعلان</h2>
<p class="muted">اعلان مرورگر فقط پس از اجازه شما در همین دستگاه نمایش داده می‌شود.</p>
<button class="btn btn-primary" type="button" data-enable-browser-notifications>فعال‌کردن اعلان مرورگر</button>
<p class="muted" data-browser-notification-state></p>
<form method="post" style="margin-top:18px"><?=csrf_field()?><input type="hidden" name="action" value="save_preferences">
<div class="notification-preference-grid">
<label><input type="checkbox" name="in_panel" value="1" <?=$prefs['in_panel']?'checked':''?>> اعلان داخل پنل</label>
<label><input type="checkbox" name="browser_enabled" value="1" <?=$prefs['browser_enabled']?'checked':''?>> اعلان مرورگر</label>
<label><input type="checkbox" name="sound_enabled" value="1" <?=$prefs['sound_enabled']?'checked':''?>> پخش صدای کوتاه</label>
</div>
<?php if(admin_can('manage_settings')):?><div class="form-grid"><div class="field"><label>حد هشدار موجودی کم</label><input type="number" name="admin_low_stock_threshold" min="1" max="100" value="<?=e((string)setting('admin_low_stock_threshold','3'))?>"><small>وقتی موجودی محصول به این عدد یا کمتر برسد، اعلان ثبت می‌شود.</small></div><div class="field"><label>نگهداری اعلان‌ها، روز</label><input type="number" name="admin_notification_retention_days" min="7" max="365" value="<?=e((string)setting('admin_notification_retention_days','90'))?>"><small>اعلان‌های قدیمی‌تر توسط Cron مرکزی پاک می‌شوند.</small></div></div><?php endif?>
<h3>رویدادهای موردنظر</h3>
<div class="notification-preference-grid">
<?php foreach($catalog as $eventKey=>$meta):$preference=$meta['preference'];?>
<label><input type="checkbox" name="<?=e($preference)?>" value="1" <?=$prefs[$preference]?'checked':''?>> <?=e($meta['label'])?></label>
<?php endforeach?>
</div>
<button class="btn btn-primary" style="margin-top:16px">ذخیره تنظیمات</button>
</form>
</section>
<section class="card notification-list-card">
<div class="admin-title"><h2>آخرین اعلان‌ها</h2><span class="badge"><?=fa_number(admin_notification_unread_count($adminId))?> خوانده‌نشده</span></div>
<div class="notification-list">
<?php foreach($rows as $row):$url=admin_notification_safe_path((string)($row['action_path']??''));?>
<article class="notification-item notification-item--<?=e($row['level'])?> <?=$row['read_at']?'is-read':'is-unread'?>">
<div class="notification-item__signal"></div>
<div class="notification-item__body"><div><strong><?=e($row['title'])?></strong><time><?=e(jalali_format($row['created_at']))?></time></div><p><?=e($row['message'])?></p><div class="notification-item__actions">
<?php if($url!==''):?><a class="btn btn-secondary btn-sm" href="<?=e(app_url($url))?>">مشاهده</a><?php endif?>
<?php if(!$row['read_at']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-secondary btn-sm">خواندم</button></form><?php endif?>
<form method="post" data-confirm="این اعلان از فهرست شما حذف شود؟"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-danger btn-sm">حذف</button></form>
</div></div>
</article>
<?php endforeach?>
<?php if(!$rows):?><div class="empty-state">هنوز اعلانی برای شما ثبت نشده است.</div><?php endif?>
</div>
</section>
</div>
<?php require __DIR__.'/partials/footer.php';
