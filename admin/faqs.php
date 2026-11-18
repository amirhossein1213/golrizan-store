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
            $title = trim(scalar_string($_POST['faq_page_title'] ?? ''));
            $intro = trim(scalar_string($_POST['faq_page_intro'] ?? ''));
            if (text_length($title) < 2 || text_length($title) > 180 || text_length($intro) > 500) throw new RuntimeException('عنوان یا توضیح صفحه پرسش‌ها معتبر نیست.');
            $stmt = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $stmt->execute(['faq_page_title',$title]);
            $stmt->execute(['faq_page_intro',$intro]);
            admin_audit_event('faq_settings_saved');
            flash('success', 'عنوان و توضیح پرسش‌های متداول ذخیره شد.');
            redirect(app_url('admin/faqs.php'));
        }
        if ($action === 'delete') {
            db()->prepare('DELETE FROM faqs WHERE id=?')->execute([$id]);
            admin_audit_event('faq_deleted', 'faq', $id);
            flash('success', 'پرسش حذف شد.');
            redirect(app_url('admin/faqs.php'));
        }
        if ($action === 'toggle') {
            db()->prepare('UPDATE faqs SET is_active=1-is_active WHERE id=?')->execute([$id]);
            admin_audit_event('faq_toggled', 'faq', $id);
            flash('success', 'وضعیت نمایش پرسش تغییر کرد.');
            redirect(app_url('admin/faqs.php'));
        }
        $categoryKey = scalar_string($_POST['category_key'] ?? 'purchase', 'purchase');
        $question = trim(scalar_string($_POST['question'] ?? ''));
        $answer = trim(scalar_string($_POST['answer'] ?? ''));
        $sortOrder = max(-10000, min(10000, scalar_int($_POST['sort_order'] ?? 0)));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if (!isset(faq_category_catalog()[$categoryKey])) throw new RuntimeException('دسته پرسش معتبر نیست.');
        if (text_length($question) < 5 || text_length($question) > 255) throw new RuntimeException('متن پرسش باید بین ۵ تا ۲۵۵ نویسه باشد.');
        if (text_length($answer) < 5 || text_length($answer) > 5000) throw new RuntimeException('پاسخ باید بین ۵ تا ۵۰۰۰ نویسه باشد.');
        if ($id > 0) db()->prepare('UPDATE faqs SET category_key=?,question=?,answer=?,sort_order=?,is_active=? WHERE id=?')->execute([$categoryKey,$question,$answer,$sortOrder,$isActive,$id]);
        else {
            db()->prepare('INSERT INTO faqs(category_key,question,answer,sort_order,is_active) VALUES(?,?,?,?,?)')->execute([$categoryKey,$question,$answer,$sortOrder,$isActive]);
            $id = (int)db()->lastInsertId();
        }
        admin_audit_event('faq_saved', 'faq', $id, ['category'=>$categoryKey,'active'=>$isActive]);
        flash('success', 'پرسش و پاسخ ذخیره شد.');
        redirect(app_url('admin/faqs.php?edit=' . $id));
    } catch (Throwable $exception) {
        log_error($exception);
        $errors[] = $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage() : 'ذخیره پرسش انجام نشد.';
    }
}

$editId = max(0, scalar_int($_GET['edit'] ?? 0));
$editing = null;
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM faqs WHERE id=?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}
$editing = $editing ?: ['id'=>0,'category_key'=>'purchase','question'=>'','answer'=>'','sort_order'=>0,'is_active'=>1];
$items = db()->query('SELECT * FROM faqs ORDER BY category_key,sort_order,id')->fetchAll();
$categoryCatalog = faq_category_catalog();
$adminPage = 'faqs';
$adminTitle = 'پرسش‌های متداول';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><div><h1>پرسش‌های متداول</h1><p class="muted">پرسش‌ها را دسته‌بندی کنید؛ موارد فعال همراه دادهٔ ساختاریافته سئو نمایش داده می‌شوند.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('faq.php'))?>" target="_blank" rel="noopener noreferrer">مشاهده صفحه</a><a class="btn btn-primary" href="<?=e(app_url('admin/faqs.php'))?>">پرسش جدید</a></div></div>
<?php foreach($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="action" value="settings"><h2>معرفی صفحه</h2><div class="form-grid"><div class="field"><label>عنوان صفحه</label><input name="faq_page_title" maxlength="180" value="<?=e((string)setting('faq_page_title','پرسش‌های متداول'))?>" required></div><div class="field full"><label>توضیح آغازین</label><textarea name="faq_page_intro" maxlength="500" rows="3"><?=e((string)setting('faq_page_intro','پاسخ پرسش‌های رایج درباره خرید، پرداخت، ارسال، گارانتی و حساب کاربری.'))?></textarea></div></div><button class="btn btn-primary">ذخیره معرفی صفحه</button></form>
<div class="two-col faq-admin-layout"><div class="table-wrap"><table><thead><tr><th>پرسش</th><th>دسته و ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><strong><?=e($item['question'])?></strong><small class="muted"><?=e(text_substr((string)$item['answer'],0,110))?></small></td><td><?=e($categoryCatalog[$item['category_key']] ?? 'سایر')?> · <?=fa_number((int)$item['sort_order'])?></td><td><span class="badge <?=$item['is_active']?'green':'red'?>"><?=$item['is_active']?'فعال':'غیرفعال'?></span></td><td><div class="admin-actions"><a class="btn btn-secondary btn-sm" href="?edit=<?=$item['id']?>">ویرایش</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-secondary btn-sm">تغییر وضعیت</button></form><form method="post" data-confirm="این پرسش حذف شود؟"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-danger btn-sm">حذف</button></form></div></td></tr><?php endforeach?><?php if(!$items):?><tr><td colspan="4">هنوز پرسشی ثبت نشده است.</td></tr><?php endif?></tbody></table></div>
<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_item"><input type="hidden" name="id" value="<?=$editing['id']?>"><h2><?=$editing['id']?'ویرایش پرسش':'پرسش جدید'?></h2><div class="field"><label>دسته</label><select name="category_key"><?php foreach($categoryCatalog as $key=>$label):?><option value="<?=e($key)?>" <?=$editing['category_key']===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select></div><div class="field"><label>پرسش</label><input name="question" maxlength="255" value="<?=e($editing['question'])?>" required></div><div class="field"><label>پاسخ</label><textarea name="answer" maxlength="5000" rows="7" required><?=e($editing['answer'])?></textarea></div><div class="field"><label>ترتیب</label><input type="number" name="sort_order" min="-10000" max="10000" value="<?=e($editing['sort_order'])?>"></div><label class="checkbox-row"><input type="checkbox" name="is_active" <?=$editing['is_active']?'checked':''?>> نمایش در صفحه عمومی</label><button class="btn btn-primary">ذخیره پرسش و پاسخ</button></form></div>
<?php require __DIR__ . '/partials/footer.php';
