<?php
declare(strict_types=1);

function snappay_api_base(): string
{
    $url = rtrim(trim((string)setting('snappay_api_url','https://api.snapppay.ir')), '/');
    return $url !== '' ? $url : 'https://api.snapppay.ir';
}

function snappay_secret(string $key): string
{
    return trim(reveal_secret((string)setting($key,'')));
}

function snappay_client_id(): string { return snappay_secret('snappay_client_id_enc'); }
function snappay_client_secret(): string { return snappay_secret('snappay_client_secret_enc'); }
function snappay_username(): string { return snappay_secret('snappay_username_enc'); }
function snappay_password(): string { return snappay_secret('snappay_password_enc'); }
function snappay_title(): string { return trim((string)setting('snappay_title','پرداخت اقساطی اسنپ‌پی')) ?: 'پرداخت اقساطی اسنپ‌پی'; }
function snappay_settle_enabled(): bool { return (string)setting('snappay_settle_enabled','1') === '1'; }

function snappay_payment_method_types(): array
{
    $raw = strtoupper(trim((string)setting('snappay_payment_method_types','INSTALLMENT')));
    if ($raw === '') return [];
    $allowed = ['POSTPAID'=>true,'INSTALLMENT'=>true,'FINANCING'=>true];
    $result = [];
    foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $type) {
        $type = trim($type);
        if ($type !== '' && isset($allowed[$type])) $result[$type] = $type;
    }
    return array_values($result);
}

function snappay_merchant_transaction_id(int $paymentTransactionId): string
{
    if ($paymentTransactionId < 1) throw new RuntimeException('شناسه داخلی تراکنش اسنپ‌پی معتبر نیست.');
    $base36 = strtoupper(base_convert((string)$paymentTransactionId, 10, 36));
    // سند اسنپ‌پی: ۵ تا ۱۰ کاراکتر؛ برای شناسه‌های بلند وجود حداقل یک حرف الزامی است.
    return 'G'.str_pad($base36, 8, '0', STR_PAD_LEFT);
}

function snappay_eligibility(int $amountToman, int $timeoutSeconds = 12): array
{
    if (!snappay_enabled() || $amountToman < 1) {
        return ['eligible'=>false,'title'=>'','description'=>'','status'=>'disabled'];
    }
    $query = ['amount'=>payment_toman_to_rial($amountToman)];
    $types = snappay_payment_method_types();
    if ($types) $query['paymentMethodTypes'] = implode(',', $types);
    try {
        $response = snappay_api_json('GET', '/api/online/offer/v1/eligible?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), [], $timeoutSeconds);
        if (!snappay_http_ok($response)) {
            return ['eligible'=>false,'title'=>'','description'=>'','status'=>'api_error','message'=>snappay_error_message($response,'سرویس الیجیبل اسنپ‌پی در دسترس نیست.')];
        }
        $nested = is_array($response['response'] ?? null) ? $response['response'] : [];
        return [
            'eligible'=>(bool)($nested['eligible'] ?? false),
            'title'=>trim((string)($nested['title_message'] ?? $nested['titleMessage'] ?? $nested['title'] ?? '')),
            'description'=>trim((string)($nested['description'] ?? '')),
            'status'=>'ok',
        ];
    } catch (Throwable $e) {
        log_error($e);
        return ['eligible'=>false,'title'=>'','description'=>'','status'=>'network_error'];
    }
}

function snappay_status_details(string $paymentToken, int $timeoutSeconds = 12): array
{
    if ($paymentToken === '') return ['ok'=>false,'status'=>'UNKNOWN','transactionId'=>'','amount'=>null];
    try {
        $response = snappay_api_json('GET', '/api/online/payment/v1/status?paymentToken='.rawurlencode($paymentToken), [], $timeoutSeconds);
        if (!snappay_http_ok($response)) return ['ok'=>false,'status'=>'UNKNOWN','transactionId'=>'','amount'=>null,'response'=>$response];
        $nested = is_array($response['response'] ?? null) ? $response['response'] : [];
        $amount = $nested['amount'] ?? $response['amount'] ?? null;
        return [
            'ok'=>true,
            'status'=>strtoupper(trim((string)($nested['status'] ?? $nested['state'] ?? $response['status'] ?? $response['state'] ?? 'UNKNOWN'))),
            'transactionId'=>trim((string)($nested['transactionId'] ?? $response['transactionId'] ?? '')),
            'amount'=>$amount,
            'response'=>$response,
        ];
    } catch (Throwable $e) {
        log_error($e);
        return ['ok'=>false,'status'=>'UNKNOWN','transactionId'=>'','amount'=>null];
    }
}

function snappay_enabled(): bool
{
    return (string)setting('snappay_enabled','0') === '1'
        && snappay_client_id() !== ''
        && snappay_client_secret() !== ''
        && snappay_username() !== ''
        && snappay_password() !== '';
}

function snappay_endpoint(string $path): string
{
    return snappay_api_base() . '/' . ltrim($path, '/');
}

function snappay_http(string $method, string $url, array $payload = [], array $headers = [], bool $formEncoded = false, int $timeoutSeconds = 30): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی هاست فعال نیست.');
    $method = strtoupper($method);
    $timeoutSeconds = max(5, min(60, $timeoutSeconds));
    $curl = curl_init($url);
    $requestHeaders = array_values($headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ];
    if ($method !== 'GET') {
        if ($formEncoded) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $requestHeaders[] = 'Accept: application/json';
            $options[CURLOPT_HTTPHEADER] = array_values(array_unique($requestHeaders));
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($body)) throw new RuntimeException('ساخت درخواست اسنپ‌پی انجام نشد.');
            $options[CURLOPT_POSTFIELDS] = $body;
            $requestHeaders[] = 'Content-Type: application/json';
            $requestHeaders[] = 'Accept: application/json';
            $options[CURLOPT_HTTPHEADER] = array_values(array_unique($requestHeaders));
        }
    }
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($raw)) throw new RuntimeException('ارتباط با اسنپ‌پی برقرار نشد: '.($error ?: 'خطای شبکه'));
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('پاسخ اسنپ‌پی قابل‌خواندن نیست. کد HTTP '.$http);
    $json['_http'] = $http;
    return $json;
}

function snappay_http_ok(array $response): bool
{
    $http = (int)($response['_http'] ?? 0);
    if ($http < 200 || $http >= 300) return false;
    if (array_key_exists('successful',$response) && !($response['successful'] ?? false)) return false;
    return true;
}

function snappay_error_message(array $response, string $fallback): string
{
    $candidates = [
        $response['errorData']['message'] ?? null,
        $response['error']['message'] ?? null,
        $response['result']['message'] ?? null,
        $response['message'] ?? null,
        $response['error_description'] ?? null,
    ];
    foreach ($candidates as $value) {
        if (is_scalar($value) && trim((string)$value) !== '') return text_substr(trim((string)$value), 0, 500);
    }
    return $fallback;
}

function snappay_token_cache_path(): string
{
    return BASE_PATH . '/storage/snappay-access-token.json';
}

function snappay_token_cache_fingerprint(string $clientId, string $username): string
{
    return hash('sha256', snappay_api_base().'|'.$clientId.'|'.$username);
}

function snappay_cached_access_token(string $fingerprint): string
{
    $raw = @file_get_contents(snappay_token_cache_path());
    $cached = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cached)
        || !hash_equals($fingerprint, (string)($cached['fingerprint'] ?? ''))
        || (int)($cached['expires_at'] ?? 0) <= time() + 60) {
        return '';
    }
    return reveal_secret((string)($cached['access_token'] ?? ''));
}

function snappay_store_access_token(string $token, string $fingerprint, int $expiresIn): void
{
    $path = snappay_token_cache_path();
    $directory = dirname($path);
    if ((!is_dir($directory) && !@mkdir($directory, 0755, true)) || !is_writable($directory)) return;
    try {
        $payload = json_encode([
            'fingerprint' => $fingerprint,
            'access_token' => protect_secret($token),
            'expires_at' => time() + max(1, min(86400, $expiresIn)),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) return;
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temporary, $payload, LOCK_EX) === strlen($payload)) {
            @chmod($temporary, 0640);
            if (!@rename($temporary, $path)) @unlink($temporary);
        } else {
            @unlink($temporary);
        }
    } catch (Throwable $cacheError) {
        log_error($cacheError);
    }
}

function snappay_clear_access_token_cache(): void
{
    $path = snappay_token_cache_path();
    if (is_file($path)) @unlink($path);
}

function snappay_access_token(bool $forceRefresh = false): string
{
    $clientId = snappay_client_id();
    $clientSecret = snappay_client_secret();
    $username = snappay_username();
    $password = snappay_password();
    if ($clientId === '' || $clientSecret === '' || $username === '' || $password === '') {
        throw new RuntimeException('اطلاعات اتصال اسنپ‌پی در پنل مدیریت کامل نشده است.');
    }
    $fingerprint = snappay_token_cache_fingerprint($clientId, $username);
    if (!$forceRefresh) {
        $cached = snappay_cached_access_token($fingerprint);
        if ($cached !== '') return $cached;
    }

    $lock = @fopen(snappay_token_cache_path().'.lock', 'c+');
    if (is_resource($lock)) @flock($lock, LOCK_EX);
    try {
        if (!$forceRefresh) {
            $cached = snappay_cached_access_token($fingerprint);
            if ($cached !== '') return $cached;
        }
        $response = snappay_http('POST', snappay_endpoint('/api/online/v1/oauth/token'), [
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
            'scope' => 'online-merchant',
        ], [
            'Authorization: Basic '.base64_encode($clientId.':'.$clientSecret),
            'Accept: application/json',
        ], true);
        if (!snappay_http_ok($response)) {
            throw new RuntimeException(snappay_error_message($response, 'احراز هویت اسنپ‌پی انجام نشد.'));
        }
        $token = trim((string)($response['access_token'] ?? ''));
        if ($token === '') throw new RuntimeException('اسنپ‌پی Access Token معتبر برنگرداند.');
        snappay_store_access_token($token, $fingerprint, (int)($response['expires_in'] ?? 300));
        return $token;
    } finally {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}

function snappay_api_json(string $method, string $path, array $payload = [], int $timeoutSeconds = 30): array
{
    $token = snappay_access_token();
    $response = snappay_http($method, snappay_endpoint($path), $payload, [
        'Authorization: Bearer '.$token,
        'Accept: application/json',
    ], false, $timeoutSeconds);
    if ((int)($response['_http'] ?? 0) === 401) {
        snappay_clear_access_token_cache();
        $token = snappay_access_token(true);
        $response = snappay_http($method, snappay_endpoint($path), $payload, [
            'Authorization: Bearer '.$token,
            'Accept: application/json',
        ], false, $timeoutSeconds);
    }
    return $response;
}

function snappay_payment_page_url(array $response): string
{
    $nested = is_array($response['response'] ?? null) ? $response['response'] : [];
    $url = trim((string)($nested['paymentPageUrl'] ?? $response['paymentPageUrl'] ?? $nested['payment_page_url'] ?? $response['payment_page_url'] ?? $nested['redirectUrl'] ?? $response['redirectUrl'] ?? $nested['paymentUrl'] ?? $response['paymentUrl'] ?? $nested['url'] ?? $response['url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('آدرس صفحه پرداخت اسنپ‌پی معتبر نیست.');
    }
    return $url;
}

function snappay_payment_token(array $response): string
{
    $nested = is_array($response['response'] ?? null) ? $response['response'] : [];
    $token = trim((string)($nested['paymentToken'] ?? $response['paymentToken'] ?? $nested['payment_token'] ?? $response['payment_token'] ?? ''));
    if ($token === '' || text_length($token) > 150 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
        throw new RuntimeException('توکن پرداخت اسنپ‌پی معتبر نیست.');
    }
    return $token;
}

function snappay_payment_redirect_url(string $paymentUrl, string $paymentToken): string
{
    $fragment = '';
    $hashPos = strpos($paymentUrl, '#');
    if ($hashPos !== false) {
        $fragment = substr($paymentUrl, $hashPos);
        $paymentUrl = substr($paymentUrl, 0, $hashPos);
    }
    $parts = explode('?', $paymentUrl, 2);
    $base = $parts[0];
    $query = $parts[1] ?? '';
    $params = [];
    if ($query !== '') parse_str($query, $params);
    $params['payment_token'] = $paymentToken;
    return $base.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986).$fragment;
}

function snappay_cart_from_rows(array $order, array $rows): array
{
    if ($rows === []) throw new RuntimeException('اقلام سفارش برای ارسال به اسنپ‌پی پیدا نشد.');

    $cartItems = [];
    $calculatedSubtotal = 0;
    foreach ($rows as $row) {
        $quantity = (int)$row['quantity'];
        $unitPrice = (int)$row['unit_price'];
        if ($quantity < 1 || $unitPrice < 1 || $unitPrice > intdiv(PHP_INT_MAX, $quantity)) {
            throw new RuntimeException('مبلغ یکی از اقلام سفارش برای اسنپ‌پی معتبر نیست.');
        }
        $lineTotal = $unitPrice * $quantity;
        if (isset($row['line_total']) && (int)$row['line_total'] !== $lineTotal) {
            throw new RuntimeException('جمع یکی از اقلام سفارش برای اسنپ‌پی معتبر نیست.');
        }
        if ($calculatedSubtotal > PHP_INT_MAX - $lineTotal) throw new RuntimeException('جمع اقلام سفارش بیش از محدوده مجاز است.');
        $calculatedSubtotal += $lineTotal;
        $variantTitle = trim((string)($row['variant_title'] ?? ''));
        $name = trim((string)$row['title']).($variantTitle !== '' ? ' - '.$variantTitle : '');
        $cartItems[] = [
            'id' => (int)$row['id'],
            'name' => text_substr($name, 0, 255),
            'count' => $quantity,
            'amount' => payment_toman_to_rial($unitPrice),
            'category' => text_substr((string)($row['category_slug'] ?? 'general'), 0, 100),
            'commissionType' => 100,
        ];
    }

    $shipping = max(0, (int)($order['shipping_amount'] ?? 0));
    $cartTotal = $calculatedSubtotal + $shipping;
    return [[
        'cartId' => (string)$order['order_number'],
        'totalAmount' => payment_toman_to_rial($cartTotal),
        'shippingAmount' => $shipping > 0 ? payment_toman_to_rial($shipping) : 0,
        'isShipmentIncluded' => $shipping === 0,
        'taxAmount' => 0,
        'isTaxIncluded' => true,
        'cartItems' => $cartItems,
    ]];
}

function snappay_cart_for_order(array $order): array
{
    $stmt = db()->prepare('SELECT oi.id,oi.product_id,oi.sku,oi.title,oi.variant_title,oi.unit_price,oi.quantity,oi.line_total,COALESCE(c.slug,\'general\') category_slug FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE oi.order_id=? ORDER BY oi.id');
    $stmt->execute([(int)$order['id']]);
    $rows = $stmt->fetchAll();
    $cart = snappay_cart_from_rows($order, $rows);
    $calculatedSubtotal = 0;
    foreach ($rows as $row) $calculatedSubtotal += (int)$row['unit_price'] * (int)$row['quantity'];
    if ($calculatedSubtotal !== (int)$order['subtotal']) {
        throw new RuntimeException('جمع اقلام سفارش با مبلغ ثبت‌شده سفارش یکسان نیست.');
    }
    return $cart;
}

function snappay_start_for_order(array $order): string
{
    if ((int)($order['total'] ?? 0) > 0) release_expired_unpaid_orders(25);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $lock->execute([(int)$order['id']]);
        $locked = $lock->fetch();
        if (!$locked) throw new RuntimeException('سفارش پیدا نشد.');
        if ((string)$locked['payment_method'] !== 'snappay') throw new RuntimeException('روش پرداخت این سفارش اسنپ‌پی نیست.');
        if ((string)$locked['payment_status'] === 'paid') {
            $pdo->commit();
            return app_url('order-success.php?token='.$locked['public_token']);
        }
        if ((int)$locked['total'] < 1) throw new RuntimeException('این سفارش نیازی به پرداخت اسنپ‌پی ندارد.');
        if (!snappay_enabled()) throw new RuntimeException('درگاه اسنپ‌پی از پنل مدیریت فعال یا تکمیل نشده است.');
        if (!in_array((string)$locked['order_status'], ['new','pending_payment'], true)) throw new RuntimeException('این سفارش دیگر قابل پرداخت نیست.');
        if (!in_array((string)$locked['payment_status'], ['unpaid','failed','pending'], true)) throw new RuntimeException('وضعیت پرداخت این سفارش اجازه شروع تراکنش جدید نمی‌دهد.');
        $eligibility = snappay_eligibility((int)$locked['total']);
        if (empty($eligibility['eligible'])) throw new RuntimeException('اسنپ‌پی این مبلغ را در حال حاضر واجد شرایط پرداخت اعلام نکرده است.');

        $recent = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='snappay' AND status IN ('created','requested') ORDER BY id DESC LIMIT 1 FOR UPDATE");
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
                    try { $oldUrl = snappay_payment_page_url($oldResponse); } catch (Throwable) {}
                    if ($oldUrl !== '') {
                        $oldPaymentToken = trim((string)($existing['authority'] ?? ''));
                        $pdo->commit();
                        return $oldPaymentToken !== '' ? snappay_payment_redirect_url($oldUrl, $oldPaymentToken) : $oldUrl;
                    }
                }
            }
            if ((string)$existing['status'] === 'created' && $age < 90) throw new RuntimeException('درخواست پرداخت دیگری در حال ایجاد است؛ چند لحظه بعد دوباره تلاش کنید.');
            $pdo->prepare("UPDATE payment_transactions SET status='cancelled',response_json=COALESCE(response_json,?) WHERE id=? AND status IN ('created','requested')")
                ->execute([json_encode(['reason'=>'expired_before_retry','expired_at'=>date('c')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), (int)$existing['id']]);
        }

        $order = $locked;
        $pdo->prepare("INSERT INTO payment_transactions(order_id,gateway,amount,status,request_json) VALUES(?, 'snappay', ?, 'created', NULL)")
            ->execute([(int)$order['id'], (int)$order['total']]);
        $transactionId = (int)$pdo->lastInsertId();
        $callback = app_url('snappay-callback.php');
        $merchantTransactionId = snappay_merchant_transaction_id($transactionId);
        $payload = [
            'amount' => payment_toman_to_rial((int)$order['total']),
            'returnURL' => $callback,
            'transactionId' => $merchantTransactionId,
            'externalSourceAmount' => 0,
            'discountAmount' => (int)$order['discount_amount'] > 0 ? payment_toman_to_rial((int)$order['discount_amount']) : 0,
            'cartList' => snappay_cart_for_order($order),
            'mobile' => (string)$order['phone'],
        ];
        $forcedTypes = snappay_payment_method_types();
        if ($forcedTypes) $payload['forcedPaymentMethodTypes'] = $forcedTypes;
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
        $response = snappay_api_json('POST', '/api/online/payment/v1/token', $payload);
        if (!snappay_http_ok($response)) throw new RuntimeException(snappay_error_message($response, 'ایجاد پرداخت اسنپ‌پی انجام نشد.'));
        $paymentToken = snappay_payment_token($response);
        $paymentUrl = snappay_payment_page_url($response);
        $pdo->prepare("UPDATE payment_transactions SET authority=?,status='requested',response_json=? WHERE id=?")
            ->execute([$paymentToken, json_encode($response, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $transactionId]);
        $pdo->prepare("UPDATE orders SET payment_status='pending',gateway_reference=?,order_status=IF(order_status IN ('new','pending_payment'),'pending_payment',order_status) WHERE id=? AND payment_status<>'paid'")
            ->execute([$paymentToken, (int)$order['id']]);
        $_SESSION['_snappay_last_payment'] = ['order_token'=>(string)$order['public_token'],'payment_token'=>$paymentToken,'time'=>time()];
        return snappay_payment_redirect_url($paymentUrl, $paymentToken);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE payment_transactions SET status='failed',response_json=? WHERE id=?")
            ->execute([json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE), $transactionId]);
        $pdo->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND payment_status<>'paid'")->execute([(int)$order['id']]);
        admin_notify_gateway_failure((int)$order['id'], $e->getMessage(), 'snappay-request-'.$transactionId);
        throw $e;
    }
}

function snappay_latest_requested_transaction(int $orderId): ?array
{
    $stmt = db()->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='snappay' AND status IN ('requested','paid') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

function snappay_callback_transaction(int $orderId, int $transactionId = 0): ?array
{
    if ($transactionId > 0) {
        $stmt = db()->prepare("SELECT * FROM payment_transactions WHERE id=? AND order_id=? AND gateway='snappay' LIMIT 1");
        $stmt->execute([$transactionId,$orderId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    // سازگاری با پرداخت‌هایی که قبل از اضافه‌شدن tx به Callback ساخته شده‌اند.
    return snappay_latest_requested_transaction($orderId);
}

function snappay_verify_order(array $order, string $paymentToken): array
{
    if ($paymentToken === '' || text_length($paymentToken) > 150) throw new RuntimeException('توکن پرداخت اسنپ‌پی معتبر نیست.');
    $lockPath = BASE_PATH.'/storage/snappay-verify-'.hash('sha256', $paymentToken).'.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) @fclose($lock);
        throw new RuntimeException('قفل بررسی تراکنش اسنپ‌پی ایجاد نشد؛ دوباره تلاش کنید.');
    }
    try {
        return snappay_verify_order_locked($order, $paymentToken);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function snappay_verify_order_locked(array $order, string $paymentToken): array
{
    if ($paymentToken === '' || text_length($paymentToken) > 150) throw new RuntimeException('توکن پرداخت اسنپ‌پی معتبر نیست.');
    $pre = db()->prepare("SELECT pt.*,o.total,o.payment_status,o.gateway_reference FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.order_id=? AND pt.gateway='snappay' AND pt.authority=? ORDER BY pt.id DESC LIMIT 1");
    $pre->execute([(int)$order['id'], $paymentToken]);
    $preRow = $pre->fetch();
    if (!$preRow) throw new RuntimeException('تراکنش اسنپ‌پی مربوط به سفارش پیدا نشد.');
    if ((int)$preRow['amount'] !== (int)$preRow['total']) throw new RuntimeException('مبلغ تراکنش اسنپ‌پی با سفارش تطابق ندارد.');
    if ((string)$preRow['payment_status'] === 'paid' || (string)$preRow['status'] === 'paid') {
        sms_notify_after_payment_completed((int)$order['id']);
        sms_notify_payment_status((int)$order['id'],'paid');
        return ['ok'=>true,'already'=>true,'reference'=>(string)($preRow['reference_id'] ?: $preRow['gateway_reference'])];
    }

    $previousResponse = json_decode((string)($preRow['response_json'] ?? ''), true);
    if (!is_array($previousResponse)) $previousResponse = [];
    $previousRemoteStatus = strtoupper((string)($previousResponse['remote_status'] ?? ''));
    $verifyAlreadySucceeded = !empty($previousResponse['verify_completed_at']) || is_array($previousResponse['verify'] ?? null) || $previousRemoteStatus === 'VERIFY' || in_array($previousRemoteStatus, ['COMPLETED','SETTLED','SETTLE','PAID'], true);
    $settleAlreadySucceeded = !empty($previousResponse['settle_completed_at']) || in_array($previousRemoteStatus, ['COMPLETED','SETTLED','SETTLE','PAID'], true);
    if ($verifyAlreadySucceeded && is_array($previousResponse['verify'] ?? null)) {
        $verify = $previousResponse['verify'];
    } elseif ($verifyAlreadySucceeded) {
        $verify = ['_http'=>200,'successful'=>true,'response'=>['status'=>$settleAlreadySucceeded ? 'SETTLE' : 'VERIFY']];
    } else {
        $verify = null;
        $verifyError = null;
        try {
            $candidate = snappay_api_json('POST', '/api/online/payment/v1/verify', ['paymentToken'=>$paymentToken], 30);
            if (snappay_http_ok($candidate)) $verify = $candidate;
            else $verifyError = snappay_error_message($candidate, 'تراکنش اسنپ‌پی تأیید نشد.');
        } catch (Throwable $verifyException) {
            $verifyError = $verifyException->getMessage();
            log_error($verifyException);
        }
        if (!is_array($verify)) {
            // طبق داکیومنت: در Timeout/عدم پاسخ Verify حتماً Status خوانده می‌شود.
            $statusInfo = snappay_status_details($paymentToken, 12);
            $remoteStatus = strtoupper((string)($statusInfo['status'] ?? 'UNKNOWN'));
            if (in_array($remoteStatus, ['VERIFY','SETTLE'], true)) {
                $verify = ['_http'=>200,'successful'=>true,'response'=>[
                    'status'=>$remoteStatus,
                    'transactionId'=>(string)($statusInfo['transactionId'] ?? ''),
                    'amount'=>$statusInfo['amount'] ?? null,
                ]];
                if ($remoteStatus === 'SETTLE') $settleAlreadySucceeded = true;
            } elseif ($remoteStatus === 'PENDING') {
                // فقط در حالت PENDING یک بار Verify تکرار می‌شود.
                $retry = snappay_api_json('POST', '/api/online/payment/v1/verify', ['paymentToken'=>$paymentToken], 30);
                if (!snappay_http_ok($retry)) throw new RuntimeException(snappay_error_message($retry, 'تراکنش اسنپ‌پی پس از بررسی وضعیت نیز تأیید نشد.'));
                $verify = $retry;
            } else {
                throw new RuntimeException($verifyError ?: 'وضعیت تراکنش اسنپ‌پی پس از Verify نامشخص است.');
            }
        }
    }
    // Verify باید فقط یک‌بار انجام شود. موفقیت آن را پیش از Settle ثبت می‌کنیم تا
    // در Timeout/خطای Settle، درخواست بعدی مستقیماً از Status/Settle ادامه پیدا کند.
    if (!$verifyAlreadySucceeded) {
        $verifyMarker = $previousResponse;
        $verifyMarker['verify'] = $verify;
        $verifyMarker['verify_completed_at'] = date('c');
        $verifyMarker['remote_status'] = $settleAlreadySucceeded ? 'SETTLE' : 'VERIFY';
        if ($settleAlreadySucceeded) $verifyMarker['settle_completed_at'] = date('c');
        db()->prepare("UPDATE payment_transactions SET response_json=? WHERE id=? AND status='requested'")
            ->execute([json_encode($verifyMarker, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE), (int)$preRow['id']]);
        $previousResponse = $verifyMarker;
        $verifyAlreadySucceeded = true;
    }

    $verifyResponse = is_array($verify['response'] ?? null) ? $verify['response'] : [];
    $verifiedAmountRaw = $verifyResponse['amount'] ?? $verify['amount'] ?? null;
    if ($verifiedAmountRaw !== null) {
        $verifiedAmountText = trim((string)$verifiedAmountRaw);
        if (!preg_match('/^\d+$/', $verifiedAmountText)) throw new RuntimeException('مبلغ برگشتی اسنپ‌پی معتبر نیست.');
        $verifiedAmount = (int)$verifiedAmountText;
        $expectedRial = payment_toman_to_rial((int)$preRow['total']);
        if ($verifiedAmount !== $expectedRial) {
            throw new RuntimeException('مبلغ تأییدشده اسنپ‌پی با مبلغ سفارش یکسان نیست.');
        }
    }
    $request = json_decode((string)($preRow['request_json'] ?? ''), true);
    $expectedTransactionId = is_array($request) ? trim((string)($request['transactionId'] ?? '')) : '';
    $verifiedTransactionId = trim((string)($verifyResponse['transactionId'] ?? $verify['transactionId'] ?? ''));
    if ($expectedTransactionId !== '' && $verifiedTransactionId !== '' && !hash_equals($expectedTransactionId, $verifiedTransactionId)) {
        throw new RuntimeException('شناسه تراکنش تأییدشده اسنپ‌پی با سفارش یکسان نیست.');
    }

    $settle = $settleAlreadySucceeded && is_array($previousResponse['settle'] ?? null) ? $previousResponse['settle'] : null;
    if (!$settleAlreadySucceeded) {
        $settleError = null;
        try {
            $candidate = snappay_api_json('POST', '/api/online/payment/v1/settle', ['paymentToken'=>$paymentToken], 30);
            if (snappay_http_ok($candidate)) $settle = $candidate;
            else $settleError = snappay_error_message($candidate, 'تأیید نهایی (Settle) اسنپ‌پی انجام نشد.');
        } catch (Throwable $settleException) {
            $settleError = $settleException->getMessage();
            log_error($settleException);
        }
        if (!is_array($settle)) {
            // طبق داکیومنت: شکست/عدم پاسخ Settle با Status بازیابی می‌شود.
            $statusInfo = snappay_status_details($paymentToken, 12);
            $remoteStatus = strtoupper((string)($statusInfo['status'] ?? 'UNKNOWN'));
            if ($remoteStatus === 'SETTLE') {
                $settle = ['_http'=>200,'successful'=>true,'response'=>[
                    'status'=>'SETTLE',
                    'transactionId'=>(string)($statusInfo['transactionId'] ?? ''),
                    'amount'=>$statusInfo['amount'] ?? null,
                ]];
            } elseif ($remoteStatus === 'VERIFY') {
                // اگر Status هنوز VERIFY باشد، Settle یک بار دیگر فراخوانی می‌شود.
                $retry = snappay_api_json('POST', '/api/online/payment/v1/settle', ['paymentToken'=>$paymentToken], 30);
                if (!snappay_http_ok($retry)) throw new RuntimeException(snappay_error_message($retry, 'Settle اسنپ‌پی پس از بررسی وضعیت نیز انجام نشد.'));
                $settle = $retry;
            } else {
                throw new RuntimeException($settleError ?: 'وضعیت تراکنش اسنپ‌پی پس از Settle نامشخص است.');
            }
        }
        $settleAlreadySucceeded = true;
        $settleMarker = $previousResponse;
        $settleMarker['verify'] = $verify;
        $settleMarker['verify_completed_at'] = $settleMarker['verify_completed_at'] ?? date('c');
        $settleMarker['settle'] = $settle;
        $settleMarker['settle_completed_at'] = date('c');
        $settleMarker['remote_status'] = 'SETTLE';
        db()->prepare("UPDATE payment_transactions SET response_json=? WHERE id=? AND status='requested'")
            ->execute([json_encode($settleMarker, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), (int)$preRow['id']]);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $lock->execute([(int)$order['id']]);
        $locked = $lock->fetch();
        if (!$locked) throw new RuntimeException('سفارش پیدا نشد.');
        $tx = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id=? AND gateway='snappay' AND authority=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $tx->execute([(int)$order['id'], $paymentToken]);
        $transaction = $tx->fetch();
        if (!$transaction) throw new RuntimeException('تراکنش اسنپ‌پی مربوط به سفارش پیدا نشد.');
        if ((int)$transaction['amount'] !== (int)$locked['total']) throw new RuntimeException('مبلغ تراکنش با سفارش تطابق ندارد.');
        if ((string)$locked['payment_status'] === 'paid' || (string)$transaction['status'] === 'paid') {
            $pdo->commit();
            return ['ok'=>true,'already'=>true,'reference'=>(string)($transaction['reference_id'] ?: $locked['gateway_reference'])];
        }
        $reference = $verifiedTransactionId !== '' ? $verifiedTransactionId : ($expectedTransactionId !== '' ? $expectedTransactionId : $paymentToken);
        $storedResponse = ['verify'=>$verify];
        if ($settle !== null) $storedResponse['settle'] = $settle;
        $storedResponse['settle_completed_at'] = date('c');
        $pdo->prepare("UPDATE payment_transactions SET status='paid',reference_id=?,response_json=?,verified_at=NOW() WHERE id=?")
            ->execute([$reference, json_encode($storedResponse, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), (int)$transaction['id']]);
        $oldStatus = (string)$locked['order_status'];
        $newStatus = in_array($oldStatus,['new','pending_payment'],true) ? 'reviewing' : $oldStatus;
        if ($oldStatus === 'cancelled') accounting_cancelled_order_late_payment($pdo,(int)$locked['id'],(int)$locked['total']);
        else { accounting_ensure_order_sale($pdo,(int)$locked['id']); accounting_order_paid($pdo,(int)$locked['id'],(int)$locked['total']); }
        $pdo->prepare("UPDATE orders SET payment_status='paid',order_status=?,gateway_reference=?,paid_at=COALESCE(paid_at,NOW()) WHERE id=?")
            ->execute([$newStatus,$reference,(int)$locked['id']]);
        $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')
            ->execute([(int)$locked['id'],$oldStatus,$newStatus,$locked['payment_status'],'paid','تأیید خودکار پرداخت اسنپ‌پی']);
        $pdo->commit();
        admin_notify_payment_success((int)$locked['id']);
        if ($oldStatus === 'cancelled') {
            try { create_admin_refund_case_for_order((int)$locked['id'],'پرداخت اسنپ‌پی پس از لغو سفارش؛ نیازمند بازپرداخت'); } catch (Throwable $refundError) { log_error($refundError); }
        }
        sms_notify_after_payment_completed((int)$locked['id']);
        sms_notify_payment_status((int)$locked['id'],'paid');
        return ['ok'=>true,'already'=>false,'reference'=>$reference];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function snappay_mark_verification_pending(int $orderId, string $paymentToken, string $message): void
{
    if ($paymentToken === '' || text_length($paymentToken) > 150) return;
    $existingStmt = db()->prepare("SELECT response_json FROM payment_transactions WHERE order_id=? AND gateway='snappay' AND authority=? AND status='requested' ORDER BY id DESC LIMIT 1");
    $existingStmt->execute([$orderId,$paymentToken]);
    $existing = json_decode((string)($existingStmt->fetchColumn() ?: ''), true);
    if (!is_array($existing)) $existing = [];
    $existing['verification_pending'] = true;
    $existing['error'] = text_substr($message, 0, 1000);
    $existing['checked_at'] = date('c');
    $payload = json_encode($existing, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    db()->prepare("UPDATE payment_transactions SET response_json=? WHERE order_id=? AND gateway='snappay' AND authority=? AND status='requested'")
        ->execute([$payload,$orderId,$paymentToken]);
    db()->prepare("UPDATE orders SET payment_status='pending' WHERE id=? AND gateway_reference=? AND payment_status IN ('unpaid','pending','failed')")
        ->execute([$orderId,$paymentToken]);
    admin_notify_gateway_failure($orderId,$message,'snappay-verify-pending-'.hash('sha256',$paymentToken));
}

function snappay_status(string $paymentToken, int $timeoutSeconds = 12): string
{
    return (string)(snappay_status_details($paymentToken, $timeoutSeconds)['status'] ?? 'UNKNOWN');
}

function snappay_reconcile_pending_transactions(int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $result = ['checked'=>0,'paid'=>0,'failed'=>0,'pending'=>0,'errors'=>0];
    if (!snappay_enabled()) return $result + ['skipped'=>true];

    $rows = db()->query("SELECT pt.id,pt.order_id,pt.authority,pt.request_json,pt.amount,o.total FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.gateway='snappay' AND pt.status='requested' AND o.payment_status='pending' AND pt.created_at<=DATE_SUB(NOW(),INTERVAL 2 MINUTE) ORDER BY pt.id LIMIT ".$limit)->fetchAll();
    $paidStates = ['SUCCESS','SUCCESSFUL','AUTHORIZED','AUTHORISED','VERIFIED','VERIFY','COMPLETED','SETTLED','SETTLE','PAID'];
    $settledStates = ['COMPLETED','SETTLED','SETTLE','PAID'];
    $failedStates = ['FAILED','CANCELLED','CANCELED','CANCEL','EXPIRED','REJECTED','REVERTED','REVERT'];
    $deadline = microtime(true) + 25;
    foreach ($rows as $row) {
        if (microtime(true) >= $deadline) {$result['pending'] += max(0,count($rows)-$result['checked']);break;}
        $paymentToken = trim((string)$row['authority']);
        if ($paymentToken === '') continue;
        $result['checked']++;
        try {
            $statusInfo = snappay_status_details($paymentToken, 8);
            if (empty($statusInfo['ok'])) { $result['pending']++; continue; }
            $status = strtoupper((string)($statusInfo['status'] ?? 'UNKNOWN'));
            $request = json_decode((string)($row['request_json'] ?? ''), true);
            $expectedTransactionId = is_array($request) ? trim((string)($request['transactionId'] ?? '')) : '';
            $remoteTransactionId = trim((string)($statusInfo['transactionId'] ?? ''));
            if ($expectedTransactionId !== '' && $remoteTransactionId !== '' && !hash_equals($expectedTransactionId, $remoteTransactionId)) {
                throw new RuntimeException('شناسه تراکنش Get Payment Status اسنپ‌پی با سفارش یکسان نیست.');
            }
            $remoteAmount = $statusInfo['amount'] ?? null;
            if ($remoteAmount !== null && (string)$remoteAmount !== '') {
                $amountText = trim((string)$remoteAmount);
                if (!preg_match('/^\d+$/', $amountText) || (int)$amountText !== payment_toman_to_rial((int)$row['total'])) {
                    throw new RuntimeException('مبلغ Get Payment Status اسنپ‌پی با سفارش یکسان نیست.');
                }
            }
            if (in_array($status, $failedStates, true)) {
                db()->prepare("UPDATE payment_transactions SET status='failed',response_json=? WHERE id=? AND status='requested'")
                    ->execute([json_encode(['reconciled_at'=>date('c'),'status'=>$status], JSON_UNESCAPED_SLASHES), (int)$row['id']]);
                db()->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND payment_status='pending'")->execute([(int)$row['order_id']]);
                $result['failed']++;
                continue;
            }
            if (!in_array($status, $paidStates, true)) {
                $result['pending']++;
                continue;
            }
            if (in_array($status, $settledStates, true) || $status === 'VERIFY') {
                $existingResponseStmt = db()->prepare('SELECT response_json FROM payment_transactions WHERE id=? LIMIT 1');
                $existingResponseStmt->execute([(int)$row['id']]);
                $marker = json_decode((string)($existingResponseStmt->fetchColumn() ?: ''), true);
                if (!is_array($marker)) $marker = [];
                $marker['remote_status'] = $status;
                $marker['reconciled_at'] = date('c');
                if ($status === 'VERIFY') $marker['verify_completed_at'] = $marker['verify_completed_at'] ?? date('c');
                if (in_array($status, $settledStates, true)) $marker['settle_completed_at'] = $marker['settle_completed_at'] ?? date('c');
                db()->prepare("UPDATE payment_transactions SET response_json=? WHERE id=? AND status='requested'")
                    ->execute([json_encode($marker, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE), (int)$row['id']]);
            }
            $orderStmt = db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
            $orderStmt->execute([(int)$row['order_id']]);
            $order = $orderStmt->fetch();
            if (!$order) throw new RuntimeException('سفارش تراکنش در حال تطبیق پیدا نشد.');
            snappay_verify_order($order, $paymentToken);
            $result['paid']++;
        } catch (Throwable $exception) {
            $result['errors']++;
            log_error($exception);
        }
    }
    return $result;
}

function snappay_cancel(string $paymentToken): bool
{
    if ($paymentToken === '') return false;
    $response = snappay_api_json('POST', '/api/online/payment/v1/cancel', ['paymentToken'=>$paymentToken]);
    return snappay_http_ok($response);
}

function snappay_revert(string $paymentToken): bool
{
    if ($paymentToken === '') return false;
    $response = snappay_api_json('POST', '/api/online/payment/v1/revert', ['paymentToken'=>$paymentToken]);
    return snappay_http_ok($response);
}


function snappay_latest_paid_transaction_for_order(PDO $pdo, int $orderId, bool $forUpdate = false): ?array
{
    $sql = "SELECT * FROM payment_transactions WHERE order_id=? AND gateway='snappay' AND status IN ('paid','partially_refunded') ORDER BY id DESC LIMIT 1".($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function snappay_transaction_reference(array $transaction): string
{
    $reference = trim((string)($transaction['reference_id'] ?? ''));
    if ($reference !== '') return $reference;
    $request = json_decode((string)($transaction['request_json'] ?? ''), true);
    return is_array($request) ? trim((string)($request['transactionId'] ?? '')) : '';
}

function snappay_validate_remote_transaction(array $response, string $expectedTransactionId): void
{
    if ($expectedTransactionId === '') return;
    $nested = is_array($response['response'] ?? null) ? $response['response'] : [];
    $actual = trim((string)($nested['transactionId'] ?? $response['transactionId'] ?? ''));
    if ($actual !== '' && !hash_equals($expectedTransactionId, $actual)) {
        throw new RuntimeException('شناسه تراکنش برگشتی اسنپ‌پی با سفارش یکسان نیست.');
    }
}

function snappay_recalculate_order_discount(PDO $pdo, array $order, int $newSubtotal): int
{
    $newSubtotal = max(0, $newSubtotal);
    $oldDiscount = max(0, (int)($order['discount_amount'] ?? 0));
    if ($newSubtotal === 0 || $oldDiscount === 0) return 0;
    $couponId = (int)($order['coupon_id'] ?? 0);
    if ($couponId < 1) return min($newSubtotal, $oldDiscount);

    $stmt = $pdo->prepare('SELECT * FROM coupons WHERE id=? LIMIT 1');
    $stmt->execute([$couponId]);
    $coupon = $stmt->fetch();
    if (!$coupon) return min($newSubtotal, $oldDiscount);
    if ($newSubtotal < (int)($coupon['minimum_order'] ?? 0)) return 0;
    if ((string)$coupon['discount_type'] === 'percent') {
        $percent = max(0, min(100, (int)$coupon['discount_value']));
        $discount = (int)floor($newSubtotal * $percent / 100);
    } else {
        $discount = max(0, (int)$coupon['discount_value']);
    }
    if ($coupon['maximum_discount'] !== null) $discount = min($discount, (int)$coupon['maximum_discount']);
    return max(0, min($newSubtotal, $discount));
}

function snappay_update_order(int $orderId, array $requestedQuantities): array
{
    if ($orderId < 1) throw new RuntimeException('سفارش معتبر نیست.');
    $lockPath = BASE_PATH.'/storage/snappay-update-'.hash('sha256', (string)$orderId).'.lock';
    $fileLock = @fopen($lockPath, 'c+');
    if (!is_resource($fileLock) || !@flock($fileLock, LOCK_EX)) {
        if (is_resource($fileLock)) @fclose($fileLock);
        throw new RuntimeException('قفل بروزرسانی سفارش اسنپ‌پی ایجاد نشد؛ دوباره تلاش کنید.');
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) throw new RuntimeException('سفارش پیدا نشد.');
        if ((string)$order['payment_method'] !== 'snappay') throw new RuntimeException('این سفارش مربوط به اسنپ‌پی نیست.');
        if (!in_array((string)$order['payment_status'], ['paid','partially_refunded'], true)) throw new RuntimeException('Update فقط روی سفارش پرداخت و Settle‌شده اسنپ‌پی مجاز است.');
        if (in_array((string)$order['order_status'], ['cancelled','refunded','returned'], true)) throw new RuntimeException('این سفارش دیگر قابل Update نیست.');

        $transaction = snappay_latest_paid_transaction_for_order($pdo, $orderId, true);
        if (!$transaction) throw new RuntimeException('تراکنش موفق اسنپ‌پی برای سفارش پیدا نشد.');
        $paymentToken = trim((string)$transaction['authority']);
        if ($paymentToken === '') throw new RuntimeException('Payment Token اسنپ‌پی برای سفارش پیدا نشد.');

        $savedResponse = json_decode((string)($transaction['response_json'] ?? ''), true);
        if (!is_array($savedResponse)) $savedResponse = [];
        $updates = is_array($savedResponse['updates'] ?? null) ? $savedResponse['updates'] : [];
        if ($updates) {
            $last = end($updates);
            $lastTs = is_array($last) ? strtotime((string)($last['completed_at'] ?? '')) : false;
            if ($lastTs && time() - $lastTs < 30) {
                $remaining = 30 - (time() - $lastTs);
                throw new RuntimeException('برای Update بعدی اسنپ‌پی '.max(1,$remaining).' ثانیه صبر کنید.');
            }
        }

        $itemsStmt = $pdo->prepare("SELECT oi.*,COALESCE(c.slug,'general') category_slug FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE oi.order_id=? ORDER BY oi.id FOR UPDATE");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();
        if (!$items) throw new RuntimeException('قلمی برای Update در سفارش وجود ندارد.');

        $normalized = [];
        foreach ($requestedQuantities as $itemId=>$qty) {
            $itemId = (int)$itemId;
            if ($itemId < 1) continue;
            if (is_array($qty)) continue;
            $qtyText = normalize_digits(trim((string)$qty));
            if (!preg_match('/^\d+$/', $qtyText)) throw new RuntimeException('تعداد جدید یکی از اقلام معتبر نیست.');
            $normalized[$itemId] = (int)$qtyText;
        }

        $newRows = [];
        $changes = [];
        $newSubtotal = 0;
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $oldQty = (int)$item['quantity'];
            $newQty = array_key_exists($itemId, $normalized) ? $normalized[$itemId] : $oldQty;
            if ($newQty < 0 || $newQty > $oldQty) throw new RuntimeException('در Update اسنپ‌پی فقط کاهش تعداد یا حذف کامل آیتم مجاز است.');
            if ($newQty !== $oldQty) $changes[] = ['item_id'=>$itemId,'old_quantity'=>$oldQty,'new_quantity'=>$newQty];
            if ($newQty > 0) {
                $copy = $item;
                $copy['quantity'] = $newQty;
                $copy['line_total'] = (int)$item['unit_price'] * $newQty;
                $newRows[] = $copy;
                $newSubtotal += (int)$copy['line_total'];
            }
        }
        if (!$changes) throw new RuntimeException('برای Update باید حداقل تعداد یک کالا کاهش پیدا کند.');
        if (!$newRows || $newSubtotal < 1) throw new RuntimeException('برای مرجوع کامل سفارش از Cancel اسنپ‌پی استفاده کنید.');

        $newDiscount = snappay_recalculate_order_discount($pdo, $order, $newSubtotal);
        $shipping = max(0, (int)($order['shipping_amount'] ?? 0));
        $newTotal = max(0, $newSubtotal + $shipping - $newDiscount);
        if ($newTotal < 1 || $newTotal >= (int)$order['total']) throw new RuntimeException('مبلغ Update باید از مبلغ فعلی سفارش کمتر و بزرگ‌تر از صفر باشد.');

        $remoteOrder = $order;
        $remoteOrder['subtotal'] = $newSubtotal;
        $remoteOrder['discount_amount'] = $newDiscount;
        $remoteOrder['total'] = $newTotal;
        $payload = [
            'amount'=>payment_toman_to_rial($newTotal),
            'cartList'=>snappay_cart_from_rows($remoteOrder, $newRows),
            'discountAmount'=>payment_toman_to_rial($newDiscount),
            'externalSourceAmount'=>0,
            'mobile'=>(string)$order['phone'],
            'paymentToken'=>$paymentToken,
        ];
        $remote = snappay_api_json('POST', '/api/online/payment/v1/update', $payload, 30);
        if (!snappay_http_ok($remote)) throw new RuntimeException(snappay_error_message($remote, 'Update سفارش در اسنپ‌پی انجام نشد.'));
        $expectedTransactionId = snappay_transaction_reference($transaction);
        snappay_validate_remote_transaction($remote, $expectedTransactionId);

        $admin = function_exists('admin_user') ? admin_user() : null;
        $actorId = is_array($admin) && !empty($admin['id']) ? (int)$admin['id'] : null;
        $syncProducts = [];
        foreach ($changes as $change) {
            $item = null;
            foreach ($items as $candidate) if ((int)$candidate['id'] === (int)$change['item_id']) { $item = $candidate; break; }
            if (!$item) continue;
            $returned = (int)$change['old_quantity'] - (int)$change['new_quantity'];
            $productId = (int)$item['product_id'];
            $variantId = (int)($item['variant_id'] ?? 0);
            if ($variantId > 0) {
                $pdo->prepare('UPDATE product_variants SET stock=stock+? WHERE id=? AND product_id=?')->execute([$returned,$variantId,$productId]);
                inventory_record_existing_change($pdo,$productId,$variantId,'return',$returned,(int)($item['unit_cost']??0),'order',$orderId,'مرجوعی آیتم از Update اسنپ‌پی',$actorId);
                $syncProducts[$productId] = true;
            } else {
                $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$returned,$productId]);
                inventory_record_existing_change($pdo,$productId,null,'return',$returned,(int)($item['unit_cost']??0),'order',$orderId,'مرجوعی آیتم از Update اسنپ‌پی',$actorId);
            }
            if ((int)$change['new_quantity'] === 0) {
                $pdo->prepare('DELETE FROM order_items WHERE id=? AND order_id=?')->execute([(int)$change['item_id'],$orderId]);
            } else {
                $lineTotal = (int)$item['unit_price'] * (int)$change['new_quantity'];
                $lineCost = (int)($item['unit_cost']??0) * (int)$change['new_quantity'];
                $pdo->prepare('UPDATE order_items SET quantity=?,line_total=?,line_cost=? WHERE id=? AND order_id=?')->execute([(int)$change['new_quantity'],$lineTotal,$lineCost,(int)$change['item_id'],$orderId]);
            }
        }
        foreach (array_keys($syncProducts) as $productId) sync_product_variant_summary($pdo, (int)$productId);

        $oldPayment = (string)$order['payment_status'];
        $refundDelta = max(0, (int)$order['total'] - $newTotal);
        if ($refundDelta > 0 && function_exists('accounting_post')) {
            accounting_post($pdo,'کاهش مبلغ سفارش اسنپ‌پی '.$orderId,[
                ['account_id'=>accounting_account_id($pdo,'sales_returns'),'debit'=>$refundDelta,'credit'=>0],
                ['account_id'=>accounting_account_id($pdo,'cash_bank'),'debit'=>0,'credit'=>$refundDelta],
            ],'snappay-update:'.(int)$transaction['id'].':'.(count($updates)+1),'order',$orderId);
        }
        $pdo->prepare("UPDATE orders SET subtotal=?,discount_amount=?,total=?,payment_status='partially_refunded' WHERE id=?")->execute([$newSubtotal,$newDiscount,$newTotal,$orderId]);
        $pdo->prepare('UPDATE coupon_usages SET discount_amount=? WHERE order_id=?')->execute([$newDiscount,$orderId]);
        $updates[] = [
            'completed_at'=>date('c'),
            'old_total'=>(int)$order['total'],
            'new_total'=>$newTotal,
            'changes'=>$changes,
            'request'=>$payload,
            'response'=>$remote,
        ];
        if (count($updates) > 20) $updates = array_slice($updates, -20);
        $savedResponse['updates'] = $updates;
        $savedResponse['last_update_completed_at'] = date('c');
        $pdo->prepare("UPDATE payment_transactions SET status='partially_refunded',amount=?,response_json=? WHERE id=?")->execute([$newTotal,json_encode($savedResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),(int)$transaction['id']]);
        if ($oldPayment !== 'partially_refunded') {
            $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,?)')
                ->execute([$orderId,(string)$order['order_status'],(string)$order['order_status'],$oldPayment,'partially_refunded','کاهش/حذف آیتم با سرویس Update اسنپ‌پی',$actorId]);
        }
        $pdo->commit();
        if (function_exists('admin_audit_event')) admin_audit_event('snappay_order_updated','order',$orderId,['old_total'=>(int)$order['total'],'new_total'=>$newTotal,'changes'=>$changes]);
        return ['ok'=>true,'transaction_id'=>$expectedTransactionId,'old_total'=>(int)$order['total'],'new_total'=>$newTotal,'changes'=>$changes];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        @flock($fileLock, LOCK_UN);
        @fclose($fileLock);
    }
}

function snappay_cancel_order(int $orderId): array
{
    if ($orderId < 1) throw new RuntimeException('سفارش معتبر نیست.');
    $lockPath = BASE_PATH.'/storage/snappay-cancel-'.hash('sha256', (string)$orderId).'.lock';
    $fileLock = @fopen($lockPath, 'c+');
    if (!is_resource($fileLock) || !@flock($fileLock, LOCK_EX)) {
        if (is_resource($fileLock)) @fclose($fileLock);
        throw new RuntimeException('قفل لغو سفارش اسنپ‌پی ایجاد نشد؛ دوباره تلاش کنید.');
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) throw new RuntimeException('سفارش پیدا نشد.');
        if ((string)$order['payment_method'] !== 'snappay') throw new RuntimeException('این سفارش مربوط به اسنپ‌پی نیست.');
        if ((string)$order['payment_status'] === 'refunded' && (string)$order['order_status'] === 'cancelled') {
            $pdo->commit();
            return ['ok'=>true,'already'=>true,'transaction_id'=>(string)($order['gateway_reference']??'')];
        }
        if (!in_array((string)$order['payment_status'], ['paid','partially_refunded'], true)) throw new RuntimeException('Cancel فقط روی سفارش پرداخت و Settle‌شده اسنپ‌پی مجاز است.');
        $transaction = snappay_latest_paid_transaction_for_order($pdo, $orderId, true);
        if (!$transaction) throw new RuntimeException('تراکنش موفق اسنپ‌پی برای سفارش پیدا نشد.');
        $paymentToken = trim((string)$transaction['authority']);
        if ($paymentToken === '') throw new RuntimeException('Payment Token اسنپ‌پی برای سفارش پیدا نشد.');
        $expectedTransactionId = snappay_transaction_reference($transaction);

        $remote = snappay_api_json('POST', '/api/online/payment/v1/cancel', ['paymentToken'=>$paymentToken], 30);
        if (!snappay_http_ok($remote)) throw new RuntimeException(snappay_error_message($remote, 'Cancel سفارش در اسنپ‌پی انجام نشد.'));
        snappay_validate_remote_transaction($remote, $expectedTransactionId);

        $oldOrder = (string)$order['order_status'];
        $oldPayment = (string)$order['payment_status'];
        restore_order_stock($pdo,$orderId);
        accounting_order_cancelled($pdo,$orderId,(int)$order['subtotal'],(int)$order['shipping_amount'],true,(int)($order['discount_amount']??0));
        accounting_order_refunded($pdo,$orderId,(int)$order['total']);
        $pdo->prepare('DELETE FROM coupon_usages WHERE order_id=?')->execute([$orderId]);
        $savedResponse = json_decode((string)($transaction['response_json'] ?? ''), true);
        if (!is_array($savedResponse)) $savedResponse = [];
        $savedResponse['cancel'] = $remote;
        $savedResponse['cancel_completed_at'] = date('c');
        $pdo->prepare("UPDATE payment_transactions SET status='refunded',response_json=? WHERE id=?")->execute([json_encode($savedResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),(int)$transaction['id']]);
        $pdo->prepare("UPDATE orders SET order_status='cancelled',payment_status='refunded',cancelled_at=NOW() WHERE id=?")->execute([$orderId]);
        $admin = function_exists('admin_user') ? admin_user() : null;
        $actorId = is_array($admin) && !empty($admin['id']) ? (int)$admin['id'] : null;
        $pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,?)')
            ->execute([$orderId,$oldOrder,'cancelled',$oldPayment,'refunded','لغو کامل با سرویس Cancel اسنپ‌پی',$actorId]);
        $pdo->commit();
        admin_notify_order_cancelled($orderId);
        if (function_exists('admin_audit_event')) admin_audit_event('snappay_order_cancelled','order',$orderId,['transaction_id'=>$expectedTransactionId]);
        return ['ok'=>true,'already'=>false,'transaction_id'=>$expectedTransactionId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        @flock($fileLock, LOCK_UN);
        @fclose($fileLock);
    }
}

function snappay_test_connection(): array
{
    snappay_access_token(true);
    return ['ok'=>true];
}
