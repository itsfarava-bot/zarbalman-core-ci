(function () {
    'use strict';
    if (!document.querySelector('.zbm-managed-panel, .zbm-manual-admin')) { return; }
    var normalizeDigits = function (value) {
        return value.replace(/[۰-۹٠-٩]/g, function (digit) {
            var persian = '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit);
            return String(persian >= 0 ? persian : '٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
        });
    };
    var withinPanel = function (element) { return element.closest('.zbm-managed-panel, .zbm-manual-admin'); };
    var show = function (container, message, kind) {
        var feedback = container.querySelector('[data-zbm-ops-feedback]');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'zbm-managed-alert zbm-ops-feedback';
            feedback.setAttribute('data-zbm-ops-feedback', '');
            feedback.setAttribute('role', 'status');
            feedback.tabIndex = -1;
            container.prepend(feedback);
        }
        feedback.textContent = message;
        feedback.classList.toggle('is-success', kind === 'success');
        feedback.classList.toggle('is-error', kind === 'error');
        feedback.focus({ preventScroll: true });
        return feedback;
    };
    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement) || !withinPanel(input)) { return; }
        if (input.name === 'code') { input.value = normalizeDigits(input.value).replace(/[^0-9]/g, '').slice(0, 6); }
        if (input.name === 'confirmed_amount_toman') { input.value = normalizeDigits(input.value).replace(/[^0-9]/g, ''); }
    });
    var releasePreviews = function (container) {
        container.querySelectorAll('[data-zbm-object-url]').forEach(function (image) {
            URL.revokeObjectURL(image.getAttribute('data-zbm-object-url'));
        });
    };
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement) || !withinPanel(input)) { return; }
        if (input.matches('[data-zbm-manual-toggle]')) {
            var status = withinPanel(input).querySelector('[data-zbm-manual-status]');
            if (status) {
                status.textContent = input.checked ? 'فعال' : 'غیرفعال';
                status.classList.toggle('is-enabled', input.checked);
                status.classList.toggle('is-disabled', !input.checked);
            }
        }
        if (input.type !== 'file') { return; }
        var files = Array.from(input.files || []);
        var isReceipt = input.name === 'receipt';
        var maximum = isReceipt ? 1 : 4;
        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (isReceipt) { allowed.push('application/pdf'); }
        var invalid = files.length > maximum || files.some(function (file) {
            return file.size > 5 * 1024 * 1024 || (!isReceipt && allowed.indexOf(file.type) === -1);
        });
        input.setCustomValidity(invalid ? 'تعداد، نوع یا حجم فایل مجاز نیست؛ حداکثر ۵ مگابایت برای هر فایل.' : '');
        var prior = input.parentElement.querySelector('.zbm-file-preview');
        if (prior) { releasePreviews(prior); prior.remove(); }
        if (invalid || !window.URL || !URL.createObjectURL) { return; }
        var preview = document.createElement('div');
        preview.className = 'zbm-file-preview';
        files.forEach(function (file, index) {
            if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) { return; }
            var image = document.createElement('img');
            var url = URL.createObjectURL(file);
            image.src = url;
            image.alt = 'پیش‌نمایش عکس انتخاب‌شده ' + (index + 1);
            image.setAttribute('data-zbm-object-url', url);
            preview.appendChild(image);
        });
        input.parentElement.appendChild(preview);
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-zbm-receipt-expand]');
        if (!button) { return; }
        var figure = button.closest('.zbm-manual-receipt-preview');
        if (!figure) { return; }
        var expanded = figure.classList.toggle('is-expanded');
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.textContent = expanded ? 'نمایش جمع‌وجور' : 'نمایش تصویر در اندازهٔ بزرگ‌تر';
    });
    document.addEventListener('error', function (event) {
        var target = event.target;
        if (!(target instanceof HTMLImageElement) || !target.matches('[data-zbm-receipt-image]')) { return; }
        var figure = target.closest('.zbm-manual-receipt-preview');
        if (!figure) { return; }
        target.hidden = true;
        var error = figure.querySelector('[data-zbm-receipt-image-error]');
        var button = figure.querySelector('[data-zbm-receipt-expand]');
        if (error) { error.hidden = false; }
        if (button) { button.hidden = true; }
    }, true);
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.zbm-managed-form') || !withinPanel(form)) { return; }
        var panel = form.closest('[data-zbm-case]');
        if (form.dataset.submitting === '1' || (panel && panel.dataset.busy === '1')) { event.preventDefault(); return; }
        var confirmation = form.getAttribute('data-confirm');
        if (confirmation && !window.confirm(confirmation)) { event.preventDefault(); return; }
        if (!form.checkValidity()) { event.preventDefault(); form.reportValidity(); return; }
        var ajaxUrl = panel && panel.getAttribute('data-zbm-ajax-url');
        ajaxUrl = ajaxUrl || form.getAttribute('data-ajax-url');
        if (!ajaxUrl || !window.fetch || !window.FormData || !window.DOMParser) { return; }
        var target = new URL(ajaxUrl, window.location.href);
        if (target.origin !== window.location.origin) { return; }
        event.preventDefault();
        var body = new FormData(form);
        form.dataset.submitting = '1';
        form.setAttribute('aria-busy', 'true');
        if (panel) { panel.dataset.busy = '1'; }
        form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
        show(form, 'در حال ثبت اطلاعات؛ لطفاً منتظر بمانید.', 'info');
        var committed = false;
        var resultMessage = '';
        var resultKind = 'success';
        window.fetch(target.href, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data || !data.success) {
                        throw new Error(data && data.data && data.data.message || 'نتیجه روشن نیست؛ وضعیت درخواست را تازه کنید.');
                    }
                    return data.data;
                });
            }).then(function (data) {
                committed = true;
                resultMessage = data.message;
                resultKind = data.notice_type || 'success';
                show(form, resultMessage, resultKind);
                if (!panel || !data.view_url) { return; }
                var url = new URL(data.view_url, window.location.href);
                if (url.origin !== window.location.origin) { throw new Error('نشانی تازه‌سازی معتبر نیست.'); }
                return window.fetch(url.href, { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
                    if (!response.ok) { throw new Error('نمایش وضعیت تازه ممکن نشد.'); }
                    return response.text();
                }).then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var next = Array.from(doc.querySelectorAll('[data-zbm-case]')).find(function (item) {
                        return item.getAttribute('data-zbm-case') === panel.getAttribute('data-zbm-case');
                    });
                    if (!next) { throw new Error('نمایش وضعیت تازه ممکن نشد.'); }
                    // Only the authenticated same-origin panel is imported; never run response scripts.
                    next.querySelectorAll('script').forEach(function (script) { script.remove(); });
                    var imported = document.importNode(next, true);
                    // Keep other rows' unsent weight/photos when this row refreshes
                    // the shared case version. Never preserve security/hidden fields.
                    var draftFiles = [];
                    panel.querySelectorAll('form.zbm-managed-form').forEach(function (draft) {
                        if (draft === form) { return; }
                        var operation = draft.elements.operation, lineId = draft.elements.line_id;
                        if (!operation || !lineId || ['flow_record_weight', 'flow_propose_weight', 'flow_object'].indexOf(operation.value) === -1) { return; }
                        var replacement = Array.from(imported.querySelectorAll('form.zbm-managed-form')).find(function (candidate) {
                            return candidate.elements.operation && candidate.elements.line_id && candidate.elements.operation.value === operation.value && candidate.elements.line_id.value === lineId.value;
                        });
                        if (!replacement) { return; }
                        ['weight', 'reason', 'acknowledged'].forEach(function (name) {
                            var before = draft.elements[name], after = replacement.elements[name];
                            if (!before || !after) { return; }
                            if (before.type === 'checkbox') { after.checked = before.checked; } else { after.value = before.value; }
                        });
                        var file = draft.querySelector('input[type="file"]'), nextFile = replacement.querySelector('input[type="file"]');
                        if (file && nextFile && file.files && file.files.length) { nextFile.replaceWith(file); draftFiles.push(file); }
                    });
                    releasePreviews(panel);
                    panel.replaceWith(imported);
                    draftFiles.forEach(function (input) { input.dispatchEvent(new Event('change', {bubbles: true})); });
                    show(imported, resultMessage, resultKind);
                });
            }).catch(function (error) {
                var feedback = show(form, committed
                    ? resultMessage + ' برای دیدن آخرین وضعیت، صفحه را تازه کنید؛ اطلاعات را دوباره نفرستید.'
                    : (error.message || 'پاسخ سرور روشن نیست؛ پیش از تلاش دوباره وضعیت را تازه کنید.'), committed ? resultKind : 'error');
                var refresh = document.createElement('button');
                refresh.type = 'button';
                refresh.className = 'button';
                refresh.textContent = 'تازه‌کردن وضعیت';
                refresh.addEventListener('click', function () { window.location.reload(); });
                feedback.appendChild(refresh);
                // An unknown response can follow a committed mutation. Never auto-retry POST.
            }).finally(function () {
                form.removeAttribute('aria-busy');
            });
    });
    // Local display only: no polling, automatic submission or server-state transitions.
    var updateDeadlines = function () {
        document.querySelectorAll('[data-zbm-review-deadline]').forEach(function (node) {
            var time = Date.parse(node.getAttribute('data-zbm-review-deadline'));
            if (!Number.isFinite(time)) { return; }
            if (!node.dataset.originalLabel) { node.dataset.originalLabel = node.textContent; }
            var seconds = Math.ceil((time - Date.now()) / 1000);
            node.textContent = seconds <= 0 ? 'مهلت بررسی پایان یافته است؛ آخرین وضعیت را تازه کنید.' :
                node.dataset.originalLabel + ' · حدود ' + Math.ceil(seconds / 60).toLocaleString('fa-IR') + ' دقیقه باقی‌مانده';
        });
    };
    updateDeadlines();
    window.setInterval(updateDeadlines, 30000);
    window.addEventListener('pagehide', function () { releasePreviews(document); });
})();
