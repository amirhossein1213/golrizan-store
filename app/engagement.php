<?php
declare(strict_types=1);

function product_approved_reviews(int $productId, int $limit=50): array
{
    $limit=max(1,min(100,$limit));
    try {
        $stmt=db()->prepare("SELECT r.*,COALESCE(NULLIF(c.full_name,''),NULLIF(c.email,''),CONCAT('مشتری ',RIGHT(c.phone,4)),'مشتری') customer_name FROM product_reviews r JOIN customers c ON c.id=r.customer_id WHERE r.product_id=? AND r.status='approved' ORDER BY r.id DESC LIMIT $limit");
        $stmt->execute([$productId]);return $stmt->fetchAll();
    } catch (PDOException $exception) {
        log_error($exception);return [];
    }
}

function product_approved_questions(int $productId, int $limit=50): array
{
    $limit=max(1,min(100,$limit));
    try {
        $stmt=db()->prepare("SELECT q.*,COALESCE(NULLIF(c.full_name,''),NULLIF(c.email,''),CONCAT('مشتری ',RIGHT(c.phone,4)),'مشتری') customer_name FROM product_questions q JOIN customers c ON c.id=q.customer_id WHERE q.product_id=? AND q.status='approved' ORDER BY q.id DESC LIMIT $limit");
        $stmt->execute([$productId]);return $stmt->fetchAll();
    } catch (PDOException $exception) {
        log_error($exception);return [];
    }
}

function customer_bought_product(int $customerId,int $productId): bool
{
    $stmt=db()->prepare("SELECT 1 FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.customer_id=? AND oi.product_id=? AND o.payment_status IN ('paid','partially_refunded','refunded') AND o.order_status<>'cancelled' LIMIT 1");
    $stmt->execute([$customerId,$productId]);return (bool)$stmt->fetchColumn();
}

function recalculate_product_rating(PDO $pdo,int $productId): void
{
    $stmt=$pdo->prepare("SELECT COUNT(*) review_count,COALESCE(AVG(rating),0) rating_average FROM product_reviews WHERE product_id=? AND status='approved'");
    $stmt->execute([$productId]);$summary=$stmt->fetch()?:[];
    $pdo->prepare('UPDATE products SET rating=?,reviews_count=? WHERE id=?')->execute([round((float)($summary['rating_average']??0),2),(int)($summary['review_count']??0),$productId]);
}

function submit_product_review(int $productId,int $customerId,int $rating,string $title,string $body): void
{
    if((string)setting('reviews_enabled','1')!=='1')throw new RuntimeException('ثبت دیدگاه غیرفعال است.');
    if(!find_product_by_id($productId))throw new RuntimeException('محصول پیدا نشد.');
    if($rating<1||$rating>5)throw new RuntimeException('امتیاز باید بین ۱ تا ۵ باشد.');
    $title=trim($title);$body=trim($body);
    if(text_length($title)>160||text_length($body)<5||text_length($body)>3000)throw new RuntimeException('متن دیدگاه باید بین ۵ تا ۳۰۰۰ کاراکتر باشد.');
    rate_limit('product-review:'.$customerId,5,86400);
    $verified=customer_bought_product($customerId,$productId)?1:0;
    try{
        $pdo=db();$pdo->prepare("INSERT INTO product_reviews(product_id,customer_id,rating,title,body,status,is_verified_purchase) VALUES(?,?,?,?,?,'pending',?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),title=VALUES(title),body=VALUES(body),status='pending',is_verified_purchase=VALUES(is_verified_purchase),admin_reply=NULL,replied_by=NULL,replied_at=NULL")->execute([$productId,$customerId,$rating,$title?:null,$body,$verified]);recalculate_product_rating($pdo,$productId);
    }catch(PDOException $e){throw new RuntimeException('ذخیره دیدگاه انجام نشد.');}
}

function submit_product_question(int $productId,int $customerId,string $question): void
{
    if((string)setting('questions_enabled','1')!=='1')throw new RuntimeException('ثبت پرسش غیرفعال است.');
    if(!find_product_by_id($productId))throw new RuntimeException('محصول پیدا نشد.');
    $question=trim($question);
    if(text_length($question)<5||text_length($question)>2000)throw new RuntimeException('متن پرسش باید بین ۵ تا ۲۰۰۰ کاراکتر باشد.');
    rate_limit('product-question:'.$customerId,5,86400);
    db()->prepare("INSERT INTO product_questions(product_id,customer_id,question,status) VALUES(?,?,?,'pending')")->execute([$productId,$customerId,$question]);
}

function customer_wishlist_ids(int $customerId): array
{
    if((string)setting('wishlist_enabled','1')!=='1')return [];
    $stmt=db()->prepare('SELECT product_id FROM customer_wishlists WHERE customer_id=? ORDER BY created_at DESC');$stmt->execute([$customerId]);return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
}

function customer_wishlist_products(int $customerId,int $limit=100): array
{
    if((string)setting('wishlist_enabled','1')!=='1')return [];
    $limit=max(1,min(100,$limit));
    $stmt=db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug FROM customer_wishlists w JOIN products p ON p.id=w.product_id JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE w.customer_id=? AND p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1 ORDER BY w.created_at DESC LIMIT $limit");
    $stmt->execute([$customerId]);return hydrate_products($stmt->fetchAll());
}

function customer_toggle_wishlist(int $customerId,int $productId): bool
{
    if((string)setting('wishlist_enabled','1')!=='1')throw new RuntimeException('علاقه‌مندی‌ها غیرفعال است.');
    if(!find_product_by_id($productId))throw new RuntimeException('محصول پیدا نشد.');
    $pdo=db();$pdo->beginTransaction();
    try{$check=$pdo->prepare('SELECT 1 FROM customer_wishlists WHERE customer_id=? AND product_id=? FOR UPDATE');$check->execute([$customerId,$productId]);
        if($check->fetchColumn()){$pdo->prepare('DELETE FROM customer_wishlists WHERE customer_id=? AND product_id=?')->execute([$customerId,$productId]);$active=false;}
        else{$pdo->prepare('INSERT INTO customer_wishlists(customer_id,product_id) VALUES(?,?)')->execute([$customerId,$productId]);$active=true;}
        $pdo->commit();return $active;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function comparison_products(array $ids): array
{
    if((string)setting('compare_enabled','1')!=='1')return [];
    $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0))),0,4);
    if(!$ids)return [];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT p.*,c.name category_name,c.slug category_slug,b.name brand_name,b.slug brand_slug FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE p.id IN ($marks) AND p.status='active' AND p.price>0 AND c.is_active=1 AND b.is_active=1");
    $stmt->execute($ids);$map=[];foreach(hydrate_products($stmt->fetchAll()) as $row)$map[(int)$row['id']]=$row;
    $out=[];$categoryId=null;
    foreach($ids as $id){
        if(!isset($map[$id]))continue;
        $product=$map[$id];$productCategoryId=(int)$product['category_id'];
        if($categoryId===null)$categoryId=$productCategoryId;
        if($productCategoryId===$categoryId)$out[]=$product;
    }
    return $out;
}
