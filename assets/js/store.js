(() => {
'use strict';
const APP = window.MOUSAVI_APP || {};
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const cartKey = 'mousavi_cart_v3';
const checkoutTokenKey = 'mousavi_checkout_token_v1';
const checkoutContextKey = 'mousavi_checkout_context_v1';
const cartTokenKey = 'mousavi_cart_identity_v1';
const couponKey = 'mousavi_coupon_v1';
const compareKey = 'mousavi_compare_v1';
const compareCategoryKey = 'mousavi_compare_category_v1';
let inMemoryCheckoutToken = '';
let mobileDrawerOpener = null;
let mobileCategoryOpener = null;
const fa = value => new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
const price = value => `${fa(value)} تومان`;
const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));

function toast(message, type = 'ok') {
    const element = $('[data-toast]');
    if (!element) return;
    element.textContent = message;
    element.dataset.type = type;
    element.classList.add('is-show');
    clearTimeout(window.__toast);
    window.__toast = setTimeout(() => element.classList.remove('is-show'), 3200);
}

function normalizeCart(raw) {
    if (!Array.isArray(raw)) return [];
    const merged = new Map();
    raw.slice(0, 100).forEach(item => {
        if (!item || typeof item !== 'object') return;
        const id = Number.parseInt(item.id, 10);
        const variantId = Math.max(0, Number.parseInt(item.variant_id, 10) || 0);
        const quantity = Number.parseInt(item.quantity, 10);
        if (!Number.isSafeInteger(id) || id < 1) return;
        const safeQuantity = Number.isSafeInteger(quantity) ? Math.max(1, Math.min(20, quantity)) : 1;
        const key = `${id}:${variantId}`;
        const previous = merged.get(key) || {id, variant_id:variantId, quantity:0};
        previous.quantity = Math.min(20, previous.quantity + safeQuantity);
        merged.set(key, previous);
    });
    return [...merged.values()];
}

function getCart() {
    try {
        return normalizeCart(JSON.parse(localStorage.getItem(cartKey) || '[]'));
    } catch {
        return [];
    }
}

function checkoutAttributeSelections() {
    const selections = {};
    $$('[data-checkout-attribute]').forEach(select => {
        const cartKey = String(select.dataset.cartKey || '');
        const valueId = Number.parseInt(select.value, 10);
        if (!cartKey || !Number.isSafeInteger(valueId) || valueId < 1) return;
        if (!selections[cartKey]) selections[cartKey] = [];
        selections[cartKey].push(valueId);
    });
    Object.values(selections).forEach(ids => ids.sort((a,b) => a-b));
    return selections;
}

function checkoutItems() {
    const selections = checkoutAttributeSelections();
    return getCart().map(item => {
        const key = `${item.id}:${item.variant_id || 0}`;
        return {...item, attribute_value_ids:selections[key] || []};
    });
}

function persistentToken(key) {
    let token='';try{token=localStorage.getItem(key)||'';}catch{/* storage unavailable */}
    if(!/^[a-f0-9-]{32,64}$/.test(token)){token=(globalThis.crypto?.randomUUID?.()||`${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`).toLowerCase().slice(0,64);try{localStorage.setItem(key,token);}catch{/* storage unavailable */}}
    return token;
}
function cartToken(){return persistentToken(cartTokenKey);}
function getCoupon(){try{return String(localStorage.getItem(couponKey)||'').trim().toUpperCase().slice(0,60);}catch{return '';}}
function setCoupon(code){const previous=getCoupon();const next=String(code||'').trim().toUpperCase().slice(0,60);try{next?localStorage.setItem(couponKey,next):localStorage.removeItem(couponKey);}catch{/* storage unavailable */}if(previous!==next)resetCheckoutToken();}
function getCompare(){try{const ids=JSON.parse(localStorage.getItem(compareKey)||'[]');return Array.isArray(ids)?[...new Set(ids.map(Number).filter(id=>Number.isInteger(id)&&id>0))].slice(0,4):[];}catch{return [];}}
function getCompareCategory(){try{const value=JSON.parse(localStorage.getItem(compareCategoryKey)||'null');const id=Number(value?.id);return Number.isInteger(id)&&id>0?{id,name:String(value.name||'این دسته').slice(0,120)}:null;}catch{return null;}}
function saveCompare(ids,category){const normalized=[...new Set((Array.isArray(ids)?ids:[]).map(Number).filter(id=>Number.isInteger(id)&&id>0))].slice(0,4);try{localStorage.setItem(compareKey,JSON.stringify(normalized));if(!normalized.length||category===null)localStorage.removeItem(compareCategoryKey);else if(category!==undefined&&Number.isInteger(Number(category.id))&&Number(category.id)>0)localStorage.setItem(compareCategoryKey,JSON.stringify({id:Number(category.id),name:String(category.name||'این دسته').slice(0,120)}));}catch{/* storage unavailable */}}
function syncCompareButtons(ids=getCompare()){$$('[data-compare]').forEach(button=>{const active=ids.includes(Number(button.dataset.compare));button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',active?'true':'false');});}

function saveCart(cart) {
    const normalized = normalizeCart(cart);
    const changed = JSON.stringify(getCart()) !== JSON.stringify(normalized);
    try {
        localStorage.setItem(cartKey, JSON.stringify(normalized));
    } catch {
        toast('ذخیره سبد خرید در مرورگر انجام نشد.', 'error');
    }
    if (changed) resetCheckoutToken();
    updateCount();
}

function updateCount() {
    const count = getCart().reduce((sum, item) => sum + item.quantity, 0);
    $$('[data-cart-count]').forEach(element => {
        element.textContent = fa(count);
        if (element.classList.contains('mobile-bottom-nav__badge')) element.classList.toggle('is-empty', count === 0);
    });
}

function cartAddedFeedback(button) {
    $$('[data-cart-count]').forEach(element => {
        const target = element.closest('.mobile-bottom-nav__item,.icon-btn') || element;
        target.classList.remove('cart-added-pulse');
        void target.offsetWidth;
        target.classList.add('cart-added-pulse');
        window.setTimeout(() => target.classList.remove('cart-added-pulse'), 650);
    });
    if (!button) return;
    const original = button.dataset.cartOriginalLabel || button.textContent.trim();
    button.dataset.cartOriginalLabel = original;
    button.textContent = 'اضافه شد ✓';
    button.classList.add('is-added');
    clearTimeout(button.__cartFeedbackTimer);
    button.__cartFeedbackTimer = window.setTimeout(() => {
        button.textContent = original;
        button.classList.remove('is-added');
    }, 1400);
}

function addCart(id, quantity = 1, variantId = 0, trigger = null) {
    const productId = Number.parseInt(id, 10);
    const safeVariantId = Math.max(0, Number.parseInt(variantId, 10) || 0);
    if (!Number.isSafeInteger(productId) || productId < 1) return;
    const cart = getCart();
    const item = cart.find(entry => entry.id === productId && entry.variant_id === safeVariantId);
    if (item) item.quantity = Math.min(20, item.quantity + Math.max(1, Number.parseInt(quantity, 10) || 1));
    else cart.push({id: productId, variant_id:safeVariantId, quantity: Math.max(1, Number.parseInt(quantity,10)||1)});
    saveCart(cart);
    cartAddedFeedback(trigger);
    toast('کالا به سبد خرید اضافه شد.');
    api('api/cart.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:getCart(),coupon_code:getCoupon(),cart_token:cartToken()})}).catch(()=>{});
}

function resetCheckoutToken() {
    inMemoryCheckoutToken = '';
    try { sessionStorage.removeItem(checkoutTokenKey); } catch { /* storage may be unavailable */ }
    try { sessionStorage.removeItem(checkoutContextKey); } catch { /* storage may be unavailable */ }
}

function checkoutContext() {
    const items = checkoutItems().map(item => ({id:item.id,variant_id:item.variant_id,quantity:item.quantity,attribute_value_ids:item.attribute_value_ids}));
    items.sort((a,b) => a.id-b.id || a.variant_id-b.variant_id);
    return JSON.stringify({items,coupon:getCoupon()});
}

function checkoutToken() {
    const context = checkoutContext();
    let token = inMemoryCheckoutToken;
    let storedContext = '';
    if (!/^[a-f0-9-]{32,64}$/.test(token)) {
        try { token = sessionStorage.getItem(checkoutTokenKey) || ''; } catch { /* storage may be unavailable */ }
    }
    try { storedContext = sessionStorage.getItem(checkoutContextKey) || ''; } catch { /* storage may be unavailable */ }
    if (storedContext !== context) token = '';
    if (!/^[a-f0-9-]{32,64}$/.test(token)) {
        token = globalThis.crypto?.randomUUID?.() || `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
        token = token.toLowerCase().slice(0, 64);
        try { sessionStorage.setItem(checkoutTokenKey, token); } catch { /* storage may be unavailable */ }
    }
    try { sessionStorage.setItem(checkoutContextKey, context); } catch { /* storage may be unavailable */ }
    inMemoryCheckoutToken = token;
    return token;
}

async function api(path, options = {}) {
    const {headers = {}, ...rest} = options;
    let response;
    try {
        response = await fetch(`${APP.baseUrl}/${path.replace(/^\//, '')}`, {
            ...rest,
            headers: {'Accept':'application/json','X-CSRF-Token':APP.csrf,...headers},
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') throw error;
        throw new Error('ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید.');
    }
    let data;
    try { data = await response.json(); }
    catch { throw new Error('پاسخ سرور قابل خواندن نیست.'); }
    if (!response.ok || data.ok === false) {
        const error = new Error(data.message || 'خطایی رخ داد.');
        error.status = response.status;
        throw error;
    }
    return data;
}

document.addEventListener('click', event => {
    const add = event.target.closest('[data-add-cart]');
    if (add && !add.disabled) { addCart(add.dataset.addCart, add.dataset.quantity || 1, add.dataset.variantId || 0, add); return; }
    const openMenu = event.target.closest('[data-open-menu]');
    const openCategories = event.target.closest('[data-open-categories]');
    const close = event.target.closest('[data-close-menu]');
    const closeCategories = event.target.closest('[data-close-categories]');
    const drawer = $('[data-mobile-drawer]');
    const categorySheet = $('[data-category-sheet]');
    if (openMenu && drawer) {
        mobileDrawerOpener = openMenu;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        openMenu.setAttribute('aria-expanded', 'true');
        document.body.classList.add('nav-open');
        $('[data-close-menu]', drawer)?.focus();
    }
    if (openCategories && categorySheet) {
        mobileCategoryOpener = openCategories;
        categorySheet.classList.add('is-open');
        categorySheet.setAttribute('aria-hidden', 'false');
        openCategories.setAttribute('aria-expanded', 'true');
        document.body.classList.add('nav-open');
        $('[data-close-categories]', categorySheet)?.focus();
    }
    if (close && drawer) {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        $$('[data-open-menu]').forEach(opener => opener.setAttribute('aria-expanded', 'false'));
        if (!categorySheet?.classList.contains('is-open')) document.body.classList.remove('nav-open');
        mobileDrawerOpener?.focus();
        mobileDrawerOpener = null;
    }
    if (closeCategories && categorySheet) {
        categorySheet.classList.remove('is-open');
        categorySheet.setAttribute('aria-hidden', 'true');
        $$('[data-open-categories]').forEach(opener => opener.setAttribute('aria-expanded', 'false'));
        if (!drawer?.classList.contains('is-open')) document.body.classList.remove('nav-open');
        mobileCategoryOpener?.focus();
        mobileCategoryOpener = null;
    }
    if (event.target.closest('[data-filter-toggle]')) {$('.filters')?.classList.add('is-open');document.body.classList.add('filter-open');}
    if (event.target.closest('[data-filter-close]')) {$('.filters')?.classList.remove('is-open');document.body.classList.remove('filter-open');}
    if (event.target.closest('[data-clear-cart]')) {
        saveCart([]);
        location.href = `${APP.baseUrl}/shop.php`;
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        if ($('[data-category-sheet].is-open')) $('[data-close-categories]')?.click();
        else if ($('.filters.is-open')) $('[data-filter-close]')?.click();
        else $('[data-close-menu]')?.click();
    }
});

function initNavigationDropdown() {
    const roots = $$('[data-nav-dropdown]');
    if (!roots.length) return;
    const closeAll = except => roots.forEach(root => {
        if (root === except) return;
        root.classList.remove('is-open');
        $('[data-nav-dropdown-toggle]', root)?.setAttribute('aria-expanded', 'false');
    });
    roots.forEach(root => {
        const toggle = $('[data-nav-dropdown-toggle]', root);
        if (!toggle) return;
        const setOpen = open => {
            if (open) closeAll(root);
            root.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        toggle.addEventListener('click', event => {
            event.stopPropagation();
            setOpen(!root.classList.contains('is-open'));
        });
        root.addEventListener('click', event => event.stopPropagation());
        root.addEventListener('focusout', event => {
            if (!root.contains(event.relatedTarget)) setOpen(false);
        });
    });
    document.addEventListener('click', () => closeAll(null));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const openRoot = roots.find(root => root.classList.contains('is-open'));
        if (!openRoot) return;
        openRoot.classList.remove('is-open');
        const toggle = $('[data-nav-dropdown-toggle]', openRoot);
        toggle?.setAttribute('aria-expanded', 'false');
        toggle?.focus();
    });
}

document.addEventListener('change', event => {
    const field = event.target.closest('[data-auto-submit]');
    if (!field || !field.form) return;
    if (typeof field.form.requestSubmit === 'function') field.form.requestSubmit();
    else field.form.submit();
});

function initLiveSearch() {
    $$('[data-live-search]').forEach(form => {
        const input = $('input[type=search]', form);
        const root = $('[data-search-results]', form);
        if (!input || !root) return;
        let timer;
        let controller;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            controller?.abort();
            const query = input.value.trim();
            if (query.length < 2) {
                root.innerHTML = '';
                root.classList.remove('is-open');
                return;
            }
            timer = setTimeout(async () => {
                controller = new AbortController();
                try {
                    const data = await api(`api/search.php?q=${encodeURIComponent(query)}`, {signal: controller.signal});
                    if (input.value.trim() !== query) return;
                    root.innerHTML = data.items.length
                        ? data.items.map(product => `<a class="search-result" href="${esc(product.url)}"><span class="search-result__text"><strong>${esc(product.title)}</strong><small>${price(product.price)}</small></span><span class="search-result__action">مشاهده</span></a>`).join('')
                        : '<div class="search-empty">نتیجه‌ای پیدا نشد</div>';
                    root.classList.add('is-open');
                } catch (error) {
                    if (error.name !== 'AbortError') root.classList.remove('is-open');
                }
            }, 280);
        });
        document.addEventListener('click', event => {
            if (!form.contains(event.target)) root.classList.remove('is-open');
        });
    });
}

async function fetchCart(payment = '', checkSnappayEligibility = false) {
    return api('api/cart.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:getCart(),coupon_code:getCoupon(),cart_token:cartToken(),payment,check_snappay_eligibility:checkSnappayEligibility})});
}

function renderCheckoutAttributes(items, form) {
    const root = $('[data-checkout-attributes]', form);
    if (!root) return;
    const products = items.filter(item => Array.isArray(item.checkout_attributes) && item.checkout_attributes.length);
    if (!products.length) {
        root.hidden = true;
        root.innerHTML = '';
        return;
    }
    root.innerHTML = `<h2>انتخاب ویژگی‌های کالا</h2><p class="checkout-attribute-card__intro">برای ثبت سفارش، از هر ویژگی یک گزینه انتخاب کنید. قیمت و موجودی با این انتخاب تغییر نمی‌کند.</p><div class="checkout-attribute-products">${products.map(item => {
        const cartKey = String(item.cart_key || `${item.id}:${item.variant_id || 0}`);
        const variant = item.variant_title ? ` <small>(${esc(item.variant_title)})</small>` : '';
        const groups = item.checkout_attributes.map(attribute => {
            const selectId = `checkout-attribute-${item.id}-${item.variant_id || 0}-${attribute.id}`;
            const options = (Array.isArray(attribute.values) ? attribute.values : []).map(value => `<option value="${Number(value.id)}">${esc(value.value)}</option>`).join('');
            return `<div class="field checkout-attribute-field"><label for="${esc(selectId)}">${esc(attribute.name)}</label><select id="${esc(selectId)}" required data-checkout-attribute data-cart-key="${esc(cartKey)}" data-attribute-id="${Number(attribute.id)}"><option value="">انتخاب ${esc(attribute.name)}</option>${options}</select></div>`;
        }).join('');
        return `<section class="checkout-attribute-product"><h3>${esc(item.short_title || item.title)} × ${fa(item.quantity)}${variant}</h3><div class="form-grid">${groups}</div></section>`;
    }).join('')}</div>`;
    root.hidden = false;
    root.addEventListener('change', resetCheckoutToken, {once:false});
}

function cartItemHtml(product) {
    const festivalText = product.festival_title ? (product.festival_title === 'جشنواره' ? (product.festival_saving > 0 ? 'قیمت جشنواره‌ای' : 'ارسال رایگان جشنواره') : 'جشنواره: ' + product.festival_title) + (product.festival_free_shipping && product.festival_saving > 0 ? ' • ارسال رایگان' : '') : '';
    const festival = festivalText ? `<div class="festival-line">${esc(festivalText)}</div>` : '';
    const base = product.base_price > product.price ? `<small class="old-price-inline">${price(product.base_price)}</small>` : '';
    const variant = product.variant_title ? `<p class="cart-variant">${esc(product.variant_title)}</p>` : '';
    const metaTags = [];
    if (product.warranty) metaTags.push(`<span>${esc(product.warranty)}</span>`);
    if (product.color) metaTags.push(`<span>رنگ ${esc(product.color)}</span>`);
    if (product.authenticity_guarantee) metaTags.push(`<span class="cart-meta-tag--trust">✓ تضمین اصالت کالا</span>`);
    if (product.safe_shipping_guarantee) metaTags.push(`<span class="cart-meta-tag--trust">✓ ارسال سلامت کالا</span>`);
    const metaHtml = metaTags.length ? `<div class="cart-item__meta">${metaTags.join('')}</div>` : '';
    const key = esc(product.cart_key || `${product.id}:${product.variant_id||0}`);
    const trashSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>`;
    return `<article class="cart-item" data-cart-row="${key}"><a class="cart-item__media" href="${esc(product.url)}"><img src="${esc(product.image)}" alt="${esc(product.short_title)}" loading="lazy" decoding="async"></a><div class="cart-item__body"><a class="cart-item__title" href="${esc(product.url)}"><h3>${esc(product.title)}</h3></a>${variant}${metaHtml}${festival}<div class="cart-item__controls"><div class="quantity"><button type="button" data-qty-minus="${key}" aria-label="کاهش تعداد">−</button><span>${fa(product.quantity)}</span><button type="button" data-qty-plus="${key}" aria-label="افزایش تعداد">+</button></div><button type="button" class="remove-btn" data-remove-cart="${key}">${trashSvg}<span>حذف</span></button></div></div><div class="cart-item-price">${base}<strong>${price(product.line_total)}</strong><small class="muted">${price(product.price)} × ${fa(product.quantity)}</small></div></article>`;
}

async function renderCart() {
    const itemsRoot = $('[data-cart-items]');
    const summaryRoot = $('[data-cart-summary]');
    if (!itemsRoot || !summaryRoot) return;
    try {
        const data = await fetchCart();
        saveCart(data.items.map(item => ({id:item.id, variant_id:item.variant_id||0, quantity:item.quantity})));
        if (!data.items.length) {
            const bagSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8h12l1.2 11a2 2 0 0 1-2 2.2H6.8a2 2 0 0 1-2-2.2L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>`;
            itemsRoot.innerHTML = `<div class="empty-state empty-state--cart"><div class="empty-state__icon">${bagSvg}</div><h2>سبد خرید شما خالی است</h2><p>برای مشاهده قیمت روز، موجودی و مقایسه مدل‌ها به فروشگاه سر بزنید.</p><a class="btn btn-primary" href="${APP.baseUrl}/shop.php">مشاهده فروشگاه</a></div>`;
            summaryRoot.innerHTML = '<h2>خلاصه سفارش</h2><div class="summary-row"><span>تعداد کالا</span><strong>۰</strong></div><div class="summary-row summary-total"><span>مبلغ قابل پرداخت</span><strong>۰ تومان</strong></div>';
            return;
        }
        const totalQty = data.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
        itemsRoot.innerHTML = `<div class="cart-panel"><div class="cart-list-head"><h2>اقلام سبد خرید</h2><span>${fa(totalQty)} کالا</span></div><div class="cart-items-list">${data.items.map(cartItemHtml).join('')}</div></div>`;
        const savingRow = data.festival_saving > 0 ? `<div class="summary-row festival-saving"><span>کاهش قیمت جشنواره</span><strong>− ${price(data.festival_saving)}</strong></div>` : '';
        const couponRow=data.discount>0?`<div class="summary-row festival-saving"><span>تخفیف کد ${esc(data.coupon?.code||'')}</span><strong>− ${price(data.discount)}</strong></div>`:'';
        const couponMessage=data.coupon&&!data.coupon.valid?`<small class="error-text coupon-error">${esc(data.coupon.message||'کد معتبر نیست.')}</small>`:'';
        summaryRoot.innerHTML = `<h2>خلاصه سفارش</h2><div class="summary-rows"><div class="summary-row"><span>قیمت پایه کالاها (${fa(totalQty)})</span><span>${price(data.base_subtotal)}</span></div>${savingRow}${couponRow}<div class="summary-row"><span>روش ارسال</span><span>${esc(data.shipping_details.label)}</span></div></div><form class="coupon-form" data-coupon-form><input name="coupon" maxlength="60" value="${esc(getCoupon())}" placeholder="کد تخفیف دارید؟"><button class="btn btn-secondary">اعمال</button>${getCoupon()?'<button type="button" class="btn btn-secondary coupon-remove-btn" data-remove-coupon>حذف</button>':''}</form>${couponMessage}<small class="shipping-note">${esc(data.shipping_details.description)}</small><div class="summary-row summary-total"><span>مبلغ قابل پرداخت در سایت</span><strong>${price(data.total)}</strong></div><a class="btn btn-primary btn-block cart-checkout-btn" href="${APP.baseUrl}/checkout.php">ادامه فرایند خرید ←</a><div class="cart-trust-note"><span>✓ تضمین اصالت کالا</span><span>✓ کنترل لحظه‌ای قیمت و موجودی</span></div>`;
    } catch (error) {
        itemsRoot.innerHTML = `<div class="form-error">${esc(error.message)}</div>`;
    }
}

document.addEventListener('click', event => {
    const plus = event.target.closest('[data-qty-plus]');
    const minus = event.target.closest('[data-qty-minus]');
    const remove = event.target.closest('[data-remove-cart]');
    if (!plus && !minus && !remove) return;
    const element = plus || minus || remove;
    const key = String(element.dataset.qtyPlus || element.dataset.qtyMinus || element.dataset.removeCart || '');
    const [idRaw,variantRaw] = key.split(':');
    const id = Number.parseInt(idRaw,10), variantId=Math.max(0,Number.parseInt(variantRaw,10)||0);
    let cart = getCart();
    const item = cart.find(entry => entry.id === id && entry.variant_id === variantId);
    if (remove) cart = cart.filter(entry => !(entry.id === id && entry.variant_id === variantId));
    else if (item) item.quantity = Math.max(1, Math.min(20, item.quantity + (plus ? 1 : -1)));
    saveCart(cart);
    renderCart();
});

document.addEventListener('submit',event=>{const form=event.target.closest('[data-coupon-form]');if(!form)return;event.preventDefault();setCoupon(new FormData(form).get('coupon')||'');renderCart();});
document.addEventListener('click',event=>{if(!event.target.closest('[data-remove-coupon]'))return;setCoupon('');renderCart();});

async function initCheckout() {
    const form = $('[data-checkout-form]');
    const root = $('[data-checkout-summary]');
    if (!form || !root) return;
    try {
        const selectedPayment = () => $('input[name="payment"]:checked', form)?.value || '';
        const applySnappayEligibility = quote => {
            const label = $('[data-snappay-payment]', form);
            if (!label) return false;
            const eligibility = quote?.snappay_eligibility || {eligible:false};
            const radio = $('input[value="snappay"]', label);
            const title = $('[data-snappay-title]', label);
            const description = $('[data-snappay-description]', label);
            const eligible = eligibility.eligible === true;
            label.hidden = !eligible;
            if (title) title.textContent = eligible ? String(eligibility.title || '') : '';
            if (description) description.textContent = eligible ? String(eligibility.description || '') : '';
            let paymentChanged = false;
            if (!eligible && radio?.checked) {
                radio.checked = false;
                const replacement = $$('input[name="payment"]', form).find(input => !input.closest('[hidden]') && input.value !== 'snappay');
                if (replacement) { replacement.checked = true; paymentChanged = true; }
            }
            return paymentChanged;
        };
        let data = await fetchCart(selectedPayment(), true);
        if (applySnappayEligibility(data)) {
            data = await fetchCart(selectedPayment(), true);
            applySnappayEligibility(data);
        }
        saveCart(data.items.map(item => ({id:item.id, variant_id:item.variant_id||0, quantity:item.quantity})));
        if (!data.items.length) {
            form.innerHTML = `<div class="empty-state"><h2>سبد خرید خالی است</h2><a class="btn btn-primary" href="${APP.baseUrl}/shop.php">رفتن به فروشگاه</a></div>`;
            root.innerHTML = '';
            return;
        }
        renderCheckoutAttributes(data.items, form);
        const renderPaymentSummary = quote => {
            const savingRow = quote.festival_saving > 0 ? `<div class="summary-row festival-saving"><span>کاهش قیمت جشنواره</span><strong>− ${price(quote.festival_saving)}</strong></div>` : '';
            const couponRow=quote.discount>0?`<div class="summary-row festival-saving"><span>کد تخفیف ${esc(quote.coupon?.code||'')}</span><strong>− ${price(quote.discount)}</strong></div>`:'';
            const markupRow=quote.payment_markup_amount>0?`<div class="summary-row"><span>افزایش لحاظ‌شده ترب‌پی (${esc(String(quote.payment_markup_percent||0))}٪)</span><strong>${price(quote.payment_markup_amount)}</strong></div>`:'';
            root.innerHTML = `<h2>خلاصه پرداخت</h2>${quote.items.map(item => { const guarantees = [item.variant_title ? item.variant_title : '', item.warranty ? item.warranty : '', item.authenticity_guarantee ? '✓ تضمین اصالت کالا' : '', item.safe_shipping_guarantee ? '✓ گارانتی ارسال سلامت کالا' : '', item.festival_free_shipping ? '✓ ارسال رایگان جشنواره' : ''].filter(Boolean).join(' • '); return `<div class="summary-row"><span>${esc(item.short_title)} × ${fa(item.quantity)}${guarantees ? `<br><small class="muted">${esc(guarantees)}</small>` : ''}</span><span>${price(item.line_total)}</span></div>`; }).join('')}<div class="summary-row"><span>قیمت پایه کالاها</span><span>${price(quote.base_subtotal)}</span></div>${savingRow}${markupRow}${couponRow}<div class="summary-row"><span>ارسال</span><span>${esc(quote.shipping_details.label)}</span></div><small class="shipping-note">${esc(quote.shipping_details.description)}</small><div class="summary-row summary-total"><span>مبلغ نهایی در سایت</span><strong>${price(quote.total)}</strong></div>`;
        };
        renderPaymentSummary(data);
        form.addEventListener('change', async event => {
            if (!event.target.matches('input[name="payment"]')) return;
            resetCheckoutToken();
            root.innerHTML = '<div class="loading-box">در حال محاسبه مبلغ روش پرداخت…</div>';
            try {
                data = await fetchCart(selectedPayment(), true);
                if (applySnappayEligibility(data)) { data = await fetchCart(selectedPayment(), true); applySnappayEligibility(data); }
                renderPaymentSummary(data);
            }
            catch (error) { root.innerHTML = `<div class="form-error">${esc(error.message)}</div>`; }
        });
        const shippingLabel = $('[data-checkout-shipping-label]', form);
        const shippingDescription = $('[data-checkout-shipping-description]', form);
        const shippingInput = $('input[name="shipping"]', form);
        if (shippingLabel) shippingLabel.textContent = data.shipping_details.label;
        if (shippingDescription) shippingDescription.textContent = data.shipping_details.description;
        if (shippingInput) shippingInput.value = data.shipping_details.mode;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const button = $('button[type=submit]', form);
            const message = $('[data-form-message]', form);
            button.disabled = true;
            message.textContent = 'در حال ثبت سفارش…';
            message.className = 'form-message';
            try {
                const fields = Object.fromEntries(new FormData(form).entries());
                const result = await api('api/checkout.php', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body:JSON.stringify({...fields, items:checkoutItems(),coupon_code:getCoupon(),cart_token:cartToken(), _csrf:APP.csrf, checkout_token:checkoutToken()}),
                });
                location.href = result.redirect;
            } catch (error) {
                if (error.status === 409) resetCheckoutToken();
                message.textContent = error.message;
                message.className = 'form-message error';
                button.disabled = false;
            }
        });
    } catch (error) {
        root.innerHTML = `<div class="form-error">${esc(error.message)}</div>`;
    }
}

function initProductTabs() {
    const root = $('[data-product-tabs]');
    if (!root) return;
    root.addEventListener('click', event => {
        const tab = event.target.closest('[data-product-tab]');
        if (!tab) return;
        const key = tab.dataset.productTab;
        $$('[data-product-tab]', root).forEach(button => {
            const active = button === tab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });
        $$('[data-product-panel]', root).forEach(panel => { panel.hidden = panel.dataset.productPanel !== key; });
    });
    root.addEventListener('keydown', event => {
        if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        const tabs = $$('[data-product-tab]', root);
        const current = tabs.indexOf(document.activeElement);
        if (current < 0) return;
        event.preventDefault();
        let next = current;
        if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else next = (current + (event.key === 'ArrowLeft' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[next].focus();
        tabs[next].click();
    });
}

function initProductVariants() {
    const root = $('[data-variant-picker]');
    if (!root) return;
    let variants = [];
    try { variants = JSON.parse(root.dataset.variants || '[]'); } catch { variants = []; }
    if (!Array.isArray(variants) || !variants.length) return;
    const selections = {};
    const hidden = $('[data-selected-variant]', root);
    const addButton = $('[data-product-add]');
    const priceRoot = $('[data-variant-price]');
    const stockRoot = $('[data-variant-stock]');
    const image = $('[data-product-main-image]');
    const warranty = $('[data-variant-warranty]');
    const sku = $('[data-variant-sku]');
    const alertVariant = $('[data-alert-variant]');
    const installmentRoot = $('[data-torobpay-installment]');
    const installmentAmount = installmentRoot ? $('[data-torobpay-installment-amount]', installmentRoot) : null;
    const optionButtons = $$('[data-attribute-value]', root);
    const renderInstallment = quote => {
        if (!installmentRoot) return;
        const amount = Number(quote?.installment_amount || 0);
        const visible = quote?.supported === true && Number.isSafeInteger(amount) && amount > 0;
        installmentRoot.hidden = !visible;
        if (visible && installmentAmount) installmentAmount.textContent = fa(amount);
    };
    const chooseVariant = () => {
        const wanted = Object.values(selections).map(Number).sort((a,b)=>a-b);
        const match = variants.find(variant => {
            const values = (variant.value_ids || []).map(Number).sort((a,b)=>a-b);
            return values.length === wanted.length && values.every((value,index)=>value===wanted[index]);
        });
        optionButtons.forEach(button => {
            const attr = button.dataset.attributeId;
            const test = {...selections,[attr]:Number(button.dataset.attributeValue)};
            const values = Object.values(test).map(Number);
            const possible = variants.some(variant => variant.stock > 0 && values.every(value => (variant.value_ids||[]).map(Number).includes(value)));
            button.disabled = !possible;
        });
        if (!match) {
            if (hidden) hidden.value='';
            if (addButton){addButton.disabled=true;addButton.dataset.variantId='0';}
            if(stockRoot)stockRoot.textContent='این ترکیب موجود نیست';
            renderInstallment(null);
            return;
        }
        if(hidden)hidden.value=String(match.id);
        if(addButton){addButton.dataset.variantId=String(match.id);addButton.disabled=match.stock<1;}
        if(priceRoot)priceRoot.innerHTML = `${match.base_price>match.price?`<div class="old-price">${price(match.base_price)}</div>`:''}<div class="price">${price(match.price)}</div>${match.festival_title?`<small class="festival-product-note">جشنواره ${esc(match.festival_title)}</small>`:''}`;
        renderInstallment(match.torobpay);
        if(stockRoot)stockRoot.textContent=match.stock>0?`موجودی: ${fa(match.stock)} دستگاه`:'ناموجود';
        if(image && match.image){if(match.image_srcset)image.srcset=match.image_srcset;else image.removeAttribute('srcset');image.src=match.image;image.dispatchEvent(new CustomEvent('mousavi:image-change'));}
        if(alertVariant)alertVariant.value=String(match.id);
        if(warranty)warranty.textContent=match.warranty||'';
        if(sku)sku.textContent=match.sku||'';
    };
    optionButtons.forEach(button => button.addEventListener('click',()=>{
        const attr=button.dataset.attributeId;
        selections[attr]=Number(button.dataset.attributeValue);
        $$(`[data-attribute-id="${CSS.escape(attr)}"]`,root).forEach(peer=>peer.classList.toggle('active',peer===button));
        chooseVariant();
    }));
    const requestedVariantId = Number(hidden?.value || 0);
    const defaultVariant = variants.find(v=>Number(v.id)===requestedVariantId) || variants.find(v=>v.is_default && v.stock>0) || variants.find(v=>v.stock>0) || variants.find(v=>v.is_default) || variants[0];
    (defaultVariant.value_ids||[]).forEach(valueId=>{
        const button=optionButtons.find(item=>Number(item.dataset.attributeValue)===Number(valueId));
        if(button){selections[button.dataset.attributeId]=Number(valueId);button.classList.add('active');}
    });
    chooseVariant();
}

function initMobileProductBuybar() {
    const root = $('[data-mobile-product-buybar]');
    const main = $('[data-product-add]');
    if (!root || !main) return;
    const mobile = $('[data-mobile-product-add]', root);
    const priceRoot = $('[data-variant-price]');
    const mobilePrice = $('[data-mobile-product-price]', root);
    if (!mobile) return;
    const sync = () => {
        mobile.dataset.variantId = main.dataset.variantId || '0';
        mobile.disabled = main.disabled;
        if (mobilePrice && priceRoot) {
            const current = $('.price', priceRoot);
            if (current) mobilePrice.textContent = current.textContent.trim();
        }
    };
    new MutationObserver(sync).observe(main, {attributes:true, attributeFilter:['disabled','data-variant-id']});
    if (priceRoot) new MutationObserver(sync).observe(priceRoot, {childList:true, subtree:true, characterData:true});
    sync();
}

function initFestivalCountdown() {
    $$('[data-festival-countdown]').forEach(root => {
        const output = $('[data-festival-time]', root);
        const endsAt = Number(root.dataset.endsAt || 0);
        if (!output || !Number.isFinite(endsAt) || endsAt < 1) return;
        let timer = 0;
        const render = () => {
            const remaining = Math.max(0, endsAt - Date.now());
            const totalSeconds = Math.floor(remaining / 1000);
            const days = Math.floor(totalSeconds / 86400);
            const hours = Math.floor((totalSeconds % 86400) / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;
            output.textContent = days > 0
                ? `${fa(days)} روز و ${fa(hours)} ساعت`
                : `${fa(hours).padStart(2,'۰')} : ${fa(minutes).padStart(2,'۰')} : ${fa(seconds).padStart(2,'۰')}`;
            if (remaining <= 0) {
                output.textContent = 'پایان یافته';
                if (timer) window.clearInterval(timer);
            }
        };
        render();
        if (endsAt > Date.now()) timer = window.setInterval(render, 1000);
    });
}

function initForms() {
    const newsletter = $('[data-newsletter]');
    newsletter?.addEventListener('submit', async event => {
        event.preventDefault();
        const button = $('button', newsletter);
        button.disabled = true;
        try {
            await api('api/newsletter.php', {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({phone:new FormData(newsletter).get('phone'), _csrf:APP.csrf}),
            });
            newsletter.reset();
            toast('عضویت شما ثبت شد.');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    });

    const contact = $('[data-contact-form]');
    contact?.addEventListener('submit', async event => {
        event.preventDefault();
        const message = $('[data-form-message]', contact);
        const button = $('button[type=submit]', contact);
        button.disabled = true;
        try {
            const body = Object.fromEntries(new FormData(contact).entries());
            body._csrf = APP.csrf;
            await api('api/contact.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
            contact.reset();
            message.textContent = 'پیام شما با موفقیت ثبت شد.';
            message.className = 'form-message success';
        } catch (error) {
            message.textContent = error.message;
            message.className = 'form-message error';
        } finally {
            button.disabled = false;
        }
    });
}

function initProductGallery(){
    const root=$('[data-product-gallery]');if(!root)return;
    const main=$('[data-gallery-main]',root),lightbox=$('[data-gallery-lightbox]',root),lightboxImage=$('[data-gallery-lightbox-image]',root),thumbs=$('.product-gallery__thumbs',root);
    const sync=()=>{if(lightboxImage&&main){lightboxImage.src=main.src;lightboxImage.alt=main.alt;}};
    const focusThumb=(button)=>{if(!button)return;$$('[data-gallery-thumb]',root).forEach(peer=>peer.classList.toggle('active',peer===button));button.scrollIntoView({behavior:'smooth',inline:'center',block:'nearest'});};
    $$('[data-gallery-thumb]',root).forEach(button=>button.addEventListener('click',()=>{if(!main)return;if(button.dataset.srcset)main.srcset=button.dataset.srcset;else main.removeAttribute('srcset');main.src=button.dataset.image||'';main.alt=button.dataset.alt||'';focusThumb(button);sync();}));
    if(thumbs){const active=$('.gallery-thumb.active',thumbs)||$('[data-gallery-thumb]',thumbs);if(active)focusThumb(active);}
    $('[data-gallery-zoom]',root)?.addEventListener('click',()=>{sync();if(lightbox){lightbox.hidden=false;document.body.classList.add('gallery-open');}});
    const close=()=>{if(lightbox){lightbox.hidden=true;document.body.classList.remove('gallery-open');}};
    $('[data-gallery-close]',root)?.addEventListener('click',close);lightbox?.addEventListener('click',event=>{if(event.target===lightbox)close();});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&lightbox&&!lightbox.hidden)close();});main?.addEventListener('mousavi:image-change',sync);
}

function initProductAlert(){
    const form=$('[data-product-alert]');if(!form)return;const type=$('[name="alert_type"]',form),target=$('[name="target_price"]',form),message=$('[data-alert-message]',form),button=$('button[type="submit"],button:not([type])',form);
    const sync=()=>{if(target){target.disabled=type?.value!=='price_drop';target.hidden=target.disabled;}};type?.addEventListener('change',sync);sync();
    form.addEventListener('submit',async event=>{event.preventDefault();if(button)button.disabled=true;try{const values=Object.fromEntries(new FormData(form).entries());values._csrf=APP.csrf;const data=await api('api/product-alert.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(values)});if(message){message.textContent=data.message;message.className='form-message success';}toast(data.message);}catch(error){if(message){message.textContent=error.message;message.className='form-message error';}toast(error.message,'error');}finally{if(button)button.disabled=false;}});
}

function initCustomerLinks(){
    const path=location.pathname;
    if(path.endsWith('/account.php')||path.endsWith('account.php')){const sidebar=$('.account-sidebar');if(sidebar&&!$('[data-ticket-link]',sidebar)){const link=document.createElement('a');link.href=`${APP.baseUrl}/tickets.php`;link.textContent='تیکت‌های پشتیبانی';link.dataset.ticketLink='1';sidebar.insertBefore(link,sidebar.querySelector('form'));}}
    if(path.endsWith('/account-order.php')||path.endsWith('account-order.php')){const actions=$('.admin-title .admin-actions');const id=new URLSearchParams(location.search).get('id');if(actions&&/^\d+$/.test(id||'')&&!$('[data-invoice-link]',actions)){const link=document.createElement('a');link.className='btn btn-secondary';link.href=`${APP.baseUrl}/invoice-pdf.php?id=${encodeURIComponent(id)}`;link.textContent='دریافت فاکتور PDF';link.dataset.invoiceLink='1';actions.append(link);}}
}

async function toggleWishlist(button){
    if(!APP.customerLoggedIn){location.href=`${APP.baseUrl}/customer-login.php`;return;}
    button.disabled=true;try{const data=await api('api/wishlist.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:Number(button.dataset.wishlist)})});button.classList.toggle('is-active',data.active);button.setAttribute('aria-pressed',data.active?'true':'false');if(button.classList.contains('wishlist-btn')){if(!button.querySelector('svg'))button.textContent=data.active?'♥':'♡';}else{button.textContent=data.active?'حذف از علاقه‌مندی':'افزودن به علاقه‌مندی';}toast(data.active?'به علاقه‌مندی‌ها اضافه شد.':'از علاقه‌مندی‌ها حذف شد.');if(document.body.dataset.page==='account'&&!data.active)button.closest('.wishlist-card')?.remove();}catch(error){toast(error.message,'error');}finally{button.disabled=false;}
}

async function initEngagementButtons(){syncCompareButtons();if(!APP.customerLoggedIn)return;try{const data=await api('api/wishlist.php');const ids=(data.ids||[]).map(Number);$$('[data-wishlist]').forEach(button=>{const active=ids.includes(Number(button.dataset.wishlist));button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',active?'true':'false');if(button.classList.contains('wishlist-btn')){if(!button.querySelector('svg'))button.textContent=active?'♥':'♡';}else{button.textContent=active?'حذف از علاقه‌مندی':'افزودن به علاقه‌مندی';}});}catch{/* non-critical */}}

document.addEventListener('click',async event=>{
    const wishlist=event.target.closest('[data-wishlist]');if(wishlist){toggleWishlist(wishlist);return;}
    const compare=event.target.closest('[data-compare]');if(!compare)return;
    const id=Number(compare.dataset.compare);let ids=getCompare();
    if(ids.includes(id)){
        ids=ids.filter(value=>value!==id);saveCompare(ids);syncCompareButtons(ids);toast('از مقایسه حذف شد.');if($('[data-compare-page]'))initCompare();return;
    }
    const candidate={id:Number(compare.dataset.compareCategory),name:String(compare.dataset.compareCategoryLabel||'این دسته')};
    if(!Number.isInteger(candidate.id)||candidate.id<1){toast('دسته‌بندی این محصول برای مقایسه مشخص نیست.','error');return;}
    let selectedCategory=getCompareCategory();
    if(ids.length&&!selectedCategory){
        compare.disabled=true;
        try{
            const current=await api('api/compare.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids})});
            ids=(current.items||[]).map(item=>Number(item.id));selectedCategory=current.category?{id:Number(current.category.id),name:String(current.category.name||'این دسته')}:null;
            saveCompare(ids,selectedCategory);
        }catch(error){toast(error.message,'error');return;}finally{compare.disabled=false;}
    }
    if(selectedCategory&&selectedCategory.id!==candidate.id){toast(`فقط محصولات دسته «${selectedCategory.name}» را می‌توانید با هم مقایسه کنید.`,'error');return;}
    if(ids.length>=4){toast('حداکثر چهار کالا قابل مقایسه است.','error');return;}
    ids.push(id);saveCompare(ids,selectedCategory||candidate);syncCompareButtons(ids);toast(`به مقایسه ${candidate.name} اضافه شد.`);if($('[data-compare-page]'))initCompare();
});

async function initCompare(){
    const root=$('[data-compare-page]');if(!root)return;const ids=getCompare();if(!ids.length){root.innerHTML=`<div class="empty-state"><h2>فهرست مقایسه خالی است</h2><a class="btn btn-primary" href="${APP.baseUrl}/shop.php">انتخاب محصول</a></div>`;return;}
    try{const data=await api('api/compare.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids})});if(!data.items.length){saveCompare([],null);syncCompareButtons([]);root.innerHTML='<div class="empty-state">محصول فعالی برای مقایسه وجود ندارد.</div>';return;}const acceptedIds=data.items.map(item=>Number(item.id));const category=data.category?{id:Number(data.category.id),name:String(data.category.name||'این دسته')}:null;saveCompare(acceptedIds,category);syncCompareButtons(acceptedIds);if((data.removed_ids||[]).length)toast(data.message||'محصولات ناهم‌نوع از مقایسه حذف شدند.','error');const allFeatures=[...new Set(data.items.flatMap(item=>item.features||[]))].slice(0,30);const row=(label,render)=>`<tr><th>${esc(label)}</th>${data.items.map(item=>`<td>${render(item)}</td>`).join('')}</tr>`;root.innerHTML=`<div class="compare-context"><div><strong>مقایسه ${esc(category?.name||'محصولات')}</strong><span>برای نتیجه دقیق، فقط کالاهای همین دسته در جدول نگه داشته می‌شوند.</span></div><a class="btn btn-secondary" href="${APP.baseUrl}/shop.php?category=${encodeURIComponent(data.category?.slug||'')}">افزودن محصول هم‌نوع</a></div><div class="table-wrap"><table class="compare-table"><thead><tr><th>مشخصات</th>${data.items.map(item=>`<th><img src="${esc(item.image)}" alt=""><a href="${esc(item.url)}">${esc(item.title)}</a><button class="remove-btn" data-compare="${item.id}">حذف</button></th>`).join('')}</tr></thead><tbody>${row('قیمت',item=>price(item.price))}${row('برند',item=>esc(item.brand))}${row('دسته',item=>esc(item.category))}${row('موجودی',item=>item.stock>0?'موجود':'ناموجود')}${row('گارانتی',item=>esc(item.warranty))}${row('رده انرژی',item=>esc(item.energy_label))}${row('رنگ',item=>esc(item.color))}${row('امتیاز',item=>fa(item.rating))}${allFeatures.map(feature=>row(feature,item=>(item.features||[]).includes(feature)?'✓':'—')).join('')}</tbody></table></div>`;}catch(error){root.innerHTML=`<div class="form-error">${esc(error.message)}</div>`;}
}

function initPasswordToggles(){
    $$('[data-password-toggle]').forEach(button=>button.addEventListener('click',()=>{
        const input=button.closest('.auth-password-field')?.querySelector('input');if(!input)return;
        const visible=input.type==='text';input.type=visible?'password':'text';button.textContent=visible?'نمایش':'پنهان';button.setAttribute('aria-pressed',visible?'false':'true');
    }));
}

function initMobileKeyboardGuard(){
    let releaseTimer=0;
    const isEditable=target=>target instanceof Element&&target.matches('input,textarea,select,[contenteditable="true"]');
    document.addEventListener('focusin',event=>{
        if(window.matchMedia('(max-width: 980px)').matches&&isEditable(event.target)){
            clearTimeout(releaseTimer);document.body.classList.add('mobile-keyboard-open');
        }
    });
    document.addEventListener('focusout',()=>{
        clearTimeout(releaseTimer);releaseTimer=window.setTimeout(()=>{
            if(!isEditable(document.activeElement))document.body.classList.remove('mobile-keyboard-open');
        },160);
    });
}

if (APP.orderSuccess) {
    saveCart([]);
    setCoupon('');
    try { localStorage.removeItem(cartTokenKey); } catch { /* storage may be unavailable */ }
    resetCheckoutToken();
}
updateCount();
initNavigationDropdown();
initLiveSearch();
initPasswordToggles();
initMobileKeyboardGuard();
renderCart();
initCheckout();
initProductTabs();
initProductVariants();
initMobileProductBuybar();
initFestivalCountdown();
initProductGallery();
initProductAlert();
initCustomerLinks();
initForms();
initCompare();
initEngagementButtons();
})();
