(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.zbm-request-form').forEach(function (form) {
            var box = form.querySelector('[data-zbm-lines]');
            if (!box || box.dataset.ready) { return; }
            box.dataset.ready = '1';
            var rows = [], page = 1, serial = 0, variableId = 0;
            var hidden = form.querySelector('[name="lines_json"]');
            var picker = box.querySelector('[data-zbm-product-picker]');
            var list = box.querySelector('[data-zbm-selected-lines]');
            var results = box.querySelector('[data-zbm-product-results]');
            var message = box.querySelector('[data-zbm-product-message]');
            var more = box.querySelector('[data-zbm-more-products]');
            var back = box.querySelector('[data-zbm-back-products]');
            function write() {
                hidden.value = JSON.stringify(rows.map(function (row) { return {product_id: row.product_id, variation_id: row.variation_id, quantity: row.quantity}; }));
                form.dispatchEvent(new CustomEvent('zbm:lines-changed', {bubbles: true}));
            }
            function button(text, action) {
                var node = document.createElement('button'); node.type = 'button'; node.className = 'button'; node.textContent = text; node.addEventListener('click', action); return node;
            }
            function render() {
                list.replaceChildren();
                rows.forEach(function (row) {
                    var card = document.createElement('div'); card.className = 'zbm-selected-product';
                    var title = document.createElement('strong'); title.textContent = row.name; card.appendChild(title);
                    var label = document.createElement('label'); label.textContent = 'تعداد بسته';
                    var input = document.createElement('input'); input.type = 'number'; input.min = '1'; input.step = '1'; input.inputMode = 'numeric'; input.value = row.quantity; input.required = true;
                    input.addEventListener('input', function () { var value = Number(input.value); row.quantity = Number.isSafeInteger(value) && value > 0 ? value : 0; write(); });
                    label.appendChild(input); card.appendChild(label);
                    card.appendChild(button('حذف', function () { rows = rows.filter(function (other) { return other !== row; }); render(); write(); }));
                    list.appendChild(card);
                });
            }
            function load(append) {
                var token = ++serial, config = window.zbmPurchaseRequestsConfig || {};
                if (!config.ajaxUrl) { message.textContent = 'فهرست محصولات در دسترس نیست.'; return; }
                var body = new URLSearchParams({action: 'zbm_request_vendor_products', product_id: form.elements.product_id.value, variation_id: form.elements.variation_id.value, variable_id: String(variableId), search: box.querySelector('[data-zbm-product-search]').value, page: String(page)});
                back.hidden = variableId === 0;
                message.textContent = 'در حال دریافت محصولات…'; more.hidden = true;
                if (!append) { results.replaceChildren(); }
                fetch(config.ajaxUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()})
                    .then(function (response) { if (!response.ok) { throw new Error('unavailable'); } return response.json(); })
                    .then(function (response) {
                        if (token !== serial) { return; }
                        if (!response.success || !response.data || !Array.isArray(response.data.items)) { throw new Error('unavailable'); }
                        response.data.items.forEach(function (item) {
                            if (!Number.isSafeInteger(item.product_id) || !Number.isSafeInteger(item.variation_id) || typeof item.name !== 'string') { return; }
                            if (item.type !== 'variable' && String(item.product_id) === form.elements.product_id.value && String(item.variation_id) === form.elements.variation_id.value) { return; }
                            var card = document.createElement('div'); card.className = 'zbm-product-choice';
                            if (item.type === 'variable') {
                                var parentTitle = document.createElement('span'); parentTitle.textContent = item.name; card.appendChild(parentTitle);
                                card.appendChild(button('انتخاب نوع محصول', function () { variableId = item.product_id; page = 1; load(false); }));
                                results.appendChild(card); return;
                            }
                            var title = document.createElement('span'); title.textContent = item.name + ' — ' + Number(item.price).toLocaleString('fa-IR') + ' تومان / بسته'; card.appendChild(title);
                            card.appendChild(button('افزودن', function () {
                                var existing = rows.find(function (row) { return row.product_id === item.product_id && row.variation_id === item.variation_id; });
                                if (existing) { message.textContent = 'این محصول انتخاب شده است؛ تعداد را در ردیف آن تغییر دهید.'; return; }
                                if (rows.length >= 19) { message.textContent = 'حداکثر ۲۰ محصول در یک درخواست قابل ثبت است.'; return; }
                                rows.push({product_id: item.product_id, variation_id: item.variation_id, quantity: 1, name: item.name});
                                render(); write(); message.textContent = 'محصول به درخواست اضافه شد.';
                            }));
                            results.appendChild(card);
                        });
                        message.textContent = results.children.length ? 'محصول و تعداد موردنظر را انتخاب کنید.' : 'محصول دیگری در این صفحه یافت نشد.';
                        more.hidden = response.data.has_more !== true;
                    }).catch(function () { if (token === serial) { message.textContent = 'دریافت محصولات انجام نشد؛ دوباره جست‌وجو کنید.'; } });
            }
            box.querySelector('[data-zbm-open-products]').addEventListener('click', function () { picker.hidden = !picker.hidden; if (!picker.hidden) { variableId = 0; page = 1; load(false); } });
            back.addEventListener('click', function () { variableId = 0; page = 1; load(false); });
            box.querySelector('[data-zbm-search-products]').addEventListener('click', function () { variableId = 0; page = 1; load(false); });
            box.querySelector('[data-zbm-product-search]').addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); variableId = 0; page = 1; load(false); } });
            more.addEventListener('click', function () { page += 1; load(true); });
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
