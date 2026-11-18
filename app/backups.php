<?php
declare(strict_types=1);

function backup_directory(): string
{
    $directory=BASE_PATH.'/storage/backups';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('پوشه بکاپ قابل ایجاد نیست.');
    return $directory;
}

function backup_write($handle,string $data): void
{
    $length=strlen($data);$written=0;
    while($written<$length){$result=fwrite($handle,substr($data,$written));if($result===false||$result===0)throw new RuntimeException('نوشتن فایل بکاپ کامل نشد.');$written+=$result;}
}

function backup_sql_identifier(string $identifier): string
{
    return '`'.str_replace('`','``',$identifier).'`';
}

function backup_create_sql_dump(PDO $pdo,string $path): void
{
    $handle=fopen($path,'wb');if($handle===false)throw new RuntimeException('فایل موقت بکاپ ساخته نشد.');if($pdo->inTransaction()){fclose($handle);throw new RuntimeException('ساخت بکاپ هنگام تراکنش دیتابیس مجاز نیست.');}$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try{
        backup_write($handle,"-- Mousavi Store database backup\n-- Created: ".date('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables=$pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
        foreach($tables as $tableRow){
            $table=(string)($tableRow[0]??'');if($table==='')continue;$quoted=backup_sql_identifier($table);
            $createRow=$pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);$create=(string)($createRow[1]??'');if($create==='')continue;
            backup_write($handle,"DROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");
            if($table==='backup_runs')continue;
            $offset=0;$batch=250;
            do{
                $rows=$pdo->query('SELECT * FROM '.$quoted.' LIMIT '.$batch.' OFFSET '.$offset)->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as $row){$columns=array_map('backup_sql_identifier',array_keys($row));$values=[];foreach($row as $value)$values[]=$value===null?'NULL':$pdo->quote((string)$value);backup_write($handle,'INSERT INTO '.$quoted.' ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");}
                $count=count($rows);$offset+=$count;
            }while($count===$batch);
            backup_write($handle,"\n");
        }
        backup_write($handle,"SET FOREIGN_KEY_CHECKS=1;\n");$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}finally{fclose($handle);}
}

function backup_add_directory_to_zip(ZipArchive $zip,string $source,string $prefix): void
{
    if(!is_dir($source))return;$root=rtrim(str_replace('\\','/',$source),'/');
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
    foreach($iterator as $file){if(!$file instanceof SplFileInfo||!$file->isFile()||$file->isLink())continue;$path=str_replace('\\','/',$file->getPathname());$relative=ltrim(substr($path,strlen($root)),'/');if($relative==='')continue;if(!$zip->addFile($file->getPathname(),trim($prefix,'/').'/'.$relative))throw new RuntimeException('افزودن فایل به آرشیو بکاپ انجام نشد.');}
}

function backup_cleanup_old_files(): void
{
    $keep=max(1,min(50,(int)setting('backup_retention_count','7')));$stmt=db()->prepare("SELECT id,file_name FROM backup_runs WHERE status='completed' AND file_name IS NOT NULL ORDER BY id DESC LIMIT 1000 OFFSET ".$keep);$stmt->execute();$delete=db()->prepare('DELETE FROM backup_runs WHERE id=?');$directory=backup_directory();
    foreach($stmt->fetchAll() as $row){$name=(string)$row['file_name'];if($name!==''&&basename($name)===$name){$path=$directory.'/'.$name;if(is_file($path))@unlink($path);}$delete->execute([(int)$row['id']]);}
    db()->exec("DELETE FROM backup_runs WHERE status='failed' AND created_at<DATE_SUB(NOW(),INTERVAL 30 DAY)");
}

function create_store_backup(string $trigger='manual',?int $adminId=null): array
{
    if(!in_array($trigger,['manual','automatic','cron'],true))$trigger='manual';
    @set_time_limit(0);$pdo=db();$pdo->exec("UPDATE backup_runs SET status='failed',error_message='فرایند قبلی کامل نشده است.',completed_at=NOW() WHERE status='running' AND started_at<DATE_SUB(NOW(),INTERVAL 6 HOUR)");$includeUploads=(string)setting('backup_include_uploads','1')==='1';$requestedType=$includeUploads?'full':'database';
    $stmt=$pdo->prepare("INSERT INTO backup_runs(backup_type,status,triggered_by,admin_id,started_at) VALUES(?,'running',?,?,NOW())");$stmt->execute([$requestedType,$trigger,$adminId]);$runId=(int)$pdo->lastInsertId();
    $directory=backup_directory();$token=date('Ymd-His').'-'.bin2hex(random_bytes(4));$sqlPath=$directory.'/.database-'.$token.'.sql';$finalPath='';$finalName='';
    try{
        backup_create_sql_dump($pdo,$sqlPath);
        if(class_exists('ZipArchive')){
            $finalName='mousavi-backup-'.$token.'.zip';$temporary=$directory.'/.'.$finalName.'.tmp';$zip=new ZipArchive();
            if($zip->open($temporary,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('آرشیو ZIP بکاپ ساخته نشد.');
            try{
                if(!$zip->addFile($sqlPath,'database.sql'))throw new RuntimeException('دیتابیس به آرشیو افزوده نشد.');
                foreach(['config/database.php','storage/app.key'] as $relative){$source=BASE_PATH.'/'.$relative;if(is_file($source)&&!$zip->addFile($source,$relative))throw new RuntimeException('افزودن فایل تنظیمات به بکاپ انجام نشد.');}
                if($includeUploads)backup_add_directory_to_zip($zip,BASE_PATH.'/uploads','uploads');
            }finally{if(!$zip->close())throw new RuntimeException('بستن آرشیو ZIP بکاپ انجام نشد.');}
            $finalPath=$directory.'/'.$finalName;if(!rename($temporary,$finalPath))throw new RuntimeException('نهایی‌سازی فایل بکاپ انجام نشد.');
        }elseif(function_exists('gzopen')){
            $finalName='mousavi-database-'.$token.'.sql.gz';$finalPath=$directory.'/'.$finalName;$input=fopen($sqlPath,'rb');$output=gzopen($finalPath,'wb9');if($input===false||$output===false)throw new RuntimeException('فایل فشرده بکاپ ساخته نشد.');try{while(!feof($input)){$chunk=fread($input,1024*1024);if($chunk===false)throw new RuntimeException('خواندن دیتابیس موقت انجام نشد.');if($chunk!==''&&gzwrite($output,$chunk)===false)throw new RuntimeException('فشرده‌سازی دیتابیس انجام نشد.');}}finally{fclose($input);gzclose($output);}$requestedType='database';
        }else{
            $finalName='mousavi-database-'.$token.'.sql';$finalPath=$directory.'/'.$finalName;if(!rename($sqlPath,$finalPath))throw new RuntimeException('نهایی‌سازی دیتابیس پشتیبان انجام نشد.');$sqlPath='';$requestedType='database';
        }
        if(!is_file($finalPath)||filesize($finalPath)===false||filesize($finalPath)<1)throw new RuntimeException('فایل بکاپ نهایی معتبر نیست.');
        $size=(int)filesize($finalPath);$pdo->prepare("UPDATE backup_runs SET backup_type=?,status='completed',file_name=?,file_size=?,completed_at=NOW() WHERE id=?")->execute([$requestedType,$finalName,$size,$runId]);$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['backup_last_success_at',date('Y-m-d H:i:s')]);backup_cleanup_old_files();if($adminId)admin_audit_event('backup_created','backup',$runId,['type'=>$requestedType,'size'=>$size]);return ['id'=>$runId,'file_name'=>$finalName,'file_size'=>$size,'backup_type'=>$requestedType];
    }catch(Throwable $error){if($finalPath!==''&&is_file($finalPath))@unlink($finalPath);$pdo->prepare("UPDATE backup_runs SET status='failed',error_message=?,completed_at=NOW() WHERE id=?")->execute([text_substr($error->getMessage(),0,1000),$runId]);throw $error;}
    finally{if($sqlPath!==''&&is_file($sqlPath))@unlink($sqlPath);foreach(glob($directory.'/.mousavi-backup-'.$token.'*')?:[] as $temporary)if(is_file($temporary))@unlink($temporary);}
}

function backup_maybe_run(string $trigger='automatic'): bool
{
    if((string)setting('backup_enabled','1')!=='1')return false;$hours=max(1,min(168,(int)setting('backup_interval_hours','24')));$last=trim((string)setting('backup_last_success_at',''));$lastTime=$last!==''?strtotime($last):false;if($lastTime!==false&&$lastTime>time()-($hours*3600))return false;
    $lock=fopen(backup_directory().'/backup.lock','c+');if($lock===false||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);return false;}
    try{$fresh=(string)db()->query("SELECT setting_value FROM settings WHERE setting_key='backup_last_success_at' LIMIT 1")->fetchColumn();$freshTime=$fresh!==''?strtotime($fresh):false;if($freshTime!==false&&$freshTime>time()-($hours*3600))return false;create_store_backup($trigger,null);return true;}catch(Throwable $error){log_error($error);return false;}finally{flock($lock,LOCK_UN);fclose($lock);}
}
