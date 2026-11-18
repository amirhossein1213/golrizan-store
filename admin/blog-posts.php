<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

if (is_post()) {
    verify_csrf();
    $id = max(0, scalar_int($_POST['id'] ?? 0));
    $action = scalar_string($_POST['action'] ?? '');
    try {
        $stmt = db()->prepare('SELECT id,status,cover_image FROM blog_posts WHERE id=?');
        $stmt->execute([$id]);
        $post = $stmt->fetch();
        if (!$post) throw new RuntimeException('نوشته پیدا نشد.');
        if ($action === 'publish') {
            db()->prepare("UPDATE blog_posts SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=?")->execute([$id]);
            flash('success', 'نوشته منتشر شد.');
        } elseif ($action === 'archive') {
            db()->prepare("UPDATE blog_posts SET status='archived' WHERE id=?")->execute([$id]);
            flash('success', 'نوشته بایگانی شد.');
        } elseif ($action === 'delete') {
            if ($post['status'] === 'published') throw new RuntimeException('برای حذف، ابتدا نوشته منتشرشده را بایگانی کنید.');
            db()->prepare('DELETE FROM blog_posts WHERE id=?')->execute([$id]);
            delete_blog_upload((string)$post['cover_image']);
            flash('success', 'نوشته حذف شد.');
        }
    } catch (Throwable $exception) {
        log_error($exception);
        flash('error', $exception->getMessage());
    }
    redirect(app_url('admin/blog-posts.php'));
}

$status = scalar_string($_GET['status'] ?? '');
if (!array_key_exists($status, blog_status_labels())) $status = '';
$query = text_substr(trim(scalar_string($_GET['q'] ?? '')), 0, 100);
$where = ['1=1']; $params = [];
if ($status !== '') { $where[] = 'bp.status=?'; $params[] = $status; }
if ($query !== '') { $where[] = '(bp.title LIKE ? OR bp.slug LIKE ? OR bp.content LIKE ?)'; $needle = '%' . $query . '%'; array_push($params,$needle,$needle,$needle); }
$stmt = db()->prepare('SELECT bp.*,bc.name category_name,a.full_name author_name FROM blog_posts bp JOIN blog_categories bc ON bc.id=bp.category_id LEFT JOIN admins a ON a.id=bp.author_id WHERE ' . implode(' AND ', $where) . ' ORDER BY bp.id DESC LIMIT 500');
$stmt->execute($params); $posts = $stmt->fetchAll();
$stats = db()->query("SELECT COUNT(*) total,SUM(status='published') published,SUM(status='draft') drafts,SUM(status='archived') archived FROM blog_posts")->fetch() ?: [];
$adminPage = 'blog'; $adminTitle = 'وبلاگ و محتوا';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><div><h1>وبلاگ و محتوا</h1><p class="muted">نوشته‌های آموزشی و راهنمای خرید را بنویسید، برای سئو آماده کنید و منتشر کنید.</p></div><div class="admin-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/blog-categories.php'))?>">دسته‌بندی‌ها</a><a class="btn btn-primary" href="<?=e(app_url('admin/blog-edit.php'))?>">نوشته جدید</a></div></div>
<div class="stats-grid"><div class="stat-card"><small>همه نوشته‌ها</small><strong><?=fa_number((int)($stats['total']??0))?></strong></div><div class="stat-card"><small>منتشرشده</small><strong><?=fa_number((int)($stats['published']??0))?></strong></div><div class="stat-card"><small>پیش‌نویس</small><strong><?=fa_number((int)($stats['drafts']??0))?></strong></div><div class="stat-card"><small>بایگانی</small><strong><?=fa_number((int)($stats['archived']??0))?></strong></div></div>
<form class="filters-admin" method="get"><input name="q" value="<?=e($query)?>" placeholder="جست‌وجوی عنوان یا متن"><select name="status"><option value="">همه وضعیت‌ها</option><?php foreach(blog_status_labels() as $key=>$label):?><option value="<?=$key?>" <?=$status===$key?'selected':''?>><?=e($label)?></option><?php endforeach?></select><button class="btn btn-secondary">اعمال فیلتر</button></form>
<div class="table-wrap"><table><thead><tr><th>نوشته</th><th>دسته</th><th>وضعیت</th><th>انتشار</th><th>بازدید</th><th>عملیات</th></tr></thead><tbody><?php foreach($posts as $post):?><tr><td><strong><?=e($post['title'])?></strong><?php if($post['is_featured']):?> <span class="badge green">ویژه</span><?php endif?><br><small dir="ltr"><?=e($post['slug'])?></small></td><td><?=e($post['category_name'])?></td><td><?=e(blog_status_labels()[$post['status']]??$post['status'])?></td><td><?=e($post['published_at']?jalali_format($post['published_at']):'—')?></td><td><?=fa_number((int)$post['views_count'])?></td><td><div class="admin-actions"><a class="btn btn-secondary btn-sm" href="<?=e(app_url('admin/blog-edit.php?id='.(int)$post['id']))?>">ویرایش</a><?php if($post['status']==='published'):?><a class="btn btn-secondary btn-sm" target="_blank" rel="noopener" href="<?=e(blog_post_url($post))?>">مشاهده</a><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$post['id']?>"><input type="hidden" name="action" value="archive"><button class="btn btn-secondary btn-sm">بایگانی</button></form><?php else:?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$post['id']?>"><input type="hidden" name="action" value="publish"><button class="btn btn-primary btn-sm">انتشار</button></form><form method="post" data-confirm="این نوشته برای همیشه حذف شود؟"><?=csrf_field()?><input type="hidden" name="id" value="<?=$post['id']?>"><input type="hidden" name="action" value="delete"><button class="btn btn-danger btn-sm">حذف</button></form><?php endif?></div></td></tr><?php endforeach?><?php if(!$posts):?><tr><td colspan="6">نوشته‌ای مطابق این فیلتر پیدا نشد.</td></tr><?php endif?></tbody></table></div>
<?php require __DIR__ . '/partials/footer.php';
