<?php
declare(strict_types=1);

function accounting_entry_number(): string { return 'JV-' . jalali_compact_date() . '-' . strtoupper(bin2hex(random_bytes(6))); }

function accounting_account_id(PDO $pdo, string $systemKey): int
{
    $stmt = $pdo->prepare('SELECT id FROM accounting_accounts WHERE system_key=? AND is_active=1 LIMIT 1');
    $stmt->execute([$systemKey]);
    $id = (int)$stmt->fetchColumn();
    if ($id < 1) throw new RuntimeException('حساب سیستمی «' . $systemKey . '» تعریف نشده است. ابتدا ارتقای دیتابیس را اجرا کنید.');
    return $id;
}

function accounting_post(PDO $pdo, string $description, array $lines, ?string $eventKey = null, ?string $referenceType = null, ?int $referenceId = null, ?string $entryDate = null, ?int $createdBy = null): int
{
    if ($eventKey !== null) {
        $check = $pdo->prepare('SELECT id FROM accounting_entries WHERE event_key=? LIMIT 1');
        $check->execute([$eventKey]);
        $existing = (int)$check->fetchColumn();
        if ($existing > 0) return $existing;
    }
    $debit = 0; $credit = 0; $clean = [];
    foreach ($lines as $line) {
        $accountId = (int)($line['account_id'] ?? 0);
        $d = max(0, (int)($line['debit'] ?? 0)); $c = max(0, (int)($line['credit'] ?? 0));
        if ($accountId < 1 || ($d > 0) === ($c > 0) || $d > MAX_MONEY_AMOUNT || $c > MAX_MONEY_AMOUNT) throw new RuntimeException('آرتیکل حسابداری نامعتبر است.');
        if ($debit > MAX_MONEY_AMOUNT-$d || $credit > MAX_MONEY_AMOUNT-$c) throw new RuntimeException('مبلغ سند حسابداری از سقف مجاز بیشتر است.');
        $debit += $d; $credit += $c; $clean[] = ['account_id'=>$accountId,'debit'=>$d,'credit'=>$c,'memo'=>text_substr(trim(scalar_string($line['memo']??'')),0,300)];
    }
    if (!$clean || $debit <= 0 || $debit !== $credit) throw new RuntimeException('سند حسابداری تراز نیست.');
    $date = $entryDate && preg_match('/^\d{4}-\d{2}-\d{2}$/',$entryDate) ? $entryDate : date('Y-m-d');
    $insert = $pdo->prepare('INSERT INTO accounting_entries(entry_number,entry_date,description,event_key,reference_type,reference_id,created_by) VALUES(?,?,?,?,?,?,?)');
    try { $insert->execute([accounting_entry_number(),$date,text_substr($description,0,500),$eventKey,$referenceType,$referenceId,$createdBy]); }
    catch (PDOException $e) {
        if ($eventKey !== null && (int)($e->errorInfo[1]??0)===1062) { $check=$pdo->prepare('SELECT id FROM accounting_entries WHERE event_key=?');$check->execute([$eventKey]);$found=(int)$check->fetchColumn();if($found>0)return $found; }
        throw $e;
    }
    $entryId=(int)$pdo->lastInsertId();
    $lineStmt=$pdo->prepare('INSERT INTO accounting_lines(entry_id,account_id,debit,credit,memo) VALUES(?,?,?,?,?)');
    foreach($clean as $line) $lineStmt->execute([$entryId,$line['account_id'],$line['debit'],$line['credit'],$line['memo']]);
    return $entryId;
}

function accounting_order_created(PDO $pdo, int $orderId, int $subtotal, int $shipping, int $discount=0): void
{
    $discount=max(0,min($subtotal,$discount));$total=$subtotal+$shipping-$discount; if($subtotal+$shipping<=0)return;
    $lines=[];if($total>0)$lines[]=['account_id'=>accounting_account_id($pdo,'accounts_receivable'),'debit'=>$total,'credit'=>0,'memo'=>'مطالبات سفارش'];$lines[]=['account_id'=>accounting_account_id($pdo,'sales_revenue'),'debit'=>0,'credit'=>$subtotal,'memo'=>'فروش کالا'];
    if($discount>0)$lines[]=['account_id'=>accounting_account_id($pdo,'sales_discounts'),'debit'=>$discount,'credit'=>0,'memo'=>'تخفیف کد خرید'];
    if($shipping>0)$lines[]=['account_id'=>accounting_account_id($pdo,'shipping_revenue'),'debit'=>0,'credit'=>$shipping,'memo'=>'درآمد ارسال'];
    accounting_post($pdo,'ثبت فروش سفارش شماره '.$orderId,$lines,'order:'.$orderId.':created','order',$orderId);
}

function accounting_event_exists(PDO $pdo,string $eventKey): bool
{
    $stmt=$pdo->prepare('SELECT id FROM accounting_entries WHERE event_key=? LIMIT 1');$stmt->execute([$eventKey]);return (bool)$stmt->fetchColumn();
}

function accounting_ensure_order_sale(PDO $pdo,int $orderId): void
{
    if(accounting_event_exists($pdo,'order:'.$orderId.':created'))return;
    $stmt=$pdo->prepare('SELECT subtotal,shipping_amount,discount_amount FROM orders WHERE id=? LIMIT 1');$stmt->execute([$orderId]);$order=$stmt->fetch();
    if(!$order)throw new RuntimeException('سفارش حسابداری پیدا نشد.');
    accounting_order_created($pdo,$orderId,(int)$order['subtotal'],(int)$order['shipping_amount'],(int)$order['discount_amount']);
}

function accounting_order_paid(PDO $pdo, int $orderId, int $total): void
{
    if($total<1)return;
    accounting_post($pdo,'دریافت وجه سفارش شماره '.$orderId,[['account_id'=>accounting_account_id($pdo,'cash_bank'),'debit'=>$total,'credit'=>0],['account_id'=>accounting_account_id($pdo,'accounts_receivable'),'debit'=>0,'credit'=>$total]],'order:'.$orderId.':paid','order',$orderId);
}

function accounting_cancelled_order_late_payment(PDO $pdo,int $orderId,int $total): void
{
    if($total<1)return;
    accounting_post($pdo,'دریافت وجه پس از لغو سفارش شماره '.$orderId,[
        ['account_id'=>accounting_account_id($pdo,'cash_bank'),'debit'=>$total,'credit'=>0],
        ['account_id'=>accounting_account_id($pdo,'customer_refunds_payable'),'debit'=>0,'credit'=>$total],
    ],'order:'.$orderId.':late-paid-after-cancel','order',$orderId);
}

function accounting_order_delivered(PDO $pdo, int $orderId): void
{
    $stmt=$pdo->prepare('SELECT COALESCE(SUM(line_cost),0) FROM order_items WHERE order_id=?');$stmt->execute([$orderId]);$cost=(int)$stmt->fetchColumn();
    if($cost<=0)return;
    accounting_post($pdo,'ثبت بهای تمام‌شده سفارش شماره '.$orderId,[['account_id'=>accounting_account_id($pdo,'cost_of_goods_sold'),'debit'=>$cost,'credit'=>0],['account_id'=>accounting_account_id($pdo,'inventory'),'debit'=>0,'credit'=>$cost]],'order:'.$orderId.':delivered-cogs','order',$orderId);
}

function accounting_order_cancelled(PDO $pdo, int $orderId, int $subtotal, int $shipping, bool $wasPaid, int $discount=0): void
{
    if(!accounting_event_exists($pdo,'order:'.$orderId.':created'))return;
    $discount=max(0,min($subtotal,$discount));$total=$subtotal+$shipping-$discount;if($subtotal+$shipping<=0)return;
    $creditAccount=$wasPaid?'customer_refunds_payable':'accounts_receivable';
    $lines=[['account_id'=>accounting_account_id($pdo,'sales_returns'),'debit'=>$subtotal,'credit'=>0,'memo'=>'برگشت از فروش']];
    if($shipping>0)$lines[]=['account_id'=>accounting_account_id($pdo,'shipping_revenue'),'debit'=>$shipping,'credit'=>0,'memo'=>'برگشت هزینه ارسال'];
    if($discount>0)$lines[]=['account_id'=>accounting_account_id($pdo,'sales_discounts'),'debit'=>0,'credit'=>$discount,'memo'=>'برگشت تخفیف فروش'];
    if($total>0)$lines[]=['account_id'=>accounting_account_id($pdo,$creditAccount),'debit'=>0,'credit'=>$total,'memo'=>$wasPaid?'بدهی بازپرداخت به مشتری':'بستن حساب دریافتنی'];
    accounting_post($pdo,'لغو سفارش شماره '.$orderId,$lines,'order:'.$orderId.':cancelled','order',$orderId);
}

function accounting_order_refunded(PDO $pdo, int $orderId, int $total): void
{
    accounting_post($pdo,'بازپرداخت سفارش شماره '.$orderId,[['account_id'=>accounting_account_id($pdo,'customer_refunds_payable'),'debit'=>$total,'credit'=>0],['account_id'=>accounting_account_id($pdo,'cash_bank'),'debit'=>0,'credit'=>$total]],'order:'.$orderId.':refunded','order',$orderId);
}

function accounting_inventory_adjustment(PDO $pdo,int $productId,int $oldValue,int $newValue,?int $createdBy=null): void
{
    $difference=$newValue-$oldValue;if($difference===0)return;
    if($difference>0){$lines=[['account_id'=>accounting_account_id($pdo,'inventory'),'debit'=>$difference,'credit'=>0],['account_id'=>accounting_account_id($pdo,'owner_equity'),'debit'=>0,'credit'=>$difference]];$description='افزایش ارزش موجودی محصول '.$productId;}
    else{$amount=abs($difference);$lines=[['account_id'=>accounting_account_id($pdo,'inventory_adjustment'),'debit'=>$amount,'credit'=>0],['account_id'=>accounting_account_id($pdo,'inventory'),'debit'=>0,'credit'=>$amount]];$description='کاهش ارزش موجودی محصول '.$productId;}
    accounting_post($pdo,$description,$lines,null,'product',$productId,date('Y-m-d'),$createdBy);
}

function accounting_summary(string $from, string $to): array
{
    $sql="SELECT a.account_type,a.normal_balance,a.system_key,SUM(l.debit) debit,SUM(l.credit) credit FROM accounting_lines l JOIN accounting_entries e ON e.id=l.entry_id AND e.status='posted' JOIN accounting_accounts a ON a.id=l.account_id WHERE e.entry_date BETWEEN ? AND ? GROUP BY a.id,a.account_type,a.normal_balance,a.system_key";
    $stmt=db()->prepare($sql);$stmt->execute([$from,$to]);$map=[];
    foreach($stmt->fetchAll() as $row){$d=(int)$row['debit'];$c=(int)$row['credit'];$map[$row['system_key']??('account-'.$row['account_type'])]=['debit'=>$d,'credit'=>$c,'balance'=>$row['normal_balance']==='debit'?$d-$c:$c-$d];}
    $value=fn(string $key):int=>(int)($map[$key]['balance']??0);
    $debit=fn(string $key):int=>(int)($map[$key]['debit']??0);
    $credit=fn(string $key):int=>(int)($map[$key]['credit']??0);
    $revenue=max(0,$credit('sales_revenue')+$credit('shipping_revenue'));
    // بدهکارشدن حساب درآمد و حساب برگشت از فروش، هر دو به‌عنوان برگشت دوره گزارش می‌شوند.
    $returns=max(0,$debit('sales_revenue')+$debit('shipping_revenue')+$value('sales_returns'));
    $discounts=max(0,$value('sales_discounts'));
    $expenses=max(0,$value('cost_of_goods_sold')+$value('shipping_expense')+$value('general_expense')+$value('inventory_adjustment'));
    $balanceStmt=db()->prepare("SELECT a.system_key,a.normal_balance,SUM(l.debit) debit,SUM(l.credit) credit FROM accounting_lines l JOIN accounting_entries e ON e.id=l.entry_id AND e.status='posted' JOIN accounting_accounts a ON a.id=l.account_id WHERE e.entry_date<=? AND a.system_key IN ('cash_bank','accounts_receivable','customer_refunds_payable','accounts_payable','inventory') GROUP BY a.id,a.system_key,a.normal_balance");
    $balanceStmt->execute([$to]);$balances=[];foreach($balanceStmt->fetchAll() as $row){$d=(int)$row['debit'];$c=(int)$row['credit'];$balances[$row['system_key']]=$row['normal_balance']==='debit'?$d-$c:$c-$d;}
    $festivalStmt=db()->prepare("SELECT COALESCE(SUM(oi.festival_saving*oi.quantity),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.order_status<>'cancelled'");
    $festivalStmt->execute([$from,$to]);
    $festivalSaving=(int)$festivalStmt->fetchColumn();
    return ['revenue'=>$revenue,'returns'=>$returns,'discounts'=>$discounts,'expenses'=>$expenses,'net'=>$revenue-$returns-$discounts-$expenses,'festival_saving'=>$festivalSaving,'cash'=>(int)($balances['cash_bank']??0),'receivable'=>(int)($balances['accounts_receivable']??0),'refund_payable'=>(int)($balances['customer_refunds_payable']??0),'supplier_payable'=>(int)($balances['accounts_payable']??0),'inventory'=>(int)($balances['inventory']??0)];
}
