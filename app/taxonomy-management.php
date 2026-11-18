<?php
declare(strict_types=1);

/** Delete a managed image only when no known storefront record references it anymore. */
function taxonomy_delete_image_if_unused(string $path): void
{
    $path = trim($path);
    if ($path === '') return;
    $checks = [
        ['categories','image_path'],
        ['products','image_path'],
        ['product_gallery','image_path'],
        ['product_variants','image_path'],
        ['home_banners','image_path'],
    ];
    foreach ($checks as [$table,$column]) {
        try {
            if (!db_table_exists(db(), $table) || !db_column_exists(db(), $table, $column)) continue;
            $stmt = db()->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");
            $stmt->execute([$path]);
            if ((int)$stmt->fetchColumn() > 0) return;
        } catch (Throwable $error) {
            log_error($error);
            return; // fail closed: never delete a file when reference checks are uncertain
        }
    }
    delete_managed_upload($path);
}

function taxonomy_clear_deleted_default(PDO $pdo, string $type, int $id): void
{
    $key = $type === 'category' ? 'bale_product_bot_default_category_id' : 'bale_product_bot_default_brand_id';
    $stmt = $pdo->prepare("UPDATE settings SET setting_value='0' WHERE setting_key=? AND CAST(setting_value AS UNSIGNED)=?");
    $stmt->execute([$key,$id]);
}

/** @return array{ok:bool,message:string,category_id:int,name:string,moved_products:int,fallback_name:string} */
function taxonomy_delete_category(int $categoryId, array $actor = []): array
{
    if ($categoryId < 1) return ['ok'=>false,'message'=>'دسته‌بندی معتبر نیست.','category_id'=>0,'name'=>'','moved_products'=>0,'fallback_name'=>''];
    $pdo = db();
    $image = '';$name = '';$moved = 0;$fallbackName = '';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id,name,slug,image_path FROM categories WHERE id=? FOR UPDATE');
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('دسته‌بندی پیدا نشد یا قبلاً حذف شده است.');
        $name = (string)$row['name'];
        $image = (string)($row['image_path'] ?? '');
        if ((string)$row['slug'] === 'festival') throw new RuntimeException('دسته جشنواره سیستمی است و قابل حذف نیست.');

        $festival = $pdo->prepare("SELECT COUNT(*) FROM festivals WHERE scope='category' AND target_id=?");
        $festival->execute([$categoryId]);
        if ((int)$festival->fetchColumn() > 0) throw new RuntimeException('این دسته‌بندی هدف مستقیم یک جشنواره است. ابتدا جشنواره را ویرایش یا حذف کنید.');

        $count = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id=?');
        $count->execute([$categoryId]);
        $moved = (int)$count->fetchColumn();
        if ($moved > 0) {
            $fallback = $pdo->prepare("SELECT id,name FROM categories WHERE id<>? AND slug LIKE 'uncategorized%' ORDER BY (slug='uncategorized') DESC,id ASC LIMIT 1 FOR UPDATE");
            $fallback->execute([$categoryId]);
            $fallbackRow = $fallback->fetch();
            if (!$fallbackRow) {
                $slug = 'uncategorized';$i = 2;
                $check = $pdo->prepare('SELECT 1 FROM categories WHERE slug=? LIMIT 1');
                while (true) {
                    $check->execute([$slug]);
                    if (!$check->fetchColumn()) break;
                    $slug = 'uncategorized-' . $i++;
                    if ($i > 9999) { $slug = 'uncategorized-' . bin2hex(random_bytes(4)); break; }
                }
                $pdo->prepare('INSERT INTO categories(name,slug,image_path,meta_title,meta_description,sort_order,is_active) VALUES(?,?,NULL,NULL,NULL,999999,1)')
                    ->execute(['بدون دسته‌بندی',$slug]);
                $fallbackId = (int)$pdo->lastInsertId();
                $fallbackName = 'بدون دسته‌بندی';
            } else {
                $fallbackId = (int)$fallbackRow['id'];
                $fallbackName = (string)$fallbackRow['name'];
            }
            if ($fallbackId < 1 || $fallbackId === $categoryId) throw new RuntimeException('ایجاد دسته جایگزین امن انجام نشد.');
            $move = $pdo->prepare('UPDATE products SET category_id=? WHERE category_id=?');
            $move->execute([$fallbackId,$categoryId]);
            if ($move->rowCount() !== $moved) throw new RuntimeException('انتقال همه محصولات دسته قبل از حذف کامل نشد.');
        }

        taxonomy_clear_deleted_default($pdo,'category',$categoryId);
        $delete = $pdo->prepare('DELETE FROM categories WHERE id=?');
        $delete->execute([$categoryId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('حذف دسته‌بندی انجام نشد.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'message'=>$error->getMessage(),'category_id'=>$categoryId,'name'=>$name,'moved_products'=>$moved,'fallback_name'=>$fallbackName];
    }
    taxonomy_delete_image_if_unused($image);
    $audit = ['name'=>$name,'moved_products'=>$moved,'fallback_category'=>$fallbackName] + $actor;
    admin_audit_event((string)($actor['audit_event'] ?? 'category_deleted'),'category',$categoryId,$audit);
    $message = 'دسته‌بندی حذف شد.';
    if ($moved > 0) $message .= ' تعداد ' . fa_number($moved) . ' محصول به دسته «' . $fallbackName . '» منتقل شد.';
    return ['ok'=>true,'message'=>$message,'category_id'=>$categoryId,'name'=>$name,'moved_products'=>$moved,'fallback_name'=>$fallbackName];
}

/** @return array{ok:bool,message:string,brand_id:int,name:string,moved_products:int,fallback_name:string} */
function taxonomy_delete_brand(int $brandId, array $actor = []): array
{
    if ($brandId < 1) return ['ok'=>false,'message'=>'برند معتبر نیست.','brand_id'=>0,'name'=>'','moved_products'=>0,'fallback_name'=>''];
    $pdo = db();$name='';$moved=0;$fallbackName='';
    try {
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('SELECT id,name,slug FROM brands WHERE id=? FOR UPDATE');$stmt->execute([$brandId]);$row=$stmt->fetch();
        if(!$row) throw new RuntimeException('برند پیدا نشد یا قبلاً حذف شده است.');
        $name=(string)$row['name'];
        $festival=$pdo->prepare("SELECT COUNT(*) FROM festivals WHERE scope='brand' AND target_id=?");$festival->execute([$brandId]);
        if((int)$festival->fetchColumn()>0) throw new RuntimeException('این برند هدف مستقیم یک جشنواره است. ابتدا جشنواره را ویرایش یا حذف کنید.');
        $count=$pdo->prepare('SELECT COUNT(*) FROM products WHERE brand_id=?');$count->execute([$brandId]);$moved=(int)$count->fetchColumn();
        if($moved>0){
            $fallback=$pdo->prepare("SELECT id,name FROM brands WHERE id<>? AND slug LIKE 'unbranded%' ORDER BY (slug='unbranded') DESC,id ASC LIMIT 1 FOR UPDATE");$fallback->execute([$brandId]);$f=$fallback->fetch();
            if(!$f){
                $slug='unbranded';$i=2;$check=$pdo->prepare('SELECT 1 FROM brands WHERE slug=? LIMIT 1');
                while(true){$check->execute([$slug]);if(!$check->fetchColumn())break;$slug='unbranded-'.$i++;if($i>9999){$slug='unbranded-'.bin2hex(random_bytes(4));break;}}
                $pdo->prepare('INSERT INTO brands(name,slug,sort_order,is_active) VALUES(?,?,999999,1)')->execute(['بدون برند',$slug]);
                $fallbackId=(int)$pdo->lastInsertId();$fallbackName='بدون برند';
            } else {$fallbackId=(int)$f['id'];$fallbackName=(string)$f['name'];}
            if($fallbackId<1||$fallbackId===$brandId)throw new RuntimeException('ایجاد برند جایگزین امن انجام نشد.');
            $move=$pdo->prepare('UPDATE products SET brand_id=? WHERE brand_id=?');$move->execute([$fallbackId,$brandId]);
            if($move->rowCount()!==$moved)throw new RuntimeException('انتقال همه محصولات برند قبل از حذف کامل نشد.');
        }
        taxonomy_clear_deleted_default($pdo,'brand',$brandId);
        $del=$pdo->prepare('DELETE FROM brands WHERE id=?');$del->execute([$brandId]);if($del->rowCount()!==1)throw new RuntimeException('حذف برند انجام نشد.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return ['ok'=>false,'message'=>$e->getMessage(),'brand_id'=>$brandId,'name'=>$name,'moved_products'=>$moved,'fallback_name'=>$fallbackName];}
    $audit=['name'=>$name,'moved_products'=>$moved,'fallback_brand'=>$fallbackName]+$actor;
    admin_audit_event((string)($actor['audit_event']??'brand_deleted'),'brand',$brandId,$audit);
    $msg='برند حذف شد.';if($moved>0)$msg.=' تعداد '.fa_number($moved).' محصول به «'.$fallbackName.'» منتقل شد.';
    return ['ok'=>true,'message'=>$msg,'brand_id'=>$brandId,'name'=>$name,'moved_products'=>$moved,'fallback_name'=>$fallbackName];
}
