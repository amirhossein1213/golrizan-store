<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
json_response(['ok'=>false,'message'=>'استعلام و محاسبه کرایه پستی حذف شده است. ارسال عادی به‌صورت پس‌کرایه انجام می‌شود.'], 410);
