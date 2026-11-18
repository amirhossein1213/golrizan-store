import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const files = [
  'app/bale-product-bot.php',
  'app/bale-product-bot-advanced.php',
  'app/bale-torobpay-festival.php',
  'app/bale-price-adjustments.php',
  'app/bale-dashboard.php',
];
const source = files.map(file => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
const core = fs.readFileSync(path.join(root, 'app/bale-product-bot.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'app/bale-dashboard.php'), 'utf8');
const failures = [];
let checks = 0;
const assert = (condition, message) => { checks += 1; if (!condition) failures.push(message); };

assert(!/['"]callback_data['"]\s*=>\s*['"]cancel['"]/.test(source), 'A raw shared cancel callback remains');
for (const required of [
  'function bale_product_bot_callback_family',
  'function bale_product_bot_session_family',
  'function bale_product_bot_callback_matches_session',
  'function bale_product_bot_cancel_callback',
  "preg_match('/^cancel:([a-f0-9]{16})$/D'",
  "'این دکمه مربوط به عملیات دیگری است؛ عملیات فعلی دست‌نخورده ماند.'",
  "'این دکمه متعلق به نسخه قدیمی است؛ عملیات فعلی دست‌نخورده ماند.",
  "preg_match('/^[A-Za-z0-9:_-]{1,64}$/D'",
  "throw new RuntimeException('دو دکمه با شناسه یکسان در یک صفحه ساخته شده‌اند.')",
  "if ($callbackFamily === 'unknown') return false;",
  'bale_product_bot_session_family($step)',
]) assert(core.includes(required), `Missing button isolation guard: ${required}`);

const guardIndex = core.indexOf('bale_product_bot_callback_matches_session($session,$dataValue)');
for (const delegate of ['bale_torobpay_festival_callback(', 'bale_price_adjustment_callback(', 'bale_product_bot_advanced_callback(']) {
  assert(guardIndex >= 0 && guardIndex < core.indexOf(delegate, guardIndex), `Cross-flow guard must run before ${delegate}`);
}

const allowedNamespaces = new Set(['adv','attr','bpa','cancel','cd','nav','opt','ord','page','pd','pe','pick','price','tpf']);
const literalCallbacks = [...source.matchAll(/['"]callback_data['"]\s*=>\s*'([^']+)'/g)].map(match => match[1]);
assert(literalCallbacks.length >= 70, 'Too few callback declarations were audited');
for (const callback of literalCallbacks) {
  const staticPart = callback.split("'.")[0];
  assert(Buffer.byteLength(staticPart, 'utf8') <= 64, `Static callback part exceeds 64 bytes: ${staticPart}`);
  assert(/^[A-Za-z0-9:_-]+$/.test(staticPart), `Static callback contains unsupported characters: ${staticPart}`);
  assert(allowedNamespaces.has(staticPart.split(':')[0]), `Unregistered callback namespace: ${staticPart}`);
}

const boundarySamples = [
  'bpa:undo-confirm:18446744073709551615:0123456789abcdef',
  'adv:img:primary:18446744073709551615:18446744073709551615',
  'ord:status:18446744073709551615:ready_to_ship',
  'tpf:cancelok:18446744073709551615:0123456789abcdef',
  'attr:group:18446744073709551615:999999:999999',
  'cancel:0123456789abcdef',
];
for (const callback of boundarySamples) {
  assert(Buffer.byteLength(callback, 'utf8') <= 64, `Boundary callback exceeds Bale limit: ${callback}`);
  assert(/^[A-Za-z0-9:_-]+$/.test(callback), `Boundary callback fails the runtime validator: ${callback}`);
}

const mainSections = ['📦 محصولات','🧾 سفارش‌ها','💰 قیمت و تخفیف','📊 گزارش‌ها','💬 پشتیبانی','⚙️ تنظیمات و دسترسی','🏠 داشبورد'];
assert(new Set(mainSections).size === mainSections.length, 'Dashboard contains duplicate main labels');
for (const label of mainSections) {
  assert(dashboard.includes(`'${label}'`), `Dashboard button is missing: ${label}`);
}
for (const required of [
  'function bale_dashboard_reply_keyboard',
  'function bale_dashboard_main_keyboard',
  'function bale_dashboard_section_keyboard',
  'function bale_dashboard_command_allowed',
  'function bale_dashboard_handle_command',
  "$seen[$label]",
  "['📈 افزایش/کاهش درصدی','⏳ تخفیف محدود']",
  "['🎫 تیکت‌های مشتریان']",
  "['↩️ درخواست‌های مرجوعی']",
  "['💳 درصد افزایش ترب‌پی']",
]) assert(dashboard.includes(required), `Dashboard contract missing: ${required}`);

for (const family of ['limited_discount','bulk_price','orders','returns','tickets','low_stock','taxonomy','image_repair','images','quick_edit','products']) {
  assert(core.includes(`return '${family}'`), `Callback/session family is not registered: ${family}`);
}
assert(core.includes("$sessionFamily === 'low_stock' && $callbackFamily === 'quick_edit'"), 'Intentional low-stock to quick-edit transition is missing');
assert(core.includes("$data['_flow_token']=$token"), 'Session flow token is not persisted');
assert(core.includes("hash_equals($sessionToken,(string)$cancelMatch[1])"), 'Cancel token is not compared safely');
assert(core.includes("require_once BASE_PATH . '/app/bale-dashboard.php';"), 'Dashboard module is not loaded');

const festival = fs.readFileSync(path.join(root, 'app/bale-torobpay-festival.php'), 'utf8');
for (const required of [
  "'torobpay_festival_manage'",
  "'torobpay_festival_cancel_confirm'",
  "preg_match('/^tpf:cancelok:(\\d+):([a-f0-9]{16})$/D'",
  "hash_equals($expectedNonce,(string)$match[2])",
  "'این تأیید لغو منقضی شده است؛ هیچ قیمتی تغییر نکرد.'",
]) assert(festival.includes(required), `Limited-discount cancellation guard missing: ${required}`);

if (failures.length) {
  console.error(`FAIL: ${failures.length} of ${checks} Bale button checks failed`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log(`PASS: ${checks} Bale button isolation and dashboard checks`);
