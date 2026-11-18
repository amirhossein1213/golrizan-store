<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';
$cli=PHP_SAPI==='cli';$key=trim($cli?(string)($argv[1]??''):(string)($_SERVER['HTTP_X_CRON_KEY']??''));$expected=trim((string)setting('sms_campaign_cron_key',''));
if($expected===''||$key===''||!hash_equals($expected,$key)){if(!$cli)http_response_code(403);exit("Forbidden\n");}
$lock=@fopen(BASE_PATH.'/storage/sms-campaigns.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(!$cli)http_response_code(409);exit("Another worker is running\n");}
try{$ids=db()->query("SELECT id FROM sms_campaigns WHERE status='running' ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);$processed=0;foreach($ids as $id){$result=process_sms_campaign((int)$id,50);$processed+=(int)$result['processed'];}if(!$cli)header('Content-Type: text/plain; charset=utf-8');echo 'processed='.$processed."\n";}finally{flock($lock,LOCK_UN);fclose($lock);}
