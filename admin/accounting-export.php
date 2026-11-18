<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$fromInput = scalar_string($_GET['from'] ?? jalali_month_start());
$toInput = scalar_string($_GET['to'] ?? jalali_today());
$from = jalali_parse_date($fromInput);
$to = jalali_parse_date($toInput);
if ($from === null || $to === null || $from > $to) {
    http_response_code(422);
    exit('بازه تاریخ شمسی معتبر نیست. نمونه: ۱۴۰۵/۰۵/۰۹');
}
$stmt = db()->prepare("SELECT e.entry_number,e.entry_date,e.description,a.code,a.name,l.debit,l.credit,l.memo FROM accounting_entries e JOIN accounting_lines l ON l.entry_id=e.id JOIN accounting_accounts a ON a.id=l.account_id WHERE e.status='posted' AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date,e.id,l.id");
$stmt->execute([$from,$to]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=accounting-jalali-'.jalali_compact_date().'.csv');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF";
$safe = static function (mixed $value): string {
    $text = (string)$value;
    return preg_match('/^[=+\-@]/u',$text) ? "'".$text : $text;
};
$out = fopen('php://output','wb');
fputcsv($out,['شماره سند','تاریخ شمسی','شرح','کد حساب','نام حساب','بدهکار','بستانکار','یادداشت']);
while ($row = $stmt->fetch()) {
    $row['entry_date'] = jalali_date_format((string)$row['entry_date']);
    fputcsv($out,array_map($safe,array_values($row)));
}
fclose($out);
exit;
