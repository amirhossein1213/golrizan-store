<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$errors = [];
$data = [
    'title'=>'','category'=>'','expense_date'=>jalali_today(),'amount'=>'','payment_account_id'=>'','expense_account_id'=>'','note'=>''
];

if (is_post()) {
    verify_csrf();
    foreach (array_keys($data) as $key) $data[$key] = trim(scalar_string($_POST[$key] ?? $data[$key]));
    $title = $data['title'];
    $category = $data['category'];
    $dateInput = $data['expense_date'];
    $date = jalali_parse_date($dateInput);
    $amountValid = false;
    $amount = bounded_uint($data['amount'], MAX_MONEY_AMOUNT, $amountValid);
    $note = $data['note'];
    $payment = max(0, scalar_int($data['payment_account_id']));
    $expense = max(0, scalar_int($data['expense_account_id']));

    if (text_length($title) < 2 || text_length($title) > 200) $errors[] = 'عنوان هزینه معتبر نیست.';
    if (text_length($category) < 2 || text_length($category) > 120) $errors[] = 'دسته هزینه معتبر نیست.';
    if (!$amountValid || $amount <= 0) $errors[] = 'مبلغ هزینه معتبر نیست.';
    if ($date === null) $errors[] = 'تاریخ شمسی معتبر نیست؛ مانند ۱۴۰۵/۰۵/۰۹ وارد کنید.';
    if (text_length($note) > 3000) $errors[] = 'توضیحات بیش از حد طولانی است.';

    if (!$errors) {
        $accountCheck = db()->prepare('SELECT id,account_type,system_key FROM accounting_accounts WHERE id IN (?,?) AND is_active=1');
        $accountCheck->execute([$payment,$expense]);
        $accounts = [];
        foreach ($accountCheck->fetchAll() as $row) $accounts[(int)$row['id']] = $row;
        if (($accounts[$payment]['system_key'] ?? '') !== 'cash_bank') $errors[] = 'در این نسخه، پرداخت هزینه فقط از حساب بانک و صندوق مجاز است.';
        if (($accounts[$expense]['account_type'] ?? '') !== 'expense') $errors[] = 'حساب هزینه باید از نوع هزینه باشد.';
        if ($payment === $expense) $errors[] = 'حساب پرداخت و حساب هزینه نمی‌توانند یکسان باشند.';
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $entry = accounting_post($pdo, 'هزینه: ' . $title, [
                ['account_id'=>$expense,'debit'=>$amount,'credit'=>0,'memo'=>$category],
                ['account_id'=>$payment,'debit'=>0,'credit'=>$amount,'memo'=>'پرداخت هزینه'],
            ], null, 'expense', null, $date, (int)admin_user()['id']);
            $stmt = $pdo->prepare('INSERT INTO accounting_expenses(title,category,amount,expense_date,payment_account_id,expense_account_id,note,entry_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$title,$category,$amount,$date,$payment,$expense,$note,$entry,admin_user()['id']]);
            $expenseId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE accounting_entries SET reference_id=? WHERE id=?')->execute([$expenseId,$entry]);
            $pdo->commit();
            flash('success', 'هزینه و سند حسابداری ثبت شد.');
            redirect(app_url('admin/accounting.php'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_error($e);
            $errors[] = 'ثبت هزینه انجام نشد؛ حساب‌ها و مبالغ را بررسی کنید.';
        }
    }
}

$paymentAccounts = db()->query("SELECT * FROM accounting_accounts WHERE is_active=1 AND system_key='cash_bank' ORDER BY code")->fetchAll();
$expenseAccounts = db()->query("SELECT * FROM accounting_accounts WHERE is_active=1 AND account_type='expense' ORDER BY code")->fetchAll();
$adminPage = 'accounting';
$adminTitle = 'ثبت هزینه';
require __DIR__ . '/partials/header.php';
?>
<div class="admin-title"><h1>ثبت هزینه</h1><a class="btn btn-secondary" href="<?=e(app_url('admin/accounting.php'))?>">بازگشت</a></div>
<?php foreach ($errors as $error): ?><div class="flash error"><?=e($error)?></div><?php endforeach ?>
<form class="card" method="post"><?=csrf_field()?>
<div class="form-grid">
    <div class="field full"><label>عنوان هزینه</label><input name="title" maxlength="200" value="<?=e($data['title'])?>" required></div>
    <div class="field"><label>دسته هزینه</label><input name="category" maxlength="120" value="<?=e($data['category'])?>" placeholder="اجاره، حمل، تبلیغات، حقوق و ..." required></div>
    <div class="field"><label>تاریخ</label><input type="text" name="expense_date" value="<?=e($data['expense_date'])?>" data-jalali-date placeholder="۱۴۰۵/۰۵/۰۹" inputmode="numeric" autocomplete="off" required></div>
    <div class="field"><label>مبلغ (تومان)</label><input type="number" name="amount" min="1" max="100000000000000" value="<?=e($data['amount'])?>" required></div>
    <div class="field"><label>پرداخت از حساب</label><select name="payment_account_id" required><?php foreach ($paymentAccounts as $account): ?><option value="<?=$account['id']?>" <?=$data['payment_account_id']==$account['id']?'selected':''?>><?=e($account['code'].' - '.$account['name'])?></option><?php endforeach ?></select></div>
    <div class="field"><label>ثبت در حساب هزینه</label><select name="expense_account_id" required><?php foreach ($expenseAccounts as $account): ?><option value="<?=$account['id']?>" <?=$data['expense_account_id']==$account['id']?'selected':''?>><?=e($account['code'].' - '.$account['name'])?></option><?php endforeach ?></select></div>
    <div class="field full"><label>توضیحات</label><textarea name="note" rows="4" maxlength="3000"><?=e($data['note'])?></textarea></div>
</div><button class="btn btn-primary">ثبت هزینه</button></form>
<?php require __DIR__ . '/partials/footer.php';
