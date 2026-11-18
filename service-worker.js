'use strict';

const APP_VERSION = '2.10.50';
const STATIC_CACHE = `golrizan-static-${APP_VERSION}`;
const IMAGE_CACHE = `golrizan-images-${APP_VERSION}`;
const OFFLINE_URL = new URL('offline.html', self.registration.scope).href;
const PRECACHE = [
  OFFLINE_URL,
  new URL('assets/images/pwa/icon-192.png', self.registration.scope).href,
  new URL('assets/images/pwa/icon-512.png', self.registration.scope).href,
  new URL('assets/images/pwa/maskable-512.png', self.registration.scope).href,
  new URL(`assets/css/style.css?v=${APP_VERSION}`, self.registration.scope).href,
  new URL(`assets/fonts/Vazirmatn.woff2?v=${APP_VERSION}`, self.registration.scope).href,
  new URL(`assets/js/store.js?v=${APP_VERSION}`, self.registration.scope).href,
  new URL(`assets/js/pwa.js?v=${APP_VERSION}`, self.registration.scope).href
];

const SENSITIVE_PATHS = [
  '/admin/', '/api/', '/install/', '/storage/', '/config/', '/app/',
  '/checkout.php', '/cart.php', '/payment-start.php', '/payment-callback.php',
  '/torobpay-start.php', '/torobpay-consent.php', '/torobpay-callback.php',
  '/snappay-start.php', '/snappay-callback.php',
  '/order-success.php', '/invoice-pdf.php', '/account.php', '/account-order.php',
  '/account-return.php', '/customer-login.php', '/customer-register.php',
  '/customer-logout.php', '/tickets.php', '/ticket.php', '/return-request.php',
  '/bale-product-webhook.php', '/cron.php', '/cron-backup.php',
  '/cron-product-alerts.php', '/cron-sms-campaigns.php'
];

function relativePathname(url) {
  const scopePath = new URL(self.registration.scope).pathname.replace(/\/$/, '');
  const path = url.pathname;
  return scopePath && path.startsWith(scopePath) ? path.slice(scopePath.length) || '/' : path;
}

function isSensitive(url) {
  const p = relativePathname(url).toLowerCase();
  return SENSITIVE_PATHS.some(item => p === item || p.startsWith(item));
}

async function trimCache(cacheName, maxEntries) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length <= maxEntries) return;
  await Promise.all(keys.slice(0, keys.length - maxEntries).map(key => cache.delete(key)));
}

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    await cache.addAll(PRECACHE);
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter(name => name.startsWith('golrizan-') && ![STATIC_CACHE, IMAGE_CACHE].includes(name)).map(name => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || isSensitive(url)) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => (await caches.match(OFFLINE_URL)) || Response.error()));
    return;
  }

  const isStatic = request.destination === 'style' || request.destination === 'script' || request.destination === 'font';
  if (isStatic) {
    event.respondWith((async () => {
      const cached = await caches.match(request);
      if (cached) return cached;
      const response = await fetch(request);
      if (response.ok) {
        const cache = await caches.open(STATIC_CACHE);
        cache.put(request, response.clone());
      }
      return response;
    })());
    return;
  }

  if (request.destination === 'image') {
    event.respondWith((async () => {
      const cached = await caches.match(request);
      const network = fetch(request).then(async response => {
        if (response.ok) {
          const cache = await caches.open(IMAGE_CACHE);
          await cache.put(request, response.clone());
          trimCache(IMAGE_CACHE, 80).catch(() => {});
        }
        return response;
      }).catch(() => null);
      return cached || (await network) || Response.error();
    })());
  }
});
