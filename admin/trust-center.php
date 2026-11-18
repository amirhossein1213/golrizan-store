<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$errors = [];
if (is_post()) {
    verify_csrf();
    $action = scalar_string($_POST['action'] ?? 'save_item', 'save_item');
    $id = max(0, scalar_int($_POST['id'] ?? 0));
    try {
        if ($action === 'settings') {
            $title = trim(scalar_string($_POST['trust_page_title'] ?? ''));
            $intro = trim(scalar_string($_POST['trust_page_intro'] ?? ''));
            if (text_length($title) < 2 || text_length($title) > 180 || text_length($intro) > 500) throw new RuntimeException('عنوان یا توضیح مرکز اعتماد معتبر نیست.');
            $stmt = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $stmt->execute(['trust_page_title',$title]);
            $stmt->execute(['trust_page_intro',$intro]);
            admin_audit_event('trust_settings_saved');
            flash('success', 'عنوان و توضیح مرکز اعتماد ذخیره شد.');
            redirect(app_url('admin/trust-center.php'));
        }
        if ($action === 'delete') {
            db()->prepare('DELETE FROM trust_items WHERE id=?')->execute([$id]);
            admin_audit_event('trust_item_deleted', 'trust_item', $id);
            flash('success', 'تعهد فروشگاه حذف شد.');
            redirect(app_url('admin/trust-center.php'));
        }
        if ($action === 'toggle') {
            db()->prepare('UPDATE trust_items SET is_active=1-is_active WHERE id=?')->execute([$id]);
            admin_audit_event('trust_item_toggled', 'trust_item', $id);
            flash('success', 'وضعیت نمایش تعهد تغییر کرد.');
            redirect(app_url('admin/trust-center.php'));
        }

        $title = trim(scalar_string($_POST['title'] ?? ''));
        $summary = trim(scalar_string($_POST['summary'] ?? ''));
        $details = trim(scalar_string($_POST['details'] ?? ''));
        $iconKey = scalar_string($_POST['icon_key'] ?? 'shield', 'shield');
        $sortOrder = max(-10000, min(10000, scalar_int($_POST['sort_order'] ?? 0)));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if (text_length($title) < 2 || text_length($title) > 160) throw new RuntimeException('عنوان تعهد باید بین ۲ تا ۱۶۰ نویسه باشد.');
        if (text_length($summary) < 3 || text_length($summary) > 320 || text_length($details) > 3000) throw new RuntimeException('خلاصه یا توضیح تکمیلی معتبر نیست.');
        if (!isset(trust_icon_catalog()[$iconKey])) throw new RuntimeException('نماد انتخاب‌شده معتبر نیست.');
        if ($id > 0) db()->prepare('UPDATE trust_items SET title=?,summary=?,details=?,icon_key=?,sort_order=?,is_active=? WHERE id=?')->execute([$title,$summary,$details ?: null,$iconKey,$sortOrder,$isActive,$id]);
        else {
            db()->prepare('INSERT INTO trust_items(title,summary,details,icon_key,sort_order,is_active) VALUES(?,?,?,?,?,?)')->execute([$title,$summary,$details ?: null,$iconKey,$sortOrder,$isActive]);
            $id = (int)db()->lastInsertId();
        }
        admin_audit_event('trust_item_saved', 'trust_item', $id, ['active'=>$isActive]);
        flash('success', 'تعهد فروشگاه ذخیره شد.');
        redirect(app_url('admin/trust-center.php?edit=' . $id));
    } catch (Throwable $exception) {
        log_error($exception);
        $errors[] = $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage() : 'ذخیره مرکز اعتماد انجام نشد.';
    }
}

$editId = max(0, scalar_int($_GET['edit'] ?? 0));
$editing = null;
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM trust_items WHERE id=?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}
$editing = $editing ?: ['id'=>0,'title'=>'','summary'=>'','details'=>'','icon_key'=>'shield','sort_order'=>0,'is_active'=>1];
$items = trust_center_items(false);
$iconCatalog = trust_icon_catalog();
$adminPage = 'trust-center';
$adminTitle = 'مرکز اعتماد';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><div><h1>مرکز اعتماد</h1><p class="muted">تعهدهای واقعی فروشگاه را با ترتیب و توضیح کامل مدیریت کنید.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('trust.php'))?>" target="_blank" rel="noopener noreferrer">مشاهده صفحه</a><a class="btn btn-primary" href="<?=e(app_url('admin/trust-center.php'))?>">تعهد جدید</a></div></div>
<?php foreach($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="action" value="settings"><h2>معرفی صفحه</h2><div class="form-grid"><div class="field"><label>عنوان صفحه</label><input name="trust_page_title" maxlength="180" value="<?=e((string)setting('trust_page_title','مرکز اعتماد گلریزان'))?>" required></div><div class="field full"><label>توضیح آغازین</label><textarea name="trust_page_intro" maxlength="500" rows="3"><?=e((string)setting('trust_page_intro','پیش از خرید، تعهدات فروشگاه و مسیرهای پشتیبانی را شفاف ببینید.'))?></textarea></div></div><button class="btn btn-primary">ذخیره معرفی صفحه</button></form>
<div class="two-col trust-admin-layout">
<div class="table-wrap"><table><thead><tr><th>تعهد</th><th>نماد و ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><strong><?=e($item['title'])?></strong><small class="muted"><?=e($item['summary'])?></small></td><td><?=e($iconCatalog[$item['icon_key']] ?? 'ضمانت')?> · <?=fa_number((int)$item['sort_order'])?></td><td><span class="badge <?=$item['is_active']?'green':'red'?>"><?=$item['is_active']?'فعال':'غیرفعال'?></span></td><td><div class="admin-actions"><a class="btn btn-secondary btn-sm" href="?edit=<?=$item['id']?>">ویرایش</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-secondary btn-sm">تغییر وضعیت</button></form><form method="post" data-confirm="این مورد از مرکز اعتماد حذف شود؟"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-danger btn-sm">حذف</button></form></div></td></tr><?php endforeach?><?php if(!$items):?><tr><td colspan="4">هنوز موردی ثبت نشده است.</td></tr><?php endif?></tbody></table></div>
<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_item"><input type="hidden" name="id" value="<?=$editing['id']?>"><h2><?=$editing['id']?'ویرایش تعهد':'تعهد جدید'?></h2><div class="field"><label>عنوان</label><input name="title" maxlength="160" value="<?=e($editing['title'])?>" required></div><div class="field"><label>خلاصه</label><textarea name="summary" maxlength="320" rows="3" required><?=e($editing['summary'])?></textarea></div><div class="field"><label>توضیح کامل</label><textarea name="details" maxlength="3000" rows="5"><?=e($editing['details'])?></textarea></div><div class="field"><label>نماد</label><select name="icon_key"><?php foreach($iconCatalog as $key=>$label):?><option value="<?=e($key)?>" <?=$editing['icon_key']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></div><div class="field"><label>ترتیب</label><input type="number" name="sort_order" min="-10000" max="10000" value="<?=e($editing['sort_order'])?>"></div><label class="checkbox-row"><input type="checkbox" name="is_active" <?=$editing['is_active']?'checked':''?>> نمایش در مرکز اعتماد</label><button class="btn btn-primary">ذخیره تعهد</button></form></div>
<?php require __DIR__ . '/partials/footer.php';
