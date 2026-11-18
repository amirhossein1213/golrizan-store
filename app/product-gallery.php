<?php
declare(strict_types=1);

function product_gallery_images(int $productId, string $fallbackPath = '', string $fallbackAlt = '', bool $respectSetting = true): array
{
    $rows = [];
    if (!$respectSetting || (string)setting('gallery_enabled', '1') === '1') {
        try {
            $stmt = db()->prepare('SELECT id,product_id,image_path,alt_text,sort_order,is_primary FROM product_gallery WHERE product_id=? ORDER BY is_primary DESC,sort_order,id LIMIT 12');
            $stmt->execute([$productId]);
            $rows = $stmt->fetchAll();
        } catch (PDOException $exception) {
            log_error($exception);
            $rows = [];
        }
    }
    if (!$rows && $fallbackPath !== '') {
        $rows[] = ['id'=>0,'product_id'=>$productId,'image_path'=>$fallbackPath,'alt_text'=>$fallbackAlt,'sort_order'=>0,'is_primary'=>1];
    }
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['product_id'] = (int)$row['product_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['is_primary'] = (int)$row['is_primary'];
        $row['url'] = public_image((string)$row['image_path']);
        $row['thumbnail_url'] = public_image_thumbnail((string)$row['image_path']);
        $row['srcset'] = public_image_srcset((string)$row['image_path']);
        $row['alt_text'] = trim((string)$row['alt_text']) ?: $fallbackAlt;
    }
    unset($row);
    return $rows;
}


function product_gallery_ensure_primary_row(PDO $pdo, int $productId, string $fallbackPath = '', string $fallbackAlt = ''): int
{
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_gallery WHERE product_id=?');
    $countStmt->execute([$productId]);
    $count = (int)$countStmt->fetchColumn();
    if ($count > 0 || trim($fallbackPath) === '') return $count;

    $insert = $pdo->prepare('INSERT INTO product_gallery(product_id,image_path,alt_text,sort_order,is_primary) VALUES(?,?,?,0,1)');
    $insert->execute([$productId, $fallbackPath, trim($fallbackAlt) ?: null]);
    return 1;
}

function product_gallery_sync_primary(PDO $pdo, int $productId): void
{
    $stmt = $pdo->prepare('SELECT id,image_path FROM product_gallery WHERE product_id=? ORDER BY is_primary DESC,sort_order,id LIMIT 1 FOR UPDATE');
    $stmt->execute([$productId]);
    $primary = $stmt->fetch();
    if (!$primary) {
        $pdo->prepare('UPDATE products SET image_path=NULL WHERE id=?')->execute([$productId]);
        return;
    }
    $pdo->prepare('UPDATE product_gallery SET is_primary=(id=?) WHERE product_id=?')->execute([(int)$primary['id'],$productId]);
    $pdo->prepare('UPDATE products SET image_path=? WHERE id=?')->execute([(string)$primary['image_path'],$productId]);
}

function product_gallery_delete_file_if_unused(string $path): void
{
    if ($path === '') return;
    $checks = [
        ['SELECT COUNT(*) FROM product_gallery WHERE image_path=?',[$path]],
        ['SELECT COUNT(*) FROM products WHERE image_path=?',[$path]],
        ['SELECT COUNT(*) FROM product_variants WHERE image_path=?',[$path]],
    ];
    foreach ($checks as [$sql,$params]) {
        $stmt = db()->prepare($sql);$stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) return;
    }
    delete_managed_upload($path);
}

/** @return array<int,string> */
function product_image_reference_paths(PDO $pdo): array
{
    $sql = "SELECT image_path FROM products WHERE image_path IS NOT NULL AND image_path<>''
            UNION
            SELECT image_path FROM product_gallery WHERE image_path IS NOT NULL AND image_path<>''
            UNION
            SELECT image_path FROM product_variants WHERE image_path IS NOT NULL AND image_path<>''
            ORDER BY image_path
            LIMIT 10000";
    $paths = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $path = trim((string)$path);
        if ($path !== '') $paths[$path] = $path;
    }
    return array_values($paths);
}

/** @return array{path:string,internal:?string,status:string,reason:string,repairable:bool,width:int,height:int,mime:string,low_source:bool} */
function product_image_repair_details(string $path): array
{
    $path = trim($path);
    $result = [
        'path'=>$path,
        'internal'=>null,
        'status'=>'unavailable',
        'reason'=>'مسیر تصویر داخلی و معتبر نیست',
        'repairable'=>false,
        'width'=>0,
        'height'=>0,
        'mime'=>'',
        'low_source'=>false,
    ];
    $internal = safe_internal_image_path($path);
    if ($internal === null) return $result;
    $result['internal'] = $internal;
    $absolute = BASE_PATH.'/'.$internal;
    if (!is_file($absolute)) {
        $result['reason'] = 'فایل تصویر پیدا نشد';
        return $result;
    }
    $dimensions = @getimagesize($absolute);
    if (!is_array($dimensions) || (int)($dimensions[0] ?? 0) < 1 || (int)($dimensions[1] ?? 0) < 1) {
        $result['reason'] = 'فایل تصویر خوانا نیست';
        return $result;
    }
    $width = (int)$dimensions[0];
    $height = (int)$dimensions[1];
    $mime = strtolower((string)($dimensions['mime'] ?? ''));
    $result['width'] = $width;
    $result['height'] = $height;
    $result['mime'] = $mime;
    $result['low_source'] = min($width,$height) < 600;
    if ($mime === 'image/webp' && $width === PRODUCT_IMAGE_CANVAS_SIZE && $height === PRODUCT_IMAGE_CANVAS_SIZE) {
        $result['status'] = 'ready';
        $result['reason'] = 'آماده ترب';
        return $result;
    }
    if ($width > 8000 || $height > 8000 || ($width * $height) > 30000000) {
        $result['reason'] = 'ابعاد فایل بیش از حد بزرگ است';
        return $result;
    }
    $decoder = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };
    if ($decoder === '' || !function_exists($decoder)
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled')
        || !function_exists('imagewebp')) {
        $result['reason'] = $decoder === '' ? 'فرمت قدیمی قابل تبدیل نیست' : 'افزونه GD/WebP هاست کامل نیست';
        return $result;
    }
    $result['status'] = 'pending';
    $result['reason'] = 'نیازمند اصلاح';
    $result['repairable'] = true;
    return $result;
}

/** @return array{total:int,ready:int,pending:int,unavailable:int,low_source:int,paths:array<int,string>,reasons:array<string,int>} */
function product_image_repair_status(PDO $pdo): array
{
    $summary = ['total'=>0,'ready'=>0,'pending'=>0,'unavailable'=>0,'low_source'=>0,'paths'=>[],'reasons'=>[]];
    foreach (product_image_reference_paths($pdo) as $path) {
        $summary['total']++;
        $details = product_image_repair_details($path);
        if ($details['status'] === 'ready') {
            $summary['ready']++;
        } elseif ($details['repairable']) {
            $summary['pending']++;
            $summary['paths'][] = $path;
            if ($details['low_source']) $summary['low_source']++;
        } else {
            $summary['unavailable']++;
            $reason = (string)$details['reason'];
            $summary['reasons'][$reason] = (int)($summary['reasons'][$reason] ?? 0) + 1;
        }
    }
    return $summary;
}

/** @return array{status:string,path:string,new_path:string,width:int,height:int,low_source:bool,affected:int} */
function product_image_repair_one(PDO $pdo, string $path): array
{
    $details = product_image_repair_details($path);
    if ($details['status'] === 'ready') {
        return ['status'=>'skipped','path'=>$path,'new_path'=>$path,'width'=>$details['width'],'height'=>$details['height'],'low_source'=>$details['low_source'],'affected'=>0];
    }
    if (!$details['repairable'] || $details['internal'] === null) {
        throw new RuntimeException((string)$details['reason']);
    }

    $absolute = BASE_PATH.'/'.(string)$details['internal'];
    $decoder = match ($details['mime']) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };
    $source = @$decoder($absolute);
    if ($source === false) throw new RuntimeException('بازکردن فایل تصویر قدیمی انجام نشد.');
    $width = (int)$details['width'];
    $height = (int)$details['height'];
    try {
        if ($details['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($absolute);
            $orientation = (int)($exif['Orientation'] ?? 1);
            if (in_array($orientation,[3,6,8],true) && function_exists('imagerotate')) {
                $angle = $orientation === 3 ? 180 : ($orientation === 6 ? -90 : 90);
                $rotated = @imagerotate($source,$angle,0);
                if ($rotated !== false) {
                    imagedestroy($source);
                    $source = $rotated;
                    $width = imagesx($source);
                    $height = imagesy($source);
                }
            }
        }
        $directory = BASE_PATH.'/uploads/products';
        if (!is_dir($directory) && !mkdir($directory,0755,true)) throw new RuntimeException('پوشه تصاویر محصول قابل ساخت نیست.');
        $baseName = date('Ymd').'-'.bin2hex(random_bytes(8));
        $newPath = write_torob_ready_product_image($source,$width,$height,$directory,$baseName);
    } finally {
        imagedestroy($source);
    }
    if ($newPath === null) throw new RuntimeException('ساخت نسخه استاندارد تصویر انجام نشد.');

    $affected = 0;
    try {
        $pdo->beginTransaction();
        foreach ([
            'UPDATE products SET image_path=? WHERE image_path=?',
            'UPDATE product_gallery SET image_path=? WHERE image_path=?',
            'UPDATE product_variants SET image_path=? WHERE image_path=?',
        ] as $sql) {
            $update = $pdo->prepare($sql);
            $update->execute([$newPath,$path]);
            $affected += $update->rowCount();
        }
        if ($affected < 1) {
            $pdo->rollBack();
            delete_managed_upload($newPath);
            return ['status'=>'skipped','path'=>$path,'new_path'=>$path,'width'=>$width,'height'=>$height,'low_source'=>min($width,$height)<600,'affected'=>0];
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        delete_managed_upload($newPath);
        throw $exception;
    }
    product_gallery_delete_file_if_unused($path);
    return ['status'=>'repaired','path'=>$path,'new_path'=>$newPath,'width'=>$width,'height'=>$height,'low_source'=>min($width,$height)<600,'affected'=>$affected];
}

/** @return array{before:array<string,mixed>,after:array<string,mixed>,attempted:int,repaired:int,skipped:int,failed:int,low_source:int,errors:array<int,string>} */
function product_image_repair_batch(PDO $pdo, int $limit = 5): array
{
    $limit = max(1,min(10,$limit));
    $before = product_image_repair_status($pdo);
    $result = ['before'=>$before,'after'=>$before,'attempted'=>0,'repaired'=>0,'skipped'=>0,'failed'=>0,'low_source'=>0,'errors'=>[]];
    foreach (array_slice($before['paths'],0,$limit) as $path) {
        $result['attempted']++;
        try {
            $repair = product_image_repair_one($pdo,(string)$path);
            if ($repair['status'] === 'repaired') {
                $result['repaired']++;
                if ($repair['low_source']) $result['low_source']++;
            } else {
                $result['skipped']++;
            }
        } catch (Throwable $exception) {
            $result['failed']++;
            log_error($exception);
            if (count($result['errors']) < 3) $result['errors'][] = text_substr($exception->getMessage(),0,160);
        }
    }
    $result['after'] = product_image_repair_status($pdo);
    return $result;
}
