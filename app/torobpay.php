<?php
declare(strict_types=1);

function torobpay_api_base(): string
{
    $url = rtrim(trim((string)setting('torobpay_api_url','https://cpg.torobpay.com')), '/');
    return $url !== '' ? $url : 'https://cpg.torobpay.com';
}

function torobpay_secret(string $key): string
{
    return trim(reveal_secret((string)setting($key,'')));
}

function torobpay_client_id(): string { return torobpay_secret('torobpay_client_id_enc'); }
function torobpay_client_secret(): string { return torobpay_secret('torobpay_client_secret_enc'); }
function torobpay_username(): string { return torobpay_secret('torobpay_username_enc'); }
function torobpay_password(): string { return torobpay_secret('torobpay_password_enc'); }
function torobpay_title(): string { return trim((string)setting('torobpay_title','پرداخت اعتباری ترب‌پی')) ?: 'پرداخت اعتباری ترب‌پی'; }
function torobpay_installment_count(): int { return 4; }

function torobpay_installment_amount(int $amountToman): int
{
    if ($amountToman < 1 || $amountToman > MAX_MONEY_AMOUNT) {
        throw new RuntimeException('مبلغ کالا برای محاسبه اقساط ترب‌پی معتبر نیست.');
    }
    $count = torobpay_installment_count();
    return intdiv($amountToman + $count - 1, $count);
}

function torobpay_amount_is_supported(int $amountToman): bool
{
    return $amountToman >= 20_000 && $amountToman <= 100_000_000;
}

function torobpay_installment_quote(int $productPriceToman): array
{
    $torobpayTotal = torobpay_apply_markup($productPriceToman);
    return [
        'count' => torobpay_installment_count(),
        'total' => $torobpayTotal,
        'installment_amount' => torobpay_installment_amount($torobpayTotal),
        'supported' => torobpay_amount_is_supported($torobpayTotal),
    ];
}
// در چرخه رسمی ترب‌پی، Verify فقط مجوز پرداخت را تأیید می‌کند و ثبت قطعی
// سفارش باید بعد از Settle موفق انجام شود. این تابع برای سازگاری با نسخه‌های
// قبلی باقی مانده، اما خاموش‌کردن Settle دیگر مجاز نیست.
function torobpay_settle_enabled(): bool { return true; }

function torobpay_normalize_markup_percent(string $raw): string
{
    $raw = trim(normalize_digits($raw));
    $raw = str_replace(['٪','%',' '], '', $raw);
    $raw = str_replace(['٫',','], '.', $raw);
    if ($raw === '') $raw = '0';
    if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $raw)) {
        throw new RuntimeException('درصد افزایش ترب‌پی باید عددی بین ۰ تا ۱۰۰ و حداکثر با دو رقم اعشار باشد.');
    }
    $value = (float)$raw;
    if ($value < 0 || $value > 100) throw new RuntimeException('درصد افزایش ترب‌پی باید بین ۰ تا ۱۰۰ باشد.');
    $canonical = number_format($value, 2, '.', '');
    $canonical = rtrim(rtrim($canonical, '0'), '.');
    return $canonical === '' ? '0' : $canonical;
}

function torobpay_markup_percent(): string
{
    try { return torobpay_normalize_markup_percent((string)setting('torobpay_markup_percent','0')); }
    catch (Throwable) { return '0'; }
}

function torobpay_markup_basis_points(): int
{
    return (int)round((float)torobpay_markup_percent() * 100);
}

function torobpay_markup_label(): string
{
    return str_replace('.', '٫', torobpay_markup_percent()).'٪';
}

function torobpay_apply_markup(int $amount, ?int $basisPoints = null): int
{
    if ($amount < 0 || $amount > MAX_MONEY_AMOUNT) throw new RuntimeException('مبلغ برای محاسبه افزایش ترب‌پی معتبر نیست.');
    $basisPoints = $basisPoints ?? torobpay_markup_basis_points();
    $basisPoints = max(0, min(10000, $basisPoints));
    if ($basisPoints === 0 || $amount === 0) return $amount;
    $increase = intdiv(($amount * $basisPoints) + 5000, 10000);
    if ($increase > MAX_MONEY_AMOUNT - $amount) throw new RuntimeException('مبلغ پس از اعمال درصد ترب‌پی از سقف مجاز بیشتر است.');
    return $amount + $increase;
}

function torobpay_enabled(): bool
{
    return (string)setting('torobpay_enabled','0') === '1'
        && torobpay_client_id() !== ''
        && torobpay_client_secret() !== ''
        && torobpay_username() !== ''
        && torobpay_password() !== '';
}

function torobpay_endpoint(string $path): string
{
    return torobpay_api_base() . '/' . ltrim($path, '/');
}

function torobpay_http(string $method, string $url, array $payload = [], array $headers = []): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
    $method = strtoupper($method);
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    // GET و OAuth تکرارپذیرند؛ روی خطاهای موقت زیرساخت یک بار دیگر امتحان می‌شوند.
    // درخواست ساخت/تأیید پرداخت عمداً خودکار تکرار نمی‌شود تا سفارش تکراری ساخته نشود.
    $maxAttempts = $method === 'GET' || str_ends_with($path, '/api/online/v1/oauth/token') ? 2 : 1;
    $lastError = 'خطای شبکه';
    $lastHttp = 0;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('راه‌اندازی ارتباط cURL با ترب‌پی انجام نشد.');
        $requestHeaders = array_values($headers);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $requestHeaders,
        ];
        if ($method !== 'GET') {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($body)) throw new RuntimeException('ساخت درخواست ترب‌پی انجام نشد.');
            $options[CURLOPT_POSTFIELDS] = $body;
            $requestHeaders[] = 'Content-Type: application/json';
            $requestHeaders[] = 'Accept: application/json';
            $options[CURLOPT_HTTPHEADER] = array_values(array_unique($requestHeaders));
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $lastError = trim(curl_error($curl)) ?: 'خطای شبکه';
        $lastHttp = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $transient = $lastHttp === 0 || in_array($lastHttp, [502,503,504], true);
        if ((!is_string($raw) || $transient) && $attempt < $maxAttempts) {
            usleep(250000);
            continue;
        }
        if (!is_string($raw)) throw new RuntimeException('ارتباط با ترب‌پی برقرار نشد: '.$lastError);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            if (in_array($lastHttp, [502,503,504], true)) {
                throw new RuntimeException('سرویس ترب‌پی موقتاً در دسترس نیست (HTTP '.$lastHttp.'). کمی بعد دوباره تلاش کنید.');
            }
            throw new RuntimeException('پاسخ ترب‌پی قابل‌خواندن نیست. کد HTTP '.$lastHttp);
        }
        $json['_http'] = $lastHttp;
        return $json;
    }
    throw new RuntimeException('ارتباط با ترب‌پی برقرار نشد: '.$lastError.($lastHttp > 0 ? ' [HTTP '.$lastHttp.']' : ''));
}

function torobpay_http_ok(array $response): bool
{
    $http = (int)($response['_http'] ?? 0);
    if ($http < 200 || $http >= 300) return false;
    if (array_key_exists('successful', $response) && ($response['successful'] ?? null) !== true) return false;
    return true;
}

function torobpay_error_message(array $response, string $fallback): string
{
    $candidates = [
        $response['error']['user_message'] ?? null,
        $response['errorData']['message'] ?? null,
        $response['error']['message'] ?? null,
        $response['result']['message'] ?? null,
        $response['message'] ?? null,
        $response['error_description'] ?? null,
    ];
    $message = '';
    foreach ($candidates as $value) {
        if (is_scalar($value) && trim((string)$value) !== '') {
            $message = text_substr(trim((string)$value), 0, 420);
            break;
        }
    }
    if ($message === '') $message = $fallback;
    $code = $response['error']['code'] ?? $response['errorData']['errorCode'] ?? null;
    if (is_scalar($code) && trim((string)$code) !== '') $message .= ' (کد ترب‌پی: '.text_substr(trim((string)$code), 0, 30).')';
    $http = (int)($response['_http'] ?? 0);
    if ($http > 0) $message .= ' [HTTP '.$http.']';
    return text_substr($message, 0, 500);
}

function torobpay_access_token(bool $forceRefresh = false): string
{
    static $requestToken = '';
    if (!$forceRefresh && $requestToken !== '') return $requestToken;
    $clientId = torobpay_client_id();
    $clientSecret = torobpay_client_secret();
    $username = torobpay_username();
    $password = torobpay_password();
    if ($clientId === '' || $clientSecret === '' || $username === '' || $password === '') {
        throw new RuntimeException('اطلاعات اتصال ترب‌پی در پنل مدیریت کامل نشده است.');
    }
    // طبق سند CPG v1.4، OAuth یک JSON Body با همین دو فیلد است.
    $response = torobpay_http('POST', torobpay_endpoint('/api/online/v1/oauth/token'), [
        'username' => $username,
        'password' => $password,
    ], [
        'Authorization: Basic '.base64_encode($clientId.':'.$clientSecret),
        'Accept: application/json',
        'User-Agent: TorobPay, '.preg_replace('/[\x00-\x1F\x7F]/', '', $clientId),
        'X-TorobPay-Plugin-Version: '.app_version(),
        'X-TorobPay-Shop-URL: '.app_url(),
    ]);
    if (!torobpay_http_ok($response)) {
        throw new RuntimeException(torobpay_error_message($response, 'احراز هویت ترب‌پی انجام نشد.'));
    }
    $token = trim((string)($response['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('ترب‌پی Access Token معتبر برنگرداند.');
    $requestToken = $token;
    return $requestToken;
}

function torobpay_api_json(string $method, string $path, array $payload = []): array
{
    $token = torobpay_access_token();
    $headers = [
        'Authorization: Bearer '.$token,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: TorobPay, '.preg_replace('/[\x00-\x1F\x7F]/', '', torobpay_client_id()),
        'X-TorobPay-Plugin-Version: '.app_version(),
        'X-TorobPay-Shop-URL: '.app_url(),
    ];
    $response = torobpay_http($method, torobpay_endpoint($path), $payload, $headers);
    if ((int)($response['_http'] ?? 0) === 401) {
        $token = torobpay_access_token(true);
        $headers[0] = 'Authorization: Bearer '.$token;
        $response = torobpay_http($method, torobpay_endpoint($path), $payload, $headers);
    }
    return $response;
}

function torobpay_payment_page_url(array $response): string
{
    $nested = is_array($response['response'] ?? null) ? $response['response'] : [];
    $url = trim((string)($nested['paymentPageUrl'] ?? $response['paymentPageUrl'] ?? $nested['payment_page_url'] ?? $response['payment_page_url'] ?? ''));
    $parts = $url !== '' ? parse_url($url) : false;
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('آدرس صفحه پرداخت ترب‌پی معتبر نیست.');
    }
    // سند CPG دامنه ثابتی برای paymentPageUrl تضمین نکرده است؛ URL احراز‌شده API
    // بدون بازنویسی پذیرفته می‌شود و فقط باید HTTPS معتبر و بدون userinfo باشد.
    return $url;
}

function torobpay_payment_token(array $response): string
{
    $nested = is_array($response['response'] ?? null) ? $response['response'] : [];
    $token = trim((string)($nested['paymentToken'] ?? $response['paymentToken'] ?? $nested['payment_token'] ?? $response['payment_token'] ?? ''));
    // فرمت Token در قراردادهای مختلف ممکن است UUID یا رشته رمزگذاری‌شده باشد؛
    // فقط طول ستون و کاراکترهای کنترلی را محدود می‌کنیم و هر استفاده با Prepared Statement/URL encoding انجام می‌شود.
    if ($token === '' || text_length($token) > 150 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
        throw new RuntimeException('توکن پرداخت ترب‌پی معتبر نیست.');
    }
    return $token;
}

function torobpay_payment_redirect_url(string $paymentUrl, string $paymentToken): string
{
    // paymentPageUrl یک URL آماده و امضاشده است؛ افزودن یا بازنویسی Query آن
    // می‌تواند ورود به درگاه را نامعتبر کند. Token جداگانه فقط برای Verify ذخیره می‌شود.
    unset($paymentToken);
    return $paymentUrl;
}

function torobpay_assert_eligible(int $amountToman): void
{
    $amountRial = payment_toman_to_rial($amountToman);
    // بازه اعلام‌شده در سند CPG: از ۲۰۰٬۰۰۰ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ ریال.
    if ($amountRial < 200_000 || $amountRial > 1_000_000_000) {
        throw new RuntimeException('مبلغ سفارش باید برای ترب‌پی بین ۲۰ هزار تا ۱۰۰ میلیون تومان باشد.');
    }
    $response = torobpay_api_json('GET', '/api/online/offer/v1/eligible?amount='.rawurlencode((string)$amountRial));
    if (!torobpay_http_ok($response) || ($response['successful'] ?? null) !== true) {
        throw new RuntimeException(torobpay_error_message($response, 'بررسی امکان ارائه خرید اعتباری ترب‌پی انجام نشد.'));
    }
    $result = is_array($response['response'] ?? null) ? $response['response'] : [];
    if (($result['eligible'] ?? null) !== true) {
        $message = trim((string)($result['description'] ?? $result['title_message'] ?? ''));
        throw new RuntimeException($message !== '' ? text_substr($message, 0, 500) : 'در حال حاضر امکان ارائه خرید اعتباری ترب‌پی برای این مبلغ وجود ندارد.');
    }
}

function torobpay_cart_for_order(array $order): array
{
    $stmt = db()->prepare("SELECT oi.id,oi.product_id,oi.variant_id,oi.title,oi.variant_title,oi.unit_price,oi.quantity,oi.line_total,COALESCE(c.name,'عمومی') category_name FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE oi.order_id=? ORDER BY oi.id");
    $stmt->execute([(int)$order['id']]);
    $rows = $stmt->fetchAll();
    if ($rows === []) throw new RuntimeException('اقلام سفارش برای ارسال به ترب‌پی پیدا نشد.');

    $items = [];
    $calculatedSubtotal = 0;
    foreach ($rows as $row) {
        $quantity = (int)$row['quantity'];
        $unitPrice = (int)$row['unit_price'];
        $lineTotal = (int)$row['line_total'];
        if ($quantity < 1 || $unitPrice < 1 || $lineTotal !== $unitPrice * $quantity) {
            throw new RuntimeException('مبلغ یکی از اقلام سفارش برای ترب‌پی معتبر نیست.');
        }
        if ($calculatedSubtotal > MAX_MONEY_AMOUNT - $lineTotal) throw new RuntimeException('جمع اقلام سفارش بیش از محدوده مجاز است.');
        $calculatedSubtotal += $lineTotal;
        $variantTitle = trim((string)($row['variant_title'] ?? ''));
        $name = trim((string)$row['title']).($variantTitle !== '' ? ' - '.$variantTitle : '');
        $item = [
            'id' => (string)(int)($row['variant_id'] ?: $row['product_id']),
            'name' => text_substr($name, 0, 255),
            'count' => $quantity,
            'amount' => payment_toman_to_rial($unitPrice),
            'category' => text_substr((string)$row['category_name'], 0, 100),
        ];
        $items[] = $item;
    }
    if ($calculatedSubtotal !== (int)$order['subtotal']) {
        throw new RuntimeException('جمع اقلام سفارش با مبلغ ثبت‌شده سفارش یکسان نیست.');
    }

    $shipping = max(0, (int)($order['shipping_amount'] ?? 0));
    return [[
        'cartId' => (string)(int)$order['id'],
        'totalAmount' => payment_toman_to_rial((int)$order['total']),
        'taxAmount' => 0,
        'shippingAmount' => $shipping > 0 ? payment_toman_to_rial($shipping) : 0,
        'isTaxIncluded' => false,
        'isShipmentIncluded' => $shipping > 0,
        'cartItems' => $items,
    ]];
}

function torobpay_start_for_order(array $order): string
{
    if ((int)($order['total'] ?? 0) > 0) release_expired_unpaid_orders(25);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $lock->execute([(int)$order['id']]);
        $locked = $lock->fetch();
        if (!$locked) throw new RuntimeException('سفارش پیدا نشد.');
        if ((string)$locked['payment_method'] !== 'torobpay') throw new RuntimeException('روش پرداخت این سفارش ترب‌پی نیست.');
        if ((string)$locked['payment_status'] === 'paid') {
            $pdo->commit();
            return app_url('order-success.php?token='.$locked['public_token']);
        }
        if ((int)$locked['total'] < 1) throw new RuntimeException('این سفارش نیازی به پرداخت ترب‌پی ندارد.');
        if (!torobpay_enabled()) throw new RuntimeException('درگاه ترب‌پی از پنل مدیریت فعال یا تکمیل نشده است.');
        if (!in_array((string)$locked['order_status'], ['new','pending_payment'], true)) throw new RuntimeException('این سفارش دیگر قابل پرداخت نیست.');
        if (!in_array((string)$locked['payment_status'], ['unpaid','failed','pending'], true)) throw new RuntimeException('وضعیت پرداخت این سفارش اجازه شروع تراکنش جدید نمی‌دهد.');

        $recent = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='torobpay' AND status IN ('created','requested') ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $recent->execute([(int)$locked['id']]);
        $existing = $recent->fetch();
        if ($existing) {
            $createdTs = strtotime((string)$existing['created_at']);
            $age = $createdTs > 0 ? max(0, time()-$createdTs) : PHP_INT_MAX;
            $reuseMinutes = max(5, min(120, (int)setting('payment_reservation_minutes','30')));
            if ((string)$existing['status'] === 'requested' && $age < $reuseMinutes*60) {
                $oldResponse = json_decode((string)($existing['response_json'] ?? ''), true);
                if (is_array($oldResponse)) {
                    $oldUrl = '';
                    try { $oldUrl = torobpay_payment_page_url($oldResponse); } catch (Throwable) {}
                    if ($oldUrl !== '') {
                        $oldPaymentToken = trim((string)($existing['authority'] ?? ''));
                        $pdo->commit();
                        return $oldPaymentToken !== '' ? torobpay_payment_redirect_url($oldUrl, $oldPaymentToken) : $oldUrl;
                    }
                }
            }
            if ((string)$existing['status'] === 'created' && $age < 90) throw new RuntimeException('درخواست پرداخت دیگری در حال ایجاد است؛ چند لحظه بعد دوباره تلاش کنید.');
            $pdo->prepare("UPDATE payment_transactions SET status='cancelled',response_json=COALESCE(response_json,?) WHERE id=? AND status IN ('created','requested')")
                ->execute([json_encode(['reason'=>'expired_before_retry','expired_at'=>date('c')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), (int)$existing['id']]);
        }

        $order = $locked;
        $pdo->prepare("INSERT INTO payment_transactions(order_id,gateway,amount,status,request_json) VALUES(?, 'torobpay', ?, 'created', NULL)")
            ->execute([(int)$order['id'], (int)$order['total']]);
        $transactionId = (int)$pdo->lastInsertId();
        $callback = app_url('torobpay-callback.php?token='.rawurlencode((string)$order['public_token']).'&tx='.$transactionId);
        // شناسه رشته‌ای و یکتای سمت فروشگاه؛ شناسه داخلی تراکنش از تکرار آن جلوگیری می‌کند.
        $merchantTransactionId = (string)(int)$order['id'].'-'.$transactionId;
        $payload = [
            'amount' => payment_toman_to_rial((int)$order['total']),
            'paymentMethodTypeDto' => 'ONLINE_CREDIT',
            'returnURL' => $callback,
            'transactionId' => $merchantTransactionId,
            'cartList' => torobpay_cart_for_order($order),
            'discountAmount' => (int)$order['discount_amount'] > 0 ? payment_toman_to_rial((int)$order['discount_amount']) : 0,
            'mobile' => (string)$order['phone'],
            'registration_phone_number' => (string)$order['phone'],
            'customer_full_name' => text_substr(trim((string)$order['customer_name']), 0, 160),
            'province' => text_substr(trim((string)$order['province']), 0, 80),
            'city' => text_substr(trim((string)$order['city']), 0, 100),
            'address' => text_substr(trim((string)$order['address']), 0, 1000),
            'postalCode' => trim((string)$order['postal_code']),
        ];
        $pdo->prepare('UPDATE payment_transactions SET request_json=? WHERE id=?')->execute([
            json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),
            $transactionId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    try {
        torobpay_assert_eligible((int)$order['total']);
        $response = torobpay_api_json('POST', '/api/online/payment/v1/token', $payload);
        if (!torobpay_http_ok($response) || ($response['successful'] ?? null) !== true) throw new RuntimeException(torobpay_error_message($response, 'ایجاد پرداخت ترب‌پی انجام نشد.'));
        $paymentToken = torobpay_payment_token($response);
        $paymentUrl = torobpay_payment_page_url($response);
        $pdo->prepare("UPDATE payment_transactions SET authority=?,status='requested',response_json=? WHERE id=?")
            ->execute([$paymentToken, json_encode($response, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $transactionId]);
        $pdo->prepare("UPDATE orders SET payment_status='pending',gateway_reference=?,order_status=IF(order_status IN ('new','pending_payment'),'pending_payment',order_status) WHERE id=? AND payment_status<>'paid'")
            ->execute([$paymentToken, (int)$order['id']]);
        return torobpay_payment_redirect_url($paymentUrl, $paymentToken);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE payment_transactions SET status='failed',response_json=? WHERE id=?")
            ->execute([json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE), $transactionId]);
        $pdo->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND payment_status<>'paid'")->execute([(int)$order['id']]);
        admin_notify_gateway_failure((int)$order['id'], $e->getMessage(), 'torobpay-request-'.$transactionId);
        throw $e;
    }
}

function torobpay_latest_requested_transaction(int $orderId): ?array
{
    $stmt = db()->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='torobpay' AND status IN ('requested','paid') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

function torobpay_callback_transaction(int $orderId, int $transactionId = 0): ?array
{
    if ($transactionId > 0) {
        $stmt = db()->prepare("SELECT * FROM payment_transactions WHERE id=? AND order_id=? AND gateway='torobpay' LIMIT 1");
        $stmt->execute([$transactionId,$orderId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    // سازگاری با پرداخت‌هایی که قبل از اضافه‌شدن tx به Callback ساخته شده‌اند.
    return torobpay_latest_requested_transaction($orderId);
}

function torobpay_verify_order(array $order, string $paymentToken): array
{
    if ($paymentToken === '' || text_length($paymentToken) > 150) throw new RuntimeException('توکن پرداخت ترب‌پی معتبر نیست.');
    $lockPath = BASE_PATH.'/storage/torobpay-verify-'.hash('sha256', $paymentToken).'.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) @fclose($lock);
        throw new RuntimeException('قفل بررسی تراکنش ترب‌پی ایجاد نشد؛ دوباره تلاش کنید.');
    }
    try {
        return torobpay_verify_order_locked($order, $paymentToken);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function torobpay_verify_order_locked(array $order, string $paymentToken): array
{
    if ($paymentToken === '' || text_length($paymentToken) > 150) throw new RuntimeException('توکن پرداخت ترب‌پی معتبر نیست.');
    $pre = db()->prepare("SELECT pt.*,o.total,o.payment_status,o.gateway_reference FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.order_id=? AND pt.gateway='torobpay' AND pt.authority=? ORDER BY pt.id DESC LIMIT 1");
    $pre->execute([(int)$order['id'], $paymentToken]);
    $preRow = $pre->fetch();
    if (!$preRow) throw new RuntimeException('تراکنش ترب‌پی مربوط به سفارش پیدا نشد.');
    if ((int)$preRow['amount'] !== (int)$preRow['total']) throw new RuntimeException('مبلغ تراکنش ترب‌پی با سفارش تطابق ندارد.');
    if ((string)$preRow['payment_status'] === 'paid' || (string)$preRow['status'] === 'paid') {
        sms_notify_after_payment_completed((int)$order['id']);
        sms_notify_payment_status((int)$order['id'],'paid');
        return ['ok'=>true,'already'=>true,'reference'=>(string)($preRow['reference_id'] ?: $preRow['gateway_reference'])];
    }

    $verify = torobpay_api_json('POST', '/api/online/payment/v1/verify', ['paymentToken'=>$paymentToken]);
    if (!torobpay_http_ok($verify) || ($verify['successful'] ?? null) !== true) {
        throw new RuntimeException(torobpay_error_message($verify, 'تراکنش ترب‌پی تأیید نشد.'));
    }
    $verifyResponse = is_array($verify['response'] ?? null) ? $verify['response'] : [];
    if (isset($verifyResponse['amount']) && is_numeric($verifyResponse['amount'])) {
        $verifiedAmount = (int)$verifyResponse['amount'];
        $expectedToman = (int)$preRow['total'];
        $expectedRial = payment_toman_to_rial($expectedToman);
        if (!in_array($verifiedAmount, [$expectedToman,$expectedRial], true)) {
            throw new RuntimeException('مبلغ تأییدشده ترب‌پی با مبلغ سفارش یکسان نیست.');
        }
    }
    $request = json_decode((string)($preRow['request_json'] ?? ''), true);
    $expectedTransactionId = is_array($request) ? trim((string)($request['transactionId'] ?? '')) : '';
    $verifiedTransactionId = trim((string)($verifyResponse['transactionId'] ?? ''));
    if ($expectedTransactionId !== '' && $verifiedTransactionId !== '' && !hash_equals($expectedTransactionId, $verifiedTransactionId)) {
        throw new RuntimeException('شناسه تراکنش تأییدشده ترب‌پی با سفارش یکسان نیست.');
    }

    // سفارش فقط پس از Settle قطعی پرداخت‌شده می‌شود؛ Verify به‌تنهایی کافی نیست.
    $settle = torobpay_api_json('POST', '/api/online/payment/v1/settle', ['paymentToken'=>$paymentToken]);
    if (!torobpay_http_ok($settle) || ($settle['successful'] ?? null) !== true) {
        throw new RuntimeException(torobpay_error_message($settle, 'تأیید نهایی (Settle) ترب‌پی انجام نشد.'));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $lock->execute([(int)$order['id']]);
        $locked = $lock->fetch();
        if (!$locked) throw new RuntimeException('سفارش پیدا نشد.');
        $tx = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='torobpay' AND authority=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $tx->execute([(int)$order['id'], $paymentToken]);
        $transaction = $tx->fetch();
        if (!$transaction) throw new RuntimeException('تراکنش ترب‌پی مربوط به سفارش پیدا نشد.');
        if ((int)$transaction['amount'] !== (int)$locked['total']) throw new RuntimeException('مبلغ تراکنش با سفارش تطابق ندارد.');
        if ((string)$locked['payment_status'] === 'paid' || (string)$transaction['status'] === 'paid') {
            $pdo->commit();
            return ['ok'=>true,'already'=>true,'reference'=>(string)($transaction['reference_id'] ?: $locked['gateway_reference'])];
        }
        $reference = $verifiedTransactionId !== '' ? $verifiedTransactionId : $paymentToken;
        $storedResponse = ['verify'=>$verify,'settle'=>$settle];
        $pdo->prepare("UPDATE payment_transactions SET status='paid',reference_id=?,response_json=?,verified_at=NOW() WHERE id=?")
            ->execute([$reference, json_encode($storedResponse, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), (int)$transaction['id']]);
        $oldStatus = (string)$locked['order_status'];
        $newStatus = in_array($oldStatus,['new','pending_payment'],true) ? 'reviewing' : $oldStatus;
        if ($oldStatus === 'cancelled') accounting_cancelled_order_late_payment($pdo,(int)$locked['id'],(int)$locked['total']);
        else { accounting_ensure_order_sale($pdo,(int)$locked['id']); accounting_order_paid($pdo,(int)$locked['id'],(int)$locked['total']); }
        $pdo->prepare("UPDATE orders SET payment_status='paid',order_status=?,gateway_reference=?,paid_at=COALESCE(paid_at,NOW()) WHERE id=?")
            ->execute([$newStatus,$reference,(int)$locked['id']]);
        $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')
            ->execute([(int)$locked['id'],$oldStatus,$newStatus,$locked['payment_status'],'paid','تأیید خودکار پرداخت ترب‌پی']);
        $pdo->commit();
        admin_notify_payment_success((int)$locked['id']);
        if ($oldStatus === 'cancelled') {
            try { create_admin_refund_case_for_order((int)$locked['id'],'پرداخت ترب‌پی پس از لغو سفارش؛ نیازمند بازپرداخت'); } catch (Throwable $refundError) { log_error($refundError); }
        }
        sms_notify_after_payment_completed((int)$locked['id']);
        sms_notify_payment_status((int)$locked['id'],'paid');
        return ['ok'=>true,'already'=>false,'reference'=>$reference];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function torobpay_mark_verification_pending(int $orderId, string $paymentToken, string $message): void
{
    if ($paymentToken === '' || text_length($paymentToken) > 150) return;
    $payload = json_encode(['verification_pending'=>true,'error'=>$message,'checked_at'=>date('c')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    db()->prepare("UPDATE payment_transactions SET response_json=? WHERE order_id=? AND gateway='torobpay' AND authority=? AND status='requested'")
        ->execute([$payload,$orderId,$paymentToken]);
    db()->prepare("UPDATE orders SET payment_status='pending' WHERE id=? AND gateway_reference=? AND payment_status IN ('unpaid','pending','failed')")
        ->execute([$orderId,$paymentToken]);
    admin_notify_gateway_failure($orderId,$message,'torobpay-verify-pending-'.hash('sha256',$paymentToken));
}

function torobpay_status(string $paymentToken): string
{
    if ($paymentToken === '') return 'UNKNOWN';
    try {
        $response = torobpay_api_json('GET', '/api/online/payment/v1/status?paymentToken='.rawurlencode($paymentToken));
        if (!torobpay_http_ok($response) || ($response['successful'] ?? null) !== true) return 'UNKNOWN';
        return strtoupper(trim((string)($response['response']['status'] ?? 'UNKNOWN')));
    } catch (Throwable) {
        return 'UNKNOWN';
    }
}

function torobpay_status_is_cancelled(string $status): bool
{
    return in_array(strtoupper(trim($status)), ['REVERT','CANCELLED','CANCELED'], true);
}

function torobpay_status_is_terminal_failure(string $status): bool
{
    return in_array(strtoupper(trim($status)), ['REVERT','FAILED','CANCELLED','CANCELED','EXPIRED','REJECTED'], true);
}

function torobpay_cancel(string $paymentToken): bool
{
    if ($paymentToken === '') return false;
    $response = torobpay_api_json('POST', '/api/online/payment/v1/cancel', ['paymentToken'=>$paymentToken]);
    return torobpay_http_ok($response) && ($response['successful'] ?? null) === true;
}

function torobpay_revert(string $paymentToken): bool
{
    if ($paymentToken === '') return false;
    $response = torobpay_api_json('POST', '/api/online/payment/v1/revert', ['paymentToken'=>$paymentToken]);
    return torobpay_http_ok($response) && ($response['successful'] ?? null) === true;
}

function torobpay_test_connection(): array
{
    $token = torobpay_access_token();
    return ['ok'=>true,'token_prefix'=>text_substr($token,0,8)];
}
