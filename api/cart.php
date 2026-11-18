<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (!is_post()) json_response(['ok'=>false,'message'=>'روش درخواست نامعتبر است.'], 405);
rate_limit('cart', 120, 300);
$data = input_json();
$couponCode=normalize_coupon_code(scalar_string($data['coupon_code']??''));
$cartToken=valid_cart_token(scalar_string($data['cart_token']??''));
$payment=scalar_string($data['payment']??'');$torobMarkupBps=$payment==='torobpay'&&payment_method_available('torobpay')?torobpay_markup_basis_points():0;$torobMarkupAmount=0;
$raw = is_array($data['items'] ?? null) ? array_slice($data['items'], 0, 100) : [];
$products = cart_products($raw);
$snappayQuoteProducts=$products;
$checkSnappayEligibility=!empty($data['check_snappay_eligibility']);
$snappayProductEligible=true;
foreach($products as $snappayProduct){
    if(empty($snappayProduct['_snappay_enabled'])){$snappayProductEligible=false;break;}
}
if($torobMarkupBps>0){foreach($products as &$quotedProduct){$original=(int)$quotedProduct['price'];$quotedProduct['price']=torobpay_apply_markup($original,$torobMarkupBps);$quotedProduct['_torob_markup_amount']=(int)$quotedProduct['price']-$original;$quotedProduct['base_price']=torobpay_apply_markup((int)($quotedProduct['base_price']??$original),$torobMarkupBps);$quotedProduct['festival_saving']=max(0,(int)$quotedProduct['base_price']-(int)$quotedProduct['price']);}unset($quotedProduct);}
$items = [];
foreach ($products as $product) {
    $quantity = (int)$product['quantity'];
    $price = (int)$product['price'];
    $basePrice = (int)($product['base_price'] ?? $price);
    $torobMarkupAmount += (int)($product['_torob_markup_amount']??0) * $quantity;
    if ($quantity < 1) continue;
    if ($price <= 0 || $price > MAX_MONEY_AMOUNT || $price > intdiv(MAX_MONEY_AMOUNT, $quantity)) {
        json_response(['ok'=>false,'message'=>'قیمت یا تعداد یکی از کالاها از سقف مجاز بیشتر است.'], 422);
    }
    $lineTotal = $price * $quantity;
    $checkoutAttributes = product_checkout_attributes((int)$product['id'], (array)($product['variant_options'] ?? []));
    $items[] = [
        'id'=>(int)$product['id'],
        'variant_id'=>(int)($product['variant_id']??0),
        'cart_key'=>(string)($product['cart_key']??((int)$product['id'].':0')),
        'slug'=>$product['slug'],
        'sku'=>$product['sku'],
        'title'=>$product['title'],
        'variant_title'=>$product['variant_title']??null,
        'variant_options'=>$product['variant_options']??[],
        'checkout_attributes'=>$checkoutAttributes,
        'short_title'=>$product['short_title'],
        'price'=>$price,
        'base_price'=>$basePrice,
        'festival_title'=>$product['festival_title'],
        'festival_label'=>$product['festival_label'],
        'festival_saving'=>(int)$product['festival_saving'],
        'stock'=>(int)$product['stock'],
        'quantity'=>$quantity,
        'line_total'=>$lineTotal,
        'base_line_total'=>$basePrice*$quantity,
        'image'=>public_image((string)$product['image_path']),
        'url'=>product_url($product),
        'warranty'=>$product['warranty'],
        'authenticity_guarantee'=>(int)($product['authenticity_guarantee']??0),
        'safe_shipping_guarantee'=>(int)($product['safe_shipping_guarantee']??0),
        'festival_enabled'=>(int)($product['festival_enabled']??0),
        'festival_free_shipping'=>(int)($product['festival_free_shipping']??0),
        'color'=>$product['color'],
    ];
}
try { $totals = cart_totals($products); }
catch (RuntimeException $e) { json_response(['ok'=>false,'message'=>$e->getMessage()], 422); }
$customer=customer_user();$coupon=coupon_quote(db(),$couponCode,(int)$totals['subtotal'],$customer?(int)$customer['id']:null,$customer?(string)$customer['phone']:'');
$discount=!empty($coupon['valid'])?(int)$coupon['discount']:0;
$finalTotal=max(0,(int)$totals['total']-$discount);
$snappayEligibility=null;
if($checkSnappayEligibility){
    if(!payment_method_available('snappay'))$snappayEligibility=['eligible'=>false,'title'=>'','description'=>'','status'=>'disabled'];
    elseif(!$snappayProductEligible)$snappayEligibility=['eligible'=>false,'title'=>'','description'=>'','status'=>'product_not_eligible'];
    else{
        try{$snappayTotals=cart_totals($snappayQuoteProducts);$snappayCoupon=coupon_quote(db(),$couponCode,(int)$snappayTotals['subtotal'],$customer?(int)$customer['id']:null,$customer?(string)$customer['phone']:'');$snappayDiscount=!empty($snappayCoupon['valid'])?(int)$snappayCoupon['discount']:0;$snappayEligibility=snappay_eligibility(max(0,(int)$snappayTotals['total']-$snappayDiscount));}
        catch(Throwable $snappayQuoteError){log_error($snappayQuoteError);$snappayEligibility=['eligible'=>false,'title'=>'','description'=>'','status'=>'quote_error'];}
    }
}
track_abandoned_cart($cartToken,$raw,(int)$totals['subtotal'],$couponCode);
json_response([
    'ok'=>true,
    'items'=>$items,
    'subtotal'=>$totals['subtotal'],
    'base_subtotal'=>$totals['base_subtotal'],
    'festival_saving'=>$totals['festival_saving'],
    'shipping'=>0,
    'shipping_details'=>$totals['shipping'],
    'discount'=>$discount,
    'coupon'=>$couponCode!==''?$coupon:null,
    'payment_markup_percent'=>$torobMarkupBps>0?torobpay_markup_percent():'0',
    'payment_markup_amount'=>$torobMarkupAmount,
    'total'=>$finalTotal,
    'snappay_eligibility'=>$snappayEligibility,
]);
