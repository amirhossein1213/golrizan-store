<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$fromInput = scalar_string($_GET['from'] ?? jalali_month_start());
$toInput = scalar_string($_GET['to'] ?? jalali_today());
$from = jalali_parse_date($fromInput) ?? jalali_month_start_gregorian();
$to = jalali_parse_date($toInput) ?? date('Y-m-d');
if ($from > $to) {
    [$from,$to] = [$to,$from];
    [$fromInput,$toInput] = [jalali_date_format($from),jalali_date_format($to)];
} else {
    $fromInput = jalali_date_format($from);
    $toInput = jalali_date_format($to);
}

$summary = accounting_summary($from,$to);
$stmt = db()->prepare("SELECT e.*,COALESCE(SUM(l.debit),0) total_debit FROM accounting_entries e LEFT JOIN accounting_lines l ON l.entry_id=e.id WHERE e.status='posted' AND e.entry_date BETWEEN ? AND ? GROUP BY e.id ORDER BY e.entry_date DESC,e.id DESC LIMIT 200");
$stmt->execute([$from,$to]);
$entries = $stmt->fetchAll();
$adminPage = 'accounting';
$adminTitle = 'حسابداری';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><h1>حسابداری فروشگاه</h1><div class="actions"><a class="btn btn-primary" href="<?=e(app_url('admin/expense-edit.php'))?>">ثبت هزینه</a><a class="btn btn-secondary" href="<?=e(app_url('admin/accounting-export.php?from='.rawurlencode($fromInput).'&to='.rawurlencode($toInput)))?>">خروجی CSV</a></div></div>
<form class="filters-admin"><input type="text" name="from" value="<?=e($fromInput)?>" data-jalali-date placeholder="۱۴۰۵/۰۱/۰۱" inputmode="numeric" autocomplete="off"><input type="text" name="to" value="<?=e($toInput)?>" data-jalali-date placeholder="۱۴۰۵/۱۲/۲۹" inputmode="numeric" autocomplete="off"><button class="btn btn-secondary">نمایش</button></form>
<div class="stats">
    <div class="card stat"><span>درآمد ناخالص</span><strong><?=fa_number(format_price($summary['revenue']))?></strong><small>تومان</small></div>
    <div class="card stat"><span>برگشت از فروش</span><strong><?=fa_number(format_price($summary['returns']))?></strong><small>تومان</small></div>
    <div class="card stat"><span>هزینه و بهای تمام‌شده</span><strong><?=fa_number(format_price($summary['expenses']))?></strong><small>تومان</small></div>
    <div class="card stat"><span>سود/زیان دوره</span><strong class="<?=$summary['net']<0?'negative':'positive'?>"><?=fa_number(format_price($summary['net']))?></strong><small>تومان</small></div>
    <div class="card stat"><span>ارزش جشنواره‌های سفارش‌ها</span><strong><?=fa_number(format_price($summary['festival_saving']))?></strong><small>تومان</small></div>
</div>
<div class="stats">
    <div class="card stat"><span>مانده بانک و صندوق</span><strong><?=fa_number(format_price($summary['cash']))?></strong><small>تومان</small></div>
    <div class="card stat"><span>مطالبات مشتریان</span><strong><?=fa_number(format_price($summary['receivable']))?></strong><small>تومان</small></div>
    <div class="card stat"><span>بدهی بازپرداخت</span><strong><?=fa_number(format_price($summary['refund_payable']))?></strong><small>تومان</small></div>
</div>
<div class="card"><h2>دفتر روزنامه</h2><p class="muted">اسناد فروش، دریافت وجه، تحویل کالا، لغو، بازپرداخت و هزینه‌ها به‌صورت خودکار یا دستی ثبت می‌شوند. همه تاریخ‌های نمایشی شمسی هستند.</p></div>
<div class="table-wrap"><table><thead><tr><th>شماره سند</th><th>تاریخ</th><th>شرح</th><th>مرجع</th><th>مبلغ سند</th></tr></thead><tbody>
<?php foreach ($entries as $entry): ?><tr><td><?=e($entry['entry_number'])?></td><td><?=e(jalali_date_format($entry['entry_date']))?></td><td><?=e($entry['description'])?></td><td><?=e(($entry['reference_type']??'-').' '.($entry['reference_id']??''))?></td><td><?=fa_number(format_price($entry['total_debit']))?></td></tr><?php endforeach ?>
<?php if (!$entries): ?><tr><td colspan="5">سندی در این بازه ثبت نشده است.</td></tr><?php endif ?>
</tbody></table></div>
<?php require __DIR__ . '/partials/footer.php';
