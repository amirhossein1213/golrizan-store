<?php
declare(strict_types=1);

function admin_notification_event_catalog(): array
{
    return [
        'new_order'=>['label'=>'سفارش جدید','preference'=>'notify_new_order','level'=>'success','permission'=>'manage_orders'],
        'payment_success'=>['label'=>'پرداخت موفق','preference'=>'notify_payment_success','level'=>'success','permission'=>'manage_orders'],
        'order_cancelled'=>['label'=>'لغو سفارش','preference'=>'notify_order_cancelled','level'=>'warning','permission'=>'manage_orders'],
        'return_requested'=>['label'=>'درخواست مرجوعی','preference'=>'notify_return_requested','level'=>'warning','permission'=>'manage_returns'],
        'low_stock'=>['label'=>'موجودی کم','preference'=>'notify_low_stock','level'=>'warning','permission'=>'manage_products'],
        'sms_failed'=>['label'=>'خطای پیامک','preference'=>'notify_sms_failed','level'=>'error','permission'=>'manage_sms'],
        'gateway_failed'=>['label'=>'خطای درگاه','preference'=>'notify_gateway_failed','level'=>'error','permission'=>'manage_payments'],
        'order_assigned'=>['label'=>'سفارش واگذارشده','preference'=>'notify_order_assigned','level'=>'info','permission'=>'manage_orders'],
    ];
}

function admin_notification_preferences(int $adminId): array
{
    $defaults=[
        'in_panel'=>1,'browser_enabled'=>1,'sound_enabled'=>1,
        'notify_new_order'=>1,'notify_payment_success'=>1,'notify_order_cancelled'=>1,
        'notify_return_requested'=>1,'notify_low_stock'=>1,'notify_sms_failed'=>1,
        'notify_gateway_failed'=>1,'notify_order_assigned'=>1,
    ];
    if($adminId<1)return $defaults;
    try{
        $stmt=db()->prepare('SELECT * FROM admin_notification_preferences WHERE admin_id=? LIMIT 1');
        $stmt->execute([$adminId]);
        $row=$stmt->fetch();
        if(!$row)return $defaults;
        foreach($defaults as $key=>$value)$defaults[$key]=(int)($row[$key]??$value);
    }catch(Throwable $e){log_error($e);}
    return $defaults;
}

function admin_notification_save_preferences(int $adminId,array $input): void
{
    if($adminId<1)throw new RuntimeException('مدیر معتبر نیست.');
    $keys=array_merge(['in_panel','browser_enabled','sound_enabled'],array_values(array_column(admin_notification_event_catalog(),'preference')));
    $values=[];foreach($keys as $key)$values[$key]=!empty($input[$key])?1:0;
    $sql='INSERT INTO admin_notification_preferences(admin_id,'.implode(',',$keys).') VALUES(?,'.implode(',',array_fill(0,count($keys),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(static fn(string $key):string=>$key.'=VALUES('.$key.')',$keys));
    db()->prepare($sql)->execute(array_merge([$adminId],array_values($values)));
}

function admin_notification_safe_path(string $path): string
{
    $path=trim($path);
    if($path===''||strlen($path)>255||str_contains($path,'\\')||str_contains(rawurldecode($path),'\\'))return '';
    $parts=parse_url($path);
    if($parts===false||isset($parts['scheme'])||isset($parts['host'])||isset($parts['user'])||isset($parts['pass']))return '';
    $clean=ltrim((string)($parts['path']??''),'/');
    if(!str_starts_with($clean,'admin/'))return '';
    $query=isset($parts['query'])?'?'.$parts['query']:'';
    return text_substr($clean.$query,0,255);
}

function admin_notification_recipient_ids(string $eventKey,?int $targetAdminId=null): array
{
    $catalog=admin_notification_event_catalog();$event=$catalog[$eventKey]??null;if(!$event)return [];
    $preference=(string)$event['preference'];
    if(!preg_match('/^notify_[a-z_]+$/',$preference))return [];
    $permission=(string)$event['permission'];
    $params=[];
    $where=['a.is_active=1','(COALESCE(p.in_panel,1)=1 OR COALESCE(p.browser_enabled,1)=1 OR COALESCE(p.sound_enabled,1)=1)','COALESCE(p.`'.$preference.'`,1)=1'];
    if($targetAdminId!==null){$where[]='a.id=?';$params[]=$targetAdminId;}
    else{$where[]='(a.role_id IS NULL OR EXISTS(SELECT 1 FROM admin_role_permissions rp JOIN admin_roles r ON r.id=rp.role_id AND r.is_active=1 WHERE rp.role_id=a.role_id AND rp.permission_key=?))';$params[]=$permission;}
    $stmt=db()->prepare('SELECT DISTINCT a.id FROM admins a LEFT JOIN admin_notification_preferences p ON p.admin_id=a.id WHERE '.implode(' AND ',$where));
    $stmt->execute($params);
    return array_values(array_unique(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function admin_notification_emit(string $eventKey,string $title,string $message,array $options=[]): int
{
    $catalog=admin_notification_event_catalog();if(!isset($catalog[$eventKey]))return 0;
    try{
        $targetAdminId=isset($options['target_admin_id'])?max(1,(int)$options['target_admin_id']):null;
        $recipients=admin_notification_recipient_ids($eventKey,$targetAdminId);
        if(!$recipients)return 0;
        $title=text_substr(trim($title),0,180);$message=text_substr(trim($message),0,500);
        if($title===''||$message==='')return 0;
        $level=(string)($options['level']??$catalog[$eventKey]['level']);
        if(!in_array($level,['info','success','warning','error'],true))$level='info';
        $referenceType=text_substr(trim((string)($options['reference_type']??'')),0,60)?:null;
        $referenceId=isset($options['reference_id'])?max(0,(int)$options['reference_id']):null;
        $actionPath=admin_notification_safe_path((string)($options['action_path']??''))?:null;
        $dedupeKey=text_substr(trim((string)($options['dedupe_key']??'')),0,190)?:null;
        $pdo=db();
        $stmt=$pdo->prepare('INSERT INTO admin_notifications(event_key,title,message,level,reference_type,reference_id,action_path,dedupe_key) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$eventKey,$title,$message,$level,$referenceType,$referenceId,$actionPath,$dedupeKey]);
        $notificationId=(int)$pdo->lastInsertId();if($notificationId<1)return 0;
        $insert=$pdo->prepare('INSERT IGNORE INTO admin_notification_recipients(notification_id,admin_id) VALUES(?,?)');
        foreach($recipients as $adminId)$insert->execute([$notificationId,$adminId]);
        return $notificationId;
    }catch(Throwable $e){log_error($e);return 0;}
}

function admin_notification_unread_count(int $adminId): int
{
    if($adminId<1)return 0;
    try{$stmt=db()->prepare('SELECT COUNT(*) FROM admin_notification_recipients WHERE admin_id=? AND read_at IS NULL');$stmt->execute([$adminId]);return (int)$stmt->fetchColumn();}
    catch(Throwable $e){log_error($e);return 0;}
}

function admin_notifications_for_admin(int $adminId,int $limit=20,bool $unreadOnly=false): array
{
    if($adminId<1)return [];$limit=max(1,min(100,$limit));
    try{
        $sql='SELECT n.*,r.read_at,r.seen_at FROM admin_notification_recipients r JOIN admin_notifications n ON n.id=r.notification_id WHERE r.admin_id=?';
        if($unreadOnly)$sql.=' AND r.read_at IS NULL';
        $sql.=' ORDER BY n.id DESC LIMIT '.$limit;
        $stmt=db()->prepare($sql);$stmt->execute([$adminId]);return $stmt->fetchAll();
    }catch(Throwable $e){log_error($e);return [];}
}

function admin_notification_mark_seen(int $adminId,array $ids): void
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));if(!$ids||$adminId<1)return;
    $ids=array_slice($ids,0,100);$marks=implode(',',array_fill(0,count($ids),'?'));
    db()->prepare('UPDATE admin_notification_recipients SET seen_at=COALESCE(seen_at,NOW()) WHERE admin_id=? AND notification_id IN ('.$marks.')')->execute(array_merge([$adminId],$ids));
}

function admin_notification_mark_read(int $adminId,array $ids): void
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));if(!$ids||$adminId<1)return;
    $ids=array_slice($ids,0,100);$marks=implode(',',array_fill(0,count($ids),'?'));
    db()->prepare('UPDATE admin_notification_recipients SET read_at=COALESCE(read_at,NOW()),seen_at=COALESCE(seen_at,NOW()) WHERE admin_id=? AND notification_id IN ('.$marks.')')->execute(array_merge([$adminId],$ids));
}

function admin_notification_mark_all_read(int $adminId): void
{
    if($adminId<1)return;db()->prepare('UPDATE admin_notification_recipients SET read_at=COALESCE(read_at,NOW()),seen_at=COALESCE(seen_at,NOW()) WHERE admin_id=? AND read_at IS NULL')->execute([$adminId]);
}

function admin_notification_delete_for_admin(int $adminId,int $notificationId): void
{
    if($adminId<1||$notificationId<1)return;
    db()->prepare('DELETE FROM admin_notification_recipients WHERE admin_id=? AND notification_id=?')->execute([$adminId,$notificationId]);
}

function admin_notification_public_row(array $row): array
{
    $path=admin_notification_safe_path((string)($row['action_path']??''));
    return [
        'id'=>(int)$row['id'],'event_key'=>(string)$row['event_key'],'title'=>(string)$row['title'],
        'message'=>(string)$row['message'],'level'=>(string)$row['level'],'read'=>!empty($row['read_at']),
        'created_at'=>(string)$row['created_at'],'created_label'=>jalali_format((string)$row['created_at']),
        'url'=>$path!==''?app_url($path):'',
    ];
}

function admin_notify_new_order(int $orderId): void
{
    try{$stmt=db()->prepare('SELECT order_number,customer_name,total,payment_method FROM orders WHERE id=?');$stmt->execute([$orderId]);$o=$stmt->fetch();if(!$o)return;
        admin_notification_emit('new_order','سفارش جدید '.$o['order_number'],(string)$o['customer_name'].' · '.format_price((int)$o['total']).' تومان · '.payment_method_label((string)$o['payment_method']),['reference_type'=>'order','reference_id'=>$orderId,'action_path'=>'admin/order.php?id='.$orderId,'dedupe_key'=>'new-order:'.$orderId]);
        if(function_exists('bale_product_bot_notify_new_order'))bale_product_bot_notify_new_order($orderId);
    }catch(Throwable $e){log_error($e);}
}

function admin_notify_payment_success(int $orderId): void
{
    try{$stmt=db()->prepare('SELECT order_number,customer_name,total FROM orders WHERE id=?');$stmt->execute([$orderId]);$o=$stmt->fetch();if(!$o)return;
        admin_notification_emit('payment_success','پرداخت سفارش '.$o['order_number'].' موفق شد',(string)$o['customer_name'].' · '.format_price((int)$o['total']).' تومان',['reference_type'=>'order','reference_id'=>$orderId,'action_path'=>'admin/order.php?id='.$orderId,'dedupe_key'=>'payment-success:'.$orderId]);
        if(function_exists('bale_product_bot_notify_payment_success'))bale_product_bot_notify_payment_success($orderId);
        // Low-stock alerts are emitted only after a completed payment, not while checkout stock is merely reserved.
        admin_notify_low_stock_for_order($orderId);
        if(function_exists('bale_product_bot_notify_low_stock_for_order'))bale_product_bot_notify_low_stock_for_order($orderId);
    }catch(Throwable $e){log_error($e);}
}

function admin_notify_order_cancelled(int $orderId): void
{
    try{$stmt=db()->prepare('SELECT order_number,customer_name,total FROM orders WHERE id=?');$stmt->execute([$orderId]);$o=$stmt->fetch();if(!$o)return;
        admin_notification_emit('order_cancelled','سفارش '.$o['order_number'].' لغو شد',(string)$o['customer_name'].' · '.format_price((int)$o['total']).' تومان',['reference_type'=>'order','reference_id'=>$orderId,'action_path'=>'admin/order.php?id='.$orderId,'dedupe_key'=>'order-cancelled:'.$orderId]);
    }catch(Throwable $e){log_error($e);}
}

function admin_notify_return_requested(int $returnId): void
{
    try{$stmt=db()->prepare('SELECT r.return_number,o.order_number,o.customer_name FROM return_requests r JOIN orders o ON o.id=r.order_id WHERE r.id=?');$stmt->execute([$returnId]);$r=$stmt->fetch();if(!$r)return;
        admin_notification_emit('return_requested','درخواست مرجوعی '.$r['return_number'],'سفارش '.$r['order_number'].' · '.$r['customer_name'],['reference_type'=>'return','reference_id'=>$returnId,'action_path'=>'admin/return.php?id='.$returnId,'dedupe_key'=>'return-requested:'.$returnId]);
    }catch(Throwable $e){log_error($e);}
}

function admin_notify_low_stock_for_order(int $orderId): void
{
    $threshold=max(0,min(100,(int)setting('admin_low_stock_threshold','3')));if($threshold<1)return;
    try{
        $pdo=db();$paid=$pdo->prepare('SELECT payment_status FROM orders WHERE id=?');$paid->execute([$orderId]);if((string)$paid->fetchColumn()!=='paid')return;
        $simple=$pdo->prepare("SELECT DISTINCT p.id,p.title,p.stock FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? AND oi.variant_id IS NULL AND p.status='active' AND p.stock<=?");$simple->execute([$orderId,$threshold]);
        foreach($simple->fetchAll() as $p){$stock=(int)$p['stock'];admin_notification_emit('low_stock','موجودی کم: '.$p['title'],'موجودی فعلی '.fa_number($stock).' عدد است.',['reference_type'=>'product','reference_id'=>(int)$p['id'],'action_path'=>'admin/product-edit.php?id='.(int)$p['id'],'dedupe_key'=>'low-stock:p:'.(int)$p['id'].':'.$stock.':'.date('Y-m-d')]);}
        $variants=$pdo->prepare("SELECT DISTINCT v.id variant_id,v.product_id,v.sku,v.title_suffix,v.stock,p.title FROM order_items oi JOIN product_variants v ON v.id=oi.variant_id JOIN products p ON p.id=v.product_id WHERE oi.order_id=? AND v.status='active' AND p.status='active' AND v.stock<=?");$variants->execute([$orderId,$threshold]);
        foreach($variants->fetchAll() as $v){$stock=(int)$v['stock'];$label=trim((string)($v['title_suffix']??''));if($label==='')$label=(string)$v['sku'];admin_notification_emit('low_stock','موجودی کم مدل: '.$v['title'].' — '.$label,'موجودی این مدل '.fa_number($stock).' عدد است.',['reference_type'=>'product','reference_id'=>(int)$v['product_id'],'action_path'=>'admin/product-edit.php?id='.(int)$v['product_id'].'#variants','dedupe_key'=>'low-stock:v:'.(int)$v['variant_id'].':'.$stock.':'.date('Y-m-d')]);}
    }catch(Throwable $e){log_error($e);}
}

function admin_notify_sms_failure(int $smsLogId,string $message): void
{
    try{$stmt=db()->prepare('SELECT event_key,mobile,reference_type,reference_id FROM sms_logs WHERE id=?');$stmt->execute([$smsLogId]);$row=$stmt->fetch();if(!$row)return;
        admin_notification_emit('sms_failed','خطای ارسال پیامک',((string)$row['mobile']).' · '.text_substr($message,0,300),['reference_type'=>'sms_log','reference_id'=>$smsLogId,'action_path'=>'admin/sms-logs.php?status=failed','dedupe_key'=>'sms-failed:'.$smsLogId]);
    }catch(Throwable $e){log_error($e);}
}

function admin_notify_gateway_failure(int $orderId,string $message,string $dedupeSuffix=''): void
{
    try{$stmt=db()->prepare('SELECT order_number,customer_name FROM orders WHERE id=?');$stmt->execute([$orderId]);$o=$stmt->fetch();if(!$o)return;
        admin_notification_emit('gateway_failed','خطای درگاه سفارش '.$o['order_number'],(string)$o['customer_name'].' · '.text_substr($message,0,300),['reference_type'=>'order','reference_id'=>$orderId,'action_path'=>'admin/order.php?id='.$orderId,'dedupe_key'=>'gateway-failed:'.$orderId.':'.($dedupeSuffix!==''?$dedupeSuffix:substr(hash('sha256',$message),0,12))]);
        if(function_exists('bale_product_bot_notify_payment_alert'))bale_product_bot_notify_payment_alert($orderId,$message,$dedupeSuffix);
    }catch(Throwable $e){log_error($e);}
}

function admin_notify_order_assigned(int $orderId,int $adminId): void
{
    try{$stmt=db()->prepare('SELECT order_number,customer_name FROM orders WHERE id=?');$stmt->execute([$orderId]);$o=$stmt->fetch();if(!$o)return;
        admin_notification_emit('order_assigned','سفارش '.$o['order_number'].' به شما واگذار شد',(string)$o['customer_name'],['target_admin_id'=>$adminId,'reference_type'=>'order','reference_id'=>$orderId,'action_path'=>'admin/order.php?id='.$orderId,'dedupe_key'=>'order-assigned:'.$orderId.':'.$adminId.':'.date('YmdHi')]);
    }catch(Throwable $e){log_error($e);}
}
