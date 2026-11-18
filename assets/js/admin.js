(() => {
    'use strict';

    const body = document.body;
    const sidebar = document.querySelector('[data-admin-sidebar]');
    const menuButton = document.querySelector('[data-admin-menu]');
    const closeButtons = document.querySelectorAll('[data-admin-menu-close]');

    const setSidebar = open => {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        body.classList.toggle('admin-menu-open', open);
        menuButton?.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    menuButton?.addEventListener('click', () => setSidebar(!sidebar?.classList.contains('open')));
    closeButtons.forEach(button => button.addEventListener('click', () => setSidebar(false)));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') setSidebar(false);
    });
    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024) setSidebar(false);
    });

    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-confirm]');
        if (form && !confirm(form.dataset.confirm || 'ادامه می‌دهید؟')) event.preventDefault();
    });

    document.querySelectorAll('input[name="warranty"]').forEach(input => input.closest('.field')?.remove());

    const bulkProductsForm = document.querySelector('[data-bulk-products-form]');
    if (bulkProductsForm) {
        const productCheckboxes = Array.from(document.querySelectorAll('[data-product-select]'));
        const selectAll = document.querySelector('[data-product-select-all]');
        const selectedCount = bulkProductsForm.querySelector('[data-product-selected-count]');
        const bulkAction = bulkProductsForm.querySelector('[data-product-bulk-action]');
        const submitButton = bulkProductsForm.querySelector('[data-product-bulk-submit]');

        const updateProductSelection = () => {
            const checked = productCheckboxes.filter(checkbox => checkbox.checked);
            productCheckboxes.forEach(checkbox => checkbox.closest('[data-product-row]')?.classList.toggle('is-selected', checkbox.checked));
            if (selectAll) {
                selectAll.checked = productCheckboxes.length > 0 && checked.length === productCheckboxes.length;
                selectAll.indeterminate = checked.length > 0 && checked.length < productCheckboxes.length;
            }
            if (selectedCount) selectedCount.textContent = checked.length
                ? `${checked.length.toLocaleString('fa-IR')} محصول انتخاب شده است.`
                : 'هیچ محصولی انتخاب نشده است.';
            if (submitButton) submitButton.disabled = checked.length === 0;
        };

        selectAll?.addEventListener('change', () => {
            productCheckboxes.forEach(checkbox => { checkbox.checked = selectAll.checked; });
            updateProductSelection();
        });
        productCheckboxes.forEach(checkbox => checkbox.addEventListener('change', updateProductSelection));
        bulkProductsForm.addEventListener('submit', event => {
            const count = productCheckboxes.filter(checkbox => checkbox.checked).length;
            if (count === 0) {
                event.preventDefault();
                alert('حداقل یک محصول را انتخاب کنید.');
                return;
            }
            const deleting = bulkAction?.value === 'delete';
            const message = deleting
                ? `آیا از حذف ${count.toLocaleString('fa-IR')} محصول مطمئن هستید؟ محصولات دارای سابقه سفارش، خرید یا انبار حذف نمی‌شوند.`
                : `آیا ${count.toLocaleString('fa-IR')} محصول انتخاب‌شده غیرفعال شوند؟`;
            if (!confirm(message)) event.preventDefault();
        });
        updateProductSelection();
    }

    const activeItem = document.querySelector('.admin-nav__item.active');
    activeItem?.scrollIntoView({block:'nearest'});
})();

// Admin notifications and near-real-time browser alerts (v2.9.5)
(() => {
    'use strict';
    const body = document.body;
    const endpoint = body.dataset.notificationEndpoint || '';
    const csrf = body.dataset.notificationCsrf || '';
    const root = document.querySelector('[data-admin-notification-root]');
    if (!endpoint || !csrf || !root) return;

    const toggle = root.querySelector('[data-admin-notification-toggle]');
    const popover = root.querySelector('[data-admin-notification-popover]');
    const countNode = root.querySelector('[data-admin-notification-count]');
    const listNode = root.querySelector('[data-admin-notification-list]');
    const readAllButton = root.querySelector('[data-admin-notification-read-all]');
    const browserEnabled = body.dataset.notificationBrowser === '1';
    const soundEnabled = body.dataset.notificationSound === '1';
    const panelEnabled = body.dataset.notificationPanel === '1';
    let lastId = Math.max(0, Number(body.dataset.notificationLastId || 0), ...Array.from(root.querySelectorAll('[data-notification-id]')).map(node => Number(node.dataset.notificationId || 0)));
    let polling = false;
    let audioContext = null;

    const post = (action, ids = []) => fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({_csrf: csrf, action, ids}),
        keepalive: true,
    }).then(response => response.json());

    const setCount = value => {
        const count = Math.max(0, Number(value || 0));
        if (!countNode || !panelEnabled) return;
        countNode.textContent = count.toLocaleString('fa-IR');
        countNode.classList.toggle('is-empty', count < 1);
    };

    const notificationClass = level => ['success','warning','error','info'].includes(level) ? level : 'info';

    const createMini = notification => {
        const link = document.createElement('a');
        link.className = `admin-notification-mini admin-notification-mini--${notificationClass(notification.level)} ${notification.read ? 'is-read' : 'is-unread'}`;
        link.href = notification.url || `${endpoint.replace(/api-notifications\.php(?:\?.*)?$/, 'notifications.php')}`;
        link.dataset.notificationId = String(notification.id);
        const title = document.createElement('strong'); title.textContent = notification.title || 'اعلان جدید';
        const message = document.createElement('span'); message.textContent = notification.message || '';
        const time = document.createElement('small'); time.textContent = notification.created_label || '';
        link.append(title, message, time);
        link.addEventListener('click', () => { post('read', [notification.id]).catch(() => {}); });
        return link;
    };

    const beep = () => {
        if (!soundEnabled) return;
        try {
            audioContext ||= new (window.AudioContext || window.webkitAudioContext)();
            if (audioContext.state !== 'running') return;
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.frequency.value = 760;
            gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.12, audioContext.currentTime + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.16);
            oscillator.connect(gain); gain.connect(audioContext.destination);
            oscillator.start(); oscillator.stop(audioContext.currentTime + 0.17);
        } catch (_) {}
    };

    const shouldSignalNotification = notification => {
        const id = Number(notification?.id || 0);
        if (id < 1) return false;
        const key = `golrizan-admin-notification-${id}`;
        try {
            const previous = Number(localStorage.getItem(key) || 0);
            if (previous > Date.now() - 60000) return false;
            localStorage.setItem(key, String(Date.now()));
        } catch (_) {}
        return true;
    };

    const showBrowserNotification = notification => {
        if (!browserEnabled || !('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            const alert = new Notification(notification.title || 'اعلان مدیریت گلریزان', {
                body: notification.message || '',
                tag: `golrizan-admin-${notification.id}`,
                renotify: false,
            });
            alert.onclick = () => { window.focus(); if (notification.url) window.location.href = notification.url; alert.close(); };
        } catch (_) {}
    };

    const renderIncoming = notifications => {
        if (!Array.isArray(notifications) || notifications.length === 0) return;
        const empty = panelEnabled ? listNode?.querySelector('.admin-notification-empty') : null; empty?.remove();
        notifications.sort((a,b) => Number(a.id) - Number(b.id));
        const seenIds = [];
        let shouldBeep = false;
        notifications.forEach(notification => {
            lastId = Math.max(lastId, Number(notification.id || 0));
            if (panelEnabled && listNode) listNode.prepend(createMini(notification));
            if (shouldSignalNotification(notification)) { showBrowserNotification(notification); shouldBeep = true; }
            seenIds.push(notification.id);
        });
        while (panelEnabled && listNode && listNode.children.length > 5) listNode.lastElementChild?.remove();
        if (shouldBeep) beep();
        if (seenIds.length) post('seen', seenIds).catch(() => {});
    };

    const poll = async () => {
        if (polling) return;
        polling = true;
        try {
            const response = await fetch(`${endpoint}?after=${encodeURIComponent(lastId)}`, {credentials:'same-origin', headers:{'Accept':'application/json'}, cache:'no-store'});
            if (!response.ok) return;
            const data = await response.json();
            if (!data?.ok) return;
            setCount(data.unread);
            renderIncoming(data.notifications || []);
        } catch (_) {
        } finally { polling = false; }
    };

    toggle?.addEventListener('click', event => {
        event.stopPropagation();
        const open = popover?.hasAttribute('hidden');
        if (popover) popover.toggleAttribute('hidden', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', event => {
        if (popover && !root.contains(event.target)) { popover.hidden = true; toggle?.setAttribute('aria-expanded','false'); }
    });
    root.querySelectorAll('[data-notification-id]').forEach(link => link.addEventListener('click', () => post('read', [Number(link.dataset.notificationId || 0)]).catch(() => {})));
    readAllButton?.addEventListener('click', async () => {
        try { const data = await post('read_all'); if (data?.ok) { setCount(0); root.querySelectorAll('.admin-notification-mini').forEach(item => item.classList.replace('is-unread','is-read')); } } catch (_) {}
    });

    const permissionButton = document.querySelector('[data-enable-browser-notifications]');
    const permissionState = document.querySelector('[data-browser-notification-state]');
    const updatePermissionState = () => {
        if (!permissionState) return;
        if (!('Notification' in window)) permissionState.textContent = 'مرورگر شما اعلان دسکتاپ را پشتیبانی نمی‌کند.';
        else if (Notification.permission === 'granted') permissionState.textContent = 'اعلان مرورگر در این دستگاه فعال است.';
        else if (Notification.permission === 'denied') permissionState.textContent = 'اجازه اعلان در تنظیمات مرورگر مسدود شده است.';
        else permissionState.textContent = 'برای دریافت اعلان سفارش در ویندوز یا موبایل، اجازه مرورگر را فعال کنید.';
    };
    permissionButton?.addEventListener('click', async () => {
        if (!('Notification' in window)) { updatePermissionState(); return; }
        try { await Notification.requestPermission(); } catch (_) {}
        updatePermissionState();
    });
    updatePermissionState();

    document.addEventListener('pointerdown', () => {
        if (!soundEnabled) return;
        try { audioContext ||= new (window.AudioContext || window.webkitAudioContext)(); audioContext.resume?.(); } catch (_) {}
    }, {once:true});
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
    window.setTimeout(poll, 1500);
    setInterval(poll, 12000);
})();

// Drag-and-drop order board (v2.9.5)
(() => {
    'use strict';
    const board = document.querySelector('[data-order-board]');
    if (!board) return;
    const endpoint = board.dataset.orderActionEndpoint || '';
    const csrf = board.dataset.orderCsrf || '';
    const sendSmsInput = document.querySelector('[data-order-board-send-sms]');
    const message = document.querySelector('[data-order-board-message]');
    let dragging = null;

    const updateCounts = () => {
        board.querySelectorAll('[data-order-stage]').forEach(column => {
            const count = column.querySelectorAll('[data-order-card]').length;
            const node = column.querySelector('[data-order-stage-count]');
            if (node) node.textContent = count.toLocaleString('fa-IR');
        });
    };
    const showMessage = (text, ok = false) => {
        if (!message) return;
        message.hidden = false;
        message.textContent = text;
        message.classList.toggle('success', ok);
        message.classList.toggle('error', !ok);
        window.setTimeout(() => { message.hidden = true; }, 6000);
    };

    board.querySelectorAll('[data-order-card]').forEach(card => {
        card.addEventListener('dragstart', event => {
            dragging = card;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.orderId || '');
        });
        card.addEventListener('dragend', () => { card.classList.remove('is-dragging'); dragging = null; board.querySelectorAll('[data-order-dropzone]').forEach(zone => zone.classList.remove('is-over')); });
    });

    board.querySelectorAll('[data-order-dropzone]').forEach(zone => {
        zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('is-over'); event.dataTransfer.dropEffect = 'move'; });
        zone.addEventListener('dragleave', () => zone.classList.remove('is-over'));
        zone.addEventListener('drop', async event => {
            event.preventDefault(); zone.classList.remove('is-over');
            if (!dragging) return;
            const source = dragging.parentElement;
            const targetColumn = zone.closest('[data-order-stage]');
            const targetStatus = targetColumn?.dataset.orderTarget || '';
            const currentStatus = dragging.dataset.orderStatus || '';
            if (!targetStatus || targetStatus === currentStatus || source === zone) return;
            let trackingCode = '';
            if (targetStatus === 'shipped') {
                const entered = window.prompt('کد رهگیری مرسوله را وارد کنید:', dragging.dataset.orderTracking || '');
                if (entered === null) return;
                trackingCode = entered.trim();
                if (!trackingCode) { alert('برای ثبت ارسال، واردکردن کد رهگیری الزامی است.'); return; }
            }
            const placeholder = document.createComment('order-card-origin');
            source?.insertBefore(placeholder, dragging);
            zone.prepend(dragging); updateCounts();
            try {
                const response = await fetch(endpoint, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type':'application/json','Accept':'application/json'},
                    body: JSON.stringify({_csrf:csrf, action:'status', id:Number(dragging.dataset.orderId || 0), status:targetStatus, tracking_code:trackingCode, send_sms:Boolean(sendSmsInput?.checked), note:'تغییر وضعیت از تابلوی سفارش‌ها'}),
                });
                const data = await response.json();
                if (!response.ok || !data?.ok) throw new Error(data?.message || 'تغییر وضعیت انجام نشد.');
                dragging.dataset.orderStatus = data.status;
                if (trackingCode) dragging.dataset.orderTracking = trackingCode;
                const badges = dragging.querySelectorAll('.order-board-card__meta span');
                if (badges.length >= 3) badges[2].textContent = data.status_label;
                placeholder.remove(); showMessage(data.message || 'وضعیت سفارش ذخیره شد.', true);
            } catch (error) {
                placeholder.parentNode?.insertBefore(dragging, placeholder); placeholder.remove(); updateCounts();
                showMessage(error.message || 'تغییر وضعیت سفارش انجام نشد.');
            }
        });
    });
})();

// Bulk order actions and print helpers (v2.9.5)
(() => {
    'use strict';
    const form = document.querySelector('[data-order-bulk-form]');
    if (!form) return;
    const boxes = Array.from(document.querySelectorAll(`[data-order-select][form="${form.id}"]`));
    const all = document.querySelector('[data-order-select-all]');
    const count = form.querySelector('[data-order-selected-count]');
    const controls = form.querySelectorAll('[data-order-bulk-status],[data-order-bulk-assign],[data-order-print]');
    const selectedIds = () => boxes.filter(box => box.checked).map(box => Number(box.value));
    const update = () => {
        const ids = selectedIds();
        if (count) count.textContent = ids.length ? `${ids.length.toLocaleString('fa-IR')} سفارش انتخاب شده است.` : 'هیچ سفارشی انتخاب نشده است.';
        controls.forEach(control => { control.disabled = ids.length === 0; });
        boxes.forEach(box => box.closest('[data-order-row]')?.classList.toggle('is-selected', box.checked));
        if (all) { all.checked = boxes.length > 0 && ids.length === boxes.length; all.indeterminate = ids.length > 0 && ids.length < boxes.length; }
    };
    all?.addEventListener('change', () => { boxes.forEach(box => { box.checked = all.checked; }); update(); });
    boxes.forEach(box => box.addEventListener('change', update));
    form.querySelectorAll('[data-order-print]').forEach(button => button.addEventListener('click', () => {
        const ids = selectedIds(); if (!ids.length) return;
        const mode = button.dataset.orderPrint === 'label' ? 'label' : 'invoice';
        const base = window.location.pathname.replace(/orders\.php$/, 'order-print.php');
        window.open(`${base}?mode=${encodeURIComponent(mode)}&ids=${encodeURIComponent(ids.join(','))}`, '_blank', 'noopener');
    }));
    form.addEventListener('submit', event => {
        const submitter = event.submitter;
        if (!submitter || !['bulk_status','bulk_assign'].includes(submitter.value)) return;
        const ids = selectedIds();
        if (!ids.length) { event.preventDefault(); alert('حداقل یک سفارش را انتخاب کنید.'); return; }
        const label = submitter.value === 'bulk_status' ? 'وضعیت سفارش‌های انتخاب‌شده تغییر کند؟' : 'واگذاری و اولویت سفارش‌های انتخاب‌شده تغییر کند؟';
        if (!confirm(label)) event.preventDefault();
    });
    update();
})();

// Tracking-code prompts for order shipping actions (v2.10.12)
(() => {
    'use strict';
    const requestTracking = current => {
        const entered = window.prompt('کد رهگیری مرسوله را وارد کنید:', current || '');
        if (entered === null) return null;
        const value = entered.trim();
        if (!value) { alert('برای ثبت ارسال، واردکردن کد رهگیری الزامی است.'); return null; }
        return value;
    };

    document.querySelectorAll('[data-order-tracking-form]').forEach(form => {
        form.addEventListener('submit', event => {
            const input = form.querySelector('input[name="tracking_code"]');
            if (!input) return;
            const value = requestTracking(form.dataset.currentTracking || input.value || '');
            if (value === null) { event.preventDefault(); return; }
            input.value = value;
        });
    });

    document.querySelectorAll('[data-order-quick-status-form]').forEach(form => {
        form.addEventListener('submit', event => {
            const select = form.querySelector('select[name="status"]');
            const input = form.querySelector('input[name="tracking_code"]');
            if (!select || !input || select.value !== 'shipped') { if (input) input.value = ''; return; }
            const value = requestTracking(form.dataset.currentTracking || '');
            if (value === null) { event.preventDefault(); return; }
            input.value = value;
        });
    });
})();

// Multi-image picker with preview, removal and ordering (v2.10.1)
(() => {
    'use strict';

    document.querySelectorAll('[data-multi-image-picker]').forEach(root => {
        const input = root.querySelector('[data-image-picker-input]');
        const preview = root.querySelector('[data-image-picker-preview]');
        const help = root.querySelector('[data-image-picker-help]');
        const counter = root.querySelector('[data-image-picker-count]');
        const dropzone = input?.closest('.quick-media-dropzone');
        if (!input || !preview || !help) return;

        const maximum = Math.max(0, Number(root.dataset.maxFiles || 0));
        const existing = Math.max(0, Number(root.dataset.existingFiles || 0));
        const absoluteMaximum = 12;
        const maximumFileSize = 6 * 1024 * 1024;
        const defaultHelp = help.textContent || '';
        const canRebuildFileList = typeof DataTransfer !== 'undefined';
        let files = [];
        let objectUrls = [];

        const fa = value => Number(value || 0).toLocaleString('fa-IR');
        const formatSize = size => size >= 1024 * 1024
            ? `${(size / (1024 * 1024)).toFixed(1).replace('.', '٫')} مگابایت`
            : `${Math.max(1, Math.round(size / 1024)).toLocaleString('fa-IR')} کیلوبایت`;

        const clearObjectUrls = () => {
            objectUrls.forEach(url => URL.revokeObjectURL(url));
            objectUrls = [];
        };

        const syncInput = () => {
            if (!canRebuildFileList) return false;
            try {
                const transfer = new DataTransfer();
                files.forEach(file => transfer.items.add(file));
                input.files = transfer.files;
                return true;
            } catch (_) {
                // مرورگرهای جدید DataTransfer را پشتیبانی می‌کنند؛ در مرورگر قدیمی
                // انتخاب اصلی فایل دست‌نخورده می‌ماند و کنترل سرور همچنان فعال است.
                return false;
            }
        };

        const updateCounter = () => {
            if (!counter) return;
            counter.textContent = `${fa(existing + files.length)} از ${fa(absoluteMaximum)} تصویر ثبت/انتخاب شده`;
        };

        const move = (index, direction) => {
            const destination = index + direction;
            if (destination < 0 || destination >= files.length) return;
            [files[index], files[destination]] = [files[destination], files[index]];
            syncInput();
            render();
        };

        const remove = index => {
            files.splice(index, 1);
            syncInput();
            render();
        };

        const render = (message = '') => {
            clearObjectUrls();
            preview.innerHTML = '';
            preview.hidden = files.length === 0;
            updateCounter();

            if (files.length === 0) {
                help.textContent = message || defaultHelp;
                input.setCustomValidity('');
                return;
            }

            files.forEach((file, index) => {
                const figure = document.createElement('figure');
                figure.className = 'quick-media-thumb quick-media-thumb--editable';

                const imageUrl = URL.createObjectURL(file);
                objectUrls.push(imageUrl);
                const image = document.createElement('img');
                image.src = imageUrl;
                image.alt = file.name;

                const body = document.createElement('figcaption');
                body.className = 'quick-media-thumb__body';
                const badge = document.createElement('strong');
                badge.textContent = index === 0 ? 'تصویر اول' : `تصویر ${fa(index + 1)}`;
                const name = document.createElement('span');
                name.className = 'quick-media-thumb__name';
                name.textContent = file.name;
                name.title = file.name;
                const size = document.createElement('small');
                size.textContent = formatSize(file.size);
                body.append(badge, name, size);

                const actions = document.createElement('div');
                actions.className = 'quick-media-thumb__actions';
                const up = document.createElement('button');
                up.type = 'button'; up.className = 'quick-media-thumb__button'; up.textContent = '←';
                up.title = 'انتقال به قبل'; up.disabled = index === 0;
                up.addEventListener('click', () => move(index, -1));
                const down = document.createElement('button');
                down.type = 'button'; down.className = 'quick-media-thumb__button'; down.textContent = '→';
                down.title = 'انتقال به بعد'; down.disabled = index === files.length - 1;
                down.addEventListener('click', () => move(index, 1));
                const removeButton = document.createElement('button');
                removeButton.type = 'button'; removeButton.className = 'quick-media-thumb__button quick-media-thumb__button--remove';
                removeButton.textContent = 'حذف'; removeButton.title = 'حذف از فایل‌های انتخاب‌شده';
                removeButton.addEventListener('click', () => remove(index));
                actions.append(up, down, removeButton);
                actions.hidden = !canRebuildFileList;

                figure.append(image, body, actions);
                preview.append(figure);
            });

            help.textContent = message || `${fa(files.length)} تصویر جدید انتخاب شده است؛ ترتیب نمایش بالا همان ترتیب ذخیره خواهد بود.`;
            input.setCustomValidity('');
        };

        const addFiles = selected => {
            let rejectedType = 0;
            let rejectedSize = 0;
            let rejectedCapacity = 0;
            const fingerprints = new Set(files.map(file => `${file.name}:${file.size}:${file.lastModified}`));

            Array.from(selected || []).forEach(file => {
                if (!(file instanceof File) || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                    rejectedType += 1;
                    return;
                }
                if (file.size > maximumFileSize) {
                    rejectedSize += 1;
                    return;
                }
                const fingerprint = `${file.name}:${file.size}:${file.lastModified}`;
                if (fingerprints.has(fingerprint)) return;
                if (files.length >= maximum) {
                    rejectedCapacity += 1;
                    return;
                }
                fingerprints.add(fingerprint);
                files.push(file);
            });

            syncInput();
            const messages = [];
            if (rejectedCapacity) messages.push(`${fa(rejectedCapacity)} فایل به‌دلیل تکمیل ظرفیت اضافه نشد.`);
            if (rejectedSize) messages.push(`${fa(rejectedSize)} فایل بیشتر از ۶ مگابایت بود.`);
            if (rejectedType) messages.push(`${fa(rejectedType)} فایل فرمت تصویری مجاز نداشت.`);
            render(messages.join(' '));
        };

        input.addEventListener('change', () => {
            const selected = Array.from(input.files || []);
            if (!canRebuildFileList) {
                files = selected.slice(0, maximum);
                render('مرورگر شما مرتب‌سازی و حذف فایل قبل از ارسال را پشتیبانی نمی‌کند؛ انتخاب فعلی بدون تغییر ارسال می‌شود.');
                return;
            }
            // انتخاب مجدد فایل‌ها به مجموعه فعلی اضافه می‌شود، نه اینکه آن را پاک کند.
            input.value = '';
            addFiles(selected);
        });

        if (dropzone) {
            ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => {
                event.preventDefault();
                if (!input.disabled) dropzone.classList.add('is-dragover');
            }));
            ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => {
                event.preventDefault();
                dropzone.classList.remove('is-dragover');
            }));
            dropzone.addEventListener('drop', event => {
                if (input.disabled) return;
                addFiles(event.dataTransfer?.files || []);
            });
        }

        root.closest('form')?.addEventListener('submit', event => {
            syncInput();
            if (files.length > maximum) {
                event.preventDefault();
                input.setCustomValidity(`حداکثر ${fa(maximum)} تصویر جدید می‌توانید انتخاب کنید.`);
                input.reportValidity();
            }
        });

        window.addEventListener('beforeunload', clearObjectUrls);
        updateCounter();
    });
})();


// Convert product feature lines in the form «title: value» to technical specification rows (v2.10.2)
(() => {
    'use strict';

    const normalize = value => String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('fa-IR');
    const hasLetter = value => /[A-Za-z\u0600-\u06FF]/.test(value);
    const parseLine = line => {
        const text = String(line || '').trim();
        if (!text) return null;
        const match = text.match(/^(.{1,180}?)[\u003A\uFF1A]\s*(.+)$/u);
        if (!match) return null;
        const title = match[1].trim();
        const value = match[2].trim();
        if (!title || !value || !hasLetter(title)) return null;
        if (/^[a-z][a-z0-9+.-]*$/i.test(title) && value.startsWith('//')) return null;
        if ([...title].length > 180 || [...value].length > 2000) return null;
        return {title, value};
    };

    document.querySelectorAll('[data-feature-specification-bridge]').forEach(bridge => {
        const source = bridge.querySelector('[data-feature-specification-source]');
        const button = bridge.querySelector('[data-convert-feature-specifications]');
        const message = bridge.querySelector('[data-feature-specification-message]');
        const form = bridge.closest('form');
        const list = form?.querySelector('[data-specification-list]');
        const template = form?.querySelector('[data-specification-template]');
        const addButton = form?.querySelector('[data-add-specification]');
        if (!source || !list || !template) return;

        const showMessage = (text, type = 'success') => {
            if (!message) return;
            message.hidden = !text;
            message.textContent = text;
            message.className = `feature-specification-bridge__message feature-specification-bridge__message--${type}`;
        };

        const rowData = () => Array.from(list.querySelectorAll('[data-specification-row]')).map(row => ({
            row,
            titleInput: row.querySelector('input[name="spec_name[]"]'),
            valueInput: row.querySelector('textarea[name="spec_value[]"]'),
        }));

        const addSpecification = (title, value) => {
            let rows = rowData();
            let target = rows.find(item => !item.titleInput?.value.trim() && !item.valueInput?.value.trim());
            if (!target) {
                if (rows.length >= 60) return false;
                if (addButton) addButton.click();
                else list.append(template.content.cloneNode(true));
                rows = rowData();
                target = rows[rows.length - 1];
            }
            if (!target?.titleInput || !target?.valueInput) return false;
            target.titleInput.value = title;
            target.valueInput.value = value;
            target.titleInput.dispatchEvent(new Event('input', {bubbles: true}));
            target.valueInput.dispatchEvent(new Event('input', {bubbles: true}));
            return true;
        };

        const convert = (announce = true, showEmptyMessage = true) => {
            const lines = source.value.split(/\r?\n/);
            const kept = [];
            let converted = 0;
            let duplicate = 0;
            let conflict = 0;
            let overflow = 0;

            for (const originalLine of lines) {
                const line = originalLine.trim();
                if (!line) continue;
                const pair = parseLine(line);
                if (!pair) {
                    kept.push(line);
                    continue;
                }

                const existing = rowData().find(item => normalize(item.titleInput?.value) === normalize(pair.title));
                if (existing) {
                    const currentValue = existing.valueInput?.value.trim() || '';
                    if (!currentValue && existing.valueInput) {
                        existing.valueInput.value = pair.value;
                        existing.valueInput.dispatchEvent(new Event('input', {bubbles: true}));
                        converted++;
                    } else if (normalize(currentValue) === normalize(pair.value)) {
                        duplicate++;
                    } else {
                        kept.push(line);
                        conflict++;
                    }
                    continue;
                }

                if (addSpecification(pair.title, pair.value)) converted++;
                else {
                    kept.push(line);
                    overflow++;
                }
            }

            source.value = kept.join('\n');
            if (!announce) return {converted, duplicate, conflict, overflow};
            if (converted > 0) {
                const details = [];
                if (duplicate) details.push(`${duplicate.toLocaleString('fa-IR')} مورد تکراری حذف شد`);
                if (conflict) details.push(`${conflict.toLocaleString('fa-IR')} مورد به‌دلیل تفاوت مقدار در متن باقی ماند`);
                if (overflow) details.push(`${overflow.toLocaleString('fa-IR')} مورد به‌دلیل تکمیل ظرفیت باقی ماند`);
                showMessage(`${converted.toLocaleString('fa-IR')} مورد به جدول مشخصات فنی منتقل شد${details.length ? `؛ ${details.join('؛ ')}` : ''}.`, conflict || overflow ? 'warning' : 'success');
            } else if (conflict || overflow) {
                showMessage('موردی منتقل نشد؛ مشخصه هم‌نام با مقدار متفاوت وجود دارد یا ظرفیت جدول تکمیل شده است.', 'warning');
            } else if (button && showEmptyMessage) {
                showMessage('خط معتبری با الگوی «عنوان: مقدار» پیدا نشد.', 'info');
            }
            return {converted, duplicate, conflict, overflow};
        };

        button?.addEventListener('click', () => convert(true, true));
        source.addEventListener('blur', () => convert(true, false));
        source.addEventListener('paste', () => window.setTimeout(() => convert(true, false), 40));
        source.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) window.setTimeout(() => convert(true, false), 0);
        });
        form?.addEventListener('submit', () => convert(false));
    });
})();
