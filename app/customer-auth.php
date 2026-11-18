<?php
declare(strict_types=1);

function customer_user(bool $refresh=false): ?array
{
    static $loaded=false,$user=null;
    if ($refresh) {$loaded=false;$user=null;}
    if ($loaded) return $user;
    $loaded=true;
    $id=(int)($_SESSION['_customer_id']??0);
    if($id<1)return null;
    $stmt=db()->prepare('SELECT * FROM customers WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$id]);
    $row=$stmt->fetch();
    if(!$row){unset($_SESSION['_customer_id']);return null;}
    return $user=$row;
}

function customer_logged_in(): bool { return customer_user()!==null; }
function customer_login(int $id): void { session_regenerate_id(true);$_SESSION['_customer_id']=$id;$_SESSION['_customer_authenticated_at']=time();customer_user(true); }
function customer_logout(): void
{
    // فقط داده‌های احراز هویت مشتری پاک می‌شوند؛ سبد خرید و اطلاعات عمومی نشست باقی می‌مانند.
    foreach(array_keys($_SESSION) as $key){
        $name=(string)$key;
        if(str_starts_with($name,'_otp_')||str_starts_with($name,'_customer_')||$name==='_google_oauth')unset($_SESSION[$key]);
    }
    unset($_SESSION['_customer_id'],$_SESSION['_customer_authenticated_at']);
    if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
    customer_user(true);
}
function customer_required(): array { $u=customer_user();if(!$u){$_SESSION['_customer_return']=(safe_local_redirect((string)($_SERVER['REQUEST_URI']??'')) ?? app_url('account.php'));redirect(app_url('customer-login.php'));}return $u; }

function customer_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function customer_password_is_strong(string $password): bool
{
    return text_length($password)>=8&&text_length($password)<=200&&strlen($password)<=1024&&preg_match('/\p{L}/u',$password)===1&&preg_match('/\d/u',$password)===1;
}

function customer_otp_hash(string $phone,string $purpose,string $code): string
{
    return hash_hmac('sha256',$phone.'|'.$purpose.'|'.$code,application_secret());
}

function customer_otp_session_key(string $purpose,string $suffix='phone'): string
{
    return '_otp_'.preg_replace('/[^a-z0-9_-]/i','',$purpose).'_'.$suffix;
}

function customer_request_otp(string $phone,string $purpose='login'): array
{
    $phone=normalize_phone($phone);$purpose=text_substr(preg_replace('/[^a-z0-9_-]/i','',$purpose)??'',0,40);
    if(!preg_match('/^09\d{9}$/',$phone))throw new RuntimeException('شماره موبایل معتبر نیست.');
    if($purpose==='')throw new RuntimeException('هدف کد تأیید معتبر نیست.');
    if(!sms_enabled())throw new RuntimeException('سامانه پیامک هنوز از پنل مدیریت فعال نشده است.');
    if(sms_is_sandbox())throw new RuntimeException('ورود پیامکی در حالت آزمایشی غیرفعال است. برای ارسال کد واقعی، حالت پیامک را روی «واقعی» قرار دهید.');
    if(in_array($purpose,['login','register','register_phone'],true)){$state=db()->prepare('SELECT is_active FROM customers WHERE phone=? LIMIT 1');$state->execute([$phone]);$active=$state->fetchColumn();if($active!==false&&(int)$active!==1)throw new RuntimeException('این حساب غیرفعال است. برای بررسی با پشتیبانی تماس بگیرید.');}
    $cooldown=max(30,min(600,(int)setting('sms_otp_cooldown_seconds','60')));
    $ipBucket='otp-ip:'.client_ip();
    $ipRetry=rate_limit_retry_after($ipBucket,5,600);
    if($ipRetry>0)throw new RuntimeException('تعداد درخواست‌های این اتصال زیاد است. کمی بعد تلاش کنید.');
    $bucket='otp-'.$purpose.':'.$phone;
    $retry=rate_limit_retry_after($bucket,1,$cooldown);
    if($retry>0)throw new RuntimeException('ارسال دوباره کد تا '.fa_number($retry).' ثانیه دیگر امکان‌پذیر است.');
    $length=max(4,min(8,(int)setting('sms_otp_length','5')));
    $min=10**($length-1);$max=(10**$length)-1;$code=(string)random_int($min,$max);
    $expiry=max(1,min(15,(int)setting('sms_otp_expiry_minutes','2')));
    $pdo=db();
    $pdo->exec("DELETE FROM customer_otp_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    rate_limit_record($ipBucket,600);rate_limit_record($bucket,$cooldown);
    $pdo->prepare('UPDATE customer_otp_codes SET used_at=NOW() WHERE phone=? AND purpose=? AND used_at IS NULL')->execute([$phone,$purpose]);
    $stmt=$pdo->prepare('INSERT INTO customer_otp_codes(phone,purpose,code_hash,expires_at,request_ip) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL ? MINUTE),?)');
    $stmt->execute([$phone,$purpose,customer_otp_hash($phone,$purpose,$code),$expiry,client_ip()]);
    $otpId=(int)$pdo->lastInsertId();
    try { sms_send_otp($phone,$code); }
    catch(Throwable $e){$pdo->prepare('UPDATE customer_otp_codes SET used_at=NOW() WHERE id=?')->execute([$otpId]);throw $e;}
    $_SESSION[customer_otp_session_key($purpose)]=$phone;
    return ['phone'=>$phone,'expires_in'=>$expiry*60];
}

function customer_consume_otp(string $phone,string $code,string $purpose): void
{
    $phone=normalize_phone($phone);$code=normalize_digits(trim($code));
    if(!preg_match('/^09\d{9}$/',$phone)||!preg_match('/^\d{4,8}$/',$code))throw new RuntimeException('شماره موبایل یا کد تأیید معتبر نیست.');
    $maxAttempts=max(3,min(10,(int)setting('sms_otp_max_attempts','5')));
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM customer_otp_codes WHERE phone=? AND purpose=? AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');$stmt->execute([$phone,$purpose]);$otp=$stmt->fetch();
        if(!$otp||strtotime((string)$otp['expires_at'])<time())throw new RuntimeException('کد تأیید منقضی شده است. کد جدید دریافت کنید.');
        if((int)$otp['attempts']>=$maxAttempts)throw new RuntimeException('تعداد تلاش مجاز تمام شده است. کد جدید دریافت کنید.');
        if(!hash_equals((string)$otp['code_hash'],customer_otp_hash($phone,$purpose,$code))){$pdo->prepare('UPDATE customer_otp_codes SET attempts=attempts+1 WHERE id=?')->execute([$otp['id']]);$pdo->commit();throw new RuntimeException('کد تأیید نادرست است.');}
        $pdo->prepare('UPDATE customer_otp_codes SET used_at=NOW() WHERE id=?')->execute([$otp['id']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_verify_otp(string $phone,string $code,string $purpose='login'): array
{
    $phone=normalize_phone($phone);
    $precheck=db()->prepare('SELECT is_active FROM customers WHERE phone=? LIMIT 1');$precheck->execute([$phone]);$existingState=$precheck->fetchColumn();
    if($existingState!==false && (int)$existingState!==1)throw new RuntimeException('این حساب غیرفعال است. برای بررسی با پشتیبانی تماس بگیرید.');
    customer_consume_otp($phone,$code,$purpose);
    $pdo=db();$pdo->beginTransaction();
    try{
        $find=$pdo->prepare('SELECT * FROM customers WHERE phone=? LIMIT 1 FOR UPDATE');$find->execute([$phone]);$customer=$find->fetch();
        if($customer){
            if((int)$customer['is_active']!==1)throw new RuntimeException('این حساب غیرفعال است. برای بررسی با پشتیبانی تماس بگیرید.');
            $pdo->prepare('UPDATE customers SET phone_verified_at=COALESCE(phone_verified_at,NOW()),last_login_at=NOW() WHERE id=?')->execute([(int)$customer['id']]);
        }else{
            $pdo->prepare('INSERT INTO customers(phone,marketing_sms_enabled,phone_verified_at,last_login_at,is_active) VALUES(?,0,NOW(),NOW(),1)')->execute([$phone]);
            $id=(int)$pdo->lastInsertId();$find=$pdo->prepare('SELECT * FROM customers WHERE id=? LIMIT 1');$find->execute([$id]);$customer=$find->fetch();
        }
        if(!$customer)throw new RuntimeException('ساخت حساب مشتری انجام نشد.');
        $pdo->prepare('UPDATE orders SET customer_id=? WHERE customer_id IS NULL AND phone=?')->execute([(int)$customer['id'],$phone]);
        $refresh=$pdo->prepare('SELECT * FROM customers WHERE id=? LIMIT 1');$refresh->execute([(int)$customer['id']]);$customer=$refresh->fetch()?:$customer;
        $pdo->commit();customer_login((int)$customer['id']);unset($_SESSION[customer_otp_session_key($purpose)]);return $customer;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_begin_registration(string $firstName,string $lastName,string $phone,string $email,string $password,string $confirm): array
{
    $firstName=trim($firstName);$lastName=trim($lastName);$phone=normalize_phone($phone);$email=customer_normalize_email($email);
    if(text_length($firstName)<2||text_length($firstName)>80)throw new RuntimeException('نام باید بین ۲ تا ۸۰ کاراکتر باشد.');
    if(text_length($lastName)<2||text_length($lastName)>100)throw new RuntimeException('نام خانوادگی باید بین ۲ تا ۱۰۰ کاراکتر باشد.');
    if(!preg_match('/^09\d{9}$/',$phone))throw new RuntimeException('شماره همراه معتبر نیست.');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||text_length($email)>190)throw new RuntimeException('ایمیل معتبر نیست.');
    if(!customer_password_is_strong($password))throw new RuntimeException('رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.');
    if(!hash_equals($password,$confirm))throw new RuntimeException('تکرار رمز عبور یکسان نیست.');
    $exists=db()->prepare('SELECT id,phone,email,password_hash,is_active FROM customers WHERE phone=? OR email=? ORDER BY (phone=?) DESC LIMIT 1');$exists->execute([$phone,$email,$phone]);$existing=$exists->fetch();
    $existingId=0;
    if($existing){
        if((int)$existing['is_active']!==1)throw new RuntimeException('این حساب غیرفعال است. برای بررسی با پشتیبانی تماس بگیرید.');
        if(normalize_phone((string)($existing['phone']??''))!==$phone||(!empty($existing['email'])&&customer_normalize_email((string)$existing['email'])!==$email))throw new RuntimeException('این ایمیل یا شماره همراه در حساب دیگری ثبت شده است.');
        if(!empty($existing['password_hash']))throw new RuntimeException('با این مشخصات قبلاً حساب ساخته شده است. وارد حساب شوید.');
        $existingId=(int)$existing['id'];
    }
    $result=customer_request_otp($phone,'register');
    $_SESSION['_customer_register_pending']=['existing_customer_id'=>$existingId,'first_name'=>$firstName,'last_name'=>$lastName,'phone'=>$phone,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'created_at'=>time()];
    return $result;
}

function customer_pending_registration(): ?array
{
    $pending=$_SESSION['_customer_register_pending']??null;
    if(!is_array($pending)||(int)($pending['created_at']??0)<time()-900){unset($_SESSION['_customer_register_pending']);return null;}
    return $pending;
}

function customer_complete_registration(string $code): array
{
    $pending=customer_pending_registration();if(!$pending)throw new RuntimeException('اطلاعات ثبت‌نام منقضی شده است. دوباره ثبت‌نام کنید.');
    $phone=(string)$pending['phone'];customer_consume_otp($phone,$code,'register');
    $pdo=db();$pdo->beginTransaction();
    try{
        $existingId=(int)($pending['existing_customer_id']??0);
        $exists=$pdo->prepare('SELECT id FROM customers WHERE (phone=? OR email=?) AND id<>? LIMIT 1 FOR UPDATE');$exists->execute([$phone,$pending['email'],$existingId]);
        if($exists->fetchColumn())throw new RuntimeException('این شماره همراه یا ایمیل قبلاً ثبت شده است.');
        $fullName=trim($pending['first_name'].' '.$pending['last_name']);
        if($existingId>0){
            $locked=$pdo->prepare('SELECT id,is_active FROM customers WHERE id=? AND phone=? LIMIT 1 FOR UPDATE');$locked->execute([$existingId,$phone]);$lockedCustomer=$locked->fetch();if(!$lockedCustomer)throw new RuntimeException('حساب قبلی برای تکمیل ثبت‌نام پیدا نشد.');
            if((int)$lockedCustomer['is_active']!==1)throw new RuntimeException('این حساب غیرفعال است. برای بررسی با پشتیبانی تماس بگیرید.');
            $pdo->prepare('UPDATE customers SET first_name=?,last_name=?,full_name=?,email=?,password_hash=?,phone_verified_at=COALESCE(phone_verified_at,NOW()),last_login_at=NOW() WHERE id=?')->execute([$pending['first_name'],$pending['last_name'],$fullName,$pending['email'],$pending['password_hash'],$existingId]);$id=$existingId;
        }else{
            $pdo->prepare('INSERT INTO customers(phone,first_name,last_name,full_name,email,password_hash,marketing_sms_enabled,phone_verified_at,last_login_at) VALUES(?,?,?,?,?,?,0,NOW(),NOW())')->execute([$phone,$pending['first_name'],$pending['last_name'],$fullName,$pending['email'],$pending['password_hash']]);$id=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE orders SET customer_id=? WHERE customer_id IS NULL AND phone=?')->execute([$id,$phone]);$pdo->commit();
        unset($_SESSION['_customer_register_pending'],$_SESSION[customer_otp_session_key('register')]);customer_login($id);
        $find=db()->prepare('SELECT * FROM customers WHERE id=?');$find->execute([$id]);return $find->fetch()?:[];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_password_login(string $identifier,string $password): array
{
    $identifier=trim($identifier);$phone=normalize_phone($identifier);$isPhone=preg_match('/^09\d{9}$/',$phone)===1;$email=$isPhone?'':customer_normalize_email($identifier);$bucketValue=$isPhone?$phone:$email;$bucket='customer-password-login:'.hash('sha256',$bucketValue).'|'.client_ip();$window=900;
    $retry=rate_limit_retry_after($bucket,8,$window);if($retry>0)throw new RuntimeException('تعداد تلاش ورود زیاد است. کمی بعد دوباره تلاش کنید.');
    rate_limit_record($bucket,$window);
    if($isPhone){$stmt=db()->prepare('SELECT * FROM customers WHERE phone=? AND phone_verified_at IS NOT NULL AND is_active=1 LIMIT 1');$stmt->execute([$phone]);}
    else{$stmt=db()->prepare('SELECT * FROM customers WHERE email=? AND email_verified_at IS NOT NULL AND is_active=1 LIMIT 1');$stmt->execute([$email]);}
    $customer=$stmt->fetch();
    $hash=is_array($customer)?(string)($customer['password_hash']??''):'';
    $valid=$hash!==''&&password_verify($password,$hash);
    if(!$valid){if($hash==='')password_verify($password,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');throw new RuntimeException('شماره همراه، ایمیل تأییدشده یا رمز عبور نادرست است.');}
    if(password_needs_rehash($hash,PASSWORD_DEFAULT))db()->prepare('UPDATE customers SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$customer['id']]);
    db()->prepare('UPDATE customers SET last_login_at=NOW() WHERE id=?')->execute([$customer['id']]);rate_limit_clear($bucket);customer_login((int)$customer['id']);return $customer;
}

function customer_google_client_id(): string { return trim((string)setting('google_client_id','')); }
function customer_google_client_secret(): string { return reveal_secret((string)setting('google_client_secret_enc','')); }
function customer_google_enabled(): bool { return (string)setting('google_auth_enabled','0')==='1'&&customer_google_client_id()!==''&&customer_google_client_secret()!==''; }
function customer_google_redirect_uri(): string { return app_url('google-callback.php'); }
function customer_base64url(string $bytes): string { return rtrim(strtr(base64_encode($bytes),'+/','-_'),'='); }

function customer_google_authorization_url(): string
{
    if(!customer_google_enabled())throw new RuntimeException('ورود با Google هنوز از پنل مدیریت فعال نشده است.');
    $redirect=customer_google_redirect_uri();$host=(string)(parse_url($redirect,PHP_URL_HOST)??'');
    if(!str_starts_with($redirect,'https://')&&!in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('برای ورود با Google باید آدرس سایت HTTPS باشد.');
    $state=customer_base64url(random_bytes(32));$verifier=customer_base64url(random_bytes(64));
    $_SESSION['_google_oauth']=['state'=>$state,'verifier'=>$verifier,'created_at'=>time(),'return'=>safe_local_redirect((string)($_SESSION['_customer_return']??''))];
    return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
        'client_id'=>customer_google_client_id(),'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>'openid email profile','state'=>$state,
        'code_challenge'=>customer_base64url(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256','prompt'=>'select_account','access_type'=>'online',
    ],'','&',PHP_QUERY_RFC3986);
}

function customer_google_http(string $url,array $post=[],string $bearer=''): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
    $curl=curl_init($url);$headers=['Accept: application/json'];
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers];
    if($bearer!=='')$options[CURLOPT_HTTPHEADER][]='Authorization: Bearer '.$bearer;
    if($post){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($post,'','&',PHP_QUERY_RFC3986);$options[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded';}
    curl_setopt_array($curl,$options);$raw=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
    if(!is_string($raw)){log_error(new RuntimeException('Google cURL error: '.($error?:'unknown')));throw new RuntimeException('ارتباط امن با Google برقرار نشد. کمی بعد دوباره تلاش کنید.');}
    if(strlen($raw)>1024*1024)throw new RuntimeException('پاسخ Google بیش از حد بزرگ است.');
    $json=json_decode($raw,true);if(!is_array($json)||$http<200||$http>=300)throw new RuntimeException('تأیید حساب Google انجام نشد. دوباره تلاش کنید.');return $json;
}

function customer_google_email_available(PDO $pdo,string $email,int $exceptCustomerId=0): bool
{
    $sql='SELECT id,email_verified_at,google_sub FROM customers WHERE email=?';$params=[$email];
    if($exceptCustomerId>0){$sql.=' AND id<>?';$params[]=$exceptCustomerId;}
    $sql.=' LIMIT 1 FOR UPDATE';$stmt=$pdo->prepare($sql);$stmt->execute($params);$owner=$stmt->fetch();
    if(!$owner)return true;
    if(!empty($owner['email_verified_at'])||trim((string)($owner['google_sub']??''))!=='')return false;
    $release=$pdo->prepare("UPDATE customers SET email=NULL WHERE id=? AND email_verified_at IS NULL AND (google_sub IS NULL OR google_sub='')");$release->execute([(int)$owner['id']]);
    return $release->rowCount()===1;
}

function customer_google_complete(string $code,string $state): array
{
    $pending=$_SESSION['_google_oauth']??null;unset($_SESSION['_google_oauth']);
    if(!is_array($pending)||(int)($pending['created_at']??0)<time()-600||!is_string($pending['state']??null)||!hash_equals($pending['state'],$state))throw new RuntimeException('درخواست ورود Google معتبر نیست یا منقضی شده است.');
    if(!preg_match('/^[A-Za-z0-9._\/-]{10,2048}$/',$code))throw new RuntimeException('کد ورود Google معتبر نیست.');
    $tokens=customer_google_http('https://oauth2.googleapis.com/token',[
        'client_id'=>customer_google_client_id(),'client_secret'=>customer_google_client_secret(),'code'=>$code,'code_verifier'=>$pending['verifier'],
        'grant_type'=>'authorization_code','redirect_uri'=>customer_google_redirect_uri(),
    ]);
    $accessToken=(string)($tokens['access_token']??'');if($accessToken===''||strlen($accessToken)>4096)throw new RuntimeException('توکن ورود Google دریافت نشد.');
    $profile=customer_google_http('https://openidconnect.googleapis.com/v1/userinfo',[],$accessToken);
    $sub=trim((string)($profile['sub']??''));$email=customer_normalize_email((string)($profile['email']??''));
    if($sub===''||strlen($sub)>255||!filter_var($email,FILTER_VALIDATE_EMAIL)||($profile['email_verified']??false)!==true)throw new RuntimeException('ایمیل تأییدشده از Google دریافت نشد.');
    $first=text_substr(trim((string)($profile['given_name']??'')),0,80);$last=text_substr(trim((string)($profile['family_name']??'')),0,100);$name=text_substr(trim((string)($profile['name']??trim($first.' '.$last))),0,160);
    $picture=trim((string)($profile['picture']??''));if(!filter_var($picture,FILTER_VALIDATE_URL)||!str_starts_with($picture,'https://'))$picture='';
    $linkCustomerId=max(0,(int)($pending['link_customer_id']??0));
    $pdo=db();$pdo->beginTransaction();
    try{
        $bySubStmt=$pdo->prepare('SELECT * FROM customers WHERE google_sub=? LIMIT 1 FOR UPDATE');$bySubStmt->execute([$sub]);$bySub=$bySubStmt->fetch();
        if($linkCustomerId>0){
            $targetStmt=$pdo->prepare('SELECT * FROM customers WHERE id=? LIMIT 1 FOR UPDATE');$targetStmt->execute([$linkCustomerId]);$customer=$targetStmt->fetch();
            if(!$customer||(int)$customer['is_active']!==1)throw new RuntimeException('حساب فعلی فعال نیست و اتصال Google انجام نشد.');
            if($bySub&&(int)$bySub['id']!==$linkCustomerId)throw new RuntimeException('این حساب Google قبلاً به حساب مشتری دیگری متصل شده است.');
            $currentSub=trim((string)($customer['google_sub']??''));
            if($currentSub!==''&&!hash_equals($currentSub,$sub))throw new RuntimeException('حساب فعلی قبلاً به Google دیگری متصل شده است.');
            $emailAvailable=customer_google_email_available($pdo,$email,$linkCustomerId);
            $currentEmail=customer_normalize_email((string)($customer['email']??''));$currentEmailVerified=!empty($customer['email_verified_at']);$storeGoogleEmail=$emailAvailable&&(!$currentEmailVerified||$currentEmail===''||hash_equals($currentEmail,$email));
            $first=(string)($customer['first_name']?:$first);$last=(string)($customer['last_name']?:$last);$name=(string)($customer['full_name']?:($name?:trim($first.' '.$last)));
            $pdo->prepare('UPDATE customers SET first_name=?,last_name=?,full_name=?,email=?,google_sub=?,google_picture_url=?,email_verified_at=IF(?,NOW(),email_verified_at),last_login_at=NOW() WHERE id=?')->execute([$first?:null,$last?:null,$name?:null,$storeGoogleEmail?$email:($customer['email']?:null),$sub,$picture?:null,$storeGoogleEmail?1:0,$linkCustomerId]);$id=$linkCustomerId;
        }elseif($bySub){
            $customer=$bySub;if((int)$customer['is_active']!==1)throw new RuntimeException('این حساب غیرفعال است. برای بررسی با پشتیبانی تماس بگیرید.');
            $emailAvailable=customer_google_email_available($pdo,$email,(int)$customer['id']);
            $first=(string)($customer['first_name']?:$first);$last=(string)($customer['last_name']?:$last);$name=(string)($customer['full_name']?:($name?:trim($first.' '.$last)));
            $pdo->prepare('UPDATE customers SET first_name=?,last_name=?,full_name=?,email=?,google_picture_url=?,email_verified_at=IF(?,NOW(),email_verified_at),last_login_at=NOW() WHERE id=?')->execute([$first?:null,$last?:null,$name?:null,$emailAvailable?$email:($customer['email']?:null),$picture?:null,$emailAvailable?1:0,$customer['id']]);$id=(int)$customer['id'];
        }else{
            if(!customer_google_email_available($pdo,$email))throw new RuntimeException('این ایمیل تأییدشده قبلاً به حساب دیگری متصل است. ابتدا وارد همان حساب شوید.');
            $pdo->prepare('INSERT INTO customers(phone,first_name,last_name,full_name,email,google_sub,google_picture_url,email_verified_at,last_login_at) VALUES(NULL,?,?,?,?,?,?,NOW(),NOW())')->execute([$first?:null,$last?:null,$name?:null,$email,$sub,$picture?:null]);$id=(int)$pdo->lastInsertId();
        }
        $pdo->commit();customer_login($id);$refresh=db()->prepare('SELECT * FROM customers WHERE id=?');$refresh->execute([$id]);return $refresh->fetch()?:[];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function customer_default_address(int $customerId): ?array
{
    $stmt=db()->prepare('SELECT * FROM customer_addresses WHERE customer_id=? ORDER BY is_default DESC,id DESC LIMIT 1');$stmt->execute([$customerId]);return $stmt->fetch()?:null;
}
