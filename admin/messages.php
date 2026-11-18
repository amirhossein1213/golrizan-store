<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
if (is_post()) {
    verify_csrf();
    $id = max(0, scalar_int($_POST['id'] ?? 0));
    if ($id) {
        $statement = db()->prepare('UPDATE contact_messages SET is_read=1 WHERE id=? AND is_read=0');
        $statement->execute([$id]);
        if ($statement->rowCount() !== 1) flash('error', 'پیام پیدا نشد یا قبلاً خوانده شده است.');
        else flash('success', 'پیام خوانده‌شده علامت‌گذاری شد.');
    }
    redirect(app_url('admin/messages.php'));
}
$department=scalar_string($_GET['department']??'');if(!in_array($department,['support','sales'],true))$department='';
$page = max(1, min(100000, scalar_int($_GET['page'] ?? 1, 1)));
$perPage = 50;
$countSql='SELECT COUNT(*) FROM contact_messages'.($department!==''?' WHERE department='.db()->quote($department):'');
$total = (int)db()->query($countSql)->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$listSql='SELECT * FROM contact_messages'.($department!==''?' WHERE department='.db()->quote($department):'').' ORDER BY id DESC LIMIT '.$perPage.' OFFSET '.$offset;$messages=db()->query($listSql)->fetchAll();
$adminPage = 'messages';
$adminTitle = 'پیام‌های مشتریان';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><div><h1>پیام‌های مشتریان</h1><span class="muted"><?=fa_number($total)?> پیام</span></div><div class="admin-actions"><a class="btn btn-secondary <?=$department===''?'active':''?>" href="?">همه</a><a class="btn btn-secondary <?=$department==='support'?'active':''?>" href="?department=support">پشتیبانی</a><a class="btn btn-secondary <?=$department==='sales'?'active':''?>" href="?department=sales">فروش</a></div></div><div class="table-wrap"><table><thead><tr><th>واحد مقصد</th><th>فرستنده</th><th>موضوع و پیام</th><th>تاریخ</th><th>وضعیت</th></tr></thead><tbody><?php foreach ($messages as $message): ?><tr><td><span class="badge <?=($message['department']??'support')==='sales'?'orange':'blue'?>"><?=($message['department']??'support')==='sales'?'فروش و مشاوره':'پشتیبانی مشتریان'?></span></td><td><?=e($message['name'])?><br><small><?=e($message['phone'])?></small></td><td><strong><?=e($message['subject'])?></strong><br><?=nl2br(e($message['message']))?></td><td><?=e(jalali_format($message['created_at']))?></td><td><?php if ($message['is_read']): ?>خوانده‌شده<?php else: ?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$message['id']?>"><button class="btn btn-primary">علامت خوانده‌شده</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$messages): ?><tr><td colspan="5">پیامی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
<?php if($pages>1):$start=max(1,$page-2);$end=min($pages,$page+2);?><nav class="pagination"><?php if($start>1):?><a href="?<?=e(http_build_query(array_filter(['department'=>$department,'page'=>1])))?>">۱</a><?php endif?><?php for($i=$start;$i<=$end;$i++):?><a class="<?=$i===$page?'active':''?>" href="?<?=e(http_build_query(array_filter(['department'=>$department,'page'=>$i])))?>"><?=fa_number($i)?></a><?php endfor?><?php if($end<$pages):?><a href="?<?=e(http_build_query(array_filter(['department'=>$department,'page'=>$pages])))?>"><?=fa_number($pages)?></a><?php endif?></nav><?php endif?>
<?php require __DIR__ . '/partials/footer.php';
