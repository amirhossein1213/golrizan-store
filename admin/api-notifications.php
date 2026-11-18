<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
admin_required();
$admin=admin_user();$adminId=(int)($admin['id']??0);
if($adminId<1)json_response(['ok'=>false,'message'=>'نشست مدیریت معتبر نیست.'],401);

if(is_post()){
    $data=input_json();verify_csrf(scalar_string($data['_csrf']??''));$action=scalar_string($data['action']??'');
    try{
        if($action==='seen'){admin_notification_mark_seen($adminId,is_array($data['ids']??null)?$data['ids']:[]);}
        elseif($action==='read'){admin_notification_mark_read($adminId,is_array($data['ids']??null)?$data['ids']:[]);}
        elseif($action==='read_all'){admin_notification_mark_all_read($adminId);}
        else json_response(['ok'=>false,'message'=>'عملیات معتبر نیست.'],422);
        json_response(['ok'=>true,'unread'=>admin_notification_unread_count($adminId)]);
    }catch(Throwable $e){log_error($e);json_response(['ok'=>false,'message'=>'ثبت وضعیت اعلان انجام نشد.'],500);}
}

$after=max(0,scalar_int($_GET['after']??0));$rows=admin_notifications_for_admin($adminId,30,false);
if($after>0)$rows=array_values(array_filter($rows,static fn(array $row):bool=>(int)$row['id']>$after));
$prefs=admin_notification_preferences($adminId);
json_response([
    'ok'=>true,'unread'=>admin_notification_unread_count($adminId),
    'notifications'=>array_map('admin_notification_public_row',$rows),
    'preferences'=>['browser_enabled'=>(bool)$prefs['browser_enabled'],'sound_enabled'=>(bool)$prefs['sound_enabled']],
    'server_time'=>time(),
]);
