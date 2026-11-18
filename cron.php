<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$cli = PHP_SAPI === 'cli';
$provided = $cli
    ? ''
    : trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$expected = central_cron_key();
if (!$cli && ($provided === '' || !hash_equals($expected, $provided))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden\n");
}

$result = central_cron_run_all($cli ? 'cli' : 'http');
if (!empty($result['locked'])) {
    http_response_code(409);
} elseif (empty($result['ok'])) {
    http_response_code(500);
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
