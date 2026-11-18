<?php
require dirname(__DIR__).'/app/bootstrap.php';
if(!is_post())json_response(['ok'=>false],405);
$d=input_json();verify_csrf(array_key_exists('_csrf',$d) ? scalar_string($d['_csrf']) : null);rate_limit('contact',5,3600);
if(trim(scalar_string($d['website']??''))!=='')json_response(['ok'=>true]);
$department=scalar_string($d['department']??'support');if(!in_array($department,['support','sales'],true))$department='support';$name=trim(scalar_string($d['name']??''));$phone=normalize_phone(scalar_string($d['phone']??''));$subject=trim(scalar_string($d['subject']??''));$message=trim(scalar_string($d['message']??''));
if(text_length($name)<2||text_length($name)>160||!preg_match('/^09\d{9}$/',$phone)||text_length($subject)>200||text_length($message)<10||text_length($message)>5000)json_response(['ok'=>false,'message'=>'نام، شماره تماس و متن پیام را کامل و معتبر وارد کنید.'],422);
try{db()->prepare('INSERT INTO contact_messages(department,name,phone,subject,message) VALUES(?,?,?,?,?)')->execute([$department,$name,$phone,$subject,$message]);json_response(['ok'=>true]);}catch(Throwable $e){log_error($e);json_response(['ok'=>false,'message'=>'ثبت پیام انجام نشد. دوباره تلاش کنید.'],500);}
