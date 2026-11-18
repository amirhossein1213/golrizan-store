<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/pdf.php';
if((string)setting('invoice_pdf_enabled','1')!=='1'){http_response_code(404);exit('فاکتور PDF غیرفعال است.');}
$customer=customer_required();$id=max(0,scalar_int($_GET['id']??0));$stmt=db()->prepare('SELECT * FROM orders WHERE id=? AND customer_id=? LIMIT 1');$stmt->execute([$id,(int)$customer['id']]);$order=$stmt->fetch();if(!$order){http_response_code(404);exit('سفارش پیدا نشد.');}$itemsStmt=db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$itemsStmt->execute([$id]);$items=$itemsStmt->fetchAll();generate_persian_pdf(invoice_pdf_entries($order,$items),'invoice-'.$order['order_number'].'.pdf');
