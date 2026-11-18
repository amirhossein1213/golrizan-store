<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$provided=PHP_SAPI==='cli'?trim((string)($argv[1]??'')):trim((string)($_SERVER['HTTP_X_BACKUP_KEY']??''));$expected=(string)setting('backup_cron_key','');
if($expected===''||$provided===''||!hash_equals($expected,$provided)){if(PHP_SAPI!=='cli')http_response_code(403);exit("Backup authorization failed.\n");}
if((string)setting('backup_enabled','1')!=='1')exit("Automatic backups are disabled.\n");
$created=backup_maybe_run('cron');echo $created?"Backup completed.\n":"Backup is not due or another process is running.\n";
