<?php
declare(strict_types=1);

function application_secret_fallback(): string
{
    try {
        $config = require BASE_PATH . '/config/database.php';
        return hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE) . '|' . BASE_PATH);
    } catch (Throwable) {
        return hash('sha256', BASE_PATH . '|mousavi-store-fallback');
    }
}

function application_secret_file_state(): array
{
    $path = BASE_PATH . '/storage/app.key';
    if (!is_file($path)) return ['exists'=>false,'valid'=>false,'readable'=>false,'path'=>$path];
    $raw = @file_get_contents($path);
    $readable = is_string($raw);
    $secret = $readable ? trim($raw) : '';
    return [
        'exists'=>true,
        'valid'=>preg_match('/^[a-f0-9]{64}$/i', $secret) === 1,
        'readable'=>$readable,
        'path'=>$path,
    ];
}

function application_secret(): string
{
    $path = BASE_PATH . '/storage/app.key';
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $secret = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^[a-f0-9]{64}$/i', $secret)) return strtolower($secret);

        // فایل موجود اما خراب/ناخوانا را هرگز خودکار جایگزین نکن؛ چرخش ناخواسته
        // این کلید همه رازهای ذخیره‌شده از جمله API Key پیامک را غیرقابل‌خواندن می‌کند.
        log_error(new RuntimeException('storage/app.key exists but is invalid or unreadable.'));
        return application_secret_fallback();
    }

    $secret = bin2hex(random_bytes(32));
    $directory = dirname($path);
    if ((is_dir($directory) || @mkdir($directory, 0755, true)) && is_writable($directory)) {
        // ایجاد اتمیک با حالت x؛ در اجرای هم‌زمان کلید موجود بازنویسی نمی‌شود.
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $written = @fwrite($handle, $secret);
            @fflush($handle);
            @fclose($handle);
            if ($written === strlen($secret)) {
                @chmod($path, 0640);
                return $secret;
            }
            @unlink($path);
        } elseif (is_file($path)) {
            $existing = trim((string)@file_get_contents($path));
            if (preg_match('/^[a-f0-9]{64}$/i', $existing)) return strtolower($existing);
        }
    }

    // روی هاست بدون دسترسی نوشتن، کلید پایدار مشتق‌شده مانع تغییر کلید در هر درخواست می‌شود.
    return application_secret_fallback();
}

function protect_secret(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') return '';
    $key = hash('sha256', application_secret(), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher)) throw new RuntimeException('رمزنگاری اطلاعات محرمانه انجام نشد.');
    return 'v1:' . rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
}

function reveal_secret(string $protected): string
{
    $protected = trim($protected);
    if ($protected === '') return '';
    if (!str_starts_with($protected, 'v1:')) return $protected; // سازگاری با تنظیمات قدیمی
    $encoded = substr($protected, 3);
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
    if (!is_string($raw) || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', application_secret(), true), OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

function sms_enabled(): bool { return (string)setting('sms_enabled', '0') === '1'; }
function sms_is_sandbox(): bool { return (string)setting('sms_mode', 'sandbox') === 'sandbox'; }
function sms_api_key(): string { return reveal_secret((string)setting('sms_api_key_enc', '')); }
function sms_api_key_state(): array
{
    $stored = trim((string)setting('sms_api_key_enc', ''));
    $file = application_secret_file_state();
    if ($stored === '') return ['status'=>'missing','message'=>'API Key ذخیره نشده است.','stored'=>false,'readable'=>false,'key_file'=>$file];
    $plain = reveal_secret($stored);
    if ($plain !== '') return ['status'=>'ready','message'=>'API Key ذخیره‌شده قابل‌خواندن است.','stored'=>true,'readable'=>true,'key_file'=>$file];
    if (str_starts_with($stored, 'v1:')) {
        return [
            'status'=>'unreadable',
            'message'=>'API Key ذخیره شده اما رمزگشایی نمی‌شود؛ احتمالاً storage/app.key حذف یا تعویض شده است. API Key را دوباره وارد و ذخیره کنید.',
            'stored'=>true,'readable'=>false,'key_file'=>$file,
        ];
    }
    return ['status'=>'invalid','message'=>'مقدار API Key ذخیره‌شده معتبر نیست؛ آن را دوباره وارد کنید.','stored'=>true,'readable'=>false,'key_file'=>$file];
}
function sms_live_ready(): bool { return sms_enabled() && !sms_is_sandbox() && sms_api_key() !== ''; }

function sms_event_session_key(string $eventKey, ?string $referenceType, ?int $referenceId): string
{
    return $referenceType !== null && $referenceId !== null && $referenceId > 0
        ? $referenceType.':'.$referenceId.':'.$eventKey
        : '';
}

function sms_event_mark_session_sent(string $eventKey, ?string $referenceType, ?int $referenceId): void
{
    $key=sms_event_session_key($eventKey,$referenceType,$referenceId);
    if($key==='')return;
    if(!isset($_SESSION)||!is_array($_SESSION))return;
    $sent=is_array($_SESSION['_sms_sent_events']??null)?$_SESSION['_sms_sent_events']:[];
    $sent[$key]=time();
    foreach($sent as $storedKey=>$timestamp)if((int)$timestamp<time()-86400)unset($sent[$storedKey]);
    $_SESSION['_sms_sent_events']=array_slice($sent,-100,null,true);
}

function sms_event_last_log(string $eventKey, ?string $referenceType, ?int $referenceId): ?array
{
    if ($referenceType === null || $referenceId === null || $referenceId < 1) return null;
    try {
        $stmt = db()->prepare("SELECT status,response_message,created_at,sent_at FROM sms_logs WHERE event_key=? AND reference_type=? AND reference_id=? ORDER BY (status='sent') DESC,id DESC LIMIT 1");
        $stmt->execute([$eventKey,$referenceType,$referenceId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $exception) {
        log_error($exception);
        return null;
    }
}

function sms_event_should_dispatch(string $eventKey, ?string $referenceType, ?int $referenceId, bool $forceAttempt = true, int $retryCooldownSeconds = 300): bool
{
    $sessionKey=sms_event_session_key($eventKey,$referenceType,$referenceId);
    if($sessionKey!==''&&!empty($_SESSION['_sms_sent_events'][$sessionKey]))return false;
    $last = sms_event_last_log($eventKey,$referenceType,$referenceId);
    if (!$last) return true;
    if ((string)$last['status'] === 'sent') return false;
    if ($forceAttempt) return true;
    $createdAt = strtotime((string)($last['created_at'] ?? '')) ?: 0;
    return $createdAt < time() - max(30,$retryCooldownSeconds);
}

function sms_mobile(string $phone): string
{
    $phone = normalize_phone($phone);
    return preg_match('/^09\d{9}$/', $phone) ? $phone : '';
}

function smsir_api_mobile(string $phone): string
{
    $local = sms_mobile($phone);
    // نمونه‌های رسمی API v2 شماره ایران را بدون صفر ابتدایی می‌فرستند.
    return $local !== '' ? substr($local, 1) : '';
}

/**
 * شماره ثبت‌شده روی خود سفارش اولویت دارد؛ بنابراین خرید مهمان هیچ وابستگی
 * به وجود customer_id یا ثبت‌نام قبلی ندارد. شماره حساب فقط fallback است.
 */
function sms_order_recipient_phone(array $order): string
{
    $orderPhone=sms_mobile((string)($order['phone']??''));
    if($orderPhone!=='')return $orderPhone;
    return sms_mobile((string)($order['customer_phone']??''));
}

/**
 * SMS.ir Verify accepts at most 25 Unicode characters for each variable value.
 * This normalization is applied only to the outgoing SMS payload; database values
 * such as product title, order number and tracking code remain unchanged.
 */
function sms_parameter_value(string $name, mixed $value): string
{
    $text = is_scalar($value) || $value === null ? (string)$value : '';
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if ($text === '') $text = '—';

    if (text_length($text) <= 25) return $text;

    // Codes, order numbers, prices and other identifiers must not receive an ellipsis.
    $identifier = preg_match('/(?:code|order|return|ticket|otp|amount|price|number|id)/i', $name) === 1;
    return $identifier
        ? text_substr($text, 0, 25)
        : text_substr($text, 0, 24) . '…';
}

function sms_log_create(string $mobile, string $eventKey, ?int $templateId, array $payload, ?string $referenceType=null, ?int $referenceId=null): int
{
    if (random_int(1, 50) === 1) {
        try {
            $days=max(7,min(365,(int)setting('sms_log_retention_days','90')));
            db()->exec('DELETE FROM sms_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL '.(int)$days.' DAY)');
        } catch (Throwable $e) { log_error($e); }
    }
    $safePayload = $payload;
    foreach ($safePayload as &$parameter) {
        if (is_array($parameter) && preg_match('/code|otp|password/i', (string)($parameter['name'] ?? ''))) $parameter['value'] = '[secure]';
    }
    unset($parameter);
    $stmt = db()->prepare('INSERT INTO sms_logs(mobile,event_key,template_id,payload_json,status,reference_type,reference_id) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$mobile,$eventKey,$templateId,json_encode($safePayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),'pending',$referenceType,$referenceId]);
    return (int)db()->lastInsertId();
}

function sms_log_finish(int $logId, string $status, string $message, ?int $messageId=null, int|float|null $cost=null): void
{
    if ($logId < 1) return;
    try {
        db()->prepare("UPDATE sms_logs SET status=?,response_message=?,message_id=?,cost=?,sent_at=IF(?='sent',NOW(),sent_at) WHERE id=?")
            ->execute([$status,text_substr($message,0,500),$messageId,$cost,$status,$logId]);
        if($status==='failed'&&function_exists('admin_notify_sms_failure'))admin_notify_sms_failure($logId,$message);
    } catch (Throwable $e) { log_error($e); }
}

function smsir_http(string $method, string $path, ?array $payload=null): array
{
    $state = sms_api_key_state();
    $key = sms_api_key();
    if ($key === '') throw new RuntimeException((string)($state['message'] ?? 'کلید API پیامک در پنل مدیریت وارد نشده است.'));
    if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');

    $url = 'https://api.sms.ir' . $path;
    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($body)) throw new RuntimeException('ساخت درخواست پیامک انجام نشد.');
    }

    $lastNetworkError = '';
    $lastHttp = 0;
    $lastRaw = '';
    $methodUpper = strtoupper($method);
    $safeToRetryAfterSend = in_array($methodUpper,['GET','HEAD'],true);
    $attempts = 3;
    for ($attempt=1; $attempt<=$attempts; $attempt++) {
        $curl = curl_init($url);
        $headers = ['X-API-KEY: ' . $key, 'Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $methodUpper,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'GolrizanStore/2.10.35 SMS.ir Client',
        ];
        if (defined('CURLOPT_ENCODING')) $options[CURLOPT_ENCODING] = '';
        // بسیاری از هاست‌ها DNS IPv6 دارند اما مسیر خروجی IPv6 کامل نیست.
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $error = trim((string)curl_error($curl));
        $errno = (int)curl_errno($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $lastHttp = $http;
        $lastRaw = is_string($raw) ? $raw : '';
        $lastNetworkError = $error;
        // برای POST فقط خطاهایی تکرار می‌شوند که معمولاً پیش از ارسال بدنه رخ می‌دهند؛
        // timeout یا قطع پاسخ ممکن است بعد از پذیرش پیام باشد و تکرار آن پیامک دوگانه بسازد.
        $preSendNetworkError = in_array($errno,[5,6,7,35],true);
        $readNetworkError = in_array($errno,[18,28,52,55,56],true);
        $transientNetwork = !is_string($raw) && ($preSendNetworkError || ($safeToRetryAfterSend && $readNetworkError));
        $transientHttp = $safeToRetryAfterSend && ($http === 408 || $http === 425 || $http === 429 || $http >= 500);
        if (($transientNetwork || $transientHttp) && $attempt < $attempts) {
            usleep(200000 * $attempt);
            continue;
        }
        if (!is_string($raw)) {
            throw new RuntimeException('ارتباط با SMS.ir برقرار نشد: ' . ($error ?: 'خطای شبکه') . ($errno>0?' (cURL '.$errno.')':''));
        }

        $cleanRaw = preg_replace('/^\xEF\xBB\xBF/', '', trim($raw)) ?? trim($raw);
        $json = json_decode($cleanRaw, true);
        if (!is_array($json)) {
            $preview = text_substr(strip_tags($cleanRaw), 0, 160);
            throw new RuntimeException('پاسخ SMS.ir قابل‌خواندن نیست. HTTP '.$http.($preview!==''?'؛ پاسخ: '.$preview:''));
        }
        $json['_http'] = $http;
        return $json;
    }

    throw new RuntimeException('ارتباط با SMS.ir برقرار نشد: '.($lastNetworkError!==''?$lastNetworkError:'HTTP '.$lastHttp).($lastRaw!==''?'؛ پاسخ: '.text_substr(strip_tags($lastRaw),0,160):''));
}

function smsir_send_verify(string $phone, int $templateId, array $parameters, string $eventKey, ?string $referenceType=null, ?int $referenceId=null): array
{
    $mobile = sms_mobile($phone);
    $normalized = [];
    foreach ($parameters as $name => $value) {
        $name = trim((string)$name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]{1,50}$/', $name)) continue;
        $normalized[] = ['name'=>$name,'value'=>sms_parameter_value($name,$value)];
    }

    // خرابی جدول گزارش یا مهاجرت ناقص نباید جلوی ارسال واقعی را بگیرد.
    $logId = 0;
    try {
        $logId = sms_log_create($mobile !== '' ? $mobile : trim($phone),$eventKey,$templateId > 0 ? $templateId : null,$normalized,$referenceType,$referenceId);
    } catch (Throwable $logError) {
        log_error($logError);
    }

    try {
        if ($mobile === '') throw new RuntimeException('شماره موبایل پیامک معتبر نیست.');
        if (!sms_enabled()) {
            sms_log_finish($logId,'skipped','سامانه پیامک در پنل غیرفعال است.');
            return ['ok'=>false,'skipped'=>true,'message'=>'سامانه پیامک غیرفعال است.'];
        }
        if (sms_is_sandbox()) {
            sms_log_finish($logId,'skipped','حالت Sandbox؛ هیچ پیامکی به اپراتور ارسال نشد.');
            return ['ok'=>true,'skipped'=>true,'message'=>'Sandbox','message_id'=>null,'cost'=>0,'sandbox'=>true];
        }
        if ($templateId < 1) throw new RuntimeException('شناسه قالب پیامک در پنل مدیریت تنظیم نشده است.');
        if ($normalized === []) throw new RuntimeException('پارامترهای قالب پیامک کامل نیست.');

        $apiMobile = smsir_api_mobile($mobile);
        $result = smsir_http('POST','/v1/send/verify',['mobile'=>$apiMobile,'templateId'=>$templateId,'parameters'=>$normalized]);
        $http = (int)($result['_http'] ?? 0);
        $statusValue = $result['status'] ?? $result['Status'] ?? 0;
        $ok = in_array($statusValue,[1,'1',true],true) && $http >= 200 && $http < 300;
        $message = trim((string)($result['message'] ?? $result['Message'] ?? ($ok ? 'موفق' : 'ارسال ناموفق')));
        $data = $result['data'] ?? $result['Data'] ?? null;
        $messageId = null;
        $cost = null;
        if (is_array($data)) {
            if (isset($data['messageId']) && is_numeric($data['messageId'])) $messageId = (int)$data['messageId'];
            elseif (isset($data['message_id']) && is_numeric($data['message_id'])) $messageId = (int)$data['message_id'];
            if (isset($data['cost']) && is_numeric($data['cost'])) $cost = (float)$data['cost'];
        } elseif (is_numeric($data)) {
            $messageId = (int)$data;
        }
        sms_log_finish($logId,$ok?'sent':'failed',$message,$messageId,$cost);
        if (!$ok) throw new RuntimeException('SMS.ir: ' . ($message ?: 'ارسال پیامک ناموفق بود.') . ($http > 0 ? ' (HTTP '.$http.')' : ''));
        sms_event_mark_session_sent($eventKey,$referenceType,$referenceId);
        return ['ok'=>true,'message_id'=>$messageId,'cost'=>$cost,'sandbox'=>false];
    } catch (Throwable $e) {
        sms_log_finish($logId,'failed',$e->getMessage());
        throw $e;
    }
}


/**
 * SMS.ir ابتدا فقط پذیرش درخواست را برمی‌گرداند. نتیجه تحویل نهایی با شناسه
 * messageId از گزارش ارسال خوانده می‌شود. متن‌های این نگاشت برای مشتری ایمن
 * و بدون نمایش جزئیات محرمانه پنل یا API هستند.
 */
function sms_delivery_state_info(?int $state): array
{
    return match ($state) {
        1 => [
            'status'=>'delivered','label'=>'تحویل‌شده','level'=>'success','final'=>true,
            'reason'=>'پیامک با موفقیت به شماره همراه تحویل شده است.',
            'solution'=>'نیازی به اقدام نیست.',
        ],
        2 => [
            'status'=>'handset_failed','label'=>'به گوشی تحویل نشد','level'=>'error','final'=>true,
            'reason'=>'اپراتور نتوانسته پیامک را به گوشی مقصد تحویل دهد.',
            'solution'=>'روشن‌بودن گوشی، آنتن، فضای صندوق پیامک و مسدودنبودن فرستنده را بررسی کنید؛ سپس ارسال دوباره را بزنید.',
        ],
        3 => [
            'status'=>'telecom_received','label'=>'تحویل مخابرات شد','level'=>'info','final'=>false,
            'reason'=>'پیامک به شبکه مخابرات تحویل شده و نتیجه نهایی هنوز اعلام نشده است.',
            'solution'=>'چند دقیقه صبر کنید و وضعیت را دوباره بررسی کنید.',
        ],
        4 => [
            'status'=>'telecom_failed','label'=>'به مخابرات تحویل نشد','level'=>'error','final'=>true,
            'reason'=>'پیامک از مسیر اپراتور به شبکه مخابرات تحویل نشده است.',
            'solution'=>'کمی بعد دوباره تلاش کنید. اگر تکرار شد، فروشگاه باید مسیر یا سرشماره خدماتی را با SMS.ir بررسی کند.',
        ],
        5 => [
            'status'=>'operator_received','label'=>'تحویل اپراتور شد','level'=>'info','final'=>false,
            'reason'=>'اپراتور پیامک را دریافت کرده ولی تحویل نهایی به گوشی هنوز مشخص نیست.',
            'solution'=>'چند دقیقه صبر کنید و سپس وضعیت را تازه کنید.',
        ],
        6 => [
            'status'=>'failed','label'=>'ارسال ناموفق','level'=>'error','final'=>true,
            'reason'=>'اپراتور ارسال پیامک را ناموفق اعلام کرده است.',
            'solution'=>'شماره را بررسی و دوباره درخواست ارسال بدهید؛ در صورت تکرار با پشتیبانی فروشگاه تماس بگیرید.',
        ],
        7 => [
            'status'=>'blacklisted','label'=>'محدودیت دریافت پیامک','level'=>'warning','final'=>true,
            'reason'=>'شماره شما دریافت پیامک‌های تبلیغاتی یا انبوه را مسدود کرده و اپراتور پیام را تحویل نداده است.',
            'solution'=>'از راهنمای اپراتور پایین صفحه، دریافت پیامک‌های تبلیغاتی را فعال کنید؛ پس از دریافت تأیید اپراتور چند دقیقه صبر کرده و ارسال مجدد را بزنید.',
        ],
        8 => [
            'status'=>'unknown','label'=>'وضعیت نامشخص','level'=>'warning','final'=>false,
            'reason'=>'اپراتور هنوز نتیجه قابل‌اتکایی برای تحویل پیامک اعلام نکرده است.',
            'solution'=>'کمی بعد دوباره بررسی کنید؛ در صورت نرسیدن پیامک از ارسال مجدد یا پشتیبانی فروشگاه استفاده کنید.',
        ],
        9 => [
            'status'=>'rejected','label'=>'ردشده توسط مخابرات','level'=>'error','final'=>true,
            'reason'=>'شبکه مخابرات پیام را رد کرده است.',
            'solution'=>'کمی بعد دوباره تلاش کنید. اگر خطا ادامه داشت، فروشگاه باید متن و سرشماره خدماتی را با SMS.ir بررسی کند.',
        ],
        default => [
            'status'=>'pending','label'=>'در انتظار گزارش تحویل','level'=>'info','final'=>false,
            'reason'=>'درخواست ارسال پذیرفته شده ولی گزارش تحویل نهایی هنوز آماده نیست.',
            'solution'=>'معمولاً گزارش تحویل با کمی تأخیر ثبت می‌شود؛ یک دقیقه بعد دوباره بررسی کنید.',
        ],
    };
}

function sms_delivery_status_info(?string $status): array
{
    $state=match($status){
        'delivered'=>1,'handset_failed'=>2,'telecom_received'=>3,'telecom_failed'=>4,
        'operator_received'=>5,'failed'=>6,'blacklisted'=>7,'unknown'=>8,'rejected'=>9,
        default=>null,
    };
    return sms_delivery_state_info($state);
}


/**
 * راهنمای عمومی رفع محدودیت دریافت پیامک. به دلیل امکان ترابرد شماره، اپراتور
 * از روی پیش‌شماره حدس زده نمی‌شود و کاربر اپراتور واقعی سیم‌کارت را انتخاب می‌کند.
 */
function sms_blacklist_release_guide(): array
{
    return [
        'title'=>'چطور دریافت پیامک را فعال کنم؟',
        'intro'=>'اپراتور، پیام را به‌دلیل بسته‌بودن دریافت پیامک‌های تبلیغاتی یا انبوه تحویل نداده است. اپراتور واقعی سیم‌کارت خود را از راه‌های زیر انتخاب کنید.',
        'operators'=>[
            [
                'name'=>'همراه اول',
                'steps'=>[
                    'عدد ۲ را به ۸۹۹۹ پیامک کنید.',
                    'یا کد دستوری ‎*8999#‎ را شماره‌گیری و گزینه فعال‌سازی را انتخاب کنید.',
                    'همچنین می‌توانید از برنامه «همراه من» یا پشتیبانی ۹۹۹۰ استفاده کنید.',
                ],
            ],
            [
                'name'=>'ایرانسل',
                'steps'=>[
                    'کد دستوری ‎*800#‎ را شماره‌گیری و گزینه فعال‌سازی دریافت پیامک را انتخاب کنید.',
                    'یا در برنامه «ایرانسل من»، بخش مدیریت پیامک‌های تبلیغاتی را روی فعال قرار دهید.',
                    'در صورت نیاز از خط ایرانسل با پشتیبانی ۷۰۰ تماس بگیرید.',
                ],
            ],
            [
                'name'=>'رایتل و سایر اپراتورها',
                'steps'=>[
                    'در برنامه اپراتور، بخش مدیریت پیامک‌های انبوه و تبلیغاتی را فعال کنید.',
                    'برای رایتل می‌توانید منوی ‎*800#‎ یا ‎*195#‎ را نیز بررسی کنید.',
                    'اگر گزینه فعال‌سازی نمایش داده نشد، با پشتیبانی اپراتور سیم‌کارت تماس بگیرید.',
                ],
            ],
        ],
        'after'=>'پس از دریافت پیام تأیید اپراتور، چند دقیقه صبر کنید و سپس دکمه «ارسال مجدد پیامک» را بزنید.',
        'warning'=>'فعال‌سازی دریافت پیامک‌های تبلیغاتی ممکن است باعث دریافت پیامک‌های تبلیغاتی دیگر نیز شود. فروشگاه نیز می‌تواند با خدماتی‌کردن خط پیامکی، نیاز به این کار را کاهش دهد.',
    ];
}

function sms_notice_is_blacklisted(array $notice): bool
{
    return (string)($notice['delivery_status']??'')==='blacklisted';
}

function sms_order_event_can_be_resent(string $eventKey, array $order): bool
{
    if($eventKey==='order_created')return true;
    if($eventKey==='payment_status_paid')return (string)($order['payment_status']??'')==='paid';
    if(str_starts_with($eventKey,'order_status_')){
        $status=substr($eventKey,13);
        return $status!==''&&hash_equals((string)($order['order_status']??''),$status);
    }
    return false;
}

/**
 * خروجی این تابع فقط از متن‌های ثابت و مقادیر escape‌شده ساخته می‌شود و برای
 * نمایش مستقیم در View مناسب است.
 */
function sms_blacklist_release_guide_html(array $options=[]): string
{
    $guide=sms_blacklist_release_guide();
    $resendFields=is_array($options['resend_fields']??null)?$options['resend_fields']:[];
    $changeUrl=trim((string)($options['change_url']??''));
    $changeLabel=trim((string)($options['change_label']??'تغییر شماره موبایل'))?:'تغییر شماره موبایل';
    $compact=!empty($options['compact']);
    ob_start();
    ?>
    <section class="sms-blacklist-guide<?=$compact?' sms-blacklist-guide--compact':''?>" aria-label="راهنمای رفع محدودیت پیامک">
        <div class="sms-blacklist-guide__head"><span aria-hidden="true">!</span><div><strong><?=e($guide['title'])?></strong><p><?=e($guide['intro'])?></p></div></div>
        <div class="sms-blacklist-guide__operators">
            <?php foreach($guide['operators'] as $operator):?>
                <article><h4><?=e($operator['name'])?></h4><ol><?php foreach($operator['steps'] as $step):?><li><?=e($step)?></li><?php endforeach?></ol></article>
            <?php endforeach?>
        </div>
        <p class="sms-blacklist-guide__after"><strong>بعد از فعال‌سازی:</strong> <?=e($guide['after'])?></p>
        <p class="sms-blacklist-guide__warning"><?=e($guide['warning'])?></p>
        <?php if($resendFields!==[]||$changeUrl!==''):?><div class="sms-blacklist-guide__actions">
            <?php if($resendFields!==[]):?><form method="post" action="<?=e(app_url('sms-resend.php'))?>"><?=csrf_field()?><?php foreach($resendFields as $name=>$value):$fieldName=preg_replace('/[^a-z0-9_-]/i','',(string)$name);if($fieldName==='')continue;?><input type="hidden" name="<?=e($fieldName)?>" value="<?=e(is_scalar($value)?(string)$value:'')?>"><?php endforeach?><button class="btn btn-primary" type="submit">ارسال مجدد پیامک</button></form><?php endif?>
            <?php if($changeUrl!==''):?><a class="btn btn-secondary" href="<?=e($changeUrl)?>"><?=e($changeLabel)?></a><?php endif?>
        </div><?php endif?>
    </section>
    <?php
    return (string)ob_get_clean();
}

function sms_delivery_badge_class(?string $status): string
{
    return match ($status) {
        'delivered' => 'green',
        'blacklisted','pending','telecom_received','operator_received','unknown' => 'orange',
        'handset_failed','telecom_failed','failed','rejected' => 'red',
        default => '',
    };
}

function smsir_delivery_report(int $messageId): array
{
    if ($messageId < 1) throw new InvalidArgumentException('شناسه پیامک برای گزارش تحویل معتبر نیست.');
    $result=smsir_http('GET','/v1/send/'.$messageId);
    $http=(int)($result['_http']??0);
    if ((int)($result['status']??0)!==1 || $http<200 || $http>=300) {
        throw new RuntimeException('SMS.ir: '.trim((string)($result['message']??'دریافت گزارش تحویل ناموفق بود.')));
    }
    $data=$result['data']??null;
    if (is_array($data) && array_is_list($data)) $data=$data[0]??null;
    if (!is_array($data)) throw new RuntimeException('گزارش تحویل SMS.ir ساختار قابل‌خواندن ندارد.');
    $state=null;
    foreach (['deliveryState','delivery_state','DeliveryState'] as $key) {
        if (array_key_exists($key,$data) && $data[$key]!==null && is_numeric($data[$key])) {$state=(int)$data[$key];break;}
    }
    $deliveryTime=null;
    foreach (['deliveryDateTime','delivery_date_time','DeliveryDateTime'] as $key) {
        if (!array_key_exists($key,$data) || !is_numeric($data[$key])) continue;
        $timestamp=(int)$data[$key];
        if ($timestamp>20000000000) $timestamp=(int)floor($timestamp/1000);
        if ($timestamp>0) $deliveryTime=date('Y-m-d H:i:s',$timestamp);
        break;
    }
    return ['state'=>$state,'delivered_at'=>$deliveryTime,'raw'=>$data];
}

function sms_refresh_log_delivery(int $logId, bool $force=false): array
{
    $stmt=db()->prepare('SELECT * FROM sms_logs WHERE id=? LIMIT 1');
    $stmt->execute([$logId]);
    $log=$stmt->fetch();
    if(!$log)return ['ok'=>false,'skipped'=>true,'message'=>'گزارش پیامک پیدا نشد.'];
    if((string)($log['status']??'')!=='sent'||(int)($log['message_id']??0)<1){
        return ['ok'=>false,'skipped'=>true,'message'=>'این پیامک شناسه قابل پیگیری ندارد.','log'=>$log];
    }
    if(!sms_live_ready())return ['ok'=>false,'skipped'=>true,'message'=>'سامانه پیامک برای دریافت گزارش تحویل آماده نیست.','log'=>$log];
    $lastCheck=strtotime((string)($log['delivery_checked_at']??''))?:0;
    if(!$force&&$lastCheck>time()-60)return ['ok'=>true,'cached'=>true,'message'=>'گزارش کمتر از یک دقیقه قبل بررسی شده است.','log'=>$log];
    try{
        $report=smsir_delivery_report((int)$log['message_id']);
        $info=sms_delivery_state_info($report['state']);
        db()->prepare('UPDATE sms_logs SET delivery_state=?,delivery_status=?,delivery_reason=?,delivery_last_error=NULL,delivery_checked_at=NOW(),delivered_at=COALESCE(?,delivered_at) WHERE id=?')
            ->execute([$report['state'],$info['status'],text_substr($info['reason'],0,255),$report['delivered_at'],$logId]);
        $stmt->execute([$logId]);
        return ['ok'=>true,'message'=>'گزارش تحویل به‌روزرسانی شد.','info'=>$info,'log'=>$stmt->fetch()?:$log];
    }catch(Throwable $e){
        try{db()->prepare('UPDATE sms_logs SET delivery_last_error=?,delivery_checked_at=NOW() WHERE id=?')->execute([text_substr($e->getMessage(),0,255),$logId]);}catch(Throwable $dbError){log_error($dbError);}
        log_error($e);
        return ['ok'=>false,'message'=>$e->getMessage(),'log'=>$log];
    }
}

function sms_sync_delivery_reports(int $limit=20): array
{
    $limit=max(1,min(50,$limit));
    if(!sms_live_ready())return ['paused'=>true,'processed'=>0,'updated'=>0,'failed'=>0,'message'=>'سامانه پیامک برای گزارش تحویل آماده نیست.'];
    $sql="SELECT id FROM sms_logs
          WHERE status='sent' AND message_id IS NOT NULL AND message_id>0
            AND created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
            AND sent_at<=DATE_SUB(NOW(),INTERVAL 1 MINUTE)
            AND (delivery_checked_at IS NULL OR delivery_checked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))
            AND (delivery_status IS NULL OR delivery_status IN ('pending','telecom_received','operator_received','unknown'))
          ORDER BY id ASC LIMIT ".$limit;
    $ids=db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $summary=['paused'=>false,'processed'=>0,'updated'=>0,'failed'=>0];
    foreach($ids as $id){
        $summary['processed']++;
        $result=sms_refresh_log_delivery((int)$id,true);
        if(!empty($result['ok']))$summary['updated']++;else$summary['failed']++;
    }
    $summary['message']=$summary['processed']===0?'پیامکی برای به‌روزرسانی گزارش تحویل وجود ندارد.':'گزارش تحویل پیامک‌ها بررسی شد.';
    return $summary;
}

function sms_public_event_label(string $eventKey): string
{
    return match(true){
        $eventKey==='order_created'=>'ثبت سفارش',
        $eventKey==='payment_status_paid'=>'پرداخت موفق',
        str_starts_with($eventKey,'payment_status_')=>'وضعیت پرداخت',
        str_starts_with($eventKey,'order_status_')=>'وضعیت سفارش',
        str_starts_with($eventKey,'shipment_status_')=>'وضعیت ارسال',
        str_starts_with($eventKey,'return_status_')=>'وضعیت مرجوعی',
        $eventKey==='ticket_reply'=>'پاسخ پشتیبانی',
        $eventKey==='customer_otp'=>'کد تأیید',
        default=>'پیامک اطلاع‌رسانی',
    };
}

function sms_customer_delivery_notice(array $log): array
{
    $event=(string)($log['event_key']??'');
    $base=['event_key'=>$event,'event_label'=>sms_public_event_label($event),'level'=>'info','title'=>'وضعیت پیامک','message'=>'','solution'=>'','checked_at'=>$log['delivery_checked_at']??null];
    $sendStatus=(string)($log['status']??'');
    if($sendStatus==='failed')return array_merge($base,[
        'level'=>'error','title'=>'پیامک ارسال نشد','message'=>'سامانه نتوانست این پیامک را ارسال کند.','delivery_status'=>'send_failed',
        'solution'=>'درستی شماره همراه را بررسی کنید و دوباره درخواست ارسال بدهید؛ در صورت تکرار با پشتیبانی فروشگاه تماس بگیرید.',
    ]);
    if($sendStatus==='skipped')return array_merge($base,[
        'level'=>'warning','title'=>'پیامک واقعی ارسال نشد','message'=>'ارسال این پیامک در سامانه انجام نشده است.','delivery_status'=>'skipped',
        'solution'=>'برای بررسی تنظیمات و ارسال مجدد با پشتیبانی فروشگاه تماس بگیرید.',
    ]);
    if($sendStatus!=='sent')return array_merge($base,[
        'title'=>'پیامک در حال پردازش است','message'=>'درخواست پیامک هنوز در حال پردازش است.','delivery_status'=>'pending',
        'solution'=>'کمی صبر کنید و سپس صفحه را تازه کنید.',
    ]);
    $state=array_key_exists('delivery_state',$log)&&$log['delivery_state']!==null?(int)$log['delivery_state']:null;
    $info=sms_delivery_state_info($state);
    return array_merge($base,[
        'level'=>$info['level'],'title'=>$info['label'],'message'=>$info['reason'],'solution'=>$info['solution'],
        'delivery_status'=>$info['status'],'final'=>$info['final'],
    ]);
}

function sms_customer_order_delivery_notices(int $orderId, bool $refresh=true): array
{
    if($orderId<1)return [];
    $stmt=db()->prepare("SELECT * FROM sms_logs WHERE reference_type='order' AND reference_id=?
        AND (event_key='order_created' OR event_key LIKE 'payment_status_%' OR event_key LIKE 'order_status_%')
        ORDER BY id DESC");
    $stmt->execute([$orderId]);
    $latest=[];
    foreach($stmt->fetchAll() as $row){$key=(string)$row['event_key'];if(!isset($latest[$key]))$latest[$key]=$row;}
    if($refresh){
        foreach($latest as $key=>$row){
            $sentAt=strtotime((string)($row['sent_at']??$row['created_at']??''))?:0;
            $checkedAt=strtotime((string)($row['delivery_checked_at']??''))?:0;
            if((string)$row['status']==='sent'&&(int)($row['message_id']??0)>0&&$sentAt>0&&$sentAt<=time()-60&&$checkedAt<=time()-60){
                $result=sms_refresh_log_delivery((int)$row['id'],false);
                if(!empty($result['log']))$latest[$key]=$result['log'];
            }
        }
    }
    $notices=[];
    foreach($latest as $row)$notices[]=sms_customer_delivery_notice($row);
    usort($notices,static function(array $a,array $b):int{
        $priority=['order_created'=>10,'payment_status_paid'=>20];
        return ($priority[$a['event_key']]??30)<=>($priority[$b['event_key']]??30);
    });
    return $notices;
}

function sms_latest_mobile_event_log(string $phone,string $eventKey='customer_otp'): ?array
{
    $mobile=sms_mobile($phone);if($mobile==='')return null;
    $stmt=db()->prepare('SELECT * FROM sms_logs WHERE mobile=? AND event_key=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$mobile,$eventKey]);
    return $stmt->fetch()?:null;
}

function sms_customer_mobile_event_notice(string $phone,string $eventKey='customer_otp',bool $refresh=true): ?array
{
    $log=sms_latest_mobile_event_log($phone,$eventKey);if(!$log)return null;
    if($refresh&&(string)$log['status']==='sent'&&(int)($log['message_id']??0)>0){
        $sentAt=strtotime((string)($log['sent_at']??$log['created_at']??''))?:0;
        $checkedAt=strtotime((string)($log['delivery_checked_at']??''))?:0;
        if($sentAt>0&&$sentAt<=time()-60&&$checkedAt<=time()-60){$result=sms_refresh_log_delivery((int)$log['id'],false);if(!empty($result['log']))$log=$result['log'];}
    }
    return sms_customer_delivery_notice($log);
}

function smsir_credit(): array
{
    $result = smsir_http('GET','/v1/credit');
    if ((int)($result['status'] ?? 0) !== 1) throw new RuntimeException('SMS.ir: ' . (string)($result['message'] ?? 'دریافت اعتبار ناموفق بود.'));
    return ['credit'=>(float)($result['data'] ?? 0),'message'=>(string)($result['message'] ?? 'موفق')];
}

function sms_order_status_label(string $status): string { return function_exists('order_status_label') ? order_status_label($status) : $status; }

function sms_template_id(string $key): int { return max(0,(int)setting($key,'0')); }
function sms_param_name(string $key, string $default): string
{
    $name = trim((string)setting($key,$default));
    return preg_match('/^[A-Za-z0-9_]{1,50}$/',$name) ? $name : $default;
}

function sms_send_otp(string $phone, string $code): array
{
    if ((string)setting('sms_otp_enabled','1') !== '1') throw new RuntimeException('ورود پیامکی از پنل مدیریت غیرفعال است.');
    return smsir_send_verify($phone,sms_template_id('sms_otp_template_id'),[
        sms_param_name('sms_otp_param_code','CODE')=>$code,
    ],'customer_otp','customer',null);
}


function sms_payment_status_label(string $status): string { return function_exists('payment_status_label') ? payment_status_label($status) : $status; }

function sms_immediate_outcome(bool $attempted, bool $ok, bool $skipped, string $message, array $extra = []): array
{
    return array_merge([
        'attempted'=>$attempted,
        'ok'=>$ok,
        'skipped'=>$skipped,
        'message'=>$message,
    ],$extra);
}

function sms_order_payment_completed(array $order): bool
{
    $status=(string)($order['payment_status']??'');
    if(in_array($status,['paid','partially_refunded','refunded'],true))return true;
    // سازگاری با رکوردهای بسیار قدیمی که وضعیت پرداخت ندارند اما paid_at ثبت شده است.
    return $status===''&&!empty($order['paid_at']);
}

function sms_admin_flash_immediate_result(array $result, string $label): void
{
    $message=trim((string)($result['message']??''));
    if(!empty($result['ok'])&&empty($result['skipped'])){
        flash('success',$label.' فوراً ارسال شد و منتظر Cron نماند.');
        return;
    }
    if(!empty($result['sandbox'])){
        flash('error',$label.' ارسال واقعی نشد؛ سامانه پیامک روی حالت Sandbox است.');
        return;
    }
    if(!empty($result['skipped'])){
        flash('error',$label.' ارسال نشد'.($message!==''?'؛ '.$message:'.'));
        return;
    }
    flash('error',$label.' ناموفق بود'.($message!==''?'؛ '.$message:'.'));
}

function sms_notify_payment_status(int $orderId, string $status, bool $forceAttempt = true, bool $allowResendSent = false): array
{
    if ((string)setting('sms_payment_status_enabled','0')!=='1') {
        return sms_immediate_outcome(false,false,true,'ارسال پیامک وضعیت پرداخت در تنظیمات غیرفعال است.');
    }
    $eventKey='payment_status_'.$status;
    if (!$allowResendSent && !sms_event_should_dispatch($eventKey,'order',$orderId,$forceAttempt)) {
        return sms_immediate_outcome(false,false,true,'این وضعیت پرداخت قبلاً پیامک شده است.');
    }
    try {
        $stmt=db()->prepare('SELECT o.id,o.order_number,o.phone,o.payment_status,o.paid_at,c.phone customer_phone FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
        $stmt->execute([$orderId]);
        $o=$stmt->fetch();
        if(!$o) return sms_immediate_outcome(false,false,true,'سفارش پیدا نشد.');
        if(!sms_order_payment_completed($o)) return sms_immediate_outcome(false,false,true,'تا تکمیل پرداخت سفارش، هیچ پیامک تراکنشی برای مشتری ارسال نمی‌شود.');
        $result=smsir_send_verify(sms_order_recipient_phone($o),sms_template_id('sms_payment_status_template_id'),[
            sms_param_name('sms_payment_param_order','Order')=>$o['order_number'],
            sms_param_name('sms_payment_param_status','Status')=>sms_payment_status_label($status),
        ],$eventKey,'order',(int)$o['id']);
        return sms_immediate_outcome(true,!empty($result['ok']),!empty($result['skipped']),(string)($result['message']??''),$result);
    } catch (Throwable $e) {
        log_error($e);
        return sms_immediate_outcome(true,false,false,$e->getMessage());
    }
}

function sms_notify_order_created(int $orderId, bool $forceAttempt = true, bool $allowResendSent = false): array
{
    $customerOutcome=sms_immediate_outcome(false,false,true,'پیامک ثبت سفارش در تنظیمات غیرفعال است.');
    $adminOutcome=sms_immediate_outcome(false,false,true,'پیامک مدیر سفارش جدید غیرفعال است.');
    try {
        $stmt=db()->prepare('SELECT o.id,o.order_number,o.customer_name,o.phone,o.total,o.payment_status,o.paid_at,c.phone customer_phone FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
        $stmt->execute([$orderId]);
        $o=$stmt->fetch();
        if(!$o)return ['customer'=>sms_immediate_outcome(false,false,true,'سفارش پیدا نشد.'),'admin'=>$adminOutcome];
    } catch (Throwable $e) {
        log_error($e);
        return ['customer'=>sms_immediate_outcome(false,false,false,'خواندن سفارش برای پیامک ناموفق بود.'),'admin'=>$adminOutcome];
    }

    if ((string)setting('sms_order_created_enabled','0')==='1') {
        $recipient=sms_order_recipient_phone($o);
        if(!sms_order_payment_completed($o)){
            $customerOutcome=sms_immediate_outcome(false,false,true,'ثبت سفارش انجام شد؛ پیامک مشتری تا تکمیل پرداخت متوقف می‌ماند.');
        }elseif($recipient===''){
            $customerOutcome=sms_immediate_outcome(false,false,true,'شماره موبایل معتبر روی سفارش ثبت نشده است.');
        }elseif(!$allowResendSent&&!sms_event_should_dispatch('order_created','order',(int)$o['id'],$forceAttempt)){
            $customerOutcome=sms_immediate_outcome(false,true,true,'پیامک ثبت سفارش قبلاً با موفقیت ارسال شده است.');
        }else{
            try {
                $result=smsir_send_verify($recipient,sms_template_id('sms_order_created_template_id'),[
                    sms_param_name('sms_order_param_order','Order')=>$o['order_number'],
                    sms_param_name('sms_order_param_name','Name')=>$o['customer_name'],
                    sms_param_name('sms_order_param_amount','Amount')=>format_price((int)$o['total']),
                ],'order_created','order',(int)$o['id']);
                $customerOutcome=sms_immediate_outcome(true,!empty($result['ok']),!empty($result['skipped']),(string)($result['message']??''),$result);
            } catch (Throwable $e) {
                log_error($e);
                $customerOutcome=sms_immediate_outcome(true,false,false,$e->getMessage());
            }
        }
    }

    $adminMobile=normalize_phone((string)setting('sms_admin_mobile',''));
    if ((string)setting('sms_admin_new_order_enabled','0')==='1') {
        if(!preg_match('/^09\d{9}$/',$adminMobile)){
            $adminOutcome=sms_immediate_outcome(false,false,true,'شماره مدیر معتبر تنظیم نشده است.');
        }elseif(!sms_event_should_dispatch('admin_new_order','order',(int)$o['id'],$forceAttempt)){
            $adminOutcome=sms_immediate_outcome(false,true,true,'پیامک مدیر قبلاً ارسال شده است.');
        }else{
            try {
                $result=smsir_send_verify($adminMobile,sms_template_id('sms_admin_new_order_template_id'),[
                    sms_param_name('sms_admin_param_order','Order')=>$o['order_number'],
                    sms_param_name('sms_admin_param_name','Name')=>$o['customer_name'],
                    sms_param_name('sms_admin_param_amount','Amount')=>format_price((int)$o['total']),
                ],'admin_new_order','order',(int)$o['id']);
                $adminOutcome=sms_immediate_outcome(true,!empty($result['ok']),!empty($result['skipped']),(string)($result['message']??''),$result);
            } catch (Throwable $e) {
                log_error($e);
                $adminOutcome=sms_immediate_outcome(true,false,false,$e->getMessage());
            }
        }
    }
    return ['customer'=>$customerOutcome,'admin'=>$adminOutcome,'phone'=>sms_order_recipient_phone($o)];
}


function sms_resend_order_event(int $orderId,string $eventKey): array
{
    if($orderId<1)return sms_immediate_outcome(false,false,true,'سفارش معتبر نیست.');
    if($eventKey==='order_created'){
        $result=sms_notify_order_created($orderId,true,true);
        return is_array($result['customer']??null)?$result['customer']:sms_immediate_outcome(true,false,false,'نتیجه ارسال پیامک مشخص نیست.');
    }
    if($eventKey==='payment_status_paid'){
        $stmt=db()->prepare('SELECT payment_status FROM orders WHERE id=? LIMIT 1');$stmt->execute([$orderId]);
        if((string)$stmt->fetchColumn()!=='paid')return sms_immediate_outcome(false,false,true,'پرداخت این سفارش هنوز موفق نیست.');
        return sms_notify_payment_status($orderId,'paid',true,true);
    }
    if(str_starts_with($eventKey,'order_status_')){
        $status=substr($eventKey,13);
        $stmt=db()->prepare('SELECT order_status FROM orders WHERE id=? LIMIT 1');$stmt->execute([$orderId]);
        $current=(string)$stmt->fetchColumn();
        if($status===''||!hash_equals($current,$status))return sms_immediate_outcome(false,false,true,'فقط پیامک وضعیت فعلی سفارش قابل ارسال مجدد است.');
        return sms_notify_order_status($orderId,$status,true,true);
    }
    return sms_immediate_outcome(false,false,true,'این نوع پیامک از این مسیر قابل ارسال مجدد نیست.');
}

/**
 * تلاش پشتیبان برای پیامک‌های حیاتی خرید است. ارسال اولیه همچنان همان لحظه بعد از
 * ثبت سفارش/تأیید پرداخت انجام می‌شود؛ Cron فقط شکست‌های ۲۴ ساعت اخیر را حداکثر
 * سه بار دوباره امتحان می‌کند.
 */
function sms_retry_failed_order_messages(int $limit=10): array
{
    $limit=max(1,min(50,$limit));
    if(!sms_live_ready())return ['paused'=>true,'processed'=>0,'sent'=>0,'failed'=>0,'message'=>'سامانه پیامک برای تلاش مجدد آماده نیست.'];

    $enabledEvents=[];
    if((string)setting('sms_order_created_enabled','0')==='1'&&sms_template_id('sms_order_created_template_id')>0)$enabledEvents[]='order_created';
    if((string)setting('sms_payment_status_enabled','0')==='1'&&sms_template_id('sms_payment_status_template_id')>0)$enabledEvents[]='payment_status_paid';
    if(!$enabledEvents)return ['paused'=>true,'processed'=>0,'sent'=>0,'failed'=>0,'message'=>'قالب فعال ثبت سفارش یا پرداخت موفق وجود ندارد.'];

    $marks=implode(',',array_fill(0,count($enabledEvents),'?'));
    $sql="SELECT s.event_key,s.reference_id,COUNT(*) attempts,MAX(s.created_at) last_attempt
          FROM sms_logs s
          WHERE s.status='failed' AND s.reference_type='order' AND s.reference_id IS NOT NULL
            AND s.event_key IN ($marks)
            AND s.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)
            AND NOT EXISTS (
                SELECT 1 FROM sms_logs ok
                WHERE ok.event_key=s.event_key AND ok.reference_type=s.reference_type
                  AND ok.reference_id=s.reference_id AND ok.status='sent'
            )
          GROUP BY s.event_key,s.reference_id
          HAVING attempts<3 AND last_attempt<=DATE_SUB(NOW(),INTERVAL 60 SECOND)
          ORDER BY last_attempt ASC LIMIT ".(int)$limit;
    $stmt=db()->prepare($sql);$stmt->execute($enabledEvents);$rows=$stmt->fetchAll();
    $summary=['paused'=>false,'processed'=>0,'sent'=>0,'failed'=>0,'message'=>'پیامک ناموفقی برای تلاش مجدد وجود ندارد.'];
    foreach($rows as $row){
        $summary['processed']++;
        try{
            if((string)$row['event_key']==='order_created'){
                $result=sms_notify_order_created((int)$row['reference_id'],true);
                $outcome=is_array($result['customer']??null)?$result['customer']:[];
            }else{
                $outcome=sms_notify_payment_status((int)$row['reference_id'],'paid',true);
            }
            if(!empty($outcome['ok'])&&empty($outcome['skipped']))$summary['sent']++;
            else $summary['failed']++;
        }catch(Throwable $e){$summary['failed']++;log_error($e);}
    }
    if($summary['processed']>0)$summary['message']='تلاش مجدد پیامک‌های حیاتی خرید انجام شد.';
    return $summary;
}

function sms_notify_order_status(int $orderId, string $status, bool $forceAttempt = true, bool $allowResendSent = false): array
{
    if ((string)setting('sms_order_status_enabled','0')!=='1') {
        return sms_immediate_outcome(false,false,true,'ارسال پیامک تغییر وضعیت سفارش در تنظیمات غیرفعال است.');
    }
    $eventKey='order_status_'.$status;
    if (!$allowResendSent && !sms_event_should_dispatch($eventKey,'order',$orderId,$forceAttempt)) {
        return sms_immediate_outcome(false,false,true,'این وضعیت سفارش قبلاً پیامک شده است.');
    }
    try {
        $stmt=db()->prepare('SELECT o.id,o.order_number,o.phone,o.tracking_code,o.payment_status,o.paid_at,c.phone customer_phone FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
        $stmt->execute([$orderId]);
        $o=$stmt->fetch();
        if(!$o) return sms_immediate_outcome(false,false,true,'سفارش پیدا نشد.');
        if(!sms_order_payment_completed($o)) return sms_immediate_outcome(false,false,true,'تا تکمیل پرداخت سفارش، پیامک تغییر وضعیت برای مشتری ارسال نمی‌شود.');
        $statusText=sms_order_status_label($status);
        $result=smsir_send_verify(sms_order_recipient_phone($o),sms_template_id('sms_order_status_template_id'),[
            sms_param_name('sms_status_param_order','Order')=>$o['order_number'],
            sms_param_name('sms_status_param_status','Status')=>$statusText,
        ],$eventKey,'order',(int)$o['id']);
        return sms_immediate_outcome(true,!empty($result['ok']),!empty($result['skipped']),(string)($result['message']??''),$result);
    } catch (Throwable $e) {
        log_error($e);
        return sms_immediate_outcome(true,false,false,$e->getMessage());
    }
}

function sms_notify_after_payment_completed(int $orderId): array
{
    $result=['order_created'=>null,'order_status'=>null,'shipment_status'=>null];
    $created=sms_notify_order_created($orderId,true,false);
    $result['order_created']=is_array($created['customer']??null)?$created['customer']:null;
    try{
        $stmt=db()->prepare('SELECT order_status FROM orders WHERE id=? LIMIT 1');
        $stmt->execute([$orderId]);$status=(string)$stmt->fetchColumn();
        if($status!==''&&!in_array($status,['new','pending_payment'],true)){
            $result['order_status']=sms_notify_order_status($orderId,$status,true,false);
        }
        if(function_exists('shipment_for_order')&&function_exists('sms_notify_shipment_status')){
            $shipment=shipment_for_order($orderId);
            if(is_array($shipment)&&!empty($shipment['id'])&&trim((string)($shipment['tracking_code']??''))!==''){
                $result['shipment_status']=sms_notify_shipment_status((int)$shipment['id'],(string)$shipment['status'],true,false);
            }
        }
    }catch(Throwable $e){log_error($e);}
    return $result;
}

function sms_notify_return_status(int $returnId, string $status, bool $forceAttempt = true): void
{
    if ((string)setting('sms_return_status_enabled','0')!=='1') return;
    $eventKey='return_status_'.$status;
    if (!sms_event_should_dispatch($eventKey,'return',$returnId,$forceAttempt)) return;
    try {
        $stmt=db()->prepare('SELECT r.id,r.return_number,o.phone,c.phone customer_phone FROM return_requests r JOIN orders o ON o.id=r.order_id LEFT JOIN customers c ON c.id=o.customer_id WHERE r.id=?');
        $stmt->execute([$returnId]);
        $row=$stmt->fetch();
        if(!$row)return;
        smsir_send_verify(sms_order_recipient_phone($row),sms_template_id('sms_return_status_template_id'),[
            sms_param_name('sms_return_param_number','Return')=>$row['return_number'],
            sms_param_name('sms_return_param_status','Status')=>return_status_label($status),
        ],$eventKey,'return',(int)$row['id']);
    } catch (Throwable $e) { log_error($e); }
}
