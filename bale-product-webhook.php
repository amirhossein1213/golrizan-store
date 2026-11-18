<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);header('Allow: POST');exit('Method Not Allowed');
}
$provided = trim(scalar_string($_GET['key'] ?? ''));
$expected = trim((string)setting('bale_product_bot_webhook_key', ''));
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) { http_response_code(403);exit('Forbidden'); }
if (!bale_product_bot_enabled()) { http_response_code(200);exit('Disabled'); }
$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 2*1024*1024) { http_response_code(413);exit('Too Large'); }
$update = json_decode($raw, true);
if (!is_array($update)) { http_response_code(400);exit('Bad JSON'); }

http_response_code(200);header('Content-Type: text/plain; charset=utf-8');header('Connection: close');echo 'OK';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_flush();@flush(); }
try { bale_product_bot_process_update($update); } catch (Throwable $error) { log_error($error); }
