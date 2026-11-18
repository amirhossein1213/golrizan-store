(() => {
  'use strict';
  const app = window.MOUSAVI_APP || {};
  const baseUrl = String(app.baseUrl || '').replace(/\/$/, '');
  const swUrl = `${baseUrl}/service-worker.js`;
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
  let deferredPrompt = null;

  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' }).catch(() => {});
    });
  }

  if (isStandalone) return;

  const dismissedAt = Number(localStorage.getItem('golrizan_pwa_dismissed_at') || 0);
  const dismissedRecently = dismissedAt > 0 && Date.now() - dismissedAt < 7 * 24 * 60 * 60 * 1000;
  if (dismissedRecently) return;

  const bar = document.createElement('div');
  bar.className = 'pwa-install-bar';
  bar.hidden = true;
  bar.innerHTML = '<div class="pwa-install-bar__icon"><img alt="" src="' + baseUrl + '/assets/images/pwa/icon-192.png"></div><div class="pwa-install-bar__copy"><strong>اپ گل‌ریزان</strong><small>فروشگاه را روی صفحه اصلی موبایل نصب کنید.</small></div><button type="button" class="pwa-install-bar__action">نصب</button><button type="button" class="pwa-install-bar__close" aria-label="بستن">×</button>';
  document.body.appendChild(bar);

  const action = bar.querySelector('.pwa-install-bar__action');
  const close = bar.querySelector('.pwa-install-bar__close');

  const hide = () => { bar.hidden = true; };
  close.addEventListener('click', () => {
    localStorage.setItem('golrizan_pwa_dismissed_at', String(Date.now()));
    hide();
  });

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredPrompt = event;
    bar.hidden = false;
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    localStorage.removeItem('golrizan_pwa_dismissed_at');
    hide();
  });

  action.addEventListener('click', async () => {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      try { await deferredPrompt.userChoice; } catch (_) {}
      deferredPrompt = null;
      hide();
      return;
    }
    if (isIos) {
      const modal = document.createElement('div');
      modal.className = 'pwa-ios-modal';
      modal.innerHTML = '<div class="pwa-ios-modal__overlay"></div><section class="pwa-ios-modal__panel" role="dialog" aria-modal="true" aria-label="راهنمای نصب اپ"><button type="button" class="pwa-ios-modal__close" aria-label="بستن">×</button><img src="' + baseUrl + '/assets/images/pwa/icon-192.png" alt="گل‌ریزان"><h2>نصب روی آیفون</h2><p>در Safari روی دکمه <b>Share</b> بزنید، سپس <b>Add to Home Screen</b> را انتخاب و تأیید کنید.</p><button type="button" class="pwa-ios-modal__ok">متوجه شدم</button></section>';
      document.body.appendChild(modal);
      const remove = () => modal.remove();
      modal.querySelector('.pwa-ios-modal__overlay').addEventListener('click', remove);
      modal.querySelector('.pwa-ios-modal__close').addEventListener('click', remove);
      modal.querySelector('.pwa-ios-modal__ok').addEventListener('click', remove);
    }
  });

  if (isIos) {
    window.setTimeout(() => { bar.hidden = false; }, 1200);
  }
})();
