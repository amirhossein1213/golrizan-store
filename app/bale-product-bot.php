<?php
declare(strict_types=1);

function bale_product_bot_normalize_token(string $token): string
{
    $token = html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $token = preg_replace('/^[\x{FEFF}\x{200B}-\x{200F}\x{2060}]+|[\x{FEFF}\x{200B}-\x{200F}\x{2060}]+$/u', '', $token) ?? $token;
    $token = trim($token);

    // Accept common copied forms without ever logging or displaying the secret.
    if (preg_match('#^https?://tapi\.bale\.ai/bot([^/?#]+)(?:/[^?#]*)?(?:[?#].*)?$#i', $token, $match)) {
        $token = rawurldecode((string)$match[1]);
    }
    $token = preg_replace('/^(?:Bearer|Token)\s+/i', '', $token) ?? $token;
    if (preg_match('/^bot(.{20,})$/i', $token, $match) && str_contains((string)$match[1], ':')) {
        $token = (string)$match[1];
    }

    // Copy/paste may insert line breaks or invisible ASCII whitespace.
    $token = preg_replace('/[\x00-\x20\x7F]+/', '', $token) ?? $token;
    return trim($token);
}

function bale_product_bot_token(): string
{
    return bale_product_bot_normalize_token(reveal_secret((string)setting('bale_product_bot_token_enc', '')));
}

function bale_product_bot_enabled(): bool
{
    return (string)setting('bale_product_bot_enabled', '0') === '1' && bale_product_bot_token() !== '';
}

/** @return array<int,string> */
function bale_product_bot_allowed_ids(): array
{
    $raw = preg_split('/[\s,;]+/', trim((string)setting('bale_product_bot_allowed_ids', ''))) ?: [];
    $ids = [];
    foreach ($raw as $id) {
        $id = trim((string)$id);
        if (preg_match('/^-?\d{1,20}$/', $id)) $ids[$id] = $id;
    }
    return array_values($ids);
}

function bale_product_bot_is_allowed(string $userId): bool
{
    return in_array($userId, bale_product_bot_allowed_ids(), true);
}

/** @return array<int,string> */
function bale_product_bot_capabilities(): array
{
    return ['products','orders','tickets','returns','reports','payments'];
}

/** @return array<string,array<int,string>> */
function bale_product_bot_permission_map(): array
{
    $raw = trim((string)setting('bale_product_bot_permissions_json',''));
    if ($raw === '') return [];
    $decoded = json_decode($raw,true);
    if (!is_array($decoded)) return [];
    $valid = array_flip(bale_product_bot_capabilities());$map=[];
    foreach ($decoded as $id=>$permissions) {
        $id=(string)$id;if(!preg_match('/^-?\d{1,20}$/',$id))continue;
        if ($permissions === 'full') {$map[$id]=bale_product_bot_capabilities();continue;}
        if (!is_array($permissions)) continue;
        $caps=[];foreach($permissions as $cap){$cap=trim((string)$cap);if(isset($valid[$cap]))$caps[$cap]=$cap;}
        $legacyFull=['products','orders','tickets','returns','reports'];if(!isset($caps['payments'])&&count(array_diff($legacyFull,array_keys($caps)))===0)$caps['payments']='payments';
        $map[$id]=array_values($caps);
    }
    return $map;
}

/** @return array<int,string> */
function bale_product_bot_user_permissions(string $userId): array
{
    if (!bale_product_bot_is_allowed($userId)) return [];
    $map=bale_product_bot_permission_map();
    // Backward compatibility for installations upgraded from older versions: existing allowed IDs stay full-access until rules are saved.
    return array_key_exists($userId,$map) ? $map[$userId] : bale_product_bot_capabilities();
}

function bale_product_bot_can(string $userId,string $capability): bool
{
    return in_array($capability,bale_product_bot_user_permissions($userId),true);
}

function bale_product_bot_runtime_user(?string $set = null): string
{
    static $current='';
    if ($set !== null) $current=$set;
    return $current;
}

function bale_product_bot_runtime_chat(?string $set = null): string
{
    static $current='';
    if ($set !== null) $current=$set;
    return $current;
}

function bale_product_bot_command_capability(string $text): ?string
{
    return match ($text) {
        '📦 محصولات','💰 قیمت و تخفیف','➕ افزودن سریع محصول','✏️ ویرایش محصول','🗑 حذف محصول','🔗 لینک محصولات','➕ افزودن دسته','➕ افزودن برند','🗑 حذف دسته','📂 مدیریت دسته‌ها','🏷 مدیریت برندها','🖼 مدیریت تصاویر','🪄 اصلاح عکس‌های قبلی','⚡ قیمت/موجودی سریع','📊 تغییر گروهی قیمت','📈 افزایش/کاهش درصدی','⏳ تخفیف محدود','🟣 جشنواره ترب‌پی' => 'products',
        '🧾 سفارش‌ها','🧾 مدیریت سفارش‌ها' => 'orders',
        '🎫 تیکت‌ها','🎫 تیکت‌های مشتریان' => 'tickets',
        '↩️ مرجوعی‌ها','↩️ درخواست‌های مرجوعی' => 'returns',
        '📊 گزارش‌ها','📊 گزارش فروش امروز','⚠️ موجودی کم' => 'reports',
        '💳 درصد ترب‌پی','💳 درصد افزایش ترب‌پی' => 'payments',
        default => null,
    };
}

function bale_product_bot_callback_capability(string $data): ?string
{
    if ($data==='' || $data==='cancel' || $data==='page:noop:0') return null;
    if (str_starts_with($data,'adv:ret:')) return 'returns';
    if (str_starts_with($data,'adv:tkt:')) return 'tickets';
    if (str_starts_with($data,'adv:low:')) return 'reports';
    if (str_starts_with($data,'ord:') || str_starts_with($data,'pick:o:') || str_starts_with($data,'page:o:')) return 'orders';
    if (str_starts_with($data,'adv:tax:') || str_starts_with($data,'adv:taxpage:') || str_starts_with($data,'adv:taxmenu:') || str_starts_with($data,'adv:img:') || str_starts_with($data,'adv:imgrepair:') || str_starts_with($data,'adv:q:') || str_starts_with($data,'adv:vq:')) return 'products';
    if (str_starts_with($data,'tpf:')) return 'products';
    if (str_starts_with($data,'bpa:')) return 'products';
    if (preg_match('/^(?:page|pick):(c|b|ec|eb|dc|dp|ep|pl):/', $data)) return 'products';
    if (preg_match('/^(?:pe|price|opt|attr|cd|pd):/', $data)) return 'products';
    return null;
}

function bale_product_bot_step_capability(string $step): ?string
{
    if (str_starts_with($step,'order_')) return 'orders';
    if (str_starts_with($step,'adv_ticket')) return 'tickets';
    if (str_starts_with($step,'adv_return')) return 'returns';
    if (str_starts_with($step,'adv_low')) return 'reports';
    if ($step==='torobpay_markup_input') return 'payments';
    if (str_starts_with($step,'torobpay_festival_')) return 'products';
    if (str_starts_with($step,'bulk_price_')) return 'products';
    if (in_array($step,['title','category','brand','price','stock','description','features','specifications','warranty','authenticity','safe_shipping','festival_price','festival_free_shipping','home_festival','images','confirm','status','processing','price_panel','price_main_input','price_festival_input','product_options','warranty_input','attribute_groups','attribute_values','attribute_new_name','attribute_new_type','attribute_new_value','product_edit_select','product_edit_category','product_edit_brand','product_delete_select','product_delete_confirm','product_link_select','category_delete_select','category_delete_confirm','add_category_name','add_brand_name'],true)) return 'products';
    if (str_starts_with($step,'product_edit_') || str_starts_with($step,'adv_image') || str_starts_with($step,'adv_quick') || str_starts_with($step,'adv_variant_quick') || str_starts_with($step,'adv_category') || str_starts_with($step,'adv_brand') || str_starts_with($step,'adv_tax')) return 'products';
    return null;
}

function bale_product_bot_callback_family(string $data): string
{
    if ($data === '' || $data === 'page:noop:0' || $data === 'cancel' || str_starts_with($data,'cancel:') || $data === 'nav:home') return 'global';
    if (str_starts_with($data,'tpf:')) return 'limited_discount';
    if (str_starts_with($data,'bpa:')) return 'bulk_price';
    if (str_starts_with($data,'ord:') || str_starts_with($data,'pick:o:') || str_starts_with($data,'page:o:')) return 'orders';
    if (str_starts_with($data,'adv:ret:')) return 'returns';
    if (str_starts_with($data,'adv:tkt:')) return 'tickets';
    if (str_starts_with($data,'adv:low:')) return 'low_stock';
    if (str_starts_with($data,'adv:tax:') || str_starts_with($data,'adv:taxpage:') || str_starts_with($data,'adv:taxmenu:')) return 'taxonomy';
    if (str_starts_with($data,'adv:imgrepair:')) return 'image_repair';
    if (str_starts_with($data,'adv:img:')) return 'images';
    if (str_starts_with($data,'adv:q:') || str_starts_with($data,'adv:vq:')) return 'quick_edit';
    if (preg_match('/^(?:page|pick):(c|b|ec|eb|dc|dp|ep|pl):/', $data)) return 'products';
    if (preg_match('/^(?:pe|price|opt|attr|cd|pd):/', $data)) return 'products';
    return 'unknown';
}

function bale_product_bot_session_family(string $step): string
{
    if (str_starts_with($step,'torobpay_festival_')) return 'limited_discount';
    if (str_starts_with($step,'bulk_price_')) return 'bulk_price';
    if (str_starts_with($step,'order_')) return 'orders';
    if (str_starts_with($step,'adv_return')) return 'returns';
    if (str_starts_with($step,'adv_ticket')) return 'tickets';
    if (str_starts_with($step,'adv_low')) return 'low_stock';
    if (str_starts_with($step,'adv_tax') || str_starts_with($step,'adv_category') || str_starts_with($step,'adv_brand') || in_array($step,['add_category_name','add_brand_name'],true)) return 'taxonomy';
    if ($step === 'adv_image_repair') return 'image_repair';
    if (str_starts_with($step,'adv_image')) return 'images';
    if (str_starts_with($step,'adv_quick') || str_starts_with($step,'adv_variant_quick')) return 'quick_edit';
    if ($step === 'torobpay_markup_input') return 'payments';
    if (bale_product_bot_step_capability($step) === 'products') return 'products';
    return 'unknown';
}

function bale_product_bot_callback_matches_session(?array $session, string $data): bool
{
    if (!$session) return true;
    $callbackFamily=bale_product_bot_callback_family($data);
    if ($callbackFamily === 'global') return true;
    if ($callbackFamily === 'unknown') return false;
    $sessionFamily=bale_product_bot_session_family((string)($session['step']??''));
    if ($sessionFamily === $callbackFamily) return true;
    return $sessionFamily === 'low_stock' && $callbackFamily === 'quick_edit'
        && (preg_match('/^adv:(?:q|vq):menu:\d+$/',$data) === 1);
}

function bale_product_bot_permission_denied(string $chatId,string $userId,string $capability): void
{
    $labels=['products'=>'محصولات و موجودی','orders'=>'سفارش‌ها','tickets'=>'تیکت‌ها','returns'=>'مرجوعی‌ها','reports'=>'گزارش‌ها','payments'=>'تنظیمات پرداخت'];
    bale_product_bot_send($chatId,'⛔ دسترسی شما به بخش «'.($labels[$capability]??$capability).'» فعال نیست.',bale_product_bot_main_keyboard($userId));
}


function bale_product_bot_order_notifications_enabled(): bool
{
    return bale_product_bot_enabled() && (string)setting('bale_product_bot_order_notifications','1') === '1';
}

function bale_product_bot_payment_notifications_enabled(): bool
{
    return bale_product_bot_enabled() && (string)setting('bale_product_bot_payment_notifications','1') === '1';
}

function bale_product_bot_order_management_enabled(): bool
{
    return bale_product_bot_enabled() && (string)setting('bale_product_bot_order_management','1') === '1';
}

function bale_product_bot_delivery_claim(string $eventKey, string $chatId): bool
{
    $eventKey = text_substr(trim($eventKey),0,190);
    $chatId = text_substr(trim($chatId),0,64);
    if ($eventKey === '' || $chatId === '') return false;
    try {
        $stmt = db()->prepare("INSERT IGNORE INTO bale_product_bot_deliveries(event_key,chat_id,status,created_at,updated_at) VALUES(?,?,'sending',NOW(),NOW())");
        $stmt->execute([$eventKey,$chatId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $error) {
        log_error($error);
        return true;
    }
}

function bale_product_bot_delivery_sent(string $eventKey, string $chatId, int $messageId): void
{
    try {
        db()->prepare("UPDATE bale_product_bot_deliveries SET status='sent',message_id=?,error_message=NULL,updated_at=NOW() WHERE event_key=? AND chat_id=?")
            ->execute([$messageId > 0 ? $messageId : null,$eventKey,$chatId]);
    } catch (Throwable $error) { log_error($error); }
}

function bale_product_bot_delivery_failed(string $eventKey, string $chatId, string $message): void
{
    try {
        db()->prepare('DELETE FROM bale_product_bot_deliveries WHERE event_key=? AND chat_id=? AND status=\'sending\'')->execute([$eventKey,$chatId]);
    } catch (Throwable $error) { log_error($error); }
    log_error(new RuntimeException('ارسال اعلان بله انجام نشد: '.text_substr($message,0,400)));
}

/** @param array<string,mixed>|null $markup */
function bale_product_bot_notify_allowed(string $eventKey, string $text, ?array $markup = null, ?string $requiredCapability = null): int
{
    if (!bale_product_bot_enabled()) return 0;
    $sent = 0;
    foreach (bale_product_bot_allowed_ids() as $chatId) {
        if ($requiredCapability !== null && !bale_product_bot_can($chatId,$requiredCapability)) continue;
        if (!bale_product_bot_delivery_claim($eventKey,$chatId)) continue;
        try {
            $response = bale_product_bot_send($chatId,$text,$markup);
            $result = is_array($response['result']??null) ? $response['result'] : [];
            bale_product_bot_delivery_sent($eventKey,$chatId,max(0,(int)($result['message_id']??0)));
            $sent++;
        } catch (Throwable $error) {
            bale_product_bot_delivery_failed($eventKey,$chatId,$error->getMessage());
        }
    }
    return $sent;
}

/** @param array<string,mixed> $params @return array<string,mixed> */
function bale_product_bot_api(string $method, array $params = [], ?string $tokenOverride = null): array
{
    $token = bale_product_bot_normalize_token($tokenOverride ?? bale_product_bot_token());
    if ($token === '') throw new RuntimeException('توکن ربات بله تنظیم نشده است.');
    if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
    $url = 'https://tapi.bale.ai/bot' . $token . '/' . rawurlencode($method);
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('ساخت ارتباط با بله انجام نشد.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($params, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    $body = curl_exec($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $error !== '') throw new RuntimeException('ارتباط با بله انجام نشد: ' . ($error !== '' ? $error : 'پاسخ خالی'));
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) throw new RuntimeException('پاسخ بله قابل خواندن نیست.');
    if ($http < 200 || $http >= 300 || empty($decoded['ok'])) {
        $message = trim(scalar_string($decoded['description'] ?? $decoded['message'] ?? 'خطای نامشخص بله'));
        if ($http === 401 || preg_match('/unauthori[sz]ed|invalid\s*token|token\s*invalid/i', $message)) {
            throw new RuntimeException('بله توکن را نپذیرفت (Unauthorized). فقط توکن خام ربات بله را وارد کنید؛ بدون کلمه bot، بدون نشانی API و بدون فاصله. اگر توکن را لغو یا دوباره ساخته‌اید، توکن جدید را کامل وارد کنید.');
        }
        throw new RuntimeException('بله: ' . ($message !== '' ? $message : 'درخواست ناموفق بود.') . ($http > 0 ? ' (HTTP ' . $http . ')' : ''));
    }
    return $decoded;
}

/** @param array<int,array<int,string>> $rows @return array<string,mixed> */
function bale_product_bot_keyboard(array $rows, bool $oneTime = false): array
{
    $keyboard = [];
    $hasCancel = false;
    foreach ($rows as $row) {
        $buttons = [];
        foreach ($row as $text) {
            $text = trim((string)$text);
            if ($text === '') continue;
            if (in_array($text, ['❌ لغو','❌ لغو عملیات','🧹 لغو عملیات'], true)) $hasCancel = true;
            $buttons[] = ['text'=>$text];
        }
        if ($buttons) $keyboard[] = $buttons;
    }
    if (!$hasCancel) $keyboard[] = [['text'=>'❌ لغو عملیات']];
    return ['keyboard'=>$keyboard,'resize_keyboard'=>true,'one_time_keyboard'=>$oneTime,'selective'=>true];
}

/** @param array<string,mixed>|null $markup @return array<string,mixed> */
function bale_product_bot_send(string $chatId, string $text, ?array $markup = null): array
{
    $params = ['chat_id'=>(preg_match('/^-?\d+$/', $chatId) ? (int)$chatId : $chatId), 'text'=>text_substr($text, 0, 3900)];
    if ($markup !== null) $params['reply_markup'] = $markup;
    return bale_product_bot_api('sendMessage', $params);
}

/** @param array<string,mixed>|null $markup @return array<string,mixed> */
function bale_product_bot_edit(string $chatId, int $messageId, string $text, ?array $markup = null): array
{
    if ($messageId < 1) return [];
    $params = [
        'chat_id'=>(preg_match('/^-?\d+$/', $chatId) ? (int)$chatId : $chatId),
        'message_id'=>$messageId,
        'text'=>text_substr($text, 0, 3900),
    ];
    if ($markup !== null) $params['reply_markup'] = $markup;
    return bale_product_bot_api('editMessageText', $params);
}

function bale_product_bot_answer_callback(string $callbackId, string $text = ''): void
{
    if ($callbackId === '') return;
    try {
        $params = ['callback_query_id'=>$callbackId];
        if ($text !== '') $params['text'] = text_substr($text, 0, 180);
        bale_product_bot_api('answerCallbackQuery', $params);
    } catch (Throwable $error) {
        log_error($error);
    }
}

/** @param array<int,array<int,array{text:string,callback_data:string}>> $rows @return array<string,mixed> */
function bale_product_bot_inline_keyboard(array $rows): array
{
    $keyboard = [];
    $hasCancel = false;
    $seen = [];
    foreach ($rows as $row) {
        $buttons = [];
        foreach ($row as $button) {
            $text = trim((string)($button['text'] ?? ''));
            $data = trim((string)($button['callback_data'] ?? ''));
            if ($text !== '' && $data !== '') {
                if (!preg_match('/^[A-Za-z0-9:_-]{1,64}$/D',$data)) throw new RuntimeException('شناسه یکی از دکمه‌های ربات نامعتبر یا بیش از ۶۴ بایت است.');
                if (isset($seen[$data])) throw new RuntimeException('دو دکمه با شناسه یکسان در یک صفحه ساخته شده‌اند.');
                $seen[$data] = true;
                if ($data === 'cancel' || str_starts_with($data,'cancel:') || $data === 'nav:home') $hasCancel = true;
                $buttons[] = ['text'=>text_substr($text,0,60),'callback_data'=>$data];
            }
        }
        if ($buttons) $keyboard[] = $buttons;
    }
    if (!$hasCancel) {
        $cancelData=bale_product_bot_cancel_callback();
        if (isset($seen[$cancelData])) throw new RuntimeException('دکمه پایان این صفحه شناسه تکراری دارد.');
        $keyboard[] = [[
            'text'=>str_starts_with($cancelData,'cancel:')?'❌ لغو عملیات':'🏠 داشبورد',
            'callback_data'=>$cancelData,
        ]];
    }
    return ['inline_keyboard'=>$keyboard];
}

function bale_product_bot_main_keyboard(?string $userId = null): array
{
    $userId = trim((string)($userId ?? bale_product_bot_runtime_user()));
    if (function_exists('bale_dashboard_main_keyboard')) return bale_dashboard_main_keyboard($userId);
    $full = $userId === '';
    $can = static fn(string $cap): bool => $full || bale_product_bot_can($userId,$cap);
    $rows = [];
    if ($can('products')) {
        $rows[]=['➕ افزودن سریع محصول','✏️ ویرایش محصول'];
        $rows[]=['🗑 حذف محصول','🖼 مدیریت تصاویر'];
        $rows[]=['🪄 اصلاح عکس‌های قبلی'];
        $rows[]=['📂 مدیریت دسته‌ها','🏷 مدیریت برندها'];
        $rows[]=['⚡ قیمت/موجودی سریع','🔗 لینک محصولات'];
        $rows[]=['📊 تغییر گروهی قیمت'];
        $rows[]=['⏳ تخفیف محدود'];
    }
    if ($can('reports')) $rows[]=['⚠️ موجودی کم','📊 گزارش فروش امروز'];
    if ($can('payments')) $rows[]=['💳 درصد ترب‌پی'];
    if ($can('returns') || $can('tickets')) {
        $row=[];if($can('returns'))$row[]='↩️ مرجوعی‌ها';if($can('tickets'))$row[]='🎫 تیکت‌ها';if($row)$rows[]=$row;
    }
    if ($can('orders') && bale_product_bot_order_management_enabled()) $rows[] = ['🧾 مدیریت سفارش‌ها'];
    $rows[] = ['🧹 لغو عملیات','🆔 شناسه من'];
    return bale_product_bot_keyboard($rows);
}

function bale_product_bot_cancel_keyboard(): array
{
    return bale_product_bot_keyboard([['❌ لغو عملیات']]);
}

function bale_product_bot_images_keyboard(): array
{
    return bale_product_bot_keyboard([['✅ پایان تصاویر'],['🗑 حذف آخرین تصویر','❌ لغو عملیات']]);
}

/** @return array{chat_id:string,user_id:string,step:string,data:array<string,mixed>}|null */
function bale_product_bot_session(string $chatId): ?array
{
    $stmt = db()->prepare('SELECT chat_id,user_id,step,payload_json FROM bale_product_bot_sessions WHERE chat_id=? AND expires_at>NOW() LIMIT 1');
    $stmt->execute([$chatId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $data = json_decode((string)$row['payload_json'], true);
    return ['chat_id'=>(string)$row['chat_id'],'user_id'=>(string)$row['user_id'],'step'=>(string)$row['step'],'data'=>is_array($data)?$data:[]];
}

/** @param array<string,mixed> $data */
function bale_product_bot_session_save(string $chatId, string $userId, string $step, array $data): void
{
    $token=trim((string)($data['_flow_token']??''));
    if (!preg_match('/^[a-f0-9]{16}$/D',$token)) {
        $current=bale_product_bot_session($chatId);
        $currentFamily=$current ? bale_product_bot_session_family((string)($current['step']??'')) : 'unknown';
        $nextFamily=bale_product_bot_session_family($step);
        $sameFlow=$current
            && (string)($current['user_id']??'')===$userId
            && $currentFamily !== 'unknown'
            && $currentFamily === $nextFamily;
        $currentToken=$sameFlow ? trim((string)($current['data']['_flow_token']??'')) : '';
        $token=preg_match('/^[a-f0-9]{16}$/D',$currentToken) ? $currentToken : bin2hex(random_bytes(8));
    }
    $data['_flow_token']=$token;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    $minutes=max(15,min(120,(int)setting('bale_product_bot_session_minutes','60')));
    $stmt = db()->prepare("INSERT INTO bale_product_bot_sessions(chat_id,user_id,step,payload_json,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL {$minutes} MINUTE)) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),step=VALUES(step),payload_json=VALUES(payload_json),expires_at=VALUES(expires_at),updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$chatId,$userId,text_substr($step,0,60),$json]);
}

function bale_product_bot_session_delete(string $chatId): void
{
    db()->prepare('DELETE FROM bale_product_bot_sessions WHERE chat_id=?')->execute([$chatId]);
}

function bale_product_bot_cancel_callback(): string
{
    $chatId=bale_product_bot_runtime_chat();
    $userId=bale_product_bot_runtime_user();
    if ($chatId === '' || $userId === '') return 'nav:home';
    try {
        $session=bale_product_bot_session($chatId);
        $token=$session && (string)($session['user_id']??'')===$userId
            ? trim((string)($session['data']['_flow_token']??'')) : '';
        return preg_match('/^[a-f0-9]{16}$/D',$token) ? 'cancel:'.$token : 'nav:home';
    } catch (Throwable $error) {
        log_error($error);
        return 'nav:home';
    }
}

/** @return array{allowed:bool,message:string} */
function bale_product_bot_cancel_outcome(?array $session): array
{
    if (!$session) {
        return ['allowed'=>true,'message'=>'عملیات فعالی وجود نداشت؛ منوی مدیریت نمایش داده شد.'];
    }
    if ((string)($session['step']??'') === 'processing') {
        return ['allowed'=>false,'message'=>'ثبت نهایی محصول آغاز شده و در این مرحله قابل لغو نیست؛ تا نمایش نتیجه صبر کنید.'];
    }
    return [
        'allowed'=>true,
        'message'=>'عملیات بسته شد و اطلاعات موقت پاک شد. تغییراتی که پیش از لغو ذخیره شده‌اند باقی می‌مانند.',
    ];
}

function bale_product_bot_prepare_menu_switch(string $chatId, string $userId): bool
{
    $session=bale_product_bot_session($chatId);
    if (!$session || (string)($session['user_id']??'')!==$userId) return true;
    if ((string)($session['step']??'') === 'processing') {
        bale_product_bot_send($chatId,'⏳ ثبت نهایی محصول در حال انجام است. تا نمایش نتیجه، دکمه بخش دیگری را نزنید.');
        return false;
    }
    bale_product_bot_session_delete($chatId);
    return true;
}

function bale_product_bot_claim_update(int $updateId): bool
{
    if ($updateId < 1) return true;
    try {
        $stmt = db()->prepare('INSERT IGNORE INTO bale_product_bot_updates(update_id,created_at) VALUES(?,NOW())');
        $stmt->execute([$updateId]);
        if (random_int(1,100) === 1) db()->exec('DELETE FROM bale_product_bot_updates WHERE created_at<DATE_SUB(NOW(),INTERVAL 7 DAY)');
        return $stmt->rowCount() > 0;
    } catch (Throwable $error) {
        log_error($error);
        return true;
    }
}

function bale_product_bot_pair(string $userId, string $code): bool
{
    $code = normalize_digits(trim($code));
    if (!preg_match('/^\d{8}$/', $code)) return false;
    $stmt = db()->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('bale_product_bot_pair_hash','bale_product_bot_pair_expires_at')");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    $hash = trim($settings['bale_product_bot_pair_hash'] ?? '');
    $expires = trim($settings['bale_product_bot_pair_expires_at'] ?? '');
    if ($hash === '' || $expires === '' || strtotime($expires) < time()) return false;
    $expected = hash_hmac('sha256', $code, application_secret());
    if (!hash_equals($hash, $expected)) return false;
    $ids = bale_product_bot_allowed_ids();
    if (!in_array($userId, $ids, true)) $ids[] = $userId;
    $save = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $save->execute(['bale_product_bot_allowed_ids', implode(',', $ids)]);
    $permissionMap=bale_product_bot_permission_map();$permissionMap[$userId]=bale_product_bot_capabilities();
    $save->execute(['bale_product_bot_permissions_json',json_encode($permissionMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $save->execute(['bale_product_bot_pair_hash','']);
    $save->execute(['bale_product_bot_pair_expires_at','']);
    admin_audit_event('bale_product_bot_paired', 'bale_user', null, ['username'=>'bale:'.$userId,'bale_user_id'=>$userId]);
    return true;
}

/** @return array<int,array{id:int,name:string}> */
function bale_product_bot_choices(string $type): array
{
    if ($type === 'category') {
        $rows = db()->query("SELECT id,name FROM categories WHERE is_active=1 AND slug<>'festival' ORDER BY sort_order,name,id LIMIT 80")->fetchAll();
    } else {
        $rows = db()->query('SELECT id,name FROM brands WHERE is_active=1 ORDER BY sort_order,name,id LIMIT 80')->fetchAll();
    }
    return array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>(string)$row['name']], $rows);
}

/** @param array<int,array{id:int,name:string}> $rows */
function bale_product_bot_send_choices(string $chatId, string $title, array $rows, int $defaultId = 0): void
{
    if (!$rows) { bale_product_bot_send($chatId, 'هیچ گزینه فعالی پیدا نشد. عملیات را لغو کنید و از منوی ربات دسته یا برند جدید بسازید.'); return; }
    $chunks = array_chunk($rows, 28);
    foreach ($chunks as $index => $chunk) {
        $lines = $index === 0 ? [$title] : ['ادامه فهرست:'];
        foreach ($chunk as $row) $lines[] = $row['id'] . ' — ' . $row['name'];
        if ($index === count($chunks)-1) {
            $lines[] = '';
            $lines[] = $defaultId > 0 ? 'شناسه را بفرستید؛ برای انتخاب پیش‌فرض «پیش‌فرض» بنویسید.' : 'شناسه گزینه موردنظر را بفرستید.';
        }
        bale_product_bot_send($chatId, implode("\n", $lines), $index === count($chunks)-1 ? bale_product_bot_cancel_keyboard() : null);
    }
}

/** @param array<int,array{id:int,name:string}> $rows */
function bale_product_bot_resolve_choice(string $text, array $rows, int $defaultId = 0): int
{
    $text = trim(normalize_digits($text));
    if ($defaultId > 0 && in_array($text, ['پیش‌فرض','پیش فرض','default'], true)) {
        foreach ($rows as $row) if ($row['id'] === $defaultId) return $defaultId;
        return 0;
    }
    if (ctype_digit($text)) {
        $id = (int)$text;
        foreach ($rows as $row) if ($row['id'] === $id) return $id;
    }
    foreach ($rows as $row) if (trim($row['name']) === trim($text)) return $row['id'];
    return 0;
}


/**
 * @return array{rows:array<int,array<string,mixed>>,page:int,pages:int,total:int}
 */
function bale_product_bot_inline_list_page(string $mode, int $page = 1): array
{
    $page = max(1, $page);
    $perPage = in_array($mode,['dp','ep','pl','o'],true) ? 8 : 10;
    $pdo = db();

    if (in_array($mode,['c','ec'],true)) {
        $where = "is_active=1 AND slug<>'festival'";
        $total = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE {$where}")->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT id,name FROM categories WHERE {$where} ORDER BY sort_order,name,id LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute();
    } elseif (in_array($mode,['b','eb'],true)) {
        $where = 'is_active=1';
        $total = (int)$pdo->query("SELECT COUNT(*) FROM brands WHERE {$where}")->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT id,name FROM brands WHERE {$where} ORDER BY sort_order,name,id LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute();
    } elseif ($mode === 'dc') {
        $where = "c.slug<>'festival'";
        $total = (int)$pdo->query("SELECT COUNT(*) FROM categories c WHERE {$where}")->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT c.id,c.name,c.slug,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) AS product_count FROM categories c WHERE {$where} ORDER BY c.sort_order,c.name,c.id LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute();
    } elseif (in_array($mode,['dp','ep'],true)) {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT id,title,sku,status FROM products ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute();
    } elseif ($mode === 'pl') {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT p.id,p.title,p.sku,p.slug,p.status,p.price,c.is_active category_active,b.is_active brand_active FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id ORDER BY p.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute();
    } elseif ($mode === 'o') {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT id,order_number,customer_name,total,order_status,payment_status,priority,created_at FROM orders ORDER BY FIELD(priority,'urgent','high','normal'),id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute();
    } else {
        return ['rows'=>[],'page'=>1,'pages'=>1,'total'=>0];
    }

    return ['rows'=>$stmt->fetchAll() ?: [], 'page'=>$page, 'pages'=>$pages, 'total'=>$total];
}

function bale_product_bot_inline_list_title(string $mode, int $page, int $pages, int $total): string
{
    $title = match ($mode) {
        'c' => 'دسته‌بندی محصول را انتخاب کنید:',
        'b' => 'برند محصول را انتخاب کنید:',
        'dc' => 'دسته‌ای را که می‌خواهید حذف شود انتخاب کنید:',
        'dp' => 'محصولی را که می‌خواهید حذف شود انتخاب کنید:',
        'ep' => 'محصولی را که می‌خواهید ویرایش کنید انتخاب کنید:',
        'pl' => '🔗 محصولی را برای دریافت لینک صفحه‌اش انتخاب کنید:',
        'ec' => 'دسته‌بندی جدید محصول را انتخاب کنید:',
        'eb' => 'برند جدید محصول را انتخاب کنید:',
        'o' => 'سفارش موردنظر را انتخاب کنید:',
        default => 'یک گزینه را انتخاب کنید:',
    };
    $hint = match ($mode) {
        'dc' => "\nاگر دسته محصول داشته باشد، محصولات پیش از حذف به دسته «بدون دسته‌بندی» منتقل می‌شوند.",
        'dp' => "\nبعد از انتخاب، تأیید دومرحله‌ای نمایش داده می‌شود.",
        'ep' => "\nپس از انتخاب، فهرست بخش‌های قابل ویرایش نمایش داده می‌شود.",
        'pl' => "\nبا انتخاب هر محصول، لینک مستقیم صفحه همان محصول برایتان ارسال می‌شود و می‌توانید محصول بعدی را هم انتخاب کنید.",
        'o' => "\nوضعیت، اطلاعات مشتری و ارسال سفارش از داخل ربات قابل مدیریت است.",
        default => '',
    };
    return $title . $hint . "\n\nصفحه " . fa_number($page) . ' از ' . fa_number($pages) . ' — مجموع ' . fa_number($total) . ' مورد';
}

/** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
function bale_product_bot_inline_list_markup(string $mode, array $rows, int $page, int $pages): array
{
    $buttons = [];
    $defaultId = in_array($mode,['c','ec'],true)
        ? (int)setting('bale_product_bot_default_category_id','0')
        : (in_array($mode,['b','eb'],true) ? (int)setting('bale_product_bot_default_brand_id','0') : 0);

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id < 1) continue;
        if ($mode === 'ep') {
            $title = trim((string)($row['title'] ?? 'محصول'));
            $sku = trim((string)($row['sku'] ?? ''));
            $label = '✏️ ' . text_substr($title, 0, 34) . ($sku !== '' ? ' | ' . text_substr($sku,0,16) : '');
            $data = 'pick:ep:' . $id;
        } elseif ($mode === 'pl') {
            $title = trim((string)($row['title'] ?? 'محصول'));
            $sku = trim((string)($row['sku'] ?? ''));
            $isPublic = (string)($row['status'] ?? '') === 'active' && (int)($row['price'] ?? 0) > 0 && !empty($row['category_active']) && !empty($row['brand_active']);
            $label = ($isPublic ? '🔗 ' : '⚠️ ') . text_substr($title, 0, 34) . ($sku !== '' ? ' | ' . text_substr($sku,0,16) : '');
            $data = 'pick:pl:' . $id;
        } elseif ($mode === 'dp') {
            $title = trim((string)($row['title'] ?? 'محصول'));
            $sku = trim((string)($row['sku'] ?? ''));
            $label = '🗑 ' . text_substr($title, 0, 34) . ($sku !== '' ? ' | ' . text_substr($sku,0,16) : '');
            $data = 'pick:dp:' . $id;
        } elseif ($mode === 'dc') {
            $count = max(0, (int)($row['product_count'] ?? 0));
            $label = '🗑 ' . text_substr((string)($row['name'] ?? ''),0,38);
            if ($count > 0) $label .= ' (' . fa_number($count) . ' محصول)';
            $data = 'pick:dc:' . $id;
        } elseif ($mode === 'o') {
            $paymentIcon = (string)($row['payment_status']??'') === 'paid' ? '✅' : '⏳';
            $priorityIcon = (string)($row['priority']??'normal') === 'urgent' ? '🚨 ' : ((string)($row['priority']??'normal') === 'high' ? '⚠️ ' : '');
            $label = $priorityIcon.$paymentIcon.' '.text_substr((string)($row['order_number']??''),0,18).' · '.text_substr((string)($row['customer_name']??''),0,24).' · '.order_status_label((string)($row['order_status']??''));
            $data = 'pick:o:' . $id;
        } else {
            $label = ($id === $defaultId ? '⭐ ' : '') . text_substr((string)($row['name'] ?? ''),0,46);
            $data = 'pick:' . $mode . ':' . $id;
        }
        $buttons[] = [[ 'text'=>$label, 'callback_data'=>$data ]];
    }

    $nav = [];
    if ($page > 1) $nav[] = ['text'=>'⬅️ قبلی','callback_data'=>'page:'.$mode.':'.($page-1)];
    $nav[] = ['text'=>fa_number($page).' / '.fa_number($pages),'callback_data'=>'page:noop:0'];
    if ($page < $pages) $nav[] = ['text'=>'بعدی ➡️','callback_data'=>'page:'.$mode.':'.($page+1)];
    if ($nav) $buttons[] = $nav;
    $buttons[] = [[ 'text'=>'❌ لغو عملیات', 'callback_data'=>bale_product_bot_cancel_callback() ]];
    return bale_product_bot_inline_keyboard($buttons);
}

function bale_product_bot_send_inline_list(string $chatId, string $mode, int $page = 1): void
{
    $list = bale_product_bot_inline_list_page($mode, $page);
    if (!$list['rows']) {
        $message = in_array($mode,['c','ec','dc'],true)
            ? 'هیچ دسته‌بندی قابل نمایشی پیدا نشد.'
            : (in_array($mode,['b','eb'],true) ? 'هیچ برند فعالی پیدا نشد.' : ($mode==='o' ? 'هنوز سفارشی ثبت نشده است.' : 'هیچ محصولی برای نمایش وجود ندارد.'));
        bale_product_bot_send($chatId, $message, bale_product_bot_main_keyboard());
        return;
    }
    bale_product_bot_send(
        $chatId,
        bale_product_bot_inline_list_title($mode,$list['page'],$list['pages'],$list['total']),
        bale_product_bot_inline_list_markup($mode,$list['rows'],$list['page'],$list['pages'])
    );
}

function bale_product_bot_edit_inline_list(string $chatId, int $messageId, string $mode, int $page = 1): void
{
    $list = bale_product_bot_inline_list_page($mode, $page);
    if (!$list['rows']) {
        bale_product_bot_edit($chatId,$messageId,'موردی برای نمایش وجود ندارد.');
        return;
    }
    bale_product_bot_edit(
        $chatId,
        $messageId,
        bale_product_bot_inline_list_title($mode,$list['page'],$list['pages'],$list['total']),
        bale_product_bot_inline_list_markup($mode,$list['rows'],$list['page'],$list['pages'])
    );
}


function bale_product_bot_unique_slug(string $table, string $name): string
{
    if (!in_array($table, ['categories','brands'], true)) throw new RuntimeException('جدول مقصد معتبر نیست.');
    $base = text_substr(slugify($name), 0, 125);
    if ($base === '') $base = 'item-' . date('Ymd-His');
    $check = db()->prepare("SELECT 1 FROM `{$table}` WHERE slug=? LIMIT 1");
    $candidate = $base;
    for ($suffix = 2; $suffix <= 999; $suffix++) {
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) return $candidate;
        $candidate = text_substr($base, 0, 125 - strlen((string)$suffix)) . '-' . $suffix;
    }
    return text_substr($base,0,115) . '-' . bin2hex(random_bytes(4));
}

/** @return array{id:int,name:string,slug:string,created:bool} */
function bale_product_bot_create_taxonomy(string $type, string $name, string $userId): array
{
    $name = trim($name);
    if (text_length($name) < 2 || text_length($name) > 120) throw new RuntimeException('نام باید بین ۲ تا ۱۲۰ کاراکتر باشد.');
    $table = $type === 'category' ? 'categories' : ($type === 'brand' ? 'brands' : '');
    if ($table === '') throw new RuntimeException('نوع اطلاعات معتبر نیست.');
    $existing = db()->prepare("SELECT id,name,slug FROM `{$table}` WHERE name=? LIMIT 1");
    $existing->execute([$name]);
    $row = $existing->fetch();
    if ($row) return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],'created'=>false];
    $slug = bale_product_bot_unique_slug($table, $name);
    if ($type === 'category') {
        db()->prepare('INSERT INTO categories(name,slug,image_path,meta_title,meta_description,sort_order,is_active) VALUES(?,?,NULL,NULL,NULL,0,1)')->execute([$name,$slug]);
        $action = 'bale_category_created';
        $entity = 'category';
    } else {
        db()->prepare('INSERT INTO brands(name,slug,sort_order,is_active) VALUES(?,?,0,1)')->execute([$name,$slug]);
        $action = 'bale_brand_created';
        $entity = 'brand';
    }
    $id = (int)db()->lastInsertId();
    admin_audit_event($action, $entity, $id, ['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'name'=>$name,'slug'=>$slug]);
    return ['id'=>$id,'name'=>$name,'slug'=>$slug,'created'=>true];
}

/** @return array{id:int,name:string,slug:string,product_count:int,image_path:string}|null */
function bale_product_bot_resolve_category(string $text): ?array
{
    $text = trim(normalize_digits($text));
    if ($text === '') return null;
    if (ctype_digit($text)) {
        $stmt = db()->prepare('SELECT c.id,c.name,c.slug,c.image_path,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) product_count FROM categories c WHERE c.id=? LIMIT 1');
        $stmt->execute([(int)$text]);
    } else {
        $stmt = db()->prepare('SELECT c.id,c.name,c.slug,c.image_path,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) product_count FROM categories c WHERE c.name=? OR c.slug=? ORDER BY c.id LIMIT 1');
        $stmt->execute([$text,$text]);
    }
    $row = $stmt->fetch();
    return $row ? ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],'product_count'=>(int)$row['product_count'],'image_path'=>(string)($row['image_path']??'')] : null;
}

/** @return array<int,array{id:int,title:string,sku:string,status:string}> */
function bale_product_bot_find_products(string $text): array
{
    $text = trim(normalize_digits($text));
    if ($text === '') return [];
    if (ctype_digit($text)) {
        $stmt = db()->prepare('SELECT id,title,sku,status FROM products WHERE id=? LIMIT 1');
        $stmt->execute([(int)$text]);
        $row = $stmt->fetch();
        return $row ? [['id'=>(int)$row['id'],'title'=>(string)$row['title'],'sku'=>(string)$row['sku'],'status'=>(string)$row['status']]] : [];
    }
    $exact = db()->prepare('SELECT id,title,sku,status FROM products WHERE sku=? OR title=? ORDER BY id DESC LIMIT 10');
    $exact->execute([$text,$text]);
    $rows = $exact->fetchAll();
    if (!$rows) {
        $like = '%' . text_substr($text,0,100) . '%';
        $stmt = db()->prepare('SELECT id,title,sku,status FROM products WHERE title LIKE ? OR sku LIKE ? ORDER BY id DESC LIMIT 10');
        $stmt->execute([$like,$like]);
        $rows = $stmt->fetchAll();
    }
    return array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'title'=>(string)$row['title'],'sku'=>(string)$row['sku'],'status'=>(string)$row['status']], $rows);
}

/** @return array{ok:bool,message:string,product_id:int,title:string} */
function bale_product_bot_delete_product(int $productId, string $userId): array
{
    if ($productId < 1) return ['ok'=>false,'message'=>'محصول معتبر نیست.','product_id'=>0,'title'=>''];
    $pdo = db();
    $images = [];
    $title = '';
    try {
        $pdo->beginTransaction();
        $product = $pdo->prepare('SELECT id,title,image_path FROM products WHERE id=? FOR UPDATE');
        $product->execute([$productId]);
        $row = $product->fetch();
        if (!$row) throw new RuntimeException('محصول پیدا نشد یا قبلاً حذف شده است.');
        $title = (string)$row['title'];
        if (torobpay_special_live_campaign_for_product($productId, $pdo)) throw new RuntimeException('این محصول تخفیف محدود فعال یا زمان‌بندی‌شده دارد؛ ابتدا تخفیف را لغو کنید.');
        if (!empty($row['image_path'])) $images[] = (string)$row['image_path'];
        foreach (['order_items','purchase_order_items','inventory_movements','damaged_inventory'] as $table) {
            $check = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE product_id=? LIMIT 1");
            $check->execute([$productId]);
            if ($check->fetchColumn()) throw new RuntimeException('این محصول سابقه سفارش، خرید یا گردش انبار دارد و برای حفظ سوابق مالی قابل حذف نیست. آن را از پنل غیرفعال کنید.');
        }
        try {
            $gallery = $pdo->prepare('SELECT image_path FROM product_gallery WHERE product_id=?');
            $gallery->execute([$productId]);
            foreach ($gallery->fetchAll(PDO::FETCH_COLUMN) as $path) if (is_string($path) && $path !== '') $images[] = $path;
        } catch (Throwable $optionalError) { log_error($optionalError); }
        $delete = $pdo->prepare('DELETE FROM products WHERE id=?');
        $delete->execute([$productId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('حذف محصول انجام نشد.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'message'=>$error->getMessage(),'product_id'=>$productId,'title'=>$title];
    }
    foreach (array_unique($images) as $path) delete_managed_upload((string)$path);
    admin_audit_event('bale_product_deleted','product',$productId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'title'=>$title]);
    return ['ok'=>true,'message'=>'محصول حذف شد.','product_id'=>$productId,'title'=>$title];
}

/** @return array{ok:bool,message:string,category_id:int,name:string} */
function bale_product_bot_delete_category(int $categoryId, string $userId): array
{
    $result = taxonomy_delete_category($categoryId, [
        'audit_event'=>'bale_category_deleted',
        'username'=>'bale:'.$userId,
        'bale_user_id'=>$userId,
    ]);
    return [
        'ok'=>(bool)$result['ok'],
        'message'=>(string)$result['message'],
        'category_id'=>(int)$result['category_id'],
        'name'=>(string)$result['name'],
    ];
}

/** @param array<int,string> $parts */
function bale_product_bot_join_words(array $parts): string
{
    $parts = array_values(array_filter(array_map('trim',$parts), static fn(string $item): bool => $item !== ''));
    return implode(' و ', $parts);
}

function bale_product_bot_under_thousand_words(int $number): string
{
    if ($number === 0) return '';
    $ones = ['', 'یک','دو','سه','چهار','پنج','شش','هفت','هشت','نه'];
    $teens = [10=>'ده',11=>'یازده',12=>'دوازده',13=>'سیزده',14=>'چهارده',15=>'پانزده',16=>'شانزده',17=>'هفده',18=>'هجده',19=>'نوزده'];
    $tens = [2=>'بیست',3=>'سی',4=>'چهل',5=>'پنجاه',6=>'شصت',7=>'هفتاد',8=>'هشتاد',9=>'نود'];
    $hundreds = [1=>'صد',2=>'دویست',3=>'سیصد',4=>'چهارصد',5=>'پانصد',6=>'ششصد',7=>'هفتصد',8=>'هشتصد',9=>'نهصد'];
    $parts = [];
    $h = intdiv($number,100);
    if ($h > 0) $parts[] = $hundreds[$h];
    $rest = $number % 100;
    if ($rest >= 10 && $rest <= 19) $parts[] = $teens[$rest];
    else {
        $t = intdiv($rest,10);
        $o = $rest % 10;
        if ($t > 0) $parts[] = $tens[$t];
        if ($o > 0) $parts[] = $ones[$o];
    }
    return bale_product_bot_join_words($parts);
}

function bale_product_bot_money_words(int $number): string
{
    if ($number === 0) return 'صفر تومان';
    if ($number < 0) return 'منفی ' . bale_product_bot_money_words(abs($number));
    $scales = [
        1000000000000=>'تریلیون',
        1000000000=>'میلیارد',
        1000000=>'میلیون',
        1000=>'هزار',
    ];
    $parts = [];
    foreach ($scales as $scale=>$label) {
        if ($number >= $scale) {
            $chunk = intdiv($number,$scale);
            $parts[] = bale_product_bot_under_thousand_words($chunk) . ' ' . $label;
            $number %= $scale;
        }
    }
    if ($number > 0) $parts[] = bale_product_bot_under_thousand_words($number);
    return bale_product_bot_join_words($parts) . ' تومان';
}

/** @return array{file_id:string,file_size:int}|null */
function bale_product_bot_extract_image(array $message): ?array
{
    $photos = $message['photo'] ?? [];
    if (is_array($photos) && $photos) {
        $last = end($photos);
        if (is_array($last) && trim(scalar_string($last['file_id'] ?? '')) !== '') return ['file_id'=>trim(scalar_string($last['file_id'])),'file_size'=>max(0,(int)($last['file_size'] ?? 0))];
    }
    $document = $message['document'] ?? null;
    if (is_array($document) && str_starts_with(strtolower(trim(scalar_string($document['mime_type'] ?? ''))), 'image/')) {
        $fileId = trim(scalar_string($document['file_id'] ?? ''));
        if ($fileId !== '') return ['file_id'=>$fileId,'file_size'=>max(0,(int)($document['file_size'] ?? 0))];
    }
    return null;
}

function bale_product_bot_download_file(string $fileId): string
{
    $info = bale_product_bot_api('getFile', ['file_id'=>$fileId]);
    $result = $info['result'] ?? null;
    $path = is_array($result) ? trim(scalar_string($result['file_path'] ?? '')) : (is_string($result) ? trim($result) : '');
    if ($path === '' || str_contains($path, '..')) throw new RuntimeException('مسیر فایل دریافتی از بله معتبر نیست.');
    $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    $url = 'https://tapi.bale.ai/file/bot' . bale_product_bot_token() . '/' . $encoded;
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('دانلود تصویر از بله شروع نشد.');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>35,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $bytes = curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
    if (!is_string($bytes) || $http < 200 || $http >= 300 || $error !== '') throw new RuntimeException('دانلود تصویر از بله انجام نشد.');
    if (strlen($bytes) < 1 || strlen($bytes) > 6*1024*1024) throw new RuntimeException('حجم یکی از تصاویر خارج از محدوده ۶ مگابایت است.');
    return $bytes;
}

function bale_product_bot_save_image_bytes(string $bytes): string
{
    $tmpDir = BASE_PATH . '/storage/tmp';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true)) throw new RuntimeException('پوشه موقت قابل ساخت نیست.');
    $tmp = $tmpDir . '/bale-' . bin2hex(random_bytes(12));
    if (file_put_contents($tmp, $bytes, LOCK_EX) === false) throw new RuntimeException('ذخیره موقت تصویر انجام نشد.');
    try {
        $size = (int)filesize($tmp);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($allowed[$mime])) throw new RuntimeException('فرمت تصویر دریافتی از بله مجاز نیست.');
        $dimensions = @getimagesize($tmp);
        if (!is_array($dimensions) || ($dimensions[0]??0)<1 || ($dimensions[1]??0)<1) throw new RuntimeException('تصویر دریافتی معتبر نیست.');
        if (min((int)$dimensions[0],(int)$dimensions[1])<PRODUCT_IMAGE_MIN_SOURCE_SIDE) throw new RuntimeException('برای حفظ کیفیت، ضلع کوتاه تصویر محصول باید حداقل ۳۰۰ پیکسل باشد.');
        if ((int)$dimensions[0]>8000 || (int)$dimensions[1]>8000 || ((int)$dimensions[0]*(int)$dimensions[1])>30000000) throw new RuntimeException('ابعاد تصویر بیش از حد بزرگ است.');
        $dir = BASE_PATH . '/uploads/products';
        if (!is_dir($dir) && !mkdir($dir,0755,true)) throw new RuntimeException('پوشه تصاویر محصول قابل ساخت نیست.');
        $base = date('Ymd') . '-' . bin2hex(random_bytes(8));
        $decoder = match($mime){'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp',default=>''};
        if ($decoder==='' || !function_exists($decoder) || !function_exists('imagewebp')) throw new RuntimeException('برای آماده‌سازی خودکار تصویر محصول، افزونه GD با پشتیبانی WebP باید فعال باشد.');
        $source=@$decoder($tmp);
        if($source===false)throw new RuntimeException('خواندن تصویر محصول انجام نشد.');
        $width=(int)$dimensions[0];$height=(int)$dimensions[1];
        if ($mime==='image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
            $exif=@exif_read_data($tmp);$orientation=(int)($exif['Orientation']??1);
            if (in_array($orientation,[3,6,8],true)) {$rotated=@imagerotate($source,$orientation===3?180:($orientation===6?-90:90),0);if($rotated!==false){imagedestroy($source);$source=$rotated;$width=imagesx($source);$height=imagesy($source);}}
        }
        $path=write_torob_ready_product_image($source,$width,$height,$dir,$base);
        imagedestroy($source);
        if($path===null)throw new RuntimeException('آماده‌سازی خودکار تصویر محصول انجام نشد؛ افزونه GD و WebP هاست را بررسی کنید.');
        return $path;
    } finally { @unlink($tmp); }
}




/** @return array<string,mixed>|null */
function bale_product_bot_order(int $orderId): ?array
{
    if ($orderId < 1) return null;
    $stmt = db()->prepare("SELECT o.*,s.id shipment_id,s.status shipment_status,s.tracking_code shipment_tracking_code,s.carrier_id,c.name carrier_name FROM orders o LEFT JOIN shipments s ON s.order_id=o.id LEFT JOIN shipping_carriers c ON c.id=s.carrier_id WHERE o.id=? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return null;
    $items = db()->prepare('SELECT title,variant_title,variant_options_json,sku,quantity,line_total FROM order_items WHERE order_id=? ORDER BY id');
    $items->execute([$orderId]);
    $order['items'] = $items->fetchAll() ?: [];
    $tx = db()->prepare('SELECT gateway,status,authority,reference_id,card_pan,verified_at,created_at FROM payment_transactions WHERE order_id=? ORDER BY id DESC LIMIT 1');
    $tx->execute([$orderId]);
    $order['transaction'] = $tx->fetch() ?: null;
    return $order;
}

function bale_product_bot_clean_line(string $value, int $max = 180): string
{
    $value = preg_replace('/\s+/u',' ',trim($value)) ?? trim($value);
    return text_substr($value,0,$max);
}

/** @param array<string,mixed> $order */
function bale_product_bot_order_text(array $order, string $headline = ''): string
{
    $lines = [];
    if ($headline !== '') { $lines[] = $headline; $lines[] = ''; }
    $lines[] = '🧾 سفارش ' . bale_product_bot_clean_line((string)($order['order_number']??''),40);
    $lines[] = '👤 مشتری: ' . bale_product_bot_clean_line((string)($order['customer_name']??''));
    $lines[] = '📱 موبایل: ' . bale_product_bot_clean_line((string)($order['phone']??''),30);
    $location = bale_product_bot_clean_line(trim((string)($order['province']??'').'، '.(string)($order['city']??'')),120);
    if ($location !== '،' && $location !== '') $lines[] = '📍 مقصد: ' . $location;
    $lines[] = '💰 مبلغ: ' . fa_number(format_price((int)($order['total']??0))) . ' تومان';
    $lines[] = '💳 پرداخت: ' . payment_method_label((string)($order['payment_method']??'')) . ' — ' . order_payment_status_label($order);
    $lines[] = '📦 وضعیت سفارش: ' . order_status_label((string)($order['order_status']??''));
    $lines[] = '⚑ اولویت: ' . order_priority_label((string)($order['priority']??'normal'));
    $lines[] = '🕒 ثبت: ' . jalali_format((string)($order['created_at']??''));
    $tracking = trim((string)($order['shipment_tracking_code']??$order['tracking_code']??''));
    if ($tracking !== '') $lines[] = '🚚 رهگیری: ' . bale_product_bot_clean_line($tracking,120) . ((string)($order['carrier_name']??'')!=='' ? ' — '.bale_product_bot_clean_line((string)$order['carrier_name'],80) : '');
    $items = is_array($order['items']??null) ? $order['items'] : [];
    if ($items) {
        $lines[] = '';
        $lines[] = '🛒 اقلام سفارش:';
        foreach (array_slice($items,0,10) as $item) {
            $title = bale_product_bot_clean_line((string)($item['title']??''),80);
            $variant = bale_product_bot_clean_line(order_item_option_label($item),260);
            if ($variant !== '') $title .= ' (' . $variant . ')';
            $lines[] = '• ' . $title . ' × ' . fa_number((int)($item['quantity']??0)) . ' — ' . fa_number(format_price((int)($item['line_total']??0))) . ' تومان';
        }
        if (count($items)>10) $lines[] = '… و '.fa_number(count($items)-10).' قلم دیگر';
    }
    $note = bale_product_bot_clean_line((string)($order['note']??''),300);
    if ($note !== '') { $lines[]=''; $lines[]='📝 توضیح مشتری: '.$note; }
    return text_substr(implode("\n",$lines),0,3900);
}

function bale_product_bot_order_status_icon(string $status): string
{
    return match ($status) {
        'reviewing'=>'🔎','sourcing'=>'🛒','confirmed'=>'✅','preparing'=>'🧰','ready_to_ship'=>'📦',
        'handed_to_carrier'=>'🚚','shipped'=>'🚛','delivered'=>'🏠','cancelled'=>'❌',default=>'➡️',
    };
}

/** @param array<string,mixed> $order @return array<string,mixed> */
function bale_product_bot_order_markup(array $order): array
{
    $orderId = (int)($order['id']??0);
    $rows = [
        [
            ['text'=>'🔄 بروزرسانی','callback_data'=>'ord:view:'.$orderId],
            ['text'=>'📍 اطلاعات مشتری','callback_data'=>'ord:info:'.$orderId],
        ],
    ];
    if (bale_product_bot_order_management_enabled()) {
        $allowedTargets = ['reviewing','sourcing','confirmed','preparing','ready_to_ship','delivered','cancelled'];
        $buttons = [];
        foreach (order_next_statuses((string)($order['order_status']??'')) as $status=>$label) {
            if (!in_array($status,$allowedTargets,true)) continue;
            $buttons[] = ['text'=>bale_product_bot_order_status_icon($status).' '.$label,'callback_data'=>'ord:status:'.$orderId.':'.$status];
        }
        foreach (array_chunk($buttons,2) as $chunk) $rows[] = $chunk;
        if (in_array((string)($order['order_status']??''),['confirmed','preparing','ready_to_ship','handed_to_carrier','shipped'],true)) {
            $shipLabel=(string)($order['order_status']??'')==='shipped'?'✏️ اصلاح کد رهگیری':'🚚 ارسال و ثبت کد رهگیری';
            $rows[] = [[ 'text'=>$shipLabel,'callback_data'=>'ord:ship:'.$orderId ]];
        }
    }
    if (bale_product_bot_order_management_enabled()) $rows[] = [[ 'text'=>'⬅️ فهرست سفارش‌ها','callback_data'=>'ord:list:1' ]];
    return bale_product_bot_inline_keyboard($rows);
}

function bale_product_bot_show_order(string $chatId, int $orderId, int $messageId = 0, string $headline = ''): void
{
    $order = bale_product_bot_order($orderId);
    if (!$order) {
        if ($messageId>0) bale_product_bot_edit($chatId,$messageId,'سفارش پیدا نشد یا حذف شده است.');
        else bale_product_bot_send($chatId,'سفارش پیدا نشد یا حذف شده است.',bale_product_bot_main_keyboard());
        return;
    }
    $text = bale_product_bot_order_text($order,$headline);
    $markup = bale_product_bot_order_markup($order);
    $response = $messageId>0 ? bale_product_bot_edit($chatId,$messageId,$text,$markup) : bale_product_bot_send($chatId,$text,$markup);
    $runtimeUser=bale_product_bot_runtime_user();
    if ($runtimeUser!=='' && bale_product_bot_can($runtimeUser,'orders')) {
        $result=is_array($response['result']??null)?$response['result']:[];
        $currentMessageId=max($messageId,max(0,(int)($result['message_id']??0)));
        bale_product_bot_session_save($chatId,$runtimeUser,'order_view',['order_id'=>$orderId,'order_message_id'=>$currentMessageId]);
    }
}

function bale_product_bot_order_session_matches(?array $session, int $orderId, array|string $steps = 'order_view', int $messageId = 0): bool
{
    if (!$session || (int)($session['data']['order_id']??0) !== $orderId) return false;
    $sessionMessageId=max(0,(int)($session['data']['order_message_id']??0));
    if($messageId>0 && $sessionMessageId>0 && $messageId!==$sessionMessageId) return false;
    $allowed=is_array($steps)?$steps:[$steps];
    return in_array((string)($session['step']??''),$allowed,true);
}

/** @return array<string,mixed> */
function bale_product_bot_shipping_carriers_markup(int $orderId): array
{
    $rows = db()->query('SELECT id,name FROM shipping_carriers WHERE is_active=1 ORDER BY sort_order,name,id LIMIT 40')->fetchAll();
    $buttons = [];
    foreach ($rows as $row) $buttons[] = [[ 'text'=>'🚚 '.text_substr((string)$row['name'],0,48), 'callback_data'=>'ord:carrier:'.$orderId.':'.(int)$row['id'] ]];
    $buttons[] = [[ 'text'=>'⬅️ برگشت به سفارش','callback_data'=>'ord:view:'.$orderId ]];
    return bale_product_bot_inline_keyboard($buttons);
}

function bale_product_bot_notify_new_order(int $orderId): void
{
    if (!bale_product_bot_order_notifications_enabled()) return;
    $order = bale_product_bot_order($orderId);
    if (!$order) return;
    bale_product_bot_notify_allowed('new-order:'.$orderId,bale_product_bot_order_text($order,'🔔 سفارش جدید در گلریزان'),bale_product_bot_order_markup($order),'orders');
}

function bale_product_bot_notify_payment_success(int $orderId): void
{
    if (!bale_product_bot_payment_notifications_enabled()) return;
    $order = bale_product_bot_order($orderId);
    if (!$order) return;
    $text = bale_product_bot_order_text($order,'✅ پرداخت جدید با موفقیت ثبت شد');
    $tx = is_array($order['transaction']??null) ? $order['transaction'] : [];
    $reference = trim((string)($tx['reference_id']??$order['gateway_reference']??''));
    $card = trim((string)($tx['card_pan']??''));
    if ($reference !== '') $text .= "\n\nشناسه پرداخت: ".bale_product_bot_clean_line($reference,120);
    if ($card !== '') $text .= "\nکارت: ".bale_product_bot_clean_line($card,50);
    bale_product_bot_notify_allowed('payment-success:'.$orderId,$text,bale_product_bot_order_markup($order),'orders');
}

function bale_product_bot_notify_payment_alert(int $orderId, string $message, string $dedupeSuffix = ''): void
{
    if (!bale_product_bot_payment_notifications_enabled()) return;
    $order = bale_product_bot_order($orderId);
    if (!$order) return;
    $suffix = $dedupeSuffix !== '' ? text_substr(preg_replace('/[^a-zA-Z0-9_-]/','-',$dedupeSuffix)??'',0,80) : substr(hash('sha256',$message),0,16);
    $text = bale_product_bot_order_text($order,'⚠️ هشدار پرداخت یا درگاه');
    $text .= "\n\nشرح: ".bale_product_bot_clean_line($message,350);
    bale_product_bot_notify_allowed('payment-alert:'.$orderId.':'.$suffix,$text,bale_product_bot_order_markup($order),'orders');
}

/** @param array<string,mixed> $data */
function bale_product_bot_price_text(array $data): string
{
    $price = max(0,(int)($data['price']??0));
    $festivalPrice = isset($data['festival_price']) && $data['festival_price'] !== null ? max(0,(int)$data['festival_price']) : 0;
    $freeShipping = !empty($data['festival_free_shipping']);
    $lines = [
        'قیمت‌گذاری محصول',
        '',
        'از دو دکمه اول، قیمت اصلی و قیمت جشنواره اختیاری را وارد کنید.',
        '',
        'قیمت اصلی: ' . ($price > 0 ? fa_number(format_price($price)).' تومان' : 'ثبت نشده'),
    ];
    if ($price > 0) $lines[] = 'به حروف: ' . bale_product_bot_money_words($price);
    $lines[] = 'قیمت جشنواره: ' . ($festivalPrice > 0 ? fa_number(format_price($festivalPrice)).' تومان' : 'ندارد');
    if ($festivalPrice > 0) $lines[] = 'جشنواره به حروف: ' . bale_product_bot_money_words($festivalPrice);
    $lines[] = 'ارسال رایگان جشنواره: ' . ($freeShipping ? 'فعال' : 'غیرفعال');
    return implode("\n",$lines);
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function bale_product_bot_price_markup(array $data): array
{
    $price = max(0,(int)($data['price']??0));
    $festivalPrice = isset($data['festival_price']) && $data['festival_price'] !== null ? max(0,(int)$data['festival_price']) : 0;
    $freeShipping = !empty($data['festival_free_shipping']);
    $rows = [
        [
            ['text'=>($price > 0 ? '✅ ' : '').'💰 قیمت اصلی','callback_data'=>'price:main'],
            ['text'=>($festivalPrice > 0 ? '✅ ' : '').'🎉 قیمت جشنواره (اختیاری)','callback_data'=>'price:festival'],
        ],
        [[
            'text'=>($freeShipping ? '✅' : '⬜').' ارسال رایگان جشنواره',
            'callback_data'=>'price:free',
        ]],
    ];
    if ($festivalPrice > 0) $rows[] = [[ 'text'=>'🧹 حذف قیمت جشنواره','callback_data'=>'price:clear' ]];
    $rows[] = [[ 'text'=>'ادامه و ثبت موجودی ➡️','callback_data'=>'price:done' ]];
    $rows[] = [[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]];
    return bale_product_bot_inline_keyboard($rows);
}

/** @param array<string,mixed> $data */
function bale_product_bot_send_price_panel(string $chatId, string $userId, array $data): void
{
    $response = bale_product_bot_send($chatId,bale_product_bot_price_text($data),bale_product_bot_price_markup($data));
    $result = $response['result'] ?? null;
    if (is_array($result) && isset($result['message_id'])) $data['price_message_id'] = (int)$result['message_id'];
    bale_product_bot_session_save($chatId,$userId,'price_panel',$data);
}

/** @param array<string,mixed> $data */
function bale_product_bot_refresh_price_panel(string $chatId, string $userId, array $data): void
{
    $messageId = max(0,(int)($data['price_message_id']??0));
    if ($messageId > 0) {
        try {
            bale_product_bot_edit($chatId,$messageId,bale_product_bot_price_text($data),bale_product_bot_price_markup($data));
            bale_product_bot_session_save($chatId,$userId,'price_panel',$data);
            return;
        } catch (Throwable $error) {
            log_error($error);
        }
    }
    bale_product_bot_send_price_panel($chatId,$userId,$data);
}

/** @return array<int,array<string,mixed>> */
function bale_product_bot_attribute_definitions(): array
{
    try { return all_product_attributes(true); }
    catch (Throwable $error) { log_error($error); return []; }
}

/** @param array<string,mixed> $data @return array<int,int> */
function bale_product_bot_selected_attribute_ids(array $data): array
{
    return normalize_product_attribute_value_ids($data['attribute_value_ids']??[]);
}

/** @param array<string,mixed> $data */
function bale_product_bot_attribute_context(array $data): string
{
    return (string)($data['attribute_context']??'create') === 'edit' && (int)($data['product_id']??0) > 0
        ? 'edit'
        : 'create';
}

/** @param array<int,string> $allowedSteps */
function bale_product_bot_attribute_session_matches(?array $session, int $messageId = 0, array $allowedSteps = []): bool
{
    if (!$session) return false;
    if (!$allowedSteps) $allowedSteps = ['attribute_groups','attribute_values','attribute_new_name','attribute_new_type','attribute_new_value'];
    if (!in_array((string)($session['step']??''),$allowedSteps,true)) return false;
    $boundMessageId = max(0,(int)($session['data']['attribute_message_id']??0));
    return $messageId < 1 || $boundMessageId < 1 || $messageId === $boundMessageId;
}

function bale_product_bot_attribute_screen_message(string $chatId, int $messageId, string $text, array $markup): int
{
    if ($messageId > 0) {
        try {
            bale_product_bot_edit($chatId,$messageId,$text,$markup);
            return $messageId;
        } catch (Throwable $error) {
            log_error($error);
        }
    }
    $response = bale_product_bot_send($chatId,$text,$markup);
    $result = is_array($response['result']??null) ? $response['result'] : [];
    return max(0,(int)($result['message_id']??0));
}

function bale_product_bot_unique_attribute_slug(PDO $pdo, string $name): string
{
    $base = text_substr(slugify($name),0,110);
    if ($base === '') $base = 'attribute-' . date('Ymd-His');
    $check = $pdo->prepare('SELECT 1 FROM product_attributes WHERE slug=? LIMIT 1');
    $candidate = $base;
    for ($suffix=2;$suffix<=999;$suffix++) {
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) return $candidate;
        $candidate = text_substr($base,0,max(20,115-strlen((string)$suffix))) . '-' . $suffix;
    }
    return text_substr($base,0,105) . '-' . bin2hex(random_bytes(4));
}

function bale_product_bot_unique_attribute_value_slug(PDO $pdo, int $attributeId, string $value): string
{
    $base = text_substr(slugify($value),0,128);
    if ($base === '') $base = 'value-' . date('Ymd-His');
    $check = $pdo->prepare('SELECT 1 FROM product_attribute_values WHERE attribute_id=? AND slug=? LIMIT 1');
    $candidate = $base;
    for ($suffix=2;$suffix<=999;$suffix++) {
        $check->execute([$attributeId,$candidate]);
        if (!$check->fetchColumn()) return $candidate;
        $candidate = text_substr($base,0,max(20,135-strlen((string)$suffix))) . '-' . $suffix;
    }
    return text_substr($base,0,125) . '-' . bin2hex(random_bytes(4));
}

/** @return array{id:int,name:string,slug:string,display_type:string,created:bool,reactivated:bool} */
function bale_product_bot_create_attribute(string $name, string $displayType, string $userId): array
{
    $name = trim(preg_replace('/\s+/u',' ',trim($name))??trim($name));
    if (text_length($name)<2 || text_length($name)>100) throw new RuntimeException('نام ویژگی باید بین ۲ تا ۱۰۰ کاراکتر باشد.');
    if (!in_array($displayType,['button','color','select'],true)) throw new RuntimeException('نوع نمایش ویژگی معتبر نیست.');
    $pdo = db();
    $existing = $pdo->prepare('SELECT id,name,slug,display_type,is_active FROM product_attributes WHERE name=? ORDER BY id LIMIT 1');
    $existing->execute([$name]);
    $row = $existing->fetch();
    if ($row) {
        $reactivated = empty($row['is_active']);
        if ($reactivated) {
            $pdo->prepare('UPDATE product_attributes SET is_active=1 WHERE id=?')->execute([(int)$row['id']]);
            admin_audit_event('bale_product_attribute_reactivated','product_attribute',(int)$row['id'],[
                'username'=>'bale:'.$userId,'bale_user_id'=>$userId,'name'=>(string)$row['name'],
            ]);
        }
        return [
            'id'=>(int)$row['id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],
            'display_type'=>(string)$row['display_type'],'created'=>false,'reactivated'=>$reactivated,
        ];
    }
    $slug = bale_product_bot_unique_attribute_slug($pdo,$name);
    $sortOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM product_attributes')->fetchColumn();
    $pdo->prepare('INSERT INTO product_attributes(name,slug,display_type,sort_order,is_active) VALUES(?,?,?,?,1)')
        ->execute([$name,$slug,$displayType,$sortOrder]);
    $id = (int)$pdo->lastInsertId();
    admin_audit_event('bale_product_attribute_created','product_attribute',$id,[
        'username'=>'bale:'.$userId,'bale_user_id'=>$userId,'name'=>$name,'slug'=>$slug,'display_type'=>$displayType,
    ]);
    return ['id'=>$id,'name'=>$name,'slug'=>$slug,'display_type'=>$displayType,'created'=>true,'reactivated'=>false];
}

/** @return array{value:string,color_hex:?string} */
function bale_product_bot_parse_attribute_value(string $input): array
{
    $input = trim(preg_replace('/\s+/u',' ',trim($input))??trim($input));
    $colorHex = null;
    if (preg_match('/^(.*?)(?:\s*[|،]\s*|\s+)(#[0-9a-fA-F]{6})$/u',$input,$match)) {
        $input = trim((string)$match[1]);
        $colorHex = strtoupper((string)$match[2]);
    } elseif (str_contains($input,'#')) {
        throw new RuntimeException('کد رنگ باید دقیقاً مانند #FFFFFF باشد.');
    }
    if (text_length($input)<1 || text_length($input)>120) throw new RuntimeException('نام مقدار ویژگی باید بین ۱ تا ۱۲۰ کاراکتر باشد.');
    return ['value'=>$input,'color_hex'=>$colorHex];
}

/** @return array{id:int,attribute_id:int,value:string,color_hex:?string,created:bool,color_updated:bool} */
function bale_product_bot_create_attribute_value(int $attributeId, string $input, string $userId): array
{
    if ($attributeId < 1) throw new RuntimeException('ویژگی مقصد معتبر نیست.');
    $pdo = db();
    $attribute = $pdo->prepare('SELECT id,name,display_type FROM product_attributes WHERE id=? AND is_active=1 LIMIT 1');
    $attribute->execute([$attributeId]);
    $attributeRow = $attribute->fetch();
    if (!$attributeRow) throw new RuntimeException('ویژگی انتخاب‌شده فعال نیست.');
    $parsed = bale_product_bot_parse_attribute_value($input);
    $value = $parsed['value'];
    $colorHex = $parsed['color_hex'];
    $existing = $pdo->prepare('SELECT id,value,color_hex FROM product_attribute_values WHERE attribute_id=? AND value=? ORDER BY id LIMIT 1');
    $existing->execute([$attributeId,$value]);
    $row = $existing->fetch();
    if ($row) {
        $colorUpdated = $colorHex !== null && strtoupper((string)($row['color_hex']??'')) !== $colorHex;
        if ($colorUpdated) {
            $pdo->prepare('UPDATE product_attribute_values SET color_hex=? WHERE id=? AND attribute_id=?')->execute([$colorHex,(int)$row['id'],$attributeId]);
            admin_audit_event('bale_product_attribute_value_color_updated','product_attribute_value',(int)$row['id'],[
                'username'=>'bale:'.$userId,'bale_user_id'=>$userId,'attribute_id'=>$attributeId,'color_hex'=>$colorHex,
            ]);
        }
        return [
            'id'=>(int)$row['id'],'attribute_id'=>$attributeId,'value'=>(string)$row['value'],
            'color_hex'=>$colorUpdated?$colorHex:($row['color_hex']!==null?(string)$row['color_hex']:null),
            'created'=>false,'color_updated'=>$colorUpdated,
        ];
    }
    $slug = bale_product_bot_unique_attribute_value_slug($pdo,$attributeId,$value);
    $sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM product_attribute_values WHERE attribute_id=?');
    $sort->execute([$attributeId]);
    $sortOrder = (int)$sort->fetchColumn();
    $pdo->prepare('INSERT INTO product_attribute_values(attribute_id,value,slug,color_hex,sort_order) VALUES(?,?,?,?,?)')
        ->execute([$attributeId,$value,$slug,$colorHex,$sortOrder]);
    $id = (int)$pdo->lastInsertId();
    admin_audit_event('bale_product_attribute_value_created','product_attribute_value',$id,[
        'username'=>'bale:'.$userId,'bale_user_id'=>$userId,'attribute_id'=>$attributeId,
        'attribute_name'=>(string)$attributeRow['name'],'value'=>$value,'slug'=>$slug,'color_hex'=>$colorHex,
    ]);
    return ['id'=>$id,'attribute_id'=>$attributeId,'value'=>$value,'color_hex'=>$colorHex,'created'=>true,'color_updated'=>false];
}

/** @param array<string,mixed> $data */
function bale_product_bot_attribute_groups_text(array $data, int $page, int $pages): string
{
    $definitions = bale_product_bot_attribute_definitions();
    $lines = [
        bale_product_bot_attribute_context($data)==='edit' ? '🎨 رنگ و ویژگی‌های محصول' : '🎨 انتخاب ویژگی‌های محصول',
        '',
        'یک گروه مانند رنگ، ظرفیت یا مدل را انتخاب کنید؛ سپس گزینه‌های مجاز این محصول را تیک بزنید. مشتری هنگام تکمیل سفارش از هر گروه یک گزینه انتخاب می‌کند.',
        'تعداد انتخاب فعلی: ' . fa_number(count(bale_product_bot_selected_attribute_ids($data))),
    ];
    if (!$definitions) $lines[] = 'هنوز ویژگی فعالی وجود ندارد؛ از دکمه «ساخت ویژگی جدید» استفاده کنید.';
    $lines[] = '';
    $lines[] = 'صفحه ' . fa_number($page) . ' از ' . fa_number($pages);
    return implode("\n",$lines);
}

/** @param array<string,mixed> $data */
function bale_product_bot_attribute_groups_markup(array $data, int $page = 1): array
{
    $definitions = bale_product_bot_attribute_definitions();
    $perPage = 8;
    $pages = max(1,(int)ceil(count($definitions)/$perPage));
    $page = min(max(1,$page),$pages);
    $chunk = array_slice($definitions,($page-1)*$perPage,$perPage);
    $selected = bale_product_bot_selected_attribute_ids($data);
    $rows = [];
    foreach ($chunk as $attribute) {
        $count = 0;
        foreach (($attribute['values']??[]) as $value) if (in_array((int)($value['id']??0),$selected,true)) $count++;
        $label = '🎛 ' . text_substr((string)($attribute['name']??'ویژگی'),0,38);
        if ($count > 0) $label .= ' (' . fa_number($count) . ' انتخاب)';
        $rows[] = [[ 'text'=>$label,'callback_data'=>'attr:group:'.(int)$attribute['id'].':'.$page.':1' ]];
    }
    if ($pages > 1) {
        $nav = [];
        if ($page > 1) $nav[] = ['text'=>'⬅️ قبلی','callback_data'=>'attr:groups:'.($page-1)];
        $nav[] = ['text'=>fa_number($page).' / '.fa_number($pages),'callback_data'=>'page:noop:0'];
        if ($page < $pages) $nav[] = ['text'=>'بعدی ➡️','callback_data'=>'attr:groups:'.($page+1)];
        $rows[] = $nav;
    }
    $rows[] = [[ 'text'=>'➕ ساخت ویژگی جدید','callback_data'=>'attr:new' ]];
    $rows[] = [[
        'text'=>bale_product_bot_attribute_context($data)==='edit'?'✅ ذخیره رنگ و ویژگی‌ها':'✅ پایان انتخاب ویژگی‌ها',
        'callback_data'=>'attr:done',
    ]];
    return bale_product_bot_inline_keyboard($rows);
}

/** @param array<string,mixed> $data */
function bale_product_bot_attribute_values_screen(array $data, int $attributeId, int $page = 1): array
{
    $attribute = null;
    foreach (bale_product_bot_attribute_definitions() as $candidate) if ((int)($candidate['id']??0)===$attributeId) { $attribute=$candidate; break; }
    if (!$attribute) return ['text'=>'این ویژگی دیگر فعال نیست.','markup'=>bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ بازگشت','callback_data'=>'attr:groups:1' ]]])];
    $values = array_values(is_array($attribute['values']??null)?$attribute['values']:[]);
    $perPage = 8;
    $pages = max(1,(int)ceil(count($values)/$perPage));
    $page = min(max(1,$page),$pages);
    $selected = bale_product_bot_selected_attribute_ids($data);
    $locked = normalize_product_attribute_value_ids($data['attribute_locked_value_ids']??[]);
    $rows = [];
    foreach (array_slice($values,($page-1)*$perPage,$perPage) as $value) {
        $valueId = (int)($value['id']??0);
        if ($valueId < 1) continue;
        $checked = in_array($valueId,$selected,true);
        $isLocked = $checked && in_array($valueId,$locked,true);
        $label = (string)($value['value']??'');
        if ((string)($attribute['display_type']??'')==='color') {
            $hex = strtoupper(trim((string)($value['color_hex']??'')));
            $label = '🎨 ' . $label . ($hex!==''?' · '.$hex:'');
        }
        $rows[] = [[
            'text'=>($isLocked?'🔒':($checked?'✅':'⬜')).' '.text_substr($label,0,48),
            'callback_data'=>'attr:value:'.$attributeId.':'.$valueId.':'.$page,
        ]];
    }
    $returnPage = max(1,(int)($data['attribute_groups_page']??1));
    if ($pages > 1) {
        $nav = [];
        if ($page > 1) $nav[] = ['text'=>'⬅️ قبلی','callback_data'=>'attr:group:'.$attributeId.':'.$returnPage.':'.($page-1)];
        $nav[] = ['text'=>fa_number($page).' / '.fa_number($pages),'callback_data'=>'page:noop:0'];
        if ($page < $pages) $nav[] = ['text'=>'بعدی ➡️','callback_data'=>'attr:group:'.$attributeId.':'.$returnPage.':'.($page+1)];
        $rows[] = $nav;
    }
    $rows[] = [[
        'text'=>(string)($attribute['display_type']??'')==='color'?'➕ افزودن رنگ جدید':'➕ افزودن مقدار جدید',
        'callback_data'=>'attr:addv:'.$attributeId,
    ]];
    $rows[] = [[ 'text'=>'⬅️ گروه‌های ویژگی','callback_data'=>'attr:groups:'.$returnPage ],[ 'text'=>'✅ ذخیره و پایان','callback_data'=>'attr:done' ]];
    $text = 'ویژگی: ' . (string)($attribute['name']??'') . "\n\n" .
        ($values?'گزینه‌های مجاز این محصول را تیک بزنید. هر تعداد گزینه قابل ثبت است و مشتری در تکمیل سفارش یکی را انتخاب می‌کند.':'هنوز مقداری برای این ویژگی ثبت نشده است؛ یک مقدار جدید بسازید.') . "\n" .
        'تعداد کل انتخاب‌ها: ' . fa_number(count($selected));
    if ($locked) $text .= "\n🔒 گزینه‌های قفل‌شده در مدل‌های محصول استفاده شده‌اند و از اینجا حذف نمی‌شوند.";
    return ['text'=>$text,'markup'=>bale_product_bot_inline_keyboard($rows)];
}

/** @param array<string,mixed> $data */
function bale_product_bot_open_attribute_groups(string $chatId, string $userId, array $data, int $page = 1, int $messageId = 0): void
{
    unset($data['attribute_new_name'],$data['attribute_id'],$data['attribute_value_page']);
    $definitions = bale_product_bot_attribute_definitions();
    $pages = max(1,(int)ceil(count($definitions)/8));
    $page = min(max(1,$page),$pages);
    $data['attribute_groups_page'] = $page;
    $targetMessageId = $messageId > 0 ? $messageId : max(0,(int)($data['attribute_message_id']??0));
    $data['attribute_message_id'] = bale_product_bot_attribute_screen_message(
        $chatId,$targetMessageId,bale_product_bot_attribute_groups_text($data,$page,$pages),bale_product_bot_attribute_groups_markup($data,$page)
    );
    bale_product_bot_session_save($chatId,$userId,'attribute_groups',$data);
}

/** @param array<string,mixed> $data */
function bale_product_bot_open_attribute_values(string $chatId, string $userId, array $data, int $attributeId, int $page = 1, int $messageId = 0): void
{
    unset($data['attribute_new_name']);
    $data['attribute_id'] = $attributeId;
    $data['attribute_value_page'] = max(1,$page);
    $screen = bale_product_bot_attribute_values_screen($data,$attributeId,$data['attribute_value_page']);
    $targetMessageId = $messageId > 0 ? $messageId : max(0,(int)($data['attribute_message_id']??0));
    $data['attribute_message_id'] = bale_product_bot_attribute_screen_message($chatId,$targetMessageId,(string)$screen['text'],$screen['markup']);
    bale_product_bot_session_save($chatId,$userId,'attribute_values',$data);
}

/** @param array<string,mixed> $data */
function bale_product_bot_options_text(array $data): string
{
    $warranty = max(0,min(120,(int)($data['warranty_months']??0)));
    $homepageFestival = configured_homepage_festival();
    $selectedAttributes = count(bale_product_bot_selected_attribute_ids($data));
    $lines = [
        'تنظیم مزایا و ویژگی‌های محصول',
        '',
        'گارانتی: ' . ($warranty > 0 ? fa_number($warranty).' ماه' : 'ندارد'),
        'تضمین اصالت: ' . (!empty($data['authenticity_guarantee']) ? 'فعال' : 'غیرفعال'),
        'تضمین ارسال سالم: ' . (!empty($data['safe_shipping_guarantee']) ? 'فعال' : 'غیرفعال'),
        'ویژگی‌های قابل انتخاب: ' . fa_number($selectedAttributes) . ' مورد',
    ];
    if ($homepageFestival) $lines[] = 'ردیف جشنواره صفحه اول «'.(string)$homepageFestival['title'].'»: '.(!empty($data['home_festival'])?'فعال':'غیرفعال');
    $lines[] = '';
    $lines[] = 'گزینه‌ها را با دکمه‌های شیشه‌ای تغییر دهید؛ همان پیام به‌روزرسانی می‌شود.';
    return implode("\n",$lines);
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function bale_product_bot_options_markup(array $data): array
{
    $warranty = max(0,min(120,(int)($data['warranty_months']??0)));
    $rows = [
        [
            [ 'text'=>($warranty===0?'✅':'⬜').' بدون گارانتی','callback_data'=>'opt:w:0' ],
            [ 'text'=>($warranty===6?'✅':'').' ۶ ماه','callback_data'=>'opt:w:6' ],
            [ 'text'=>($warranty===12?'✅':'').' ۱۲ ماه','callback_data'=>'opt:w:12' ],
        ],
        [
            [ 'text'=>($warranty===18?'✅':'').' ۱۸ ماه','callback_data'=>'opt:w:18' ],
            [ 'text'=>($warranty===24?'✅':'').' ۲۴ ماه','callback_data'=>'opt:w:24' ],
            [ 'text'=>($warranty===36?'✅':'').' ۳۶ ماه','callback_data'=>'opt:w:36' ],
        ],
        [[ 'text'=>'✍️ گارانتی دلخواه (۱ تا ۱۲۰ ماه)','callback_data'=>'opt:w:custom' ]],
        [
            [ 'text'=>(!empty($data['authenticity_guarantee'])?'✅':'⬜').' تضمین اصالت', 'callback_data'=>'opt:a' ],
            [ 'text'=>(!empty($data['safe_shipping_guarantee'])?'✅':'⬜').' ارسال سالم', 'callback_data'=>'opt:s' ],
        ],
        [[ 'text'=>'🎛 ویژگی‌های قابل انتخاب ('.fa_number(count(bale_product_bot_selected_attribute_ids($data))).')','callback_data'=>'opt:attributes' ]],
    ];
    if (configured_homepage_festival()) {
        $rows[] = [[ 'text'=>(!empty($data['home_festival'])?'✅':'⬜').' نمایش در جشنواره صفحه اول','callback_data'=>'opt:home' ]];
    }
    $rows[] = [[ 'text'=>'ادامه و افزودن تصاویر ➡️', 'callback_data'=>'opt:done' ]];
    return bale_product_bot_inline_keyboard($rows);
}

/** @param array<string,mixed> $data */
function bale_product_bot_send_options(string $chatId, string $userId, array $data): void
{
    $response = bale_product_bot_send($chatId, bale_product_bot_options_text($data), bale_product_bot_options_markup($data));
    $result = $response['result'] ?? null;
    if (is_array($result) && isset($result['message_id'])) $data['options_message_id'] = (int)$result['message_id'];
    bale_product_bot_session_save($chatId,$userId,'product_options',$data);
}

/** @return array<string,mixed>|null */
function bale_product_bot_product_for_edit(int $productId): ?array
{
    if ($productId < 1) return null;
    $stmt = db()->prepare("SELECT p.*,c.name category_name,c.is_active category_active,b.name brand_name,b.is_active brand_active,(SELECT COUNT(*) FROM product_features pf WHERE pf.product_id=p.id) feature_count,(SELECT COUNT(*) FROM product_specifications ps WHERE ps.product_id=p.id) specification_count,(SELECT COUNT(*) FROM product_attribute_selections pas WHERE pas.product_id=p.id) attribute_selection_count,(SELECT COUNT(DISTINCT pvv.attribute_value_id) FROM product_variants pv JOIN product_variant_values pvv ON pvv.variant_id=pv.id WHERE pv.product_id=p.id) used_attribute_count FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.id=? LIMIT 1");
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bale_product_bot_warranty_months(string $warranty): int
{
    if (preg_match('/(\d{1,3})/u',normalize_digits($warranty),$match)) return max(0,min(120,(int)$match[1]));
    return 0;
}

/** @param array<string,mixed> $product */
function bale_product_bot_product_edit_text(array $product): string
{
    $description=trim((string)($product['description']??''));
    $descriptionPreview=$description===''?'ندارد':text_substr(preg_replace('/\s+/u',' ',$description)??$description,0,260).(text_length($description)>260?'…':'');
    $festivalPrice=isset($product['festival_price'])&&$product['festival_price']!==null?max(0,(int)$product['festival_price']):0;
    $warranty=bale_product_bot_warranty_months((string)($product['warranty']??''));
    return implode("\n",[
        '✏️ ویرایش محصول',
        '',
        'نام: '.(string)$product['title'],
        'کد کالا: '.(string)$product['sku'],
        'دسته: '.(string)$product['category_name'],
        'برند: '.(string)$product['brand_name'],
        'قیمت: '.fa_number(format_price((int)$product['price'])).' تومان',
        'موجودی: '.fa_number((int)$product['stock']),
        'وضعیت: '.((string)$product['status']==='active'?'فعال':'پیش‌نویس'),
        'گارانتی: '.($warranty>0?fa_number($warranty).' ماه':'ندارد'),
        'تضمین اصالت: '.(!empty($product['authenticity_guarantee'])?'بله':'خیر'),
        'تضمین ارسال سالم: '.(!empty($product['safe_shipping_guarantee'])?'بله':'خیر'),
        'قیمت جشنواره: '.($festivalPrice>0?fa_number(format_price($festivalPrice)).' تومان':'ندارد'),
        'ارسال رایگان جشنواره: '.(!empty($product['festival_free_shipping'])?'بله':'خیر'),
        'رنگ و ویژگی‌های انتخابی: '.fa_number(max((int)($product['attribute_selection_count']??0),(int)($product['used_attribute_count']??0))),
        'ویژگی متنی: '.fa_number((int)($product['feature_count']??0)),
        'مشخصات فنی: '.fa_number((int)($product['specification_count']??0)),
        'توضیحات: '.$descriptionPreview,
        '',
        'بخشی را که می‌خواهید تغییر کند انتخاب کنید. تغییرات بلافاصله ذخیره می‌شوند.',
    ]);
}

/** @param array<string,mixed> $product @return array<string,mixed> */
function bale_product_bot_product_edit_markup(array $product): array
{
    $id=(int)$product['id'];
    $warranty=bale_product_bot_warranty_months((string)($product['warranty']??''));
    $festivalPrice=isset($product['festival_price'])&&$product['festival_price']!==null?max(0,(int)$product['festival_price']):0;
    $attributeCount=max((int)($product['attribute_selection_count']??0),(int)($product['used_attribute_count']??0));
    return bale_product_bot_inline_keyboard([
        [[ 'text'=>'📝 نام محصول','callback_data'=>'pe:title:'.$id ],[ 'text'=>'📂 دسته‌بندی','callback_data'=>'pe:category:'.$id ]],
        [[ 'text'=>'🏷 برند','callback_data'=>'pe:brand:'.$id ],[ 'text'=>'💰 قیمت','callback_data'=>'pe:price:'.$id ]],
        [[ 'text'=>'📦 موجودی','callback_data'=>'pe:stock:'.$id ],[ 'text'=>'📄 توضیحات','callback_data'=>'pe:description:'.$id ]],
        [[ 'text'=>'📋 ویژگی‌ها و مشخصات','callback_data'=>'pe:features:'.$id ],[ 'text'=>'🛡 گارانتی '.($warranty>0?fa_number($warranty).' ماه':'ندارد'),'callback_data'=>'pe:warranty:'.$id ]],
        [[ 'text'=>'🎨 رنگ و ویژگی‌های انتخابی ('.fa_number($attributeCount).')','callback_data'=>'pe:attributes:'.$id ]],
        [[ 'text'=>(!empty($product['authenticity_guarantee'])?'✅':'⬜').' اصالت','callback_data'=>'pe:auth:'.$id ],[ 'text'=>(!empty($product['safe_shipping_guarantee'])?'✅':'⬜').' ارسال سالم','callback_data'=>'pe:safe:'.$id ]],
        [[ 'text'=>'🎉 قیمت جشنواره '.($festivalPrice>0?fa_number(format_price($festivalPrice)):'ندارد'),'callback_data'=>'pe:fprice:'.$id ],[ 'text'=>(!empty($product['festival_free_shipping'])?'✅':'⬜').' ارسال رایگان','callback_data'=>'pe:ffree:'.$id ]],
        [[ 'text'=>(string)$product['status']==='active'?'🟢 تبدیل به پیش‌نویس':'🚀 فعال‌کردن محصول','callback_data'=>'pe:status:'.$id ]],
        [[ 'text'=>'✅ پایان ویرایش','callback_data'=>'pe:done:'.$id ]],
    ]);
}

function bale_product_bot_show_product_edit(string $chatId, string $userId, int $productId, int $messageId = 0, string $notice = ''): void
{
    $product=bale_product_bot_product_for_edit($productId);
    if(!$product){bale_product_bot_session_delete($chatId);bale_product_bot_send($chatId,'محصول پیدا نشد یا حذف شده است.',bale_product_bot_main_keyboard());return;}
    $text=($notice!==''?$notice."\n\n":'').bale_product_bot_product_edit_text($product);
    $response=[];
    if($messageId>0){try{$response=bale_product_bot_edit($chatId,$messageId,$text,bale_product_bot_product_edit_markup($product));}catch(Throwable $error){log_error($error);$response=bale_product_bot_send($chatId,$text,bale_product_bot_product_edit_markup($product));}}
    else $response=bale_product_bot_send($chatId,$text,bale_product_bot_product_edit_markup($product));
    $result=is_array($response['result']??null)?$response['result']:[];
    $menuMessageId=max($messageId,max(0,(int)($result['message_id']??0)));
    bale_product_bot_session_save($chatId,$userId,'product_edit_menu',['product_id'=>$productId,'menu_message_id'=>$menuMessageId]);
}

function bale_product_bot_product_edit_session_matches(?array $session, int $productId, bool $allowPrompt = false, int $messageId = 0): bool
{
    if (!$session || (int)($session['data']['product_id']??0) !== $productId) return false;
    $sessionMessageId=max(0,(int)($session['data']['menu_message_id']??0));
    if($messageId>0 && $sessionMessageId>0 && $messageId!==$sessionMessageId) return false;
    $step=(string)($session['step']??'');
    if ($step === 'product_edit_menu') return true;
    return $allowPrompt && str_starts_with($step,'product_edit_');
}

function bale_product_bot_product_edit_prompt(string $chatId, string $userId, int $productId, int $messageId, string $step, string $prompt): void
{
    bale_product_bot_session_save($chatId,$userId,$step,['product_id'=>$productId,'menu_message_id'=>$messageId]);
    bale_product_bot_edit($chatId,$messageId,$prompt,bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ بازگشت به ویرایش','callback_data'=>'pe:back:'.$productId ]]]));
}

function bale_product_bot_product_can_activate(array $product): void
{
    if(empty($product['category_active'])||empty($product['brand_active']))throw new RuntimeException('برای فعال‌کردن، دسته و برند محصول باید فعال باشند.');
    if((string)($product['product_type']??'simple')==='variable'){
        $stmt=db()->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id=? AND status='active' AND price>0");$stmt->execute([(int)$product['id']]);
        if((int)$stmt->fetchColumn()<1)throw new RuntimeException('محصول متغیر برای فعال‌شدن باید حداقل یک متغیر فعال و قیمت‌دار داشته باشد.');
    }elseif((int)($product['price']??0)<1)throw new RuntimeException('قیمت محصول فعال باید بیشتر از صفر باشد.');
}

function bale_product_bot_product_edit_audit(int $productId, string $userId, string $field, mixed $value): void
{
    admin_audit_event('bale_product_edited','product',$productId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'field'=>$field,'value'=>is_scalar($value)?text_substr((string)$value,0,500):'updated']);
}

/** @param array<int,int|string> $selectedIds @return array<int,int> */
function bale_product_bot_save_product_attribute_selections(int $productId, array $selectedIds, string $userId): array
{
    if($productId<1)throw new RuntimeException('محصول معتبر نیست.');
    $selected=normalize_product_attribute_value_ids($selectedIds);
    $pdo=db();
    try{
        $pdo->beginTransaction();
        $lockSql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? 'SELECT id FROM products WHERE id=? FOR UPDATE'
            : 'SELECT id FROM products WHERE id=?';
        $lock=$pdo->prepare($lockSql);$lock->execute([$productId]);
        if(!$lock->fetchColumn())throw new RuntimeException('محصول پیدا نشد یا حذف شده است.');
        $validRows=product_attribute_value_rows($pdo,$selected,true);
        if(count($validRows)!==count($selected))throw new RuntimeException('یکی از رنگ‌ها یا ویژگی‌های انتخاب‌شده دیگر فعال نیست.');
        $used=product_used_attribute_value_ids($productId,$pdo);
        if(array_diff($used,$selected))throw new RuntimeException('یکی از گزینه‌ها در مدل‌های محصول استفاده شده و قابل حذف نیست.');
        replace_product_attribute_selections($pdo,$productId,$selected);
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
    bale_product_bot_product_edit_audit($productId,$userId,'attribute_value_ids',implode(',',$selected));
    return $selected;
}

/** @param array<string,mixed> $data */
function bale_product_bot_confirmation(string $chatId, array $data): void
{
    $category = '';
    $brand = '';
    if (!empty($data['category_id'])) {
        $stmt=db()->prepare('SELECT name FROM categories WHERE id=?');
        $stmt->execute([(int)$data['category_id']]);
        $category=(string)($stmt->fetchColumn()?:'');
    }
    if (!empty($data['brand_id'])) {
        $stmt=db()->prepare('SELECT name FROM brands WHERE id=?');
        $stmt->execute([(int)$data['brand_id']]);
        $brand=(string)($stmt->fetchColumn()?:'');
    }
    $price = max(0,(int)($data['price']??0));
    $festivalPrice = isset($data['festival_price']) && $data['festival_price'] !== null ? max(0,(int)$data['festival_price']) : 0;
    $warranty=max(0,(int)($data['warranty_months']??0));
    $lines=[
        'پیش‌نمایش محصول',
        '',
        'نام: '.($data['title']??''),
        'دسته: '.$category,
        'برند: '.$brand,
        'قیمت اصلی: '.fa_number(format_price($price)).' تومان',
        'به حروف: '.bale_product_bot_money_words($price),
        'قیمت جشنواره: '.($festivalPrice>0?fa_number(format_price($festivalPrice)).' تومان':'ندارد'),
        'ارسال رایگان جشنواره: '.(!empty($data['festival_free_shipping'])?'بله':'خیر'),
        'موجودی: '.fa_number((int)($data['stock']??0)),
        'گارانتی: '.($warranty>0?fa_number($warranty).' ماه':'ندارد'),
        'تضمین اصالت: '.(!empty($data['authenticity_guarantee'])?'بله':'خیر'),
        'تضمین ارسال سالم: '.(!empty($data['safe_shipping_guarantee'])?'بله':'خیر'),
        'ویژگی‌های قابل انتخاب: '.fa_number(count(bale_product_bot_selected_attribute_ids($data))),
        'نمایش در جشنواره صفحه اول: '.(!empty($data['home_festival'])?'بله':'خیر'),
        'تصاویر: '.fa_number(count($data['images']??[])),
        'ویژگی متنی: '.fa_number(count($data['features']??[])),
        'مشخصات فنی: '.fa_number(count($data['specifications']??[])),
        'توضیحات: '.(trim((string)($data['description']??''))!==''?text_substr(preg_replace('/\s+/u',' ',trim((string)$data['description']))??trim((string)$data['description']),0,260):'ندارد'),
        'وضعیت: '.(($data['status']??'draft')==='active'?'انتشار مستقیم':'پیش‌نویس'),
        '',
        'اطلاعات را بررسی و ثبت نهایی را تأیید کنید.',
    ];
    bale_product_bot_send($chatId,implode("\n",$lines),bale_product_bot_keyboard([['✅ ثبت محصول'],['🔄 شروع دوباره','❌ لغو عملیات']]));
}

/** @param array<string,mixed> $callback */
function bale_product_bot_process_callback(array $callback): void
{
    $callbackId = trim(scalar_string($callback['id']??''));
    $dataValue = trim(scalar_string($callback['data']??''));
    $from = is_array($callback['from']??null) ? $callback['from'] : [];
    $message = is_array($callback['message']??null) ? $callback['message'] : [];
    $chat = is_array($message['chat']??null) ? $message['chat'] : [];
    $chatId = trim(scalar_string($chat['id']??''));
    $userId = trim(scalar_string($from['id']??$chatId));
    $messageId = max(0,(int)($message['message_id']??0));
    if ($chatId==='' || $userId==='') { bale_product_bot_answer_callback($callbackId,'درخواست معتبر نیست.'); return; }
    bale_product_bot_runtime_user($userId);
    bale_product_bot_runtime_chat($chatId);
    if (!bale_product_bot_is_allowed($userId)) { bale_product_bot_answer_callback($callbackId,'دسترسی ندارید.'); return; }
    $requiredCapability=bale_product_bot_callback_capability($dataValue);
    if($requiredCapability!==null && !bale_product_bot_can($userId,$requiredCapability)){bale_product_bot_answer_callback($callbackId,'دسترسی این بخش برای شما فعال نیست.');return;}
    $session = bale_product_bot_session($chatId);
    if($session && (string)$session['user_id']!==$userId)$session=null;

    if (!bale_product_bot_callback_matches_session($session,$dataValue)) {
        bale_product_bot_answer_callback($callbackId,'این دکمه مربوط به عملیات دیگری است؛ عملیات فعلی دست‌نخورده ماند.');
        return;
    }

    if ($dataValue === 'nav:home') {
        if ($session) {
            bale_product_bot_answer_callback($callbackId,'ابتدا عملیات فعلی را پایان دهید یا از دکمه لغو همان عملیات استفاده کنید.');
            return;
        }
        bale_product_bot_answer_callback($callbackId,'داشبورد نمایش داده شد.');
        try { bale_product_bot_edit($chatId,$messageId,'🏠 این عملیات بسته است؛ از داشبورد پایین ادامه دهید.'); } catch(Throwable $error) { log_error($error); }
        if (function_exists('bale_dashboard_send')) bale_dashboard_send($chatId,$userId);
        else bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));
        return;
    }

    if (preg_match('/^cancel:([a-f0-9]{16})$/D',$dataValue,$cancelMatch)) {
        $sessionToken=$session ? trim((string)($session['data']['_flow_token']??'')) : '';
        if (!$session || !hash_equals($sessionToken,(string)$cancelMatch[1])) {
            bale_product_bot_answer_callback($callbackId,'این دکمه لغو منقضی شده است؛ عملیات فعلی تغییر نکرد.');
            return;
        }
        $outcome=bale_product_bot_cancel_outcome($session);
        if (!$outcome['allowed']) { bale_product_bot_answer_callback($callbackId,$outcome['message']); return; }
        bale_product_bot_session_delete($chatId);
        bale_product_bot_answer_callback($callbackId,'عملیات لغو شد.');
        try { bale_product_bot_edit($chatId,$messageId,$outcome['message']); } catch(Throwable $error) { log_error($error); }
        if (function_exists('bale_dashboard_send')) bale_dashboard_send($chatId,$userId);
        else bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));
        return;
    }

    if ($dataValue === 'cancel') {
        if ($session) {
            bale_product_bot_answer_callback($callbackId,'این دکمه متعلق به نسخه قدیمی است؛ عملیات فعلی دست‌نخورده ماند. از لغو همان پیام استفاده کنید.');
            return;
        }
        bale_product_bot_answer_callback($callbackId,'این دکمه قدیمی منقضی شده است.');
        if (function_exists('bale_dashboard_send')) bale_dashboard_send($chatId,$userId);
        else bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard($userId));
        return;
    }

    if ($dataValue === 'page:noop:0') { bale_product_bot_answer_callback($callbackId,'صفحه جاری'); return; }

    if (function_exists('bale_torobpay_festival_callback') && bale_torobpay_festival_callback($chatId,$userId,$messageId,$dataValue,$callbackId,$session)) return;

    if (function_exists('bale_price_adjustment_callback') && bale_price_adjustment_callback($chatId,$userId,$messageId,$dataValue,$callbackId,$session)) return;

    if (function_exists('bale_product_bot_advanced_callback') && bale_product_bot_advanced_callback($chatId,$userId,$messageId,$dataValue,$callbackId,$session)) return;

    if (preg_match('/^page:(c|b|ec|eb|dc|dp|ep|pl|o):(\d+)$/',$dataValue,$match)) {
        $mode = (string)$match[1];
        $requiredStep = ['c'=>'category','b'=>'brand','ec'=>'product_edit_category','eb'=>'product_edit_brand','dc'=>'category_delete_select','dp'=>'product_delete_select','ep'=>'product_edit_select','pl'=>'product_link_select','o'=>'order_list'][$mode] ?? '';
        if (!$session || $session['step'] !== $requiredStep) { bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.'); return; }
        if(in_array($mode,['ec','eb'],true) && (int)($session['data']['menu_message_id']??0)!==$messageId){bale_product_bot_answer_callback($callbackId,'این فهرست ویرایش منقضی شده است.');return;}
        bale_product_bot_edit_inline_list($chatId,$messageId,$mode,max(1,(int)$match[2]));
        bale_product_bot_answer_callback($callbackId);
        return;
    }

    if (preg_match('/^pick:c:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step'] !== 'category') { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $id = (int)$match[1];
        $stmt = db()->prepare("SELECT id,name FROM categories WHERE id=? AND is_active=1 AND slug<>'festival' LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { bale_product_bot_answer_callback($callbackId,'دسته معتبر نیست.'); return; }
        $state = $session['data'];
        $state['category_id'] = $id;
        bale_product_bot_session_save($chatId,$userId,'brand',$state);
        try { bale_product_bot_edit($chatId,$messageId,'✅ دسته انتخاب شد: '.(string)$row['name']); } catch(Throwable $error) { log_error($error); }
        bale_product_bot_answer_callback($callbackId,'دسته انتخاب شد.');
        bale_product_bot_send_inline_list($chatId,'b',1);
        return;
    }

    if (preg_match('/^pick:b:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step'] !== 'brand') { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $id = (int)$match[1];
        $stmt = db()->prepare('SELECT id,name FROM brands WHERE id=? AND is_active=1 LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { bale_product_bot_answer_callback($callbackId,'برند معتبر نیست.'); return; }
        $state = $session['data'];
        $state['brand_id'] = $id;
        $state['price'] = max(0,(int)($state['price']??0));
        $state['festival_price'] = $state['festival_price']??null;
        $state['festival_free_shipping'] = !empty($state['festival_free_shipping']);
        try { bale_product_bot_edit($chatId,$messageId,'✅ برند انتخاب شد: '.(string)$row['name']); } catch(Throwable $error) { log_error($error); }
        bale_product_bot_answer_callback($callbackId,'برند انتخاب شد.');
        bale_product_bot_send_price_panel($chatId,$userId,$state);
        return;
    }

    if (preg_match('/^pick:ep:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step']!=='product_edit_select') { bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.'); return; }
        $productId=(int)$match[1];
        if(!bale_product_bot_product_for_edit($productId)){bale_product_bot_answer_callback($callbackId,'محصول پیدا نشد.');return;}
        bale_product_bot_answer_callback($callbackId,'محصول انتخاب شد.');
        bale_product_bot_show_product_edit($chatId,$userId,$productId,$messageId);
        return;
    }

    if (preg_match('/^pick:pl:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step']!=='product_link_select') { bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.'); return; }
        $productId=(int)$match[1];
        $stmt=db()->prepare('SELECT p.id,p.title,p.sku,p.slug,p.status,p.price,c.is_active category_active,b.is_active brand_active FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.id=? LIMIT 1');
        $stmt->execute([$productId]);
        $product=$stmt->fetch();
        if(!$product){bale_product_bot_answer_callback($callbackId,'محصول پیدا نشد.');return;}
        $url=product_url($product);
        $isPublic=(string)($product['status']??'')==='active' && (int)($product['price']??0)>0 && !empty($product['category_active']) && !empty($product['brand_active']);
        $statusText=$isPublic?'✅ فعال و قابل مشاهده':'⚠️ فعلاً در سایت عمومی قابل مشاهده نیست';
        $text="🔗 لینک محصول\n\nمحصول: ".(string)$product['title']."\nکد کالا: ".(string)$product['sku']."\nوضعیت: {$statusText}\n\n{$url}";
        if(!$isPublic)$text.="\n\nتا زمانی که محصول فعال، دارای قیمت و دسته/برند آن فعال نباشد، این لینک برای مشتری صفحه محصول را نمایش نمی‌دهد.";
        bale_product_bot_answer_callback($callbackId,'لینک ارسال شد.');
        bale_product_bot_send($chatId,$text);
        return;
    }

    if (preg_match('/^pick:ec:(\d+)$/',$dataValue,$match)) {
        if(!$session||$session['step']!=='product_edit_category'||(int)($session['data']['menu_message_id']??0)!==$messageId){bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.');return;}
        $productId=max(0,(int)($session['data']['product_id']??0));$id=(int)$match[1];
        $stmt=db()->prepare("SELECT id,name FROM categories WHERE id=? AND is_active=1 AND slug<>'festival' LIMIT 1");$stmt->execute([$id]);$row=$stmt->fetch();
        if($productId<1||!$row||!bale_product_bot_product_for_edit($productId)){bale_product_bot_answer_callback($callbackId,'دسته یا محصول معتبر نیست.');return;}
        db()->prepare('UPDATE products SET category_id=? WHERE id=?')->execute([$id,$productId]);
        bale_product_bot_product_edit_audit($productId,$userId,'category_id',$id);
        bale_product_bot_answer_callback($callbackId,'دسته تغییر کرد.');
        bale_product_bot_show_product_edit($chatId,$userId,$productId,$messageId,'✅ دسته‌بندی تغییر کرد: '.(string)$row['name']);
        return;
    }

    if (preg_match('/^pick:eb:(\d+)$/',$dataValue,$match)) {
        if(!$session||$session['step']!=='product_edit_brand'||(int)($session['data']['menu_message_id']??0)!==$messageId){bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.');return;}
        $productId=max(0,(int)($session['data']['product_id']??0));$id=(int)$match[1];
        $stmt=db()->prepare('SELECT id,name FROM brands WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();
        if($productId<1||!$row||!bale_product_bot_product_for_edit($productId)){bale_product_bot_answer_callback($callbackId,'برند یا محصول معتبر نیست.');return;}
        db()->prepare('UPDATE products SET brand_id=? WHERE id=?')->execute([$id,$productId]);
        bale_product_bot_product_edit_audit($productId,$userId,'brand_id',$id);
        bale_product_bot_answer_callback($callbackId,'برند تغییر کرد.');
        bale_product_bot_show_product_edit($chatId,$userId,$productId,$messageId,'✅ برند تغییر کرد: '.(string)$row['name']);
        return;
    }

    if (preg_match('/^pe:(title|category|brand|price|stock|description|features|attributes|warranty|auth|safe|fprice|ffree|status|back|done):(\d+)$/',$dataValue,$match)) {
        $action=(string)$match[1];$productId=(int)$match[2];$product=bale_product_bot_product_for_edit($productId);
        if(!$product){bale_product_bot_answer_callback($callbackId,'محصول پیدا نشد.');return;}
        $allowPrompt=$action==='back';
        if(!bale_product_bot_product_edit_session_matches($session,$productId,$allowPrompt,$messageId)){bale_product_bot_answer_callback($callbackId,'این دکمه ویرایش منقضی شده است؛ محصول را دوباره از منوی ویرایش باز کنید.');return;}
        if($action==='done'){
            bale_product_bot_session_delete($chatId);bale_product_bot_answer_callback($callbackId,'ویرایش پایان یافت.');
            try{bale_product_bot_edit($chatId,$messageId,'✅ ویرایش محصول پایان یافت.\nمحصول: '.(string)$product['title'].'\nکد: '.(string)$product['sku']);}catch(Throwable $error){log_error($error);}
            bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard());return;
        }
        if($action==='back'){bale_product_bot_answer_callback($callbackId);bale_product_bot_show_product_edit($chatId,$userId,$productId,$messageId);return;}
        if($action==='category'){
            bale_product_bot_session_save($chatId,$userId,'product_edit_category',['product_id'=>$productId,'menu_message_id'=>$messageId]);
            bale_product_bot_edit_inline_list($chatId,$messageId,'ec',1);bale_product_bot_answer_callback($callbackId,'دسته را انتخاب کنید.');return;
        }
        if($action==='brand'){
            bale_product_bot_session_save($chatId,$userId,'product_edit_brand',['product_id'=>$productId,'menu_message_id'=>$messageId]);
            bale_product_bot_edit_inline_list($chatId,$messageId,'eb',1);bale_product_bot_answer_callback($callbackId,'برند را انتخاب کنید.');return;
        }
        if($action==='attributes'){
            $selectedIds=product_selected_attribute_value_ids($productId);
            $lockedIds=product_used_attribute_value_ids($productId);
            $selectedIds=normalize_product_attribute_value_ids(array_merge($selectedIds,$lockedIds));
            $activeRows=product_attribute_value_rows(db(),$selectedIds,true);
            $activeIds=array_values(array_unique(array_map(static fn(array $row):int=>(int)$row['value_id'],$activeRows)));
            $inactiveLocked=array_values(array_diff($lockedIds,$activeIds));
            if($inactiveLocked){bale_product_bot_answer_callback($callbackId,'یکی از ویژگی‌های مدل‌های محصول غیرفعال است؛ ابتدا آن را در پنل فعال کنید.');return;}
            $state=[
                'attribute_context'=>'edit',
                'product_id'=>$productId,
                'menu_message_id'=>$messageId,
                'attribute_message_id'=>$messageId,
                'attribute_groups_page'=>1,
                'attribute_value_ids'=>$activeIds,
                'attribute_locked_value_ids'=>$lockedIds,
            ];
            bale_product_bot_open_attribute_groups($chatId,$userId,$state,1,$messageId);
            bale_product_bot_answer_callback($callbackId,'رنگ‌ها و ویژگی‌ها باز شد.');
            return;
        }
        $prompts=[
            'title'=>"نام جدید محصول را بفرستید.\nنام باید بین ۳ تا ۲۵۵ کاراکتر باشد.",
            'price'=>"قیمت جدید را به تومان و فقط به‌صورت عدد بفرستید.\nمثال: 2850000",
            'stock'=>"موجودی جدید را بین صفر تا ۱٬۰۰۰٬۰۰۰ بفرستید.",
            'description'=>"توضیحات جدید محصول را بفرستید.\nبرای خالی‌کردن توضیحات فقط - بفرستید.",
            'features'=>"ویژگی‌ها و مشخصات جدید را هر مورد در یک خط بفرستید.\nهر خط «عنوان: مقدار» مشخصه فنی است و خط معمولی ویژگی متنی.\nبرای حذف همه موارد فقط - بفرستید.",
            'warranty'=>"مدت گارانتی را به ماه و بین ۰ تا ۱۲۰ بفرستید.\nعدد 0 یعنی بدون گارانتی.",
            'fprice'=>"قیمت جشنواره جدید را به تومان بفرستید.\nبرای حذف قیمت جشنواره عدد 0 را بفرستید.",
        ];
        if(isset($prompts[$action])){
            bale_product_bot_product_edit_prompt($chatId,$userId,$productId,$messageId,'product_edit_'.$action,$prompts[$action]);bale_product_bot_answer_callback($callbackId,'مقدار جدید را بفرستید.');return;
        }
        try{
            if($action==='auth'){$value=empty($product['authenticity_guarantee'])?1:0;db()->prepare('UPDATE products SET authenticity_guarantee=? WHERE id=?')->execute([$value,$productId]);$notice='✅ تضمین اصالت '.($value?'فعال':'غیرفعال').' شد.';}
            elseif($action==='safe'){$value=empty($product['safe_shipping_guarantee'])?1:0;db()->prepare('UPDATE products SET safe_shipping_guarantee=? WHERE id=?')->execute([$value,$productId]);$notice='✅ تضمین ارسال سالم '.($value?'فعال':'غیرفعال').' شد.';}
            elseif($action==='ffree'){$value=empty($product['festival_free_shipping'])?1:0;if($value&&torobpay_special_live_campaign_for_product($productId))throw new RuntimeException('ابتدا تخفیف محدود این محصول را لغو کنید.');$enabled=$value||((int)($product['festival_price']??0)>0)?1:0;db()->prepare('UPDATE products SET festival_free_shipping=?,festival_enabled=? WHERE id=?')->execute([$value,$enabled,$productId]);$notice='✅ ارسال رایگان جشنواره '.($value?'فعال':'غیرفعال').' شد.';}
            elseif($action==='status'){$value=(string)$product['status']==='active'?'draft':'active';if($value!=='active'&&torobpay_special_live_campaign_for_product($productId))throw new RuntimeException('ابتدا تخفیف محدود این محصول را لغو کنید.');if($value==='active')bale_product_bot_product_can_activate($product);db()->prepare('UPDATE products SET status=? WHERE id=?')->execute([$value,$productId]);$notice='✅ وضعیت محصول به '.($value==='active'?'فعال':'پیش‌نویس').' تغییر کرد.';}
            else{bale_product_bot_answer_callback($callbackId,'عملیات معتبر نیست.');return;}
            bale_product_bot_product_edit_audit($productId,$userId,$action,$value);bale_product_bot_answer_callback($callbackId,'ذخیره شد.');bale_product_bot_show_product_edit($chatId,$userId,$productId,$messageId,$notice);
        }catch(Throwable $error){log_error($error);bale_product_bot_answer_callback($callbackId,text_substr($error->getMessage(),0,170));}
        return;
    }

    if (str_starts_with($dataValue,'price:')) {
        if (!$session || $session['step']!=='price_panel') { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $state = $session['data'];
        $action = substr($dataValue,6);
        if ($action==='main') {
            $state['price_message_id']=$messageId;
            bale_product_bot_session_save($chatId,$userId,'price_main_input',$state);
            bale_product_bot_answer_callback($callbackId,'قیمت اصلی را بفرستید.');
            bale_product_bot_send($chatId,"قیمت اصلی را به تومان و فقط به‌صورت عدد بفرستید.\nمثال: 2850000",bale_product_bot_cancel_keyboard());
            return;
        }
        if ($action==='festival') {
            if (max(0,(int)($state['price']??0))<1) { bale_product_bot_answer_callback($callbackId,'ابتدا قیمت اصلی را ثبت کنید.'); return; }
            $state['price_message_id']=$messageId;
            bale_product_bot_session_save($chatId,$userId,'price_festival_input',$state);
            bale_product_bot_answer_callback($callbackId,'قیمت جشنواره را بفرستید.');
            bale_product_bot_send($chatId,"قیمت جشنواره را به تومان بفرستید؛ باید کمتر از قیمت اصلی باشد.\nبرای حذف قیمت جشنواره عدد 0 را بفرستید.",bale_product_bot_cancel_keyboard());
            return;
        }
        if ($action==='free') {
            $state['festival_free_shipping']=empty($state['festival_free_shipping']);
            $state['festival_enabled']=!empty($state['festival_free_shipping']) || max(0,(int)($state['festival_price']??0))>0;
            $state['price_message_id']=$messageId;
            bale_product_bot_session_save($chatId,$userId,'price_panel',$state);
            bale_product_bot_edit($chatId,$messageId,bale_product_bot_price_text($state),bale_product_bot_price_markup($state));
            bale_product_bot_answer_callback($callbackId,'ارسال رایگان تغییر کرد.');
            return;
        }
        if ($action==='clear') {
            $state['festival_price']=null;
            $state['festival_enabled']=!empty($state['festival_free_shipping']);
            $state['price_message_id']=$messageId;
            bale_product_bot_session_save($chatId,$userId,'price_panel',$state);
            bale_product_bot_edit($chatId,$messageId,bale_product_bot_price_text($state),bale_product_bot_price_markup($state));
            bale_product_bot_answer_callback($callbackId,'قیمت جشنواره حذف شد.');
            return;
        }
        if ($action==='done') {
            $price=max(0,(int)($state['price']??0));
            $festivalPrice=isset($state['festival_price'])&&$state['festival_price']!==null?max(0,(int)$state['festival_price']):0;
            if ($price<1) { bale_product_bot_answer_callback($callbackId,'قیمت اصلی هنوز ثبت نشده است.'); return; }
            if ($festivalPrice>0 && $festivalPrice >= $price) { bale_product_bot_answer_callback($callbackId,'قیمت جشنواره باید کمتر از قیمت اصلی باشد.'); return; }
            $state['festival_enabled']=$festivalPrice>0 || !empty($state['festival_free_shipping']);
            bale_product_bot_session_save($chatId,$userId,'stock',$state);
            try { bale_product_bot_edit($chatId,$messageId,bale_product_bot_price_text($state)."\n\n✅ قیمت‌ها ثبت شدند."); } catch(Throwable $error) { log_error($error); }
            bale_product_bot_answer_callback($callbackId,'قیمت‌ها ثبت شدند.');
            bale_product_bot_send($chatId,"موجودی قابل فروش را بفرستید.\nمثال: 5",bale_product_bot_cancel_keyboard());
            return;
        }
    }

    if (preg_match('/^pick:dc:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step'] !== 'category_delete_select') { bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.'); return; }
        $category = bale_product_bot_resolve_category((string)$match[1]);
        if (!$category || $category['slug']==='festival') { bale_product_bot_answer_callback($callbackId,'این دسته قابل حذف نیست.'); return; }
        bale_product_bot_session_save($chatId,$userId,'category_delete_confirm',['category_id'=>$category['id'],'category_name'=>$category['name']]);
        $text = "⚠️ حذف دسته‌بندی برگشت‌پذیر نیست.\nنام: {$category['name']}\nشناسه: ".fa_number($category['id']);
        if ($category['product_count']>0) $text .= "\nمحصولات داخل دسته: ".fa_number($category['product_count'])."\nاین محصولات خودکار به دسته «بدون دسته‌بندی» منتقل می‌شوند.";
        $text .= "\n\nحذف را تأیید می‌کنید؟";
        bale_product_bot_edit($chatId,$messageId,$text,bale_product_bot_inline_keyboard([[[ 'text'=>'✅ بله، حذف شود','callback_data'=>'cd:'.$category['id'] ],[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]]]));
        bale_product_bot_answer_callback($callbackId,'دسته انتخاب شد.');
        return;
    }

    if (preg_match('/^pick:dp:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step'] !== 'product_delete_select') { bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.'); return; }
        $products = bale_product_bot_find_products((string)$match[1]);
        if (!$products) { bale_product_bot_answer_callback($callbackId,'محصول پیدا نشد.'); return; }
        $product = $products[0];
        bale_product_bot_session_save($chatId,$userId,'product_delete_confirm',['product_id'=>$product['id'],'product_title'=>$product['title']]);
        $text = "⚠️ حذف محصول برگشت‌پذیر نیست.\nمحصول: {$product['title']}\nکد: {$product['sku']}\nشناسه: ".fa_number($product['id'])."\n\nاگر محصول سابقه مالی یا انبار داشته باشد حذف نخواهد شد.";
        bale_product_bot_edit($chatId,$messageId,$text,bale_product_bot_inline_keyboard([[[ 'text'=>'✅ بله، حذف شود','callback_data'=>'pd:'.$product['id'] ],[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]]]));
        bale_product_bot_answer_callback($callbackId,'محصول انتخاب شد.');
        return;
    }


    if (preg_match('/^pick:o:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step']!=='order_list') { bale_product_bot_answer_callback($callbackId,'این فهرست منقضی شده است.'); return; }
        bale_product_bot_answer_callback($callbackId);
        bale_product_bot_show_order($chatId,(int)$match[1],$messageId);
        return;
    }

    if (preg_match('/^ord:list:(\d+)$/',$dataValue,$match)) {
        if ($session && $session['step']==='order_shipping_tracking') bale_product_bot_session_delete($chatId);
        if (!bale_product_bot_order_management_enabled()) { bale_product_bot_answer_callback($callbackId,'مدیریت سفارش از ربات غیرفعال است.'); return; }
        bale_product_bot_session_save($chatId,$userId,'order_list',[]);
        bale_product_bot_edit_inline_list($chatId,$messageId,'o',max(1,(int)$match[1]));
        bale_product_bot_answer_callback($callbackId);
        return;
    }

    if (preg_match('/^ord:(?:view|back):(\d+)$/',$dataValue,$match)) {
        if ($session && $session['step']==='order_shipping_tracking') bale_product_bot_session_delete($chatId);
        bale_product_bot_answer_callback($callbackId);
        bale_product_bot_show_order($chatId,(int)$match[1],$messageId);
        return;
    }

    if (preg_match('/^ord:info:(\d+)$/',$dataValue,$match)) {
        $orderId=(int)$match[1];
        if(!bale_product_bot_order_session_matches($session,$orderId,'order_view',$messageId)){bale_product_bot_answer_callback($callbackId,'این دکمه منقضی شده است؛ ابتدا «بروزرسانی» سفارش را بزنید.');return;}
        $order = bale_product_bot_order($orderId);
        if (!$order) { bale_product_bot_answer_callback($callbackId,'سفارش پیدا نشد.'); return; }
        $text = "📍 اطلاعات مشتری و تحویل\n\n";
        $text .= 'سفارش: '.bale_product_bot_clean_line((string)$order['order_number'],40)."\n";
        $text .= 'نام: '.bale_product_bot_clean_line((string)$order['customer_name'],160)."\n";
        $text .= 'موبایل: '.bale_product_bot_clean_line((string)$order['phone'],30)."\n";
        $text .= 'استان: '.bale_product_bot_clean_line((string)$order['province'],80)."\n";
        $text .= 'شهر: '.bale_product_bot_clean_line((string)$order['city'],100)."\n";
        $text .= 'کدپستی: '.bale_product_bot_clean_line((string)$order['postal_code'],30)."\n";
        $text .= 'آدرس: '.bale_product_bot_clean_line((string)$order['address'],1000);
        bale_product_bot_edit($chatId,$messageId,$text,bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ برگشت به سفارش','callback_data'=>'ord:back:'.(int)$order['id'] ]]]));
        bale_product_bot_answer_callback($callbackId);
        return;
    }

    if (preg_match('/^ord:status:(\d+):([a-z_]+)$/',$dataValue,$match)) {
        if (!bale_product_bot_order_management_enabled()) { bale_product_bot_answer_callback($callbackId,'مدیریت سفارش از ربات غیرفعال است.'); return; }
        $orderId=(int)$match[1];
        if(!bale_product_bot_order_session_matches($session,$orderId,'order_view',$messageId)){bale_product_bot_answer_callback($callbackId,'این دکمه منقضی شده است؛ ابتدا سفارش را بروزرسانی کنید.');return;}
        $order = bale_product_bot_order($orderId);
        $target = (string)$match[2];
        if (!$order || !isset(order_status_options()[$target]) || !order_status_transition_allowed((string)$order['order_status'],$target)) { bale_product_bot_answer_callback($callbackId,'این تغییر وضعیت دیگر معتبر نیست.'); return; }
        $smsNote=(string)($order['payment_status']??'')==='paid'?'پیامک وضعیت برای مشتری ارسال می‌شود.':'پرداخت هنوز کامل نشده است؛ وضعیت ذخیره می‌شود اما پیامکی برای مشتری ارسال نخواهد شد.';
        $text = "⚠️ تأیید تغییر وضعیت سفارش\n\nسفارش: ".bale_product_bot_clean_line((string)$order['order_number'],40)."\nوضعیت فعلی: ".order_status_label((string)$order['order_status'])."\nوضعیت جدید: ".order_status_label($target)."\n\n".$smsNote;
        bale_product_bot_session_save($chatId,$userId,'order_status_confirm',['order_id'=>$orderId,'target'=>$target,'order_message_id'=>$messageId]);
        bale_product_bot_edit($chatId,$messageId,$text,bale_product_bot_inline_keyboard([[[ 'text'=>'✅ تأیید تغییر','callback_data'=>'ord:apply:'.(int)$order['id'].':'.$target ],[ 'text'=>'⬅️ انصراف','callback_data'=>'ord:back:'.(int)$order['id'] ]]]));
        bale_product_bot_answer_callback($callbackId,'تأیید نهایی لازم است.');
        return;
    }

    if (preg_match('/^ord:apply:(\d+):([a-z_]+)$/',$dataValue,$match)) {
        if (!bale_product_bot_order_management_enabled()) { bale_product_bot_answer_callback($callbackId,'مدیریت سفارش از ربات غیرفعال است.'); return; }
        $orderId=(int)$match[1];$target=(string)$match[2];
        if(!bale_product_bot_order_session_matches($session,$orderId,'order_status_confirm',$messageId) || (string)($session['data']['target']??'')!==$target){bale_product_bot_answer_callback($callbackId,'تأیید تغییر وضعیت منقضی شده است؛ سفارش را دوباره باز کنید.');return;}
        $order = bale_product_bot_order($orderId);
        if (!$order || !isset(order_status_options()[$target]) || !order_status_transition_allowed((string)$order['order_status'],$target)) { bale_product_bot_answer_callback($callbackId,'وضعیت سفارش تغییر کرده؛ صفحه را تازه کنید.'); return; }
        try {
            $statusResult=admin_update_order_status((int)$order['id'],$target,(string)$order['payment_status'],'تغییر وضعیت از ربات بله توسط شناسه '.$userId,true,(string)($order['tracking_code']??''));
            admin_audit_event('bale_order_status_changed','order',(int)$order['id'],['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'new_status'=>$target]);
            $smsOutcome=is_array($statusResult['order_sms']??null)?$statusResult['order_sms']:null;
            $headline='✅ وضعیت سفارش با موفقیت به‌روزرسانی شد';
            if(is_array($smsOutcome)&&!empty($smsOutcome['skipped']))$headline.="\nℹ️ ".bale_product_bot_clean_line((string)($smsOutcome['message']??'پیامک ارسال نشد.'),220);
            bale_product_bot_answer_callback($callbackId,'وضعیت سفارش تغییر کرد.');
            bale_product_bot_show_order($chatId,(int)$order['id'],$messageId,$headline);
        } catch (Throwable $error) {
            log_error($error);
            bale_product_bot_answer_callback($callbackId,'تغییر انجام نشد.');
            bale_product_bot_edit($chatId,$messageId,'❌ تغییر وضعیت انجام نشد: '.text_substr($error->getMessage(),0,500),bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ برگشت','callback_data'=>'ord:back:'.(int)$order['id'] ]]]));
        }
        return;
    }

    if (preg_match('/^ord:ship:(\d+)$/',$dataValue,$match)) {
        if (!bale_product_bot_order_management_enabled()) { bale_product_bot_answer_callback($callbackId,'مدیریت سفارش از ربات غیرفعال است.'); return; }
        $orderId=(int)$match[1];
        if(!bale_product_bot_order_session_matches($session,$orderId,'order_view',$messageId)){bale_product_bot_answer_callback($callbackId,'این دکمه ارسال منقضی شده است؛ ابتدا سفارش را بروزرسانی کنید.');return;}
        $order = bale_product_bot_order($orderId);
        if (!$order || !in_array((string)$order['order_status'],['confirmed','preparing','ready_to_ship','handed_to_carrier','shipped'],true)) { bale_product_bot_answer_callback($callbackId,'ابتدا سفارش را تأیید کنید.'); return; }
        bale_product_bot_session_save($chatId,$userId,'order_shipping_tracking',['order_id'=>(int)$order['id'],'carrier_id'=>(int)($order['carrier_id']??0),'carrier_name'=>(string)($order['carrier_name']??''),'order_message_id'=>$messageId]);
        $currentTracking=trim((string)($order['shipment_tracking_code']??$order['tracking_code']??''));
        $prompt="🚚 کد رهگیری سفارش ".bale_product_bot_clean_line((string)$order['order_number'],40)." را در پیام بعدی بفرستید.\n\nکد رهگیری اجباری است و پس از ثبت، برای مشتری پیامک می‌شود.";
        if($currentTracking!=='')$prompt.="\nکد فعلی: ".bale_product_bot_clean_line($currentTracking,150);
        if((string)($order['payment_status']??'')!=='paid')$prompt.="\n\n⚠️ پرداخت هنوز کامل نشده است؛ ارسال ثبت می‌شود اما پیامک تا تکمیل پرداخت متوقف می‌ماند.";
        bale_product_bot_edit($chatId,$messageId,$prompt,bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ برگشت','callback_data'=>'ord:back:'.(int)$order['id'] ]]]));
        bale_product_bot_answer_callback($callbackId,'کد رهگیری را بفرستید.');
        return;
    }

    if (preg_match('/^ord:carrier:(\d+):(\d+)$/',$dataValue,$match)) {
        if (!bale_product_bot_order_management_enabled()) { bale_product_bot_answer_callback($callbackId,'مدیریت سفارش از ربات غیرفعال است.'); return; }
        $orderId=(int)$match[1];
        if(!bale_product_bot_order_session_matches($session,$orderId,'order_shipping_carrier',$messageId)){bale_product_bot_answer_callback($callbackId,'انتخاب شرکت حمل منقضی شده است؛ دوباره از سفارش شروع کنید.');return;}
        $order = bale_product_bot_order($orderId);
        $carrier = db()->prepare('SELECT id,name FROM shipping_carriers WHERE id=? AND is_active=1 LIMIT 1');
        $carrier->execute([(int)$match[2]]);$carrierRow=$carrier->fetch();
        if (!$order || !$carrierRow || !in_array((string)$order['order_status'],['confirmed','preparing','ready_to_ship','handed_to_carrier','shipped'],true)) { bale_product_bot_answer_callback($callbackId,'سفارش یا شرکت حمل معتبر نیست.'); return; }
        bale_product_bot_session_save($chatId,$userId,'order_shipping_tracking',['order_id'=>(int)$order['id'],'carrier_id'=>(int)$carrierRow['id'],'carrier_name'=>(string)$carrierRow['name'],'order_message_id'=>$messageId]);
        bale_product_bot_edit($chatId,$messageId,"🚚 شرکت حمل انتخاب شد: ".(string)$carrierRow['name']."\n\nکد رهگیری را در پیام بعدی بفرستید. کد رهگیری برای ثبت ارسال الزامی است.",bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ برگشت','callback_data'=>'ord:back:'.(int)$order['id'] ]]]));
        bale_product_bot_answer_callback($callbackId,'کد رهگیری را بفرستید.');
        return;
    }

    if (str_starts_with($dataValue,'opt:')) {
        if (!$session || $session['step']!=='product_options') { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $state = $session['data'];
        $action = substr($dataValue,4);
        if (preg_match('/^w:(0|6|12|18|24|36)$/',$action,$match)) {
            $state['warranty_months']=(int)$match[1];
            bale_product_bot_session_save($chatId,$userId,'product_options',$state);
            bale_product_bot_edit($chatId,$messageId,bale_product_bot_options_text($state),bale_product_bot_options_markup($state));
            bale_product_bot_answer_callback($callbackId,'گارانتی تغییر کرد.');
            return;
        }
        if ($action==='w:custom') {
            $state['options_message_id']=$messageId;
            bale_product_bot_session_save($chatId,$userId,'warranty_input',$state);
            bale_product_bot_answer_callback($callbackId,'مدت گارانتی را بفرستید.');
            bale_product_bot_send($chatId,'مدت گارانتی را به ماه و بین ۱ تا ۱۲۰ بفرستید. برای بدون گارانتی عدد 0 را ارسال کنید.',bale_product_bot_cancel_keyboard());
            return;
        }
        if ($action==='a') {
            $state['authenticity_guarantee']=empty($state['authenticity_guarantee']);
            bale_product_bot_session_save($chatId,$userId,'product_options',$state);
            bale_product_bot_edit($chatId,$messageId,bale_product_bot_options_text($state),bale_product_bot_options_markup($state));
            bale_product_bot_answer_callback($callbackId,'تضمین اصالت تغییر کرد.');
            return;
        }
        if ($action==='s') {
            $state['safe_shipping_guarantee']=empty($state['safe_shipping_guarantee']);
            bale_product_bot_session_save($chatId,$userId,'product_options',$state);
            bale_product_bot_edit($chatId,$messageId,bale_product_bot_options_text($state),bale_product_bot_options_markup($state));
            bale_product_bot_answer_callback($callbackId,'تضمین ارسال سالم تغییر کرد.');
            return;
        }
        if ($action==='home') {
            if (!configured_homepage_festival()) { bale_product_bot_answer_callback($callbackId,'جشنواره صفحه اول تنظیم نشده است.'); return; }
            $state['home_festival']=empty($state['home_festival']);
            bale_product_bot_session_save($chatId,$userId,'product_options',$state);
            bale_product_bot_edit($chatId,$messageId,bale_product_bot_options_text($state),bale_product_bot_options_markup($state));
            bale_product_bot_answer_callback($callbackId,'جشنواره صفحه اول تغییر کرد.');
            return;
        }
        if ($action==='attributes') {
            $state['attribute_context']='create';
            $state['options_message_id']=$messageId;
            $state['attribute_message_id']=$messageId;
            $state['attribute_groups_page']=1;
            bale_product_bot_open_attribute_groups($chatId,$userId,$state,1,$messageId);
            bale_product_bot_answer_callback($callbackId,'ویژگی‌ها باز شد.');
            return;
        }
        if ($action==='done') {
            $state['images']=is_array($state['images']??null)?$state['images']:[];
            bale_product_bot_session_save($chatId,$userId,'images',$state);
            try { bale_product_bot_edit($chatId,$messageId,bale_product_bot_options_text($state)."\n\n✅ تنظیمات ثبت شد."); } catch (Throwable $error) { log_error($error); }
            bale_product_bot_answer_callback($callbackId,'ثبت شد.');
            bale_product_bot_send($chatId,'تصاویر محصول را یکی‌یکی بفرستید. عکس اول تصویر اصلی خواهد بود و هر عکس خودکار بدون برش یا کشیدگی به WebP سفید ۱۲۰۰×۱۲۰۰ آماده ترب تبدیل می‌شود. ضلع کوتاه فایل اولیه باید حداقل ۳۰۰ پیکسل باشد. پس از پایان، «پایان تصاویر» را بزنید. بدون عکس هم می‌توانید ادامه دهید.',bale_product_bot_images_keyboard());
            return;
        }
    }

    if ($dataValue==='attr:new') {
        if (!bale_product_bot_attribute_session_matches($session,$messageId,['attribute_groups','attribute_values'])) { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $state=$session['data'];
        $returnPage=max(1,(int)($state['attribute_groups_page']??1));
        $state['attribute_message_id']=$messageId;
        unset($state['attribute_new_name'],$state['attribute_id']);
        $state['attribute_message_id']=bale_product_bot_attribute_screen_message(
            $chatId,$messageId,
            "نام ویژگی جدید را بفرستید.\nمثال: رنگ، ظرفیت یا مدل",
            bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ بازگشت','callback_data'=>'attr:groups:'.$returnPage ]]])
        );
        bale_product_bot_session_save($chatId,$userId,'attribute_new_name',$state);
        bale_product_bot_answer_callback($callbackId,'نام ویژگی را بفرستید.');
        return;
    }

    if (preg_match('/^attr:newtype:(b|c|s)$/',$dataValue,$match)) {
        if (!bale_product_bot_attribute_session_matches($session,$messageId,['attribute_new_type'])) { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $state=$session['data'];
        $name=trim((string)($state['attribute_new_name']??''));
        $displayType=['b'=>'button','c'=>'color','s'=>'select'][(string)$match[1]]??'';
        try {
            $created=bale_product_bot_create_attribute($name,$displayType,$userId);
            unset($state['attribute_new_name']);
            bale_product_bot_open_attribute_values($chatId,$userId,$state,(int)$created['id'],1,$messageId);
            $notice=$created['created']?'ویژگی ساخته شد.':($created['reactivated']?'ویژگی دوباره فعال شد.':'این ویژگی از قبل وجود داشت.');
            bale_product_bot_answer_callback($callbackId,$notice.' حالا مقدارهای آن را انتخاب یا اضافه کنید.');
        } catch(Throwable $error) {
            log_error($error);
            $message=$error instanceof PDOException?'ساخت ویژگی انجام نشد؛ احتمالاً نام یا نشانی تکراری است.':$error->getMessage();
            bale_product_bot_answer_callback($callbackId,text_substr($message,0,170));
        }
        return;
    }

    if (preg_match('/^attr:addv:(\d+)$/',$dataValue,$match)) {
        if (!bale_product_bot_attribute_session_matches($session,$messageId,['attribute_groups','attribute_values'])) { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $attributeId=(int)$match[1];
        $attribute=null;
        foreach(bale_product_bot_attribute_definitions() as $candidate)if((int)($candidate['id']??0)===$attributeId){$attribute=$candidate;break;}
        if(!$attribute){bale_product_bot_answer_callback($callbackId,'این ویژگی دیگر فعال نیست.');return;}
        $state=$session['data'];
        $state['attribute_id']=$attributeId;
        $state['attribute_message_id']=$messageId;
        $returnPage=max(1,(int)($state['attribute_groups_page']??1));
        $isColor=(string)($attribute['display_type']??'')==='color';
        $prompt=$isColor
            ? "نام رنگ جدید را بفرستید.\nنمونه: سفید | #FFFFFF\nکد رنگ اختیاری است، اما اگر می‌فرستید باید شش‌رقمی باشد."
            : "مقدار جدید «".(string)$attribute['name']."» را بفرستید.\nمثال: ۸ لیتر";
        $state['attribute_message_id']=bale_product_bot_attribute_screen_message(
            $chatId,$messageId,$prompt,
            bale_product_bot_inline_keyboard([[[ 'text'=>'⬅️ بازگشت به مقدارها','callback_data'=>'attr:group:'.$attributeId.':'.$returnPage.':1' ]]])
        );
        bale_product_bot_session_save($chatId,$userId,'attribute_new_value',$state);
        bale_product_bot_answer_callback($callbackId,$isColor?'نام رنگ را بفرستید.':'مقدار جدید را بفرستید.');
        return;
    }

    if (preg_match('/^attr:groups:(\d+)$/',$dataValue,$match)) {
        if (!bale_product_bot_attribute_session_matches($session,$messageId)) { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $state=$session['data'];
        $page=max(1,(int)$match[1]);
        bale_product_bot_open_attribute_groups($chatId,$userId,$state,$page,$messageId);
        bale_product_bot_answer_callback($callbackId);
        return;
    }

    if (preg_match('/^attr:group:(\d+):(\d+):(\d+)$/',$dataValue,$match)) {
        if (!bale_product_bot_attribute_session_matches($session,$messageId)) { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $state=$session['data'];
        $state['attribute_groups_page']=max(1,(int)$match[2]);
        bale_product_bot_open_attribute_values($chatId,$userId,$state,(int)$match[1],max(1,(int)$match[3]),$messageId);
        bale_product_bot_answer_callback($callbackId);
        return;
    }

    if (preg_match('/^attr:value:(\d+):(\d+):(\d+)$/',$dataValue,$match)) {
        if (!bale_product_bot_attribute_session_matches($session,$messageId,['attribute_values'])) { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $attributeId=(int)$match[1];
        $valueId=(int)$match[2];
        $valid=db()->prepare('SELECT 1 FROM product_attribute_values pav JOIN product_attributes pa ON pa.id=pav.attribute_id WHERE pav.id=? AND pav.attribute_id=? AND pa.is_active=1 LIMIT 1');
        $valid->execute([$valueId,$attributeId]);
        if (!$valid->fetchColumn()) { bale_product_bot_answer_callback($callbackId,'این مقدار دیگر فعال نیست.'); return; }
        $state=$session['data'];
        $selected=bale_product_bot_selected_attribute_ids($state);
        $locked=normalize_product_attribute_value_ids($state['attribute_locked_value_ids']??[]);
        if (in_array($valueId,$selected,true) && in_array($valueId,$locked,true)) { bale_product_bot_answer_callback($callbackId,'این گزینه در یکی از مدل‌های محصول استفاده شده و قابل حذف نیست.'); return; }
        if (in_array($valueId,$selected,true)) $selected=array_values(array_filter($selected,static fn(int $id):bool=>$id!==$valueId));
        else $selected[]=$valueId;
        $state['attribute_value_ids']=normalize_product_attribute_value_ids($selected);
        bale_product_bot_open_attribute_values($chatId,$userId,$state,$attributeId,max(1,(int)$match[3]),$messageId);
        bale_product_bot_answer_callback($callbackId,'انتخاب به‌روزرسانی شد.');
        return;
    }

    if ($dataValue==='attr:done') {
        if (!bale_product_bot_attribute_session_matches($session,$messageId,['attribute_groups','attribute_values'])) { bale_product_bot_answer_callback($callbackId,'این مرحله منقضی شده است.'); return; }
        $state=$session['data'];
        if(bale_product_bot_attribute_context($state)==='edit'){
            $productId=max(0,(int)($state['product_id']??0));
            $selected=bale_product_bot_selected_attribute_ids($state);
            try{
                bale_product_bot_save_product_attribute_selections($productId,$selected,$userId);
                bale_product_bot_answer_callback($callbackId,'رنگ‌ها و ویژگی‌ها ذخیره شدند.');
                bale_product_bot_show_product_edit($chatId,$userId,$productId,$messageId,'✅ رنگ‌ها و ویژگی‌های قابل انتخاب ذخیره شدند.');
            }catch(Throwable $error){
                log_error($error);
                $message=$error instanceof PDOException?'ذخیره ویژگی‌ها به‌دلیل خطای پایگاه داده انجام نشد.':$error->getMessage();
                bale_product_bot_answer_callback($callbackId,text_substr($message,0,170));
            }
            return;
        }
        $state['options_message_id']=$messageId;
        bale_product_bot_session_save($chatId,$userId,'product_options',$state);
        bale_product_bot_edit($chatId,$messageId,bale_product_bot_options_text($state),bale_product_bot_options_markup($state));
        bale_product_bot_answer_callback($callbackId,'ویژگی‌ها ثبت شدند.');
        return;
    }

    if (preg_match('/^cd:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step']!=='category_delete_confirm' || (int)($session['data']['category_id']??0)!==(int)$match[1]) { bale_product_bot_answer_callback($callbackId,'تأیید حذف منقضی شده است.'); return; }
        $result=bale_product_bot_delete_category((int)$match[1],$userId);
        bale_product_bot_session_delete($chatId);
        bale_product_bot_answer_callback($callbackId,$result['ok']?'حذف شد.':'حذف نشد.');
        try { bale_product_bot_edit($chatId,$messageId,($result['ok']?'✅ ':'❌ ').$result['message'].($result['name']!==''?"\nدسته: ".$result['name']:'')); } catch(Throwable $error){log_error($error);}
        bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard());
        return;
    }
    if (preg_match('/^pd:(\d+)$/',$dataValue,$match)) {
        if (!$session || $session['step']!=='product_delete_confirm' || (int)($session['data']['product_id']??0)!==(int)$match[1]) { bale_product_bot_answer_callback($callbackId,'تأیید حذف منقضی شده است.'); return; }
        $result=bale_product_bot_delete_product((int)$match[1],$userId);
        bale_product_bot_session_delete($chatId);
        bale_product_bot_answer_callback($callbackId,$result['ok']?'حذف شد.':'حذف نشد.');
        try { bale_product_bot_edit($chatId,$messageId,($result['ok']?'✅ ':'❌ ').$result['message'].($result['title']!==''?"\nمحصول: ".$result['title']:'')); } catch(Throwable $error){log_error($error);}
        bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard());
        return;
    }
    bale_product_bot_answer_callback($callbackId,'دکمه معتبر نیست یا منقضی شده است.');
}

/** @param array<string,mixed> $update */
function bale_product_bot_process_update(array $update): void
{
    $updateId = max(0,(int)($update['update_id']??0));
    if (!bale_product_bot_claim_update($updateId)) return;
    if (is_array($update['callback_query']??null)) { bale_product_bot_process_callback($update['callback_query']); return; }
    $message = $update['message'] ?? $update['edited_message'] ?? null;
    if (!is_array($message)) return;
    $chat = $message['chat'] ?? [];
    $from = $message['from'] ?? [];
    $chatId=trim(scalar_string($chat['id']??''));
    $userId=trim(scalar_string($from['id']??$chatId));
    $chatType=trim(scalar_string($chat['type']??'private'));
    if ($chatId===''||$userId==='') return;
    if ($chatType!=='' && $chatType!=='private') {
        try { bale_product_bot_send($chatId,'مدیریت فروشگاه فقط در گفت‌وگوی خصوصی با ربات انجام می‌شود.'); } catch(Throwable) {}
        return;
    }
    $text=trim(scalar_string($message['text']??$message['caption']??''));
    bale_product_bot_runtime_user($userId);
    bale_product_bot_runtime_chat($chatId);

    if (!bale_product_bot_is_allowed($userId)) {
        if (preg_match('/^\/pair\s+(\d{8})$/u',$text,$match) && bale_product_bot_pair($userId,$match[1])) {
            if (function_exists('bale_dashboard_send')) bale_dashboard_send($chatId,$userId,'✅ این حساب بله به‌عنوان مدیر مجاز ثبت شد.');
            else bale_product_bot_send($chatId,'✅ این حساب بله به‌عنوان مدیر مجاز ثبت شد. اکنون می‌توانید محصولات، دسته‌ها، برندها و سفارش‌ها را مدیریت کنید.',bale_product_bot_main_keyboard());
            return;
        }
        bale_product_bot_send($chatId,"⛔ این حساب اجازه مدیریت فروشگاه ندارد.\nشناسه شما: {$userId}\nاز پنل سایت کد اتصال بسازید و سپس این دستور را بفرستید:\n/pair 12345678");
        return;
    }

    if (in_array($text,['/start','شروع','منو','/menu'],true)) {
        $session=bale_product_bot_session($chatId);
        if ($session && (string)($session['user_id']??'')!==$userId) $session=null;
        $outcome=bale_product_bot_cancel_outcome($session);
        if (!$outcome['allowed']) { bale_product_bot_send($chatId,$outcome['message']); return; }
        bale_product_bot_session_delete($chatId);
        if (function_exists('bale_dashboard_send')) bale_dashboard_send($chatId,$userId);
        else bale_product_bot_send($chatId,'ربات مدیریت فروشگاه گلریزان آماده است.',bale_product_bot_main_keyboard($userId));
        return;
    }
    if (in_array($text,['/cancel','لغو','❌ لغو','❌ لغو عملیات','🧹 لغو عملیات'],true)) {
        $session=bale_product_bot_session($chatId);
        if ($session && (string)$session['user_id']!==$userId) $session=null;
        $outcome=bale_product_bot_cancel_outcome($session);
        if ($outcome['allowed']) bale_product_bot_session_delete($chatId);
        if ($outcome['allowed'] && function_exists('bale_dashboard_send')) bale_dashboard_send($chatId,$userId,$outcome['message']);
        else bale_product_bot_send($chatId,$outcome['message'],$outcome['allowed']?bale_product_bot_main_keyboard($userId):null);
        return;
    }
    $commandCapability=bale_product_bot_command_capability($text);
    if($commandCapability!==null&&!bale_product_bot_can($userId,$commandCapability)){bale_product_bot_permission_denied($chatId,$userId,$commandCapability);return;}
    $dashboardCommand=function_exists('bale_dashboard_is_command')&&bale_dashboard_is_command($text);
    if ($dashboardCommand && function_exists('bale_dashboard_command_allowed') && !bale_dashboard_command_allowed($userId,$text)) {
        bale_product_bot_send($chatId,'⛔ دسترسی این بخش برای حساب شما فعال نیست.',bale_product_bot_main_keyboard($userId));
        return;
    }
    if (($commandCapability!==null || $dashboardCommand || $text==='🆔 شناسه من') && !bale_product_bot_prepare_menu_switch($chatId,$userId)) return;
    if ($dashboardCommand && function_exists('bale_dashboard_handle_command') && bale_dashboard_handle_command($chatId,$userId,$text)) return;
    if ($text==='🆔 شناسه من') {
        bale_product_bot_send($chatId,"🆔 شناسه مدیر بله\n━━━━━━━━━━━━━━━━━━\n{$userId}\n\nاین شناسه برای تعریف سطح دسترسی در پنل سایت استفاده می‌شود.",function_exists('bale_dashboard_section_keyboard')?bale_dashboard_section_keyboard('settings',$userId):bale_product_bot_main_keyboard($userId));
        return;
    }
    if (in_array($text,['⏳ تخفیف محدود','🟣 جشنواره ترب‌پی'],true)) { bale_torobpay_festival_start($chatId,$userId); return; }
    if (in_array($text,['📊 تغییر گروهی قیمت','📈 افزایش/کاهش درصدی'],true)) { bale_price_adjustment_start($chatId,$userId); return; }
    if (function_exists('bale_product_bot_advanced_command') && bale_product_bot_advanced_command($chatId,$userId,$text)) return;
    if (in_array($text,['💳 درصد ترب‌پی','💳 درصد افزایش ترب‌پی'],true)) { $current=torobpay_markup_label(); bale_product_bot_session_save($chatId,$userId,'torobpay_markup_input',[]); bale_product_bot_send($chatId,"💳 تنظیم افزایش قیمت ترب‌پی\n━━━━━━━━━━━━━━━━━━\nدرصد فعلی: {$current}

درصد جدید را بین ۰ تا ۱۰۰ بفرستید. مثال: 10 یا 7.5
عدد 0 یعنی بدون افزایش قیمت.",bale_product_bot_cancel_keyboard()); return; }
    if ($text==='🔗 لینک محصولات') { bale_product_bot_session_save($chatId,$userId,'product_link_select',[]); bale_product_bot_send_inline_list($chatId,'pl',1); return; }
    if ($text==='✏️ ویرایش محصول') { bale_product_bot_session_save($chatId,$userId,'product_edit_select',[]); bale_product_bot_send_inline_list($chatId,'ep',1); return; }
    if ($text==='➕ افزودن سریع محصول') { bale_product_bot_session_save($chatId,$userId,'title',[]); bale_product_bot_send($chatId,"نام کامل محصول را بفرستید.\nمثال: جاروبرقی فیلیپس مدل FC9170",bale_product_bot_cancel_keyboard()); return; }
    if ($text==='➕ افزودن دسته') { bale_product_bot_session_save($chatId,$userId,'add_category_name',[]); bale_product_bot_send($chatId,"نام دسته‌بندی جدید را بفرستید.\nمثال: پنکه و تهویه",bale_product_bot_cancel_keyboard()); return; }
    if ($text==='➕ افزودن برند') { bale_product_bot_session_save($chatId,$userId,'add_brand_name',[]); bale_product_bot_send($chatId,"نام برند جدید را بفرستید.\nمثال: پارس خزر",bale_product_bot_cancel_keyboard()); return; }
    if ($text==='🗑 حذف دسته') { bale_product_bot_session_save($chatId,$userId,'category_delete_select',[]); bale_product_bot_send_inline_list($chatId,'dc',1); return; }
    if ($text==='🗑 حذف محصول') { bale_product_bot_session_save($chatId,$userId,'product_delete_select',[]); bale_product_bot_send_inline_list($chatId,'dp',1); return; }
    if ($text==='🧾 مدیریت سفارش‌ها') { if(!bale_product_bot_order_management_enabled()){bale_product_bot_send($chatId,'مدیریت سفارش از ربات در تنظیمات غیرفعال است.',bale_product_bot_main_keyboard());return;} bale_product_bot_session_save($chatId,$userId,'order_list',[]); bale_product_bot_send_inline_list($chatId,'o',1); return; }

    $session=bale_product_bot_session($chatId);
    if ($session && (string)$session['user_id']!==$userId) $session=null;
    if (!$session) {
        if (function_exists('bale_dashboard_send')) bale_dashboard_send($chatId,$userId,'ℹ️ پیام شما به عملیات فعالی مربوط نبود.');
        else bale_product_bot_send($chatId,'یک عملیات را از منوی زیر انتخاب کنید.',bale_product_bot_main_keyboard($userId));
        return;
    }
    $step=$session['step'];
    $data=$session['data'];
    $stepCapability=bale_product_bot_step_capability($step);
    if($stepCapability!==null&&!bale_product_bot_can($userId,$stepCapability)){bale_product_bot_session_delete($chatId);bale_product_bot_permission_denied($chatId,$userId,$stepCapability);return;}

    if (function_exists('bale_torobpay_festival_message') && bale_torobpay_festival_message($chatId,$userId,$step,$data,$message,$text)) return;
    if (function_exists('bale_price_adjustment_message') && bale_price_adjustment_message($chatId,$userId,$step,$data,$message,$text)) return;
    if (function_exists('bale_product_bot_advanced_message') && bale_product_bot_advanced_message($chatId,$userId,$step,$data,$message,$text)) return;

    if ($step==='torobpay_markup_input') {
        try {
            $percent=torobpay_normalize_markup_percent($text);
            db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['torobpay_markup_percent',$percent]);
            admin_audit_event('bale_torobpay_markup_updated',null,null,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'percent'=>$percent]);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_send($chatId,'✅ درصد افزایش ترب‌پی روی '.torobpay_markup_label().' تنظیم شد. این مقدار فقط روی سفارش‌های جدید ترب‌پی اعمال می‌شود.',bale_product_bot_main_keyboard($userId));
        } catch(Throwable $error) { bale_product_bot_send($chatId,'❌ '.$error->getMessage()."
مثال معتبر: 10 یا 7.5",bale_product_bot_cancel_keyboard()); }
        return;
    }

    if ($step==='order_shipping_tracking') {
        $orderId=max(0,(int)($data['order_id']??0));
        $carrierId=max(0,(int)($data['carrier_id']??0));
        $tracking=text_substr(trim($text),0,150);
        if($orderId<1){bale_product_bot_session_delete($chatId);bale_product_bot_send($chatId,'اطلاعات ارسال منقضی شده است. دوباره از سفارش شروع کنید.',bale_product_bot_main_keyboard());return;}
        if($tracking===''||$tracking==='-'){bale_product_bot_send($chatId,'کد رهگیری الزامی است. کد معتبر مرسوله را بفرستید یا عملیات را لغو کنید.',bale_product_bot_cancel_keyboard());return;}
        try{
            $order=bale_product_bot_order($orderId);
            if(!$order||!in_array((string)$order['order_status'],['confirmed','preparing','ready_to_ship','handed_to_carrier','shipped'],true))throw new RuntimeException('سفارش دیگر در مرحله قابل ارسال نیست.');
            $shipResult=admin_mark_order_shipped($orderId,$tracking,$carrierId>0?$carrierId:null,'ثبت ارسال از ربات بله توسط شناسه '.$userId,true);
            admin_audit_event('bale_order_shipping_saved','order',$orderId,['username'=>'bale:'.$userId,'bale_user_id'=>$userId,'carrier_id'=>$carrierId?:null,'tracking_code'=>$tracking]);
            $smsOutcome=is_array($shipResult['sms']??null)?$shipResult['sms']:null;
            $headline='✅ سفارش ارسال‌شده و کد رهگیری ثبت شد';
            if(is_array($smsOutcome)){
                if(!empty($smsOutcome['ok'])&&empty($smsOutcome['skipped']))$headline.="\n📨 پیامک کد رهگیری برای مشتری ارسال شد.";
                elseif(!empty($smsOutcome['skipped']))$headline.="\nℹ️ ".bale_product_bot_clean_line((string)($smsOutcome['message']??'پیامک ارسال نشد.'),250);
                else $headline.="\n⚠️ ثبت ارسال انجام شد اما پیامک ناموفق بود: ".bale_product_bot_clean_line((string)($smsOutcome['message']??''),220);
            }
            $orderMessageId=max(0,(int)($data['order_message_id']??0));
            bale_product_bot_session_delete($chatId);
            if($orderMessageId>0)bale_product_bot_show_order($chatId,$orderId,$orderMessageId,$headline);
            else bale_product_bot_show_order($chatId,$orderId,0,$headline);
            bale_product_bot_send($chatId,'منوی مدیریت:',bale_product_bot_main_keyboard());
        }catch(Throwable $error){log_error($error);bale_product_bot_send($chatId,'❌ ثبت ارسال انجام نشد: '.text_substr($error->getMessage(),0,500),bale_product_bot_cancel_keyboard());}
        return;
    }
    if ($step==='order_list') { bale_product_bot_send($chatId,'سفارش را از دکمه‌های شیشه‌ای فهرست انتخاب کنید.'); return; }

    if ($step==='attribute_new_name') {
        $name=trim(preg_replace('/\s+/u',' ',trim($text))??trim($text));
        if(text_length($name)<2||text_length($name)>100){bale_product_bot_send($chatId,'نام ویژگی باید بین ۲ تا ۱۰۰ کاراکتر باشد. دوباره بفرستید.',bale_product_bot_cancel_keyboard());return;}
        $data['attribute_new_name']=$name;
        $returnPage=max(1,(int)($data['attribute_groups_page']??1));
        $messageId=max(0,(int)($data['attribute_message_id']??0));
        $prompt="نوع نمایش «{$name}» را انتخاب کنید.\nبرای رنگ‌هایی مانند سفید و مشکی، گزینه «رنگ» مناسب است.";
        $markup=bale_product_bot_inline_keyboard([
            [[ 'text'=>'🎨 رنگ','callback_data'=>'attr:newtype:c' ],[ 'text'=>'🔘 دکمه','callback_data'=>'attr:newtype:b' ],[ 'text'=>'📋 فهرست','callback_data'=>'attr:newtype:s' ]],
            [[ 'text'=>'⬅️ بازگشت','callback_data'=>'attr:groups:'.$returnPage ]],
        ]);
        $data['attribute_message_id']=bale_product_bot_attribute_screen_message($chatId,$messageId,$prompt,$markup);
        bale_product_bot_session_save($chatId,$userId,'attribute_new_type',$data);
        return;
    }
    if ($step==='attribute_new_value') {
        $attributeId=max(0,(int)($data['attribute_id']??0));
        try{
            $created=bale_product_bot_create_attribute_value($attributeId,$text,$userId);
            $selected=bale_product_bot_selected_attribute_ids($data);
            $selected[]=(int)$created['id'];
            $data['attribute_value_ids']=normalize_product_attribute_value_ids($selected);
            bale_product_bot_open_attribute_values(
                $chatId,$userId,$data,$attributeId,max(1,(int)($data['attribute_value_page']??1)),max(0,(int)($data['attribute_message_id']??0))
            );
            $notice=$created['created']?'✅ مقدار جدید ساخته و انتخاب شد.':($created['color_updated']?'✅ کد رنگ به‌روزرسانی و گزینه انتخاب شد.':'ℹ️ این مقدار از قبل وجود داشت و انتخاب شد.');
            bale_product_bot_send($chatId,$notice.' از دکمه‌های پیام ویژگی بالا ادامه دهید.');
        }catch(Throwable $error){
            log_error($error);
            $message=$error instanceof PDOException?'مقدار تکراری است یا ذخیره پایگاه داده انجام نشد.':$error->getMessage();
            bale_product_bot_send($chatId,'❌ افزودن مقدار انجام نشد: '.text_substr($message,0,350),bale_product_bot_cancel_keyboard());
        }
        return;
    }
    if ($step==='attribute_new_type') { bale_product_bot_send($chatId,'نوع نمایش ویژگی را از دکمه‌های شیشه‌ای پیام قبلی انتخاب کنید.'); return; }

    if ($step==='product_edit_select') {
        $products=bale_product_bot_find_products($text);
        if(!$products){bale_product_bot_send($chatId,'محصولی پیدا نشد؛ از فهرست انتخاب کنید یا شناسه، کد کالا یا بخشی از نام را بفرستید.');return;}
        if(count($products)>1){$lines=['چند محصول پیدا شد. شناسه دقیق یکی را بفرستید:'];foreach($products as $product)$lines[]=$product['id'].' — '.$product['sku'].' — '.$product['title'];bale_product_bot_send($chatId,implode("\n",$lines),bale_product_bot_cancel_keyboard());return;}
        bale_product_bot_show_product_edit($chatId,$userId,(int)$products[0]['id']);return;
    }
    if(in_array($step,['product_edit_menu','product_edit_category','product_edit_brand'],true)){bale_product_bot_send($chatId,'برای ویرایش از دکمه‌های شیشه‌ای پیام محصول استفاده کنید.');return;}
    if(str_starts_with($step,'product_edit_')){
        $productId=max(0,(int)($data['product_id']??0));$menuMessageId=max(0,(int)($data['menu_message_id']??0));$product=bale_product_bot_product_for_edit($productId);
        if(!$product){bale_product_bot_session_delete($chatId);bale_product_bot_send($chatId,'محصول پیدا نشد یا حذف شده است.',bale_product_bot_main_keyboard());return;}
        try{
            $field=substr($step,13);$notice='✅ تغییرات ذخیره شد.';$value='';
            if($field==='title'){
                if(text_length($text)<3||text_length($text)>255)throw new RuntimeException('نام محصول باید بین ۳ تا ۲۵۵ کاراکتر باشد.');
                db()->prepare('UPDATE products SET title=?,short_title=? WHERE id=?')->execute([$text,text_substr($text,0,180),$productId]);$value=$text;$notice='✅ نام محصول تغییر کرد.';
            }elseif($field==='price'){
                $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';if($number===''||strlen($number)>15)throw new RuntimeException('قیمت معتبر نیست.');$value=(int)$number;if($value>MAX_MONEY_AMOUNT)throw new RuntimeException('قیمت از محدوده مجاز بیشتر است.');if((string)$product['status']==='active'&&(string)($product['product_type']??'simple')==='simple'&&$value<1)throw new RuntimeException('قیمت محصول فعال باید بیشتر از صفر باشد.');
                if($value!==(int)$product['price']&&torobpay_special_live_campaign_for_product($productId))throw new RuntimeException('تا پایان یا لغو تخفیف محدود، قیمت اصلی این محصول قفل است.');
                $festival=(int)($product['festival_price']??0);if($festival>0&&$festival>=$value)throw new RuntimeException('قیمت جدید باید بیشتر از قیمت جشنواره فعلی باشد یا ابتدا قیمت جشنواره را حذف کنید.');
                db()->prepare('UPDATE products SET price=? WHERE id=?')->execute([$value,$productId]);$notice='✅ قیمت محصول تغییر کرد.';
            }elseif($field==='stock'){
                $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';if($number===''||strlen($number)>7||(int)$number>1000000)throw new RuntimeException('موجودی باید بین صفر تا ۱٬۰۰۰٬۰۰۰ باشد.');$value=(int)$number;db()->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$value,$productId]);$notice='✅ موجودی محصول تغییر کرد.';
            }elseif($field==='description'){
                $value=in_array($text,['-','⏭ بدون توضیحات','خالی'],true)?'':trim($text);if(text_length($value)>30000)throw new RuntimeException('توضیحات محصول بیش از حد طولانی است.');db()->prepare('UPDATE products SET description=? WHERE id=?')->execute([$value!==''?$value:null,$productId]);$notice=$value!==''?'✅ توضیحات محصول تغییر کرد.':'✅ توضیحات محصول خالی شد.';
            }elseif($field==='warranty'){
                $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';if($number===''||strlen($number)>3||(int)$number>120)throw new RuntimeException('مدت گارانتی باید بین صفر تا ۱۲۰ ماه باشد.');$value=(int)$number;$warranty=$value>0?$value.' ماه گارانتی':null;db()->prepare('UPDATE products SET warranty=? WHERE id=?')->execute([$warranty,$productId]);$notice='✅ گارانتی محصول تغییر کرد.';
            }elseif($field==='fprice'){
                $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';if($number===''||strlen($number)>15)throw new RuntimeException('قیمت جشنواره معتبر نیست.');$value=(int)$number;if($value>0&&($value>MAX_MONEY_AMOUNT||$value>=(int)$product['price']))throw new RuntimeException('قیمت جشنواره باید بیشتر از صفر و کمتر از قیمت اصلی باشد.');if($value>0&&torobpay_special_live_campaign_for_product($productId))throw new RuntimeException('ابتدا تخفیف محدود این محصول را لغو کنید.');$enabled=$value>0||!empty($product['festival_free_shipping'])?1:0;db()->prepare('UPDATE products SET festival_price=?,festival_enabled=? WHERE id=?')->execute([$value>0?$value:null,$enabled,$productId]);$notice=$value>0?'✅ قیمت جشنواره تغییر کرد.':'✅ قیمت جشنواره حذف شد.';
            }elseif($field==='features'){
                $merged=merge_product_feature_lines_into_specifications($text==='-'?'':$text,[]);$pdo=db();$pdo->beginTransaction();try{$pdo->prepare('DELETE FROM product_features WHERE product_id=?')->execute([$productId]);if($merged['features']){$insert=$pdo->prepare('INSERT INTO product_features(product_id,feature_text,sort_order) VALUES(?,?,?)');foreach($merged['features'] as $index=>$feature)$insert->execute([$productId,$feature,$index]);}replace_product_specifications($pdo,$productId,$merged['specifications']);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}$value='updated';$notice='✅ ویژگی‌ها و مشخصات فنی جایگزین شدند.';
            }else throw new RuntimeException('مرحله ویرایش معتبر نیست.');
            bale_product_bot_product_edit_audit($productId,$userId,$field,$value);bale_product_bot_show_product_edit($chatId,$userId,$productId,$menuMessageId,$notice);
        }catch(Throwable $error){log_error($error);bale_product_bot_send($chatId,'❌ ذخیره تغییرات انجام نشد: '.text_substr($error->getMessage(),0,500),bale_product_bot_cancel_keyboard());}
        return;
    }

    if ($step==='add_category_name') {
        try {
            $created=bale_product_bot_create_taxonomy('category',$text,$userId);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_send($chatId,($created['created']?'✅ دسته جدید ساخته شد.':'ℹ️ این دسته از قبل وجود داشت.')."\nنام: {$created['name']}\nشناسه: ".fa_number($created['id']),bale_product_bot_main_keyboard());
        } catch(Throwable $error) { bale_product_bot_send($chatId,'❌ ساخت دسته انجام نشد: '.text_substr($error->getMessage(),0,350),bale_product_bot_cancel_keyboard()); }
        return;
    }
    if ($step==='add_brand_name') {
        try {
            $created=bale_product_bot_create_taxonomy('brand',$text,$userId);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_send($chatId,($created['created']?'✅ برند جدید ساخته شد.':'ℹ️ این برند از قبل وجود داشت.')."\nنام: {$created['name']}\nشناسه: ".fa_number($created['id']),bale_product_bot_main_keyboard());
        } catch(Throwable $error) { bale_product_bot_send($chatId,'❌ ساخت برند انجام نشد: '.text_substr($error->getMessage(),0,350),bale_product_bot_cancel_keyboard()); }
        return;
    }
    if ($step==='category_delete_select') {
        $category=bale_product_bot_resolve_category($text);
        if(!$category){bale_product_bot_send($chatId,'دسته پیدا نشد؛ شناسه یا نام دقیق را دوباره بفرستید.');return;}
        if($category['slug']==='festival'){bale_product_bot_send($chatId,'دسته جشنواره سیستمی است و در فهرست حذف نمایش داده نمی‌شود.');return;}
        bale_product_bot_session_save($chatId,$userId,'category_delete_confirm',['category_id'=>$category['id'],'category_name'=>$category['name']]);
        $confirmation="⚠️ حذف دسته‌بندی برگشت‌پذیر نیست.\nنام: {$category['name']}\nشناسه: ".fa_number($category['id']);
        if($category['product_count']>0)$confirmation.="\nمحصولات داخل دسته: ".fa_number($category['product_count'])."\nمحصولات خودکار به «بدون دسته‌بندی» منتقل می‌شوند.";
        $confirmation.="\n\nحذف را تأیید می‌کنید؟";
        bale_product_bot_send($chatId,$confirmation,bale_product_bot_inline_keyboard([[[ 'text'=>'✅ بله، حذف شود','callback_data'=>'cd:'.$category['id'] ],[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]]]));
        return;
    }
    if ($step==='product_delete_select') {
        $products=bale_product_bot_find_products($text);
        if(!$products){bale_product_bot_send($chatId,'محصولی پیدا نشد؛ شناسه، SKU یا نام دقیق‌تری بفرستید.');return;}
        if(count($products)>1){$lines=['چند محصول پیدا شد. شناسه دقیق یکی را بفرستید:'];foreach($products as $product)$lines[]=$product['id'].' — '.$product['sku'].' — '.$product['title'];bale_product_bot_send($chatId,implode("\n",$lines));return;}
        $product=$products[0];
        bale_product_bot_session_save($chatId,$userId,'product_delete_confirm',['product_id'=>$product['id'],'product_title'=>$product['title']]);
        bale_product_bot_send($chatId,"⚠️ حذف محصول برگشت‌پذیر نیست.\nمحصول: {$product['title']}\nکد: {$product['sku']}\nشناسه: ".fa_number($product['id'])."\n\nاگر محصول سابقه مالی یا انبار داشته باشد حذف نخواهد شد.",bale_product_bot_inline_keyboard([[[ 'text'=>'✅ بله، حذف شود','callback_data'=>'pd:'.$product['id'] ],[ 'text'=>'❌ لغو عملیات','callback_data'=>bale_product_bot_cancel_callback() ]]]));
        return;
    }

    if ($step==='title') {
        if (text_length($text)<3||text_length($text)>255){bale_product_bot_send($chatId,'نام محصول باید بین ۳ تا ۲۵۵ کاراکتر باشد.');return;}
        $data['title']=$text;
        bale_product_bot_session_save($chatId,$userId,'category',$data);
        bale_product_bot_send_inline_list($chatId,'c',1);
        return;
    }
    if ($step==='category') {
        $rows=bale_product_bot_choices('category');
        $id=bale_product_bot_resolve_choice($text,$rows,(int)setting('bale_product_bot_default_category_id','0'));
        if($id<1){bale_product_bot_send($chatId,'دسته معتبر نیست؛ از دکمه‌های شیشه‌ای فهرست استفاده کنید.');return;}
        $data['category_id']=$id;
        bale_product_bot_session_save($chatId,$userId,'brand',$data);
        bale_product_bot_send_inline_list($chatId,'b',1);
        return;
    }
    if ($step==='brand') {
        $rows=bale_product_bot_choices('brand');
        $id=bale_product_bot_resolve_choice($text,$rows,(int)setting('bale_product_bot_default_brand_id','0'));
        if($id<1){bale_product_bot_send($chatId,'برند معتبر نیست؛ از دکمه‌های شیشه‌ای فهرست استفاده کنید.');return;}
        $data['brand_id']=$id;
        $data['price']=0;
        $data['festival_price']=null;
        $data['festival_free_shipping']=false;
        bale_product_bot_send_price_panel($chatId,$userId,$data);
        return;
    }
    if ($step==='price_main_input') {
        $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';
        if($number===''||strlen($number)>15||(int)$number<1||(int)$number>MAX_MONEY_AMOUNT){bale_product_bot_send($chatId,'قیمت اصلی معتبر نیست؛ فقط عدد بزرگ‌تر از صفر بفرستید.');return;}
        $data['price']=(int)$number;
        if (max(0,(int)($data['festival_price']??0)) >= (int)$number) $data['festival_price']=null;
        $data['festival_enabled']=max(0,(int)($data['festival_price']??0))>0 || !empty($data['festival_free_shipping']);
        bale_product_bot_refresh_price_panel($chatId,$userId,$data);
        bale_product_bot_send($chatId,"✅ قیمت اصلی ثبت شد: ".fa_number(format_price((int)$number))." تومان\nبه حروف: ".bale_product_bot_money_words((int)$number)."\nاز پنل قیمت بالا ادامه دهید.",bale_product_bot_cancel_keyboard());
        return;
    }
    if ($step==='price_festival_input') {
        $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';
        if($number===''||strlen($number)>15||(int)$number>MAX_MONEY_AMOUNT){bale_product_bot_send($chatId,'قیمت جشنواره معتبر نیست؛ فقط عدد بفرستید.');return;}
        $festival=(int)$number;
        $main=max(0,(int)($data['price']??0));
        if($festival>0 && ($main<1 || $festival >= $main)){bale_product_bot_send($chatId,'قیمت جشنواره باید بزرگ‌تر از صفر و کمتر از قیمت اصلی باشد.');return;}
        $data['festival_price']=$festival>0?$festival:null;
        $data['festival_enabled']=$festival>0 || !empty($data['festival_free_shipping']);
        bale_product_bot_refresh_price_panel($chatId,$userId,$data);
        $messageText=$festival>0
            ? "✅ قیمت جشنواره ثبت شد: ".fa_number(format_price($festival))." تومان\nبه حروف: ".bale_product_bot_money_words($festival)
            : '✅ قیمت جشنواره حذف شد.';
        bale_product_bot_send($chatId,$messageText."\nاز پنل قیمت بالا ادامه دهید.",bale_product_bot_cancel_keyboard());
        return;
    }
    if ($step==='price_panel') { bale_product_bot_send($chatId,'قیمت اصلی و قیمت جشنواره اختیاری را با دکمه‌های شیشه‌ای پنل قیمت تنظیم کنید.'); return; }
    if ($step==='stock') {
        $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';
        if($number===''||strlen($number)>7||(int)$number>1000000){bale_product_bot_send($chatId,'موجودی باید عددی بین صفر تا ۱٬۰۰۰٬۰۰۰ باشد.');return;}
        $data['stock']=(int)$number;
        bale_product_bot_session_save($chatId,$userId,'features',$data);
        bale_product_bot_send($chatId,"ویژگی‌ها و مشخصات را هر مورد در یک خط بفرستید.\nهر خط دارای «عنوان: مقدار» وارد جدول مشخصات فنی می‌شود.\nخط معمولی به‌عنوان ویژگی متنی ذخیره می‌شود.\nبرای ردکردن این مرحله فقط - بفرستید.",bale_product_bot_cancel_keyboard());
        return;
    }
    if ($step==='features') {
        $merged=merge_product_feature_lines_into_specifications($text==='-'?'':$text,[]);
        $data['features']=$merged['features'];
        $data['specifications']=$merged['specifications'];
        $data['warranty_months']=max(0,min(120,(int)setting('bale_product_bot_default_warranty_months','0')));
        $data['authenticity_guarantee']=(string)setting('bale_product_bot_default_authenticity','0')==='1';
        $data['safe_shipping_guarantee']=(string)setting('bale_product_bot_default_safe_shipping','0')==='1';
        $data['attribute_value_ids']=normalize_product_attribute_value_ids($data['attribute_value_ids']??[]);
        $data['home_festival']=false;
        bale_product_bot_send_options($chatId,$userId,$data);
        return;
    }
    if ($step==='warranty_input') {
        $number=preg_replace('/[^0-9]/','',normalize_digits($text))??'';
        if($number===''||strlen($number)>3||(int)$number>120){bale_product_bot_send($chatId,'مدت گارانتی باید عددی بین صفر تا ۱۲۰ ماه باشد.');return;}
        $data['warranty_months']=(int)$number;
        $messageId=max(0,(int)($data['options_message_id']??0));
        bale_product_bot_session_save($chatId,$userId,'product_options',$data);
        if($messageId>0){try{bale_product_bot_edit($chatId,$messageId,bale_product_bot_options_text($data),bale_product_bot_options_markup($data));}catch(Throwable $error){log_error($error);}}
        bale_product_bot_send($chatId,'✅ مدت گارانتی ثبت شد: '.((int)$number>0?fa_number((int)$number).' ماه':'بدون گارانتی')."\nاز پنل گزینه‌ها ادامه دهید.",bale_product_bot_cancel_keyboard());
        return;
    }
    if (in_array($step,['product_options','attribute_groups','attribute_values'],true)) { bale_product_bot_send($chatId,'این مرحله با دکمه‌های شیشه‌ای پیام قبلی انجام می‌شود.'); return; }
    if ($step==='images') {
        $image=bale_product_bot_extract_image($message);
        $images=is_array($data['images']??null)?$data['images']:[];
        $max=max(1,min(12,(int)setting('bale_product_bot_max_images','12')));
        if($image){
            if(count($images)>=$max){bale_product_bot_send($chatId,'حداکثر '.fa_number($max).' تصویر قابل ثبت است.',bale_product_bot_images_keyboard());return;}
            if($image['file_size']>6*1024*1024){bale_product_bot_send($chatId,'حجم این تصویر بیشتر از ۶ مگابایت است و پذیرفته نشد.');return;}
            $images[]=$image;
            $data['images']=$images;
            bale_product_bot_session_save($chatId,$userId,'images',$data);
            bale_product_bot_send($chatId,'✅ تصویر '.fa_number(count($images)).' از '.fa_number($max).' دریافت شد. عکس اول تصویر اصلی است.',bale_product_bot_images_keyboard());
            return;
        }
        if($text==='🗑 حذف آخرین تصویر'){
            if($images)array_pop($images);
            $data['images']=$images;
            bale_product_bot_session_save($chatId,$userId,'images',$data);
            bale_product_bot_send($chatId,'تعداد تصاویر فعلی: '.fa_number(count($images)),bale_product_bot_images_keyboard());
            return;
        }
        if($text==='✅ پایان تصاویر'||$text==='-'){
            $data['images']=$images;
            $allow=(string)setting('bale_product_bot_allow_direct_publish','0')==='1';
            bale_product_bot_session_save($chatId,$userId,'status',$data);
            $rows=$allow?[['📝 پیش‌نویس','✅ انتشار مستقیم'],['❌ لغو عملیات']]:[['📝 پیش‌نویس'],['❌ لغو عملیات']];
            bale_product_bot_send($chatId,$allow?'وضعیت ثبت محصول را انتخاب کنید.':'برای امنیت، محصولات ربات ابتدا به‌صورت پیش‌نویس ثبت می‌شوند.',bale_product_bot_keyboard($rows));
            return;
        }
        bale_product_bot_send($chatId,'یک تصویر بفرستید یا از دکمه پایان تصاویر استفاده کنید.',bale_product_bot_images_keyboard());
        return;
    }
    if ($step==='status') {
        $allow=(string)setting('bale_product_bot_allow_direct_publish','0')==='1';
        if($text==='✅ انتشار مستقیم'&&$allow)$data['status']='active';
        elseif($text==='📝 پیش‌نویس')$data['status']='draft';
        else{bale_product_bot_send($chatId,'یکی از وضعیت‌های نمایش‌داده‌شده را انتخاب کنید.');return;}
        bale_product_bot_session_save($chatId,$userId,'description',$data);
        bale_product_bot_send($chatId,"توضیحات محصول را بفرستید. این بخش اختیاری است.
برای ثبت محصول بدون توضیحات، دکمه «بدون توضیحات» را بزنید یا فقط - بفرستید.",bale_product_bot_keyboard([['⏭ بدون توضیحات'],['❌ لغو عملیات']]));
        return;
    }
    if ($step==='description') {
        $description=in_array($text,['-','⏭ بدون توضیحات','خالی'],true)?'':trim($text);
        if(text_length($description)>30000){bale_product_bot_send($chatId,'توضیحات محصول بیش از حد طولانی است. حداکثر ۳۰٬۰۰۰ کاراکتر مجاز است.');return;}
        $data['description']=$description;
        bale_product_bot_session_save($chatId,$userId,'confirm',$data);
        bale_product_bot_confirmation($chatId,$data);
        return;
    }
    if ($step==='confirm') {
        if($text==='🔄 شروع دوباره'){bale_product_bot_session_save($chatId,$userId,'title',[]);bale_product_bot_send($chatId,'نام محصول را دوباره بفرستید.',bale_product_bot_cancel_keyboard());return;}
        if($text!=='✅ ثبت محصول'){bale_product_bot_confirmation($chatId,$data);return;}
        bale_product_bot_session_save($chatId,$userId,'processing',$data);
        $paths=[];
        try {
            foreach(($data['images']??[]) as $imageRow){
                if(!is_array($imageRow))continue;
                $bytes=bale_product_bot_download_file(trim(scalar_string($imageRow['file_id']??'')));
                $paths[]=bale_product_bot_save_image_bytes($bytes);
            }
            $homepageFestival=configured_homepage_festival();
            $festivalPrice=isset($data['festival_price'])&&$data['festival_price']!==null?max(0,(int)$data['festival_price']):null;
            $festivalEnabled=($festivalPrice!==null&&$festivalPrice>0)||!empty($data['festival_free_shipping']);
            $result=quick_product_service_create([
                'title'=>$data['title']??'',
                'description'=>$data['description']??'',
                'category_id'=>$data['category_id']??0,
                'brand_id'=>$data['brand_id']??0,
                'price'=>$data['price']??0,
                'stock'=>$data['stock']??0,
                'status'=>$data['status']??'draft',
                'features'=>$data['features']??[],
                'specifications'=>$data['specifications']??[],
                'warranty_months'=>(int)($data['warranty_months']??0),
                'authenticity_guarantee'=>!empty($data['authenticity_guarantee']),
                'safe_shipping_guarantee'=>!empty($data['safe_shipping_guarantee']),
                'festival_enabled'=>$festivalEnabled,
                'festival_price'=>$festivalPrice,
                'festival_free_shipping'=>!empty($data['festival_free_shipping']),
                'attribute_value_ids'=>bale_product_bot_selected_attribute_ids($data),
                'home_festival_id'=>!empty($data['home_festival'])&&$homepageFestival?(int)$homepageFestival['id']:0,
            ],$paths);
            db()->prepare('INSERT INTO bale_product_bot_history(product_id,chat_id,user_id,sku,created_at) VALUES(?,?,?,?,NOW())')->execute([(int)$result['product_id'],$chatId,$userId,(string)$result['sku']]);
            admin_audit_event('bale_product_created','product',(int)$result['product_id'],[
                'username'=>'bale:'.$userId,
                'bale_user_id'=>$userId,
                'sku'=>$result['sku'],
                'status'=>$result['status'],
                'images'=>count($paths),
                'festival_enabled'=>$festivalEnabled,
                'attribute_values'=>count(bale_product_bot_selected_attribute_ids($data)),
            ]);
            bale_product_bot_session_delete($chatId);
            $siteUrl=rtrim((string)setting('site_url','https://golrizan1368.ir'),'/');
            if($siteUrl==='')$siteUrl='https://golrizan1368.ir';
            $publicUrl=product_url(['slug'=>(string)$result['slug']]);
            $messageText="✅ محصول ثبت شد.\nکد کالا: {$result['sku']}\nوضعیت: ".($result['status']==='active'?'فعال':'پیش‌نویس')."\n\nمشاهده محصول در سایت:\n{$publicUrl}\n\nفروشگاه گلریزان:\n{$siteUrl}\n\nویرایش در پنل:\n".app_url('admin/product-edit.php?id='.(int)$result['product_id']);
            if(!$result['gallery_saved'])$messageText.="\n⚠️ ثبت کامل گالری انجام نشد؛ تصویر اصلی نگه داشته شد.";
            bale_product_bot_send($chatId,$messageText,bale_product_bot_main_keyboard());
        } catch(Throwable $error) {
            foreach($paths as $path)delete_managed_upload($path);
            log_error($error);
            bale_product_bot_session_save($chatId,$userId,'confirm',$data);
            bale_product_bot_send($chatId,'❌ ثبت محصول انجام نشد: '.text_substr($error->getMessage(),0,500)."\nاطلاعات حفظ شده است؛ دوباره ثبت را بزنید یا عملیات را لغو کنید.",bale_product_bot_keyboard([['✅ ثبت محصول'],['❌ لغو عملیات']]));
        }
        return;
    }
    if(in_array($step,['category_delete_confirm','product_delete_confirm'],true)){bale_product_bot_send($chatId,'برای تأیید یا لغو از دکمه‌های شیشه‌ای پیام قبلی استفاده کنید.');return;}
    if($step==='processing'){bale_product_bot_send($chatId,'محصول در حال ثبت است؛ چند لحظه صبر کنید.');return;}
    bale_product_bot_session_delete($chatId);
    bale_product_bot_send($chatId,'وضعیت عملیات نامعتبر بود و پاک شد. دوباره شروع کنید.',bale_product_bot_main_keyboard());
}
require_once BASE_PATH . '/app/bale-product-bot-advanced.php';
require_once BASE_PATH . '/app/bale-torobpay-festival.php';
require_once BASE_PATH . '/app/bale-price-adjustments.php';
require_once BASE_PATH . '/app/bale-dashboard.php';
