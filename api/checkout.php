<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(!is_post())json_response(['ok'=>false,'message'=>'روش درخواست نامعتبر است.'],405);
$data=input_json();verify_csrf(array_key_exists('_csrf',$data)?scalar_string($data['_csrf']):null);rate_limit('checkout',10,600);
$checkoutToken=strtolower(trim(scalar_string($data['checkout_token']??'')));
if(!preg_match('/^[a-f0-9-]{32,64}$/',$checkoutToken))json_response(['ok'=>false,'message'=>'شناسه ثبت سفارش معتبر نیست. صفحه را تازه‌سازی کنید.'],422);

function checkout_redirect_for_order(array $order): string
{
    if((string)$order['payment_status']!=='paid'){
        if((string)$order['payment_method']==='gateway')return app_url('payment-start.php?token='.rawurlencode((string)$order['public_token']));
        if((string)$order['payment_method']==='torobpay')return app_url('torobpay-consent.php?token='.rawurlencode((string)$order['public_token']));
        if((string)$order['payment_method']==='snappay')return app_url('snappay-start.php?token='.rawurlencode((string)$order['public_token']));
    }
    return app_url('order-success.php?token='.rawurlencode((string)$order['public_token']));
}
$name=trim(scalar_string($data['name']??''));$phone=normalize_phone(scalar_string($data['phone']??''));$province=normalize_place_name(scalar_string($data['province']??''));$city=normalize_place_name(scalar_string($data['city']??''));$address=trim(scalar_string($data['address']??''));$postalCheck=validate_iran_postal_code(scalar_string($data['postal']??''));$postal=scalar_string($postalCheck['postal_code']??'');$note=trim(scalar_string($data['note']??''));$payment=scalar_string($data['payment']??'coordination','coordination');$couponCode=normalize_coupon_code(scalar_string($data['coupon_code']??''));$cartToken=valid_cart_token(scalar_string($data['cart_token']??''));
if(text_length($name)<3||text_length($name)>160||!preg_match('/^09\d{9}$/',$phone)||$province===''||!in_array($province,iran_provinces(),true)||text_length($province)>80||$city===''||text_length($city)>100||text_length($address)<10||text_length($address)>2000||empty($postalCheck['valid'])||text_length($note)>2000)json_response(['ok'=>false,'message'=>'اطلاعات خریدار یا نشانی کامل و معتبر نیست.'],422);
$allowed=[];foreach(['coordination','gateway','torobpay','snappay'] as $method)if(payment_method_available($method))$allowed[]=$method;
if(!in_array($payment,$allowed,true))json_response(['ok'=>false,'message'=>'روش پرداخت انتخاب‌شده فعال نیست.'],422);
$rawItems=is_array($data['items']??null)?array_slice($data['items'],0,100):[];$cart=[];
foreach($rawItems as $item){
    if(!is_array($item))continue;
    $id=scalar_int($item['id']??0);$variantId=max(0,scalar_int($item['variant_id']??0));$qty=max(1,min(20,scalar_int($item['quantity']??1,1)));
    if($id<1)continue;
    $attributeValueIds=normalize_product_attribute_value_ids(array_slice((array)($item['attribute_value_ids']??[]),0,50));sort($attributeValueIds,SORT_NUMERIC);
    $key=$id.':'.$variantId;
    if(isset($cart[$key])&&$cart[$key]['attribute_value_ids']!==$attributeValueIds)json_response(['ok'=>false,'message'=>'برای یک قلم کالا، چند انتخاب ویژگی متفاوت ارسال شده است.'],422);
    $cart[$key]=['id'=>$id,'variant_id'=>$variantId,'quantity'=>min(20,(int)($cart[$key]['quantity']??0)+$qty),'attribute_value_ids'=>$attributeValueIds];
}
if(!$cart)json_response(['ok'=>false,'message'=>'سبد خرید خالی است.'],422);
ksort($cart,SORT_STRING);$customer=customer_user();$customerId=$customer?(int)$customer['id']:null;
$requestPayload=['items'=>array_values($cart),'name'=>$name,'phone'=>$phone,'province'=>$province,'city'=>$city,'address'=>$address,'postal'=>$postal,'note'=>$note,'payment'=>$payment,'coupon_code'=>$couponCode,'customer_id'=>$customerId];
$requestJson=json_encode($requestPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if(!is_string($requestJson))json_response(['ok'=>false,'message'=>'اطلاعات ثبت سفارش قابل پردازش نیست.'],422);$checkoutRequestHash=hash('sha256',$requestJson);
$completed=$_SESSION['_completed_checkouts']??[];
if(is_array($completed)&&isset($completed[$checkoutToken])&&is_array($completed[$checkoutToken])){
    $known=$completed[$checkoutToken];$knownHash=(string)($known['request_hash']??'');
    if($knownHash!==''&&hash_equals($knownHash,$checkoutRequestHash)&&!empty($known['orderNumber'])&&!empty($known['redirect']))json_response(['ok'=>true,'orderNumber'=>$known['orderNumber'],'redirect'=>$known['redirect'],'duplicate'=>true]);
    json_response(['ok'=>false,'message'=>'سبد یا اطلاعات سفارش پس از تلاش قبلی تغییر کرده است. دوباره دکمه ثبت سفارش را بزنید.'],409);
}
$pdo=db();
release_expired_unpaid_orders(25);
try{
    $pdo->beginTransaction();
    $productIds=array_values(array_unique(array_map(static fn(array $row):int=>$row['id'],$cart)));sort($productIds,SORT_NUMERIC);$marks=implode(',',array_fill(0,count($productIds),'?'));
    $stmt=$pdo->prepare("SELECT p.*,c.is_active category_active,b.is_active brand_active FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.id IN ($marks) FOR UPDATE");$stmt->execute($productIds);$productRows=$stmt->fetchAll();if(count($productRows)!==count($productIds))throw new RuntimeException('یکی از کالاها دیگر در دسترس نیست.');$productMap=[];foreach($productRows as $row)$productMap[(int)$row['id']]=$row;
    $variantIds=array_values(array_unique(array_filter(array_map(static fn(array $row):int=>$row['variant_id'],$cart))));$variantMap=[];
    if($variantIds){sort($variantIds,SORT_NUMERIC);$vm=implode(',',array_fill(0,count($variantIds),'?'));$vs=$pdo->prepare("SELECT * FROM product_variants WHERE id IN ($vm) FOR UPDATE");$vs->execute($variantIds);foreach($vs->fetchAll() as $v)$variantMap[(int)$v['id']]=$v;}
    $subtotal=0;$lines=[];$variableProducts=[];$torobMarkupBps=$payment==='torobpay'?torobpay_markup_basis_points():0;$torobMarkupAmount=0;
    foreach($cart as $entry){$product=$productMap[$entry['id']]??null;if(!$product||$product['status']!=='active'||!(int)$product['category_active']||!(int)$product['brand_active'])throw new RuntimeException('یکی از کالاها غیرفعال شده است.');$qty=$entry['quantity'];$variant=null;
        if((string)($product['product_type']??'simple')==='variable'){
            $variant=$variantMap[$entry['variant_id']]??null;if(!$variant||(int)$variant['product_id']!==(int)$product['id']||$variant['status']!=='active')throw new RuntimeException('مدل انتخاب‌شده کالا معتبر یا فعال نیست.');
            if((int)$variant['stock']<$qty)throw new RuntimeException('موجودی متغیر «'.$product['title'].'» کافی نیست.');
            $variantDetails=product_variant_by_id((int)$variant['id'],false,$pdo);
            if(!$variantDetails)throw new RuntimeException('جزئیات مدل انتخاب‌شده کالا پیدا نشد.');
            $effective=variant_with_festival($product,array_merge($variant,['options'=>$variantDetails['options']??[],'option_label'=>$variantDetails['option_label']??'']),$pdo);
            $price=(int)$effective['price'];$basePrice=(int)$effective['base_price'];$unitCost=(int)$variant['cost_price'];$sku=(string)$variant['sku'];$variantTitle=(string)($effective['option_label']?:$variant['title_suffix']);$variantOptions=$effective['options']??[];$stockTable='variant';$variableProducts[(int)$product['id']]=true;
            if($payment==='snappay'&&!(int)$variant['snappay_enabled'])throw new RuntimeException('مدل انتخاب‌شده مشمول اسنپ‌پی نیست.');
        }else{
            if((int)$product['stock']<$qty)throw new RuntimeException('موجودی «'.$product['title'].'» کافی نیست.');$effective=apply_active_festivals_to_products([$product],$pdo)[0];$price=(int)$effective['price'];$basePrice=(int)$effective['base_price'];$unitCost=(int)$product['cost_price'];$sku=(string)$product['sku'];$variantTitle='';$variantOptions=[];$stockTable='product';if($payment==='snappay'&&!(int)$product['snappay_enabled'])throw new RuntimeException('یکی از کالاها مشمول اسنپ‌پی نیست.');
        }
        $checkoutSelection=validate_product_checkout_attribute_selections((int)$product['id'],$entry['attribute_value_ids'],$variantOptions,$pdo);
        if($checkoutSelection['options']){
            $variantOptions=array_merge($variantOptions,$checkoutSelection['options']);
            $variantTitle=implode('، ',array_values(array_filter([$variantTitle,(string)$checkoutSelection['label']],static fn(string $value):bool=>$value!=='')));
        }
        $variantTitle=text_substr($variantTitle,0,255);
        $festivalSaving=(int)($effective['festival_saving']??0);
        if($torobMarkupBps>0){$originalPrice=$price;$price=torobpay_apply_markup($price,$torobMarkupBps);$basePrice=torobpay_apply_markup($basePrice,$torobMarkupBps);$festivalSaving=max(0,$basePrice-$price);$unitMarkup=$price-$originalPrice;if($unitMarkup>0){if($unitMarkup>intdiv(MAX_MONEY_AMOUNT,$qty)||$torobMarkupAmount>MAX_MONEY_AMOUNT-($unitMarkup*$qty))throw new RuntimeException('افزایش قیمت ترب‌پی از سقف مجاز بیشتر است.');$torobMarkupAmount+=$unitMarkup*$qty;}}
        if($price<=0||$price>MAX_MONEY_AMOUNT||$price>intdiv(MAX_MONEY_AMOUNT,$qty))throw new RuntimeException('قیمت یکی از کالاها معتبر نیست.');$line=$price*$qty;if($subtotal>MAX_MONEY_AMOUNT-$line)throw new RuntimeException('مبلغ سفارش از سقف مجاز بیشتر است.');$subtotal+=$line;if($unitCost>MAX_MONEY_AMOUNT||$unitCost>intdiv(MAX_MONEY_AMOUNT,$qty))throw new RuntimeException('بهای تمام‌شده یکی از کالاها معتبر نیست.');
        $lines[]=['product'=>$product,'variant'=>$variant,'qty'=>$qty,'line'=>$line,'unit_cost'=>$unitCost,'base_price'=>$basePrice,'price'=>$price,'sku'=>$sku,'variant_title'=>$variantTitle,'variant_options'=>$variantOptions,'festival_id'=>$effective['festival_id']??null,'festival_title'=>$effective['festival_title']??null,'festival_saving'=>$festivalSaving,'stock_table'=>$stockTable];
    }
    $coupon=coupon_quote($pdo,$couponCode,$subtotal,$customerId,$phone,true);if($couponCode!==''&&empty($coupon['valid']))throw new RuntimeException((string)($coupon['message']??'کد تخفیف معتبر نیست.'));$discount=!empty($coupon['valid'])?(int)$coupon['discount']:0;$total=max(0,$subtotal-$discount);if($payment==='snappay'&&$total>0){$eligibility=snappay_eligibility($total);if(empty($eligibility['eligible']))throw new RuntimeException('این مبلغ در حال حاضر واجد شرایط پرداخت با اسنپ‌پی نیست.');}$paymentForOrder=$total===0?'coordination':$payment;
    $shippingProducts=array_map(static fn(array $line):array=>array_merge($line['product'],['quantity'=>$line['qty']]),$lines);$shippingQuote=festival_shipping_quote($pdo,$shippingProducts);$number=order_number();$publicToken=bin2hex(random_bytes(32));$initialOrderStatus=$total===0?'reviewing':(in_array($paymentForOrder,['gateway','torobpay','snappay'],true)?'pending_payment':'new');$initialPaymentStatus=$total===0?'paid':'unpaid';$shippingJson=json_encode($shippingQuote,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    $markupPercentForOrder=$paymentForOrder==='torobpay'?torobpay_markup_percent():'0';$markupAmountForOrder=$paymentForOrder==='torobpay'?$torobMarkupAmount:0;
    $pdo->prepare('INSERT INTO orders(customer_id,order_number,public_token,checkout_token,checkout_request_hash,customer_name,phone,province,city,address,postal_code,postal_status,note,shipping_method,payment_method,payment_status,order_status,subtotal,discount_amount,coupon_id,coupon_code,shipping_amount,shipping_festival_id,shipping_details_json,payment_markup_percent,payment_markup_amount,total) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$customerId,$number,$publicToken,$checkoutToken,$checkoutRequestHash,$name,$phone,$province,$city,$address,$postal,'local_valid',$note,$shippingQuote['mode'],$paymentForOrder,$initialPaymentStatus,$initialOrderStatus,$subtotal,$discount,!empty($coupon['valid'])?(int)$coupon['id']:null,!empty($coupon['valid'])?$coupon['code']:null,0,$shippingQuote['festival_id'],$shippingJson,$markupPercentForOrder,$markupAmountForOrder,$total]);
    $orderId=(int)$pdo->lastInsertId();if($total===0)$pdo->prepare('UPDATE orders SET paid_at=NOW() WHERE id=?')->execute([$orderId]);$pdo->prepare('INSERT INTO order_status_history(order_id,old_order_status,new_order_status,old_payment_status,new_payment_status,note,admin_id) VALUES(?,?,?,?,?,?,NULL)')->execute([$orderId,null,$initialOrderStatus,null,$initialPaymentStatus,$total===0?'ثبت سفارش صفر تومان؛ پرداخت خودکار تکمیل شد':'ثبت سفارش توسط مشتری']);
    $insert=$pdo->prepare('INSERT INTO order_items(order_id,product_id,variant_id,sku,title,variant_title,variant_options_json,unit_price,base_unit_price,unit_cost,festival_id,festival_title,festival_saving,quantity,line_total,line_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$updateProduct=$pdo->prepare('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?');$updateVariant=$pdo->prepare('UPDATE product_variants SET stock=stock-? WHERE id=? AND stock>=?');
    foreach($lines as $line){$item=$line['product'];$qty=$line['qty'];$lineCost=$line['unit_cost']*$qty;$insert->execute([$orderId,$item['id'],$line['variant']['id']??null,$line['sku'],$item['title'],$line['variant_title']?:null,json_encode($line['variant_options'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$line['price'],$line['base_price'],$line['unit_cost'],$line['festival_id'],$line['festival_title'],$line['festival_saving'],$qty,$line['line'],$lineCost]);if($line['stock_table']==='variant'){$updateVariant->execute([$qty,$line['variant']['id'],$qty]);if($updateVariant->rowCount()!==1)throw new RuntimeException('موجودی یکی از متغیرها تغییر کرده است.');inventory_record_existing_change($pdo,(int)$item['id'],(int)$line['variant']['id'],'sale',-$qty,(int)$line['unit_cost'],'order',$orderId,'رزرو برای سفارش');}else{$updateProduct->execute([$qty,$item['id'],$qty]);if($updateProduct->rowCount()!==1)throw new RuntimeException('موجودی یکی از کالاها تغییر کرده است.');inventory_record_existing_change($pdo,(int)$item['id'],null,'sale',-$qty,(int)$line['unit_cost'],'order',$orderId,'رزرو برای سفارش');}}
    foreach(array_keys($variableProducts) as $productId)sync_product_variant_summary($pdo,(int)$productId);
    if(!empty($coupon['valid']))$pdo->prepare('INSERT INTO coupon_usages(coupon_id,order_id,customer_id,phone,discount_amount) VALUES(?,?,?,?,?)')->execute([(int)$coupon['id'],$orderId,$customerId,$phone,$discount]);recover_abandoned_cart($pdo,$cartToken,$orderId,$phone);
    if($total===0)accounting_order_created($pdo,$orderId,$subtotal,0,$discount);$pdo->commit();admin_notify_new_order($orderId);sms_notify_order_created($orderId);
    if($total===0)admin_notify_payment_success($orderId);
    $order=['id'=>$orderId,'order_number'=>$number,'public_token'=>$publicToken,'payment_method'=>$paymentForOrder,'payment_status'=>$initialPaymentStatus];$redirectUrl=checkout_redirect_for_order($order);$completed=is_array($_SESSION['_completed_checkouts']??null)?$_SESSION['_completed_checkouts']:[];$completed[$checkoutToken]=['orderNumber'=>$number,'redirect'=>$redirectUrl,'publicToken'=>$publicToken,'request_hash'=>$checkoutRequestHash,'time'=>time()];$_SESSION['_last_order_public_token']=$publicToken;
    if(!$customer){
        $_SESSION['_checkout_account_notice'][$publicToken]=['guest'=>true,'time'=>time()];
        $guestLinks=is_array($_SESSION['_guest_order_account_links']??null)?$_SESSION['_guest_order_account_links']:[];
        $guestLinks[$publicToken]=['phone'=>$phone,'time'=>time()];
        foreach($guestLinks as $key=>$value)if(!is_array($value)||(int)($value['time']??0)<time()-86400)unset($guestLinks[$key]);
        $_SESSION['_guest_order_account_links']=array_slice($guestLinks,-20,null,true);
        // شماره OTP قدیمی نباید روی سفارش مهمان جدید باقی بماند.
        unset($_SESSION[customer_otp_session_key('login')]);
    }
    foreach($completed as $key=>$value)if(!is_array($value)||(int)($value['time']??0)<time()-86400)unset($completed[$key]);$_SESSION['_completed_checkouts']=array_slice($completed,-20,null,true);json_response(['ok'=>true,'orderNumber'=>$number,'redirect'=>$redirectUrl,'accountCreated'=>false]);
}catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();try{$existing=$pdo->prepare('SELECT * FROM orders WHERE checkout_token=? LIMIT 1');$existing->execute([$checkoutToken]);$known=$existing->fetch();if($known){$knownHash=(string)($known['checkout_request_hash']??'');if($knownHash!==''&&hash_equals($knownHash,$checkoutRequestHash)){$redirectUrl=checkout_redirect_for_order($known);json_response(['ok'=>true,'orderNumber'=>$known['order_number'],'redirect'=>$redirectUrl,'duplicate'=>true]);}json_response(['ok'=>false,'message'=>'این شناسه قبلاً برای سفارش دیگری استفاده شده است. دوباره دکمه ثبت سفارش را بزنید.'],409);}}catch(Throwable $lookup){log_error($lookup);}log_error($exception);$business=$exception instanceof RuntimeException&&!($exception instanceof PDOException);json_response(['ok'=>false,'message'=>$business?$exception->getMessage():'ثبت سفارش انجام نشد. دوباره تلاش کنید.'],$business?422:500);}
