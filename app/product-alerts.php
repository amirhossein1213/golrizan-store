<?php
declare(strict_types=1);

function product_alert_type_labels(): array
{
    return ['stock'=>'موجودشدن','price_drop'=>'کاهش قیمت'];
}

function product_alert_status_labels(): array
{
    return ['pending'=>'در انتظار','processing'=>'در حال پردازش','sent'=>'ارسال‌شده','cancelled'=>'لغوشده','failed'=>'ناموفق'];
}

function product_alert_sms_readiness(?string $type = null): array
{
    $reasons=[];
    if((string)setting('product_alerts_enabled','1')!=='1')$reasons[]='ثبت هشدار محصول غیرفعال است.';
    if((string)setting('sms_product_alerts_enabled','0')!=='1')$reasons[]='ارسال پیامک هشدار محصول غیرفعال است.';
    if(!sms_enabled())$reasons[]='سامانه پیامک غیرفعال است.';
    if(sms_is_sandbox())$reasons[]='سامانه پیامک روی Sandbox است و ارسال واقعی انجام نمی‌شود.';
    if(sms_api_key()==='')$reasons[]='API Key پیامک ذخیره نشده است.';

    $stockTemplate=sms_template_id('sms_stock_alert_template_id');
    $priceTemplate=sms_template_id('sms_price_alert_template_id');
    if($type==='stock'&&$stockTemplate<1)$reasons[]='قالب موجودشدن محصول تنظیم نشده است.';
    elseif($type==='price_drop'&&$priceTemplate<1)$reasons[]='قالب کاهش قیمت محصول تنظیم نشده است.';
    elseif($type===null&&$stockTemplate<1&&$priceTemplate<1)$reasons[]='هیچ قالبی برای موجودشدن یا کاهش قیمت تنظیم نشده است.';

    return [
        'ready'=>$reasons===[],
        'reasons'=>$reasons,
        'message'=>$reasons?implode(' ',$reasons):'مسیر پیامک هشدار محصول آماده است.',
        'stock_template'=>$stockTemplate,
        'price_template'=>$priceTemplate,
    ];
}

function product_alert_wake_for_product(PDO $pdo,int $productId,?int $variantId=null): void
{
    if($productId<1)return;
    try{
        if($variantId!==null&&$variantId>0){
            $stmt=$pdo->prepare("UPDATE product_alerts SET next_attempt_at=NOW(),last_error=NULL WHERE product_id=? AND variant_id=? AND status='pending'");
            $stmt->execute([$productId,$variantId]);
        }else{
            $stmt=$pdo->prepare("UPDATE product_alerts SET next_attempt_at=NOW(),last_error=NULL WHERE product_id=? AND status='pending'");
            $stmt->execute([$productId]);
        }
    }catch(Throwable $exception){log_error($exception);}
}

function product_alert_record_run(array $result): void
{
    try{
        $stmt=db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $stmt->execute(['product_alert_last_run_at',date('Y-m-d H:i:s')]);
        $encoded=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        $stmt->execute(['product_alert_last_result',text_substr(is_string($encoded)?$encoded:'{}',0,1500)]);
    }catch(Throwable $exception){log_error($exception);}
}

function product_alert_current(int $productId, int $variantId = 0): ?array
{
    $product=find_product_by_id($productId);
    if(!$product)return null;
    if(product_has_variants($product)){
        $variant=$variantId>0?product_variant_by_id($variantId,true):product_default_variant($productId,true);
        if(!$variant||$variant['product_id']!==$productId)return null;
        $variant=variant_with_festival($product,$variant);
        return ['product'=>$product,'variant'=>$variant,'variant_id'=>(int)$variant['id'],'price'=>(int)$variant['price'],'stock'=>(int)$variant['stock'],'label'=>trim((string)$variant['option_label'])];
    }
    // find_product_by_id قیمت مؤثر جشنواره را نیز روی محصول اعمال می‌کند.
    return ['product'=>$product,'variant'=>null,'variant_id'=>0,'price'=>(int)$product['price'],'stock'=>(int)$product['stock'],'label'=>''];
}

function product_alert_subscribe(int $productId, int $variantId, string $phone, string $type, ?int $targetPrice, ?int $customerId): array
{
    if((string)setting('product_alerts_enabled','1')!=='1')throw new RuntimeException('هشدار محصولات از پنل مدیریت غیرفعال است.');
    $phone=normalize_phone($phone);
    if(!preg_match('/^09\d{9}$/',$phone))throw new RuntimeException('شماره موبایل معتبر نیست.');
    if(!isset(product_alert_type_labels()[$type]))throw new RuntimeException('نوع هشدار معتبر نیست.');
    $readiness=product_alert_sms_readiness($type);
    if(!$readiness['ready'])throw new RuntimeException('امکان ثبت این هشدار پیامکی موقتاً فعال نیست: '.(string)$readiness['message']);
    $current=product_alert_current($productId,$variantId);
    if(!$current)throw new RuntimeException('محصول یا مدل انتخاب‌شده در دسترس نیست.');
    if($type==='stock'&&$current['stock']>0)throw new RuntimeException('این کالا هم‌اکنون موجود است.');
    if($type==='price_drop'){
        if($targetPrice!==null&&($targetPrice<1||$targetPrice>MAX_MONEY_AMOUNT))throw new RuntimeException('قیمت هدف معتبر نیست.');
        if($targetPrice!==null&&$targetPrice>=$current['price'])throw new RuntimeException('قیمت هدف باید کمتر از قیمت فعلی باشد.');
    }else $targetPrice=null;
    $max=max(1,min(10,(int)setting('product_alert_max_attempts','5')));
    $variantKey=(int)$current['variant_id'];
    $stmt=db()->prepare("INSERT INTO product_alerts(product_id,variant_id,variant_key,customer_id,phone,alert_type,baseline_price,target_price,status,attempts,max_attempts,next_attempt_at,last_error,sent_at,locked_at,lock_token) VALUES(?,?,?,?,?,?,?,?, 'pending',0,?,NOW(),NULL,NULL,NULL,NULL) ON DUPLICATE KEY UPDATE variant_id=VALUES(variant_id),customer_id=VALUES(customer_id),baseline_price=VALUES(baseline_price),target_price=VALUES(target_price),status='pending',attempts=0,max_attempts=VALUES(max_attempts),next_attempt_at=NOW(),last_error=NULL,sent_at=NULL,locked_at=NULL,lock_token=NULL");
    $stmt->execute([$productId,$variantKey?:null,$variantKey,$customerId,$phone,$type,$current['price'],$targetPrice,$max]);
    return ['type'=>$type,'phone'=>$phone,'product'=>$current['product']['title'],'variant'=>$current['label']];
}

function sms_send_product_alert(array $alert, array $current): array
{
    $type=(string)$alert['alert_type'];
    $readiness=product_alert_sms_readiness($type);
    if(!$readiness['ready'])throw new RuntimeException((string)$readiness['message']);
    $templateKey=$type==='stock'?'sms_stock_alert_template_id':'sms_price_alert_template_id';
    $title=(string)$current['product']['short_title'];
    if($current['label']!=='')$title.=' '.$current['label'];
    $result=smsir_send_verify((string)$alert['phone'],sms_template_id($templateKey),[
        sms_param_name('sms_alert_param_product','Product')=>text_substr($title,0,90),
        sms_param_name('sms_alert_param_price','Price')=>format_price((int)$current['price']),
    ],'product_alert_'.$type,'product_alert',(int)$alert['id']);
    if(!empty($result['skipped']))throw new RuntimeException((string)($result['message']??'ارسال واقعی پیامک انجام نشد.'));
    if(empty($result['ok']))throw new RuntimeException((string)($result['message']??'ارسال هشدار پیامکی انجام نشد.'));
    return $result;
}

function process_product_alerts(int $limit=30): array
{
    $readiness=product_alert_sms_readiness();
    if(!$readiness['ready']){
        $result=['claimed'=>0,'checked'=>0,'sent'=>0,'failed'=>0,'paused'=>true,'message'=>$readiness['message']];
        product_alert_record_run($result);
        return $result;
    }

    $availableTypes=[];
    if((int)$readiness['stock_template']>0)$availableTypes[]='stock';
    if((int)$readiness['price_template']>0)$availableTypes[]='price_drop';
    if(!$availableTypes){
        $result=['claimed'=>0,'checked'=>0,'sent'=>0,'failed'=>0,'paused'=>true,'message'=>'قالب هشدار محصول تنظیم نشده است.'];
        product_alert_record_run($result);
        return $result;
    }

    $limit=max(1,min(100,$limit));
    $pdo=db();
    $pdo->exec("UPDATE product_alerts SET status='pending',lock_token=NULL,locked_at=NULL WHERE status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
    $token=bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try{
        $typeMarks=implode(',',array_fill(0,count($availableTypes),'?'));
        $sql="SELECT id FROM product_alerts WHERE status='pending' AND alert_type IN ($typeMarks) AND attempts<max_attempts AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id LIMIT {$limit} FOR UPDATE";
        $stmt=$pdo->prepare($sql);
        $stmt->execute($availableTypes);
        $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
        if($ids){
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $update=$pdo->prepare("UPDATE product_alerts SET status='processing',locked_at=NOW(),lock_token=? WHERE id IN ($marks) AND status='pending'");
            $update->execute(array_merge([$token],$ids));
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    if(!$ids){
        $result=['claimed'=>0,'checked'=>0,'sent'=>0,'failed'=>0,'message'=>'هشدار آماده‌ای برای پردازش وجود ندارد.'];
        product_alert_record_run($result);
        return $result;
    }

    $marks=implode(',',array_fill(0,count($ids),'?'));
    $fetch=$pdo->prepare("SELECT * FROM product_alerts WHERE id IN ($marks) AND lock_token=? ORDER BY id");
    $fetch->execute(array_merge($ids,[$token]));
    $alerts=$fetch->fetchAll();
    $result=['claimed'=>count($alerts),'checked'=>0,'sent'=>0,'failed'=>0];
    foreach($alerts as $alert){
        try{
            $current=product_alert_current((int)$alert['product_id'],(int)($alert['variant_id']??0));
            $result['checked']++;
            if(!$current){
                $pdo->prepare("UPDATE product_alerts SET status='cancelled',last_error='محصول یا مدل غیرفعال شده است',last_checked_at=NOW(),lock_token=NULL,locked_at=NULL WHERE id=? AND lock_token=?")->execute([$alert['id'],$token]);
                continue;
            }
            $trigger=(string)$alert['alert_type']==='stock'
                ? $current['stock']>0
                : ($alert['target_price']!==null?$current['price']<=(int)$alert['target_price']:$current['price']<(int)$alert['baseline_price']);
            if(!$trigger){
                $pdo->prepare("UPDATE product_alerts SET status='pending',last_error=NULL,last_checked_at=NOW(),next_attempt_at=DATE_ADD(NOW(),INTERVAL 15 MINUTE),lock_token=NULL,locked_at=NULL WHERE id=? AND lock_token=?")->execute([$alert['id'],$token]);
                continue;
            }
            sms_send_product_alert($alert,$current);
            $pdo->prepare("UPDATE product_alerts SET status='sent',sent_at=NOW(),last_checked_at=NOW(),last_error=NULL,lock_token=NULL,locked_at=NULL WHERE id=? AND lock_token=?")->execute([$alert['id'],$token]);
            $result['sent']++;
        }catch(Throwable $e){
            $attempt=(int)$alert['attempts']+1;
            $max=(int)$alert['max_attempts'];
            $delay=min(1440,5*(2**min(8,$attempt)));
            $status=$attempt>=$max?'failed':'pending';
            $pdo->prepare("UPDATE product_alerts SET status=?,attempts=?,last_error=?,last_checked_at=NOW(),next_attempt_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),lock_token=NULL,locked_at=NULL WHERE id=? AND lock_token=?")->execute([$status,$attempt,text_substr($e->getMessage(),0,500),$delay,$alert['id'],$token]);
            $result['failed']++;
            log_error($e);
        }
    }
    $result['message']='پردازش هشدارها انجام شد.';
    product_alert_record_run($result);
    return $result;
}
