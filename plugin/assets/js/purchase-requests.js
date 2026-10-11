(function ($) {
    'use strict';

    var DIGITS = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9', '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };
    var AJAX_TIMEOUT_MS = 15000;
    var QUOTE_DEBOUNCE_MS = 300;

    function latinDigits(value) {
        return String(value || '').replace(/[۰-۹٠-٩]/g, function (digit) { return DIGITS[digit] || digit; });
    }

    var JALALI_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    function persianDigits(value) {
        return String(value).replace(/[0-9]/g, function (digit) { return '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]; });
    }

    function padDatePart(value) {
        return String(value).padStart(2, '0');
    }

    function isGregorianLeapYear(year) {
        return year % 400 === 0 || (year % 4 === 0 && year % 100 !== 0);
    }

    function jalaliToGregorian(year, month, day) {
        var jy = year;
        var gy = jy <= 979 ? 621 : 1600;
        jy -= jy <= 979 ? 0 : 979;
        var days = 365 * jy + Math.floor(jy / 33) * 8 + Math.floor((jy % 33 + 3) / 4) + 78 + day + (month < 7 ? (month - 1) * 31 : (month - 7) * 30 + 186);
        gy += 400 * Math.floor(days / 146097);
        days %= 146097;
        if (days > 36524) {
            gy += 100 * Math.floor(--days / 36524);
            days %= 36524;
            if (days >= 365) { days += 1; }
        }
        gy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) {
            gy += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }
        var monthLengths = [31, isGregorianLeapYear(gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gregorianMonth = 1;
        while (gregorianMonth <= 12 && days >= monthLengths[gregorianMonth - 1]) {
            days -= monthLengths[gregorianMonth - 1];
            gregorianMonth += 1;
        }
        return { year: gy, month: gregorianMonth, day: days + 1 };
    }

    function gregorianToJalali(year, month, day) {
        var monthDaysBefore = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var jy = year <= 1600 ? 0 : 979;
        var gy = year - (year <= 1600 ? 621 : 1600);
        var gy2 = month > 2 ? gy + 1 : gy;
        var days = 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + day + monthDaysBefore[month - 1];
        jy += 33 * Math.floor(days / 12053);
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) {
            jy += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }
        return {
            year: jy,
            month: days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30),
            day: 1 + (days < 186 ? days % 31 : (days - 186) % 30)
        };
    }

    function jalaliMonthLength(year, month) {
        if (month <= 6) { return 31; }
        if (month <= 11) { return 30; }
        var lastDay = jalaliToGregorian(year, 12, 30);
        var back = gregorianToJalali(lastDay.year, lastDay.month, lastDay.day);
        return back.year === year && back.month === 12 && back.day === 30 ? 30 : 29;
    }

    function parseJalaliDate(value) {
        var normalized = latinDigits(value).trim().replace(/[.\-]+/g, '/');
        var match = normalized.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
        if (!match) { return null; }
        var parsed = { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
        if (parsed.year < 1 || parsed.year > 3000 || parsed.month < 1 || parsed.month > 12 || parsed.day < 1 || parsed.day > jalaliMonthLength(parsed.year, parsed.month)) {
            return null;
        }
        return parsed;
    }

    function formatJalaliDate(date, usePersianDigits) {
        var value = date.year + '/' + padDatePart(date.month) + '/' + padDatePart(date.day);
        return usePersianDigits ? persianDigits(value) : value;
    }

    function displayJalaliValue(value) {
        var parsed = parseJalaliDate(value);
        return parsed ? formatJalaliDate(parsed, true) : String(value || '—');
    }

    function jalaliInputDigits(value) {
        return latinDigits(value).replace(/[^0-9]/g, '').slice(0, 8);
    }

    function formatJalaliInputValue(value) {
        var digits = jalaliInputDigits(value);
        var formatted = digits;
        if (digits.length > 4) {
            formatted = digits.slice(0, 4) + '/' + digits.slice(4, 6);
        }
        if (digits.length > 6) {
            formatted += '/' + digits.slice(6, 8);
        }
        return persianDigits(formatted);
    }

    function digitCountBefore(value, position) {
        return latinDigits(String(value || '').slice(0, Math.max(0, position))).replace(/[^0-9]/g, '').length;
    }

    function digitCountInRange(value, start, end) {
        return digitCountBefore(value, end) - digitCountBefore(value, start);
    }

    function caretForDigitCount(value, count) {
        if (count <= 0) { return 0; }
        var seen = 0;
        for (var index = 0; index < value.length; index += 1) {
            if (/^[0-9]$/.test(latinDigits(value.charAt(index)))) {
                seen += 1;
                if (seen >= count) { return index + 1; }
            }
        }
        return value.length;
    }

    function normalizeJalaliDateInput(input) {
        var previous = String(input.value || '');
        var caret = typeof input.selectionStart === 'number' ? input.selectionStart : previous.length;
        var digitsBeforeCaret = digitCountBefore(previous, caret);
        var formatted = formatJalaliInputValue(previous);
        if (previous !== formatted) {
            input.value = formatted;
            if (document.activeElement === input && typeof input.setSelectionRange === 'function') {
                var nextCaret = caretForDigitCount(formatted, Math.min(digitsBeforeCaret, 8));
                input.setSelectionRange(nextCaret, nextCaret);
            }
        }
        return syncJalaliDateInput(input, true);
    }

    function isJalaliNavigationKey(event) {
        return event.ctrlKey || event.metaKey || event.altKey || ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'Tab', 'Enter', 'Escape'].indexOf(event.key) !== -1;
    }

    function jalaliDateToIso(value) {
        var parsed = parseJalaliDate(value);
        if (!parsed) { return ''; }
        var gregorian = jalaliToGregorian(parsed.year, parsed.month, parsed.day);
        return gregorian.year + '-' + padDatePart(gregorian.month) + '-' + padDatePart(gregorian.day);
    }

    function compareJalaliDate(left, right) {
        if (left.year !== right.year) { return left.year - right.year; }
        if (left.month !== right.month) { return left.month - right.month; }
        return left.day - right.day;
    }

    function syncJalaliDateInput(input, dispatchChange) {
        var hidden = input.parentNode ? input.parentNode.querySelector('input[type="hidden"]') : null;
        var iso = jalaliDateToIso(input.value);
        if (hidden) {
            hidden.value = iso;
            if (dispatchChange) { hidden.dispatchEvent(new Event('change', { bubbles: true })); }
        }
        if (input.value.trim()) { input.setAttribute('aria-invalid', iso ? 'false' : 'true'); }
        else { input.removeAttribute('aria-invalid'); }
        return iso;
    }

    function setJalaliDate(input, date) {
        input.value = formatJalaliDate(date, true);
        syncJalaliDateInput(input, true);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function jalaliDateIsAllowed(input, date) {
        var minimum = parseJalaliDate(input.getAttribute('data-jalali-min') || '');
        var maximum = parseJalaliDate(input.getAttribute('data-jalali-max') || '');
        return (!minimum || compareJalaliDate(date, minimum) >= 0) && (!maximum || compareJalaliDate(date, maximum) <= 0);
    }

    function setJalaliPickerState(input, picker, visible) {
        picker.hidden = !visible;
        input.setAttribute('aria-expanded', visible ? 'true' : 'false');
        var trigger = input.parentNode ? input.parentNode.querySelector('.zbm-jalali-trigger') : null;
        if (trigger) { trigger.setAttribute('aria-expanded', visible ? 'true' : 'false'); }
    }

    function renderJalaliPicker(input, picker, year, month) {
        picker.textContent = '';
        var header = document.createElement('div');
        header.className = 'zbm-jalali-picker-header';
        var previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'zbm-jalali-month-button';
        previous.textContent = '‹';
        previous.setAttribute('aria-label', 'ماه قبل');
        var title = document.createElement('strong');
        title.textContent = JALALI_MONTHS[month - 1] + ' ' + persianDigits(year);
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'zbm-jalali-month-button';
        next.textContent = '›';
        next.setAttribute('aria-label', 'ماه بعد');
        var minimumMonth = parseJalaliDate(input.getAttribute('data-jalali-min') || '');
        var maximumMonth = parseJalaliDate(input.getAttribute('data-jalali-max') || '');
        previous.disabled = !!minimumMonth && (year < minimumMonth.year || (year === minimumMonth.year && month <= minimumMonth.month));
        next.disabled = !!maximumMonth && (year > maximumMonth.year || (year === maximumMonth.year && month >= maximumMonth.month));
        header.appendChild(previous);
        header.appendChild(title);
        header.appendChild(next);
        picker.appendChild(header);

        var weekdays = document.createElement('div');
        weekdays.className = 'zbm-jalali-weekdays';
        ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].forEach(function (label) {
            var weekday = document.createElement('span');
            weekday.textContent = label;
            weekday.setAttribute('aria-hidden', 'true');
            weekdays.appendChild(weekday);
        });
        picker.appendChild(weekdays);

        var grid = document.createElement('div');
        grid.className = 'zbm-jalali-days';
        var firstGregorian = jalaliToGregorian(year, month, 1);
        var offset = (new Date(Date.UTC(firstGregorian.year, firstGregorian.month - 1, firstGregorian.day)).getUTCDay() + 1) % 7;
        for (var blank = 0; blank < offset; blank += 1) { grid.appendChild(document.createElement('span')); }
        var selected = parseJalaliDate(input.value);
        for (var day = 1; day <= jalaliMonthLength(year, month); day += 1) {
            var button = document.createElement('button');
            var date = { year: year, month: month, day: day };
            button.type = 'button';
            button.className = 'zbm-jalali-day';
            button.textContent = persianDigits(day);
            button.setAttribute('aria-label', formatJalaliDate(date, true));
            button.disabled = !jalaliDateIsAllowed(input, date);
            if (selected && compareJalaliDate(selected, date) === 0) { button.classList.add('is-selected'); }
            button.addEventListener('click', function (event) {
                var selectedDate = parseJalaliDate(event.currentTarget.getAttribute('data-zbm-date'));
                if (!selectedDate) { return; }
                setJalaliDate(input, selectedDate);
                setJalaliPickerState(input, picker, false);
                input.setAttribute('data-zbm-suppress-picker-open', '1');
                input.focus();
            });
            button.setAttribute('data-zbm-date', formatJalaliDate(date, false));
            grid.appendChild(button);
        }
        picker.appendChild(grid);
        previous.addEventListener('click', function () {
            var previousMonth = month === 1 ? 12 : month - 1;
            var previousYear = month === 1 ? year - 1 : year;
            renderJalaliPicker(input, picker, previousYear, previousMonth);
        });
        next.addEventListener('click', function () {
            var nextMonth = month === 12 ? 1 : month + 1;
            var nextYear = month === 12 ? year + 1 : year;
            renderJalaliPicker(input, picker, nextYear, nextMonth);
        });
    }

    function openJalaliPicker(input) {
        var picker = input.parentNode ? input.parentNode.querySelector('.zbm-jalali-picker') : null;
        if (!picker) { return; }
        var selected = parseJalaliDate(input.value) || parseJalaliDate(input.getAttribute('data-jalali-min') || '');
        if (!selected) {
            var now = new Date();
            selected = gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
        }
        renderJalaliPicker(input, picker, selected.year, selected.month);
        setJalaliPickerState(input, picker, true);
    }

    function initJalaliDateFields(form) {
        form.querySelectorAll('.zbm-jalali-date').forEach(function (input) {
            if (input.getAttribute('data-zbm-jalali-ready') === '1') { return; }
            input.setAttribute('data-zbm-jalali-ready', '1');
            var picker = document.createElement('div');
            picker.className = 'zbm-jalali-picker';
            picker.hidden = true;
            picker.id = input.id + '-picker';
            picker.setAttribute('role', 'dialog');
            picker.setAttribute('aria-label', 'تقویم شمسی');
            input.parentNode.appendChild(picker);
            input.setAttribute('aria-controls', picker.id);
            setJalaliPickerState(input, picker, false);
            input.addEventListener('focus', function () {
                if (input.getAttribute('data-zbm-suppress-picker-open') === '1') {
                    input.removeAttribute('data-zbm-suppress-picker-open');
                    return;
                }
                openJalaliPicker(input);
            });
            input.addEventListener('beforeinput', function (event) {
                if (!event.data || (event.inputType && event.inputType.indexOf('delete') === 0)) { return; }
                var isPaste = event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop';
                var allowed = isPaste ? /^[0-9۰-۹٠-٩/\-\.\s]+$/ : /^[0-9۰-۹٠-٩]+$/;
                if (!allowed.test(event.data)) { event.preventDefault(); }
                var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
                var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
                var existingDigits = jalaliInputDigits(input.value).length;
                var selectedDigits = digitCountInRange(input.value, start, end);
                var incomingDigits = latinDigits(event.data).replace(/[^0-9]/g, '').length;
                if (existingDigits - selectedDigits + incomingDigits > 8) { event.preventDefault(); }
            });
            input.addEventListener('keydown', function (event) {
                if (isJalaliNavigationKey(event) || !event.key || event.key === 'Unidentified' || event.key === 'Process') { return; }
                if (/^[0-9۰-۹٠-٩]$/.test(event.key)) {
                    var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
                    var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
                    if (jalaliInputDigits(input.value).length - digitCountInRange(input.value, start, end) < 8) { return; }
                }
                event.preventDefault();
            });
            input.addEventListener('input', function () { normalizeJalaliDateInput(input); });
            input.addEventListener('blur', function () {
                normalizeJalaliDateInput(input);
                var parsed = parseJalaliDate(input.value);
                if (parsed) {
                    input.value = formatJalaliDate(parsed, true);
                    syncJalaliDateInput(input, true);
                }
                window.setTimeout(function () {
                    if (document.activeElement !== input && !picker.contains(document.activeElement)) {
                        setJalaliPickerState(input, picker, false);
                    }
                }, 0);
            });
            var trigger = input.parentNode.querySelector('.zbm-jalali-trigger');
            if (trigger) {
                trigger.addEventListener('click', function () {
                    if (picker.hidden) { input.focus(); openJalaliPicker(input); }
                    else { setJalaliPickerState(input, picker, false); }
                });
            }
            syncJalaliDateInput(input, false);
            document.addEventListener('click', function (event) {
                if (!input.parentNode.contains(event.target)) {
                    setJalaliPickerState(input, picker, false);
                }
            });
        });
    }

    function iranianTimeInputDigits(value) {
        return latinDigits(value).replace(/[^0-9]/g, '').slice(0, 4);
    }

    function formatIranianTimeValue(value) {
        var digits = iranianTimeInputDigits(value);
        var formatted = digits;
        if (digits.length > 2) {
            formatted = digits.slice(0, 2) + ':' + digits.slice(2, 4);
        }
        return persianDigits(formatted);
    }

    function parseIranianTime(value) {
        var normalized = latinDigits(value).trim();
        var match = normalized.match(/^(?:([01]\d|2[0-3]):([0-5]\d))$/);
        if (!match) { return null; }
        return { hour: Number(match[1]), minute: Number(match[2]) };
    }

    function normalizeIranianTimeInput(input) {
        var previous = String(input.value || '');
        var caret = typeof input.selectionStart === 'number' ? input.selectionStart : previous.length;
        var digitsBeforeCaret = digitCountBefore(previous, caret);
        var formatted = formatIranianTimeValue(previous);
        if (previous !== formatted) {
            input.value = formatted;
            if (document.activeElement === input && typeof input.setSelectionRange === 'function') {
                var nextCaret = caretForDigitCount(formatted, Math.min(digitsBeforeCaret, 4));
                input.setSelectionRange(nextCaret, nextCaret);
            }
        }
        var parsed = parseIranianTime(input.value);
        if (input.value.trim()) { input.setAttribute('aria-invalid', parsed ? 'false' : 'true'); }
        else { input.removeAttribute('aria-invalid'); }
        return parsed;
    }

    function initIranianTimeFields(form) {
        form.querySelectorAll('.zbm-iranian-time').forEach(function (input) {
            if (input.getAttribute('data-zbm-time-ready') === '1') { return; }
            input.setAttribute('data-zbm-time-ready', '1');
            input.addEventListener('beforeinput', function (event) {
                if (!event.data || (event.inputType && event.inputType.indexOf('delete') === 0)) { return; }
                var isPaste = event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop';
                var allowed = isPaste ? /^[0-9۰-۹٠-٩:]+$/ : /^[0-9۰-۹٠-٩]+$/;
                if (!allowed.test(event.data)) { event.preventDefault(); return; }
                var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
                var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
                var existingDigits = iranianTimeInputDigits(input.value).length;
                var selectedDigits = digitCountInRange(input.value, start, end);
                var incomingDigits = latinDigits(event.data).replace(/[^0-9]/g, '').length;
                if (existingDigits - selectedDigits + incomingDigits > 4) { event.preventDefault(); }
            });
            input.addEventListener('keydown', function (event) {
                if (isJalaliNavigationKey(event) || !event.key || event.key === 'Unidentified' || event.key === 'Process') { return; }
                if (/^[0-9۰-۹٠-٩]$/.test(event.key)) {
                    var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
                    var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
                    if (iranianTimeInputDigits(input.value).length - digitCountInRange(input.value, start, end) < 4) { return; }
                }
                event.preventDefault();
            });
            input.addEventListener('input', function () { normalizeIranianTimeInput(input); });
            input.addEventListener('blur', function () { normalizeIranianTimeInput(input); });
            normalizeIranianTimeInput(input);
        });
    }

    function formatNumber(value) {
        var number = Number(value);
        return Number.isFinite(number) ? number.toLocaleString('fa-IR') : '—';
    }

    function parsePriceToman(value) {
        var normalized = latinDigits(String(value || '')).replace(/[\s,٬]/g, '');
        if (!/^\d+(?:\.0+)?$/.test(normalized)) { return 0; }
        var number = Number(normalized.split('.')[0]);
        return Number.isSafeInteger(number) ? number : 0;
    }

    function checkedMultiply(left, right) {
        if (!Number.isSafeInteger(left) || !Number.isSafeInteger(right) || left < 1 || right < 1) { return 0; }
        if (left > Math.floor(Number.MAX_SAFE_INTEGER / right)) { return 0; }
        return left * right;
    }

    function formatMoney(value) {
        return value > 0 ? formatNumber(value) + ' تومان' : '—';
    }

    function formatShippingMoney(value) {
        return Number(value) === 0 ? 'رایگان' : formatMoney(value);
    }

    function formatShippingRate(value) {
        return Number(value) === 0 ? 'رایگان' : formatNumber(value) + ' تومان / کیلوگرم';
    }

    function safeIntegerValue(value) {
        if (value === null || typeof value === 'undefined' || value === '') { return null; }
        var number = Number(value);
        return Number.isSafeInteger(number) ? number : null;
    }

    function field(form, name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function withAjaxTimeout(requestPromise, cancel) {
        var timeoutId = null;
        var timeoutPromise = new Promise(function (resolve, reject) {
            timeoutId = window.setTimeout(function () {
                reject(new Error('ajax_timeout'));
                if (typeof cancel === 'function') {
                    try {
                        cancel();
                    } catch (error) {
                        // The timeout remains authoritative if transport cleanup fails.
                    }
                }
            }, AJAX_TIMEOUT_MS);
        });
        return Promise.race([requestPromise, timeoutPromise]).finally(function () {
            if (timeoutId !== null) { window.clearTimeout(timeoutId); }
        });
    }

    function ajaxResponseError(code, response) {
        var error = new Error(code);
        error.zbmResponse = response || null;
        return error;
    }

    function ajaxFailureMessage(error, fallback) {
        var response = error && error.zbmResponse;
        var data = response && response.data;
        var message = data && typeof data.message === 'string' ? data.message.trim() : '';
        if (!message || message.length > 300 || /[<>\u0000-\u001F\u007F]/.test(message)) {
            return fallback;
        }
        return message;
    }

    function ajaxJson(payload) {
        var config = window.zbmPurchaseRequestsConfig || {};
        if (!config.ajaxUrl) { return Promise.reject(new Error('ajax_unavailable')); }
        var body = new FormData();
        Object.keys(payload || {}).forEach(function (key) { body.append(key, payload[key] == null ? '' : String(payload[key])); });
        if (window.fetch) {
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var fetchOptions = {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            };
            if (controller) { fetchOptions.signal = controller.signal; }
            var fetchRequest = Promise.resolve().then(function () {
                return window.fetch(config.ajaxUrl, fetchOptions);
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (json) {
                    if (!response.ok) { throw ajaxResponseError('ajax_http_error', json); }
                    if (!json) { throw ajaxResponseError('ajax_json_invalid', null); }
                    return json;
                });
            });
            return withAjaxTimeout(fetchRequest, controller ? function () { controller.abort(); } : null);
        }
        var jqueryRequest = null;
        var jqueryPromise = new Promise(function (resolve, reject) {
            jqueryRequest = $.ajax({ url: config.ajaxUrl, method: 'POST', data: payload, dataType: 'json' });
            jqueryRequest.done(resolve).fail(function (xhr) {
                reject(ajaxResponseError('ajax_error', xhr && xhr.responseJSON ? xhr.responseJSON : null));
            });
        });
        return withAjaxTimeout(jqueryPromise, function () {
            if (jqueryRequest && typeof jqueryRequest.abort === 'function') { jqueryRequest.abort(); }
        });
    }

    function clearRequestBootstrap(form) {
        var nonce = field(form, '_zbm_nonce');
        var idempotencyKey = field(form, 'idempotency_key');
        if (nonce) { nonce.value = ''; }
        if (idempotencyKey) { idempotencyKey.value = ''; }
    }

    function liveAuthState(openButton, form) {
        var config = window.zbmPurchaseRequestsConfig || {};
        clearRequestBootstrap(form);
        if (!config.ajaxUrl || !config.authAction) {
            openButton.removeAttribute('data-zbm-authenticated');
            return Promise.resolve(null);
        }
        return ajaxJson({ action: config.authAction }).then(function (response) {
            if (!response || response.success !== true || !response.data || typeof response.data.authenticated !== 'boolean') {
                throw new Error('auth_response_invalid');
            }
            var authenticated = response.data.authenticated;
            if (authenticated) {
                var nonce = response.data.create_nonce;
                var idempotencyKey = response.data.idempotency_key;
                var nonceField = field(form, '_zbm_nonce');
                var idempotencyField = field(form, 'idempotency_key');
                if (
                    typeof nonce !== 'string' || nonce !== nonce.trim() || !nonce || nonce.length > 128 ||
                    typeof idempotencyKey !== 'string' || idempotencyKey !== idempotencyKey.trim() || idempotencyKey.length < 8 || idempotencyKey.length > 128 ||
                    !nonceField || !idempotencyField
                ) {
                    throw new Error('auth_bootstrap_invalid');
                }
                nonceField.value = nonce;
                idempotencyField.value = idempotencyKey;
            }
            openButton.setAttribute('data-zbm-authenticated', authenticated ? '1' : '0');
            return authenticated;
        }).catch(function () {
            clearRequestBootstrap(form);
            openButton.removeAttribute('data-zbm-authenticated');
            return null;
        });
    }

    function setAuthFeedback(box, message, focus) {
        var feedback = box.querySelector('[data-zbm-auth-feedback]');
        if (!feedback) { return; }
        feedback.textContent = message || '';
        feedback.hidden = !message;
        if (message && focus) { feedback.focus(); }
    }

    function setFeedback(form, message) {
        var feedback = form.closest('.zbm-modal-dialog').querySelector('.zbm-form-feedback');
        feedback.textContent = message || '';
        feedback.hidden = !message;
    }

    function clearFeedback(form) {
        setFeedback(form, '');
    }

    function provinceMessage(data, fallback) {
        var message = data && typeof data.province_message === 'string' ? data.province_message.trim() : '';
        return message || fallback;
    }

    function ensureProvinceSupportStatus(form) {
        var existing = form.querySelector('[data-zbm-province-support]');
        if (existing) { return existing; }
        var province = field(form, 'province_code');
        if (!province || !province.parentNode) { return null; }
        var provinceField = typeof province.closest === 'function' ? province.closest('.zbm-field') : province.parentNode;
        if (!provinceField || !provinceField.parentNode) { return null; }

        var status = document.createElement('div');
        var describedBy = province.getAttribute('aria-describedby');
        status.id = describedBy || ((province.id || 'zbm-province') + '-status');
        status.className = 'zbm-province-support';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('data-state', 'idle');
        status.setAttribute('data-zbm-province-support', '');

        var icon = document.createElement('span');
        icon.className = 'zbm-province-support-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = 'i';
        status.appendChild(icon);

        var message = document.createElement('span');
        message.setAttribute('data-zbm-province-support-message', '');
        message.textContent = 'برای بررسی امکان ارسال و برآورد هزینه، استان مقصد را انتخاب کنید.';
        status.appendChild(message);

        provinceField.parentNode.insertBefore(status, provinceField.nextSibling);
        province.setAttribute('aria-describedby', status.id);
        return status;
    }

    function setProvinceSupportStatus(form, state, message) {
        var status = ensureProvinceSupportStatus(form);
        if (!status) { return; }
        var allowedStates = ['idle', 'checking', 'supported', 'unsupported', 'error'];
        var safeState = allowedStates.indexOf(state) !== -1 ? state : 'idle';
        var icons = { idle: 'i', checking: '…', supported: '✓', unsupported: '!', error: '!' };
        var icon = status.querySelector('.zbm-province-support-icon');
        var text = status.querySelector('[data-zbm-province-support-message]');
        var province = field(form, 'province_code');
        status.setAttribute('data-state', safeState);
        status.setAttribute('aria-busy', safeState === 'checking' ? 'true' : 'false');
        if (icon) { icon.textContent = icons[safeState]; }
        if (text) { text.textContent = message || ''; }
        if (province) {
            if (safeState === 'unsupported' || safeState === 'error') { province.setAttribute('aria-invalid', 'true'); }
            else if (safeState === 'supported') { province.setAttribute('aria-invalid', 'false'); }
            else { province.removeAttribute('aria-invalid'); }
        }
    }

    function validProductQuote(data) {
        var weight = safeIntegerValue(data.reference_weight_tenths);
        var product = safeIntegerValue(data.estimated_product_toman);
        var deposit = safeIntegerValue(data.deposit_toman);
        var balance = safeIntegerValue(data.estimated_balance_toman);
        var gross = safeIntegerValue(data.estimated_gross_toman);
        return weight !== null && weight > 0 && product !== null && product > 0 &&
            deposit !== null && deposit > 0 && balance !== null && balance >= 0 &&
            gross !== null && gross >= product && deposit <= gross && balance === gross - deposit &&
            data.pricing_version === 2 && data.shipping_is_outside_deposit === false &&
            (data.province_supported === true ? gross === safeIntegerValue(data.estimated_total_with_shipping) : gross === product);
    }

    function validSupportedQuote(data, requestedProvince) {
        var rate = safeIntegerValue(data.shipping_rate_per_kg_toman);
        var shipping = safeIntegerValue(data.estimated_shipping_toman);
        var total = safeIntegerValue(data.estimated_total_with_shipping);
        var product = safeIntegerValue(data.estimated_product_toman);
        var version = typeof data.shipping_rate_version === 'string' ? data.shipping_rate_version.trim() : '';
        return String(data.province_code || '') === requestedProvince &&
            typeof data.province_name === 'string' && data.province_name.trim() !== '' &&
            rate !== null && rate >= 0 && shipping !== null && shipping >= 0 &&
            total !== null && product !== null && product <= Number.MAX_SAFE_INTEGER - shipping &&
            total === product + shipping && /^[a-f0-9]{64}$/i.test(version);
    }

    function validSupportedProvinces(data) {
        if (!data || !Array.isArray(data.supported_provinces)) { return null; }
        var codes = [];
        var supported = [];
        for (var index = 0; index < data.supported_provinces.length; index += 1) {
            var province = data.supported_provinces[index];
            if (!province || typeof province !== 'object' || Array.isArray(province) ||
                Object.keys(province).length !== 2 || !Object.prototype.hasOwnProperty.call(province, 'code') ||
                !Object.prototype.hasOwnProperty.call(province, 'name') || typeof province.code !== 'string' ||
                typeof province.name !== 'string') {
                return null;
            }
            var code = province.code.trim();
            var name = province.name.trim();
            if (!/^[a-z0-9_-]+$/i.test(code) || !name || codes.indexOf(code) !== -1) { return null; }
            codes.push(code);
            supported.push({ code: code, name: name });
        }
        return supported;
    }

    function supportedProvinceMessage(data) {
        var message = data && typeof data.supported_province_message === 'string' ? data.supported_province_message.trim() : '';
        return message || 'این فروشنده به استان‌های زیر ارسال دارد؛ استان مقصدتان را از فهرست انتخاب کنید.';
    }

    function syncDeliveryAvailability(form, supportedProvinces) {
        var delivery = form.querySelector('input[name="delivery_method"][value="delivery_to_buyer_address"]');
        if (!delivery) { return; }
        var deliveryCard = typeof delivery.closest === 'function' ? delivery.closest('label') : null;
        var hasSupportedProvinces = supportedProvinces.length > 0;
        delivery.disabled = !hasSupportedProvinces;
        if (deliveryCard) {
            deliveryCard.classList.toggle('is-disabled', !hasSupportedProvinces);
            deliveryCard.setAttribute('aria-disabled', hasSupportedProvinces ? 'false' : 'true');
            // Cached templates may not yet include the dedicated availability hook.
            var availability = deliveryCard.querySelector('[data-zbm-delivery-availability]') || deliveryCard.querySelector('.zbm-choice-unavailable');
            if (!availability) {
                availability = document.createElement('small');
                deliveryCard.appendChild(availability);
            }
            availability.setAttribute('data-zbm-delivery-availability', '');
            availability.className = 'zbm-choice-unavailable zbm-supported-provinces--empty';
            availability.textContent = hasSupportedProvinces ? '' : 'موقتاً غیرفعال؛ فروشنده برای هیچ استانی نرخ حمل ثبت نکرده است.';
            availability.hidden = hasSupportedProvinces;
        }
        var pickup = form.querySelector('input[name="delivery_method"][value="pickup_from_vendor"]');
        if (hasSupportedProvinces) {
            if (!pickup || pickup.disabled || !pickup.checked) { delivery.checked = true; }
        } else {
            delivery.checked = false;
            if (pickup && !pickup.disabled) { pickup.checked = true; }
        }
        syncConditionalFields(form);
    }

    function syncSupportedProvinceHelp(form, supportedProvinces, message) {
        var help = form.querySelector('[data-zbm-supported-provinces]');
        if (!help) {
            var province = field(form, 'province_code');
            var provinceField = province ? province.closest('.zbm-field') : null;
            if (!provinceField || !provinceField.parentNode) { return; }
            help = document.createElement('div');
            help.setAttribute('data-zbm-supported-provinces', '');
            provinceField.parentNode.insertBefore(help, provinceField.nextSibling);
        }
        help.className = 'zbm-supported-provinces';
        while (help.firstChild) { help.removeChild(help.firstChild); }
        var heading = document.createElement('strong');
        heading.textContent = message;
        help.appendChild(heading);
        var list = document.createElement('ul');
        supportedProvinces.forEach(function (province) {
            var item = document.createElement('li');
            item.textContent = province.name;
            list.appendChild(item);
        });
        help.appendChild(list);
    }

    function syncSupportedProvinceOptions(form, modal, supportedProvinces, message) {
        syncDeliveryAvailability(form, supportedProvinces);
        syncSupportedProvinceHelp(form, supportedProvinces, message);
        var province = field(form, 'province_code');
        if (!province) { return false; }
        var selected = String(province.value || '');
        var provinceSupportedCodes = supportedProvinces.map(function (item) { return item.code; });
        province.options.length = 0;
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'استان را انتخاب کنید';
        province.appendChild(placeholder);
        supportedProvinces.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.code;
            option.textContent = item.name;
            province.appendChild(option);
        });
        modal._zbmSupportedProvinceCodes = provinceSupportedCodes;
        modal._zbmSupportedProvinceMessage = message;
        if (selected && provinceSupportedCodes.indexOf(selected) === -1) {
            province.value = '';
            return true;
        }
        province.value = selected;
        return false;
    }

    function normalizePricingResponse(data, requestedProvince) {
        if (!data || typeof data !== 'object' || !validProductQuote(data)) { return null; }
        var requested = String(requestedProvince || '');
        var normalized = Object.assign({}, data);
        var supportedProvinces = null;
        if (Object.prototype.hasOwnProperty.call(normalized, 'supported_provinces')) {
            supportedProvinces = validSupportedProvinces(normalized);
            if (!supportedProvinces || typeof normalized.supported_province_message !== 'string') { return null; }
            normalized.supported_provinces = supportedProvinces;
        }
        if (!requested) {
            if (typeof normalized.province_supported !== 'undefined' && normalized.province_supported !== null) { return null; }
            normalized.province_supported = null;
            return normalized;
        }
        if (normalized.province_supported === false) {
            return String(normalized.province_code || '') === requested &&
                typeof normalized.province_name === 'string' && normalized.province_name.trim() !== '' ? normalized : null;
        }
        if (normalized.province_supported === true) {
            return validSupportedQuote(normalized, requested) && (!supportedProvinces || supportedProvinces.some(function (province) {
                return province.code === requested;
            })) ? normalized : null;
        }
        if (typeof normalized.province_supported === 'undefined' && validSupportedQuote(normalized, requested)) {
            normalized.province_supported = true;
            return normalized;
        }
        return null;
    }

    function syncConditionalFields(form) {
        var method = form.querySelector('input[name="delivery_method"]:checked');
        var isDelivery = method && method.value === 'delivery_to_buyer_address';
        var isPickup = method && method.value === 'pickup_from_vendor';
        var address = form.querySelector('.zbm-address-fields');
        var pickupFields = form.querySelector('.zbm-pickup-fields');
        if (address) {
            address.hidden = !isDelivery;
            address.querySelectorAll('input,textarea,select').forEach(function (input) {
                input.required = !!isDelivery;
                input.disabled = !isDelivery;
                input.setAttribute('aria-required', isDelivery ? 'true' : 'false');
            });
        }
        if (pickupFields) {
            pickupFields.hidden = !isPickup;
            pickupFields.setAttribute('aria-hidden', isPickup ? 'false' : 'true');
            pickupFields.querySelectorAll('input,textarea').forEach(function (input) {
                var isConfirmation = input.name === 'pickup_visit_confirmed';
                input.disabled = !isPickup;
                input.required = !!isPickup && isConfirmation;
                input.setAttribute('aria-required', isPickup && isConfirmation ? 'true' : 'false');
            });
        }

        var windowType = form.querySelector('input[name="delivery_window_type"]:checked');
        var isExact = !windowType || windowType.value === 'exact';
        var exact = form.querySelector('.zbm-exact-window');
        var flexible = form.querySelector('.zbm-flex-window');
        if (exact) {
            exact.hidden = !isExact;
            exact.setAttribute('aria-hidden', isExact ? 'false' : 'true');
            exact.classList.toggle('is-inactive', !isExact);
            exact.querySelectorAll('input').forEach(function (input) {
                input.required = input.type !== 'hidden' && isExact;
                input.disabled = !isExact;
                if (input.type !== 'hidden') { input.setAttribute('aria-required', isExact ? 'true' : 'false'); }
            });
        }
        if (flexible) {
            flexible.hidden = isExact;
            flexible.setAttribute('aria-hidden', isExact ? 'true' : 'false');
            flexible.classList.toggle('is-inactive', isExact);
            flexible.querySelectorAll('input').forEach(function (input) {
                input.required = input.type !== 'hidden' && !isExact;
                input.disabled = isExact;
                if (input.type !== 'hidden') { input.setAttribute('aria-required', isExact ? 'false' : 'true'); }
            });
        }
    }

    function updateSummary(form, modal) {
        var quantity = parseInt(latinDigits(field(form, 'quantity') ? field(form, 'quantity').value : '1'), 10) || 0;
        var weight = parseFloat(latinDigits(modal.getAttribute('data-package-weight') || ''));
        var totalWeight = weight > 0 && quantity > 0 ? weight * quantity : 0;
        var packagePrice = parsePriceToman(modal.getAttribute('data-product-price') || '');
        var estimatedTotal = checkedMultiply(packagePrice, quantity);
        var quote = modal._zbmQuote || null;
        if (quote && Number.isSafeInteger(quote.reference_weight_tenths)) { totalWeight = quote.reference_weight_tenths / 10; }
        if (quote && Number.isSafeInteger(quote.total_quantity)) { quantity = quote.total_quantity; }
        var quotePending = modal._zbmQuotePending === true;
        modal.querySelectorAll('[data-zbm-review-items]').forEach(function (node) {
            node.replaceChildren();
            if (quotePending) { node.textContent = 'در حال به‌روزرسانی اقلام و مبلغ…'; return; }
            if (!quote || !Array.isArray(quote.lines)) { node.textContent = 'برای تأیید اقلام، منتظر محاسبهٔ مبلغ بمانید.'; return; }
            quote.lines.forEach(function (item) {
                var row = document.createElement('p');
                row.textContent = item.name + ' · ' + formatNumber(item.quantity) + ' بسته · ' + formatNumber(item.reference_tenths / 10) + ' کیلوگرم · ' + formatMoney(item.estimated_total);
                node.appendChild(row);
            });
        });
        var productQuote = quote ? safeIntegerValue(quote.estimated_product_toman) : null;
        var productTotal = null === productQuote ? estimatedTotal : productQuote;
        var provinceSupported = quote && typeof quote.province_supported === 'boolean' ? quote.province_supported : null;
        var shippingRate = quote ? safeIntegerValue(quote.shipping_rate_per_kg_toman) : null;
        var shippingTotal = quote ? safeIntegerValue(quote.estimated_shipping_toman) : null;
        var totalWithShipping = quote ? safeIntegerValue(quote.estimated_total_with_shipping) : null;
        var deposit = quote ? safeIntegerValue(quote.deposit_toman) : null;
        var balance = quote ? safeIntegerValue(quote.estimated_balance_toman) : null;
        var selectedMethod = form.querySelector('input[name="delivery_method"]:checked');
        var isPickup = selectedMethod && selectedMethod.value === 'pickup_from_vendor';
        if (!isPickup && provinceSupported !== true) {
            deposit = null;
            balance = null;
        }
        if (isPickup) { totalWithShipping = productTotal; }
        modal.querySelectorAll('[data-zbm-summary="weight"],[data-zbm-summary="final-weight"]').forEach(function (node) {
            node.textContent = totalWeight > 0 ? formatNumber(totalWeight) + ' کیلوگرم' : '—';
        });
        modal.querySelectorAll('[data-zbm-summary="final-quantity"]').forEach(function (node) {
            node.textContent = quantity > 0 ? formatNumber(quantity) : '—';
        });
        modal.querySelectorAll('[data-zbm-summary="package-price"]').forEach(function (node) {
            node.textContent = quote && quote.product_count > 1 ? 'مطابق ردیف محصولات' : formatMoney(packagePrice);
        });
        modal.querySelectorAll('[data-zbm-summary="estimated-total"]').forEach(function (node) {
            node.textContent = formatMoney(productTotal);
        });
        modal.querySelectorAll('[data-zbm-summary="shipping-rate"]').forEach(function (node) {
            node.textContent = null === shippingRate
                ? (isPickup ? 'دریافت حضوری' : (quotePending ? 'در حال بررسی…' : (provinceSupported === false ? 'ارسال فعال نیست' : 'استان را انتخاب کنید')))
                : formatShippingRate(shippingRate);
        });
        modal.querySelectorAll('[data-zbm-summary="shipping"]').forEach(function (node) {
            node.textContent = null === shippingTotal
                ? (isPickup ? 'دریافت حضوری' : (quotePending ? 'در حال بررسی…' : (provinceSupported === false ? 'برای این استان ارائه نمی‌شود' : 'استان را انتخاب کنید')))
                : formatShippingMoney(shippingTotal);
        });
        modal.querySelectorAll('[data-zbm-summary="total-with-shipping"]').forEach(function (node) {
            node.textContent = null === totalWithShipping ? '—' : formatMoney(totalWithShipping);
        });
        modal.querySelectorAll('[data-zbm-summary="deposit"]').forEach(function (node) {
            node.textContent = null === deposit ? '—' : formatMoney(deposit);
        });
        modal.querySelectorAll('[data-zbm-summary="balance"]').forEach(function (node) {
            node.textContent = null === balance ? '—' : formatMoney(balance);
        });
        var method = form.querySelector('input[name="delivery_method"]:checked');
        var methodLabel = method ? method.closest('label').querySelector('strong') : null;
        modal.querySelectorAll('[data-zbm-summary="method"]').forEach(function (node) {
            node.textContent = methodLabel ? methodLabel.textContent.trim() : '—';
        });
        var windowType = form.querySelector('input[name="delivery_window_type"]:checked');
        var windowText = '—';
        if (windowType && windowType.value === 'exact') {
            var exactDate = field(form, 'exact_date_display');
            var startTime = field(form, 'start_time');
            var endTime = field(form, 'end_time');
            if (exactDate && exactDate.value && startTime && startTime.value && endTime && endTime.value) {
                windowText = displayJalaliValue(exactDate.value) + '، ' + formatIranianTimeValue(startTime.value) + ' تا ' + formatIranianTimeValue(endTime.value);
            }
        } else if (windowType && windowType.value === 'flexible') {
            var flexStart = field(form, 'flex_start_date_display');
            var flexEnd = field(form, 'flex_end_date_display');
            if (flexStart && flexStart.value && flexEnd && flexEnd.value) {
                windowText = displayJalaliValue(flexStart.value) + ' تا ' + displayJalaliValue(flexEnd.value);
            }
        }
        modal.querySelectorAll('[data-zbm-summary="window"]').forEach(function (node) {
            node.textContent = windowText;
        });
        var pickupAddress = form.querySelector('[data-zbm-pickup-address]');
        var pickupAddressText = pickupAddress ? String(pickupAddress.getAttribute('data-zbm-address-text') || '').trim() : '';
        var pickupNote = field(form, 'pickup_note');
        modal.querySelectorAll('[data-zbm-summary="pickup-address"]').forEach(function (node) {
            node.textContent = isPickup ? (pickupAddressText || 'نشانی فروشنده در دسترس نیست') : '—';
        });
        modal.querySelectorAll('[data-zbm-summary="pickup-note"]').forEach(function (node) {
            var note = pickupNote ? pickupNote.value.trim() : '';
            node.textContent = isPickup ? (note || 'بدون توضیح') : '—';
        });
    }

    function refreshPricingPreview(form, modal) {
        if (modal.hidden) { return; }
        var method = form.querySelector('input[name="delivery_method"]:checked');
        var isDelivery = method && method.value === 'delivery_to_buyer_address';
        var province = field(form, 'province_code');
        var quantity = field(form, 'quantity');
        var variation = field(form, 'variation_id');
        var config = window.zbmPurchaseRequestsConfig || {};
        var requestId = (modal._zbmQuoteRequestId || 0) + 1;
        var requestedProvince = isDelivery && province ? province.value : '';
        modal._zbmQuoteRequestId = requestId;
        modal.removeAttribute('data-zbm-quote-error');
        if (!quantity || !config.pricingAction || !modal.getAttribute('data-product-id')) {
            modal._zbmQuote = null;
            modal._zbmQuotePending = false;
            if (isDelivery) {
                setProvinceSupportStatus(form, 'error', 'بررسی امکان ارسال فعلاً در دسترس نیست. لطفاً دوباره تلاش کنید.');
            }
            updateSummary(form, modal);
            return;
        }

        modal._zbmQuote = null;
        modal._zbmQuotePending = true;
        if (isDelivery && province && province.value) {
            setProvinceSupportStatus(form, 'checking', 'در حال بررسی امکان ارسال و محاسبهٔ هزینه…');
        } else {
            setProvinceSupportStatus(form, 'idle', 'برای بررسی امکان ارسال و برآورد هزینه، استان مقصد را انتخاب کنید.');
        }
        updateSummary(form, modal);
        ajaxJson({
            action: config.pricingAction,
            product_id: modal.getAttribute('data-product-id') || '',
            variation_id: variation ? variation.value : '0',
            quantity: latinDigits(quantity.value || ''),
            lines_json: field(form, 'lines_json') ? field(form, 'lines_json').value : '',
            province_code: requestedProvince
        }).then(function (response) {
            if (requestId !== modal._zbmQuoteRequestId) { return; }
            if (!response || response.success !== true || !response.data) {
                throw ajaxResponseError('pricing_unavailable', response);
            }
            var quote = normalizePricingResponse(response.data, requestedProvince);
            if (!quote) { throw ajaxResponseError('pricing_response_invalid', response); }
            var selectionRemoved = Array.isArray(quote.supported_provinces) && syncSupportedProvinceOptions(
                form,
                modal,
                quote.supported_provinces,
                supportedProvinceMessage(quote)
            );
            var shippingCleared = selectionRemoved || (Array.isArray(quote.supported_provinces) && quote.supported_provinces.length === 0);
            if (shippingCleared) {
                // A withdrawn shipping option must not retain a payable quote.
                quote.province_code = '';
                quote.province_name = '';
                quote.province_supported = null;
                quote.shipping_rate_per_kg_toman = null;
                quote.shipping_rate_version = null;
                quote.estimated_shipping_toman = null;
                quote.estimated_total_with_shipping = null;
                if (isDelivery) {
                    quote.deposit_toman = null;
                    quote.estimated_balance_toman = null;
                }
            }
            modal._zbmQuote = quote;
            modal._zbmQuotePending = false;
            modal.removeAttribute('data-zbm-quote-error');
            if (shippingCleared) {
                setProvinceSupportStatus(form, 'unsupported', supportedProvinceMessage(quote));
            } else if (isDelivery && province && province.value) {
                if (quote.province_supported === true) {
                    setProvinceSupportStatus(form, 'supported', provinceMessage(quote, 'ارسال به استان انتخاب‌شده فعال است.'));
                } else if (quote.province_supported === false) {
                    setProvinceSupportStatus(form, 'unsupported', provinceMessage(quote, 'فروشنده برای استان انتخاب‌شده ارسال فعال ندارد.'));
                } else {
                    setProvinceSupportStatus(form, 'error', 'پاسخ بررسی استان کامل نبود؛ لطفاً استان را دوباره انتخاب کنید.');
                }
            } else {
                setProvinceSupportStatus(form, 'idle', 'برای بررسی امکان ارسال و برآورد هزینه، استان مقصد را انتخاب کنید.');
            }
            updateSummary(form, modal);
        }).catch(function (error) {
            if (requestId !== modal._zbmQuoteRequestId) { return; }
            modal._zbmQuote = null;
            modal._zbmQuotePending = false;
            modal.setAttribute('data-zbm-quote-error', '1');
            if (isDelivery && province && province.value) {
                setProvinceSupportStatus(
                    form,
                    'error',
                    ajaxFailureMessage(error, 'بررسی امکان ارسال انجام نشد. اتصال را بررسی کنید و استان را دوباره انتخاب کنید.')
                );
            }
            updateSummary(form, modal);
        });
    }

    function firstFocusable(dialog) {
        return dialog.querySelector('input:not([disabled]),textarea:not([disabled]),select:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])');
    }

    function validationError(form, step) {
        var quantity = field(form, 'quantity');
        if (step === 1) {
            var rawAmount = latinDigits(quantity ? quantity.value : '').trim();
            var amount = Number(rawAmount);
            if (!quantity || !/^[1-9][0-9]*$/.test(rawAmount) || !Number.isSafeInteger(amount) || amount < 1) {
                return { field: quantity, message: 'لطفاً تعداد بسته را به‌صورت عدد صحیح و حداقل ۱ وارد کنید.' };
            }
            var invalidLine = Array.prototype.find.call(form.querySelectorAll('[data-zbm-selected-lines] input'), function (input) {
                return !/^[1-9][0-9]*$/.test(input.value) || !Number.isSafeInteger(Number(input.value));
            });
            if (invalidLine) { return {field: invalidLine, message: 'تعداد تمام محصولات باید عدد صحیح مثبت باشد.'}; }
            var pricingModal = form.closest('.zbm-modal');
            if (pricingModal && (pricingModal._zbmQuotePending || !pricingModal._zbmQuote)) {
                return {field: quantity, message: 'محاسبهٔ مبلغ هنوز تأیید نشده است؛ تعداد یا مشخصات محصول را بررسی کنید و دوباره تلاش کنید.'};
            }
        }
        if (step === 2 && !form.querySelector('input[name="delivery_method"]:checked')) {
            return { field: form.querySelector('input[name="delivery_method"]'), message: 'لطفاً روش دریافت را انتخاب کنید.' };
        }
        if (step === 3) {
            var recipient = field(form, 'recipient_name');
            if (!recipient || recipient.value.trim().length < 2) {
                return { field: recipient, message: 'نام و نام خانوادگی دریافت‌کننده را وارد کنید.' };
            }
            var method = form.querySelector('input[name="delivery_method"]:checked');
            if (method && method.value === 'delivery_to_buyer_address') {
                var province = field(form, 'province_code');
                if (!province || !province.value) {
                    return { field: province, message: 'لطفاً استان مقصد را انتخاب کنید.' };
                }
                var requestModal = form.closest('.zbm-modal');
                var provinceSupportedCodes = requestModal && Array.isArray(requestModal._zbmSupportedProvinceCodes)
                    ? requestModal._zbmSupportedProvinceCodes : null;
                if (provinceSupportedCodes && provinceSupportedCodes.indexOf(province.value) === -1) {
                    return { field: province, message: requestModal && requestModal._zbmSupportedProvinceMessage ? requestModal._zbmSupportedProvinceMessage : 'استان مقصد را از فهرست استان‌های پشتیبانی‌شده انتخاب کنید.' };
                }
                var postal = field(form, 'postal_code');
                var postalValue = latinDigits(postal ? postal.value : '').replace(/[^0-9]/g, '');
                if (!postal || postalValue.length !== 10) {
                    return { field: postal, message: 'کدپستی باید دقیقاً ۱۰ رقم باشد.' };
                }
                var address = field(form, 'address');
                if (!address || address.value.trim().length < 10) {
                    return { field: address, message: 'نشانی کامل تحویل را وارد کنید.' };
                }
                if (requestModal && requestModal._zbmQuotePending) {
                    return { field: province, message: 'بررسی امکان ارسال هنوز کامل نشده است؛ چند لحظه صبر کنید.' };
                }
                var quote = requestModal ? requestModal._zbmQuote : null;
                if (!quote) {
                    return { field: province, message: 'نرخ حمل این استان هنوز از سرور دریافت نشده است؛ استان را دوباره انتخاب کنید.' };
                }
                if (quote.province_supported === false) {
                    return { field: province, message: provinceMessage(quote, 'فروشنده برای استان انتخاب‌شده ارسال فعال ندارد.') };
                }
                if (quote.province_supported !== true || String(quote.province_code || '') !== province.value) {
                    return { field: province, message: 'وضعیت ارسال این استان تأیید نشده است؛ استان را دوباره انتخاب کنید.' };
                }
            } else if (method && method.value === 'pickup_from_vendor') {
                var pickupNote = field(form, 'pickup_note');
                if (pickupNote && pickupNote.value.trim().length > 500) {
                    return { field: pickupNote, message: 'توضیحات مراجعه نباید بیشتر از ۵۰۰ نویسه باشد.' };
                }
                var pickupConfirmation = field(form, 'pickup_visit_confirmed');
                if (!pickupConfirmation || !pickupConfirmation.checked) {
                    return { field: pickupConfirmation, message: 'برای دریافت حضوری، بررسی نشانی و توانایی مراجعه را تأیید کنید.' };
                }
            }
        }
        if (step === 4) {
            var selectedWindow = form.querySelector('input[name="delivery_window_type"]:checked');
            if (!selectedWindow) {
                return { field: form.querySelector('input[name="delivery_window_type"]'), message: 'لطفاً نوع بازه زمانی را انتخاب کنید.' };
            }
            if (selectedWindow.value === 'exact') {
                var exactDate = field(form, 'exact_date_display');
                var exactDateCanonical = field(form, 'exact_date');
                var startTime = field(form, 'start_time');
                var endTime = field(form, 'end_time');
                var exactIso = exactDate ? syncJalaliDateInput(exactDate, false) : '';
                if (!exactDate || !exactDate.value || !exactDateCanonical || !exactIso || !startTime || !startTime.value || !endTime || !endTime.value) {
                    return { field: exactDate || startTime || endTime, message: 'تاریخ و هر دو ساعت شروع و پایان را وارد کنید.' };
                }
                var startParsed = normalizeIranianTimeInput(startTime);
                var endParsed = normalizeIranianTimeInput(endTime);
                if (!startParsed || !endParsed) {
                    return { field: !startParsed ? startTime : endTime, message: 'ساعت را به‌صورت ۲۴ساعته و مانند ۱۴:۳۰ وارد کنید.' };
                }
                var startMinutes = startParsed.hour * 60 + startParsed.minute;
                var endMinutes = endParsed.hour * 60 + endParsed.minute;
                if (startMinutes >= endMinutes) {
                    return { field: endTime, message: 'ساعت پایان باید بعد از ساعت شروع باشد.' };
                }
                if ((exactDate.getAttribute('data-iso-min') && exactIso < exactDate.getAttribute('data-iso-min')) || (exactDate.getAttribute('data-iso-max') && exactIso > exactDate.getAttribute('data-iso-max'))) {
                    return { field: exactDate, message: 'تاریخ باید از فردا تا حداکثر ۳۰ روز آینده باشد.' };
                }
            } else {
                var flexStart = field(form, 'flex_start_date_display');
                var flexEnd = field(form, 'flex_end_date_display');
                var flexStartCanonical = field(form, 'flex_start_date');
                var flexEndCanonical = field(form, 'flex_end_date');
                var flexStartIso = flexStart ? syncJalaliDateInput(flexStart, false) : '';
                var flexEndIso = flexEnd ? syncJalaliDateInput(flexEnd, false) : '';
                if (!flexStart || !flexStart.value || !flexStartCanonical || !flexStartIso || !flexEnd || !flexEnd.value || !flexEndCanonical || !flexEndIso) {
                    return { field: flexStart || flexEnd, message: 'ابتدا تاریخ شروع و پایان بازه را وارد کنید.' };
                }
                if (flexStartIso > flexEndIso) {
                    return { field: flexEnd, message: 'تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.' };
                }
                if ((flexStart.getAttribute('data-iso-min') && flexStartIso < flexStart.getAttribute('data-iso-min')) || (flexEnd.getAttribute('data-iso-max') && flexEndIso > flexEnd.getAttribute('data-iso-max'))) {
                    return { field: flexStart, message: 'بازه باید از فردا تا حداکثر ۳۰ روز آینده باشد.' };
                }
            }
        }
        return null;
    }

    function validateStep(form, step, focusError) {
        var error = validationError(form, step);
        if (!error) { return true; }
        setFeedback(form, error.message);
        if (focusError && error.field && !error.field.disabled) {
            error.field.focus();
        }
        return false;
    }

    function initRequestModal(box) {
        var modal = box.querySelector('.zbm-modal');
        var form = box.querySelector('.zbm-request-form');
        var openButton = box.querySelector('.zbm-open-request');
        var dialog = modal ? modal.querySelector('.zbm-modal-dialog') : null;
        if (!modal || !form || !openButton || !dialog) { return; }
        var steps = Array.prototype.slice.call(modal.querySelectorAll('[data-zbm-step]'));
        var progress = Array.prototype.slice.call(modal.querySelectorAll('[data-zbm-progress]'));
        var nextButton = modal.querySelector('[data-zbm-next]');
        var backButton = modal.querySelector('[data-zbm-back]');
        var currentStep = 1;
        var previousFocus = null;
        var isSubmitting = false;
        var authPending = false;
        var quoteDebounceId = null;
        var footer = modal.querySelector('.zbm-modal-footer');
        initJalaliDateFields(form);
        initIranianTimeFields(form);
        form.querySelectorAll('input[name="delivery_window_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                syncConditionalFields(form);
                updateSummary(form, modal);
            });
        });

        function setStep(step) {
            currentStep = Math.max(1, Math.min(4, step));
            steps.forEach(function (node) {
                var active = Number(node.getAttribute('data-zbm-step')) === currentStep;
                node.hidden = !active;
                node.classList.toggle('is-active', active);
                node.setAttribute('aria-hidden', active ? 'false' : 'true');
            });
            progress.forEach(function (node) {
                var number = Number(node.getAttribute('data-zbm-progress'));
                node.classList.toggle('is-active', number === currentStep);
                node.classList.toggle('is-complete', number < currentStep);
            });
            backButton.hidden = currentStep === 1;
            if (footer) { footer.classList.toggle('is-first-step', currentStep === 1); }
            nextButton.hidden = false;
            nextButton.textContent = currentStep === 4
                ? nextButton.getAttribute('data-final-label')
                : nextButton.getAttribute('data-next-label');
            syncConditionalFields(form);
            updateSummary(form, modal);
            clearFeedback(form);
            var activeHeading = modal.querySelector('[data-zbm-step="' + currentStep + '"] h3');
            if (activeHeading) {
                activeHeading.setAttribute('tabindex', '-1');
                activeHeading.focus();
            }
        }

        function open() {
            previousFocus = document.activeElement;
            modal.hidden = false;
            document.body.classList.add('zbm-modal-open');
            setStep(1);
            refreshPricingNow();
            window.setTimeout(function () { dialog.focus(); }, 0);
        }

        function refreshPricingNow() {
            if (quoteDebounceId !== null) {
                window.clearTimeout(quoteDebounceId);
                quoteDebounceId = null;
            }
            refreshPricingPreview(form, modal);
        }

        function scheduleQuantityPricingPreview() {
            if (modal.hidden) { return; }
            if (quoteDebounceId !== null) { window.clearTimeout(quoteDebounceId); }
            modal._zbmQuoteRequestId = (modal._zbmQuoteRequestId || 0) + 1;
            modal._zbmQuote = null;
            modal._zbmQuotePending = true;
            modal.removeAttribute('data-zbm-quote-error');
            var method = form.querySelector('input[name="delivery_method"]:checked');
            var province = field(form, 'province_code');
            if (method && method.value === 'delivery_to_buyer_address' && province && province.value) {
                setProvinceSupportStatus(form, 'checking', 'در حال به‌روزرسانی برآورد برای تعداد جدید…');
            }
            updateSummary(form, modal);
            quoteDebounceId = window.setTimeout(function () {
                quoteDebounceId = null;
                refreshPricingPreview(form, modal);
            }, QUOTE_DEBOUNCE_MS);
        }

        modal._zbmRefreshPricingNow = refreshPricingNow;

        function variationIsReady() {
            var variation = form.querySelector('.zbm-variation-id');
            return !variation || variation.value !== '0' || !document.querySelector('form.variations_form');
        }

        function openAfterVariationCheck(showAlert) {
            if (!variationIsReady()) {
                if (showAlert) { window.alert('لطفاً ابتدا مشخصات محصول را انتخاب کنید.'); }
                return false;
            }
            open();
            return true;
        }

        function resetSubmissionState() {
            isSubmitting = false;
            nextButton.disabled = false;
            nextButton.removeAttribute('aria-busy');
            nextButton.textContent = currentStep === 4
                ? nextButton.getAttribute('data-final-label')
                : nextButton.getAttribute('data-next-label');
        }

        function close() {
            if (quoteDebounceId !== null) {
                window.clearTimeout(quoteDebounceId);
                quoteDebounceId = null;
            }
            modal._zbmQuoteRequestId = (modal._zbmQuoteRequestId || 0) + 1;
            modal._zbmQuotePending = false;
            modal.hidden = true;
            document.body.classList.remove('zbm-modal-open');
            if (previousFocus && typeof previousFocus.focus === 'function') { previousFocus.focus(); }
        }

        function bootstrapAndOpen(redirectGuest, showVariationAlert, focusError) {
            if (authPending) { return; }
            authPending = true;
            setAuthFeedback(box, '', false);
            openButton.setAttribute('aria-busy', 'true');
            liveAuthState(openButton, form).then(function (authenticated) {
                if (authenticated === null) {
                    setAuthFeedback(box, 'وضعیت ورود و اعتبار فرم قابل تشخیص نیست. لطفاً اتصال خود را بررسی کنید و دوباره تلاش کنید.', focusError);
                    return;
                }
                if (!authenticated) {
                    if (!redirectGuest) { return; }
                    var loginUrl = openButton.getAttribute('data-zbm-login-url');
                    if (loginUrl) {
                        window.location.assign(loginUrl);
                    } else {
                        setAuthFeedback(box, 'مسیر ورود و ثبت‌نام در دسترس نیست. لطفاً صفحه را تازه کنید و دوباره تلاش کنید.', focusError);
                    }
                    return;
                }
                setAuthFeedback(box, '', false);
                openAfterVariationCheck(showVariationAlert);
            }).finally(function () {
                authPending = false;
                openButton.removeAttribute('aria-busy');
            });
        }

        openButton.addEventListener('click', function () {
            bootstrapAndOpen(true, true, true);
        });
        modal.querySelectorAll('[data-zbm-close]').forEach(function (button) { button.addEventListener('click', close); });
        nextButton.addEventListener('click', function () {
            if (isSubmitting) { return; }
            if (currentStep < 4) {
                if (validateStep(form, currentStep, true)) { setStep(currentStep + 1); }
                return;
            }
            for (var step = 1; step <= 4; step += 1) {
                if (!validateStep(form, step, false)) {
                    setStep(step);
                    validateStep(form, step, true);
                    return;
                }
            }
            if (!variationIsReady()) {
                window.alert('لطفاً ابتدا مشخصات محصول را انتخاب کنید.');
                return;
            }
            isSubmitting = true;
            nextButton.disabled = true;
            nextButton.setAttribute('aria-busy', 'true');
            nextButton.textContent = 'در حال ارسال…';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                HTMLFormElement.prototype.submit.call(form);
            }
        });
        backButton.addEventListener('click', function () { setStep(currentStep - 1); });
        form.addEventListener('zbm:lines-changed', function () { scheduleQuantityPricingPreview(); });
        form.addEventListener('input', function (event) {
            updateSummary(form, modal);
            if (event.target && event.target.name === 'quantity') { scheduleQuantityPricingPreview(); }
        });
        form.addEventListener('change', function (event) {
            syncConditionalFields(form);
            updateSummary(form, modal);
            if (event.target && event.target.name === 'quantity') {
                scheduleQuantityPricingPreview();
            } else if (event.target && ['province_code', 'delivery_method'].indexOf(event.target.name) !== -1) {
                refreshPricingNow();
            }
        });
        form.addEventListener('submit', function (event) {
            if (currentStep < 4) {
                event.preventDefault();
                if (validateStep(form, currentStep, true)) { setStep(currentStep + 1); }
                return;
            }
            for (var step = 1; step <= 4; step += 1) {
                if (!validateStep(form, step, false)) {
                    event.preventDefault();
                    setStep(step);
                    validateStep(form, step, true);
                    resetSubmissionState();
                    return;
                }
            }
            if (!variationIsReady()) {
                event.preventDefault();
                window.alert('لطفاً ابتدا مشخصات محصول را انتخاب کنید.');
                resetSubmissionState();
                return;
            }
            isSubmitting = true;
            nextButton.disabled = true;
            nextButton.setAttribute('aria-busy', 'true');
            nextButton.textContent = 'در حال ارسال…';
        });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.key !== 'Tab') { return; }
            var focusable = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]),input:not([type="hidden"]):not([disabled]),textarea:not([disabled]),select:not([disabled])')).filter(function (node) {
                return !node.closest('[hidden]');
            });
            if (!focusable.length) { return; }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });
        syncConditionalFields(form);
        updateSummary(form, modal);
        var shouldAutoOpen = openButton.getAttribute('data-zbm-auto-open') === '1' || /(?:^|&)zbm_resume_request=1(?:&|$)/.test(window.location.search.replace(/^\?/, ''));
        if (shouldAutoOpen) {
            bootstrapAndOpen(true, false, false);
        }
    }

    document.querySelectorAll('.zbm-request-box').forEach(initRequestModal);

    function initAccountEditFields() {
        document.querySelectorAll('.woocommerce-EditAccountForm').forEach(function (form) {
            if (!form.querySelector('.zbm-account-profile-card')) { return; }
            form.classList.add('zbm-account-edit-form');
            ['account_first_name', 'account_last_name', 'account_display_name', 'account_email'].forEach(function (name) {
                var input = form.querySelector('[name="' + name + '"]');
                if (!input) { return; }
                var row = input.closest('p, .form-row, .woocommerce-form-row');
                if (row) { row.classList.add('zbm-native-account-row-hidden'); }
            });
        });
    }

    initAccountEditFields();

    document.querySelectorAll('.zbm-request-form').forEach(function (form) {
        var wooForm = document.querySelector('form.variations_form');
        if (wooForm) {
            var wooButton = wooForm.querySelector('.single_add_to_cart_button');
            if (wooButton) { wooButton.hidden = true; }
            $(wooForm).on('found_variation', function (event, variation) {
                var variationId = form.querySelector('.zbm-variation-id');
                if (variationId) { variationId.value = variation && variation.variation_id ? variation.variation_id : 0; }
                var requestModal = form.closest('.zbm-modal');
                if (requestModal && !requestModal.hidden) {
                    if (typeof requestModal._zbmRefreshPricingNow === 'function') { requestModal._zbmRefreshPricingNow(); }
                    else { refreshPricingPreview(form, requestModal); }
                }
            }).on('reset_data hide_variation', function () {
                var variationId = form.querySelector('.zbm-variation-id');
                if (variationId) { variationId.value = 0; }
                var requestModal = form.closest('.zbm-modal');
                if (requestModal && !requestModal.hidden) {
                    if (typeof requestModal._zbmRefreshPricingNow === 'function') { requestModal._zbmRefreshPricingNow(); }
                    else { refreshPricingPreview(form, requestModal); }
                }
            });
        } else {
            var simpleButton = document.querySelector('form.cart .single_add_to_cart_button');
            if (simpleButton) { simpleButton.hidden = true; }
        }
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
        });
    });

    // Shared payment-clock.js also runs in the admin and after AJAX panel replacement.
})(jQuery);
