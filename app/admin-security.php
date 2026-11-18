<?php
declare(strict_types=1);

const ADMIN_ROUTE_UNMAPPED = '__unmapped__';

function admin_permission_catalog(): array
{
    return [
        'view_dashboard'=>'مشاهده داشبورد',
        'manage_products'=>'محصولات، دسته‌ها، برندها و جشنواره‌ها',
        'manage_orders'=>'سفارش‌ها و وضعیت پرداخت',
        'manage_returns'=>'مرجوعی و بازپرداخت',
        'manage_customers'=>'مشتریان',
        'manage_payments'=>'تنظیمات و گزارش درگاه',
        'manage_shipping'=>'ارسال، شرکت‌های حمل و رهگیری',
        'manage_accounting'=>'حسابداری و گزارش‌های مالی',
        'manage_sms'=>'پیامک و گزارش ارسال',
        'manage_content'=>'پیام‌های مشتری و محتوای فروشگاه',
        'manage_blog'=>'نوشته‌ها و دسته‌بندی‌های وبلاگ',
        'manage_reviews'=>'دیدگاه‌ها و پرسش‌وپاسخ محصولات',
        'manage_marketing'=>'کد تخفیف، سبد رهاشده و کمپین پیامکی',
        'manage_tickets'=>'تیکت‌های پشتیبانی',
        'view_reports'=>'گزارش‌های مدیریتی و خروجی Excel',
        'manage_settings'=>'تنظیمات عمومی فروشگاه',
        'manage_admins'=>'مدیران، نقش‌ها و امنیت',
        'view_audit'=>'مشاهده گزارش فعالیت مدیران',
        'manage_backups'=>'ساخت، دانلود و تنظیم بکاپ',
        'manage_system'=>'ارتقا و تعمیر سیستم',
    ];
}

function admin_route_permission(?string $script=null): ?string
{
    $script=$script!==null?basename($script):basename((string)($_SERVER['SCRIPT_NAME']??''));
    $map=[
        'index.php'=>'view_dashboard','login.php'=>null,'logout.php'=>null,'two-factor.php'=>null,'notifications.php'=>null,'api-notifications.php'=>null,
        'products.php'=>'manage_products','price-adjustments.php'=>'manage_products','product-edit.php'=>'manage_products','product-quick-add.php'=>'manage_products','product-gallery.php'=>'manage_products','product-variants.php'=>'manage_products','attributes.php'=>'manage_products','categories.php'=>'manage_products','brands.php'=>'manage_products','festivals.php'=>'manage_products','limited-discounts.php'=>'manage_products','torobpay-festivals.php'=>'manage_products','torob-sync.php'=>'manage_products','bale-product-bot.php'=>'manage_system',
        'orders.php'=>'manage_orders','order.php'=>'manage_orders','order-board.php'=>'manage_orders','order-action.php'=>'manage_orders','order-print.php'=>'manage_orders',
        'returns.php'=>'manage_returns','return.php'=>'manage_returns','return-settings.php'=>'manage_returns',
        'customers.php'=>'manage_customers',
        'payment-settings.php'=>'manage_payments','payments.php'=>'manage_payments',
        'shipping.php'=>'manage_shipping','shipments.php'=>'manage_shipping','shipment.php'=>'manage_shipping',
        'accounting.php'=>'manage_accounting','accounting-export.php'=>'manage_accounting','expense-edit.php'=>'manage_accounting',
        'sms-settings.php'=>'manage_sms','sms-logs.php'=>'manage_sms','sms-recipients.php'=>'manage_sms',
        'sms-campaigns.php'=>'manage_marketing','coupons.php'=>'manage_marketing','abandoned-carts.php'=>'manage_marketing','product-alerts.php'=>'manage_marketing',
        'tickets.php'=>'manage_tickets',
        'reviews.php'=>'manage_reviews','reports.php'=>'view_reports','reports-export.php'=>'view_reports',
        'messages.php'=>'manage_content','homepage.php'=>'manage_content','home-banners.php'=>'manage_content','trust-center.php'=>'manage_content','faqs.php'=>'manage_content',
        'blog-posts.php'=>'manage_blog','blog-edit.php'=>'manage_blog','blog-categories.php'=>'manage_blog',
        'settings.php'=>'manage_settings',
        'admin-users.php'=>'manage_admins','roles.php'=>'manage_admins','security.php'=>null,
        'activity-logs.php'=>'view_audit',
        'backups.php'=>'manage_backups','backup-download.php'=>'manage_backups',
        'upgrade.php'=>'manage_system','system-health.php'=>'manage_system',
    ];
    return array_key_exists($script,$map)?$map[$script]:ADMIN_ROUTE_UNMAPPED;
}

function admin_is_super(?array $user=null): bool
{
    $user??=function_exists('admin_user')?admin_user():null;
    return is_array($user) && empty($user['role_id']);
}

function admin_permissions(?array $user=null): array
{
    $user??=function_exists('admin_user')?admin_user():null;
    if(!is_array($user))return [];
    if(admin_is_super($user))return array_keys(admin_permission_catalog());
    $roleId=(int)($user['role_id']??0);if($roleId<1)return [];
    static $cache=[];
    if(isset($cache[$roleId]))return $cache[$roleId];
    $stmt=db()->prepare('SELECT rp.permission_key FROM admin_role_permissions rp JOIN admin_roles r ON r.id=rp.role_id AND r.is_active=1 WHERE rp.role_id=?');
    $stmt->execute([$roleId]);
    return $cache[$roleId]=array_values(array_intersect(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)),array_keys(admin_permission_catalog())));
}

function admin_can(string $permission,?array $user=null): bool
{
    return in_array($permission,admin_permissions($user),true);
}

function admin_enforce_route(): void
{
    $permission=admin_route_permission();
    if($permission===null)return;
    if($permission===ADMIN_ROUTE_UNMAPPED){
        if(admin_is_super())return;
        admin_audit_event('unmapped_admin_route_denied',null,null,['script'=>basename((string)($_SERVER['SCRIPT_NAME']??''))]);
        http_response_code(403);
        exit('این مسیر مدیریتی هنوز سطح دسترسی مشخصی ندارد.');
    }
    if(admin_can($permission))return;
    admin_audit_event('permission_denied',null,null,['permission'=>$permission]);
    http_response_code(403);
    exit('شما اجازه دسترسی به این بخش را ندارید.');
}

function admin_audit_event(string $eventKey,?string $targetType=null,?int $targetId=null,array $details=[]): void
{
    try{
        $user=function_exists('admin_user')?admin_user():null;
        $detailsJson=$details?json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE):null;
        $stmt=db()->prepare('INSERT INTO admin_activity_logs(admin_id,username,event_key,route,request_method,target_type,target_id,details_json,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $user['id']??null,$user['username']??($details['username']??null),text_substr($eventKey,0,100),text_substr((string)($_SERVER['REQUEST_URI']??''),0,190),text_substr((string)($_SERVER['REQUEST_METHOD']??''),0,10),$targetType,$targetId,$detailsJson,text_substr(client_ip(),0,45),text_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),
        ]);
        if(random_int(1,100)===1){$days=max(30,min(1095,(int)setting('admin_audit_retention_days','365')));db()->exec('DELETE FROM admin_activity_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL '.$days.' DAY)');}
    }catch(Throwable $e){log_error($e);}
}

function admin_register_request_audit(): void
{
    static $registered=false;
    if($registered||strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')return;
    $registered=true;
    $action=text_substr(trim(scalar_string($_POST['action']??'update')),0,80);
    $target=max(0,scalar_int($_POST['id']??$_POST['product_id']??$_POST['order_id']??0));
    register_shutdown_function(static function()use($action,$target):void{
        admin_audit_event('admin_post_'.$action,null,$target>0?$target:null,['status'=>http_response_code(),'fields'=>array_values(array_diff(array_keys($_POST),['_csrf','password','current_password','new_password','new_password_confirm','sms_api_key','payment_merchant_id','google_client_secret','bot_token','bale_product_bot_token']))]);
    });
}

function base32_encode_secret(string $bytes): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';$out='';
    foreach(str_split($bytes) as $char)$bits.=str_pad(decbin(ord($char)),8,'0',STR_PAD_LEFT);
    foreach(str_split($bits,5) as $chunk)$out.=$alphabet[bindec(str_pad($chunk,5,'0',STR_PAD_RIGHT))];
    return $out;
}

function base32_decode_secret(string $secret): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$secret=strtoupper(preg_replace('/[^A-Z2-7]/','',$secret)??'');$bits='';$out='';
    foreach(str_split($secret) as $char){$position=strpos($alphabet,$char);if($position===false)return '';$bits.=str_pad(decbin($position),5,'0',STR_PAD_LEFT);}
    foreach(str_split($bits,8) as $chunk)if(strlen($chunk)===8)$out.=chr(bindec($chunk));
    return $out;
}

function admin_totp_code(string $secret,?int $timestamp=null): string
{
    $key=base32_decode_secret($secret);if($key==='')return '';$counter=intdiv($timestamp??time(),30);
    $binary=pack('N2',($counter>>32)&0xffffffff,$counter&0xffffffff);$hash=hash_hmac('sha1',$binary,$key,true);$offset=ord($hash[19])&15;
    $value=((ord($hash[$offset])&127)<<24)|((ord($hash[$offset+1])&255)<<16)|((ord($hash[$offset+2])&255)<<8)|(ord($hash[$offset+3])&255);
    return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
}

function admin_totp_verify(string $secret,string $code): bool
{
    $code=normalize_digits(trim($code));if(!preg_match('/^\d{6}$/',$code))return false;
    foreach([-30,0,30] as $shift)if(hash_equals(admin_totp_code($secret,time()+$shift),$code))return true;
    return false;
}

function admin_generate_recovery_codes(int $adminId): array
{
    $codes=[];$pdo=db();$pdo->prepare('DELETE FROM admin_recovery_codes WHERE admin_id=?')->execute([$adminId]);$insert=$pdo->prepare('INSERT INTO admin_recovery_codes(admin_id,code_hash) VALUES(?,?)');
    for($i=0;$i<8;$i++){$raw=strtoupper(bin2hex(random_bytes(4)));$code=substr($raw,0,4).'-'.substr($raw,4,4);$codes[]=$code;$insert->execute([$adminId,hash('sha256',str_replace('-','',$code))]);}
    return $codes;
}

function admin_use_recovery_code(int $adminId,string $code): bool
{
    $hash=hash('sha256',strtoupper(preg_replace('/[^A-F0-9]/','',$code)??''));
    $stmt=db()->prepare('UPDATE admin_recovery_codes SET used_at=NOW() WHERE admin_id=? AND code_hash=? AND used_at IS NULL');$stmt->execute([$adminId,$hash]);return $stmt->rowCount()===1;
}

function admin_password_is_strong(string $password): bool
{
    return text_length($password)>=12&&text_length($password)<=200&&strlen($password)<=1024&&preg_match('/\d/u',$password)&&preg_match('/\D/u',$password);
}
