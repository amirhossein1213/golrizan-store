<?php
declare(strict_types=1);

function ticket_status_labels(): array
{
    return ['open'=>'باز','pending_admin'=>'در انتظار پاسخ پشتیبانی','pending_customer'=>'در انتظار پاسخ مشتری','resolved'=>'حل‌شده','closed'=>'بسته'];
}

function ticket_priority_labels(): array
{
    return ['low'=>'کم','normal'=>'عادی','high'=>'زیاد','urgent'=>'فوری'];
}

function support_ticket_number(): string
{
    return 'TKT-'.jalali_compact_date().'-'.strtoupper(bin2hex(random_bytes(5)));
}

function support_ticket_rate_limit(string $bucket,int $maxAttempts,int $windowSeconds): void
{
    $retry=rate_limit_retry_after($bucket,$maxAttempts,$windowSeconds);
    if($retry>0)throw new RuntimeException('تعداد درخواست‌های پشتیبانی زیاد است. '.fa_number($retry).' ثانیه دیگر تلاش کنید.');
    rate_limit_record($bucket,$windowSeconds);
}

function support_ticket_create(int $customerId,string $subject,string $message): int
{
    if((string)setting('tickets_enabled','1')!=='1')throw new RuntimeException('تیکت پشتیبانی از پنل مدیریت غیرفعال است.');
    $subject=text_substr(trim($subject),0,200);$message=trim($message);
    if(text_length($subject)<3)throw new RuntimeException('موضوع تیکت باید حداقل ۳ کاراکتر باشد.');
    if(text_length($message)<5||text_length($message)>5000)throw new RuntimeException('متن پیام باید بین ۵ تا ۵۰۰۰ کاراکتر باشد.');
    $hourly=max(1,min(20,(int)setting('tickets_create_limit_hour','5')));support_ticket_rate_limit('ticket-create:customer:'.$customerId,$hourly,3600);
    $openLimit=max(1,min(50,(int)setting('tickets_open_limit','10')));$open=db()->prepare("SELECT COUNT(*) FROM support_tickets WHERE customer_id=? AND status NOT IN ('resolved','closed')");$open->execute([$customerId]);if((int)$open->fetchColumn()>=$openLimit)throw new RuntimeException('ابتدا تیکت‌های باز فعلی را پیگیری کنید؛ سقف تیکت باز تکمیل شده است.');
    $pdo=db();$pdo->beginTransaction();
    try{$pdo->prepare("INSERT INTO support_tickets(ticket_number,customer_id,subject,status,priority,last_message_at) VALUES(?,?,?,'pending_admin','normal',NOW())")->execute([support_ticket_number(),$customerId,$subject]);$id=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO support_ticket_messages(ticket_id,sender_type,customer_id,message) VALUES(?,'customer',?,?)")->execute([$id,$customerId,$message]);$pdo->commit();return $id;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function support_ticket_for_customer(int $ticketId,int $customerId): ?array
{
    $stmt=db()->prepare('SELECT * FROM support_tickets WHERE id=? AND customer_id=?');$stmt->execute([$ticketId,$customerId]);return $stmt->fetch()?:null;
}

function support_ticket_messages(int $ticketId): array
{
    $stmt=db()->prepare('SELECT m.*,a.full_name admin_name,c.full_name customer_name FROM support_ticket_messages m LEFT JOIN admins a ON a.id=m.admin_id LEFT JOIN customers c ON c.id=m.customer_id WHERE m.ticket_id=? ORDER BY m.id');$stmt->execute([$ticketId]);return $stmt->fetchAll();
}

function support_ticket_customer_reply(int $ticketId,int $customerId,string $message): void
{
    $message=trim($message);if(text_length($message)<2||text_length($message)>5000)throw new RuntimeException('متن پاسخ باید بین ۲ تا ۵۰۰۰ کاراکتر باشد.');
    $limit=max(2,min(30,(int)setting('tickets_reply_limit_10min','10')));support_ticket_rate_limit('ticket-reply:customer:'.$customerId.':ticket:'.$ticketId,$limit,600);
    $pdo=db();$pdo->beginTransaction();try{$stmt=$pdo->prepare('SELECT status FROM support_tickets WHERE id=? AND customer_id=? FOR UPDATE');$stmt->execute([$ticketId,$customerId]);$status=$stmt->fetchColumn();if($status===false)throw new RuntimeException('تیکت پیدا نشد.');if($status==='closed')throw new RuntimeException('این تیکت بسته شده است.');$pdo->prepare("INSERT INTO support_ticket_messages(ticket_id,sender_type,customer_id,message) VALUES(?,'customer',?,?)")->execute([$ticketId,$customerId,$message]);$pdo->prepare("UPDATE support_tickets SET status='pending_admin',last_message_at=NOW() WHERE id=?")->execute([$ticketId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function support_ticket_admin_reply(int $ticketId,int $adminId,string $message,string $status,string $priority,bool $sendSms=true): void
{
    $message=trim($message);if($message!==''&&(text_length($message)<2||text_length($message)>5000))throw new RuntimeException('متن پاسخ باید بین ۲ تا ۵۰۰۰ کاراکتر باشد.');if(!isset(ticket_status_labels()[$status])||!isset(ticket_priority_labels()[$priority]))throw new RuntimeException('وضعیت یا اولویت معتبر نیست.');
    $pdo=db();$pdo->beginTransaction();try{$stmt=$pdo->prepare('SELECT id FROM support_tickets WHERE id=? FOR UPDATE');$stmt->execute([$ticketId]);if(!$stmt->fetchColumn())throw new RuntimeException('تیکت پیدا نشد.');if($message!==''){$pdo->prepare("INSERT INTO support_ticket_messages(ticket_id,sender_type,admin_id,message) VALUES(?,'admin',?,?)")->execute([$ticketId,$adminId,$message]);if($status==='open'||$status==='pending_admin')$status='pending_customer';}$pdo->prepare('UPDATE support_tickets SET status=?,priority=?,last_message_at=IF(?,NOW(),last_message_at) WHERE id=?')->execute([$status,$priority,$message!==''?1:0,$ticketId]);$pdo->commit();if($message!==''&&$sendSms)sms_notify_ticket_reply($ticketId);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function sms_notify_ticket_reply(int $ticketId): void
{
    if((string)setting('sms_ticket_reply_enabled','0')!=='1')return;
    try{$stmt=db()->prepare('SELECT t.id,t.ticket_number,t.subject,c.phone FROM support_tickets t JOIN customers c ON c.id=t.customer_id WHERE t.id=?');$stmt->execute([$ticketId]);$ticket=$stmt->fetch();if(!$ticket)return;smsir_send_verify((string)$ticket['phone'],sms_template_id('sms_ticket_reply_template_id'),[sms_param_name('sms_ticket_param_number','Ticket')=>$ticket['ticket_number'],sms_param_name('sms_ticket_param_subject','Subject')=>text_substr((string)$ticket['subject'],0,90)],'ticket_reply','ticket',(int)$ticket['id']);}catch(Throwable $e){log_error($e);}
}
