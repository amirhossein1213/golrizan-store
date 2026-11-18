<?php
declare(strict_types=1);
function report_date_range(string $from,string $to): array
{
    $today=date('Y-m-d');$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)?$from:date('Y-m-01');$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)?$to:$today;if($from>$to)[$from,$to]=[$to,$from];if(strtotime($to)-strtotime($from)>366*86400)$from=date('Y-m-d',strtotime($to)-366*86400);return [$from,$to];
}
function management_report(string $from,string $to): array
{
    $pdo=db();$refundJoin=" LEFT JOIN (SELECT order_id,SUM(amount) refunded_amount FROM refund_transactions WHERE status='completed' GROUP BY order_id) rf ON rf.order_id=o.id ";$net='GREATEST(o.total-COALESCE(rf.refunded_amount,0),0)';$where="DATE(o.created_at) BETWEEN ? AND ? AND o.order_status<>'cancelled' AND o.payment_status IN ('paid','partially_refunded','refunded')";
    $summary=$pdo->prepare("SELECT COUNT(*) orders_count,COALESCE(SUM($net),0) revenue,COALESCE(SUM(COALESCE(rf.refunded_amount,0)),0) refunds,COALESCE(SUM(o.discount_amount),0) discounts,COUNT(DISTINCT o.customer_id) customers FROM orders o $refundJoin WHERE $where");$summary->execute([$from,$to]);$summaryRow=$summary->fetch()?:[];
    $topProducts=$pdo->prepare("SELECT oi.product_id,oi.title,SUM(oi.quantity) quantity,SUM(CASE WHEN o.total>0 THEN FLOOR(oi.line_total*$net/o.total) ELSE 0 END) gross FROM order_items oi JOIN orders o ON o.id=oi.order_id $refundJoin WHERE $where GROUP BY oi.product_id,oi.title ORDER BY quantity DESC,gross DESC LIMIT 20");$topProducts->execute([$from,$to]);
    $topCustomers=$pdo->prepare("SELECT o.phone,MAX(o.customer_name) customer_name,COUNT(*) orders_count,SUM($net) total FROM orders o $refundJoin WHERE $where GROUP BY o.phone ORDER BY total DESC LIMIT 20");$topCustomers->execute([$from,$to]);
    $daily=$pdo->prepare("SELECT DATE(o.created_at) day,COUNT(*) orders_count,SUM($net) total FROM orders o $refundJoin WHERE $where GROUP BY DATE(o.created_at) ORDER BY day");$daily->execute([$from,$to]);
    $coupons=$pdo->prepare("SELECT COALESCE(o.coupon_code,'بدون کد') coupon_code,COUNT(*) orders_count,SUM(o.discount_amount) discount_total,SUM($net) total FROM orders o $refundJoin WHERE $where GROUP BY o.coupon_code ORDER BY discount_total DESC");$coupons->execute([$from,$to]);
    $abandoned=$pdo->prepare("SELECT status,COUNT(*) count,COALESCE(SUM(subtotal),0) subtotal FROM abandoned_carts WHERE DATE(last_activity_at) BETWEEN ? AND ? GROUP BY status");$abandoned->execute([$from,$to]);
    return ['summary'=>['orders'=>(int)($summaryRow['orders_count']??0),'revenue'=>(int)($summaryRow['revenue']??0),'refunds'=>(int)($summaryRow['refunds']??0),'discounts'=>(int)($summaryRow['discounts']??0),'customers'=>(int)($summaryRow['customers']??0)],'top_products'=>$topProducts->fetchAll(),'top_customers'=>$topCustomers->fetchAll(),'daily'=>$daily->fetchAll(),'coupons'=>$coupons->fetchAll(),'abandoned'=>$abandoned->fetchAll()];
}
