<?php
require dirname(__DIR__).'/app/bootstrap.php';
if(!is_post())json_response(['ok'=>false],405);
$d=input_json();verify_csrf(array_key_exists('_csrf',$d) ? scalar_string($d['_csrf']) : null);rate_limit('newsletter',5,3600);$phone=normalize_phone(scalar_string($d['phone']??''));
if(!preg_match('/^09\d{9}$/',$phone))json_response(['ok'=>false,'message'=>'شماره موبایل معتبر نیست.'],422);
try{db()->prepare('INSERT INTO newsletter_subscribers(phone) VALUES(?) ON DUPLICATE KEY UPDATE is_active=1')->execute([$phone]);json_response(['ok'=>true]);}catch(Throwable $e){log_error($e);json_response(['ok'=>false,'message'=>'ثبت شماره انجام نشد. دوباره تلاش کنید.'],500);}
