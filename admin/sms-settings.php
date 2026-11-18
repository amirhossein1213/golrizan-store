<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
admin_required();
$adminPage='sms';
$adminTitle='تنظیمات پیامک SMS.ir';

function sms_admin_bool(string $key): string
{
    return isset($_POST[$key]) ? '1' : '0';
}

function sms_admin_clean_digits(mixed $value): string
{
    $raw=normalize_digits(trim(scalar_string($value)));
    return preg_replace('/[\s,،٬]+/u','',$raw) ?? $raw;
}

function sms_admin_template(string $key,string $label,bool $strict): string
{
    $raw=sms_admin_clean_digits($_POST[$key]??'');
    if($raw==='')return '0';
    if(!preg_match('/^\d{1,10}$/',$raw)){
        if(!$strict)return '0';
        throw new RuntimeException('شناسه قالب «'.$label.'» باید فقط عدد باشد.');
    }
    $value=(int)$raw;
    if($value<0 || $value>2147483647){
        if(!$strict)return '0';
        throw new RuntimeException('شناسه قالب «'.$label.'» معتبر نیست.');
    }
    return (string)$value;
}

function sms_admin_param(string $key,string $default,string $label,bool $strict): string
{
    $value=trim(scalar_string($_POST[$key]??''));
    if($value==='')return $default;
    // کاربر ممکن است نام متغیر را به‌شکل #CODE# یا {CODE} کپی کند.
    $value=trim($value,"#{}[]() \t\n\r\0\x0B");
    if(!preg_match('/^[A-Za-z0-9_]{1,50}$/',$value)){
        if(!$strict)return $default;
        throw new RuntimeException('نام متغیر «'.$label.'» باید مانند '.$default.' و فقط با حروف انگلیسی، عدد یا _ وارد شود.');
    }
    return $value;
}

function sms_admin_int(string $key,int $min,int $max,int $default,string $label,bool $strict=true): string
{
    $raw=sms_admin_clean_digits($_POST[$key]??(string)$default);
    if($raw==='' || !preg_match('/^\d+$/',$raw)){
        if(!$strict)return (string)$default;
        throw new RuntimeException('مقدار «'.$label.'» باید عدد صحیح باشد.');
    }
    $value=(int)$raw;
    if($value<$min || $value>$max){
        if(!$strict)return (string)$default;
        throw new RuntimeException('مقدار «'.$label.'» باید بین '.fa_number($min).' و '.fa_number($max).' باشد.');
    }
    return (string)$value;
}

function sms_admin_require_template(array $values,string $enabledKey,string $templateKey,string $label,bool $globalEnabled): void
{
    if($globalEnabled && ($values[$enabledKey]??'0')==='1' && (int)($values[$templateKey]??0)<1){
        throw new RuntimeException('در بخش «'.$label.'»، شناسه قالب SMS.ir را وارد کنید.');
    }
}


function sms_admin_route_test_payload(string $route): array
{
    return match($route){
        'otp'=>['sms_otp_template_id',[sms_param_name('sms_otp_param_code','CODE')=>'12345'],'آزمایش OTP'],
        'order_created'=>['sms_order_created_template_id',[
            sms_param_name('sms_order_param_order','Order')=>'TEST-1001',
            sms_param_name('sms_order_param_name','Name')=>'مشتری آزمایشی',
            sms_param_name('sms_order_param_amount','Amount')=>'100000',
        ],'آزمایش ثبت سفارش مشتری'],
        'admin_new_order'=>['sms_admin_new_order_template_id',[
            sms_param_name('sms_admin_param_order','Order')=>'TEST-1001',
            sms_param_name('sms_admin_param_name','Name')=>'مشتری آزمایشی',
            sms_param_name('sms_admin_param_amount','Amount')=>'100000',
        ],'آزمایش اطلاع مدیر'],
        'order_status'=>['sms_order_status_template_id',[
            sms_param_name('sms_status_param_order','Order')=>'TEST-1001',
            sms_param_name('sms_status_param_status','Status')=>'در حال بررسی',
        ],'آزمایش وضعیت سفارش'],
        'payment_status'=>['sms_payment_status_template_id',[
            sms_param_name('sms_payment_param_order','Order')=>'TEST-1001',
            sms_param_name('sms_payment_param_status','Status')=>'پرداخت‌شده',
        ],'آزمایش وضعیت پرداخت'],
        'stock_alert'=>['sms_stock_alert_template_id',[
            sms_param_name('sms_alert_param_product','Product')=>'محصول آزمایشی',
            sms_param_name('sms_alert_param_price','Price')=>'100000',
        ],'آزمایش موجودشدن محصول'],
        'price_alert'=>['sms_price_alert_template_id',[
            sms_param_name('sms_alert_param_product','Product')=>'محصول آزمایشی',
            sms_param_name('sms_alert_param_price','Price')=>'90000',
        ],'آزمایش کاهش قیمت'],
        'return_status'=>['sms_return_status_template_id',[
            sms_param_name('sms_return_param_number','Return')=>'RET-1001',
            sms_param_name('sms_return_param_status','Status')=>'در حال بررسی',
        ],'آزمایش مرجوعی'],
        'shipment_status'=>['sms_shipment_status_template_id',[
            sms_param_name('sms_shipment_param_order','Order')=>'TEST-1001',
            sms_param_name('sms_shipment_param_status','Status')=>'تحویل شرکت حمل',
            sms_param_name('sms_shipment_param_code','Code')=>'1234567890',
            sms_param_name('sms_shipment_param_carrier','Carrier')=>'پست',
        ],'آزمایش ارسال و رهگیری'],
        'ticket_reply'=>['sms_ticket_reply_template_id',[
            sms_param_name('sms_ticket_param_number','Ticket')=>'TKT-1001',
            sms_param_name('sms_ticket_param_subject','Subject')=>'پیگیری سفارش',
        ],'آزمایش پاسخ تیکت'],
        default=>throw new RuntimeException('مسیر آزمایشی انتخاب‌شده معتبر نیست.'),
    };
}

$smsFormPost=null;
$smsSaveError='';
if(is_post()){
    verify_csrf();
    $action=scalar_string($_POST['action']??'save','save');
    try{
        if($action==='save'){
            $flags=[
                'sms_enabled'=>sms_admin_bool('sms_enabled'),
                'customer_accounts_enabled'=>sms_admin_bool('customer_accounts_enabled'),
                'customer_checkout_autofill'=>sms_admin_bool('customer_checkout_autofill'),
                'sms_otp_enabled'=>sms_admin_bool('sms_otp_enabled'),
                'sms_order_created_enabled'=>sms_admin_bool('sms_order_created_enabled'),
                'sms_order_status_enabled'=>sms_admin_bool('sms_order_status_enabled'),
                'sms_payment_status_enabled'=>sms_admin_bool('sms_payment_status_enabled'),
                'sms_return_status_enabled'=>sms_admin_bool('sms_return_status_enabled'),
                'sms_shipment_status_enabled'=>sms_admin_bool('sms_shipment_status_enabled'),
                'sms_product_alerts_enabled'=>sms_admin_bool('sms_product_alerts_enabled'),
                'sms_ticket_reply_enabled'=>sms_admin_bool('sms_ticket_reply_enabled'),
                'sms_admin_new_order_enabled'=>sms_admin_bool('sms_admin_new_order_enabled'),
            ];
            $globalEnabled=$flags['sms_enabled']==='1';
            $strictOtp=$globalEnabled && $flags['sms_otp_enabled']==='1';
            $strictOrder=$globalEnabled && $flags['sms_order_created_enabled']==='1';
            $strictOrderStatus=$globalEnabled && $flags['sms_order_status_enabled']==='1';
            $strictPayment=$globalEnabled && $flags['sms_payment_status_enabled']==='1';
            $strictReturn=$globalEnabled && $flags['sms_return_status_enabled']==='1';
            $strictShipment=$globalEnabled && $flags['sms_shipment_status_enabled']==='1';
            $strictAlerts=$globalEnabled && $flags['sms_product_alerts_enabled']==='1';
            $strictTicket=$globalEnabled && $flags['sms_ticket_reply_enabled']==='1';
            $strictAdmin=$globalEnabled && $flags['sms_admin_new_order_enabled']==='1';

            $mode=scalar_string($_POST['sms_mode']??'sandbox');
            if(!in_array($mode,['sandbox','live'],true))$mode='sandbox';
            $values=$flags+[
                'sms_mode'=>$mode,
                'sms_otp_template_id'=>sms_admin_template('sms_otp_template_id','ورود با کد یک‌بارمصرف',$strictOtp),
                'sms_otp_param_code'=>sms_admin_param('sms_otp_param_code','CODE','کد ورود',$strictOtp),
                'sms_otp_length'=>sms_admin_int('sms_otp_length',4,8,5,'تعداد رقم کد',$strictOtp),
                'sms_otp_expiry_minutes'=>sms_admin_int('sms_otp_expiry_minutes',1,15,2,'زمان انقضای کد',$strictOtp),
                'sms_otp_cooldown_seconds'=>sms_admin_int('sms_otp_cooldown_seconds',30,600,60,'فاصله ارسال مجدد',$strictOtp),
                'sms_otp_max_attempts'=>sms_admin_int('sms_otp_max_attempts',3,10,5,'حداکثر تلاش کد',$strictOtp),
                'sms_order_created_template_id'=>sms_admin_template('sms_order_created_template_id','ثبت سفارش مشتری',$strictOrder),
                'sms_order_param_order'=>sms_admin_param('sms_order_param_order','Order','شماره سفارش',$strictOrder),
                'sms_order_param_name'=>sms_admin_param('sms_order_param_name','Name','نام مشتری',$strictOrder),
                'sms_order_param_amount'=>sms_admin_param('sms_order_param_amount','Amount','مبلغ سفارش',$strictOrder),
                'sms_order_status_template_id'=>sms_admin_template('sms_order_status_template_id','تغییر وضعیت سفارش',$strictOrderStatus),
                'sms_status_param_order'=>sms_admin_param('sms_status_param_order','Order','شماره سفارش',$strictOrderStatus),
                'sms_status_param_status'=>sms_admin_param('sms_status_param_status','Status','وضعیت سفارش',$strictOrderStatus),
                'sms_payment_status_template_id'=>sms_admin_template('sms_payment_status_template_id','تغییر وضعیت پرداخت',$strictPayment),
                'sms_payment_param_order'=>sms_admin_param('sms_payment_param_order','Order','شماره سفارش',$strictPayment),
                'sms_payment_param_status'=>sms_admin_param('sms_payment_param_status','Status','وضعیت پرداخت',$strictPayment),
                'sms_return_status_template_id'=>sms_admin_template('sms_return_status_template_id','مرجوعی و بازپرداخت',$strictReturn),
                'sms_return_param_number'=>sms_admin_param('sms_return_param_number','Return','شماره مرجوعی',$strictReturn),
                'sms_return_param_status'=>sms_admin_param('sms_return_param_status','Status','وضعیت مرجوعی',$strictReturn),
                'sms_shipment_status_template_id'=>sms_admin_template('sms_shipment_status_template_id','ارسال و رهگیری',$strictShipment),
                'sms_shipment_param_order'=>sms_admin_param('sms_shipment_param_order','Order','شماره سفارش مرسوله',$strictShipment),
                'sms_shipment_param_status'=>sms_admin_param('sms_shipment_param_status','Status','وضعیت ارسال',$strictShipment),
                'sms_shipment_param_code'=>sms_admin_param('sms_shipment_param_code','Code','کد رهگیری',$strictShipment),
                'sms_shipment_param_carrier'=>sms_admin_param('sms_shipment_param_carrier','Carrier','شرکت حمل',$strictShipment),
                'sms_stock_alert_template_id'=>sms_admin_template('sms_stock_alert_template_id','موجودشدن محصول',$strictAlerts),
                'sms_price_alert_template_id'=>sms_admin_template('sms_price_alert_template_id','کاهش قیمت محصول',$strictAlerts),
                'sms_alert_param_product'=>sms_admin_param('sms_alert_param_product','Product','نام محصول',$strictAlerts),
                'sms_alert_param_price'=>sms_admin_param('sms_alert_param_price','Price','قیمت محصول',$strictAlerts),
                'sms_ticket_reply_template_id'=>sms_admin_template('sms_ticket_reply_template_id','پاسخ تیکت',$strictTicket),
                'sms_ticket_param_number'=>sms_admin_param('sms_ticket_param_number','Ticket','شماره تیکت',$strictTicket),
                'sms_ticket_param_subject'=>sms_admin_param('sms_ticket_param_subject','Subject','موضوع تیکت',$strictTicket),
                'sms_admin_new_order_template_id'=>sms_admin_template('sms_admin_new_order_template_id','اطلاع مدیر از سفارش جدید',$strictAdmin),
                'sms_admin_param_order'=>sms_admin_param('sms_admin_param_order','Order','شماره سفارش مدیر',$strictAdmin),
                'sms_admin_param_name'=>sms_admin_param('sms_admin_param_name','Name','نام مشتری برای مدیر',$strictAdmin),
                'sms_admin_param_amount'=>sms_admin_param('sms_admin_param_amount','Amount','مبلغ سفارش برای مدیر',$strictAdmin),
                'sms_log_retention_days'=>sms_admin_int('sms_log_retention_days',7,365,90,'مدت نگهداری گزارش‌ها',true),
            ];

            $adminMobile=normalize_phone(scalar_string($_POST['sms_admin_mobile']??''));
            if($strictAdmin && !preg_match('/^09\d{9}$/',$adminMobile))throw new RuntimeException('در بخش «اطلاع مدیر»، شماره موبایل مدیر را به‌صورت 09xxxxxxxxx وارد کنید.');
            if($adminMobile!=='' && !preg_match('/^09\d{9}$/',$adminMobile))throw new RuntimeException('شماره موبایل مدیر معتبر نیست.');
            $values['sms_admin_mobile']=$adminMobile;

            $apiKey=trim(scalar_string($_POST['sms_api_key']??''));
            if($apiKey!=='' && (strlen($apiKey)<20 || strlen($apiKey)>300))throw new RuntimeException('طول API Key معتبر نیست. کلید را بدون فاصله اضافی کپی کنید.');
            $existingKey=sms_api_key();
            $willHaveKey=$apiKey!=='' || (!isset($_POST['clear_api_key']) && $existingKey!=='');
            if($globalEnabled && $mode==='live' && !$willHaveKey)throw new RuntimeException('برای حالت واقعی، API Key حساب SMS.ir را وارد کنید.');

            sms_admin_require_template($values,'sms_otp_enabled','sms_otp_template_id','ورود با کد یک‌بارمصرف',$globalEnabled);
            sms_admin_require_template($values,'sms_order_created_enabled','sms_order_created_template_id','ثبت سفارش مشتری',$globalEnabled);
            sms_admin_require_template($values,'sms_order_status_enabled','sms_order_status_template_id','تغییر وضعیت سفارش',$globalEnabled);
            sms_admin_require_template($values,'sms_payment_status_enabled','sms_payment_status_template_id','تغییر وضعیت پرداخت',$globalEnabled);
            sms_admin_require_template($values,'sms_return_status_enabled','sms_return_status_template_id','مرجوعی و بازپرداخت',$globalEnabled);
            sms_admin_require_template($values,'sms_shipment_status_enabled','sms_shipment_status_template_id','ارسال و رهگیری',$globalEnabled);
            sms_admin_require_template($values,'sms_ticket_reply_enabled','sms_ticket_reply_template_id','پاسخ تیکت پشتیبانی',$globalEnabled);
            sms_admin_require_template($values,'sms_admin_new_order_enabled','sms_admin_new_order_template_id','اطلاع مدیر از سفارش جدید',$globalEnabled);
            if($strictAlerts && (int)$values['sms_stock_alert_template_id']<1 && (int)$values['sms_price_alert_template_id']<1){
                throw new RuntimeException('در بخش «هشدار محصول»، حداقل یکی از قالب‌های موجودشدن یا کاهش قیمت را وارد کنید.');
            }

            $pdo=db();
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach($values as $key=>$value)$stmt->execute([$key,$value]);
            if(isset($_POST['clear_api_key']))$stmt->execute(['sms_api_key_enc','']);
            if($apiKey!=='')$stmt->execute(['sms_api_key_enc',protect_secret($apiKey)]);
            $pdo->commit();
            flash('success','تنظیمات پیامک با موفقیت ذخیره شد.');
            redirect(app_url('admin/sms-settings.php'));
        }
        if($action==='credit'){
            if(!function_exists('curl_init'))throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
            $credit=smsir_credit();
            flash('success','ارتباط برقرار شد. اعتبار فعلی: '.fa_number($credit['credit']));
            redirect(app_url('admin/sms-settings.php'));
        }
        if($action==='test'){
            $mobile=normalize_phone(scalar_string($_POST['test_mobile']??''));
            if(!preg_match('/^09\d{9}$/',$mobile))throw new RuntimeException('شماره موبایل آزمایشی معتبر نیست.');
            $length=max(4,min(8,(int)setting('sms_otp_length','5')));
            $code=(string)random_int(10**($length-1),(10**$length)-1);
            $testResult=smsir_send_verify($mobile,sms_template_id('sms_otp_template_id'),[sms_param_name('sms_otp_param_code','CODE')=>$code],'admin_test','admin',(int)(admin_user()['id']??0));
            if(empty($testResult['ok']))throw new RuntimeException((string)($testResult['message']??'ارسال آزمایشی انجام نشد.'));
            flash('success',sms_is_sandbox()?'ارسال در حالت Sandbox شبیه‌سازی شد. کد آزمایشی: '.fa_number($code):'پیامک آزمایشی با موفقیت به SMS.ir ارسال شد.');
            redirect(app_url('admin/sms-settings.php'));
        }
        if($action==='route_test'){
            $mobile=normalize_phone(scalar_string($_POST['route_test_mobile']??''));
            if(!preg_match('/^09\d{9}$/',$mobile))throw new RuntimeException('شماره موبایل آزمایش مسیر معتبر نیست.');
            $route=scalar_string($_POST['route_test_key']??'');
            [$templateKey,$parameters,$routeLabel]=sms_admin_route_test_payload($route);
            $result=smsir_send_verify($mobile,sms_template_id($templateKey),$parameters,'admin_route_test_'.$route,'admin',(int)(admin_user()['id']??0));
            if(!empty($result['skipped']))throw new RuntimeException('ارسال واقعی انجام نشد: '.(string)($result['message']??'سامانه در حالت آزمایشی یا خاموش است.'));
            if(empty($result['ok']))throw new RuntimeException((string)($result['message']??'ارسال آزمایشی مسیر انجام نشد.'));
            flash('success',$routeLabel.' با موفقیت ارسال شد. نتیجه در گزارش پیامک‌ها ثبت شده است.');
            redirect(app_url('admin/sms-settings.php'));
        }
    }catch(Throwable $e){
        if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack();
        log_error($e);
        if($action==='save'){
            $smsSaveError=$e->getMessage();
            $smsFormPost=$_POST;
        }else{
            flash('error',$e->getMessage());
            redirect(app_url('admin/sms-settings.php'));
        }
    }
}

function sms_view_value(string $key,string $default=''): string
{
    global $smsFormPost;
    if(is_array($smsFormPost))return array_key_exists($key,$smsFormPost)?scalar_string($smsFormPost[$key]):'';
    return (string)setting($key,$default);
}

function sms_view_checked(string $key,string $default='0'): bool
{
    global $smsFormPost;
    if(is_array($smsFormPost))return isset($smsFormPost[$key]);
    return (string)setting($key,$default)==='1';
}

function sms_section_state(array $section): array
{
    if(!sms_view_checked((string)$section['enabled_key'],(string)($section['enabled_default']??'0')))return ['خاموش','off'];
    if(!sms_view_checked('sms_enabled','0'))return ['سامانه خاموش','paused'];
    $templates=(array)($section['templates']??[]);
    $mode=(string)($section['template_mode']??'all');
    $ready=$templates===[];
    if($templates!==[]){
        $filled=array_map(static fn(string $key): bool=>(int)sms_admin_clean_digits(sms_view_value($key,'0'))>0,$templates);
        $ready=$mode==='any'?in_array(true,$filled,true):!in_array(false,$filled,true);
    }
    if($ready && !empty($section['phone_key']))$ready=preg_match('/^09\d{9}$/',normalize_phone(sms_view_value((string)$section['phone_key'],'')))===1;
    return $ready?['آماده','ready']:['نیازمند تکمیل','incomplete'];
}

function sms_render_field(array $field): void
{
    $name=(string)$field['name'];
    $type=(string)($field['type']??'text');
    $value=sms_view_value($name,(string)($field['default']??''));
    $label=(string)$field['label'];
    $hint=(string)($field['hint']??'');
    $placeholder=(string)($field['placeholder']??'');
    $attrs='';
    if(isset($field['min']))$attrs.=' min="'.(int)$field['min'].'"';
    if(isset($field['max']))$attrs.=' max="'.(int)$field['max'].'"';
    if(!empty($field['template']))$attrs.=' data-template-field="1"';
    if(!empty($field['required_when_enabled']))$attrs.=' data-required-when-enabled="1"';
    $inputType=$type==='password'?'password':($type==='number'?'text':'text');
    $dir=in_array($type,['template','param','password'],true)?'ltr':'rtl';
    $inputmode=in_array($type,['template','number','phone'],true)?'numeric':'';
    ?>
    <div class="sms-field <?=e($type)?>">
        <label for="<?=e($name)?>"><?=e($label)?></label>
        <input id="<?=e($name)?>" type="<?=$inputType?>" name="<?=e($name)?>" value="<?=$type==='password'?'':e($value)?>" dir="<?=$dir?>" <?=$inputmode!==''?'inputmode="'.$inputmode.'"':''?> placeholder="<?=e($placeholder)?>"<?=$attrs?>>
        <?php if($hint!==''):?><small><?=e($hint)?></small><?php endif?>
    </div>
    <?php
}

$sections=[
    [
        'id'=>'otp','title'=>'ورود با کد یک‌بارمصرف','icon'=>'🔐','enabled_key'=>'sms_otp_enabled','enabled_default'=>'1','templates'=>['sms_otp_template_id'],
        'description'=>'ارسال کد ورود و ساخت حساب مشتری با شماره همراه.',
        'fields'=>[
            ['name'=>'sms_otp_template_id','label'=>'شناسه قالب OTP','type'=>'template','default'=>'123456','placeholder'=>'مثلاً 123456','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_otp_param_code','label'=>'نام متغیر کد','type'=>'param','default'=>'CODE','placeholder'=>'CODE','required_when_enabled'=>true,'hint'=>'در SMS.ir دقیقاً CODE باشد؛ واردکردن #CODE# نیز خودکار اصلاح می‌شود.'],
            ['name'=>'sms_otp_length','label'=>'تعداد رقم کد','type'=>'number','default'=>'5','min'=>4,'max'=>8],
            ['name'=>'sms_otp_expiry_minutes','label'=>'انقضا، دقیقه','type'=>'number','default'=>'2','min'=>1,'max'=>15],
            ['name'=>'sms_otp_cooldown_seconds','label'=>'ارسال مجدد، ثانیه','type'=>'number','default'=>'60','min'=>30,'max'=>600],
            ['name'=>'sms_otp_max_attempts','label'=>'حداکثر تلاش','type'=>'number','default'=>'5','min'=>3,'max'=>10],
        ],
        'variables'=>['CODE'],'example'=>"فروشگاه گلریزان\nکد ورود شما: #CODE#\nاین کد را در اختیار دیگران قرار ندهید.\ngolrizan1368.ir",
    ],
    [
        'id'=>'order-created','title'=>'ثبت سفارش مشتری','icon'=>'🛍️','enabled_key'=>'sms_order_created_enabled','templates'=>['sms_order_created_template_id'],
        'description'=>'پس از ثبت موفق سفارش برای مشتری ارسال می‌شود.',
        'fields'=>[
            ['name'=>'sms_order_created_template_id','label'=>'شناسه قالب','type'=>'template','default'=>'0','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_order_param_order','label'=>'متغیر شماره سفارش','type'=>'param','default'=>'Order'],
            ['name'=>'sms_order_param_name','label'=>'متغیر نام مشتری','type'=>'param','default'=>'Name'],
            ['name'=>'sms_order_param_amount','label'=>'متغیر مبلغ','type'=>'param','default'=>'Amount'],
        ],
        'variables'=>['Order','Name','Amount'],'example'=>"#Name# عزیز\nسفارش شماره #Order# با مبلغ #Amount# تومان ثبت شد.\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
    [
        'id'=>'order-status','title'=>'تغییر وضعیت سفارش','icon'=>'📦','enabled_key'=>'sms_order_status_enabled','templates'=>['sms_order_status_template_id'],
        'description'=>'هنگام تغییر وضعیت پردازش سفارش برای مشتری ارسال می‌شود.',
        'fields'=>[
            ['name'=>'sms_order_status_template_id','label'=>'شناسه قالب','type'=>'template','default'=>'0','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_status_param_order','label'=>'متغیر شماره سفارش','type'=>'param','default'=>'Order'],
            ['name'=>'sms_status_param_status','label'=>'متغیر وضعیت','type'=>'param','default'=>'Status'],
        ],
        'variables'=>['Order','Status'],'example'=>"وضعیت سفارش شماره #Order# به «#Status#» تغییر کرد.\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
    [
        'id'=>'payment-status','title'=>'وضعیت پرداخت','icon'=>'💳','enabled_key'=>'sms_payment_status_enabled','templates'=>['sms_payment_status_template_id'],
        'description'=>'موفق یا ناموفق‌بودن پرداخت سفارش را اطلاع می‌دهد.',
        'fields'=>[
            ['name'=>'sms_payment_status_template_id','label'=>'شناسه قالب','type'=>'template','default'=>'0','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_payment_param_order','label'=>'متغیر شماره سفارش','type'=>'param','default'=>'Order'],
            ['name'=>'sms_payment_param_status','label'=>'متغیر وضعیت پرداخت','type'=>'param','default'=>'Status'],
        ],
        'variables'=>['Order','Status'],'example'=>"وضعیت پرداخت سفارش شماره #Order#:\n#Status#\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
    [
        'id'=>'return-status','title'=>'مرجوعی و بازپرداخت','icon'=>'↩️','enabled_key'=>'sms_return_status_enabled','templates'=>['sms_return_status_template_id'],
        'description'=>'تغییر وضعیت درخواست مرجوعی را به مشتری اطلاع می‌دهد.',
        'fields'=>[
            ['name'=>'sms_return_status_template_id','label'=>'شناسه قالب','type'=>'template','default'=>'0','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_return_param_number','label'=>'متغیر شماره مرجوعی','type'=>'param','default'=>'Return'],
            ['name'=>'sms_return_param_status','label'=>'متغیر وضعیت','type'=>'param','default'=>'Status'],
        ],
        'variables'=>['Return','Status'],'example'=>"وضعیت درخواست مرجوعی شماره #Return# به «#Status#» تغییر کرد.\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
    [
        'id'=>'shipment-status','title'=>'ارسال و رهگیری','icon'=>'🚚','enabled_key'=>'sms_shipment_status_enabled','templates'=>['sms_shipment_status_template_id'],
        'description'=>'شرکت حمل، وضعیت مرسوله و کد رهگیری را ارسال می‌کند.',
        'fields'=>[
            ['name'=>'sms_shipment_status_template_id','label'=>'شناسه قالب','type'=>'template','default'=>'0','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_shipment_param_order','label'=>'متغیر شماره سفارش','type'=>'param','default'=>'Order'],
            ['name'=>'sms_shipment_param_status','label'=>'متغیر وضعیت ارسال','type'=>'param','default'=>'Status'],
            ['name'=>'sms_shipment_param_code','label'=>'متغیر کد رهگیری','type'=>'param','default'=>'Code'],
            ['name'=>'sms_shipment_param_carrier','label'=>'متغیر شرکت حمل','type'=>'param','default'=>'Carrier'],
        ],
        'variables'=>['Order','Status','Carrier','Code'],'example'=>"سفارش شماره #Order#\nوضعیت ارسال: #Status#\nشرکت حمل: #Carrier#\nکد رهگیری: #Code#\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
    [
        'id'=>'product-alerts','title'=>'هشدار موجودی و کاهش قیمت','icon'=>'🔔','enabled_key'=>'sms_product_alerts_enabled','templates'=>['sms_stock_alert_template_id','sms_price_alert_template_id'],'template_mode'=>'any',
        'description'=>'حداقل یکی از دو قالب کافی است؛ نوعی که شناسه ندارد ارسال نمی‌شود.',
        'fields'=>[
            ['name'=>'sms_stock_alert_template_id','label'=>'قالب موجودشدن محصول','type'=>'template','default'=>'0','template'=>true],
            ['name'=>'sms_price_alert_template_id','label'=>'قالب کاهش قیمت محصول','type'=>'template','default'=>'0','template'=>true],
            ['name'=>'sms_alert_param_product','label'=>'متغیر نام محصول','type'=>'param','default'=>'Product'],
            ['name'=>'sms_alert_param_price','label'=>'متغیر قیمت','type'=>'param','default'=>'Price'],
        ],
        'variables'=>['Product','Price'],'example'=>"محصول «#Product#» دوباره موجود شد.\nقیمت فعلی: #Price# تومان\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
    [
        'id'=>'ticket-reply','title'=>'پاسخ تیکت پشتیبانی','icon'=>'💬','enabled_key'=>'sms_ticket_reply_enabled','templates'=>['sms_ticket_reply_template_id'],
        'description'=>'پس از پاسخ مدیر به تیکت برای مشتری ارسال می‌شود.',
        'fields'=>[
            ['name'=>'sms_ticket_reply_template_id','label'=>'شناسه قالب','type'=>'template','default'=>'0','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_ticket_param_number','label'=>'متغیر شماره تیکت','type'=>'param','default'=>'Ticket'],
            ['name'=>'sms_ticket_param_subject','label'=>'متغیر موضوع','type'=>'param','default'=>'Subject'],
        ],
        'variables'=>['Ticket','Subject'],'example'=>"برای تیکت شماره #Ticket# با موضوع «#Subject#» پاسخ جدید ثبت شد.\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
    [
        'id'=>'admin-order','title'=>'اطلاع مدیر از سفارش جدید','icon'=>'👤','enabled_key'=>'sms_admin_new_order_enabled','templates'=>['sms_admin_new_order_template_id'],'phone_key'=>'sms_admin_mobile',
        'description'=>'هم‌زمان با ثبت سفارش جدید، خلاصه سفارش برای مدیر ارسال می‌شود.',
        'fields'=>[
            ['name'=>'sms_admin_mobile','label'=>'شماره موبایل مدیر','type'=>'phone','default'=>'','placeholder'=>'09xxxxxxxxx','required_when_enabled'=>true],
            ['name'=>'sms_admin_new_order_template_id','label'=>'شناسه قالب','type'=>'template','default'=>'0','template'=>true,'required_when_enabled'=>true],
            ['name'=>'sms_admin_param_order','label'=>'متغیر شماره سفارش','type'=>'param','default'=>'Order'],
            ['name'=>'sms_admin_param_name','label'=>'متغیر نام مشتری','type'=>'param','default'=>'Name'],
            ['name'=>'sms_admin_param_amount','label'=>'متغیر مبلغ','type'=>'param','default'=>'Amount'],
        ],
        'variables'=>['Order','Name','Amount'],'example'=>"سفارش جدید شماره #Order#\nمشتری: #Name#\nمبلغ سفارش: #Amount# تومان\nفروشگاه گلریزان\ngolrizan1368.ir",
    ],
];

$apiKeyState=sms_api_key_state();
$hasApiKey=!empty($apiKeyState['readable']);
$storedApiKey=!empty($apiKeyState['stored']);
$apiKeyNeedsRecovery=$storedApiKey&&!$hasApiKey;
$alertReadiness=product_alert_sms_readiness();
$lastAlertRun=(string)setting('product_alert_last_run_at','');
$routeChecks=[
    ['title'=>'ارسال واقعی SMS.ir','ready'=>sms_enabled()&&!sms_is_sandbox()&&$hasApiKey,'message'=>!sms_enabled()?'سامانه خاموش است.':(sms_is_sandbox()?'حالت Sandbox فعال است؛ هیچ پیام واقعی ارسال نمی‌شود.':(!$hasApiKey?(string)($apiKeyState['message']??'API Key در دسترس نیست.'):'اتصال برای ارسال واقعی آماده است.'))],
    ['title'=>'پیامک ثبت خرید','ready'=>(string)setting('sms_order_created_enabled','0')==='1'&&sms_template_id('sms_order_created_template_id')>0,'message'=>(string)setting('sms_order_created_enabled','0')!=='1'?'تیک ثبت سفارش مشتری خاموش است.':(sms_template_id('sms_order_created_template_id')<1?'شناسه قالب ثبت سفارش وارد نشده است.':'قالب ثبت سفارش آماده است.')],
    ['title'=>'پیامک وضعیت پرداخت','ready'=>(string)setting('sms_payment_status_enabled','0')==='1'&&sms_template_id('sms_payment_status_template_id')>0,'message'=>(string)setting('sms_payment_status_enabled','0')!=='1'?'تیک وضعیت پرداخت خاموش است.':(sms_template_id('sms_payment_status_template_id')<1?'شناسه قالب وضعیت پرداخت وارد نشده است.':'قالب وضعیت پرداخت آماده است.')],
    ['title'=>'هشدار کاهش قیمت','ready'=>(bool)$alertReadiness['ready']&&sms_template_id('sms_price_alert_template_id')>0&&$lastAlertRun!=='','message'=>sms_template_id('sms_price_alert_template_id')<1?'قالب کاهش قیمت وارد نشده است.':($lastAlertRun===''?'Cron هشدار محصول هنوز اجرا نشده است.':(string)$alertReadiness['message'])],
];
try{$recentFailures=(int)db()->query("SELECT COUNT(*) FROM sms_logs WHERE status='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();}catch(Throwable $exception){log_error($exception);$recentFailures=0;}
require __DIR__.'/partials/header.php';
?>
<style>
.sms-page {
    --sms-primary: #6d28d9;
    --sms-primary-soft: #f3e8ff;
    --sms-border: #e5e7eb;
    --sms-muted: #64748b;
    display: grid;
    gap: 20px;
    width: 100%;
    min-width: 0;
}
.sms-page, .sms-page *, .sms-page *::before, .sms-page *::after { box-sizing: border-box; }
.sms-page form, .sms-page section, .sms-page div, .sms-page article { min-width: 0; }
.sms-page input, .sms-page select, .sms-page textarea, .sms-page button { max-width: 100%; }
.sms-page h1, .sms-page h2, .sms-page h3, .sms-page p, .sms-page small, .sms-page span, .sms-page label {
    overflow-wrap: anywhere;
}
.sms-hero {
    position: relative;
    overflow: hidden;
    width: 100%;
    background: linear-gradient(135deg, #312e81 0%, #6d28d9 55%, #9333ea 100%);
    color: #fff;
    border-radius: 24px;
    padding: 26px;
    box-shadow: 0 18px 45px rgba(76, 29, 149, .2);
}
.sms-hero::after {
    content: "";
    position: absolute;
    width: 250px;
    height: 250px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .1);
    left: -70px;
    top: -110px;
    pointer-events: none;
}
.sms-hero-head {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.sms-hero-head > div:first-child { flex: 1 1 360px; min-width: 0; }
.sms-hero h1 { margin: 0 0 8px; color: #fff; font-size: clamp(23px, 2.2vw, 32px); }
.sms-hero p { margin: 0; color: rgba(255, 255, 255, .82); line-height: 1.9; }
.sms-hero-actions { display: flex; gap: 10px; flex-wrap: wrap; flex: 0 1 auto; }
.sms-hero-actions .btn { min-height: 42px; }
.sms-hero .btn { border-color: rgba(255, 255, 255, .32); }
.sms-stats {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 170px), 1fr));
    gap: 12px;
    margin-top: 22px;
}
.sms-stat {
    min-width: 0;
    background: rgba(255, 255, 255, .12);
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 16px;
    padding: 14px;
    backdrop-filter: blur(8px);
}
.sms-stat small { display: block; color: rgba(255, 255, 255, .72); margin-bottom: 5px; }
.sms-stat strong { display: block; font-size: 16px; }
.sms-error {
    width: 100%;
    border-radius: 16px;
    padding: 15px 18px;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #9f1239;
    font-weight: 700;
    line-height: 1.9;
}
#sms-settings-form { display: grid; gap: 20px; width: 100%; min-width: 0; }
.sms-connect {
    width: 100%;
    min-width: 0;
    border: 1px solid var(--sms-border);
    border-radius: 22px;
    background: #fff;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
}
.sms-connect .admin-title { gap: 12px; }
.sms-connect-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 310px), 1fr));
    gap: 16px;
    width: 100%;
}
.sms-switch-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    min-width: 0;
    padding: 14px 16px;
    background: #f8fafc;
    border: 1px solid var(--sms-border);
    border-radius: 16px;
}
.sms-switch-row label {
    margin: 0;
    min-width: 0;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.7;
}
.sms-switch-row > span { flex: 0 0 auto; font-size: 12px; color: #475569; }
.sms-switch-row input[type=checkbox], .sms-section-toggle input {
    width: 20px;
    height: 20px;
    min-width: 20px;
    flex: 0 0 20px;
    accent-color: var(--sms-primary);
}
.sms-api { grid-column: 1 / -1; }
.sms-api input { font-family: monospace; }
.sms-note {
    width: 100%;
    padding: 12px 14px;
    border-radius: 14px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #854d0e;
    font-size: 13px;
    line-height: 1.9;
}
.sms-accounts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
    gap: 10px 18px;
    width: 100%;
    padding-top: 14px;
}
.sms-accounts label {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    min-width: 0;
    font-weight: 700;
    line-height: 1.8;
}
.sms-accounts input { flex: 0 0 auto; margin-top: 4px; }
.sms-section-nav {
    display: flex;
    gap: 8px;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 4px 1px 6px;
    scroll-snap-type: x proximity;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.sms-section-nav a {
    flex: 0 0 auto;
    scroll-snap-align: start;
    white-space: nowrap;
    text-decoration: none;
    padding: 9px 13px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid var(--sms-border);
    color: #334155;
    font-size: 13px;
    font-weight: 700;
}
.sms-template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 430px), 1fr));
    gap: 18px;
    width: 100%;
    min-width: 0;
}
.sms-template-card {
    width: 100%;
    min-width: 0;
    border: 1px solid var(--sms-border);
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 8px 28px rgba(15, 23, 42, .05);
    overflow: hidden;
    transition: .2s ease;
    contain: inline-size;
}
.sms-template-card.inactive { opacity: .72; }
.sms-template-card.incomplete { border-color: #f59e0b; box-shadow: 0 8px 28px rgba(245, 158, 11, .12); }
.sms-template-card.ready { border-color: #86efac; }
.sms-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    min-width: 0;
    padding: 18px;
    border-bottom: 1px solid #eef2f7;
}
.sms-card-title {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    flex: 1 1 260px;
    min-width: 0;
}
.sms-card-title > div:last-child { min-width: 0; }
.sms-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    background: var(--sms-primary-soft);
    font-size: 21px;
    flex: 0 0 42px;
}
.sms-card-title h3 { margin: 0 0 5px; font-size: 17px; }
.sms-card-title p { margin: 0; color: var(--sms-muted); font-size: 13px; line-height: 1.7; }
.sms-card-tools {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex: 0 0 auto;
    min-width: 0;
}
.sms-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    max-width: 100%;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
}
.sms-badge.ready { background: #dcfce7; color: #166534; }
.sms-badge.incomplete { background: #fef3c7; color: #92400e; }
.sms-badge.off { background: #f1f5f9; color: #475569; }
.sms-badge.paused { background: #e0e7ff; color: #3730a3; }
.sms-section-toggle { display: flex; align-items: center; flex: 0 0 auto; }
.sms-card-body { min-width: 0; padding: 18px; }
.sms-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
    gap: 13px;
    width: 100%;
    min-width: 0;
}
.sms-field { width: 100%; min-width: 0; }
.sms-field label { display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px; }
.sms-field input, .sms-field select, .sms-field textarea {
    display: block;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    border: 1px solid #dbe2ea;
    border-radius: 12px;
    padding: 10px 12px;
    background: #fff;
    transition: .15s;
}
.sms-field select { min-height: 43px; }
.sms-field input:focus, .sms-field select:focus, .sms-field textarea:focus {
    outline: none;
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139, 92, 246, .12);
}
.sms-field small { display: block; color: var(--sms-muted); font-size: 11px; margin-top: 5px; line-height: 1.6; }
.sms-field.template input { font-weight: 800; }
.sms-vars { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; min-width: 0; margin-top: 15px; }
.sms-vars span { font-size: 11px; color: #64748b; }
.sms-chip {
    max-width: 100%;
    direction: ltr;
    background: #eef2ff;
    color: #4338ca;
    border: 1px solid #c7d2fe;
    padding: 4px 8px;
    border-radius: 8px;
    font-family: monospace;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.sms-preview {
    min-width: 0;
    margin-top: 14px;
    border-radius: 14px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    overflow: hidden;
}
.sms-preview summary { cursor: pointer; padding: 11px 13px; font-weight: 800; color: #475569; }
.sms-preview pre {
    max-width: 100%;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
    margin: 0;
    padding: 0 13px 13px;
    font-family: inherit;
    line-height: 1.9;
    color: #334155;
    font-size: 12px;
}
.sms-preview-actions { display: flex; justify-content: flex-end; padding: 0 13px 12px; }
.sms-copy { border: 0; background: #ede9fe; color: #5b21b6; border-radius: 9px; padding: 7px 11px; cursor: pointer; font-weight: 800; font-size: 11px; }
.sms-savebar {
    position: sticky;
    bottom: 12px;
    z-index: 20;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    width: 100%;
    min-width: 0;
    background: rgba(15, 23, 42, .94);
    color: #fff;
    border-radius: 18px;
    padding: 13px 16px;
    box-shadow: 0 16px 38px rgba(15, 23, 42, .28);
    backdrop-filter: blur(10px);
}
.sms-savebar > div { min-width: 0; }
.sms-savebar small { display: block; color: #cbd5e1; line-height: 1.7; }
.sms-test-card {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 330px), 1fr));
    gap: 16px;
    width: 100%;
    min-width: 0;
}
.sms-test-box {
    min-width: 0;
    background: #fff;
    border: 1px solid var(--sms-border);
    border-radius: 18px;
    padding: 17px;
}
.sms-test-box h3 { margin-top: 0; }
.sms-test-box form { display: flex; gap: 9px; align-items: stretch; flex-wrap: wrap; min-width: 0; }
.sms-test-box input { flex: 1 1 210px; width: auto; min-width: 0; }
.sms-test-box button { flex: 0 0 auto; }
.sms-help { font-size: 12px; color: var(--sms-muted); line-height: 1.8; }
.sms-hidden-error { display: none; color: #b91c1c; font-size: 11px; margin-top: 5px; }
.sms-template-card.client-error { border-color: #ef4444; }
.sms-template-card.client-error .sms-hidden-error { display: block; }

@media (max-width: 1180px) {
    .sms-template-grid { grid-template-columns: 1fr; }
}
@media (max-width: 760px) {
    .sms-page { gap: 15px; }
    .sms-hero { padding: 20px; border-radius: 18px; }
    .sms-hero-head { display: grid; grid-template-columns: 1fr; }
    .sms-hero-actions { width: 100%; }
    .sms-hero-actions .btn { flex: 1 1 180px; justify-content: center; }
    .sms-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 17px; }
    .sms-stat:last-child { grid-column: 1 / -1; }
    .sms-connect { padding: 16px; border-radius: 18px; }
    .sms-connect-grid, .sms-template-grid, .sms-test-card { grid-template-columns: 1fr; }
    .sms-connect .admin-title { align-items: flex-start; }
    .sms-section-nav { margin-inline: -2px; padding-inline: 2px; }
    .sms-card-head { padding: 15px; }
    .sms-card-body { padding: 15px; }
    .sms-fields { grid-template-columns: 1fr; }
    .sms-savebar { position: static; align-items: stretch; flex-direction: column; border-radius: 16px; }
    .sms-savebar .btn { width: 100%; justify-content: center; }
    .sms-test-box form { display: grid; grid-template-columns: 1fr; }
    .sms-test-box input, .sms-test-box button { width: 100%; min-width: 0; }
}
@media (max-width: 520px) {
    .sms-hero { padding: 17px 15px; }
    .sms-hero h1 { font-size: 22px; }
    .sms-hero-actions { display: grid; grid-template-columns: 1fr; }
    .sms-hero-actions .btn { width: 100%; }
    .sms-stats { grid-template-columns: 1fr; }
    .sms-stat:last-child { grid-column: auto; }
    .sms-connect { padding: 14px; }
    .sms-switch-row { align-items: flex-start; flex-direction: column; padding: 13px; }
    .sms-switch-row > span, .sms-switch-row .sms-badge { align-self: flex-start; }
    .sms-accounts { grid-template-columns: 1fr; }
    .sms-template-card { border-radius: 17px; }
    .sms-card-head { gap: 12px; padding: 14px; }
    .sms-card-title { flex-basis: 100%; gap: 10px; }
    .sms-card-icon { width: 38px; height: 38px; flex-basis: 38px; border-radius: 12px; font-size: 19px; }
    .sms-card-title h3 { font-size: 15px; }
    .sms-card-title p { font-size: 12px; }
    .sms-card-tools { width: 100%; justify-content: space-between; }
    .sms-card-body { padding: 14px; }
    .sms-vars { align-items: flex-start; }
    .sms-preview-actions .sms-copy { width: 100%; }
    .sms-test-box { padding: 14px; }
}
@media (max-width: 360px) {
    .sms-card-tools { align-items: flex-start; flex-direction: column; }
    .sms-badge { white-space: normal; text-align: center; }
}

.sms-health-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 12px; }
.sms-health-item { padding: 14px; border: 1px solid var(--sms-border); border-radius: 16px; background: #fff; }
.sms-health-item strong { display: block; margin-bottom: 6px; color: #0f172a; }
.sms-health-item small { display: block; line-height: 1.8; color: var(--sms-muted); }
.sms-health-item.ready { border-color: #a7f3d0; background: #f0fdf4; }
.sms-health-item.problem { border-color: #fecaca; background: #fff7f7; }
.sms-health-alert { margin-top: 12px; padding: 11px 13px; border-radius: 12px; background: #fff7ed; color: #9a3412; font-size: 11px; }
.sms-route-test-form { display: grid; grid-template-columns: minmax(180px,1fr) minmax(160px,1fr) auto; gap: 10px; align-items: end; }
@media (max-width: 980px) { .sms-health-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width: 620px) { .sms-health-grid { grid-template-columns: 1fr; } .sms-route-test-form { grid-template-columns: 1fr; } .sms-route-test-form .btn { width: 100%; } }

</style>
<div class="sms-page">
    <section class="sms-hero">
        <div class="sms-hero-head">
            <div><h1>مرکز پیامک SMS.ir</h1><p>اتصال، قالب‌ها، متغیرها و ارسال آزمایشی را بدون سردرگمی از یک صفحه مدیریت کن.</p></div>
            <div class="sms-hero-actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-recipients.php'))?>">وضعیت شماره‌ها</a><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-logs.php'))?>">گزارش ارسال‌ها</a><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-campaigns.php'))?>">کمپین پیامکی</a></div>
        </div>
        <div class="sms-stats">
            <div class="sms-stat"><small>وضعیت سامانه</small><strong><?=sms_view_checked('sms_enabled','0')?'فعال':'غیرفعال'?></strong></div>
            <div class="sms-stat"><small>محیط ارسال</small><strong><?=sms_view_value('sms_mode','sandbox')==='live'?'واقعی':'Sandbox'?></strong></div>
            <div class="sms-stat"><small>API Key</small><strong><?=$hasApiKey?'سالم و قابل‌خواندن':($storedApiKey?'نیازمند ورود مجدد':'وارد نشده')?></strong></div>
        </div>
    </section>

    <?php if($smsSaveError!==''):?><div class="sms-error">⚠️ <?=e($smsSaveError)?><br><small>مقادیر واردشده حفظ شده‌اند؛ همان بخش را اصلاح و دوباره ذخیره کن.</small></div><?php endif?>

    <?php if($apiKeyNeedsRecovery):?><div class="sms-error">⚠️ <?=e((string)$apiKeyState['message'])?><br><small>این وضعیت معمولاً بعد از حذف یا جایگزینی فایل <span dir="ltr">storage/app.key</span> رخ می‌دهد. کلید API را دوباره در بخش اتصال وارد و تنظیمات را ذخیره کن.</small></div><?php endif?>

    <section class="sms-connect">
        <div class="admin-title"><div><h2>سلامت مسیرهای ارسال</h2><p class="muted">این بخش نشان می‌دهد چرا پیامک خرید یا کاهش قیمت ارسال نشده است.</p></div><div class="actions"><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-recipients.php?view=orders'))?>">کنترل پیامک سفارش‌ها</a><a class="btn btn-secondary" href="<?=e(app_url('admin/sms-logs.php?status=failed'))?>">مشاهده خطاهای ارسال</a></div></div>
        <div class="sms-health-grid"><?php foreach($routeChecks as $check):?><div class="sms-health-item <?=!empty($check['ready'])?'ready':'problem'?>"><strong><?=!empty($check['ready'])?'✓':'!'?> <?=e($check['title'])?></strong><small><?=e($check['message'])?></small></div><?php endforeach?></div>
        <?php if($recentFailures>0):?><div class="sms-health-alert">در ۲۴ ساعت گذشته <?=fa_number($recentFailures)?> ارسال ناموفق ثبت شده است. جزئیات پاسخ SMS.ir را در گزارش ارسال‌ها ببین.</div><?php endif?>
    </section>

    <form method="post" id="sms-settings-form" autocomplete="off">
        <?=csrf_field()?><input type="hidden" name="action" value="save">
        <section class="sms-connect" id="sms-connection">
            <div class="admin-title"><div><h2>اتصال و تنظیمات اصلی</h2><p class="muted">ابتدا در Sandbox تست کن و سپس محیط را روی واقعی قرار بده.</p></div></div>
            <div class="sms-connect-grid">
                <div class="sms-switch-row"><label><input id="sms-global-toggle" type="checkbox" name="sms_enabled" value="1" <?=sms_view_checked('sms_enabled','0')?'checked':''?>> فعال‌سازی ارسال پیامک</label><span class="sms-badge <?=sms_view_checked('sms_enabled','0')?'ready':'off'?>"><?=sms_view_checked('sms_enabled','0')?'فعال':'خاموش'?></span></div>
                <div class="sms-field"><label for="sms_mode">محیط ارسال</label><select id="sms_mode" name="sms_mode"><option value="sandbox" <?=sms_view_value('sms_mode','sandbox')==='sandbox'?'selected':''?>>آزمایشی Sandbox؛ بدون ارسال واقعی</option><option value="live" <?=sms_view_value('sms_mode','sandbox')==='live'?'selected':''?>>واقعی؛ ارسال از SMS.ir</option></select></div>
                <div class="sms-field sms-api"><label for="sms_api_key">API Key</label><input id="sms_api_key" type="password" name="sms_api_key" dir="ltr" autocomplete="new-password" placeholder="<?=$hasApiKey?'کلید قبلی سالم است؛ برای حفظ آن خالی بگذارید':($storedApiKey?'کلید قبلی خوانده نمی‌شود؛ دوباره وارد کنید':'API Key را از پنل SMS.ir کپی کنید')?>"><small>در حالت واقعی و هنگام فعال‌بودن سامانه الزامی است. فقط وقتی وضعیت کلید «سالم و قابل‌خواندن» است، خالی‌گذاشتن این فیلد کلید قبلی را حفظ می‌کند.</small></div>
                <div class="sms-switch-row sms-api"><label><input type="checkbox" name="clear_api_key" value="1"> حذف API Key ذخیره‌شده</label><span><?=$hasApiKey?'کلید فعلی سالم است':($storedApiKey?'کلید ذخیره‌شده خوانده نمی‌شود':'کلیدی ذخیره نشده')?></span></div>
            </div>
            <div class="sms-note" style="margin-top:14px">نام متغیرها به حروف بزرگ و کوچک حساس است. برای مثال <b>Order</b> با <b>ORDER</b> متفاوت است. سیستم ورودی‌هایی مانند <b>#CODE#</b> را هنگام ذخیره به <b>CODE</b> تبدیل می‌کند.</div>
            <div class="sms-accounts"><label><input type="checkbox" name="customer_accounts_enabled" value="1" <?=sms_view_checked('customer_accounts_enabled','1')?'checked':''?>> حساب کاربری مشتری فعال باشد</label><label><input type="checkbox" name="customer_checkout_autofill" value="1" <?=sms_view_checked('customer_checkout_autofill','1')?'checked':''?>> تکمیل خودکار اطلاعات تسویه‌حساب</label></div>
        </section>

        <nav class="sms-section-nav" aria-label="دسترسی سریع قالب‌ها">
            <?php foreach($sections as $section):?><a href="#sms-<?=e($section['id'])?>"><?=e($section['icon'].' '.$section['title'])?></a><?php endforeach?>
        </nav>

        <div class="sms-template-grid">
            <?php foreach($sections as $section):[$stateLabel,$stateClass]=sms_section_state($section);$enabled=sms_view_checked((string)$section['enabled_key'],(string)($section['enabled_default']??'0'));?>
                <section id="sms-<?=e($section['id'])?>" class="sms-template-card <?=$enabled?$stateClass:'inactive'?>" data-sms-section data-template-mode="<?=e((string)($section['template_mode']??'all'))?>">
                    <div class="sms-card-head">
                        <div class="sms-card-title"><div class="sms-card-icon"><?=e($section['icon'])?></div><div><h3><?=e($section['title'])?></h3><p><?=e($section['description'])?></p></div></div>
                        <div class="sms-card-tools"><span class="sms-badge <?=$stateClass?>" data-state-badge><?=e($stateLabel)?></span><label class="sms-section-toggle" title="فعال یا غیرفعال"><input type="checkbox" name="<?=e($section['enabled_key'])?>" value="1" data-section-toggle <?=$enabled?'checked':''?>></label></div>
                    </div>
                    <div class="sms-card-body">
                        <div class="sms-fields"><?php foreach($section['fields'] as $field)sms_render_field($field);?></div>
                        <div class="sms-hidden-error" data-section-error>فیلدهای ضروری این بخش را کامل کن.</div>
                        <div class="sms-vars"><span>متغیرهای قالب:</span><?php foreach($section['variables'] as $variable):?><code class="sms-chip"><?=e($variable)?></code><?php endforeach?></div>
                        <details class="sms-preview"><summary>متن پیشنهادی قالب در SMS.ir</summary><pre id="preview-<?=e($section['id'])?>"><?=e($section['example'])?></pre><div class="sms-preview-actions"><button type="button" class="sms-copy" data-copy="preview-<?=e($section['id'])?>">کپی متن قالب</button></div></details>
                    </div>
                </section>
            <?php endforeach?>
        </div>

        <section class="sms-connect" style="margin-top:18px"><h2>نگهداری گزارش‌ها</h2><div class="sms-fields"><div class="sms-field"><label for="sms_log_retention_days">مدت نگهداری گزارش پیامک، روز</label><input id="sms_log_retention_days" name="sms_log_retention_days" inputmode="numeric" min="7" max="365" value="<?=e(sms_view_value('sms_log_retention_days','90'))?>"><small>عدد مجاز بین ۷ تا ۳۶۵ روز است.</small></div></div></section>

        <div class="sms-savebar"><div><strong>ذخیره تنظیمات پیامک</strong><br><small>بخش‌های خاموش می‌توانند ناقص بمانند و مانع ذخیره نمی‌شوند.</small></div><button class="btn btn-primary" type="submit">ذخیره همه تنظیمات</button></div>
    </form>

    <section class="sms-test-card">
        <div class="sms-test-box"><h3>بررسی اتصال و اعتبار</h3><p class="sms-help">فقط در حالت واقعی با API Key ذخیره‌شده به SMS.ir متصل می‌شود.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="credit"><button class="btn btn-secondary">بررسی اعتبار حساب</button></form></div>
        <div class="sms-test-box"><h3>ارسال OTP آزمایشی</h3><p class="sms-help">ابتدا تنظیمات را ذخیره کن. در Sandbox پیام واقعی ارسال نمی‌شود.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test"><input name="test_mobile" inputmode="numeric" maxlength="11" placeholder="09xxxxxxxxx" required><button class="btn btn-primary">ارسال آزمایشی</button></form></div>
    </section>
    <section class="sms-connect"><h2>آزمایش جداگانه هر مسیر</h2><p class="sms-help">با این ابزار می‌توانی قالب خرید، پرداخت، کاهش قیمت و سایر مسیرها را جداگانه آزمایش کنی.</p><form method="post" class="sms-route-test-form"><?=csrf_field()?><input type="hidden" name="action" value="route_test"><div class="sms-field"><label>مسیر پیامک</label><select name="route_test_key" required><option value="order_created">ثبت سفارش مشتری</option><option value="admin_new_order">اطلاع مدیر از سفارش</option><option value="payment_status">وضعیت پرداخت</option><option value="order_status">وضعیت سفارش</option><option value="price_alert">کاهش قیمت محصول</option><option value="stock_alert">موجودشدن محصول</option><option value="shipment_status">ارسال و رهگیری</option><option value="return_status">مرجوعی</option><option value="ticket_reply">پاسخ تیکت</option><option value="otp">OTP</option></select></div><div class="sms-field"><label>شماره دریافت‌کننده تست</label><input name="route_test_mobile" inputmode="numeric" maxlength="11" placeholder="09xxxxxxxxx" required></div><button class="btn btn-primary">ارسال تست مسیر</button></form></section>
</div>
<script nonce="<?=e(CSP_NONCE)?>">
(function(){
    const form=document.getElementById('sms-settings-form');
    const globalToggle=document.getElementById('sms-global-toggle');
    const mode=document.getElementById('sms_mode');
    const api=document.getElementById('sms_api_key');
    const cards=[...document.querySelectorAll('[data-sms-section]')];

    function normalizeDigits(value){
        const fa='۰۱۲۳۴۵۶۷۸۹', ar='٠١٢٣٤٥٦٧٨٩';
        return String(value||'').replace(/[۰-۹]/g,d=>String(fa.indexOf(d))).replace(/[٠-٩]/g,d=>String(ar.indexOf(d)));
    }
    function updateCard(card){
        const toggle=card.querySelector('[data-section-toggle]');
        const active=!!(toggle&&toggle.checked);
        const globalActive=!!(globalToggle&&globalToggle.checked);
        const templateFields=[...card.querySelectorAll('[data-template-field]')];
        const requiredFields=[...card.querySelectorAll('[data-required-when-enabled]')];
        const modeType=card.dataset.templateMode||'all';
        let templatesReady=true;
        if(templateFields.length){
            const filled=templateFields.map(input=>{const value=normalizeDigits(input.value).replace(/[\s,،٬]/g,'');return /^\d+$/.test(value)&&parseInt(value||'0',10)>0;});
            templatesReady=modeType==='any'?filled.some(Boolean):filled.every(Boolean);
        }
        const extraReady=requiredFields.every(input=>(input.value||'').trim()!=='');
        const ready=templatesReady&&extraReady;
        card.classList.toggle('inactive',!active);
        card.classList.toggle('ready',active&&globalActive&&ready);
        card.classList.toggle('incomplete',active&&globalActive&&!ready);
        const badge=card.querySelector('[data-state-badge]');
        if(badge){
            badge.className='sms-badge';
            if(!active){badge.classList.add('off');badge.textContent='خاموش';}
            else if(!globalActive){badge.classList.add('paused');badge.textContent='سامانه خاموش';}
            else if(ready){badge.classList.add('ready');badge.textContent='آماده';}
            else{badge.classList.add('incomplete');badge.textContent='نیازمند تکمیل';}
        }
        return !active||!globalActive||ready;
    }
    function refresh(){cards.forEach(updateCard);}
    cards.forEach(card=>card.addEventListener('input',()=>{card.classList.remove('client-error');updateCard(card);}));
    globalToggle&&globalToggle.addEventListener('change',refresh);
    mode&&mode.addEventListener('change',()=>{if(api)api.placeholder=mode.value==='live'?'API Key حساب SMS.ir':'در Sandbox الزامی نیست';});

    document.querySelectorAll('.sms-field.template input,.sms-field.number input,.sms-field.phone input').forEach(input=>{
        input.addEventListener('blur',()=>{input.value=normalizeDigits(input.value).replace(/[\s,،٬]/g,'');});
    });
    document.querySelectorAll('.sms-field.param input').forEach(input=>{
        input.addEventListener('blur',()=>{
            input.value=(input.value||'').trim().replace(/^[#{}\[\]()\s]+|[#{}\[\]()\s]+$/g,'');
        });
    });
    document.querySelectorAll('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{
        const target=document.getElementById(button.dataset.copy||'');
        if(!target)return;
        try{await navigator.clipboard.writeText(target.textContent||'');button.textContent='کپی شد ✓';setTimeout(()=>button.textContent='کپی متن قالب',1400);}catch(e){button.textContent='کپی نشد';}
    }));
    form&&form.addEventListener('submit',event=>{
        let firstBad=null;
        cards.forEach(card=>{
            card.classList.remove('client-error');
            if(!updateCard(card)){card.classList.add('client-error');if(!firstBad)firstBad=card;}
        });
        if(globalToggle&&globalToggle.checked&&mode&&mode.value==='live'&&api&&!api.value.trim()&&<?=json_encode($hasApiKey)?>===false){
            event.preventDefault();api.focus();alert('برای حالت واقعی، API Key را وارد کنید.');return;
        }
        if(firstBad){event.preventDefault();firstBad.scrollIntoView({behavior:'smooth',block:'center'});}
    });
    refresh();
})();
</script>
<?php require __DIR__.'/partials/footer.php';
