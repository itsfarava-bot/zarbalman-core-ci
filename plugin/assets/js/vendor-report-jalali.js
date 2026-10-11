(function () {
    'use strict';

    var root;

    var DIGITS = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
    };
    var PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    var RANGE_MESSAGE = 'تاریخ پایان باید بعد از تاریخ شروع باشد؛ لطفاً بازهٔ زمانی را اصلاح کنید.';
    var INVALID_MESSAGE = 'تاریخ‌ها را فقط با عدد و در قالب سال/ماه/روز وارد کنید؛ مثلاً ۱۴۰۵/۰۷/۰۲.';

    function latinDigits(value) {
        return String(value || '').replace(/[۰-۹٠-٩]/g, function (digit) { return DIGITS[digit] || digit; });
    }

    function persianDigits(value) {
        return String(value).replace(/[0-9]/g, function (digit) { return PERSIAN_DIGITS[Number(digit)]; });
    }

    function pad(value) { return String(value).length < 2 ? '0' + value : String(value); }

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
        var normalized = latinDigits(value).trim();
        var match = normalized.match(/^(\d{4})\/(\d{2})\/(\d{2})$/);
        if (!match) { return null; }
        var result = { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
        if (result.year < 1 || result.year > 3000 || result.month < 1 || result.month > 12 || result.day < 1 || result.day > jalaliMonthLength(result.year, result.month)) { return null; }
        return result;
    }

    function formatDate(date, persian) {
        var value = date.year + '/' + pad(date.month) + '/' + pad(date.day);
        return persian ? persianDigits(value) : value;
    }

    function compareJalaliDate(left, right) {
        if (left.year !== right.year) { return left.year - right.year; }
        if (left.month !== right.month) { return left.month - right.month; }
        return left.day - right.day;
    }

    function dateDigits(value) {
        return latinDigits(value).replace(/[^0-9]/g, '').slice(0, 8);
    }

    function formatInput(value) {
        var digits = dateDigits(value);
        var formatted = digits.slice(0, 4);
        if (digits.length > 4) { formatted += '/' + digits.slice(4, 6); }
        if (digits.length > 6) { formatted += '/' + digits.slice(6, 8); }
        return persianDigits(formatted);
    }

    function digitCountBefore(value, position) {
        return latinDigits(String(value || '').slice(0, Math.max(0, position))).replace(/[^0-9]/g, '').length;
    }

    function caretForDigitCount(value, count) {
        var seen = 0;
        if (count <= 0) { return 0; }
        for (var index = 0; index < value.length; index += 1) {
            if (/^[0-9]$/.test(latinDigits(value.charAt(index)))) {
                seen += 1;
                if (seen >= count) { return index + 1; }
            }
        }
        return value.length;
    }

    function normalizeInput(input) {
        var previous = String(input.value || '');
        var caret = typeof input.selectionStart === 'number' ? input.selectionStart : previous.length;
        var digitsBeforeCaret = digitCountBefore(previous, caret);
        var formatted = formatInput(previous);
        if (previous !== formatted) {
            input.value = formatted;
            if (document.activeElement === input && typeof input.setSelectionRange === 'function') {
                var nextCaret = caretForDigitCount(formatted, Math.min(digitsBeforeCaret, 8));
                input.setSelectionRange(nextCaret, nextCaret);
            }
        }
    }

    function isNavigationKey(event) {
        return event.ctrlKey || event.metaKey || event.altKey || ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'Tab', 'Enter', 'Escape'].indexOf(event.key) !== -1;
    }

    function inputForRole(role) {
        return root.querySelector('[data-range-role="' + role + '"]');
    }

    function dateAllowed(input, date) {
        var start = parseJalaliDate((inputForRole('start') || {}).value || '');
        var end = parseJalaliDate((inputForRole('end') || {}).value || '');
        var role = input.getAttribute('data-range-role');
        if (role === 'start' && end) { return compareJalaliDate(date, end) < 0; }
        if (role === 'end' && start) { return compareJalaliDate(date, start) > 0; }
        return true;
    }

    function setPickerState(input, picker, visible) {
        picker.hidden = !visible;
        input.setAttribute('aria-expanded', visible ? 'true' : 'false');
        var trigger = input.parentNode ? input.parentNode.querySelector('.zbm-vendor-report-jalali-trigger') : null;
        if (trigger) { trigger.setAttribute('aria-expanded', visible ? 'true' : 'false'); }
    }

    function renderPicker(input, picker, year, month) {
        picker.textContent = '';
        var header = document.createElement('div');
        header.className = 'zbm-vendor-report-jalali-picker-header';
        var previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'zbm-vendor-report-jalali-month-button';
        previous.textContent = '‹';
        previous.setAttribute('aria-label', 'ماه قبل');
        previous.disabled = year <= 1;
        var title = document.createElement('strong');
        title.textContent = MONTHS[month - 1] + ' ' + persianDigits(year);
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'zbm-vendor-report-jalali-month-button';
        next.textContent = '›';
        next.setAttribute('aria-label', 'ماه بعد');
        next.disabled = year >= 3000;
        header.appendChild(previous);
        header.appendChild(title);
        header.appendChild(next);
        picker.appendChild(header);

        var weekdays = document.createElement('div');
        weekdays.className = 'zbm-vendor-report-jalali-weekdays';
        WEEKDAYS.forEach(function (label) {
            var weekday = document.createElement('span');
            weekday.textContent = label;
            weekday.setAttribute('aria-hidden', 'true');
            weekdays.appendChild(weekday);
        });
        picker.appendChild(weekdays);

        var grid = document.createElement('div');
        grid.className = 'zbm-vendor-report-jalali-days';
        var firstGregorian = jalaliToGregorian(year, month, 1);
        var offset = (new Date(Date.UTC(firstGregorian.year, firstGregorian.month - 1, firstGregorian.day)).getUTCDay() + 1) % 7;
        for (var blank = 0; blank < offset; blank += 1) { grid.appendChild(document.createElement('span')); }
        var selected = parseJalaliDate(input.value);
        for (var day = 1; day <= jalaliMonthLength(year, month); day += 1) {
            var date = { year: year, month: month, day: day };
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'zbm-vendor-report-jalali-day';
            button.textContent = persianDigits(day);
            button.setAttribute('aria-label', formatDate(date, true));
            button.setAttribute('data-zbm-date', formatDate(date, false));
            button.disabled = !dateAllowed(input, date);
            if (selected && compareJalaliDate(selected, date) === 0) { button.classList.add('is-selected'); }
            button.addEventListener('click', function (event) {
                var selectedDate = parseJalaliDate(event.currentTarget.getAttribute('data-zbm-date'));
                if (!selectedDate) { return; }
                input.value = formatDate(selectedDate, true);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                setPickerState(input, picker, false);
                input.setAttribute('data-zbm-vendor-report-jalali-suppress-open', '1');
                input.focus();
            });
            grid.appendChild(button);
        }
        picker.appendChild(grid);
        previous.addEventListener('click', function () {
            var previousMonth = month === 1 ? 12 : month - 1;
            var previousYear = month === 1 ? year - 1 : year;
            renderPicker(input, picker, previousYear, previousMonth);
        });
        next.addEventListener('click', function () {
            var nextMonth = month === 12 ? 1 : month + 1;
            var nextYear = month === 12 ? year + 1 : year;
            renderPicker(input, picker, nextYear, nextMonth);
        });
    }

    function openPicker(input) {
        var picker = input.parentNode ? input.parentNode.querySelector('.zbm-vendor-report-jalali-picker') : null;
        if (!picker) { return; }
        var selected = parseJalaliDate(input.value);
        if (!selected) {
            var counterpart = inputForRole(input.getAttribute('data-range-role') === 'start' ? 'end' : 'start');
            selected = counterpart ? parseJalaliDate(counterpart.value) : null;
        }
        if (!selected) {
            var now = new Date();
            selected = gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
        }
        renderPicker(input, picker, selected.year, selected.month);
        setPickerState(input, picker, true);
    }

    function validateRange(showMessage) {
        var startInput = inputForRole('start');
        var endInput = inputForRole('end');
        var feedback = root.querySelector('[data-zbm-range-error]');
        if (!startInput || !endInput) { return true; }
        var start = parseJalaliDate(startInput.value);
        var end = parseJalaliDate(endInput.value);
        var message = '';
        if (!start || !end) {
            message = (startInput.value.trim() || endInput.value.trim()) ? INVALID_MESSAGE : '';
        } else if (compareJalaliDate(end, start) <= 0) {
            message = RANGE_MESSAGE;
        }
        startInput.setCustomValidity(message);
        endInput.setCustomValidity(message);
        startInput.setAttribute('aria-invalid', message ? 'true' : 'false');
        endInput.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (feedback) {
            feedback.textContent = message;
            var hasOrderedDates = start && end && compareJalaliDate(end, start) <= 0;
            feedback.hidden = !message || (!showMessage && !hasOrderedDates);
        }
        return !message;
    }

    function initDateInputs() {
        var form = root.querySelector('.zbm-reports-filter-form');
        if (!form) { return; }
        form.querySelectorAll('.zbm-vendor-report-jalali-date').forEach(function (input) {
            if (input.getAttribute('data-zbm-vendor-report-jalali-ready') === '1') { return; }
            input.setAttribute('data-zbm-vendor-report-jalali-ready', '1');
            var picker = document.createElement('div');
            picker.className = 'zbm-vendor-report-jalali-picker';
            picker.hidden = true;
            picker.id = input.id + '-picker';
            picker.setAttribute('role', 'dialog');
            picker.setAttribute('aria-label', 'تقویم شمسی');
            input.parentNode.appendChild(picker);
            input.setAttribute('aria-controls', picker.id);
            input.addEventListener('focus', function () {
                if (input.getAttribute('data-zbm-vendor-report-jalali-suppress-open') === '1') {
                    input.removeAttribute('data-zbm-vendor-report-jalali-suppress-open');
                    return;
                }
                openPicker(input);
            });
            input.addEventListener('beforeinput', function (event) {
                if (!event.data || (event.inputType && event.inputType.indexOf('delete') === 0)) { return; }
                var isPaste = event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop';
                var allowed = isPaste ? /^[0-9۰-۹٠-٩/]+$/ : /^[0-9۰-۹٠-٩]+$/;
                if (!allowed.test(event.data)) { event.preventDefault(); }
            });
            input.addEventListener('keydown', function (event) {
                if (isNavigationKey(event) || !event.key || event.key === 'Unidentified' || event.key === 'Process') { return; }
                if (/^[0-9۰-۹٠-٩]$/.test(event.key)) {
                    var digits = dateDigits(input.value);
                    var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
                    var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
                    var selectedDigits = digitCountBefore(input.value, end) - digitCountBefore(input.value, start);
                    if (digits.length - selectedDigits < 8) { return; }
                }
                event.preventDefault();
            });
            input.addEventListener('input', function () {
                normalizeInput(input);
                validateRange(false);
            });
            input.addEventListener('change', function () { validateRange(false); });
            input.addEventListener('blur', function () {
                normalizeInput(input);
                var parsed = parseJalaliDate(input.value);
                if (parsed) { input.value = formatDate(parsed, true); }
                validateRange(false);
                window.setTimeout(function () {
                    if (document.activeElement !== input && !picker.contains(document.activeElement)) { setPickerState(input, picker, false); }
                }, 0);
            });
            var trigger = input.parentNode.querySelector('.zbm-vendor-report-jalali-trigger');
            if (trigger) {
                trigger.addEventListener('click', function () {
                    if (picker.hidden) { input.focus(); openPicker(input); }
                    else { setPickerState(input, picker, false); }
                });
            }
        });
        form.addEventListener('submit', function (event) {
            if (!validateRange(true)) {
                event.preventDefault();
                var invalid = form.querySelector('[aria-invalid="true"]');
                if (invalid) { invalid.focus(); }
            }
        });
        document.addEventListener('click', function (event) {
            if (!event.target.closest('.zbm-vendor-report-jalali-date-wrap')) {
                root.querySelectorAll('.zbm-vendor-report-jalali-picker').forEach(function (picker) {
                    var input = picker.parentNode ? picker.parentNode.querySelector('.zbm-vendor-report-jalali-date') : null;
                    if (input) { setPickerState(input, picker, false); }
                });
            }
        });
        validateRange(false);
    }

    function init() {
        root = document.querySelector('.zbm-vendor-reports');
        if (root) { initDateInputs(); }
    }

    window.ZarbalmanVendorReportJalali104 = {
        parse: parseJalaliDate,
        format: formatDate,
        compare: compareJalaliDate
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
