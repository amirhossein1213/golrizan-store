<?php
declare(strict_types=1);

function normalize_coupon_code(string $code): string
{
    return strtoupper(text_substr(preg_replace('/\s+/u','',trim(normalize_digits($code)))??'',0,60));
}

function coupon_quote(PDO $pdo,string $code,int $subtotal,?int $customerId=null,string $phone='',bool $forUpdate=false): array
{
    $code=normalize_coupon_code($code);
    if((string)setting('coupons_enabled','1')!=='1'||$code==='')return ['valid'=>false,'code'=>$code,'discount'=>0,'message'=>$code===''?'':'کد تخفیف فعال نیست.'];
    $stmt=$pdo->prepare("SELECT * FROM coupons WHERE code=? LIMIT 1".($forUpdate?' FOR UPDATE':''));$stmt->execute([$code]);$coupon=$stmt->fetch();
    if(!$coupon||(int)$coupon['is_active']!==1)return ['valid'=>false,'code'=>$code,'discount'=>0,'message'=>'کد تخفیف معتبر یا فعال نیست.'];
    $now=time();if($coupon['starts_at']&&strtotime((string)$coupon['starts_at'])>$now)return ['valid'=>false,'code'=>$code,'discount'=>0,'message'=>'زمان استفاده از این کد هنوز شروع نشده است.'];
    if($coupon['ends_at']&&strtotime((string)$coupon['ends_at'])<$now)return ['valid'=>false,'code'=>$code,'discount'=>0,'message'=>'مهلت استفاده از این کد تمام شده است.'];
    if($subtotal<(int)$coupon['minimum_order'])return ['valid'=>false,'code'=>$code,'discount'=>0,'message'=>'حداقل مبلغ سفارش برای این کد '.format_price((int)$coupon['minimum_order']).' تومان است.'];
    if($coupon['usage_limit']!==null){$count=$pdo->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id=?');$count->execute([$coupon['id']]);if((int)$count->fetchColumn()>=(int)$coupon['usage_limit'])return ['valid'=>false,'code'=>$code,'discount'=>0,'message'=>'ظرفیت استفاده از این کد تکمیل شده است.'];}
    $phone=normalize_phone($phone);$usageSql=$customerId?'customer_id=?':'phone=?';$usageValue=$customerId?:$phone;
    if($usageValue){$used=$pdo->prepare('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id=? AND '.$usageSql);$used->execute([$coupon['id'],$usageValue]);if((int)$used->fetchColumn()>=(int)$coupon['per_customer_limit'])return ['valid'=>false,'code'=>$code,'discount'=>0,'message'=>'سقف استفاده شما از این کد تکمیل شده است.'];}
    if($coupon['discount_type']==='percent'){$percent=max(0,min(100,(int)$coupon['discount_value']));$discount=(int)floor($subtotal*$percent/100);}
    else $discount=(int)$coupon['discount_value'];
    if($coupon['maximum_discount']!==null)$discount=min($discount,(int)$coupon['maximum_discount']);$discount=max(0,min($subtotal,$discount));
    return ['valid'=>$discount>0,'id'=>(int)$coupon['id'],'code'=>$code,'title'=>(string)$coupon['title'],'discount'=>$discount,'message'=>$discount>0?'کد تخفیف اعمال شد.':'این کد برای سبد فعلی تخفیفی ندارد.'];
}

function valid_cart_token(string $token): string
{
    $token=strtolower(trim($token));return preg_match('/^[a-f0-9-]{32,64}$/',$token)?$token:'';
}

function track_abandoned_cart(string $token,array $rawItems,int $subtotal,string $couponCode=''): void
{
    if((string)setting('abandoned_cart_enabled','1')!=='1'||$token==='')return;
    $customer=customer_user();$customerId=$customer?(int)$customer['id']:null;$phone=$customer?(string)$customer['phone']:null;
    if(!$rawItems){db()->prepare("UPDATE abandoned_carts SET status='expired',last_activity_at=NOW() WHERE cart_token=? AND status='active'")->execute([$token]);return;}
    $json=json_encode(array_slice($rawItems,0,100),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if(!is_string($json))return;
    $count=0;foreach($rawItems as $item)if(is_array($item))$count+=max(0,(int)($item['quantity']??0));
    db()->prepare("INSERT INTO abandoned_carts(cart_token,customer_id,phone,items_json,items_count,subtotal,coupon_code,status,last_activity_at) VALUES(?,?,?,?,?,?,?,'active',NOW()) ON DUPLICATE KEY UPDATE customer_id=COALESCE(VALUES(customer_id),customer_id),phone=COALESCE(VALUES(phone),phone),items_json=VALUES(items_json),items_count=VALUES(items_count),subtotal=VALUES(subtotal),coupon_code=VALUES(coupon_code),status=IF(status='recovered','recovered','active'),last_activity_at=NOW()")->execute([$token,$customerId,$phone,$json,$count,$subtotal,normalize_coupon_code($couponCode)?:null]);
}

function recover_abandoned_cart(PDO $pdo,string $token,int $orderId,string $phone): void
{
    if($token==='')return;$pdo->prepare("UPDATE abandoned_carts SET status='recovered',recovered_order_id=?,phone=COALESCE(phone,?),last_activity_at=NOW() WHERE cart_token=? AND status<>'recovered'")->execute([$orderId,$phone,$token]);
}

function create_sms_campaign(PDO $pdo,string $title,string $audience,int $templateId,string $parameterName,string $parameterValue,int $adminId): int
{
    $title=trim($title);$parameterName=trim($parameterName);$parameterValue=trim($parameterValue);
    if(text_length($title)<3||text_length($title)>160)throw new RuntimeException('عنوان کمپین معتبر نیست.');
    if(!in_array($audience,['all','buyers','non_buyers'],true))throw new RuntimeException('گروه مخاطبان معتبر نیست.');
    if($templateId<1||!preg_match('/^[A-Za-z0-9_]{1,50}$/',$parameterName)||$parameterValue===''||text_length($parameterValue)>100)throw new RuntimeException('قالب و پارامتر پیامک کامل نیست.');
    $pdo->beginTransaction();try{
        $pdo->prepare("INSERT INTO sms_campaigns(title,audience,template_id,parameter_name,parameter_value,status,created_by) VALUES(?,?,?,?,?,'draft',?)")->execute([$title,$audience,$templateId,$parameterName,$parameterValue,$adminId]);$id=(int)$pdo->lastInsertId();
        $having=$audience==='buyers'?' HAVING COUNT(o.id)>0':($audience==='non_buyers'?' HAVING COUNT(o.id)=0':'');
        $sql="SELECT c.id,c.phone FROM customers c LEFT JOIN orders o ON o.customer_id=c.id WHERE c.is_active=1 AND c.marketing_sms_enabled=1 GROUP BY c.id,c.phone".$having;
        $insert=$pdo->prepare('INSERT IGNORE INTO sms_campaign_recipients(campaign_id,customer_id,mobile) VALUES(?,?,?)');$total=0;
        foreach($pdo->query($sql)->fetchAll() as $customer){if(!preg_match('/^09\d{9}$/',(string)$customer['phone']))continue;$insert->execute([$id,$customer['id'],$customer['phone']]);$total+=$insert->rowCount();}
        $pdo->prepare('UPDATE sms_campaigns SET total_count=? WHERE id=?')->execute([$total,$id]);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function process_sms_campaign(int $campaignId,int $limit=20): array
{
    $limit=max(1,min(100,$limit));$pdo=db();$lockName='mousavi-sms-campaign-'.$campaignId;$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)return ['processed'=>0,'sent'=>0,'failed'=>0];try{$campaignStmt=$pdo->prepare("SELECT * FROM sms_campaigns WHERE id=? AND status='running' LIMIT 1");$campaignStmt->execute([$campaignId]);$campaign=$campaignStmt->fetch();if(!$campaign)return ['processed'=>0,'sent'=>0,'failed'=>0];
    $rows=$pdo->query("SELECT * FROM sms_campaign_recipients WHERE campaign_id=".(int)$campaignId." AND status='pending' ORDER BY id LIMIT ".$limit)->fetchAll();$sent=0;$failed=0;
    foreach($rows as $row){try{$result=smsir_send_verify((string)$row['mobile'],(int)$campaign['template_id'],[(string)$campaign['parameter_name']=>(string)$campaign['parameter_value']],'marketing_campaign','sms_campaign',$campaignId);$status=!empty($result['skipped'])?'skipped':(!empty($result['ok'])?'sent':'failed');if($status==='sent')$sent++;elseif($status==='failed')$failed++;$error=in_array($status,['sent','skipped'],true)?null:(string)($result['message']??'ناموفق');}catch(Throwable $e){$status='failed';$error=$e->getMessage();$failed++;}
        $pdo->prepare('UPDATE sms_campaign_recipients SET status=?,error_message=?,processed_at=NOW() WHERE id=? AND status=\'pending\'')->execute([$status,$error,$row['id']]);}
    $pdo->prepare('UPDATE sms_campaigns SET sent_count=(SELECT COUNT(*) FROM sms_campaign_recipients WHERE campaign_id=? AND status=\'sent\'),failed_count=(SELECT COUNT(*) FROM sms_campaign_recipients WHERE campaign_id=? AND status=\'failed\') WHERE id=?')->execute([$campaignId,$campaignId,$campaignId]);
    $left=$pdo->prepare("SELECT COUNT(*) FROM sms_campaign_recipients WHERE campaign_id=? AND status='pending'");$left->execute([$campaignId]);if((int)$left->fetchColumn()===0)$pdo->prepare("UPDATE sms_campaigns SET status='completed',completed_at=NOW() WHERE id=? AND status='running'")->execute([$campaignId]);
    return ['processed'=>count($rows),'sent'=>$sent,'failed'=>$failed];
    }finally{try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $e){log_error($e);}}
}
