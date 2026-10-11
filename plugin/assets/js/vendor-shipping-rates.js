(function () {
    'use strict';

    var panel = document.querySelector('.zbm-vendor-shipping-rates');
    if (!panel) { return; }

    var normalizeDigits = function (value) {
        return value.replace(/[۰-۹٠-٩]/g, function (digit) {
            var persian = '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit);
            return String(persian >= 0 ? persian : '٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
        });
    };

    panel.querySelectorAll('[data-zbm-rate-input]').forEach(function (input) {
        var form = input.closest('form');
        var equivalent = form ? form.querySelector('[data-zbm-rate-equivalent]') : null;
        var updateEquivalent = function () {
            // Validate a separate representation; never rewrite signs, decimals or exponents.
            // Comma, Arabic thousands separator and ASCII space match the server/form contract.
            var normalized = normalizeDigits(input.value).replace(/[,٬ ]/g, '');
            var valid = /^[0-9]+$/.test(normalized) && Number.isSafeInteger(Number(normalized)) && Number(normalized) <= 999999999;
            input.setCustomValidity(valid ? '' : 'نرخ را به‌صورت عدد صحیح بین صفر و ۹۹۹٬۹۹۹٬۹۹۹ وارد کنید؛ جداکننده‌های مجاز ویرگول، ٬ و فاصله هستند.');
            input.setAttribute('aria-invalid', valid ? 'false' : 'true');
            if (equivalent) {
                equivalent.textContent = valid ? 'معادل: ' + Number(normalized).toLocaleString('fa-IR') + ' تومان برای هر کیلوگرم' : '';
            }
        };
        input.addEventListener('input', updateEquivalent);
        updateEquivalent();
    });
}());
