<?php
declare(strict_types=1);

/** @param array<int,string> $steps @param array<string,int|string> $expected */
function bale_adv_session_matches(?array $session,string $userId,array $steps,array $expected=[]): bool
{
    if(!$session||(string)($session['user_id']??'')!==$userId||!in_array((string)($session['step']??''),$steps,true))return false;
    $data=is_array($session['data']??null)?$session['data']:[];
    foreach($expected as $key=>$value)if((string)($data[$key]??'')!==(string)$value)return false;
    return true;
}

function bale_adv_expired_callback(string $callbackId,string $message='این دکمه منقضی شده است؛ عملیات را دوباره از منوی ربات شروع کنید.'): bool
{
    bale_product_bot_answer_callback($callbackId,$message);return true;
}

function bale_adv_tax_rows(string $type,int $page=1,int $perPage=10): array
{
    $table=$type==='category'?'categories':'brands';
    $where=$type==='category'?"slug<>'festival'":'1=1';
    $page=max(1,$page);$perPage=max(5,min(15,$perPage));$pdo=db();
    $total=(int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    $pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
    if($type==='category')$stmt=$pdo->prepare("SELECT id,name,slug,(SELECT COUNT(*) FROM products p WHERE p.category_id=categories.id) product_count FROM categories WHERE {$where} ORDER BY sort_order,name,id LIMIT {$perPage} OFFSET {$offset}");
    else $stmt=$pdo->prepare("SELECT id,name,slug,(SELECT COUNT(*) FROM products p WHERE p.brand_id=brands.id) product_count FROM brands WHERE {$where} ORDER BY sort_order,name,id LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute();return ['rows'=>$stmt->fetchAll()?:[],'page'=>$page,'pages'=>$pages,'total'=>$total];
}

function bale_adv_tax_list_markup(string $type,string $action,int $page=1): array
{
    $data=bale_adv_tax_rows($type,$page);$rows=[];$prefix=$type==='category'?'دسته':'برند';
    foreach($data['rows'] as $row){$icon=$action==='delete'?'🗑':'✏️';$count=(int)($row['product_count']??0);$label=$icon.' '.text_substr((string)$row['name'],0,36).($count>0?' · '.fa_number($count).' محصول':'');$rows[]=[[ 'text'=>$label,'callback_data'=>'adv:tax:'.$type[0].':'.$action[0].':'.(int)$row['id'] ]];}
    $nav=[];if($data['page']>1)$nav[]=['text'=>'⬅️ قبلی','callback_data'=>'adv:taxpage:'.$type[0].':'.$action[0].':'.($data['page']-1)];
    $nav[]=['text'=>fa_number($data['page']).'/'.fa_number($data['pages']),'callback_data'=>'page:noop:0'];
    if($data['page']<$data['pages'])$nav[]=['text'=>'بعدی ➡️','callback_data'=>'adv:taxpage:'.$type[0].':'.$action[0].':'.($data['page']+1)];if($nav)$rows[]=$nav;
    $rows[]=[[ 'text'=>'⬅️ بازگشت','callback_data'=>'adv:taxmenu:'.$type[0] ]];
    return bale_product_bot_inline_keyboard($rows);
}

function bale_adv_tax_list_text(string $type,string $action,int $page=1): string
{
    $data=bale_adv_tax_rows($type,$page);$what=$type==='category'?'دسته‌بندی':'برند';$verb=$action==='delete'?'حذف':'ویرایش';
    return "{$what} موردنظر برای {$verb} را انتخاب کنید.\n\nصفحه ".fa_number($data['page']).' از '.fa_number($data['pages']).' — مجموع '.fa_number($data['total']).' مورد';
}

function bale_adv_tax_menu(string $chatId,string $type,int $messageId=0): void
{
    $title=$type==='category'?'📂 مدیریت دسته‌بندی‌ها':'🏷 مدیریت برندها';$key=$type[0];
    $markup=bale_product_bot_inline_keyboard([[[ 'text'=>'➕ افزودن','callback_data'=>'adv:tax:'.$key.':add' ],[ 'text'=>'✏️ ویرایش','callback_data'=>'adv:tax:'.$key.':edit' ]],[[ 'text'=>'🗑 حذف','callback_data'=>'adv:tax:'.$key.':delete' ]],[[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]]]);
    if($messageId>0)bale_product_bot_edit($chatId,$messageId,$title."\nعملیات موردنظر را انتخاب کنید.",$markup);else bale_product_bot_send($chatId,$title."\nعملیات موردنظر را انتخاب کنید.",$markup);
}

function bale_adv_tax_record(string $type,int $id): ?array
{
    $table=$type==='category'?'categories':'brands';$stmt=db()->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");$stmt->execute([$id]);$row=$stmt->fetch();return $row?:null;
}

function bale_adv_tax_rename(string $type,int $id,string $name,string $userId): string
{
    $name=trim($name);if(text_length($name)<2||text_length($name)>120)throw new RuntimeException('نام باید بین ۲ تا ۱۲۰ کاراکتر باشد.');
    $table=$type==='category'?'categories':'brands';$row=bale_adv_tax_record($type,$id);if(!$row)throw new RuntimeException('مورد انتخاب‌شده پیدا نشد.');if($type==='category'&&(string)$row['slug']==='festival')throw new RuntimeException('دسته سیستمی جشنواره قابل ویرایش از ربات نیست.');
    $dup=db()->prepare("SELECT 1 FROM {$table} WHERE name=? AND id<>? LIMIT 1");$dup->execute([$name,$id]);if($dup->fetchColumn())throw new RuntimeException('موردی با همین نام از قبل وجود دارد.');
    db()->prepare("UPDATE {$table} SET name=? WHERE id=?")->execute([$name,$id]);admin_audit_event('bale_'.$type.'_renamed',$type,$id,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'old_name'=>(string)$row['name'],'new_name'=>$name]);return $name;
}

function bale_adv_resolve_brand(int $id): ?array
{
    $stmt=db()->prepare('SELECT b.id,b.name,b.slug,(SELECT COUNT(*) FROM products p WHERE p.brand_id=b.id) product_count FROM brands b WHERE b.id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();return $row?:null;
}

function bale_adv_delete_brand(int $brandId,string $userId): array
{
    $result=taxonomy_delete_brand($brandId,[
        'audit_event'=>'bale_brand_deleted',
        'username'=>'bale:'.$userId,
        'bale_user_id'=>$userId,
    ]);
    return ['ok'=>(bool)$result['ok'],'message'=>(string)$result['message'],'name'=>(string)$result['name']];
}

function bale_adv_product_gallery(int $productId): array
{
    $p=db()->prepare('SELECT id,title,image_path FROM products WHERE id=?');$p->execute([$productId]);$product=$p->fetch();if(!$product)return ['product'=>null,'images'=>[]];
    $pdo=db();$pdo->beginTransaction();try{product_gallery_ensure_primary_row($pdo,$productId,(string)($product['image_path']??''),(string)$product['title']);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();log_error($e);}
    return ['product'=>$product,'images'=>product_gallery_images($productId,(string)($product['image_path']??''),(string)$product['title'],false)];
}

function bale_adv_gallery_text(int $productId): string
{
    $g=bale_adv_product_gallery($productId);if(!$g['product'])return 'محصول پیدا نشد.';$lines=['🖼 مدیریت تصاویر محصول','محصول: '.(string)$g['product']['title'],'تعداد تصاویر: '.fa_number(count($g['images']))];
    foreach($g['images'] as $i=>$img){$lines[]=(($img['is_primary']??0)?'⭐ ':'').fa_number($i+1).' — '.text_substr((string)$img['url'],0,180);}return implode("\n",$lines);
}

function bale_adv_gallery_markup(int $productId): array
{
    return bale_product_bot_inline_keyboard([[[ 'text'=>'➕ افزودن تصویر','callback_data'=>'adv:img:add:'.$productId ],[ 'text'=>'🗑 حذف تصویر','callback_data'=>'adv:img:delmenu:'.$productId ]],[[ 'text'=>'⭐ تعیین تصویر اصلی','callback_data'=>'adv:img:primarymenu:'.$productId ]],[[ 'text'=>'⬅️ پایان','callback_data'=>'adv:img:done:'.$productId ]]]);
}

function bale_adv_gallery_choice_markup(int $productId,string $action): array
{
    $g=bale_adv_product_gallery($productId);$rows=[];foreach($g['images'] as $i=>$img){$id=(int)($img['id']??0);if($id<1)continue;$label=($action==='primary'?'⭐ ':'🗑 ').fa_number($i+1).(!empty($img['is_primary'])?' · اصلی':'');$rows[]=[[ 'text'=>$label,'callback_data'=>'adv:img:'.($action==='primary'?'primary':'delete').':'.$productId.':'.$id ]];}$rows[]=[[ 'text'=>'⬅️ بازگشت','callback_data'=>'adv:img:menu:'.$productId ]];return bale_product_bot_inline_keyboard($rows);
}

function bale_adv_gallery_add(int $productId,array $image,string $userId): void
{
    $g=bale_adv_product_gallery($productId);if(!$g['product'])throw new RuntimeException('محصول پیدا نشد.');if(count($g['images'])>=12)throw new RuntimeException('گالری این محصول به سقف ۱۲ تصویر رسیده است.');if((int)$image['file_size']>6*1024*1024)throw new RuntimeException('حجم تصویر بیشتر از ۶ مگابایت است.');
    $bytes=bale_product_bot_download_file((string)$image['file_id']);$path=bale_product_bot_save_image_bytes($bytes);$pdo=db();try{$pdo->beginTransaction();$max=$pdo->prepare('SELECT COALESCE(MAX(sort_order),-1) FROM product_gallery WHERE product_id=? FOR UPDATE');$max->execute([$productId]);$sort=(int)$max->fetchColumn()+1;$primary=count($g['images'])===0?1:0;$pdo->prepare('INSERT INTO product_gallery(product_id,image_path,alt_text,sort_order,is_primary) VALUES(?,?,?,?,?)')->execute([$productId,$path,(string)$g['product']['title'],$sort,$primary]);product_gallery_sync_primary($pdo,$productId);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();delete_managed_upload($path);throw $e;}admin_audit_event('bale_product_image_added','product',$productId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'path'=>$path]);
}

function bale_adv_gallery_delete(int $productId,int $imageId,string $userId): void
{
    $pdo=db();$path='';try{$pdo->beginTransaction();$s=$pdo->prepare('SELECT image_path FROM product_gallery WHERE id=? AND product_id=? FOR UPDATE');$s->execute([$imageId,$productId]);$path=(string)($s->fetchColumn()?:'');if($path==='')throw new RuntimeException('تصویر پیدا نشد.');$pdo->prepare('DELETE FROM product_gallery WHERE id=? AND product_id=?')->execute([$imageId,$productId]);product_gallery_sync_primary($pdo,$productId);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}product_gallery_delete_file_if_unused($path);admin_audit_event('bale_product_image_deleted','product',$productId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'image_id'=>$imageId]);
}

function bale_adv_gallery_primary(int $productId,int $imageId,string $userId): void
{
    $pdo=db();$pdo->beginTransaction();try{$s=$pdo->prepare('SELECT image_path FROM product_gallery WHERE id=? AND product_id=? FOR UPDATE');$s->execute([$imageId,$productId]);$path=$s->fetchColumn();if($path===false)throw new RuntimeException('تصویر پیدا نشد.');$pdo->prepare('UPDATE product_gallery SET is_primary=(id=?) WHERE product_id=?')->execute([$imageId,$productId]);$pdo->prepare('UPDATE products SET image_path=? WHERE id=?')->execute([(string)$path,$productId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}admin_audit_event('bale_product_image_primary','product',$productId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'image_id'=>$imageId]);
}

/** @param array<string,mixed> $status */
function bale_adv_image_repair_status_text(array $status): string
{
    $lines = [
        '🪄 اصلاح خودکار عکس‌های قبلی',
        '',
        'تصاویر اصلی، گالری و مدل‌های محصول بررسی می‌شوند.',
        'خروجی: WebP سفید ۱۲۰۰×۱۲۰۰، بدون برش و بدون کشیدگی.',
        '',
        'تصاویر یکتای پیدا‌شده: '.fa_number((int)($status['total']??0)),
        'از قبل آماده: '.fa_number((int)($status['ready']??0)),
        'نیازمند اصلاح: '.fa_number((int)($status['pending']??0)),
    ];
    if ((int)($status['low_source']??0) > 0) {
        $lines[] = 'منبع کوچک‌تر از ۶۰۰ پیکسل: '.fa_number((int)$status['low_source']).' (ابعاد اصلاح می‌شود، اما جزئیات واقعی بیشتر نمی‌شود)';
    }
    if ((int)($status['unavailable']??0) > 0) {
        $lines[] = 'فایل مفقود/ناسازگار و غیرقابل تبدیل: '.fa_number((int)$status['unavailable']);
    }
    if ((int)($status['pending']??0) > 0) {
        $lines[] = '';
        $lines[] = 'برای جلوگیری از قطع‌شدن ربات، هر بار حداکثر ۵ تصویر اصلاح می‌شود و می‌توانید مرحله بعد را بزنید.';
    }
    return implode("\n",$lines);
}

function bale_adv_image_repair_markup(bool $continue = false): array
{
    $label = $continue ? '▶️ اصلاح ۵ عکس بعدی' : '✅ شروع اصلاح';
    return bale_product_bot_inline_keyboard([[[
        'text'=>$label,
        'callback_data'=>'adv:imgrepair:run',
    ]],[[
        'text'=>'❌ پایان / انصراف',
        'callback_data'=>bale_product_bot_cancel_callback(),
    ]]]);
}

/** @param array<string,mixed> $result */
function bale_adv_image_repair_result_text(array $result): string
{
    $after = is_array($result['after']??null) ? $result['after'] : [];
    $lines = [
        '🪄 نتیجه این مرحله',
        '',
        'اصلاح‌شده: '.fa_number((int)($result['repaired']??0)),
        'ردشده به‌علت تغییر همزمان: '.fa_number((int)($result['skipped']??0)),
        'ناموفق: '.fa_number((int)($result['failed']??0)),
        'باقی‌مانده قابل اصلاح: '.fa_number((int)($after['pending']??0)),
        'کل تصاویر آماده: '.fa_number((int)($after['ready']??0)),
    ];
    if ((int)($result['low_source']??0) > 0) {
        $lines[] = 'هشدار کیفیت منبع در این مرحله: '.fa_number((int)$result['low_source']);
    }
    if ((int)($after['unavailable']??0) > 0) {
        $lines[] = 'مفقود/ناسازگار و نیازمند بررسی دستی: '.fa_number((int)$after['unavailable']);
    }
    $errors = is_array($result['errors']??null) ? $result['errors'] : [];
    if ($errors) {
        $lines[] = '';
        $lines[] = 'خطاهای نمونه:';
        foreach ($errors as $error) $lines[] = '• '.text_substr((string)$error,0,150);
    }
    if ((int)($after['pending']??0) < 1) {
        $lines[] = '';
        $lines[] = '✅ همه تصاویر قابل‌تبدیل بررسی شدند.';
    }
    return implode("\n",$lines);
}

function bale_adv_quick_menu(string $chatId,string $userId,int $productId,int $messageId=0,string $notice=''): void
{
    $p=bale_product_bot_product_for_edit($productId);if(!$p){bale_product_bot_send($chatId,'محصول پیدا نشد.',bale_product_bot_main_keyboard());return;}$text=($notice!==''?$notice."\n\n":'').'⚡ ویرایش سریع' . "\nمحصول: ".$p['title']."\nقیمت: ".fa_number(format_price((int)$p['price']))." تومان\nموجودی: ".fa_number((int)$p['stock']);
    $markup=bale_product_bot_inline_keyboard([[[ 'text'=>'💰 تغییر قیمت','callback_data'=>'adv:q:price:'.$productId ],[ 'text'=>'📦 تغییر موجودی','callback_data'=>'adv:q:stock:'.$productId ]],[[ 'text'=>'✅ پایان','callback_data'=>'adv:q:done:'.$productId ]]]);if($messageId>0)bale_product_bot_edit($chatId,$messageId,$text,$markup);else bale_product_bot_send($chatId,$text,$markup);bale_product_bot_session_save($chatId,$userId,'adv_quick_menu',['product_id'=>$productId,'menu_message_id'=>$messageId]);
}

function bale_adv_set_stock(int $productId,int $stock,string $userId): void
{
    if($stock<0||$stock>1000000)throw new RuntimeException('موجودی خارج از محدوده مجاز است.');$pdo=db();$pdo->beginTransaction();try{$s=$pdo->prepare('SELECT stock,product_type FROM products WHERE id=? FOR UPDATE');$s->execute([$productId]);$row=$s->fetch();if(!$row)throw new RuntimeException('محصول پیدا نشد.');if((string)$row['product_type']==='variable')throw new RuntimeException('موجودی محصول متغیر باید از مدل‌های محصول ویرایش شود.');$old=(int)$row['stock'];$delta=$stock-$old;$pdo->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$stock,$productId]);if($delta!==0)inventory_record_movement($pdo,$productId,null,'adjustment',$delta,$stock,0,'bale_quick',null,'ویرایش سریع موجودی از بله',null);$pdo->commit();if($stock>$old)product_alert_wake_for_product(db(),$productId,null);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}admin_audit_event('bale_quick_stock','product',$productId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'stock'=>$stock]);
}

function bale_adv_return_rows(): array
{
    return db()->query("SELECT r.id,r.return_number,r.status,r.reason,r.requested_amount,r.created_at,o.order_number,o.customer_name FROM return_requests r JOIN orders o ON o.id=r.order_id WHERE r.status NOT IN ('closed','refunded') ORDER BY FIELD(r.status,'requested','reviewing','approved','received','refund_pending','rejected'),r.id DESC LIMIT 20")->fetchAll()?:[];
}
function bale_adv_return_list_markup(): array{$rows=[];foreach(bale_adv_return_rows() as $r){$rows[]=[[ 'text'=>'↩️ '.text_substr((string)$r['return_number'],0,18).' · '.return_status_label((string)$r['status']),'callback_data'=>'adv:ret:view:'.(int)$r['id'] ]];}$rows[]=[[ 'text'=>'🔄 تازه‌سازی','callback_data'=>'adv:ret:list' ],[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]];return bale_product_bot_inline_keyboard($rows);}
function bale_adv_return_list_text(): string{$rows=bale_adv_return_rows();return $rows?'↩️ درخواست‌های مرجوعی فعال\nپرونده موردنظر را انتخاب کنید.':'✅ درخواست مرجوعی فعالی وجود ندارد.';}
function bale_adv_return_detail(int $id): ?array{$r=return_request_by_id($id);if(!$r)return null;$r['items']=return_items_for_request($id);return $r;}
function bale_adv_return_text(int $id): string{$r=bale_adv_return_detail($id);if(!$r)return 'پرونده مرجوعی پیدا نشد.';$reason=return_reason_options()[(string)$r['reason']]??(string)$r['reason'];$lines=['↩️ پرونده مرجوعی '.$r['return_number'],'سفارش: '.$r['order_number'],'مشتری: '.$r['customer_name'].' · '.$r['phone'],'وضعیت: '.return_status_label((string)$r['status']),'دلیل: '.$reason,'مبلغ درخواستی: '.fa_number(format_price((int)$r['requested_amount'])).' تومان'];if(trim((string)($r['customer_note']??''))!=='')$lines[]='توضیح مشتری: '.text_substr((string)$r['customer_note'],0,500);$lines[]='اقلام: '.fa_number(count($r['items']));return implode("\n",$lines);}
function bale_adv_return_markup(int $id): array{$r=bale_adv_return_detail($id);$rows=[];if(!$r)return bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ فهرست','callback_data'=>'adv:ret:list' ]]]);$status=(string)$r['status'];if(in_array($status,['requested','reviewing'],true)){$rows[]=[[ 'text'=>'🔎 در حال بررسی','callback_data'=>'adv:ret:status:'.$id.':reviewing' ],[ 'text'=>'✅ تأیید کامل','callback_data'=>'adv:ret:status:'.$id.':approved' ]];$rows[]=[[ 'text'=>'❌ رد درخواست','callback_data'=>'adv:ret:status:'.$id.':rejected' ]];}$rows[]=[[ 'text'=>'🌐 ادامه مدیریت در پنل','callback_data'=>'adv:ret:panel:'.$id ]];$rows[]=[[ 'text'=>'⬅️ فهرست مرجوعی‌ها','callback_data'=>'adv:ret:list' ]];return bale_product_bot_inline_keyboard($rows);}

function bale_adv_return_update(int $returnId,string $target,string $userId): string
{
    if(!in_array($target,['reviewing','approved','rejected'],true))throw new RuntimeException('این وضعیت از ربات قابل ثبت نیست.');$pdo=db();$pdo->beginTransaction();try{$r=return_request_by_id($returnId,true,$pdo);if(!$r)throw new RuntimeException('پرونده پیدا نشد.');$old=(string)$r['status'];if($old===$target){$pdo->commit();return 'وضعیت تغییری نکرد.';}if(!return_transition_allowed($old,$target))throw new RuntimeException('این تغییر وضعیت مجاز نیست.');
        $items=return_items_for_request($returnId,$pdo);$approvedTotal=0;if($target==='approved'){$requested=(int)$r['requested_amount'];$maxSum=0;foreach($items as $it)$maxSum+=(int)$it['unit_price']*(int)$it['quantity'];$remain=$requested;$upd=$pdo->prepare('UPDATE return_items SET approved_amount=? WHERE id=? AND return_id=?');foreach($items as $i=>$it){$max=(int)$it['unit_price']*(int)$it['quantity'];$amt=$i===array_key_last($items)?$remain:($maxSum>0?(int)floor($requested*$max/$maxSum):0);$amt=max(0,min($max,$amt,$remain));$remain-=$amt;$approvedTotal+=$amt;$upd->execute([$amt,(int)$it['id'],$returnId]);}}
        if($target==='rejected'){$pdo->prepare('UPDATE return_items SET approved_amount=0 WHERE return_id=?')->execute([$returnId]);}
        $pdo->prepare('UPDATE return_requests SET status=?,approved_amount=? WHERE id=?')->execute([$target,$target==='approved'?$approvedTotal:($target==='rejected'?0:(int)$r['approved_amount']),$returnId]);$orderOld=(string)$r['order_status'];$orderNew=$orderOld;if($target==='approved')$orderNew='return_approved';elseif($target==='rejected'&&in_array($orderOld,['return_requested','return_approved'],true))$orderNew=!empty($r['installed_at'])?'installed':'delivered';if($orderNew!==$orderOld){$pdo->prepare('UPDATE orders SET order_status=? WHERE id=?')->execute([$orderNew,(int)$r['order_id']]);$pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')->execute([(int)$r['order_id'],$orderOld,$orderNew,$r['payment_status'],$r['payment_status'],'تغییر وضعیت مرجوعی از ربات بله']);}$pdo->commit();sms_notify_return_status($returnId,$target);if($orderNew!==$orderOld)sms_notify_order_status((int)$r['order_id'],$orderNew);admin_audit_event('bale_return_status','return',$returnId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'status'=>$target]);return 'وضعیت مرجوعی به «'.return_status_label($target).'» تغییر کرد.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function bale_adv_ticket_rows(): array{return db()->query("SELECT t.id,t.ticket_number,t.subject,t.status,t.priority,t.last_message_at,c.full_name,c.phone FROM support_tickets t JOIN customers c ON c.id=t.customer_id WHERE t.status NOT IN ('closed') ORDER BY FIELD(t.status,'pending_admin','open','pending_customer','resolved'),FIELD(t.priority,'urgent','high','normal','low'),t.last_message_at DESC LIMIT 20")->fetchAll()?:[];}
function bale_adv_ticket_list_text(): string{return bale_adv_ticket_rows()?'🎫 تیکت‌های پشتیبانی\nتیکت موردنظر را انتخاب کنید.':'✅ تیکت بازی وجود ندارد.';}
function bale_adv_ticket_list_markup(): array{$rows=[];foreach(bale_adv_ticket_rows() as $t){$icon=(string)$t['status']==='pending_admin'?'🔴':'🎫';$rows[]=[[ 'text'=>$icon.' '.text_substr((string)$t['ticket_number'],0,18).' · '.text_substr((string)$t['subject'],0,26),'callback_data'=>'adv:tkt:view:'.(int)$t['id'] ]];}$rows[]=[[ 'text'=>'🔄 تازه‌سازی','callback_data'=>'adv:tkt:list' ],[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]];return bale_product_bot_inline_keyboard($rows);}
function bale_adv_ticket_detail(int $id): ?array{$s=db()->prepare('SELECT t.*,c.full_name,c.phone FROM support_tickets t JOIN customers c ON c.id=t.customer_id WHERE t.id=?');$s->execute([$id]);$t=$s->fetch();if(!$t)return null;$msgs=support_ticket_messages($id);$t['messages']=array_slice($msgs,-5);return $t;}
function bale_adv_ticket_text(int $id): string{$t=bale_adv_ticket_detail($id);if(!$t)return 'تیکت پیدا نشد.';$lines=['🎫 '.$t['ticket_number'],'موضوع: '.$t['subject'],'مشتری: '.$t['full_name'].' · '.$t['phone'],'وضعیت: '.(ticket_status_labels()[(string)$t['status']]??$t['status']),'اولویت: '.(ticket_priority_labels()[(string)$t['priority']]??$t['priority']),'','آخرین پیام‌ها:'];foreach($t['messages'] as $m){if((string)$m['sender_type']==='customer')$who='مشتری';else{$actor=trim((string)($m['admin_name']??$m['actor_label']??''));$who=$actor!==''?'پشتیبانی ('.$actor.')':'پشتیبانی';}$lines[]=$who.': '.text_substr((string)$m['message'],0,450);}return implode("\n",$lines);}
function bale_adv_ticket_markup(int $id): array{$t=bale_adv_ticket_detail($id);$rows=[];if($t&&(string)$t['status']!=='closed'){$rows[]=[[ 'text'=>'✍️ پاسخ','callback_data'=>'adv:tkt:reply:'.$id ],[ 'text'=>'✅ حل‌شده','callback_data'=>'adv:tkt:status:'.$id.':resolved' ]];$rows[]=[[ 'text'=>'🔒 بستن تیکت','callback_data'=>'adv:tkt:status:'.$id.':closed' ]];}$rows[]=[[ 'text'=>'⬅️ فهرست تیکت‌ها','callback_data'=>'adv:tkt:list' ]];return bale_product_bot_inline_keyboard($rows);}
function bale_adv_ticket_reply(int $ticketId,string $message,string $userId,?string $expectedLastMessageAt=null): void
{
    $message=trim($message);if(text_length($message)<2||text_length($message)>5000)throw new RuntimeException('متن پاسخ باید بین ۲ تا ۵۰۰۰ کاراکتر باشد.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT status,last_message_at FROM support_tickets WHERE id=? FOR UPDATE');$s->execute([$ticketId]);$ticket=$s->fetch();
        if(!$ticket)throw new RuntimeException('تیکت پیدا نشد.');if((string)$ticket['status']==='closed')throw new RuntimeException('این تیکت بسته شده است.');
        if($expectedLastMessageAt!==null&&$expectedLastMessageAt!==''&&(string)$ticket['last_message_at']!==$expectedLastMessageAt)throw new RuntimeException('پس از بازکردن تیکت پیام جدیدی ثبت شده است. ابتدا تیکت را تازه‌سازی کنید.');
        $actorLabel='بله #'.text_substr($userId,0,64);
        $pdo->prepare("INSERT INTO support_ticket_messages(ticket_id,sender_type,admin_id,actor_label,message) VALUES(?,'admin',NULL,?,?)")->execute([$ticketId,$actorLabel,$message]);
        $pdo->prepare("UPDATE support_tickets SET status='pending_customer',last_message_at=NOW() WHERE id=?")->execute([$ticketId]);$pdo->commit();
        sms_notify_ticket_reply($ticketId);admin_audit_event('bale_ticket_reply','ticket',$ticketId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'actor_label'=>$actorLabel]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function bale_adv_ticket_status(int $ticketId,string $status,string $userId,?string $expectedLastMessageAt=null): void
{
    if(!in_array($status,['resolved','closed'],true))throw new RuntimeException('وضعیت معتبر نیست.');
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT status,last_message_at FROM support_tickets WHERE id=? FOR UPDATE');$s->execute([$ticketId]);$row=$s->fetch();
        if(!$row)throw new RuntimeException('تیکت پیدا نشد.');
        if((string)$row['status']==='closed'){if($status==='closed'){$pdo->commit();return;}throw new RuntimeException('تیکت قبلاً بسته شده است.');}
        if($expectedLastMessageAt!==null && $expectedLastMessageAt!=='' && (string)$row['last_message_at']!==$expectedLastMessageAt)throw new RuntimeException('پس از بازکردن این تیکت پیام جدیدی ثبت شده است. ابتدا تیکت را تازه‌سازی کنید.');
        $pdo->prepare('UPDATE support_tickets SET status=? WHERE id=?')->execute([$status,$ticketId]);$pdo->commit();
        admin_audit_event('bale_ticket_status','ticket',$ticketId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'status'=>$status]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}


function bale_adv_daily_report(): string
{
    $pdo=db();
    $paid=$pdo->query("SELECT COUNT(*) c,COALESCE(SUM(total),0) s FROM orders WHERE paid_at>=CURDATE() AND paid_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND payment_status IN ('paid','partially_refunded','refunded')")->fetch();
    $refund=$pdo->query("SELECT COUNT(*) c,COALESCE(SUM(amount),0) s FROM refund_transactions WHERE status='completed' AND processed_at>=CURDATE() AND processed_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)")->fetch();
    $gross=(int)($paid['s']??0);$refundAmount=(int)($refund['s']??0);$net=$gross-$refundAmount;
    $new=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
    $unpaid=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status IN ('unpaid','pending','failed') AND created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
    $returns=(int)$pdo->query("SELECT COUNT(*) FROM return_requests WHERE created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
    $tickets=(int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status='pending_admin'")->fetchColumn();
    $low=bale_adv_low_stock_count();
    $top=$pdo->query("SELECT oi.title,SUM(oi.quantity) qty,SUM(oi.line_total) amount FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.paid_at>=CURDATE() AND o.paid_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND o.payment_status IN ('paid','partially_refunded','refunded') GROUP BY oi.product_id,oi.title ORDER BY qty DESC,amount DESC LIMIT 3")->fetchAll();
    $lines=['📊 گزارش فروش امروز','سفارش‌های ثبت‌شده: '.fa_number($new),'سفارش‌های پرداخت‌شده امروز: '.fa_number((int)($paid['c']??0)),'فروش ناخالص: '.fa_number(format_price($gross)).' تومان','بازپرداخت انجام‌شده امروز: '.fa_number(format_price($refundAmount)).' تومان ('.fa_number((int)($refund['c']??0)).' تراکنش)','فروش خالص امروز: '.fa_number(format_price($net)).' تومان','پرداخت تکمیل‌نشده امروز: '.fa_number($unpaid),'مرجوعی جدید: '.fa_number($returns),'تیکت منتظر پاسخ: '.fa_number($tickets),'اقلام کم‌موجود: '.fa_number($low)];
    if($top){$lines[]='';$lines[]='🏆 پرفروش‌های سفارش‌های پرداخت‌شده امروز:';foreach($top as $i=>$r)$lines[]=fa_number($i+1).'. '.text_substr((string)$r['title'],0,60).' — '.fa_number((int)$r['qty']).' عدد';}
    return implode("\n",$lines);
}

function bale_adv_low_stock_count(): int
{
    $threshold=max(1,min(100,(int)setting('admin_low_stock_threshold','3')));$pdo=db();
    $simple=$pdo->prepare("SELECT COUNT(*) FROM products WHERE status='active' AND product_type='simple' AND stock<=?");$simple->execute([$threshold]);
    $variants=$pdo->prepare("SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.status='active' AND p.product_type='variable' AND v.status='active' AND v.stock<=?");$variants->execute([$threshold]);
    return (int)$simple->fetchColumn()+(int)$variants->fetchColumn();
}

/** @return array<int,array<string,mixed>> */
function bale_adv_low_stock_rows(): array
{
    $threshold=max(1,min(100,(int)setting('admin_low_stock_threshold','3')));$pdo=db();
    $sql="SELECT 'product' kind,p.id product_id,0 variant_id,p.title display_title,p.sku,p.stock,p.price FROM products p WHERE p.status='active' AND p.product_type='simple' AND p.stock<=?
          UNION ALL
          SELECT 'variant' kind,p.id product_id,v.id variant_id,CONCAT(p.title,CASE WHEN COALESCE(v.title_suffix,'')<>'' THEN CONCAT(' — ',v.title_suffix) ELSE CONCAT(' — ',v.sku) END) display_title,v.sku,v.stock,v.price FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.status='active' AND p.product_type='variable' AND v.status='active' AND v.stock<=?
          ORDER BY stock ASC,product_id DESC LIMIT 50";
    $stmt=$pdo->prepare($sql);$stmt->execute([$threshold,$threshold]);return $stmt->fetchAll()?:[];
}

function bale_adv_low_stock_text(): string
{
    $rows=bale_adv_low_stock_rows();if(!$rows)return '✅ هیچ کالا یا مدل فعالی زیر حد هشدار موجودی نیست.';
    $threshold=max(1,min(100,(int)setting('admin_low_stock_threshold','3')));$lines=['⚠️ موجودی کم','حد هشدار: '.fa_number($threshold),'محصولات ساده و مدل‌های متغیر جداگانه بررسی می‌شوند.'];
    foreach($rows as $r)$lines[]='• '.text_substr((string)$r['display_title'],0,68).' — موجودی '.fa_number((int)$r['stock']).' — '.((string)$r['kind']==='variant'?'مدل':'محصول').' #'.fa_number((int)((string)$r['kind']==='variant'?$r['variant_id']:$r['product_id']));
    return implode("\n",$lines);
}

function bale_adv_low_stock_markup(): array
{
    $rows=[];$userId=bale_product_bot_runtime_user();$canEdit=$userId!==''&&bale_product_bot_can($userId,'products');
    if($canEdit)foreach(array_slice(bale_adv_low_stock_rows(),0,12) as $r){$id=(string)$r['kind']==='variant'?(int)$r['variant_id']:(int)$r['product_id'];$cb=(string)$r['kind']==='variant'?'adv:vq:menu:'.$id:'adv:q:menu:'.$id;$rows[]=[[ 'text'=>'📦 '.text_substr((string)$r['display_title'],0,35).' · '.fa_number((int)$r['stock']),'callback_data'=>$cb ]];}
    $rows[]=[[ 'text'=>'🔄 تازه‌سازی','callback_data'=>'adv:low:list' ],[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]];return bale_product_bot_inline_keyboard($rows);
}

function bale_adv_variant_record(int $variantId): ?array
{
    $stmt=db()->prepare("SELECT v.*,p.title product_title,p.status product_status,p.product_type FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=? LIMIT 1");$stmt->execute([$variantId]);$row=$stmt->fetch();return $row?:null;
}

function bale_adv_variant_quick_menu(string $chatId,string $userId,int $variantId,int $messageId=0,string $notice=''): void
{
    $v=bale_adv_variant_record($variantId);if(!$v){bale_product_bot_send($chatId,'مدل محصول پیدا نشد.',bale_product_bot_main_keyboard($userId));return;}
    $label=trim((string)($v['title_suffix']??''));if($label==='')$label=(string)$v['sku'];
    $text=($notice!==''?$notice."\n\n":'').'⚡ ویرایش سریع مدل'."\nمحصول: ".$v['product_title']."\nمدل: ".$label."\nSKU: ".$v['sku']."\nقیمت: ".fa_number(format_price((int)$v['price']))." تومان\nموجودی: ".fa_number((int)$v['stock']);
    $markup=bale_product_bot_inline_keyboard([[[ 'text'=>'💰 تغییر قیمت مدل','callback_data'=>'adv:vq:price:'.$variantId ],[ 'text'=>'📦 تغییر موجودی مدل','callback_data'=>'adv:vq:stock:'.$variantId ]],[[ 'text'=>'✅ پایان','callback_data'=>'adv:vq:done:'.$variantId ]]]);
    if($messageId>0)bale_product_bot_edit($chatId,$messageId,$text,$markup);else bale_product_bot_send($chatId,$text,$markup);
    bale_product_bot_session_save($chatId,$userId,'adv_variant_quick_menu',['variant_id'=>$variantId,'product_id'=>(int)$v['product_id'],'menu_message_id'=>$messageId]);
}

function bale_adv_set_variant_stock(int $variantId,int $stock,string $userId): void
{
    if($stock<0||$stock>1000000)throw new RuntimeException('موجودی خارج از محدوده مجاز است.');$pdo=db();$pdo->beginTransaction();
    try{$s=$pdo->prepare('SELECT id,product_id,stock FROM product_variants WHERE id=? FOR UPDATE');$s->execute([$variantId]);$v=$s->fetch();if(!$v)throw new RuntimeException('مدل محصول پیدا نشد.');$old=(int)$v['stock'];$delta=$stock-$old;$pdo->prepare('UPDATE product_variants SET stock=? WHERE id=?')->execute([$stock,$variantId]);if($delta!==0)inventory_record_movement($pdo,(int)$v['product_id'],$variantId,'adjustment',$delta,$stock,0,'bale_quick_variant',null,'ویرایش سریع موجودی مدل از بله',null);sync_product_variant_summary($pdo,(int)$v['product_id']);$pdo->commit();if($stock>$old)product_alert_wake_for_product(db(),(int)$v['product_id'],$variantId);admin_audit_event('bale_quick_variant_stock','variant',$variantId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'stock'=>$stock,'product_id'=>(int)$v['product_id']]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function bale_adv_set_variant_price(int $variantId,int $price,string $userId): void
{
    if($price<1||$price>MAX_MONEY_AMOUNT)throw new RuntimeException('قیمت معتبر نیست.');$pdo=db();$pdo->beginTransaction();
    try{$s=$pdo->prepare('SELECT id,product_id FROM product_variants WHERE id=? FOR UPDATE');$s->execute([$variantId]);$v=$s->fetch();if(!$v)throw new RuntimeException('مدل محصول پیدا نشد.');$pdo->prepare('UPDATE product_variants SET price=? WHERE id=?')->execute([$price,$variantId]);sync_product_variant_summary($pdo,(int)$v['product_id']);$pdo->commit();product_alert_wake_for_product(db(),(int)$v['product_id'],$variantId);admin_audit_event('bale_quick_variant_price','variant',$variantId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'price'=>$price,'product_id'=>(int)$v['product_id']]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function bale_product_bot_notify_low_stock_for_order(int $orderId): int
{
    if(!bale_product_bot_enabled() || (string)setting('bale_product_bot_low_stock_notifications','1')!=='1')return 0;
    try{
        $pdo=db();$paid=$pdo->prepare("SELECT payment_status FROM orders WHERE id=?");$paid->execute([$orderId]);if((string)$paid->fetchColumn()!=='paid')return 0;
        $threshold=max(1,min(100,(int)setting('admin_low_stock_threshold','3')));
        $sql="SELECT DISTINCT 'product' kind,p.id product_id,0 variant_id,p.title display_title,p.sku,p.stock FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? AND oi.variant_id IS NULL AND p.status='active' AND p.stock<=?
              UNION ALL
              SELECT DISTINCT 'variant' kind,p.id product_id,v.id variant_id,CONCAT(p.title,CASE WHEN COALESCE(v.title_suffix,'')<>'' THEN CONCAT(' — ',v.title_suffix) ELSE CONCAT(' — ',v.sku) END) display_title,v.sku,v.stock FROM order_items oi JOIN product_variants v ON v.id=oi.variant_id JOIN products p ON p.id=v.product_id WHERE oi.order_id=? AND v.status='active' AND p.status='active' AND v.stock<=?";
        $stmt=$pdo->prepare($sql);$stmt->execute([$orderId,$threshold,$orderId,$threshold]);$sent=0;
        foreach($stmt->fetchAll() as $item){
            $isVariant=(string)$item['kind']==='variant';$editId=$isVariant?(int)$item['variant_id']:(int)$item['product_id'];
            $text='⚠️ هشدار موجودی کم پس از پرداخت موفق'."
".($isVariant?'مدل: ':'محصول: ').(string)$item['display_title']."
کد: ".(string)$item['sku']."
موجودی فعلی: ".fa_number((int)$item['stock']);
            $markup=bale_product_bot_inline_keyboard([[[ 'text'=>'⚡ ویرایش سریع','callback_data'=>($isVariant?'adv:vq:menu:':'adv:q:menu:').$editId ]]]);
            $event='low-stock-paid-order:'.$orderId.':'.($isVariant?'v':'p').':'.$editId.':'.(int)$item['stock'];
            $sent+=bale_product_bot_notify_allowed($event,$text,$markup,'products');
        }
        return $sent;
    }catch(Throwable $e){log_error($e);return 0;}
}

function bale_product_bot_advanced_command(string $chatId,string $userId,string $text): bool
{
    if($text==='📂 مدیریت دسته‌ها'){bale_product_bot_session_save($chatId,$userId,'adv_tax_category',[]);bale_adv_tax_menu($chatId,'category');return true;}
    if($text==='🏷 مدیریت برندها'){bale_product_bot_session_save($chatId,$userId,'adv_tax_brand',[]);bale_adv_tax_menu($chatId,'brand');return true;}
    if($text==='🖼 مدیریت تصاویر'){bale_product_bot_session_save($chatId,$userId,'adv_image_product',[]);bale_product_bot_send($chatId,'شناسه، کد کالا یا بخشی از نام محصول را برای مدیریت تصاویر بفرستید.',bale_product_bot_cancel_keyboard());return true;}
    if($text==='🪄 اصلاح عکس‌های قبلی'){
        try{
            $status=product_image_repair_status(db());
            if((int)$status['pending']<1){bale_product_bot_session_delete($chatId);bale_product_bot_send($chatId,bale_adv_image_repair_status_text($status)."\n\n✅ تصویر قابل‌تبدیلِ باقی‌مانده‌ای وجود ندارد.",bale_product_bot_main_keyboard($userId));return true;}
            bale_product_bot_session_save($chatId,$userId,'adv_image_repair',[]);
            bale_product_bot_send($chatId,bale_adv_image_repair_status_text($status),bale_adv_image_repair_markup(false));
        }catch(Throwable $e){log_error($e);bale_product_bot_session_delete($chatId);bale_product_bot_send($chatId,'❌ بررسی تصاویر انجام نشد. ساختار پایگاه داده و دسترسی فایل‌ها را بررسی کنید.',bale_product_bot_main_keyboard($userId));}
        return true;
    }
    if(in_array($text,['↩️ مرجوعی‌ها','↩️ درخواست‌های مرجوعی'],true)){bale_product_bot_session_save($chatId,$userId,'adv_returns',[]);if((string)setting('return_enabled','1')!=='1'){bale_product_bot_send($chatId,'مدیریت مرجوعی در تنظیمات سایت غیرفعال است.',bale_product_bot_main_keyboard());return true;}bale_product_bot_send($chatId,bale_adv_return_list_text(),bale_adv_return_list_markup());return true;}
    if(in_array($text,['🎫 تیکت‌ها','🎫 تیکت‌های مشتریان'],true)){bale_product_bot_session_save($chatId,$userId,'adv_tickets',[]);if((string)setting('tickets_enabled','1')!=='1'){bale_product_bot_send($chatId,'تیکت پشتیبانی در تنظیمات سایت غیرفعال است.',bale_product_bot_main_keyboard());return true;}bale_product_bot_send($chatId,bale_adv_ticket_list_text(),bale_adv_ticket_list_markup());return true;}
    if($text==='📊 گزارش فروش امروز'){bale_product_bot_session_delete($chatId);bale_product_bot_send($chatId,bale_adv_daily_report(),bale_product_bot_main_keyboard());return true;}
    if($text==='⚠️ موجودی کم'){bale_product_bot_session_save($chatId,$userId,'adv_low_stock',[]);bale_product_bot_send($chatId,bale_adv_low_stock_text(),bale_adv_low_stock_markup());return true;}
    if($text==='⚡ قیمت/موجودی سریع'){bale_product_bot_session_save($chatId,$userId,'adv_quick_product',[]);bale_product_bot_send($chatId,'شناسه، کد کالا یا بخشی از نام محصول را بفرستید.',bale_product_bot_cancel_keyboard());return true;}
    return false;
}

function bale_product_bot_advanced_message(string $chatId,string $userId,string $step,array $data,array $message,string $text): bool
{
    if($step==='adv_category_rename'||$step==='adv_brand_rename'){$type=$step==='adv_category_rename'?'category':'brand';$id=(int)($data['id']??0);try{$name=bale_adv_tax_rename($type,$id,$text,$userId);bale_product_bot_session_delete($chatId);bale_product_bot_send($chatId,'✅ نام '.($type==='category'?'دسته':'برند').' به «'.$name.'» تغییر کرد.',bale_product_bot_main_keyboard());}catch(Throwable $e){bale_product_bot_send($chatId,'❌ '.$e->getMessage(),bale_product_bot_cancel_keyboard());}return true;}
    if($step==='adv_image_product'||$step==='adv_quick_product'){$products=bale_product_bot_find_products($text);if(!$products){bale_product_bot_send($chatId,'محصولی پیدا نشد. شناسه، کد یا نام را دوباره بفرستید.');return true;}if(count($products)>1){$lines=['چند محصول پیدا شد؛ شناسه دقیق را بفرستید:'];foreach($products as $p)$lines[]=$p['id'].' — '.$p['sku'].' — '.$p['title'];bale_product_bot_send($chatId,implode("\n",$lines));return true;}$id=(int)$products[0]['id'];if($step==='adv_image_product'){bale_product_bot_session_save($chatId,$userId,'adv_image_menu',['product_id'=>$id]);bale_product_bot_send($chatId,bale_adv_gallery_text($id),bale_adv_gallery_markup($id));}else bale_adv_quick_menu($chatId,$userId,$id);return true;}
    if($step==='adv_image_add'){$productId=(int)($data['product_id']??0);if($text==='✅ پایان مدیریت تصاویر'||$text==='-'){bale_product_bot_session_save($chatId,$userId,'adv_image_menu',['product_id'=>$productId]);bale_product_bot_send($chatId,bale_adv_gallery_text($productId),bale_adv_gallery_markup($productId));return true;}$image=bale_product_bot_extract_image($message);if(!$image){bale_product_bot_send($chatId,'یک تصویر بفرستید یا «پایان مدیریت تصاویر» را بزنید.',bale_product_bot_keyboard([['✅ پایان مدیریت تصاویر'],['❌ لغو عملیات']]));return true;}try{bale_adv_gallery_add($productId,$image,$userId);bale_product_bot_send($chatId,'✅ تصویر اضافه شد. می‌توانید تصویر بعدی را بفرستید یا پایان را بزنید.',bale_product_bot_keyboard([['✅ پایان مدیریت تصاویر'],['❌ لغو عملیات']]));}catch(Throwable $e){bale_product_bot_send($chatId,'❌ افزودن تصویر انجام نشد: '.$e->getMessage(),bale_product_bot_cancel_keyboard());}return true;}
    if($step==='adv_quick_price'||$step==='adv_quick_stock'){$id=(int)($data['product_id']??0);$menu=(int)($data['menu_message_id']??0);try{$number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';if($number==='')throw new RuntimeException('عدد معتبر بفرستید.');$value=(int)$number;if($step==='adv_quick_price'){if($value<1||$value>MAX_MONEY_AMOUNT)throw new RuntimeException('قیمت معتبر نیست.');$p=bale_product_bot_product_for_edit($id);if(!$p)throw new RuntimeException('محصول پیدا نشد.');if((string)($p['product_type']??'simple')==='variable')throw new RuntimeException('قیمت محصول متغیر باید از مدل‌های محصول مدیریت شود.');if($value!==(int)$p['price']&&torobpay_special_live_campaign_for_product($id))throw new RuntimeException('تا پایان یا لغو تخفیف محدود، قیمت اصلی این محصول قفل است.');if((int)($p['festival_price']??0)>0&&(int)$p['festival_price']>=$value)throw new RuntimeException('قیمت باید از قیمت جشنواره بیشتر باشد.');db()->prepare('UPDATE products SET price=? WHERE id=?')->execute([$value,$id]);product_alert_wake_for_product(db(),$id,null);admin_audit_event('bale_quick_price','product',$id,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'price'=>$value]);$notice='✅ قیمت تغییر کرد.';}else{bale_adv_set_stock($id,$value,$userId);$notice='✅ موجودی تغییر کرد.';}bale_adv_quick_menu($chatId,$userId,$id,$menu,$notice);}catch(Throwable $e){bale_product_bot_send($chatId,'❌ '.$e->getMessage(),bale_product_bot_cancel_keyboard());}return true;}
    if(in_array($step,['adv_variant_quick_price','adv_variant_quick_stock'],true)){$id=(int)($data['variant_id']??0);$menu=(int)($data['menu_message_id']??0);try{$number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';if($number==='')throw new RuntimeException('عدد معتبر بفرستید.');$value=(int)$number;if($step==='adv_variant_quick_price'){bale_adv_set_variant_price($id,$value,$userId);$notice='✅ قیمت مدل تغییر کرد.';}else{bale_adv_set_variant_stock($id,$value,$userId);$notice='✅ موجودی مدل تغییر کرد.';}bale_adv_variant_quick_menu($chatId,$userId,$id,$menu,$notice);}catch(Throwable $e){bale_product_bot_send($chatId,'❌ '.$e->getMessage(),bale_product_bot_cancel_keyboard());}return true;}
    if($step==='adv_ticket_reply'){$id=(int)($data['ticket_id']??0);$expected=(string)($data['last_message_at']??'');try{bale_adv_ticket_reply($id,$text,$userId,$expected);$fresh=bale_adv_ticket_detail($id);bale_product_bot_session_save($chatId,$userId,'adv_tickets',['ticket_id'=>$id,'last_message_at'=>(string)($fresh['last_message_at']??'')]);bale_product_bot_send($chatId,'✅ پاسخ برای مشتری ثبت شد.',bale_product_bot_main_keyboard($userId));bale_product_bot_send($chatId,bale_adv_ticket_text($id),bale_adv_ticket_markup($id));}catch(Throwable $e){bale_product_bot_session_save($chatId,$userId,'adv_tickets',[]);bale_product_bot_send($chatId,'❌ '.$e->getMessage().'\nتیکت را دوباره از فهرست باز کنید.',bale_product_bot_main_keyboard($userId));}return true;}
    if(in_array($step,['adv_image_menu','adv_image_repair','adv_quick_menu','adv_variant_quick_menu','adv_low_stock','adv_returns','adv_tickets','adv_tax_category','adv_tax_brand','adv_image_delete','adv_image_primary'],true)){bale_product_bot_send($chatId,'از دکمه‌های شیشه‌ای پیام قبلی استفاده کنید.');return true;}
    return false;
}

function bale_product_bot_advanced_callback(string $chatId,string $userId,int $messageId,string $dataValue,string $callbackId,?array $session): bool
{
    if($dataValue==='adv:imgrepair:run'){
        if(!bale_adv_session_matches($session,$userId,['adv_image_repair']))return bale_adv_expired_callback($callbackId);
        bale_product_bot_answer_callback($callbackId,'اصلاح تصاویر شروع شد…');
        try{
            $result=product_image_repair_batch(db(),5);
            admin_audit_event('bale_product_images_repaired',null,null,[
                'username'=>'bale:'.$userId,
                'bale_user_id'=>$userId,
                'attempted'=>(int)$result['attempted'],
                'repaired'=>(int)$result['repaired'],
                'failed'=>(int)$result['failed'],
                'remaining'=>(int)($result['after']['pending']??0),
            ]);
            $remaining=(int)($result['after']['pending']??0);
            if($remaining>0){
                bale_product_bot_session_save($chatId,$userId,'adv_image_repair',[]);
                bale_product_bot_edit($chatId,$messageId,bale_adv_image_repair_result_text($result),bale_adv_image_repair_markup(true));
            }else{
                bale_product_bot_session_delete($chatId);
                bale_product_bot_edit($chatId,$messageId,bale_adv_image_repair_result_text($result));
                bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));
            }
        }catch(Throwable $e){
            log_error($e);
            bale_product_bot_edit($chatId,$messageId,'❌ اصلاح تصاویر در این مرحله انجام نشد: '.public_exception_message($e),bale_adv_image_repair_markup(true));
        }
        return true;
    }

    // Taxonomy management: all modifying callbacks require a live, user-bound session.
    if(preg_match('/^adv:taxmenu:([cb])$/',$dataValue,$m)){
        $type=$m[1]==='c'?'category':'brand';$baseStep=$type==='category'?'adv_tax_category':'adv_tax_brand';
        if(!bale_adv_session_matches($session,$userId,[$baseStep,'adv_tax_delete_confirm']))return bale_adv_expired_callback($callbackId);
        bale_product_bot_session_save($chatId,$userId,$baseStep,[]);bale_adv_tax_menu($chatId,$type,$messageId);bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:tax:([cb]):(add|edit|delete)$/',$dataValue,$m)){
        $type=$m[1]==='c'?'category':'brand';$action=$m[2];$baseStep=$type==='category'?'adv_tax_category':'adv_tax_brand';
        if(!bale_adv_session_matches($session,$userId,[$baseStep]))return bale_adv_expired_callback($callbackId);
        if($action==='add'){
            bale_product_bot_session_save($chatId,$userId,$type==='category'?'add_category_name':'add_brand_name',[]);
            bale_product_bot_edit($chatId,$messageId,'نام '.($type==='category'?'دسته‌بندی':'برند').' جدید را بفرستید.');bale_product_bot_answer_callback($callbackId);return true;
        }
        bale_product_bot_edit($chatId,$messageId,bale_adv_tax_list_text($type,$action,1),bale_adv_tax_list_markup($type,$action,1));bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:taxpage:([cb]):([ed]):(\d+)$/',$dataValue,$m)){
        $type=$m[1]==='c'?'category':'brand';$baseStep=$type==='category'?'adv_tax_category':'adv_tax_brand';
        if(!bale_adv_session_matches($session,$userId,[$baseStep]))return bale_adv_expired_callback($callbackId);
        $action=$m[2]==='e'?'edit':'delete';$page=max(1,(int)$m[3]);
        bale_product_bot_edit($chatId,$messageId,bale_adv_tax_list_text($type,$action,$page),bale_adv_tax_list_markup($type,$action,$page));bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:tax:([cb]):([ed]):(\d+)$/',$dataValue,$m)){
        $type=$m[1]==='c'?'category':'brand';$baseStep=$type==='category'?'adv_tax_category':'adv_tax_brand';
        if(!bale_adv_session_matches($session,$userId,[$baseStep]))return bale_adv_expired_callback($callbackId);
        $action=$m[2]==='e'?'edit':'delete';$id=(int)$m[3];$row=bale_adv_tax_record($type,$id);
        if(!$row){bale_product_bot_answer_callback($callbackId,'مورد پیدا نشد.');return true;}
        if($action==='edit'){
            bale_product_bot_session_save($chatId,$userId,$type==='category'?'adv_category_rename':'adv_brand_rename',['id'=>$id]);
            bale_product_bot_edit($chatId,$messageId,'نام جدید «'.(string)$row['name'].'» را بفرستید.');bale_product_bot_answer_callback($callbackId);return true;
        }
        $countStmt=db()->prepare('SELECT COUNT(*) FROM products WHERE '.($type==='category'?'category_id':'brand_id').'=?');$countStmt->execute([$id]);$count=(int)$countStmt->fetchColumn();
        bale_product_bot_session_save($chatId,$userId,'adv_tax_delete_confirm',['type'=>$type,'id'=>$id]);
        $txt="⚠️ حذف ".($type==='category'?'دسته':'برند')." «{$row['name']}»؟".($count>0?"\n".fa_number($count).' محصول به گروه جایگزین منتقل می‌شود.':'');
        $cb='adv:tax:'.($type==='category'?'c':'b').':confirm:'.$id;
        bale_product_bot_edit($chatId,$messageId,$txt,bale_product_bot_inline_keyboard([[[ 'text'=>'✅ حذف شود','callback_data'=>$cb ],[ 'text'=>'❌ انصراف','callback_data'=>'adv:taxmenu:'.$type[0] ]]]));bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:tax:([cb]):confirm:(\d+)$/',$dataValue,$m)){
        $type=$m[1]==='c'?'category':'brand';$id=(int)$m[2];
        if(!bale_adv_session_matches($session,$userId,['adv_tax_delete_confirm'],['type'=>$type,'id'=>$id]))return bale_adv_expired_callback($callbackId,'تأیید حذف منقضی شده است. دوباره دسته/برند را انتخاب کنید.');
        $res=$type==='category'?bale_product_bot_delete_category($id,$userId):bale_adv_delete_brand($id,$userId);bale_product_bot_session_delete($chatId);
        bale_product_bot_answer_callback($callbackId,$res['ok']?'حذف شد.':'حذف نشد.');bale_product_bot_edit($chatId,$messageId,($res['ok']?'✅ ':'❌ ').$res['message']);bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));return true;
    }

    // Product gallery. Destructive image actions require exact product/session scope.
    if(preg_match('/^adv:img:(menu|add|delmenu|primarymenu|done):(\d+)$/',$dataValue,$m)){
        $act=$m[1];$id=(int)$m[2];$allowed=['adv_image_menu','adv_image_delete','adv_image_primary','adv_image_add'];
        if(!bale_adv_session_matches($session,$userId,$allowed,['product_id'=>$id]))return bale_adv_expired_callback($callbackId);
        if($act==='done'){bale_product_bot_session_delete($chatId);bale_product_bot_edit($chatId,$messageId,'✅ مدیریت تصاویر پایان یافت.');bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));bale_product_bot_answer_callback($callbackId);return true;}
        if($act==='add'){bale_product_bot_session_save($chatId,$userId,'adv_image_add',['product_id'=>$id]);bale_product_bot_edit($chatId,$messageId,'تصویر جدید را بفرستید. می‌توانید چند تصویر پشت سر هم ارسال کنید. هر عکس خودکار بدون برش یا کشیدگی به WebP سفید ۱۲۰۰×۱۲۰۰ آماده ترب تبدیل می‌شود؛ ضلع کوتاه فایل اولیه حداقل ۳۰۰ پیکسل باشد.');bale_product_bot_send($chatId,'برای پایان، دکمه زیر را بزنید.',bale_product_bot_keyboard([['✅ پایان مدیریت تصاویر'],['❌ لغو عملیات']]));bale_product_bot_answer_callback($callbackId);return true;}
        if($act==='delmenu'){bale_product_bot_session_save($chatId,$userId,'adv_image_delete',['product_id'=>$id]);bale_product_bot_edit($chatId,$messageId,'تصویری را که باید حذف شود انتخاب کنید.',bale_adv_gallery_choice_markup($id,'delete'));bale_product_bot_answer_callback($callbackId);return true;}
        if($act==='primarymenu'){bale_product_bot_session_save($chatId,$userId,'adv_image_primary',['product_id'=>$id]);bale_product_bot_edit($chatId,$messageId,'تصویر اصلی را انتخاب کنید.',bale_adv_gallery_choice_markup($id,'primary'));bale_product_bot_answer_callback($callbackId);return true;}
        bale_product_bot_session_save($chatId,$userId,'adv_image_menu',['product_id'=>$id]);bale_product_bot_edit($chatId,$messageId,bale_adv_gallery_text($id),bale_adv_gallery_markup($id));bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:img:(delete|primary):(\d+):(\d+)$/',$dataValue,$m)){
        $action=$m[1];$productId=(int)$m[2];$imageId=(int)$m[3];$required=$action==='delete'?'adv_image_delete':'adv_image_primary';
        if(!bale_adv_session_matches($session,$userId,[$required],['product_id'=>$productId]))return bale_adv_expired_callback($callbackId);
        try{if($action==='delete')bale_adv_gallery_delete($productId,$imageId,$userId);else bale_adv_gallery_primary($productId,$imageId,$userId);bale_product_bot_session_save($chatId,$userId,'adv_image_menu',['product_id'=>$productId]);bale_product_bot_edit($chatId,$messageId,bale_adv_gallery_text($productId),bale_adv_gallery_markup($productId));bale_product_bot_answer_callback($callbackId,'ذخیره شد.');}catch(Throwable $e){bale_product_bot_answer_callback($callbackId,text_substr($e->getMessage(),0,170));}return true;
    }

    // Quick edit for simple products. Opening a menu is safe entry; changes require the live menu session.
    if(preg_match('/^adv:q:(menu|price|stock|done):(\d+)$/',$dataValue,$m)){
        $act=$m[1];$id=(int)$m[2];
        if($act==='menu'){bale_adv_quick_menu($chatId,$userId,$id,$messageId);bale_product_bot_answer_callback($callbackId);return true;}
        if(!bale_adv_session_matches($session,$userId,['adv_quick_menu','adv_quick_price','adv_quick_stock'],['product_id'=>$id]))return bale_adv_expired_callback($callbackId);
        if($act==='done'){bale_product_bot_session_delete($chatId);bale_product_bot_edit($chatId,$messageId,'✅ ویرایش سریع پایان یافت.');bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));bale_product_bot_answer_callback($callbackId);return true;}
        $step=$act==='price'?'adv_quick_price':'adv_quick_stock';bale_product_bot_session_save($chatId,$userId,$step,['product_id'=>$id,'menu_message_id'=>$messageId]);bale_product_bot_edit($chatId,$messageId,$act==='price'?'قیمت جدید را به تومان و فقط عدد بفرستید.':'موجودی جدید را فقط به‌صورت عدد بفرستید.');bale_product_bot_answer_callback($callbackId);return true;
    }

    // Quick edit for variants.
    if(preg_match('/^adv:vq:(menu|price|stock|done):(\d+)$/',$dataValue,$m)){
        $act=$m[1];$id=(int)$m[2];
        if($act==='menu'){bale_adv_variant_quick_menu($chatId,$userId,$id,$messageId);bale_product_bot_answer_callback($callbackId);return true;}
        if(!bale_adv_session_matches($session,$userId,['adv_variant_quick_menu','adv_variant_quick_price','adv_variant_quick_stock'],['variant_id'=>$id]))return bale_adv_expired_callback($callbackId);
        if($act==='done'){bale_product_bot_session_delete($chatId);bale_product_bot_edit($chatId,$messageId,'✅ ویرایش سریع مدل پایان یافت.');bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));bale_product_bot_answer_callback($callbackId);return true;}
        $step=$act==='price'?'adv_variant_quick_price':'adv_variant_quick_stock';$record=bale_adv_variant_record($id);if(!$record){bale_product_bot_answer_callback($callbackId,'مدل پیدا نشد.');return true;}
        bale_product_bot_session_save($chatId,$userId,$step,['variant_id'=>$id,'product_id'=>(int)$record['product_id'],'menu_message_id'=>$messageId]);bale_product_bot_edit($chatId,$messageId,$act==='price'?'قیمت جدید مدل را به تومان و فقط عدد بفرستید.':'موجودی جدید مدل را فقط عدد بفرستید.');bale_product_bot_answer_callback($callbackId);return true;
    }

    if($dataValue==='adv:low:list'){
        if(!bale_adv_session_matches($session,$userId,['adv_low_stock']))return bale_adv_expired_callback($callbackId);
        bale_product_bot_edit($chatId,$messageId,bale_adv_low_stock_text(),bale_adv_low_stock_markup());bale_product_bot_answer_callback($callbackId);return true;
    }

    // Returns.
    if($dataValue==='adv:ret:list'){
        if(!bale_adv_session_matches($session,$userId,['adv_returns']))return bale_adv_expired_callback($callbackId);
        bale_product_bot_session_save($chatId,$userId,'adv_returns',[]);bale_product_bot_edit($chatId,$messageId,bale_adv_return_list_text(),bale_adv_return_list_markup());bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:ret:view:(\d+)$/',$dataValue,$m)){
        if(!bale_adv_session_matches($session,$userId,['adv_returns']))return bale_adv_expired_callback($callbackId);
        $id=(int)$m[1];bale_product_bot_session_save($chatId,$userId,'adv_returns',['return_id'=>$id]);bale_product_bot_edit($chatId,$messageId,bale_adv_return_text($id),bale_adv_return_markup($id));bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:ret:status:(\d+):(reviewing|approved|rejected)$/',$dataValue,$m)){
        $id=(int)$m[1];if(!bale_adv_session_matches($session,$userId,['adv_returns'],['return_id'=>$id]))return bale_adv_expired_callback($callbackId);
        try{$msg=bale_adv_return_update($id,$m[2],$userId);bale_product_bot_edit($chatId,$messageId,$msg."\n\n".bale_adv_return_text($id),bale_adv_return_markup($id));bale_product_bot_answer_callback($callbackId,'ذخیره شد.');}catch(Throwable $e){bale_product_bot_answer_callback($callbackId,text_substr($e->getMessage(),0,170));}return true;
    }
    if(preg_match('/^adv:ret:panel:(\d+)$/',$dataValue,$m)){
        $id=(int)$m[1];if(!bale_adv_session_matches($session,$userId,['adv_returns'],['return_id'=>$id]))return bale_adv_expired_callback($callbackId);
        bale_product_bot_answer_callback($callbackId,'لینک پنل ارسال شد.');bale_product_bot_send($chatId,"ادامه مدیریت مرجوعی در پنل:\n".app_url('admin/return.php?id='.$id));return true;
    }

    // Tickets with last-message fingerprint to avoid closing/replying over a concurrent customer message.
    if($dataValue==='adv:tkt:list'){
        if(!bale_adv_session_matches($session,$userId,['adv_tickets']))return bale_adv_expired_callback($callbackId);
        bale_product_bot_session_save($chatId,$userId,'adv_tickets',[]);bale_product_bot_edit($chatId,$messageId,bale_adv_ticket_list_text(),bale_adv_ticket_list_markup());bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:tkt:view:(\d+)$/',$dataValue,$m)){
        if(!bale_adv_session_matches($session,$userId,['adv_tickets']))return bale_adv_expired_callback($callbackId);
        $id=(int)$m[1];$ticket=bale_adv_ticket_detail($id);if(!$ticket){bale_product_bot_answer_callback($callbackId,'تیکت پیدا نشد.');return true;}
        bale_product_bot_session_save($chatId,$userId,'adv_tickets',['ticket_id'=>$id,'last_message_at'=>(string)$ticket['last_message_at']]);bale_product_bot_edit($chatId,$messageId,bale_adv_ticket_text($id),bale_adv_ticket_markup($id));bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:tkt:reply:(\d+)$/',$dataValue,$m)){
        $id=(int)$m[1];if(!bale_adv_session_matches($session,$userId,['adv_tickets'],['ticket_id'=>$id]))return bale_adv_expired_callback($callbackId);
        $expected=(string)($session['data']['last_message_at']??'');bale_product_bot_session_save($chatId,$userId,'adv_ticket_reply',['ticket_id'=>$id,'last_message_at'=>$expected]);bale_product_bot_edit($chatId,$messageId,'پاسخ خود را برای مشتری بفرستید.');bale_product_bot_answer_callback($callbackId);return true;
    }
    if(preg_match('/^adv:tkt:status:(\d+):(resolved|closed)$/',$dataValue,$m)){
        $id=(int)$m[1];if(!bale_adv_session_matches($session,$userId,['adv_tickets'],['ticket_id'=>$id]))return bale_adv_expired_callback($callbackId);
        $expected=(string)($session['data']['last_message_at']??'');
        try{bale_adv_ticket_status($id,$m[2],$userId,$expected);$fresh=bale_adv_ticket_detail($id);bale_product_bot_session_save($chatId,$userId,'adv_tickets',['ticket_id'=>$id,'last_message_at'=>(string)($fresh['last_message_at']??'')]);bale_product_bot_edit($chatId,$messageId,bale_adv_ticket_text($id),bale_adv_ticket_markup($id));bale_product_bot_answer_callback($callbackId,'وضعیت تغییر کرد.');}catch(Throwable $e){bale_product_bot_answer_callback($callbackId,text_substr($e->getMessage(),0,170));}return true;
    }
    return false;
}
