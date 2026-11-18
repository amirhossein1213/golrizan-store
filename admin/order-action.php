<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';admin_required();
if(!is_post())json_response(['ok'=>false,'message'=>'روش درخواست معتبر نیست.'],405);
$data=str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')?input_json():$_POST;
verify_csrf(scalar_string($data['_csrf']??''));
$action=scalar_string($data['action']??'');$orderId=max(0,scalar_int($data['id']??0));
try{
    if($orderId<1)throw new RuntimeException('سفارش معتبر نیست.');
    if($action==='status'){
        $status=scalar_string($data['status']??'');$sendSms=!empty($data['send_sms']);$note=text_substr(trim(scalar_string($data['note']??'تغییر سریع وضعیت توسط مدیر')),0,500);
        if($status==='shipped'){
            $tracking=text_substr(trim(scalar_string($data['tracking_code']??'')),0,150);
            $result=admin_mark_order_shipped($orderId,$tracking,null,$note,$sendSms);
            json_response(['ok'=>true,'message'=>'سفارش ارسال‌شده و کد رهگیری ثبت شد.','status'=>'shipped','status_label'=>order_status_label('shipped'),'stage'=>order_workflow_stage('shipped'),'sms'=>$result['sms']??null]);
        }
        $result=admin_advance_order_to_status($orderId,$status,$note,$sendSms);
        json_response(['ok'=>true,'message'=>'وضعیت سفارش به «'.order_status_label($status).'» تغییر کرد.','status'=>$status,'status_label'=>order_status_label($status),'stage'=>order_workflow_stage($status),'sms'=>$result['order_sms']??null]);
    }
    if($action==='meta'){
        $assigned=scalar_int($data['assigned_admin_id']??0);$assigned=$assigned>0?$assigned:null;
        $priority=scalar_string($data['priority']??'normal');$note=scalar_string($data['internal_note']??'');
        admin_update_order_meta($orderId,$assigned,$priority,$note);
        json_response(['ok'=>true,'message'=>'مسئول و اطلاعات داخلی سفارش ذخیره شد.']);
    }
    throw new RuntimeException('عملیات انتخاب‌شده معتبر نیست.');
}catch(Throwable $e){log_error($e);json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
