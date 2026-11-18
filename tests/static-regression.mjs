import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';

const root = path.resolve(import.meta.dirname, '..');
const failures = [];
let checks = 0;
const assert = (condition, message) => {
  checks += 1;
  if (!condition) failures.push(message);
};
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const walk = directory => fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
  const absolute = path.join(directory, entry.name);
  return entry.isDirectory() ? walk(absolute) : [absolute];
});

const files = walk(root).filter(file => !file.includes(`${path.sep}.git${path.sep}`));
const phpFiles = files.filter(file => file.endsWith('.php'));
const jsFiles = files.filter(file => file.endsWith('.js') || file.endsWith('.mjs'));

for (const file of jsFiles) {
  const result = spawnSync(process.execPath, ['--check', file], { encoding: 'utf8' });
  assert(result.status === 0, `JavaScript syntax: ${path.relative(root, file)}\n${result.stderr}`);
}

const baleButtonAudit = spawnSync(process.execPath, [path.join(root, 'tests/bale-button-audit.mjs')], { encoding: 'utf8' });
assert(baleButtonAudit.status === 0, `Bale button audit failed:\n${baleButtonAudit.stdout}\n${baleButtonAudit.stderr}`);

function phpSegments(source) {
  source = source.replace(/<\?xml version="1\.0" encoding="UTF-8"\?>/g, '');
  const segments = [];
  let cursor = 0;
  while (cursor < source.length) {
    const start = source.indexOf('<?', cursor);
    if (start < 0) break;
    const codeStart = source.startsWith('<?php', start) ? start + 5 : source.startsWith('<?=', start) ? start + 3 : start + 2;
    const end = source.indexOf('?>', codeStart);
    segments.push(source.slice(codeStart, end < 0 ? source.length : end));
    if (end < 0) break;
    cursor = end + 2;
  }
  return segments.join('\n');
}

function balancedPhp(source) {
  source = phpSegments(source).replace(/<<<['"]?([A-Za-z_][A-Za-z0-9_]*)['"]?\r?\n[\s\S]*?\r?\n\1;/g, 'null;');
  const stack = [];
  const pairs = { ')': '(', ']': '[', '}': '{' };
  let quote = '';
  let lineComment = false;
  let blockComment = false;
  for (let index = 0; index < source.length; index += 1) {
    const char = source[index];
    const next = source[index + 1] || '';
    if (lineComment) {
      if (char === '\n') lineComment = false;
      continue;
    }
    if (blockComment) {
      if (char === '*' && next === '/') { blockComment = false; index += 1; }
      continue;
    }
    if (quote) {
      if (char === '\\') { index += 1; continue; }
      if (char === quote) quote = '';
      continue;
    }
    if (char === '/' && next === '/') { lineComment = true; index += 1; continue; }
    if (char === '#') { lineComment = true; continue; }
    if (char === '/' && next === '*') { blockComment = true; index += 1; continue; }
    if (char === "'" || char === '"' || char === '`') { quote = char; continue; }
    if (char === '(' || char === '[' || char === '{') stack.push(char);
    if (pairs[char]) {
      if (stack.pop() !== pairs[char]) return false;
    }
  }
  return !quote && !blockComment && stack.length === 0;
}

for (const file of phpFiles) {
  assert(balancedPhp(fs.readFileSync(file, 'utf8')), `PHP delimiters: ${path.relative(root, file)}`);
}

const version = read('VERSION.txt').trim();
assert(version === '2.10.50', 'VERSION.txt must be 2.10.50');
assert(read('service-worker.js').includes(`APP_VERSION = '${version}'`), 'Service Worker version mismatch');
assert(read('app/migrations.php').includes(`MOUSAVI_SCHEMA_VERSION = '${version}'`), 'Migration version mismatch');
assert(read('install/index.php').includes(`$schemaVersion = '${version}'`), 'Installer version mismatch');

const torobpay = read('app/torobpay.php');
for (const expected of [
  "'username' => $username",
  "'password' => $password",
  "$response['error']['user_message']",
  "$response['errorData']['message']",
  'X-TorobPay-Plugin-Version:',
  'X-TorobPay-Shop-URL:',
  "'paymentMethodTypeDto' => 'ONLINE_CREDIT'",
  "'discountAmount'",
  "'shippingAmount'",
  "'isShipmentIncluded'",
  "'taxAmount'",
  "'isTaxIncluded'",
  "'cartId' => (string)(int)$order['id']",
  "'id' => (string)(int)($row['variant_id'] ?: $row['product_id'])",
  'payment_toman_to_rial($unitPrice)',
  'torobpay_assert_eligible',
  '/api/online/offer/v1/eligible?amount=',
  'سرویس ترب‌پی موقتاً در دسترس نیست (HTTP ',
  "($verify['successful'] ?? null) !== true",
  "($settle['successful'] ?? null) !== true",
  '/api/online/payment/v1/settle',
  'torobpay-verify-',
  "['REVERT','CANCELLED','CANCELED']",
  'return $paymentUrl;',
]) assert(torobpay.includes(expected), `TorobPay contract missing: ${expected}`);
for (const forbidden of [
  "'paymentMethodTypeDto' => 'INSTALLMENT'",
  "'scope' => 'online-merchant'",
  "'grant_type' =>",
  "'cartId' => (int)$order['id']",
  "'id' => (int)($row['variant_id'] ?: $row['product_id'])",
  '$isTorobPayHost',
  'دامنه صفحه پرداخت برگشتی از ترب‌پی معتبر نیست',
  "'tax_amount' =>",
  "'shipping_amount' =>",
  "'is_tax_included' =>",
  "'is_shipment_included' =>",
  "$params['payment_token']",
]) assert(!torobpay.includes(forbidden), `Legacy TorobPay behavior remains: ${forbidden}`);
const torobOauth = torobpay.slice(torobpay.indexOf('function torobpay_access_token'), torobpay.indexOf('function torobpay_api_json'));
assert(torobOauth.includes("torobpay_http('POST', torobpay_endpoint('/api/online/v1/oauth/token')"), 'TorobPay OAuth endpoint is missing');
assert(!torobOauth.includes('application/x-www-form-urlencoded'), 'TorobPay OAuth must use a JSON body');

const orders = read('app/orders.php');
assert(orders.includes("$newOrderStatus==='cancelled'&&in_array($oldPayment,['unpaid','pending','failed'],true)"), 'Cancelled unpaid orders are not normalized to failed');
assert(orders.includes("return 'پرداخت انجام شده؛ بازپرداخت لازم است'"), 'Paid/cancelled refund warning is missing');
assert(orders.includes('function customer_cancel_unpaid_torobpay_order'), 'Customer TorobPay cancellation handler is missing');
assert(orders.includes('انصراف مشتری پیش از ورود به درگاه ترب‌پی'), 'Customer cancellation history is missing');
const torobConsent = read('torobpay-consent.php');
assert(torobConsent.includes('name="action" value="cancel_order"'), 'Consent cancellation POST action is missing');
assert(torobConsent.includes('انصراف و لغو سفارش'), 'Consent cancellation label is ambiguous');
assert(torobConsent.includes('customer_cancel_unpaid_torobpay_order($token)'), 'Consent cancellation is not wired to order cancellation');
const orderSuccess = read('order-success.php');
assert(orderSuccess.includes("if ($order && (string)$order['order_status'] !== 'cancelled')"), 'Cancelled order can still trigger created/payment SMS fallback');
const torobpayCallback = read('torobpay-callback.php');
assert(torobpayCallback.includes("$transactionStatus=$cancelled?'cancelled':'failed'"), 'TorobPay cancellation transaction status is missing');
assert(torobpayCallback.includes('هیچ پرداخت موفقی برای این تراکنش ثبت نشد'), 'TorobPay cancellation message is ambiguous');
assert(torobpayCallback.includes("$_POST['transactionId']"), 'TorobPay callback transactionId validation is missing');
assert(torobpayCallback.includes("payment_toman_to_rial((int)$order['total'])"), 'TorobPay callback rial amount validation is missing');

const snappay = read('app/snappay.php');
for (const expected of [
  'application/x-www-form-urlencoded',
  "'scope' => 'online-merchant'",
  "'forcedPaymentMethodTypes'",
  "'commissionType' => 100",
  "$response['errorData']['message']",
  "$reference = $verifiedTransactionId !== '' ? $verifiedTransactionId : ($expectedTransactionId !== '' ? $expectedTransactionId : $paymentToken);",
  "'externalSourceAmount' => 0",
  "'discountAmount'",
  "'shippingAmount'",
  "'isShipmentIncluded'",
  "'taxAmount'",
  "'isTaxIncluded'",
  'payment_toman_to_rial($unitPrice)',
  'snappay_reconcile_pending_transactions',
  'snappay_eligibility',
  'snappay_status_details',
  'snappay_update_order',
  'snappay_cancel_order',
  'snappay-verify-',
]) assert(snappay.includes(expected), `SnappPay contract missing: ${expected}`);
for (const forbidden of ['ONLINE_CREDIT', 'paymentMethodTypeDto', "'tax_amount' =>", "'shipping_amount' =>", "'is_tax_included' =>", "'is_shipment_included' =>"]) {
  assert(!snappay.includes(forbidden), `Legacy SnappPay field remains: ${forbidden}`);
}

const shop = read('shop.php');
const repositories = read('app/repositories.php');
assert(shop.includes("$outOfRange=!empty($result['out_of_range'])"), 'Shop out-of-range handling missing');
assert(shop.includes("http_response_code(404)"), 'Shop 404 handling missing');
assert(repositories.includes('$effectivePriceSql'), 'SQL festival price calculation missing');
assert(!repositories.includes('$needsFestivalAwareCollection'), 'Full in-memory catalog collection remains');

const product = read('product.php');
for (const expected of ["'ProductGroup'", "'productGroupID'", "'variesBy'", "'hasVariant'", "'isVariantOf'"]) {
  assert(product.includes(expected), `ProductGroup schema missing: ${expected}`);
}
for (const expected of ['data-torobpay-installment', 'data-torobpay-installment-amount', 'هر قسط با ترب‌پی:', "'torobpay'=>$torobpayInstallmentEnabled?torobpay_installment_quote"]) {
  assert(product.includes(expected), `TorobPay product installment UI missing: ${expected}`);
}
for (const expected of ['function torobpay_installment_count', 'function torobpay_installment_amount', 'function torobpay_installment_quote', "'count' => torobpay_installment_count()", "'supported' => torobpay_amount_is_supported($torobpayTotal)"]) {
  assert(torobpay.includes(expected), `TorobPay installment quote missing: ${expected}`);
}
const storeJs = read('assets/js/store.js');
assert(storeJs.includes('renderInstallment(match.torobpay)'), 'Variant selection does not update the TorobPay installment amount');
assert(storeJs.includes("$('[data-torobpay-installment]')"), 'TorobPay installment DOM binding is missing');
assert(read('assets/css/style.css').includes('.torobpay-installment-info'), 'TorobPay installment styling is missing');
assert(!product.includes('itemscope'), 'Duplicate product microdata remains');

const festivals = read('app/festivals.php');
for (const expected of [
  'function limited_discount_create_campaign',
  'function limited_discount_set_active',
  'function limited_discount_channels',
  'function limited_discount_sql_price_condition',
  'limited_discount_channel',
  'torobpay_old_price',
  'torobpay_new_price',
  'torobpay_popularity_reason',
  'قیمت اصلی محصول پس از ثبت تخفیف تغییر کرده است',
  '٪ تخفیف محدود',
]) assert(festivals.includes(expected), `Limited discount contract missing: ${expected}`);
assert(festivals.includes("UPDATE festivals SET is_active=? WHERE id=?"), 'Limited discount cancellation does not disable the campaign');
assert(!festivals.includes("UPDATE products SET price=? WHERE id=?"), 'Limited discount core must not overwrite the original product price');
const limitedDiscountAdmin = read('admin/torobpay-festivals.php');
for (const expected of ['ساخت تخفیف محدود','کانال هماهنگی','محصول خارج از جشنواره','حداکثر سه‌روزه','قیمت قبل','قیمت بعد از تخفیف','موجودی قابل اعلام','درصد تخفیف','دلیل جذابیت تخفیف','لغو و بازگشت قیمت','data-copy-torobpay']) {
  assert(limitedDiscountAdmin.includes(expected), `Limited discount admin field missing: ${expected}`);
}
assert(read('admin/limited-discounts.php').includes("require __DIR__ . '/torobpay-festivals.php'"), 'Limited discount admin route is missing');
const baleLimitedDiscount = read('app/bale-torobpay-festival.php');
for (const expected of ['tpf:channel:site','tpf:channel:torobpay','tpf:channel:snappay','tpf:pick:','tpf:dur:24','tpf:dur:48','tpf:dur:72','tpf:save','tpf:manage:','tpf:cancel:','tpf:cancelok:','قیمت اصلی فعال']) {
  assert(baleLimitedDiscount.includes(expected), `Bale limited discount flow missing: ${expected}`);
}
const baleBot = read('app/bale-product-bot.php');
assert(baleBot.includes("$rows[]=['⏳ تخفیف محدود']"), 'Bale limited discount main button is missing');
assert(baleBot.includes("str_starts_with($data,'tpf:')"), 'Bale limited discount callbacks are not permission protected');
const home = read('index.php');
assert(home.includes('homepage_limited_discount_products'), 'Homepage limited discount query is missing');
assert(home.includes('home-limited-discounts-title'), 'Homepage limited discount section is missing');
assert(read('app/views/product-card.php').includes('limited-discount-countdown'), 'Limited discount countdown is missing from product cards');
assert(read('app/repositories.php').includes('f.torobpay_old_price=p.price'), 'Catalog SQL does not preserve limited-discount before/after price equality');
assert(read('install/schema.sql').includes('idx_festivals_limited'), 'Fresh-install limited discount index is missing');
assert(read('app/migrations.php').includes('limited_discount_channel'), 'Limited discount migration is missing');

const torobProductApi = read('app/torob-product-api.php');
for (const expected of [
  "TOROB_API_V3_NAME = 'torob_api_v3'",
  'TOROB_API_V3_PAGE_SIZE = 100',
  "TOROB_API_V3_TOKEN_VERSION = '1'",
  "TOROB_API_V3_PUBLIC_KEY_RAW_BASE64 = 't6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0='",
  'sodium_crypto_sign_verify_detached',
  "$payload['exp']",
  "$payload['nbf']",
  "$payload['aud']",
  "['date_added_desc', 'date_updated_desc']",
  "CONCAT('product-',p.id)",
  "CONCAT('variant-',pv.id)",
  "'current_price'=>$currentPrice",
  "'image_links'=>torob_api_v3_images",
  "'spec'=>(object)$specifications",
  "'product_group_id'",
  "'date_added'",
  "'date_updated'",
]) assert(torobProductApi.includes(expected), `Torob Product API v3 contract missing: ${expected}`);
assert(!torobProductApi.includes("'current_price'=>$currentPrice*10"), 'Torob v3 price must stay in Toman');
const officialTorobDer = Buffer.from('MCowBQYDK2VwAyEAt6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=', 'base64');
const officialTorobJwk = crypto.createPublicKey({ key: officialTorobDer, format: 'der', type: 'spki' }).export({ format: 'jwk' });
assert(Buffer.from(officialTorobJwk.x, 'base64url').toString('base64') === 't6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=', 'Bundled raw Torob Ed25519 key does not match the official SPKI key');
const torobV3Endpoint = read('api/torob-products-v3.php');
for (const expected of ['REQUEST_METHOD', 'application/json', 'HTTP_X_TOROB_TOKEN', 'HTTP_X_TOROB_TOKEN_VERSION', "['error'=>'unauthorized']", 'torob_api_v3_response_data']) {
  assert(torobV3Endpoint.includes(expected), `Torob v3 endpoint guard missing: ${expected}`);
}
const legacyTorobFeed = read('api/torob-feed.php');
assert(legacyTorobFeed.includes("require __DIR__ . '/torob-products-v3.php'"), 'Legacy Torob URL does not support v3 POST requests');
assert(legacyTorobFeed.includes("$_GET['key']"), 'Legacy Torob GET feed was removed');
assert(read('robots.php').includes('api/torob-products-v3.php'), 'Torob v3 endpoint is not explicitly allowed in robots.txt');
assert(read('admin/torob-sync.php').includes('Product API v3 Endpoint'), 'Torob admin diagnostics page is missing');
assert(read('admin/partials/header.php').includes("'torob-sync.php'"), 'Torob diagnostics navigation item is missing');
assert(read('app/migrations.php').includes("2.10.50-snappay-production-compliance"), 'SnappPay compliance migration signature is missing');
assert(read('install/index.php').includes("'sodium'"), 'Fresh installer does not require Sodium');

const imageHelpers = read('app/helpers.php');
for (const expected of [
  'const PRODUCT_IMAGE_CANVAS_SIZE = 1200',
  'const PRODUCT_IMAGE_MIN_SOURCE_SIDE = 300',
  'function write_torob_ready_product_image',
  '$canvasSize = PRODUCT_IMAGE_CANVAS_SIZE',
  'imagecolorallocate($canvas, 255, 255, 255)',
  '$offsetX = intdiv($canvasSize - $contentWidth, 2)',
  '$offsetY = intdiv($canvasSize - $contentHeight, 2)',
  "return 'uploads/products/' . $baseName . '.webp'",
  "throw new RuntimeException('برای آماده‌سازی خودکار تصویر محصول، افزونه GD با پشتیبانی WebP باید فعال باشد.')",
]) assert(imageHelpers.includes(expected), `Torob-ready image pipeline missing: ${expected}`);
assert(imageHelpers.includes("save_managed_image_upload($file, 'products', 6 * 1024 * 1024, PRODUCT_IMAGE_MIN_SOURCE_SIDE, PRODUCT_IMAGE_MIN_SOURCE_SIDE, PRODUCT_IMAGE_CANVAS_SIZE)"), 'Product uploads do not use the Torob-ready limits');
for (const [sourceWidth, sourceHeight] of [[512,512],[393,328],[521,900],[1052,1280]]) {
  const scale = Math.min(1200 / sourceWidth, 1200 / sourceHeight);
  const width = Math.max(1, Math.min(1200, Math.round(sourceWidth * scale)));
  const height = Math.max(1, Math.min(1200, Math.round(sourceHeight * scale)));
  const offsetX = Math.trunc((1200 - width) / 2);
  const offsetY = Math.trunc((1200 - height) / 2);
  assert(width <= 1200 && height <= 1200, `Torob canvas overflows for ${sourceWidth}x${sourceHeight}`);
  assert(width === 1200 || height === 1200, `Torob canvas does not fit one full axis for ${sourceWidth}x${sourceHeight}`);
  assert(Math.abs((width / height) - (sourceWidth / sourceHeight)) < 0.005, `Torob canvas changes aspect ratio for ${sourceWidth}x${sourceHeight}`);
  assert(offsetX >= 0 && offsetY >= 0, `Torob canvas produces a negative offset for ${sourceWidth}x${sourceHeight}`);
}
const baleImagePipeline = read('app/bale-product-bot.php');
assert(baleImagePipeline.includes('write_torob_ready_product_image($source,$width,$height,$dir,$base)'), 'Bale product images bypass the shared Torob-ready pipeline');
assert(baleImagePipeline.includes("$rows[]=['🪄 اصلاح عکس‌های قبلی']"), 'Bale old-image repair button is missing from the main keyboard');
assert(baleImagePipeline.includes("str_starts_with($data,'adv:imgrepair:')"), 'Bale old-image repair callbacks are not permission protected');
const productGallery = read('app/product-gallery.php');
for (const expected of [
  'function product_image_reference_paths',
  'function product_image_repair_details',
  'function product_image_repair_status',
  'function product_image_repair_one',
  'function product_image_repair_batch',
  "SELECT image_path FROM product_variants WHERE image_path IS NOT NULL",
  'write_torob_ready_product_image($source,$width,$height,$directory,$baseName)',
  'UPDATE products SET image_path=? WHERE image_path=?',
  'UPDATE product_gallery SET image_path=? WHERE image_path=?',
  'UPDATE product_variants SET image_path=? WHERE image_path=?',
  'product_gallery_delete_file_if_unused($path)',
]) assert(productGallery.includes(expected), `Existing product-image repair pipeline missing: ${expected}`);
const baleAdvancedImages = read('app/bale-product-bot-advanced.php');
for (const expected of [
  "if($text==='🪄 اصلاح عکس‌های قبلی')",
  "bale_product_bot_session_save($chatId,$userId,'adv_image_repair',[])",
  "if($dataValue==='adv:imgrepair:run')",
  "bale_adv_session_matches($session,$userId,['adv_image_repair'])",
  'product_image_repair_batch(db(),5)',
  "admin_audit_event('bale_product_images_repaired'",
  'اصلاح ۵ عکس بعدی',
]) assert(baleAdvancedImages.includes(expected), `Bale existing-image repair flow missing: ${expected}`);
assert(read('admin/product-edit.php').includes('بوم سفید ۱۲۰۰×۱۲۰۰'), 'Full product editor does not explain automatic Torob image preparation');
assert(read('admin/product-quick-add.php').includes('آماده ترب؛ ۱۲۰۰×۱۲۰۰'), 'Quick product editor does not expose Torob-ready image preparation');
assert(read('admin/product-gallery.php').includes('WebP سفید ۱۲۰۰×۱۲۰۰ آماده ترب'), 'Product gallery does not expose Torob-ready image preparation');
assert(read('admin/product-variants.php').includes('WebP سفید ۱۲۰۰×۱۲۰۰ آماده ترب'), 'Variant image form does not expose Torob-ready image preparation');
assert(read('app/system-health.php').includes("'pdo_mysql', 'curl', 'openssl', 'sodium', 'gd'"), 'System health does not require GD');
assert(read('install/index.php').includes("'sodium', 'gd'"), 'Fresh installer does not require GD');
assert(read('install/index.php').includes("function_exists('imagewebp')"), 'Fresh installer does not require WebP output support');

const priceAdjustments = read('app/price-adjustments.php');
for (const expected of [
  'function price_adjustment_parse_percent',
  'function price_adjustment_calculate',
  'function price_adjustment_preview',
  'function price_adjustment_apply',
  'function price_adjustment_revert',
  'price_adjustment_catalog_state($pdo, true)',
  '$positiveVariantCount',
  'hash_equals($expectedSignature',
  "status='applied'",
  "status='reverted'",
  'UPDATE product_variants SET price=?,old_price=?',
  'UPDATE products SET price=?,old_price=?,festival_price=?',
  'فقط آخرین تغییر گروهیِ فعال قابل بازگردانی است',
  'قیمت یکی از محصولات بعداً به‌صورت دستی تغییر کرده است',
]) assert(priceAdjustments.includes(expected), `Bulk price adjustment contract missing: ${expected}`);
assert(!priceAdjustments.includes('SET cost_price='), 'Bulk price adjustment must not change purchase prices');
assert(priceAdjustments.includes("is_torobpay_special=1 AND is_active=1"), 'Limited-discount price lock is missing from bulk adjustment');

const bulkPriceAdmin = read('admin/price-adjustments.php');
for (const expected of ['تغییر گروهی قیمت‌ها','محاسبه و نمایش پیش‌نمایش','تأیید و اعمال روی همه قیمت‌ها','بازگردانی قیمت‌ها','price_adjustment_preview','price_adjustment_apply','price_adjustment_revert']) {
  assert(bulkPriceAdmin.includes(expected), `Bulk price admin flow missing: ${expected}`);
}
assert(read('admin/partials/header.php').includes("'price-adjustments.php'"), 'Bulk price admin navigation item is missing');
assert(read('app/admin-security.php').includes("'price-adjustments.php'=>'manage_products'"), 'Bulk price admin permission mapping is missing');
assert(read('admin/products.php').includes("app_url('admin/price-adjustments.php')"), 'Products page does not link to bulk price adjustment');

const baleBulkPrices = read('app/bale-price-adjustments.php');
for (const expected of ['bpa:direction:increase','bpa:direction:decrease','bpa:apply:','bpa:undo:','bpa:undo-confirm:','bulk_price_percent','bulk_price_confirm','bulk_price_undo_confirm','price_adjustment_preview','price_adjustment_apply','price_adjustment_revert']) {
  assert(baleBulkPrices.includes(expected), `Bale bulk price flow missing: ${expected}`);
}
assert(baleBot.includes("$rows[]=['📊 تغییر گروهی قیمت']"), 'Bale bulk price main button is missing');
assert(baleBot.includes("str_starts_with($data,'bpa:')"), 'Bale bulk price callbacks are not permission protected');
assert(baleBot.includes("str_starts_with($step,'bulk_price_')"), 'Bale bulk price session steps are not permission protected');

for (const schemaSource of [read('install/schema.sql'), read('app/migrations.php')]) {
  assert(schemaSource.includes('CREATE TABLE IF NOT EXISTS price_adjustment_batches'), 'Price adjustment batch table is missing');
  assert(schemaSource.includes('CREATE TABLE IF NOT EXISTS price_adjustment_items'), 'Price adjustment item table is missing');
  assert(schemaSource.includes('festival_price_before'), 'Festival price snapshots are missing');
}

const calculateAdjustedPrice = (amount, direction, basisPoints) => Math.max(1, Math.trunc((amount * (direction === 'increase' ? 10000 + basisPoints : 10000 - basisPoints) + 5000) / 10000));
assert(calculateAdjustedPrice(1_000_000, 'increase', 1000) === 1_100_000, '10% price increase calculation is incorrect');
assert(calculateAdjustedPrice(1_000_000, 'decrease', 1000) === 900_000, '10% price decrease calculation is incorrect');
assert(calculateAdjustedPrice(999, 'increase', 1) === 999, 'Basis-point rounding contract changed unexpectedly');
assert(calculateAdjustedPrice(10_001, 'decrease', 750) === 9_251, 'Decimal percentage price decrease calculation is incorrect');

const sitemap = read('sitemap.php');
assert(!sitemap.includes("$add(app_url(), date('Y-m-d')"), 'Synthetic daily home lastmod remains');
assert(sitemap.includes('MAX(p.updated_at)'), 'Product-derived sitemap lastmod missing');

for (const [relative, maximum] of [
  ['assets/images/branding/golrizan-logo.webp', 100 * 1024],
  ['assets/images/branding/golrizan-favicon.webp', 30 * 1024],
  ['assets/images/branding/golrizan-logo.png', 150 * 1024],
  ['assets/images/branding/golrizan-favicon.png', 50 * 1024],
]) {
  const absolute = path.join(root, relative);
  assert(fs.existsSync(absolute) && fs.statSync(absolute).size <= maximum, `Brand asset too large: ${relative}`);
}

const literalReferences = new Set();
const routeReferences = new Set();
const assetReferences = new Set();
for (const file of phpFiles) {
  const source = fs.readFileSync(file, 'utf8');
  for (const match of source.matchAll(/\b(?:require|require_once|include|include_once)\s+(?:BASE_PATH\s*\.\s*)?['"]\/?([^'"?]+)['"]/g)) literalReferences.add(match[1]);
  for (const match of source.matchAll(/\bapp_url\(\s*['"]([^'"]*)['"]\s*\)/g)) routeReferences.add(match[1]);
  for (const match of source.matchAll(/\basset\(\s*['"]([^'"]+)['"]\s*\)/g)) assetReferences.add(match[1]);
}
for (const relative of literalReferences) {
  if (relative === 'config/database.php') continue;
  assert(fs.existsSync(path.join(root, relative)), `Missing literal include: ${relative}`);
}
for (const route of routeReferences) {
  const relative = route.split(/[?#]/, 1)[0].replace(/^\/+/, '');
  if (!relative || ['sitemap.xml','robots.txt'].includes(relative) || relative.endsWith('/') || relative.startsWith('product/') || relative.startsWith('blog/')) continue;
  assert(fs.existsSync(path.join(root, relative)), `Missing literal route target: ${route}`);
}
for (const asset of assetReferences) {
  const relative = asset.split(/[?#]/, 1)[0].replace(/^\/+/, '');
  assert(fs.existsSync(path.join(root, 'assets', relative)), `Missing literal asset: ${asset}`);
}

if (failures.length) {
  console.error(`FAILED: ${failures.length} of ${checks} checks`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log(`PASS: ${checks} static regression checks across ${phpFiles.length} PHP and ${jsFiles.length} JavaScript files.`);
