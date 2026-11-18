<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/** @param array<string,mixed> $payload */
function torob_api_v3_emit(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    torob_api_v3_emit(['error'=>'method must be POST'], 405);
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0] ?? ''));
if ($contentType !== 'application/json') {
    torob_api_v3_emit(['error'=>'content type must be application/json'], 415);
}

try {
    torob_api_v3_verify_token(
        trim((string)($_SERVER['HTTP_X_TOROB_TOKEN'] ?? '')),
        trim((string)($_SERVER['HTTP_X_TOROB_TOKEN_VERSION'] ?? ''))
    );
} catch (TorobApiConfigurationException $exception) {
    log_error($exception);
    header('Retry-After: 300');
    torob_api_v3_emit(['error'=>'product API authentication is not configured on the shop server'], 503);
} catch (TorobApiAuthenticationException) {
    torob_api_v3_emit(['error'=>'unauthorized'], 401);
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody)) {
    torob_api_v3_emit(['error'=>'request body could not be read'], 400);
}
if (strlen($rawBody) > 262144) {
    torob_api_v3_emit(['error'=>'request body is too large'], 413);
}
try {
    $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    torob_api_v3_emit(['error'=>'request body is not valid JSON'], 400);
}
if (!is_array($input)) {
    torob_api_v3_emit(['error'=>'request body must be a JSON object'], 400);
}

try {
    $request = torob_api_v3_validate_request($input);
    torob_api_v3_emit(torob_api_v3_response_data(db(), $request));
} catch (TorobApiRequestException $exception) {
    torob_api_v3_emit(['error'=>$exception->getMessage()], 400);
} catch (Throwable $exception) {
    log_error($exception);
    header('Retry-After: 60');
    torob_api_v3_emit(['error'=>'temporary shop server error'], 500);
}
