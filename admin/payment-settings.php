<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
admin_required();
$adminPage='payments';$adminTitle='تنظیمات درگاه‌های پرداخت';

function payment_settings_secret_will_exist(string $settingKey,string $postKey,string $clearKey): bool
{
    $new=trim(scalar_string($_POST[$postKey]??''));
    if($new!=='')return true;
    if(isset($_POST[$clearKey]))return false;
    return reveal_secret((string)setting($settingKey,''))!=='';
}

function payment_settings_validate_api_base(string $url,string $label): string
{
    $url=rtrim(trim($url),'/');
    if($url!==''&&!preg_match('#^[a-z][a-z0-9+.-]*://#i',$url))$url='https://'.$url;
    if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'||trim((string)parse_url($url,PHP_URL_HOST))==='')throw new RuntimeException('آدرس API '.$label.' باید یک HTTPS URL معتبر باشد (مثال: https://api.snapppay.ir).');
    if(parse_url($url,PHP_URL_QUERY)!==null||parse_url($url,PHP_URL_FRAGMENT)!==null)throw new RuntimeException('در Base URL '.$label.' Query یا Fragment وارد نکنید.');
    return $url;
}

if(is_post()){
    verify_csrf();
    $action=scalar_string($_POST['action']??'save','save');
    try{
        if($action==='save'){
            $zibalEnabled=isset($_POST['payment_gateway_enabled'])?'1':'0';
            $manual=isset($_POST['payment_manual_enabled'])?'1':'0';
            $mode=in_array(scalar_string($_POST['payment_gateway_mode']??'sandbox'),['sandbox','live'],true)?scalar_string($_POST['payment_gateway_mode']):'sandbox';
            $zibalTitle=trim(scalar_string($_POST['payment_gateway_title']??'پرداخت آنلاین زیبال'));
            $reservation=max(5,min(1440,scalar_int($_POST['payment_reservation_minutes']??30,30)));
            $manualReservation=max(30,min(10080,scalar_int($_POST['manual_payment_reservation_minutes']??1440,1440)));
            if(text_length($zibalTitle)<3||text_length($zibalTitle)>120)throw new RuntimeException('عنوان زیبال باید بین ۳ تا ۱۲۰ کاراکتر باشد.');
            $merchant=trim(scalar_string($_POST['payment_merchant_id']??''));
            if($merchant!==''&&!preg_match('/^[A-Za-z0-9_-]{3,100}$/',$merchant))throw new RuntimeException('مرچنت زیبال معتبر نیست.');
            $oldMerchant=reveal_secret((string)setting('payment_merchant_id_enc',''));
            $willHaveMerchant=$mode==='sandbox'||$merchant!==''||(!isset($_POST['clear_merchant'])&&$oldMerchant!=='');
            if($zibalEnabled==='1'&&!$willHaveMerchant)throw new RuntimeException('برای فعال‌سازی زیبال واقعی، مرچنت را وارد کنید.');

            $torobEnabled=isset($_POST['torobpay_enabled'])?'1':'0';
            $torobTitle=trim(scalar_string($_POST['torobpay_title']??'پرداخت اعتباری ترب‌پی'));
            $torobApi=payment_settings_validate_api_base(scalar_string($_POST['torobpay_api_url']??'https://cpg.torobpay.com'),'ترب‌پی');
            $torobSettle='1';
            $torobMarkup=torobpay_normalize_markup_percent(scalar_string($_POST['torobpay_markup_percent']??'0'));
            if(text_length($torobTitle)<3||text_length($torobTitle)>120)throw new RuntimeException('عنوان ترب‌پی باید بین ۳ تا ۱۲۰ کاراکتر باشد.');
            $torobFields=[
                ['torobpay_client_id_enc','torobpay_client_id','clear_torobpay_client_id','Client ID'],
                ['torobpay_client_secret_enc','torobpay_client_secret','clear_torobpay_client_secret','Client Secret'],
                ['torobpay_username_enc','torobpay_username','clear_torobpay_username','Username'],
                ['torobpay_password_enc','torobpay_password','clear_torobpay_password','Password'],
            ];
            if($torobEnabled==='1')foreach($torobFields as [$settingKey,$postKey,$clearKey,$label])if(!payment_settings_secret_will_exist($settingKey,$postKey,$clearKey))throw new RuntimeException('برای فعال‌سازی ترب‌پی، '.$label.' را وارد کنید.');

            $snappEnabled=isset($_POST['snappay_enabled'])?'1':'0';
            $snappTitle=trim(scalar_string($_POST['snappay_title']??'پرداخت اقساطی اسنپ‌پی'));
            $snappApi=payment_settings_validate_api_base(scalar_string($_POST['snappay_api_url']??'https://api.snapppay.ir'),'اسنپ‌پی');
            $snappSettle='1';
            $snappMethodTypes=strtoupper(trim(scalar_string($_POST['snappay_payment_method_types']??'INSTALLMENT')));
            if($snappMethodTypes!==''&&!preg_match('/^(?:POSTPAID|INSTALLMENT|FINANCING)(?:[\s,;|]+(?:POSTPAID|INSTALLMENT|FINANCING))*$/',$snappMethodTypes))throw new RuntimeException('روش‌های پرداخت اسنپ‌پی فقط می‌تواند POSTPAID، INSTALLMENT یا FINANCING باشد.');
            if(text_length($snappTitle)<3||text_length($snappTitle)>120)throw new RuntimeException('عنوان اسنپ‌پی باید بین ۳ تا ۱۲۰ کاراکتر باشد.');
            $snappFields=[
                ['snappay_client_id_enc','snappay_client_id','clear_snappay_client_id','Client ID'],
                ['snappay_client_secret_enc','snappay_client_secret','clear_snappay_client_secret','Client Secret'],
                ['snappay_username_enc','snappay_username','clear_snappay_username','Username'],
                ['snappay_password_enc','snappay_password','clear_snappay_password','Password'],
            ];
            if($snappEnabled==='1')foreach($snappFields as [$settingKey,$postKey,$clearKey,$label])if(!payment_settings_secret_will_exist($settingKey,$postKey,$clearKey))throw new RuntimeException('برای فعال‌سازی اسنپ‌پی، '.$label.' را وارد کنید.');

            if($zibalEnabled!=='1'&&$torobEnabled!=='1'&&$snappEnabled!=='1'&&$manual!=='1')throw new RuntimeException('حداقل یکی از روش‌های زیبال، ترب‌پی، اسنپ‌پی یا هماهنگی پرداخت باید فعال باشد.');

            $pdo=db();$pdo->beginTransaction();
            $stmt=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach([
                'payment_gateway'=>'zibal','payment_gateway_enabled'=>$zibalEnabled,'payment_gateway_mode'=>$mode,'payment_gateway_title'=>$zibalTitle,
                'payment_manual_enabled'=>$manual,'payment_reservation_minutes'=>(string)$reservation,'manual_payment_reservation_minutes'=>(string)$manualReservation,
                'torobpay_enabled'=>$torobEnabled,'torobpay_title'=>$torobTitle,'torobpay_api_url'=>$torobApi,'torobpay_settle_enabled'=>$torobSettle,'torobpay_markup_percent'=>$torobMarkup,
                'snappay_enabled'=>$snappEnabled,'snappay_title'=>$snappTitle,'snappay_api_url'=>$snappApi,'snappay_settle_enabled'=>$snappSettle,'snappay_payment_method_types'=>$snappMethodTypes,'snappay_adapter_ready'=>'1',
            ] as $key=>$value)$stmt->execute([$key,$value]);
            if(isset($_POST['clear_merchant']))$stmt->execute(['payment_merchant_id_enc','']);
            if($merchant!=='')$stmt->execute(['payment_merchant_id_enc',protect_secret($merchant)]);
            foreach(array_merge($torobFields,$snappFields) as [$settingKey,$postKey,$clearKey]){
                $value=trim(scalar_string($_POST[$postKey]??''));
                if(isset($_POST[$clearKey]))$stmt->execute([$settingKey,'']);
                if($value!=='')$stmt->execute([$settingKey,protect_secret($value)]);
            }
            $pdo->commit();
            flash('success','تنظیمات پرداخت ذخیره شد.');
        }elseif($action==='test_zibal'||$action==='test'){
            $merchant=trim(scalar_string($_POST['test_merchant']??''));if($merchant==='')$merchant=payment_gateway_merchant_id();
            $result=zibal_test_connection($merchant);flash('success','ارتباط با زیبال موفق بود. trackId آزمایشی: '.fa_number($result['track_id']));
        }elseif($action==='test_torobpay'){
            torobpay_test_connection();flash('success','احراز هویت JSON و دریافت Access Token از ترب‌پی مطابق سند CPG موفق بود.');
        }elseif($action==='test_snappay'){
            snappay_test_connection();flash('success','احراز هویت و دریافت Access Token از اسنپ‌پی موفق بود.');
        }
    }catch(Throwable $e){
        if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
        log_error($e);flash('error',$e->getMessage());
    }
    redirect(app_url('admin/payment-settings.php'));
}
$zibalCallback=app_url('payment-callback.php');$torobCallback=app_url('torobpay-callback.php');$snappCallback=app_url('snappay-callback.php');
$maskedZibal=reveal_secret((string)setting('payment_merchant_id_enc',''))!==''?'••••••••••••••••':'';
$torobMasks=[];foreach(['client_id','client_secret','username','password'] as $part)$torobMasks[$part]=reveal_secret((string)setting('torobpay_'.$part.'_enc',''))!==''?'••••••••••••••••':'';
$snappMasks=[];foreach(['client_id','client_secret','username','password'] as $part)$snappMasks[$part]=reveal_secret((string)setting('snappay_'.$part.'_enc',''))!==''?'••••••••••••••••':'';
require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><div><h1>درگاه‌های پرداخت</h1><p class="muted">زیبال، ترب‌پی و اسنپ‌پی مستقل از هم قابل فعال‌سازی هستند.</p></div><a class="btn btn-secondary" href="<?=e(app_url('admin/payments.php'))?>">تراکنش‌ها</a></div>
<div class="flash success"><strong>ماژول‌های پرداخت نصب هستند.</strong> نسخه برنامه: <?=e(app_version())?> — تنظیمات اسنپ‌پی پایین همین صفحه قرار دارد.</div>
<form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="save">
<h2>تنظیمات عمومی</h2><div class="form-grid">
<div class="field"><label><input type="checkbox" name="payment_manual_enabled" value="1" <?=(string)setting('payment_manual_enabled','1')==='1'?'checked':''?>> روش «هماهنگی پرداخت» فعال باشد</label></div>
<div class="field"><label>مهلت پرداخت آنلاین و رزرو موجودی، دقیقه</label><input type="number" name="payment_reservation_minutes" min="5" max="1440" value="<?=e(setting('payment_reservation_minutes','30'))?>"></div>
<div class="field"><label>مهلت هماهنگی پرداخت، دقیقه</label><input type="number" name="manual_payment_reservation_minutes" min="30" max="10080" value="<?=e(setting('manual_payment_reservation_minutes','1440'))?>"></div>
</div>
<hr style="margin:24px 0"><h2>زیبال</h2><div class="form-grid">
<div class="field"><label><input type="checkbox" name="payment_gateway_enabled" value="1" <?=(string)setting('payment_gateway_enabled','0')==='1'?'checked':''?>> فعال‌سازی زیبال</label></div>
<div class="field"><label>محیط زیبال</label><select name="payment_gateway_mode"><option value="sandbox" <?=payment_gateway_mode()==='sandbox'?'selected':''?>>آزمایشی</option><option value="live" <?=payment_gateway_mode()==='live'?'selected':''?>>واقعی</option></select></div>
<div class="field"><label>عنوان نمایشی</label><input name="payment_gateway_title" maxlength="120" value="<?=e(setting('payment_gateway_title','پرداخت آنلاین زیبال'))?>"></div>
<div class="field full"><label>Merchant زیبال</label><input type="password" name="payment_merchant_id" dir="ltr" autocomplete="new-password" placeholder="<?=$maskedZibal?:'مرچنت دریافتی از پنل زیبال'?>"><small>خالی = حفظ مقدار قبلی</small><label><input type="checkbox" name="clear_merchant" value="1"> حذف مرچنت ذخیره‌شده</label></div>
<div class="field full"><label>Callback زیبال</label><div class="codebox"><?=e($zibalCallback)?></div></div>
</div>
<hr style="margin:24px 0"><section id="torobpay"><h2>ترب‌پی</h2><p class="muted">ترب‌پی مستقل از زیبال و اسنپ‌پی باقی می‌ماند.</p><div class="form-grid">
<div class="field"><label><input type="checkbox" name="torobpay_enabled" value="1" <?=(string)setting('torobpay_enabled','0')==='1'?'checked':''?>> فعال‌سازی ترب‌پی</label></div>
<div class="field"><label>عنوان نمایشی</label><input name="torobpay_title" maxlength="120" value="<?=e(setting('torobpay_title','پرداخت اعتباری ترب‌پی'))?>"></div>
<div class="field"><label>درصد افزایش قیمت محصولات در ترب‌پی</label><input type="number" name="torobpay_markup_percent" min="0" max="100" step="0.01" inputmode="decimal" value="<?=e(torobpay_markup_percent())?>"><small>مثلاً ۱۰ یعنی قیمت هر محصول فقط در سفارش ترب‌پی ۱۰٪ افزایش پیدا می‌کند.</small></div>
<div class="field full"><label>TorobPay API Base URL</label><input name="torobpay_api_url" dir="ltr" maxlength="255" value="<?=e(setting('torobpay_api_url','https://cpg.torobpay.com'))?>"></div>
<div class="field"><label>Client ID</label><input type="password" name="torobpay_client_id" dir="ltr" autocomplete="new-password" placeholder="<?=$torobMasks['client_id']?:'Client ID'?>"><label><input type="checkbox" name="clear_torobpay_client_id" value="1"> حذف</label></div>
<div class="field"><label>Client Secret</label><input type="password" name="torobpay_client_secret" dir="ltr" autocomplete="new-password" placeholder="<?=$torobMasks['client_secret']?:'Client Secret'?>"><label><input type="checkbox" name="clear_torobpay_client_secret" value="1"> حذف</label></div>
<div class="field"><label>Username</label><input type="password" name="torobpay_username" dir="ltr" autocomplete="new-password" placeholder="<?=$torobMasks['username']?:'Username'?>"><label><input type="checkbox" name="clear_torobpay_username" value="1"> حذف</label></div>
<div class="field"><label>Password</label><input type="password" name="torobpay_password" dir="ltr" autocomplete="new-password" placeholder="<?=$torobMasks['password']?:'Password'?>"><label><input type="checkbox" name="clear_torobpay_password" value="1"> حذف</label></div>
<div class="field full"><input type="hidden" name="torobpay_settle_enabled" value="1"><label><input type="checkbox" value="1" checked disabled> بعد از Verify، درخواست Settle اجرا شود</label><small>این مرحله برای چرخه استاندارد ترب‌پی الزامی است؛ سفارش فقط پس از Settle موفق پرداخت‌شده ثبت می‌شود.</small></div>
<div class="field full"><label>Callback ترب‌پی</label><div class="codebox"><?=e($torobCallback)?></div></div>
</div></section>
<hr style="margin:24px 0"><section id="snappay"><h2>اسنپ‌پی</h2><p class="muted">اطلاعات بخش «توسعه فنی ← دسترسی‌های فنی» پرتال اسنپ‌پی را وارد کنید. IP سرور نیز باید نزد اسنپ‌پی ثبت شده باشد.</p><div class="form-grid">
<div class="field"><label><input type="checkbox" name="snappay_enabled" value="1" <?=(string)setting('snappay_enabled','0')==='1'?'checked':''?>> فعال‌سازی اسنپ‌پی</label></div>
<div class="field"><label>عنوان نمایشی</label><input name="snappay_title" maxlength="120" value="<?=e(setting('snappay_title','پرداخت اقساطی اسنپ‌پی'))?>"></div>
<div class="field full"><label>SnappPay API Base URL (Staging / Production)</label><input name="snappay_api_url" dir="ltr" maxlength="255" placeholder="https://api.snapppay.ir" value="<?=e(setting('snappay_api_url','https://api.snapppay.ir'))?>"><small>آدرس کامل با <code>https://</code> مثل <code>https://api.snapppay.ir</code>؛ اگر در پرتال اسنپ‌پی Base URL دیگری برای پذیرنده شما نمایش داده شده، همان مقدار را وارد کنید.</small></div>
<div class="field"><label>Client ID</label><input type="password" name="snappay_client_id" dir="ltr" autocomplete="new-password" placeholder="<?=$snappMasks['client_id']?:'Client ID'?>"><label><input type="checkbox" name="clear_snappay_client_id" value="1"> حذف</label></div>
<div class="field"><label>Client Secret</label><input type="password" name="snappay_client_secret" dir="ltr" autocomplete="new-password" placeholder="<?=$snappMasks['client_secret']?:'Client Secret'?>"><label><input type="checkbox" name="clear_snappay_client_secret" value="1"> حذف</label></div>
<div class="field"><label>Username</label><input type="password" name="snappay_username" dir="ltr" autocomplete="new-password" placeholder="<?=$snappMasks['username']?:'Username'?>"><label><input type="checkbox" name="clear_snappay_username" value="1"> حذف</label></div>
<div class="field"><label>Password</label><input type="password" name="snappay_password" dir="ltr" autocomplete="new-password" placeholder="<?=$snappMasks['password']?:'Password'?>"><label><input type="checkbox" name="clear_snappay_password" value="1"> حذف</label></div>
<div class="field full"><input type="hidden" name="snappay_settle_enabled" value="1"><label><input type="checkbox" value="1" checked disabled> بعد از Verify، Settle اجرا شود</label><small>Settle طبق الزامات اسنپ‌پی اجباری است و در این نسخه قابل غیرفعال‌کردن نیست.</small></div>
<div class="field full"><label>Payment Method Types برای Eligible / Token</label><input name="snappay_payment_method_types" dir="ltr" maxlength="80" value="<?=e(setting('snappay_payment_method_types','INSTALLMENT'))?>" placeholder="INSTALLMENT"><small>مقادیر مجاز: INSTALLMENT، FINANCING، POSTPAID. چند مقدار را با کاما جدا کنید. فقط گزینه‌هایی را وارد کنید که اسنپ‌پی برای پذیرنده فعال کرده است.</small></div>
<div class="field full"><label>Callback اسنپ‌پی</label><div class="codebox"><?=e($snappCallback)?></div><small>همین آدرس ثابت را در فرم فنی اسنپ‌پی ثبت کنید: بدون Query String و بدون پارامتر اضافه.</small></div>
</div></section>
<button class="btn btn-primary" style="margin-top:18px">ذخیره همه تنظیمات</button></form>
<div class="card" style="margin-top:20px"><h2>تست اتصال زیبال</h2><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="test_zibal"><input name="test_merchant" dir="ltr" placeholder="خالی = مرچنت ذخیره‌شده"><button class="btn btn-secondary">تست زیبال</button></form></div>
<div class="card" style="margin-top:20px"><h2>تست اتصال ترب‌پی</h2><p class="muted">این تست OAuth را با Basic Auth و بدنه JSON طبق سند CPG بررسی می‌کند و خریدی ایجاد نمی‌کند.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test_torobpay"><button class="btn btn-secondary">تست احراز هویت ترب‌پی</button></form></div>
<div class="card" style="margin-top:20px"><h2>تست اتصال اسنپ‌پی</h2><p class="muted">ابتدا اطلاعات اسنپ‌پی را ذخیره کنید. این تست فقط دریافت Access Token را بررسی می‌کند و هیچ سفارش یا پرداختی ایجاد نمی‌کند.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test_snappay"><button class="btn btn-secondary">تست احراز هویت اسنپ‌پی</button></form></div>
<div class="card" style="margin-top:20px"><h2>نکات امنیتی</h2><ul class="clean-list"><li>زیبال، ترب‌پی و اسنپ‌پی مستقل‌اند.</li><li>Client Secret، Username و Password ترب‌پی/اسنپ‌پی به‌صورت رمز‌شده در settings ذخیره می‌شوند.</li><li>سفارش‌های آنلاین فقط بعد از تأیید سمت سرور همان درگاه پرداخت‌شده ثبت می‌شوند.</li><li>برای سفارش Settle‌شده اسنپ‌پی، Update و Cancel فقط از کنترل اختصاصی داخل صفحه سفارش و پس از تأیید مجدد مدیر اجرا می‌شوند.</li><li>Get Payment Status برای تطبیق خودکار تراکنش‌های Pending در Cron استفاده می‌شود.</li></ul></div>
<?php require __DIR__.'/partials/footer.php';
