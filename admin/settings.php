<?php
require dirname(__DIR__).'/app/bootstrap.php';
admin_required();
$keys=['site_url','store_name','store_tagline','phone','mobile','email','address',
'support_phone','support_mobile','support_email','support_hours','support_whatsapp_url','support_bale_url','support_description',
'sales_phone','sales_mobile','sales_email','sales_hours','sales_whatsapp_url','sales_bale_url','sales_description',
'enamad_html','business_license_html','google_client_id','seo_default_title','seo_default_description','seo_default_image','seo_logo_image'];
$featureKeys=['reviews_enabled','questions_enabled','wishlist_enabled','compare_enabled','coupons_enabled','abandoned_cart_enabled','sms_marketing_enabled','gallery_enabled','product_alerts_enabled','tickets_enabled','invoice_pdf_enabled','google_auth_enabled','blog_enabled','seo_indexing_enabled'];
$errors=[];
if(is_post()){
    verify_csrf();
    $input=[];
    foreach($keys as $key) $input[$key]=trim(scalar_string($_POST[$key]??''));
    foreach($featureKeys as $key)$input[$key]=isset($_POST[$key])?'1':'0';
    $input['abandoned_cart_minutes']=(string)max(5,min(10080,scalar_int($_POST['abandoned_cart_minutes']??30,30)));
    $input['site_url']=rtrim($input['site_url'],'/');
    if(!valid_site_url($input['site_url'])) $errors[]='آدرس سایت باید کامل، بدون پارامتر اضافی و با http یا https باشد.';
    if(text_length($input['store_name'])<2 || text_length($input['store_name'])>120) $errors[]='نام فروشگاه باید بین ۲ تا ۱۲۰ کاراکتر باشد.';
    if(text_length($input['store_tagline'])>180) $errors[]='شعار فروشگاه حداکثر ۱۸۰ کاراکتر است.';
    if(text_length($input['phone'])>30 || text_length($input['mobile'])>30) $errors[]='شماره‌های تماس بیش از حد طولانی هستند.';
    if(!preg_match('/^09[0-9]{9}$/',normalize_digits($input['mobile']))) $errors[]='موبایل فروشگاه باید با ۰۹ شروع شود و ۱۱ رقم باشد.';
    if($input['phone']!==''&&!preg_match('/^[0-9۰-۹٠-٩+() -]{7,30}$/u',$input['phone'])) $errors[]='تلفن ثابت معتبر نیست.';
    if(text_length($input['email'])>190 || ($input['email']!=='' && !filter_var($input['email'],FILTER_VALIDATE_EMAIL))) $errors[]='ایمیل واردشده معتبر نیست.';
    foreach(['support_phone'=>'تلفن پشتیبانی مشتریان','sales_phone'=>'تلفن فروش'] as $key=>$label){
        if(text_length($input[$key])>30 || ($input[$key]!==''&&!preg_match('/^[0-9۰-۹٠-٩+() -]{7,30}$/u',$input[$key]))) $errors[]=$label.' معتبر نیست.';
    }
    foreach(['support_mobile'=>'موبایل پشتیبانی مشتریان','sales_mobile'=>'موبایل فروش'] as $key=>$label){
        if($input[$key]!==''&&!preg_match('/^09[0-9]{9}$/',normalize_digits($input[$key]))) $errors[]=$label.' باید با ۰۹ شروع شود و ۱۱ رقم باشد.';
    }
    foreach(['support_email'=>'ایمیل پشتیبانی مشتریان','sales_email'=>'ایمیل فروش'] as $key=>$label){
        if(text_length($input[$key])>190 || ($input[$key]!==''&&!filter_var($input[$key],FILTER_VALIDATE_EMAIL))) $errors[]=$label.' معتبر نیست.';
    }
    foreach(['support_hours'=>'ساعات پاسخگویی پشتیبانی','sales_hours'=>'ساعات پاسخگویی فروش'] as $key=>$label) if(text_length($input[$key])>180) $errors[]=$label.' حداکثر ۱۸۰ کاراکتر است.';
    foreach(['support_description'=>'توضیحات پشتیبانی مشتریان','sales_description'=>'توضیحات فروش'] as $key=>$label) if(text_length($input[$key])>1000) $errors[]=$label.' حداکثر ۱۰۰۰ کاراکتر است.';
    foreach(['support_whatsapp_url'=>'لینک واتساپ پشتیبانی','support_bale_url'=>'لینک بله پشتیبانی','sales_whatsapp_url'=>'لینک واتساپ فروش','sales_bale_url'=>'لینک بله فروش'] as $key=>$label){
        if(text_length($input[$key])>500 || ($input[$key]!==''&&!safe_external_url($input[$key]))) $errors[]=$label.' باید یک آدرس HTTPS معتبر باشد.';
    }
    if(text_length($input['address'])<5 || text_length($input['address'])>1000) $errors[]='نشانی واقعی فروشگاه الزامی است و حداکثر ۱۰۰۰ کاراکتر دارد.';
    if(strlen($input['enamad_html'])>20000 || strlen($input['business_license_html'])>20000) $errors[]='کد مجوز واردشده بیش از حد طولانی است.';
    if(text_length($input['seo_default_title'])>255 || text_length($input['seo_default_description'])>320) $errors[]='عنوان یا توضیحات پیش‌فرض سئو بیش از حد طولانی است.';
    foreach(['seo_default_image','seo_logo_image'] as $imageKey)if($input[$imageKey]!==''&&!safe_external_url($input[$imageKey])&&!safe_internal_image_path($input[$imageKey]))$errors[]='مسیر تصویر سئو باید یک تصویر داخلی معتبر یا آدرس HTTPS باشد.';
    $googleSecret=trim(scalar_string($_POST['google_client_secret']??''));
    $currentGoogleSecret=customer_google_client_secret();
    if($input['google_client_id']!==''&&!preg_match('/^[A-Za-z0-9._-]{20,255}\.apps\.googleusercontent\.com$/',$input['google_client_id']))$errors[]='Client ID گوگل معتبر نیست.';
    if(strlen($googleSecret)>500)$errors[]='Client Secret گوگل بیش از حد طولانی است.';
    if($input['google_auth_enabled']==='1'){
        $siteHost=(string)(parse_url($input['site_url'],PHP_URL_HOST)??'');
        if($input['google_client_id']===''||($googleSecret===''&&$currentGoogleSecret===''))$errors[]='برای فعال‌کردن ورود با Google، Client ID و Client Secret را کامل کنید.';
        if(!str_starts_with($input['site_url'],'https://')&&!in_array($siteHost,['localhost','127.0.0.1','::1'],true))$errors[]='ورود با Google روی سایت اصلی به HTTPS نیاز دارد.';
    }
    $currentPassword=scalar_string($_POST['current_password']??'');
    $newPassword=scalar_string($_POST['new_password']??'');
    $newPasswordConfirm=scalar_string($_POST['new_password_confirm']??'');
    if($newPassword!=='' && !admin_password_is_strong($newPassword)) $errors[]='رمز جدید باید حداقل ۱۲ کاراکتر و شامل رقم و نویسه غیرعددی باشد.';
    if($newPassword!=='' && $newPassword!==$newPasswordConfirm) $errors[]='تکرار رمز جدید با رمز اصلی یکسان نیست.';
    if($newPassword!=='' && !$errors){
        $passwordStatement=db()->prepare('SELECT password_hash FROM admins WHERE id=? AND is_active=1');
        $passwordStatement->execute([admin_user()['id']]);
        $passwordHash=$passwordStatement->fetchColumn();
        if(!is_string($passwordHash) || !password_verify($currentPassword,$passwordHash)) $errors[]='رمز فعلی مدیر صحیح نیست.';
    }
    if(!$errors){
        $pdo=db();
        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach($input as $key=>$value) $stmt->execute([$key,$value]);
            if($googleSecret!=='')$stmt->execute(['google_client_secret_enc',protect_secret($googleSecret)]);
            if($newPassword!==''){$pdo->prepare('UPDATE admins SET password_hash=?,last_password_change_at=NOW() WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),admin_user()['id']]);$_SESSION['admin_authenticated_at']=time();}
            $pdo->commit();
            flash('success','تنظیمات فروشگاه ذخیره شد.'.($newPassword!==''?' رمز مدیر نیز تغییر کرد.':''));
            redirect(app_url('admin/settings.php'));
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            log_error($e);$errors[]='ذخیره تنظیمات انجام نشد. دوباره تلاش کنید.';
        }
    }
}
$vals=[];
foreach(array_merge($keys,$featureKeys,['abandoned_cart_minutes']) as $key) $vals[$key]=is_post()?($key==='abandoned_cart_minutes'?max(5,min(10080,scalar_int($_POST[$key]??30,30))):(in_array($key,$featureKeys,true)?(isset($_POST[$key])?'1':'0'):trim(scalar_string($_POST[$key]??'')))):setting($key);
$feed=app_url('api/torob-feed.php?key='.rawurlencode((string)setting('torob_feed_key')));
$torobV3=torob_api_v3_endpoint_url();
$adminPage='settings';$adminTitle='تنظیمات فروشگاه';
require __DIR__.'/partials/header.php';
?>
<div class="admin-title"><h1>تنظیمات فروشگاه</h1></div>
<?php foreach($errors as $error):?><div class="flash error"><?=e($error)?></div><?php endforeach?>
<form method="post"><?=csrf_field()?>
<div class="card"><div class="form-grid">
<div class="field full"><label>آدرس کامل سایت</label><input name="site_url" dir="ltr" value="<?=e($vals['site_url'])?>" required></div>
<div class="field"><label>نام فروشگاه</label><input name="store_name" maxlength="120" value="<?=e($vals['store_name'])?>" required></div>
<div class="field"><label>شعار کوتاه</label><input name="store_tagline" maxlength="180" value="<?=e($vals['store_tagline'])?>"></div>
<div class="field"><label>تلفن</label><input name="phone" maxlength="30" value="<?=e($vals['phone'])?>"></div>
<div class="field"><label>موبایل</label><input name="mobile" dir="ltr" inputmode="numeric" pattern="09[0-9]{9}" minlength="11" maxlength="11" value="<?=e($vals['mobile'])?>" required></div>
<div class="field"><label>ایمیل</label><input type="email" name="email" maxlength="190" value="<?=e($vals['email'])?>"></div>
<div class="field full"><label>نشانی فروشگاه</label><textarea name="address" minlength="5" maxlength="1000" rows="3" required><?=e($vals['address'])?></textarea></div>
<div class="field full"><div class="flash success">ارسال عادی فروشگاه پس‌کرایه است. ارسال رایگان و تغییر قیمت‌ها از بخش «جشنواره‌ها» مدیریت می‌شود.</div></div>
<div class="field full"><div class="flash success"><strong>درگاه‌های پرداخت:</strong> زیبال، ترب‌پی و اسنپ‌پی در ماژول پرداخت قابل تنظیم هستند. <a class="btn btn-secondary btn-sm" href="<?=e(app_url('admin/payment-settings.php'))?>">تنظیم درگاه‌های پرداخت</a></div></div>
<div class="field full"><label>کد HTML اینماد</label><textarea name="enamad_html" maxlength="20000" rows="3"><?=e($vals['enamad_html'])?></textarea></div>
<div class="field full"><label>کد مجوز دیگر</label><textarea name="business_license_html" maxlength="20000" rows="3"><?=e($vals['business_license_html'])?></textarea></div>
<div class="field"><label>رمز فعلی مدیر</label><input type="password" name="current_password" maxlength="200" autocomplete="current-password"><small>فقط هنگام تغییر رمز لازم است.</small></div>
<div class="field"><label>رمز جدید مدیر</label><input type="password" name="new_password" minlength="12" maxlength="200" autocomplete="new-password"><small>حداقل ۱۲ کاراکتر شامل رقم و نویسه غیرعددی؛ برای حفظ رمز فعلی خالی بگذارید.</small></div>
<div class="field"><label>تکرار رمز جدید</label><input type="password" name="new_password_confirm" minlength="12" maxlength="200" autocomplete="new-password"></div>
</div></div>
<div class="card support-settings-card"><h2>اطلاعات پشتیبانی مشتریان</h2><p class="muted">این اطلاعات برای پیگیری سفارش، خدمات پس از فروش و پاسخ‌گویی به مشتریان استفاده می‌شود. شماره این بخش در نوار پشتیبانی سایت نمایش داده می‌شود.</p><div class="form-grid">
<div class="field"><label>تلفن پشتیبانی</label><input name="support_phone" maxlength="30" value="<?=e($vals['support_phone'])?>" placeholder="مثلاً 02112345678"></div>
<div class="field"><label>موبایل پشتیبانی</label><input name="support_mobile" dir="ltr" inputmode="numeric" maxlength="11" value="<?=e($vals['support_mobile'])?>" placeholder="09xxxxxxxxx"></div>
<div class="field"><label>ایمیل پشتیبانی</label><input type="email" name="support_email" maxlength="190" value="<?=e($vals['support_email'])?>" placeholder="support@example.com"></div>
<div class="field"><label>ساعات پاسخگویی</label><input name="support_hours" maxlength="180" value="<?=e($vals['support_hours'])?>" placeholder="مثلاً شنبه تا پنجشنبه، ۹ تا ۱۸"></div>
<div class="field"><label>لینک واتساپ پشتیبانی</label><input name="support_whatsapp_url" dir="ltr" maxlength="500" value="<?=e($vals['support_whatsapp_url'])?>" placeholder="https://wa.me/..."></div>
<div class="field"><label>لینک بله پشتیبانی</label><input name="support_bale_url" dir="ltr" maxlength="500" value="<?=e($vals['support_bale_url'])?>" placeholder="https://ble.ir/..."></div>
<div class="field full"><label>توضیحات پشتیبانی مشتریان</label><textarea name="support_description" maxlength="1000" rows="3" placeholder="مثلاً برای پیگیری سفارش، مرجوعی و خدمات پس از فروش با این واحد تماس بگیرید."><?=e($vals['support_description'])?></textarea></div>
</div></div>
<div class="card sales-settings-card"><h2>اطلاعات فروش و مشاوره خرید</h2><p class="muted">این بخش برای مشتریانی است که قبل از خرید درباره انتخاب محصول، موجودی و شرایط فروش راهنمایی می‌خواهند.</p><div class="form-grid">
<div class="field"><label>تلفن فروش</label><input name="sales_phone" maxlength="30" value="<?=e($vals['sales_phone'])?>" placeholder="مثلاً 02112345678"></div>
<div class="field"><label>موبایل فروش</label><input name="sales_mobile" dir="ltr" inputmode="numeric" maxlength="11" value="<?=e($vals['sales_mobile'])?>" placeholder="09xxxxxxxxx"></div>
<div class="field"><label>ایمیل فروش</label><input type="email" name="sales_email" maxlength="190" value="<?=e($vals['sales_email'])?>" placeholder="sales@example.com"></div>
<div class="field"><label>ساعات پاسخگویی فروش</label><input name="sales_hours" maxlength="180" value="<?=e($vals['sales_hours'])?>" placeholder="مثلاً همه‌روزه ۹ تا ۲۰"></div>
<div class="field"><label>لینک واتساپ فروش</label><input name="sales_whatsapp_url" dir="ltr" maxlength="500" value="<?=e($vals['sales_whatsapp_url'])?>" placeholder="https://wa.me/..."></div>
<div class="field"><label>لینک بله فروش</label><input name="sales_bale_url" dir="ltr" maxlength="500" value="<?=e($vals['sales_bale_url'])?>" placeholder="https://ble.ir/..."></div>
<div class="field full"><label>توضیحات فروش و مشاوره</label><textarea name="sales_description" maxlength="1000" rows="3" placeholder="مثلاً برای مشاوره انتخاب کالا و اطلاع از موجودی با واحد فروش تماس بگیرید."><?=e($vals['sales_description'])?></textarea></div>
</div></div>
<div class="card"><h2>ورود و ثبت‌نام مشتریان</h2><div class="form-grid">
<div class="field full checkboxes"><label><input type="checkbox" name="google_auth_enabled" <?=$vals['google_auth_enabled']==='1'?'checked':''?>> فعال‌سازی عملکرد ورود و ثبت‌نام با Google</label><small>دکمه Google در صفحه‌های ورود و ثبت‌نام همیشه نمایش داده می‌شود؛ برای کارکرد آن، این گزینه و اطلاعات زیر باید کامل باشند.</small></div>
<div class="field full"><label>Google OAuth Client ID</label><input name="google_client_id" dir="ltr" maxlength="255" autocomplete="off" value="<?=e($vals['google_client_id'])?>" placeholder="...apps.googleusercontent.com"></div>
<div class="field full"><label>Google OAuth Client Secret</label><input type="password" name="google_client_secret" dir="ltr" maxlength="500" autocomplete="new-password" placeholder="برای حفظ مقدار فعلی خالی بگذارید"><small>این مقدار با کلید برنامه رمزنگاری و ذخیره می‌شود.</small></div>
<div class="field full"><label>Authorized redirect URI</label><div class="codebox" dir="ltr"><?=e(customer_google_redirect_uri())?></div><small>همین آدرس را دقیقاً در Google Cloud برای Web application ثبت کنید.</small></div>
</div></div>
<div class="card"><h2>سئوی خودکار سایت</h2><div class="form-grid">
<div class="field full checkboxes"><label><input type="checkbox" name="seo_indexing_enabled" <?=$vals['seo_indexing_enabled']==='1'?'checked':''?>> اجازه ایندکس‌شدن صفحات عمومی توسط موتورهای جست‌وجو</label><small>هنگام طراحی یا انتقال سایت می‌توانید موقتاً این گزینه را خاموش کنید.</small></div>
<div class="field full"><label>عنوان پیش‌فرض سایت</label><input name="seo_default_title" maxlength="255" value="<?=e($vals['seo_default_title'])?>" placeholder="در صورت خالی‌بودن، نام فروشگاه استفاده می‌شود."></div>
<div class="field full"><label>توضیحات پیش‌فرض سئو</label><textarea name="seo_default_description" maxlength="320" rows="3" placeholder="معرفی کوتاه فروشگاه برای نتایج گوگل"><?=e($vals['seo_default_description'])?></textarea></div>
<div class="field"><label>تصویر پیش‌فرض اشتراک‌گذاری</label><input name="seo_default_image" dir="ltr" maxlength="255" value="<?=e($vals['seo_default_image'])?>" placeholder="uploads/products/... یا https://..."></div>
<div class="field"><label>تصویر لوگو برای Schema</label><input name="seo_logo_image" dir="ltr" maxlength="255" value="<?=e($vals['seo_logo_image'])?>" placeholder="uploads/products/... یا https://..."></div>
<div class="field full"><div class="codebox" dir="ltr"><?=e(app_url('sitemap.xml'))?></div><small>نقشه سایت محصولات، دسته‌ها و مقاله‌های منتشرشده به‌صورت خودکار به‌روز می‌شود.</small></div>
</div></div>
<div class="card"><h2>امکانات فروش و ارتباط با مشتری</h2><div class="form-grid"><div class="field checkboxes"><label><input type="checkbox" name="reviews_enabled" <?=$vals['reviews_enabled']==='1'?'checked':''?>> نظر و امتیاز محصول</label><label><input type="checkbox" name="questions_enabled" <?=$vals['questions_enabled']==='1'?'checked':''?>> پرسش‌وپاسخ محصول</label><label><input type="checkbox" name="wishlist_enabled" <?=$vals['wishlist_enabled']==='1'?'checked':''?>> علاقه‌مندی مشتری</label><label><input type="checkbox" name="compare_enabled" <?=$vals['compare_enabled']==='1'?'checked':''?>> مقایسه محصولات</label><label><input type="checkbox" name="gallery_enabled" <?=$vals['gallery_enabled']==='1'?'checked':''?>> گالری چندتصویری و بزرگ‌نمایی</label><label><input type="checkbox" name="blog_enabled" <?=$vals['blog_enabled']==='1'?'checked':''?>> وبلاگ و انتشار محتوا</label></div><div class="field checkboxes"><label><input type="checkbox" name="coupons_enabled" <?=$vals['coupons_enabled']==='1'?'checked':''?>> کد تخفیف</label><label><input type="checkbox" name="abandoned_cart_enabled" <?=$vals['abandoned_cart_enabled']==='1'?'checked':''?>> ثبت سبد رهاشده</label><label><input type="checkbox" name="sms_marketing_enabled" <?=$vals['sms_marketing_enabled']==='1'?'checked':''?>> پیامک گروهی مشتریان</label><label><input type="checkbox" name="product_alerts_enabled" <?=$vals['product_alerts_enabled']==='1'?'checked':''?>> هشدار موجودشدن و کاهش قیمت</label><label><input type="checkbox" name="tickets_enabled" <?=$vals['tickets_enabled']==='1'?'checked':''?>> تیکت پشتیبانی دوطرفه</label><label><input type="checkbox" name="invoice_pdf_enabled" <?=$vals['invoice_pdf_enabled']==='1'?'checked':''?>> فاکتور PDF برای مشتری</label></div><div class="field"><label>زمان تشخیص سبد رهاشده (دقیقه)</label><input type="number" name="abandoned_cart_minutes" min="5" max="10080" value="<?=e($vals['abandoned_cart_minutes'])?>"></div><div class="field"><small>خدمت نصب حضوری محصول فقط در صورت تعریف و فعال‌سازی رسمی باید به مشتری اعلام شود؛ گارانتی و مدت آن برای هر محصول جداگانه ثبت می‌شود.</small></div></div></div>
<div class="card"><h2>خروجی محصولات ترب</h2><p><strong>آدرس پیشنهادی Product API v3:</strong></p><div class="codebox" dir="ltr"><?=e($torobV3)?></div><p>قیمت در این خروجی مطابق قرارداد جدید ترب به تومان، محصولات جدید در ابتدای فهرست و هر صفحه دقیقاً شامل حداکثر ۱۰۰ ردیف است.</p><a class="btn btn-secondary btn-sm" href="<?=e(app_url('admin/torob-sync.php'))?>">بررسی سلامت و محصولات خروجی</a><details style="margin-top:14px"><summary>خروجی قدیمی برای سازگاری</summary><div class="codebox" dir="ltr"><?=e($feed)?></div><p class="muted">این مسیر حذف نشده است، اما برای اتصال تازه باید API نسخه ۳ بالا را به ترب بدهید.</p></details></div>
<button class="btn btn-primary">ذخیره تنظیمات</button></form>
<?php require __DIR__.'/partials/footer.php';
