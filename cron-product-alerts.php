<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$provided=scalar_string($_GET['key']??'');$expected=(string)setting('product_alert_cron_key','');
if(PHP_SAPI!=='cli'&&($expected===''||!hash_equals($expected,$provided))){http_response_code(403);exit("Forbidden\n");}
$lock=@fopen(BASE_PATH.'/storage/product-alerts.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){http_response_code(409);exit("Another worker is running\n");}
try{$result=process_product_alerts(max(1,min(100,(int)setting('product_alert_batch_size','30'))));echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}catch(Throwable $e){log_error($e);http_response_code(500);echo "Processing failed\n";}finally{flock($lock,LOCK_UN);fclose($lock);}
