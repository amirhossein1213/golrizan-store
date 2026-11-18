<?php
declare(strict_types=1);

function inventory_movement_labels(): array
{
    return ['opening'=>'موجودی اولیه','purchase'=>'خرید','sale'=>'فروش','sale_cancel'=>'لغو فروش','return'=>'مرجوعی سالم','adjustment'=>'اصلاح دستی','damage'=>'آسیب‌دیده','discard'=>'ضایعات'];
}

function inventory_record_movement(PDO $pdo,int $productId,?int $variantId,string $type,int $delta,int $balance,int $unitCost=0,?string $referenceType=null,?int $referenceId=null,string $note='',?int $createdBy=null): int
{
    if(!isset(inventory_movement_labels()[$type]))throw new RuntimeException('نوع گردش موجودی معتبر نیست.');
    $stmt=$pdo->prepare('INSERT INTO inventory_movements(product_id,variant_id,movement_type,quantity_delta,balance_after,unit_cost,reference_type,reference_id,note,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$productId,$variantId,$type,$delta,max(0,$balance),max(0,$unitCost),$referenceType,$referenceId,text_substr(trim($note),0,500)?:null,$createdBy]);return (int)$pdo->lastInsertId();
}

function inventory_record_existing_change(PDO $pdo,int $productId,?int $variantId,string $type,int $delta,int $unitCost=0,?string $referenceType=null,?int $referenceId=null,string $note='',?int $createdBy=null): void
{
    if($variantId){$stmt=$pdo->prepare('SELECT stock FROM product_variants WHERE id=? AND product_id=?');$stmt->execute([$variantId,$productId]);}
    else{$stmt=$pdo->prepare('SELECT stock FROM products WHERE id=?');$stmt->execute([$productId]);}
    $balance=$stmt->fetchColumn();if($balance===false)throw new RuntimeException('کالای گردش موجودی پیدا نشد.');inventory_record_movement($pdo,$productId,$variantId,$type,$delta,(int)$balance,$unitCost,$referenceType,$referenceId,$note,$createdBy);
}

function inventory_apply_delta(PDO $pdo,int $productId,?int $variantId,int $delta,string $type,int $unitCost=0,?string $referenceType=null,?int $referenceId=null,string $note='',?int $createdBy=null,bool $updateCost=false): int
{
    if($delta===0)throw new RuntimeException('تغییر موجودی نمی‌تواند صفر باشد.');
    if($variantId){$stmt=$pdo->prepare('SELECT id,product_id,stock,cost_price FROM product_variants WHERE id=? AND product_id=? FOR UPDATE');$stmt->execute([$variantId,$productId]);$row=$stmt->fetch();}
    else{$stmt=$pdo->prepare("SELECT id,stock,cost_price,product_type FROM products WHERE id=? FOR UPDATE");$stmt->execute([$productId]);$row=$stmt->fetch();if($row&&(string)$row['product_type']==='variable')throw new RuntimeException('برای محصول متغیر باید مدل مشخص شود.');}
    if(!$row)throw new RuntimeException('کالا یا مدل انبار پیدا نشد.');$old=(int)$row['stock'];$new=$old+$delta;if($new<0||$new>1000000)throw new RuntimeException('موجودی جدید خارج از محدوده مجاز است.');$newCost=(int)$row['cost_price'];
    if($updateCost&&$delta>0&&$unitCost>=0){$newCost=$new>0?(int)round((($old*(int)$row['cost_price'])+($delta*$unitCost))/$new):$unitCost;}
    if($variantId){$pdo->prepare('UPDATE product_variants SET stock=?,cost_price=? WHERE id=? AND product_id=?')->execute([$new,$newCost,$variantId,$productId]);sync_product_variant_summary($pdo,$productId);}
    else{$pdo->prepare('UPDATE products SET stock=?,cost_price=? WHERE id=?')->execute([$new,$newCost,$productId]);}
    inventory_record_movement($pdo,$productId,$variantId,$type,$delta,$new,$unitCost,$referenceType,$referenceId,$note,$createdBy);
    if($delta>0)product_alert_wake_for_product($pdo,$productId,$variantId);
    return $new;
}

function purchase_number(): string
{
    return 'PO-'.jalali_compact_date().'-'.strtoupper(bin2hex(random_bytes(4)));
}

function purchase_status_labels(): array
{
    return ['draft'=>'پیش‌نویس','ordered'=>'سفارش‌گذاری‌شده','received'=>'دریافت‌شده','cancelled'=>'لغوشده'];
}

function accounting_purchase_received(PDO $pdo,int $purchaseId,int $total): void
{
    if($total<1)return;accounting_post($pdo,'دریافت خرید شماره '.$purchaseId,[['account_id'=>accounting_account_id($pdo,'inventory'),'debit'=>$total,'credit'=>0],['account_id'=>accounting_account_id($pdo,'accounts_payable'),'debit'=>0,'credit'=>$total]],'purchase:'.$purchaseId.':received','purchase',$purchaseId,date('Y-m-d'),(int)(admin_user()['id']??0));
}

function accounting_supplier_payment(PDO $pdo,int $paymentId,int $amount): void
{
    accounting_post($pdo,'پرداخت به تأمین‌کننده',[['account_id'=>accounting_account_id($pdo,'accounts_payable'),'debit'=>$amount,'credit'=>0],['account_id'=>accounting_account_id($pdo,'cash_bank'),'debit'=>0,'credit'=>$amount]],'supplier-payment:'.$paymentId,'supplier_payment',$paymentId,date('Y-m-d'),(int)(admin_user()['id']??0));
}

function receive_purchase(int $purchaseId): void
{
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM purchase_orders WHERE id=? FOR UPDATE");$stmt->execute([$purchaseId]);$purchase=$stmt->fetch();if(!$purchase)throw new RuntimeException('فاکتور خرید پیدا نشد.');if(!in_array((string)$purchase['status'],['draft','ordered'],true))throw new RuntimeException('این خرید قبلاً دریافت یا لغو شده است.');
        $itemsStmt=$pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_id=? ORDER BY id FOR UPDATE');$itemsStmt->execute([$purchaseId]);$items=$itemsStmt->fetchAll();if(!$items)throw new RuntimeException('فاکتور خرید هیچ قلمی ندارد.');$calculated=0;$updateCost=(string)setting('purchase_update_cost_price','1')==='1';
        foreach($items as $item){$line=(int)$item['line_total'];if($calculated>MAX_MONEY_AMOUNT-$line)throw new RuntimeException('مبلغ خرید بیش از سقف مجاز است.');$calculated+=$line;inventory_apply_delta($pdo,(int)$item['product_id'],$item['variant_id']!==null?(int)$item['variant_id']:null,(int)$item['quantity'],'purchase',(int)$item['unit_cost'],'purchase',$purchaseId,'دریافت از تأمین‌کننده',(int)(admin_user()['id']??0),$updateCost);}
        $shipping=max(0,(int)$purchase['shipping_amount']);if($shipping>MAX_MONEY_AMOUNT-$calculated)throw new RuntimeException('جمع خرید بیش از سقف مجاز است.');$beforeDiscount=$calculated+$shipping;$total=$beforeDiscount-min($beforeDiscount,max(0,(int)$purchase['discount_amount']));
        $pdo->prepare("UPDATE purchase_orders SET status='received',subtotal=?,total=?,received_at=NOW(),received_by=? WHERE id=?")->execute([$calculated,$total,(int)(admin_user()['id']??0),$purchaseId]);accounting_purchase_received($pdo,$purchaseId,$total);$pdo->commit();admin_audit_event('purchase_received','purchase',$purchaseId,['total'=>$total]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function supplier_balance(int $supplierId): int
{
    $p=db()->prepare("SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE supplier_id=? AND status='received'");$p->execute([$supplierId]);$buy=(int)$p->fetchColumn();$s=db()->prepare('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=?');$s->execute([$supplierId]);return $buy-(int)$s->fetchColumn();
}

function create_supplier_payment(int $supplierId,?int $purchaseId,int $amount,string $date,string $method,string $reference,string $note): int
{
    if($supplierId<1||$amount<1||$amount>MAX_MONEY_AMOUNT)throw new RuntimeException('مبلغ پرداخت معتبر نیست.');if(!valid_iso_date($date))throw new RuntimeException('تاریخ پرداخت معتبر نیست.');if(!in_array($method,['cash','card','bank','cheque'],true))throw new RuntimeException('روش پرداخت معتبر نیست.');
    $pdo=db();$pdo->beginTransaction();try{$lock=$pdo->prepare('SELECT id FROM suppliers WHERE id=? FOR UPDATE');$lock->execute([$supplierId]);if(!$lock->fetchColumn())throw new RuntimeException('تأمین‌کننده پیدا نشد.');if($purchaseId!==null){$check=$pdo->prepare("SELECT id FROM purchase_orders WHERE id=? AND supplier_id=? AND status='received'");$check->execute([$purchaseId,$supplierId]);if(!$check->fetchColumn())throw new RuntimeException('فاکتور خرید انتخاب‌شده معتبر نیست.');}$p=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE supplier_id=? AND status='received'");$p->execute([$supplierId]);$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=?');$s->execute([$supplierId]);$balance=(int)$p->fetchColumn()-(int)$s->fetchColumn();if($amount>$balance)throw new RuntimeException('مبلغ پرداخت از مانده بدهی تأمین‌کننده بیشتر است.');$pdo->prepare('INSERT INTO supplier_payments(supplier_id,purchase_id,amount,payment_date,method,reference_id,note,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$supplierId,$purchaseId,$amount,$date,$method,text_substr(trim($reference),0,150)?:null,text_substr(trim($note),0,500)?:null,(int)(admin_user()['id']??0)]);$id=(int)$pdo->lastInsertId();accounting_supplier_payment($pdo,$id,$amount);$pdo->commit();admin_audit_event('supplier_payment_created','supplier',$supplierId,['payment_id'=>$id,'amount'=>$amount]);return $id;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
