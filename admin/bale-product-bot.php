<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
if (!admin_can('manage_products') || !admin_can('manage_system')) { http_response_code(403); exit('برای تنظیم ربات، دسترسی محصولات و سیستم لازم است.'); }

function bale_admin_save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]);
}
function bale_admin_normalize_ids(string $raw): string
{
    $ids=[];foreach(preg_split('/[\s,;]+/',trim($raw))?:[] as $id){$id=trim($id);if(preg_match('/^-?\d{1,20}$/',$id))$ids[$id]=$id;}return implode(',',array_slice(array_values($ids),0,30));
}

/** @param array<int,string> $allowedIds @return array<string,array<int,string>> */
function bale_admin_parse_permission_rules(string $raw,array $allowedIds): array
{
    $valid=array_flip(bale_product_bot_capabilities());$map=[];
    foreach(preg_split('/\R+/',trim($raw))?:[] as $line){
        $line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
        if(!preg_match('/^(-?\d{1,20})\s*=\s*(.+)$/',$line,$m))continue;
        $id=(string)$m[1];if(!in_array($id,$allowedIds,true))continue;
        $value=strtolower(trim((string)$m[2]));
        if(in_array($value,['full','all','کامل'],true)){$map[$id]=bale_product_bot_capabilities();continue;}
        $caps=[];foreach(preg_split('/[\s,;|]+/',$value)?:[] as $cap){$cap=trim($cap);if(isset($valid[$cap]))$caps[$cap]=$cap;}
        $map[$id]=array_values($caps);
    }
    // IDs without an explicit rule keep full access for backward compatibility and to avoid accidental lockout.
    foreach($allowedIds as $id)if(!array_key_exists($id,$map))$map[$id]=bale_product_bot_capabilities();
    return $map;
}

function bale_admin_permission_rules_text(): string
{
    $map=bale_product_bot_permission_map();$lines=[];
    foreach(bale_product_bot_allowed_ids() as $id){$caps=$map[$id]??bale_product_bot_capabilities();$all=count(array_diff(bale_product_bot_capabilities(),$caps))===0;$lines[]=$id.'='.($all?'full':implode(',',$caps));}
    return implode("\n",$lines);
}

$categories=array_values(array_filter(all_categories(false),static fn(array $row):bool=>(string)$row['slug']!=='festival'&&(int)$row['is_active']===1));
$brands=array_values(array_filter(all_brands(false),static fn(array $row):bool=>(int)$row['is_active']===1));
$errors=[];

if(is_post()){
    verify_csrf();$action=scalar_string($_POST['action']??'save_install');
    try{
        if($action==='save_install'){
            $tokenInput=bale_product_bot_normalize_token(scalar_string($_POST['bot_token']??''));$token=$tokenInput!==''?$tokenInput:bale_product_bot_token();
            if($token===''||!preg_match('/^[A-Za-z0-9:_-]{20,220}$/',$token))throw new RuntimeException('توکن ربات معتبر نیست.');
            $siteUrl=rtrim(app_url(),'/');if(!str_starts_with(strtolower($siteUrl),'https://'))throw new RuntimeException('برای نصب Webhook، نشانی سایت باید با HTTPS شروع شود.');
            $categoryId=max(0,scalar_int($_POST['default_category_id']??0));$brandId=max(0,scalar_int($_POST['default_brand_id']??0));
            if($categoryId>0&&!in_array($categoryId,array_map(static fn(array $r):int=>(int)$r['id'],$categories),true))throw new RuntimeException('دسته پیش‌فرض معتبر نیست.');
            if($brandId>0&&!in_array($brandId,array_map(static fn(array $r):int=>(int)$r['id'],$brands),true))throw new RuntimeException('برند پیش‌فرض معتبر نیست.');
            $maxImages=max(1,min(12,scalar_int($_POST['max_images']??12,12)));$warranty=max(0,min(120,scalar_int($_POST['default_warranty_months']??0,0)));$sessionMinutes=max(15,min(120,scalar_int($_POST['session_minutes']??60,60)));
            $allowedIdsText=bale_admin_normalize_ids(scalar_string($_POST['allowed_ids']??''));$allowedIds=$allowedIdsText===''?[]:explode(',',$allowedIdsText);$permissionMap=bale_admin_parse_permission_rules(scalar_string($_POST['permission_rules']??''),$allowedIds);
            $me=bale_product_bot_api('getMe',[],$token);$bot=$me['result']??[];
            $webhookKey=trim((string)setting('bale_product_bot_webhook_key',''));if(!preg_match('/^[a-f0-9]{48}$/',$webhookKey))$webhookKey=bin2hex(random_bytes(24));
            $webhookUrl=app_url('bale-product-webhook.php?key='.$webhookKey);
            bale_product_bot_api('setWebhook',['url'=>$webhookUrl],$token);
            $values=[
                'bale_product_bot_enabled'=>'1','bale_product_bot_token_enc'=>protect_secret($token),'bale_product_bot_webhook_key'=>$webhookKey,
                'bale_product_bot_allowed_ids'=>$allowedIdsText,'bale_product_bot_permissions_json'=>json_encode($permissionMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'bale_product_bot_session_minutes'=>(string)$sessionMinutes,
                'bale_product_bot_default_category_id'=>(string)$categoryId,'bale_product_bot_default_brand_id'=>(string)$brandId,
                'bale_product_bot_allow_direct_publish'=>isset($_POST['allow_direct_publish'])?'1':'0','bale_product_bot_max_images'=>(string)$maxImages,
                'bale_product_bot_default_warranty_months'=>(string)$warranty,'bale_product_bot_default_authenticity'=>isset($_POST['default_authenticity'])?'1':'0','bale_product_bot_default_safe_shipping'=>isset($_POST['default_safe_shipping'])?'1':'0',
                'bale_product_bot_order_notifications'=>isset($_POST['order_notifications'])?'1':'0','bale_product_bot_payment_notifications'=>isset($_POST['payment_notifications'])?'1':'0','bale_product_bot_order_management'=>isset($_POST['order_management'])?'1':'0','bale_product_bot_low_stock_notifications'=>isset($_POST['low_stock_notifications'])?'1':'0',
                'bale_product_bot_name'=>text_substr(trim(scalar_string(is_array($bot)?($bot['first_name']??''):'')),0,180),'bale_product_bot_username'=>text_substr(trim(scalar_string(is_array($bot)?($bot['username']??''):'')),0,180),'bale_product_bot_installed_at'=>date('Y-m-d H:i:s'),
            ];foreach($values as $key=>$value)bale_admin_save_setting($key,$value);
            admin_audit_event('bale_product_bot_installed');flash('success','ربات بله با موفقیت متصل و Webhook فعال شد.');redirect(app_url('admin/bale-product-bot.php'));
        }
        if($action==='test'){
            $me=bale_product_bot_api('getMe');$bot=$me['result']??[];$ids=bale_product_bot_allowed_ids();
            if(isset($_POST['send_test'])&&$ids)foreach($ids as $id)bale_product_bot_send($id,'✅ اتصال ربات مدیریت فروشگاه گلریزان سالم است.',bale_product_bot_main_keyboard($id));
            flash('success','اتصال با ربات '.trim(scalar_string(is_array($bot)?($bot['username']??$bot['first_name']??''):'')).' سالم است.'.($ids?' پیام آزمایشی نیز ارسال شد.':''));redirect(app_url('admin/bale-product-bot.php'));
        }
        if($action==='pair_code'){
            if(!bale_product_bot_enabled())throw new RuntimeException('ابتدا ربات را نصب و فعال کنید.');$code=(string)random_int(10000000,99999999);$save=db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$save->execute(['bale_product_bot_pair_hash',hash_hmac('sha256',$code,application_secret())]);$save->execute(['bale_product_bot_pair_expires_at',date('Y-m-d H:i:s',time()+600)]);$_SESSION['_bale_pair_code']=$code;flash('success','کد اتصال ۸ رقمی و ۱۰ دقیقه‌ای ساخته شد.');redirect(app_url('admin/bale-product-bot.php'));
        }
        if($action==='stop'){
            $token=bale_product_bot_token();if($token!=='')try{bale_product_bot_api('deleteWebhook',[],$token);}catch(Throwable $e){log_error($e);}bale_admin_save_setting('bale_product_bot_enabled','0');admin_audit_event('bale_product_bot_stopped');flash('success','Webhook ربات متوقف شد.');redirect(app_url('admin/bale-product-bot.php'));
        }
        if($action==='reset_sessions'){db()->exec('DELETE FROM bale_product_bot_sessions');flash('success','فرایندهای نیمه‌تمام ربات پاک شدند.');redirect(app_url('admin/bale-product-bot.php'));}
    }catch(Throwable $error){log_error($error);$errors[]=$error->getMessage();}
}

$enabled=bale_product_bot_enabled();$configured=bale_product_bot_token()!=='';$webhookKey=(string)setting('bale_product_bot_webhook_key','');$webhookUrl=$webhookKey!==''?app_url('bale-product-webhook.php?key='.$webhookKey):'پس از نصب ساخته می‌شود';$webhookInfo=null;
if($configured)try{$webhookInfo=bale_product_bot_api('getWebhookInfo')['result']??null;}catch(Throwable $error){$webhookInfo=['error'=>$error->getMessage()];}
$activeSessions=(int)db()->query('SELECT COUNT(*) FROM bale_product_bot_sessions WHERE expires_at>NOW()')->fetchColumn();$history=db()->query('SELECT h.*,p.title FROM bale_product_bot_history h LEFT JOIN products p ON p.id=h.product_id ORDER BY h.id DESC LIMIT 10')->fetchAll();$pairCode=$_SESSION['_bale_pair_code']??'';unset($_SESSION['_bale_pair_code']);
$adminPage='bale-product-bot';$adminTitle='ربات بله مدیریت محصولات و سفارش‌ها';require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1>ربات بله مدیریت محصولات، سفارش‌ها و پرداخت‌ها</h1><p class="muted">توکن را وارد کنید؛ سایت اتصال را آزمایش می‌کند و Webhook را نصب می‌کند. ربات افزودن محصول، مدیریت دسته و برند، دریافت لینک مستقیم هر محصول، اعلام فوری سفارش و پرداخت و رسیدگی مرحله‌ای به سفارش‌ها را بدون Cron انجام می‌دهد.</p></div><span class="badge <?=$enabled?'success':'warning'?>"><?=$enabled?'فعال':'متوقف'?></span></div>
<?php foreach($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<?php if($pairCode!==''):?><div class="flash success"><strong>کد اتصال:</strong> <code dir="ltr" style="font-size:20px"><?=e($pairCode)?></code><br>در گفت‌وگوی خصوصی ربات بفرستید: <code dir="ltr">/pair <?=e($pairCode)?></code> — اعتبار: ۱۰ دقیقه و یک‌بارمصرف.</div><?php endif?>
<div class="stats"><div class="card stat"><strong><?=$configured?'بله':'خیر'?></strong><span>توکن تنظیم‌شده</span></div><div class="card stat"><strong><?=$enabled?'فعال':'خاموش'?></strong><span>Webhook</span></div><div class="card stat"><strong><?=fa_number(count(bale_product_bot_allowed_ids()))?></strong><span>مدیر مجاز بله</span></div><div class="card stat"><strong><?=fa_number($activeSessions)?></strong><span>فرایند نیمه‌تمام</span></div></div>
<form method="post" class="card" autocomplete="off"><?=csrf_field()?><input type="hidden" name="action" value="save_install"><h2>نصب و تنظیم ربات</h2><div class="form-grid">
<div class="field full"><label>توکن ربات بله</label><input type="password" name="bot_token" dir="ltr" placeholder="<?=$configured?'برای حفظ توکن فعلی خالی بگذارید':'توکن خام دریافتی از سازنده ربات بله'?>" autocomplete="new-password"><small>فقط خود توکن را وارد کنید؛ بدون <code>bot</code>، بدون نشانی <code>tapi.bale.ai</code> و بدون فاصله. توکن رمزگذاری‌شده ذخیره می‌شود و در صفحه یا گزارش فعالیت نمایش داده نمی‌شود.</small></div>
<div class="field full"><label>شناسه‌های عددی مدیران مجاز</label><textarea name="allowed_ids" rows="2" dir="ltr" placeholder="123456789, 987654321"><?=e((string)setting('bale_product_bot_allowed_ids',''))?></textarea><small>می‌توانید خالی بگذارید، نصب را انجام دهید و سپس با کد اتصال حساب خود را مجاز کنید.</small></div>
<div class="field full"><label>سطح دسترسی هر مدیر بله</label><textarea name="permission_rules" rows="5" dir="ltr" placeholder="123456789=full&#10;987654321=products,tickets"><?=e(bale_admin_permission_rules_text())?></textarea><small>هر خط: <code>ID=full</code> یا ترکیبی از <code>products, orders, tickets, returns, reports, payments</code>. شناسه‌ای که قانون جدا ندارد برای جلوگیری از قفل‌شدن ناخواسته، دسترسی کامل می‌گیرد.</small></div>
<div class="field"><label>اعتبار فرایند ربات (دقیقه)</label><input type="number" name="session_minutes" min="15" max="120" value="<?=e((string)setting('bale_product_bot_session_minutes','60'))?>"><small>پیشنهاد: ۶۰ دقیقه. پس از آن دکمه‌های عملیاتی قدیمی منقضی می‌شوند.</small></div>
<div class="field"><label>دسته پیش‌فرض</label><select name="default_category_id"><option value="0">بدون پیش‌فرض؛ داخل ربات انتخاب شود</option><?php foreach($categories as $row):?><option value="<?=$row['id']?>" <?=(int)setting('bale_product_bot_default_category_id','0')===(int)$row['id']?'selected':''?>><?=e($row['name'])?></option><?php endforeach?></select></div>
<div class="field"><label>برند پیش‌فرض</label><select name="default_brand_id"><option value="0">بدون پیش‌فرض؛ داخل ربات انتخاب شود</option><?php foreach($brands as $row):?><option value="<?=$row['id']?>" <?=(int)setting('bale_product_bot_default_brand_id','0')===(int)$row['id']?'selected':''?>><?=e($row['name'])?></option><?php endforeach?></select></div>
<div class="field"><label>حداکثر تصاویر</label><input type="number" name="max_images" min="1" max="12" value="<?=e((string)setting('bale_product_bot_max_images','12'))?>"></div>
<div class="field"><label>گارانتی پیش‌فرض (ماه)</label><input type="number" name="default_warranty_months" min="0" max="120" value="<?=e((string)setting('bale_product_bot_default_warranty_months','0'))?>"></div>
<div class="field full"><label><input type="checkbox" name="allow_direct_publish" value="1" <?=(string)setting('bale_product_bot_allow_direct_publish','0')==='1'?'checked':''?>> اجازه انتشار مستقیم محصول از ربات</label><small>پیشنهاد امن: خاموش باشد تا محصول ابتدا پیش‌نویس ثبت و در پنل بررسی شود.</small></div>
<div class="field"><label><input type="checkbox" name="default_authenticity" value="1" <?=(string)setting('bale_product_bot_default_authenticity','0')==='1'?'checked':''?>> تضمین اصالت پیش‌فرض</label></div><div class="field"><label><input type="checkbox" name="default_safe_shipping" value="1" <?=(string)setting('bale_product_bot_default_safe_shipping','0')==='1'?'checked':''?>> تضمین ارسال سالم پیش‌فرض</label></div>
<div class="field"><label><input type="checkbox" name="order_notifications" value="1" <?=(string)setting('bale_product_bot_order_notifications','1')==='1'?'checked':''?>> اعلام فوری سفارش جدید در بله</label></div><div class="field"><label><input type="checkbox" name="payment_notifications" value="1" <?=(string)setting('bale_product_bot_payment_notifications','1')==='1'?'checked':''?>> اعلام پرداخت موفق و خطای درگاه</label></div><div class="field"><label><input type="checkbox" name="low_stock_notifications" value="1" <?=(string)setting('bale_product_bot_low_stock_notifications','1')==='1'?'checked':''?>> هشدار موجودی کم در بله</label><small>حد هشدار از تنظیمات اعلان‌های مدیریت سایت خوانده می‌شود.</small></div><div class="field full"><label><input type="checkbox" name="order_management" value="1" <?=(string)setting('bale_product_bot_order_management','1')==='1'?'checked':''?>> فعال‌بودن مدیریت سفارش از ربات</label><small>مدیران مجاز می‌توانند جزئیات مشتری، وضعیت سفارش، شرکت حمل و کد رهگیری را از دکمه‌های شیشه‌ای مدیریت کنند.</small></div>
<div class="field full"><label>آدرس Webhook</label><div class="codebox" dir="ltr"><?=e($webhookUrl)?></div><small>این آدرس دارای کلید محرمانه است؛ آن را عمومی نکنید.</small></div>
</div><button class="btn btn-primary">ذخیره و نصب خودکار ربات</button></form>
<div class="card"><h2>کنترل و آزمایش</h2><div class="admin-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test"><label><input type="checkbox" name="send_test" value="1"> ارسال پیام آزمایشی به مدیران مجاز</label> <button class="btn btn-secondary">آزمایش اتصال</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="pair_code"><button class="btn btn-secondary">ساخت کد اتصال مدیر</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="stop"><button class="btn btn-danger">توقف ربات</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reset_sessions"><button class="btn btn-secondary">پاک‌کردن فرایندهای نیمه‌تمام</button></form></div>
<?php if(is_array($webhookInfo)):?><div class="codebox" dir="ltr" style="margin-top:16px;white-space:pre-wrap"><?=e(json_encode($webhookInfo,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))?></div><?php endif?></div>
<div class="card"><h2>امکانات و روند کار ربات</h2><ol><li>افزودن و ویرایش محصول، قیمت، موجودی، ویژگی، گارانتی و جشنواره</li><li>افزودن، ویرایش و حذف امن دسته‌بندی و برند</li><li>مدیریت گالری محصول: افزودن، حذف و تعیین تصویر اصلی</li><li>مدیریت سفارش، شرکت حمل و کد رهگیری با کنترل پرداخت</li><li>بررسی و تأیید/رد اولیه درخواست‌های مرجوعی؛ مراحل انبار و بازپرداخت حساس از پنل سایت ادامه پیدا می‌کند</li><li>مشاهده و پاسخ به تیکت‌های مشتری و بستن یا حل‌کردن تیکت</li><li>گزارش فروش روزانه و محصولات پرفروش امروز</li><li>هشدار خودکار موجودی کم و ویرایش سریع قیمت/موجودی</li><li>اعلام فوری سفارش جدید، پرداخت موفق و هشدار خطای درگاه</li><li>تغییر درصد افزایش قیمت ترب‌پی از داخل ربات برای مدیر دارای دسترسی پرداخت</li><li>دکمه «⏳ تخفیف محدود» برای ساخت تخفیف ۱ تا ۳ روزه، انتخاب کانال فروشگاه/ترب‌پی/اسنپ‌پی و مشاهده یا لغو تخفیف با بازگشت فوری قیمت اصلی</li><li>بخش «🔗 لینک محصولات» برای انتخاب هر محصول و دریافت لینک مستقیم صفحه همان محصول در سایت</li><li>لغو یکپارچه در تمام کیبوردهای معمولی و شیشه‌ای و پاک‌سازی اطلاعات موقت عملیات</li></ol><p class="muted">لغو عملیات نیمه‌کاره فقط اطلاعات موقت را پاک می‌کند؛ اما گزینه «لغو و بازگشت قیمت» در بخش تخفیف محدود، کمپین ذخیره‌شده را غیرفعال و قیمت اصلی را فوراً فعال می‌کند. عملیات مالی حساس مانند بازپرداخت نهایی مرجوعی همچنان در پنل سایت انجام می‌شود.</p></div>
<div class="admin-title"><h2>آخرین محصولات ثبت‌شده با ربات</h2></div><div class="table-wrap"><table><thead><tr><th>محصول</th><th>SKU</th><th>شناسه بله</th><th>زمان</th></tr></thead><tbody><?php foreach($history as $row):?><tr><td><?php if($row['product_id']):?><a href="<?=e(app_url('admin/product-edit.php?id='.(int)$row['product_id']))?>"><?=e((string)($row['title']?:'محصول حذف‌شده'))?></a><?php else:?>محصول حذف‌شده<?php endif?></td><td><?=e($row['sku'])?></td><td dir="ltr"><?=e($row['user_id'])?></td><td><?=e(jalali_format($row['created_at']))?></td></tr><?php endforeach?><?php if(!$history):?><tr><td colspan="4">هنوز محصولی از ربات ثبت نشده است.</td></tr><?php endif?></tbody></table></div>
<?php require __DIR__.'/partials/footer.php';
